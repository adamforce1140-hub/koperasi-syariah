<?php

namespace App\Models;

use App\Support\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MasterProdukPembiayaan extends Model
{
    protected $table = 'master_produk_pembiayaan';

    /* Status sertifikasi opini syariah oleh DPS. */
    public const SERTIFIKASI_BELUM = 'Belum Disertifikasi';

    public const SERTIFIKASI_VALID = 'Disertifikasi';

    public const SERTIFIKASI_DITOLAK = 'Ditolak';

    /* Panjang & pola kode produk; diselaraskan dengan kolom kode_produk (30). */
    public const KODE_PRODUK_PANJANG = 30;

    public const KODE_PRODUK_POLA = '/^[A-Z0-9][A-Z0-9\-]*$/';

    /** Aturan validasi kode produk (dipakai controller & pengujian). */
    public const RULE_KODE_PRODUK = [
        'nullable',
        'string',
        'max:30',
        'regex:/^[A-Z0-9][A-Z0-9\-]*$/',
    ];

    protected $fillable = [
        'kode_produk', 'nama_produk', 'akad_syariah', 'min_plafon', 'max_plafon',
        'margin_persen', 'nisbah_anggota', 'nisbah_koperasi', 'biaya_admin', 'min_bulan_anggota',
        'min_tenor_bulan', 'max_tenor_bulan', 'status_aktif',
        'status_sertifikasi_dps', 'disahkan_oleh', 'tanggal_disahkan', 'catatan_dps',
    ];

    protected $casts = [
        'status_aktif' => 'boolean',
        'min_plafon' => 'decimal:2',
        'max_plafon' => 'decimal:2',
        'margin_persen' => 'decimal:2',
        'nisbah_anggota' => 'decimal:3',
        'nisbah_koperasi' => 'decimal:3',
        'tanggal_disahkan' => 'datetime',
    ];

    /**
     * Akad bagi hasil (bukan jual beli/sewa) yang memerlukan nisbah.
     *
     * @return array<int, string>
     */
    public static function akadBagiHasil(): array
    {
        return [
            Pembiayaan::AKAD_MUDHARABAH,
            Pembiayaan::AKAD_MUSYARAKAH,
        ];
    }

    /** Produk ini memakai akad bagi hasil sehingga wajib memiliki nisbah. */
    public function memerlukanNisbah(): bool
    {
        return in_array($this->akad_syariah, self::akadBagiHasil(), true);
    }

    /**
     * Validasi nisbah: keduanya kosong, atau keduanya terisi dan berjumlah 100%.
     *
     * Setara `DepositoProduct::nisbahValid()`; mencegah produk bagi hasil
     * disimpan dengan porsi yang tidak utuh (pelanggaran keadilan bagi hasil).
     */
    public function nisbahValid(): bool
    {
        if ($this->nisbah_anggota === null && $this->nisbah_koperasi === null) {
            return true;
        }

        if ($this->nisbah_anggota === null || $this->nisbah_koperasi === null) {
            return false;
        }

        return abs((float) $this->nisbah_anggota + (float) $this->nisbah_koperasi - 100.0) < Setting::toleransiPersen();
    }

    public function syaratDokumen(): HasMany
    {
        return $this->hasMany(ProdukSyaratDokumen::class, 'produk_id');
    }

    /** DPS yang mengesahkan produk ini. */
    public function disahkanOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disahkan_oleh');
    }

    /**
     * Pemetaan akad → CoA khusus produk ini (bila pengurus mengisinya).
     *
     * Bila tidak ada baris khusus produk, kembaliannya baris universal ('*')
     * milik akad — sehingga `kodePiutang()` tetap memberi hasil yang sama
     * dengan perilaku lama (kebijakan/registri CoA) untuk produk tanpa
     * pemetaan khusus.
     */
    public function pemetaanAkadCoa(): PemetaanAkadCoa
    {
        if (! $this->akad_syariah) {
            return new PemetaanAkadCoa(['kode_produk' => PemetaanAkadCoa::SEMUA_PRODUK]);
        }

        return PemetaanAkadCoa::untukAkad(
            $this->akad_syariah,
            $this->kode_produk ?: PemetaanAkadCoa::SEMUA_PRODUK
        );
    }

    /** Produk sudah disertifikasi DPS. */
    public function sudahDisertifikasi(): bool
    {
        return $this->status_sertifikasi_dps === self::SERTIFIKASI_VALID;
    }

    /**
     * Normalisasi kode produk menjadi huruf besar tanpa spasi tepi.
     *
     * Kode produk menjadi kunci pemetaan akad → CoA PER PRODUK
     * (`pemetaan_akad_coa.kode_produk`) dan divalidasi dengan
     * {@see RULE_KODE_PRODUK}, sehingga ' mrb01 ' dan 'MRB01' tidak boleh
     * tersimpan sebagai dua kode berbeda.
     */
    public function setKodeProdukAttribute($value): void
    {
        $kode = trim((string) $value);

        $this->attributes['kode_produk'] = $kode === '' ? null : strtoupper($kode);
    }

    /**
     * Validasi kelengkapan logika bisnis produk.
     *
     * Dipakai bersama `nisbahValid()` dan aturan `RULE_*` agar seluruh titik
     * simpan (seeder, controller, impor) menegakkan aturan yang sama.
     *
     * @return array<int, string> daftar pesan galat; kosong = valid
     */
    public function nilaiBisnisTidakValid(): array
    {
        $galat = [];

        if ($this->memerlukanNisbah() && ! $this->nisbahValid()) {
            $galat[] = "Produk berakad {$this->akad_syariah} wajib mencantumkan nisbah "
                .'anggota dan koperasi yang berjumlah tepat 100%.';
        }

        if ($this->nisbahValid() === false && ! $this->memerlukanNisbah()) {
            $galat[] = 'Nisbah anggota dan nisbah koperasi harus diisi keduanya '
                .'atau dikosongkan keduanya.';
        }

        if ((float) $this->min_plafon > (float) $this->max_plafon) {
            $galat[] = 'Plafon minimum tidak boleh melebihi plafon maksimum.';
        }

        if ((int) $this->min_tenor_bulan > (int) $this->max_tenor_bulan) {
            $galat[] = 'Tenor minimum tidak boleh melebihi tenor maksimum.';
        }

        if ((float) $this->margin_persen < 0 || (float) $this->margin_persen > 100) {
            $galat[] = 'Margin/persentase bagi hasil harus berada pada rentang 0–100%.';
        }

        return $galat;
    }

    /**
     * Kode produk baru, deterministik per bulan: PRD-YYYYMM-NNNN.
     *
     * Penomoran memakai ID terbesar produk ber-prefix sama (bukan memotong
     * 4 digit terakhir kode) agar tetap benar walau kode dibuat manual dan
     * tetap monoton walau produk ber-prefix tersebut dihapus.
     */
    public static function generateKode(): string
    {
        $prefix = 'PRD-'.date('Ym').'-';
        $maxId = (int) static::query()
            ->where('kode_produk', 'like', $prefix.'%')
            ->max('id');

        return $prefix.str_pad((string) ($maxId + 1), 4, '0', STR_PAD_LEFT);
    }
}
