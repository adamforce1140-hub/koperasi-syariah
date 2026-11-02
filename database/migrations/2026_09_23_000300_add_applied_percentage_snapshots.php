<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot persentase pada akad/transaksi (pencegahan korupsi data historis).
 *
 * Nilai nisbah & margin bersifat DINAMIS dan dapat diubah kapan saja lewat
 * Master COA. Tanpa snapshot, perubahan nilai master akan ikut mengubah
 * perhitungan akad lama — melanggar prinsip bahwa akad adalah kesepakatan
 * yang mengikat pada saat dibuat (PSAK 101/108).
 *
 * Karena itu setiap akad menyimpan SALINAN nilai yang berlaku saat transaksi
 * dibuat:
 *  - `applied_percentage_member`      : Nisbah Anggota / Rate Margin saat akad
 *  - `applied_percentage_institution` : Nisbah Koperasi saat akad
 *
 * Catatan tabel: spesifikasi menyebut `savings_accounts`, `deposito_accounts`,
 * dan `financing_accounts`. Nama tabel nyata di proyek ini adalah `rekening`
 * (simpanan), `depositos` (simpanan berjangka), dan `pembiayaan` — tabel
 * tersebut yang dipakai seluruh aplikasi sehingga kolom ditambahkan di sana
 * agar tidak terjadi duplikasi data.
 */
return new class extends Migration
{
    /** Tabel akad beserta kolom acuan posisi penempatan kolom baru. */
    private const TABEL = [
        'rekening' => 'locked_reason',
        'depositos' => 'status',
        'pembiayaan' => 'status_kolektibilitas',
    ];

    public function up(): void
    {
        foreach (self::TABEL as $tabel => $setelah) {
            Schema::table($tabel, function (Blueprint $table) use ($tabel, $setelah) {
                if (! Schema::hasColumn($tabel, 'applied_percentage_member')) {
                    $table->decimal('applied_percentage_member', 5, 2)
                        ->nullable()
                        ->after($setelah);
                }

                if (! Schema::hasColumn($tabel, 'applied_percentage_institution')) {
                    $table->decimal('applied_percentage_institution', 5, 2)
                        ->nullable()
                        ->after('applied_percentage_member');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABEL) as $tabel) {
            Schema::table($tabel, function (Blueprint $table) use ($tabel) {
                foreach (['applied_percentage_institution', 'applied_percentage_member'] as $kolom) {
                    if (Schema::hasColumn($tabel, $kolom)) {
                        $table->dropColumn($kolom);
                    }
                }
            });
        }
    }
};
