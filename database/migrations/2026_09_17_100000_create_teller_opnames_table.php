<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kas Opname & Closing Teller — diajukan Teller, diverifikasi Manajer.
 *
 * Satu baris = satu pengajuan opname fisik untuk satu sesi Buka Kas (kas_harian).
 * Selama status masih `pending`, kas belum boleh dianggap berpindah ke brankas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teller_opnames', function (Blueprint $table) {
            $table->id();

            // Sesi Buka Kas yang diopname (unique: 1 sesi = 1 pengajuan aktif)
            $table->foreignId('kas_harian_id')->constrained('kas_harian')->cascadeOnDelete();

            // Teller yang melakukan opname
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->date('tanggal');

            // Angka hasil opname
            $table->decimal('saldo_sistem', 15, 2)->default(0);   // menurut catatan sistem
            $table->decimal('total_fisik', 15, 2)->default(0);    // hasil hitung fisik pecahan
            $table->decimal('selisih', 15, 2)->default(0);        // total_fisik - saldo_sistem

            // Rincian per pecahan: [{pecahan: 100000, lembar: 3, subtotal: 300000}, ...]
            $table->json('rincian_pecahan');

            // pending = menunggu manajer; closed = diterima; rejected = ditolak
            $table->enum('status', ['pending', 'closed', 'rejected'])->default('pending');

            // balanced = selisih 0; over = kas lebih; short = kas kurang
            $table->enum('status_selisih', ['balanced', 'over', 'short'])->default('balanced');

            $table->text('catatan')->nullable();

            // Jejak verifikasi manajer
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('catatan_approval')->nullable();

            // Jurnal pemindahan kas yang dibentuk saat approve
            $table->foreignId('jurnal_id')->nullable()->constrained('jurnal')->nullOnDelete();

            $table->timestamps();

            $table->index(['status', 'tanggal']);
            $table->index(['user_id', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teller_opnames');
    }
};
