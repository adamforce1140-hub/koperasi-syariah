<?php

namespace Tests\Feature;

use App\Models\Angsuran;
use App\Models\Pembiayaan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * REGRESI #31 — filter halaman `pengurus.angsuran.index`.
 *
 * Sebelumnya filter `?status=terlambat|belum` memakai kolom
 * `angsuran.status_angsuran`, sedangkan kartu statistik di halaman yang sama
 * memakai tanggal sungguhan. Akibatnya tabel dan kartu bisa saling
 * bertentangan, dan angsuran yang sudah dibayar (status basi) tetap muncul
 * sebagai tunggakan.
 *
 * Sekarang keduanya memakai acuan yang sama: `tanggal_bayar` (null = belum
 * dibayar) + `tanggal_jatuh_tempo`. Test ini mengunci perilaku itu, termasuk
 * kasus baris BERSTATUS BASI yang tidak boleh lolos ke filter tunggakan.
 *
 * Catatan wewenang: rute `pengurus.angsuran.index` dijaga izin
 * `angsuran.setoran` (teller & super_admin) sesuai segregation of duties —
 * karena itu aktor pengujian di sini adalah TELLER, bukan peran pengurus.
 */
class FilterAngsuranPengurusTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    private User $teller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'FLT'.strtoupper(substr(uniqid(), -6));

        $this->teller = User::firstOrCreate(
            ['email' => 'teller.filter.uji@test.local'],
            [
                'name' => 'Teller Filter Uji',
                'password' => Hash::make('password'),
                'role' => 'teller',
                'is_active' => true,
            ]
        );

        $this->teller->forceFill([
            'password' => Hash::make('password'),
            'role' => 'teller',
            'is_active' => true,
        ])->save();
    }

    /**
     * Buat pembiayaan + jadwal angsuran.
     *
     * @param  array<int, array{tanggal: string, status: string, dibayar?: bool}>  $jadwal
     */
    private function pembiayaan(string $label, string $status, array $jadwal): Pembiayaan
    {
        $anggotaId = DB::table('anggota')->insertGetId([
            'no_anggota' => $this->prefix.'-'.strtoupper(substr(uniqid(), -6)),
            'nik' => 'NIK'.strtoupper(substr(uniqid(), -10)),
            'nama_lengkap' => $this->prefix.' '.$label,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jalan Uji No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => 'Aktif',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => $this->prefix.'-'.strtoupper(substr(uniqid(), -8)),
            'anggota_id' => $anggotaId,
            'akun_id' => DB::table('akun')->value('id'),
            'akad_pembiayaan' => 'Murabahah',
            'jumlah_pembiayaan' => 1200000,
            'total_pembiayaan' => 1320000,
            'tenor' => 12,
            'jumlah_angsuran' => 110000,
            'tanggal_akad' => now()->subMonths(6)->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths(6)->toDateString(),
            'sisa_pembiayaan' => 1200000,
            'status_pembiayaan' => $status,
        ]);

        foreach ($jadwal as $i => $baris) {
            $sudahDibayar = $baris['dibayar'] ?? $baris['status'] === 'Sudah Dibayar';

            DB::table('angsuran')->insert([
                'pembiayaan_id' => $pembiayaan->id,
                'angsuran_ke' => $i + 1,
                'tanggal_jatuh_tempo' => $baris['tanggal'],
                'tanggal_bayar' => $sudahDibayar ? now()->toDateString() : null,
                'jumlah_angsuran' => 110000,
                'total_bayar' => $sudahDibayar ? 110000 : 0,
                'sisa_pokok' => 100000,
                'status_angsuran' => $baris['status'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $pembiayaan;
    }

    /** Id pembiayaan pada halaman hasil filter (lintas halaman). */
    private function idDalamHasil(?string $status): array
    {
        $url = route('pengurus.angsuran.index').'?'.http_build_query(array_filter([
            'status' => $status,
        ]));

        $view = $this->actingAs($this->teller)->get($url)->assertOk()->viewData('pembiayaan');

        return $view->pluck('id')->all();
    }

    public function test_filter_terlambat_memakai_tanggal_dan_mengabaikan_status_basi(): void
    {
        $terlambat = $this->pembiayaan('TERLAMBAT', Pembiayaan::STATUS_AKTIF, [
            ['tanggal' => now()->subDays(10)->toDateString(), 'status' => 'Terlambat'],
        ]);

        // Status basi: tertulis 'Terlambat' tapi tanggal_bayar terisi (sudah dibayar).
        $basi = $this->pembiayaan('BASI', Pembiayaan::STATUS_AKTIF, [
            ['tanggal' => now()->subDays(20)->toDateString(), 'status' => 'Terlambat', 'dibayar' => true],
        ]);

        $belum = $this->pembiayaan('BELUM', Pembiayaan::STATUS_AKTIF, [
            ['tanggal' => now()->addDays(10)->toDateString(), 'status' => 'Belum Jatuh Tempo'],
        ]);

        $hasil = $this->idDalamHasil('terlambat');

        $this->assertContains($terlambat->id, $hasil, 'Angsuran lewat JT & belum dibayar wajib muncul.');
        $this->assertNotContains($basi->id, $hasil, 'Baris basi yang sudah dibayar tidak boleh dianggap tunggakan.');
        $this->assertNotContains($belum->id, $hasil, 'Angsuran yang JT-nya masih depan bukan tunggakan.');
    }

    public function test_filter_belum_memakai_tanggal(): void
    {
        $belum = $this->pembiayaan('BELUMSAJA', Pembiayaan::STATUS_AKTIF, [
            ['tanggal' => now()->addDays(10)->toDateString(), 'status' => 'Belum Jatuh Tempo'],
        ]);

        // Status basi: tertulis 'Belum Jatuh Tempo' tapi JT sudah lewat.
        $basi = $this->pembiayaan('BASIBELUM', Pembiayaan::STATUS_AKTIF, [
            ['tanggal' => now()->subDays(5)->toDateString(), 'status' => 'Belum Jatuh Tempo'],
        ]);

        $terlambat = $this->pembiayaan('TERLAMBAT2', Pembiayaan::STATUS_AKTIF, [
            ['tanggal' => now()->subDays(5)->toDateString(), 'status' => 'Terlambat'],
        ]);

        $hasil = $this->idDalamHasil('belum');

        $this->assertContains($belum->id, $hasil);
        $this->assertNotContains($basi->id, $hasil, 'JT sudah lewat — bukan "belum".');
        $this->assertNotContains($terlambat->id, $hasil);
    }

    public function test_kartu_statistik_sejalan_dengan_filter(): void
    {
        $terlambat = $this->pembiayaan('KARTUTERLAMBAT', Pembiayaan::STATUS_AKTIF, [
            ['tanggal' => now()->subDays(3)->toDateString(), 'status' => 'Jatuh Tempo'],
        ]);
        $belum = $this->pembiayaan('KARTUBELUM', Pembiayaan::STATUS_AKTIF, [
            ['tanggal' => now()->addDays(3)->toDateString(), 'status' => 'Jatuh Tempo'],
        ]);

        $response = $this->actingAs($this->teller)
            ->get(route('pengurus.angsuran.index'))
            ->assertOk();

        $terlambatGlobal = $response->viewData('totalAngsuranTerlambat');
        $belumGlobal = $response->viewData('totalAngsuranBelum');

        // Kartu = agregat global; filter = daftar kontrak. Yang diuji: kontrak
        // yang muncul di filter ikut terhitung pada kartu yang sama acuannya.
        $this->assertContains($terlambat->id, $this->idDalamHasil('terlambat'));
        $this->assertContains($belum->id, $this->idDalamHasil('belum'));

        $hitungTerlambat = Angsuran::whereNull('tanggal_bayar')
            ->where('tanggal_jatuh_tempo', '<', now()->toDateString())->count();
        $hitungBelum = Angsuran::whereNull('tanggal_bayar')
            ->where('tanggal_jatuh_tempo', '>=', now()->toDateString())->count();

        $this->assertSame($hitungTerlambat, $terlambatGlobal);
        $this->assertSame($hitungBelum, $belumGlobal);
    }
}
