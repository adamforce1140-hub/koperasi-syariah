<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\ZakatTransaksi;
use App\Services\ZakatService;
use Illuminate\Database\Seeder;

/**
 * Data demo operasional modul Zakat, Infak & Sedekah (PSAK 109).
 *
 * Menutup temuan audit Z-02: sebelumnya tabel `zakat_transaksis` KOSONG
 * (0 baris) sehingga modul tidak memiliki bukti operasi dan keabsahan
 * transaksi tidak dapat diverifikasi. Seeder ini mengisi alur lengkap —
 * penerimaan (pengumpulan) dan penyaluran (pendistribusian) — melalui
 * {@see ZakatService} agar jurnal & buku besar konsisten (dana titipan
 * terpisah dari pendapatan dan tidak memengaruhi SHU).
 *
 * Idempoten: seluruh baris memakai nomor referensi tetap (ZKTD-…) sehingga
 * dijalankan ulang akan dilewati, bukan menggandakan data.
 */
class ZakatDemoSeeder extends Seeder
{
    /** Nomor referensi tetap — penanda idempotensi. */
    private const REF_PENERIMAAN = [
        'ZKTD-2026-0001', // zakat maal anggota
        'ZKTD-2026-0002', // zakat fitrah anggota
        'ZKTD-2026-0003', // infak/sedekah (kotak amal)
        'ZKTD-2026-0004', // infak/sedekah (donasi kegiatan)
    ];

    private const REF_PENYALURAN = [
        'ZKTD-2026-0101', // zakat → fakir
        'ZKTD-2026-0102', // zakat → amil
        'ZKTD-2026-0103', // infak → kegiatan sosial
    ];

    public function run(): void
    {
        // Idempoten: bila salah satu referensi tetap sudah ada, lewati.
        if (ZakatTransaksi::whereIn('no_referensi', self::REF_PENERIMAAN)->exists()) {
            $this->command->info('ZakatDemoSeeder: data demo zakat sudah ada — dilewati (idempoten).');

            return;
        }

        $service = app(ZakatService::class);
        $pengurus = User::where('role', 'pengurus')->first();

        if ($pengurus === null) {
            $this->command->warn('ZakatDemoSeeder: user Pengurus tidak ditemukan — seeder dilewati.');

            return;
        }

        $userId = $pengurus->id;

        // ── PENGUMPULAN (penerimaan) ─────────────────────────────────────────
        // Zakat maal: harta anggota telah mencapai nishab (85 gr emas).
        $service->terima([
            'no_referensi' => self::REF_PENERIMAAN[0],
            'dana' => ZakatTransaksi::DANA_ZAKAT,
            'kategori' => 'zakat_maal',
            'pihak' => 'Budi Santoso',
            'jumlah' => 1_500_000.00,
            'keterangan' => 'Zakat maal tahunan atas harta — Budi Santoso',
            'tanggal' => now()->subMonths(3)->toDateString(),
        ], $userId);

        // Zakat fitrah: dibayarkan per jiwa pada bulan Ramadan.
        $service->terima([
            'no_referensi' => self::REF_PENERIMAAN[1],
            'dana' => ZakatTransaksi::DANA_ZAKAT,
            'kategori' => 'zakat_fitrah',
            'pihak' => 'Siti Aminah',
            'jumlah' => 200_000.00,
            'keterangan' => 'Zakat fitrah 4 jiwa — Siti Aminah',
            'tanggal' => now()->subMonths(2)->toDateString(),
        ], $userId);

        // Infak/sedekah: kotak amal & donasi kegiatan (dana terpisah 2-3004).
        $service->terima([
            'no_referensi' => self::REF_PENERIMAAN[2],
            'dana' => ZakatTransaksi::DANA_INFAK_SEDEKAH,
            'kategori' => 'infak',
            'pihak' => 'Kotak Amal Koperasi',
            'jumlah' => 750_000.00,
            'keterangan' => 'Infak kotak amal mingguan',
            'tanggal' => now()->subMonths(2)->toDateString(),
        ], $userId);

        $service->terima([
            'no_referensi' => self::REF_PENERIMAAN[3],
            'dana' => ZakatTransaksi::DANA_INFAK_SEDEKAH,
            'kategori' => 'sedekah',
            'pihak' => 'Ahmad Fauzi',
            'jumlah' => 500_000.00,
            'keterangan' => 'Sedekah untuk kegiatan sosial koperasi',
            'tanggal' => now()->subMonth()->toDateString(),
        ], $userId);

        // ── PENDISTRIBUSIAN (penyaluran) ─────────────────────────────────────
        // Zakat HANYA boleh disalurkan kepada 8 asnaf (QS At-Taubah 60).
        $service->salurkan([
            'no_referensi' => self::REF_PENYALURAN[0],
            'dana' => ZakatTransaksi::DANA_ZAKAT,
            'kategori' => 'fakir',
            'pihak' => 'Mustahik Fakir — Bpk. Slamet',
            'jumlah' => 1_000_000.00,
            'keterangan' => 'Penyaluran zakat maal kepada fakir',
            'tanggal' => now()->subMonth()->toDateString(),
        ], $userId);

        // Hak amil (pengelola) atas pengumpulan zakat.
        $service->salurkan([
            'no_referensi' => self::REF_PENYALURAN[1],
            'dana' => ZakatTransaksi::DANA_ZAKAT,
            'kategori' => 'amil',
            'pihak' => 'Amil Koperasi Syariah',
            'jumlah' => 300_000.00,
            'keterangan' => 'Hak amil atas pengelolaan dana zakat',
            'tanggal' => now()->subMonth()->toDateString(),
        ], $userId);

        // Infak/sedekah → kegiatan sosial.
        $service->salurkan([
            'no_referensi' => self::REF_PENYALURAN[2],
            'dana' => ZakatTransaksi::DANA_INFAK_SEDEKAH,
            'kategori' => 'kegiatan_sosial',
            'pihak' => 'Kegiatan Santunan Yatim',
            'jumlah' => 600_000.00,
            'keterangan' => 'Penyaluran infak untuk santunan yatim',
            'tanggal' => now()->subWeeks(2)->toDateString(),
        ], $userId);

        $this->command->info('ZakatDemoSeeder selesai — alur penerimaan & penyaluran zakat terisi (PSAK 109).');
    }
}
