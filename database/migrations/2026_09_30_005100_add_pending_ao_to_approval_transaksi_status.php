<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah tahap `PENDING_AO` pada ENUM status approval transaksi.
 *
 * Latar: rantai otorisasi yang sebenarnya berlaku adalah
 * Teller -> AO -> Manajer -> Pengurus, tetapi ENUM lama
 * ('PENDING_MANAJER','PENDING_PENGURUS','APPROVED','REJECTED') tidak punya
 * tempat untuk tahap AO — akibatnya pengajuan dari Teller TIDAK PERNAH
 * berhenti di AO dan langsung melompat ke Manajer.
 *
 * P4 "Default Aman": ENUM lama tetap dipertahankan seluruhnya, hanya satu
 * nilai baru yang ditambahkan. Baris yang sudah ada tidak diubah, sehingga
 * perilaku sistem tidak berubah sampai ada pengajuan baru dari Teller.
 * `PENDING_MANAJER` tetap bermakna sama (tahap Manajer) agar data lama dan
 * pengajuan dari AO tetap sah dibaca.
 */
return new class extends Migration
{
    private const STATUS_LAMA = ['PENDING_MANAJER', 'PENDING_PENGURUS', 'APPROVED', 'REJECTED'];

    private const STATUS_BARU = ['PENDING_AO', 'PENDING_MANAJER', 'PENDING_PENGURUS', 'APPROVED', 'REJECTED'];

    public function up(): void
    {
        if (! Schema::hasTable('approval_transaksi')) {
            return;
        }

        DB::statement(
            'ALTER TABLE approval_transaksi MODIFY COLUMN status '
            ."ENUM('PENDING_AO','PENDING_MANAJER','PENDING_PENGURUS','APPROVED','REJECTED') "
            ."NOT NULL DEFAULT 'PENDING_MANAJER'"
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('approval_transaksi')) {
            return;
        }

        // Baris PENDING_AO tidak punya padanan pada ENUM lama. Menyempitkan
        // ENUM begitu saja akan mengosongkan kolomnya, jadi baris tersebut
        // dinormalkan lebih dulu ke tahap Manajer agar tidak ada pengajuan
        // yang hilang dari daftar tugas pemutus.
        DB::table('approval_transaksi')
            ->where('status', 'PENDING_AO')
            ->update(['status' => 'PENDING_MANAJER']);

        DB::statement(
            'ALTER TABLE approval_transaksi MODIFY COLUMN status '
            ."ENUM('PENDING_MANAJER','PENDING_PENGURUS','APPROVED','REJECTED') "
            ."NOT NULL DEFAULT 'PENDING_MANAJER'"
        );
    }
};
