<?php

namespace Tests\Feature;

use App\Models\LimitTransaksiRole;
use App\Models\User;
use App\Support\HierarkiRole;
use App\Support\Izin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * MATRIKS HAK AKSES & OVERRIDE BATAS OPERASIONAL (nomor 7).
 *
 * Halaman `pengurus/users` sebelumnya berjudul "Manajemen User & Hak Akses"
 * tetapi hanya menampilkan daftar user. Test ini mengunci tiga hal:
 *   1. Halaman menampilkan matriks izin dari config/izin.php (READ-ONLY).
 *   2. Override `limit_transaksi_role` dapat ditambah/diubah/dihapus oleh
 *      Super Admin, dan langsung mengubah `HierarkiRole::batas()`.
 *   3. Guard TIDAK dilonggarkan: Pengurus (dan peran operasional lain) tetap
 *      ditolak 403 dari halaman maupun aksi tulis.
 */
class MatriksHakAksesTest extends TestCase
{
    use DatabaseTransactions;

    private User $superAdmin;

    private User $pengurus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::firstOrCreate(
            ['email' => 'matriks.izin.superadmin@test.local'],
            [
                'name' => 'Uji Matriks Super Admin',
                'password' => Hash::make('password'),
                'role' => 'super_admin',
                'is_active' => true,
            ]
        );

        $this->pengurus = User::firstOrCreate(
            ['email' => 'matriks.izin.pengurus@test.local'],
            [
                'name' => 'Uji Matriks Pengurus',
                'password' => Hash::make('password'),
                'role' => 'pengurus',
                'is_active' => true,
            ]
        );
    }

    protected function tearDown(): void
    {
        HierarkiRole::flush();
        parent::tearDown();
    }

    /* ══════════════════════════════════════════════════════════════════
     |  1. MATRIKS IZIN (READ-ONLY)
     ══════════════════════════════════════════════════════════════════ */

    public function test_halaman_menampilkan_seluruh_izin_dari_konfigurasi(): void
    {
        $response = $this->actingAs($this->superAdmin)->get(route('pengurus.users.index'));

        $response->assertOk();
        $response->assertSee('Matriks Hak Akses per Peran', false);

        // Setiap izin pada peta wajib tampil — kalau tidak, dokumentasi hidup
        // halaman ini tidak dapat dipakai sebagai rujukan.
        foreach (array_keys(Izin::peta()) as $izin) {
            $response->assertSee($izin, false);
        }
    }

    public function test_matriks_tidak_menyediakan_jalur_ubah_izin(): void
    {
        // Tidak ada rute tulis ke config/izin.php, dan tidak ada izin baru
        // yang dibuat untuk halaman ini (pakai master.data existing).
        $namaRute = collect(app('router')->getRoutes()->getRoutes())
            ->map(static fn ($route): string => (string) $route->getName())
            ->filter(static fn (string $nama): bool => str_contains($nama, 'izin'));

        $this->assertSame([], $namaRute->values()->all());
        $this->assertSame(['super_admin'], Izin::peran('master.data'));
    }

    /* ══════════════════════════════════════════════════════════════════
     |  2. CRUD OVERRIDE LIMIT
     ══════════════════════════════════════════════════════════════════ */

    public function test_super_admin_dapat_menambah_override_dan_langsung_berlaku(): void
    {
        LimitTransaksiRole::where('role', 'ao')->where('jenis_transaksi', 'non_tunai')->delete();
        $sebelum = HierarkiRole::batas('ao', 'non_tunai');

        $this->actingAs($this->superAdmin)
            ->post(route('pengurus.limit-role.store'), [
                'role' => 'ao',
                'jenis_transaksi' => 'non_tunai',
                'limit_per_transaksi' => '2500000',
                'limit_harian' => '10000000',
                'butuh_approval' => '1',
                'approval_role' => 'manajer',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('pengurus.users.index'));

        $this->assertDatabaseHas('limit_transaksi_role', [
            'role' => 'ao',
            'jenis_transaksi' => 'non_tunai',
            'approval_role' => 'manajer',
        ]);

        // Cache override harus sudah dibersihkan oleh model (event saved).
        $sesudah = HierarkiRole::batas('ao', 'non_tunai');

        $this->assertNotSame($sebelum, $sesudah);
        $this->assertSame(2500000.0, (float) $sesudah['limit_per_transaksi']);
        $this->assertSame(10000000.0, (float) $sesudah['limit_harian']);
        $this->assertTrue((bool) $sesudah['butuh_approval']);
        $this->assertSame('manajer', $sesudah['approval_role']);
    }

    public function test_simbol_nol_berarti_dilarang_dan_kosong_berarti_unlimited(): void
    {
        LimitTransaksiRole::updateOrCreate(
            ['role' => 'teller', 'jenis_transaksi' => 'non_tunai'],
            ['limit_per_transaksi' => 0, 'limit_harian' => null, 'butuh_approval' => false]
        );

        $batas = HierarkiRole::batas('teller', 'non_tunai');

        $this->assertSame(0.0, (float) $batas['limit_per_transaksi'], 'Nol harus tetap nol (dilarang), bukan unlimited.');
        $this->assertNull($batas['limit_harian'], 'NULL harus bermakna unlimited.');
    }

    public function test_override_dapat_diubah_dan_dihapus(): void
    {
        $limit = LimitTransaksiRole::updateOrCreate(
            ['role' => 'teller', 'jenis_transaksi' => 'tarik_tunai'],
            ['limit_per_transaksi' => 1000000, 'limit_harian' => null, 'butuh_approval' => false]
        );

        $this->actingAs($this->superAdmin)
            ->put(route('pengurus.limit-role.update', $limit->id), [
                'role' => 'teller',
                'jenis_transaksi' => 'tarik_tunai',
                'limit_per_transaksi' => '7500000',
                'limit_harian' => '',
                'butuh_approval' => '0',
                'approval_role' => 'manajer',
            ])
            ->assertSessionHasNoErrors();

        $limit->refresh();
        $this->assertSame('7500000.00', (string) $limit->limit_per_transaksi);
        $this->assertNull($limit->approval_role, 'Approval role harus dikosongkan saat butuh_approval = false.');

        $this->actingAs($this->superAdmin)
            ->delete(route('pengurus.limit-role.destroy', $limit->id))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('limit_transaksi_role', ['id' => $limit->id]);
    }

    public function test_pasangan_peran_dan_jenis_tidak_boleh_ganda(): void
    {
        LimitTransaksiRole::updateOrCreate(
            ['role' => 'manajer', 'jenis_transaksi' => 'setor_tunai'],
            ['limit_per_transaksi' => 50000000]
        );

        $this->actingAs($this->superAdmin)
            ->post(route('pengurus.limit-role.store'), [
                'role' => 'manajer',
                'jenis_transaksi' => 'setor_tunai',
                'limit_harian' => '1000000',
            ])
            ->assertSessionHasErrors(['role']);
    }

    public function test_validasi_menolak_peran_jenis_dan_nominal_tidak_sah(): void
    {
        $kasus = [
            'peran tidak dikenal' => ['role' => 'kasir_asing', 'jenis_transaksi' => 'setor_tunai'],
            'jenis tidak dikenal' => ['role' => 'ao', 'jenis_transaksi' => 'transfer_asing'],
            'nominal negatif' => ['role' => 'ao', 'jenis_transaksi' => 'setor_tunai', 'limit_per_transaksi' => '-1'],
            'approval tak dikenal' => ['role' => 'ao', 'jenis_transaksi' => 'setor_tunai', 'butuh_approval' => '1', 'approval_role' => 'dewa'],
        ];

        foreach ($kasus as $label => $muatan) {
            $this->actingAs($this->superAdmin)
                ->post(route('pengurus.limit-role.store'), $muatan)
                ->assertSessionHasErrors(null, null, $label);
        }
    }

    /* ══════════════════════════════════════════════════════════════════
     |  3. GUARD TIDAK DILONGGARKAN
     ══════════════════════════════════════════════════════════════════ */

    public function test_pengurus_tetap_ditolak_dari_halaman_dan_aksi_tulis(): void
    {
        $limit = LimitTransaksiRole::updateOrCreate(
            ['role' => 'teller', 'jenis_transaksi' => 'setor_tunai'],
            ['limit_per_transaksi' => 1000000]
        );

        $this->actingAs($this->pengurus)->get(route('pengurus.users.index'))->assertForbidden();

        $this->actingAs($this->pengurus)
            ->post(route('pengurus.limit-role.store'), [
                'role' => 'teller',
                'jenis_transaksi' => 'setor_tunai',
                'limit_harian' => '999999999',
            ])
            ->assertForbidden();

        $this->actingAs($this->pengurus)
            ->put(route('pengurus.limit-role.update', $limit->id), [
                'role' => 'teller',
                'jenis_transaksi' => 'setor_tunai',
                'limit_harian' => '999999999',
            ])
            ->assertForbidden();

        $this->actingAs($this->pengurus)
            ->delete(route('pengurus.limit-role.destroy', $limit->id))
            ->assertForbidden();

        $this->assertDatabaseHas('limit_transaksi_role', ['id' => $limit->id]);
    }

    public function test_teller_dan_manajer_ditolak_dari_halaman(): void
    {
        foreach (['teller', 'manajer', 'ao'] as $peran) {
            $user = User::firstOrCreate(
                ['email' => 'matriks.izin.'.$peran.'@test.local'],
                [
                    'name' => 'Uji Matriks '.$peran,
                    'password' => Hash::make('password'),
                    'role' => $peran,
                    'is_active' => true,
                ]
            );

            $this->actingAs($user)->get(route('pengurus.users.index'))->assertForbidden();
        }
    }
}
