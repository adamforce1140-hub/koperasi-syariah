<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\PengajuanPembiayaan;
use App\Models\User;
use App\Support\PemisahanTugas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * GCG — PEMISAHAN TUGAS pada approve/reject pengajuan oleh AO (R-03).
 *
 * Menutup temuan audit: AOController::pengajuanApprove() dan pengajuanReject()
 * sebelumnya tidak memakai PemisahanTugas, sehingga AO dapat memproses
 * pengajuan yang ia buat sendiri.
 *
 * Setiap kasus diuji DUA sisi:
 *  - AO bukan pengaju ⇒ DIPROSES (status berubah).
 *  - AO = pengaju     ⇒ DITOLAK (status TIDAK berubah + pesan standar).
 */
class PemisahanTugasAoTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $role, string $email): User
    {
        return User::updateOrCreate(
            ['email' => $email],
            [
                'name' => ucfirst($role).' Uji SoD AO',
                'password' => Hash::make('rahasia'),
                'role' => $role,
                'is_active' => true,
            ]
        );
    }

    private function anggota(): Anggota
    {
        return Anggota::create([
            'no_anggota' => 'SODAO'.uniqid(),
            'nama_lengkap' => 'Anggota Uji SoD AO',
            'nik' => '3201010101010002',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji SoD AO No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'no_hp' => '0813'.random_int(10000000, 99999999),
            'status_anggota' => Anggota::STATUS_ACTIVE,
        ]);
    }

    /** Membuat pengajuan dengan pengaju (ao_id & created_by) yang ditentukan. */
    private function buatPengajuan(int $pengajuId): PengajuanPembiayaan
    {
        $anggota = $this->anggota();

        return PengajuanPembiayaan::create([
            'no_pengajuan' => 'PGJ-SODAO-'.uniqid(),
            'anggota_id' => $anggota->id,
            'jenis_akad' => 'Mudharabah',
            'jumlah_pengajuan' => 5_000_000,
            'total_pembiayaan' => 5_000_000,
            'tenor' => 12,
            'tujuan' => 'Uji pemisahan tugas AO',
            'status' => PengajuanPembiayaan::STATUS_MENUNGGU,
            'ao_id' => $pengajuId,
            'created_by' => $pengajuId,
        ]);
    }

    public function test_ao_bukan_pengaju_dapat_meneruskan_pengajuan(): void
    {
        $pengaju = $this->user('ao', 'sod-ao-pengaju@koperasi.test');
        $ao = $this->user('ao', 'sod-ao-approver@koperasi.test');
        $item = $this->buatPengajuan($pengaju->id);

        $this->actingAs($ao)
            ->post(route('ao.pengajuan.approve', $item->id), [])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error');

        $this->assertSame(
            PengajuanPembiayaan::STATUS_DIREVIEW,
            $item->fresh()->status,
            'AO yang bukan pengaju harus dapat meneruskan pengajuan.'
        );
    }

    public function test_ao_pengaju_tidak_dapat_meneruskan_pengajuan_sendiri(): void
    {
        $ao = $this->user('ao', 'sod-ao-self-approve@koperasi.test');
        $item = $this->buatPengajuan($ao->id);

        $this->actingAs($ao)
            ->post(route('ao.pengajuan.approve', $item->id), [])
            ->assertSessionHas('error', PemisahanTugas::PESAN);

        $this->assertSame(
            PengajuanPembiayaan::STATUS_MENUNGGU,
            $item->fresh()->status,
            'Status TIDAK boleh berubah ketika AO meneruskan pengajuan sendiri.'
        );
    }

    public function test_ao_bukan_pengaju_dapat_menolak_pengajuan(): void
    {
        $pengaju = $this->user('ao', 'sod-ao-pengaju-tolak@koperasi.test');
        $ao = $this->user('ao', 'sod-ao-penolak@koperasi.test');
        $item = $this->buatPengajuan($pengaju->id);

        $this->actingAs($ao)
            ->post(route('ao.pengajuan.reject', $item->id), [
                'catatan_tolak' => 'Dokumen tidak lengkap.',
            ])
            ->assertSessionHas('success');

        $this->assertSame(
            PengajuanPembiayaan::STATUS_DITOLAK,
            $item->fresh()->status,
            'AO yang bukan pengaju harus dapat menolak pengajuan.'
        );
    }

    public function test_ao_pengaju_tidak_dapat_menolak_pengajuan_sendiri(): void
    {
        $ao = $this->user('ao', 'sod-ao-self-reject@koperasi.test');
        $item = $this->buatPengajuan($ao->id);

        $this->actingAs($ao)
            ->post(route('ao.pengajuan.reject', $item->id), [
                'catatan_tolak' => 'Mencoba menolak pengajuan sendiri.',
            ])
            ->assertSessionHas('error', PemisahanTugas::PESAN);

        $this->assertSame(
            PengajuanPembiayaan::STATUS_MENUNGGU,
            $item->fresh()->status,
            'Penolakan oleh AO pengaju sendiri harus dicegah.'
        );
    }
}
