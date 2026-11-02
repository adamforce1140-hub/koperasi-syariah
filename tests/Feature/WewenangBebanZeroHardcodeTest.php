<?php

namespace Tests\Feature;

use App\Models\Coa;
use App\Models\PengeluaranBeban;
use App\Models\User;
use App\Services\AutoJournalingService;
use App\Support\HierarkiRole;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Wewenang modul Pengeluaran Beban Operasional — anti-hardcode.
 *
 * Guard lama menulis literal `if ($role === 'ao') { redirect('dashboard.ao') }`
 * di dalam PengeluaranBebanController::__construct(). Guard baru membaca
 * pemetaan dari `role_hierarki.kelompok_tolak`, sehingga:
 *
 *   A. Peran berwenang (`pengelola_beban`) boleh membuka modul.
 *   B. Peran yang ditolak TETAPI punya pengalihan ramah (AO) dialihkan ke
 *      dasbornya + pesan error — BUKAN 403. Perilaku ini identik dengan guard
 *      literal yang digantikannya (anti-regresi).
 *   C. Peran lain yang tidak berwenang menerima 403.
 *   D. Pemetaan pengalihan hidup di konfigurasi, bukan di controller — sehingga
 *      menambah pengalihan tidak perlu mengedit kode.
 */
class WewenangBebanZeroHardcodeTest extends TestCase
{
    use DatabaseTransactions;

    private function pengguna(string $role): User
    {
        return User::firstOrCreate(
            ['email' => "wewenangbeban.{$role}@test.local"],
            [
                'name' => 'Wewenang Beban '.ucfirst(str_replace('_', ' ', $role)),
                'password' => Hash::make('password'),
                'role' => $role,
            ]
        );
    }

    /* ============================================================
     * A. Sumber pusat — kelompok & pemetaan penolakan
     * ============================================================ */

    public function test_kelompok_pengelola_beban_hanya_teller_dan_manajer(): void
    {
        $this->assertSame(['teller', 'manajer'], HierarkiRole::kelompok('pengelola_beban'));
    }

    public function test_pemetaan_penolakan_kelompok_berasal_dari_konfigurasi(): void
    {
        // Nilainya HARUS identik dengan isi config — bukti hidup di konfigurasi,
        // bukan literal di controller.
        $this->assertSame(
            config('role_hierarki.kelompok_tolak.pengelola_beban'),
            [
                'ao' => [
                    'route' => 'dashboard.ao',
                    'pesan' => 'Akses ditolak. Anda tidak memiliki izin mengelola beban operasional.',
                ],
            ]
        );
    }

    public function test_penolakan_kelompok_dikenali_untuk_ao(): void
    {
        $tolak = HierarkiRole::penolakanKelompok('ao', 'pengelola_beban');

        $this->assertNotNull($tolak);
        $this->assertSame('dashboard.ao', $tolak['route']);
        $this->assertStringContainsString('tidak memiliki izin', $tolak['pesan']);
    }

    public function test_penolakan_kelompok_null_untuk_peran_berwenang_dan_kelompok_tak_terdaftar(): void
    {
        // Peran berwenang tidak punya pengalihan (ia lolos, bukan ditolak).
        $this->assertNull(HierarkiRole::penolakanKelompok('teller', 'pengelola_beban'));
        $this->assertNull(HierarkiRole::penolakanKelompok('manajer', 'pengelola_beban'));

        // Kelompok tanpa entri `kelompok_tolak` → null (pemanggil abort 403).
        $this->assertNull(HierarkiRole::penolakanKelompok('ao', 'kelompok_tak_terdaftar'));

        // Peran null/kosong tidak pernah dianggap punya pengalihan.
        $this->assertNull(HierarkiRole::penolakanKelompok(null, 'pengelola_beban'));
        $this->assertNull(HierarkiRole::penolakanKelompok('', 'pengelola_beban'));
    }

    /* ============================================================
     * B. Perilaku HTTP — anti-regresi guard lama
     * ============================================================ */

    public function test_teller_dan_manajer_dapat_membuka_modul_beban(): void
    {
        foreach (['teller', 'manajer'] as $role) {
            $res = $this->actingAs($this->pengguna($role))->get(route('pengeluaran-beban.index'));

            $res->assertOk();
        }
    }

    public function test_ao_dialihkan_ke_dasbor_dengan_pesan_ramah_bukan_403(): void
    {
        $res = $this->actingAs($this->pengguna('ao'))->get(route('pengeluaran-beban.index'));

        // Perilaku guard literal lama: redirect ke dashboard.ao + pesan error.
        $res->assertRedirect(route('dashboard.ao'));
        $res->assertSessionHas('error', 'Akses ditolak. Anda tidak memiliki izin mengelola beban operasional.');
    }

    public function test_peran_lain_yang_tidak_berwenang_menerima_403(): void
    {
        // dps/pengurus/admin tidak masuk daftar rute (role:teller,manajer,ao) →
        // 403 dari middleware sebelum sampai controller.
        foreach (['dps', 'pengurus', 'admin'] as $role) {
            $res = $this->actingAs($this->pengguna($role))->get(route('pengeluaran-beban.index'));

            $res->assertForbidden();
        }
    }

    /* ============================================================
     * C. Guard benar-benar ditegakkan (bukan hanya deklarasi rute)
     * ============================================================ */

    public function test_ao_juga_ditolak_saat_menyimpan_beban(): void
    {
        $coaKas = Coa::findByKode('1-1001') ?? Coa::where('kategori', 'Aset')->firstOrFail();
        $coaBeban = Coa::where('kategori', 'Beban')->firstOrFail();

        $res = $this->actingAs($this->pengguna('ao'))->post(route('pengeluaran-beban.store'), [
            'tanggal' => now()->toDateString(),
            'jenis_beban' => array_key_first(AutoJournalingService::JENIS_BEBAN),
            'coa_beban' => $coaBeban->id,
            'coa_kas' => $coaKas->id,
            'nominal' => 50000,
            'keterangan' => 'Uji tolak AO',
        ]);

        $res->assertRedirect(route('dashboard.ao'));

        // Tidak ada baris yang tercipta.
        $this->assertSame(
            0,
            PengeluaranBeban::where('keterangan', 'Uji tolak AO')->count()
        );
    }
}
