<?php

namespace Database\Seeders;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\Coa;
use App\Models\JenisSimpanan;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\KebijakanKoperasi;
use App\Models\MandatorySavingsBill;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Studi kasus end-to-end modul Simpanan — anggota dummy "Adam Renata".
 *
 * Mengikuti persis Bagian 7 spesifikasi modul Simpanan:
 *  1. Anggota `ANG-2026-0017` (CIF sama), `tanggal_gabung` = 2026-01-31,
 *     status awal PENDING, `is_auto_debit` = true.
 *  2. Simulasi pendaftaran: rekening Pokok/Wajib dibuka + tagihan BUNDLING
 *     setoran awal diterbitkan (dari `cooperative_settings` secara dinamis).
 *  3. Simulasi pelunasan setoran awal oleh TELLER → status ACTIVE + jurnal
 *     PSAK 101: DEBET 1-1001 | KREDIT 3-1001 + 3-1002.
 *  4. Top-up Simpanan Sukarela (2-1001) sebagai sumber dana auto-debit.
 *  5. Generasi riwayat tagihan Simpanan Wajib Januari–September 2026, seluruhnya
 *     PAID via AUTO_DEBIT. Februari 2026 diproses 28 Februari 2026 sebagai
 *     penyesuaian end-of-month (anggota bergabung 31 Januari 2026).
 *
 * Seeder bersifat idempoten: seluruh operasi memakai `firstOrCreate`, sehingga
 * `db:seed` dapat diulang tanpa menggandakan tagihan, jurnal, atau saldo.
 */
class AdamRenataSeeder extends Seeder
{
    /** CoA Kas Teller / Bank — lawan setoran Tunai (PSAK 101). */
    private const COA_KAS = '1-1001';

    /** CoA Simpanan Sukarela — sumber dana auto-debit Simpanan Wajib. */
    private const COA_SUKARELA = '2-1001';

    /** CoA Simpanan Pokok — Modal Anggota. */
    private const COA_POKOK = '3-1001';

    /** CoA Simpanan Wajib — Modal Anggota. */
    private const COA_WAJIB = '3-1002';

    private ?User $teller = null;

    public function run(): void
    {
        $anggota = Anggota::where('no_anggota', 'ANG-2026-0017')->first();

        if (! $anggota) {
            $this->command?->warn('AdamRenataSeeder: anggota ANG-2026-0017 tidak ditemukan — jalankan AnggotaSeeder lebih dahulu.');

            return;
        }

        $this->teller = User::where('role', 'teller')->first()
            ?? User::where('role', 'admin')->first();

        // Nominal SELALU dibaca dari parameter koperasi (tanpa hardcode).
        $pokok = KebijakanKoperasi::simpananPokokWajib();
        $wajib = KebijakanKoperasi::simpananWajibBulanan();
        $jumlah = round($pokok + $wajib, 2);

        // Simpanan Pokok & Wajib bulan ke-1 memakai total setoran awal.
        // Presisi 2 desimal (bukan pembulatan) agar nilai jurnal cocok dengan
        // `jurnalDetailSudahAda()` dan seeder tetap idempoten.
        $anggota->update([
            'simpanan_pokok' => $pokok,
            'simpanan_wajib' => $wajib,
        ]);

        $rekeningSukarela = $this->pastikanRekeningSukarela($anggota);

        // Rekening Pokok & Wajib dibuka seperti alur produksi
        // (CifController::bukaRekening) agar subledger simpanan punya wadah
        // yang sepadan dengan jurnal GL 3-1001 / 3-1002.
        $rekeningSimpanan = $this->pastikanRekeningSimpanan($anggota, $pokok, $wajib);

        $this->simulasikanPelunasanSetoranAwal($anggota, $pokok, $wajib, $jumlah, $rekeningSimpanan);
        $this->pastikanSaldoSukarela($anggota, $rekeningSukarela);
        $this->generateRiwayatSimpananWajib($anggota);

        // Konvergensi subledger: samakan saldo rekening Pokok/Wajib dengan
        // saldo buku besar per anggota. Seeder jadi idempoten sekaligus
        // menyembuhkan data lama yang rekeningnya belum pernah dibuka.
        $this->selaraskanSaldoRekeningSimpanan($anggota, $rekeningSimpanan);

        $this->laporkan($anggota, $rekeningSukarela, $jumlah);
    }

    /* =====================================================================
     | 1. REKENING SIMPANAN SUKARELA (sumber dana auto-debit)
     |===================================================================== */

    private function pastikanRekeningSukarela(Anggota $anggota): Rekening
    {
        $rekening = Rekening::firstOrCreate(
            [
                'anggota_id' => $anggota->id,
                'jenis_rekening' => 'Simpanan Sukarela',
            ],
            [
                'no_rekening' => 'SUK-'.str_pad((string) $anggota->id, 6, '0', STR_PAD_LEFT),
                'akun_id' => $this->akunId(self::COA_SUKARELA),
                'akad' => 'Mudharabah',
                'saldo' => 0,
                'batas_minimum' => 0,
                'nisbah_bank' => 25,
                'nisbah_nasabah' => 75,
                'tanggal_buka' => $anggota->tanggal_gabung?->toDateString() ?? '2026-01-31',
                'status_rekening' => 'Aktif',
                'keterangan' => 'Simpanan Sukarela — sumber dana auto-debit Simpanan Wajib',
            ]
        );

        // Konvergensi: rekening Sukarela dibuka bersamaan dengan pendaftaran,
        // sehingga tanggal bukanya harus mengikuti tanggal gabung anggota.
        if ($rekening->tanggal_buka?->toDateString() !== $anggota->tanggal_gabung?->toDateString()) {
            $rekening->update(['tanggal_buka' => $anggota->tanggal_gabung?->toDateString()]);
        }

        return $rekening;
    }

    /**
     * Pastikan rekening Simpanan Pokok & Simpanan Wajib tersedia.
     *
     * Meniru `CifController::bukaRekening()`: kedua rekening dibuka bersamaan
     * saat pendaftaran, masing-masing dengan saldo sebesar porsi komponennya
     * (Pokok = simpanan pokok, Wajib = simpanan wajib bulan ke-1).
     *
     * @return array{pokok: Rekening, wajib: Rekening}
     */
    private function pastikanRekeningSimpanan(Anggota $anggota, float $pokok, float $wajib): array
    {
        $tanggalBuka = $anggota->tanggal_gabung?->toDateString() ?? '2026-01-31';

        $rekeningPokok = Rekening::firstOrCreate(
            [
                'anggota_id' => $anggota->id,
                'jenis_rekening' => JenisSimpanan::namaUtama(JenisSimpanan::TIPE_POKOK, 'Simpanan Pokok'),
            ],
            [
                'no_rekening' => 'POK-'.str_pad((string) $anggota->id, 6, '0', STR_PAD_LEFT),
                'akun_id' => $this->akunId(self::COA_POKOK),
                'akad' => 'Wadiah',
                'saldo' => $pokok,
                'batas_minimum' => 0,
                'tanggal_buka' => $tanggalBuka,
                'status_rekening' => 'Aktif',
                'keterangan' => 'Rekening Simpanan Pokok — dibuka saat pendaftaran anggota',
            ]
        );

        $rekeningWajib = Rekening::firstOrCreate(
            [
                'anggota_id' => $anggota->id,
                'jenis_rekening' => JenisSimpanan::namaUtama(JenisSimpanan::TIPE_WAJIB, 'Simpanan Wajib'),
            ],
            [
                'no_rekening' => 'WAJ-'.str_pad((string) $anggota->id, 6, '0', STR_PAD_LEFT),
                'akun_id' => $this->akunId(self::COA_WAJIB),
                'akad' => 'Wadiah',
                'saldo' => $wajib,
                'batas_minimum' => 0,
                'tanggal_buka' => $tanggalBuka,
                'status_rekening' => 'Aktif',
                'keterangan' => 'Rekening Simpanan Wajib — dibuka saat pendaftaran anggota',
            ]
        );

        return ['pokok' => $rekeningPokok, 'wajib' => $rekeningWajib];
    }

    /* =====================================================================
     | 2. SETORAN AWAL (BUNDLING) — TAGIHAN + PELUNASAN OLEH TELLER
     |===================================================================== */

    /**
     * Simulasi pelunasan setoran awal oleh Teller.
     *
     * Urutan sesuai alur nyata pada CifController::bukaRekening():
     *   PENDING → tagihan setoran awal diterbitkan → Teller konfirmasi bayar
     *   → tagihan PAID → Auto-Journal PSAK 101 → status anggota ACTIVE.
     */
    private function simulasikanPelunasanSetoranAwal(
        Anggota $anggota,
        float $pokok,
        float $wajib,
        float $jumlah,
        array $rekeningSimpanan
    ): void {
        $periode = MandatorySavingsBill::periodDari($anggota->tanggal_gabung);

        // Tagihan setoran awal = bundling Simpanan Pokok + Simpanan Wajib bulan ke-1.
        // PSAK 101: kolom `amount` pada tagihan berjadwal HANYA mewakili porsi
        // Simpanan Wajib (modal anggota), bukan total bundling — harus sama
        // dengan nilai yang dikredit ke akun 3-1002 pada jurnalBundling().
        // Jalur produksi CifController juga memakai porsi wajib saja.
        $bill = MandatorySavingsBill::firstOrCreate(
            [
                'member_id' => $anggota->id,
                'bill_period' => $periode,
            ],
            [
                'amount' => $wajib,
                'status' => MandatorySavingsBill::STATUS_UNPAID,
            ]
        );

        // Sudah dilunasi pada eksekusi seeder sebelumnya → jaga idempotensi.
        if ($bill->isPaid() && $anggota->isActive()) {
            return;
        }

        DB::transaction(function () use ($anggota, $bill, $pokok, $wajib, $jumlah, $rekeningSimpanan): void {
            $tanggalBayar = $anggota->tanggal_gabung->copy()->setTime(9, 0);

            $transaksi = Transaksi::firstOrCreate(
                ['no_transaksi' => 'TRX-SETORAN-AWAL-'.$anggota->id],
                [
                    'waktu_transaksi' => $tanggalBayar,
                    'rekening_id' => null,
                    'anggota_id' => $anggota->id,
                    'jenis_transaksi' => 'Setoran Awal Anggota',
                    'posisi' => 'Debet',
                    'jumlah' => $jumlah,
                    'saldo_setelah' => $jumlah,
                    'user_id' => $this->teller?->id,
                    'sumber_transaksi' => 'Teller',
                    'status_transaksi' => 'Sukses',
                    'status' => 'Sukses',
                    'catatan' => 'Setoran awal (bundling) Simpanan Pokok + Simpanan Wajib bulan ke-1 — pelunasan oleh Teller',
                ]
            );

            $jurnal = $this->jurnalBundling(
                $anggota,
                $pokok,
                $wajib,
                $jumlah,
                $transaksi->id,
                $tanggalBayar,
                $rekeningSimpanan['pokok'],
                $rekeningSimpanan['wajib']
            );

            $bill->update([
                'amount' => $wajib,
                'status' => MandatorySavingsBill::STATUS_PAID,
                'paid_at' => $tanggalBayar,
                'payment_method' => MandatorySavingsBill::METODE_CASH,
                'transaksi_id' => $transaksi->id,
                'jurnal_id' => $jurnal?->id,
            ]);

            // Aktivasi anggota HANYA setelah setoran awal lunas.
            $anggota->update(['status_anggota' => Anggota::STATUS_ACTIVE]);
        });
    }

    /**
     * Auto-Journal setoran awal (PSAK 101/108):
     *   DEBET  1-1001 Kas Teller / Bank   = total setoran awal
     *   KREDIT 3-1001 Simpanan Pokok      = nominal Simpanan Pokok
     *   KREDIT 3-1002 Simpanan Wajib      = nominal Simpanan Wajib bulan ke-1
     *
     * Guard double posting: bila jurnal setoran awal anggota ini sudah ada,
     * jurnal lama dikembalikan (tidak membuat jurnal kedua).
     */
    private function jurnalBundling(
        Anggota $anggota,
        float $pokok,
        float $wajib,
        float $jumlah,
        int $transaksiId,
        Carbon $tanggal,
        Rekening $rekeningPokok,
        Rekening $rekeningWajib
    ): ?Jurnal {
        $sumber = 'Setoran Awal Anggota (Bundling)';
        $keterangan = 'Setoran awal (bundling) pendaftaran anggota '.$anggota->nama_lengkap;

        $tersimpan = Jurnal::where('sumber', $sumber)
            ->whereHas('detail', fn ($query) => $query->where('anggota_id', $anggota->id))
            ->latest('id')
            ->first();

        if ($tersimpan) {
            return $tersimpan;
        }

        $kas = Coa::findByKode(self::COA_KAS);
        $coaPokok = Coa::findByKode(self::COA_POKOK);
        $coaWajib = Coa::findByKode(self::COA_WAJIB);

        if (! $kas || ! $coaPokok || ! $coaWajib) {
            Log::warning('AdamRenataSeeder: CoA setoran awal tidak lengkap — jurnal bundling dilewati.', [
                'coa_kas' => (bool) $kas,
                'coa_pokok' => (bool) $coaPokok,
                'coa_wajib' => (bool) $coaWajib,
            ]);

            return null;
        }

        return DB::transaction(function () use ($anggota, $kas, $coaPokok, $coaWajib, $pokok, $wajib, $jumlah, $transaksiId, $keterangan, $sumber, $tanggal, $rekeningPokok, $rekeningWajib): Jurnal {
            $jurnal = Jurnal::create([
                'no_jurnal' => $this->generateNoJurnal(),
                'tanggal_jurnal' => $tanggal->toDateString(),
                'jenis_jurnal' => 'Jurnal Otomatis',
                'transaksi_id' => $transaksiId,
                'sumber' => $sumber,
                'keterangan' => $keterangan,
                'total_debet' => $jumlah,
                'total_kredit' => $jumlah,
                'is_balance' => true,
                'status' => 'Posted',
                'posted_at' => $tanggal,
                'user_posted' => $this->teller?->id,
                'user_id' => $this->teller?->id,
            ]);

            foreach ([
                ['coa' => $kas,      'debet' => $jumlah, 'kredit' => 0.0,    'keterangan' => $kas->nama_akun,      'rekening_id' => null],
                ['coa' => $coaPokok, 'debet' => 0.0,     'kredit' => $pokok,  'keterangan' => $coaPokok->nama_akun, 'rekening_id' => $rekeningPokok->id],
                ['coa' => $coaWajib, 'debet' => 0.0,     'kredit' => $wajib,  'keterangan' => $coaWajib->nama_akun, 'rekening_id' => $rekeningWajib->id],
            ] as $index => $baris) {
                JurnalDetail::create([
                    'jurnal_id' => $jurnal->id,
                    'coa_id' => $baris['coa']->id,
                    'akun_id' => null,
                    'baris' => $index + 1,
                    'debet' => $baris['debet'],
                    'kredit' => $baris['kredit'],
                    'keterangan' => $baris['keterangan'],
                    'rekening_id' => $baris['rekening_id'],
                    'anggota_id' => $anggota->id,
                ]);
            }

            return $jurnal;
        });
    }

    /* =====================================================================
     | 3. TOP-UP SIMPANAN SUKARELA (SALDO SUMBER AUTO-DEBIT)
     |===================================================================== */

    /**
     * Isi saldo Simpanan Sukarela sebagai sumber dana auto-debit.
     *
     * Saldo awal sudah memperhitungkan seluruh tagihan yang sudah terpotong,
     * sehingga seeder aman dijalankan berulang (tidak menambah saldo ganda):
     *   saldo = SALDO_AWAL − Σ(total debit transaksi auto-debit yang tercatat)
     */
    private function pastikanSaldoSukarela(Anggota $anggota, Rekening $rekening): void
    {
        $totalAutoDebit = (float) Transaksi::where('anggota_id', $anggota->id)
            ->where('rekening_id', $rekening->id)
            ->where('jenis_transaksi', 'Auto-Debit Simpanan Wajib')
            ->sum('jumlah');

        $saldoTarget = round($this->saldoAwal() - $totalAutoDebit, 2);

        $rekening->update(['saldo' => $saldoTarget]);

        $this->pastikanTopUpSukarela($anggota, $rekening, $saldoTarget);
    }

    private function saldoAwal(): float
    {
        return 5000000.00;
    }

    /**
     * Catat setoran Tunai ke rekening Sukarela + jurnal DEBET 1-1001 /
     * KREDIT 2-1001, memakai pola "delta" agar idempoten: selisih antara
     * yang seharusnya dan yang sudah tercatat di jurnal.
     */
    private function pastikanTopUpSukarela(Anggota $anggota, Rekening $rekening, float $saldoTarget): void
    {
        $kas = Coa::findByKode(self::COA_KAS);
        $sukarela = Coa::findByKode(self::COA_SUKARELA);

        if (! $kas || ! $sukarela) {
            return;
        }

        $sudahDijurnal = $this->saldoCoaAnggota($anggota->id, $sukarela);
        $selisih = round($saldoTarget - $sudahDijurnal, 2);

        if ($selisih <= 0) {
            return;
        }

        DB::transaction(function () use ($anggota, $rekening, $kas, $sukarela, $selisih, $saldoTarget): void {
            $tanggal = $anggota->tanggal_gabung->copy()->setTime(9, 30);

            $transaksi = Transaksi::firstOrCreate(
                ['no_transaksi' => 'TRX-SUKARELA-TOPDOWN-'.$anggota->id],
                [
                    'waktu_transaksi' => $tanggal,
                    'rekening_id' => $rekening->id,
                    'anggota_id' => $anggota->id,
                    'jenis_transaksi' => 'Setoran Tunai',
                    'posisi' => 'Debet',
                    'jumlah' => $selisih,
                    'saldo_setelah' => $saldoTarget,
                    'user_id' => $this->teller?->id,
                    'sumber_transaksi' => 'Teller',
                    'status_transaksi' => 'Sukses',
                    'status' => 'Sukses',
                    'catatan' => 'Setoran Simpanan Sukarela — sumber dana auto-debit Simpanan Wajib',
                ]
            );

            $jurnal = Jurnal::create([
                'no_jurnal' => $this->generateNoJurnal(),
                'tanggal_jurnal' => $tanggal->toDateString(),
                'jenis_jurnal' => 'Jurnal Otomatis',
                'transaksi_id' => $transaksi->id,
                'sumber' => 'Setoran Simpanan Sukarela',
                'keterangan' => 'Setoran Simpanan Sukarela '.$anggota->nama_lengkap,
                'total_debet' => $selisih,
                'total_kredit' => $selisih,
                'is_balance' => true,
                'status' => 'Posted',
                'posted_at' => $tanggal,
                'user_posted' => $this->teller?->id,
                'user_id' => $this->teller?->id,
            ]);

            JurnalDetail::create([
                'jurnal_id' => $jurnal->id,
                'coa_id' => $kas->id,
                'akun_id' => null,
                'baris' => 1,
                'debet' => $selisih,
                'kredit' => 0,
                'keterangan' => $kas->nama_akun,
                'rekening_id' => $rekening->id,
                'anggota_id' => $anggota->id,
            ]);

            JurnalDetail::create([
                'jurnal_id' => $jurnal->id,
                'coa_id' => $sukarela->id,
                'akun_id' => null,
                'baris' => 2,
                'debet' => 0,
                'kredit' => $selisih,
                'keterangan' => $sukarela->nama_akun,
                'rekening_id' => $rekening->id,
                'anggota_id' => $anggota->id,
            ]);
        });
    }

    /* =====================================================================
     | 4. RIWAYAT TAGIHAN SIMPANAN WAJIB JANUARI–SEPTEMBER 2026 (AUTO_DEBIT)
     |===================================================================== */

    /**
     * Generate seluruh riwayat tagihan Simpanan Wajib dari bulan bergabung
     * sampai bulan berjalan, semuanya PAID via AUTO_DEBIT dari saldo
     * Simpanan Sukarela.
     *
     * Aturan penjadwalan mengikuti MandatorySavingsService::terjadwalHariIni():
     *  - Anggota bergabung 31 Januari 2026 (end-of-month) → tanggal eksekusi
     *    selalu hari terakhir bulan berjalan. Karena itu tagihan Februari 2026
     *    dieksekusi 28 Februari 2026, Maret pada 31 Maret 2026, dan seterusnya.
     *  - Bila seeder dijalankan sebelum akhir bulan berjalan, tagihan bulan ini
     *    dicatat pada tanggal hari ini agar tidak ada jurnal bertanggal masa depan.
     */
    private function generateRiwayatSimpananWajib(Anggota $anggota): void
    {
        $mulai = $anggota->tanggal_gabung->copy()->startOfMonth();
        $selesai = Carbon::now()->startOfMonth();

        // Batas aman: maksimum 24 bulan ke belakang bila tanggal sistem melenceng.
        $batas = $mulai->copy()->addMonths(24);
        if ($selesai->gt($batas)) {
            $selesai = $batas;
        }

        $kursor = $mulai->copy();

        while ($kursor->lte($selesai)) {
            $this->lunasiSimpananWajibBulan($anggota, $kursor->copy());
            $kursor->addMonth();
        }

        // Kembalikan status ke ACTIVE (mitigasi bila seeder lain menimpanya).
        if (! $anggota->isActive() && ! $this->masihAdaTunggakan($anggota)) {
            $anggota->update(['status_anggota' => Anggota::STATUS_ACTIVE]);
        }
    }

    private function lunasiSimpananWajibBulan(Anggota $anggota, Carbon $awalBulan): void
    {
        $periode = $awalBulan->format('Y-m');
        $nominal = KebijakanKoperasi::simpananWajibBulanan();

        // Auto-debit dieksekusi pada hari terakhir bulan berjalan. Bila seeder
        // dijalankan sebelum akhir bulan, tanggal tersebut masih di masa depan —
        // pakai tanggal hari ini agar tidak ada transaksi/jurnal bertanggal maju.
        $tanggal = $awalBulan->copy()->endOfMonth()->setTime(0, 1);
        if ($tanggal->isFuture()) {
            $tanggal = Carbon::now()->setTime(0, 1);
        }

        // Bulk-setoran awal (bulan bergabung) sudah tercatat saat pembayaran
        // setoran awal oleh Teller — jangan digandakan sebagai auto-debit.
        $bill = MandatorySavingsBill::firstOrCreate(
            [
                'member_id' => $anggota->id,
                'bill_period' => $periode,
            ],
            [
                'amount' => $nominal,
                'status' => MandatorySavingsBill::STATUS_UNPAID,
            ]
        );

        if ($bill->isPaid()) {
            return;
        }

        DB::transaction(function () use ($anggota, $bill, $nominal, $tanggal, $periode): void {
            $rekening = $this->pastikanRekeningSukarela($anggota);
            $sukarela = Coa::findByKode(self::COA_SUKARELA);
            $wajib = Coa::findByKode(self::COA_WAJIB);

            $keterangan = sprintf(
                'Auto-debit Simpanan Wajib %s — %s',
                $bill->nama_periode,
                $anggota->nama_lengkap
            );

            $transaksi = Transaksi::firstOrCreate(
                ['no_transaksi' => 'TRX-AUTODEBIT-SW-'.$anggota->id.'-'.$periode],
                [
                    'waktu_transaksi' => $tanggal,
                    'rekening_id' => $rekening->id,
                    'anggota_id' => $anggota->id,
                    'jenis_transaksi' => 'Auto-Debit Simpanan Wajib',
                    'posisi' => 'Kredit',
                    'jumlah' => $nominal,
                    'saldo_setelah' => $rekening->saldo,
                    'user_id' => null,
                    'sumber_transaksi' => 'Auto',
                    'status_transaksi' => 'Sukses',
                    'status' => 'Sukses',
                    'catatan' => $keterangan,
                ]
            );

            /* AUTO-DEBIT nyata: potong saldo Simpanan Sukarela.
             | Dilakukan SEBELUM perhitungan saldo_setelah agar idempoten —
             | bila transaksi sudah ada (seeder diulang), pemotongan dilewati. */
            if ($transaksi->wasRecentlyCreated && $rekening->exists) {
                $saldoBaru = round((float) $rekening->saldo - $nominal, 2);

                $rekening->update(['saldo' => $saldoBaru]);
                $transaksi->update(['saldo_setelah' => $saldoBaru]);

                // Subledger rekening Wajib ikut bertambah (selaras jurnal KREDIT 3-1002).
                $rekeningWajib = Rekening::query()
                    ->where('anggota_id', $anggota->id)
                    ->whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_WAJIB))
                    ->where('status_rekening', 'Aktif')
                    ->orderBy('id')
                    ->first();

                if ($rekeningWajib) {
                    $rekeningWajib->update(['saldo' => round((float) $rekeningWajib->saldo + $nominal, 2)]);
                }
            }

            // Jurnal PSAK: DEBET 2-1001 Simpanan Sukarela | KREDIT 3-1002 Simpanan Wajib.
            $jurnal = $this->jurnalAutoDebit($anggota, $rekening, $sukarela, $wajib, $nominal, $transaksi->id, $keterangan, $tanggal);

            $bill->update([
                'amount' => $nominal,
                'status' => MandatorySavingsBill::STATUS_PAID,
                'paid_at' => $tanggal,
                'payment_method' => MandatorySavingsBill::METODE_AUTO_DEBIT,
                'transaksi_id' => $transaksi->id,
                'jurnal_id' => $jurnal?->id,
            ]);
        });
    }

    private function jurnalAutoDebit(
        Anggota $anggota,
        Rekening $rekening,
        ?Coa $sukarela,
        ?Coa $wajib,
        float $nominal,
        int $transaksiId,
        string $keterangan,
        Carbon $tanggal
    ): ?Jurnal {
        if (! $sukarela || ! $wajib) {
            Log::warning('AdamRenataSeeder: CoA Simpanan Sukarela/Wajib tidak ditemukan — jurnal auto-debit dilewati.');

            return null;
        }

        // Guard idempoten: transaksi ini sudah punya jurnal → jangan posting ulang.
        $tersimpan = Jurnal::where('transaksi_id', $transaksiId)
            ->whereHas('detail', fn ($query) => $query->where('coa_id', $wajib->id))
            ->first();

        if ($tersimpan) {
            return $tersimpan;
        }

        $jurnal = Jurnal::create([
            'no_jurnal' => $this->generateNoJurnal(),
            'tanggal_jurnal' => $tanggal->toDateString(),
            'jenis_jurnal' => 'Jurnal Otomatis',
            'transaksi_id' => $transaksiId,
            'sumber' => 'Simpanan Wajib Auto-Debit',
            'keterangan' => $keterangan,
            'total_debet' => $nominal,
            'total_kredit' => $nominal,
            'is_balance' => true,
            'status' => 'Posted',
            'posted_at' => $tanggal,
            'user_posted' => null,
            'user_id' => null,
        ]);

        foreach ([
            ['coa' => $sukarela, 'debet' => $nominal, 'kredit' => 0.0],
            ['coa' => $wajib,    'debet' => 0.0,     'kredit' => $nominal],
        ] as $index => $baris) {
            JurnalDetail::create([
                'jurnal_id' => $jurnal->id,
                'coa_id' => $baris['coa']->id,
                'akun_id' => null,
                'baris' => $index + 1,
                'debet' => $baris['debet'],
                'kredit' => $baris['kredit'],
                'keterangan' => $baris['coa']->nama_akun,
                'rekening_id' => $rekening->id,
                'anggota_id' => $anggota->id,
            ]);
        }

        return $jurnal;
    }

    /* =====================================================================
     | 5. UTILITAS
     |===================================================================== */

    /** Akun operasional (`akuns`) untuk relasi rekenings.akun_id. */
    /**
     * Id baris pada tabel `akun` (CoA legacy) dari kode CoA modern (`coas`).
     *
     * `rekening.akun_id` menunjuk ke tabel `akun` (legacy), sedangkan jurnal
     * memakai `jurnal_detail.coa_id` → tabel `coas`. Keduanya memakai kode
     * yang berbeda (1133 vs 2-1001), sehingga perlu dipetakan.
     */
    private function akunId(string $kodeCoa): ?int
    {
        $kodeLegacy = match ($kodeCoa) {
            self::COA_POKOK => '1131', // Simpanan Pokok Anggota
            self::COA_WAJIB => '1132', // Simpanan Wajib Anggota
            self::COA_SUKARELA => '1133', // Simpanan Sukarela Anggota
            default => $kodeCoa,
        };

        return Akun::where('kode_akun', $kodeLegacy)->value('id')
            ?? Akun::where('kode_akun', $kodeCoa)->value('id');
    }

    /**
     * Saldo berjalan sebuah CoA untuk satu anggota, dinyatakan dalam
     * orientasi saldo normal akun tersebut.
     *
     * CoA bersaldo normal Kredit (Kewajiban/Ekuitas — mis. 2-1001 Simpanan
     * Sukarela, 3-1001/3-1002 Simpanan Pokok/Wajib) menghasilkan angka positif
     * sebesar kewajiban koperasi kepada anggota, sehingga dapat langsung
     * dibandingkan dengan `rekening.saldo`.
     */
    private function saldoCoaAnggota(int $anggotaId, Coa $coa): float
    {
        $mutasi = JurnalDetail::query()
            ->join('jurnal', 'jurnal.id', '=', 'jurnal_detail.jurnal_id')
            ->whereNull('jurnal.deleted_at')
            ->where('jurnal.status', 'Posted')
            ->where('jurnal_detail.anggota_id', $anggotaId)
            ->where('jurnal_detail.coa_id', $coa->id)
            ->selectRaw('COALESCE(SUM(jurnal_detail.debet), 0) AS total_debet, COALESCE(SUM(jurnal_detail.kredit), 0) AS total_kredit')
            ->first();

        $debet = (float) ($mutasi->total_debet ?? 0);
        $kredit = (float) ($mutasi->total_kredit ?? 0);

        // Arah saldo mengikuti POSISI akun (turunan Kepala kode), bukan kolom
        // mentah `tipe_saldo_normal`, agar konsisten dengan Coa::saldoBerjalan().
        $saldo = $coa->posisiSaldo() === 'Kredit'
            ? $kredit - $debet
            : $debet - $kredit;

        return round($saldo, 2);
    }

    private function masihAdaTunggakan(Anggota $anggota): bool
    {
        return MandatorySavingsBill::where('member_id', $anggota->id)
            ->where('status', MandatorySavingsBill::STATUS_OVERDUE)
            ->exists();
    }

    /**
     * Samakan saldo subledger (rekening) Pokok & Wajib dengan mutasi buku
     * besar per anggota. Aman dijalankan berulang karena nilainya konvergen,
     * bukan akumulatif.
     *
     * @param  array{pokok: Rekening, wajib: Rekening}  $rekeningSimpanan
     */
    private function selaraskanSaldoRekeningSimpanan(Anggota $anggota, array $rekeningSimpanan): void
    {
        $pasangan = [
            [self::COA_POKOK, $rekeningSimpanan['pokok']],
            [self::COA_WAJIB, $rekeningSimpanan['wajib']],
        ];

        foreach ($pasangan as [$kodeCoa, $rekening]) {
            $coa = Coa::findByKode($kodeCoa);

            if (! $coa) {
                continue;
            }

            $saldoBuku = $this->saldoCoaAnggota($anggota->id, $coa);

            if (abs((float) $rekening->saldo - $saldoBuku) >= 0.01) {
                $rekening->update(['saldo' => $saldoBuku]);
            }
        }

        // Backfill atribusi `rekening_id` pada jurnal bundling lama yang
        // terposting sebelum rekening Pokok/Wajib dibuka seeder ini.
        $jurnalBundling = Jurnal::where('sumber', 'Setoran Awal Anggota (Bundling)')
            ->whereHas('detail', fn ($query) => $query->where('anggota_id', $anggota->id))
            ->latest('id')
            ->first();

        if (! $jurnalBundling) {
            return;
        }

        foreach ($pasangan as [$kodeCoa, $rekening]) {
            $coa = Coa::findByKode($kodeCoa);

            if (! $coa) {
                continue;
            }

            DB::table('jurnal_detail')
                ->where('jurnal_id', $jurnalBundling->id)
                ->where('coa_id', $coa->id)
                ->where(fn ($query) => $query->whereNull('rekening_id')->orWhere('rekening_id', 0))
                ->update(['rekening_id' => $rekening->id]);
        }
    }

    /** Generator nomor jurnal harian: JRN-YYYYMMDD-0001 (aman record terhapus). */
    private function generateNoJurnal(): string
    {
        $prefix = 'JRN-'.date('Ymd').'-';
        $maxNo = (string) Jurnal::withTrashed()->where('no_jurnal', 'like', $prefix.'%')->max('no_jurnal');
        $urut = $maxNo ? ((int) substr($maxNo, -4)) + 1 : 1;

        return $prefix.str_pad((string) $urut, 4, '0', STR_PAD_LEFT);
    }

    /* =====================================================================
     | 6. LAPORAN VERIFIKASI (dijalankan otomatis saat db:seed)
     |===================================================================== */

    private function laporkan(Anggota $anggota, Rekening $rekening, float $jumlahSetoranAwal): void
    {
        $anggota->refresh();
        $rekening->refresh();

        $tagihan = MandatorySavingsBill::where('member_id', $anggota->id)->orderBy('bill_period')->get();

        $totalAutoDebit = (float) Transaksi::where('anggota_id', $anggota->id)
            ->where('jenis_transaksi', 'Auto-Debit Simpanan Wajib')
            ->sum('jumlah');

        $sukarela = Coa::findByKode(self::COA_SUKARELA);
        $wajib = Coa::findByKode(self::COA_WAJIB);

        $saldoBukuSukarela = $sukarela ? $this->saldoCoaAnggota($anggota->id, $sukarela) : 0.0;
        $saldoBukuWajib = $wajib ? $this->saldoCoaAnggota($anggota->id, $wajib) : 0.0;
        $selisih = round($saldoBukuSukarela - (float) $rekening->saldo, 2);

        $this->command?->newLine();
        $this->command?->info('── Studi Kasus Adam Renata (ANG-2026-0017) ─────────────────');
        $this->command?->line('  CIF                 : '.($anggota->no_cif ?? '—'));
        $this->command?->line('  Tanggal gabung      : '.$anggota->tanggal_gabung?->toDateString());
        $this->command?->line('  Status keanggotaan  : '.$anggota->status_anggota);
        $this->command?->line('  Preferensi bayar    : '.($anggota->is_auto_debit ? 'AUTO_DEBIT' : 'TELLER'));
        $this->command?->line('  Setoran awal        : Rp '.number_format($jumlahSetoranAwal, 0, ',', '.').' (PAID via Teller)');
        $this->command?->line('  Tagihan Simpanan Wajib: '.$tagihan->count().' periode');
        $this->command?->line('    · PAID AUTO_DEBIT : '.$tagihan->where('status', MandatorySavingsBill::STATUS_PAID)
            ->where('payment_method', MandatorySavingsBill::METODE_AUTO_DEBIT)->count());
        $this->command?->line('    · UNPAID/OVERDUE  : '.$tagihan->whereIn('status', [MandatorySavingsBill::STATUS_UNPAID, MandatorySavingsBill::STATUS_OVERDUE])->count());

        foreach ($tagihan as $baris) {
            $this->command?->line(sprintf(
                '      - %s | %s | %s | Rp %s',
                $baris->bill_period,
                str_pad((string) $baris->status, 8),
                str_pad((string) ($baris->payment_method ?? '—'), 11),
                number_format((float) $baris->amount, 0, ',', '.')
            ));

            if ($baris->paid_at) {
                $this->command?->line('        paid_at: '.$baris->paid_at->format('Y-m-d H:i:s'));
            }
        }

        $this->command?->line('  Saldo Simpanan Sukarela (rekening): Rp '.number_format((float) $rekening->saldo, 0, ',', '.'));
        $this->command?->line('  Saldo Simpanan Sukarela (buku CoA): Rp '.number_format($saldoBukuSukarela, 0, ',', '.'));
        $this->command?->line('  Saldo Simpanan Wajib (buku CoA)   : Rp '.number_format($saldoBukuWajib, 0, ',', '.'));
        $this->command?->line('  Total auto-debit terpotong        : Rp '.number_format($totalAutoDebit, 0, ',', '.'));

        if (abs($selisih) < 0.01) {
            $this->command?->info('  Selisih rekening vs Buku Besar: Rp 0 — seimbang (tanpa selisih).');
        } else {
            $this->command?->error('  Selisih rekening vs Buku Besar: Rp '.number_format($selisih, 2, ',', '.'));
        }

        $this->command?->info('────────────────────────────────────────────────────────────');
        $this->command?->newLine();
    }
}
