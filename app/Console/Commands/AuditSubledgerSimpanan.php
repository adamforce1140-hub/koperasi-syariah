<?php

namespace App\Console\Commands;

use App\Models\Coa;
use App\Models\JenisSimpanan;
use App\Services\JurnalReconciliationService;
use App\Support\Coa as KodeCoa;
use App\Support\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Audit atribusi selisih subledger simpanan (P1-2).
 *
 * Melengkapi `jurnal:rekonsiliasi` yang hanya memberi selisih per AKUN
 * KONTROL. Perintah ini menurunkan selisih tersebut ke tingkat ANGGOTA,
 * sehingga dapat diketahui rekening/anggota mana yang menyebabkan selisih
 * dan skenario remediasi mana yang berlaku (R-1/R-2/R-3).
 *
 * Keluaran per anggota:
 *   anggota_id | no_anggota | nama | no_rekening | saldo_subledger |
 *   mutasi_gl | selisih | jumlah_transaksi
 *
 * `mutasi_gl` dihitung dari `jurnal_detail` (jurnal Posted, belum dihapus)
 * yang tertaut `anggota_id`. Baris GL tanpa `anggota_id` dikelompokkan
 * sebagai TANPA_ANGGOTA — kelompok inilah yang paling mungkin menjadi
 * penyebab selisih dan harus ditelusuri manual.
 *
 * PERINTAH INI HANYA MEMBACA. Ia tidak menulis jurnal, tidak mengubah saldo
 * rekening, dan tidak melakukan remediasi. Jurnal penyesuaian tetap dibuat
 * manual di UI jurnal dengan berita acara (lihat docs P1-2).
 */
class AuditSubledgerSimpanan extends Command
{
    protected $signature = 'simpanan:audit-subledger
                            {--kode=* : Kode akun kontrol yang diaudit (boleh berulang; bawaan 2-1001 & 2-1003)}
                            {--format=table : Format keluaran: table|json|csv}
                            {--hanya-berselisih : Sembunyikan baris yang selisihnya nol}';

    protected $description = 'Audit read-only atribusi selisih subledger simpanan per anggota: saldo rekening vs mutasi buku besar.';

    public function handle(): int
    {
        $format = strtolower(trim((string) $this->option('format')));

        if (! in_array($format, ['table', 'json', 'csv'], true)) {
            $this->error("Format tidak dikenal: '{$format}'. Gunakan table|json|csv.");

            return self::FAILURE;
        }

        $kode = $this->option('kode');
        $kode = is_array($kode) ? $kode : [$kode];
        $kode = array_values(array_unique(array_filter(array_map(
            static fn ($nilai) => trim((string) $nilai),
            $kode
        ))));

        if ($kode === []) {
            $kode = [KodeCoa::SIMPANAN_SUKARELA, KodeCoa::SIMPANAN_BERJANGKA];
        }

        $toleransi = Setting::toleransiSelisihJurnal();
        $hanya = (bool) $this->option('hanya-berselisih');

        $laporan = [];

        foreach ($kode as $satu) {
            $laporan[] = $this->auditKode($satu, $toleransi, $hanya);
        }

        if ($format === 'json') {
            $this->output->writeln((string) json_encode(
                ['toleransi' => $toleransi, 'akun' => $laporan],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            ));

            return self::SUCCESS;
        }

        if ($format === 'csv') {
            $this->tulisCsv($laporan);

            return self::SUCCESS;
        }

        $this->tampilkanTabel($laporan, $toleransi);

        return self::SUCCESS;
    }

    /**
     * Hitung atribusi satu akun kontrol.
     *
     * @return array{
     *     kode: string,
     *     tipe: string|null,
     *     coa_ada: bool,
     *     gl: float,
     *     subledger: float,
     *     selisih: float,
     *     baris: array<int, array<string, mixed>>
     * }
     */
    private function auditKode(string $kode, float $toleransi, bool $hanyaBerselisih): array
    {
        $tipe = JurnalReconciliationService::TIPE_BY_KODE[$kode] ?? null;
        $nama = $tipe !== null ? JenisSimpanan::namaRekening($tipe) : [];

        $coaId = Coa::query()->where('kode_akun', $kode)->value('id');

        if ($coaId === null || $nama === []) {
            return [
                'kode' => $kode,
                'tipe' => $tipe,
                'coa_ada' => $coaId !== null,
                'gl' => 0.0,
                'subledger' => 0.0,
                'selisih' => 0.0,
                'baris' => [],
            ];
        }

        $rekening = DB::table('rekening as r')
            ->leftJoin('anggota as a', 'a.id', '=', 'r.anggota_id')
            ->whereNull('r.deleted_at')
            ->whereIn('r.jenis_rekening', $nama)
            ->orderBy('a.no_anggota')
            ->orderBy('r.no_rekening')
            ->get([
                'r.id',
                'r.no_rekening',
                'r.anggota_id',
                'r.saldo',
                'a.no_anggota',
                'a.nama_lengkap',
            ]);

        // ── Subledger per anggota (satu anggota bisa punya >1 rekening) ────
        $perAnggota = [];

        foreach ($rekening as $baris) {
            $kunci = $baris->anggota_id === null ? 'TANPA_ANGGOTA' : (string) $baris->anggota_id;

            $perAnggota[$kunci] ??= [
                'anggota_id' => $baris->anggota_id === null ? null : (int) $baris->anggota_id,
                'no_anggota' => $baris->anggota_id === null ? 'TANPA_ANGGOTA' : ($baris->no_anggota ?: '-'),
                'nama' => $baris->anggota_id === null ? 'TANPA_ANGGOTA' : ($baris->nama_lengkap ?: '-'),
                'no_rekening' => [],
                'saldo_subledger' => 0.0,
                'mutasi_gl' => 0.0,
                'jumlah_transaksi' => 0,
            ];

            $perAnggota[$kunci]['no_rekening'][] = (string) $baris->no_rekening;
            $perAnggota[$kunci]['saldo_subledger'] += (float) $baris->saldo;
        }

        // ── Mutasi GL per anggota ─────────────────────────────────────────
        $gl = DB::table('jurnal_detail as d')
            ->join('jurnal as j', function ($join) {
                $join->on('j.id', '=', 'd.jurnal_id')->whereNull('j.deleted_at');
            })
            ->where('j.status', 'Posted')
            ->where('d.coa_id', $coaId)
            ->groupBy('d.anggota_id')
            ->get([
                'd.anggota_id',
                DB::raw('COALESCE(SUM(d.kredit), 0) - COALESCE(SUM(d.debet), 0) as saldo'),
            ]);

        foreach ($gl as $baris) {
            $kunci = $baris->anggota_id === null ? 'TANPA_ANGGOTA' : (string) $baris->anggota_id;

            $perAnggota[$kunci] ??= [
                'anggota_id' => $baris->anggota_id === null ? null : (int) $baris->anggota_id,
                'no_anggota' => $baris->anggota_id === null ? 'TANPA_ANGGOTA' : '-',
                'nama' => $baris->anggota_id === null ? 'TANPA_ANGGOTA' : '-',
                'no_rekening' => [],
                'saldo_subledger' => 0.0,
                'mutasi_gl' => 0.0,
                'jumlah_transaksi' => 0,
            ];

            $perAnggota[$kunci]['mutasi_gl'] += (float) $baris->saldo;
        }

        // ── Profil anggota yang hanya muncul di sisi GL ────────────────────
        $tanpaProfil = array_values(array_filter(
            array_keys($perAnggota),
            static fn (string $kunci) => $kunci !== 'TANPA_ANGGOTA'
                && $perAnggota[$kunci]['no_anggota'] === '-'
        ));

        if ($tanpaProfil !== []) {
            $profil = DB::table('anggota')
                ->whereIn('id', array_map('intval', $tanpaProfil))
                ->get(['id', 'no_anggota', 'nama_lengkap'])
                ->keyBy('id');

            foreach ($tanpaProfil as $kunci) {
                $data = $profil->get((int) $kunci);

                if ($data !== null) {
                    $perAnggota[$kunci]['no_anggota'] = $data->no_anggota ?: '-';
                    $perAnggota[$kunci]['nama'] = $data->nama_lengkap ?: '-';
                }
            }
        }

        // ── Jumlah transaksi pada rekening produk ini ─────────────────────
        $rekeningIds = $rekening->pluck('id')->all();

        if ($rekeningIds !== []) {
            $jumlah = DB::table('transaksi')
                ->whereNull('deleted_at')
                ->whereIn('rekening_id', $rekeningIds)
                ->groupBy('anggota_id')
                ->get([
                    'anggota_id',
                    DB::raw('COUNT(*) as jumlah'),
                ]);

            foreach ($jumlah as $baris) {
                $kunci = $baris->anggota_id === null ? 'TANPA_ANGGOTA' : (string) $baris->anggota_id;

                if (isset($perAnggota[$kunci])) {
                    $perAnggota[$kunci]['jumlah_transaksi'] = (int) $baris->jumlah;
                }
            }
        }

        // Total akun dihitung dari SELURUH baris (sebelum `--hanya-berselisih`
        // menyaring), supaya nilainya tetap sama dengan tabel di
        // `jurnal:rekonsiliasi`. Menghitung dari `$hasil` akan membuat total
        // ikut menyusut ketika opsi penyaring dipakai.
        $totalGl = array_sum(array_column($perAnggota, 'mutasi_gl'));
        $totalSubledger = array_sum(array_column($perAnggota, 'saldo_subledger'));

        // ── Susun baris keluaran ──────────────────────────────────────────
        $hasil = [];

        foreach ($perAnggota as $data) {
            $selisih = $data['mutasi_gl'] - $data['saldo_subledger'];

            if ($hanyaBerselisih && abs($selisih) < $toleransi) {
                continue;
            }

            $data['no_rekening'] = $data['no_rekening'] === []
                ? '-'
                : implode(', ', $data['no_rekening']);
            $data['selisih'] = $selisih;

            $hasil[] = $data;
        }

        usort($hasil, static function (array $a, array $b) {
            if ($a['anggota_id'] === null) {
                return 1;
            }

            if ($b['anggota_id'] === null) {
                return -1;
            }

            return [$a['no_anggota'], $a['anggota_id']] <=> [$b['no_anggota'], $b['anggota_id']];
        });

        return [
            'kode' => $kode,
            'tipe' => $tipe,
            'coa_ada' => true,
            'gl' => $totalGl,
            'subledger' => $totalSubledger,
            'selisih' => $totalGl - $totalSubledger,
            'baris' => $hasil,
        ];
    }

    /** @param array<int, array<string, mixed>> $laporan */
    private function tampilkanTabel(array $laporan, float $toleransi): void
    {
        $this->info('AUDIT SUBLEDGER SIMPANAN (read-only)');
        $this->line('Toleransi selisih: '.$this->rupiah($toleransi));
        $this->newLine();

        foreach ($laporan as $akun) {
            $this->line("AKUN [{$akun['kode']}] tipe: ".($akun['tipe'] ?? 'tidak diketahui'));

            if (! $akun['coa_ada']) {
                $this->warn('  Akun kontrol tidak ditemukan di bagan akun — dilewati.');
                $this->newLine();

                continue;
            }

            if ($akun['tipe'] === null) {
                $this->warn('  Akun ini tidak memiliki subledger rekening — dilewati.');
                $this->newLine();

                continue;
            }

            $this->line('  GL: '.$this->rupiah((float) $akun['gl'])
                .' | Subledger: '.$this->rupiah((float) $akun['subledger'])
                .' | Selisih: '.$this->rupiah((float) $akun['selisih']));

            if ($akun['baris'] === []) {
                $this->line('  Tidak ada baris untuk ditampilkan.');
                $this->newLine();

                continue;
            }

            $tabel = [];

            foreach ($akun['baris'] as $baris) {
                $tabel[] = [
                    $baris['anggota_id'] ?? '-',
                    $baris['no_anggota'],
                    $baris['nama'],
                    $baris['no_rekening'],
                    $this->rupiah((float) $baris['saldo_subledger']),
                    $this->rupiah((float) $baris['mutasi_gl']),
                    $this->rupiah((float) $baris['selisih']),
                    $baris['jumlah_transaksi'],
                ];
            }

            $this->table(
                ['ID', 'No Anggota', 'Nama', 'No Rekening', 'Subledger', 'Mutasi GL', 'Selisih', 'Trx'],
                $tabel
            );
            $this->newLine();
        }

        $berselisih = 0;

        foreach ($laporan as $akun) {
            foreach ($akun['baris'] as $baris) {
                if (abs((float) $baris['selisih']) >= $toleransi) {
                    $berselisih++;
                }
            }
        }

        if ($berselisih === 0) {
            $this->info('Tidak ada baris berselisih pada akun yang diaudit.');

            return;
        }

        $this->warn("Ditemukan {$berselisih} baris berselisih. Remediasi (R-1/R-2/R-3) "
            .'diputuskan setelah audit ini ditinjau — command tidak mengubah data.');
    }

    /** @param array<int, array<string, mixed>> $laporan */
    private function tulisCsv(array $laporan): void
    {
        $aliran = fopen('php://temp', 'r+');

        if ($aliran === false) {
            return;
        }

        fputcsv($aliran, [
            'kode_akun', 'anggota_id', 'no_anggota', 'nama', 'no_rekening',
            'saldo_subledger', 'mutasi_gl', 'selisih', 'jumlah_transaksi',
        ]);

        foreach ($laporan as $akun) {
            foreach ($akun['baris'] as $baris) {
                fputcsv($aliran, [
                    $akun['kode'],
                    $baris['anggota_id'] ?? '',
                    $baris['no_anggota'],
                    $baris['nama'],
                    $baris['no_rekening'],
                    number_format((float) $baris['saldo_subledger'], 2, '.', ''),
                    number_format((float) $baris['mutasi_gl'], 2, '.', ''),
                    number_format((float) $baris['selisih'], 2, '.', ''),
                    $baris['jumlah_transaksi'],
                ]);
            }
        }

        rewind($aliran);
        $this->output->write((string) stream_get_contents($aliran));
        fclose($aliran);
    }

    private function rupiah(float $nilai): string
    {
        return 'Rp '.number_format($nilai, 2, ',', '.');
    }
}
