<?php

namespace Tests\Feature;

use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Services\LaporanKeuanganSyariahService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * K-2 — Jurnal Penutup tidak boleh membuat laporan "impas semu".
 *
 * Setelah tutup buku, jurnal penutup menolkan seluruh akun Pendapatan &
 * Beban lalu memindahkan labanya ke 3-9000 Ikhtisar SHU. Bila efek itu ikut
 * dihitung saat MENYAJIKAN laporan:
 *
 *   • Laba Rugi periode yang sudah ditutup buku terbaca Pendapatan = Beban = 0
 *     (impas semu), padahal realisasinya ada;
 *   • Laba yang sama berpotensi dihitung dua kali (3-9000 + Laba Berjalan)
 *     sehingga Total Pasiva menggelembung dan neraca tidak seimbang.
 *
 * Perbaikan: `Coa::saldoBerjalanLaporan()` mengecualikan jurnal penutup pada
 * KEDUA sisi sekaligus. Test ini mengunci:
 *
 *   A. Buku tetap melihat efek penutup apa adanya (`saldoBerjalan()` nol),
 *      sedangkan penyajian laporan memakai nilai riil.
 *   B. Laba Rugi, Posisi Keuangan, dan Perubahan Ekuitas menghasilkan laba
 *      bersih yang identik.
 *   C. Neraca tetap seimbang (Total Aset = Total Pasiva, selisih 0).
 *   D. Diagnostik arus kas (uji identitas buku) tidak memunculkan temuan palsu.
 *
 * Nilai absolut tidak dijadikan patokan karena basis data dev berisi data
 * nyata; pengujian memakai DELTA terhadap kondisi sebelum jurnal uji.
 */
class LaporanLabaRugiJurnalPenutupTest extends TestCase
{
    use DatabaseTransactions;

    private function akun(string $kode, string $nama, string $kategori, string $klasifikasi): Coa
    {
        $coa = Coa::updateOrCreate(
            ['kode_akun' => $kode],
            [
                'nama_akun' => $nama,
                'kategori' => $kategori,
                'klasifikasi_neraca' => $klasifikasi,
                'tipe_saldo_normal' => $kategori === 'Beban' || $kategori === 'Aset' ? 'Debet' : 'Kredit',
                'saldo_awal' => 0,
                'is_active' => true,
            ]
        );

        // Pastikan akun uji mulai dari nol (sisa test lain yang tidak ter-rollback).
        JurnalDetail::where('coa_id', $coa->id)->delete();
        $coa->update(['saldo_awal' => 0]);

        return $coa->fresh();
    }

    /**
     * Posting satu jurnal seimbang.
     *
     * @param  list<array{0: Coa, 1: float, 2: float}>  $baris  [akun, debet, kredit]
     */
    private function jurnal(array $baris, string $jenis = 'Jurnal Umum', string $sumber = 'UJI'): Jurnal
    {
        $debet = array_sum(array_map(fn (array $b) => $b[1], $baris));
        $kredit = array_sum(array_map(fn (array $b) => $b[2], $baris));

        $jurnal = Jurnal::create([
            'no_jurnal' => 'UJI-PENUTUP-'.uniqid(),
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => $jenis,
            'sumber' => $sumber,
            'keterangan' => 'Uji K-2: jurnal penutup tidak boleh membuat impas semu.',
            'total_debet' => $debet,
            'total_kredit' => $kredit,
            'is_balance' => true,
            'status' => 'Posted',
            'posted_at' => now(),
        ]);

        foreach ($baris as $i => [$coa, $d, $k]) {
            JurnalDetail::create([
                'jurnal_id' => $jurnal->id,
                'coa_id' => $coa->id,
                'baris' => $i + 1,
                'debet' => $d,
                'kredit' => $k,
            ]);
        }

        return $jurnal->pastikanBalance();
    }

    public function test_jurnal_penutup_tidak_menghasilkan_impas_semu_dan_neraca_tetap_seimbang(): void
    {
        $layanan = app(LaporanKeuanganSyariahService::class);
        $periode = $layanan->periode();

        $pendapatan = $this->akun('4-9191', 'Pendapatan Uji Jurnal Penutup', 'Pendapatan', 'Pendapatan Usaha');
        $beban = $this->akun('5-9191', 'Beban Uji Jurnal Penutup', 'Beban', 'Beban Usaha');
        $kas = $this->akun('1-9191', 'Kas Uji Jurnal Penutup', 'Aset', 'Aset Lancar');
        $shu = Coa::findByKode('3-9000');

        $this->assertNotNull($shu, 'Akun 3-9000 Ikhtisar SHU wajib ada.');

        $shuBukuSebelum = (float) $shu->saldoBerjalan();
        $shuLaporanSebelum = (float) $shu->saldoBerjalanLaporan();

        $labaRugiSebelum = $layanan->labaRugi($periode);
        $posisiSebelum = $layanan->posisiKeuangan($periode);
        $diagnostikSebelum = $layanan->deteksiArusKasTakTerlacak($periode);

        // Realisasi periode: pendapatan 10.000.000, beban 6.000.000 → laba 4.000.000.
        $this->jurnal([[$kas, 10_000_000, 0], [$pendapatan, 0, 10_000_000]]);
        $this->jurnal([[$beban, 6_000_000, 0], [$kas, 0, 6_000_000]]);

        // Tutup buku: nolkan Pendapatan & Beban, pindahkan laba ke 3-9000.
        $this->jurnal(
            [
                [$pendapatan, 10_000_000, 0],
                [$beban, 0, 6_000_000],
                [$shu, 0, 4_000_000],
            ],
            'Jurnal Penutup',
            'TutupBuku'
        );

        // ── A. Buku vs penyajian laporan ────────────────────────────────
        $this->assertEqualsWithDelta(
            0,
            (float) $pendapatan->fresh()->saldoBerjalan(),
            0.01,
            'Jurnal penutup memang harus menolkan saldo BUKU akun Pendapatan.'
        );
        $this->assertEqualsWithDelta(
            0,
            (float) $beban->fresh()->saldoBerjalan(),
            0.01,
            'Jurnal penutup memang harus menolkan saldo BUKU akun Beban.'
        );

        $this->assertEqualsWithDelta(
            10_000_000,
            (float) $pendapatan->fresh()->saldoBerjalanLaporan(),
            0.01,
            'Basis laporan harus tetap menyajikan pendapatan riil, bukan nol.'
        );
        $this->assertEqualsWithDelta(
            6_000_000,
            (float) $beban->fresh()->saldoBerjalanLaporan(),
            0.01,
            'Basis laporan harus tetap menyajikan beban riil, bukan nol.'
        );
        $this->assertEqualsWithDelta(
            $shuLaporanSebelum,
            (float) $shu->fresh()->saldoBerjalanLaporan(),
            0.01,
            '3-9000 pada basis laporan tidak boleh memuat laba yang sama (cegah hitung ganda).'
        );
        $this->assertEqualsWithDelta(
            $shuBukuSebelum + 4_000_000,
            (float) $shu->fresh()->saldoBerjalan(),
            0.01,
            'Buku Besar tetap melihat pemindahan laba ke 3-9000 apa adanya.'
        );

        // ── B. Laba Rugi tetap riil ─────────────────────────────────────
        $labaRugi = $layanan->labaRugi($periode);

        $this->assertEqualsWithDelta(
            $labaRugiSebelum['totalPendapatan'] + 10_000_000,
            $labaRugi['totalPendapatan'],
            0.01,
            'Pendapatan periode yang sudah ditutup buku tidak boleh terbaca nol.'
        );
        $this->assertEqualsWithDelta(
            $labaRugiSebelum['totalBeban'] + 6_000_000,
            $labaRugi['totalBeban'],
            0.01,
            'Beban periode yang sudah ditutup buku tidak boleh terbaca nol.'
        );
        $this->assertEqualsWithDelta(
            $labaRugiSebelum['labaBersih'] + 4_000_000,
            $labaRugi['labaBersih'],
            0.01,
            'Laba bersih harus naik sebesar laba riil periode.'
        );

        // ── C. Neraca seimbang & laba identik antar laporan ─────────────
        $posisi = $layanan->posisiKeuangan($periode);
        $perubahan = $layanan->perubahanEkuitas($periode);

        $this->assertEqualsWithDelta(0.0, $posisi['selisihNeraca'], 0.01, 'Neraca wajib tetap seimbang.');
        $this->assertEqualsWithDelta($posisi['totalAset'], $posisi['totalPasiva'], 0.01);
        $this->assertEqualsWithDelta(
            $posisiSebelum['labaBersih'] + 4_000_000,
            $posisi['labaBersih'],
            0.01,
            'Laba Berjalan pada Neraca harus naik sebesar laba riil periode.'
        );
        $this->assertEqualsWithDelta(
            $labaRugi['labaBersih'],
            $posisi['labaBersih'],
            0.01,
            'Laba bersih Neraca harus identik dengan Laba Rugi.'
        );
        $this->assertEqualsWithDelta(
            $labaRugi['labaBersih'],
            $perubahan['labaBersih'],
            0.01,
            'Laba bersih Perubahan Ekuitas harus identik dengan Laba Rugi.'
        );
        $this->assertEqualsWithDelta(
            $labaRugi['labaBersih'],
            Coa::labaBersihBerjalan($periode['sampaiStr']),
            0.01,
            'Coa::labaBersihBerjalan() harus memakai basis penyajian laporan yang sama.'
        );

        // ── D. Diagnostik arus kas tetap bersih ─────────────────────────
        $diagnostik = $layanan->deteksiArusKasTakTerlacak($periode);

        $this->assertEqualsWithDelta(
            $diagnostikSebelum['selisih'],
            $diagnostik['selisih'],
            0.01,
            'Jurnal penutup yang seimbang tidak boleh menggeser identitas buku.'
        );
        $this->assertFalse(
            $diagnostik['terdeteksi'],
            'Buku yang seimbang tidak boleh dilaporkan memiliki mutasi kas tak terjelaskan.'
        );
    }
}
