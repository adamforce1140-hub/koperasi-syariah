<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel antrian basis data (`jobs`, `job_batches`, `failed_jobs`).
 *
 * MENGAPA MIGRASI INI ADA
 * -----------------------
 * `config/queue.php` menyediakan koneksi `database`, tetapi tabel-tabelnya
 * tidak pernah dibuat (perintah `queue:table` tidak dijalankan). Akibatnya
 * menyalakan `QUEUE_CONNECTION=database` — sesuai petunjuk di
 * `config/job_operasional.php` — langsung gagal dengan
 * `Table 'jobs' doesn't exist` setiap kali sebuah job didorong, dan job yang
 * gagal permanen tidak terekam di `failed_jobs` sehingga tidak dapat
 * diperiksa maupun di-retry.
 *
 * Skema mengikuti migrasi bawaan Laravel 12 (termasuk indeks
 * `queue, reserved_at` untuk kecepatan worker memesan job).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');

                $table->index(['queue', 'reserved_at']);
            });
        }

        if (! Schema::hasTable('job_batches')) {
            Schema::create('job_batches', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });
        }

        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();

                // Perbedaan dari skema bawaan Laravel: `connection` dan `queue`
                // berupa VARCHAR, bukan TEXT. MySQL 8.4 menolak TEXT di dalam
                // indeks tanpa panjang kunci (galat 1170), sedangkan indeks
                // gabungan di bawah justru dibutuhkan agar `queue:failed` dan
                // penyaringan per koneksi tetap cepat.
                $table->string('connection');
                $table->string('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();

                $table->index(['connection', 'queue', 'failed_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('jobs');
    }
};
