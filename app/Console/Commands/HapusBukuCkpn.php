<?php

namespace App\Console\Commands;

use App\Models\Pembiayaan;
use App\Services\CkpnService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command: php artisan ckpn:hapus-buku
 *
 * CKPN-5 — operasionalisasi HAPUS BUKU (write-off) piutang pembiayaan macet
 * sesuai PSAK 71 (penghentian pengakuan aset keuangan) dan POJK 12/2023.
 *
 * Hapus buku adalah keputusan yang mengubah neraca, sehingga TIDAK dijalankan
 * otomatis oleh scheduler: pemanggil WAJIB menyebutkan sasaran secara eksplisit
 * (--id atau --kol) dan disarankan memakai --dry-run lebih dahulu.
 *
 *   php artisan ckpn:hapus-buku --id=123 --dry-run
 *   php artisan ckpn:hapus-buku --kol=kol5 --user=1
 *
 * Jurnal yang dihasilkan (lihat {@see CkpnService::hapusBuku()}):
 *   DEBET 1-2100 (sebesar pokok, maksimal saldo penyisihan tersedia)
 *   DEBET 5-4001 (kekurangan bila cadangan belum mencukupi)
 *   DEBET 1-2101 (membatalkan kontra margin yang belum terealisasi)
 *   KREDIT 1-200x Piutang per akad (nilai bruto)
 */
class HapusBukuCkpn extends Command
{
    protected $signature = 'ckpn:hapus-buku
                                {--id=*     : ID pembiayaan yang akan dihapus buku (dapat diulang)}
                                {--kol=     : Kode kolektibilitas sasaran, mis. kol5}
                                {--date=    : Tanggal efektif hapus buku (Y-m-d), default hari ini}
                                {--user=    : ID pengguna yang mengotorisasi (dicatat di jurnal)}
                                {--dry-run  : Tampilkan rencana tanpa memposting jurnal}';

    protected $description = 'Menghapus buku (write-off) piutang pembiayaan macet terhadap cadangan kerugian CKPN (PSAK 71).';

    public function __construct(private CkpnService $ckpnService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tanggal = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::today();
        $dryRun = (bool) $this->option('dry-run');
        $userId = $this->option('user') !== null ? (int) $this->option('user') : null;

        $this->info('=== Hapus Buku Piutang Pembiayaan (PSAK 71) | '.$tanggal->format('d/m/Y').' ==='
            .($dryRun ? ' [DRY-RUN]' : ''));

        $sasaran = $this->sasaran();

        if ($sasaran === null) {
            $this->error('Sebutkan sasaran hapus buku: --id=<id> (dapat diulang) atau --kol=<kode kolektibilitas>.');

            return self::INVALID;
        }

        if ($sasaran->isEmpty()) {
            $this->warn('Tidak ada pembiayaan yang memenuhi kriteria hapus buku.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'No. Pembiayaan', 'Akad', 'Kolektibilitas', 'Sisa Piutang'],
            $sasaran->map(fn (Pembiayaan $p) => [
                $p->id,
                $p->no_pembiayaan,
                $p->akad_pembiayaan,
                strtoupper($this->ckpnService->kolektibilitasKode($p, $tanggal)),
                number_format((float) $p->sisa_pembiayaan, 2, ',', '.'),
            ])->all()
        );

        if ($dryRun) {
            $this->warn('Mode dry-run: tidak ada jurnal yang diposting.');

            return self::SUCCESS;
        }

        $berhasil = 0;
        $gagal = 0;

        foreach ($sasaran as $pembiayaan) {
            try {
                $jurnal = $this->ckpnService->hapusBuku($pembiayaan, $tanggal, $userId);

                if ($jurnal === null) {
                    $this->line("  [SKIP] #{$pembiayaan->id} {$pembiayaan->no_pembiayaan} — sudah dihapus buku / tidak ada sisa.");

                    continue;
                }

                $berhasil++;

                $this->info("  [OK] #{$pembiayaan->id} {$pembiayaan->no_pembiayaan} — jurnal {$jurnal->no_jurnal} "
                    .'(Rp '.number_format((float) $jurnal->total_kredit, 2, ',', '.').')');

                Log::info('[CKPN] Hapus buku piutang pembiayaan.', [
                    'pembiayaan_id' => $pembiayaan->id,
                    'no_pembiayaan' => $pembiayaan->no_pembiayaan,
                    'no_jurnal' => $jurnal->no_jurnal,
                    'tanggal' => $tanggal->toDateString(),
                    'user_id' => $userId,
                ]);
            } catch (\Throwable $e) {
                $gagal++;

                $this->error("  [GAGAL] #{$pembiayaan->id} {$pembiayaan->no_pembiayaan} — {$e->getMessage()}");

                Log::error('[CKPN] Gagal hapus buku: '.$e->getMessage(), [
                    'pembiayaan_id' => $pembiayaan->id,
                ]);
            }
        }

        $this->newLine();
        $this->info("Selesai. Berhasil: {$berhasil} | Gagal: {$gagal}");

        return $gagal > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Daftar pembiayaan sasaran hapus buku.
     *
     * Mengembalikan null bila pemanggil tidak menyebutkan sasaran apa pun —
     * hapus buku tidak boleh berjalan "untuk semua" secara tidak sengaja.
     *
     * @return Collection<int, Pembiayaan>|null
     */
    private function sasaran(): ?Collection
    {
        $ids = array_values(array_filter((array) $this->option('id'), fn ($v) => $v !== null && $v !== ''));
        $kol = $this->option('kol') !== null ? strtolower(trim((string) $this->option('kol'))) : null;

        if (empty($ids) && $kol === null) {
            return null;
        }

        $query = Pembiayaan::query()
            ->tercair()
            ->where('sisa_pembiayaan', '>', 0)
            ->whereNull('tanggal_hapus_buku');

        if (! empty($ids)) {
            $query->whereIn('id', $ids);
        }

        $kandidat = $query->get();

        if ($kol !== null) {
            $kandidat = $kandidat
                ->filter(fn (Pembiayaan $p) => $this->ckpnService->kolektibilitasKode($p) === $kol)
                ->values();
        }

        return $kandidat;
    }
}
