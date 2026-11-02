<?php

namespace Tests\Feature;

use App\Support\CadanganTerenkripsi;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/**
 * Enkripsi cadangan basis data (R6).
 *
 * Cadangan memuat jurnal, saldo, dan PII anggota. Test ini mengunci:
 *   1. Kunci diturunkan dari APP_KEY bila BACKUP_ENCRYPTION_KEY kosong.
 *   2. Kunci yang salah format ditolak, bukan dipakai diam-diam.
 *   3. Berkas bolak-balik (enkripsi → dekripsi) utuh, termasuk multi-potongan.
 *   4. Kunci berbeda dan berkas terpotong ditolak saat pemulihan.
 *   5. Enkripsi dapat dimatikan secara eksplisit (jalur darurat).
 */
class CadanganEnkripsiTest extends TestCase
{
    use DatabaseTransactions;

    private string $sementara;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sementara = storage_path('framework/testing/enkripsi-uji-'.uniqid());
        File::makeDirectory($this->sementara, 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sementara);

        config(['cadangan.kunci' => null, 'cadangan.enkripsi' => true]);

        parent::tearDown();
    }

    public function test_kunci_diturunkan_dari_app_key_bila_tidak_dikonfigurasi(): void
    {
        config(['cadangan.kunci' => null]);

        $kunci = CadanganTerenkripsi::kunci();

        $this->assertSame(32, strlen($kunci));
        $this->assertSame($kunci, CadanganTerenkripsi::kunci(), 'Turunan kunci harus deterministik');
    }

    public function test_kunci_base64_yang_salah_ditolak(): void
    {
        config(['cadangan.kunci' => base64_encode('terlalu-pendek')]);

        $this->expectException(RuntimeException::class);

        CadanganTerenkripsi::kunci();
    }

    public function test_berkas_bolak_balik_utuh_termasuk_multi_potongan(): void
    {
        $asli = $this->sementara.'/asli.sql.gz';
        // > 3 MiB data acak agar kompresi tidak mengecilkan berkas di bawah
        // ukuran potongan (1 MiB) dan jalur multi-potongan benar-benar teruji.
        file_put_contents($asli, random_bytes(3 * 1048576 + 137));

        $terenkripsi = $asli.'.enc';
        CadanganTerenkripsi::enkripsiBerkas($asli, $terenkripsi);

        $this->assertFileExists($terenkripsi);
        $this->assertNotSame(
            file_get_contents($asli),
            file_get_contents($terenkripsi),
            'Isi berkas terenkripsi tidak boleh sama dengan aslinya'
        );

        $hasil = $this->sementara.'/hasil.sql.gz';
        CadanganTerenkripsi::dekripsiBerkas($terenkripsi, $hasil);

        $this->assertSame(file_get_contents($asli), file_get_contents($hasil));
    }

    public function test_kunci_berbeda_tidak_dapat_mendekripsi(): void
    {
        $asli = $this->sementara.'/asli.sql.gz';
        file_put_contents($asli, random_bytes(2048));

        $terenkripsi = $asli.'.enc';
        CadanganTerenkripsi::enkripsiBerkas($asli, $terenkripsi);

        config(['cadangan.kunci' => base64_encode(random_bytes(32))]);

        $hasil = $this->sementara.'/hasil.sql.gz';

        try {
            CadanganTerenkripsi::dekripsiBerkas($terenkripsi, $hasil);
            $this->fail('Dekripsi dengan kunci berbeda seharusnya gagal.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('kunci enkripsi salah', $e->getMessage());
        }

        $this->assertFileDoesNotExist($hasil, 'Berkas keluaran tidak boleh tertinggal saat dekripsi gagal');
    }

    public function test_berkas_terpotong_ditolak(): void
    {
        $asli = $this->sementara.'/asli.sql.gz';
        file_put_contents($asli, random_bytes(4096));

        $terenkripsi = $asli.'.enc';
        CadanganTerenkripsi::enkripsiBerkas($asli, $terenkripsi);

        // Buang 10 byte terakhir: penanda akhir hilang.
        $isi = file_get_contents($terenkripsi);
        file_put_contents($terenkripsi, substr($isi, 0, -10));

        $this->expectException(RuntimeException::class);

        CadanganTerenkripsi::dekripsiBerkas($terenkripsi, $this->sementara.'/hasil.sql.gz');
    }

    public function test_enkripsi_dapat_dimatikan_secara_eksplisit(): void
    {
        config(['cadangan.enkripsi' => false]);

        $this->artisan('db:cadangkan', ['--tujuan' => $this->sementara])
            ->assertSuccessful();

        $berkas = File::glob($this->sementara.'/*.sql.gz');

        $this->assertCount(1, $berkas, 'Tanpa enkripsi, berkas harus tetap .sql.gz');
        $this->assertIsArray(gzfile($berkas[0]));
    }
}
