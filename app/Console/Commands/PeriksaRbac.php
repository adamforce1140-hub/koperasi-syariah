<?php

namespace App\Console\Commands;

use App\Support\HierarkiRole;
use App\Support\Izin;
use Illuminate\Console\Command;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/**
 * Pemeriksa konsistensi RBAC — DYN-16.
 *
 * Middleware `role:` dan `izin:` hanya gagal SAAT RUTE DIAKSES. Akibatnya salah
 * ketik nama peran (`role:manajerx`) atau nama izin (`izin:kas.openig`) tidak
 * pernah ketahuan sampai ada pengguna yang membuka halaman itu — dan gejalanya
 * menyerupai masalah hak akses (403), bukan kesalahan konfigurasi. Perintah ini
 * memindai SELURUH definisi rute beserta konfigurasi RBAC sekaligus, sehingga
 * kesalahan tersebut terlihat sebelum rilis.
 *
 * Kategori pemeriksaan:
 *   1. ROUTE_ROLE_TAK_DIKENAL    — argumen `role:` tidak ada di
 *      `config/role_hierarki.php` (HierarkiRole::dikenal()).
 *   2. ROUTE_IZIN_TAK_TERDAFTAR  — argumen `izin:` tidak ada di
 *      `config/izin.php` (Izin::ada()).
 *   3. IZIN_PERAN_TAK_DIKENAL    — peta izin memuat peran yang tidak dikenal,
 *      atau memetakan peran kosong (rute selalu 403).
 *   4. KONFIG_PERAN_TAK_DIKENAL  — nama peran pada rantai/batas/kelompok/
 *      frekuensi/akses_data/izin_dokumen/kelompok_tolak tidak dikenal.
 *   5. DASBOR_TAK_TERDAFTAR      — `peran.*.dashboard_route` menunjuk rute
 *      yang tidak ada.
 *   6. PENGALIHAN_TAK_TERDAFTAR  — `kelompok_tolak.*.*.route` menunjuk rute
 *      yang tidak ada.
 *
 * Perintah ini hanya MEMBACA konfigurasi dan definisi rute — tidak mengubah
 * apa pun. Exit code FAILURE dikembalikan bila ada temuan, sehingga dapat
 * dipakai sebagai gerbang pada pipeline rilis.
 */
class PeriksaRbac extends Command
{
    protected $signature = 'rbac:periksa
                            {--contoh=10 : Jumlah baris contoh yang ditampilkan per kategori}';

    protected $description = 'Periksa konsistensi nama peran & izin pada rute dan konfigurasi terhadap SSOT RBAC.';

    public function handle(): int
    {
        $batasContoh = max(0, (int) $this->option('contoh'));

        $kategori = [
            'ROUTE_ROLE_TAK_DIKENAL' => $this->roleRuteTakDikenal(),
            'ROUTE_IZIN_TAK_TERDAFTAR' => $this->izinRuteTakTerdaftar(),
            'IZIN_PERAN_TAK_DIKENAL' => $this->izinPeranTakDikenal(),
            'KONFIG_PERAN_TAK_DIKENAL' => $this->konfigPeranTakDikenal(),
            'DASBOR_TAK_TERDAFTAR' => $this->dasborTakTerdaftar(),
            'PENGALIHAN_TAK_TERDAFTAR' => $this->pengalihanTakTerdaftar(),
        ];

        $total = collect($kategori)->sum(fn (array $baris) => count($baris));

        $this->info('PEMERIKSAAN KONSISTENSI RBAC');
        $this->line('Peran dikenal: '.implode(', ', HierarkiRole::peran()));
        $this->line('Izin terdaftar: '.count(Izin::peta()));
        $this->newLine();

        $this->table(
            ['Kategori', 'Jumlah', 'Keterangan'],
            collect($kategori)->map(fn (array $baris, string $nama) => [
                $nama,
                count($baris),
                $this->keteranganKategori($nama),
            ])->values()->all()
        );

        foreach ($kategori as $nama => $baris) {
            if ($baris === []) {
                continue;
            }

            $this->newLine();
            $this->warn("{$nama} — ".count($baris).' temuan:');

            foreach (array_slice($baris, 0, $batasContoh) as $satu) {
                $this->line('  - '.$satu);
            }

            if (count($baris) > $batasContoh) {
                $this->line('  … dan '.(count($baris) - $batasContoh).' temuan lain.');
            }
        }

        $this->newLine();

        if ($total === 0) {
            $this->info('Seluruh nama peran & izin pada rute dan konfigurasi konsisten dengan SSOT RBAC.');

            return self::SUCCESS;
        }

        $this->error("Ditemukan {$total} ketidaksesuaian RBAC. Perbaiki sebelum rilis.");

        return self::FAILURE;
    }

    /* =====================================================================
     | 1 & 2. ARGUMEN MIDDLEWARE PADA DEFINISI RUTE
     ===================================================================== */

    /** @return list<string> */
    private function roleRuteTakDikenal(): array
    {
        $hasil = [];

        foreach (Route::getRoutes() as $rute) {
            foreach ($this->argumenMiddleware($rute, 'role:') as $peran) {
                if (! HierarkiRole::dikenal($peran)) {
                    $hasil[] = "peran \"{$peran}\" tidak dikenal — ".$this->labelRute($rute);
                }
            }
        }

        return $hasil;
    }

    /** @return list<string> */
    private function izinRuteTakTerdaftar(): array
    {
        $hasil = [];

        foreach (Route::getRoutes() as $rute) {
            foreach ($this->argumenMiddleware($rute, 'izin:') as $nama) {
                if (! Izin::ada($nama)) {
                    $hasil[] = "izin \"{$nama}\" tidak terdaftar — ".$this->labelRute($rute);
                }
            }
        }

        return $hasil;
    }

    /* =====================================================================
     | 3. PETA IZIN → PERAN
     ===================================================================== */

    /** @return list<string> */
    private function izinPeranTakDikenal(): array
    {
        $hasil = [];

        foreach (Izin::peta() as $nama => $daftarPeran) {
            $daftarPeran = array_values(array_filter(array_map('strval', (array) $daftarPeran)));

            if ($daftarPeran === []) {
                $hasil[] = "izin \"{$nama}\" tidak memetakan peran apa pun — rute selalu 403";

                continue;
            }

            foreach ($daftarPeran as $peran) {
                if (! HierarkiRole::dikenal($peran)) {
                    $hasil[] = "izin \"{$nama}\" memuat peran \"{$peran}\" yang tidak dikenal";
                }
            }
        }

        return $hasil;
    }

    /* =====================================================================
     | 4. NAMA PERAN DI SELURUH BAGIAN config/role_hierarki.php
     ===================================================================== */

    /** @return list<string> */
    private function konfigPeranTakDikenal(): array
    {
        $hasil = [];

        foreach ($this->pasanganPeranKonfigurasi() as [$bagian, $peran]) {
            if (! HierarkiRole::dikenal($peran)) {
                $hasil[] = "{$bagian} memuat peran \"{$peran}\" yang tidak dikenal";
            }
        }

        return $hasil;
    }

    /**
     * Seluruh pasangan (bagian konfigurasi, nama peran) yang harus merujuk
     * peran sah — termasuk kunci peta, nilai rantai, dan approval_role.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function pasanganPeranKonfigurasi(): array
    {
        $konfig = (array) config('role_hierarki', []);
        $hasil = [];

        foreach (array_keys((array) ($konfig['rantai'] ?? [])) as $peran) {
            $hasil[] = ['rantai (kunci)', (string) $peran];
        }

        foreach ((array) ($konfig['rantai'] ?? []) as $tujuan) {
            if ($tujuan !== null && $tujuan !== '') {
                $hasil[] = ['rantai (nilai)', (string) $tujuan];
            }
        }

        foreach ((array) ($konfig['batas'] ?? []) as $peran => $jenis) {
            $hasil[] = ['batas', (string) $peran];

            foreach ((array) $jenis as $aturan) {
                $penyetuju = $aturan['approval_role'] ?? null;

                if ($penyetuju !== null && $penyetuju !== '') {
                    $hasil[] = ['batas.approval_role', (string) $penyetuju];
                }
            }
        }

        foreach (['batas_dari_kebijakan', 'frekuensi', 'akses_data', 'status_menunggu'] as $bagian) {
            foreach (array_keys((array) ($konfig[$bagian] ?? [])) as $peran) {
                $hasil[] = [$bagian, (string) $peran];
            }
        }

        foreach ((array) ($konfig['kelompok'] ?? []) as $nama => $daftar) {
            foreach ((array) $daftar as $peran) {
                $hasil[] = ["kelompok.{$nama}", (string) $peran];
            }
        }

        foreach ((array) ($konfig['izin_dokumen'] ?? []) as $prefiks => $daftar) {
            foreach ((array) $daftar as $peran) {
                $hasil[] = ["izin_dokumen.{$prefiks}", (string) $peran];
            }
        }

        foreach ((array) ($konfig['kelompok_tolak'] ?? []) as $nama => $daftar) {
            foreach (array_keys((array) $daftar) as $peran) {
                $hasil[] = ["kelompok_tolak.{$nama}", (string) $peran];
            }
        }

        return $hasil;
    }

    /* =====================================================================
     | 5 & 6. RUTE TUJUAN YANG DIRUJUK KONFIGURASI
     ===================================================================== */

    /** @return list<string> */
    private function dasborTakTerdaftar(): array
    {
        $hasil = [];

        foreach ((array) config('role_hierarki.peran', []) as $peran => $meta) {
            $nama = $meta['dashboard_route'] ?? null;

            if (is_string($nama) && $nama !== '' && ! Route::has($nama)) {
                $hasil[] = "peran \"{$peran}\" menunjuk rute dasbor \"{$nama}\" yang tidak terdaftar";
            }
        }

        return $hasil;
    }

    /** @return list<string> */
    private function pengalihanTakTerdaftar(): array
    {
        $hasil = [];

        foreach ((array) config('role_hierarki.kelompok_tolak', []) as $kelompok => $daftar) {
            foreach ((array) $daftar as $peran => $peta) {
                $nama = $peta['route'] ?? null;

                if (is_string($nama) && $nama !== '' && ! Route::has($nama)) {
                    $hasil[] = "kelompok_tolak.{$kelompok}.{$peran} menunjuk rute \"{$nama}\" yang tidak terdaftar";
                }
            }
        }

        return $hasil;
    }

    /* =====================================================================
     | BANTUAN
     ===================================================================== */

    /**
     * Argumen sebuah middleware berprefiks (mis. `role:` → daftar peran).
     *
     * @return list<string>
     */
    private function argumenMiddleware(LaravelRoute $rute, string $prefiks): array
    {
        $hasil = [];

        foreach ($rute->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, $prefiks)) {
                continue;
            }

            foreach (explode(',', substr($middleware, strlen($prefiks))) as $argumen) {
                $argumen = trim($argumen);

                if ($argumen !== '') {
                    $hasil[] = $argumen;
                }
            }
        }

        return $hasil;
    }

    private function labelRute(LaravelRoute $rute): string
    {
        $metode = implode('|', array_values(array_diff($rute->methods(), ['HEAD'])));
        $nama = $rute->getName();

        return $metode.' /'.ltrim($rute->uri(), '/').($nama !== null ? " [{$nama}]" : '');
    }

    private function keteranganKategori(string $nama): string
    {
        return match ($nama) {
            'ROUTE_ROLE_TAK_DIKENAL' => 'Middleware role: menyebut peran di luar config/role_hierarki.php.',
            'ROUTE_IZIN_TAK_TERDAFTAR' => 'Middleware izin: menyebut izin di luar config/izin.php.',
            'IZIN_PERAN_TAK_DIKENAL' => 'Peta izin memuat peran tak dikenal atau daftar peran kosong.',
            'KONFIG_PERAN_TAK_DIKENAL' => 'Konfigurasi hierarki memuat nama peran yang tidak dikenal.',
            'DASBOR_TAK_TERDAFTAR' => 'Rute dasbor peran tidak terdaftar — pengalihan akan gagal.',
            'PENGALIHAN_TAK_TERDAFTAR' => 'Rute pengalihan ramah tidak terdaftar.',
            default => '',
        };
    }
}
