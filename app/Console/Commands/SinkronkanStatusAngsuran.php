<?php

namespace App\Console\Commands;

use App\Models\Angsuran;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Artisan command: php artisan angsuran:sinkronkan-status
 *
 * Menyelaraskan kolom `angsuran.status_angsuran` dengan keadaan sebenarnya:
 *   - `tanggal_bayar` terisi          → 'Sudah Dibayar'
 *   - belum dibayar & JT < hari ini   → 'Terlambat'
 *   - belum dibayar & JT = hari ini   → 'Jatuh Tempo'
 *   - belum dibayar & JT > hari ini   → 'Belum Jatuh Tempo'
 *
 * Kolom ini disimpan (bukan dihitung saat query) karena dipakai sebagai filter
 * SQL oleh banyak modul (teller, nasabah, CKPN, restrukturisasi, autodebet).
 * Tanpa proses penyelarasan, nilainya "membeku" pada saat jadwal dibuat:
 * angsuran yang jatuh tempo bulan lalu tetap tertulis 'Jatuh Tempo' selamanya,
 * sehingga daftar tunggakan dan NPF tidak akurat.
 *
 * Baris berstatus 'Dibayar Sebagian' SENGAJA dilewati: status itu menandakan
 * pokok belum tertagih penuh (`sisa_pokok > 0`) dan tidak boleh ditimpa oleh
 * aturan tanggal. Baris tanpa `tanggal_jatuh_tempo` juga dilewati (data tidak
 * lengkap — command tidak menebak).
 *
 * Idempoten: hanya baris yang statusnya berbeda yang ditulis, sehingga aman
 * dijalankan berulang (scheduler harian) maupun manual.
 */
class SinkronkanStatusAngsuran extends Command
{
    protected $signature = 'angsuran:sinkronkan-status
                                {--dry-run : Tampilkan perubahan tanpa menyimpan}
                                {--date=   : Tanggal acuan (Y-m-d), default hari ini}';

    protected $description = 'Sinkronkan status_angsuran dengan tanggal_bayar & tanggal_jatuh_tempo (anti-status basi)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $today = $this->option('date')
            ? Carbon::parse($this->option('date'))->startOfDay()
            : Carbon::today();

        $this->info('=== Sinkronisasi status angsuran | acuan '.$today->toDateString().' ==='
            .($dryRun ? ' [DRY-RUN]' : ''));

        $diperiksa = 0;
        $diubah = 0;
        $rincian = [];

        Angsuran::query()
            // 'Dibayar Sebagian' = pokok belum tertagih penuh; jangan ditimpa.
            ->where(function ($q) {
                $q->whereNull('status_angsuran')
                    ->orWhere('status_angsuran', '!=', 'Dibayar Sebagian');
            })
            ->orderBy('id')
            ->chunkById(500, function ($baris) use ($today, $dryRun, &$diperiksa, &$diubah, &$rincian) {
                foreach ($baris as $angsuran) {
                    $diperiksa++;

                    $target = $this->statusTarget($angsuran, $today);

                    if ($target === null || $target === $angsuran->status_angsuran) {
                        continue;
                    }

                    $kunci = ($angsuran->status_angsuran ?? '(kosong)').' → '.$target;
                    $rincian[$kunci] = ($rincian[$kunci] ?? 0) + 1;

                    if (! $dryRun) {
                        $angsuran->update(['status_angsuran' => $target]);
                    }

                    $diubah++;
                }
            });

        foreach ($rincian as $label => $jumlah) {
            $this->line("  {$label}: {$jumlah}");
        }

        $this->newLine();
        $this->info("Selesai. Diperiksa: {$diperiksa} | Diubah: {$diubah}"
            .($dryRun ? ' (dry-run — tidak ada yang disimpan)' : ''));

        return self::SUCCESS;
    }

    private function statusTarget(Angsuran $angsuran, Carbon $today): ?string
    {
        if ($angsuran->tanggal_bayar !== null) {
            return 'Sudah Dibayar';
        }

        if (! $angsuran->tanggal_jatuh_tempo) {
            return null;
        }

        $jatuhTempo = Carbon::parse($angsuran->tanggal_jatuh_tempo)->startOfDay();

        if ($jatuhTempo->lt($today)) {
            return 'Terlambat';
        }

        if ($jatuhTempo->equalTo($today)) {
            return 'Jatuh Tempo';
        }

        return 'Belum Jatuh Tempo';
    }
}
