<?php

namespace Database\Seeders;

use App\Models\Coa;
use Illuminate\Database\Seeder;

class CoaSeeder extends Seeder
{
    /**
     * Bagan Akun (CoA) standar Koperasi Syariah — referensi PSAK 101-111.
     * Format kode: [Kategori]-[Nomor]
     *
     * `kategori`          = 5 pos besar laporan keuangan.
     * `klasifikasi_neraca` = pengelompokan penyajian standar (PSAK 101):
     *                        Aset Lancar / Aset Tidak Lancar,
     *                        Kewajiban Jangka Pendek / Jangka Panjang,
     *                        Dana Syirkah Temporer, Modal Anggota,
     *                        Dana Cadangan, Saldo Laba / SHU,
     *                        Pendapatan Usaha, Beban Usaha.
     *
     * Akun lama yang belum memiliki klasifikasi diisi otomatis oleh
     * migrasi 2026_09_16_000001_add_klasifikasi_neraca_to_coas_table.
     *
     * PARAMETER PERSENTASE DINAMIS (kolom `has_percentage`, `percentage_type`,
     * `default_percentage_member`, `default_percentage_institution`):
     * nilai nisbah/margin TIDAK di-hardcode di service, melainkan disimpan di
     * COA dan dapat diubah kapan saja lewat menu Master COA. Akad yang sudah
     * terjadi menyimpan snapshot-nya sendiri sehingga aman dari perubahan.
     *
     *  - 2-1001 Simpanan Sukarela : nisbah 15 : 85 (anggota : koperasi)
     *  - 2-1003 Simpanan Berjangka: nisbah (porsi per tenor ada di produk)
     *  - 1-2001 Piutang Murabahah  : margin flat 12,00% per tahun
     *  - 3-1001 Simpanan Pokok     : SHU tahunan (tanpa nisbah bulanan)
     *  - 3-1002 Simpanan Wajib     : SHU tahunan (tanpa nisbah bulanan)
     */
    public function run(): void
    {
        $akun = [
            // ── ASET (1-XXXX) ─────────────────────────────
            ['kode_akun' => '1-1001', 'nama_akun' => 'Kas Teller', 'kategori' => 'Aset', 'klasifikasi_neraca' => 'Aset Lancar', 'tipe_saldo_normal' => 'Debet'],
            ['kode_akun' => '1-1002', 'nama_akun' => 'Bank / Rekening Utama Koperasi', 'kategori' => 'Aset', 'klasifikasi_neraca' => 'Aset Lancar', 'tipe_saldo_normal' => 'Debet'],
            [
                'kode_akun' => '1-2001', 'nama_akun' => 'Piutang Pembiayaan Murabahah', 'kategori' => 'Aset', 'klasifikasi_neraca' => 'Aset Lancar', 'tipe_saldo_normal' => 'Debet',
                // Rate margin keuntungan FLAT per tahun yang dipakai sebagai
                // default form pengajuan pembiayaan murabahah.
                'has_percentage' => true, 'percentage_type' => Coa::PERCENTAGE_MARGIN,
                'default_percentage_member' => 12.00, 'default_percentage_institution' => 0.00,
            ],
            ['kode_akun' => '1-2002', 'nama_akun' => 'Piutang Pembiayaan Mudharabah', 'kategori' => 'Aset', 'klasifikasi_neraca' => 'Aset Lancar', 'tipe_saldo_normal' => 'Debet'],
            ['kode_akun' => '1-2003', 'nama_akun' => 'Pembiayaan Musyarakah',           'kategori' => 'Aset', 'klasifikasi_neraca' => 'Aset Lancar', 'tipe_saldo_normal' => 'Debet'],
            ['kode_akun' => '1-2004', 'nama_akun' => 'Piutang Ijarah',                  'kategori' => 'Aset', 'klasifikasi_neraca' => 'Aset Lancar', 'tipe_saldo_normal' => 'Debet'],
            ['kode_akun' => '1-2005', 'nama_akun' => 'Pinjaman Qardh / Kebajikan',     'kategori' => 'Aset', 'klasifikasi_neraca' => 'Aset Lancar', 'tipe_saldo_normal' => 'Debet'],
            /* R9 — PIUTANG PER AKAD WAJIB PUNYA AKUN SENDIRI (PSAK 101-104).
             | Sebelumnya Istishna & Salam memakai kode 1-2001 (sama dengan
             | Murabahah) sehingga neraca tidak dapat memisahkan penyajian per
             | akad. Kini keduanya diberi kode unik. */
            ['kode_akun' => '1-2006', 'nama_akun' => 'Piutang Istishna',                'kategori' => 'Aset', 'klasifikasi_neraca' => 'Aset Lancar', 'tipe_saldo_normal' => 'Debet'],
            ['kode_akun' => '1-2007', 'nama_akun' => 'Piutang Salam',                   'kategori' => 'Aset', 'klasifikasi_neraca' => 'Aset Lancar', 'tipe_saldo_normal' => 'Debet'],
            /* 1-2100 CKPN & 1-2101 Margin Ditangguhkan = AKUN KONTRA PIUTANG.
             | Keduanya berkepala '1' (kelompok Aset) tetapi bersaldo normal
             | KREDIT, sehingga Coa::isKontra() mengenalinya dan saldonya
             | MENGURANGI total piutang pembiayaan. Penyajian neto ini diwajibkan
             | PSAK 102: "Piutang murabahah disajikan sebesar nilai neto ... |
             | Marjin murabahah tangguhan disajikan sebagai pengurang (contra
             | account) piutang murabahah", jo. PSAK 71 untuk penyisihan kerugian.
             | Akun keduanya dibuat oleh migrasi (1-2100 & 1-2101), bukan seeder,
             | agar basis data lama ikut dikoreksi. */
            ['kode_akun' => '1-2100', 'nama_akun' => 'Penyisihan Kerugian Piutang Pembiayaan (CKPN)', 'kategori' => 'Aset', 'klasifikasi_neraca' => 'Aset Lancar', 'tipe_saldo_normal' => 'Kredit'],
            ['kode_akun' => '1-2101', 'nama_akun' => 'Margin Murabahah Ditangguhkan',   'kategori' => 'Aset', 'klasifikasi_neraca' => 'Aset Lancar', 'tipe_saldo_normal' => 'Kredit'],
            /* Akun alias 1-2010 / 1-2020 / 1-2030 / 1-2040 DIHAPUS dari COA.
             | Keempatnya adalah duplikat tak terpakai dari 1-2001…1-2005 dengan
             | penamaan silang yang membingungkan (1-2020 "Musyarakah" vs 1-2003;
             | 1-2030 "Mudharabah" vs 1-2002). Pemetaan tunggal akun piutang per
             | akad ada di AutoJournalingService::piutangByAkad() → 1-2001…1-2005.
             | Akun lama di database dinonaktifkan oleh migrasi
             | 2026_09_17_120000_nonaktifkan_akun_alias_piutang_pembiayaan. */

            // ── KEWAJIBAN / PASIVA (2-XXXX) ───────────────
            ['kode_akun' => '2-1001', 'nama_akun' => 'Simpanan Sukarela / Wadiah Anggota', 'kategori' => 'Kewajiban', 'klasifikasi_neraca' => 'Kewajiban Jangka Pendek', 'tipe_saldo_normal' => 'Kredit',
                // Nisbah bagi hasil bulanan anggota : koperasi = 15% : 85%.
                'has_percentage' => true, 'percentage_type' => Coa::PERCENTAGE_NISBAH,
                'default_percentage_member' => 15.00, 'default_percentage_institution' => 85.00],
            ['kode_akun' => '2-1002', 'nama_akun' => 'Simpanan Wajib Anggota',                        'kategori' => 'Kewajiban', 'klasifikasi_neraca' => 'Kewajiban Jangka Pendek', 'tipe_saldo_normal' => 'Kredit'],
            ['kode_akun' => '2-1003', 'nama_akun' => 'Simpanan Berjangka / Deposito Mudharabah',      'kategori' => 'Kewajiban', 'klasifikasi_neraca' => 'Dana Syirkah Temporer',   'tipe_saldo_normal' => 'Kredit',
                // Nisbah deposito mengikuti porsi produk per tenor (40/60, 50/50, 60/40).
                // Nilai COA di bawah adalah default aplikasi bila produk tidak menentukan.
                'has_percentage' => true, 'percentage_type' => Coa::PERCENTAGE_NISBAH,
                'default_percentage_member' => 40.00, 'default_percentage_institution' => 60.00],
            ['kode_akun' => '2-2001', 'nama_akun' => 'Utang Dana Pengurus & Karyawan',                'kategori' => 'Kewajiban', 'klasifikasi_neraca' => 'Kewajiban Jangka Pendek', 'tipe_saldo_normal' => 'Kredit'],
            ['kode_akun' => '2-3001', 'nama_akun' => 'Dana Kebajikan / Social Fund [Ta\'zir]',        'kategori' => 'Kewajiban', 'klasifikasi_neraca' => 'Kewajiban Jangka Pendek', 'tipe_saldo_normal' => 'Kredit'],
            ['kode_akun' => '2-3003', 'nama_akun' => 'Dana Sosial / Pos Sosial',                      'kategori' => 'Kewajiban', 'klasifikasi_neraca' => 'Kewajiban Jangka Pendek', 'tipe_saldo_normal' => 'Kredit'],
            /* 2-4001 Margin Murabahah Ditangguhkan DIHAPUS dari bagan akun.
             | PSAK 102: "Marjin murabahah tangguhan disajikan sebagai pengurang
             | (contra account) piutang murabahah" — jadi ia akun KONTRA-ASET,
             | bukan Kewajiban. Akun dipindahkan ke 1-2101 (lihat daftar Aset di
             | atas & migrasi 2026_10_01_000200_margin_ditangguhkan_contra_piutang)
             | agar neraca menyajikan piutang murabahah NETO, bukan menyajikan
             | margin sebagai Kewajiban Jangka Pendek. */
            // ── EKUITAS (3-XXXX) ─────────────────────────
            ['kode_akun' => '3-1001', 'nama_akun' => 'Simpanan Pokok Anggota', 'kategori' => 'Ekuitas', 'klasifikasi_neraca' => 'Modal Anggota',    'tipe_saldo_normal' => 'Kredit',
                // Sengaja has_percentage = false: tidak ada nisbah/bagi hasil bulanan.
                // Imbalan anggota diperhitungkan lewat SHU Tahunan di bagian Ekuitas.
                'has_percentage' => false, 'percentage_type' => Coa::PERCENTAGE_SHU,
                'default_percentage_member' => 0.00, 'default_percentage_institution' => 0.00],
            // 3-1002 adalah akun MODAL/EKUITAS untuk Simpanan Wajib (PSAK 101:
            // simpanan wajib anggota tidak dapat ditarik sehingga disajikan
            // sebagai Modal Anggota). Sejak Tahap 12, SELURUH jalur jurnal
            // Simpanan Wajib (setor, teller, auto-debit, reversal) mengkredit
            // akun ini melalui titik resolusi tunggal
            // `MandatorySavingsService::akunSimpananWajib()`. Akun operasional
            // legacy 2-1002 hanya dipertahankan untuk data historis.
            ['kode_akun' => '3-1002', 'nama_akun' => 'Simpanan Wajib Anggota (Ekuitas)', 'kategori' => 'Ekuitas', 'klasifikasi_neraca' => 'Modal Anggota', 'tipe_saldo_normal' => 'Kredit',
                // Sama seperti Simpanan Pokok: diselesaikan via SHU Tahunan.
                'has_percentage' => false, 'percentage_type' => Coa::PERCENTAGE_SHU,
                'default_percentage_member' => 0.00, 'default_percentage_institution' => 0.00],
            ['kode_akun' => '3-2001', 'nama_akun' => 'Dana Cadangan Koperasi', 'kategori' => 'Ekuitas', 'klasifikasi_neraca' => 'Dana Cadangan',    'tipe_saldo_normal' => 'Kredit'],
            ['kode_akun' => '3-9000', 'nama_akun' => 'Ikhtisar SHU / Laba Ditahan', 'kategori' => 'Ekuitas', 'klasifikasi_neraca' => 'Saldo Laba / SHU', 'tipe_saldo_normal' => 'Kredit'],

            // ── PENDAPATAN (4-XXXX) ───────────────────────
            ['kode_akun' => '4-1001', 'nama_akun' => 'Pendapatan Margin Murabahah', 'kategori' => 'Pendapatan', 'klasifikasi_neraca' => 'Pendapatan Usaha', 'tipe_saldo_normal' => 'Kredit'],
            ['kode_akun' => '4-1002', 'nama_akun' => 'Pendapatan Bagi Hasil Mudharabah', 'kategori' => 'Pendapatan', 'klasifikasi_neraca' => 'Pendapatan Usaha', 'tipe_saldo_normal' => 'Kredit'],
            ['kode_akun' => '4-1003', 'nama_akun' => 'Pendapatan Bagi Hasil Musyarakah', 'kategori' => 'Pendapatan', 'klasifikasi_neraca' => 'Pendapatan Usaha', 'tipe_saldo_normal' => 'Kredit'],
            ['kode_akun' => '4-1004', 'nama_akun' => 'Pendapatan Sewa Ijarah', 'kategori' => 'Pendapatan', 'klasifikasi_neraca' => 'Pendapatan Usaha', 'tipe_saldo_normal' => 'Kredit'],
            ['kode_akun' => '4-2001', 'nama_akun' => 'Pendapatan Biaya Administrasi', 'kategori' => 'Pendapatan', 'klasifikasi_neraca' => 'Pendapatan Usaha', 'tipe_saldo_normal' => 'Kredit'],

            // ── BEBAN (5-XXXX) ────────────────────────────
            // 5-1001 dipertahankan sebagai akun BEBAN GENERAL / cadangan (fallback)
            // bila kode akun beban spesifik di bawah ini tidak tersedia.
            ['kode_akun' => '5-1001', 'nama_akun' => 'Beban Operasional Koperasi',   'kategori' => 'Beban', 'klasifikasi_neraca' => 'Beban Usaha', 'tipe_saldo_normal' => 'Debet'],
            // Akun beban operasional spesifik per jenis pengeluaran.
            ['kode_akun' => '5-1002', 'nama_akun' => 'Beban ATK & Perlengkapan',     'kategori' => 'Beban', 'klasifikasi_neraca' => 'Beban Usaha', 'tipe_saldo_normal' => 'Debet'],
            ['kode_akun' => '5-1003', 'nama_akun' => 'Beban Utilitas / Listrik',     'kategori' => 'Beban', 'klasifikasi_neraca' => 'Beban Usaha', 'tipe_saldo_normal' => 'Debet'],
            // Beban bagi hasil dana syirkah temporer (deposito Mudharabah) —
            // dipisahkan dari beban operasional agar penyajian laba/rugi PSAK 101 tepat.
            ['kode_akun' => '5-3001', 'nama_akun' => 'Beban Bagi Hasil Deposito Mudharabah', 'kategori' => 'Beban', 'klasifikasi_neraca' => 'Beban Usaha', 'tipe_saldo_normal' => 'Debet'],

            // ── DANA TITIPAN ZAKAT & INFAK/SEDEKAH (PSAK 109) ─────────────
            // Disajikan TERPISAH dari Dana Kebajikan (2-3001) dan Dana Sosial
            // (2-3003): zakat wajib disalurkan kepada 8 asnaf, infak/sedekah
            // tidak. Keduanya titipan (kewajiban), bukan pendapatan/SHU.
            ['kode_akun' => '2-3002', 'nama_akun' => 'Dana Zakat',              'kategori' => 'Kewajiban', 'klasifikasi_neraca' => 'Kewajiban Jangka Pendek', 'tipe_saldo_normal' => 'Kredit'],
            ['kode_akun' => '2-3004', 'nama_akun' => 'Dana Infak & Sedekah',    'kategori' => 'Kewajiban', 'klasifikasi_neraca' => 'Kewajiban Jangka Pendek', 'tipe_saldo_normal' => 'Kredit'],
        ];

        foreach ($akun as $item) {
            Coa::updateOrCreate(
                ['kode_akun' => $item['kode_akun']],
                $item + ['is_active' => true, 'saldo_awal' => 0]
            );
        }

        $this->command->info('CoaSeeder: '.count($akun).' akun standar syariah siap.');
    }
}
