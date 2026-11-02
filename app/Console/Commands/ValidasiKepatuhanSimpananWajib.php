<?php

namespace App\Console\Commands;

use App\Models\Coa;
use App\Models\MandatorySavingsBill;
use App\Services\MandatorySavingsService;
use App\Support\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Validasi kepatuhan akuntansi Simpanan Wajib terhadap PSAK 101 / 108.
 *
 * Perintah ini adalah GERBANG KEPATUHAN (Tahap 12 / R4): memverifikasi bahwa
 * seluruh transaksi Simpanan Wajib di sistem telah dicatat sesuai penyajian
 * PSAK 101 — Simpanan Wajib sebagai MODAL ANGGOTA (ekuitas), bukan kewajiban —
 * serta bahwa setiap tagihan lunas terhubung ke jurnal yang berimbang.
 *
 * Kategori pemeriksaan:
 *   1. AKUN_SIMPANAN_WAJIB_KEWAJIBAN — tagihan lunas yang jurnalnya mengkredit
 *      akun berkategori Kewajiban (pelanggaran penyajian PSAK 101 ¶ 31).
 *   2. TAGIHAN_LUNAS_TANPA_JURNAL    — tagihan PAID tanpa jurnal_id / jurnal
 *      tidak ditemukan (jejak audit hilang).
 *   3. TAGIHAN_PAID_TANPA_TRANSAKSI  — tagihan PAID tanpa transaksi_id.
 *   4. JURNAL_TIDAK_BERIMBANG        — jurnal Simpanan Wajib yang total
 *      debet ≠ kredit di luar toleransi kebijakan.
 *   5. NOMINAL_TAGIHAN_TIDAK_SESUAI  — nominal tagihan ≠ jumlah kredit jurnal
 *      simpanan (pelanggaran integritas double-entry).
 *   6. TAGIHAN_DUPLIKAT_PERIODE      — lebih dari satu tagihan untuk
 *      (anggota, periode) yang sama.
 *   7. SALDO_MODAL_NEGATIF           — akun modal Simpanan Wajib bersaldo
 *      negatif (indikasi jurnal terbalik).
 *
 * Perintah ini hanya MEMBACA data. Exit code FAILURE bila menemukan
 * pelanggaran, sehingga dapat dipakai sebagai gerbang pada pipeline rilis.
 */
class ValidasiKepatuhanSimpananWajib extends Command
{
    protected $signature = 'psak:validasi-simpanan-wajib
                            {--contoh=10 : Jumlah baris contoh yang ditampilkan per kategori}';

    protected $description = 'Validasi kepatuhan PSAK 101/108 modul Simpanan Wajib (penyajian modal anggota, jurnal berimbang, jejak audit).';

    public function handle(): int
    {
        if (! Schema::hasTable('mandatory_savings_bills')) {
            $this->warn('Tabel `mandatory_savings_bills` belum tersedia — validasi dilewati.');

            return self::SUCCESS;
        }

        $batasContoh = max(0, (int) $this->option('contoh'));
        $toleransi = Setting::toleransiSelisihJurnal();

        $akunKanonik = app(MandatorySavingsService::class)->akunSimpananWajib();

        // Hanya akun MODAL ANGGOTA (setoran modal: Simpanan Pokok 3-1001 &
        // Simpanan Wajib 3-1002) yang boleh diperiksa saldo negatifnya. Akun
        // Ekuitas lain — khususnya 3-9000 Ikhtisar SHU / Laba Ditahan
        // (klasifikasi 'Saldo Laba / SHU') — WAJAR negatif ketika koperasi
        // merugi, sehingga tidak boleh ditandai sebagai jurnal terbalik.
        $kodeModal = Coa::where('klasifikasi_neraca', 'Modal Anggota')->pluck('id')->all();
        $kodeWajib = Coa::whereIn('kode_akun', [$akunKanonik, \App\Support\Coa::SIMPANAN_WAJIB])
            ->pluck('id')
            ->all();

        $kategori = [
            'AKUN_SIMPANAN_WAJIB_KEWAJIBAN' => $this->kreditAkunKewajiban(),
            'TAGIHAN_LUNAS_TANPA_JURNAL' => $this->tagihanLunasTanpaJurnal(),
            'TAGIHAN_PAID_TANPA_TRANSAKSI' => $this->tagihanPaidTanpaTransaksi(),
            'JURNAL_TIDAK_BERIMBANG' => $this->jurnalTidakBerimbang($toleransi),
            'NOMINAL_TAGIHAN_TIDAK_SESUAI' => $this->nominalTagihanTidakSesuai($kodeWajib),
            'TAGIHAN_DUPLIKAT_PERIODE' => $this->tagihanDuplikatPeriode(),
            'SALDO_MODAL_NEGATIF' => $this->saldoModalNegatif($kodeModal),
        ];

        $this->line('');
        $this->info('╔══════════════════════════════════════════════════════════════════╗');
        $this->info('║  VALIDASI KEPATUHAN PSAK 101/108 — SIMPANAN WAJIB                ║');
        $this->info('╚══════════════════════════════════════════════════════════════════╝');
        $this->line('  Akun kanonik (titik resolusi) : <fg=cyan>'.$akunKanonik.'</>');
        $this->line('  Akun modal anggota terdaftar  : <fg=cyan>'.(count($kodeModal) ?: '—').'</>');
        $this->line('  Toleransi selisih jurnal      : <fg=cyan>'.$toleransi.'</>');
        $this->line('');

        $totalPelanggaran = 0;
        $baris = [];

        foreach ($kategori as $nama => $hasil) {
            $jumlah = $hasil->count();
            $totalPelanggaran += $jumlah;
            $baris[] = [$nama, $jumlah > 0 ? '<fg=red>'.$jumlah.'</>' : '<fg=green>0</>'];

            if ($jumlah > 0 && $batasContoh > 0) {
                foreach ($hasil->take($batasContoh) as $contoh) {
                    $this->line('   • '.$nama.' → '.$contoh);
                }
            }
        }

        $this->table(['Kategori Pelanggaran', 'Jumlah'], $baris);
        $this->line('');

        $diperiksa = [
            'Total tagihan' => DB::table('mandatory_savings_bills')->count(),
            'Tagihan lunas (PAID)' => DB::table('mandatory_savings_bills')->where('status', MandatorySavingsBill::STATUS_PAID)->count(),
            'Tagihan tunggakan' => DB::table('mandatory_savings_bills')->where('status', MandatorySavingsBill::STATUS_OVERDUE)->count(),
        ];

        foreach ($diperiksa as $label => $nilai) {
            $this->line('  '.str_pad($label, 26, '.').' '.$nilai);
        }
        $this->line('');

        if ($totalPelanggaran === 0) {
            $this->info('✔ LULUS — tidak ditemukan pelanggaran kepatuhan PSAK 101/108.');

            return self::SUCCESS;
        }

        $this->error('✘ GAGAL — ditemukan '.$totalPelanggaran.' pelanggaran kepatuhan.');

        return self::FAILURE;
    }

    /**
     * (5) Nominal tagihan tidak sama dengan jumlah kredit jurnal simpanan.
     * Pelanggaran integritas double-entry: nominal yang diakui ≠ nominal tagihan.
     */
    private function nominalTagihanTidakSesuai(array $kodeWajib): Collection
    {
        if (empty($kodeWajib)) {
            return collect();
        }

        return DB::table('mandatory_savings_bills as b')
            ->join('jurnal_detail as d', 'd.jurnal_id', '=', 'b.jurnal_id')
            ->where('b.status', MandatorySavingsBill::STATUS_PAID)
            ->whereIn('d.coa_id', $kodeWajib)
            ->groupBy('b.id', 'b.member_id', 'b.bill_period', 'b.amount')
            ->havingRaw('ABS(SUM(d.kredit) - b.amount) >= ?', [Setting::toleransiSelisihJurnal()])
            ->select('b.member_id', 'b.bill_period', 'b.amount', DB::raw('SUM(d.kredit) as total_kredit'))
            ->get()
            ->map(fn ($r) => sprintf(
                'Anggota #%s periode %s (tagihan %s ≠ jurnal %s)',
                $r->member_id,
                $r->bill_period,
                number_format((float) $r->amount, 2, ',', '.'),
                number_format((float) $r->total_kredit, 2, ',', '.')
            ));
    }

    /** Akun modal anggota: kode kanonik + seluruh akun kategori Ekuitas. */
    private function kodeAkunModal(): array
    {
        $kode = [\App\Support\Coa::SIMPANAN_WAJIB_MODAL];

        $ekuitas = Coa::where('kategori', 'Ekuitas')->pluck('kode_akun')->all();

        return array_values(array_unique(array_merge($kode, $ekuitas)));
    }

    /**
     * (1) Tagihan lunas yang jurnalnya mengkredit akun berkategori Kewajiban.
     * Pelanggaran PSAK 101 ¶ 31 — Simpanan Wajib harus disajikan sebagai modal.
     */
    private function kreditAkunKewajiban(): Collection
    {
        return DB::table('jurnal_detail as d')
            ->join('jurnal as j', 'j.id', '=', 'd.jurnal_id')
            ->join('coas as c', 'c.id', '=', 'd.coa_id')
            ->join('mandatory_savings_bills as b', 'b.jurnal_id', '=', 'j.id')
            ->where('b.status', MandatorySavingsBill::STATUS_PAID)
            ->where('d.kredit', '>', 0)
            ->where('c.kategori', 'Kewajiban')
            ->whereNull('j.deleted_at')
            ->select('j.no_jurnal', 'c.kode_akun', 'c.nama_akun', 'b.bill_period')
            ->get()
            ->map(fn ($r) => sprintf(
                'Jurnal %s kredit %s %s (periode %s)',
                $r->no_jurnal,
                $r->kode_akun,
                $r->nama_akun,
                $r->bill_period
            ));
    }

    /** (2) Tagihan PAID tanpa jurnal_id valid. */
    private function tagihanLunasTanpaJurnal(): Collection
    {
        return DB::table('mandatory_savings_bills as b')
            ->leftJoin('jurnal as j', 'j.id', '=', 'b.jurnal_id')
            ->where('b.status', MandatorySavingsBill::STATUS_PAID)
            ->where(function ($q) {
                $q->whereNull('b.jurnal_id')->orWhereNull('j.id');
            })
            ->select('b.member_id', 'b.bill_period', 'b.jurnal_id')
            ->get()
            ->map(fn ($r) => sprintf(
                'Anggota #%s periode %s (jurnal_id: %s)',
                $r->member_id,
                $r->bill_period,
                $r->jurnal_id ?? 'null'
            ));
    }

    /** (3) Tagihan PAID tanpa transaksi_id. */
    private function tagihanPaidTanpaTransaksi(): Collection
    {
        return DB::table('mandatory_savings_bills')
            ->where('status', MandatorySavingsBill::STATUS_PAID)
            ->whereNull('transaksi_id')
            ->select('member_id', 'bill_period')
            ->get()
            ->map(fn ($r) => sprintf('Anggota #%s periode %s', $r->member_id, $r->bill_period));
    }

    /** (4) Jurnal yang terhubung ke tagihan Simpanan Wajib namun tidak berimbang. */
    private function jurnalTidakBerimbang(float $toleransi): Collection
    {
        return DB::table('jurnal as j')
            ->join('mandatory_savings_bills as b', 'b.jurnal_id', '=', 'j.id')
            ->whereNull('j.deleted_at')
            ->whereRaw('ABS(j.total_debet - j.total_kredit) >= ?', [$toleransi])
            ->select('j.no_jurnal', 'j.total_debet', 'j.total_kredit')
            ->distinct()
            ->get()
            ->map(fn ($r) => sprintf(
                'Jurnal %s (debet %s ≠ kredit %s)',
                $r->no_jurnal,
                $r->total_debet,
                $r->total_kredit
            ));
    }

    /** (6) Lebih dari satu tagihan untuk (anggota, periode) yang sama. */
    private function tagihanDuplikatPeriode(): Collection
    {
        return DB::table('mandatory_savings_bills')
            ->select('member_id', 'bill_period', DB::raw('COUNT(*) as jumlah'))
            ->groupBy('member_id', 'bill_period')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->map(fn ($r) => sprintf(
                'Anggota #%s periode %s (%d tagihan)',
                $r->member_id,
                $r->bill_period,
                $r->jumlah
            ));
    }

    /** (7) Akun modal Simpanan Wajib bersaldo negatif (indikasi jurnal terbalik). */
    private function saldoModalNegatif(array $kodeModal): Collection
    {
        if (empty($kodeModal)) {
            return collect();
        }

        return DB::table('jurnal_detail as d')
            ->join('jurnal as j', 'j.id', '=', 'd.jurnal_id')
            ->join('coas as c', 'c.id', '=', 'd.coa_id')
            ->whereNull('j.deleted_at')
            ->whereIn('d.coa_id', $kodeModal)
            ->groupBy('c.kode_akun', 'c.nama_akun')
            ->havingRaw('SUM(d.kredit - d.debet) < 0')
            ->select('c.kode_akun', 'c.nama_akun', DB::raw('SUM(d.kredit - d.debet) as saldo'))
            ->get()
            ->map(fn ($r) => sprintf(
                '%s %s bersaldo %s',
                $r->kode_akun,
                $r->nama_akun,
                number_format((float) $r->saldo, 2, ',', '.')
            ));
    }
}
