<?php

namespace App\Jobs;

use App\Services\MandatorySavingsService;
use App\Support\QueueOperasional;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job: pemrosesan harian Simpanan Wajib (terbit tagihan, auto-debit, tandai
 * OVERDUE).
 *
 * Rekomendasi 4 langkah 5 — membungkus `MandatorySavingsService::processDaily()`
 * tanpa menduplikasi logika bisnis.
 *
 * IDEMPOTEN: `processDaily()` memakai `firstOrCreate(['member_id',
 * 'bill_period'])` sehingga tagihan tidak pernah ganda, dan jurnal auto-debit
 * dibukukan per tagihan (bukan per pemanggilan). Menjalankan job dua kali pada
 * tanggal yang sama tidak menggandakan apa pun.
 */
class ProsesTagihanSimpananWajibJob implements ShouldQueue
{
    use Queueable;

    /**
     * Ringkasan hasil eksekusi terakhir (dibaca command pada mode sinkron).
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

    public function __construct(public ?string $tanggal = null, public ?int $userId = null)
    {
        $parameter = QueueOperasional::parameter('proses_tagihan_simpanan_wajib');

        $this->tries = $parameter['tries'];
        $this->backoff = $parameter['backoff'];
        $this->timeout = $parameter['timeout'];
    }

    public function handle(MandatorySavingsService $service): void
    {
        $this->hasil = $this->jalankan($service);
    }

    /**
     * Eksekusi SINKRON yang mengembalikan ringkasan hasil. Dipakai command
     * agar ringkasan dapat ditampilkan tanpa memanggil layanan dua kali.
     *
     * @return array<string, mixed>
     */
    public function jalankanSinkron(): array
    {
        $this->hasil = $this->jalankan(app(MandatorySavingsService::class));

        return $this->hasil;
    }

    /**
     * @return array<string, mixed>
     */
    private function jalankan(MandatorySavingsService $service): array
    {
        $tanggal = $this->tanggal ? Carbon::parse($this->tanggal) : Carbon::today();

        $hasil = $service->processDaily($tanggal, false, $this->userId);

        Log::info('ProsesTagihanSimpananWajibJob: pemrosesan harian selesai.', [
            'tanggal' => $tanggal->toDateString(),
            'issued' => $hasil['issued'],
            'paid' => $hasil['paid'],
            'overdue' => $hasil['overdue'],
            'ditangguhkan' => $hasil['ditangguhkan'] ?? 0,
            'skipped' => $hasil['skipped'],
            'gagal' => $hasil['gagal'],
        ]);

        return $hasil;
    }

    public function failed(Throwable $e): void
    {
        Log::error('ProsesTagihanSimpananWajibJob GAGAL (percobaan habis).', [
            'tanggal' => $this->tanggal,
            'pesan' => $e->getMessage(),
        ]);
    }
}
