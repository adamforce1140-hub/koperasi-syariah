<?php

/**
 * Konfigurasi operasi berat (job asinkron) — Rekomendasi 4, langkah 5.
 *
 * SATU-SATUNYA SUMBER KEBENARAN untuk:
 *   - apakah sebuah job dijalankan melalui antrian (asinkron) atau langsung
 *     (sinkron);
 *   - parameter ketahanan job (tries, backoff, timeout) per operasi.
 *
 * P4 "Default Aman": `aktif` bernilai FALSE. Selama false, SELURUH job
 * dijalankan SINKRON lewat `dispatchSync` — persis perilaku sistem sebelum
 * ekstraksi job. Dengan demikian menyalakan antrian benar-benar opsional dan
 * tidak mengubah hasil apa pun sampai operator mengaktifkannya.
 *
 * Cara mengaktifkan (setelah lulus uji):
 *   1. set `QUEUE_CONNECTION=database` pada .env;
 *   2. jalankan `php artisan queue:table` + `php artisan migrate` (tabel jobs &
 *      failed_jobs) bila belum ada;
 *   3. set `FEATURE_QUEUE_ASYNC=true`;
 *   4. jalankan worker: `php artisan queue:work --tries=3`.
 */
return [

    /*
    |----------------------------------------------------------------------
    | Saklar utama
    |----------------------------------------------------------------------
    | false → job dijalankan sinkron (perilaku lama, default aman).
    | true  → job didorong ke antrian dan diproses oleh worker.
    */
    'aktif' => (bool) env('FEATURE_QUEUE_ASYNC', false),

    /*
    |----------------------------------------------------------------------
    | Parameter ketahanan per job
    |----------------------------------------------------------------------
    | `tries`   : jumlah maksimum percobaan sebelum masuk `failed_jobs`.
    | `backoff` : jeda detik antar percobaan (naik bertahap).
    | `timeout` : batas detik satu eksekusi job.
    |
    | Seluruh job WAJIB idempoten: cek status sebelum berefek sehingga
    | percobaan ulang tidak menggandakan jurnal/mutasi.
    */
    'jobs' => [
        'proses_jatuh_tempo_deposito' => [
            'tries' => 3,
            'backoff' => [60, 300, 900],
            'timeout' => 900,
        ],
        'distribusi_bagi_hasil' => [
            'tries' => 3,
            'backoff' => [60, 300, 900],
            'timeout' => 1800,
        ],
        'tutup_buku_shu' => [
            'tries' => 2,
            'backoff' => [120, 600],
            'timeout' => 1800,
        ],
        'proses_tagihan_simpanan_wajib' => [
            'tries' => 3,
            'backoff' => [60, 300, 900],
            'timeout' => 1200,
        ],
    ],

];
