<?php

namespace Tests\Feature;

use App\Models\DepositoProduct;
use App\Models\KasHarian;
use App\Models\Pembiayaan;
use App\Models\Rekening;
use App\Models\User;
use App\Support\Izin;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
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

    /* ───────────── K-3 — rute API memakai izin bernama ───────────── */

    /**
     * Tidak boleh ada lagi gerbang peran literal (`role:`) pada routes/api.php.
     *
     * Peran literal mengikat definisi rute ke nama peran, sehingga peran baru
     * atau perubahan wewenang menuntut penyuntingan rute. Seluruh gerbang API
     * wajib menyebut IZIN (`izin:`), dan pemetaannya hidup di config/izin.php.
     */
    public function test_rute_api_tidak_lagi_memakai_gerbang_peran_literal(): void
    {
        $tersisa = [];

        foreach (Route::getRoutes() as $rute) {
            if (! str_starts_with($rute->uri(), 'api/')) {
                continue;
            }

            foreach ($rute->gatherMiddleware() as $mw) {
                if (is_string($mw) && str_starts_with($mw, 'role:')) {
                    $tersisa[] = $rute->uri().' → '.$mw;
                }
            }
        }

        $this->assertSame(
            [],
            $tersisa,
            "Rute API masih memakai peran literal:\n".implode("\n", $tersisa)
        );
    }

    /**
     * Izin baca operasional memindahkan SELURUH peran yang sebelumnya dilayani
     * `role:admin,manajer,pengurus,teller,ao,dps` — ditambah super_admin sebagai
     * puncak administratif. Tidak ada peran yang dipersempit (pemindahan persis).
     */
    public function test_izin_baca_operasional_api_memuat_seluruh_peran_lama(): void
    {
        $this->assertPeranSama(
            ['admin', 'manajer', 'pengurus', 'teller', 'ao', 'dps', 'super_admin'],
            'api.baca.operasional'
        );
    }

    /**
     * Gerbang cetak bilyet sebelumnya `role:admin,ao,manajer,pengurus,teller`.
     * `ao` WAJIB tetap lolos meski bukan penerbit bilyet: pemilik deposito
     * berperan `ao` harus dapat mencetak bilyetnya sendiri (lihat
     * DepositoSyariahTest::test_bilyet_tidak_dapat_diakses_anggota_lain...).
     * Pembedaan "boleh mencetak milik sendiri" vs "milik siapa pun" tetap
     * ditegakkan di controller lewat kelompok `pencetak_bilyet`.
     */
    public function test_izin_cetak_bilyet_tidak_mempersempit_gerbang_lama(): void
    {
        $this->assertPeranSama(
            ['admin', 'ao', 'manajer', 'pengurus', 'teller', 'super_admin'],
            'deposito.cetak_bilyet'
        );
    }

    /**
     * Pencairan dini mengeluarkan dana koperasi: tetap HANYA pemegang laci.
     * `super_admin` sengaja tidak diikutkan karena kelompok `pencairan_dini`
     * di role_hierarki pun mengecualikannya (pewaris_puncak_kecuali).
     */
    public function test_izin_pencairan_dini_hanya_untuk_pemegang_laci(): void
    {
        $this->assertSame(['teller'], Izin::peran('deposito.pencairan_dini'));
    }

    /** Migrasi gerbang tidak boleh mempersempit akses BACA yang sudah berjalan. */
    public function test_peran_pembaca_api_tetap_diloloskan_setelah_migrasi(): void
    {
        foreach (['admin', 'manajer', 'pengurus', 'teller', 'ao', 'dps'] as $peran) {
            $this->actingAs($this->pengguna($peran))
                ->getJson('/api/deposito/products')
                ->assertOk();
        }
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

    /**
     * Bandingkan daftar peran sebuah izin tanpa memperhatikan urutan.
     *
     * @param  list<string>  $diharapkan
     */
    private function assertPeranSama(array $diharapkan, string $namaIzin): void
    {
        $aktual = Izin::peran($namaIzin);

        sort($diharapkan);
        sort($aktual);

        $this->assertSame(
            $diharapkan,
            $aktual,
            "Izin '{$namaIzin}' tidak memindahkan daftar peran secara persis."
        );
    }

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
