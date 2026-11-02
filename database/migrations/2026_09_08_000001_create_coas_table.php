<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabel Chart of Accounts (CoA) standar akuntansi syariah (PSAK 101-111)
        Schema::create('coas', function (Blueprint $table) {
            $table->id();
            $table->string('kode_akun', 20)->unique();   // ex: 1-1001
            $table->string('nama_akun', 150);
            $table->enum('kategori', ['Aset', 'Kewajiban', 'Ekuitas', 'Pendapatan', 'Beban']);
            $table->enum('tipe_saldo_normal', ['Debet', 'Kredit']);
            $table->decimal('saldo_awal', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Kolom penghubung jurnal_detail ke CoA
        Schema::table('jurnal_detail', function (Blueprint $table) {
            // akun_id punya FK ke tabel akun — dibuat nullable agar entri berbasis CoA tidak melanggar FK
            $table->unsignedBigInteger('akun_id')->nullable()->change();
            $table->unsignedBigInteger('coa_id')->nullable()->after('akun_id');
            $table->foreign('coa_id')->references('id')->on('coas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('jurnal_detail', function (Blueprint $table) {
            $table->dropForeign(['coa_id']);
            $table->dropColumn('coa_id');
        });

        Schema::dropIfExists('coas');
    }
};
