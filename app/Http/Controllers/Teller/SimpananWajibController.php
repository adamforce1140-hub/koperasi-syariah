<?php

namespace App\Http\Controllers\Teller;

use App\Http\Controllers\Controller;
use App\Models\Anggota;
use App\Models\MandatorySavingsBill;
use App\Services\MandatorySavingsService;
use App\Support\GuardSimpananModal;
use App\Support\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Permukaan HTTP modul Simpanan Wajib bulanan untuk loket Teller.
 *
 * Modul ini melengkapi layer yang sudah ada:
 *  - `MandatorySavingsService::processDaily()`  → penerbitan tagihan + auto-debit (scheduler).
 *  - `MandatorySavingsService::bayarViaTeller()` → pelunasan manual (tunai/transfer).
 *
 * Sebelumnya `bayarViaTeller()` tidak pernah dipanggil dari HTTP mana pun,
 * sehingga anggota yang memilih pembayaran manual (`is_auto_debit = false`)
 * tidak punya jalur pelunasan sama sekali — tagihannya menggantung UNPAID
 * sampai jatuh tempo lalu OVERDUE tanpa cara melunasinya di loket.
 * Controller ini menutup celah tersebut: daftar tagihan + aksi pelunasan.
 *
 * Jurnal yang diposting tetap sepenuhnya dikendalikan service (PSAK 101/108):
 *   DEBET  1-1001 Kas Teller / Bank
 *   KREDIT 3-1002 Simpanan Wajib Anggota (Modal Anggota)
 */
class SimpananWajibController extends Controller
{
    public function __construct(
        private MandatorySavingsService $service,
    ) {}

    /**
     * Daftar tagihan Simpanan Wajib seluruh anggota dengan filter
     * status / periode / pencarian anggota, plus ringkasan tunggakan.
     */
    public function index(Request $request)
    {
        $query = MandatorySavingsBill::query()->with('member');

        if ($request->filled('status') && in_array($request->status, MandatorySavingsBill::STATUS_LIST, true)) {
            $query->where('status', $request->status);
        }

        if ($request->filled('periode')) {
            $query->periode($request->periode);
        }

        if ($request->filled('cari')) {
            $cari = $request->cari;
            $query->whereHas('member', fn ($q) => $q
                ->where('nama_lengkap', 'like', "%{$cari}%")
                ->orWhere('no_anggota', 'like', "%{$cari}%"));
        }

        $tagihan = $query
            ->orderByDesc('bill_period')
            ->orderBy('member_id')
            ->paginate(25)
            ->withQueryString();

        // Ringkasan selalu dihitung dari SELURUH data (bukan hasil filter) agar
        // angka tunggakan tidak berubah hanya karena filter tampilan berubah.
        $ringkasan = [
            'unpaid' => MandatorySavingsBill::where('status', MandatorySavingsBill::STATUS_UNPAID)->count(),
            'overdue' => MandatorySavingsBill::where('status', MandatorySavingsBill::STATUS_OVERDUE)->count(),
            'ditangguhkan' => MandatorySavingsBill::where('status', MandatorySavingsBill::STATUS_PENDING_CONFIRMATION)->count(),
            'total_tunggakan' => Setting::bulatkanUang((float) MandatorySavingsBill::belumLunas()->sum('amount')),
        ];

        $periodeTersedia = MandatorySavingsBill::query()
            ->select('bill_period')
            ->distinct()
            ->orderByDesc('bill_period')
            ->limit(24)
            ->pluck('bill_period');

        // Ringkasan penanganan dinamis per tagihan (hanya halaman aktif, agar
        // tidak membebani seluruh dataset): kekurangan saldo Sukarela, jadwal
        // bertahap aktif, dan jumlah reschedule — dipakai panel tindakan Teller.
        $penanganan = $tagihan->getCollection()
            ->mapWithKeys(fn (MandatorySavingsBill $t) => [
                $t->id => $this->service->ringkasanPenanganan($t) + [
                    // Tenggat pembayaran tagihan (spec pelacakan daftar tertagih).
                    'tenggat' => $t->member
                        ? $this->service->tanggalJatuhTempo(
                            $t->member,
                            $this->service->periodeTagihan($t)
                        )
                        : null,
                ],
            ]);

        // Status saklar kebijakan (auto-debit & penanganan dinamis) agar panel
        // tindakan dapat memberi umpan balik ketika modul dinonaktifkan Pengurus.
        $kebijakan = [
            'auto_debit_aktif' => Setting::simpananWajibAutoDebitAktif(),
            'penanganan_dinamis_aktif' => Setting::simpananWajibPenangananDinamis(),
            'maks_cicilan' => Setting::simpananWajibMaksCicilan(),
            'maks_reschedule' => Setting::simpananWajibMaksReschedule(),
        ];

        // Panel penarikan rutin DIHAPUS dari UI (PSAK 101 ¶ 31): Simpanan Wajib
        // (3-1002) adalah Modal/Ekuitas anggota. Pengembalian dana hanya melalui
        // modul Pengunduran Diri (member_terminations). View kini menampilkan
        // panel terkunci sebagai ganti formulir penarikan.
        // Rute `teller.simpanan-wajib.tarik` tetap dipertahankan sebagai gerbang
        // backend yang selalu menolak (defense-in-depth terhadap request langsung).
        return view('teller.simpanan-wajib', compact(
            'tagihan', 'ringkasan', 'periodeTersedia', 'penanganan', 'kebijakan'
        ));
    }

    /**
     * Lunasi satu tagihan Simpanan Wajib secara manual di loket.
     *
     * Metode hanya Tunai (CASH) atau Transfer — AUTO_DEBIT adalah jalur
     * scheduler, bukan jalur loket, sehingga tidak boleh dipilih di sini.
     */
    public function bayar(Request $request, MandatorySavingsBill $tagihan)
    {
        $validated = $request->validate([
            'metode' => 'required|in:'.MandatorySavingsBill::METODE_CASH.','.MandatorySavingsBill::METODE_TRANSFER,
        ], [
            'metode.required' => 'Metode pembayaran wajib dipilih.',
            'metode.in' => 'Metode pembayaran hanya boleh Tunai atau Transfer.',
        ]);

        try {
            $berhasil = $this->service->bayarViaTeller(
                $tagihan,
                $validated['metode'],
                null,
                Auth::id()
            );
        } catch (\Throwable $e) {
            // Jurnal gagal → seluruh transaksi di-rollback oleh service. Tagihan
            // tetap terbuka sehingga Teller dapat mencoba ulang setelah CoA/policy
            // diperbaiki. Kegagalan dicatat agar dapat ditelusuri.
            Log::error('SimpananWajibController: pelunasan tagihan gagal.', [
                'bill_id' => $tagihan->id,
                'periode' => $tagihan->bill_period,
                'metode' => $validated['metode'],
                'user_id' => Auth::id(),
                'pesan' => $e->getMessage(),
            ]);

            return back()->with('error',
                'Pelunasan gagal: '.$e->getMessage().' Tagihan belum berubah, silakan coba lagi.');
        }

        if (! $berhasil) {
            return back()->with('warning',
                'Tagihan periode '.$tagihan->nama_periode.' sudah lunas sebelumnya. Tidak ada jurnal baru yang diposting.');
        }

        $tagihan->refresh();

        return redirect()
            ->route('teller.nota', $tagihan->transaksi_id)
            ->with('success', 'Setoran Simpanan Wajib '.$tagihan->nama_periode.' berhasil. Jurnal PSAK 101 telah diposting.');
    }

    /**
     * KONFIRMASI MANUAL (BUG-03): petugas menyetujui setoran otomatis yang
     * sebelumnya DITANGGUHKAN karena verifikasi status pembayaran gagal.
     *
     * Dana tetap TIDAK dipotong bila verifikasi masih gagal — service menolak
     * konfirmasi dan mencatat jejak audit, sehingga risiko kerugian akibat
     * anggota yang tidak membayar dapat diminimalkan.
     */
    public function konfirmasi(Request $request, MandatorySavingsBill $tagihan)
    {
        if (! $tagihan->isPendingConfirmation()) {
            return back()->with('warning',
                'Tagihan periode '.$tagihan->nama_periode.' tidak dalam status ditangguhkan — konfirmasi manual tidak berlaku.');
        }

        $validated = $request->validate([
            'catatan' => 'nullable|string|max:500',
        ], [
            'catatan.max' => 'Catatan maksimal 500 karakter.',
        ]);

        try {
            $berhasil = $this->service->konfirmasiPenangguhan(
                $tagihan,
                (int) Auth::id(),
                $validated['catatan'] ?? null
            );
        } catch (\Throwable $e) {
            Log::error('SimpananWajibController: konfirmasi penangguhan gagal.', [
                'bill_id' => $tagihan->id,
                'periode' => $tagihan->bill_period,
                'user_id' => Auth::id(),
                'pesan' => $e->getMessage(),
            ]);

            return back()->with('error',
                'Konfirmasi gagal: '.$e->getMessage().' Tidak ada dana yang dipotong.');
        }

        if (! $berhasil) {
            return back()->with('warning',
                'Konfirmasi TIDAK dijalankan — verifikasi status pembayaran masih gagal (mis. saldo Sukarela tidak mencukupi). '
                .'Dana anggota TIDAK dipotong; tagihan tetap menunggu tindakan lanjutan.');
        }

        $tagihan->refresh();

        return back()->with('success',
            'Konfirmasi manual berhasil. Setoran Simpanan Wajib '.$tagihan->nama_periode.' dilanjutkan setelah verifikasi lolos; jurnal PSAK 101 telah diposting.');
    }

    /**
     * TOLAK konfirmasi: petugas memutuskan setoran otomatis TIDAK dijalankan.
     * Tagihan dialihkan ke jalur manual (OVERDUE); dana anggota tidak dipotong.
     */
    public function tolak(Request $request, MandatorySavingsBill $tagihan)
    {
        if (! $tagihan->isPendingConfirmation()) {
            return back()->with('warning',
                'Tagihan periode '.$tagihan->nama_periode.' tidak dalam status ditangguhkan — aksi tolak tidak berlaku.');
        }

        $validated = $request->validate([
            'catatan' => 'nullable|string|max:500',
        ], [
            'catatan.max' => 'Catatan maksimal 500 karakter.',
        ]);

        try {
            $this->service->tolakKonfirmasi(
                $tagihan,
                (int) Auth::id(),
                $validated['catatan'] ?? null
            );
        } catch (\Throwable $e) {
            Log::error('SimpananWajibController: tolak konfirmasi gagal.', [
                'bill_id' => $tagihan->id,
                'periode' => $tagihan->bill_period,
                'user_id' => Auth::id(),
                'pesan' => $e->getMessage(),
            ]);

            return back()->with('error',
                'Penolakan konfirmasi gagal: '.$e->getMessage().' Tagihan belum berubah.');
        }

        return back()->with('success',
            'Konfirmasi ditolak. Setoran otomatis '.$tagihan->nama_periode.' TIDAK dijalankan — tagihan dialihkan ke penanganan manual tanpa denda.');
    }

    /**
     * Tetapkan JADWAL PEMBAYARAN BERTAHAP untuk tagihan yang saldo Simpanan
     * Sukarelanya tidak mencukupi (penanganan dinamis, sesuai kemampuan anggota).
     */
    public function jadwalkan(Request $request, MandatorySavingsBill $tagihan)
    {
        $validated = $request->validate([
            'jumlah_cicilan' => 'nullable|integer|min:2|max:60',
        ], [
            'jumlah_cicilan.integer' => 'Jumlah cicilan harus berupa angka bulat.',
            'jumlah_cicilan.min' => 'Jumlah cicilan minimal 2.',
        ]);

        $hasil = $this->service->jadwalkanBertahap(
            $tagihan,
            isset($validated['jumlah_cicilan']) ? (int) $validated['jumlah_cicilan'] : null,
            null,
            Auth::id()
        );

        if (! $hasil['ok']) {
            return back()->with('warning', $this->pesanAlasanPenanganan($hasil['alasan']));
        }

        return back()->with('success', sprintf(
            'Jadwal bertahap %d cicilan @ Rp %s ditetapkan untuk periode %s — tanpa denda.',
            $hasil['cicilan'],
            number_format((float) $hasil['nominal_cicilan'], 0, ',', '.'),
            $tagihan->nama_periode
        ));
    }

    /**
     * Potong satu CICILAN dari saldo Simpanan Sukarela anggota.
     */
    public function bayarCicilan(Request $request, MandatorySavingsBill $tagihan)
    {
        $hasil = $this->service->bayarCicilan($tagihan, Auth::id());

        if (! $hasil['ok']) {
            return back()->with('warning', $this->pesanAlasanPenanganan($hasil['alasan']));
        }

        $pesan = sprintf(
            'Cicilan Rp %s dipotong dari Simpanan Sukarela. Sisa tagihan Rp %s.',
            number_format((float) $hasil['dibayar'], 0, ',', '.'),
            number_format((float) $hasil['sisa'], 0, ',', '.')
        );

        return back()->with($hasil['lunas'] ? 'success' : 'info',
            $hasil['lunas'] ? $pesan.' Tagihan LUNAS.' : $pesan);
    }

    /**
     * JADWALKAN ULANG tenggat tagihan TANPA DENDA (prinsip syariah).
     */
    public function reschedule(Request $request, MandatorySavingsBill $tagihan)
    {
        $hasil = $this->service->reschedule($tagihan, null, Auth::id());

        if (! $hasil['ok']) {
            return back()->with('warning', $this->pesanAlasanPenanganan($hasil['alasan']));
        }

        return back()->with('success', sprintf(
            'Tenggat tagihan periode %s digeser ke %s tanpa denda.',
            $tagihan->nama_periode,
            Carbon::parse($hasil['tenggat_baru'])->format('d/m/Y')
        ));
    }

    /**
     * Riwayat tindakan penanganan (audit trail) satu tagihan — transparansi
     * kepada anggota dan bahan audit pengurus.
     */
    public function riwayat(Request $request, MandatorySavingsBill $tagihan)
    {
        $tagihan->load('member');

        $tindakan = $tagihan->tindakan()
            ->with('user')
            ->orderByDesc('waktu_tindakan')
            ->paginate(30);

        $ringkasan = $this->service->ringkasanPenanganan($tagihan);

        return view('teller.simpanan-wajib-riwayat', compact('tagihan', 'tindakan', 'ringkasan'));
    }

    /**
     * Terjemahkan kode alasan penanganan dinamis menjadi pesan berbahasa
     * Indonesia yang informatif untuk petugas.
     */
    private function pesanAlasanPenanganan(?string $alasan): string
    {
        return match ($alasan) {
            'SUDAH_LUNAS' => 'Tagihan sudah lunas — tidak ada tindakan yang diperlukan.',
            'SUDAH_ADA_JADWAL' => 'Tagihan ini sudah memiliki jadwal bertahap aktif.',
            'SALDO_CUKUP' => 'Saldo Simpanan Sukarela sudah mencukupi — silakan lunasi langsung.',
            'NOMINAL_NOL' => 'Nominal tagihan nol — tidak dapat dijadwalkan.',
            'ANGGOTA_TIDAK_DITEMUKAN' => 'Anggota pemilik tagihan tidak ditemukan.',
            'BATAS_RESCHEDULE_TERCAPAI' => 'Batas maksimal penjadwalan ulang untuk tagihan ini telah tercapai.',
            'SALDO_KOSONG' => 'Saldo Simpanan Sukarela kosong — silakan setor tunai di Teller atau ajukan reschedule.',
            'SALDO_BERUBAH' => 'Saldo berubah saat pemotongan berlangsung. Silakan coba lagi.',
            'BELUM_ADA_JADWAL' => 'Tagihan ini belum memiliki jadwal bertahap — tetapkan jadwal terlebih dahulu.',
            'TIDAK_BERHAK_AUTO_DEBIT' => 'Anggota belum menyetujui auto-debit — cicilan tidak dapat dipotong dari Simpanan Sukarela.',
            'MODUL_NONAKTIF' => 'Modul penanganan dinamis sedang dinonaktifkan pada kebijakan koperasi.',
            default => 'Tindakan tidak dapat dijalankan.',
        };
    }

    /**
     * Tarik saldo Simpanan Wajib anggota (pengurangan Modal Anggota).
     *
     * PSAK 101 ¶ 31 — Simpanan Wajib adalah Modal Anggota; penarikannya umumnya
     * hanya sah saat anggota keluar (sesuai kebijakan `simpanan_wajib_hari_penarikan`).
     * Jurnal: DEBET akun kanonik Simpanan Wajib / KREDIT Kas Teller.
     *
     * @param  Anggota  $anggota  (route-model binding)
     */
    public function tarik(Request $request, Anggota $anggota)
    {
        $validated = $request->validate([
            'jumlah' => 'nullable|numeric|min:0.01',
            'metode' => 'required|in:'.MandatorySavingsBill::METODE_CASH.','.MandatorySavingsBill::METODE_TRANSFER,
            'catatan' => 'nullable|string|max:255',
        ], [
            'jumlah.numeric' => 'Nominal penarikan harus berupa angka.',
            'jumlah.min' => 'Nominal penarikan minimal Rp 0,01.',
            'metode.required' => 'Metode penarikan wajib dipilih.',
            'metode.in' => 'Metode penarikan hanya boleh Tunai atau Transfer.',
        ]);

        $anggota->loadMissing('user');

        // GATE — LARANGAN PENARIKAN MODAL ANGGOTA (PSAK 101 ¶ 31).
        // Penarikan rutin Simpanan Wajib (3-1002) DILARANG PERMANEN; pengembalian
        // dana hanya sah lewat modul `member_terminations` (PSAK 108). Gembok ini
        // TIDAK bersyarat sehingga request langsung pun selalu ditolak.
        try {
            GuardSimpananModal::pastikanTidakDitarikRutin(
                $anggota,
                'teller.simpanan-wajib.tarik'
            );
        } catch (\RuntimeException $e) {
            Log::warning('PENARIKAN_MODAL_DIBLOKIR: SimpananWajibController::tarik', [
                'anggota_id' => $anggota->id,
                'user_id' => Auth::id(),
            ]);

            return back()->with('error', $e->getMessage());
        }

        try {
            $hasil = $this->service->tarikSimpananWajib(
                $anggota,
                isset($validated['jumlah']) ? (float) $validated['jumlah'] : null,
                $validated['metode'],
                null,
                Auth::id()
            );
        } catch (\Throwable $e) {
            // Seluruh transaksi di-rollback oleh service: saldo anggota TIDAK
            // berkurang dan tidak ada jurnal menggantung. Kegagalan dicatat.
            Log::error('SimpananWajibController: penarikan gagal.', [
                'anggota_id' => $anggota->id,
                'nominal' => $validated['jumlah'] ?? null,
                'metode' => $validated['metode'],
                'user_id' => Auth::id(),
                'pesan' => $e->getMessage(),
            ]);

            return back()->with('error',
                'Penarikan gagal: '.$e->getMessage().' Tidak ada perubahan saldo maupun jurnal.');
        }

        $pesan = sprintf(
            'Penarikan Simpanan Wajib berhasil. Saldo Rp %s → Rp %s.',
            number_format($hasil['saldo_sebelum'], 2, ',', '.'),
            number_format($hasil['saldo_setelah'], 2, ',', '.')
        );

        return redirect()
            ->route('teller.nota', $hasil['transaksi']->id)
            ->with('success', $pesan);
    }
}
