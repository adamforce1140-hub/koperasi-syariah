<?php

namespace App\Console\Commands;

use App\Jobs\DistribusiBagiHasilJob;
use App\Services\PendapatanService;
use App\Services\TahunBukuService;
use App\Support\QueueOperasional;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command: php artisan deposito:bagi-hasil
 *
 * R8 — Distribusi bagi hasil deposito Mudharabah Muthlaqah berbasis LABA RIIL.
 *
 * Alur:
 *   1. Hitung pendapatan investasi riil koperasi pada periode (bulan) acuan
 *      lewat PendapatanService (margin + bagi hasil + ujroh; ta'zir/denda
 *      dikecualikan karena masuk Dana Kebajikan 2-3001).
 *   2. Bagikan ke seluruh deposito ACTIVE secara proporsional terhadap
 *      principal_amount melalui DepositoService::bagiHasilPeriode().
 *
 * Default periode = bulan sebelumnya (bagi hasil dibagikan setelah bulan tutup).
 * Idempoten: deposito yang periodenya sudah dibayar akan dilewati.
 */
class DistribusiBagiHasilDeposito extends Command
{
    protected $signature = 'deposito:bagi-hasil
                                {--year=  : Tahun buku periode (YYYY), default bulan buku sebelumnya}
                                {--month= : Nomor bulan buku (1..jumlah bulan tahun buku), default bulan buku sebelumnya}
                                {--dry-run : Hitung & tampilkan rencana bagi hasil tanpa memposting}
                                {--user=  : ID pengguna pelaksana untuk jejak jurnal}';

    protected $description = 'Distribusikan bagi hasil deposito Mudharabah dari pendapatan investasi riil periode';

    public function __construct(
        private PendapatanService $pendapatanService,
        private TahunBukuService $tahunBukuService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Nomor periode bulan mengikuti TAHUN BUKU koperasi (dapat diubah
        // pengurus lewat parameter kebijakan), bukan asumsi kaku Januari–
        // Desember. Tahun buku non-kalender (mis. Juli–Juni) tetap sahih.
        $bulanMulai = $this->tahunBukuService->bulanMulai();
        $jumlahBulan = $this->tahunBukuService->jumlahBulan();

        // Acuan default = bulan yang baru saja tutup (bagi hasil dibagikan
        // setelah bulan tutup).
        $acuan = Carbon::now()->subMonthNoOverflow();

        $year = (int) ($this->option('year') ?: $this->tahunBukuService->tahunBukuDari($acuan));
        $month = (int) ($this->option('month') ?: $this->bulanPeriodeDari($acuan, $bulanMulai));

        if ($month < 1 || $month > $jumlahBulan) {
            $this->error("Bulan periode harus antara 1 dan {$jumlahBulan} (jumlah bulan tahun buku koperasi).");

            return self::FAILURE;
        }

        $userId = $this->option('user') ? (int) $this->option('user') : null;

        $pendapatan = $this->pendapatanService->pendapatanInvestasiBulanan($year, $month);
        $periode = str_pad((string) $month, 2, '0', STR_PAD_LEFT)."/{$year}";
        $bulanKalender = $this->tahunBukuService->bulanKe($year, $month)->format('m/Y');

        $this->info("Periode bagi hasil: {$periode} (bulan buku ke-{$month} = {$bulanKalender})");
        $this->line('  Pendapatan margin    : Rp '.number_format($pendapatan['margin'], 0, ',', '.'));
        $this->line('  Pendapatan bagi hasil: Rp '.number_format($pendapatan['bagi_hasil'], 0, ',', '.'));
        $this->line('  Pendapatan ujroh/fee : Rp '.number_format($pendapatan['ujroh'], 0, ',', '.'));
        $this->line('  Total pendapatan     : Rp '.number_format($pendapatan['total'], 0, ',', '.'));

        if ($dryRun) {
            $this->warn('[dry-run] Tidak ada jurnal/transaksi yang dibuat.');

            return self::SUCCESS;
        }

        try {
            /* Operasi berat diekstrak menjadi job (Rekomendasi 4 langkah 5).
             | Selama `FEATURE_QUEUE_ASYNC=false` job dijalankan SINKRON lewat
             | `dispatch_sync`, dan ringkasan hasil dibaca dari properti job —
             | satu kali eksekusi saja, tanpa pemanggilan ganda. */
            $job = new DistribusiBagiHasilJob($year, $month, $userId);

            if (QueueOperasional::aktif()) {
                QueueOperasional::jalankan($job);
            } else {
                $hasil = $job->jalankanSinkron();
            }

            if ($hasil === null) {
                // Mode antrian: hasil belum tersedia saat ini.
                $this->info("Distribusi bagi hasil periode {$periode} telah didorong ke antrian.");

                return self::SUCCESS;
            }
        } catch (\Throwable $e) {
            Log::error('DistribusiBagiHasilDeposito gagal.', [
                'periode' => $periode,
                'error' => $e->getMessage(),
            ]);
            $this->error('Gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Selesai. Dibagikan: %d deposito | dilewati (sudah dibayar): %d | total porsi anggota: Rp %s',
            $hasil['dibagikan'],
            $hasil['dilewati'],
            number_format($hasil['total_porsi_anggota'], 0, ',', '.')
        ));

        return self::SUCCESS;
    }

    /**
     * Nomor bulan buku (1..jumlahBulan) dari sebuah tanggal kalender.
     *
     * Menggeser bulan kalender sebesar `bulanMulai - 1` lalu memetakan ke
     * rentang 1..jumlahBulan sehingga benar untuk tahun buku non-kalender
     * (mis. mulai Juli → Juli = bulan buku 1).
     */
    private function bulanPeriodeDari(Carbon $tanggal, int $bulanMulai): int
    {
        return ((int) $tanggal->month - $bulanMulai + 12) % 12 + 1;
    }
}
