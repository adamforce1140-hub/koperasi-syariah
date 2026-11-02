<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Anggota;
use App\Models\Coa;
use App\Models\Deposito;
use App\Models\DepositoProduct;
use App\Models\JenisSimpanan;
use App\Models\KebijakanKoperasi;
use App\Models\User;
use App\Services\AutoJournalingService;
use App\Services\DepositoService;
use App\Services\MandatorySavingsService;
use App\Services\PendapatanService;
use App\Support\HierarkiRole;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * API Deposito / Simpanan Berjangka Syariah (akad Mudharabah Muthlaqah).
 *
 * Catatan aturan bisnis yang ditegakkan:
 *  - Sumber dana pembukaan adalah Rekening Simpanan Sukarela (CoA 2-1001),
 *    BUKAN Kas Teller.
 *  - `maturity_date` dihitung sistem dari start_date + tenor produk. Field
 *    tersebut tidak pernah diterima dari input pengguna.
 *  - Bagi hasil memakai nisbah (bukan bunga tetap) dan tidak melibatkan SHU.
 */
class DepositoController extends Controller
{
    public function __construct(
        private DepositoService $depositoService,
        private PendapatanService $pendapatanService,
    ) {}

    /**
     * GET /api/deposito/products
     * Daftar produk deposito yang aktif beserta estimasi simulasi standar.
     */
    public function products(): JsonResponse
    {
        // Hanya produk bertipe 'deposito' (PSAK 108). Master produk juga
        // menyimpan Simpanan Pokok/Wajib/Sukarela (PSAK 101) yang tidak punya
        // tenor/minimum setoran sehingga harus dikecualikan dari endpoint ini.
        $products = DepositoProduct::active()
            ->deposito()
            ->orderBy('tenor_months')
            ->get()
            ->map(fn (DepositoProduct $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'tenor_months' => (int) $p->tenor_months,
                'nisbah_anggota' => (float) $p->nisbah_anggota,
                'nisbah_koperasi' => (float) $p->nisbah_koperasi,
                'min_amount' => (float) $p->min_amount,
                'is_active' => (bool) $p->is_active,
                'label' => $p->label,
            ]);

        return $this->sukses($products, 'Daftar produk deposito aktif.');
    }

    /**
     * POST /api/deposito/simulate
     * Body: amount, product_id, start_date (opsional).
     */
    public function simulate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'product_id' => ['required', 'integer', 'exists:deposito_products,id'],
            'start_date' => ['nullable', 'date'],
        ]);

        try {
            $simulasi = $this->depositoService->calculateSimulation(
                (float) $validated['amount'],
                (int) $validated['product_id'],
                null,
                $validated['start_date'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->gagal($e->getMessage(), 422, $e->errors());
        }

        return $this->sukses($simulasi, 'Simulasi bagi hasil deposito berhasil dihitung.');
    }

    /**
     * POST /api/deposito/store
     * Body: member_id, product_id, source_saving_account_id, principal_amount,
     *       start_date (opsional), aro_mode (opsional: NON_ARO|ARO_POKOK|ARO_PLUS),
     *       is_aro (opsional, kompatibilitas lama).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'member_id' => ['required', 'integer', 'exists:anggota,id'],
            'product_id' => ['required', 'integer', 'exists:deposito_products,id'],
            'source_saving_account_id' => ['required', 'integer', 'exists:rekening,id'],
            // Batas minimum/maksimum nominal pembukaan rekening bersumber dari
            // parameter kebijakan koperasi (dapat diubah pengurus; tanpa hardcode).
            'principal_amount' => KebijakanKoperasi::aturanNominalPembukaan(),
            'start_date' => ['nullable', 'date'],
            'aro_mode' => ['nullable', Rule::in(Deposito::ARO_MODE)],
            'is_aro' => ['nullable', 'boolean'],
        ], [
            'aro_mode.in' => 'Mode ARO tidak dikenal. Pilihan sah: NON_ARO, ARO_POKOK, ARO_PLUS.',
        ] + KebijakanKoperasi::pesanValidasiNominalPembukaan('Nominal pokok deposito'));

        try {
            $deposito = $this->depositoService->createDeposito($validated + [
                'user_id' => $request->user()?->id,
            ]);
        } catch (ValidationException $e) {
            return $this->gagal($e->getMessage(), 422, $e->errors());
        } catch (\RuntimeException $e) {
            return $this->gagal($e->getMessage(), 409);
        }

        return $this->sukses($this->formatDeposito($deposito), 'Deposito berhasil dibuka.', 201);
    }

    /**
     * GET /api/deposito/member/{memberId}
     * Seluruh bilyet deposito milik anggota beserta produk dan riwayat bagi hasil.
     */
    public function member(int $memberId): JsonResponse
    {
        $anggota = Anggota::find($memberId);

        if (! $anggota) {
            return $this->gagal('Anggota tidak ditemukan.', 404);
        }

        $depositos = Deposito::with([
            'product',
            'sourceSavingAccount',
            'depositoRekening',
            'profitSharings' => fn ($q) => $q->orderByDesc('period_year')->orderByDesc('period_month'),
        ])
            ->milik($memberId)
            ->orderByDesc('start_date')
            ->get();

        return $this->sukses([
            'member' => [
                'id' => $anggota->id,
                'no_anggota' => $anggota->no_anggota,
                'no_cif' => $anggota->no_cif,
                'nama_lengkap' => $anggota->nama_lengkap,
            ],
            'total_deposito' => $depositos->count(),
            'total_pokok' => Setting::bulatkanUang($depositos->sum(fn ($d) => (float) $d->principal_amount)),
            'total_bagi_hasil' => Setting::bulatkanUang($depositos->sum(
                fn ($d) => (float) $d->profitSharings->sum('member_profit_amount')
            )),
            'depositos' => $depositos->map(fn (Deposito $d) => $this->formatDeposito($d)),
        ], 'Daftar deposito anggota.');
    }

    /* =====================================================================
     | KONFIGURASI DINAMIS COA & PRODUK (SINGLE SOURCE OF TRUTH)
     |===================================================================== */

    /**
     * GET /api/deposito/coa-config
     *
     * Mengembalikan parameter persentase yang berlaku SAAT INI dari Master
     * COA beserta daftar produk deposito aktif. Dipakai form UI untuk menarik
     * nisbah/margin secara real-time setiap kali jenis transaksi berubah,
     * sehingga nilai di layar selalu sinkron dengan Master COA — tanpa
     * menanam angka di JavaScript.
     */
    public function coaConfig(): JsonResponse
    {
        $kode = [
            'sukarela' => Deposito::COA_SIMPANAN_SUKARELA,
            'deposito' => Deposito::COA_SIMPANAN_BERJANGKA,
            'margin' => AutoJournalingService::PIUTANG_MURABAHAH,
            'pokok' => AutoJournalingService::SIMPANAN_POKOK,
            // PSAK 101: Simpanan Wajib = Modal Anggota. Nilai awal memakai
            // titik resolusi tunggal; blok di bawah tetap menyelaraskannya
            // dengan pemetaan tipe dinamis dari master produk.
            'wajib' => app(MandatorySavingsService::class)->akunSimpananWajib(),
        ];

        // PARAMETER DINAMIS: bila Pengurus mengubah pemetaan akun sebuah tipe
        // simpanan, nilai di atas harus ikut bergerak agar nisbah di layar
        // (dibaca per TIPE, bukan per nama jenis) tetap sinkron.
        foreach ([
            JenisSimpanan::TIPE_POKOK => 'pokok',
            JenisSimpanan::TIPE_WAJIB => 'wajib',
            JenisSimpanan::TIPE_SUKARELA => 'sukarela',
            JenisSimpanan::TIPE_BERJANGKA => 'deposito',
        ] as $tipe => $kunci) {
            $kodeSimpanan = JenisSimpanan::coaKodeBertipe($tipe);

            if ($kodeSimpanan !== null) {
                $kode[$kunci] = $kodeSimpanan;
            }
        }

        $coa = [];

        foreach ($kode as $kunci => $kodeAkun) {
            $akun = Coa::findByKode($kodeAkun);

            $coa[$kunci] = [
                'kode_akun' => $kodeAkun,
                'nama_akun' => $akun?->nama_akun,
                'has_percentage' => (bool) ($akun?->has_percentage ?? false),
                'percentage_type' => $akun?->percentage_type,
                'percentage_label' => $akun?->percentage_label,
                'default_percentage_member' => (float) ($akun?->persentaseAnggota() ?? 0),
                'default_percentage_institution' => (float) ($akun?->persentaseInstitusi() ?? 0),
            ];
        }

        $products = DepositoProduct::active()
            ->deposito()
            ->orderBy('tenor_months')
            ->get()
            ->map(fn (DepositoProduct $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'label' => $p->label,
                'tenor_months' => (int) $p->tenor_months,
                'nisbah_anggota' => (float) $p->nisbah_anggota,
                'nisbah_koperasi' => (float) $p->nisbah_koperasi,
                'min_amount' => (float) $p->min_amount,
                'allow_early_withdrawal' => (bool) $p->allow_early_withdrawal,
                'penalty_fee_type' => $p->penalty_fee_type,
                'penalty_fee_amount' => (float) $p->penalty_fee_amount,
                'forfeit_monthly_profit' => (bool) $p->forfeit_monthly_profit,
                'penalty_coa_id' => $p->coaPenalti(),
                'penalty_label' => $p->penalti_label,
            ])
            ->values();

        return $this->sukses([
            'coa' => $coa,
            'products' => $products,
        ], 'Konfigurasi COA & produk deposito.');
    }

    /* =====================================================================
     | PENCAIRAN SEBELUM JATUH TEMPO (BREAK DEPOSITO)
     |===================================================================== */

    /**
     * POST /api/deposito/{id}/early-withdrawal
     *
     * Mencairkan deposito sebelum jatuh tempo. Biaya penalti dihitung dari
     * aturan produk (`penalty_fee_type` fixed/percentage) dan seluruh rangkaian
     * auto-journal dikerjakan atomik di dalam DepositoService.
     *
     * Otorisasi berlapis (segregation of duties):
     *  1. Middleware route `web` + `auth` + `role:teller` menolak pengguna
     *     anonim dan peran selain teller (403).
     *  2. Pemeriksaan di sini mengulang penegakan aturan tersebut agar
     *     pemanggilan internal/AJAX lain tetap aman bila middleware berubah.
     */
    public function earlyWithdrawal(Request $request, int $id): JsonResponse
    {
        $deposito = Deposito::find($id);

        if (! $deposito) {
            return $this->gagal('Deposito tidak ditemukan.', 404);
        }

        if (! $this->bolehCairkanDini($request->user())) {
            return $this->gagal(
                'Akses ditolak. Pencairan dini deposito hanya dapat dilakukan oleh teller.',
                403
            );
        }

        try {
            $hasil = $this->depositoService->earlyWithdrawal($id, $request->user()?->id);
        } catch (ValidationException $e) {
            return $this->gagal($e->getMessage(), 422, $e->errors());
        } catch (\RuntimeException $e) {
            return $this->gagal($e->getMessage(), 409);
        }

        return $this->sukses([
            'deposito' => $this->formatDeposito($hasil['deposito']),
            'pokok' => $hasil['pokok'],
            'penalti' => $hasil['penalti'],
            'diterima_anggota' => $hasil['diterima_anggota'],
            'no_jurnal' => $hasil['jurnal']->no_jurnal,
        ], 'Deposito berhasil dicairkan sebelum jatuh tempo.');
    }

    /* =====================================================================
     | CETAK BILYET (PDF)
     |===================================================================== */

    /**
     * GET /api/deposito/{id}/print-bilyet
     *
     * Menampilkan stream PDF Bilyet Simpanan Berjangka Mudharabah langsung di
     * browser (Content-Disposition: inline) dengan nama berkas
     * `Bilyet-Deposito-{deposito_number}.pdf`.
     *
     * Otorisasi berlapis:
     *  1. Middleware route `web` + `auth` + `role:` menolak pengguna anonim dan
     *     peran yang tidak berhak (403).
     *  2. Pemeriksaan di sini menutup celah IDOR: peran selain pengurus/teller
     *     hanya boleh mencetak bilyet miliknya sendiri, sehingga menukar `id`
     *     pada URL tidak membocorkan data anggota lain.
     *
     * Bilyet hanya boleh dicetak setelah deposito terverifikasi (ACTIVE);
     * status PENDING/MATURED/CLOSED ditolak dengan pesan yang jelas.
     */
    public function generateBilyetPdf(Request $request, int $id): Response|JsonResponse
    {
        $deposito = Deposito::with('member')->find($id);

        if (! $deposito) {
            return $this->gagal('Deposito tidak ditemukan.', 404);
        }

        if (! $this->bolehCetakBilyet($request->user(), $deposito)) {
            return $this->gagal('Akses ditolak. Anda tidak berwenang mencetak bilyet deposito ini.', 403);
        }

        if ($deposito->status !== Deposito::STATUS_ACTIVE) {
            return $this->gagal(
                'Bilyet belum dapat dicetak. Deposito berstatus '.$deposito->status
                .' dan belum terverifikasi/aktif.',
                409
            );
        }

        try {
            $pdf = $this->depositoService->generateBilyetPdf($deposito->id);
        } catch (ValidationException $e) {
            return $this->gagal($e->getMessage(), 422, $e->errors());
        } catch (\RuntimeException $e) {
            return $this->gagal($e->getMessage(), 500);
        }

        Log::info('DepositoController: bilyet PDF diakses.', [
            'deposito_number' => $deposito->deposito_number,
            'user_id' => $request->user()?->id,
            'role' => $request->user()?->role,
        ]);

        return $pdf->stream($this->depositoService->namaFileBilyet($deposito));
    }

    /**
     * Boleh atau tidak pengguna mencairkan deposito sebelum jatuh tempo.
     *
     * Pencairan dini adalah transaksi kas (mengeluarkan dana koperasi), sehingga
     * berdasarkan prinsip segregation of duties hanya `teller` (petugas loket)
     * yang berwenang. Role lain — termasuk `ao`, `manajer`, `pengurus`, dan
     * `admin` — ditolak.
     */
    private function bolehCairkanDini(?User $user): bool
    {
        return HierarkiRole::dalamKelompok($user?->role, 'pencairan_dini');
    }

    /**
     * Boleh atau tidak pengguna mencetak bilyet milik deposito ini.
     *
     * - `pengurus` & `teller`: wali data koperasi, boleh mencetak semua bilyet.
     * - Peran lain: HANYA bila dirinya sendiri pemilik deposito tersebut.
     */
    private function bolehCetakBilyet(?User $user, Deposito $deposito): bool
    {
        if (! $user) {
            return false;
        }

        if (HierarkiRole::dalamKelompok($user->role, 'pencetak_bilyet')) {
            return true;
        }

        $anggota = $deposito->member;

        if (! $anggota) {
            return false;
        }

        // Kepemilikan langsung: anggota terhubung ke akun pengguna.
        if ($anggota->user_id && (int) $anggota->user_id === (int) $user->id) {
            return true;
        }

        // Fallback pencocokan identitas — kolom `anggota.user_id` kerap kosong
        // pada data lama, sehingga kecocokan surel dipakai sebagai penanda.
        return filled($anggota->email)
            && strcasecmp((string) $anggota->email, (string) $user->email) === 0;
    }

    /* =====================================================================
     | DISTRIBUSI BAGI HASIL DARI LABA RIIL (R8)
     |===================================================================== */

    /**
     * POST /api/deposito/distribusi-bagi-hasil
     *
     * R8 — Distribusi bagi hasil deposito berbasis pendapatan investasi riil
     * periode, menggantikan penerimaan angka `bagi_hasil` manual.
     *
     * Pengguna TIDAK mengirim nominal pendapatan. Sistem yang menghitung dari
     * `PendapatanService::pendapatanInvestasiBulanan()` (margin + bagi hasil +
     * ujroh, ta'zir dikecualikan) lalu membagikan proporsional ke seluruh
     * deposito ACTIVE dengan nisbah masing-masing.
     *
     * Otorisasi: `pengurus`/`manajer` (pengelola kebijakan bagi hasil) —
     * dijalankan lewat middleware rute.
     */
    public function distribusiBagiHasil(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => 'required|integer|min:2000|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $year = (int) $data['year'];
        $month = (int) $data['month'];

        // Nominal pendapatan TIDAK diterima dari pengguna — sistem menghitungnya
        // dari pendapatan investasi riil periode (margin + bagi hasil + ujroh).
        $pendapatan = $this->pendapatanService->pendapatanInvestasiBulanan($year, $month);

        try {
            $hasil = $this->depositoService->bagiHasilPeriode($year, $month, $pendapatan['total'], $request->user()?->id);
        } catch (ValidationException $e) {
            return $this->gagal($e->getMessage(), 422, $e->errors());
        } catch (\RuntimeException $e) {
            return $this->gagal($e->getMessage(), 409);
        }

        return $this->sukses(
            $hasil + ['rincian_pendapatan' => $pendapatan],
            'Bagi hasil periode '.$hasil['periode'].' selesai diproses.'
        );
    }

    /* =====================================================================
     | HELPER
     |===================================================================== */

    /** Bentuk payload deposito yang konsisten untuk seluruh endpoint. */
    private function formatDeposito(Deposito $deposito): array
    {
        $payload = [
            'id' => $deposito->id,
            'deposito_number' => $deposito->deposito_number,
            'member_id' => $deposito->member_id,
            'product_id' => $deposito->product_id,
            'product_name' => $deposito->product?->name,
            'source_saving_account_id' => $deposito->source_saving_account_id,
            'source_no_rekening' => $deposito->sourceSavingAccount?->no_rekening,
            'deposito_rekening_id' => $deposito->deposito_rekening_id,
            'deposito_no_rekening' => $deposito->depositoRekening?->no_rekening,
            'principal_amount' => (float) $deposito->principal_amount,
            'principal_formatted' => $deposito->principal_formatted,
            'nisbah_anggota' => (float) $deposito->nisbah_anggota,
            'nisbah_koperasi' => (float) $deposito->nisbah_koperasi,
            'start_date' => Carbon::parse($deposito->start_date)->toDateString(),
            'maturity_date' => Carbon::parse($deposito->maturity_date)->toDateString(),
            'tenor_months' => $deposito->tenorMonths(),
            'is_aro' => (bool) $deposito->is_aro,
            'aro_mode' => $deposito->aro_mode ?: Deposito::ARO_NON,
            'aro_label' => $deposito->aro_label,
            'roll_count' => (int) $deposito->roll_count,
            'last_rolled_at' => optional($deposito->last_rolled_at)->toDateTimeString(),
            'status' => $deposito->status,
            'sudah_jatuh_tempo' => $deposito->sudahJatuhTempo(),
            'sisa_hari' => $deposito->sisaHari(),
        ];

        if ($deposito->relationLoaded('profitSharings')) {
            $payload['profit_sharings'] = $deposito->profitSharings->map(fn ($s) => [
                'id' => $s->id,
                'period_month' => (int) $s->period_month,
                'period_year' => (int) $s->period_year,
                'nama_periode' => $s->nama_periode,
                'gross_koperasi_revenue' => (float) $s->gross_koperasi_revenue,
                'member_profit_amount' => (float) $s->member_profit_amount,
                'member_profit_formatted' => $s->member_profit_formatted,
                'payout_date' => optional($s->payout_date)->toDateTimeString(),
                'status' => $s->status,
                'journal_id' => $s->journal_id,
            ])->values();

            $payload['total_bagi_hasil_diterima'] = Setting::bulatkanUang(
                (float) $deposito->profitSharings->sum('member_profit_amount'));
        }

        return $payload;
    }

    private function sukses(mixed $data, string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    private function gagal(string $message, int $status, array $errors = []): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }
}
