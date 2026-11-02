<?php

namespace App\Http\Controllers;

use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\Coa;
use App\Models\JenisSimpanan;
use App\Models\MasterProdukPembiayaan;
use App\Models\Pembiayaan;
use App\Models\PemetaanAkadCoa;
use App\Models\PengajuanPembiayaan;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Services\AutoJournalingService;
use App\Support\JenisTransaksi;
use App\Support\PemisahanTugas;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Modul Pengajuan & Approval Pembiayaan Syariah Integrated
 * =========================================================
 * - Eligibility check CIF (status anggota, rekening sukarela, NPF)
 * - Form pengajuan: Murabahah / Mudharabah / Musyarakah / Qardh
 * - Agunan deposito back-to-back (hold bilyet)
 * - Approval bertingkat AO → Manajer → Pengurus
 * - Auto-journaling pencairan (double-entry)
 */
class PengajuanPembiayaanIntegController extends Controller
{
    /** Nilai cadangan bila master produk tidak tersedia. */
    private const JENIS_SUKARELA = ['Simpanan Sukarela', 'Sukarela', 'Wadiah', 'Tabungan'];

    private const JENIS_DEPOSITO = ['Simpanan Berjangka', 'Deposito', 'Simpanan Berjangka / Deposito'];

    /** Nama rekening sumber dana sukarela: master aktif + alias historis. */
    private static function jenisSukarela(): array
    {
        return array_values(array_unique(array_merge(
            JenisSimpanan::namaRekening(JenisSimpanan::TIPE_SUKARELA),
            self::JENIS_SUKARELA
        )));
    }

    /** Nama rekening agunan deposito: master aktif + alias historis. */
    private static function jenisDeposito(): array
    {
        return array_values(array_unique(array_merge(
            JenisSimpanan::namaRekening(JenisSimpanan::TIPE_BERJANGKA),
            self::JENIS_DEPOSITO
        )));
    }

    private const STATUS_MACET = [Pembiayaan::STATUS_MACET];

    public function __construct(private AutoJournalingService $journalService) {}

    // =========================================================================
    // HALAMAN UTAMA — Daftar + Form Pengajuan (AO)
    // =========================================================================

    public function index(Request $request)
    {
        // Hanya anggota berstatus aktif (bentuk kanonik 'ACTIVE' + alias legacy
        // 'Aktif') yang boleh mengajukan pembiayaan.
        $anggota = Anggota::aktif()
            ->orderBy('nama_lengkap')->get();
        $produk = MasterProdukPembiayaan::where('status_aktif', true)
            ->orderBy('nama_produk')->get();

        $query = Pembiayaan::with(['anggota', 'approvedBy'])
            ->statusIn([
                Pembiayaan::STATUS_PENDING_AO, Pembiayaan::STATUS_PENDING_MANAJER,
                Pembiayaan::STATUS_PENDING_PENGURUS, Pembiayaan::STATUS_DIREVIEW,
                Pembiayaan::STATUS_DISETUJUI, Pembiayaan::STATUS_DITOLAK,
                Pembiayaan::STATUS_DICAIRKAN,
            ]);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('no_pembiayaan', 'like', "%{$search}%")
                    ->orWhereHas('anggota', fn ($a) => $a
                        ->where('nama_lengkap', 'like', "%{$search}%")
                        ->orWhere('no_anggota', 'like', "%{$search}%")
                        ->orWhere('no_cif', 'like', "%{$search}%"));
            });
        }

        if ($status = $request->input('status')) {
            $query->statusIn([$status]);
        }

        $daftarPengajuan = $query->latest()->paginate(15)->withQueryString();

        $stats = [
            'menunggu' => Pembiayaan::statusIn([Pembiayaan::STATUS_PENDING_AO, Pembiayaan::STATUS_PENDING_MANAJER, Pembiayaan::STATUS_PENDING_PENGURUS])->count(),
            'direview' => Pembiayaan::statusIn([Pembiayaan::STATUS_DIREVIEW])->count(),
            'disetujui' => Pembiayaan::statusIn([Pembiayaan::STATUS_DISETUJUI, Pembiayaan::STATUS_DICAIRKAN])->count(),
            'ditolak' => Pembiayaan::statusIn([Pembiayaan::STATUS_DITOLAK])->count(),
        ];

        return view('pengajuan-pembiayaan.index', compact(
            'anggota', 'produk', 'daftarPengajuan', 'stats'
        ));
    }

    // =========================================================================
    // AJAX — Eligibility Check CIF
    // =========================================================================

    public function eligibilityCheck(Request $request): JsonResponse
    {
        $request->validate(['anggota_id' => 'required|exists:anggota,id']);

        $anggota = Anggota::with('rekening')->findOrFail($request->anggota_id);

        $issues = [];
        $warnings = [];

        // 1. Status keanggotaan — bandingkan lewat normalisasi model agar
        //    nilai kanonik ('ACTIVE') maupun alias legacy ('Aktif') sama-sama
        //    dikenali aktif.
        if (! $anggota->isActive()) {
            $issues[] = 'Status anggota tidak AKTIF ('.$anggota->status_anggota.'). Pengajuan tidak dapat diproses.';
        }

        // 2. Simpanan pokok & wajib
        if ((float) $anggota->simpanan_pokok <= 0) {
            $issues[] = 'Anggota belum melunasi Simpanan Pokok.';
        }
        if ((float) $anggota->simpanan_wajib <= 0) {
            $warnings[] = 'Anggota belum memiliki catatan Simpanan Wajib.';
        }

        // 3. Rekening simpanan sukarela / wadiah aktif
        $rekeningSukarela = $anggota->rekening
            ->whereIn('jenis_rekening', self::jenisSukarela())
            ->where('status_rekening', 'Aktif')
            ->first();

        if (! $rekeningSukarela) {
            $warnings[] = 'Anggota belum memiliki Rekening Simpanan Sukarela/Wadiah aktif. Rekening akan dibuat otomatis saat pencairan.';
        }

        // 4. Cek NPF / tunggakan macet
        $adaMacet = Pembiayaan::where('anggota_id', $anggota->id)
            ->statusIn(self::STATUS_MACET)
            ->exists();

        if ($adaMacet) {
            $issues[] = 'Anggota memiliki pembiayaan aktif yang berstatus MACET/NPF. Pengajuan otomatis ditolak.';
        }

        // Rekening deposito aktif milik anggota (untuk opsi agunan back-to-back)
        $rekeningDeposito = $anggota->rekening
            ->whereIn('jenis_rekening', self::jenisDeposito())
            ->where('status_rekening', 'Aktif')
            ->where('is_locked', false)
            ->values();

        return response()->json([
            'eligible' => empty($issues),
            'issues' => $issues,
            'warnings' => $warnings,
            'anggota' => [
                'id' => $anggota->id,
                'nama' => $anggota->nama_lengkap,
                'no_anggota' => $anggota->no_anggota,
                'no_cif' => $anggota->no_cif,
                'status' => $anggota->status_anggota,
                'simpanan_pokok' => (float) $anggota->simpanan_pokok,
                'simpanan_wajib' => (float) $anggota->simpanan_wajib,
            ],
            'rekening_sukarela' => $rekeningSukarela ? [
                'id' => $rekeningSukarela->id,
                'no_rekening' => $rekeningSukarela->no_rekening,
                'saldo' => (float) $rekeningSukarela->saldo,
            ] : null,
            'rekening_deposito' => $rekeningDeposito->map(fn ($r) => [
                'id' => $r->id,
                'no_rekening' => $r->no_rekening,
                'jenis_rekening' => $r->jenis_rekening,
                'saldo' => (float) $r->saldo,
                'tanggal_jatuh_tempo' => $r->tanggal_jatuh_tempo?->format('d/m/Y'),
            ])->values(),
        ]);
    }

    // =========================================================================
    // STORE — Simpan Pengajuan Baru
    // =========================================================================

    public function store(Request $request)
    {
        $validated = $request->validate([
            'anggota_id' => 'required|exists:anggota,id',
            'akad_pembiayaan' => Pembiayaan::aturanValidasiAkad(),
            'jumlah_pembiayaan' => 'required|numeric|min:'.Setting::minimalPengajuanPembiayaan(),
            'tenor' => 'required|integer|min:1|max:360',
            'margin_nisbah' => 'required|numeric|min:0|max:100',
            'tujuan_pembiayaan' => 'required|string|max:500',
            'agunan_tipe' => 'nullable|string|max:50',
            'deposito_id' => 'nullable|exists:rekening,id',
            'keterangan' => 'nullable|string|max:1000',
        ]);

        $anggota = Anggota::findOrFail($validated['anggota_id']);

        // Guard: status anggota harus aktif (kanonik 'ACTIVE' atau alias legacy 'Aktif')
        if (! $anggota->isActive()) {
            return back()->withInput()->with('error', 'Status anggota tidak AKTIF. Pengajuan tidak dapat disimpan.');
        }

        // Guard: cek NPF
        $adaMacet = Pembiayaan::where('anggota_id', $anggota->id)
            ->statusIn(self::STATUS_MACET)
            ->exists();
        if ($adaMacet) {
            return back()->withInput()->with('error', 'Anggota memiliki pembiayaan MACET/NPF. Pengajuan ditolak otomatis.');
        }

        // Guard: validasi deposito back-to-back
        if (! empty($validated['deposito_id'])) {
            $deposito = Rekening::findOrFail($validated['deposito_id']);

            if ($deposito->anggota_id !== $anggota->id) {
                return back()->withInput()->with('error', 'Rekening deposito tidak milik anggota bersangkutan.');
            }
            if ($deposito->is_locked) {
                return back()->withInput()->with('error', 'Rekening deposito sudah terkunci/digunakan sebagai agunan lain.');
            }
            if ((float) $deposito->saldo < (float) $validated['jumlah_pembiayaan']) {
                return back()->withInput()->with('error',
                    'Saldo Deposito (Rp '.number_format($deposito->saldo, 0, ',', '.').') '.
                    'kurang dari nominal pengajuan (Rp '.number_format($validated['jumlah_pembiayaan'], 0, ',', '.').').'
                );
            }
        }

        // B6 — nisbah pada akad bagi hasil.
        // `margin_nisbah` dipakai sebagai nisbah koperasi (pengelola). Akad
        // Mudharabah/Musyarakah tidak sah bila nisbahnya 0% karena bagi hasil
        // tidak dapat dihitung; akad jual beli (Murabahah dll.) tetap memakai
        // margin sebagai ujroh sehingga tidak dipaksa mengisi nisbah.
        $bagiHasil = in_array($validated['akad_pembiayaan'], MasterProdukPembiayaan::akadBagiHasil(), true);
        $marginPct = (float) $validated['margin_nisbah'];

        if ($bagiHasil && $marginPct <= 0.0) {
            return back()->withInput()->with(
                'error',
                "Akad {$validated['akad_pembiayaan']} adalah akad bagi hasil sehingga nisbah wajib lebih dari 0%."
            );
        }

        // Hitung finansial
        $pokok = (float) $validated['jumlah_pembiayaan'];
        $tenor = (int) $validated['tenor'];
        $totalMargin = Setting::bulatkanUang($pokok * ($marginPct / 100));
        $totalPembiayaan = $pokok + $totalMargin;
        $angsuranPerBulan = $tenor > 0 ? Setting::bulatkanUang($totalPembiayaan / $tenor) : $totalPembiayaan;

        // Nomor pembiayaan (berbasis MAX id untuk hindari duplikasi saat data di-delete)
        $prefix = 'PMB-'.date('Ymd').'-';
        $lastPmb = Pembiayaan::where('no_pembiayaan', 'like', $prefix.'%')->orderByDesc('id')->first();
        $urutPmb = $lastPmb ? ((int) substr($lastPmb->no_pembiayaan, -4)) + 1 : 1;
        $noPembiayaan = $prefix.str_pad($urutPmb, 4, '0', STR_PAD_LEFT);

        /* Ambil akun CoA piutang sesuai akad.
         |
         | PERBAIKAN: sebelumnya lookup dilakukan ke tabel `akun` (Akun::where),
         | padahal kode_akun 1-200x hanya ada di tabel `coas`. Akibatnya lookup
         | SELALU gagal dan jatuh ke Akun::first(), sehingga pembiayaan tersimpan
         | dengan akun_id yang tidak berhubungan dengan akadnya. Kini lookup
         | memakai Coa::findByKode() — sumber tunggal yang sama dengan
         | AutoJournalingService::piutangByAkad() — sehingga posting jurnal selalu
         | jatuh ke akun utama 1-2001..1-2005 yang aktif.
         |
         | CATATAN: kolom `pembiayaan.akun_id` adalah kolom lama yang ber-FK ke
         | tabel `akun` (bukan `coas`) dan bersifat NOT NULL, sehingga tetap diisi
         | dari tabel `akun`. Nilainya diresolusi DINAMIS dari akad via
         | PengajuanPembiayaan::akunLegacyIdUntukAkad() — tanpa `Akun::first()`
         | yang dapat menunjuk akun sembarang. Yang menentukan akun jurnal adalah
         | $coaPiutang di atas. */
        $kodeAkun = \App\Support\Coa::piutangByAkad($validated['akad_pembiayaan']);
        $coaPiutang = Coa::findByKode($kodeAkun);

        // Akun piutang wajib ada dan aktif — jangan lanjut dengan akun yang salah/nonaktif.
        if (! $coaPiutang || ! $coaPiutang->is_active) {
            return back()->withInput()->with(
                'error',
                "Akun CoA piutang [{$kodeAkun}] untuk akad {$validated['akad_pembiayaan']} tidak ditemukan atau nonaktif. "
                .'Hubungi admin untuk melengkapi COA sebelum pengajuan disimpan.'
            );
        }

        $akunIdLegacy = PengajuanPembiayaan::akunLegacyIdUntukAkad($validated['akad_pembiayaan']);

        DB::transaction(function () use (
            $validated, $anggota, $pokok, $tenor, $marginPct, $bagiHasil,
            $totalMargin, $totalPembiayaan, $angsuranPerBulan,
            $noPembiayaan, $akunIdLegacy
        ) {
            $isDeposito = ! empty($validated['deposito_id']);

            // Buat record pembiayaan
            $pembiayaan = Pembiayaan::create([
                'no_pembiayaan' => $noPembiayaan,
                'anggota_id' => $anggota->id,
                'akun_id' => $akunIdLegacy,
                'akad_pembiayaan' => $validated['akad_pembiayaan'],
                'tujuan_pembiayaan' => $validated['tujuan_pembiayaan'],
                'jumlah_pembiayaan' => $pokok,
                'margin_nisbah' => $marginPct,
                'total_ujroh' => $totalMargin,
                'total_pembiayaan' => $totalPembiayaan,
                'jumlah_angsuran' => $angsuranPerBulan,
                'tenor' => $tenor,
                'frekuensi_angsuran' => 'Bulanan',
                'sisa_pembiayaan' => $totalPembiayaan,
                'status_pembiayaan' => Pembiayaan::STATUS_PENDING_AO,
                'agunan' => $validated['keterangan'] ?? null,
                'agunan_tipe' => $validated['agunan_tipe'] ?? ($isDeposito ? 'deposito_backtoback' : null),
                'deposito_id' => $isDeposito ? $validated['deposito_id'] : null,
                'is_disbursed' => false,
                'is_low_risk' => $isDeposito,
                'tanggal_akad' => today(),
                // `tanggal_jatuh_tempo` NOT NULL tanpa default: tanpa pengisian
                // di sini seluruh pengajuan gagal (SQLSTATE 1364 → HTTP 500).
                // Jatuh tempo kontrak = tanggal akad + tenor (tanpa overflow bulan).
                'tanggal_jatuh_tempo' => today()->addMonthsNoOverflow($tenor)->toDateString(),
                // Nisbah disimpan eksplisit pada akad bagi hasil agar laporan DPS
                // tidak lagi menurunkan porsi dari `margin_nisbah` (COALESCE) dan
                // porsi pengelola + pemilik dijamin berjumlah 100%.
                'nisbah_pengelola' => $bagiHasil ? $marginPct : null,
                'nisbah_pemilik' => $bagiHasil ? round(100 - $marginPct, 2) : null,
                'keterangan' => $validated['keterangan'] ?? null,
                'user_approved' => Auth::id(),
            ]);

            // Kunci bilyet deposito back-to-back
            if ($isDeposito) {
                Rekening::where('id', $validated['deposito_id'])->update([
                    'is_locked' => true,
                    'locked_reason' => "Agunan Back-to-Back Pembiayaan {$noPembiayaan}",
                ]);
            }
        });

        return redirect()->route('pengajuan-pembiayaan.index')
            ->with('success', "Pengajuan pembiayaan {$noPembiayaan} berhasil disimpan dan menunggu review Manajer.");
    }

    // =========================================================================
    // SHOW — Detail pengajuan
    // =========================================================================

    public function show(int $id)
    {
        $pembiayaan = Pembiayaan::with([
            'anggota.rekening',
            'angsuran',
            'approvedBy',
            'reviewedBy',
            'approvedFinalBy',
        ])->findOrFail($id);

        // Data deposito back-to-back jika ada
        $depositoAgunan = null;
        if ($pembiayaan->deposito_id) {
            $depositoAgunan = Rekening::find($pembiayaan->deposito_id);
        }

        return view('pengajuan-pembiayaan.show', compact('pembiayaan', 'depositoAgunan'));
    }

    // =========================================================================
    // MANAJER — Review (teruskan ke Pengurus)
    // =========================================================================

    public function review(Request $request, int $id)
    {
        $pembiayaan = Pembiayaan::findOrFail($id);

        // GCG — maker ≠ checker: pengaju tidak boleh mereview pengajuannya sendiri.
        if ($this->langgarPemisahanTugas($pembiayaan)) {
            return back()->with('error', PemisahanTugas::PESAN);
        }

        if (! $pembiayaan->isStatus([Pembiayaan::STATUS_PENDING_AO, Pembiayaan::STATUS_PENDING_MANAJER])) {
            return back()->with('error', 'Pembiayaan tidak dalam status yang bisa direview.');
        }

        $request->validate(['catatan_manajer' => 'nullable|string|max:1000']);

        $pembiayaan->update([
            'status_pembiayaan' => Pembiayaan::STATUS_DIREVIEW,
            'direview_oleh' => Auth::id(),
            'tanggal_direview' => now(),
            'catatan_manajer' => $request->catatan_manajer,
        ]);

        return back()->with('success', 'Pengajuan telah direview dan diteruskan ke Pengurus.');
    }

    // =========================================================================
    // MANAJER — Tolak
    // =========================================================================

    public function tolakManajer(Request $request, int $id)
    {
        $pembiayaan = Pembiayaan::findOrFail($id);

        // GCG — maker ≠ checker: pengaju tidak boleh menolak pengajuannya sendiri.
        if ($this->langgarPemisahanTugas($pembiayaan)) {
            return back()->with('error', PemisahanTugas::PESAN);
        }

        $request->validate(['catatan_manajer' => 'required|string|max:1000']);

        // Lepas kunci deposito jika ada
        $this->lepasKunciDeposito($pembiayaan);

        $pembiayaan->update([
            'status_pembiayaan' => Pembiayaan::STATUS_DITOLAK,
            'direview_oleh' => Auth::id(),
            'tanggal_direview' => now(),
            'catatan_manajer' => $request->catatan_manajer,
        ]);

        return back()->with('info', 'Pengajuan telah ditolak oleh Manajer.');
    }

    // =========================================================================
    // PENGURUS — Approve Final + Auto-Journaling Pencairan
    // =========================================================================

    public function approvePengurus(Request $request, int $id)
    {
        $pembiayaan = Pembiayaan::findOrFail($id);

        // GCG — maker ≠ checker: pengaju tidak boleh menyetujui pengajuannya sendiri.
        if ($this->langgarPemisahanTugas($pembiayaan)) {
            return back()->with('error', PemisahanTugas::PESAN);
        }

        if (! $pembiayaan->isStatus([
            Pembiayaan::STATUS_PENDING_AO, Pembiayaan::STATUS_PENDING_MANAJER,
            Pembiayaan::STATUS_PENDING_PENGURUS, Pembiayaan::STATUS_DIREVIEW,
        ])) {
            return back()->with('error', 'Pembiayaan tidak dalam status yang bisa disetujui.');
        }

        $request->validate(['catatan_pengurus' => 'nullable|string|max:1000']);

        // Persetujuan saja. Pencairan (kas keluar + jurnal) dilakukan terpisah
        // oleh cairkan() agar penyetuju dan pencair tidak sama (GCG).
        $pembiayaan->update([
            'status_pembiayaan' => Pembiayaan::STATUS_DISETUJUI,
            'disetujui_oleh' => Auth::id(),
            'tanggal_disetujui' => now(),
            'catatan_pengurus' => $request->catatan_pengurus,
        ]);

        return back()->with('success', 'Pembiayaan disetujui. Pencairan dilakukan melalui aksi Cairkan oleh pengurus yang berbeda.');
    }

    // =========================================================================
    // PENGURUS — Pencairan (terpisah dari persetujuan)
    // =========================================================================

    public function cairkan(Request $request, int $id)
    {
        $pembiayaan = Pembiayaan::with('anggota.rekening')->findOrFail($id);

        // GCG — maker ≠ checker: pengaju tidak boleh mencairkan pengajuannya sendiri.
        if ($this->langgarPemisahanTugas($pembiayaan)) {
            return back()->with('error', PemisahanTugas::PESAN);
        }

        // GCG — penyetuju tidak boleh sekaligus mencairkan (pemisahan persetujuan & pencairan).
        if (PemisahanTugas::dilanggar($pembiayaan->disetujui_oleh, Auth::id())) {
            return back()->with('error', PemisahanTugas::PESAN);
        }

        if (! $pembiayaan->isStatus([Pembiayaan::STATUS_DISETUJUI])) {
            return back()->with('error', 'Pembiayaan belum disetujui sehingga tidak dapat dicairkan.');
        }

        // Cegah double pencairan
        if ($pembiayaan->is_disbursed) {
            return back()->with('error', 'Pembiayaan ini sudah pernah dicairkan.');
        }

        $anggota = $pembiayaan->anggota;
        $pokok = (float) $pembiayaan->jumlah_pembiayaan;
        $totalKewajiban = (float) ($pembiayaan->total_pembiayaan ?: $pokok + (float) $pembiayaan->total_ujroh);
        $totalMargin = Setting::bulatkanUang($totalKewajiban - $pokok);
        $tenor = max(1, (int) $pembiayaan->tenor);
        $angsuranPerBulan = Setting::bulatkanUang($totalKewajiban / $tenor);
        $pokokPerBulan = Setting::bulatkanUang($pokok / $tenor);
        $marginPerBulan = Setting::bulatkanUang($totalMargin / $tenor);

        $noTrx = 'TRX-CAIR-'.now()->format('YmdHis').'-'.strtoupper(substr(uniqid(), -4));

        try {
            DB::transaction(function () use (
                $pembiayaan, $anggota, $pokok, $totalKewajiban,
                $tenor, $angsuranPerBulan, $pokokPerBulan, $marginPerBulan,
                $noTrx
            ) {
                // --- A. Cari / buat rekening sukarela anggota ---
                $rekeningSukarela = Rekening::where('anggota_id', $anggota->id)
                    ->whereIn('jenis_rekening', self::jenisSukarela())
                    ->where('status_rekening', 'Aktif')
                    ->lockForUpdate()
                    ->first();

                if (! $rekeningSukarela) {
                    // Resolusi dinamis akun simpanan sukarela dari master pemetaan
                    // (Jenis Simpanan) dengan padanan CoA sebagai cadangan — tanpa
                    // `Akun::first()` yang bisa menunjuk akun sembarang.
                    // `rekening.akun_id` ber-FK NOT NULL, jadi bila tidak ada
                    // pemetaan kita gagal eksplisit alih-alih menulis akun salah.
                    $akunSukarelaId = JenisSimpanan::akunLegacyId(JenisSimpanan::namaUtama(JenisSimpanan::TIPE_SUKARELA, 'Simpanan Sukarela'))
                        ?? \App\Support\Coa::akunLegacyId(\App\Support\Coa::SIMPANAN_SUKARELA);
                    if ($akunSukarelaId === null) {
                        // K-2 (KRITIS): di dalam DB::transaction, `return` hanya
                        // mengembalikan nilai dari closure — transaksi tetap COMMIT
                        // sehingga rekening/status/jadwal angsuran tersimpan tanpa
                        // akun yang benar. Lempar exception agar seluruh transaksi
                        // rollback, lalu ditangani di luar transaksi.
                        throw new \RuntimeException(
                            'Akun simpanan sukarela belum dipetakan. Atur pemetaan akun pada menu '
                            .'Pengurus → Jenis Simpanan (Parameter) sebelum mencairkan pembiayaan.'
                        );
                    }

                    // R-04: nomor rekening per-anggota (REK-{id}-{NN}) dihitung dari
                    // MAX sufiks numerik, bukan count()+1, agar aman saat rekening
                    // dihapus/dibuat bersamaan.
                    $noRekeningSukarela = Rekening::nomorRekeningBaru(sprintf('REK-%04d-', $anggota->id), 2);

                    $rekeningSukarela = Rekening::create([
                        'no_rekening' => $noRekeningSukarela,
                        'anggota_id' => $anggota->id,
                        'akun_id' => $akunSukarelaId,
                        'jenis_rekening' => JenisSimpanan::namaUtama(JenisSimpanan::TIPE_SUKARELA, 'Simpanan Sukarela'),
                        'akad' => 'Wadiah',
                        'saldo' => 0,
                        'batas_minimum' => 0,
                        'tanggal_buka' => today()->toDateString(),
                        'status_rekening' => 'Aktif',
                        'keterangan' => 'Dibuat otomatis saat pencairan pembiayaan',
                    ]);
                }

                // --- B. Kredit saldo simpanan sukarela ---
                $saldoLama = (float) $rekeningSukarela->saldo;
                $saldoBaru = $saldoLama + $pokok;
                $rekeningSukarela->update(['saldo' => $saldoBaru]);

                // --- C. Update status pembiayaan ---
                $pembiayaan->update([
                    'status_pembiayaan' => Pembiayaan::STATUS_DICAIRKAN,
                    'tanggal_cair' => today()->toDateString(),
                    'tgl_dicairkan' => now(),
                    'tgl_pencairan' => today()->toDateString(),
                    'sisa_pembiayaan' => $totalKewajiban,
                    'jumlah_angsuran' => $angsuranPerBulan,
                    'rekening_id' => $rekeningSukarela->id,
                    'is_disbursed' => true,
                ]);

                // --- D. Catat mutasi rekening ---
                //
                // Kolom `sumber_transaksi` & `status` adalah VARCHAR tanpa penjaga
                // nilai, sehingga sebelumnya tertulis 'Pengurus' dan 'berhasil' —
                // keduanya BUKAN nilai kanonik sistem ('Pencairan Pembiayaan' juga
                // bukan). Akibatnya laporan yang memfilter status teknis
                // `success`/`pending_approval` tidak menemukan baris ini, dan
                // rekonsiliasi membaca status tak dikenal. Nilai dikembalikan ke
                // kanonik; pencairan pembiayaan bukan transaksi kas tunai, sehingga
                // sumbernya 'Auto' (dipicu proses sistem, bukan loket).
                $transaksi = Transaksi::create([
                    'no_transaksi' => $noTrx,
                    'rekening_id' => $rekeningSukarela->id,
                    'anggota_id' => $anggota->id,
                    'pembiayaan_id' => $pembiayaan->id,
                    'user_id' => Auth::id(),
                    'dibuat_oleh' => Auth::id(),
                    'jenis_transaksi' => JenisTransaksi::PENCAIRAN_PEMBIAYAAN,
                    'posisi' => 'Debet',
                    'jumlah' => $pokok,
                    'saldo_setelah' => $saldoBaru,
                    'sumber_transaksi' => 'Auto',
                    'status_transaksi' => Transaksi::STATUS_TRANSAKSI_SUKSES,
                    'status' => Transaksi::STATUS_TEKNIS_SUCCESS,
                    'catatan' => "Pencairan Pembiayaan {$pembiayaan->akad_pembiayaan} - {$pembiayaan->no_pembiayaan} ke {$rekeningSukarela->no_rekening}",
                    'waktu_transaksi' => now(),
                ]);

                // --- E. Generate jadwal angsuran ---
                Angsuran::where('pembiayaan_id', $pembiayaan->id)->delete();
                $sisaPokok = $pokok;
                for ($i = 1; $i <= $tenor; $i++) {
                    $sisaPokok = max(0, Setting::bulatkanUang($sisaPokok - $pokokPerBulan));
                    Angsuran::create([
                        'pembiayaan_id' => $pembiayaan->id,
                        'angsuran_ke' => $i,
                        'tanggal_jatuh_tempo' => Carbon::now()->addMonths($i)->toDateString(),
                        'pokok_angsuran' => $pokokPerBulan,
                        'margin_ujroh' => $marginPerBulan,
                        'jumlah_angsuran' => $angsuranPerBulan,
                        'sisa_pokok' => $sisaPokok,
                        'status_angsuran' => 'Belum Jatuh Tempo',
                    ]);
                }

                // --- F. Auto-journaling double-entry ---
                // DEBET : Piutang sesuai akad          → nominal pokok
                // KREDIT: Simpanan Sukarela Anggota     → nominal pokok
                // KREDIT: Pendapatan Margin (Murabahah) → total margin (khusus Murabahah)
                //
                // K-2 (KRITIS): jurnal pencairan WAJIB berhasil. Exception TIDAK boleh
                // ditelan — bila ditelan di dalam DB::transaction, kas keluar + status
                // DICAIRKAN + jadwal angsuran akan tersimpan TANPA jurnal (neraca tidak
                // seimbang). Biarkan exception menjalar agar seluruh transaksi rollback,
                // konsisten dengan prinsip "hanya mencatat transaksi yang berhasil
                // sepenuhnya & selalu berpasangan dengan jurnal double-entry".
                $this->journalService->jurnalPencairan(
                    $pokok,
                    $pembiayaan->akad_pembiayaan ?? Pembiayaan::akadDefault(),
                    "Pencairan {$pembiayaan->akad_pembiayaan} {$pembiayaan->no_pembiayaan} - {$anggota->nama_lengkap}",
                    Auth::id(),
                    $transaksi->id,
                    $pembiayaan->id,
                    $rekeningSukarela->id,
                    $anggota->id,
                    0.0,
                    null,
                    null,
                    $pembiayaan->produk?->kode_produk ?: PemetaanAkadCoa::SEMUA_PRODUK,
                );
            });
        } catch (\Throwable $e) {
            // K-2 (KRITIS): seluruh mutasi (kas keluar, status DICAIRKAN, jadwal
            // angsuran) telah di-rollback oleh DB::transaction. Sampaikan pesan
            // yang dapat ditindaklanjuti kepada pengguna untuk kegagalan lokasi
            // (mis. pemetaan akun), sedangkan detail teknis tetap dicatat.
            report($e);

            $pesan = $e instanceof \RuntimeException
                ? 'Pencairan dibatalkan: '.$e->getMessage()
                : 'Pencairan dibatalkan karena kegagalan pencatatan jurnal. '
                    .'Tidak ada data yang tersimpan. Silakan hubungi administrator.';

            return back()->withInput()->with('error', $pesan);
        }

        return redirect()->route('pengajuan-pembiayaan.index')
            ->with('success',
                "Pembiayaan {$pembiayaan->no_pembiayaan} sebesar Rp ".
                number_format($pokok, 0, ',', '.').
                " berhasil dicairkan. Jadwal angsuran {$tenor} bulan telah di-generate."
            );
    }

    // =========================================================================
    // PENGURUS — Tolak
    // =========================================================================

    public function tolakPengurus(Request $request, int $id)
    {
        $pembiayaan = Pembiayaan::findOrFail($id);

        // GCG — maker ≠ checker: pengaju tidak boleh menolak pengajuannya sendiri.
        if ($this->langgarPemisahanTugas($pembiayaan)) {
            return back()->with('error', PemisahanTugas::PESAN);
        }

        // Hanya pengajuan yang masih menunggu keputusan Pengurus yang boleh
        // ditolak. Tanpa guard ini, pembiayaan yang sudah AKTIF / CAIR dapat
        // "ditolak" tanpa pembalikan jurnal & tanpa pembatalan jadwal angsuran.
        if (! $pembiayaan->isStatus(Pembiayaan::STATUS_PENDING_PENGURUS)) {
            return back()->with('error', 'Pengajuan tidak dalam status menunggu approval pengurus sehingga tidak dapat ditolak.');
        }

        $request->validate(['catatan_pengurus' => 'required|string|max:1000']);

        $this->lepasKunciDeposito($pembiayaan);

        $pembiayaan->update([
            'status_pembiayaan' => Pembiayaan::STATUS_DITOLAK,
            'disetujui_oleh' => Auth::id(),
            'tanggal_disetujui' => now(),
            'catatan_pengurus' => $request->catatan_pengurus,
        ]);

        return back()->with('info', 'Pengajuan ditolak oleh Pengurus.');
    }

    // =========================================================================
    // HELPER — Lepas kunci deposito saat pengajuan ditolak
    // =========================================================================

    private function lepasKunciDeposito(Pembiayaan $pembiayaan): void
    {
        if ($pembiayaan->deposito_id) {
            Rekening::where('id', $pembiayaan->deposito_id)->update([
                'is_locked' => false,
                'locked_reason' => null,
            ]);
        }
    }

    // =========================================================================
    // HELPER — GCG: Pemisahan Tugas (Maker ≠ Checker)
    // =========================================================================

    /**
     * Jaga prinsip maker ≠ checker pada rantai approval pembiayaan.
     *
     * Satu akun TIDAK BOLEH mereview/menyetujui/menolak pengajuan yang ia
     * ajukan sendiri. Identitas pengaju diambil dari pengajuan_pembiayaan
     * (ao_id + created_by) — kolom yang memang tersedia — lalu dibandingkan
     * dengan pemutus (Auth::id()) memakai helper PemisahanTugas agar pesan &
     * definisi konsisten dengan modul GCG lain.
     *
     * Nilai pengaju NULL (data dibentuk sistem/seeder/job) tidak dianggap
     * pelanggaran, sehingga proses otomatis tetap berjalan.
     */
    private function langgarPemisahanTugas(Pembiayaan $pembiayaan): bool
    {
        $pengaju = DB::table('pengajuan_pembiayaan')
            ->where('pembiayaan_id', $pembiayaan->id)
            ->select('ao_id', 'created_by')
            ->first();

        if ($pengaju === null) {
            return false;
        }

        return PemisahanTugas::dilanggarSalahSatu(
            [$pengaju->ao_id, $pengaju->created_by],
            Auth::id()
        );
    }
}
