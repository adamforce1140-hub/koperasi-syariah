<?php

namespace App\Services;

use App\Enums\DisbursementMethod;
use App\Events\PembiayaanDicairkan;
use App\Models\Angsuran;
use App\Models\Coa;
use App\Models\JenisSimpanan;
use App\Models\KasHarian;
use App\Models\Pembiayaan;
use App\Models\PemetaanAkadCoa;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use App\Support\PemisahanTugas;
use App\Support\Setting;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Mesin Pencairan (Dropping) Pembiayaan — ZERO-HARDCODING, PSAK 108.
 *
 * Satu pintu untuk ketiga skema pencairan:
 *
 *   CASH             → uang fisik keluar Laci Teller  (kas_harian + transaksi 'Pencairan Pembiayaan')
 *   TRANSFER_BANK    → transfer keluar bank operasional (transaksi 'Transfer Keluar')
 *   TRANSFER_SAVINGS → pindah buku ke Simpanan Sukarela anggota (transaksi 'Simpanan Sukarela')
 *
 * Seluruh alur dibungkus SATU `DB::transaction()` sehingga pembaruan status
 * pembiayaan, jadwal angsuran, buku pembantu kas/bank/simpanan, dan jurnal
 * akuntansi bersifat atomik — bila salah satu langkah gagal, seluruhnya
 * di-rollback (tidak ada separuh pencairan yang tertinggal di buku besar).
 *
 * TIDAK ada satu pun nomor akun CoA yang ditanam di file ini. Pemetaan akun
 * diserahkan ke CoaResolver (Setting `kebijakan_koperasis` → fallback registri
 * App\Support\Coa), dan penjadwalan tanggal jatuh tempo ke HariLiburService.
 */
class DisbursementService
{
    public function __construct(
        private CoaResolver $coaResolver,
        private HariLiburService $hariLibur,
        private AutoJournalingService $journalService,
    ) {}

    /* ══════════════════════════════════════════════════════════════════════
     | PINTU MASUK TUNGGAL
     ══════════════════════════════════════════════════════════════════════ */

    /**
     * Cairkan sebuah pembiayaan.
     *
     * @param  Pembiayaan  $pembiayaan  Baris pembiayaan yang akan dicairkan.
     * @param  DisbursementMethod|string  $metode  Skema pencairan (CASH | TRANSFER_BANK | TRANSFER_SAVINGS).
     * @param  int|null  $userId  Petugas yang memproses (default: user login).
     * @param  int|null  $bankAccountId  ID CoA bank operasional (wajib untuk TRANSFER_BANK).
     * @param  int|null  $savingsAccountId  ID rekening Simpanan Sukarela (wajib untuk TRANSFER_SAVINGS).
     * @param  CarbonInterface|string|null  $tanggalCair  Tanggal pencairan (default: hari ini).
     *
     * @throws \RuntimeException bila validasi bisnis/akuntansi tidak terpenuhi.
     */
    public function cairkan(
        Pembiayaan $pembiayaan,
        DisbursementMethod|string $metode,
        ?int $userId = null,
        ?int $bankAccountId = null,
        ?int $savingsAccountId = null,
        CarbonInterface|string|null $tanggalCair = null,
    ): Pembiayaan {
        $metode = $metode instanceof DisbursementMethod
            ? $metode
            : DisbursementMethod::from($metode);

        $userId ??= Auth::id();
        $tanggalCair = $this->keCarbon($tanggalCair);
        $kodeAkad = $pembiayaan->akad_pembiayaan ?: Pembiayaan::AKAD_MURABAHAH;

        $nominalPokok = Setting::bulatkanUang((float) $pembiayaan->jumlah_pembiayaan);
        $margin = $this->marginUntuk($pembiayaan, $kodeAkad);
        $marginJadwal = $this->marginJadwal($pembiayaan);
        $tenor = max(1, (int) $pembiayaan->tenor);

        // Idempoten: akad yang sudah pernah dicairkan TIDAK boleh dicairkan lagi.
        // Dua jalur pencairan hidup berdampingan (DisbursementService ini dan
        // Teller\ProsesPencairanAction) sehingga tanpa guard di kedua sisi satu
        // akad dapat mengeluarkan kas dua kali.
        if ((bool) $pembiayaan->is_disbursed || $pembiayaan->tgl_dicairkan !== null) {
            $this->tolak("Pembiayaan {$pembiayaan->no_pembiayaan} sudah pernah dicairkan.", [
                'pembiayaan_id' => $pembiayaan->id,
                'status' => $pembiayaan->status_pembiayaan,
                'tgl_dicairkan' => $pembiayaan->tgl_dicairkan?->toDateTimeString(),
            ]);
        }

        if ($nominalPokok <= 0) {
            $this->tolak("Nominal pokok pembiayaan {$pembiayaan->no_pembiayaan} tidak valid.", [
                'pembiayaan_id' => $pembiayaan->id,
                'nominal' => $nominalPokok,
            ]);
        }

        // Akun sumber dana ditentukan SEBELUM transaksi dibuka agar kesalahan
        // konfigurasi CoA tidak mengunci baris pembiayaan lebih lama dari perlu.
        $akunSumber = $this->coaResolver->kodeSumberDanaByMetode($metode->value, $bankAccountId);

        // CASH: hak akses teller & saldo laci diperiksa lebih awal.
        $sesiTeller = null;

        if ($metode->mengeluarkanKasFisik()) {
            $sesiTeller = $this->pastikanTellerSiap($userId, $tanggalCair);
        }

        $hasil = DB::transaction(function () use (
            $pembiayaan,
            $metode,
            $userId,
            $bankAccountId,
            $savingsAccountId,
            $tanggalCair,
            $nominalPokok,
            $margin,
            $marginJadwal,
            $tenor,
            $akunSumber,
            &$sesiTeller,
        ) {
            // ── 1. Kunci baris pembiayaan (cegah dropping ganda) ────────────
            $pb = Pembiayaan::query()->lockForUpdate()->findOrFail($pembiayaan->id);

            if (! $pb->isStatus([
                Pembiayaan::STATUS_DISETUJUI,
                Pembiayaan::STATUS_PENDING_PENCAIRAN,
                Pembiayaan::STATUS_DISAHKAN,
            ])) {
                $this->tolak('Pembiayaan ini tidak dalam status siap dicairkan.', [
                    'pembiayaan_id' => $pb->id,
                    'status_pembiayaan' => $pb->status_pembiayaan,
                ]);
            }

            // ── GCG: pemisahan tugas (maker ≠ checker) ──────────────────────
            // Penyetuju tidak boleh mencairkan akadnya sendiri, dan pengaju
            // (AO/pembuat) tidak boleh mencairkan pengajuannya sendiri.
            if (PemisahanTugas::dilanggar($pb->disetujui_oleh, $userId)) {
                $this->tolak(PemisahanTugas::PESAN, [
                    'pembiayaan_id' => $pb->id,
                    'user_id' => $userId,
                    'alasan' => 'penyetuju_sama',
                ]);
            }

            $pengaju = DB::table('pengajuan_pembiayaan')
                ->where('pembiayaan_id', $pb->id)
                ->select('ao_id', 'created_by')
                ->first();

            if ($pengaju !== null && PemisahanTugas::dilanggarSalahSatu([$pengaju->ao_id, $pengaju->created_by], $userId)) {
                $this->tolak(PemisahanTugas::PESAN, [
                    'pembiayaan_id' => $pb->id,
                    'user_id' => $userId,
                    'alasan' => 'pengaju_sama',
                ]);
            }

            $anggota = $pb->anggota;

            if (! $anggota) {
                $this->tolak('Data anggota pembiayaan tidak ditemukan.', ['pembiayaan_id' => $pb->id]);
            }

            // ── 2. Rekening tujuan pindah buku (bila skema non-tunai) ───────
            $rekeningSimpanan = null;
            $rekeningBank = null;

            if ($metode->memerlukanRekeningSimpanan()) {
                $rekeningSimpanan = $this->siapkanRekeningSimpanan($pb, $savingsAccountId, (int) $anggota->id);
            }

            if ($metode->memerlukanRekeningBank()) {
                $rekeningBank = $this->siapkanRekeningBank($bankAccountId, $nominalPokok);
            }

            // ── 3. Buku pembantu kas/bank/simpanan + transaksi ledger ───────
            $transaksi = null;
            $saldoSetelah = 0.0;

            if ($metode->mengeluarkanKasFisik()) {
                [$transaksi, $saldoSetelah] = $this->catatKasTeller(
                    $pb,
                    $anggota,
                    $nominalPokok,
                    $userId,
                    $tanggalCair,
                    $sesiTeller,
                    $akunSumber
                );
            } elseif ($metode === DisbursementMethod::TRANSFER_BANK) {
                [$transaksi, $saldoSetelah] = $this->catatTransferBank(
                    $pb,
                    $anggota,
                    $nominalPokok,
                    $userId,
                    $tanggalCair,
                    $rekeningBank,
                    $akunSumber
                );
            } else { // TRANSFER_SAVINGS
                [$transaksi, $saldoSetelah] = $this->catatPindahBukuSimpanan(
                    $pb,
                    $anggota,
                    $nominalPokok,
                    $userId,
                    $tanggalCair,
                    $rekeningSimpanan,
                    $akunSumber
                );
            }

            // ── 4. Jadwal angsuran (dinamis, sadar hari libur) ──────────────
            $jadwal = $this->hariLibur->jadwalJatuhTempo($tanggalCair, $tenor);
            $this->buatJadwalAngsuran($pb, $jadwal, $marginJadwal, $userId, $anggota->id, $transaksi?->id);

            $jatuhTempoPertama = $jadwal[1] ?? $this->hariLibur->jatuhTempoPertama($tanggalCair);

            // ── 5. Tutup pencairan: status, tanggal, snapshot margin ────────
            //        (dilakukan SETELAH buku pembantu sukses, agar status
            //        'dicairkan' hanya ini terpasang bila seluruh langkah sah)
            $pb->update([
                'status_pembiayaan' => Pembiayaan::STATUS_DICAIRKAN,
                'disbursement_method' => $metode->value,
                'bank_account_id' => $metode === DisbursementMethod::TRANSFER_BANK ? $bankAccountId : null,
                'savings_account_id' => $metode === DisbursementMethod::TRANSFER_SAVINGS ? $savingsAccountId : null,
                'margin_deferred_amount' => $margin > 0 ? $margin : null,
                'tanggal_cair' => $tanggalCair->toDateString(),
                // `tgl_pencairan` wajib ikut diisi: kolom itu adalah tanggal
                // rencana dari DPS, tetapi juga dipakai TellerPencairanController
                // sebagai penanda "sudah pernah cair" pada guard double-pencairan.
                'tgl_pencairan' => $tanggalCair->toDateString(),
                // Penanda anti double-pencairan. Tanpa ini, jalur kedua
                // (Teller\ProsesPencairanAction yang menerima status
                // STATUS_DICAIRKAN) akan mencairkan akad yang sama untuk kedua
                // kalinya karena status masih dianggap "siap cair".
                'is_disbursed' => true,
                'tgl_dicairkan' => now(),
                'tanggal_jatuh_tempo' => $jatuhTempoPertama->toDateString(),
                'sisa_pembiayaan' => $pb->total_pembiayaan,
                'user_approved' => $pb->user_approved ?: $userId,
            ]);

            // Jurnal menumpang transaksi yang sama: bila posting gagal, seluruh
            // pencairan ikut di-rollback (integritas buku besar terjaga).
            $this->postingJurnal(
                $pb,
                $metode,
                $anggota,
                $userId,
                $nominalPokok,
                $margin,
                $akunSumber,
                $transaksi?->id,
                $rekeningSimpanan?->id,
            );

            return $pb->fresh();
        });

        Log::info('Pencairan pembiayaan berhasil.', [
            'pembiayaan_id' => $hasil->id,
            'no_pembiayaan' => $hasil->no_pembiayaan,
            'akad' => $kodeAkad,
            'metode' => $metode->value,
            'pokok' => $nominalPokok,
            'margin' => $margin,
            'akun_sumber' => $akunSumber,
        ]);

        /*
         | Publikasikan peristiwa domain SETELAH transaksi DB berhasil di-commit
         | sehingga listener melihat data final. Dibungkus try/catch: kegagalan
         | listener tidak boleh menggagalkan pencairan (fail-safe, aditif).
         */
        try {
            PembiayaanDicairkan::dispatch(
                $hasil,
                $metode->value,
                $nominalPokok,
                $userId,
            );
        } catch (\Throwable $e) {
            Log::warning('Gagal mempublikasikan peristiwa PembiayaanDicairkan: '.$e->getMessage());
        }

        return $hasil;
    }

    /* ══════════════════════════════════════════════════════════════════════
     | SKEMA CASH — LACI TELLER
     ══════════════════════════════════════════════════════════════════════ */

    /**
     * Validasi sesi Laci Teller: user harus Teller, memiliki sesi OPEN hari ini,
     * dan saldo tunainya cukup. Dipanggil sebelum transaksi DB dibuka.
     */
    private function pastikanTellerSiap(?int $userId, CarbonInterface $tanggal): KasHarian
    {
        if (! $userId) {
            $this->tolak('Pencairan tunai wajib dilakukan oleh user yang terautentikasi.');
        }

        $teller = User::find($userId);

        if (! $teller || ! $teller->isTeller()) {
            $this->tolak('Hanya Teller aktif yang berhak memproses pencairan tunai.', [
                'user_id' => $userId,
                'role' => $teller?->role,
            ]);
        }

        // Sesi laci = baris kas_harian user untuk tanggal berjalan. Sesi dianggap
        // aktif bila belum closing (status_opname = OPEN). `expired_at` tidak
        // tersedia pada skema tabel ini — masa berlaku sesi = satu hari buku.
        $sesi = KasHarian::query()
            ->where('user_id', $userId)
            ->where('tanggal', $tanggal->toDateString())
            ->lockForUpdate()
            ->first();

        if (! $sesi || ! $sesi->isOpen()) {
            $this->tolak(
                'Laci Teller belum dibuka atau sudah ditutup. Buka kas teller terlebih dahulu sebelum melakukan pencairan tunai.',
                [
                    'user_id' => $userId,
                    'tanggal' => $tanggal->toDateString(),
                    'status_opname' => $sesi?->status_opname,
                ]
            );
        }

        return $sesi;
    }

    /**
     * Catat pencairan tunai: buku pembantu transaksi + validasi anti-deficit laci.
     *
     * @return array{0: Transaksi, 1: float} [transaksi, saldo kas setelah pencairan]
     */
    private function catatKasTeller(
        Pembiayaan $pb,
        $anggota,
        float $nominal,
        ?int $userId,
        CarbonInterface $tanggal,
        ?KasHarian $sesi,
        string $akunSumber,
    ): array {
        if ($sesi === null) {
            $this->tolak('Sesi Laci Teller tidak terbuka — pencairan tunai dibatalkan.');
        }

        $this->isiSaldoSesiTeller($sesi, $userId, $tanggal);

        $saldoTersedia = (float) $sesi->saldo_sistem;

        if ($saldoTersedia < $nominal) {
            $this->tolak('Saldo Kas Teller Tidak Mencukupi untuk Dropping Tunai', [
                'user_id' => $userId,
                'saldo_tersedia' => $saldoTersedia,
                'nominal_diminta' => $nominal,
                'tanggal' => $tanggal->toDateString(),
            ]);
        }

        $saldoSetelah = $saldoTersedia - $nominal;

        // Buku pembantu: kolom COA sumber pada `referensi` menyimpan kode akun
        // hasil resolver (dinamis), bukan nomor akun yang ditanam di kode.
        $transaksi = Transaksi::create([
            'no_transaksi' => $this->nomorTransaksi('TRX-KAS'),
            'waktu_transaksi' => now(),
            'anggota_id' => $anggota->id,
            'pembiayaan_id' => $pb->id,
            'jenis_transaksi' => 'Pencairan Pembiayaan',
            'posisi' => 'Debet',
            'jumlah' => $nominal,
            'saldo_setelah' => $saldoSetelah,
            'user_id' => $userId,
            'sumber_transaksi' => 'Teller',
            'status_transaksi' => 'Sukses',
            'referensi' => $akunSumber,
            'catatan' => "Pencairan tunai pembiayaan {$pb->no_pembiayaan} akad {$pb->akad_pembiayaan}",
        ]);

        // Atomik: saldo laci (kas_harian) dikunci ulang lalu diperbarui.
        KasHarian::query()
            ->whereKey($sesi->getKey())
            ->lockForUpdate()
            ->update(['saldo_sistem' => $saldoSetelah]);

        $sesi->setAttribute('saldo_sistem', $saldoSetelah);

        return [$transaksi, $saldoSetelah];
    }

    /* ══════════════════════════════════════════════════════════════════════
     | SKEMA TRANSFER_BANK
     ══════════════════════════════════════════════════════════════════════ */

    /**
     * Validasi rekening bank operasional: harus CoA aktif dan saldo buku
     * besarnya cukup menutup nominal pokok.
     *
     * @return array{coa: Coa, saldo: float}
     */
    private function siapkanRekeningBank(?int $bankAccountId, float $nominal): array
    {
        if (! $bankAccountId) {
            $this->tolak('Rekening bank operasional wajib dipilih untuk pencairan transfer bank.');
        }

        $coa = Coa::query()->whereKey($bankAccountId)->where('is_active', true)->first();

        if (! $coa) {
            $this->tolak('Rekening bank operasional tidak ditemukan atau tidak aktif.', [
                'bank_account_id' => $bankAccountId,
            ]);
        }

        $saldo = $this->saldoBukuBesar($coa);

        if ($saldo < $nominal) {
            $this->tolak('Saldo rekening bank operasional tidak mencukupi untuk pencairan.', [
                'bank_account_id' => $bankAccountId,
                'kode_akun' => $coa->kode_akun,
                'saldo_tersedia' => $saldo,
                'nominal_diminta' => $nominal,
            ]);
        }

        return ['coa' => $coa, 'saldo' => $saldo];
    }

    /**
     * Catat transfer keluar ke bank operasional (buku pembantu bank_transactions
     * dipetakan ke tabel `transaksi` ledger — tidak ada tabel paralel).
     *
     * @param  array{coa: Coa, saldo: float}  $rekeningBank
     * @return array{0: Transaksi, 1: float}
     */
    private function catatTransferBank(
        Pembiayaan $pb,
        $anggota,
        float $nominal,
        ?int $userId,
        CarbonInterface $tanggal,
        array $rekeningBank,
        string $akunSumber,
    ): array {
        $coa = $rekeningBank['coa'];
        $saldoSetelah = $rekeningBank['saldo'] - $nominal;
        $noReferensi = $this->nomorTransaksi('BNK-OUT');

        return [
            Transaksi::create([
                'no_transaksi' => $noReferensi,
                'waktu_transaksi' => now(),
                'anggota_id' => $anggota->id,
                'pembiayaan_id' => $pb->id,
                'jenis_transaksi' => 'Transfer Keluar',
                'posisi' => 'Kredit',
                'jumlah' => $nominal,
                'saldo_setelah' => $saldoSetelah,
                'user_id' => $userId,
                'sumber_transaksi' => 'Transfer',
                'status_transaksi' => 'Sukses',
                'referensi' => $noReferensi.'|'.$akunSumber.'|'.$coa->kode_akun,
                'catatan' => "Transfer pencairan pembiayaan {$pb->no_pembiayaan} akad {$pb->akad_pembiayaan} via bank {$coa->nama_akun}",
            ]),
            $saldoSetelah,
        ];
    }

    /* ══════════════════════════════════════════════════════════════════════
     | SKEMA TRANSFER_SAVINGS — PINDAH BUKU
     ══════════════════════════════════════════════════════════════════════ */

    /**
     * Validasi rekening Simpanan Sukarela tujuan: aktif, milik anggota
     * pembiayaan, dan bertipe simpanan yang diizinkan menerima pencairan.
     *
     * Tipe yang diizinkan dibaca DINAMIS dari master `jenis_simpanans`
     * (tipe 'sukarela' / 'lain'), sehingga pengurus dapat menambah jenis
     * simpanan baru tanpa mengubah kode.
     */
    private function siapkanRekeningSimpanan(Pembiayaan $pb, ?int $savingsAccountId, int $anggotaId): Rekening
    {
        if (! $savingsAccountId) {
            $this->tolak('Rekening Simpanan Sukarela wajib dipilih untuk pencairan pindah buku.', [
                'pembiayaan_id' => $pb->id,
            ]);
        }

        $rekening = Rekening::query()->whereKey($savingsAccountId)->lockForUpdate()->first();

        if (! $rekening) {
            $this->tolak('Rekening Simpanan Sukarela tidak ditemukan.', [
                'savings_account_id' => $savingsAccountId,
            ]);
        }

        if ((int) $rekening->anggota_id !== $anggotaId) {
            $this->tolak('Rekening simpanan bukan milik anggota pemohon pembiayaan.', [
                'savings_account_id' => $savingsAccountId,
                'anggota_id' => $anggotaId,
                'pemilik_rekening' => $rekening->anggota_id,
            ]);
        }

        if ($rekening->status_rekening !== 'Aktif') {
            $this->tolak('Rekening Simpanan Sukarela tujuan tidak aktif.', [
                'savings_account_id' => $savingsAccountId,
                'status_rekening' => $rekening->status_rekening,
            ]);
        }

        if (! $this->tipeSimpananDiizinkanUntukPencairan($rekening->jenis_rekening)) {
            $this->tolak('Jenis simpanan rekening tujuan tidak diizinkan menerima pencairan pembiayaan.', [
                'savings_account_id' => $savingsAccountId,
                'jenis_rekening' => $rekening->jenis_rekening,
            ]);
        }

        return $rekening;
    }

    /** Apakah jenis simpanan ini boleh menerima dana pencairan pembiayaan? */
    private function tipeSimpananDiizinkanUntukPencairan(?string $jenisRekening): bool
    {
        $jenis = (string) $jenisRekening;

        if ($jenis === '') {
            return false;
        }

        // Jenis simpanan aktif yang bertipe sukarela / lain-lain; 'lain' dipakai
        // koperasi yang menamai produknya sendiri (mis. "Wadiah Pencairan").
        $diizinkan = array_merge(
            JenisSimpanan::aktifBertipe(JenisSimpanan::TIPE_SUKARELA),
            JenisSimpanan::aktifBertipe(JenisSimpanan::TIPE_LAIN),
        );

        return in_array($jenis, $diizinkan, true);
    }

    /**
     * Tambah saldo Simpanan Sukarela (pindah buku) + catat transaksi ledger.
     *
     * Tidak ada record kas keluar tunai pada skema ini — uang fisik tidak
     * bergerak, sehingga Laci Teller tidak boleh tersentuh.
     *
     * @return array{0: Transaksi, 1: float}
     */
    private function catatPindahBukuSimpanan(
        Pembiayaan $pb,
        $anggota,
        float $nominal,
        ?int $userId,
        CarbonInterface $tanggal,
        ?Rekening $rekening,
        string $akunSumber,
    ): array {
        if ($rekening === null) {
            $this->tolak('Rekening Simpanan Sukarela tujuan tidak tersedia — pindah buku dibatalkan.');
        }

        $saldoLama = (float) $rekening->saldo;
        $saldoSetelah = $saldoLama + $nominal;

        // Atomic update + locking: increment hanya terpasang setelah baris
        // rekening dikunci, mencegah kehilangan update pada transaksi bersamaan.
        Rekening::query()
            ->whereKey($rekening->getKey())
            ->lockForUpdate()
            ->update(['saldo' => $saldoSetelah]);

        $rekening->setAttribute('saldo', $saldoSetelah);

        return [
            Transaksi::create([
                'no_transaksi' => $this->nomorTransaksi('SVG-IN'),
                'waktu_transaksi' => now(),
                'rekening_id' => $rekening->id,
                'anggota_id' => $anggota->id,
                'pembiayaan_id' => $pb->id,
                'jenis_transaksi' => 'Simpanan Sukarela',
                'posisi' => 'Kredit',
                'jumlah' => $nominal,
                'saldo_setelah' => $saldoSetelah,
                'user_id' => $userId,
                'sumber_transaksi' => 'Auto',
                'status_transaksi' => 'Sukses',
                'referensi' => $akunSumber,
                'catatan' => "Pindah buku pencairan pembiayaan {$pb->no_pembiayaan} ke rekening {$rekening->no_rekening}",
            ]),
            $saldoSetelah,
        ];
    }

    /* ══════════════════════════════════════════════════════════════════════
     | PENJADWALAN & JURNAL
     ══════════════════════════════════════════════════════════════════════ */

    /**
     * Buat jadwal angsuran pembiayaan.
     *
     * @param  array<int, Carbon>  $jadwal  kunci = angsuran ke-, nilai = tanggal jatuh tempo
     */
    private function buatJadwalAngsuran(
        Pembiayaan $pb,
        array $jadwal,
        float $marginTotal,
        ?int $userId,
        int $anggotaId,
        ?int $transaksiId,
    ): void {
        $jumlahAngsuran = max(1, count($jadwal));
        $pokokPerAngsuran = Setting::bulatkanUang((float) $pb->jumlah_pembiayaan / $jumlahAngsuran);
        $marginPerAngsuran = Setting::bulatkanUang($marginTotal / $jumlahAngsuran);
        $totalPerAngsuran = $pokokPerAngsuran + $marginPerAngsuran;
        $sisaPokok = (float) $pb->jumlah_pembiayaan;

        foreach ($jadwal as $ke => $tanggalJatuhTempo) {
            $sisaPokok = Setting::bulatkanUang($sisaPokok - $pokokPerAngsuran);

            Angsuran::updateOrCreate(
                ['pembiayaan_id' => $pb->id, 'angsuran_ke' => $ke],
                [
                    'transaksi_id' => $transaksiId,
                    'tanggal_jatuh_tempo' => $tanggalJatuhTempo->toDateString(),
                    'pokok_angsuran' => $pokokPerAngsuran,
                    'margin_ujroh' => $marginPerAngsuran,
                    'jumlah_angsuran' => $totalPerAngsuran,
                    'denda' => 0,
                    'total_bayar' => 0,
                    'sisa_pokok' => max(0, $sisaPokok),
                    'status_angsuran' => 'Belum Jatuh Tempo',
                    'hari_terlambat' => 0,
                    'user_id' => $userId,
                    'catatan' => "Jadwal pencairan pembiayaan {$pb->no_pembiayaan}",
                ]
            );
        }
    }

    /**
     * Posting jurnal pencairan PSAK 108 (DEBET Piutang/Investasi [Pokok+Margin],
     * KREDIT sumber dana [Pokok], KREDIT Margin Ditangguhkan [Margin] bila ada).
     *
     * Akun piutang & margin ditangguhkan diambil DINAMIS dari CoaResolver.
     * Bila akun piutang tidak dapat diresolusi, jurnal dibatalkan dan exception
     * dilempar sehingga seluruh pencairan di-rollback — mencegah setoran dana
     * tanpa pengakuan piutang.
     */
    private function postingJurnal(
        Pembiayaan $pb,
        DisbursementMethod $metode,
        $anggota,
        ?int $userId,
        float $nominalPokok,
        float $margin,
        string $akunSumber,
        ?int $transaksiId,
        ?int $rekeningId,
    ): void {
        // Guard: pastikan akun piutang/penyertaan akad ini benar-benar ada &
        // aktif sebelum jurnal dibuat. Kode produk diteruskan agar pemetaan
        // akad→CoA dapat dipersempit per produk (Tahap 11); produk yang belum
        // dipetakan otomatis jatuh ke pemetaan '*' (semua produk).
        $akunPiutang = $this->coaResolver->kodePiutangByAkad(
            $pb->akad_pembiayaan,
            $pb->produk?->kode_produk ?: PemetaanAkadCoa::SEMUA_PRODUK
        );

        if (! $this->coaAktif($akunPiutang)) {
            $this->tolak(
                "Akun piutang/investasi untuk akad {$pb->akad_pembiayaan} belum dikonfigurasi (kode {$akunPiutang}).",
                ['pembiayaan_id' => $pb->id, 'kode_akun' => $akunPiutang]
            );
        }

        $keterangan = "Pencairan pembiayaan {$pb->no_pembiayaan} - {$anggota->nama_lengkap}";

        $jurnal = $this->journalService->jurnalPencairan(
            $nominalPokok,
            $pb->akad_pembiayaan ?: Pembiayaan::AKAD_MURABAHAH,
            $keterangan,
            $userId,
            $transaksiId,
            $pb->id,
            $rekeningId,
            (int) $anggota->id,
            $margin,
            $akunSumber,
            $metode->value,
            $pb->produk?->kode_produk ?: PemetaanAkadCoa::SEMUA_PRODUK,
        );

        if ($jurnal === null) {
            $this->tolak(
                "Jurnal pencairan pembiayaan {$pb->no_pembiayaan} gagal dibentuk — periksa kelengkapan CoA (piutang/margin ditangguhkan/sumber dana).",
                ['pembiayaan_id' => $pb->id, 'akun_sumber' => $akunSumber, 'margin' => $margin]
            );
        }
    }

    /* ══════════════════════════════════════════════════════════════════════
     | UTILITAS INTERNAL
     ══════════════════════════════════════════════════════════════════════ */

    /**
     * Hitung saldo buku besar sebuah CoA dari jurnal_detail (dinamis).
     * Konvensi akun aset/kewajiban: Debet - Kredit.
     */
    private function saldoBukuBesar(Coa $coa): float
    {
        $baris = DB::table('jurnal_detail as jd')
            ->join('jurnal as j', 'j.id', '=', 'jd.jurnal_id')
            ->where('jd.coa_id', $coa->id)
            ->selectRaw('COALESCE(SUM(jd.debet), 0) AS total_debet, COALESCE(SUM(jd.kredit), 0) AS total_kredit')
            ->first();

        return Setting::bulatkanUang((float) ($baris->total_debet ?? 0) - (float) ($baris->total_kredit ?? 0));
    }

    /** Apakah kode akun CoA ada dan aktif? */
    private function coaAktif(string $kode): bool
    {
        return Coa::query()->where('kode_akun', $kode)->where('is_active', true)->exists();
    }

    /**
     * Isi ulang kolom saldo_sistem sesi Laci Teller dari mutasi kas hari itu
     * (KasHarian::hitungSaldoKasTeller) — sumber tunggal perhitungan saldo kas.
     */
    private function isiSaldoSesiTeller(KasHarian $sesi, ?int $userId, CarbonInterface $tanggal): void
    {
        $rekap = KasHarian::hitungSaldoKasTeller((int) $userId, $tanggal->toDateString());

        $sesi->setAttribute('saldo_sistem', Setting::bulatkanUang((float) $rekap['saldo']));

        KasHarian::query()
            ->whereKey($sesi->getKey())
            ->lockForUpdate()
            ->update(['saldo_sistem' => Setting::bulatkanUang((float) $rekap['saldo'])]);
    }

    /**
     * Margin yang diakui sebagai PENDAPATAN DITANGGUHKAN saat pencairan.
     *
     * Hanya akad yang memiliki akun Margin Ditangguhkan (Murabahah/Istishna/
     * Salam) yang menghasilkan nilai > 0; akad bagi hasil, sewa, dan Qardh
     * selalu 0 karena pendapatannya diakui bertahap, bukan ditangguhkan.
     */
    private function marginUntuk(Pembiayaan $pb, string $akad): float
    {
        // Snapshot margin yang sudah tersimpan lebih diutamakan (idempoten).
        if ($pb->margin_deferred_amount !== null && (float) $pb->margin_deferred_amount > 0) {
            return Setting::bulatkanUang((float) $pb->margin_deferred_amount);
        }

        // Akad tanpa margin ditangguhkan (Mudharabah/Musyarakah/Ijarah/Qardh) → 0.
        // Kode produk diteruskan agar akun margin ditangguhkan dapat dipetakan
        // per produk (Tahap 11), konsisten dengan akun piutang.
        if ($this->coaResolver->kodeMarginDitangguhkanByAkad(
            $akad,
            $pb->produk?->kode_produk ?: PemetaanAkadCoa::SEMUA_PRODUK
        ) === null) {
            return 0.0;
        }

        return $this->selisihMargin($pb);
    }

    /**
     * Total margin/ujroh yang dibagi ke seluruh jadwal angsuran (semua akad).
     * Berbeda dari marginUntuk(): nilai ini TIDAK bergantung pada ada/tidaknya
     * akun Margin Ditangguhkan, karena setiap akad tetap menagih margin.
     */
    private function marginJadwal(Pembiayaan $pb): float
    {
        return $this->selisihMargin($pb);
    }

    /** Total margin/ujroh pembiayaan: kolom total_ujroh, atau selisih total - pokok. */
    private function selisihMargin(Pembiayaan $pb): float
    {
        $margin = Setting::bulatkanUang((float) $pb->total_ujroh);

        if ($margin <= 0) {
            $margin = Setting::bulatkanUang((float) $pb->total_pembiayaan - (float) $pb->jumlah_pembiayaan);
        }

        return max(0.0, $margin);
    }

    /**
     * Normalisasi tanggal jatuh tempo agar selalu hari kerja koperasi
     * (konsisten dengan aturan penjadwalan angsuran).
     */
    private function keCarbon(CarbonInterface|string|null $tanggal): Carbon
    {
        $carbon = $tanggal instanceof CarbonInterface
            ? Carbon::instance($tanggal->toDateTime())
            : ($tanggal ? Carbon::parse($tanggal) : now()->copy());

        return $carbon;
    }

    /** Nomor referensi unik untuk pelacakan transaksi pencairan. */
    private function nomorTransaksi(string $prefix): string
    {
        return $prefix.'-'.now()->format('YmdHis').'-'.strtoupper(Str::random(6));
    }

    /**
     * Tolak permintaan dengan exception yang TERCATAT di log sistem.
     *
     * @throws \RuntimeException
     */
    private function tolak(string $pesan, array $konteks = []): never
    {
        Log::error('Pencairan pembiayaan ditolak: '.$pesan, $konteks);

        throw new \RuntimeException($pesan);
    }
}
