<?php

namespace App\Support;

use App\Models\Anggota;
use App\Models\AuditLog;
use App\Models\JenisSimpanan;
use App\Models\Rekening;
use App\Services\ExitKeanggotaanService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * GUARD TERPUSAT — LARANGAN PENARIKAN SIMPANAN POKOK & SIMPANAN WAJIB.
 *
 * Simpanan Pokok (CoA 3-1001) dan Simpanan Wajib (CoA 3-1002) adalah
 * MODAL/EKUITAS anggota koperasi syariah — bukan kewajiban jangka pendek yang
 * dapat ditarik kapan saja (PSAK 101 ¶ 31). Karena itu penarikan rutin pada
 * kedua jenis simpanan tersebut DILARANG selama anggota berstatus aktif.
 *
 * Pengembalian dana HANYA sah melalui modul `member_terminations`
 * (lihat {@see ExitKeanggotaanService}).
 *
 * Kelas ini adalah SATU-SATUNYA titik penegakan aturan tersebut di lapisan
 * backend. Seluruh endpoint penarikan (teller, simpanan wajib, dsb.) WAJIB
 * memanggil salah satu metode `pastikan*()` di sini SEBELUM memproses
 * transaksi, sehingga tidak ada jalur penarikan yang lolos.
 *
 * Setiap upaya pelanggaran dicatat secara TERPUSAT ke:
 *  - Laravel log  : channel default, event `PENARIKAN_MODAL_DIBLOKIR`
 *  - Tabel audit  : `audit_log` (jenis `PENARIKAN_MODAL_DIBLOKIR`)
 * untuk keperluan audit keamanan & kepatuhan.
 */
final class GuardSimpananModal
{
    /** Pesan standar penolakan (dipakai seluruh lapisan API & UI). */
    public const PESAN = 'Penarikan tunai tidak diizinkan untuk rekening Simpanan Pokok/Wajib, silakan gunakan modul pengunduran diri anggota untuk proses pengembalian dana';

    /** Kode akun Simpanan Sukarela — SATU-SATUNYA rekening yang boleh ditarik tunai. */
    public const KODE_AKUN_SUKARELA = Coa::SIMPANAN_SUKARELA;

    /** Jenis rekening (tabel `rekening`) yang boleh ditarik tunai harian. */
    public const JENIS_REKENING_DAPAT_DITARIK = [
        'Simpanan Sukarela',
    ];

    /** Kode akun modal anggota yang dilarang ditarik secara rutin. */
    public const KODE_AKUN_MODAL = [
        Coa::SIMPANAN_POKOK,
        Coa::SIMPANAN_WAJIB_MODAL,
    ];

    /** Jenis rekening (tabel `rekening`) yang setara dengan akun modal di atas. */
    public const JENIS_REKENING_MODAL = [
        'Simpanan Pokok',
        'Simpanan Wajib',
    ];

    /**
     * Daftar jenis rekening yang boleh ditarik tunai menurut master produk.
     *
     * Nama baku di {@see self::JENIS_REKENING_DAPAT_DITARIK} selalu ikut agar
     * data historis tetap dapat ditarik; produk bertipe sukarela yang aktif
     * di master ditambahkan sehingga penggantian nama produk langsung berlaku.
     *
     * @return list<string>
     */
    public static function jenisRekeningDapatDitarik(): array
    {
        return array_values(array_unique(array_merge(
            self::JENIS_REKENING_DAPAT_DITARIK,
            JenisSimpanan::aktifBertipe(JenisSimpanan::TIPE_SUKARELA)
        )));
    }

    /**
     * Daftar jenis rekening modal (pokok/wajib) menurut master produk.
     *
     * @return list<string>
     */
    public static function jenisRekeningModalList(): array
    {
        return array_values(array_unique(array_merge(
            self::JENIS_REKENING_MODAL,
            JenisSimpanan::aktifBertipe(JenisSimpanan::TIPE_POKOK),
            JenisSimpanan::aktifBertipe(JenisSimpanan::TIPE_WAJIB)
        )));
    }

    /* =====================================================================
     | PEMERIKSAAN (TANPA EFEK SAMPING)
     |===================================================================== */

    /**
     * Apakah jenis rekening termasuk Simpanan Pokok / Simpanan Wajib?
     * Perbandingan case-insensitive & toleran spasi.
     */
    public static function jenisRekeningModal(?string $jenisRekening): bool
    {
        return self::cocok($jenisRekening, self::jenisRekeningModalList());
    }

    /** Apakah kode akun (CoA) termasuk akun modal anggota yang dikunci? */
    public static function kodeAkunModal(?string $kodeAkun): bool
    {
        return self::cocok($kodeAkun, self::KODE_AKUN_MODAL);
    }

    /** Apakah rekening ini termasuk rekening modal yang dikunci? */
    public static function rekeningModal(?Rekening $rekening): bool
    {
        return $rekening !== null
            && self::jenisRekeningModal($rekening->jenis_rekening ?? null);
    }

    /* =====================================================================
     | PENEGAKAN (MELEMPAR EXCEPTION + MENCATAT AUDIT)
     |===================================================================== */

    /**
     * Tolak penarikan bila rekening target adalah Simpanan Pokok/Wajib.
     *
     * @throws RuntimeException
     */
    public static function pastikanRekeningDapatDitarik(?Rekening $rekening, string $konteks): void
    {
        if ($rekening === null) {
            return;
        }

        if (! self::rekeningModal($rekening)) {
            return;
        }

        self::blokir($konteks, [
            'rekening_id' => $rekening->id,
            'no_rekening' => $rekening->no_rekening,
            'jenis_rekening' => $rekening->jenis_rekening,
            'anggota_id' => $rekening->anggota_id,
        ]);
    }

    /**
     * ALLOWLIST KETAT penarikan tunai harian (Bagian 4 poin 1).
     *
     * Penarikan tunai harian HANYA diizinkan untuk rekening Simpanan Sukarela
     * (CoA 2-1001). Seluruh jenis rekening lain — terutama Simpanan Pokok
     * (3-1001) & Simpanan Wajib (3-1002) yang merupakan MODAL/EKUITAS anggota
     * (PSAK 101 ¶ 31) — DITOLAK dengan pesan standar {@see self::PESAN}.
     *
     * Pengembalian dana Simpanan Pokok/Wajib hanya sah melalui modul
     * Pengunduran Diri Anggota (`member_terminations`).
     *
     * @throws RuntimeException
     */
    public static function pastikanRekeningDapatDitarikTunai(?Rekening $rekening, string $konteks): void
    {
        if ($rekening === null) {
            return;
        }

        $jenis = (string) ($rekening->jenis_rekening ?? '');

        if (self::cocok($jenis, self::jenisRekeningDapatDitarik())) {
            return;
        }

        self::blokir($konteks, [
            'rekening_id' => $rekening->id,
            'no_rekening' => $rekening->no_rekening,
            'jenis_rekening' => $rekening->jenis_rekening,
            'anggota_id' => $rekening->anggota_id,
            'alasan' => 'Penarikan tunai harian hanya untuk Simpanan Sukarela (CoA '
                .self::KODE_AKUN_SUKARELA.').',
        ]);
    }

    /**
     * Apakah rekening Simpanan Sukarela (satu-satunya yang dapat ditarik tunai)?
     */
    public static function rekeningDapatDitarikTunai(?Rekening $rekening): bool
    {
        return $rekening !== null
            && self::cocok((string) ($rekening->jenis_rekening ?? ''), self::jenisRekeningDapatDitarik());
    }

    /**
     * Tolak penarikan berdasarkan KODE AKUN (CoA) — untuk jalur yang tidak
     * melalui baris `rekening` (mis. tarik berbasis anggota + akun simpanan).
     *
     * @throws RuntimeException
     */
    public static function pastikanAkunDapatDitarik(?string $kodeAkun, array $konteksData = [], string $konteks = ''): void
    {
        if (! self::kodeAkunModal($kodeAkun)) {
            return;
        }

        self::blokir($konteks, array_merge(['kode_akun' => $kodeAkun], $konteksData));
    }

    /**
     * Tolak penarikan atas nama anggota bila anggota tersebut memiliki
     * rekening Simpanan Pokok/Wajib yang aktif — dipakai pada endpoint yang
     * hanya menerima parameter anggota (tanpa rekening_id eksplisit).
     *
     * @throws RuntimeException
     */
    public static function pastikanAnggotaTanpaRekeningModal(Anggota $anggota, string $konteks): void
    {
        $rekening = Rekening::query()
            ->where('anggota_id', $anggota->id)
            ->whereIn('jenis_rekening', self::jenisRekeningModalList())
            ->first();

        if ($rekening === null) {
            return;
        }

        self::blokir($konteks, [
            'anggota_id' => $anggota->id,
            'no_anggota' => $anggota->no_anggota ?? null,
            'rekening_id' => $rekening->id,
            'no_rekening' => $rekening->no_rekening,
            'jenis_rekening' => $rekening->jenis_rekening,
        ]);
    }

    /**
     * GEMBOK PERMANEN — tolak SETIAP penarikan rutin saldo Simpanan Wajib
     * (CoA 3-1002) atas nama anggota, TANPA SYARAT.
     *
     * Berbeda dari {@see pastikanAnggotaTanpaRekeningModal()} yang bergantung
     * pada keberadaan baris `rekening` jenis modal, gembok ini tidak
     * memeriksa apa pun: seluruh jalur penarikan rutin Simpanan Wajib
     * ditutup permanen, apa pun status anggota, saklar kebijakan, maupun
     * konfigurasi akunnya. Tidak ada celah "data tanpa rekening modal" yang
     * bisa melewatinya.
     *
     * Pengembalian dana HANYA sah melalui modul `member_terminations`
     * (PSAK 108) — lihat {@see ExitKeanggotaanService}.
     *
     * @throws RuntimeException
     */
    public static function pastikanTidakDitarikRutin(Anggota $anggota, string $konteks): void
    {
        self::blokir($konteks, [
            'anggota_id' => $anggota->id,
            'no_anggota' => $anggota->no_anggota ?? null,
        ]);
    }

    /* =====================================================================
     | INTERNAL
     |===================================================================== */

    /**
     * Catat upaya penarikan yang diblokir (log terpusat + audit trail) lalu
     * lempar RuntimeException dengan pesan standar.
     *
     * @throws RuntimeException
     */
    private static function blokir(string $konteks, array $data): void
    {
        $konteks = $konteks !== '' ? $konteks : 'Penarikan Simpanan';

        Log::warning('PENARIKAN_MODAL_DIBLOKIR', array_merge([
            'konteks' => $konteks,
            'pesan' => self::PESAN,
            'user_id' => auth()->id(),
            'ip' => request()->ip(),
        ], $data));

        try {
            AuditLog::record(
                'PENARIKAN_MODAL_DIBLOKIR',
                0,
                $data['no_rekening'] ?? null,
                sprintf('%s diblokir pada [%s]. %s', 'Penarikan Simpanan Pokok/Wajib', $konteks, self::PESAN)
            );
        } catch (\Throwable $e) {
            // Audit tidak boleh menggagalkan penolakan itu sendiri.
            Log::error('PENARIKAN_MODAL_DIBLOKIR: gagal menulis audit_log.', [
                'pesan' => $e->getMessage(),
            ]);
        }

        throw new RuntimeException(self::PESAN);
    }

    /** Pencocokan case-insensitive terhadap daftar nilai yang dikunci. */
    private static function cocok(?string $nilai, array $daftar): bool
    {
        if ($nilai === null || trim($nilai) === '') {
            return false;
        }

        $nilai = mb_strtolower(trim($nilai));

        foreach ($daftar as $item) {
            if ($nilai === mb_strtolower($item)) {
                return true;
            }
        }

        return false;
    }
}
