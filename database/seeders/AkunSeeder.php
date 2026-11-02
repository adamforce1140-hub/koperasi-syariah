<?php

namespace Database\Seeders;

use App\Models\Akun;
use Illuminate\Database\Seeder;

class AkunSeeder extends Seeder
{
    public function run(): void
    {
        $akuns = [
            // Aset (1)
            ['kode_akun' => '1', 'nama_akun' => 'ASET', 'klasifikasi' => 'Aset', 'jenis_akun' => 'Header', 'parent_id' => null, 'saldo_normal' => 0],
            ['kode_akun' => '11', 'nama_akun' => 'Aset Lancar', 'klasifikasi' => 'Aset', 'jenis_akun' => 'Header', 'parent_id' => 1, 'saldo_normal' => 0],
            ['kode_akun' => '111', 'nama_akun' => 'Kas', 'klasifikasi' => 'Aset', 'jenis_akun' => 'Detail', 'parent_id' => 2, 'saldo_normal' => 0],
            ['kode_akun' => '112', 'nama_akun' => 'Bank BRI', 'klasifikasi' => 'Aset', 'jenis_akun' => 'Detail', 'parent_id' => 2, 'saldo_normal' => 0],
            ['kode_akun' => '113', 'nama_akun' => 'Simpanan Anggota', 'klasifikasi' => 'Aset', 'jenis_akun' => 'Header', 'parent_id' => 2, 'saldo_normal' => 0],
            ['kode_akun' => '1131', 'nama_akun' => 'Simpanan Pokok Anggota', 'klasifikasi' => 'Aset', 'jenis_akun' => 'Detail', 'parent_id' => 5, 'saldo_normal' => 0],
            ['kode_akun' => '1132', 'nama_akun' => 'Simpanan Wajib Anggota', 'klasifikasi' => 'Aset', 'jenis_akun' => 'Detail', 'parent_id' => 5, 'saldo_normal' => 0],
            ['kode_akun' => '1133', 'nama_akun' => 'Simpanan Sukarela Anggota', 'klasifikasi' => 'Aset', 'jenis_akun' => 'Detail', 'parent_id' => 5, 'saldo_normal' => 0],
            ['kode_akun' => '12', 'nama_akun' => 'Pembiayaan', 'klasifikasi' => 'Aset', 'jenis_akun' => 'Header', 'parent_id' => 1, 'saldo_normal' => 0],
            ['kode_akun' => '121', 'nama_akun' => 'Pembiayaan Murabahah', 'klasifikasi' => 'Aset', 'jenis_akun' => 'Detail', 'parent_id' => 9, 'saldo_normal' => 0],
            ['kode_akun' => '122', 'nama_akun' => 'Pembiayaan Mudharabah', 'klasifikasi' => 'Aset', 'jenis_akun' => 'Detail', 'parent_id' => 9, 'saldo_normal' => 0],
            ['kode_akun' => '123', 'nama_akun' => 'Pembiayaan Qard', 'klasifikasi' => 'Aset', 'jenis_akun' => 'Detail', 'parent_id' => 9, 'saldo_normal' => 0],
            ['kode_akun' => '124', 'nama_akun' => 'Pembiayaan Musyarakah', 'klasifikasi' => 'Aset', 'jenis_akun' => 'Detail', 'parent_id' => 9, 'saldo_normal' => 0],
            ['kode_akun' => '125', 'nama_akun' => 'Pembiayaan Ijarah', 'klasifikasi' => 'Aset', 'jenis_akun' => 'Detail', 'parent_id' => 9, 'saldo_normal' => 0],
            // R11 — kelengkapan 8 akad kanonik: Istishna, Salam, dan IMBT
            // (IMBT memakai akun Murabahah karena berakhir dengan kepemilikan).
            ['kode_akun' => '126', 'nama_akun' => 'Pembiayaan Istishna', 'klasifikasi' => 'Aset', 'jenis_akun' => 'Detail', 'parent_id' => 9, 'saldo_normal' => 0],
            ['kode_akun' => '127', 'nama_akun' => 'Pembiayaan Salam', 'klasifikasi' => 'Aset', 'jenis_akun' => 'Detail', 'parent_id' => 9, 'saldo_normal' => 0],

            // Kewajiban (2)
            ['kode_akun' => '2', 'nama_akun' => 'KEWAJIBAN', 'klasifikasi' => 'Kewajiban', 'jenis_akun' => 'Header', 'parent_id' => null, 'saldo_normal' => 0],
            ['kode_akun' => '21', 'nama_akun' => 'Kewajiban Jangka Pendek', 'klasifikasi' => 'Kewajiban', 'jenis_akun' => 'Header', 'parent_id' => 13, 'saldo_normal' => 0],
            ['kode_akun' => '211', 'nama_akun' => 'Hutang Dagang', 'klasifikasi' => 'Kewajiban', 'jenis_akun' => 'Detail', 'parent_id' => 14, 'saldo_normal' => 0],
            ['kode_akun' => '212', 'nama_akun' => 'Hutang Bagi Hasil Simpanan', 'klasifikasi' => 'Kewajiban', 'jenis_akun' => 'Detail', 'parent_id' => 14, 'saldo_normal' => 0],

            // Modal (3)
            ['kode_akun' => '3', 'nama_akun' => 'MODAL', 'klasifikasi' => 'Modal', 'jenis_akun' => 'Header', 'parent_id' => null, 'saldo_normal' => 0],
            ['kode_akun' => '31', 'nama_akun' => 'Modal Sendiri', 'klasifikasi' => 'Modal', 'jenis_akun' => 'Header', 'parent_id' => 17, 'saldo_normal' => 0],
            ['kode_akun' => '311', 'nama_akun' => 'Modal Dasar', 'klasifikasi' => 'Modal', 'jenis_akun' => 'Detail', 'parent_id' => 18, 'saldo_normal' => 0],
            ['kode_akun' => '312', 'nama_akun' => 'Cadangan Umum', 'klasifikasi' => 'Modal', 'jenis_akun' => 'Detail', 'parent_id' => 18, 'saldo_normal' => 0],
            ['kode_akun' => '313', 'nama_akun' => 'Sisa Hasil Usaha (SHU)', 'klasifikasi' => 'Modal', 'jenis_akun' => 'Detail', 'parent_id' => 18, 'saldo_normal' => 0],

            // Pendapatan (4)
            ['kode_akun' => '4', 'nama_akun' => 'PENDAPATAN', 'klasifikasi' => 'Pendapatan', 'jenis_akun' => 'Header', 'parent_id' => null, 'saldo_normal' => 0],
            ['kode_akun' => '41', 'nama_akun' => 'Pendapatan Operasional', 'klasifikasi' => 'Pendapatan', 'jenis_akun' => 'Header', 'parent_id' => 22, 'saldo_normal' => 0],
            ['kode_akun' => '411', 'nama_akun' => 'Pendapatan Margin Murabahah', 'klasifikasi' => 'Pendapatan', 'jenis_akun' => 'Detail', 'parent_id' => 23, 'saldo_normal' => 0],
            ['kode_akun' => '412', 'nama_akun' => 'Pendapatan Bagi Hasil Pembiayaan', 'klasifikasi' => 'Pendapatan', 'jenis_akun' => 'Detail', 'parent_id' => 23, 'saldo_normal' => 0],
            ['kode_akun' => '413', 'nama_akun' => 'Pendapatan Ujroh', 'klasifikasi' => 'Pendapatan', 'jenis_akun' => 'Detail', 'parent_id' => 23, 'saldo_normal' => 0],
            ['kode_akun' => '414', 'nama_akun' => 'Pendapatan Administrasi', 'klasifikasi' => 'Pendapatan', 'jenis_akun' => 'Detail', 'parent_id' => 23, 'saldo_normal' => 0],
            ['kode_akun' => '42', 'nama_akun' => 'Pendapatan Non Operasional', 'klasifikasi' => 'Pendapatan', 'jenis_akun' => 'Header', 'parent_id' => 22, 'saldo_normal' => 0],
            ['kode_akun' => '421', 'nama_akun' => 'Pendapatan Lainnya', 'klasifikasi' => 'Pendapatan', 'jenis_akun' => 'Detail', 'parent_id' => 28, 'saldo_normal' => 0],

            // Beban (5)
            ['kode_akun' => '5', 'nama_akun' => 'BEBAN', 'klasifikasi' => 'Beban', 'jenis_akun' => 'Header', 'parent_id' => null, 'saldo_normal' => 0],
            ['kode_akun' => '51', 'nama_akun' => 'Beban Operasional', 'klasifikasi' => 'Beban', 'jenis_akun' => 'Header', 'parent_id' => 30, 'saldo_normal' => 0],
            ['kode_akun' => '511', 'nama_akun' => 'Beban Bagi Hasil Simpanan', 'klasifikasi' => 'Beban', 'jenis_akun' => 'Detail', 'parent_id' => 31, 'saldo_normal' => 0],
            ['kode_akun' => '512', 'nama_akun' => 'Beban Gaji dan Tunjangan', 'klasifikasi' => 'Beban', 'jenis_akun' => 'Detail', 'parent_id' => 31, 'saldo_normal' => 0],
            ['kode_akun' => '513', 'nama_akun' => 'Beban Sewa', 'klasifikasi' => 'Beban', 'jenis_akun' => 'Detail', 'parent_id' => 31, 'saldo_normal' => 0],
            ['kode_akun' => '514', 'nama_akun' => 'Beban Listrik, Air, Telepon', 'klasifikasi' => 'Beban', 'jenis_akun' => 'Detail', 'parent_id' => 31, 'saldo_normal' => 0],
            ['kode_akun' => '515', 'nama_akun' => 'Beban Administrasi dan Umum', 'klasifikasi' => 'Beban', 'jenis_akun' => 'Detail', 'parent_id' => 31, 'saldo_normal' => 0],
            ['kode_akun' => '52', 'nama_akun' => 'Beban Non Operasional', 'klasifikasi' => 'Beban', 'jenis_akun' => 'Header', 'parent_id' => 30, 'saldo_normal' => 0],
            ['kode_akun' => '521', 'nama_akun' => 'Beban Lainnya', 'klasifikasi' => 'Beban', 'jenis_akun' => 'Detail', 'parent_id' => 37, 'saldo_normal' => 0],
        ];

        foreach ($akuns as $akun) {
            Akun::updateOrCreate(
                ['kode_akun' => $akun['kode_akun']],
                $akun
            );
        }
    }
}
