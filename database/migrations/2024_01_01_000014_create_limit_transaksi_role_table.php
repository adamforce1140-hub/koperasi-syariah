<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('limit_transaksi_role', function (Blueprint $table) {
            $table->id();
            $table->string('role'); // teller, manajer, pengurus, admin
            $table->string('jenis_transaksi'); // setor_tunai, tarik_tunai, non_tunai, pencairan, angsuran
            $table->decimal('limit_per_transaksi', 15, 2)->nullable()->comment('NULL = unlimited');
            $table->decimal('limit_harian', 15, 2)->nullable()->comment('NULL = unlimited');
            $table->boolean('butuh_approval')->default(false);
            $table->string('approval_role')->nullable()->comment('Role yang harus approve jika melebihi limit');
            $table->timestamps();
            $table->unique(['role', 'jenis_transaksi']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limit_transaksi_role');
    }
};
