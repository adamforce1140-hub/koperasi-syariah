<?php

namespace Tests\Feature;

use App\Models\ApprovalTransaksi;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * LANGKAH 4 — BATCH 2: CAKUPAN ANTREAN APPROVAL TRANSAKSI.
 *
 * Halaman daftar (`index`) sudah membatasi kueri pada antrean yang menjadi
 * wewenang peran pengguna: manajer hanya melihat antrean PENDING_MANAJER dan
 * keputusan yang ia buat sendiri, sedangkan pengurus melihat seluruh antrean.
 * Batas itu WAJIB berlaku juga pada pintu lain yang menampilkan baris yang
 * sama — detail (`show`) dan API `approval/status` — karena tanpa itu cakupan
 * daftar hanya lapisan tampilan: siapa pun yang menebak ID dapat membaca
 * detail approval di luar antreannya.
 */
class CakupanAntreanApprovalTest extends TestCase
{
    use DatabaseTransactions;

    private function pengguna(string $peran, string $kunci): User
    {
        return User::firstOrCreate(
            ['email' => "approval.{$kunci}@test.local"],
            [
                'name' => 'Uji '.ucfirst($kunci),
                'password' => Hash::make('password'),
                'role' => $peran,
                'is_active' => true,
            ],
        );
    }

    private function approval(string $kode, string $status, User $pengaju, ?User $approver = null): ApprovalTransaksi
    {
        return ApprovalTransaksi::create([
            'kode_approval' => $kode,
            'tipe_referensi' => 'transaksi',
            'referensi_id' => random_int(100000, 999999),
            'jenis_transaksi' => 'Setor Tunai',
            'nominal' => 75000000,
            'jenis_pembayaran' => 'tunai',
            'status' => $status,
            'diajukan_oleh' => $pengaju->id,
            'role_pengaju' => $pengaju->role,
            'disetujui_oleh' => $approver?->id,
            'role_approver' => $approver?->role,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════
     * 1. DAFTAR (index)
     * ═══════════════════════════════════════════════════════════════════ */

    public function test_daftar_approval_manajer_tidak_memuat_antrean_pengurus(): void
    {
        $teller = $this->pengguna('teller', 'pengaju');
        $manajer = $this->pengguna('manajer', 'manajer');
        $pengurus = $this->pengguna('pengurus', 'pengurus');

        $antreanManajer = $this->approval('APR-UJI-MJ-0001', 'PENDING_MANAJER', $teller);
        $antreanPengurus = $this->approval('APR-UJI-PG-0001', 'PENDING_PENGURUS', $manajer);

        $this->actingAs($manajer)
            ->get(route('pengelola.approval.index'))
            ->assertOk()
            ->assertSee($antreanManajer->kode_approval)
            ->assertDontSee($antreanPengurus->kode_approval);

        $this->actingAs($pengurus)
            ->get(route('pengelola.approval.index'))
            ->assertOk()
            ->assertSee($antreanManajer->kode_approval)
            ->assertSee($antreanPengurus->kode_approval);
    }

    /* ═══════════════════════════════════════════════════════════════════
     * 2. DETAIL (show) — cakupan yang sama harus berlaku
     * ═══════════════════════════════════════════════════════════════════ */

    public function test_manajer_dapat_membuka_detail_antrean_manajer(): void
    {
        $teller = $this->pengguna('teller', 'pengaju');
        $manajer = $this->pengguna('manajer', 'manajer');

        $antrean = $this->approval('APR-UJI-MJ-0002', 'PENDING_MANAJER', $teller);

        $this->actingAs($manajer)
            ->get(route('pengelola.approval.show', $antrean))
            ->assertOk()
            ->assertSee($antrean->kode_approval);
    }

    public function test_pengurus_dapat_membuka_detail_antrean_mana_pun(): void
    {
        $manajer = $this->pengguna('manajer', 'manajer');
        $pengurus = $this->pengguna('pengurus', 'pengurus');

        $antrean = $this->approval('APR-UJI-PG-0002', 'PENDING_PENGURUS', $manajer);

        $this->actingAs($pengurus)
            ->get(route('pengelola.approval.show', $antrean))
            ->assertOk()
            ->assertSee($antrean->kode_approval);
    }

    public function test_manajer_tidak_dapat_membuka_detail_antrean_pengurus(): void
    {
        $manajer = $this->pengguna('manajer', 'manajer');
        $manajerLain = $this->pengguna('manajer', 'manajer-lain');

        // Antrean milik rantai keputusan pengurus — bukan wewenang manajer.
        $antrean = $this->approval('APR-UJI-PG-0003', 'PENDING_PENGURUS', $manajer);

        $this->actingAs($manajerLain)
            ->get(route('pengelola.approval.show', $antrean))
            ->assertForbidden();
    }

    public function test_manajer_tidak_dapat_membuka_detail_keputusan_manajer_lain(): void
    {
        $teller = $this->pengguna('teller', 'pengaju');
        $manajer = $this->pengguna('manajer', 'manajer');
        $manajerLain = $this->pengguna('manajer', 'manajer-lain');

        // Sudah diputuskan manajer lain: di luar cakupan manajer kedua.
        $antrean = $this->approval('APR-UJI-MJ-0003', 'APPROVED', $teller, $manajer);

        $this->actingAs($manajer)
            ->get(route('pengelola.approval.show', $antrean))
            ->assertOk();

        $this->actingAs($manajerLain)
            ->get(route('pengelola.approval.show', $antrean))
            ->assertForbidden();
    }

    /* ═══════════════════════════════════════════════════════════════════
     * 3. API approval/status — auth:sanctum + kanal khusus personel (T-2)
     * ═══════════════════════════════════════════════════════════════════ */

    public function test_api_status_ditolak_untuk_peran_pemakai_jasa(): void
    {
        $teller = $this->pengguna('teller', 'pengaju');
        $manajer = $this->pengguna('manajer', 'manajer');
        $anggota = $this->pengguna('anggota', 'anggota');

        $this->approval('APR-UJI-MJ-0004', 'PENDING_MANAJER', $teller);
        $this->approval('APR-UJI-PG-0004', 'PENDING_PENGURUS', $manajer);

        // T-2: cabang `diajukan_oleh` membuat anggota tetap dapat memindai ID
        // approval (ada/tidak ada membocorkan keberadaan antrean pihak lain),
        // sementara peran anggota/nasabah tidak tunduk pada `frekuensi` dan
        // `akses_data` sehingga pembatas laju berbasis kebijakan ikut mati.
        // Kanal ini kini khusus personel.
        Sanctum::actingAs($anggota);

        $this->getJson('/api/approval/status')->assertForbidden();
    }

    public function test_api_status_ditolak_untuk_personel_tanpa_antrean_approval(): void
    {
        $teller = $this->pengguna('teller', 'pengaju');
        $this->approval('APR-UJI-MJ-0006', 'PENDING_MANAJER', $teller);

        foreach (['admin', 'dps', 'super_admin'] as $peran) {
            Sanctum::actingAs($this->pengguna($peran, "personel-{$peran}"));

            $this->getJson('/api/approval/status')->assertForbidden();
        }
    }

    public function test_api_status_manajer_hanya_memuat_antrean_manajer(): void
    {
        $teller = $this->pengguna('teller', 'pengaju');
        $manajer = $this->pengguna('manajer', 'manajer');

        $antreanManajer = $this->approval('APR-UJI-MJ-0005', 'PENDING_MANAJER', $teller);
        $antreanPengurus = $this->approval('APR-UJI-PG-0005', 'PENDING_PENGURUS', $manajer);

        Sanctum::actingAs($manajer);
        $response = $this->getJson('/api/approval/status')
            ->assertOk()
            ->assertJsonPath('data.pending_count', 1)
            ->assertJsonCount(1, 'data.approvals');

        $this->assertSame(
            $antreanManajer->kode_approval,
            $response->json('data.approvals.0.kode_approval'),
        );
        $this->assertNotSame(
            $antreanPengurus->kode_approval,
            $response->json('data.approvals.0.kode_approval'),
        );
    }

    /* ═══════════════════════════════════════════════════════════════════
     * 4. KEPUTUSAN (approve) — peran pemutus diturunkan dari status baris
     * ═══════════════════════════════════════════════════════════════════ */

    public function test_manajer_tidak_dapat_menyetujui_antrean_pengurus(): void
    {
        $manajer = $this->pengguna('manajer', 'manajer');
        $pengurus = $this->pengguna('pengurus', 'pengurus');

        $antrean = $this->approval('APR-UJI-PG-0006', 'PENDING_PENGURUS', $manajer);

        $this->actingAs($pengurus)->get(route('pengelola.approval.index'))->assertOk();

        $this->actingAs($manajer)
            ->post(route('pengelola.approval.approve', $antrean))
            ->assertRedirect(route('pengelola.approval.index'))
            ->assertSessionHas('error');

        $this->assertSame('PENDING_PENGURUS', $antrean->fresh()->status);
    }
}
