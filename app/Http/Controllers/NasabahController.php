<?php

namespace App\Http\Controllers;

use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\Deposito;
use App\Models\DepositoProfitSharing;
use App\Models\MasterProdukPembiayaan;
use App\Models\Pembiayaan;
use App\Models\PengajuanPembiayaan;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Support\RentangPeriode;
use App\Support\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class NasabahController extends Controller
{
    /** Ambil data Anggota milik user yang login, abort 403 jika tidak ditemukan. */
    private function getAnggota(): Anggota
    {
        $anggota = Anggota::where('user_id', Auth::id())->first();
        abort_if(! $anggota, 403, 'Data anggota tidak ditemukan untuk akun ini.');

        return $anggota;
    }

    /* ===================== DASHBOARD ===================== */

    public function dashboard()
    {
        return view('nasabah.dashboard', $this->dataDashboard());
    }

    /**
     * Potongan HTML untuk mesin denyut (real-time tanpa reload penuh).
     * Kunci `html` dipetakan ke `data-denyut-sasaran` pada view.
     * Hanya menyentuh data milik anggota yang sedang login.
     */
    public function dashboardRingkas(): JsonResponse
    {
        $data = $this->dataDashboard();

        return response()->json([
            'html' => [
                'metrik' => view('nasabah.partials.dashboard-metrik', $data)->render(),
                'angsuran' => view('nasabah.partials.dashboard-angsuran', $data)->render(),
                'transaksi' => view('nasabah.partials.dashboard-transaksi', $data)->render(),
            ],
            'waktu' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store, must-revalidate');
    }

    /** Satu sumber data untuk dashboard() dan dashboardRingkas(). */
    private function dataDashboard(): array
    {
        $anggota = $this->getAnggota();

        $rekening = Rekening::where('anggota_id', $anggota->id)->aktif()->get();
        $totalSaldo = $rekening->sum('saldo');

        $pembiayaanAktif = Pembiayaan::where('anggota_id', $anggota->id)
            ->statusIn(Pembiayaan::statusTercair())
            ->with('angsuran')
            ->get();

        $sisaAngsuranTotal = $pembiayaanAktif->sum('sisa_pembiayaan');

        $angsuranMendatang = Angsuran::whereHas('pembiayaan', fn ($q) => $q->where('anggota_id', $anggota->id))
            ->whereIn('status_angsuran', ['Belum Jatuh Tempo', 'Jatuh Tempo', 'Terlambat'])
            ->orderBy('tanggal_jatuh_tempo')
            ->first();

        $transaksiTerbaru = Transaksi::where('anggota_id', $anggota->id)
            ->where('status_transaksi', 'Sukses')
            ->with('rekening')
            ->orderByDesc('waktu_transaksi')
            ->limit(5)
            ->get();

        // Rentang eksplisit (sargable) agar indeks waktu_transaksi terpakai.
        $totalTrxBulanIni = RentangPeriode::padaBulan(
            Transaksi::where('anggota_id', $anggota->id)->where('status_transaksi', 'Sukses'),
            'waktu_transaksi',
            (int) now()->month,
            (int) now()->year
        )->count();

        return compact(
            'anggota', 'rekening', 'totalSaldo',
            'pembiayaanAktif', 'sisaAngsuranTotal',
            'angsuranMendatang', 'transaksiTerbaru', 'totalTrxBulanIni'
        );
    }

    /* ===================== SIMPANAN ===================== */

    public function simpananIndex()
    {
        $anggota = $this->getAnggota();

        $rekening = Rekening::where('anggota_id', $anggota->id)
            ->with('akun')
            ->orderBy('jenis_rekening')
            ->get();

        // Kelompokkan per jenis
        $grouped = $rekening->groupBy('jenis_rekening');
        $totalSaldo = $rekening->sum('saldo');

        return view('nasabah.simpanan', compact('anggota', 'rekening', 'grouped', 'totalSaldo'));
    }

    /* ===================== MUTASI ===================== */

    public function mutasiIndex(Request $request)
    {
        $anggota = $this->getAnggota();

        $query = Transaksi::where('anggota_id', $anggota->id)
            ->whereIn('status_transaksi', ['Sukses', 'sukses', 'success', 'Success'])
            ->with('rekening');

        // Filter rekening
        if ($request->filled('rekening_id')) {
            $query->where('rekening_id', $request->rekening_id);
        }

        // Filter tanggal
        if ($request->filled('dari')) {
            $query->whereDate('waktu_transaksi', '>=', $request->dari);
        }
        if ($request->filled('sampai')) {
            $query->whereDate('waktu_transaksi', '<=', $request->sampai);
        }

        // Filter jenis
        if ($request->filled('posisi')) {
            $query->where('posisi', $request->posisi);
        }

        $transaksi = $query->orderByDesc('waktu_transaksi')->paginate(20)->withQueryString();
        $rekening = Rekening::where('anggota_id', $anggota->id)->aktif()->get();

        // Total debet & kredit pada filter aktif (tanpa pagination)
        $queryTotal = Transaksi::where('anggota_id', $anggota->id)->where('status_transaksi', 'Sukses');
        if ($request->filled('rekening_id')) {
            $queryTotal->where('rekening_id', $request->rekening_id);
        }
        if ($request->filled('dari')) {
            $queryTotal->whereDate('waktu_transaksi', '>=', $request->dari);
        }
        if ($request->filled('sampai')) {
            $queryTotal->whereDate('waktu_transaksi', '<=', $request->sampai);
        }

        // Total mutasi masuk/keluar pada filter aktif (tanpa pagination).
        // Konvensi posisi mutasi simpanan: Debet = uang masuk, Kredit = uang keluar
        // (konsisten dengan TellerController, TransactionLimitService, ShuCalculatorService).
        $totalMasuk = (clone $queryTotal)->where('posisi', 'Debet')->sum('jumlah');
        $totalKeluar = (clone $queryTotal)->where('posisi', 'Kredit')->sum('jumlah');

        return view('nasabah.mutasi', compact(
            'anggota', 'transaksi', 'rekening', 'totalMasuk', 'totalKeluar'
        ));
    }

    /* ===================== BUKU TABUNGAN (PASSBOOK) ===================== */

    /**
     * Buku tabungan satu rekening: saldo awal periode, mutasi kronologis,
     * dan saldo berjalan pada setiap baris.
     *
     * `transaksi.saldo_setelah` TIDAK dipakai sebagai sumber saldo berjalan
     * karena kolom itu nullable — transaksi lama/seed bernilai NULL sehingga
     * portal anggota menampilkan "Rp 0". Saldo diturunkan dari `rekening.saldo`
     * dikurangi net seluruh mutasi (algoritme yang sama dengan migrasi
     * `2026_10_28_000100_rapikan_saldo_setelah_transaksi`), lalu diakumulasi
     * secara kronologis. Baris terakhir karena itu berakhir tepat pada saldo
     * rekening, dan tidak ada baris yang tampil tanpa saldo.
     */
    public function bukuTabungan(Request $request, Rekening $rekening)
    {
        $anggota = $this->getAnggota();

        // Otorisasi kepemilikan: anggota hanya boleh membuka rekeningnya sendiri.
        abort_unless(
            (int) $rekening->anggota_id === (int) $anggota->id,
            403,
            'Anda tidak berhak mengakses buku tabungan rekening ini.'
        );

        $aturan = [
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date'],
        ];

        // Bandingkan hanya bila `dari` diisi — `after_or_equal:dari` dengan
        // field pembanding kosong akan selalu gagal validasi.
        if ($request->filled('dari')) {
            $aturan['sampai'][] = 'after_or_equal:dari';
        }

        $validated = $request->validate($aturan);

        $mutasi = Transaksi::where('rekening_id', $rekening->id)
            ->whereIn('status_transaksi', ['Sukses', 'sukses', 'success', 'Success'])
            ->orderBy('waktu_transaksi')
            ->orderBy('id')
            ->get(['id', 'waktu_transaksi', 'jenis_transaksi', 'catatan', 'posisi', 'jumlah']);

        // Konvensi kanonik: Debet = uang masuk (+), Kredit = uang keluar (−).
        $efek = fn (Transaksi $trx): float => $trx->isMasuk()
            ? (float) $trx->jumlah
            : -(float) $trx->jumlah;

        $saldo = round((float) $rekening->saldo - $mutasi->sum($efek), 2);

        $baris = $mutasi->map(function (Transaksi $trx) use (&$saldo, $efek) {
            $saldoAwalBaris = $saldo;
            $saldo = round($saldo + $efek($trx), 2);

            return [
                'waktu' => $trx->waktu_transaksi,
                'jenis' => $trx->jenis_transaksi,
                'catatan' => $trx->catatan,
                'masuk' => $trx->isMasuk(),
                'jumlah' => (float) $trx->jumlah,
                'saldo_awal' => $saldoAwalBaris,
                'saldo_akhir' => $saldo,
            ];
        })->all();

        $dari = filled($validated['dari'] ?? null)
            ? Carbon::parse($validated['dari'])->startOfDay()
            : null;
        $sampai = filled($validated['sampai'] ?? null)
            ? Carbon::parse($validated['sampai'])->endOfDay()
            : null;

        $tersaring = array_values(array_filter($baris, function (array $b) use ($dari, $sampai) {
            if ($dari && $b['waktu']->lt($dari)) {
                return false;
            }

            if ($sampai && $b['waktu']->gt($sampai)) {
                return false;
            }

            return true;
        }));

        $saldoAwal = $tersaring === [] ? $saldo : $tersaring[0]['saldo_awal'];
        $saldoAkhir = $tersaring === [] ? $saldo : $tersaring[count($tersaring) - 1]['saldo_akhir'];
        $totalMasuk = array_sum(array_map(fn (array $b) => $b['masuk'] ? $b['jumlah'] : 0.0, $tersaring));
        $totalKeluar = array_sum(array_map(fn (array $b) => $b['masuk'] ? 0.0 : $b['jumlah'], $tersaring));

        return view('nasabah.buku-tabungan', [
            'anggota' => $anggota,
            'rekening' => $rekening,
            'mutasi' => $tersaring,
            'saldoAwal' => $saldoAwal,
            'saldoAkhir' => $saldoAkhir,
            'totalMasuk' => $totalMasuk,
            'totalKeluar' => $totalKeluar,
        ]);
    }

    /* ===================== DEPOSITO / SIMPANAN BERJANGKA ===================== */

    /**
     * Daftar bilyet deposito (Mudharabah Muthlaqah) milik anggota beserta
     * nisbah bagi hasil yang disepakati pada masing-masing akad.
     */
    public function depositoIndex()
    {
        $anggota = $this->getAnggota();

        $deposito = Deposito::milik($anggota->id)
            ->with(['product', 'profitSharings'])
            ->orderByDesc('start_date')
            ->get();

        $totalPokok = $deposito->where('status', Deposito::STATUS_ACTIVE)->sum('principal_amount');
        $totalBagiHasil = $deposito->sum(fn (Deposito $d) => $d->akumulasiBagiHasil());

        return view('nasabah.deposito', compact('anggota', 'deposito', 'totalPokok', 'totalBagiHasil'));
    }

    /**
     * Rincian bagi hasil per periode untuk satu bilyet deposito.
     *
     * Menampilkan nisbah (anggota:koperasi) yang berlaku serta sumber
     * perhitungannya (pendapatan koperasi dari penempatan dana), sehingga
     * anggota dapat memverifikasi bahwa bagi hasil dihitung dari hasil usaha
     * riil — bukan bunga tetap yang dijanjikan di muka.
     */
    public function depositoBagiHasil(Deposito $deposito)
    {
        $anggota = $this->getAnggota();

        // Otorisasi kepemilikan: anggota hanya boleh melihat bilyet miliknya.
        abort_unless((int) $deposito->member_id === (int) $anggota->id, 403,
            'Anda tidak berhak mengakses bilyet deposito ini.');

        $deposito->load(['product', 'profitSharings' => fn ($q) => $q
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')]);

        $rincian = $deposito->profitSharings;

        $totalDibayar = $rincian->where('status', DepositoProfitSharing::STATUS_PAID)->sum('member_profit_amount');
        $totalHangus = $rincian->where('status', DepositoProfitSharing::STATUS_FORFEITED)->sum('member_profit_amount');
        $totalPendapatan = $rincian->whereIn('status', DepositoProfitSharing::STATUS_DIAKUI)->sum('gross_koperasi_revenue');

        return view('nasabah.deposito-bagi-hasil', compact(
            'anggota', 'deposito', 'rincian',
            'totalDibayar', 'totalHangus', 'totalPendapatan'
        ));
    }

    /* ===================== PENGAJUAN PEMBIAYAAN ===================== */

    public function pengajuanIndex()
    {
        $anggota = $this->getAnggota();

        $pengajuan = PengajuanPembiayaan::where('anggota_id', $anggota->id)
            ->with('produk')
            ->orderByDesc('created_at')
            ->paginate(10);

        return view('nasabah.pengajuan.index', compact('anggota', 'pengajuan'));
    }

    public function pengajuanCreate()
    {
        $anggota = $this->getAnggota();
        $produk = MasterProdukPembiayaan::orderBy('nama_produk')->get();

        return view('nasabah.pengajuan.create', compact('anggota', 'produk'));
    }

    public function pengajuanStore(Request $request)
    {
        $anggota = $this->getAnggota();

        $validated = $request->validate([
            'produk_id' => 'required|exists:master_produk_pembiayaan,id',
            'jenis_akad' => 'required|string|max:50',
            'jumlah_pengajuan' => 'required|numeric|min:'.Setting::minimalPengajuanPembiayaan(),
            'tenor' => 'required|integer|min:1|max:360',
            'tujuan' => 'required|string|max:500',
            'agunan' => 'nullable|string|max:500',
            'penghasilan_bulanan' => 'nullable|numeric|min:0',
            'pengeluaran_bulanan' => 'nullable|numeric|min:0',
            'jumlah_tanggungan' => 'nullable|integer|min:0',
        ]);

        $noPengajuan = 'PJN-'.date('Ymd').'-'.strtoupper(substr(uniqid(), -5));

        // Kolom turunan (margin, total, angsuran) WAJIB diisi agar pengajuan
        // dari kanal nasabah setara dengan pengajuan dari kanal AO/pengelola —
        // tanpa ini kolom tersebut tersimpan NULL dan tampil Rp 0 di layar
        // verifikasi.
        $produk = MasterProdukPembiayaan::find($validated['produk_id']);
        $galatPlafon = $this->galatPlafonProduk($produk, (float) $validated['jumlah_pengajuan']);
        if ($galatPlafon !== null) {
            return back()->withInput()->with('error', $galatPlafon);
        }

        $hitung = $this->hitungPengajuan(
            $produk,
            (float) $validated['jumlah_pengajuan'],
            (int) $validated['tenor']
        );

        PengajuanPembiayaan::create(array_merge($validated, $hitung, [
            'no_pengajuan' => $noPengajuan,
            'anggota_id' => $anggota->id,
            'status' => PengajuanPembiayaan::STATUS_MENUNGGU,
            'step_terakhir' => 1,
        ]));

        return redirect()->route('nasabah.pengajuan.index')
            ->with('success', "Pengajuan {$noPengajuan} berhasil diajukan. Tim kami akan segera memprosesnya.");
    }

    /* ===================== ANGSURAN ===================== */

    public function angsuranIndex(Request $request)
    {
        $anggota = $this->getAnggota();

        $pembiayaanList = Pembiayaan::where('anggota_id', $anggota->id)
            ->statusIn(array_merge(Pembiayaan::statusTercair(), [Pembiayaan::STATUS_LUNAS]))
            ->with([
                'produk', // hindari N+1 saat view menampilkan nama produk
                'angsuran' => fn ($q) => $q->orderBy('angsuran_ke'),
            ])
            ->orderByDesc('tanggal_cair')
            ->get();

        // Angsuran mendatang / jatuh tempo / terlambat / dibayar sebagian.
        // Memakai konstanta bersama agar angsuran "Dibayar Sebagian" tidak hilang.
        $angsuranTagihan = Angsuran::whereHas('pembiayaan', fn ($q) => $q->where('anggota_id', $anggota->id))
            ->whereIn('status_angsuran', Angsuran::STATUS_BELUM_LUNAS)
            ->with(['pembiayaan.produk'])
            ->orderBy('tanggal_jatuh_tempo')
            ->get();

        return view('nasabah.angsuran', compact('anggota', 'pembiayaanList', 'angsuranTagihan'));
    }

    /* ===================== PROFIL ===================== */

    public function profilIndex()
    {
        $anggota = $this->getAnggota();
        $anggota->load('user', 'rekening');

        return view('nasabah.profil', compact('anggota'));
    }

    public function profilUpdate(Request $request)
    {
        $anggota = $this->getAnggota();

        $validated = $request->validate([
            'no_telepon' => 'nullable|string|max:20',
            'no_hp' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:100',
            'alamat' => 'nullable|string|max:500',
            'pekerjaan' => 'nullable|string|max:100',
            'keterangan' => 'nullable|string|max:255',
        ]);

        $anggota->update($validated);

        if ($request->filled('email') && $request->email !== Auth::user()->email) {
            Auth::user()->update(['email' => $request->email]);
        }

        return redirect()->route('nasabah.profil.index')
            ->with('success', 'Profil berhasil diperbarui.');
    }

    /* ===================== REKENING ===================== */

    public function rekeningIndex()
    {
        $anggota = $this->getAnggota();
        $items = Rekening::where('anggota_id', $anggota->id)->get();

        return view('nasabah.rekening', compact('anggota', 'items'));
    }

    /* ===================== PEMBIAYAAN ===================== */

    public function pembiayaanIndex()
    {
        $anggota = $this->getAnggota();
        $items = Pembiayaan::where('anggota_id', $anggota->id)
            ->orderByDesc('created_at')
            ->get();

        return view('nasabah.pembiayaan', compact('anggota', 'items'));
    }

    /* ===================== PENGAJUAN PINJAMAN (wizard lama) ===================== */

    public function pengajuanPinjamanIndex()
    {
        $anggota = $this->getAnggota();
        $produk = MasterProdukPembiayaan::orderBy('nama_produk')->get();
        $riwayat = PengajuanPembiayaan::where('anggota_id', $anggota->id)
            ->with('produk')
            ->orderByDesc('created_at')
            ->paginate(10);

        return view('nasabah.pengajuan-pinjaman', compact('anggota', 'produk', 'riwayat'));
    }

    /* ===================== PENGAJUAN PINJAMAN BARU (wizard 3-step) ===================== */

    public function pengajuanPinjamanBaru()
    {
        $anggota = $this->getAnggota();
        $produk = MasterProdukPembiayaan::orderBy('nama_produk')->get();

        return view('nasabah.pengajuan-pinjaman-baru', compact('anggota', 'produk'));
    }

    public function pengajuanPinjamanStoreBaru(Request $request)
    {
        $anggota = $this->getAnggota();

        $validated = $request->validate([
            'produk_id' => 'required|exists:master_produk_pembiayaan,id',
            'jumlah_pengajuan' => 'required|numeric|min:'.Setting::minimalPengajuanPembiayaan(),
            'tenor' => 'required|integer|min:1|max:360',
            'tujuan' => 'required|string|max:500',
            'penghasilan_bulanan' => 'required|numeric|min:0',
            'pengeluaran_bulanan' => 'required|numeric|min:0',
            'jumlah_tanggungan' => 'required|integer|min:0',
            'agunan_tipe' => 'required|string|max:100',
            'agunan_deskripsi' => 'required|string|max:500',
            'dokumen_ktp' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'dokumen_kk' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'dokumen_penghasilan' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'dokumen_agunan' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        $noPengajuan = 'PJN-'.date('Ymd').'-'.strtoupper(substr(uniqid(), -5));
        $agunan = $validated['agunan_tipe'].' — '.$validated['agunan_deskripsi'];

        $produk = MasterProdukPembiayaan::find($validated['produk_id']);
        $jenis_akad = $produk?->akad_syariah ?? '-';

        $galatPlafon = $this->galatPlafonProduk($produk, (float) $validated['jumlah_pengajuan']);
        if ($galatPlafon !== null) {
            return back()->withInput()->with('error', $galatPlafon);
        }

        // Kolom turunan dihitung dari produk (sama seperti kanal AO/pengelola)
        // agar layar verifikasi tidak menampilkan margin/angsuran Rp 0.
        $hitung = $this->hitungPengajuan(
            $produk,
            (float) $validated['jumlah_pengajuan'],
            (int) $validated['tenor']
        );

        $pengajuan = PengajuanPembiayaan::create(array_merge($hitung, [
            'no_pengajuan' => $noPengajuan,
            'anggota_id' => $anggota->id,
            'produk_id' => $validated['produk_id'],
            'jenis_akad' => $jenis_akad,
            'jumlah_pengajuan' => $validated['jumlah_pengajuan'],
            'tenor' => $validated['tenor'],
            'tujuan' => $validated['tujuan'],
            'agunan' => $agunan,
            'penghasilan_bulanan' => $validated['penghasilan_bulanan'],
            'pengeluaran_bulanan' => $validated['pengeluaran_bulanan'],
            'jumlah_tanggungan' => $validated['jumlah_tanggungan'],
            'status' => PengajuanPembiayaan::STATUS_MENUNGGU,
            'step_terakhir' => 1,
        ]));

        // Simpan dokumen
        foreach (['dokumen_ktp', 'dokumen_kk', 'dokumen_penghasilan', 'dokumen_agunan'] as $field) {
            if ($request->hasFile($field)) {
                $berkas = $request->file($field);
                $path = $berkas->store('dokumen-pengajuan/'.$pengajuan->id, 'dokumen');
                // Nama kolom mengikuti skema `dokumen_pengajuan_pembiayaan`
                // (sama dengan kanal AO): nama_file/path_file/ukuran_file.
                // Sebelumnya ditulis nama_dokumen/path sehingga `nama_file`
                // (NOT NULL tanpa default) kosong dan unggahan gagal 500.
                $pengajuan->dokumen()->create([
                    'nama_file' => $berkas->getClientOriginalName(),
                    'jenis_dokumen' => str_replace('dokumen_', '', $field),
                    'path_file' => $path,
                    'ukuran_file' => $berkas->getSize(),
                    'diupload_oleh' => Auth::id(),
                ]);
            }
        }

        return redirect()->route('nasabah.status-pengajuan.index')
            ->with('success', "Pengajuan {$noPengajuan} berhasil diajukan. Tim kami akan segera memproses.");
    }

    /* ===================== STATUS PENGAJUAN ===================== */

    public function statusPengajuan()
    {
        $anggota = $this->getAnggota();
        $pengajuan = PengajuanPembiayaan::where('anggota_id', $anggota->id)
            ->with(['produk', 'dokumen'])
            ->orderByDesc('created_at')
            ->paginate(10);

        return view('nasabah.status-pengajuan', compact('anggota', 'pengajuan'));
    }

    /**
     * Hitung kolom turunan pengajuan (margin, total, angsuran) dari produk.
     *
     * Satu tempat perhitungan agar pengajuan dari kanal nasabah, AO, dan
     * pengelola menghasilkan angka identik. Produk tanpa `margin_persen`
     * memakai default kebijakan (Setting::marginDefaultPersen()).
     *
     * @return array{margin_persen: float, total_pembiayaan: float, angsuran_per_bulan: float}
     */
    private function hitungPengajuan(?MasterProdukPembiayaan $produk, float $plafon, int $tenor): array
    {
        $marginPersen = (float) ($produk?->margin_persen ?? Setting::marginDefaultPersen());
        $totalMargin = $plafon * ($marginPersen / 100);
        $total = $plafon + $totalMargin;

        return [
            'margin_persen' => $marginPersen,
            'total_pembiayaan' => $total,
            'angsuran_per_bulan' => $tenor > 0 ? ($total / $tenor) : $total,
        ];
    }

    /**
     * Pesan galat bila nominal pengajuan di luar rentang plafon produk.
     *
     * Rentang dibaca dari `master_produk_pembiayaan` (min_plafon/max_plafon)
     * agar setiap kanal pengajuan memakai batas yang sama dengan data produk.
     */
    private function galatPlafonProduk(?MasterProdukPembiayaan $produk, float $nominal): ?string
    {
        if ($produk === null) {
            return 'Produk pembiayaan tidak ditemukan.';
        }

        if ((float) $produk->min_plafon > 0 && $nominal < (float) $produk->min_plafon) {
            return 'Jumlah pengajuan kurang dari minimum plafon Rp '
                .number_format((float) $produk->min_plafon, 0, ',', '.')
                .' untuk produk '.$produk->nama_produk.'.';
        }

        if ($produk->max_plafon && $nominal > (float) $produk->max_plafon) {
            return 'Jumlah pengajuan melebihi maksimum plafon Rp '
                .number_format((float) $produk->max_plafon, 0, ',', '.')
                .' untuk produk '.$produk->nama_produk.'.';
        }

        return null;
    }
}
