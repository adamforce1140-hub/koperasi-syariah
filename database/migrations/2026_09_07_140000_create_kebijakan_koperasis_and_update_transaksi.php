<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Buat tabel kebijakan_koperasis jika belum ada
        if (! Schema::hasTable('kebijakan_koperasis')) {
            Schema::create('kebijakan_koperasis', function (Blueprint $table) {
                $table->id();
                $table->decimal('limit_penarikan_teller', 15, 2)->default(10000000.00);
                $table->decimal('limit_setoran_teller', 15, 2)->default(50000000.00);
                $table->decimal('limit_pembiayaan_manajer', 15, 2)->default(50000000.00);
                $table->string('nama_koperasi')->default('Koperasi Syariah');
                $table->timestamps();
            });
        }

        // 2. Tambahkan kolom status, approved_by, alasan_penolakan, is_large_transaction di tabel transaksi jika belum ada
        Schema::table('transaksi', function (Blueprint $table) {
            if (! Schema::hasColumn('transaksi', 'status')) {
                $table->string('status', 30)->default('success')->after('status_transaksi');
            }
            if (! Schema::hasColumn('transaksi', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete()->after('user_id');
            }
            if (! Schema::hasColumn('transaksi', 'alasan_penolakan')) {
                $table->text('alasan_penolakan')->nullable()->after('catatan');
            }
            if (! Schema::hasColumn('transaksi', 'is_large_transaction')) {
                $table->boolean('is_large_transaction')->default(false)->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transaksi', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['status', 'approved_by', 'alasan_penolakan', 'is_large_transaction']);
        });

        Schema::dropIfExists('kebijakan_koperasis');
    }
};
