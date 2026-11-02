<?php

namespace App\Http\Controllers\Dashboard;

use App\Contracts\AuditAkadRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Models\Anggota;
use App\Models\AuditLog;
use App\Models\DpsOpiniCatatan;
use App\Models\MasterProdukPembiayaan;
use App\Models\Pembiayaan;
use App\Models\Transaksi;
use App\Services\DanaKebajikanService;
use App\Services\PendapatanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DpsController extends Controller
{
    public function index(PendapatanService $pendapatanService, DanaKebajikanService $danaKebajikanService)
    {
        return view('dashboard.dps', $this->data($pendapatanService, $danaKebajikanService));
    }

    /**
     * Potongan HTML untuk mesin denyut (real-time tanpa reload penuh).
     * Kunci `html` dipetakan ke `data-denyut-sasaran` pada view.
     */
    public function ringkas(PendapatanService $pendapatanService, DanaKebajikanService $danaKebajikanService): JsonResponse
    {
        $data = $this->data($pendapatanService, $danaKebajikanService);

        return response()->json([
            'html' => [
                'stats' => view('dashboard.partials.dps-stats', $data)->render(),
                'akad' => view('dashboard.partials.dps-akad', $data)->render(),
                'pendapatan' => view('dashboard.partials.dps-pendapatan', $data)->render(),
                'kebajikan' => view('dashboard.partials.dps-kebajikan', $data)->render(),
                'transaksi' => view('dashboard.partials.dps-transaksi', $data)->render(),
                'pembiayaan' => view('dashboard.partials.dps-pembiayaan', $data)->render(),
            ],
            'waktu' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store, must-revalidate');
    }

    /** Satu sumber data untuk index() dan ringkas(). */
    private function data(PendapatanService $pendapatanService, DanaKebajikanService $danaKebajikanService): array
    {
        // Statistik dasar
        $totalAnggota = Anggota::count();
        $totalPembiayaanAktif = Pembiayaan::statusIn([
            Pembiayaan::STATUS_DICAIRKAN,
            Pembiayaan::STATUS_AKTIF,
            Pembiayaan::STATUS_RESTRUKTURISASI,
        ])->count();
        $totalTransaksiAll = Transaksi::count();

        // Breakdown pembiayaan per jenis akad (semua status)
        $pembiayaanPerAkad = Pembiayaan::selectRaw('akad_pembiayaan, COUNT(*) as jumlah_kontrak, SUM(jumlah_pembiayaan) as total_plafon')
            ->whereNotNull('akad_pembiayaan')
            ->groupBy('akad_pembiayaan')
            ->orderByDesc('total_plafon')
            ->get();

        /* Pendapatan Margin vs Bagi Hasil.
         |
         | PENTING: kolom `pembiayaan.margin_nisbah` berisi PERSENTASE nisbah
         | (mis. 12.00 berarti 12%), BUKAN nominal rupiah. Menjumlahkannya
         | sebagai rupiah pernah menghasilkan angka palsu (Margin Rp 21,
         | Bagi Hasil Rp 18) — nilainya hanya akumulasi persen.
         |
         | Sumber nilai yang benar: `angsuran.margin_ujroh` untuk angsuran yang
         | benar-benar sudah dibayar. Perhitungannya diambil dari
         | PendapatanService agar IDENTIK dengan halaman Laporan Keuangan
         | Syariah (satu sumber kebenaran, tidak bisa lagi menyimpang).
         |
         | Pengelompokan akad mengikuti PSAK 107:
         |   Margin     = Murabahah, IMBT, Istishna, Salam
         |   Bagi Hasil = Mudharabah, Musyarakah
         |   Ujroh      = Ijarah + biaya administrasi/fee (akun 4-2001) */
        $pendapatan = $pendapatanService->ringkasan();
        $totalMargin = $pendapatan['margin'];
        $totalBagiHasil = $pendapatan['bagi_hasil'];
        $totalUjroh = $pendapatan['ujroh'];
        $totalPendapatan = $pendapatan['total'];

        /* Dana Terkumpul — WAJIB identik dengan modul Dana Kebajikan.
         |
         | Sebelumnya widget ini dihitung dari tabel `transaksi` dengan filter
         | LIKE '%Denda%|%Penalti%|%Ta'zir%|%Kebajikan%|%Infaq%'. Query itu salah
         | karena:
         |   1. Menjumlahkan SELURUH baris tanpa memandang posisi Debet/Kredit,
         |      sehingga penyaluran dana keluar justru MENAMBAH total.
         |   2. Memakai kolom `status` yang berisi nilai tak baku ('success',
         |      'rejected') alih-alih `status_transaksi`, dan enum-nya tidak
         |      memuat 'Voided' sehingga baris void lolos filter.
         |   3. Tidak melihat jurnal sama sekali, padahal ta'zir dibukukan
         |      KREDIT 2-3001 secara double-entry.
         | Akibatnya nilai tampil Rp 50.000 (hanya 1 transaksi yang kebetulan
         | cocok LIKE-nya) sementara modul Dana Kebajikan menampilkan Rp 1.156.000.
         |
         | Perbaikan: ambil TOTAL TERKUMPUL = SUM(KREDIT) mutasi jurnal akun
         | 2-3001 dari DanaKebajikanService::ringkasan() — method yang sama
         | persis dengan yang dipakai halaman dps/dana-kebajikan. Dengan begitu
         | tidak ada lagi perbedaan filter (rentang tanggal, status transaksi,
         | maupun posisi Debet/Kredit) yang bisa membuat kedua halaman berbeda. */
        $danaKebajikan = $danaKebajikanService->ringkasan()['totalTerkumpul'];

        // Transaksi terbaru untuk audit (10 terakhir)
        $transaksiTerbaru = Transaksi::with(['rekening.anggota', 'dibuatOleh'])
            ->latest('waktu_transaksi')
            ->limit(10)
            ->get();

        // Pembiayaan aktif untuk sampling kepatuhan syariah
        $pembiayaanAktif = Pembiayaan::with(['anggota', 'approvedBy'])
            ->statusIn([
                Pembiayaan::STATUS_DICAIRKAN,
                Pembiayaan::STATUS_AKTIF,
                Pembiayaan::STATUS_RESTRUKTURISASI,
            ])
            ->latest()
            ->limit(10)
            ->get();

        return [
            'totalAnggota' => $totalAnggota,
            'totalPembiayaanAktif' => $totalPembiayaanAktif,
            'totalTransaksiAll' => $totalTransaksiAll,
            'pembiayaanPerAkad' => $pembiayaanPerAkad,
            'totalMargin' => $totalMargin,
            'totalBagiHasil' => $totalBagiHasil,
            'totalUjroh' => $totalUjroh,
            'totalPendapatan' => $totalPendapatan,
            'danaKebajikan' => $danaKebajikan,
            'transaksiTerbaru' => $transaksiTerbaru,
            'pembiayaanAktif' => $pembiayaanAktif,
        ];
    }

    public function auditAkad(AuditAkadRepositoryInterface $auditAkad)
    {
        $akadList = Pembiayaan::daftarAkad();

        /*
         | Satu-satunya sumber daftar status untuk modul ini.
         | Dipakai bersama oleh: dropdown filter, badge tab, dan query tabel —
         | sehingga jumlah pada badge tidak mungkin berbeda dari isi tabel.
         */
        $statusList = Pembiayaan::statusPascaPersetujuan();

        // Ambil parameter filter dari request (di-whitelist agar aman)
        $akadFilter = request('akad');
        $statusFilter = request('status');
        $search = request('search');
        $tglMulai = request('tgl_mulai');
        $tglSelesai = request('tgl_selesai');

        if ($akadFilter && ! in_array($akadFilter, $akadList, true)) {
            $akadFilter = null;
        }
        // Normalisasi agar 'cair' (dan varian lain) diterima sebagai 'dicairkan'.
        if ($statusFilter) {
            $statusFilter = Pembiayaan::normalizeStatus($statusFilter);
        }
        if ($statusFilter && ! in_array($statusFilter, $statusList, true)) {
            $statusFilter = null;
        }

        /*
         | Seluruh kueri modul ini hidup di `AuditAkadRepository`:
         | tabel utama, badge per akad, total "Semua Akad", dan ringkasan.
         | Semuanya memakai basis populasi `pascaPersetujuan()` yang sama
         | sehingga badge tidak mungkin berbeda dari isi tabel.
         */
        $pembiayaan = $auditAkad->daftar($akadFilter, $statusFilter, $search, $tglMulai, $tglSelesai, 25);
        $counterAkad = $auditAkad->hitungPerAkad($statusFilter, $search, $tglMulai, $tglSelesai);
        $totalSemua = $auditAkad->total($statusFilter, $search, $tglMulai, $tglSelesai);
        $ringkasanAkad = $auditAkad->ringkasanPerAkadDanStatus();

        return view('dps.audit-akad', compact(
            'pembiayaan', 'ringkasanAkad', 'akadFilter', 'akadList',
            'statusList', 'statusFilter', 'search', 'tglMulai', 'tglSelesai',
            'counterAkad', 'totalSemua'
        ));
    }

    public function opiniCatatan()
    {
        $opiniList = DpsOpiniCatatan::with('user')->latest()->paginate(15);

        return view('dps.opini-catatan', compact('opiniList'));
    }

    public function opiniCatatanStore(Request $request)
    {
        $request->validate([
            'judul' => 'required|string|max:255',
            'kategori' => 'nullable|string',
            'periode' => 'nullable|string',
            'status_kepatuhan' => 'nullable|string',
            'isi' => 'required|string',
            'lampiran' => 'nullable|file|mimes:pdf,doc,docx,xls,xlsx|max:5120',
        ]);

        $lampiranPath = null;
        if ($request->hasFile('lampiran')) {
            $lampiranPath = $request->file('lampiran')->store('dps_opini', 'dokumen');
        }

        DpsOpiniCatatan::create([
            'user_id' => Auth::id(),
            'judul' => $request->judul,
            'kategori' => $request->kategori ?? 'opini_tahunan',
            'periode' => $request->periode,
            'status_kepatuhan' => $request->status_kepatuhan ?? 'patuh',
            'isi' => $request->isi,
            'lampiran' => $lampiranPath,
        ]);

        return redirect()->route('dps.opini-catatan')
            ->with('success', 'Opini & Catatan Syariah berhasil disimpan dan didokumentasikan.');
    }

    public function reviewProduk(Request $request)
    {
        $produk = MasterProdukPembiayaan::orderBy('nama_produk')->paginate(15);

        return view('dps.review-produk', compact('produk'));
    }

    public function reviewProdukApprove(Request $request, int $id)
    {
        $validated = $request->validate([
            'catatan_dps' => 'nullable|string|max:1000',
        ]);

        $produk = MasterProdukPembiayaan::findOrFail($id);

        // Idempoten: sertifikasi ulang hanya menghasilkan audit log palsu
        // ("berhasil diterbitkan") padahal status tidak berubah.
        if ($produk->status_sertifikasi_dps === MasterProdukPembiayaan::SERTIFIKASI_VALID) {
            return redirect()->route('dps.review-produk.index')
                ->with('info', "Produk '{$produk->nama_produk}' sudah tersertifikasi DPS.");
        }

        DB::transaction(function () use ($produk, $validated) {
            $produk->update([
                'status_sertifikasi_dps' => MasterProdukPembiayaan::SERTIFIKASI_VALID,
                'disahkan_oleh' => Auth::id(),
                'tanggal_disahkan' => now(),
                'catatan_dps' => $validated['catatan_dps'] ?? null,
            ]);

            AuditLog::record(
                'SERTIFIKASI_PRODUK_DPS',
                0,
                null,
                "Sertifikasi opini syariah produk '{$produk->nama_produk}' (akad {$produk->akad_syariah}) oleh DPS."
            );
        });

        return redirect()->route('dps.review-produk.index')
            ->with('success', "Sertifikasi Opini Syariah untuk produk '{$produk->nama_produk}' berhasil diterbitkan oleh DPS.");
    }

    public function danaKebajikan(DanaKebajikanService $danaKebajikan)
    {
        // Riwayat transaksi denda/penalti/ta'zir/infak (termasuk transaksi tunai teller).
        $transaksi = $danaKebajikan->riwayat(25);

        /* Card summary WAJIB dihitung dari mutasi jurnal akun 2-3001 — bukan dari
         | tabel transaksi. Transaksi pemotongan ta'zir berposisi 'Debet' pada sisi
         | anggota, sehingga menghitung penyaluran dari `transaksi.posisi` akan
         | salah mengklasifikasikan ta'zir masuk sebagai dana keluar.
         |
         |  TOTAL TERKUMPUL  = SUM(KREDIT) mutasi 2-3001
         |  TOTAL DISALURKAN = SUM(DEBET)  mutasi 2-3001
         |  SALDO            = TOTAL TERKUMPUL - TOTAL DISALURKAN
         */
        $ringkasan = $danaKebajikan->ringkasan();
        $totalTerkumpul = $ringkasan['totalTerkumpul'];
        $totalDisalurkan = $ringkasan['totalDisalurkan'];
        $saldoKebajikan = $ringkasan['saldoKebajikan'];

        // Validasi perhitungan: saldo harus konsisten dengan seluruh mutasi tercatat.
        $validasiSaldo = $danaKebajikan->validasiSaldo();

        return view('dps.dana-kebajikan', compact(
            'transaksi',
            'totalTerkumpul',
            'totalDisalurkan',
            'saldoKebajikan',
            'validasiSaldo'
        ));
    }

    /**
     * Endpoint ringkasan card Dana Kebajikan (JSON) — dipakai refresh otomatis
     * di halaman DPS tanpa reload penuh.
     */
    public function ringkasanDanaKebajikan(DanaKebajikanService $danaKebajikan)
    {
        $ringkasan = $danaKebajikan->ringkasan();

        return response()->json([
            'totalTerkumpul' => $ringkasan['totalTerkumpul'],
            'totalDisalurkan' => $ringkasan['totalDisalurkan'],
            'saldoKebajikan' => $ringkasan['saldoKebajikan'],
            'jumlahMutasi' => $ringkasan['jumlahMutasi'],
            'valid' => $danaKebajikan->validasiSaldo()['valid'],
            'diperbarui_pada' => now()->toIso8601String(),
        ]);
    }

    public function laporanKeuangan(DanaKebajikanService $danaKebajikanService, PendapatanService $pendapatanService)
    {
        /* Status pembiayaan yang SAH masuk Neraca Pembiayaan (PSAK 107).
         |
         | 'ditolak' dan 'pengajuan' (pending_ao) dikecualikan sepenuhnya —
         | kontrak yang belum/tidak pernah cair bukan piutang dan bukan aset,
         | sehingga tidak boleh ikut menghitung sisa pokok maupun % tertagih
         | (penyebab munculnya baris dummy & % tertagih negatif). */
        $statusNeraca = [
            Pembiayaan::STATUS_AKTIF,
            Pembiayaan::STATUS_RESTRUKTURISASI,
            Pembiayaan::STATUS_LUNAS,
        ];

        /* ------------------------------------------------------------------
         | PENTING: kolom `pembiayaan.margin_nisbah` berisi PERSENTASE (mis. 12.00
         | berarti 12%), bukan nominal rupiah. Menjumlahkannya sebagai rupiah
         | menghasilkan angka palsu (sebelumnya Rp 21 untuk seluruh portofolio).
         |
         | Pendapatan riil diambil dari jadwal angsuran yang benar-benar sudah
         | dibayar: `angsuran.margin_ujroh` (bagi hasil/margin) dan
         | `angsuran.pokok_angsuran` (pengembalian pokok — bukan pendapatan).
         | Perhitungannya dipusatkan di PendapatanService agar kartu dashboard
         | DPS dan halaman ini SELALU menampilkan angka yang sama.
         | ---------------------------------------------------------------- */

        // Rincian agregat per akad (dipakai tabel 'Rincian Pendapatan per Akad').
        $pendapatanPerAkad = $pendapatanService->agregatPerAkad();

        // Ringkasan pendapatan — satu sumber kebenaran dengan dashboard DPS.
        $ringkasanPendapatan = $pendapatanService->ringkasan();

        // Pendapatan Margin: akad jual-beli (Murabahah, IMBT, Istishna, Salam).
        $pendapatanMargin = $ringkasanPendapatan['margin'];

        // Pendapatan Bagi Hasil: akad Mudharabah & Musyarakah.
        $pendapatanBagiHasil = $ringkasanPendapatan['bagi_hasil'];

        /* Pendapatan Ujroh / Fee: gabungan ujroh akad Ijarah dengan transaksi
         | berjenis biaya administrasi (akun 4-2001).
         |
         | PSAK 107: imbalan akad Ijarah (sewa/jasa) diakui sebagai ujroh, bukan
         | margin jual-beli, sehingga dipisahkan dari agregasi Margin.
         |
         | Ta'zir/denda SENGAJA dikecualikan: sesuai jurnalTazir(), dana ta'zir
         | masuk ke Dana Kebajikan (2-3001) dan BUKAN pendapatan koperasi. */
        $pendapatanUjroh = $ringkasanPendapatan['ujroh'];

        // Pengembalian pokok (bukan pendapatan, tapi penting untuk rekonsiliasi).
        $pengembalianPokok = $ringkasanPendapatan['pengembalian_pokok'];

        /* Dana kebajikan/ta'zir yang terkumpul — dipisahkan agar tidak dihitung
         | sebagai pendapatan koperasi.
         |
         | Sumber nilai: akumulasi mutasi KREDIT akun 2-3001 (satu-satunya
         | sumber kebenaran akuntansi), BUKAN tabel transaksi. Nilai ini identik
         | dengan yang ditampilkan box real-time pada halaman. */
        $danaKebajikan = (float) $danaKebajikanService->ringkasan()['totalTerkumpul'];

        // Total penghimpunan dana (setoran/simpanan masuk) — hanya transaksi sukses.
        $totalSetoran = (float) Transaksi::query()
            ->where('status_transaksi', 'Sukses')
            ->where(function ($q) {
                $q->where('jenis_transaksi', 'like', '%Setor%')
                    ->orWhere('jenis_transaksi', 'like', '%Simpanan%');
            })
            ->sum('jumlah');

        /* Total pembiayaan disalurkan — HANYA status sah (aktif, restrukturisasi,
         | lunas) agar sepadan dengan baris yang ditampilkan di tabel neraca di
         | bawahnya. 'ditolak'/'pengajuan'/'dicairkan' tanpa kontrak berjalan
         | tidak ikut dihitung. */
        $totalDisalurkan = (float) Pembiayaan::statusIn($statusNeraca)->sum('jumlah_pembiayaan');

        /* Distribusi bagi hasil per akad (Mudharabah, Musyarakah).
         |
         | `nisbah_pengelola` & `nisbah_pemilik` masih NULL pada data berjalan,
         | sehingga persentase diturunkan dari margin_nisbah (yang berisi % nisbah
         | bagi hasil) agar laporan tetap informatif; bila nisbah terisi, nilai
         | itulah yang dipakai. */
        $distribusiBagiHasil = Pembiayaan::selectRaw('akad_pembiayaan, SUM(jumlah_pembiayaan) as total')
            ->selectRaw('AVG(COALESCE(nisbah_pengelola, margin_nisbah)) as avg_nisbah_pengelola')
            ->selectRaw('AVG(COALESCE(nisbah_pemilik, 100 - margin_nisbah)) as avg_nisbah_pemilik')
            ->whereIn('akad_pembiayaan', ['Mudharabah', 'Musyarakah'])
            ->whereNotNull('akad_pembiayaan')
            ->groupBy('akad_pembiayaan')
            ->get();

        /* Pembiayaan per status untuk neraca.
         | Difilter ke status sah saja — 'ditolak'/'pengajuan' dikecualikan
         | sehingga sisa pokok & % tertagih tidak pernah dihitung dari kontrak
         | yang belum/tidak pernah cair. */
        $pembiayaanPerStatus = Pembiayaan::selectRaw('status_pembiayaan, COUNT(*) as jumlah, SUM(sisa_pembiayaan) as total_sisa, SUM(jumlah_pembiayaan) as total_plafon')
            ->statusIn($statusNeraca)
            ->groupBy('status_pembiayaan')
            ->get();

        return view('dps.laporan-keuangan', compact(
            'pendapatanMargin', 'pendapatanBagiHasil', 'pendapatanUjroh',
            'pengembalianPokok', 'danaKebajikan', 'pendapatanPerAkad',
            'totalSetoran', 'totalDisalurkan',
            'distribusiBagiHasil', 'pembiayaanPerStatus'
        ));
    }

    /**
     * Endpoint real-time box 'Dana Kebajikan (Ta'zir/Denda)' pada halaman
     * Laporan Keuangan Syariah (JSON).
     *
     * Nilai SELALU diambil dari akumulasi mutasi KREDIT akun 2-3001 sehingga
     * box ter-update tanpa memuat ulang seluruh halaman.
     */
    public function ringkasanKebajikanLaporanKeuangan(DanaKebajikanService $danaKebajikan)
    {
        $ringkasan = $danaKebajikan->ringkasan();

        return response()->json([
            'totalKebajikan' => $ringkasan['totalTerkumpul'],
            'jumlahMutasi' => $ringkasan['jumlahMutasi'],
            'diperbarui_pada' => now()->toIso8601String(),
        ]);
    }
}
