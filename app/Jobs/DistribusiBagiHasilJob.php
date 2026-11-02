<?php

namespace App\Jobs;

use App\Services\DepositoService;
use App\Services\PendapatanService;
use App\Support\QueueOperasional;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job: distribusi bagi hasil deposito Mudharabah untuk satu periode bulan buku.
 *
 * Rekomendasi 4 langkah 5 — membungkus `PendapatanService` +
 * `DepositoService::bagiHasilPeriode()` tanpa menduplikasi logika bisnis.
 *
 * IDEMPOTEN: `bagiHasilPeriode()` melewati deposito yang periodenya SUDAH
 * dibayar (berbasis catatan pembayaran per deposito per periode), sehingga
 * eksekusi ulang tidak menggandakan bagi hasil maupun jurnal.
 */
class DistribusiBagiHasilJob implements ShouldQueue
{
    use Queueable;

    /**
     * Ringkasan hasil eksekusi terakhir. Hanya terisi pada eksekusi SINKRON
     * (dibaca command untuk menampilkan ringkasan); null bila job didorong ke
     * antrian.
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

    public function __construct(
        public int $year,
        public int $month,
        public ?int $userId = null,
    ) {
        $parameter = QueueOperasional::parameter('distribusi_bagi_hasil');

        $this->tries = $parameter['tries'];
        $this->backoff = $parameter['backoff'];
        $this->timeout = $parameter['timeout'];
    }

    public function handle(DepositoService $depositoService, PendapatanService $pendapatanService): void
    {
        $this->hasil = $this->jalankan($depositoService, $pendapatanService);
    }

    /**
     * Eksekusi SINKRON yang mengembalikan ringkasan hasil.
     *
     * Dipakai oleh command agar dapat menampilkan ringkasan tanpa memanggil
     * layanan dua kali (yang berisiko efek ganda). Menyalakan antrian tetap
     * memakai jalur `handle()` biasa.
     *
     * @return array{dibagikan: int, dilewati: int, total_porsi_anggota: float, periode: string}
     */
    public function jalankanSinkron(): array
    {
        $hasil = $this->jalankan(
            app(DepositoService::class),
            app(PendapatanService::class)
        );

        $this->hasil = $hasil;

        return $hasil;
    }

    private function jalankan(DepositoService $depositoService, PendapatanService $pendapatanService): array
    {
        $pendapatan = $pendapatanService->pendapatanInvestasiBulanan($this->year, $this->month);

        $hasil = $depositoService->bagiHasilPeriode(
            $this->year,
            $this->month,
            (float) $pendapatan['total'],
            $this->userId
        );

        Log::info('DistribusiBagiHasilJob: distribusi bagi hasil selesai.', [
            'periode' => $hasil['periode'] ?? "{$this->month}/{$this->year}",
            'dibagikan' => $hasil['dibagikan'],
            'dilewati' => $hasil['dilewati'],
            'total_porsi_anggota' => $hasil['total_porsi_anggota'],
        ]);

        return $hasil;
    }

    public function failed(Throwable $e): void
    {
        Log::error('DistribusiBagiHasilJob GAGAL (percobaan habis).', [
            'year' => $this->year,
            'month' => $this->month,
            'pesan' => $e->getMessage(),
        ]);
    }
}
