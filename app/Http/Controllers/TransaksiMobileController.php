<?php

namespace App\Http\Controllers;

use App\Models\Rekening;
use App\Services\TransactionLimitService;
use App\Support\GuardSimpananModal;
use App\Support\HierarkiRole;
use App\Support\NomorDokumen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransaksiMobileController extends Controller
{
    public function __construct(private TransactionLimitService $limitService) {}

    public function setor(Request $request)
    {
        $request->validate([
            'rekening_id' => 'required|exists:rekening,id',
            'jumlah' => 'required|numeric|min:1000',
            'keterangan' => 'nullable|string|max:255',
        ]);

        $user = $request->user();

        // T-2 — hanya personel berkebijakan batas yang boleh memakai kanal ini.
        if (! HierarkiRole::tundukBatasOperasional($user?->role)) {
            return $this->peranTanpaBatas();
        }

        // K-01 (IDOR): anggota hanya boleh bertransaksi atas rekening MILIKNYA.
        $rekening = $this->rekeningMilikPengguna($request, (int) $request->rekening_id);
        if ($rekening instanceof JsonResponse) {
            return $rekening;
        }

        $result = $this->limitService->processTransactionWithLimitCheck(
            $user,
            'setor_tunai',
            (float) $request->jumlah,
            'tunai',
            [
                'no_transaksi' => NomorDokumen::kode('transaksi').'-'.date('YmdHis').'-'.strtoupper(substr(uniqid(), -4)),
                'rekening_id' => $rekening->id,
                'jenis_transaksi' => 'Setoran Tunai',
                'posisi' => 'Debet',
                'jumlah' => (float) $request->jumlah,
                'catatan' => $request->keterangan ?? 'Setor tunai via mobile',
                'waktu_transaksi' => now(),
            ]
        );

        return $this->responsHasil($result);
    }

    public function tarik(Request $request)
    {
        $request->validate([
            'rekening_id' => 'required|exists:rekening,id',
            'jumlah' => 'required|numeric|min:1000',
            'keterangan' => 'nullable|string|max:255',
        ]);

        // K-01 (IDOR): pastikan rekening benar-benar milik pemanggil SEBELUM
        // guard berikutnya dijalankan, agar tidak ada kebocoran informasi
        // (jenis rekening/akad) maupun eksekusi atas rekening orang lain.
        $rekening = $this->rekeningMilikPengguna($request, (int) $request->rekening_id);
        if ($rekening instanceof JsonResponse) {
            return $rekening;
        }

        // ALLOWLIST KETAT PENARIKAN TUNAI (Bagian 4 poin 1): hanya rekening
        // Simpanan Sukarela (CoA 2-1001) yang dapat ditarik tunai. Simpanan
        // Pokok (3-1001) & Simpanan Wajib (3-1002) adalah Modal/Ekuitas anggota
        // (PSAK 101 ¶ 31) — pengembaliannya hanya via modul `member_terminations`.
        try {
            GuardSimpananModal::pastikanRekeningDapatDitarikTunai($rekening, 'mobile.tarik');
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $user = $request->user();

        // T-2 — sama dengan setor(): peran pemakai jasa tidak boleh menembus
        // kebijakan batas yang memang tidak memuat batas untuk mereka.
        if (! HierarkiRole::tundukBatasOperasional($user?->role)) {
            return $this->peranTanpaBatas();
        }

        $result = $this->limitService->processTransactionWithLimitCheck(
            $user,
            'tarik_tunai',
            (float) $request->jumlah,
            'tunai',
            [
                'no_transaksi' => NomorDokumen::kode('transaksi').'-'.date('YmdHis').'-'.strtoupper(substr(uniqid(), -4)),
                'rekening_id' => $rekening->id,
                'jenis_transaksi' => 'Penarikan Tunai',
                'posisi' => 'Kredit',
                'jumlah' => (float) $request->jumlah,
                'catatan' => $request->keterangan ?? 'Tarik tunai via mobile',
                'waktu_transaksi' => now(),
            ]
        );

        return $this->responsHasil($result);
    }

    /**
     * Ambil rekening milik pengguna terautentikasi, atau kembalikan respons 403.
     *
     * Mencegah IDOR (OWASP A01): `rekening_id` datang dari klien sehingga tidak
     * boleh dipercaya begitu saja. Kepemilikan dicek secara berlapis:
     *   1. `anggota.user_id` menunjuk akun pengguna;
     *   2. cadangan pencocokan surel (kolom `anggota.user_id` sering kosong
     *      pada data lama — pola sama dengan `ApiDepositoController::bolehCetakBilyet`);
     *   3. pengguna dengan peran operasional (teller/pengurus/admin/manajer)
     *      diizinkan melayani rekening anggota mana pun.
     *
     * @return Rekening|JsonResponse
     */
    private function rekeningMilikPengguna(Request $request, int $rekeningId)
    {
        $user = $request->user();
        $rekening = Rekening::with('anggota')->find($rekeningId);

        if (! $rekening) {
            return response()->json(['success' => false, 'message' => 'Rekening tidak ditemukan.'], 404);
        }

        if (HierarkiRole::dalamKelompok($user->role ?? null, 'wali_data_rekening')) {
            return $rekening;
        }

        $anggota = $rekening->anggota;

        $milikSendiri = $anggota
            && (($anggota->user_id && (int) $anggota->user_id === (int) $user->id)
                || (filled($anggota->email)
                    && strcasecmp((string) $anggota->email, (string) $user->email) === 0));

        if (! $milikSendiri) {
            return response()->json([
                'success' => false,
                'message' => 'Rekening ini bukan milik Anda.',
            ], 403);
        }

        return $rekening;
    }

    /**
     * Tolak peran yang tidak tunduk pada kebijakan batas operasional (T-2).
     *
     * Memakai 403 — bukan 422 — agar pesan tidak membocorkan konfigurasi
     * kebijakan, dan konsisten dengan middleware `role:`/`izin:`.
     */
    private function peranTanpaBatas(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Peran Anda tidak berwenang melakukan transaksi tunai melalui kanal ini.',
        ], 403);
    }

    /**
     * Terjemahkan hasil `TransactionLimitService` menjadi respons HTTP.
     *
     * BUG-01: service mengembalikan kunci **`status`** (`SUCCESS`,
     * `PENDING_APPROVAL`, `REJECTED`, `error`) — BUKAN `success`. Sebelumnya
     * kode membaca `$result['success']` yang selalu `null` sehingga seluruh
     * permintaan mobile dijawab HTTP 422 meski dana sudah diproses → memicu
     * pengiriman ulang ganda. Pemetaan di bawah menjadikan kunci `status`
     * sebagai sumber kebenaran, sekaligus menambahkan alias boolean `success`
     * agar klien lama tetap kompatibel.
     */
    private function responsHasil(array $result)
    {
        $status = strtoupper((string) ($result['status'] ?? 'REJECTED'));

        $kode = match ($status) {
            'SUCCESS' => 200,
            'PENDING_APPROVAL' => 202,
            default => 422,
        };

        return response()->json(
            $result + ['success' => $kode === 200],
            $kode
        );
    }
}
