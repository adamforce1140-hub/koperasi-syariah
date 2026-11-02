<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengajuan_rekening', function (Blueprint $table) {
            $table->id();
            $table->string('kode_pengajuan', 30)->unique();
            $table->unsignedBigInteger('user_id')->nullable(); // calon anggota (mobile)
            // Data diri calon anggota
            $table->string('nama_lengkap', 150);
            $table->string('nik', 16);
            $table->string('tempat_lahir', 100)->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->enum('jenis_kelamin', ['Laki-laki', 'Perempuan']);
            $table->text('alamat');
            $table->string('kota', 100)->nullable();
            $table->string('provinsi', 100)->nullable();
            $table->string('no_hp', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('pekerjaan', 100)->nullable();
            // Dokumen
            $table->string('foto_ktp')->nullable();         // path file e-KTP
            $table->string('foto_selfie_ktp')->nullable();  // path selfie + KTP
            // Jenis simpanan yang dipilih
            $table->enum('jenis_simpanan', [
                'Simpanan Pokok', 'Simpanan Wajib', 'Simpanan Sukarela',
            ])->default('Simpanan Pokok');
            $table->decimal('nominal_simpanan_awal', 15, 2)->default(0);
            // Status alur
            $table->enum('status', [
                'Menunggu Verifikasi',
                'Disetujui',
                'Ditolak',
                'Menunggu Pembayaran',
                'Menunggu Konfirmasi',
                'Aktif',
            ])->default('Menunggu Verifikasi');
            $table->text('alasan_tolak')->nullable();
            $table->unsignedBigInteger('diverifikasi_oleh')->nullable(); // user pengelola
            $table->timestamp('waktu_verifikasi')->nullable();
            // Setelah aktif
            $table->unsignedBigInteger('anggota_id')->nullable(); // link ke tabel anggota
            $table->string('no_rekening_terbit', 30)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('diverifikasi_oleh')->references('id')->on('users')->nullOnDelete();
            $table->foreign('anggota_id')->references('id')->on('anggota')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengajuan_rekening');
    }
};
