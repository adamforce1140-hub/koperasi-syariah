<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * JournalPostingException — kegagalan VALIDASI posting jurnal.
 *
 * DILEMPAR oleh `AutoJournalingService::post()` ketika jurnal baru tidak lolos
 * salah satu gerbang validasi (CoA tidak ditemukan, CoA nonaktif, tidak balance,
 * pelanggaran aturan PSAK 108/109, atau status transaksi belum sukses).
 *
 * MENGAPA MELEMPAR (dan bukan lagi mengembalikan `null` diam-diam):
 *
 * Sebelumnya `post()` mengembalikan `null` tanpa exception ketika validasi
 * gagal. Konsekuensinya transaksi pemanggil TIDAK otomatis di-rollback,
 * sehingga data transaksi bisa tersimpan meskipun jurnalnya tidak pernah
 * terbentuk — saldo kas bertambah tanpa pengakuan piutang, dan Neraca menjadi
 * tidak seimbang. Dengan melempar exception, setiap `DB::transaction` pemanggil
 * ikut rollback secara atomik, memenuhi prinsip "hanya mencatat transaksi yang
 * telah berhasil diproses sepenuhnya".
 *
 * Kelas ini mewarisi `RuntimeException` — jenis yang sudah ditangkap secara
 * aman oleh `pastikanBalance()`, `PeriodeAkuntansiService`, dan handler
 * exception terpusat (`bootstrap/app.php`) — sehingga kompatibel dengan seluruh
 * guard `catch (\RuntimeException $e)` dan `if (! $jurnal)` yang sudah ada.
 *
 * Membawa properti terstruktur (`sumber`, `transaksiId`, `aturan`, `konteks`)
 * agar logger dapat menelusuri alur transaksi secara terperinci tanpa perlu
 * mem-parsing pesan teks.
 */
class JournalPostingException extends RuntimeException
{
    /** Kode aturan yang dilanggar, mis. `COA_TIDAK_DITEMUKAN`. */
    public readonly string $aturan;

    /** Sumber jurnal (penanda jenis transaksi), mis. `Angsuran`. */
    public readonly string $sumber;

    /** Konteks tambahan untuk logging terperinci (bebas struktur). */
    public readonly array $konteks;

    /** ID transaksi terkait (bila ada) — untuk melacak alur transaksi. */
    public readonly ?int $transaksiId;

    public function __construct(
        string $message,
        string $aturan = 'VALIDASI_POSTING',
        string $sumber = '',
        ?int $transaksiId = null,
        array $konteks = [],
    ) {
        parent::__construct($message);

        $this->aturan = $aturan;
        $this->sumber = $sumber;
        $this->transaksiId = $transaksiId;
        $this->konteks = $konteks;
    }
}
