<?php

namespace Tests\Feature;

use App\Models\Angsuran;
use App\Models\ApprovalTransaksi;
use App\Models\Coa;
use App\Models\KasHarian;
use App\Models\KebijakanKoperasi;
use App\Models\Pembiayaan;
use App\Models\PengeluaranBeban;
use App\Models\TazirReliefRequest;
use App\Models\TellerOpname;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\ReversalService;
use App\Services\TransactionLimitService;
use App\Support\PemisahanTugas;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * GCG — PEMISAHAN TUGAS (Maker ≠ Checker).
 *
 * Mengunci aturan tata kelola: satu akun TIDAK BOLEH memutuskan (menyetujui /
 * menolak) pengajuan yang ia ajukan sendiri. Tanpa kontrol ini, hierarki
 * Teller → AO → Manajer → Pengurus dapat dilangkahi oleh satu orang dan jejak
 * audit kehilangan nilai pembuktiannya (temuan GCG/OJK).
 *
 * Cakupan:
 *  1. TransactionLimitService::approveTransaction  (approval limit transaksi)
 *  2. TransactionLimitService::rejectTransaction
 *  3. ReversalService::executeVoidReversal         (void transaksi)
 *  4. PengeluaranBebanController::approve          (beban > limit, jalur HTTP)
 *  5. PengeluaranBebanController::reject           (jalur HTTP)
 *  6. ApprovalTazirController::approve             (keringanan ta'zir, jalur HTTP)
 *  7. ClosingApprovalController::approve           (closing kas teller, jalur HTTP)
 *  8. AmlComplianceController::verify              (verifikasi AML, jalur HTTP)
 *  9. Helper PemisahanTugas itu sendiri (kontrak & nilai null)
 *
 * Prinsip pengujian:
 *  - Setiap kasus diuji DUA sisi: maker ≠ checker ⇒ DIPROSES; maker = checker
 *    ⇒ DITOLAK dengan pesan standar PemisahanTugas::PESAN.
 *  - Test TIDAK melonggarkan kode produksi; bila alur lama bertabrakan dengan
 *    aturan ini, test lama yang diselaraskan ke alur maker–checker nyata.
 */
class PemisahanTugasGcgTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Ta'zir hanya sah bila fitur denda harian aktif — dipakai jalur tazir.
        $kebijakan = KebijakanKoperasi::first() ?? new KebijakanKoperasi;
        $kebijakan->tazir_mode = 'FLAT_HARIAN';
        $kebijakan->financing_late_fee_per_day = 2500.00;
        $kebijakan->save();

        Setting::flush();
    }

    /* =====================================================================
     | Helper
     ===================================================================== */

    private function user(string $role, string $suffix = ''): User
    {
        $email = "gcg.{$role}{$suffix}@test.local";

        return User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'GCG '.ucfirst($role).$suffix,
                'password' => Hash::make('password'),
                'role' => $role,
                'is_active' => true,
            ]
        );
    }

    private function anggotaId(): int
    {
        $anggotaId = DB::table('anggota')->value('id');

        if (! $anggotaId) {
            $anggotaId = DB::table('anggota')->insertGetId([
                'nama_lengkap' => 'Anggota GCG',
                'no_anggota' => 'AGT-GCG-'.strtoupper(substr(uniqid(), -6)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return (int) $anggotaId;
    }

    /** Pembiayaan Qardh sederhana + satu jadwal angsuran untuk uji void. */
    private function buatPembiayaan(float $total = 1_200_000): Pembiayaan
    {
        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => 'PMB-GCG-'.strtoupper(substr(uniqid(), -6)),
            'anggota_id' => $this->anggotaId(),
            'akun_id' => Coa::query()->value('id') ?? 1,
            'akad_pembiayaan' => 'Qardh',
            'jumlah_pembiayaan' => $total,
            'total_pembiayaan' => $total,
            'sisa_pembiayaan' => $total,
            'tenor' => 12,
            'jumlah_angsuran' => round($total / 12, 2),
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths(12)->toDateString(),
            'status_pembiayaan' => 'Aktif',
        ]);

        for ($i = 1; $i <= 12; $i++) {
            $pokok = round($total / 12, 2);

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

    /* =====================================================================
     | 0. Kontrak helper PemisahanTugas
     ===================================================================== */

    public function test_helper_pemisahan_tugas_mengenali_pelanggaran(): void
    {
        $this->assertTrue(PemisahanTugas::dilanggar(7, 7), 'ID sama harus dikenali sebagai pelanggaran.');
        $this->assertTrue(PemisahanTugas::dilanggar('7', 7), 'Perbandingan harus tahan tipe (string vs int).');
        $this->assertFalse(PemisahanTugas::dilanggar(7, 8));
        $this->assertFalse(PemisahanTugas::dilanggar(null, 7), 'Pengaju null = data sistem, bukan pelanggaran.');
        $this->assertFalse(PemisahanTugas::dilanggar(7, null));
        $this->assertFalse(PemisahanTugas::dilanggar(null, null));
        $this->assertFalse(PemisahanTugas::dilanggar('', 7), 'String kosong disamakan dengan null.');
    }

    public function test_helper_multi_kolom_menangkap_salah_satu_pengaju(): void
    {
        // Ta'zir: teller_id = 3, ao_id = 5.
        $this->assertTrue(PemisahanTugas::dilanggarSalahSatu([3, 5], 5));
        $this->assertTrue(PemisahanTugas::dilanggarSalahSatu([3, 5], 3));
        $this->assertFalse(PemisahanTugas::dilanggarSalahSatu([3, 5], 9));
        $this->assertFalse(PemisahanTugas::dilanggarSalahSatu([null, 5], 9), 'Kolom pengaju null diabaikan.');
    }

    /* =====================================================================
     | 1 & 2. Approval limit transaksi (service — jalur HTTP + console)
     ===================================================================== */

    public function test_approval_transaksi_ditolak_bila_pengaju_menyetujui_sendiri(): void
    {
        $pengaju = $this->user('manajer', 'Pengaju');
        $approval = $this->approvalPending($pengaju);

        $hasil = app(TransactionLimitService::class)
            ->approveTransaction($approval, $pengaju, 'Percobaan self-approve');

        $this->assertSame('error', $hasil['status']);
        $this->assertSame(PemisahanTugas::PESAN, $hasil['message']);

        $approval->refresh();
        $this->assertSame('PENDING_MANAJER', $approval->status, 'Status TIDAK boleh berubah saat self-approve ditolak.');
    }

    public function test_approval_transaksi_diproses_bila_pemutus_berbeda(): void
    {
        $pengaju = $this->user('teller', 'Pengaju');
        $checker = $this->user('manajer', 'Checker');
        $approval = $this->approvalPending($pengaju);

        $hasil = app(TransactionLimitService::class)
            ->approveTransaction($approval, $checker, 'Disetujui manajer');

        $this->assertSame('SUCCESS', strtoupper((string) $hasil['status']), $hasil['message'] ?? '');

        $approval->refresh();
        $this->assertSame('APPROVED', $approval->status);
        $this->assertSame($checker->id, (int) $approval->disetujui_oleh);
    }

    public function test_penolakan_transaksi_ditolak_bila_pengaju_menolak_sendiri(): void
    {
        $pengaju = $this->user('manajer', 'PengajuTolak');
        $approval = $this->approvalPending($pengaju);

        $hasil = app(TransactionLimitService::class)
            ->rejectTransaction($approval, $pengaju, 'Menolak sendiri');

        $this->assertSame('error', $hasil['status']);
        $this->assertSame(PemisahanTugas::PESAN, $hasil['message']);

        $approval->refresh();
        $this->assertSame('PENDING_MANAJER', $approval->status);
    }

    /** Approval transaksi PENDING_MANAJER + transaksi rujukan minimal. */
    private function approvalPending(User $pengaju): ApprovalTransaksi
    {
        $transaksi = Transaksi::create([
            'no_transaksi' => 'GCG-'.strtoupper(substr(uniqid(), -8)),
            'jenis_transaksi' => 'Setoran Tunai',
            'jumlah' => 25_000_000,
            'posisi' => 'Debet',
            'status_transaksi' => 'Pending',
            'status' => 'pending_approval',
            'dibuat_oleh' => $pengaju->id,
            'user_id' => $pengaju->id,
            'anggota_id' => $this->anggotaId(),
            'waktu_transaksi' => now(),
        ]);

        return ApprovalTransaksi::create([
            'kode_approval' => ApprovalTransaksi::generateKode(),
            'tipe_referensi' => 'transaksi',
            'referensi_id' => $transaksi->id,
            'jenis_transaksi' => 'Setoran Tunai',
            'nominal' => 25_000_000,
            'status' => 'PENDING_MANAJER',
            'diajukan_oleh' => $pengaju->id,
            'role_pengaju' => $pengaju->role,
        ]);
    }

    /* =====================================================================
     | 3. Void reversal transaksi
     ===================================================================== */

    public function test_void_reversal_ditolak_bila_pemohon_mengeksekusi_sendiri(): void
    {
        [$transaksi, $pemohon] = $this->transaksiPendingVoid();

        $hasil = app(ReversalService::class)->executeVoidReversal(
            $this->approvalVoid($transaksi, $pemohon),
            $pemohon->id
        );

        $this->assertSame('error', $hasil['status']);
        $this->assertSame(PemisahanTugas::PESAN, $hasil['message']);
    }

    public function test_void_reversal_diproses_bila_pemutus_berbeda(): void
    {
        [$transaksi, $pemohon] = $this->transaksiPendingVoid();
        $checker = $this->user('manajer', 'VoidChecker');

        $hasil = app(ReversalService::class)->executeVoidReversal(
            $this->approvalVoid($transaksi, $pemohon),
            $checker->id
        );

        $this->assertSame('success', $hasil['status'], $hasil['message'] ?? '');
    }

    /** Transaksi sederhana berstatus Pending Void + identitas pemohon void. */
    private function transaksiPendingVoid(): array
    {
        $pemohon = $this->user('teller', 'VoidPemohon');

        $transaksi = Transaksi::create([
            'no_transaksi' => 'GCG-VOID-'.strtoupper(substr(uniqid(), -6)),
            'jenis_transaksi' => 'Setoran Tunai',
            'jumlah' => 50_000,
            'posisi' => 'Debet',
            'status_transaksi' => 'Pending Void',
            'status' => 'success',
            'dibuat_oleh' => $pemohon->id,
            'user_id' => $pemohon->id,
            'anggota_id' => $this->anggotaId(),
            'waktu_transaksi' => now(),
        ]);

        return [$transaksi, $pemohon];
    }

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
        ]);
    }

    /* =====================================================================
     | 4 & 5. Pengeluaran beban > limit (jalur HTTP, role manajer)
     ===================================================================== */

    public function test_pengeluaran_beban_approve_ditolak_bila_penginput_menyetujui_sendiri(): void
    {
        $penginput = $this->user('manajer', 'BebanPenginput');
        $beban = $this->pengeluaranPending($penginput);

        $this->actingAs($penginput)
            ->post(route('manajer.beban.approve', $beban->id), ['catatan' => 'Setuju sendiri'])
            ->assertSessionHas('error', PemisahanTugas::PESAN);

        $beban->refresh();
        $this->assertSame('Pending', $beban->status, 'Beban TIDAK boleh berubah status saat self-approve ditolak.');
    }

    public function test_pengeluaran_beban_approve_diproses_bila_pemutus_berbeda(): void
    {
        $penginput = $this->user('teller', 'BebanPenginput2');
        $checker = $this->user('manajer', 'BebanChecker');
        $beban = $this->pengeluaranPending($penginput);

        $this->actingAs($checker)
            ->post(route('manajer.beban.approve', $beban->id), ['catatan' => 'Disetujui'])
            ->assertSessionHas('success');

        $beban->refresh();
        $this->assertSame('Approved', $beban->status);
        $this->assertSame($checker->id, (int) $beban->approved_by);
    }

    public function test_pengeluaran_beban_reject_ditolak_bila_penginput_menolak_sendiri(): void
    {
        $penginput = $this->user('manajer', 'BebanPenginput3');
        $beban = $this->pengeluaranPending($penginput);

        $this->actingAs($penginput)
            ->post(route('manajer.beban.reject', $beban->id), ['catatan' => 'Tolak sendiri'])
            ->assertSessionHas('error', PemisahanTugas::PESAN);

        $beban->refresh();
        $this->assertSame('Pending', $beban->status);
    }

    /** Pengeluaran beban di atas limit approval sehingga berstatus Pending. */
    private function pengeluaranPending(User $penginput): PengeluaranBeban
    {
        $coaKas = Coa::findByKode('1-1001') ?? Coa::where('kategori', 'Aset')->firstOrFail();
        $coaBeban = Coa::where('kategori', 'Beban')->first()
            ?? Coa::where('kode_akun', 'like', '5-%')->firstOrFail();

        return PengeluaranBeban::create([
            'no_pengeluaran' => 'BB-'.strtoupper(substr(uniqid(), -8)),
            'tanggal' => now()->toDateString(),
            'coa_beban_id' => $coaBeban->id,
            'coa_kas_id' => $coaKas->id,
            'nominal' => PengeluaranBeban::LIMIT_APPROVAL + 50_000,
            'keterangan' => 'Uji pemisahan tugas',
            'status' => 'Pending',
            'created_by' => $penginput->id,
        ]);
    }

    /* =====================================================================
     | 6. Keringanan Ta'zir (jalur HTTP, role manajer)
     ===================================================================== */

    public function test_keringanan_tazir_approve_ditolak_bila_pengaju_menyetujui_sendiri(): void
    {
        $pengaju = $this->user('manajer', 'TazirPengaju');
        $relief = $this->reliefPendingManajer($pengaju, null);

        $this->actingAs($pengaju)
            ->post(route('manajer.keringanan-tazir.approve', $relief->id), ['catatan_manajer' => 'Setuju sendiri'])
            ->assertSessionHas('error', PemisahanTugas::PESAN);

        $relief->refresh();
        $this->assertSame(TazirReliefRequest::STATUS_PENDING_MANAJER, $relief->status);
    }

    public function test_keringanan_tazir_approve_ditolak_bila_ao_perekomendasi_menyetujui(): void
    {
        $pengaju = $this->user('teller', 'TazirPengaju2');
        $ao = $this->user('manajer', 'TazirAo');
        $relief = $this->reliefPendingManajer($pengaju, $ao);

        $this->actingAs($ao)
            ->post(route('manajer.keringanan-tazir.approve', $relief->id), ['catatan_manajer' => 'Setuju'])
            ->assertSessionHas('error', PemisahanTugas::PESAN);
    }

    public function test_keringanan_tazir_approve_diproses_bila_pemutus_berbeda(): void
    {
        $pengaju = $this->user('teller', 'TazirPengaju3');
        $checker = $this->user('manajer', 'TazirChecker');
        $relief = $this->reliefPendingManajer($pengaju, null);

        $this->actingAs($checker)
            ->post(route('manajer.keringanan-tazir.approve', $relief->id), ['catatan_manajer' => 'Disetujui'])
            ->assertSessionHas('success');

        $relief->refresh();
        $this->assertSame(TazirReliefRequest::STATUS_APPROVED, $relief->status);
        $this->assertSame($checker->id, (int) $relief->manajer_id);
    }

    /** Pengajuan keringanan ta'zir pada tahap keputusan Manajer. */
    private function reliefPendingManajer(User $teller, ?User $ao): TazirReliefRequest
    {
        $pembiayaan = $this->buatPembiayaan(1_200_000);
        $angsuran = Angsuran::where('pembiayaan_id', $pembiayaan->id)->where('angsuran_ke', 1)->firstOrFail();

        return TazirReliefRequest::create([
            'jadwal_angsuran_id' => $angsuran->id,
            'pembiayaan_id' => $pembiayaan->id,
            'anggota_id' => $pembiayaan->anggota_id,
            'nominal_tazir_awal' => 30_000,
            'nominal_tazir_disetujui' => 0,
            'alasan_teller' => 'Uji pemisahan tugas',
            'teller_id' => $teller->id,
            'rekomendasi_ao' => $ao ? TazirReliefRequest::REKOMENDASI_SETUJU : null,
            'catatan_ao' => $ao ? 'Rekomendasi setuju' : null,
            'ao_id' => $ao?->id,
            'status' => TazirReliefRequest::STATUS_PENDING_MANAJER,
        ]);
    }

    /* =====================================================================
     | 7. Closing kas teller (jalur HTTP, role manajer)
     ===================================================================== */

    public function test_closing_kas_approve_diproses_bila_pemutus_berbeda(): void
    {
        $teller = $this->user('teller', 'ClosingPengaju');
        $opname = $this->opnamePending($teller);

        $pemutus = $this->user('manajer', 'ClosingChecker');

        $this->actingAs($pemutus)
            ->post(route('manajer.closing.approve', $opname->id))
            ->assertSessionDoesntHaveErrors();

        $opname->refresh();
        $this->assertSame(TellerOpname::STATUS_CLOSED, $opname->status, 'Maker ≠ checker harus diterima.');
    }

    public function test_closing_kas_guard_menolak_bila_user_pemutus_adalah_pemilik_sesi_kas(): void
    {
        /* Kasus nyata: seorang Manajer juga tercatat sebagai pemilik sesi kas
         | (merangkap teller). Guard WAJIB tetap menolak self-approve — peran
         | teknis yang berwenang tidak boleh melangkahi pemisahan tugas. */
        $manajer = $this->user('manajer', 'ClosingSelf2');
        $opname = $this->opnamePending($manajer);

        $this->actingAs($manajer)
            ->post(route('manajer.closing.approve', $opname->id))
            ->assertSessionHas('error', PemisahanTugas::PESAN);

        $opname->refresh();
        $this->assertSame(TellerOpname::STATUS_PENDING, $opname->status, 'Opname TIDAK boleh ditutup saat self-approve ditolak.');
    }

    /** Opname pending milik teller tertentu (tanpa menyentuh KasHarian produksi). */
    private function opnamePending(User $teller): TellerOpname
    {
        $kas = KasHarian::create([
            'user_id' => $teller->id,
            'tanggal' => now()->toDateString(),
            'modal_awal' => 1_000_000,
            'tambah_modal' => [],
            'waktu_opening' => now()->format('H:i:s'),
            'status_opname' => KasHarian::STATUS_OPEN,
        ]);

        return TellerOpname::create([
            'kas_harian_id' => $kas->id,
            'user_id' => $teller->id,
            'tanggal' => now()->toDateString(),
            'saldo_sistem' => 1_000_000,
            'total_fisik' => 1_000_000,
            'selisih' => 0,
            'rincian_pecahan' => [100000 => 10],
            'status' => TellerOpname::STATUS_PENDING,
            'status_selisih' => TellerOpname::SELISIH_BALANCED,
        ]);
    }

    /* =====================================================================
     | 8. Verifikasi AML — transaksi high value (jalur HTTP, role pengurus)
     ===================================================================== */

    public function test_verifikasi_aml_ditolak_bila_penginput_verifikasi_sendiri(): void
    {
        $pengurus = $this->user('pengurus', 'AmlPenginput');
        $transaksi = $this->transaksiHighValue($pengurus);

        $this->actingAs($pengurus)
            ->post(route('pengurus.aml.verify', $transaksi->id), [
                'aml_sumber_dana' => 'Gaji',
                'aml_status_verifikasi' => 'menunggu',
            ])
            ->assertSessionHas('error', PemisahanTugas::PESAN);

        $transaksi->refresh();
        $this->assertNotSame('terverifikasi', $transaksi->aml_status_verifikasi);
    }

    public function test_verifikasi_aml_diproses_bila_verifikator_berbeda(): void
    {
        $penginput = $this->user('teller', 'AmlPenginput2');
        $verifikator = $this->user('pengurus', 'AmlVerifikator');
        $transaksi = $this->transaksiHighValue($penginput);

        $this->actingAs($verifikator)
            ->post(route('pengurus.aml.verify', $transaksi->id), [
                'aml_sumber_dana' => 'Hasil usaha',
                'aml_status_verifikasi' => 'menunggu',
            ])
            ->assertSessionDoesntHaveErrors();

        $transaksi->refresh();
        $this->assertSame('menunggu', $transaksi->aml_status_verifikasi);
    }

    public function test_penolakan_aml_ditolak_bila_penginput_menolak_sendiri(): void
    {
        $pengurus = $this->user('pengurus', 'AmlPenginput3');
        $transaksi = $this->transaksiHighValue($pengurus);

        $this->actingAs($pengurus)
            ->post(route('pengurus.aml.tolak', $transaksi->id), [
                'aml_catatan' => 'Tolak sendiri',
            ])
            ->assertSessionHas('error', PemisahanTugas::PESAN);

        $transaksi->refresh();
        $this->assertSame('menunggu', $transaksi->aml_status_verifikasi);
    }

    /** Transaksi bernilai besar yang menunggu verifikasi kepatuhan AML. */
    private function transaksiHighValue(User $penginput): Transaksi
    {
        return Transaksi::create([
            'no_transaksi' => 'GCG-AML-'.strtoupper(substr(uniqid(), -6)),
            'jenis_transaksi' => 'Setoran Tunai',
            'jumlah' => Setting::amlThreshold() + 1_000_000,
            'posisi' => 'Debet',
            'status_transaksi' => 'Pending',
            'status' => 'pending_approval',
            'dibuat_oleh' => $penginput->id,
            'user_id' => $penginput->id,
            'anggota_id' => $this->anggotaId(),
            'waktu_transaksi' => now(),
            'is_aml_high_value' => true,
            'aml_status_verifikasi' => 'menunggu',
        ]);
    }
}
