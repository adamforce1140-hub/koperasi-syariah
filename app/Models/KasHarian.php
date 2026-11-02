<?php

namespace App\Models;

use App\Support\JenisTransaksi;
use App\Support\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class KasHarian extends Model
{
    protected $table = 'kas_harian';

    /**
     * Jenis transaksi yang menambah kas fisik teller (cash in).
     * Catatan: 'Simpanan Sukarela' & 'Setoran Simpanan Berjangka' ikut dihitung
     * karena keduanya juga menyetor uang tunai ke loket.
     *
     * PENTING: 'Injeksi Kas Brankas' SENGAJA tidak didaftarkan di sini. Injeksi
     * Vault → Teller sudah menambah `modal_awal` lewat tambahModalKas(), dan
     * `modal_awal` adalah SATU-SATUNYA sumber kas awal pada hitungSaldoKasTeller().
     * Bila jenis ini ikut dijumlahkan sebagai cash in, nominal injeksi akan
     * terhitung dua kali (double counting) pada saldo & rekonsiliasi closing.
     */
    public const JENIS_CASH_IN = JenisTransaksi::CASH_IN;

    /**
     * Jenis transaksi yang mengurangi kas fisik teller (cash out).
     * Catatan: 'Pencairan Deposito' juga mengeluarkan uang tunai dari loket.
     *
     * Nilai dipusatkan di App\Support\JenisTransaksi::CASH_OUT (satu sumber
     * kebenaran). Konstanta ini dipertahankan sebagai alias agar pemakai lama
     * (rincian kas, closing, guard penarikan) tetap kompatibel.
     */
    public const JENIS_CASH_OUT = JenisTransaksi::CASH_OUT;

    /** Status transaksi yang tidak boleh dihitung sebagai mutasi kas fisik. */
    public const STATUS_NON_KAS = JenisTransaksi::STATUS_NON_KAS;

    /** Siklus hidup sesi Buka Kas Teller. */
    public const STATUS_OPEN = 'OPEN';

    public const STATUS_PENDING_APPROVAL = 'PENDING_APPROVAL';

    public const STATUS_CLOSED = 'CLOSED';

    protected $fillable = [
        'user_id',
        'tanggal',
        'modal_awal',
        'tambah_modal',
        'catatan_opening',
        'waktu_opening',
        'status_opname',
        'fisik_kas',
        'saldo_sistem',
        'selisih',
        'status_closing',
        'catatan_closing',
        'waktu_closing',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'modal_awal' => 'decimal:2',
        'tambah_modal' => 'array',
        'fisik_kas' => 'decimal:2',
        'saldo_sistem' => 'decimal:2',
        'selisih' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Pengajuan opname/closing untuk sesi ini (satu sesi = satu pengajuan aktif). */
    public function opname(): HasMany
    {
        return $this->hasMany(TellerOpname::class, 'kas_harian_id');
    }

    /** Pengajuan opname terbaru — dipakai UI untuk menampilkan status terkini. */
    public function opnameTerakhir(): HasOne
    {
        return $this->hasOne(TellerOpname::class, 'kas_harian_id')->latestOfMany();
    }

    public function isOpen(): bool
    {
        return $this->status_opname === self::STATUS_OPEN;
    }

    public function isPendingApproval(): bool
    {
        return $this->status_opname === self::STATUS_PENDING_APPROVAL;
    }

    public function isStatusClosed(): bool
    {
        return $this->status_opname === self::STATUS_CLOSED;
    }

    public static function labelStatusOpname(?string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING_APPROVAL => 'Menunggu Approval Manajer',
            self::STATUS_CLOSED => 'Selesai (Closing Disetujui)',
            default => 'Kas Terbuka',
        };
    }

    /** Total modal kas hari ini (sudah termasuk semua penambahan modal). */
    public function getTotalModalAttribute(): float
    {
        return (float) $this->modal_awal;
    }

    /**
     * Tambah modal kas di tengah hari.
     *
     * `modal_awal` adalah SATU-SATUNYA sumber nilai kas awal (dipakai oleh
     * hitungSaldoKasTeller, rekap/closing, dan guard penarikan). Sedangkan
     * `tambah_modal` hanya menyimpan riwayat/jejak audit penambahannya.
     *
     * @return float Total modal setelah penambahan.
     */
    public function tambahModalKas(float $nominal, ?string $keterangan = null, ?string $waktu = null): float
    {
        $riwayat = $this->tambah_modal ?? [];
        $riwayat[] = [
            'nominal' => $nominal,
            'keterangan' => $keterangan ?: 'Suntikan dari brankas',
            'waktu' => $waktu ?? now()->format('H:i:s'),
        ];

        $totalModal = (float) $this->modal_awal + $nominal;

        $this->update([
            'modal_awal' => $totalModal,
            'tambah_modal' => $riwayat,
        ]);

        return $totalModal;
    }

    /**
     * Hitung saldo kas fisik teller: Kas Awal + Total Cash In - Total Cash Out.
     *
     * Cash in  = setoran simpanan tunai (Sukarela/Pokok/Wajib/Berjangka) + angsuran + biaya/denda.
     * Cash out = penarikan tunai + pencairan pembiayaan/deposito.
     * Transaksi berstatus Gagal/Dibatalkan/Voided/Pending tidak diperhitungkan.
     * Transaksi ber-`jenis_pembayaran` non-kas (mis. autodebet angsuran yang
     * memindahkan dana dari Simpanan Sukarela) juga tidak diperhitungkan —
     * uangnya tidak pernah masuk laci teller.
     *
     * Transaksi lama yang hanya tercatat pada kolom `dibuat_oleh` (sebelum kolom
     * `user_id` terisi) tetap terhitung, sehingga data shift lama tidak hilang.
     */
    public static function hitungSaldoKasTeller(int $userId, ?string $tanggal = null): array
    {
        $tanggal = $tanggal ?? now()->toDateString();
        $kasHarian = static::where('user_id', $userId)->where('tanggal', $tanggal)->first();
        $kasAwal = (float) ($kasHarian?->modal_awal ?? 0);

        $base = static::kecualikanPembayaranNonKas(static::queryTransaksiKas($userId, $tanggal));

        $cashIn = (float) (clone $base)->whereIn('jenis_transaksi', self::JENIS_CASH_IN)->sum('jumlah');
        $cashOut = (float) (clone $base)->whereIn('jenis_transaksi', self::JENIS_CASH_OUT)->sum('jumlah');

        return [
            'kas_awal' => $kasAwal,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'saldo' => $kasAwal + $cashIn - $cashOut,
        ];
    }

    /**
     * Saldo kas Brankas Utama (Vault) secara real-time.
     *
     * Dihitung dari saldo akun CoA 1-1003 pada buku besar (jurnal Posted),
     * sehingga selalu konsisten dengan neraca: setiap mutasi Brankas —
     * injeksi ke teller, setoran hasil kas teller, maupun pemindahan saat
     * closing — otomatis tercermin tanpa pencatatan ganda.
     *
     * Bila akun CoA belum tersedia (mis. saat seeding awal), saldo = 0
     * sehingga transaksi keluar dari Vault akan ditolak oleh guard, bukan
     * menghasilkan angka negatif yang menyesatkan.
     */
    public static function saldoBrankas(): float
    {
        $akun = Coa::findByKode(\App\Support\Coa::KAS_BRANKAS);

        if (! $akun) {
            return 0.0;
        }

        $debet = (float) JurnalDetail::where('coa_id', $akun->id)->sum('debet');
        $kredit = (float) JurnalDetail::where('coa_id', $akun->id)->sum('kredit');

        return Setting::bulatkanUang($debet - $kredit);
    }

    /**
     * Query dasar transaksi yang memengaruhi kas fisik seorang teller pada
     * tanggal tertentu. Dipakai bersama oleh halaman Opening, Rekap/Closing,
     * dan guard penarikan agar sumber angkanya selalu sama.
     */
    public static function queryTransaksiKas(int $userId, string $tanggal)
    {
        return Transaksi::where(function ($q) use ($userId) {
            $q->where('user_id', $userId)
                ->orWhere('dibuat_oleh', $userId);
        })
            ->whereDate('waktu_transaksi', $tanggal)
            ->whereNotIn('status_transaksi', self::STATUS_NON_KAS);
    }

    /**
     * Keluarkan transaksi yang bukan kas fisik (mis. autodebet angsuran yang
     * memindahkan dana dari Simpanan Sukarela). Tanpa filter ini, transaksi
     * ber-`jenis_transaksi = 'Pembayaran Angsuran'` hasil autodebet ikut
     * terhitung sebagai cash-in laci, sehingga saldo kas teller overstated
     * dan guard penarikan tunai menjadi terlalu longgar.
     */
    private static function kecualikanPembayaranNonKas($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('jenis_pembayaran')
                ->orWhereNotIn('jenis_pembayaran', JenisTransaksi::PEMBAYARAN_NON_KAS);
        });
    }

    /**
     * Ringkasan posisi kas untuk tampilan (Opening & Rekap/Closing).
     *
     * Semua angka ringkasan dihitung dari satu sumber ini supaya kartu-kartu
     * ringkasan tidak pernah berbeda dengan saldo kas yang ditampilkan.
     */
    public static function ringkasanPosisiKas(int $userId, ?string $tanggal = null): array
    {
        $tanggal = $tanggal ?? now()->toDateString();

        $transaksi = static::queryTransaksiKas($userId, $tanggal)
            ->get(['jenis_transaksi', 'jenis_pembayaran', 'jumlah', 'status_transaksi']);

        $in = array_fill_keys(self::JENIS_CASH_IN, 0.0);
        $out = array_fill_keys(self::JENIS_CASH_OUT, 0.0);

        foreach ($transaksi as $trx) {
            $jenis = (string) $trx->jenis_transaksi;
            $nilai = (float) $trx->jumlah;

            // Baris non-kas (mis. autodebet angsuran) hanya pemindahan dana —
            // tidak pernah menyentuh laci teller, jadi tidak dihitung.
            if (in_array((string) $trx->jenis_pembayaran, JenisTransaksi::PEMBAYARAN_NON_KAS, true)) {
                continue;
            }

            // Klasifikasi arah kas memakai registri tunggal JenisTransaksi.
            // Jika nilai tidak terdaftar (mis. auto-debit/ta'zir susulan yang
            // bersifat pemindahan, bukan kas fisik), transaksi sengaja
            // diabaikan agar tidak salah menambah/mengurangi kas fisik.
            if (in_array($jenis, self::JENIS_CASH_IN, true) && array_key_exists($jenis, $in)) {
                $in[$jenis] += $nilai;
            } elseif (in_array($jenis, self::JENIS_CASH_OUT, true) && array_key_exists($jenis, $out)) {
                $out[$jenis] += $nilai;
            }
        }

        $kasAwal = (float) (static::where('user_id', $userId)->where('tanggal', $tanggal)->value('modal_awal') ?? 0);
        $cashIn = array_sum($in);
        $cashOut = array_sum($out);

        return [
            'tanggal' => $tanggal,
            'kas_awal' => $kasAwal,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'saldo' => $kasAwal + $cashIn - $cashOut,
            'jumlah_transaksi' => $transaksi->count(),
            'rincian_in' => $in,
            'rincian_out' => $out,
        ];
    }

    /** Cek apakah sudah closing */
    public function sudahClosing(): bool
    {
        return $this->waktu_closing !== null;
    }
}
