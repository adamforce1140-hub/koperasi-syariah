<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Jejak audit peristiwa approval transaksi — APPEND-ONLY.
 *
 * Mencatat SETIAP peristiwa pada rantai otorisasi berjenjang: pengajuan,
 * persetujuan, penolakan, dan percobaan pemutusan yang gagal karena peran
 * pemutus tidak berwenang. Peristiwa terakhir itu penting — justru di situ
 * konflik kewenangan lintas-peran terlihat.
 *
 * Mengikuti pola `LaporanKeuanganLog`: setiap baris disegel rantai hash
 *
 *     hash = SHA256( isi_baris + hash_baris_sebelumnya )
 *
 * sehingga penyuntingan atau penghapusan satu baris memutus rantai dan
 * terdeteksi oleh verifyChain(). Model menolak `updating`/`deleting` agar
 * tidak ada jalur (tinker, seeder) yang bisa menyunting jejak.
 */
class ApprovalTransaksiLog extends Model
{
    protected $table = 'approval_transaksi_logs';

    /** Log append-only — tidak ada kolom updated_at. */
    public const UPDATED_AT = null;

    // ── Peristiwa yang dikenali ──
    public const PERISTIWA_DIAJUKAN = 'diajukan';

    public const PERISTIWA_DISETUJUI = 'disetujui';

    public const PERISTIWA_DITOLAK = 'ditolak';

    public const PERISTIWA_GAGAL_DIPUTUS = 'gagal_diputus';

    public const PERISTIWA_DIBATALKAN = 'dibatalkan';

    /** Label & warna per peristiwa untuk tampilan audit. */
    public const LABEL_PERISTIWA = [
        self::PERISTIWA_DIAJUKAN => 'Diajukan',
        self::PERISTIWA_DISETUJUI => 'Disetujui',
        self::PERISTIWA_DITOLAK => 'Ditolak',
        self::PERISTIWA_GAGAL_DIPUTUS => 'Gagal Diputus',
        self::PERISTIWA_DIBATALKAN => 'Dibatalkan',
    ];

    public const WARNA_PERISTIWA = [
        self::PERISTIWA_DIAJUKAN => '#3b82f6',
        self::PERISTIWA_DISETUJUI => '#059669',
        self::PERISTIWA_DITOLAK => '#dc2626',
        self::PERISTIWA_GAGAL_DIPUTUS => '#f59e0b',
        self::PERISTIWA_DIBATALKAN => '#64748b',
    ];

    protected $fillable = [
        'approval_id',
        'kode_approval',
        'peristiwa',
        'status_sebelum',
        'status_sesudah',
        'user_id',
        'user_nama',
        'role',
        'role_berwenang',
        'nominal',
        'catatan',
        'ip_address',
        'user_agent',
        'hash_sebelumnya_id',
        'hash_sebelumnya',
        'hash',
    ];

    protected $casts = [
        'nominal' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function approval(): BelongsTo
    {
        return $this->belongsTo(ApprovalTransaksi::class, 'approval_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function labelPeristiwa(): string
    {
        return self::LABEL_PERISTIWA[$this->peristiwa] ?? ucfirst((string) $this->peristiwa);
    }

    public function warnaPeristiwa(): string
    {
        return self::WARNA_PERISTIWA[$this->peristiwa] ?? '#64748b';
    }

    // ─────────────────────────────────────────────────────────────────────
    // Append-only
    // ─────────────────────────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Jejak audit approval bersifat append-only dan tidak dapat diubah.');
        });

        static::deleting(function () {
            throw new RuntimeException('Jejak audit approval bersifat append-only dan tidak dapat dihapus.');
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Hash chain
    // ─────────────────────────────────────────────────────────────────────

    /** Susun string kanonik dari isi baris (urutan field dikunci di satu tempat). */
    public function stringUntukHash(): string
    {
        $bagian = [
            $this->id,
            $this->approval_id,
            $this->kode_approval,
            $this->peristiwa,
            $this->status_sebelum,
            $this->status_sesudah,
            $this->user_id,
            $this->user_nama,
            $this->role,
            $this->role_berwenang,
            $this->nominal,
            $this->catatan,
            $this->ip_address,
            $this->user_agent,
            $this->created_at?->format('Y-m-d H:i:s'),
            $this->hash_sebelumnya,
        ];

        return implode('|', array_map(fn ($v) => $v === null ? "\x00" : (string) $v, $bagian));
    }

    public function hitungHash(): string
    {
        return hash('sha256', $this->stringUntukHash());
    }

    /** Hash baris terakhir sebagai titik sambung rantai. */
    public static function ujungRantai(): array
    {
        $terakhir = DB::table('approval_transaksi_logs')
            ->orderByDesc('id')
            ->first(['id', 'hash']);

        return [
            'id' => $terakhir->id ?? null,
            'hash' => $terakhir->hash ?? null,
        ];
    }

    /**
     * Tulis satu baris jejak lengkap dengan segelnya.
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

            // `id` ikut disegel, jadi baris disimpan dulu tanpa hash lalu disegel.
            $log->hash = str_repeat('0', 64);
            $log->save();

            $log->hash = $log->hitungHash();
            $log->saveQuietly();

            return $log;
        });
    }

    /**
     * Catat peristiwa approval secara ringkas.
     *
     * Dipanggil dari jalur transaksi (service) maupun dari pengawas mutasi
     * model. Seluruhnya dibungkus try/catch agar kegagalan penulisan jejak
     * TIDAK pernah membatalkan transaksi keuangan yang sah — kegagalan itu
     * dilaporkan ke log aplikasi sebagai peringatan kepatuhan.
     */
    public static function catatPeristiwa(
        ApprovalTransaksi $approval,
        string $peristiwa,
        ?User $pelaku,
        array $tambahan = []
    ): ?self {
        try {
            $user = $pelaku;

            if ($user === null) {
                $id = Auth::id();
                $user = $id ? User::find($id) : null;
            }

            $request = static::permintaanSaatIni();

            return static::catat([
                'approval_id' => $approval->id,
                'kode_approval' => $approval->kode_approval,
                'peristiwa' => $peristiwa,
                'status_sebelum' => $tambahan['status_sebelum'] ?? null,
                'status_sesudah' => $tambahan['status_sesudah'] ?? $approval->status,
                'user_id' => $user?->id,
                'user_nama' => $user?->name,
                'role' => $user?->role ?? $approval->role_pengaju,
                'role_berwenang' => $tambahan['role_berwenang'] ?? null,
                'nominal' => $approval->nominal,
                'catatan' => $tambahan['catatan'] ?? null,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Gagal menulis jejak audit approval.', [
                'approval_id' => $approval->id ?? null,
                'peristiwa' => $peristiwa,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** Permintaan HTTP saat ini bila ada (aman dipanggil dari CLI/queue). */
    private static function permintaanSaatIni(): ?Request
    {
        try {
            /** @var Request $request */
            $request = app('request');

            return $request;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Periksa keutuhan rantai hash.
     *
     * @return array{utuh: bool, diperiksa: int, rusak_di: int|null, alasan: string|null}
     */
    public static function verifyChain(?int $batas = null): array
    {
        $query = static::query()->orderBy('id');
        if ($batas) {
            $query->limit($batas);
        }

        $hashSebelumnya = null;
        $idSebelumnya = null;
        $diperiksa = 0;

        foreach ($query->get() as $log) {
            $diperiksa++;

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

        return ['utuh' => true, 'diperiksa' => $diperiksa, 'rusak_di' => null, 'alasan' => null];
    }
}
