<?php

namespace App\Console\Commands;

use App\Services\JurnalReconciliationService;
use App\Support\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pemeriksaan operasional berkala (pemantauan "denyut kesehatan").
 *
 * Satu perintah untuk empat indikator yang menentukan ketersediaan layanan:
 *
 *   1. KETERSEDIAAN BASIS DATA — koneksi benar-benar dapat melayani kueri.
 *   2. INTEGRITAS PEMBUKUAN    — setiap jurnal wajib debet = kredit.
 *   3. KESEGARAN CADANGAN      — berkas .sql.gz terbaru tidak boleh lebih tua
 *                                dari ambang (default 26 jam ≈ RPO 24 jam
 *                                + toleransi 2 jam).
 *   4. REKONSILIASI SUBLEDGER  — saldo rekening anggota sama dengan saldo akun
 *                                kontrol simpanan di buku besar.
 *
 * Pemeriksaan bersifat BACA-SAJA: tidak membuka transaksi dan tidak menulis
 * ke tabel mana pun, sehingga aman dijalankan kapan saja — termasuk di
 * tengah jam operasional.
 *
 * Kegagalan dicatat ke kanal log `denyut` (dibatasi sekali per jam per
 * indikator agar log tidak dibanjiri) dan mengembalikan kode keluar FAILURE
 * supaya pemantau luar (Uptime Kuma, Zabbix, Task Scheduler) dapat memicu
 * peringatan.
 */
class PeriksaDenyutOperasional extends Command
{
    protected $signature = 'denyut:pemeriksaan
                            {--toleransi-cadangan-jam=26 : Umur maksimum berkas cadangan (jam)}';

    protected $description = 'Periksa ketersediaan basis data, integritas jurnal, kesegaran cadangan, dan kesesuaian subledger simpanan.';

    public function __construct(private readonly JurnalReconciliationService $reconciliation)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $gagal = 0;

        $gagal += $this->periksaBasisData();
        $gagal += $this->periksaJurnal();
        $gagal += $this->periksaSubledger();
        $gagal += $this->periksaCadangan((int) $this->option('toleransi-cadangan-jam'));

        if ($gagal === 0) {
            $this->info('SELURUH INDIKATOR SEHAT');

            return self::SUCCESS;
        }

        $this->error("{$gagal} indikator GAGAL — periksa segera.");

        return self::FAILURE;
    }

    private function periksaBasisData(): int
    {
        try {
            DB::select('SELECT 1');
        } catch (Throwable $e) {
            $this->line('[GAGAL] Basis data: '.$e->getMessage());
            $this->catat('pemantauan.database', 'Basis data tidak dapat dihubungi.', 'critical', ['error' => $e->getMessage()]);

            return 1;
        }

        $this->line('[OK]   Basis data: tersambung');

        return 0;
    }

    private function periksaJurnal(): int
    {
        try {
            // Ambang ketidakseimbangan dibaca dari kebijakan
            // (`Setting::toleransiSelisihJurnal()`), bukan literal 0,005 yang
            // membuat pemeriksaan ini lebih ketat daripada seluruh pemeriksaan
            // jurnal lain di sistem.
            $tidakBalance = DB::select(
                'SELECT j.id FROM jurnal j
                 JOIN jurnal_detail d ON d.jurnal_id = j.id
                 GROUP BY j.id
                 HAVING ABS(SUM(d.debet) - SUM(d.kredit)) > ?',
                [Setting::toleransiSelisihJurnal()]
            );
        } catch (Throwable $e) {
            $this->line('[GAGAL] Integritas jurnal: '.$e->getMessage());
            $this->catat('pemantauan.jurnal', 'Pemeriksaan integritas jurnal gagal dijalankan.', 'error', ['error' => $e->getMessage()]);

            return 1;
        }

        if ($tidakBalance !== []) {
            $ids = implode(', ', array_map(fn ($r) => $r->id, array_slice($tidakBalance, 0, 10)));
            $this->line(sprintf('[GAGAL] Integritas jurnal: %d jurnal tidak balance (id: %s)', count($tidakBalance), $ids));
            $this->catat('pemantauan.jurnal', 'Ditemukan jurnal dengan debet ≠ kredit.', 'critical', [
                'jumlah' => count($tidakBalance),
                'contoh' => $ids,
            ]);

            return 1;
        }

        $this->line('[OK]   Integritas jurnal: seluruh jurnal debet = kredit');

        return 0;
    }

    /**
     * Indikator keempat: saldo subledger rekening anggota vs akun kontrol GL.
     *
     * Selalu mengembalikan 0 pada MODE LAPOR meskipun ada selisih — kondisi
     * itu sudah dicatat `critical` di log `denyut` dan dilaporkan oleh
     * `jurnal:rekonsiliasi`. Nilai 1 hanya bila mode tegakkan aktif.
     */
    private function periksaSubledger(): int
    {
        try {
            $hasil = $this->reconciliation->rekonsiliasiSubledger();
        } catch (Throwable $e) {
            $this->line('[GAGAL] Rekonsiliasi subledger: '.$e->getMessage());
            $this->catat('pemantauan.subledger', 'Pemeriksaan subledger gagal dijalankan.', 'error', ['error' => $e->getMessage()]);

            return 1;
        }

        if ($hasil['seimbang']) {
            $this->line('[OK]   Rekonsiliasi subledger: saldo rekening = akun kontrol');

            return 0;
        }

        $ringkas = implode(' ', array_map(
            fn (array $b) => sprintf('%s selisih %.2f', $b['kode'], $b['selisih']),
            array_filter($hasil['baris'], fn (array $b) => $b['status'] !== 'cocok')
        ));

        $this->catat('pemantauan.subledger', 'Saldo subledger simpanan tidak sama dengan buku besar.', 'critical', [
            'baris' => $hasil['baris'],
        ]);

        if (! $this->reconciliation->gateSubledgerDitegakkan()) {
            $this->line("[LAPOR] Rekonsiliasi subledger: {$ringkas}");

            return 0;
        }

        $this->line("[GAGAL] Rekonsiliasi subledger: {$ringkas}");

        return 1;
    }

    private function periksaCadangan(int $toleransiJam): int
    {
        $berkas = array_merge(
            File::glob(storage_path('app/backups/*.sql.gz')),
            File::glob(storage_path('app/backups/*.sql.gz.enc'))
        );

        if ($berkas === []) {
            $this->line('[GAGAL] Cadangan: belum ada satu berkas pun di storage/app/backups');
            $this->catat('pemantauan.cadangan', 'Tidak ada berkas cadangan basis data.', 'critical');

            return 1;
        }

        usort($berkas, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $terbaru = $berkas[0];
        $umurJam = (time() - filemtime($terbaru)) / 3600;

        if ($umurJam > $toleransiJam) {
            $this->line(sprintf('[GAGAL] Cadangan: terbaru berumur %.1f jam (ambang %d jam)', $umurJam, $toleransiJam));
            $this->catat('pemantauan.cadangan', 'Cadangan basis data kedaluwarsa.', 'critical', [
                'berkas' => basename($terbaru),
                'umur_jam' => round($umurJam, 1),
            ]);

            return 1;
        }

        $this->line(sprintf(
            '[OK]   Cadangan: %s (%.1f jam lalu, %.1f KB)',
            basename($terbaru),
            $umurJam,
            File::size($terbaru) / 1024
        ));

        return 0;
    }

    /**
     * Catat kegagalan indikator ke kanal `denyut`, maksimal sekali per jam
     * per indikator. Kegagalan pencatatan tidak boleh menggagalkan perintah.
     *
     * @param  array<string,mixed>  $tambahan
     */
    private function catat(string $konteks, string $pesan, string $level, array $tambahan = []): void
    {
        try {
            if (! Cache::add('pemantauan:log:'.$konteks, true, now()->addHour())) {
                return;
            }

            Log::channel('denyut')->log($level, $pesan, array_merge(['konteks' => $konteks], $tambahan));
        } catch (Throwable) {
            // Pemantauan tidak boleh menjadi sumber kegagalan baru.
        }
    }
}
