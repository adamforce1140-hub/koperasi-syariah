<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\User;
use App\Services\NavigationService;
use App\Support\Izin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * NAVIGASI ⇄ WEWENANG — anti-403 untuk SEMUA peran internal.
 *
 * Akar masalah yang dijaga berkas ini: menu sidebar dapat memuat tautan ke
 * rute yang justru dijaga peran lain. Pengurus pernah mengalami ini —
 * menuPengurus() memuat `dps.zakat.index`/`dps.zakat.laporan` yang ber-guard
 * `role:dps`, sehingga setiap klik berakhir 403 "Akses Ditolak".
 *
 * Karena itu berkas ini mengunci satu INVARIANT yang berlaku untuk seluruh
 * peran dan seluruh menu:
 *
 *   Setiap rute GET yang ditawarkan menu sidebar kepada peran X WAJIB
 *   benar-benar boleh diakses oleh peran X  →  middleware `role:`
 *   pada rute tersebut harus memuat X.
 *
 * Invariant dibaca langsung dari sumber kebenaran, bukan dari daftar yang
 * dipatok ulang, sehingga menu/route baru otomatis ikut diuji:
 *   - Menu         : NavigationService::getMenu($peran)
 *   - Daftar rute  : RouteFacade::getRoutes()
 *   - Guard        : middleware `role:` pada definisi rute
 *
 * Test tidak melonggarkan kode produksi; ia hanya memastikan janji wewenang
 * yang sudah dinyatakan di rute tidak dikhianati oleh tautan antarmuka.
 */
class NavigasiWewenangPeranTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * SEMUA peran yang punya menu sidebar.
     *
     * `anggota` (swalayan) WAJIB ikut daftar: nilai itulah yang tersimpan di
     * `users.role` dan yang dipakai `WebAuthController::redirectByRole()`.
     * Versi lama mengabaikannya dengan alasan "anggota tidak lagi masuk
     * aplikasi" — premise itu salah; justru karena inilah role-nya hilang dari
     * navigasi (lihat NavigationService::getMenu).
     */
    private const PERAN = ['teller', 'ao', 'manajer', 'pengurus', 'dps', 'anggota', 'admin', 'super_admin'];

    /**
     * Folder view yang menjadi "wilayah" sebuah peran. Dipakai test §4 untuk
     * memindai tautan silang di dalam halaman peran tersebut.
     */
    private const FOLDER_PERAN = [
        'teller' => ['teller'],
        'ao' => ['ao'],
        'manajer' => ['manajer', 'pengelola'],

        // `pengurus/` TIDAK dipindai lewat glob penuh. Folder itu memuat
        // campuran halaman yang dikelola DUA peran berbeda:
        //   · Halaman KEWENANGAN Pengurus (guard `role:pengurus`) —
        //     mis. laporan, kas, cif, aml, approval, kebijakan.
        //   · Halaman KONFIGURASI Super Admin (guard `role:super_admin`) —
        //     mis. coa, produk/produk-edit, users, jenis-simpanan,
        //     riwayat-pengujian.
        // Memindai folder penuh akan melaporkan tautan lintas domain (mis.
        // tautan `pengurus.coa.*` di coa.blade.php — domain Super Admin) sebagai
        // pelanggaran palsu untuk Pengurus. Karena itu wilayah scan dipersempit
        // ke berkas yang benar-benar dirender salah satu peran (lihat daftar
        // berkas di bawah); lebih aman menambah berkas saat dibuat daripada
        // memindai folder campuran.
        'pengurus' => [
            'pengurus/approval.blade.php',
            'pengurus/pembiayaan.blade.php',
            'pengurus/pembiayaan-show.blade.php',
            'pengurus/shu/index.blade.php',
            'pengurus/shu/detail.blade.php',
            'pengurus/pengesahan-shu.blade.php',
            'pengurus/laporan.blade.php',
            'pengurus/kas.blade.php',
            'pengurus/anggota.blade.php',
            'pengurus/simpanan.blade.php',
            'pengurus/cif/index.blade.php',
            'pengurus/laporan_syariah/index.blade.php',
            'pengurus/laporan/dana-kebajikan.blade.php',
            'pengurus/aml/index.blade.php',
            'pengurus/cadangan.blade.php',
            'pengurus/kebijakan.blade.php',
            'pengurus/hari-libur.blade.php',
        ],

        'dps' => ['dps', 'laporan'],
        'anggota' => ['nasabah'],
        'admin' => ['admin'],

        // `super_admin` TIDAK mewarisi seluruh folder `pengurus/` — ia hanya
        // boleh merender halaman KONFIGURASI/administratif yang rutenya memang
        // ber-guard `role:super_admin`:
        //   pengurus.produk.index            → pengurus/produk.blade.php
        //   pengurus.produk.edit             → pengurus/produk-edit.blade.php
        //   pengurus.users.index             → pengurus/users.blade.php
        //   pengurus.riwayat-pengujian.index → pengurus/riwayat-pengujian.blade.php
        //   pengurus.jenis-simpanan.index    → pengurus/jenis-simpanan.blade.php
        //   admin.settings.index             → pengurus/kebijakan.blade.php (alias UI)
        //   pengurus.coa.index               → pengurus/coa.blade.php
        // CATATAN: `pengurus/kebijakan.blade.php` melayani DUA rute —
        // PengurusController::kebijakan (guard `role:pengurus`) dan
        // AdminSettingsController::index (guard `role:super_admin`). Karena
        // berkas yang SAMA dirender oleh kedua peran, ia dipindai untuk
        // keduanya; tautannya (kebijakan.update / pemetaan-akad) memang
        // dikunjungi dari UI Pengurus, bukan Super Admin, dan sengaja TIDAK
        // dijaga gerbang peran di Blade (form disubmit ke rute Pengurus).
        'super_admin' => [
            'pengurus/produk.blade.php',
            'pengurus/produk-edit.blade.php',
            'pengurus/users.blade.php',
            'pengurus/kebijakan.blade.php',
            'pengurus/riwayat-pengujian.blade.php',
            'pengurus/jenis-simpanan.blade.php',
            'pengurus/coa.blade.php',
        ],
    ];

    private function pengguna(string $peran): User
    {
        $pengguna = User::firstOrCreate(
            ['email' => "nav.{$peran}@example.test"],
            [
                'name' => 'Nav '.ucfirst($peran),
                'password' => Hash::make('secret'),
                'role' => $peran,
                'is_active' => true,
            ]
        );

        // Peran `anggota` tidak cukup hanya punya akun: NasabahController
        // mengambil data lewat `Anggota::where('user_id', Auth::id())` dan
        // `abort_if(!$anggota, 403)` bila tidak ada. Tanpa tautan itu, 403 pada
        // enam halaman swalayan adalah perilaku yang BENAR (isolasi data), bukan
        // tautan rusak — jadi test wajib menyiapkan anggota aktifnya sendiri.
        if ($peran === 'anggota' && ! Anggota::where('user_id', $pengguna->id)->exists()) {
            Anggota::create([
                'user_id' => $pengguna->id,
                'no_anggota' => 'NAV-ANG-'.$pengguna->id,
                'nama_lengkap' => 'Nav Anggota',
                'nik' => str_pad((string) $pengguna->id, 16, '0', STR_PAD_LEFT),
                'jenis_kelamin' => 'Laki-laki',
                'alamat' => 'Jl. Uji Navigasi No. 1',
                'kota' => 'Bandung',
                'provinsi' => 'Jawa Barat',
                'status_anggota' => Anggota::STATUS_ACTIVE,
                'tanggal_gabung' => now()->toDateString(),
            ]);
        }

        return $pengguna;
    }

    /**
     * Kumpulkan SELURUH nama rute daun pada menu sebuah peran
     * (termasuk anak bertingkat sedalam apa pun).
     *
     * @return list<string>
     */
    private function ruteMenu(string $peran): array
    {
        $hasil = [];

        $telusuri = function (array $items) use (&$telusuri, &$hasil): void {
            foreach ($items as $item) {
                if (! empty($item['route'])) {
                    $hasil[] = $item['route'];
                }
                if (! empty($item['children']) && is_array($item['children'])) {
                    $telusuri($item['children']);
                }
            }
        };

        $telusuri(NavigationService::getMenu($peran));

        return array_values(array_unique($hasil));
    }

    /**
     * Daftar peran yang dinyatakan boleh oleh middleware `role:` atau `izin:`
     * pada sebuah rute. Mengembalikan null bila rute tidak dijaga keduanya
     * (mis. hanya bergantung middleware lain) — pemanggil yang memutuskan
     * sikapnya.
     *
     * DYN-18: rute beralih dari `role:` ke `izin:`. `role:` tetap dibaca lebih
     * dulu selama transisi; `izin:` diresolusi lewat SSOT config/izin.php
     * dengan irisan peran (beberapa izin = DAN).
     *
     * @return list<string>|null
     */
    private function peranDiizinkan(LaravelRoute $route): ?array
    {
        $izin = [];

        foreach ($route->gatherMiddleware() as $mw) {
            if (! is_string($mw)) {
                continue;
            }

            if (str_starts_with($mw, 'role:')) {
                return array_values(array_filter(array_map(
                    'trim',
                    explode(',', substr($mw, strlen('role:')))
                )));
            }

            if (str_starts_with($mw, 'izin:')) {
                $izin = array_merge($izin, array_map(
                    'trim',
                    explode(',', substr($mw, strlen('izin:')))
                ));
            }
        }

        if ($izin !== []) {
            return Izin::peranEfektif(array_values(array_filter($izin)));
        }

        return null;
    }

    /* ═════════════════════════════════════════════════════════════════════
     | 1. INVARIANT UTAMA — menu tidak boleh menautkan rute di luar wewenang
     |═════════════════════════════════════════════════════════════════════ */

    public function test_setiap_rute_menu_hanya_menunjuk_rute_yang_diizinkan_peran_itu(): void
    {
        $semua = RouteFacade::getRoutes();
        $pelanggaran = [];

        foreach (self::PERAN as $peran) {
            foreach ($this->ruteMenu($peran) as $nama) {
                $route = $semua->getByName($nama);

                if ($route === null) {
                    $pelanggaran[] = "[{$peran}] rute '{$nama}' TIDAK TERDAFTAR";

                    continue;
                }

                $diizinkan = $this->peranDiizinkan($route);

                // Rute tanpa guard `role:` sengaja dilewati: otorisasinya
                // ditangani middleware lain (mis. anggota.aktif) atau di
                // controller, sehingga tidak dapat dinilai di sini.
                if ($diizinkan === null) {
                    continue;
                }

                if (! in_array($peran, $diizinkan, true)) {
                    $pelanggaran[] = sprintf(
                        "[%s] menu → '%s' (URI: %s) hanya untuk role:%s",
                        $peran,
                        $nama,
                        $route->uri(),
                        implode(',', $diizinkan)
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $pelanggaran,
            "Menu sidebar menautkan rute di luar wewenang peran — klik akan berakhir 403:\n"
                .implode("\n", $pelanggaran)
        );
    }

    /* ═════════════════════════════════════════════════════════════════════
     | 1b. WEWENANG YANG TIDAK DAPAT DITEMUKAN DARI ANTARMUKA (A6)
     |═════════════════════════════════════════════════════════════════════ */

    /**
     * Kebalikan dari test di atas: test itu memastikan menu tidak menjanjikan
     * akses yang tidak dimiliki; test ini memastikan wewenang yang dimiliki
     * benar-benar ditawarkan.
     *
     * Dulu `admin` dan `super_admin` memegang `autodebit.konfirmasi` tetapi
     * entri menu Pusat Konfirmasi hanya ada di sidebar manajer — keduanya
     * berwenang namun hanya bisa masuk dengan mengetik URL langsung.
     */
    public function test_setiap_peran_berwenang_konfirmasi_autodebit_mendapat_menu(): void
    {
        $berwenang = Izin::peran('autodebit.konfirmasi');

        $this->assertNotEmpty($berwenang, 'Izin autodebit.konfirmasi tidak boleh kosong.');

        foreach ($berwenang as $peran) {
            $this->assertContains(
                'autodebit.index',
                $this->ruteMenu($peran),
                "Peran {$peran} berwenang mengonfirmasi auto-debit tetapi tidak menemukan menunya di sidebar."
            );
        }
    }

    /* ═════════════════════════════════════════════════════════════════════
     | 2. REGRESI KHUSUS PENGURUS (bug 403 yang dilaporkan)
     |═════════════════════════════════════════════════════════════════════ */

    public function test_menu_pengurus_tidak_lagi_menautkan_rute_khusus_dps(): void
    {
        $rute = $this->ruteMenu('pengurus');

        $this->assertNotContains(
            'dps.zakat.index',
            $rute,
            'Menu Pengurus tidak boleh menautkan rute ber-guard role:dps — akan berakhir 403.'
        );
        $this->assertNotContains('dps.zakat.laporan', $rute);
    }

    /**
     * PEMISAHAN TUGAS (Segregation of Duties) — Pengurus TIDAK boleh menjalankan
     * transaksi operasional harian, fungsi administrator teknis IT, maupun audit
     * internal. Enam menu berikut dihapus dari sidebar Pengurus DAN hak
     * aksesnya dialihkan ke peran yang tepat.
     *
     * Test ini mengunci DUA hal sekaligus:
     *   1. Menu Pengurus tidak lagi menautkan rutenya (tidak tampil di sidebar).
     *   2. Akses URL langsung dikembalikan 403 Forbidden — bukan sekadar
     *      disembunyikan dari tampilan.
     */
    #[DataProvider('ruteKhususPeranLain')]
    public function test_pengurus_dilarang_mengakses_menu_di_luar_wewenangnya(string $namaRute, string $peranBaru): void
    {
        // 1. Tidak ditawarkan menu Pengurus.
        $this->assertNotContains(
            $namaRute,
            $this->ruteMenu('pengurus'),
            "Menu '{$namaRute}' tidak boleh ditawarkan kepada Pengurus."
        );

        // 2. Guard rute benar-benar dialihkan ke peran baru.
        $route = RouteFacade::getRoutes()->getByName($namaRute);
        $this->assertNotNull($route, "Rute '{$namaRute}' harus tetap terdaftar.");

        $diizinkan = $this->peranDiizinkan($route);
        $this->assertNotNull($diizinkan, "Rute '{$namaRute}' harus dijaga middleware role:.");
        $this->assertNotContains('pengurus', $diizinkan, "Pengurus tidak boleh lagi diizinkan pada '{$namaRute}'.");
        $this->assertContains($peranBaru, $diizinkan, "Rute '{$namaRute}' harus dialihkan ke role:{$peranBaru}.");

        // 3. Akses URL langsung oleh Pengurus → 403 Forbidden.
        $uri = '/'.ltrim($route->uri(), '/');
        $status = $this->actingAs($this->pengguna('pengurus'))->get($uri)->getStatusCode();

        $this->assertSame(
            403,
            $status,
            "Pengurus mengakses '{$uri}' secara langsung harus menerima 403 Forbidden, bukan {$status}."
        );
    }

    /**
     * Daftar rute yang WAJIB dicabut dari Pengurus beserta peran penggantinya.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function ruteKhususPeranLain(): array
    {
        return [
            'Setoran Angsuran → Teller' => ['pengurus.angsuran.index', 'teller'],
            'Pusat Konfirmasi Auto-Debit' => ['autodebit.index', 'manajer'],
            'Audit Laporan Keuangan → DPS' => ['audit.laporan.index', 'dps'],
            'Manajemen User & Hak Akses' => ['pengurus.users.index', 'super_admin'],
            'Riwayat Pengujian' => ['pengurus.riwayat-pengujian.index', 'super_admin'],
            'Mapping COA' => ['pengurus.coa.index', 'super_admin'],
            'Parameter Jenis Simpanan' => ['pengurus.jenis-simpanan.index', 'super_admin'],
            'Kelola Produk & Akad' => ['pengurus.produk.index', 'super_admin'],
        ];
    }

    /**
     * Setiap peran WAJIB bisa membuka seluruh halaman yang ditawarkan menunya.
     *
     * Audit menemukan versi lama hanya menguji `pengurus`, sehingga
     * empat peran lain tidak punya jaminan apa pun — dan `anggota` terbukti
     * benar-benar rusak (menu kosong). Karena itu loop di sini mencakup
     * seluruh PERAN, termasuk `admin`.
     */
    public function test_setiap_peran_dapat_membuka_seluruh_rute_pada_menunya(): void
    {
        $semua = RouteFacade::getRoutes();
        $gagal = [];
        $diperiksa = 0;

        foreach (self::PERAN as $peran) {
            $pengguna = $this->pengguna($peran);

            foreach ($this->ruteMenu($peran) as $nama) {
                $route = $semua->getByName($nama);

                // Hanya rute GET tanpa parameter yang dapat diuji tanpa data;
                // rute ber-parameter/aksi POST tetap dicakup invariant §1.
                if ($route === null || ! in_array('GET', $route->methods(), true)) {
                    continue;
                }
                if (preg_match('/\{[^}]+\}/', $route->uri())) {
                    continue;
                }

                $diperiksa++;
                $status = $this->actingAs($pengguna)->get('/'.ltrim($route->uri(), '/'))->getStatusCode();

                // 403 = tautan menu menunjuk halaman yang memang bukan haknya.
                if ($status === 403) {
                    $gagal[] = sprintf('[%s] %s (%s)', $peran, $nama, $route->uri());
                }
            }
        }

        $this->assertGreaterThan(
            0,
            $diperiksa,
            'Tidak ada rute menu yang teruji — pola pengujian ini sudah tidak berlaku.'
        );

        $this->assertSame(
            [],
            $gagal,
            "Peran masih menerima 403 pada halaman yang ditawarkan menunya:\n"
                .implode("\n", $gagal)
        );
    }

    /* ═════════════════════════════════════════════════════════════════════
     | 3. MENU TIDAK BOLEH KOSONG — penjaga regresi role 'anggota'
     |═════════════════════════════════════════════════════════════════════ */

    /**
     * Setiap peran yang punya dasbor wajib punya entri menu. Role yang jatuh
     * ke `default => []` di getMenu() tetap bisa login dan membuka dasbornya,
     * tetapi sidebar kosong total — kegagalan yang tidak tertangkap test mana pun
     * karena tidak ada yang melecetkan tautan yang rusak (memang tidak ada).
     */
    public function test_tiap_peran_dasar_memiliki_menu_yang_tidak_kosong(): void
    {
        $kosong = [];

        foreach (self::PERAN as $peran) {
            if ($this->ruteMenu($peran) === []) {
                $kosong[] = $peran;
            }
        }

        $this->assertSame(
            [],
            $kosong,
            "Peran berikut tidak mendapat menu sidebar sama sekali:\n".implode("\n", $kosong)
        );
    }

    /* ═════════════════════════════════════════════════════════════════════
     | 4. TAUTAN LINTAS-PERAN DI DALAM TAMPILAN SETIAP PERAN
     |═════════════════════════════════════════════════════════════════════ */

    /**
     * Halaman milik peran X tidak boleh menautkan rute ber-guard peran lain.
     *
     * Audit menemukan versi lama hanya memindai folder `pengurus/`. Kini
     * seluruh wilayah view tiap peran dipindai, termasuk halaman dasbornya.
     *
     * Tautan yang sudah DIJAGA `@if` peran tetap diabaikan. Tanpa itu test
     * melaporkan tiga temuan palsu sekaligus menutupi bug asli: pola yang benar
     * (`@if(auth()->user()->role === 'pengurus')`) dan pola yang salah
     * (`@if(session('success'))` tanpa penjaga peran) sama-sama terbaca sebagai
     * `route('pengurus.coa.index')`. Perbedaan safety-nya justru ada di
     * condition-nya, jadi condition WAJIB ikut diperiksa.
     */
    public function test_tampilan_setiap_peran_tidak_menautkan_rute_ber_guard_peran_lain(): void
    {
        $semua = RouteFacade::getRoutes();
        $pelanggaran = [];

        foreach (self::PERAN as $peran) {
            $berkas = [];

            foreach (self::FOLDER_PERAN[$peran] ?? [] as $wilayah) {
                // Entri boleh berupa NAMA FOLDER (dipindai rekursif) atau
                // JALUR BERKAS relatif (mis. 'pengurus/produk.blade.php') untuk
                // peran yang hanya boleh merender sebagian berkas di dalam
                // sebuah folder. Deteksi lewat ada/tidaknya ekstensi berkas.
                if (str_ends_with($wilayah, '.blade.php')) {
                    $kandidat = resource_path("views/{$wilayah}");

                    if (is_file($kandidat)) {
                        $berkas[] = $kandidat;
                    }

                    continue;
                }

                $berkas = array_merge(
                    $berkas,
                    glob(resource_path("views/{$wilayah}/**/*.blade.php")) ?: [],
                    glob(resource_path("views/{$wilayah}/*.blade.php")) ?: []
                );
            }

            // Dasbor peran ikut dipindai karena tautannya tampil di layar.
            $berkas = array_merge($berkas, glob(resource_path("views/dashboard/{$peran}.blade.php")) ?: []);
            // Wilayah dasbor ber-tanda hubung ('' → '*'): super_admin memakai
            // `dashboard/super-admin*.blade.php` (dasbor + kinerja unit).
            $berkas = array_merge(
                $berkas,
                glob(resource_path('views/dashboard/'.str_replace('_', '-', $peran).'*.blade.php')) ?: []
            );

            foreach (array_unique($berkas) as $path) {
                $isi = (string) file_get_contents($path);

                if (! preg_match_all("/route\(\s*'([a-z0-9_.]+)'/i", $isi, $m, PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                foreach ($m[1] as [$nama, $posisi]) {
                    $route = $semua->getByName($nama);

                    if ($route === null) {
                        $pelanggaran[] = sprintf('[%s] %s → rute %s TIDAK TERDAFTAR', $peran, basename($path), $nama);

                        continue;
                    }

                    $diizinkan = $this->peranDiizinkan($route);

                    if ($diizinkan === null || in_array($peran, $diizinkan, true)) {
                        continue;
                    }

                    if ($this->dijagaGerbangPeran($isi, $posisi)) {
                        continue;
                    }

                    $pelanggaran[] = sprintf(
                        '[%s] %s → %s hanya untuk role:%s',
                        $peran,
                        basename($path),
                        $nama,
                        implode(',', $diizinkan)
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $pelanggaran,
            "Tampilan peran menautkan rute di luar wewenangnya:\n".implode("\n", $pelanggaran)
        );
    }

    /**
     * Apakah kemunculan `route()` pada byte offset tertentu berada di dalam
     * blok Blade bersyarat yang memeriksa wewenang (peran, hak akses laporan,
     * atau pembantu peran pada User).
     *
     * Pendekatan: telusuri blok `@if`/`@elseif`/`@else`/`@endif`/`@can` yang
     * masih terbuka pada posisi itu, lalu lihat apakah salah satu condition-nya
     * bicara tentang wewenang. Condition `@else` sengaja wiping condition
     * sebelumnya — di cabang `@else` sebuah `@if(role === 'pengurus')` justru
     * TIDAK berlaku.
     *
     * Batasan yang diketahui: condition `@if`.multiline tidak ditelusuri,
     * hanya condition satu baris. Semua penjaga peran di proyek ini ditulis
     * satu baris, jadi batas ini tidak mengurangi daya deteksi di sini.
     */
    private function dijagaGerbangPeran(string $isi, int $posisi): bool
    {
        $sebelum = substr($isi, 0, $posisi);
        $tumpukan = [];

        preg_match_all('/@(if|elseif|else|endif|can|endcan)\b([^\n{]*)/', $sebelum, $m, PREG_SET_ORDER);

        foreach ($m as $token) {
            $jenis = $token[1];
            $kondisi = trim($token[2] ?? '');

            if ($jenis === 'if' || $jenis === 'can') {
                $tumpukan[] = $kondisi;

                continue;
            }

            if ($jenis === 'endif' || $jenis === 'endcan') {
                array_pop($tumpukan);

                continue;
            }

            if ($jenis === 'else') {
                if ($tumpukan !== []) {
                    $tumpukan[count($tumpukan) - 1] = '';
                }

                continue;
            }

            // @elseif — cabang ini digantikan condition sebelumnya.
            if ($tumpukan !== []) {
                $tumpukan[count($tumpukan) - 1] = $kondisi;
            }
        }

        foreach ($tumpukan as $kondisi) {
            if ($kondisi !== '' && preg_match(
                '/auth\(\)|\brole\b|boleh|permission|Gate::|HierarkiRole|->is[A-Z]\w*\(|->can[A-Z]\w*\(/i',
                $kondisi
            )) {
                return true;
            }
        }

        return false;
    }
}
