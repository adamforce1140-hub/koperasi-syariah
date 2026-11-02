<?php

namespace App\Services;

use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\AuditLog;
use App\Models\Coa;
use App\Models\Deposito;
use App\Models\ExitApprovalLog;
use App\Models\JenisSimpanan;
use App\Models\MandatorySavingsBill;
use App\Models\MemberTermination;
use App\Models\Pembiayaan;
use App\Models\Rekening;
use App\Models\TazirPenangguhan;
use App\Models\Transaksi;
use App\Models\User;
use App\Notifications\PengunduranDiriApprovalNotification;
use App\Support\Coa as CoaRegistry;
use App\Support\Izin;
use App\Support\NomorDokumen;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * EXIT KEANGGOTAAN (PENGUNDURAN DIRI) — satu-satunya pintu SAH pengembalian dana
 * Simpanan Pokok (CoA 3-1001) dan Simpanan Wajib (CoA 3-1002).
 *
 * Landasan syariah & regulasi:
 *  - PSAK 101 ¶ 31 : Simpanan Pokok & Simpanan Wajib adalah MODAL/EKUITAS
 *                    anggota — bukan kewajiban jangka pendek yang bisa ditarik
 *                    kapan saja. Karena itu penarikan rutin DILARANG.
 *  - PSAK 108     : pengembalian modal anggota saat keluar koperasi.
 *  - UU No. 25/1992 Pasal 30 & PP No. 7/2021 : keanggotaan berakhir/keluar,
 *                    penyelesaian hak anggota dilakukan setelah kewajiban lunas.
 *
 * Prinsip yang ditegakkan modul ini:
 *  1. Kelayakan exit WAJIB lobos dulu (`pastikanLayakExit()`) — tidak ada
 *     piutang pembiayaan aktif, tidak ada tunggakan angsuran / ta'zir / denda,
 *     tidak ada tagihan Simpanan Wajib tertunggak, tidak ada biaya administrasi.
 *  2. Eksekusi refund + auto-journal PSAK 108 terjadi dalam SATU transaksi DB.
 *  3. Jurnal hanya boleh diposting SEKALI (idempotensi via `jurnal_id`).
 *  4. Status anggota baru menjadi TERMINATED setelah jurnal sukses ter-posted.
 *  5. Jejak audit lengkap: tanggal pengunduran diri, total dana dikembalikan,
 *     & user pemroses.
 */
class ExitKeanggotaanService
{
    public function __construct(
        private AutoJournalingService $journaling,
        private MandatorySavingsService $simpananWajib,
    ) {}

    /* =====================================================================
     | 1. KELAYAKAN EXIT — GERBANG PENGEMBALIAN DANA
     |===================================================================== */

    /**
     * Pastikan anggota BERSIH dari seluruh kewajiban sebelum dananya boleh
     * dikembalikan. Melempar RuntimeException berisi pesan yang jelas bila
     * masih ada kewajiban menggantung — proses exit dibatalkan otomatis.
     */
    public function pastikanLayakExit(Anggota $anggota): void
    {
        $kendala = $this->kendalaExit($anggota);

        if ($kendala !== []) {
            $this->catatBlokirExit($anggota, $kendala);

            throw new RuntimeException(
                'Exit keanggotaan dibatalkan — anggota masih memiliki kewajiban yang belum '
                .'diselesaikan:'.PHP_EOL.PHP_EOL
                .'- '.implode(PHP_EOL.'- ', $kendala).PHP_EOL.PHP_EOL
                .'Simpanan Pokok & Simpanan Wajib adalah Modal/Ekuitas Koperasi (PSAK 101 ¶ 31) '
                .'dan hanya dapat dikembalikan setelah seluruh kewajiban anggota LUNAS.'
            );
        }
    }

    /**
     * Daftar kewajiban yang menghalangi exit. Array kosong = anggota bersih.
     *
     * @return array<int, string>
     */
    public function kendalaExit(Anggota $anggota): array
    {
        return array_merge(
            $this->kendalaPembiayaan($anggota),
            $this->kendalaAngsuran($anggota),
            $this->kendalaTazirDenda($anggota),
            $this->kendalaTagihanSimpananWajib($anggota),
            $this->kendalaPenjamin($anggota),
        );
    }

    /** Apakah anggota bebas dari seluruh kewajiban (tanpa melempar exception). */
    public function layakExit(Anggota $anggota): bool
    {
        return $this->kendalaExit($anggota) === [];
    }

    /**
     * Nilai NPF (Non-Performing Financing) anggota — rasio sisa kewajiban
     * pembiayaan berjalan terhadap total plafon.
     *
     * Nilai 0.0 berarti SELURUH pembiayaan anggota LUNAS (tidak ada sisa
     * kewajiban) — syarat mutlak pengembalian dana Simpanan Pokok & Wajib
     * (Bagian 4 poin 2). Pembiayaan lunas/ditolak/dibatalkan tidak dihitung.
     */
    public function npfAnggota(Anggota $anggota): float
    {
        $daftar = Pembiayaan::query()
            ->where('anggota_id', $anggota->id)
            ->whereNotIn(DB::raw('LOWER(status_pembiayaan)'), [
                Pembiayaan::STATUS_LUNAS,
                Pembiayaan::STATUS_DITOLAK,
                Pembiayaan::STATUS_DIBATALKAN,
            ])
            ->get();

        $totalPlafon = 0.0;
        $totalSisa = 0.0;

        foreach ($daftar as $pembiayaan) {
            if ($pembiayaan->isLunas()) {
                continue;
            }

            $sisa = (float) $pembiayaan->sisa_pembiayaan;

            if ($sisa <= 0) {
                continue;
            }

            $totalPlafon += (float) $pembiayaan->jumlah_pembiayaan;
            $totalSisa += $sisa;
        }

        if ($totalPlafon <= 0) {
            return 0.0;
        }

        return round($totalSisa / $totalPlafon, 6);
    }

    /**
     * Pastikan NPF anggota = 0 (seluruh pembiayaan LUNAS) sebelum pengembalian
     * dana Simpanan Pokok & Wajib dieksekusi.
     *
     * @throws RuntimeException
     */
    public function pastikanNpfNol(Anggota $anggota): void
    {
        $npf = $this->npfAnggota($anggota);

        if ($npf > 0.0) {
            $this->catatBlokirExit($anggota, [
                sprintf('NPF (Non-Performing Financing) anggota = %s (harus 0 / seluruh pembiayaan LUNAS).', $npf),
            ]);

            throw new RuntimeException(sprintf(
                'Pengembalian dana Simpanan Pokok/Wajib DITOLAK — NPF anggota %s masih %s '
                .'(bukan 0). Seluruh pembiayaan wajib LUNAS (PAID_OFF) terlebih dahulu.',
                $anggota->nama_lengkap ?? ('#'.$anggota->id),
                number_format($npf, 6, ',', '.')
            ));
        }
    }

    /**
     * (a) Seluruh kontrak pembiayaan harus PAID_OFF/LUNAS (NPF = 0).
     * Pembiayaan yang sudah berakhir (lunas / ditolak / dibatalkan) diabaikan;
     * sisanya dianggap masih berjalan dan wajib diselesaikan lebih dulu.
     *
     * @return array<int, string>
     */
    private function kendalaPembiayaan(Anggota $anggota): array
    {
        $kendala = [];

        $daftar = Pembiayaan::query()
            ->where('anggota_id', $anggota->id)
            ->whereNotIn(DB::raw('LOWER(status_pembiayaan)'), [
                Pembiayaan::STATUS_LUNAS,
                Pembiayaan::STATUS_DITOLAK,
                Pembiayaan::STATUS_DIBATALKAN,
            ])
            ->get();

        foreach ($daftar as $pembiayaan) {
            // Kontrak batal / tolak → tidak menanggung kewajiban.
            if ($pembiayaan->isStatus([Pembiayaan::STATUS_DITOLAK, Pembiayaan::STATUS_DIBATALKAN])) {
                continue;
            }

            // Status LUNAS atau sisa pokok sudah nol → bersih.
            if ($pembiayaan->isLunas()) {
                continue;
            }

            $sisa = (float) $pembiayaan->sisa_pembiayaan;

            $kendala[] = sprintf(
                'Pembiayaan %s (%s) masih berjalan — sisa kewajiban Rp %s. '
                .'Semua pembiayaan harus LUNAS (PAID_OFF) sebelum anggota dapat keluar (NPF = 0).',
                $pembiayaan->no_pembiayaan ?? ('#'.$pembiayaan->id),
                (string) $pembiayaan->status_pembiayaan,
                number_format($sisa, 2, ',', '.')
            );
        }

        return $kendala;
    }

    /**
     * (a) Tidak boleh ada angsuran yang belum dibayar (termasuk yang terlambat
     * dan dibayar sebagian) pada pembiayaan yang masih berjalan.
     *
     * @return array<int, string>
     */
    private function kendalaAngsuran(Anggota $anggota): array
    {
        $jumlah = Angsuran::query()
            ->whereHas('pembiayaan', function ($query) use ($anggota) {
                $query->where('anggota_id', $anggota->id)
                    ->whereNotIn(DB::raw('LOWER(status_pembiayaan)'), [
                        Pembiayaan::STATUS_LUNAS,
                        Pembiayaan::STATUS_DITOLAK,
                        Pembiayaan::STATUS_DIBATALKAN,
                    ]);
            })
            ->belumDibayar()
            ->count();

        if ($jumlah === 0) {
            return [];
        }

        return [sprintf(
            'Terdapat %d angsuran yang belum dibayar (termasuk tunggakan). '
            .'Seluruh angsuran wajib dilunasi sebelum pengembalian dana exit.',
            $jumlah
        )];
    }

    /**
     * (b) Kewajiban lain: ta'zir/denda & biaya administrasi yang belum dibayar.
     *
     * @return array<int, string>
     */
    private function kendalaTazirDenda(Anggota $anggota): array
    {
        $kendala = [];

        $tunggakanTazir = TazirPenangguhan::totalTunggakanAnggota($anggota->id);

        if ($tunggakanTazir > 0) {
            $kendala[] = sprintf(
                'Tunggakan ta\'zir/denda sebesar Rp %s belum diselesaikan. '
                .'Anggota harus bersih dari seluruh kewajiban finansial.',
                number_format($tunggakanTazir, 2, ',', '.')
            );
        }

        return $kendala;
    }

    /**
     * (b) Tagihan Simpanan Wajib bulanan yang masih tertunggak (UNPAID/OVERDUE).
     *
     * @return array<int, string>
     */
    private function kendalaTagihanSimpananWajib(Anggota $anggota): array
    {
        $jumlah = MandatorySavingsBill::query()
            ->where('member_id', $anggota->id)
            ->belumLunas()
            ->count();

        if ($jumlah === 0) {
            return [];
        }

        return [sprintf(
            'Terdapat %d tagihan Simpanan Wajib bulanan yang belum lunas. '
            .'Selesaikan seluruh tagihan sebelum pengembalian dana exit.',
            $jumlah
        )];
    }

    /**
     * (c) Anggota masih tercatat sebagai PENJAMIN pada pembiayaan anggota lain
     * yang berstatus bermasalah (macet / diragukan / hapus buku) atau memiliki
     * tunggakan lebih dari 3 bulan.
     *
     * Tabel `pembiayaan_penjamin` bersifat opsional: bila belum ada data
     * penjamin, pengecekan otomatis lolos dan tidak memblokir exit.
     *
     * E-01 (batch 10): hanya kontrak yang DANANYA SUDAH DISALURKAN yang dapat
     * membebani penjamin. Sebelumnya query ini memakai daftar status 4 nilai
     * (mengecualikan lunas/ditolak/dibatalkan), sehingga pengajuan yang masih
     * menunggu keputusan (pending_ao … pending_pencairan) ikut menahan penjamin
     * keluar — padahal belum ada risiko yang dijamin.
     *
     * Daftar status di bawah = `Pembiayaan::statusTercair()` + dua status
     * BERMASALAH (`diragukan`, `hapus_buku`) yang justru menjadi alasan utama
     * penjamin ditahan. Keduanya sengaja tetap dihitung karena kewajiban
     * penjamin belum berakhir walau piutang sudah dikeluarkan dari neraca.
     *
     * @return array<int, string>
     */
    private function kendalaPenjamin(Anggota $anggota): array
    {
        if (! DB::getSchemaBuilder()->hasTable('pembiayaan_penjamin')) {
            return [];
        }

        $kendala = [];

        // Kontrak yang sudah menyalurkan dana ATAU bernasalah.
        $statusMenahanPenjamin = array_values(array_unique(array_merge(
            Pembiayaan::statusTercair(),
            [Pembiayaan::STATUS_HAPUS_BUKU, 'diragukan'],
        )));

        $daftar = DB::table('pembiayaan_penjamin as pj')
            ->join('pembiayaan as p', 'p.id', '=', 'pj.pembiayaan_id')
            ->where('pj.anggota_id', $anggota->id)
            ->whereIn(DB::raw('LOWER(p.status_pembiayaan)'), $statusMenahanPenjamin)
            ->select([
                'p.id',
                'p.no_pembiayaan',
                'p.status_pembiayaan',
                'p.sisa_pembiayaan',
            ])
            ->get();

        $batasTunggakan = Carbon::today()->subMonths(3);

        foreach ($daftar as $pembiayaan) {
            $status = strtolower((string) $pembiayaan->status_pembiayaan);

            $statusBermasalah = in_array($status, [
                Pembiayaan::STATUS_MACET,
                Pembiayaan::STATUS_HAPUS_BUKU,
                'diragukan',
            ], true);

            $tunggakanTertua = Angsuran::query()
                ->where('pembiayaan_id', $pembiayaan->id)
                ->belumDibayar()
                ->whereDate('tanggal_jatuh_tempo', '<', $batasTunggakan->toDateString())
                ->orderBy('tanggal_jatuh_tempo')
                ->value('tanggal_jatuh_tempo');

            if (! $statusBermasalah && $tunggakanTertua === null) {
                continue;
            }

            $kendala[] = sprintf(
                'Anggota terdaftar sebagai PENJAMIN pada pembiayaan %s milik anggota lain '
                .'(status %s, sisa Rp %s%s). Pengunduran diri tidak dapat diproses sebelum '
                .'pembiayaan yang dijamin selesai/lunas.',
                $pembiayaan->no_pembiayaan ?? ('#'.$pembiayaan->id),
                (string) $pembiayaan->status_pembiayaan,
                number_format((float) $pembiayaan->sisa_pembiayaan, 2, ',', '.'),
                $tunggakanTertua !== null
                    ? ', tunggakan sejak '.Carbon::parse($tunggakanTertua)->format('d/m/Y')
                    : ''
            );
        }

        return $kendala;
    }

    /* =====================================================================
     | 2. PENGAJUAN EXIT
     |===================================================================== */

    /**
     * Ajukan permohonan exit keanggotaan (status DIAJUKAN).
     *
     * Kelayakan BELUM diuji di sini — pengurus dapat menerima pengajuan lalu
     * menagih sisa kewajiban lebih dulu. Uji kelayakan dilakukan saat eksekusi.
     *
     * @param  array<string, mixed>  $data  catatan, tanggal_pengunduran_diri, ...
     */
    public function ajukan(Anggota $anggota, array $data = [], ?int $userId = null): MemberTermination
    {
        $userId = $userId ?? Auth::id();

        if ($anggota->isTerminated()) {
            throw new RuntimeException(sprintf(
                'Anggota %s sudah berstatus TERMINATED (keluar resmi dari koperasi).',
                $anggota->nama_lengkap ?? ('#'.$anggota->id)
            ));
        }

        $sudahAda = MemberTermination::query()
            ->where('anggota_id', $anggota->id)
            ->menunggu()
            ->exists();

        if ($sudahAda) {
            throw new RuntimeException(sprintf(
                'Sudah ada pengajuan exit keanggotaan yang masih menunggu proses untuk anggota %s.',
                $anggota->nama_lengkap ?? ('#'.$anggota->id)
            ));
        }

        $tanggalPengunduran = $data['tanggal_pengunduran_diri'] ?? null;
        $tanggal = $tanggalPengunduran
            ? Carbon::parse($tanggalPengunduran)->startOfDay()
            : Carbon::today();

        $rincian = $this->rincianSaldo($anggota, $tanggal);
        $butuhApproval = $this->butuhApproval((float) $rincian['total_net']);

        $terminasi = DB::transaction(function () use (
            $anggota, $data, $userId, $tanggal, $rincian, $butuhApproval
        ) {
            return MemberTermination::create([
                'anggota_id' => $anggota->id,
                'no_terminasi' => MemberTermination::generateNoTerminasi(),
                'no_referensi' => MemberTermination::generateNoReferensi($tanggal),
                'status' => $butuhApproval
                    ? MemberTermination::STATUS_MENUNGGU_APPROVAL
                    : MemberTermination::STATUS_DIAJUKAN,
                'tanggal_pengunduran_diri' => $tanggal->toDateString(),
                'diajukan_oleh' => $userId,
                'diajukan_pada' => now(),
                'catatan' => $data['catatan'] ?? null,
                'exit_reason' => $data['exit_reason'] ?? ($data['catatan'] ?? null),
                'butuh_approval' => $butuhApproval,
                // Nominal pratinjau; dihitung ulang saat eksekusi (anti-manipulasi).
                'nominal_simpanan_pokok' => $rincian['pokok'],
                'nominal_simpanan_wajib' => $rincian['wajib'],
                'nominal_sukarela' => $rincian['sukarela'],
                'nominal_deposito' => $rincian['deposito'],
                'nominal_akrual_bagi_hasil' => $rincian['akrual_bagi_hasil'],
                'nominal_zakat' => $rincian['zakat'],
                'nominal_biaya_admin' => $rincian['biaya_admin'],
                'total_net' => $rincian['total_net'],
                'total_dikembalikan' => 0,
            ]);
        });

        if ($butuhApproval) {
            $this->kirimPermintaanApproval($terminasi);
        }

        return $terminasi;
    }

    /* =====================================================================
     | 2B. PERSETUJUAN MANAJER (HIGH-VALUE WITHDRAWAL)
     |===================================================================== */

    /**
     * Manajer menyetujui pengajuan yang melampaui `high_value_withdrawal_limit`.
     *
     * Status dokumen TETAP `MENUNGGU_APPROVAL`; yang berubah adalah
     * `approved_at` + `disetujui_oleh`. {@see eksekusi()} hanya membuka gerbang
     * pencairan bila `approved_at` sudah terisi, sehingga dana tetap terblokir
     * sampai keputusan Manajer tercatat. Setiap keputusan masuk ke
     * `exit_approval_logs` (jejak audit permanen).
     */
    public function setujui(MemberTermination $terminasi, ?string $catatan = null, ?int $userId = null): MemberTermination
    {
        $userId = $userId ?? Auth::id();

        if ($terminasi->sudahDiposting()) {
            throw new RuntimeException(
                'Dokumen terminasi yang jurnalnya sudah diposting tidak dapat diubah (PSAK 108).'
            );
        }

        if (! $terminasi->menungguApproval()) {
            throw new RuntimeException(sprintf(
                'Dokumen terminasi %s berstatus %s — tidak sedang menunggu persetujuan Manajer.',
                $terminasi->no_terminasi,
                $terminasi->status_label
            ));
        }

        if ($terminasi->approved_at !== null) {
            throw new RuntimeException(sprintf(
                'Dokumen terminasi %s sudah disetujui pada %s.',
                $terminasi->no_terminasi,
                $terminasi->approved_at->format('d/m/Y H:i')
            ));
        }

        return DB::transaction(function () use ($terminasi, $catatan, $userId) {
            $terminasi->fill([
                'approved_at' => now(),
                'disetujui_oleh' => $userId,
            ])->save();

            ExitApprovalLog::create([
                'member_termination_id' => $terminasi->id,
                'user_id' => $userId,
                'aksi' => ExitApprovalLog::AKSI_APPROVED,
                'catatan' => $catatan,
            ]);

            AuditLog::record(
                'EXIT_APPROVAL_DISETUJUI',
                (float) $terminasi->total_net,
                null,
                sprintf(
                    'Manajer (user #%s) menyetujui pencairan terminasi %s (net Rp %s).',
                    $userId,
                    $terminasi->no_referensi ?? $terminasi->no_terminasi,
                    number_format((float) $terminasi->total_net, 2, ',', '.')
                )
            );

            Log::info('EXIT_APPROVAL_DISETUJUI', [
                'terminasi_id' => $terminasi->id,
                'no_referensi' => $terminasi->no_referensi,
                'anggota_id' => $terminasi->anggota_id,
                'total_net' => (float) $terminasi->total_net,
                'user_id' => $userId,
                'catatan' => $catatan,
            ]);

            return $terminasi->refresh();
        });
    }

    /**
     * Manajer menolak pengajuan bernilai tinggi. Dana tidak pernah dicairkan.
     */
    public function tolakApproval(MemberTermination $terminasi, string $alasan, ?int $userId = null): MemberTermination
    {
        $userId = $userId ?? Auth::id();

        if ($terminasi->sudahDiposting()) {
            throw new RuntimeException(
                'Dokumen terminasi yang jurnalnya sudah diposting tidak dapat ditolak (PSAK 108).'
            );
        }

        if (! $terminasi->menungguApproval()) {
            throw new RuntimeException(sprintf(
                'Dokumen terminasi %s berstatus %s — tidak sedang menunggu persetujuan Manajer.',
                $terminasi->no_terminasi,
                $terminasi->status_label
            ));
        }

        // Keputusan Manajer bersifat final: dokumen yang SUDAH disetujui tidak
        // boleh ditolak oleh Manajer lain (mencegah keputusan audit ganda).
        if ($terminasi->approved_at !== null) {
            throw new RuntimeException(sprintf(
                'Dokumen terminasi %s sudah DISETUJUI Manajer pada %s sehingga tidak dapat ditolak lagi.',
                $terminasi->no_terminasi,
                $terminasi->approved_at->format('d/m/Y H:i')
            ));
        }

        return DB::transaction(function () use ($terminasi, $alasan, $userId) {
            $terminasi->fill([
                'status' => MemberTermination::STATUS_DITOLAK,
                'alasan_penolakan' => $alasan,
                'diproses_oleh' => $userId,
                'diproses_pada' => now(),
            ])->save();

            ExitApprovalLog::create([
                'member_termination_id' => $terminasi->id,
                'user_id' => $userId,
                'aksi' => ExitApprovalLog::AKSI_REJECTED,
                'catatan' => $alasan,
            ]);

            AuditLog::record(
                'EXIT_APPROVAL_DITOLAK',
                (float) $terminasi->total_net,
                null,
                sprintf(
                    'Manajer (user #%s) menolak pencairan terminasi %s. Alasan: %s',
                    $userId,
                    $terminasi->no_referensi ?? $terminasi->no_terminasi,
                    $alasan
                )
            );

            Log::info('EXIT_APPROVAL_DITOLAK', [
                'terminasi_id' => $terminasi->id,
                'no_referensi' => $terminasi->no_referensi,
                'anggota_id' => $terminasi->anggota_id,
                'total_net' => (float) $terminasi->total_net,
                'user_id' => $userId,
                'alasan' => $alasan,
            ]);

            return $terminasi->refresh();
        });
    }

    /* =====================================================================
     | 3. EKSEKUSI PENGEMBALIAN DANA + AUTO-JOURNAL PSAK 108
     |===================================================================== */

    /**
     * Eksekusi pengembalian dana exit.
     *
     * Alur (semua dalam satu transaksi DB — atomic):
     *  1. Uji kelayakan (pembiayaan lunas / NPF = 0, tanpa tunggakan apa pun).
     *  2. Hitung saldo Simpanan Pokok (CoA 3-1001) & Simpanan Wajib (CoA 3-1002).
     *  3. Catat transaksi kas keluar pada tabel `transaksi` (sumber CASH_OUT /
     *     BANK_OUT pada kolom `jenis_transaksi`).
     *  4. Posting auto-journal PSAK 108 (DEBET 3-1001 + DEBET 3-1002,
     *     KREDIT 1-1001 untuk CASH / KREDIT 1-1002 untuk TRANSFER_BANK).
     *  5. Set status anggota → TERMINATED + catatan audit exit.
     *
     * IDEMPOTENSI: bila `jurnal_id` sudah terisi, eksekusi ulang akan
     * mengembalikan dokumen yang sama tanpa memposting jurnal baru.
     */
    public function eksekusi(
        MemberTermination $terminasi,
        string $metode,
        ?int $userId = null,
        ?string $nomorRekeningTujuan = null,
        ?string $namaBankTujuan = null
    ): MemberTermination {
        $userId = $userId ?? Auth::id();
        $metode = strtoupper(trim($metode));
        $nomorRekeningTujuan = $nomorRekeningTujuan ?? $terminasi->nomor_rekening_tujuan;
        $namaBankTujuan = $namaBankTujuan ?? $terminasi->nama_bank_tujuan;

        if (! in_array($metode, MemberTermination::METODE_LIST, true)) {
            throw new RuntimeException(sprintf(
                'Metode pengembalian dana tidak dikenal: "%s". Pilihan sah: %s.',
                $metode,
                implode(' / ', MemberTermination::METODE_LIST)
            ));
        }

        // ── IDEMPOTENSI: jurnal sudah pernah diposting → jangan posting ulang ──
        if ($terminasi->sudahDiposting()) {
            return $terminasi;
        }

        if ($terminasi->status === MemberTermination::STATUS_MENUNGGU_APPROVAL
            && $terminasi->approved_at === null) {
            throw new RuntimeException(sprintf(
                'Dokumen terminasi %s masih MENUNGGU PERSETUJUAN MANAJER. '
                .'Pencairan dana diblokir sampai persetujuan diberikan.',
                $terminasi->no_terminasi
            ));
        }

        $bolehEksekusi = $terminasi->status === MemberTermination::STATUS_DIAJUKAN
            || ($terminasi->status === MemberTermination::STATUS_MENUNGGU_APPROVAL
                && $terminasi->approved_at !== null);

        if (! $bolehEksekusi) {
            throw new RuntimeException(sprintf(
                'Dokumen terminasi %s berstatus %s dan tidak dapat dieksekusi.',
                $terminasi->no_terminasi,
                $terminasi->status_label
            ));
        }

        if ($metode === MemberTermination::METODE_TRANSFER_BANK
            && blank($nomorRekeningTujuan)) {
            throw new RuntimeException(
                'Metode TRANSFER_BANK mengharuskan nomor rekening tujuan diisi sebelum eksekusi.'
            );
        }

        $anggota = $terminasi->anggota;
        if (! $anggota) {
            throw new RuntimeException('Anggota pada dokumen terminasi tidak ditemukan.');
        }

        // ── 1. GERBANG KELAYAKAN ─────────────────────────────────────────────
        $this->pastikanLayakExit($anggota);

        // ── 1b. GERBANG NPF = 0 (kepatuhan PSAK 108 — pengembalian ekuitas) ──
        // Pengembalian dana Simpanan Pokok/Wajib hanya boleh dieksekusi bila
        // SELURUH pembiayaan anggota sudah LUNAS (NPF = 0).
        $this->pastikanNpfNol($anggota);

        // ── 2. HITUNG SELURUH KOMPONEN DANA YANG DICAIRKAN ───────────────────
        $tanggal = $terminasi->tanggal_pengunduran_diri
            ? Carbon::parse($terminasi->tanggal_pengunduran_diri)->startOfDay()
            : Carbon::today();

        $rincian = $this->rincianSaldo($anggota, $tanggal);
        $nominalPokok = (float) $rincian['pokok'];
        $nominalWajib = (float) $rincian['wajib'];
        $nominalSukarela = (float) $rincian['sukarela'];
        $nominalDeposito = (float) $rincian['deposito'];
        $nominalAkrual = (float) $rincian['akrual_bagi_hasil'];
        $nominalZakat = (float) $rincian['zakat'];
        $nominalBiayaAdmin = (float) $rincian['biaya_admin'];
        $total = (float) $rincian['total_net'];

        if (Setting::bulatkanUang($rincian['bruto']) <= 0) {
            throw new RuntimeException(sprintf(
                'Tidak ada dana simpanan yang dapat dikembalikan untuk anggota %s '
                .'(Pokok Rp %s, Wajib Rp %s, Sukarela Rp %s, Deposito Rp %s).',
                $anggota->nama_lengkap ?? ('#'.$anggota->id),
                number_format($nominalPokok, 2, ',', '.'),
                number_format($nominalWajib, 2, ',', '.'),
                number_format($nominalSukarela, 2, ',', '.'),
                number_format($nominalDeposito, 2, ',', '.')
            ));
        }

        if ($total <= 0) {
            throw new RuntimeException(sprintf(
                'Total net pencairan anggota %s ≤ 0 setelah potongan zakat (Rp %s) '
                .'dan biaya administrasi (Rp %s).',
                $anggota->nama_lengkap ?? ('#'.$anggota->id),
                number_format($nominalZakat, 2, ',', '.'),
                number_format($nominalBiayaAdmin, 2, ',', '.')
            ));
        }

        $this->pastikanAkunEkuitasSiap($nominalPokok, $nominalWajib, $metode, array_filter([
            CoaRegistry::SIMPANAN_SUKARELA => $nominalSukarela > 0 ? 'Simpanan Sukarela' : null,
            CoaRegistry::SIMPANAN_BERJANGKA => $nominalDeposito > 0 ? 'Simpanan Berjangka' : null,
            CoaRegistry::BEBAN_BAGI_HASIL_DEPOSITO => $nominalAkrual > 0 ? 'Beban Bagi Hasil Deposito' : null,
            CoaRegistry::DANA_ZAKAT => $nominalZakat > 0 ? 'Dana Zakat' : null,
            CoaRegistry::PENDAPATAN_ADMIN => $nominalBiayaAdmin > 0 ? 'Pendapatan Administrasi' : null,
        ]));

        // Isolasi READ COMMITTED — cegah dirty read selama pencairan berjalan.
        // Diterapkan pada sesi SEBELUM transaksi dibuka (syarat MySQL).
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');

        try {
            return DB::transaction(function () use (
                $terminasi, $anggota, $metode, $userId, $tanggal,
                $nominalPokok, $nominalWajib, $nominalSukarela, $nominalDeposito,
                $nominalAkrual, $nominalZakat, $nominalBiayaAdmin, $total,
                $nomorRekeningTujuan, $namaBankTujuan
            ) {
                $jenisTransaksi = $metode === MemberTermination::METODE_TRANSFER_BANK
                    ? 'BANK_OUT'
                    : 'CASH_OUT';

                $keterangan = sprintf(
                    'Penutupan keanggotaan %s (%s) via %s — Pokok Rp %s + Wajib Rp %s + Sukarela Rp %s + Deposito Rp %s + Akrual Bagi Hasil Rp %s − Zakat Rp %s − Biaya Admin Rp %s = Net Rp %s',
                    $terminasi->no_referensi ?? $terminasi->no_terminasi,
                    $anggota->nama_lengkap ?? ('#'.$anggota->id),
                    $metode,
                    number_format($nominalPokok, 2, ',', '.'),
                    number_format($nominalWajib, 2, ',', '.'),
                    number_format($nominalSukarela, 2, ',', '.'),
                    number_format($nominalDeposito, 2, ',', '.'),
                    number_format($nominalAkrual, 2, ',', '.'),
                    number_format($nominalZakat, 2, ',', '.'),
                    number_format($nominalBiayaAdmin, 2, ',', '.'),
                    number_format($total, 2, ',', '.')
                );

                /* ── 3. CATAT TRANSAKSI KAS KELUAR ──────────────────────────────
                 | Ledger tunggal koperasi: tabel `transaksi`. SATU baris per
                 | komponen dana yang dilepas — konsisten dengan pola bundling
                 | setoran awal — sehingga nominal MASING-MASING AKUN tercatat
                 | eksplisit bersama ID anggota & timestamp transaksi.
                 | Kolom `jenis_transaksi` menyimpan label arus kas (CASH_OUT /
                 | BANK_OUT) agar rekonsiliasi kas & bank dapat ditelusuri.
                 | Seluruh baris dibedakan lewat `referensi` = no_terminasi. */
                $sumberTransaksi = $metode === MemberTermination::METODE_TRANSFER_BANK
                    ? 'Transfer'
                    : 'Teller';

                $komponen = [];

                foreach ([
                    'Simpanan Pokok' => $nominalPokok,
                    'Simpanan Wajib' => $nominalWajib,
                    'Simpanan Sukarela' => $nominalSukarela,
                    'Simpanan Berjangka' => $nominalDeposito,
                    'Akrual Bagi Hasil' => $nominalAkrual,
                ] as $label => $nominal) {
                    if (Setting::bulatkanUang($nominal) > 0) {
                        $komponen[] = [
                            'label' => $label,
                            'nominal' => Setting::bulatkanUang($nominal),
                        ];
                    }
                }

                $transaksiUtama = null;

                foreach ($komponen as $item) {
                    $transaksi = Transaksi::create([
                        'no_transaksi' => $this->generateNoTransaksi(),
                        'waktu_transaksi' => now(),
                        'anggota_id' => $anggota->id,
                        'jenis_transaksi' => $jenisTransaksi,
                        'posisi' => 'Kredit',
                        'jumlah' => $item['nominal'],
                        'nominal' => $item['nominal'],
                        'sumber_transaksi' => $sumberTransaksi,
                        'status_transaksi' => 'Sukses',
                        'status' => 'success',
                        'catatan' => $keterangan.' ['.$item['label'].']'
                            .$this->keteranganTujuan($metode, $nomorRekeningTujuan, $namaBankTujuan),
                        'referensi' => $terminasi->no_terminasi,
                        'user_id' => $userId,
                    ]);

                    $transaksiUtama ??= $transaksi;
                }

                /* ── 4. AUTO-JOURNAL SYARIAH ───────────────────────────────────
                 | DEBET  : 3-1001 Pokok, 3-1002 Wajib, 2-1001 Sukarela,
                 |          2-1003 Berjangka, 5-3001 Akrual Bagi Hasil
                 | KREDIT : 2-3002 Dana Zakat, 4-2001 Pendapatan Admin,
                 |          1-1001 Kas Teller / 1-1002 Kas di Bank (net)
                 | Hanya diposting SEKALI (idempotensi via `jurnal_id`). */
                $jurnal = $this->journaling->jurnalPengembalianDanaExit(
                    $nominalPokok,
                    $nominalWajib,
                    $keterangan,
                    $metode,
                    $userId,
                    $transaksiUtama?->id,
                    null,
                    $anggota->id,
                    $tanggal,
                    null,
                    $nominalSukarela,
                    $nominalDeposito,
                    $nominalAkrual,
                    $nominalZakat,
                    $nominalBiayaAdmin,
                );

                if (! $jurnal) {
                    throw new RuntimeException(
                        'Auto-journal penutupan keanggotaan gagal diposting. Pastikan CoA '
                        .CoaRegistry::SIMPANAN_POKOK.' (Simpanan Pokok), '
                        .CoaRegistry::SIMPANAN_WAJIB_MODAL.' (Simpanan Wajib — Modal Anggota), dan '
                        .($metode === MemberTermination::METODE_TRANSFER_BANK
                            ? CoaRegistry::BANK_KOPERASI.' (Kas di Bank)'
                            : CoaRegistry::KAS_TELLER.' (Kas Teller)')
                        .' tersedia, aktif, dan periode akuntansi terbuka.'
                    );
                }

                /* ── 5. TUTUP SELURUH REKENING ANGGOTA ────────────────────────
                 | Status → Ditutup, saldo dinolkan, jejak closed_at/closed_by
                 | untuk audit penutupan CIF.
                 |
                 | E-2 — cakupan BUKAN hanya 'Aktif'. Rekening 'Jatuh Tempo' dan
                 | 'Dibekukan' juga masih menyimpan kewajiban kepada anggota; bila
                 | dibiarkan, saldonya tertinggal di subledger setelah CIF menjadi
                 | TERMINATED dan ikut terhitung pada total simpanan/dashboard.
                 | 'Ditutup' dilewati agar closed_at/closed_by penutupan lama tidak
                 | tertimpa. */
                $rekeningAnggota = Rekening::query()
                    ->where('anggota_id', $anggota->id)
                    ->whereIn('status_rekening', ['Aktif', 'Jatuh Tempo', 'Dibekukan'])
                    ->lockForUpdate()
                    ->get();

                foreach ($rekeningAnggota as $rekening) {
                    $rekening->forceFill([
                        'saldo' => 0,
                        'status_rekening' => 'Ditutup',
                        'closed_at' => now(),
                        'closed_by' => $userId,
                    ])->save();
                }

                // Deposito berjangka ikut ditutup (tidak ada ARO setelah exit).
                Deposito::query()
                    ->where('member_id', $anggota->id)
                    ->whereIn('status', [Deposito::STATUS_ACTIVE, Deposito::STATUS_MATURED])
                    ->update(['status' => Deposito::STATUS_CLOSED]);

                /* ── 6. UPDATE CIF ANGGOTA → RESIGNED ─────────────────────────
                 | exit_date / exit_reason / exit_approved_by melengkapi jejak
                 | pengunduran diri pada data keanggotaan (CIF). */
                $anggota->forceFill([
                    'status_anggota' => Anggota::STATUS_TERMINATED,
                    'tanggal_keluar' => $terminasi->tanggal_pengunduran_diri?->toDateString()
                        ?? $tanggal->toDateString(),
                    'tanggal_terminasi' => $tanggal->toDateString(),
                    'total_dana_dikembalikan' => $total,
                    'terminasi_diproses_oleh' => $userId,
                    'exit_date' => $tanggal->toDateString(),
                    'exit_reason' => $terminasi->exit_reason ?? $terminasi->catatan,
                    'exit_approved_by' => $terminasi->disetujui_oleh ?: $userId,
                ])->save();

                $terminasi->fill([
                    'status' => MemberTermination::STATUS_DISETUJUI,
                    'metode' => $metode,
                    'nomor_rekening_tujuan' => $nomorRekeningTujuan,
                    'nama_bank_tujuan' => $namaBankTujuan,
                    'nominal_simpanan_pokok' => $nominalPokok,
                    'nominal_simpanan_wajib' => $nominalWajib,
                    'nominal_sukarela' => $nominalSukarela,
                    'nominal_deposito' => $nominalDeposito,
                    'nominal_akrual_bagi_hasil' => $nominalAkrual,
                    'nominal_zakat' => $nominalZakat,
                    'nominal_biaya_admin' => $nominalBiayaAdmin,
                    'total_net' => $total,
                    'total_dikembalikan' => $total,
                    'transaksi_id' => $transaksiUtama?->id,
                    'jurnal_id' => $jurnal->id,
                    'no_jurnal' => $jurnal->no_jurnal ?? null,
                    'diproses_oleh' => $userId,
                    'disetujui_oleh' => $terminasi->disetujui_oleh ?: $userId,
                    'diproses_pada' => now(),
                ])->save();

                // Catat keputusan Manajer (bila pengajuan melewati approval).
                if ($terminasi->approved_at !== null) {
                    ExitApprovalLog::create([
                        'member_termination_id' => $terminasi->id,
                        'user_id' => $terminasi->disetujui_oleh ?: $userId,
                        'aksi' => ExitApprovalLog::AKSI_APPROVED,
                        'catatan' => sprintf(
                            'Disetujui pada %s; pencairan dieksekusi oleh user #%s.',
                            $terminasi->approved_at?->format('d/m/Y H:i') ?? '-',
                            $userId
                        ),
                    ]);
                }

                AuditLog::record(
                    'EXIT_KEANGGOTAAN',
                    $total,
                    $anggota->rekening()->first(),
                    sprintf(
                        'Penutupan keanggotaan %s — net Rp %s (Pokok Rp %s, Wajib Rp %s, '
                        .'Sukarela Rp %s, Deposito Rp %s, Akrual Rp %s, Zakat Rp %s, '
                        .'Biaya Admin Rp %s) via %s. Tanggal pengunduran diri %s, diproses user #%s.',
                        $anggota->nama_lengkap ?? ('#'.$anggota->id),
                        number_format($total, 2, ',', '.'),
                        number_format($nominalPokok, 2, ',', '.'),
                        number_format($nominalWajib, 2, ',', '.'),
                        number_format($nominalSukarela, 2, ',', '.'),
                        number_format($nominalDeposito, 2, ',', '.'),
                        number_format($nominalAkrual, 2, ',', '.'),
                        number_format($nominalZakat, 2, ',', '.'),
                        number_format($nominalBiayaAdmin, 2, ',', '.'),
                        $metode,
                        ($terminasi->tanggal_pengunduran_diri?->toDateString() ?? $tanggal->toDateString()),
                        $userId
                    )
                );

                Log::info('EXIT_KEANGGOTAAN_SUKSES', [
                    'terminasi_id' => $terminasi->id,
                    'no_referensi' => $terminasi->no_referensi,
                    'anggota_id' => $anggota->id,
                    'user_id' => $userId,
                    'metode' => $metode,
                    'total_net' => $total,
                    'zakat' => $nominalZakat,
                    'biaya_admin' => $nominalBiayaAdmin,
                    'status' => 'success',
                ]);

                return $terminasi->refresh();
            });
        } catch (\Throwable $e) {
            // Log detail SEBELUM rollback agar jejak kegagalan tidak hilang.
            Log::error('EXIT_KEANGGOTAAN_GAGAL', [
                'terminasi_id' => $terminasi->id,
                'no_referensi' => $terminasi->no_referensi,
                'anggota_id' => $anggota->id,
                'user_id' => $userId,
                'metode' => $metode,
                'total_net' => $total ?? null,
                'status' => 'failed',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /* =====================================================================
     | 4. PERHITUNGAN SALDO EKUITAS ANGGOTA
     |===================================================================== */

    /**
     * Saldo Simpanan Pokok (CoA 3-1001) milik anggota.
     *
     * Dihitung dari mutasi baris jurnal yang DIKUNCI ke akun kanonik Pokok —
     * TERMASUK jurnal dengan akun bankir legacy `1-2010`/`3-1001` agar saldo
     * historis tidak hilang. DIFILTER per `anggota_id` sehingga mencerminkan
     * penyertaan anggota tersebut.
     */
    public function saldoSimpananPokok(Anggota $anggota): float
    {
        $row = DB::table('jurnal_detail as d')
            ->join('jurnal as j', 'j.id', '=', 'd.jurnal_id')
            ->join('coas as c', 'c.id', '=', 'd.coa_id')
            ->whereNull('j.deleted_at')
            ->where('j.status', 'Posted')
            ->where('c.kode_akun', CoaRegistry::SIMPANAN_POKOK)
            ->where('d.anggota_id', $anggota->id)
            ->selectRaw('COALESCE(SUM(d.kredit), 0) - COALESCE(SUM(d.debet), 0) AS saldo')
            ->value('saldo');

        return Setting::pembulatanNominal((float) ($row ?? 0));
    }

    /**
     * Saldo Simpanan Wajib (CoA 3-1002) milik anggota — total akumulasi
     * setoran yang tercatat di buku besar. Didelegasikan ke
     * {@see MandatorySavingsService::saldoSimpananWajib()} agar definisi saldo
     * hanya ada SATU di seluruh aplikasi (mencegah selisih perhitungan).
     *
     * Dipakai modul `member_terminations` untuk menampilkan pratinjau nominal
     * pengembalian dana sebelum eksekusi.
     */
    public function saldoSimpananWajib(Anggota $anggota): float
    {
        return $this->simpananWajib->saldoSimpananWajib($anggota);
    }

    /* =====================================================================
     | 4B. RINCIAN SALDO PENGEMBALIAN (EXIT & CLOSURE)
     |===================================================================== */

    /**
     * Rincian LENGKAP dana anggota yang akan dicairkan pada tanggal pengunduran
     * diri — dipakai UI (pratinjau) maupun eksekusi (nilai final).
     *
     * Sumber saldo mengikuti definisi kanonik aplikasi (tidak menduplikasi):
     *  - Pokok      : mutasi jurnal CoA 3-1001 (`saldoSimpananPokok()`).
     *  - Wajib      : mutasi jurnal CoA 3-1002 (`MandatorySavingsService`).
     *  - Sukarela   : kolom `rekening.saldo` seluruh rekening Sukarela/Wadiah
     *                 berstatus Aktif.
     *  - Deposito   : `principal_amount` deposito berstatus ACTIVE (deposito
     *                 MATURED sudah dikembalikan ke rekening Sukarela sehingga
     *                 tidak boleh dihitung dua kali).
     *  - Akrual     : bagi hasil proporsional harian deposito
     *                 (pokok × rate/tahun × hari/365 × nisbah anggota).
     *  - Zakat      : `Setting::hitungZakat()` (PSAK 109 / Fatwa DSN-MUI 08).
     *  - Biaya admin: parameter `closure_admin_fee`.
     *
     * @return array<string, mixed>
     */
    public function rincianSaldo(Anggota $anggota, ?Carbon $tanggal = null): array
    {
        $tanggal = ($tanggal ?? Carbon::today())->copy()->startOfDay();

        // Parameter biaya administrasi WAJIB terdaftar — jangan menebak nilai.
        if (! Setting::parameterTersedia('closure_admin_fee')) {
            throw new RuntimeException(
                'Parameter sistem `closure_admin_fee` (biaya administrasi penutupan CIF) '
                .'tidak ditemukan. Hubungi administrator sebelum memproses pengunduran diri.'
            );
        }

        $pokok = Setting::pembulatanNominal($this->saldoSimpananPokok($anggota));
        $wajib = Setting::pembulatanNominal($this->simpananWajib->saldoSimpananWajib($anggota));

        [$sukarela, $sukarelaDetail] = $this->rincianSukarela($anggota);
        [$deposito, $akrual, $depositoDetail] = $this->rincianDeposito($anggota, $tanggal);

        $subtotal = Setting::bulatkanUang($pokok + $wajib + $sukarela + $deposito);
        $bruto = Setting::bulatkanUang($subtotal + $akrual);

        $zakatDetail = Setting::hitungZakat(
            $bruto,
            $anggota->created_at?->toDateString()
        );
        $zakat = Setting::bulatkanUang((float) ($zakatDetail['zakat'] ?? 0));
        $biayaAdmin = Setting::bulatkanUang(Setting::closureAdminFee());
        $totalNet = Setting::bulatkanUang($bruto - $zakat - $biayaAdmin);

        return [
            'tanggal' => $tanggal->toDateString(),
            'pokok' => $pokok,
            'wajib' => $wajib,
            'sukarela' => $sukarela,
            'deposito' => $deposito,
            'akrual_bagi_hasil' => $akrual,
            'subtotal_simpanan' => $subtotal,
            'bruto' => $bruto,
            'zakat' => $zakat,
            'biaya_admin' => $biayaAdmin,
            'total_net' => max(0.0, $totalNet),
            'sukarela_detail' => $sukarelaDetail,
            'deposito_detail' => $depositoDetail,
            'zakat_detail' => $zakatDetail,
        ];
    }

    /**
     * Total saldo rekening Sukarela/Wadiah (seluruh rekening aktif anggota).
     *
     * @return array{0: float, 1: list<array<string, mixed>>}
     */
    private function rincianSukarela(Anggota $anggota): array
    {
        $daftar = Rekening::query()
            ->where('anggota_id', $anggota->id)
            ->whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_SUKARELA))
            ->aktif()
            ->orderBy('id')
            ->get(['id', 'no_rekening', 'jenis_rekening', 'saldo']);

        $total = 0.0;
        $detail = [];

        foreach ($daftar as $rekening) {
            $saldo = Setting::pembulatanNominal((float) $rekening->saldo);

            if ($saldo <= 0) {
                continue;
            }

            $total += $saldo;
            $detail[] = [
                'rekening_id' => (int) $rekening->id,
                'no_rekening' => $rekening->no_rekening,
                'jenis' => $rekening->jenis_rekening,
                'saldo' => $saldo,
            ];
        }

        return [Setting::bulatkanUang($total), $detail];
    }

    /**
     * Pokok deposito berjangka + akrual bagi hasil proporsional harian.
     *
     * Akrual = pokok × rate/tahun × (hari berjalan ÷ 365) × nisbah anggota ÷ 100.
     * Hari berjalan dihitung dari `start_date` sampai tanggal pengunduran diri,
     * dibatasi maksimal `maturity_date` (setelah jatuh tempo tidak ada akrual baru).
     *
     * @return array{0: float, 1: float, 2: list<array<string, mixed>>}
     */
    private function rincianDeposito(Anggota $anggota, Carbon $tanggal): array
    {
        $rate = Setting::depositoRatePa();

        $daftar = Deposito::query()
            ->milik($anggota->id)
            ->where('status', Deposito::STATUS_ACTIVE)
            ->orderBy('id')
            ->get();

        $totalPokok = 0.0;
        $totalAkrual = 0.0;
        $detail = [];
        $rekeningTerbilyet = [];

        foreach ($daftar as $deposito) {
            $pokok = Setting::pembulatanNominal((float) $deposito->principal_amount);
            $mulai = $deposito->start_date ? Carbon::parse($deposito->start_date)->startOfDay() : null;
            $akhir = $deposito->maturity_date ? Carbon::parse($deposito->maturity_date)->startOfDay() : null;

            $batas = ($akhir !== null && $akhir->lt($tanggal)) ? $akhir : $tanggal;
            $hari = ($mulai !== null && $batas->gte($mulai)) ? (int) $mulai->diffInDays($batas) : 0;

            $nisbah = (float) ($deposito->nisbah_anggota ?? 0);
            $akrual = Setting::bulatkanUang($pokok * $rate * ($hari / 365) * ($nisbah / 100));

            $totalPokok += $pokok;
            $totalAkrual += $akrual;

            if ($deposito->deposito_rekening_id) {
                $rekeningTerbilyet[] = (int) $deposito->deposito_rekening_id;
            }

            $detail[] = [
                'deposito_id' => (int) $deposito->id,
                'nomor' => $deposito->deposito_number,
                'pokok' => $pokok,
                'start_date' => $deposito->start_date?->toDateString(),
                'maturity_date' => $deposito->maturity_date?->toDateString(),
                'jatuh_tempo' => $akhir !== null && $akhir->lte($tanggal),
                'hari_akrual' => $hari,
                'nisbah_anggota' => $nisbah,
                'akrual' => $akrual,
            ];
        }

        /* E-1 — REKENING BERJANGKA TANPA BILYET.
         | Saldo Simpanan Berjangka bisa ada di subledger `rekening` tanpa baris
         | bilyet `depositos` (input rekening langsung, migrasi data, atau bilyet
         | terhapus). Tanpa cabang ini, saldo tersebut tidak pernah masuk rincian,
         | padahal eksekusi exit tetap menol-kan rekeningnya → dana anggota hangus
         | dan GL 2-1003 tidak ter-debit.
         |
         | Akrual bagi hasil TIDAK dihitung: tanpa bilyet tidak ada `start_date`
         | maupun `nisbah_anggota` yang sah, sehingga menebak rate akan melanggar
         | prinsip kehati-hatian. Pokok saja yang dikembalikan. */
        $rekeningYatim = Rekening::query()
            ->where('anggota_id', $anggota->id)
            ->whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_BERJANGKA))
            ->where('saldo', '>', 0)
            ->when($rekeningTerbilyet !== [], fn ($q) => $q->whereNotIn('id', $rekeningTerbilyet))
            ->orderBy('id')
            ->get(['id', 'no_rekening', 'jenis_rekening', 'saldo', 'tanggal_buka']);

        foreach ($rekeningYatim as $rekening) {
            $pokok = Setting::pembulatanNominal((float) $rekening->saldo);

            if ($pokok <= 0) {
                continue;
            }

            $totalPokok += $pokok;

            $detail[] = [
                'deposito_id' => null,
                'nomor' => $rekening->no_rekening,
                'pokok' => $pokok,
                'start_date' => $rekening->tanggal_buka?->toDateString(),
                'maturity_date' => null,
                'jatuh_tempo' => false,
                'hari_akrual' => 0,
                'nisbah_anggota' => 0.0,
                'akrual' => 0.0,
                'sumber' => 'rekening',
            ];
        }

        return [
            Setting::bulatkanUang($totalPokok),
            Setting::bulatkanUang($totalAkrual),
            $detail,
        ];
    }

    /**
     * Apakah total net pencairan wajib persetujuan Manajer.
     *
     * Limit diambil dinamis dari `high_value_withdrawal_limit`. Nilai 0
     * (default) berarti approval workflow NONAKTIF (P4 Default Aman).
     */
    public function butuhApproval(float $totalNet): bool
    {
        $limit = Setting::highValueWithdrawalLimit();

        return $limit > 0 && Setting::bulatkanUang($totalNet) > $limit;
    }

    /**
     * Kirim permintaan persetujuan ke SELURUH Manajer (in-app + email).
     *
     * Kegagalan notifikasi TIDAK membatalkan pengajuan — dokumen tetap
     * tersimpan sebagai MENUNGGU_APPROVAL dan dapat diproses manual. Kegagalan
     * dicatat ke log agar tim operasional dapat menindaklanjuti.
     */
    private function kirimPermintaanApproval(MemberTermination $terminasi): void
    {
        try {
            // Penerima approval mengikuti SSOT RBAC (config/izin.php), bukan
            // daftar peran yang di-hardcode, agar perubahan wewenang cukup
            // dilakukan di satu tempat.
            $peranPenyetuju = Izin::peran('terminasi.approval');

            if ($peranPenyetuju === []) {
                Log::warning('EXIT_APPROVAL_TANPA_PERAN', [
                    'terminasi_id' => $terminasi->id,
                    'no_referensi' => $terminasi->no_referensi,
                ]);
            }

            $manajer = User::query()
                ->whereIn('role', $peranPenyetuju)
                ->whereNull('deleted_at')
                ->get();

            if ($manajer->isEmpty()) {
                Log::warning('EXIT_APPROVAL_TANPA_MANAJER', [
                    'terminasi_id' => $terminasi->id,
                    'no_referensi' => $terminasi->no_referensi,
                ]);

                return;
            }

            Notification::send(
                $manajer,
                PengunduranDiriApprovalNotification::dariTerminasi($terminasi)
            );
        } catch (\Throwable $e) {
            Log::error('EXIT_APPROVAL_NOTIFIKASI_GAGAL', [
                'terminasi_id' => $terminasi->id,
                'no_referensi' => $terminasi->no_referensi,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /* =====================================================================
     | 5. UTILITAS INTERNAL
     |===================================================================== */

    /**
     * Pastikan CoA ekuitas, komponen dana tambahan, & kas/bank tujuan tersedia.
     *
     * @param  array<string, string>  $tambahan  Peta kode → label akun tambahan
     *                                           (Sukarela, Berjangka, Beban Bagi Hasil, Dana Zakat, Pendapatan Admin).
     */
    private function pastikanAkunEkuitasSiap(
        float $nominalPokok,
        float $nominalWajib,
        string $metode,
        array $tambahan = []
    ): void {
        $wajibAda = [];

        if (Setting::bulatkanUang($nominalPokok) > 0) {
            $wajibAda[CoaRegistry::SIMPANAN_POKOK] = 'Simpanan Pokok';
        }

        if (Setting::bulatkanUang($nominalWajib) > 0) {
            $wajibAda[CoaRegistry::SIMPANAN_WAJIB_MODAL] = 'Simpanan Wajib (Modal Anggota)';
        }

        foreach ($tambahan as $kode => $label) {
            if ($label !== null && $label !== '') {
                $wajibAda[$kode] = $label;
            }
        }

        $wajibAda[$metode === MemberTermination::METODE_TRANSFER_BANK
            ? CoaRegistry::BANK_KOPERASI
            : CoaRegistry::KAS_TELLER] = $metode === MemberTermination::METODE_TRANSFER_BANK
                ? 'Kas di Bank'
                : 'Kas Teller';

        foreach (array_keys($wajibAda) as $kode) {
            $coa = Coa::query()->where('kode_akun', $kode)->first();

            if (! $coa) {
                throw new RuntimeException(sprintf(
                    'CoA %s (%s) tidak ditemukan — pengembalian dana exit tidak dapat diproses.',
                    $kode,
                    $wajibAda[$kode]
                ));
            }
        }

        // Akun bank hanya relevan untuk TRANSFER_BANK (metode CASH memakai teller).
        if ($metode === MemberTermination::METODE_TRANSFER_BANK) {
            Log::info(
                'ExitKeanggotaanService: pengembalian via TRANSFER_BANK — kredit ke CoA '
                .CoaRegistry::BANK_KOPERASI.' (Kas di Bank).'
            );
        }
    }

    /**
     * Keterangan tujuan transfer bank (nomor rekening + nama bank) untuk
     * metode TRANSFER_BANK; string kosong untuk metode CASH.
     */
    private function keteranganTujuan(string $metode, ?string $nomorRekening, ?string $namaBank): string
    {
        if ($metode !== MemberTermination::METODE_TRANSFER_BANK) {
            return '';
        }

        $tujuan = trim((string) $nomorRekening);
        $bank = trim((string) $namaBank);

        if ($tujuan === '' && $bank === '') {
            return '';
        }

        return sprintf(' ke rekening %s%s', $tujuan !== '' ? $tujuan : '-', $bank !== '' ? " ({$bank})" : '');
    }

    /** Nomor transaksi harian: TRX-YYYYMMDD-XXXXXX (seragam dengan modul lain). */
    private function generateNoTransaksi(): string
    {
        // K-03: sumber urutan tunggal ber-kunci baris (anti race condition).
        // Wajib dipanggil di dalam DB::transaction() pemanggil.
        // Awalan dapat diubah Pengurus (Setting `prefix_nomor_transaksi`).
        return NomorDokumen::buat('transaksi');
    }

    /** Catat upaya exit yang diblokir karena syarat belum terpenuhi. */
    private function catatBlokirExit(Anggota $anggota, array $kendala): void
    {
        Log::warning('EXIT_KEANGGOTAAN_DIBLOKIR', [
            'anggota_id' => $anggota->id,
            'no_anggota' => $anggota->no_anggota ?? null,
            'user_id' => Auth::id(),
            'kendala' => $kendala,
        ]);

        AuditLog::record(
            'EXIT_KEANGGOTAAN_DIBLOKIR',
            0,
            $anggota->rekening()->first(),
            'Exit keanggotaan dibatalkan — '.implode(' | ', $kendala)
        );
    }

    /* =====================================================================
     | 6. PEMBATALAN / PENOLAKAN
     |===================================================================== */

    public function tolak(MemberTermination $terminasi, string $alasan, ?int $userId = null): MemberTermination
    {
        $userId = $userId ?? Auth::id();
        $this->pastikanMasihDapatDiputuskan($terminasi, 'ditolak');

        return DB::transaction(function () use ($terminasi, $alasan, $userId) {
            $terminasi->fill([
                'status' => MemberTermination::STATUS_DITOLAK,
                'alasan_penolakan' => $alasan,
                'diproses_oleh' => $userId,
                'diproses_pada' => now(),
            ])->save();

            AuditLog::record(
                'EXIT_KEANGGOTAAN_DITOLAK',
                (float) $terminasi->total_net,
                $terminasi->anggota?->rekening()->first(),
                sprintf(
                    'Pengajuan exit %s ditolak oleh user #%s. Alasan: %s',
                    $terminasi->no_referensi ?? $terminasi->no_terminasi,
                    $userId,
                    $alasan
                )
            );

            Log::info('EXIT_KEANGGOTAAN_DITOLAK', [
                'terminasi_id' => $terminasi->id,
                'no_referensi' => $terminasi->no_referensi,
                'anggota_id' => $terminasi->anggota_id,
                'user_id' => $userId,
                'alasan' => $alasan,
            ]);

            return $terminasi->refresh();
        });
    }

    public function batalkan(MemberTermination $terminasi, ?string $alasan = null, ?int $userId = null): MemberTermination
    {
        $userId = $userId ?? Auth::id();
        $this->pastikanMasihDapatDiputuskan($terminasi, 'dibatalkan');

        return DB::transaction(function () use ($terminasi, $alasan, $userId) {
            $terminasi->fill([
                'status' => MemberTermination::STATUS_DIBATALKAN,
                'alasan_penolakan' => $alasan,
                'diproses_oleh' => $userId,
                'diproses_pada' => now(),
            ])->save();

            AuditLog::record(
                'EXIT_KEANGGOTAAN_DIBATALKAN',
                (float) $terminasi->total_net,
                $terminasi->anggota?->rekening()->first(),
                sprintf(
                    'Pengajuan exit %s dibatalkan oleh user #%s.%s',
                    $terminasi->no_referensi ?? $terminasi->no_terminasi,
                    $userId,
                    filled($alasan) ? ' Alasan: '.$alasan : ''
                )
            );

            Log::info('EXIT_KEANGGOTAAN_DIBATALKAN', [
                'terminasi_id' => $terminasi->id,
                'no_referensi' => $terminasi->no_referensi,
                'anggota_id' => $terminasi->anggota_id,
                'user_id' => $userId,
                'alasan' => $alasan,
            ]);

            return $terminasi->refresh();
        });
    }

    /**
     * Penolakan/pembatalan hanya sah untuk dokumen yang belum final.
     * Status DITOLAK/DIBATALKAN bersifat terminal agar jejak keputusan tidak
     * bolak-balik; dokumen yang sudah disetujui Manajer juga tidak boleh
     * dibatalkan oleh pihak lain (PSAK 108 — integritas keputusan).
     */
    private function pastikanMasihDapatDiputuskan(MemberTermination $terminasi, string $aksi): void
    {
        if ($terminasi->sudahDiposting()) {
            throw new RuntimeException(sprintf(
                'Dokumen terminasi yang jurnalnya sudah diposting tidak dapat %s (PSAK 108).',
                $aksi
            ));
        }

        if (in_array($terminasi->status, [
            MemberTermination::STATUS_DITOLAK,
            MemberTermination::STATUS_DIBATALKAN,
        ], true)) {
            throw new RuntimeException(sprintf(
                'Dokumen terminasi %s sudah berstatus %s dan tidak dapat %s lagi.',
                $terminasi->no_terminasi,
                $terminasi->status_label,
                $aksi
            ));
        }

        if ($terminasi->status === MemberTermination::STATUS_DISETUJUI) {
            throw new RuntimeException(sprintf(
                'Dokumen terminasi %s sudah DISETUJUI dan tidak dapat %s.',
                $terminasi->no_terminasi,
                $aksi
            ));
        }

        if ($terminasi->approved_at !== null) {
            throw new RuntimeException(sprintf(
                'Dokumen terminasi %s sudah disetujui Manajer pada %s sehingga tidak dapat %s.',
                $terminasi->no_terminasi,
                $terminasi->approved_at->format('d/m/Y H:i'),
                $aksi
            ));
        }
    }
}
