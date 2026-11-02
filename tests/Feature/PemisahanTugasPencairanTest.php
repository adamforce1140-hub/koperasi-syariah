<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Coa;
use App\Models\Pembiayaan;
use App\Models\PengajuanPembiayaan;
use App\Models\Rekening;
use App\Models\User;
use App\Support\PemisahanTugas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * GCG — PEMISAHAN persetujuan dan pencairan pembiayaan.
 *
 * Pencairan (kas keluar, jadwal angsuran, jurnal) dipisah dari persetujuan:
 *  - approve()  hanya menyetujui (status DISETUJUI, tanpa kas keluar).
 *  - cairkan()  dilakukan pengurus yang BUKAN pengaju dan BUKAN penyetuju.
 */
class PemisahanTugasPencairanTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $role, string $email): User
    {
        return User::updateOrCreate(
            ['email' => $email],
            [
                'name' => ucfirst($role).' Uji SoD Cair',
                'password' => Hash::make('rahasia'),
                'role' => $role,
                'is_active' => true,
            ]
        );
    }

    /** Mengambil id COA sebagai akun_id wajib. */
    private function akunPembiayaanId(): int
    {
        return (int) (Coa::query()->value('id')
            ?? DB::table('coas')->value('id'));
    }

    private function anggota(): Anggota
    {
        return Anggota::create([
            'no_anggota' => 'SODC'.uniqid(),
            'nama_lengkap' => 'Anggota Uji SoD Cair',
            'nik' => '3201010101010003',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji SoD Cair No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'no_hp' => '0814'.random_int(10000000, 99999999),
            'status_anggota' => Anggota::STATUS_ACTIVE,
        ]);
    }

    /** Membuat kontrak pembiayaan + pengajuan dengan pengaju yang ditentukan. */
    private function buatPembiayaan(int $pengajuId, string $status, ?int $disetujuiOleh = null): Pembiayaan
    {
        $anggota = $this->anggota();

        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => 'PBY-SODC-'.uniqid(),
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
            'disetujui_oleh' => $disetujuiOleh,
        ]);

        PengajuanPembiayaan::create([
            'no_pengajuan' => 'PGJ-SODC-'.uniqid(),
            'anggota_id' => $anggota->id,
            'jenis_akad' => 'Mudharabah',
            'jumlah_pengajuan' => 5_000_000,
            'total_pembiayaan' => 5_000_000,
            'tenor' => 12,
            'tujuan' => 'Uji pemisahan pencairan',
            'status' => PengajuanPembiayaan::STATUS_MENUNGGU,
            'pembiayaan_id' => $pembiayaan->id,
            'ao_id' => $pengajuId,
            'created_by' => $pengajuId,
        ]);

        return $pembiayaan;
    }

    public function test_approve_hanya_menyetujui_tanpa_mencairkan(): void
    {
        $ao = $this->user('ao', 'sodc-ao-approve@koperasi.test');
        $pengurus = $this->user('pengurus', 'sodc-pgr-approve@koperasi.test');
        $pembiayaan = $this->buatPembiayaan($ao->id, Pembiayaan::STATUS_DIREVIEW);
        $saldoAwal = Rekening::query()->where('anggota_id', $pembiayaan->anggota_id)->sum('saldo');

        $this->actingAs($pengurus)
            ->post(route('pengajuan-pembiayaan.approve', $pembiayaan->id), [])
            ->assertSessionHas('success');

        $fresh = $pembiayaan->fresh();
        $this->assertSame(Pembiayaan::STATUS_DISETUJUI, $fresh->status_pembiayaan);
        $this->assertSame($pengurus->id, (int) $fresh->disetujui_oleh);
        $this->assertFalse((bool) $fresh->is_disbursed, 'Approve tidak boleh menandai sudah dicairkan.');
        $this->assertSame(
            $saldoAwal,
            Rekening::query()->where('anggota_id', $pembiayaan->anggota_id)->sum('saldo'),
            'Approve tidak boleh mengubah saldo rekening.'
        );
    }

    public function test_pengelola_approve_hanya_menyetujui_tanpa_mencairkan(): void
    {
        $ao = $this->user('ao', 'sodc-ao-pgl-approve@koperasi.test');
        $pengurus = $this->user('pengurus', 'sodc-pgr-pgl-approve@koperasi.test');
        $pembiayaan = $this->buatPembiayaan($ao->id, Pembiayaan::STATUS_DIREVIEW);
        $saldoAwal = Rekening::query()->where('anggota_id', $pembiayaan->anggota_id)->sum('saldo');

        $this->actingAs($pengurus)
            ->patch(route('pengelola.pengajuan-pinjaman.approve', $pembiayaan->id), [])
            ->assertSessionHas('success');

        $fresh = $pembiayaan->fresh();
        $this->assertSame(Pembiayaan::STATUS_DISETUJUI, $fresh->status_pembiayaan);
        $this->assertSame($pengurus->id, (int) $fresh->disetujui_oleh);
        $this->assertFalse((bool) $fresh->is_disbursed, 'Approve tidak boleh menandai sudah dicairkan.');
        $this->assertSame(0, $fresh->angsuran()->count(), 'Approve tidak boleh membuat jadwal angsuran.');
        $this->assertSame(
            $saldoAwal,
            Rekening::query()->where('anggota_id', $pembiayaan->anggota_id)->sum('saldo'),
            'Approve tidak boleh mengubah saldo rekening.'
        );
    }

    public function test_pengelola_tidak_dapat_menyetujui_pengajuan_sendiri(): void
    {
        $pengurus = $this->user('pengurus', 'sodc-pgr-pgl-pengaju@koperasi.test');
        $pembiayaan = $this->buatPembiayaan($pengurus->id, Pembiayaan::STATUS_DIREVIEW);

        $this->actingAs($pengurus)
            ->patch(route('pengelola.pengajuan-pinjaman.approve', $pembiayaan->id), [])
            ->assertSessionHas('error', PemisahanTugas::PESAN);

        $this->assertSame(
            Pembiayaan::STATUS_DIREVIEW,
            $pembiayaan->fresh()->status_pembiayaan,
            'Pengaju tidak boleh menyetujui pengajuannya sendiri.'
        );
    }

    public function test_pengurus_approve_mencatat_disetujui_oleh(): void
    {
        $ao = $this->user('ao', 'sodc-ao-pgr-approve@koperasi.test');
        $pengurus = $this->user('pengurus', 'sodc-pgr-pgr-approve@koperasi.test');
        $pembiayaan = $this->buatPembiayaan($ao->id, Pembiayaan::STATUS_PENDING_PENGURUS);

        $this->actingAs($pengurus)
            ->post(route('pengurus.pembiayaan.approve', $pembiayaan->id), [])
            ->assertSessionHas('success');

        $fresh = $pembiayaan->fresh();
        $this->assertSame(Pembiayaan::STATUS_PENDING_DPS, $fresh->status_pembiayaan);
        $this->assertSame(
            $pengurus->id,
            (int) $fresh->disetujui_oleh,
            'Penyetuju harus tercatat agar guard pemisahan tugas di tahap pencairan mengenalinya.'
        );
    }

    public function test_pengurus_berbeda_dapat_mencairkan(): void
    {
        $ao = $this->user('ao', 'sodc-ao-cair-ok@koperasi.test');
        $penyetuju = $this->user('pengurus', 'sodc-pgr-setuju-ok@koperasi.test');
        $pencair = $this->user('pengurus', 'sodc-pgr-cair-ok@koperasi.test');
        $pembiayaan = $this->buatPembiayaan($ao->id, Pembiayaan::STATUS_DISETUJUI, $penyetuju->id);

        $this->actingAs($pencair)
            ->post(route('pengajuan-pembiayaan.cairkan', $pembiayaan->id), [])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error');

        $fresh = $pembiayaan->fresh();
        $this->assertSame(Pembiayaan::STATUS_DICAIRKAN, $fresh->status_pembiayaan);
        $this->assertTrue((bool) $fresh->is_disbursed);
        $this->assertSame(
            $penyetuju->id,
            (int) $fresh->disetujui_oleh,
            'Pencairan tidak boleh menimpa penyetuju asli.'
        );
    }

    public function test_penyetuju_tidak_dapat_mencairkan_sendiri(): void
    {
        $ao = $this->user('ao', 'sodc-ao-self-cair@koperasi.test');
        $penyetuju = $this->user('pengurus', 'sodc-pgr-self-cair@koperasi.test');
        $pembiayaan = $this->buatPembiayaan($ao->id, Pembiayaan::STATUS_DISETUJUI, $penyetuju->id);

        $this->actingAs($penyetuju)
            ->post(route('pengajuan-pembiayaan.cairkan', $pembiayaan->id), [])
            ->assertSessionHas('error', PemisahanTugas::PESAN);

        $this->assertSame(
            Pembiayaan::STATUS_DISETUJUI,
            $pembiayaan->fresh()->status_pembiayaan,
            'Penyetuju tidak boleh mencairkan pembiayaan yang ia setujui sendiri.'
        );
    }

    public function test_pengaju_tidak_dapat_mencairkan_pengajuan_sendiri(): void
    {
        $pengurus = $this->user('pengurus', 'sodc-pgr-pengaju@koperasi.test');
        $penyetuju = $this->user('pengurus', 'sodc-pgr-setuju-pengaju@koperasi.test');
        $pembiayaan = $this->buatPembiayaan($pengurus->id, Pembiayaan::STATUS_DISETUJUI, $penyetuju->id);

        $this->actingAs($pengurus)
            ->post(route('pengajuan-pembiayaan.cairkan', $pembiayaan->id), [])
            ->assertSessionHas('error', PemisahanTugas::PESAN);

        $this->assertSame(
            Pembiayaan::STATUS_DISETUJUI,
            $pembiayaan->fresh()->status_pembiayaan,
            'Pengaju tidak boleh mencairkan pengajuannya sendiri.'
        );
    }

    public function test_cairkan_ditolak_jika_belum_disetujui(): void
    {
        $ao = $this->user('ao', 'sodc-ao-belum@koperasi.test');
        $pencair = $this->user('pengurus', 'sodc-pgr-belum@koperasi.test');
        $pembiayaan = $this->buatPembiayaan($ao->id, Pembiayaan::STATUS_DIREVIEW);

        $this->actingAs($pencair)
            ->post(route('pengajuan-pembiayaan.cairkan', $pembiayaan->id), [])
            ->assertSessionHas('error');

        $this->assertSame(
            Pembiayaan::STATUS_DIREVIEW,
            $pembiayaan->fresh()->status_pembiayaan,
            'Pencairan dari status selain disetujui harus ditolak.'
        );
        $this->assertFalse((bool) $pembiayaan->fresh()->is_disbursed);
    }
}
