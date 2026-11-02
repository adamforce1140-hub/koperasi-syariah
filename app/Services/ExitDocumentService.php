<?php

namespace App\Services;

use App\Models\MemberTermination;
use App\Support\BarcodeCode39;
use App\Support\HierarkiRole;
use Barryvdh\DomPDF\PDF;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * DOKUMEN BUKTI PENUTUPAN KEANGGOTAAN & TANDA TERIMA UANG.
 *
 * Sumber angka SATU-SATUNYA adalah kolom nominal yang sudah diposting pada
 * `member_terminations` — bukan perhitungan ulang. Setelah jurnal diposting,
 * saldo rekening sudah nol dan rekening berstatus ditutup, sehingga
 * menghitung ulang akan menghasilkan dokumen yang berbeda dari buku besar.
 *
 * Dua format:
 *  - PDF A4 (barryvdh/laravel-dompdf) untuk arsip resmi & anggota;
 *  - HTML 80 mm untuk printer thermal kasir (dicetak via dialog print browser).
 *
 * Setiap dokumen memuat nomor referensi unik `EXIT/YYYYMM/NNNNNN` beserta
 * barcode Code 39 dan kode verifikasi digital (HMAC-SHA256) pejabat koperasi.
 */
class ExitDocumentService
{
    /** Render dokumen PDF A4 siap unduh. */
    public function pdf(MemberTermination $terminasi): PDF
    {
        $data = $this->dataDokumen($terminasi);

        Log::info('EXIT_DOKUMEN_PDF_DIBUAT', [
            'terminasi_id' => $terminasi->id,
            'no_referensi' => $data['no_referensi'],
            'user_id' => Auth::id(),
        ]);

        return app('dompdf.wrapper')
            ->setOption('defaultFont', (string) config('pdf.bilyet.font', 'DejaVu Sans'))
            ->setOption('isRemoteEnabled', false)
            ->setOption('isHtml5ParserEnabled', true)
            ->loadView('member-terminations.bukti-pdf', $data);
    }

    /** Data untuk dokumen thermal 80 mm. */
    public function dataThermal(MemberTermination $terminasi): array
    {
        $data = $this->dataDokumen($terminasi);

        Log::info('EXIT_DOKUMEN_THERMAL_DIBUAT', [
            'terminasi_id' => $terminasi->id,
            'no_referensi' => $data['no_referensi'],
            'user_id' => Auth::id(),
        ]);

        return $data;
    }

    /**
     * Susun seluruh data dokumen.
     *
     * @return array<string, mixed>
     */
    public function dataDokumen(MemberTermination $terminasi): array
    {
        $terminasi->loadMissing(['anggota', 'diprosesOleh', 'disetujuiOleh']);

        $anggota = $terminasi->anggota;
        $noReferensi = (string) ($terminasi->no_referensi ?: $terminasi->no_terminasi);

        $tanggalTutup = $terminasi->diproses_pada
            ?? $terminasi->approved_at
            ?? $terminasi->updated_at
            ?? Carbon::now();

        // Pejabat penandatangan: operator yang benar-benar mencairkan dana.
        $pejabat = $terminasi->diprosesOleh ?? $terminasi->disetujuiOleh ?? Auth::user();

        $alamat = $this->alamatLengkap($anggota);

        $rincian = [
            'pokok' => (float) $terminasi->nominal_simpanan_pokok,
            'wajib' => (float) $terminasi->nominal_simpanan_wajib,
            'sukarela' => (float) $terminasi->nominal_sukarela,
            'deposito' => (float) $terminasi->nominal_deposito,
            'akrual' => (float) $terminasi->nominal_akrual_bagi_hasil,
            'zakat' => (float) $terminasi->nominal_zakat,
            'biaya_admin' => (float) $terminasi->nominal_biaya_admin,
        ];

        $rincian['bruto'] = $rincian['pokok'] + $rincian['wajib'] + $rincian['sukarela']
            + $rincian['deposito'] + $rincian['akrual'];

        $rincian['total_net'] = (float) ($terminasi->total_dikembalikan ?? $terminasi->total_net);

        // Kode verifikasi digital: HMAC atas isi pokok dokumen. Dapat
        // dihitung ulang oleh auditor dari data yang sama + APP_KEY.
        $kodeVerifikasi = strtoupper(substr(hash_hmac(
            'sha256',
            implode('|', [
                $noReferensi,
                (string) $terminasi->anggota_id,
                number_format($rincian['total_net'], 2, '.', ''),
                $tanggalTutup->format('Y-m-d H:i:s'),
                (string) ($terminasi->no_jurnal ?? '-'),
            ]),
            (string) config('app.key')
        ), 0, 20));

        return [
            'terminasi' => $terminasi,
            'anggota' => $anggota,
            'koperasi' => (array) config('pdf.koperasi', []),
            'logo' => $this->logoPath(),
            'pejabat' => $pejabat,
            'jabatan_pejabat' => HierarkiRole::jabatan($pejabat?->role),
            'no_referensi' => $noReferensi,
            'no_terminasi' => $terminasi->no_terminasi,
            'no_jurnal' => $terminasi->no_jurnal,
            'tanggal_tutup' => $tanggalTutup,
            'tanggal_cetak' => Carbon::now(),
            'nik' => $anggota?->nik,
            'nik_masked' => $this->samarkanNik($anggota?->nik),
            'alamat_lengkap' => $alamat,
            'metode_label' => $terminasi->metode_label,
            'rekening_tujuan' => $terminasi->membutuhkanRekeningTujuan()
                ? trim(($terminasi->nama_bank_tujuan ?? '').' — '.($terminasi->nomor_rekening_tujuan ?? ''), ' —')
                : null,
            'rincian' => $rincian,
            'barcode_svg' => BarcodeCode39::svg($noReferensi, 13.0, 0.28),
            'barcode_uri' => BarcodeCode39::dataUri($noReferensi, 13.0, 0.28),
            'kode_verifikasi' => $kodeVerifikasi,
            'nama_file' => $this->namaFileDariReferensi($noReferensi),
        ];
    }

    public function namaFile(MemberTermination $terminasi): string
    {
        return $this->namaFileDariReferensi((string) ($terminasi->no_referensi ?: $terminasi->no_terminasi));
    }

    private function namaFileDariReferensi(string $referensi): string
    {
        $aman = preg_replace('/[^A-Za-z0-9\-_]/', '-', $referensi) ?: 'EXIT';

        return 'Bukti-Penutupan-Keanggotaan-'.$aman.'.pdf';
    }

    /** Path logo absolut bila tersedia (dompdf butuh path lokal). */
    private function logoPath(): ?string
    {
        $path = (string) config('pdf.logo', '');

        if ($path === '') {
            return null;
        }

        $kandidat = public_path($path);

        return (is_file($kandidat) && is_readable($kandidat)) ? $kandidat : null;
    }

    private function alamatLengkap($anggota): string
    {
        if (! $anggota) {
            return '—';
        }

        $bagian = array_filter([
            $anggota->alamat,
            $anggota->rt_rw ? 'RT/RW '.$anggota->rt_rw : null,
            $anggota->kelurahan,
            $anggota->kecamatan,
            $anggota->kota,
            $anggota->provinsi,
            $anggota->kode_pos,
        ], fn ($v) => filled($v));

        return $bagian === [] ? '—' : implode(', ', $bagian);
    }

    private function samarkanNik(?string $nik): ?string
    {
        if ($nik === null || $nik === '') {
            return null;
        }

        $panjang = strlen($nik);

        return $panjang <= 4
            ? str_repeat('*', $panjang)
            : str_repeat('*', $panjang - 4).substr($nik, -4);
    }
}
