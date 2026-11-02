<?php

namespace App\Services;

use App\Models\HariLibur;
use App\Support\Setting;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Penjadwalan tanggal jatuh tempo angsuran dengan kalender hari libur.
 *
 * Aturan koperasi (seluruhnya dinamis, tanpa hardcode):
 *   - Tanggal jatuh tempo bulanan mengikuti `Setting::tanggalJatuhTempoDefault()`.
 *   - Bila jatuh pada Sabtu/Minggu atau terdaftar di tabel `hari_liburs`,
 *     tanggal digeser ke hari kerja terdekat.
 *   - Arah penggeseran mengikuti `Setting::hariLiburStrategi()`:
 *       MAJU   → hari kerja berikutnya (default), atau
 *       MUNDUR → hari kerja sebelumnya.
 *   - Bila `Setting::hariLiburAktif()` = false, penyesuaian dilewati.
 *
 * Sumber hari libur adalah tabel `hari_liburs` (diisi HariLiburSeeder dan
 * dapat dipelihara pengurus) — bukan daftar tanggal yang ditanam di kode.
 */
class HariLiburService
{
    /** Batas maksimum iterasi penggeseran tanggal (pengaman loop). */
    private const BATAS_GESER_HARI = 31;

    /**
     * Apakah tanggal merupakan hari kerja koperasi?
     * (bukan Sabtu/Minggu dan tidak terdaftar sebagai hari libur aktif)
     */
    public function isHariKerja(CarbonInterface|string $tanggal): bool
    {
        $tgl = $tanggal instanceof CarbonInterface ? $tanggal->copy() : Carbon::parse($tanggal);

        if ($tgl->isWeekend()) {
            return false;
        }

        return ! HariLibur::merupakanLibur($tgl);
    }

    /**
     * Geser tanggal ke hari kerja terdekat sesuai strategi kebijakan.
     * Mengembalikan tanggal yang sama bila sudah hari kerja atau fitur nonaktif.
     */
    public function keHariKerja(CarbonInterface|string $tanggal): Carbon
    {
        $tgl = $tanggal instanceof CarbonInterface ? $tanggal->copy() : Carbon::parse($tanggal);

        if (! Setting::hariLiburAktif() || $this->isHariKerja($tgl)) {
            return $tgl;
        }

        $arah = Setting::hariLiburStrategi() === 'MUNDUR' ? -1 : 1;

        for ($i = 1; $i <= self::BATAS_GESER_HARI; $i++) {
            $kandidat = $tgl->copy()->addDays($i * $arah);

            if ($this->isHariKerja($kandidat)) {
                return $kandidat;
            }
        }

        // Tidak ditemukan hari kerja dalam batas iterasi → kembalikan apa adanya
        // agar proses pencairan tidak gagal; kondisi ini praktis mustahil.
        return $tgl;
    }

    /**
     * Tanggal jatuh tempo angsuran PERTAMA (first due date).
     *
     * Diambil dari tanggal pencairan + 1 bulan, lalu disetel ke tanggal jatuh
     * tempo bulanan koperasi dan disesuaikan ke hari kerja terdekat.
     */
    public function jatuhTempoPertama(CarbonInterface|string $tanggalCair): Carbon
    {
        $cair = $tanggalCair instanceof CarbonInterface ? $tanggalCair->copy() : Carbon::parse($tanggalCair);

        $dasar = $cair->copy()->addMonthNoOverflow()->day(Setting::tanggalJatuhTempoDefault());

        return $this->keHariKerja($dasar);
    }

    /**
     * Seluruh tanggal jatuh tempo angsuran untuk tenor tertentu.
     *
     * @return array<int, Carbon> kunci = angsuran ke- (1..tenor)
     */
    public function jadwalJatuhTempo(CarbonInterface|string $tanggalCair, int $tenor): array
    {
        $cair = $tanggalCair instanceof CarbonInterface ? $tanggalCair->copy() : Carbon::parse($tanggalCair);
        $tenor = max(1, $tenor);
        $hari = Setting::tanggalJatuhTempoDefault();
        $hasil = [];

        for ($ke = 1; $ke <= $tenor; $ke++) {
            $dasar = $cair->copy()->addMonthsNoOverflow($ke)->day($hari);
            $hasil[$ke] = $this->keHariKerja($dasar);
        }

        return $hasil;
    }
}
