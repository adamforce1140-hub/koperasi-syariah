<?php

namespace App\Console\Commands;

use App\Models\Angsuran;
use App\Models\AutodebitUsulan;
use App\Models\JenisSimpanan;
use App\Models\KebijakanKoperasi;
use App\Models\Pembiayaan;
use App\Models\Rekening;
use App\Models\TazirPenangguhan;
use App\Services\AngsuranTransactionService;
use App\Services\AutodebitService;
use App\Services\TazirService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command: php artisan angsuran:run-autodebet
 *
 * KEBIJAKAN: "TIDAK ADA AUTO-DEBIT TANPA KONFIRMASI PENGGUNA".
 *
 * Command ini TIDAK memotong saldo anggota. Ia hanya:
 *   1. Menemukan angsuran jatuh tempo yang memakai metode AUTODEBET, lalu
 *      MENGUSULKAN pemotongan ke Pusat Konfirmasi (AutodebitService::usulkan).
 *   2. Menemukan tunggakan ta'zir susulan (keringanan ditolak Manajer), lalu
 *      mengusulkan pemotongannya juga.
 *
 * Pemotongan sebenarnya baru terjadi ketika pengguna berwenang menekan
 * konfirmasi (lihat AutodebitService::konfirmasi). Dengan begitu tidak ada
 * dana yang berpindah tanpa persetujuan manusia.
 */
class RunAutodebetAngsuran extends Command
{
    protected $signature = 'angsuran:run-autodebet
                                {--dry-run : Simulasi tanpa proses nyata}
                                {--date=   : Tanggal proses (Y-m-d), default hari ini}';

    protected $description = 'Usulkan autodebet angsuran & ta\'zir susulan (menunggu konfirmasi pengguna, PSAK syariah)';

    public function __construct(
        private AngsuranTransactionService $angsuranService,
        private TazirService $tazirService,
        private AutodebitService $autodebitService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $tanggal = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : Carbon::today();

        $config = KebijakanKoperasi::getSetting();

        $this->info("=== Usulan Autodebet Angsuran | {$tanggal->format('d/m/Y')} ===".($isDryRun ? ' [DRY-RUN]' : ''));

        // Ambil semua angsuran jatuh tempo yang belum dibayar
        $angsuranList = Angsuran::with(['pembiayaan.anggota'])
            ->whereIn('status_angsuran', ['Jatuh Tempo', 'Terlambat', 'Belum Jatuh Tempo'])
            ->whereNull('tanggal_bayar')
            ->whereHas('pembiayaan', fn ($q) => $q->statusIn([
                Pembiayaan::STATUS_DICAIRKAN,
                Pembiayaan::STATUS_DISETUJUI,
                Pembiayaan::STATUS_AKTIF,
                Pembiayaan::STATUS_RESTRUKTURISASI,
            ]))
            ->whereDate('tanggal_jatuh_tempo', '<=', $tanggal->toDateString())
            ->get();

        $this->info("Ditemukan {$angsuranList->count()} angsuran jatuh tempo.");

        $berhasil = 0;
        $gagal = 0;

        foreach ($angsuranList as $angsuran) {
            $pembiayaan = $angsuran->pembiayaan;
            $anggota = $pembiayaan?->anggota;

            if (! $pembiayaan || ! $anggota) {
                $this->warn("  [SKIP] Angsuran #{$angsuran->id} — data pembiayaan/anggota tidak lengkap.");

                continue;
            }

            // Filter: hanya proses jika default_method = AUTODEBET
            $metode = strtoupper($config->tazir_default_method ?? 'TUNAI');
            if ($metode !== 'AUTODEBET') {
                $this->line("  [SKIP] {$anggota->nama_lengkap} — metode default bukan AUTODEBET ({$metode}).");

                continue;
            }

            $nominalTazir = $this->tazirService->calculate($angsuran);

            $label = "Angsuran #{$angsuran->id} ke-{$angsuran->angsuran_ke} | {$pembiayaan->no_pembiayaan} | {$anggota->nama_lengkap}";
            $this->line("  >> {$label}");
            $this->line('     Pokok: Rp '.number_format($angsuran->pokok_angsuran, 0, ',', '.').
                        ' | Margin: Rp '.number_format($angsuran->margin_ujroh, 0, ',', '.').
                        " | Ta'zir: Rp ".number_format($nominalTazir, 0, ',', '.'));

            if ($isDryRun) {
                $this->info('     [DRY-RUN] Tidak diproses (akan diusulkan bila dijalankan).');

                continue;
            }

            try {
                // === USULKAN, JANGAN POTONG ===
                $usulan = $this->autodebitService->usulkan(
                    AutodebitUsulan::KANAL_ANGSURAN,
                    (int) $angsuran->id,
                    $anggota,
                    (float) $angsuran->jumlah_angsuran,
                    [
                        'periode' => $angsuran->tanggal_jatuh_tempo
                            ? Carbon::parse($angsuran->tanggal_jatuh_tempo)->format('Y-m')
                            : null,
                        'alasan' => AutodebitService::wajibKonfirmasi() ? 'MENUNGGU_KONFIRMASI' : 'AUTODEBET',
                        'catatan' => "Usulan autodebet angsuran ke-{$angsuran->angsuran_ke} | {$pembiayaan->no_pembiayaan}.",
                        'referensi_tipe' => Angsuran::class,
                    ]
                );

                $this->info("     [USULAN #{$usulan->id}] Menunggu konfirmasi — dana BELUM dipotong.");
                $berhasil++;

                // Akumulasi tunggakan ta'zir (keringanan ditolak Manajer) juga
                // DIUSULKAN (bukan dipotong) agar tetap menunggu konfirmasi.
                $rekeningSukarela = Rekening::where('anggota_id', $pembiayaan->anggota_id)
                    ->whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_SUKARELA))
                    ->where('status_rekening', 'Aktif')
                    ->first();

                if ($rekeningSukarela) {
                    $tunggakanList = TazirPenangguhan::pendingAutodebetSusulan()
                        ->where('anggota_id', $pembiayaan->anggota_id)
                        ->orderBy('id')
                        ->get();

                    foreach ($tunggakanList as $tunggakan) {
                        $usulanTazir = $this->autodebitService->usulkan(
                            AutodebitUsulan::KANAL_TAZIR,
                            (int) $tunggakan->id,
                            $anggota,
                            (float) $tunggakan->nominal_tazir,
                            [
                                'rekening_id' => $rekeningSukarela->id,
                                'alasan' => AutodebitService::wajibKonfirmasi() ? 'MENUNGGU_KONFIRMASI' : 'AUTODEBET',
                                'catatan' => "Usulan ta'zir susulan | {$pembiayaan->no_pembiayaan}.",
                                'referensi_tipe' => TazirPenangguhan::class,
                            ]
                        );
                        $this->info("     [USULAN TAZIR #{$usulanTazir->id}] Menunggu konfirmasi — dana BELUM dipotong.");
                    }
                }

            } catch (\Exception $e) {
                $this->error('     [GAGAL] '.$e->getMessage());
                Log::warning("Usulan autodebet gagal: angsuran #{$angsuran->id} | {$pembiayaan->no_pembiayaan} | ".$e->getMessage());
                $gagal++;
            }
        }

        $this->newLine();
        $this->info("Selesai. Diusulkan: {$berhasil} | Gagal/Skip: {$gagal}");
        $this->comment('Catatan: tidak ada dana yang dipotong. Konfirmasi di Pusat Konfirmasi Auto-Debit.');

        return self::SUCCESS;
    }
}
