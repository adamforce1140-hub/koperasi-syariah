<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Regresi K-1 — ability `role` harus terdaftar di Gate.
 *
 * Sebelum perbaikan, `@can('role', ['ao','manajer'])` pada view pengajuan
 * pembiayaan selalu false karena tidak ada `Gate::define('role', ...)`
 * maupun method `role()` di TellerPolicy. Akibatnya form pengajuan tidak
 * pernah dirender untuk peran mana pun.
 */
class AbilityRoleGateTest extends TestCase
{
    use DatabaseTransactions;

    /** Peran yang benar-benar ada di ENUM users.role. */
    private const SEMUA_PERAN = ['admin', 'teller', 'manajer', 'pengurus', 'anggota', 'ao', 'dps', 'super_admin'];

    private array $pengguna = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::SEMUA_PERAN as $peran) {
            $this->pengguna[$peran] = User::firstOrCreate(
                ['email' => "gate.role.{$peran}@test.local"],
                [
                    'name' => 'Uji '.ucfirst($peran),
                    'password' => Hash::make('password'),
                    'role' => $peran,
                ]
            );
        }
    }

    private function sebagai(string $peran): self
    {
        $this->actingAs($this->pengguna[$peran]);

        return $this;
    }

    public function test_ability_role_menerjemahkan_daftar_peran_menjadi_boolean(): void
    {
        // Hanya peran yang diminta yang lolos — tidak ada bypass implisit.
        $diharapkan = [
            'ao' => true,
            'manajer' => true,
            'pengurus' => false,
            'teller' => false,
            'dps' => false,
            'anggota' => false,
            'admin' => false,
            'super_admin' => false,
        ];

        foreach ($diharapkan as $peran => $boleh) {
            $this->assertSame(
                $boleh,
                Gate::forUser($this->pengguna[$peran])->allows('role', ['ao', 'manajer']),
                "Ability role untuk peran {$peran} tidak sesuai harapan."
            );
        }
    }

    public function test_ability_role_menerima_satu_peran_maupun_daftar_bersarang(): void
    {
        $this->assertTrue(
            Gate::forUser($this->pengguna['ao'])->allows('role', 'ao'),
            'Ability role harus menerima satu nama peran sebagai string.'
        );

        $this->assertTrue(
            Gate::forUser($this->pengguna['manajer'])->allows('role', [['ao', 'manajer']]),
            'Daftar peran bersarang harus diratakan sebelum dibandingkan.'
        );
    }

    public function test_form_pengajuan_dirender_untuk_ao(): void
    {
        $this->sebagai('ao')
            ->get(route('pengajuan-pembiayaan.index'))
            ->assertOk()
            ->assertSee('Form Pengajuan Pembiayaan');
    }

    public function test_form_pengajuan_dirender_untuk_manajer(): void
    {
        $this->sebagai('manajer')
            ->get(route('pengajuan-pembiayaan.index'))
            ->assertOk()
            ->assertSee('Form Pengajuan Pembiayaan');
    }

    public function test_form_pengajuan_tidak_dirender_untuk_pengurus(): void
    {
        // Pengurus berhak membuka halaman (izin:pembiayaan.pengajuan), tetapi
        // tidak berwenang menginput pengajuan — form harus tetap tersembunyi.
        $this->sebagai('pengurus')
            ->get(route('pengajuan-pembiayaan.index'))
            ->assertOk()
            ->assertDontSee('Form Pengajuan Pembiayaan');
    }

    public function test_form_pengajuan_tidak_dapat_diakses_teller(): void
    {
        $this->sebagai('teller')
            ->get(route('pengajuan-pembiayaan.index'))
            ->assertForbidden();
    }
}
