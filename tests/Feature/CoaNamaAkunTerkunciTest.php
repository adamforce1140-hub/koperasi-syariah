<?php

namespace Tests\Feature;

use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Regresi temuan #5 — nama akun COA yang sudah bermutasi tidak boleh diubah.
 *
 * Nama akun adalah jejak audit laporan keuangan. Bila diganti setelah akun
 * dipakai pada jurnal Posted, laporan periode lalu seolah "berubah sejarah".
 * Guard hanya berlaku untuk akun yang sudah bermutasi; akun baru tetap bebas
 * diganti namanya.
 */
class CoaNamaAkunTerkunciTest extends TestCase
{
    use DatabaseTransactions;

    private function superAdmin(): User
    {
        return User::firstOrCreate(
            ['email' => 'superadmin.coanama@test.local'],
            ['name' => 'Super Admin Uji Nama CoA', 'password' => Hash::make('password'), 'role' => 'super_admin']
        );
    }

    private function akun(string $kode, string $nama): Coa
    {
        return Coa::create([
            'kode_akun' => $kode,
            'nama_akun' => $nama,
            'kategori' => 'Aset',
            'klasifikasi_neraca' => 'Aset Lancar',
            'tipe_saldo_normal' => 'Debet',
            'saldo_awal' => 0,
            'is_active' => true,
        ]);
    }

    private function muatPayload(Coa $coa, string $namaBaru): array
    {
        return [
            'kode_akun' => $coa->kode_akun,
            'nama_akun' => $namaBaru,
            'kategori' => 'Aset',
            'klasifikasi_neraca' => 'Aset Lancar',
            'tipe_saldo_normal' => 'Debet',
            'saldo_awal' => 0,
            'has_percentage' => 0,
        ];
    }

    /** Posting satu jurnal Posted yang memakai akun tersebut. */
    private function catatMutasi(Coa $coa): void
    {
        $jurnal = Jurnal::create([
            'no_jurnal' => 'UJI-NAMA-'.uniqid(),
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'UJI-NAMA-COA',
            'keterangan' => 'Uji guard nama akun CoA',
            'total_debet' => 1000,
            'total_kredit' => 1000,
            'is_balance' => true,
            'status' => 'Posted',
            'posted_at' => now(),
        ]);

        JurnalDetail::create([
            'jurnal_id' => $jurnal->id,
            'coa_id' => $coa->id,
            'baris' => 1,
            'debet' => 1000,
            'kredit' => 0,
            'keterangan' => 'Uji guard nama akun CoA',
        ]);
    }

    public function test_nama_akun_tanpa_mutasi_boleh_diubah(): void
    {
        $coa = $this->akun('1-9912', 'Akun Uji Tanpa Mutasi');

        $response = $this->actingAs($this->superAdmin())
            ->put(route('pengurus.coa.update', $coa), $this->muatPayload($coa, 'Akun Uji Nama Baru'));

        $response->assertRedirect(route('pengurus.coa.index'));
        $response->assertSessionHas('success');
        $this->assertSame('Akun Uji Nama Baru', $coa->fresh()->nama_akun);
    }

    public function test_nama_akun_sudah_bermutasi_ditolak_dan_tidak_berubah(): void
    {
        $coa = $this->akun('1-9911', 'Akun Uji Sudah Bermutasi');
        $this->catatMutasi($coa);

        $response = $this->actingAs($this->superAdmin())
            ->put(route('pengurus.coa.update', $coa), $this->muatPayload($coa, 'Nama Yang Dilarang'));

        $response->assertRedirect(route('pengurus.coa.index'));
        $response->assertSessionHas('error');
        $this->assertSame('Akun Uji Sudah Bermutasi', $coa->fresh()->nama_akun);
    }
}
