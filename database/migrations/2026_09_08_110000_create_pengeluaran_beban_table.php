<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pengeluaran Beban Operasional Koperasi — terhubung langsung CoA (kelompok Beban 5-XXXX).
     * Input: Teller/AO | Approval > Rp 1.000.000: Manajer | Audit: DPS read-only.
     */
    public function up(): void
    {
        Schema::create('pengeluaran_beban', function (Blueprint $table) {
            $table->id();
            $table->string('no_pengeluaran', 30)->unique();
            $table->date('tanggal');
            $table->foreignId('coa_beban_id')->constrained('coas')->cascadeOnUpdate();
            $table->foreignId('coa_kas_id')->constrained('coas')->cascadeOnUpdate();
            $table->decimal('nominal', 15, 2);
            $table->string('keterangan');
            $table->enum('status', ['Approved', 'Pending', 'Ditolak'])->default('Pending');
            $table->unsignedBigInteger('jurnal_id')->nullable(); // diisi setelah jurnal terposting
            $table->foreignId('created_by')->constrained('users')->cascadeOnUpdate();
            $table->foreignId('approved_by')->nullable()->constrained('users')->cascadeOnUpdate();
            $table->timestamp('approved_at')->nullable();
            $table->text('catatan_manajer')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengeluaran_beban');
    }
};
