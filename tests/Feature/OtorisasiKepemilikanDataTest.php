<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Deposito;
use App\Models\DepositoProduct;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * LANGKAH 4 — BATCH 1: OTORISASI KEPEMILIKAN DATA.
 *
 * Tiga pintu yang sebelumnya hanya mengandalkan ID dari klien dikunci di sini:
 *
 *  1. `POST /api/transaksi/setor` & `/tarik` — IDOR (OWASP A01). Anggota wajib
 *     hanya dapat bertransaksi atas rekening MILIKNYA; peran wali data
 *     (teller/pengurus/admin/manajer) tetap boleh melayani rekening siapa pun.
 *  2. `GET nasabah/deposito/{deposito}/bagi-hasil` — bilyet deposito milik
 *     anggota lain tidak boleh dibuka (kebocoran pokok, nisbah, dan riwayat
 *     bagi hasil).
 *  3. `PUT nasabah/profil` — hanya kolom kontak/alamat yang boleh berubah.
 *     `role`, `user_id`, `status_anggota`, `nik`, dan `no_anggota` TIDAK boleh
 *     dapat ditimpa lewat payload (mass assignment / eskalasi hak akses).
 */
class OtorisasiKepemilikanDataTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('rekening') || ! Schema::hasTable('depositos')) {
            $this->markTestSkipped('Tabel inti belum tersedia.');
        }
    }

    /* =====================================================================
     | Helper
     ===================================================================== */

    private function pengguna(string $kunci, string $role = 'anggota'): User
    {
        return User::firstOrCreate(
            ['email' => "otorisasi.{$kunci}@test.local"],
            [
                'name' => 'Otorisasi '.ucfirst($kunci),
                'password' => Hash::make('password'),
                'role' => $role,
                'is_active' => true,
            ]
        );
    }

    private function anggota(?User $pengguna = null, array $override = []): Anggota
    {
        $suffix = strtoupper(substr(uniqid(), -6));

        return Anggota::create(array_merge([
            'user_id' => $pengguna?->id,
            'no_anggota' => 'OT-'.$suffix,
            'nama_lengkap' => 'Anggota Otorisasi '.$suffix,
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji Otorisasi No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_ACTIVE,
            'tanggal_gabung' => now()->toDateString(),
        ], $override));
    }

    /** Akun CoA (FK wajib `rekening.akun_id`). */
    private function akunId(): int
    {
        $id = DB::table('akun')->value('id');

        if ($id) {
            return (int) $id;
        }

        return (int) DB::table('akun')->insertGetId([
            'kode_akun' => '1-1001',
            'nama_akun' => 'Kas Uji Otorisasi',
            'tipe_akun' => 'Aset',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function rekening(Anggota $anggota, float $saldo = 0): Rekening
    {
        return Rekening::create([
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunId(),
            'no_rekening' => 'REK-OT-'.strtoupper(substr(uniqid(), -8)),
            'jenis_rekening' => 'Simpanan Sukarela',
            'status_rekening' => 'Aktif',
            'saldo' => $saldo,
            'tanggal_buka' => now()->toDateString(),
        ]);
    }

    private function produkDeposito(): DepositoProduct
    {
        return DepositoProduct::create([
            'name' => 'Deposito Uji Otorisasi '.strtoupper(substr(uniqid(), -6)),
            'tipe_produk' => DepositoProduct::TIPE_DEPOSITO,
            'has_nisbah' => true,
            'coa_kode' => DepositoProduct::COA_BY_TIPE[DepositoProduct::TIPE_DEPOSITO],
            'tenor_months' => 3,
            'nisbah_anggota' => 60,
            'nisbah_koperasi' => 40,
            'min_amount' => 100000,
            'is_active' => true,
        ]);
    }

    private function deposito(Anggota $anggota, ?Rekening $sumber = null): Deposito
    {
        return Deposito::create([
            'deposito_number' => 'DEP-OT-'.strtoupper(substr(uniqid(), -6)),
            'member_id' => $anggota->id,
            'product_id' => $this->produkDeposito()->id,
            'source_saving_account_id' => ($sumber ?? $this->rekening($anggota))->id,
            'principal_amount' => 1000000,
            'nisbah_anggota' => 60,
            'nisbah_koperasi' => 40,
            'start_date' => now()->subMonth()->toDateString(),
            'maturity_date' => now()->addMonths(2)->toDateString(),
            'is_aro' => false,
            'status' => Deposito::STATUS_ACTIVE,
        ]);
    }

    /* =====================================================================
     | 1. IDOR TRANSAKSI MOBILE (setor / tarik)
     ===================================================================== */

    public function test_anggota_tidak_dapat_setor_ke_rekening_anggota_lain(): void
    {
        $penyusup = $this->pengguna('penyusup.setor');
        $this->anggota($penyusup);

        $korban = $this->anggota($this->pengguna('korban.setor'));
        $rekeningKorban = $this->rekening($korban);

        Sanctum::actingAs($penyusup);

        $respons = $this->postJson('/api/transaksi/setor', [
            'rekening_id' => $rekeningKorban->id,
            'jumlah' => 50000,
        ]);

        $respons->assertForbidden();
        $respons->assertJson(['success' => false]);

        $this->assertSame(
            0,
            Transaksi::where('rekening_id', $rekeningKorban->id)->count(),
            'Tidak boleh ada transaksi yang tercatat pada rekening korban.'
        );
    }

    public function test_anggota_tidak_dapat_tarik_dari_rekening_anggota_lain(): void
    {
        $penyusup = $this->pengguna('penyusup.tarik');
        $this->anggota($penyusup);

        $korban = $this->anggota($this->pengguna('korban.tarik'));
        $rekeningKorban = $this->rekening($korban, 500000);

        Sanctum::actingAs($penyusup);

        $respons = $this->postJson('/api/transaksi/tarik', [
            'rekening_id' => $rekeningKorban->id,
            'jumlah' => 50000,
        ]);

        $respons->assertForbidden();
        $respons->assertJson(['success' => false]);

        $this->assertSame(
            0,
            Transaksi::where('rekening_id', $rekeningKorban->id)->count(),
            'Penarikan atas rekening orang lain tidak boleh diproses.'
        );
    }

    public function test_anggota_dapat_setor_ke_rekening_miliknya_sendiri(): void
    {
        $pemilik = $this->pengguna('pemilik.setor');
        $anggota = $this->anggota($pemilik);
        $rekening = $this->rekening($anggota);

        Sanctum::actingAs($pemilik);

        $this->postJson('/api/transaksi/setor', [
            'rekening_id' => $rekening->id,
            'jumlah' => 50000,
        ])->assertOk();

        $this->assertSame(
            1,
            Transaksi::where('rekening_id', $rekening->id)->count(),
            'Transaksi atas rekening sendiri harus tetap diproses.'
        );
    }

    public function test_teller_tetap_dapat_melayani_rekening_anggota_lain(): void
    {
        $teller = $this->pengguna('teller', 'teller');
        $this->bukaSesiKas($teller);

        $anggota = $this->anggota($this->pengguna('nasabah.teller'));
        $rekening = $this->rekening($anggota);

        Sanctum::actingAs($teller);

        $respons = $this->postJson('/api/transaksi/setor', [
            'rekening_id' => $rekening->id,
            'jumlah' => 50000,
        ]);

        $this->assertNotSame(403, $respons->status(),
            'Peran wali data rekening tidak boleh diblokir oleh guard kepemilikan.');
        $respons->assertOk();

        $this->assertSame(
            1,
            Transaksi::where('rekening_id', $rekening->id)->count(),
            'Setoran teller atas rekening anggota harus tercatat.'
        );
    }

    /* =====================================================================
     | 2. OTORISASI BILYET DEPOSITO
     ===================================================================== */

    public function test_anggota_tidak_dapat_melihat_bagi_hasil_bilyet_anggota_lain(): void
    {
        $pemilik = $this->pengguna('pemilik.deposito');
        $deposito = $this->deposito($this->anggota($pemilik));

        $penyusup = $this->pengguna('penyusup.deposito');
        $this->anggota($penyusup);

        $this->actingAs($penyusup)
            ->get(route('nasabah.deposito.bagi-hasil', $deposito))
            ->assertForbidden();
    }

    public function test_anggota_dapat_melihat_bagi_hasil_bilyet_miliknya(): void
    {
        $pemilik = $this->pengguna('pemilik.bilyet');
        $deposito = $this->deposito($this->anggota($pemilik));

        $this->actingAs($pemilik)
            ->get(route('nasabah.deposito.bagi-hasil', $deposito))
            ->assertOk()
            ->assertSee($deposito->deposito_number);
    }

    /* =====================================================================
     | 3. PROFIL NASABAH — ALLOWLIST KOLOM
     ===================================================================== */

    public function test_profil_tidak_dapat_menimpa_kolom_di_luar_allowlist(): void
    {
        $pengguna = $this->pengguna('profil.massal');
        $nikAsli = '3276010101990001';
        $noAnggotaAsli = 'OT-PROFIL-ASLI';

        $anggota = $this->anggota($pengguna, [
            'nik' => $nikAsli,
            'no_anggota' => $noAnggotaAsli,
        ]);

        $this->actingAs($pengguna)->put(route('nasabah.profil.update'), [
            'no_hp' => '081234567890',
            'role' => 'super_admin',
            'user_id' => 999999,
            'status_anggota' => 'Nonaktif',
            'nik' => '0000000000000000',
            'no_anggota' => 'OT-DIBUAT-PENYUSUP',
        ])->assertRedirect(route('nasabah.profil.index'));

        $anggota->refresh();
        $pengguna->refresh();

        $this->assertSame('081234567890', $anggota->no_hp, 'Kolom yang diizinkan tetap diperbarui.');
        $this->assertSame('anggota', $pengguna->role, 'Peran akun tidak boleh berubah lewat profil.');
        $this->assertSame($pengguna->id, (int) $anggota->user_id, 'Kepemilikan akun tidak boleh dialihkan.');
        $this->assertSame(Anggota::STATUS_ACTIVE, $anggota->status_anggota, 'Status anggota tidak boleh diubah.');
        $this->assertSame($nikAsli, $anggota->nik, 'NIK tidak boleh ditimpa dari formulir profil.');
        $this->assertSame($noAnggotaAsli, $anggota->no_anggota, 'Nomor anggota tidak boleh diubah.');
    }
}
