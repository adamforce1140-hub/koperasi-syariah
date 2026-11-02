<?php

namespace App\Services;

use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Support\Setting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Layanan akun 3-9000 (Ikhtisar SHU / Laba Ditahan).
 *
 * Akun 3-9000 adalah akun penampung laba/rugi berjalan sebelum dipindahkan ke
 * pos ekuitas permanen. Saldo normalnya KREDIT, sehingga saldo negatif berarti
 * ada mutasi DEBET yang belum pernah ditutup oleh jurnal penutup.
 *
 * Dua operasi yang ditangani:
 *   1. tutupBuku()  — memindahkan saldo akun Pendapatan (Kepala 4) & Beban
 *                     (Kepala 5) ke 3-9000 secara PRESISI (menolkan kedua
 *                     kelompok akun lalu memindahkan selisihnya).
 *   2. bersihkan()  — membuat jurnal penyesuaian agar saldo 3-9000 kembali Rp 0
 *                     bila terdapat pencatatan gantung (mutasi debet tanpa
 *                     jurnal penutup lawan), mis. sisa distribusi SHU.
 *
 * Aturan yang dijaga kedua operasi:
 *   - Jurnal selalu BALANCE (debet = kredit) — divalidasi sebelum disimpan.
 *   - IDEMPOTEN: operasi kedua menghasilkan nol baris bila sudah tidak ada
 *     mutasi yang perlu dipindahkan/dibersihkan (tidak menggandakan angka).
 *   - Batas tanggal ($sampai) HARUS sama dengan tanggal jurnal yang dibuat,
 *     supaya idempotensi tetap berlaku saat tanggal sistem berbeda.
 *   - Tidak ada angka yang dibuat-buat: bila jurnal tidak balance, transaksi
 *     dibatalkan seluruhnya (rollback) dan RuntimeException dilempar.
 */
class IkhtisarShuService
{
    /** Kode akun Ikhtisar SHU / Laba Ditahan. */
    public const COA_IKHTISAR_SHU = \App\Support\Coa::IKHTISAR_SHU;

    /**
     * Toleransi pembulatan rupiah — dibaca dari parameter kebijakan koperasi
     * (Setting::toleransiSelisihJurnal(), default 1 sen) agar tidak ada
     * duplikasi ambang toleransi di kode.
     */
    private static function toleransi(): float
    {
        return Setting::toleransiSelisihJurnal();
    }

    /* =====================================================================
     | 1. TUTUP BUKU — Pendapatan & Beban → 3-9000
     ===================================================================== */

    /**
     * Pratinjau Tutup Buku tanpa mengubah data.
     *
     * @return array{
     *     tanggal:string, saldo_awal_ikhtisar:float,
     *     baris:array<int, array{coa_id:int, kode:string, nama:string, kategori:string, posisi:string, debet:float, kredit:float}>,
     *     total_debet:float, total_kredit:float, selisih_shu:float,
     *     saldo_akhir_ikhtisar:float, status:string, sudah_ditutup:bool
     * }
     */
    public function pratinjauTutupBuku(?string $sampai = null): array
    {
        $sampai = $this->tanggalBatas($sampai);
        $akunIkhtisar = $this->akunIkhtisar();

        $baris = [];
        $totalDebet = 0.0;
        $totalKredit = 0.0;

        foreach ($this->akunResetable() as $coa) {
            $saldo = $this->saldoPenutup($coa, $sampai);

            if (abs($saldo) < self::toleransi()) {
                continue;
            }

            // Akun yang berposisi KREDIT (Pendapatan) ditutup dengan DEBET,
            // dan sebaliknya — sehingga saldonya menjadi nol.
            $debet = $coa->posisiSaldo() === 'Kredit' ? Setting::bulatkanUang($saldo) : 0.0;
            $kredit = $coa->posisiSaldo() === 'Debet' ? Setting::bulatkanUang($saldo) : 0.0;

            $baris[] = [
                'coa_id' => $coa->id,
                'kode' => $coa->kode_akun,
                'nama' => $coa->nama_akun,
                'kategori' => $coa->kategori,
                'posisi' => $coa->posisiSaldo(),
                'debet' => $debet,
                'kredit' => $kredit,
            ];

            $totalDebet += $debet;
            $totalKredit += $kredit;
        }

        // Selisih Pendapatan − Beban = SHU. Karena Pendapatan ditutup di sisi
        // DEBET dan Beban di sisi KREDIT, arah jurnal ke 3-9000 adalah:
        //   laba  (totalDebet > totalKredit) → KREDIT 3-9000 (menambah ekuitas)
        //   rugi  (totalKredit > totalDebet) → DEBET  3-9000 (mengurangi ekuitas)
        $selisihSHU = Setting::bulatkanUang($totalDebet - $totalKredit);
        $saldoAwalIkhtisar = $this->saldoIkhtisar($sampai);

        return [
            'tanggal' => $sampai,
            'saldo_awal_ikhtisar' => $saldoAwalIkhtisar,
            'baris' => $baris,
            'total_debet' => Setting::bulatkanUang($totalDebet),
            'total_kredit' => Setting::bulatkanUang($totalKredit),
            'selisih_shu' => $selisihSHU,
            'saldo_akhir_ikhtisar' => Setting::bulatkanUang($saldoAwalIkhtisar + $selisihSHU),
            'status' => $this->statusDari($selisihSHU),
            'sudah_ditutup' => empty($baris),
        ];
    }

    /**
     * Eksekusi Tutup Buku: jurnal penutup Pendapatan & Beban → 3-9000.
     *
     * @return array{tanggal:string, jumlah_baris:int, selisih_shu:float,
     *               status:string, jurnal:?Jurnal, sudah_ditutup:bool,
     *               saldo_akhir_ikhtisar:float}
     */
    public function tutupBuku(?string $sampai = null, ?int $userId = null): array
    {
        $pratinjau = $this->pratinjauTutupBuku($sampai);

        if ($pratinjau['sudah_ditutup']) {
            return [
                'tanggal' => $pratinjau['tanggal'],
                'jumlah_baris' => 0,
                'selisih_shu' => 0.0,
                'status' => 'impas',
                'jurnal' => null,
                'sudah_ditutup' => true,
                'saldo_akhir_ikhtisar' => $pratinjau['saldo_awal_ikhtisar'],
            ];
        }

        $this->pastikanAdaAkunIkhtisar();

        $sampai = $pratinjau['tanggal'];
        $selisih = $pratinjau['selisih_shu'];
        $totalDebet = $pratinjau['total_debet'];
        $totalKredit = $pratinjau['total_kredit'];

        // Baris penyeimbang ke 3-9000. Selisih nol (impas) tidak membuat baris.
        $debetIkhtisar = $selisih < 0 ? Setting::bulatkanUang(abs($selisih)) : 0.0;
        $kreditIkhtisar = $selisih > 0 ? Setting::bulatkanUang($selisih) : 0.0;
        $totalDebet = Setting::bulatkanUang($totalDebet + $debetIkhtisar);
        $totalKredit = Setting::bulatkanUang($totalKredit + $kreditIkhtisar);

        return DB::transaction(function () use (
            $pratinjau, $sampai, $selisih, $totalDebet, $totalKredit,
            $debetIkhtisar, $kreditIkhtisar, $userId
        ) {
            $kodeJurnal = 'TUTUPBUKU-'.str_replace('-', '', $sampai)
                .'-'.str_pad((string) $this->urutanJurnal($sampai), 4, '0', STR_PAD_LEFT);

            $jurnal = Jurnal::create([
                'no_jurnal' => $kodeJurnal,
                'tanggal_jurnal' => $sampai,
                'jenis_jurnal' => 'Jurnal Penutup',
                'sumber' => 'TutupBuku',
                'no_ref' => 'TUTUP-BUKU-'.substr($sampai, 0, 4),
                'keterangan' => 'Jurnal Penutup: pemindahan Pendapatan & Beban ke Ikhtisar SHU per '.$sampai,
                'total_debet' => $totalDebet,
                'total_kredit' => $totalKredit,
                'is_balance' => true,
                'status' => 'Posted',
                'posted_at' => now(),
                'user_posted' => $userId ?? Auth::id(),
                'user_id' => $userId ?? Auth::id(),
            ]);

            $baris = 1;

            foreach ($pratinjau['baris'] as $row) {
                JurnalDetail::create([
                    'jurnal_id' => $jurnal->id,
                    'coa_id' => $row['coa_id'],
                    'baris' => $baris++,
                    'debet' => $row['debet'],
                    'kredit' => $row['kredit'],
                    'keterangan' => 'Penutupan akun '.$row['kode'].' '.$row['nama'],
                ]);
            }

            if ($debetIkhtisar > 0 || $kreditIkhtisar > 0) {
                JurnalDetail::create([
                    'jurnal_id' => $jurnal->id,
                    'coa_id' => $this->akunIkhtisar()->id,
                    'baris' => $baris++,
                    'debet' => $debetIkhtisar,
                    'kredit' => $kreditIkhtisar,
                    'keterangan' => $selisih >= 0
                        ? 'SHU tahun buku '.substr($sampai, 0, 4).' (laba)'
                        : 'Rugi tahun buku '.substr($sampai, 0, 4),
                ]);
            }

            $jurnal->hitungBalance();
            $this->pastikanBalance($jurnal, $totalDebet, $totalKredit, 'Tutup Buku');
            // Tutup Buku mengubah status jurnal menjadi Posted — buktikan header
            // benar-benar didukung baris detail yang seimbang sebelum diposting.
            $jurnal->pastikanBalance();
            $jurnal->save();

            /* Nol-kan saldo_awal akun Pendapatan & Beban tahun buku yang ditutup.
             | Setelah jurnal penutup, mutasinya sudah nol; saldo_awal yang
             | tertinggal dari tahun buku sebelumnya juga harus di-nol-kan agar
             | tidak terhitung dua kali pada tahun buku berikutnya. */
            $terdampak = collect($pratinjau['baris'])->pluck('coa_id')->all();
            if (! empty($terdampak)) {
                Coa::whereIn('id', $terdampak)->update(['saldo_awal' => 0]);
            }

            Log::info('IkhtisarShuService: jurnal penutup ['.$jurnal->no_jurnal.'] dibuat — SHU '.$selisih);

            return [
                'tanggal' => $sampai,
                'jumlah_baris' => $baris - 1,
                'selisih_shu' => $selisih,
                'status' => $this->statusDari($selisih),
                'jurnal' => $jurnal,
                'sudah_ditutup' => false,
                'saldo_akhir_ikhtisar' => $pratinjau['saldo_akhir_ikhtisar'],
            ];
        });
    }

    /* =====================================================================
     | 2. PEMBERSIHAN PENCATATAN GANTUNG PADA 3-9000
     ===================================================================== */

    /**
     * Pratinjau pembersihan akun 3-9000. Tidak mengubah data apa pun.
     */
    public function pratinjauBersihkan(?string $sampai = null): array
    {
        $sampai = $this->tanggalBatas($sampai);
        $akunIkhtisar = $this->akunIkhtisar();

        if (! $akunIkhtisar) {
            return [
                'tanggal' => $sampai,
                'saldo_awal' => 0.0,
                'total_debet' => 0.0,
                'total_kredit' => 0.0,
                'saldo' => 0.0,
                'perlu_dibersihkan' => false,
                'arah' => 'bersih',
                'keterangan' => 'Akun 3-9000 belum ada di bagan akun.',
                'rincian' => collect(),
            ];
        }

        $saldo = $this->saldoPenutup($akunIkhtisar, $sampai);

        // Hanya saldo NEGATIF yang dibersihkan: pada akun berposisi Kredit,
        // saldo negatif berarti ada mutasi DEBET tanpa jurnal penutup lawan.
        // Saldo positif adalah laba berjalan yang sah dan harus dibiarkan agar
        // dapat dipindahkan oleh proses Tutup Buku.
        $perlu = $saldo < -self::toleransi();

        return [
            'tanggal' => $sampai,
            'saldo_awal' => (float) $akunIkhtisar->saldo_awal,
            'total_debet' => $akunIkhtisar->totalDebet($sampai),
            'total_kredit' => $akunIkhtisar->totalKredit($sampai),
            'saldo' => Setting::bulatkanUang($saldo),
            'perlu_dibersihkan' => $perlu,
            'arah' => $perlu ? 'debet' : 'bersih',
            'keterangan' => $perlu
                ? 'Saldo 3-9000 negatif Rp '.number_format(abs($saldo), 2, ',', '.')
                    .' — pencatatan gantung tanpa jurnal penutup lawan.'
                : 'Saldo 3-9000 sudah tidak minus (Rp '.number_format($saldo, 2, ',', '.').').',
            'rincian' => $this->rincianMutasi($akunIkhtisar, $sampai),
        ];
    }

    /**
     * Eksekusi pembersihan: jurnal penyesuaian yang meng-KREDIT 3-9000 sebesar
     * saldo minusnya, dengan lawan akun 3-2001 Dana Cadangan.
     *
     * Pemilihan akun lawan:
     *   - Bila minus 3-9000 berasal dari distribusi SHU, lawannya berada di
     *     sisi Kredit (Dana Cadangan / Simpanan Sukarela / Dana Pengurus).
     *     Meng-KREDIT 3-9000 bersaldo DEBET tidak akan menyeimbangkan posisi
     *     akun-akun tersebut, sehingga dana tetap harus dipindahkan ke pos
     *     ekuitas permanen 3-2001 Dana Cadangan — bukan dikembalikan ke akun
     *     kewajiban Dana Syirkah Temporer.
     *   - Distribusi ke anggota (Simpanan Sukarela) tidak dibatalkan karena
     *     dananya sudah nyata diterima anggota; perbaikannya cukup di sisi SHU.
     */
    public function bersihkan(?string $sampai = null, ?int $userId = null): array
    {
        $pratinjau = $this->pratinjauBersihkan($sampai);

        if (! $pratinjau['perlu_dibersihkan']) {
            return [
                'tanggal' => $pratinjau['tanggal'],
                'jumlah' => 0.0,
                'jurnal' => null,
                'keterangan' => $pratinjau['keterangan'],
            ];
        }

        $this->pastikanAdaAkunIkhtisar();

        $akunIkhtisar = $this->akunIkhtisar();
        $akunCadangan = Coa::where('kode_akun', self::COA_DANA_CADANGAN)->first();

        if (! $akunCadangan) {
            throw new RuntimeException(
                'Akun '.self::COA_DANA_CADANGAN.' Dana Cadangan tidak ditemukan — '
                .'jurnal penyesuaian tidak dapat dibuat tanpa akun lawan.'
            );
        }

        if (substr((string) $akunCadangan->kode_akun, 0, 1) !== '3') {
            throw new RuntimeException('Akun lawan '.$akunCadangan->kode_akun.' bukan akun Ekuitas (Kepala 3).');
        }

        $sampai = $pratinjau['tanggal'];
        $nominal = Setting::bulatkanUang(abs($pratinjau['saldo']));

        return DB::transaction(function () use (
            $sampai, $nominal, $pratinjau, $akunIkhtisar, $akunCadangan, $userId
        ) {
            $kodeJurnal = 'PENYESUAIAN-'.str_replace('-', '', $sampai)
                .'-'.str_pad((string) $this->urutanJurnal($sampai), 4, '0', STR_PAD_LEFT);

            $jurnal = Jurnal::create([
                'no_jurnal' => $kodeJurnal,
                'tanggal_jurnal' => $sampai,
                'jenis_jurnal' => 'Jurnal Penyesuaian',
                'sumber' => 'PenyesuaianIkhtisarSHU',
                'no_ref' => 'RESET-3-9000',
                'keterangan' => 'Penyesuaian Ikhtisar SHU: pembersihan saldo minus '
                    .'Rp '.number_format($nominal, 2, ',', '.')
                    .' ke Dana Cadangan sebelum Tutup Buku',
                'total_debet' => $nominal,
                'total_kredit' => $nominal,
                'is_balance' => true,
                'status' => 'Posted',
                'posted_at' => now(),
                'user_posted' => $userId ?? Auth::id(),
                'user_id' => $userId ?? Auth::id(),
            ]);

            JurnalDetail::create([
                'jurnal_id' => $jurnal->id,
                'coa_id' => $akunCadangan->id,
                'baris' => 1,
                'debet' => $nominal,
                'kredit' => 0,
                'keterangan' => 'Penyesuaian saldo minus Ikhtisar SHU ke Dana Cadangan',
            ]);

            JurnalDetail::create([
                'jurnal_id' => $jurnal->id,
                'coa_id' => $akunIkhtisar->id,
                'baris' => 2,
                'debet' => 0,
                'kredit' => $nominal,
                'keterangan' => 'Menolkan saldo minus Ikhtisar SHU (pencatatan gantung)',
            ]);

            $jurnal->hitungBalance();
            $this->pastikanBalance($jurnal, $nominal, $nominal, 'Penyesuaian Ikhtisar SHU');
            // Penyesuaian juga mem-Post jurnal — header wajib lolos bukti balance.
            $jurnal->pastikanBalance();
            $jurnal->save();

            Log::warning('IkhtisarShuService: saldo minus 3-9000 dibersihkan Rp '.$nominal
                .' melalui jurnal ['.$jurnal->no_jurnal.']');

            return [
                'tanggal' => $sampai,
                'jumlah' => $nominal,
                'jurnal' => $jurnal,
                'keterangan' => 'Jurnal penyesuaian dibuat: Debet '.$akunCadangan->kode_akun
                    .' / Kredit '.self::COA_IKHTISAR_SHU.' sebesar Rp '
                    .number_format($nominal, 2, ',', '.'),
                'pratinjau' => $pratinjau,
            ];
        });
    }

    /* =====================================================================
     | PEMBANTU
     ===================================================================== */

    /** Kode akun Dana Cadangan — akun lawan bawaan untuk penyesuaian. */
    public const COA_DANA_CADANGAN = \App\Support\Coa::CADANGAN_KOPERASI;

    /** Akun 3-9000; null bila belum ada. */
    public function akunIkhtisar(): ?Coa
    {
        return Coa::where('kode_akun', self::COA_IKHTISAR_SHU)->first();
    }

    /** Saldo 3-9000 per tanggal (bisa negatif). */
    public function saldoIkhtisar(?string $sampai = null): float
    {
        $akun = $this->akunIkhtisar();

        return $akun ? $this->saldoPenutup($akun, $this->tanggalBatas($sampai)) : 0.0;
    }

    /**
     * Batas tanggal operasi.
     *
     * Bila tidak diminta, batas diambil dari tanggal jurnal terakhir yang
     * sudah tercatat — BUKAN tanggal sistem. Jurnal penutup/penyesuaian lalu
     * diberi tanggal yang sama dengan batas ini, sehingga:
     *   - seluruh jurnal yang sudah ada ikut terhitung (saldo presisi), dan
     *   - menjalankan operasi dua kali bersifat idempoten walaupun tanggal
     *     sistem berbeda dengan tanggal jurnal terakhir.
     */
    public function tanggalBatas(?string $sampai = null): string
    {
        if ($sampai !== null && $sampai !== '') {
            return $sampai;
        }

        $terakhir = Jurnal::query()->max('tanggal_jurnal');

        return $terakhir ? substr((string) $terakhir, 0, 10) : now()->toDateString();
    }

    /** Saldo akun sesuai posisinya, termasuk saldo_awal. */
    private function saldoPenutup(Coa $coa, string $sampai): float
    {
        $debet = $coa->totalDebet($sampai);
        $kredit = $coa->totalKredit($sampai);

        $mutasi = $coa->posisiSaldo() === 'Debet' ? $debet - $kredit : $kredit - $debet;

        return (float) $coa->saldo_awal + $mutasi;
    }

    /** Akun Pendapatan & Beban yang masih aktif. */
    private function akunResetable()
    {
        return Coa::resetable()->where('is_active', true)->orderBy('kode_akun')->get();
    }

    /** Rincian mutasi 3-9000 agar penyebab saldo minus dapat ditelusuri. */
    private function rincianMutasi(Coa $coa, string $sampai)
    {
        return $coa->jurnalDetail()
            ->with('jurnal:id,no_jurnal,tanggal_jurnal,sumber,keterangan')
            ->whereHas('jurnal', function ($q) use ($sampai) {
                $q->where('status', 'Posted')->whereDate('tanggal_jurnal', '<=', $sampai);
            })
            ->orderBy('baris')
            ->get()
            ->map(fn ($d) => [
                'no_jurnal' => $d->jurnal?->no_jurnal,
                'tanggal' => optional($d->jurnal?->tanggal_jurnal)->format('Y-m-d'),
                'sumber' => $d->jurnal?->sumber,
                'keterangan' => $d->keterangan ?: $d->jurnal?->keterangan,
                'debet' => (float) $d->debet,
                'kredit' => (float) $d->kredit,
            ]);
    }

    /** Nomor urut jurnal berikutnya untuk tanggal tertentu. */
    private function urutanJurnal(string $tanggal): int
    {
        // R-04: berbasis MAX sufiks numerik (bukan count()+1) agar tidak
        // bertabrakan saat dua jurnal dibuat bersamaan pada tanggal yang sama.
        // `no_jurnal` ber-unique index sehingga tabrakan akan menggagalkan
        // transaksi. Semua jurnal pada satu tanggal memakai sufiks numerik yang
        // sama-sama berasal dari satu deret (JRN-, TUTUPBUKU-, PENYESUAIAN-),
        // jadi MAX sufiks = urutan berikutnya.
        $maks = Jurnal::withTrashed()
            ->whereDate('tanggal_jurnal', $tanggal)
            ->selectRaw("MAX(CAST(SUBSTRING_INDEX(no_jurnal, '-', -1) AS UNSIGNED)) AS maks_urut")
            ->value('maks_urut');

        return ((int) $maks) + 1;
    }

    private function statusDari(float $selisih): string
    {
        if (abs($selisih) < self::toleransi()) {
            return 'impas';
        }

        return $selisih > 0 ? 'untung' : 'rugi';
    }

    private function pastikanAdaAkunIkhtisar(): void
    {
        if (! $this->akunIkhtisar()) {
            throw new RuntimeException(
                'Akun '.self::COA_IKHTISAR_SHU.' (Ikhtisar SHU / Laba Ditahan) belum ada di bagan akun.'
            );
        }
    }

    /**
     * Pengaman terakhir: jurnal yang tidak balance TIDAK boleh tersimpan.
     * Diperiksa dari baris detail yang benar-benar ditulis, bukan dari angka
     * yang dihitung di memori.
     */
    private function pastikanBalance(Jurnal $jurnal, float $debet, float $kredit, string $konteks): void
    {
        $detailDebet = (float) $jurnal->detail()->sum('debet');
        $detailKredit = (float) $jurnal->detail()->sum('kredit');

        if (abs($detailDebet - $detailKredit) >= self::toleransi()) {
            throw new RuntimeException(
                $konteks.': jurnal tidak balance — debet Rp '.number_format($detailDebet, 2, ',', '.')
                .' vs kredit Rp '.number_format($detailKredit, 2, ',', '.')
                .'. Seluruh jurnal dibatalkan.'
            );
        }

        if (abs($debet - $kredit) >= self::toleransi()) {
            throw new RuntimeException($konteks.': total debet dan kredit header jurnal tidak sama.');
        }
    }
}
