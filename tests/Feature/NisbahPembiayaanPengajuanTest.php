<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\Pembiayaan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * B6 — NISBAH PEMBIAYAAN pada jalur pengajuan AO.
 *
 * Temuan audit: `PengajuanPembiayaanIntegController::store()` menerima
 * `margin_nisbah` tanpa batas atas dan tanpa syarat nisbah untuk akad bagi
 * hasil, lalu menyimpan kontrak tanpa `nisbah_pengelola`/`nisbah_pemilik`.
 * Akibatnya akad Mudharabah/Musyarakah dapat lahir dengan nisbah 0% (bagi
 * hasil tidak dapat dihitung) atau > 100% (porsi melebihi pendapatan), dan
 * laporan DPS terpaksa menurunkan nisbah dari `margin_nisbah` (COALESCE).
 *
 * Kontrak perilaku yang diuji di sini:
 *  1. Akad bagi hasil wajib nisbah > 0%.
 *  2. Nisbah di atas 100% ditolak oleh validasi.
 *  3. Akad bagi hasil menyimpan nisbah pengelola + pemilik yang berjumlah 100%.
 *  4. Akad jual beli (Murabahah) tidak dipaksa mengisi nisbah (perilaku lama).
 */
class NisbahPembiayaanPengajuanTest extends TestCase
{
    use DatabaseTransactions;

    private function pengguna(string $role = 'ao'): User
    {
        return User::updateOrCreate(
            ['email' => "{$role}.nisbah@uji.local"],
            [
                'name' => ucfirst($role).' Uji Nisbah',
                'password' => Hash::make('rahasia'),
                'role' => $role,
                'is_active' => true,
            ]
        );
    }

    private function anggota(): Anggota
    {
        return Anggota::create([
            'no_anggota' => 'NSB'.uniqid(),
            'nama_lengkap' => 'Anggota Uji Nisbah',
            'nik' => '3201010101010002',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji Nisbah No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'no_hp' => '0813'.random_int(10000000, 99999999),
            'status_anggota' => Anggota::STATUS_ACTIVE,
        ]);
    }

    private function payload(Anggota $anggota, array $override = []): array
    {
        return array_merge([
            'anggota_id' => $anggota->id,
            'akad_pembiayaan' => Pembiayaan::AKAD_MUDHARABAH,
            'jumlah_pembiayaan' => 5_000_000,
            'tenor' => 12,
            'margin_nisbah' => 30,
            'tujuan_pembiayaan' => 'Modal Usaha',
        ], $override);
    }

    public function test_akad_bagi_hasil_wajib_nisbah_lebih_dari_nol(): void
    {
        $anggota = $this->anggota();

        $this->actingAs($this->pengguna())
            ->post(route('pengajuan-pembiayaan.store'), $this->payload($anggota, ['margin_nisbah' => 0]))
            ->assertSessionHas('error');

        $this->assertSame(
            0,
            Pembiayaan::where('anggota_id', $anggota->id)->count(),
            'Pengajuan bagi hasil tanpa nisbah tidak boleh tersimpan.'
        );
    }

    public function test_nisbah_di_atas_seratus_persen_ditolak(): void
    {
        $anggota = $this->anggota();

        $this->actingAs($this->pengguna())
            ->post(route('pengajuan-pembiayaan.store'), $this->payload($anggota, ['margin_nisbah' => 150]))
            ->assertSessionHasErrors('margin_nisbah');

        $this->assertSame(
            0,
            Pembiayaan::where('anggota_id', $anggota->id)->count(),
            'Nisbah di atas 100% tidak boleh menghasilkan kontrak.'
        );
    }

    public function test_akad_bagi_hasil_menyimpan_nisbah_pengelola_dan_pemilik_berjumlah_seratus(): void
    {
        $anggota = $this->anggota();

        $this->actingAs($this->pengguna())
            ->post(route('pengajuan-pembiayaan.store'), $this->payload($anggota, ['margin_nisbah' => 30]))
            ->assertSessionHas('success');

        $pembiayaan = Pembiayaan::where('anggota_id', $anggota->id)->firstOrFail();

        $this->assertSame(30.0, (float) $pembiayaan->nisbah_pengelola);
        $this->assertSame(70.0, (float) $pembiayaan->nisbah_pemilik);
        $this->assertSame(
            100.0,
            (float) $pembiayaan->nisbah_pengelola + (float) $pembiayaan->nisbah_pemilik,
            'Nisbah pengelola + pemilik wajib tepat 100%.'
        );
        $this->assertSame(
            today()->addMonthsNoOverflow(12)->toDateString(),
            $pembiayaan->tanggal_jatuh_tempo?->toDateString(),
            'Jatuh tempo kontrak wajib terisi: tanggal akad + tenor.'
        );
    }

    public function test_akad_jual_beli_tidak_dipaksa_mengisi_nisbah(): void
    {
        $anggota = $this->anggota();

        $this->actingAs($this->pengguna())
            ->post(route('pengajuan-pembiayaan.store'), $this->payload($anggota, [
                'akad_pembiayaan' => Pembiayaan::AKAD_MURABAHAH,
                'margin_nisbah' => 12,
            ]))
            ->assertSessionHas('success');

        $pembiayaan = Pembiayaan::where('anggota_id', $anggota->id)->firstOrFail();

        $this->assertNull($pembiayaan->nisbah_pengelola);
        $this->assertNull($pembiayaan->nisbah_pemilik);
        $this->assertSame(12.0, (float) $pembiayaan->margin_nisbah);
    }

    /** Payload minimum untuk `POST /api/pembiayaan` (jalur tulis kontrak). */
    private function payloadApi(Anggota $anggota, array $override = []): array
    {
        return array_merge([
            'no_pembiayaan' => 'PMB-API-'.uniqid(),
            'anggota_id' => $anggota->id,
            'akun_id' => (int) Akun::query()->value('id'),
            'akad_pembiayaan' => Pembiayaan::AKAD_MUDHARABAH,
            'tujuan_pembiayaan' => 'Modal Usaha',
            'jumlah_pembiayaan' => 5_000_000,
            'margin_nisbah' => 30,
            'total_pembiayaan' => 5_000_000,
            'tenor' => 12,
            'frekuensi_angsuran' => 'Bulanan',
            'jumlah_angsuran' => 416_666.67,
            'tanggal_akad' => today()->toDateString(),
            'tanggal_jatuh_tempo' => today()->addMonthsNoOverflow(12)->toDateString(),
            'sisa_pembiayaan' => 5_000_000,
        ], $override);
    }

    public function test_api_menolak_nisbah_pengelola_dan_pemilik_yang_tidak_berjumlah_seratus(): void
    {
        $anggota = $this->anggota();

        $this->actingAs($this->pengguna('manajer'))
            ->postJson('/api/pembiayaan', $this->payloadApi($anggota, [
                'nisbah_pengelola' => 30,
                'nisbah_pemilik' => 40,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('nisbah_pemilik');

        $this->assertSame(0, Pembiayaan::where('anggota_id', $anggota->id)->count());
    }

    public function test_api_menerima_nisbah_pengelola_dan_pemilik_yang_berjumlah_seratus(): void
    {
        $anggota = $this->anggota();

        $this->actingAs($this->pengguna('manajer'))
            ->postJson('/api/pembiayaan', $this->payloadApi($anggota, [
                'nisbah_pengelola' => 30,
                'nisbah_pemilik' => 70,
            ]))
            ->assertStatus(201);

        $pembiayaan = Pembiayaan::where('anggota_id', $anggota->id)->firstOrFail();

        $this->assertSame(100.0, (float) $pembiayaan->nisbah_pengelola + (float) $pembiayaan->nisbah_pemilik);
    }

    public function test_api_menolak_margin_nisbah_di_atas_seratus_persen(): void
    {
        $anggota = $this->anggota();

        $this->actingAs($this->pengguna('manajer'))
            ->postJson('/api/pembiayaan', $this->payloadApi($anggota, ['margin_nisbah' => 150]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('margin_nisbah');
    }
}
