<?php

/**
 * Konfigurasi hardening keamanan data (UU No. 27/2022 tentang Pelindungan
 * Data Pribadi).
 *
 * SEMUA nilai dibaca dari variabel lingkungan DI SINI — bukan langsung di
 * middleware/route — karena `env()` di luar file config akan bernilai null
 * begitu `php artisan config:cache` dijalankan di produksi.
 *
 * Lihat: docs/TAHAP10-KEAMANAN-DATA-UU-PDP.md
 */
return [

    /*
    |----------------------------------------------------------------------
    | Jejak audit perubahan data pribadi
    |----------------------------------------------------------------------
    | Saat aktif, setiap perubahan kolom PII pada `anggota` dan
    | `pengajuan_rekening` dicatat ke tabel `audit_perubahan_pii`
    | (append-only + rantai hash). Matikan hanya bila benar-benar diperlukan;
    | UU PDP mewajibkan kemampuan menelusuri perubahan data pribadi.
    */
    'audit_pii' => env('KEAMANAN_AUDIT_PII', true),

    /*
    |----------------------------------------------------------------------
    | Header keamanan HTTP
    |----------------------------------------------------------------------
    */
    'header' => [
        // Kirim Content-Security-Policy (atau Report-Only) pada setiap respons.
        'csp_aktif' => env('KEAMANAN_CSP_AKTIF', true),

        // `false` = Content-Security-Policy-Report-Only. Berguna untuk
        // mengaktifkan CSP secara bertahap tanpa mematahkan halaman.
        //
        // S-04 — default aman: di produksi CSP di-ENFORCE (benar-benar
        // memblokir) agar XSS tidak lolos; di lokal/testing tetap Report-Only
        // supaya pengembangan tidak terhambat. Dapat ditimpa lewat env.
        'csp_enforce' => env(
            'KEAMANAN_CSP_ENFORCE',
            ! in_array((string) env('APP_ENV', 'production'), ['local', 'testing'], true)
        ),

        // S-04 — Mode ketat: aktifkan nonce per-permintaan dan BUANG
        // `'unsafe-inline'` dari script-src/style-src sehingga XSS inline benar
        // benar diblokir. Aktifkan hanya setelah seluruh blok <script>/<style>
        // inline diberi `nonce="{{ csp_nonce() }}"` (lihat KeamananHeader).
        // Default `false` agar perilaku terkini tidak berubah.
        'csp_strict' => env('KEAMANAN_CSP_STRICT', false),

        // HSTS hanya dikirim pada koneksi HTTPS. Biarkan `false` di lingkungan
        // pengembangan (Laragon) agar http://localhost tidak terkunci ke HTTPS.
        'hsts_aktif' => env('KEAMANAN_HSTS_AKTIF', false),
        'hsts_max_age' => (int) env('KEAMANAN_HSTS_MAX_AGE', 31536000),
    ],

    /*
    |----------------------------------------------------------------------
    | Kebijakan kekuatan password
    |----------------------------------------------------------------------
    | Diterapkan saat pengguna BARU dibuat atau password diganti (bukan saat
    | login). Di lingkungan `local`/`testing` panjang minimum diturunkan agar
    | akun demo & seeder (password bawaan `password123`) tetap sah.
    */
    /*
    |----------------------------------------------------------------------
    | Kebijakan retensi jejak audit (OJK-04)
    |----------------------------------------------------------------------
    | UU No. 27/2022 (PDP) Pasal 16 mewajibkan pembatasan penyimpanan: data
    | pribadi tidak disimpan lebih lama dari yang diperlukan. Di sisi lain,
    | UU No. 25/1992 & POJK mewajibkan jejak audit keuangan disimpan sekian
    | tahun. Kebijakan di bawah menyeimbangkan keduanya:
    |
    |   - Log AUDIT operasional/keuangan (audit_logs, laporan_keuangan_logs,
    |     approval_transaksi_logs): disimpan `log_hari` hari lalu diarsipkan
    |     dan dipurge. Default 1095 hari (±3 tahun) mengikuti praktik lazim
    |     dokumen koperasi.
    |
    |   - Log AUDIT perubahan DATA PRIBADI (audit_perubahan_pii): disimpan
    |     `pii_hari` hari. Default 365 hari — lebih pendek karena tabel ini
    |     menyangkut data pribadi Subjek Data (minimalisasi data).
    |
    | `0` berarti TANPA BATAS (retensi tak terbatas) untuk tabel tsb.
    | Purge hanya boleh dijalankan via command `log:purge-kedaluwarsa`.
    */
    'retensi_log' => [
        // audit_logs (mutasi rekening) — hari.
        'log_hari' => (int) env('KEAMANAN_RETENSI_LOG_HARI', 1095),

        // laporan_keuangan_logs & approval_transaksi_logs — hari.
        'log_keuangan_hari' => (int) env('KEAMANAN_RETENSI_LOG_KEUANGAN_HARI', 1095),

        // audit_perubahan_pii (data pribadi) — hari.
        'pii_hari' => (int) env('KEAMANAN_RETENSI_PII_HARI', 365),

        // Jumlah baris yang dipurge per batch (menjaga memori & lock waktu).
        'batch' => (int) env('KEAMANAN_RETENSI_BATCH', 1000),
    ],

    /*
    |----------------------------------------------------------------------
    | Observability — Health-check (liveness & readiness)
    |----------------------------------------------------------------------
    | Endpoint `/health` (liveness: proses hidup) dan `/readyz` (readiness:
    | dependensi DB/cache/queue siap melayani). Dipakai orkestrator (load
    | balancer, Docker/Kubernetes) maupun uptime monitor.
    |
    | Keamanan: kedua endpoint TIDAK memerlukan autentikasi karena dipanggil
    | infrastruktur. Karena itu dibatasi rate limit dan dapat dimatikan lewat
    | env `HEALTH_ENDPOINT_ENABLED` tanpa mengubah kode. Respons readiness
    | hanya memuat status ringkas — tanpa kredensial/stack trace.
    */
    'health' => [
        'aktif' => (bool) env('HEALTH_ENDPOINT_ENABLED', true),

        // Batas permintaan per menit per IP (cegah penyalahgunaan probe).
        'rate_limit_per_menit' => (int) env('HEALTH_RATE_LIMIT_PER_MINUTE', 60),
    ],

    'password' => (function () {
        $lokal = in_array((string) env('APP_ENV', 'production'), ['local', 'testing'], true);

        return [
            'min' => (int) env('KEAMANAN_PASSWORD_MIN', $lokal ? 8 : 12),
            'huruf_besar' => (bool) env('KEAMANAN_PASSWORD_HURUF_BESAR', ! $lokal),
            'angka' => (bool) env('KEAMANAN_PASSWORD_ANGKA', true),
            'simbol' => (bool) env('KEAMANAN_PASSWORD_SIMBOL', ! $lokal),
            // Tolak password yang sudah pernah bocor pada kebocoran data publik.
            'cek_bocor' => (bool) env('KEAMANAN_PASSWORD_CEK_BOCOR', false),
        ];
    })(),

];
