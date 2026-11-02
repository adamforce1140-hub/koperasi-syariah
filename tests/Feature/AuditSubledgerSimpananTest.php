<?php

namespace Tests\Feature;

use App\Models\Coa;
use App\Models\JenisSimpanan;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\Rekening;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P1-2 — command audit read-only `simpanan:audit-subledger`.
 *
 * Yang dibuktikan:
 *  A. ATRIBUSI  — saldo rekening tanpa dukungan jurnal muncul sebagai baris
 *                 anggota dengan selisih negatif.
 *  B. PENUTUP   — jurnal yang mendukung saldo (tertaut `anggota_id`) membuat
 *                 selisih baris tersebut nol dan menyembunyikannya saat
 *                 `--hanya-berselisih`.
 *  C. KELOMPOK  — baris GL tanpa `anggota_id` dikelompokkan TANPA_ANGGOTA.
 *  D. FORMAT    — table/json/csv; format tak dikenal ditolak.
 *  E. READ-ONLY — command tidak menulis jurnal, detail, maupun saldo.
 *
 * Semua asersi memakai data ber-prefix unik / delta, karena basis data
 * pengembangan memuat selisih warisan yang tidak boleh diubah test.
 */
class AuditSubledgerSimpananTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    private ?int $anggotaId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'AUD'.strtoupper(substr(uniqid(), -6));
    }

    /* ==================================================================
     | FIXTURE
     ================================================================== */

    private function anggota(): int
    {
        if ($this->anggotaId === null) {
            $this->anggotaId = $this->anggotaBaru();
        }

        return $this->anggotaId;
    }

    /** Anggota baru tanpa cache — dipakai saat satu test butuh dua anggota. */
    private function anggotaBaru(): int
    {
        return (int) DB::table('anggota')->insertGetId([
            'no_anggota' => 'AGT-'.$this->prefix.'-'.strtoupper(substr(uniqid(), -4)),
            'nik' => '8888'.random_int(10000000, 99999999),
            'nama_lengkap' => 'Anggota '.$this->prefix,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji Audit Subledger No. 2',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function rekening(string $suffix, float $saldo, ?int $anggotaId = null): Rekening
    {
        return Rekening::create([
            'no_rekening' => 'AUD-'.$this->prefix.'-'.$suffix,
            'anggota_id' => $anggotaId ?? $this->anggota(),
            'akun_id' => (int) DB::table('akun')->value('id'),
            'jenis_rekening' => JenisSimpanan::NAMA_SUKARELA,
            'akad' => 'Wadiah',
            'saldo' => $saldo,
            'tanggal_buka' => now()->subMonth()->toDateString(),
            'status_rekening' => 'Aktif',
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

    /** Posting jurnal Posted dua baris; akun kontrol dikredit. */
    private function kreditAkun(string $kode, float $nominal, ?int $anggotaId = null): Jurnal
    {
        $kontrol = $this->coa($kode);
        $lawan = $this->coa('1-1999');

        $jurnal = Jurnal::create([
            'no_jurnal' => 'UJI-AUD-'.strtoupper(substr(uniqid(), -8)),
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'UJI-AUDIT-SUBLEDGER',
            'keterangan' => 'Uji audit subledger',
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
            'keterangan' => 'Lawan uji audit subledger',
        ]);

        JurnalDetail::create([
            'jurnal_id' => $jurnal->id,
            'coa_id' => $kontrol->id,
            'anggota_id' => $anggotaId,
            'baris' => 2,
            'debet' => 0,
            'kredit' => $nominal,
            'keterangan' => 'Akun kontrol uji audit subledger',
        ]);

        return $jurnal;
    }

    /** @param array<int, string> $kode */
    private function jalankanJson(array $kode, bool $hanyaBerselisih = false): array
    {
        $parameter = ['--format' => 'json'];

        if ($kode !== []) {
            $parameter['--kode'] = $kode;
        }

        if ($hanyaBerselisih) {
            $parameter['--hanya-berselisih'] = true;
        }

        $exit = Artisan::call('simpanan:audit-subledger', $parameter);
        $keluaran = Artisan::output();

        $this->assertSame(0, $exit, 'Command audit harus selesai sukses. Keluaran: '.$keluaran);

        $data = json_decode($keluaran, true);

        $this->assertIsArray($data, 'Keluaran JSON tidak dapat dibaca: '.$keluaran);

        return $data;
    }

    /** @return array<string, mixed>|null */
    private function barisAnggota(array $data, string $kode, ?int $anggotaId): ?array
    {
        foreach ($data['akun'] as $akun) {
            if ($akun['kode'] !== $kode) {
                continue;
            }

            foreach ($akun['baris'] as $baris) {
                if ($baris['anggota_id'] === $anggotaId) {
                    return $baris;
                }
            }
        }

        return null;
    }

    /* ==================================================================
     | A. ATRIBUSI
     ================================================================== */

    public function test_saldo_rekening_tanpa_jurnal_muncul_sebagai_selisih_anggota(): void
    {
        $anggota = $this->anggota();
        $rekening = $this->rekening('A', 750_000);

        $baris = $this->barisAnggota(
            $this->jalankanJson(['2-1001'], true),
            '2-1001',
            $anggota
        );

        $this->assertNotNull($baris, 'Anggota dengan rekening tanpa jurnal harus muncul.');
        $this->assertSame($rekening->no_rekening, $baris['no_rekening']);
        $this->assertEqualsWithDelta(750_000.0, (float) $baris['saldo_subledger'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $baris['mutasi_gl'], 0.01);
        $this->assertEqualsWithDelta(-750_000.0, (float) $baris['selisih'], 0.01);
    }

    public function test_jurnal_tertaut_anggota_menutup_selisih_dan_disembunyikan(): void
    {
        $anggota = $this->anggota();
        $rekening = $this->rekening('B', 500_000);

        $this->kreditAkun('2-1001', 500_000, $anggota);

        $data = $this->jalankanJson(['2-1001'], true);
        $baris = $this->barisAnggota($data, '2-1001', $anggota);

        $this->assertNull(
            $baris,
            'Baris yang sudah seimbang tidak boleh tampil saat --hanya-berselisih.'
        );

        // Tanpa filter, baris tetap ada dengan selisih nol.
        $semua = $this->barisAnggota($this->jalankanJson(['2-1001']), '2-1001', $anggota);

        $this->assertNotNull($semua);
        $this->assertEqualsWithDelta(500_000.0, (float) $semua['saldo_subledger'], 0.01);
        $this->assertEqualsWithDelta(500_000.0, (float) $semua['mutasi_gl'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $semua['selisih'], 0.01);
        $this->assertSame($rekening->no_rekening, $semua['no_rekening']);
    }

    /**
     * Regresi: total akun harus dihitung dari SELURUH baris, bukan dari baris
     * yang lolos `--hanya-berselisih`. Sebelumnya total ikut menyusut sehingga
     * angka akun berbeda dengan `jurnal:rekonsiliasi` (mis. 3-1001 tampil
     * Rp400.000 padahal subledger sebenarnya Rp600.000).
     */
    public function test_total_akun_tetap_utuh_saat_hanya_berselisih_dipakai(): void
    {
        $seimbang = $this->anggota();
        $berselisih = $this->anggotaBaru();

        $this->rekening('C1', 400_000, $seimbang);
        $this->kreditAkun('2-1001', 400_000, $seimbang);

        $this->rekening('C2', 250_000, $berselisih);

        $tanpaFilter = $this->jalankanJson(['2-1001']);
        $terfilter = $this->jalankanJson(['2-1001'], true);

        $akunTanpa = $tanpaFilter['akun'][0];
        $akunFilter = $terfilter['akun'][0];

        $this->assertNull($this->barisAnggota($terfilter, '2-1001', $seimbang));
        $this->assertNotNull($this->barisAnggota($terfilter, '2-1001', $berselisih));

        $this->assertEqualsWithDelta(
            (float) $akunTanpa['subledger'],
            (float) $akunFilter['subledger'],
            0.01,
            'Total subledger akun tidak boleh berubah karena opsi penyaring.'
        );
        $this->assertEqualsWithDelta(
            (float) $akunTanpa['gl'],
            (float) $akunFilter['gl'],
            0.01,
            'Total GL akun tidak boleh berubah karena opsi penyaring.'
        );
        $this->assertEqualsWithDelta(
            (float) $akunTanpa['selisih'],
            (float) $akunFilter['selisih'],
            0.01,
            'Selisih akun tidak boleh berubah karena opsi penyaring.'
        );
    }

    /* ==================================================================
     | C. KELOMPOK TANPA_ANGGOTA
     ================================================================== */

    public function test_jurnal_tanpa_anggota_dikelompokkan_tanpa_anggota(): void
    {
        $sebelum = $this->totalTanpaAnggota($this->jalankanJson(['2-1001']));

        $this->kreditAkun('2-1001', 333_333, null);

        $sesudah = $this->totalTanpaAnggota($this->jalankanJson(['2-1001']));

        $this->assertEqualsWithDelta(
            $sebelum + 333_333,
            $sesudah,
            0.01,
            'Mutasi GL tanpa anggota harus menambah kelompok TANPA_ANGGOTA.'
        );
    }

    private function totalTanpaAnggota(array $data): float
    {
        foreach ($data['akun'] as $akun) {
            if ($akun['kode'] !== '2-1001') {
                continue;
            }

            foreach ($akun['baris'] as $baris) {
                if ($baris['no_anggota'] === 'TANPA_ANGGOTA') {
                    return (float) $baris['mutasi_gl'];
                }
            }
        }

        return 0.0;
    }

    /* ==================================================================
     | D. FORMAT
     ================================================================== */

    public function test_tanpa_opsi_kode_mengaudit_dua_akun_bawaan(): void
    {
        $data = $this->jalankanJson([]);

        $kode = array_column($data['akun'], 'kode');

        $this->assertContains('2-1001', $kode);
        $this->assertContains('2-1003', $kode);
    }

    public function test_format_csv_memuat_header_dan_baris(): void
    {
        $this->rekening('C', 125_000);

        $exit = Artisan::call('simpanan:audit-subledger', [
            '--kode' => ['2-1001'],
            '--format' => 'csv',
        ]);

        $keluaran = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringStartsWith(
            'kode_akun,anggota_id,no_anggota,nama,no_rekening,saldo_subledger,mutasi_gl,selisih,jumlah_transaksi',
            $keluaran
        );
        $this->assertStringContainsString('AUD-'.$this->prefix.'-C', $keluaran);
    }

    public function test_format_tidak_dikenal_ditolak(): void
    {
        $exit = Artisan::call('simpanan:audit-subledger', ['--format' => 'xml']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Format tidak dikenal', Artisan::output());
    }

    public function test_kode_tanpa_subledger_dilaporkan_tanpa_error(): void
    {
        $data = $this->jalankanJson(['9-9999']);

        $this->assertFalse($data['akun'][0]['coa_ada']);
        $this->assertSame([], $data['akun'][0]['baris']);
    }

    /* ==================================================================
     | E. READ-ONLY
     ================================================================== */

    public function test_command_tidak_mengubah_jurnal_maupun_saldo(): void
    {
        $this->rekening('D', 250_000);

        $sebelum = $this->sidikJari();

        $this->jalankanJson(['2-1001', '3-1001']);

        $this->assertSame($sebelum, $this->sidikJari(), 'Command audit tidak boleh menulis data.');
    }

    /** @return array<string, int|float> */
    private function sidikJari(): array
    {
        return [
            'jurnal' => (int) DB::table('jurnal')->count(),
            'jurnal_detail' => (int) DB::table('jurnal_detail')->count(),
            'transaksi' => (int) DB::table('transaksi')->count(),
            'saldo' => (float) DB::table('rekening')->sum('saldo'),
        ];
    }
}
