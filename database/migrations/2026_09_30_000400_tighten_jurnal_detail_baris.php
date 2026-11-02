<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TAHAP 5 — INTEGRITAS URUTAN BARIS JURNAL.
 *
 * `jurnal_detail.baris` adalah kolom NOT NULL TANPA default maupun unique
 * constraint. Artinya:
 *
 * 1. INSERT yang lupa mengisi `baris` akan gagal dengan pesan galat mentah
 *    MySQL ("Field 'baris' doesn't have a default value") — bukan kegagalan
 *    yang menjelaskan diri sendiri. Setiap jalur baru yang menulis detail
 *    jurnal (seeder, command, perbaikan migrasi, kode fitur) harus ingat.
 * 2. Tidak ada yang mencegah dua baris dalam SATU jurnal memakai `baris` yang
 *    sama. Read-path `Jurnal::detail()` mengurutkan berdasarkan `baris`, jadi
 *    duplikat membuat URUTAN DASAR JURNAL menjadi tidak deterministik — dan
 *    urutan baris adalah jejak audit akuntansi (Debet dulu, Kredit lawan).
 *
 * Perbaikan yang dilakukan migrasi ini:
 * - Menetapkan DEFAULT 0 pada `baris` agar INSERT tetap berhasil walau kolom
 *   tidak disebut (fail-safe), TANPA mengubah kode produksi.
 * - Menambah UNIQUE (jurnal_id, baris) sebagai penjaga integritas terakhir di
 *   level basis data; nomor baris dalam satu jurnal tidak boleh bertabrakan.
 *
 * Aman pada basis data berisi data: sebelum memasang UNIQUE, migrasi ini
 * memeriksa apakah sudah ada duplikat. Bila TIDAK ada (kondisi normal), UNIQUE
 * langsung dipasang. Bila ADA, nomor baris dirapikan lebih dulu (deterministik:
 * urut id) agar migrasi tidak gagal — data lama tetap terselamatkan dan
 * penjaganya tetap terpasang.
 */
return new class extends Migration
{
    private const INDEKS = 'jurnal_detail_jurnal_id_baris_unique';

    public function up(): void
    {
        if (! Schema::hasTable('jurnal_detail') || ! Schema::hasColumn('jurnal_detail', 'baris')) {
            return;
        }

        // 1. Fail-safe: kolom tetap boleh tidak disebut saat INSERT.
        if (Schema::hasColumn('jurnal_detail', 'baris')) {
            try {
                DB::statement('ALTER TABLE `jurnal_detail` MODIFY `baris` INT NOT NULL DEFAULT 0');
            } catch (Throwable $e) {
                // Dialek non-MySQL: lewati penyesuaian default.
            }
        }

        // 2. Rapikan duplikat (bila ada) sebelum memasang penjaga UNIQUE.
        $this->rapikanDuplikat();

        // 3. Penjaga integritas: nomor baris unik per jurnal.
        if (! $this->indeksAda()) {
            try {
                DB::statement('ALTER TABLE `jurnal_detail` ADD UNIQUE `'.self::INDEKS.'` (`jurnal_id`, `baris`)');
            } catch (Throwable $e) {
                // Driver tanpa dukungan DDL ini: jangan gagalkan migrasi.
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('jurnal_detail')) {
            return;
        }

        if ($this->indeksAda()) {
            try {
                DB::statement('ALTER TABLE `jurnal_detail` DROP INDEX `'.self::INDEKS.'`');
            } catch (Throwable $e) {
                // Kebersihan down() tidak boleh menggagalkan rollback migrasi lain.
            }
        }

        try {
            DB::statement('ALTER TABLE `jurnal_detail` MODIFY `baris` INT NOT NULL');
        } catch (Throwable $e) {
            // Dialek non-MySQL: lewati.
        }
    }

    /**
     * Beri nomor ulang baris HANYA pada jurnal yang memiliki tabrakan nomor.
     *
     * Jurnal yang sudah bersih tidak disentuh sama sekali — nomor baris asli
     * yang mungkin dikutip catatan audit tidak diubah tanpa alasan.
     */
    private function rapikanDuplikat(): void
    {
        $jurnalBermasalah = DB::table('jurnal_detail')
            ->select('jurnal_id')
            ->groupBy('jurnal_id', 'baris')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('jurnal_id')
            ->filter()
            ->unique()
            ->values();

        foreach ($jurnalBermasalah as $jurnalId) {
            $baris = DB::table('jurnal_detail')
                ->where('jurnal_id', $jurnalId)
                ->orderBy('id')
                ->pluck('id');

            $nomor = 1;
            foreach ($baris as $id) {
                DB::table('jurnal_detail')->where('id', $id)->update(['baris' => $nomor++]);
            }
        }
    }

    private function indeksAda(): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            return DB::table('information_schema.STATISTICS')
                ->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', 'jurnal_detail')
                ->where('INDEX_NAME', self::INDEKS)
                ->exists();
        }

        if ($driver === 'sqlite') {
            return DB::table('sqlite_master')->where('type', 'index')->where('name', self::INDEKS)->exists();
        }

        if ($driver === 'pgsql') {
            return DB::table('pg_indexes')->where('indexname', self::INDEKS)->exists();
        }

        return false;
    }
};
