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

    /**
     * DYN-18 — wewenang menginput pengajuan dibaca dari izin bernama
     * `pembiayaan.pengajuan.masukan`, bukan daftar peran literal di view.
     * Harus memuat `ao` & `manajer`, dan TIDAK memuat `pengurus`/`teller`.
     */
    public function test_izin_masukan_pengajuan_mengikuti_daftar_peran_form(): void
    {
        $peran = \App\Support\Izin::peran('pembiayaan.pengajuan.masukan');

        sort($peran);

        $this->assertSame(['ao', 'manajer', 'super_admin'], $peran);
    }

    public function test_view_pengajuan_tidak_lagi_memakai_ability_peran_literal(): void
    {
        $tersisa = [];

        $berkas = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($berkas as $satu) {
            if ($satu->getExtension() !== 'php') {
                continue;
            }

            $isi = (string) file_get_contents($satu->getPathname());

            // Daftar peran literal = tanda kurung siku sesudah ability `role`.
            if (preg_match("/@(?:can|cannot|canany|cannotany)\(\s*'role'\s*,\s*\[/", $isi)) {
                $tersisa[] = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $satu->getPathname());
            }
        }

        $this->assertSame([], $tersisa, "View masih memakai @can('role', [...]) literal:\n".implode("\n", $tersisa));
    }

    /**
     * Super Admin memegang `pembiayaan.pengajuan.masukan` (aturan 5: pewaris
     * puncak), sehingga form pengajuan juga dirender untuknya.
     */
    public function test_form_pengajuan_dirender_untuk_super_admin(): void
    {
        $this->sebagai('super_admin')
            ->get(route('pengajuan-pembiayaan.index'))
            ->assertOk()
            ->assertSee('Form Pengajuan Pembiayaan');
    }
}
