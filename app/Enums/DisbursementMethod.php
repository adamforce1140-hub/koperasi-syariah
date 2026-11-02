<?php

namespace App\Enums;

/**
 * Metode pencairan (dropping) pembiayaan.
 *
 * Tiga skema yang didukung sistem — semuanya bermuara pada jurnal PSAK 108
 * yang sama (DEBET Piutang/Investasi sebesar Pokok+Margin, KREDIT sumber dana
 * sebesar Pokok, dan KREDIT Margin Ditangguhkan sebesar Margin untuk akad
 * Murabahah). Perbedaannya hanya pada sisi KREDIT sumber dana dan pada
 * buku pembantu (teller / bank / simpanan) yang ikut tercatat:
 *
 *  - CASH             : uang fisik keluar dari Laci Teller (kas 1-1001).
 *  - TRANSFER_BANK    : transfer keluar dari rekening bank operasional koperasi.
 *  - TRANSFER_SAVINGS : pindah buku ke rekening Simpanan Sukarela anggota.
 *
 * Dipakai sebagai cast Eloquent (`Pembiayaan::$casts`) sehingga nilai kolom
 * selalu tervalidasi ketat saat dibaca — nilai di luar daftar ini akan ditolak.
 */
enum DisbursementMethod: string
{
    case CASH = 'CASH';
    case TRANSFER_BANK = 'TRANSFER_BANK';
    case TRANSFER_SAVINGS = 'TRANSFER_SAVINGS';

    /** Label manusiawi untuk dropdown / tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::CASH => 'Tunai (Laci Teller)',
            self::TRANSFER_BANK => 'Transfer Bank Operasional',
            self::TRANSFER_SAVINGS => 'Pindah Buku Simpanan Sukarela',
        };
    }

    /** Apakah metode ini mengeluarkan uang fisik dari Laci Teller? */
    public function mengeluarkanKasFisik(): bool
    {
        return $this === self::CASH;
    }

    /** Apakah metode ini memerlukan referensi rekening bank operasional? */
    public function memerlukanRekeningBank(): bool
    {
        return $this === self::TRANSFER_BANK;
    }

    /** Apakah metode ini memerlukan referensi rekening simpanan anggota? */
    public function memerlukanRekeningSimpanan(): bool
    {
        return $this === self::TRANSFER_SAVINGS;
    }

    /**
     * Daftar nilai enum (untuk aturan validasi Rule::in()).
     *
     * @return array<int, string>
     */
    public static function nilai(): array
    {
        return array_map(static fn (self $m): string => $m->value, self::cases());
    }

    /**
     * Peta nilai => label untuk dropdown form.
     *
     * @return array<string, string>
     */
    public static function pilihan(): array
    {
        $pilihan = [];

        foreach (self::cases() as $metode) {
            $pilihan[$metode->value] = $metode->label();
        }

        return $pilihan;
    }

    /** Nilai default bila kebijakan belum menetapkan metode lain. */
    public static function bawaan(): self
    {
        return self::CASH;
    }
}
