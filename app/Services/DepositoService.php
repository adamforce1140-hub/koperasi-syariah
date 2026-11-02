<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Coa;
use App\Models\Deposito;
use App\Models\DepositoPenaltyRelief;
use App\Models\DepositoProduct;
use App\Models\DepositoProfitSharing;
use App\Models\JenisSimpanan;
use App\Models\Jurnal;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Support\NomorDokumen;
use App\Support\Setting;
use Barryvdh\DomPDF\PDF;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Logika bisnis Deposito / Simpanan Berjangka Syariah (akad Mudharabah Muthlaqah).
 *
 * Prinsip yang ditegakkan service ini:
 *  1. TIDAK memakai sistem bunga tetap dan TIDAK mengaitkan komponen SHU.
 *     Imbalan anggota murni dari bagi hasil pendapatan koperasi atas nisbah.
 *  2. Sumber dana pembukaan deposito adalah REKENING SIMPANAN SUKARELA anggota
 *     (CoA 2-1001). Kas Teller tidak boleh dipakai.
 *  3. Jatuh tempo selalu dihitung sistem: start_date + tenor produk (Carbon).
 *     Tidak pernah diterima dari input pengguna.
 *  4. Setiap perubahan saldo selalu disertai jurnal otomatis yang balance.
 *
 * Alur jurnal (CoA):
 *   Pembukaan   : DEBET 2-1001 Simpanan Sukarela | KREDIT 2-1003 Simpanan Berjangka
 *   Pencairan   : DEBET 2-1003 Simpanan Berjangka | KREDIT 2-1001 Simpanan Sukarela
 *   Bagi hasil  : DEBET 5-3001 Beban Bagi Hasil    | KREDIT 2-1001 Simpanan Sukarela
 */
class DepositoService
{
    /**
     * Asumsi pendapatan yang dibagihasilkan (p.a.) untuk estimasi/simulasi.
     *
     * Dipakai HANYA untuk estimasi/simulasi bagi hasil, karena pendapatan riil
     * koperasi baru diketahui setelah periode berjalan. Angka ini BUKAN bunga
     * yang dijamin — imbalan anggota tetap nisbah × pendapatan riil, dan
     * realisasinya dicatat pada `gross_koperasi_revenue` di tabel
     * deposito_profit_sharings.
     */
    public const ASUMSI_RATE_PA = 0.08;

    /**
     * Asumsi rate p.a. yang berlaku saat ini.
     *
     * Selalu membaca `Setting::depositoRatePa()` sehingga perubahan asumsi
     * bagi hasil (PSAK 105) cukup dilakukan dari halaman Kebijakan Pengurus.
     * Konstanta ASUMSI_RATE_PA tetap dipertahankan sebagai dokumentasi nilai
     * bawaan sekaligus jaring aman bila kolom kebijakan belum tersedia.
     */
    public static function asumsiRatePa(): float
    {
        return Setting::depositoRatePa();
    }

    public function __construct(
        private AutoJournalingService $journaling,
        private JurnalReconciliationService $reconciliation
    ) {}

    /**
     * Gerbang P1-1 di akhir transaksi: saldo rekening anggota (subledger)
     * wajib sama dengan akun kontrol simpanan di buku besar.
     *
     * Dipanggil DI DALAM `DB::transaction` pemanggil sehingga membaca keadaan
     * belum-commit pada koneksi yang sama. Pada MODE LAPOR (bawaan) hanya
     * mencatat log `critical`; pada MODE TEGAKKAN
     * (`RECONCILIATION_GATE_ENFORCE=true`) melempar exception sehingga
     * seluruh transaksi digulung.
     */
    private function pastikanSubledgerSehat(): void
    {
        $this->reconciliation->pastikanSubledgerSehat();
    }

    /* =====================================================================
     | 1. SIMULASI BAGI HASIL
     |===================================================================== */

    /**
     * Hitung estimasi porsi bagi hasil anggota & koperasi berdasarkan nisbah
     * produk. Tidak ada bunga tetap dan tidak ada komponen SHU.
     *
     * PSAK 105 / Fatwa DSN-MUI No. 115: imbalan mudharabah = NISBAH ×
     * pendapatan riil usaha yang dibagihasilkan, BUKAN rate × nominal.
     * Karena itu parameter `$asumsiRate` diperlakukan sebagai asumsi
     * PENDAPATAN yang dibagihasilkan periode berjalan (bukan "bunga"), dan
     * dihitung per bulan ke dalam bulan-bulan deposito berjalan:
     *
     *   pokok(Bo) × asumsi(rate) × porsi(hari efektif / hari dalam tahun) × nisbah
     *
     * Hasilnya setara dengan versi lama untuk deposito yang seluruhnya berada
     * dalam satu periode, tetapi sekarang benar untuk akad yang melewati
     * batas periode maupun yang tidak dimulai pada awal bulan.
     *
     * @param  float  $amount  Nominal pokok deposito.
     * @param  int  $productId  ID produk deposito.
     * @param  float|null  $asumsiRate  Asumsi pendapatan dibagihasilkan p.a. (0.08 = 8%).
     * @param  string|null  $startDate  Tanggal mulai (Y-m-d). Default hari ini.
     * @return array<string, mixed>
     */
    public function calculateSimulation(float $amount, int $productId, ?float $asumsiRate = null, ?string $startDate = null): array
    {
        $product = DepositoProduct::active()->find($productId);

        if (! $product) {
            throw ValidationException::withMessages([
                'product_id' => 'Produk deposito tidak ditemukan atau sudah tidak aktif.',
            ]);
        }

        if ($amount < (float) $product->min_amount) {
            throw ValidationException::withMessages([
                'amount' => 'Nominal minimal untuk produk ini adalah Rp '
                    .number_format((float) $product->min_amount, 0, ',', '.').'.',
            ]);
        }

        $rate = $asumsiRate ?? self::asumsiRatePa();
        $start = Carbon::parse($startDate ?: today()->toDateString())->startOfDay();
        $tenor = (int) $product->tenor_months;
        $maturity = $this->hitungJatuhTempo($start, $tenor);

        $nisbahAnggota = (float) $product->nisbah_anggota;
        $nisbahKoperasi = (float) $product->nisbah_koperasi;

        // Alokasi proporsional per bulan kalender berdasarkan HARI EFEKTIF,
        // bukan "satu bulan penuh". Dengan basis hari/tahun, akad yang dimulai
        // di tengah bulan atau melintasi tahun buku tetap menghasilkan estimasi
        // yang konsisten (12 bulan penuh = tepat satu tahun).
        //
        // Nilai per bulan dilaporkan sebagai SELISIH dari kumulatif yang sudah
        // dibulatkan, sehingga Σ baris bulanan = total (tanpa selisih pembulatan).
        $kumulatifPendapatan = 0.0;
        $kumulatifAnggota = 0.0;
        $dilaporkanPendapatan = 0.0;
        $dilaporkanAnggota = 0.0;
        $pendapatanBulanan = [];

        $kursor = $start->copy();
        while ($kursor->lt($maturity)) {
            $akhirBulan = $kursor->copy()->endOfMonth()->startOfDay();
            $potong = $akhirBulan->lt($maturity) ? $akhirBulan : $maturity->copy()->subDay();

            $hariEfektif = $kursor->diffInDays($potong) + 1;
            $hariDalamTahun = $kursor->daysInYear;

            $kumulatifPendapatan += $amount * $rate * $hariEfektif / $hariDalamTahun;
            $kumulatifAnggota += $amount * $rate * $hariEfektif / $hariDalamTahun * $nisbahAnggota / 100;

            $pendapatanBulan = Setting::bulatkanUang($kumulatifPendapatan) - $dilaporkanPendapatan;
            $porsiAnggota = Setting::bulatkanUang($kumulatifAnggota) - $dilaporkanAnggota;
            $porsiKoperasi = Setting::bulatkanUang($pendapatanBulan - $porsiAnggota);

            $dilaporkanPendapatan = Setting::bulatkanUang($kumulatifPendapatan);
            $dilaporkanAnggota = Setting::bulatkanUang($kumulatifAnggota);

            $pendapatanBulanan[] = [
                'periode' => $kursor->format('Y-m'),
                'pendapatan' => $pendapatanBulan,
                'porsi_anggota' => $porsiAnggota,
                'porsi_koperasi' => $porsiKoperasi,
            ];

            $kursor = $akhirBulan->copy()->addDay();
        }

        $grossRevenue = $dilaporkanPendapatan;
        $memberProfit = $dilaporkanAnggota;
        $koperasiProfit = Setting::bulatkanUang($grossRevenue - $memberProfit);

        return [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'tenor_months' => $tenor,
                'nisbah_anggota' => $nisbahAnggota,
                'nisbah_koperasi' => $nisbahKoperasi,
                'min_amount' => (float) $product->min_amount,
            ],
            'amount' => Setting::bulatkanUang($amount),
            'tenor_months' => $tenor,
            'start_date' => $start->toDateString(),
            'maturity_date' => $maturity->toDateString(),
            'asumsi_rate_pa' => $rate,
            'asumsi_rate_pa_persen' => Setting::bulatkanUang($rate * 100),
            'estimated_gross_revenue' => $grossRevenue,
            'estimated_member_profit' => $memberProfit,
            'estimated_koperasi_profit' => $koperasiProfit,
            'estimated_monthly_profit' => $tenor > 0 ? Setting::bulatkanUang($memberProfit / $tenor) : 0.0,
            'total_diterima_saat_jatuh_tempo' => Setting::bulatkanUang($amount + $memberProfit),
            'pendapatan_bulanan' => $pendapatanBulanan,
            'skema' => 'Mudharabah Muthlaqah (nisbah, bukan bunga tetap)',
            'catatan' => 'Angka bagi hasil adalah ESTIMASI: nisbah anggota × asumsi pendapatan '
                .'yang dibagihasilkan '.Setting::bulatkanUang($rate * 100)
                .'% p.a. Realisasi mengikuti pendapatan koperasi pada periode berjalan.',
        ];
    }

    /* =====================================================================
     | 2. PEMBUKAAN DEPOSITO
     |===================================================================== */

    /**
     * Snapshot nisbah yang berlaku saat akad dibuka.
     *
     * Sumber utama porsi deposito adalah PRODUK (porsi per tenor), sedangkan
     * CoA 2-1003 menjadi nilai cadangan bila produk belum mengisi nisbah.
     * Nilai disalin ke akad sehingga perubahan master setelahnya tidak
     * mengubah perhitungan bagi hasil akad lama.
     *
     * @return array{applied_percentage_member: float, applied_percentage_institution: float}
     */
    private function snapshotNisbah(DepositoProduct $product): array
    {
        $anggota = (float) $product->nisbah_anggota;
        $institusi = (float) $product->nisbah_koperasi;

        if ($anggota + $institusi <= 0) {
            $coa = Coa::findByKode(Deposito::COA_SIMPANAN_BERJANGKA);
            $anggota = $coa?->persentaseAnggota() ?? 0.0;
            $institusi = $coa?->persentaseInstitusi() ?? 0.0;
        }

        return [
            'applied_percentage_member' => $anggota,
            'applied_percentage_institution' => $institusi,
        ];
    }

    /**
     * Snapshot nisbah dari master CoA untuk rekening simpanan.
     *
     * Dipakai saat pembukaan rekening Simpanan Sukarela (2-1001). Nilai
     * disalin dari Master CoA saat rekening dibuat sehingga perubahan nisbah
     * di menu Master CoA tidak mengubah akad yang sudah berjalan.
     *
     * @return array{applied_percentage_member: float|null, applied_percentage_institution: float|null}
     */
    public static function snapshotNisbahSimpanan(string $kodeCoa): array
    {
        $coa = Coa::findByKode($kodeCoa);

        if (! $coa || ! $coa->menerapkanPersentase()) {
            return [
                'applied_percentage_member' => null,
                'applied_percentage_institution' => null,
            ];
        }

        return [
            'applied_percentage_member' => $coa->persentaseAnggota(),
            'applied_percentage_institution' => $coa->persentaseInstitusi(),
        ];
    }

    /**
     * Buka deposito baru dengan memotong saldo Rekening Simpanan Sukarela.
     *
     * @param  array{
     *     member_id: int,
     *     product_id: int,
     *     source_saving_account_id: int,
     *     principal_amount: float|string,
     *     start_date?: string|null,
     *     is_aro?: bool,
     *     user_id?: int|null
     * } $data
     */
    public function createDeposito(array $data): Deposito
    {
        $memberId = (int) ($data['member_id'] ?? 0);
        $productId = (int) ($data['product_id'] ?? 0);
        $sourceId = (int) ($data['source_saving_account_id'] ?? 0);
        $principal = Setting::bulatkanUang((float) ($data['principal_amount'] ?? 0));
        $userId = $data['user_id'] ?? null;
        $startDate = Carbon::parse($data['start_date'] ?? today()->toDateString())->startOfDay();

        // ── Mode ARO (Automatic Roll Over) ──────────────────────────────
        // `aro_mode` adalah sumber kebenaran; `is_aro` (boolean lama) tetap
        // diterima agar pemanggil lama tidak perlu diubah.
        $aroMode = $data['aro_mode'] ?? null;

        if (! in_array($aroMode, Deposito::ARO_MODE, true)) {
            $aroMode = ! empty($data['is_aro']) ? Deposito::ARO_POKOK : Deposito::ARO_NON;
        }

        $isAro = $aroMode !== Deposito::ARO_NON;

        // ── Validasi produk ─────────────────────────────────────────────
        // Hanya produk bertipe 'deposito' (PSAK 108). Simpanan Pokok/Wajib/
        // Sukarela adalah produk simpanan (PSAK 101) tanpa tenor sehingga
        // tidak boleh dipakai untuk membuka Simpanan Berjangka.
        $product = DepositoProduct::active()
            ->deposito()
            ->find($productId);

        if (! $product) {
            throw ValidationException::withMessages([
                'product_id' => 'Produk deposito tidak ditemukan atau sudah tidak aktif.',
            ]);
        }

        if (! $product->nisbahValid()) {
            throw ValidationException::withMessages([
                'product_id' => 'Nisbah produk tidak sah: total nisbah anggota + koperasi harus 100%.',
            ]);
        }

        if ($principal < (float) $product->min_amount) {
            throw ValidationException::withMessages([
                'principal_amount' => 'Nominal deposito minimal Rp '
                    .number_format((float) $product->min_amount, 0, ',', '.')
                    .' untuk produk '.$product->name.'.',
            ]);
        }

        // ── Validasi rekening sumber (Simpanan Sukarela) ─────────────────
        $sumber = Rekening::where('id', $sourceId)
            ->where('anggota_id', $memberId)
            ->whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_SUKARELA))
            ->first();

        if (! $sumber) {
            throw ValidationException::withMessages([
                'source_saving_account_id' => 'Rekening Simpanan Sukarela sumber dana tidak ditemukan untuk anggota ini.',
            ]);
        }

        if ($sumber->status_rekening !== 'Aktif') {
            throw ValidationException::withMessages([
                'source_saving_account_id' => 'Rekening Simpanan Sukarela sumber dana berstatus '
                    .$sumber->status_rekening.' dan tidak dapat dipakai.',
            ]);
        }

        if ((float) $sumber->saldo < $principal) {
            throw ValidationException::withMessages([
                'principal_amount' => 'Saldo Rekening Simpanan Sukarela tidak mencukupi. Saldo tersedia Rp '
                    .number_format((float) $sumber->saldo, 0, ',', '.')
                    .', dibutuhkan Rp '.number_format($principal, 0, ',', '.').'.',
            ]);
        }

        // ── Jatuh tempo dihitung sistem (tidak dapat diinput manual) ─────
        $maturityDate = $this->hitungJatuhTempo($startDate, (int) $product->tenor_months);

        return DB::transaction(function () use (
            $memberId, $product, $sumber, $principal, $isAro, $aroMode, $userId, $startDate, $maturityDate
        ) {
            // Kunci baris rekening sumber agar saldo tidak berubah bersamaan.
            $sumber = Rekening::whereKey($sumber->id)->lockForUpdate()->first();

            if ((float) $sumber->saldo < $principal) {
                throw ValidationException::withMessages([
                    'principal_amount' => 'Saldo Rekening Simpanan Sukarela tidak mencukupi pada saat transaksi diproses.',
                ]);
            }

            $saldoSumberSetelah = (float) $sumber->saldo - $principal;
            $sumber->update(['saldo' => $saldoSumberSetelah]);

            // Snapshot nisbah yang berlaku SAAT akad dibuat. Perubahan master
            // CoA/produk di kemudian hari tidak boleh mengubah akad lama.
            $snapshot = $this->snapshotNisbah($product);

            // Rekening Simpanan Berjangka penampung bilyet — agar tetap terlihat
            // pada Portofolio CIF & modul teller yang membaca tabel `rekening`.
            $rekeningDeposito = Rekening::create([
                'no_rekening' => $this->generateNoRekening(),
                'anggota_id' => $memberId,
                'akun_id' => $this->akunIdSimpanan(),
                'jenis_rekening' => JenisSimpanan::namaUtama(JenisSimpanan::TIPE_BERJANGKA),
                'akad' => 'Mudharabah',
                'saldo' => $principal,
                // Jejak nominal pembukaan rekening (tidak berubah saat saldo berjalan berubah).
                'nominal_pembukaan' => $principal,
                'batas_minimum' => 0,
                'nisbah_nasabah' => $snapshot['applied_percentage_member'],
                'nisbah_bank' => $snapshot['applied_percentage_institution'],
                'tanggal_buka' => $startDate->toDateString(),
                'tanggal_jatuh_tempo' => $maturityDate->toDateString(),
                'status_rekening' => 'Aktif',
                'keterangan' => 'Deposito '.$product->name.' — akad Mudharabah Muthlaqah',
            ] + $snapshot);

            $deposito = Deposito::create([
                'deposito_number' => $this->generateDepositoNumber(),
                'member_id' => $memberId,
                'product_id' => $product->id,
                'source_saving_account_id' => $sumber->id,
                'deposito_rekening_id' => $rekeningDeposito->id,
                'principal_amount' => $principal,
                'nisbah_anggota' => $product->nisbah_anggota,
                'nisbah_koperasi' => $product->nisbah_koperasi,
                'start_date' => $startDate->toDateString(),
                'maturity_date' => $maturityDate->toDateString(),
                'is_aro' => $isAro,
                'aro_mode' => $aroMode,
                'status' => Deposito::STATUS_ACTIVE,
            ] + $snapshot);

            $keterangan = "Pembukaan deposito {$deposito->deposito_number} "
                .'(pokok Rp '.number_format($principal, 0, ',', '.').') '
                ."dari Simpanan Sukarela {$sumber->no_rekening}, "
                ."tenor {$product->tenor_months} bulan, jatuh tempo "
                .$maturityDate->format('d/m/Y').'.';

            // Transaksi: dana keluar dari Simpanan Sukarela (posisi Kredit).
            // Konvensi sistem (Transaksi::isMasuk, TransactionLimitService, dan
            // ReversalService): 'Debet' = uang MASUK menambah saldo rekening,
            // 'Kredit' = uang KELUAR mengurangi saldo rekening. Pembukaan
            // deposito memindahkan dana KELUAR dari Sukarela menuju Berjangka.
            $this->catatTransaksi([
                'no_transaksi' => $this->generateNoTransaksi(),
                'rekening_id' => $sumber->id,
                'anggota_id' => $memberId,
                'jenis_transaksi' => 'Pembukaan Deposito Mudharabah',
                'posisi' => 'Kredit',
                'jumlah' => $principal,
                'saldo_setelah' => $saldoSumberSetelah,
                'user_id' => $userId,
                'sumber_transaksi' => 'Auto',
                'catatan' => $keterangan,
            ]);

            // … dan masuk ke rekening Simpanan Berjangka (posisi Debet = masuk).
            $this->catatTransaksi([
                'no_transaksi' => $this->generateNoTransaksi(),
                'rekening_id' => $rekeningDeposito->id,
                'anggota_id' => $memberId,
                'jenis_transaksi' => 'Setoran Simpanan Berjangka',
                'posisi' => 'Debet',
                'jumlah' => $principal,
                'saldo_setelah' => $principal,
                'user_id' => $userId,
                'sumber_transaksi' => 'Auto',
                'catatan' => $keterangan,
            ]);

            // Keterangan header jurnal memakai format baku
            // "Pembukaan Deposito a.n {nama_anggota}" agar mudah ditelusuri
            // dari Buku Besar. Rincian operasional (nomor bilyet, tenor,
            // jatuh tempo) tetap tersimpan pada catatan transaksi dan record
            // deposito sehingga audit trail tidak hilang.
            $keteranganJurnal = 'Pembukaan Deposito a.n '
                .($deposito->member?->nama_lengkap ?? $memberId);

            // Jurnal: DEBET 2-1001 Simpanan Sukarela | KREDIT 2-1003 Simpanan Berjangka
            $jurnal = $this->journaling->jurnalPembukaanDeposito(
                $principal,
                $keteranganJurnal,
                $userId,
                null,
                $rekeningDeposito->id,
                $memberId
            );

            if (! $jurnal) {
                throw new \RuntimeException(
                    'Pembukaan deposito dibatalkan: auto-journaling gagal. '
                    .'Pastikan akun CoA 2-1001 dan 2-1003 tersedia serta aktif.'
                );
            }

            Log::info('DepositoService: deposito dibuka.', [
                'deposito_number' => $deposito->deposito_number,
                'member_id' => $memberId,
                'principal' => $principal,
                'maturity_date' => $maturityDate->toDateString(),
                'no_jurnal' => $jurnal->no_jurnal,
            ]);

            $this->pastikanSubledgerSehat();

            return $deposito->fresh(['product', 'member', 'sourceSavingAccount', 'depositoRekening']);
        });
    }

    /* =====================================================================
     | 3. PENCAIRAN DEPOSITO JATUH TEMPO
     |===================================================================== */

    /**
     * Cairkan deposito yang sudah jatuh tempo: pokok kembali ke Simpanan Sukarela.
     *
     * Seluruh proses atomik — status, saldo, dan jurnal berubah bersama atau
     * tidak sama sekali.
     */
    public function settleMaturedDeposito(Deposito $deposito, ?int $userId = null): Deposito
    {
        return DB::transaction(function () use ($deposito, $userId) {
            $deposito = Deposito::whereKey($deposito->id)->lockForUpdate()->firstOrFail();

            if ($deposito->status !== Deposito::STATUS_ACTIVE) {
                throw new \RuntimeException(
                    "Deposito {$deposito->deposito_number} berstatus {$deposito->status} "
                    .'dan tidak dapat dicairkan.'
                );
            }

            $principal = (float) $deposito->principal_amount;

            $sumber = Rekening::whereKey($deposito->source_saving_account_id)->lockForUpdate()->firstOrFail();
            $saldoSumberSetelah = (float) $sumber->saldo + $principal;

            $keterangan = "Pencairan pokok deposito {$deposito->deposito_number} "
                .'(Rp '.number_format($principal, 0, ',', '.').') '
                ."ke Simpanan Sukarela {$sumber->no_rekening} — jatuh tempo "
                .Carbon::parse($deposito->maturity_date)->format('d/m/Y').'.';

            /* R12 — URUTAN BAKU AKUNTANSI: jurnal DIPOSTING & DIVERIFIKASI lebih
             | dulu, baru saldo dinolkan dan status rekening ditutup. Bila
             | auto-journaling gagal, seluruh transaksi dibatalkan tanpa
             | meninggalkan rekening yang sudah tertutup tanpa jejak jurnal. */
            // DEBET 2-1003 Simpanan Berjangka | KREDIT 2-1001 Simpanan Sukarela
            $jurnal = $this->journaling->jurnalPencairanDeposito(
                $principal,
                $keterangan,
                $userId,
                null,
                $deposito->deposito_rekening_id,
                $deposito->member_id
            );

            if (! $jurnal) {
                throw new \RuntimeException(
                    'Pencairan deposito dibatalkan: auto-journaling gagal. '
                    .'Pastikan akun CoA 2-1003 dan 2-1001 tersedia serta aktif.'
                );
            }

            $this->catatTransaksi([
                'no_transaksi' => $this->generateNoTransaksi(),
                'rekening_id' => $deposito->deposito_rekening_id,
                'anggota_id' => $deposito->member_id,
                'jenis_transaksi' => 'Penarikan Simpanan Berjangka',
                // Saldo Simpanan Berjangka berkurang → posisi Kredit (dana keluar).
                'posisi' => 'Kredit',
                'jumlah' => $principal,
                'saldo_setelah' => 0,
                'user_id' => $userId,
                'sumber_transaksi' => 'Auto',
                'catatan' => $keterangan,
            ]);

            $this->catatTransaksi([
                'no_transaksi' => $this->generateNoTransaksi(),
                'rekening_id' => $sumber->id,
                'anggota_id' => $deposito->member_id,
                'jenis_transaksi' => 'Pencairan Deposito Mudharabah',
                // Dana masuk kembali ke Simpanan Sukarela → posisi Debet (masuk).
                'posisi' => 'Debet',
                'jumlah' => $principal,
                'saldo_setelah' => $saldoSumberSetelah,
                'user_id' => $userId,
                'sumber_transaksi' => 'Auto',
                'catatan' => $keterangan,
            ]);

            $deposito->update(['status' => Deposito::STATUS_MATURED]);

            // Pokok dikembalikan ke saldo Simpanan Sukarela anggota.
            $sumber->update(['saldo' => $saldoSumberSetelah]);

            // Rekening Simpanan Berjangka ditutup karena dananya sudah dicairkan.
            if ($deposito->deposito_rekening_id) {
                Rekening::whereKey($deposito->deposito_rekening_id)->update([
                    'saldo' => 0,
                    'status_rekening' => 'Ditutup',
                ]);
            }

            Log::info('DepositoService: deposito dicairkan.', [
                'deposito_number' => $deposito->deposito_number,
                'principal' => $principal,
                'no_jurnal' => $jurnal->no_jurnal,
            ]);

            $this->pastikanSubledgerSehat();

            return $deposito->fresh(['product', 'member', 'sourceSavingAccount', 'profitSharings']);
        });
    }

    /* =====================================================================
     | 3c. PEMROSESAN JATUH TEMPO DINAMIS (ARO — AUTOMATIC ROLL OVER)
     |===================================================================== */

    /**
     * Proses satu deposito yang jatuh tempo sesuai mode ARO pada bilyet.
     *
     * Mode (kolom `depositos.aro_mode`) menentukan perlakuan pokok & bagi hasil:
     *
     *  - NON_ARO   → pokok + bagi hasil cair ke Simpanan Sukarela (2-1001),
     *                status deposito menjadi CLOSED.
     *  - ARO_POKOK → bagi hasil ditransfer ke Simpanan Sukarela (2-1001),
     *                pokok tetap mengendap; jatuh tempo diperpanjang tenor awal.
     *  - ARO_PLUS  → bagi hasil yang sudah masuk Simpanan Sukarela DITARIK
     *                kembali menjadi pokok baru (kapitalisasi / reinvestasi),
     *                jatuh tempo diperpanjang tenor awal.
     *
     * Pada dua mode ARO, `roll_count` bertambah 1 dan `last_rolled_at` diisi
     * waktu eksekusi sebagai jejak audit.
     *
     * SELURUH aliran dana mengikuti prinsip akad Mudharabah: pokok & bagi hasil
     * selalu bermuara pada rekening Simpanan Sukarela anggota (2-1001), sehingga
     * dana yang diinvestasikan kembali hanya berpindah antar rekening milik
     * anggota yang sama. Karena itu dana bagi hasil yang dikapitalisasi harus
     * SUDAH berada di rekening anggota (atau diselesaikan lebih dulu) sebelum
     * dikapitalisasi — jurnal kapitalisasi hanya mereklasifikasi kewajiban
     * 2-1001 menjadi Dana Syirkah Temporer 2-1003.
     *
     * @return array{deposito: Deposito, mode: string, pokok: float, pokok_baru: float,
     *               bagi_hasil_ditransfer: float, bagi_hasil_dikapitalisasi: float,
     *               status: string, jurnal: Jurnal|null}
     */
    public function processMaturity(Deposito $deposito, ?int $userId = null): array
    {
        return DB::transaction(function () use ($deposito, $userId) {
            $deposito = Deposito::with('product')
                ->whereKey($deposito->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($deposito->status !== Deposito::STATUS_ACTIVE) {
                throw new \RuntimeException(
                    "Deposito {$deposito->deposito_number} berstatus {$deposito->status} "
                    .'dan tidak dapat diproses jatuh tempo.'
                );
            }

            $produk = $deposito->product;

            if (! $produk) {
                throw new \RuntimeException(
                    "Produk deposito {$deposito->deposito_number} tidak ditemukan."
                );
            }

            $mode = in_array($deposito->aro_mode, Deposito::ARO_MODE, true)
                ? $deposito->aro_mode
                : Deposito::ARO_NON;
            $pokok = Setting::bulatkanUang((float) $deposito->principal_amount);

            $sumber = Rekening::whereKey($deposito->source_saving_account_id)
                ->lockForUpdate()
                ->firstOrFail();

            // ── 1. SELESAIKAN BAGI HASIL TERTUNDA.
            //      Bagi hasil bulanan pada sistem ini sudah dikreditkan nyata ke
            //      Simpanan Sukarela saat `distributeProfitSharing()` dijalankan.
            //      Baris yang tercatat namun belum berjurnal diselesaikan lebih
            //      dahulu agar SELURUH bagi hasil akumulasi benar-benar berada di
            //      rekening anggota sebelum bilyet dicairkan (NON_ARO) atau
            //      dikapitalisasi (ARO_PLUS).
            $bagiHasilTertunda = $this->selesaikanBagiHasilTertunda($deposito, $sumber, $userId);

            // ── 2. Porsi bagi hasil yang sudah berada di rekening Simpanan Sukarela
            //      anggota sejak perpanjangan terakhir — kandidat kapitalisasi
            //      ARO_PLUS. Dibatasi `last_rolled_at` agar porsi yang sudah
            //      pernah dikapitalisasi tidak dikapitalisasi ulang.
            $bagiHasilKePokok = $mode === Deposito::ARO_PLUS
                ? $deposito->bagiHasilSiapKapitalisasi()
                : 0.0;

            $pokokBaru = Setting::bulatkanUang($pokok + $bagiHasilKePokok);

            // ── 3. Eksekusi sesuai mode.
            if ($mode === Deposito::ARO_NON) {
                $jurnal = $this->cairkanPenuhNonAro($deposito, $sumber, $pokok, $userId);
            } else {
                $jurnal = $this->perpanjangAro(
                    $deposito, $produk, $sumber, $mode, $pokok, $pokokBaru,
                    $bagiHasilKePokok, $userId
                );
            }

            $deposito->refresh();

            Log::info('DepositoService: jatuh tempo diproses.', [
                'deposito_number' => $deposito->deposito_number,
                'mode' => $mode,
                'pokok' => $pokok,
                'pokok_baru' => $pokokBaru,
                'bagi_hasil_tertunda' => $bagiHasilTertunda,
                'status_akhir' => $deposito->status,
                'no_jurnal' => $jurnal?->no_jurnal,
            ]);

            return [
                'deposito' => $deposito->loadMissing(['product', 'member', 'sourceSavingAccount', 'profitSharings']),
                'mode' => $mode,
                'pokok' => $pokok,
                'pokok_baru' => $pokokBaru,
                'bagi_hasil_tertunda' => $bagiHasilTertunda,
                'bagi_hasil_dikapitalisasi' => $bagiHasilKePokok,
                'status' => $deposito->status,
                'jurnal' => $jurnal,
            ];
        });
    }

    /**
     * Bayarkan bagi hasil yang tercatat namun belum berjurnal ke Simpanan
     * Sukarela anggota (DEBET 5-3001 Beban Bagi Hasil | KREDIT 2-1001),
     * lalu tandai barisnya sebagai lunas beserta jurnalnya.
     *
     * @return float Total bagi hasil yang diselesaikan.
     */
    private function selesaikanBagiHasilTertunda(
        Deposito $deposito,
        Rekening $sumber,
        ?int $userId
    ): float {
        $tertunda = $deposito->bagiHasilBelumDibayar();

        if ($tertunda->isEmpty()) {
            return 0.0;
        }

        $total = Setting::bulatkanUang((float) $tertunda->sum('member_profit_amount'));

        if ($total <= 0) {
            return 0.0;
        }

        $keterangan = "Penyelesaian bagi hasil tertunda deposito {$deposito->deposito_number} — Rp "
            .number_format($total, 0, ',', '.')
            ." ke Simpanan Sukarela {$sumber->no_rekening}.";

        // DEBET 5-3001 Beban Bagi Hasil | KREDIT 2-1001 Simpanan Sukarela
        $jurnal = $this->journaling->jurnalTransferBagiHasilKeSukarela(
            $total,
            $keterangan,
            $userId,
            null,
            $sumber->id,
            $deposito->member_id
        );

        if (! $jurnal) {
            throw new \RuntimeException(
                "Jurnal penyelesaian bagi hasil deposito {$deposito->deposito_number} gagal dibuat."
            );
        }

        $saldoBaru = Setting::bulatkanUang((float) $sumber->saldo + $total);
        $sumber->update(['saldo' => $saldoBaru]);

        $this->catatTransaksi([
            'no_transaksi' => $this->generateNoTransaksi(),
            'rekening_id' => $sumber->id,
            'anggota_id' => $deposito->member_id,
            'jenis_transaksi' => 'Bagi Hasil Deposito',
            'posisi' => 'Debet',
            'jumlah' => $total,
            'saldo_setelah' => $saldoBaru,
            'user_id' => $userId,
            'sumber_transaksi' => 'Auto',
            'catatan' => $keterangan,
        ]);

        foreach ($tertunda as $baris) {
            $baris->update([
                'status' => DepositoProfitSharing::STATUS_PAID,
                'journal_id' => $jurnal->id,
                'payout_date' => $baris->payout_date ?: now(),
            ]);
        }

        return $total;
    }

    /**
     * Jalur NON_ARO: pokok dicairkan ke Simpanan Sukarela dan bilyet ditutup
     * (status CLOSED).
     */
    private function cairkanPenuhNonAro(
        Deposito $deposito,
        Rekening $sumber,
        float $pokok,
        ?int $userId
    ): ?Jurnal {
        $saldoSumberBaru = Setting::bulatkanUang((float) $sumber->saldo + $pokok);

        $keterangan = "Pencairan deposito {$deposito->deposito_number} (NON-ARO) — pokok Rp "
            .number_format($pokok, 0, ',', '.')
            ." ke Simpanan Sukarela {$sumber->no_rekening}, jatuh tempo "
            .Carbon::parse($deposito->maturity_date)->format('d/m/Y').'.';

        /* R12 — URUTAN BAKU AKUNTANSI: jurnal diposting & diverifikasi lebih dulu,
         | baru saldo/status diperbarui (lihat `settleMaturedDeposito()`). */
        // DEBET 2-1003 Simpanan Berjangka | KREDIT 2-1001 Simpanan Sukarela
        $jurnal = $this->journaling->jurnalPencairanDeposito(
            $pokok,
            $keterangan,
            $userId,
            null,
            $deposito->deposito_rekening_id,
            $deposito->member_id
        );

        if (! $jurnal) {
            throw new \RuntimeException(
                'Pencairan deposito dibatalkan: auto-journaling gagal. '
                .'Pastikan akun CoA 2-1003 dan 2-1001 tersedia serta aktif.'
            );
        }

        $this->catatTransaksi([
            'no_transaksi' => $this->generateNoTransaksi(),
            'rekening_id' => $deposito->deposito_rekening_id,
            'anggota_id' => $deposito->member_id,
            'jenis_transaksi' => 'Penarikan Simpanan Berjangka',
            // Saldo Simpanan Berjangka berkurang → posisi Kredit (dana keluar).
            'posisi' => 'Kredit',
            'jumlah' => $pokok,
            'saldo_setelah' => 0,
            'user_id' => $userId,
            'sumber_transaksi' => 'Auto',
            'catatan' => $keterangan,
        ]);

        $this->catatTransaksi([
            'no_transaksi' => $this->generateNoTransaksi(),
            'rekening_id' => $sumber->id,
            'anggota_id' => $deposito->member_id,
            'jenis_transaksi' => 'Pencairan Deposito Mudharabah',
            'posisi' => 'Kredit',
            'jumlah' => $pokok,
            'saldo_setelah' => $saldoSumberBaru,
            'user_id' => $userId,
            'sumber_transaksi' => 'Auto',
            'catatan' => $keterangan,
        ]);

        $deposito->update(['status' => Deposito::STATUS_CLOSED]);
        $sumber->update(['saldo' => $saldoSumberBaru]);

        if ($deposito->deposito_rekening_id) {
            Rekening::whereKey($deposito->deposito_rekening_id)->update([
                'saldo' => 0,
                'status_rekening' => 'Ditutup',
            ]);
        }

        return $jurnal;
    }

    /**
     * Jalur ARO_POKOK & ARO_PLUS: bilyet diperpanjang, bukan dicairkan.
     */
    private function perpanjangAro(
        Deposito $deposito,
        DepositoProduct $produk,
        Rekening $sumber,
        string $mode,
        float $pokok,
        float $pokokBaru,
        float $bagiHasilKePokok,
        ?int $userId
    ): ?Jurnal {
        $tenor = (int) $produk->tenor_months;
        $jatuhTempo = Carbon::parse($deposito->maturity_date)->startOfDay();
        $jatuhTempoBaru = $this->hitungJatuhTempo($jatuhTempo, $tenor);

        $jurnal = null;

        // ── ARO_PLUS: bagi hasil di rekening anggota ditarik menjadi pokok baru.
        if ($bagiHasilKePokok > 0) {
            $saldoSukarelaBaru = Setting::bulatkanUang((float) $sumber->saldo - $bagiHasilKePokok);

            if ($saldoSukarelaBaru < 0) {
                throw new \RuntimeException(
                    "Perpanjangan ARO_PLUS dibatalkan: saldo Simpanan Sukarela {$sumber->no_rekening} "
                    .'tidak mencukupi untuk kapitalisasi bagi hasil Rp '
                    .number_format($bagiHasilKePokok, 0, ',', '.').'.'
                );
            }

            $sumber->update(['saldo' => $saldoSukarelaBaru]);

            $keterangan = "Kapitalisasi bagi hasil deposito {$deposito->deposito_number} (ARO_PLUS) — Rp "
                .number_format($bagiHasilKePokok, 0, ',', '.')
                .' menjadi pokok baru Rp '.number_format($pokokBaru, 0, ',', '.').'.';

            $this->catatTransaksi([
                'no_transaksi' => $this->generateNoTransaksi(),
                'rekening_id' => $sumber->id,
                'anggota_id' => $deposito->member_id,
                'jenis_transaksi' => 'Kapitalisasi Bagi Hasil Deposito',
                // Debit dari Simpanan Sukarela (posisi Kredit = dana KELUAR
                // dari rekening Sukarela anggota untuk menambah pokok deposito).
                'posisi' => 'Kredit',
                'jumlah' => $bagiHasilKePokok,
                'saldo_setelah' => $saldoSukarelaBaru,
                'user_id' => $userId,
                'sumber_transaksi' => 'Auto',
                'catatan' => $keterangan,
            ]);

            // DEBET 2-1001 Simpanan Sukarela | KREDIT 2-1003 Simpanan Berjangka
            $jurnal = $this->journaling->jurnalKapitalisasiBagiHasilDeposito(
                $bagiHasilKePokok,
                $keterangan,
                $userId,
                null,
                $sumber->id,
                $deposito->member_id
            );

            if (! $jurnal) {
                throw new \RuntimeException(
                    'Perpanjangan ARO_PLUS dibatalkan: auto-journaling kapitalisasi gagal. '
                    .'Pastikan akun CoA 2-1001 dan 2-1003 tersedia serta aktif.'
                );
            }
        }

        // ── Perpanjangan bilyet: jatuh tempo + tenor awal, catat jejak roll.
        $rollKe = (int) $deposito->roll_count + 1;

        $deposito->update([
            'principal_amount' => $pokokBaru,
            'maturity_date' => $jatuhTempoBaru->toDateString(),
            'roll_count' => $rollKe,
            'last_rolled_at' => now(),
            'status' => Deposito::STATUS_ACTIVE,
        ]);

        if ($deposito->deposito_rekening_id) {
            Rekening::whereKey($deposito->deposito_rekening_id)->update([
                'saldo' => $pokokBaru,
                'status_rekening' => 'Aktif',
            ]);
        }

        $keteranganRoll = "Perpanjangan otomatis (ARO) deposito {$deposito->deposito_number} mode {$mode} — "
            .'pokok Rp '.number_format($pokok, 0, ',', '.')
            .' → Rp '.number_format($pokokBaru, 0, ',', '.')
            .', jatuh tempo baru '.$jatuhTempoBaru->format('d/m/Y')
            .' (roll ke-'.$rollKe.').';

        $this->catatTransaksi([
            'no_transaksi' => $this->generateNoTransaksi(),
            'rekening_id' => $deposito->deposito_rekening_id,
            'anggota_id' => $deposito->member_id,
            'jenis_transaksi' => 'Perpanjangan Deposito (ARO)',
            // Pokok deposito di-set ke nilai baru (saldo_setelah = pokokBaru);
            // saldo simpanan berjangka bertambah/berputar → posisi Debet (masuk).
            'posisi' => 'Debet',
            'jumlah' => $pokokBaru,
            'saldo_setelah' => $pokokBaru,
            'user_id' => $userId,
            'sumber_transaksi' => 'Auto',
            'catatan' => $keteranganRoll,
        ]);

        return $jurnal;
    }

    /* =====================================================================
     | 3b. PENCAIRAN DINI (BREAK DEPOSITO)
     |===================================================================== */

    /**
     * Cairkan deposito SEBELUM jatuh tempo (break deposito).
     *
     * Biaya penalti dihitung DINAMIS dari aturan produk (kolom `deposito_products`):
     *  - tipe 'fixed'      → nominal `penalty_fee_amount` (rupiah)
     *  - tipe 'percentage' → pokok × `penalty_fee_amount` / 100
     *
     * Bila `forfeit_monthly_profit` aktif, bagi hasil bulanan yang belum
     * dibayarkan kepada anggota dibatalkan (hangus) sesuai kesepakatan akad.
     *
     * R18 — Keringanan penalti: bila terdapat pengajuan keringanan yang SUDAH
     * disetujui Manajer dan belum pernah dipakai (`deposito_penalty_reliefs`),
     * nominal tersebut dikurangkan dari biaya penalti (tidak pernah negatif).
     * Keringanan ditandai terpakai (`dipakai_at`) agar tidak dapat dipakai dua kali.
     *
     * Auto-journal (PSAK 101/108), seluruhnya dalam satu transaksi atomik:
     *  DEBET  : 2-1003 Simpanan Berjangka         = Total Pokok yang dicairkan
     *  KREDIT : 2-3001 Dana Kebajikan             = Total Biaya Penalti (ta'zir)
     *  KREDIT : 2-1001 Simpanan Sukarela          = Pokok − Biaya Penalti
     *
     * @return array{deposito: Deposito, pokok: float, penalti_awal: float, keringanan: DepositoPenaltyRelief|null, penalti: float, diterima_anggota: float, jurnal: Jurnal|null}
     */
    public function earlyWithdrawal(int $depositoId, ?int $userId = null): array
    {
        return DB::transaction(function () use ($depositoId, $userId) {
            $deposito = Deposito::with('product')
                ->whereKey($depositoId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($deposito->status !== Deposito::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'deposito' => "Deposito {$deposito->deposito_number} berstatus {$deposito->status} "
                        .'dan tidak dapat dicairkan sebelum jatuh tempo.',
                ]);
            }

            $product = $deposito->product;

            if (! $product) {
                throw ValidationException::withMessages([
                    'deposito' => "Produk deposito {$deposito->deposito_number} tidak ditemukan.",
                ]);
            }

            if (! $product->allow_early_withdrawal) {
                throw ValidationException::withMessages([
                    'deposito' => "Produk {$product->name} tidak mengizinkan pencairan sebelum jatuh tempo.",
                ]);
            }

            // ── Perhitungan biaya penalti (dinamis dari aturan produk) ─────
            $pokok = Setting::bulatkanUang((float) $deposito->principal_amount);
            $penaltiAwal = $product->hitungPenalti($pokok);

            // ── Keringanan penalti yang telah disetujui (bila ada) ─────────
            // R18: keringanan hanya berlaku bila benar-benar disetujui Manajer
            // melalui jalur banding berjenjang dan belum pernah dipakai.
            $keringanan = DepositoPenaltyRelief::siapDipakaiUntuk($deposito->id);
            $penalti = $penaltiAwal;

            if ($keringanan) {
                $nominalKeringanan = Setting::bulatkanUang((float) $keringanan->nominal_penalti_disetujui);
                $penalti = Setting::bulatkanUang(max(0.0, $penaltiAwal - $nominalKeringanan));
                // Penandaan `dipakai_at` diletakkan di AKHIR transaksi agar bila ada
                // langkah lanjutan yang gagal (mis. jurnal), keringanan tidak ikut
                // ditandai terpakai padahal rollback membatalkan pencairannya.
            }

            if ($penalti >= $pokok) {
                throw ValidationException::withMessages([
                    'deposito' => 'Biaya penalti ('.number_format($penalti, 0, ',', '.')
                        .') tidak boleh sama atau melebihi pokok deposito.',
                ]);
            }

            $diterimaAnggota = Setting::bulatkanUang($pokok - $penalti);

            // ── Rekening sumber (Simpanan Sukarela) ────────────────────────
            $sumber = Rekening::whereKey($deposito->source_saving_account_id)
                ->lockForUpdate()
                ->firstOrFail();

            $saldoSumberBaru = Setting::bulatkanUang((float) $sumber->saldo + $diterimaAnggota);

            // ── Hanguskan bagi hasil bulanan yang belum dibayar ────────────
            if ($product->forfeit_monthly_profit) {
                $deposito->profitSharings()
                    ->where('status', '!=', DepositoProfitSharing::STATUS_PAID)
                    ->update(['status' => DepositoProfitSharing::STATUS_FORFEITED]);
            }

            $deposito->update(['status' => Deposito::STATUS_CLOSED]);
            $sumber->update(['saldo' => $saldoSumberBaru]);

            // Rekening Simpanan Berjangka ditutup — dana sudah dicairkan.
            if ($deposito->deposito_rekening_id) {
                Rekening::whereKey($deposito->deposito_rekening_id)->update([
                    'saldo' => 0,
                    'status_rekening' => 'Ditutup',
                ]);
            }

            $keterangan = "Pencairan dini deposito {$deposito->deposito_number} — penalti Rp "
                .number_format($penalti, 0, ',', '.').' ('.$product->penalti_label.').';

            if ($keringanan) {
                $keterangan .= ' Keringanan penalti Rp '
                    .number_format((float) $keringanan->nominal_penalti_disetujui, 0, ',', '.')
                    .' telah disetujui Manajer (pengajuan #'.$keringanan->id.').';
            }

            // Catat mutasi keluar dari rekening Simpanan Berjangka.
            // Simpan referensinya: jurnal pencairan dini HARUS ditautkan ke
            // transaksi ini (bukan null). Tanpa tautan, ReversalService tidak
            // dapat menemukan jurnal asal lewat relasi `Transaksi::jurnal`,
            // sehingga nominal ta'zir tidak terbaca saat void dan Dana
            // Kebajikan (2-3001) tidak pernah dipulihkan.
            $transaksiBerjangka = $this->catatTransaksi([
                'no_transaksi' => $this->generateNoTransaksi(),
                'rekening_id' => $deposito->deposito_rekening_id,
                'anggota_id' => $deposito->member_id,
                'jenis_transaksi' => 'Pencairan Dini Deposito Mudharabah',
                'posisi' => 'Kredit',
                'jumlah' => $pokok,
                'saldo_setelah' => 0,
                'user_id' => $userId,
                'sumber_transaksi' => 'Auto',
                'catatan' => $keterangan,
            ]);

            // Catat mutasi masuk (bersih setelah penalti) ke Simpanan Sukarela.
            $this->catatTransaksi([
                'no_transaksi' => $this->generateNoTransaksi(),
                'rekening_id' => $sumber->id,
                'anggota_id' => $deposito->member_id,
                'jenis_transaksi' => 'Pencairan Dini Deposito Mudharabah',
                'posisi' => 'Debet',
                'jumlah' => $diterimaAnggota,
                'saldo_setelah' => $saldoSumberBaru,
                'user_id' => $userId,
                'sumber_transaksi' => 'Auto',
                'catatan' => $keterangan,
            ]);

            // Jurnal: DEBET 2-1003 | KREDIT penalty_coa_id | KREDIT 2-1001
            // `transaksi_id` diisi dengan leg Simpanan Berjangka (bukan null)
            // supaya jurnal asal dapat ditemukan kembali lewat relasi
            // `Transaksi::jurnal` ketika transaksi divoid — ReversalService
            // memakainya untuk membaca nominal ta'zir yang harus dipulihkan.
            $jurnal = $this->journaling->jurnalPenaltiDeposito(
                $pokok,
                $penalti,
                $keterangan,
                $userId,
                $transaksiBerjangka->id,
                $deposito->deposito_rekening_id,
                $deposito->member_id,
                $product->coaPenalti()
            );

            if (! $jurnal) {
                throw new \RuntimeException(
                    'Pencairan dini dibatalkan: auto-journaling gagal. '
                    .'Pastikan akun CoA 2-1003, 2-1001, dan '
                    .$product->coaPenalti().' tersedia serta aktif.'
                );
            }

            Log::info('DepositoService: deposito dicairkan sebelum jatuh tempo.', [
                'deposito_number' => $deposito->deposito_number,
                'pokok' => $pokok,
                'penalti_awal' => $penaltiAwal,
                'keringanan_id' => $keringanan?->id,
                'penalti' => $penalti,
                'diterima' => $diterimaAnggota,
                'no_jurnal' => $jurnal->no_jurnal,
            ]);

            // R18: tandai keringanan terpakai SETELAH seluruh langkah (jurnal)
            // sukses, sehingga sekali-pakai benar-benar terjadi tepat satu kali.
            if ($keringanan) {
                $keringanan->update(['dipakai_at' => now()]);
            }

            $this->pastikanSubledgerSehat();

            return [
                'deposito' => $deposito->fresh(['product', 'member', 'sourceSavingAccount', 'profitSharings']),
                'pokok' => $pokok,
                'penalti_awal' => $penaltiAwal,
                'keringanan' => $keringanan,
                'penalti' => $penalti,
                'diterima_anggota' => $diterimaAnggota,
                'jurnal' => $jurnal,
            ];
        });
    }

    /* =====================================================================
     | 4. PEMBAGIAN BAGI HASIL BULANAN
     |===================================================================== */

    /**
     * Bagikan bagi hasil satu periode bulan ke saldo Simpanan Sukarela anggota.
     *
     * @param  float  $grossRevenue  Pendapatan koperasi atas penempatan dana periode ini.
     *
     * Porsi anggota = grossRevenue × nisbah_anggota%. Porsi koperasi TIDAK
     * dicatat sebagai beban — hanya porsi anggota yang menjadi beban bagi hasil.
     */
    public function distributeProfitSharing(
        Deposito $deposito,
        int $year,
        int $month,
        float $grossRevenue,
        ?int $userId = null
    ): DepositoProfitSharing {
        // Batas bulan mengikuti JUMLAH BULAN TAHUN BUKU (dapat diubah pengurus
        // lewat parameter kebijakan), bukan asumsi kaku Januari–Desember.
        // `bulanKe($year, $month)` memetakan urutan bulan tahun buku → bulan
        // kalender, sehingga tahun buku non-kalender (mis. Juli–Juni) sah.
        $jumlahBulan = app(TahunBukuService::class)->jumlahBulan();
        if ($month < 1 || $month > $jumlahBulan) {
            throw ValidationException::withMessages([
                'period_month' => "Bulan periode bagi hasil harus antara 1 dan {$jumlahBulan} (jumlah bulan tahun buku).",
            ]);
        }

        if ($deposito->status !== Deposito::STATUS_ACTIVE) {
            throw new \RuntimeException(
                'Bagi hasil hanya dapat dibagikan untuk deposito ACTIVE. '
                ."Deposito {$deposito->deposito_number} berstatus {$deposito->status}."
            );
        }

        return DB::transaction(function () use ($deposito, $year, $month, $grossRevenue, $userId) {
            $sudahDibayar = DepositoProfitSharing::where('deposito_id', $deposito->id)
                ->where('period_year', $year)
                ->where('period_month', $month)
                ->exists();

            if ($sudahDibayar) {
                throw new \RuntimeException(
                    "Bagi hasil deposito {$deposito->deposito_number} periode "
                    .str_pad((string) $month, 2, '0', STR_PAD_LEFT)."/{$year} sudah pernah dibayarkan."
                );
            }

            $grossRevenue = Setting::bulatkanUang($grossRevenue);
            $porsiAnggota = Setting::bulatkanUang($grossRevenue * (float) $deposito->nisbah_anggota / 100);

            $sumber = Rekening::whereKey($deposito->source_saving_account_id)->lockForUpdate()->firstOrFail();
            $saldoSetelah = (float) $sumber->saldo + $porsiAnggota;

            $keterangan = "Bagi hasil deposito {$deposito->deposito_number} periode "
                .str_pad((string) $month, 2, '0', STR_PAD_LEFT)."/{$year} — nisbah anggota "
                .(float) $deposito->nisbah_anggota.'% dari pendapatan Rp '
                .number_format($grossRevenue, 0, ',', '.').'.';

            // Jurnal: DEBET 5-3001 Beban Bagi Hasil | KREDIT 2-1001 Simpanan Sukarela
            $jurnal = null;
            if ($porsiAnggota > 0) {
                $jurnal = $this->journaling->jurnalBagiHasilDepositoMudharabah(
                    $porsiAnggota,
                    $keterangan,
                    $userId,
                    null,
                    $sumber->id,
                    $deposito->member_id
                );

                if (! $jurnal) {
                    throw new \RuntimeException(
                        'Bagi hasil deposito dibatalkan: auto-journaling gagal. '
                        .'Pastikan akun CoA 5-3001 dan 2-1001 tersedia serta aktif.'
                    );
                }

                $sumber->update(['saldo' => $saldoSetelah]);

                $this->catatTransaksi([
                    'no_transaksi' => $this->generateNoTransaksi(),
                    'rekening_id' => $sumber->id,
                    'anggota_id' => $deposito->member_id,
                    'jenis_transaksi' => 'Bagi Hasil Deposito Mudharabah',
                    // Bagi hasil menambah saldo Simpanan Sukarela → posisi Debet (masuk).
                    'posisi' => 'Debet',
                    'jumlah' => $porsiAnggota,
                    'saldo_setelah' => $saldoSetelah,
                    'user_id' => $userId,
                    'sumber_transaksi' => 'Auto',
                    'catatan' => $keterangan,
                ]);
            }

            $sharing = DepositoProfitSharing::create([
                'deposito_id' => $deposito->id,
                'period_month' => $month,
                'period_year' => $year,
                'gross_koperasi_revenue' => $grossRevenue,
                'member_profit_amount' => $porsiAnggota,
                'payout_date' => now(),
                'status' => DepositoProfitSharing::STATUS_PAID,
                'journal_id' => $jurnal?->id,
            ]);

            Log::info('DepositoService: bagi hasil deposito dibagikan.', [
                'deposito_number' => $deposito->deposito_number,
                'periode' => str_pad((string) $month, 2, '0', STR_PAD_LEFT)."/{$year}",
                'porsi_anggota' => $porsiAnggota,
                'no_jurnal' => $jurnal?->no_jurnal,
            ]);

            /* Jejak audit distribusi bagi hasil (Mudharabah). Ditulis DI DALAM
             | transaksi: bagi hasil yang tidak menyentuh saldo (porsi Rp 0)
             | tidak menghasilkan jurnal, dan transaksi yang digulung tidak boleh
             | meninggalkan jejak "sudah dibagikan". Nomor rekening sumber dipakai
             | sebagai objek audit karena imbalan mengalir ke rekening itu. */
            AuditLog::record(
                'Bagi Hasil — Deposito Mudharabah',
                $porsiAnggota,
                $sumber->no_rekening ?? '-',
                'Bagi hasil deposito '.$deposito->deposito_number.' periode '
                .str_pad((string) $month, 2, '0', STR_PAD_LEFT)."/{$year} dibagikan: porsi anggota Rp "
                .number_format($porsiAnggota, 2, ',', '.').' dari pendapatan Rp '
                .number_format($grossRevenue, 2, ',', '.').' (nisbah anggota '
                .(float) $deposito->nisbah_anggota.'%), jurnal #'.($jurnal?->id ?? '-').'.'
            );

            $this->pastikanSubledgerSehat();

            return $sharing;
        });
    }

    /* =====================================================================
     | 4b. BAGI HASIL PERIODE DARI LABA RIIL (R8)
     |===================================================================== */

    /**
     * R8 — Bagikan bagi hasil satu periode ke SELURUH deposito ACTIVE.
     *
     * Bagi hasil dihitung dari PENDAPATAN INVESTASI RIIL periode (bukan angka
     * yang diketik manual). Pendapatan dialokasikan ke tiap deposito secara
     * PROPORSIONAL terhadap `principal_amount`-nya (porsi dana anggota dalam
     * pool investasi), lalu dikalikan nisbah anggota deposito. Deposito yang
     * periodenya sudah pernah dibayar dilewati (idempoten).
     *
     * @param  float  $totalPendapatan  Pendapatan investasi riil koperasi periode ini.
     * @return array{periode: string, total_pendapatan: float, total_pokok: float,
     *               dibagikan: int, dilewati: int, total_porsi_anggota: float,
     *               tanpa_sumber: int}
     */
    public function bagiHasilPeriode(
        int $year,
        int $month,
        float $totalPendapatan,
        ?int $userId = null
    ): array {
        // Batas bulan mengikuti jumlah bulan tahun buku (zero-hardcoding),
        // sama seperti distributeProfitSharing() dan PendapatanService.
        $jumlahBulan = app(TahunBukuService::class)->jumlahBulan();
        if ($month < 1 || $month > $jumlahBulan) {
            throw ValidationException::withMessages([
                'period_month' => "Bulan periode bagi hasil harus antara 1 dan {$jumlahBulan} (jumlah bulan tahun buku).",
            ]);
        }

        if ($totalPendapatan < 0) {
            throw ValidationException::withMessages([
                'total_pendapatan' => 'Pendapatan investasi periode tidak boleh negatif.',
            ]);
        }

        $depositos = Deposito::query()
            ->where('status', Deposito::STATUS_ACTIVE)
            ->whereNotNull('source_saving_account_id')
            ->orderBy('id')
            ->get();

        $totalPokok = Setting::bulatkanUang((float) $depositos->sum('principal_amount'));

        $hasil = [
            'periode' => str_pad((string) $month, 2, '0', STR_PAD_LEFT)."/{$year}",
            'total_pendapatan' => Setting::bulatkanUang($totalPendapatan),
            'total_pokok' => $totalPokok,
            'dibagikan' => 0,
            'dilewati' => 0,
            'total_porsi_anggota' => 0.0,
            'tanpa_sumber' => 0,
        ];

        if ($totalPokok <= 0 || $totalPendapatan <= 0) {
            return $hasil;
        }

        // F-09 — Alokasi proporsional dengan metode largest remainder.
        //
        // Membulatkan porsi tiap deposito secara terpisah (round per-item)
        // membuat jumlah seluruh porsi berpotensi BEDA dari $totalPendapatan
        // (selisih beberapa sen). Metode largest remainder menjamin:
        //   Σ porsi = $totalPendapatan  (persis, tanpa kehilangan/kelebihan sen).
        // Caranya: hitung porsi ideal tanpa pembulatan, ambil floor (satuan sen)
        // per deposito, lalu bagikan sisa sen kepada deposito dengan pecahan
        // (remainder) terbesar.
        $porsiDeposito = $this->alokasiPorsiBagiHasil($depositos, $totalPendapatan);

        foreach ($depositos as $deposito) {
            $sudahDibayar = DepositoProfitSharing::where('deposito_id', $deposito->id)
                ->where('period_year', $year)
                ->where('period_month', $month)
                ->exists();

            if ($sudahDibayar) {
                $hasil['dilewati']++;

                continue;
            }

            $porsiRevenue = $porsiDeposito[$deposito->id] ?? 0.0;

            $sharing = $this->distributeProfitSharing($deposito, $year, $month, $porsiRevenue, $userId);

            $hasil['dibagikan']++;
            $hasil['total_porsi_anggota'] = Setting::bulatkanUang(
                $hasil['total_porsi_anggota'] + (float) $sharing->member_profit_amount);
        }

        Log::info('DepositoService: bagi hasil periode dari laba riil selesai.', $hasil);

        return $hasil;
    }

    /**
     * F-09 — Bagi $totalPendapatan secara proporsional terhadap pokok tiap
     * deposito memakai metode largest remainder, sehingga Σ porsi sama persis
     * dengan $totalPendapatan (tidak ada selisih sen akibat pembulatan).
     *
     * @param  Collection<int, Deposito>  $depositos
     * @return array<int, float> Peta [deposito_id => porsi dalam rupiah]
     */
    private function alokasiPorsiBagiHasil($depositos, float $totalPendapatan): array
    {
        // Bekerja dalam satuan sen agar bebas galat pembulatan floating point.
        $totalSen = (int) round($totalPendapatan * 100);
        $pokokSen = [];
        $pokokTotalSen = 0;

        foreach ($depositos as $deposito) {
            $sen = (int) round((float) $deposito->principal_amount * 100);
            $pokokSen[$deposito->id] = $sen;
            $pokokTotalSen += $sen;
        }

        if ($pokokTotalSen <= 0 || $totalSen <= 0) {
            return [];
        }

        $alokasi = [];
        $sisa = [];
        $terpakai = 0;

        foreach ($depositos as $deposito) {
            // Porsi ideal dalam sen (tanpa pembulatan), lalu ambil floor-nya.
            $ideal = $totalSen * $pokokSen[$deposito->id] / $pokokTotalSen;
            $dasar = (int) floor($ideal);

            $alokasi[$deposito->id] = $dasar;
            $sisa[$deposito->id] = $ideal - $dasar;
            $terpakai += $dasar;
        }

        // Bagikan sisa sen kepada deposito dengan pecahan (remainder) terbesar
        // agar total alokasi kembali persis $totalSen.
        $kurang = $totalSen - $terpakai;
        if ($kurang > 0) {
            arsort($sisa);
            foreach (array_keys($sisa) as $id) {
                if ($kurang <= 0) {
                    break;
                }
                $alokasi[$id]++;
                $kurang--;
            }
        }

        return array_map(fn (int $sen) => Setting::bulatkanUang($sen / 100), $alokasi);
    }

    /* =====================================================================
     | 5. CETAK BILYET DEPOSITO (PDF)
     |===================================================================== */
    /**
     * Bangun dokumen Bilyet Simpanan Berjangka Mudharabah dalam format PDF.
     *
     * Dokumen ini adalah bukti kepemilikan dana syirkah temporer sehingga
     * hanya boleh dicetak untuk deposito yang datanya lengkap dan sah.
     *
     * Alur kerja:
     *  1. Validasi keberadaan deposito — melempar ModelNotFoundException
     *     (HTTP 404) bila `$depositoId` tidak ada.
     *  2. Ambil seluruh data terkait: deposito, anggota (pemilik), produk
     *     (tenor & nisbah), dan rekening sumber dana. Identitas koperasi
     *     diambil dari config('pdf.koperasi').
     *  3. Render view `deposito.bilyet-pdf` dengan data tersebut.
     *  4. Kembalikan objek PDF yang siap di-stream atau diunduh pemanggil.
     *
     * @throws ModelNotFoundException 404
     */
    public function generateBilyetPdf(int $depositoId): PDF
    {
        $deposito = Deposito::with([
            'member',
            'product',
            'sourceSavingAccount',
            'depositoRekening',
        ])->findOrFail($depositoId);

        Log::info('DepositoService: bilyet deposito dicetak.', [
            'deposito_number' => $deposito->deposito_number,
            'deposito_id' => $deposito->id,
        ]);

        return app('dompdf.wrapper')
            ->setOption('defaultFont', (string) config('pdf.bilyet.font', 'DejaVu Sans'))
            ->setOption('isRemoteEnabled', false)
            ->setOption('isHtml5ParserEnabled', true)
            ->loadView('deposito.bilyet-pdf', $this->dataBilyet($deposito));
    }

    /**
     * Data yang dirender ke view bilyet.
     *
     * Dipisah dari generateBilyetPdf() supaya isi dokumen dapat diperiksa
     * (mis. oleh pengujian akurasi data) tanpa harus mengekstrak teks dari
     * berkas PDF biner.
     *
     * @return array<string, mixed>
     */
    public function dataBilyet(Deposito $deposito): array
    {
        $deposito->loadMissing(['member', 'product', 'sourceSavingAccount', 'depositoRekening']);

        // Bila file logo benar-benar tersedia di public/, pakai gambar;
        // selain itu view memakai emblem monogram HTML/CSS.
        $logo = null;

        if ($pathLogo = (string) config('pdf.logo', '')) {
            $kandidat = public_path($pathLogo);

            if (is_file($kandidat) && is_readable($kandidat)) {
                $logo = $kandidat;
            }
        }

        return [
            'deposito' => $deposito,
            'anggota' => $deposito->member,
            'produk' => $deposito->product,
            'koperasi' => config('pdf.koperasi', []),
            'logo' => $logo,
            'nomorBilyet' => (string) $deposito->deposito_number,
            'namaFile' => $this->namaFileBilyet($deposito),
            'margin' => (array) config('pdf.bilyet.margin', []),
        ];
    }

    /**
     * Nama berkas unduhan bilyet: Bilyet-Deposito-{deposito_number}.pdf
     */
    public function namaFileBilyet(Deposito $deposito): string
    {
        $nomor = (string) $deposito->deposito_number;
        $nomor = $nomor !== '' ? $nomor : (string) $deposito->id;

        return 'Bilyet-Deposito-'.$nomor.'.pdf';
    }

    /* =====================================================================
     | HELPER
     |===================================================================== */

    /**
     * Hitung tanggal jatuh tempo: start_date + tenor bulan.
     *
     * Memakai addMonthsNoOverflow() agar perhitungan presisi dan tidak meluap
     * ke bulan berikutnya, misalnya 31 Januari + 1 bulan = 28/29 Februari
     * (bukan 3 Maret). Tanggal jatuh tempo TIDAK PERNAH diterima dari input
     * pengguna — selalu hasil hitungan di sini.
     */
    private function hitungJatuhTempo(Carbon $start, int $tenor): Carbon
    {
        if ($tenor <= 0) {
            throw new \InvalidArgumentException('Tenor deposito harus lebih besar dari 0 bulan.');
        }

        return $start->copy()->startOfDay()->addMonthsNoOverflow($tenor);
    }

    /** Catat baris transaksi (mutasi rekening) untuk audit. */
    private function catatTransaksi(array $data): Transaksi
    {
        return Transaksi::create($data + [
            'waktu_transaksi' => now(),
            'status_transaksi' => 'Sukses',
            'status' => 'success',
        ]);
    }

    /** Nomor bilyet: DEP-YYYYMMDD-XXXX dengan XXXX nomor urut harian. */
    private function generateDepositoNumber(): string
    {
        // Awalan dapat diubah Pengurus (Setting `prefix_nomor_deposito`);
        // kosong = tetap `DEP`.
        $prefix = NomorDokumen::prefix('deposito');
        $panjang = 4;

        // Urutan NUMERIK terbesar (bukan perbandingan string) agar aman saat
        // urutan melewati 9999 — `substr($maxNo, -4)` lama akan memotong
        // `10001` menjadi `0001` dan menghasilkan nomor duplikat.
        $baris = Deposito::where('deposito_number', 'like', $prefix.'%')
            ->selectRaw('MAX(CAST(SUBSTRING(deposito_number, ?) AS UNSIGNED)) AS maks_urut', [strlen($prefix) + 1])
            ->first();

        $urut = ((int) ($baris->maks_urut ?? 0)) + 1;

        do {
            $kandidat = $prefix.str_pad((string) $urut, $panjang, '0', STR_PAD_LEFT);
            $urut++;
        } while (Deposito::where('deposito_number', $kandidat)->exists());

        return $kandidat;
    }

    /** Nomor rekening Simpanan Berjangka: RKN-YYYY-XXXXXX. */
    private function generateNoRekening(): string
    {
        return Rekening::nomorRekeningBaru();
    }

    /** Nomor transaksi harian: TRX-YYYYMMDD-XXXXXX. */
    private function generateNoTransaksi(): string
    {
        // K-03: sumber urutan tunggal ber-kunci baris (SELECT ... FOR UPDATE).
        // Menggantikan pola MAX()+cari-kandidat yang masih rawan balapan saat
        // dua proses membaca MAX yang sama sebelum salah satu melakukan insert.
        // Wajib dipanggil di dalam DB::transaction() pemanggil.
        // Awalan dapat diubah Pengurus (Setting `prefix_nomor_transaksi`).
        return NomorDokumen::buat('transaksi');
    }

    /**
     * Akun legacy (tabel `akun`) untuk rekening Simpanan Berjangka.
     *
     * Tabel `akun` belum punya akun khusus deposito, sehingga memakai akun
     * simpanan anggota (1133) seperti pola yang sudah dipakai CifController.
     * Akuntansi sebenarnya tetap memakai CoA 2-1003 lewat AutoJournalingService.
     */
    private function akunIdSimpanan(): int
    {
        // ZERO-HARDCODING: resolusi lewat registri CoA, TANPA fallback
        // `Akun::query()->value('id')` yang dapat diam-diam memilih akun
        // sembarang (mis. akun header) dan merusak laporan keuangan.
        $akunId = \App\Support\Coa::akunLegacyId(\App\Support\Coa::SIMPANAN_SUKARELA);

        if (! $akunId) {
            throw new \RuntimeException(
                'Akun simpanan (legacy 1133) tidak ditemukan: jalankan AkunSeeder '
                .'sebelum membuka deposito.'
            );
        }

        return (int) $akunId;
    }
}
