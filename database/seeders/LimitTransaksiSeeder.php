<?php

namespace Database\Seeders;

use App\Models\LimitTransaksiRole;
use App\Models\MasterProdukPembiayaan;
use App\Models\ProdukSyaratDokumen;
use Illuminate\Database\Seeder;

class LimitTransaksiSeeder extends Seeder
{
    public function run(): void
    {
        // Limit transaksi per role — hierarki: DPS > Pengurus > Manajer > AO > Teller
        $limits = [
            ['role' => 'teller',   'jenis_transaksi' => 'setor_tunai',  'limit_per_transaksi' => 50000000, 'butuh_approval' => true,  'approval_role' => 'ao'],
            ['role' => 'teller',   'jenis_transaksi' => 'tarik_tunai',  'limit_per_transaksi' => 5000000,  'butuh_approval' => true,  'approval_role' => 'ao'],
            ['role' => 'teller',   'jenis_transaksi' => 'non_tunai',    'limit_per_transaksi' => 0,        'butuh_approval' => false, 'approval_role' => null],
            ['role' => 'ao',       'jenis_transaksi' => 'setor_tunai',  'limit_per_transaksi' => 100000000, 'butuh_approval' => true, 'approval_role' => 'manajer'],
            ['role' => 'ao',       'jenis_transaksi' => 'tarik_tunai',  'limit_per_transaksi' => 20000000, 'butuh_approval' => true,  'approval_role' => 'manajer'],
            ['role' => 'ao',       'jenis_transaksi' => 'non_tunai',    'limit_per_transaksi' => 0,        'butuh_approval' => false, 'approval_role' => null],
            ['role' => 'manajer',  'jenis_transaksi' => 'setor_tunai',  'limit_per_transaksi' => null,     'butuh_approval' => false, 'approval_role' => null],
            ['role' => 'manajer',  'jenis_transaksi' => 'tarik_tunai',  'limit_per_transaksi' => null,     'butuh_approval' => false, 'approval_role' => null],
            ['role' => 'manajer',  'jenis_transaksi' => 'non_tunai',    'limit_per_transaksi' => 50000000, 'butuh_approval' => true,  'approval_role' => 'pengurus'],
            ['role' => 'pengurus', 'jenis_transaksi' => 'setor_tunai',  'limit_per_transaksi' => null,     'butuh_approval' => false, 'approval_role' => null],
            ['role' => 'pengurus', 'jenis_transaksi' => 'tarik_tunai',  'limit_per_transaksi' => null,     'butuh_approval' => false, 'approval_role' => null],
            ['role' => 'pengurus', 'jenis_transaksi' => 'non_tunai',    'limit_per_transaksi' => null,     'butuh_approval' => false, 'approval_role' => null],
        ];

        foreach ($limits as $limit) {
            LimitTransaksiRole::updateOrCreate(
                ['role' => $limit['role'], 'jenis_transaksi' => $limit['jenis_transaksi']],
                $limit
            );
        }

        // Produk pembiayaan default — 8 akad kanonik (selaras Pembiayaan::daftarAkad()).
        // Akad bagi hasil (Mudharabah/Musyarakah) wajib mencantumkan nisbah berjumlah 100%.
        $produkData = [
            ['nama_produk' => 'Murabahah Konsumtif', 'akad_syariah' => 'Murabahah', 'min_plafon' => 1000000,  'max_plafon' => 50000000,  'margin_persen' => 1.5, 'biaya_admin' => 50000,  'min_bulan_anggota' => 3,  'min_tenor_bulan' => 6,  'max_tenor_bulan' => 36],
            ['nama_produk' => 'Murabahah Produktif',  'akad_syariah' => 'Murabahah', 'min_plafon' => 5000000,  'max_plafon' => 200000000, 'margin_persen' => 1.2, 'biaya_admin' => 100000, 'min_bulan_anggota' => 6,  'min_tenor_bulan' => 12, 'max_tenor_bulan' => 60],
            ['nama_produk' => 'Mudharabah Modal Usaha', 'akad_syariah' => 'Mudharabah', 'min_plafon' => 10000000, 'max_plafon' => 500000000, 'margin_persen' => 0,   'nisbah_anggota' => 40, 'nisbah_koperasi' => 60, 'biaya_admin' => 200000, 'min_bulan_anggota' => 12, 'min_tenor_bulan' => 12, 'max_tenor_bulan' => 60],
            ['nama_produk' => 'Musyarakah Patungan Usaha', 'akad_syariah' => 'Musyarakah', 'min_plafon' => 5000000, 'max_plafon' => 300000000, 'margin_persen' => 0,  'nisbah_anggota' => 50, 'nisbah_koperasi' => 50, 'biaya_admin' => 150000, 'min_bulan_anggota' => 6,  'min_tenor_bulan' => 12, 'max_tenor_bulan' => 48],
            ['nama_produk' => 'Ijarah Sewa Guna Usaha', 'akad_syariah' => 'Ijarah',   'min_plafon' => 3000000,  'max_plafon' => 150000000, 'margin_persen' => 1.0, 'biaya_admin' => 100000, 'min_bulan_anggota' => 6,  'min_tenor_bulan' => 12, 'max_tenor_bulan' => 48],
            ['nama_produk' => 'Ijarah Muntahia Bittamlik', 'akad_syariah' => 'Ijarah Muntahia Bittamlik', 'min_plafon' => 5000000, 'max_plafon' => 200000000, 'margin_persen' => 1.0, 'biaya_admin' => 150000, 'min_bulan_anggota' => 6, 'min_tenor_bulan' => 12, 'max_tenor_bulan' => 60],
            ['nama_produk' => 'Istishna Pesanan Barang', 'akad_syariah' => 'Istishna', 'min_plafon' => 2000000,  'max_plafon' => 100000000, 'margin_persen' => 1.3, 'biaya_admin' => 75000,  'min_bulan_anggota' => 3,  'min_tenor_bulan' => 6,  'max_tenor_bulan' => 36],
            ['nama_produk' => 'Salam Pertanian',      'akad_syariah' => 'Salam',     'min_plafon' => 1000000,  'max_plafon' => 50000000,  'margin_persen' => 1.3, 'biaya_admin' => 50000,  'min_bulan_anggota' => 3,  'min_tenor_bulan' => 3,  'max_tenor_bulan' => 12],
            ['nama_produk' => 'Qardh (Pinjaman Darurat)', 'akad_syariah' => 'Qardh',  'min_plafon' => 500000,   'max_plafon' => 5000000,   'margin_persen' => 0,   'biaya_admin' => 25000,  'min_bulan_anggota' => 1,  'min_tenor_bulan' => 3,  'max_tenor_bulan' => 12],
        ];

        foreach ($produkData as $pd) {
            $produk = MasterProdukPembiayaan::firstOrCreate(['nama_produk' => $pd['nama_produk']], array_merge($pd, ['status_aktif' => true]));
        }

        // Syarat dokumen untuk produk pertama (Murabahah Konsumtif)
        $produkKonsumtif = MasterProdukPembiayaan::where('nama_produk', 'Murabahah Konsumtif')->first();
        if ($produkKonsumtif && $produkKonsumtif->syaratDokumen()->count() === 0) {
            $syarat = ['Fotokopi KTP', 'Fotokopi KK', 'Slip Gaji / Bukti Penghasilan', 'Foto Jaminan'];
            foreach ($syarat as $s) {
                ProdukSyaratDokumen::create(['produk_id' => $produkKonsumtif->id, 'nama_dokumen' => $s, 'is_required' => true]);
            }
        }
    }
}
