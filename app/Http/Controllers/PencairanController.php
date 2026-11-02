<?php

namespace App\Http\Controllers;

use App\Enums\DisbursementMethod;
use App\Models\AuditLog;
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
 * Pencairan pembiayaan (status disetujui / pending pencairan / disahkan).
 *
 * Seluruh logika pencairan — pemetaan CoA dinamis, buku pembantu kas/bank/
 * simpanan, penjadwalan angsuran sadar hari libur, dan jurnal PSAK 108 —
 * berada di DisbursementService. Controller hanya memvalidasi input dan
 * menerjemahkan penolakan bisnis menjadi pesan redirect.
 */
class PencairanController extends Controller
{
    public function __construct(
        private DisbursementService $disbursement,
        private CoaResolver $coaResolver,
    ) {}

    /**
     * Daftar pengajuan pembiayaan yang siap dicairkan.
     */
    public function index(Request $request)
    {
        $query = Pembiayaan::with([
            'anggota.rekening' => fn ($q) => $q->whereIn('jenis_rekening', JenisSimpanan::namaPencairan()),
            'produk',
        ])
            ->statusIn([
                Pembiayaan::STATUS_DISETUJUI,
                Pembiayaan::STATUS_PENDING_PENCAIRAN,
                Pembiayaan::STATUS_DISAHKAN,
            ])
            ->orderByDesc('updated_at');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('no_pembiayaan', 'like', "%{$search}%")
                    ->orWhereHas('anggota', function ($qa) use ($search) {
                        $qa->where('nama_lengkap', 'like', "%{$search}%")
                            ->orWhere('no_anggota', 'like', "%{$search}%");
                    });
            });
        }

        $pembiayaans = $query->paginate(10)->withQueryString();

        return view('teller.pencairan.index', [
            'pembiayaans' => $pembiayaans,
            'today' => today(),
            'metodePencairan' => DisbursementMethod::pilihan(),
            'bankOperasional' => $this->coaResolver->daftarBankOperasional(),
            'jenisSimpananSukarela' => JenisSimpanan::namaPencairan(),
        ]);
    }

    /**
     * Eksekusi pencairan — delegasi penuh ke DisbursementService.
     */
    public function prosesPencairan(Request $request, $id)
    {
        $data = $request->validate([
            'disbursement_method' => ['nullable', 'string', Rule::enum(DisbursementMethod::class)],
            'bank_account_id' => ['nullable', 'integer', 'min:1'],
            'savings_account_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $pembiayaan = Pembiayaan::with('anggota')->findOrFail($id);

        if (! $pembiayaan->isStatus([
            Pembiayaan::STATUS_DISETUJUI,
            Pembiayaan::STATUS_PENDING_PENCAIRAN,
            Pembiayaan::STATUS_DISAHKAN,
        ])) {
            return redirect()->back()->with('error', 'Pembiayaan ini tidak dalam status siap dicairkan.');
        }

        if (! $pembiayaan->anggota) {
            return redirect()->back()->with('error', 'Data anggota pembiayaan tidak ditemukan.');
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
            );
        } catch (QueryException $e) {
            // Error sistem (SQL/DB) TIDAK boleh disamarkan menjadi pesan
            // bisnis — biarkan framework melaporkan apa adanya.
            throw $e;
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            /*
             * Kegagalan tak terduga (mis. TypeError dari data master yang cacat)
             * TIDAK BOLEH lolos tanpa jejak: sebelumnya pengecualian jenis ini
             * keluar dari method sebelum redirect, sehingga tidak pernah ada
             * pesan sukses — tetapi juga tidak ada pesan error yang jelas bagi
             * teller. Catat ke log dan kembalikan pesan kegagalan yang eksplisit
             * agar teller tahu pencairan TIDAK berhasil.
             */
            report($e);

            return redirect()->back()->with(
                'error',
                'Pencairan gagal karena kesalahan tak terduga: '.$e->getMessage()
            );
        }

        $rekeningTujuan = $metode === DisbursementMethod::TRANSFER_SAVINGS
            ? $savingsAccountId
            : null;

        AuditLog::record(
            'Pencairan Pembiayaan',
            (float) $hasil->jumlah_pembiayaan,
            $rekeningTujuan,
            sprintf(
                'Pencairan dana pembiayaan %s sebesar Rp %s kepada %s via %s.',
                $hasil->no_pembiayaan,
                number_format((float) $hasil->jumlah_pembiayaan, 0, ',', '.'),
                $pembiayaan->anggota->nama_lengkap,
                $metode->label()
            )
        );

        return redirect()->route('teller.pencairan.index')->with(
            'success',
            sprintf(
                'Pembiayaan sebesar Rp %s berhasil dicairkan kepada %s via %s. Jadwal angsuran telah di-generate.',
                number_format((float) $hasil->jumlah_pembiayaan, 0, ',', '.'),
                $pembiayaan->anggota->nama_lengkap,
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
