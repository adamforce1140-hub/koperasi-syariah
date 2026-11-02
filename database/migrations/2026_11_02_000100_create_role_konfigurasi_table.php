<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot konfigurasi peran di DATABASE — langkah #6 permintaan pemilik
 * sistem: "Simpan konfigurasi role super admin dengan benar di database
 * sistem agar pengaturan hak akses tetap konsisten dan bertahan di semua
 * sesi penggunaan sistem".
 *
 * Mengapa tabel ini ada padahal sumber aturan sudah berupa berkas config:
 *   - berkas `config/role_hierarki.php` & `config/izin.php` adalah DEFAULT
 *     AMAN yang ikut berubah setiap deploy;
 *   - yang perlu BERTAHAN lintas sesi/deploy adalah SNAPSHOT dari default
 *     tersebut, sehingga penyimpangan (rule yang hilang / berkurang) dapat
 *     dideteksi dan dipulihkan — bukan diam-diam berlaku.
 *
 * Tabel ini TIDAK memberi wewenang. Wewenang tetap ditegakkan middleware
 * dari config (lihat catatan di `config/role_hierarki.php`: hierarki tidak
 * memberi akses implisit). Baris di sini adalah CATATAN KEBENARAN untuk
 * audit & pemulihan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_konfigurasi', function (Blueprint $table) {
            $table->id();
            $table->string('role')->unique()->comment('Nama peran pada config/role_hierarki.php (`peran`)');
            $table->string('label');
            $table->unsignedSmallInteger('level')->default(0)->comment('Makin besar = makin tinggi');
            $table->string('kategori')->nullable();
            $table->boolean('penyetuju')->default(false);
            $table->boolean('operasional')->default(false);
            $table->string('dashboard_route')->nullable();
            $table->json('izin')->nullable()->comment('Daftar nama izin dari config/izin.php yang dipegang peran');
            $table->json('kelompok')->nullable()->comment('Daftar kelompok peran_hierarki yang diikuti peran');
            $table->json('izin_dokumen')->nullable()->comment('Daftar prefiks dokumen yang dapat diakses');
            $table->string('sidik', 64)->comment('sha256(role + payload) untuk deteksi penyimpangan config');
            $table->timestamp('disinkronkan_pada')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_konfigurasi');
    }
};
