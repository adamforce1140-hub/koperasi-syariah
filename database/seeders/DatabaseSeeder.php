<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Baris tunggal parameter kebijakan koperasi — WAJIB pertama karena
            // seeder lain (Anggota/Rekening/Simpanan) membaca nominal dinamis
            // (Simpanan Pokok/Wajib, batas pembukaan rekening) dari sini.
            KebijakanKoperasiSeeder::class,
            UserSeeder::class,
            AkunSeeder::class,
            CoaSeeder::class,
            AnggotaSeeder::class,
            RekeningSeeder::class,
            DemoDataSeeder::class,
            // Studi kasus Bagian 7: anggota dummy Adam Renata (ANG-2026-0017)
            // PENDING → ACTIVE + riwayat Simpanan Wajib Jan–Sep 2026 (AUTO_DEBIT).
            AdamRenataSeeder::class,
            DepositoProductSeeder::class,
            // Master parameter dinamis jenis simpanan (pokok/wajib/sukarela/berjangka).
            JenisSimpananSeeder::class,
            // Kalender hari libur nasional/cuti bersama untuk penjadwalan angsuran
            // (first_due_date digeser otomatis ke hari kerja terdekat).
            HariLiburSeeder::class,
            // Studi kasus Bagian 7: tiga skenario uji modul Simpanan —
            // PENDING (belum setoran awal), ACTIVE auto-debit, ACTIVE manual Teller.
            ModulSimpananSeeder::class,
            // Data demo operasional modul Zakat/Infak (PSAK 109) — menutup
            // temuan audit Z-02 (modul sebelumnya kosong / tanpa bukti operasi).
            ZakatDemoSeeder::class,
            // Snapshot konfigurasi peran (izin/kelompok/dokumen) ke database —
            // pengaturan hak akses bertahan lintas sesi & deploy (langkah #6).
            RoleKonfigurasiSeeder::class,
        ]);
    }
}
