<?php

namespace App\Contracts;

use App\Models\Coa;
use Illuminate\Database\Eloquent\Collection;

/**
 * KONTRAK — RESOLVER AKUN CoA (ZERO-HARDCODING akun).
 *
 * Satu-satunya antarmuka yang menerjemahkan konsep bisnis ("akun piutang untuk
 * akad murabahah", "bank operasional mana") menjadi KODE akun CoA. Pemanggil
 * (controller/service pencairan, jurnal otomatis) tidak boleh menanam nomor
 * akun di kode; semuanya melalui resolver ini.
 *
 * Implementasi bawaan: `App\Services\CoaResolver` (pemetaan berbasis data →
 * kebijakan koperasi → fallback registri standar `App\Support\Coa`).
 */
interface CoaResolverInterface
{
    /**
     * Kode akun piutang/penyertaan sesuai akad pembiayaan.
     */
    public function kodePiutangByAkad(?string $akad, string $kodeProduk = '*'): string;

    /**
     * Kode akun pendapatan margin/bagi hasil sesuai akad (null bila tak ada).
     */
    public function kodePendapatanByAkad(?string $akad, string $kodeProduk = '*'): ?string;

    /**
     * Kode akun margin ditangguhkan sesuai akad (null bila tak ada).
     */
    public function kodeMarginDitangguhkanByAkad(?string $akad, string $kodeProduk = '*'): ?string;

    /**
     * Kode akun kas di teller.
     */
    public function kodeKasTeller(): string;

    /**
     * Kode akun bank / rekening utama koperasi.
     */
    public function kodeBankKoperasi(): string;

    /**
     * Kode akun simpanan sukarela (wadiah) anggota.
     */
    public function kodeSimpananSukarela(): string;

    /**
     * Kode akun sumber dana berdasarkan metode penerimaan/pembayaran.
     */
    public function kodeSumberDanaByMetode(string $metode, ?int $bankAccountId = null): string;

    /**
     * Daftar rekening bank operasional koperasi yang aktif (dropdown dinamis).
     *
     * @return Collection<int, Coa>
     */
    public function daftarBankOperasional(): Collection;

    /**
     * Kode akun CoA bank yang dipilih lewat ID (null bila tidak sah/nonaktif).
     */
    public function kodeBankTerpilih(?int $coaId): ?string;
}
