<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * KOREKSI DATA: Margin Murabahah Ditangguhkan yang belum diposting (PSAK 108).
 *
 * LATAR BELAKANG
 * --------------
 * Sebelum perbaikan, `DemoDataSeeder` memanggil `jurnalPencairan()` TANPA
 * argumen ke-9 (`$margin`), sehingga jurnal pencairan akad Murabahah terbentuk
 * hanya 2 baris:
 *
 *     D 1-2001 Piutang Pembiayaan Murabahah   = pokok            (10.000.000)
 *     K 1-1001 Kas Teller                     = pokok            (10.000.000)
 *
 * Padahal harga jual akad murabahah = pokok + margin. Akibatnya:
 *   1. Piutang ter-*understate* sebesar margin (PSAK 108: piutang murabahah
 *      diukur sebesar harga jual, bukan biaya perolehan).
 *   2. Akun `2-4001` Margin Murabahah Ditangguhkan tetap 0, sehingga tidak ada
 *      dasar amortisasi ke Pendapatan Margin Murabahah (4-1001) per angsuran.
 *   3. Neraca tidak dapat menyajikan piutang neto (piutang − margin ditangguhkan).
 *
 * Migrasi ini MENGOREKSI jurnal yang sudah ada agar konsisten dengan seeder
 * yang telah diperbaiki. Hanya menyentuh jurnal yang benar-benar cacat.
 *
 * CAKUPAN — SANGAT TERBATAS
 * -------------------------
 *  1. Hanya jurnal ber-sumber 'Pencairan Pembiayaan' dengan `pembiayaan_id`
 *     NOT NULL dan `akad_pembiayaan` = 'Murabahah' (via relasi `pembiayaan`).
 *  2. Hanya akun `1-2001` (debit) dan `2-4001` (kredit baru) yang disentuh.
 *  3. **4 akun piutang non-Murabahah (1-2002/1-2003/1-2004/1-2005) TIDAK
 *     PERNAH disentuh** — akad tersebut tidak punya akun Margin Ditangguhkan
 *     (lihat App\Support\Coa::MARGIN_DITANGGUHKAN_BY_AKAD), sehingga margin
 *     yang dipaksakan akan membatalkan seluruh jurnalnya.
 *
 * IDEMPOTEN
 * ---------
 * Jurnal yang sudah memiliki baris `coa_id = 2-4001` dilewati. Nomor urut baris
 * dihitung dari MAX(baris) yang ada, sehingga aman dieksekusi berulang.
 *
 * ROLLBACK
 * --------
 * `down()` mencari baris `2-4001` yang PERSIS dibuat migrasi ini — dikenali
 * dari `keterangan = 'Margin Murabahah Ditangguhkan (koreksi migrasi)'` — lalu
 * menghapusnya sambil mengurangi debet `1-2001` dan total header dengan nominal
 * yang sama. Baris margin yang dibuat oleh alur normal (bukan migrasi) tidak
 * ikut terhapus.
 */
return new class extends Migration
{
    private const KODE_PIUTANG = '1-2001';

    private const KODE_MARGIN = '2-4001';

    private const KETERANGAN_MARGIN = 'Margin Murabahah Ditangguhkan (koreksi migrasi)';

    private const BATAS_TOLERANSI = 0.01;

    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('jurnal')
            || ! DB::getSchemaBuilder()->hasTable('jurnal_detail')
            || ! DB::getSchemaBuilder()->hasTable('coas')) {
            return;
        }

        $akunPiutang = DB::table('coas')->where('kode_akun', self::KODE_PIUTANG)->first();
        $akunMargin = DB::table('coas')->where('kode_akun', self::KODE_MARGIN)->first();

        if (! $akunPiutang || ! $akunMargin) {
            Log::warning('Koreksi margin ditangguhkan dilewati: akun '
                .self::KODE_PIUTANG.' atau '.self::KODE_MARGIN.' tidak ditemukan.');

            return;
        }

        $kandidat = $this->kandidatJurnal($akunMargin->id);

        if ($kandidat->isEmpty()) {
            return;
        }

        $diperbaiki = [];

        foreach ($kandidat as $jurnal) {
            $margin = $this->marginUntuk($jurnal);

            if ($margin <= 0) {
                continue;
            }

            DB::transaction(function () use ($jurnal, $margin, $akunPiutang, $akunMargin, &$diperbaiki) {
                // 1. Naikkan debet piutang Murabahah sebesar margin.
                DB::table('jurnal_detail')
                    ->where('jurnal_id', $jurnal->id)
                    ->where('coa_id', $akunPiutang->id)
                    ->update(['debet' => DB::raw('debet + '.$this->literal($margin))]);

                // 2. Sisipkan baris kredit margin ditangguhkan.
                $barisTerakhir = (int) DB::table('jurnal_detail')
                    ->where('jurnal_id', $jurnal->id)->max('baris');

                DB::table('jurnal_detail')->insert([
                    'jurnal_id' => $jurnal->id,
                    'coa_id' => $akunMargin->id,
                    'akun_id' => null,
                    'baris' => $barisTerakhir + 1,
                    'debet' => 0,
                    'kredit' => $margin,
                    'keterangan' => self::KETERANGAN_MARGIN,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // 3. Selaraskan total header dengan baris detail yang sebenarnya.
                $debet = (float) DB::table('jurnal_detail')->where('jurnal_id', $jurnal->id)->sum('debet');
                $kredit = (float) DB::table('jurnal_detail')->where('jurnal_id', $jurnal->id)->sum('kredit');

                DB::table('jurnal')->where('id', $jurnal->id)->update([
                    'total_debet' => $debet,
                    'total_kredit' => $kredit,
                    'is_balance' => abs($debet - $kredit) < self::BATAS_TOLERANSI,
                    'updated_at' => now(),
                ]);

                $diperbaiki[] = $jurnal->id;

                Log::info('Koreksi margin ditangguhkan PSAK 108: jurnal #'.$jurnal->id
                    .' ('.$jurnal->no_jurnal.') ditambah kredit '.number_format($margin, 2, ',', '.'));
            });
        }

        if (! empty($diperbaiki)) {
            Log::info('Koreksi margin ditangguhkan selesai: '.count($diperbaiki)
                .' jurnal diperbaiki — #'.implode(', #', $diperbaiki));
        }
    }

    public function down(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('jurnal')
            || ! DB::getSchemaBuilder()->hasTable('jurnal_detail')
            || ! DB::getSchemaBuilder()->hasTable('coas')) {
            return;
        }

        $akunPiutang = DB::table('coas')->where('kode_akun', self::KODE_PIUTANG)->first();
        $akunMargin = DB::table('coas')->where('kode_akun', self::KODE_MARGIN)->first();

        if (! $akunPiutang || ! $akunMargin) {
            return;
        }

        // Hanya baris yang diciptakan migrasi ini (dikenali dari keterangannya).
        $barisMigrasi = DB::table('jurnal_detail')
            ->where('coa_id', $akunMargin->id)
            ->where('keterangan', self::KETERANGAN_MARGIN)
            ->get(['id', 'jurnal_id', 'kredit']);

        foreach ($barisMigrasi as $baris) {
            DB::transaction(function () use ($baris, $akunPiutang) {
                $margin = (float) $baris->kredit;

                DB::table('jurnal_detail')->where('id', $baris->id)->delete();

                DB::table('jurnal_detail')
                    ->where('jurnal_id', $baris->jurnal_id)
                    ->where('coa_id', $akunPiutang->id)
                    ->update(['debet' => DB::raw('GREATEST(debet - '.$this->literal($margin).', 0)')]);

                $debet = (float) DB::table('jurnal_detail')->where('jurnal_id', $baris->jurnal_id)->sum('debet');
                $kredit = (float) DB::table('jurnal_detail')->where('jurnal_id', $baris->jurnal_id)->sum('kredit');

                DB::table('jurnal')->where('id', $baris->jurnal_id)->update([
                    'total_debet' => $debet,
                    'total_kredit' => $kredit,
                    'is_balance' => abs($debet - $kredit) < self::BATAS_TOLERANSI,
                    'updated_at' => now(),
                ]);
            });
        }
    }

    /**
     * Jurnal pencairan akad Murabahah yang BELUM punya baris margin ditangguhkan.
     *
     * @return Collection<int, object>
     */
    private function kandidatJurnal(int $coaMarginId)
    {
        return DB::table('jurnal as j')
            ->join('pembiayaan as p', 'p.id', '=', 'j.pembiayaan_id')
            ->where('j.sumber', 'Pencairan Pembiayaan')
            ->where('j.status', 'Posted')
            ->whereNull('j.deleted_at')
            ->whereNotNull('j.pembiayaan_id')
            ->whereRaw('LOWER(p.akad_pembiayaan) = ?', ['murabahah'])
            ->whereNotExists(function ($q) use ($coaMarginId) {
                $q->select(DB::raw(1))
                    ->from('jurnal_detail as d')
                    ->whereColumn('d.jurnal_id', 'j.id')
                    ->where('d.coa_id', $coaMarginId);
            })
            ->get(['j.id', 'j.no_jurnal', 'j.total_debet', 'j.total_kredit', 'p.jumlah_pembiayaan', 'p.total_pembiayaan']);
    }

    /**
     * Margin = total_pembiayaan − jumlah_pembiayaan (harga jual − pokok).
     * `total_pembiayaan` adalah sumber otoritatif; kolom
     * `margin_deferred_amount` belum terisi pada data historis.
     */
    private function marginUntuk(object $jurnal): float
    {
        $margin = (float) $jurnal->total_pembiayaan - (float) $jurnal->jumlah_pembiayaan;

        return $margin > 0 ? round($margin, 2) : 0.0;
    }

    /** Literal numerik aman untuk ekspresi SQL (hindari binding pada DB::raw). */
    private function literal(float $nilai): string
    {
        return number_format($nilai, 2, '.', '');
    }
};
