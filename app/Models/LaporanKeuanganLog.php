<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Log audit modul Laporan Keuangan.
 *
 * Model ini bersifat APPEND-ONLY (hanya bisa ditambah, tidak bisa diubah atau
 * dihapus). Setiap baris disegel dengan rantai hash:
 *
 *     hash = SHA256( isi_baris + hash_baris_sebelumnya )
 *
 * Karena setiap hash ikut menyegel hash sebelumnya, menyunting atau menghapus
 * satu baris akan memutus rantai dan langsung terdeteksi oleh verifyChain().
 * Ini bukan pengganti kontrol akses, melainkan lapisan tambahan: walau
 * seseorang berhasil menulis langsung ke database, jejaknya tetap terbaca.
 */
class LaporanKeuanganLog extends Model
{
    protected $table = 'laporan_keuangan_logs';

    /** Log append-only — tidak ada kolom updated_at. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'user_nama',
        'role',
        'modul',
        'aktivitas',
        'deskripsi',
        'route',
        'metode',
        'url',
        'parameter',
        'perubahan_data',
        'ip_address',
        'user_agent',
        'status_http',
        'berhasil',
        'hash_sebelumnya_id',
        'hash_sebelumnya',
        'hash',
    ];

    protected $casts = [
        'parameter' => 'array',
        'perubahan_data' => 'array',
        'berhasil' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Label untuk tampilan
    // ─────────────────────────────────────────────────────────────────────

    /** Aktivitas khusus: pemeriksaan/penyegaran integritas rantai hash. */
    public const AKTIVITAS_VERIFIKASI = 'verifikasi';

    /** Daftar aktivitas yang dikenali modul Laporan Keuangan. */
    public const AKTIVITAS = [
        'lihat' => 'Lihat',
        'unduh' => 'Unduh',
        'edit' => 'Edit',
        'hapus' => 'Hapus',
        'ekspor' => 'Ekspor',
        self::AKTIVITAS_VERIFIKASI => 'Verifikasi',
    ];

    /** Warna badge per aktivitas agar laporan audit cepat dipindai mata. */
    public const WARNA_AKTIVITAS = [
        'lihat' => '#3b82f6',
        'unduh' => '#8b5cf6',
        'edit' => '#f59e0b',
        'hapus' => '#dc2626',
        'ekspor' => '#059669',
        self::AKTIVITAS_VERIFIKASI => '#0ea5e9',
    ];

    public function labelAktivitas(): string
    {
        return self::AKTIVITAS[$this->aktivitas] ?? ucfirst((string) $this->aktivitas);
    }

    public function warnaAktivitas(): string
    {
        return self::WARNA_AKTIVITAS[$this->aktivitas] ?? '#64748b';
    }

    // ─────────────────────────────────────────────────────────────────────
    // Append-only: tolak semua upaya ubah / hapus
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Cegah pembaruan baris log.
     *
     * Menggagalkan di level model, bukan hanya di controller, supaya tidak ada
     * jalur mana pun (tinker, seeder, kode baru) yang bisa menyunting jejak
     * audit tanpa disadari.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Log audit Laporan Keuangan bersifat append-only dan tidak dapat diubah.');
        });

        static::deleting(function () {
            throw new RuntimeException('Log audit Laporan Keuangan bersifat append-only dan tidak dapat dihapus.');
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Hash chain
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Susun string kanonik dari isi baris.
     *
     * Urutan field dikunci di satu tempat dan memakai pemisah '|' serta
     * menandai nilai null dengan penanda khusus, supaya dua baris berbeda
     * tidak pernah menghasilkan string yang sama.
     *
     * Kolom JSON (parameter & perubahan_data) di-encode lewat jsonKanonik()
     * agar urutan kuncinya tidak lagi memengaruhi hash. MySQL tidak menyimpan
     * urutan kunci objek JSON apa adanya — ia mengurutkannya sendiri menurut
     * panjang lalu abjad — sehingga tanpa pengurutan di sisi PHP, hash yang
     * dihitung saat menulis bisa berbeda dari yang dihitung saat memverifikasi.
     */
    public function stringUntukHash(): string
    {
        $bagian = [
            $this->id,
            $this->user_id,
            $this->user_nama,
            $this->role,
            $this->modul,
            $this->aktivitas,
            $this->deskripsi,
            $this->route,
            $this->metode,
            $this->url,
            self::jsonKanonik($this->parameter),
            self::jsonKanonik($this->perubahan_data),
            $this->ip_address,
            $this->user_agent,
            $this->status_http,
            $this->berhasil ? '1' : '0',
            $this->created_at?->format('Y-m-d H:i:s'),
            $this->hash_sebelumnya,
        ];

        return implode('|', array_map(fn ($v) => $v === null ? "\x00" : (string) $v, $bagian));
    }

    /**
     * Encode nilai menjadi JSON dengan kunci terurut secara rekursif.
     *
     * Hasilnya tidak bergantung pada urutan kunci saat array disusun, sehingga
     * baris yang isinya sama selalu menghasilkan hash yang sama.
     */
    private static function jsonKanonik(mixed $nilai): string
    {
        return (string) json_encode(
            self::urutkanKunci($nilai),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /** Urutkan kunci array secara rekursif; nilai skalar dibiarkan apa adanya. */
    private static function urutkanKunci(mixed $nilai): mixed
    {
        if (! is_array($nilai)) {
            return $nilai;
        }

        $hasil = [];
        foreach ($nilai as $kunci => $isi) {
            $hasil[$kunci] = self::urutkanKunci($isi);
        }

        ksort($hasil, SORT_STRING);

        return $hasil;
    }

    /** Hash baris ini, dirangkai dengan hash baris sebelumnya. */
    public function hitungHash(): string
    {
        return hash('sha256', $this->stringUntukHash());
    }

    /**
     * Ambil hash baris terakhir sebagai titik sambung rantai.
     *
     * Memakai query langsung (bukan model) agar tidak memicu event model dan
     * tetap aman dipanggil di dalam transaksi.
     */
    public static function ujungRantai(): array
    {
        $terakhir = DB::table('laporan_keuangan_logs')
            ->orderByDesc('id')
            ->first(['id', 'hash']);

        return [
            'id' => $terakhir->id ?? null,
            'hash' => $terakhir->hash ?? null,
        ];
    }

    /**
     * Tulis satu baris log baru lengkap dengan segelnya.
     *
     * Dilakukan dalam transaksi supaya pembacaan ujung rantai dan penulisan
     * baris baru tidak disela proses lain — kalau tidak, dua permintaan
     * bersamaan bisa memakai hash_sebelumnya yang sama dan merusak rantai.
     *
     * @param  array<string, mixed>  $atribut
     */
    public static function catat(array $atribut): self
    {
        return DB::transaction(function () use ($atribut) {
            $ujung = self::ujungRantai();

            $log = new self($atribut);
            $log->hash_sebelumnya_id = $ujung['id'];
            $log->hash_sebelumnya = $ujung['hash'];
            $log->created_at = $log->created_at ?? now();

            // `id` harus sudah ada sebelum hash dihitung karena id termasuk
            // field yang disegel. Simpan dulu tanpa hash, lalu segel.
            $log->hash = str_repeat('0', 64);
            $log->save();

            $log->hash = $log->hitungHash();
            $log->saveQuietly();   // lewati event agar tidak memicu guard updating

            return $log;
        });
    }

    /**
     * Periksa keutuhan seluruh rantai hash.
     *
     * @return array{utuh: bool, diperiksa: int, rusak_di: int|null, alasan: string|null}
     */
    public static function verifyChain(?int $batas = null): array
    {
        $query = static::query()->orderBy('id');
        if ($batas) {
            $query->limit($batas);
        }
        $baris = $query->get();

        $hashSebelumnya = null;
        $idSebelumnya = null;
        $diperiksa = 0;

        foreach ($baris as $log) {
            $diperiksa++;

            // 1. Sambungan ke baris sebelumnya harus cocok
            if ($log->hash_sebelumnya_id !== $idSebelumnya
                || $log->hash_sebelumnya !== $hashSebelumnya) {
                return [
                    'utuh' => false,
                    'diperiksa' => $diperiksa,
                    'rusak_di' => $log->id,
                    'alasan' => 'Rantai terputus: rujukan ke baris sebelumnya tidak cocok '
                                    .'(kemungkinan ada baris yang dihapus atau disisipkan).',
                ];
            }

            // 2. Isi baris harus masih menghasilkan hash yang tersimpan
            if (! hash_equals($log->hash, $log->hitungHash())) {
                return [
                    'utuh' => false,
                    'diperiksa' => $diperiksa,
                    'rusak_di' => $log->id,
                    'alasan' => 'Isi baris tidak cocok dengan segelnya '
                                    .'(kemungkinan baris disunting langsung di database).',
                ];
            }

            $hashSebelumnya = $log->hash;
            $idSebelumnya = $log->id;
        }

        return [
            'utuh' => true,
            'diperiksa' => $diperiksa,
            'rusak_di' => null,
            'alasan' => null,
        ];
    }
}
