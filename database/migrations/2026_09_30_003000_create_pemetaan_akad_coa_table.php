<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R12 — PEMETAAN AKAD → AKUN COA BERBASIS DATA (fleksibilitas PSAK).
 *
 * Sebelumnya pemetaan akad pembiayaan ke akun piutang / pendapatan / margin
 * ditangguhkan ditanam sebagai konstanta PHP di `App\Support\Coa`
 * (`PIUTANG_BY_AKAD`, `PENDAPATAN_BY_AKAD`, `MARGIN_DITANGGUHKAN_BY_AKAD`).
 * Akibatnya, menambah akad syariah baru (mis. akad turunan atau akad yang
 * baru difatwakan DSN-MUI) selalu menuntut perubahan kode sumber dan deploy.
 *
 * Tabel ini memindahkan pemetaan tersebut ke basis data sehingga Pengurus
 * dapat memetakan akad ke akun CoA lewat menu Kebijakan Koperasi tanpa
 * mengubah kode. `App\Support\Coa` tetap menjadi fallback standar
 * (zero-hardcoding: DB dulu, registri standar bila kosong).
 *
 * `kode_produk` = '*' berarti "berlaku untuk semua produk pada akad ini",
 * sehingga pengurus tidak wajib memetakan per produk.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pemetaan_akad_coa')) {
            return;
        }

        Schema::create('pemetaan_akad_coa', function (Blueprint $table) {
            $table->id();

            $table->string('kode_produk', 30)->default('*')
                ->comment("Kode produk (master_produk_pembiayaan.kode_produk); '*' = berlaku semua produk pada akad ini");

            $table->string('akad_pembiayaan', 50);

            $table->unsignedBigInteger('account_piutang_id')->nullable()
                ->comment('Akun CoA piutang/penyertaan (PSAK 105/106/107/108/109)');

            $table->unsignedBigInteger('account_pendapatan_id')->nullable()
                ->comment('Akun CoA pendapatan (margin/bagi hasil/ujrah); null = tanpa pendapatan (Qardh)');

            $table->unsignedBigInteger('account_margin_ditangguhkan_id')->nullable()
                ->comment('Akun CoA margin ditangguhkan (PSAK 108); null = tidak ditangguhkan');

            $table->boolean('status_aktif')->default(true);

            $table->string('catatan', 255)->nullable();

            $table->timestamps();

            $table->unique(['kode_produk', 'akad_pembiayaan'], 'uq_pemetaan_akad_coa');

            $table->index('akad_pembiayaan');

            $table->foreign('account_piutang_id')->references('id')->on('coas')->nullOnDelete();
            $table->foreign('account_pendapatan_id')->references('id')->on('coas')->nullOnDelete();
            $table->foreign('account_margin_ditangguhkan_id')->references('id')->on('coas')->nullOnDelete();
        });

        // ── Backfill: buatkan baris '*' untuk setiap akad kanonik ──────────
        // Nilai akun SENGAJA dibiarkan NULL: artinya "ikuti pemetaan standar
        // (kebijakan koperasi / registri App\Support\Coa)". Dengan begitu
        // migrasi ini 100% tidak mengubah perilaku jurnal yang sudah berjalan;
        // pengurus dapat mengisinya kapan saja lewat menu Kebijakan Koperasi.
        $akadKanonik = [
            'Murabahah',
            'Mudharabah',
            'Musyarakah',
            'Ijarah',
            'Ijarah Muntahia Bittamlik',
            'Qardh',
            'Istishna',
            'Salam',
        ];

        $sekarang = now();

        foreach ($akadKanonik as $namaAkad) {
            DB::table('pemetaan_akad_coa')->insert([
                'kode_produk' => '*',
                'akad_pembiayaan' => $namaAkad,
                'account_piutang_id' => null,
                'account_pendapatan_id' => null,
                'account_margin_ditangguhkan_id' => null,
                'status_aktif' => true,
                'catatan' => 'Pemetaan standar akad '.$namaAkad.' — kosong berarti mengikuti registri CoA bawaan.',
                'created_at' => $sekarang,
                'updated_at' => $sekarang,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pemetaan_akad_coa');
    }
};
