<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tambah kolom-kolom yang mungkin belum ada di tabel angsuran
        Schema::table('angsuran', function (Blueprint $table) {
            if (! Schema::hasColumn('angsuran', 'pembiayaan_id')) {
                $table->unsignedBigInteger('pembiayaan_id')->after('id');
            }
            if (! Schema::hasColumn('angsuran', 'transaksi_id')) {
                $table->unsignedBigInteger('transaksi_id')->nullable()->after('pembiayaan_id');
            }
            if (! Schema::hasColumn('angsuran', 'angsuran_ke')) {
                $table->integer('angsuran_ke')->default(1)->after('transaksi_id');
            }
            if (! Schema::hasColumn('angsuran', 'tanggal_jatuh_tempo')) {
                $table->date('tanggal_jatuh_tempo')->nullable()->after('angsuran_ke');
            }
            if (! Schema::hasColumn('angsuran', 'tanggal_bayar')) {
                $table->date('tanggal_bayar')->nullable()->after('tanggal_jatuh_tempo');
            }
            if (! Schema::hasColumn('angsuran', 'pokok_angsuran')) {
                $table->decimal('pokok_angsuran', 15, 2)->default(0)->after('tanggal_bayar');
            }
            if (! Schema::hasColumn('angsuran', 'margin_ujroh')) {
                $table->decimal('margin_ujroh', 15, 2)->default(0)->after('pokok_angsuran');
            }
            if (! Schema::hasColumn('angsuran', 'jumlah_angsuran')) {
                $table->decimal('jumlah_angsuran', 15, 2)->default(0)->after('margin_ujroh');
            }
            if (! Schema::hasColumn('angsuran', 'denda')) {
                $table->decimal('denda', 15, 2)->default(0)->after('jumlah_angsuran');
            }
            if (! Schema::hasColumn('angsuran', 'total_bayar')) {
                $table->decimal('total_bayar', 15, 2)->default(0)->after('denda');
            }
            if (! Schema::hasColumn('angsuran', 'sisa_pokok')) {
                $table->decimal('sisa_pokok', 15, 2)->default(0)->after('total_bayar');
            }
            if (! Schema::hasColumn('angsuran', 'status_angsuran')) {
                $table->enum('status_angsuran', [
                    'Belum Jatuh Tempo', 'Jatuh Tempo', 'Terlambat', 'Sudah Dibayar', 'Lewat',
                ])->default('Belum Jatuh Tempo')->after('sisa_pokok');
            }
            if (! Schema::hasColumn('angsuran', 'hari_terlambat')) {
                $table->integer('hari_terlambat')->default(0)->after('status_angsuran');
            }
            if (! Schema::hasColumn('angsuran', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('hari_terlambat');
            }
            if (! Schema::hasColumn('angsuran', 'catatan')) {
                $table->text('catatan')->nullable()->after('user_id');
            }
            if (! Schema::hasColumn('angsuran', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // Tambah foreign key hanya jika belum ada
        $fkExists = collect(DB::select("
            SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'angsuran'
              AND CONSTRAINT_NAME = 'angsuran_pembiayaan_id_foreign'
              AND CONSTRAINT_TYPE = 'FOREIGN KEY'
        "))->isNotEmpty();

        if (! $fkExists && Schema::hasColumn('angsuran', 'pembiayaan_id')) {
            Schema::table('angsuran', function (Blueprint $table) {
                $table->foreign('pembiayaan_id')
                    ->references('id')->on('pembiayaan')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Tidak drop tabel, hanya hapus kolom yang ditambahkan
        Schema::table('angsuran', function (Blueprint $table) {
            $cols = ['pembiayaan_id', 'transaksi_id', 'angsuran_ke', 'tanggal_jatuh_tempo',
                'tanggal_bayar', 'pokok_angsuran', 'margin_ujroh', 'jumlah_angsuran',
                'denda', 'total_bayar', 'sisa_pokok', 'status_angsuran',
                'hari_terlambat', 'user_id', 'catatan', 'deleted_at'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('angsuran', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
