<?php

namespace App\Console\Commands;

use App\Models\LaporanKeuanganLog;
use App\Support\LaporanKeuanganPermission;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Menyegarkan (menghitung ulang) segel rantai hash log audit Laporan Keuangan.
 *
 * Diperlukan karena versi lama stringUntukHash() belum menormalkan urutan
 * kunci kolom JSON. MySQL menyimpan objek JSON dengan kunci terurut ulang,
 * sehingga baris yang punya parameter/perubahan_data banyak kunci menghasilkan
 * hash yang tidak cocok saat diverifikasi — seolah-olah disunting, padahal
 * tidak. Setelah perbaikan kanonikalisasi, baris-baris lama itu perlu disegel
 * ulang sekali agar rantai kembali utuh.
 *
 * Perintah ini HANYA menulis ulang kolom segel (hash_sebelumnya_id,
 * hash_sebelumnya, hash). Isi baris — pelaku, waktu, deskripsi, parameter —
 * tidak disentuh sama sekali, dan setiap penyegaran ulang dicatat sebagai
 * baris audit baru supaya tidak ada perubahan yang hilang diam-diam.
 */
class SegarkanRantaiLaporanKeuangan extends Command
{
    protected $signature = 'laporan-keuangan:segarkan-rantai
                            {--force : Terapkan penyegaran (tanpa opsi ini hanya menampilkan pratinjau)}';

    protected $description = 'Hitung ulang segel rantai hash log audit Laporan Keuangan dengan kanonikalisasi terkini.';

    public function handle(): int
    {
        $terapkan = (bool) $this->option('force');

        $baris = LaporanKeuanganLog::orderBy('id')->get();

        if ($baris->isEmpty()) {
            $this->info('Tabel log audit masih kosong — tidak ada yang perlu disegarkan.');

            return self::SUCCESS;
        }

        $hashSebelumnya = null;
        $idSebelumnya = null;
        $disegarkan = [];

        foreach ($baris as $log) {
            $idLama = $log->hash_sebelumnya_id;
            $hashLama = $log->hash_sebelumnya;
            $segelLama = $log->hash;

            // Rangkai ulang ke baris sebelumnya, lalu hitung segel yang benar.
            $log->hash_sebelumnya_id = $idSebelumnya;
            $log->hash_sebelumnya = $hashSebelumnya;
            $segelBaru = $log->hitungHash();

            $berubah = $idLama !== $idSebelumnya
                || $hashLama !== $hashSebelumnya
                || $segelLama !== $segelBaru;

            if ($berubah) {
                $disegarkan[] = $log->id;

                if ($terapkan) {
                    // Sengaja lewat query builder, bukan model: guard append-only
                    // memang melarang penyuntingan isi baris, sedangkan di sini
                    // hanya segel yang dihitung ulang.
                    DB::table('laporan_keuangan_logs')
                        ->where('id', $log->id)
                        ->update([
                            'hash_sebelumnya_id' => $idSebelumnya,
                            'hash_sebelumnya' => $hashSebelumnya,
                            'hash' => $segelBaru,
                        ]);
                }
            }

            $hashSebelumnya = $segelBaru;
            $idSebelumnya = $log->id;
        }

        if ($disegarkan === []) {
            $this->info('Rantai hash sudah utuh ('.$baris->count().' baris diperiksa) — tidak ada yang diubah.');

            return self::SUCCESS;
        }

        if (! $terapkan) {
            $this->warn('Pratinjau: '.count($disegarkan).' baris akan disegel ulang.');
            $this->line('  #'.implode(', #', $disegarkan));
            $this->line('Jalankan ulang dengan --force untuk menerapkan.');

            return self::SUCCESS;
        }

        // Catat penyegaran sebagai baris audit baru, agar perubahan segel
        // punya jejak yang bisa dibaca auditor.
        LaporanKeuanganLog::catat([
            'user_nama' => 'sistem (artisan)',
            'modul' => LaporanKeuanganPermission::MODUL_AUDIT,
            'aktivitas' => LaporanKeuanganLog::AKTIVITAS_VERIFIKASI,
            'deskripsi' => 'Penyegaran segel rantai hash setelah perbaikan kanonikalisasi: '
                                .count($disegarkan).' baris disegel ulang.',
            'route' => 'laporan-keuangan:segarkan-rantai',
            'metode' => 'CLI',
            'perubahan_data' => ['baris_disegarkan' => $disegarkan],
            'berhasil' => true,
        ]);

        $this->info(count($disegarkan).' baris disegel ulang: #'.implode(', #', $disegarkan));

        $hasil = LaporanKeuanganLog::verifyChain();

        if ($hasil['utuh']) {
            $this->info('Verifikasi ulang: rantai UTUH ('.$hasil['diperiksa'].' baris).');

            return self::SUCCESS;
        }

        $this->error('Verifikasi ulang masih gagal pada baris #'.$hasil['rusak_di'].': '.$hasil['alasan']);

        return self::FAILURE;
    }
}
