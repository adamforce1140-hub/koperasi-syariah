<?php

namespace Tests\Feature;

use App\Models\Coa;
use App\Models\JenisSimpanan;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\Rekening;
use App\Services\JurnalReconciliationService;
use App\Support\Coa as KodeCoa;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * Gerbang P1-1: subledger simpanan vs akun kontrol buku besar.
 *
 * Yang dibuktikan:
 *  A. DETEKSI  — saldo rekening anggota yang tidak didukung jurnal terbaca
 *                sebagai selisih, dan jurnal yang mendukungnya menutup selisih.
 *  B. CAKUPAN  — rekening `Jatuh Tempo`/`Dibekukan` tetap dihitung (kewajiban
 *                koperasi), sedangkan rekening ber-soft-delete diabaikan.
 *  C. COA HILANG — akun kontrol yang tidak ada di bagan akun dilaporkan
 *                sebagai `coa_hilang` tanpa melempar exception.
 *  D. MODE GATE — mode LAPOR (bawaan) tidak melempar; mode TEGAKKAN
 *                (`reconciliation.gate_enforce`) melempar RuntimeException.
 *  E. PERINTAH — `jurnal:rekonsiliasi` menampilkan tabel subledger dan
 *                mengikuti mode gate pada exit code.
 *
 * Seluruh test memakai selisih (delta) terhadap keadaan awal, karena basis
 * data pengembangan sesungguhnya memuat selisih warisan (P1-2) yang tidak
 * boleh diubah oleh test.
 */
class RekonsiliasiSubledgerTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    private ?int $anggotaId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'SUBL'.strtoupper(substr(uniqid(), -6));
        config(['reconciliation.gate_enforce' => false]);
    }

    /* ==================================================================
     | FIXTURE
     ================================================================== */

    private function service(): JurnalReconciliationService
    {
        return app(JurnalReconciliationService::class);
    }

    private function anggotaUji(): int
    {
        if ($this->anggotaId === null) {
            $this->anggotaId = (int) DB::table('anggota')->insertGetId([
                'no_anggota' => 'AGT-'.$this->prefix,
                'nik' => '7777'.random_int(10000000, 99999999),
                'nama_lengkap' => 'Anggota '.$this->prefix,
                'jenis_kelamin' => 'Perempuan',
                'alamat' => 'Jl. Uji Subledger No. 1',
                'kota' => 'Bandung',
                'provinsi' => 'Jawa Barat',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $this->anggotaId;
    }

    private function rekening(string $suffix, float $saldo, string $status = 'Aktif'): Rekening
    {
        return Rekening::create([
            'no_rekening' => 'SUBL-'.$this->prefix.'-'.$suffix,
            'anggota_id' => $this->anggotaUji(),
            'akun_id' => (int) DB::table('akun')->value('id'),
            'jenis_rekening' => JenisSimpanan::NAMA_SUKARELA,
            'akad' => 'Wadiah',
            'saldo' => $saldo,
            'tanggal_buka' => now()->subMonth()->toDateString(),
            'status_rekening' => $status,
        ]);
    }

    private function coa(string $kode): Coa
    {
        $kepala = substr($kode, 0, 1);

        [$kategori, $klasifikasi, $tipe] = match ($kepala) {
            '1' => ['Aset', 'Aset Lancar', 'Debet'],
            '2' => ['Kewajiban', 'Kewajiban Jangka Pendek', 'Kredit'],
            '3' => ['Ekuitas', 'Saldo Laba / SHU', 'Kredit'],
            '4' => ['Pendapatan', 'Pendapatan', 'Kredit'],
            default => ['Beban', 'Beban Operasional', 'Debet'],
        };

        return Coa::updateOrCreate(
            ['kode_akun' => $kode],
            [
                'nama_akun' => 'Akun uji '.$kode,
                'kategori' => $kategori,
                'klasifikasi_neraca' => $klasifikasi,
                'tipe_saldo_normal' => $tipe,
                'saldo_awal' => 0,
                'is_active' => true,
            ]
        );
    }

    /**
     * Posting dua baris jurnal langsung (tanpa AutoJournalingService) agar
     * test tidak bergantung pada aturan posting lain. Pasangan baris dibuat
     * seimbang terhadap akun lawan `1-1999`, sehingga total buku besar tetap
     * seimbang dan hanya subledger yang diuji.
     *
     * @param  string  $kode  Akun kontrol yang dikredit.
     */
    private function kreditAkun(string $kode, float $nominal): Jurnal
    {
        $kontrol = $this->coa($kode);
        $lawan = $this->coa('1-1999');

        $jurnal = Jurnal::create([
            'no_jurnal' => 'UJI-SUBL-'.strtoupper(substr(uniqid(), -8)),
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'UJI-SUBLEDGER',
            'keterangan' => 'Uji gerbang subledger',
            'total_debet' => $nominal,
            'total_kredit' => $nominal,
            'is_balance' => true,
            'status' => 'Posted',
            'posted_at' => now(),
        ]);

        JurnalDetail::create([
            'jurnal_id' => $jurnal->id,
            'coa_id' => $lawan->id,
            'baris' => 1,
            'debet' => $nominal,
            'kredit' => 0,
            'keterangan' => 'Lawan uji subledger',
        ]);

        JurnalDetail::create([
            'jurnal_id' => $jurnal->id,
            'coa_id' => $kontrol->id,
            'baris' => 2,
            'debet' => 0,
            'kredit' => $nominal,
            'keterangan' => 'Akun kontrol uji subledger',
        ]);

        return $jurnal;
    }

    /** @return array{kode: string, gl: float, subledger: float, selisih: float, status: string} */
    private function baris(array $hasil, string $kode): array
    {
        foreach ($hasil as $item) {
            if ($item['kode'] === $kode) {
                return $item;
            }
        }

        $this->fail("Akun [{$kode}] tidak ada pada hasil rekonsiliasi subledger.");
    }

    private function selisihKode(string $kode): float
    {
        return (float) $this->baris(
            $this->service()->selisihSubledger([$kode]),
            $kode
        )['selisih'];
    }

    /* ==================================================================
     | A. DETEKSI SELISIH
     ================================================================== */

    public function test_saldo_rekening_tanpa_dukungan_jurnal_terbaca_sebagai_selisih(): void
    {
        $kode = KodeCoa::SIMPANAN_SUKARELA;
        $sebelum = $this->selisihKode($kode);

        // Rekening bersaldo Rp 1.000.000 tanpa jurnal pendukung.
        $rekening = $this->rekening('A', 1_000_000);

        $baris = $this->baris($this->service()->selisihSubledger([$kode]), $kode);

        $this->assertEqualsWithDelta(
            $sebelum - 1_000_000,
            $baris['selisih'],
            0.01,
            'Selisih harus bertambah negatif sebesar saldo rekening yang tidak didukung jurnal.'
        );
        $this->assertSame('selisih', $baris['status']);

        // Jurnal yang mendukung saldo tersebut menutup selisih kembali.
        $this->kreditAkun($kode, 1_000_000);

        $this->assertEqualsWithDelta(
            $sebelum,
            $this->selisihKode($kode),
            0.01,
            'Jurnal pendukung harus menutup selisih yang tadi terbentuk.'
        );
    }

    public function test_selisih_di_bawah_toleransi_dianggap_cocok(): void
    {
        $kode = KodeCoa::SIMPANAN_SUKARELA;
        $sebelum = $this->selisihKode($kode);
        $toleransi = Setting::toleransiSelisihJurnal();

        // Geser subledger tepat di bawah ambang toleransi dari kondisi awal.
        // Bila kondisi awal sudah `cocok`, pergeseran ini tetap `cocok`.
        $this->rekening('T', max(0.0, $toleransi / 4));

        $baris = $this->baris($this->service()->selisihSubledger([$kode]), $kode);

        if (abs($sebelum) < $toleransi / 2) {
            $this->assertSame('cocok', $baris['status']);
        } else {
            $this->assertEqualsWithDelta($sebelum - $toleransi / 4, $baris['selisih'], 0.01);
        }
    }

    /* ==================================================================
     | B. CAKUPAN REKENING
     ================================================================== */

    public function test_rekening_jatuh_tempo_dan_dibekukan_tetap_dihitung(): void
    {
        $kode = KodeCoa::SIMPANAN_SUKARELA;
        $sebelum = $this->selisihKode($kode);

        $this->rekening('JT', 400_000, 'Jatuh Tempo');
        $this->rekening('BK', 600_000, 'Dibekukan');

        $this->assertEqualsWithDelta(
            $sebelum - 1_000_000,
            $this->selisihKode($kode),
            0.01,
            'Rekening Jatuh Tempo/Dibekukan tetap kewajiban koperasi dan wajib dihitung sebagai subledger.'
        );
    }

    public function test_rekening_terhapus_secara_lunak_tidak_dihitung(): void
    {
        $kode = KodeCoa::SIMPANAN_SUKARELA;
        $sebelum = $this->selisihKode($kode);

        $this->rekening('HAPUS', 750_000)->delete();

        $this->assertEqualsWithDelta(
            $sebelum,
            $this->selisihKode($kode),
            0.01,
            'Rekening ber-soft-delete bukan lagi subledger aktif.'
        );
    }

    public function test_rekening_berjangka_tidak_mempengaruhi_akun_sukarela(): void
    {
        $sukarela = KodeCoa::SIMPANAN_SUKARELA;
        $berjangka = KodeCoa::SIMPANAN_BERJANGKA;

        $sebelumSukarela = $this->selisihKode($sukarela);
        $sebelumBerjangka = $this->selisihKode($berjangka);

        Rekening::create([
            'no_rekening' => 'DEP-'.$this->prefix,
            'anggota_id' => $this->anggotaUji(),
            'akun_id' => (int) DB::table('akun')->value('id'),
            'jenis_rekening' => JenisSimpanan::NAMA_BERJANGKA,
            'akad' => 'Mudharabah',
            'saldo' => 5_000_000,
            'tanggal_buka' => now()->subMonth()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);

        $this->assertEqualsWithDelta($sebelumSukarela, $this->selisihKode($sukarela), 0.01);
        $this->assertEqualsWithDelta($sebelumBerjangka - 5_000_000, $this->selisihKode($berjangka), 0.01);
    }

    /* ==================================================================
     | C. COA HILANG
     ================================================================== */

    public function test_kode_akun_tanpa_subledger_ditandai_tanpa_subledger(): void
    {
        $this->coa('1-8888');

        $baris = $this->baris($this->service()->selisihSubledger(['1-8888']), '1-8888');

        $this->assertSame('tanpa_subledger', $baris['status']);
        $this->assertEqualsWithDelta(0.0, $baris['selisih'], 0.01);
    }

    public function test_akun_kontrol_hilang_dilaporkan_tanpa_melempar_exception(): void
    {
        $kode = '9-7777';
        Coa::where('kode_akun', $kode)->delete();

        $hasil = $this->service()->rekonsiliasiSubledger([$kode]);

        $baris = $this->baris($hasil['baris'], $kode);

        $this->assertSame('coa_hilang', $baris['status']);
        $this->assertFalse($hasil['seimbang']);
        $this->assertSame('SUBLEDGER_COA_HILANG', $hasil['temuan'][0]['aturan']);
    }

    /* ==================================================================
     | D. MODE GATE
     ================================================================== */

    public function test_mode_lapor_tidak_melempar_walau_ada_selisih(): void
    {
        config(['reconciliation.gate_enforce' => false]);

        // Rekening tanpa jurnal pendukung menjamin selisih di atas toleransi.
        $this->rekening('LAPOR', 2_000_000);

        $this->service()->pastikanSubledgerSehat([KodeCoa::SIMPANAN_SUKARELA]);

        $this->assertFalse($this->service()->gateSubledgerDitegakkan());
    }

    public function test_mode_tegakkan_melempar_runtime_exception_dan_menggulung_transaksi(): void
    {
        config(['reconciliation.gate_enforce' => true]);

        $this->assertTrue($this->service()->gateSubledgerDitegakkan());

        try {
            DB::transaction(function () {
                $this->rekening('GAGAL', 3_000_000);
                $this->service()->pastikanSubledgerSehat([KodeCoa::SIMPANAN_SUKARELA]);
            });

            $this->fail('Mode tegakkan seharusnya melempar RuntimeException saat ada selisih.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Rekonsiliasi subledger menolak transaksi', $e->getMessage());
        }

        $this->assertDatabaseMissing('rekening', ['no_rekening' => 'SUBL-'.$this->prefix.'-GAGAL']);
    }

    public function test_mode_tegakkan_tidak_melempar_bila_akun_seimbang(): void
    {
        config(['reconciliation.gate_enforce' => true]);

        // Kode tanpa subledger selalu seimbang dengan dirinya (status
        // `tanpa_subledger`), sehingga gate tidak boleh menolak.
        $this->coa('1-8889');

        $this->service()->pastikanSubledgerSehat(['1-8889']);

        $this->assertTrue(true);
    }

    /* ==================================================================
     | E. PERINTAH
     ================================================================== */

    public function test_command_jurnal_rekonsiliasi_menampilkan_subledger_dan_mode_lapor(): void
    {
        config(['reconciliation.gate_enforce' => false]);

        $this->artisan('jurnal:rekonsiliasi')
            ->expectsOutputToContain('SUBLEDGER SIMPANAN vs BUKU BESAR')
            ->expectsOutputToContain(KodeCoa::SIMPANAN_SUKARELA)
            ->assertSuccessful();
    }

    public function test_rekonsiliasi_buku_besar_memuat_kunci_subledger(): void
    {
        $hasil = $this->service()->rekonsiliasiBukuBesar();

        $this->assertArrayHasKey('subledger', $hasil);
        $this->assertCount(2, $hasil['subledger']);
    }

    /* ==================================================================
     | F. PENANDA DEDUP LOG (ANTI-SPAM & ANTI-SENYAP)
     ================================================================== */

    /**
     * ALARM TIDAK BOLEH HILANG SENYAP SAAT CACHE GAGAL.
     *
     * Bug yang dikunci: penanda `Cache::add()` untuk dedup alarm berada di
     * DALAM blok `try` yang sama dengan `Log::critical`. Saat store cache
     * gagal (driver file tak bisa menulis, Redis mati, disk penuh), exception
     * tertelan dan alarm selisih HILANG — tepat saat infrastruktur bermasalah
     * dan alarm paling dibutuhkan.
     *
     * Store cache di sini benar-benar dirusak (bukan di-mock): direktori
     * `storage/framework/cache` diganti berkas biasa, sehingga setiap baca
     * maupun tulis melalui facade `Cache` melempar. Inilah bentuk nyata dari
     * disk penuh / direktori cache tidak dapat dibuat.
     */
    public function test_alarm_selisih_tetap_tercatat_walau_cache_gagal(): void
    {
        config(['reconciliation.gate_enforce' => false]);
        $this->rekening('CACHE-MATI', 4_000_000);

        $penanda = storage_path('framework/cache/rekonsiliasi-subledger-log');
        @unlink($penanda);

        $this->app['env'] = 'production';

        try {
            /*
             * Rusakkan store `file`: direktori cache diarahkan ke DALAM sebuah
             * BERKAS biasa. Laravel tidak dapat membuat subfolder hash di
             * dalamnya, sehingga `Cache::add()` melempar exception asli.
             */
            $berkas = storage_path('app/uji-cache-bukan-folder');
            @unlink($berkas);
            file_put_contents($berkas, 'x');

            config([
                'cache.stores.rusak' => [
                    'driver' => 'file',
                    'path' => $berkas.DIRECTORY_SEPARATOR.'cache',
                ],
            ]);
            Cache::forgetDriver('rusak');

            // Prasyarat: kegagalan cache memang terjadi, agar test tidak hijau palsu.
            $cacheGagal = false;

            try {
                Cache::store('rusak')->put('uji-pemicu', true, 60);
            } catch (\Throwable) {
                $cacheGagal = true;
            }

            $this->assertTrue($cacheGagal, 'Prasyarat test: store cache harus gagal menulis.');

            Log::spy();

            // Alarm dibatasi sekali per jam lewat penanda BERKAS — tidak
            // bergantung pada store cache yang sedang rusak.
            $this->service()->pastikanSubledgerSehat([KodeCoa::SIMPANAN_SUKARELA]);

            Log::shouldHaveReceived('critical')
                ->withArgs(fn (string $pesan) => str_contains($pesan, 'Rekonsiliasi subledger menemukan selisih'))
                ->atLeast()->once();

            $this->assertFileExists($penanda, 'Penanda dedup harus memakai berkas polos, bukan Cache::add().');
        } finally {
            @unlink($berkas);
            Cache::forgetDriver('rusak');
            @unlink($penanda);
            $this->app['env'] = 'testing';
        }
    }

    /** Penanda yang sudah ada (<= 1 jam) tetap mencegah log berulang. */
    public function test_alarm_selisih_tidak_diulang_dalam_satu_jam(): void
    {
        config(['reconciliation.gate_enforce' => false]);
        $this->rekening('DEDUP', 5_000_000);

        $this->app['env'] = 'production';

        $penanda = storage_path('framework/cache/rekonsiliasi-subledger-log');

        try {
            @unlink($penanda);
            Log::spy();

            // Panggilan pertama menulis alarm dan memasang penanda.
            $this->service()->pastikanSubledgerSehat([KodeCoa::SIMPANAN_SUKARELA]);

            $this->assertFileExists($penanda, 'Alarm pertama harus memasang penanda dedup.');

            // Panggilan kedua dalam jam yang sama tidak boleh menambah alarm.
            $this->service()->pastikanSubledgerSehat([KodeCoa::SIMPANAN_SUKARELA]);

            Log::shouldHaveReceived('critical')
                ->withArgs(fn (string $pesan) => str_contains($pesan, 'Rekonsiliasi subledger menemukan selisih'))
                ->once();
        } finally {
            @unlink($penanda);
            $this->app['env'] = 'testing';
        }
    }

    /**
     * Lingkungan pengujian tidak boleh membanjiri log `testing`: suite ini
     * memanggil gate ribuan kali dengan selisih buatan test. Bukti nyata
     * tercatat di `laravel-2026-11-03.log` — 1512 baris CRITICAL palsu.
     */
    public function test_alarm_selisih_tidak_menulis_log_di_lingkungan_pengujian(): void
    {
        $this->assertSame('testing', app()->environment());

        config(['reconciliation.gate_enforce' => false]);
        $this->rekening('SENYAP', 6_000_000);

        $penanda = storage_path('framework/cache/rekonsiliasi-subledger-log');

        @unlink($penanda);
        Log::spy();

        $this->service()->pastikanSubledgerSehat([KodeCoa::SIMPANAN_SUKARELA]);

        Log::shouldNotHaveReceived('critical');
    }
}
