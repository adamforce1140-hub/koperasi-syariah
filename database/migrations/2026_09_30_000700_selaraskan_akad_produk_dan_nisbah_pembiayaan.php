<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R11 — Selaraskan enum `master_produk_pembiayaan.akad_syariah` dengan 8 akad
 *       kanonik `Pembiayaan::daftarAkad()` (sebelumnya hanya 5 akad sehingga
 *       produk ber-akad IMBT/Istishna/Salam tidak dapat dibuat, padahal sistem
 *       sudah mendukung kontrak, CoA, dan pengakuannya).
 *
 * R10 — Tambahkan nisbah bagi hasil produk pembiayaan (`nisbah_anggota` /
 *       `nisbah_koperasi`) beserta validasi total 100% (PSAK 105/106), setara
 *       dengan `DepositoProduct::nisbahValid()`.
 *
 * Enum diubah menjadi VARCHAR + CHECK constraint agar penambahan akad baru
 * tidak lagi memerlukan `ALTER TABLE ... MODIFY ENUM`.
 */
return new class extends Migration
{
    /** Nilai enum lama sebelum diperluas. */
    private const AKAD_LAMA = ['Murabahah', 'Musyarakah', 'Mudharabah', 'Ijarah', 'Qardh'];

    /** 8 akad kanonik — WAJIB sama dengan App\Models\Pembiayaan::daftarAkad(). */
    private const AKAD_BARU = [
        'Murabahah',
        'Mudharabah',
        'Musyarakah',
        'Ijarah',
        'Ijarah Muntahia Bittamlik',
        'Qardh',
        'Istishna',
        'Salam',
    ];

    public function up(): void
    {
        // ── R11: enum 5 akad → varchar + CHECK 8 akad (MySQL 8 aman di ALTER) ──
        DB::statement('ALTER TABLE `master_produk_pembiayaan` MODIFY `akad_syariah` VARCHAR(50) NOT NULL');

        $daftar = implode("','", self::AKAD_BARU);
        DB::statement(
            'ALTER TABLE `master_produk_pembiayaan` '
            .'ADD CONSTRAINT `chk_produk_pembiayaan_akad_syariah` '
            ."CHECK (`akad_syariah` IN ('{$daftar}'))"
        );

        // ── R10: kolom nisbah bagi hasil produk pembiayaan ──
        Schema::table('master_produk_pembiayaan', function (Blueprint $table) {
            $table->decimal('nisbah_anggota', 6, 3)->nullable()->after('margin_persen')
                ->comment('Porsi bagi hasil anggota (%), hanya untuk akad bagi hasil');
            $table->decimal('nisbah_koperasi', 6, 3)->nullable()->after('nisbah_anggota')
                ->comment('Porsi bagi hasil koperasi (%), hanya untuk akad bagi hasil');
        });

        // ── R11: lengkapi akun legacy piutang untuk akad Istishna & Salam ──
        // (IMBT memakai akun Murabahah '121' karena berakhir kepemilikan.)
        $headerPembiayaanId = DB::table('akun')->where('kode_akun', '12')->value('id');

        foreach ([
            ['kode_akun' => '126', 'nama_akun' => 'Pembiayaan Istishna'],
            ['kode_akun' => '127', 'nama_akun' => 'Pembiayaan Salam'],
        ] as $akun) {
            if (! DB::table('akun')->where('kode_akun', $akun['kode_akun'])->exists()) {
                DB::table('akun')->insert([
                    'kode_akun' => $akun['kode_akun'],
                    'nama_akun' => $akun['nama_akun'],
                    'klasifikasi' => 'Aset',
                    'jenis_akun' => 'Detail',
                    'parent_id' => $headerPembiayaanId,
                    'saldo_normal' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('master_produk_pembiayaan', function (Blueprint $table) {
            $table->dropColumn(['nisbah_anggota', 'nisbah_koperasi']);
        });

        DB::statement('ALTER TABLE `master_produk_pembiayaan` DROP CHECK `chk_produk_pembiayaan_akad_syariah`');

        $lama = implode("','", self::AKAD_LAMA);
        // Nilai di luar daftar lama tidak dapat dikembalikan; normalisasi lebih dulu.
        DB::statement(
            "UPDATE `master_produk_pembiayaan` SET `akad_syariah` = 'Murabahah' "
            ."WHERE `akad_syariah` NOT IN ('{$lama}')"
        );
        DB::statement(
            'ALTER TABLE `master_produk_pembiayaan` MODIFY '
            ."`akad_syariah` ENUM('{$lama}') NOT NULL"
        );
    }
};
