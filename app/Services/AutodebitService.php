<?php

namespace App\Services;

use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\AutodebitUsulan;
use App\Models\JenisSimpanan;
use App\Models\MandatorySavingsBill;
use App\Models\Rekening;
use App\Models\TazirPenangguhan;
use App\Models\User;
use App\Notifications\AutodebitUsulanNotification;
use App\Support\Izin;
use App\Support\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Layanan Pusat Usulan & Konfirmasi Auto-Debit.
 *
 * Ini adalah implementasi tunggal kebijakan:
 *   "TIDAK ADA auto-debit tanpa konfirmasi pengguna."
 *
 * Peran:
 *   1. `usulkan()` — dipanggil oleh SELURUH kanal (Simpanan Wajib, Angsuran,
 *      Ta'zir). Hanya MENCATAT usulan + mengirim notifikasi; dilarang menyentuh
 *      saldo. Inilah yang menjadikan auto-debit "dinamis": kanal berhenti
 *      memotong sendiri, cukup mengusulkan.
 *   2. `konfirmasi()` — dipanggil oleh pengguna berwenang (manajer/super_admin/
 *      admin — sama dengan peran yang lolos `izin:autodebit.konfirmasi`).
 *      Memverifikasi ulang kelayakan KANAL terkait, lalu mengeksekusi
 *      pemotongan lewat jalur resmi masing-masing (yang sudah punya jurnal
 *      PSAK + penguncian baris). Bila verifikasi gagal, usulan ditandai GAGAL
 *      dan TIDAK ada dana yang dipotong.
 *   3. `tolak()` — pengguna menolak; tidak ada pemotongan.
 *
 * Karena keputusan "potong sekarang" HANYA dapat berasal dari `konfirmasi()`
 * dengan `confirmed_by` terisi, tidak ada jalan yang memotong dana tanpa
 * persetujuan manusia.
 */
class AutodebitService
{
    public function __construct(
        private MandatorySavingsService $mandatorySavings,
        private AngsuranTransactionService $angsuran,
        private TazirService $tazir,
    ) {}

    /** Saklar kebijakan global: apakah auto-debit wajib dikonfirmasi pengguna? */
    public static function wajibKonfirmasi(): bool
    {
        return Setting::autodebitWajibKonfirmasi();
    }

    /* =====================================================================
     | 1. USULAN (TIDAK MEMOTONG DANA)
     |===================================================================== */

    /**
     * Catat usulan auto-debit ke pusat konfirmasi.
     *
     * IDEMPOTEN: bila sudah ada usulan berstatus MENUNGGU_KONFIRMASI untuk
     * kanal + referensi + periode yang sama, usulan lama yang dikembalikan
     * (tidak digandakan). Dengan begitu scheduler yang berjalan berulang tidak
     * menumpuk ujian pemotongan.
     *
     * @param  array{periode?:?string, alasan?:?string, catatan?:?string,
     *               referensi_tipe?:?string, rekening_id?:?int}  $meta
     */
    public function usulkan(
        string $kanal,
        int $referensiId,
        ?Anggota $anggota,
        float $nominal,
        array $meta = []
    ): AutodebitUsulan {
        $periode = $meta['periode'] ?? null;
        $alasan = $meta['alasan'] ?? null;

        // Idempoten: kembalikan usulan yang masih menunggu bila ada.
        $existing = AutodebitUsulan::untukReferensi($kanal, $referensiId)
            ->where('periode', $periode)
            ->menungguKonfirmasi()
            ->first();

        if ($existing) {
            return $existing;
        }

        $rekeningId = $meta['rekening_id'] ?? ($anggota ? $this->rekeningSukarelaId($anggota) : null);

        $usulan = AutodebitUsulan::create([
            'kanal' => $kanal,
            'referensi_id' => $referensiId,
            'referensi_tipe' => $meta['referensi_tipe'] ?? null,
            'anggota_id' => $anggota?->id,
            'rekening_id' => $rekeningId,
            'nominal' => $nominal,
            'periode' => $periode,
            'alasan' => $alasan,
            'catatan' => $meta['catatan'] ?? null,
            'status' => AutodebitUsulan::STATUS_MENUNGGU_KONFIRMASI,
            'signature' => AutodebitUsulan::signature($kanal, $referensiId, $periode),
        ]);

        $this->notifikasiPengguna($usulan);

        Log::info('AutodebitService: usulan auto-debit dibuat (dana BELUM dipotong).', [
            'usulan_id' => $usulan->id,
            'kanal' => $kanal,
            'referensi' => $referensiId,
            'nominal' => $nominal,
        ]);

        return $usulan;
    }

    /* =====================================================================
     | 2. KONFIRMASI (SATU-SATUNYA JALUR PEMOTONGAN)
     |===================================================================== */

    /**
     * Konfirmasi usulan → eksekusi pemotongan lewat jalur resmi kanal.
     *
     * @return array{ok:bool, pesan:string, usulan:AutodebitUsulan}
     */
    public function konfirmasi(AutodebitUsulan $usulan, int $userId, ?string $catatan = null): array
    {
        // Idempoten: usulan yang sudah diproses tidak dipotong dua kali.
        if (! $usulan->isMenungguKonfirmasi()) {
            return [
                'ok' => false,
                'pesan' => 'Usulan ini sudah '.strtolower($usulan->status_label).' — tidak dapat dikonfirmasi ulang.',
                'usulan' => $usulan,
            ];
        }

        $anggota = $usulan->anggota;

        if (! $anggota) {
            return $this->gagalkan($usulan, $userId, $catatan, 'Anggota tidak ditemukan.');
        }

        // Delegasi ke eksekutor kanal. Setiap eksekutor WAJIB memverifikasi
        // ulang kelayakan (terutama saldo terkini) sebelum memotong.
        $eksekusi = $this->eksekusiKanal($usulan, $anggota, $userId);

        if (! ($eksekusi['ok'] ?? false)) {
            return $this->gagalkan($usulan, $userId, $catatan, $eksekusi['pesan'] ?? 'Eksekusi gagal.');
        }

        $usulan->update([
            'status' => AutodebitUsulan::STATUS_DIKONFIRMASI,
            'confirmed_by' => $userId,
            'confirmed_at' => now(),
            'confirmation_note' => $catatan,
            'transaksi_id' => $eksekusi['transaksi_id'] ?? null,
            'executed_at' => now(),
        ]);

        Log::info('AutodebitService: usulan auto-debit DIKONFIRMASI & dipotong.', [
            'usulan_id' => $usulan->id,
            'kanal' => $usulan->kanal,
            'confirmed_by' => $userId,
        ]);

        return [
            'ok' => true,
            'pesan' => $eksekusi['pesan'] ?? 'Auto-debit berhasil dikonfirmasi.',
            'usulan' => $usulan->refresh(),
        ];
    }

    /**
     * Tolak usulan → tidak ada pemotongan; tagihan dikembalikan ke status
     * tunggakan kanal terkait (bila perlu) agar anggota tetap dapat melunasi
     * lewat jalur manual (Teller).
     */
    public function tolak(AutodebitUsulan $usulan, int $userId, ?string $catatan = null): array
    {
        if (! $usulan->isMenungguKonfirmasi()) {
            return [
                'ok' => false,
                'pesan' => 'Usulan ini sudah '.strtolower($usulan->status_label).'.',
            ];
        }

        $usulan->update([
            'status' => AutodebitUsulan::STATUS_DITOLAK,
            'confirmed_by' => $userId,
            'confirmed_at' => now(),
            'confirmation_note' => $catatan,
        ]);

        // Kanal Simpanan Wajib menyimpan state PENDING_CONFIRMATION pada tagihan;
        // kembalikan ke OVERDUE agar tampil sebagai tunggakan biasa (Jalur A).
        if ($usulan->kanal === AutodebitUsulan::KANAL_SIMPANAN_WAJIB) {
            $bill = MandatorySavingsBill::find($usulan->referensi_id);
            if ($bill && $bill->isPendingConfirmation()) {
                $this->mandatorySavings->tolakKonfirmasi($bill, $userId, $catatan);
            }
        }

        return [
            'ok' => true,
            'pesan' => 'Usulan auto-debit ditolak. Tidak ada dana yang dipotong.',
        ];
    }

    /* =====================================================================
     | 3. EKSEKUTOR PER KANAL
     |===================================================================== */

    /**
     * @return array{ok:bool, pesan:string, transaksi_id?:?int}
     */
    private function eksekusiKanal(AutodebitUsulan $usulan, Anggota $anggota, int $userId): array
    {
        return match ($usulan->kanal) {
            AutodebitUsulan::KANAL_SIMPANAN_WAJIB => $this->eksekusiSimpananWajib($usulan, $anggota, $userId),
            AutodebitUsulan::KANAL_ANGSURAN => $this->eksekusiAngsuran($usulan, $anggota, $userId),
            AutodebitUsulan::KANAL_TAZIR => $this->eksekusiTazir($usulan, $anggota, $userId),
            default => ['ok' => false, 'pesan' => 'Kanal usulan tidak dikenal: '.$usulan->kanal],
        };
    }

    private function eksekusiSimpananWajib(AutodebitUsulan $usulan, Anggota $anggota, int $userId): array
    {
        $bill = MandatorySavingsBill::find($usulan->referensi_id);

        if (! $bill) {
            return ['ok' => false, 'pesan' => 'Tagihan Simpanan Wajib tidak ditemukan.'];
        }

        if ($bill->isPaid()) {
            return ['ok' => true, 'pesan' => 'Tagihan sudah lunas (tidak ada pemotongan ganda).', 'transaksi_id' => $bill->transaksi_id];
        }

        // Verifikasi ulang prasyarat (TERMASUK saldo terkini) sebelum memotong.
        $verifikasi = $this->mandatorySavings->verifikasiStatusPembayaran($bill, $anggota);
        if (! ($verifikasi['boleh'] ?? false)) {
            return ['ok' => false, 'pesan' => 'Verifikasi gagal ('.($verifikasi['alasan'] ?? 'TIDAK_DIKETAHUI').').'];
        }

        // Backdate: pemotongan mengikuti tanggal jatuh tempo periode tagihan
        // (mis. 28 Feb 2026) — BUKAN tanggal konfirmasi. Ini menjaga tanggal
        // efektif pembayaran & jurnal PSAK tetap konsisten dengan periode
        // tagihan meski konfirmasi pengguna dilakukan belakangan.
        $tanggalJatuhTempo = $this->mandatorySavings->tanggalJatuhTempo(
            $anggota,
            $this->mandatorySavings->periodeTagihan($bill)
        );

        // `viaKonfirmasi = true`: pemotongan ini adalah eksekusi usulan yang
        // SUDAH disetujui pengguna berwenang, sehingga sah melewati gerbang
        // kebijakan "tidak ada auto-debit tanpa konfirmasi".
        $berhasil = $this->mandatorySavings->autoDebit($bill, $anggota, $tanggalJatuhTempo, $userId, true);

        return [
            'ok' => $berhasil,
            'pesan' => $berhasil
                ? 'Auto-debit Simpanan Wajib dikonfirmasi & dipotong.'
                : 'Pemotongan Simpanan Wajib gagal (saldo tidak mencukupi).',
            'transaksi_id' => $bill->fresh()->transaksi_id ?? null,
        ];
    }

    private function eksekusiAngsuran(AutodebitUsulan $usulan, Anggota $anggota, int $userId): array
    {
        $angsuran = Angsuran::find($usulan->referensi_id);

        if (! $angsuran) {
            return ['ok' => false, 'pesan' => 'Baris angsuran tidak ditemukan.'];
        }

        if ($angsuran->tanggal_bayar !== null) {
            return ['ok' => true, 'pesan' => 'Angsuran sudah dibayar (tidak ada pemotongan ganda).'];
        }

        $nominalTazir = $this->tazir->calculate($angsuran);

        try {
            $result = $this->angsuran->proses($angsuran, [
                'jumlah_bayar' => (float) $angsuran->jumlah_angsuran,
                'nominal_tazir' => $nominalTazir,
                'metode_bayar' => 'AUTODEBET',
                'dibebaskan_tazir' => false,
                'user_id' => $userId,
                // Eksekusi usulan yang SUDAH dikonfirmasi pengguna berwenang.
                'via_konfirmasi' => true,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'pesan' => 'Pemotongan angsuran gagal: '.$e->getMessage()];
        }

        return [
            'ok' => true,
            'pesan' => 'Auto-debit angsuran dikonfirmasi & dipotong.',
            'transaksi_id' => $result['transaksi_id'] ?? null,
        ];
    }

    private function eksekusiTazir(AutodebitUsulan $usulan, Anggota $anggota, int $userId): array
    {
        $tunggakan = TazirPenangguhan::find($usulan->referensi_id);

        if (! $tunggakan) {
            return ['ok' => false, 'pesan' => "Tunggakan ta'zir tidak ditemukan."];
        }

        if ($tunggakan->is_tazir_paid) {
            return ['ok' => true, 'pesan' => "Ta'zir sudah dibayar (tidak ada pemotongan ganda)."];
        }

        $rekeningId = $usulan->rekening_id ?? $this->rekeningSukarelaId($anggota);
        $rekening = $rekeningId ? Rekening::find($rekeningId) : null;

        if (! $rekening) {
            return ['ok' => false, 'pesan' => 'Rekening sumber (Simpanan Sukarela) tidak ditemukan.'];
        }

        $dipotong = $this->tazir->prosesAutodebetSusulan($rekening, $userId, (int) $tunggakan->id, true);

        return [
            'ok' => $dipotong > 0,
            'pesan' => $dipotong > 0
                ? "Auto-debit ta'zir susulan dikonfirmasi & dipotong."
                : "Pemotongan ta'zir gagal (saldo tidak mencukupi).",
        ];
    }

    /* =====================================================================
     | 4. UTILITAS
     |===================================================================== */

    private function gagalkan(AutodebitUsulan $usulan, int $userId, ?string $catatan, string $pesan): array
    {
        $usulan->update([
            'status' => AutodebitUsulan::STATUS_GAGAL,
            'confirmed_by' => $userId,
            'confirmed_at' => now(),
            'confirmation_note' => trim(($catatan ?? '').' | '.$pesan),
        ]);

        Log::warning('AutodebitService: konfirmasi GAGAL, tidak ada dana dipotong.', [
            'usulan_id' => $usulan->id,
            'pesan' => $pesan,
        ]);

        return ['ok' => false, 'pesan' => $pesan, 'usulan' => $usulan->refresh()];
    }

    /**
     * Kirim notifikasi ke pengguna berwenang saat ada usulan baru.
     *
     * Penerima DITURUNKAN dari `Izin::peran('autodebit.konfirmasi')` — sumber
     * yang sama dengan penjaga rute Pusat Konfirmasi. Dengan begitu mustahil
     * ada peran yang menerima notifikasi "menunggu konfirmasi Anda" tetapi
     * ditolak 403 saat membuka tautannya (A6).
     */
    private function notifikasiPengguna(AutodebitUsulan $usulan): void
    {
        try {
            $peran = Izin::peran('autodebit.konfirmasi');

            if ($peran === []) {
                return;
            }

            $penerima = User::whereIn('role', $peran)->get();
            if ($penerima->isNotEmpty()) {
                Notification::send($penerima, AutodebitUsulanNotification::dari($usulan));
            }
        } catch (\Throwable $e) {
            // Notifikasi tidak boleh menggagalkan pembuatan usulan.
            Log::notice('AutodebitService: notifikasi usulan gagal dikirim.', [
                'usulan_id' => $usulan->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Id rekening sumber dana (Simpanan Sukarela aktif) milik anggota. */
    private function rekeningSukarelaId(Anggota $anggota): ?int
    {
        return Rekening::where('anggota_id', $anggota->id)
            ->whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_SUKARELA))
            ->where('status_rekening', 'Aktif')
            ->value('id');
    }
}
