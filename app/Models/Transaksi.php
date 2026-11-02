<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaksi extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'transaksi';

    /*
    |--------------------------------------------------------------------------
    | BUG-07 — Kamus status transaksi (satu sumber kebenaran)
    |--------------------------------------------------------------------------
    | Dua kolom paralel menyimpan status dengan tujuan berbeda:
    |   • `status_transaksi` — status BISNIS berbahasa Indonesia (title case),
    |     dipakai pada laporan & tampilan: Sukses | Gagal | Pending | Voided.
    |   • `status`           — status TEKNIS/alur kerja huruf kecil (snake case),
    |     dipakai logika aplikasi: success | rejected | pending_approval.
    | Sebelumnya penulisan status tersebar sebagai literal di banyak berkas
    | sehingga rawan tidak konsisten. Konstanta berikut memusatkannya.
    */
    public const STATUS_TRANSAKSI_SUKSES = 'Sukses';

    public const STATUS_TRANSAKSI_GAGAL = 'Gagal';

    public const STATUS_TRANSAKSI_PENDING = 'Pending';

    public const STATUS_TRANSAKSI_VOIDED = 'Voided';

    /**
     * Status sementara selama permohonan void menunggu otorisasi
     * (lihat `AjukanVoidAction`). Nilainya sengaja memakai spasi karena sudah
     * tersimpan begitu di data produksi; konstanta ini hanya memusatkan
     * penulisannya agar tidak lagi disebar sebagai literal.
     */
    public const STATUS_TRANSAKSI_PENDING_VOID = 'Pending Void';

    public const STATUS_TEKNIS_SUCCESS = 'success';

    public const STATUS_TEKNIS_REJECTED = 'rejected';

    public const STATUS_TEKNIS_PENDING_APPROVAL = 'pending_approval';

    /**
     * Status yang menandakan transaksi benar-benar terselesaikan (bisnis).
     *
     * HANYA `status_transaksi = 'Sukses'` yang dianggap berhasil. Kolom teknis
     * `status` sengaja TIDAK ikut di-OR-kan: saat void diajukan, transaksi
     * berubah menjadi `status_transaksi = 'Pending Void'` tetapi `status` tetap
     * `success` (lihat `AjukanVoidAction`). Bila OR dipertahankan, transaksi
     * yang justru sedang dibatalkan tetap lolos gerbang posting & rekonsiliasi
     * sehingga buku besar dianggap sehat padahal kas sudah ditarik kembali.
     *
     * Pengecualian untuk jurnal PEMBALIK (reversal) tidak diletakkan di sini,
     * melainkan pada konteks posting (`PostingJurnal::post(reversal: true)`)
     * dan ditandai permanen di kolom `jurnal.is_reversal`. Dengan begitu
     * pengecualian bersifat eksplisit, terbatas, dan dapat diaudit.
     */
    public function sukses(): bool
    {
        return $this->status_transaksi === self::STATUS_TRANSAKSI_SUKSES;
    }

    /** Status yang menandakan transaksi sedang menunggu otorisasi. */
    public function menungguOtorisasi(): bool
    {
        return $this->status_transaksi === self::STATUS_TRANSAKSI_PENDING
            || $this->status === self::STATUS_TEKNIS_PENDING_APPROVAL;
    }

    protected $fillable = [
        'no_transaksi',
        'waktu_transaksi',
        'rekening_id',
        'anggota_id',
        'pembiayaan_id',
        'jenis_transaksi',
        'posisi',
        'jumlah',
        'nominal',
        'saldo_setelah',
        'user_id',
        'sumber_transaksi',
        'jenis_pembayaran',
        'status_transaksi',
        'status',
        'approved_by',
        'approval_id',
        'dibuat_oleh',
        'alasan_penolakan',
        'is_large_transaction',
        'is_aml_high_value',
        'aml_sumber_dana',
        'aml_dokumen_pendukung',
        'aml_status_verifikasi',
        'aml_catatan_compliance',
        'referensi',
        'catatan',
    ];

    protected $casts = [
        'waktu_transaksi' => 'datetime',
        'jumlah' => 'decimal:2',
        'nominal' => 'decimal:2',
        'saldo_setelah' => 'decimal:2',
    ];

    /**
     * Kelengkapan status saat baris transaksi dibuat.
     *
     * Kolom `status_transaksi` ber-default `'Sukses'` di database. Tanpa
     * penjagaan ini, jalur yang membuat transaksi tanpa menyebut status akan
     * diam-diam dianggap BERHASIL — padahal belum tentu uang benar-benar
     * diterima. Perilaku seperti itu berbahaya: `sukses()` menjadi gerbang
     * posting jurnal, sehingga transaksi setengah jadi bisa masuk buku besar.
     *
     * Aturan yang dipakai:
     *  • bila `status_transaksi` tidak diisi → anggap `Pending` (belum selesai);
     *  • bila `status` (teknis) kosong → turunkan dari status bisnis agar kedua
     *    kolom tidak saling bertentangan sejak awal.
     *
     * Pemanggil yang memang tahu transaksi sudah berhasil tetap dapat menyetel
     * `Sukses` secara eksplisit (mis. `SetorTunaiAction` setelah kas diterima).
     */
    protected static function booted(): void
    {
        static::creating(function (self $transaksi): void {
            if (blank($transaksi->status_transaksi)) {
                $transaksi->status_transaksi = self::STATUS_TRANSAKSI_PENDING;
            }

            if (blank($transaksi->status)) {
                $transaksi->status = $transaksi->status_transaksi === self::STATUS_TRANSAKSI_SUKSES
                    ? self::STATUS_TEKNIS_SUCCESS
                    : self::STATUS_TEKNIS_PENDING_APPROVAL;
            }
        });
    }

    public function rekening(): BelongsTo
    {
        return $this->belongsTo(Rekening::class);
    }

    public function anggota(): BelongsTo
    {
        return $this->belongsTo(Anggota::class);
    }

    public function pembiayaan(): BelongsTo
    {
        return $this->belongsTo(Pembiayaan::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function dibuatOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function teller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function angsuran(): HasOne
    {
        return $this->hasOne(Angsuran::class);
    }

    public function jurnal(): HasOne
    {
        return $this->hasOne(Jurnal::class);
    }

    public function scopeSukses($query)
    {
        return $query->where('status_transaksi', 'Sukses');
    }

    public function scopeDebet($query)
    {
        return $query->where('posisi', 'Debet');
    }

    public function scopeKredit($query)
    {
        return $query->where('posisi', 'Kredit');
    }

    public function scopeBetweenDate($query, $tanggalAwal, $tanggalAkhir)
    {
        return $query->whereBetween('waktu_transaksi', [$tanggalAwal, $tanggalAkhir]);
    }

    public function getJumlahFormattedAttribute(): string
    {
        return 'Rp '.number_format($this->jumlah, 2, ',', '.');
    }

    /**
     * Apakah transaksi ini menambah saldo simpanan anggota (mutasi masuk)?
     *
     * Konvensi posisi mutasi simpanan: `posisi = 'Debet'` berarti uang MASUK
     * (menambah saldo rekening), `posisi = 'Kredit'` berarti uang KELUAR
     * (mengurangi saldo rekening). Lihat TransactionLimitService & TellerController.
     */
    public function isMasuk(): bool
    {
        // Posisi eksplisit adalah sumber kebenaran untuk arah mutasi rekening.
        if ($this->posisi === 'Debet') {
            return true;
        }
        if ($this->posisi === 'Kredit') {
            return false;
        }

        return in_array($this->jenis_transaksi, [
            'Setoran Tunai', 'Transfer Masuk', 'Bagi Hasil',
            'Simpanan Pokok', 'Simpanan Wajib', 'Simpanan Sukarela',
        ]);
    }

    /** Kebalikan dari {@see isMasuk()}: transaksi memotong saldo simpanan. */
    public function isKeluar(): bool
    {
        return ! $this->isMasuk();
    }
}
