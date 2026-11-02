<?php

namespace App\Services;

use App\Models\Angsuran;
use App\Models\DanaKebajikan;
use App\Models\KebijakanKoperasi;
use App\Models\Rekening;
use App\Models\TazirPenangguhan;
use App\Models\Transaksi;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Kalkulator Ta'zir (Denda Keterlambatan Syariah)
 *
 * Ta'zir bersifat edukatif — bukan sumber pendapatan koperasi.
 * Seluruh hasil ta'zir dialokasikan ke Dana Kebajikan (2-3001) sesuai PSAK 109.
 */
class TazirService
{
    public function __construct(
        private AutoJournalingService $journalService
    ) {}

    /**
     * Saklar tunggal fitur denda keterlambatan angsuran pembiayaan.
     *
     * Kebijakan PSAK 108/109 + Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000:
     * denda Ta'zir hanya boleh DIBEBANKAN bila pengurus telah mengaktifkannya
     * secara eksplisit melalui parameter `financing_late_fee_per_day`.
     * Nilai 0 (default) berarti fitur NONAKTIF — tidak boleh ada denda yang
     * dihitung, dipotong, maupun diposting ke Dana Kebajikan (2-3001).
     *
     * Mode ta'zir bernilai 'DISABLED' juga menutup fitur ini.
     */
    public function fiturAktif(): bool
    {
        $config = KebijakanKoperasi::getSetting();

        $mode = $config->tazir_mode ?? Setting::get('tazir_mode', 'FLAT_HARIAN');
        if ($mode === 'DISABLED') {
            return false;
        }

        $tarif = (float) ($config->financing_late_fee_per_day
            ?? Setting::financingLateFeePerDay());

        return $tarif > 0;
    }

    /**
     * Hitung nominal ta'zir untuk satu baris jadwal angsuran.
     *
     * @return float Nominal ta'zir (0 jika tidak terlambat / dibebaskan / mode DISABLED)
     */
    public function calculate(Angsuran $angsuran): float
    {
        $config = KebijakanKoperasi::getSetting();

        // Sudah dibayar → tidak dikenakan ta'zir
        if ($angsuran->tanggal_bayar !== null) {
            return 0.0;
        }

        // Belum jatuh tempo → tidak dikenakan ta'zir
        if ($angsuran->tanggal_jatuh_tempo === null) {
            return 0.0;
        }

        // Fitur denda belum diaktifkan pengurus → tidak ada dasar pemungutan.
        if (! $this->fiturAktif()) {
            return 0.0;
        }

        $jatuhTempo = Carbon::parse($angsuran->tanggal_jatuh_tempo)->startOfDay();
        $today = Carbon::today();

        if ($today->lte($jatuhTempo)) {
            return 0.0;
        }

        // Mode ta'zir efektif — fallback diselaraskan dengan parameter kebijakan
        // (satu sumber: Setting/KebijakanKoperasi), bukan 'DISABLED' yang berbeda sendiri.
        $modeTazir = $config->tazir_mode ?? Setting::get('tazir_mode', 'FLAT_HARIAN');

        // Mode DISABLED → selalu 0
        if ($modeTazir === 'DISABLED') {
            return 0.0;
        }

        $hariTerlambat = (int) $jatuhTempo->diffInDays($today);

        // Kurangi grace period
        $gracePeriod = max(0, (int) ($config->tazir_grace_period_days ?? Setting::get('tazir_grace_period_days', 0)));
        $hariEfektif = $hariTerlambat - $gracePeriod;

        if ($hariEfektif <= 0) {
            return 0.0;
        }

        $rate = (float) ($config->tazir_rate ?? Setting::get('tazir_rate', 2000.00));

        return match ($modeTazir) {
            'FLAT_HARIAN' => Setting::bulatkanUang($hariEfektif * $rate),
            'FIXED_PER_KEJADIAN' => Setting::bulatkanUang($rate),
            default => 0.0,
        };
    }

    /**
     * Kembalikan detail kalkulasi (untuk ditampilkan di UI teller).
     *
     * S-1: `hari_terlambat` & `hari_efektif` HANYA bermakna bila fitur denda
     * aktif. Bila `fiturAktif()` false, keduanya dikembalikan 0 dan `mode`
     * dilaporkan 'DISABLED' — kontrak JSON ini juga dikonsumsi konsumen API
     * lain, sehingga tidak boleh membocorkan angka keterlambatan yang tidak
     * punya dasar penagihan. UI teller memakai cabang `mode === 'DISABLED'`
     * untuk menyatakan "tidak ada denda keterlambatan".
     */
    public function detail(Angsuran $angsuran): array
    {
        $config = KebijakanKoperasi::getSetting();
        $nominal = $this->calculate($angsuran);
        $fiturAktif = $this->fiturAktif();

        $hariTerlambat = 0;
        if ($fiturAktif && $angsuran->tanggal_jatuh_tempo && ! $angsuran->tanggal_bayar) {
            $jatuhTempo = Carbon::parse($angsuran->tanggal_jatuh_tempo)->startOfDay();
            $today = Carbon::today();
            if ($today->gt($jatuhTempo)) {
                $hariTerlambat = (int) $jatuhTempo->diffInDays($today);
            }
        }

        $gracePeriod = max(0, (int) ($config->tazir_grace_period_days ?? Setting::get('tazir_grace_period_days', 0)));
        $mode = $config->tazir_mode ?? Setting::get('tazir_mode', 'FLAT_HARIAN');

        return [
            'nominal' => $nominal,
            'hari_terlambat' => $hariTerlambat,
            'grace_period' => $gracePeriod,
            'hari_efektif' => max(0, $hariTerlambat - $gracePeriod),
            'rate' => (float) ($config->tazir_rate ?? Setting::get('tazir_rate', 2000.00)),
            'mode' => $fiturAktif ? $mode : 'DISABLED',
            'default_method' => $config->tazir_default_method ?? Setting::get('tazir_default_method', 'TUNAI'),
        ];
    }

    /**
     * Eksekusi Autodebet Susulan atas tunggakan ta'zir ber-flag
     * PENDING_AUTODEBET_SUSULAN (keringanan ditolak Manajer saat saldo tidak cukup).
     *
     * Dipicu otomatis oleh sistem pada dua momen:
     *   1) Ada setoran tunai Simpanan Sukarela baru dari anggota (TellerController@setorStore).
     *   2) Akumulasi pada jadwal autodebet angsuran periode berikutnya
     *      (Artisan command angsuran:run-autodebet / Cron Job).
     *
     * SAFEGUARD:
     *   - Hanya memotong selama saldo TETAP >= batas_minimum rekening
     *     (DILARANG memotong hingga minus).
     *   - Ta'zir baru dibukukan ke Dana Kebajikan (2-3001) saat benar-benar
     *     dipotong — Cash Basis Syariah / PSAK 109.
     *   - Setiap pemotongan dibungkus DB::transaction() dengan jurnal
     *     double-entry balance: DEBET 2-1001 | KREDIT 2-3001.
     *
     * @param  int|null  $tunggakanId  Bila diisi, HANYA tunggakan ini yang
     *                                 diproses (dipakai saat konfirmasi manual
     *                                 satu usulan di Pusat Konfirmasi Auto-Debit).
     * @return float Total nominal ta'zir yang berhasil dipotong susulan.
     */
    public function prosesAutodebetSusulan(Rekening $rekening, ?int $userId = null, ?int $tunggakanId = null, bool $viaKonfirmasi = false): float
    {
        if (! $rekening->anggota_id) {
            return 0.0;
        }

        // Saklar tunggal fitur denda: bila ta'zir NONAKTIF, tidak boleh ada
        // pemotongan susulan (tiada pembebanan tanpa dasar kebijakan aktif).
        if (! $this->fiturAktif()) {
            return 0.0;
        }

        /* GERBANG KEBIJAKAN INDUK (TIDAK ADA AUTO-DEBIT TANPA KONFIRMASI).
         | Pemotongan susulan mengambil dana anggota dari Simpanan Sukarela.
         | Selama kebijakan induk aktif, hanya eksekusi usulan yang sudah
         | dikonfirmasi pengguna berwenang yang boleh memotong. */
        if (! $viaKonfirmasi && Setting::autodebitWajibKonfirmasi()) {
            Log::warning("TazirService: pemotongan ta'zir susulan ditolak — kebijakan wajib konfirmasi pengguna aktif.", [
                'rekening_id' => $rekening->id,
                'anggota_id' => $rekening->anggota_id,
                'tunggakan_id' => $tunggakanId,
            ]);

            return 0.0;
        }

        $query = TazirPenangguhan::pendingAutodebetSusulan()
            ->with(['angsuran', 'pembiayaan.anggota'])
            ->where('anggota_id', $rekening->anggota_id);

        if ($tunggakanId !== null) {
            $query->whereKey($tunggakanId);
        }

        $tunggakanList = $query->orderBy('id')->get();

        $totalDipotong = 0.0;
        $terpotongDalamLoop = 0.0;

        foreach ($tunggakanList as $tunggakan) {
            $nominal = (float) $tunggakan->nominal_tazir;

            if ($nominal <= 0) {
                continue;
            }

            // SAFEGUARD: saldo tidak boleh minus → wajib menyisakan batas_minimum.
            // Jika tidak cukup, hentikan; sisanya tetap PENDING_AUTODEBET_SUSULAN.
            // Diuji terhadap saldo EFEKTIF (saldo baris luar − akumulasi potongan
            // loop). `$rekening` di-capture BY VALUE oleh closure (tanpa `&`)
            // sehingga object luar TIDAK ikut ter-mutasi; pelacak inilah yang
            // menggantikan refresh-nya. Tanpa pelacak, iterasi ke-2 dst. membaca
            // saldo BASI dan dapat memotong melebihi saldo/ menembus batas_minimum.
            $saldoEfektif = (float) $rekening->saldo - $terpotongDalamLoop;
            if (($saldoEfektif - $nominal) < (float) $rekening->batas_minimum) {
                break;
            }

            DB::transaction(function () use ($rekening, $tunggakan, $nominal, $userId, &$totalDipotong, &$terpotongDalamLoop) {
                // TAHAP 5 — INTEGRITAS SALDO: kunci ulang baris rekening DI DALAM
                // transaksi. Pemeriksaan di luar transaksi hanya saringan awal;
                // keputusan potong yang MENGIKAT diambil atas saldo TERKUNCI.
                // `$rekeningKunci->saldo` sudah memperhitungkan potongan iterasi
                // sebelumnya (decrement tersimpan), jadi TIDAK ditambah pelacak.
                // Bila tidak cukup → batal dalam senyap (return; transaksi kosong),
                // TIDAK menggagalkan pemrosesan tunggakan lain.
                $rekeningKunci = Rekening::whereKey($rekening->id)->lockForUpdate()->firstOrFail();

                if (! $rekeningKunci->isDapatDitarik($nominal)) {
                    return;
                }

                $rekeningKunci->decrement('saldo', $nominal);
                $rekeningKunci->refresh();
                $saldoSisa = (float) $rekeningKunci->saldo;

                $noPembiayaan = $tunggakan->pembiayaan?->no_pembiayaan ?? '-';
                $namaAnggota = $tunggakan->pembiayaan?->anggota?->nama_lengkap ?? 'Anggota';

                // Mutasi Simpanan Sukarela (pemotongan ta'zir susulan)
                $transaksi = Transaksi::create([
                    'no_transaksi' => 'TRX-TAZIR-'.now()->format('YmdHis').'-'.strtoupper(substr(uniqid(), -4)),
                    'waktu_transaksi' => now(),
                    'rekening_id' => $rekeningKunci->id,
                    'anggota_id' => $tunggakan->anggota_id,
                    'pembiayaan_id' => $tunggakan->pembiayaan_id,
                    'jenis_transaksi' => 'AUTODEBET_TAZIR_SUSULAN',
                    'posisi' => 'Debet',
                    'jumlah' => $nominal,
                    'saldo_setelah' => $saldoSisa,
                    'sumber_transaksi' => 'Autodebet Tazir',
                    'status_transaksi' => 'Sukses',
                    'status' => 'success',
                    'catatan' => "Pemotongan susulan ta'zir (keringanan ditolak Manajer) | {$noPembiayaan}",
                    'user_id' => $userId,
                ]);

                // Penerimaan Dana Kebajikan (cash basis, PSAK 109)
                DanaKebajikan::create([
                    'no_referensi' => 'DK-TAZIR-'.now()->format('YmdHis').'-'.strtoupper(substr(uniqid(), -4)),
                    'sumber_dana' => "Ta'zir Autodebet Susulan - {$namaAnggota} ({$noPembiayaan})",
                    'jenis' => 'Penerimaan',
                    'jumlah' => $nominal,
                    'keterangan' => "Denda ta'zir dipotong otomatis susulan dari Simpanan Sukarela ".
                                      '(keringanan ditolak Manajer)',
                    'created_by' => Auth::user()->name ?? 'System',
                ]);

                // Jurnal double-entry: DEBET 2-1001 | KREDIT 2-3001
                $jurnal = $this->journalService->jurnalTazir(
                    $nominal,
                    "Pemotongan susulan ta'zir autodebet | {$noPembiayaan}",
                    $userId,
                    $transaksi->id,
                    $rekeningKunci->id,
                    $tunggakan->anggota_id
                );

                // `post()` mengembalikan null (bukan throw) bila CoA tidak
                // ditemukan/nonaktif → wajib di-throw agar mutasi saldo anggota
                // di-rollback (pemotongan tanpa jurnal tidak boleh tersimpan).
                if ($jurnal === null) {
                    throw new \RuntimeException("Jurnal ta'zir susulan gagal diposting (CoA tidak ditemukan atau nonaktif).");
                }

                // Tunggakan → LUNAS
                $tunggakan->update([
                    'is_tazir_paid' => true,
                    'flag' => TazirPenangguhan::FLAG_AUTODEBET_LUNAS,
                    'paid_at' => now(),
                    'transaksi_id' => $transaksi->id,
                    'catatan' => trim(($tunggakan->catatan ?? '').' | Dipotong autodebet susulan otomatis.'),
                ]);

                // Status ta'zir pada jadwal angsuran → LUNAS
                $tunggakan->angsuran?->update(['status_tazir' => 'LUNAS']);

                $totalDipotong += $nominal;
                $terpotongDalamLoop += $nominal;
            });
        }

        if ($totalDipotong > 0) {
            Log::info('Autodebet susulan ta\'zir diproses', [
                'rekening_id' => $rekening->id,
                'anggota_id' => $rekening->anggota_id,
                'total_dipotong' => $totalDipotong,
                'user_id' => $userId,
            ]);
        }

        return $totalDipotong;
    }
}
