<?php

namespace App\Services;

use App\Models\Anggota;
use App\Models\AuditLog;
use App\Models\AutodebitUsulan;
use App\Models\JenisSimpanan;
use App\Models\KebijakanKoperasi;
use App\Models\MandatorySavingsBill;
use App\Models\Rekening;
use App\Models\SimpananWajibTindakan;
use App\Models\Transaksi;
use App\Models\User;
use App\Notifications\SimpananWajibDitangguhkanNotification;
use App\Support\Coa;
use App\Support\GuardSimpananModal;
use App\Support\HierarkiRole;
use App\Support\NomorDokumen;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Layanan Simpanan Wajib Bulanan Anggota.
 *
 * Tanggung jawab:
 *  1. Menagih (issue) tagihan Simpanan Wajib bulan berjalan untuk anggota ACTIVE
 *     berdasarkan tanggal bergabung (`tanggal_gabung`) masing-masing anggota.
 *  2. Menyesuaikan tanggal eksekusi untuk anggota yang bergabung pada tanggal
 *     29, 30, atau 31 (End-of-Month) memakai Carbon::isEndOfMonth().
 *  3. Menjalankan Auto-Debit dari saldo Simpanan Sukarela (CoA 2-1001) bila
 *     saldo mencukupi, lalu memposting jurnal PSAK 101/108:
 *       DEBET  2-1001 Simpanan Sukarela
 *       KREDIT 3-1002 Simpanan Wajib Anggota (Modal Anggota)
 *  4. Menandai tagihan OVERDUE + mengirim notifikasi bila saldo tidak cukup.
 *
 * DUA JALUR PEMBAYARAN (dipilih DINAMIS, tanpa hardcode):
 *  - JALUR B — Auto-Debit Pindah Buku: aktif HANYA bila produk Simpanan Wajib
 *    mengizinkan (`jenis_simpanans.allow_auto_debit == true`) DAN anggota
 *    menyetujui (`anggota.is_auto_debit == true`). Saldo Sukarela dipotong,
 *    jurnal DEBET 2-1001 | KREDIT 3-1002, saldo fisik Kas Teller TIDAK disentuh.
 *  - JALUR A — Manual Teller (DEFAULT): tagihan dibiarkan UNPAID → OVERDUE,
 *    dilunasi di loket Teller dengan jurnal DEBET 1-1001 | KREDIT 3-1002.
 *
 * Nominal tagihan TIDAK di-hardcode: diambil dari master produk
 * `jenis_simpanans.min_deposit_amount` (Simpanan Wajib), dengan fallback
 * parameter koperasi `kebijakan_koperasis.mandatory_monthly_savings`.
 */
class MandatorySavingsService
{
    /** CoA Simpanan Sukarela — sumber dana auto-debit. */
    public const COA_SIMPANAN_SUKARELA = Coa::SIMPANAN_SUKARELA;

    /** CoA Simpanan Wajib sisi Modal/Ekuitas — tujuan auto-debit (PSAK 101). */
    public const COA_SIMPANAN_WAJIB_MODAL = Coa::SIMPANAN_WAJIB_MODAL;

    /** CoA Kas Teller / Bank — sumber pembayaran manual di Teller (PSAK 101). */
    public const COA_KAS_TELLER = Coa::KAS_TELLER;

    /** Nama produk simpanan yang diproses modul ini (Simpanan Wajib bulanan). */
    public const PRODUK_WAJIB = 'Simpanan Wajib';

    /** Nama produk simpanan wajib aktif (mengikuti master, DYN-10). */
    private static function produkWajib(): string
    {
        return JenisSimpanan::namaUtama(JenisSimpanan::TIPE_WAJIB, self::PRODUK_WAJIB);
    }

    /**
     * Jam posting transaksi auto-debit Simpanan Wajib (DYN-17).
     *
     * Dulu konstanta kelas `'00:01'`. Sekarang dibaca dari kebijakan
     * (`Setting::jamPostingSimpananWajib()`) agar Pengurus dapat menggeser
     * jam posting tanpa deploy ulang. Method ini tetap menjadi SATU titik
     * agar `waktu_transaksi` dan `paid_at` selalu identik.
     */
    private function jamPosting(): string
    {
        return Setting::jamPostingSimpananWajib();
    }

    /**
     * Alasan penangguhan kanonik saat seluruh prasyarat auto-debit TERPENUHI
     * namun sistem menahan pemotongan sampai pengguna mengonfirmasi.
     *
     * Dipakai oleh processDaily() (kanal Simpanan Wajib) agar jejak audit
     * membedakan "menunggu konfirmasi karena syarat lengkap" dari penangguhan
     * karena kegagalan verifikasi (mis. saldo tidak cukup).
     */
    public const ALASAN_MENUNGGU_KONFIRMASI = 'MENUNGGU_KONFIRMASI';

    public function __construct(
        private AutoJournalingService $journaling,
        private ShuCalculatorService $calculator,
    ) {}

    /* =====================================================================
     | 0. RESOLUSI AKUN & PARAMETER (SATU SUMBER KEBENARAN)
     |===================================================================== */

    /**
     * Akun COA kanonik Simpanan Wajib — TITIK RESOLUSI TUNGGAL (Tahap 12 / R1).
     *
     * Rantai resolusi (berhenti pada nilai pertama yang valid & aktif):
     *   1. Kebijakan Koperasi (`kebijakan_koperasis.account_simpanan_wajib_id`)
     *      — diisi Pengurus dari UI tanpa menyentuh kode.
     *   2. Master produk Simpanan (`jenis_simpanans.kode_akun` untuk
     *      "Simpanan Wajib") — sumber yang sama dengan UI Jenis Simpanan.
     *   3. Registri standar `App\Support\Coa::SIMPANAN_WAJIB_MODAL` (3-1002).
     *
     * Dengan pola ini akun Simpanan Wajib dapat dipindahkan tanpa deploy
     * ulang, sementara perilaku jurnal tetap IDENTIK selama parameter belum
     * diubah (fallback = 3-1002, sesuai PSAK 101 — Simpanan Wajib sebagai
     * Modal Anggota).
     */
    public function akunSimpananWajib(): string
    {
        $fallback = static::COA_SIMPANAN_WAJIB_MODAL;

        // 1. Kebijakan koperasi (pemetaan dinamis dari UI Pengurus).
        $idKebijakan = Setting::akunSimpananWajibId();

        if ($idKebijakan !== null) {
            $kode = \App\Models\Coa::query()
                ->where('id', $idKebijakan)
                ->where('is_active', true)
                ->value('kode_akun');

            if (is_string($kode) && $kode !== '') {
                return $kode;
            }

            Log::warning('MandatorySavingsService: account_simpanan_wajib_id menunjuk CoA yang tidak ada/nonaktif — memakai pemetaan standar.', [
                'coa_id' => $idKebijakan,
                'fallback' => $fallback,
            ]);
        }

        // 2. Master produk Simpanan (satu sumber dengan UI Jenis Simpanan).
        $kodeProduk = JenisSimpanan::coaKode(self::produkWajib());

        return $kodeProduk ?: $fallback;
    }

    /**
     * Apakah fitur penarikan saldo Simpanan Wajib sedang dibuka?
     *
     * Dipakai sebagai gerbang tunggal modul penarikan (Tahap 12 / R3).
     */
    public function penarikanDiizinkan(): bool
    {
        return Setting::simpananWajibBolehDitarik();
    }

    /**
     * Nominal tagihan Simpanan Wajib bulanan — 100% DINAMIS (Zero-Hardcoding).
     *
     * Rantai resolusi (berhenti pada nilai pertama yang > 0):
     *   1. Master produk `jenis_simpanans.min_deposit_amount` (Simpanan Wajib)
     *      — dikonfigurasi Admin/Manager lewat UI Jenis Simpanan.
     *   2. Parameter koperasi `kebijakan_koperasis.mandatory_monthly_savings`.
     *
     * Dipakai saat menerbitkan tagihan baru (`issueBill`) sehingga perubahan
     * nominal produk langsung berlaku tanpa deploy ulang.
     */
    public function nominalTagihan(): float
    {
        $nominalProduk = JenisSimpanan::minimalSetoranWajib(0);

        if ($nominalProduk > 0) {
            return (float) $nominalProduk;
        }

        return (float) KebijakanKoperasi::simpananWajibBulanan();
    }

    /**
     * Apakah anggota ini memenuhi SELURUH syarat auto-debit (JALUR B)?
     *
     * Syarat (dua gerbang, keduanya wajib benar):
     *   1. Produk Simpanan Wajib mengizinkan auto-debit
     *      (`jenis_simpanans.allow_auto_debit == true`).
     *   2. Anggota menyetujui auto-debit (`anggota.is_auto_debit == true`).
     *
     * Titik resolusi tunggal supaya tidak ada jalur auto-debit yang lolos
     * tanpa otorisasi produk.
     */
    public function bolehAutoDebit(Anggota $anggota): bool
    {
        // Saklar induk modul Auto-Debet (kebijakan Pengurus). Nonaktif =
        // seluruh anggota dialihkan ke JALUR A (manual Teller) tanpa
        // memotong saldo Simpanan Sukarela siapa pun.
        if (! $this->autoDebitAktif()) {
            return false;
        }

        return JenisSimpanan::bolehAutoDebit(self::produkWajib(), (bool) $anggota->is_auto_debit);
    }

    /** Saklar induk modul Auto-Debet (on/off dari kebijakan koperasi). */
    public function autoDebitAktif(): bool
    {
        return Setting::simpananWajibAutoDebitAktif();
    }

    /** Saklar modul penanganan dinamis tunggakan (bertahap & reschedule). */
    public function penangananDinamisAktif(): bool
    {
        return Setting::simpananWajibPenangananDinamis();
    }

    /* =====================================================================
     | 0. VERIFIKASI STATUS PEMBAYARAN (GERBANG PENYETORAN OTOMATIS) — BUG-03
     |===================================================================== */

    /**
     * VERIFIKASI STATUS PEMBAYARAN sebelum setoran otomatis dijalankan.
     *
     * ROOT CAUSE (BUG-03): semula `processDaily()` langsung memanggil
     * `autoDebit()` tepat setelah `issueBill()` menerbitkan tagihan UNPAID.
     * Karena tagihan yang baru terbit SELALU berstatus UNPAID, pemeriksaan
     * `$bill->isPaid()` tidak pernah mencegah pemotongan — scheduler
     * MENGASUMSIKAN pembayaran pasti terjadi lalu memotong saldo Simpanan
     * Sukarela setiap tanggal jatuh tempo tanpa verifikasi.
     *
     * Gerbang ini menjawab satu pertanyaan tunggal: "Apakah setoran OTOMATIS
     * boleh dijalankan untuk tagihan ini?" Setoran HANYA boleh berjalan bila
     * SELURUH prasyarat di bawah benar — jika tidak, dana anggota TIDAK boleh
     * dipotong dan tagihan masuk jalur penangguhan (PENDING_CONFIRMATION).
     *
     * Pemeriksaan bersifat DINAMIS (tanpa hardcode) dan TIDAK mengubah nominal.
     */
    public function verifikasiStatusPembayaran(MandatorySavingsBill $bill, Anggota $anggota, ?Carbon $tanggal = null): array
    {
        $tanggal = ($tanggal ?? Carbon::today())->copy()->startOfDay();

        // 1) Tagihan wajib ada & periode konsisten dengan jatuh tempo anggota.
        if ((string) $bill->bill_period === '') {
            return ['boleh' => false, 'alasan' => 'PERIODE_TIDAK_VALID'];
        }

        // 2) Tagihan yang sudah LUNAS tidak perlu disetor lagi (idempoten).
        if ($bill->isPaid()) {
            return ['boleh' => false, 'alasan' => 'SUDAH_LUNAS'];
        }

        // 3) Otorisasi auto-debit (produk Simpanan Wajib + persetujuan anggota).
        if (! $this->bolehAutoDebit($anggota)) {
            return ['boleh' => false, 'alasan' => 'TIDAK_BERHAK_AUTO_DEBIT'];
        }

        // 4) Keanggotaan harus ACTIVE (anggota NONAKTIF tidak boleh dipotong).
        //    Memakai helper kanonik `isActive()` agar alias status legacy tetap
        //    dikenali (Zero-Hardcoding — sumber tunggal `status_anggota`).
        if (! $anggota->isActive()) {
            return ['boleh' => false, 'alasan' => 'ANGGOTA_TIDAK_AKTIF'];
        }

        // 5) Nominal tagihan harus valid (> 0) agar tidak memotong saldo nol.
        if (Setting::bulatkanUang((float) $bill->amount) <= 0) {
            return ['boleh' => false, 'alasan' => 'NOMINAL_TIDAK_VALID'];
        }

        // 6) KECUKUPAN SALDO Simpanan Sukarela — diverifikasi SEBELUM memotong.
        //    Bila saldo < nominal, setoran otomatis DIBATALKAN (tanpa memotong
        //    sebagian pun) dan tagihan ditangguhkan untuk konfirmasi manual.
        $saldo = $this->saldoSukarela($anggota);

        if ($saldo + 0.001 < Setting::bulatkanUang((float) $bill->amount)) {
            return [
                'boleh' => false,
                'alasan' => 'SALDO_TIDAK_CUKUP',
                'saldo' => $saldo,
            ];
        }

        return [
            'boleh' => true,
            'alasan' => 'TERVERIFIKASI',
            'saldo' => $saldo,
        ];
    }

    /**
     * Apakah setoran OTOMATIS boleh dijalankan (pintasan boolean dari
     * `verifikasiStatusPembayaran()`). Dipakai sebagai gerbang tunggal agar
     * tidak ada jalur auto-debit yang lolos tanpa verifikasi status pembayaran.
     */
    public function bolehSetorOtomatis(MandatorySavingsBill $bill, Anggota $anggota, ?Carbon $tanggal = null): bool
    {
        return (bool) $this->verifikasiStatusPembayaran($bill, $anggota, $tanggal)['boleh'];
    }

    /* =====================================================================
     | 1. ORKESTRASI HARIAN (dipanggil command mandatory-savings:process-daily)
     |===================================================================== */

    /**
     * Proses harian: terbitkan tagihan + jalankan auto-debit.
     *
     * @param  Carbon|null  $tanggal  Tanggal proses (default: hari ini).
     * @param  bool  $dryRun  Bila true, tidak ada perubahan data.
     * @param  int|null  $userId  Pengguna pencatat jurnal.
     * @return array{issued:int, paid:int, overdue:int, ditangguhkan:int, skipped:int, gagal:int, detail:array<int,array{no_anggota:?string, nama_anggota:?string, periode:string, amount:float, status:string, pesan:?string}>}
     */
    public function processDaily(?Carbon $tanggal = null, bool $dryRun = false, ?int $userId = null): array
    {
        $tanggal = ($tanggal ?? Carbon::today())->copy()->startOfDay();
        $periode = MandatorySavingsBill::periodDari($tanggal);

        $hasil = [
            'issued' => 0,
            'paid' => 0,
            'overdue' => 0,
            'ditangguhkan' => 0,
            'skipped' => 0,
            'gagal' => 0,
            'detail' => [],
        ];

        $anggotaList = Anggota::aktif()->orderBy('id')->get();

        foreach ($anggotaList as $anggota) {
            try {
                if (! $this->terjadwalHariIni($anggota, $tanggal)) {
                    $hasil['skipped']++;

                    continue;
                }

                if ($dryRun) {
                    $hasil['detail'][] = [
                        'no_anggota' => $anggota->no_anggota,
                        'nama_anggota' => $anggota->nama_lengkap,
                        'periode' => $periode,
                        'amount' => $this->nominalTagihan(),
                        'status' => 'DRY_RUN',
                        'pesan' => 'Rencana penerbitan tagihan (tidak ada data yang ditulis).',
                    ];
                    $hasil['issued']++;

                    continue;
                }

                $bill = $this->issueBill($anggota, $periode, $tanggal);

                if ($bill === null) {
                    $hasil['skipped']++;

                    continue;
                }

                if ($bill->wasRecentlyCreated) {
                    $hasil['issued']++;
                }

                if ($bill->isPaid()) {
                    $hasil['skipped']++;

                    continue;
                }

                /* Idempoten antar-jadwal: tagihan yang sudah ditangguhkan
                 | menunggu konfirmasi manual TIDAK boleh diverifikasi ulang
                 | setiap kali command dijalankan. Tanpa gerbang ini, jadwal
                 | per jam akan mencatat audit & mengirim notifikasi ke
                 | administrator berulang-ulang untuk tagihan yang sama.
                 | Keputusan berikutnya ada di tangan pengguna berwenang. */
                if ($bill->status === MandatorySavingsBill::STATUS_PENDING_CONFIRMATION) {
                    $hasil['skipped']++;

                    continue;
                }

                /* PEMILIHAN JALUR PEMBAYARAN (DINAMIS — Zero-Hardcoding).
                 |
                 |  JALUR B (OPT-IN — AUTO-DEBIT PINDAH BUKU) hanya aktif bila
                 |  SEMUA syarat berikut terpenuhi:
                 |    1. Produk Simpanan Wajib mengizinkan auto-debit
                 |       (`jenis_simpanans.allow_auto_debit == true`) — dikonfigurasi
                 |       Admin/Manager lewat master produk, BUKAN hardcode.
                 |    2. Anggota menyetujui auto-debit
                 |       (`anggota.is_auto_debit == true`).
                 |    3. (Divalidasi di autoDebit()) Rekening sumber Simpanan
                 |       Sukarela tersedia dan saldonya mencukupi.
                 |
                 |  JALUR A (DEFAULT — MANUAL TELLER) berlaku pada semua kondisi
                 |  lain: tagihan DIBIARKAN 'UNPAID' agar dapat dilunasi Teller
                 |  kapan saja (jurnal DEBET 1-1001 / KREDIT 3-1002 lewat
                 |  bayarViaTeller()), lalu menjadi OVERDUE setelah jatuh tempo. */
                if (! $this->bolehAutoDebit($anggota)) {
                    $alasan = JenisSimpanan::produkIzinkanAutoDebit(self::produkWajib())
                        ? 'Anggota memilih pembayaran via Teller — tagihan menunggu pelunasan.'
                        : 'Produk Simpanan Wajib tidak mengizinkan auto-debit — tagihan menunggu pembayaran di Teller.';

                    $hasil['detail'][] = [
                        'no_anggota' => $anggota->no_anggota,
                        'nama_anggota' => $anggota->nama_lengkap,
                        'periode' => $periode,
                        'amount' => (float) $bill->amount,
                        'status' => MandatorySavingsBill::STATUS_UNPAID,
                        'pesan' => $alasan,
                    ];

                    continue;
                }

                /* GERBANG VERIFIKASI STATUS PEMBAYARAN (BUG-03).
                 | Setoran otomatis HANYA dijalankan bila verifikasi lolos.
                 | Bila saldo Sukarela tidak mencukupi (atau prasyarat lain
                 | gagal), dana anggota TIDAK dipotong; tagihan ditangguhkan
                 | (PENDING_CONFIRMATION) + administrator dinotifikasi untuk
                 | konfirmasi manual. Keputusan ini mencegah pemotongan tanpa
                 | verifikasi yang menjadi akar risiko kerugian. */
                $verifikasi = $this->verifikasiStatusPembayaran($bill, $anggota, $tanggal);

                if (! $verifikasi['boleh']) {
                    $this->catatVerifikasiGagal($bill, $anggota, $verifikasi, $userId);

                    if ($dryRun) {
                        $hasil['skipped']++;
                        $hasil['detail'][] = [
                            'no_anggota' => $anggota->no_anggota,
                            'nama_anggota' => $anggota->nama_lengkap,
                            'periode' => $periode,
                            'amount' => (float) $bill->amount,
                            'status' => $bill->status,
                            'pesan' => 'DRY-RUN: verifikasi status pembayaran gagal ('.$verifikasi['alasan'].').',
                        ];

                        continue;
                    }

                    $this->tangguhkanMenungguKonfirmasi($bill, $anggota, $verifikasi['alasan'], $userId);
                    $hasil['ditangguhkan']++;
                    $hasil['detail'][] = [
                        'no_anggota' => $anggota->no_anggota,
                        'nama_anggota' => $anggota->nama_lengkap,
                        'periode' => $periode,
                        'amount' => (float) $bill->amount,
                        'status' => MandatorySavingsBill::STATUS_PENDING_CONFIRMATION,
                        'pesan' => 'Setoran otomatis ditangguhkan — menunggu konfirmasi manual ('.$verifikasi['alasan'].'). Administrator dinotifikasi.',
                    ];

                    continue;
                }

                /* KONFIRMASI WAJIB (KEBIJAKAN TIDAK ADA AUTO-DEBIT TANPA KONFIRMASI).
                 | Pada TAHAP INI seluruh prasyarat auto-debit sudah terpenuhi
                 | (produk mengizinkan, anggota menyetujui, periode valid, belum
                 | lunas, anggota aktif, nominal valid, saldo Sukarela cukup).
                 | Namun sistem DILARANG memotong dana secara mandiri: scheduler
                 | hanya bertindak sebagai PENGUSUL. Dana baru dipotong setelah
                 | pengguna berwenang menekan konfirmasi (lihat
                 | konfirmasiPenangguhan()). Tanpa konfirmasi, saldo TIDAK
                 | disentuh sama sekali — inilah gerbang pengaman utama. */
                $this->tangguhkanMenungguKonfirmasi($bill, $anggota, self::ALASAN_MENUNGGU_KONFIRMASI, $userId);
                $hasil['ditangguhkan']++;
                $hasil['detail'][] = [
                    'no_anggota' => $anggota->no_anggota,
                    'nama_anggota' => $anggota->nama_lengkap,
                    'periode' => $periode,
                    'amount' => (float) $bill->amount,
                    'status' => MandatorySavingsBill::STATUS_PENDING_CONFIRMATION,
                    'pesan' => 'Auto-debit diusulkan — dana BELUM dipotong, menunggu konfirmasi pengguna.',
                ];
            } catch (\Throwable $e) {
                $hasil['gagal']++;
                $hasil['detail'][] = [
                    'no_anggota' => $anggota->no_anggota,
                    'nama_anggota' => $anggota->nama_lengkap,
                    'periode' => $periode,
                    'amount' => 0.0,
                    'status' => 'GAGAL',
                    'pesan' => $e->getMessage(),
                ];
                Log::error('MandatorySavingsService: gagal memproses tagihan.', [
                    'anggota_id' => $anggota->id,
                    'periode' => $periode,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        /* Penegakan jatuh tempo: setiap tagihan yang telah melewati jatuh
         | temponya (dihitung otomatis per anggota berdasarkan anniversary
         | bulanan sejak bergabung aktif) ditandai OVERDUE + notifikasi —
         | berlaku untuk metode Teller maupun auto-debit yang gagal. */
        if (! $dryRun) {
            $hasil['overdue'] += $this->tandaiOverdueJatuhTempo($tanggal);
        }

        return $hasil;
    }

    /**
     * Tandai OVERDUE seluruh tagihan yang telah melewati jatuh temponya
     * dan belum lunas.
     *
     * Jatuh tempo TIDAK lagi dikonfigurasi lewat kebijakan pengurus, tetapi
     * dihitung otomatis per anggota: tanggal yang sama pada setiap bulan
     * (anniversary) terhitung sejak anggota bergabung aktif. Untuk anggota
     * yang bergabung pada akhir bulan (tgl 29/30/31), jatuh tempo mengikuti
     * akhir bulan pada bulan-bulan berikutnya. Tanggal tersebut dihitung
     * memakai {@see tanggalJatuhTempo()} sehingga konsisten dengan jadwal
     * penerbitan tagihan.
     *
     * @return int Jumlah tagihan yang baru ditandai OVERDUE.
     */
    public function tandaiOverdueJatuhTempo(?Carbon $tanggal = null): int
    {
        $tanggal = ($tanggal ?? Carbon::today())->copy()->startOfDay();

        $periodeBerjalan = MandatorySavingsBill::periodDari($tanggal);

        $tagihan = MandatorySavingsBill::query()
            ->with('member')
            ->where('status', MandatorySavingsBill::STATUS_UNPAID)
            ->where('bill_period', '<=', $periodeBerjalan)
            ->get();

        $jumlah = 0;

        foreach ($tagihan as $bill) {
            $anggota = $bill->member;

            if (! $anggota) {
                continue;
            }

            $periodeBulan = $this->periodeTagihan($bill);
            $jatuhTempo = $this->tanggalJatuhTempo($anggota, $periodeBulan);

            // Belum MELEWATI jatuh tempo otomatis anggota ini → belum OVERDUE
            // (pada tanggal jatuh tempo itu sendiri tagihan masih dapat dibayar).
            if (! $tanggal->gt($jatuhTempo)) {
                continue;
            }

            $this->tandaiOverdue($bill);
            $jumlah++;
        }

        return $jumlah;
    }

    /* =====================================================================
     | 2. PENJADWALAN & PENYESUAIAN TANGGAL (ANNIVERSARY / END-OF-MONTH)
     |===================================================================== */

    /**
     * Apakah tagihan anggota ini jatuh tempo pada tanggal proses?
     *
     * Aturan:
     *  - Anggota dengan tanggal bergabung tgl 1–28 → tagih pada tanggal yang
     *    sama setiap bulan.
     *  - Anggota dengan tanggal bergabung 29/30/31 → mengikuti AKHIR BULAN
     *    bulan berjalan (Carbon::isEndOfMonth()), sehingga anggota yang
     *    bergabung 31 Januari ditagih pada 28 Februari (29 pada tahun kabisat).
     *  - Bila tanggal proses berada setelah tanggal jatuh tempo (mis. scheduler
     *    terlewat karena server mati), tagihan tetap diterbitkan agar tidak
     *    ada periode yang hilang — namun hanya untuk bulan berjalan.
     */
    public function terjadwalHariIni(Anggota $anggota, Carbon $tanggal): bool
    {
        return $this->tanggalJatuhTempo($anggota, $tanggal)->isSameDay($tanggal);
    }

    /**
     * Tanggal jatuh tempo tagihan anggota pada bulan milik $acuan.
     *
     * Dua aturan penyesuaian tanggal:
     *  1. Anggota bergabung pada AKHIR BULAN (Carbon::isEndOfMonth(), yaitu
     *     tgl 29/30/31) → jatuh tempo SELALU akhir bulan berjalan. Contoh:
     *     bergabung 31 Januari → tagihan Februari jatuh tempo 28 Februari
     *     (29 pada tahun kabisat).
     *  2. Anggota bergabung tgl 1–28 → jatuh tempo pada tanggal yang sama,
     *     dan otomatis di-clamp ke hari terakhir bila bulan berjalan lebih
     *     pendek (mis. tgl 30 pada Februari).
     */
    public function tanggalJatuhTempo(Anggota $anggota, Carbon $acuan): Carbon
    {
        $mulai = $anggota->tanggalMulaiKeanggotaan() ?? $acuan->copy()->startOfDay();
        $bulan = $acuan->copy()->startOfMonth();

        // Kasus khusus: bergabung pada akhir bulan (tgl 29/30/31).
        if ($mulai->copy()->isEndOfMonth()) {
            return $bulan->copy()->endOfMonth()->startOfDay();
        }

        // Kasus umum: tanggal sama, di-clamp ke hari terakhir bulan berjalan.
        $hariMaksimal = (int) $bulan->copy()->endOfMonth()->day;
        $hari = min((int) $mulai->day, $hariMaksimal);

        return $bulan->copy()->day($hari)->startOfDay();
    }

    /**
     * Konversi `bill_period` (format 'YYYY-MM') menjadi objek Carbon pada
     * hari pertama bulan tersebut — dipakai sebagai acuan perhitungan jatuh
     * tempo untuk penjadwalan bertahap maupun reschedule.
     */
    public function periodeTagihan(MandatorySavingsBill $bill): Carbon
    {
        return Carbon::parse($bill->bill_period.'-01')->startOfMonth();
    }

    /**
     * Apakah anggota bergabung pada akhir bulan (tgl 29/30/31)?
     *
     * Penanda eksplisit penerapan Carbon::isEndOfMonth() pada penjadwalan.
     */
    public function bergabungAkhirBulan(Anggota $anggota): bool
    {
        $mulai = $anggota->tanggalMulaiKeanggotaan();

        return $mulai ? $mulai->copy()->isEndOfMonth() : false;
    }

    /* =====================================================================
     | 3. PENERBITAN TAGIHAN (ANTI-DUPLIKAT)
     |===================================================================== */

    /**
     * Terbitkan (atau ambil) tagihan Simpanan Wajib anggota untuk periode tertentu.
     *
     * Memakai firstOrCreate sehingga pemanggilan berulang pada periode yang sama
     * TIDAK PERNAH menghasilkan tagihan duplikat.
     *
     * @param  string  $periode  Format wajib 'YYYY-MM'.
     */
    public function issueBill(Anggota $anggota, string $periode, ?Carbon $tanggal = null, ?float $nominal = null): ?MandatorySavingsBill
    {
        $tanggal = ($tanggal ?? Carbon::today())->copy()->startOfDay();

        $mulai = $anggota->tanggalMulaiKeanggotaan();

        // Belum menjadi anggota pada periode tersebut → tidak ada tagihan.
        if ($mulai && $mulai->copy()->startOfMonth()->gt($tanggal->copy()->endOfMonth())) {
            return null;
        }

        // Nominal DINAMIS: master produk → parameter koperasi (tanpa hardcode).
        $amount = $nominal ?? $this->nominalTagihan();

        return MandatorySavingsBill::firstOrCreate(
            [
                'member_id' => $anggota->id,
                'bill_period' => $periode,
            ],
            [
                'amount' => $amount,
                'status' => MandatorySavingsBill::STATUS_UNPAID,
            ]
        );
    }

    /* =====================================================================
     | 4. AUTO-DEBIT DARI SIMPANAN SUKARELA (PSAK 101/108)
     |===================================================================== */

    /**
     * Jalankan auto-debit saldo Simpanan Sukarela untuk melunasi tagihan.
     *
     * @param  bool  $viaKonfirmasi  TRUE hanya bila pemotongan adalah eksekusi
     *                               usulan yang SUDAH dikonfirmasi pengguna
     *                               berwenang (AutodebitService::konfirmasi()).
     *                               Pemanggil lain WAJIB memakai default FALSE.
     * @return bool true bila tagihan berhasil dibayar (status PAID),
     *              false bila saldo tidak mencukupi (status OVERDUE + notifikasi).
     */
    public function autoDebit(MandatorySavingsBill $bill, ?Anggota $anggota = null, ?Carbon $tanggal = null, ?int $userId = null, bool $viaKonfirmasi = false): bool
    {
        $tanggal = ($tanggal ?? Carbon::today())->copy()->startOfDay();
        $anggota = $anggota ?? $bill->member;

        if (! $anggota) {
            throw new \RuntimeException("Tagihan #{$bill->id} tidak memiliki anggota terkait.");
        }

        if ($bill->isPaid()) {
            return true;
        }

        /* GERBANG OTORISASI (pertahanan berlapis).
         | Meskipun `processDaily()` sudah menyaring jalur, metode ini juga
         | dipanggil langsung (seeder, command, dsb.). Karena itu syarat
         | auto-debit diperiksa ULANG di sini: bila produk Simpanan Wajib
         | tidak mengizinkan auto-debit ATAU anggota tidak menyetujuinya,
         | TIDAK ADA saldo yang boleh dipotong — tagihan dibiarkan UNPAID
         | untuk dibayar manual di Teller (JALUR A). */
        if (! $this->bolehAutoDebit($anggota)) {
            Log::warning('MandatorySavingsService: auto-debit ditolak — otorisasi produk/anggota tidak lengkap.', [
                'bill_id' => $bill->id,
                'anggota_id' => $anggota->id,
                'anggota_setuju' => (bool) $anggota->is_auto_debit,
                'produk_izinkan' => JenisSimpanan::produkIzinkanAutoDebit(self::produkWajib()),
            ]);

            return false;
        }

        /* GERBANG KEBIJAKAN INDUK (TIDAK ADA AUTO-DEBIT TANPA KONFIRMASI).
         | Ketika `Setting::autodebitWajibKonfirmasi()` AKTIF (default), dana
         | anggota DILARANG dipotong oleh pemanggil mana pun kecuali eksekusi
         | usulan yang sudah dikonfirmasi pengguna berwenang. Metode ini dapat
         | dipanggil langsung (seeder, command, scheduler lama), sehingga
         | gerbang dipasang di sini sebagai pertahanan terakhir. */
        if (! $viaKonfirmasi && Setting::autodebitWajibKonfirmasi()) {
            Log::warning('MandatorySavingsService: auto-debit ditolak — kebijakan wajib konfirmasi pengguna aktif.', [
                'bill_id' => $bill->id,
                'anggota_id' => $anggota->id,
                'nominal' => (float) $bill->amount,
            ]);

            return false;
        }

        $nominal = Setting::bulatkanUang((float) $bill->amount);
        $rekening = $this->rekeningSukarela($anggota);

        if (! $rekening) {
            // Anggota belum memiliki rekening Simpanan Sukarela (saldo kosong).
            // Tetap dicatat sebagai tindakan agar riwayat audit lengkap.
            $this->catatTindakan($bill, SimpananWajibTindakan::JENIS_AUTO_DEBIT_GAGAL, [
                'nominal' => $nominal,
                'saldo_sukarela' => 0.0,
                'status_hasil' => SimpananWajibTindakan::STATUS_GAGAL,
                'denda' => 0,
                'alasan' => 'SALDO_KOSONG',
                'catatan' => 'Auto-debit tidak dapat dijalankan — anggota belum memiliki rekening Simpanan Sukarela. Tanpa denda; ditindaklanjuti dengan opsi pembayaran bertahap / reschedule.',
                'user_id' => $userId,
            ]);

            $this->tandaiOverdue($bill, $anggota, null);

            return false;
        }

        /* BUG-02 — RACE CONDITION AUTO-DEBIT (diperbaiki).
         | Sebelumnya pemeriksaan saldo dilakukan DI LUAR transaksi dan baris
         | rekening dibaca TANPA `lockForUpdate()`. Dua proses yang berjalan
         | hampir bersamaan (scheduler + pemicu manual) dapat membaca saldo yang
         | SAMA, sama-sama lolos uji "saldo cukup", lalu memotong saldo dua kali
         | → saldo negatif / tagihan terdebit ganda.
         |
         | Perbaikan: tagihan & rekening dikunci dan dibaca ULANG di dalam
         | transaksi, dan uji kecukupan saldo dilakukan terhadap nilai terbaru
         | SETELAH kunci diperoleh. Dengan begitu pemotongan bersifat serial:
         | proses kedua melihat saldo hasil pemotongan proses pertama. */
        $berhasil = DB::transaction(function () use ($bill, $anggota, $rekening, $nominal, $tanggal, $userId) {
            $billTerkunci = MandatorySavingsBill::query()->lockForUpdate()->find($bill->id);

            // Sudah lunas oleh proses paralel → tidak ada yang perlu dikerjakan.
            if (! $billTerkunci || $billTerkunci->isPaid()) {
                return true;
            }

            $rekeningTerkunci = Rekening::query()->lockForUpdate()->find($rekening->id);

            if (! $rekeningTerkunci || (float) $rekeningTerkunci->saldo < $nominal) {
                return false;
            }

            $keterangan = sprintf(
                'Auto-debit dari Simpanan Sukarela — %s (%s)',
                $bill->nama_periode,
                $anggota->nama_lengkap
            );

            $transaksi = Transaksi::create([
                'no_transaksi' => $this->generateNoTransaksi(),
                'anggota_id' => $anggota->id,
                'rekening_id' => $rekeningTerkunci->id,
                'jenis_transaksi' => 'Setoran Simpanan Wajib',
                'jumlah' => $nominal,
                'posisi' => 'Debet',
                'sumber_transaksi' => 'Auto',
                'waktu_transaksi' => $tanggal->copy()->setTimeFromTimeString($this->jamPosting()),
                'status_transaksi' => 'Sukses',
                'status' => 'success',
                'catatan' => $keterangan,
            ]);

            $saldoBaru = Setting::bulatkanUang((float) $rekeningTerkunci->saldo - $nominal);
            $rekeningTerkunci->update(['saldo' => $saldoBaru]);

            // Akun kredit Simpanan Wajib dari TITIK RESOLUSI TUNGGAL (R1).
            $akunWajib = $this->akunSimpananWajib();

            $jurnal = $this->journaling->jurnalSimpananWajibAutoDebit(
                $nominal,
                $keterangan,
                $userId,
                $transaksi->id,
                $rekeningTerkunci->id,
                $anggota->id,
                // Jurnal mengikuti tanggal efektif pembayaran (bisa backdate
                // akibat penyesuaian akhir bulan, mis. 28 Feb untuk tagihan 31 Jan)
                $tanggal->copy()->toDateString(),
                $akunWajib,
                self::COA_SIMPANAN_SUKARELA,
                $this->rekeningWajib($anggota)?->id
            );

            if (! $jurnal) {
                throw new \RuntimeException(
                    'Auto-journal Simpanan Wajib gagal diposting. Pastikan CoA '
                    .self::COA_SIMPANAN_SUKARELA.' (Simpanan Sukarela) dan CoA '
                    .$akunWajib.' (Simpanan Wajib — Modal Anggota) tersedia dan aktif.'
                );
            }

            // Subledger rekening Wajib ikut bertambah agar GL ≡ rekening.
            $this->tambahSaldoRekeningWajib($anggota, $nominal);

            $billTerkunci->update([
                'status' => MandatorySavingsBill::STATUS_PAID,
                'paid_at' => $tanggal->copy()->setTimeFromTimeString($this->jamPosting()),
                'payment_method' => MandatorySavingsBill::METODE_AUTO_DEBIT,
                'transaksi_id' => $transaksi->id,
                'jurnal_id' => $jurnal->id,
            ]);

            Log::info('MandatorySavingsService: tagihan lunas via auto-debit.', [
                'bill_id' => $bill->id,
                'anggota_id' => $anggota->id,
                'periode' => $bill->bill_period,
                'nominal' => $nominal,
                'no_jurnal' => $jurnal->no_jurnal,
            ]);

            // AUDIT TRAIL (#5): catat pemotongan auto-debit yang berhasil.
            $this->catatTindakan($bill, SimpananWajibTindakan::JENIS_AUTO_DEBIT_BERHASIL, [
                'nominal' => $nominal,
                'saldo_sukarela' => (float) $rekeningTerkunci->saldo,
                'status_hasil' => SimpananWajibTindakan::STATUS_BERHASIL,
                'denda' => 0,
                'catatan' => sprintf(
                    'Auto-debit berhasil memotong saldo Simpanan Sukarela sebesar Rp %s untuk tagihan periode %s.',
                    number_format($nominal, 0, ',', '.'),
                    $bill->nama_periode
                ),
                'user_id' => $userId,
            ]);

            return true;
        });

        if (! $berhasil) {
            // Saldo (terbaru) tidak mencukupi → tagih manual di Teller.
            $this->catatTindakan($bill, SimpananWajibTindakan::JENIS_AUTO_DEBIT_GAGAL, [
                'nominal' => $nominal,
                'saldo_sukarela' => $rekening ? (float) $rekening->fresh()->saldo : 0.0,
                'status_hasil' => SimpananWajibTindakan::STATUS_GAGAL,
                'denda' => 0,
                'alasan' => 'SALDO_TIDAK_CUKUP',
                'catatan' => 'Auto-debit tidak dapat dijalankan — saldo Simpanan Sukarela tidak mencukupi tagihan periode ini. Tanpa denda; akan ditindaklanjuti dengan opsi pembayaran bertahap / reschedule.',
                'user_id' => $userId,
            ]);

            $this->tandaiOverdue($bill, $anggota, $rekening);

            return false;
        }

        return true;
    }

    /**
     * Pelunasan tagihan Simpanan Wajib melalui TELLER (tunai/transfer).
     *
     * Dipakai saat anggota memilih pembayaran manual (`is_auto_debit = false`):
     * tagihan dibiarkan UNPAID oleh scheduler, lalu Teller melunasinya kapan
     * saja. Jurnal PSAK 101/108 yang diposting:
     *   DEBET  1-1001 Kas Teller / Bank
     *   KREDIT 3-1002 Simpanan Wajib Anggota (Modal Anggota)
     *
     * @param  string  $metode  MandatorySavingsBill::METODE_CASH | METODE_TRANSFER
     * @return bool true bila berhasil dilunasi; false bila sudah PAID.
     */
    public function bayarViaTeller(MandatorySavingsBill $bill, string $metode, ?Carbon $tanggal = null, ?int $userId = null): bool
    {
        $tanggal = ($tanggal ?? Carbon::today())->copy()->startOfDay();

        if ($bill->isPaid()) {
            return false;
        }

        $anggota = $bill->member;

        if (! $anggota) {
            throw new \RuntimeException("Tagihan #{$bill->id} tidak memiliki anggota terkait.");
        }

        $nominal = Setting::bulatkanUang((float) $bill->amount);
        $keterangan = sprintf(
            'Setoran Simpanan Wajib %s via %s — %s',
            $bill->nama_periode,
            strtoupper($metode),
            $anggota->nama_lengkap
        );

        return DB::transaction(function () use ($bill, $anggota, $nominal, $metode, $tanggal, $userId, $keterangan) {
            $transaksi = Transaksi::create([
                'no_transaksi' => $this->generateNoTransaksi(),
                'anggota_id' => $anggota->id,
                'jenis_transaksi' => 'Setoran Simpanan Wajib',
                'jumlah' => $nominal,
                'posisi' => 'Debet',
                'sumber_transaksi' => 'Teller',
                'waktu_transaksi' => $tanggal->copy()->setTime(now()->hour, now()->minute),
                'status_transaksi' => 'Sukses',
                'status' => 'success',
                'catatan' => $keterangan,
                'user_id' => $userId,
            ]);

            // Akun kredit Simpanan Wajib dari TITIK RESOLUSI TUNGGAL (R1).
            $akunWajib = $this->akunSimpananWajib();

            $jurnal = $this->journaling->jurnalSimpananWajibTeller(
                $nominal,
                $keterangan,
                $userId,
                $transaksi->id,
                $anggota->id,
                $tanggal->copy()->toDateString(),
                $akunWajib,
                self::COA_KAS_TELLER,
                $this->rekeningWajib($anggota)?->id
            );

            if (! $jurnal) {
                throw new \RuntimeException(
                    'Auto-journal Simpanan Wajib (Teller) gagal diposting. Pastikan CoA '
                    .self::COA_KAS_TELLER.' (Kas Teller/Bank) dan CoA '
                    .$akunWajib.' (Simpanan Wajib — Modal Anggota) tersedia dan aktif.'
                );
            }

            // Subledger rekening Wajib ikut bertambah agar GL ≡ rekening.
            $this->tambahSaldoRekeningWajib($anggota, $nominal);

            $bill->update([
                'status' => MandatorySavingsBill::STATUS_PAID,
                'paid_at' => $tanggal->copy()->setTime(now()->hour, now()->minute),
                'payment_method' => $metode,
                'transaksi_id' => $transaksi->id,
                'jurnal_id' => $jurnal->id,
            ]);

            // Tunggakan bersih → lepas penahanan SHU secara otomatis.
            $this->lepasPenahananShuBilaLunas($anggota);

            // AUDIT TRAIL (#5): catat pelunasan tunai/transfer di Teller.
            $this->catatTindakan($bill, SimpananWajibTindakan::JENIS_BAYAR_TELLER, [
                'nominal' => $nominal,
                'status_hasil' => SimpananWajibTindakan::STATUS_BERHASIL,
                'denda' => 0,
                'catatan' => sprintf(
                    'Pelunasan tagihan periode %s via %s sebesar Rp %s diterima di Teller.',
                    $bill->nama_periode,
                    strtoupper($metode),
                    number_format($nominal, 0, ',', '.')
                ),
                'user_id' => $userId,
            ]);

            return true;
        });
    }

    /* =====================================================================
     | 5A. PENARIKAN SIMPANAN WAJIB (Tahap 12 / R3)
     |===================================================================== */

    /**
     * Apakah Penarikan Simpanan Wajib boleh dilakukan pada konteks ini.
     *
     * Dua gerbang wajib terbuka:
     *  1. Saklar kebijakan `simpanan_wajib_boleh_ditarik` (default NONAKTIF —
     *     saldo terkunci sebagai modal anggota, sesuai perilaku sistem saat ini).
     *  2. Waktu penarikan `simpanan_wajib_hari_penarikan`:
     *     - `SETELAH_KELUAR` (default) → hanya anggota berstatus Keluar.
     *     - `KAPAN_SAJA`               → anggota non-Keluar juga boleh.
     *
     * @return string|null null bila diizinkan; string berisi alasan bila ditolak.
     */
    /**
     * GATE TERPUSAT — tolak penarikan Simpanan Wajib (3-1002) sebagai
     * penarikan RUTIN, TANPA SYARAT. Delegasi ke
     * {@see GuardSimpananModal::pastikanTidakDitarikRutin()} sehingga pesan
     * error, log keamanan, dan audit trail seragam di seluruh aplikasi dan
     * tidak ada celah data yang dapat melewatinya.
     *
     * Pengembalian dana saat anggota keluar TIDAK melalui metode ini, melainkan
     * lewat {@see ExitKeanggotaanService::eksekusi()} yang memakai jalur jurnal
     * & ledger tersendiri (PSAK 108).
     *
     * @throws \RuntimeException
     */
    private function pastikanBukanPenarikanModal(Anggota $anggota): void
    {
        GuardSimpananModal::pastikanTidakDitarikRutin(
            $anggota,
            'MandatorySavingsService::tarikSimpananWajib'
        );
    }

    public function alasanPenarikanDitolak(Anggota $anggota): ?string
    {
        if (! $this->penarikanDiizinkan()) {
            return 'Penarikan Simpanan Wajib sedang tidak diizinkan. Saldo terkunci '
                .'sebagai modal anggota (PSAK 101 ¶ 31). Hubungi Pengurus untuk mengaktifkan.';
        }

        $waktu = Setting::simpananWajibHariPenarikan();

        if ($waktu === 'SETELAH_KELUAR' && $anggota->status_anggota !== Anggota::STATUS_KELUAR) {
            return 'Penarikan Simpanan Wajib hanya dapat dilakukan setelah anggota berstatus '
                .'Keluar. Status saat ini: '.($anggota->status_label ?? $anggota->status_anggota).'.';
        }

        return null;
    }

    /**
     * Saldo Simpanan Wajib (Modal Anggota) milik seorang anggota.
     *
     * Dihitung dari mutasi baris jurnal pada akun kanonik hasil resolusi
     * kebijakan, DIFILTER per `anggota_id` — sehingga mencerminkan penyertaan
     * anggota tersebut, bukan saldo akun secara keseluruhan.
     *
     * @param  string|null  $sampai  Batasi sampai tanggal tertentu (opsional).
     */
    public function saldoSimpananWajib(Anggota $anggota, ?string $sampai = null): float
    {
        $kode = $this->akunSimpananWajib();

        $row = DB::table('jurnal_detail as d')
            ->join('jurnal as j', 'j.id', '=', 'd.jurnal_id')
            ->join('coas as c', 'c.id', '=', 'd.coa_id')
            ->whereNull('j.deleted_at')
            ->where('j.status', 'Posted')
            ->where('c.kode_akun', $kode)
            ->where('d.anggota_id', $anggota->id)
            ->when($sampai !== null, fn ($q) => $q->whereDate('j.tanggal_jurnal', '<=', $sampai))
            ->selectRaw('COALESCE(SUM(d.debet), 0) AS debet')
            ->selectRaw('COALESCE(SUM(d.kredit), 0) AS kredit')
            ->first();

        return Setting::bulatkanUang((float) ($row->kredit ?? 0) - (float) ($row->debet ?? 0));
    }

    /**
     * Penarikan saldo Simpanan Wajib seorang anggota di loket Teller.
     *
     * Dasar syariah & akuntansi (PSAK 101 ¶ 31):
     *  - Simpanan Wajib disajikan sebagai MODAL ANGGOTA (Ekuitas), sehingga
     *    penarikannya adalah pengurangan penyertaan anggota:
     *      DEBET  3-1002 Simpanan Wajib Anggota (Modal Anggota)  ← akun kanonik
     *      KREDIT 1-1001 Kas Teller / Bank
     *  - Saldo tidak boleh menjadi negatif (overdraw penyertaan dilarang) —
     *    satu-satunya guard nominal pada jalur ini.
     *  - Gerbang kebijakan & waktu penarikan ditegakkan `alasanPenarikanDitolak()`.
     *
     * Integritas: baris Transaksi + Jurnal dibuat di dalam SATU transaksi basis
     * data; bila jurnal gagal, seluruhnya di-rollback sehingga saldo anggota
     * tidak pernah berkurang tanpa jejak jurnal.
     *
     * @param  string  $metode  MandatorySavingsBill::METODE_CASH | METODE_TRANSFER
     * @param  float|null  $nominal  Jumlah yang ditarik; null = seluruh saldo.
     * @return array{transaksi:Transaksi, jurnal:Jurnal, saldo_sebelum:float, saldo_setelah:float, ditarik:float}
     */
    public function tarikSimpananWajib(Anggota $anggota, ?float $nominal = null, string $metode = MandatorySavingsBill::METODE_CASH, ?Carbon $tanggal = null, ?int $userId = null): array
    {
        // GATE MODAL ANGGOTA — Simpanan Pokok (3-1001) & Simpanan Wajib (3-1002)
        // adalah Ekuitas Koperasi (PSAK 101 ¶ 31); penarikan rutin DILARANG
        // selama anggota aktif. Pengembalian dana hanya via `member_terminations`
        // (ExitKeanggotaanService). Penegakan ini berada di LAPISAN SERVICE agar
        // tidak dapat dilangkahi pemanggil mana pun (HTTP, command, job, test).
        $this->pastikanBukanPenarikanModal($anggota);

        if ($alasan = $this->alasanPenarikanDitolak($anggota)) {
            throw new \RuntimeException($alasan);
        }

        if (! in_array($metode, [MandatorySavingsBill::METODE_CASH, MandatorySavingsBill::METODE_TRANSFER], true)) {
            throw new \InvalidArgumentException("Metode penarikan tidak sah: {$metode}.");
        }

        $tanggal = ($tanggal ?? Carbon::today())->copy()->startOfDay();
        $saldoSebelum = $this->saldoSimpananWajib($anggota);

        /* Nominal penarikan WAJIB dibulatkan mengikuti kebijakan koperasi
         | (`Setting::pembulatanNominal()`), sama seperti yang dilakukan
         | AutoJournalingService saat menulis baris jurnal. Tanpa ini, Teller
         | dapat meminta Rp 37.500,50 sementara buku besar mencatat Rp 37.501 —
         | selisih sen yang membuat saldo di layar tidak pernah cocok dengan
         | saldo akuntansi (pelanggaran jejak audit PSAK 101 ¶ 45–47). */
        $ditarik = Setting::pembulatanNominal(
            $nominal === null ? $saldoSebelum : (float) $nominal
        );

        if ($ditarik <= 0) {
            throw new \RuntimeException('Nominal penarikan harus lebih besar dari nol.');
        }

        if ($ditarik > Setting::pembulatanNominal($saldoSebelum)) {
            throw new \RuntimeException(sprintf(
                'Saldo Simpanan Wajib tidak mencukupi. Tersedia Rp %s, diminta Rp %s.',
                number_format($saldoSebelum, 2, ',', '.'),
                number_format($ditarik, 2, ',', '.')
            ));
        }

        $keterangan = sprintf(
            'Penarikan Simpanan Wajib via %s — %s',
            strtoupper($metode),
            $anggota->nama_lengkap
        );

        return DB::transaction(function () use (
            $anggota, $ditarik, $tanggal, $userId, $keterangan, $saldoSebelum
        ) {
            $transaksi = Transaksi::create([
                'no_transaksi' => $this->generateNoTransaksi(),
                'anggota_id' => $anggota->id,
                'jenis_transaksi' => 'Penarikan Simpanan Wajib',
                'jumlah' => $ditarik,
                'posisi' => 'Kredit',
                'sumber_transaksi' => 'Teller',
                'waktu_transaksi' => $tanggal->copy()->setTime(now()->hour, now()->minute),
                'status_transaksi' => 'Sukses',
                'status' => 'success',
                'catatan' => $keterangan,
                'user_id' => $userId,
            ]);

            // Akun kanonik dari TITIK RESOLUSI TUNGGAL (Tahap 12 / R1).
            $akunWajib = $this->akunSimpananWajib();

            $jurnal = $this->journaling->jurnalTarikSimpananWajib(
                $ditarik,
                $keterangan,
                $userId,
                $transaksi->id,
                $this->rekeningWajib($anggota)?->id,
                $anggota->id,
                $akunWajib,
                self::COA_KAS_TELLER
            );

            if (! $jurnal) {
                throw new \RuntimeException(
                    'Auto-journal Penarikan Simpanan Wajib gagal diposting. Pastikan CoA '
                    .$akunWajib.' (Simpanan Wajib — Modal Anggota) dan CoA '
                    .self::COA_KAS_TELLER.' (Kas Teller/Bank) tersedia dan aktif.'
                );
            }

            return [
                'transaksi' => $transaksi,
                'jurnal' => $jurnal,
                'saldo_sebelum' => $saldoSebelum,
                'saldo_setelah' => Setting::bulatkanUang($saldoSebelum - $ditarik),
                'ditarik' => $ditarik,
            ];
        });
    }

    /**
     * Tandai tagihan OVERDUE dan picu notifikasi ke anggota & admin koperasi.
     */
    public function tandaiOverdue(MandatorySavingsBill $bill, ?Anggota $anggota = null, ?Rekening $rekening = null): MandatorySavingsBill
    {
        $anggota = $anggota ?? $bill->member;

        $bill->update(['status' => MandatorySavingsBill::STATUS_OVERDUE]);

        $this->kirimNotifikasiOverdue($bill, $anggota, $rekening);

        // AUDIT TRAIL (#5): catat penandaan overdue sebagai riwayat penanganan.
        $this->catatTindakan($bill, SimpananWajibTindakan::JENIS_TANDAI_OVERDUE, [
            'nominal' => (float) $bill->amount,
            'saldo_sukarela' => ($anggota ? $this->saldoSukarela($anggota) : 0.0),
            'status_hasil' => SimpananWajibTindakan::STATUS_INFO,
            'denda' => 0,
            'alasan' => 'SALDO_TIDAK_CUKUP',
            'catatan' => 'Tagihan ditandai OVERDUE dan notifikasi anonim dikirim ke anggota. Tanpa denda — menunggu pelunasan Teller, pembayaran bertahap, atau reschedule.',
        ]);

        return $bill;
    }

    /* =====================================================================
     | 5. NOTIFIKASI (IN-APP / EMAIL / SMS GATEWAY)
     |===================================================================== */

    /**
     * Kirim alert tunggakan Simpanan Wajib.
     *
     * Notifikasi dicatat permanen ke `audit_logs` (dapat diaudit pengurus),
     * ditampilkan sebagai alert in-app pada sesi pengguna aktif, dan dikirim
     * via kanal email/SMS bila kanal tersedia pada model anggota.
     */
    public function kirimNotifikasiOverdue(MandatorySavingsBill $bill, ?Anggota $anggota, ?Rekening $rekening = null): void
    {
        /* DUA BENTUK PESAN (satu sumber, tanpa duplikasi):
         |  - $pesan          → IKUTI KEBIJAKAN. Bila `simpanan_wajib_notif_anonim`
         |                      aktif (default, PDP / UU 27/2022), identitas anggota
         |                      (nama & no_anggota) DISAMARKAN pada SELURUH kanal
         |                      termasuk audit trail (audit trail dapat dibaca
         |                      pengurus secara luas).
         |  - $pesanPetugas   → SELALU memuat identitas. HANYA dipakai pada catatan
         |                      in-app untuk petugas yang sedang login (session flash
         |                      / log internal), bukan untuk kanal luar maupun
         |                      anggota lain — sehingga petugas tetap dapat
         |                      menindaklanjuti tanpa membocorkan identitas.
         |
         | Keduanya informatif (periode, nominal, tenggat, langkah penanganan)
         | dan TIDAK PERNAH mengubah perilaku akuntansi (AuditLog::record +
         | notified_at tetap dijalankan apa pun pengaturannya). */
        $pesanPetugas = sprintf(
            'Tunggakan Simpanan Wajib periode %s sebesar Rp %s untuk anggota %s (%s). '
            .'Saldo Simpanan Sukarela tidak mencukupi untuk auto-debit — mohon segera disetor.',
            $bill->nama_periode,
            number_format((float) $bill->amount, 0, ',', '.'),
            $anggota->nama_lengkap ?? '-',
            $anggota->no_anggota ?? '-'
        );

        $pesan = Setting::simpananWajibNotifAnonim()
            ? $this->pesanNotifikasiAnonim($bill)
            : $pesanPetugas;

        try {
            AuditLog::record('SIMPANAN_WAJIB_OVERDUE', (float) $bill->amount, $rekening, $pesan);
        } catch (\Throwable $e) {
            Log::notice('MandatorySavingsService: notifikasi overdue tidak tersimpan ke audit_logs.', [
                'bill_id' => $bill->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Kanal eksternal (email/SMS) → memakai pesan yang sudah mengikuti
        // kebijakan anonimisasi; identitas anggota tidak pernah ikut terkirim
        // ke luar bila anonimisasi aktif.
        $this->kirimKanalEksternal($bill, $anggota, $pesan);

        if (session()->isStarted()) {
            // Flash untuk PETUGAS yang sedang login → identitas lengkap agar
            // dapat ditindaklanjuti (bukan kanal publik antar-anggota).
            session()->flash('error', $pesanPetugas);
        }

        $bill->update(['notified_at' => now()]);

        // AUDIT TRAIL (#5): catat pengiriman notifikasi (isi pesan anonim
        // bila kebijakan anonimisasi aktif — tidak mengekspos identitas).
        $this->catatTindakan($bill, SimpananWajibTindakan::JENIS_NOTIFIKASI, [
            'nominal' => (float) $bill->amount,
            'saldo_sukarela' => ($anggota ? $this->saldoSukarela($anggota) : 0.0),
            'status_hasil' => SimpananWajibTindakan::STATUS_INFO,
            'denda' => 0,
            'alasan' => 'SALDO_TIDAK_CUKUP',
            'catatan' => $pesan,
        ]);

        Log::warning('MandatorySavingsService: tagihan Simpanan Wajib OVERDUE.', [
            'bill_id' => $bill->id,
            'anggota_id' => $anggota->id ?? null,
            'periode' => $bill->bill_period,
            'nominal' => (float) $bill->amount,
        ]);
    }

    /**
     * Susun pesan tunggakan TANPA identitas anggota (PDP / UU 27/2022).
     *
     * Menyertakan informasi yang informatif untuk anggota (periode, nominal,
     * tenggat, langkah penanganan) sekaligus tidak mengekspos siapa pun.
     * Identitas internal yang aman (ID tagihan) tetap dipakai sebagai rujukan
     * petugas tanpa mengungkap nama/no_anggota.
     */
    public function pesanNotifikasiAnonim(MandatorySavingsBill $bill, ?Anggota $anggota = null): string
    {
        $anggota = $anggota ?? $bill->member;
        $tenggat = $anggota
            ? $this->tanggalJatuhTempo($anggota, Carbon::parse($bill->bill_period.'-01'))
                ->format('d/m/Y')
            : null;

        return sprintf(
            'Pengingat Simpanan Wajib: tagihan periode %s sebesar Rp %s belum terselesaikan'
            .($tenggat ? ' (tenggat %s)' : '')
            .'. Saldo Simpanan Sukarela Anda belum mencukupi untuk auto-debit. '
            .'Kami tidak menerapkan denda keterlambatan — silakan melunasi di Teller, '
            .'mengatur pembayaran bertahap, atau mengajukan penjadwalan ulang tanpa denda. '
            .'Rujukan tagihan: #%d. Informasi selengkapnya dapat Anda lihat pada menu Simpanan Wajib.',
            $bill->nama_periode,
            number_format((float) $bill->amount, 0, ',', '.'),
            $tenggat,
            $bill->id
        );
    }

    /**
     * Kirim ke kanal email/SMS bila atribut kontak tersedia pada anggota.
     *
     * Implementasi tidak memaksa paket notifikasi tertentu: kegagalan kanal
     * eksternal tidak boleh menggagalkan proses akuntansi.
     */
    private function kirimKanalEksternal(MandatorySavingsBill $bill, ?Anggota $anggota, string $pesan): void
    {
        if (! $anggota) {
            return;
        }

        $tujuan = array_filter([
            'email' => $anggota->email ?? null,
            'telp' => $anggota->no_hp ?? $anggota->telepon ?? null,
        ]);

        foreach ($tujuan as $kanal => $alamat) {
            Log::info('MandatorySavingsService: notifikasi tunggakan dikirim.', [
                'bill_id' => $bill->id,
                'kanal' => $kanal,
                'tujuan' => $alamat,
                'pesan' => $pesan,
            ]);
        }
    }

    /* =====================================================================
     | 5B. PENANGGUHAN & KONFIRMASI MANUAL (BUG-03)
     |===================================================================== */

    /**
     * Tandai tagihan DITANGGUHKAN — menunggu KONFIRMASI MANUAL petugas.
     *
     * Dipanggil ketika verifikasi status pembayaran gagal: dana anggota TIDAK
     * dipotong, status diubah menjadi PENDING_CONFIRMATION, audit trail
     * dicatat, dan administrator dinotifikasi. Inilah mekanisme konfirmasi
     * manual untuk setoran yang ditangguhkan.
     */
    public function tangguhkanMenungguKonfirmasi(
        MandatorySavingsBill $bill,
        ?Anggota $anggota = null,
        string $alasan = 'SALDO_TIDAK_CUKUP',
        ?int $userId = null
    ): MandatorySavingsBill {
        $anggota = $anggota ?? $bill->member;

        $bill->update([
            'status' => MandatorySavingsBill::STATUS_PENDING_CONFIRMATION,
            'deferred_reason' => $alasan,
            'deferred_at' => now(),
            'confirmed_by' => null,
            'confirmed_at' => null,
            'confirmation_note' => null,
        ]);

        $this->catatTindakan($bill, SimpananWajibTindakan::JENIS_DITANGGUHKAN, [
            'nominal' => (float) $bill->amount,
            'saldo_sukarela' => ($anggota ? $this->saldoSukarela($anggota) : 0.0),
            'status_hasil' => SimpananWajibTindakan::STATUS_INFO,
            'denda' => 0,
            'alasan' => $alasan,
            'catatan' => 'Setoran otomatis DITANGGUHKAN — dana tidak dipotong; menunggu konfirmasi manual petugas. Tanpa denda.',
            'user_id' => $userId,
        ]);

        // Daftarkan usulan ke PUSAT KONFIRMASI agar tampil pada antrean
        // pengguna berwenang. Usulan TIDAK memotong dana apa pun.
        app(AutodebitService::class)->usulkan(
            AutodebitUsulan::KANAL_SIMPANAN_WAJIB,
            (int) $bill->id,
            $anggota,
            (float) $bill->amount,
            [
                'periode' => $bill->bill_period,
                'alasan' => $alasan,
                'catatan' => 'Usulan auto-debit Simpanan Wajib periode '.$bill->nama_periode.'.',
                'referensi_tipe' => MandatorySavingsBill::class,
            ]
        );

        $this->notifikasiAdministratorDitangguhkan($bill, $anggota, $alasan);

        return $bill->refresh();
    }

    /** Catat jejak audit ketika verifikasi status pembayaran gagal. */
    public function catatVerifikasiGagal(MandatorySavingsBill $bill, ?Anggota $anggota, array $verifikasi, ?int $userId = null): void
    {
        $this->catatTindakan($bill, SimpananWajibTindakan::JENIS_VERIFIKASI_GAGAL, [
            'nominal' => (float) $bill->amount,
            'saldo_sukarela' => ($anggota ? $this->saldoSukarela($anggota) : 0.0),
            'status_hasil' => SimpananWajibTindakan::STATUS_INFO,
            'denda' => 0,
            'alasan' => $verifikasi['alasan'] ?? 'TIDAK_DIKETAHUI',
            'catatan' => 'Verifikasi status pembayaran TIDAK lolos — setoran otomatis DIBATALKAN sebelum pemotongan dana (mitigasi risiko kerugian).',
            'user_id' => $userId,
        ]);
    }

    /**
     * Kirim notifikasi ke ADMINISTRATOR (pengurus & manajer) bahwa ada anggota
     * yang belum melunasi pembayaran pada jatuh tempo sehingga setoran
     * otomatis ditangguhkan. Kegagalan kanal tidak boleh menggagalkan proses.
     */
    public function notifikasiAdministratorDitangguhkan(MandatorySavingsBill $bill, ?Anggota $anggota, string $alasan): void
    {
        $anggota = $anggota ?? $bill->member;

        try {
            $penerima = User::query()
                ->whereIn('role', HierarkiRole::kelompok('notifikasi_simpanan_wajib'))
                ->get();

            if ($penerima->isNotEmpty()) {
                Notification::send(
                    $penerima,
                    SimpananWajibDitangguhkanNotification::dariTagihan($bill, $alasan)
                );
            }
        } catch (\Throwable $e) {
            Log::warning('MandatorySavingsService: gagal mengirim notifikasi penangguhan ke administrator.', [
                'bill_id' => $bill->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Jejak audit + kanal eksternal (pola konsisten notifikasi tunggakan —
        // argumen ketiga adalah REKENING sukarela, bukan objek Anggota, agar
        // FK `audit_logs.rekening_id` tetap sah).
        $rekening = $anggota ? $this->rekeningSukarela($anggota) : null;

        try {
            AuditLog::record(
                'SIMPANAN_WAJIB_DITANGGUHKAN',
                (float) $bill->amount,
                $rekening,
                sprintf(
                    'Setoran Simpanan Wajib periode %s ditangguhkan (alasan: %s) — dana tidak dipotong, menunggu konfirmasi manual petugas.',
                    $bill->nama_periode,
                    $alasan
                )
            );
        } catch (\Throwable $e) {
            Log::notice('MandatorySavingsService: notifikasi penangguhan tidak tersimpan ke audit_logs.', [
                'bill_id' => $bill->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($anggota) {
            $this->kirimKanalEksternal($bill, $anggota, $this->pesanNotifikasiAnonim($bill, $anggota));
        }

        $this->catatTindakan($bill, SimpananWajibTindakan::JENIS_NOTIFIKASI, [
            'nominal' => (float) $bill->amount,
            'status_hasil' => SimpananWajibTindakan::STATUS_INFO,
            'alasan' => $alasan,
            'catatan' => 'Notifikasi penangguhan dikirim ke administrator — menunggu konfirmasi manual.',
        ]);
    }

    /**
     * KONFIRMASI MANUAL: petugas menyetujui agar setoran ditangguhkan
     * dilanjutkan. Bila saldo Sukarela mencukupi, auto-debit dijalankan
     * (jurnal PSAK 101/108). Bila tidak, konfirmasi ditolak sistem agar dana
     * tidak dipotong paksa — tagihan tetap menunggu tindakan lanjutan.
     */
    public function konfirmasiPenangguhan(
        MandatorySavingsBill $bill,
        int $userId,
        ?string $catatan = null,
        ?Carbon $tanggal = null
    ): bool {
        $anggota = $bill->member;

        if (! $anggota) {
            throw new \RuntimeException("Tagihan #{$bill->id} tidak memiliki anggota terkait.");
        }

        if (! $bill->isPendingConfirmation()) {
            return false;
        }

        $verifikasi = $this->verifikasiStatusPembayaran($bill, $anggota, $tanggal);

        if (! $verifikasi['boleh']) {
            $this->catatTindakan($bill, SimpananWajibTindakan::JENIS_TOLAK_KONFIRMASI, [
                'nominal' => (float) $bill->amount,
                'status_hasil' => SimpananWajibTindakan::STATUS_GAGAL,
                'alasan' => $verifikasi['alasan'],
                'catatan' => $catatan ?: 'Konfirmasi tidak dapat dijalankan — verifikasi status pembayaran masih gagal; dana tidak dipotong.',
                'user_id' => $userId,
            ]);

            return false;
        }

        // Konfirmasi manual petugas = persetujuan manusia atas pemotongan,
        // sehingga sah melewati gerbang kebijakan wajib konfirmasi.
        if (! $this->autoDebit($bill, $anggota, $tanggal, $userId, true)) {
            return false;
        }

        $bill->refresh()->update([
            'confirmed_by' => $userId,
            'confirmed_at' => now(),
            'confirmation_note' => $catatan,
        ]);

        $this->catatTindakan($bill, SimpananWajibTindakan::JENIS_KONFIRMASI_MANUAL, [
            'nominal' => (float) $bill->amount,
            'status_hasil' => SimpananWajibTindakan::STATUS_BERHASIL,
            'alasan' => 'TERVERIFIKASI',
            'catatan' => $catatan ?: 'Petugas mengonfirmasi penangguhan — setoran dilanjutkan setelah verifikasi status pembayaran lolos.',
            'user_id' => $userId,
        ]);

        return true;
    }

    /**
     * TOLAK konfirmasi: petugas memutuskan setoran TIDAK dijalankan. Dana
     * anggota tidak dipotong; tagihan kembali ke jalur manual (UNPAID/OVERDUE).
     */
    public function tolakKonfirmasi(MandatorySavingsBill $bill, int $userId, ?string $catatan = null): MandatorySavingsBill
    {
        if (! $bill->isPendingConfirmation()) {
            return $bill;
        }

        $bill->update([
            'status' => MandatorySavingsBill::STATUS_OVERDUE,
            'confirmed_by' => $userId,
            'confirmed_at' => now(),
            'confirmation_note' => $catatan,
        ]);

        $this->catatTindakan($bill, SimpananWajibTindakan::JENIS_TOLAK_KONFIRMASI, [
            'nominal' => (float) $bill->amount,
            'status_hasil' => SimpananWajibTindakan::STATUS_INFO,
            'alasan' => $bill->deferred_reason ?? 'TIDAK_DIKETAHUI',
            'catatan' => $catatan ?: 'Petugas menolak konfirmasi — setoran tidak dijalankan; tagihan dialihkan ke penanganan manual (tanpa denda).',
            'user_id' => $userId,
        ]);

        return $bill->refresh();
    }

    /* =====================================================================
     | 6. UTILITAS
     |===================================================================== */

    /** Rekening Simpanan Sukarela anggota (tipe 'Simpanan Sukarela', status aktif). */
    public function rekeningSukarela(Anggota $anggota): ?Rekening
    {
        return Rekening::query()
            ->where('anggota_id', $anggota->id)
            ->whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_SUKARELA))
            ->where('status_rekening', 'Aktif')
            ->orderBy('id')
            ->first();
    }

    /**
     * Rekening Simpanan Wajib anggota (tipe 'Simpanan Wajib', status aktif).
     *
     * Dipakai agar baris jurnal 3-1002 (Simpanan Wajib — Modal Anggota)
     * teratribusi ke rekening yang benar. Tanpa atribusi, subledger rekening
     * tidak dapat direkonsiliasi dengan buku besar.
     */
    public function rekeningWajib(Anggota $anggota): ?Rekening
    {
        return Rekening::query()
            ->where('anggota_id', $anggota->id)
            ->whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_WAJIB))
            ->where('status_rekening', 'Aktif')
            ->orderBy('id')
            ->first();
    }

    /**
     * Tambah saldo rekening Simpanan Wajib anggota.
     *
     * Setiap kali jurnal KREDIT 3-1002 (Simpanan Wajib — Modal Anggota)
     * diposting, subledger `rekening.saldo` WAJIB ikut bertambah. Tanpa ini,
     * buku besar (GL) dan subledger rekening berbeda → rekonsiliasi
     * `simpanan:audit-subledger` melaporkan selisih.
     *
     * Harus dipanggil DI DALAM transaksi yang sama dengan posting jurnal.
     *
     * @param  float  $nominal  Nominal yang ditambahkan (selalu positif).
     * @return bool true bila saldo rekening berhasil ditambahkan.
     */
    private function tambahSaldoRekeningWajib(Anggota $anggota, float $nominal): bool
    {
        if ($nominal <= 0) {
            return false;
        }

        $wajib = $this->rekeningWajib($anggota);

        if (! $wajib) {
            return false;
        }

        $wajibTerkunci = Rekening::query()->lockForUpdate()->find($wajib->id);

        if (! $wajibTerkunci) {
            return false;
        }

        $wajibTerkunci->update([
            'saldo' => Setting::bulatkanUang((float) $wajibTerkunci->saldo + $nominal),
        ]);

        return true;
    }

    /**
     * Catat SATU tindakan penanganan ke riwayat audit (append-only).
     *
     * TITIK PENCATATAN TUNGGAL — seluruh tindakan sistem/petugas atas tagihan
     * (auto-debit, notifikasi, jadwal bertahap, cicilan, reschedule, teller,
     * overdue) mengalir lewat sini agar riwayat konsisten dan lengkap
     * (spesifikasi #5). Kegagalan pencatatan TIDAK PERNAH menggagalkan
     * transaksi akuntansi yang sudah berjalan (hanya dicatat ke log).
     *
     * @param  array<string, mixed>  $atribut
     */
    public function catatTindakan(MandatorySavingsBill $bill, string $jenis, array $atribut = []): ?SimpananWajibTindakan
    {
        try {
            return SimpananWajibTindakan::create(array_merge([
                'bill_id' => $bill->id,
                'member_id' => $bill->member_id,
                'jenis' => $jenis,
                'periode' => $bill->bill_period,
                'nominal' => (float) $bill->amount,
                'status_hasil' => SimpananWajibTindakan::STATUS_BERHASIL,
                'denda' => 0, // PRINSIP SYARIAH — tidak pernah ada denda.
                'waktu_tindakan' => Carbon::now(),
            ], $atribut));
        } catch (\Throwable $e) {
            Log::notice('MandatorySavingsService: riwayat tindakan tidak tersimpan.', [
                'bill_id' => $bill->id,
                'jenis' => $jenis,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /* =====================================================================
     | 6B. PENANGANAN DINAMIS TUNGGAKAN (Tahap 12 / R4 — prinsip syariah)
     |===================================================================== */
    /**
     * Modul penanganan ketika saldo Simpanan Sukarela anggota TIDAK MENCUKUPI
     * atau KOSONG — fleksibel & memberdayakan anggota, bukan sekadar menandai
     * OVERDUE. Seluruh jalur berlaku TANPA DENDA (larangan riba/ta'zir pada
     * keterlambatan simpanan) dan setiap tindakan tercatat pada riwayat audit.
     *
     * Tiga jalur adaptif (dipilih berdasarkan kemampuan anggota):
     *   1. JADWAL BERTAHAP  — pecah nominal tagihan menjadi beberapa cicilan
     *      sesuai kemampuan; tenggat tiap cicilan dihitung dari interval yang
     *      diajukan anggota. Cicilan dibayar dari saldo Sukarela yang tersedia
     *      (bila ada) tanpa memaksa saldo negatif.
     *   2. RESCHEDULE       — geser tenggat tagihan N bulan ke depan, TANPA
     *      DENDA, dibatasi jumlah maksimum agar tidak menjadi penundaan abadi.
     *   3. PRIORITAS        — kekurangan ringan (≤ ambang) → cicilan singkat;
     *      kekurangan berat    → reschedule lebih dahulu.
     */

    /**
     * Terapkan penanganan dinamis pada SATU tagihan yang saldo Sukarelanya
     * tidak mencukupi. Mengembalikan deskripsi tindakan yang diambil.
     *
     * @param  int|null  $userId  Petugas yang memicu (null = sistem).
     * @param  string|null  $preferensi  'BERTAHAP'|'RESCHEDULE'|null (otomatis).
     * @param  int|null  $maksCicilan  Override maks cicilan (opsional).
     * @return array{jenis:?string, ok:bool, alasan:?string, cicilan:int, nominal_cicilan:float, tenggat_baru:?string}
     */
    public function tanganiKekuranganSaldo(
        MandatorySavingsBill $bill,
        ?int $userId = null,
        ?string $preferensi = null,
        ?int $maksCicilan = null
    ): array {
        $kosong = [
            'jenis' => null,
            'ok' => false,
            'alasan' => null,
            'cicilan' => 0,
            'nominal_cicilan' => 0.0,
            'tenggat_baru' => null,
        ];

        // Saklar modul penanganan dinamis (kebijakan Pengurus).
        if (! $this->penangananDinamisAktif()) {
            $kosong['alasan'] = 'MODUL_NONAKTIF';

            return $kosong;
        }

        if ($bill->isPaid()) {
            $kosong['alasan'] = 'SUDAH_LUNAS';

            return $kosong;
        }

        $anggota = $bill->member;

        if (! $anggota) {
            $kosong['alasan'] = 'ANGGOTA_TIDAK_DITEMUKAN';

            return $kosong;
        }

        $saldo = $this->saldoSukarela($anggota);
        $kekurangan = Setting::bulatkanUang((float) $bill->amount - max(0.0, $saldo));

        // Pemilihan jalur OTOMATIS (dinamis, memprioritaskan pemberdayaan):
        //   - kekurangan RINGAN (≤ ambang) → jadwal bertahap singkat.
        //   - kekurangan BERAT              → reschedule (beri waktu lebih dulu).
        if ($preferensi === null) {
            $preferensi = $kekurangan <= Setting::simpananWajibAmbangKurangRingan()
                ? SimpananWajibTindakan::JENIS_JADWAL_BERTAHAP
                : SimpananWajibTindakan::JENIS_RESCHEDULE;
        }

        $ringkas = ($preferensi === SimpananWajibTindakan::JENIS_JADWAL_BERTAHAP)
            ? $this->jadwalkanBertahap($bill, $maksCicilan, $saldo, $userId)
            : $this->reschedule($bill, null, $userId);

        return array_merge($kosong, $ringkas);
    }

    /**
     * Jalur 1 — JADWAL PEMBAYARAN BERTAHAP sesuai kemampuan anggota.
     *
     * Nominal tagihan pecah menjadi `$jumlahCicilan` cicilan; tenggat cicilan
     * pertama tetap (segera) dan cicilan berikutnya berjarak SATU BULAN.
     * Penjadwalan ulang tenggat TIDAK mengurangi nominal — hanya merelaksasi
     * waktu, karena prinsip syariah melarang pengenaan denda/bunga atas
     * keterlambatan (hanya memberi kelonggaran; QS. Al-Baqarah 2:280).
     *
     * @return array{jenis:string, ok:bool, alasan:?string, cicilan:int, nominal_cicilan:float, tenggat_baru:?string}
     */
    public function jadwalkanBertahap(
        MandatorySavingsBill $bill,
        ?int $jumlahCicilan = null,
        ?float $saldoSukarela = null,
        ?int $userId = null
    ): array {
        $gagal = fn (string $alasan, int $cicilan = 0) => [
            'jenis' => SimpananWajibTindakan::JENIS_JADWAL_BERTAHAP,
            'ok' => false,
            'alasan' => $alasan,
            'cicilan' => $cicilan,
            'nominal_cicilan' => 0.0,
            'tenggat_baru' => null,
        ];

        if ($bill->isPaid()) {
            return $gagal('SUDAH_LUNAS');
        }

        // Bila tagihan sudah punya jadwal bertahap aktif → tolak duplikat.
        if ($bill->jadwalBertahap()->exists()) {
            return $gagal('SUDAH_ADA_JADWAL');
        }

        $nominal = Setting::bulatkanUang((float) $bill->amount);

        if ($nominal <= 0) {
            return $gagal('NOMINAL_NOL');
        }

        // Tentukan jumlah cicilan secara DINAMIS, dengan BATAS KEBIJAKAN yang
        // selalu berlaku: permintaan anggota/petugas dijepit ke [2, maks].
        // (maks 0 = tanpa batas). Kekurangan berat → estimasi lebih banyak.
        $saldo = $saldoSukarela ?? ($bill->member ? $this->saldoSukarela($bill->member) : 0.0);
        $kurang = max(0.0, $nominal - max(0.0, $saldo));

        // Bila saldo Sukarela sudah cukup → langsung lunas (tidak perlu cicilan).
        if ($kurang <= 0) {
            return $gagal('SALDO_CUKUP');
        }

        $estimasi = (int) ceil($kurang / max(1.0, $nominal / 6));
        $diminta = $jumlahCicilan ?? $estimasi;
        $maksKebijakan = Setting::simpananWajibMaksCicilan();
        $batas = $maksKebijakan > 0 ? $maksKebijakan : max(2, $diminta);
        $jumlah = max(2, min($diminta, $batas));

        $perCicilan = Setting::bulatkanUang($nominal / $jumlah);
        // Sisa pembulatan ditambahkan ke cicilan terakhir agar total tetap sama.
        $sisa = Setting::bulatkanUang($nominal - ($perCicilan * ($jumlah - 1)));

        $tenggatBaru = $this->tanggalJatuhTempo($bill->member, $this->periodeTagihan($bill))
            ->copy()->addMonthNoOverflow();

        $this->catatTindakan($bill, SimpananWajibTindakan::JENIS_JADWAL_BERTAHAP, [
            'nominal' => $nominal,
            'saldo_sukarela' => $saldo,
            'cicilan_ke' => 0,
            'total_cicilan' => $jumlah,
            'nominal_cicilan' => $perCicilan,
            'tenggat_lama' => $this->tanggalJatuhTempo($bill->member, $this->periodeTagihan($bill))->toDateString(),
            'tenggat_baru' => $tenggatBaru->toDateString(),
            'denda' => 0,
            'alasan' => 'SALDO_TIDAK_CUKUP',
            'catatan' => sprintf(
                'Jadwal bertahap %d cicilan @ Rp %s (cicilan terakhir Rp %s), TOTAL tetap Rp %s — tanpa denda.',
                $jumlah,
                number_format($perCicilan, 0, ',', '.'),
                number_format($sisa, 0, ',', '.'),
                number_format($nominal, 0, ',', '.')
            ),
            'user_id' => $userId,
        ]);

        return [
            'jenis' => SimpananWajibTindakan::JENIS_JADWAL_BERTAHAP,
            'ok' => true,
            'alasan' => null,
            'cicilan' => $jumlah,
            'nominal_cicilan' => $perCicilan,
            'tenggat_baru' => $tenggatBaru->toDateString(),
        ];
    }

    /**
     * Jalur 2 — PENJADWALAN ULANG (reschedule) tenggat TANPA DENDA.
     *
     * Menggeser tenggat tagihan `simpanan_wajib_reschedule_bulan` bulan ke
     * depan, dibatasi `simpanan_wajib_maks_reschedule` kali per tagihan.
     * TIDAK mengubah nominal sedikit pun (tidak ada penalti/bunga).
     *
     * @return array{jenis:string, ok:bool, alasan:?string, cicilan:int, nominal_cicilan:float, tenggat_baru:?string}
     */
    public function reschedule(
        MandatorySavingsBill $bill,
        ?Carbon $tenggatBaru = null,
        ?int $userId = null
    ): array {
        $gagal = fn (string $alasan) => [
            'jenis' => SimpananWajibTindakan::JENIS_RESCHEDULE,
            'ok' => false,
            'alasan' => $alasan,
            'cicilan' => 0,
            'nominal_cicilan' => 0.0,
            'tenggat_baru' => null,
        ];

        if ($bill->isPaid()) {
            return $gagal('SUDAH_LUNAS');
        }

        if (! $bill->member) {
            return $gagal('ANGGOTA_TIDAK_DITEMUKAN');
        }

        // Batas jumlah reschedule per tagihan (0 = tanpa batas).
        $sudahReschedule = $bill->tindakan()->where('jenis', SimpananWajibTindakan::JENIS_RESCHEDULE)->count();
        $maks = Setting::simpananWajibMaksReschedule();

        if ($maks > 0 && $sudahReschedule >= $maks) {
            return $gagal('BATAS_RESCHEDULE_TERCAPAI');
        }

        $tenggatLama = $this->tanggalJatuhTempo($bill->member, $this->periodeTagihan($bill));
        $tenggatBaru = $tenggatBaru
            ?? $tenggatLama->copy()->addMonthsNoOverflow(max(1, Setting::simpananWajibRescheduleBulan()));

        $this->catatTindakan($bill, SimpananWajibTindakan::JENIS_RESCHEDULE, [
            'nominal' => (float) $bill->amount,
            'saldo_sukarela' => $this->saldoSukarela($bill->member),
            'tenggat_lama' => $tenggatLama->toDateString(),
            'tenggat_baru' => $tenggatBaru->toDateString(),
            'denda' => 0, // PRINSIP SYARIAH — reschedule TANPA denda.
            'alasan' => 'SALDO_TIDAK_CUKUP',
            'catatan' => sprintf(
                'Tenggat digeser %s → %s tanpa denda (reschedule ke-%d).',
                $tenggatLama->format('d/m/Y'),
                $tenggatBaru->format('d/m/Y'),
                $sudahReschedule + 1
            ),
            'user_id' => $userId,
        ]);

        return [
            'jenis' => SimpananWajibTindakan::JENIS_RESCHEDULE,
            'ok' => true,
            'alasan' => null,
            'cicilan' => 0,
            'nominal_cicilan' => 0.0,
            'tenggat_baru' => $tenggatBaru->toDateString(),
        ];
    }

    /**
     * Bayar SATU cicilan jadwal bertahap dari saldo Simpanan Sukarela.
     *
     * Pemotongan dilakukan HANYA sebesar yang tersedia (tidak pernah memaksa
     * saldo negatif) dan TANPA denda. Bila akumulasi cicilan mencapai nominal
     * tagihan, tagihan otomatis menjadi PAID dan jurnal PSAK diposting.
     *
     * @return array{ok:bool, alasan:?string, dibayar:float, sisa:float, lunas:bool}
     */
    public function bayarCicilan(MandatorySavingsBill $bill, ?int $userId = null): array
    {
        if ($bill->isPaid()) {
            return ['ok' => false, 'alasan' => 'SUDAH_LUNAS', 'dibayar' => 0.0, 'sisa' => 0.0, 'lunas' => true];
        }

        $anggota = $bill->member;

        if (! $anggota) {
            return ['ok' => false, 'alasan' => 'ANGGOTA_TIDAK_DITEMUKAN', 'dibayar' => 0.0, 'sisa' => 0.0, 'lunas' => false];
        }

        /* GERBANG OTORISASI AUTO-DEBIT.
         | Cicilan dipotong dari Simpanan Sukarela anggota, sehingga termasuk
         | auto-debit. Bila produk tidak mengizinkan atau anggota menolak
         | (`is_auto_debit = false`), TIDAK ADA saldo yang boleh dipotong —
         | tagihan harus dilunasi lewat jalur tunai/manual di Teller. */
        if (! $this->bolehAutoDebit($anggota)) {
            $this->catatTindakan($bill, SimpananWajibTindakan::JENIS_CICILAN_DIBAYAR, [
                'nominal' => 0,
                'saldo_sukarela' => $this->saldoSukarela($anggota),
                'status_hasil' => SimpananWajibTindakan::STATUS_GAGAL,
                'alasan' => 'TIDAK_BERHAK_AUTO_DEBIT',
                'catatan' => 'Cicilan tidak dipotong — produk tidak mengizinkan auto-debit atau anggota menolak pemotongan otomatis. Silakan setor tunai di Teller.',
                'user_id' => $userId,
            ]);

            return ['ok' => false, 'alasan' => 'TIDAK_BERHAK_AUTO_DEBIT', 'dibayar' => 0.0, 'sisa' => 0.0, 'lunas' => false];
        }

        $jadwal = $bill->tindakan()
            ->where('jenis', SimpananWajibTindakan::JENIS_JADWAL_BERTAHAP)
            ->latest('waktu_tindakan')
            ->first();

        if (! $jadwal || (float) $jadwal->nominal_cicilan <= 0) {
            return ['ok' => false, 'alasan' => 'BELUM_ADA_JADWAL', 'dibayar' => 0.0, 'sisa' => 0.0, 'lunas' => false];
        }

        $sudahDibayar = (float) $bill->tindakan()
            ->where('jenis', SimpananWajibTindakan::JENIS_CICILAN_DIBAYAR)
            ->sum('nominal');

        $sisa = Setting::bulatkanUang((float) $bill->amount - $sudahDibayar);
        $targetCicil = Setting::bulatkanUang(min((float) $jadwal->nominal_cicilan, $sisa));
        $saldo = $this->saldoSukarela($anggota);
        $dibayar = Setting::bulatkanUang(min($targetCicil, max(0.0, $saldo)));

        if ($dibayar <= 0) {
            $this->catatTindakan($bill, SimpananWajibTindakan::JENIS_CICILAN_DIBAYAR, [
                'nominal' => 0,
                'saldo_sukarela' => $saldo,
                'status_hasil' => SimpananWajibTindakan::STATUS_GAGAL,
                'alasan' => 'SALDO_KOSONG',
                'catatan' => 'Cicilan tidak dapat dipotong — saldo Simpanan Sukarela kosong. Silakan setor tunai di Teller.',
                'user_id' => $userId,
            ]);

            return ['ok' => false, 'alasan' => 'SALDO_KOSONG', 'dibayar' => 0.0, 'sisa' => $sisa, 'lunas' => false];
        }

        $rekening = $this->rekeningSukarela($anggota);

        // Pemotongan saldo + jurnal dijalankan oleh bayarViaTeller? TIDAK:
        // jadwal bertahap adalah pindah buku antar-simpanan anggota sendiri
        // (Sukarela → Wajib), sehingga memakai jalur auto-debit yang sudah
        // teruji (lockForUpdate anti-race + jurnal DEBET 2-1001 | KREDIT 3-1002).
        $berhasil = DB::transaction(function () use ($bill, $anggota, $rekening, $dibayar, $userId) {
            $rekeningTerkunci = $rekening
                ? Rekening::query()->lockForUpdate()->find($rekening->id)
                : null;

            if (! $rekeningTerkunci || (float) $rekeningTerkunci->saldo < $dibayar) {
                return false;
            }

            $keterangan = sprintf(
                'Cicilan Simpanan Wajib — %s (%s)',
                $bill->nama_periode,
                $anggota->nama_lengkap
            );

            $transaksi = Transaksi::create([
                'no_transaksi' => $this->generateNoTransaksi(),
                'anggota_id' => $anggota->id,
                'rekening_id' => $rekeningTerkunci->id,
                'jenis_transaksi' => 'Setoran Simpanan Wajib',
                'jumlah' => $dibayar,
                'posisi' => 'Debet',
                'sumber_transaksi' => 'Auto',
                'waktu_transaksi' => now(),
                'status_transaksi' => 'Sukses',
                'status' => 'success',
                'catatan' => $keterangan,
            ]);

            $rekeningTerkunci->update(['saldo' => Setting::bulatkanUang((float) $rekeningTerkunci->saldo - $dibayar)]);

            $akunWajib = $this->akunSimpananWajib();

            $jurnal = $this->journaling->jurnalSimpananWajibAutoDebit(
                $dibayar,
                $keterangan,
                $userId,
                $transaksi->id,
                $rekeningTerkunci->id,
                $anggota->id,
                now()->toDateString(),
                $akunWajib,
                self::COA_SIMPANAN_SUKARELA,
                $this->rekeningWajib($anggota)?->id
            );

            if (! $jurnal) {
                throw new \RuntimeException('Auto-journal cicilan Simpanan Wajib gagal diposting.');
            }

            // Subledger rekening Wajib ikut bertambah agar GL ≡ rekening.
            $this->tambahSaldoRekeningWajib($anggota, $dibayar);

            return $transaksi->id;
        });

        if ($berhasil === false) {
            return ['ok' => false, 'alasan' => 'SALDO_BERUBAH', 'dibayar' => 0.0, 'sisa' => $sisa, 'lunas' => false];
        }

        $totalDibayar = Setting::bulatkanUang($sudahDibayar + $dibayar);
        $sisaAkhir = Setting::bulatkanUang((float) $bill->amount - $totalDibayar);
        $lunas = $sisaAkhir <= 0;

        $this->catatTindakan($bill, SimpananWajibTindakan::JENIS_CICILAN_DIBAYAR, [
            'nominal' => $dibayar,
            'saldo_sukarela' => $saldo,
            'cicilan_ke' => $bill->tindakan()->where('jenis', SimpananWajibTindakan::JENIS_CICILAN_DIBAYAR)->count(),
            'total_cicilan' => (int) $jadwal->total_cicilan,
            'nominal_cicilan' => (float) $jadwal->nominal_cicilan,
            'denda' => 0,
            'alasan' => 'CICILAN_TERBAYAR',
            'catatan' => sprintf(
                'Cicilan Rp %s dipotong dari Simpanan Sukarela. Akumulasi Rp %s / Rp %s.',
                number_format($dibayar, 0, ',', '.'),
                number_format($totalDibayar, 0, ',', '.'),
                number_format((float) $bill->amount, 0, ',', '.')
            ),
            'user_id' => $userId,
        ]);

        if ($lunas) {
            $bill->update([
                'status' => MandatorySavingsBill::STATUS_PAID,
                'paid_at' => now(),
                'payment_method' => MandatorySavingsBill::METODE_AUTO_DEBIT,
            ]);

            $this->lepasPenahananShuBilaLunas($anggota);
        }

        return ['ok' => true, 'alasan' => null, 'dibayar' => $dibayar, 'sisa' => $sisaAkhir, 'lunas' => $lunas];
    }

    /**
     * Ringkasan status penanganan satu tagihan (dipakai UI daftar tertagih):
     * kekurangan terhadap saldo Sukarela, jumlah cicilan terjadwal, riwayat
     * reschedule, dan status pelunasan.
     *
     * @return array<string, mixed>
     */
    public function ringkasanPenanganan(MandatorySavingsBill $bill): array
    {
        $saldo = $bill->member ? $this->saldoSukarela($bill->member) : 0.0;
        $jadwal = $bill->tindakan()->where('jenis', SimpananWajibTindakan::JENIS_JADWAL_BERTAHAP)->latest('waktu_tindakan')->first();
        $reschedule = $bill->tindakan()->where('jenis', SimpananWajibTindakan::JENIS_RESCHEDULE)->count();
        $dibayar = (float) $bill->tindakan()->where('jenis', SimpananWajibTindakan::JENIS_CICILAN_DIBAYAR)->sum('nominal');

        return [
            'saldo_sukarela' => $saldo,
            'kekurangan' => Setting::bulatkanUang(max(0.0, (float) $bill->amount - $saldo)),
            'ada_jadwal' => (bool) $jadwal,
            'total_cicilan' => $jadwal ? (int) $jadwal->total_cicilan : 0,
            'nominal_cicilan' => $jadwal ? (float) $jadwal->nominal_cicilan : 0.0,
            'sudah_dicicil' => $dibayar,
            'jumlah_reschedule' => $reschedule,
            'maks_reschedule' => Setting::simpananWajibMaksReschedule(),
            'boleh_reschedule' => $this->bolehReschedule($bill),
            'penanganan_dinamis_aktif' => $this->penangananDinamisAktif(),
            'denda' => 0.0,
            // Alias agregat seluruh tindakan (audit) — untuk verifikasi UI/uji.
            'total_denda' => 0.0,
        ];
    }

    /** Apakah tagihan masih boleh di-reschedule (batas belum tercapai)? */
    public function bolehReschedule(MandatorySavingsBill $bill): bool
    {
        if ($bill->isPaid() || ! $this->penangananDinamisAktif()) {
            return false;
        }

        $maks = Setting::simpananWajibMaksReschedule();

        if ($maks === 0) {
            return true;
        }

        return $bill->tindakan()->where('jenis', SimpananWajibTindakan::JENIS_RESCHEDULE)->count() < $maks;
    }

    /** Saldo Simpanan Sukarela anggota (0 bila rekening tidak ada). */
    public function saldoSukarela(Anggota $anggota): float
    {
        $rekening = $this->rekeningSukarela($anggota);

        return $rekening ? Setting::bulatkanUang((float) $rekening->saldo) : 0.0;
    }

    /**
     * Tagihan yang masih terbuka (UNPAID/OVERDUE) milik anggota.
     */
    public function tagihanTerbuka(Anggota $anggota): Collection
    {
        return MandatorySavingsBill::query()
            ->where('member_id', $anggota->id)
            ->belumLunas()
            ->orderBy('bill_period')
            ->get();
    }

    /** Nomor transaksi harian: TRX-YYYYMMDD-XXXXXX. */
    private function generateNoTransaksi(): string
    {
        // K-03: penomoran kini melalui sumber urutan tunggal yang mengunci baris
        // (SELECT ... FOR UPDATE) sehingga dua proses paralel tidak menghasilkan
        // nomor kembar. Wajib dipanggil di dalam DB::transaction() pemanggil.
        // Awalan dapat diubah Pengurus (Setting `prefix_nomor_transaksi`).
        return NomorDokumen::buat('transaksi');
    }

    /* =====================================================================
     | 7. PENAHANAN SHU (SISTEMIK) — PEMICU PELEPASAN OTOMATIS
     |===================================================================== */

    /**
     * Lepas penahanan SHU anggota begitu SELURUH tunggakan Simpanan Wajibnya
     * lunas. Dipanggil otomatis setelah pembayaran (Teller maupun auto-debit)
     * agar hak SHU anggota tidak menggantung tanpa intervensi manual.
     *
     * Jurnal pelepasan: DEBET 2-1004 Utang SHU Ditahan / KREDIT 2-1001
     * Simpanan Sukarela — reklasifikasi kewajiban menjadi hak anggota.
     *
     * @return int Jumlah baris distribusi SHU yang dilepas.
     */
    public function lepasPenahananShuBilaLunas(Anggota $anggota): int
    {
        if (! Setting::tahanShuSaatMenunggak()) {
            return 0;
        }

        // Masih ada tunggakan → penahanan tetap berlaku (sanksi sistemik).
        if (MandatorySavingsBill::query()
            ->where('member_id', $anggota->id)
            ->where('status', MandatorySavingsBill::STATUS_OVERDUE)
            ->exists()) {
            return 0;
        }

        return $this->calculator->lepasPenahananAnggota($anggota);
    }
}
