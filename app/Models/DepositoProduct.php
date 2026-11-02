<?php

namespace App\Models;

use App\Support\Coa;
use App\Support\Setting;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Produk Deposito / Simpanan Berjangka Syariah (Mudharabah Muthlaqah).
 *
 * Nisbah adalah PORSI BAGI HASIL (persentase), bukan bunga tetap. Tidak ada
 * nominal imbalan yang dijamin di muka — besarnya bagi hasil bergantung pada
 * pendapatan koperasi dari penempatan dana.
 */
class DepositoProduct extends Model
{
    use HasFactory;

    /** Tenor yang diizinkan (bulan) sesuai kebijakan produk. */
    public const TENOR_DIIZINKAN = [1, 3, 6, 12];

    /** Tipe produk: simpanan (pokok/wajib/sukarela) dan simpanan berjangka. */
    public const TIPE_POKOK = 'pokok';

    public const TIPE_WAJIB = 'wajib';

    public const TIPE_SUKARELA = 'sukarela';

    public const TIPE_DEPOSITO = 'deposito';

    /** COA bawaan tiap tipe produk (referensi master, bukan jurnal transaksi). */
    public const COA_BY_TIPE = [
        self::TIPE_POKOK => Coa::SIMPANAN_POKOK,
        self::TIPE_WAJIB => Coa::SIMPANAN_WAJIB_MODAL,
        self::TIPE_SUKARELA => Coa::SIMPANAN_SUKARELA,
        self::TIPE_DEPOSITO => Coa::SIMPANAN_BERJANGKA,
    ];

    /** Tipe biaya penalti pencairan dini. */
    public const PENALTI_FIXED = 'fixed';

    public const PENALTI_PERCENTAGE = 'percentage';

    /**
     * COA bawaan penerima biaya penalti.
     *
     * R7 — Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000: ta'zir/penalti adalah DANA
     * SOSIAL, tidak boleh menjadi pendapatan koperasi. Karena itu default-nya
     * diarahkan ke Dana Kebajikan (2-3001), bukan Pendapatan Administrasi.
     */
    public const PENALTI_COA_DEFAULT = Coa::DANA_KEBAJIKAN;

    /** Nominal penalti bawaan (rupiah) bila produk belum dikonfigurasi. */
    public const PENALTI_AMOUNT_DEFAULT = 50000;

    protected $table = 'deposito_products';

    protected $fillable = [
        'name',
        'tipe_produk',
        'has_nisbah',
        'coa_kode',
        'tenor_months',
        'nisbah_anggota',
        'nisbah_koperasi',
        'min_amount',
        'allow_early_withdrawal',
        'penalty_fee_type',
        'penalty_fee_amount',
        'forfeit_monthly_profit',
        'penalty_coa_id',
        'is_active',
    ];

    protected $casts = [
        'tipe_produk' => 'string',
        'has_nisbah' => 'boolean',
        'tenor_months' => 'integer',
        'nisbah_anggota' => 'decimal:2',
        'nisbah_koperasi' => 'decimal:2',
        'min_amount' => 'decimal:2',
        'allow_early_withdrawal' => 'boolean',
        'penalty_fee_type' => 'string',
        'penalty_fee_amount' => 'decimal:2',
        'forfeit_monthly_profit' => 'boolean',
        'penalty_coa_id' => 'string',
        'is_active' => 'boolean',
    ];

    /* ===================== RELASI ===================== */

    public function depositos(): HasMany
    {
        return $this->hasMany(Deposito::class, 'product_id');
    }

    /* ===================== SCOPE ===================== */

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeTenor($query, int $bulan)
    {
        return $query->where('tenor_months', $bulan);
    }

    /** Produk simpanan berjangka (deposito) — dipakai pemilihan tenor di UI. */
    public function scopeDeposito($query)
    {
        return $query->where('tipe_produk', self::TIPE_DEPOSITO);
    }

    /** Produk simpanan non-berjangka (pokok/wajib/sukarela). */
    public function scopeSimpanan($query)
    {
        return $query->whereIn('tipe_produk', [self::TIPE_POKOK, self::TIPE_WAJIB, self::TIPE_SUKARELA]);
    }

    public function scopeTipe($query, string $tipe)
    {
        return $query->where('tipe_produk', $tipe);
    }

    /* ===================== HELPER ===================== */

    /** Total nisbah harus 100% — dasar pembagian bagi hasil yang sah. */
    public function nisbahValid(): bool
    {
        if (! $this->has_nisbah) {
            return true;
        }

        return abs(((float) $this->nisbah_anggota + (float) $this->nisbah_koperasi) - 100.0) < Setting::toleransiPersen();
    }

    public function getLabelAttribute(): string
    {
        return $this->tenor_months
            ? "{$this->name} ({$this->tenor_months} bulan)"
            : $this->name;
    }

    /* ===================== PENALTI PENCAIRAN DINI ===================== */

    /**
     * Kode COA penerima biaya penalti.
     *
     * R7 — penalti pencairan dini adalah ta'zir (dana sosial), sehingga akun
     * yang diarahkan ke akun PENDAPATAN ditolak dan otomatis dialihkan ke
     * Dana Kebajikan. Ini mencegah konfigurasi produk yang melanggar Fatwa
     * DSN-MUI No. 17/DSN-MUI/IX/2000.
     */
    public function coaPenalti(): string
    {
        $kode = $this->penalty_coa_id ?: self::PENALTI_COA_DEFAULT;

        return self::akunPenaltiDiizinkan($kode) ? $kode : self::PENALTI_COA_DEFAULT;
    }

    /**
     * Apakah kode akun sah dipakai sebagai penerima ta'zir/penalti.
     *
     * Akun yang diizinkan HANYA akun non-pendapatan, supaya ta'zir tidak
     * pernah tercatat sebagai pendapatan koperasi. Kerangka CoA memakai pola
     * `[Kategori]-[Nomor]` dengan kepala 4 = Pendapatan, sehingga cukup
     * diperiksa dari prefiksnya (tanpa query DB).
     */
    public static function akunPenaltiDiizinkan(string $kode): bool
    {
        $kode = trim($kode);

        if ($kode === '') {
            return true; // fallback ke default
        }

        return ! str_starts_with($kode, '4-');
    }

    /** Apakah produk memakai penalti berbasis persentase dari pokok. */
    public function penaltiPersentase(): bool
    {
        return $this->penalty_fee_type === self::PENALTI_PERCENTAGE;
    }

    /**
     * Hitung biaya penalti secara DINAMIS dari konfigurasi produk.
     *
     *  - tipe 'fixed'      → nominal `penalty_fee_amount`, atau
     *                        PENALTI_AMOUNT_DEFAULT bila produk tidak mengisi,
     *  - tipe 'percentage' → pokok × penalty_fee_amount / 100, atau
     *                        PENALTI_AMOUNT_DEFAULT bila produk tidak mengisi.
     *
     * Nominal 0 yang DIPILIH produk dipakai apa adanya (penalti ditanggung).
     * Biaya penalti tidak boleh melebihi pokok deposito (saldo bersih yang
     * dikembalikan ke anggota tidak boleh negatif).
     */
    public function hitungPenalti(float $pokok): float
    {
        $dasar = (float) ($this->penalty_fee_amount ?? self::PENALTI_AMOUNT_DEFAULT);

        $biaya = $this->penaltiPersentase()
            ? Setting::bulatkanUang($pokok * $dasar / 100)
            : Setting::bulatkanUang($dasar);

        return max(0.0, min($biaya, Setting::bulatkanUang($pokok)));
    }

    /** Keterangan tipe penalti untuk tampilan UI (mis. "1% dari pokok"). */
    public function getPenaltiLabelAttribute(): string
    {
        if (! $this->allow_early_withdrawal) {
            return 'Pencairan dini tidak diizinkan';
        }

        $nominal = (float) ($this->penalty_fee_amount ?? 0);

        return $this->penaltiPersentase()
            ? rtrim(rtrim(number_format($nominal, 2, ',', '.'), '0'), ',').'% dari pokok'
            : 'Rp '.number_format($nominal, 0, ',', '.');
    }
}
