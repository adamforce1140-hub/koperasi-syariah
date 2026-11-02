<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Buku tabungan (passbook) per rekening pada portal anggota.
 *
 * KONVENSI YANG DIKUNCI TEST INI
 * ------------------------------
 *   1. Saldo berjalan diturunkan dari `rekening.saldo` dikurangi net seluruh
 *      mutasi (Debet menambah, Kredit mengurangi) — BUKAN dari
 *      `transaksi.saldo_setelah` yang nullable.
 *   2. Baris terakhir buku tabungan harus berakhir tepat pada `rekening.saldo`.
 *   3. Anggota hanya boleh membuka rekening miliknya sendiri (403).
 *   4. Filter periode mempersempit baris DAN menyesuaikan saldo awal/akhir.
 */
class BukuTabunganAnggotaTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('transaksi') || ! Schema::hasTable('rekening')) {
            $this->markTestSkipped('Tabel inti belum tersedia.');
        }
    }

    /* =====================================================================
     | Helper
     ===================================================================== */

    private function pengguna(string $kunci, string $role = 'anggota'): User
    {
        return User::firstOrCreate(
            ['email' => "bukutabungan.{$kunci}@test.local"],
            [
                'name' => 'BukuTabungan '.ucfirst($kunci),
                'password' => Hash::make('password'),
                'role' => $role,
                'is_active' => true,
            ]
        );
    }

    private function anggota(?User $pengguna = null): Anggota
    {
        $suffix = strtoupper(substr(uniqid(), -6));

        return Anggota::create([
            'user_id' => $pengguna?->id,
            'no_anggota' => 'BT-'.$suffix,
            'nama_lengkap' => 'Anggota BukuTabungan '.$suffix,
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji BukuTabungan No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_ACTIVE,
            'tanggal_gabung' => now()->toDateString(),
        ]);
    }

    private function akunId(): int
    {
        $id = DB::table('akun')->value('id');
        if ($id) {
            return (int) $id;
        }

        return (int) DB::table('akun')->insertGetId([
            'kode_akun' => '1-1001',
            'nama_akun' => 'Kas Uji BukuTabungan',
            'tipe_akun' => 'Aset',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function rekening(Anggota $anggota, float $saldo = 0): Rekening
    {
        return Rekening::create([
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunId(),
            'no_rekening' => 'REK-BT-'.strtoupper(substr(uniqid(), -8)),
            'jenis_rekening' => 'Simpanan Sukarela',
            'status_rekening' => 'Aktif',
            'saldo' => $saldo,
            'tanggal_buka' => now()->toDateString(),
        ]);
    }

    private function transaksi(Anggota $anggota, Rekening $rekening, array $override = []): Transaksi
    {
        return Transaksi::create(array_merge([
            'no_transaksi' => 'TRX-BT-'.strtoupper(substr(uniqid(), -8)),
            'waktu_transaksi' => now(),
            'rekening_id' => $rekening->id,
            'anggota_id' => $anggota->id,
            'jenis_transaksi' => 'Setoran Tunai',
            'posisi' => 'Debet',
            'jumlah' => 100000,
            'saldo_setelah' => null,
            'sumber_transaksi' => 'Teller',
            'status_transaksi' => 'Sukses',
        ], $override));
    }

    /**
     * Rekening saldo 400.000 dengan tiga mutasi:
     *   Debet 250.000 (3 hari lalu), Debet 150.000 (2 hari lalu),
     *   Kredit 50.000 (1 hari lalu).
     * Net = 350.000 sehingga saldo awal = 50.000.
     *
     * @return array{0: User, 1: Anggota, 2: Rekening}
     */
    private function skenarioTigaMutasi(): array
    {
        $pengguna = $this->pengguna('utama');
        $anggota = $this->anggota($pengguna);
        $rekening = $this->rekening($anggota, 400000);

        $this->transaksi($anggota, $rekening, [
            'posisi' => 'Debet',
            'jenis_transaksi' => 'Setoran Tunai',
            'jumlah' => 250000,
            'waktu_transaksi' => now()->subDays(3),
            'saldo_setelah' => null,
        ]);
        $this->transaksi($anggota, $rekening, [
            'posisi' => 'Debet',
            'jenis_transaksi' => 'Setoran Tunai',
            'jumlah' => 150000,
            'waktu_transaksi' => now()->subDays(2),
            'saldo_setelah' => null,
        ]);
        $this->transaksi($anggota, $rekening, [
            'posisi' => 'Kredit',
            'jenis_transaksi' => 'Penarikan Tunai',
            'jumlah' => 50000,
            'waktu_transaksi' => now()->subDays(1),
            'saldo_setelah' => null,
        ]);

        return [$pengguna, $anggota, $rekening];
    }

    /* =====================================================================
     | 1. Otorisasi kepemilikan rekening
     ===================================================================== */

    public function test_anggota_tidak_boleh_membuka_rekening_anggota_lain(): void
    {
        $pemilik = $this->pengguna('pemilik');
        $anggotaP = $this->anggota($pemilik);
        $rekening = $this->rekening($anggotaP, 100000);

        $penyusup = $this->pengguna('penyusup');
        $this->anggota($penyusup);

        $this->actingAs($penyusup)
            ->get(route('nasabah.buku-tabungan.index', $rekening))
            ->assertForbidden();
    }

    /* =====================================================================
     | 2. Saldo awal & saldo berjalan
     ===================================================================== */

    public function test_saldo_berjalan_berakhir_tepat_pada_saldo_rekening(): void
    {
        [$pengguna, , $rekening] = $this->skenarioTigaMutasi();

        $respons = $this->actingAs($pengguna)->get(route('nasabah.buku-tabungan.index', $rekening));
        $respons->assertOk();

        $data = $respons->original->getData();

        $this->assertEquals(50000, (float) $data['saldoAwal'], 'Saldo awal = saldo rekening − net seluruh mutasi.');
        $this->assertEquals(400000, (float) $data['saldoAkhir'], 'Saldo akhir harus sama dengan saldo rekening.');
        $this->assertCount(3, $data['mutasi']);

        $mutasi = $data['mutasi'];
        $this->assertEquals(300000, (float) $mutasi[0]['saldo_akhir'], 'Baris 1: 50.000 + 250.000.');
        $this->assertEquals(450000, (float) $mutasi[1]['saldo_akhir'], 'Baris 2: 300.000 + 150.000.');
        $this->assertEquals(400000, (float) $mutasi[2]['saldo_akhir'], 'Baris 3: 450.000 − 50.000.');

        // Saldo awal tiap baris = saldo akhir baris sebelumnya.
        $this->assertEquals(50000, (float) $mutasi[0]['saldo_awal']);
        $this->assertEquals($mutasi[0]['saldo_akhir'], $mutasi[1]['saldo_awal']);
        $this->assertEquals($mutasi[1]['saldo_akhir'], $mutasi[2]['saldo_awal']);
    }

    /**
     * Regresi: `transaksi.saldo_setelah` NULL (transaksi lama/seed) TIDAK boleh
     * membuat buku tabungan menampilkan "Rp 0" atau kehilangan saldo berjalan.
     */
    public function test_saldo_setelah_null_tidak_merusak_saldo_berjalan(): void
    {
        [$pengguna, , $rekening] = $this->skenarioTigaMutasi();

        $data = $this->actingAs($pengguna)
            ->get(route('nasabah.buku-tabungan.index', $rekening))
            ->assertOk()
            ->original
            ->getData();

        foreach ($data['mutasi'] as $baris) {
            $this->assertNotEquals(0.0, (float) $baris['saldo_akhir'], 'Saldo berjalan tidak boleh 0 hanya karena saldo_setelah NULL.');
        }

        $this->assertEquals(400000, (float) $data['saldoAkhir']);
    }

    /* =====================================================================
     | 3. Filter periode
     ===================================================================== */

    public function test_filter_periode_mempersempit_baris_dan_menyesuaikan_saldo(): void
    {
        [$pengguna, , $rekening] = $this->skenarioTigaMutasi();

        $data = $this->actingAs($pengguna)
            ->get(route('nasabah.buku-tabungan.index', [
                'rekening' => $rekening->id,
                'dari' => now()->subDays(2)->toDateString(),
                'sampai' => now()->subDays(1)->toDateString(),
            ]))
            ->assertOk()
            ->original
            ->getData();

        $this->assertCount(2, $data['mutasi'], 'Hanya dua mutasi dalam rentang 2 hari terakhir.');
        $this->assertEquals(300000, (float) $data['saldoAwal'], 'Saldo awal periode = saldo sebelum mutasi pertama pada rentang.');
        $this->assertEquals(400000, (float) $data['saldoAkhir']);
        $this->assertEquals(150000, (float) $data['totalMasuk']);
        $this->assertEquals(50000, (float) $data['totalKeluar']);
    }

    /**
     * Regresi: `sampai` saja (tanpa `dari`) tidak boleh gagal validasi
     * `after_or_equal:dari`.
     */
    public function test_filter_sampai_saja_tanpa_dari_tidak_gagal_validasi(): void
    {
        [$pengguna, , $rekening] = $this->skenarioTigaMutasi();

        $data = $this->actingAs($pengguna)
            ->get(route('nasabah.buku-tabungan.index', [
                'rekening' => $rekening->id,
                'sampai' => now()->subDays(2)->toDateString(),
            ]))
            ->assertOk()
            ->original
            ->getData();

        $this->assertCount(2, $data['mutasi']);
        $this->assertEquals(450000, (float) $data['saldoAkhir'], 'Saldo akhir sampai 2 hari lalu = 450.000.');
    }

    /* =====================================================================
     | 4. Tampilan halaman buku tabungan
     ===================================================================== */

    public function test_view_menampilkan_kop_rekening_saldo_dan_tanda_plus_minus(): void
    {
        [$pengguna, , $rekening] = $this->skenarioTigaMutasi();

        $this->actingAs($pengguna)
            ->get(route('nasabah.buku-tabungan.index', $rekening))
            ->assertOk()
            ->assertSee($rekening->no_rekening)
            ->assertSee('Saldo Awal')
            ->assertSee('Saldo Akhir')
            ->assertSee('+ Rp 250.000', false)
            ->assertSee('- Rp 50.000', false)
            ->assertSee('Saldo awal periode')
            ->assertSee('Saldo akhir periode')
            ->assertSee('Cetak');
    }

    /* =====================================================================
     | 5. Pintu masuk dari halaman Simpanan Saya
     ===================================================================== */

    /**
     * Halaman Simpanan Saya harus menautkan buku tabungan dan membaca kolom
     * yang benar (`status_rekening`), bukan kolom fiktif `status`.
     */
    public function test_halaman_simpanan_menautkan_buku_tabungan_dan_status_aktif(): void
    {
        $pengguna = $this->pengguna('simpanan');
        $anggota = $this->anggota($pengguna);
        $rekening = $this->rekening($anggota, 125000);

        $this->actingAs($pengguna)
            ->get(route('nasabah.simpanan.index'))
            ->assertOk()
            ->assertSee('Buku Tabungan')
            ->assertSee(route('nasabah.buku-tabungan.index', $rekening), false)
            ->assertDontSee('Tidak Aktif')
            ->assertSee('Aktif');
    }
}
