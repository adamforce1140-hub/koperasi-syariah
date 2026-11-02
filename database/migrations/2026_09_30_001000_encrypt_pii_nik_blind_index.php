<?php

use App\Support\PiiHasher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TAHAP 10 — ENKRIPSI DATA PRIBADI (PII) + BLIND INDEX.
 *
 * Kepatuhan: UU No. 27 Tahun 2022 tentang Pelindungan Data Pribadi (UU PDP),
 * Pasal 35 & 39 (jaminan teknis perlindungan data) dan Pasal 40 (pembatasan
 * akses). NIK adalah data pribadi bersifat spesifik/identitas sehingga wajib
 * dilindungi dengan pengamanan teknis, bukan hanya kontrol akses aplikasi.
 *
 * Masalah
 * -------
 * Sebelum migrasi ini, `anggota.nik` dan `pengajuan_rekening.nik` tersimpan
 * sebagai teks biasa (plaintext). Siapa pun yang memperoleh dump basis data
 * (mis. berkas backup bocor, akses baca langsung ke MySQL) langsung membaca
 * seluruh NIK tanpa perlu menembus aplikasi.
 *
 * Perubahan
 * ---------
 *   1. Kolom `nik` diperlebar menjadi TEXT dan disimpan dalam bentuk
 *      ciphertext (cast `encrypted` pada model, AES-256-CBC + MAC, IV acak).
 *   2. Ditambahkan kolom `nik_hash` — "blind index" berisi HMAC-SHA256
 *      deterministik (hex 64 karakter) dari NIK yang telah dinormalisasi.
 *      Kolom ini menggantikan peran `nik` untuk:
 *        - constraint keunikan (`anggota_nik_hash_unique`), dan
 *        - pencarian tepat (`WHERE nik_hash = ?`).
 *   3. Seluruh baris lama di-backfill: NIK plaintext dienkripsi di tempat
 *      dan blind index-nya dihitung.
 *
 * Mengapa harus ada kolom terpisah?
 * ---------------------------------
 * Ciphertext bersifat NON-DETERMINISTIK (IV acak), sehingga kolom `nik` tidak
 * lagi dapat dipakai untuk `unique()` maupun `WHERE nik = ?`. Blind index
 * memulihkan kedua kemampuan tersebut tanpa mengorbankan kerahasiaan, karena
 * HMAC tidak dapat dibalik tanpa `APP_KEY`. Lihat `App\Support\PiiHasher`.
 *
 * Sifat migrasi
 * -------------
 * - IDEMPOTEN: kolom/indeks hanya dibuat bila belum ada; baris yang sudah
 *   terenkripsi tidak dienkripsi ulang (dideteksi lewat `Crypt::decryptString`).
 * - SADAR-DRIVER: perlebaran kolom memakai Schema Builder (didukung MySQL,
 *   PostgreSQL, dan SQLite) sehingga aman pada basis data pengembangan.
 * - Aman dijalankan pada basis data berisi (diproses per 200 baris).
 *
 * CATATAN OPERASIONAL — rotasi APP_KEY
 * ------------------------------------
 * `APP_KEY` dipakai sebagai kunci enkripsi sekaligus kunci HMAC blind index.
 * Mengganti `APP_KEY` pada instalasi yang sudah berisi data akan membuat
 * seluruh NIK lama TIDAK TERBACA dan blind index tidak lagi cocok. Prosedur
 * rotasi ada di docs/TAHAP10-KEAMANAN-DATA-UU-PDP.md.
 */
return new class extends Migration
{
    /**
     * Tabel yang memiliki kolom `nik` beserta sifat indeks blind index-nya.
     *
     * `unik = true`  → anggota: satu NIK hanya boleh dimiliki satu anggota
     *                  (menggantikan `anggota_nik_unique`).
     * `unik = false` → pengajuan_rekening: keunikan ditegakkan di lapisan
     *                  validasi (perilaku lama dipertahankan) agar baris
     *                  historis yang mungkin sudah duplikat tidak membuat
     *                  migrasi gagal.
     *
     * @var array<string, array{unik: bool, nama_indeks: string}>
     */
    private array $tabel = [
        'anggota' => [
            'unik' => true,
            'nama_indeks' => 'anggota_nik_hash_unique',
        ],
        'pengajuan_rekening' => [
            'unik' => false,
            'nama_indeks' => 'pengajuan_rekening_nik_hash_index',
        ],
    ];

    public function up(): void
    {
        foreach ($this->tabel as $tabel => $konfigurasi) {
            if (! Schema::hasTable($tabel) || ! Schema::hasColumn($tabel, 'nik')) {
                continue;
            }

            // 1. Lepas unique index lama pada `nik`. Constraint ini tidak lagi
            //    bermakna karena ciphertext berbeda untuk nilai yang sama.
            $this->hapusIndeksBilaAda($tabel.'_nik_unique');

            // 2. Perlebar `nik` agar mampu menampung ciphertext (tetap NOT NULL).
            if (! $this->kolomSudahTeks($tabel, 'nik')) {
                Schema::table($tabel, function (Blueprint $table) {
                    $table->text('nik')->nullable(false)->change();
                });
            }

            // 3. Tambah kolom blind index (nullable agar baris tanpa NIK tetap sah).
            if (! Schema::hasColumn($tabel, 'nik_hash')) {
                Schema::table($tabel, function (Blueprint $table) {
                    $table->string('nik_hash', 64)->nullable()->after('nik');
                });
            }

            // 4. Indeks pendukung keunikan / pencarian tepat.
            $this->buatIndeksBilaBelumAda(
                $konfigurasi['nama_indeks'],
                $tabel,
                ['nik_hash'],
                $konfigurasi['unik']
            );
        }

        // 5. Backfill data lama: enkripsi NIK di tempat + hitung blind index.
        $this->backfill();
    }

    public function down(): void
    {
        // Kembalikan NIK ke bentuk plaintext sebelum kolom `nik_hash` dibuang,
        // agar model tanpa cast `encrypted` tetap dapat membaca data.
        $this->kembalikanKePlaintext();

        foreach ($this->tabel as $tabel => $konfigurasi) {
            if (! Schema::hasTable($tabel)) {
                continue;
            }

            $this->hapusIndeksBilaAda($konfigurasi['nama_indeks']);

            if (Schema::hasColumn($tabel, 'nik_hash')) {
                Schema::table($tabel, function (Blueprint $table) {
                    $table->dropColumn('nik_hash');
                });
            }

            if ($this->kolomSudahTeks($tabel, 'nik')) {
                Schema::table($tabel, function (Blueprint $table) {
                    $table->string('nik', 16)->nullable(false)->change();
                });
            }
        }

        // Pulihkan constraint keunikan lama pada `anggota.nik`.
        $this->buatIndeksBilaBelumAda('anggota_nik_unique', 'anggota', ['nik'], true);
    }

    /* =====================================================================
     | BACKFILL
     |===================================================================== */

    /**
     * Enkripsi seluruh NIK plaintext dan hitung blind index-nya.
     *
     * Idempoten: nilai yang sudah berupa ciphertext (berhasil didekripsi)
     * tidak dienkripsi ulang — hanya blind index-nya yang dipastikan terisi.
     */
    private function backfill(): void
    {
        foreach (array_keys($this->tabel) as $tabel) {
            if (! Schema::hasTable($tabel) || ! Schema::hasColumn($tabel, 'nik_hash')) {
                continue;
            }

            DB::table($tabel)
                ->select('id', 'nik')
                ->orderBy('id')
                ->chunkById(200, function ($baris) use ($tabel) {
                    foreach ($baris as $row) {
                        $nik = $this->nikPlaintext($row->nik);

                        if ($nik === null) {
                            continue;
                        }

                        DB::table($tabel)->where('id', $row->id)->update([
                            'nik' => Crypt::encryptString($nik),
                            'nik_hash' => PiiHasher::hash($nik),
                        ]);
                    }
                });
        }
    }

    /**
     * Kembalikan kolom `nik` ke plaintext (dipakai oleh down()).
     *
     * Nilai dipotong 16 karakter agar muat pada skema lama `varchar(16)`.
     */
    private function kembalikanKePlaintext(): void
    {
        foreach (array_keys($this->tabel) as $tabel) {
            if (! Schema::hasTable($tabel) || ! Schema::hasColumn($tabel, 'nik')) {
                continue;
            }

            DB::table($tabel)
                ->select('id', 'nik')
                ->orderBy('id')
                ->chunkById(200, function ($baris) use ($tabel) {
                    foreach ($baris as $row) {
                        $nik = $this->nikPlaintext($row->nik);

                        if ($nik === null) {
                            continue;
                        }

                        DB::table($tabel)->where('id', $row->id)->update([
                            'nik' => substr($nik, 0, 16),
                        ]);
                    }
                });
        }
    }

    /**
     * Nilai NIK asli (plaintext) dari isi kolom `nik` apa pun bentuknya.
     *
     * Mengembalikan null untuk kolom kosong. Bila isi kolom BUKAN ciphertext
     * yang sah (mis. baris yang belum pernah dienkripsi), nilai dikembalikan
     * apa adanya sehingga backfill tetap dapat memprosesnya.
     */
    private function nikPlaintext(?string $isi): ?string
    {
        if ($isi === null || $isi === '') {
            return null;
        }

        try {
            return Crypt::decryptString($isi);
        } catch (Throwable $e) {
            return $isi;
        }
    }

    /* =====================================================================
     | UTILITAS SKEMA
     |===================================================================== */

    /** True bila kolom sudah bertipe TEXT (atau turunannya). */
    private function kolomSudahTeks(string $tabel, string $kolom): bool
    {
        try {
            return strtolower((string) Schema::getColumnType($tabel, $kolom)) === 'text';
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Buat indeks (unik atau biasa) secara idempoten dan sadar-driver.
     *
     * @param  list<string>  $kolom
     */
    private function buatIndeksBilaBelumAda(
        string $nama,
        string $tabel,
        array $kolom,
        bool $unik
    ): void {
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

        $driver = DB::connection()->getDriverName();
        $daftar = implode(', ', array_map(fn (string $k): string => "`{$k}`", $kolom));
        $jenis = $unik ? 'UNIQUE INDEX' : 'INDEX';

        $dialek = match ($driver) {
            'mysql', 'mariadb' => "ALTER TABLE `{$tabel}` ADD {$jenis} `{$nama}` ({$daftar})",
            'pgsql' => 'CREATE '.($unik ? 'UNIQUE ' : '')."INDEX \"{$nama}\" ON \"{$tabel}\" ({$daftar})",
            'sqlite' => 'CREATE '.($unik ? 'UNIQUE ' : '')."INDEX \"{$nama}\" ON \"{$tabel}\" ({$daftar})",
            default => null,
        };

        if ($dialek === null) {
            return;
        }

        DB::statement($dialek);
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
