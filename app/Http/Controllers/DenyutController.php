<?php

namespace App\Http\Controllers;

use App\Support\DenyutData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * DenyutController — kanal deteksi perubahan data real-time.
 *
 * AKAR MASALAH YANG DISELESAIKAN
 * ------------------------------
 * Dashboard koperasi dirender di server (berbasis sesi) dan tidak memiliki
 * WebSocket (BROADCAST_DRIVER=log) maupun queue asinkron
 * (QUEUE_CONNECTION=sync). Tidak ada jalur apa pun yang memberi tahu peramban
 * bahwa data telah berubah, sehingga angka baru muncul hanya setelah pengguna
 * menekan refresh.
 *
 * SOLUSI — DETEKSI PERUBAHAN YANG SANGAT MURAH
 * --------------------------------------------
 * Endpoint ini TIDAK menyentuh database. Ia hanya membaca beberapa integer
 * dari cache (buku versi `DenyutData`) dan mengembalikan sidik jarinya. Klien
 * memanggilnya secara berkala (adaptive polling) atau menyambungnya lewat SSE;
 * begitu sidik jari berubah, klien memuat ulang HANYA bagian yang terdampak
 * melalui endpoint `?ringkas=1` pada halaman terkait.
 *
 * DUA TRANSPORTASI, SATU SUMBER KEBENARAN
 * ---------------------------------------
 *   1. GET /denyut              → JSON pendek (tanda + versi per domain).
 *   2. GET /denyut?stream=1     → Server-Sent Events (push instan).
 *   3. GET /denyut?tunggu=1     → long-polling (tahan sambungan s.d. ada
 *                                 perubahan atau batas waktu tercapai).
 *
 * Respons ketiganya memuat `tanda` yang sama sehingga klien cukup punya satu
 * pemahaman. Transportasi aktif dipilih lewat config `denyut.sse`/`denyut.tunggu`
 * (lihat config/denyut.php) agar instalasi dengan worker PHP terbatas tidak
 * kehabisan proses karena sambungan berumur panjang.
 */
class DenyutController extends Controller
{
    /**
     * Titik masuk. Dispatcher transportasi.
     */
    public function index(Request $request): JsonResponse|StreamedResponse
    {
        if ($request->boolean('stream') && config('denyut.sse')) {
            return $this->stream();
        }

        if ($request->boolean('tunggu') && config('denyut.tunggu')) {
            return $this->longPoll((string) $request->query('tanda', ''));
        }

        return $this->snapshot();
    }

    /**
     * Status alur cepat (SSE & long polling) yang berlaku saat ini.
     *
     * Dikirim pada SETIAP respons agar klien dapat mendeteksi sendiri saat
     * operator mematikan alur cepat lewat variabel lingkungan
     * (`DENYUT_SSE`/`DENYUT_TUNGGU`) lalu beralih ke polling adaptif — tanpa
     * memuat ulang halaman dan tanpa mengubah kode.
     *
     * @return array{sse:bool,tunggu:bool}
     */
    private function transportasi(): array
    {
        return [
            'sse' => (bool) config('denyut.sse'),
            'tunggu' => (bool) config('denyut.tunggu'),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // JSON — polling adaptif
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Snapshot ringan: sidik jari perubahan + versi per domain.
     *
     * Tanpa kueri database; biaya hanya beberapa pembacaan cache.
     */
    public function snapshot(): JsonResponse
    {
        $peta = DenyutData::peta();

        $respons = response()->json([
            'tanda' => $peta['tanda'],
            'versi' => $peta['versi'],
            'detak_aktif' => (int) config('denyut.jeda_aktif'),
            'detak_idle' => (int) config('denyut.jeda_idle'),
            'transportasi' => $this->transportasi(),
            'waktu_server' => now()->toIso8601String(),
        ]);

        // Conditional request (ETag): peramban yang sudah punya salinan identik
        // menerima 304 tanpa body — menghemat bandwidth dan CPU di klien.
        return $respons->setEtag($peta['tanda'], weak: true)
            ->header('Cache-Control', 'no-cache, must-revalidate, max-age=0');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Long polling — fallback sempurna bila SSE tidak tersedia
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Tahan permintaan hingga sidik jari berubah atau batas waktu tercapai.
     *
     * Lebih efisien daripada polling cepat: satu sambungan menutupi banyak
     * interval, dan server membalas SEGERA saat ada perubahan (latensi < 1 dtk)
     * alih-alih menunggu interval berikutnya.
     */
    private function longPoll(string $tandaKlien): JsonResponse
    {
        $batas = microtime(true) + (int) config('denyut.maks_tunggu');
        $peta = DenyutData::peta();

        while ($peta['tanda'] === $tandaKlien && microtime(true) < $batas) {
            // Bebaskan lock sesi: permintaan panjang tidak boleh memblokir tab
            // lain milik sesi yang sama.
            if (function_exists('session_write_close')) {
                session_write_close();
            }

            usleep((int) config('denyut.jeda_tunggu') * 1_000_000);
            $peta = DenyutData::peta();
        }

        return response()->json([
            'berubah' => $peta['tanda'] !== $tandaKlien,
            'tanda' => $peta['tanda'],
            'versi' => $peta['versi'],
            'transportasi' => ['sse' => (bool) config('denyut.sse'), 'tunggu' => true],
            'waktu_server' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    // ─────────────────────────────────────────────────────────────────────
    // SSE — push instan
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Kanal Server-Sent Events.
     *
     * Server mengirim event `denyut` tepat saat sidik jari berubah, dan
     * komentar keep-alive (`: detak`) secara berkala agar proxy tidak menutup
     * sambungan. Klien menyegarkan UI segera setelah event `denyut` tiba.
     */
    public function stream(): StreamedResponse
    {
        $tandaAwal = DenyutData::peta()['tanda'];

        return response()->stream(function () use ($tandaAwal): void {
            // Bebaskan lock sesi: SSE berumur panjang, sedangkan sesi Laravel
            // mengunci berkas. Tanpa ini tab lain akan terblokir.
            if (function_exists('session_write_close')) {
                session_write_close();
            }

            ignore_user_abort(false);
            set_time_limit((int) config('denyut.maks_sse') + 5);

            $mulai = time();
            $terakhir = $tandaAwal;

            $peta = DenyutData::peta();
            $this->kirimEvent('siap', [
                'tanda' => $terakhir,
                'versi' => $peta['versi'],
                'detak_aktif' => (int) config('denyut.jeda_aktif'),
                'detak_idle' => (int) config('denyut.jeda_idle'),
                'transportasi' => $this->transportasi(),
            ]);

            while (! connection_aborted()) {
                if ((time() - $mulai) >= (int) config('denyut.maks_sse')) {
                    // Berakhir wajar → EventSource menyambung ulang otomatis.
                    $this->kirimEvent('putus', ['alasan' => 'batas-waktu']);
                    break;
                }

                // Alur cepat dimatikan lewat env saat sambungan ini hidup →
                // beri tahu klien lalu tutup sopan agar ia turun ke polling
                // adaptif tanpa menunggu batas waktu.
                if (! config('denyut.sse')) {
                    $this->kirimEvent('putus', [
                        'alasan' => 'alur-cepat-dimatikan',
                        'transportasi' => $this->transportasi(),
                    ]);
                    break;
                }

                $peta = DenyutData::peta();

                if ($peta['tanda'] !== $terakhir) {
                    $terakhir = $peta['tanda'];
                    $this->kirimEvent('denyut', [
                        'tanda' => $terakhir,
                        'versi' => $peta['versi'],
                        'transportasi' => $this->transportasi(),
                    ]);
                } else {
                    echo ": detak\n\n"; // keep-alive, tanpa beban JSON
                }

                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                @flush();

                sleep((int) config('denyut.jeda_sse'));
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no', // matikan buffer reverse-proxy (nginx)
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * Kirim satu event SSE berformat standar.
     *
     * @param  array<string,mixed>  $data
     */
    private function kirimEvent(string $nama, array $data): void
    {
        echo "event: {$nama}\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";
    }
}
