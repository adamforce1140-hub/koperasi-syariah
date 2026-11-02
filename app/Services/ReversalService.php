<?php

namespace App\Services;

use App\Models\Angsuran;
use App\Models\ApprovalTransaksi;
use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\Pembiayaan;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Support\JenisTransaksi;
use App\Support\PemisahanTugas;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReversalService
{
    public function __construct(
        private AutoJournalingService $journalService,
        private DanaKebajikanService $danaKebajikanService
    ) {}

    /**
     * Eksekusi void reversal secara atomik (ACID).
     * Membalikkan saldo rekening/angsuran dan membuat jurnal pembalik.
     *
     * @return array ['status' => 'success'|'error', 'message' => string]
     */
    public function executeVoidReversal(ApprovalTransaksi $approval, int $approverId): array
    {
        // Hanya proses jika tipe_referensi adalah 'transaksi'
        if ($approval->tipe_referensi !== 'transaksi') {
            return ['status' => 'error', 'message' => 'Tipe referensi bukan transaksi.'];
        }

        $transaksi = Transaksi::find($approval->referensi_id);
        if (! $transaksi) {
            return ['status' => 'error', 'message' => 'Transaksi asal tidak ditemukan.'];
        }

        if ($transaksi->status_transaksi !== 'Pending Void') {
            return ['status' => 'error', 'message' => 'Transaksi tidak dalam status Pending Void.'];
        }

        // GCG — pemisahan tugas: pemohon void tidak boleh mengeksekusi void-nya sendiri.
        if (PemisahanTugas::dilanggar($approval->diajukan_oleh, $approverId)) {
            return ['status' => 'error', 'message' => PemisahanTugas::PESAN];
        }

        return DB::transaction(function () use ($transaksi, $approval, $approverId) {
            // 1. Balikkan mutasi saldo rekening + posting jurnal pembalik
            $reversal = $this->reverseTransactionEffect(
                $transaksi,
                $approverId,
                'REVERSAL - '.$transaksi->jenis_transaksi.' | '.$transaksi->no_transaksi
            );
            if ($reversal['status'] !== 'success') {
                return $reversal;
            }

            // 2. Update status transaksi asal menjadi Voided
            $transaksi->update(['status_transaksi' => 'Voided']);

            // 3. Update approval
            $approval->update([
                'status' => 'APPROVED',
                'disetujui_oleh' => $approverId,
                'catatan_approver' => 'Void disetujui. Reversal berhasil dieksekusi.',
                'approved_at' => now(),
            ]);

            return ['status' => 'success', 'message' => 'Void berhasil dieksekusi. Saldo dan jurnal telah dibalikkan.'];
        });
    }

    /**
     * Balikkan efek mutasi saldo + jurnal dari sebuah transaksi yang dibatalkan
     * (void disetujui atau penolakan AML). Mengembalikan saldo rekening ke
     * kondisi sebelum transaksi tersebut.
     *
     * WAJIB dipanggil di dalam DB::transaction() milik pemanggil agar lockForUpdate efektif.
     *
     * @return array ['status' => 'success'|'error', 'message' => string]
     */
    public function reverseTransactionEffect(Transaksi $transaksi, int $userId, string $keterangan = ''): array
    {
        $nominal = (float) $transaksi->jumlah;
        $jenis = $transaksi->jenis_transaksi;

        // Porsi margin yang diakui pada pembayaran angsuran ini, dihitung dari
        // data SEBELUM angsuran di-reset (kolom `total_bayar` akan dikosongkan
        // di blok reversal di bawah). Dipakai untuk memutar-balik amortisasi
        // margin ditangguhkan 1-2101 (PSAK 102/402 — CKPN-11).
        $marginReversal = 0.0;

        // 1. Reversal saldo rekening (jika ada rekening terkait)
        if ($transaksi->rekening_id) {
            $rekening = Rekening::lockForUpdate()->find($transaksi->rekening_id);
            if ($rekening) {
                if ($transaksi->posisi === 'Debet') {
                    // Transaksi asal menambah saldo → reversal kurangi saldo
                    if ((float) $rekening->saldo < $nominal) {
                        return ['status' => 'error', 'message' => 'Saldo rekening tidak cukup untuk reversal (saldo akan menjadi negatif).'];
                    }
                    $rekening->decrement('saldo', $nominal);
                } else {
                    // Transaksi asal mengurangi saldo → reversal tambah saldo
                    $rekening->increment('saldo', $nominal);
                }
            }
        }

        // 2. Reversal angsuran (jika transaksi pembayaran angsuran)
        if (JenisTransaksi::adalahAngsuran((string) $jenis)) {
            $angsuran = Angsuran::lockForUpdate()->where('transaksi_id', $transaksi->id)->first();
            if (! $angsuran) {
                return [
                    'status' => 'error',
                    'message' => 'Angsuran terkait transaksi '.$transaksi->no_transaksi.
                                 ' tidak ditemukan. Reversal dibatalkan agar sisa pokok pembiayaan '.
                                 'tidak salah saji (saldo & jurnal tetap konsisten).',
                ];
            }

            $pembiayaan = $angsuran->pembiayaan_id
                ? Pembiayaan::lockForUpdate()->find($angsuran->pembiayaan_id)
                : null;

            if (! $pembiayaan) {
                return [
                    'status' => 'error',
                    'message' => 'Pembiayaan #'.($angsuran->pembiayaan_id ?: '-').
                                 ' tidak ditemukan. Reversal dibatalkan agar sisa pembiayaan '.
                                 'tidak salah saji.',
                ];
            }

            // Pokok yang BENAR-BENAR terbayar pada angsuran ini = pokok tagihan
            // dikurangi sisa pokok yang masih menjadi kewajiban. Tabel `angsuran`
            // TIDAK punya kolom `jumlah_pokok`; kolom yang benar adalah
            // `pokok_angsuran` (tagihan) dan `sisa_pokok` (kekurangan). Untuk
            // angsuran lunas, sisa_pokok = 0 sehingga seluruh tagihan dikembalikan.
            $pokokTagihan = Setting::bulatkanUang(max(0, (float) ($angsuran->pokok_angsuran ?? 0)));
            $sisaKewajibanAngsuran = Setting::bulatkanUang(max(0, (float) ($angsuran->sisa_pokok ?? 0)));
            $pengembalian = Setting::bulatkanUang(max(0, $pokokTagihan - $sisaKewajibanAngsuran));

            // Margin yang telah diakui = min(margin tagihan, bayar bersih ta'zir).
            // `alokasiPembayaran()` mendahulukan ta'zir lalu margin, sehingga
            // inilah jumlah yang dibalik dari pendapatan (4-100x) ke akun kontra
            // margin ditangguhkan (1-2101) pada jurnal pembalik.
            $marginTagihan = Setting::bulatkanUang(max(0, (float) ($angsuran->margin_ujroh ?? 0)));
            $totalBayarLama = Setting::bulatkanUang(max(0, (float) ($angsuran->total_bayar ?? 0)));
            $tazirLama = Setting::bulatkanUang(max(0, (float) ($angsuran->denda ?? 0)));
            $marginReversal = Setting::bulatkanUang(min($marginTagihan, max(0, $totalBayarLama - $tazirLama)));

            /* Status angsuran WAJIB diturunkan dari tanggal (bukan asumsi):
             | setelah `tanggal_bayar` dikosongkan, baris kembali menjadi
             | kewajiban penuh. Bila jatuh temponya sudah lewat, status yang
             | benar adalah 'Terlambat' — bukan 'Belum Jatuh Tempo'. Aturan ini
             | sama dengan `angsuran:sinkronkan-status` (SSOT status turunan)
             | sehingga daftar tunggakan/NPF langsung akurat pasca-void. */
            $jatuhTempo = $angsuran->tanggal_jatuh_tempo
                ? Carbon::parse($angsuran->tanggal_jatuh_tempo)->startOfDay()
                : null;
            $hariIni = Carbon::today();

            $statusSetelahVoid = match (true) {
                $jatuhTempo === null => 'Belum Jatuh Tempo',
                $jatuhTempo->lt($hariIni) => 'Terlambat',
                $jatuhTempo->equalTo($hariIni) => 'Jatuh Tempo',
                default => 'Belum Jatuh Tempo',
            };

            $angsuran->update([
                'status_angsuran' => $statusSetelahVoid,
                'tanggal_bayar' => null,
                // Kolom `total_bayar` NOT NULL DEFAULT 0 — kembalikan ke 0
                // (bukan null) agar reversal tidak gagal dengan SQLSTATE 23000.
                'total_bayar' => 0,
                'transaksi_id' => null,
                // Angsuran kembali menjadi kewajiban penuh → sisa pokok = tagihan.
                'sisa_pokok' => $pokokTagihan,
            ]);

            // Kembalikan sisa pembiayaan ke kondisi sebelum angsuran ini dibayar.
            // Kolom yang benar pada tabel `pembiayaan` adalah `sisa_pembiayaan`
            // (kolom `sisa_pokok` hanya ada di tabel `angsuran`). Nilai dihitung
            // lalu di-cap pada total_pembiayaan agar tidak melewati plafon.
            $sisaBaru = Setting::bulatkanUang(min(
                (float) $pembiayaan->total_pembiayaan,
                (float) $pembiayaan->sisa_pembiayaan + $pengembalian
            ));

            $pembiayaan->update(['sisa_pembiayaan' => $sisaBaru]);
        }

        // 3. Buat jurnal pembalik (reversal entry)
        $keteranganReversal = $keterangan ?: ('REVERSAL - '.$jenis.' | '.$transaksi->no_transaksi);
        $this->buatJurnalReversal($transaksi, $nominal, $keteranganReversal, $userId, $marginReversal);

        return ['status' => 'success', 'message' => 'Saldo dan jurnal berhasil dibalikkan.'];
    }

    /**
     * Buat jurnal pembalik dengan membalik posisi Debet/Kredit dari transaksi asal.
     */
    private function buatJurnalReversal(Transaksi $transaksi, float $nominal, string $keterangan, int $userId, float $marginReversal = 0.0): void
    {
        // Tentukan akun berdasarkan jenis transaksi
        $jenis = $transaksi->jenis_transaksi;
        $jurnal = null;

        // Reversal pencairan butuh data pembiayaan (akad & margin) — pastikan
        // relasi dimuat agar tidak terkena lazy-loading nol.
        $transaksi->loadMissing('pembiayaan');

        /* CATATAN URUTAN PENTING:
         | Cabang `Pencairan` HARUS diperiksa SEBELUM cabang `Setor` generik
         | agar "Pencairan Pembiayaan" tidak tertangkap sebagai setoran.
         | Cabang `Simpanan Pokok/Wajib/Sukarela` juga diperiksa lebih dulu
         | agar sisi DEBET menyentuh akun kewajiban yang benar. */
        if (str_contains($jenis, 'Pencairan Pembiayaan')) {
            // Reversal pencairan pembiayaan (dropping): balik jurnal PSAK 108.
            // Asal: DEBET Kas/(Sumber dana) | KREDIT Piutang (pokok+margin)
            //       + DEBET Margin Ditangguhkan (akad jual-beli)
            // Reversal: DEBET Piutang | KREDIT Sumber dana.
            $pembiayaan = $transaksi->pembiayaan;
            $akad = $pembiayaan?->akad_pembiayaan ?? Pembiayaan::akadDefault();
            // Margin = sisa kewajiban di luar pokok yang dicairkan.
            $margin = Setting::bulatkanUang(max(
                0,
                (float) ($pembiayaan?->total_pembiayaan ?? 0)
                    - (float) ($pembiayaan?->sisa_pembiayaan ?? 0)
                    - (float) $nominal
            ));
            // Bila belum pernah diangsur (sisa = total), margin diambil dari
            // selisih total akad vs pokok akad.
            if ($margin <= 0 && $pembiayaan) {
                $margin = Setting::bulatkanUang(max(0, (float) $pembiayaan->total_pembiayaan - (float) $pembiayaan->jumlah_pembiayaan));
            }

            $jurnal = $this->journalService->jurnalReversalPencairanPembiayaan(
                $nominal,
                $margin,
                $keterangan,
                $akad,
                $userId,
                $transaksi->id,
                $transaksi->pembiayaan_id,
                $transaksi->referensi ?: AutoJournalingService::KAS_TELLER
            );
        } elseif (str_contains($jenis, 'Injeksi Kas')) {
            // Reversal injeksi kas Brankas → Laci Teller.
            $jurnal = $this->journalService->jurnalReversalInjeksiKas(
                $nominal,
                $keterangan,
                $userId,
                $transaksi->id
            );
        } elseif ((str_contains($jenis, 'Simpanan Pokok')
            || str_contains($jenis, 'Simpanan Wajib')
            || str_contains($jenis, 'Simpanan Sukarela'))
            && ! str_contains($jenis, 'Tarik')
            && ! str_contains($jenis, 'Penarikan')) {
            // Reversal setoran simpanan: DEBET akun simpanan | KREDIT Kas Teller.
            $jurnal = $this->journalService->jurnalReversalSetoranSimpanan(
                $nominal,
                $keterangan,
                $this->kodeSimpananUntukJenis((string) $jenis, $transaksi),
                $userId,
                $transaksi->id,
                $transaksi->rekening_id,
                $transaksi->anggota_id
            );
        } elseif (str_contains($jenis, 'Pencairan Dini Deposito')) {
            // Reversal Pencairan DINI Deposito Mudharabah (PSAK 105, fatwa DSN MUI 17).
            // Asal : DEBET 2-1003 Berjangka (pokok) | KREDIT penalti + KREDIT 2-1001.
            // Balik: DEBET 2-1001 (kembali ke Sukarela) + DEBET penalti | KREDIT 2-1003.
            // Nominal penalti dibaca dari jurnal ASAL agar nilai kembalinya presisi
            // (bukan diasumsikan nol) sehingga saldo anggota pulih tepat.
            $kodePenalti = \App\Support\Coa::DANA_KEBAJIKAN;
            $penalti = 0.0;
            // Pokok default = nominal leg yang divoid; bila jurnal asal
            // ditemukan, pokok dibaca dari baris DEBET 2-1003 sehingga tepat
            // meski yang divoid adalah leg Simpanan Sukarela (nominal bersih
            // setelah penalti, bukan pokok penuh).
            $pokokReversal = $nominal;
            $jurnalAsal = $transaksi->jurnal;

            // Fallback: pada alur lama jurnal pencairan dini dicatat dengan
            // `transaksi_id` NULL sehingga relasi `jurnal()` tidak menemukannya.
            // Pencairan dini menghasilkan DUA leg transaksi (Simpanan Berjangka
            // dan Simpanan Sukarela) yang berbagi `catatan` identik (memuat
            // nomor deposito & nominal penalti). `catatan` inilah kunci
            // deterministik untuk menemukan jurnal asal dari leg mana pun.
            if (! $jurnalAsal && ! empty($transaksi->catatan)) {
                $jurnalAsal = Jurnal::where('sumber', 'Pencairan Dini Deposito Mudharabah')
                    ->where('keterangan', $transaksi->catatan)
                    ->latest('id')
                    ->first();
            }

            if (! $jurnalAsal) {
                // Penalti tetap 0 tanpa jurnal asal; jurnal pembalik akan
                // menghapus satu-satunya bukti ta'zir. Catat agar terdeteksi.
                Log::warning('ReversalService: jurnal asal pencairan dini deposito tidak ditemukan; penalti diasumsikan 0.', [
                    'transaksi_id' => $transaksi->id,
                    'no_transaksi' => $transaksi->no_transaksi,
                    'rekening_id' => $transaksi->rekening_id,
                    'nominal' => $nominal,
                ]);
            }

            if ($jurnalAsal) {
                // Relasi ini bernama `detail()` (bukan `details()`) pada model Jurnal.
                // Sebelum perbaikan, `loadMissing('details')` memicu
                // RelationNotFoundException dan penalti selalu terbaca 0 →
                // reversal pencairan dini tidak mengembalikan ta'zir ke Dana
                // Kebajikan sehingga buku besar menyimpang. Guard try/catch
                // menjaga reversal tetap berjalan bila jurnal asal tidak lengkap.
                try {
                    $jurnalAsal->loadMissing('detail');
                } catch (\Throwable $e) {
                    Log::warning('ReversalService: gagal memuat detail jurnal asal pencairan dini; penalti diasumsikan 0.', [
                        'transaksi_id' => $transaksi->id,
                        'jurnal_id' => $jurnalAsal->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                foreach ($jurnalAsal->detail as $detail) {
                    // `JurnalDetail` TIDAK menyimpan kolom `kode_akun`; kode akun
                    // harus diresolusi dari `coa_id` (relasi `coa`). Sebelum
                    // perbaikan, pembacaan `$detail->kode_akun` selalu null →
                    // penalti terdeteksi salah dan ta'zir tidak dikembalikan.
                    $kode = (string) ($detail->coa?->kode_akun ?? '');
                    if ($kode === '' && $detail->coa_id) {
                        $kode = (string) (Coa::find($detail->coa_id)?->kode_akun ?? '');
                    }
                    // Baris DEBET 2-1003 = pokok penuh yang dikeluarkan dari
                    // Simpanan Berjangka (sumber otoritatif nominal reversal).
                    if (Setting::bulatkanUang((float) $detail->debet) > 0
                        && $kode === \App\Support\Coa::SIMPANAN_BERJANGKA) {
                        $pokokReversal = Setting::bulatkanUang((float) $detail->debet);
                    }
                    // Baris KREDIT selain akun Sukarela = penalti/dana kebajikan.
                    if (Setting::bulatkanUang((float) $detail->kredit) > 0
                        && $kode !== \App\Support\Coa::SIMPANAN_SUKARELA) {
                        $penalti = Setting::bulatkanUang((float) $detail->kredit);
                        $kodePenalti = $kode;
                    }
                }
            }

            $jurnal = $this->journalService->jurnalReversalPencairanDiniDeposito(
                $pokokReversal,
                $penalti,
                $keterangan,
                $kodePenalti,
                $userId,
                $transaksi->id,
                $transaksi->rekening_id,
                $transaksi->anggota_id
            );
        } elseif (str_contains($jenis, 'Pencairan Deposito')) {
            // Reversal Pencairan Deposito Mudharabah (non-ARO, non-dini).
            // Asal : DEBET 2-1003 | KREDIT 2-1001.
            // Balik: DEBET 2-1001 | KREDIT 2-1003.
            $jurnal = $this->journalService->jurnalReversalPencairanDeposito(
                $nominal,
                $keterangan,
                $userId,
                $transaksi->id,
                $transaksi->rekening_id,
                $transaksi->anggota_id
            );
        } elseif (str_contains($jenis, 'Kapitalisasi Bagi Hasil')) {
            // Reversal Kapitalisasi Bagi Hasil Deposito (ARO_PLUS).
            // Asal : DEBET 2-1001 | KREDIT 2-1003.
            // Balik: DEBET 2-1003 | KREDIT 2-1001.
            $jurnal = $this->journalService->jurnalReversalPembukaanDeposito(
                $nominal,
                $keterangan,
                $userId,
                $transaksi->id,
                $transaksi->rekening_id,
                $transaksi->anggota_id
            );
        } elseif (str_contains($jenis, 'Bagi Hasil Deposito')) {
            // Reversal Bagi Hasil Deposito Mudharabah (akrual bulanan).
            // Asal : DEBET 5-3001 Beban Bagi Hasil | KREDIT 2-1001 Sukarela.
            // Balik: DEBET 2-1001 | KREDIT 5-3001.
            $jurnal = $this->journalService->jurnalReversalBagiHasilDepositoMudharabah(
                $nominal,
                $keterangan,
                $userId,
                $transaksi->id,
                $transaksi->rekening_id,
                $transaksi->anggota_id
            );
        } elseif (str_contains($jenis, 'Pembukaan Deposito')) {
            // Reversal Pembukaan Deposito Mudharabah.
            // Asal : DEBET 2-1001 | KREDIT 2-1003.
            // Balik: DEBET 2-1003 | KREDIT 2-1001.
            $jurnal = $this->journalService->jurnalReversalPembukaanDeposito(
                $nominal,
                $keterangan,
                $userId,
                $transaksi->id,
                $transaksi->rekening_id,
                $transaksi->anggota_id
            );
        } elseif (str_contains($jenis, 'Deposito') || str_contains($jenis, 'Berjangka')) {
            // Cadangan: seluruh jenis transaksi lain yang menyentuh Simpanan
            // Berjangka (2-1003) dibalik dengan memutar jurnal penarikan deposito
            // standar (DEBET 2-1003 | KREDIT 1-1001 → DEBET 1-1001 | KREDIT 2-1003).
            $jurnal = $this->journalService->jurnalReversalTarikDeposito(
                $nominal,
                $keterangan,
                $userId,
                $transaksi->id,
                $transaksi->rekening_id,
                $transaksi->anggota_id
            );
        } elseif (str_contains($jenis, 'Setor') || str_contains($jenis, 'Setoran')) {
            // Reversal setor: balik jurnal setor tunai
            // Asal: DEBIT Kas | KREDIT Simpanan → Reversal: DEBIT Simpanan | KREDIT Kas
            $jurnal = $this->journalService->jurnalTarikTunai(
                $nominal,
                $keterangan,
                $userId,
                $transaksi->id,
                $transaksi->rekening_id,
                $transaksi->anggota_id,
                true // reversal: transaksi asal sedang dibatalkan
            );
        } elseif (str_contains($jenis, 'Tarik') || str_contains($jenis, 'Penarikan')) {
            // Reversal tarik: balik jurnal tarik tunai
            // Asal: DEBIT Simpanan | KREDIT Kas → Reversal: DEBIT Kas | KREDIT Simpanan
            $jurnal = $this->journalService->jurnalSetorTunai(
                $nominal,
                $keterangan,
                $userId,
                $transaksi->id,
                $transaksi->rekening_id,
                $transaksi->anggota_id,
                true // reversal: transaksi asal sedang dibatalkan
            );
        } elseif (JenisTransaksi::adalahAngsuran((string) $jenis)) {
            // Reversal angsuran: DEBIT Piutang | KREDIT Kas, PLUS balik
            // amortisasi margin ditangguhkan (PSAK 102/402 — CKPN-11):
            //   DEBET 4-100x Pendapatan Margin | KREDIT 1-2101 Margin Ditangguhkan.
            // Tanpa memutar-balik amortisasi, pembatalan angsuran Murabahah akan
            // meninggalkan pendapatan margin & mengurangi akun kontra 1-2101 secara
            // permanen sehingga neraca dan laba-rugi salah saji.
            $akad = $transaksi->pembiayaan?->akad_pembiayaan ?? Pembiayaan::akadDefault();
            $margin = $marginReversal;
            $jurnal = $this->journalService->jurnalReversalAngsuran(
                $nominal,
                $keterangan,
                $userId,
                $transaksi->id,
                $transaksi->pembiayaan_id,
                $akad,
                $transaksi->rekening_id,
                $transaksi->anggota_id,
                $margin
            );
        } elseif ($this->danaKebajikanService->jenisTransaksiKebajikan((string) $jenis)) {
            // Reversal ta'zir/denda/infak: balik jurnal penerimaan Dana Kebajikan
            // Asal: DEBET Simpanan/Kas | KREDIT 2-3001
            // Reversal: DEBET 2-3001 | KREDIT Simpanan (auto-debet) / Kas (tunai)
            $dariSimpanan = $transaksi->rekening_id !== null;

            $jurnal = $this->journalService->jurnalReversalTazir(
                $nominal,
                $keterangan,
                $userId,
                $transaksi->id,
                $transaksi->rekening_id,
                $transaksi->anggota_id,
                $dariSimpanan
                    ? AutoJournalingService::SIMPANAN_SUKARELA
                    : AutoJournalingService::KAS_TELLER
            );

            // Tandai pencatatan Dana Kebajikan sebagai dibatalkan agar tidak
            // terhitung dua kali di laporan berbasis tabel `dana_kebajikans`.
            $this->danaKebajikanService->batalkanPencatatanTransaksi($transaksi, $userId);
        }

        /* Jurnal pembalik WAJIB ada bila saldo rekening sudah dikembalikan.
         | `post()` mengembalikan null (bukan throw) bila CoA tidak ditemukan
         | atau nonaktif → tanpa pemeriksaan ini saldo berubah tanpa jurnal
         | (neraca timpang). Lempar agar DB::transaction pemanggil rollback.
         | Jenis transaksi yang tidak punya pemetaan reversal (jurnal tetap
         | null dan tidak ada branch yang cocok) tidak digagalkan di sini agar
         | alur penolakan AML tetap berjalan — cukup dicatat ke log. */
        if ($jurnal === null && (
            str_contains($jenis, 'Setor') || str_contains($jenis, 'Setoran')
            || str_contains($jenis, 'Tarik') || str_contains($jenis, 'Penarikan')
            || JenisTransaksi::adalahAngsuran((string) $jenis)
            || str_contains($jenis, 'Pencairan Pembiayaan') || str_contains($jenis, 'Injeksi Kas')
            || str_contains($jenis, 'Simpanan Pokok') || str_contains($jenis, 'Simpanan Wajib')
            || str_contains($jenis, 'Deposito') || str_contains($jenis, 'Berjangka')
            || str_contains($jenis, 'Kapitalisasi Bagi Hasil')
            || $this->danaKebajikanService->jenisTransaksiKebajikan((string) $jenis)
        )) {
            throw new \RuntimeException(
                "Jurnal pembalik untuk transaksi {$transaksi->no_transaksi} ({$jenis}) ".
                'gagal diposting (CoA tidak ditemukan atau nonaktif).'
            );
        }

        if ($jurnal === null) {
            Log::warning(
                'ReversalService: transaksi tanpa pemetaan jurnal pembalik otomatis — '.
                'saldo dikembalikan tanpa jurnal, mohon input jurnal manual.',
                ['no_transaksi' => $transaksi->no_transaksi, 'jenis_transaksi' => $jenis]
            );
        }
    }

    /**
     * Petakan jenis transaksi setoran simpanan → kode akun CoA kewajiban.
     *
     * Dipakai oleh `buatJurnalReversal()` agar sisi DEBET jurnal pembalik
     * menyentuh akun simpanan yang benar (Pokok / Wajib / Sukarela) — R6.
     *
     * PENTING (konsistensi akun — PSAK 101): seluruh jalur setoran Simpanan
     * Wajib (berjadwal/teller/auto-debit/manual) kini mengkredit akun yang SAMA,
     * yaitu akun hasil TITIK RESOLUSI TUNGGAL
     * `MandatorySavingsService::akunSimpananWajib()` (kanonik 3-1002 — Modal
     * Anggota), sehingga jurnal pembalik selalu menyentuh akun yang identik
     * dengan jurnal asalnya dan saldo anggota kembali ke posisi nol.
     */
    private function kodeSimpananUntukJenis(string $jenis, ?Transaksi $transaksi = null): string
    {
        if (str_contains($jenis, 'Pokok')) {
            return AutoJournalingService::SIMPANAN_POKOK;
        }

        if (str_contains($jenis, 'Wajib')) {
            // PSAK 101: Simpanan Wajib = Modal Anggota. Selalu pakai titik
            // resolusi tunggal agar perubahan parameter akun langsung berlaku
            // pada jurnal pembalik tanpa perlu mengubah kode (zero-hardcoding).
            return app(MandatorySavingsService::class)->akunSimpananWajib();
        }

        return AutoJournalingService::SIMPANAN_SUKARELA;
    }
}
