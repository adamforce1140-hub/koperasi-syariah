<?php

namespace App\Console\Commands;

use App\Models\ApprovalTransaksiLog;
use App\Models\AuditLog;
use App\Models\AuditPerubahanPii;
use App\Models\LaporanKeuanganLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Purge jejak audit yang melampaui masa retensi (OJK-04).
 *
 * Dasar hukum:
 *   - UU No. 27/2022 (PDP) Pasal 16 — pembatasan penyimpanan data pribadi.
 *   - UU No. 27/2022 (PDP) Pasal 35 — hak Subjek Data atas penghapusan.
 *   - UU No. 25/1992 & POJK — kewajiban menyimpan jejak audit keuangan.
 *
 * Alur (aman untuk tabel append-only):
 *   1. Hitung batas waktu (cutoff) per tabel dari config('keamanan.retensi_log').
 *   2. ARSIPKAN baris kedaluwarsa ke storage/app/arsip-log/*.jsonl (agar jejak
 *      tetap dapat ditelusuri auditor walau barisnya dihapus dari DB).
 *   3. Hapus baris kedaluwarsa lewat query builder (menembus guard append-only
 *      yang hanya berlaku di level model).
 *   4. SEGEL ULANG rantai hash baris yang tersisa — tanpa ini verifyChain()
 *      akan melaporkan rantai terputus karena baris kepala (tertua) sudah
 *      dibuang. Ini menyunting HANYA kolom segel, bukan isi baris.
 *
 * Default dijalankan sebagai PRATINJAU. Gunakan --force untuk benar-benar
 * menghapus. Command ini juga dijadwalkan harian di routes/console.php.
 */
class PurgeLogKedaluwarsa extends Command
{
    protected $signature = 'log:purge-kedaluwarsa
                            {--force : Terapkan penghapusan (tanpa opsi ini hanya menampilkan pratinjau)}
                            {--dry-run : Paksa mode pratinjau, jangan hapus apa pun}';

    protected $description = 'Arsipkan lalu hapus jejak audit yang melampaui masa retensi (UU PDP Pasal 16 & 35).';

    /**
     * Pemetaan tabel → {label, hari retensi, model penyegel rantai}.
     *
     * @var array<string, array{label: string, hari: int, model: class-string}>
     */
    private array $tabel = [];

    public function handle(): int
    {
        $config = (array) config('keamanan.retensi_log', []);
        $batch = max(100, (int) ($config['batch'] ?? 1000));

        $this->tabel = [
            'audit_logs' => [
                'label' => 'Audit mutasi rekening',
                'hari' => (int) ($config['log_hari'] ?? 0),
                'model' => AuditLog::class,
            ],
            'laporan_keuangan_logs' => [
                'label' => 'Audit Laporan Keuangan',
                'hari' => (int) ($config['log_keuangan_hari'] ?? 0),
                'model' => LaporanKeuanganLog::class,
            ],
            'approval_transaksi_logs' => [
                'label' => 'Jejak approval transaksi',
                'hari' => (int) ($config['log_keuangan_hari'] ?? 0),
                'model' => ApprovalTransaksiLog::class,
            ],
            'audit_perubahan_pii' => [
                'label' => 'Audit perubahan data pribadi',
                'hari' => (int) ($config['pii_hari'] ?? 0),
                'model' => AuditPerubahanPii::class,
            ],
        ];

        $terapkan = (bool) $this->option('force') && ! (bool) $this->option('dry-run');

        $this->info('Kebijakan retensi jejak audit');
        $this->line('  Mode: '.($terapkan ? 'TERAPKAN (arsip + hapus)' : 'PRATINJAU (tidak menghapus)'));
        $this->newLine();

        $total = 0;

        foreach ($this->tabel as $nama => $info) {
            $hari = (int) $info['hari'];

            if ($hari <= 0) {
                $this->line(sprintf('  %-30s : retensi tak terbatas — dilewati', $info['label']));

                continue;
            }

            $cutoff = now()->subDays($hari);
            $jumlah = DB::table($nama)->where('created_at', '<', $cutoff)->count();

            if ($jumlah === 0) {
                $this->line(sprintf(
                    '  %-30s : 0 baris kedaluwarsa (cutoff %s)',
                    $info['label'],
                    $cutoff->toDateString()
                ));

                continue;
            }

            $this->warn(sprintf(
                '  %-30s : %d baris kedaluwarsa (sebelum %s)',
                $info['label'],
                $jumlah,
                $cutoff->toDateString()
            ));

            $total += $jumlah;

            if (! $terapkan) {
                continue;
            }

            $dihapus = $this->purgeTabel($nama, $cutoff, $batch);
            $this->line("      → {$dihapus} baris dihapus; arsip: ".$this->pathArsip($nama));

            // Baris kepala (tertua) sudah dibuang → segel ulang rantai.
            $this->segarkanRantai($info['model']);
        }

        $this->newLine();

        if (! $terapkan) {
            $this->info("Pratinjau selesai: {$total} baris akan dipurge. Jalankan ulang dengan --force untuk menerapkan.");

            return self::SUCCESS;
        }

        $this->info("Purge selesai: {$total} baris dihapus dan rantai hash disegel ulang.");

        Log::info('Purge jejak audit kedaluwarsa dijalankan (OJK-04).', [
            'baris_dihapus' => $total,
            'kebijakan' => [
                'log_hari' => $config['log_hari'] ?? null,
                'log_keuangan_hari' => $config['log_keuangan_hari'] ?? null,
                'pii_hari' => $config['pii_hari'] ?? null,
            ],
        ]);

        return self::SUCCESS;
    }

    /**
     * Arsipkan lalu hapus baris kedaluwarsa per batch.
     *
     * @return int jumlah baris yang dihapus
     */
    private function purgeTabel(string $tabel, \DateTimeInterface $cutoff, int $batch): int
    {
        $dihapus = 0;

        while (true) {
            $ids = DB::table($tabel)
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($batch)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            // Arsipkan dulu — jejak yang dihapus tetap dapat ditelusuri auditor.
            $baris = DB::table($tabel)->whereIn('id', $ids)->orderBy('id')->get();
            foreach ($baris as $b) {
                File::append(
                    $this->pathArsip($tabel),
                    json_encode($b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
                );
            }

            $dihapus += DB::table($tabel)->whereIn('id', $ids)->delete();
        }

        return $dihapus;
    }

    /**
     * Hitung ulang segel rantai hash untuk baris yang tersisa.
     *
     * Karena baris kepala dibuang, baris pertama yang tersisa harus dijadikan
     * titik awal rantai baru (hash_sebelumnya = null), lalu segel setiap baris
     * berikutnya mengikuti baris sebelumnya. Update dilakukan lewat query
     * builder agar tidak memicu guard append-only pada model.
     */
    private function segarkanRantai(string $model): void
    {
        $tabel = (new $model)->getTable();
        $hashSebelumnya = null;
        $idSebelumnya = null;
        $jumlah = 0;

        $model::query()->orderBy('id')->chunkById(500, function ($baris) use (
            &$hashSebelumnya,
            &$idSebelumnya,
            &$jumlah,
            $tabel
        ) {
            foreach ($baris as $log) {
                $log->hash_sebelumnya_id = $idSebelumnya;
                $log->hash_sebelumnya = $hashSebelumnya;
                $segel = $log->hitungHash();

                DB::table($tabel)->where('id', $log->id)->update([
                    'hash_sebelumnya_id' => $idSebelumnya,
                    'hash_sebelumnya' => $hashSebelumnya,
                    'hash' => $segel,
                ]);

                $hashSebelumnya = $segel;
                $idSebelumnya = $log->id;
                $jumlah++;
            }
        });

        $this->line("      → rantai hash disegel ulang ({$jumlah} baris).");
    }

    /** Path berkas arsip JSONL untuk sebuah tabel (dibuat bila belum ada). */
    private function pathArsip(string $tabel): string
    {
        $dir = storage_path('app/arsip-log');

        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        return $dir.DIRECTORY_SEPARATOR.$tabel.'-'.now()->format('Ymd').'.jsonl';
    }
}
