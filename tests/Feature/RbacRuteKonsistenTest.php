<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\HierarkiRole;
use App\Support\Izin;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * INVARIANT KONSISTENSI RBAC — DYN-16.
 *
 * Middleware `role:` dan `izin:` baru melempar exception saat RUTE DIAKSES.
 * Artinya salah ketik nama peran/izin dapat lolos seluruh suite bila rute
 * tersebut tidak pernah dibuka pengujian lain, dan di produksi tampil sebagai
 * 403 yang menyerupai masalah hak akses. Uji ini mengunci dua lapis:
 *
 *  1. Setiap `role:` pada seluruh rute terdaftar di `HierarkiRole::peran()`,
 *     dan tidak ada lagi rute yang memakai gerbang peran literal (seluruhnya
 *     memakai izin bernama `izin:`).
 *  2. Perintah `rbac:periksa` melaporkan NOL temuan pada konfigurasi & rute
 *     (termasuk peta izin, konfigurasi hierarki, rute dasbor peran, dan rute
 *     pengalihan ramah).
 *  3. Penjaga wewenang DI DALAM controller membaca izin bernama, bukan
 *     membandingkan level peran — perbandingan level meloloskan peran
 *     berlevel tinggi yang tidak berwenang (mis. `dps` level 60).
 *
 * `IzinRuteTest` (DYN-18) sudah menjaga sisi `izin:` pada rute; di sini fokus
 * pada `role:` yang belum tercakup, plus gerbang command yang menyeluruh.
 */
class RbacRuteKonsistenTest extends TestCase
{
    /**
     * DYN-18 — migrasi tuntas: tidak ada rute yang masih menyebut peran
     * literal. Gerbang rute wajib memakai izin bernama (`izin:`) agar satu
     * sumber kebenaran tetap `config/izin.php`.
     *
     * Bila kelak `role:` dipakai kembali, nama perannya tetap harus sah
     * menurut `config/role_hierarki.php` — karena itu pemeriksaan nama
     * dijalankan sebelum rute literal ditolak.
     */
    public function test_rute_tidak_lagi_memakai_gerbang_peran_literal(): void
    {
        $tersisa = [];

        foreach (Route::getRoutes() as $rute) {
            foreach ($rute->gatherMiddleware() as $mw) {
                if (! is_string($mw) || ! str_starts_with($mw, 'role:')) {
                    continue;
                }

                $tersisa[] = $rute->uri().' → '.$mw;

                foreach (explode(',', substr($mw, strlen('role:'))) as $peran) {
                    $peran = trim($peran);

                    if ($peran !== '') {
                        $this->assertTrue(
                            HierarkiRole::dikenal($peran),
                            "Rute memakai peran '{$peran}' yang tidak terdaftar di config/role_hierarki.php."
                        );
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $tersisa,
            "Rute masih memakai peran literal:\n".implode("\n", $tersisa)
        );
    }

    public function test_command_rbac_periksa_tidak_menemukan_temuan(): void
    {
        $this->artisan('rbac:periksa')
            ->expectsOutputToContain('Seluruh nama peran & izin pada rute dan konfigurasi konsisten dengan SSOT RBAC.')
            ->assertExitCode(0);
    }

    public function test_command_rbac_periksa_gagal_bila_izin_tak_terdaftar(): void
    {
        $peta = Izin::peta();
        $peta['uji.salah_ketik'] = ['peran_yang_tidak_ada'];

        config(['izin.izin' => $peta]);

        $this->artisan('rbac:periksa')
            ->expectsOutputToContain('IZIN_PERAN_TAK_DIKENAL — 1 temuan')
            ->assertExitCode(1);
    }

    public function test_command_rbac_periksa_gagal_bila_rute_menunjuk_peran_tak_dikenal(): void
    {
        Route::middleware('role:manajerx')->get('/uji-rbac-salah-ketik', fn () => 'ok');

        $this->artisan('rbac:periksa')
            ->expectsOutputToContain('ROUTE_ROLE_TAK_DIKENAL — 1 temuan')
            ->assertExitCode(1);
    }

    /**
     * 5g — `POST /logout` mengakhiri sesi, sehingga wajib hanya untuk pengguna
     * yang benar-benar sedang masuk. Tanpa `auth`, permintaan tamu tetap
     * dieksekusi: sesi diinvalidasi dan token CSRF diganti — jalur yang tidak
     * masuk akal untuk pengguna anonim dan memperluas permukaan serangan.
     */
    public function test_rute_logout_wajib_terautentikasi(): void
    {
        $rute = Route::getRoutes()->getByName('logout');

        $this->assertNotNull($rute, 'Rute `logout` harus terdaftar.');
        $this->assertContains(
            'auth',
            $rute->gatherMiddleware(),
            'POST /logout belum memakai middleware `auth`.'
        );
    }

    /**
     * 5g — rute pengajuan rekening API adalah jalur TULIS yang dipanggil klien
     * mobile (termasuk unggah bukti bayar). Tanpa `throttle:`, satu token dapat
     * membanjiri antrean verifikasi maupun penyimpanan berkas.
     *
     * Padanan web (`/buka-rekening`) sudah dibatasi 30/menit untuk GET dan
     * 10/menit untuk POST; di sini batasnya diperiksa ada, bukan nilainya.
     */
    public function test_rute_api_pengajuan_rekening_memuat_pembatas_laju(): void
    {
        $wajib = [
            'api/pengajuan-rekening',
            'api/pengajuan-rekening/status',
            'api/pengajuan-rekening/bukti-bayar',
        ];

        $diperiksa = [];

        foreach (Route::getRoutes() as $rute) {
            if (! in_array($rute->uri(), $wajib, true)) {
                continue;
            }

            $diperiksa[] = $rute->uri();

            $pembatas = array_filter(
                $rute->gatherMiddleware(),
                fn ($mw): bool => is_string($mw) && str_starts_with($mw, 'throttle:')
            );

            $this->assertNotEmpty(
                $pembatas,
                'Rute '.implode('|', $rute->methods()).' /'.$rute->uri().' belum memuat middleware `throttle:`.'
            );
        }

        $this->assertCount(count($wajib), $diperiksa, 'Seluruh rute pengajuan rekening API harus diperiksa.');
    }

    /**
     * 5h — form pengajuan hanya dirender untuk pemegang
     * `pembiayaan.pengajuan.masukan` (AO/Manajer/Super Admin), tetapi
     * `POST /pengajuan-pembiayaan` masih memakai gerbang grup
     * `izin:pembiayaan.pengajuan` yang juga memuat `pengurus`.
     *
     * Menyembunyikan form bukan pengaman: pengurus tetap dapat memanggil rute
     * simpan langsung. Gembok tulis harus mengikuti izin yang sama dengan
     * penampil form.
     */
    public function test_pengurus_tidak_dapat_menyimpan_pengajuan_pembiayaan(): void
    {
        $this->actingAs(new User(['role' => 'pengurus']))
            ->post(route('pengajuan-pembiayaan.store'), [])
            ->assertForbidden();
    }

    /**
     * Sisi sebaliknya: pembatas tulis tidak boleh mengunci AO. Tanpa payload
     * sah, AO tetap sampai ke controller dan ditolak VALIDASI (302), bukan 403.
     */
    public function test_ao_tetap_dapat_menyimpan_pengajuan_pembiayaan(): void
    {
        $this->actingAs(new User(['role' => 'ao']))
            ->post(route('pengajuan-pembiayaan.store'), [])
            ->assertStatus(302);
    }

    /**
     * DYN-18 lanjutan — penjaga wewenang di dalam controller tidak boleh
     * membandingkan LEVEL peran.
     *
     * `HierarkiRole::level($peran) >= level('manajer')` terbaca "minimal
     * Manajer", padahal hierarki level hanyalah peringkat organisasi: `dps`
     * (level 60) lolos perbandingan itu tanpa pernah diberi wewenang pada
     * modul yang dijaganya. Wewenang harus dibaca dari `config/izin.php`.
     */
    public function test_controller_tidak_memakai_perbandingan_level_peran(): void
    {
        $pelanggaran = [];

        // `glob()` tidak menelusuri subfolder; berkas dijaga banyak controller
        // berarsitektur `Controllers/Dashboard/*`, jadi pakai iterator rekursif.
        $berkas = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Http/Controllers'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($berkas as $satu) {
            if ($satu->getExtension() !== 'php') {
                continue;
            }

            $isi = (string) file_get_contents($satu->getPathname());

            if (preg_match('/HierarkiRole::level\([^)]*\)\s*(>=|>|<|<=)/', $isi, $cocok, PREG_OFFSET_CAPTURE)) {
                $baris = substr_count(substr($isi, 0, $cocok[0][1]), "\n") + 1;
                $pelanggaran[] = str_replace(app_path().DIRECTORY_SEPARATOR, '', $satu->getPathname()).':'.$baris;
            }
        }

        $this->assertSame(
            [],
            $pelanggaran,
            "Controller masih membandingkan level peran (pakai Izin::peran()):\n".implode("\n", $pelanggaran)
        );
    }

    /**
     * Gembok perilaku: peran yang memegang izin modul memperoleh wewenang
     * penjaga controller, dan peran berlevel tinggi yang TIDAK memegang izin
     * modul itu tidak memperolehnya.
     *
     * `dps` berlevel 60 (di atas `manajer` 40) tetapi bukan pemegang
     * `kebijakan.koperasi` maupun `simpanan.jenis`.
     */
    public function test_penjaga_controller_hanya_meloloskan_pemegang_izin_modul(): void
    {
        $this->assertContains('pengurus', Izin::peran('kebijakan.koperasi'));
        $this->assertNotContains('dps', Izin::peran('kebijakan.koperasi'));
        $this->assertContains('super_admin', Izin::peran('simpanan.jenis'));
        $this->assertNotContains('dps', Izin::peran('simpanan.jenis'));

        $this->assertGreaterThan(
            HierarkiRole::level('manajer'),
            HierarkiRole::level('dps'),
            'Prasyarat uji: dps harus berlevel di atas manajer agar perbandingan level benar-benar keliru.'
        );
    }

    /**
     * Penjaga wewenang di dalam controller diperiksa langsung lewat refleksi,
     * karena gerbang rute sudah menolak lebih dulu sehingga salahnya sumber
     * wewenang tidak akan terlihat dari respons HTTP.
     *
     *   - Kalender hari libur (`izin:kebijakan.koperasi`): pengurus lolos,
     *     `dps` (level 60, lebih tinggi dari manajer 40) DITOLAK.
     *   - Auto-debit jenis simpanan (`izin:simpanan.jenis`): super_admin lolos,
     *     manajer DITOLAK — dan penolakannya adalah soal KEWENANGAN, bukan
     *     soal kode produk.
     */
    public function test_penjaga_wewenang_controller_mengikuti_izin_modul(): void
    {
        $hariLibur = new \App\Http\Controllers\HariLiburController;
        $bolehUbah = new \ReflectionMethod($hariLibur, 'bolehUbah');
        $bolehUbah->setAccessible(true);

        foreach (['pengurus' => true, 'super_admin' => true, 'dps' => false, 'manajer' => false] as $peran => $diharapkan) {
            $this->actingAs(new User(['role' => $peran]));

            $this->assertSame(
                $diharapkan,
                $bolehUbah->invoke($hariLibur),
                "Penjaga kalender hari libur keliru untuk peran '{$peran}'."
            );
        }

        $jenisSimpanan = new \App\Http\Controllers\JenisSimpananController;
        $gembok = new \ReflectionMethod($jenisSimpanan, 'pastikanAllowAutoDebitSah');
        $gembok->setAccessible(true);

        $this->actingAs(new User(['role' => 'manajer']));

        try {
            $gembok->invoke($jenisSimpanan, true, \App\Models\JenisSimpanan::CODE_WAJIB, null);
            $this->fail('Manajer tidak memegang `simpanan.jenis` sehingga harus ditolak.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString(
                'berwenang mengaktifkan auto-debit',
                (string) ($e->errors()['allow_auto_debit'][0] ?? '')
            );
        }

        $this->actingAs(new User(['role' => 'super_admin']));
        $gembok->invoke($jenisSimpanan, true, \App\Models\JenisSimpanan::CODE_WAJIB, null);
        $this->addToAssertionCount(1);
    }
}
