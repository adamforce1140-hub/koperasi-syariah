<?php

namespace App\Services;

use App\Models\Transaksi;
use App\Support\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Penyusun berkas pelaporan PPATK untuk koperasi syariah.
 *
 * Dasar hukum: UU No. 8 Tahun 2010 tentang Pencegahan dan Pemberantasan TPPU
 * serta POJK No. 27/POJK.03/2022 tentang Penerapan Program APU-PPT. Koperasi
 * sebagai penyedia jasa keuangan wajib menyampaikan laporan kepada PPATK
 * melalui aplikasi goAML:
 *
 *   - LTKT : Laporan Transaksi Keuangan Tunai (transaksi tunai >= ambang).
 *   - LTKM : Laporan Transaksi Keuangan Mencurigakan (transaksi bertanda AML).
 *   - STR  : Surat Transaksi Keuangan (rekap transaksi AML yang telah
 *            diverifikasi/ditolak sebagai bahan tindak lanjut).
 *
 * Seluruh ambang batas, jenis transaksi tunai, dan identitas pelapor bersifat
 * DINAMIS (dibaca dari {@see Setting}), sehingga perubahan regulasi di masa
 * mendatang cukup dilakukan lewat konfigurasi tanpa mengubah kode inti.
 */
class PpatkReportService
{
    /** Ambang LTKT bawaan (Rp) — selaras batas pelaporan tunai PPATK. */
    public const AMBANG_TUNAI_DEFAULT = 500000000.00;

    /** Kode jenis laporan yang didukung. */
    public const JENIS = ['LTKT', 'LTKM', 'STR'];

    /**
     * Daftar jenis transaksi yang dikategorikan tunai (dinamis via Setting).
     *
     * @return list<string>
     */
    public function jenisTunai(): array
    {
        return Setting::jenisTransaksiTunai();
    }

    /** Ambang nominal transaksi tunai yang wajib dilaporkan (Rp). */
    public function ambangTunai(): float
    {
        return Setting::nominal('ltkt_threshold', self::AMBANG_TUNAI_DEFAULT);
    }

    /** Ambang transaksi bernilai besar / mencurigakan (Rp). */
    public function ambangMencurigakan(): float
    {
        return Setting::amlThreshold();
    }

    /**
     * Identitas koperasi sebagai pihak pelapor (bahan kop laporan).
     *
     * @return array<string, string>
     */
    public function identitasPelapor(): array
    {
        return [
            'nama' => (string) Setting::get('ppatk_nama_pelapor', (string) Setting::get('nama_koperasi', 'Koperasi Syariah')),
            'kode' => (string) Setting::get('ppatk_kode_pelapor', ''),
            'npwp' => (string) Setting::get('npwp_koperasi', ''),
        ];
    }

    /** Judul kolom (header CSV) untuk tiap jenis laporan. */
    public function judulKolom(string $jenis): array
    {
        return match (strtoupper($jenis)) {
            'LTKT' => [
                'NO_TRANSAKSI', 'TANGGAL', 'NAMA_NASABAH', 'NIK', 'NO_REKENING',
                'JENIS_TRANSAKSI', 'POSISI', 'NOMINAL', 'KANAL', 'PETUGAS',
            ],
            'LTKM', 'STR' => [
                'NO_TRANSAKSI', 'TANGGAL', 'NAMA_NASABAH', 'NIK', 'NO_REKENING',
                'JENIS_TRANSAKSI', 'NOMINAL', 'SUMBER_DANA', 'STATUS_VERIFIKASI',
                'KETERANGAN',
            ],
            default => throw new \InvalidArgumentException("Jenis laporan PPATK tidak dikenal: {$jenis}"),
        };
    }

    /**
     * LTKT — transaksi tunai dengan nominal >= ambang dalam rentang periode.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function ltkt(Carbon $dari, Carbon $sampai): Collection
    {
        $ambang = $this->ambangTunai();

        return $this->dasar($dari, $sampai)
            ->whereIn('jenis_transaksi', $this->jenisTunai())
            ->where('jumlah', '>=', $ambang)
            ->orderBy('waktu_transaksi')
            ->get()
            ->map(fn (Transaksi $t) => [
                'NO_TRANSAKSI' => $t->no_transaksi,
                'TANGGAL' => optional($t->waktu_transaksi)->format('Y-m-d H:i:s'),
                'NAMA_NASABAH' => $t->anggota->nama_lengkap ?? '-',
                'NIK' => $t->anggota->nik ?? '-',
                'NO_REKENING' => $t->rekening->no_rekening ?? '-',
                'JENIS_TRANSAKSI' => $t->jenis_transaksi,
                'POSISI' => $t->posisi,
                'NOMINAL' => (float) $t->jumlah,
                'KANAL' => $t->sumber_transaksi ?? '-',
                'PETUGAS' => $t->teller->name ?? '-',
            ]);
    }

    /**
     * LTKM — transaksi yang ditandai bernilai besar / mencurigakan.
     *
     * Kriteria: bertanda `is_aml_high_value`, `is_large_transaction`, atau
     * nominal >= ambang AML. Periode mengikuti `waktu_transaksi`.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function ltkm(Carbon $dari, Carbon $sampai): Collection
    {
        $ambang = $this->ambangMencurigakan();

        return $this->dasar($dari, $sampai)
            ->where(function ($q) use ($ambang) {
                $q->where('is_aml_high_value', true)
                    ->orWhere('is_large_transaction', true)
                    ->orWhere('jumlah', '>=', $ambang);
            })
            ->orderBy('waktu_transaksi')
            ->get()
            ->map(fn (Transaksi $t) => $this->barisAml($t));
    }

    /**
     * STR — rekap transaksi AML yang telah diputus (terverifikasi/ditolak).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function str(Carbon $dari, Carbon $sampai): Collection
    {
        return $this->dasar($dari, $sampai)
            ->whereNotNull('aml_status_verifikasi')
            ->whereIn('aml_status_verifikasi', ['terverifikasi', 'ditolak'])
            ->orderBy('waktu_transaksi')
            ->get()
            ->map(fn (Transaksi $t) => $this->barisAml($t));
    }

    /**
     * Susun isi berkas CSV (tanpa BOM) untuk jenis laporan tertentu.
     *
     * @return array{jenis: string, periode: array{dari: string, sampai: string}, judul: array<int, string>, baris: Collection<int, array<string, mixed>>}
     */
    public function susun(string $jenis, Carbon $dari, Carbon $sampai): array
    {
        $jenis = strtoupper($jenis);

        $baris = match ($jenis) {
            'LTKT' => $this->ltkt($dari, $sampai),
            'LTKM' => $this->ltkm($dari, $sampai),
            'STR' => $this->str($dari, $sampai),
            default => throw new \InvalidArgumentException("Jenis laporan PPATK tidak dikenal: {$jenis}"),
        };

        return [
            'jenis' => $jenis,
            'periode' => [
                'dari' => $dari->toDateString(),
                'sampai' => $sampai->toDateString(),
            ],
            'judul' => $this->judulKolom($jenis),
            'baris' => $baris,
        ];
    }

    /**
     * Konversi hasil {@see susun()} menjadi string CSV siap unduh.
     */
    public function csv(string $jenis, Carbon $dari, Carbon $sampai): string
    {
        $laporan = $this->susun($jenis, $dari, $sampai);

        $handle = fopen('php://temp', 'r+');

        // Baris kop: identitas pelapor & periode laporan.
        fputcsv($handle, ['JENIS_LAPORAN', $laporan['jenis']]);
        fputcsv($handle, ['PELAPOR', $this->identitasPelapor()['nama']]);
        fputcsv($handle, ['KODE_PELAPOR', $this->identitasPelapor()['kode']]);
        fputcsv($handle, ['PERIODE_DARI', $laporan['periode']['dari']]);
        fputcsv($handle, ['PERIODE_SAMPAI', $laporan['periode']['sampai']]);
        fputcsv($handle, []);

        fputcsv($handle, $laporan['judul']);

        foreach ($laporan['baris'] as $baris) {
            fputcsv($handle, array_map(
                fn ($kolom) => $baris[$kolom] ?? '',
                $laporan['judul']
            ));
        }

        rewind($handle);
        $isi = stream_get_contents($handle);
        fclose($handle);

        return (string) $isi;
    }

    /** Nama berkas unduhan CSV yang informatif & aman. */
    public function namaBerkas(string $jenis, Carbon $dari, Carbon $sampai): string
    {
        return sprintf(
            'PPATK-%s-%s_%s.csv',
            strtoupper($jenis),
            $dari->format('Ymd'),
            $sampai->format('Ymd')
        );
    }

    /** Query dasar transaksi dalam rentang periode (kolom waktu_transaksi). */
    private function dasar(Carbon $dari, Carbon $sampai)
    {
        return Transaksi::with(['anggota', 'rekening', 'teller'])
            ->whereBetween('waktu_transaksi', [
                $dari->copy()->startOfDay(),
                $sampai->copy()->endOfDay(),
            ]);
    }

    /**
     * Bentuk satu baris laporan AML (dipakai LTKM & STR).
     *
     * @return array<string, mixed>
     */
    private function barisAml(Transaksi $t): array
    {
        return [
            'NO_TRANSAKSI' => $t->no_transaksi,
            'TANGGAL' => optional($t->waktu_transaksi)->format('Y-m-d H:i:s'),
            'NAMA_NASABAH' => $t->anggota->nama_lengkap ?? '-',
            'NIK' => $t->anggota->nik ?? '-',
            'NO_REKENING' => $t->rekening->no_rekening ?? '-',
            'JENIS_TRANSAKSI' => $t->jenis_transaksi,
            'NOMINAL' => (float) $t->jumlah,
            'SUMBER_DANA' => $t->aml_sumber_dana ?? '-',
            'STATUS_VERIFIKASI' => $t->aml_status_verifikasi ?? '-',
            'KETERANGAN' => $t->aml_catatan_compliance ?? ($t->catatan ?? '-'),
        ];
    }
}
