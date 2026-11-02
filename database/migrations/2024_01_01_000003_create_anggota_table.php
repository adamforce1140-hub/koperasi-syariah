<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anggota', function (Blueprint $table) {
            $table->id();
            $table->string('no_anggota', 20)->unique();
            $table->string('no_cif', 30)->nullable()->unique();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('nik', 16)->unique();
            $table->string('nama_lengkap', 150);
            $table->string('tempat_lahir', 100)->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->enum('jenis_kelamin', ['Laki-laki', 'Perempuan']);
            $table->enum('status_perkawinan', ['Belum Menikah', 'Menikah', 'Cerai', 'Janda', 'Duda'])->nullable();
            $table->text('alamat');
            $table->string('rt_rw', 20)->nullable();
            $table->string('kelurahan', 100)->nullable();
            $table->string('kecamatan', 100)->nullable();
            $table->string('kota', 100);
            $table->string('provinsi', 100);
            $table->string('kode_pos', 10)->nullable();
            $table->string('no_telepon', 20)->nullable();
            $table->string('no_hp', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('pekerjaan', 100)->nullable();
            $table->string('nama_ibu_kandung', 150)->nullable();
            $table->enum('status_anggota', ['Aktif', 'Tidak Aktif', 'Keluar', 'Dibekukan'])->default('Aktif');
            $table->date('tanggal_gabung')->nullable();
            $table->date('tanggal_keluar')->nullable();
            $table->decimal('simpanan_pokok', 15, 2)->default(0);
            $table->decimal('simpanan_wajib', 15, 2)->default(0);
            $table->text('keterangan')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anggota');
    }
};
