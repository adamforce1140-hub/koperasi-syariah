<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konfigurasi DINAMIS produk simpanan (Zero-Hardcoding) + kepatuhan PSAK 101/108.
 *
 * Menambahkan kolom parameter produk pada master `jenis_simpanans` sehingga
 * seluruh perilaku modul Simpanan (nominal setoran, auto-debit, akun COA) dapat
 * diubah pengurus tanpa menyentuh kode inti:
 *
 *   - code               : kode baku produk (POKOK | WAJIB | SUKARELA), unik.
 *   - name               : alias kolom `nama` (nama resmi produk simpanan).
 *   - min_deposit_amount : nominal minimal setoran (bigint, rupiah bulat).
 *   - allow_auto_debit   : izin auto-debit pindah buku (default false).
 *   - account_coa_id     : FK ke `coas` (integritas referensial COA).
 *
 * Catatan PSAK 101 ¶ 31: Simpanan Pokok (3-1001) & Simpanan Wajib (3-1002)
 * adalah MODAL/EKUITAS; Simpanan Sukarela (2-1001) adalah KEWAJIBAN (titipan).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('jenis_simpanans')) {
            return;
        }

        Schema::table('jenis_simpanans', function (Blueprint $table) {
            if (! Schema::hasColumn('jenis_simpanans', 'code')) {
                $table->string('code', 20)->nullable()->unique()->after('id');
            }

            if (! Schema::hasColumn('jenis_simpanans', 'name')) {
                $table->string('name', 100)->nullable()->after('code');
            }

            if (! Schema::hasColumn('jenis_simpanans', 'min_deposit_amount')) {
                $table->unsignedBigInteger('min_deposit_amount')->default(0)->after('tipe');
            }

            if (! Schema::hasColumn('jenis_simpanans', 'allow_auto_debit')) {
                $table->boolean('allow_auto_debit')->default(false)->after('min_deposit_amount');
            }

            if (! Schema::hasColumn('jenis_simpanans', 'account_coa_id')) {
                $table->unsignedBigInteger('account_coa_id')->nullable()->after('allow_auto_debit');
            }
        });

        // Constraint referensial COA: hapus produk yang masih direferensikan
        // akan ditolak (restrictOnDelete) agar integritas akuntansi terjaga.
        if (Schema::hasTable('coas') && Schema::hasColumn('jenis_simpanans', 'account_coa_id')) {
            Schema::table('jenis_simpanans', function (Blueprint $table) {
                $table->foreign('account_coa_id')
                    ->references('id')
                    ->on('coas')
                    ->restrictOnDelete();
            });
        }

        // ── Backfill data lama ──────────────────────────────────────────────
        // `name` disamakan dengan `nama` (nama resmi produk).
        DB::table('jenis_simpanans')
            ->whereNull('name')
            ->update(['name' => DB::raw('nama')]);

        // `code` diturunkan dari `tipe` (nilai sah: POKOK | WAJIB | SUKARELA).
        $petaKode = [
            'pokok' => 'POKOK',
            'wajib' => 'WAJIB',
            'sukarela' => 'SUKARELA',
        ];

        foreach ($petaKode as $tipe => $code) {
            DB::table('jenis_simpanans')
                ->where('tipe', $tipe)
                ->whereNull('code')
                ->update(['code' => $code]);
        }

        // Simpanan Pokok = 100000, Simpanan Wajib = 50000 (konfigurasi awal).
        DB::table('jenis_simpanans')->where('code', 'POKOK')->where('min_deposit_amount', 0)
            ->update(['min_deposit_amount' => 100000]);
        DB::table('jenis_simpanans')->where('code', 'WAJIB')->where('min_deposit_amount', 0)
            ->update(['min_deposit_amount' => 50000]);

        // Ikat ke COA resmi: Pokok→3-1001, Wajib→3-1002, Sukarela→2-1001.
        $petaCoa = [
            'POKOK' => '3-1001',
            'WAJIB' => '3-1002',
            'SUKARELA' => '2-1001',
        ];

        if (Schema::hasTable('coas')) {
            foreach ($petaCoa as $code => $kodeAkun) {
                $coaId = DB::table('coas')->where('kode_akun', $kodeAkun)->value('id');

                if ($coaId !== null) {
                    DB::table('jenis_simpanans')
                        ->where('code', $code)
                        ->whereNull('account_coa_id')
                        ->update(['account_coa_id' => $coaId]);
                }
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('jenis_simpanans')) {
            return;
        }

        if (Schema::hasColumn('jenis_simpanans', 'account_coa_id')) {
            Schema::table('jenis_simpanans', function (Blueprint $table) {
                $table->dropForeign(['account_coa_id']);
            });
        }

        Schema::table('jenis_simpanans', function (Blueprint $table) {
            foreach (['account_coa_id', 'allow_auto_debit', 'min_deposit_amount', 'name', 'code'] as $kolom) {
                if (Schema::hasColumn('jenis_simpanans', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
