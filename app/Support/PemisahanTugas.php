<?php

namespace App\Support;

/**
 * Prinsip GCG — PEMISAHAN TUGAS (Segregation of Duties / Maker ≠ Checker).
 *
 * Satu orang TIDAK BOLEH memutuskan (menyetujui/menolak) pengajuan yang ia
 * ajukan sendiri, walaupun perannya secara teknis berwenang. Tanpa penjagaan
 * ini, hierarki Teller → AO → Manajer → Pengurus dapat dilangkahi oleh satu
 * akun, dan jejak audit kehilangan nilai pembuktiannya.
 *
 * Aturan ditaruh di satu tempat agar seluruh modul (approval limit transaksi,
 * pengeluaran beban, keringanan ta'zir, restrukturisasi pembiayaan, verifikasi
 * AML) memakai definisi & pesan yang sama — tidak ada varian logika.
 *
 * Pemakaian:
 *
 *   if (PemisahanTugas::dilanggar($pengajuan->created_by, Auth::id())) {
 *       return back()->with('error', PemisahanTugas::PESAN);
 *   }
 *
 * Nilai pengaju `null` berarti data dibentuk sistem (seeder/simulasi/job);
 * kasus itu tidak dianggap pelanggaran agar proses otomatis tidak terblokir.
 */
final class PemisahanTugas
{
    /** Pesan penolakan standar — dipakai ulang oleh seluruh pemanggil & test. */
    public const PESAN = 'Akses ditolak. Anda tidak dapat menyetujui atau menolak pengajuan yang Anda ajukan sendiri (prinsip pemisahan tugas).';

    /**
     * Apakah aturan maker ≠ checker dilanggar?
     *
     * @param  int|string|null  $idPemohon  ID pengaju (kolom created_by/ao_id/teller_id/diajukan_oleh)
     * @param  int|string|null  $idPemutus  ID pihak yang hendak memutuskan
     */
    public static function dilanggar(int|string|null $idPemohon, int|string|null $idPemutus): bool
    {
        if ($idPemohon === null || $idPemohon === '' || $idPemutus === null || $idPemutus === '') {
            return false;
        }

        return (int) $idPemohon === (int) $idPemutus;
    }

    /**
     * Varian multi-kolom: pelanggaran bila SALAH SATU kolom pengaju sama
     * dengan pihak pemutus. Dipakai modul yang punya dua pengaju berjenjang
     * (mis. Ta'zir: teller_id lalu ao_id).
     *
     * @param  array<int, int|string|null>  $idPemohonList
     */
    public static function dilanggarSalahSatu(array $idPemohonList, int|string|null $idPemutus): bool
    {
        foreach ($idPemohonList as $idPemohon) {
            if (self::dilanggar($idPemohon, $idPemutus)) {
                return true;
            }
        }

        return false;
    }
}
