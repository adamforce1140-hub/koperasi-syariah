<?php

namespace App\Http\Controllers;

use App\Jobs\TutupBukuShuJob;
use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Services\IkhtisarShuService;
use App\Support\QueueOperasional;
use App\Support\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Modul Chart of Accounts (CoA) — Role Pengurus (CRUD) & DPS (Read-Only).
 */
class CoaController extends Controller
{
    /* ================= PENGURUS: MASTER DATA COA (CRUD) ================= */

    public function index()
    {
        /*
         | Jumlah mutasi HARUS memakai basis yang sama dengan saldo berjalan:
         | hanya baris jurnal berstatus 'Posted' (lihat Coa::totalDebet/Kredit
         | dan saldoBerjalan()). Tanpa filter ini, entri Draft ikut terhitung
         | pada label "(N mutasi)" padahal tidak membentuk saldo — sehingga
         | angka mutasi dan saldo yang ditampilkan menjadi tidak konsisten.
         */
        $coas = Coa::query()
            ->withCount(['jurnalDetail as jurnal_detail_count' => fn ($q) => $q->whereHas(
                'jurnal',
                fn ($j) => $j->where('status', 'Posted')
            )])
            ->orderBy('kode_akun')
            ->get();

        // Group per kategori untuk tampilan Tree View
        $grouped = $coas->groupBy('kategori');

        /*
         | Total neraca saldo dikelompokkan menurut POSISI EFEKTIF akun
         | (posisiSaldo(), turunan Kepala kode akun) — BUKAN kolom mentah
         | `tipe_saldo_normal`.
         |
         | Alasannya: akun kontra seperti 1-2100 CKPN menyimpan label 'Kredit'
         | pada kolom itu, padahal posisinya tetap Debet (Kepala 1) sehingga
         | saldoBerjalan()-nya bernilai NEGATIF dan berfungsi mengurangi total
         | Aset. Bila dikelompokkan memakai kolom mentah, nilai negatif itu
         | jatuh ke kelompok Kredit dan membuat kedua total tidak seimbang.
         */
        $totalDebet = $coas->filter(fn (Coa $c) => $c->posisiSaldo() === 'Debet')
            ->sum(fn (Coa $c) => Setting::bulatkanUang($c->saldoBerjalan()));
        $totalKredit = $coas->filter(fn (Coa $c) => $c->posisiSaldo() === 'Kredit')
            ->sum(fn (Coa $c) => Setting::bulatkanUang($c->saldoBerjalan()));

        return view('pengurus.coa', compact('grouped', 'totalDebet', 'totalKredit'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->aturanValidasi(null));

        $this->pastikanKlasifikasiSesuaiKategori($validated['kategori'], $validated['klasifikasi_neraca']);

        $validated = $this->normalisasiPersentase($validated, $request);

        $validated['saldo_awal'] = $validated['saldo_awal'] ?? 0;
        $validated['is_active'] = $request->boolean('is_active', true);

        Coa::create($validated);

        return redirect()->route('pengurus.coa.index')->with('success', 'Akun CoA "'.$validated['nama_akun'].'" berhasil ditambahkan.');
    }

    public function update(Request $request, Coa $coa)
    {
        $validated = $request->validate($this->aturanValidasi($coa->id));

        /* Akun yang sudah dipakai pada jurnal Posted tidak boleh diganti nama:
         | nama akun adalah jejak audit laporan keuangan yang sudah terbit.
         | Tanpa guard ini, mengganti nama akun lama akan membuat buku besar,
         | neraca, dan laporan laba-rugi periode lalu "berubah sejarah".
         | Pola guard mengikuti `toggle()` di bawah (redirect + flash error). */
        if ($validated['nama_akun'] !== $coa->nama_akun && $this->sudahBermutasi($coa)) {
            return redirect()->route('pengurus.coa.index')
                ->with('error', 'Nama akun "'.$coa->kode_akun.' '.$coa->nama_akun.'" tidak dapat diubah '
                    .'karena akun ini sudah memiliki mutasi jurnal. Hanya nama akun yang belum pernah '
                    .'dipakai pada jurnal Posted yang boleh diganti.');
        }

        $this->pastikanKlasifikasiSesuaiKategori($validated['kategori'], $validated['klasifikasi_neraca']);

        $validated = $this->normalisasiPersentase($validated, $request);

        $coa->update($validated);

        return redirect()->route('pengurus.coa.index')->with('success', 'Akun CoA "'.$coa->nama_akun.'" berhasil diperbarui.');
    }

    /**
     * Aturan validasi akun CoA — termasuk parameter persentase dinamis
     * (PSAK 101/108) yang dapat diubah kapan saja lewat Master COA.
     */
    private function aturanValidasi(?int $abaikanId): array
    {
        return [
            'kode_akun' => 'required|string|max:20|unique:coas,kode_akun'.($abaikanId ? ','.$abaikanId : ''),
            'nama_akun' => 'required|string|max:150',
            'kategori' => 'required|in:Aset,Kewajiban,Ekuitas,Pendapatan,Beban',
            'klasifikasi_neraca' => 'required|string|max:50',
            'tipe_saldo_normal' => 'required|in:Debet,Kredit',
            'saldo_awal' => 'nullable|numeric|min:0',
            'has_percentage' => 'nullable|boolean',
            'percentage_type' => 'nullable|in:'.implode(',', Coa::PERCENTAGE_TYPE),
            'default_percentage_member' => 'nullable|numeric|min:0|max:100',
            'default_percentage_institution' => 'nullable|numeric|min:0|max:100',
        ];
    }

    /**
     * Normalisasi parameter persentase sebelum disimpan.
     *
     * `percentage_type` tetap disimpan meski `has_percentage` false, karena
     * tipe itu sendiri bermakna: akun 3-1001/3-1002 bertipe 'shu' dengan
     * has_percentage=false — imbalannya lewat SHU Tahunan, bukan nisbah
     * bulanan. Yang dinolkan hanyalah angka persentasenya agar tidak ada
     * nilai "hantu" yang terbaca modul transaksi.
     */
    private function normalisasiPersentase(array $validated, Request $request): array
    {
        $validated['has_percentage'] = $request->boolean('has_percentage');
        $validated['percentage_type'] = ($validated['percentage_type'] ?? null) ?: null;

        if (! $validated['has_percentage']) {
            $validated['default_percentage_member'] = 0;
            $validated['default_percentage_institution'] = 0;

            return $validated;
        }

        $validated['default_percentage_member'] = Setting::bulatkanUang((float) ($validated['default_percentage_member'] ?? 0));
        $validated['default_percentage_institution'] = Setting::bulatkanUang((float) ($validated['default_percentage_institution'] ?? 0));

        // Nisbah bagi hasil harus berjumlah 100% — margin & SHU tidak terikat
        // aturan ini (margin hanya angka tunggal untuk anggota).
        if ($validated['percentage_type'] === Coa::PERCENTAGE_NISBAH) {
            $total = $validated['default_percentage_member'] + $validated['default_percentage_institution'];

            if ($total > 0 && abs($total - 100.0) > Setting::toleransiSelisihJurnal()) {
                throw ValidationException::withMessages([
                    'default_percentage_member' => 'Total nisbah anggota + koperasi harus 100% (saat ini '
                        .number_format($total, 2, ',', '.').'%).',
                ]);
            }
        }

        return $validated;
    }

    /**
     * Pastikan klasifikasi penyajian termasuk taksonomi kategori akunnya.
     * Mencegah salah pengelompokan Neraca (mis. akun Aset diberi
     * klasifikasi "Kewajiban Jangka Pendek").
     *
     * @throws ValidationException
     */
    private function pastikanKlasifikasiSesuaiKategori(string $kategori, string $klasifikasi): void
    {
        $diizinkan = Coa::klasifikasiUntuk($kategori);

        if (! in_array($klasifikasi, $diizinkan, true)) {
            throw ValidationException::withMessages([
                'klasifikasi_neraca' => 'Klasifikasi "'.$klasifikasi.'" tidak sesuai untuk kategori '.$kategori
                    .'. Pilihan yang sah: '.(implode(', ', $diizinkan) ?: '-').'.',
            ]);
        }
    }

    /**
     * Apakah akun sudah pernah dipakai pada jurnal berstatus Posted?
     *
     * Dipakai sebagai guard pada `update()`: akun yang sudah bermutasi tidak
     * boleh diganti namanya agar laporan periode lalu tetap konsisten.
     */
    private function sudahBermutasi(Coa $coa): bool
    {
        return $coa->jurnalDetail()
            ->whereHas('jurnal', fn ($query) => $query->where('status', 'Posted'))
            ->exists();
    }

    /** Toggle aktif / nonaktif (soft disable, tidak hapus agar riwayat jurnal aman). */
    public function toggle(Coa $coa)
    {
        // Akun inti (terdaftar di App\Support\Coa) dipakai lintas service untuk
        // memposting jurnal. Menonaktifkannya membuat Coa::terdaftar() melempar
        // RuntimeException sehingga transaksi nyata gagal di tengah jalan.
        if ($coa->is_active && in_array($coa->kode_akun, \App\Support\Coa::semuaKode(), true)) {
            return redirect()->route('pengurus.coa.index')
                ->with('error', 'Akun "'.$coa->kode_akun.' '.$coa->nama_akun.'" adalah akun inti sistem dan tidak dapat dinonaktifkan.');
        }

        $coa->update(['is_active' => ! $coa->is_active]);

        $status = $coa->is_active ? 'diaktifkan' : 'dinonaktifkan';

        return redirect()->route('pengurus.coa.index')->with('success', 'Akun "'.$coa->nama_akun.'" berhasil '.$status.'.');
    }

    /**
     * Verifikasi Jurnal — VERIFIKASI GANDA atas seluruh saldo akun.
     *
     * Lapis 1: hitung ulang saldo setiap akun langsung dari agregat
     *          `jurnal_detail` berstatus Posted (query SQL mandiri), lalu
     *          bandingkan dengan hasil `Coa::saldoBerjalan()` (jalur model).
     * Lapis 2: susun neraca saldo dan uji keseimbangannya — Σ mutasi Debet =
     *          Σ mutasi Kredit, dan Σ saldo pos Debet = Σ saldo pos Kredit —
     *          serta tandai akun yang saldonya berlawanan arah posisinya.
     *
     * Posisi akun diturunkan dari Kepala kode (posisiSaldo()), BUKAN dari kolom
     * mentah `tipe_saldo_normal`, supaya akun kontra (1-2100 CKPN) diperlakukan
     * konsisten dengan laporan keuangan: saldonya negatif dan mengurangi Aset.
     */
    public function verifyJournals()
    {
        $toleransi = Setting::toleransiSelisihJurnal();

        // Agregat debet & kredit per akun, langsung dari jurnal_detail Posted.
        $mutasi = JurnalDetail::select(
            'coa_id',
            DB::raw('COALESCE(SUM(debet), 0) as total_debet'),
            DB::raw('COALESCE(SUM(kredit), 0) as total_kredit')
        )
            ->whereHas('jurnal', fn ($q) => $q->where('status', 'Posted'))
            ->whereNotNull('coa_id')
            ->groupBy('coa_id')
            ->get()
            ->keyBy('coa_id');

        $coas = Coa::orderBy('kode_akun')->get();

        $report = [];
        $anomali = [];
        $totalSaldoDebet = 0.0;
        $totalSaldoKredit = 0.0;
        $totalMutasiDebet = 0.0;
        $totalMutasiKredit = 0.0;

        foreach ($coas as $coa) {
            $m = $mutasi->get($coa->id);
            $d = Setting::bulatkanUang($m ? (float) $m->total_debet : 0.0);
            $k = Setting::bulatkanUang($m ? (float) $m->total_kredit : 0.0);

            $totalMutasiDebet += $d;
            $totalMutasiKredit += $k;

            // Posisi EFEKTIF dari Kepala kode akun.
            $posisi = $coa->posisiSaldo();

            // Lapis 1 — hitung ulang saldo dari agregat SQL.
            $saldo = Setting::bulatkanUang(
                $posisi === 'Debet'
                    ? (float) $coa->saldo_awal + $d - $k
                    : (float) $coa->saldo_awal + $k - $d);

            // Lapis 1 — bandingkan dengan jalur model (Coa::saldoBerjalan()).
            $saldoModel = Setting::bulatkanUang($coa->saldoBerjalan());
            $cocok = abs($saldo - $saldoModel) <= $toleransi;

            if ($posisi === 'Debet') {
                $totalSaldoDebet += $saldo;
            } else {
                $totalSaldoKredit += $saldo;
            }

            // Akun Ikhtisar SHU (3-9000) berposisi Kredit. Aturan bisnis untuk
            // saldo negatifnya bersumber TUNGGAL dari IkhtisarShuService: saldo
            // negatif berarti ada mutasi DEBET tanpa jurnal penutup lawan
            // ("pencatatan gantung") dan WAJIB ditandai anomali agar dapat
            // diperbaiki lewat IkhtisarShuService::bersihkan(). Emisi jurnal
            // GANTUNG ini justru yang menjadi cacat pencatatan — bukan hal yang
            // boleh disembunyikan. Konsisten dengan pratinjauBersihkan().
            $ikhtisarShuGantung = $coa->kode_akun === \App\Support\Coa::IKHTISAR_SHU
                && $saldo < -$toleransi;

            // Lapis 2 — akun non-kontra yang saldonya berlawanan arah posisinya.
            // $ikhtisarShuGantung selalu bernilai true tepat ketika saldo 3-9000
            // berlawanan arah, sehingga penyebutan eksplisitnya di sini bersifat
            // dokumentatif (memperjelas mengapa akun ini TIDAK dikecualikan).
            if ($saldo < -$toleransi && ! $coa->isKontra() && ! $ikhtisarShuGantung) {
                $anomali[] = 'Akun '.$coa->kode_akun.' ('.$coa->nama_akun.') berposisi '
                    .$posisi.' tetapi bersaldo Rp '.number_format($saldo, 2, ',', '.')
                    .' — periksa jurnalnya.';
            }

            if (! $cocok) {
                $anomali[] = 'Akun '.$coa->kode_akun.' ('.$coa->nama_akun.') tidak konsisten: '
                    .'hitung ulang Rp '.number_format($saldo, 2, ',', '.')
                    .' vs model Rp '.number_format($saldoModel, 2, ',', '.').'.';
            }

            $report[] = [
                'kode' => $coa->kode_akun,
                'nama' => $coa->nama_akun,
                'posisi' => $posisi,
                'kontra' => $coa->isKontra(),
                'debet' => $d,
                'kredit' => $k,
                'saldo' => $saldo,
                'cocok' => $cocok,
            ];
        }

        $totalSaldoDebet = Setting::bulatkanUang($totalSaldoDebet);
        $totalSaldoKredit = Setting::bulatkanUang($totalSaldoKredit);
        $totalMutasiDebet = Setting::bulatkanUang($totalMutasiDebet);
        $totalMutasiKredit = Setting::bulatkanUang($totalMutasiKredit);

        $selisihNeraca = Setting::bulatkanUang($totalSaldoDebet - $totalSaldoKredit);
        $selisihMutasi = Setting::bulatkanUang($totalMutasiDebet - $totalMutasiKredit);

        $neracaSeimbang = abs($selisihNeraca) <= $toleransi;
        $mutasiSeimbang = abs($selisihMutasi) <= $toleransi;

        if (! $mutasiSeimbang) {
            array_unshift($anomali, 'Total mutasi jurnal tidak seimbang: Debet Rp '
                .number_format($totalMutasiDebet, 2, ',', '.').' vs Kredit Rp '
                .number_format($totalMutasiKredit, 2, ',', '.')
                .' (selisih Rp '.number_format(abs($selisihMutasi), 2, ',', '.').').');
        }

        if (! $neracaSeimbang) {
            array_unshift($anomali, 'Neraca saldo tidak seimbang: pos Debet Rp '
                .number_format($totalSaldoDebet, 2, ',', '.').' vs pos Kredit Rp '
                .number_format($totalSaldoKredit, 2, ',', '.')
                .' (selisih Rp '.number_format(abs($selisihNeraca), 2, ',', '.').').');
        }

        $hasil = [
            'baris' => $report,
            'jumlah_akun' => count($report),
            'total_saldo_debet' => $totalSaldoDebet,
            'total_saldo_kredit' => $totalSaldoKredit,
            'selisih_neraca' => $selisihNeraca,
            'neraca_seimbang' => $neracaSeimbang,
            'total_mutasi_debet' => $totalMutasiDebet,
            'total_mutasi_kredit' => $totalMutasiKredit,
            'selisih_mutasi' => $selisihMutasi,
            'mutasi_seimbang' => $mutasiSeimbang,
            'anomali' => $anomali,
            'waktu' => now()->format('d/m/Y H:i:s'),
        ];

        $pesan = $neracaSeimbang && $mutasiSeimbang && $anomali === []
            ? 'Verifikasi ganda LULUS — neraca saldo seimbang (Debet Rp '
                .number_format($totalSaldoDebet, 2, ',', '.').' = Kredit Rp '
                .number_format($totalSaldoKredit, 2, ',', '.').'), tidak ada anomali.'
            : 'Verifikasi ganda menemukan '.count($anomali).' hal untuk ditindaklanjuti. '
                .'Total Debet: Rp '.number_format($totalSaldoDebet, 2, ',', '.')
                .' | Total Kredit: Rp '.number_format($totalSaldoKredit, 2, ',', '.');

        return redirect()->route('pengurus.coa.index')
            ->with('verify_result', $hasil)
            ->with($neracaSeimbang && $mutasiSeimbang ? 'success' : 'error', $pesan);
    }

    /**
     * Tutup Buku Akhir Tahun (Close Fiscal Year).
     *
     * Proses:
     * 1. Hitung selisih Pendapatan (4-xxxx) vs Beban (5-xxxx) → SHU/Laba Ditahan
     * 2. Buat Jurnal Penutup:
     *    - Debet semua akun Pendapatan (nol-kan)
     *    - Kredit semua akun Beban (nol-kan)
     *    - Selisihnya masuk ke akun 3-9000 (Ikhtisar SHU / Laba Ditahan)
     * 3. Reset saldo_awal akun Pendapatan & Beban ke 0
     */
    /**
     * Tutup Buku Akhir Tahun (Close Fiscal Year).
     *
     * Seluruh perhitungan & penulisan jurnal ditangani IkhtisarShuService agar
     * presisi (memakai saldo akun sesuai posisinya, bukan rumus terbalik) dan
     * idempoten (dijalankan dua kali tidak menggandakan SHU). Lihat
     * App\Services\IkhtisarShuService::tutupBuku().
     */
    public function closeFiscalYear(Request $request)
    {
        $request->validate([
            'tahun_buku' => 'required|integer|min:2000|max:2100',
        ]);

        $tahun = (int) $request->tahun_buku;

        try {
            /*
             | Tutup buku dijalankan lewat Job agar konsisten dengan operasi
             | berat lain dan dapat dipindah ke antrian bila FEATURE_QUEUE_ASYNC
             | diaktifkan. Saat saklar mati (default), job dieksekusi SINKRON
             | (dispatchNow) sehingga perilaku identik dengan sebelumnya — tanpa
             | menyentuh antrian. Lihat App\Support\QueueOperasional.
             */
            $job = new TutupBukuShuJob(null, $request->user()?->id);

            if (QueueOperasional::aktif()) {
                QueueOperasional::jalankan($job);

                return redirect()->route('pengurus.coa.index')
                    ->with('success', 'Tutup Buku Tahun '.$tahun.' sedang diproses di latar belakang. '
                        .'Silakan periksa kembali hasilnya beberapa saat lagi.');
            }

            $hasil = $job->jalankanSinkron();

            if ($hasil['sudah_ditutup']) {
                return redirect()->route('pengurus.coa.index')
                    ->with('error', 'Tutup Buku Tahun '.$tahun.' tidak dijalankan: tidak ada saldo Pendapatan/Beban '
                        .'yang perlu ditutup (semua akun sudah nol). Saldo akun 3-9000: Rp '
                        .number_format($hasil['saldo_akhir_ikhtisar'], 2, ',', '.'));
            }

            return redirect()->route('pengurus.coa.index')
                ->with('success', 'Tutup Buku Tahun '.$tahun.' berhasil. '
                    .ucfirst($hasil['status']).' Rp '.number_format(abs($hasil['selisih_shu']), 2, ',', '.')
                    .' dipindahkan ke akun 3-9000 melalui jurnal '.$hasil['jurnal']->no_jurnal
                    .' ('.$hasil['jumlah_baris'].' baris).');

        } catch (\Throwable $e) {
            // Kegagalan tutup buku dapat menghentikan alur akhir tahun —
            // WAJIB tercatat lengkap untuk investigasi, bukan hanya flash pesan.
            Log::error('CoaController: Tutup Buku gagal.', [
                'tahun' => $tahun,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->route('pengurus.coa.index')
                ->with('error', 'Gagal Tutup Buku: '.$e->getMessage());
        }
    }

    /* ================= IKTISAR SHU (3-9000): PREVIEW & PEMBERSIHAN ================= */

    /**
     * Pratinjau Pengaturan Awal SHU — memperlihatkan posisi akun 3-9000 dan
     * rencana jurnal penutup Tutup Buku, TANPA mengubah data apa pun.
     *
     * Dipakai tombol "Preview & Bersihkan 3-9000" pada halaman CoA sebelum
     * pembersihan dijalankan, sesuai prinsip tidak mengubah buku besar tanpa
     * terlebih dahulu memperlihatkan dampaknya.
     */
    public function previewIkhtisarShu(Request $request)
    {
        $service = app(IkhtisarShuService::class);
        $sampai = $service->tanggalBatas($request->input('sampai'));

        return redirect()->route('pengurus.coa.index')
            ->with('ikhtisar_preview', [
                'bersih' => $service->pratinjauBersihkan($sampai),
                'tutupbuku' => $service->pratinjauTutupBuku($sampai),
            ]);
    }

    /**
     * Eksekusi pembersihan pencatatan gantung akun 3-9000 (Ikhtisar SHU).
     *
     * Bila saldo 3-9000 negatif karena jurnal distribusi SHU yang mendebetnya
     * tanpa jurnal penutup lawan, dibuat jurnal penyesuaian yang seimbang
     * (Debet 3-2001 Dana Cadangan / Kredit 3-9000) sehingga saldo 3-9000
     * kembali Rp 0 dengan jejak audit yang dapat ditelusuri — bukan dengan
     * menghapus jurnal historis.
     */
    public function resetIkhtisarShu(Request $request)
    {
        try {
            $hasil = app(IkhtisarShuService::class)->bersihkan($request->input('sampai'));

            if ($hasil['jumlah'] <= 0) {
                return redirect()->route('pengurus.coa.index')
                    ->with('error', 'Tidak ada pembersihan yang diperlukan. '.$hasil['keterangan']);
            }

            return redirect()->route('pengurus.coa.index')
                ->with('success', 'Akun 3-9000 dibersihkan Rp '.number_format($hasil['jumlah'], 2, ',', '.')
                    .' melalui jurnal penyesuaian '.$hasil['jurnal']->no_jurnal.'. '.$hasil['keterangan']);

        } catch (\Throwable $e) {
            Log::error('CoaController: pembersihan akun 3-9000 (Ikhtisar SHU) gagal.', [
                'sampai' => $request->input('sampai'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->route('pengurus.coa.index')
                ->with('error', 'Gagal membersihkan akun 3-9000: '.$e->getMessage());
        }
    }

    /* ================= DPS: JURNAL UMUM (READ-ONLY) ================= */

    public function jurnalUmum(Request $request)
    {
        $query = Jurnal::with(['detail.coa', 'transaksi', 'pembiayaan', 'postedBy'])
            ->where('status', 'Posted')
            ->orderByDesc('tanggal_jurnal')
            ->orderByDesc('id');

        // Filter sumber jurnal
        if ($sumber = $request->input('sumber')) {
            $query->where('sumber', $sumber);
        }

        // Filter no_anggota / CIF — melalui jurnal_detail yang punya anggota_id
        if ($request->filled('no_anggota')) {
            $cari = $request->input('no_anggota');
            $query->whereHas('detail', function ($q) use ($cari) {
                $q->whereHas('anggota', function ($a) use ($cari) {
                    $a->where('no_anggota', 'like', "%{$cari}%")
                        ->orWhere('no_cif', 'like', "%{$cari}%")
                        ->orWhere('nama_lengkap', 'like', "%{$cari}%");
                });
            });
        }

        // Filter tanggal
        if ($request->filled('dari')) {
            $query->whereDate('tanggal_jurnal', '>=', $request->dari);
        }
        if ($request->filled('sampai')) {
            $query->whereDate('tanggal_jurnal', '<=', $request->sampai);
        }

        $jurnals = $query->paginate(20)->withQueryString();

        // Daftar sumber jurnal unik untuk filter
        $sumberList = Jurnal::query()->whereNotNull('sumber')
            ->distinct()->orderBy('sumber')->pluck('sumber');

        /* ===== Mutasi Dana Kebajikan (2-3001) — verifikasi kepatuhan DPS =====
         | Akun 2-3001 berkategori KEWAJIBAN (Passiva), posisi saldo normal
         | KREDIT, sehingga saldo berjalan tiap baris wajib dihitung:
         |     Saldo Baru = Saldo Sebelumnya + KREDIT − DEBET
         | Saldo akhir running balance inilah satu-satunya sumber nilai untuk
         | summary card, supaya angka di kartu tidak pernah berbeda dengan baris
         | terakhir tabel mutasi. */
        $danaKebajikan = Coa::findByKode(\App\Support\Coa::DANA_KEBAJIKAN);

        $mutasiDanaKebajikan = collect();
        $saldoDanaKebajikan = 0.0;
        $totalMutasiKredit = 0.0;
        $totalMutasiDebet = 0.0;

        if ($danaKebajikan) {
            /* Urutan kronologis: created_at ASC dengan tie-breaker id ASC.
             | Beberapa baris dapat berbagi created_at yang sama persis, sehingga
             | id wajib dipakai sebagai pemecah seri agar urutan mutasi — dan
             | karenanya nilai saldo berjalan — selalu deterministik (tidak
             | meloncat antar pemuatan halaman). */
            $mutasiDanaKebajikan = $danaKebajikan->jurnalDetail()
                ->with('jurnal.transaksi')
                ->whereHas('jurnal', fn ($q) => $q->where('status', 'Posted'))
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            // Baris pertama melanjutkan saldo awal akun (2-3001: 0,00).
            $saldoBerjalan = (float) $danaKebajikan->saldo_awal;

            foreach ($mutasiDanaKebajikan as $mutasi) {
                // Validasi nilai sebelum masuk kalkulasi: kolom decimal bisa saja
                // berisi string/null, dan nilai negatif tidak wajar untuk akun ini
                // (posisi Debet/Kredit sudah ditentukan oleh arah mutasi), sehingga
                // nilai negatif dinolkan agar tidak membalik arah saldo.
                $debet = $this->nilaiMutasiValid($mutasi->debet);
                $kredit = $this->nilaiMutasiValid($mutasi->kredit);

                $saldoBerjalan = Setting::bulatkanUang($saldoBerjalan + $kredit - $debet);

                $mutasi->debet_valid = $debet;
                $mutasi->kredit_valid = $kredit;
                $mutasi->saldo_berjalan = $saldoBerjalan;
                $mutasi->keterangan_tampil = $this->narasiMutasi($mutasi, $danaKebajikan);

                $totalMutasiKredit += $kredit;
                $totalMutasiDebet += $debet;
            }

            // Saldo card = saldo akhir running balance baris terakhir tabel mutasi.
            $saldoDanaKebajikan = $saldoBerjalan;
        }

        $jumlahMutasi = $mutasiDanaKebajikan->count();

        return view('dps.jurnal-umum', compact(
            'jurnals',
            'saldoDanaKebajikan',
            'mutasiDanaKebajikan',
            'totalMutasiKredit',
            'totalMutasiDebet',
            'jumlahMutasi',
            'sumberList'
        ));
    }

    /**
     * Validasi nilai mutasi sebelum dipakai dalam kalkulasi saldo berjalan.
     *
     * Menjaga agar kesalahan tipe data (null/string non-numerik) atau nilai
     * negatif yang tidak wajar tidak merusak akumulasi saldo.
     */
    private function nilaiMutasiValid(mixed $nilai): float
    {
        if (! is_numeric($nilai)) {
            return 0.0;
        }

        return max(0.0, (float) $nilai);
    }

    /**
     * Narasi/keterangan yang benar untuk satu baris mutasi.
     *
     * `jurnal_detail.keterangan` diisi otomatis oleh AutoJournalingService
     * dengan NAMA AKUN — bukan narasi transaksi — sehingga seluruh baris akun
     * 2-3001 tampil seragam dan tidak informatif. Prioritas narasi:
     *   1. keterangan baris detail, bila bukan sekadar nama/kode akun;
     *   2. keterangan jurnal (narasi transaksi sebenarnya, mis. "Ta'zir - Siti Aminah");
     *   3. keterangan transaksi sumber;
     *   4. nama akun sebagai fallback terakhir.
     */
    private function narasiMutasi(JurnalDetail $mutasi, Coa $akun): string
    {
        $placeholder = array_filter([
            mb_strtolower(trim((string) $akun->nama_akun)),
            mb_strtolower(trim((string) $akun->kode_akun)),
        ]);

        $kandidat = [
            $mutasi->keterangan,
            $mutasi->jurnal?->keterangan,
            $mutasi->jurnal?->transaksi?->keterangan,
        ];

        foreach ($kandidat as $teks) {
            $teks = trim((string) $teks);

            if ($teks === '' || in_array(mb_strtolower($teks), $placeholder, true)) {
                continue;
            }

            return $teks;
        }

        return (string) $akun->nama_akun;
    }
}
