<?php

namespace App\Http\Controllers\Manajer;

use App\Http\Controllers\Controller;
use App\Models\AutodebitUsulan;
use App\Models\JenisSimpanan;
use App\Models\Rekening;
use App\Models\TazirPenangguhan;
use App\Models\TazirReliefRequest;
use App\Services\AutodebitService;
use App\Support\PemisahanTugas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MANAJER — Keputusan akhir (approve/reject) pengajuan Keringanan Ta'zir.
 *
 * Alur: AO (PENDING_MANAJER) -> MANAJER (APPROVED / REJECTED).
 *
 * SAFEGUARD:
 *   - Seluruh update status angsuran & pengajuan dibungkus DB::transaction().
 *   - Approve  : tagihan ta'zir -> Rp 0. Jurnal tetap balance karena ta'zir tidak
 *                pernah dikreditkan ke 2-3001 (ditangguhkan sejak awal).
 *   - Reject   : angsuran pokok & margin yang sudah dibayar Teller TIDAK dibatalkan
 *                (tidak ada void/reversal pemotongan Simpanan Sukarela).
 *                Ta'zir baru dibukukan ke Dana Kebajikan (2-3001) saat benar-benar
 *                diterima — Cash Basis Syariah / PSAK 109.
 *
 * KEBIJAKAN AUTO-DEBIT (TIDAK ADA PEMOTONGAN TANPA KONFIRMASI):
 *   Menerima penolakan keringanan BUKAN berarti memotong saldo saat itu juga.
 *   Controller ini hanya MENCATAT tunggakan ta'zir lalu MENGUSULKAN auto-debit
 *   ke Pusat Konfirmasi (tabel `autodebit_usulans`). Pemotongan saldo
 *   Simpanan Sukarela baru dijalankan melalui `AutodebitService::konfirmasi()`
 *   oleh pengguna berwenang (teller/pengurus/manajer). Dengan begitu keputusan
 *   Manajer dan eksekusi pemotongan dana tetap terpisah — tidak ada celah
 *   pemotongan otomatis tanpa konfirmasi pengguna.
 */
class ApprovalTazirController extends Controller
{
    public function __construct(
        private AutodebitService $autodebitService
    ) {}

    public function index(Request $request)
    {
        $status = $request->status ?? TazirReliefRequest::STATUS_PENDING_MANAJER;
        $search = $request->search;

        $query = TazirReliefRequest::with([
            'angsuran',
            'pembiayaan.anggota',
            'anggota',
            'teller',
            'ao',
            'manajer',
        ])->latest();

        if ($status && in_array($status, [
            TazirReliefRequest::STATUS_PENDING_AO,
            TazirReliefRequest::STATUS_PENDING_MANAJER,
            TazirReliefRequest::STATUS_APPROVED,
            TazirReliefRequest::STATUS_REJECTED,
        ])) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('anggota', fn ($x) => $x->where('nama_lengkap', 'like', "%{$search}%")
                    ->orWhere('no_anggota', 'like', "%{$search}%")
                )
                    ->orWhereHas('pembiayaan', fn ($x) => $x->where('no_pembiayaan', 'like', "%{$search}%")
                    );
            });
        }

        $pengajuan = $query->paginate(20)->withQueryString();

        $totalPendingAo = TazirReliefRequest::pendingAo()->count();
        $totalPendingManajer = TazirReliefRequest::pendingManajer()->count();
        $totalApproved = TazirReliefRequest::where('status', TazirReliefRequest::STATUS_APPROVED)->count();
        $totalRejected = TazirReliefRequest::where('status', TazirReliefRequest::STATUS_REJECTED)->count();

        return view('manajer.keringanan_tazir.index', compact(
            'pengajuan', 'totalPendingAo', 'totalPendingManajer',
            'totalApproved', 'totalRejected', 'status', 'search'
        ));
    }

    public function approve(Request $request, TazirReliefRequest $relief)
    {
        if (! $relief->isPendingManajer()) {
            return redirect()->back()->with('error', 'Pengajuan ini belum/tidak dalam tahap approval Manajer.');
        }

        // GCG — pemisahan tugas: pihak yang pernah mengajukan/merekomendasikan
        // keringanan ini (Teller pengaju atau AO perekomendasi) tidak boleh
        // menjadi Manajer yang memutuskan.
        if (PemisahanTugas::dilanggarSalahSatu([$relief->teller_id, $relief->ao_id], Auth::id())) {
            return redirect()->back()->with('error', PemisahanTugas::PESAN);
        }

        $request->validate([
            'catatan_manajer' => 'nullable|string|max:1000',
        ]);

        DB::transaction(function () use ($relief, $request) {
            // 1) Setujui keringanan → ta'zir dibebaskan (Rp 0)
            $relief->update([
                'status' => TazirReliefRequest::STATUS_APPROVED,
                'nominal_tazir_disetujui' => 0,
                'manajer_id' => Auth::id(),
                'catatan_manajer' => $request->catatan_manajer,
            ]);

            // 2) Tagihan ta'zir pada jadwal angsuran di-set Rp 0
            $angsuran = $relief->angsuran;
            if ($angsuran) {
                $angsuran->update(['denda' => 0, 'status_tazir' => 'DIBEBASKAN']);
            }
        });

        return redirect()->back()->with('success',
            'Keringanan ta\'zir DISETUJUI. Tagihan ta\'zir anggota menjadi Rp 0 (Dana Kebajikan 2-3001 = Rp 0).');
    }

    /**
     * Tolak keringanan ta'zir.
     *
     * PERILAKU (Pusat Konfirmasi Auto-Debit):
     *  Semua penolakan HANYA mencatat tunggakan ta'zir lalu MENGUSULKAN
     *  pemotongan ke Pusat Konfirmasi — saldo Simpanan Sukarela TIDAK dipotong
     *  pada saat ini. Tindakan ini sengaja membedakan keputusan Manajer dari
     *  Tindakan pemotongan dana agar tidak ada auto-debit yang berjalan tanpa
     *  konfirmasi pengguna.
     */
    public function reject(Request $request, TazirReliefRequest $relief)
    {
        // TAHAP 5 — URUTAN VALIDASI: input divalidasi LEBIH DULU, baru state mesin
        // persetujuan, baru guard GCG. Tujuan: kombinasi antara pelanggaran
        // pemisahan tugas dan input tidak sah tidak lagi lolos sebagai galat
        // validasi (yang dapat disalahbaca sebagai "coba lagi") — pelanggaran
        // GCG harus muncul sebagai penolakan eksplisit setelah input bersih.
        $request->validate([
            'catatan_manajer' => 'required|string|max:1000',
        ], [
            'catatan_manajer.required' => 'Catatan alasan penolakan wajib diisi.',
        ]);

        if (! $relief->isPendingManajer()) {
            return redirect()->back()->with('error', 'Pengajuan ini belum/tidak dalam tahap approval Manajer.');
        }

        // GCG — pemisahan tugas: pengaju/perekomendasi tidak boleh menolak sendiri.
        if (PemisahanTugas::dilanggarSalahSatu([$relief->teller_id, $relief->ao_id], Auth::id())) {
            return redirect()->back()->with('error', PemisahanTugas::PESAN);
        }

        $nominalTazir = (float) $relief->nominal_tazir_awal;
        $kondisi = 'TANPA_DENDA';
        $saldoSisa = null;

        DB::transaction(function () use ($relief, $request, $nominalTazir, &$kondisi, &$saldoSisa) {
            // 1) Tolak keringanan
            $relief->update([
                'status' => TazirReliefRequest::STATUS_REJECTED,
                'nominal_tazir_disetujui' => $nominalTazir,
                'manajer_id' => Auth::id(),
                'catatan_manajer' => $request->catatan_manajer,
            ]);

            if ($nominalTazir <= 0) {
                return;
            }

            // 2) Record tunggakan ta'zir (idempoten per pengajuan)
            $tunggakan = TazirPenangguhan::firstOrNew([
                'tazir_relief_request_id' => $relief->id,
            ]);
            $tunggakan->fill([
                'jadwal_angsuran_id' => $relief->jadwal_angsuran_id,
                'pembiayaan_id' => $relief->pembiayaan_id,
                'anggota_id' => $relief->anggota_id,
                'nominal_tazir' => $nominalTazir,
            ]);

            // 3) Rekening Simpanan Sukarela anggota — HANYA untuk menautkan
            //    rekening sumber ke usulan. TIDAK ada pemotongan saldo di sini.
            $rekening = Rekening::where('anggota_id', $relief->anggota_id)
                ->whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_SUKARELA))
                ->where('status_rekening', 'Aktif')
                ->first();

            /* ══════════ USULKAN AUTO-DEBIT (TIDAK MEMOTONG) ══════════
             | KEBIJAKAN: "tidak ada auto-debit tanpa konfirmasi pengguna".
             | Manajer menolak keringanan (keputusan manusia), namun PEMOTONGAN
             | saldo tetap wajib melalui konfirmasi eksplisit di Pusat Konfirmasi
             | Auto-Debit. Karena itu saldo TIDAK disentuh di sini: tunggakan
             | dicatat, lalu diterbitkan USULAN kanal TAZIR. Pemotongan nyata
             | (mutasi + Dana Kebajikan 2-3001 + jurnal DEBET 2-1001 | KREDIT 2-3001)
             | baru terjadi saat usulan dikonfirmasi pengguna berwenang. */
            $tunggakan->fill([
                'is_tazir_paid' => false,
                'flag' => TazirPenangguhan::FLAG_PENDING_AUTODEBET_SUSULAN,
                'paid_at' => null,
                'transaksi_id' => null,
                'catatan' => 'Keringanan ditolak Manajer; menunggu konfirmasi '.
                                   "pemotongan ta'zir. ".($request->catatan_manajer ?: ''),
            ])->save();

            $relief->angsuran?->update(['status_tazir' => 'TUNGGAKAN']);

            // Terbitkan usulan ke Pusat Konfirmasi (idempoten per tunggakan).
            $this->autodebitService->usulkan(
                AutodebitUsulan::KANAL_TAZIR,
                (int) $tunggakan->id,
                $relief->anggota,
                $nominalTazir,
                [
                    'alasan' => 'KERINGANAN_DITOLAK',
                    'catatan' => "Usulan pemotongan ta'zir (keringanan ditolak Manajer) | ".
                                        ($relief->pembiayaan?->no_pembiayaan ?? '-'),
                    'referensi_tipe' => TazirPenangguhan::class,
                    'rekening_id' => $rekening?->id,
                ]
            );

            $kondisi = 'MENUNGGU_KONFIRMASI';
        });

        // Rekap log penolakan ta'zir
        Log::info('Rekap Penolakan Keringanan Ta\'zir', [
            'tazir_relief_request_id' => $relief->id,
            'anggota_id' => $relief->anggota_id,
            'pembiayaan_id' => $relief->pembiayaan_id,
            'nominal_tazir' => $nominalTazir,
            'kondisi' => $kondisi,
            'saldo_sukarela_setelah' => $saldoSisa,
            'manajer_id' => Auth::id(),
        ]);

        if ($kondisi === 'MENUNGGU_KONFIRMASI') {
            return redirect()->back()->with('success',
                'Keringanan ditolak. Pemotongan ta\'zir sebesar Rp '.
                number_format($nominalTazir, 0, ',', '.').
                ' DIAJUKAN ke Pusat Konfirmasi Auto-Debit — dana BELUM dipotong '.
                'dan menunggu konfirmasi pengguna berwenang.');
        }

        return redirect()->back()->with('warning',
            'Keringanan ta\'zir DITOLAK. Tidak ada ta\'zir yang perlu ditagihkan.');
    }
}
