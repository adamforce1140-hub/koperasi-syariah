<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Ubah ENUM ke VARCHAR agar menerima teks bebas dari nasabah
        DB::statement("ALTER TABLE pembiayaan MODIFY COLUMN tujuan_pembiayaan VARCHAR(100) NOT NULL DEFAULT 'Lainnya'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE pembiayaan MODIFY COLUMN tujuan_pembiayaan ENUM('Modal Usaha','Konsumsi','Investasi','Pendidikan','Kesehatan','Perumahan','Kendaraan','Lainnya') NOT NULL DEFAULT 'Lainnya'");
    }
};
