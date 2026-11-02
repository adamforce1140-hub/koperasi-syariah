<?php

namespace App\Services\Jurnal;

use App\Models\Coa;
use App\Models\Jurnal;
use App\Support\Coa as KodeCoa;
use Illuminate\Support\Facades\Log;

/**
 * RESEP JURNAL DOMAIN BEBAN OPERASIONAL & ALOKASI SHU.
 *
 * Seluruh method di sini hanya MENYUSUN baris jurnal (resep), lalu
 * menyerahkannya ke `PostingJurnal` — mesin inti yang menjadi satu-satunya
 * gerbang validasi, keseimbangan, cut-off periode, dan penulisan buku besar.
 *
 * Pemisahan domain ini behavior-preserving: `AutoJournalingService` tetap
 * menyediakan method yang sama dan mendelegasikannya ke kelas ini.
 */
class JournalBebanShu
{
    /**
     * Pemetaan JENIS BEBAN OPERASIONAL -> kode akun CoA spesifik.
     *
     * Dipakai form input & pembuatan jurnal agar setiap jenis pengeluaran
     * tercatat pada akun yang tepat, bukan menumpuk di satu akun general.
     * Jenis 'gaji' memakai 5-1001 yang sekaligus berperan sebagai akun
     * general/cadangan bila kode akun spesifik lain belum tersedia.
     */
    public const JENIS_BEBAN = [
        'gaji' => ['kode' => KodeCoa::BEBAN_OPERASIONAL, 'label' => 'Beban Gaji & Tunjangan'],
        'atk' => ['kode' => KodeCoa::BEBAN_ATK,         'label' => 'Beban ATK & Perlengkapan'],
        'utilitas' => ['kode' => KodeCoa::BEBAN_UTILITAS,    'label' => 'Beban Utilitas / Listrik'],
    ];

    public function __construct(private readonly PostingJurnal $mesin) {}

    /**
     * Biaya admin rekening/transaksi.
     *
     * DEBET : 1-1001 Kas Teller
     * KREDIT: 4-1005 Pendapatan Admin
     */
    public function jurnalBiayaAdmin(
        float $nominal,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null
    ): ?Jurnal {
        return $this->mesin->post($keterangan, [
            ['kode' => KodeCoa::KAS_TELLER,       'debet' => $nominal],
            ['kode' => KodeCoa::PENDAPATAN_ADMIN, 'kredit' => $nominal],
        ], 'Biaya Admin', $transaksiId, null, $userId);
    }

    /**
     * Pengeluaran Beban Operasional (double-entry berpasangan).
     *
     * DEBET : Akun Beban terpilih (mis. 5-1001) -> saldo beban bertambah
     * KREDIT: Sumber Kas/Bank terpilih (1-1001 / 1-1002) -> saldo kas berkurang
     */
    public function jurnalPengeluaranBeban(
        float $nominal,
        string $kodeAkunBeban,
        string $kodeAkunKas,
        string $keterangan,
        ?int $userId = null,
        ?string $noRef = null
    ): ?Jurnal {
        // Resolusi akun beban: bila kode spesifik tidak ada/nonaktif di master CoA,
        // otomatis jatuh ke akun beban general (5-1001) agar proses tidak gagal.
        $kodeAkunBeban = $this->kodeAkunBebanTerpakai($kodeAkunBeban, $keterangan);

        $jurnal = $this->mesin->post($keterangan, [
            ['kode' => $kodeAkunBeban, 'debet' => $nominal],
            ['kode' => $kodeAkunKas,   'kredit' => $nominal],
        ], 'Pengeluaran Beban', null, null, $userId);

        if ($jurnal && $noRef) {
            $jurnal->update(['no_ref' => $noRef]);
        }

        return $jurnal;
    }

    /**
     * Kode akun beban yang benar-benar dipakai untuk posting jurnal.
     *
     * Error handling master CoA: akun beban spesifik (5-1002/5-1003) bisa saja
     * belum ter-seed atau dinonaktifkan. Bila demikian, pengeluaran TIDAK boleh
     * gagal — akun dialihkan ke Beban Operasional Koperasi (5-1001) sebagai
     * akun general, dan pengalihan dicatat ke log untuk jejak audit.
     */
    public function kodeAkunBebanTerpakai(string $kodeAkunBeban, ?string $konteks = null): string
    {
        $kodeAkunBeban = trim($kodeAkunBeban);

        if ($kodeAkunBeban !== '' && Coa::findByKode($kodeAkunBeban)) {
            return $kodeAkunBeban;
        }

        // Akun general sendiri wajib ada; bila tidak, biarkan `post()` yang
        // membatalkan jurnal dengan warning (perilaku lama tetap terjaga).
        if (! Coa::findByKode(KodeCoa::BEBAN_OPERASIONAL)) {
            Log::error('JournalBebanShu: akun beban general '.KodeCoa::BEBAN_OPERASIONAL.' tidak ditemukan di master CoA.', [
                'kode_diminta' => $kodeAkunBeban,
                'konteks' => $konteks,
            ]);

            return $kodeAkunBeban;
        }

        Log::warning('JournalBebanShu: akun beban spesifik tidak ditemukan/nonaktif — dialihkan ke akun general '.KodeCoa::BEBAN_OPERASIONAL.'.', [
            'kode_diminta' => $kodeAkunBeban,
            'konteks' => $konteks,
        ]);

        return KodeCoa::BEBAN_OPERASIONAL;
    }

    /**
     * Resolusi JENIS BEBAN OPERASIONAL (gaji/atk/utilitas) -> kode akun CoA.
     *
     * Selalu melalui `kodeAkunBebanTerpakai()` sehingga jaminan fallback ke
     * akun general berlaku untuk seluruh jalur input.
     */
    public function kodeAkunBebanByJenis(?string $jenisBeban, ?string $konteks = null): string
    {
        $jenis = strtolower(trim((string) $jenisBeban));

        if (array_key_exists($jenis, self::JENIS_BEBAN)) {
            return $this->kodeAkunBebanTerpakai(self::JENIS_BEBAN[$jenis]['kode'], $konteks);
        }

        // Jenis tidak dikenal / kosong -> akun general (bukan kegagalan proses).
        return $this->kodeAkunBebanTerpakai(KodeCoa::BEBAN_OPERASIONAL, $konteks);
    }

    /**
     * Alokasi SHU ke Dana Cadangan Koperasi.
     *
     * DEBET : 3-9000 (Ikhtisar SHU / Laba Ditahan)
     * KREDIT: 3-2001 (Dana Cadangan Koperasi)
     * Hanya boleh dipanggil oleh Pengurus.
     */
    public function jurnalAlokasiShu(
        float $nominal,
        string $keterangan,
        ?int $userId = null
    ): ?Jurnal {
        return $this->mesin->post($keterangan, [
            ['kode' => KodeCoa::IKHTISAR_SHU,      'debet' => $nominal],
            ['kode' => KodeCoa::CADANGAN_KOPERASI, 'kredit' => $nominal],
        ], 'Alokasi SHU ke Cadangan', null, null, $userId);
    }
}
