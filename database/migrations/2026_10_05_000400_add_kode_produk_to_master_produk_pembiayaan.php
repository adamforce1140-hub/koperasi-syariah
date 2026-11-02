<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KODE PRODUK PEMBIAYAAN — melengkapi pemetaan Akad → CoA PER PRODUK.
 *
 * Tabel `pemetaan_akad_coa` sudah menyediakan kolom `kode_produk`
 * ('*' = berlaku semua produk pada akad tersebut) sehingga pengurus dapat
 * mengarahkan satu produk ke akun piutang/pendapatan/margin-ditangguhkan
 * yang berbeda dari produk lain berakad sama. Namun sisi PRODUK belum
 * memiliki identitas kode sama sekali:
 *
 *   - `MasterProdukPembiayaan::generateKode()` merujuk kolom `kode_produk`
 *     yang tidak pernah dibuat (dead code yang akan error bila dipanggil),
 *   - sehingga nilai `kode_produk` selain '*' tidak dapat diisi pengurus,
 *     dan fitur pemetaan per-produk praktis tidak dapat dipakai.
 *
 * Migrasi aditif ini menambahkan kolom `kode_produk` (unik) dan mengisi
 * (backfill) produk lama dengan kode deterministik `PRD-<INISIAL AKAD><NN>`
 * — mis. `PRD-MRB01`, `PRD-MDR01`. Backfill deterministik dipilih agar
 * kode produk tetap sama walau migrasi dijalankan pada basis data yang
 * sudah terisi (idempotent, tidak bergantung urutan id).
 */
return new class extends Migration
{
    /** Inisial akad → komponen kode produk. */
    private const INISIAL_AKAD = [
        'Murabahah' => 'MRB',
        'Mudharabah' => 'MDR',
        'Musyarakah' => 'MSY',
        'Ijarah' => 'IJR',
        'Ijarah Muntahia Bittamlik' => 'IMB',
        'Qardh' => 'QRD',
        'Istishna' => 'IST',
        'Salam' => 'SLM',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('master_produk_pembiayaan')) {
            return;
        }

        if (! Schema::hasColumn('master_produk_pembiayaan', 'kode_produk')) {
            Schema::table('master_produk_pembiayaan', function (Blueprint $table) {
                $table->string('kode_produk', 30)->nullable()->after('id')
                    ->comment('Kode unik produk; dirujuk pemetaan_akad_coa.kode_produk ("*" = semua produk)');
            });
        }

        $this->backfill();

        // Kode produk wajib unik; indeks dibuat setelah backfill agar kolom
        // lama tetap mendapat kode sebelum constraint dipasang.
        Schema::table('master_produk_pembiayaan', function (Blueprint $table) {
            $table->unique('kode_produk', 'uq_master_produk_kode');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('master_produk_pembiayaan')
            || ! Schema::hasColumn('master_produk_pembiayaan', 'kode_produk')) {
            return;
        }

        Schema::table('master_produk_pembiayaan', function (Blueprint $table) {
            $table->dropUnique('uq_master_produk_kode');
            $table->dropColumn('kode_produk');
        });
    }

    /**
     * Isi kode_produk untuk seluruh produk yang belum memilikinya.
     *
     * Penomoran urut per-akad mengikuti id (stabil & deterministik), dan
     * bentrok dengan kode yang sudah terpakai (mis. hasil pembuatan produk
     * baru sebelum migrasi) dihindari dengan melewati kode yang sudah ada.
     */
    private function backfill(): void
    {
        $produk = DB::table('master_produk_pembiayaan')
            ->orderBy('id')
            ->get(['id', 'akad_syariah', 'kode_produk']);

        $terpakai = [];

        foreach ($produk as $baris) {
            $kode = trim((string) ($baris->kode_produk ?? ''));

            if ($kode !== '') {
                $terpakai[$kode] = true;
            }
        }

        $urutPerAkad = [];

        foreach ($produk as $baris) {
            if (trim((string) ($baris->kode_produk ?? '')) !== '') {
                continue;
            }

            $inisial = self::INISIAL_AKAD[$baris->akad_syariah] ?? 'GEN';
            $nomor = ($urutPerAkad[$inisial] ?? 0) + 1;

            do {
                $kode = 'PRD-'.$inisial.str_pad((string) $nomor, 2, '0', STR_PAD_LEFT);
                $nomor++;
            } while (isset($terpakai[$kode]));

            $urutPerAkad[$inisial] = $nomor - 1;
            $terpakai[$kode] = true;

            DB::table('master_produk_pembiayaan')
                ->where('id', $baris->id)
                ->update(['kode_produk' => $kode]);
        }
    }
};
