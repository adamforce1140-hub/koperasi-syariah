<?php

namespace App\Services;

use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * TAHAP 5 — Integritas periode akuntansi (cut-off tutup buku).
 *
 * MASALAH YANG DITUTUP
 * --------------------
 * Sebelum service ini ada, `tanggal_jurnal` / `waktu_transaksi` dapat diisi
 * tanggal mana pun (termasuk mundur ke periode yang sudah ditutup buku).
 * Akibatnya laporan keuangan periode yang sudah dilaporkan berubah setelah
 * fakta (mis. Neraca 2025 berubah karena jurnal bertanggal Desember 2025
 * dibuat pada Februari 2026), dan jurnal penutup periode lama menjadi
 * tidak konsisten (akun Pendapatan/Beban yang sudah di-nol-kan kembali
 * bermutasi).
 *
 * PENGECUALIAN YANG DISENGAJA
 * ---------------------------
 * Jurnal dengan `sumber = 'TutupBuku'` DIIZINKAN bertanggal <= cut-off:
 * justru jurnal itulah yang MENUTUP periode. Tanpa pengecualian ini proses
 * tutup buku tidak akan pernah bisa dijalankan setelah kebijakan cut-off
 * dipasang.
 *
 * SUMBER NILAI CUT-OFF
 * --------------------
 * Parameter kebijakan `tutup_buku_sampai` (tabel `kebijakan_koperasis`)
 * diakses lewat Setting::tutupBukuSampai() — zero-hardcoding: tidak ada
 * tanggal yang ditulis di kode.
 *   - `null` / kosong  -> periode terbuka, seluruh tanggal sah (default).
 *   - nilai tidak sah  -> diperlakukan `null` + dicatat ke log (fail-open
 *                         agar salah ketik kebijakan tidak melumpuhkan
 *                         operasional; keputusan ini didokumentasikan).
 */
class PeriodeAkuntansiService
{
    /** Sumber jurnal yang dikecualikan dari cut-off karena justru menutup periode. */
    public const SUMBER_DIKECUALIKAN = ['TutupBuku'];

    /**
     * Tanggal cut-off (Y-m-d) periode terakhir yang sudah ditutup, atau
     * `null` bila periode masih terbuka.
     */
    public function cutOff(): ?string
    {
        return Setting::tutupBukuSampai();
    }

    /** Apakah cut-off periode aktif? */
    public function aktif(): bool
    {
        return $this->cutOff() !== null;
    }

    /**
     * Apakah tanggal tertentu berada pada periode yang SUDAH DITUTUP?
     * Tanggal kosong dianggap periode terbuka (tidak diblokir) supaya
     * pemanggil yang belum mengirim tanggal tidak gagal hanya karena cut-off.
     */
    public function sudahDitutup($tanggal, ?string $sumber = null): bool
    {
        $cutOff = $this->cutOff();

        if ($cutOff === null) {
            // Parameter `periode_cutoff_mode` menentukan perilaku saat nilai
            // kebijakan ADA tetapi TIDAK DAPAT DIURAI:
            //   - fail_open   (default) → periode terbuka, posting diizinkan.
            //   - fail_closed           → periode dianggap tertutup, posting
            //                             DITOLAK demi integritas laporan.
            if (Setting::tutupBukuSampaiTidakValid() && Setting::periodeCutoffMode() === 'fail_closed') {
                return $tanggal !== null && $tanggal !== '';
            }

            return false;
        }

        if ($tanggal === null || $tanggal === '') {
            return false;
        }

        if ($sumber !== null && in_array($sumber, self::SUMBER_DIKECUALIKAN, true)) {
            return false;
        }

        try {
            $tgl = $tanggal instanceof \DateTimeInterface
                ? Carbon::instance($tanggal)->toDateString()
                : Carbon::parse((string) $tanggal)->toDateString();
        } catch (\Throwable $e) {
            // Tanggal tidak dapat diurai → biarkan validasi format tanggung
            // jawab pemanggil (rule `date`), bukan diblokir di sini.
            // Dicatat pada level debug: gerbang ini fail-open secara sengaja,
            // tetapi jejaknya tetap diperlukan saat menelusuri tanggal tak sah
            // yang lolos ke posting.
            Log::debug('PeriodeAkuntansiService: tanggal tidak dapat diurai, gerbang cut-off dilewati.', [
                'tanggal' => is_scalar($tanggal) ? (string) $tanggal : gettype($tanggal),
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return $tgl <= $cutOff;
    }

    /**
     * Pesan penolakan yang konsisten & informatif untuk seluruh modul.
     */
    public function pesanPenolakan($tanggal): string
    {
        $tgl = $tanggal instanceof \DateTimeInterface
            ? Carbon::instance($tanggal)->toDateString()
            : (string) $tanggal;
        $cutOff = (string) $this->cutOff();

        return "Periode akuntansi sampai {$cutOff} sudah ditutup buku, sehingga tanggal {$tgl} tidak dapat dipakai lagi. "
             .'Gunakan tanggal setelah periode tutup buku, atau batalkan tutup buku terlebih dahulu.';
    }

    /**
     * Gerbang tunggal untuk seluruh jalur posting jurnal.
     *
     * @param  mixed  $tanggal  tanggal jurnal (`tanggal_jurnal`)
     * @param  string  $sumber  sumber jurnal (mis. 'TutupBuku')
     * @param  string  $konteks  label modul untuk log (mis. 'AutoJournalingService')
     *
     * @throws \RuntimeException bila tanggal berada di periode yang sudah ditutup
     */
    public function pastikanDapatDiposting($tanggal, string $sumber = '', string $konteks = ''): void
    {
        if (! $this->sudahDitutup($tanggal, $sumber)) {
            return;
        }

        $pesan = $this->pesanPenolakan($tanggal);

        Log::warning('PeriodeAkuntansiService: posting jurnal ditolak karena periode sudah ditutup.', [
            'konteks' => $konteks,
            'sumber' => $sumber,
            'tanggal' => (string) $tanggal,
            'cut_off' => $this->cutOff(),
        ]);

        throw new \RuntimeException($pesan);
    }

    /**
     * Validator bentuk `Rule` untuk dipakai di `$request->validate([...])`.
     * Mengembalikan closure yang menerima (attribute, value, fail).
     *
     * @param  string  $sumber  sumber jurnal bila relevan (mis. 'TutupBuku')
     */
    public function rule($sumber = ''): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($sumber) {
            if ($this->sudahDitutup($value, $sumber)) {
                $fail($this->pesanPenolakan($value));
            }
        };
    }
}
