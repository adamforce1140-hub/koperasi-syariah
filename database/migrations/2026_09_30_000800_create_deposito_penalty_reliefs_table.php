<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel pengajuan Keringanan / Pembebasan Penalti Pencairan Dini Deposito.
 *
 * Penalti pencairan dini deposito Mudharabah adalah ta'zir (dana sosial) sesuai
 * Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000. Sebagaimana ta'zir pembiayaan, anggota
 * dapat mengajukan keringanan melalui jalur banding berjenjang:
 *
 *   TELLER (ajukan) -> AO (verifikasi & rekomendasi) -> MANAJER (approve/reject)
 *
 * Keringanan yang disetujui dipakai SATU KALI pada saat pencairan dini
 * (`dipakai_at`) dan tidak pernah mengurangi nilai ta'zir di bawah nol.
 *
 * CATATAN SAFEGUARD:
 *   - Migration ini TIDAK menyentuh tabel/kolom lama (depositos, deposito_products).
 *   - Memakai Schema::hasTable() agar aman dijalankan berulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('deposito_penalty_reliefs')) {
            Schema::create('deposito_penalty_reliefs', function (Blueprint $table) {
                $table->id();

                $table->foreignId('deposito_id')->constrained('depositos')->cascadeOnDelete();
                $table->foreignId('anggota_id')->constrained('anggota')->cascadeOnDelete();

                $table->decimal('nominal_penalti_awal', 15, 2)->default(0);      // hasil hitung produk
                $table->decimal('nominal_penalti_disetujui', 15, 2)->default(0); // 0 = bebas penuh

                // Pengajuan dari Teller / Petugas loket
                $table->text('alasan_pengaju');
                $table->foreignId('pengaju_id')->constrained('users')->cascadeOnDelete();

                // Verifikasi & rekomendasi AO
                $table->enum('rekomendasi_ao', ['SETUJU', 'TOLAK'])->nullable();
                $table->text('catatan_ao')->nullable();
                $table->foreignId('ao_id')->nullable()->constrained('users')->nullOnDelete();

                // Keputusan Manajer
                $table->text('catatan_manajer')->nullable();
                $table->foreignId('manajer_id')->nullable()->constrained('users')->nullOnDelete();

                $table->enum('status', ['PENDING_AO', 'PENDING_MANAJER', 'APPROVED', 'REJECTED'])
                    ->default('PENDING_AO');

                // Kapan keringanan benar-benar dipakai pada pencairan dini (sekali pakai).
                $table->timestamp('dipakai_at')->nullable();

                $table->timestamps();

                $table->index(['status', 'created_at']);
                $table->index('deposito_id');
                $table->index('anggota_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('deposito_penalty_reliefs');
    }
};
