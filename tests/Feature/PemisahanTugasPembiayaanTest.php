<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Coa;
use App\Models\Pembiayaan;
use App\Models\PengajuanPembiayaan;
use App\Models\User;
use App\Support\PemisahanTugas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * GCG — PEMISAHAN TUGAS pada rantai approval pembiayaan.
 *
 * Menutup temuan audit: PengajuanPembiayaanIntegController sebelumnya TIDAK
 * memakai helper PemisahanTugas, sehingga satu akun dapat mengajukan sekaligus
 * mereview/menyetujui/menolak pengajuan yang ia buat sendiri.
 *
 * Cakupan: review(), tolakManajer(), approvePengurus(), tolakPengurus().
 * Setiap kasus diuji DUA sisi:
 *  - maker ≠ checker ⇒ DIPROSES (status berubah).
 *  - maker = checker ⇒ DITOLAK (status TIDAK berubah + pesan standar).
 */
class PemisahanTugasPembiayaanTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $role, string $email): User
    {
        return User::updateOrCreate(
            ['email' => $email],
            [
                'name' => ucfirst($role).' Uji SoD',
                'password' => Hash::make('rahasia'),
                'role' => $role,
                'is_active' => true,
            ]
        );
    }

    /** Mengambil id COA piutang pembiayaan sebagai akun_id wajib. */
    private function akunPembiayaanId(): int
    {
        return (int) (Coa::query()->value('id')
            ?? DB::table('coas')->value('id'));
    }

    private function anggota(): Anggota
    {
        return Anggota::create([
            'no_anggota' => 'SOD'.uniqid(),
            'nama_lengkap' => 'Anggota Uji SoD',
            'nik' => '3201010101010001',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji SoD No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'no_hp' => '0812'.random_int(10000000, 99999999),
            'status_anggota' => Anggota::STATUS_ACTIVE,
        ]);
    }

    /**
     * Membuat kontrak pembiayaan + pengajuan dengan pengaju (ao_id & created_by)
     * yang dapat ditentukan, pada status yang diminta.
     */
    private function buatPengajuan(int $pengajuId, string $status): Pembiayaan
    {
        $anggota = $this->anggota();

        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => 'PBY-SOD-'.uniqid(),
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunPembiayaanId(),
            'akad_pembiayaan' => 'Mudharabah',
            'jumlah_pembiayaan' => 5_000_000,
            'total_pembiayaan' => 5_000_000,
            'tenor' => 12,
            'jumlah_angsuran' => 416_666.67,
            'sisa_pembiayaan' => 5_000_000,
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths(12)->toDateString(),
            'status_pembiayaan' => $status,
        ]);

        PengajuanPembiayaan::create([
            'no_pengajuan' => 'PGJ-SOD-'.uniqid(),
            'anggota_id' => $anggota->id,
            'jenis_akad' => 'Mudharabah',
            'jumlah_pengajuan' => 5_000_000,
            'total_pembiayaan' => 5_000_000,
            'tenor' => 12,
            'tujuan' => 'Uji pemisahan tugas pembiayaan',
            'status' => PengajuanPembiayaan::STATUS_MENUNGGU,
            'pembiayaan_id' => $pembiayaan->id,
            'ao_id' => $pengajuId,
            'created_by' => $pengajuId,
        ]);

        return $pembiayaan;
    }

    /* =====================================================================
     | review() — Manajer
     ===================================================================== */

    public function test_manajer_bukan_pengaju_dapat_mereview(): void
    {
        $ao = $this->user('ao', 'sod-ao-review@koperasi.test');
        $manajer = $this->user('manajer', 'sod-mgr-review@koperasi.test');
        $pembiayaan = $this->buatPengajuan($ao->id, Pembiayaan::STATUS_PENDING_MANAJER);

        $this->actingAs($manajer)
            ->post(route('pengajuan-pembiayaan.review', $pembiayaan->id), [])
            ->assertSessionHas('success');

        $this->assertSame(
            Pembiayaan::STATUS_DIREVIEW,
            $pembiayaan->fresh()->status_pembiayaan,
            'Manajer yang bukan pengaju harus dapat mereview.'
        );
    }

    public function test_pengaju_tidak_dapat_mereview_pengajuan_sendiri(): void
    {
        $manajer = $this->user('manajer', 'sod-mgr-self-review@koperasi.test');
        // Manajer ini juga pengaju (ao_id & created_by = dirinya).
        $pembiayaan = $this->buatPengajuan($manajer->id, Pembiayaan::STATUS_PENDING_MANAJER);

        $this->actingAs($manajer)
            ->post(route('pengajuan-pembiayaan.review', $pembiayaan->id), [])
            ->assertSessionHas('error', PemisahanTugas::PESAN);

        $this->assertSame(
            Pembiayaan::STATUS_PENDING_MANAJER,
            $pembiayaan->fresh()->status_pembiayaan,
            'Status TIDAK boleh berubah ketika pemisahan tugas dilanggar.'
        );
    }

    /* =====================================================================
     | tolakManajer() — Manajer
     ===================================================================== */

    public function test_pengaju_tidak_dapat_menolak_pengajuan_sendiri(): void
    {
        $manajer = $this->user('manajer', 'sod-mgr-self-reject@koperasi.test');
        $pembiayaan = $this->buatPengajuan($manajer->id, Pembiayaan::STATUS_PENDING_MANAJER);

        $this->actingAs($manajer)
            ->post(route('pengajuan-pembiayaan.tolak-manajer', $pembiayaan->id), [
                'catatan_manajer' => 'Mencoba menolak pengajuan sendiri.',
            ])
            ->assertSessionHas('error', PemisahanTugas::PESAN);

        $this->assertSame(
            Pembiayaan::STATUS_PENDING_MANAJER,
            $pembiayaan->fresh()->status_pembiayaan,
            'Penolakan oleh pengaju sendiri harus dicegah.'
        );
    }

    /* =====================================================================
     | tolakPengurus() — Pengurus
     ===================================================================== */

    public function test_pengurus_pengaju_tidak_dapat_menolak_pengajuan_sendiri(): void
    {
        $pengurus = $this->user('pengurus', 'sod-pgr-self-reject@koperasi.test');
        $pembiayaan = $this->buatPengajuan($pengurus->id, Pembiayaan::STATUS_DIREVIEW);

        $this->actingAs($pengurus)
            ->post(route('pengajuan-pembiayaan.tolak-pengurus', $pembiayaan->id), [
                'catatan_pengurus' => 'Mencoba menolak pengajuan sendiri.',
            ])
            ->assertSessionHas('error', PemisahanTugas::PESAN);

        $this->assertSame(
            Pembiayaan::STATUS_DIREVIEW,
            $pembiayaan->fresh()->status_pembiayaan,
            'Penolakan pengurus atas pengajuan sendiri harus dicegah.'
        );
    }

    /* =====================================================================
     | Nilai pengaju NULL (data sistem) tidak dianggap pelanggaran.
     ===================================================================== */

    public function test_pengaju_null_tidak_dianggap_pelanggaran(): void
    {
        $anggota = $this->anggota();

        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => 'PBY-SOD-NULL-'.uniqid(),
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunPembiayaanId(),
            'akad_pembiayaan' => 'Mudharabah',
            'jumlah_pembiayaan' => 5_000_000,
            'total_pembiayaan' => 5_000_000,
            'tenor' => 12,
            'jumlah_angsuran' => 416_666.67,
            'sisa_pembiayaan' => 5_000_000,
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths(12)->toDateString(),
            'status_pembiayaan' => Pembiayaan::STATUS_PENDING_MANAJER,
        ]);

        // Sengaja TANPA baris pengajuan_pembiayaan → pengaju NULL.
        $manajer = $this->user('manajer', 'sod-mgr-null@koperasi.test');

        $this->actingAs($manajer)
            ->post(route('pengajuan-pembiayaan.review', $pembiayaan->id), [])
            ->assertSessionHas('success');

        $this->assertSame(
            Pembiayaan::STATUS_DIREVIEW,
            $pembiayaan->fresh()->status_pembiayaan,
            'Data tanpa pengaju (sistem) harus tetap dapat diproses.'
        );
    }
}
