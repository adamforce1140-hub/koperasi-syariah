<?php

namespace App\Jobs;

use App\Models\Deposito;
use App\Services\DepositoService;
use App\Support\QueueOperasional;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job: pemrosesan SATU deposito yang sudah jatuh tempo sesuai mode ARO.
 *
 * Rekomendasi 4 langkah 5 — seluruh operasi berat diekstrak menjadi job yang
 * dapat dijalankan SINKRON (default, `FEATURE_QUEUE_ASYNC=false`) atau melalui
 * antrian. Job ini TIDAK memuat logika bisnis apa pun: ia hanya membungkus
 * `DepositoService::processMaturity()` yang sudah teruji.
 *
 * IDEMPOTEN: `processMaturity()` memakai `lockForUpdate` + guard status
 * `ACTIVE` sehingga eksekusi kedua (retry atau double-dispatch) tidak akan
 * menggandakan jurnal/mutasi. Model di-`refresh` dari database sebelum
 * dikerjakan agar status terbaru terbaca — bukan snapshot saat job dibuat.
 */
class ProsesJatuhTempoDepositoJob implements ShouldQueue
{
    use Queueable;

    /**
     * Hasil eksekusi SINKRON terakhir (dibaca command untuk ringkasan).
     * Null bila deposito tidak ditemukan, status bukan ACTIVE, atau job
     * didorong ke antrian.
     *
     * @var array<string, mixed>|null
     */
    public ?array $hasil = null;

    /* Deklarasi eksplisit agar tidak memicu deprecation dynamic property
     | (PHP 8.2+); nilai WAJIB diisi dari `QueueOperasional::parameter()`. */
    public int $tries;

    /** @var array<int, int> */
    public array $backoff;

    public int $timeout;

    /**
     * Parameter ketahanan diambil dari SSOT `config/job_operasional.php`
     * sehingga tidak ada angka ajaib (magic number) di kelas ini.
     */
    public function __construct(public int $depositoId, public ?int $userId = null)
    {
        $parameter = QueueOperasional::parameter('proses_jatuh_tempo_deposito');

        $this->tries = $parameter['tries'];
        $this->backoff = $parameter['backoff'];
        $this->timeout = $parameter['timeout'];
    }

    public function handle(DepositoService $depositoService): void
    {
        $this->hasil = $this->jalankan($depositoService);
    }

    /**
     * Eksekusi SINKRON yang mengembalikan hasil pemrosesan (atau null bila
     * dilewati karena idempoten). Dipakai command agar dapat menampilkan
     * ringkasan tanpa memanggil layanan dua kali.
     *
     * @return array<string, mixed>|null
     */
    public function jalankanSinkron(): ?array
    {
        $this->hasil = $this->jalankan(app(DepositoService::class));

        return $this->hasil;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jalankan(DepositoService $depositoService): ?array
    {
        $deposito = Deposito::with('product')->find($this->depositoId);

        if (! $deposito) {
            Log::warning('ProsesJatuhTempoDepositoJob: deposito tidak ditemukan — dilewati.', [
                'deposito_id' => $this->depositoId,
            ]);

            return null;
        }

        // Guard idempotensi tingkat job: hanya deposito yang masih ACTIVE yang
        // perlu diproses. Bila sudah CLOSED/MATURED (mis. job dijalankan ulang),
        // proses dilewati agar tidak menggandakan jurnal pencairan.
        if ($deposito->status !== Deposito::STATUS_ACTIVE) {
            Log::info('ProsesJatuhTempoDepositoJob: status bukan ACTIVE — dilewati (idempoten).', [
                'deposito_id' => $this->depositoId,
                'status' => $deposito->status,
            ]);

            return null;
        }

        $hasil = $depositoService->processMaturity($deposito, $this->userId);

        Log::info('ProsesJatuhTempoDepositoJob: deposito jatuh tempo diproses.', [
            'deposito_id' => $this->depositoId,
            'deposito_no' => $deposito->deposito_number,
            'mode' => $hasil['mode'] ?? null,
            'status_akhir' => $hasil['status'] ?? null,
            'no_jurnal' => data_get($hasil, 'jurnal.no_jurnal'),
        ]);

        return $hasil;
    }

    /**
     * Dipanggil setelah seluruh percobaan habis. Karena `processMaturity()`
     * sudah transaksional, kegagalan di sini berarti TIDAK ada efek parsial —
     * cukup dicatat untuk audit.
     */
    public function failed(Throwable $e): void
    {
        Log::error('ProsesJatuhTempoDepositoJob GAGAL (percobaan habis).', [
            'deposito_id' => $this->depositoId,
            'pesan' => $e->getMessage(),
        ]);
    }
}
