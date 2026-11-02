<?php

namespace App\Services\Jurnal;

use App\Models\JenisSimpanan;
use App\Models\Jurnal;
use App\Models\MemberTermination;
use App\Support\Coa;
use App\Support\Setting;
use Illuminate\Support\Facades\Log;

/**
 * RESEP JURNAL DOMAIN SIMPANAN ANGGOTA (SUKARELA, POKOK, WAJIB, EXIT).
 *
 * Seluruh method di sini hanya MENYUSUN baris jurnal (resep), lalu
 * menyerahkannya ke `PostingJurnal` — mesin inti yang menjadi satu-satunya
 * gerbang validasi, keseimbangan, cut-off periode, dan penulisan buku besar.
 *
 * Pemisahan domain ini behavior-preserving: `AutoJournalingService` tetap
 * menyediakan method yang sama dan mendelegasikannya ke kelas ini.
 */
class JournalSimpananModal
{
    /**
     * Sumber jurnal bundling setoran awal anggota baru.
     *
     * Dipakai sebagai kunci IDEMPOTENSI: satu anggota hanya boleh memiliki
     * satu jurnal setoran awal. Percobaan posting ulang (mis. dari double-submit
     * form Teller) akan dikembalikan ke jurnal yang sudah ada, bukan membuat
     * jurnal baru — mencegah double posting & saldo Kas Teller menggelembung.
     */
    public const SUMBER_BUNDLING_SETORAN_AWAL = 'Setoran Awal Anggota (Bundling)';

    /**
     * Sumber jurnal PENGEMBALIAN DANA EXIT KEANGGOTAAN (PSAK 108).
     *
     * Dipakai sebagai kunci IDEMPOTENSI: satu dokumen terminasi hanya boleh
     * menghasilkan satu jurnal pengembalian. Percobaan posting ulang akan
     * dikembalikan ke jurnal yang sudah ada (lihat `jurnalPengembalianExitTersimpan()`),
     * bukan membuat jurnal baru — mencegah double posting & Kas Teller/Bank menggelembung.
     */
    public const SUMBER_PENGEMBALIAN_EXIT = 'Pengembalian Dana Exit Keanggotaan';

    public function __construct(private readonly PostingJurnal $mesin) {}

    /**
     * Setoran Sukarela.
     *
     * DEBET : Kas Teller (1-1001)
     * KREDIT: Simpanan Sukarela Anggota (2-1001)
     *
     * `$reversal = true` dipakai HANYA oleh alur pembalikan (void/AML): arah
     * baris tetap seperti penarikan, tetapi jurnal ditandai `is_reversal` dan
     * dikecualikan dari gate status transaksi (transaksi asal berstatus void).
     */
    public function jurnalSetorTunai(
        float $nominal,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null,
        bool $reversal = false
    ): ?Jurnal {
        return $this->mesin->post($keterangan, [
            ['kode' => Coa::KAS_TELLER,        'debet' => $nominal],
            ['kode' => Coa::SIMPANAN_SUKARELA, 'kredit' => $nominal],
        ], 'Setoran Tunai', $transaksiId, null, $userId, $rekeningId, $anggotaId,
            null, null, null, null, null, false, $reversal);
    }

    /**
     * Penarikan Sukarela.
     *
     * DEBET : Simpanan Sukarela Anggota (2-1001)
     * KREDIT: Kas Teller (1-1001)
     *
     * `$reversal = true` dipakai HANYA oleh alur pembalikan (void/AML) — lihat
     * `jurnalSetorTunai()`.
     */
    public function jurnalTarikTunai(
        float $nominal,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null,
        bool $reversal = false
    ): ?Jurnal {
        return $this->mesin->post($keterangan, [
            ['kode' => Coa::SIMPANAN_SUKARELA, 'debet' => $nominal],
            ['kode' => Coa::KAS_TELLER,        'kredit' => $nominal],
        ], 'Penarikan Tunai', $transaksiId, null, $userId, $rekeningId, $anggotaId,
            null, null, null, null, null, false, $reversal);
    }

    /**
     * Setoran Simpanan Pokok.
     *
     * DEBET : Kas Teller (1-1001)
     * KREDIT: Simpanan Pokok Anggota (3-1001)
     */
    public function jurnalSetorSimpananPokok(
        float $nominal,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null
    ): ?Jurnal {
        return $this->mesin->post($keterangan, [
            ['kode' => Coa::KAS_TELLER,     'debet' => $nominal],
            ['kode' => Coa::SIMPANAN_POKOK, 'kredit' => $nominal],
        ], 'Setoran Simpanan Pokok', $transaksiId, null, $userId, $rekeningId, $anggotaId);
    }

    /**
     * Setoran Simpanan Wajib.
     *
     * PSAK 101 — Simpanan Wajib disajikan sebagai MODAL ANGGOTA (ekuitas),
     * sehingga akun kredit kanonik = 3-1002, bukan akun kewajiban legacy 2-1002.
     *
     * DEBET : Kas Teller (1-1001)
     * KREDIT: Simpanan Wajib Anggota (3-1002)
     */
    public function jurnalSetorSimpananWajib(
        float $nominal,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null
    ): ?Jurnal {
        return $this->mesin->post($keterangan, [
            ['kode' => Coa::KAS_TELLER,           'debet' => $nominal],
            ['kode' => Coa::SIMPANAN_WAJIB_MODAL, 'kredit' => $nominal],
        ], 'Setoran Simpanan Wajib', $transaksiId, null, $userId, $rekeningId, $anggotaId);
    }

    /**
     * Setoran simpanan dengan akun KREDIT mengikuti PARAMETER DINAMIS.
     *
     * Pengganti `match` hardcoded di pemanggil: akun kredit dibaca dari master
     * `jenis_simpanans` (kolom `kode_akun`) melalui resolver JenisSimpanan yang
     * ber-cache. Pengurus cukup mengubah parameter di UI — tanpa mengubah kode —
     * dan seluruh alur setoran (CIF, Teller, auto-debit, dsb.) langsung mengikuti.
     *
     * PENJAGA KOMPATIBILITAS:
     *  - `$sumber` SENGAJA tetap memakai label lama ('Setoran Simpanan Pokok' /
     *    'Setoran Simpanan Wajib' / 'Setoran Tunai') agar filter & laporan
     *    yang sudah ada tidak berubah perilaku.
     *  - Jenis simpanan bertipe `berjangka` (deposito) dilewatkan ke alur setoran
     *    tunai agar penempatan deposito tidak keliru diposting sebagai titipan biasa.
     *
     * @param  string|null  $namaJenis  Jenis simpanan pemilik rekening; null = setoran tunai umum.
     * @param  string|null  $tipe  Hasil `JenisSimpanan::tipe($namaJenis)` bila sudah diketahui.
     */
    public function jurnalSetorSimpanan(
        float $nominal,
        string $keterangan,
        ?string $namaJenis = null,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null,
        ?string $tipe = null,
        $tanggalJurnal = null
    ): ?Jurnal {
        $tipe ??= $namaJenis !== null ? JenisSimpanan::tipe($namaJenis) : null;

        // Deposito / berjangka serta jenis non-simpanan → alur setoran tunai biasa.
        if ($tipe === null || ! in_array($tipe, [JenisSimpanan::TIPE_POKOK, JenisSimpanan::TIPE_WAJIB, JenisSimpanan::TIPE_SUKARELA], true)) {
            return $this->jurnalSetorTunai($nominal, $keterangan, $userId, $transaksiId, $rekeningId, $anggotaId);
        }

        // Label sumber lama — dipertahankan demi kompatibilitas laporan.
        $sumber = match ($tipe) {
            JenisSimpanan::TIPE_POKOK => 'Setoran Simpanan Pokok',
            JenisSimpanan::TIPE_WAJIB => 'Setoran Simpanan Wajib',
            default => 'Setoran Tunai',
        };

        // Akun kredit dari parameter; bila kosong → fallback statis.
        // PSAK 101: Simpanan Wajib = Modal Anggota (3-1002), bukan 2-1002.
        $kodeKredit = JenisSimpanan::coaKode($namaJenis) ?: match ($tipe) {
            JenisSimpanan::TIPE_POKOK => Coa::SIMPANAN_POKOK,
            JenisSimpanan::TIPE_WAJIB => Coa::SIMPANAN_WAJIB_MODAL,
            default => Coa::SIMPANAN_SUKARELA,
        };

        return $this->mesin->post($keterangan, [
            ['kode' => Coa::KAS_TELLER, 'debet' => $nominal],
            ['kode' => $kodeKredit,     'kredit' => $nominal],
        ], $sumber, $transaksiId, null, $userId, $rekeningId, $anggotaId, $tanggalJurnal);
    }

    /**
     * Pelunasan Tagihan Simpanan Wajib melalui TELLER (tunai/transfer).
     *
     * PSAK 101 (penyajian modal anggota) — sisi KREDIT memakai akun MODAL
     * Simpanan Wajib (3-1002), bukan akun kewajiban legacy 2-1002.
     *
     * @param  ?string  $akunWajibKode  Akun kredit hasil resolusi kebijakan
     *                                  (`MandatorySavingsService::akunSimpananWajib()`). NULL = pakai akun
     *                                  standar (perilaku lama) sehingga pemanggil lama tetap kompatibel.
     * @param  ?string  $akunKasKode  Akun debet (kas/bank) opsional.
     */
    public function jurnalSimpananWajibTeller(
        float $nominal,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $anggotaId = null,
        $tanggalJurnal = null,
        ?string $akunWajibKode = null,
        ?string $akunKasKode = null,
        ?int $rekeningWajibId = null
    ): ?Jurnal {
        return $this->mesin->post($keterangan, [
            ['kode' => $akunKasKode ?: Coa::KAS_TELLER,               'debet' => $nominal, 'rekening_id' => null],
            ['kode' => $akunWajibKode ?: Coa::SIMPANAN_WAJIB_MODAL,   'kredit' => $nominal, 'rekening_id' => $rekeningWajibId],
        ], 'Setoran Simpanan Wajib via Teller', $transaksiId, null, $userId, null, $anggotaId, $tanggalJurnal);
    }

    /**
     * Bundling Setoran Awal Anggota Baru (Simpanan Pokok + Simpanan Wajib Bulan 1).
     *
     * Nominal diambil dinamis dari parameter koperasi
     * (`kebijakan_koperasis.mandatory_principal_savings` &
     * `mandatory_monthly_savings`) — lihat KebijakanKoperasi::simpananPokokWajib().
     *
     * PSAK 101 (penyajian modal anggota):
     *  DEBET  : 1-1001 Kas / Bank                      = pokok + wajib
     *  KREDIT : 3-1001 Simpanan Pokok Anggota          = pokok
     *  KREDIT : 3-1002 Simpanan Wajib Anggota (Modal)  = wajib
     */
    public function jurnalBundlingSetoranAwal(
        float $simpananPokok,
        float $simpananWajib,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningPokokId = null,
        ?int $anggotaId = null,
        ?int $rekeningWajibId = null
    ): ?Jurnal {
        /* GUARD DOUBLE POSTING: satu anggota hanya boleh punya SATU jurnal
         | setoran awal. Bila jurnal sudah pernah diposting (mis. karena klik
         | ganda pada form Teller atau retry request), jurnal lama dikembalikan
         | apa adanya tanpa membuat jurnal baru sehingga Buku Besar tidak
         | tercatat dua kali. */
        if ($anggotaId && ($jurnalTersimpan = $this->jurnalBundlingTersimpan($anggotaId))) {
            Log::warning('JournalSimpananModal: jurnal setoran awal sudah ada — posting ganda dicegah.', [
                'anggota_id' => $anggotaId,
                'no_jurnal' => $jurnalTersimpan->no_jurnal,
                'transaksi_id' => $transaksiId,
            ]);

            return $jurnalTersimpan;
        }

        $total = Setting::bulatkanUang($simpananPokok + $simpananWajib);

        return $this->mesin->post($keterangan, [
            ['kode' => Coa::KAS_TELLER,           'debet' => $total, 'rekening_id' => null],
            ['kode' => Coa::SIMPANAN_POKOK,       'kredit' => $simpananPokok, 'rekening_id' => $rekeningPokokId],
            ['kode' => Coa::SIMPANAN_WAJIB_MODAL, 'kredit' => $simpananWajib, 'rekening_id' => $rekeningWajibId],
        ], self::SUMBER_BUNDLING_SETORAN_AWAL, $transaksiId, null, $userId, $rekeningPokokId, $anggotaId);
    }

    /**
     * Cari jurnal setoran awal milik anggota (kunci guard double posting).
     *
     * `anggota_id` tersimpan di baris `jurnal_detail` (bukan header), sehingga
     * pencarian memakai whereHas('detail').
     */
    private function jurnalBundlingTersimpan(int $anggotaId): ?Jurnal
    {
        return Jurnal::where('sumber', self::SUMBER_BUNDLING_SETORAN_AWAL)
            ->whereHas('detail', fn ($query) => $query->where('anggota_id', $anggotaId))
            ->latest('id')
            ->first();
    }

    /**
     * Jurnal pengembalian dana exit yang SUDAH tersimpan untuk seorang anggota.
     *
     * Kunci idempotensi modul `member_terminations`: dipakai untuk mencegah
     * dobel posting pengembalian dana `3-1001`/`3-1002` (PSAK 108 — integritas
     * buku besar). Bila jurnal sudah ada, pemanggil WAJIB memakai jurnal lama
     * (bukan membuat yang baru).
     */
    public function jurnalPengembalianExitTersimpan(int $anggotaId): ?Jurnal
    {
        return Jurnal::where('sumber', self::SUMBER_PENGEMBALIAN_EXIT)
            ->whereHas('detail', fn ($query) => $query->where('anggota_id', $anggotaId))
            ->latest('id')
            ->first();
    }

    /**
     * PENGEMBALIAN DANA EXIT KEANGGOTAAN — Simpanan Pokok + Simpanan Wajib.
     *
     * Satu-satunya pintu sah pengembalian dana akun ekuitas anggota `3-1001`
     * dan `3-1002` (PSAK 101 ¶ 31 — Simpanan Pokok & Wajib adalah Modal
     * Anggota; PSAK 108 — akad titipan/modal). Dipanggil eksklusif oleh
     * `ExitKeanggotaanService::eksekusi()` setelah anggota dinyatakan bersih
     * dari seluruh kewajiban.
     *
     * Baris debet bernilai nol otomatis diabaikan agar jurnal tetap balance
     * (mis. anggota tanpa Simpanan Pokok terisi). Bila total nol, jurnal tidak
     * dibuat (return null) — tidak ada dana yang dikembalikan.
     *
     * @param  float  $nominalPokok  Saldo Simpanan Pokok (3-1001) yang dikembalikan.
     * @param  float  $nominalWajib  Akumulasi Simpanan Wajib (3-1002) yang dikembalikan.
     * @param  string  $metode  MemberTermination::METODE_CASH | METODE_TRANSFER_BANK.
     * @param  ?string  $akunKasKode  Override akun kredit (opsional, hasil resolusi kebijakan).
     * @param  float  $nominalSukarela  Saldo Sukarela/Wadiah (2-1001).
     * @param  float  $nominalDeposito  Pokok Simpanan Berjangka (2-1003).
     * @param  float  $nominalAkrualBagiHasil  Akrual bagi hasil deposito (5-3001).
     * @param  float  $nominalZakat  Potongan zakat → KREDIT Dana Zakat (2-3002).
     * @param  float  $nominalBiayaAdmin  Biaya penutupan CIF → KREDIT Pendapatan Admin (4-2001).
     */
    public function jurnalPengembalianDanaExit(
        float $nominalPokok,
        float $nominalWajib,
        string $keterangan,
        string $metode,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null,
        $tanggalJurnal = null,
        ?string $akunKasKode = null,
        float $nominalSukarela = 0.0,
        float $nominalDeposito = 0.0,
        float $nominalAkrualBagiHasil = 0.0,
        float $nominalZakat = 0.0,
        float $nominalBiayaAdmin = 0.0,
    ): ?Jurnal {
        $nominalPokok = Setting::bulatkanUang($nominalPokok);
        $nominalWajib = Setting::bulatkanUang($nominalWajib);
        $nominalSukarela = Setting::bulatkanUang($nominalSukarela);
        $nominalDeposito = Setting::bulatkanUang($nominalDeposito);
        $nominalAkrualBagiHasil = Setting::bulatkanUang($nominalAkrualBagiHasil);
        $nominalZakat = Setting::bulatkanUang($nominalZakat);
        $nominalBiayaAdmin = Setting::bulatkanUang($nominalBiayaAdmin);

        // Total DEBET bruto = seluruh kewajiban koperasi kepada anggota.
        $bruto = Setting::bulatkanUang(
            $nominalPokok + $nominalWajib + $nominalSukarela + $nominalDeposito + $nominalAkrualBagiHasil
        );

        if ($bruto <= 0) {
            Log::warning('JournalSimpananModal: pengembalian dana exit dibatalkan — total Simpanan Pokok + Wajib nol.', [
                'anggota_id' => $anggotaId,
                'keterangan' => $keterangan,
            ]);

            return null;
        }

        // Kas/bank yang benar-benar dibayarkan = bruto − zakat − biaya admin.
        $total = Setting::bulatkanUang($bruto - $nominalZakat - $nominalBiayaAdmin);

        if ($total <= 0) {
            Log::warning('JournalSimpananModal: pengembalian dana exit dibatalkan — net kas ≤ 0.', [
                'anggota_id' => $anggotaId,
                'bruto' => $bruto,
                'zakat' => $nominalZakat,
                'biaya' => $nominalBiayaAdmin,
            ]);

            return null;
        }

        // Akun kas/bank mengikuti metode pengembalian yang dipilih (standar PSAK 108).
        $akunKredit = $akunKasKode ?: (
            $metode === MemberTermination::METODE_TRANSFER_BANK ? Coa::BANK_KOPERASI : Coa::KAS_TELLER
        );

        $entries = [];

        // DEBET 3-1001 Simpanan Pokok — hanya bila ada saldo.
        if ($nominalPokok > 0) {
            $entries[] = ['kode' => Coa::SIMPANAN_POKOK, 'debet' => $nominalPokok];
        }

        // DEBET 3-1002 Simpanan Wajib — hanya bila ada akumulasi.
        if ($nominalWajib > 0) {
            $entries[] = ['kode' => Coa::SIMPANAN_WAJIB_MODAL, 'debet' => $nominalWajib];
        }

        // DEBET 2-1001 Simpanan Sukarela / Wadiah.
        if ($nominalSukarela > 0) {
            $entries[] = ['kode' => Coa::SIMPANAN_SUKARELA, 'debet' => $nominalSukarela];
        }

        // DEBET 2-1003 Simpanan Berjangka / Deposito Mudharabah.
        if ($nominalDeposito > 0) {
            $entries[] = ['kode' => Coa::SIMPANAN_BERJANGKA, 'debet' => $nominalDeposito];
        }

        // DEBET 5-3001 Beban Bagi Hasil Deposito — akrual hak anggota.
        if ($nominalAkrualBagiHasil > 0) {
            $entries[] = ['kode' => Coa::BEBAN_BAGI_HASIL_DEPOSITO, 'debet' => $nominalAkrualBagiHasil];
        }

        // KREDIT 2-3002 Dana Zakat — potongan zakat menjadi kewajiban koperasi
        // (PSAK 109). Dikreditkan agar jurnal tetap balance; zakat mengurangi
        // kas yang dibayarkan ke anggota.
        if ($nominalZakat > 0) {
            $entries[] = ['kode' => Coa::DANA_ZAKAT, 'kredit' => $nominalZakat];
        }

        // KREDIT 4-2001 Pendapatan Administrasi Penutupan CIF.
        if ($nominalBiayaAdmin > 0) {
            $entries[] = ['kode' => Coa::PENDAPATAN_ADMIN, 'kredit' => $nominalBiayaAdmin];
        }

        // KREDIT 1-1001 Kas Teller / 1-1002 Kas di Bank = dana net diterima anggota.
        $entries[] = ['kode' => $akunKredit, 'kredit' => $total];

        return $this->mesin->post(
            $keterangan,
            $entries,
            self::SUMBER_PENGEMBALIAN_EXIT,
            $transaksiId,
            null,
            $userId,
            $rekeningId,
            $anggotaId,
            $tanggalJurnal
        );
    }

    /**
     * Auto-Debit Simpanan Wajib Bulanan dari saldo Simpanan Sukarela anggota.
     *
     * Dipakai scheduler `mandatory-savings:process-daily` ketika saldo Sukarela
     * mencukupi tagihan bulan berjalan.
     *
     * PSAK 101 (reklasifikasi kewajiban sukarela → modal anggota):
     *  DEBET  : 2-1001 Simpanan Sukarela Anggota       = nominal tagihan
     *  KREDIT : 3-1002 Simpanan Wajib Anggota (Modal)  = nominal tagihan
     *
     * @param  ?string  $akunWajibKode  Akun kredit hasil resolusi kebijakan
     *                                  (`MandatorySavingsService::akunSimpananWajib()`). NULL = akun standar.
     * @param  ?string  $akunSukarelaKode  Akun debet (sumber dana) opsional.
     */
    public function jurnalSimpananWajibAutoDebit(
        float $nominal,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null,
        $tanggalJurnal = null,
        ?string $akunWajibKode = null,
        ?string $akunSukarelaKode = null,
        ?int $rekeningWajibId = null
    ): ?Jurnal {
        return $this->mesin->post($keterangan, [
            ['kode' => $akunSukarelaKode ?: Coa::SIMPANAN_SUKARELA,   'debet' => $nominal, 'rekening_id' => $rekeningId],
            ['kode' => $akunWajibKode ?: Coa::SIMPANAN_WAJIB_MODAL,   'kredit' => $nominal, 'rekening_id' => $rekeningWajibId],
        ], 'Simpanan Wajib Auto-Debit', $transaksiId, null, $userId, $rekeningId, $anggotaId, $tanggalJurnal);
    }

    /**
     * Penarikan Simpanan Wajib (mis. penutupan rekening / keluar keanggotaan).
     *
     * PSAK 101 — Simpanan Wajib disajikan sebagai Modal Anggota, sehingga sisi
     * DEBET memakai akun kanonik hasil resolusi kebijakan (standar 3-1002).
     *
     * @param  ?string  $akunWajibKode  Akun debet hasil resolusi kebijakan.
     * @param  ?string  $akunKasKode  Akun kredit (kas/bank) opsional.
     */
    public function jurnalTarikSimpananWajib(
        float $nominal,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null,
        ?string $akunWajibKode = null,
        ?string $akunKasKode = null
    ): ?Jurnal {
        return $this->mesin->post($keterangan, [
            ['kode' => $akunWajibKode ?: Coa::SIMPANAN_WAJIB_MODAL, 'debet' => $nominal],
            ['kode' => $akunKasKode ?: Coa::KAS_TELLER,             'kredit' => $nominal],
        ], 'Penarikan Simpanan Wajib', $transaksiId, null, $userId, $rekeningId, $anggotaId);
    }

    /**
     * Reversal SETORAN SIMPANAN (Pokok / Wajib / Sukarela) — R6.
     *
     * Membalik jurnal setoran simpanan: DEBET akun simpanan / KREDIT Kas Teller.
     * Dipakai saat void/penolakan transaksi berjenis `Simpanan Pokok`,
     * `Simpanan Wajib`, atau `Simpanan Sukarela` (input manual TransaksiController)
     * yang sebelumnya TIDAK punya pemetaan jurnal pembalik.
     *
     * @param  string  $kodeSimpanan  Akun simpanan (debet) — default Sukarela.
     */
    public function jurnalReversalSetoranSimpanan(
        float $nominal,
        string $keterangan,
        string $kodeSimpanan = Coa::SIMPANAN_SUKARELA,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null
    ): ?Jurnal {
        return $this->mesin->post($keterangan, [
            ['kode' => $kodeSimpanan, 'debet' => $nominal],
            ['kode' => Coa::KAS_TELLER, 'kredit' => $nominal],
        ], 'Reversal Setoran Simpanan', $transaksiId, null, $userId, $rekeningId, $anggotaId,
            null, null, null, null, null, false, true);
    }
}
