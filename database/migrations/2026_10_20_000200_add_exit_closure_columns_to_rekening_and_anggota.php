<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * JEJAK PENUTUPAN REKENING & CIF untuk modul Member Exit & Closure.
 *
 * - `rekening.closed_at` / `rekening.closed_by`: timestamp & operator yang
 *   menutup setiap rekening simpanan (Pokok, Wajib, Sukarela, Berjangka).
 *   Status rekening memakai nilai `Ditutup` yang SUDAH ada pada enum
 *   `status_rekening` — tidak ada perubahan enum.
 * - `anggota.exit_date` / `exit_reason` / `exit_approved_by`: field eksplisit
 *   sesuai spesifikasi CIF. Status keanggotaan tetap memakai nilai kanonik
 *   `TERMINATED` (padanan RESIGNED) agar seluruh modul existing tetap membaca
 *   status yang sama.
 * - `pembiayaan_penjamin`: daftar penjamin (guarantor) per pembiayaan. Tabel
 *   ini belum ada di sistem, sehingga pengecekan penjamin pada modul exit
 *   memerlukan skema minimal ini. Tanpa data penjamin, pengecekan otomatis
 *   lolos (tidak memblokir exit).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rekening')) {
            Schema::table('rekening', function (Blueprint $table) {
                if (! Schema::hasColumn('rekening', 'closed_at')) {
                    $table->timestamp('closed_at')->nullable()->after('status_rekening')
                        ->comment('Waktu rekening ditutup karena exit keanggotaan.');
                }

                if (! Schema::hasColumn('rekening', 'closed_by')) {
                    $table->foreignId('closed_by')->nullable()->after('closed_at')
                        ->constrained('users')->nullOnDelete()
                        ->comment('User operator yang menutup rekening.');
                }
            });
        }

        if (Schema::hasTable('anggota')) {
            Schema::table('anggota', function (Blueprint $table) {
                if (! Schema::hasColumn('anggota', 'exit_date')) {
                    $table->date('exit_date')->nullable()->after('tanggal_terminasi')
                        ->comment('Tanggal resmi pengunduran diri anggota.');
                }

                if (! Schema::hasColumn('anggota', 'exit_reason')) {
                    $table->text('exit_reason')->nullable()->after('exit_date')
                        ->comment('Alasan pengunduran diri yang dicatat operator.');
                }

                if (! Schema::hasColumn('anggota', 'exit_approved_by')) {
                    $table->foreignId('exit_approved_by')->nullable()->after('exit_reason')
                        ->constrained('users')->nullOnDelete()
                        ->comment('Manajer yang menyetujui exit (bila melewati limit).');
                }
            });
        }

        if (! Schema::hasTable('pembiayaan_penjamin')) {
            Schema::create('pembiayaan_penjamin', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pembiayaan_id')->constrained('pembiayaan')->cascadeOnDelete();
                $table->foreignId('anggota_id')->constrained('anggota')->cascadeOnDelete();
                $table->string('hubungan', 50)->nullable();
                $table->text('keterangan')->nullable();
                $table->timestamps();

                $table->unique(['pembiayaan_id', 'anggota_id'], 'pembiayaan_penjamin_unik');
                $table->index('anggota_id', 'pembiayaan_penjamin_anggota_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pembiayaan_penjamin');

        if (Schema::hasTable('anggota')) {
            Schema::table('anggota', function (Blueprint $table) {
                foreach (['exit_approved_by', 'exit_reason', 'exit_date'] as $kolom) {
                    if (Schema::hasColumn('anggota', $kolom)) {
                        $table->dropColumn($kolom);
                    }
                }
            });
        }

        if (Schema::hasTable('rekening')) {
            Schema::table('rekening', function (Blueprint $table) {
                foreach (['closed_by', 'closed_at'] as $kolom) {
                    if (Schema::hasColumn('rekening', $kolom)) {
                        $table->dropColumn($kolom);
                    }
                }
            });
        }
    }
};
