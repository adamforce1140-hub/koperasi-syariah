<?php

namespace Tests\Feature;

use App\Models\KebijakanKoperasi;
use App\Models\User;
use App\Support\Setting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * RETENSI CADANGAN BASIS DATA SEBAGAI PARAMETER KEBIJAKAN (nomor 6).
 *
 * Sebelumnya angka retensi ditulis keras di routes/console.php
 * (`db:cadangkan --simpan-hari=14`). Test ini mengunci empat hal:
 *   1. Getter `Setting::backupRetensiHari()` memakai default 14 dan
 *      mengunci nilai ke rentang aman 1-365.
 *   2. Nilai dapat diubah dari form Kebijakan Koperasi oleh Pengurus.
 *   3. Pembaruan sebagian (nilai tidak dikirim) tidak mereset retensi.
 *   4. Scheduler benar-benar memakai nilai dari kebijakan, bukan angka keras.
 */
class BackupRetensiSettingTest extends TestCase
{
    use DatabaseTransactions;

    private User $pengurus;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasColumn('kebijakan_koperasis', 'backup_retensi_hari')) {
            $this->markTestSkipped('Kolom backup_retensi_hari belum tersedia.');
        }

        $this->pengurus = User::firstOrCreate(
            ['email' => 'retensi.cadangan.pengurus@test.local'],
            [
                'name' => 'Uji Retensi Cadangan',
                'password' => Hash::make('password'),
                'role' => 'pengurus',
                'is_active' => true,
            ]
        );
    }

    protected function tearDown(): void
    {
        Setting::flush();
        parent::tearDown();
    }

    /* ══════════════════════════════════════════════════════════════════
     |  1. GETTER & PENGAMAN RENTANG
     ══════════════════════════════════════════════════════════════════ */

    public function test_default_retensi_adalah_14_hari(): void
    {
        $this->aturKebijakan(['backup_retensi_hari' => 14]);

        $this->assertSame(14, Setting::backupRetensiHari());
    }

    public function test_nilai_di_luar_rentang_aman_dikunci(): void
    {
        // 0/negatif akan menghapus cadangan yang baru dibuat → dikunci ke 1.
        // (Kolom bertipe UNSIGNED, jadi hanya 0 yang dapat disimpan; nilai
        // negatif ditolak basis data dan tidak mungkin tersimpan.)
        $this->aturKebijakan(['backup_retensi_hari' => 0]);
        $this->assertSame(1, Setting::backupRetensiHari());

        // Nilai terlalu besar memenuhi disk → dikunci ke 365.
        $this->aturKebijakan(['backup_retensi_hari' => 9999]);
        $this->assertSame(365, Setting::backupRetensiHari());
    }

    /* ══════════════════════════════════════════════════════════════════
     |  2. FORM KEBIJAKAN
     ══════════════════════════════════════════════════════════════════ */

    public function test_retensi_tersimpan_dari_form_kebijakan(): void
    {
        $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), $this->muatanKebijakan([
                'backup_retensi_hari' => '30',
            ]))
            ->assertSessionHasNoErrors();

        Setting::flush();

        $this->assertSame(30, (int) KebijakanKoperasi::first()->backup_retensi_hari);
        $this->assertSame(30, Setting::backupRetensiHari());
    }

    public function test_retensi_tidak_dikirim_tidak_mengubah_nilai(): void
    {
        $this->aturKebijakan(['backup_retensi_hari' => 21]);

        // Pembaruan sebagian: kunci tidak dikirim sama sekali (P4 Default Aman).
        $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), $this->muatanKebijakan())
            ->assertSessionHasNoErrors();

        Setting::flush();

        $this->assertSame(21, (int) KebijakanKoperasi::first()->backup_retensi_hari);
        $this->assertSame(21, Setting::backupRetensiHari());
    }

    public function test_retensi_di_luar_1_sampai_365_ditolak(): void
    {
        foreach (['0', '366', 'abc'] as $tidakValid) {
            $this->actingAs($this->pengurus)
                ->put(route('pengurus.kebijakan.update'), $this->muatanKebijakan([
                    'backup_retensi_hari' => $tidakValid,
                ]))
                ->assertSessionHasErrors(['backup_retensi_hari']);
        }
    }

    /* ══════════════════════════════════════════════════════════════════
     |  3. SCHEDULER MEMBACA KEBIJAKAN
     ══════════════════════════════════════════════════════════════════ */

    public function test_scheduler_cadangan_memakai_retensi_dari_kebijakan(): void
    {
        $this->aturKebijakan(['backup_retensi_hari' => 45]);

        $perintah = collect($this->eventScheduler()->events())
            ->map(static fn (Event $event): string => (string) $event->command)
            ->first(static fn (string $command): bool => str_contains($command, 'db:cadangkan'));

        $this->assertNotNull($perintah, 'Jadwal db:cadangkan tidak terdaftar.');
        $this->assertStringContainsString('--simpan-hari=45', $perintah);
    }

    private function muatanKebijakan(array $override = []): array
    {
        $kebijakan = KebijakanKoperasi::first() ?? KebijakanKoperasi::create([]);

        return array_merge([
            'limit_manajer' => (string) $kebijakan->limit_manajer,
            'tazir_mode' => $kebijakan->tazir_mode ?: 'FLAT_HARIAN',
            'tazir_rate' => (string) ($kebijakan->tazir_rate ?? 0),
            'tazir_grace_period_days' => (string) ($kebijakan->tazir_grace_period_days ?? 0),
            'tazir_default_method' => $kebijakan->tazir_default_method ?: 'TUNAI',
            'mandatory_principal_savings' => (string) $kebijakan->mandatory_principal_savings,
            'mandatory_monthly_savings' => (string) $kebijakan->mandatory_monthly_savings,
            'minimum_pembukaan_rekening' => (string) $kebijakan->minimum_pembukaan_rekening,
            'maksimum_pembukaan_rekening' => (string) $kebijakan->maksimum_pembukaan_rekening,
            'shu_pct_cadangan_modal' => (string) Setting::get('shu_pct_cadangan_modal', 30),
            'shu_pct_jasa_anggota' => (string) Setting::get('shu_pct_jasa_anggota', 40),
            'shu_pct_jasa_modal' => (string) Setting::get('shu_pct_jasa_modal', 20),
            'shu_pct_dana_pendidikan' => (string) Setting::get('shu_pct_dana_pendidikan', 10),
        ], $override);
    }

    private function aturKebijakan(array $kolom): void
    {
        $kebijakan = KebijakanKoperasi::first() ?? KebijakanKoperasi::create([]);
        $kebijakan->forceFill($kolom)->save();
        Setting::flush();
    }

    /**
     * Baca ulang definisi jadwal dari routes/console.php pada instance
     * Schedule baru, agar memakai nilai kebijakan yang baru ditetapkan
     * (jadwal dibaca saat registrasi).
     */
    private function eventScheduler(): Schedule
    {
        $scheduler = new Schedule;

        $this->app->instance(Schedule::class, $scheduler);
        Facade::clearResolvedInstance(Schedule::class);

        require base_path('routes/console.php');

        return $scheduler;
    }
}
