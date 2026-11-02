<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menambahkan parameter dinamis "Nominal Pembukaan Rekening".
 *
 * Bagian A — Parameter batas pada tabel kebijakan_koperasis:
 *   - minimum_pembukaan_rekening : batas bawah nominal pembukaan rekening yang diizinkan
 *   - maksimum_pembukaan_rekening: batas atas nominal pembukaan rekening yang diizinkan
 *   Nilai ini dibaca melalui App\Support\Setting sehingga dapat diubah pengurus
 *   dari halaman Profil & Kebijakan tanpa mengubah kode (tidak hardcode).
 *
 * Bagian B — Jejak nominal pada tabel rekening:
 *   - nominal_pembukaan: nominal setoran pembukaan rekening saat rekening dibuka,
 *     disimpan agar tidak ikut berubah ketika saldo berjalan berubah.
 *     Rekening lama di-backfill dari kolom saldo.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Bagian A: parameter batas nominal pada kebijakan koperasi ──────────
        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (! Schema::hasColumn('kebijakan_koperasis', 'minimum_pembukaan_rekening')) {
                $table->decimal('minimum_pembukaan_rekening', 15, 2)
                    ->default(10000.00)
                    ->after('mandatory_monthly_savings');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'maksimum_pembukaan_rekening')) {
                $table->decimal('maksimum_pembukaan_rekening', 15, 2)
                    ->default(50000000.00)
                    ->after('minimum_pembukaan_rekening');
            }
        });

        // ── Bagian B: kolom jejak nominal pembukaan pada rekening ──────────────
        Schema::table('rekening', function (Blueprint $table) {
            if (! Schema::hasColumn('rekening', 'nominal_pembukaan')) {
                $table->decimal('nominal_pembukaan', 15, 2)
                    ->nullable()
                    ->after('saldo')
                    ->comment('Nominal setoran saat rekening dibuka (jejak historis, tidak berubah saat saldo berjalan berubah).');
            }
        });

        // Backfill rekening lama: nominal pembukaan dianggap sama dengan saldo saat ini.
        DB::table('rekening')
            ->whereNull('nominal_pembukaan')
            ->update(['nominal_pembukaan' => DB::raw('saldo')]);
    }

    public function down(): void
    {
        Schema::table('rekening', function (Blueprint $table) {
            if (Schema::hasColumn('rekening', 'nominal_pembukaan')) {
                $table->dropColumn('nominal_pembukaan');
            }
        });

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (Schema::hasColumn('kebijakan_koperasis', 'maksimum_pembukaan_rekening')) {
                $table->dropColumn('maksimum_pembukaan_rekening');
            }

            if (Schema::hasColumn('kebijakan_koperasis', 'minimum_pembukaan_rekening')) {
                $table->dropColumn('minimum_pembukaan_rekening');
            }
        });
    }
};
