<?php

/*
|--------------------------------------------------------------------------
| Konfigurasi Denyut Sinkronisasi Real-Time
|--------------------------------------------------------------------------
| Sistem ini dirender di server (berbasis sesi) tanpa WebSocket/Pusher dan
| tanpa queue asinkron. Sinkronisasi antar-peramban dilakukan lewat kanal
| "denyut" (lihat App\Support\DenyutData & App\Http\Controllers\DenyutController):
|
|   1. SSE (Server-Sent Events) — push instan, latensi < 1 detik.
|   2. Long polling            — fallback bila SSE tak tersedia.
|   3. Polling adaptif        — cadangan terakhir, selalu aktif.
|
| Seluruh nilai dapat ditimpa lewat .env agar dapat disesuaikan dengan
| kapasitas server tanpa mengubah kode.
*/

return [

    /*
    | Aktifkan SSE. Matikan bila PHP-FPM hanya punya sedikit worker, karena
    | setiap sambungan SSE menahan satu proses selama `maks_sse` detik.
    */
    'sse' => (bool) env('DENYUT_SSE', true),

    /*
    | Aktifkan long polling sebagai transportasi alternatif. Jauh lebih hemat
    | proses daripada SSE, tetapi tetap memberi latensi jauh di bawah 2 detik.
    */
    'tunggu' => (bool) env('DENYUT_TUNGGU', true),

    /*
    | Jeda antar-pemeriksaan versi pada sambungan SSE (detik). Nilai kecil
    | membuat perubahan tercermin cepat, tetapi menaikkan beban CPU. Nilai
    | bawaan 1 detik memberi respons < 2 detik sesuai target.
    */
    'jeda_sse' => (int) env('DENYUT_JEDA_SSE', 1),

    /*
    | Umur maksimum satu sambungan SSE (detik). Setelah ini server menutup
    | sambungan dengan sopan dan EventSource menyambung ulang otomatis.
    */
    'maks_sse' => (int) env('DENYUT_MAKS_SSE', 25),

    /*
    | Batas waktu satu permintaan long polling (detik).
    */
    'maks_tunggu' => (int) env('DENYUT_MAKS_TUNGGU', 20),

    /*
    | Jeda antar-pemeriksaan versi selama long polling (detik).
    */
    'jeda_tunggu' => (float) env('DENYUT_JEDA_TUNGGU', 0.5),

    /*
    | Interval polling adaptif pada KLIEN (detik) — dikirim ke klien lewat
    | respons JSON. `jeda_aktif` dipakai sesaat setelah perubahan terdeteksi
    | (tab sedang dilihat), `jeda_idle` dipakai saat hening.
    */
    'jeda_aktif' => (int) env('DENYUT_JEDA_AKTIF', 3),
    'jeda_idle' => (int) env('DENYUT_JEDA_IDLE', 10),

];
