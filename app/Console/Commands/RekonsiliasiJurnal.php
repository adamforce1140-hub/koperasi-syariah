<?php

namespace App\Console\Commands;

use App\Services\JurnalReconciliationService;
use Illuminate\Console\Command;

/**
 * Rekonsiliasi buku besar — verifikasi otomatis keseimbangan AKTIVA vs PASIVA
 * sekaligus kesesuaian subledger rekening anggota dengan akun kontrolnya.
 *
 * Melengkapi mode INLINE yang berjalan setiap kali transaksi diposting
 * (`AutoJournalingService::post()` → `JurnalReconciliationService::pastikanJurnalSehat()`).
 * Perintah ini melakukan audit MENYELURUH atas seluruh buku besar — dapat
 * dijalankan manual maupun dijadwalkan (mis. tiap malam) sebagai gerbang
 * integritas laporan keuangan.
 *
 * Yang diverifikasi:
 *   1. BUKU_BESAR_TIDAK_SEIMBANG — total debet ≠ total kredit (double-entry bocor).
 *   2. JURNAL_TIMBANG            — ada jurnal Posted yang baris detailnya tidak balance.
 *   3. TRANSAKSI_BELUM_SUKSES    — jurnal tertaut transaksi gagal/pending/void.
 *   4. SUBLEDGER_SELISIH         — saldo rekening anggota ≠ saldo akun kontrol
 *                                  (2-1001 Sukarela, 2-1003 Berjangka).
 *
 * Sekaligus menampilkan NERACA ringkas per kategori (Aset, Kewajiban, Ekuitas,
 * Pendapatan, Beban) sebagai bukti keseimbangan aktiva–pasiva.
 *
 * Perintah ini hanya MEMBACA data. Exit code FAILURE dikembalikan bila ada
 * temuan integritas buku besar. Selisih subledger mengikuti mode gate: pada
 * MODE LAPOR (bawaan) ia dilaporkan sebagai peringatan tanpa mengubah exit
 * code; pada MODE TEGAKKAN (`RECONCILIATION_GATE_ENFORCE=true`) ia dihitung
 * sebagai kegagalan.
 */
class RekonsiliasiJurnal extends Command
{
    protected $signature = 'jurnal:rekonsiliasi
                            {--dari= : Batasi awal periode (YYYY-MM-DD). Kosong = tanpa batas awal}
                            {--sampai= : Batasi akhir periode (YYYY-MM-DD). Kosong = tanpa batas akhir}';

    protected $description = 'Rekonsiliasi otomatis buku besar: verifikasi keseimbangan aktiva–pasiva, jurnal timbang, status keberhasilan transaksi, dan kesesuaian subledger simpanan.';

    public function handle(JurnalReconciliationService $reconciliation): int
    {
        $dari = $this->option('dari') ?: null;
        $sampai = $this->option('sampai') ?: null;

        $hasil = $reconciliation->rekonsiliasiBukuBesar($dari, $sampai);

        $this->info('REKONSILIASI BUKU BESAR');
        $this->line('Periode: '.($dari ?? 'awal').' s.d. '.($sampai ?? 'sekarang'));
        $this->newLine();

        // ── Neraca ringkas per kategori ────────────────────────────────────
        $this->line('NERACA (saldo neto per kategori):');
        $barisNeraca = [];
        foreach ($hasil['neraca'] as $kategori => $saldo) {
            $barisNeraca[] = [$kategori, $this->rupiah((float) $saldo)];
        }

        $this->table(['Kategori', 'Saldo Neto'], $barisNeraca);
        $this->newLine();

        $this->line('Total debet : '.$this->rupiah((float) $hasil['total_debet']));
        $this->line('Total kredit: '.$this->rupiah((float) $hasil['total_kredit']));
        $this->line('Selisih     : '.$this->rupiah((float) $hasil['selisih']));
        $this->newLine();

        // ── Subledger vs buku besar ────────────────────────────────────────
        $subledgerBermasalah = $this->tampilkanSubledger($hasil['subledger'] ?? []);

        // ── Temuan ─────────────────────────────────────────────────────────
        $temuan = $hasil['temuan'];

        if (empty($temuan) && $subledgerBermasalah === []) {
            $this->info('Buku besar SEIMBANG. Tidak ada temuan — aktiva & pasiva konsisten.');

            return self::SUCCESS;
        }

        if (! empty($temuan)) {
            $this->warn('Ringkasan temuan:');
            $this->table(
                ['Jumlah', 'Aturan'],
                [[count($temuan), $temuan[0]['aturan'] ?? 'BEBERAPA_ATURAN']]
            );

            foreach ($temuan as $t) {
                $this->line(sprintf('  • [%s] %s', $t['aturan'] ?? '-', $t['pesan'] ?? '-'));
            }

            $this->newLine();
        }

        if (! empty($temuan) && $subledgerBermasalah !== []) {
            $this->newLine();
        }

        if ($subledgerBermasalah !== []) {
            $tegakkan = $reconciliation->gateSubledgerDitegakkan();

            $this->warn('Ringkasan subledger:');

            foreach ($subledgerBermasalah as $t) {
                $this->line(sprintf('  • [%s] %s', $t['aturan'], $t['pesan']));
            }

            $this->newLine();

            if ($tegakkan) {
                $this->error(
                    'Ditemukan '.count($subledgerBermasalah).' selisih subledger (mode TEGAKKAN). '
                        .'Transaksi simpanan akan ditolak sampai selisih diremediasi.'
                );
            } else {
                $this->warn(
                    'Ditemukan '.count($subledgerBermasalah).' selisih subledger (mode LAPOR — '
                        .'tidak memblokir operasional). Remediasi menunggu keputusan P1-2.'
                );
            }
        }

        if (! empty($temuan)) {
            $this->error('Ditemukan '.count($temuan).' temuan rekonsiliasi. Periksa sebelum menutup periode.');

            return self::FAILURE;
        }

        // Selisih subledger hanya menggagalkan exit code pada mode tegakkan.
        return $reconciliation->gateSubledgerDitegakkan() ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Tampilkan tabel subledger dan kembalikan daftar temuan yang perlu
     * dilaporkan (selisih maupun CoA hilang).
     *
     * @param  array<int, array{kode: string, gl: float, subledger: float, selisih: float, status: string}>  $baris
     * @return array<int, array{aturan: string, pesan: string}>
     */
    private function tampilkanSubledger(array $baris): array
    {
        if ($baris === []) {
            return [];
        }

        $this->line('SUBLEDGER SIMPANAN vs BUKU BESAR:');

        $tabel = [];
        $temuan = [];

        foreach ($baris as $item) {
            $tabel[] = [
                $item['kode'],
                $this->rupiah((float) $item['gl']),
                $this->rupiah((float) $item['subledger']),
                $this->rupiah((float) $item['selisih']),
                strtoupper($item['status']),
            ];

            if ($item['status'] === 'selisih') {
                $temuan[] = [
                    'aturan' => 'SUBLEDGER_SELISIH',
                    'pesan' => "Akun [{$item['kode']}] GL ".$this->rupiah((float) $item['gl'])
                        .' vs subledger '.$this->rupiah((float) $item['subledger'])
                        .' (selisih '.$this->rupiah((float) $item['selisih']).').',
                ];
            } elseif ($item['status'] === 'coa_hilang') {
                $temuan[] = [
                    'aturan' => 'SUBLEDGER_COA_HILANG',
                    'pesan' => "Akun kontrol [{$item['kode']}] tidak ditemukan di bagan akun.",
                ];
            }
        }

        $this->table(['Kode', 'GL', 'Subledger', 'Selisih', 'Status'], $tabel);
        $this->newLine();

        return $temuan;
    }

    private function rupiah(float $nilai): string
    {
        return 'Rp '.number_format($nilai, 2, ',', '.');
    }
}
