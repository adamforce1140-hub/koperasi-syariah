<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\RoleKonfigurasi;
use App\Models\User;
use App\Support\HierarkiRole;
use App\Support\Izin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * LANGKAH #6 — konfigurasi peran tersimpan di database & BERTAHAN.
 *
 * Pemilik sistem meminta konfigurasi role `super admin` disimpan dengan
 * benar di database sehingga pengaturan hak akses tetap konsisten dan
 * bertahan di semua sesi. Yang diuji:
 *
 *   1. Snapshot memuat SELURUH aturan yang berlaku: level, kategori,
 *      kelompok, izin_dokumen, dan setiap izin yang dipegang peran.
 *   2. Sinkronisasi idempoten (dijalankan berulang tidak menggandakan baris).
 *   3. Penyimpangan config DIDETEKSI (`selisih`) dan dapat DIPULIHKAN
 *      (`pulihkan`) tanpa mengubah aturan yang berlaku.
 *   4. Puncak administratif tidak dapat kehilangan hak: snapshot super_admin
 *      memuat seluruh izin modul, dan barisnya tidak dapat disunting pihak lain.
 */
class KonfigurasiRoleTersimpanTest extends TestCase
{
    use DatabaseTransactions;

    private function pengguna(string $peran, string $email): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            ['name' => 'Uji '.$peran, 'password' => Hash::make('secret'), 'role' => $peran]
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function payloadSuperAdmin(): array
    {
        return RoleKonfigurasi::payloadPeran('super_admin');
    }

    // ───────────────────────────────────────────────────────────────
    // 1. SNAPSHOT LENGKAP
    // ───────────────────────────────────────────────────────────────
    public function test_snapshot_super_admin_memuat_seluruh_lapisan_aturan(): void
    {
        RoleKonfigurasi::sinkronkan();

        $baris = RoleKonfigurasi::query()->where('role', 'super_admin')->first();
        $this->assertNotNull($baris, 'Baris role_konfigurasi untuk super_admin tidak tersimpan.');

        $this->assertSame(100, $baris->level);
        $this->assertSame('Super Admin', $baris->label);
        $this->assertFalse($baris->penyetuju, 'Super Admin tetap bukan penyetuju transaksi.');
        $this->assertSame(HierarkiRole::puncak(), $baris->role);

        // Seluruh izin yang berlaku WAJIB tercatat — inilah inti "tidak ada
        // yang terlewat" pada langkah #1, kini tersimpan di database.
        foreach (Izin::peta() as $nama => $daftar) {
            if (! in_array('super_admin', array_map('strval', (array) $daftar), true)) {
                continue;
            }

            $this->assertContains((string) $nama, $baris->izin, "Izin '{$nama}' tidak tercatat pada snapshot.");
        }

        // Kelompok & prefiks dokumen ikut tersimpan.
        $this->assertNotEmpty($baris->kelompok);
        $this->assertContains('sistem', $baris->kelompok);
        $this->assertNotEmpty($baris->izin_dokumen);
        $this->assertContains('ktp', $baris->izin_dokumen);

        $this->assertSame(RoleKonfigurasi::hitungSidik($this->payloadSuperAdmin()), $baris->sidik);
    }

    public function test_setiap_peran_dikenal_memiliki_snapshot(): void
    {
        RoleKonfigurasi::sinkronkan();

        foreach (HierarkiRole::peran() as $peran) {
            $this->assertDatabaseHas('role_konfigurasi', ['role' => $peran]);
        }

        $this->assertSame(
            count(HierarkiRole::peran()),
            RoleKonfigurasi::query()->count(),
            'Snapshot tidak boleh memuat peran di luar config.'
        );
    }

    // ───────────────────────────────────────────────────────────────
    // 2. IDEMPOTEN
    // ───────────────────────────────────────────────────────────────
    public function test_sinkronisasi_idempoten(): void
    {
        RoleKonfigurasi::sinkronkan();
        $jumlah = RoleKonfigurasi::query()->count();

        $kedua = RoleKonfigurasi::sinkronkan();

        $this->assertSame($jumlah, RoleKonfigurasi::query()->count(), 'Sinkronisasi menggandakan baris.');

        // Sinkronisasi kedua tidak boleh mengubah apa pun, apa pun keadaan
        // awalnya (baris bisa sudah ada dari seeder — karena itu `$pertama`
        // tidak dijadikan patokan).
        foreach ($kedua as $peran => $keadaan) {
            $this->assertSame('tetap', $keadaan, "Sinkronisasi kedua seharusnya tidak mengubah '{$peran}'.");
        }

        $this->assertSame([], RoleKonfigurasi::selisih());
    }

    // ───────────────────────────────────────────────────────────────
    // 3. DETEKSI & PEMULIHAN PENYIMPANGAN
    // ───────────────────────────────────────────────────────────────
    public function test_penyimpangan_config_terdeteksi(): void
    {
        RoleKonfigurasi::sinkronkan();

        // Simulasi suntingan config yang menghapus Super Admin dari satu izin.
        $peta = Izin::peta();
        $peta['master.data'] = array_values(array_diff($peta['master.data'], ['super_admin']));
        config(['izin.izin' => $peta]);

        $selisih = RoleKonfigurasi::selisih();

        $this->assertArrayHasKey('super_admin', $selisih);
        $this->assertSame('berbeda', $selisih['super_admin']['jenis']);
        $this->assertContains('master.data', $selisih['super_admin']['rincian']['izin']['hilang']);
    }

    public function test_pemulihan_mengembalikan_izin_puncak_yang_hilang(): void
    {
        RoleKonfigurasi::sinkronkan();

        $peta = Izin::peta();
        $peta['master.data'] = array_values(array_diff($peta['master.data'], ['super_admin']));
        config(['izin.izin' => $peta]);

        $this->assertNotContains('super_admin', Izin::peran('master.data'));

        $dipulihkan = RoleKonfigurasi::pulihkan();

        $this->assertArrayHasKey('super_admin', $dipulihkan);
        $this->assertContains('master.data', $dipulihkan['super_admin']);
        $this->assertContains('super_admin', Izin::peran('master.data'), 'Izin tidak kembali setelah pemulihan.');
    }

    public function test_pemulihan_tidak_pernah_mencabut_izin_baru(): void
    {
        RoleKonfigurasi::sinkronkan();

        // Izin baru pada config yang BELUM ada di snapshot tidak boleh hilang.
        $peta = Izin::peta();
        $peta['master.data'][] = 'dps';
        config(['izin.izin' => $peta]);

        RoleKonfigurasi::pulihkan();

        $this->assertContains('dps', Izin::peran('master.data'));
        $this->assertContains('super_admin', Izin::peran('master.data'));
    }

    // ───────────────────────────────────────────────────────────────
    // 4. PERINTAH & PELINDUNG PUNCAK
    // ───────────────────────────────────────────────────────────────
    public function test_perintah_sinkronkan_dan_cek_selaras(): void
    {
        $this->artisan('role:sinkronkan')->assertExitCode(0);
        $this->artisan('role:sinkronkan', ['--cek' => true])->assertExitCode(0);
        $this->artisan('role:sinkronkan', ['--paksa' => true])->assertExitCode(0);

        $this->assertSame([], RoleKonfigurasi::selisih());
    }

    public function test_perintah_cek_gagal_bila_config_menyimpang(): void
    {
        $this->artisan('role:sinkronkan')->assertExitCode(0);

        $peta = Izin::peta();
        $peta['master.data'] = array_values(array_diff($peta['master.data'], ['super_admin']));
        config(['izin.izin' => $peta]);

        $this->artisan('role:sinkronkan', ['--cek' => true])->assertExitCode(1);
    }

    public function test_perintah_pulihkan_menulis_jejak_audit(): void
    {
        $this->artisan('role:sinkronkan')->assertExitCode(0);

        $peta = Izin::peta();
        $peta['master.data'] = array_values(array_diff($peta['master.data'], ['super_admin']));
        config(['izin.izin' => $peta]);

        $sebelum = AuditLog::query()->count();

        $this->artisan('role:pulihkan')->assertExitCode(0);

        $this->assertSame($sebelum + 1, AuditLog::query()->count());
        $this->assertTrue(
            AuditLog::query()
                ->where('jenis_transaksi', 'role.pulihkan')
                ->exists(),
            'Pemulihan hak akses wajib tercatat di audit log.'
        );
    }

    public function test_baris_puncak_tidak_dapat_disunting_pihak_lain(): void
    {
        RoleKonfigurasi::sinkronkan();

        $this->actingAs($this->pengguna('pengurus', 'kunci.pengurus.snapshot@koperasi.test'));

        $baris = RoleKonfigurasi::query()->where('role', 'super_admin')->firstOrFail();
        $baris->izin = [];

        $this->expectException(\RuntimeException::class);

        $baris->save();
    }

    public function test_super_admin_dapat_menyinkronkan_ulang_barisnya(): void
    {
        RoleKonfigurasi::sinkronkan();

        $this->actingAs($this->pengguna('super_admin', 'kunci.superadmin.snapshot@koperasi.test'));

        $hasil = RoleKonfigurasi::sinkronkan(true);

        $this->assertSame('tetap', $hasil['super_admin']);
        $this->assertNotEmpty(
            RoleKonfigurasi::query()->where('role', 'super_admin')->firstOrFail()->izin
        );
    }
}
