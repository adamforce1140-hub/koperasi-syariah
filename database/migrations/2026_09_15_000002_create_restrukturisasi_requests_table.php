<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel pengajuan Restrukturisasi Pembiayaan (3R).
 *
 * Alur: AO (PENDING_APPROVAL) -> MANAJER (APPROVED / REJECTED).
 *
 * SAFEGUARD SYARIAH (Fatwa DSN-MUI No. 49/DSN-MUI/IV/2005):
 *   Kolom *_snapshot menyimpan sisa pokok / sisa margin / sisa piutang pada saat
 *   pengajuan. Nilai sisa piutang inilah yang dibagi rata ke tenor baru —
 *   perpanjangan tenor TIDAK BOLEH menambah nominal sisa margin/piutang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restrukturisasi_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pembiayaan_id');
            $table->unsignedBigInteger('ao_id')->nullable();

            $table->enum('skema_restrukturisasi', ['Rescheduling', 'Reconditioning', 'Restructuring']);
            $table->integer('tenor_baru')->nullable();
            $table->integer('grace_period')->nullable();           // penundaan bayar pokok (bulan)
            $table->date('tanggal_jatuh_tempo_baru')->nullable();  // perubahan jatuh tempo
            $table->string('alasan_restrukturisasi');
            $table->decimal('kemampuan_bayar_baru', 15, 2)->default(0);
            $table->text('catatan_ao')->nullable();

            // Snapshot kondisi piutang saat pengajuan (safeguard + komparasi)
            $table->decimal('sisa_pokok_snapshot', 15, 2)->default(0);
            $table->decimal('sisa_margin_snapshot', 15, 2)->default(0);
            $table->decimal('sisa_piutang_snapshot', 15, 2)->default(0);
            $table->decimal('angsuran_lama_snapshot', 15, 2)->default(0);

            $table->string('status', 30)->default('PENDING_APPROVAL');
            $table->unsignedBigInteger('manajer_id')->nullable();
            $table->text('catatan_manajer')->nullable();
            $table->timestamp('keputusan_at')->nullable();
            $table->string('addendum_no')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('pembiayaan_id')->references('id')->on('pembiayaan')->cascadeOnDelete();
            $table->foreign('ao_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('manajer_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['status', 'pembiayaan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restrukturisasi_requests');
    }
};
