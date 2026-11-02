<?php

namespace App\Http\Controllers;

use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\LaporanKeuanganLog;
use App\Services\AutoJournalingService;
use App\Services\LaporanKeuanganSyariahService;
use App\Support\LaporanKeuanganPermission;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class DanaCadanganController extends Controller
{
    public function __construct(private AutoJournalingService $journaling) {}

    /** Halaman utama alokasi SHU — hanya Pengurus */
    public function index()
    {
        $coaCadangan = Coa::findByKode(\App\Support\Coa::CADANGAN_KOPERASI);
        $coaShu = Coa::findByKode(\App\Support\Coa::IKHTISAR_SHU);

        /* Saldo Dana Cadangan (3-2001) — Ekuitas/Modal berposisi KREDIT,
         | sehingga saldo = Total Kredit − Total Debet. Tidak pernah ditampilkan
         | minus; bila tertekan ke arah Debet, penyebabnya dilaporkan terpisah. */
        $cadanganTampil = $coaCadangan ? $coaCadangan->saldoBerjalanTampil() : null;

        /* Saldo Ikhtisar SHU (3-9000) — juga Ekuitas/Modal berposisi KREDIT.
         |
         | VALIDASI PROFIT/LOSS: nilai mentah akun ini bisa negatif (mis. ada
         | jurnal distribusi SHU yang mendebet 3-9000 tanpa jurnal penutup laba
         | yang mengkredit). Nilai negatif pada akun Ekuitas saat koperasi UNTUNG
         | adalah anomali jurnal — bukan posisi keuangan yang wajar — sehingga
         | TIDAK boleh ditampilkan sebagai angka minus di card. Yang ditampilkan
         | adalah 0, disertai penanda `akun_bermasalah` agar penyebabnya tetap
         | dapat ditelusuri oleh pengurus/auditor. */
        $shuTampil = $coaShu ? $coaShu->saldoBerjalanTampil() : null;

        /* STATUS KEUANGAN berjalan (Pendapatan − Beban) dipakai untuk menilai
         | apakah kondisi koperasi sedang untung, rugi, atau impas. */
        $statusKeuangan = Coa::statusKeuangan();
        $labaBersih = Coa::labaBersihBerjalan();

        $saldoCadangan = $cadanganTampil['nilai'] ?? 0;
        $saldoShu = $shuTampil['nilai'] ?? 0;

        /* Peringatan yang menjelaskan bahwa angka 0 di card berasal dari
         | penjagaan tampilan, bukan dari saldo yang benar-benar nol — supaya
         | pengguna tidak salah menyimpulkan bahwa SHU memang nihil. */
        $catatanShu = null;
        $kelebihanDebetShu = 0.0;
        if ($shuTampil && $shuTampil['akun_bermasalah']) {
            $kelebihanDebetShu = $coaShu->kelebihanMutasiBerlawanan();
            $catatanShu = 'Akun 3-9000 tertekan ke posisi Debet sebesar Rp '
                .number_format($kelebihanDebetShu, 0, ',', '.')
                .' — nilai tidak ditampilkan minus. Jalankan Tutup Buku atau '
                .'periksa jurnal distribusi SHU yang mendebet akun ini tanpa '
                .'diimbangi jurnal penutup laba.';
        }

        // Riwayat jurnal alokasi SHU (sumber = 'Alokasi SHU ke Cadangan')
        $riwayat = Jurnal::where('sumber', 'Alokasi SHU ke Cadangan')
            ->orderByDesc('tanggal_jurnal')
            ->orderByDesc('id')
            ->paginate(10);

        return view('pengurus.cadangan', compact(
            'saldoCadangan',
            'saldoShu',
            'riwayat',
            'statusKeuangan',
            'labaBersih',
            'catatanShu',
            'kelebihanDebetShu',
            'shuTampil',
            'cadanganTampil'
        ));
    }

    /** Proses alokasi SHU — hanya Pengurus */
    public function alokasiShu(Request $request)
    {
        $request->validate([
            'nominal' => 'required|numeric|min:1',
            'keterangan' => 'required|string|max:255',
        ]);

        $nominal = (float) $request->nominal;
        $keterangan = $request->keterangan;

        /* VALIDASI PROFIT/LOSS — pertahanan sisi server.
         |
         | Tombol "Posting Jurnal" sudah dinonaktifkan di antarmuka saat posisi
         | keuangan berjalan merugi, namun penonaktifan tombol saja tidak cukup:
         | request POST masih bisa dikirim langsung (mis. lewat alat bantu HTTP).
         | Karena itu kondisi yang sama WAJIB diperiksa ulang di backend.
         |
         | Alokasi SHU ke Dana Cadangan adalah pembagian hasil usaha; secara
         | akuntansi tidak sah bila koperasi sedang merugi, karena yang dibagi
         | bukan laba melainkan menambah beban ekuitas. */
        if (Coa::labaBersihBerjalan() < 0) {
            return back()->withErrors([
                'nominal' => 'Alokasi Dana Cadangan tidak dapat dilakukan karena posisi keuangan berjalan sedang mengalami kerugian.',
            ])->withInput();
        }

        // Cek saldo SHU mencukupi
        $coaShu = Coa::findByKode(\App\Support\Coa::IKHTISAR_SHU);
        $coaCadangan = Coa::findByKode(\App\Support\Coa::CADANGAN_KOPERASI);
        $saldoCadanganSebelum = $coaCadangan ? (float) $coaCadangan->saldoBerjalan() : 0.0;

        /* Cek saldo SHU mencukupi — memakai saldo MENTAH (bukan nilai tampilan),
         | karena keputusan bisnis "SHU cukup atau tidak" harus berdasar angka
         | akuntansi sebenarnya. Memakai nilai tampilan yang sudah dijaga di 0
         | akan meloloskan alokasi yang sebenarnya tidak tertutup saldo.
         |
         | Catatan: alokasi ini mendebet 3-9000 (akun Ekuitas berposisi Kredit),
         | sehingga justru MENGURANGI ekuitas. Karena itu akun 3-9000 wajib
         | bersaldo positif dari jurnal penutup laba sebelum alokasi dijalankan. */
        $saldoShuMentah = $coaShu ? $coaShu->saldoBerjalan() : 0.0;

        if (! $coaShu || $saldoShuMentah < $nominal) {
            return back()->withErrors(['nominal' => 'Saldo Ikhtisar SHU tidak mencukupi untuk alokasi ini.'])->withInput();
        }

        $jurnal = $this->journaling->jurnalAlokasiShu($nominal, $keterangan, auth()->id());

        if (! $jurnal) {
            return back()->withErrors(['nominal' => 'Gagal memposting jurnal. Pastikan akun 3-9000 dan 3-2001 aktif di CoA.'])->withInput();
        }

        // Catat perubahan data yang dihasilkan operasi ini. Middleware RBAC
        // mencatat peristiwanya; di sini yang dicatat adalah dampaknya —
        // supaya auditor bisa menilai besar dan arah perubahannya tanpa
        // menelusuri jurnal secara manual.
        //
        // Modul yang dipakai adalah MODUL_PENYESUAIAN, bukan MODUL_NERACA:
        // wewenang MENULIS laporan keuangan sengaja dikeluarkan dari modul
        // laporan (yang kini hanya-baca untuk semua peran) ke modul penyesuaian
        // jurnal. Menyimpannya sebagai modul laporan akan membuat jejak audit
        // menyatakan adanya "edit laporan neraca" oleh peran yang secara
        // matriks tidak mungkin melakukannya — jejak yang menyesatkan auditor.
        $saldoCadanganSesudah = $coaCadangan ? (float) Coa::findByKode(\App\Support\Coa::CADANGAN_KOPERASI)?->saldoBerjalan() : 0.0;

        LaporanKeuanganLog::catat([
            'user_id' => auth()->id(),
            'user_nama' => auth()->user()?->name,
            'role' => auth()->user()?->role,
            'modul' => LaporanKeuanganPermission::MODUL_PENYESUAIAN,
            'aktivitas' => LaporanKeuanganPermission::EDIT,
            'deskripsi' => 'Alokasi SHU ke Dana Cadangan Rp '.number_format($nominal, 0, ',', '.')
                           .' (Jurnal: '.$jurnal->no_jurnal.')',
            'route' => $request->route()?->getName(),
            'metode' => $request->method(),
            'url' => mb_substr($request->fullUrl(), 0, 500),
            'parameter' => ['nominal' => $nominal, 'keterangan' => $keterangan],
            'perubahan_data' => [
                'akun_debit' => ['kode' => \App\Support\Coa::IKHTISAR_SHU, 'nama' => 'Ikhtisar SHU',        'nominal' => $nominal],
                'akun_kredit' => ['kode' => \App\Support\Coa::CADANGAN_KOPERASI, 'nama' => 'Dana Cadangan',       'nominal' => $nominal],
                'no_jurnal' => $jurnal->no_jurnal,
                'saldo_cadangan_sebelum' => $saldoCadanganSebelum,
                'saldo_cadangan_sesudah' => $saldoCadanganSesudah,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'status_http' => 302,
            'berhasil' => true,
        ]);

        return redirect()->route('pengurus.cadangan.index')
            ->with('success', 'Alokasi SHU Rp '.number_format($nominal, 0, ',', '.')." berhasil diposting (Jurnal: {$jurnal->no_jurnal}).");
    }

    /** Laporan Neraca — bisa diakses Manajer & DPS (read-only) */
    public function neraca(Request $request)
    {
        // Mode: 'tanggal' = per tanggal spesifik, 'akhir_bulan' = ditarik ke hari terakhir bulan.
        $mode = $request->input('mode') === 'akhir_bulan' ? 'akhir_bulan' : 'tanggal';
        $input = (string) $request->input('tanggal', now()->toDateString());

        try {
            $tanggal = Carbon::parse($input);
        } catch (\Throwable $e) {
            // Tanggal filter tidak dapat diurai → jatuh ke hari ini, tetapi
            // catat sebabnya agar input tak sah tidak senyap.
            Log::warning('DanaCadanganController: tanggal filter Neraca tidak dapat diurai, memakai hari ini.', [
                'input' => $input,
                'error' => $e->getMessage(),
            ]);

            $tanggal = now();
        }

        $tanggalFilter = $mode === 'akhir_bulan'
            ? $tanggal->copy()->endOfMonth()
            : $tanggal->copy();

        /* Batas akumulasi saldo. Memakai `tanggal_jurnal` (tanggal kejadian),
         | bukan `created_at` (tanggal input): jurnal pembuka koperasi bertanggal
         | 2026-03-10 tetapi diinput 2026-09-10, sehingga filter created_at membuat
         | Neraca per 30 Juni 2026 menampilkan Aset Rp 0. */
        $sampai = $tanggalFilter->toDateString();

        $aset = Coa::where('kategori', 'Aset')->where('is_active', true)->orderBy('kode_akun')->get();
        $kewajiban = Coa::where('kategori', 'Kewajiban')->where('is_active', true)->orderBy('kode_akun')->get();
        $ekuitas = Coa::where('kategori', 'Ekuitas')->where('is_active', true)->orderBy('kode_akun')->get();
        $pendapatan = Coa::where('kategori', 'Pendapatan')->where('is_active', true)->orderBy('kode_akun')->get();
        $beban = Coa::where('kategori', 'Beban')->where('is_active', true)->orderBy('kode_akun')->get();

        /* Dana Syirkah Temporer (mis. Deposito Mudharabah) disajikan terpisah dari
         | Kewajiban sesuai PSAK 101/PSAK 105, namun tetap ber-kategori 'Kewajiban'
         | agar persamaan akuntansi Aset = Kewajiban + DST + Ekuitas + Laba tidak berubah. */
        $danaSyirkahTemporer = $kewajiban->where('klasifikasi_neraca', 'Dana Syirkah Temporer')->values();
        $kewajibanBersih = $kewajiban->where('klasifikasi_neraca', '!=', 'Dana Syirkah Temporer')->values();

        /* Seluruh nilai dijumlahkan dengan presisi sen (round 2 desimal) agar
         | penjumlahan floating point tidak menyisakan selisih palsu antara sisi
         | Aktiva dan Pasiva — mis. Rp 0,0000000001 yang terbaca sebagai
         | "selisih Rp 1" setelah dibulatkan untuk tampilan. */
        $totalAset = self::jumlahPresisi($aset, $sampai);
        $totalKewajiban = self::jumlahPresisi($kewajibanBersih, $sampai);
        $totalDanaSyirkahTemporer = self::jumlahPresisi($danaSyirkahTemporer, $sampai);

        /* Ekuitas dihitung TERMASUK nilai minus. Memakai rumus terbalik
         | (Total Debet − Total Kredit) untuk akun Kepala 3 akan menampilkan
         | akun 3-9000 yang terdebet sebagai nilai POSITIF, sehingga Total
         | Ekuitas tampak sehat padahal saldo Ikhtisar SHU-nya menggantung —
         | dan selisihnya menghilang tanpa jejak dari Neraca. saldoBerjalan()
         | memakai rumus sesuai posisi akun (Kredit − Debet untuk Kepala 3),
         | sehingga minus pada akun Ekuitas tetap terbaca dan dapat ditelusuri. */
        $totalEkuitas = self::jumlahPresisi($ekuitas, $sampai);

        // Laba bersih berjalan = Pendapatan - Beban (belum di-closing ke SHU)
        // Harus dimasukkan ke sisi Pasiva agar neraca balance: Aset = Kewajiban + Ekuitas + Laba Bersih
        $totalPendapatan = self::jumlahPresisi($pendapatan, $sampai);
        $totalBeban = self::jumlahPresisi($beban, $sampai);
        $labaBersih = Setting::bulatkanUang($totalPendapatan - $totalBeban);

        /* Total Pasiva = Kewajiban + DST + Ekuitas + Laba Berjalan.
         | Dihitung sekali di controller agar angka yang ditampilkan pada baris
         | rincian, badge status, dan peringatan keseimbangan selalu identik. */
        $totalPasiva = Setting::bulatkanUang(
            $totalKewajiban + $totalDanaSyirkahTemporer + $totalEkuitas + $labaBersih);

        // Selisih Aktiva vs Pasiva; selisih di bawah setengah sen dianggap nol.
        $selisihNeraca = Setting::bulatkanUang($totalAset - $totalPasiva);

        // Status laba/rugi berjalan untuk badge pada baris rincian Pasiva.
        $statusLaba = abs($labaBersih) < Setting::toleransiSelisihJurnal()
            ? 'impas'
            : ($labaBersih > 0 ? 'untung' : 'rugi');

        $data = [
            'asetGroups' => $this->kelompokkanKlasifikasi($aset, Coa::KLASIFIKASI['Aset'], $sampai),
            'kewajibanGroups' => $this->kelompokkanKlasifikasi($kewajibanBersih, ['Kewajiban Jangka Pendek', 'Kewajiban Jangka Panjang'], $sampai),
            'dstGroups' => $this->kelompokkanKlasifikasi($danaSyirkahTemporer, ['Dana Syirkah Temporer'], $sampai),
            'ekuitasGroups' => $this->kelompokkanKlasifikasi($ekuitas, Coa::KLASIFIKASI['Ekuitas'], $sampai),
            'pendapatanGroups' => $this->kelompokkanKlasifikasi($pendapatan, Coa::KLASIFIKASI['Pendapatan'], $sampai),
            'bebanGroups' => $this->kelompokkanKlasifikasi($beban, Coa::KLASIFIKASI['Beban'], $sampai),
            'totalAset' => $totalAset,
            'totalKewajiban' => $totalKewajiban,
            'totalDanaSyirkahTemporer' => $totalDanaSyirkahTemporer,
            'totalEkuitas' => $totalEkuitas,
            'labaBersih' => $labaBersih,
            'totalPendapatan' => $totalPendapatan,
            'totalBeban' => $totalBeban,
            'totalPasiva' => $totalPasiva,
            'selisihNeraca' => $selisihNeraca,
            'statusLaba' => $statusLaba,
            'mode' => $mode,
            'tanggalInput' => $tanggal->toDateString(),
            'tanggalFilter' => $tanggalFilter,
            'sampai' => $sampai,
        ];

        if ($request->input('export') === 'excel') {
            return response()->view('laporan.neraca-excel', $data, 200, [
                'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="Neraca-'.$sampai.'.xls"',
            ]);
        }

        return view('laporan.neraca', $data);
    }

    /**
     * Jumlahkan saldo berjalan sekumpulan akun dengan presisi sen.
     *
     * Penjumlahan float mentah dapat menyisakan pecahan sangat kecil
     * (mis. 0.0000000001) yang setelah dibulatkan untuk tampilan muncul
     * sebagai "selisih Rp 1" palsu antara sisi Aktiva dan Pasiva.
     *
     * TAHAP 5 — DELEGASI SATU SUMBER KEBENARAN: rumus ini identik dengan
     * LaporanKeuanganSyariahService::jumlahPresisi(). Dua salinan rumus
     * presisi adalah risiko nyata kedua laporan (Neraca halaman Pengurus
     * dan Neraca lima-unsur PSAK 101) menyimpang senyap di kemudian hari.
     * Karena itu rumusnya TIDAK lagi disalin di sini, melainkan dipanggil
     * dari service agar hanya ada satu tempat yang perlu diubah.
     */
    private static function jumlahPresisi($akun, string $sampai): float
    {
        return self::presisiService()->jumlahPresisi($akun, $sampai);
    }

    /**
     * Susun koleksi akun menjadi sub-kelompok klasifikasi penyajian standar.
     *
     * Urutan kelompok mengikuti $urutanKlasifikasi (taksonomi PSAK 101).
     * Akun yang belum dipetakan (klasifikasi_neraca null) tetap ditampilkan
     * pada kelompok "Belum Diklasifikasi" agar tidak hilang tanpa jejak.
     *
     * TAHAP 5 — DELEGASI SATU SUMBER KEBENARAN: sama seperti jumlahPresisi(),
     * pengelompokan & subtotal memakai LaporanKeuanganSyariahService::kelompokkan()
     * yang sudah bersifat public dan berperilaku identik (urutan taksonomi
     * diikuti, kelompok di luar taksonomi diletakkan di akhir). Salinan lokal
     * dihapus supaya aturan penyajian tidak dapat bercabang.
     *
     * @return array<int, array{label: string, akun: Collection, subtotal: float}>
     */
    private function kelompokkanKlasifikasi($akun, array $urutanKlasifikasi, string $sampai): array
    {
        return self::presisiService()->kelompokkan($akun, $urutanKlasifikasi, $sampai);
    }

    /** Service laporan sebagai pemegang tunggal rumus presisi & pengelompokan. */
    private static function presisiService(): LaporanKeuanganSyariahService
    {
        return app(LaporanKeuanganSyariahService::class);
    }

    /**
     * Buku Besar Akun — bisa diakses DPS, Manajer & Pengurus (read-only).
     *
     * Judul halaman (`judulHalaman`) dan teks setiap opsi dropdown dibangun dari
     * SATU method yang sama (`labelAkunDropdown()`) supaya keduanya dijamin
     * identik karakter-per-karakter: "KODE — Nama Akun [Kategori]".
     * Label opsi terpilih juga dikirim terpisah sebagai `labelTerpilih`, sehingga
     * frontend dapat menyinkronkan judul dan kolom "Akun Beban (CoA)" memakai
     * daftar akun yang benar-benar tampil di dropdown (bukan daftar hardcode).
     */
    public function bukuBesar(Request $request)
    {
        $user = auth()->user();
        $dpsOnly = $user->isDPS();

        // Semua akun aktif untuk dropdown (DPS dibatasi ke Ekuitas & Kewajiban).
        // Diurutkan per kode akun supaya urutan opsi = urutan keputusan akuntansi.
        $daftarAkun = Coa::where('is_active', true)
            ->when($dpsOnly, fn ($q) => $q->whereIn('kategori', ['Ekuitas', 'Kewajiban']))
            ->orderBy('kode_akun')
            ->get();

        // Akun default diambil dari SSOT CoA (DYN-11), bukan string literal.
        $kodeAkun = (string) $request->query('kode_akun', \App\Support\Coa::SIMPANAN_BERJANGKA);

        // Akun yang diminta harus benar-benar ada di daftar dropdown.
        $coa = $daftarAkun->firstWhere('kode_akun', $kodeAkun);

        if (! $coa) {
            /* Kode di luar daftar dropdown. Untuk DPS, meminta akun aktif di luar
             | wewenangnya (bukan Ekuitas/Kewajiban) tetap ditolak 403 — bukan
             | diam-diam dialihkan, agar batas wewenang tidak tersamarkan. */
            if ($dpsOnly && Coa::where('kode_akun', $kodeAkun)->where('is_active', true)->exists()) {
                abort(403, 'DPS hanya dapat mengakses Buku Besar akun Ekuitas dan Kewajiban.');
            }

            // Kode tidak dikenal / nonaktif: jatuh ke akun pertama agar halaman
            // tetap tampil dan dropdown tidak menunjuk opsi yang tidak ada.
            $coa = $daftarAkun->first();
        }

        $kodeAkun = $coa?->kode_akun ?? $kodeAkun;

        // Judul default saat tidak ada akun sama sekali pada daftar.
        $judulHalaman = $coa ? $this->labelAkunDropdown($coa) : 'Pilih akun di bawah';
        $labelTerpilih = $judulHalaman;

        $details = $coa ? JurnalDetail::with(['jurnal', 'coa'])
            ->where('coa_id', $coa->id)
            ->whereHas('jurnal', fn ($q) => $q->where('status', 'Posted'))
            /* Urut berdasarkan TANGGAL JURNAL, bukan created_at.
             | Saldo kumulatif memakai batas tanggal_jurnal (lihat
             | Coa::saldoBerjalan()), jadi urutan baris wajib sejalan dengan
             | batas itu. Memakai created_at membuat jurnal bertanggal mundur
             | tampil di urutan yang salah dan saldo kumulatifnya melompat. */
            ->orderBy(
                Jurnal::query()->select('tanggal_jurnal')
                    ->whereColumn('jurnal.id', 'jurnal_detail.jurnal_id')
                    ->limit(1)
            )
            ->orderBy('jurnal_detail.id')
            ->paginate(20) : collect();

        return view('laporan.buku-besar', compact(
            'coa',
            'details',
            'kodeAkun',
            'daftarAkun',
            'judulHalaman',
            'labelTerpilih'
        ));
    }

    /**
     * Satu-satunya sumber teks judul/opsi akun, dibentuk dinamis dari CoA
     * terpilih, mis. "3-1002 — Simpanan Wajib Anggota [Ekuitas]".
     * Dipakai identik oleh judul halaman maupun setiap <option> dropdown.
     */
    private function labelAkunDropdown(Coa $akun): string
    {
        return trim($akun->kode_akun.' — '.$akun->nama_akun.' ['.$akun->kategori.']');
    }
}
