<?php

namespace Database\Seeders;

use App\Models\HariLibur;
use Illuminate\Database\Seeder;

/**
 * Kalender Hari Libur Nasional & Cuti Bersama.
 *
 * Data di sini adalah DATA AWAL yang dapat dipelihara pengurus melalui menu
 * master — bukan aturan bisnis yang ditanam di kode. Service penjadwalan
 * (HariLiburService) hanya membaca tabel `hari_liburs`, sehingga penambahan /
 * perubahan hari libur tidak memerlukan perubahan kode program.
 *
 * Sumber: SKB 3 Menteri (Kemenag, Kemnaker, KemenPANRB) — 2025 dan 2026.
 *
 * Catatan: akhir pekan (Sabtu/Minggu) sudah ditangani secara terpisah oleh
 * HariLiburService dan TIDAK perlu didaftarkan di sini. Tanggal libur nasional
 * yang kebetulan jatuh pada akhir pekan tetap didaftarkan demi kelengkapan
 * pelaporan.
 */
class HariLiburSeeder extends Seeder
{
    /** Pemetaan kode jenis → konstanta model. */
    private const JENIS = [
        'N' => HariLibur::JENIS_NASIONAL,
        'C' => HariLibur::JENIS_CUTI_BERSAMA,
        'K' => HariLibur::JENIS_KOPERASI,
    ];

    /**
     * Format: 'tanggal' => [kode jenis, keterangan].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const DATA = [
        // ── 2026: Libur Nasional (17 hari) ───────────────────────────────────
        '2026-01-01' => ['N', 'Tahun Baru 2026 Masehi'],
        '2026-01-16' => ['N', 'Isra Mikraj Nabi Muhammad SAW'],
        '2026-02-17' => ['N', 'Tahun Baru Imlek 2577 Kongzili'],
        '2026-03-19' => ['N', 'Hari Suci Nyepi (Tahun Baru Saka 1948)'],
        '2026-03-21' => ['N', 'Hari Raya Idul Fitri 1447 H'],
        '2026-03-22' => ['N', 'Hari Raya Idul Fitri 1447 H (Hari Kedua)'],
        '2026-04-03' => ['N', 'Wafat Yesus Kristus'],
        '2026-04-05' => ['N', 'Kebangkitan Yesus Kristus (Paskah)'],
        '2026-05-01' => ['N', 'Hari Buruh Internasional'],
        '2026-05-14' => ['N', 'Kenaikan Yesus Kristus'],
        '2026-05-27' => ['N', 'Hari Raya Idul Adha 1447 H'],
        '2026-05-31' => ['N', 'Hari Raya Waisak 2570 BE'],
        '2026-06-01' => ['N', 'Hari Lahir Pancasila'],
        '2026-06-16' => ['N', '1 Muharam Tahun Baru Islam 1448 H'],
        '2026-08-17' => ['N', 'Proklamasi Kemerdekaan Republik Indonesia'],
        '2026-08-25' => ['N', 'Maulid Nabi Muhammad SAW'],
        '2026-12-25' => ['N', 'Kelahiran Yesus Kristus (Hari Raya Natal)'],

        // ── 2026: Cuti Bersama (8 hari) ──────────────────────────────────────
        '2026-02-16' => ['C', 'Cuti Bersama Tahun Baru Imlek 2577 Kongzili'],
        '2026-03-18' => ['C', 'Cuti Bersama Hari Suci Nyepi (Tahun Baru Saka 1948)'],
        '2026-03-20' => ['C', 'Cuti Bersama Hari Raya Idul Fitri 1447 H'],
        '2026-03-23' => ['C', 'Cuti Bersama Hari Raya Idul Fitri 1447 H'],
        '2026-03-24' => ['C', 'Cuti Bersama Hari Raya Idul Fitri 1447 H'],
        '2026-05-15' => ['C', 'Cuti Bersama Kenaikan Yesus Kristus'],
        '2026-05-28' => ['C', 'Cuti Bersama Hari Raya Idul Adha 1447 H'],
        '2026-12-24' => ['C', 'Cuti Bersama Hari Raya Natal'],

        // ── 2025: Libur Nasional (17 hari) ───────────────────────────────────
        '2025-01-01' => ['N', 'Tahun Baru 2025 Masehi'],
        '2025-01-27' => ['N', 'Isra Mikraj Nabi Muhammad SAW'],
        '2025-01-29' => ['N', 'Tahun Baru Imlek 2576 Kongzili'],
        '2025-03-29' => ['N', 'Hari Suci Nyepi (Tahun Baru Saka 1947)'],
        '2025-03-31' => ['N', 'Hari Raya Idul Fitri 1446 H'],
        '2025-04-01' => ['N', 'Hari Raya Idul Fitri 1446 H (Hari Kedua)'],
        '2025-04-18' => ['N', 'Wafat Yesus Kristus'],
        '2025-04-20' => ['N', 'Kebangkitan Yesus Kristus (Paskah)'],
        '2025-05-01' => ['N', 'Hari Buruh Internasional'],
        '2025-05-12' => ['N', 'Hari Raya Waisak 2569 BE'],
        '2025-05-29' => ['N', 'Kenaikan Yesus Kristus'],
        '2025-06-01' => ['N', 'Hari Lahir Pancasila'],
        '2025-06-06' => ['N', 'Hari Raya Idul Adha 1446 H'],
        '2025-06-27' => ['N', '1 Muharam Tahun Baru Islam 1447 H'],
        '2025-08-17' => ['N', 'Proklamasi Kemerdekaan Republik Indonesia'],
        '2025-09-05' => ['N', 'Maulid Nabi Muhammad SAW'],
        '2025-12-25' => ['N', 'Kelahiran Yesus Kristus (Hari Raya Natal)'],

        // ── 2025: Cuti Bersama (11 hari, termasuk penambahan 18 Agustus) ─────
        '2025-01-28' => ['C', 'Cuti Bersama Tahun Baru Imlek 2576 Kongzili'],
        '2025-03-28' => ['C', 'Cuti Bersama Hari Suci Nyepi (Tahun Baru Saka 1947)'],
        '2025-04-02' => ['C', 'Cuti Bersama Hari Raya Idul Fitri 1446 H'],
        '2025-04-03' => ['C', 'Cuti Bersama Hari Raya Idul Fitri 1446 H'],
        '2025-04-04' => ['C', 'Cuti Bersama Hari Raya Idul Fitri 1446 H'],
        '2025-04-07' => ['C', 'Cuti Bersama Hari Raya Idul Fitri 1446 H'],
        '2025-05-13' => ['C', 'Cuti Bersama Hari Raya Waisak 2569 BE'],
        '2025-05-30' => ['C', 'Cuti Bersama Kenaikan Yesus Kristus'],
        '2025-06-09' => ['C', 'Cuti Bersama Hari Raya Idul Adha 1446 H'],
        '2025-08-18' => ['C', 'Cuti Bersama Proklamasi Kemerdekaan Republik Indonesia'],
        '2025-12-26' => ['C', 'Cuti Bersama Hari Raya Natal'],
    ];

    public function run(): void
    {
        $jumlah = 0;

        foreach (self::DATA as $tanggal => [$jenisKode, $keterangan]) {
            HariLibur::updateOrCreate(
                ['tanggal' => $tanggal],
                [
                    'keterangan' => $keterangan,
                    'jenis' => self::JENIS[$jenisKode] ?? HariLibur::JENIS_NASIONAL,
                    'is_active' => true,
                ]
            );

            $jumlah++;
        }

        $this->command?->info("HariLiburSeeder: {$jumlah} tanggal hari libur siap dipakai penjadwalan angsuran.");
    }
}
