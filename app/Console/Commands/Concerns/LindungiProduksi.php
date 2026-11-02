<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * Guard produksi untuk command DESTRUKTIF / SEEDER.
 *
 * Beberapa command di aplikasi ini memutasi data keuangan secara permanen
 * (menghapus transaksi, jurnal, rekening, anggota, atau menyuntikkan data
 * sampel). Tanpa guard, satu salah ketik di lingkungan produksi dapat
 * menghapus buku besar koperasi secara tidak dapat dipulihkan.
 *
 * Trait ini menolak eksekusi di produksi KECUALI operator secara eksplisit
 * menyatakan niat melalui opsi `--force`, dan setiap percobaan (ditolak
 * maupun diizinkan) meninggalkan jejak audit di log aplikasi.
 */
trait LindungiProduksi
{
    /**
     * Apakah command berjalan di lingkungan produksi.
     */
    protected function diProduksi(): bool
    {
        return app()->isProduction();
    }

    /**
     * Tolak eksekusi command destruktif di produksi.
     *
     * @param  string  $namaCommand  Nama command untuk jejak audit.
     * @return bool true bila eksekusi harus DIHENTIKAN (ditolak).
     */
    protected function tolakDiProduksiTanpaForce(string $namaCommand): bool
    {
        if (! $this->diProduksi() || $this->option('force')) {
            if ($this->diProduksi()) {
                // Diizinkan, tetapi tetap dicatat: operator harus dapat
                // menelusuri siapa menjalankan command merusak dan kapan.
                Log::warning("Command destruktif {$namaCommand} DIJALANKAN di PRODUKSI dengan opsi --force.", [
                    'command' => $namaCommand,
                    'user' => get_current_user() ?: 'cli',
                ]);
            }

            return false;
        }

        $this->error('DITOLAK: command ini memutasi/menghapus data secara permanen dan TIDAK boleh dijalankan di lingkungan PRODUKSI tanpa opsi --force.');
        $this->line('Jalankan ulang dengan --force bila Anda benar-benar bermaksud demikian, atau gunakan data backup terlebih dahulu.');

        Log::critical("Command destruktif {$namaCommand} DITOLAK di PRODUKSI (tanpa --force).", [
            'command' => $namaCommand,
            'user' => get_current_user() ?: 'cli',
        ]);

        return true;
    }
}
