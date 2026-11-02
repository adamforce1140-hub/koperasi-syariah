<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel pengajuan Keringanan / Pembebasan Ta'zir (Denda Syariah).
 *
 * Alur: TELLER (ajukan) -> AO (verifikasi & rekomendasi) -> MANAJER (approve/reject).
 *
 * CATATAN SAFEGUARD:
 *   - Migration ini TIDAK menyentuh tabel/kolom lama (keringanan_tazirs, angsuran, dll).
 *   - Menggunakan Schema::hasTable() & Schema::hasColumn() agar aman dijalankan berulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tazir_relief_requests')) {
            Schema::create('tazir_relief_requests', function (Blueprint $table) {
                $table->id();

                // Referensi jadwal angsuran (tabel aktual: `angsuran`)
                $table->foreignId('jadwal_angsuran_id')->constrained('angsuran')->cascadeOnDelete();
                $table->foreignId('pembiayaan_id')->constrained('pembiayaan')->cascadeOnDelete();
                $table->foreignId('anggota_id')->constrained('anggota')->cascadeOnDelete();

                $table->decimal('nominal_tazir_awal', 15, 2)->default(0);       // hasil kalkulasi TazirService
                $table->decimal('nominal_tazir_disetujui', 15, 2)->default(0);  // 0 = bebas penuh

                // Pengajuan dari Teller
                $table->text('alasan_teller');
                $table->foreignId('teller_id')->constrained('users')->cascadeOnDelete();

                // Verifikasi & rekomendasi AO
                $table->enum('rekomendasi_ao', ['SETUJU', 'TOLAK'])->nullable();
                $table->text('catatan_ao')->nullable();
                $table->foreignId('ao_id')->nullable()->constrained('users')->nullOnDelete();

                // Keputusan Manajer
                $table->text('catatan_manajer')->nullable();
                $table->foreignId('manajer_id')->nullable()->constrained('users')->nullOnDelete();

                $table->enum('status', ['PENDING_AO', 'PENDING_MANAJER', 'APPROVED', 'REJECTED'])
                    ->default('PENDING_AO');

                $table->timestamps();

                $table->index(['status', 'created_at']);
                $table->index('jadwal_angsuran_id');
                $table->index('anggota_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tazir_relief_requests');
    }
};
