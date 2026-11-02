<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LindungiProduksi;
use App\Support\HierarkiRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetDataAnggota extends Command
{
    use LindungiProduksi;

    protected $signature = 'reset:data-anggota {--force : Jalankan tanpa konfirmasi}';

    protected $description = 'Hapus semua data transaksi, anggota, dan COA entries. Pertahankan data master dan user staff.';

    public function handle()
    {
        // GUARD PRODUKSI: command ini menghapus buku besar secara permanen.
        if ($this->tolakDiProduksiTanpaForce('reset:data-anggota')) {
            return 1;
        }

        // Daftar peran diturunkan dari SSOT RBAC (DYN-14), bukan dipatri di sini:
        // peran anggota mengikuti kelompok 'anggota', staff = sisanya.
        $peranAnggota = HierarkiRole::kelompok('anggota');
        $peranStaff = array_values(array_diff(HierarkiRole::peran(), $peranAnggota));

        if (! $this->option('force')) {
            $this->warn('PERINGATAN: Perintah ini akan menghapus SEMUA data berikut:');
            $this->line('  - Jurnal & Jurnal Detail');
            $this->line('  - Angsuran');
            $this->line('  - Pembayaran Simpanan');
            $this->line('  - Approval Transaksi');
            $this->line('  - Transaksi');
            $this->line('  - Pembiayaan');
            $this->line('  - Dokumen Pembiayaan');
            $this->line('  - Pengajuan Pembiayaan (termasuk dokumen & opini DPS)');
            $this->line('  - Pengajuan Rekening');
            $this->line('  - Rekening');
            $this->line('  - Kas Harian');
            $this->line('  - Dana Kebajikan');
            $this->line('  - Pengeluaran Beban');
            $this->line('  - Audit Logs');
            $this->line('  - Anggota');
            $this->line('  - Users dengan role '.implode('/', $peranAnggota));
            $this->line('');
            $this->line('Data yang DIPERTAHANKAN:');
            $this->line('  - Users staff ('.implode(', ', $peranStaff).')');
            $this->line('  - COA / Chart of Accounts (struktur akun)');
            $this->line('  - Master Produk Pembiayaan & Syarat Dokumen');
            $this->line('  - Kebijakan Koperasi');
            $this->line('  - Limit Transaksi Role');
            $this->line('');

            if (! $this->confirm('Apakah Anda yakin ingin melanjutkan?')) {
                $this->info('Dibatalkan.');

                return 0;
            }
        }

        $this->info('Memulai reset data...');

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        $tables = [
            'audit_logs',
            'approval_transaksi',
            'jurnal_detail',
            'jurnal',
            'angsuran',
            'pembayaran_simpanan',
            'transaksi',
            'dokumen_pembiayaan',
            'dps_opini_catatan',
            'dokumen_pengajuan_pembiayaan',
            'pengajuan_pembiayaan',
            'pembiayaan',
            'pengajuan_rekening',
            'rekening',
            'kas_harian',
            'dana_kebajikans',
            'pengeluaran_beban',
            'anggota',
        ];

        foreach ($tables as $table) {
            DB::table($table)->truncate();
            $this->line("  ✓ Tabel '{$table}' dikosongkan.");
        }

        // Hapus users berperan anggota — daftar peran dari SSOT RBAC (DYN-14),
        // sehingga peran baru berkategori anggota ikut terhapus otomatis.
        $deletedAnggota = DB::table('users')->whereIn('role', $peranAnggota)->delete();
        if ($deletedAnggota > 0) {
            $this->line("  ✓ {$deletedAnggota} user anggota dihapus.");
        } else {
            $this->line('  ✓ Tidak ada user anggota yang perlu dihapus.');
        }

        // Reset saldo_awal COA ke 0 (saldo dihitung dinamis dari jurnal)
        if (DB::getSchemaBuilder()->hasTable('coas')) {
            DB::table('coas')->update(['saldo_awal' => 0]);
            $this->line('  ✓ saldo_awal semua akun COA direset ke 0.');
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->newLine();
        $this->info('Reset data selesai. Sistem siap digunakan dengan data baru.');

        return 0;
    }
}
