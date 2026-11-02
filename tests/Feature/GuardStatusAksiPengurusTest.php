<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Coa;
use App\Models\Pembiayaan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * GUARD STATUS pada aksi Pengurus yang bersifat final:
 *
 *  1. `pengurus.pembiayaan.reject` (Dashboard\PengurusController) — sebelumnya
 *     pembiayaan berstatus AKTIF dapat diubah menjadi DITOLAK tanpa pembalikan
 *     jurnal maupun pembatalan jadwal angsuran.
 *  2. `pengajuan-pembiayaan.tolak-pengurus` (PengajuanPembiayaanIntegController)
 *     — celah yang sama, hanya dijaga maker ≠ checker.
 *  3. `pengurus.coa.toggle` — akun INTI (registri `App\Support\Coa`) yang dipakai
 *     lintas service tidak boleh dinonaktifkan, karena `Coa::terdaftar()` akan
 *     melempar RuntimeException sehingga posting jurnal gagal di tengah jalan.
 */
class GuardStatusAksiPengurusTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $role, string $email): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            [
                'name' => ucfirst($role).' Uji Guard',
                'password' => Hash::make('rahasia'),
                'role' => $role,
                'is_active' => true,
            ]
        );
    }

    private function pembiayaan(string $status): Pembiayaan
    {
        $anggota = Anggota::create([
            'no_anggota' => 'GRD'.uniqid(),
            'nama_lengkap' => 'Anggota Uji Guard',
            'nik' => '320101'.random_int(1000000000, 9999999999),
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji Guard No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'no_hp' => '0813'.random_int(10000000, 99999999),
            'status_anggota' => Anggota::STATUS_ACTIVE,
        ]);

        return Pembiayaan::create([
            'no_pembiayaan' => 'PB-GRD-'.uniqid(),
            'anggota_id' => $anggota->id,
            'akun_id' => (int) Coa::query()->value('id'),
            'kode_produk' => 'UJI-GUARD',
            'akad_pembiayaan' => 'Murabahah',
            'jumlah_pembiayaan' => 5_000_000,
            'total_pembiayaan' => 5_000_000,
            'tenor' => 12,
            'jumlah_angsuran' => 416_666.67,
            'sisa_pembiayaan' => 5_000_000,
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths(12)->toDateString(),
            'status_pembiayaan' => $status,
        ]);
    }

    public function test_pengurus_tidak_dapat_menolak_pembiayaan_yang_sudah_aktif(): void
    {
        $item = $this->pembiayaan(Pembiayaan::STATUS_AKTIF);

        $this->actingAs($this->user('pengurus', 'guard-pengurus@koperasi.test'))
            ->post(route('pengurus.pembiayaan.reject', $item->id), ['catatan_tolak' => 'Uji guard'])
            ->assertRedirect(route('pengurus.pembiayaan.index'))
            ->assertSessionHas('error');

        $this->assertSame(
            Pembiayaan::STATUS_AKTIF,
            $item->fresh()->status_pembiayaan,
            'Pembiayaan aktif tidak boleh berubah menjadi ditolak.'
        );
    }

    public function test_pengurus_masih_dapat_menolak_pembiayaan_yang_menunggu_approval(): void
    {
        $item = $this->pembiayaan(Pembiayaan::STATUS_PENDING_PENGURUS);

        $this->actingAs($this->user('pengurus', 'guard-pengurus@koperasi.test'))
            ->post(route('pengurus.pembiayaan.reject', $item->id), ['catatan_tolak' => 'Dokumen tidak lengkap'])
            ->assertRedirect(route('pengurus.pembiayaan.index'))
            ->assertSessionHas('success');

        $this->assertSame(Pembiayaan::STATUS_DITOLAK, $item->fresh()->status_pembiayaan);
    }

    public function test_tolak_pengurus_tidak_dapat_menolak_pembiayaan_yang_sudah_aktif(): void
    {
        $item = $this->pembiayaan(Pembiayaan::STATUS_AKTIF);

        $this->actingAs($this->user('pengurus', 'guard-pengurus@koperasi.test'))
            ->post(route('pengajuan-pembiayaan.tolak-pengurus', $item->id), ['catatan_pengurus' => 'Uji guard'])
            ->assertSessionHas('error');

        $this->assertSame(
            Pembiayaan::STATUS_AKTIF,
            $item->fresh()->status_pembiayaan,
            'Pembiayaan aktif tidak boleh berubah menjadi ditolak melalui jalur integrasi.'
        );
    }

    public function test_akun_inti_tidak_dapat_dinonaktifkan(): void
    {
        $coa = Coa::firstOrCreate(
            ['kode_akun' => \App\Support\Coa::SIMPANAN_SUKARELA],
            [
                'nama_akun' => 'Simpanan Sukarela',
                'kategori' => 'Kewajiban',
                'klasifikasi_neraca' => 'Kewajiban',
                'tipe_saldo_normal' => 'Kredit',
                'saldo_awal' => 0,
                'is_active' => true,
            ]
        );
        $coa->update(['is_active' => true]);

        $this->actingAs($this->user('super_admin', 'guard-superadmin@koperasi.test'))
            ->post(route('pengurus.coa.toggle', $coa->id))
            ->assertRedirect(route('pengurus.coa.index'))
            ->assertSessionHas('error');

        $this->assertTrue((bool) $coa->fresh()->is_active, 'Akun inti harus tetap aktif.');
    }

    public function test_akun_non_inti_tetap_dapat_dinonaktifkan(): void
    {
        $coa = Coa::firstOrCreate(
            ['kode_akun' => '9-9901'],
            [
                'nama_akun' => 'Akun Uji Non Inti',
                'kategori' => 'Beban',
                'klasifikasi_neraca' => 'Beban',
                'tipe_saldo_normal' => 'Debet',
                'saldo_awal' => 0,
                'is_active' => true,
            ]
        );
        $coa->update(['is_active' => true]);

        $this->actingAs($this->user('super_admin', 'guard-superadmin@koperasi.test'))
            ->post(route('pengurus.coa.toggle', $coa->id))
            ->assertSessionHas('success');

        $this->assertFalse((bool) $coa->fresh()->is_active);
    }
}
