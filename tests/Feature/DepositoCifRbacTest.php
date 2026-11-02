<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\Deposito;
use App\Models\DepositoProduct;
use App\Models\Rekening;
use App\Models\User;
use App\Services\DepositoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SEGREGATION OF DUTIES — hak akses role atas fitur kas & riwayat nasabah.
 *
 * Latar belakang (audit RBAC):
 * Role `ao` (Account Officer) sebelumnya dapat:
 *   1. memicu PENCAIRAN DINI deposito via POST /api/deposito/{id}/early-withdrawal
 *      (route masih memuat `ao` pada daftar role), dan
 *   2. melihat tabel "10 Mutasi Terakhir" (riwayat CIF) pada Portofolio CIF.
 *
 * Keduanya adalah kewenangan petugas loket. AO hanya berwenang atas analisis &
 * pengajuan pembiayaan, sehingga:
 *   - Pencairan dini  : HANYA `teller` (transaksi kas, mengubah saldo 2-1003).
 *   - Riwayat/mutasi  : `teller`, `manajer`, `pengurus` (pengawas & wali data).
 *
 * Test ini mengunci ketiga syarat penerimaan perbaikan:
 *   1. `ao` DITOLAK (403) pada kedua fitur.
 *   2. `teller` tetap BERHASIL normal.
 *   3. Peran lain (admin/manajer/pengurus/dps/nasabah) tidak terganggu dan
 *      tidak ada eskalasi hak akses baru (fail-open) di Portofolio CIF.
 */
class DepositoCifRbacTest extends TestCase
{
    use DatabaseTransactions;

    private const PERAN_TIDAK_BERWENANG_KAS = ['ao', 'admin', 'manajer', 'pengurus', 'dps', 'anggota'];

    private const PERAN_LIHAT_MUTASI = ['teller', 'manajer', 'pengurus'];

    private string $prefix;

    /** @var array<string, User> */
    private array $pengguna = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'RBACCIF'.strtoupper(substr(uniqid(), -6));

        foreach (['admin', 'teller', 'manajer', 'pengurus', 'ao', 'dps', 'anggota'] as $peran) {
            $this->pengguna[$peran] = User::firstOrCreate(
                ['email' => "rbac.cif.{$peran}@test.local"],
                [
                    'name' => 'RBAC '.ucfirst($peran),
                    'password' => Hash::make('password'),
                    'role' => $peran,
                    'is_active' => true,
                ]
            );
        }
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    private function sebagai(string $peran): self
    {
        $this->actingAs($this->pengguna[$peran]);

        return $this;
    }

    private function anggota(): Anggota
    {
        return Anggota::firstOrCreate(
            ['no_anggota' => $this->prefix.'AG'],
            [
                'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
                'nama_lengkap' => 'Anggota Uji RBAC CIF',
                'jenis_kelamin' => 'Laki-laki',
                'alamat' => 'Alamat Uji RBAC',
                'kota' => 'Jakarta',
                'provinsi' => 'DKI Jakarta',
                'status_anggota' => 'Aktif',
                'tanggal_gabung' => now()->toDateString(),
            ]
        );
    }

    private function akunId(): int
    {
        $id = Akun::where('kode_akun', '1133')->value('id') ?? Akun::query()->value('id');

        if (! $id) {
            $this->markTestSkipped('Tabel akun kosong — jalankan AkunSeeder.');
        }

        return (int) $id;
    }

    private function rekeningSukarela(Anggota $anggota, float $saldo = 10000000): Rekening
    {
        return Rekening::create([
            'no_rekening' => 'RKN-'.strtoupper(substr(uniqid(), -8)),
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunId(),
            'jenis_rekening' => 'Simpanan Sukarela',
            'akad' => 'Wadiah',
            'saldo' => $saldo,
            'batas_minimum' => 0,
            'tanggal_buka' => now()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);
    }

    /** Deposito AKTIF dengan produk yang mengizinkan pencairan dini (penalti fixed). */
    private function depositoAktif(Anggota $anggota): Deposito
    {
        $produk = DepositoProduct::create([
            'name' => $this->prefix.' Deposito RBAC',
            'tipe_produk' => DepositoProduct::TIPE_DEPOSITO,
            'has_nisbah' => true,
            'coa_kode' => DepositoProduct::COA_BY_TIPE[DepositoProduct::TIPE_DEPOSITO],
            'tenor_months' => 3,
            'min_amount' => 100000,
            'nisbah_anggota' => 60.00,
            'nisbah_koperasi' => 40.00,
            'is_active' => true,
        ]);

        $produk->forceFill([
            'allow_early_withdrawal' => true,
            'penalty_fee_type' => DepositoProduct::PENALTI_FIXED,
            'penalty_fee_amount' => 50000,
            'penalty_coa_id' => DepositoProduct::PENALTI_COA_DEFAULT,
        ])->save();

        $sumber = $this->rekeningSukarela($anggota, 10000000);

        $deposito = app(DepositoService::class)->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 1000000,
            'user_id' => $this->pengguna['teller']->id,
        ]);

        $deposito->forceFill(['status' => Deposito::STATUS_ACTIVE])->save();

        return $deposito;
    }

    /* =====================================================================
     | 1 — PENCAIRAN DINI: hanya teller
     |===================================================================== */

    /**
     * Setiap peran non-teller ditolak 403 oleh middleware `role:teller`
     * SEBELUM service/auto-journal dijalankan.
     */
    #[DataProvider('peranNonTellerProvider')]
    public function test_pencairan_dini_ditolak_untuk_semua_peran_selain_teller(string $peran): void
    {
        $anggota = $this->anggota();
        $deposito = $this->depositoAktif($anggota);

        $respon = $this->sebagai($peran)
            ->postJson('/api/deposito/'.$deposito->id.'/early-withdrawal');

        $respon->assertStatus(403);

        // Tidak ada efek samping: status deposito tidak berubah.
        $this->assertSame(
            Deposito::STATUS_ACTIVE,
            $deposito->fresh()->status,
            "Peran {$peran} tidak boleh mengubah status deposito."
        );
    }

    public static function peranNonTellerProvider(): array
    {
        $data = [];

        foreach (self::PERAN_TIDAK_BERWENANG_KAS as $peran) {
            $data["peran {$peran}"] = [$peran];
        }

        return $data;
    }

    /** AO ditolak dengan pesan yang jelas (bukan 500 / redirect login). */
    public function test_ao_ditolak_dengan_403_saat_mencairkan_dini_deposito(): void
    {
        $anggota = $this->anggota();
        $deposito = $this->depositoAktif($anggota);

        $this->sebagai('ao')
            ->postJson('/api/deposito/'.$deposito->id.'/early-withdrawal')
            ->assertStatus(403);
    }

    /** Teller tetap dapat mencairkan dini secara normal (regresi fungsional). */
    public function test_teller_tetap_dapat_mencairkan_dini_deposito(): void
    {
        $anggota = $this->anggota();
        $deposito = $this->depositoAktif($anggota);

        $this->sebagai('teller')
            ->postJson('/api/deposito/'.$deposito->id.'/early-withdrawal')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.penalti', 50000)
            ->assertJsonPath('data.diterima_anggota', 950000);

        $this->assertNotSame(
            Deposito::STATUS_ACTIVE,
            $deposito->fresh()->status,
            'Teller harus berhasil mengubah status deposito setelah pencairan dini.'
        );
    }

    /** Pengguna anonim diarahkan ke login (tidak bocor sebagai 403/200). */
    public function test_pencairan_dini_oleh_tamu_diarahkan_ke_login(): void
    {
        $anggota = $this->anggota();
        $deposito = $this->depositoAktif($anggota);

        $this->post('/api/deposito/'.$deposito->id.'/early-withdrawal')
            ->assertRedirect(route('login'));
    }

    /* =====================================================================
     | 2 — RIWAYAT CIF (tabel "10 Mutasi Terakhir")
     |===================================================================== */

    /**
     * Tabel mutasi hanya dirender untuk teller/manajer/pengurus; AO dan peran
     * lain tidak menerima tabel maupun datanya.
     */
    public function test_riwayat_mutasi_cif_hanya_dirender_untuk_peran_berwenang(): void
    {
        $anggota = $this->anggota();
        $this->rekeningSukarela($anggota, 1000000);

        foreach (self::PERAN_LIHAT_MUTASI as $peran) {
            $html = $this->sebagai($peran)
                ->get(route($peran.'.cif.show', $anggota->id))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString(
                'id="tabel-mutasi-terakhir"',
                $html,
                "Peran {$peran} seharusnya MELIHAT tabel 10 Mutasi Terakhir."
            );
        }
    }

    /** AO membuka Portofolio CIF tetapi TIDAK melihat tabel mutasi. */
    public function test_ao_tidak_melihat_tabel_mutasi_pada_portofolio_cif(): void
    {
        $anggota = $this->anggota();
        $this->rekeningSukarela($anggota, 1000000);

        $html = $this->sebagai('ao')
            ->get(route('ao.cif.show', $anggota->id))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            'id="tabel-mutasi-terakhir"',
            $html,
            'Role AO tidak boleh menerima tabel 10 Mutasi Terakhir.'
        );
    }

    /** Tombol/modal/pemanggil endpoint pencairan dini tidak dirender untuk AO. */
    public function test_ao_tidak_menerima_tombol_pencairan_dini_pada_portofolio_cif(): void
    {
        $anggota = $this->anggota();
        $this->depositoAktif($anggota);

        $html = $this->sebagai('ao')
            ->get(route('ao.cif.show', $anggota->id))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('modal-cair-dini', $html,
            'Modal konfirmasi pencairan dini tidak boleh dirender untuk AO.');
        $this->assertStringNotContainsString('Pencairan Dini</', $html,
            'Tombol Pencairan Dini tidak boleh dirender untuk AO.');
    }

    /** Teller tetap menerima tombol, modal, dan skrip pencairan dini. */
    public function test_teller_tetap_menerima_tombol_pencairan_dini_pada_portofolio_cif(): void
    {
        $anggota = $this->anggota();
        $this->depositoAktif($anggota);

        $html = $this->sebagai('teller')
            ->get(route('teller.cif.show', $anggota->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('btn-cair-dini', $html,
            'Teller harus menerima tombol Pencairan Dini.');
        $this->assertStringContainsString('modal-cair-dini', $html,
            'Teller harus menerima modal konfirmasi pencairan dini.');
        $this->assertStringContainsString('early-withdrawal', $html,
            'Skrip teller harus memanggil endpoint early-withdrawal.');
    }

    /* =====================================================================
     | 3 — PERAN LAIN TIDAK TERGANGGU & TIDAK ADA FAIL-OPEN
     |===================================================================== */

    /**
     * Daftar putih Portofolio CIF bersifat fail-CLOSED: peran yang tidak
     * terdaftar (mis. `admin`, `dps`, `nasabah`) ditolak 403, bukan diam-diam
     * dialihkan ke view AO seperti pola lama `?? 'ao.cif.show'`.
     */
    #[DataProvider('peranTanpaPortofolioProvider')]
    public function test_peran_di_luar_daftar_putih_tidak_bisa_membuka_portofolio_cif(string $peran): void
    {
        $anggota = $this->anggota();

        $this->sebagai($peran)
            ->get('/ao/cif/'.$anggota->id)
            ->assertStatus(403);
    }

    public static function peranTanpaPortofolioProvider(): array
    {
        return [
            'admin' => ['admin'],
            'dps' => ['dps'],
            'anggota' => ['anggota'],
        ];
    }

    /** Manajer & pengurus (pengawas) tetap dapat membuka Portofolio CIF. */
    public function test_manajer_dan_pengurus_tetap_dapat_membuka_portofolio_cif(): void
    {
        $anggota = $this->anggota();

        foreach (['manajer', 'pengurus'] as $peran) {
            $this->sebagai($peran)
                ->get(route($peran.'.cif.show', $anggota->id))
                ->assertOk();
        }
    }
}
