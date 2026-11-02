<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Normalisasi nilai status_transaksi & kolom status ke standar 'Sukses'.
     * Mengubah semua variasi ('berhasil', 'SUKSES', 'SUCCESS', 'success', 'sukses')
     * menjadi 'Sukses' (Title Case) yang konsisten di seluruh tabel transaksi.
     */
    public function up(): void
    {
        // Normalisasi status_transaksi
        DB::statement("
            UPDATE transaksi
            SET status_transaksi = 'Sukses'
            WHERE LOWER(status_transaksi) IN ('berhasil', 'sukses', 'success', 'berhasil', 'ok')
        ");

        // Normalisasi kolom status (legacy field)
        DB::statement("
            UPDATE transaksi
            SET status = 'Sukses'
            WHERE LOWER(status) IN ('berhasil', 'sukses', 'success', 'ok')
              AND status != 'Voided'
              AND status != 'Pending'
              AND status != 'Pending Void'
        ");

        // Normalisasi status_transaksi 'Voided' → pastikan konsisten
        DB::statement("
            UPDATE transaksi
            SET status_transaksi = 'Voided'
            WHERE LOWER(status_transaksi) IN ('voided', 'void', 'dibatalkan', 'cancelled')
        ");

        // Normalisasi status_transaksi 'Pending'
        DB::statement("
            UPDATE transaksi
            SET status_transaksi = 'Pending'
            WHERE LOWER(status_transaksi) IN ('pending', 'menunggu', 'waiting')
              AND status_transaksi NOT IN ('Pending Void', 'PENDING_MANAJER')
        ");
    }

    public function down(): void
    {
        // Tidak ada rollback — normalisasi data tidak dapat dibalik secara aman
    }
};
