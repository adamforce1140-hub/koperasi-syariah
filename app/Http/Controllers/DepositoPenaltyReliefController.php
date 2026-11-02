<?php

namespace App\Http\Controllers;

use App\Models\Anggota;
use App\Models\Deposito;
use App\Models\DepositoPenaltyRelief;
use App\Models\Rekening;
use App\Support\Coa;
use App\Support\PemisahanTugas;
use App\Support\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pencairan dini deposito & pengajuan keringanan penalti (ta'zir).
 *
 * Penalti pencairan dini deposito Mudharabah adalah ta'zir yang dibukukan ke
 * Dana Kebajikan (2-3001), bukan pendapatan koperasi — Fatwa DSN-MUI
 * No. 17/DSN-MUI/IX/2000. Anggota yang keberatan dapat mengajukan keringanan
 * melalui jalur banding berjenjang:
 *
 *   TELLER (ajukan, PENDING_AO)
 *     -> AO (verifikasi & rekomendasi, PENDING_MANAJER)
 *       -> MANAJER (approve/reject, APPROVED/REJECTED)
 *
 * Prinsip maker ≠ checker dijaga lewat App\Support\PemisahanTugas: satu orang
 * tidak boleh memutuskan pengajuan yang ia ajukan sendiri.
 *
 * SATU controller dipakai bersama oleh tiga peran; otorisasi per aksi
 * ditegakkan lewat middleware `role:` pada definisi rute.
 */
class DepositoPenaltyReliefController extends Controller
{
    /* =====================================================================
     | 1. TELLER — Ajukan keringanan penalti untuk sebuah bilyet deposito
     |===================================================================== */

    /** Form pengajuan keringanan penalti (dipilih dari daftar deposito aktif). */
    public function create(Request $request)
    {
        $anggota = null;
        $deposito = collect();

        if ($request->filled('anggota_id')) {
            $anggota = Anggota::findOrFail($request->anggota_id);
            $deposito = Deposito::milik($anggota->id)
                ->where('status', Deposito::STATUS_ACTIVE)
                ->with('product')
                ->orderByDesc('start_date')
                ->get();
        }

        return view('teller.keringanan-penalti-deposito.create', compact('anggota', 'deposito'));
    }

    /** Simpan pengajuan keringanan (status awal: PENDING_AO). */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'deposito_id' => 'required|exists:depositos,id',
            'alasan_pengaju' => 'required|string|max:1000',
        ], [
            'deposito_id.required' => 'Bilyet deposito wajib dipilih.',
            'deposito_id.exists' => 'Bilyet deposito tidak ditemukan.',
            'alasan_pengaju.required' => 'Alasan pengajuan keringanan wajib diisi.',
        ]);

        $deposito = Deposito::with('product')->findOrFail($validated['deposito_id']);

        if ($deposito->status !== Deposito::STATUS_ACTIVE) {
            return back()->with('error',
                "Deposito {$deposito->deposito_number} berstatus {$deposito->status} dan tidak dapat diajukan keringanan.");
        }

        if ($deposito->depositoSudahDiajukanKeringanan()) {
            return back()->with('error',
                "Deposito {$deposito->deposito_number} sudah memiliki pengajuan keringanan yang masih berjalan.");
        }

        $pokok = Setting::bulatkanUang((float) $deposito->principal_amount);
        $penaltiAwal = $deposito->product
            ? $deposito->product->hitungPenalti($pokok)
            : 0.0;

        $relief = DB::transaction(function () use ($validated, $deposito, $penaltiAwal, $pokok) {
            // Penalti awal tidak boleh melebihi pokok — konsisten dengan guard service.
            $penaltiAwal = min($penaltiAwal, $pokok);

            return DepositoPenaltyRelief::create([
                'deposito_id' => $deposito->id,
                'anggota_id' => $deposito->member_id,
                'nominal_penalti_awal' => $penaltiAwal,
                'alasan_pengaju' => $validated['alasan_pengaju'],
                'pengaju_id' => Auth::id(),
                'status' => DepositoPenaltyRelief::STATUS_PENDING_AO,
            ]);
        });

        Log::info('DepositoPenaltyRelief: pengajuan keringanan penalti dibuat.', [
            'relief_id' => $relief->id,
            'deposito_number' => $deposito->deposito_number,
            'penalti_awal' => $penaltiAwal,
            'pengaju_id' => Auth::id(),
        ]);

        return redirect()->route('teller.keringanan-penalti.index')
            ->with('success', "Pengajuan keringanan penalti untuk {$deposito->deposito_number} terkirim ke AO untuk verifikasi.");
    }

    /* =====================================================================
     | 2. AO — Verifikasi lapangan & beri rekomendasi
     |===================================================================== */

    public function aoIndex(Request $request)
    {
        $status = $request->status ?? DepositoPenaltyRelief::STATUS_PENDING_AO;

        $query = DepositoPenaltyRelief::with(['deposito', 'anggota', 'pengaju', 'ao'])
            ->latest();

        if ($status && in_array($status, [
            DepositoPenaltyRelief::STATUS_PENDING_AO,
            DepositoPenaltyRelief::STATUS_PENDING_MANAJER,
            DepositoPenaltyRelief::STATUS_APPROVED,
            DepositoPenaltyRelief::STATUS_REJECTED,
        ], true)) {
            $query->where('status', $status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('anggota', fn ($x) => $x
                    ->where('nama_lengkap', 'like', "%{$search}%")
                    ->orWhere('no_anggota', 'like', "%{$search}%"))
                    ->orWhereHas('deposito', fn ($x) => $x
                        ->where('deposito_number', 'like', "%{$search}%"));
            });
        }

        $pengajuan = $query->paginate(20)->withQueryString();

        $search = $request->search;
        $totalPendingAo = DepositoPenaltyRelief::pendingAo()->count();
        $totalPendingManajer = DepositoPenaltyRelief::pendingManajer()->count();
        $totalApproved = DepositoPenaltyRelief::where('status', DepositoPenaltyRelief::STATUS_APPROVED)->count();
        $totalRejected = DepositoPenaltyRelief::where('status', DepositoPenaltyRelief::STATUS_REJECTED)->count();

        return view('ao.keringanan_penalti_deposito.index', compact(
            'pengajuan',
            'status',
            'search',
            'totalPendingAo',
            'totalPendingManajer',
            'totalApproved',
            'totalRejected'
        ));
    }

    /** AO menyimpan rekomendasi; status lanjut ke PENDING_MANAJER. */
    public function rekomendasi(Request $request, DepositoPenaltyRelief $relief)
    {
        $validated = $request->validate([
            'rekomendasi_ao' => 'required|in:SETUJU,TOLAK',
            'catatan_ao' => 'required|string|max:1000',
        ], [
            'rekomendasi_ao.required' => 'Rekomendasi AO wajib dipilih.',
            'rekomendasi_ao.in' => 'Rekomendasi AO tidak valid.',
            'catatan_ao.required' => 'Catatan analisis AO wajib diisi.',
        ]);

        if (! $relief->isPendingAo()) {
            return back()->with('error', 'Pengajuan ini sudah diproses dan tidak dapat direkomendasikan ulang.');
        }

        if (PemisahanTugas::dilanggar($relief->pengaju_id, Auth::id())) {
            return back()->with('error', PemisahanTugas::PESAN);
        }

        DB::transaction(function () use ($relief, $validated) {
            $relief->update([
                'rekomendasi_ao' => $validated['rekomendasi_ao'],
                'catatan_ao' => $validated['catatan_ao'],
                'ao_id' => Auth::id(),
                'status' => DepositoPenaltyRelief::STATUS_PENDING_MANAJER,
            ]);
        });

        $pesan = $validated['rekomendasi_ao'] === DepositoPenaltyRelief::REKOMENDASI_SETUJU
            ? 'Rekomendasi BEBAS penalti (Rp 0) terkirim ke Manajer untuk keputusan akhir.'
            : 'Rekomendasi TOLAK (anggota tetap menanggung penalti) terkirim ke Manajer untuk keputusan akhir.';

        return redirect()->route('ao.keringanan-penalti.index')->with('success', $pesan);
    }

    /* =====================================================================
     | 3. MANAJER — Keputusan akhir (approve / reject)
     |===================================================================== */

    public function manajerIndex(Request $request)
    {
        $status = $request->status ?? DepositoPenaltyRelief::STATUS_PENDING_MANAJER;

        $query = DepositoPenaltyRelief::with(['deposito', 'anggota', 'pengaju', 'ao', 'manajer'])
            ->latest();

        if ($status && in_array($status, [
            DepositoPenaltyRelief::STATUS_PENDING_AO,
            DepositoPenaltyRelief::STATUS_PENDING_MANAJER,
            DepositoPenaltyRelief::STATUS_APPROVED,
            DepositoPenaltyRelief::STATUS_REJECTED,
        ], true)) {
            $query->where('status', $status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('anggota', fn ($x) => $x
                    ->where('nama_lengkap', 'like', "%{$search}%")
                    ->orWhere('no_anggota', 'like', "%{$search}%"))
                    ->orWhereHas('deposito', fn ($x) => $x
                        ->where('deposito_number', 'like', "%{$search}%"));
            });
        }

        $pengajuan = $query->paginate(20)->withQueryString();

        $search = $request->search;
        $totalPendingAo = DepositoPenaltyRelief::pendingAo()->count();
        $totalPendingManajer = DepositoPenaltyRelief::pendingManajer()->count();
        $totalApproved = DepositoPenaltyRelief::where('status', DepositoPenaltyRelief::STATUS_APPROVED)->count();
        $totalRejected = DepositoPenaltyRelief::where('status', DepositoPenaltyRelief::STATUS_REJECTED)->count();

        return view('manajer.keringanan_penalti_deposito.index', compact(
            'pengajuan',
            'status',
            'search',
            'totalPendingAo',
            'totalPendingManajer',
            'totalApproved',
            'totalRejected'
        ));
    }

    /**
     * Setujui keringanan.
     *
     * Anggota dibebaskan dari penalti maksimal sebesar nominal penalti awal;
     * keringanan tidak pernah membuat anggota "menerima lebih" dari pokoknya.
     */
    public function approve(Request $request, DepositoPenaltyRelief $relief)
    {
        if (! $relief->isPendingManajer()) {
            return back()->with('error', 'Pengajuan ini belum/tidak berada pada tahap keputusan Manajer.');
        }

        if (PemisahanTugas::dilanggarSalahSatu([$relief->pengaju_id, $relief->ao_id], Auth::id())) {
            return back()->with('error', PemisahanTugas::PESAN);
        }

        $validated = $request->validate([
            'catatan_manajer' => 'nullable|string|max:1000',
            'nominal_disetujui' => 'nullable|numeric|min:0',
        ], [
            'nominal_disetujui.numeric' => 'Nominal keringanan harus berupa angka.',
            'nominal_disetujui.min' => 'Nominal keringanan tidak boleh negatif.',
        ]);

        $penaltiAwal = Setting::bulatkanUang((float) $relief->nominal_penalti_awal);

        // Default: bebas penuh (Rp 0 penalti). Bila Manajer mengisi nominal,
        // nilainya dibatasi tidak melebihi penalti awal.
        $disetujui = $request->filled('nominal_disetujui')
            ? min(Setting::bulatkanUang((float) $validated['nominal_disetujui']), $penaltiAwal)
            : $penaltiAwal;

        DB::transaction(function () use ($relief, $validated, $disetujui) {
            $relief->update([
                'nominal_penalti_disetujui' => $disetujui,
                'catatan_manajer' => $validated['catatan_manajer'] ?? null,
                'manajer_id' => Auth::id(),
                'status' => DepositoPenaltyRelief::STATUS_APPROVED,
            ]);
        });

        Log::info('DepositoPenaltyRelief: keringanan penalti disetujui.', [
            'relief_id' => $relief->id,
            'deposito_number' => $relief->deposito?->deposito_number,
            'penalti_awal' => $penaltiAwal,
            'penalti_disetujui' => $disetujui,
            'manajer_id' => Auth::id(),
        ]);

        if ($disetujui >= $penaltiAwal && $penaltiAwal > 0) {
            return back()->with('success', 'Keringanan BEBAS PENUH disetujui. Anggota tidak menanggung biaya penalti.');
        }

        return back()->with('success',
            'Keringanan disetujui Rp '.number_format($disetujui, 0, ',', '.').
            '. Anggota tetap menanggung Rp '.number_format($penaltiAwal - $disetujui, 0, ',', '.').'.');
    }

    /** Tolak keringanan — penalti tetap ditanggung penuh oleh anggota. */
    public function reject(Request $request, DepositoPenaltyRelief $relief)
    {
        $validated = $request->validate([
            'catatan_manajer' => 'required|string|max:1000',
        ], [
            'catatan_manajer.required' => 'Alasan penolakan wajib diisi.',
        ]);

        if (! $relief->isPendingManajer()) {
            return back()->with('error', 'Pengajuan ini belum/tidak berada pada tahap keputusan Manajer.');
        }

        if (PemisahanTugas::dilanggarSalahSatu([$relief->pengaju_id, $relief->ao_id], Auth::id())) {
            return back()->with('error', PemisahanTugas::PESAN);
        }

        DB::transaction(function () use ($relief, $validated) {
            $relief->update([
                'nominal_penalti_disetujui' => 0,
                'catatan_manajer' => $validated['catatan_manajer'],
                'manajer_id' => Auth::id(),
                'status' => DepositoPenaltyRelief::STATUS_REJECTED,
            ]);
        });

        Log::info('DepositoPenaltyRelief: keringanan penalti ditolak.', [
            'relief_id' => $relief->id,
            'deposito_number' => $relief->deposito?->deposito_number,
            'manajer_id' => Auth::id(),
        ]);

        return back()->with('warning',
            'Keringanan DITOLAK. Penalti tetap ditanggung penuh dan dibukukan sebagai dana sosial '
            .'('.Coa::DANA_KEBAJIKAN.' Dana Kebajikan).');
    }

    /* =====================================================================
     | 4. RIWAYAT — untuk Teller
     |===================================================================== */

    public function riwayat(Request $request)
    {
        $query = DepositoPenaltyRelief::with(['deposito', 'anggota', 'ao', 'manajer'])
            ->where('pengaju_id', Auth::id())
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $pengajuan = $query->paginate(20)->withQueryString();

        return view('teller.keringanan-penalti-deposito.index', compact('pengajuan'));
    }

    /**
     * Ringkasan bilyet deposito aktif milik anggota — dipakai form pengajuan
     * agar teller dapat memverifikasi kecukupan pokok sebelum mengajukan.
     *
     * SEC (Batch 24): respons SENGAJA dibentuk sebagai array eksplisit
     * (id/no_anggota/nama + ringkasan bilyet), TIDAK menyertakan nilai mentah
     * `Anggota`. `Anggota::$hidden` hanya menutup `nik_hash`, sedangkan `nik`
     * ber-cast `encrypted` sehingga terbaca kembali bila model diserialisasi
     * apa adanya — melanggar minimisasi data UU PDP No. 27/2022. Perubahan
     * struktur respons harus tetap dijaga test
     * `IntegritasRuteTellerTanpaTest::test_info_anggota_tidak_membocorkan_nik_dan_hash`.
     */
    public function infoAnggota(Anggota $anggota)
    {
        $deposito = Deposito::milik($anggota->id)
            ->where('status', Deposito::STATUS_ACTIVE)
            ->with('product')
            ->get()
            ->map(fn (Deposito $d) => [
                'id' => $d->id,
                'deposito_number' => $d->deposito_number,
                'pokok' => (float) $d->principal_amount,
                'penalti_estimasi' => Setting::bulatkanUang((float) ($d->product?->hitungPenalti((float) $d->principal_amount) ?? 0)),
                'sumber_sukarela' => Rekening::whereKey($d->source_saving_account_id)->value('no_rekening'),
            ]);

        return response()->json([
            'anggota' => [
                'id' => $anggota->id,
                'no_anggota' => $anggota->no_anggota,
                'nama' => $anggota->nama_lengkap,
            ],
            'deposito' => $deposito,
        ]);
    }
}
