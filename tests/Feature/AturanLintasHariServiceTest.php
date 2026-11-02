<?php

namespace Tests\Feature;

use App\Models\HariLibur;
use App\Models\KasHarian;
use App\Models\User;
use App\Services\AturanLintasHariService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * TAHAP 2 — UNIT TEST MODUL ATURAN LINTAS HARI.
 *
 * Seluruh cabang `AturanLintasHariService` diuji langsung (tanpa HTTP):
 *   • tanggal bisnis & cut-off tengah malam,
 *   • konversi zona waktu (Asia/Jakarta, tanpa DST),
 *   • selisih hari termasuk tahun kabisat,
 *   • hari kerja / akhir pekan / hari libur dinamis,
 *   • validasi & normalisasi tanggal sesi,
 *   • deteksi sesi kas yang belum ditutup pada hari sebelumnya.
 *
 * Catatan DST: zona operasional `Asia/Jakarta` memakai offset tetap +07:00
 * sepanjang tahun. Perilaku "tanpa DST" tetap diuji eksplisit agar asumsi
 * ini tidak berubah diam-diam bila konfigurasi zona diubah.
 */
class AturanLintasHariServiceTest extends TestCase
{
    use DatabaseTransactions;

    private AturanLintasHariService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(AturanLintasHariService::class);
        Carbon::setTestNow();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* =====================================================================
     | HELPER
     |===================================================================== */

    private function teller(): User
    {
        return User::firstOrCreate(
            ['email' => 'teller.lintashari@test.local'],
            [
                'name' => 'Teller Lintas Hari',
                'password' => Hash::make('password'),
                'role' => 'teller',
                'is_active' => true,
            ]
        );
    }

    private function sesi(User $user, string $tanggal, string $status): KasHarian
    {
        return KasHarian::create([
            'user_id' => $user->getKey(),
            'tanggal' => $tanggal,
            'modal_awal' => 0,
            'tambah_modal' => [],
            'waktu_opening' => '08:00:00',
            'status_opname' => $status,
        ]);
    }

    /* =====================================================================
     | ZONA WAKTU & TANGGAL BISNIS
     |===================================================================== */

    public function test_zona_waktu_mengikuti_konfigurasi_aplikasi(): void
    {
        $this->assertSame('Asia/Jakarta', config('app.timezone'));
        $this->assertSame(config('app.timezone'), $this->service->zonaWaktu());
    }

    public function test_tanggal_bisnis_memakai_zona_waktu_operasional(): void
    {
        // 17:30 UTC = 00:30 WIB keesokan harinya.
        $this->travelTo(Carbon::parse('2026-10-18 17:30:00', 'UTC'));

        $this->assertSame('2026-10-19', $this->service->tanggalBisnis());
        $this->assertSame('2026-10-19 00:30:00', $this->service->sekarang()->format('Y-m-d H:i:s'));
    }

    public function test_tanggal_bisnis_string_dianggap_waktu_lokal(): void
    {
        $this->assertSame('2026-10-18', $this->service->tanggalBisnis('2026-10-18 23:30:00'));
    }

    public function test_cut_off_tengah_malam_menggeser_ke_hari_sebelumnya(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 00:30:00', 'Asia/Jakarta'));

        // Shift malam: transaksi 00:30 masih milik tanggal bisnis 18 Okt.
        $this->assertSame('2026-10-18', $this->service->tanggalBisnis(null, 120));
        $this->assertSame('2026-10-18', $this->service->tanggalBisnis(null, 31));
    }

    public function test_cut_off_tengah_malam_batas_tepat_tidak_digeser(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 02:00:00', 'Asia/Jakarta'));

        // 02:00 = 120 menit; syarat geser adalah menit < batas (ketat).
        $this->assertSame('2026-10-19', $this->service->tanggalBisnis(null, 120));
        $this->assertSame('2026-10-18', $this->service->tanggalBisnis(null, 121));
    }

    public function test_cut_off_tengah_malam_di_clamp_ke_rentang_sah(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 10:00:00', 'Asia/Jakarta'));

        // Nilai negatif diperlakukan 0 (tanpa geser).
        $this->assertSame('2026-10-19', $this->service->tanggalBisnis(null, -100));

        // Nilai > 1439 diperlakukan 1439 (hanya 23:59 yang tidak digeser).
        $this->assertSame('2026-10-18', $this->service->tanggalBisnis(null, 5000));
    }

    public function test_zona_operasional_tanpa_perubahan_dst(): void
    {
        // Asia/Jakarta = UTC+7 tetap, baik Januari maupun Juli.
        $januari = Carbon::parse('2026-01-15 12:00:00', 'Asia/Jakarta');
        $juli = Carbon::parse('2026-07-15 12:00:00', 'Asia/Jakarta');

        $this->assertSame('+07:00', $januari->format('P'));
        $this->assertSame('+07:00', $juli->format('P'));

        // Pergantian hari tetap terjadi tepat 00:00 lokal, bukan 23:00/01:00.
        $this->assertSame('2026-01-15', $this->service->tanggalBisnis('2026-01-15 00:00:00'));
        $this->assertSame('2026-01-16', $this->service->tanggalBisnis('2026-01-16 00:00:00'));
    }

    /* =====================================================================
     | SELISIH HARI & TAHUN KABISAT
     |===================================================================== */

    public function test_selisih_hari_positif_negatif_dan_nol(): void
    {
        $this->assertSame(0, $this->service->selisihHari('2026-10-19', '2026-10-19'));
        $this->assertSame(1, $this->service->selisihHari('2026-10-19', '2026-10-20'));
        $this->assertSame(-1, $this->service->selisihHari('2026-10-19', '2026-10-18'));
        $this->assertSame(2, $this->service->selisihHari('2026-10-17', '2026-10-19'));
    }

    public function test_selisih_hari_tidak_terpengaruh_jam(): void
    {
        // 23:59 → 00:01 tetap dihitung 1 hari (dasar: awal hari).
        $this->assertSame(1, $this->service->selisihHari(
            '2026-10-18 23:59:59',
            '2026-10-19 00:00:01'
        ));
    }

    public function test_selisih_hari_memakai_zona_operasional(): void
    {
        // 17:00 UTC 18 Okt = 00:00 WIB 19 Okt → selisih 0 terhadap 19 Okt.
        $this->assertSame(0, $this->service->selisihHari(
            Carbon::parse('2026-10-18 17:00:00', 'UTC'),
            '2026-10-19'
        ));
    }

    public function test_selisih_hari_melewati_tahun_kabisat(): void
    {
        $this->assertSame(2, $this->service->selisihHari('2028-02-28', '2028-03-01'));
        $this->assertSame(366, $this->service->selisihHari('2028-02-28', '2029-02-28'));
        $this->assertSame(365, $this->service->selisihHari('2026-02-28', '2027-02-28'));
    }

    public function test_29_februari_tahun_kabisat_dikenali_sebagai_hari_kerja(): void
    {
        // 29 Feb 2028 = Selasa.
        $this->assertSame('Selasa', $this->service->hariKerjaBerikutnya('2028-02-29')->translatedFormat('l'));
        $this->assertTrue($this->service->adalahHariKerja('2028-02-29'));
        $this->assertSame('2028-02-29', $this->service->hariKerjaBerikutnya('2028-02-29')->toDateString());
    }

    public function test_tanggal_overflow_ditolak(): void
    {
        $this->assertFalse($this->service->tanggalSesiSah('2027-02-29', '2027-03-01'));
        $this->assertSame('2026-10-19', $this->service->normalisasiTanggal('2027-02-29', '2026-10-19'));
    }

    /* =====================================================================
     | HARI KERJA, AKHIR PEKAN, HARI LIBUR
     |===================================================================== */

    public function test_hari_kerja_menolak_sabtu_dan_minggu(): void
    {
        $this->assertFalse($this->service->adalahHariKerja('2026-10-17')); // Sabtu
        $this->assertFalse($this->service->adalahHariKerja('2026-10-18')); // Minggu
        $this->assertTrue($this->service->adalahHariKerja('2026-10-19'));  // Senin
    }

    public function test_hari_kerja_menolak_hari_libur_aktif(): void
    {
        HariLibur::create([
            'tanggal' => '2026-10-19',
            'keterangan' => 'Libur Uji',
            'jenis' => HariLibur::JENIS_KOPERASI,
            'is_active' => true,
        ]);

        $this->assertFalse($this->service->adalahHariKerja('2026-10-19'));
    }

    public function test_hari_libur_nonaktif_tidak_dihitung(): void
    {
        HariLibur::create([
            'tanggal' => '2026-10-19',
            'keterangan' => 'Libur Nonaktif Uji',
            'jenis' => HariLibur::JENIS_KOPERASI,
            'is_active' => false,
        ]);

        $this->assertTrue($this->service->adalahHariKerja('2026-10-19'));
    }

    public function test_hari_kerja_berikutnya_dari_akhir_pekan_ke_senin(): void
    {
        $this->assertSame('2026-10-19', $this->service->hariKerjaBerikutnya('2026-10-17')->toDateString());
        $this->assertSame('2026-10-19', $this->service->hariKerjaBerikutnya('2026-10-18')->toDateString());
    }

    public function test_hari_kerja_berikutnya_mempertahankan_hari_kerja(): void
    {
        $this->assertSame('2026-10-16', $this->service->hariKerjaBerikutnya('2026-10-16')->toDateString());
    }

    public function test_hari_kerja_berikutnya_melompati_libur_beruntun(): void
    {
        HariLibur::create([
            'tanggal' => '2026-10-19',
            'keterangan' => 'Libur Uji Senin',
            'jenis' => HariLibur::JENIS_NASIONAL,
            'is_active' => true,
        ]);

        $this->assertSame('2026-10-20', $this->service->hariKerjaBerikutnya('2026-10-17')->toDateString());
    }

    public function test_hari_kerja_sebelumnya_mempertahankan_hari_kerja_dan_mundur_dari_akhir_pekan(): void
    {
        // Hari kerja tetap dipertahankan (sama dengan tanggal acuan).
        $this->assertSame('2026-10-19', $this->service->hariKerjaSebelumnya('2026-10-19')->toDateString());

        // Minggu 18 Okt 2026 → Jumat 16 Okt 2026.
        $this->assertSame('2026-10-16', $this->service->hariKerjaSebelumnya('2026-10-18')->toDateString());
    }

    /* =====================================================================
     | VALIDASI & NORMALISASI TANGGAL SESI
     |===================================================================== */

    public function test_tanggal_sesi_sah_hanya_sampai_hari_ini(): void
    {
        $this->assertTrue($this->service->tanggalSesiSah('2026-10-18', '2026-10-19'));
        $this->assertTrue($this->service->tanggalSesiSah('2026-10-19', '2026-10-19'));
        $this->assertFalse($this->service->tanggalSesiSah('2026-10-20', '2026-10-19'));
    }

    public function test_tanggal_sesi_sah_menolak_format_salah(): void
    {
        $this->assertFalse($this->service->tanggalSesiSah('18-10-2026', '2026-10-19'));
        $this->assertFalse($this->service->tanggalSesiSah('', '2026-10-19'));
        $this->assertFalse($this->service->tanggalSesiSah('2026-13-01', '2026-10-19'));
    }

    public function test_normalisasi_tanggal_mengembalikan_acuan_untuk_input_tidak_sah(): void
    {
        $acuan = '2026-10-19';

        $this->assertSame($acuan, $this->service->normalisasiTanggal(null, $acuan));
        $this->assertSame($acuan, $this->service->normalisasiTanggal('   ', $acuan));
        $this->assertSame($acuan, $this->service->normalisasiTanggal('bukan-tanggal', $acuan));
        $this->assertSame($acuan, $this->service->normalisasiTanggal('2026-12-31', $acuan));
    }

    public function test_normalisasi_tanggal_mempertahankan_tanggal_lampau(): void
    {
        $this->assertSame('2026-10-14', $this->service->normalisasiTanggal('2026-10-14', '2026-10-19'));
        $this->assertSame('2026-10-19', $this->service->normalisasiTanggal('2026-10-19', '2026-10-19'));
    }

    /* =====================================================================
     | SESI KAS BELUM DITUTUP (LINTAS HARI)
     |===================================================================== */

    public function test_sesi_belum_tutup_hanya_open_dan_pending_sebelum_acuan(): void
    {
        $teller = $this->teller();

        $this->sesi($teller, '2026-10-13', KasHarian::STATUS_CLOSED);
        $this->sesi($teller, '2026-10-14', KasHarian::STATUS_OPEN);
        $this->sesi($teller, '2026-10-15', KasHarian::STATUS_PENDING_APPROVAL);
        $this->sesi($teller, '2026-10-19', KasHarian::STATUS_OPEN); // hari ini

        $daftar = $this->service->daftarSesiBelumTutup($teller->id, '2026-10-19');

        $this->assertSame(
            ['2026-10-15', '2026-10-14'],
            $daftar->map(fn (KasHarian $k) => $k->tanggal->toDateString())->all()
        );
    }

    public function test_sesi_belum_tutup_mengembalikan_yang_terbaru(): void
    {
        $teller = $this->teller();

        $this->sesi($teller, '2026-10-14', KasHarian::STATUS_OPEN);
        $this->sesi($teller, '2026-10-16', KasHarian::STATUS_OPEN);

        $this->assertSame(
            '2026-10-16',
            $this->service->sesiBelumTutupSebelum($teller->id, '2026-10-19')?->tanggal->toDateString()
        );
    }

    public function test_sesi_belum_tutup_null_bila_semua_selesai(): void
    {
        $teller = $this->teller();

        $this->sesi($teller, '2026-10-14', KasHarian::STATUS_CLOSED);
        $this->sesi($teller, '2026-10-19', KasHarian::STATUS_OPEN);

        $this->assertNull($this->service->sesiBelumTutupSebelum($teller->id, '2026-10-19'));
        $this->assertTrue($this->service->daftarSesiBelumTutup($teller->id, '2026-10-19')->isEmpty());
    }

    public function test_sesi_belum_tutup_mengabaikan_milik_teller_lain(): void
    {
        $teller = $this->teller();
        $lain = User::firstOrCreate(
            ['email' => 'teller.lintashari.lain@test.local'],
            [
                'name' => 'Teller Lain',
                'password' => Hash::make('password'),
                'role' => 'teller',
                'is_active' => true,
            ]
        );

        $this->sesi($lain, '2026-10-14', KasHarian::STATUS_OPEN);

        $this->assertNull($this->service->sesiBelumTutupSebelum($teller->id, '2026-10-19'));
    }
}
