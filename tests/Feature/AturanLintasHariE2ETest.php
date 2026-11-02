<?php

namespace Tests\Feature;

use App\Models\KasHarian;
use App\Models\TellerOpname;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * TAHAP 2 — END-TO-END TEST ATURAN LINTAS HARI.
 *
 * Menguji rantai HTTP nyata (middleware auth/izin → controller → view/DB)
 * untuk perilaku yang bergantung pada perbedaan tanggal:
 *
 *   A. Buka Kas diblokir bila sesi tanggal sebelumnya belum ditutup.
 *   B. Buka Kas tetap berjalan bila sesi sebelumnya sudah CLOSED.
 *   C. Transaksi melewati tengah malam (shift malam 00:15 WIB).
 *   D. Halaman Closing dapat membuka & memproses sesi tanggal lampau.
 *   E. Tanggal sesi masa depan / format salah ditolak.
 *   F. Akhir pekan tidak memblokir Buka Kas (aturan akhir pekan hanya
 *      berlaku untuk penjadwalan jatuh tempo, bukan operasional loket).
 *
 * Seluruh waktu diuji pada zona operasional `Asia/Jakarta` (tanpa DST).
 */
class AturanLintasHariE2ETest extends TestCase
{
    use DatabaseTransactions;

    /* =====================================================================
     | HELPER
     |===================================================================== */

    private function teller(string $suffix = 'utama'): User
    {
        return User::firstOrCreate(
            ['email' => "teller.lintashari.{$suffix}@test.local"],
            [
                'name' => 'Teller Lintas Hari '.ucfirst($suffix),
                'password' => Hash::make('password'),
                'role' => 'teller',
                'is_active' => true,
            ]
        );
    }

    private function sesi(User $user, string $tanggal, string $status = KasHarian::STATUS_OPEN, float $modalAwal = 0): KasHarian
    {
        return KasHarian::create([
            'user_id' => $user->getKey(),
            'tanggal' => $tanggal,
            'modal_awal' => $modalAwal,
            'tambah_modal' => [],
            'waktu_opening' => '08:00:00',
            'status_opname' => $status,
        ]);
    }

    /** Bersihkan sesi milik user uji agar skenario deterministik. */
    private function hapusSemuaSesi(User $user): void
    {
        KasHarian::where('user_id', $user->getKey())->delete();
    }

    /* =====================================================================
     | A. BUKA KAS DIBLOKIR OLEH SESI LINTAS HARI
     |===================================================================== */

    public function test_e2e_opening_diblokir_bila_sesi_kemarin_masih_open(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 09:00:00', 'Asia/Jakarta'));

        $teller = $this->teller('blokir');
        $this->hapusSemuaSesi($teller);
        $this->sesi($teller, '2026-10-18', KasHarian::STATUS_OPEN, 750000);

        $response = $this->actingAs($teller)->post(route('teller.kas.opening.store'), [
            'modal_awal' => 1000000,
        ]);

        $response->assertRedirect(route('teller.kas.closing', ['tanggal' => '2026-10-18']));
        $response->assertSessionHas('error');

        $this->assertStringContainsString(
            'Kas tanggal 2026-10-18 belum ditutup',
            (string) session('error')
        );

        $this->assertDatabaseMissing('kas_harian', [
            'user_id' => $teller->getKey(),
            'tanggal' => '2026-10-19',
        ]);
    }

    public function test_e2e_opening_diblokir_bila_sesi_kemarin_pending_approval(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 09:00:00', 'Asia/Jakarta'));

        $teller = $this->teller('pending');
        $this->hapusSemuaSesi($teller);
        $this->sesi($teller, '2026-10-18', KasHarian::STATUS_PENDING_APPROVAL, 750000);

        $response = $this->actingAs($teller)->post(route('teller.kas.opening.store'), [
            'modal_awal' => 1000000,
        ]);

        $response->assertRedirect(route('teller.kas.closing', ['tanggal' => '2026-10-18']));
        $response->assertSessionHas('error');

        $this->assertDatabaseMissing('kas_harian', [
            'user_id' => $teller->getKey(),
            'tanggal' => '2026-10-19',
        ]);
    }

    public function test_e2e_opening_diizinkan_bila_sesi_kemarin_sudah_closed(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 09:00:00', 'Asia/Jakarta'));

        $teller = $this->teller('closed');
        $this->hapusSemuaSesi($teller);
        $this->sesi($teller, '2026-10-18', KasHarian::STATUS_CLOSED, 750000);

        $response = $this->actingAs($teller)->post(route('teller.kas.opening.store'), [
            'modal_awal' => 1000000,
        ]);

        $response->assertRedirect(route('teller.kas.opening'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('kas_harian', [
            'user_id' => $teller->getKey(),
            'tanggal' => '2026-10-19',
            'status_opname' => KasHarian::STATUS_OPEN,
        ]);
    }

    public function test_e2e_halaman_opening_menampilkan_peringatan_sesi_tertunda(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 09:00:00', 'Asia/Jakarta'));

        $teller = $this->teller('peringatan');
        $this->hapusSemuaSesi($teller);
        $this->sesi($teller, '2026-10-18', KasHarian::STATUS_OPEN, 750000);

        $response = $this->actingAs($teller)->get(route('teller.kas.opening'));

        $response->assertOk();
        $response->assertSee('Sesi kas sebelumnya belum ditutup');
        $response->assertSee('2026-10-18');
    }

    /* =====================================================================
     | B. TRANSAKSI MELEWATI TENGAH MALAM
     |===================================================================== */

    public function test_e2e_transaksi_melewati_tengah_malam_tetap_milik_tanggal_sebelumnya(): void
    {
        // 00:15 WIB tanggal 19 Okt: shift malam masih terikat sesi 18 Okt.
        $this->travelTo(Carbon::parse('2026-10-19 00:15:00', 'Asia/Jakarta'));

        $teller = $this->teller('tengahmalam');
        $this->hapusSemuaSesi($teller);
        $this->sesi($teller, '2026-10-18', KasHarian::STATUS_OPEN, 500000);

        $response = $this->actingAs($teller)->post(route('teller.kas.opening.store'), [
            'modal_awal' => 250000,
        ]);

        // Tanggal bisnis sudah 19 Okt → sesi 18 Okt wajib ditutup lebih dulu.
        $response->assertRedirect(route('teller.kas.closing', ['tanggal' => '2026-10-18']));
        $response->assertSessionHas('error');

        $this->assertDatabaseMissing('kas_harian', [
            'user_id' => $teller->getKey(),
            'tanggal' => '2026-10-19',
        ]);
    }

    public function test_e2e_closing_sesi_kemarin_dapat_diakses_setelah_tengah_malam(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 00:15:00', 'Asia/Jakarta'));

        $teller = $this->teller('closingmalam');
        $this->hapusSemuaSesi($teller);
        $this->sesi($teller, '2026-10-18', KasHarian::STATUS_OPEN, 500000);

        $response = $this->actingAs($teller)->get(
            route('teller.kas.closing', ['tanggal' => '2026-10-18'])
        );

        $response->assertOk();
        $response->assertSee('SESI LINTAS HARI');
        $response->assertSee('formOpname', false);
    }

    /* =====================================================================
     | C. CLOSING LINTAS HARI
     |===================================================================== */

    public function test_e2e_halaman_closing_menampilkan_sesi_lintas_hari_tanpa_sesi_hari_ini(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 09:00:00', 'Asia/Jakarta'));

        $teller = $this->teller('tampil');
        $this->hapusSemuaSesi($teller);
        $this->sesi($teller, '2026-10-14', KasHarian::STATUS_OPEN, 400000);

        $response = $this->actingAs($teller)->get(
            route('teller.kas.closing', ['tanggal' => '2026-10-14'])
        );

        $response->assertOk();
        $response->assertSee('SESI LINTAS HARI');
        // Gate `tutupKas` harus lolos walau teller tidak punya sesi hari ini.
        $response->assertSee('formOpname', false);
        $response->assertDontSee('Belum ada Opening pada tanggal 2026-10-14');
    }

    public function test_e2e_store_opname_untuk_sesi_lintas_hari(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 09:00:00', 'Asia/Jakarta'));

        $teller = $this->teller('opname');
        $this->hapusSemuaSesi($teller);
        $kas = $this->sesi($teller, '2026-10-14', KasHarian::STATUS_OPEN, 500000);

        $response = $this->actingAs($teller)->post(route('teller.kas.closing.store'), [
            'tanggal' => '2026-10-14',
            'rincian_pecahan' => ['100000' => 5],
            'catatan' => 'Closing shift malam tanggal 14',
        ]);

        $response->assertRedirect(route('teller.kas.closing', ['tanggal' => '2026-10-14']));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('teller_opnames', [
            'kas_harian_id' => $kas->id,
            'user_id' => $teller->getKey(),
            'tanggal' => '2026-10-14',
            'total_fisik' => 500000,
            'status' => TellerOpname::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('kas_harian', [
            'id' => $kas->id,
            'status_opname' => KasHarian::STATUS_PENDING_APPROVAL,
        ]);
    }

    public function test_e2e_batal_opname_lintas_hari_kembali_ke_tanggal_sesi(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 09:00:00', 'Asia/Jakarta'));

        $teller = $this->teller('batal');
        $this->hapusSemuaSesi($teller);
        $kas = $this->sesi($teller, '2026-10-14', KasHarian::STATUS_PENDING_APPROVAL, 500000);

        $opname = TellerOpname::create([
            'kas_harian_id' => $kas->id,
            'user_id' => $teller->getKey(),
            'tanggal' => '2026-10-14',
            'saldo_sistem' => 500000,
            'total_fisik' => 500000,
            'selisih' => 0,
            'rincian_pecahan' => [],
            'status' => TellerOpname::STATUS_PENDING,
            'status_selisih' => TellerOpname::SELISIH_BALANCED,
        ]);

        $response = $this->actingAs($teller)->post(
            route('teller.kas.closing.batal', $opname->id)
        );

        $response->assertRedirect(route('teller.kas.closing', ['tanggal' => '2026-10-14']));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('kas_harian', [
            'id' => $kas->id,
            'status_opname' => KasHarian::STATUS_OPEN,
        ]);
    }

    /* =====================================================================
     | D. VALIDASI TANGGAL SESI
     |===================================================================== */

    public function test_e2e_store_opname_menolak_tanggal_masa_depan(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 09:00:00', 'Asia/Jakarta'));

        $teller = $this->teller('masadepan');
        $this->hapusSemuaSesi($teller);
        $this->sesi($teller, '2026-10-19', KasHarian::STATUS_OPEN, 500000);

        $response = $this->actingAs($teller)->post(route('teller.kas.closing.store'), [
            'tanggal' => '2026-12-31',
            'rincian_pecahan' => ['100000' => 5],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString(
            'tidak sah atau melewati hari ini',
            (string) session('error')
        );

        $this->assertDatabaseCount('teller_opnames', 0);
    }

    public function test_e2e_store_opname_menolak_format_tanggal_salah(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 09:00:00', 'Asia/Jakarta'));

        $teller = $this->teller('format');
        $this->hapusSemuaSesi($teller);
        $this->sesi($teller, '2026-10-19', KasHarian::STATUS_OPEN, 500000);

        $response = $this->actingAs($teller)->post(route('teller.kas.closing.store'), [
            'tanggal' => '18-10-2026',
            'rincian_pecahan' => ['100000' => 5],
        ]);

        $response->assertSessionHasErrors('tanggal');
        $this->assertDatabaseCount('teller_opnames', 0);
    }

    public function test_e2e_halaman_closing_menormalkan_tanggal_masa_depan_ke_hari_ini(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 09:00:00', 'Asia/Jakarta'));

        $teller = $this->teller('normalisasi');
        $this->hapusSemuaSesi($teller);

        $response = $this->actingAs($teller)->get(
            route('teller.kas.closing', ['tanggal' => '2026-12-31'])
        );

        $response->assertOk();
        $response->assertSee('Belum ada Opening hari ini');
        $response->assertDontSee('SESI LINTAS HARI');
    }

    /* =====================================================================
     | E. AKHIR PEKAN
     |===================================================================== */

    public function test_e2e_akhir_pekan_tidak_memblokir_buka_kas(): void
    {
        // Sabtu 17 Okt 2026 — loket tetap beroperasi; aturan akhir pekan
        // hanya menggeser tanggal jatuh tempo, bukan melarang Buka Kas.
        $this->travelTo(Carbon::parse('2026-10-17 09:00:00', 'Asia/Jakarta'));

        $teller = $this->teller('akhirpekan');
        $this->hapusSemuaSesi($teller);
        $this->sesi($teller, '2026-10-16', KasHarian::STATUS_CLOSED, 300000);

        $response = $this->actingAs($teller)->post(route('teller.kas.opening.store'), [
            'modal_awal' => 400000,
        ]);

        $response->assertRedirect(route('teller.kas.opening'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('kas_harian', [
            'user_id' => $teller->getKey(),
            'tanggal' => '2026-10-17',
        ]);
    }
}
