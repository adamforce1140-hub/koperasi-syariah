<?php

namespace App\Console\Commands;

use App\Services\CkpnService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command: php artisan ckpn:hitung-penyesuaian
 *
 * Operasionalisasi penuh PSAK 71 (instrumen keuangan) untuk piutang pembiayaan:
 * command ini menyandingkan saldo TERCATAT akun 1-2100 (Penyisihan Kerugian
 * Piutang Pembiayaan) dengan saldo TARGET yang dihitung dari kolektibilitas
 * seluruh pembiayaan berjalan, lalu memposting jurnal penyesuaian atas
 * selisihnya:
 *
 *   Selisih positif (target > tercatat) → DEBET 5-4001 | KREDIT 1-2100
 *   Selisih negatif (target < tercatat) → DEBET 1-2100 | KREDIT 5-4001 (pemulihan)
 *
 * SELURUH persentase penyisihan dan ambang hari kolektibilitas dibaca dari
 * App\Support\Setting (tidak ada tarif yang di-hardcode), sehingga koperasi
 * dapat menyesuaikan kebijakan tanpa mengubah kode command.
 *
 * Command bersifat IDEMPOTEN: dijalankan dua kali berturut-turut pada tanggal
 * yang sama, eksekusi kedua tidak akan menghasilkan jurnal baru karena selisih
 * sudah nol / di bawah toleransi kebijakan (Setting::toleransiSelisihJurnal()).
 *
 * Dijalankan harian via scheduler (lihat routes/console.php).
 */
class HitungCkpnPenyesuaian extends Command
{
    protected $signature = 'ckpn:hitung-penyesuaian {--dry-run} {--date=}';

    protected $description = 'Menghitung dan memposting jurnal penyesuaian CKPN piutang pembiayaan (PSAK 71) berdasarkan target kolektibilitas.';

    public function __construct(private CkpnService $ckpnService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tanggal = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::today();
        $dryRun = (bool) $this->option('dry-run');

        $this->info('=== Penyesuaian CKPN Piutang Pembiayaan (PSAK 71) | '.$tanggal->format('d/m/Y').' ==='
            .($dryRun ? ' [DRY-RUN]' : ''));

        // CKPN-4: segarkan klasifikasi kolektibilitas & status macet dari
        // tunggakan riil sebelum saldo target dihitung, agar dasar perhitungan
        // tidak memakai kolom kolektibilitas yang basi.
        $sinkron = $this->ckpnService->sinkronkanStatusKolektibilitas($tanggal, ! $dryRun);

        $this->info(sprintf(
            'Sinkronisasi kolektibilitas: %d pembiayaan diperiksa, %d diperbarui, %d menjadi MACET.',
            $sinkron['diperiksa'],
            $sinkron['diubah'],
            $sinkron['macet'],
        ));

        $rincian = $this->ckpnService->rincian($tanggal);

        $this->table(['Kolektibilitas', 'Jumlah Pembiayaan', 'Sisa Pokok', 'Persentase', 'CKPN'], $this->agregasi($rincian));

        $this->info('Target saldo penyisihan   : Rp '.number_format($this->ckpnService->targetSaldo($tanggal), 2, ',', '.'));
        $this->info('Saldo tercatat akun 1-2100: Rp '.number_format($this->ckpnService->saldoTercatat($tanggal), 2, ',', '.'));
        $this->info('Selisih (target - tercatat): Rp '.number_format($this->ckpnService->selisih($tanggal), 2, ',', '.'));

        if ($dryRun) {
            $this->warn('Mode dry-run: tidak ada jurnal yang diposting.');

            return self::SUCCESS;
        }

        $berhasil = 0;
        $gagal = 0;

        try {
            $jurnal = $this->ckpnService->catatPenyesuaian($tanggal, null);

            if ($jurnal === null) {
                $this->info('Selisih dalam batas toleransi kebijakan; tidak ada penyesuaian.');

                Log::info('[CKPN] Tidak ada penyesuaian: selisih dalam batas toleransi kebijakan.', [
                    'tanggal' => $tanggal->toDateString(),
                ]);
            } else {
                $berhasil++;

                $this->info("Jurnal penyesuaian diposting: {$jurnal->no_jurnal} (nominal Rp "
                    .number_format((float) $jurnal->total_debet, 2, ',', '.').')');

                Log::info('[CKPN] Jurnal penyesuaian diposting.', [
                    'no_jurnal' => $jurnal->no_jurnal,
                    'tanggal' => $tanggal->toDateString(),
                    'nominal' => (float) $jurnal->total_debet,
                ]);
            }
        } catch (\Throwable $e) {
            $gagal++;

            $this->error($e->getMessage());

            Log::error('[CKPN] Gagal memposting penyesuaian: '.$e->getMessage());
        }

        return $gagal > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Agregasi rincian per kode kolektibilitas ('kol1'..'kol5').
     *
     * @param  array<int, array{pembiayaan_id: int, anggota: ?string, sisa_pokok: float, kolektibilitas: string, persen: float, ckpn: float}>  $rincian
     * @return array<int, array<int, string>>
     */
    private function agregasi(array $rincian): array
    {
        $agregat = [];

        foreach ($rincian as $baris) {
            $kode = $baris['kolektibilitas'];

            if (! isset($agregat[$kode])) {
                $agregat[$kode] = [
                    'jumlah' => 0,
                    'sisa' => 0.0,
                    'persen' => (float) $baris['persen'],
                    'ckpn' => 0.0,
                ];
            }

            $agregat[$kode]['jumlah']++;
            $agregat[$kode]['sisa'] += (float) $baris['sisa_pokok'];
            $agregat[$kode]['ckpn'] += (float) $baris['ckpn'];
        }

        ksort($agregat);

        $baris = [];

        foreach ($agregat as $kode => $nilai) {
            $baris[] = [
                strtoupper($kode),
                number_format($nilai['jumlah'], 0, ',', '.'),
                number_format($nilai['sisa'], 2, ',', '.'),
                number_format($nilai['persen'], 2, ',', '.').' %',
                number_format($nilai['ckpn'], 2, ',', '.'),
            ];
        }

        return $baris;
    }
}
