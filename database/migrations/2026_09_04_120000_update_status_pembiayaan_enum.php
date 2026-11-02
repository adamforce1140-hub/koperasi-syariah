<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Ganti ENUM lama ke VARCHAR agar fleksibel dan tidak ada konflik duplikat
        DB::statement("
            ALTER TABLE pembiayaan
            MODIFY COLUMN status_pembiayaan VARCHAR(50) NOT NULL DEFAULT 'pending_ao'
        ");

        // Migrasi data lama ke nilai baru
        DB::table('pembiayaan')->where('status_pembiayaan', 'Pengajuan')->update(['status_pembiayaan' => 'pending_ao']);
        DB::table('pembiayaan')->where('status_pembiayaan', 'Draft')->update(['status_pembiayaan' => 'pending_ao']);
        DB::table('pembiayaan')->where('status_pembiayaan', 'Disetujui')->update(['status_pembiayaan' => 'disetujui']);
        DB::table('pembiayaan')->where('status_pembiayaan', 'Ditolak')->update(['status_pembiayaan' => 'ditolak']);
        DB::table('pembiayaan')->where('status_pembiayaan', 'Dicairkan')->update(['status_pembiayaan' => 'dicairkan']);
    }

    public function down(): void
    {
        // Kembalikan ke ENUM lama
        DB::statement("
            ALTER TABLE pembiayaan
            MODIFY COLUMN status_pembiayaan
            ENUM('Draft','Pengajuan','Disetujui','Ditolak','Dicairkan','Aktif','Lunas','Macet','Dibatalkan')
            NOT NULL DEFAULT 'Draft'
        ");
    }
};
