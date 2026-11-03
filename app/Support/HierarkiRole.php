<?php

namespace App\Support;

use App\Models\KebijakanKoperasi;
use App\Models\LimitTransaksiRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Hierarki peran (RBAC) & batas operasional — pintu masuk tunggal.
 *
 * Kelas ini menyatukan dua hal yang sebelumnya tersebar dan saling
 * bertentangan (komentar di model User, `in_array` di banyak tempat, konstanta
 * DEFAULT_LIMITS di service, dan baris `limit_transaksi_role` di database):
 *
 *   1. STRUKTUR   — daftar peran, tingkat kewenangan, dan rantai approval.
 *   2. BATAS      — limit nominal, frekuensi aksi, dan volume akses data.
 *
 * Sumber nilai: `config/role_hierarki.php` (default aman) yang dapat ditimpa
 * baris tabel `limit_transaksi_role` (override dari UI tanpa deploy). Kelas ini
 * TIDAK menyimpan aturan sendiri — ia hanya membaca dan menegakkan.
 *
 * Cara menambah peran baru: tambahkan entri di `role_hierarki.peran` +
 * `role_hierarki.rantai` + `role_hierarki.batas`. Tidak ada kode yang perlu
 * disentuh — nama status approval (`PENDING_<PERAN>`) ikut diturunkan otomatis.
 */
class HierarkiRole
{
    /** Kunci cache tunggal untuk seluruh override batas dari database. */
    public const CACHE_KEY = 'role_hierarki.override';

    /** Masa hidup cache override (detik) — 60 menit. */
    public const CACHE_TTL = 3600;

    /* ═════════════════════════════════════════════════════════════════════
     | STRUKTUR PERAN
     |═════════════════════════════════════════════════════════════════════ */

    /**
     * Seluruh peran yang dikenal sistem, dari kewenangan terendah ke tertinggi.
     *
     * @return list<string>
     */
    public static function peran(): array
    {
        $peran = static::konfigurasi()['peran'] ?? [];

        // Urutkan menurut level supaya urutan pemakaian konsisten di seluruh
        // aplikasi (tidak bergantung urutan penulisan pada file config).
        uasort($peran, fn ($a, $b) => ($a['level'] ?? 0) <=> ($b['level'] ?? 0));

        return array_keys($peran);
    }

    /** Apakah peran ini dikenal sistem. */
    public static function dikenal(?string $peran): bool
    {
        return $peran !== null && array_key_exists($peran, static::konfigurasi()['peran'] ?? []);
    }

    /** Tingkat kewenangan peran (makin besar = makin tinggi). Peran tak dikenal → 0. */
    public static function level(?string $peran): int
    {
        return (int) (static::konfigurasi()['peran'][$peran]['level'] ?? 0);
    }

    /** Label tampilan peran. Peran tak dikenal → dikembalikan apa adanya. */
    public static function label(?string $peran): string
    {
        return (string) (static::konfigurasi()['peran'][$peran]['label'] ?? $peran);
    }

    /**
     * Nama rute dasbor utama peran (dari `peran.*.dashboard_route`).
     *
     * Dipakai oleh `WebAuthController::redirectByRole()` dan halaman galat 403
     * supaya pemetaan peran → dasbor hanya hidup di satu tempat. Peran tak
     * dikenal (atau belum punya dasbor) → null, agar pemanggil memilih
     * halaman netral alih-alih memutar balik ke login.
     */
    public static function dashboardRoute(?string $peran): ?string
    {
        $rute = static::konfigurasi()['peran'][$peran]['dashboard_route'] ?? null;

        return is_string($rute) && $rute !== '' ? $rute : null;
    }

    /**
     * Jabatan penandatangan dokumen resmi (mis. bilyet deposito) untuk peran.
     *
     * Dipakai `config/pdf.php` agar label jabatan tidak diduplikasi: satu
     * sumber di sini, satu tempat menambah peran. Peran tak dikenal / belum
     * punya jabatan → fallback ke `label()`.
     */
    public static function jabatan(?string $peran): string
    {
        $jabatan = static::konfigurasi()['peran'][$peran]['jabatan'] ?? null;

        return is_string($jabatan) && $jabatan !== '' ? $jabatan : static::label($peran);
    }

    /* ═════════════════════════════════════════════════════════════════════
     | KELOMPOK PERAN TERPUSAT
     |═════════════════════════════════════════════════════════════════════ */

    /**
     * Daftar peran untuk sebuah kelompok logis (mis. 'pengelola',
     * 'penerima_notifikasi', 'sistem').
     *
     * Sumber nilai: `role_hierarki.kelompok.<nama>` (daftar eksplisit) ATAU
     * `role_hierarki.peran.*.kategori` (bila nama kelompok sama dengan nama
     * kategori). Hasil SELALU disaring dengan `dikenal()` sehingga peran yang
     * dihapus dari `peran` otomatis hilang dari jawaban — mencegah kelompok
     * mengandung peran hantu.
     *
     * Ini adalah pengganti tunggal untuk seluruh `in_array($role, [...])`
     * literal yang sebelumnya tersebar di model/controller/view.
     *
     * @return list<string>
     */
    public static function kelompok(string $nama): array
    {
        $konfig = static::konfigurasi();

        $daftar = $konfig['kelompok'][$nama] ?? null;

        // Fallback: nama kelompok = nama kategori (mis. 'pengelola').
        if ($daftar === null) {
            $daftar = [];
            foreach ($konfig['peran'] ?? [] as $peran => $meta) {
                if (($meta['kategori'] ?? null) === $nama) {
                    $daftar[] = $peran;
                }
            }
        }

        return array_values(array_filter(
            (array) $daftar,
            fn ($p) => static::dikenal($p)
        ));
    }

    /**
     * Apakah $peran termasuk dalam kelompok logis $nama.
     *
     * PEWARISAN PUNCAK: peran `role_hierarki.pewaris_puncak` (Super Admin)
     * dianggap anggota SETIAP kelompok tanpa perlu menyalin namanya ke
     * daftar kelompok. Pengecualiannya adalah kelompok pada
     * `role_hierarki.pewaris_puncak_kecuali` — semuanya kewenangan
     * menyetujui/mengeksekusi kas atau baris staf, yang harus tetap dipegang
     * peran operasional agar pemisahan tugas (maker-checker) utuh.
     *
     * Pewarisan di sini HANYA menyentuh kelompok logis. `checkRole`
     * (`role:` di rute) dan ability Gate `role` TIDAK mewarisi apa pun —
     * daftar peran pada rute tetap eksplisit.
     */
    public static function dalamKelompok(?string $peran, string $nama): bool
    {
        if ($peran === null || $peran === '') {
            return false;
        }

        if (in_array($peran, static::kelompok($nama), true)) {
            return true;
        }

        $konfig = static::konfigurasi();

        if ($peran !== ($konfig['pewaris_puncak'] ?? null)) {
            return false;
        }

        if (in_array($nama, (array) ($konfig['pewaris_puncak_kecuali'] ?? []), true)) {
            return false;
        }

        // Kelompok tak dikenal tetap false: pewarisan tidak menciptakan
        // kelompok baru, hanya membuka kelompok yang benar-benar terdaftar.
        return static::kelompok($nama) !== [];
    }

    /**
     * Tujuan pengalihan (redirect) yang ramah untuk peran yang DITOLAK pada
     * sebuah kelompok kewenangan — beserta pesannya.
     *
     * Sebelumnya pola ini ditulis sebagai literal `if ($role === 'ao') { return
     * redirect()->route('dashboard.ao')->with('error', '...'); }` di controller.
     * Kini pasangan (peran → tujuan) hidup di
     * `role_hierarki.kelompok_tolak.<kelompok>` sehingga menambah/mengubah
     * pengalihan cukup lewat konfigurasi, bukan mengedit controller.
     *
     * Kelompok tanpa entri ini → null (pemanggil memilih `abort(403)`), yaitu
     * perilaku aman bawaan.
     *
     * @return array{route: string, pesan: string}|null
     */
    public static function penolakanKelompok(?string $peran, string $namaKelompok): ?array
    {
        if ($peran === null || $peran === '') {
            return null;
        }

        $peta = static::konfigurasi()['kelompok_tolak'][$namaKelompok][$peran] ?? null;

        if (! is_array($peta)) {
            return null;
        }

        $rute = $peta['route'] ?? null;

        if (! is_string($rute) || $rute === '') {
            return null;
        }

        $pesan = $peta['pesan'] ?? null;

        return [
            'route' => $rute,
            'pesan' => is_string($pesan) && $pesan !== ''
                ? $pesan
                : 'Akses ditolak. Peran Anda tidak memiliki izin untuk tindakan ini.',
        ];
    }

    /** Kategori peran (pengawas|pengurus|pengelola|sistem|anggota). Tak dikenal → ''. */
    public static function kategori(?string $peran): string
    {
        return (string) (static::konfigurasi()['peran'][$peran]['kategori'] ?? '');
    }

    /**
     * Kategori yang dimiliki peran apa pun yang masuk kelompok $nama — dipakai
     * untuk menurunkan daftar peran secara otomatis bila kelompok tidak
     * didefinisikan eksplisit.
     *
     * @return list<string>
     */
    public static function peranDenganKategori(string $kategori): array
    {
        return static::kelompok($kategori);
    }

    /* ═════════════════════════════════════════════════════════════════════
     | IZIN DOKUMEN PRIVAT
     |═════════════════════════════════════════════════════════════════════ */

    /**
     * Daftar peran yang berhak mengunduh dokumen pada prefiks folder tertentu.
     * Prefiks yang tidak dipetakan → [] (fail-CLOSED).
     *
     * @return list<string>
     */
    public static function izinDokumen(string $prefiks): array
    {
        $peta = static::konfigurasi()['izin_dokumen'] ?? [];

        if (! array_key_exists($prefiks, $peta)) {
            return [];
        }

        return array_values(array_filter(
            (array) $peta[$prefiks],
            fn ($p) => static::dikenal($p)
        ));
    }

    /** Apakah $peran berhak mengunduh dokumen pada prefiks folder tersebut. */
    public static function bolehUnduhDokumen(?string $peran, string $prefiks): bool
    {
        return $peran !== null
            && in_array($peran, static::izinDokumen($prefiks), true);
    }

    /** Apakah peran A lebih tinggi daripada peran B. */
    public static function lebihTinggi(?string $a, ?string $b): bool
    {
        return static::level($a) > static::level($b);
    }

    /**
     * Peran berurutan dari $peran ke puncak hierarki (tanpa $peran itu sendiri).
     *
     * @return list<string>
     */
    public static function atasanDari(?string $peran): array
    {
        $hasil = [];
        $naik = static::rantai($peran);
        $batas = count(static::peran()) + 1; // jaga-jaga terhadap rantai melingkar

        while ($naik !== null && $batas-- > 0) {
            $hasil[] = $naik;
            $naik = static::rantai($naik);
        }

        return $hasil;
    }

    /* ═════════════════════════════════════════════════════════════════════
     | RANTAI APPROVAL
     |═════════════════════════════════════════════════════════════════════ */

    /**
     * Peran penyetuju langsung (satu tingkat di atas) untuk peran pengaju.
     * NULL = puncak rantai, tidak ada yang di atasnya.
     */
    public static function rantai(?string $peran): ?string
    {
        $rantai = static::konfigurasi()['rantai'] ?? [];

        return $rantai[$peran] ?? null;
    }

    /** Apakah peran ini berwenang menjadi pemutus (checker) pada rantai approval. */
    public static function penyetuju(?string $peran): bool
    {
        return (bool) (static::konfigurasi()['peran'][$peran]['penyetuju'] ?? false);
    }

    /* ═════════════════════════════════════════════════════════════════════
     | PELINDUNG PUNCAK ADMINISTRATIF
     |═════════════════════════════════════════════════════════════════════ */

    /**
     * Peran tertinggi yang hak aksesnya TIDAK BOLEH diubah pihak lain.
     *
     * Nilainya dibaca dari `role_hierarki.pewaris_puncak` (satu sumber dengan
     * pewarisan kelompok), sehingga bila suatu saat puncak administratif
     * berpindah peran, seluruh penjaga di bawah ikut berpindah tanpa suntingan
     * kode. Default aman bila kunci tidak ada: `super_admin`.
     */
    public static function puncak(): string
    {
        $puncak = static::konfigurasi()['pewaris_puncak'] ?? 'super_admin';

        return is_string($puncak) && $puncak !== '' ? $puncak : 'super_admin';
    }

    /** Apakah $peran adalah puncak administratif. */
    public static function adalahPuncak(?string $peran): bool
    {
        return $peran !== null && $peran !== '' && $peran === static::puncak();
    }

    /**
     * Apakah $aktor berwenang mengubah hak akses / konfigurasi peran $target.
     *
     * ATURAN (langkah #3 permintaan pemilik sistem): hak akses puncak
     * administratif hanya boleh diubah oleh dirinya sendiri. Role mana pun —
     * termasuk Pengurus dan Admin yang memegang halaman pengaturan — tidak
     * dapat mencabut, menurunkan level, atau melonggarkan hak Super Admin.
     *
     * Peran lain selain puncak tetap dapat diatur oleh siapa pun yang lolos
     * gerbang `izin:` rute, karena keputusan itu tidak menyentuh puncak.
     */
    public static function bolehMengubahPeran(?string $aktor, ?string $target): bool
    {
        if (! static::adalahPuncak($target)) {
            return true;
        }

        return static::adalahPuncak($aktor);
    }

    /** Apakah peran ini terlibat transaksi operasional harian. */
    public static function operasional(?string $peran): bool
    {
        return (bool) (static::konfigurasi()['peran'][$peran]['operasional'] ?? false);
    }

    /**
     * Apakah peran tunduk pada kebijakan batas operasional (`batas`),
     * yaitu peran PERSONEL koperasi.
     *
     * T-2 — `anggota` dan `nasabah` adalah peran PEMAKAI JASA, bukan personel,
     * sehingga sengaja tidak tercantum pada `role_hierarki.batas`. Akibat
     * sampingnya `batas()` mengembalikan `limit_per_transaksi = NULL` yang
     * berarti "tanpa batas", sedangkan `frekuensi()` dan `aksesData()`
     * mengembalikan NULL yang juga berarti "tanpa batas" — penjaga di
     * `TransactionLimitService` dan `ambilDenganBatas()` tak pernah menyala
     * untuk mereka. Kanal yang hanya boleh dilayani personel (mis. API kas
     * mobile) wajib memakai pemeriksaan ini LEBIH DULU.
     */
    public static function tundukBatasOperasional(?string $peran): bool
    {
        return $peran !== null
            && $peran !== ''
            && array_key_exists($peran, (array) (static::konfigurasi()['batas'] ?? []));
    }

    /**
     * Peran apa saja yang berwenang menyetujui pengajuan dari $peranPengaju.
     *
     * @return list<string>
     */
    public static function penyetujuUntuk(?string $peranPengaju): array
    {
        $peran = static::rantai($peranPengaju);

        return ($peran !== null && static::penyetuju($peran)) ? [$peran] : [];
    }

    /**
     * Status baris `approval_transaksi` untuk peran penyetuju.
     * Peran tak dikenal → 'PENDING_' . strtoupper($peran) (diturunkan, bukan
     * dipetakan manual) sehingga peran baru langsung berfungsi.
     */
    public static function statusMenunggu(?string $peranPenyetuju): ?string
    {
        if ($peranPenyetuju === null) {
            return null;
        }

        $peta = static::konfigurasi()['status_menunggu'] ?? [];

        return $peta[$peranPenyetuju] ?? ('PENDING_'.strtoupper($peranPenyetuju));
    }

    /**
     * Status `pembiayaan` yang menandai baris sedang menunggu peran tertentu.
     *
     * Diturunkan dari `statusMenunggu()` agar penambahan peran/tahap approval
     * tidak memerlukan perubahan kode controller (DYN-05). Contoh:
     * `pengurus` → `pending_pengurus`, `dps` → `pending_dps`.
     */
    public static function statusPembiayaanMenunggu(?string $peran): ?string
    {
        $status = static::statusMenunggu($peran);

        return $status === null ? null : strtolower($status);
    }

    /**
     * Terbalik dari statusMenunggu(): peran apa yang boleh memutus status ini.
     * Status 'APPROVED'/'REJECTED'/tak dikenal → [] (tidak ada yang boleh).
     *
     * @return list<string>
     */
    public static function peranUntukStatus(?string $status): array
    {
        if ($status === null || $status === '') {
            return [];
        }

        foreach (static::peran() as $peran) {
            if (static::statusMenunggu($peran) === $status && static::penyetuju($peran)) {
                return [$peran];
            }
        }

        return [];
    }

    /** Seluruh status "menunggu keputusan" yang mungkin (untuk query daftar tugas). */
    public static function semuaStatusMenunggu(): array
    {
        return array_values(array_filter(array_map(
            fn ($peran) => static::penyetuju($peran) ? static::statusMenunggu($peran) : null,
            static::peran()
        )));
    }

    /**
     * Peran pemutus tertinggi (puncak rantai approval yang berwenang menyetujui).
     *
     * Dipakai untuk otorisasi yang menurut kebijakan wajib naik ke puncak
     * (mis. transaksi AML high-value), sehingga tidak perlu menulis nama peran
     * literal di service. NULL bila tidak ada peran pemutus sama sekali.
     */
    public static function penyetujuTertinggi(): ?string
    {
        $tertinggi = null;

        foreach (static::peran() as $peran) {
            if (! static::penyetuju($peran) || static::rantai($peran) !== null) {
                continue;
            }

            if ($tertinggi === null || static::level($peran) > static::level($tertinggi)) {
                $tertinggi = $peran;
            }
        }

        return $tertinggi;
    }

    /* ═════════════════════════════════════════════════════════════════════
     | BATAS OPERASIONAL
     |═════════════════════════════════════════════════════════════════════ */

    /**
     * Batas lengkap satu peran untuk satu jenis transaksi, sudah digabung
     * dengan override database.
     *
     * @return array{limit_per_transaksi: float|null, limit_harian: float|null, butuh_approval: bool, approval_role: string|null}
     */
    public static function batas(?string $peran, string $jenisTransaksi): array
    {
        $dasar = static::konfigurasi()['batas'][$peran][$jenisTransaksi] ?? null;

        $nilai = [
            'limit_per_transaksi' => static::angkaAtauNull($dasar['limit_per_transaksi'] ?? null),
            'limit_harian' => static::angkaAtauNull($dasar['limit_harian'] ?? null),
            'butuh_approval' => (bool) ($dasar['butuh_approval'] ?? false),
            'approval_role' => ($dasar['approval_role'] ?? null) ?: null,
        ];

        // Lapis kedua: baris tabel `limit_transaksi_role`. Bila baris ada, ia
        // yang berlaku untuk pasangan (peran, jenis) tersebut — inilah override
        // yang dapat diubah operator tanpa deploy.
        $override = static::override()[$peran][$jenisTransaksi] ?? null;

        if ($override !== null) {
            $nilai['limit_per_transaksi'] = static::angkaAtauNull($override['limit_per_transaksi']);
            $nilai['limit_harian'] = static::angkaAtauNull($override['limit_harian']);
            $nilai['butuh_approval'] = (bool) $override['butuh_approval'];
            $nilai['approval_role'] = ($override['approval_role'] ?? null) ?: null;
        }

        // Kebijakan nominal khusus yang berasal dari kolom kebijakan koperasi
        // (dapat diubah Pengurus dari UI). Daftar pasangan (peran, jenis
        // transaksi) → nama kolom hidup di config/role_hierarki.php
        // (`batas_dari_kebijakan`), bukan sebagai `if` per nama peran (DYN-07).
        $kolomKebijakan = static::konfigurasi()['batas_dari_kebijakan'][$peran][$jenisTransaksi] ?? null;

        if ($kolomKebijakan !== null) {
            $kebijakan = static::kebijakanNominal($kolomKebijakan);
            if ($kebijakan !== null) {
                $nilai['limit_per_transaksi'] = $kebijakan;
            }
        }

        return $nilai;
    }

    /**
     * Nominal batas dari kolom kebijakan koperasi (kolom yang sudah ada di UI
     * Pengurus). NULL bila kebijakan tidak tersedia (mis. tabel belum ada).
     */
    private static function kebijakanNominal(string $kolom): ?float
    {
        try {
            $kebijakan = KebijakanKoperasi::getSetting();
        } catch (\Throwable $e) {
            Log::warning('HierarkiRole: gagal membaca kebijakan batas, memakai batas config.', [
                'kolom' => $kolom,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return static::angkaAtauNull($kebijakan->{$kolom} ?? null);
    }

    /** Batas nominal per transaksi. NULL = tanpa batas, 0 = dilarang. */
    public static function limitPerTransaksi(?string $peran, string $jenisTransaksi): ?float
    {
        return static::batas($peran, $jenisTransaksi)['limit_per_transaksi'];
    }

    /** Batas akumulasi nominal per hari. NULL = tanpa batas. */
    public static function limitHarian(?string $peran, string $jenisTransaksi): ?float
    {
        return static::batas($peran, $jenisTransaksi)['limit_harian'];
    }

    /**
     * Batas frekuensi aksi per hari. NULL = tanpa batas.
     *
     * @param  string  $aksi  mis. 'maks_transaksi_per_hari'
     */
    public static function frekuensi(?string $peran, string $aksi = 'maks_transaksi_per_hari'): ?int
    {
        $nilai = static::konfigurasi()['frekuensi'][$peran][$aksi] ?? null;

        return $nilai === null ? null : max(0, (int) $nilai);
    }

    /**
     * Batas volume baris akses data (mis. ekspor). NULL = tanpa batas.
     *
     * @param  string  $aksi  mis. 'maks_baris_ekspor'
     */
    public static function aksesData(?string $peran, string $aksi = 'maks_baris_ekspor'): ?int
    {
        $nilai = static::konfigurasi()['akses_data'][$peran][$aksi] ?? null;

        return $nilai === null ? null : max(0, (int) $nilai);
    }

    /**
     * Ambil baris sambil menghormati batas volume akses data peran.
     *
     * Mengambil `batas + 1` baris: bila yang kembali melebihi `batas`, pemanggil
     * tahu permintaan MELAMPAUI batas dan wajib menolaknya — bukan menyajikan
     * data yang terpotong diam-diam (UU PDP No. 27/2022: minimalisasi data).
     * `null`/`0` = tanpa batas (perilaku default, tidak mengubah apa pun).
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query
     * @return array{data: Collection, batas: int|null, melampaui: bool}
     */
    public static function ambilDenganBatas($query, ?string $peran, string $aksi = 'maks_baris_ekspor'): array
    {
        $batas = static::aksesData($peran, $aksi);

        if ($batas === null || $batas === 0) {
            return ['data' => $query->get(), 'batas' => $batas, 'melampaui' => false];
        }

        $data = $query->limit($batas + 1)->get();

        return [
            'data' => $data->take($batas),
            'batas' => $batas,
            'melampaui' => $data->count() > $batas,
        ];
    }

    /**
     * Seluruh batas satu peran (semua jenis transaksi) — untuk tampilan
     * konfigurasi di dashboard Pengelola.
     *
     * @return array<string, array>
     */
    public static function batasPeran(?string $peran): array
    {
        $hasil = [];

        foreach (array_keys(static::konfigurasi()['jenis_transaksi'] ?? []) as $jenis) {
            $hasil[$jenis] = static::batas($peran, $jenis);
        }

        return $hasil;
    }

    /** Label tampilan sebuah jenis transaksi. */
    public static function labelJenis(string $jenis): string
    {
        return (string) (static::konfigurasi()['jenis_transaksi'][$jenis] ?? $jenis);
    }

    /* ═════════════════════════════════════════════════════════════════════
     | PEMBACAAN KONFIGURASI & OVERRIDE
     |═════════════════════════════════════════════════════════════════════ */

    /**
     * Konfigurasi hierarki (default aman) dari `config/role_hierarki.php`.
     *
     * @return array<string, mixed>
     */
    public static function konfigurasi(): array
    {
        return (array) config('role_hierarki', []);
    }

    /**
     * Override batas dari tabel `limit_transaksi_role`, ter-cache 60 menit.
     *
     * Bentuk: [peran][jenis_transaksi][kolom] => nilai.
     * Tabel belum ada (mis. sebelum migrasi) → [] dan sistem memakai default
     * dari config. Kegagalan lain tetap dicatat agar tidak hilang diam-diam.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function override(): array
    {
        return Cache::remember(static::CACHE_KEY, static::CACHE_TTL, function (): array {
            try {
                $baris = LimitTransaksiRole::query()->get();
            } catch (\Throwable $e) {
                Log::warning('HierarkiRole: gagal membaca override limit_transaksi_role, memakai default config.', [
                    'error' => $e->getMessage(),
                ]);

                return [];
            }

            $hasil = [];

            foreach ($baris as $item) {
                $peran = (string) $item->role;
                $jenis = (string) $item->jenis_transaksi;

                $hasil[$peran][$jenis] = [
                    'limit_per_transaksi' => $item->limit_per_transaksi,
                    'limit_harian' => $item->limit_harian,
                    'butuh_approval' => $item->butuh_approval,
                    'approval_role' => $item->approval_role,
                ];
            }

            return $hasil;
        });
    }

    /** Bersihkan cache override (dipanggil setelah baris limit disimpan/dihapus). */
    public static function flush(): void
    {
        Cache::forget(static::CACHE_KEY);
    }

    /** Muat ulang override dari database. */
    public static function refresh(): array
    {
        static::flush();

        return static::override();
    }

    /* ═════════════════════════════════════════════════════════════════════
     | BANTUAN INTERNAL
     |═════════════════════════════════════════════════════════════════════ */

    /** Konversi nilai apa pun menjadi float, atau NULL bila memang kosong. */
    private static function angkaAtauNull(mixed $nilai): ?float
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        return (float) $nilai;
    }
}
