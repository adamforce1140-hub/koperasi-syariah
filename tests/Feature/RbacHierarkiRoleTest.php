<?php

namespace Tests\Feature;

use App\Models\ApprovalTransaksi;
use App\Models\ApprovalTransaksiLog;
use App\Models\KebijakanKoperasi;
use App\Models\LimitTransaksiRole;
use App\Models\User;
use App\Services\TransactionLimitService;
use App\Support\HierarkiRole;
use App\Support\PemisahanTugas;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * RBAC — HIERARKI PERAN, PENEGAKAN BATAS, & RANTAI APPROVAL BERJENJANG.
 *
 * Mengunci tiga janji sistem:
 *  1. PENEGAKAN BATAS — nominal per transaksi, akumulasi harian, dan frekuensi
 *     aksi ditegakkan persis seperti konfigurasi, tanpa jalur lolos.
 *  2. RANTAI APPROVAL — pengajuan yang melewati limit naik ke peran yang
 *     berwenang (Teller → AO → Manajer → Pengurus); pemutusan hanya sah bila
 *     dilakukan peran pada tahap itu.
 *  3. KONFLIK LINTAS-PERAN — percobaan memutus oleh peran tak berwenang dan
 *     self-approval (maker = checker) DITOLAK serta TEREKAM pada jejak audit.
 *
 * Prinsip: test TIDAK melonggarkan kode produksi. Setiap ambang dibaca dari
 * sumber kebenaran (config/HierarkiRole), bukan dipatok ulang, agar perubahan
 * kebijakan tidak membuat test berbohong.
 */
class RbacHierarkiRoleTest extends TestCase
{
    use DatabaseTransactions;

    private TransactionLimitService $service;

    /** Prefix nomor referensi agar fixture tidak bentrok (DatabaseTransactions). */
    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::flush();
        HierarkiRole::flush();
        LimitTransaksiRole::query()->delete();

        // Batas tunai teller dibaca dari kolom kebijakan koperasi (bukan dari
        // config), sehingga harus dipatok agar ambang pada test ini deterministik
        // dan tidak bergantung pada nilai yang kebetulan ada di basis data.
        $kebijakan = KebijakanKoperasi::first() ?? new KebijakanKoperasi;
        $kebijakan->limit_penarikan_teller = 5_000_000;
        $kebijakan->limit_setoran_teller = 50_000_000;
        $kebijakan->save();

        HierarkiRole::flush();

        $this->service = app(TransactionLimitService::class);
        $this->prefix = 'RBAC'.strtoupper(substr(uniqid(), -6));
    }

    protected function tearDown(): void
    {
        Setting::flush();
        HierarkiRole::flush();

        parent::tearDown();
    }

    /* ═════════════════════════════════════════════════════════════════════
     | 1. SUMBER KEBENARAN HIERARKI
     |═════════════════════════════════════════════════════════════════════ */

    public function test_hierarki_mengurutkan_kewenangan_dari_terendah_ke_tertinggi(): void
    {
        $urut = HierarkiRole::peran();

        $this->assertSame('anggota', $urut[0], 'Peran terendah harus anggota.');
        $this->assertSame('super_admin', end($urut), 'Peran tertinggi harus super_admin (puncak administratif).');

        $this->assertTrue(HierarkiRole::lebihTinggi('super_admin', 'dps'));
        $this->assertTrue(HierarkiRole::lebihTinggi('pengurus', 'manajer'));
        $this->assertTrue(HierarkiRole::lebihTinggi('teller', 'anggota'));
        $this->assertTrue(HierarkiRole::lebihTinggi('ao', 'teller'));
    }

    public function test_rantai_approval_diturunkan_berjenjang(): void
    {
        $this->assertSame('ao', HierarkiRole::rantai('teller'));
        $this->assertSame('manajer', HierarkiRole::rantai('ao'));
        $this->assertSame('pengurus', HierarkiRole::rantai('manajer'));
        $this->assertNull(HierarkiRole::rantai('pengurus'), 'Pengurus adalah puncak rantai.');

        // Status menunggu diturunkan otomatis dari nama peran.
        $this->assertSame('PENDING_AO', HierarkiRole::statusMenunggu('ao'));
        $this->assertSame('PENDING_MANAJER', HierarkiRole::statusMenunggu('manajer'));
        $this->assertSame('PENDING_PENGURUS', HierarkiRole::statusMenunggu('pengurus'));

        // Pemetaan balik status → peran berwenang.
        $this->assertSame(['ao'], HierarkiRole::peranUntukStatus('PENDING_AO'));
        $this->assertSame(['manajer'], HierarkiRole::peranUntukStatus('PENDING_MANAJER'));
        $this->assertSame(['pengurus'], HierarkiRole::peranUntukStatus('PENDING_PENGURUS'));
        $this->assertSame([], HierarkiRole::peranUntukStatus('APPROVED'));
    }

    public function test_pemisahan_peran_penyetuju_vs_non_penyetuju_dijaga(): void
    {
        foreach (['pengurus', 'manajer', 'ao'] as $peran) {
            $this->assertTrue(HierarkiRole::penyetuju($peran), "Peran {$peran} harus boleh memutus.");
        }

        foreach (['teller', 'admin', 'dps', 'anggota'] as $peran) {
            $this->assertFalse(HierarkiRole::penyetuju($peran), "Peran {$peran} TIDAK boleh memutus.");
        }
    }

    public function test_jenis_transaksi_tak_dikenal_dinilai_sebagai_non_tunai(): void
    {
        $manajer = $this->buatUser('manajer');
        $limit = (float) HierarkiRole::batas('manajer', 'non_tunai')['limit_per_transaksi'];

        // `pencairan` bukan salah satu tiga jenis operasional → dinilai non_tunai.
        $hasil = $this->service->processTransactionWithLimitCheck(
            $manajer, 'pencairan', $limit + 5_000_000, 'non_tunai', $this->dataTransaksi($manajer, $limit + 5_000_000, 'Pencairan Pembiayaan')
        );

        $this->assertSame('PENDING_APPROVAL', $hasil['status']);
        $this->assertSame('PENDING_PENGURUS', $hasil['data']['approval']->status);
    }

    /* ═════════════════════════════════════════════════════════════════════
     | 2. PENEGAKAN BATAS NOMINAL PER TRANSAKSI
     |═════════════════════════════════════════════════════════════════════ */

    public function test_transaksi_di_bawah_limit_langsung_diproses_tanpa_approval(): void
    {
        $teller = $this->buatUser('teller');
        $limit = (float) HierarkiRole::batas('teller', 'tarik_tunai')['limit_per_transaksi'];

        $hasil = $this->service->processTransactionWithLimitCheck(
            $teller, 'tarik_tunai', $limit - 1, 'tunai', $this->dataTransaksi($teller, $limit - 1, 'Penarikan Tunai')
        );

        $this->assertSame('success', $hasil['status']);
        $this->assertArrayNotHasKey('approval', $hasil['data'] ?? []);
    }

    public function test_transaksi_melebihi_limit_teller_naik_ke_ao(): void
    {
        $teller = $this->buatUser('teller');
        $limit = (float) HierarkiRole::batas('teller', 'tarik_tunai')['limit_per_transaksi'];

        $hasil = $this->service->processTransactionWithLimitCheck(
            $teller, 'tarik_tunai', $limit + 1_000_000, 'tunai', $this->dataTransaksi($teller, $limit + 1_000_000, 'Penarikan Tunai')
        );

        $this->assertSame('PENDING_APPROVAL', $hasil['status']);

        $approval = $hasil['data']['approval'];
        $this->assertSame('PENDING_AO', $approval->status);
        $this->assertSame($teller->id, (int) $approval->diajukan_oleh);
        // `role_approver` baru terisi saat keputusan diambil — pengajuan yang masih
        // menunggu belum punya pemutus, dan itu memang disengaja.
        $this->assertNull($approval->role_approver);

        $this->assertDatabaseHas('approval_transaksi', [
            'id' => $approval->id,
            'status' => 'PENDING_AO',
            'diajukan_oleh' => $teller->id,
        ]);
    }

    public function test_limit_nol_menolak_peran_tanpa_jalur_otorisasi(): void
    {
        // Teller dilarang transaksi non-tunai (limit 0). Service menangkap ini
        // lebih dulu lewat guard khusus sebelum penilaian batas.
        $teller = $this->buatUser('teller');

        $hasil = $this->service->processTransactionWithLimitCheck(
            $teller, 'non_tunai', 100_000, 'non_tunai', $this->dataTransaksi($teller, 100_000, 'Transfer Keluar')
        );

        $this->assertSame('REJECTED', $hasil['status']);
        $this->assertNull($hasil['data']);
    }

    public function test_limit_nol_peran_lain_ditolak_oleh_penilaian_batas(): void
    {
        // AO juga dilarang non-tunai (limit 0) — dinilai lewat nilaiBatas.
        $ao = $this->buatUser('ao');

        $hasil = $this->service->processTransactionWithLimitCheck(
            $ao, 'non_tunai', 100_000, 'non_tunai', $this->dataTransaksi($ao, 100_000, 'Transfer Keluar')
        );

        $this->assertSame('REJECTED', $hasil['status']);
        $this->assertStringContainsString('tidak berwenang', (string) $hasil['message']);
    }

    /* ═════════════════════════════════════════════════════════════════════
     | 3. BATAS AKUMULASI HARIAN & FREKUENSI AKSI (OVERRIDE DB)
     |═════════════════════════════════════════════════════════════════════ */

    public function test_batas_akumulasi_harian_menolak_transaksi_yang_melampaui(): void
    {
        $ao = $this->buatUser('ao');

        // Override: limit per transaksi 5jt (tanpa approval) + batas harian 10jt.
        LimitTransaksiRole::create([
            'role' => 'ao',
            'jenis_transaksi' => 'tarik_tunai',
            'limit_per_transaksi' => 5_000_000,
            'limit_harian' => 10_000_000,
            'butuh_approval' => false,
            'approval_role' => null,
        ]);
        HierarkiRole::flush();

        // 6jt > limit per transaksi 5jt, tetapi nominal 6jt harus dipaksa lewat:
        // batas harian dievaluasi lebih dulu atas AKUMULASI, sehingga 6jt+6jt > 10jt.
        // Transaksi pertama (6jt) → akumulasi 6jt (masih di bawah limit harian,
        // tetapi di atas limit per transaksi tanpa jalur otorisasi → REJECTED).
        $pertama = $this->service->processTransactionWithLimitCheck(
            $ao, 'tarik_tunai', 6_000_000, 'tunai', $this->dataTransaksi($ao, 6_000_000, 'Penarikan Tunai')
        );
        $this->assertSame('REJECTED', $pertama['status']);
        $this->assertStringContainsString('tidak tersedia jalur otorisasi', (string) $pertama['message']);

        // Perkuat: dengan limit per transaksi = 7jt dan limit harian = 10jt,
        // transaksi 6jt lolos pertama, lalu 6jt kedua melampaui batas harian.
        LimitTransaksiRole::query()->delete();
        LimitTransaksiRole::create([
            'role' => 'ao',
            'jenis_transaksi' => 'tarik_tunai',
            'limit_per_transaksi' => 7_000_000,
            'limit_harian' => 10_000_000,
            'butuh_approval' => false,
            'approval_role' => null,
        ]);
        HierarkiRole::flush();

        $satu = $this->service->processTransactionWithLimitCheck(
            $ao, 'tarik_tunai', 6_000_000, 'tunai', $this->dataTransaksi($ao, 6_000_000, 'Penarikan Tunai')
        );
        $this->assertSame('success', $satu['status']);

        $dua = $this->service->processTransactionWithLimitCheck(
            $ao, 'tarik_tunai', 6_000_000, 'tunai', $this->dataTransaksi($ao, 6_000_000, 'Penarikan Tunai')
        );
        $this->assertSame('REJECTED', $dua['status']);
        $this->assertStringContainsString('batas harian', (string) $dua['message']);
    }

    public function test_frekuensi_aksi_per_hari_menolak_transaksi_berikutnya(): void
    {
        $teller = $this->buatUser('teller');

        // Override: tanpa batas nominal, murni uji frekuensi aksi.
        LimitTransaksiRole::create([
            'role' => 'teller',
            'jenis_transaksi' => 'tarik_tunai',
            'limit_per_transaksi' => null,
            'limit_harian' => null,
            'butuh_approval' => false,
            'approval_role' => null,
        ]);
        HierarkiRole::flush();

        config(['role_hierarki.frekuensi.teller.maks_transaksi_per_hari' => 2]);

        for ($i = 1; $i <= 2; $i++) {
            $ok = $this->service->processTransactionWithLimitCheck(
                $teller, 'tarik_tunai', 100_000, 'tunai', $this->dataTransaksi($teller, 100_000, 'Penarikan Tunai')
            );
            $this->assertSame('success', $ok['status'], "Transaksi ke-{$i} harus lolos.");
        }

        $ketiga = $this->service->processTransactionWithLimitCheck(
            $teller, 'tarik_tunai', 100_000, 'tunai', $this->dataTransaksi($teller, 100_000, 'Penarikan Tunai')
        );

        $this->assertSame('REJECTED', $ketiga['status']);
        $this->assertStringContainsString('frekuensi', (string) $ketiga['message']);
    }

    public function test_override_db_menang_atas_default_config(): void
    {
        // Peran `ao` dipilih karena batas tunai teller dibaca dari kolom kebijakan
        // koperasi (bukan config), sehingga bukan sampel yang tepat untuk menguji
        // presedensi config vs override DB.
        $bawaan = (float) HierarkiRole::batas('ao', 'tarik_tunai')['limit_per_transaksi'];
        $this->assertNotSame(1_000_000.0, $bawaan, 'Prasyarat: nilai default config harus berbeda dari override.');

        // Override DB menetapkan limit 1 juta, lebih ketat dari default config.
        LimitTransaksiRole::create([
            'role' => 'ao',
            'jenis_transaksi' => 'tarik_tunai',
            'limit_per_transaksi' => 1_000_000,
            'butuh_approval' => true,
            'approval_role' => 'manajer',
        ]);
        HierarkiRole::flush();

        $this->assertSame(1_000_000.0, (float) HierarkiRole::batas('ao', 'tarik_tunai')['limit_per_transaksi']);

        $ao = $this->buatUser('ao');
        $hasil = $this->service->processTransactionWithLimitCheck(
            $ao, 'tarik_tunai', 2_000_000, 'tunai', $this->dataTransaksi($ao, 2_000_000, 'Penarikan Tunai')
        );

        $this->assertSame('PENDING_APPROVAL', $hasil['status']);
        $this->assertSame('PENDING_MANAJER', $hasil['data']['approval']->status);
    }

    /* ═════════════════════════════════════════════════════════════════════
     | 4. RANTAI APPROVAL & KONFLIK LINTAS-PERAN
     |═════════════════════════════════════════════════════════════════════ */

    public function test_ao_berwenang_memutus_pengajuan_tingkat_ao(): void
    {
        $teller = $this->buatUser('teller');
        $ao = $this->buatUser('ao');

        $approval = $this->ajukanDanAmbilApproval($teller, 'tarik_tunai', 9_000_000, 'tunai');
        $this->assertSame('PENDING_AO', $approval->status);

        $putusan = $this->service->approveTransaction($approval->fresh(), $ao, 'Disetujui AO.');

        $this->assertSame('success', $putusan['status'] ?? null, 'AO harus boleh memutus tahap PENDING_AO.');

        $approval->refresh();
        $this->assertSame('APPROVED', $approval->status);
        $this->assertSame($ao->id, $approval->disetujui_oleh);

        $this->assertDatabaseHas('approval_transaksi_logs', [
            'approval_id' => $approval->id,
            'peristiwa' => ApprovalTransaksiLog::PERISTIWA_DISETUJUI,
            'role' => 'ao',
        ]);
    }

    public function test_peran_tak_berwenang_gagal_memutus_dan_terekam(): void
    {
        $teller = $this->buatUser('teller');
        $teller2 = $this->buatUser('teller'); // sesama teller: bukan pemutus tahap AO
        $manajer = $this->buatUser('manajer'); // lebih tinggi, tetapi bukan tahap ini

        $approval = $this->ajukanDanAmbilApproval($teller, 'tarik_tunai', 9_000_000, 'tunai');
        $this->assertSame('PENDING_AO', $approval->status);

        $gagal = $this->service->approveTransaction($approval->fresh(), $teller2, 'Mencoba melangkahi.');
        $this->assertSame('error', $gagal['status'] ?? null);
        $this->assertSame('PENDING_AO', $approval->fresh()->status, 'Status tidak boleh berubah.');

        $this->assertDatabaseHas('approval_transaksi_logs', [
            'approval_id' => $approval->id,
            'peristiwa' => ApprovalTransaksiLog::PERISTIWA_GAGAL_DIPUTUS,
            'role' => 'teller',
            'role_berwenang' => 'ao',
        ]);

        // Manajer tidak boleh melompati AO (tahapnya belum tiba).
        $lompat = $this->service->approveTransaction($approval->fresh(), $manajer, 'Melompati AO.');
        $this->assertSame('error', $lompat['status'] ?? null);
        $this->assertSame('PENDING_AO', $approval->fresh()->status);
    }

    public function test_pengurus_berwenang_memutus_tahap_pengurus(): void
    {
        $manajer = $this->buatUser('manajer');
        $pengurus = $this->buatUser('pengurus');

        // Manajer non-tunai 60jt (> 50jt) → PENDING_PENGURUS.
        $approval = $this->ajukanDanAmbilApproval($manajer, 'non_tunai', 60_000_000, 'non_tunai');
        $this->assertSame('PENDING_PENGURUS', $approval->status);
        $this->assertSame($manajer->id, $approval->diajukan_oleh);

        $putusan = $this->service->approveTransaction($approval->fresh(), $pengurus, 'Disetujui Pengurus.');
        $this->assertSame('success', $putusan['status'] ?? null);
        $this->assertSame('APPROVED', $approval->fresh()->status);
    }

    public function test_self_approval_ditolak_dan_terekam(): void
    {
        // Kontrak PemisahanTugas: pengaju = pemutus ⇒ pelanggaran.
        $this->assertFalse(PemisahanTugas::dilanggar(1, 2));
        $this->assertTrue(PemisahanTugas::dilanggar(7, 7));
        $this->assertTrue(PemisahanTugas::dilanggar((string) 7, 7));

        // Uji jalur nyata: pengajuan tingkat Pengurus (non-tunai 60jt dari manajer
        // → PENDING_PENGURUS) kemudian pengaju diubah menjadi Pengurus itu sendiri,
        // sehingga pemutus berwenang = pengaju ⇒ guard maker = checker wajib aktif.
        $manajer = $this->buatUser('manajer');
        $pengurus = $this->buatUser('pengurus');
        $approval = $this->ajukanDanAmbilApproval($manajer, 'non_tunai', 60_000_000, 'non_tunai');

        $this->assertSame('PENDING_PENGURUS', $approval->status);

        ApprovalTransaksi::whereKey($approval->id)->update(['diajukan_oleh' => $pengurus->id]);
        $approval->refresh();

        $putusan = $this->service->approveTransaction($approval->fresh(), $pengurus, 'Self approval.');
        $this->assertSame('error', $putusan['status'] ?? null);
        $this->assertSame(PemisahanTugas::PESAN, $putusan['message']);
        $this->assertSame('PENDING_PENGURUS', $approval->fresh()->status);

        $this->assertDatabaseHas('approval_transaksi_logs', [
            'approval_id' => $approval->id,
            'peristiwa' => ApprovalTransaksiLog::PERISTIWA_GAGAL_DIPUTUS,
            'role' => 'pengurus',
        ]);
    }

    public function test_penolakan_terekam_pada_jejak_audit(): void
    {
        $teller = $this->buatUser('teller');
        $ao = $this->buatUser('ao');

        $approval = $this->ajukanDanAmbilApproval($teller, 'tarik_tunai', 9_000_000, 'tunai');

        $putusan = $this->service->rejectTransaction($approval->fresh(), $ao, 'Dokumen tidak lengkap.');
        $this->assertSame('success', $putusan['status'] ?? null);
        $this->assertSame('REJECTED', $approval->fresh()->status);

        $this->assertDatabaseHas('approval_transaksi_logs', [
            'approval_id' => $approval->id,
            'peristiwa' => ApprovalTransaksiLog::PERISTIWA_DITOLAK,
            'role' => 'ao',
        ]);
    }

    /* ═════════════════════════════════════════════════════════════════════
     | 5. INTEGRITAS JEJAK AUDIT (RANTAI HASH)
     |═════════════════════════════════════════════════════════════════════ */

    public function test_jejak_merekam_peristiwa_diajukan_dengan_tahap_berwenang(): void
    {
        $teller = $this->buatUser('teller');
        $approval = $this->ajukanDanAmbilApproval($teller, 'tarik_tunai', 9_000_000, 'tunai');

        $this->assertDatabaseHas('approval_transaksi_logs', [
            'approval_id' => $approval->id,
            'peristiwa' => ApprovalTransaksiLog::PERISTIWA_DIAJUKAN,
            'status_sesudah' => 'PENDING_AO',
            'role_berwenang' => 'ao',
        ]);
    }

    public function test_jejak_audit_approval_utuh_dan_tak_dapat_disunting(): void
    {
        $teller = $this->buatUser('teller');
        $ao = $this->buatUser('ao');

        $approval = $this->ajukanDanAmbilApproval($teller, 'tarik_tunai', 9_000_000, 'tunai');
        $this->service->approveTransaction($approval->fresh(), $ao, 'OK');

        $this->assertGreaterThanOrEqual(2, ApprovalTransaksiLog::count());

        $verifikasi = ApprovalTransaksiLog::verifyChain();
        $this->assertTrue($verifikasi['utuh'], 'Rantai hash jejak audit harus utuh: '.($verifikasi['alasan'] ?? ''));

        $baris = ApprovalTransaksiLog::first();
        $this->expectException(\RuntimeException::class);
        $baris->catatan = 'diubah diam-diam';
        $baris->save();
    }

    /* ═════════════════════════════════════════════════════════════════════
     | 5b. VOLUME AKSES DATA (UU PDP No. 27/2022 — minimalisasi data)
     |═════════════════════════════════════════════════════════════════════ */

    public function test_akses_data_tanpa_batas_mengembalikan_seluruh_baris(): void
    {
        // admin default: maks_baris_ekspor = null => tanpa batas.
        $this->assertNull(HierarkiRole::aksesData('admin'));

        $hasil = HierarkiRole::ambilDenganBatas(User::query(), 'admin');

        $this->assertFalse($hasil['melampaui']);
        $this->assertNull($hasil['batas']);
        $this->assertSame(User::count(), $hasil['data']->count());
    }

    public function test_akses_data_terbatas_menandai_permintaan_yang_melampaui(): void
    {
        // Empat pengguna dijamin ada.
        $this->buatUser('teller');
        $this->buatUser('ao');
        $this->buatUser('manajer');
        $this->buatUser('pengurus');

        config(['role_hierarki.akses_data.teller' => ['maks_baris_ekspor' => 2]]);

        $this->assertSame(2, HierarkiRole::aksesData('teller'));

        $hasil = HierarkiRole::ambilDenganBatas(User::query(), 'teller');

        $this->assertTrue($hasil['melampaui'], 'Permintaan di atas batas wajib ditandai melampaui.');
        $this->assertSame(2, $hasil['batas']);
        $this->assertCount(2, $hasil['data'], 'Data disajikan sebatas batas, bukan terpotong diam-diam.');
    }

    public function test_akses_data_tepat_pada_batas_tidak_dianggap_melampaui(): void
    {
        $this->buatUser('ao');
        $this->buatUser('manajer');

        config(['role_hierarki.akses_data.ao' => ['maks_baris_ekspor' => User::count()]]);

        $hasil = HierarkiRole::ambilDenganBatas(User::query(), 'ao');

        $this->assertFalse($hasil['melampaui'], 'Tepat pada batas masih diperbolehkan.');
        $this->assertSame(User::count(), $hasil['data']->count());
    }

    /* ═════════════════════════════════════════════════════════════════════
     | BANTUAN
     |═════════════════════════════════════════════════════════════════════ */

    private function buatUser(string $role): User
    {
        return User::create([
            'name' => ucfirst($role).' '.$this->prefix,
            'email' => $role.'.'.strtolower($this->prefix).'.'.uniqid().'@koperasi.test',
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    /** Payload minimum untuk tabel `transaksi` (kolom NOT NULL tanpa default wajib diisi). */
    private function dataTransaksi(User $user, float $nominal = 0.0, string $jenis = 'Setoran Tunai'): array
    {
        return [
            'no_transaksi' => $this->prefix.'-'.strtoupper(substr(uniqid(), -6)),
            'jenis_transaksi' => $jenis,
            'user_id' => $user->id,
            'jumlah' => $nominal,
            'posisi' => 'Debet',
            'status_transaksi' => 'Berhasil',
            'waktu_transaksi' => now(),
        ];
    }

    /** Jenis buku besar yang cocok untuk tiap jenis batas transaksi. */
    private static function jenisBuku(string $jenis): string
    {
        return $jenis === 'tarik_tunai' ? 'Penarikan Tunai' : 'Setoran Tunai';
    }

    /** Ajukan transaksi berlimit dan kembalikan baris approval yang menunggu. */
    private function ajukanDanAmbilApproval(User $user, string $jenis, float $nominal, string $pembayaran): ApprovalTransaksi
    {
        $hasil = $this->service->processTransactionWithLimitCheck(
            $user,
            $jenis,
            $nominal,
            $pembayaran,
            $this->dataTransaksi($user, $nominal, self::jenisBuku($jenis)),
            'Pengajuan uji RBAC.'
        );

        $this->assertSame('PENDING_APPROVAL', $hasil['status'], 'Pengajuan harus masuk rantai approval.');

        return $hasil['data']['approval'];
    }
}
