<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Jejak audit transaksi & peristiwa operasional (mutasi rekening, login,
 * persetujuan, pelanggaran guard, dsb).
 *
 * Model ini bersifat APPEND-ONLY (hanya bisa ditambah, tidak bisa diubah atau
 * dihapus). Setiap baris disegel dengan rantai hash:
 *
 *     hash = SHA256( isi_baris + hash_baris_sebelumnya )
 *
 * Karena setiap hash ikut menyegel hash sebelumnya, menyunting atau menghapus
 * satu baris akan memutus rantai dan langsung terdeteksi oleh verifyChain().
 * Ini lapisan tambahan di atas kontrol akses: walau seseorang berhasil menulis
 * langsung ke database, jejak penyuntingannya tetap terbaca.
 *
 * Lihat: docs/AUDIT-MENYELURUH-PSAK-OJK.md (Gelombang 2 — OJK-03).
 */
class AuditLog extends Model
{
    protected $table = 'audit_logs';

    /** Log append-only — tidak ada kolom updated_at. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'rekening_id',
        'no_rekening',
        'jenis_transaksi',
        'nominal',
        'keterangan',
        'ip_address',
        'hash_sebelumnya_id',
        'hash_sebelumnya',
        'hash',
    ];

    protected $casts = [
        'nominal' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rekening(): BelongsTo
    {
        return $this->belongsTo(Rekening::class, 'rekening_id');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Append-only: tolak semua upaya ubah / hapus
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Cegah pembaruan dan penghapusan baris log.
     *
     * Menggagalkan di level model, bukan hanya di controller, supaya tidak ada
     * jalur mana pun (tinker, seeder, kode baru) yang bisa menyunting jejak
     * audit tanpa disadari.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Log audit bersifat append-only dan tidak dapat diubah.');
        });

        static::deleting(function () {
            throw new RuntimeException('Log audit bersifat append-only dan tidak dapat dihapus.');
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
     */
    public function stringUntukHash(): string
    {
        $bagian = [
            $this->id,
            $this->user_id,
            $this->rekening_id,
            $this->no_rekening,
            $this->jenis_transaksi,
            $this->nominal,
            $this->keterangan,
            $this->ip_address,
            $this->created_at?->format('Y-m-d H:i:s'),
            $this->hash_sebelumnya,
        ];

        return implode('|', array_map(fn ($v) => $v === null ? "\x00" : (string) $v, $bagian));
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
        $terakhir = DB::table('audit_logs')
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
            if (! hash_equals((string) $log->hash, $log->hitungHash())) {
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

    // ─────────────────────────────────────────────────────────────────────
    // Pencatatan
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Catat satu peristiwa audit.
     *
     * Dipertahankan dengan tanda tangan lama agar seluruh pemanggil yang ada
     * tetap kompatibel; di baliknya penulisan kini lewat catat() sehingga
     * baris selalu tersegel dalam rantai hash.
     */
    public static function record($jenis, $nominal = 0, $rekening = null, $keterangan = null): self
    {
        $rekeningId = null;
        $noRekening = '-';

        if (is_object($rekening)) {
            $rekeningId = $rekening->id ?? null;
            $noRekening = $rekening->no_rekening ?? '-';
        } elseif (is_string($rekening) && ! empty($rekening)) {
            $noRekening = $rekening;
        }

        return self::catat([
            'user_id' => auth()->id(),
            'rekening_id' => $rekeningId,
            'no_rekening' => $noRekening,
            'jenis_transaksi' => $jenis,
            'nominal' => $nominal ?? 0,
            'keterangan' => $keterangan ?? ($jenis.($nominal > 0 ? ' sebesar Rp '.number_format($nominal, 0, ',', '.') : '')),
            'ip_address' => request()->ip(),
        ]);
    }
}
