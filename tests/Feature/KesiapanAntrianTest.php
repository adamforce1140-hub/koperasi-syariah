<?php

namespace Tests\Feature;

use App\Support\QueueOperasional;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Kesiapan antrian basis data (Rekomendasi 4 — prasyarat produksi).
 *
 * Menyalakan `QUEUE_CONNECTION=database` + `FEATURE_QUEUE_ASYNC=true` hanya
 * mungkin bila tabel `jobs`/`failed_jobs` benar-benar ada. Sebelum migrasi
 * ini dipasang, setiap job yang didorong gagal dengan
 * `Table 'jobs' doesn't exist`, dan job yang gagal permanen tidak terekam.
 *
 * Test ini mengunci empat hal:
 *  1. Tabel antrian tersedia beserta kolom yang dibutuhkan worker.
 *  2. Job benar-benar MASUK tabel `jobs` (bukan dieksekusi inline) saat
 *     saklar antrian dinyalakan.
 *  3. Worker `queue:work` dapat mengambil dan mengeksekusi job tersebut.
 *  4. `failed_jobs` menyimpan jejak kegagalan (exception + waktu).
 */
class KesiapanAntrianTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        config(['job_operasional.aktif' => false, 'queue.default' => 'sync']);

        JobUjiAntrian::$dijalankan = 0;

        parent::tearDown();
    }

    public function test_tabel_antrian_tersedia(): void
    {
        $this->assertTrue(Schema::hasTable('jobs'), 'Tabel `jobs` wajib ada agar antrian basis data dapat dipakai.');
        $this->assertTrue(Schema::hasTable('failed_jobs'), 'Tabel `failed_jobs` wajib ada agar kegagalan job terekam.');
        $this->assertTrue(Schema::hasTable('job_batches'), 'Tabel `job_batches` wajib ada untuk job batch.');
    }

    public function test_kolom_tabel_jobs_sesuai_kebutuhan_worker(): void
    {
        foreach ([
            'id', 'queue', 'payload', 'attempts',
            'reserved_at', 'available_at', 'created_at',
        ] as $kolom) {
            $this->assertTrue(
                Schema::hasColumn('jobs', $kolom),
                "Kolom `jobs.{$kolom}` wajib ada."
            );
        }
    }

    public function test_kolom_tabel_failed_jobs_memuat_jejak_kegagalan(): void
    {
        foreach ([
            'id', 'uuid', 'connection', 'queue',
            'payload', 'exception', 'failed_at',
        ] as $kolom) {
            $this->assertTrue(
                Schema::hasColumn('failed_jobs', $kolom),
                "Kolom `failed_jobs.{$kolom}` wajib ada."
            );
        }
    }

    public function test_job_masuk_tabel_jobs_saat_saklar_antrian_dinyalakan(): void
    {
        config(['queue.default' => 'database', 'job_operasional.aktif' => true]);

        DB::table('jobs')->delete();

        QueueOperasional::jalankan(new JobUjiAntrian);

        $baris = DB::table('jobs')->get();

        $this->assertCount(1, $baris, 'Job harus tersimpan sebagai baris pada tabel `jobs`.');
        $this->assertSame('default', $baris->first()->queue);
        $this->assertSame(0, (int) $baris->first()->attempts);
        // Payload JSON meng-escape garis miring balik, sehingga nama kelas
        // hadir sebagai `Tests\\Feature\\JobUjiAntrian`.
        $this->assertStringContainsString(
            addslashes(JobUjiAntrian::class),
            $baris->first()->payload
        );
        $this->assertSame(0, JobUjiAntrian::$dijalankan, 'Job TIDAK boleh dieksekusi inline saat antrian aktif.');
    }

    public function test_worker_mengeksekusi_job_yang_menunggu_di_tabel_jobs(): void
    {
        config(['queue.default' => 'database', 'job_operasional.aktif' => true]);

        DB::table('jobs')->delete();

        QueueOperasional::jalankan(new JobUjiAntrian);

        $this->assertSame(0, JobUjiAntrian::$dijalankan, 'Prasyarat: job belum dijalankan sebelum worker bekerja.');

        Artisan::call('queue:work', ['--once' => true, '--queue' => 'default']);

        $this->assertSame(1, JobUjiAntrian::$dijalankan, 'Worker wajib mengeksekusi job dari tabel `jobs`.');
        $this->assertSame(0, DB::table('jobs')->count(), 'Job yang selesai wajib dihapus dari tabel `jobs`.');
    }
}

/**
 * Job ringan khusus pengujian lapisan antrian: tidak menyentuh basis data,
 * hanya menaikkan penghitung agar dapat dibuktikan kapan dieksekusi.
 */
class JobUjiAntrian implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public static int $dijalankan = 0;

    public function handle(): void
    {
        static::$dijalankan++;
    }
}
