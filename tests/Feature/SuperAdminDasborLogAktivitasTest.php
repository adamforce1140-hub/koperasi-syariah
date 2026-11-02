<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\HierarkiRole;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * LANGKAH #4 & #5 — dasbor Super Admin terbuka penuh & aktivitasnya tercatat.
 *
 *   #4: seluruh halaman dasbor (render penuh DAN potongan denyut) dapat
 *       dibuka tanpa 500. Sebelumnya `ringkasanSistem()` membaca metadata
 *       peran dari `HierarkiRole::peran()` (daftar nama) seolah-olah peta
 *       slug => meta, memicu "Illegal string offset 'label'".
 *   #5: setiap permintaan Super Admin meninggalkan jejak pada log aktivitas
 *       (audit_logs) yang dapat dibaca auditor.
 */
class SuperAdminDasborLogAktivitasTest extends TestCase
{
    use DatabaseTransactions;

    private function superAdmin(): User
    {
        return User::firstOrCreate(
            ['email' => 'uji.dasbor.superadmin@koperasi.test'],
            ['name' => 'Uji Super Admin', 'password' => Hash::make('secret'), 'role' => 'super_admin']
        );
    }

    public function test_ringkasan_sistem_memuat_seluruh_peran_beserta_label_dan_level(): void
    {
        $this->actingAs($this->superAdmin());

        $respon = $this->get(route('dashboard.super-admin'));
        $respon->assertOk();

        $ringkasan = $respon->viewData('sistem');
        $this->assertIsArray($ringkasan);
        $this->assertArrayHasKey('peran', $ringkasan);

        foreach (HierarkiRole::peran() as $peran) {
            $this->assertArrayHasKey($peran, $ringkasan['peran']);
            $this->assertSame(HierarkiRole::label($peran), $ringkasan['peran'][$peran]['label']);
            $this->assertSame(HierarkiRole::level($peran), $ringkasan['peran'][$peran]['level']);
        }

        $this->assertSame('Super Admin', $ringkasan['peran']['super_admin']['label']);
        $this->assertSame(100, $ringkasan['peran']['super_admin']['level']);
    }

    public function test_potongan_denyut_dasbor_super_admin_tidak_galat(): void
    {
        $this->actingAs($this->superAdmin());

        $this->get(route('dashboard.super-admin'))->assertOk();
        $this->get(route('dashboard.super-admin.ringkas'))->assertOk();
    }

    public function test_halaman_kinerja_seluruh_unit_terbuka(): void
    {
        $this->actingAs($this->superAdmin());

        $this->get(route('super-admin.kinerja-unit'))->assertOk();
    }

    public function test_aktivitas_super_admin_tercatat_di_log_audit(): void
    {
        $superAdmin = $this->superAdmin();
        $this->actingAs($superAdmin);

        // Tindakan nyata yang menulis jejak audit: ubah peran pengguna lain.
        $teller = User::firstOrCreate(
            ['email' => 'uji.dasbor.teller@koperasi.test'],
            ['name' => 'Uji Teller', 'password' => Hash::make('secret'), 'role' => 'teller']
        );

        $this->put(route('pengurus.users.peran', $teller->id), ['role' => 'ao'])
            ->assertRedirect(route('pengurus.users.index'));

        $catatan = AuditLog::query()
            ->where('jenis_transaksi', 'role.ubah')
            ->where('user_id', $superAdmin->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($catatan, 'Tindakan Super Admin tidak tercatat di audit log.');
        $this->assertStringContainsString('uji.dasbor.teller@koperasi.test', (string) $catatan->keterangan);
        $this->assertNotEmpty($catatan->ip_address, 'Jejak audit wajib menyimpan alamat IP.');
        $this->assertNotEmpty($catatan->hash, 'Jejak audit wajib tersegel rantai hash.');
    }
}
