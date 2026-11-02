<?php

namespace Tests\Feature;

use App\Models\LaporanKeuanganLog;
use App\Models\User;
use App\Support\HierarkiRole;
use App\Support\LaporanKeuanganPermission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Batas volume ekspor log audit wajib mengikuti SSOT peran
 * (config/role_hierarki.php → akses_data.maks_baris_ekspor), bukan angka
 * tetap di dalam controller.
 *
 * Sebelum perbaikan, AuditLaporanKeuanganController::ekspor() memakai
 * `limit(20000)` untuk semua peran. DPS — satu-satunya pemegang izin modul
 * audit — berhak 50.000 baris, sehingga 30.000 baris terakhir hilang tanpa
 * pemberitahuan: berkas terlihat lengkap padahal terpotong.
 */
class BatasEksporAuditSesuaiPeranTest extends TestCase
{
    use DatabaseTransactions;

    private function dps(): User
    {
        return User::firstOrCreate(
            ['email' => 'batas.ekspor.dps@koperasi.test'],
            [
                'name' => 'Uji DPS',
                'password' => Hash::make('secret'),
                'role' => 'dps',
                'is_active' => true,
            ]
        );
    }

    /** Buat sejumlah baris log audit agar volume ekspor dapat dikendalikan. */
    private function buatLog(int $jumlah): void
    {
        for ($i = 0; $i < $jumlah; $i++) {
            LaporanKeuanganLog::catat([
                'user_nama' => 'Uji Batas Ekspor',
                'role' => 'dps',
                'modul' => LaporanKeuanganPermission::MODUL_AUDIT,
                'aktivitas' => LaporanKeuanganPermission::LIHAT,
                'deskripsi' => 'Baris uji batas ekspor #'.$i,
                'metode' => 'GET',
                'status_http' => 200,
                'berhasil' => true,
            ]);
        }
    }

    public function test_ekspor_ditolak_terang_terangan_bila_melebihi_batas_peran(): void
    {
        $awal = LaporanKeuanganLog::count();

        // Batas dibuat lebih kecil dari volume data, meniru kondisi nyata
        // ketika permintaan melampaui hak akses peran.
        config(['role_hierarki.akses_data.dps' => ['maks_baris_ekspor' => $awal + 2]]);
        $this->buatLog(3);

        $respons = $this->actingAs($this->dps())->get(route('audit.laporan.ekspor'));

        $respons->assertRedirect();
        $respons->assertSessionHas('error', function ($pesan) use ($awal) {
            return str_contains($pesan, number_format($awal + 2, 0, ',', '.'));
        });
    }

    public function test_ekspor_diizinkan_bila_volume_tepat_di_batas_peran(): void
    {
        $awal = LaporanKeuanganLog::count();

        config(['role_hierarki.akses_data.dps' => ['maks_baris_ekspor' => $awal + 3]]);
        $this->buatLog(3);

        $respons = $this->actingAs($this->dps())->get(route('audit.laporan.ekspor'));

        $respons->assertOk();
        $this->assertStringContainsString('text/csv', (string) $respons->headers->get('Content-Type'));
    }

    public function test_batas_null_berarti_tanpa_batas(): void
    {
        config(['role_hierarki.akses_data.dps' => ['maks_baris_ekspor' => null]]);

        $this->buatLog(3);

        $this->actingAs($this->dps())
            ->get(route('audit.laporan.ekspor'))
            ->assertOk();
    }

    public function test_batas_dibaca_dari_ssot_bukan_angka_tetap(): void
    {
        // Mengunci sumber nilai: yang dibaca controller adalah konfigurasi
        // peran, sehingga mengubah SSOT langsung mengubah perilaku ekspor.
        config(['role_hierarki.akses_data.dps' => ['maks_baris_ekspor' => 12345]]);

        $this->assertSame(12345, HierarkiRole::aksesData('dps'));
    }
}
