<?php

namespace App\Jobs;

use App\Services\IkhtisarShuService;
use App\Support\QueueOperasional;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job: TUTUP BUKU periode akuntansi (jurnal penutup Pendapatan & Beban →
 * akun 3-9000 Ikhtisar SHU).
 *
 * Rekomendasi 4 langkah 5 — menggerakkan operasi tutup buku ke luar siklus HTTP
 * agar request tidak menahan transaksi panjang. Job ini hanya membungkus
 * `IkhtisarShuService::tutupBuku()` yang sudah teruji.

 * IDEMPOTEN: `tutupBuku()` menghitung pratinjau lebih dulu dan mengembalikan
 * `sudah_ditutup = true` tanpa menulis apa pun bila seluruh saldo Pendapatan &
 * Beban sudah dipindahkan. Dengan begitu retry / double-dispatch tidak pernah
 * membuat jurnal penutup ganda.
 *
 * CATATAN CAKUPAN: job ini TIDAK mendistribusikan SHU ke anggota. Distribusi
 * per anggota (mode HOLD tunggakan, jurnal per rekening, jejak audit) tetap
 * memakai jalur `ShuController::proses()` yang telah teruji. Memisahkan keduanya
 * mencegah jurnal ganda pada dana anggota.
 */
class TutupBukuShuJob implements ShouldQueue
{
    use Queueable;

    /**
     * Hasil eksekusi SINKRON terakhir (dibaca command untuk ringkasan).
     *
     * @var array<string, mixed>|null
     */
    public ?array $hasil = null;

    /* Parameter ketahanan job dideklarasikan eksplisit: menulisnya sebagai
     | dynamic property memicu deprecation PHP 8.2+ ("Creation of dynamic
     | property"). Tanpa nilai bawaan — nilai WAJIB diisi dari
     | `QueueOperasional::parameter()` pada konstruktor (SSOT config). */
    public int $tries;

    /** @var array<int, int> */
    public array $backoff;

    public int $timeout;

    public function __construct(
        /** Batas tanggal tutup buku (Y-m-d). Null = ikuti kebijakan periode. */
        public ?string $sampai = null,
        public ?int $userId = null,
    ) {
        $parameter = QueueOperasional::parameter('tutup_buku_shu');

        $this->tries = $parameter['tries'];
        $this->backoff = $parameter['backoff'];
        $this->timeout = $parameter['timeout'];
    }

    public function handle(IkhtisarShuService $ikhtisar): void
    {
        $this->hasil = $this->jalankan($ikhtisar);
    }

    /**
     * Eksekusi SINKRON yang mengembalikan ringkasan hasil.
     *
     * @return array<string, mixed>
     */
    public function jalankanSinkron(): array
    {
        $this->hasil = $this->jalankan(app(IkhtisarShuService::class));

        return $this->hasil;
    }

    /**
     * @return array<string, mixed>
     */
    private function jalankan(IkhtisarShuService $ikhtisar): array
    {
        $hasil = $ikhtisar->tutupBuku($this->sampai, $this->userId);

        Log::info('TutupBukuShuJob: tutup buku selesai.', [
            'tanggal' => $hasil['tanggal'] ?? null,
            'sudah_ditutup' => $hasil['sudah_ditutup'] ?? null,
            'jumlah_baris' => $hasil['jumlah_baris'] ?? null,
            'selisih_shu' => $hasil['selisih_shu'] ?? null,
            'status' => $hasil['status'] ?? null,
            'no_jurnal' => data_get($hasil, 'jurnal.no_jurnal'),
        ]);

        return $hasil;
    }

    public function failed(Throwable $e): void
    {
        Log::error('TutupBukuShuJob GAGAL (percobaan habis).', [
            'sampai' => $this->sampai,
            'pesan' => $e->getMessage(),
        ]);
    }
}
