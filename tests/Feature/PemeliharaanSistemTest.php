<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Command pemeliharaan: cadangan & pemantauan operasional.
 *
 * Diuji tanpa menyentuh data keuangan: cadangan ditulis ke direktori
 * sementara, dan cek kesegaran cadangan cukup memakai satu berkas contoh.
 */
class PemeliharaanSistemTest extends TestCase
{
    use DatabaseTransactions;

    private string $sementara;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sementara = storage_path('framework/testing/cadangan-uji-'.uniqid());
        File::makeDirectory($this->sementara, 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sementara);
        // Sisa penanda uji pemantauan dibersihkan juga di sini agar proses
        // yang mati paksa tidak meninggalkan berkas "baru" di folder cadangan
        // asli dan menutupi cadangan yang sebenarnya sudah kedaluwarsa.
        File::delete(File::glob(storage_path('app/backups/uji-denyut-*')));
        parent::tearDown();
    }

    public function test_command_cadangan_terdaftar_dengan_opsi_retensi(): void
    {
        // Opsi dikirim sebagai array: path Windows mengandung backslash yang
        // tidak terurai dengan benar bila opsi digabung dalam satu string.
        $this->artisan('db:cadangkan', [
            '--tujuan' => $this->sementara,
            '--simpan-hari' => 3,
        ])->assertSuccessful();
    }

    public function test_command_cadangan_menghasilkan_berkas_terenkripsi_yang_dapat_dipulihkan(): void
    {
        $this->artisan('db:cadangkan', ['--tujuan' => $this->sementara])
            ->assertSuccessful();

        $berkas = File::glob($this->sementara.'/*.sql.gz.enc');

        $this->assertCount(1, $berkas, 'Cadangan harus menghasilkan tepat satu berkas .sql.gz.enc');
        $this->assertCount(0, File::glob($this->sementara.'/*.sql.gz'), 'Tidak boleh ada berkas cadangan tanpa enkripsi');

        // Berkas terenkripsi tidak boleh dapat dibaca sebagai gzip langsung.
        $this->assertFalse(@gzdecode((string) file_get_contents($berkas[0])), 'Cadangan harus terenkripsi, bukan gzip mentah');

        $terbuka = $this->sementara.'/terbuka.sql.gz';
        $this->artisan('db:buka-cadangan', ['berkas' => $berkas[0], '--tujuan' => $terbuka])
            ->assertSuccessful();

        $isi = gzfile($terbuka);

        $this->assertIsArray($isi, 'Hasil dekripsi harus berupa gzip yang valid');
        $this->assertStringContainsString('CREATE TABLE', implode("\n", $isi));
    }

    public function test_command_cadangan_menghapus_berkas_lebih_tua_dari_batas_retensi(): void
    {
        $basi = $this->sementara.'/koperasi_syariah-20200101-000000.sql.gz';
        file_put_contents($basi, gzencode('-- basi'));
        touch($basi, now()->subDays(30)->getTimestamp());

        $this->artisan('db:cadangkan', [
            '--tujuan' => $this->sementara,
            '--simpan-hari' => 14,
        ])->assertSuccessful();

        $this->assertFileDoesNotExist($basi, 'Berkas melebihi retensi harus dibersihkan');
        $this->assertCount(1, File::glob($this->sementara.'/*.sql.gz.enc'));
    }

    public function test_pemantauan_operasional_melaporkan_seluruh_indikator_sehat(): void
    {
        // Indikator cadangan membaca folder asli storage/app/backups. Tanpa
        // berkas segar di sana hasilnya bergantung pada jadwal cadangan mesin
        // sehingga test bisa merah hanya karena cadangan kemarin kedaluwarsa.
        // Berkas penanda dibuat sendiri lalu dibersihkan agar deterministik.
        $penanda = storage_path('app/backups/uji-denyut-'.uniqid().'.sql.gz');
        File::ensureDirectoryExists(dirname($penanda));
        file_put_contents($penanda, gzencode('-- penanda uji pemantauan'));

        try {
            $this->artisan('denyut:pemeriksaan')
                ->expectsOutputToContain('[OK]   Basis data')
                ->expectsOutputToContain('[OK]   Integritas jurnal')
                ->assertSuccessful();
        } finally {
            File::delete($penanda);
        }
    }

    public function test_pemantauan_gagal_bila_cadangan_kedaluwarsa(): void
    {
        $this->artisan('denyut:pemeriksaan --toleransi-cadangan-jam=0')
            ->expectsOutputToContain('indikator GAGAL')
            ->assertFailed();
    }
}
