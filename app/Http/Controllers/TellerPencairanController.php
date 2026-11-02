<?php

namespace App\Http\Controllers;

use App\Enums\DisbursementMethod;
use App\Models\JenisSimpanan;
use App\Models\Pembiayaan;
use App\Models\Rekening;
use App\Services\CoaResolver;
use App\Services\DisbursementService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Pencairan (dropping) pembiayaan berstatus DISETUJUI — pintu masuk Teller.
 *
 * Controller ini TIDAK lagi memuat logika akuntansi, pemetaan CoA, penjadwalan,
 * maupun perhitungan saldo: seluruhnya didelegasikan ke DisbursementService
 * (ZERO-HARDCODING, PSAK 108). Tugas controller tinggal:
 *   1. memvalidasi input request (metode + rekening tujuan) secara ketat,
 *   2. menyediakan data dropdown dinamis untuk form,
 *   3. menerjemahkan penolakan bisnis (RuntimeException) menjadi pesan redirect.
 */
class TellerPencairanController extends Controller
{
    public function __construct(private DisbursementService $disbursement) {}

    /**
     * Daftar pembiayaan berstatus 'disetujui' yang siap dicairkan.
     */
    public function index(CoaResolver $coaResolver)
    {
        $pembiayaan = Pembiayaan::with(['anggota.rekening', 'disahkanOleh'])
            ->statusIn([Pembiayaan::STATUS_DISETUJUI])
            ->orderBy('tgl_pencairan', 'asc')
            ->get();

        return view('teller.pencairan.index', [
            'pembiayaan' => $pembiayaan,
            'today' => today(),
            'metodePencairan' => DisbursementMethod::pilihan(),
            'bankOperasional' => $coaResolver->daftarBankOperasional(),
            'jenisSimpananSukarela' => JenisSimpanan::namaPencairan(),
        ]);
    }

    /**
     * Eksekusi pencairan — delegasi penuh ke DisbursementService.
     */
    public function cairkan(Request $request, int $id)
    {
        $data = $request->validate([
            'disbursement_method' => ['nullable', 'string', Rule::enum(DisbursementMethod::class)],
            'bank_account_id' => ['nullable', 'integer', 'min:1'],
            'savings_account_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $pembiayaan = Pembiayaan::with('anggota')->findOrFail($id);

        // Validasi jadwal pencairan (aturan koperasi, bukan aturan akuntansi).
        if (is_null($pembiayaan->tgl_pencairan)) {
            return redirect()->back()->with('error', 'Tanggal rencana pencairan belum ditetapkan oleh DPS.');
        }

        if (today()->lt($pembiayaan->tgl_pencairan)) {
            return redirect()->back()->with('error', sprintf(
                'Pencairan belum dapat dilakukan. Tanggal rencana pencairan: %s.',
                $pembiayaan->tgl_pencairan->format('d/m/Y')
            ));
        }

        $metode = $this->tentukanMetode($data['disbursement_method'] ?? null, $pembiayaan);
        $bankAccountId = $data['bank_account_id'] ?? null;
        $savingsAccountId = $data['savings_account_id'] ?? null;

        // Skema pindah buku: bila rekening tidak dikirim, ambil rekening
        // simpanan sukarela milik anggota pemohon secara dinamis.
        if ($metode === DisbursementMethod::TRANSFER_SAVINGS && ! $savingsAccountId) {
            $savingsAccountId = $this->rekeningSukarelaAnggota($pembiayaan)?->id;
        }

        try {
            $hasil = $this->disbursement->cairkan(
                $pembiayaan,
                $metode,
                Auth::id(),
                $bankAccountId,
                $savingsAccountId,
                today(),
            );
        } catch (QueryException $e) {
            // Error sistem (SQL/DB) TIDAK boleh disamarkan menjadi pesan
            // bisnis — biarkan framework melaporkan apa adanya.
            throw $e;
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('teller.pencairan-disetujui.index')->with(
            'success',
            sprintf(
                'Pembiayaan %s sebesar Rp %s berhasil dicairkan via %s. Jadwal angsuran telah diterbitkan.',
                $hasil->no_pembiayaan,
                number_format((float) $hasil->jumlah_pembiayaan, 0, ',', '.'),
                $metode->label()
            )
        );
    }

    /**
     * Metode pencairan efektif: request → tersimpan di pembiayaan → bawaan
     * alur lama (pindah buku ke Simpanan Sukarela).
     */
    private function tentukanMetode(?string $diminta, Pembiayaan $pembiayaan): DisbursementMethod
    {
        if ($diminta !== null && $diminta !== '') {
            return DisbursementMethod::from($diminta);
        }

        if ($pembiayaan->disbursement_method instanceof DisbursementMethod) {
            return $pembiayaan->disbursement_method;
        }

        return DisbursementMethod::TRANSFER_SAVINGS;
    }

    /** Rekening simpanan sukarela milik anggota pembiayaan (prioritas Aktif). */
    private function rekeningSukarelaAnggota(Pembiayaan $pembiayaan): ?Rekening
    {
        if (! $pembiayaan->anggota) {
            return null;
        }

        return Rekening::query()
            ->where('anggota_id', $pembiayaan->anggota->id)
            ->whereIn('jenis_rekening', JenisSimpanan::namaPencairan())
            ->orderByRaw("CASE WHEN status_rekening = 'Aktif' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();
    }
}
