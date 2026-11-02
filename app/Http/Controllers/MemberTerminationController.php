<?php

namespace App\Http\Controllers;

use App\Models\Anggota;
use App\Models\MemberTermination;
use App\Services\ExitDocumentService;
use App\Services\ExitKeanggotaanService;
use App\Support\GuardSimpananModal;
use App\Support\Izin;
use App\Support\PiiHasher;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * MODUL PENGUNDURAN DIRI / EXIT KEANGGOTAAN (`member_terminations`).
 *
 * Ini adalah SATU-SATUNYA permukaan HTTP yang sah untuk mengembalikan dana
 * Simpanan Pokok (CoA 3-1001) & Simpanan Wajib (CoA 3-1002). Kedua akun itu
 * adalah MODAL/EKUITAS KOPERASI (PSAK 101 ¶ 31); penarikan rutinnya sudah
 * diblokir total di lapisan lain oleh {@see GuardSimpananModal}.
 *
 * Controller ini sengaja TIDAK menghitung nominal apa pun sendiri: seluruh
 * angka, kelayakan, idempotensi jurnal, dan perubahan status anggota
 * dikendalikan {@see ExitKeanggotaanService}. Controller hanya:
 *   1. memvalidasi masukan,
 *   2. memanggil service,
 *   3. menerjemahkan RuntimeException service menjadi pesan UI/HTTP yang jelas,
 *   4. mencatat percobaan yang gagal (audit).
 */
class MemberTerminationController extends Controller
{
    public function __construct(
        private ExitKeanggotaanService $exit,
        private ExitDocumentService $dokumen,
    ) {}

    /**
     * Otorisasi berlapis di dalam controller.
     *
     * Rute sudah dijaga middleware `izin:<nama>`, tetapi aksi keuangan tetap
     * diverifikasi ulang di sini agar tidak bisa ditembus bila middleware
     * berubah/dinonaktifkan (pertahanan berlapis). Sumber peran tetap SSOT
     * `config/izin.php` — bukan daftar literal kedua.
     */
    private function authorizeIzin(string $nama, string $pesan): void
    {
        $peran = (string) (Auth::user()?->role ?? '');

        abort_unless(in_array($peran, Izin::peran($nama), true), 403, $pesan);
    }

    /* =====================================================================
     | DAFTAR & DETAIL
     |===================================================================== */

    public function index(Request $request)
    {
        $query = MemberTermination::query()->with(['anggota', 'diprosesOleh']);

        if ($request->filled('status') && in_array($request->status, MemberTermination::STATUS_LIST, true)) {
            $query->where('status', $request->status);
        }

        if ($request->filled('cari')) {
            $cari = $request->cari;
            $query->where(function ($q) use ($cari) {
                $q->where('no_terminasi', 'like', "%{$cari}%")
                    ->orWhereHas('anggota', fn ($a) => $a
                        ->where('nama_lengkap', 'like', "%{$cari}%")
                        ->orWhere('no_anggota', 'like', "%{$cari}%"));
            });
        }

        $daftar = $query->orderByDesc('created_at')->paginate(20)->withQueryString();

        $ringkasan = [
            'diajukan' => MemberTermination::query()
                ->whereIn('status', [MemberTermination::STATUS_DIAJUKAN, MemberTermination::STATUS_MENUNGGU_APPROVAL])
                ->count(),
            'disetujui' => MemberTermination::query()->where('status', MemberTermination::STATUS_DISETUJUI)->count(),
            'ditotal' => (float) MemberTermination::query()
                ->where('status', MemberTermination::STATUS_DISETUJUI)
                ->sum('total_dikembalikan'),
        ];

        return view('member-terminations.index', compact('daftar', 'ringkasan'));
    }

    public function show(MemberTermination $terminasi)
    {
        $terminasi->load(['anggota.rekening', 'anggota', 'diajukanOleh', 'diprosesOleh', 'disetujuiOleh', 'jurnal', 'transaksi', 'approvalLogs.user']);

        $anggota = $terminasi->anggota;

        // Pratinjau kelayakan — TIDAK melempar exception, hanya informasi UI.
        $kendala = $anggota ? $this->exit->kendalaExit($anggota) : [];
        $layak = $kendala === [];

        $saldoPokok = $anggota ? $this->exit->saldoSimpananPokok($anggota) : 0.0;
        $saldoWajib = $anggota ? $this->exit->saldoSimpananWajib($anggota) : 0.0;

        // Rincian lengkap (sukarela, deposito, akrual, zakat, biaya admin).
        // Parameter sistem yang hilang TIDAK boleh mematikan halaman detail —
        // tampilkan null dan biarkan UI memperingatkan operator.
        $rincian = null;
        $errorRincian = null;

        if ($anggota) {
            try {
                $rincian = $this->exit->rincianSaldo(
                    $anggota,
                    $terminasi->tanggal_pengunduran_diri
                        ? Carbon::parse($terminasi->tanggal_pengunduran_diri)
                        : null
                );
            } catch (RuntimeException $e) {
                $errorRincian = $e->getMessage();
            }
        }

        return view('member-terminations.show', [
            'terminasi' => $terminasi,
            'anggota' => $anggota,
            'kendala' => $kendala,
            'layak' => $layak,
            'saldoPokok' => $saldoPokok,
            'saldoWajib' => $saldoWajib,
            'total' => $saldoPokok + $saldoWajib,
            'rincian' => $rincian,
            'errorRincian' => $errorRincian,
        ]);
    }

    /* =====================================================================
     | PENGAJUAN EXIT
     |===================================================================== */

    public function create(Request $request)
    {
        $this->authorizeIzin('terminasi.pengajuan', 'Peran Anda tidak berwenang mengajukan pengunduran diri anggota.');

        $anggotaTerpilih = null;

        if ($request->filled('anggota_id')) {
            $anggotaTerpilih = Anggota::query()->find($request->anggota_id);
        }

        return view('member-terminations.create', [
            'anggotaTerpilih' => $anggotaTerpilih,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeIzin('terminasi.pengajuan', 'Peran Anda tidak berwenang mengajukan pengunduran diri anggota.');

        $validated = $request->validate([
            'anggota_id' => 'required|integer|exists:anggota,id',
            'tanggal_pengunduran_diri' => 'nullable|date',
            'catatan' => 'nullable|string|max:1000',
        ]);

        $anggota = Anggota::query()->findOrFail($validated['anggota_id']);

        try {
            $terminasi = $this->exit->ajukan($anggota, [
                'tanggal_pengunduran_diri' => $validated['tanggal_pengunduran_diri'] ?? null,
                'catatan' => $validated['catatan'] ?? null,
            ], Auth::id());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('member-terminations.show', $terminasi->id)
            ->with('success', sprintf(
                'Pengajuan exit keanggotaan %s berhasil dibuat (status: %s). %s',
                $terminasi->no_terminasi,
                $terminasi->status_label,
                $terminasi->menungguApproval()
                    ? 'Pencairan dana menunggu persetujuan Manajer sebelum dapat dieksekusi.'
                    : 'Lanjutkan ke proses pengembalian dana setelah seluruh kewajiban lunas.'
            ));
    }

    /* =====================================================================
     | PENCARIAN ANGGOTA REAL-TIME (NIK / No. CIF)
     |===================================================================== */

    /**
     * Cari anggota berdasarkan NIK atau No. CIF lalu kembalikan pratinjau
     * kelayakan + rincian saldo sebagai JSON.
     *
     * NIK TIDAK dicari pada kolom terenkripsi (non-deterministik) melainkan
     * lewat blind index `nik_hash` — lihat {@see PiiHasher}. NIK yang
     * dikembalikan ke klien SELALU disamarkan (UU PDP No. 27/2022:
     * minimalisasi data); operator tidak perlu melihat NIK penuh untuk
     * memverifikasi identitas.
     */
    public function cariAnggota(Request $request)
    {
        $this->authorizeIzin('terminasi.pengajuan', 'Peran Anda tidak berwenang mencari data anggota.');

        $validated = $request->validate([
            'q' => 'required|string|min:3|max:50',
        ], [
            'q.required' => 'Masukkan NIK atau No. CIF anggota.',
            'q.min' => 'Pencarian minimal 3 karakter.',
        ]);

        $kata = trim((string) $validated['q']);

        $anggota = Anggota::query()
            ->where(function ($query) use ($kata) {
                $query->where('no_cif', $kata)
                    ->orWhere('no_anggota', $kata)
                    ->orWhere('nik_hash', PiiHasher::hash($kata));
            })
            ->first();

        // Pencarian sebagian No. CIF (mis. "CIF-2026").
        if (! $anggota) {
            $pola = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $kata);

            $anggota = Anggota::query()
                ->where('no_cif', 'like', $pola.'%')
                ->orderBy('no_cif')
                ->first();
        }

        if (! $anggota) {
            return response()->json([
                'found' => false,
                'message' => 'Anggota dengan NIK / No. CIF tersebut tidak ditemukan.',
            ]);
        }

        $kendala = [];

        if ($anggota->isTerminated()) {
            $kendala[] = 'Anggota sudah berstatus TERMINATED (keluar resmi dari koperasi).';
        }

        $kendala = array_merge($kendala, $this->exit->kendalaExit($anggota));

        $pending = MemberTermination::query()
            ->where('anggota_id', $anggota->id)
            ->menunggu()
            ->latest('id')
            ->first();

        if ($pending) {
            $kendala[] = sprintf(
                'Sudah ada pengajuan exit keanggotaan %s berstatus %s yang belum selesai.',
                $pending->no_referensi ?? $pending->no_terminasi,
                $pending->status_label
            );
        }

        $rincian = null;
        $errorRincian = null;

        try {
            $rincian = $this->exit->rincianSaldo($anggota);
        } catch (RuntimeException $e) {
            $errorRincian = $e->getMessage();
            $kendala[] = $errorRincian;
        }

        $butuhApproval = $rincian !== null
            && $this->exit->butuhApproval((float) $rincian['total_net']);

        return response()->json([
            'found' => true,
            'anggota' => [
                'id' => (int) $anggota->id,
                'no_anggota' => $anggota->no_anggota,
                'no_cif' => $anggota->no_cif,
                'nama_lengkap' => $anggota->nama_lengkap,
                'alamat' => $anggota->alamat,
                'status' => $anggota->status_anggota,
                'status_label' => $anggota->status_label,
                'nik_masked' => $this->samarkanNik($anggota->nik),
                'tanggal_gabung' => $anggota->tanggal_gabung?->toDateString(),
            ],
            'kendala' => array_values($kendala),
            'boleh_ajukan' => $kendala === [],
            'pending' => $pending ? [
                'id' => (int) $pending->id,
                'no_referensi' => $pending->no_referensi,
                'status' => $pending->status,
                'status_label' => $pending->status_label,
            ] : null,
            'rincian' => $rincian,
            'error_rincian' => $errorRincian,
            'butuh_approval' => $butuhApproval,
            'limit' => Setting::highValueWithdrawalLimit(),
        ]);
    }

    /** Samarkan NIK: hanya 4 digit terakhir yang ditampilkan. */
    private function samarkanNik(?string $nik): ?string
    {
        if ($nik === null || $nik === '') {
            return null;
        }

        $panjang = strlen($nik);

        if ($panjang <= 4) {
            return str_repeat('*', $panjang);
        }

        return str_repeat('*', $panjang - 4).substr($nik, -4);
    }

    /* =====================================================================
     | DOKUMEN BUKTI PENUTUPAN & TANDA TERIMA UANG
     |===================================================================== */

    /**
     * Unduh Bukti Penutupan Keanggotaan & Tanda Terima Uang (PDF A4).
     *
     * Hanya tersedia untuk dokumen yang SUDAH diposting jurnalnya — angka
     * pada dokumen berasal dari kolom nominal hasil posting, bukan hitungan
     * ulang, sehingga mustahil berbeda dengan buku besar.
     */
    public function buktiPdf(MemberTermination $terminasi)
    {
        $this->authorizeIzin('terminasi.pengajuan', 'Peran Anda tidak berwenang mencetak dokumen penutupan keanggotaan.');

        if ($tolak = $this->pastikanDokumenFinal($terminasi)) {
            return $tolak;
        }

        try {
            $pdf = $this->dokumen->pdf($terminasi);
        } catch (Throwable $e) {
            Log::error('EXIT_DOKUMEN_PDF_GAGAL', [
                'terminasi_id' => $terminasi->id,
                'no_referensi' => $terminasi->no_referensi,
                'user_id' => Auth::id(),
                'pesan' => $e->getMessage(),
            ]);

            return back()->with('error', 'Dokumen PDF gagal dibuat: '.$e->getMessage());
        }

        return $pdf->download($this->dokumen->namaFile($terminasi));
    }

    /**
     * Tampilkan struk thermal 80 mm (otomatis membuka dialog cetak browser).
     */
    public function buktiThermal(MemberTermination $terminasi)
    {
        $this->authorizeIzin('terminasi.pengajuan', 'Peran Anda tidak berwenang mencetak dokumen penutupan keanggotaan.');

        if ($tolak = $this->pastikanDokumenFinal($terminasi)) {
            return $tolak;
        }

        return response()->view(
            'member-terminations.bukti-thermal',
            $this->dokumen->dataThermal($terminasi)
        );
    }

    /**
     * Gerbang dokumen: hanya setelah dana benar-benar dicairkan & jurnal
     * diposting. Mencegah pencetakan "tanda terima" atas dana yang belum
     * dibayarkan (risiko penyalahgunaan dokumen).
     */
    private function pastikanDokumenFinal(MemberTermination $terminasi)
    {
        if ($terminasi->sudahDiposting() && $terminasi->status === MemberTermination::STATUS_DISETUJUI) {
            return null;
        }

        return redirect()
            ->route('member-terminations.show', $terminasi->id)
            ->with('error', 'Dokumen bukti penutupan hanya dapat dicetak setelah pengembalian dana dieksekusi dan jurnal diposting.');
    }

    /* =====================================================================
     | EKSEKUSI PENGEMBALIAN DANA + AUTO-JOURNAL PSAK 108
     |===================================================================== */

    /**
     * Jalankan pengembalian dana Simpanan Pokok & Wajib.
     *
     * Metode: CASH (tunai via teller → KREDIT 1-1001) atau TRANSFER_BANK
     * (→ KREDIT 1-1002 Kas di Bank, wajib nomor rekening tujuan).
     */
    public function eksekusi(Request $request, MemberTermination $terminasi)
    {
        $this->authorizeIzin('terminasi.eksekusi', 'Peran Anda tidak berwenang mencairkan dana pengunduran diri anggota.');

        $validated = $request->validate([
            'metode' => 'required|in:'.implode(',', MemberTermination::METODE_LIST),
            'nomor_rekening_tujuan' => 'nullable|string|max:60|required_if:metode,'.MemberTermination::METODE_TRANSFER_BANK,
            'nama_bank_tujuan' => 'nullable|string|max:100|required_if:metode,'.MemberTermination::METODE_TRANSFER_BANK,
        ], [
            'nomor_rekening_tujuan.required_if' => 'Nomor rekening tujuan wajib diisi untuk metode Transfer Bank.',
            'nama_bank_tujuan.required_if' => 'Nama bank tujuan wajib diisi untuk metode Transfer Bank.',
        ]);

        try {
            // Data rekening tujuan diikutsertakan ke dalam transaksi eksekusi
            // sehingga ikut di-rollback bila pencairan gagal (tidak ada
            // perubahan data setengah jadi).
            $terminasi = $this->exit->eksekusi(
                $terminasi,
                $validated['metode'],
                Auth::id(),
                $validated['nomor_rekening_tujuan'] ?? null,
                $validated['nama_bank_tujuan'] ?? null
            );
        } catch (RuntimeException $e) {
            // Termasuk: kewajiban belum lunas → exit dibatalkan otomatis.
            Log::warning('EXIT_KEANGGOTAAN_GAGAL', [
                'terminasi_id' => $terminasi->id,
                'no_terminasi' => $terminasi->no_terminasi,
                'metode' => $validated['metode'],
                'user_id' => Auth::id(),
                'pesan' => $e->getMessage(),
            ]);

            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('EXIT_KEANGGOTAAN_ERROR', [
                'terminasi_id' => $terminasi->id,
                'pesan' => $e->getMessage(),
            ]);

            return back()->with('error',
                'Pengembalian dana gagal: '.$e->getMessage()
                .' Tidak ada perubahan saldo, jurnal, maupun status keanggotaan.');
        }

        return redirect()
            ->route('member-terminations.show', $terminasi->id)
            ->with('success', sprintf(
                'Pengembalian dana BERHASIL. Total Rp %s dibayarkan via %s. '
                .'Jurnal PSAK 108 (no. %s) telah diposting dan status anggota kini TERMINATED.',
                number_format((float) $terminasi->total_dikembalikan, 2, ',', '.'),
                $terminasi->metode_label,
                $terminasi->no_jurnal ?? '-'
            ));
    }

    /* =====================================================================
     | PENOLAKAN & PEMBATALAN
     |===================================================================== */

    public function tolak(Request $request, MemberTermination $terminasi)
    {
        $this->authorizeIzin('terminasi.eksekusi', 'Peran Anda tidak berwenang menolak pengajuan pengunduran diri.');

        $validated = $request->validate([
            'alasan_penolakan' => 'required|string|max:1000',
        ]);

        try {
            $terminasi = $this->exit->tolak($terminasi, $validated['alasan_penolakan'], Auth::id());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('member-terminations.show', $terminasi->id)
            ->with('success', 'Pengajuan exit keanggotaan '.$terminasi->no_terminasi.' telah DITOLAK.');
    }

    public function batalkan(Request $request, MemberTermination $terminasi)
    {
        $this->authorizeIzin('terminasi.eksekusi', 'Peran Anda tidak berwenang membatalkan pengajuan pengunduran diri.');

        $validated = $request->validate([
            'alasan_penolakan' => 'nullable|string|max:1000',
        ]);

        try {
            $terminasi = $this->exit->batalkan($terminasi, $validated['alasan_penolakan'] ?? null, Auth::id());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('member-terminations.show', $terminasi->id)
            ->with('success', 'Pengajuan exit keanggotaan '.$terminasi->no_terminasi.' telah DIBATALKAN.');
    }

    /* =====================================================================
     | PERSETUJUAN MANAJER (HIGH-VALUE WITHDRAWAL)
     |===================================================================== */

    public function setujui(Request $request, MemberTermination $terminasi)
    {
        $this->authorizeIzin('terminasi.approval', 'Hanya Manajer yang berwenang menyetujui pencairan bernilai tinggi.');

        $validated = $request->validate([
            'catatan' => 'nullable|string|max:1000',
        ]);

        try {
            $terminasi = $this->exit->setujui($terminasi, $validated['catatan'] ?? null, Auth::id());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('member-terminations.show', $terminasi->id)
            ->with('success', 'Pengajuan '.$terminasi->no_referensi.' telah DISETUJUI. '
                .'Teller kini dapat mencairkan dana sesuai metode yang dipilih.');
    }

    public function tolakApproval(Request $request, MemberTermination $terminasi)
    {
        $this->authorizeIzin('terminasi.approval', 'Hanya Manajer yang berwenang menolak pencairan bernilai tinggi.');

        $validated = $request->validate([
            'alasan_penolakan' => 'required|string|max:1000',
        ], [
            'alasan_penolakan.required' => 'Alasan penolakan wajib diisi.',
        ]);

        try {
            $terminasi = $this->exit->tolakApproval($terminasi, $validated['alasan_penolakan'], Auth::id());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('member-terminations.show', $terminasi->id)
            ->with('success', 'Pengajuan '.$terminasi->no_referensi.' telah DITOLAK oleh Manajer.');
    }
}
