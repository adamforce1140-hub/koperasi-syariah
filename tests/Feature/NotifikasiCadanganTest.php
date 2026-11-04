<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\CadanganGagalNotification;
use App\Notifications\CadanganKedaluwarsaNotification;
use App\Support\CadanganTerenkripsi;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Notifikasi kegagalan cadangan (penghalang nomor 3 — cadangan offsite).
 *
 * Sebelumnya kegagalan cadangan hanya berakhir di berkas log `denyut`, yang
 * tidak pernah dibaca siapa pun. Test ini mengunci tiga hal:
 *
 *   1. `denyut:pemeriksaan` yang menemukan cadangan tidak ada atau
 *      kedaluwarsa mengirim notifikasi `database` ke pemegang
 *      `pengurus.operasional`, sehingga peringatan terlihat di dalam
 *      aplikasi, bukan hanya di berkas log.
 *   2. Notifikasi TIDAK dikirim berulang setiap jam selama kondisinya sama
 *      (pembatas yang sama dengan log `denyut`).
 *   3. `db:cadangkan --salinan-tujuan=` menyalin berkas hasil cadangan ke
 *      lokasi kedua (disk lain/USB/host cadangan). Yang disalin adalah berkas
 *      yang SUDAH terenkripsi, dan hasilnya diverifikasi dapat dipulihkan.
 */
class NotifikasiCadanganTest extends TestCase
{
    use DatabaseTransactions;

    private string $sementara;

    private string $salinan;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['notifications', 'users'] as $tabel) {
            if (! Schema::hasTable($tabel)) {
                $this->markTestSkipped("Tabel {$tabel} belum tersedia — jalankan migrasi.");
            }
        }

        $asing = uniqid();
        $this->sementara = storage_path('framework/testing/cadangan-notif-'.$asing);
        $this->salinan = storage_path('framework/testing/cadangan-salinan-'.$asing);
        File::makeDirectory($this->sementara, 0700, true);

        // Direktori cadangan diarahkan ke folder uji agar hasil pemeriksaan
        // tidak bergantung pada cadangan nyata mesin ini.
        config(['cadangan.direktori' => $this->sementara]);

        User::firstOrCreate(
            ['email' => 'notif.cadangan.pengurus@test.local'],
            ['name' => 'Pengurus Notif Cadangan', 'password' => 'password', 'role' => 'pengurus', 'is_active' => true]
        );
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sementara);
        File::deleteDirectory($this->salinan);

        parent::tearDown();
    }

    /**
     * Pengguna aktif yang wajib menerima peringatan cadangan.
     *
     * @return Collection<int, User>
     */
    private function penerima()
    {
        return User::whereIn('role', ['pengurus', 'super_admin'])->where('is_active', true)->get();
    }

    /* =====================================================================
     | NOTIFIKASI PEMANTAUAN
     ===================================================================== */

    public function test_cadangan_kedaluwarsa_memicu_notifikasi_di_dalam_aplikasi(): void
    {
        $berkas = $this->sementara.'/koperasi_syariah-20200101-010000.sql.gz.enc';
        File::put($berkas, 'cadangan-lama');
        touch($berkas, now()->subHours(48)->getTimestamp());

        Notification::fake();

        $this->artisan('denyut:pemeriksaan', ['--toleransi-cadangan-jam' => 26])
            ->assertFailed();

        $this->assertNotEmpty($this->penerima(), 'Uji membutuhkan minimal satu pengguna aktif pemegang pengurus.operasional.');

        foreach ($this->penerima() as $penerima) {
            Notification::assertSentTo(
                $penerima,
                CadanganKedaluwarsaNotification::class,
                function ($notifikasi, array $kanal) use ($berkas) {
                    $data = $notifikasi->toArray(new \stdClass);
                    $this->assertContains('database', $kanal, 'Peringatan wajib tersimpan di tabel notifications.');
                    $this->assertSame('cadangan_kedaluwarsa', $data['tipe']);
                    $this->assertSame(basename($berkas), $data['berkas']);

                    return true;
                }
            );
        }
    }

    public function test_peringatan_tidak_diulang_setiap_jam_untuk_kondisi_yang_sama(): void
    {
        // Direktori uji sengaja dibiarkan kosong → indikator gagal karena
        // tidak ada satu pun berkas cadangan.
        Notification::fake();

        $this->artisan('denyut:pemeriksaan')->assertFailed();
        $this->artisan('denyut:pemeriksaan')->assertFailed();

        foreach ($this->penerima() as $penerima) {
            Notification::assertSentToTimes($penerima, CadanganKedaluwarsaNotification::class, 1);
        }
    }

    public function test_cadangan_sehat_tidak_mengirim_peringatan(): void
    {
        File::put($this->sementara.'/koperasi_syariah-'.now()->format('Ymd-His').'.sql.gz.enc', 'cadangan-baru');

        Notification::fake();

        $this->artisan('denyut:pemeriksaan', ['--toleransi-cadangan-jam' => 26])
            ->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_kontrak_payload_notifikasi_kedaluwarsa(): void
    {
        $data = CadanganKedaluwarsaNotification::dari(
            'tidak ada berkas cadangan basis data',
            ['direktori' => 'storage/app/backups']
        )->toArray(new \stdClass);

        $this->assertSame('cadangan_kedaluwarsa', $data['tipe']);
        $this->assertSame('Cadangan Basis Data Bermasalah', $data['judul']);
        $this->assertStringContainsString('tidak ada berkas cadangan basis data', $data['pesan']);
        $this->assertArrayHasKey('url', $data);
    }

    /* =====================================================================
     | SALINAN OFFSITE
     ===================================================================== */

    public function test_kegagalan_cadangan_mengirim_notifikasi_ke_operator(): void
    {
        // Direktori tujuan tidak dapat dibuat karena jalurnya berada di bawah
        // sebuah BERKAS biasa — kegagalan paling dini yang dialami command.
        $penghalang = $this->sementara.'/berkas-biasa';
        File::put($penghalang, 'bukan-direktori');

        Notification::fake();

        $this->artisan('db:cadangkan', ['--tujuan' => $penghalang.'/cadangan'])->assertFailed();

        $this->assertNotEmpty($this->penerima(), 'Uji membutuhkan minimal satu pengguna aktif pemegang pengurus.operasional.');

        foreach ($this->penerima() as $penerima) {
            Notification::assertSentTo(
                $penerima,
                CadanganGagalNotification::class,
                function ($notifikasi) {
                    $data = $notifikasi->toArray(new \stdClass);
                    $this->assertSame('cadangan_gagal', $data['tipe']);

                    return true;
                }
            );
        }
    }

    public function test_cadangan_dapat_disalin_ke_lokasi_kedua(): void
    {
        $this->artisan('db:cadangkan', [
            '--tujuan' => $this->sementara,
            '--salinan-tujuan' => $this->salinan,
        ])->assertSuccessful();

        $lokal = File::glob($this->sementara.'/*.sql.gz.enc');
        $offsite = File::glob($this->salinan.'/*.sql.gz.enc');

        $this->assertCount(1, $lokal, 'Cadangan lokal harus tetap dibuat.');
        $this->assertCount(1, $offsite, 'Cadangan wajib punya salinan di lokasi kedua.');
        $this->assertSame(
            File::size($lokal[0]),
            File::size($offsite[0]),
            'Salinan harus identik dengan berkas aslinya.'
        );

        // Berkas salinan sudah terenkripsi; kunci yang sama harus dapat
        // membukanya, membuktikan salinan tidak rusak dalam perjalanan.
        $hasil = $this->salinan.'/pulih.sql.gz';
        CadanganTerenkripsi::dekripsiBerkas($offsite[0], $hasil);
        $this->assertFileExists($hasil);
        $this->assertGreaterThan(0, File::size($hasil));
    }

    public function test_salinan_offsite_dibaca_dari_konfigurasi(): void
    {
        // Jadwal cadangan berjalan tanpa opsi `--salinan-tujuan`, sehingga
        // `BACKUP_SALINAN_DIREKTORI` pada .env harus benar-benar dipakai.
        // Tanpa ini, kunci di .env.example tidak berefek apa pun dan cadangan
        // tetap hanya ada di satu disk.
        config(['cadangan.salinan_direktori' => $this->salinan]);

        $this->artisan('db:cadangkan', ['--tujuan' => $this->sementara])
            ->assertSuccessful();

        $this->assertCount(1, File::glob($this->salinan.'/*.sql.gz.enc'),
            'Salinan offsite dari konfigurasi tidak terbentuk.');
    }

    public function test_gagal_menyalin_offsite_membuat_command_gagal(): void
    {
        // Tujuan salinan berupa BERKAS biasa → direktori tujuan tak dapat
        // dibuat. Cadangan lokal sudah jadi, tetapi command wajib melaporkan
        // kegagalan agar operator tidak mengira salinan offsite ada.
        File::put($this->salinan, 'bukan-direktori');

        $this->artisan('db:cadangkan', [
            '--tujuan' => $this->sementara,
            '--salinan-tujuan' => $this->salinan,
        ])->assertFailed();

        $this->assertCount(1, File::glob($this->sementara.'/*.sql.gz.enc'));

        File::delete($this->salinan);
    }
}
