<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TAHAP 5 — HARDENING FOREIGN KEY (INTEGRITAS DATA).
 *
 * TEMUAN
 * ------
 * Beberapa foreign key memakai `cascadeOnDelete()` pada relasi yang menyimpan
 * BUKTI AKUNTANSI / JEJAK OPERASIONAL. Menghapus satu baris induk (user,
 * pembiayaan, jurnal) akan MENGHAPUS baris anak tanpa peringatan:
 *
 *   - teller_opnames.user_id   -> opname kas fisik hilang bila user dihapus
 *   - angsuran.pembiayaan_id   -> jadwal angsuran hilang bila pembiayaan dihapus
 *   - jurnal_detail.jurnal_id  -> baris buku besar hilang bila jurnal dihapus
 *
 * Untuk entitas akuntansi, hilangnya baris anak berarti laporan periode
 * sebelumnya berubah diam-diam — persis yang dilarang prinsip integritas data.
 *
 * PERBAIKAN
 * ---------
 * Ketiga relasi diubah menjadi `restrictOnDelete()`: penghapusan induk DITOLAK
 * selama masih ada baris anak, sehingga pemanggil wajib menyelesaikan
 * ketergantungannya secara eksplisit (soft delete / penghapusan anak dulu).
 *
 * `pengeluaran_beban` memakai `cascadeOnUpdate()` tanpa perilaku onDelete
 * eksplisit. `cascadeOnUpdate` pada PK auto-increment tidak pernah terpakai
 * dan menyamarkan maksud skema, jadi diganti `restrictOnDelete()` eksplisit.
 *
 * CATATAN: nama constraint dibaca dari information_schema (bukan diasumsikan
 * `{tabel}_{kolom}_foreign`) supaya migrasi tetap aman bila constraint pernah
 * dibuat dengan nama lain.
 */
return new class extends Migration
{
    /**
     * Definisi FK yang dirapikan.
     *
     * `tabel`, `kolom`, `induk`, `onDelete` (restrict|cascade), `cascadeUpdate`.
     *
     * @var array<int, array{tabel: string, kolom: string, induk: string, onDelete: string, cascadeUpdate: bool}>
     */
    private array $definisi = [
        ['tabel' => 'teller_opnames',    'kolom' => 'user_id',     'induk' => 'users',      'onDelete' => 'restrict', 'cascadeUpdate' => false],
        ['tabel' => 'angsuran',          'kolom' => 'pembiayaan_id', 'induk' => 'pembiayaan', 'onDelete' => 'restrict', 'cascadeUpdate' => false],
        ['tabel' => 'jurnal_detail',     'kolom' => 'jurnal_id',   'induk' => 'jurnal',     'onDelete' => 'restrict', 'cascadeUpdate' => false],
        ['tabel' => 'pengeluaran_beban', 'kolom' => 'created_by',  'induk' => 'users',      'onDelete' => 'restrict', 'cascadeUpdate' => false],
        ['tabel' => 'pengeluaran_beban', 'kolom' => 'coa_beban_id', 'induk' => 'coas',      'onDelete' => 'restrict', 'cascadeUpdate' => false],
        ['tabel' => 'pengeluaran_beban', 'kolom' => 'coa_kas_id',  'induk' => 'coas',       'onDelete' => 'restrict', 'cascadeUpdate' => false],
    ];

    public function up(): void
    {
        foreach ($this->definisi as $definisi) {
            $this->pasangUlang($definisi['tabel'], $definisi['kolom'], $definisi['induk'], 'restrict', false);
        }
    }

    public function down(): void
    {
        // Kembalikan perilaku semula: cascade delete untuk jejak operasional &
        // buku besar, cascadeOnUpdate tanpa onDelete untuk pengeluaran beban.
        foreach ($this->definisi as $definisi) {
            $asal = $definisi['tabel'] === 'pengeluaran_beban'
                ? ['onDelete' => null, 'cascadeUpdate' => true]
                : ['onDelete' => 'cascade', 'cascadeUpdate' => false];

            $this->pasangUlang($definisi['tabel'], $definisi['kolom'], $definisi['induk'], $asal['onDelete'], $asal['cascadeUpdate']);
        }
    }

    /**
     * Lepas foreign key lama (bila ada) lalu pasang kembali dengan perilaku baru.
     *
     * @param  string|null  $onDelete  'restrict' | 'cascade' | null (tanpa aksi eksplisit)
     */
    private function pasangUlang(string $tabel, string $kolom, string $induk, ?string $onDelete, bool $cascadeUpdate): void
    {
        if (! Schema::hasTable($tabel) || ! Schema::hasTable($induk) || ! Schema::hasColumn($tabel, $kolom)) {
            return;
        }

        $namaLama = $this->namaForeign($tabel, $kolom);

        if ($namaLama !== null) {
            Schema::table($tabel, function (Blueprint $table) use ($namaLama) {
                $table->dropForeign($namaLama);
            });
        }

        Schema::table($tabel, function (Blueprint $table) use ($kolom, $induk, $onDelete, $cascadeUpdate) {
            $fk = $table->foreign($kolom)->references('id')->on($induk);

            if ($cascadeUpdate) {
                $fk->cascadeOnUpdate();
            }

            if ($onDelete === 'restrict') {
                $fk->restrictOnDelete();
            } elseif ($onDelete === 'cascade') {
                $fk->cascadeOnDelete();
            }
        });
    }

    /**
     * Nama constraint foreign key yang aktif untuk kolom tertentu, atau null.
     */
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
