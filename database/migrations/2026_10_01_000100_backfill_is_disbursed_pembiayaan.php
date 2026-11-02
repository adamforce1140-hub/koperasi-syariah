<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Memperbaiki kolom `is_disbursed` yang selama ini IKUT TERBUANG oleh
 * mass-assignment karena tidak terdaftar pada `$fillable` model Pembiayaan.
 *
 * Dampak nyata (audit CKPN): seluruh pembiayaan yang sudah dicairkan di database
 * memiliki `is_disbursed = 0`, sehingga penjagaan "cegah double pencairan"
 * (PengajuanPembiayaanIntegController) tidak pernah bekerja. Setelah kolom
 * dimasukkan ke `$fillable`, migrasi ini memulihkan nilainya secara retroaktif
 * dari bukti keadaan riil: pembiayaan yang SUDAH memiliki jadwal angsuran
 * berarti dananya sudah disalurkan (jadwal dibuat tepat saat pencairan).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Pulihkan is_disbursed untuk pembiayaan yang sudah punya jadwal angsuran.
        DB::table('pembiayaan')
            ->where('is_disbursed', false)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('angsuran')
                    ->whereColumn('angsuran.pembiayaan_id', 'pembiayaan.id');
            })
            ->update(['is_disbursed' => true]);
    }

    public function down(): void
    {
        // Tidak dikembalikan: nilai hasil pemulihan mencerminkan keadaan riil
        // dan aman untuk dibiarkan aktif.
    }
};
