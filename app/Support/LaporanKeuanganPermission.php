<?php

namespace App\Support;

/**
 * Peta hak akses (RBAC) modul Laporan Keuangan.
 *
 * Satu-satunya sumber kebenaran untuk pertanyaan "peran X boleh apa pada modul
 * Y". Middleware, controller, dan tampilan semuanya membaca dari sini supaya
 * tidak ada aturan yang bercabang dan saling bertentangan.
 *
 * Hak akses yang dikenal:
 *   lihat   — membuka halaman laporan
 *   unduh   — mengunduh berkas laporan (mis. versi cetak/salinan)
 *   edit    — mengubah data yang mendasari laporan
 *   hapus   — menghapus data yang mendasari laporan
 *   ekspor  — mengekspor data laporan ke berkas (Excel/CSV)
 *
 * Catatan penetapan (matriks konservatif — mempertahankan akses yang sudah ada
 * sambil menutup celah, bukan memperluas akses):
 *   - teller dan ao TIDAK diberi akses sama sekali. Keduanya adalah peran
 *     operasional front-office; membuka laporan keuangan kepada mereka bukan
 *     praktik yang lazim dan tidak diminta.
 *   - Manajer hanya melihat laporan operasional. Neraca dan Buku Besar tetap
 *     dapat dilihat karena sudah jadi praktik sistem saat ini, namun manajer
 *     TIDAK boleh mengubah atau menghapus apa pun.
 *   - DPS bersifat pengawas syariah: hanya lihat, dan hanya pada akun Ekuitas
 *     serta Kewajiban (pembatasan per-akun diterapkan di controller karena
 *     bergantung pada parameter akun yang dipilih, bukan sekadar peran).
 *   - Modul Audit Laporan Keuangan EKSKLUSIF DPS (pengawas independen).
 *     Pengurus, Manajer, Super Admin, dan Admin TIDAK diberi akses: pihak yang
 *     diawasi maupun administrator sistem tidak boleh mengaudit dirinya sendiri
 *     (Segregation of Duties / Good Co-operative Governance).
 *   - PEMISAHAN TUGAS (maker-checker, GCG): hak menulis TIDAK lagi menempel
 *     pada modul laporan. Laporan (Neraca, Buku Besar, Laporan Operasional,
 *     PSAK 101) bersifat turunan dan hanya-baca — siapa pun yang boleh
 *     membukanya tidak otomatis boleh mengubah data akuntansi di baliknya.
 *     Wewenang menulis dikeluarkan ke modul tersendiri
 *     `MODUL_PENYESUAIAN` (posting penyesuaian jurnal) yang hanya dipegang
 *     Pengurus. Dengan begitu hak EDIT/HAPUS terpisah dari hak
 *     LIHAT/UNDUH/EKSPOR, dan setiap penulisan dapat diaudit sebagai
 *     perbuatan atas modul penyesuaian — bukan sebagai "membuka laporan".
 */
class LaporanKeuanganPermission
{
    // ── Nama modul ──
    public const MODUL_NERACA = 'neraca';

    public const MODUL_BUKU_BESAR = 'buku_besar';

    public const MODUL_LAPORAN_MANAJER = 'laporan_manajer';

    public const MODUL_LAPORAN_PSAK101 = 'laporan_psak101';

    public const MODUL_AUDIT = 'audit_laporan';

    /** Modul tulis: posting penyesuaian jurnal (CKPN, alokasi SHU, tutup buku). */
    public const MODUL_PENYESUAIAN = 'penyesuaian_jurnal';

    // ── Nama hak akses ──
    public const LIHAT = 'lihat';

    public const UNDUH = 'unduh';

    public const EDIT = 'edit';

    public const HAPUS = 'hapus';

    public const EKSPOR = 'ekspor';

    /** Label modul untuk dropdown filter & tampilan. */
    public const LABEL_MODUL = [
        self::MODUL_NERACA => 'Neraca',
        self::MODUL_BUKU_BESAR => 'Buku Besar',
        self::MODUL_LAPORAN_MANAJER => 'Laporan Operasional',
        self::MODUL_LAPORAN_PSAK101 => 'Laporan Keuangan Syariah (PSAK 101)',
        self::MODUL_AUDIT => 'Audit Laporan Keuangan',
        self::MODUL_PENYESUAIAN => 'Penyesuaian Jurnal',
    ];

    /**
     * Seluruh modul laporan keuangan, berurutan untuk tampilan tabel.
     *
     * Satu-satunya daftar urut modul — dipakai controller & tampilan audit
     * supaya kolom tabel, payload JSON, dan sidik jari tidak pernah bercabang.
     *
     * @var list<string>
     */
    public const MODUL = [
        self::MODUL_NERACA,
        self::MODUL_BUKU_BESAR,
        self::MODUL_LAPORAN_MANAJER,
        self::MODUL_LAPORAN_PSAK101,
        self::MODUL_PENYESUAIAN,
        self::MODUL_AUDIT,
    ];

    /**
     * Matriks peran → modul → daftar hak akses.
     *
     * Sengaja ditulis lengkap (tidak memakai wildcard) agar setiap peran dapat
     * diaudit baris per baris tanpa harus menelusuri logika pewarisan.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const MATRIKS = [
        // Super Admin sistem: PUNCAK ADMINISTRATIF — seluruh modul yang rutenya
        // terbuka untuknya dicantumkan lengkap di sini.
        //
        // Setiap entri di bawah ini harus berpasangan dengan izin rute pada
        // `config/izin.php`. Bila satu modul ditambahkan ke matriks tanpa
        // izinnya, halaman audit menjanjikan akses yang setiap kali dicoba
        // berakhir 403; bila izinnya ada tetapi matriksnya kosong, rute
        // diloloskan sementara middleware `laporan.akses` justru memblokir.
        // Test `LaporanKeuanganRbacTest` mengunci kesepadanan ini.
        //
        // Modul Audit TETAP tidak diberikan: audit adalah alat pengawasan
        // independen dan eksklusif DPS — pihak yang mengelola sistem tidak
        // boleh mengaudit dirinya sendiri (Segregation of Duties).
        'super_admin' => [
            self::MODUL_NERACA => [self::LIHAT, self::UNDUH, self::EKSPOR],
            self::MODUL_BUKU_BESAR => [self::LIHAT, self::UNDUH, self::EKSPOR],
            self::MODUL_LAPORAN_MANAJER => [self::LIHAT, self::UNDUH, self::EKSPOR],
            self::MODUL_LAPORAN_PSAK101 => [self::LIHAT, self::UNDUH, self::EKSPOR],
            self::MODUL_PENYESUAIAN => [self::LIHAT, self::EDIT, self::HAPUS],
        ],

        // Admin sistem: TIDAK memegang satu pun hak pada matriks ini.
        // `config/izin.php` tidak mendaftarkan `admin` pada izin laporan mana
        // pun, dan rute penyesuaian jurnal dijaga izin yang hanya dimiliki
        // Pengurus. Mencantumkan hak di sini hanya menyesatkan halaman audit —
        // seolah admin dapat membuka laporan padahal setiap percobaannya
        // berakhir 403. Entri kosong ditulis eksplisit supaya keputusan
        // "tidak diberi akses" tercatat sebagai keputusan, bukan kelalaian.
        'admin' => [],

        // Pengurus: memegang wewenang menulis — tetapi lewat modul tersendiri
        // (penyesuaian jurnal), BUKAN lewat modul laporan. Laporan tetap
        // hanya-baca bagi semua peran; itu inti pemisahan tugas (R19/B11).
        // Modul Laporan Operasional tidak dicantumkan: rute
        // `manajer.laporan.index` dijaga `izin:laporan.manajer` yang hanya
        // memuat peran Manajer, jadi hak di sini tidak akan pernah terpakai.
        // Modul Audit TIDAK dipegang: Pengurus adalah pihak yang diawasi.
        'pengurus' => [
            self::MODUL_NERACA => [self::LIHAT, self::UNDUH, self::EKSPOR],
            self::MODUL_BUKU_BESAR => [self::LIHAT, self::UNDUH, self::EKSPOR],
            self::MODUL_LAPORAN_PSAK101 => [self::LIHAT, self::UNDUH, self::EKSPOR],
            self::MODUL_PENYESUAIAN => [self::LIHAT, self::EDIT, self::HAPUS],
        ],

        // Manajer: melihat & mengekspor laporan, tanpa hak mengubah data.
        // Modul Audit TIDAK dipegang: Manajer juga pihak yang diawasi.
        //
        // Laporan Operasional TETAP dicantumkan meski halamannya sedang
        // disembunyikan — dan itu memang disengaja. Dua lapis berbeda bekerja
        // di sini:
        //   · WEWENANG (berkas ini) — rute `manajer.laporan.index` dijaga
        //     `laporan.akses:laporan_manajer`, yang membaca matriks ini. Hak
        //     Manajer-lah yang meloloskan rute tersebut.
        //   · KETERSEDIAAN — `ManajerController::FITUR_DISEMBUNYIKAN` memuat
        //     'laporan', sehingga halamannya dialihkan ke dasbor Manajer.
        // Mencabut hak di sini akan membuat rute berbalik menjadi 403 dan
        // menutup jalan menghidupkan kembali halaman tanpa menyentuh RBAC.
        // Yang menyembunyikan halaman adalah keputusan fitur, bukan pencabutan
        // wewenang — jadi jangan "rapikan" entri ini dengan menghapusnya.
        'manajer' => [
            self::MODUL_NERACA => [self::LIHAT, self::UNDUH, self::EKSPOR],
            self::MODUL_BUKU_BESAR => [self::LIHAT, self::UNDUH, self::EKSPOR],
            self::MODUL_LAPORAN_MANAJER => [self::LIHAT, self::UNDUH, self::EKSPOR],
            self::MODUL_LAPORAN_PSAK101 => [self::LIHAT, self::UNDUH, self::EKSPOR],
        ],

        // DPS (Dewan Pengawas Syariah): pengawas independen — pemegang tunggal
        // modul Audit Laporan Keuangan. Untuk tiap modul hanya melihat.
        //
        // Kekhususan modul audit: auditor diberi LIHAT + EKSPOR agar dapat
        // mengunduh jejak audit (CSV) sebagai bukti kerja pengawasan. EKSPOR
        // hanya berlaku pada modul audit — bukan pada laporan keuangan itu
        // sendiri — sehingga tidak melebar menjadi hak mengunduh dokumen
        // keuangan. Pembatasan ke akun Ekuitas & Kewajiban pada laporan ada
        // di controller.
        'dps' => [
            self::MODUL_NERACA => [self::LIHAT],
            self::MODUL_BUKU_BESAR => [self::LIHAT],
            self::MODUL_LAPORAN_PSAK101 => [self::LIHAT],
            self::MODUL_AUDIT => [self::LIHAT, self::EKSPOR],
        ],

        // Teller & AO sengaja tidak diberi entri apa pun.
        'teller' => [],
        'ao' => [],

        // 'anggota' masih ada di ENUM users.role sebagai sisa sistem lama dan
        // mungkin masih dipakai sebagian akun. Diberi entri kosong secara
        // eksplisit — bukan dibiarkan tanpa entri — supaya keputusan "tidak
        // diberi akses" tercatat sebagai keputusan, bukan kelalaian.
        'anggota' => [],
    ];

    /** Semua peran yang dikenali sistem, berurutan untuk tampilan. */
    public const PERAN = ['super_admin', 'admin', 'pengurus', 'manajer', 'dps', 'teller', 'ao', 'anggota'];

    /** Label peran untuk tampilan. */
    public const LABEL_PERAN = [
        'super_admin' => 'Super Admin',
        'admin' => 'Admin',
        'pengurus' => 'Pengurus',
        'manajer' => 'Manajer',
        'dps' => 'DPS',
        'teller' => 'Teller',
        'ao' => 'Account Officer',
        'anggota' => 'Anggota',
    ];

    /** Nama peran siap tampil. */
    public static function labelPeran(?string $peran): string
    {
        if ($peran === null || $peran === '') {
            return '(tanpa peran)';
        }

        return self::LABEL_PERAN[$peran] ?? ucfirst($peran);
    }

    /** Nama modul siap tampil. */
    public static function labelModul(?string $modul): string
    {
        return self::LABEL_MODUL[$modul] ?? ucfirst((string) $modul);
    }

    /**
     * Apakah peran tertentu boleh melakukan aksi pada modul tertentu.
     */
    public static function boleh(?string $peran, string $modul, string $aksi): bool
    {
        if ($peran === null || $peran === '') {
            return false;
        }

        return in_array($aksi, self::MATRIKS[$peran][$modul] ?? [], true);
    }

    /**
     * Semua hak akses sebuah peran pada sebuah modul.
     *
     * @return list<string>
     */
    public static function hak(?string $peran, string $modul): array
    {
        return self::MATRIKS[$peran ?? ''][$modul] ?? [];
    }

    /**
     * Modul yang boleh diakses sebuah peran, beserta haknya.
     *
     * @return array<string, list<string>>
     */
    public static function modulYangBoleh(?string $peran): array
    {
        return self::MATRIKS[$peran ?? ''] ?? [];
    }

    /** Apakah peran ini punya akses apa pun ke modul laporan keuangan. */
    public static function punyaAksesLaporan(?string $peran): bool
    {
        return self::modulYangBoleh($peran) !== [];
    }

    /**
     * Matriks lengkap untuk ditampilkan sebagai tabel di halaman audit.
     *
     * @return array<string, array<string, list<string>>>
     */
    public static function matriksLengkap(): array
    {
        return self::MATRIKS;
    }

    /**
     * Peran yang boleh melakukan aksi tertentu pada modul tertentu.
     *
     * @return list<string>
     */
    public static function peranYangBoleh(string $modul, string $aksi): array
    {
        return array_values(array_filter(
            self::PERAN,
            fn ($p) => self::boleh($p, $modul, $aksi)
        ));
    }

    /**
     * Modul LAPORAN (turunan, hanya-baca) — di luar modul penyesuaian.
     *
     * Dipakai untuk menegakkan dan menguji janji pemisahan tugas: tidak satu
     * pun peran boleh mengubah/menghapus data lewat modul laporan.
     *
     * @return list<string>
     */
    public static function modulLaporan(): array
    {
        return array_values(array_filter(
            self::MODUL,
            fn ($m) => $m !== self::MODUL_PENYESUAIAN
        ));
    }

    /**
     * Apakah modul ini bersifat hanya-baca (tidak ada hak edit/hapus sama
     * sekali untuk peran mana pun).
     */
    public static function modulHanyaBaca(string $modul): bool
    {
        foreach (self::PERAN as $peran) {
            if (self::boleh($peran, $modul, self::EDIT) || self::boleh($peran, $modul, self::HAPUS)) {
                return false;
            }
        }

        return true;
    }
}
