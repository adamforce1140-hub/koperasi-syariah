<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Rekening;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Formulir "Setor Tunai" Teller — pemuatan data anggota.
 *
 * Regresi akar masalah (anggota ID 8560 / "Nadia Puspita"): anggota baru
 * berstatus PENDING menyelesaikan SETORAN AWAL di loket Teller, tetapi
 * formulir setoran menyaring mereka keluar lewat `Anggota::aktif()`,
 * sehingga namanya tidak pernah tampil dan proses setor tidak bisa
 * dilanjutkan dari tautan `?anggota_id=...` pada halaman profil anggota.
 *
 * Aturan yang dikunci test ini:
 *  1. Anggota ACTIVE maupun PENDING (dengan rekening Aktif) tampil pada dropdown.
 *  2. Query `?anggota_id=` mempraseleksi anggota ybs. sehingga NAMA tampil.
 *  3. Anggota yang diminta di luar populasi (mis. riwayat/suspended) tetap
 *     disertakan agar formulir tidak pernah kosong.
 *  4. Anggota tidak dikenal → pesan error yang jelas, bukan halaman kosong.
 *  5. Anggota tanpa rekening aktif → tidak masuk dropdown, tetapi tetap
 *     ditampilkan bila diminta eksplisit (rekening kosong ditangani di UI).
 */
class SetorTunaiAnggotaTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $role = 'teller'): User
    {
        return User::firstOrCreate(
            ['email' => "{$role}.setortunai@test.local"],
            [
                'name' => ucfirst($role).' Setor Tunai',
                'password' => Hash::make('password'),
                'role' => $role,
            ]
        );
    }

    /** Akun CoA (FK wajib `rekening.akun_id`) — buat bila belum ada. */
    private function akunId(): int
    {
        $id = DB::table('coas')->value('id');
        if ($id) {
            return (int) $id;
        }

        return (int) DB::table('coas')->insertGetId([
            'kode_akun' => '1-1001',
            'nama_akun' => 'Kas Uji',
            'tipe_akun' => 'Aset',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function anggota(array $override = []): Anggota
    {
        $suffix = strtoupper(substr(uniqid(), -6));

        return Anggota::create(array_merge([
            'no_anggota' => 'ANG-ST-'.$suffix,
            'nama_lengkap' => 'Anggota Setor '.$suffix,
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'jenis_kelamin' => 'Perempuan',
            'alamat' => 'Jl. Uji Setor No. 1',
            'kota' => 'Bogor',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_PENDING,
            'tanggal_gabung' => now()->subDays(3)->toDateString(),
        ], $override));
    }

    private function rekening(Anggota $anggota, string $jenis = 'Simpanan Pokok', string $status = 'Aktif'): Rekening
    {
        return Rekening::create([
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunId(),
            'no_rekening' => 'REK-ST-'.strtoupper(substr(uniqid(), -8)),
            'jenis_rekening' => $jenis,
            'status_rekening' => $status,
            'saldo' => 0,
            'tanggal_buka' => now()->toDateString(),
        ]);
    }

    /* =====================================================================
     | 1. Populasi dropdown
     |===================================================================== */

    /** Anggota PENDING (calon penyetor setoran awal) HARUS tampil di dropdown. */
    public function test_anggota_pending_tampil_pada_dropdown_setoran(): void
    {
        $anggota = $this->anggota(['nama_lengkap' => 'Nadia Puspita Uji']);
        $this->rekening($anggota);

        $this->actingAs($this->user())
            ->get(route('teller.setor'))
            ->assertOk()
            ->assertSee('Nadia Puspita Uji', false);
    }

    /** Anggota ACTIVE juga tetap tampil. */
    public function test_anggota_active_tampil_pada_dropdown_setoran(): void
    {
        $anggota = $this->anggota([
            'nama_lengkap' => 'Gilang Prasetyo Uji',
            'status_anggota' => Anggota::STATUS_ACTIVE,
        ]);
        $this->rekening($anggota);

        $this->actingAs($this->user())
            ->get(route('teller.setor'))
            ->assertOk()
            ->assertSee('Gilang Prasetyo Uji', false);
    }

    /* =====================================================================
     | 2. Praseleksi `?anggota_id=`
     |===================================================================== */

    /** Tautan profil `?anggota_id=` memuat NAMA anggota yang benar. */
    public function test_query_anggota_id_memuat_nama_anggota(): void
    {
        $anggota = $this->anggota(['nama_lengkap' => 'Nadia Puspita Terpilih']);
        $this->rekening($anggota);

        $this->actingAs($this->user())
            ->get(route('teller.setor', ['anggota_id' => $anggota->id]))
            ->assertOk()
            ->assertSee('Nadia Puspita Terpilih', false);
    }

    /** Anggota yang diminta dipilih (atribut `selected`) pada dropdown. */
    public function test_anggota_diminta_terpraseleksi_pada_dropdown(): void
    {
        $anggota = $this->anggota(['nama_lengkap' => 'Anggota Praseleksi Uji']);
        $this->rekening($anggota);

        $html = $this->actingAs($this->user())
            ->get(route('teller.setor', ['anggota_id' => $anggota->id]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<option value="'.$anggota->id.'"[^>]*selected[^>]*>/',
            $html,
            'Anggota yang diminta harus terpraseleksi pada dropdown.'
        );
    }

    /**
     * Anggota yang diminta di luar populasi (mis. SUSPENDED) tetap ditampilkan
     * agar formulir tidak pernah kosong saat dibuka dari tautan profil.
     */
    public function test_anggota_di_luar_populasi_tetap_ditampilkan_bila_diminta(): void
    {
        $anggota = $this->anggota([
            'nama_lengkap' => 'Anggota Suspended Uji',
            'status_anggota' => Anggota::STATUS_SUSPENDED,
        ]);
        $this->rekening($anggota);

        $this->actingAs($this->user())
            ->get(route('teller.setor', ['anggota_id' => $anggota->id]))
            ->assertOk()
            ->assertSee('Anggota Suspended Uji', false);
    }

    /* =====================================================================
     | 3. Penanganan error
     |===================================================================== */

    /** Anggota tidak ditemukan → pesan jelas, bukan halaman kosong. */
    public function test_anggota_tidak_ditemukan_menampilkan_pesan_jelas(): void
    {
        $this->actingAs($this->user())
            ->get(route('teller.setor', ['anggota_id' => 999999999]))
            ->assertRedirect(route('teller.setor'))
            ->assertSessionHas('error');

        // Pesan tampil pada render berikutnya.
        $this->actingAs($this->user())
            ->get(route('teller.setor'))
            ->assertOk()
            ->assertSee('Anggota tidak ditemukan', false);
    }

    /** Anggota tanpa rekening aktif tidak masuk dropdown, tetapi tetap bisa dimuat eksplisit. */
    public function test_anggota_tanpa_rekening_aktif_tidak_masuk_populasi_umum(): void
    {
        $anggota = $this->anggota(['nama_lengkap' => 'Tanpa Rekening Uji']);
        $this->rekening($anggota, 'Simpanan Pokok', 'Ditutup');

        // Tanpa parameter: tidak tampil (tidak ada rekening aktif).
        $this->actingAs($this->user())
            ->get(route('teller.setor'))
            ->assertOk()
            ->assertDontSee('Tanpa Rekening Uji', false);

        // Dengan parameter eksplisit: tetap dimuat agar teller tahu alasannya.
        $this->actingAs($this->user())
            ->get(route('teller.setor', ['anggota_id' => $anggota->id]))
            ->assertOk()
            ->assertSee('Tanpa Rekening Uji', false);
    }

    /* =====================================================================
     | 4. RBAC
     |===================================================================== */

    /** Role di luar teller/manajer/pengurus tidak boleh mengakses formulir. */
    public function test_role_terlarang_ditolak(): void
    {
        $this->actingAs($this->user('ao'))
            ->get(route('teller.setor'))
            ->assertForbidden();
    }
}
