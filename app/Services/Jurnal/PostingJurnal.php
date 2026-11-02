<?php

namespace App\Services\Jurnal;

use App\Exceptions\JournalPostingException;
use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Services\JurnalReconciliationService;
use App\Services\PeriodeAkuntansiService;
use App\Support\NomorDokumen;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MESIN INTI POSTING JURNAL — satu-satunya gerbang validasi & penulisan
 * jurnal umum berpasangan (Debet/Kredit) ke buku besar.
 *
 * Kelas ini diekstrak dari `AutoJournalingService` agar:
 *  - resep jurnal per domain (simpanan, pembiayaan, deposito, ta'zir, dst.)
 *    dapat dipisah tanpa menduplikasi kontrol PSAK, dan
 *  - seluruh pengaman (CoA valid/aktif, keseimbangan, cut-off periode,
 *    rekonsiliasi inline) tetap terpusat di satu tempat.
 *
 * `AutoJournalingService` mempertahankan kontrak lamanya dan mendelegasikan
 * ke kelas ini, sehingga perilaku uang/jurnal TIDAK berubah.
 */
class PostingJurnal
{
    /** Sumber jurnal yang diizinkan memakai posisi DEBET pada akun 2-3001 (penyaluran dana sosial keluar). */
    public const SUMBER_PENYALURAN_DANA_KEBAJIKAN = 'Penyaluran Dana Kebajikan';

    /* =====================================================================
     | PSAK 108 / PSAK 109 — JENIS POSTING (PEMISAHAN ABSOLUT)
     |
     |   POST_TYPE_MARGIN   -> 4-1001 Pendapatan Margin (dan 4-1002/4-1003/4-1004)
     |   POST_TYPE_TAAZIR   -> 2-3001 Dana Kebajikan
     |   POST_TYPE_DONATION -> 2-3001 Dana Kebajikan
     |   POST_TYPE_QARDH    -> 1-2005 Pokok Piutang Qardh (tanpa margin apa pun)
     ===================================================================== */
    public const POST_TYPE_MARGIN = 'MARGIN';

    public const POST_TYPE_TAAZIR = 'TAAZIR';

    public const POST_TYPE_DONATION = 'DONATION';

    public const POST_TYPE_QARDH = 'QARDH';

    /**
     * Pemetaan jenis posting -> daftar akun CoA yang SAH menerima KREDIT.
     * Di luar daftar ini, jurnal ditolak (pencegahan misrouting permanen).
     *
     * @var array<string, array<int, string>>
     */
    public const AKUN_KREDIT_SAH_BY_POST_TYPE = [
        self::POST_TYPE_MARGIN => [
            \App\Support\Coa::PENDAPATAN_MARGIN,
            \App\Support\Coa::PENDAPATAN_BAGI_HASIL,
            \App\Support\Coa::PENDAPATAN_BAGI_HASIL_MUSYARAKAH,
            \App\Support\Coa::PENDAPATAN_IJARAH,
        ],
        self::POST_TYPE_TAAZIR => [\App\Support\Coa::DANA_KEBAJIKAN],
        self::POST_TYPE_DONATION => [\App\Support\Coa::DANA_KEBAJIKAN],
        self::POST_TYPE_QARDH => [\App\Support\Coa::PINJAMAN_QARDH],
    ];

    /**
     * Prefix kelas akun PENDAPATAN OPERASIONAL koperasi (4-XXXX).
     * Akun kelas ini pembentuk SHU dan DILARANG dialihkan ke Dana Kebajikan.
     */
    public const PREFIX_PENDAPATAN_OPERASIONAL = '4-';

    /**
     * Sumber jurnal yang SAH boleh memuat margin operasional (kredit kelas 4-)
     * sekaligus penampung ta'zir (kredit 2-3001) dalam SATU entri jurnal.
     *
     * @var array<int, string>
     */
    public const SUMBER_GABUNGAN_SAH = [
        'Angsuran',
    ];

    /**
     * Akun penampung DANA SOSIAL / KEBAJIKAN (non-operasional PSAK 109).
     * Margin operasional DILARANG mengalir ke akun-akun ini.
     *
     * @var array<int, string>
     */
    public const AKUN_DANA_SOSIAL = [
        \App\Support\Coa::DANA_KEBAJIKAN,
        \App\Support\Coa::DANA_ZAKAT,
        \App\Support\Coa::DANA_SOSIAL,
        \App\Support\Coa::DANA_INFAK_SEDEKAH,
    ];

    /**
     * Helper inti: buat Jurnal berpasangan ke CoA + validasi balance.
     *
     * Aman dipanggil di dalam DB::transaction() controller (nested transaction
     * di Laravel menggunakan savepoint otomatis) maupun standalone.
     *
     * Jika ada kode CoA yang tidak ditemukan, jurnal TETAP dibatalkan seluruhnya
     * dan peringatan dicatat ke log — bukan di-skip diam-diam — agar tidak ada
     * posting setengah-setengah yang merusak neraca.
     *
     * Dua mode pembalikan yang BERBEDA dan tidak boleh dicampur:
     *  - `$pembalikan` — contra-entry jurnal manual: baris sengaja dibalik,
     *    sehingga aturan POSISI (Dana Kebajikan/margin) dilewati.
     *  - `$reversal` — jurnal pembalik transaksi (void/AML): aturan POSISI
     *    tetap berlaku penuh, tetapi jurnal dikecualikan dari gate "transaksi
     *    harus Sukses" dan ditandai `is_reversal = true` secara permanen.
     */
    public function post(
        string $keterangan,
        array $entries,
        string $sumber = 'Jurnal Umum',
        ?int $transaksiId = null,
        ?int $pembiayaanId = null,
        ?int $userId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null,
        $tanggalJurnal = null,
        ?string $postType = null,
        ?string $noJurnal = null,
        ?string $noRef = null,
        ?string $jenisJurnal = null,
        bool $pembalikan = false,
        bool $reversal = false
    ): ?Jurnal {
        $userId = $userId ?? Auth::id();
        $postType = $postType ?? $this->deteksiPostType($sumber);

        return DB::transaction(function () use (
            $keterangan, $entries, $sumber,
            $transaksiId, $pembiayaanId, $userId, $rekeningId, $anggotaId, $tanggalJurnal,
            $postType, $noJurnal, $noRef, $jenisJurnal, $pembalikan, $reversal
        ) {
            $totalDebet = 0.0;
            $totalKredit = 0.0;
            $baris = [];

            // LOGGING ALUR (1/4): mulai posting — jejak awal setiap transaksi.
            Log::info('AutoJournaling: MULAI posting jurnal.', [
                'tahap' => 'MULAI',
                'sumber' => $sumber,
                'post_type' => $postType,
                'keterangan' => $keterangan,
                'transaksi_id' => $transaksiId,
                'reversal' => $reversal,
                'jumlah_baris' => count($entries),
                'user_id' => $userId,
            ]);

            /* ================================================================
             | GATE STATUS KEBERHASILAN TRANSAKSI (tuntutan: hanya mencatat
             | transaksi yang telah berhasil diproses sepenuhnya).
             |
             | Bila jurnal ditautkan ke sebuah transaksi (`transaksi_id`), maka
             | transaksi tersebut WAJIB berstatus SUKSES sebelum dicatat ke buku
             | besar. Tanpa gate ini, transaksi berstatus Gagal/Pending/Voided
             | tetap bisa membentuk jurnal — kas bertambah tanpa dasar transaksi
             | yang sah, dan laporan keuangan menjadi tidak dapat dipercaya.
             |
             | Jurnal manual tanpa `transaksi_id` (mis. penyesuaian/akrual) tetap
             | diizinkan karena memang tidak terikat pada satu transaksi kas.
             |
             | KONTEKS REVERSAL: jurnal pembalik (`$reversal = true`) sengaja
             | DIBEBASKAN dari gate status. Saat void, transaksi asal memang
             | berstatus `Pending Void` — justru karena itu jurnalnya dibalik.
             | Pengecualian ini eksplisit di pemanggil (bukan efek samping OR
             | pada `Transaksi::sukses()`), terbatas pada satu jalur, dan
             | ditandai permanen lewat kolom `jurnal.is_reversal` agar dapat
             | diaudit ulang kapan pun.
             ================================================================ */
            if ($transaksiId !== null) {
                $transaksi = Transaksi::find($transaksiId);

                if ($transaksi === null) {
                    $this->tolakPosting(
                        "Transaksi #{$transaksiId} tidak ditemukan — jurnal [{$sumber}] dibatalkan.",
                        'TRANSAKSI_TIDAK_DITEMUKAN',
                        $sumber,
                        $transaksiId,
                        ['keterangan' => $keterangan]
                    );
                }

                if (! $reversal && Setting::postingWajibTransaksiSukses() && ! $transaksi->sukses()) {
                    $this->tolakPosting(
                        "Transaksi [{$transaksi->no_transaksi}] belum berhasil diproses "
                            ."(status_transaksi='{$transaksi->status_transaksi}', status='{$transaksi->status}') "
                            ."— jurnal [{$sumber}] dibatalkan agar hanya transaksi sukses yang tercatat.",
                        'TRANSAKSI_BELUM_SUKSES',
                        $sumber,
                        $transaksiId,
                        [
                            'no_transaksi' => $transaksi->no_transaksi,
                            'status_transaksi' => $transaksi->status_transaksi,
                            'status_teknis' => $transaksi->status,
                        ]
                    );
                }
            }

            foreach ($entries as $entry) {
                $kode = $entry['kode'] ?? '';
                $coa = Coa::findByKode($kode);

                if (! $coa) {
                    // CoA belum ter-seed → batalkan seluruh jurnal & catat warning
                    $this->tolakPosting(
                        "Kode CoA [{$kode}] tidak ditemukan — jurnal [{$sumber}] dibatalkan.",
                        'COA_TIDAK_DITEMUKAN',
                        $sumber,
                        $transaksiId,
                        ['kode' => $kode, 'keterangan' => $keterangan]
                    );
                }

                /* Hanya akun AKTIF yang boleh dipakai posting jurnal baru.
                 | Akun lama yang sudah dinonaktifkan (mis. alias piutang 1-2010 /
                 | 1-2020 / 1-2030 / 1-2040) tetap tersimpan agar jurnal historis
                 | tidak kehilangan akunnya, tetapi tidak boleh menerima mutasi
                 | baru. Jurnal dibatalkan seluruhnya supaya tidak ada jurnal
                 | setengah jadi yang menyesatkan. */
                if (! $coa->is_active) {
                    $this->tolakPosting(
                        "Akun CoA [{$kode}] {$coa->nama_akun} NONAKTIF — jurnal [{$sumber}] dibatalkan.",
                        'COA_NONAKTIF',
                        $sumber,
                        $transaksiId,
                        ['kode' => $kode, 'nama_akun' => $coa->nama_akun, 'keterangan' => $keterangan]
                    );
                }

                // Bulatkan sesuai kebijakan pembulatan koperasi (default: satuan rupiah
                // penuh) agar total debet = total kredit tepat. Nilai angsuran/margin
                // hasil bagi periode menyimpan pecahan sen (mis. 833.333,33) yang
                // membuat jurnal ditolak oleh validasi balance.
                $debet = Setting::pembulatanNominal((float) ($entry['debet'] ?? 0));
                $kredit = Setting::pembulatanNominal((float) ($entry['kredit'] ?? 0));

                if ($debet <= 0 && $kredit <= 0) {
                    continue;
                }

                /* ATURAN KETAT AKUN 2-3001 (Dana Kebajikan)
                 | Posisi DEBET hanya boleh untuk PENYALURAN dana sosial keluar
                 | kepada penerima yang berhak. Seluruh pemotongan ta'zir/denda,
                 | infak, dan kontribusi kebajikan yang MASUK wajib KREDIT.
                 | Posisi yang melanggar ditolak dan dicatat ke log. */
                if (! $pembalikan && ! $this->posisiDanaKebajikanValid($kode, $debet, $kredit, $sumber)) {
                    $this->tolakPosting(
                        "Posisi jurnal Dana Kebajikan (2-3001) tidak sah — jurnal [{$sumber}] dibatalkan. "
                            ."DEBET 2-3001 hanya untuk penyaluran dana sosial; penerimaan ta'zir/infak wajib KREDIT.",
                        'POSISI_DANA_KEBAJIKAN',
                        $sumber,
                        $transaksiId,
                        ['keterangan' => $keterangan, 'kode' => $kode, 'debet' => $debet, 'kredit' => $kredit]
                    );
                }

                $baris[] = [
                    'coa_id' => $coa->id,
                    'akun_id' => null,
                    'baris' => count($baris) + 1,
                    'debet' => $debet,
                    'kredit' => $kredit,
                    'kode' => $kode,
                    /* Atribusi per-baris dapat dioper eksplisit oleh pemanggil
                     | (mis. jurnal pembalik yang harus mempertahankan
                     | anggota/rekening/keterangan baris asal). Bila tidak
                     | diisi, jatuh ke atribut global pemanggilan `post()`. */
                    'keterangan' => $entry['keterangan'] ?? $coa->nama_akun,
                    'rekening_id' => array_key_exists('rekening_id', $entry)
                        ? $entry['rekening_id']
                        : $this->rekeningIdUntukCoa($coa, $rekeningId),
                    'anggota_id' => $entry['anggota_id'] ?? $anggotaId,
                ];

                $totalDebet += $debet;
                $totalKredit += $kredit;
            }

            // Tidak ada baris valid
            if (empty($baris)) {
                $this->tolakPosting(
                    "Tidak ada baris CoA valid untuk [{$sumber}] — {$keterangan}.",
                    'TANPA_BARIS_VALID',
                    $sumber,
                    $transaksiId,
                    ['keterangan' => $keterangan, 'jumlah_entri' => count($entries)]
                );
            }

            /* PSAK 108/109 — PEMISAHAN ABSOLUT MARGIN vs DANA KEBAJIKAN.
             | Lapis 2: pemetaan jenis posting (MARGIN → 4-100x, TAAZIR/DONATION
             | → 2-3001) diambil dari sumber jurnal; QARDH wajib tanpa margin.
             |
             | JURNAL PEMBALIK (mode `$pembalikan`) dikecualikan dari ketiga
             | lapis aturan POSISI di bawah: contra-entry memang membalik posisi
             | Debet/Kredit baris asal, sehingga menerapkan aturan posisi normal
             | justru akan menolak pembalikan yang sah. Pengaman CoA valid/aktif,
             | keseimbangan, cut-off, dan rekonsiliasi tetap berlaku penuh. */
            if (! $pembalikan && $postType !== null && ! $this->mappingPostTypeValid($postType, $baris)) {
                $this->tolakPosting(
                    "Jenis posting {$postType} hanya boleh mengkredit akun yang terdaftar (MARGIN→4-100x, TAAZIR/DONATION→2-3001, QARDH→tanpa margin).",
                    'MAPPING_POST_TYPE',
                    $sumber,
                    $transaksiId,
                    ['keterangan' => $keterangan, 'post_type' => $postType]
                );
            }

            /* Lapis 1: margin pembiayaan DILARANG dialokasikan ke rekening
             | Dana Kebajikan / Dana Sosial. */
            if (! $pembalikan && ! $this->posisiMarginValid($baris, $sumber)) {
                $this->tolakPosting(
                    'Margin pembiayaan (akun Pendapatan 4-100x) DILARANG dialokasikan ke Dana Kebajikan/Dana Sosial (2-3001/2-3003).',
                    'PEMISAHAN_MARGIN_KEBAJIKAN',
                    $sumber,
                    $transaksiId,
                    ['keterangan' => $keterangan, 'baris' => $baris]
                );
            }

            /* Lapis 3: larangan mencampuradukkan pengalokasian margin (4-1001)
             | dengan Dana Kebajikan (2-3001) dalam satu entri jurnal yang sama.
             | Jurnal gabungan sah (sumber 'Angsuran') dikecualikan. */
            if (! $pembalikan && ! $this->posisiCampuranMarginValid($baris, $sumber)) {
                $this->tolakPosting(
                    'Jurnal tidak boleh mencampuradukkan CoA 4-1001 (Pendapatan Margin) dengan CoA 2-3001 (Dana Kebajikan) sebagai pemindahan dana.',
                    'PENCAMPURAN_MARGIN_KEBAJIKAN',
                    $sumber,
                    $transaksiId,
                    ['keterangan' => $keterangan, 'baris' => $baris]
                );
            }

            // Validasi balance debet = kredit (toleransi dari parameter kebijakan)
            if (abs($totalDebet - $totalKredit) >= Setting::toleransiSelisihJurnal()) {
                $this->tolakPosting(
                    "Jurnal tidak balance [{$sumber}] debet=".number_format($totalDebet, 2, ',', '.')
                        .' kredit='.number_format($totalKredit, 2, ',', '.')." — {$keterangan}.",
                    'JURNAL_TIDAK_BALANCE',
                    $sumber,
                    $transaksiId,
                    [
                        'keterangan' => $keterangan,
                        'total_debet' => $totalDebet,
                        'total_kredit' => $totalKredit,
                        'selisih' => abs($totalDebet - $totalKredit),
                    ]
                );
            }

            /* Tanggal jurnal dapat di-set eksplisit oleh pemanggil (mis. scheduler
             | Simpanan Wajib yang memproses tagihan backdate karena penyesuaian
             | akhir bulan). Bila null, gunakan tanggal hari ini. */
            $tanggalJurnal = $tanggalJurnal
                ? Carbon::parse($tanggalJurnal)->toDateString()
                : now()->toDateString();

            /* TAHAP 5 — INTEGRITAS PERIODE (cut-off tutup buku).
             | Seluruh jalur posting jurnal melewati method ini, sehingga satu
             | gerbang di sini menutup backdate untuk semua modul sekaligus.
             | Jurnal penutup (sumber 'TutupBuku') dikecualikan karena justru
             | dialah yang menutup periode. Bila cut-off belum diatur, gerbang
             | ini tidak berpengaruh sama sekali. */
            app(PeriodeAkuntansiService::class)
                ->pastikanDapatDiposting($tanggalJurnal, $sumber, static::class.'::post');

            $jurnal = Jurnal::create([
                'no_jurnal' => $noJurnal ?? $this->generateNoJurnal(),
                'no_ref' => $noRef,
                'tanggal_jurnal' => $tanggalJurnal,
                'jenis_jurnal' => $jenisJurnal ?? 'Jurnal Otomatis',
                'is_reversal' => $reversal,
                'transaksi_id' => $transaksiId,
                'pembiayaan_id' => $pembiayaanId,
                'sumber' => $sumber,
                'keterangan' => $keterangan,
                'total_debet' => $totalDebet,
                'total_kredit' => $totalKredit,
                'is_balance' => true,
                'status' => 'Posted',
                'posted_at' => now(),
                'user_posted' => $userId,
                'user_id' => $userId,
            ]);

            foreach ($baris as $row) {
                /* `kode` hanya dipakai untuk validasi PSAK (pemetaan posisi akun)
                 | dan BUKAN kolom tabel `jurnal_detail`. Kunci ini wajib dibuang
                 | sebelum insert: seeder berjalan di bawah Model::unguarded()
                 | (lihat SeedCommand), sehingga tanpa unset kunci non-kolom ikut
                 | terkirim ke SQL dan menggagalkan penyimpanan. */
                unset($row['kode']);
                $row['jurnal_id'] = $jurnal->id;
                JurnalDetail::create($row);
            }

            /* Bukti akhir sebelum jurnal dinyatakan sah: header Posted wajib
             | didukung baris detail yang benar-benar tersimpan dan seimbang.
             | Pemeriksaan sebelumnya hanya memakai akumulasi $totalDebet /
             | $totalKredit di memori, sehingga selisih antara yang dihitung
             | dan yang benar-benar tertulis bisa lolos. pastikanBalance()
             | melempar RuntimeException → DB::transaction ikut rollback. */
            $jurnal->pastikanBalance();

            /* REKONSILIASI OTOMATIS INLINE (tuntutan: verifikasi kesesuaian
             | saldo SETIAP KALI transaksi diproses).
             |
             | pastikanBalance() hanya memverifikasi header vs baris detail yang
             | baru saja ditulis. Rekonsiliasi ini memeriksa ULANG dari basis
             | data: baris detail benar-benar tersimpan, header sinkron, CoA
             | valid & aktif, serta transaksi terkait berstatus sukses. Bila ada
             | temuan, JournalPostingException dilempar → DB::transaction
             | pemanggil rollback, sehingga tidak ada jurnal cacat di buku besar. */
            if (Setting::rekonsiliasiInlineAktif()) {
                app(JurnalReconciliationService::class)->pastikanJurnalSehat($jurnal);
            }

            // LOGGING ALUR (4/4): sukses — jurnal sah tersimpan & tervalidasi.
            Log::info('AutoJournaling: SUKSES posting jurnal.', [
                'tahap' => 'SUKSES',
                'no_jurnal' => $jurnal->no_jurnal,
                'jurnal_id' => $jurnal->id,
                'sumber' => $sumber,
                'transaksi_id' => $transaksiId,
                'reversal' => $reversal,
                'total_debet' => $jurnal->total_debet,
                'total_kredit' => $jurnal->total_kredit,
                'keterangan' => $keterangan,
                'rekonsiliasi' => Setting::rekonsiliasiInlineAktif() ? 'inline-ok' : 'nonaktif',
            ]);

            return $jurnal;
        });
    }

    /**
     * Tolak posting jurnal — catat alur ke log lalu LEMPAR `JournalPostingException`.
     *
     * Sebelumnya kegagalan validasi hanya `return null` (silent cancel) sehingga
     * `DB::transaction` pemanggil TIDAK ikut rollback dan data transaksi bisa
     * tersimpan tanpa jurnal. Method ini menutup celah tersebut: setiap
     * penolakan tercatat ke log audit DAN melempar exception agar seluruh
     * transaksi dibatalkan secara atomik — prinsip "hanya mencatat transaksi
     * yang telah berhasil diproses sepenuhnya".
     *
     * @throws JournalPostingException
     */
    private function tolakPosting(
        string $pesan,
        string $aturan,
        string $sumber,
        ?int $transaksiId,
        array $konteks = []
    ): never {
        // LOGGING ALUR (GAGAL): jejak audit lengkap sebelum transaksi dibatalkan.
        Log::error('AutoJournaling: GAGAL posting jurnal — '.$pesan, array_merge([
            'tahap' => 'GAGAL',
            'aturan' => $aturan,
            'sumber' => $sumber,
            'transaksi_id' => $transaksiId,
        ], $konteks));

        throw new JournalPostingException($pesan, $aturan, $sumber, $transaksiId, $konteks);
    }

    /** Generator nomor jurnal harian: JRN-YYYYMMDD-0001 (aman terhadap record terhapus) */
    private function generateNoJurnal(): string
    {
        // Awalan dapat diubah Pengurus (Setting `prefix_nomor_jurnal`);
        // kosong = tetap `JRN`.
        $prefix = NomorDokumen::prefix('jurnal');
        $panjang = 4;

        // Ambil urutan NUMERIK terbesar (bukan perbandingan string), sehingga
        // sufiks dengan panjang berbeda — mis. `0001` dan `10001` — dihitung
        // benar. Sebelumnya `substr($maxNo, -4)` memotong `10001` menjadi
        // `0001` lalu menghasilkan nomor yang sudah terpakai (risiko tabrakan
        // saat volume tinggi).
        $baris = Jurnal::withTrashed()
            ->where('no_jurnal', 'like', $prefix.'%')
            ->selectRaw('MAX(CAST(SUBSTRING(no_jurnal, ?) AS UNSIGNED)) AS maks_urut', [strlen($prefix) + 1])
            ->first();

        $urut = ((int) ($baris->maks_urut ?? 0)) + 1;

        // Bila kandidat ternyata sudah ada (nomor diisi manual/record lama),
        // lanjutkan pencarian sampai nomor bebas.
        do {
            $kandidat = $prefix.str_pad((string) $urut, $panjang, '0', STR_PAD_LEFT);
            $urut++;
        } while (Jurnal::withTrashed()->where('no_jurnal', $kandidat)->exists());

        return $kandidat;
    }

    /**
     * Tentukan `rekening_id` baris jurnal berdasarkan jenis rekening anggota.
     *
     * Baris jurnal hanya boleh diatribusikan ke rekening bila kode CoA-nya
     * memang akun simpanan yang sesuai dengan jenis rekening tersebut. Tanpa
     * pemeriksaan ini, jurnal gabungan yang memuat beberapa baris — mis. Kas
     * dan baris simpanan jenis lain (mis. Pokok/Wajib pada jurnal bundling)
     * ikut tertandai rekening yang salah. Subledger per rekening menjadi
     * menyesatkan. Baris tanpa rekening yang jelas dibiarkan NULL
     * (tidak diatribusikan) daripada diatribusikan keliru.
     */
    private function rekeningIdUntukCoa(Coa $coa, ?int $rekeningId): ?int
    {
        if ($rekeningId === null) {
            return null;
        }

        static $peta = [
            'Simpanan Sukarela' => [\App\Support\Coa::SIMPANAN_SUKARELA],
            'Simpanan Wajib' => [\App\Support\Coa::SIMPANAN_WAJIB, \App\Support\Coa::SIMPANAN_WAJIB_MODAL],
            'Simpanan Berjangka' => [\App\Support\Coa::SIMPANAN_BERJANGKA],
            'Simpanan Pokok' => [\App\Support\Coa::SIMPANAN_POKOK],
        ];

        static $jenisCache = [];
        $jenis = $jenisCache[$rekeningId]
            ??= Rekening::whereKey($rekeningId)->value('jenis_rekening');

        $kodeSah = $peta[$jenis] ?? [];

        return in_array($coa->kode_akun, $kodeSah, true) ? $rekeningId : null;
    }

    /* =====================================================================
     | PSAK 108 / 109 — PEMISAHAN ABSOLUT MARGIN vs DANA KEBAJIKAN
     |
     | Tiga lapis pengaman:
     |  1. posisiMarginValid()          → margin tidak boleh dialokasikan ke
     |                                    Dana Kebajikan / Dana Sosial.
     |  2. mappingPostTypeValid()       → POST_TYPE_MARGIN wajib ke 4-100x;
     |                                    POST_TYPE_TAAZIR/DONATION wajib ke 2-3001.
     |  3. posisiCampuranMarginValid()  → jurnal TIDAK boleh mencampuradukkan
     |                                    pengalokasian margin (4-100x) dengan
     |                                    Dana Kebajikan (2-3001) dalam bentuk
     |                                    Debet-Pendapatan / Kredit-Kebajikan.
     | Semua pelanggaran ditolak & dicatat ke log audit (Log::error).
     ===================================================================== */

    /**
     * Apakah sebuah kode akun termasuk akun Pendapatan Operasional (kelas 4-).
     */
    public function akunPendapatanOperasional(string $kode): bool
    {
        return str_starts_with($kode, self::PREFIX_PENDAPATAN_OPERASIONAL);
    }

    /**
     * Apakah sebuah kode akun termasuk penampung Dana Sosial / Kebajikan.
     */
    public function akunDanaSosial(string $kode): bool
    {
        return in_array($kode, self::AKUN_DANA_SOSIAL, true);
    }

    /**
     * LAPIS 1 — Pembatasan permanen: margin pembiayaan (Murabahah/Mudharabah/
     * Musyarakah/Ijarah) DILARANG dialokasikan ke rekening Dana Kebajikan /
     * Dana Sosial.
     *
     * Pola pelanggaran yang ditolak: baris DEBET pada akun Pendapatan (4-100x)
     * dipasangkan dengan baris KREDIT pada akun Dana Kebajikan/Dana Sosial —
     * yaitu "memindahkan" margin keluar dari Pendapatan Operasional.
     *
     * @param  array  $barisBaris  Baris jurnal hasil normalisasi
     *                             (masing-masing: kode, debet, kredit)
     * @param  string  $sumber  Sumber jurnal (penanda jenis transaksi)
     * @return bool true bila tidak ada pengalihan margin ke Dana Kebajikan
     */
    public function posisiMarginValid(array $barisBaris, string $sumber): bool
    {
        if (! $this->berlakuAturanPemisahanMargin($sumber)) {
            return true;
        }

        foreach ($barisBaris as $baris) {
            if (($baris['debet'] ?? 0) <= 0 || ! $this->akunPendapatanOperasional((string) $baris['kode'])) {
                continue;
            }

            foreach ($barisBaris as $lawan) {
                if (($lawan['kredit'] ?? 0) > 0 && $this->akunDanaSosial((string) $lawan['kode'])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * LAPIS 2 — Validasi pemetaan JENIS POSTING ke akun CoA (tidak dapat diubah).
     *
     *  - POST_TYPE_MARGIN   → KREDIT HANYA boleh ke akun Pendapatan Margin/
     *                         Bagi Hasil (4-100x). Mapping ke akun lain DITOLAK.
     *  - POST_TYPE_TAAZIR   → KREDIT HANYA boleh ke 2-3001 Dana Kebajikan.
     *  - POST_TYPE_DONATION → KREDIT HANYA boleh ke 2-3001 Dana Kebajikan.
     *  - POST_TYPE_QARDH    → tanpa margin: TIDAK boleh ada KREDIT ke kelas 4-.
     *
     * @param  string  $postType  Jenis posting (salah satu POST_TYPE_*)
     * @return bool true bila seluruh baris KREDIT sesuai pemetaan
     */
    public function mappingPostTypeValid(string $postType, array $barisBaris): bool
    {
        if (! isset(self::AKUN_KREDIT_SAH_BY_POST_TYPE[$postType])) {
            return true; // jenis posting tidak dikenal → tidak diatur di sini
        }

        $sah = self::AKUN_KREDIT_SAH_BY_POST_TYPE[$postType];

        foreach ($barisBaris as $baris) {
            if (($baris['kredit'] ?? 0) <= 0) {
                continue;
            }

            $kode = (string) $baris['kode'];

            // Qardh: produk kebajikan tanpa margin apa pun.
            if ($postType === self::POST_TYPE_QARDH) {
                if ($this->akunPendapatanOperasional($kode)) {
                    return false;
                }

                continue;
            }

            if (! in_array($kode, $sah, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * LAPIS 3 — Larangan permanen mencampuradukkan pengalokasian margin
     * (CoA 4-1001 dan keluarga 4-100x) dengan CoA 2-3001 (Dana Kebajikan)
     * sebagai PEMINDAHAN dana, dalam satu entri jurnal yang sama.
     *
     * DILARANG  : Debet 4-1001 Pendapatan Margin  | Kredit 2-3001 Dana Kebajikan
     * DIIZINKAN : jurnal gabungan sah pada sumber 'Angsuran', mis.
     *             Debet 1-1001 Kas | Kredit 1-2001 Piutang | Kredit 4-1001 Margin
     *             | Kredit 2-3001 Ta'zir — sesuai keputusan desain & PSAK 109.
     *
     * @return bool true bila jurnal tidak melakukan pemindahan margin ke kebajikan
     */
    public function posisiCampuranMarginValid(array $barisBaris, string $sumber): bool
    {
        if (in_array($sumber, self::SUMBER_GABUNGAN_SAH, true)) {
            return true;
        }

        // Tidak ada pengalokasian margin (debet kelas 4-) → tidak ada yang dicampur.
        $adaDebetPendapatan = false;
        foreach ($barisBaris as $baris) {
            if (($baris['debet'] ?? 0) > 0 && $this->akunPendapatanOperasional((string) $baris['kode'])) {
                $adaDebetPendapatan = true;
                break;
            }
        }

        if (! $adaDebetPendapatan) {
            return true;
        }

        // Dana Kebajikan (2-3001) hanya boleh menerima KREDIT pada sumber selain penyaluran.
        foreach ($barisBaris as $baris) {
            $kode = (string) $baris['kode'];

            if (! $this->akunDanaSosial($kode)) {
                continue;
            }

            if (($baris['debet'] ?? 0) > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Apakah jurnal dari sumber ini tunduk pada aturan pemisahan margin.
     * Jurnal gabungan yang sah (sumber 'Angsuran') dikecualikan.
     */
    private function berlakuAturanPemisahanMargin(string $sumber): bool
    {
        if (in_array($sumber, self::SUMBER_GABUNGAN_SAH, true)) {
            return false;
        }

        if ($sumber === self::SUMBER_PENYALURAN_DANA_KEBAJIKAN) {
            return false;
        }

        return true;
    }

    /**
     * Deteksi otomatis JENIS POSTING (POST_TYPE_*) dari sumber jurnal, HANYA
     * untuk jenis yang tidak ambigu (dana sosial). Margin operasional DIBERIKAN
     * eksplisit oleh pemanggil (mis. via parameter $postType), karena string
     * sumber bebas dan rawan salah klasifikasi (mis. "Bagi Hasil Simpanan
     * Berjangka" adalah BEBAN, bukan margin pendapatan).
     *
     * Bila tidak terklasifikasi → null: jurnal tetap tunduk pada aturan
     * pemisahan margin lapis 1 & 3 yang bersifat universal.
     */
    public function deteksiPostType(string $sumber): ?string
    {
        $s = strtolower($sumber);

        // Ta'zir / denda / penalti → wajib 2-3001 (non-operasional PSAK 109).
        if (str_contains($s, "ta'zir") || str_contains($s, 'tazir')
            || str_contains($s, 'denda') || str_contains($s, 'penalti')
        ) {
            return self::POST_TYPE_TAAZIR;
        }

        return null;
    }

    /**
     * QARDHUL HASAN TIDAK BOLEH MEMILIKI MARGIN (PSAK 108 / Fatwa DSN-MUI No. 19/DSN-MUI/IV/2001).
     *
     * Qardh adalah pinjaman kebajikan (tabarru') — koperasi hanya boleh
     * menerima kembali POKOK pinjaman; menetapkan margin/bagi hasil apa pun
     * menjadikannya riba dan membatalkan sifat kebajikannya. Parameter
     * `$margin` / `$ujroh` untuk akad Qardh SELALU ditolak (dan dicatat ke log).
     *
     * @param  string  $akad  Akad pembiayaan (Murabahah/Mudharabah/.../Qardh)
     * @param  float  $margin  Porsi margin/bagi hasil yang DIMINTA dicatat
     * @param  float  $ujroh  Porsi ujrah yang DIMINTA dicatat
     * @return bool true bila input melanggar (harus ditolak)
     */
    public function marginQardhDilarang(string $akad, float $margin, float $ujroh = 0.0): bool
    {
        if (! str_contains(strtolower($akad), 'qardh') && ! str_contains(strtolower($akad), 'qard')) {
            return false;
        }

        if ($margin <= 0 && $ujroh <= 0) {
            return false;
        }

        $this->catatPelanggaranPemisahan(
            'QARDH_TANPA_MARGIN',
            "JurnalAkad:{$akad}",
            [
                'margin_diminta' => $margin,
                'ujroh_diminta' => $ujroh,
                'akun_dilarang' => '4-1001/4-1002/4-1003/4-1004 (Pendapatan Operasional)',
                'akun_sah' => \App\Support\Coa::PINJAMAN_QARDH.' (Pokok Piutang Qardh)',
                'keterangan' => 'Akad Qardhul Hasan tidak boleh memiliki margin/ujroh — hanya pokok piutang yang dicatat.',
            ]
        );

        return true;
    }

    /**
     * Audit log sistem atas setiap UPAYA PELANGGARAN aturan pemisahan
     * margin vs Dana Kebajikan (PSAK 108/109). Dipakai untuk keperluan audit
     * internal maupun eksternal — setiap penolakan meninggalkan jejak.
     */
    public function catatPelanggaranPemisahan(string $aturan, string $sumber, array $konteks = []): void
    {
        Log::error('AutoJournalingService: PELANGGARAN ATURAN PEMISAHAN MARGIN vs DANA KEBAJIKAN (PSAK 108/109) — jurnal DIBATALKAN.', array_merge([
            'aturan' => $aturan,
            'sumber' => $sumber,
        ], $konteks));
    }

    /**
     * Validasi posisi akun Dana Kebajikan (2-3001).
     *
     * Penerimaan (KREDIT) selalu sah. DEBET hanya sah untuk penyaluran dana
     * sosial keluar atau pembalikannya.
     *
     * @param  string  $kode  Kode CoA baris jurnal
     * @param  float  $debet  Nominal debet baris
     * @param  float  $kredit  Nominal kredit baris
     * @param  string  $sumber  Sumber jurnal (penanda jenis transaksi)
     * @return bool true bila posisi sah
     */
    public function posisiDanaKebajikanValid(string $kode, float $debet, float $kredit, string $sumber): bool
    {
        if ($kode !== \App\Support\Coa::DANA_KEBAJIKAN) {
            return true;
        }

        $sumberDebetSah = [
            self::SUMBER_PENYALURAN_DANA_KEBAJIKAN,
            'Penyaluran Dana Kebajikan',
            'Penyaluran Kebajikan',
            // Pembalikan (void) dana yang sebelumnya masuk — mengurangi akumulasi terkumpul.
            'Reversal Dana Kebajikan',
            // K-02: pembalikan penalti pencairan dini deposito. Penalti awalnya
            // dikredit ke Dana Kebajikan (ta'zir); saat transaksi di-void, posisi
            // harus didebet untuk mengembalikan akumulasi dana ke kondisi semula.
            'Reversal Pencairan Dini Deposito Mudharabah',
        ];

        // Penerimaan (KREDIT) selalu sah — termasuk ta'zir auto-debet & tunai teller.
        if ($kredit > 0 && $debet <= 0) {
            return true;
        }

        // DEBET 2-3001 hanya sah untuk penyaluran dana sosial keluar atau pembalikannya.
        return in_array($sumber, $sumberDebetSah, true);
    }
}
