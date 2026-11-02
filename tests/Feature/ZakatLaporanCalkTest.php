<?php

namespace Tests\Feature;

use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\User;
use App\Models\ZakatTransaksi;
use App\Services\LaporanKeuanganSyariahService;
use App\Support\Coa as Akun;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * R-09 (Z-09) — Penyajian laporan sumber & penggunaan dana zakat pada bundel
 * laporan keuangan syariah (CaLK 6.g), bukan lagi hanya di halaman modul.
 *
 * Prinsip yang diuji:
 *   A. Komponen `zakat` tersedia dari `semuaKomponen()` dan konsisten dengan
 *      komponen DIT — keduanya membaca akun & mutasi jurnal yang sama.
 *   B. Rincian per jenis zakat & per asnaf benar-benar diambil dari buku besar
 *      modul (`zakat_transaksis`), tidak dikarang dari jurnal.
 *   C. Rekonsiliasi jurnal vs buku besar modul terdeteksi sebagai temuan, bukan
 *      ditutup diam-diam.
 *   D. Hak amil dihitung dari parameter kebijakan (Fatwa DSN-MUI 08), bukan
 *      angka tetap.
 *   E. Halaman & ekspor Excel menyajikan sub-bagian 6.g tanpa error.
 *
 * Catatan: nilai absolut tidak dijadikan patokan karena basis data dev juga
 * berisi data nyata; pengujian memakai SELISIH (delta) agar deterministik.
 */
class ZakatLaporanCalkTest extends TestCase
{
    use DatabaseTransactions;

    private const PERAN_BERWENANG = 'pengurus';

    protected function setUp(): void
    {
        parent::setUp();

        User::updateOrCreate(
            ['email' => 'calk.zakat@test.local'],
            [
                'name' => 'Pengurus CaLK Zakat',
                'password' => Hash::make('password'),
                'role' => self::PERAN_BERWENANG,
            ]
        );
    }

    protected function tearDown(): void
    {
        Setting::flush();

        parent::tearDown();
    }

    private function sebagai(string $peran = self::PERAN_BERWENANG): self
    {
        $user = User::where('role', $peran)->orderBy('id')->firstOrFail();
        $this->actingAs($user);

        return $this;
    }

    private function layanan(): LaporanKeuanganSyariahService
    {
        return app(LaporanKeuanganSyariahService::class);
    }

    private function komponen(): array
    {
        return $this->layanan()->semuaKomponen($this->layanan()->periode('', ''));
    }

    /** Posting jurnal kas ↔ akun lawan dan kembalikan id jurnalnya. */
    private function jurnal(Coa $akunLawan, float $nominal, bool $kasMasuk): int
    {
        $kas = Coa::whereIn('kode_akun', Akun::AKUN_KAS_ARUS_KAS)
            ->orderBy('kode_akun')
            ->firstOrFail();

        $jurnal = Jurnal::create([
            'no_jurnal' => 'CALK-'.uniqid(),
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'UJI',
            'keterangan' => 'Uji CaLK zakat',
            'total_debet' => $nominal,
            'total_kredit' => $nominal,
            'status' => 'Posted',
        ]);

        JurnalDetail::create([
            'jurnal_id' => $jurnal->id,
            'coa_id' => $kasMasuk ? $kas->id : $akunLawan->id,
            'baris' => 1,
            'debet' => $nominal,
            'kredit' => 0,
        ]);
        JurnalDetail::create([
            'jurnal_id' => $jurnal->id,
            'coa_id' => $kasMasuk ? $akunLawan->id : $kas->id,
            'baris' => 2,
            'debet' => 0,
            'kredit' => $nominal,
        ]);

        return $jurnal->id;
    }

    /** Catat satu baris buku besar zakat yang terhubung ke jurnal baru. */
    private function barisZakat(string $jenis, string $kategori, float $jumlah): void
    {
        $kasMasuk = $jenis === ZakatTransaksi::JENIS_PENERIMAAN;
        $akunCoa = Coa::findByKode(Akun::DANA_ZAKAT);
        $jurnalId = $this->jurnal($akunCoa, $jumlah, $kasMasuk);

        ZakatTransaksi::create([
            'no_referensi' => 'CALK-'.uniqid(),
            'jenis' => $jenis,
            'dana' => ZakatTransaksi::DANA_ZAKAT,
            'kategori' => $kategori,
            'pihak' => 'Pihak Uji CaLK',
            'jumlah' => $jumlah,
            'keterangan' => 'Uji CaLK zakat',
            'tanggal' => now()->toDateString(),
            'jurnal_id' => $jurnalId,
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // A. KETERSEDIAAN & STRUKTUR KOMPONEN
    // ═════════════════════════════════════════════════════════════════════

    public function test_komponen_zakat_tersedia_lengkap(): void
    {
        $z = $this->komponen()['zakat'];

        foreach ([
            'akun', 'akunTersedia', 'saldoAwal', 'penerimaan', 'penyaluran',
            'saldoAkhir', 'mutasiSeimbang', 'rincianPenerimaan',
            'rincianPenyaluran', 'totalRincianPenerimaan', 'totalRincianPenyaluran',
            'amil', 'kontrol',
        ] as $kunci) {
            $this->assertArrayHasKey(
                $kunci,
                $z,
                "Komponen zakat harus memuat \"{$kunci}\" agar CaLK 6.g tidak kehilangan data."
            );
        }
    }

    public function test_komponen_zakat_memakai_akun_yang_sama_dengan_dit(): void
    {
        $k = $this->komponen();

        $this->assertSame(
            $k['dit']['Dana Zakat']['kode'] ?? $k['zakat']['akun'],
            $k['zakat']['akun'],
            'Akun zakat pada CaLK harus identik dengan akun Dana Zakat pada komponen DIT.'
        );
    }

    public function test_balasan_saldo_mutasi_konsisten(): void
    {
        $z = $this->komponen()['zakat'];

        $this->assertEqualsWithDelta(
            $z['saldoAwal'] + $z['penerimaan'] - $z['penyaluran'],
            $z['saldoAkhir'],
            max(Setting::toleransiSelisihJurnal(), 0.01),
            'Saldo akhir = saldo awal + penerimaan − penyaluran (mutasi harus seimbang).'
        );
        $this->assertTrue(
            $z['mutasiSeimbang'],
            'Mutasi zakat dari jurnal harus seimbang dalam toleransi kebijakan.'
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // B. RINCIAN DARI BUKU BESAR MODUL
    // ═════════════════════════════════════════════════════════════════════

    public function test_rincian_penerimaan_mengikuti_jenis_zakat(): void
    {
        $sebelum = $this->komponen()['zakat'];
        $jenis = array_key_first(ZakatTransaksi::KATEGORI_PENERIMAAN);

        $this->barisZakat(ZakatTransaksi::JENIS_PENERIMAAN, $jenis, 1_000_000.0);
        Setting::flush();

        $sesudah = $this->komponen()['zakat'];

        $barisSebelum = collect($sebelum['rincianPenerimaan'])->firstWhere('kode', $jenis)['jumlah'];
        $barisSesudah = collect($sesudah['rincianPenerimaan'])->firstWhere('kode', $jenis)['jumlah'];

        $this->assertEqualsWithDelta(
            $barisSebelum + 1_000_000.0,
            $barisSesudah,
            0.01,
            'Penerimaan baru harus menambah baris rincian jenis zakat yang sesuai.'
        );
    }

    public function test_rincian_penyaluran_mengikuti_asnaf(): void
    {
        $sebelum = $this->komponen()['zakat'];
        $asnaf = 'fakir';

        $this->barisZakat(ZakatTransaksi::JENIS_PENYALURAN, $asnaf, 400_000.0);
        Setting::flush();

        $sesudah = $this->komponen()['zakat'];

        $barisSebelum = collect($sebelum['rincianPenyaluran'])->firstWhere('kode', $asnaf)['jumlah'];
        $barisSesudah = collect($sesudah['rincianPenyaluran'])->firstWhere('kode', $asnaf)['jumlah'];

        $this->assertEqualsWithDelta(
            $barisSebelum + 400_000.0,
            $barisSesudah,
            0.01,
            'Penyaluran baru harus menambah baris rincian asnaf yang sesuai.'
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // C. REKONSILIASI — TEMUAN DIEKSPOS, BUKAN DITUTUP
    // ═════════════════════════════════════════════════════════════════════

    public function test_baris_tanpa_jurnal_terdeteksi_sebagai_temuan(): void
    {
        $sebelum = $this->komponen()['zakat']['kontrol'];

        ZakatTransaksi::create([
            'no_referensi' => 'CALK-ORPHAN-'.uniqid(),
            'jenis' => ZakatTransaksi::JENIS_PENERIMAAN,
            'dana' => ZakatTransaksi::DANA_ZAKAT,
            'kategori' => array_key_first(ZakatTransaksi::KATEGORI_PENERIMAAN),
            'pihak' => 'Pihak Uji Tanpa Jurnal',
            'jumlah' => 250_000.0,
            'keterangan' => 'Baris tanpa jurnal',
            'tanggal' => now()->toDateString(),
            'jurnal_id' => null,
        ]);
        Setting::flush();

        $sesudah = $this->komponen()['zakat']['kontrol'];

        $this->assertSame(
            $sebelum['tanpaJurnal'] + 1,
            $sesudah['tanpaJurnal'],
            'Baris buku besar tanpa jurnal wajib terhitung sebagai temuan rekonsiliasi.'
        );
        $this->assertFalse(
            $sesudah['selaras'],
            'Kehadiran baris tanpa jurnal harus membuat status rekonsiliasi tidak selaras.'
        );
    }

    public function test_penyaluran_di_luar_asnaf_terdeteksi(): void
    {
        $sebelum = $this->komponen()['zakat']['kontrol'];

        ZakatTransaksi::create([
            'no_referensi' => 'CALK-LUAR-'.uniqid(),
            'jenis' => ZakatTransaksi::JENIS_PENYALURAN,
            'dana' => ZakatTransaksi::DANA_ZAKAT,
            'kategori' => 'bantuan_pendidikan',
            'pihak' => 'Pihak Uji Kategori Lain',
            'jumlah' => 100_000.0,
            'keterangan' => 'Kategori di luar 8 asnaf',
            'tanggal' => now()->toDateString(),
            'jurnal_id' => null,
        ]);
        Setting::flush();

        $sesudah = $this->komponen()['zakat']['kontrol'];

        $this->assertSame(
            $sebelum['asnafDiLuar'] + 1,
            $sesudah['asnafDiLuar'],
            'Penyaluran dengan kategori di luar 8 asnaf wajib terdeteksi.'
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // D. HAK AMIL DARI PARAMETER KEBIJAKAN
    // ═════════════════════════════════════════════════════════════════════

    public function test_hak_amil_mengikuti_parameter_kebijakan(): void
    {
        $z = $this->komponen()['zakat'];

        $porsi = Setting::zakatPorsiAmilPersen();

        $this->assertSame(
            $porsi,
            $z['amil']['persen'],
            'Persentase hak amil harus dibaca dari parameter kebijakan, bukan angka tetap.'
        );
        $this->assertLessThanOrEqual(
            12.5,
            $z['amil']['persen'],
            'Fatwa DSN-MUI No. 08 membatasi porsi amil maksimal 1/8 (12,5%).'
        );
        $this->assertEqualsWithDelta(
            Setting::zakatHakAmil($z['penerimaan']),
            $z['amil']['hak'],
            0.01,
            'Hak amil = penerimaan × porsi kebijakan.'
        );
        $this->assertEqualsWithDelta(
            $z['amil']['hak'] - $z['amil']['realisasi'],
            $z['amil']['sisa'],
            0.01,
            'Sisa hak amil = hak − realisasi.'
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // E. PENYAJIAN — HALAMAN & EKSPOR EXCEL
    // ═════════════════════════════════════════════════════════════════════

    public function test_halaman_menyajikan_sub_bagian_calk_zakat(): void
    {
        $html = $this->sebagai()->get(route('laporan.psak101'))->assertOk()->getContent();

        $this->assertStringContainsString(
            '6.g Laporan Sumber',
            $html,
            'Halaman PSAK 101 harus menyajikan sub-bagian CaLK 6.g untuk dana zakat.'
        );
        $this->assertStringContainsString(
            'Dana Zakat',
            $html,
            'Judul baris Dana Zakat harus tampil pada sub-bagian 6.g.'
        );
    }

    public function test_ekspor_excel_memuat_sub_bagian_calk_zakat(): void
    {
        $html = $this->sebagai()
            ->get(route('laporan.psak101', ['export' => 'excel']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            '6.g Laporan Sumber',
            $html,
            'Berkas ekspor Excel harus memuat sub-bagian CaLK 6.g.'
        );
        $this->assertStringContainsString(
            '6.g.3 Rekonsiliasi Hak Amil',
            $html,
            'Berkas ekspor Excel harus memuat rekonsiliasi hak amil.'
        );
    }
}
