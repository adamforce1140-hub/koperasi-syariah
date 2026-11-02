<?php

use App\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Perkuat `audit_logs` menjadi jejak audit yang tidak dapat disangkal.
     *
     * Menambahkan kolom penyegel rantai hash (mengikuti pola
     * `laporan_keuangan_logs`):
     *   hash = SHA256( isi_baris + hash_baris_sebelumnya )
     *
     * `hash` dan `hash_sebelumnya` disimpan sebagai `char(64)` berkolasi biner
     * agar perbandingan byte-per-byte (nilai hash tidak boleh dibandingkan
     * secara case-insensitive seperti kolasi utf8mb4_unicode_ci).
     *
     * Baris lama (bila ada) disegel ulang berurutan sehingga rantai tetap utuh.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('hash_sebelumnya_id')->nullable()->after('ip_address');
            $table->char('hash_sebelumnya', 64)->nullable()->collation('utf8mb4_bin')->after('hash_sebelumnya_id');
            $table->char('hash', 64)->nullable()->collation('utf8mb4_bin')->after('hash_sebelumnya');
        });

        $this->segeliBarisLama();
    }

    /**
     * Hitung dan simpan segel hash untuk baris yang belum punya hash.
     *
     * Memakai model (bukan query mentah) agar rumus hash tetap satu sumber
     * dengan AuditLog::hitungHash(). `saveQuietly()` melewati event model
     * sehingga guard append-only tidak menghalangi proses penyegelan.
     */
    private function segeliBarisLama(): void
    {
        $hashSebelumnya = null;
        $idSebelumnya = null;

        AuditLog::query()->orderBy('id')->chunkById(500, function ($baris) use (&$hashSebelumnya, &$idSebelumnya) {
            foreach ($baris as $log) {
                $log->hash_sebelumnya_id = $idSebelumnya;
                $log->hash_sebelumnya = $hashSebelumnya;
                $log->hash = $log->hitungHash();
                $log->saveQuietly();

                $hashSebelumnya = $log->hash;
                $idSebelumnya = $log->id;
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn(['hash_sebelumnya_id', 'hash_sebelumnya', 'hash']);
        });
    }
};
