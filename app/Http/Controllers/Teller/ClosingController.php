<?php

namespace App\Http\Controllers\Teller;

use App\Http\Controllers\Controller;
use App\Models\KasHarian;
use App\Models\TellerOpname;
use App\Services\AturanLintasHariService;
use App\Support\JenisTransaksi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Kas Opname & Closing Teller.
 *
 * Teller menghitung fisik uang per pecahan, sistem menghitung saldo menurut
 * catatan (Kas Awal + Cash In - Cash Out), lalu selisihnya diajukan ke Manajer.
 *
 * PENTING: seluruh kalkulasi uang dihitung ULANG di backend. Angka yang dikirim
 * browser tidak pernah dipercaya — mencegah manipulasi total fisik/selisih.
 */
class ClosingController extends Controller
{
    public function __construct(private AturanLintasHariService $lintasHari) {}

    /**
     * Halaman opname & closing: kartu ringkasan kas + form pecahan fisik.
     *
     * Parameter `tanggal` (query string, opsional) memungkinkan teller
     * menyelesaikan sesi kas LINTAS HARI — mis. shift malam yang baru
     * ditutup setelah tengah malam. Nilai tidak sah / tanggal masa depan
     * dinormalisasi ke tanggal bisnis berjalan agar halaman tidak kosong.
     */
    public function index(Request $request): View
    {
        $tellerId = Auth::id();
        $hariIni = $this->lintasHari->tanggalBisnis();
        $tanggal = $this->lintasHari->normalisasiTanggal($request->query('tanggal'), $hariIni);

        $kasHarian = KasHarian::where('user_id', $tellerId)->whereDate('tanggal', $tanggal)->first();

        // Saldo sistem = Kas Awal (modal_awal) + Cash In - Cash Out.
        // Sumber perhitungan sama dengan halaman Buka Kas & Rekap agar konsisten.
        $errorKas = null;
        $posisiKas = null;
        $opnameAktif = null;
        $sesiTertunda = collect();

        try {
            $posisiKas = KasHarian::ringkasanPosisiKas($tellerId, $tanggal);
        } catch (\Throwable $e) {
            report($e);
            $errorKas = 'Gagal menghitung posisi kas: '.$e->getMessage();
        }

        try {
            $opnameAktif = $kasHarian?->opnameTerakhir()->first();
        } catch (\Throwable $e) {
            report($e);
            $errorKas = $errorKas ?? 'Gagal memuat status opname: '.$e->getMessage();
        }

        // Sesi sebelum hari ini yang masih OPEN/PENDING_APPROVAL — ditampilkan
        // agar teller/manajer dapat menuntaskannya tanpa kehilangan akses.
        try {
            $sesiTertunda = $this->lintasHari->daftarSesiBelumTutup($tellerId, $hariIni);
        } catch (\Throwable $e) {
            report($e);
        }

        return view('teller.kas.closing', [
            'kasHarian' => $kasHarian,
            'posisiKas' => $posisiKas,
            'errorKas' => $errorKas,
            'opnameAktif' => $opnameAktif,
            'tanggal' => $tanggal,
            'hariIni' => $hariIni,
            'sesiTertunda' => $sesiTertunda,
            'pecahanList' => TellerOpname::PECAHAN,
            'totalSetoran' => JenisTransaksi::jumlah($posisiKas['rincian_in'] ?? [], [JenisTransaksi::SETORAN_TUNAI]),
            'totalPenarikan' => JenisTransaksi::jumlah($posisiKas['rincian_out'] ?? [], [JenisTransaksi::PENARIKAN_TUNAI]),
            'totalAngsuran' => JenisTransaksi::jumlah($posisiKas['rincian_in'] ?? [], JenisTransaksi::angsuran()),
            'saldoSistem' => $posisiKas['saldo'] ?? 0,
        ]);
    }

    /**
     * Simpan pengajuan opname dengan status awal `pending`.
     *
     * Alur:
     *  1. Validasi array pecahan (bentuk & rentang lembar).
     *  2. Hitung total fisik & selisih ULANG di backend.
     *  3. DB::transaction: simpan teller_opnames + tandai sesi Buka Kas
     *     menjadi PENDING_APPROVAL sehingga teller tidak bisa menambah transaksi.
     */
    public function storeOpname(Request $request): RedirectResponse
    {
        // rincian_pecahan dikirim sebagai map: ['100000' => 3, '50000' => 10, ...]
        $request->validate([
            'rincian_pecahan' => 'required|array',
            'rincian_pecahan.*' => 'nullable|integer|min:0|max:1000000',
            'catatan' => 'nullable|string|max:1000',
            'tanggal' => 'nullable|date_format:Y-m-d',
        ], [
            'rincian_pecahan.required' => 'Rincian pecahan wajib diisi.',
            'rincian_pecahan.array' => 'Format rincian pecahan tidak valid.',
            'rincian_pecahan.*.integer' => 'Jumlah lembar harus berupa angka bulat.',
            'rincian_pecahan.*.min' => 'Jumlah lembar tidak boleh negatif.',
            'rincian_pecahan.*.max' => 'Jumlah lembar tidak wajar (maksimal 1.000.000 lembar).',
            'tanggal.date_format' => 'Format tanggal sesi kas harus YYYY-MM-DD.',
        ]);

        $tellerId = Auth::id();
        $hariIni = $this->lintasHari->tanggalBisnis();
        $inputTanggal = $request->input('tanggal');

        // Validasi batas lintas hari: tanggal sesi tidak boleh melewati
        // tanggal bisnis berjalan (kas fisik masa depan belum mungkin dihitung).
        if (is_string($inputTanggal) && trim($inputTanggal) !== ''
            && ! $this->lintasHari->tanggalSesiSah(trim($inputTanggal), $hariIni)) {
            return back()->withInput()->with('error',
                'Tanggal sesi kas tidak sah atau melewati hari ini. Periksa kembali tanggal yang dipilih.');
        }

        $tanggal = $this->lintasHari->normalisasiTanggal(
            is_string($inputTanggal) ? $inputTanggal : null,
            $hariIni
        );

        $kasHarian = KasHarian::where('user_id', $tellerId)->whereDate('tanggal', $tanggal)->first();

        if (! $kasHarian) {
            $keteranganTanggal = $tanggal === $hariIni ? 'hari ini' : "tanggal {$tanggal}";

            return back()->withInput()->with('error', "Belum ada Opening Kas {$keteranganTanggal}. Lakukan Buka Kas terlebih dahulu sebelum melakukan opname.");
        }

        if ($kasHarian->isStatusClosed()) {
            return back()->with('error', "Sesi kas tanggal {$tanggal} sudah ditutup dan disetujui Manajer. Tidak dapat mengajukan opname lagi.");
        }

        // Cegah pengajuan ganda: satu sesi hanya boleh punya satu opname pending.
        $sudahPending = TellerOpname::where('kas_harian_id', $kasHarian->id)
            ->where('status', TellerOpname::STATUS_PENDING)
            ->exists();

        if ($sudahPending) {
            return back()->with('error', 'Sudah ada pengajuan opname yang menunggu verifikasi Manajer. Tunggu keputusan sebelum mengajukan ulang.');
        }

        // ─── Kalkulasi otoritatif di backend ────────────────────
        try {
            $saldoSistem = (float) KasHarian::ringkasanPosisiKas($tellerId, $tanggal)['saldo'];
        } catch (\Throwable $e) {
            report($e);
            Log::error('ClosingController: gagal menghitung saldo sistem saat opname.', ['teller_id' => $tellerId, 'error' => $e->getMessage()]);

            return back()->withInput()->with('error', 'Opname gagal: posisi kas sistem tidak dapat dihitung. '.$e->getMessage());
        }

        $rincian = TellerOpname::hitungRincianPecahan($request->input('rincian_pecahan', []));
        $totalFisik = (float) $rincian['total'];
        $selisih = $totalFisik - $saldoSistem;
        $statusSelisih = TellerOpname::tentukanStatusSelisih($selisih);

        if ($totalFisik <= 0) {
            return back()->withInput()->with('error', 'Total fisik kas Rp 0. Isi jumlah lembar pada minimal satu pecahan sebelum mengajukan opname.');
        }

        try {
            $opname = DB::transaction(function () use (
                $kasHarian, $tellerId, $tanggal, $saldoSistem, $totalFisik, $selisih, $statusSelisih, $rincian, $request
            ) {
                $opname = TellerOpname::create([
                    'kas_harian_id' => $kasHarian->id,
                    'user_id' => $tellerId,
                    'tanggal' => $tanggal,
                    'saldo_sistem' => $saldoSistem,
                    'total_fisik' => $totalFisik,
                    'selisih' => $selisih,
                    'rincian_pecahan' => $rincian['baris'],
                    'status' => TellerOpname::STATUS_PENDING,
                    'status_selisih' => $statusSelisih,
                    'catatan' => $request->input('catatan'),
                ]);

                // Tandai sesi Buka Kas: menunggu approval, kas tidak boleh berputar lagi.
                $kasHarian->update([
                    'status_opname' => KasHarian::STATUS_PENDING_APPROVAL,
                    'fisik_kas' => $totalFisik,
                    'saldo_sistem' => $saldoSistem,
                    'selisih' => $selisih,
                    'status_closing' => TellerOpname::labelStatusSelisih($statusSelisih),
                    'catatan_closing' => $request->input('catatan'),
                    'waktu_closing' => now()->format('H:i:s'),
                ]);

                return $opname;
            });
        } catch (\Throwable $e) {
            report($e);
            Log::error('ClosingController: gagal menyimpan opname.', ['teller_id' => $tellerId, 'error' => $e->getMessage()]);

            return back()->withInput()->with('error', 'Opname gagal disimpan: '.$e->getMessage());
        }

        $label = TellerOpname::labelStatusSelisih($statusSelisih);
        $pesan = "Opname berhasil diajukan (status: {$label}). Menunggu verifikasi Manajer.";
        if ($statusSelisih !== TellerOpname::SELISIH_BALANCED) {
            $pesan .= ' Selisih Rp '.number_format(abs($selisih), 0, ',', '.')
                .($selisih > 0 ? ' (kas lebih).' : ' (kas kurang).');
        }

        // Sertakan `tanggal` hanya untuk sesi lintas hari, agar URL sesi
        // berjalan tetap bersih (kompatibel dengan tautan/redirect lama).
        return redirect()->route('teller.kas.closing', $tanggal === $hariIni ? [] : ['tanggal' => $tanggal])
            ->with('success', $pesan)
            ->with('opname_id', $opname->id);
    }

    /**
     * Batalkan pengajuan opname yang masih pending agar teller dapat menghitung ulang.
     */
    public function batalOpname(TellerOpname $opname): RedirectResponse
    {
        if ((int) $opname->user_id !== (int) Auth::id()) {
            abort(403, 'Anda hanya dapat membatalkan pengajuan opname milik sendiri.');
        }

        if (! $opname->isPending()) {
            return back()->with('error', 'Pengajuan ini sudah diproses Manajer dan tidak dapat dibatalkan.');
        }

        try {
            DB::transaction(function () use ($opname) {
                $opname->update([
                    'status' => TellerOpname::STATUS_REJECTED,
                    'catatan_approval' => 'Dibatalkan sendiri oleh teller untuk dihitung ulang.',
                    'approved_at' => now(),
                ]);

                $opname->kasHarian?->update([
                    'status_opname' => KasHarian::STATUS_OPEN,
                    'waktu_closing' => null,
                    'status_closing' => null,
                    'catatan_closing' => null,
                ]);
            });
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Gagal membatalkan pengajuan: '.$e->getMessage());
        }

        // Kembali ke sesi yang sama (mendukung Closing lintas hari) agar
        // teller tidak kehilangan konteks tanggal setelah membatalkan opname.
        $tanggalSesi = $opname->tanggal?->toDateString();
        $perluTanggal = $tanggalSesi !== null && $tanggalSesi !== $this->lintasHari->tanggalBisnis();

        return redirect()->route('teller.kas.closing', $perluTanggal ? ['tanggal' => $tanggalSesi] : [])
            ->with('success', 'Pengajuan opname dibatalkan. Silakan hitung ulang fisik kas.');
    }
}
