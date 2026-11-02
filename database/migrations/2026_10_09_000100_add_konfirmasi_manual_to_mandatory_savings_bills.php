<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Konfirmasi manual atas setoran otomatis Simpanan Wajib yang DITANGGUHKAN.
 *
 * LATAR BELAKANG (perbaikan BUG-03 — otomatisasi tanpa verifikasi):
 * Sebelumnya scheduler (`mandatory-savings:process-daily`) memanggil
 * auto-debit SEGERA setelah menerbitkan tagihan UNPAID, tanpa pernah
 * memverifikasi status pembayaran anggota. Akibatnya dana dipotong otomatis
 * setiap tanggal jatuh tempo seolah-olah pembayaran pasti terjadi.
 *
 * Sesudah perbaikan, tagihan yang tidak lolos verifikasi ditangguhkan
 * (status tertahan) dan MENUNGGU KONFIRMASI MANUAL petugas. Kolom di bawah
 * merekam jejak konfirmasi tersebut agar dapat diaudit:
 *
 *   status               → ditambah nilai 'PENDING_CONFIRMATION' (ditangguhkan)
 *   deferred_reason      → alasan penangguhan (mis. SALDO_TIDAK_CUKUP)
 *   deferred_at          → waktu penangguhan oleh sistem
 *   confirmed_by         → user (petugas/pengurus) yang mengonfirmasi/menolak
 *   confirmed_at         → waktu konfirmasi manual
 *   confirmation_note    → catatan keputusan petugas
 *
 * Prinsip syariah: penangguhan TIDAK mengenakan denda apa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mandatory_savings_bills')) {
            return;
        }

        Schema::table('mandatory_savings_bills', function (Blueprint $table) {
            if (! Schema::hasColumn('mandatory_savings_bills', 'deferred_reason')) {
                $table->string('deferred_reason', 60)->nullable()->after('status');
                $table->dateTime('deferred_at')->nullable()->after('deferred_reason');
                $table->unsignedBigInteger('confirmed_by')->nullable()->after('deferred_at');
                $table->dateTime('confirmed_at')->nullable()->after('confirmed_by');
                $table->text('confirmation_note')->nullable()->after('confirmed_at');

                $table->foreign('confirmed_by')->references('id')->on('users')->nullOnDelete();
                $table->index(['status', 'deferred_at'], 'mandatory_bills_status_deferred_index');
            }
        });

        // Lebarkan enum status agar memuat 'PENDING_CONFIRMATION' (MySQL 8 / SQLite).
        $this->ubahEnumStatus();
    }

    public function down(): void
    {
        if (! Schema::hasTable('mandatory_savings_bills')) {
            return;
        }

        Schema::table('mandatory_savings_bills', function (Blueprint $table) {
            if (Schema::hasColumn('mandatory_savings_bills', 'deferred_reason')) {
                $table->dropForeign(['confirmed_by']);
                $table->dropIndex('mandatory_bills_status_deferred_index');
                $table->dropColumn([
                    'deferred_reason',
                    'deferred_at',
                    'confirmed_by',
                    'confirmed_at',
                    'confirmation_note',
                ]);
            }
        });
    }

    /**
     * Samakan himpunan nilai kolom `status` di seluruh driver.
     *
     * - MySQL/MariaDB : kolom adalah ENUM → diubah via ALTER MODIFY.
     * - SQLite        : kolom adalah VARCHAR + CHECK → CHECK dilonggarkan dengan
     *                   mengubah definisi tabel (di sini cukup dibiarkan, karena
     *                   SQLite tidak menegakkan CHECK pada enum() Laravel bila
     *                   tabel dibuat ulang; kami tambahkan nilai lewat VARCHAR).
     */
    private function ubahEnumStatus(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            Schema::getConnection()->statement(
                'ALTER TABLE `mandatory_savings_bills` MODIFY `status` '
                ."ENUM('UNPAID','PAID','OVERDUE','PENDING_CONFIRMATION') NOT NULL DEFAULT 'UNPAID'"
            );

            return;
        }

        if ($driver === 'sqlite') {
            // SQLite menyimpan enum sebagai varchar + check constraint. Untuk
            // menjaga kompatibilitas pengujian, batasan check dilepas dengan
            // cara menyalin tabel tanpa constraint (dilakukan hanya bila ada).
            $sql = Schema::getConnection()->selectOne(
                "SELECT sql FROM sqlite_master WHERE type='table' AND name='mandatory_savings_bills'"
            );

            if ($sql && str_contains((string) $sql->sql, 'check')) {
                // Dibuat ulang tanpa CHECK: salin data lalu tukar tabel.
                Schema::getConnection()->statement('PRAGMA foreign_keys=OFF');

                Schema::getConnection()->statement(
                    'CREATE TABLE mandatory_savings_bills_tmp AS SELECT * FROM mandatory_savings_bills'
                );
                Schema::getConnection()->statement('DROP TABLE mandatory_savings_bills');
                Schema::getConnection()->statement(
                    'ALTER TABLE mandatory_savings_bills_tmp RENAME TO mandatory_savings_bills'
                );

                Schema::getConnection()->statement('PRAGMA foreign_keys=ON');
            }
        }
    }
};
