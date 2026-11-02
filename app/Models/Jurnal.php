<?php

namespace App\Models;

use App\Services\PeriodeAkuntansiService;
use App\Support\Setting;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class Jurnal extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'jurnal';

    protected $fillable = [
        'no_jurnal',
        'tanggal_jurnal',
        'jenis_jurnal',
        'is_reversal',
        'transaksi_id',
        'pembiayaan_id',
        'sumber',
        'no_ref',
        'keterangan',
        'total_debet',
        'total_kredit',
        'is_balance',
        'status',
        'posted_at',
        'user_posted',
        'user_id',
    ];

    protected $casts = [
        'tanggal_jurnal' => 'date',
        'total_debet' => 'decimal:2',
        'total_kredit' => 'decimal:2',
        'is_balance' => 'boolean',
        'is_reversal' => 'boolean',
        'posted_at' => 'datetime',
    ];

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(Transaksi::class);
    }

    public function pembiayaan(): BelongsTo
    {
        return $this->belongsTo(Pembiayaan::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_posted');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function detail(): HasMany
    {
        return $this->hasMany(JurnalDetail::class)->orderBy('baris');
    }

    public function scopePosted($query)
    {
        return $query->where('status', 'Posted');
    }

    /**
     * Jurnal PEMBALIK (reversal) — jurnal yang sengaja dibuat untuk membatalkan
     * efek jurnal transaksi asal (void / penolakan AML).
     *
     * Penanda ini dipakai rekonsiliasi untuk membedakan pembalikan yang SAH
     * dari jurnal transaksi gagal yang lolos karena kelalaian posting.
     */
    public function scopeReversal($query)
    {
        return $query->where('is_reversal', true);
    }

    /**
     * Pengaman balance jurnal di level model.
     *
     * Sebagian alur membuat jurnal LANGSUNG (Jurnal::create lalu
     * JurnalDetail::create) tanpa melewati AutoJournalingService::post(),
     * sehingga validasi di service tidak berlaku untuk jurnal tersebut.
     *
     * `saving` dipakai untuk menutup jalur UPDATE: setiap kali header jurnal
     * disimpan ulang, total debet/kredit dihitung ulang dari baris detail yang
     * benar-benar ada di database — bukan dari angka yang dihitung di memori.
     * Jurnal yang tidak balance ditolak dengan RuntimeException agar transaksi
     * pemanggil ikut di-rollback.
     *
     * Untuk jalur CREATE (header dulu, detail menyusul) pemeriksaan dilakukan
     * setelah baris terakhir ditulis melalui pastikanBalance().
     */
    protected static function booted(): void
    {
        /* F-04 — GERBANG CUT-OFF PERIODE UNIVERSAL.
         | Sebelumnya gerbang tutup buku hanya dipasang di
         | AutoJournalingService::post(), sehingga jurnal yang dibuat lewat
         | `Jurnal::create` langsung (IkhtisarShuService, ShuCalculatorService,
         | JurnalController, dsb.) dapat diposting mundur ke periode yang sudah
         | ditutup → laporan periode tertutup berubah retroaktif (PSAK 101).
         |
         | Gerbang dipasang di event `creating` (bukan `updating`) supaya:
         |   1. Setiap jurnal BARU melewati satu titik kontrol yang sama.
         |   2. Perubahan status jurnal lama (post/void) tidak ikut terblokir —
         |      integritas periode tetap terjaga karena tidak ada baris baru
         |      yang masuk ke periode tertutup.
         | Jurnal penutup (sumber 'TutupBuku') dikecualikan oleh service karena
         | justru dialah yang menutup periode. Bila cut-off belum diatur,
         | gerbang ini tidak berpengaruh sama sekali (perilaku lama terjaga). */
        static::creating(function (Jurnal $jurnal) {
            app(PeriodeAkuntansiService::class)->pastikanDapatDiposting(
                $jurnal->tanggal_jurnal,
                (string) ($jurnal->sumber ?? ''),
                static::class.'::creating'
            );
        });

        static::saving(function (Jurnal $jurnal) {
            // Jurnal baru belum punya baris detail — lihat pastikanBalance().
            if (! $jurnal->exists) {
                return;
            }

            // Hanya jurnal POSTED yang wajib balance. Jurnal Draft memang boleh
            // belum balance karena masih dalam proses pengisian baris.
            if ($jurnal->status !== 'Posted') {
                return;
            }

            $debet = (float) DB::table('jurnal_detail')->where('jurnal_id', $jurnal->id)->sum('debet');
            $kredit = (float) DB::table('jurnal_detail')->where('jurnal_id', $jurnal->id)->sum('kredit');

            // Belum ada detail sama sekali → jangan paksa balance saat header disimpan.
            if ($debet == 0.0 && $kredit == 0.0) {
                return;
            }

            $jurnal->total_debet = $debet;
            $jurnal->total_kredit = $kredit;
            // Satu sumber aturan toleransi dengan pastikanBalance(), AutoJournalingService
            // & IkhtisarShuService — parameter kebijakan, bukan angka yang ditanam.
            $jurnal->is_balance = abs($debet - $kredit) < Setting::toleransiSelisihJurnal();

            if (! $jurnal->is_balance) {
                throw new RuntimeException(
                    'Jurnal ['.$jurnal->no_jurnal.'] tidak balance: debet Rp '
                    .number_format($debet, 2, ',', '.').' vs kredit Rp '
                    .number_format($kredit, 2, ',', '.').'. Penyimpanan dibatalkan.'
                );
            }
        });
    }

    /**
     * Verifikasi & simpan ulang header jurnal setelah seluruh baris detailnya
     * tertulis. Dipanggil di akhir setiap alur pembuatan jurnal langsung yang
     * tidak melewati AutoJournalingService::post().
     *
     * @throws RuntimeException bila debet ≠ kredit atau jurnal tanpa detail
     *                          (pemanggil di dalam DB::transaction ikut rollback).
     */
    public function pastikanBalance(): self
    {
        $debet = (float) $this->detail()->sum('debet');
        $kredit = (float) $this->detail()->sum('kredit');

        // Satu sumber aturan toleransi dengan AutoJournalingService & IkhtisarShuService.
        $toleransi = Setting::toleransiSelisihJurnal();

        if (abs($debet - $kredit) >= $toleransi) {
            throw new RuntimeException(
                'Jurnal ['.$this->no_jurnal.'] tidak balance: debet Rp '
                .number_format($debet, 2, ',', '.').' vs kredit Rp '
                .number_format($kredit, 2, ',', '.').'. Seluruh jurnal dibatalkan.'
            );
        }

        if ($debet == 0.0 && $kredit == 0.0) {
            throw new RuntimeException(
                'Jurnal ['.$this->no_jurnal.'] tidak memiliki baris detail — jurnal kosong ditolak.'
            );
        }

        $this->total_debet = $debet;
        $this->total_kredit = $kredit;
        $this->is_balance = true;
        $this->save();

        return $this;
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'Draft');
    }

    public function scopeBetweenDate($query, $tanggalAwal, $tanggalAkhir)
    {
        return $query->whereBetween('tanggal_jurnal', [$tanggalAwal, $tanggalAkhir]);
    }

    public function isDraft(): bool
    {
        return $this->status === 'Draft';
    }

    public function isPosted(): bool
    {
        return $this->status === 'Posted';
    }

    public function isVoid(): bool
    {
        return $this->status === 'Void';
    }

    public function hitungBalance(): bool
    {
        $totalDebet = $this->detail()->sum('debet');
        $totalKredit = $this->detail()->sum('kredit');
        $this->total_debet = $totalDebet;
        $this->total_kredit = $totalKredit;
        $this->is_balance = abs($totalDebet - $totalKredit) < Setting::toleransiSelisihJurnal();

        return $this->is_balance;
    }

    public function getTotalDebetFormattedAttribute(): string
    {
        return 'Rp '.number_format($this->total_debet, 2, ',', '.');
    }

    public function getTotalKreditFormattedAttribute(): string
    {
        return 'Rp '.number_format($this->total_kredit, 2, ',', '.');
    }
}
