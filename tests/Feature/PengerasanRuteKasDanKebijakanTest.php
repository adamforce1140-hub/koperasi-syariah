<?php

namespace Tests\Feature;

use App\Models\KasHarian;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * TAHAP 7 — PENGERASAN DUA PINTU MASUK YANG SEBELUMNYA TERBUKA.
 *
 * 1. API `transaksi/setor` & `transaksi/tarik` hanya dijaga `auth:sanctum`,
 *    padahal keduanya menggerakkan uang tunai. Jalur web memakai guard
 *    `kas.terbuka`; jalur API harus setara. Peran non-teller (termasuk
 *    anggota yang bertransaksi atas rekeningnya sendiri) tetap diloloskan
 *    karena mereka tidak memegang laci kas.
 *
 * 2. Alias `/admin/settings` menyimpan ke controller yang SAMA dengan
 *    `/pengurus/kebijakan`, tetapi dijaga `izin:pengaturan.admin`
 *    (super_admin, admin, pengurus) sedangkan jalur resminya hanya
 *    `izin:kebijakan.koperasi` (pengurus). Akibatnya admin/super_admin dapat
 *    mengubah parameter bisnis lewat pintu samping.
 */
class PengerasanRuteKasDanKebijakanTest extends TestCase
{
    use DatabaseTransactions;

    private function pengguna(string $role, string $email): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Uji '.strtoupper($role),
                'password' => Hash::make('secret'),
                'role' => $role,
                'is_active' => true,
            ],
        );
    }

    /* ═══════════════════════════════════════════════════════════════════
     * 1. GUARD SESI KAS PADA API SETOR / TARIK
     * ═══════════════════════════════════════════════════════════════════ */

    public function test_setor_api_ditolak_bila_teller_belum_membuka_kas(): void
    {
        $teller = $this->pengguna('teller', 'tahap7.teller.setor@koperasi.test');

        KasHarian::query()
            ->where('user_id', $teller->id)
            ->whereDate('tanggal', now()->toDateString())
            ->delete();

        Sanctum::actingAs($teller);

        $respons = $this->postJson('/api/transaksi/setor', [
            'rekening_id' => 1,
            'jumlah' => 50000,
        ]);

        $respons->assertForbidden();
    }

    public function test_tarik_api_ditolak_bila_teller_belum_membuka_kas(): void
    {
        $teller = $this->pengguna('teller', 'tahap7.teller.tarik@koperasi.test');

        KasHarian::query()
            ->where('user_id', $teller->id)
            ->whereDate('tanggal', now()->toDateString())
            ->delete();

        Sanctum::actingAs($teller);

        $respons = $this->postJson('/api/transaksi/tarik', [
            'rekening_id' => 1,
            'jumlah' => 50000,
        ]);

        $respons->assertForbidden();
    }

    public function test_setor_api_tetap_diloloskan_guard_kas_untuk_peran_non_teller(): void
    {
        $anggota = $this->pengguna('anggota', 'tahap7.anggota.setor@koperasi.test');

        // Tanpa sesi kas. Guard hanya menegakkan aturan bagi pemegang laci,
        // sehingga permintaan berlanjut ke validasi controller (422 karena
        // rekening tidak ada) — BUKAN 403 dari guard kas.
        Sanctum::actingAs($anggota);

        $respons = $this->postJson('/api/transaksi/setor', [
            'rekening_id' => 999999,
            'jumlah' => 50000,
        ]);

        $this->assertNotSame(
            403,
            $respons->status(),
            'Anggota tanpa laci kas tidak boleh diblokir guard `kas.terbuka`.',
        );
    }

    /* ═══════════════════════════════════════════════════════════════════
     * 2. ALIAS /admin/settings TIDAK BOLEH MELEBIHI WEWENANG PENGURUS
     * ═══════════════════════════════════════════════════════════════════ */

    public function test_admin_tidak_dapat_mengubah_kebijakan_lewat_alias_settings(): void
    {
        $admin = $this->pengguna('admin', 'tahap7.admin@koperasi.test');

        $this->actingAs($admin)
            ->put('/admin/settings', [])
            ->assertForbidden();
    }

    public function test_super_admin_dapat_mengubah_kebijakan_lewat_alias_settings(): void
    {
        $superAdmin = $this->pengguna('super_admin', 'tahap7.superadmin@koperasi.test');

        // Super Admin adalah puncak administratif: `kebijakan.koperasi` memuat
        // dirinya, sehingga alias ini TIDAK boleh lagi menolaknya dengan 403.
        // Muatan kosong → validasi controller menolak dan mengalihkan kembali
        // (302). Yang diuji di sini adalah gerbang izin, bukan validasi —
        // sama seperti perlakuan terhadap Pengurus di test berikutnya.
        $respons = $this->actingAs($superAdmin)->put('/admin/settings', []);

        $this->assertNotSame(
            403,
            $respons->status(),
            'Super Admin memegang `kebijakan.koperasi` dan harus lolos gerbang izin.'
        );
    }

    public function test_pengurus_tetap_dapat_mengubah_kebijakan_lewat_alias_settings(): void
    {
        $pengurus = $this->pengguna('pengurus', 'tahap7.pengurus@koperasi.test');

        // Muatan kosong → validasi controller menolak dan mengalihkan kembali
        // (302). Yang diuji di sini adalah gerbang izin, bukan validasi.
        $respons = $this->actingAs($pengurus)->put('/admin/settings', []);

        $this->assertNotSame(
            403,
            $respons->status(),
            'Pengurus adalah pemegang `kebijakan.koperasi` dan harus lolos gerbang izin.',
        );
    }

    public function test_admin_tetap_dapat_membaca_halaman_settings(): void
    {
        $admin = $this->pengguna('admin', 'tahap7.admin.baca@koperasi.test');

        $this->actingAs($admin)
            ->get('/admin/settings')
            ->assertOk();
    }
}
