<?php

namespace App\Http\Controllers;

use App\Models\DokumenPengajuan;
use App\Models\MasterProdukPembiayaan;
use App\Models\Pembiayaan;
use App\Models\PengajuanPembiayaan;
use App\Support\PemisahanTugas;
use App\Support\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PengajuanPembiayaanController extends Controller
{
    // ===================== NASABAH =====================

    /** Form pengajuan + riwayat pengajuan milik nasabah */
    public function form()
    {
        $anggota = Auth::user()->anggota;
        if (! $anggota) {
            return redirect()->route('dashboard.nasabah')->with('error', 'Data anggota tidak ditemukan.');
        }

        $produk = MasterProdukPembiayaan::where('status_aktif', true)->get();
        $riwayat = PengajuanPembiayaan::with('produk')
            ->where('anggota_id', $anggota->id)
            ->latest()
            ->paginate(10);

        return view('nasabah.pengajuan-pinjaman', compact('anggota', 'produk', 'riwayat'));
    }

    /** Simpan pengajuan baru dari nasabah */
    public function store(Request $request)
    {
        $anggota = Auth::user()->anggota;
        if (! $anggota) {
            return back()->with('error', 'Data anggota tidak ditemukan.');
        }

        $validated = $request->validate([
            'produk_id' => 'required|exists:master_produk_pembiayaan,id',
            'jumlah_pengajuan' => 'required|numeric|min:'.Setting::minimalPengajuanPembiayaan(),
            'tenor' => 'required|integer|min:1|max:360',
            'tujuan' => 'required|string|max:255',
            'agunan' => 'nullable|string|max:255',
        ]);

        $produk = MasterProdukPembiayaan::findOrFail($validated['produk_id']);

        // Validasi plafon
        if ($validated['jumlah_pengajuan'] < $produk->min_plafon) {
            return back()->withInput()->with('error', 'Jumlah pengajuan kurang dari minimum plafon Rp '.number_format($produk->min_plafon, 0, ',', '.'));
        }
        if ($produk->max_plafon && $validated['jumlah_pengajuan'] > $produk->max_plafon) {
            return back()->withInput()->with('error', 'Jumlah pengajuan melebihi maksimum plafon Rp '.number_format($produk->max_plafon, 0, ',', '.'));
        }

        // Cek pengajuan aktif yang belum selesai
        $pengajuanAktif = PengajuanPembiayaan::where('anggota_id', $anggota->id)
            ->menunggu()
            ->exists();
        if ($pengajuanAktif) {
            return back()->withInput()->with('error', 'Anda masih memiliki pengajuan yang sedang diproses. Tunggu hingga selesai sebelum mengajukan baru.');
        }

        // R-04: nomor dari MAX sufiks numerik (bukan count()+1) agar tidak ada
        // tabrakan saat pengajuan dihapus atau diajukan bersamaan.
        $noPengajuan = PengajuanPembiayaan::nomorPengajuanBaru('PJN-'.date('Ymd').'-');

        $marginPersen = (float) ($produk->margin_persen ?? Setting::marginDefaultPersen());
        $plafon = (float) $validated['jumlah_pengajuan'];
        $tenor = (int) $validated['tenor'];
        $totalMargin = $plafon * ($marginPersen / 100);
        $totalPembiayaan = $plafon + $totalMargin;
        $angsuranPerBulan = $tenor > 0 ? ($totalPembiayaan / $tenor) : $totalPembiayaan;

        PengajuanPembiayaan::create([
            'no_pengajuan' => $noPengajuan,
            'anggota_id' => $anggota->id,
            'produk_id' => $validated['produk_id'],
            'jenis_akad' => $produk->akad_syariah,
            'margin_persen' => $marginPersen,
            'jumlah_pengajuan' => $plafon,
            'total_pembiayaan' => $totalPembiayaan,
            'angsuran_per_bulan' => $angsuranPerBulan,
            'tenor' => $tenor,
            'tujuan' => $validated['tujuan'],
            'agunan' => $validated['agunan'],
            'status' => PengajuanPembiayaan::STATUS_MENUNGGU,
        ]);

        return back()->with('success', 'Pengajuan pinjaman berhasil dikirim! No. Pengajuan: '.$noPengajuan.'. Silakan tunggu konfirmasi dari petugas.');
    }

    /** Halaman form baru multi-step (berbeda dari form() yang lama) */
    public function formBaru()
    {
        $anggota = Auth::user()->anggota;
        if (! $anggota) {
            return redirect()->route('dashboard.nasabah')->with('error', 'Data anggota tidak ditemukan.');
        }
        $produk = MasterProdukPembiayaan::where('status_aktif', true)->get();

        return view('nasabah.pengajuan-pinjaman-baru', compact('anggota', 'produk'));
    }

    /** Simpan pengajuan dari form multi-step dengan dokumen */
    public function storeBaru(Request $request)
    {
        $anggota = Auth::user()->anggota;
        if (! $anggota) {
            return back()->with('error', 'Data anggota tidak ditemukan.');
        }

        $validated = $request->validate([
            'produk_id' => 'required|exists:master_produk_pembiayaan,id',
            'jumlah_pengajuan' => 'required|numeric|min:'.Setting::minimalPengajuanPembiayaan(),
            'tenor' => 'required|integer|min:1|max:360',
            'tujuan' => 'required|string|max:255',
            'penghasilan_bulanan' => 'required|numeric|min:0',
            'pengeluaran_bulanan' => 'required|numeric|min:0',
            'jumlah_tanggungan' => 'required|integer|min:0|max:20',
            'agunan_tipe' => 'nullable|string|max:100',
            'agunan_deskripsi' => 'nullable|string|max:500',
            'dokumen_ktp' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'dokumen_kk' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'dokumen_penghasilan' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'dokumen_agunan' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        // Cek pengajuan aktif
        $pengajuanAktif = PengajuanPembiayaan::where('anggota_id', $anggota->id)
            ->whereIn('status', ['Menunggu', 'Diproses'])
            ->exists();
        if ($pengajuanAktif) {
            return back()->withInput()->with('error', 'Masih ada pengajuan yang sedang diproses.');
        }

        $produk = MasterProdukPembiayaan::findOrFail($validated['produk_id']);

        // Rentang plafon produk juga mengikat di wizard ini; tanpa pemeriksaan
        // ini pengajuan bisa lolos di bawah min_plafon / di atas max_plafon.
        $plafonDiminta = (float) $validated['jumlah_pengajuan'];
        if ((float) $produk->min_plafon > 0 && $plafonDiminta < (float) $produk->min_plafon) {
            return back()->withInput()->with('error', 'Jumlah pengajuan kurang dari minimum plafon Rp '.number_format((float) $produk->min_plafon, 0, ',', '.'));
        }
        if ($produk->max_plafon && $plafonDiminta > (float) $produk->max_plafon) {
            return back()->withInput()->with('error', 'Jumlah pengajuan melebihi maksimum plafon Rp '.number_format((float) $produk->max_plafon, 0, ',', '.'));
        }

        $noPengajuan = PengajuanPembiayaan::nomorPengajuanBaru('PJN-'.date('Ymd').'-');

        $marginPersen = (float) ($produk->margin_persen ?? Setting::marginDefaultPersen());
        $plafon = (float) $validated['jumlah_pengajuan'];
        $tenor = (int) $validated['tenor'];
        $totalMargin = $plafon * ($marginPersen / 100);
        $totalPembiayaan = $plafon + $totalMargin;
        $angsuranPerBulan = $tenor > 0 ? ($totalPembiayaan / $tenor) : $totalPembiayaan;

        DB::transaction(function () use ($request, $validated, $anggota, $produk, $noPengajuan, $marginPersen, $plafon, $totalPembiayaan, $angsuranPerBulan) {
            $agunanInfo = null;
            if ($validated['agunan_tipe'] ?? null) {
                $agunanInfo = [
                    'tipe' => $validated['agunan_tipe'],
                    'deskripsi' => $validated['agunan_deskripsi'] ?? '',
                ];
            }

            $pengajuan = PengajuanPembiayaan::create([
                'no_pengajuan' => $noPengajuan,
                'anggota_id' => $anggota->id,
                'produk_id' => $validated['produk_id'],
                'jenis_akad' => $produk->akad_syariah,
                'margin_persen' => $marginPersen,
                'jumlah_pengajuan' => $plafon,
                'total_pembiayaan' => $totalPembiayaan,
                'angsuran_per_bulan' => $angsuranPerBulan,
                'tenor' => $tenor,
                'tujuan' => $validated['tujuan'],
                'agunan' => $validated['agunan_tipe'] ?? null,
                'penghasilan_bulanan' => $validated['penghasilan_bulanan'],
                'pengeluaran_bulanan' => $validated['pengeluaran_bulanan'],
                'jumlah_tanggungan' => $validated['jumlah_tanggungan'],
                'agunan_info' => $agunanInfo,
                'status' => PengajuanPembiayaan::STATUS_MENUNGGU,
            ]);

            // Upload dokumen
            $dokumenMap = [
                'dokumen_ktp' => 'KTP',
                'dokumen_kk' => 'KK',
                'dokumen_penghasilan' => 'SLIP_GAJI',
                'dokumen_agunan' => 'FOTO_AGUNAN',
            ];

            foreach ($dokumenMap as $fieldName => $jenis) {
                if ($request->hasFile($fieldName)) {
                    $file = $request->file($fieldName);
                    $filename = $jenis.'_'.$noPengajuan.'_'.time().'.'.$file->getClientOriginalExtension();
                    $path = $file->storeAs('dokumen-pengajuan/'.$pengajuan->id, $filename, 'dokumen');

                    DokumenPengajuan::create([
                        'pengajuan_id' => $pengajuan->id,
                        'jenis_dokumen' => $jenis,
                        'nama_file' => $file->getClientOriginalName(),
                        'path_file' => $path,
                        'ukuran_file' => (int) round($file->getSize() / 1024),
                    ]);
                }
            }
        });

        return redirect()->route('nasabah.status-pengajuan.index')
            ->with('success', 'Pengajuan berhasil dikirim! No. Pengajuan: '.$noPengajuan);
    }

    /** Halaman status/tracking pengajuan */
    public function statusPengajuan()
    {
        $anggota = Auth::user()->anggota;
        if (! $anggota) {
            return redirect()->route('dashboard.nasabah');
        }
        $pengajuan = PengajuanPembiayaan::with(['produk', 'dokumen'])
            ->where('anggota_id', $anggota->id)
            ->latest()
            ->paginate(10);

        return view('nasabah.status-pengajuan', compact('pengajuan'));
    }

    // ===================== PENGELOLA (TELLER / MANAJER) =====================

    /** Daftar semua pengajuan untuk pengelola — baca dari tabel pembiayaan */
    public function index(Request $request)
    {
        $status = $request->query('status', 'semua');

        // Pemetaan status tab UI ke status kanonik di DB
        $statusMap = [
            'Menunggu' => [Pembiayaan::STATUS_PENDING_AO, Pembiayaan::STATUS_PENDING_MANAJER, Pembiayaan::STATUS_PENDING_PENGURUS, Pembiayaan::STATUS_PENDING_DPS],
            'Diproses' => [Pembiayaan::STATUS_DIREVIEW],
            'Disetujui' => [Pembiayaan::STATUS_AKTIF, Pembiayaan::STATUS_DISETUJUI, Pembiayaan::STATUS_DICAIRKAN],
            'Ditolak' => [Pembiayaan::STATUS_DITOLAK],
            'semua' => null,
        ];

        $query = Pembiayaan::with(['anggota', 'approvedBy', 'approvedFinalBy'])
            ->when($status !== 'semua' && isset($statusMap[$status]), function ($q) use ($status, $statusMap) {
                $q->statusIn($statusMap[$status]);
            })
            ->latest();

        $pengajuan = $query->paginate(15);

        $stats = [
            'menunggu' => Pembiayaan::statusIn($statusMap['Menunggu'])->count(),
            'diproses' => Pembiayaan::statusIn($statusMap['Diproses'])->count(),
            'disetujui' => Pembiayaan::statusIn($statusMap['Disetujui'])->count(),
            'ditolak' => Pembiayaan::statusIn($statusMap['Ditolak'])->count(),
        ];

        return view('pengelola.pengajuan-pinjaman', compact('pengajuan', 'status', 'stats'));
    }

    /** Detail pengajuan */
    public function show(PengajuanPembiayaan $pengajuan)
    {
        $pengajuan->load(['anggota.user', 'produk', 'diprosesOleh', 'pembiayaan']);

        return view('pengelola.pengajuan-pinjaman-detail', compact('pengajuan'));
    }

    /** Manajer: review pengajuan dari tabel pembiayaan → status jadi 'Direview' */
    public function review(Request $request, Pembiayaan $pengajuan)
    {
        if (! $pengajuan->isStatus(Pembiayaan::STATUS_PENDING_AO)) {
            return back()->with('error', 'Pengajuan ini tidak dalam status Pengajuan.');
        }

        $validated = $request->validate([
            'catatan_manajer' => 'nullable|string|max:500',
        ]);

        $pengajuan->update([
            'status_pembiayaan' => Pembiayaan::STATUS_DIREVIEW,
            'direview_oleh' => Auth::id(),
            'tanggal_direview' => now(),
            'catatan_manajer' => $validated['catatan_manajer'] ?? null,
        ]);

        return redirect()->route('pengelola.pengajuan-pinjaman.index')
            ->with('success', 'Pengajuan telah direview dan diteruskan ke Pengurus untuk persetujuan final.');
    }

    /**
     * Pengurus: setujui pengajuan final.
     *
     * HANYA menyetujui. Pencairan dana (dan pembuatan jadwal angsuran) wajib
     * dilakukan terpisah oleh pengguna lain lewat menu Teller / aksi Cairkan,
     * agar:
     *   1. dana keluar TEPAT SEKALI dan tercatat dalam jurnal (mantan perilaku
     *      di sini langsung menandai AKTIF tanpa mutasi kas & tanpa jurnal,
     *      sehingga sisa_pembiayaan terisi padahal dana belum pernah cair);
     *   2. prinsip maker ≠ checker terpenuhi (penyetuju ≠ pencair).
     */
    public function approve(Request $request, Pembiayaan $pengajuan)
    {
        if (! $pengajuan->isStatus([Pembiayaan::STATUS_PENDING_AO, Pembiayaan::STATUS_DIREVIEW])) {
            return back()->with('error', 'Pengajuan ini sudah diproses sebelumnya.');
        }

        // Maker ≠ checker: pengaju dan AO pemrakarsa tidak boleh menjadi penyetuju.
        // Kolom created_by/ao_id ada di tabel pengajuan_pembiayaan, bukan di
        // pembiayaan — dibaca langsung agar tidak bergantung pada relasi opsional.
        $pengajuanAsal = DB::table('pengajuan_pembiayaan')
            ->where('pembiayaan_id', $pengajuan->id)
            ->select('ao_id', 'created_by')
            ->first();

        if (PemisahanTugas::dilanggarSalahSatu(
            [$pengajuanAsal->ao_id ?? null, $pengajuanAsal->created_by ?? null],
            Auth::id()
        )) {
            return back()->with('error', PemisahanTugas::PESAN);
        }

        $validated = $request->validate([
            'catatan_pengelola' => 'nullable|string|max:500',
        ]);

        $pengajuan->update([
            'status_pembiayaan' => Pembiayaan::STATUS_DISETUJUI,
            'user_approved' => Auth::id(),
            'disetujui_oleh' => Auth::id(),
            'tanggal_disetujui' => now(),
            'catatan_pengurus' => $validated['catatan_pengelola'] ?? null,
        ]);

        return redirect()->route('pengelola.pengajuan-pinjaman.index')
            ->with('success', 'Pengajuan disetujui. Pencairan dana dilakukan oleh pengguna lain melalui menu Pencairan.');
    }

    /** Tolak pengajuan */
    public function tolak(Request $request, Pembiayaan $pengajuan)
    {
        if (! $pengajuan->isStatus([Pembiayaan::STATUS_PENDING_AO, Pembiayaan::STATUS_DIREVIEW])) {
            return back()->with('error', 'Pengajuan ini sudah diproses sebelumnya.');
        }

        $validated = $request->validate([
            'catatan_pengelola' => 'required|string|max:500',
        ]);

        $pengajuan->update([
            'status_pembiayaan' => Pembiayaan::STATUS_DITOLAK,
            'keterangan' => $validated['catatan_pengelola'],
        ]);

        return redirect()->route('pengelola.pengajuan-pinjaman.index')
            ->with('info', 'Pengajuan telah ditolak.');
    }
}
