<?php

namespace App\Console\Commands;

use App\Events\DepositoJatuhTempo;
use App\Models\Deposito;
use App\Services\DepositoService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command: php artisan deposito:process-matured
 *
 * Mencairkan deposito Mudharabah yang sudah jatuh tempo secara otomatis:
 * status ACTIVE, bukan ARO (perpanjangan otomatis), dan maturity_date <= hari ini.
 *
 * Setiap proses dicatat ke log (audit) dan ke terminal. Satu deposito gagal
 * tidak menghentikan pemrosesan deposito berikutnya.
 */
class ProcessMaturedDeposito extends Command
{
    protected $signature = 'deposito:process-matured
                                {--dry-run : Tampilkan daftar tanpa memproses pencairan}
                                {--date=   : Tanggal acuan proses (Y-m-d), default hari ini}';

    protected $description = 'Cairkan deposito Mudharabah jatuh tempo (status ACTIVE, non-ARO, maturity_date <= hari ini)';

    public function __construct(private DepositoService $depositoService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tanggal = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : Carbon::today();

        $this->info('=== Pencairan Deposito Jatuh Tempo | '.$tanggal->format('d/m/Y').' ==='
            .($dryRun ? ' [DRY-RUN]' : ''));

        Log::info('ProcessMaturedDeposito: proses dimulai.', [
            'tanggal' => $tanggal->toDateString(),
            'dry_run' => $dryRun,
        ]);

        // Hanya deposito yang benar-benar berjalan & tidak diperpanjang otomatis.
        $daftar = Deposito::with(['product', 'member', 'sourceSavingAccount'])
            ->where('status', Deposito::STATUS_ACTIVE)
            ->where('is_aro', false)
            ->whereDate('maturity_date', '<=', $tanggal->toDateString())
            ->orderBy('maturity_date')
            ->get();

        $this->info("Ditemukan {$daftar->count()} deposito jatuh tempo.");

        if ($daftar->isEmpty()) {
            Log::info('ProcessMaturedDeposito: tidak ada deposito jatuh tempo.');
            $this->info('Tidak ada deposito yang perlu dicairkan.');

            return self::SUCCESS;
        }

        $berhasil = 0;
        $gagal = 0;

        foreach ($daftar as $deposito) {
            $label = "{$deposito->deposito_number} | "
                .($deposito->member?->nama_lengkap ?? "anggota #{$deposito->member_id}")
                .' | Pokok Rp '.number_format((float) $deposito->principal_amount, 0, ',', '.')
                .' | Jatuh tempo '.Carbon::parse($deposito->maturity_date)->format('d/m/Y');

            $this->line("  >> {$label}");

            if ($dryRun) {
                $this->info('     [DRY-RUN] Tidak dicairkan.');

                continue;
            }

            try {
                $hasil = $this->depositoService->settleMaturedDeposito($deposito);

                $this->info("     [OK] Status: {$hasil->status} — pokok dikembalikan ke Simpanan Sukarela.");

                Log::info('ProcessMaturedDeposito: deposito dicairkan.', [
                    'deposito_number' => $hasil->deposito_number,
                    'member_id' => $hasil->member_id,
                    'principal' => (float) $hasil->principal_amount,
                    'status' => $hasil->status,
                    'tanggal_proses' => $tanggal->toDateString(),
                ]);

                $berhasil++;

                /*
                 | Publikasikan peristiwa domain. Dibungkus try/catch: kegagalan
                 | listener tidak boleh menggagalkan proses pencairan deposito
                 | maupun menandai deposito ini "gagal" (fail-safe, aditif).
                 */
                try {
                    DepositoJatuhTempo::dispatch($hasil);
                } catch (\Throwable $e) {
                    Log::warning('Gagal mempublikasikan peristiwa DepositoJatuhTempo: '.$e->getMessage());
                }
            } catch (\Throwable $e) {
                $this->error('     [GAGAL] '.$e->getMessage());

                Log::error('ProcessMaturedDeposito: pencairan gagal.', [
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

        Log::info('ProcessMaturedDeposito: proses selesai.', [
            'berhasil' => $berhasil,
            'gagal' => $gagal,
            'dry_run' => $dryRun,
        ]);

        return $gagal > 0 ? self::FAILURE : self::SUCCESS;
    }
}
