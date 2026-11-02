<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Satu sumber kebenaran: config/app.php sudah menghitung password demo
        // (termasuk fallback non-produksi). Seeder TIDAK boleh menetapkan
        // fallback sendiri — dua tempat fallback membuat password di database
        // bisa berbeda dari yang diisi tombol "Akses Cepat Demo Akun".
        $password = (string) config('app.demo.password', '');

        // Lapis kedua di luar config/app.php: akun demo tidak pernah dibuat di
        // produksi, sekalipun konfigurasi di-override saat runtime. Password
        // sendiri sudah dihitung config (termasuk fallback non-produksi).
        if ($password === '' || app()->environment('production')) {
            $this->command?->warn(
                'UserSeeder dilewati: akun demo hanya dibuat di lingkungan non-produksi dengan DEMO_USER_PASSWORD terisi.'
            );

            return;
        }

        $users = [
            // Akun administrator sistem — hierarki tertinggi di antara peran
            // administratif (super_admin > admin), tetapi BUKAN peran tata
            // kelola koperasi (DPS tetap pengawas tertinggi tata kelola).
            [
                'name' => 'Super Admin Sistem',
                'email' => 'superadmin@koperasi.test',
                'password' => Hash::make($password),
                'role' => 'super_admin',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
            // HANYA 5 ROLE — diurutkan dari paling atas:
            // DPS > Pengurus > Manajer > AO > Teller
            // Tidak ada akun 'admin' (admin = manajer) dan tidak ada akun 'anggota'.
            [
                'name' => 'DPS Ustadz Fulan',
                'email' => 'dps@koperasi.test',
                'password' => Hash::make($password),
                'role' => 'dps',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
            [
                'name' => 'Pengurus Hadi',
                'email' => 'pengurus@koperasi.test',
                'password' => Hash::make($password),
                'role' => 'pengurus',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
            [
                'name' => 'Manajer Ahmad',
                'email' => 'manajer@koperasi.test',
                'password' => Hash::make($password),
                'role' => 'manajer',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
            [
                'name' => 'AO Rudi',
                'email' => 'ao@koperasi.test',
                'password' => Hash::make($password),
                'role' => 'ao',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
            [
                'name' => 'Teller Siti',
                'email' => 'teller@koperasi.test',
                'password' => Hash::make($password),
                'role' => 'teller',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        ];

        foreach ($users as $user) {
            User::firstOrCreate(['email' => $user['email']], $user);
        }
    }
}
