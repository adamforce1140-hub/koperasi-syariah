<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TAHAP 7 — INDEKS JALUR BACA (SKALABILITAS).
 *
 * Tahap 5 menambahkan indeks untuk jalur baca *laporan jurnal*. Audit
 * skalabilitas Tahap 7 menemukan lima kueri baca berulang yang MASIH
 * melakukan full table scan karena tidak ada indeks pendukung:
 *
 *   1. `rekening`  : WHERE status_rekening = 'Aktif' → SUM(saldo)
 *   2. `rekening`  : WHERE jenis_rekening IN (...)
 *   3. `jurnal`    : WHERE sumber = ?            (idempotensi posting jurnal)
 *   4. `anggota`   : WHERE status_anggota = ?     (scopeAktif / hitungan anggota)
 *   5. `angsuran`  : WHERE status_angsuran = ? AND tanggal_bayar BETWEEN ?
 *
 * Setiap kandidat indeks di bawah ini TIDAK ditambahkan berdasarkan dugaan:
 * masing-masing diuji lebih dulu dengan `EXPLAIN` (buat indeks → jalankan
 * kueri nyata → baca rencana eksekusi → hapus indeks). Hanya indeks yang
 * terbukti mengubah rencana dari `type=ALL` menjadi `type=ref`/`type=range`
 * yang dipertahankan. Indeks yang tidak terbukti dipakai dibuang agar tidak
 * menjadi beban tulis yang sia-sia.
 *
 * CATATAN PENTING — mengapa TIDAK ada indeks baru pada `transaksi`:
 * lambatnya laporan bulanan `transaksi` bukan karena kurang indeks, melainkan
 * karena bentuk kueri `whereMonth()/whereYear()` bersifat NON-SARGABLE
 * (MySQL membungkus kolom dengan MONTH()/YEAR() sehingga indeks tanggal yang
 * SUDAH ADA, `transaksi_waktu_transaksi_index`, tidak dapat dipakai).
 * Penambahan indeks apa pun pada `transaksi` tetap menghasilkan full table
 * scan untuk bentuk kueri tersebut. Perbaikannya ada di sisi kode: filter
 * tanggal diganti rentang (`App\Support\RentangPeriode`). Karena itu migrasi
 * ini sengaja tidak menambah indeks pada `transaksi` — tabel paling
 * write-heavy di sistem — demi menjaga kecepatan tulis.
 *
 * Sifat migrasi: aditif, idempoten, dan sadar-driver. Tidak mengubah data,
 * tidak mengubah struktur kolom, dan aman dijalankan pada basis data berisi.
 */
return new class extends Migration
{
    /**
     * Definisi indeks: nama => [tabel, daftar kolom berurutan].
     *
     * Urutan kolom mengikuti pola WHERE nyata pada kode:
     * - `rekening(status_rekening, saldo)` — melayani `scopeAktif()` +
     *   `SUM(saldo)`. Menjadikannya *covering index*: agregat saldo dibaca
     *   langsung dari indeks tanpa menyentuh tabel (`Extra=Using index`).
     * - `rekening(jenis_rekening, status_rekening, saldo)` — melayani filter
     *   jenis rekening (Simpanan Wajib/Sukarela/Berjangka) sekaligus agregat
     *   saldo. Kolom `jenis_rekening` diletakkan paling depan karena selalu
     *   menjadi predikat kesetaraan/in pada pemakaian nyata.
     * - `jurnal(sumber)` — melayani pengecekan idempotensi posting
     *   (`WHERE sumber = '...'`) yang dijalankan setiap kali jurnal dibuat.
     * - `anggota(status_anggota)` — melayani `scopeAktif()` dan hitungan
     *   anggota per status pada dashboard.
     * - `angsuran(status_angsuran, tanggal_bayar)` — melayani rekap angsuran
     *   masuk per bulan (`status_angsuran = 'Sudah Dibayar'` + rentang tanggal).
     */
    private array $indeks = [
        'rekening_status_saldo_index' => [
            'tabel' => 'rekening',
            'kolom' => ['status_rekening', 'saldo'],
        ],
        'rekening_jenis_status_saldo_index' => [
            'tabel' => 'rekening',
            'kolom' => ['jenis_rekening', 'status_rekening', 'saldo'],
        ],
        'jurnal_sumber_index' => [
            'tabel' => 'jurnal',
            'kolom' => ['sumber'],
        ],
        'anggota_status_anggota_index' => [
            'tabel' => 'anggota',
            'kolom' => ['status_anggota'],
        ],
        'angsuran_status_angsuran_tanggal_bayar_index' => [
            'tabel' => 'angsuran',
            'kolom' => ['status_angsuran', 'tanggal_bayar'],
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
