<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('dana_kebajikans', function (Blueprint $table) {
            $table->id();
            $table->string('no_referensi')->unique();
            $table->string('sumber_dana');
            $table->string('jenis')->default('Penerimaan'); // Penerimaan / Penyaluran
            $table->decimal('jumlah', 15, 2);
            $table->text('keterangan')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dana_kebajikans');
    }
};
