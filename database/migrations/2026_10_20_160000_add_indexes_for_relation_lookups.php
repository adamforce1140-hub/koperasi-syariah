<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INDEKS KOLOM RELASI YANG BELUM TERINDEKS.
 *
 * Audit pemeliharaan 20 Oktober 2026 memindai seluruh kolom berakhiran `_id`
 * dan menemukan 39 kolom yang tidak menempati posisi pertama pada indeks
 * mana pun. Kolom tersebut dipakai sebagai penghubung antar tabel (transaksi,
 * jurnal, rekening, anggota), sehingga tanpa indeks setiap penelusuran relasi
 * berubah menjadi full table scan begitu data bertumbuh.
 *
 * DARI 39 KANDIDAT, HANYA 18 YANG DITAMBAHKAN. Sisanya sengaja dilewati:
 *
 *  - `kebijakan_koperasis` (14 kolom `account_*_id`): tabel parameter yang
 *    hanya berisi SATU baris. Indeks tidak mempercepat apa pun dan hanya
 *    menambah biaya tulis saat kebijakan diperbarui.
 *  - `notifications.notifiable_id` dan `audit_perubahan_pii.model_id`:
 *    sudah menjadi kolom kedua pada indeks polimorfik `(type, id)`; kolom
 *    tersebut tidak pernah difilter sendirian.
 *  - `*.hash_sebelumnya_id` (4 kolom): hanya dipakai saat verifikasi rantai
 *    hash, yang membaca baris berurutan lalu membandingkan nilainya di PHP —
 *    bukan lewat klausa WHERE.
 *  - `autodebit_usulans.referensi_id`: selalu difilter bersama `kanal`, dan
 *    indeks komposit `(kanal, referensi_id)` sudah melayani pola itu.
 *
 * Sifat migrasi: aditif, idempoten (melewati indeks yang sudah ada), dan
 * tidak mengubah data maupun struktur kolom.
 */
return new class extends Migration
{
    /**
     * Definisi indeks: tabel => daftar kolom (satu indeks per kolom).
     *
     * @var array<string, list<string>>
     */
    private array $indeks = [
        'approval_transaksi' => ['referensi_id'],
        'autodebit_usulans' => ['transaksi_id'],
        'deposito_products' => ['penalty_coa_id'],
        'mandatory_savings_bills' => ['transaksi_id', 'jurnal_id'],
        'member_terminations' => ['transaksi_id', 'jurnal_id'],
        'pembiayaan' => ['bank_account_id', 'savings_account_id', 'deposito_id'],
        'pengeluaran_beban' => ['jurnal_id'],
        'shu_distributions' => ['anggota_id', 'rekening_id', 'transaksi_id'],
        'shu_periods' => ['jurnal_id', 'user_id'],
        'simpanan_wajib_tindakans' => ['user_id'],
        'transaksi' => ['approval_id'],
    ];

    public function up(): void
    {
        foreach ($this->indeks as $tabel => $kolom) {
            foreach ($kolom as $k) {
                $this->buatIndeksBilaBelumAda($this->namaIndeks($tabel, $k), $tabel, [$k]);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indeks as $tabel => $kolom) {
            foreach ($kolom as $k) {
                $this->hapusIndeksBilaAda($this->namaIndeks($tabel, $k));
            }
        }
    }

    private function namaIndeks(string $tabel, string $kolom): string
    {
        return $tabel.'_'.$kolom.'_index';
    }

    /**
     * @param  list<string>  $kolom
     */
    private function buatIndeksBilaBelumAda(string $nama, string $tabel, array $kolom): void
    {
        if (! Schema::hasTable($tabel)) {
            return;
        }

        foreach ($kolom as $k) {
            if (! Schema::hasColumn($tabel, $k)) {
                return;
            }
        }

        if ($this->indeksAda($nama, $tabel)) {
            return;
        }

        $daftar = implode(', ', array_map(fn (string $k): string => "`{$k}`", $kolom));

        $dialek = match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => "ALTER TABLE `{$tabel}` ADD INDEX `{$nama}` ({$daftar})",
            'pgsql' => "CREATE INDEX \"{$nama}\" ON \"{$tabel}\" ({$daftar})",
            'sqlite' => "CREATE INDEX \"{$nama}\" ON \"{$tabel}\" ({$daftar})",
            default => null,
        };

        if ($dialek !== null) {
            DB::statement($dialek);
        }
    }

    private function hapusIndeksBilaAda(string $nama): void
    {
        $driver = DB::connection()->getDriverName();

        try {
            if ($driver === 'mysql' || $driver === 'mariadb') {
                $tabel = DB::table('information_schema.STATISTICS')
                    ->whereRaw('TABLE_SCHEMA = DATABASE()')
                    ->where('INDEX_NAME', $nama)
                    ->value('TABLE_NAME');

                if ($tabel !== null) {
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

    private function indeksAda(string $nama, string $tabel): bool
    {
        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => DB::table('information_schema.STATISTICS')
                ->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', $tabel)
                ->where('INDEX_NAME', $nama)
                ->exists(),
            'sqlite' => DB::table('sqlite_master')
                ->where('type', 'index')
                ->where('name', $nama)
                ->exists(),
            'pgsql' => DB::table('pg_indexes')
                ->where('indexname', $nama)
                ->where('tablename', $tabel)
                ->exists(),
            default => false,
        };
    }
};
