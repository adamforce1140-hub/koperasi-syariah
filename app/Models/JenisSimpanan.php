<?php

namespace App\Models;

use App\Support\Coa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Master parameter Jenis Simpanan (dinamis, tanpa ubah kode inti).
 *
 * Menjadi pintu masuk tunggal pemetaan "jenis simpanan → kode akun COA".
 * Seluruh modul yang bergantung pada klasifikasi simpanan (jurnal otomatis,
 * pembukaan rekening, dropdown CIF) membaca dari sini sehingga perubahan
 * parameter langsung berlaku di semua modul.
 *
 * Pemakaian:
 *   JenisSimpanan::coaKode('Simpanan Pokok');   // '3-1001'
 *   JenisSimpanan::daftarAktif();               // ['Simpanan Pokok', ...]
 *   JenisSimpanan::aktif('Simpanan Wajib');     // bool
 */
class JenisSimpanan extends Model
{
    protected $table = 'jenis_simpanans';

    /** Kunci cache tunggal daftar jenis simpanan. */
    public const CACHE_KEY = 'jenis_simpanan.master';

    /** Masa hidup cache (detik). */
    public const CACHE_TTL = 300;

    /* ── Pengelompokan tipe (untuk pemilihan akun default & perilaku alur) ── */

    public const TIPE_POKOK = 'pokok';

    public const TIPE_WAJIB = 'wajib';

    public const TIPE_SUKARELA = 'sukarela';

    public const TIPE_BERJANGKA = 'berjangka';

    public const TIPE_LAIN = 'lain';

    /* ── Nama baku produk simpanan (fallback & alias historis) ─────────── */

    public const NAMA_POKOK = 'Simpanan Pokok';

    public const NAMA_WAJIB = 'Simpanan Wajib';

    public const NAMA_SUKARELA = 'Simpanan Sukarela';

    public const NAMA_BERJANGKA = 'Simpanan Berjangka';

    /**
     * Nama historis yang masih dipakai data lama untuk sebuah tipe produk.
     *
     * Dipakai saat menyusun filter `rekening.jenis_rekening` agar rekening
     * lama (mis. "Wadiah", "Tabungan") tetap terbaca setelah nama produk
     * diubah di master. Nama aktif dari tabel SELALU ikut disertakan.
     *
     * @var array<string, list<string>>
     */
    public const ALIAS_TIPE = [
        self::TIPE_POKOK => [self::NAMA_POKOK],
        self::TIPE_WAJIB => [self::NAMA_WAJIB],
        self::TIPE_SUKARELA => [self::NAMA_SUKARELA, 'Tabungan', 'Wadiah'],
        self::TIPE_BERJANGKA => [self::NAMA_BERJANGKA, 'Deposito', 'Simpanan Berjangka / Deposito'],
    ];

    /* ── Kode baku produk simpanan (standar konfigurasi dinamis) ────────── */

    public const CODE_POKOK = 'POKOK';

    public const CODE_WAJIB = 'WAJIB';

    public const CODE_SUKARELA = 'SUKARELA';

    /**
     * Kode produk yang diizinkan sistem (Zero-Hardcoding terkendali).
     * Nilai lain ditolak pada lapisan validasi.
     *
     * @var list<string>
     */
    public const CODE_DIIZINKAN = [
        self::CODE_POKOK,
        self::CODE_WAJIB,
        self::CODE_SUKARELA,
    ];

    /**
     * NILAI CADANGAN produk yang boleh mengaktifkan auto-debit pindah buku.
     *
     * Daftar berlaku dibaca dari `config/simpanan.php` (`auto_debit_kode`);
     * konstanta ini hanya dipakai bila konfigurasi tidak tersedia. Dengan
     * begitu menambah produk yang boleh auto-debit cukup mengubah konfigurasi
     * — tanpa menyentuh kode (DYN-15).
     *
     * @var list<string>
     */
    public const CODE_BOLEH_AUTO_DEBIT = [
        self::CODE_WAJIB,
    ];

    /** @var array<string, string> */
    public const LABEL_TIPE = [
        self::TIPE_POKOK => 'Simpanan Pokok (modal)',
        self::TIPE_WAJIB => 'Simpanan Wajib (modal)',
        self::TIPE_SUKARELA => 'Simpanan Sukarela (titipan)',
        self::TIPE_BERJANGKA => 'Simpanan Berjangka / Deposito',
        self::TIPE_LAIN => 'Lain-lain',
    ];

    /**
     * Fallback bila tabel master belum terisi / belum dimigrasi.
     * Menjaga sistem lama tetap berjalan tanpa error.
     *
     * @var array<string, array{code: string, tipe: string, kode_akun: string, min_deposit_amount: int, allow_auto_debit: bool}>
     */
    public const FALLBACK = [
        self::NAMA_POKOK => ['code' => self::CODE_POKOK,    'tipe' => self::TIPE_POKOK,     'kode_akun' => Coa::SIMPANAN_POKOK,        'min_deposit_amount' => 100000, 'allow_auto_debit' => false],
        // PSAK 101 — Simpanan Wajib disajikan sebagai MODAL ANGGOTA (ekuitas),
        // sehingga akun kanonik = 3-1002 (sebelumnya keliru 2-1002 kewajiban).
        self::NAMA_WAJIB => ['code' => self::CODE_WAJIB,    'tipe' => self::TIPE_WAJIB,     'kode_akun' => Coa::SIMPANAN_WAJIB_MODAL,  'min_deposit_amount' => 50000,  'allow_auto_debit' => false],
        self::NAMA_SUKARELA => ['code' => self::CODE_SUKARELA, 'tipe' => self::TIPE_SUKARELA,  'kode_akun' => Coa::SIMPANAN_SUKARELA,     'min_deposit_amount' => 0,      'allow_auto_debit' => false],
        self::NAMA_BERJANGKA => ['code' => 'BERJANGKA',         'tipe' => self::TIPE_BERJANGKA, 'kode_akun' => Coa::SIMPANAN_BERJANGKA,    'min_deposit_amount' => 0,      'allow_auto_debit' => false],
    ];

    protected $fillable = [
        'code',
        'name',
        'nama',
        'kode_akun',
        'tipe',
        'min_deposit_amount',
        'allow_auto_debit',
        'account_coa_id',
        'is_active',
        'keterangan',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'allow_auto_debit' => 'boolean',
        'min_deposit_amount' => 'integer',
    ];

    /** Label manusiawi tiap kolom — untuk tampilan riwayat perubahan. */
    public const LABEL_KOLOM = [
        'code' => 'Kode Produk',
        'name' => 'Nama Resmi Produk',
        'nama' => 'Nama Jenis Simpanan',
        'kode_akun' => 'Kode Akun COA',
        'tipe' => 'Tipe / Pengelompokan',
        'min_deposit_amount' => 'Nominal Minimal Setoran',
        'allow_auto_debit' => 'Izin Auto-Debit',
        'account_coa_id' => 'Akun COA Terkait',
        'is_active' => 'Status Aktif',
        'keterangan' => 'Keterangan',
    ];

    /** @var list<string> */
    public const KOLOM_AUDIT = [
        'code', 'name', 'nama', 'kode_akun', 'tipe',
        'min_deposit_amount', 'allow_auto_debit', 'account_coa_id',
        'is_active', 'keterangan',
    ];

    /* ── Event model ───────────────────────────────────────────────────── */

    /**
     *  - `saved`   → invalidasi cache + catat log aktivitas perubahan.
     *  - `deleted` → invalidasi cache + catat log penghapusan.
     */
    protected static function booted(): void
    {
        static::saved(function (self $jenis) {
            static::flush();

            try {
                $jenis->catatRiwayat();
            } catch (\Throwable $e) {
                // Log audit bersifat tambahan — jangan ganggu transaksi utama.
                Log::warning('Gagal mencatat log perubahan jenis simpanan: '.$e->getMessage());
            }
        });

        static::deleted(function (self $jenis) {
            static::flush();

            try {
                static::catatLog([
                    // Baris master sudah terhapus → FK `nullOnDelete` tidak
                    // mengizinkan referensi ke id yang tak ada lagi. Jejak audit
                    // dijaga lewat snapshot `nama_jenis`.
                    'jenis_simpanan_id' => null,
                    'nama_jenis' => $jenis->nama,
                    'aksi' => 'DELETE',
                    'kolom' => 'nama',
                    'nilai_lama' => $jenis->nama,
                    'nilai_baru' => null,
                    'keterangan' => 'Jenis simpanan dihapus',
                ]);
            } catch (\Throwable $e) {
                Log::warning('Gagal mencatat log penghapusan jenis simpanan: '.$e->getMessage());
            }
        });
    }

    /* ── Log aktivitas (audit trail) ───────────────────────────────────── */

    /**
     * Catat setiap kolom yang berubah ke `jenis_simpanan_logs`.
     * Hanya kolom pada KOLOM_AUDIT yang dipantau.
     */
    public function catatRiwayat(?string $keterangan = null): void
    {
        if (! Schema::hasTable('jenis_simpanan_logs')) {
            return;
        }

        $perubahan = [];

        foreach (static::KOLOM_AUDIT as $kolom) {
            if (! $this->isDirty($kolom) && $this->exists) {
                continue;
            }

            $lama = $this->getOriginal($kolom);
            $baru = $this->getAttribute($kolom);

            if ($this->exists && static::normalisasi($lama, $kolom) === static::normalisasi($baru, $kolom)) {
                continue;
            }

            $perubahan[$kolom] = [$lama, $baru];
        }

        if (empty($perubahan)) {
            return;
        }

        $aksi = $this->wasRecentlyCreated ? 'CREATE' : 'UPDATE';

        foreach ($perubahan as $kolom => [$lama, $baru]) {
            static::catatLog([
                'jenis_simpanan_id' => $this->id,
                'nama_jenis' => $this->nama,
                'aksi' => $aksi,
                'kolom' => $kolom,
                'nilai_lama' => static::formatNilai($kolom, $lama),
                'nilai_baru' => static::formatNilai($kolom, $baru),
                'keterangan' => $keterangan
                    ?? ($aksi === 'CREATE' ? 'Jenis simpanan baru ditambahkan' : null)
                    ?? (static::LABEL_KOLOM[$kolom] ?? $kolom).' diperbarui',
            ]);
        }
    }

    /** Tulis satu baris log aktivitas. */
    protected static function catatLog(array $data): void
    {
        if (! Schema::hasTable('jenis_simpanan_logs')) {
            return;
        }

        $user = Auth::user();
        $waktu = now();

        DB::table('jenis_simpanan_logs')->insert([
            'jenis_simpanan_id' => $data['jenis_simpanan_id'] ?? null,
            'nama_jenis' => $data['nama_jenis'] ?? null,
            'user_id' => $user->id ?? null,
            'nama_user' => $user->name ?? 'Sistem',
            'role_user' => $user->role ?? null,
            'aksi' => $data['aksi'] ?? 'UPDATE',
            'kolom' => $data['kolom'] ?? 'nama',
            'nilai_lama' => $data['nilai_lama'] ?? null,
            'nilai_baru' => $data['nilai_baru'] ?? null,
            'keterangan' => $data['keterangan'] ?? null,
            'ip_address' => Request::ip(),
            'created_at' => $waktu,
            'updated_at' => $waktu,
        ]);
    }

    /** Riwayat perubahan terbaru (default 50 baris). */
    public static function riwayatPerubahan(int $limit = 50)
    {
        if (! Schema::hasTable('jenis_simpanan_logs')) {
            return collect();
        }

        return DB::table('jenis_simpanan_logs')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** Normalkan nilai agar perbandingan tidak memicu perubahan palsu. */
    private static function normalisasi(mixed $nilai, ?string $kolom = null): string
    {
        if ($nilai === null) {
            return '';
        }

        if ($kolom === 'is_active' || is_bool($nilai)) {
            return filter_var($nilai, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        if (is_numeric($nilai)) {
            return rtrim(rtrim(number_format((float) $nilai, 2, '.', ''), '0'), '.') ?: '0';
        }

        return trim((string) $nilai);
    }

    /** Tampilan nilai untuk riwayat. */
    private static function formatNilai(string $kolom, mixed $nilai): ?string
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        if ($kolom === 'is_active') {
            return filter_var($nilai, FILTER_VALIDATE_BOOLEAN) ? 'Aktif' : 'Nonaktif';
        }

        if ($kolom === 'tipe') {
            return static::LABEL_TIPE[$nilai] ?? (string) $nilai;
        }

        return (string) $nilai;
    }

    /* ── Resolver terpusat (dipakai seluruh modul) ─────────────────────── */

    /**
     * Seluruh jenis simpanan ter-cache sebagai collection of array.
     * Bila tabel belum ada, kembalikan fallback agar sistem lama tetap jalan.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function semua(): Collection
    {
        return Cache::remember(static::CACHE_KEY, static::CACHE_TTL, function () {
            try {
                $rows = static::query()->orderBy('tipe')->orderBy('nama')->get();
            } catch (\Throwable $e) {
                // Tabel belum tersedia → fallback; sebab lain tetap dicatat.
                Log::warning('JenisSimpanan: gagal membaca master jenis simpanan, memakai fallback.', [
                    'error' => $e->getMessage(),
                ]);

                $rows = collect();
            }

            if ($rows->isEmpty()) {
                return collect(static::FALLBACK)->map(
                    fn (array $data, string $nama) => [
                        'id' => null,
                        'code' => $data['code'],
                        'name' => $nama,
                        'nama' => $nama,
                        'kode_akun' => $data['kode_akun'],
                        'tipe' => $data['tipe'],
                        'min_deposit_amount' => $data['min_deposit_amount'],
                        'allow_auto_debit' => $data['allow_auto_debit'],
                        'account_coa_id' => null,
                        'is_active' => true,
                        'keterangan' => 'Fallback bawaan sistem',
                    ]
                )->values();
            }

            return $rows->map(fn (self $row) => [
                'id' => $row->id,
                'code' => $row->code,
                'name' => $row->name ?: $row->nama,
                'nama' => $row->nama,
                'kode_akun' => $row->kode_akun,
                'tipe' => $row->tipe,
                'min_deposit_amount' => (int) ($row->min_deposit_amount ?? 0),
                'allow_auto_debit' => (bool) ($row->allow_auto_debit ?? false),
                'account_coa_id' => $row->account_coa_id !== null ? (int) $row->account_coa_id : null,
                'is_active' => (bool) $row->is_active,
                'keterangan' => $row->keterangan,
            ])->values();
        });
    }

    /** Daftar nama jenis simpanan yang aktif saja. */
    public static function daftarAktif(): array
    {
        return static::semua()
            ->where('is_active', true)
            ->pluck('nama')
            ->all();
    }

    /** Daftar seluruh nama jenis simpanan (termasuk nonaktif). */
    public static function daftarSemua(): array
    {
        return static::semua()->pluck('nama')->all();
    }

    /** Cari definisi satu jenis simpanan berdasarkan nama. */
    public static function cari(string $nama): ?array
    {
        return static::semua()->firstWhere('nama', $nama);
    }

    /** Apakah jenis simpanan terdaftar dan berstatus aktif? */
    public static function aktif(string $nama): bool
    {
        $data = static::cari($nama);

        return $data !== null && $data['is_active'] === true;
    }

    /**
     * Kode akun COA terkait sebuah jenis simpanan.
     *
     * Hanya mengembalikan kode bila jenis simpanan terdaftar & aktif.
     * Bila jenis belum terdaftar sama sekali, pakai fallback bawaan supaya
     * alur lama (data historis) tetap dapat dijurnal.
     */
    public static function coaKode(string $nama): ?string
    {
        $data = static::cari($nama);

        if ($data === null) {
            return static::FALLBACK[trim($nama)]['kode_akun'] ?? null;
        }

        if ($data['is_active'] !== true) {
            return null;
        }

        return $data['kode_akun'] ?: null;
    }

    /** Tipe pengelompokan sebuah jenis simpanan. */
    public static function tipe(string $nama): ?string
    {
        return static::cari($nama)['tipe'] ?? null;
    }

    /* ── Resolver konfigurasi produk (kode, nominal, auto-debit, COA) ──── */

    /**
     * Definisi produk berdasarkan KODE baku (POKOK | WAJIB | SUKARELA).
     *
     * @return array<string, mixed>|null
     */
    public static function cariKode(string $code): ?array
    {
        $code = strtoupper(trim($code));

        return static::semua()->first(
            fn (array $row) => strtoupper((string) ($row['code'] ?? '')) === $code
        );
    }

    /**
     * Definisi produk berdasarkan NAMA, dengan fallback pencocokan via tipe
     * bila `code` belum terisi pada baris tersebut.
     *
     * @return array<string, mixed>|null
     */
    public static function produk(string $namaAtauKode): ?array
    {
        return static::cariKode($namaAtauKode) ?? static::cari($namaAtauKode);
    }

    /** Kode baku (POKOK | WAJIB | SUKARELA) sebuah produk. */
    public static function kode(string $nama): ?string
    {
        $data = static::cari($nama);

        if ($data === null) {
            return static::FALLBACK[trim($nama)]['code'] ?? null;
        }

        return $data['code'] ?: null;
    }

    /**
     * Nominal minimal setoran bulanan/awal sebuah produk (rupiah bulat).
     *
     * @param  int  $default  Nilai cadangan bila produk tidak terdaftar.
     */
    public static function minimalSetoran(string $nama, int $default = 0): int
    {
        $data = static::cari($nama);

        if ($data === null) {
            return (int) (static::FALLBACK[trim($nama)]['min_deposit_amount'] ?? $default);
        }

        return (int) ($data['min_deposit_amount'] ?? $default);
    }

    /** Nominal minimal setoran Simpanan Wajib (dipakai job jatuh tempo). */
    public static function minimalSetoranWajib(int $default = 50000): int
    {
        return static::minimalSetoran(static::namaUtama(static::TIPE_WAJIB, self::NAMA_WAJIB), $default);
    }

    /** Nominal minimal setoran Simpanan Pokok (setoran awal keanggotaan). */
    public static function minimalSetoranPokok(int $default = 100000): int
    {
        return static::minimalSetoran(static::namaUtama(static::TIPE_POKOK, self::NAMA_POKOK), $default);
    }

    /* ── Nama produk untuk filter `rekening.jenis_rekening` ────────────── */

    /**
     * Nama produk AKTIF pertama untuk sebuah tipe (mengikuti master).
     *
     * Dipakai modul yang sebelumnya menulis satu nama literal, sehingga
     * penggantian nama produk di master langsung berlaku tanpa ubah kode.
     */
    public static function namaUtama(string $tipe, ?string $default = null): string
    {
        $nama = static::aktifBertipe($tipe);
        $kanonik = $default ?? (static::ALIAS_TIPE[$tipe][0] ?? '');

        // Pertahankan perilaku lama: bila produk baku masih aktif, pakai itu
        // meskipun ada produk lain bertipe sama yang urutannya lebih awal.
        if ($kanonik !== '' && in_array($kanonik, $nama, true)) {
            return $kanonik;
        }

        return $nama[0] ?? $kanonik;
    }

    /**
     * Seluruh nama `rekening.jenis_rekening` yang mewakili sebuah tipe:
     * nama aktif dari master + alias historis (data lama).
     *
     * @return list<string>
     */
    public static function namaRekening(string $tipe): array
    {
        return array_values(array_unique(array_filter(array_merge(
            static::aktifBertipe($tipe),
            static::ALIAS_TIPE[$tipe] ?? []
        ))));
    }

    /**
     * Apakah PRODUK mengizinkan auto-debit pindah buku (level konfigurasi).
     *
     * Ini baru syarat pertama; auto-debit tetap memerlukan persetujuan
     * per-anggota (`anggota.is_auto_debit`). Lihat `bolehAutoDebit()`.
     */
    public static function produkIzinkanAutoDebit(string $nama): bool
    {
        $data = static::cari($nama);

        if ($data === null) {
            return (bool) (static::FALLBACK[trim($nama)]['allow_auto_debit'] ?? false);
        }

        return ($data['allow_auto_debit'] ?? false) === true;
    }

    /**
     * Syarat lengkap auto-debit untuk seorang anggota pada produk tertentu:
     * produk mengizinkan DAN anggota menyetujui.
     *
     * @param  bool  $anggotaSetuju  Nilai `anggota.is_auto_debit`.
     */
    public static function bolehAutoDebit(string $namaProduk, bool $anggotaSetuju): bool
    {
        return static::produkIzinkanAutoDebit($namaProduk) && $anggotaSetuju;
    }

    /**
     * Daftar kode produk yang berhak mengaktifkan auto-debit.
     *
     * Sumber utama `config/simpanan.php`; konstanta hanya cadangan.
     *
     * @return list<string>
     */
    public static function kodeAutoDebitDiizinkan(): array
    {
        $daftar = config('simpanan.auto_debit_kode', static::CODE_BOLEH_AUTO_DEBIT);

        if (! is_array($daftar) || $daftar === []) {
            $daftar = static::CODE_BOLEH_AUTO_DEBIT;
        }

        return array_values(array_unique(array_map(
            static fn ($kode) => strtoupper(trim((string) $kode)),
            $daftar
        )));
    }

    /** Apakah produk (berdasarkan kode/tipe) berhak mengaktifkan auto-debit? */
    public static function kodeBolehAutoDebit(?string $code): bool
    {
        return $code !== null
            && in_array(strtoupper(trim($code)), static::kodeAutoDebitDiizinkan(), true);
    }

    /**
     * ID baris `coas` yang terikat pada sebuah produk (integritas referensial).
     * Null bila belum dikonfigurasi.
     */
    public static function coaId(string $nama): ?int
    {
        $data = static::cari($nama);

        return isset($data['account_coa_id']) && $data['account_coa_id'] !== null
            ? (int) $data['account_coa_id']
            : null;
    }

    /**
     * Kode akun COA aktual produk — diprioritaskan dari relasi `account_coa_id`
     * (sumber kebenaran baru), lalu jatuh ke kolom `kode_akun` (kompatibilitas).
     */
    public static function coaKodeProduk(string $nama): ?string
    {
        $coaId = static::coaId($nama);

        if ($coaId !== null) {
            try {
                $kode = \App\Models\Coa::whereKey($coaId)->value('kode_akun');

                if ($kode !== null) {
                    return $kode;
                }
            } catch (\Throwable $e) {
                Log::warning('JenisSimpanan: gagal resolve COA dari account_coa_id.', [
                    'coa_id' => $coaId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return static::coaKode($nama);
    }

    /* ── Kepemilikan tunggal rekening (anti-duplikasi) ─────────────────── */

    /**
     * Tipe simpanan yang HANYA boleh dimiliki satu kali oleh seorang anggota.
     *
     * Simpanan Pokok dan Simpanan Wajib adalah setoran keanggotaan (ekuitas):
     * satu anggota hanya memiliki satu rekening Pokok dan satu rekening Wajib.
     * Simpanan Sukarela/Berjangka boleh berulang (multi-rekening), sehingga
     * tidak termasuk di sini.
     *
     * Dipakai sebagai SUMBER TUNGGAL oleh dua lapis pertahanan:
     *  - Frontend (Blade `<select>`): menandai opsi "(sudah ada)" + `disabled`.
     *  - Backend (controller): menolak permintaan yang menembus UI (API call,
     *    POST langsung, atau modifikasi DOM) dengan pesan yang dapat dibaca.
     *
     * @var list<string>
     */
    public const TIPE_TUNGGAL = [
        self::TIPE_POKOK,
        self::TIPE_WAJIB,
    ];

    /** Apakah jenis simpanan (berdasarkan tipenya) hanya boleh dimiliki sekali? */
    public static function hanyaSatuPerAnggota(string $nama): bool
    {
        $tipe = static::tipe($nama);

        return $tipe !== null && in_array($tipe, static::TIPE_TUNGGAL, true);
    }

    /**
     * Daftar jenis simpanan yang SUDAH dimiliki anggota pada rekening aktif.
     *
     * Menjadi acuan bersama UI (menandai "(sudah ada)") dan validasi backend,
     * sehingga keduanya tidak mungkin berbeda. Pemeriksaan memakai status
     * 'Aktif' — rekening yang ditutup/di-soft-delete membebaskan kembali opsi.
     *
     * @param  Collection|iterable|null  $rekeningAnggota
     * @return list<string>
     */
    public static function jenisSudahDimiliki(int $anggotaId, $rekeningAnggota = null): array
    {
        $rekening = $rekeningAnggota ?? Rekening::where('anggota_id', $anggotaId)->get();

        return collect($rekening)
            ->filter(fn ($r) => ($r->status_rekening ?? null) === 'Aktif')
            ->pluck('jenis_rekening')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Nama jenis simpanan berstatus tunggal yang sudah dimiliki anggota.
     *
     * Hanya mengembalikan Pokok/Wajib (lihat `TIPE_TUNGGAL`) — inilah daftar
     * yang harus diblokir baik di UI maupun di backend.
     *
     * @param  Collection|iterable|null  $rekeningAnggota
     * @return list<string>
     */
    public static function jenisTunggalSudahDimiliki(int $anggotaId, $rekeningAnggota = null): array
    {
        return array_values(array_filter(
            static::jenisSudahDimiliki($anggotaId, $rekeningAnggota),
            fn (string $nama) => static::hanyaSatuPerAnggota($nama)
        ));
    }

    /**
     * Pesan penolakan seragam saat anggota mencoba membuka rekening tunggal
     * yang sudah ada. Dipakai bersamaan oleh UI & validasi backend.
     */
    public static function pesanSudahAda(string $nama): string
    {
        return 'Anggota sudah memiliki rekening '.$nama.' yang aktif. '
            .'Setiap anggota hanya boleh memiliki satu '.$nama.' — '
            .'pembukaan rekening kedua tidak diizinkan.';
    }

    /**
     * Kode akun COA dari jenis simpanan AKTIF pertama yang bertipe tertentu.
     *
     * Dipakai modul yang hanya peduli TIPE (mis. konfigurasi nisbah form CIF),
     * sehingga perubahan parameter pemetaan akun sebuah tipe langsung berlaku
     * tanpa menyebut nama jenis di kode.
     */
    public static function coaKodeBertipe(string $tipe): ?string
    {
        $row = static::semua()
            ->where('is_active', true)
            ->where('tipe', $tipe)
            ->first(fn (array $data) => ! empty($data['kode_akun']));

        if ($row !== null) {
            return $row['kode_akun'];
        }

        // Fallback bawaan sistem untuk tipe yang belum punya jenis aktif.
        foreach (static::FALLBACK as $data) {
            if ($data['tipe'] === $tipe) {
                return $data['kode_akun'];
            }
        }

        return null;
    }

    /**
     * Jenis simpanan yang boleh menerima pencairan pembiayaan.
     *
     * Gabungan tipe `sukarela` dan `lain` — dibaca dinamis dari master,
     * tanpa hardcode nama produk. Dipakai bersama oleh modul pencairan
     * (teller & pengajuan) sebagai satu sumber definisi.
     *
     * @return list<string>
     */
    public static function namaPencairan(): array
    {
        return array_values(array_unique(array_merge(
            static::aktifBertipe(static::TIPE_SUKARELA),
            static::aktifBertipe(static::TIPE_LAIN),
        )));
    }

    /** Daftar jenis simpanan aktif dengan tipe tertentu. */
    public static function aktifBertipe(string $tipe): array
    {
        return static::semua()
            ->where('is_active', true)
            ->where('tipe', $tipe)
            ->pluck('nama')
            ->all();
    }

    /**
     * Peta nama jenis simpanan → kode akun COA (hanya yang aktif).
     *
     * @return array<string, string>
     */
    public static function petaKodeAkun(): array
    {
        return static::semua()
            ->where('is_active', true)
            ->filter(fn (array $row) => ! empty($row['kode_akun']))
            ->pluck('kode_akun', 'nama')
            ->all();
    }

    /* ── Jembatan ke tabel akun legacy (rekening.akun_id) ──────────────── */

    /**
     * Pemetaan kode akun COA → kode akun legacy (tabel `akun`, AkunSeeder).
     *
     * Sistem memiliki DUA tabel akun paralel: `coas` (PSAK, dipakai seluruh
     * service jurnal) dan `akun` (legacy, target FK `rekening.akun_id`).
     * Jembatan ini membuat pembukaan rekening tetap dinamis: mengubah akun COA
     * sebuah jenis simpanan otomatis mengarah ke akun legacy yang setara.
     *
     * @var array<string, string>
     */
    public const AKUN_LEGACY_BY_KODE_COA = Coa::LEGACY_SIMPANAN_BY_COA;

    /**
     * Akun legacy cadangan bila kode COA sebuah jenis simpanan belum punya
     * padanan eksplisit (mis. jenis baru buatan Pengurus). 1133 = akun
     * "Simpanan Sukarela Anggota", induk generik seluruh rekening simpanan.
     */
    public const AKUN_LEGACY_DEFAULT = Coa::LEGACY_SIMPANAN_DEFAULT;

    /**
     * ID akun legacy (`akun.id`) untuk sebuah jenis simpanan.
     *
     * Dipakai saat membuat baris `rekening` yang mewajibkan `akun_id`.
     * Mengembalikan null bila jenis tidak aktif / tidak ada padanannya.
     */
    public static function akunLegacyId(string $nama): ?int
    {
        $kodeCoa = static::coaKode($nama);

        if ($kodeCoa === null) {
            return null;
        }

        $kodeLegacy = static::AKUN_LEGACY_BY_KODE_COA[$kodeCoa] ?? static::AKUN_LEGACY_DEFAULT;

        try {
            return Akun::where('kode_akun', $kodeLegacy)->value('id');
        } catch (\Throwable $e) {
            // Resolver legacy bersifat opsional (kembalikan null bila tidak
            // ada) — namun kegagalan query tetap dicatat agar tidak senyap.
            Log::warning('JenisSimpanan: gagal resolve akun legacy.', [
                'kode_akun' => $kodeLegacy,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** Invalidasi cache master — dipanggil otomatis pada event saved/deleted. */
    public static function flush(): void
    {
        Cache::forget(static::CACHE_KEY);
    }
}
