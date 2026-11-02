<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Ubah jenis_transaksi dan status_transaksi dari ENUM ke VARCHAR agar fleksibel
        DB::statement('ALTER TABLE transaksi MODIFY COLUMN jenis_transaksi VARCHAR(50) NOT NULL');
        DB::statement("ALTER TABLE transaksi MODIFY COLUMN status_transaksi VARCHAR(30) NOT NULL DEFAULT 'Sukses'");
        DB::statement('ALTER TABLE transaksi MODIFY COLUMN posisi VARCHAR(10) NOT NULL');
        DB::statement("ALTER TABLE transaksi MODIFY COLUMN sumber_transaksi VARCHAR(20) NOT NULL DEFAULT 'Teller'");

        Schema::table('transaksi', function (Blueprint $table) {
            // 2. Tambah kolom yang dipakai service tapi belum ada di tabel
            if (! Schema::hasColumn('transaksi', 'nominal')) {
                $table->decimal('nominal', 15, 2)->nullable()->after('jumlah');
            }
            if (! Schema::hasColumn('transaksi', 'jenis_pembayaran')) {
                $table->string('jenis_pembayaran', 20)->nullable()->after('nominal');
            }
            if (! Schema::hasColumn('transaksi', 'approval_id')) {
                $table->unsignedBigInteger('approval_id')->nullable()->after('jenis_pembayaran');
            }
            if (! Schema::hasColumn('transaksi', 'dibuat_oleh')) {
                $table->unsignedBigInteger('dibuat_oleh')->nullable()->after('approval_id');
            }
            if (! Schema::hasColumn('transaksi', 'waktu_transaksi')) {
                $table->timestamp('waktu_transaksi')->nullable()->after('dibuat_oleh');
            }
            if (! Schema::hasColumn('transaksi', 'no_transaksi')) {
                $table->string('no_transaksi', 50)->nullable()->after('id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transaksi', function (Blueprint $table) {
            $table->dropColumn(['nominal', 'jenis_pembayaran', 'approval_id', 'dibuat_oleh', 'waktu_transaksi', 'no_transaksi']);
        });
    }
};
