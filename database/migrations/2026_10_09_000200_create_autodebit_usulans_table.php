<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Usulan Auto-Debit (Pusat Konfirmasi) — kebijakan "tidak ada auto-debit
 * tanpa konfirmasi pengguna".
 *
 * Latar belakang (hasil audit peran): sebelum kebijakan ini, tiga kanal
 * memotong dana anggota secara MANDIRI tanpa persetujuan manusia:
 *   1. Simpanan Wajib   — command `mandatory-savings:process-daily`.
 *   2. Angsuran Pembiayaan — command `angsuran:run-autodebet`.
 *   3. Ta'zir Susulan    — dipicu setoran tunai / proses angsuran.
 *
 * Tabel ini menjadi SATU PINTU usulan: setiap kanal yang ingin memotong dana
 * lebih dulu MENULIS baris `MENUNGGU_KONFIRMASI` di sini (tanpa menyentuh
 * saldo). Pemotongan HANYA terjadi setelah pengguna berwenang menekan
 * konfirmasi, yang mengubah status baris menjadi `DIKONFIRMASI` (atau
 * `DITOLAK`). Dengan begitu tidak ada jalur yang memotong dana sebelum ada
 * jejak usulan + konfirmasi eksplisit.
 *
 * `kanal` menentukan aksi pemotongan saat dikonfirmasi (strategy per kanal).
 * `referensi_id` = id entitas sumber (mis. MandatorySavingsBill / Angsuran /
 * TazirPenangguhan) sehingga service kanal terkait dapat mengeksekusi ulang
 * pemotongan dengan aman dan idempoten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('autodebit_usulans')) {
            return;
        }

        Schema::create('autodebit_usulans', function (Blueprint $table) {
            $table->id();

            $table->string('kanal', 40);                    // SIMPANAN_WAJIB | ANGSURAN | TAZIR
            $table->unsignedBigInteger('referensi_id');     // id entitas sumber pada kanal
            $table->string('referensi_tipe', 120)->nullable(); // FQCN model sumber (opsional, audit)
            $table->unsignedBigInteger('anggota_id')->nullable();
            $table->unsignedBigInteger('rekening_id')->nullable(); // rekening sumber dana (Simpanan Sukarela)

            $table->decimal('nominal', 14, 2)->default(0);  // nominal yang akan dipotong
            $table->string('periode', 7)->nullable();       // periode tagihan (bila relevan)
            $table->string('alasan', 60)->nullable();       // alasan usulan (mis. MENUNGGU_KONFIRMASI)
            $table->text('catatan')->nullable();            // catatan tambahan / pesan sistem

            // Siklus hidup usulan.
            $table->string('status', 24)->default('MENUNGGU_KONFIRMASI');
            $table->unsignedBigInteger('confirmed_by')->nullable(); // user yang mengonfirmasi/menolak
            $table->dateTime('confirmed_at')->nullable();
            $table->text('confirmation_note')->nullable();

            // Jejak eksekusi pemotongan (setelah dikonfirmasi).
            $table->unsignedBigInteger('transaksi_id')->nullable();
            $table->dateTime('executed_at')->nullable();

            // Anti-duplikasi: satu usulan aktif per kanal+referensi.
            $table->string('signature', 191)->nullable();

            $table->timestamps();

            $table->foreign('anggota_id')->references('id')->on('anggota')->onDelete('cascade');
            $table->foreign('rekening_id')->references('id')->on('rekening')->onDelete('set null');
            $table->foreign('confirmed_by')->references('id')->on('users')->onDelete('set null');

            $table->unique('signature', 'autodebit_usulans_signature_unique');
            $table->index(['status', 'kanal'], 'autodebit_usulans_status_kanal_index');
            $table->index(['kanal', 'referensi_id'], 'autodebit_usulans_kanal_ref_index');
            $table->index(['anggota_id', 'status'], 'autodebit_usulans_anggota_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autodebit_usulans');
    }
};
