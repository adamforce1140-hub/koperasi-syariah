<?php

namespace Tests\Feature;

use App\Models\DepositoProduct;
use App\Models\KasHarian;
use App\Models\Pembiayaan;
use App\Models\Rekening;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * REGRESI K-1 & K-2 — pemisahan tugas pada routes/api.php.
 *
 * K-1: peran `admin` tidak boleh menulis/menghapus buku besar
 *      (rekening, transaksi, angsuran, jurnal, pembiayaan). `config/izin.php`
 *      hanya memberi admin wewenang administratif (#102 autodebit.konfirmasi,
 *      #118 pengaturan.admin), bukan mutasi keuangan.
 *
 * K-2: pembukaan deposito memindahkan dana anggota dari rekening sumber,
 *      sehingga merupakan transaksi loket: wajib sesi kas terbuka dan wajib
 *      peran pemegang loket (teller/AO). Peran administratif/penyetuju
 *      (admin, manajer, pengurus) dan pengawas (dps) ditolak.
 */
class PemisahanTugasApiKeuanganTest extends TestCase
{
    /* ─────────────────────────── K-1 ─────────────────────────── */

    public function test_admin_tidak_dapat_menghapus_pembiayaan(): void
    {
        $pembiayaan = Pembiayaan::query()->firstOrFail();

        $this->actingAs($this->pengguna('admin'))
            ->deleteJson("/api/pembiayaan/{$pembiayaan->id}")
            ->assertForbidden();

        $this->assertNotSoftDeleted($pembiayaan);
    }

    public function test_admin_tidak_dapat_membuat_data_pembiayaan(): void
    {
        $this->actingAs($this->pengguna('admin'))
            ->postJson('/api/pembiayaan', [])
            ->assertForbidden();
    }

    public function test_admin_tidak_dapat_mengubah_pembiayaan(): void
    {
        $pembiayaan = Pembiayaan::query()->firstOrFail();

        $this->actingAs($this->pengguna('admin'))
            ->putJson("/api/pembiayaan/{$pembiayaan->id}", [])
            ->assertForbidden();
    }

    public function test_admin_tidak_dapat_menulis_buku_besar_maupun_jurnal(): void
    {
        $admin = $this->pengguna('admin');

        $this->actingAs($admin)->postJson('/api/transaksi', [])->assertForbidden();
        $this->actingAs($admin)->putJson('/api/transaksi/1', [])->assertForbidden();
        $this->actingAs($admin)->deleteJson('/api/transaksi/1')->assertForbidden();
        $this->actingAs($admin)->postJson('/api/rekening', [])->assertForbidden();
        $this->actingAs($admin)->postJson('/api/angsuran', [])->assertForbidden();
        $this->actingAs($admin)->postJson('/api/jurnal', [])->assertForbidden();
    }

    public function test_admin_tetap_dapat_membaca_buku_besar(): void
    {
        $this->actingAs($this->pengguna('admin'))
            ->getJson('/api/rekening')
            ->assertOk();
    }

    public function test_teller_tetap_dapat_menulis_buku_besar(): void
    {
        // Gerbang otorisasi WAJIB meloloskan teller; penolakan berikutnya
        // (bila ada) berasal dari validasi, bukan 403.
        $this->actingAs($this->pengguna('teller'))
            ->postJson('/api/transaksi', [])
            ->assertStatus(422);
    }

    /* ─────────────────────────── K-2 ─────────────────────────── */

    public function test_peran_penyetuju_dan_administratif_tidak_dapat_membuka_deposito(): void
    {
        $data = $this->muatanDeposito();

        // Satu rekening sumber, satu pembukaan: bila peran pertama lolos,
        // rekeningnya sudah terdebit dan assertion saldo di bawah gagal.
        foreach (['admin', 'manajer', 'pengurus', 'dps', 'ao'] as $peran) {
            $this->actingAs($this->pengguna($peran))
                ->postJson('/api/deposito/store', $data['muatan'])
                ->assertForbidden();

            $this->assertSame(
                $data['saldo_awal'],
                (float) $data['rekening']->fresh()->saldo,
                "Saldo rekening anggota tidak boleh berubah oleh peran {$peran}."
            );
        }
    }

    public function test_teller_tanpa_sesi_kas_tidak_dapat_membuka_deposito(): void
    {
        $data = $this->muatanDeposito();
        $teller = $this->pengguna('teller');

        // Isolasi data uji: hapus sesi kas hari ini milik teller uji, termasuk
        // baris yang mungkin dibuat seed demo, agar kondisi "tanpa Buka Kas"
        // benar-benar tanpa baris `kas_harian` pada tanggal bisnis berjalan.
        KasHarian::query()
            ->where('user_id', $teller->getKey())
            ->whereDate('tanggal', now()->toDateString())
            ->delete();

        $this->actingAs($teller)
            ->postJson('/api/deposito/store', $data['muatan'])
            ->assertForbidden();

        $this->assertSame(
            $data['saldo_awal'],
            (float) $data['rekening']->fresh()->saldo,
            'Tanpa Buka Kas, dana anggota tidak boleh dipindahkan ke deposito.'
        );
    }

    public function test_teller_dengan_sesi_kas_terbuka_dapat_membuka_deposito(): void
    {
        $teller = $this->pengguna('teller');
        $this->bukaSesiKas($teller);
        $data = $this->muatanDeposito();

        $this->actingAs($teller)
            ->postJson('/api/deposito/store', $data['muatan'])
            ->assertStatus(201);

        $this->assertLessThan(
            $data['saldo_awal'],
            (float) $data['rekening']->fresh()->saldo,
            'Pembukaan deposito yang sah mendebit rekening sumber.'
        );
    }

    /* ────────────────────────── bantuan ────────────────────────── */

    private function pengguna(string $peran): User
    {
        return User::firstOrCreate(
            ['email' => "pemisahan.{$peran}@test.local"],
            [
                'name' => 'Uji Pemisahan '.ucfirst($peran),
                'password' => Hash::make('password'),
                'role' => $peran,
                'is_active' => true,
            ]
        );
    }

    /**
     * Susun muatan pembukaan deposito yang valid beserta rekening sumbernya.
     * Kunci berawalan `_` adalah data pembanding LOKAL dan tidak ikut dikirim.
     *
     * @return array{muatan: array<string, mixed>, rekening: Rekening, saldo_awal: float}
     */
    private function muatanDeposito(): array
    {
        $rekening = Rekening::query()
            ->where('jenis_rekening', 'Simpanan Sukarela')
            ->where('status_rekening', 'Aktif')
            ->where('saldo', '>=', 1000000)
            ->where('saldo', '<', 50000000)
            ->orderByDesc('saldo')
            ->firstOrFail();

        $produk = DepositoProduct::query()
            ->where('is_active', true)
            ->whereNotNull('tenor_months')
            ->where('nisbah_anggota', '>', 0)
            ->where('nisbah_koperasi', '>', 0)
            ->where('min_amount', '>', 0)
            ->where('min_amount', '<=', (float) $rekening->saldo)
            ->firstOrFail();

        return [
            'muatan' => [
                'member_id' => $rekening->anggota_id,
                'product_id' => $produk->id,
                'source_saving_account_id' => $rekening->id,
                'principal_amount' => (float) $produk->min_amount,
            ],
            'rekening' => $rekening,
            'saldo_awal' => (float) $rekening->saldo,
        ];
    }
}
