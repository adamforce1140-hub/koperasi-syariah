<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Status keanggotaan `TERMINATED` + catatan audit exit pada tabel `anggota`.
 *
 * `TERMINATED` lebih spesifik daripada `INACTIVE`/`Keluar`: ia menandakan
 * anggota telah MENYELESAIKAN proses exit (seluruh dana Simpanan Pokok &
 * Wajib dikembalikan dan jurnal PSAK 108 diposting), bukan sekadar pasif.
 *
 * Alias `Keluar` DIPERTAHANKAN agar data & view lama tetap terbaca, dan
 * dipertemukan ke makna kanonik `TERMINATED` melalui `Anggota::STATUS_ALIASES`.
 *
 * Kolom audit (tanggal pengunduran diri, total dana dikembalikan, user yang
 * memproses) ditambahkan untuk memenuhi jejak audit internal & eksternal
 * (UU No. 25/1992 Pasal 30, POJK No. 76/2016).
 *
 * Menambah SATU nilai baru di AKHIR daftar enum aman dilakukan langsung
 * (tidak mengubah/menghapus nilai lama), sehingga tidak ada data yang
 * terpotong — berbeda dengan migrasi 2026_09_24_000100 yang mengganti nilai.
 */
return new class extends Migration
{
    /**
     * Seluruh nilai sah: kanonik + alias legacy + status baru TERMINATED.
     *
     * @var list<string>
     */
    private const ENUM_KOMPATIBEL = [
        'ACTIVE', 'PENDING', 'INACTIVE', 'SUSPENDED', 'Keluar', 'TERMINATED',
        'Aktif', 'Tidak Aktif', 'Nonaktif', 'Dibekukan',
    ];

    public function up(): void
    {
        if (Schema::hasColumn('anggota', 'status_anggota')) {
            $daftar = "'".implode("','", self::ENUM_KOMPATIBEL)."'";
            DB::statement("ALTER TABLE `anggota` MODIFY `status_anggota` ENUM({$daftar}) NOT NULL DEFAULT 'PENDING'");
        }

        Schema::table('anggota', function (Blueprint $table) {
            if (! Schema::hasColumn('anggota', 'tanggal_terminasi')) {
                // Tanggal resmi pengunduran diri / exit keanggotaan.
                $table->date('tanggal_terminasi')->nullable()->after('tanggal_keluar');
            }

            if (! Schema::hasColumn('anggota', 'total_dana_dikembalikan')) {
                // Akumulasi Simpanan Pokok + Wajib yang dikembalikan saat exit.
                $table->decimal('total_dana_dikembalikan', 15, 2)->nullable()->after('tanggal_terminasi');
            }

            if (! Schema::hasColumn('anggota', 'terminasi_diproses_oleh')) {
                // User yang mengeksekusi pengembalian dana (jejak audit).
                $table->unsignedBigInteger('terminasi_diproses_oleh')->nullable()->after('total_dana_dikembalikan');
            }
        });
    }

    public function down(): void
    {
        Schema::table('anggota', function (Blueprint $table) {
            foreach (['terminasi_diproses_oleh', 'total_dana_dikembalikan', 'tanggal_terminasi'] as $kolom) {
                if (Schema::hasColumn('anggota', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });

        if (Schema::hasColumn('anggota', 'status_anggota')) {
            $lama = ['ACTIVE', 'PENDING', 'INACTIVE', 'SUSPENDED', 'Keluar',
                'Aktif', 'Tidak Aktif', 'Nonaktif', 'Dibekukan'];
            $daftar = "'".implode("','", $lama)."'";
            DB::statement("ALTER TABLE `anggota` MODIFY `status_anggota` ENUM({$daftar}) NOT NULL DEFAULT 'PENDING'");
        }
    }
};
