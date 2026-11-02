<?php

namespace Database\Seeders;

use App\Models\Anggota;
use Illuminate\Database\Seeder;

class AnggotaSeeder extends Seeder
{
    public function run(): void
    {
        $anggotas = [
            [
                'no_anggota' => 'ANG-2022-0001',
                // Aplikasi hanya memiliki 5 role login (DPS, Pengurus, Manajer, AO, Teller) —
                // anggota adalah data, bukan user, sehingga user_id dibiarkan null.
                'user_id' => null,
                'nik' => '3201010101900001',
                'nama_lengkap' => 'Budi Santoso',
                'tempat_lahir' => 'Bandung',
                'tanggal_lahir' => '1990-01-15',
                'jenis_kelamin' => 'Laki-laki',
                'status_perkawinan' => 'Menikah',
                'alamat' => 'Jl. Merdeka No. 123',
                'rt_rw' => '005/012',
                'kelurahan' => 'Citarum',
                'kecamatan' => 'Bandung Wetan',
                'kota' => 'Bandung',
                'provinsi' => 'Jawa Barat',
                'kode_pos' => '40115',
                'no_telepon' => '022-1234567',
                'no_hp' => '081234567890',
                'email' => 'budi@koperasi.test',
                'pekerjaan' => 'Wiraswasta',
                'nama_ibu_kandung' => 'Siti Aminah',
                'status_anggota' => Anggota::STATUS_ACTIVE,
                'tanggal_gabung' => '2022-01-10',
                'simpanan_pokok' => 100000,
                'simpanan_wajib' => 50000,
                'keterangan' => 'Anggota aktif sejak 2022',
            ],
            [
                'no_anggota' => 'ANG-2022-0002',
                'user_id' => null,
                'nik' => '3201010202920002',
                'nama_lengkap' => 'Siti Rahayu',
                'tempat_lahir' => 'Jakarta',
                'tanggal_lahir' => '1992-05-20',
                'jenis_kelamin' => 'Perempuan',
                'status_perkawinan' => 'Belum Menikah',
                'alamat' => 'Jl. Sudirman No. 45',
                'rt_rw' => '003/008',
                'kelurahan' => 'Gegerkalong',
                'kecamatan' => 'Sukasari',
                'kota' => 'Bandung',
                'provinsi' => 'Jawa Barat',
                'kode_pos' => '40154',
                'no_telepon' => null,
                'no_hp' => '081298765432',
                'email' => 'siti@example.com',
                'pekerjaan' => 'PNS',
                'nama_ibu_kandung' => 'Maryati',
                'status_anggota' => Anggota::STATUS_ACTIVE,
                'tanggal_gabung' => '2022-03-15',
                'simpanan_pokok' => 100000,
                'simpanan_wajib' => 50000,
                'keterangan' => null,
            ],
            [
                'no_anggota' => 'ANG-2022-0003',
                'user_id' => null,
                'nik' => '3201010303880003',
                'nama_lengkap' => 'Ahmad Wijaya',
                'tempat_lahir' => 'Cirebon',
                'tanggal_lahir' => '1988-07-10',
                'jenis_kelamin' => 'Laki-laki',
                'status_perkawinan' => 'Menikah',
                'alamat' => 'Jl. Asia Afrika No. 67',
                'rt_rw' => '010/015',
                'kelurahan' => 'Paledang',
                'kecamatan' => 'Lengkong',
                'kota' => 'Bandung',
                'provinsi' => 'Jawa Barat',
                'kode_pos' => '40114',
                'no_telepon' => '022-7654321',
                'no_hp' => '081324681012',
                'email' => 'ahmad@example.com',
                'pekerjaan' => 'Karyawan Swasta',
                'nama_ibu_kandung' => 'Rohani',
                'status_anggota' => Anggota::STATUS_ACTIVE,
                'tanggal_gabung' => '2022-06-01',
                'simpanan_pokok' => 100000,
                'simpanan_wajib' => 50000,
                'keterangan' => 'Karyawan PT Maju Mundur',
            ],
            [
                'no_anggota' => 'ANG-2023-0001',
                'user_id' => null,
                'nik' => '3201010404950004',
                'nama_lengkap' => 'Dewi Lestari',
                'tempat_lahir' => 'Garut',
                'tanggal_lahir' => '1995-11-02',
                'jenis_kelamin' => 'Perempuan',
                'status_perkawinan' => 'Belum Menikah',
                'alamat' => 'Jl. Dago No. 89',
                'rt_rw' => '007/004',
                'kelurahan' => 'Dago',
                'kecamatan' => 'Coblong',
                'kota' => 'Bandung',
                'provinsi' => 'Jawa Barat',
                'kode_pos' => '40135',
                'no_telepon' => null,
                'no_hp' => '085612345678',
                'email' => 'dewi@example.com',
                'pekerjaan' => 'Dokter',
                'nama_ibu_kandung' => 'Sri Wahyuni',
                'status_anggota' => Anggota::STATUS_ACTIVE,
                'tanggal_gabung' => '2023-01-20',
                'simpanan_pokok' => 100000,
                'simpanan_wajib' => 50000,
                'keterangan' => null,
            ],
            [
                // Nomor anggota sengaja berbeda dari CIF dan TIDAK memakai
                // rentang ANG-2026-000x — rentang itu dipakai DemoDataSeeder
                // untuk 5 anggota demo, sehingga firstOrCreate-nya tidak
                // "menyerobot" Adam Renata dan mencemari studi kasus ini.
                'no_anggota' => 'ANG-2026-0017',
                'no_cif' => 'ANG-2026-0017',
                'user_id' => null,
                'nik' => '3201010505260005',
                'nama_lengkap' => 'Adam Renata',
                'tempat_lahir' => 'Jakarta',
                'tanggal_lahir' => '1993-05-15',
                'jenis_kelamin' => 'Laki-laki',
                'status_perkawinan' => 'Menikah',
                'alamat' => 'Jl. Gatot Subroto No. 12',
                'rt_rw' => '002/003',
                'kelurahan' => 'Kuningan',
                'kecamatan' => 'Setiabudi',
                'kota' => 'Jakarta Selatan',
                'provinsi' => 'DKI Jakarta',
                'kode_pos' => '12920',
                'no_telepon' => '021-5252100',
                'no_hp' => '081234567895',
                'email' => 'adam.renata@koperasi.test',
                'pekerjaan' => 'Pengusaha',
                'nama_ibu_kandung' => 'Ratna Sari',
                // Studi kasus alur pendaftaran: anggota BARU selalu berstatus
                // PENDING (menunggu setoran awal). Aktivasi menjadi ACTIVE
                // dilakukan AdamRenataSeeder setelah setoran awal dilunasi
                // Teller — bukan ditulis langsung di sini.
                'status_anggota' => Anggota::STATUS_PENDING,
                // Preferensi pembayaran Simpanan Wajib: auto-debit dari saldo
                // Simpanan Sukarela (bukan setor manual ke Teller).
                'is_auto_debit' => true,
                'tanggal_gabung' => '2026-01-31',
                'simpanan_pokok' => 100000,
                'simpanan_wajib' => 50000,
                'keterangan' => 'Anggota utama — studi kasus Adam Renata (CIF ANG-2026-0017, join 31 Jan 2026)',
            ],
        ];

        foreach ($anggotas as $anggota) {
            // Upsert agar seeder idempoten — aman dijalankan berulang tanpa
            // memicu "Duplicate entry" pada kolom unik (no_anggota/nik/no_cif).
            Anggota::updateOrCreate(
                ['no_anggota' => $anggota['no_anggota']],
                $anggota
            );
        }
    }
}
