<?php

namespace App\Console\Commands;

use App\Jobs\ProsesTagihanSimpananWajibJob;
use App\Services\MandatorySavingsService;
use App\Support\QueueOperasional;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command: php artisan mandatory-savings:process-daily
 *
 * Scheduler harian Simpanan Wajib (dijalankan pukul 00:01 WIB).
 *
 * Alur kerja:
 *  1. Untuk setiap anggota berstatus ACTIVE, tentukan apakah hari ini adalah
 *     tanggal jatuh tempo Simpanan Wajib bulanan anggota tersebut. Anggota yang
 *     bergabung pada tanggal 29/30/31 disesuaikan ke akhir bulan berjalan
 *     (Carbon::isEndOfMonth()) agar tidak "melewati" bulan Februari.
 *  2. Terbitkan tagihan periode berjalan dengan `firstOrCreate` sehingga tidak
 *     pernah ada tagihan duplikat untuk satu anggota pada periode yang sama.
 *  3. Coba Auto-Debit dari saldo Simpanan Sukarela (2-1001). Bila saldo cukup
 *     → status tagihan PAID + jurnal DEBET 2-1001 | KREDIT 3-1002.
 *     Bila tidak cukup → status OVERDUE + notifikasi ke anggota & admin.
 *
 * Satu anggota gagal tidak menghentikan pemrosesan anggota berikutnya —
 * kegagalan dicatat ke log (audit) dan terminal.
 */
class ProcessMandatorySavings extends Command
{
    protected $signature = 'mandatory-savings:process-daily
                                {--dry-run : Tampilkan rencana proses tanpa menulis data}
                                {--date=   : Tanggal acuan proses (Y-m-d), default hari ini}';

    protected $description = 'Terbitkan tagihan Simpanan Wajib bulanan, auto-debit dari Simpanan Sukarela, dan tandai OVERDUE bila saldo tidak cukup';

    public function __construct(private MandatorySavingsService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tanggal = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : Carbon::today();

        $this->info('=== Pemrosesan Simpanan Wajib Harian | '.$tanggal->format('d/m/Y').' ==='
            .($dryRun ? ' [DRY-RUN]' : ''));

        Log::info('ProcessMandatorySavings: proses dimulai.', [
            'tanggal' => $tanggal->toDateString(),
            'dry_run' => $dryRun,
        ]);

        try {
            /* Operasi berat diekstrak menjadi job (Rekomendasi 4 langkah 5).
             | Selama `FEATURE_QUEUE_ASYNC=false` job dijalankan SINKRON dan
             | ringkasan dibaca dari properti job (satu kali eksekusi). */
            $job = new ProsesTagihanSimpananWajibJob($tanggal->toDateString());

            if ($dryRun) {
                // mode uji: jangan menulis apa pun
                $hasil = $this->service->processDaily($tanggal, true, null);
            } elseif (QueueOperasional::aktif()) {
                QueueOperasional::jalankan($job);
                $this->info('Pemrosesan Simpanan Wajib telah didorong ke antrian.');

                return self::SUCCESS;
            } else {
                $hasil = $job->jalankanSinkron();
            }
        } catch (\Throwable $e) {
            $this->error('[GAGAL] '.$e->getMessage());

            Log::error('ProcessMandatorySavings: proses gagal total.', [
                'pesan' => $e->getMessage(),
                'tanggal_proses' => $tanggal->toDateString(),
            ]);

            return self::FAILURE;
        }

        foreach ($hasil['detail'] as $baris) {
            $this->line(sprintf(
                '  • %s | %s | Rp %s | %s',
                $baris['no_anggota'] ?? '-',
                $baris['nama_anggota'] ?? '-',
                number_format((float) ($baris['amount'] ?? 0), 0, ',', '.'),
                strtoupper((string) $baris['status'])
            ));

            if (! empty($baris['pesan'])) {
                $this->warn('     [CATATAN] '.$baris['pesan']);
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Selesai. Terbit: %d | PAID: %d | DITANGGUHKAN: %d | OVERDUE: %d | Dilewati: %d | Gagal: %d',
            $hasil['issued'],
            $hasil['paid'],
            $hasil['ditangguhkan'] ?? 0,
            $hasil['overdue'],
            $hasil['skipped'],
            $hasil['gagal']
        ));

        Log::info('ProcessMandatorySavings: proses selesai.', [
            'issued' => $hasil['issued'],
            'paid' => $hasil['paid'],
            'ditangguhkan' => $hasil['ditangguhkan'] ?? 0,
            'overdue' => $hasil['overdue'],
            'skipped' => $hasil['skipped'],
            'gagal' => $hasil['gagal'],
            'dry_run' => $dryRun,
        ]);

        return $hasil['gagal'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
