<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\AuditLog;
use App\Models\Coa;
use App\Models\ExitApprovalLog;
use App\Models\KebijakanKoperasi;
use App\Models\MandatorySavingsBill;
use App\Models\MemberTermination;
use App\Models\User;
use App\Notifications\PengunduranDiriApprovalNotification;
use App\Services\AutoJournalingService;
use App\Services\ExitKeanggotaanService;
use App\Services\MandatorySavingsService;
use App\Support\Coa as KodeCoa;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * APPROVAL WORKFLOW PENCAIRAN BERNILAI TINGGI (pengunduran diri anggota).
 *
 * Mengunci janji:
 *  1. `total_net > high_value_withdrawal_limit` → status MENUNGGU_APPROVAL
 *     dan notifikasi in-app + email dikirim ke SELURUH Manajer.
 *  2. Selama `approved_at` kosong, eksekusi pencairan DIBLOKIR; saldo,
 *     jurnal, dan status anggota tidak berubah.
 *  3. `setujui()` mengisi `approved_at` + `disetujui_oleh` dan mencatat
 *     `exit_approval_logs` (APPROVED) + audit EXIT_APPROVAL_DISETUJUI.
 *  4. `tolakApproval()` → status DITOLAK, log REJECTED, audit
 *     EXIT_APPROVAL_DITOLAK, dan pencairan tidak mungkin dieksekusi.
 *  5. Limit 0 (default) → workflow NONAKTIF (status langsung DIAJUKAN).
 *  6. RBAC: `teller` dilarang menyetujui/menolak (hanya manajer/pengurus).
 */
class ExitApprovalWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'APR'.strtoupper(substr(uniqid(), -6));
        Setting::flush();
    }

    protected function tearDown(): void
    {
        Setting::flush();
        parent::tearDown();
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    private function user(string $role): User
    {
        return User::firstOrCreate(
            ['email' => "{$role}.{$this->prefix}@test.local"],
            [
                'name' => ucfirst($role).' '.$this->prefix,
                'password' => Hash::make('password'),
                'role' => $role,
            ]
        );
    }

    private function pastikanCoa(): void
    {
        foreach ([KodeCoa::SIMPANAN_POKOK, KodeCoa::SIMPANAN_WAJIB_MODAL, KodeCoa::KAS_TELLER] as $kode) {
            if (! Coa::findByKode($kode)) {
                $this->markTestSkipped("CoA {$kode} belum tersedia/aktif — jalankan CoaSeeder.");
            }
        }
    }

    /** Anggota dengan saldo Pokok + Wajib yang cukup untuk memicu approval. */
    private function anggotaDenganSaldo(float $pokok = 100000, float $wajib = 50000): Anggota
    {
        $ops = $this->user('teller');

        $anggota = Anggota::create([
            'no_anggota' => 'AGT-'.$this->prefix,
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Approval '.$this->prefix,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Alamat Uji Approval Workflow',
            'kota' => 'Jakarta',
            'provinsi' => 'DKI Jakarta',
            'status_anggota' => Anggota::STATUS_ACTIVE,
            'is_auto_debit' => false,
            'tanggal_gabung' => '2026-01-01',
        ]);

        $jurnal = app(AutoJournalingService::class)->jurnalSetorSimpananPokok(
            $pokok,
            'Setoran Simpanan Pokok uji approval '.$this->prefix,
            $ops->id,
            null,
            null,
            $anggota->id
        );
        $this->assertNotNull($jurnal, 'Jurnal setoran Simpanan Pokok gagal diposting.');

        $tagihan = MandatorySavingsBill::create([
            'member_id' => $anggota->id,
            'bill_period' => '2026-01',
            'amount' => $wajib,
            'status' => MandatorySavingsBill::STATUS_UNPAID,
        ]);
        app(MandatorySavingsService::class)->bayarViaTeller(
            $tagihan,
            MandatorySavingsBill::METODE_CASH,
            null,
            $ops->id
        );

        return $anggota->refresh();
    }

    /** Wajib `forceFill` — kolom di luar `$fillable` diam-diam diabaikan. */
    private function aturKebijakan(array $kolom): void
    {
        $kebijakan = KebijakanKoperasi::first() ?? KebijakanKoperasi::create([]);
        $kebijakan->forceFill($kolom)->save();
        Setting::flush();
    }

    private function service(): ExitKeanggotaanService
    {
        return app(ExitKeanggotaanService::class);
    }

    /* =====================================================================
     | 1. PEMICUAN WORKFLOW
     |===================================================================== */

    public function test_total_net_di_atas_limit_memicu_status_menunggu_approval(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['high_value_withdrawal_limit' => 100000, 'closure_admin_fee' => 0]);

        $anggota = $this->anggotaDenganSaldo(100000, 50000); // bruto 150.000 > limit 100.000
        $terminasi = $this->service()->ajukan($anggota, ['exit_reason' => 'Pindah domisili'], $this->user('teller')->id);

        $this->assertSame(MemberTermination::STATUS_MENUNGGU_APPROVAL, $terminasi->status);
        $this->assertTrue((bool) $terminasi->butuh_approval, '`butuh_approval` wajib bernilai true.');
        $this->assertNull($terminasi->approved_at, 'Belum ada persetujuan saat pengajuan.');
        $this->assertSame(150000.0, (float) $terminasi->total_net);
        $this->assertMatchesRegularExpression(
            '/^EXIT\/\d{6}\/\d{6}$/',
            (string) $terminasi->no_referensi,
            'Nomor referensi wajib berformat EXIT/YYYYMM/NNNNNN.'
        );
    }

    public function test_total_net_di_bawah_limit_tidak_memicu_approval(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['high_value_withdrawal_limit' => 1000000, 'closure_admin_fee' => 0]);

        $anggota = $this->anggotaDenganSaldo(100000, 50000); // 150.000 < 1.000.000
        $terminasi = $this->service()->ajukan($anggota, [], $this->user('teller')->id);

        $this->assertSame(MemberTermination::STATUS_DIAJUKAN, $terminasi->status);
        $this->assertFalse((bool) $terminasi->butuh_approval);
    }

    public function test_limit_nol_menonaktifkan_workflow_approval(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['high_value_withdrawal_limit' => 0, 'closure_admin_fee' => 0]);

        $anggota = $this->anggotaDenganSaldo();
        $terminasi = $this->service()->ajukan($anggota, [], $this->user('teller')->id);

        $this->assertSame(MemberTermination::STATUS_DIAJUKAN, $terminasi->status);
        $this->assertFalse((bool) $terminasi->butuh_approval, 'Limit 0 = approval workflow NONAKTIF.');
    }

    /* =====================================================================
     | 2. notifikasi ke SELURUH MANAJER
     |===================================================================== */

    public function test_notifikasi_in_app_dan_email_dikirim_ke_semua_manajer(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['high_value_withdrawal_limit' => 100000, 'closure_admin_fee' => 0]);

        Notification::fake();

        $manajerA = $this->user('manajer');
        $manajerB = User::firstOrCreate(
            ['email' => 'manajer2.'.$this->prefix.'@test.local'],
            ['name' => 'Manajer Dua '.$this->prefix, 'password' => Hash::make('password'), 'role' => 'manajer']
        );

        $anggota = $this->anggotaDenganSaldo();
        $terminasi = $this->service()->ajukan($anggota, [], $this->user('teller')->id);

        Notification::assertSentTo(
            [$manajerA, $manajerB],
            PengunduranDiriApprovalNotification::class,
            function (PengunduranDiriApprovalNotification $notif, array $kanal) use ($terminasi) {
                return in_array('database', $kanal, true)
                    && in_array('mail', $kanal, true)
                    && (int) $notif->ringkasan['terminasi_id'] === (int) $terminasi->id;
            }
        );

        Notification::assertSentToTimes($manajerA, PengunduranDiriApprovalNotification::class, 1);
        Notification::assertSentToTimes($manajerB, PengunduranDiriApprovalNotification::class, 1);
    }

    /* =====================================================================
     | 3. BLOKIR PENCAIRAN SEBELUM PERSETUJUAN
     |===================================================================== */

    public function test_pencairan_diblokir_selama_belum_disetujui_manajer(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['high_value_withdrawal_limit' => 100000, 'closure_admin_fee' => 0]);

        $anggota = $this->anggotaDenganSaldo();
        $terminasi = $this->service()->ajukan($anggota, [], $this->user('teller')->id);

        try {
            $this->service()->eksekusi($terminasi, MemberTermination::METODE_CASH, $this->user('teller')->id);
            $this->fail('Pencairan wajib DIBLOKIR sebelum persetujuan Manajer.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('MENUNGGU PERSETUJUAN MANAJER', $e->getMessage());
        }

        $segar = $terminasi->refresh();
        $this->assertNull($segar->jurnal_id, 'Jurnal tidak boleh diposting saat pencairan diblokir.');
        $this->assertNull($segar->transaksi_id, 'Transaksi kas tidak boleh dibuat saat diblokir.');
        $this->assertSame(MemberTermination::STATUS_MENUNGGU_APPROVAL, $segar->status);
        $this->assertSame(Anggota::STATUS_ACTIVE, $anggota->refresh()->status_anggota);
    }

    /* =====================================================================
     | 4. PERSETUJUAN MANAJER
     |===================================================================== */

    public function test_manajer_menyetujui_mengisi_approved_at_dan_mencatat_log_audit(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['high_value_withdrawal_limit' => 100000, 'closure_admin_fee' => 0]);

        $anggota = $this->anggotaDenganSaldo();
        $manajer = $this->user('manajer');
        $terminasi = $this->service()->ajukan($anggota, [], $this->user('teller')->id);

        $auditSebelum = AuditLog::where('jenis_transaksi', 'EXIT_APPROVAL_DISETUJUI')->count();

        $hasil = $this->service()->setujui($terminasi, 'Disetujui, dana aman.', $manajer->id);

        $this->assertNotNull($hasil->approved_at, '`approved_at` wajib terisi setelah persetujuan.');
        $this->assertSame($manajer->id, (int) $hasil->disetujui_oleh);
        // Status TETAP MENUNGGU_APPROVAL — gerbang eksekusi memeriksa `approved_at`.
        $this->assertSame(MemberTermination::STATUS_MENUNGGU_APPROVAL, $hasil->status);
        $this->assertSame('Disetujui Manajer — Siap Dicairkan', $hasil->status_label);

        $this->assertDatabaseHas('exit_approval_logs', [
            'member_termination_id' => $terminasi->id,
            'user_id' => $manajer->id,
            'aksi' => ExitApprovalLog::AKSI_APPROVED,
            'catatan' => 'Disetujui, dana aman.',
        ]);
        $this->assertSame(
            $auditSebelum + 1,
            AuditLog::where('jenis_transaksi', 'EXIT_APPROVAL_DISETUJUI')->count(),
            'Persetujuan Manajer WAJIB tercatat di audit log.'
        );
    }

    public function test_setelah_disetujui_pencairan_dapat_dieksekusi(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['high_value_withdrawal_limit' => 100000, 'closure_admin_fee' => 0]);

        $anggota = $this->anggotaDenganSaldo();
        $manajer = $this->user('manajer');
        $teller = $this->user('teller');

        $terminasi = $this->service()->ajukan($anggota, [], $teller->id);
        $this->service()->setujui($terminasi, null, $manajer->id);

        $hasil = $this->service()->eksekusi($terminasi->refresh(), MemberTermination::METODE_CASH, $teller->id);

        $this->assertSame(MemberTermination::STATUS_DISETUJUI, $hasil->status);
        $this->assertNotNull($hasil->jurnal_id, 'Jurnal wajib diposting setelah persetujuan.');
        $this->assertSame(150000.0, (float) $hasil->total_dikembalikan);
        $this->assertSame(Anggota::STATUS_TERMINATED, $anggota->refresh()->status_anggota);

        // Satu log dari setujui() + satu log dari eksekusi() = 2 baris APPROVED.
        $this->assertSame(
            2,
            ExitApprovalLog::where('member_termination_id', $terminasi->id)
                ->where('aksi', ExitApprovalLog::AKSI_APPROVED)
                ->count(),
            'Persetujuan + eksekusi wajib meninggalkan dua jejak APPROVED.'
        );
    }

    public function test_persetujuan_ganda_ditolak(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['high_value_withdrawal_limit' => 100000, 'closure_admin_fee' => 0]);

        $anggota = $this->anggotaDenganSaldo();
        $manajer = $this->user('manajer');
        $terminasi = $this->service()->ajukan($anggota, [], $this->user('teller')->id);

        $this->service()->setujui($terminasi, null, $manajer->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/sudah disetujui pada/');
        $this->service()->setujui($terminasi->refresh(), null, $manajer->id);
    }

    public function test_setujui_ditolak_bila_dokumen_belum_menunggu_approval(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['high_value_withdrawal_limit' => 1000000, 'closure_admin_fee' => 0]);

        $anggota = $this->anggotaDenganSaldo();
        $terminasi = $this->service()->ajukan($anggota, [], $this->user('teller')->id);

        $this->assertSame(MemberTermination::STATUS_DIAJUKAN, $terminasi->status);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/tidak sedang menunggu persetujuan/');
        $this->service()->setujui($terminasi, null, $this->user('manajer')->id);
    }

    /* =====================================================================
     | 5. PENOLAKAN MANAJER
     |===================================================================== */

    public function test_manajer_menolak_mengubah_status_dan_mencatat_log(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['high_value_withdrawal_limit' => 100000, 'closure_admin_fee' => 0]);

        $anggota = $this->anggotaDenganSaldo();
        $manajer = $this->user('manajer');
        $terminasi = $this->service()->ajukan($anggota, [], $this->user('teller')->id);

        $auditSebelum = AuditLog::where('jenis_transaksi', 'EXIT_APPROVAL_DITOLAK')->count();

        $hasil = $this->service()->tolakApproval($terminasi, 'Dana dibutuhkan untuk operasional.', $manajer->id);

        $this->assertSame(MemberTermination::STATUS_DITOLAK, $hasil->status);
        $this->assertSame('Dana dibutuhkan untuk operasional.', $hasil->alasan_penolakan);

        $this->assertDatabaseHas('exit_approval_logs', [
            'member_termination_id' => $terminasi->id,
            'user_id' => $manajer->id,
            'aksi' => ExitApprovalLog::AKSI_REJECTED,
        ]);
        $this->assertSame(
            $auditSebelum + 1,
            AuditLog::where('jenis_transaksi', 'EXIT_APPROVAL_DITOLAK')->count(),
            'Penolakan Manajer WAJIB tercatat di audit log.'
        );
    }

    public function test_pencairan_tidak_dapat_dieksekusi_setelah_ditolak(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['high_value_withdrawal_limit' => 100000, 'closure_admin_fee' => 0]);

        $anggota = $this->anggotaDenganSaldo();
        $manuel = $this->user('manajer');
        $terminasi = $this->service()->ajukan($anggota, [], $this->user('teller')->id);
        $this->service()->tolakApproval($terminasi, 'Ditolak untuk uji.', $manuel->id);

        try {
            $this->service()->eksekusi($terminasi->refresh(), MemberTermination::METODE_CASH, $this->user('teller')->id);
            $this->fail('Dokumen yang DITOLAK tidak boleh dapat dicairkan.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('tidak dapat dieksekusi', $e->getMessage());
        }

        $this->assertSame(Anggota::STATUS_ACTIVE, $anggota->refresh()->status_anggota);
        $this->assertNull($terminasi->refresh()->jurnal_id);
    }

    /* =====================================================================
     | 6. RBAC — RUTE APPROVAL
     |===================================================================== */

    public function test_teller_tidak_berwenang_menyetujui_maupun_menolak(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['high_value_withdrawal_limit' => 100000, 'closure_admin_fee' => 0]);

        $anggota = $this->anggotaDenganSaldo();
        $teller = $this->user('teller');
        $terminasi = $this->service()->ajukan($anggota, [], $teller->id);

        $this->actingAs($teller)
            ->post(route('member-terminations.setujui', $terminasi->id), ['catatan' => 'Coba-coba'])
            ->assertForbidden();

        $this->actingAs($teller)
            ->post(route('member-terminations.tolak-approval', $terminasi->id), ['alasan_penolakan' => 'Coba-coba'])
            ->assertForbidden();

        $segar = $terminasi->refresh();
        $this->assertNull($segar->approved_at, 'Teller TIDAK BOLEH menyetujui pencairan.');
        $this->assertSame(MemberTermination::STATUS_MENUNGGU_APPROVAL, $segar->status);
    }

    public function test_peran_di_luar_daftar_izin_tidak_dapat_mengakses_rute_approval(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['high_value_withdrawal_limit' => 100000, 'closure_admin_fee' => 0]);

        $anggota = $this->anggotaDenganSaldo();
        $terminasi = $this->service()->ajukan($anggota, [], $this->user('teller')->id);

        foreach (['ao', 'admin', 'super_admin', 'dps', 'anggota'] as $peran) {
            $this->actingAs($this->user($peran))
                ->post(route('member-terminations.setujui', $terminasi->id), ['catatan' => 'Uji RBAC'])
                ->assertForbidden("Peran {$peran} seharusnya 403 pada rute approval.");
        }

        $this->assertNull($terminasi->refresh()->approved_at);
    }

    public function test_manajer_dapat_menyetujui_lewat_rute_http(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['high_value_withdrawal_limit' => 100000, 'closure_admin_fee' => 0]);

        $anggota = $this->anggotaDenganSaldo();
        $manajer = $this->user('manajer');
        $terminasi = $this->service()->ajukan($anggota, [], $this->user('teller')->id);

        $this->actingAs($manajer)
            ->post(route('member-terminations.setujui', $terminasi->id), ['catatan' => 'OK via HTTP'])
            ->assertRedirect(route('member-terminations.show', $terminasi->id))
            ->assertSessionHas('success');

        $segar = $terminasi->refresh();
        $this->assertNotNull($segar->approved_at);
        $this->assertSame($manajer->id, (int) $segar->disetujui_oleh);
        $this->assertDatabaseHas('exit_approval_logs', [
            'member_termination_id' => $terminasi->id,
            'user_id' => $manajer->id,
            'aksi' => ExitApprovalLog::AKSI_APPROVED,
            'catatan' => 'OK via HTTP',
        ]);
    }
}
