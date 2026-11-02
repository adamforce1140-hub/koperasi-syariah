<?php

namespace App\Models;

use App\Support\Coa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * PEMETAAN AKAD SYARIAH → AKUN COA (berbasis data, dapat dikonfigurasi).
 *
 * Satu baris memetakan sebuah AKAD (opsional: untuk satu produk tertentu)
 * ke tiga akun CoA:
 *
 *   - `account_piutang_id`              → akun piutang/penyertaan
 *   - `account_pendapatan_id`           → akun pendapatan (margin/bagi hasil/ujrah)
 *   - `account_margin_ditangguhkan_id`  → akun margin ditangguhkan (PSAK 108)
 *
 * Mengapa tabel ini ada: daftar AKAD harus dapat bertambah (akad turunan,
 * akad baru hasil fatwa DSN-MUI) tanpa mengubah kode sumber. Kelas ini juga
 * menjadi sumber tunggal daftar akad (`daftarAkad()`) yang MENGGABUNGKAN:
 *
 *   1. akad kanonik bawaan `App\Models\Pembiayaan::daftarAkad()` (selalu ada),
 *   2. akad tambahan yang sudah punya baris pemetaan di tabel ini.
 *
 * Registri `App\Support\Coa` tetap menjadi fallback standar ketika pemetaan
 * berbasis data kosong atau menunjuk akun nonaktif (zero-hardcoding).
 */
class PemetaanAkadCoa extends Model
{
    protected $table = 'pemetaan_akad_coa';

    /** Kode produk universal: berlaku untuk semua produk pada akad tersebut. */
    public const SEMUA_PRODUK = '*';

    protected $fillable = [
        'kode_produk',
        'akad_pembiayaan',
        'account_piutang_id',
        'account_pendapatan_id',
        'account_margin_ditangguhkan_id',
        'status_aktif',
        'catatan',
    ];

    protected $casts = [
        'status_aktif' => 'boolean',
        'account_piutang_id' => 'integer',
        'account_pendapatan_id' => 'integer',
        'account_margin_ditangguhkan_id' => 'integer',
    ];

    /** Kunci cache peta akad aktif (dikelola kelas ini, dibersihkan saat simpan). */
    public const CACHE_KEY = 'pemetaan_akad_coa.aktif';

    /** TTL cache peta akad, dalam detik. */
    public const CACHE_TTL = 1800;

    /** Cache statis per-request daftar akad (menghindari query berulang). */
    private static ?array $cacheAkad = null;

    /**
     * Setiap perubahan pemetaan WAJIB membersihkan cache agar daftar akad &
     * resolusi akun langsung mengikuti data terbaru (tanpa deploy / restart).
     */
    protected static function booted(): void
    {
        $bersihkan = static fn () => static::flush();

        static::saved($bersihkan);
        static::deleted($bersihkan);
    }

    /* ── Relasi ───────────────────────────────────────────────────────── */

    public function akunPiutang(): BelongsTo
    {
        return $this->belongsTo(Coa::class, 'account_piutang_id');
    }

    public function akunPendapatan(): BelongsTo
    {
        return $this->belongsTo(Coa::class, 'account_pendapatan_id');
    }

    public function akunMarginDitangguhkan(): BelongsTo
    {
        return $this->belongsTo(Coa::class, 'account_margin_ditangguhkan_id');
    }

    /* ── Daftar akad dinamis ──────────────────────────────────────────── */

    /**
     * Seluruh akad syariah yang dikenali sistem: akad kanonik bawaan + akad
     * tambahan hasil pemetaan pengurus. Selalu minimal berisi akad bawaan.
     *
     * @return array<int, string>
     */
    public static function daftarAkad(): array
    {
        if (self::$cacheAkad !== null) {
            return self::$cacheAkad;
        }

        $daftar = Pembiayaan::daftarAkadKanonik();

        foreach (self::peta() as $baris) {
            $nama = $baris['akad_pembiayaan'] ?? null;

            if (is_string($nama) && $nama !== '' && ! in_array($nama, $daftar, true)) {
                $daftar[] = $nama;
            }
        }

        return self::$cacheAkad = $daftar;
    }

    /* ── Resolusi akun per akad/produk (dipakai view kebijakan) ───────── */

    /**
     * Ambil baris pemetaan untuk akad & kode produk tertentu.
     *
     * Urutan prioritas: baris khusus produk, lalu baris universal ('*').
     * Bila tidak ada, kembalikan baris kosong yang tetap dapat disimpan.
     */
    public static function untukAkad(string $akad, string $kodeProduk = self::SEMUA_PRODUK): self
    {
        $kunciAkad = self::kunciAkad($akad);

        foreach (self::peta() as $baris) {
            if (($baris['kode_produk'] ?? '') === $kodeProduk
                && self::kunciAkad((string) ($baris['akad_pembiayaan'] ?? '')) === $kunciAkad) {
                return new self($baris + ['exists' => true]);
            }
        }

        foreach (self::peta() as $baris) {
            if (($baris['kode_produk'] ?? '') === self::SEMUA_PRODUK
                && self::kunciAkad((string) ($baris['akad_pembiayaan'] ?? '')) === $kunciAkad) {
                return new self($baris + ['exists' => true]);
            }
        }

        return new self([
            'kode_produk' => $kodeProduk,
            'akad_pembiayaan' => $akad,
            'status_aktif' => true,
        ]);
    }

    /* ── Pembacaan peta (cached) ──────────────────────────────────────── */

    /**
     * Peta akad aktif sebagai array baris (di-cache agar hemat query).
     *
     * Aman dipanggil walau tabel belum dimigrasikan (mengembalikan peta kosong
     * sehingga sistem memakai registri standar `App\Support\Coa`).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function peta(): array
    {
        try {
            if (! Schema::hasTable('pemetaan_akad_coa')) {
                return [];
            }

            return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
                return self::query()
                    ->where('status_aktif', true)
                    ->orderByRaw("CASE WHEN kode_produk = '*' THEN 0 ELSE 1 END")
                    ->orderBy('id')
                    ->get([
                        'id', 'kode_produk', 'akad_pembiayaan',
                        'account_piutang_id', 'account_pendapatan_id',
                        'account_margin_ditangguhkan_id', 'status_aktif', 'catatan',
                    ])
                    ->toArray();
            });
        } catch (\Throwable $e) {
            Log::warning('PemetaanAkadCoa: gagal membaca pemetaan akad, memakai registri standar.', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /** Bersihkan cache peta + cache daftar akad. Panggil setiap kali data berubah. */
    public static function flush(): void
    {
        self::$cacheAkad = null;
        Cache::forget(self::CACHE_KEY);
    }

    /* ── Utilitas ─────────────────────────────────────────────────────── */

    /** Normalisasi kunci akad (huruf kecil, spasi tunggal). */
    private static function kunciAkad(string $akad): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $akad) ?? ''));
    }
}
