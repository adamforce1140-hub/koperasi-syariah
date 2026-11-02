<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Self-healing migration untuk memperbarui anggota_id pada baris transaksi angsuran / lama
     * yang anggota_id nya masih NULL berdasarkan relasi pembiayaan_id atau rekening_id.
     */
    public function up(): void
    {
        // 1. Healing dari relasi pembiayaan
        DB::statement('
            UPDATE transaksi t
            INNER JOIN pembiayaan p ON t.pembiayaan_id = p.id
            SET t.anggota_id = p.anggota_id
            WHERE t.anggota_id IS NULL AND p.anggota_id IS NOT NULL
        ');

        // 2. Healing dari relasi rekening
        DB::statement('
            UPDATE transaksi t
            INNER JOIN rekening r ON t.rekening_id = r.id
            SET t.anggota_id = r.anggota_id
            WHERE t.anggota_id IS NULL AND r.anggota_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        // Self-healing migration bersifat data fix, tidak perlu rollback destruktif.
    }
};
