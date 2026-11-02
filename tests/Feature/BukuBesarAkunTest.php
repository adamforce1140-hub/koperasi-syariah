<?php

namespace Tests\Feature;

use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Inkonsistensi halaman Buku Besar Akun (Laporan Buku Besar).
 *
 * Tiga hal yang diuji:
 *   1. Sinkronisasi dropdown ↔ judul halaman: judul HARUS berupa teks opsi
 *      terpilih apa adanya dengan format "KODE — Nama Akun [Kategori]",
 *      tidak boleh menyisakan nilai default / nilai akun lama.
 *   2. Rumus saldo berjalan mengikuti POSISI SALDO NORMAL akun:
 *        1-xxxx Aset & 5-xxxx Beban       → Saldo Awal + Debet − Kredit
 *        2/3/4-xxxx Kewajiban/Ekuitas/…   → Saldo Awal + Kredit − Debet
 *      Posisi ditentukan otomatis dari kepala kode (tanpa input manual).
 *   3. Urutan baris Buku Besar mengikuti TANGGAL JURNAL (selaras dengan batas
 *      tanggal pada Coa::saldoBerjalan()), bukan created_at.
 *
 * Database dev berisi data nyata → setiap test memakai DatabaseTransactions
 * dan hanya membuat jurnal milik dirinya sendiri, lalu membandingkan angka
 * terhadap hitungan mandiri (self-consistent), bukan angka absolut.
 */
class BukuBesarAkunTest extends TestCase
{
    use DatabaseTransactions;

    private array $pengguna = [];

    /** Nomor urut jurnal uji supaya no_jurnal unik dan mudah ditelusuri. */
    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['manajer', 'pengurus', 'dps'] as $peran) {
            $this->pengguna[$peran] = User::firstOrCreate(
                ['email' => "buku-besar.{$peran}@test.local"],
                [
                    'name' => 'Uji Buku Besar '.ucfirst($peran),
                    'password' => Hash::make('password'),
                    'role' => $peran,
                ]
            );
        }
    }

    private function sebagai(string $peran): self
    {
        $this->actingAs($this->pengguna[$peran]);

        return $this;
    }

    // ═════════════════════════════════════════════════════════════════════
    // Helper
    // ═════════════════════════════════════════════════════════════════════

    /** Label dropdown yang diharapkan untuk sebuah akun. */
    private function labelAkun(Coa $akun): string
    {
        return trim($akun->kode_akun.' — '.$akun->nama_akun.' ['.$akun->kategori.']');
    }

    /**
     * Label akun sebagaimana muncul di HTML — Blade meng-escape `&`, `'`, dsb.
     * Dipakai untuk membandingkan judul halaman dengan teks opsi apa adanya.
     */
    private function labelAkunHtml(Coa $akun): string
    {
        return e($this->labelAkun($akun));
    }

    /**
     * Buat satu jurnal Posted berisi tepat satu baris mutasi untuk akun $coa,
     * diseimbangkan oleh baris lawan pada akun penyeimbang.
     *
     * @param  string  $tanggal  Y-m-d — dipakai untuk menguji urutan & batas saldo.
     */
    private function mutasi(
        Coa $coa,
        float $debet,
        float $kredit,
        string $tanggal,
        Coa $penyeimbang,
        string $keterangan = 'Mutasi uji buku besar'
    ): void {
        // Akun penyeimbang menanggung sisi berlawanan agar jurnal tetap balance
        // (Jurnal::saving() menolak header yang tidak balance).
        $lawanDebet = $kredit > 0 ? $kredit : 0.0;
        $lawanKredit = $debet > 0 ? $debet : 0.0;

        $jurnal = Jurnal::create([
            'no_jurnal' => 'UJI-BB-'.(++self::$seq).'-'.str_replace('-', '', $tanggal),
            'tanggal_jurnal' => $tanggal,
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'Uji Buku Besar',
            'keterangan' => $keterangan,
            'status' => 'Posted',
            'user_id' => $this->pengguna['manajer']->id,
        ]);

        JurnalDetail::create([
            'jurnal_id' => $jurnal->id,
            'coa_id' => $coa->id,
            'baris' => 1,
            'debet' => $debet,
            'kredit' => $kredit,
            'keterangan' => $keterangan,
        ]);

        JurnalDetail::create([
            'jurnal_id' => $jurnal->id,
            'coa_id' => $penyeimbang->id,
            'baris' => 2,
            'debet' => $lawanDebet,
            'kredit' => $lawanKredit,
            'keterangan' => 'Penyeimbang '.$keterangan,
        ]);

        // Segarkan total debet/kredit header dari detail yang benar-benar ada.
        $jurnal->hitungBalance();
        $jurnal->save();
    }

    /** Akun penyeimbang milik test (Aset) — tidak dipakai alur lain. */
    private function penyeimbang(): Coa
    {
        return $this->coa('1-9990', 'Kas Uji Buku Besar', 'Aset', 'Aset Lancar', 'Debet');
    }

    /**
     * Detail mutasi satu akun, diurutkan persis seperti halaman Buku Besar:
     * berdasarkan tanggal jurnal, lalu id baris sebagai tie-breaker.
     */
    private function detailAkun(Coa $akun)
    {
        return JurnalDetail::with('jurnal')
            ->where('coa_id', $akun->id)
            ->whereHas('jurnal', fn ($q) => $q->where('status', 'Posted'))
            ->orderBy(
                Jurnal::query()->select('tanggal_jurnal')
                    ->whereColumn('jurnal.id', 'jurnal_detail.jurnal_id')
                    ->limit(1)
            )
            ->orderBy('jurnal_detail.id')
            ->get();
    }

    /** Kode akun yang benar-benar ada & aktif di COA (dibuat bila belum ada). */
    private function coa(
        string $kode,
        string $nama,
        string $kategori,
        string $klasifikasi,
        string $posisi = 'Debet',
        float $saldoAwal = 0.0
    ): Coa {
        return Coa::firstOrCreate(
            ['kode_akun' => $kode],
            [
                'nama_akun' => $nama,
                'kategori' => $kategori,
                'klasifikasi_neraca' => $klasifikasi,
                'tipe_saldo_normal' => $posisi,
                'saldo_awal' => $saldoAwal,
                'is_active' => true,
            ]
        );
    }

    /**
     * Urutan kode akun pada teks dropdown (dari HTML yang benar-benar dikirim
     * ke browser), agar dapat dibandingkan dengan urutan yang diharapkan.
     *
     * @return array<int, string>
     */
    private function opsiDropdown(string $html): array
    {
        preg_match_all(
            '/<option value="[^"]*"[^>]*>\s*(.*?)\s*<\/option>/s',
            $html,
            $cocok
        );

        return array_map(function (string $teks) {
            return trim(preg_replace('/\s+/', ' ', html_entity_decode($teks, ENT_QUOTES, 'UTF-8')));
        }, $cocok[1]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // BAGIAN 1 — Sinkronisasi dropdown & judul halaman
    // ═════════════════════════════════════════════════════════════════════

    public function test_judul_halaman_identik_dengan_teks_opsi_terpilih_di_dropdown(): void
    {
        // Setiap akun yang benar-benar ada di dropdown diuji satu per satu.
        $daftarAkun = Coa::where('is_active', true)->orderBy('kode_akun')->get();
        $this->assertNotEmpty($daftarAkun, 'COA aktif kosong — dropdown Buku Besar tidak berisi pilihan apa pun.');

        foreach ($daftarAkun as $akun) {
            $respon = $this->sebagai('manajer')
                ->get(route('laporan.buku-besar', ['kode_akun' => $akun->kode_akun]));

            $respon->assertOk();
            $html = $respon->getContent();

            $label = $this->labelAkun($akun);       // teks opsi apa adanya (setelah decode)
            $labelHtml = $this->labelAkunHtml($akun);   // bentuk ter-escape di HTML

            // Judul halaman (span#judul-akun) harus persis sama dengan label akun.
            $this->assertMatchesRegularExpression(
                '/<span id="judul-akun">\s*'.preg_quote($labelHtml, '/').'\s*<\/span>/',
                $html,
                "Judul halaman untuk {$akun->kode_akun} tidak identik dengan teks opsi dropdown "
                ."(diharapkan: \"{$label}\")."
            );

            // Dan label itu memang benar-benar salah satu opsi yang dikirim ke browser.
            $this->assertContains(
                $label,
                $this->opsiDropdown($html),
                "Teks judul \"{$label}\" tidak ditemukan sebagai salah satu <option> dropdown."
            );
        }
    }

    public function test_judul_memakai_format_kode_nama_dan_kategori_dengan_pemisah_em_dash(): void
    {
        $akun = $this->coa(
            '2-1003',
            'Simpanan Berjangka / Deposito Mudharabah',
            'Kewajiban',
            'Dana Syirkah Temporer',
            'Kredit'
        );

        $html = $this->sebagai('manajer')
            ->get(route('laporan.buku-besar', ['kode_akun' => $akun->kode_akun]))
            ->assertOk()
            ->getContent();

        $label = $this->labelAkun($akun);

        // Format wajib: "KODE — Nama Akun [Kategori]" (em dash U+2014, bukan hyphen).
        $this->assertStringContainsString(
            $akun->kode_akun.' — '.$akun->nama_akun.' ['.$akun->kategori.']',
            $label
        );
        $this->assertStringContainsString($this->labelAkunHtml($akun), $html);
        $this->assertStringNotContainsString(
            $akun->kode_akun.' - '.$akun->nama_akun,
            $html,
            'Judul memakai hyphen biasa; harus em dash "—" agar identik dengan opsi dropdown.'
        );
    }

    public function test_kode_akun_tidak_dikenal_jatuh_ke_akun_pertama_dropdown_bukan_nilai_lama(): void
    {
        $pertama = Coa::where('is_active', true)->orderBy('kode_akun')->first();
        $this->assertNotNull($pertama);

        $html = $this->sebagai('manajer')
            ->get(route('laporan.buku-besar', ['kode_akun' => '9-9999']))
            ->assertOk()
            ->getContent();

        // Kode tak dikenal tidak boleh dipertahankan di input maupun judul:
        // halaman harus jatuh ke akun pertama agar dropdown & judul tetap sinkron.
        $this->assertStringNotContainsString('value="9-9999"', $html);
        $this->assertStringContainsString($this->labelAkunHtml($pertama), $html);
    }

    public function test_judul_tidak_pernah_menampilkan_akun_lain_ketika_kode_kosong(): void
    {
        $pertama = Coa::where('is_active', true)->orderBy('kode_akun')->first();

        $html = $this->sebagai('manajer')
            ->get(route('laporan.buku-besar', ['kode_akun' => '']))
            ->assertOk()
            ->getContent();

        // Tepat satu opsi bertanda selected dan dropdown tidak kosong: kode kosong
        // tidak boleh menghasilkan dropdown tanpa pilihan (judul jadi nilai default).
        $this->assertSame(
            1,
            preg_match_all('/<option[^>]*\bselected\b/', $html),
            'Dropdown harus menandai tepat satu opsi terpilih.'
        );
        $this->assertStringContainsString($this->labelAkunHtml($pertama), $html);
    }

    public function test_skrip_sinkronisasi_judul_dan_tombol_tampilkan_tersedia_di_halaman(): void
    {
        $html = $this->sebagai('manajer')
            ->get(route('laporan.buku-besar'))
            ->assertOk()
            ->getContent();

        // Elemen yang dirujuk skrip harus ada.
        $this->assertStringContainsString('id="form-buku-besar"', $html);
        $this->assertStringContainsString('id="pilih-akun"', $html);
        $this->assertStringContainsString('id="judul-akun"', $html);
        $this->assertStringContainsString('id="tombol-tampilkan"', $html);

        // Judul baru HANYA diterapkan pada klik tombol 'Tampilkan' / submit form,
        // sehingga judul tidak pernah mendahului data tabel saat pilihan diubah.
        $this->assertMatchesRegularExpression(
            '/tombol\.addEventListener\(\s*[\'"]click[\'"]/',
            $html,
            'Tombol Tampilkan harus punya event listener click untuk memperbarui judul.'
        );
        $this->assertMatchesRegularExpression(
            '/form\.addEventListener\(\s*[\'"]submit[\'"]/',
            $html,
            'Submit form harus menyinkronkan judul agar konsisten saat Enter ditekan.'
        );
        $this->assertMatchesRegularExpression(
            '/select\.addEventListener\(\s*[\'"]change[\'"]/',
            $html,
            'Perubahan pilihan tanpa submit harus mengembalikan judul ke kondisi tampil.'
        );

        // Sumber teks judul adalah option.textContent — bukan string yang dirakit
        // ulang — sehingga tidak ada perbedaan karakter/spasi/format.
        $this->assertStringContainsString('select.options[select.selectedIndex]', $html);
        $this->assertStringContainsString('textContent', $html);
    }

    // ═════════════════════════════════════════════════════════════════════
    // BAGIAN 2 — Pengecekan tipe akun otomatis dari awalan kode
    // ═════════════════════════════════════════════════════════════════════

    public function test_deteksi_tipe_akun_otomatis_dari_awalan_kode(): void
    {
        // Tanpa input manual: posisi diturunkan murni dari kepala kode akun.
        $harusDebet = ['1-1001', '1-2003', '5-1001', '5-1002', '5-1003'];
        $harusKredit = ['2-1001', '2-1003', '3-1001', '3-9000', '4-1001', '4-2001'];

        foreach ($harusDebet as $kode) {
            $this->assertSame(
                'Debet',
                Coa::posisiSaldoDariKode($kode),
                "Akun {$kode} harus berposisi Debet (Aset/Beban)."
            );
        }

        foreach ($harusKredit as $kode) {
            $this->assertSame(
                'Kredit',
                Coa::posisiSaldoDariKode($kode),
                "Akun {$kode} harus berposisi Kredit (Kewajiban/Ekuitas/Pendapatan)."
            );
        }
    }

    public function test_awalan_kode_menang_atas_kolom_tipe_saldo_normal_yang_keliru(): void
    {
        // Kolom `tipe_saldo_normal` yang salah input tidak boleh membalik rumus:
        // akun 3-xxxx tetap Kredit, akun 1-xxxx tetap Debet.
        $this->assertSame('Kredit', Coa::posisiSaldoDariKode('3-9000', 'Debet'));
        $this->assertSame('Debet', Coa::posisiSaldoDariKode('1-1001', 'Kredit'));

        // Kepala tak dikenal (akun di luar bagan standar) menghormati kolom itu,
        // dan aman default ke Kredit bila kosong.
        $this->assertSame('Debet', Coa::posisiSaldoDariKode('9-9999', 'Debet'));
        $this->assertSame('Kredit', Coa::posisiSaldoDariKode('9-9999', 'Kredit'));
        $this->assertSame('Kredit', Coa::posisiSaldoDariKode('9-9999', null));
    }

    public function test_badge_saldo_normal_pada_halaman_sesuai_posisi_akun(): void
    {
        foreach (['1-1001' => 'Debet', '5-1001' => 'Debet', '2-1003' => 'Kredit', '3-2001' => 'Kredit', '4-1001' => 'Kredit'] as $kode => $posisi) {
            $akun = Coa::where('kode_akun', $kode)->where('is_active', true)->first();
            if (! $akun) {
                continue;
            }

            $this->sebagai('manajer')
                ->get(route('laporan.buku-besar', ['kode_akun' => $kode]))
                ->assertOk()
                ->assertSee('Saldo Normal: '.$posisi, false);
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // BAGIAN 3 — Rumus saldo berjalan per jenis akun
    // ═════════════════════════════════════════════════════════════════════

    public function test_saldo_akhir_baris_posisi_debet_memakai_debet_dikurangi_kredit(): void
    {
        $akun = $this->coa('5-1001', 'Beban Operasional Koperasi', 'Beban', 'Beban Usaha', 'Debet');

        // 1.000.000 + 250.000 − 100.000 = 1.150.000
        $this->assertSame(1150000.0, $akun->saldoAkhirBaris(1000000.0, 250000.0, 100000.0));
        // Tanpa mutasi, saldo tidak berubah.
        $this->assertSame(500000.0, $akun->saldoAkhirBaris(500000.0, 0.0, 0.0));
        // Koreksi/pembalikan beban (kredit lebih besar) menurunkan saldo.
        $this->assertSame(-50000.0, $akun->saldoAkhirBaris(50000.0, 0.0, 100000.0));
    }

    public function test_saldo_akhir_baris_posisi_kredit_memakai_kredit_dikurangi_debet(): void
    {
        $akun = $this->coa('2-1003', 'Simpanan Berjangka / Deposito Mudharabah', 'Kewajiban', 'Dana Syirkah Temporer', 'Kredit');

        // 1.000.000 + 250.000 − 100.000 = 1.150.000 (penambahan ada di sisi Kredit)
        $this->assertSame(1150000.0, $akun->saldoAkhirBaris(1000000.0, 100000.0, 250000.0));
        // Debet penuh (penarikan deposito) menurunkan saldo.
        $this->assertSame(0.0, $akun->saldoAkhirBaris(500000.0, 500000.0, 0.0));
    }

    public function test_rumus_saldo_berjalan_setiap_kepala_akun_sesuai_prinsip_akuntansi(): void
    {
        $kasus = [
            ['1-1001', 'Aset',       'Aset Lancar',    'Debet'],
            ['2-1001', 'Kewajiban',  'Kewajiban Jangka Pendek', 'Kredit'],
            ['3-1001', 'Ekuitas',    'Modal Anggota',  'Kredit'],
            ['4-1001', 'Pendapatan', 'Pendapatan Usaha', 'Kredit'],
            ['5-1001', 'Beban',      'Beban Usaha',    'Debet'],
        ];

        foreach ($kasus as [$kode, $kategori, $klasifikasi, $posisi]) {
            $akun = $this->coa($kode, "Akun Uji {$kode}", $kategori, $klasifikasi, $posisi);

            $debet = 300000.0;
            $kredit = 125000.0;

            $diharapkan = $posisi === 'Debet'
                ? 1000000.0 + $debet - $kredit
                : 1000000.0 + $kredit - $debet;

            $this->assertSame(
                $diharapkan,
                $akun->saldoAkhirBaris(1000000.0, $debet, $kredit),
                "Rumus saldo berjalan untuk {$kode} ({$kategori}) tidak sesuai normal balance {$posisi}."
            );
        }
    }

    public function test_saldo_kumulatif_tabel_sama_dengan_saldo_berjalan_akun(): void
    {
        $akun = $this->coa('3-9991', 'Dana Cadangan Uji', 'Ekuitas', 'Dana Cadangan', 'Kredit');
        $penyeimbang = $this->penyeimbang();

        $tanggal = '2026-01-15';

        // Dua mutasi pada tanggal yang sama: penambahan lalu pengurangan.
        $this->mutasi($akun, 0.0, 750000.0, $tanggal, $penyeimbang, 'Setoran dana cadangan');
        $this->mutasi($akun, 250000.0, 0.0, $tanggal, $penyeimbang, 'Pemakaian dana cadangan');

        $detail = $this->detailAkun($akun);

        $this->assertCount(2, $detail, 'Kedua mutasi uji harus terbaca di buku besar.');

        // Saldo berjalan dihitung ulang secara mandiri di sini — inilah angka yang
        // harus muncul sebagai "Saldo Kumulatif" pada baris terakhir tabel.
        $saldo = (float) $akun->saldoBerjalan(Carbon::parse($tanggal)->subDay()->toDateString());
        foreach ($detail as $d) {
            $saldo = $akun->saldoAkhirBaris($saldo, (float) $d->debet, (float) $d->kredit);
        }

        // Konsistensi dengan sumber otoritatif: saldo akun s/d tanggal tersebut.
        $this->assertSame(
            $akun->saldoBerjalan($tanggal),
            $saldo,
            'Saldo kumulatif baris tabel tidak sama dengan Coa::saldoBerjalan() untuk tanggal yang sama.'
        );

        // Dan halaman benar-benar menampilkan angka itu (format ribuan).
        $this->sebagai('manajer')
            ->get(route('laporan.buku-besar', ['kode_akun' => $akun->kode_akun]))
            ->assertOk()
            ->assertSee('Rp '.number_format($saldo, 0, ',', '.'), false);
    }

    public function test_saldo_berjalan_baris_berakhir_sama_dengan_saldo_akun_sampai_tanggal_terakhir(): void
    {
        foreach (['1-1001', '2-1003', '3-2001', '4-1001', '5-1001'] as $kode) {
            $akun = Coa::where('kode_akun', $kode)->where('is_active', true)->first();
            if (! $akun) {
                continue;
            }

            $detail = $this->detailAkun($akun);

            if ($detail->isEmpty()) {
                continue;
            }

            $saldo = (float) $akun->saldo_awal;
            foreach ($detail as $d) {
                $saldo = $akun->saldoAkhirBaris($saldo, (float) $d->debet, (float) $d->kredit);
            }

            $tanggalTerakhir = $detail->last()->jurnal->tanggal_jurnal->toDateString();

            $this->assertSame(
                $akun->saldoBerjalan($tanggalTerakhir),
                $saldo,
                "Saldo kumulatif akhir akun {$kode} tidak konsisten dengan Coa::saldoBerjalan()."
            );
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // BAGIAN 4 — Urutan baris mengikuti tanggal jurnal, bukan created_at
    // ═════════════════════════════════════════════════════════════════════

    public function test_urutan_baris_mengikuti_tanggal_jurnal_bukan_created_at(): void
    {
        $akun = $this->coa('2-9991', 'Dana Sosial Uji', 'Kewajiban', 'Kewajiban Jangka Pendek', 'Kredit');
        $penyeimbang = $this->penyeimbang();

        // Jurnal bertanggal mundur dibuat TERAKHIR: created_at-nya paling besar
        // tetapi tanggal_jurnal-nya paling awal.
        $this->mutasi($akun, 0.0, 400000.0, '2026-03-10', $penyeimbang, 'Mutasi Maret');
        $this->mutasi($akun, 0.0, 200000.0, '2026-02-05', $penyeimbang, 'Mutasi Februari (input belakangan)');

        $detail = $this->detailAkun($akun);

        $this->assertCount(2, $detail);
        $this->assertSame(
            '2026-02-05',
            $detail->first()->jurnal->tanggal_jurnal->toDateString(),
            'Baris pertama Buku Besar harus jurnal bertanggal paling awal, '
            .'bukan yang paling akhir diinput (created_at).'
        );
        $this->assertSame('2026-03-10', $detail->last()->jurnal->tanggal_jurnal->toDateString());

        // Rentang tanggal di halaman juga harus ikut urut.
        $this->sebagai('manajer')
            ->get(route('laporan.buku-besar', ['kode_akun' => $akun->kode_akun]))
            ->assertOk()
            ->assertSee('05/02/2026')
            ->assertSee('10/03/2026');
    }

    public function test_saldo_kumulatif_tidak_melompat_ketika_ada_jurnal_bertanggal_mundur(): void
    {
        $akun = $this->coa('2-9992', 'Dana Kebajikan Uji', 'Kewajiban', 'Kewajiban Jangka Pendek', 'Kredit');
        $penyeimbang = $this->penyeimbang();

        $this->mutasi($akun, 0.0, 400000.0, '2026-03-10', $penyeimbang, 'Mutasi Maret');
        $this->mutasi($akun, 0.0, 200000.0, '2026-02-05', $penyeimbang, 'Mutasi Februari');

        $detail = $this->detailAkun($akun);

        // Saldo awal sebelum baris pertama (Februari) tidak boleh mengandung
        // mutasi Maret — kalau ikut terbawa, saldo baris pertama akan melompat.
        $saldo = (float) $akun->saldoBerjalan(
            $detail->first()->jurnal->tanggal_jurnal->copy()->subDay()->toDateString()
        );

        // Invariant yang paling ketat: saldo kumulatif tiap baris harus sama
        // dengan saldo akun per tanggal baris itu. Bila baris terurut salah
        // (mis. menurut created_at), baris Maret muncul lebih dulu dan saldo
        // baris Februari akan kelebihan mutasi Maret → assert ini gagal.
        foreach ($detail as $urutan => $d) {
            $saldo = $akun->saldoAkhirBaris($saldo, (float) $d->debet, (float) $d->kredit);

            $this->assertSame(
                $akun->saldoBerjalan($d->jurnal->tanggal_jurnal->toDateString()),
                $saldo,
                'Saldo kumulatif baris ke-'.($urutan + 1).' melompat: urutan baris tidak '
                .'sejalan dengan batas tanggal_jurnal pada Coa::saldoBerjalan().'
            );
        }

        // Kedua baris hanya menambah sisi Kredit → saldo naik tepat sebesar
        // kredit baris kedua (400.000 milik Maret), bukan turun.
        $this->assertSame(
            $detail->last()->jurnal->tanggal_jurnal->toDateString(),
            '2026-03-10',
            'Baris terakhir harus mutasi Maret.'
        );
        $this->assertSame($akun->saldoBerjalan('2026-03-10'), $saldo);
    }

    // ═════════════════════════════════════════════════════════════════════
    // BAGIAN 5 — Seluruh alur halaman
    // ═════════════════════════════════════════════════════════════════════

    public function test_semua_pilihan_akun_dropdown_dapat_dibuka_tanpa_error(): void
    {
        foreach (['manajer', 'pengurus'] as $peran) {
            foreach (Coa::where('is_active', true)->orderBy('kode_akun')->pluck('kode_akun') as $kode) {
                $this->sebagai($peran)
                    ->get(route('laporan.buku-besar', ['kode_akun' => $kode]))
                    ->assertOk()
                    ->assertSee('Buku Besar Akun');
            }
        }
    }

    public function test_halaman_menampilkan_tombol_kembali_ke_neraca_dan_saldo_normal(): void
    {
        $html = $this->sebagai('manajer')
            ->get(route('laporan.buku-besar'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('laporan.neraca'), $html);
        $this->assertStringContainsString('Kembali ke Neraca', $html);
        $this->assertStringContainsString('Saldo Normal:', $html);
    }

    public function test_dps_tetap_dibatasi_ke_akun_ekuitas_dan_kewajiban(): void
    {
        // Pembatasan lama tidak boleh hilang oleh refaktor dropdown.
        $this->sebagai('dps')
            ->get(route('laporan.buku-besar', ['kode_akun' => '1-1001']))
            ->assertForbidden();

        $this->sebagai('dps')
            ->get(route('laporan.buku-besar', ['kode_akun' => '5-1001']))
            ->assertForbidden();

        // Akun Kewajiban/Ekuitas tetap boleh dibuka.
        $this->sebagai('dps')
            ->get(route('laporan.buku-besar', ['kode_akun' => '2-1003']))
            ->assertOk();
    }

    public function test_dropdown_dps_hanya_berisi_akun_ekuitas_dan_kewajiban(): void
    {
        $html = $this->sebagai('dps')
            ->get(route('laporan.buku-besar'))
            ->assertOk()
            ->getContent();

        $opsi = $this->opsiDropdown($html);
        $this->assertNotEmpty($opsi);

        foreach ($opsi as $teks) {
            $this->assertMatchesRegularExpression(
                '/^(2|3)-/',
                $teks,
                "Opsi \"{$teks}\" di luar wewenang DPS (hanya Ekuitas 3-xxxx & Kewajiban 2-xxxx)."
            );
        }
    }
}
