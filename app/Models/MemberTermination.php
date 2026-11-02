<?php

namespace App\Models;

use App\Support\NomorDokumen;
use App\Support\NomorSequence;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * PENGUNDURAN DIRI / EXIT KEANGGOTAAN — modul `member_terminations`.
 *
 * Satu-satunya pintu SAH untuk mengembalikan dana Simpanan Pokok (CoA 3-1001)
 * dan Simpanan Wajib (CoA 3-1002). Kedua akun tersebut adalah MODAL/EKUITAS
 * KOPERASI (PSAK 101 ¶ 31) sehingga tidak boleh dicairkan melalui jalur
 * penarikan rutin selama anggota masih aktif.
 *
 * Alur:
 *   DIAJUKAN → DISETUJUI  : dana dibayarkan, jurnal PSAK 108 diposting,
 *                           status anggota menjadi TERMINATED.
 *   DIAJUKAN → DITOLAK    : ditolak pengurus (alasan disimpan).
 *   DIAJUKAN → DIBATALKAN : dibatalkan (mis. syarat kelayakan tidak terpenuhi).
 *
 * IMUTABILITAS: begitu `jurnal_id` terisi (dana sudah dibayarkan & tercatat di
 * buku besar), baris ini MENJADI APPEND-ONLY — tidak dapat diubah maupun
 * dihapus. Ini menjamin integritas data keuangan & mencegah manipulasi jejak
 * audit (UU No. 25/1992 Pasal 30, POJK No. 76/2016, PSAK 108).
 */
class MemberTermination extends Model
{
    use HasFactory;

    protected $table = 'member_terminations';

    /* ── Status alur ─────────────────────────────────────────────────── */

    public const STATUS_DIAJUKAN = 'DIAJUKAN';

    public const STATUS_MENUNGGU_APPROVAL = 'MENUNGGU_APPROVAL';

    public const STATUS_DISETUJUI = 'DISETUJUI';

    public const STATUS_DITOLAK = 'DITOLAK';

    public const STATUS_DIBATALKAN = 'DIBATALKAN';

    public const STATUS_LIST = [
        self::STATUS_DIAJUKAN,
        self::STATUS_MENUNGGU_APPROVAL,
        self::STATUS_DISETUJUI,
        self::STATUS_DITOLAK,
        self::STATUS_DIBATALKAN,
    ];

    public const STATUS_LABEL = [
        self::STATUS_DIAJUKAN => 'Diajukan',
        self::STATUS_MENUNGGU_APPROVAL => 'Menunggu Persetujuan Manajer',
        self::STATUS_DISETUJUI => 'Disetujui & Dana Dikembalikan',
        self::STATUS_DITOLAK => 'Ditolak',
        self::STATUS_DIBATALKAN => 'Dibatalkan',
    ];

    /* ── Metode pengembalian dana ────────────────────────────────────── */

    /** Tunai via teller → KREDIT Kas Teller (1-1001). */
    public const METODE_CASH = 'CASH';

    /** Transfer rekening bank koperasi → KREDIT Bank Koperasi (1-1002). */
    public const METODE_TRANSFER_BANK = 'TRANSFER_BANK';

    public const METODE_LIST = [
        self::METODE_CASH,
        self::METODE_TRANSFER_BANK,
    ];

    public const METODE_LABEL = [
        self::METODE_CASH => 'Tunai via Teller',
        self::METODE_TRANSFER_BANK => 'Transfer via Rekening Bank Koperasi',
    ];

    protected $fillable = [
        'anggota_id',
        'no_terminasi',
        'no_referensi',
        'status',
        'metode',
        'nomor_rekening_tujuan',
        'nama_bank_tujuan',
        'nominal_simpanan_pokok',
        'nominal_simpanan_wajib',
        'nominal_sukarela',
        'nominal_deposito',
        'nominal_akrual_bagi_hasil',
        'nominal_zakat',
        'nominal_biaya_admin',
        'total_net',
        'total_dikembalikan',
        'butuh_approval',
        'approved_at',
        'exit_reason',
        'transaksi_id',
        'jurnal_id',
        'no_jurnal',
        'diajukan_oleh',
        'diproses_oleh',
        'disetujui_oleh',
        'tanggal_pengunduran_diri',
        'diajukan_pada',
        'diproses_pada',
        'catatan',
        'alasan_penolakan',
    ];

    protected $casts = [
        'nominal_simpanan_pokok' => 'decimal:2',
        'nominal_simpanan_wajib' => 'decimal:2',
        'nominal_sukarela' => 'decimal:2',
        'nominal_deposito' => 'decimal:2',
        'nominal_akrual_bagi_hasil' => 'decimal:2',
        'nominal_zakat' => 'decimal:2',
        'nominal_biaya_admin' => 'decimal:2',
        'total_net' => 'decimal:2',
        'total_dikembalikan' => 'decimal:2',
        'butuh_approval' => 'boolean',
        'approved_at' => 'datetime',
        'tanggal_pengunduran_diri' => 'date',
        'diajukan_pada' => 'datetime',
        'diproses_pada' => 'datetime',
    ];

    protected static function booted(): void
    {
        // ── APPEND-ONLY SETELAH JURNAL DIPOSTING ──────────────────────────
        // Begitu dana keluar & jurnal tercatat (`jurnal_id` terisi), baris ini
        // menjadi catatan audit permanen: tidak boleh diubah, tidak boleh
        // dihapus. Mencegah manipulasi nominal/jurnal pasca-pembayaran.
        static::updating(function (MemberTermination $terminasi) {
            if ($terminasi->getOriginal('jurnal_id') !== null) {
                throw new RuntimeException(
                    'Dokumen terminasi yang jurnalnya sudah diposting bersifat permanen '
                    .'dan tidak dapat diubah (PSAK 108 — integritas buku besar).'
                );
            }
        });

        static::deleting(function (MemberTermination $terminasi) {
            if ($terminasi->getOriginal('jurnal_id') !== null) {
                throw new RuntimeException(
                    'Dokumen terminasi yang jurnalnya sudah diposting bersifat permanen '
                    .'dan tidak dapat dihapus (PSAK 108 — integritas buku besar).'
                );
            }
        });
    }

    /* ── Relasi ──────────────────────────────────────────────────────── */

    public function anggota(): BelongsTo
    {
        return $this->belongsTo(Anggota::class, 'anggota_id');
    }

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(Transaksi::class, 'transaksi_id');
    }

    public function jurnal(): BelongsTo
    {
        return $this->belongsTo(Jurnal::class, 'jurnal_id');
    }

    public function diajukanOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diajukan_oleh');
    }

    public function diprosesOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diproses_oleh');
    }

    public function disetujuiOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disetujui_oleh');
    }

    /** Riwayat persetujuan/penolakan oleh Manajer. */
    public function approvalLogs(): HasMany
    {
        return $this->hasMany(ExitApprovalLog::class, 'member_termination_id')->latest('id');
    }

    /* ── Scope ───────────────────────────────────────────────────────── */

    /** @param  Builder<MemberTermination>  $query */
    public function scopeMenunggu($query)
    {
        return $query->whereIn('status', [
            self::STATUS_DIAJUKAN,
            self::STATUS_MENUNGGU_APPROVAL,
        ]);
    }

    /** @param  Builder<MemberTermination>  $query */
    public function scopeDisetujui($query)
    {
        return $query->where('status', self::STATUS_DISETUJUI);
    }

    /* ── Helper ──────────────────────────────────────────────────────── */

    /** Apakah dokumen ini sudah diposting jurnalnya (kunci idempotensi). */
    public function sudahDiposting(): bool
    {
        return $this->jurnal_id !== null;
    }

    /** Apakah pengajuan masih menunggu persetujuan Manajer. */
    public function menungguApproval(): bool
    {
        return $this->status === self::STATUS_MENUNGGU_APPROVAL;
    }

    public function getStatusLabelAttribute(): string
    {
        // Setelah Manajer menyetujui, dokumen tetap berstatus MENUNGGU_APPROVAL
        // (agar gerbang eksekusi tetap memeriksa `approved_at`), tetapi label
        // harus jujur: dana sudah boleh dicairkan.
        if ($this->status === self::STATUS_MENUNGGU_APPROVAL && $this->approved_at !== null) {
            return 'Disetujui Manajer — Siap Dicairkan';
        }

        return self::STATUS_LABEL[$this->status] ?? (string) $this->status;
    }

    public function getMetodeLabelAttribute(): string
    {
        return self::METODE_LABEL[$this->metode] ?? '-';
    }

    /** Apakah metode mengharuskan data rekening tujuan diisi. */
    public function membutuhkanRekeningTujuan(): bool
    {
        return $this->metode === self::METODE_TRANSFER_BANK;
    }

    /**
     * Nomor dokumen unik: TRM-YYYYMMDD-0001.
     *
     * Memakai `withTrashed()`-style max-scan agar aman terhadap nomor yang
     * sudah terpakai. Format seragam dengan `generateNoJurnal()`.
     */
    public static function generateNoTerminasi(?Carbon $tanggal = null): string
    {
        $tanggal = ($tanggal ?? Carbon::today())->copy();
        // Awalan dapat diubah Pengurus (Setting `prefix_nomor_terminasi`);
        // kosong = tetap `TRM`.
        $prefix = NomorDokumen::prefix('terminasi', $tanggal->format('Ymd'));

        $terakhir = static::query()
            ->where('no_terminasi', 'like', $prefix.'%')
            ->orderByDesc('no_terminasi')
            ->value('no_terminasi');

        $urut = $terakhir ? ((int) substr($terakhir, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $urut, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Nomor referensi dokumen resmi: EXIT/YYYYMM/NNNNNN.
     *
     * Berbeda dari `no_terminasi` (nomor internal `TRM-...`) yang dipertahankan
     * demi kompatibilitas data lama. Generator memakai `NomorSequence` yang
     * aman-race, sehingga WAJIB dipanggil di dalam `DB::transaction()`.
     */
    public static function generateNoReferensi(?Carbon $tanggal = null): string
    {
        $tanggal = ($tanggal ?? Carbon::today())->copy();

        return NomorSequence::berikutnya(
            'EXIT/'.$tanggal->format('Ym').'/',
            6,
            'member_terminations',
            'no_referensi'
        );
    }
}
