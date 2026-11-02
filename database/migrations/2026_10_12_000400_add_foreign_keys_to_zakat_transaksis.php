<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INTEGRITAS REFERENSIAL BUKU BESAR ZAKAT — temuan audit Z-05.
 *
 * TEMUAN: kolom `jurnal_id` (jejak jurnal) dan `created_by` (pelaku pencatatan)
 * pada `zakat_transaksis` hanya `unsignedBigInteger` TANPA foreign key. Baris
 * buku besar dapat menunjuk jurnal/user yang tidak ada (referensi yatim),
 * melemahkan jejak audit dana amanah (PSAK 109).
 *
 * PERBAIKAN: tambahkan foreign key
 *   - `jurnal_id`  → `jurnal.id`  (nullOnDelete)
 *   - `created_by` → `users.id`   (nullOnDelete)
 *
 * Mengapa `nullOnDelete` (bukan restrict/cascade)? Relasi ini bersifat JEJAK
 * HISTORIS dan boleh kosong (NULL). Bila jurnal/user dihapus, baris buku besar
 * tetap utuh dengan referensi dinolkan — laporan historis tidak hilang dan
 * `ZakatService::validasi()` tetap dapat mendeteksi baris tanpa jurnal.
 *
 * P4 Default Aman: kedua kolom sudah NULLABLE; nilai yatim yang mungkin ada
 * dinolkan lebih dulu agar pemasangan foreign key tidak gagal pada basis data
 * berisi data. Tanpa perubahan ini seluruh alur lama berjalan identik.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('zakat_transaksis')) {
            return;
        }

        // Bersihkan referensi yatim lebih dulu (aman: kolom nullable).
        $this->bersihkanYatim('jurnal_id', 'jurnal');
        $this->bersihkanYatim('created_by', 'users');

        // Tentukan lebih dulu FK mana yang perlu dipasang (di luar closure agar
        // tidak ada query di dalam pembangunan blueprint skema).
        $pasangJurnal = Schema::hasColumn('zakat_transaksis', 'jurnal_id')
            && $this->namaForeign('zakat_transaksis', 'jurnal_id') === null;
        $pasangUser = Schema::hasColumn('zakat_transaksis', 'created_by')
            && $this->namaForeign('zakat_transaksis', 'created_by') === null;

        if ($pasangJurnal) {
            Schema::table('zakat_transaksis', function (Blueprint $table) {
                $table->foreign('jurnal_id', 'zakat_transaksis_jurnal_id_foreign')
                    ->references('id')->on('jurnal')
                    ->nullOnDelete();
            });
        }

        if ($pasangUser) {
            Schema::table('zakat_transaksis', function (Blueprint $table) {
                $table->foreign('created_by', 'zakat_transaksis_created_by_foreign')
                    ->references('id')->on('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('zakat_transaksis')) {
            return;
        }

        Schema::table('zakat_transaksis', function (Blueprint $table) {
            if ($this->namaForeign('zakat_transaksis', 'jurnal_id') !== null) {
                $table->dropForeign('zakat_transaksis_jurnal_id_foreign');
            }

            if ($this->namaForeign('zakat_transaksis', 'created_by') !== null) {
                $table->dropForeign('zakat_transaksis_created_by_foreign');
            }
        });
    }

    /** Nolkan nilai kolom yang menunjuk baris induk tidak ada. */
    private function bersihkanYatim(string $kolom, string $induk): void
    {
        if (! Schema::hasColumn('zakat_transaksis', $kolom) || ! Schema::hasTable($induk)) {
            return;
        }

        DB::table('zakat_transaksis')
            ->whereNotNull($kolom)
            ->whereNotIn($kolom, function ($q) use ($induk) {
                $q->select('id')->from($induk);
            })
            ->update([$kolom => null]);
    }

    /** Nama constraint foreign key aktif untuk kolom tertentu, atau null. */
    private function namaForeign(string $tabel, string $kolom): ?string
    {
        $row = DB::selectOne(
            'SELECT CONSTRAINT_NAME AS nama
               FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?
                AND REFERENCED_TABLE_NAME IS NOT NULL
              LIMIT 1',
            [$tabel, $kolom]
        );

        return $row->nama ?? null;
    }
};
