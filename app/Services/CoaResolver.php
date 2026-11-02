<?php

namespace App\Services;

use App\Contracts\CoaResolverInterface;
use App\Models\Coa;
use App\Models\PemetaanAkadCoa;
use App\Support\Setting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Resolver pemetaan akun CoA modul Pencairan Pembiayaan (ZERO-HARDCODING).
 *
 * Satu-satunya tempat yang menerjemahkan "akun apa yang dipakai" menjadi KODE
 * akun CoA. Urutan resolusi:
 *
 *   1. Pemetaan akad berbasis DATA (`pemetaan_akad_coa`) — diisi pengurus
 *      lewat menu Kebijakan Koperasi, sehingga akad baru dapat dipetakan ke
 *      akun mana pun TANPA mengubah kode sumber.
 *   2. Pemetaan dinamis dari Kebijakan Koperasi (`kebijakan_koperasis`),
 *      kolom `account_*_id`.
 *   3. Bila kosong / menunjuk akun yang tidak ada atau nonaktif → fallback ke
 *      registri standar App\Support\Coa.
 *
 * Dengan pola ini tidak ada satu pun nomor akun yang ditanam di Controller /
 * Service pencairan, dan pengurus dapat memindahkan akun tanpa deploy ulang.
 */
class CoaResolver implements CoaResolverInterface
{
    /** Cache ringan per-request agar tidak query berulang dalam satu transaksi. */
    private array $cacheKodeById = [];

    /**
     * Kode akun piutang/penyertaan sesuai akad pembiayaan.
     *
     * Akad Mudharabah & Musyarakah memakai akun INVESTASI bila pengurus
     * mengisinya (koperasi yang menyajikan penyertaan, bukan piutang);
     * bila tidak, jatuh ke akun piutang akad tersebut.
     */
    public function kodePiutangByAkad(?string $akad, string $kodeProduk = PemetaanAkadCoa::SEMUA_PRODUK): string
    {
        // 1. Pemetaan berbasis data (akad baru dapat dipetakan tanpa deploy).
        $kode = $this->kodeDariPemetaan($akad, 'account_piutang_id', $kodeProduk);

        if ($kode !== null) {
            return $kode;
        }

        // 2. Pemetaan dinamis dari Kebijakan Koperasi.
        $kunci = $this->kunciAkad($akad);

        $kandidatSetting = match ($kunci) {
            'murabahah', 'murabaha' => ['account_piutang_murabahah_id'],
            'mudharabah', 'mudarabah' => ['account_investasi_mudharabah_id', 'account_piutang_mudharabah_id'],
            'musyarakah', 'musyarakat', 'musharakah' => ['account_investasi_musyarakah_id', 'account_piutang_musyarakah_id'],
            'ijarah', 'ijaroh' => ['account_piutang_ijarah_id'],
            'qardh', 'qard', 'qardhul hasan', 'qard al hasan' => ['account_piutang_qardh_id'],
            default => ['account_piutang_murabahah_id'],
        };

        foreach ($kandidatSetting as $setting) {
            $kode = $this->kodeDariSetting($setting);

            if ($kode !== null) {
                return $kode;
            }
        }

        // 3. Registri standar.
        return \App\Support\Coa::piutangByAkad($akad);
    }

    /**
     * Kode akun pendapatan (margin/bagi hasil/ujrah) sesuai akad pembiayaan;
     * null bila akad memang tidak menghasilkan pendapatan (Qardh).
     */
    public function kodePendapatanByAkad(?string $akad, string $kodeProduk = PemetaanAkadCoa::SEMUA_PRODUK): ?string
    {
        $kode = $this->kodeDariPemetaan($akad, 'account_pendapatan_id', $kodeProduk);

        if ($kode !== null) {
            return $kode;
        }

        return \App\Support\Coa::pendapatanByAkad($akad);
    }

    /**
     * Kode akun Margin Ditangguhkan untuk akad tertentu (null bila akad tidak
     * memiliki margin ditangguhkan — bagi hasil / sewa / Qardh).
     */
    public function kodeMarginDitangguhkanByAkad(?string $akad, string $kodeProduk = PemetaanAkadCoa::SEMUA_PRODUK): ?string
    {
        // Pemetaan pengurus menang lebih dulu: bila ia secara eksplisit
        // mengarahkan akad ini ke akun margin ditangguhkan, hormati keputusan itu.
        $kode = $this->kodeDariPemetaan($akad, 'account_margin_ditangguhkan_id', $kodeProduk);

        if ($kode !== null) {
            return $kode;
        }

        $standar = \App\Support\Coa::marginDitangguhkanByAkad($akad);

        if ($standar === null) {
            return null;
        }

        return $this->kodeDariSetting('account_margin_deferred_id') ?? $standar;
    }

    /**
     * Kode akun CoA penampung Dana Zakat (PSAK 109).
     *
     * Diarahkan lewat parameter kebijakan `account_dana_zakat_id`; bila kosong
     * atau akunnya tidak ada/nonaktif → fallback registri standar 2-3002.
     * Dipakai bersama oleh pencatatan modul zakat DAN penyajian DIT sehingga
     * keduanya SELALU menunjuk akun yang sama (mencegah divergensi laporan).
     */
    public function kodeDanaZakat(): string
    {
        return $this->kodeDariSetting('account_dana_zakat_id') ?? \App\Support\Coa::DANA_ZAKAT;
    }

    /**
     * Kode akun CoA penampung Dana Infak & Sedekah (PSAK 109).
     *
     * Diarahkan lewat parameter kebijakan `account_dana_infak_id`; fallback
     * registri standar 2-3004. Lihat {@see kodeDanaZakat()} untuk alasan
     * resolusi bersama.
     */
    public function kodeDanaInfakSedekah(): string
    {
        return $this->kodeDariSetting('account_dana_infak_id') ?? \App\Support\Coa::DANA_INFAK_SEDEKAH;
    }

    /** Kode akun Kas Teller (sumber dana pencairan TUNAI). */
    public function kodeKasTeller(): string
    {
        return $this->kodeDariSetting('account_kas_teller_id') ?? \App\Support\Coa::KAS_TELLER;
    }

    /** Kode akun Bank operasional utama koperasi. */
    public function kodeBankKoperasi(): string
    {
        return $this->kodeDariSetting('account_bank_koperasi_id') ?? \App\Support\Coa::BANK_KOPERASI;
    }

    /** Kode akun Simpanan Sukarela (tujuan pindah buku non-tunai). */
    public function kodeSimpananSukarela(): string
    {
        return $this->kodeDariSetting('account_simpanan_sukarela_id') ?? \App\Support\Coa::SIMPANAN_SUKARELA;
    }

    /**
     * Kode akun sumber dana berdasarkan metode pencairan.
     *
     * @param  int|null  $bankAccountId  ID CoA bank terpilih (metode TRANSFER_BANK).
     */
    public function kodeSumberDanaByMetode(string $metode, ?int $bankAccountId = null): string
    {
        return match (strtoupper($metode)) {
            'TRANSFER_BANK' => $this->kodeBankTerpilih($bankAccountId) ?? $this->kodeBankKoperasi(),
            'TRANSFER_SAVINGS' => $this->kodeSimpananSukarela(),
            default => $this->kodeKasTeller(),
        };
    }

    /**
     * Daftar rekening bank operasional koperasi yang AKTIF — sumber data
     * dropdown dinamis pada form pencairan (tidak ada daftar bank di kode).
     *
     * @return Collection<int, Coa>
     */
    public function daftarBankOperasional(): Collection
    {
        return Coa::query()
            ->where('is_active', true)
            ->where('kategori', 'Aset')
            ->where(function ($q) {
                $q->where('nama_akun', 'like', '%Bank%')
                    ->orWhere('nama_akun', 'like', '%Rekening%');
            })
            ->orderBy('kode_akun')
            ->get();
    }

    /**
     * Kode akun CoA bank yang dipilih berdasarkan ID (null bila tidak ada /
     * nonaktif / bukan akun aset).
     */
    public function kodeBankTerpilih(?int $coaId): ?string
    {
        if ($coaId === null || $coaId <= 0) {
            return null;
        }

        return $this->kodeDariId($coaId);
    }

    /* ── Internal ─────────────────────────────────────────────────────── */

    /**
     * Kode akun dari pemetaan akad berbasis data (`pemetaan_akad_coa`).
     *
     * Mengembalikan null bila belum ada baris, kolomnya kosong, atau akun yang
     * ditunjuk tidak ada/nonaktif — sehingga pemanggil dapat melanjutkan ke
     * pemetaan kebijakan lalu registri standar.
     */
    private function kodeDariPemetaan(?string $akad, string $kolom, string $kodeProduk): ?string
    {
        if ($akad === null || trim($akad) === '') {
            return null;
        }

        $id = PemetaanAkadCoa::untukAkad($akad, $kodeProduk)->getAttribute($kolom);

        if ($id === null) {
            return null;
        }

        $kode = $this->kodeDariId((int) $id);

        if ($kode === null) {
            Log::warning("CoaResolver: pemetaan akad [{$akad}] kolom [{$kolom}] menunjuk CoA id={$id} yang tidak ada/nonaktif — memakai pemetaan standar.", [
                'akad' => $akad,
                'kolom' => $kolom,
                'coa_id' => $id,
            ]);
        }

        return $kode;
    }

    /**
     * Kode akun dari sebuah kolom setting `account_*_id`.
     * Mengembalikan null (dan mencatat peringatan) bila akun tidak layak pakai
     * sehingga pemanggil dapat melakukan fallback dengan aman.
     */
    private function kodeDariSetting(string $setting): ?string
    {
        $id = Setting::akunId($setting);

        if ($id === null) {
            return null;
        }

        $kode = $this->kodeDariId($id);

        if ($kode === null) {
            Log::warning("CoaResolver: setting [{$setting}] menunjuk CoA id={$id} yang tidak ada/nonaktif — memakai pemetaan standar.", [
                'setting' => $setting,
                'coa_id' => $id,
            ]);
        }

        return $kode;
    }

    /** Kode akun CoA aktif berdasarkan ID (ter-cache per instance). */
    private function kodeDariId(int $coaId): ?string
    {
        if (array_key_exists($coaId, $this->cacheKodeById)) {
            return $this->cacheKodeById[$coaId];
        }

        $coa = Coa::query()->where('id', $coaId)->where('is_active', true)->first();

        return $this->cacheKodeById[$coaId] = $coa?->kode_akun;
    }

    /** Normalisasi kunci akad (huruf kecil, spasi tunggal). */
    private function kunciAkad(?string $akad): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', (string) $akad) ?? ''));
    }
}
