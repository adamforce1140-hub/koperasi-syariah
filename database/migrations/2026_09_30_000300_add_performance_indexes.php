<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TAHAP 5 — INDEKS KINERJA UNTUK JALUR BACA LAPORAN.
 *
 * Setiap laporan keuangan dan pengecekan saldo memfilter jurnal dengan
 * `tanggal_jurnal <= :sampai` DAN `status = 'Posted'`. Tanpa indeks, MySQL
 * melakukan full table scan pada `jurnal` PLUS scan penuh `jurnal_detail`
 * untuk SETIAP akun CoA yang ditampilkan (N+1 tersembunyi lewat
 * saldoBerjalan()). Beban tumbuh linier terhadap jumlah akun × jumlah jurnal,
 * sehingga perlambatan baru terasa setelah data produksi menumpuk — saat
 * memperbaikinya sudah jauh lebih mahal.
 *
 * Migrasi ini murni MENAMBAH indeks; tidak ada perubahan struktur, tidak ada
 * perubahan data, dan tidak menyentuh kode produksi. Sifatnya aditif sehingga
 * aman dijalankan pada basis data yang sudah berisi data.
 *
 * Catatan: Laravel/Doctrine tidak menyediakan API portable untuk INDEKS
 * KOMPOSIT, sedangkan justru indeks komposit inilah yang dibutuhkan MySQL
 * agar kolom ber-kardinalitas rendah (`status`) dapat dipakai setelah kolom
 * tanggal. Karena itu DDL ditulis eksplisit per-driver.
 */
return new class extends Migration
{
    /**
     * Definisi indeks: nama => [tabel, daftar kolom berurutan].
     *
     * Urutan kolom SENGAJA dipilih agar sesuai pola WHERE yang dipakai kode:
     * - `jurnal`: status='Posted' AND tanggal_jurnal <= ?  → (status, tanggal_jurnal)
     * - `jurnal_detail`: WHERE jurnal_id = ? (join & saldoBerjalan) → (jurnal_id, coa_id)
     *   Kolom `coa_id` diletakkan setelah `jurnal_id` supaya satu indeks melayani
     *   dua bentuk query sekaligus: "semua baris milik satu jurnal" dan
     *   "semua baris satu akun".
     * - `transaksi`: filter laporan per rentang waktu_transaksi → (waktu_transaksi)
     * - `angsuran`: penyusunan jadwal & tunggakan → (pembiayaan_id, tanggal_jatuh_tempo)
     * - `pembiayaan`: papan pemantauan per status → (status_pembiayaan, tanggal_akad)
     */
    private array $indeks = [
        'jurnal_status_tanggal_jurnal_index' => [
            'tabel' => 'jurnal',
            'kolom' => ['status', 'tanggal_jurnal'],
        ],
        'jurnal_detail_jurnal_id_coa_id_index' => [
            'tabel' => 'jurnal_detail',
            'kolom' => ['jurnal_id', 'coa_id'],
        ],
        'transaksi_waktu_transaksi_index' => [
            'tabel' => 'transaksi',
            'kolom' => ['waktu_transaksi'],
        ],
        'angsuran_pembiayaan_id_tanggal_jatuh_tempo_index' => [
            'tabel' => 'angsuran',
            'kolom' => ['pembiayaan_id', 'tanggal_jatuh_tempo'],
        ],
        'pembiayaan_status_tanggal_akad_index' => [
            'tabel' => 'pembiayaan',
            'kolom' => ['status_pembiayaan', 'tanggal_akad'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indeks as $nama => $definisi) {
            $this->buatIndeksBilaBelumAda($nama, $definisi['tabel'], $definisi['kolom']);
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->indeks) as $nama) {
            $this->hapusIndeksBilaAda($nama);
        }
    }

    /**
     * Buat indeks komposit, idempoten, dan sadar-driver.
     *
     * @param  list<string>  $kolom
     */
    private function buatIndeksBilaBelumAda(string $nama, string $tabel, array $kolom): void
    {
        if (! Schema::hasTable($tabel)) {
            return;
        }

        // Kolom wajib ada; bila skema menyimpang, lewati alih-alih gagal total.
        foreach ($kolom as $k) {
            if (! Schema::hasColumn($tabel, $k)) {
                return;
            }
        }

        if ($this->indeksAda($nama, $tabel)) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        $daftar = implode(', ', array_map(fn (string $k): string => "`{$k}`", $kolom));
        $dialek = match ($driver) {
            'mysql', 'mariadb' => "ALTER TABLE `{$tabel}` ADD INDEX `{$nama}` ({$daftar})",
            'pgsql' => "CREATE INDEX \"{$nama}\" ON \"{$tabel}\" ({$daftar})",
            'sqlite' => "CREATE INDEX \"{$nama}\" ON \"{$tabel}\" ({$daftar})",
            default => null,
        };

        if ($dialek === null) {
            // Driver tanpa dialek yang dikenal: lewati tanpa menggagalkan migrasi.
            return;
        }

        DB::statement($dialek);
    }

    private function hapusIndeksBilaAda(string $nama): void
    {
        $driver = DB::connection()->getDriverName();

        try {
            if ($driver === 'mysql' || $driver === 'mariadb') {
                // MySQL tidak punya "DROP INDEX IF EXISTS"; cek dulu lewat information_schema.
                $ada = DB::table('information_schema.STATISTICS')
                    ->whereRaw('TABLE_SCHEMA = DATABASE()')
                    ->where('INDEX_NAME', $nama)
                    ->exists();

                if ($ada) {
                    $tabel = DB::table('information_schema.STATISTICS')
                        ->whereRaw('TABLE_SCHEMA = DATABASE()')
                        ->where('INDEX_NAME', $nama)
                        ->value('TABLE_NAME');

                    DB::statement("ALTER TABLE `{$tabel}` DROP INDEX `{$nama}`");
                }

                return;
            }

            if ($driver === 'pgsql' || $driver === 'sqlite') {
                DB::statement("DROP INDEX IF EXISTS \"{$nama}\"");
            }
        } catch (Throwable $e) {
            // Kebersihan down() tidak boleh menggagalkan rollback migrasi lain.
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
