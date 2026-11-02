<?php

namespace App\Console\Commands;

use App\Models\Anggota;
use App\Models\JenisSimpanan;
use App\Models\MandatorySavingsBill;
use App\Services\MandatorySavingsService;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command: php artisan wajib-dues:process
 *
 * SCHEDULED JOB BULANAN — Penerbitan Tagihan Jatuh Tempo Simpanan Wajib
 * (`wajib_dues` / `mandatory_savings_bills`), dijalankan setiap tanggal 1
 * pukul 00:01 WIB (lihat registrasi di routes/console.php).
 *
 * Perbedaan dengan `mandatory-savings:process-daily` (legacy, harian, berbasis
 * ANNIVERSARY tanggal bergabung):
 *  - Command ini menerbitkan tagihan untuk SELURUH anggota ACTIVE sekaligus
 *    pada awal bulan, sehingga tagihan periode berjalan SELALU tersedia di
 *    awal bulan dan tidak bergantung pada tanggal bergabung masing-masing
 *    anggota (memenuhi spesifikasi Bagian 3 poin 1).
 *  - Anti-duplikat dijaga oleh `MandatorySavingsService::issueBill()` yang
 *    memakai `firstOrCreate(['member_id', 'bill_period'])`, sehingga pemanggilan
 *    berulang (retry, double-schedule) TIDAK PERNAH menghasilkan tagihan ganda.
 *
 * ALUR:
 *  1. Tentukan periode tagihan (default: bulan berjalan, format 'YYYY-MM').
 *  2. Ambil seluruh anggota berstatus ACTIVE.
 *  3. Terbitkan tagihan nominal DINAMIS dari master produk Simpanan Wajib
 *     (`jenis_simpanans.min_deposit_amount`, fallback parameter koperasi) —
 *     status awal WAJIB 'UNPAID'.
 *  4. Pemrosesan pembayaran TIDAK dilakukan di sini. Tagihan yang sudah jatuh
 *     tempo diproses oleh `mandatory-savings:process-daily`:
 *       JALUR A (default) → menunggu pembayaran manual di Teller.
 *       JALUR B (opt-in)  → auto-debit pindah buku dari Simpanan Sukarela.
 *  5. Logging komprehensif: jumlah tagihan diterbitkan, anggota dilewati,
 *     kegagalan per anggota, serta ringkasan periode ke channel log.
 *
 * Satu anggota gagal TIDAK menghentikan pemrosesan anggota berikutnya.
 */
class ProcessWajibDuesJob extends Command
{
    protected $signature = 'wajib-dues:process
                                {--dry-run : Tampilkan rencana proses tanpa menulis data}
                                {--date=   : Tanggal acuan penerbitan (Y-m-d), default hari ini}';

    protected $description = 'Terbitkan tagihan jatuh tempo Simpanan Wajib bulanan (UNPAID) untuk seluruh anggota ACTIVE dengan nominal dinamis dari master produk';

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

        $tanggal = $tanggal->copy()->startOfDay();
        $periode = MandatorySavingsBill::periodDari($tanggal);
        $nominal = $this->service->nominalTagihan();

        $this->info('=== Penerbitan Tagihan Jatuh Tempo Simpanan Wajib (Bulanan) ===');
        $this->line(sprintf(
            'Periode: %s | Tanggal acuan: %s | Nominal: Rp %s%s',
            $periode,
            $tanggal->format('d/m/Y'),
            number_format($nominal, 0, ',', '.'),
            $dryRun ? ' | [DRY-RUN]' : ''
        ));
        $this->newLine();

        Log::info('ProcessWajibDuesJob: proses penerbitan tagihan bulanan dimulai.', [
            'periode' => $periode,
            'tanggal' => $tanggal->toDateString(),
            'nominal' => $nominal,
            'sumber' => JenisSimpanan::minimalSetoranWajib(0) > 0
                ? 'master_produk_simpanan_wajib'
                : 'parameter_kebijakan_koperasi',
            'sumber_coa' => JenisSimpanan::coaKodeProduk(MandatorySavingsService::PRODUK_WAJIB),
            'dry_run' => $dryRun,
        ]);

        $anggotaList = Anggota::aktif()->orderBy('id')->get();

        $diterbitkan = 0;
        $sudahAda = 0;
        $dilewati = 0;
        $gagal = 0;
        $totalNominal = 0.0;

        foreach ($anggotaList as $anggota) {
            try {
                if ($dryRun) {
                    $this->line(sprintf(
                        '  [RENCANA] %s — %s | Rp %s',
                        $anggota->no_anggota ?? '-',
                        $anggota->nama_lengkap,
                        number_format($nominal, 0, ',', '.')
                    ));
                    $diterbitkan++;
                    $totalNominal += $nominal;

                    continue;
                }

                $bill = $this->service->issueBill($anggota, $periode, $tanggal);

                if ($bill === null) {
                    $dilewati++;
                    $this->warn(sprintf(
                        '  [DILEWATI] %s — %s (belum menjadi anggota pada periode %s).',
                        $anggota->no_anggota ?? '-',
                        $anggota->nama_lengkap,
                        $periode
                    ));

                    continue;
                }

                if (! $bill->wasRecentlyCreated) {
                    $sudahAda++;

                    continue;
                }

                $diterbitkan++;
                $totalNominal += (float) $bill->amount;

                $this->line(sprintf(
                    '  [TERBIT]   #%d %s — %s | Rp %s | %s',
                    $bill->id,
                    $anggota->no_anggota ?? '-',
                    $anggota->nama_lengkap,
                    number_format((float) $bill->amount, 0, ',', '.'),
                    $bill->status
                ));
            } catch (\Throwable $e) {
                $gagal++;
                $this->error(sprintf(
                    '  [GAGAL]    %s — %s : %s',
                    $anggota->no_anggota ?? '-',
                    $anggota->nama_lengkap,
                    $e->getMessage()
                ));

                Log::error('ProcessWajibDuesJob: gagal menerbitkan tagihan anggota.', [
                    'anggota_id' => $anggota->id,
                    'periode' => $periode,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $ringkasan = [
            'periode' => $periode,
            'total_anggota' => $anggotaList->count(),
            'diterbitkan' => $diterbitkan,
            'sudah_ada' => $sudahAda,
            'dilewati' => $dilewati,
            'gagal' => $gagal,
            'total_nominal' => Setting::bulatkanUang($totalNominal),
            'dry_run' => $dryRun,
        ];

        $this->newLine();
        $this->info(sprintf(
            'Selesai. Anggota ACTIVE: %d | Terbit: %d | Sudah ada: %d | Dilewati: %d | Gagal: %d | Total nominal: Rp %s',
            $ringkasan['total_anggota'],
            $ringkasan['diterbitkan'],
            $ringkasan['sudah_ada'],
            $ringkasan['dilewati'],
            $ringkasan['gagal'],
            number_format($totalNominal, 0, ',', '.')
        ));

        Log::info('ProcessWajibDuesJob: proses penerbitan tagihan bulanan selesai.', $ringkasan);

        return $gagal > 0 ? self::FAILURE : self::SUCCESS;
    }
}
