<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LimitTransaksiRole;
use App\Models\User;
use App\Services\NavigationService;
use App\Support\HierarkiRole;
use App\Support\Izin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * LANGKAH #3 — hak akses Super Admin tidak dapat dicabut pihak lain.
 *
 * Pemilik sistem meminta validasi yang mencegah perubahan/pencabutan hak
 * akses `super_admin` oleh role mana pun SELAIN dirinya sendiri. Yang diuji:
 *
 *   1. Aturan pusat `HierarkiRole::bolehMengubahPeran()` — satu-satunya
 *      sumber kebenaran, dipakai model, controller, dan view.
 *   2. Model User: peran puncak tidak dapat diubah/dicabut oleh siapa pun
 *      (termasuk jalur mass-assignment dari halaman Pengaturan/seeder).
 *   3. Halaman "Kelola Pengguna & Role": form ubah peran tidak dapat
 *      menurunkan super_admin; daftar peran yang ditawarkan tidak melemahkan.
 *   4. Override limit `super_admin` tidak dapat dibatasi pihak lain.
 *   5. Super Admin sendiri TETAP dapat mengelola pengguna lain, dan setiap
 *      perubahannya tercatat di audit log.
 */
class SuperAdminHakAksesTerkunciTest extends TestCase
{
    use DatabaseTransactions;

    private function pengguna(string $peran, string $email): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Uji '.$peran,
                'password' => Hash::make('secret'),
                'role' => $peran,
                'is_active' => true,
            ]
        );
    }

    private function superAdmin(): User
    {
        return $this->pengguna('super_admin', 'kunci.superadmin@koperasi.test');
    }

    // ───────────────────────────────────────────────────────────────
    // 1. ATURAN PUSAT
    // ───────────────────────────────────────────────────────────────
    public function test_aturan_pusat_hanya_puncak_boleh_mengubah_puncak(): void
    {
        $this->assertSame('super_admin', HierarkiRole::puncak());
        $this->assertTrue(HierarkiRole::adalahPuncak('super_admin'));

        foreach (['pengurus', 'manajer', 'admin', 'teller', 'ao', 'dps', 'anggota', 'nasabah'] as $peran) {
            $this->assertFalse(
                HierarkiRole::bolehMengubahPeran($peran, 'super_admin'),
                "Peran '{$peran}' TIDAK boleh mengubah hak akses super_admin."
            );
        }

        $this->assertTrue(
            HierarkiRole::bolehMengubahPeran('super_admin', 'super_admin'),
            'Super Admin sendiri tetap boleh mengelola hak aksesnya (tidak mengunci diri selamanya).'
        );

        // Kunci ini tidak melumpuhkan pengelolaan pengguna biasa.
        foreach (['pengurus', 'manajer', 'admin'] as $aktor) {
            $this->assertTrue(
                HierarkiRole::bolehMengubahPeran($aktor, 'teller'),
                "Peran '{$aktor}' tetap boleh mengatur peran non-puncak."
            );
        }
    }

    public function test_super_admin_berada_di_level_tertinggi(): void
    {
        $levelPuncak = HierarkiRole::level('super_admin');

        foreach (HierarkiRole::peran() as $peran) {
            if ($peran === 'super_admin') {
                continue;
            }

            $this->assertLessThan(
                $levelPuncak,
                HierarkiRole::level($peran),
                "Level peran '{$peran}' harus di bawah super_admin."
            );
        }
    }

    /** Langkah #1 — tidak satu pun izin modul tertinggal dari Super Admin. */
    public function test_seluruh_izin_modul_memuat_super_admin_kecuali_yang_disengaja(): void
    {
        // Pengecualian yang DISENGAJA (keputusan pemilik sistem): persetujuan,
        // audit independen, dasbor pribadi, dan layanan mandiri anggota.
        $dikecualikan = [
            'dasbor.teller', 'dasbor.ao', 'dasbor.manajer', 'dasbor.pengurus',
            'dps.pengawasan', 'laporan.audit',
            'approval.transaksi', 'pembiayaan.approve', 'pembiayaan.persetujuan',
            'terminasi.approval',
            'nasabah.swalayan',
            // `deposito.pencairan_dini` sengaja hanya `teller`: pencairan dini
            // mengeluarkan dana laci. Sejalan dengan `pewaris_puncak_kecuali`
            // pada config/role_hierarki.php dan Api\DepositoController.
            'deposito.pencairan_dini',
        ];

        foreach (Izin::peta() as $nama => $peran) {
            if (in_array($nama, $dikecualikan, true)) {
                continue;
            }

            $this->assertContains(
                'super_admin',
                array_map('strval', (array) $peran),
                "Izin modul '{$nama}' belum memuat super_admin."
            );
        }
    }

    /** Tiap izin yang dipegang Super Admin harus dapat ditemukan dari menunya. */
    public function test_setiap_izin_modul_super_admin_punya_minimal_satu_menu(): void
    {
        // Beberapa izin memang menempel pada rute BERAJA (mis. `anggota.edit`
        // pada `/teller/anggota/{id}/edit`), sehingga tidak dapat ditautkan
        // sebagai menu. Izin seperti itu dianggap terjangkau bila daftar
        // anggotanya (`teller.anggota.index`, izin `teller.loket`) tersedia di
        // menu — aksi dapat dicapai satu klik dari halaman induknya.
        //
        // `ckpn.posting` hanya menempel pada satu rute ACTION
        // (`POST /laporan/ckpn/posting`) — POST tidak dapat dijadikan menu,
        // dan tombolnya berada di halaman `laporan.ckpn` (izin `ckpn.lihat`).
        //
        // `terminasi.eksekusi` hanya menempel pada aksi POST
        // (`/member-terminations/{id}/eksekusi|tolak|batalkan`); halaman
        // daftar yang menampilkan tombolnya dijaga `terminasi.pengajuan`.
        //
        // `buku_besar.tulis` (K-1) hanya menempel pada grup TULIS routes/api.php
        // (`/api/jurnal`, `/api/rekening`, `/api/transaksi`, ...). Izin ini
        // sengaja memuat super_admin agar peran teknis tertinggi tidak terkunci
        // dari operasi tulis; halaman baca buku besar di menu dijaga
        // `laporan.buku_besar`.
        //
        // `pembiayaan.kontrak` (K-1) hanya menempel pada grup TULIS pembiayaan
        // routes/api.php. Super Admin tidak memegang `pembiayaan.approve`
        // (persetujuan sengaja dikecualikan dari peran teknis ini), melainkan
        // `manajer.operasional` — menu "Antrean Approval Pembiayaan" yang
        // memuat berkas akad pembiayaan.
        //
        // `kas.loket.eksekusi` (K-2) hanya menempel pada grup TULIS
        // /api/deposito (pembukaan deposito), yang sengaja dipersempit ke
        // pemegang laci. Aksi yang sama tersedia di WEB pada formulir
        // `teller.deposito` (izin `teller.loket`), sehingga Super Admin
        // mencapainya lewat menu "Deposito — Form".
        $terjangkauLewatInduk = [
            'anggota.edit' => 'teller.loket',
            'ckpn.posting' => 'ckpn.lihat',
            'terminasi.eksekusi' => 'terminasi.pengajuan',
            'buku_besar.tulis' => 'laporan.buku_besar',
            'pembiayaan.kontrak' => 'manajer.operasional',
            'kas.loket.eksekusi' => 'teller.loket',
            // `api.baca.operasional` hanya menempel pada grup BACA routes/api.php
            // (anggota, rekening, transaksi, pembiayaan, angsuran, jurnal, akun,
            // deposito). Padanan halamannya di menu dijaga `laporan.buku_besar`.
            'api.baca.operasional' => 'laporan.buku_besar',
            // `deposito.cetak_bilyet` hanya menempel pada GET
            // /api/deposito/{id}/print-bilyet. Tombol cetak tersedia pada menu
            // Deposito loket (`teller.loket`).
            'deposito.cetak_bilyet' => 'teller.loket',
        ];

        $terjangkau = [];

        foreach (NavigationService::getMenu('super_admin') as $seksi) {
            foreach ($seksi['children'] ?? [] as $anak) {
                $rute = $anak['route'] ?? null;

                if (! is_string($rute) || ! Route::has($rute)) {
                    continue;
                }

                foreach (Route::getRoutes()->getByName($rute)->gatherMiddleware() as $mw) {
                    if (is_string($mw) && str_starts_with($mw, 'izin:')) {
                        $terjangkau = array_merge(
                            $terjangkau,
                            array_map('trim', explode(',', substr($mw, 5)))
                        );
                    }
                }
            }
        }

        $terjangkau = array_values(array_unique($terjangkau));

        foreach (Izin::peta() as $nama => $peran) {
            if (! in_array('super_admin', array_map('strval', (array) $peran), true)) {
                continue;
            }

            $induk = $terjangkauLewatInduk[$nama] ?? null;

            $this->assertTrue(
                in_array($nama, $terjangkau, true)
                    || ($induk !== null && in_array($induk, $terjangkau, true)),
                "Izin '{$nama}' dipegang Super Admin tetapi tidak ada menu yang membukanya."
            );
        }
    }

    // ───────────────────────────────────────────────────────────────
    // 2. MODEL USER — peran puncak tidak dapat dicabut
    // ───────────────────────────────────────────────────────────────
    public function test_user_super_admin_tidak_dapat_diturunkan_perannya(): void
    {
        $superAdmin = $this->superAdmin();

        $this->expectException(\RuntimeException::class);

        $superAdmin->update(['role' => 'teller']);
    }

    public function test_peran_puncak_idempoten_bagi_seeder_dan_super_admin_sendiri(): void
    {
        $superAdmin = $this->superAdmin();

        // Seeder/command (tanpa sesi) menyimpan ulang NILAI YANG SAMA → sah.
        $superAdmin->role = 'super_admin';
        $superAdmin->save();
        $this->assertSame('super_admin', $superAdmin->fresh()->role);

        // Super Admin sendiri juga tetap dapat menyimpan dirinya.
        $this->actingAs($superAdmin);
        $superAdmin->role = 'super_admin';
        $superAdmin->save();
        $this->assertSame('super_admin', $superAdmin->fresh()->role);
    }

    public function test_seeder_tidak_dapat_mencabut_peran_puncak_tanpa_sesi(): void
    {
        $superAdmin = $this->superAdmin();

        $this->expectException(\RuntimeException::class);

        $superAdmin->role = 'anggota';
        $superAdmin->save();
    }

    public function test_peran_non_puncak_tetap_dapat_diubah(): void
    {
        $teller = $this->pengguna('teller', 'kunci.teller@koperasi.test');
        $pengurus = $this->pengguna('pengurus', 'kunci.pengurus@koperasi.test');
        $this->actingAs($pengurus);

        $teller->update(['role' => 'ao']);

        $this->assertSame('ao', $teller->fresh()->role);
    }

    // ───────────────────────────────────────────────────────────────
    // 3. HALAMAN KELOLA PENGGUNA & ROLE
    // ───────────────────────────────────────────────────────────────
    public function test_pengurus_ditolak_mengubah_peran_super_admin_lewat_halaman(): void
    {
        $pengurus = $this->pengguna('pengurus', 'kunci.pengurus2@koperasi.test');
        $superAdmin = $this->superAdmin();

        $respons = $this->actingAs($pengurus)->put('/pengurus/users/'.$superAdmin->id.'/peran', [
            'role' => 'teller',
        ]);

        $respons->assertStatus(403);
        $this->assertSame('super_admin', $superAdmin->fresh()->role);
    }

    public function test_super_admin_dapat_mengubah_peran_pengguna_lain_lewat_halaman(): void
    {
        $superAdmin = $this->superAdmin();
        $teller = $this->pengguna('teller', 'kunci.teller3@koperasi.test');

        $respons = $this->actingAs($superAdmin)->put('/pengurus/users/'.$teller->id.'/peran', [
            'role' => 'ao',
        ]);

        $respons->assertRedirect('/pengurus/users');
        $this->assertSame('ao', $teller->fresh()->role);

        // Perubahan itu WAJIB tercatat (langkah #5 — audit keamanan).
        $baris = AuditLog::query()
            ->where('user_id', $superAdmin->id)
            ->where('jenis_transaksi', 'role.ubah')
            ->latest('id')
            ->first();

        $this->assertNotNull($baris, 'Perubahan peran pengguna harus tercatat di audit log.');
        $this->assertStringContainsString('kunci.teller3@koperasi.test', (string) $baris->keterangan);
        $this->assertStringContainsString('Teller', (string) $baris->keterangan);
        $this->assertStringContainsString('Account Officer', (string) $baris->keterangan);
    }

    public function test_daftar_peran_pada_halaman_users_tidak_melemahkan_saat_dibuka_super_admin(): void
    {
        $superAdmin = $this->superAdmin();

        $respons = $this->actingAs($superAdmin)->get('/pengurus/users');
        $respons->assertOk();

        $this->assertContains(
            'super_admin',
            $respons->viewData('peranTersedia'),
            'Super Admin harus dapat mengelola seluruh peran, termasuk super_admin.'
        );
    }

    // ───────────────────────────────────────────────────────────────
    // 4. OVERRIDE LIMIT — puncak tidak dapat dibatasi pihak lain
    // ───────────────────────────────────────────────────────────────
    public function test_super_admin_tidak_dapat_dibatasi_limit_oleh_pihak_lain(): void
    {
        $pihakLain = $this->pengguna('pengurus', 'kunci.pengurus5@koperasi.test');
        $this->actingAs($pihakLain);

        // MODEL: penjaga akhir, tidak bergantung pada lapisan HTTP mana pun.
        $this->expectException(\RuntimeException::class);

        LimitTransaksiRole::create([
            'role' => 'super_admin',
            'jenis_transaksi' => 'setor',
            'limit_per_transaksi' => 1,
            'limit_harian' => 1,
            'butuh_approval' => true,
        ]);
    }

    public function test_super_admin_tetap_dapat_membatasi_dirinya_sendiri(): void
    {
        $superAdmin = $this->superAdmin();
        $this->actingAs($superAdmin);

        $baris = LimitTransaksiRole::create([
            'role' => 'super_admin',
            'jenis_transaksi' => 'setor',
            'limit_per_transaksi' => 1000,
            'limit_harian' => 2000,
            'butuh_approval' => false,
        ]);

        $this->assertDatabaseHas('limit_transaksi_role', [
            'id' => $baris->id,
            'role' => 'super_admin',
        ]);
    }
}
