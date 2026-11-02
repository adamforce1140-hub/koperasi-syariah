<?php

use App\Support\Setting;

if (! function_exists('terbilang')) {
    /**
     * Konversi angka ke teks terbilang dalam Bahasa Indonesia.
     */
    function terbilang(float $angka): string
    {
        $angka = (int) abs($angka);
        $satuan = ['', 'Satu', 'Dua', 'Tiga', 'Empat', 'Lima',
            'Enam', 'Tujuh', 'Delapan', 'Sembilan', 'Sepuluh',
            'Sebelas'];

        if ($angka < 12) {
            return $satuan[$angka];
        }

        if ($angka < 20) {
            return terbilang($angka - 10).' Belas';
        }

        if ($angka < 100) {
            return terbilang((int) ($angka / 10)).' Puluh'.
                   ($angka % 10 ? ' '.terbilang($angka % 10) : '');
        }

        if ($angka < 200) {
            return 'Seratus'.($angka % 100 ? ' '.terbilang($angka % 100) : '');
        }

        if ($angka < 1_000) {
            return terbilang((int) ($angka / 100)).' Ratus'.
                   ($angka % 100 ? ' '.terbilang($angka % 100) : '');
        }

        if ($angka < 2_000) {
            return 'Seribu'.($angka % 1_000 ? ' '.terbilang($angka % 1_000) : '');
        }

        if ($angka < 1_000_000) {
            return terbilang((int) ($angka / 1_000)).' Ribu'.
                   ($angka % 1_000 ? ' '.terbilang($angka % 1_000) : '');
        }

        if ($angka < 1_000_000_000) {
            return terbilang((int) ($angka / 1_000_000)).' Juta'.
                   ($angka % 1_000_000 ? ' '.terbilang($angka % 1_000_000) : '');
        }

        if ($angka < 1_000_000_000_000) {
            return terbilang((int) ($angka / 1_000_000_000)).' Miliar'.
                   ($angka % 1_000_000_000 ? ' '.terbilang($angka % 1_000_000_000) : '');
        }

        return (string) $angka; // fallback untuk angka sangat besar
    }
}

if (! function_exists('setting')) {
    /**
     * Ambil parameter kebijakan operasional koperasi secara dinamis.
     *
     * Helper global ini adalah jembatan tipis menuju `App\Support\Setting`
     * (single source of truth) sehingga Controller, Model, Service, Scheduler,
     * maupun Blade TIDAK PERNAH menanam nominal/persentase/aturan bisnis
     * secara hardcode. Nilai diambil dari tabel `kebijakan_koperasis` dan
     * dilayani dari cache (invalidasi otomatis saat pengurus menyimpan
     * perubahan — lihat KebijakanKoperasi::booted()).
     *
     * Contoh pemakaian:
     *   setting('mandatory_principal_savings');        // 100000.0
     *   setting('mandatory_monthly_savings', 50000);   // nilai dinamis
     *   setting('shu_hold_on_overdue');                // bool
     *   setting('teller_max_cash_limit');              // float
     *
     * @param  string  $key  Nama parameter kebijakan (lihat Setting::DEFAULTS).
     * @param  mixed  $default  Nilai pengganti bila parameter belum tersedia.
     */
    function setting(string $key, mixed $default = null): mixed
    {
        return Setting::get($key, $default);
    }
}
