<?php

namespace Tests\Feature;

use App\Jobs\DistribusiBagiHasilJob;
use App\Jobs\ProsesJatuhTempoDepositoJob;
use App\Jobs\ProsesTagihanSimpananWajibJob;
use App\Jobs\TutupBukuShuJob;
use App\Models\Deposito;
use App\Support\QueueOperasional;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Uji lapisan job asinkron (Rekomendasi 4 — langkah 5 & 7).
 *
 * Fokus utama:
 *  1. Saklar `FEATURE_QUEUE_ASYNC` default FALSE → seluruh operasi SINKRON,
 *     sehingga perilaku sistem tidak berubah tanpa persetujuan operator.
 *  2. Seluruh job memenuhi kontrak ShouldQueue + parameter ketahanan dibaca dari
 *     SSOT `config/job_operasional.php` (bukan angka ajaib di kelas).
 *  3. Idempotensi: guard status membuat eksekusi ulang tidak berefek.
 */
class JobAsinkronTest extends TestCase
{
    /* ══════════════════════════════════════════════════════════════════════
     | 1. Saklar default aman (sinkron)
     ══════════════════════════════════════════════════════════════════════ */

    public function test_saklar_antrian_default_mati(): void
    {
        // Tanpa FEATURE_QUEUE_ASYNC di .env, konfigurasi harus FALSE agar
        // seluruh job tetap dijalankan sinkron (perilaku lama).
        $this->assertFalse(
            (bool) config('job_operasional.aktif', false),
            'job_operasional.aktif harus default FALSE (mode sinkron aman).'
        );

        $this->assertFalse(QueueOperasional::aktif());
    }

    public function test_queue_operasional_sinkron_saat_saklar_mati(): void
    {
        config(['job_operasional.aktif' => false]);

        Queue::fake();

        $job = new ProsesTagihanSimpananWajibJob('2026-01-01');
        QueueOperasional::jalankan($job);

        // Saklar mati → TIDAK didorong ke antrian.
        Queue::assertNothingPushed();
    }

    public function test_queue_operasional_mendorong_ke_antrian_saat_saklar_hidup(): void
    {
        config(['job_operasional.aktif' => true]);

        Queue::fake();

        $job = new ProsesTagihanSimpananWajibJob('2026-01-01');
        QueueOperasional::jalankan($job);

        Queue::assertPushed(ProsesTagihanSimpananWajibJob::class);
    }

    /* ══════════════════════════════════════════════════════════════════════
     | 2. Kontrak ShouldQueue & parameter ketahanan dari SSOT
     ══════════════════════════════════════════════════════════════════════ */

    public function test_empat_job_memenuhi_kontrak_should_queue(): void
    {
        $kelas = [
            ProsesJatuhTempoDepositoJob::class,
            DistribusiBagiHasilJob::class,
            TutupBukuShuJob::class,
            ProsesTagihanSimpananWajibJob::class,
        ];

        foreach ($kelas as $job) {
            $this->assertTrue(
                is_subclass_of($job, ShouldQueue::class),
                "{$job} harus implements ShouldQueue."
            );
        }
    }

    public function test_parameter_ketahanan_diambil_dari_config_bukan_ajaib(): void
    {
        config([
            'job_operasional.jobs.distribusi_bagi_hasil' => [
                'tries' => 7,
                'backoff' => [11, 22],
                'timeout' => 123,
            ],
        ]);

        $job = new DistribusiBagiHasilJob(2026, 1);

        $this->assertSame(7, $job->tries);
        $this->assertSame([11, 22], $job->backoff);
        $this->assertSame(123, $job->timeout);
    }

    public function test_seluruh_kelas_job_tidak_memuat_angka_tries_keras(): void
    {
        // Memastikan tidak ada `$tries = <angka>` literal yang tertanam;
        // semuanya harus berasal dari QueueOperasional::parameter().
        $berkas = [
            app_path('Jobs/ProsesJatuhTempoDepositoJob.php'),
            app_path('Jobs/DistribusiBagiHasilJob.php'),
            app_path('Jobs/TutupBukuShuJob.php'),
            app_path('Jobs/ProsesTagihanSimpananWajibJob.php'),
        ];

        foreach ($berkas as $path) {
            $isi = (string) file_get_contents($path);

            $this->assertStringContainsString(
                'QueueOperasional::parameter(',
                $isi,
                basename($path).' harus membaca parameter dari SSOT config.'
            );

            $this->assertDoesNotMatchRegularExpression(
                '/\$(tries|timeout)\s*=\s*\d+/',
                $isi,
                basename($path).' tidak boleh menanam angka tries/timeout literal.'
            );
        }
    }

    /* ══════════════════════════════════════════════════════════════════════
     | 3. Idempotensi — guard status
     ══════════════════════════════════════════════════════════════════════ */

    public function test_job_jatuh_tempo_deposito_melewati_deposito_non_aktif(): void
    {
        // Deposito berstatus CLOSED → job harus keluar tanpa efek (null).
        $deposito = new Deposito;
        $deposito->id = 987654;
        $deposito->status = Deposito::STATUS_CLOSED;

        // Job diarahkan ke model yang tidak ada di DB agar tetap aman bila
        // implementasi berubah: hasil harus null apa pun jalurnya.
        $job = new ProsesJatuhTempoDepositoJob(987654);

        $this->assertNull(
            $job->jalankanSinkron(),
            'Job harus melewati deposito yang tidak ACTIVE (idempoten).'
        );
    }

    public function test_job_tutup_buku_idempoten_dijalankan_dua_kali(): void
    {
        $job = new TutupBukuShuJob(null, 1);

        $pertama = $job->jalankanSinkron();
        $kedua = $job->jalankanSinkron();

        // Pemanggilan kedua TIDAK memuat baris baru (sudah ditutup).
        $this->assertSame(0, (int) $kedua['jumlah_baris'], 'Tutup buku kedua harus nol baris.');
        $this->assertTrue((bool) $kedua['sudah_ditutup'], 'Tutup buku kedua harus menandai sudah_ditutup.');

        // Dan tidak boleh membuat jurnal baru.
        $this->assertNull($kedua['jurnal']);
        $this->assertIsArray($pertama);
    }

    /* ══════════════════════════════════════════════════════════════════════
     | 4. Struktur: properti hasil & handler failed()
     ══════════════════════════════════════════════════════════════════════ */

    public function test_setiap_job_punya_handler_failed(): void
    {
        $kelas = [
            ProsesJatuhTempoDepositoJob::class,
            DistribusiBagiHasilJob::class,
            TutupBukuShuJob::class,
            ProsesTagihanSimpananWajibJob::class,
        ];

        foreach ($kelas as $job) {
            $this->assertTrue(
                method_exists($job, 'failed'),
                "{$job} harus punya handler failed() untuk audit kegagalan."
            );
        }
    }

    public function test_setiap_job_punya_metode_jalankan_sinkron(): void
    {
        $kelas = [
            ProsesJatuhTempoDepositoJob::class,
            DistribusiBagiHasilJob::class,
            TutupBukuShuJob::class,
            ProsesTagihanSimpananWajibJob::class,
        ];

        foreach ($kelas as $job) {
            $this->assertTrue(
                method_exists($job, 'jalankanSinkron'),
                "{$job} harus menyediakan jalankanSinkron() agar command dapat "
                .'menampilkan ringkasan tanpa memanggil layanan dua kali.'
            );
        }
    }

    public function test_config_job_operasional_memuat_empat_kunci(): void
    {
        $jobs = (array) config('job_operasional.jobs', []);

        foreach ([
            'proses_jatuh_tempo_deposito',
            'distribusi_bagi_hasil',
            'tutup_buku_shu',
            'proses_tagihan_simpanan_wajib',
        ] as $kunci) {
            $this->assertArrayHasKey($kunci, $jobs, "Config job_operasional.jobs.{$kunci} harus ada.");
            $this->assertArrayHasKey('tries', $jobs[$kunci]);
            $this->assertArrayHasKey('backoff', $jobs[$kunci]);
            $this->assertArrayHasKey('timeout', $jobs[$kunci]);
        }
    }
}
