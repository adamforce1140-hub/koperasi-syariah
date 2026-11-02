<?php

namespace Database\Seeders;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\Coa;
use App\Models\DepositoProduct;
use App\Models\KebijakanKoperasi;
use App\Models\MandatorySavingsBill;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\AutoJournalingService;
use App\Services\MandatorySavingsService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Master produk Simpanan & Deposito Syariah + data awal Simpanan Sukarela.
 *
 * 1. Enam produk aktif dikonfigurasi penuh di database (tipe produk, penanda
 *    nisbah, kode CoA, tenor, batas minimum, dan porsi bagi hasil):
 *      - Simpanan Pokok    (CoA 3-1001) : tanpa nisbah bulanan -> SHU Tahunan
 *      - Simpanan Wajib    (CoA 3-1002) : tanpa nisbah bulanan -> SHU Tahunan
 *      - Simpanan Sukarela (CoA 2-1001) : nisbah 15 : 85
 *      - Deposito 1 Bulan  (CoA 2-1003) : nisbah 40 : 60, min Rp 100.000
 *      - Deposito 3 Bulan  (CoA 2-1003) : nisbah 50 : 50, min Rp 250.000
 *      - Deposito 6 Bulan  (CoA 2-1003) : nisbah 60 : 40, min Rp 500.000
 *
 *    Simpanan Pokok & Simpanan Wajib sengaja `has_nisbah = false` karena
 *    imbalannya tidak dihitung sebagai bagi hasil bulanan melainkan
 *    diselesaikan lewat SHU Tahunan pada bagian Ekuitas laporan keuangan
 *    (PSAK 101).
 *
 *    Setiap produk Deposito juga membawa ATURAN PENALTI yang tersimpan di
 *    database (bukan di kode): boleh/tidak pencairan dini, jenis & nominal
 *    biaya penalti, status forfeit bagi hasil bulanan, serta CoA tujuan
 *    penerimaan biaya penalti (4-2001 Pendapatan Biaya Administrasi).
 *
 *    MODE ARO (Automatic Roll Over) TIDAK dipatok pada produk: mode dipilih
 *    anggota saat pembukaan bilyet dan tersimpan per akad di kolom
 *    `depositos.aro_mode`. Produk hanya menyediakan nisbah & tenor sebagai
 *    dasar perhitungan perpanjangan otomatis.
 *
 * 2. Rekening Simpanan Sukarela anggota contoh 'Adam Renata' (CIF
 *    ANG-2026-0017) dipastikan ada, aktif, dan bersaldo minimal Rp 5.000.000
 *    lengkap dengan transaksi setoran serta jurnal akuntansinya.
 *
 * Seeder ini IDEMPOTEN (memakai upsert): aman dijalankan berulang tanpa
 * menggandakan produk, rekening, transaksi, maupun jurnal.
 */
class DepositoProductSeeder extends Seeder
{
    /**
     * Master produk Simpanan & Deposito Syariah.
     * Nisbah adalah PORSI bagi hasil (anggota + koperasi = 100%).
     *
     * Aturan penalti hanya relevan untuk produk Deposito; produk Simpanan
     * memakai nilai default (tidak ada pencairan dini).
     */
    private const PRODUK = [
        [
            'name' => 'Simpanan Pokok',
            'tipe_produk' => DepositoProduct::TIPE_POKOK,
            'has_nisbah' => false,
            'coa_kode' => '3-1001',
            'tenor_months' => null,
            'nisbah_anggota' => 0.00,
            'nisbah_koperasi' => 0.00,
            'min_amount' => null,
        ],
        [
            'name' => 'Simpanan Wajib',
            'tipe_produk' => DepositoProduct::TIPE_WAJIB,
            'has_nisbah' => false,
            'coa_kode' => '3-1002',
            'tenor_months' => null,
            'nisbah_anggota' => 0.00,
            'nisbah_koperasi' => 0.00,
            'min_amount' => null,
        ],
        [
            'name' => 'Simpanan Sukarela',
            'tipe_produk' => DepositoProduct::TIPE_SUKARELA,
            'has_nisbah' => true,
            'coa_kode' => '2-1001',
            'tenor_months' => null,
            'nisbah_anggota' => 15.00,
            'nisbah_koperasi' => 85.00,
            'min_amount' => null,
        ],
        [
            'name' => 'Deposito 1 Bulan',
            'tipe_produk' => DepositoProduct::TIPE_DEPOSITO,
            'has_nisbah' => true,
            'coa_kode' => '2-1003',
            'tenor_months' => 1,
            'nisbah_anggota' => 40.00,
            'nisbah_koperasi' => 60.00,
            'min_amount' => 100_000,
            'allow_early_withdrawal' => true,
            'penalty_fee_type' => DepositoProduct::PENALTI_PERCENTAGE,
            'penalty_fee_amount' => 1.00,
            'forfeit_monthly_profit' => true,
            'penalty_coa_id' => \App\Support\Coa::DANA_KEBAJIKAN,
        ],
        [
            'name' => 'Deposito 3 Bulan',
            'tipe_produk' => DepositoProduct::TIPE_DEPOSITO,
            'has_nisbah' => true,
            'coa_kode' => '2-1003',
            'tenor_months' => 3,
            'nisbah_anggota' => 50.00,
            'nisbah_koperasi' => 50.00,
            'min_amount' => 250_000,
            'allow_early_withdrawal' => true,
            'penalty_fee_type' => DepositoProduct::PENALTI_PERCENTAGE,
            'penalty_fee_amount' => 1.00,
            'forfeit_monthly_profit' => true,
            'penalty_coa_id' => \App\Support\Coa::DANA_KEBAJIKAN,
        ],
        [
            'name' => 'Deposito 6 Bulan',
            'tipe_produk' => DepositoProduct::TIPE_DEPOSITO,
            'has_nisbah' => true,
            'coa_kode' => '2-1003',
            'tenor_months' => 6,
            'nisbah_anggota' => 60.00,
            'nisbah_koperasi' => 40.00,
            'min_amount' => 500_000,
            'allow_early_withdrawal' => true,
            'penalty_fee_type' => DepositoProduct::PENALTI_FIXED,
            'penalty_fee_amount' => DepositoProduct::PENALTI_AMOUNT_DEFAULT,
            'forfeit_monthly_profit' => true,
            'penalty_coa_id' => \App\Support\Coa::DANA_KEBAJIKAN,
        ],
    ];

    /** Saldo awal minimal Rekening Simpanan Sukarela contoh. */
    private const SALDO_AWAL_SUKARELA = 5_000_000;

    /** CIF anggota contoh pemilik Rekening Simpanan Sukarela. */
    private const CIF_ANGGOTA_CONTOH = 'ANG-2026-0017';

    /* ── Studi kasus Adam Renata: riwayat Simpanan Wajib Jan–Sep 2026 ───── */

    /** Periode pertama riwayat tagihan Simpanan Wajib ('YYYY-MM'). */
    private const PERIODE_AWAL_SIMPANAN_WAJIB = '2026-01';

    /** Periode terakhir riwayat tagihan Simpanan Wajib ('YYYY-MM'). */
    private const PERIODE_AKHIR_SIMPANAN_WAJIB = '2026-09';

    public function run(): void
    {
        $this->seedProduk();
        $this->pastikanRekeningSukarelaContoh();
        $this->pastikanRiwayatSimpananWajib();
    }

    /**
     * Upsert master produk. Kunci `name` membuat seeder aman dijalankan
     * berulang: baris yang sudah ada disegarkan, bukan diduplikasi.
     */
    private function seedProduk(): void
    {
        $tiadaCoa = [];

        foreach (self::PRODUK as $produk) {
            // Verifikasi atribut wajib terisi sesuai spesifikasi.
            $this->verifikasiProduk($produk);

            DepositoProduct::updateOrCreate(
                ['name' => $produk['name']],
                $produk + ['is_active' => true]
            );

            if (! Coa::findByKode($produk['coa_kode'])) {
                $tiadaCoa[] = $produk['coa_kode'];
            }

            if (isset($produk['penalty_coa_id']) && ! Coa::findByKode($produk['penalty_coa_id'])) {
                $tiadaCoa[] = $produk['penalty_coa_id'];
            }
        }

        if ($tiadaCoa) {
            $this->command?->warn(
                'DepositoProductSeeder: CoA berikut belum terdaftar — jalankan CoaSeeder: '
                .implode(', ', array_unique($tiadaCoa))
            );
        }

        $this->command?->info(
            'DepositoProductSeeder: '.count(self::PRODUK).' produk Simpanan & Deposito siap (upsert).'
        );
    }

    /** Memastikan atribut produk lengkap dan konsisten sebelum disimpan. */
    private function verifikasiProduk(array $produk): void
    {
        $wajibAda = ['name', 'tipe_produk', 'has_nisbah', 'coa_kode', 'tenor_months', 'nisbah_anggota', 'nisbah_koperasi', 'min_amount'];

        foreach ($wajibAda as $kolom) {
            if (! array_key_exists($kolom, $produk)) {
                throw new \RuntimeException("DepositoProductSeeder: atribut '{$kolom}' belum diisi untuk produk '{$produk['name']}'.");
            }
        }

        if (! in_array($produk['tipe_produk'], [
            DepositoProduct::TIPE_POKOK,
            DepositoProduct::TIPE_WAJIB,
            DepositoProduct::TIPE_SUKARELA,
            DepositoProduct::TIPE_DEPOSITO,
        ], true)) {
            throw new \RuntimeException("DepositoProductSeeder: tipe_produk '{$produk['tipe_produk']}' tidak dikenal.");
        }

        $total = (float) $produk['nisbah_anggota'] + (float) $produk['nisbah_koperasi'];

        if ($produk['has_nisbah'] && abs($total - 100.0) >= 0.01) {
            throw new \RuntimeException("DepositoProductSeeder: total nisbah produk '{$produk['name']}' harus 100% (saat ini {$total}%).");
        }

        if ($produk['tipe_produk'] === DepositoProduct::TIPE_DEPOSITO) {
            if (! in_array($produk['tenor_months'], DepositoProduct::TENOR_DIIZINKAN, true)) {
                throw new \RuntimeException("DepositoProductSeeder: tenor produk '{$produk['name']}' tidak diizinkan.");
            }

            if ((float) $produk['min_amount'] <= 0) {
                throw new \RuntimeException("DepositoProductSeeder: min_amount produk '{$produk['name']}' harus lebih dari 0.");
            }

            $this->verifikasiPenalti($produk);
        }
    }

    /**
     * Aturan penalti pencairan dini wajib lengkap & konsisten untuk setiap
     * produk Deposito agar DepositoService::earlyWithdrawal() tidak perlu
     * menebak nilai default di kode.
     */
    private function verifikasiPenalti(array $produk): void
    {
        $wajibAda = ['allow_early_withdrawal', 'penalty_fee_type', 'penalty_fee_amount', 'forfeit_monthly_profit', 'penalty_coa_id'];

        foreach ($wajibAda as $kolom) {
            if (! array_key_exists($kolom, $produk)) {
                throw new \RuntimeException("DepositoProductSeeder: aturan penalti '{$kolom}' belum diisi untuk produk '{$produk['name']}'.");
            }
        }

        if (! in_array($produk['penalty_fee_type'], [
            DepositoProduct::PENALTI_FIXED,
            DepositoProduct::PENALTI_PERCENTAGE,
        ], true)) {
            throw new \RuntimeException("DepositoProductSeeder: penalty_fee_type '{$produk['penalty_fee_type']}' tidak dikenal pada produk '{$produk['name']}'.");
        }

        if ((float) $produk['penalty_fee_amount'] < 0) {
            throw new \RuntimeException("DepositoProductSeeder: penalty_fee_amount produk '{$produk['name']}' tidak boleh negatif.");
        }

        if ($produk['penalty_fee_type'] === DepositoProduct::PENALTI_PERCENTAGE
            && (float) $produk['penalty_fee_amount'] > 100) {
            throw new \RuntimeException("DepositoProductSeeder: penalty_fee_amount persentase produk '{$produk['name']}' tidak boleh melebihi 100%.");
        }

        // R7 — ta'zir/penalti tidak boleh diarahkan ke akun pendapatan
        // (Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000). Wajib Dana Kebajikan.
        if (! DepositoProduct::akunPenaltiDiizinkan((string) $produk['penalty_coa_id'])) {
            throw new \RuntimeException(
                "DepositoProductSeeder: penalty_coa_id '{$produk['penalty_coa_id']}' pada produk "
                ."'{$produk['name']}' adalah akun PENDAPATAN — ta'zir wajib diarahkan ke Dana Kebajikan (2-3001)."
            );
        }
    }

    /**
     * Pastikan anggota contoh 'Adam Renata' (CIF ANG-2026-0017) memiliki
     * Rekening Simpanan Sukarela (CoA 2-1001) berstatus Aktif dengan saldo
     * minimal Rp 5.000.000. Bila rekening belum ada, dibuat otomatis; bila
     * sudah ada namun saldonya kurang, ditambahkan setoran pelengkap — selalu
     * disertai transaksi dan jurnal akuntansi yang seimbang.
     */
    private function pastikanRekeningSukarelaContoh(): void
    {
        $anggota = $this->anggotaContoh();

        if (! $anggota) {
            $this->command?->warn(
                'DepositoProductSeeder: anggota contoh CIF '.self::CIF_ANGGOTA_CONTOH
                .' ("Adam Renata") tidak ditemukan — rekening sukarela dilewati.'
            );

            return;
        }

        $coaSukarela = Coa::findByKode(AutoJournalingService::SIMPANAN_SUKARELA);

        if (! $coaSukarela) {
            $this->command?->warn(
                'DepositoProductSeeder: CoA '.AutoJournalingService::SIMPANAN_SUKARELA
                .' tidak ditemukan — jalankan CoaSeeder lebih dulu.'
            );

            return;
        }

        // Nisbah Simpanan Sukarela diambil dari MASTER COA (2-1001), bukan
        // dipatok di seeder — agar perubahan lewat menu Master COA terpakai.
        $nisbahAnggota = $coaSukarela->persentaseAnggota() ?: 15.00;
        $nisbahKoperasi = $coaSukarela->persentaseInstitusi() ?: 85.00;
        $snapshotNisbah = [
            'nisbah_nasabah' => $nisbahAnggota,
            'nisbah_bank' => $nisbahKoperasi,
            'applied_percentage_member' => $nisbahAnggota,
            'applied_percentage_institution' => $nisbahKoperasi,
        ];

        $rekening = Rekening::where('anggota_id', $anggota->id)
            ->where('jenis_rekening', 'Simpanan Sukarela')
            ->first();

        // Target saldo = saldo acuan DIKURANGI seluruh auto-debit Simpanan Wajib
        // yang sudah tercatat. Dengan begitu seeder tetap idempoten setelah
        // riwayat Simpanan Wajib terbentuk (5.000.000 → 4.550.000) dan tidak
        // pernah menambah dana ganda saat db:seed diulang.
        $targetSaldo = $this->targetSaldoSukarela($anggota);
        $kekurangan = round($targetSaldo - (float) ($rekening?->saldo ?? 0), 2);

        // Rekening sudah ada & saldo cukup: cukup selaraskan nisbah & snapshot
        // (mis. bila nilai master COA diubah setelah rekening dibuat).
        if ($rekening && $kekurangan <= 0) {
            $rekening->update($snapshotNisbah + ['status_rekening' => 'Aktif']);

            $this->command?->info(
                'DepositoProductSeeder: Rekening Simpanan Sukarela '.$rekening->no_rekening
                .' ('.$anggota->nama_lengkap.') sudah aktif dengan saldo Rp '
                .number_format((float) $rekening->fresh()->saldo, 0, ',', '.')
                .' — nisbah '.$nisbahAnggota.' : '.$nisbahKoperasi.' diselaraskan dari CoA 2-1001.'
            );

            return;
        }

        DB::transaction(function () use ($anggota, $rekening, $kekurangan, $targetSaldo, $snapshotNisbah) {
            $user = $this->penggunaPencatat();

            if ($rekening) {
                $rekening->update($snapshotNisbah + [
                    'status_rekening' => 'Aktif',
                    'akad' => $rekening->akad ?: 'Mudharabah',
                    'saldo' => (float) $rekening->saldo + $kekurangan,
                ]);

                $nominal = $kekurangan;
                $keterangan = 'Setoran pelengkap saldo awal Rekening Simpanan Sukarela (seeder)';
            } else {
                $rekening = Rekening::create($snapshotNisbah + [
                    'no_rekening' => $this->nomorRekeningBaru(),
                    'anggota_id' => $anggota->id,
                    'akun_id' => $this->akunIdSimpanan(),
                    'jenis_rekening' => 'Simpanan Sukarela',
                    'akad' => 'Mudharabah',
                    'saldo' => $targetSaldo,
                    'batas_minimum' => 100_000,
                    'tanggal_buka' => now()->toDateString(),
                    'status_rekening' => 'Aktif',
                    'keterangan' => 'Rekening contoh dibuat otomatis oleh DepositoProductSeeder',
                ]);

                $nominal = $targetSaldo;
                $keterangan = 'Setoran awal pembukaan Rekening Simpanan Sukarela (seeder)';
            }

            $transaksi = Transaksi::create([
                'no_transaksi' => $this->nomorTransaksiBaru(),
                'waktu_transaksi' => now(),
                'rekening_id' => $rekening->id,
                'anggota_id' => $anggota->id,
                'jenis_transaksi' => 'Setoran Tunai',
                'posisi' => 'Debet',
                'jumlah' => $nominal,
                'saldo_setelah' => (float) $rekening->fresh()->saldo,
                'user_id' => $user?->id,
                'sumber_transaksi' => 'Teller',
                'status_transaksi' => 'Sukses',
                'status' => 'Sukses',
                'catatan' => $keterangan,
            ]);

            // Jurnal akuntansi: DEBET 1-1001 Kas Teller / KREDIT 2-1001 Simpanan Sukarela.
            $jurnal = app(AutoJournalingService::class)->jurnalSetorTunai(
                (float) $nominal,
                'Setoran Simpanan Sukarela - '.$anggota->nama_lengkap,
                $user?->id,
                $transaksi->id,
                $rekening->id,
                $anggota->id
            );

            if (! $jurnal) {
                throw new \RuntimeException(
                    'DepositoProductSeeder: auto-journaling setoran awal gagal. Pastikan CoA '
                    .AutoJournalingService::KAS_TELLER.' dan '.AutoJournalingService::SIMPANAN_SUKARELA.' aktif.'
                );
            }

            $this->command?->info(
                'DepositoProductSeeder: Rekening Simpanan Sukarela '.$rekening->no_rekening
                .' ('.$anggota->nama_lengkap.') saldo Rp '
                .number_format((float) $rekening->fresh()->saldo, 0, ',', '.')
                .' — setoran Rp '.number_format((float) $nominal, 0, ',', '.')
                .', jurnal '.$jurnal->no_jurnal.'.'
            );
        });
    }

    /** Anggota contoh: diprioritaskan lewat CIF, fallback ke nama lengkap. */
    private function anggotaContoh(): ?Anggota
    {
        return Anggota::where('no_cif', self::CIF_ANGGOTA_CONTOH)->first()
            ?? Anggota::where('nama_lengkap', 'Adam Renata')->first();
    }

    /* =====================================================================
     | STUDI KASUS ADAM RENATA — RIWAYAT SIMPANAN WAJIB + AUTO-DEBIT
     |===================================================================== */

    /**
     * Saldo acuan Simpanan Sukarela sebelum riwayat Simpanan Wajib diproses.
     *
     * Seed lain (DemoDataSeeder langkah 7 & 8) sudah memutasi rekening yang
     * sama, sehingga saldo acuan dihitung dari mutasi berjalan agar seeder
     * tetap idempoten dan tidak menambah dana ganda saat db:seed diulang.
     */
    private function targetSaldoSukarela(Anggota $anggota): float
    {
        $mutasiWajib = (float) MandatorySavingsBill::query()
            ->where('member_id', $anggota->id)
            ->where('status', MandatorySavingsBill::STATUS_PAID)
            ->sum('amount');

        return round(self::SALDO_AWAL_SUKARELA - $mutasiWajib, 2);
    }

    /**
     * Riwayat tagihan Simpanan Wajib Adam Renata Januari–September 2026.
     *
     * Skenario sesuai spesifikasi Permintaan 19 poin 6:
     *  - Anggota bergabung 31 Januari 2026 (End-of-Month), maka tanggal
     *    eksekusi tagihan mengikuti akhir bulan berjalan — tagihan Februari
     *    2026 diproses 28 Februari 2026 (bukan 31 Februari yang tidak ada),
     *    hasil Carbon::isEndOfMonth().
     *  - Sembilan tagihan (Jan–Sep 2026) berstatus PAID dengan metode
     *    AUTO_DEBIT, masing-masing disertai transaksi & jurnal PSAK:
     *      DEBET  2-1001 Simpanan Sukarela
     *      KREDIT 3-1002 Simpanan Wajib Anggota (Modal Anggota)
     *  - Total auto-debit Rp 450.000 memotong saldo Simpanan Sukarela dari
     *    Rp 5.000.000 menjadi Rp 4.550.000.
     */
    private function pastikanRiwayatSimpananWajib(): void
    {
        $anggota = $this->anggotaContoh();

        if (! $anggota) {
            $this->command?->warn(
                'DepositoProductSeeder: anggota contoh CIF '.self::CIF_ANGGOTA_CONTOH
                .' tidak ditemukan — riwayat Simpanan Wajib dilewati.'
            );

            return;
        }

        // Tanggal bergabung harus 31 Jan 2026 agar penyesuaian End-of-Month
        // menghasilkan 28 Feb 2026. Bila data lama masih 1 Jan, diselaraskan.
        if ($anggota->tanggal_gabung?->toDateString() !== '2026-01-31') {
            $anggota->update([
                'tanggal_gabung' => '2026-01-31',
                'status_anggota' => Anggota::STATUS_ACTIVE,
            ]);

            $anggota->refresh();
        }

        $rekeningSukarela = Rekening::where('anggota_id', $anggota->id)
            ->where('jenis_rekening', 'Simpanan Sukarela')
            ->first();

        if (! $rekeningSukarela) {
            $this->command?->warn(
                'DepositoProductSeeder: Rekening Simpanan Sukarela '.$anggota->nama_lengkap
                .' tidak ditemukan — auto-debit Simpanan Wajib dilewati.'
            );

            return;
        }

        $nominal = KebijakanKoperasi::simpananWajibBulanan();
        $periode = $this->periodeSimpananWajib();
        $dibuat = 0;
        $dilewati = 0;

        DB::transaction(function () use ($anggota, $rekeningSukarela, $nominal, $periode, &$dibuat, &$dilewati) {
            foreach ($periode as $bln) {
                /** @var MandatorySavingsBill|null $bill */
                $bill = MandatorySavingsBill::firstOrCreate(
                    [
                        'member_id' => $anggota->id,
                        'bill_period' => $bln->format('Y-m'),
                    ],
                    [
                        'amount' => $nominal,
                        'status' => MandatorySavingsBill::STATUS_UNPAID,
                    ]
                );

                if ($bill->isPaid()) {
                    $dilewati++;

                    continue;
                }

                $diDebit = $this->debitSukarelaUntukTagihan($anggota, $rekeningSukarela, $bill, $bln);

                $diDebit ? $dibuat++ : $dilewati++;
            }
        });

        $saldoAkhir = (float) $rekeningSukarela->fresh()->saldo;

        $this->command?->info(
            'DepositoProductSeeder: Simpanan Wajib '.$anggota->nama_lengkap
            .' — '.$dibuat.' tagihan PAID via AUTO_DEBIT ('
            .count($periode).' periode diperiksa'.($dilewati ? ', '.$dilewati.' dilewati' : '')
            .'), saldo Sukarela akhir Rp '.number_format($saldoAkhir, 0, ',', '.').'.'
        );
    }

    /** Daftar periode tagihan Simpanan Wajib Jan–Sep 2026 (awal bulan). */
    private function periodeSimpananWajib(): array
    {
        $mulai = Carbon::createFromFormat('Y-m', self::PERIODE_AWAL_SIMPANAN_WAJIB)->startOfMonth();
        $akhir = Carbon::createFromFormat('Y-m', self::PERIODE_AKHIR_SIMPANAN_WAJIB)->startOfMonth();
        $periode = [];

        for ($kursor = $mulai->copy(); $kursor->lte($akhir); $kursor->addMonth()) {
            $periode[] = $kursor->copy();
        }

        return $periode;
    }

    /**
     * Debit saldo Simpanan Sukarela untuk satu tagihan (PSAK 101/108):
     * transaksi + jurnal DEBET 2-1001 | KREDIT 3-1002, lalu status PAID.
     *
     * `paid_at` memakai tanggal penyesuaian End-of-Month: anggota bergabung
     * 31 Jan 2026 → tagihan Februari 2026 dibayar 28 Feb 2026.
     */
    private function debitSukarelaUntukTagihan(
        Anggota $anggota,
        Rekening $rekening,
        MandatorySavingsBill $bill,
        Carbon $periode
    ): bool {
        $nominal = (float) $bill->amount;
        $rekening->refresh();

        if ((float) $rekening->saldo < $nominal) {
            $this->command?->warn(
                'DepositoProductSeeder: saldo Simpanan Sukarela '.$anggota->nama_lengkap
                .' tidak mencukupi untuk tagihan '.$bill->bill_period
                .' — dilewati.'
            );

            return false;
        }

        $paidAt = $this->tanggalEksekusiEndOfMonth($anggota, $periode);
        $user = $this->penggunaPencatat();

        $trx = Transaksi::create([
            'no_transaksi' => $this->nomorTransaksiBaru(),
            'waktu_transaksi' => $paidAt,
            'rekening_id' => $rekening->id,
            'anggota_id' => $anggota->id,
            'jenis_transaksi' => 'Auto-Debit Simpanan Wajib',
            'posisi' => 'Kredit',
            'jumlah' => $nominal,
            'nominal' => $nominal,
            'saldo_setelah' => (float) $rekening->saldo - $nominal,
            'user_id' => $user?->id,
            'sumber_transaksi' => 'Auto',
            'status_transaksi' => 'Sukses',
            'status' => 'Sukses',
            'catatan' => 'Auto-debit Simpanan Wajib periode '.$bill->bill_period,
        ]);

        $rekening->decrement('saldo', $nominal);

        $jurnal = $this->journaling()->jurnalSimpananWajibAutoDebit(
            $nominal,
            'Auto-Debit Simpanan Wajib '.$bill->bill_period.' - '.$anggota->nama_lengkap,
            $user?->id,
            $trx->id,
            $rekening->id,
            $anggota->id,
            // Tanggal jurnal mengikuti tanggal efektif pembayaran (backdate
            // akhir bulan bila tanggal gabung 29/30/31 — mis. 28 Feb 2026)
            $paidAt->toDateString(),
            null,
            null,
            $this->mandatorySavings()->rekeningWajib($anggota)?->id
        );

        if (! $jurnal) {
            // Jurnal gagal (CoA hilang/nonaktif) → batalkan seluruh mutasi
            // agar tidak ada saldo yang terpotong tanpa jejak akuntansi.
            throw new \RuntimeException(
                'DepositoProductSeeder: jurnal auto-debit Simpanan Wajib '.$bill->bill_period
                .' gagal diposting — periksa CoA 2-1001 / 3-1002.'
            );
        }

        // Subledger rekening Wajib ikut bertambah agar GL ≡ rekening.saldo.
        $wajib = $this->mandatorySavings()->rekeningWajib($anggota);

        if ($wajib) {
            $wajib->update([
                'saldo' => round((float) $wajib->saldo + $nominal, 2),
            ]);
        }

        $bill->update([
            'status' => MandatorySavingsBill::STATUS_PAID,
            'paid_at' => $paidAt,
            'payment_method' => MandatorySavingsBill::METODE_AUTO_DEBIT,
            'transaksi_id' => $trx->id,
            'jurnal_id' => $jurnal->id,
        ]);

        return true;
    }

    /**
     * Tanggal eksekusi tagihan dengan penyesuaian End-of-Month.
     *
     * Tanggal bergabung 31 → mengikuti akhir bulan berjalan, sehingga
     * Februari 2026 (bukan kabisat) menghasilkan 28 Februari 2026.
     * Diambil langsung dari service agar perilaku seeder identik dengan
     * scheduler `mandatory-savings:process-daily`.
     */
    private function tanggalEksekusiEndOfMonth(Anggota $anggota, Carbon $periode): Carbon
    {
        // 00:01 WIB — selaras dengan jadwal `mandatory-savings:process-daily`.
        return $this->mandatorySavings()->tanggalJatuhTempo($anggota, $periode)->setTime(0, 1);
    }

    /** Service Simpanan Wajib (dipakai untuk penyesuaian tanggal). */
    private function mandatorySavings(): MandatorySavingsService
    {
        return app(MandatorySavingsService::class);
    }

    /** Service jurnal otomatis. */
    private function journaling(): AutoJournalingService
    {
        return app(AutoJournalingService::class);
    }

    /** Akun legacy (tabel `akun`) untuk rekening simpanan anggota — kode 1133. */
    private function akunIdSimpanan(): int
    {
        $akunId = Akun::where('kode_akun', '1133')->value('id') ?? Akun::query()->value('id');

        if (! $akunId) {
            throw new \RuntimeException('DepositoProductSeeder: tabel akun kosong — jalankan AkunSeeder lebih dulu.');
        }

        return (int) $akunId;
    }

    /** Pengguna yang dicatat sebagai pembuat transaksi/jurnal (teller → admin). */
    private function penggunaPencatat(): ?User
    {
        return User::where('role', 'teller')->first()
            ?? User::where('role', 'admin')->first()
            ?? User::query()->first();
    }

    /** Nomor rekening baru: RKN-YYYY-XXXXXX (mengikuti pola DepositoService). */
    private function nomorRekeningBaru(): string
    {
        return Rekening::nomorRekeningBaru();
    }

    /** Nomor transaksi baru: TRX-YYYYMMDD-XXXXXX. */
    private function nomorTransaksiBaru(): string
    {
        $prefix = 'TRX-'.date('Ymd').'-';
        $maxNo = (string) Transaksi::withTrashed()->where('no_transaksi', 'like', $prefix.'%')->max('no_transaksi');
        $urut = $maxNo ? ((int) substr($maxNo, -6)) + 1 : 1;

        return $prefix.str_pad((string) $urut, 6, '0', STR_PAD_LEFT);
    }
}
