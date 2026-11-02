<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambahkan status siklus hidup sesi Buka Kas Teller.
 *
 * OPEN              = kas sudah dibuka, teller masih bertransaksi.
 * PENDING_APPROVAL  = teller sudah submit opname, menunggu Manajer.
 * CLOSED            = Manajer sudah menerima kas & jurnal terbentuk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kas_harian', function (Blueprint $table) {
            if (! Schema::hasColumn('kas_harian', 'status_opname')) {
                $table->string('status_opname', 20)->default('OPEN')->after('catatan_opening');
                $table->index('status_opname');
            }
        });
    }

    public function down(): void
    {
        Schema::table('kas_harian', function (Blueprint $table) {
            if (Schema::hasColumn('kas_harian', 'status_opname')) {
                $table->dropIndex(['status_opname']);
                $table->dropColumn('status_opname');
            }
        });
    }
};
