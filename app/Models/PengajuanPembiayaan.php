<?php

namespace App\Models;

use App\Services\AutoJournalingService;
use App\Support\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PengajuanPembiayaan extends Model
{
    protected $table = 'pengajuan_pembiayaan';

    /* ---------------------------------------------------------------------
     | Status Pengajuan Pembiayaan — nilai kanonik (mengikuti ENUM kolom).
     | Semua penulisan status WAJIB memakai konstanta di bawah ini.
     | ------------------------------------------------------------------- */
    public const STATUS_MENUNGGU = 'Menunggu';

    public const STATUS_DIREVIEW = 'Direview';

    public const STATUS_DIPROSES = 'Diproses';

    public const STATUS_DISETUJUI = 'Disetujui';

    public const STATUS_DITOLAK = 'Ditolak';

    /**
     * Alias / varian lama -> nilai kanonik.
     * Kunci selalu lowercase; pencocokan dilakukan case-insensitive.
     */
    private const STATUS_ALIASES = [
        'pending' => self::STATUS_MENUNGGU,
        'pending_ao' => self::STATUS_MENUNGGU,
        'menunggu' => self::STATUS_MENUNGGU,
        'menunggu_survei' => self::STATUS_MENUNGGU,
        'diajukan' => self::STATUS_MENUNGGU,
        'draft' => self::STATUS_MENUNGGU,
        'dalam proses' => self::STATUS_DIPROSES,
        'survei selesai' => self::STATUS_DIREVIEW,
        'menunggu_approval_manajer' => self::STATUS_DIREVIEW,
        'pending_manajer' => self::STATUS_DIREVIEW,
        'direview manajer' => self::STATUS_DIREVIEW,
        'review' => self::STATUS_DIREVIEW,
        'menunggu persetujuan' => self::STATUS_DISETUJUI,
        'menunggu_approval_pengurus' => self::STATUS_DISETUJUI,
        'pending_pengurus' => self::STATUS_DISETUJUI,
        'disetujui pengurus' => self::STATUS_DISETUJUI,
        'menunggu dps' => self::STATUS_DISETUJUI,
        'approved' => self::STATUS_DISETUJUI,
        'disetujui' => self::STATUS_DISETUJUI,
        'rejected' => self::STATUS_DITOLAK,
        'ditolak' => self::STATUS_DITOLAK,
    ];

    /**
     * Normalisasi nilai status ke bentuk kanonik (case-insensitive).
     */
    public static function normalizeStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        $key = strtolower(trim($status));

        if ($key === '') {
            return $status;
        }

        return self::STATUS_ALIASES[$key] ?? $status;
    }

    /**
     * Daftar seluruh nilai kanonik yang dikenal.
     *
     * @return array<int, string>
     */
    public static function canonicalStatuses(): array
    {
        return [
            self::STATUS_MENUNGGU,
            self::STATUS_DIREVIEW,
            self::STATUS_DIPROSES,
            self::STATUS_DISETUJUI,
            self::STATUS_DITOLAK,
        ];
    }

    protected $fillable = [
        'no_pengajuan',
        'anggota_id',
        'produk_id',
        'jenis_akad',
        'margin_persen',
        'jumlah_pengajuan',
        'total_pembiayaan',
        'angsuran_per_bulan',
        'tenor',
        'tujuan',
        'agunan',
        'status',
        'diproses_oleh',
        'tanggal_diproses',
        'catatan_pengelola',
        'pembiayaan_id',
        'penghasilan_bulanan',
        'pengeluaran_bulanan',
        'jumlah_tanggungan',
        'agunan_info',
        'step_terakhir',
        'ao_id',
        'created_by',
        'analisis_5c',
        'catatan_survei',
        // Approval bertingkat
        'direview_oleh',
        'tanggal_direview',
        'catatan_manajer',
        'disetujui_oleh',
        'tanggal_disetujui',
        'catatan_pengurus',
    ];

    protected $casts = [
        'margin_persen' => 'decimal:2',
        'jumlah_pengajuan' => 'decimal:2',
        'total_pembiayaan' => 'decimal:2',
        'angsuran_per_bulan' => 'decimal:2',
        'tanggal_diproses' => 'datetime',
        'tanggal_disetujui' => 'datetime',
        'penghasilan_bulanan' => 'decimal:2',
        'pengeluaran_bulanan' => 'decimal:2',
        'agunan_info' => 'array',
        'analisis_5c' => 'array',
    ];

    public function anggota(): BelongsTo
    {
        return $this->belongsTo(Anggota::class);
    }

    public function produk(): BelongsTo
    {
        return $this->belongsTo(MasterProdukPembiayaan::class, 'produk_id');
    }

    public function diprosesOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diproses_oleh');
    }

    public function ao(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ao_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function pembiayaan(): BelongsTo
    {
        return $this->belongsTo(Pembiayaan::class);
    }

    public function dokumen(): HasMany
    {
        return $this->hasMany(DokumenPengajuan::class, 'pengajuan_id');
    }

    /**
     * Accessor: semua pembacaan status selalu dalam bentuk kanonik.
     */
    public function getStatusAttribute($value): ?string
    {
        return self::normalizeStatus($value);
    }

    /**
     * Mutator: semua penulisan status selalu disimpan dalam bentuk kanonik.
     */
    public function setStatusAttribute($value): void
    {
        $this->attributes['status'] = self::normalizeStatus(
            $value === null ? null : (string) $value
        );
    }

    /**
     * Scope query status yang case-insensitive.
     *
     * @param  Builder  $query
     * @param  array<int, string>  $statuses
     */
    public function scopeStatusIn($query, array $statuses)
    {
        $normalized = array_values(array_unique(array_filter(
            array_map(fn ($s) => self::normalizeStatus($s), $statuses),
            fn ($s) => $s !== null && $s !== ''
        )));

        if (empty($normalized)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn(DB::raw('LOWER(status)'), array_map('strtolower', $normalized));
    }

    /**
     * Scope: pengajuan yang statusnya sudah disetujui tetapi belum punya
     * record Pembiayaan (proses sinkronisasi gagal / terputus).
     *
     * Dipakai sebagai pengecekan validasi konsistensi data antar tabel
     * (pengajuan_pembiayaan ↔ pembiayaan) di Dashboard Pengurus.
     */
    public function scopeBelumSinkron($query)
    {
        return $query->statusIn([self::STATUS_DISETUJUI])
            ->whereNull('pembiayaan_id');
    }

    /**
     * Scope pengajuan yang masih menunggu proses (belum disetujui/ditolak).
     */
    public function scopeMenunggu($query)
    {
        return $query->statusIn([
            self::STATUS_MENUNGGU,
            self::STATUS_DIREVIEW,
            self::STATUS_DIPROSES,
        ]);
    }

    /**
     * Cek status saat ini terhadap satu atau beberapa status (case-insensitive).
     *
     * @param  string|array<int, string>  $statuses
     */
    public function isStatus(string|array $statuses): bool
    {
        $current = self::normalizeStatus($this->status);

        foreach ((array) $statuses as $status) {
            if ($current === self::normalizeStatus($status)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pemetaan akad → kode akun piutang pada tabel `akun`
     * (kolom `pembiayaan.akun_id` ber-FK ke tabel `akun`, bukan `coas`).
     *
     * R11 — WAJIB mencakup seluruh 8 akad kanonik `Pembiayaan::daftarAkad()`;
     * tanpa pemetaan, akad Istishna/Salam/IMBT akan jatuh ke akun cadangan
     * Murabahah dan laporan piutang per akad menjadi salah.
     */
    private const AKUN_PIUTANG_PER_AKAD = [
        'Murabahah' => '121',
        'Mudharabah' => '122',
        'Qardh' => '123',
        'Musyarakah' => '124',
        'Ijarah' => '125',
        'Istishna' => '126',
        'Salam' => '127',
        // IMBT berakhir dengan perpindahan kepemilikan → turunan Murabahah.
        'Ijarah Muntahia Bittamlik' => '121',
    ];

    /**
     * Kode akun piutang cadangan bila akad tidak dikenali.
     */
    private const KODE_AKUN_PIUTANG_DEFAULT = '121';

    /**
     * ID akun legacy (`akun.id`) untuk sebuah akad pembiayaan.
     *
     * Kolom `pembiayaan.akun_id` ber-FK NOT NULL ke tabel `akun` (legacy),
     * sehingga wajib diisi. Akuntansi sesungguhnya memakai CoA, namun kolom ini
     * tetap harus menunjuk akun yang PERSIS sesuai akad — bukan akun sembarang.
     *
     * Urutan resolusi (seluruhnya dinamis, tanpa `Akun::first()`):
     *   1. kode akun piutang per akad (kanonik, lihat AKUN_PIUTANG_PER_AKAD);
     *   2. padanan kode CoA → kode legacy via App\Support\Coa::legacy();
     *   3. pencocokan NAMA akun piutang dari master CoA (untuk akad tambahan
     *      yang dipetakan lewat `pemetaan_akad_coa`).
     *
     * Mengembalikan null bila tidak ada padanan; pemanggil bertanggung jawab
     * menangani (Log peringatan + pilihan akun fallback yang eksplisit).
     */
    public static function akunLegacyIdUntukAkad(?string $akad): ?int
    {
        $kodeLegacy = self::AKUN_PIUTANG_PER_AKAD[$akad] ?? self::KODE_AKUN_PIUTANG_DEFAULT;

        $id = Akun::where('kode_akun', $kodeLegacy)->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        // Akad tambahan: turunkan kode legacy dari padanan CoA-nya.
        $kodeCoa = \App\Support\Coa::piutangByAkad($akad);
        $kodeLegacy = \App\Support\Coa::legacy($kodeCoa) ?? $kodeLegacy;

        $id = Akun::where('kode_akun', $kodeLegacy)->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        // Terakhir: cocokkan nama akun piutang dari master CoA (mis. akad
        // tambahan yang dipetakan pengurus) agar tetap mengarah ke akun sejenis.
        $coa = Coa::findByKode($kodeCoa);

        if ($coa !== null) {
            $id = Akun::where('nama_akun', $coa->nama_akun)->value('id');
        }

        return $id !== null ? (int) $id : null;
    }

    /**
     * Kelayakan data pengajuan untuk membuat record Pembiayaan.
     *
     * Sebuah pengajuan hanya boleh disinkronkan bila sudah disetujui dan
     * usulan pembiayaannya terisi lengkap. Tanpa pengecekan ini, pengajuan
     * dapat berstatus 'Disetujui' tetapi tidak pernah punya kontrak di tabel
     * pembiayaan (kasus data menggantung yang membuat angka dashboard tidak
     * cocok dengan jumlah pengajuan yang disetujui).
     *
     * @param  bool  $periksaStatus  false = lewati syarat status (dipakai saat
     *                               kontrak memang dibuat pada tahap review, mis. antrian Manajer).
     * @return array<int, string> daftar alasan tidak layak (kosong = layak)
     */
    public function alasanTidakLayakSinkron(bool $periksaStatus = true): array
    {
        $alasan = [];

        if ($periksaStatus && ! $this->isStatus(self::STATUS_DISETUJUI)) {
            $alasan[] = "status masih '{$this->status}', belum Disetujui";
        }

        if (! $this->anggota_id) {
            $alasan[] = 'anggota_id kosong';
        }

        $jumlah = (float) ($this->jumlah_pengajuan ?? $this->total_pembiayaan ?? 0);
        if ($jumlah <= 0) {
            $alasan[] = 'jumlah_pengajuan / total_pembiayaan kosong atau nol';
        }

        if ((int) ($this->tenor ?? 0) <= 0) {
            $alasan[] = 'tenor kosong atau nol';
        }

        return $alasan;
    }

    /**
     * Sinkronkan pengajuan yang sudah disetujui menjadi record Pembiayaan.
     *
     * Menjamin invarian: setiap pengajuan berstatus Disetujui memiliki tepat
     * satu kontrak di tabel pembiayaan (kolom pembiayaan_id terisi).
     *
     * Idempoten — aman dipanggil berulang; mengembalikan Pembiayaan yang ada
     * bila tautan sudah terbentuk.
     *
     * @param  string|null  $statusPembiayaan  status awal kontrak;
     *                                         bawaan = menunggu pengesahan DPS, karena pengajuan yang
     *                                         disinkronkan di sini sudah lolos persetujuan Pengurus.
     * @param  bool  $periksaStatus  false = buat kontrak meski pengajuan belum
     *                               berstatus Disetujui (dipakai antrian review Manajer).
     * @return Pembiayaan|null null bila pengajuan tidak layak disinkronkan
     */
    public function sinkronkanKePembiayaan(?string $statusPembiayaan = null, bool $periksaStatus = true): ?Pembiayaan
    {
        $statusPembiayaan ??= Pembiayaan::STATUS_PENDING_DPS;

        // Tautan sudah ada dan record-nya masih hidup → tidak perlu apa-apa.
        if ($this->pembiayaan_id && ($existing = Pembiayaan::find($this->pembiayaan_id))) {
            return $existing;
        }

        if ($this->alasanTidakLayakSinkron($periksaStatus) !== []) {
            return null;
        }

        if (! Anggota::whereKey($this->anggota_id)->exists()) {
            return null;
        }

        // ZERO-HARDCODING: kolom `pembiayaan.akun_id` (legacy, NOT NULL) diisi
        // akun piutang yang PERSIS sesuai akad — tanpa `Akun::first()` yang
        // dapat menunjuk akun sembarang. Akuntansi sesungguhnya memakai CoA.
        $akunId = self::akunLegacyIdUntukAkad($this->jenis_akad);

        if ($akunId === null) {
            Log::warning(
                'PengajuanPembiayaan: akun piutang legacy untuk akad tidak '
                .'ditemukan; kontrak tidak dibuat agar tidak menunjuk akun salah.',
                ['akad' => $this->jenis_akad, 'pengajuan_id' => $this->id]
            );

            return null;
        }

        $tenor = (int) $this->tenor;

        $snapshot = $this->snapshotPersentase();

        return DB::transaction(function () use ($akunId, $tenor, $statusPembiayaan, $snapshot) {
            $pokok = (float) ($this->jumlah_pengajuan ?? 0);
            $total = (float) ($this->total_pembiayaan ?? 0);
            if ($total <= 0) {
                $total = $pokok;
            }

            $angsuranPerBulan = (float) ($this->angsuran_per_bulan ?? 0);
            if ($angsuranPerBulan <= 0 && $tenor > 0) {
                $angsuranPerBulan = Setting::bulatkanUang($total / $tenor);
            }

            $pembiayaan = Pembiayaan::create([
                'no_pembiayaan' => self::generateNoPembiayaan(),
                'anggota_id' => $this->anggota_id,
                'akun_id' => $akunId,
                'rekening_id' => Rekening::where('anggota_id', $this->anggota_id)->value('id'),
                'akad_pembiayaan' => $this->jenis_akad ?? Pembiayaan::akadDefault(),
                'tujuan_pembiayaan' => $this->tujuan ?? 'Modal Usaha',
                'jumlah_pembiayaan' => $pokok,
                'margin_nisbah' => $this->margin_persen,
                // `total_ujroh` WAJIB diisi saat akad lahir. Tanpa ini kolomnya
                // tetap 0 dan seluruh tampilan (audit akad DPS, rincian margin
                // pengajuan) melaporkan "Ujroh Rp 0" — padahal margin tetap
                // ditagih lewat jadwal angsuran.
                'total_ujroh' => Setting::bulatkanUang(max(0.0, $total - $pokok)),
                'total_pembiayaan' => $total,
                'tenor' => $tenor,
                'frekuensi_angsuran' => 'Bulanan',
                'jumlah_angsuran' => $angsuranPerBulan,
                'sisa_pembiayaan' => $total,
                'agunan' => $this->agunan,
                'tanggal_akad' => $this->tanggalAkad(),
                'tanggal_jatuh_tempo' => Carbon::parse($this->tanggalAkad())
                    ->addMonths($tenor)->toDateString(),
                'status_pembiayaan' => $statusPembiayaan ?? Pembiayaan::STATUS_PENDING_AO,
                'disetujui_oleh' => $this->disetujui_oleh,
                'tanggal_disetujui' => $this->tanggal_disetujui,
                'catatan_pengurus' => $this->catatan_pengurus,
                'keterangan' => 'Sinkronisasi otomatis dari pengajuan '.$this->no_pengajuan,
            ] + $snapshot);

            $this->forceFill(['pembiayaan_id' => $pembiayaan->id])->save();

            return $pembiayaan;
        });
    }

    /**
     * Snapshot persentase margin yang berlaku saat akad dibuat.
     *
     * Diambil dari master COA piutang Murabahah (1-2001) agar perubahan rate
     * margin di menu Master COA di kemudian hari tidak mengubah akad lama.
     * Bila COA belum menerapkan persentase, nilai jatuh ke `margin_persen`
     * pengajuan supaya kontrak tetap menyimpan angka akad yang disepakati.
     *
     * @return array{applied_percentage_member: float, applied_percentage_institution: float}
     */
    private function snapshotPersentase(): array
    {
        $coa = Coa::findByKode(AutoJournalingService::PIUTANG_MURABAHAH);

        $rate = $coa && $coa->menerapkanPersentase()
            ? $coa->persentaseAnggota()
            : (float) ($this->margin_persen ?? 0);

        return [
            'applied_percentage_member' => Setting::bulatkanUang($rate),
            'applied_percentage_institution' => $coa && $coa->menerapkanPersentase()
                ? $coa->persentaseInstitusi()
                : 0.0,
        ];
    }

    /**
     * Tanggal akad untuk kontrak hasil sinkronisasi (format Y-m-d).
     *
     * Tahan terhadap nilai string maupun Carbon, karena kolom tanggal pada
     * tabel ini tidak semuanya di-cast Eloquent.
     */
    private function tanggalAkad(): string
    {
        $acuan = $this->tanggal_disetujui ?: ($this->created_at ?: now());

        return Carbon::parse($acuan)->toDateString();
    }

    /**
     * Nomor pengajuan berikutnya, dijamin unik (format `{prefix}{NNNN}`).
     *
     * Nilai urut diambil dari sufiks NUMERIK terbesar (bukan jumlah baris),
     * sehingga nomor yang sudah ada TIDAK PERNAH dipakai ulang — jumlah baris
     * bisa turun setelah penghapusan atau bertambah bersamaan (race), yang
     * membuat `count() + 1` menghasilkan duplikat. Bila kandidat ternyata masih
     * bertabrakan, pencarian dilanjutkan sampai nomor bebas.
     *
     * Catatan: tabel `pengajuan_pembiayaan` TIDAK memakai SoftDeletes (tidak
     * ada kolom `deleted_at`), sehingga query memakai `static::query()`
     * langsung — `withTrashed()` akan melempar BadMethodCallException.
     *
     * @param  string  $prefix  Mis. `PJN-20261005-` atau `PGJ-20261005-`.
     */
    public static function nomorPengajuanBaru(string $prefix, int $panjang = 4): string
    {
        $baris = static::query()
            ->where('no_pengajuan', 'like', $prefix.'%')
            ->selectRaw(
                'MAX(CAST(SUBSTRING(no_pengajuan, ?) AS UNSIGNED)) AS maks_urut',
                [strlen($prefix) + 1]
            )
            ->first();

        $urut = ((int) ($baris->maks_urut ?? 0)) + 1;

        do {
            $kandidat = $prefix.str_pad((string) $urut, $panjang, '0', STR_PAD_LEFT);
            $urut++;
        } while (static::query()->where('no_pengajuan', $kandidat)->exists());

        return $kandidat;
    }

    /**
     * Nomor kontrak pembiayaan berikutnya (format PMB-YYYYMM-NNNN).
     */
    public static function generateNoPembiayaan(): string
    {
        $prefix = 'PMB-'.date('Ym').'-';
        $panjang = 4;

        // Urutan NUMERIK terbesar (bukan perbandingan string), sehingga
        // sufiks dengan panjang berbeda — mis. `0001` dan `10001` — dihitung
        // benar dan tidak menghasilkan nomor duplikat saat volume tinggi.
        $baris = Pembiayaan::withTrashed()
            ->where('no_pembiayaan', 'like', $prefix.'%')
            ->selectRaw('MAX(CAST(SUBSTRING(no_pembiayaan, ?) AS UNSIGNED)) AS maks_urut', [strlen($prefix) + 1])
            ->first();

        $seq = ((int) ($baris->maks_urut ?? 0)) + 1;

        do {
            $kandidat = $prefix.str_pad((string) $seq, $panjang, '0', STR_PAD_LEFT);
            $seq++;
        } while (Pembiayaan::withTrashed()->where('no_pembiayaan', $kandidat)->exists());

        return $kandidat;
    }
}
