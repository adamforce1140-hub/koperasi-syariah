<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Coa;
use App\Models\DanaKebajikan;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\Transaksi;
use App\Support\Setting;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service pencatatan Modul Dana Kebajikan / Ta'zir.
 *
 * Dasar syariah:
 *  - PSAK 109 — ta'zir/denda adalah dana sosial, bukan pendapatan koperasi.
 *  - Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000 — denda wajib disalurkan sebagai dana sosial.
 *
 * Aturan posisi jurnal akun 2-3001 (DANA_KEBAJIKAN):
 *  - KREDIT = dana masuk/terkumpul (ta'zir, denda, infak, kontribusi kebajikan).
 *  - DEBET  = HANYA penyaluran dana sosial keluar kepada penerima yang berhak.
 *
 * Card summary SELALU dihitung dari mutasi jurnal akun 2-3001 (sumber kebenaran
 * akuntansi), bukan dari tabel transaksi — agar ta'zir yang masuk (posisi Debet
 * pada transaksi anggota) tidak salah terhitung sebagai penyaluran.
 */
class DanaKebajikanService
{
    /**
     * Sumber penerimaan kebajikan yang boleh diinput tunai oleh teller.
     * Dipakai untuk mengenali transaksi loket yang wajib disinkronkan ke riwayat.
     */
    public const SUMBER_TUNAI_TELLER = [
        "Pemotongan Ta'zir Keterlambatan",
        'AUTODEBET_TAZIR',
        "Ta'zir Tunai",
        'Infak Tunai',
        'Sedekah Tunai',
        'Kontribusi Kebajikan Tunai',
    ];

    /** Kata kunci pengenali jenis transaksi kebajikan (ta'zir/denda/infak/qardh). */
    private const KATA_KUNCI_KEBAJIKAN = [
        'denda', 'penalti', 'ta\'zir', 'tazir', 'kebajikan', 'infak', 'infaq', 'sedekah', 'shadaqah',
        'qardh', 'qard',
    ];

    /**
     * SUMBER DANA KEBAJIKAN YANG SAH (daftar tertutup / whitelist permanen).
     *
     * PSAK 109 + Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000: akun Dana Kebajikan
     * (2-3001) bersifat NON-OPERASIONAL. Dana hanya boleh masuk dari:
     *
     *  a. KATEGORI_TAAZIR — denda keterlambatan angsuran (Ta'zir / Late Fee).
     *     Hanya sah bila pengurus mengaktifkan parameter
     *     `financing_late_fee_per_day` (> 0).
     *  b. KATEGORI_DONASI — Infaq, Shadaqah/Sedekah, Kontribusi Kebajikan,
     *     serta pengembalian pinjaman Qardhul Hasan (pinjaman kebajikan tanpa
     *     margin — pengembalian pokok tidak menambah pendapatan koperasi).
     *
     * Sumber di luar daftar ini DITOLAK secara permanen — khususnya margin
     * pembiayaan (Murabahah/Mudharabah/Musyarakah) yang merupakan Pendapatan
     * Operasional dan komponen pembentuk SHU, sehingga TIDAK BOLEH dialihkan
     * ke akun Dana Kebajikan.
     */
    private const KATEGORI_TAAZIR = [
        'denda', 'penalti', 'ta\'zir', 'tazir', 'late fee', 'keterlambatan',
    ];

    private const KATEGORI_DONASI = [
        'infak', 'infaq', 'sedekah', 'shadaqah', 'shodaqoh', 'donasi', 'kontribusi kebajikan',
        'qardh', 'qard', 'qardhul hasan', 'qard al hasan', 'pinjaman kebajikan',
    ];

    /**
     * Penanda eksplisit TA'ZIR pada jenis transaksi.
     *
     * R7 (tindak lanjut) — jenis transaksi penalti pencairan dini deposito
     * memuat nama akad penyimpanannya (mis. "Pencairan Dini Deposito
     * Mudharabah"). Tanpa penanda ini, kata 'mudharabah' pada jenis transaksi
     * akan terbaca sebagai penanda MARGIN pembiayaan sehingga ta'zir yang SAH
     * ikut ditolak.
     *
     * Pengabaian kata kunci margin HANYA berlaku bila jenis transaksi secara
     * eksplisit menyatakan ta'zir/denda/penalti — sehingga larangan pemisahan
     * margin pembiayaan (PSAK 108) tetap utuh: jenis transaksi yang benar-benar
     * berasal dari margin pembiayaan tidak pernah memuat penanda ini.
     */
    private const PENANDA_TAAZIR_EKSPLISIT = [
        "ta'zir", 'tazir', 'denda', 'penalti', 'late fee', 'keterlambatan',
    ];

    /**
     * Kata kunci yang MENANDAKAN dana berasal dari PENDAPATAN OPERASIONAL
     * (margin/bagi hasil pembiayaan). Kemunculannya pada jenis transaksi
     * kebajikan merupakan pelanggaran berat pemisahan PSAK 108 → selalu DITOLAK.
     */
    private const KATA_KUNCI_MARGIN_TERLARANG = [
        'margin', 'murabahah', 'murabaha', 'mudharabah', 'mudarabah',
        'musyarakah', 'musyarakat', 'musharakah', 'bagi hasil', 'pendapatan',
    ];

    /**
     * Kata kunci jenis transaksi yang menandakan PENYALURAN dana sosial keluar.
     * Hanya jenis ini yang boleh memakai badge posisi 'Debet'.
     */
    private const KATA_KUNCI_PENYALURAN = [
        'penyaluran', 'disalurkan', 'bantuan sosial', 'santunan', 'mustahiq',
    ];

    public function __construct(private AutoJournalingService $jurnal) {}

    /* =====================================================================
     | RINGKASAN / CARD SUMMARY — berbasis mutasi jurnal akun 2-3001
     ===================================================================== */

    /**
     * Hitung ringkasan Dana Kebajikan dari mutasi jurnal akun 2-3001.
     *
     *  TOTAL TERKUMPUL  = SUM(kredit) seluruh mutasi 2-3001
     *  TOTAL DISALURKAN = SUM(debet)  seluruh mutasi 2-3001
     *  SALDO            = TOTAL TERKUMPUL - TOTAL DISALURKAN
     *
     * @return array{totalTerkumpul: float, totalDisalurkan: float, saldoKebajikan: float, jumlahMutasi: int, akunTersedia: bool}
     */
    public function ringkasan(): array
    {
        $coa = Coa::findByKode(AutoJournalingService::DANA_KEBAJIKAN);

        if (! $coa) {
            Log::warning('DanaKebajikanService: akun 2-3001 (Dana Kebajikan) tidak ditemukan — ringkasan dikembalikan nol.');

            return [
                'totalTerkumpul' => 0.0,
                'totalDisalurkan' => 0.0,
                'saldoKebajikan' => 0.0,
                'jumlahMutasi' => 0,
                'akunTersedia' => false,
            ];
        }

        // Query builder langsung (bukan Eloquent) agar alias agregat tidak
        // bertabrakan dengan accessor JurnalDetail::getJumlahAttribute().
        // WAJIB join ke `jurnal` dan memfilter status='Posted' + non-deleted:
        // jurnal yang di-void tetap menyimpan baris detailnya, sehingga tanpa
        // filter ini saldo Dana Kebajikan menjadi overstated (lihat PSAK 101).
        $agg = DB::table('jurnal_detail as jd')
            ->join('jurnal as j', 'j.id', '=', 'jd.jurnal_id')
            ->where('jd.coa_id', $coa->id)
            ->where('j.status', 'Posted')
            ->whereNull('j.deleted_at')
            ->selectRaw('COALESCE(SUM(jd.kredit), 0) AS total_kredit, COALESCE(SUM(jd.debet), 0) AS total_debet, COUNT(*) AS total_baris')
            ->first();

        $totalTerkumpul = Setting::bulatkanUang((float) ($agg->total_kredit ?? 0));
        $totalDisalurkan = Setting::bulatkanUang((float) ($agg->total_debet ?? 0));

        return [
            'totalTerkumpul' => $totalTerkumpul,
            'totalDisalurkan' => $totalDisalurkan,
            'saldoKebajikan' => Setting::bulatkanUang($totalTerkumpul - $totalDisalurkan),
            'jumlahMutasi' => (int) ($agg->total_baris ?? 0),
            'akunTersedia' => true,
        ];
    }

    /**
     * Validasi perhitungan saldo terhadap seluruh mutasi 2-3001.
     *
     * Memastikan saldo hasil rumus (terkumpul - disalurkan) konsisten dengan
     * penjumlahan baris per baris, dan bahwa saldo tidak negatif (penyaluran
     * melebihi dana terkumpul = indikasi kesalahan pencatatan).
     *
     * @return array{valid: bool, saldoMutasi: float, saldoRumus: float, selisih: float, catatan: array<int,string>}
     */
    public function validasiSaldo(): array
    {
        $ringkasan = $this->ringkasan();
        $catatan = [];

        $saldoMutasi = 0.0;
        $coa = Coa::findByKode(AutoJournalingService::DANA_KEBAJIKAN);

        if ($coa) {
            // Dihitung ulang baris per baris (bukan satu agregat) sebagai kontrol silang.
            // Filter Posted + non-deleted harus IDENTIK dengan ringkasan() agar
            // kontrol silang benar-benar menguji perhitungan, bukan sumber berbeda.
            JurnalDetail::query()
                ->where('coa_id', $coa->id)
                ->whereHas('jurnal', function ($q) {
                    $q->where('status', 'Posted');
                })
                ->chunk(500, function (Collection $rows) use (&$saldoMutasi) {
                    foreach ($rows as $row) {
                        $saldoMutasi += (float) $row->kredit - (float) $row->debet;
                    }
                });
        }

        $saldoMutasi = Setting::bulatkanUang($saldoMutasi);
        $saldoRumus = $ringkasan['saldoKebajikan'];
        $selisih = Setting::bulatkanUang($saldoMutasi - $saldoRumus);

        if (abs($selisih) >= Setting::toleransiSelisihJurnal()) {
            $catatan[] = 'Saldo hasil rumus tidak sama dengan penjumlahan mutasi (selisih '.number_format($selisih, 2).').';
        }

        if ($saldoMutasi < 0) {
            $catatan[] = 'Saldo Dana Kebajikan negatif — penyaluran melebihi dana terkumpul.';
        }

        if ($catatan !== []) {
            Log::error('DanaKebajikanService: validasi saldo Dana Kebajikan gagal.', [
                'saldo_mutasi' => $saldoMutasi,
                'saldo_rumus' => $saldoRumus,
                'selisih' => $selisih,
                'catatan' => $catatan,
            ]);
        }

        return [
            'valid' => $catatan === [],
            'saldoMutasi' => $saldoMutasi,
            'saldoRumus' => $saldoRumus,
            'selisih' => $selisih,
            'catatan' => $catatan,
        ];
    }

    /**
     * Mutasi jurnal 2-3001 untuk kebutuhan tampilan riwayat (sumber kebenaran).
     */
    public function mutasi(int $limit = 50): Collection
    {
        $coa = Coa::findByKode(AutoJournalingService::DANA_KEBAJIKAN);

        if (! $coa) {
            return collect();
        }

        return JurnalDetail::query()
            ->with(['jurnal.transaksi.dibuatOleh'])
            ->where('coa_id', $coa->id)
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Daftar riwayat transaksi Dana Kebajikan (ta'zir/denda/infak) untuk halaman DPS.
     * Mencakup transaksi anggota (auto-debet/teller), transaksi tunai teller yang
     * sudah tersinkronkan, DAN penyaluran dana sosial (dana keluar dari 2-3001).
     */
    public function riwayat(int $perPage = 25): LengthAwarePaginator
    {
        return Transaksi::with(['rekening.anggota', 'dibuatOleh'])
            ->where(function ($q) {
                $q->where('jenis_transaksi', 'like', '%Denda%')
                    ->orWhere('jenis_transaksi', 'like', '%Penalti%')
                    ->orWhere('jenis_transaksi', 'like', "%Ta'zir%")
                    ->orWhere('jenis_transaksi', 'like', '%Tazir%')
                  // R7 (tindak lanjut) — ta'zir penalti pencairan dini deposito.
                    ->orWhere('jenis_transaksi', 'like', '%Pencairan Dini Deposito%')
                    ->orWhere('jenis_transaksi', 'like', '%Kebajikan%')
                    ->orWhere('jenis_transaksi', 'like', '%Infak%')
                    ->orWhere('jenis_transaksi', 'like', '%Sedekah%')
                  // Penyaluran dana sosial — satu-satunya jenis berbadge posisi 'Debet'.
                    ->orWhere('jenis_transaksi', 'like', '%Penyaluran%')
                    ->orWhere('jenis_transaksi', 'like', '%Bantuan Sosial%')
                    ->orWhere('jenis_transaksi', 'like', '%Santunan%');
            })
            ->latest('waktu_transaksi')
            ->paginate($perPage)
            ->withQueryString()
            ->through(function (Transaksi $trx) {
                /* Kolom POSISI mengikuti aturan akun 2-3001, bukan `transaksi.posisi`.
                 | Pada skema auto-debet, `transaksi.posisi` bernilai 'Debet' (sisi
                 | simpanan anggota) sehingga badge akan tampil terbalik. */
                $trx->posisi_kebajikan = $this->posisiKebajikan($trx);

                return $trx;
            });
    }

    /* =====================================================================
     | PENYALURAN DANA SOSIAL — satu-satunya jalur DEBET 2-3001
     ===================================================================== */

    /**
     * Catat penyaluran dana kebajikan kepada penerima yang berhak (mustahiq).
     *
     * Jurnal: DEBET 2-3001 (Dana Kebajikan) | KREDIT 1-1001 (Kas Teller)
     * Posisi DEBET pada 2-3001 HANYA sah melalui jalur ini.
     *
     * @param  array{penerima: string, jumlah: float, keterangan?: string, no_referensi?: string, kode_sumber_dana?: string}  $data
     *
     * @throws \InvalidArgumentException bila nominal tidak valid / melebihi saldo
     * @throws \RuntimeException bila jurnal penyaluran gagal dibuat
     */
    public function salurkan(array $data, ?int $userId = null): DanaKebajikan
    {
        $penerima = trim((string) ($data['penerima'] ?? ''));
        $jumlah = round((float) ($data['jumlah'] ?? 0), 0);

        if ($penerima === '') {
            throw new \InvalidArgumentException('Nama penerima dana kebajikan wajib diisi.');
        }

        if ($jumlah <= 0) {
            throw new \InvalidArgumentException('Nominal penyaluran dana kebajikan harus lebih besar dari nol.');
        }

        $ringkasan = $this->ringkasan();

        if ($jumlah > $ringkasan['saldoKebajikan']) {
            throw new \InvalidArgumentException(sprintf(
                'Nominal penyaluran (Rp %s) melebihi saldo Dana Kebajikan (Rp %s).',
                number_format($jumlah, 0, ',', '.'),
                number_format($ringkasan['saldoKebajikan'], 0, ',', '.')
            ));
        }

        $keterangan = $data['keterangan']
            ?? "Penyaluran dana kebajikan kepada {$penerima}";

        return DB::transaction(function () use ($data, $penerima, $jumlah, $keterangan, $userId) {
            // TOCTOU: cek saldo yang MENGIKAT harus diambil DI DALAM transaksi
            // dan atas baris akun 2-3001 yang terkunci, agar dua penyaluran
            // konkuren tidak sama-sama lolos cek saldo (menyebabkan saldo negatif).
            $coaKebajikan = Coa::findByKode(AutoJournalingService::DANA_KEBAJIKAN);
            if ($coaKebajikan) {
                Coa::whereKey($coaKebajikan->id)->lockForUpdate()->first();

                $saldoTerkunci = $this->ringkasan()['saldoKebajikan'];

                if ($jumlah > $saldoTerkunci) {
                    throw new \InvalidArgumentException(sprintf(
                        'Nominal penyaluran (Rp %s) melebihi saldo Dana Kebajikan (Rp %s).',
                        number_format($jumlah, 0, ',', '.'),
                        number_format($saldoTerkunci, 0, ',', '.')
                    ));
                }
            }

            $pencatatan = DanaKebajikan::create([
                'no_referensi' => $data['no_referensi'] ?? 'DK-SALUR-'.now()->format('YmdHis'),
                'sumber_dana' => 'Penyaluran kepada '.$penerima,
                'jenis' => 'Penyaluran',
                'jumlah' => $jumlah,
                'keterangan' => $keterangan,
                'created_by' => $userId,
            ]);

            $jurnal = $this->jurnal->jurnalPenyaluranDanaKebajikan(
                $jumlah,
                $keterangan,
                $userId,
                null,
                $data['kode_sumber_dana'] ?? AutoJournalingService::KAS_TELLER
            );

            if (! $jurnal) {
                Log::error('DanaKebajikanService: jurnal penyaluran Dana Kebajikan gagal dibuat — pencatatan dibatalkan.', [
                    'penerima' => $penerima,
                    'jumlah' => $jumlah,
                ]);

                throw new \RuntimeException('Jurnal penyaluran Dana Kebajikan gagal dibuat. Penyaluran tidak dicatat.');
            }

            return $pencatatan;
        });
    }

    /* =====================================================================
     | INTEGRASI TRANSAKSI TUNAI TELLER
     ===================================================================== */

    /**
     * Sinkronkan transaksi PENERIMAAN tunai teller (ta'zir/denda/infak/kontribusi
     * kebajikan) ke riwayat transaksi & jurnal Dana Kebajikan.
     *
     * Idempoten: aman dipanggil berulang untuk transaksi yang sama — baris
     * `dana_kebajikans` dengan `no_referensi` yang sama tidak digandakan.
     *
     * @param  Transaksi  $transaksi  Transaksi tunai dari loket teller
     * @return DanaKebajikan|null Baris pencatatan (baru atau yang sudah ada)
     */
    public function sinkronkanTransaksiTunaiTeller(Transaksi $transaksi): ?DanaKebajikan
    {
        /* PSAK 108 — PEMBATASAN PERMANEN SUMBER DANA KEBAJIKAN.
         |
         | VALIDASI INI DIJALANKAN PALING AWAL — SEBELUM filter kata kunci
         | kebajikan. Tujuannya agar setiap UPAYA MENCAMPURKAN dana operasional
         | (khususnya MARGIN pembiayaan Murabahah/Mudharabah/Musyarakah) ke akun
         | non-operasional 2-3001 SELALU tertangkap, tercatat di log audit, dan
         | DITOLAK — bukan sekadar lolos tanpa jejak karena tidak mengandung
         | kata kunci "infak/denda". */
        $jenisTransaksi = (string) $transaksi->jenis_transaksi;

        if (in_array((string) $transaksi->status_transaksi, ['Voided', 'Dibatalkan'], true)) {
            return null;
        }

        if ($this->mengandungMarginTerlarang($jenisTransaksi) && ! $this->penandaTaazirEksplisit($jenisTransaksi)) {
            $this->catatPelanggaranSumberDana(
                $transaksi,
                'MISROUTING MARGIN: dana berasal dari Pendapatan Operasional (margin/bagi hasil pembiayaan) — DILARANG dialokasikan ke Dana Kebajikan (PSAK 108).'
            );

            return null;
        }

        if (! $this->jenisTransaksiKebajikan($jenisTransaksi)) {
            return null;
        }

        /* PEMBATASAN SUMBER DANA KEBAJIKAN (daftar tertutup).
         |
         | Dana Kebajikan hanya boleh berasal dari dua kategori:
         |   a. Denda keterlambatan angsuran (Ta'zir / Late Fee) — hanya sah bila
         |      fitur diaktifkan pada parameter `financing_late_fee_per_day`.
         |   b. Infaq, Shadaqah, atau pengembalian pinjaman Qardhul Hasan.
         |
         | Sumber lain DITOLAK & dicatat ke log audit. */
        if (! $this->sumberDanaKebajikanValid($jenisTransaksi)) {
            $alasan = $this->jenisTransaksiTaazir($jenisTransaksi)
                ? 'DISABLED: fitur denda keterlambatan (financing_late_fee_per_day) sedang NONAKTIF — ta\'zir tidak boleh dibebankan.'
                : 'SUMBER TIDAK DIAKUI: bukan Denda Ta\'zir, Infaq, Shadaqah, maupun pengembalian Qardhul Hasan (PSAK 109).';

            $this->catatPelanggaranSumberDana($transaksi, $alasan);

            return null;
        }

        // --- Pengecekan duplikasi -------------------------------------------
        $referensi = 'DK-TRX-'.$transaksi->no_transaksi;

        $sudahAda = DanaKebajikan::where('no_referensi', $referensi)->first();
        if ($sudahAda) {
            Log::info('DanaKebajikanService: transaksi tunai teller sudah tersinkron — dilewati (anti-duplikasi).', [
                'no_transaksi' => $transaksi->no_transaksi,
                'no_referensi' => $referensi,
            ]);

            return $sudahAda;
        }

        try {
            return DB::transaction(function () use ($transaksi, $referensi) {
                $keterangan = $transaksi->catatan
                    ?: 'Penerimaan kebajikan tunai — '.$transaksi->jenis_transaksi;

                // Sumber dana: auto-debet akun anggota (rekening terisi) vs tunai loket.
                // Menentukan sisi DEBET jurnal dan sumber jurnalnya.
                $dariSimpananAnggota = $transaksi->rekening_id !== null;
                $kodeDebet = $dariSimpananAnggota
                    ? AutoJournalingService::SIMPANAN_SUKARELA
                    : AutoJournalingService::KAS_TELLER;
                $sumberJurnal = $dariSimpananAnggota ? "Ta'zir" : 'Penerimaan Dana Kebajikan Tunai';

                $pencatatan = DanaKebajikan::create([
                    'no_referensi' => $referensi,
                    'sumber_dana' => $transaksi->jenis_transaksi,
                    'jenis' => 'Penerimaan',
                    'jumlah' => (float) $transaksi->jumlah,
                    'keterangan' => $keterangan,
                    'created_by' => $transaksi->user_id,
                ]);

                $jurnal = $this->jurnal->jurnalPenerimaanDanaKebajikan(
                    (float) $transaksi->jumlah,
                    $keterangan,
                    $transaksi->user_id,
                    $transaksi->id,
                    $transaksi->rekening_id,
                    $transaksi->anggota_id,
                    $sumberJurnal,
                    $kodeDebet
                );

                if (! $jurnal) {
                    Log::error('DanaKebajikanService: jurnal penerimaan kebajikan transaksi tunai gagal diposting.', [
                        'no_transaksi' => $transaksi->no_transaksi,
                        'jumlah' => $transaksi->jumlah,
                    ]);

                    // Pencatatan Dana Kebajikan tanpa jurnal = dana masuk tanpa
                    // pengakuan double-entry (PSAK 109 & neraca tidak seimbang).
                    // Lempar agar DB::transaction ikut rollback.
                    throw new \RuntimeException(
                        'Gagal memposting jurnal penerimaan Dana Kebajikan untuk transaksi '.
                        $transaksi->no_transaksi.'. Pencatatan dibatalkan agar jurnal tidak hilang.'
                    );
                }

                return $pencatatan;
            });
        } catch (\Throwable $e) {
            // Logging error agar tidak ada transaksi tunai yang lewat tanpa jejak.
            Log::error('DanaKebajikanService: GAGAL sinkronisasi transaksi tunai teller ke Dana Kebajikan.', [
                'no_transaksi' => $transaksi->no_transaksi,
                'jenis_transaksi' => $transaksi->jenis_transaksi,
                'jumlah' => $transaksi->jumlah,
                'error' => $e->getMessage(),
            ]);

            // Notifikasi sistem untuk ditindaklanjuti (Laravel database notifications).
            $this->kirimNotifikasiGagalSinkronisasi($transaksi, $e->getMessage());

            // Jangan telan error: pemanggil wajib tahu bahwa jurnal Dana Kebajikan
            // tidak terbentuk sehingga dapat membatalkan/mengulang prosesnya.
            throw $e;
        }
    }

    /**
     * Sinkronisasi massal transaksi tunai teller yang belum tercatat
     * (mis. transaksi lama sebelum integrasi ini aktif).
     *
     * @return array{diperiksa:int, tersinkron:int, dilewati:int, gagal:int}
     */
    public function sinkronkanTunaiTellerTertunda(int $limit = 200): array
    {
        $hasil = ['diperiksa' => 0, 'tersinkron' => 0, 'dilewati' => 0, 'gagal' => 0];

        $kandidat = Transaksi::query()
            ->whereIn('jenis_transaksi', self::SUMBER_TUNAI_TELLER)
            ->whereNotIn('status_transaksi', ['Voided', 'Dibatalkan'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        foreach ($kandidat as $transaksi) {
            $hasil['diperiksa']++;

            $referensi = 'DK-TRX-'.$transaksi->no_transaksi;

            if (DanaKebajikan::where('no_referensi', $referensi)->exists()) {
                $hasil['dilewati']++;

                continue;
            }

            // Anti-duplikasi tingkat jurnal: transaksi yang sudah punya jurnal
            // (mis. dibuat langsung oleh modul auto-debet ta'zir) tidak diposting ulang.
            if (Jurnal::where('transaksi_id', $transaksi->id)->exists()) {
                Log::info('DanaKebajikanService: transaksi sudah memiliki jurnal — dilewati saat sinkronisasi massal.', [
                    'no_transaksi' => $transaksi->no_transaksi,
                ]);
                $hasil['dilewati']++;

                continue;
            }

            try {
                $this->sinkronkanTransaksiTunaiTeller($transaksi)
                    ? $hasil['tersinkron']++
                    : $hasil['gagal']++;
            } catch (\Throwable $e) {
                // Satu transaksi bermasalah tidak boleh menghentikan seluruh
                // proses massal, tetapi kegagalannya WAJIB terhitung — bukan
                // ditelan — agar laporan sinkronisasi tidak menyesatkan.
                $hasil['gagal']++;
                Log::error('DanaKebajikanService: sinkronisasi massal gagal untuk satu transaksi.', [
                    'no_transaksi' => $transaksi->no_transaksi,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($hasil['gagal'] > 0) {
            Log::warning('DanaKebajikanService: sinkronisasi transaksi tunai teller selesai dengan kegagalan.', $hasil);
        }

        return $hasil;
    }

    /**
     * Notifikasi sistem bila sinkronisasi gagal — perlu tindak lanjut manual.
     *
     * Notifikasi dicatat permanen ke `audit_logs` (dapat dilihat pengurus) dan
     * ditampilkan sebagai alert pada sesi pengguna yang sedang aktif.
     */
    public function kirimNotifikasiGagalSinkronisasi(Transaksi $transaksi, string $pesanError): void
    {
        $pesan = sprintf(
            'Transaksi tunai %s (%s — Rp %s) tidak dapat disinkronkan ke Dana Kebajikan: %s',
            $transaksi->no_transaksi,
            $transaksi->jenis_transaksi,
            number_format((float) $transaksi->jumlah, 0, ',', '.'),
            $pesanError
        );

        try {
            AuditLog::record(
                'SINKRONISASI_DANA_KEBAJIKAN_GAGAL',
                (float) $transaksi->jumlah,
                $transaksi->rekening,
                $pesan
            );
        } catch (\Throwable $e) {
            Log::notice('DanaKebajikanService: notifikasi kegagalan sinkronisasi tidak tersimpan.', [
                'pesan' => $pesan,
                'error' => $e->getMessage(),
            ]);
        }

        // Tampilkan ke pengguna aktif bila ada sesi (mis. teller di loket).
        if (session()->isStarted()) {
            session()->flash('error', $pesan);
        }
    }

    /** Apakah jenis transaksi termasuk kategori kebajikan (ta'zir/denda/infak). */
    public function jenisTransaksiKebajikan(string $jenisTransaksi): bool
    {
        $jenis = strtolower($jenisTransaksi);

        foreach (self::KATA_KUNCI_KEBAJIKAN as $kata) {
            if (str_contains($jenis, $kata)) {
                return true;
            }
        }

        return false;
    }

    /* =====================================================================
     | VALIDASI SUMBER DANA KEBAJIKAN (PSAK 109 — daftar tertutup)
     ===================================================================== */

    /**
     * Validasi PERMANEN sumber dana kebajikan.
     *
     * Hanya mengembalikan `true` bila dana berasal dari salah satu dari DUA
     * kategori sah berikut:
     *
     *   a. Denda keterlambatan angsuran (Ta'zir / Late Fee) — DITOLAK bila
     *      fitur `financing_late_fee_per_day` belum diaktifkan pengurus.
     *   b. Infaq / Shadaqah / Sedekah / Kontribusi Kebajikan / pengembalian
     *      pinjaman Qardhul Hasan.
     *
     * Dana yang mengandung penanda margin pembiayaan (Murabahah, Mudharabah,
     * Musyarakah, Bagi Hasil, Margin) SELALU DITOLAK — margin adalah Pendapatan
     * Operasional (komponen SHU) dan tidak boleh berbaur ke akun 2-3001.
     *
     * @param  string  $jenisTransaksi  Jenis transaksi pada tabel `transaksi`
     * @return bool true bila sumber dana sah
     */
    public function sumberDanaKebajikanValid(string $jenisTransaksi): bool
    {
        $jenis = strtolower(trim($jenisTransaksi));

        if ($jenis === '') {
            return false;
        }

        // (0) Tolak mutlak: jenis apa pun yang menandakan margin/pendapatan operasional.
        //     PENGECUALIAN (R7 tindak lanjut): jenis transaksi ta'zir penalti yang
        //     namanya memuat nama akad penyimpanan (mis. "Pencairan Dini Deposito
        //     Mudharabah") bukan margin pembiayaan — penalti adalah denda, tetap
        //     dana sosial. Pengabaian HANYA berlaku bila jenis transaksi menyatakan
        //     ta'zir/denda/penalti secara eksplisit, sehingga margin pembiayaan
        //     yang sesungguhnya tetap ditolak.
        if ($this->mengandungMarginTerlarang($jenis) && ! $this->penandaTaazirEksplisit($jenis)) {
            return false;
        }

        // (a) Denda Ta'zir / Late Fee — wajib fitur aktif.
        if ($this->jenisTransaksiTaazir($jenis)) {
            return Setting::fiturDendaPembiayaanAktif();
        }

        // (b) Infaq / Shadaqah / Qardhul Hasan.
        if ($this->jenisTransaksiDonasi($jenis)) {
            return true;
        }

        // Sumber lain (mis. 'Kontribusi Operasional') → DITOLAK.
        return false;
    }

    /**
     * Apakah jenis transaksi menyatakan TA'ZIR/DENDA/PENALTI secara eksplisit.
     *
     * Dipakai sebagai pengecualian sempit atas {@see KATA_KUNCI_MARGIN_TERLARANG}:
     * penalti pencairan dini deposito dinamai menurut akad penyimpanannya
     * ("... Deposito Mudharabah") sehingga kata 'mudharabah' muncul pada jenis
     * transaksi ta'zir yang SAH. Tanpa pengecualian ini, ta'zir tersebut akan
     * salah dibaca sebagai misrouting margin dan ikut ditolak — padahal justru
     * itulah alur yang menjamin penalti tidak masuk akun pendapatan (Fatwa
     * DSN-MUI No. 17/DSN-MUI/IX/2000).
     */
    public function penandaTaazirEksplisit(string $jenisTransaksi): bool
    {
        $jenis = strtolower($jenisTransaksi);

        foreach (self::PENANDA_TAAZIR_EKSPLISIT as $kata) {
            if (str_contains($jenis, $kata)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apakah jenis transaksi merupakan denda Ta'zir / Late Fee.
     * Sengaja dipisah agar validasi & pelaporan audit dapat menjelaskan alasan.
     */
    public function jenisTransaksiTaazir(string $jenisTransaksi): bool
    {
        $jenis = strtolower($jenisTransaksi);

        foreach (self::KATEGORI_TAAZIR as $kata) {
            if (str_contains($jenis, $kata)) {
                return true;
            }
        }

        return false;
    }

    /** Apakah jenis transaksi merupakan donasi sah (infaq/shadaqah/qardhul hasan). */
    public function jenisTransaksiDonasi(string $jenisTransaksi): bool
    {
        $jenis = strtolower($jenisTransaksi);

        foreach (self::KATEGORI_DONASI as $kata) {
            if (str_contains($jenis, $kata)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apakah jenis transaksi mengandung penanda PENDAPATAN OPERASIONAL
     * (margin/bagi hasil). Dipakai untuk audit log pelanggaran pemisahan.
     */
    public function mengandungMarginTerlarang(string $jenisTransaksi): bool
    {
        $jenis = strtolower($jenisTransaksi);

        foreach (self::KATA_KUNCI_MARGIN_TERLARANG as $kata) {
            if (str_contains($jenis, $kata)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Catat UPAYA PELANGGARAN pemisahan sumber dana kebajikan ke log sistem &
     * log audit — untuk keperluan audit internal maupun eksternal.
     *
     * Dipanggil setiap kali sebuah transaksi tunai teller DITOLAK karena:
     *  - mengandung penanda margin/bagi hasil (misrouting Pendapatan Operasional
     *    ke Dana Kebajikan), atau
     *  - bersumber dari kategori yang tidak diakui (di luar daftar tertutup
     *    Denda Ta'zir / Infaq / Shadaqah / pengembalian Qardhul Hasan).
     *
     * Pencatatan TIDAK PERNAH memblokir pencatatan log itu sendiri — kegagalan
     * AuditLog tidak boleh menghentikan penolakan transaksi.
     */
    public function catatPelanggaranSumberDana(Transaksi $transaksi, string $alasan): void
    {
        Log::error('DanaKebajikanService: SUMBER DANA KEBAJIKAN TIDAK SAH — sinkronisasi DITOLAK (PSAK 108/109).', [
            'no_transaksi' => $transaksi->no_transaksi,
            'jenis_transaksi' => $transaksi->jenis_transaksi,
            'jumlah' => $transaksi->jumlah,
            'alasan' => $alasan,
            'kategori_sah' => 'Denda Ta\'zir (bila fitur aktif), Infaq, Shadaqah, Pengembalian Qardhul Hasan',
        ]);

        AuditLog::record(
            'Pelanggaran Sumber Dana Kebajikan',
            (float) $transaksi->jumlah,
            $transaksi->rekening?->no_rekening ?? $transaksi->no_transaksi,
            'Upaya pencatatan dana kebajikan dengan sumber tidak sah: "'
                .$transaksi->jenis_transaksi.'". '.$alasan.' Transaksi DITOLAK (PSAK 108/109).'
        );
    }

    /* =====================================================================
     | POSISI TRANSAKSI UNTUK TABEL RIWAYAT
     ===================================================================== */

    /**
     * Posisi transaksi pada kolom POSISI tabel riwayat Dana Kebajikan.
     *
     * Aturan (selaras dengan aturan jurnal akun 2-3001):
     *  - Penerimaan denda/ta'zir/infak/kontribusi kebajikan → KREDIT (dana masuk).
     *  - Penyaluran dana sosial kepada penerima berhak     → DEBET  (dana keluar).
     *
     * Kolom `transaksi.posisi` TIDAK dipakai langsung karena pada skema auto-debet
     * nilainya 'Debet' (sisi simpanan anggota), sehingga akan tampil terbalik
     * sebagai dana keluar padahal ta'zir adalah dana masuk ke akun 2-3001.
     */
    public function posisiKebajikan(Transaksi|string|null $transaksi): string
    {
        $jenis = $transaksi instanceof Transaksi
            ? (string) $transaksi->jenis_transaksi
            : (string) $transaksi;

        // Pengecualian ketat: 'Debet' hanya untuk penyaluran dana sosial.
        return $this->jenisPenyaluranDanaSosial($jenis) ? 'Debet' : 'Kredit';
    }

    /** Apakah jenis transaksi merupakan penyaluran dana sosial (satu-satunya posisi DEBET). */
    public function jenisPenyaluranDanaSosial(string $jenisTransaksi): bool
    {
        $jenis = strtolower($jenisTransaksi);

        foreach (self::KATA_KUNCI_PENYALURAN as $kata) {
            if (str_contains($jenis, $kata)) {
                return true;
            }
        }

        return false;
    }

    /** Generator nomor referensi pencatatan Dana Kebajikan. */
    public function buatNoReferensi(string $prefix = 'DK'): string
    {
        return $prefix.'-'.Carbon::now()->format('YmdHis');
    }

    /** Apakah transaksi sudah tercatat di riwayat Dana Kebajikan. */
    public function sudahTercatat(Transaksi $transaksi): bool
    {
        return DanaKebajikan::where('no_referensi', 'DK-TRX-'.$transaksi->no_transaksi)->exists();
    }

    /**
     * Tandai pencatatan Dana Kebajikan sebagai dibatalkan (void).
     *
     * Jenis diubah menjadi 'Void' (bukan dihapus) agar jejak audit tetap utuh,
     * dan agar laporan berbasis tabel `dana_kebajikans` tidak menghitung ganda
     * transaksi yang jurnalnya sudah dibalik oleh ReversalService.
     */
    public function batalkanPencatatanTransaksi(Transaksi $transaksi, ?int $userId = null): void
    {
        $referensi = 'DK-TRX-'.$transaksi->no_transaksi;

        $pencatatan = DanaKebajikan::where('no_referensi', $referensi)->first();
        if (! $pencatatan) {
            return;
        }

        $pencatatan->update([
            'jenis' => 'Void',
            'keterangan' => trim((string) $pencatatan->keterangan.' | Dibatalkan (void)'
                .($userId ? ' oleh user #'.$userId : '')),
        ]);

        Log::info('DanaKebajikanService: pencatatan dibatalkan karena transaksi void.', [
            'no_referensi' => $referensi,
            'no_transaksi' => $transaksi->no_transaksi,
        ]);
    }

    /** Hitung total penerimaan/penyaluran dari tabel pencatatan (untuk laporan pengurus). */
    public function totalPencatatan(?string $dari = null, ?string $sampai = null): array
    {
        $query = DanaKebajikan::query()
            ->when($dari, fn ($q) => $q->whereDate('created_at', '>=', $dari))
            ->when($sampai, fn ($q) => $q->whereDate('created_at', '<=', $sampai));

        $penerimaan = (float) (clone $query)->where('jenis', 'Penerimaan')->sum('jumlah');
        $penyaluran = (float) (clone $query)->where('jenis', 'Penyaluran')->sum('jumlah');

        return [
            'penerimaan' => $penerimaan,
            'penyaluran' => $penyaluran,
            'saldo' => Setting::bulatkanUang($penerimaan - $penyaluran),
        ];
    }
}
