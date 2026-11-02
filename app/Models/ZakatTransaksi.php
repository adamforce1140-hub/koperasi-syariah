<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Buku besar transaksi Modul Zakat & Infak/Sedekah (PSAK 109).
 *
 * Setiap baris adalah satu peristiwa: `penerimaan` (dana masuk dari muzakki)
 * atau `penyaluran` (dana keluar kepada mustahik/asnaf). Baris penerimaan
 * menyimpan `kategori` berupa jenis zakat, baris penyaluran menyimpan
 * `kategori` berupa kode asnaf.
 */
class ZakatTransaksi extends Model
{
    use HasFactory;

    protected $table = 'zakat_transaksis';

    public const JENIS_PENERIMAAN = 'penerimaan';

    public const JENIS_PENYALURAN = 'penyaluran';

    /**
     * Status baris buku besar.
     *  - posted               : transaksi berlaku (default).
     *  - void                 : transaksi DIBATALKAN — baris TIDAK dihapus agar
     *                           jejak audit utuh, jurnalnya dibalik (Z-01).
     *  - menunggu_persetujuan : penyaluran diajukan & menunggu persetujuan
     *                           pengurus/manajer LAIN; JURNAL BELUM diposting
     *                           (dual control maker-checker — Z-02).
     *  - ditolak              : pengajuan penyaluran ditolak penyetuju; tidak
     *                           pernah diposting (Z-02).
     */
    public const STATUS_POSTED = 'posted';

    public const STATUS_VOID = 'void';

    public const STATUS_MENUNGGU_PERSETUJUAN = 'menunggu_persetujuan';

    public const STATUS_DITOLAK = 'ditolak';

    public const DANA_ZAKAT = 'zakat';

    public const DANA_INFAK_SEDEKAH = 'infak_sedekah';

    /** Jenis zakat yang sah (PSAK 109). */
    public const KATEGORI_PENERIMAAN = [
        'zakat_maal' => 'Zakat Maal (Harta)',
        'zakat_penghasilan' => 'Zakat Penghasilan (Profesi)',
        'zakat_fitrah' => 'Zakat Fitrah',
        'zakat_perdagangan' => 'Zakat Perdagangan',
    ];

    /** Delapan asnaf penerima zakat (QS At-Taubah 60) + kategori infak/sedekah. */
    public const KATEGORI_PENYALURAN = [
        'fakir' => 'Fakir',
        'miskin' => 'Miskin',
        'amil' => 'Amil (Pengelola)',
        'muallaf' => 'Muallaf',
        'riqab' => 'Riqab (Memerdekakan Budak)',
        'gharim' => 'Gharim (Terlilit Utang)',
        'fisabilillah' => 'Fisabilillah (Jalan Allah)',
        'ibnu_sabil' => 'Ibnu Sabil (Musafir)',
        // Infak/sedekah tidak terikat 8 asnaf, tetapi tetap dikelompokkan
        // agar laporan sumber & penggunaan dana dapat dirinci.
        'kegiatan_sosial' => 'Kegiatan Sosial',
        'bantuan_pendidikan' => 'Bantuan Pendidikan',
        'bantuan_kesehatan' => 'Bantuan Kesehatan',
    ];

    protected $fillable = [
        'no_referensi',
        'jenis',
        // Z-01 — status & jejak pembatalan (void).
        'status',
        'dana',
        'kategori',
        'pihak',
        // R-08 (Z-07) — relasi OPSIONAL ke master anggota. NULL = pihak
        // eksternal (bukan anggota koperasi) yang dicatat sebagai teks `pihak`.
        'pihak_id',
        'jumlah',
        'keterangan',
        'void_at',
        'void_by',
        'void_reason',
        // Z-02 — jejak persetujuan (dual control maker-checker).
        'disetujui_oleh',
        'disetujui_pada',
        'alasan_tolak',
        // Z-02 — akun kas/bank tujuan, disimpan saat pengajuan agar diketahui
        // saat persetujuan diproses (jurnal baru diposting pada saat setuju).
        'kode_kas',
        'tanggal',
        'jurnal_id',
        'created_by',
    ];

    protected $casts = [
        'jumlah' => 'decimal:2',
        'tanggal' => 'date',
        'void_at' => 'datetime',
        'disetujui_pada' => 'datetime',
    ];

    public function jurnal(): BelongsTo
    {
        return $this->belongsTo(Jurnal::class);
    }

    /**
     * Z-05 — relasi ke pelaku pencatatan (jejak audit). FK `created_by` →
     * `users.id` (nullOnDelete) sehingga relasi boleh kosong (NULL = sistem).
     */
    public function pencatat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * R-08 (Z-07) — relasi terstruktur ke master anggota (bila pihak adalah
     * anggota koperasi). Relasi ini OPSIONAL: baris dengan `pihak_id` NULL
     * tetap sah dan mengandalkan teks `pihak` (fallback pihak eksternal).
     *
     * Nama method `pihakRelasi` dipakai agar tidak menabrak atribut `pihak`
     * (kolom teks) pada Eloquent.
     */
    public function pihakRelasi(): BelongsTo
    {
        return $this->belongsTo(Anggota::class, 'pihak_id');
    }

    /**
     * Nama pihak untuk tampilan: pakai snapshot teks `pihak` bila ada, dan
     * lengkapi dengan penanda anggota (no_anggota) bila tertaut ke master.
     * Aman terhadap relasi yang sudah terhapus (nullOnDelete).
     */
    public function getPihakTampilAttribute(): string
    {
        $teks = trim((string) $this->pihak);

        // `withTrashed` agar jejak historis tetap dapat ditampilkan walau
        // anggota telah di-soft-delete (foreign key memakai nullOnDelete,
        // namun relasi lama dapat saja masih menunjuk anggota non-aktif).
        if ($this->pihak_id) {
            $anggota = $this->relationLoaded('pihakRelasi')
                ? $this->pihakRelasi
                : Anggota::withTrashed()->find($this->pihak_id);

            if ($anggota) {
                return $teks !== '' ? $teks : (string) $anggota->nama_lengkap;
            }
        }

        return $teks;
    }

    /** Apakah transaksi ini tertaut ke master anggota koperasi. */
    public function getTertautAnggotaAttribute(): bool
    {
        return ! empty($this->pihak_id);
    }

    /** Label manusiawi jenis/kategori (asnaf atau jenis zakat). */
    public function getKategoriLabelAttribute(): string
    {
        return self::KATEGORI_PENERIMAAN[$this->kategori]
            ?? self::KATEGORI_PENYALURAN[$this->kategori]
            ?? ucfirst(str_replace('_', ' ', (string) $this->kategori));
    }

    /** Label dana yang dikelola: Zakat / Infak & Sedekah. */
    public function getDanaLabelAttribute(): string
    {
        return $this->dana === self::DANA_INFAK_SEDEKAH ? 'Infak & Sedekah' : 'Zakat';
    }

    public function getJenisLabelAttribute(): string
    {
        return $this->jenis === self::JENIS_PENYALURAN ? 'Penyaluran' : 'Penerimaan';
    }

    /** Apakah transaksi ini sudah dibatalkan (void)? */
    public function getSudahVoidAttribute(): bool
    {
        return $this->status === self::STATUS_VOID;
    }

    /** Apakah penyaluran ini menunggu persetujuan (belum diposting) — Z-02. */
    public function getMenungguPersetujuanAttribute(): bool
    {
        return $this->status === self::STATUS_MENUNGGU_PERSETUJUAN;
    }

    /** Apakah pengajuan penyaluran ini ditolak — Z-02. */
    public function getDitolakAttribute(): bool
    {
        return $this->status === self::STATUS_DITOLAK;
    }

    /** Label manusiawi status baris buku besar. */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_VOID => 'Dibatalkan',
            self::STATUS_MENUNGGU_PERSETUJUAN => 'Menunggu Persetujuan',
            self::STATUS_DITOLAK => 'Ditolak',
            default => 'Berlaku',
        };
    }

    /** Scope: hanya transaksi yang BERLAKU (belum dibatalkan) — Z-01. */
    public function scopeBerlaku($query)
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    /** Scope: penyaluran yang menunggu persetujuan (Z-02). */
    public function scopeMenungguPersetujuan($query)
    {
        return $query->where('status', self::STATUS_MENUNGGU_PERSETUJUAN);
    }
}
