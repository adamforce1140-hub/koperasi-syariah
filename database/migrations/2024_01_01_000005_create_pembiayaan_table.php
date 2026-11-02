<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pembiayaan', function (Blueprint $table) {
            $table->id();
            $table->string('no_pembiayaan', 30)->unique();
            $table->unsignedBigInteger('anggota_id');
            $table->unsignedBigInteger('akun_id');
            $table->enum('akad_pembiayaan', [
                'Murabahah', 'Mudharabah', 'Musyarakah', 'Ijarah',
                'Ijarah Muntahia Bittamlik', 'Qardh', 'Istishna', 'Salam',
            ]);
            $table->enum('tujuan_pembiayaan', [
                'Modal Usaha', 'Konsumsi', 'Investasi', 'Pendidikan',
                'Kesehatan', 'Perumahan', 'Kendaraan', 'Lainnya',
            ]);
            $table->decimal('jumlah_pembiayaan', 15, 2);
            $table->decimal('margin_nisbah', 15, 2)->default(0);
            $table->decimal('total_ujroh', 15, 2)->default(0);
            $table->decimal('total_pembiayaan', 15, 2);
            $table->integer('tenor');
            $table->enum('frekuensi_angsuran', ['Mingguan', 'Bulanan', 'Triwulan', 'Semesteran', 'Tahunan'])->default('Bulanan');
            $table->decimal('jumlah_angsuran', 15, 2);
            $table->decimal('nisbah_pengelola', 5, 2)->nullable();
            $table->decimal('nisbah_pemilik', 5, 2)->nullable();
            $table->date('tanggal_akad');
            $table->date('tanggal_cair')->nullable();
            $table->date('tanggal_jatuh_tempo');
            $table->unsignedBigInteger('rekening_id')->nullable();
            $table->enum('status_pembiayaan', [
                'Draft', 'Pengajuan', 'Direview', 'Disetujui', 'Ditolak',
                'Dicairkan', 'Aktif', 'Lunas', 'Macet', 'Dibatalkan',
                // Alur approval berjenjang (AO -> Manajer -> Pengurus -> DPS)
                // Catatan: MySQL ENUM case-insensitive, jadi 'disetujui' di kode cocok dengan 'Disetujui'
                'pending_ao', 'pending_manajer', 'pending_pengurus', 'pending_dps',
                'restrukturisasi',
            ])->default('Draft');
            $table->decimal('sisa_pembiayaan', 15, 2);
            $table->text('agunan')->nullable();
            $table->unsignedBigInteger('user_approved')->nullable();
            $table->text('keterangan')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('anggota_id')->references('id')->on('anggota')->restrictOnDelete();
            $table->foreign('akun_id')->references('id')->on('akun')->restrictOnDelete();
            $table->foreign('rekening_id')->references('id')->on('rekening')->nullOnDelete();
            $table->foreign('user_approved')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pembiayaan');
    }
};
