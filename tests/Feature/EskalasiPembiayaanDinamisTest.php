<?php

namespace Tests\Feature;

use App\Models\KebijakanKoperasi;
use App\Models\Pembiayaan;
use App\Models\User;
use App\Support\HierarkiRole;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PAKET 2 — ESKALASI PEMBIAYAAN & BATAS DARI KEBIJAKAN (DYN-05, DYN-07).
 *
 * Mengunci dua syarat:
 *   1. Tujuan eskalasi "Teruskan" Manajer divalidasi terhadap matriks peran
 *      dan status tujuannya diturunkan dari `HierarkiRole`, bukan ternary.
 *   2. Batas nominal yang berasal dari kebijakan koperasi dikendalikan data
 *      (`batas_dari_kebijakan`), bukan `if ($peran === 'teller')` di kode.
 */
class EskalasiPembiayaanDinamisTest extends TestCase
{
    use DatabaseTransactions;

    private User $manajer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manajer = User::firstOrCreate(
            ['email' => 'eskalasi.pembiayaan.manajer@test.local'],
            [
                'name' => 'Uji Eskalasi Pembiayaan',
                'password' => Hash::make('password'),
                'role' => 'manajer',
                'is_active' => true,
            ]
        );

        Setting::flush();
        HierarkiRole::flush();
    }

    private function pembiayaanUji(): Pembiayaan
    {
        $anggotaId = (int) DB::table('anggota')->insertGetId([
            'no_anggota' => 'AGT-ESK-'.random_int(100000, 999999),
            'nik' => '9999'.random_int(10000000, 99999999),
            'nama_lengkap' => 'Anggota Uji Eskalasi',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji Eskalasi No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Pembiayaan::create([
            'no_pembiayaan' => 'ESK-'.random_int(100000, 999999),
            'anggota_id' => $anggotaId,
            'akun_id' => DB::table('akun')->value('id') ?? 1,
            'akad_pembiayaan' => 'Murabahah',
            'tujuan_pembiayaan' => 'Uji eskalasi pembiayaan',
            'jumlah_pembiayaan' => 1_000_000,
            'margin_nisbah' => 10,
            'total_ujroh' => 100_000,
            'total_pembiayaan' => 1_100_000,
            'jumlah_angsuran' => 91_666.67,
            'tenor' => 12,
            'sisa_pembiayaan' => 1_100_000,
            'status_pembiayaan' => Pembiayaan::STATUS_PENDING_MANAJER,
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths(12)->toDateString(),
        ]);
    }

    /* =====================================================================
     | DYN-05 — Tujuan eskalasi divalidasi & status diturunkan dari matriks
     |===================================================================== */

    public function test_forward_ke_pengurus_memakai_status_dari_matriks(): void
    {
        $pembiayaan = $this->pembiayaanUji();

        $respons = $this->actingAs($this->manajer)
            ->post(route('manajer.approval-pembiayaan.forward', $pembiayaan->id), [
                'target' => 'pengurus',
            ]);

        $respons->assertRedirect(route('manajer.approval-pembiayaan.index'));
        $respons->assertSessionHasNoErrors();

        $this->assertSame(
            Pembiayaan::STATUS_PENDING_PENGURUS,
            $pembiayaan->fresh()->status_pembiayaan
        );
    }

    public function test_forward_ke_dps_memakai_status_dari_matriks(): void
    {
        $pembiayaan = $this->pembiayaanUji();

        $this->actingAs($this->manajer)
            ->post(route('manajer.approval-pembiayaan.forward', $pembiayaan->id), [
                'target' => 'dps',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            Pembiayaan::STATUS_PENDING_DPS,
            $pembiayaan->fresh()->status_pembiayaan
        );
    }

    public function test_target_di_luar_matriks_ditolak_bukan_jatuh_ke_pengurus(): void
    {
        $pembiayaan = $this->pembiayaanUji();

        $respons = $this->actingAs($this->manajer)
            ->post(route('manajer.approval-pembiayaan.forward', $pembiayaan->id), [
                'target' => 'komite',
            ]);

        $respons->assertSessionHasErrors('target');

        // Status TIDAK berubah — dulu target asing diam-diam menjadi pengurus.
        $this->assertSame(
            Pembiayaan::STATUS_PENDING_MANAJER,
            $pembiayaan->fresh()->status_pembiayaan
        );
    }

    public function test_tanpa_target_tetap_bereskalasi_ke_pengurus(): void
    {
        $pembiayaan = $this->pembiayaanUji();

        $this->actingAs($this->manajer)
            ->post(route('manajer.approval-pembiayaan.forward', $pembiayaan->id), [])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            Pembiayaan::STATUS_PENDING_PENGURUS,
            $pembiayaan->fresh()->status_pembiayaan
        );
    }

    public function test_daftar_tujuan_eskalasi_berasal_dari_konfigurasi(): void
    {
        $this->assertSame(
            ['pengurus', 'dps'],
            HierarkiRole::kelompok('eskalasi_pembiayaan')
        );
    }

    /* =====================================================================
     | DYN-07 — Batas dari kebijakan dikendalikan data, bukan nama peran
     |===================================================================== */

    public function test_batas_teller_tetap_mengikuti_kebijakan_koperasi(): void
    {
        $kebijakan = KebijakanKoperasi::first() ?? new KebijakanKoperasi;
        $kebijakan->limit_penarikan_teller = 4_250_000;
        $kebijakan->save();

        Setting::flush();
        HierarkiRole::flush();

        $this->assertSame(4250000.0, HierarkiRole::limitPerTransaksi('teller', 'tarik_tunai'));
    }

    public function test_peran_lain_dapat_mewarisi_kebijakan_tanpa_ubah_kode(): void
    {
        $kebijakan = KebijakanKoperasi::first() ?? new KebijakanKoperasi;
        $kebijakan->limit_setoran_teller = 12_345_678;
        $kebijakan->save();

        // Tambahkan pemetaan baru hanya lewat konfigurasi — tanpa menyentuh
        // HierarkiRole. Inilah inti DYN-07: perilaku tidak lagi terikat nama
        // peran `teller`.
        config(['role_hierarki.batas_dari_kebijakan.ao.setor_tunai' => 'limit_setoran_teller']);

        Setting::flush();
        HierarkiRole::flush();

        $this->assertSame(12345678.0, HierarkiRole::limitPerTransaksi('ao', 'setor_tunai'));
    }
}
