<?php

namespace App\Services;

use App\Models\Angsuran;
use App\Models\AuditLog;
use App\Models\Pembiayaan;
use App\Models\RestrukturisasiRequest;
use App\Support\PemisahanTugas;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service terpusat Restrukturisasi Pembiayaan Syariah (3R).
 *
 * STRICT SAFEGUARDS — Fatwa DSN-MUI No. 49/DSN-MUI/IV/2005:
 *   1. Perpanjangan tenor (Rescheduling) TIDAK BOLEH menambah nominal sisa
 *      margin / sisa piutang yang sudah disepakati pada akad awal (dilarang riba).
 *      Seluruh jadwal baru dibentuk HANYA dari sisa piutang (sisa pokok + sisa margin)
 *      yang tersisa saat itu, dibagi rata ke tenor baru.
 *   2. Dilarang menambah harga jual / margin baru pada akad Murabahah yang berjalan.
 *      Karena itu service ini tidak pernah menghitung margin dari pokok × nisbah baru,
 *      melainkan memakai sisa margin yang sudah terikat di jadwal angsuran lama.
 *   3. Seluruh kalkulasi ulang & pembaruan jadwal angsuran dibungkus DB::transaction()
 *      (approve() dan reject()).
 */
class RestrukturisasiService
{
    /**
     * Ambang keterlambatan (hari) agar pembiayaan dapat diusulkan restrukturisasi.
     *
     * Konstanta dipertahankan sebagai nilai rujukan/dokumentasi; nilai EFEKTIF
     * selalu dibaca dari parameter kebijakan kolektibilitas (Setting) lewat
     * batasHariTerlambat() agar tidak ada duplikasi ambang 30 hari.
     */
    public const BATAS_HARI_TERLAMBAT = 30;

    /** Ambang hari terlambat efektif (mengikuti parameter kebijakan kolektibilitas). */
    public static function batasHariTerlambat(): int
    {
        return Setting::ambangKolektibilitas()['kol2'];
    }

    /**
     * Pemetaan kolektibilitas berdasarkan hari tunggakan (selaras ManajerController::npf()).
     *
     * Ambang hari TIDAK lagi di-hardcode di sini — seluruhnya dibaca dari
     * parameter kebijakan koperasi (Setting::kolektibilitasDariHari()).
     */
    public static function kolektibilitas(int $hariTunggakan): array
    {
        $peta = [
            'kol1' => [
                'label' => 'Lancar (Kol-1)',
                'kualitas' => 'Lancar',
                'style' => 'background:#dcfce7;color:#15803d;',
            ],
            'kol2' => [
                'label' => 'Dalam Perhatian Khusus (Kol-2)',
                'kualitas' => 'Lancar',
                'style' => 'background:#fef9c3;color:#ca8a04;',
            ],
            'kol3' => [
                'label' => 'Kurang Lancar (Kol-3)',
                'kualitas' => 'Kurang Lancar',
                'style' => 'background:#ffedd5;color:#c2410c;',
            ],
            'kol4' => [
                'label' => 'Diragukan (Kol-4)',
                'kualitas' => 'Diragukan',
                'style' => 'background:#fee2e2;color:#dc2626;',
            ],
            'kol5' => [
                'label' => 'Macet (Kol-5)',
                'kualitas' => 'Macet',
                'style' => 'background:#f3e8ff;color:#7e22ce;',
            ],
        ];

        return $peta[Setting::kolektibilitasDariHari($hariTunggakan)];
    }

    /**
     * Hari keterlambatan tertinggi (real-time) dari angsuran yang belum dibayar.
     */
    public static function hariTunggakan(Pembiayaan $pembiayaan): int
    {
        $jatuhTempoTerlama = Angsuran::where('pembiayaan_id', $pembiayaan->id)
            ->where('status_angsuran', '!=', 'Sudah Dibayar')
            ->whereNotNull('tanggal_jatuh_tempo')
            ->orderBy('tanggal_jatuh_tempo')
            ->value('tanggal_jatuh_tempo');

        if (! $jatuhTempoTerlama) {
            return 0;
        }

        $hari = Carbon::parse($jatuhTempoTerlama)
            ->startOfDay()
            ->diffInDays(Carbon::today(), false);

        return max(0, (int) round($hari));
    }

    /**
     * Ringkasan piutang berjalan — dasar perhitungan jadwal baru.
     *
     * KONVENSI KOLOM `pembiayaan.sisa_pembiayaan` (WAJIB dipahami lintas modul):
     * kolom ini menyimpan SELURUH KEWAJIBAN yang belum dibayar = sisa POKOK
     * + sisa MARGIN yang belum menjadi hak. Diisi saat pencairan sebesar
     * `total_pembiayaan` (lihat DisbursementService) dan berkurang hanya sebesar
     * pokok terbayar pada setiap angsuran (lihat AngsuranTransactionService).
     *
     * Sisa POKOK murni dihitung dari jadwal angsuran: Σ pokok_angsuran yang
     * belum dibayar + Σ sisa_pokok angsuran yang baru dibayar sebagian —
     * sama dengan basis CKPN (CkpnService::dasarPiutang()) agar konsisten.
     * Sisa margin dihitung dari margin_ujroh jadwal yang belum diakui, dikurangi
     * margin yang sudah diakui pada angsuran berstatus 'Dibayar Sebagian'.
     *
     * @return array{sisaPokok: float, sisaMargin: float, sisaPiutang: float, angsuranLama: float, tenorSisa: int}
     */
    public static function ringkasan(Pembiayaan $pembiayaan): array
    {
        $sisaPokok = app(CkpnService::class)->dasarPiutang($pembiayaan);

        // Sisa margin = Σ margin jadwal yang BELUM diakui sebagai pendapatan.
        //   - Baris belum dibayar (Belum/Jatuh Tempo/Terlambat): margin masih utuh.
        //   - Baris 'Dibayar Sebagian': sebagian margin sudah diakui saat pembayaran.
        //     Urutan alokasi WAJIB sama dengan AngsuranTransactionService
        //     (POKOK → MARGIN, lihat alokasiPembayaran()); perhitungannya
        //     dipusatkan di marginBelumTerakui() agar tidak menyimpang.
        //
        // PENTING: tabel `angsuran` TIDAK menyimpan kolom sisa margin; nilai ini harus
        // DIHITUNG dari kolom tersimpan (margin_ujroh, pokok_angsuran, sisa_pokok,
        // total_bayar, denda). Menjumlahkan `margin_ujroh` mentah untuk baris
        // 'Dibayar Sebagian' akan OVER-COUNT margin, sehingga jadwal hasil
        // restrukturisasi membawa margin melebihi sisa kewajiban yang sah
        // (total piutang bertambah — melanggar Fatwa DSN-MUI No. 49/DSN-MUI/IV/2005).
        $sisaMargin = 0.0;
        $jadwalAktif = Angsuran::where('pembiayaan_id', $pembiayaan->id)
            ->whereIn('status_angsuran', ['Belum Jatuh Tempo', 'Jatuh Tempo', 'Terlambat', 'Dibayar Sebagian'])
            ->get(['status_angsuran', 'pokok_angsuran', 'margin_ujroh', 'total_bayar', 'denda', 'sisa_pokok']);

        foreach ($jadwalAktif as $jadwal) {
            if ($jadwal->status_angsuran === 'Dibayar Sebagian') {
                $sisaMargin += self::marginBelumTerakui($jadwal);
            } else {
                $sisaMargin += (float) $jadwal->margin_ujroh;
            }
        }

        $sisaMargin = Setting::bulatkanUang($sisaMargin);

        $angsuranBerikut = Angsuran::where('pembiayaan_id', $pembiayaan->id)
            ->where('status_angsuran', '!=', 'Sudah Dibayar')
            ->orderBy('angsuran_ke')
            ->first();

        return [
            'sisaPokok' => $sisaPokok,
            'sisaMargin' => $sisaMargin,
            'sisaPiutang' => Setting::bulatkanUang($sisaPokok + $sisaMargin),
            'angsuranLama' => $angsuranBerikut
                ? Setting::bulatkanUang((float) $angsuranBerikut->jumlah_angsuran)
                : Setting::bulatkanUang((float) ($pembiayaan->jumlah_angsuran ?? 0)),
            'tenorSisa' => Angsuran::where('pembiayaan_id', $pembiayaan->id)
                ->where('status_angsuran', '!=', 'Sudah Dibayar')
                ->count(),
        ];
    }

    /**
     * Margin yang BELUM terakui pada satu baris angsuran "Dibayar Sebagian".
     *
     * F-07: angka ini wajib memakai URUTAN ALOKASI YANG SAMA dengan
     * `AngsuranTransactionService::alokasiPembayaran()` (Pokok → Margin) dan
     * data yang TERSIMPAN, bukan kesimpulan "margin terakui = total_bayar − denda".
     *
     * Kesimpulan lama keliru: bila uang yang dibayar habis untuk POKOK, seluruh
     * `total_bayar − denda` dianggap margin terakui, sehingga sisa margin
     * mengecil (contoh: bayar 100.000 atas pokok 100.000 + margin 20.000 →
     * dianggap margin terakui 100.000, sisa margin −80.000). Akibatnya total
     * piutang hasil `ringkasan()` lebih KECIL dari kewajiban sesungguhnya dan
     * jadwal restrukturisasi dibentuk dari dasar yang salah
     * (melanggar semangat Fatwa DSN-MUI No. 49 yang melarang mengubah nominal
     * piutang di luar kesepakatan).
     *
     * Data yang dipakai:
     *   - `sisa_pokok` → pokok baris ini yang MASIH menjadi kewajiban
     *   - `total_bayar` → uang yang diserahkan untuk porsi angsuran
     *   - `denda` → ta'zir periode berjalan yang DITAGIH TERPISAH (bukan
     *     bagian porsi angsuran), sehingga tidak boleh mengurangi uang angsuran
     */
    private static function marginBelumTerakui(Angsuran $jadwal): float
    {
        $marginBaris = Setting::bulatkanUang((float) $jadwal->margin_ujroh);

        if ($marginBaris <= 0) {
            return 0.0;
        }

        $pokokTertagih = Setting::bulatkanUang(max(
            0.0,
            (float) $jadwal->pokok_angsuran - (float) $jadwal->sisa_pokok
        ));

        $uangAngsuran = Setting::bulatkanUang(max(
            0.0,
            (float) $jadwal->total_bayar - (float) $jadwal->denda
        ));

        // Pokok didahulukan; margin hanya menerima sisa uang setelah pokok.
        $uangUntukMargin = Setting::bulatkanUang(max(0.0, $uangAngsuran - $pokokTertagih));
        $marginTerakui = Setting::bulatkanUang(min($marginBaris, $uangUntukMargin));

        return Setting::bulatkanUang(max(0.0, $marginBaris - $marginTerakui));
    }

    /**
     * Proyeksi angsuran baru = sisa piutang / tenor baru.
     * Total piutang TIDAK bertambah berapa pun tenor barunya.
     */
    public static function simulasiAngsuranBaru(float $sisaPiutang, int $tenorBaru): float
    {
        if ($tenorBaru < 1) {
            return 0.0;
        }

        return Setting::bulatkanUang($sisaPiutang / $tenorBaru);
    }

    /**
     * APPROVE — eksekusi jadwal baru (Manajer).
     *
     * Langkah (semua dalam satu DB::transaction):
     *   1. Kunci pengajuan & pembiayaan (lockForUpdate) + validasi status.
     *   2. Snapshot sisa pokok/margin/piutang — TIDAK boleh bertambah.
     *   3. Hapus jadwal angsuran lama yang belum terbayar.
     *   4. Re-generate jadwal angsuran baru dari sisa piutang (tanpa margin tambahan).
     *   5. Update pembiayaan (tenor, angsuran, jatuh tempo, kolektibilitas).
     *   6. Update pengajuan -> APPROVED + nomor addendum.
     *
     * @throws \Exception
     */
    public static function approve(int $requestId, int $manajerId, ?string $catatanManajer = null): RestrukturisasiRequest
    {
        return DB::transaction(function () use ($requestId, $manajerId, $catatanManajer) {
            $req = RestrukturisasiRequest::whereKey($requestId)->lockForUpdate()->first();
            if (! $req) {
                throw new \Exception('Pengajuan restrukturisasi tidak ditemukan.');
            }
            if ($req->status !== RestrukturisasiRequest::STATUS_PENDING_APPROVAL) {
                throw new \Exception('Pengajuan ini sudah diproses (status: '.$req->status.').');
            }

            // GCG — pemisahan tugas: AO pengaju tidak boleh menjadi Manajer pemutus.
            if (PemisahanTugas::dilanggar($req->ao_id, $manajerId)) {
                throw new \Exception(PemisahanTugas::PESAN);
            }

            $pembiayaan = Pembiayaan::whereKey($req->pembiayaan_id)->lockForUpdate()->first();
            if (! $pembiayaan) {
                throw new \Exception('Data pembiayaan tidak ditemukan.');
            }

            $ringkasan = self::ringkasan($pembiayaan);
            $sisaPokok = $ringkasan['sisaPokok'];
            $sisaMargin = $ringkasan['sisaMargin'];
            $sisaPiutang = $ringkasan['sisaPiutang'];

            if ($sisaPiutang <= 0) {
                throw new \Exception('Sisa piutang sudah nol — pembiayaan tidak perlu direstrukturisasi.');
            }

            $tenorBaru = (int) ($req->tenor_baru ?? 0);
            if ($tenorBaru < 1) {
                // Reconditioning tanpa tenor eksplisit: tenor sisa + grace period.
                $tenorBaru = max(1, $ringkasan['tenorSisa'] + (int) ($req->grace_period ?? 0));
            }
            if ($tenorBaru > 240) {
                throw new \Exception('Tenor baru tidak wajar (maksimal 240 bulan).');
            }

            $nomorAddendum = 'ADD-RS/'.$pembiayaan->no_pembiayaan.'/'.now()->format('Ymd').'-'.str_pad((string) $req->id, 4, '0', STR_PAD_LEFT);

            // ── 3) Hapus / delegasi jadwal angsuran lama yang BELUM terbayar ──────────
            // Jadwal yang sudah dibayar TIDAK dihapus (riwayat & jurnal tetap utuh).
            Angsuran::where('pembiayaan_id', $pembiayaan->id)
                ->where('status_angsuran', '!=', 'Sudah Dibayar')
                ->delete();

            // ── 4) Re-generate jadwal angsuran baru ──────────────────────────────────
            // Nomor angsuran dilanjutkan setelah angsuran yang sudah dibayar.
            // R-04: pakai MAX(angsuran_ke) — bukan count()+1 — agar penomoran tetap
            // benar walau ada celah/urutan tidak rapat (mis. jadwal lama dihapus).
            $angsuranKeAwal = ((int) Angsuran::where('pembiayaan_id', $pembiayaan->id)
                ->where('status_angsuran', 'Sudah Dibayar')
                ->max('angsuran_ke')) + 1;

            $tanggalMulai = $req->tanggal_jatuh_tempo_baru
                ? Carbon::parse($req->tanggal_jatuh_tempo_baru)->startOfDay()
                : Carbon::today()->addMonthNoOverflow()->startOfDay();

            $pokokPerBulan = Setting::bulatkanUang($sisaPokok / $tenorBaru);
            $marginPerBulan = Setting::bulatkanUang($sisaMargin / $tenorBaru);

            // Selisih pembulatan dibebankan pada angsuran TERAKHIR agar total tepat.
            $selisihPokok = Setting::bulatkanUang($sisaPokok - ($pokokPerBulan * $tenorBaru));
            $selisihMargin = Setting::bulatkanUang($sisaMargin - ($marginPerBulan * $tenorBaru));

            $sisaPokokBerjalan = $sisaPokok;
            $tanggalJatuhTempoTerakhir = $tanggalMulai->copy();

            for ($i = 0; $i < $tenorBaru; $i++) {
                $isTerakhir = ($i === $tenorBaru - 1);

                $pokok = $isTerakhir ? Setting::bulatkanUang($pokokPerBulan + $selisihPokok) : $pokokPerBulan;
                $margin = $isTerakhir ? Setting::bulatkanUang($marginPerBulan + $selisihMargin) : $marginPerBulan;
                $jumlah = Setting::bulatkanUang($pokok + $margin);

                $sisaPokokBerjalan = Setting::bulatkanUang(max(0, $sisaPokokBerjalan - $pokok));

                $tanggalJatuhTempo = $tanggalMulai->copy()->addMonthsNoOverflow($i);
                $tanggalJatuhTempoTerakhir = $tanggalJatuhTempo->copy();

                Angsuran::create([
                    'pembiayaan_id' => $pembiayaan->id,
                    'angsuran_ke' => $angsuranKeAwal + $i,
                    'tanggal_jatuh_tempo' => $tanggalJatuhTempo->toDateString(),
                    'pokok_angsuran' => $pokok,
                    'margin_ujroh' => $margin,
                    'jumlah_angsuran' => $jumlah,
                    'denda' => 0,
                    'total_bayar' => 0,
                    'sisa_pokok' => $sisaPokokBerjalan,
                    'status_angsuran' => 'Belum Jatuh Tempo',
                    'hari_terlambat' => 0,
                    'user_id' => $manajerId,
                    'catatan' => 'Jadwal baru hasil restrukturisasi '.$req->skema_restrukturisasi
                                             .' — Addendum '.$nomorAddendum,
                ]);
            }

            $angsuranBaru = Setting::bulatkanUang($pokokPerBulan + $marginPerBulan);

            // ── 5) Update pembiayaan utama ───────────────────────────────────────────
            $pembiayaan->update([
                'status_pembiayaan' => Pembiayaan::STATUS_RESTRUKTURISASI,
                'tenor' => $tenorBaru,
                'jumlah_angsuran' => $angsuranBaru,
                'tanggal_jatuh_tempo' => $tanggalJatuhTempoTerakhir->toDateString(),
                // Kolektibilitas pasca-restrukturisasi: kolom `status_kolektibilitas`
                // diperlakukan sebagai OVERRIDE MANUAL permanen oleh CkpnService
                // (normalisasiKodeKolektibilitas) — ia akan MEMBEKUKAN kualitas aset
                // selamanya. Menyimpan 'kol1' di sini tidak tepat karena:
                //   1. Bila jadwal baru benar-benar lancar, CkpnService SUDAH
                //      menghitung kol1 dari tunggakan nyata (hariTunggakan()).
                //   2. Bila pembiayaan hasil restrukturisasi kembali menunggak,
                //      override 'kol1' akan MENYEMBUNYIKAN tunggakan tersebut →
                //      NPF salah lapor & CKPN under-provisioning (pelanggaran
                //      POJK 12/2023 tentang kualitas aset & PSAK 71).
                // Karena itu kolom ini DIKOSONGKAN agar klasifikasi selalu dihitung
                // dinamis dari hari tunggakan — selaras prinsip "jadwal baru dimulai
                // dari kol-1 hanya bila tidak menunggak".
                'status_kolektibilitas' => null,
                'keterangan' => 'Restrukturisasi '.$req->skema_restrukturisasi
                                           .' disetujui Manajer pada '.now()->format('d/m/Y H:i')
                                           .' (Addendum '.$nomorAddendum.'). Sisa piutang tetap Rp '
                                           .number_format($sisaPiutang, 0, ',', '.')
                                           .' tanpa tambahan margin (Fatwa DSN-MUI No. 49/DSN-MUI/IV/2005).',
            ]);

            // ── 6) Update pengajuan ──────────────────────────────────────────────────
            $req->update([
                'status' => RestrukturisasiRequest::STATUS_APPROVED,
                'manajer_id' => $manajerId,
                'catatan_manajer' => $catatanManajer,
                'keputusan_at' => now(),
                'addendum_no' => $nomorAddendum,
                'tenor_baru' => $tenorBaru,
                'sisa_pokok_snapshot' => $sisaPokok,
                'sisa_margin_snapshot' => $sisaMargin,
                'sisa_piutang_snapshot' => $sisaPiutang,
            ]);

            AuditLog::record(
                'Restrukturisasi Pembiayaan',
                $sisaPiutang,
                $pembiayaan->no_pembiayaan,
                'Restrukturisasi '.$req->skema_restrukturisasi.' DISETUJUI untuk '.$pembiayaan->no_pembiayaan
                .' — tenor baru '.$tenorBaru.' bulan, angsuran baru Rp '.number_format($angsuranBaru, 0, ',', '.')
                .' (sisa piutang tetap Rp '.number_format($sisaPiutang, 0, ',', '.').', tanpa margin tambahan).'
            );

            return $req->fresh(['pembiayaan', 'ao', 'manajer']);
        });
    }

    /**
     * REJECT — jadwal angsuran lama TETAP berlaku.
     *
     * @throws \Exception
     */
    public static function reject(int $requestId, int $manajerId, ?string $catatanManajer = null): RestrukturisasiRequest
    {
        return DB::transaction(function () use ($requestId, $manajerId, $catatanManajer) {
            $req = RestrukturisasiRequest::whereKey($requestId)->lockForUpdate()->first();
            if (! $req) {
                throw new \Exception('Pengajuan restrukturisasi tidak ditemukan.');
            }
            if ($req->status !== RestrukturisasiRequest::STATUS_PENDING_APPROVAL) {
                throw new \Exception('Pengajuan ini sudah diproses (status: '.$req->status.').');
            }

            // GCG — pemisahan tugas: AO pengaju tidak boleh menolak pengajuannya sendiri.
            if (PemisahanTugas::dilanggar($req->ao_id, $manajerId)) {
                throw new \Exception(PemisahanTugas::PESAN);
            }

            $req->update([
                'status' => RestrukturisasiRequest::STATUS_REJECTED,
                'manajer_id' => $manajerId,
                'catatan_manajer' => $catatanManajer,
                'keputusan_at' => now(),
            ]);

            // Jadwal angsuran lama tetap berlaku — AO melanjutkan penagihan standar.
            AuditLog::record(
                'Restrukturisasi Pembiayaan',
                (float) $req->sisa_piutang_snapshot,
                $req->pembiayaan?->no_pembiayaan ?? '-',
                'Pengajuan restrukturisasi DITOLAK oleh Manajer'
                .($catatanManajer ? ' — alasan: '.$catatanManajer : '')
                .'. Jadwal angsuran lama tetap berlaku.'
            );

            return $req->fresh(['pembiayaan', 'ao', 'manajer']);
        });
    }
}
