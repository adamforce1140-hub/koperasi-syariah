<?php

namespace App\Http\Controllers;

use App\Models\Jurnal;
use App\Services\AutoJournalingService;
use App\Support\PemisahanTugas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class JurnalController extends Controller
{
    public function __construct(private AutoJournalingService $journalService) {}

    public function index(Request $request): JsonResponse
    {
        $query = Jurnal::with(['postedBy', 'createdBy', 'transaksi', 'pembiayaan.anggota'])
            ->orderBy('tanggal_jurnal', 'desc')
            ->orderBy('id', 'desc');

        if ($request->has('jenis') && $request->jenis !== '') {
            $query->where('jenis_jurnal', $request->jenis);
        }

        if ($request->has('status') && $request->status !== '') {
            $query->where('status', $request->status);
        }

        if ($request->has('tanggal_awal') && $request->has('tanggal_akhir')
            && $request->tanggal_awal !== '' && $request->tanggal_akhir !== '') {
            $query->whereBetween('tanggal_jurnal', [$request->tanggal_awal, $request->tanggal_akhir]);
        }

        if ($request->has('transaksi_id') && $request->transaksi_id !== '') {
            $query->where('transaksi_id', $request->transaksi_id);
        }

        if ($request->has('pembiayaan_id') && $request->pembiayaan_id !== '') {
            $query->where('pembiayaan_id', $request->pembiayaan_id);
        }

        if ($request->has('search') && $request->search !== '') {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('no_jurnal', 'like', "%{$search}%")
                    ->orWhere('keterangan', 'like', "%{$search}%")
                    ->orWhere('no_ref', 'like', "%{$search}%");
            });
        }

        $perPage = $this->perPage($request);
        $jurnals = $perPage !== null
            ? $query->paginate($perPage)
            : $query->get();

        return response()->json([
            'success' => true,
            'message' => 'Data jurnal berhasil diambil',
            'data' => $jurnals,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'no_jurnal' => 'required|string|max:30|unique:jurnal,no_jurnal',
            'tanggal_jurnal' => 'required|date',
            'jenis_jurnal' => 'required|in:Jurnal Umum,Jurnal Penerimaan Kas,Jurnal Pengeluaran Kas,Jurnal Penyesuaian,Jurnal Penutup,Jurnal Pembalik,Jurnal Otomatis,Jurnal Transaksi',
            'transaksi_id' => 'nullable|exists:transaksi,id',
            'pembiayaan_id' => 'nullable|exists:pembiayaan,id',
            'sumber' => 'nullable|string|max:50',
            'no_ref' => 'nullable|string|max:50',
            'keterangan' => 'required|string',
            'total_debet' => 'nullable|numeric|min:0',
            'total_kredit' => 'nullable|numeric|min:0',
            'is_balance' => 'nullable|boolean',
            'status' => 'nullable|in:Draft,Posted,Void',
            'user_posted' => 'nullable|exists:users,id',
            'user_id' => 'nullable|exists:users,id',
        ]);

        /* Endpoint ini hanya membuat HEADER jurnal; baris detail dibuat lewat
         | alur lain. Jurnal berstatus Posted tanpa baris detail adalah jurnal
         | kosong yang tidak dapat diaudit (double-entry tidak terpenuhi),
         | karena itu jalur ini dibatasi ke status Draft. */
        if (($validated['status'] ?? 'Draft') === 'Posted') {
            return response()->json([
                'success' => false,
                'message' => 'Jurnal tanpa baris detail (Debet/Kredit) tidak boleh berstatus Posted. '
                    .'Buat sebagai Draft, lengkapi baris detail, lalu post.',
            ], 422);
        }

        /* OJK-02 — maker-checker: identitas PEMBUAT jurnal diambil dari sesi
         | terautentikasi, bukan dari masukan klien. Kolom `user_id` inilah yang
         | dibandingkan di post()/void() untuk membuktikan bahwa pemosting BUKAN
         | pembuat jurnal (prinsip 4-eyes / pemisahan tugas). Bila klien bebas
         | mengisi user_id, pemisahan tugas dapat dilangkahi dengan mengaku
         | sebagai orang lain. */
        $validated['user_id'] = Auth::id();

        $jurnal = Jurnal::create($validated);
        $jurnal->load('postedBy', 'createdBy', 'transaksi', 'pembiayaan');

        return response()->json([
            'success' => true,
            'message' => 'Jurnal berhasil dibuat',
            'data' => $jurnal,
        ], 201);
    }

    public function show(Jurnal $jurnal): JsonResponse
    {
        $jurnal->load([
            'detail.akun',
            'detail.anggota',
            'detail.rekening.anggota',
            'postedBy',
            'createdBy',
            'transaksi',
            'pembiayaan.anggota',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Detail jurnal berhasil diambil',
            'data' => $jurnal,
        ]);
    }

    public function update(Request $request, Jurnal $jurnal): JsonResponse
    {
        $validated = $request->validate([
            'no_jurnal' => 'required|string|max:30|unique:jurnal,no_jurnal,'.$jurnal->id,
            'tanggal_jurnal' => 'required|date',
            'jenis_jurnal' => 'required|in:Jurnal Umum,Jurnal Penerimaan Kas,Jurnal Pengeluaran Kas,Jurnal Penyesuaian,Jurnal Penutup,Jurnal Pembalik,Jurnal Otomatis,Jurnal Transaksi',
            'transaksi_id' => 'nullable|exists:transaksi,id',
            'pembiayaan_id' => 'nullable|exists:pembiayaan,id',
            'sumber' => 'nullable|string|max:50',
            'no_ref' => 'nullable|string|max:50',
            'keterangan' => 'required|string',
            'total_debet' => 'nullable|numeric|min:0',
            'total_kredit' => 'nullable|numeric|min:0',
            'is_balance' => 'nullable|boolean',
            'status' => 'nullable|in:Draft,Posted,Void',
            'user_posted' => 'nullable|exists:users,id',
            'user_id' => 'nullable|exists:users,id',
        ]);

        if (($validated['status'] ?? null) === 'Posted' && $jurnal->status !== 'Posted') {
            // OJK-02 — maker-checker: pembuat jurnal tidak boleh memposting
            // jurnalnya sendiri (self-approval dilarang — prinsip 4-eyes).
            if (PemisahanTugas::dilanggar($jurnal->user_id, Auth::id())) {
                return response()->json([
                    'success' => false,
                    'message' => PemisahanTugas::PESAN,
                ], 422);
            }

            // Menaikkan status ke Posted wajib punya baris detail yang seimbang.
            if (! $jurnal->detail()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Jurnal belum memiliki baris detail (Debet/Kredit) — tidak dapat di-post.',
                ], 422);
            }

            try {
                $jurnal->pastikanBalance();
            } catch (RuntimeException $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            $validated['posted_at'] = now();
            // Pemosting sebenarnya adalah pengguna terautentikasi, bukan nilai
            // yang dipasok klien — jejak audit harus dapat dipercaya.
            $validated['user_posted'] = Auth::id();
        }

        $jurnal->update($validated);
        $jurnal->load('postedBy', 'createdBy', 'transaksi', 'pembiayaan');

        return response()->json([
            'success' => true,
            'message' => 'Jurnal berhasil diperbarui',
            'data' => $jurnal,
        ]);
    }

    public function post(Jurnal $jurnal): JsonResponse
    {
        if ($jurnal->status !== 'Draft') {
            return response()->json([
                'success' => false,
                'message' => 'Jurnal hanya bisa di-post dari status Draft',
            ], 422);
        }

        // OJK-02 — maker-checker: pembuat jurnal tidak boleh memposting
        // jurnalnya sendiri (self-approval dilarang — prinsip 4-eyes).
        if (PemisahanTugas::dilanggar($jurnal->user_id, Auth::id())) {
            return response()->json([
                'success' => false,
                'message' => PemisahanTugas::PESAN,
            ], 422);
        }

        /* Jurnal tanpa baris detail tidak boleh menjadi Posted — header wajib
         | dibuktikan seimbang oleh baris detail yang benar-benar tersimpan. */
        if (! $jurnal->detail()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Jurnal belum memiliki baris detail (Debet/Kredit) — tidak dapat di-post.',
            ], 422);
        }

        // pastikanBalance() melempar RuntimeException bila tidak seimbang;
        // tangkap agar pemanggil API menerima 422 yang informatif.
        try {
            $jurnal->pastikanBalance();
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => [
                    'total_debet' => $jurnal->total_debet,
                    'total_kredit' => $jurnal->total_kredit,
                ],
            ], 422);
        }

        $jurnal->update([
            'status' => 'Posted',
            'posted_at' => now(),
            // Pemosting sebenarnya adalah pengguna terautentikasi, bukan nilai
            // yang dipasok klien — jejak audit harus dapat dipercaya.
            'user_posted' => Auth::id(),
        ]);

        $jurnal->load('postedBy', 'detail.akun');

        return response()->json([
            'success' => true,
            'message' => 'Jurnal berhasil di-post',
            'data' => $jurnal,
        ]);
    }

    /**
     * F-03 — VOID JURNAL DENGAN JURNAL PEMBALIK (bukan sekadar ubah status).
     *
     * Sebelumnya void() hanya menulis `status = 'Void'`. Buku besar menjadi
     * tidak dapat direkonsiliasi: saldo akun tetap memuat angka jurnal yang
     * dibatalkan sehingga Neraca/Laba-Rugi periode berjalan tidak lagi sama
     * dengan penjumlahan jurnal yang masih hidup. PSAK 101/108 & praktik
     * akuntansi yang lazim mewajibkan pembatalan dilakukan lewat JURNAL
     * PEMBALIK (contra-entry) agar jejak asal tetap utuh dan dapat diaudit.
     *
     * Aturan yang diterapkan:
     *   1. Hanya jurnal Posted yang dapat di-void.
     *   2. Void hanya boleh menyasar jurnal MANUAL (tanpa transaksi_id &
     *      pembiayaan_id). Jurnal yang lahir dari transaksi/pembiayaan bersifat
     *      turunan — pembatalannya harus melalui alur void transaksi
     *      (ReversalService) agar saldo rekening/angsuran ikut dibalik. Bila
     *      jurnal turunan boleh di-void langsung, saldo buku besar dan saldo
     *      operasional akan berpisah tanpa mekanisme penyeimbang.
     *   3. Pemisahan tugas (OJK-02): pemosting/pembuat tidak boleh mem-void
     *      jurnalnya sendiri.
     *   4. Alasan void WAJIB — tanpa alasan, jejak audit kehilangan makna.
     *   5. Seluruh proses dibungkus DB::transaction supaya jurnal pembalik dan
     *      perubahan status bersifat atomik.
     */
    public function void(Jurnal $jurnal, Request $request): JsonResponse
    {
        if ($jurnal->status !== 'Posted') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya jurnal berstatus Posted yang bisa di-void',
            ], 422);
        }

        if ($jurnal->transaksi_id !== null || $jurnal->pembiayaan_id !== null) {
            return response()->json([
                'success' => false,
                'message' => 'Jurnal ini berasal dari transaksi/pembiayaan — batalkan lewat menu Void Transaksi '
                    .'agar saldo rekening dan angsuran ikut dibalik (ReversalService), bukan void jurnal manual.',
            ], 422);
        }

        // GCG — pemisahan tugas: pembuat/pemosting tidak boleh mem-void sendiri.
        if (PemisahanTugas::dilanggarSalahSatu([$jurnal->user_id, $jurnal->user_posted], Auth::id())) {
            return response()->json([
                'success' => false,
                'message' => PemisahanTugas::PESAN,
            ], 422);
        }

        $validated = $request->validate([
            'alasan' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $alasan = trim((string) $validated['alasan']);

        if (! $jurnal->detail()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Jurnal tidak memiliki baris detail — tidak ada yang perlu dibalik.',
            ], 422);
        }

        try {
            $pembalik = DB::transaction(function () use ($jurnal, $alasan) {
                return $this->buatJurnalPembalik($jurnal, $alasan);
            });
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Jurnal berhasil di-void. Jurnal pembalik ['.$pembalik->no_jurnal.'] telah diposting.',
            'data' => [
                'jurnal' => $jurnal->fresh(['postedBy', 'createdBy']),
                'jurnal_pembalik' => $pembalik->load('detail'),
            ],
        ]);
    }

    /**
     * Membuat jurnal pembalik (contra-entry) untuk jurnal manual yang di-void.
     *
     * Kolom Debet dan Kredit TUKAR POSISI: apa yang semula Debet menjadi Kredit
     * dan sebaliknya, sehingga saldo setiap akun kembali ke posisi sebelum
     * jurnal asal. Jurnal pembalik berstatus Posted dan bertanggal HARI INI
     * (bukan tanggal jurnal asal) supaya periode yang sudah dilaporkan tidak
     * berubah retroaktif — inilah sebabnya void langsung jurnal bertanggal
     * mundur dilarang, karena akan merusak laporan periode tertutup.
     *
     * @throws RuntimeException bila jurnal asal tidak memenuhi syarat pembalikan
     */
    private function buatJurnalPembalik(Jurnal $jurnal, string $alasan): Jurnal
    {
        $jurnal = Jurnal::query()->lockForUpdate()->findOrFail($jurnal->id);

        if ($jurnal->status !== 'Posted') {
            throw new RuntimeException('Jurnal tidak lagi berstatus Posted — proses void dibatalkan.');
        }

        $details = $jurnal->detail()->orderBy('baris')->get();

        if ($details->isEmpty()) {
            throw new RuntimeException('Jurnal tidak memiliki baris detail — tidak ada yang perlu dibalik.');
        }

        /* Bangun entri contra-entry dari baris jurnal asal. Kolom Debet/Kredit
         | TUKAR POSISI. Kode CoA diresolusi dari `coa_id` agar tetap melewati
         | seluruh gerbang CoA (aktif/tidak) di AutoJournalingService::post() —
         | satu-satunya jalur tulis jurnal. */
        $entries = [];
        foreach ($details as $detail) {
            $coa = $detail->coa;
            if ($coa === null) {
                throw new RuntimeException(
                    "Baris jurnal [{$jurnal->no_jurnal}] menunjuk akun CoA yang sudah tidak ada — pembalikan dibatalkan."
                );
            }

            $entries[] = [
                'kode' => $coa->kode_akun,
                'debet' => (float) $detail->kredit,
                'kredit' => (float) $detail->debet,
                'keterangan' => 'Pembalik void: '.($detail->keterangan ?: $jurnal->no_jurnal),
                'anggota_id' => $detail->anggota_id,
                'rekening_id' => $detail->rekening_id,
            ];
        }

        /* Jalur tulis tunggal (post()) memvalidasi keseimbangan, cut-off
         | periode, serta menulis ulang baris dengan status yang konsisten.
         | Mode `$pembalikan = true` menandai bahwa ini contra-entry sah —
         | validator POSISI PSAK 108/109 (yang mengasumsikan arah transaksi
         | normal) dilewati. Sumber ASAL dipertahankan karena
         | `posisiDanaKebajikanValid()` memakai sumber untuk mengizinkan DEBET
         | akun 2-3001 pada pembalikan penyaluran. */
        $pembalik = $this->journalService->post(
            'PEMBALIK VOID '.$jurnal->no_jurnal.' — '.$alasan,
            $entries,
            $jurnal->sumber,
            null,
            null,
            Auth::id(),
            null,
            null,
            now()->toDateString(),
            null,
            $this->noJurnalPembalik($jurnal),
            $jurnal->no_ref,
            $jurnal->jenis_jurnal,
            true
        );

        if ($pembalik === null) {
            throw new RuntimeException('Gagal memposting jurnal pembalik — proses void dibatalkan.');
        }

        $jurnal->update([
            'status' => 'Void',
        ]);

        return $pembalik;
    }

    /**
     * Nomor jurnal pembalik unik: `VOID-<no asal>-<suffix>`.
     *
     * Kolom `jurnal.no_jurnal` bertipe varchar(30), sehingga total panjang WAJIB
     * ≤ 30 karakter. Nomor dasar dipangkas sesuai sisa anggaran sebelum suffix
     * acak ditambahkan — mencegah galat "Data too long" pada jurnal asal yang
     * bernomor panjang.
     */
    private function noJurnalPembalik(Jurnal $jurnal): string
    {
        $batas = 30;
        $suffix = '-'.strtoupper(Str::random(6));   // mis. "-A1B2C3" = 7 char
        $dasar = Str::limit('VOID-'.$jurnal->no_jurnal, $batas - strlen($suffix), '');

        do {
            $nomor = $dasar.$suffix;
            $suffix = '-'.strtoupper(Str::random(6));
        } while (Jurnal::withTrashed()->where('no_jurnal', $nomor)->exists());

        return $nomor;
    }

    public function destroy(Jurnal $jurnal): JsonResponse
    {
        if ($jurnal->status === 'Posted') {
            return response()->json([
                'success' => false,
                'message' => 'Jurnal yang sudah di-post tidak bisa dihapus. Gunakan void.',
            ], 422);
        }

        $jurnal->delete();

        return response()->json([
            'success' => true,
            'message' => 'Jurnal berhasil dihapus',
        ]);
    }
}
