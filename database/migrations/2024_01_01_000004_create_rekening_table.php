<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rekening', function (Blueprint $table) {
            $table->id();
            $table->string('no_rekening', 30)->unique();
            $table->unsignedBigInteger('anggota_id');
            $table->unsignedBigInteger('akun_id');
            $table->enum('jenis_rekening', [
                'Simpanan Pokok', 'Simpanan Wajib', 'Simpanan Sukarela',
                'Simpanan Berjangka', 'Simpanan Hari Raya', 'Modal Penyertaan',
            ]);
            $table->enum('akad', ['Wadiah', 'Mudharabah', 'Musharakah'])->default('Wadiah');
            $table->decimal('saldo', 15, 2)->default(0);
            $table->decimal('batas_minimum', 15, 2)->default(0);
            $table->decimal('nisbah_bank', 5, 2)->nullable();
            $table->decimal('nisbah_nasabah', 5, 2)->nullable();
            $table->date('tanggal_buka');
            $table->date('tanggal_jatuh_tempo')->nullable();
            $table->enum('status_rekening', ['Aktif', 'Dibekukan', 'Ditutup', 'Jatuh Tempo'])->default('Aktif');
            $table->text('keterangan')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('anggota_id')->references('id')->on('anggota')->restrictOnDelete();
            $table->foreign('akun_id')->references('id')->on('akun')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rekening');
    }
};
