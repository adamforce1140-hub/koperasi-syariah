<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\Coa;
use App\Models\Pembiayaan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Integritas endpoint jadwal angsuran milik Teller (batch 16).
 *
 * `teller.angsuran.generate-jadwal` membuat baris jadwal baru. Bila endpoint
 * ini mau menerima pembiayaan dalam status apa pun, ia dapat dipakai untuk
 * menumbuhkan jadwal pada akad yang belum/tidak pernah dicairkan.
 */
class IntegritasJadwalAngsuranTellerTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        if (Coa::query()->count() === 0) {
            $this->markTestSkipped('CoA belum tersedia pada basis data uji.');
        }

        $this->prefix = 'JA'.strtoupper(substr(uniqid(), -6));
    }

    private function user(string $peran, string $sufiks = ''): User
    {
        return User::create([
            'name' => ucfirst($peran).' '.$this->prefix.$sufiks,
            'email' => $peran.'-'.strtolower($this->prefix).$sufiks.'@contoh.test',
            'password' => Hash::make('rahasia123'),
            'role' => $peran,
            'is_active' => true,
        ]);
    }

    private function pembiayaan(string $status): Pembiayaan
    {
        $anggota = Anggota::create([
            'nama_lengkap' => 'Anggota '.$this->prefix,
            'no_anggota' => Anggota::nomorAnggotaBaru('ANG-'.date('Ym').'-'),
            'nik' => str_pad((string) random_int(1000000000000000, 9999999999999999), 16, '0'),
            'jenis_kelamin' => 'Laki-laki',
            'no_hp' => '081200000017',
            'alamat' => 'Jl. Uji Batch 16 Jadwal',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_ACTIVE,
        ]);

        return Pembiayaan::create([
            'no_pembiayaan' => 'PB-'.$this->prefix,
            'anggota_id' => $anggota->id,
            'akun_id' => (int) Coa::query()->value('id'),
            'akad_pembiayaan' => 'murabahah',
            'jumlah_pembiayaan' => 1_200_000,
            'total_pembiayaan' => 1_200_000,
            'tenor' => 12,
            'jumlah_angsuran' => 100_000,
            'sisa_pembiayaan' => 1_200_000,
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths(12)->toDateString(),
            'status_pembiayaan' => $status,
        ]);
    }

    /**
     * T-07 — endpoint tidak menumbuhkan jadwal pada akad yang sudah lunas.
     *
     * Jadwal baru pada akad lunas membuat `sisa_pembiayaan` tampak berjalan
     * lagi di dashboard dan laporan, serta menciptakan tagihan yang tidak
     * berdasar akad. Test ini MENGUNCI perilaku yang benar.
     */
    public function test_generate_jadwal_tidak_menumbuhkan_jadwal_pada_akad_lunas(): void
    {
        $teller = $this->user('teller', 't07');
        $pembiayaan = $this->pembiayaan(Pembiayaan::STATUS_LUNAS);

        $this->actingAs($teller)
            ->post(route('teller.angsuran.generate-jadwal', $pembiayaan->id));

        $this->assertSame(0, Angsuran::query()->where('pembiayaan_id', $pembiayaan->id)->count(),
            'Akad lunas tidak boleh memperoleh jadwal angsuran baru.');
    }

    /**
     * T-08 — endpoint tidak menumbuhkan jadwal pada akad yang masih menunggu
     * keputusan (belum ada kewajiban angsuran).
     */
    public function test_generate_jadwal_tidak_menumbuhkan_jadwal_pada_akad_belum_dicairkan(): void
    {
        $teller = $this->user('teller', 't08');
        $pembiayaan = $this->pembiayaan(Pembiayaan::STATUS_PENDING_MANAJER);

        $this->actingAs($teller)
            ->post(route('teller.angsuran.generate-jadwal', $pembiayaan->id));

        $this->assertSame(0, Angsuran::query()->where('pembiayaan_id', $pembiayaan->id)->count(),
            'Akad yang belum dicairkan tidak boleh memperoleh jadwal angsuran.');
    }

    /**
     * T-09 — jalur SAH tetap berjalan: akad yang sudah berjalan memperoleh
     * jadwal sesuai tenor, dan permintaan kedua tidak menggandakan jadwal.
     */
    public function test_generate_jadwal_tetap_berjalan_untuk_akad_berjalan(): void
    {
        $teller = $this->user('teller', 't09');
        $pembiayaan = $this->pembiayaan(Pembiayaan::STATUS_DICAIRKAN);

        $this->actingAs($teller)
            ->post(route('teller.angsuran.generate-jadwal', $pembiayaan->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('sudah_ada', false)
            ->assertJsonPath('jumlah_jadwal', 12);

        $this->actingAs($teller)
            ->post(route('teller.angsuran.generate-jadwal', $pembiayaan->id))
            ->assertOk()
            ->assertJsonPath('sudah_ada', true)
            ->assertJsonPath('jumlah_jadwal', 12);

        $this->assertSame(12, Angsuran::query()->where('pembiayaan_id', $pembiayaan->id)->count(),
            'Permintaan berulang tidak boleh menggandakan jadwal.');
    }
}
