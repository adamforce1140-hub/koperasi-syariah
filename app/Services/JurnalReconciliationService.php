<?php

namespace App\Services;

use App\Exceptions\JournalPostingException;
use App\Models\Coa;
use App\Models\JenisSimpanan;
use App\Models\Jurnal;
use App\Models\Transaksi;
use App\Support\Coa as KodeCoa;
use App\Support\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * JurnalReconciliationService — rekonsiliasi otomatis buku besar.
 *
 * Dua mode pemakaian:
 *
 *  1. INLINE (setiap kali transaksi diproses)
 *     `pastikanJurnalSehat()` dipanggil di akhir `AutoJournalingService::post()`
 *     tepat setelah jurnal ditulis. Bila ditemukan ketidaksesuaian (tidak
 *     balance, header tidak sinkron, CoA hilang/nonaktif, atau transaksi terkait
 *     belum sukses), method melempar `JournalPostingException` sehingga
 *     `DB::transaction` pemanggil ikut rollback. Tidak ada jurnal setengah jadi
 *     yang lolos ke buku besar.
 *
 *  2. MENYELURUH (terjadwal / manual)
 *     `rekonsiliasiBukuBesar()` memeriksa seluruh buku besar: total debet vs
 *     total kredit (keseimbangan aktiva–pasiva), jurnal per-periode yang tidak
 *     seimbang, jurnal yang tertaut ke transaksi gagal/void, kelengkapan
 *     PASANGAN REVERSAL (jurnal pembalik tanpa jurnal asal, serta transaksi
 *     batal yang efek bukunya belum nol). Dipakai oleh command
 *     `jurnal:rekonsiliasi`.
 *
 *  3. SUBLEDGER vs BUKU BESAR (gerbang P1-1)
 *     `selisihSubledger()` membandingkan saldo rekening anggota (subledger)
 *     dengan saldo akun kontrolnya di buku besar untuk akun simpanan
 *     (`2-1001` Sukarela, `2-1003` Berjangka). `pastikanSubledgerSehat()`
 *     dipanggil di batas transaksi teller/deposito.
 *
 *     Bawaannya gate berjalan dalam MODE LAPOR: selisih dicatat ke log dan
 *     dilaporkan sebagai temuan, tetapi TIDAK melempar sehingga operasional
 *     teller tidak terblokir. Mode tegakkan diaktifkan lewat
 *     `RECONCILIATION_GATE_ENFORCE=true` (lihat `gateSubledgerDitegakkan()`).
 *
 * Seluruh pemeriksaan BERSIFAT MEMBACA (read-only) kecuali `pastikanJurnalSehat()`
 * dan `pastikanSubledgerSehat()` (mode tegakkan) yang sengaja melempar untuk
 * memicu rollback di pemanggilnya.
 */
class JurnalReconciliationService
{
    /** Akun kontrol yang memiliki subledger rekening anggota. */
    public const KODE_SUBLEDGER_DEFAULT = [KodeCoa::SIMPANAN_SUKARELA, KodeCoa::SIMPANAN_BERJANGKA];

    /**
     * Nama berkas penanda dedup alarm selisih (di `storage/framework/cache`).
     *
     * Sengaja berkas polos, bukan facade `Cache`, agar tidak bergantung pada
     * store cache yang sedang diperiksa/diasumsikan bermasalah.
     */
    private const PENANDA_LOG_SUBLEDGER = 'rekonsiliasi-subledger-log';

    /**
     * Pemetaan akun kontrol -> tipe produk simpanan pada tabel `rekening`.
     *
     * Publik agar command audit (`simpanan:audit-subledger`) memakai pemetaan
     * yang sama. Akun modal (3-1001/3-1002) disertakan untuk keperluan audit
     * X-7; `KODE_SUBLEDGER_DEFAULT` tetap hanya 2-1001 & 2-1003 sehingga
     * perilaku gate tidak berubah.
     */
    public const TIPE_BY_KODE = [
        KodeCoa::SIMPANAN_SUKARELA => JenisSimpanan::TIPE_SUKARELA,
        KodeCoa::SIMPANAN_BERJANGKA => JenisSimpanan::TIPE_BERJANGKA,
        KodeCoa::SIMPANAN_POKOK => JenisSimpanan::TIPE_POKOK,
        KodeCoa::SIMPANAN_WAJIB_MODAL => JenisSimpanan::TIPE_WAJIB,
    ];

    /** Kategori akun yang termasuk sisi AKTIVA (aset). */
    private const KATEGORI_AKTIVA = ['Aset'];

    /** Kategori akun yang termasuk sisi PASIVA (kewajiban + ekuitas). */
    private const KATEGORI_PASIVA = ['Kewajiban', 'Ekuitas'];

    /**
     * Periksa integritas satu jurnal beserta baris detail & transaksi terkaitnya.
     *
     * @return array<int, array{aturan: string, pesan: string, konteks: array<string, mixed>}>
     *                                                                                         Daftar temuan; array kosong berarti jurnal sehat.
     */
    public function periksaJurnal(Jurnal $jurnal): array
    {
        $toleransi = Setting::toleransiSelisihJurnal();
        $temuan = [];

        // Baca ulang dari basis data — bukan dari objek di memori — supaya yang
        // diperiksa benar-benar baris yang tersimpan, bukan nilai yang dihitung.
        $detail = DB::table('jurnal_detail')
            ->where('jurnal_id', $jurnal->id)
            ->get(['id', 'coa_id', 'baris', 'debet', 'kredit']);

        if ($detail->isEmpty()) {
            $temuan[] = $this->temuan(
                'JURNAL_TANPA_DETAIL',
                "Jurnal [{$jurnal->no_jurnal}] tidak memiliki baris detail (double-entry tidak terpenuhi).",
                ['jurnal_id' => $jurnal->id, 'no_jurnal' => $jurnal->no_jurnal]
            );

            return $temuan;
        }

        $jumlahDebet = (float) $detail->sum('debet');
        $jumlahKredit = (float) $detail->sum('kredit');

        if (abs($jumlahDebet - $jumlahKredit) >= $toleransi) {
            $temuan[] = $this->temuan(
                'JURNAL_TIMBANG',
                "Jurnal [{$jurnal->no_jurnal}] tidak seimbang: debet Rp "
                    .number_format($jumlahDebet, 2, ',', '.').' vs kredit Rp '
                    .number_format($jumlahKredit, 2, ',', '.').'.',
                [
                    'jurnal_id' => $jurnal->id,
                    'no_jurnal' => $jurnal->no_jurnal,
                    'jumlah_debet' => $jumlahDebet,
                    'jumlah_kredit' => $jumlahKredit,
                ]
            );
        }

        if (abs((float) $jurnal->total_debet - $jumlahDebet) >= $toleransi
            || abs((float) $jurnal->total_kredit - $jumlahKredit) >= $toleransi
            || ! (bool) $jurnal->is_balance
        ) {
            $temuan[] = $this->temuan(
                'HEADER_TIDAK_SINKRON',
                "Header jurnal [{$jurnal->no_jurnal}] tidak sinkron dengan jumlah baris detailnya.",
                [
                    'jurnal_id' => $jurnal->id,
                    'no_jurnal' => $jurnal->no_jurnal,
                    'header_debet' => (float) $jurnal->total_debet,
                    'header_kredit' => (float) $jurnal->total_kredit,
                    'detail_debet' => $jumlahDebet,
                    'detail_kredit' => $jumlahKredit,
                    'is_balance_header' => (bool) $jurnal->is_balance,
                ]
            );
        }

        $temuan = array_merge($temuan, $this->periksaBarisDetail($jurnal, $detail));

        // Gerbang status keberhasilan: jurnal untuk transaksi gagal/void tidak
        // boleh ada di buku besar.
        //
        // PENGECUALIAN REVERSAL: jurnal pembalik (`is_reversal = true`) memang
        // SELALU tertaut transaksi yang sedang dibatalkan (`Pending Void` /
        // `Voided`). Tanpa pengecualian ini, `pastikanJurnalSehat()` akan
        // menolak setiap reversal sehingga alur void & penolakan AML mustahil.
        // Pengecualian dibatasi ketat: hanya berlaku bila jurnal benar-benar
        // ditandai reversal oleh `PostingJurnal::post(reversal: true)`.
        if ($jurnal->transaksi_id !== null) {
            $transaksi = Transaksi::withTrashed()->find($jurnal->transaksi_id);

            if (! $transaksi) {
                $temuan[] = $this->temuan(
                    'TRANSAKSI_HILANG',
                    "Jurnal [{$jurnal->no_jurnal}] merujuk transaksi #{$jurnal->transaksi_id} yang tidak ditemukan.",
                    ['jurnal_id' => $jurnal->id, 'transaksi_id' => $jurnal->transaksi_id]
                );
            } elseif (! $jurnal->is_reversal && ! $transaksi->sukses()) {
                $temuan[] = $this->temuan(
                    'TRANSAKSI_BELUM_SUKSES',
                    "Jurnal [{$jurnal->no_jurnal}] tertaut transaksi #{$transaksi->id} "
                        ."berstatus '{$transaksi->status_transaksi}' (belum sukses).",
                    [
                        'jurnal_id' => $jurnal->id,
                        'transaksi_id' => $transaksi->id,
                        'status_transaksi' => $transaksi->status_transaksi,
                        'status_teknis' => $transaksi->status,
                    ]
                );
            }
        }

        return $temuan;
    }

    /**
     * Pastikan satu jurnal sehat; lempar bila ada temuan (memicu rollback).
     *
     * @throws JournalPostingException
     */
    public function pastikanJurnalSehat(Jurnal $jurnal): void
    {
        $temuan = $this->periksaJurnal($jurnal);

        if ($temuan === []) {
            return;
        }

        $pertama = $temuan[0];

        throw new JournalPostingException(
            'Rekonsiliasi otomatis menolak jurnal: '.$pertama['pesan'],
            $pertama['aturan'],
            (string) ($jurnal->sumber ?? ''),
            $jurnal->transaksi_id !== null ? (int) $jurnal->transaksi_id : null,
            ['temuan' => $temuan, 'no_jurnal' => $jurnal->no_jurnal]
        );
    }

    /**
     * Bandingkan saldo subledger rekening anggota dengan saldo akun kontrol di
     * buku besar.
     *
     * Saldo GL dihitung dari posisi normal kewajiban: kredit − debet, hanya
     * untuk jurnal Posted yang belum dihapus. Saldo subledger diambil dari
     * `rekening.saldo` milik rekening berstatus `Aktif` — rekening yang sudah
     * ditutup/dibekukan sudah dipindahkan ke akun lain atau nol, sehingga
     * memasukkannya justru menciptakan selisih palsu.
     *
     * @param  array<int, string>  $kodeAkun
     * @return array<int, array{kode: string, gl: float, subledger: float, selisih: float, status: string}>
     *                                                                                                      status: `cocok` | `selisih` | `coa_hilang` | `tanpa_subledger`
     */
    public function selisihSubledger(array $kodeAkun = self::KODE_SUBLEDGER_DEFAULT): array
    {
        $toleransi = Setting::toleransiSelisihJurnal();
        $kodeAkun = array_values(array_unique(array_map('strval', $kodeAkun)));

        if ($kodeAkun === []) {
            return [];
        }

        $akunAda = Coa::query()
            ->whereIn('kode_akun', $kodeAkun)
            ->pluck('kode_akun')
            ->map(fn ($kode) => (string) $kode)
            ->all();

        $gl = [];
        foreach ($this->saldoGlPerAkun($kodeAkun) as $baris) {
            $gl[(string) $baris->kode_akun] = (float) $baris->saldo;
        }

        $hasil = [];

        foreach ($kodeAkun as $kode) {
            $nilaiGl = $gl[$kode] ?? 0.0;
            $subledger = $this->saldoSubledger($kode);

            if (! in_array($kode, $akunAda, true)) {
                $hasil[] = [
                    'kode' => $kode,
                    'gl' => 0.0,
                    'subledger' => $subledger ?? 0.0,
                    'selisih' => 0.0,
                    'status' => 'coa_hilang',
                ];

                continue;
            }

            if ($subledger === null) {
                $hasil[] = [
                    'kode' => $kode,
                    'gl' => $nilaiGl,
                    'subledger' => 0.0,
                    'selisih' => 0.0,
                    'status' => 'tanpa_subledger',
                ];

                continue;
            }

            $selisih = $nilaiGl - $subledger;

            $hasil[] = [
                'kode' => $kode,
                'gl' => $nilaiGl,
                'subledger' => $subledger,
                'selisih' => $selisih,
                'status' => abs($selisih) >= $toleransi ? 'selisih' : 'cocok',
            ];
        }

        return $hasil;
    }

    /**
     * Rekonsiliasi subledger dalam bentuk laporan + daftar temuan.
     *
     * @param  array<int, string>  $kodeAkun
     * @return array{
     *     seimbang: bool,
     *     baris: array<int, array{kode: string, gl: float, subledger: float, selisih: float, status: string}>,
     *     temuan: array<int, array{aturan: string, pesan: string, konteks: array<string, mixed>}>
     * }
     */
    public function rekonsiliasiSubledger(array $kodeAkun = self::KODE_SUBLEDGER_DEFAULT): array
    {
        $baris = $this->selisihSubledger($kodeAkun);
        $temuan = [];

        foreach ($baris as $item) {
            if ($item['status'] === 'coa_hilang') {
                $temuan[] = $this->temuan(
                    'SUBLEDGER_COA_HILANG',
                    "Akun kontrol [{$item['kode']}] tidak ditemukan di bagan akun; "
                        .'saldo subledger Rp '.number_format($item['subledger'], 2, ',', '.')
                        .' tidak dapat dibandingkan.',
                    $item
                );

                continue;
            }

            if ($item['status'] === 'selisih') {
                $temuan[] = $this->temuan(
                    'SUBLEDGER_SELISIH',
                    "Saldo subledger akun [{$item['kode']}] tidak sama dengan buku besar: GL Rp "
                        .number_format($item['gl'], 2, ',', '.').' vs subledger Rp '
                        .number_format($item['subledger'], 2, ',', '.').' (selisih Rp '
                        .number_format($item['selisih'], 2, ',', '.').').',
                    $item
                );
            }
        }

        return [
            'seimbang' => $temuan === [],
            'baris' => $baris,
            'temuan' => $temuan,
        ];
    }

    /**
     * Gate subledger di batas transaksi teller/deposito.
     *
     * MODE LAPOR (bawaan): selisih hanya dicatat ke log `critical` (dibatasi
     * sekali per jam) dan tidak melempar, sehingga operasional tidak terblokir
     * selama selisih warisan belum diremediasi.
     *
     * MODE TEGAKKAN (`RECONCILIATION_GATE_ENFORCE=true`): melempar
     * RuntimeException sehingga `DB::transaction` pemanggil rollback.
     *
     * @param  array<int, string>  $kodeAkun
     *
     * @throws RuntimeException bila gate ditegakkan dan ada selisih.
     */
    public function pastikanSubledgerSehat(array $kodeAkun = self::KODE_SUBLEDGER_DEFAULT): void
    {
        $hasil = $this->rekonsiliasiSubledger($kodeAkun);

        if ($hasil['seimbang']) {
            return;
        }

        $tegakkan = $this->gateSubledgerDitegakkan();

        $this->catatAlarmSelisih($tegakkan, $hasil);

        if (! $tegakkan) {
            return;
        }

        throw new RuntimeException(
            'Rekonsiliasi subledger menolak transaksi: '
                .implode(' ', array_map(fn (array $t) => $t['pesan'], $hasil['temuan']))
        );
    }

    /** Apakah gate subledger dijalankan dalam mode tegakkan (default: lapor). */
    public function gateSubledgerDitegakkan(): bool
    {
        return (bool) config('reconciliation.gate_enforce', false);
    }

    /**
     * Catat alarm selisih subledger — dibatasi sekali per jam.
     *
     * Bila store cache gagal (driver file tak dapat menulis, Redis mati, disk
     * penuh), alarm TETAP dicatat: infrastruktur yang bermasalah justru saat
     * paling berbahaya, sehingga kehilangan alarm senyap tidak dapat diterima.
     * Kegagalan cache hanya menghilangkan pembatasan frekuensi, bukan alarmnya.
     *
     * Lingkungan `testing` dilewati karena suite memanggil gate ribuan kali
     * dengan selisih buatan test; tanpa ini log pengujian dibanjiri CRITICAL
     * palsu yang menenggelamkan alarm sungguhan.
     *
     * Dedup SENGAJA tidak memakai facade `Cache`: store yang bermasalah
     * menginisialisasi objek-objek internal secara separuh, dan melempar lagi
     * saat di-teardown — alarm ikut hilang. Kegagalan dedup hanya menghapus
     * pembatas frekuensi; alarm selalu tercatat.
     */
    private function catatAlarmSelisih(bool $tegakkan, array $hasil): void
    {
        if (app()->environment('testing')) {
            return;
        }

        if ($this->sudahDilaporkan(window: 60)) {
            return; // Sudah dilaporkan dalam jam terakhir.
        }

        try {
            Log::critical('Rekonsiliasi subledger menemukan selisih saldo simpanan.', [
                'mode' => $tegakkan ? 'tegakkan' : 'lapor',
                'baris' => $hasil['baris'],
                'temuan' => array_map(fn (array $t) => $t['aturan'], $hasil['temuan']),
            ]);
        } catch (\Throwable) {
            // Pencatatan tidak boleh menjadi sumber kegagalan transaksi.
        }
    }

    /**
     * Apakah alarm selisih sudah dilaporkan dalam `$window` menit terakhir.
     *
     * Penanda berupa berkas kecil di `storage/framework/cache` — TIDAK memakai
     * facade `Cache`, karena dedup ini justru harus tetap bekerja saat store
     * cache bermasalah (itulah saat alarm paling dibutuhkan). Bila penanda
     * tidak dapat ditulis/dibaca, method mengembalikan `false` sehingga alarm
     * tetap dicatat; kegagalan dedup hanya melonggarkan pembatas frekuensi.
     */
    private function sudahDilaporkan(int $window): bool
    {
        try {
            $path = storage_path('framework/cache/'.self::PENANDA_LOG_SUBLEDGER);

            if (is_file($path) && (time() - (int) @filemtime($path)) < $window * 60) {
                return true;
            }

            @file_put_contents($path, (string) time());

            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Saldo akun kontrol menurut buku besar (kredit − debet).
     *
     * @param  array<int, string>  $kodeAkun
     * @return Collection<int, object>
     */
    private function saldoGlPerAkun(array $kodeAkun)
    {
        return DB::table('jurnal_detail as d')
            ->join('jurnal as j', function ($join) {
                $join->on('j.id', '=', 'd.jurnal_id')->whereNull('j.deleted_at');
            })
            ->join('coas as c', 'c.id', '=', 'd.coa_id')
            ->where('j.status', 'Posted')
            ->whereIn('c.kode_akun', $kodeAkun)
            ->groupBy('c.kode_akun')
            ->get([
                'c.kode_akun',
                DB::raw('COALESCE(SUM(d.kredit), 0) - COALESCE(SUM(d.debet), 0) as saldo'),
            ]);
    }

    /**
     * Saldo subledger rekening anggota untuk sebuah akun kontrol.
     * Null bila akun tersebut memang tidak memiliki subledger.
     *
     * SELURUH status rekening dijumlahkan — bukan hanya `Aktif`. Rekening
     * berstatus `Jatuh Tempo` maupun `Dibekukan` tetap menyimpan kewajiban
     * koperasi kepada anggota, sehingga mengecualikannya akan menciptakan
     * selisih palsu. Rekening `Ditutup` selalu bersaldo nol pada alur yang
     * benar (pencairan/exit menolkan saldo), jadi bila masih bersaldo ia
     * memang layak dilaporkan sebagai selisih.
     */
    private function saldoSubledger(string $kodeAkun): ?float
    {
        $tipe = self::TIPE_BY_KODE[$kodeAkun] ?? null;

        if ($tipe === null) {
            return null;
        }

        $nama = JenisSimpanan::namaRekening($tipe);

        if ($nama === []) {
            return 0.0;
        }

        return (float) DB::table('rekening')
            ->whereNull('deleted_at')
            ->whereIn('jenis_rekening', $nama)
            ->sum('saldo');
    }

    /**
     * Rekonsiliasi menyeluruh buku besar (aktiva–pasiva).
     *
     * @param  string|null  $dari  Batas awal tanggal_jurnal (Y-m-d), inklusif.
     * @param  string|null  $sampai  Batas akhir tanggal_jurnal (Y-m-d), inklusif.
     * @return array{
     *     seimbang: bool,
     *     total_debet: float,
     *     total_kredit: float,
     *     selisih: float,
     *     neraca: array<string, float>,
     *     subledger: array<int, array{kode: string, gl: float, subledger: float, selisih: float, status: string}>,
     *     temuan: array<int, array{aturan: string, pesan: string, konteks: array<string, mixed>}>
     * }
     *
     * Catatan: `temuan` sengaja hanya memuat ketidakseimbangan buku besar
     * sendiri. Selisih subledger dilaporkan terpisah pada kunci `subledger`
     * karena per definisi memang masih ada (lihat P1-2) — memasukkannya ke
     * `temuan` akan membuat gerbang ini selalu gagal tanpa informasi baru.
     */
    public function rekonsiliasiBukuBesar(?string $dari = null, ?string $sampai = null): array
    {
        $toleransi = Setting::toleransiSelisihJurnal();
        $temuan = [];

        $agregat = $this->agregatBukuBesar($dari, $sampai);
        $selisih = abs($agregat['total_debet'] - $agregat['total_kredit']);

        if ($selisih >= $toleransi) {
            $temuan[] = $this->temuan(
                'BUKU_BESAR_TIDAK_SEIMBANG',
                'Total debet dan kredit buku besar tidak seimbang (aktiva–pasiva tidak seimbang): selisih Rp '
                    .number_format($selisih, 2, ',', '.').'.',
                [
                    'total_debet' => $agregat['total_debet'],
                    'total_kredit' => $agregat['total_kredit'],
                    'selisih' => $selisih,
                ]
            );
        }

        foreach ($this->jurnalTidakSeimbang($dari, $sampai, $toleransi) as $baris) {
            $temuan[] = $this->temuan(
                'JURNAL_TIMBANG',
                "Jurnal [{$baris->no_jurnal}] tidak seimbang: debet Rp "
                    .number_format((float) $baris->jumlah_debet, 2, ',', '.').' vs kredit Rp '
                    .number_format((float) $baris->jumlah_kredit, 2, ',', '.').'.',
                (array) $baris
            );
        }

        foreach ($this->jurnalTransaksiTidakSukses($dari, $sampai) as $baris) {
            $temuan[] = $this->temuan(
                'TRANSAKSI_BELUM_SUKSES',
                "Jurnal [{$baris->no_jurnal}] tertaut transaksi #{$baris->transaksi_id} "
                    ."berstatus '{$baris->status_transaksi}' (belum sukses).",
                (array) $baris
            );
        }

        foreach ($this->reversalTanpaJurnalAsal($dari, $sampai) as $baris) {
            $temuan[] = $this->temuan(
                'REVERSAL_TANPA_JURNAL_ASAL',
                "Jurnal pembalik [{$baris->no_jurnal}] merujuk transaksi #{$baris->transaksi_id} "
                    .'yang tidak memiliki jurnal asli (Posted, bukan reversal) — pembalikan tanpa dasar.',
                (array) $baris
            );
        }

        foreach ($this->transaksiVoidBelumDibalik($toleransi) as $baris) {
            $temuan[] = $this->temuan(
                'TRANSAKSI_VOID_BELUM_DIBALIK',
                "Transaksi #{$baris->transaksi_id} berstatus '{$baris->status_transaksi}' tetapi efek "
                    ."bukunya belum nol pada akun [{$baris->kode_akun}] (selisih Rp "
                    .number_format((float) $baris->selisih, 2, ',', '.').') '
                    .'— transaksi batal masih memengaruhi buku besar.',
                (array) $baris
            );
        }

        return [
            'seimbang' => $temuan === [],
            'total_debet' => $agregat['total_debet'],
            'total_kredit' => $agregat['total_kredit'],
            'selisih' => $selisih,
            'neraca' => $agregat['neraca'],
            'subledger' => $this->selisihSubledger(),
            'temuan' => $temuan,
        ];
    }

    /* =====================================================================
     | PEMERIKSAAN BARIS DETAIL
     ===================================================================== */

    /**
     * @param  Collection<int, object>  $detail
     * @return array<int, array{aturan: string, pesan: string, konteks: array<string, mixed>}>
     */
    private function periksaBarisDetail(Jurnal $jurnal, $detail): array
    {
        $temuan = [];

        $coa = Coa::query()
            ->whereIn('id', $detail->pluck('coa_id')->filter()->unique()->all())
            ->get(['id', 'kode_akun', 'nama_akun', 'is_active'])
            ->keyBy('id');

        foreach ($detail as $baris) {
            $debet = (float) $baris->debet;
            $kredit = (float) $baris->kredit;

            if ($debet > 0 && $kredit > 0) {
                $temuan[] = $this->temuan(
                    'DETAIL_DUA_SISI',
                    "Baris #{$baris->baris} jurnal [{$jurnal->no_jurnal}] terisi debet dan kredit sekaligus.",
                    ['jurnal_id' => $jurnal->id, 'detail_id' => $baris->id, 'baris' => $baris->baris]
                );
            }

            if ($debet <= 0 && $kredit <= 0) {
                $temuan[] = $this->temuan(
                    'DETAIL_KOSONG',
                    "Baris #{$baris->baris} jurnal [{$jurnal->no_jurnal}] bernilai nol (baris hampa).",
                    ['jurnal_id' => $jurnal->id, 'detail_id' => $baris->id, 'baris' => $baris->baris]
                );
            }

            $akun = $coa->get($baris->coa_id);

            if (! $akun) {
                $temuan[] = $this->temuan(
                    'DETAIL_TANPA_COA',
                    "Baris #{$baris->baris} jurnal [{$jurnal->no_jurnal}] tidak memiliki akun CoA valid.",
                    ['jurnal_id' => $jurnal->id, 'detail_id' => $baris->id, 'coa_id' => $baris->coa_id]
                );
            } elseif (! $akun->is_active) {
                $temuan[] = $this->temuan(
                    'DETAIL_COA_NONAKTIF',
                    "Baris #{$baris->baris} jurnal [{$jurnal->no_jurnal}] memakai akun nonaktif [{$akun->kode_akun}] {$akun->nama_akun}.",
                    ['jurnal_id' => $jurnal->id, 'detail_id' => $baris->id, 'kode_akun' => $akun->kode_akun]
                );
            }
        }

        return $temuan;
    }

    /* =====================================================================
     | AGREGAT BUKU BESAR
     ===================================================================== */

    /**
     * @return array{total_debet: float, total_kredit: float, neraca: array<string, float>}
     */
    private function agregatBukuBesar(?string $dari, ?string $sampai): array
    {
        $totalDebet = 0.0;
        $totalKredit = 0.0;
        $neraca = [];

        // Satu query agregat per kategori akun — menghindari N+1 pada basis
        // data besar. Hanya jurnal Posted yang dihitung (Draft/Void tidak
        // memengaruhi buku besar).
        $baris = DB::table('jurnal_detail as d')
            ->join('jurnal as j', function ($join) {
                $join->on('j.id', '=', 'd.jurnal_id')->whereNull('j.deleted_at');
            })
            ->join('coas as c', 'c.id', '=', 'd.coa_id')
            ->where('j.status', 'Posted')
            ->when($dari !== null, fn ($q) => $q->whereDate('j.tanggal_jurnal', '>=', $dari))
            ->when($sampai !== null, fn ($q) => $q->whereDate('j.tanggal_jurnal', '<=', $sampai))
            ->groupBy('c.kategori')
            ->get([
                'c.kategori',
                DB::raw('COALESCE(SUM(d.debet), 0) as total_debet'),
                DB::raw('COALESCE(SUM(d.kredit), 0) as total_kredit'),
            ]);

        foreach ($baris as $item) {
            $debet = (float) $item->total_debet;
            $kredit = (float) $item->total_kredit;

            $totalDebet += $debet;
            $totalKredit += $kredit;

            $kategori = (string) ($item->kategori ?: 'Lainnya');

            // Saldo neto menurut posisi normal kategori: aset & beban = debet−kredit,
            // kewajiban, ekuitas & pendapatan = kredit−debet.
            $neraca[$kategori] = in_array($kategori, self::KATEGORI_AKTIVA, true)
                ? $debet - $kredit
                : ($kategori === 'Beban' ? $debet - $kredit : $kredit - $debet);
        }

        return [
            'total_debet' => $totalDebet,
            'total_kredit' => $totalKredit,
            'neraca' => $neraca,
        ];
    }

    /**
     * Jurnal Posted yang baris detailnya tidak seimbang.
     *
     * @return Collection<int, object>
     */
    private function jurnalTidakSeimbang(?string $dari, ?string $sampai, float $toleransi)
    {
        return DB::table('jurnal as j')
            ->join('jurnal_detail as d', 'd.jurnal_id', '=', 'j.id')
            ->whereNull('j.deleted_at')
            ->where('j.status', 'Posted')
            ->when($dari !== null, fn ($q) => $q->whereDate('j.tanggal_jurnal', '>=', $dari))
            ->when($sampai !== null, fn ($q) => $q->whereDate('j.tanggal_jurnal', '<=', $sampai))
            ->groupBy('j.id', 'j.no_jurnal')
            ->havingRaw('ABS(COALESCE(SUM(d.debet), 0) - COALESCE(SUM(d.kredit), 0)) >= ?', [$toleransi])
            ->get([
                'j.id as jurnal_id',
                'j.no_jurnal',
                DB::raw('COALESCE(SUM(d.debet), 0) as jumlah_debet'),
                DB::raw('COALESCE(SUM(d.kredit), 0) as jumlah_kredit'),
            ]);
    }

    /**
     * Jurnal Posted yang tertaut transaksi gagal/void/belum sukses.
     *
     * Hanya `status_transaksi = 'Sukses'` yang dianggap berhasil (sumber tunggal,
     * sama dengan `Transaksi::sukses()`). Kolom teknis `status` TIDAK lagi
     * di-OR-kan: transaksi yang sedang `Pending Void` tetap berstatus teknis
     * `success`, sehingga OR lama menyembunyikan penyimpangan buku besar.
     *
     * Jurnal PEMBALIK (`is_reversal = true`) sengaja dikecualikan: jurnal itu
     * memang tertaut transaksi yang dibatalkan dan justru menjadi bukti
     * pembalikan yang sah.
     *
     * @return Collection<int, object>
     */
    private function jurnalTransaksiTidakSukses(?string $dari, ?string $sampai)
    {
        return DB::table('jurnal as j')
            ->join('transaksi as t', 't.id', '=', 'j.transaksi_id')
            ->whereNull('j.deleted_at')
            ->where('j.status', 'Posted')
            ->whereNotNull('j.transaksi_id')
            ->where('j.is_reversal', false)
            ->when($dari !== null, fn ($q) => $q->whereDate('j.tanggal_jurnal', '>=', $dari))
            ->when($sampai !== null, fn ($q) => $q->whereDate('j.tanggal_jurnal', '<=', $sampai))
            ->where('t.status_transaksi', '!=', Transaksi::STATUS_TRANSAKSI_SUKSES)
            ->orderBy('j.id')
            ->get([
                'j.id as jurnal_id',
                'j.no_jurnal',
                't.id as transaksi_id',
                't.status_transaksi',
                't.status as status_teknis',
            ]);
    }

    /**
     * Jurnal pembalik (`is_reversal = true`) yang transaksinya tidak memiliki
     * jurnal asli Posted.
     *
     * Jurnal pembalik hanya sah bila ada yang dibalik. Reversal yatim membuat
     * buku besar bergeser tanpa pasangan: akun yang didebet/dikredit oleh
     * jurnal pembalik tersebut naik/turun permanen, sementara tidak ada jurnal
     * asal yang menyeimbangkannya.
     *
     * @return Collection<int, object>
     */
    private function reversalTanpaJurnalAsal(?string $dari = null, ?string $sampai = null)
    {
        return DB::table('jurnal as j')
            ->whereNull('j.deleted_at')
            ->where('j.status', 'Posted')
            ->where('j.is_reversal', true)
            ->whereNotNull('j.transaksi_id')
            ->when($dari !== null, fn ($q) => $q->whereDate('j.tanggal_jurnal', '>=', $dari))
            ->when($sampai !== null, fn ($q) => $q->whereDate('j.tanggal_jurnal', '<=', $sampai))
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('jurnal as asal')
                    ->whereColumn('asal.transaksi_id', 'j.transaksi_id')
                    ->whereNull('asal.deleted_at')
                    ->where('asal.status', 'Posted')
                    ->where('asal.is_reversal', false);
            })
            ->orderBy('j.id')
            ->get([
                'j.id as jurnal_id',
                'j.no_jurnal',
                'j.transaksi_id',
                'j.sumber',
                'j.tanggal_jurnal',
            ]);
    }

    /**
     * Transaksi berstatus batal (`Voided`/`Dibatalkan`) yang efek bukunya
     * BELUM nol pada satu/lebih akun — artinya sebagian/seluruh jurnal asli
     * belum diputar-balik oleh jurnal pembalik.
     *
     * Pemeriksaan ini berbasis ARUS (debet − kredit), bukan menuntut adanya
     * baris `is_reversal`: reversal angsuran memposting lebih dari sekadar
     * contra-entry (mis. memutar amortisasi margin ditangguhkan), sehingga
     * "jumlah jurnal pembalik = 1" bukan syarat yang tepat. Kontrak yang benar:
     * setelah transaksi dinyatakan batal, jumlah (debet − kredit) seluruh
     * jurnalnya per akun harus nol.
     *
     * @return Collection<int, object>
     */
    private function transaksiVoidBelumDibalik(float $toleransi)
    {
        $arus = DB::table('jurnal as j')
            ->join('jurnal_detail as d', 'd.jurnal_id', '=', 'j.id')
            ->join('coas as c', 'c.id', '=', 'd.coa_id')
            ->join('transaksi as t', 't.id', '=', 'j.transaksi_id')
            ->whereNull('j.deleted_at')
            ->where('j.status', 'Posted')
            ->whereIn('t.status_transaksi', [Transaksi::STATUS_TRANSAKSI_VOIDED, 'Dibatalkan'])
            ->groupBy('j.transaksi_id', 't.status_transaksi', 'c.kode_akun')
            ->havingRaw('ABS(SUM(d.debet) - SUM(d.kredit)) >= ?', [$toleransi])
            ->orderBy('j.transaksi_id')
            ->get([
                'j.transaksi_id',
                't.status_transaksi',
                'c.kode_akun',
                DB::raw('SUM(d.debet) - SUM(d.kredit) as selisih'),
            ]);

        return collect($arus);
    }

    /** @param array<string, mixed> $konteks */
    private function temuan(string $aturan, string $pesan, array $konteks = []): array
    {
        return ['aturan' => $aturan, 'pesan' => $pesan, 'konteks' => $konteks];
    }
}
