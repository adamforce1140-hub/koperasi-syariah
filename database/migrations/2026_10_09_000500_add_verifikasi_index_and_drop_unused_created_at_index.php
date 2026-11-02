<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AUDIT TINGKAT 3 (PERFORMA) — INDEKS VERIFIKASI AML + PEMBERSIHAN INDEKS.
 *
 * 1) `transaksi.aml_status_verifikasi`
 *    Dashboard kepatuhan & papan verifikasi AML melakukan
 *    `WHERE aml_status_verifikasi = 'menunggu'` berulang kali, dan kolom ini
 *    juga dipakai sebagai filter tunggal paling selektif pada tabel `transaksi`
 *    (tabel paling ramai di sistem). Saat migrasi AML dibuat
 *    (2026_09_08_075607) kolom ini TIDAK diberi indeks, sehingga setiap kali
 *    dashboard kepatuhan dibuka MySQL melakukan full table scan atas SELURUH
 *    riwayat transaksi. Indeks ini murni aditif.
 *
 * 2) `transaksi.created_at`
 *    Indeks `transaksi_created_at_index` (dibuat otomatis oleh `$table->timestamps()`)
 *    terbukti TIDAK pernah dipakai: seluruh jalur baca transaksi mengurutkan
 *    dan memfilter memakai `waktu_transaksi` (lihat migrasi 2026_09_30_000300
 *    yang menambahkan `transaksi_waktu_transaksi_index`). Indeks yang tidak
 *    terpakai tetap dibayar mahal pada setiap INSERT/UPDATE ke tabel paling
 *    write-heavy ini, jadi indeks tersebut dibuang.
 *
 * Sifat migrasi: idempoten, sadar-driver, dan aman dijalankan pada basis data
 * berisi. `created_at` sengaja TIDAK dibuat ulang di dalam `up()` supaya
 * menjalankan ulang migrasi ini tidak memunculkan indeks yang sudah diputuskan
 * untuk dibuang.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Indeks verifikasi AML pada `transaksi` (aditif).
        if (! $this->indeksAda('transaksi_aml_status_verifikasi_index', 'transaksi')) {
            try {
                Schema::table('transaksi', function (Blueprint $table) {
                    $table->index('aml_status_verifikasi', 'transaksi_aml_status_verifikasi_index');
                });
            } catch (Throwable $e) {
                // Tidak menggagalkan migrasi: pada MySQL yang sangat lama /
                // driver tanpa dukungan, indeks ini hanya optimalisasi.
                report($e);
            }
        }

        // 2) Buang indeks `created_at` yang tidak terpakai.
        if ($this->indeksAda('transaksi_created_at_index', 'transaksi')) {
            try {
                Schema::table('transaksi', function (Blueprint $table) {
                    $table->dropIndex('transaksi_created_at_index');
                });
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    public function down(): void
    {
        if ($this->indeksAda('transaksi_aml_status_verifikasi_index', 'transaksi')) {
            try {
                Schema::table('transaksi', function (Blueprint $table) {
                    $table->dropIndex('transaksi_aml_status_verifikasi_index');
                });
            } catch (Throwable $e) {
                report($e);
            }
        }

        if (! $this->indeksAda('transaksi_created_at_index', 'transaksi')) {
            try {
                Schema::table('transaksi', function (Blueprint $table) {
                    $table->index('created_at', 'transaksi_created_at_index');
                });
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** Deteksi keberadaan indeks dengan nama persis pada tabel tersebut. */
    private function indeksAda(string $nama, string $tabel): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            return DB::table('information_schema.STATISTICS')
                ->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', $tabel)
                ->where('INDEX_NAME', $nama)
                ->exists();
        }

        if ($driver === 'sqlite') {
            return DB::table('sqlite_master')
                ->where('type', 'index')
                ->where('name', $nama)
                ->exists();
        }

        if ($driver === 'pgsql') {
            return DB::table('pg_indexes')
                ->where('indexname', $nama)
                ->where('tablename', $tabel)
                ->exists();
        }

        return false;
    }
};
