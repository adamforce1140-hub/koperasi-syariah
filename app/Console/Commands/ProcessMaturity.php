<?php

namespace App\Console\Commands;

use App\Jobs\ProsesJatuhTempoDepositoJob;
use App\Models\Deposito;
use App\Support\QueueOperasional;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command: php artisan deposito:process-maturity
 *
 * Memproses SEMUA deposito Mudharabah yang sudah jatuh tempo sesuai mode ARO
 * yang melekat pada bilyet (kolom `depositos.aro_mode`):
 *
 *   NON_ARO   → pokok dicairkan ke Simpanan Sukarela (2-1001), status CLOSED.
 *   ARO_POKOK → bagi hasil masuk Simpanan Sukarela, pokok diperpanjang
 *               (roll_count +1, last_rolled_at diperbarui).
 *   ARO_PLUS  → bagi hasil dikapitalisasi menjadi pokok baru, pokok diperpanjang
 *               (roll_count +1, last_rolled_at diperbarui).
 *
 * Dijalankan harian via scheduler (lihat routes/console.php). Setiap proses
 * dicatat ke log (audit) dan terminal; satu deposito gagal tidak menghentikan
 * pemrosesan deposito berikutnya.
 */
class ProcessMaturity extends Command
{
    protected $signature = 'deposito:process-maturity
                                {--dry-run : Tampilkan daftar deposito jatuh tempo tanpa memproses}
                                {--date=   : Tanggal acuan proses (Y-m-d), default hari ini}';

    protected $description = 'Proses deposito jatuh tempo sesuai mode ARO (NON_ARO / ARO_POKOK / ARO_PLUS)';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tanggal = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : Carbon::today();

        $this->info('=== Pemrosesan Deposito Jatuh Tempo (ARO) | '.$tanggal->format('d/m/Y').' ==='
            .($dryRun ? ' [DRY-RUN]' : ''));

        Log::info('ProcessMaturity: proses dimulai.', [
            'tanggal' => $tanggal->toDateString(),
            'dry_run' => $dryRun,
        ]);

        // Semua mode ARO diproses: NON_ARO dicairkan, ARO_* diperpanjang.
        $daftar = Deposito::with(['product', 'member', 'sourceSavingAccount'])
            ->where('status', Deposito::STATUS_ACTIVE)
            ->whereDate('maturity_date', '<=', $tanggal->toDateString())
            ->orderBy('maturity_date')
            ->get();

        $this->info("Ditemukan {$daftar->count()} deposito jatuh tempo.");

        if ($daftar->isEmpty()) {
            $this->info('Tidak ada deposito yang perlu diproses.');

            return self::SUCCESS;
        }

        $berhasil = 0;
        $gagal = 0;

        foreach ($daftar as $deposito) {
            $mode = $deposito->aro_mode ?: Deposito::ARO_NON;

            $this->line(sprintf(
                '  • %s | %s | pokok Rp %s | jatuh tempo %s | mode %s',
                $deposito->deposito_number,
                $deposito->member?->nama_lengkap ?? "anggota #{$deposito->member_id}",
                number_format((float) $deposito->principal_amount, 0, ',', '.'),
                Carbon::parse($deposito->maturity_date)->format('d/m/Y'),
                $deposito->aro_label ?? $mode
            ));

            if ($dryRun) {
                continue;
            }

            try {
                /* Operasi berat diekstrak menjadi job (Rekomendasi 4 langkah 5).
                 | Selama `FEATURE_QUEUE_ASYNC=false` job dijalankan SINKRON
                 | dan hasilnya dibaca dari properti job (satu kali eksekusi). */
                $job = new ProsesJatuhTempoDepositoJob((int) $deposito->id);
                $hasil = null;

                if (QueueOperasional::aktif()) {
                    QueueOperasional::jalankan($job);
                } else {
                    $hasil = $job->jalankanSinkron();
                }

                if ($hasil === null) {
                    $this->line('     [ANTRIAN] '.$deposito->deposito_number.' didorong ke antrian.');
                    $berhasil++;

                    continue;
                }

                $this->info(sprintf(
                    '     [OK] %s → status %s | pokok Rp %s → Rp %s | jurnal %s',
                    $hasil['mode'],
                    $hasil['status'],
                    number_format($hasil['pokok'], 0, ',', '.'),
                    number_format($hasil['pokok_baru'], 0, ',', '.'),
                    $hasil['jurnal']?->no_jurnal ?? '-'
                ));

                Log::info('ProcessMaturity: deposito diproses.', [
                    'deposito_number' => $deposito->deposito_number,
                    'mode' => $hasil['mode'],
                    'pokok' => $hasil['pokok'],
                    'pokok_baru' => $hasil['pokok_baru'],
                    'status' => $hasil['status'],
                    'no_jurnal' => $hasil['jurnal']?->no_jurnal,
                    'tanggal_proses' => $tanggal->toDateString(),
                ]);

                $berhasil++;
            } catch (\Throwable $e) {
                $this->error('     [GAGAL] '.$e->getMessage());

                Log::error('ProcessMaturity: pemrosesan gagal.', [
                    'deposito_number' => $deposito->deposito_number,
                    'member_id' => $deposito->member_id,
                    'pesan' => $e->getMessage(),
                    'tanggal_proses' => $tanggal->toDateString(),
                ]);

                $gagal++;
            }
        }

        $this->newLine();
        $this->info("Selesai. Berhasil: {$berhasil} | Gagal: {$gagal}");

        Log::info('ProcessMaturity: proses selesai.', [
            'berhasil' => $berhasil,
            'gagal' => $gagal,
            'dry_run' => $dryRun,
        ]);

        return $gagal > 0 ? self::FAILURE : self::SUCCESS;
    }
}
