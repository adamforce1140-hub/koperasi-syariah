<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Konfigurasi dinamis produk Simpanan & Deposito Syariah.
 *
 * Sebelumnya tabel `deposito_products` hanya menampung produk berjangka
 * (deposito). Modul Simpanan memerlukan master produk yang sama untuk
 * Simpanan Pokok, Simpanan Wajib, dan Simpanan Sukarela sehingga nisbah bagi
 * hasil tiap jenis simpanan dapat dikonfigurasi lewat database — bukan
 * ditanam di kode (hardcode).
 *
 * Dua kolom baru:
 *  - `tipe_produk` : pokok | wajib | sukarela | deposito
 *  - `has_nisbah`  : penanda apakah produk dihitung nisbah bagi hasil BULANAN.
 *                    Simpanan Pokok & Simpanan Wajib bernilai false karena
 *                    imbalannya diselesaikan lewat SHU Tahunan pada bagian
 *                    Ekuitas laporan keuangan (PSAK 101), bukan bagi hasil
 *                    bulanan.
 *  - `coa_kode`    : kode akun (CoA) yang menjadi rujukan pos produk, mis.
 *                    3-1001 Simpanan Pokok, 3-1002 Simpanan Wajib (Ekuitas),
 *                    2-1001 Simpanan Sukarela, 2-1003 Simpanan Berjangka.
 *
 * Kolom `tenor_months` dan `min_amount` dilonggarkan menjadi nullable karena
 * simpanan (pokok/wajib/sukarela) tidak memiliki tenor maupun batas minimum
 * pembukaan seperti deposito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposito_products', function (Blueprint $table) {
            if (! Schema::hasColumn('deposito_products', 'tipe_produk')) {
                // Ditempatkan setelah `name` supaya urutan kolom tetap mudah dibaca.
                $table->string('tipe_produk', 20)->default('deposito')->after('name');
            }

            if (! Schema::hasColumn('deposito_products', 'has_nisbah')) {
                $table->boolean('has_nisbah')->default(true)->after('tipe_produk');
            }

            if (! Schema::hasColumn('deposito_products', 'coa_kode')) {
                $table->string('coa_kode', 20)->nullable()->after('has_nisbah');
            }
        });

        // Simpanan pokok/wajib/sukarela tidak memakai tenor & minimum deposito.
        Schema::table('deposito_products', function (Blueprint $table) {
            $table->unsignedSmallInteger('tenor_months')->nullable()->default(null)->change();
            $table->decimal('min_amount', 15, 2)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('deposito_products', function (Blueprint $table) {
            $table->unsignedSmallInteger('tenor_months')->nullable(false)->default(1)->change();
            $table->decimal('min_amount', 15, 2)->nullable(false)->default(0)->change();
        });

        Schema::table('deposito_products', function (Blueprint $table) {
            if (Schema::hasColumn('deposito_products', 'coa_kode')) {
                $table->dropColumn('coa_kode');
            }

            if (Schema::hasColumn('deposito_products', 'has_nisbah')) {
                $table->dropColumn('has_nisbah');
            }

            if (Schema::hasColumn('deposito_products', 'tipe_produk')) {
                $table->dropColumn('tipe_produk');
            }
        });
    }
};
