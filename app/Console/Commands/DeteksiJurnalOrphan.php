<?php

namespace App\Console\Commands;

use App\Support\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Deteksi jurnal orphan — catatan jurnal yang tidak memiliki referensi valid
 * atau terputus dari entitas induknya.
 *
 * Orphan tidak selalu membuat aplikasi gagal, tetapi membuat laporan keuangan
 * tidak dapat diaudit: baris biaya/pendapatan yang menggantung tanpa dokumen
 * sumber, atau header Posted yang tidak didukung bukti double-entry. Karena
 * itu jurnal orphan TIDAK boleh lolos diam-diam ke produksi.
 *
 * Kategori yang diperiksa:
 *   1. JURNAL_KOSONG         — header Posted tanpa satu pun baris detail.
 *   2. JURNAL_TIMBANG        — header Posted yang baris detailnya tidak balance.
 *   3. HEADER_TIDAK_SINKRON  — total/is_balance header ≠ jumlah baris detail.
 *   4. DETAIL_TANPA_INDUK    — baris detail tanpa jurnal induk (atau induknya terhapus).
 *   5. DETAIL_TANPA_COA      — baris detail tanpa akun CoA valid.
 *   6. DETAIL_KOSONG         — baris detail bernilai debet 0 dan kredit 0.
 *   7. DETAIL_DUA_SISI       — baris detail bernilai debet > 0 sekaligus kredit > 0.
 *
 * Perintah ini hanya MEMBACA data — tidak pernah mengubah apa pun. Exit code
 * FAILURE dikembalikan bila ditemukan orphan, sehingga dapat dipakai sebagai
 * gerbang pemeriksaan pada pipeline rilis.
 */
class DeteksiJurnalOrphan extends Command
{
    protected $signature = 'jurnal:deteksi-orphan
                            {--contoh=10 : Jumlah baris contoh yang ditampilkan per kategori}';

    protected $description = 'Deteksi jurnal orphan (tanpa baris detail, tidak balance, atau terputus dari induknya) beserta laporannya.';

    public function handle(): int
    {
        $toleransi = Setting::toleransiSelisihJurnal();
        $batasContoh = max(0, (int) $this->option('contoh'));

        $kategori = [
            'JURNAL_KOSONG' => $this->jurnalKosong(),
            'JURNAL_TIMBANG' => $this->jurnalTimbang($toleransi),
            'HEADER_TIDAK_SINKRON' => $this->headerTidakSinkron($toleransi),
            'DETAIL_TANPA_INDUK' => $this->detailTanpaInduk(),
            'DETAIL_TANPA_COA' => $this->detailTanpaCoa(),
            'DETAIL_KOSONG' => $this->detailKosong(),
            'DETAIL_DUA_SISI' => $this->detailDuaSisi(),
        ];

        $totalOrphan = collect($kategori)->sum(fn (Collection $baris) => $baris->count());

        $this->info('PEMERIKSAAN JURNAL ORPHAN');
        $this->line('Toleransi selisih debet-kredit: '.number_format($toleransi, 2, ',', '.'));
        $this->newLine();

        $this->table(
            ['Kategori', 'Jumlah', 'Keterangan'],
            collect($kategori)->map(fn (Collection $baris, string $nama) => [
                $nama,
                $baris->count(),
                $this->keteranganKategori($nama),
            ])->values()->all()
        );

        foreach ($kategori as $nama => $baris) {
            if ($baris->isEmpty()) {
                continue;
            }

            $this->newLine();
            $this->warn("{$nama} — {$baris->count()} baris:");
            $this->tampilkanContoh($nama, $baris->take($batasContoh));
        }

        $this->newLine();

        if ($totalOrphan === 0) {
            $this->info('Tidak ada jurnal orphan. Seluruh header Posted didukung baris detail yang seimbang.');

            return self::SUCCESS;
        }

        $this->error("Ditemukan {$totalOrphan} jurnal orphan. Periksa daftar di atas sebelum menutup periode.");

        return self::FAILURE;
    }

    /* =====================================================================
     | 1. HEADER TANPA BARIS DETAIL
     ===================================================================== */

    /** @return Collection<int, object> */
    private function jurnalKosong(): Collection
    {
        return DB::table('jurnal as j')
            ->whereNull('j.deleted_at')
            ->where('j.status', 'Posted')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('jurnal_detail as d')
                    ->whereColumn('d.jurnal_id', 'j.id');
            })
            ->orderBy('j.id')
            ->get(['j.id', 'j.no_jurnal', 'j.tanggal_jurnal', 'j.sumber']);
    }

    /* =====================================================================
     | 2 & 3. HEADER YANG TIDAK DIDUKUNG BARIS DETAILNYA
     ===================================================================== */

    /**
     * Ringkasan setiap jurnal Posted beserta agregat baris detailnya.
     * Satu query agregat — bukan N+1 — agar aman pada basis data besar.
     *
     * @return Collection<int, object>
     */
    private function ringkasanPosted(): Collection
    {
        return DB::table('jurnal as j')
            ->leftJoin('jurnal_detail as d', 'd.jurnal_id', '=', 'j.id')
            ->whereNull('j.deleted_at')
            ->where('j.status', 'Posted')
            ->groupBy(
                'j.id', 'j.no_jurnal', 'j.tanggal_jurnal', 'j.sumber',
                'j.total_debet', 'j.total_kredit', 'j.is_balance'
            )
            ->orderBy('j.id')
            ->get([
                'j.id',
                'j.no_jurnal',
                'j.tanggal_jurnal',
                'j.sumber',
                'j.total_debet',
                'j.total_kredit',
                'j.is_balance',
                DB::raw('COALESCE(SUM(d.debet), 0) as jumlah_debet'),
                DB::raw('COALESCE(SUM(d.kredit), 0) as jumlah_kredit'),
                DB::raw('COUNT(d.id) as jumlah_baris'),
            ]);
    }

    /** @return Collection<int, object> */
    private function jurnalTimbang(float $toleransi): Collection
    {
        return $this->ringkasanPosted()
            ->filter(fn (object $j) => $j->jumlah_baris > 0
                && abs((float) $j->jumlah_debet - (float) $j->jumlah_kredit) >= $toleransi)
            ->values();
    }

    /** @return Collection<int, object> */
    private function headerTidakSinkron(float $toleransi): Collection
    {
        return $this->ringkasanPosted()
            ->filter(fn (object $j) => $j->jumlah_baris > 0
                && (
                    abs((float) $j->total_debet - (float) $j->jumlah_debet) >= $toleransi
                    || abs((float) $j->total_kredit - (float) $j->jumlah_kredit) >= $toleransi
                    || ! (bool) $j->is_balance
                ))
            ->values();
    }

    /* =====================================================================
     | 4. BARIS DETAIL YANG TERPUTUS DARI JURNAL INDUKNYA
     ===================================================================== */

    /** @return Collection<int, object> */
    private function detailTanpaInduk(): Collection
    {
        return DB::table('jurnal_detail as d')
            ->leftJoin('jurnal as j', 'j.id', '=', 'd.jurnal_id')
            ->where(function ($q) {
                $q->whereNull('j.id')
                    ->orWhereNotNull('j.deleted_at');
            })
            ->orderBy('d.id')
            ->get(['d.id', 'd.jurnal_id', 'd.baris', 'd.debet', 'd.kredit', 'j.no_jurnal', 'j.deleted_at']);
    }

    /* =====================================================================
     | 5. BARIS DETAIL TANPA AKUN COA VALID
     ===================================================================== */

    /** @return Collection<int, object> */
    private function detailTanpaCoa(): Collection
    {
        return DB::table('jurnal_detail as d')
            ->leftJoin('coas as c', 'c.id', '=', 'd.coa_id')
            ->whereNull('c.id')
            ->orderBy('d.id')
            ->get(['d.id', 'd.jurnal_id', 'd.coa_id', 'd.baris', 'd.debet', 'd.kredit']);
    }

    /* =====================================================================
     | 6 & 7. BARIS DETAIL YANG TIDAK SAH SECARA NILAI
     ===================================================================== */

    /** @return Collection<int, object> */
    private function detailKosong(): Collection
    {
        return DB::table('jurnal_detail')
            ->where('debet', '<=', 0)
            ->where('kredit', '<=', 0)
            ->orderBy('id')
            ->get(['id', 'jurnal_id', 'coa_id', 'baris', 'debet', 'kredit']);
    }

    /** @return Collection<int, object> */
    private function detailDuaSisi(): Collection
    {
        return DB::table('jurnal_detail')
            ->where('debet', '>', 0)
            ->where('kredit', '>', 0)
            ->orderBy('id')
            ->get(['id', 'jurnal_id', 'coa_id', 'baris', 'debet', 'kredit']);
    }

    /* =====================================================================
     | PELAPORAN
     ===================================================================== */

    private function keteranganKategori(string $nama): string
    {
        return match ($nama) {
            'JURNAL_KOSONG' => 'Header Posted tanpa baris detail — double-entry tidak terpenuhi.',
            'JURNAL_TIMBANG' => 'Jumlah debet dan kredit baris detail tidak seimbang.',
            'HEADER_TIDAK_SINKRON' => 'Total/is_balance header tidak sesuai jumlah baris detail.',
            'DETAIL_TANPA_INDUK' => 'Baris detail tanpa jurnal induk, atau induknya sudah dihapus.',
            'DETAIL_TANPA_COA' => 'Baris detail tanpa akun CoA valid (coa_id kosong/tidak terdaftar).',
            'DETAIL_KOSONG' => 'Baris detail bernilai debet 0 dan kredit 0 — baris hampa.',
            'DETAIL_DUA_SISI' => 'Baris detail terisi debet dan kredit sekaligus — arah tidak jelas.',
            default => '',
        };
    }

    /** @param Collection<int, object> $baris */
    private function tampilkanContoh(string $kategori, Collection $baris): void
    {
        $this->table($this->kolomKategori($kategori), $baris->map(function (object $b) use ($kategori) {
            return match ($kategori) {
                'JURNAL_KOSONG' => [
                    $b->id,
                    $b->no_jurnal,
                    $b->tanggal_jurnal,
                    $b->sumber ?? '-',
                ],
                'JURNAL_TIMBANG', 'HEADER_TIDAK_SINKRON' => [
                    $b->id,
                    $b->no_jurnal,
                    $this->rupiah($b->jumlah_debet),
                    $this->rupiah($b->jumlah_kredit),
                    $this->rupiah($b->total_debet).' / '.$this->rupiah($b->total_kredit),
                    $b->jumlah_baris,
                ],
                'DETAIL_TANPA_INDUK' => [
                    $b->id,
                    $b->jurnal_id,
                    $b->no_jurnal ?? '(induk hilang)',
                    $b->deleted_at ? 'induk terhapus' : '-',
                    $b->baris,
                ],
                default => [
                    $b->id,
                    $b->jurnal_id,
                    $b->coa_id ?? '(kosong)',
                    $b->baris,
                    $this->rupiah($b->debet),
                    $this->rupiah($b->kredit),
                ],
            };
        })->all());
    }

    /** @return array<int, string> */
    private function kolomKategori(string $kategori): array
    {
        return match ($kategori) {
            'JURNAL_KOSONG' => ['ID', 'No. Jurnal', 'Tanggal', 'Sumber'],
            'JURNAL_TIMBANG', 'HEADER_TIDAK_SINKRON' => ['ID', 'No. Jurnal', 'Σ Debet Detail', 'Σ Kredit Detail', 'Header D/K', 'Baris'],
            'DETAIL_TANPA_INDUK' => ['Detail ID', 'Jurnal ID', 'No. Jurnal Induk', 'Status Induk', 'Baris'],
            default => ['Detail ID', 'Jurnal ID', 'CoA ID', 'Baris', 'Debet', 'Kredit'],
        };
    }

    private function rupiah(mixed $nilai): string
    {
        return number_format((float) $nilai, 2, ',', '.');
    }
}
