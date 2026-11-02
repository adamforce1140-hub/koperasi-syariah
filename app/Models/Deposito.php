<?php

namespace App\Models;

use App\Services\DepositoService;
use App\Support\Coa;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Bilyet Deposito / Simpanan Berjangka Syariah (akad Mudharabah Muthlaqah).
 *
 * Catatan akuntansi (PSAK 101/108):
 *  - Dana deposito disajikan sebagai DANA SYIRKAH TEMPORER (CoA 2-1003),
 *    bukan kewajiban biasa, karena koperasi mengelola dana dengan bagi hasil.
 *  - Jatuh tempo SELALU dihitung sistem dari start_date + tenor produk.
 *  - Tidak ada komponen SHU dalam perhitungan deposito.
 */
class Deposito extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_MATURED = 'MATURED';

    public const STATUS_CLOSED = 'CLOSED';

    /* ── Mode ARO (Automatic Roll Over) ─────────────────────────────────── */

    /** Tidak diperpanjang: pokok + bagi hasil cair ke Simpanan Sukarela. */
    public const ARO_NON = 'NON_ARO';

    /** Pokok diperpanjang, bagi hasil ditransfer ke Simpanan Sukarela. */
    public const ARO_POKOK = 'ARO_POKOK';

    /** Bagi hasil dikapitalisasi ke pokok, seluruhnya diperpanjang. */
    public const ARO_PLUS = 'ARO_PLUS';

    public const ARO_MODE = [self::ARO_NON, self::ARO_POKOK, self::ARO_PLUS];

    /** Label mode ARO untuk tampilan form & bilyet. */
    public const ARO_LABEL = [
        self::ARO_NON => 'Tidak Perpanjang / Non-ARO',
        self::ARO_POKOK => 'Perpanjang Pokok',
        self::ARO_PLUS => 'Perpanjang Pokok + Bagi Hasil',
    ];

    /** CoA Dana Syirkah Temporer — Simpanan Berjangka / Deposito Mudharabah. */
    public const COA_SIMPANAN_BERJANGKA = Coa::SIMPANAN_BERJANGKA;

    /** CoA Simpanan Sukarela Anggota (sumber & tujuan dana deposito). */
    public const COA_SIMPANAN_SUKARELA = Coa::SIMPANAN_SUKARELA;

    /** CoA Beban Bagi Hasil Deposito. */
    public const COA_BEBAN_BAGI_HASIL = Coa::BEBAN_BAGI_HASIL_DEPOSITO;

    protected $table = 'depositos';

    protected $fillable = [
        'deposito_number',
        'member_id',
        'product_id',
        'source_saving_account_id',
        'deposito_rekening_id',
        'principal_amount',
        'nisbah_anggota',
        'nisbah_koperasi',
        'start_date',
        'maturity_date',
        'is_aro',
        'aro_mode',
        'roll_count',
        'last_rolled_at',
        'status',
        'applied_percentage_member',
        'applied_percentage_institution',
    ];

    protected $casts = [
        'principal_amount' => 'decimal:2',
        'nisbah_anggota' => 'decimal:2',
        'nisbah_koperasi' => 'decimal:2',
        'start_date' => 'date',
        'maturity_date' => 'date',
        'is_aro' => 'boolean',
        'roll_count' => 'integer',
        'last_rolled_at' => 'datetime',
        'applied_percentage_member' => 'decimal:2',
        'applied_percentage_institution' => 'decimal:2',
    ];

    /**
     * Jaga konsistensi dua kolom yang saling mewakili: `aro_mode` adalah
     * sumber kebenaran, `is_aro` adalah cermin boolean-nya (dipakai tampilan &
     * query lama). Diturunkan otomatis agar keduanya tidak pernah bertentangan.
     */
    protected static function booted(): void
    {
        static::saving(function (self $deposito) {
            $mode = $deposito->aro_mode;

            if (! in_array($mode, self::ARO_MODE, true)) {
                // Pemanggil lama hanya mengisi `is_aro` → turunkan modenya.
                $mode = $deposito->is_aro ? self::ARO_POKOK : self::ARO_NON;
                $deposito->aro_mode = $mode;
            }

            $deposito->is_aro = $mode !== self::ARO_NON;
        });
    }

    /* ===================== RELASI ===================== */

    public function product(): BelongsTo
    {
        return $this->belongsTo(DepositoProduct::class, 'product_id');
    }

    /** Anggota pemilik bilyet (tabel `anggota`). */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Anggota::class, 'member_id');
    }

    /** Rekening Simpanan Sukarela — satu-satunya sumber & tujuan dana deposito. */
    public function sourceSavingAccount(): BelongsTo
    {
        return $this->belongsTo(Rekening::class, 'source_saving_account_id');
    }

    /** Rekening Simpanan Berjangka (tabel `rekening`) yang menampung bilyet ini. */
    public function depositoRekening(): BelongsTo
    {
        return $this->belongsTo(Rekening::class, 'deposito_rekening_id');
    }

    public function profitSharings(): HasMany
    {
        return $this->hasMany(DepositoProfitSharing::class, 'deposito_id');
    }

    /** Pengajuan keringanan (ta'zir) penalti pencairan dini atas bilyet ini (R18). */
    public function penaltyReliefs(): HasMany
    {
        return $this->hasMany(DepositoPenaltyRelief::class, 'deposito_id');
    }

    /* ===================== SCOPE ===================== */

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /** Deposito berjalan yang harus dicairkan otomatis (bukan ARO). */
    /**
     * Deposito yang perlu diproses scheduler pada tanggal tertentu:
     * status ACTIVE dan sudah melewati jatuh tempo — mencakup SEMUA mode ARO,
     * karena mode non-ARO dicairkan sedangkan mode ARO diperpanjang.
     */
    public function scopeJatuhTempo($query, $tanggal = null)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->whereDate('maturity_date', '<=', $tanggal ?: today()->toDateString());
    }

    public function scopeMilik($query, int $memberId)
    {
        return $query->where('member_id', $memberId);
    }

    /* ===================== HELPER ===================== */

    public function sudahJatuhTempo(): bool
    {
        return $this->maturity_date && Carbon::parse($this->maturity_date)->lte(today());
    }

    public function tenorMonths(): int
    {
        // Sumber kebenaran adalah produk (tenor disimpan di sana), bukan selisih
        // tanggal: selisih `diffInMonths` dapat meleset untuk tanggal akhir bulan
        // (mis. 31 Jan → 28 Feb terbaca 0 bulan).
        $tenorProduk = $this->relationLoaded('product')
            ? $this->product?->tenor_months
            : $this->product()->value('tenor_months');

        if ($tenorProduk) {
            return (int) $tenorProduk;
        }

        if (! $this->start_date || ! $this->maturity_date) {
            return 0;
        }

        return (int) round(Carbon::parse($this->start_date)->floatDiffInMonths(Carbon::parse($this->maturity_date)));
    }

    /** Sisa hari menuju jatuh tempo (0 bila sudah lewat). */
    public function sisaHari(): int
    {
        if (! $this->maturity_date) {
            return 0;
        }

        return max(0, today()->diffInDays(Carbon::parse($this->maturity_date), false));
    }

    public function getPrincipalFormattedAttribute(): string
    {
        return 'Rp '.number_format((float) $this->principal_amount, 0, ',', '.');
    }

    /* ===================== HELPER KERINGANAN PENALTI (R18) ===================== */

    /**
     * Apakah bilyet ini sedang punya pengajuan keringanan penalti yang belum
     * selesai (PENDING_AO / PENDING_MANAJER)? Mencegah pengajuan ganda.
     */
    public function depositoSudahDiajukanKeringanan(): bool
    {
        return $this->penaltyReliefs()
            ->whereIn('status', [
                DepositoPenaltyRelief::STATUS_PENDING_AO,
                DepositoPenaltyRelief::STATUS_PENDING_MANAJER,
            ])
            ->exists();
    }

    /** Keringanan penalti yang SUDAH disetujui namun belum dipakai (bila ada). */
    public function keringananSiapDipakai(): ?DepositoPenaltyRelief
    {
        return DepositoPenaltyRelief::siapDipakaiUntuk((int) $this->getKey());
    }

    /* ===================== HELPER ARO ===================== */

    /** Apakah bilyet ini diperpanjang otomatis saat jatuh tempo? */
    public function perpanjangOtomatis(): bool
    {
        return $this->aro_mode !== self::ARO_NON;
    }

    /** Apakah bagi hasil dikapitalisasi ke pokok saat perpanjangan? */
    public function kapitalisasiBagiHasil(): bool
    {
        return $this->aro_mode === self::ARO_PLUS;
    }

    public function getAroLabelAttribute(): string
    {
        return self::ARO_LABEL[$this->aro_mode] ?? self::ARO_LABEL[self::ARO_NON];
    }

    /**
     * Akumulasi SELURUH bagi hasil anggota yang tercatat pada bilyet ini
     * (PENDING/PAID/FAILED). Dipakai untuk tampilan & audit.
     */
    public function akumulasiBagiHasil(): float
    {
        return Setting::bulatkanUang((float) $this->profitSharings()
            ->whereIn('status', DepositoProfitSharing::STATUS_DIAKUI)
            ->sum('member_profit_amount'));
    }

    /**
     * Bagi hasil yang tercatat namun porsinya BELUM masuk ke Simpanan Sukarela
     * (mis. pembagian tertunda/gagal). Aman untuk dikreditkan saat jatuh tempo
     * karena belum pernah menambah saldo anggota.
     */
    public function bagiHasilBelumDibayar(): Collection
    {
        return $this->profitSharings()
            ->whereIn('status', DepositoProfitSharing::STATUS_DIAKUI)
            ->whereNull('journal_id')
            ->get();
    }

    /**
     * Bagi hasil yang SUDAH dikreditkan ke Simpanan Sukarela anggota sejak
     * perpanjangan terakhir — kandidat reinvestasi pada mode ARO_PLUS.
     *
     * Dibatasi `last_rolled_at` supaya periode yang sudah pernah dikapitalisasi
     * pada roll sebelumnya tidak dikapitalisasi ulang (anti double-count).
     */
    public function bagiHasilSiapKapitalisasi(): float
    {
        $query = $this->profitSharings()
            ->where('status', DepositoProfitSharing::STATUS_PAID)
            ->whereNotNull('journal_id');

        if ($this->last_rolled_at) {
            $query->where('payout_date', '>', $this->last_rolled_at);
        }

        return Setting::bulatkanUang((float) $query->sum('member_profit_amount'));
    }

    /* ===================== PENCAIRAN DINI (BREAK) ===================== */

    /**
     * Pencairan deposito sebelum jatuh tempo (early withdrawal / break).
     *
     * Perhitungan biaya penalti dilakukan DINAMIS mengikuti aturan master
     * produk (`deposito_products.penalty_type` / `penalty_fee_amount`), lalu
     * seluruh mutasi saldo, penghapusan akrual bagi hasil bulan berjalan, dan
     * auto-journal PSAK 101/108 dieksekusi atomik oleh DepositoService.
     *
     * Jurnal yang dihasilkan:
     *  DEBET  2-1003 Simpanan Berjangka
     *  KREDIT 4-2001 Pendapatan Admin Non-Usaha (biaya penalti)
     *  KREDIT 2-1001 Simpanan Sukarela (pokok − penalti)
     *
     * @param  int|null  $depositoId  Bila null, memakai bilyet ini sendiri.
     * @param  int|null  $userId  Pengguna pencatat (default: user login).
     * @return array{deposito: Deposito, pokok: float, penalti: float, diterima_anggota: float, jurnal: Jurnal|null}
     */
    public function earlyWithdrawal(?int $depositoId = null, ?int $userId = null): array
    {
        return app(DepositoService::class)->earlyWithdrawal(
            $depositoId ?? (int) $this->getKey(),
            $userId
        );
    }
}
