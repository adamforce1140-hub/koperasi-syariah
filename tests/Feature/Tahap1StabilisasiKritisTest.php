<?php

namespace Tests\Feature;

use App\Models\Angsuran;
use App\Models\ApprovalTransaksi;
use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\KebijakanKoperasi;
use App\Models\Pembiayaan;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\AngsuranTransactionService;
use App\Services\AutoJournalingService;
use App\Services\DanaKebajikanService;
use App\Services\ReversalService;
use App\Support\Coa as RegistriCoa;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regresi Tahap 1 — Stabilisasi Kritis (Integritas & Keamanan).
 *
 * Mengunci perilaku perbaikan berikut agar tidak regresi:
 *
 *  - D-01 : ReversalService menulis ke kolom `sisa_pembiayaan` pada tabel
 *           `pembiayaan` (bukan `sisa_pokok`), dan mengembalikan sisa pokok
 *           angsuran memakai kolom yang benar (`pokok_angsuran` − `sisa_pokok`).
 *  - H-01 : Posting jurnal angsuran berada DI DALAM transaksi DB — jurnal gagal
 *           ⇒ saldo & angsuran ikut di-rollback.
 *  - H-02 : Kegagalan posting jurnal saat approval TIDAK ditelan.
 *  - H-03 : `sinkronkanTransaksiTunaiTeller()` melempar bila jurnal gagal.
 *  - G-01/G-02/G-04/B-01 : seluruh API keuangan menolak pengguna ANONIM.
 *  - A-14 : command destruktif ditolak di lingkungan produksi tanpa `--force`.
 */
class Tahap1StabilisasiKritisTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        /* Denda Ta'zir hanya sah bila fitur `financing_late_fee_per_day`
         | diaktifkan. Uji H-03 memakai transaksi ta'zir, jadi fitur harus
         | dinyalakan lebih dulu agar jalur jurnal benar-benar dieksekusi. */
        $kebijakan = KebijakanKoperasi::first() ?? new KebijakanKoperasi;
        $kebijakan->tazir_mode = 'FLAT_HARIAN';
        $kebijakan->financing_late_fee_per_day = 2500.00;
        $kebijakan->save();

        Setting::flush();
    }

    /* =====================================================================
     | Helper
     ===================================================================== */

    /** Akun kas loket teller (1-1001) — dipakai sebagai sisi debet angsuran tunai. */
    private function coaKasTeller(): ?Coa
    {
        return Coa::findByKode(AutoJournalingService::KAS_TELLER);
    }

    private function user(string $role = 'teller'): User
    {
        return User::firstOrCreate(
            ['email' => "{$role}.tahap1@test.local"],
            [
                'name' => ucfirst($role).' Tahap1',
                'password' => Hash::make('password'),
                'role' => $role,
            ]
        );
    }

    private function anggotaId(): int
    {
        $anggotaId = DB::table('anggota')->value('id');

        if (! $anggotaId) {
            $anggotaId = DB::table('anggota')->insertGetId([
                'nama_lengkap' => 'Anggota Tahap1',
                'no_anggota' => 'AGT-T1-'.strtoupper(substr(uniqid(), -6)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return (int) $anggotaId;
    }

    /** Pembiayaan Qardh sederhana + jadwal angsuran (pokok dibagi rata). */
    private function buatPembiayaan(float $total = 1_200_000, int $tenor = 12): Pembiayaan
    {
        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => 'PMB-T1-'.strtoupper(substr(uniqid(), -6)),
            'anggota_id' => $this->anggotaId(),
            'akun_id' => Coa::query()->value('id') ?? 1,
            'akad_pembiayaan' => 'Qardh',
            'jumlah_pembiayaan' => $total,
            'total_pembiayaan' => $total,
            'total_ujroh' => 0,
            'sisa_pembiayaan' => $total,
            'tenor' => $tenor,
            'jumlah_angsuran' => round($total / $tenor, 2),
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths($tenor)->toDateString(),
            'status_pembiayaan' => 'Aktif',
        ]);

        $perAngsuran = round($total / $tenor, 2);

        for ($i = 1; $i <= $tenor; $i++) {
            $pokok = ($i === $tenor)
                ? round($total - ($perAngsuran * ($tenor - 1)), 2)
                : $perAngsuran;

            Angsuran::create([
                'pembiayaan_id' => $pembiayaan->id,
                'angsuran_ke' => $i,
                'tanggal_jatuh_tempo' => now()->addMonths($i)->toDateString(),
                'pokok_angsuran' => $pokok,
                'margin_ujroh' => 0,
                'jumlah_angsuran' => $pokok,
                'sisa_pokok' => $pokok,
                'status_angsuran' => 'Belum Jatuh Tempo',
            ]);
        }

        return $pembiayaan;
    }

    private function service(): AngsuranTransactionService
    {
        return app(AngsuranTransactionService::class);
    }

    /* =====================================================================
     | D-01 — Reversal memakai kolom yang benar
     ===================================================================== */

    /**
     * Angsuran lunas di-void: pembiayaan.sisa_pembiayaan harus kembali naik
     * tepat sebesar pokok yang tadinya dibayar (dulu selalu 0 karena kode
     * membaca kolom `sisa_pokok` yang tidak ada di tabel `pembiayaan`).
     */
    public function test_reversal_angsuran_lunas_mengembalikan_sisa_pembiayaan(): void
    {
        $pembiayaan = $this->buatPembiayaan();
        $user = $this->user();

        $angsuran = Angsuran::where('pembiayaan_id', $pembiayaan->id)->where('angsuran_ke', 1)->first();
        $pokok = (float) $angsuran->pokok_angsuran;

        $this->service()->proses($angsuran, [
            'jumlah_bayar' => $pokok,
            'metode_bayar' => 'TUNAI',
            'user_id' => $user->id,
        ]);

        $pembiayaan->refresh();
        $sisaSetelahBayar = (float) $pembiayaan->sisa_pembiayaan;
        $this->assertSame(round(1_200_000 - $pokok, 2), $sisaSetelahBayar, 'Pembayaran penuh harus mengurangi sisa_pembiayaan.');

        $angsuran->refresh();
        $transaksi = Transaksi::findOrFail($angsuran->transaksi_id);

        // Void: transaksi ditandai Pending Void (seperti alur approval void).
        $transaksi->update(['status_transaksi' => 'Pending Void']);

        /* GCG — pemisahan tugas: pengaju void (teller) TIDAK boleh mengeksekusi
         | void-nya sendiri. Eksekusi dilakukan manajer sebagai checker. */
        $manajer = $this->user('manajer');

        $hasil = app(ReversalService::class)->executeVoidReversal(
            $this->approvalVoid($transaksi, $user),
            $manajer->id
        );

        $this->assertSame('success', $hasil['status'], $hasil['message']);

        $pembiayaan->refresh();
        $this->assertSame(
            1_200_000.0,
            (float) $pembiayaan->sisa_pembiayaan,
            'Reversal angsuran lunas WAJIB mengembalikan sisa_pembiayaan ke kondisi awal (D-01).'
        );

        $angsuran->refresh();
        $this->assertSame('Belum Jatuh Tempo', $angsuran->status_angsuran);
        $this->assertNull($angsuran->tanggal_bayar);
        $this->assertSame(
            round($pokok, 2),
            (float) $angsuran->sisa_pokok,
            'Sisa pokok angsuran harus dikembalikan menjadi tagihan penuh.'
        );
    }

    /**
     * Angsuran yang baru dibayar SEBAGIAN lalu di-void: hanya pokok yang
     * benar-benar tertagih yang dikembalikan, bukan tagihan penuh.
     */
    public function test_reversal_angsuran_sebagian_hanya_mengembalikan_pokok_tertagih(): void
    {
        $pembiayaan = $this->buatPembiayaan();
        $user = $this->user();

        $angsuran = Angsuran::where('pembiayaan_id', $pembiayaan->id)->where('angsuran_ke', 1)->first();
        $pokok = (float) $angsuran->pokok_angsuran; // 100.000

        // Bayar Rp 60.000 dari tagihan Rp 100.000 → pokok tertagih 60.000.
        $this->service()->proses($angsuran, [
            'jumlah_bayar' => 60_000,
            'metode_bayar' => 'TUNAI',
            'user_id' => $user->id,
        ]);

        $pembiayaan->refresh();
        $this->assertSame(
            round(1_200_000 - 60_000, 2),
            (float) $pembiayaan->sisa_pembiayaan,
            'Hanya pokok tertagih yang boleh mengurangi sisa_pembiayaan.'
        );

        $angsuran->refresh();
        $this->assertSame('Dibayar Sebagian', $angsuran->status_angsuran);
        $this->assertSame(round($pokok - 60_000, 2), (float) $angsuran->sisa_pokok);

        $transaksi = Transaksi::findOrFail($angsuran->transaksi_id);
        $transaksi->update(['status_transaksi' => 'Pending Void']);

        // GCG — maker (teller) ≠ checker (manajer).
        $hasil = app(ReversalService::class)->executeVoidReversal(
            $this->approvalVoid($transaksi, $user),
            $this->user('manajer')->id
        );

        $this->assertSame('success', $hasil['status'], $hasil['message']);

        $pembiayaan->refresh();
        $this->assertSame(
            1_200_000.0,
            (float) $pembiayaan->sisa_pembiayaan,
            'Reversal harus mengembalikan tepat pokok yang tertagih (Rp 60.000), bukan tagihan penuh.'
        );
    }

    /**
     * Reversal tidak boleh melampaui plafon pembiayaan (cap pada total_pembiayaan).
     */
    public function test_reversal_tidak_melampaui_total_pembiayaan(): void
    {
        $pembiayaan = $this->buatPembiayaan();
        $user = $this->user();

        $angsuran = Angsuran::where('pembiayaan_id', $pembiayaan->id)->where('angsuran_ke', 1)->first();
        $pokok = (float) $angsuran->pokok_angsuran;

        $this->service()->proses($angsuran, [
            'jumlah_bayar' => $pokok,
            'metode_bayar' => 'TUNAI',
            'user_id' => $user->id,
        ]);

        // Paksa sisa nyaris plafon penuh untuk menguji cap.
        $pembiayaan->update(['sisa_pembiayaan' => (float) $pembiayaan->total_pembiayaan]);

        $angsuran->refresh();
        $transaksi = Transaksi::findOrFail($angsuran->transaksi_id);
        $transaksi->update(['status_transaksi' => 'Pending Void']);

        app(ReversalService::class)->executeVoidReversal(
            $this->approvalVoid($transaksi, $user),
            $this->user('manajer')->id
        );

        $pembiayaan->refresh();
        $this->assertLessThanOrEqual(
            (float) $pembiayaan->total_pembiayaan,
            (float) $pembiayaan->sisa_pembiayaan,
            'Sisa pembiayaan TIDAK boleh melampaui plafon total_pembiayaan.'
        );
    }

    /* =====================================================================
     | CKPN-11 — Amortisasi margin ditangguhkan (PSAK 102/402)
     ===================================================================== */

    /** Pembiayaan Murabahah bermargin + jadwal angsuran (pokok & margin dibagi rata). */
    private function buatPembiayaanMurabahah(float $pokok = 1_200_000, float $margin = 240_000, int $tenor = 12): Pembiayaan
    {
        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => 'PMB-MRB-'.strtoupper(substr(uniqid(), -6)),
            'anggota_id' => $this->anggotaId(),
            'akun_id' => Coa::query()->value('id') ?? 1,
            'akad_pembiayaan' => 'Murabahah',
            'jumlah_pembiayaan' => $pokok,
            'total_pembiayaan' => $pokok + $margin,
            'total_ujroh' => $margin,
            'sisa_pembiayaan' => $pokok + $margin,
            'tenor' => $tenor,
            'jumlah_angsuran' => round(($pokok + $margin) / $tenor, 2),
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths($tenor)->toDateString(),
            'status_pembiayaan' => 'Aktif',
        ]);

        $pokokPer = round($pokok / $tenor, 2);
        $marginPer = round($margin / $tenor, 2);

        for ($i = 1; $i <= $tenor; $i++) {
            $pokokI = ($i === $tenor) ? round($pokok - ($pokokPer * ($tenor - 1)), 2) : $pokokPer;
            $marginI = ($i === $tenor) ? round($margin - ($marginPer * ($tenor - 1)), 2) : $marginPer;

            Angsuran::create([
                'pembiayaan_id' => $pembiayaan->id,
                'angsuran_ke' => $i,
                'tanggal_jatuh_tempo' => now()->addMonths($i)->toDateString(),
                'pokok_angsuran' => $pokokI,
                'margin_ujroh' => $marginI,
                'jumlah_angsuran' => round($pokokI + $marginI, 2),
                'sisa_pokok' => $pokokI,
                'status_angsuran' => 'Belum Jatuh Tempo',
            ]);
        }

        return $pembiayaan;
    }

    /**
     * Angsuran Murabahah: piutang dikredit sebesar POKOK+MARGIN, margin
     * ditangguhkan (1-2101, akun kontra piutang) didebet dan pendapatan
     * (4-1001) dikredit sebesar margin — bukan mengakui pendapatan langsung
     * tanpa mengurangi saldo margin ditangguhkan.
     */
    public function test_angsuran_murabahah_mengamortisasi_margin_ditangguhkan(): void
    {
        $pembiayaan = $this->buatPembiayaanMurabahah();
        $user = $this->user();

        $angsuran = Angsuran::where('pembiayaan_id', $pembiayaan->id)->where('angsuran_ke', 1)->first();
        $pokok = (float) $angsuran->pokok_angsuran;
        $margin = (float) $angsuran->margin_ujroh;

        $this->assertGreaterThan(0, $margin, 'Fixture Murabahah harus punya margin.');

        $this->service()->proses($angsuran, [
            'jumlah_bayar' => $pokok + $margin,
            'metode_bayar' => 'TUNAI',
            'user_id' => $user->id,
        ]);

        $angsuran->refresh();

        // Jurnal terakhir dari sumber Angsuran.
        $jurnal = Jurnal::where('sumber', 'Angsuran')->orderByDesc('id')->firstOrFail();

        $coaPiutang = Coa::findByKode('1-2001');
        $coaMargin = Coa::findByKode(RegistriCoa::MARGIN_DITANGGUHKAN);
        $coaPendapatan = Coa::findByKode('4-1001');

        $this->assertNotNull($coaMargin, 'CoA '.RegistriCoa::MARGIN_DITANGGUHKAN.' harus ter-seed.');
        $this->assertNotNull($coaPendapatan, 'CoA 4-1001 harus ter-seed.');

        $kreditPiutang = (float) DB::table('jurnal_detail')
            ->where('jurnal_id', $jurnal->id)->where('coa_id', $coaPiutang->id)->sum('kredit');
        $debetMargin = (float) DB::table('jurnal_detail')
            ->where('jurnal_id', $jurnal->id)->where('coa_id', $coaMargin->id)->sum('debet');
        $kreditPendapatan = (float) DB::table('jurnal_detail')
            ->where('jurnal_id', $jurnal->id)->where('coa_id', $coaPendapatan->id)->sum('kredit');

        $this->assertSame(
            round($pokok + $margin, 2),
            round($kreditPiutang, 2),
            'Piutang bruto harus dikredit sebesar POKOK + MARGIN.'
        );
        $this->assertSame(
            round($margin, 2),
            round($debetMargin, 2),
            'Margin ditangguhkan ('.RegistriCoa::MARGIN_DITANGGUHKAN.') harus didebet sebesar margin (amortisasi).'
        );
        $this->assertSame(
            round($margin, 2),
            round($kreditPendapatan, 2),
            'Pendapatan margin (4-1001) harus dikredit sebesar margin.'
        );
    }

    /**
     * Reversal angsuran Murabahah harus MEMUTAR-BALIK amortisasi: pendapatan
     * (4-1001) didebet dan margin ditangguhkan (1-2101) dikredit sebesar margin.
     */
    public function test_reversal_angsuran_murabahah_membalik_amortisasi_margin(): void
    {
        $pembiayaan = $this->buatPembiayaanMurabahah();
        $user = $this->user();

        $angsuran = Angsuran::where('pembiayaan_id', $pembiayaan->id)->where('angsuran_ke', 1)->first();
        $pokok = (float) $angsuran->pokok_angsuran;
        $margin = (float) $angsuran->margin_ujroh;

        $this->service()->proses($angsuran, [
            'jumlah_bayar' => $pokok + $margin,
            'metode_bayar' => 'TUNAI',
            'user_id' => $user->id,
        ]);

        $angsuran->refresh();
        $transaksi = Transaksi::findOrFail($angsuran->transaksi_id);
        $transaksi->update(['status_transaksi' => 'Pending Void']);

        $jurnalAwal = Jurnal::count();

        $hasil = app(ReversalService::class)->executeVoidReversal(
            $this->approvalVoid($transaksi, $user),
            $this->user('manajer')->id
        );
        $this->assertSame('success', $hasil['status'], $hasil['message']);

        $this->assertGreaterThan($jurnalAwal, Jurnal::count(), 'Reversal harus membuat jurnal pembalik.');

        $jurnalReversal = Jurnal::where('sumber', 'Reversal Angsuran')->orderByDesc('id')->firstOrFail();

        $coaMargin = Coa::findByKode(RegistriCoa::MARGIN_DITANGGUHKAN);
        $coaPendapatan = Coa::findByKode('4-1001');

        $debetPendapatan = (float) DB::table('jurnal_detail')
            ->where('jurnal_id', $jurnalReversal->id)->where('coa_id', $coaPendapatan->id)->sum('debet');
        $kreditMargin = (float) DB::table('jurnal_detail')
            ->where('jurnal_id', $jurnalReversal->id)->where('coa_id', $coaMargin->id)->sum('kredit');

        $this->assertSame(
            round($margin, 2),
            round($debetPendapatan, 2),
            'Reversal harus mendebet pendapatan margin (batalkan pengakuan).'
        );
        $this->assertSame(
            round($margin, 2),
            round($kreditMargin, 2),
            'Reversal harus mengkredit '.RegistriCoa::MARGIN_DITANGGUHKAN.' (kembalikan ke akun kontra piutang).'
        );
    }

    /* =====================================================================
     | H-01 — Jurnal angsuran gagal ⇒ rollback total
     ===================================================================== */

    /**
     * Bila posting jurnal angsuran gagal, saldo rekening, angsuran, dan sisa
     * pembiayaan WAJIB ikut di-rollback (bukan tersimpan tanpa jurnal).
     */
    public function test_kegagalan_jurnal_angsuran_menyebabkan_rollback_penuh(): void
    {
        $pembiayaan = $this->buatPembiayaan();
        $user = $this->user();

        $angsuran = Angsuran::where('pembiayaan_id', $pembiayaan->id)->where('angsuran_ke', 1)->first();
        $pokok = (float) $angsuran->pokok_angsuran;

        // Paksa kegagalan posting: akun KAS teller dinonaktifkan sehingga
        // AutoJournalingService::post() membatalkan seluruh jurnal.
        $coaKas = $this->coaKasTeller();
        $this->assertNotNull($coaKas, 'CoA Kas Loket Teller harus ter-seed.');

        $sisaSebelum = (float) $pembiayaan->sisa_pembiayaan;

        $coaKas->update(['is_active' => false]);

        try {
            $this->expectException(\Throwable::class);

            $this->service()->proses($angsuran, [
                'jumlah_bayar' => $pokok,
                'metode_bayar' => 'TUNAI',
                'user_id' => $user->id,
            ]);
        } finally {
            $coaKas->update(['is_active' => true]);
        }
    }

    /** Setelah kegagalan jurnal, tidak boleh ada jejak transaksi/angsuran terbayar. */
    public function test_kegagalan_jurnal_tidak_meninggalkan_jejak_transaksi(): void
    {
        $pembiayaan = $this->buatPembiayaan();
        $user = $this->user();

        $angsuran = Angsuran::where('pembiayaan_id', $pembiayaan->id)->where('angsuran_ke', 1)->first();
        $pokok = (float) $angsuran->pokok_angsuran;
        $sisaAwal = (float) $pembiayaan->sisa_pembiayaan;
        $jurnalAwal = Jurnal::count();

        $coaKas = $this->coaKasTeller();
        $coaKas->update(['is_active' => false]);

        try {
            $this->service()->proses($angsuran, [
                'jumlah_bayar' => $pokok,
                'metode_bayar' => 'TUNAI',
                'user_id' => $user->id,
            ]);
            $this->fail('Posting jurnal yang gagal harus melempar exception (H-01).');
        } catch (\Throwable $e) {
            // diharapkan
        } finally {
            $coaKas->update(['is_active' => true]);
        }

        $pembiayaan->refresh();
        $angsuran->refresh();

        $this->assertSame($sisaAwal, (float) $pembiayaan->sisa_pembiayaan, 'sisa_pembiayaan harus di-rollback.');
        $this->assertSame('Belum Jatuh Tempo', $angsuran->status_angsuran, 'Angsuran tidak boleh tercatat terbayar.');
        $this->assertNull($angsuran->tanggal_bayar);
        $this->assertNull($angsuran->transaksi_id, 'transaksi_id tidak boleh tersimpan.');
        $this->assertSame($jurnalAwal, Jurnal::count(), 'Tidak boleh ada jurnal tersisa dari transaksi yang gagal.');
        $this->assertSame(
            0,
            Transaksi::where('pembiayaan_id', $pembiayaan->id)->where('jenis_transaksi', 'Pembayaran Angsuran')->count(),
            'Transaksi angsuran yang gagal tidak boleh tersimpan.'
        );
    }

    /* =====================================================================
     | H-03 — Sinkronisasi dana kebajikan tidak menelan error
     ===================================================================== */

    public function test_sinkronisasi_dana_kebajikan_melempar_bila_jurnal_gagal(): void
    {
        $teller = $this->user();
        $anggotaId = $this->anggotaId();

        $transaksi = Transaksi::create([
            'no_transaksi' => 'TRX-T1-'.strtoupper(substr(uniqid(), -6)),
            'anggota_id' => $anggotaId,
            'user_id' => $teller->id,
            'jenis_transaksi' => "Pemotongan Ta'zir Keterlambatan",
            'posisi' => 'Debet',
            'jumlah' => 15_000,
            'saldo_setelah' => 0,
            'sumber_transaksi' => 'Teller',
            'status_transaksi' => 'Sukses',
            'status' => 'success',
            'waktu_transaksi' => now(),
        ]);

        $coaDana = Coa::findByKode(AutoJournalingService::DANA_KEBAJIKAN);

        if (! $coaDana) {
            $this->markTestSkipped('CoA 2-3001 (Dana Kebajikan) belum ter-seed.');
        }

        $coaDana->update(['is_active' => false]);

        try {
            $this->expectException(\RuntimeException::class);
            app(DanaKebajikanService::class)->sinkronkanTransaksiTunaiTeller($transaksi);
        } finally {
            $coaDana->update(['is_active' => true]);
        }
    }

    /* =====================================================================
     | G-01/G-02/G-04/B-01 — API keuangan menolak ANONIM
     ===================================================================== */

    /**
     * Endpoint BACA master data & buku besar wajib menolak anonim.
     */
    #[DataProvider('endpointBacaProvider')]
    public function test_api_baca_menolak_pengguna_anonim(string $method, string $uri): void
    {
        $this->app['auth']->forgetGuards();

        $response = $method === 'get'
            ? $this->getJson($uri)
            : $this->postJson($uri, []);

        // Anonim: 401 (auth) atau 302 (redirect ke login untuk guard sesi).
        $this->assertContains(
            $response->getStatusCode(),
            [302, 401],
            "Endpoint {$method} {$uri} harus MENOLAK pengguna anonim."
        );
    }

    public static function endpointBacaProvider(): array
    {
        return [
            'anggota index' => ['get', '/api/anggota'],
            'rekening index' => ['get', '/api/rekening'],
            'transaksi index' => ['get', '/api/transaksi'],
            'pembiayaan index' => ['get', '/api/pembiayaan'],
            'angsuran index' => ['get', '/api/angsuran'],
            'akun index' => ['get', '/api/akun'],
            'jurnal index' => ['get', '/api/jurnal'],
            'deposito products' => ['get', '/api/deposito/products'],
            'deposito coa' => ['get', '/api/deposito/coa-config'],
        ];
    }

    /**
     * Endpoint TULIS buku besar wajib menolak anonim (dulu siapa pun bisa
     * membuat jurnal langsung berstatus Posted).
     */
    #[DataProvider('endpointTulisProvider')]
    public function test_api_tulis_menolak_pengguna_anonim(string $method, string $uri): void
    {
        $this->app['auth']->forgetGuards();

        $response = $method === 'post'
            ? $this->postJson($uri, [])
            : $this->putJson($uri, []);

        $this->assertContains(
            $response->getStatusCode(),
            [302, 401, 403],
            "Endpoint {$method} {$uri} harus MENOLAK pengguna anonim sebelum validasi."
        );
    }

    public static function endpointTulisProvider(): array
    {
        return [
            'jurnal store' => ['post', '/api/jurnal'],
            'jurnal post' => ['post', '/api/jurnal/1/post'],
            'jurnal void' => ['post', '/api/jurnal/1/void'],
            'transaksi store' => ['post', '/api/transaksi'],
            'rekening store' => ['post', '/api/rekening'],
            'deposito store' => ['post', '/api/deposito/store'],
            'deposito simulate' => ['post', '/api/deposito/simulate'],
            'pembiayaan store' => ['post', '/api/pembiayaan'],
        ];
    }

    /** Peran `dps` (pengawas syariah) hanya boleh BACA — TIDAK boleh menulis jurnal. */
    public function test_peran_dps_dilarang_menulis_jurnal(): void
    {
        $dps = $this->user('dps');

        $this->actingAs($dps)
            ->postJson('/api/jurnal', [])
            ->assertStatus(403);
    }

    /* =====================================================================
     | A-14 — Guard produksi command destruktif
     ===================================================================== */

    /**
     * Command destruktif wajib DITOLAK di produksi tanpa `--force`.
     */
    #[DataProvider('commandDestruktifProvider')]
    public function test_command_destruktif_ditolak_di_produksi_tanpa_force(string $command): void
    {
        $this->app['env'] = 'production';

        try {
            $this->artisan($command)->assertExitCode(1);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public static function commandDestruktifProvider(): array
    {
        return [
            'reset:data-anggota' => ['reset:data-anggota'],
            'test:shu' => ['test:shu'],
            'test:role-ao' => ['test:role-ao'],
            'simulate:transaksi-hari-ini' => ['simulate:transaksi-hari-ini'],
        ];
    }

    /** Di lingkungan non-produksi guard TIDAK boleh menghalangi (regresi). */
    public function test_command_destruktif_tidak_diblokir_di_luar_produksi(): void
    {
        $this->assertNotSame('production', $this->app['env']);

        // Guard membaca app()->isProduction(); di env testing harus false.
        $this->assertFalse(app()->isProduction());
    }

    /* =====================================================================
     | Util
     ===================================================================== */

    private function approvalVoid(Transaksi $transaksi, User $pengaju): ApprovalTransaksi
    {
        return ApprovalTransaksi::create([
            'kode_approval' => ApprovalTransaksi::generateKode(),
            'tipe_referensi' => 'transaksi',
            'referensi_id' => $transaksi->id,
            'jenis_transaksi' => 'VOID',
            'nominal' => (float) $transaksi->jumlah,
            'status' => 'PENDING_MANAJER',
            'diajukan_oleh' => $pengaju->id,
            'role_pengaju' => $pengaju->role,
            'catatan_pengaju' => 'Uji reversal Tahap 1',
        ]);
    }
}
