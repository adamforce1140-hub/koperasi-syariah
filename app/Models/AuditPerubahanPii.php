<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Jejak audit perubahan DATA PRIBADI (UU No. 27/2022 — PDP).
 *
 * APPEND-ONLY: tidak ada `updated_at`, dan tidak ada jalur kode yang
 * menyunting/menghapus baris. Setiap baris disegel dengan rantai hash:
 *
 *     hash = SHA256( isi_baris + hash_baris_sebelumnya )
 *
 * sehingga penghapusan atau penyuntingan langsung di database memutus rantai
 * dan terdeteksi oleh verifyChain().
 *
 * MINIMALISASI DATA: kolom `kolom` hanya memuat NAMA kolom PII yang berubah —
 * nilai NIK/alamat TIDAK PERNAH ditulis ke sini (Pasal 16 UU PDP).
 */
class AuditPerubahanPii extends Model
{
    protected $table = 'audit_perubahan_pii';

    /** Tabel append-only — tidak ada kolom updated_at. */
    public const UPDATED_AT = null;

    public const AKSI_TAMBAH = 'tambah';

    public const AKSI_EDIT = 'edit';

    public const AKSI_HAPUS = 'hapus';

    public const AKSI_PULIHKAN = 'pulihkan';

    protected $fillable = [
        'user_id',
        'user_nama',
        'role',
        'model',
        'model_id',
        'aksi',
        'kolom',
        'jumlah_kolom',
        'deskripsi',
        'ip_address',
        'user_agent',
        'hash_sebelumnya_id',
        'hash_sebelumnya',
        'hash',
    ];

    protected $casts = [
        'kolom' => 'array',
        'jumlah_kolom' => 'integer',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Label tampilan
    // ─────────────────────────────────────────────────────────────────────

    public function labelAksi(): string
    {
        return match ($this->aksi) {
            self::AKSI_TAMBAH => 'Tambah',
            self::AKSI_EDIT => 'Ubah',
            self::AKSI_HAPUS => 'Hapus',
            self::AKSI_PULIHKAN => 'Pulihkan',
            default => ucfirst((string) $this->aksi),
        };
    }

    public function labelModel(): string
    {
        return match ($this->model) {
            'Anggota' => 'Anggota',
            'PengajuanRekening' => 'Pengajuan Rekening',
            default => (string) $this->model,
        };
    }

    // ─────────────────────────────────────────────────────────────────────
    // Perekaman (hash chain)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Muat ujung rantai saat ini. Dipisah agar mudah diuji.
     *
     * @return array{0: ?int, 1: ?string}
     */
    public static function ujungRantai(): array
    {
        $terakhir = static::query()->orderByDesc('id')->first(['id', 'hash']);

        return [$terakhir?->id, $terakhir?->hash];
    }

    /**
     * Rekatkan teks yang akan disegel. Urutan kolom harus tetap agar hash
     * dapat dihitung ulang saat verifikasi.
     */
    public function stringUntukHash(): string
    {
        $kolom = is_array($this->kolom) ? $this->kolom : [];
        sort($kolom);

        return implode('|', [
            $this->user_id ?? '',
            $this->user_nama ?? '',
            $this->role ?? '',
            $this->model ?? '',
            $this->model_id ?? '',
            $this->aksi ?? '',
            implode(',', $kolom),
            $this->deskripsi ?? '',
            $this->ip_address ?? '',
            (string) $this->created_at?->format('Y-m-d H:i:s'),
            $this->hash_sebelumnya ?? '',
        ]);
    }

    public function hitungHash(): string
    {
        return hash('sha256', $this->stringUntukHash());
    }

    /**
     * Catat satu baris audit sekaligus menyegelnya ke rantai.
     *
     * Dibungkus transaksi + kunci tulis agar dua proses yang menulis nyaris
     * bersamaan tidak menghasilkan hash_sebelumnya yang sama (percabangan
     * rantai). Penguncian memakai baris terakhir sebagai penanda.
     *
     * @param  array<string, mixed>  $atribut
     */
    public static function catat(array $atribut): self
    {
        return DB::transaction(function () use ($atribut) {
            $log = new self($atribut);

            $log->created_at = now();

            [$idSebelumnya, $hashSebelumnya] = static::ujungRantai();
            $log->hash_sebelumnya_id = $idSebelumnya;
            $log->hash_sebelumnya = $hashSebelumnya;
            $log->hash = $log->hitungHash();

            $log->save();

            return $log;
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Verifikasi integritas
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Periksa keutuhan rantai.
     *
     * Seluruh tabel dibaca berurutan, jadi batasi jumlah baris yang diperiksa
     * pada tabel besar lewat parameter $batas (diperiksa dari baris terbaru).
     *
     * @return array{utuh: bool, diperiksa: int, rusak_di: ?int, alasan: ?string}
     */
    public static function verifyChain(?int $batas = null): array
    {
        $kueri = static::query()->orderBy('id');
        $total = (clone $kueri)->count();

        if ($batas !== null && $total > $batas) {
            // Ambil N baris terbaru, lalu balik ke urutan menaik.
            $kueri->orderByDesc('id')->limit($batas);
        }

        $log = $kueri->get();

        if ($batas !== null && $total > $batas) {
            $log = $log->sortBy('id')->values();
        }

        $hashSebelumnya = null;
        $idSebelumnya = null;
        $diperiksa = 0;

        foreach ($log as $baris) {
            $diperiksa++;

            if ($idSebelumnya !== null) {
                if ($baris->hash_sebelumnya_id !== $idSebelumnya
                    || ! hash_equals((string) $hashSebelumnya, (string) $baris->hash_sebelumnya)) {
                    return [
                        'utuh' => false,
                        'diperiksa' => $diperiksa,
                        'rusak_di' => $baris->id,
                        'alasan' => 'Rantai terputus: rujukan ke baris sebelumnya tidak cocok '
                                       .'(kemungkinan ada baris yang dihapus atau disisipkan).',
                    ];
                }
            }

            if (! hash_equals((string) $baris->hash, (string) $baris->hitungHash())) {
                return [
                    'utuh' => false,
                    'diperiksa' => $diperiksa,
                    'rusak_di' => $baris->id,
                    'alasan' => 'Isi baris tidak cocok dengan segelnya '
                                   .'(kemungkinan baris disunting langsung di database).',
                ];
            }

            $hashSebelumnya = $baris->hash;
            $idSebelumnya = $baris->id;
        }

        return [
            'utuh' => true,
            'diperiksa' => $diperiksa,
            'rusak_di' => null,
            'alasan' => null,
        ];
    }
}
