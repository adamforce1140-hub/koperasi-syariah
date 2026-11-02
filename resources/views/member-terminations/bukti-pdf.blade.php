<?php
    /**
     * BUKTI PENUTUPAN KEANGGOTAAN & TANDA TERIMA UANG — PDF A4 (dompdf).
     *
     * Catatan teknis dompdf:
     *  - Hanya CSS 2.1 (tanpa flex/grid); tata letak memakai <table>.
     *  - Font DejaVu Sans agar simbol (—, −, ✓) tercetak.
     *  - Barcode disematkan sebagai data-URI SVG (isRemoteEnabled = false).
     */

    $rp = fn ($v) => 'Rp ' . number_format((float) $v, 2, ',', '.');
    $r = $rincian;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $nama_file }}</title>
    <style>
        @page { size: A4 portrait; margin: 16mm 15mm 14mm 15mm; }

        * { box-sizing: border-box; }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 9.2pt;
            color: #14261c;
            margin: 0;
            line-height: 1.45;
        }

        .frame { border: 1.6pt solid #0d5c3f; padding: 5mm; }
        .frame-inner { border: 0.6pt solid #b48a2f; padding: 4mm 4mm 3mm 4mm; }

        /* ── Kop ── */
        .kop { width: 100%; border-collapse: collapse; }
        .kop td { border: none; padding: 0; vertical-align: middle; }
        .kop-logo { width: 24mm; text-align: center; }
        .kop-logo img { width: 20mm; }
        .emblem {
            width: 18mm; height: 18mm; margin: 0 auto;
            border: 1.2pt solid #0d5c3f; border-radius: 50%;
            background-color: #f2f8f4; text-align: center;
        }
        .emblem-text { font-size: 11pt; font-weight: bold; color: #0d5c3f; padding-top: 3.4mm; }
        .emblem-sub { font-size: 4.6pt; color: #b48a2f; letter-spacing: 0.3pt; }
        .kop-identitas { padding-left: 4mm !important; }
        .kop-nama { font-size: 12.4pt; font-weight: bold; color: #0d5c3f; margin: 0; letter-spacing: 0.2pt; }
        .kop-alamat { font-size: 7.4pt; color: #33513f; margin: 0.6mm 0 0 0; }
        .kop-hukum { font-size: 6.9pt; font-style: italic; color: #6b6b6b; margin: 0.5mm 0 0 0; }

        .ornament { border-top: 0.7pt solid #0d5c3f; border-bottom: 0.7pt solid #0d5c3f; height: 1.6mm; margin: 3mm 0; background-color: #f2f8f4; }

        /* ── Judul ── */
        .judul { text-align: center; margin: 0 0 3mm 0; }
        .judul-utama { font-size: 13pt; font-weight: bold; color: #0d5c3f; margin: 0; text-transform: uppercase; letter-spacing: 0.8pt; }
        .judul-sub { font-size: 8pt; color: #33513f; margin: 1mm 0 0 0; }

        /* ── Nomor referensi ── */
        .nomor { width: 100%; border-collapse: collapse; margin-bottom: 3mm; }
        .nomor td { border: 0.6pt solid #0d5c3f; background-color: #f2f8f4; padding: 1.6mm 2.6mm; font-size: 8.8pt; }
        .nomor .label { width: 33mm; font-weight: bold; color: #0d5c3f; }
        .nomor .nilai { font-family: "DejaVu Sans Mono", monospace; font-size: 10.2pt; font-weight: bold; letter-spacing: 0.6pt; }

        /* ── Blok data ── */
        .blok { margin-bottom: 3.2mm; }
        .blok-judul {
            font-size: 8.6pt; font-weight: bold; color: #0d5c3f;
            border-bottom: 0.7pt solid #0d5c3f; padding-bottom: 0.8mm; margin: 0 0 1.4mm 0;
            text-transform: uppercase; letter-spacing: 0.4pt;
        }
        .data { width: 100%; border-collapse: collapse; }
        .data td { border: none; padding: 0.6mm 0; vertical-align: top; font-size: 8.8pt; }
        .data .label { width: 32mm; color: #4b5b52; }
        .data .pemisah { width: 2mm; }
        .data .nilai { font-weight: bold; }

        /* ── Tabel rincian ── */
        .rincian { width: 100%; border-collapse: collapse; }
        .rincian th, .rincian td { border: 0.5pt solid #9fb8aa; padding: 1.5mm 2.2mm; font-size: 8.7pt; }
        .rincian th { background-color: #e8f2ec; color: #0d5c3f; text-align: left; }
        .rincian td.angka { text-align: right; font-family: "DejaVu Sans Mono", monospace; }
        .rincian tr.total td { background-color: #0d5c3f; color: #ffffff; font-weight: bold; font-size: 9.4pt; }
        .rincian tr.potongan td.angka { color: #991b1b; }

        /* ── Pernyataan ── */
        .pernyataan {
            font-size: 8pt; color: #33513f; text-align: justify;
            background-color: #f7faf8; border-left: 2.4pt solid #b48a2f;
            padding: 2mm 3mm; margin: 3mm 0;
        }

        /* ── Tanda tangan ── */
        .ttd { width: 100%; border-collapse: collapse; margin-top: 4mm; }
        .ttd td { border: none; width: 50%; text-align: center; vertical-align: top; font-size: 8.4pt; }
        .ttd .ruang { height: 17mm; }
        .ttd .nama { font-weight: bold; text-decoration: underline; }
        .ttd .ket { color: #4b5b52; font-size: 7.6pt; }
        .ttd .digital { font-size: 6.8pt; color: #0d5c3f; margin-top: 1mm; }

        /* ── Barcode ── */
        .barcode { text-align: center; margin-top: 3mm; }
        .barcode img { height: 12mm; }
        .barcode .ref { font-family: "DejaVu Sans Mono", monospace; font-size: 7.6pt; letter-spacing: 1pt; }

        .footer { margin-top: 2mm; font-size: 6.8pt; color: #6b6b6b; text-align: center; }
    </style>
</head>
<body>
<div class="frame">
<div class="frame-inner">

    {{-- KOP --}}
    <table class="kop">
        <tr>
            <td class="kop-logo">
                @if($logo)
                    <img src="{{ $logo }}" alt="Logo">
                @else
                    <div class="emblem">
                        <div class="emblem-text">KS</div>
                        <div class="emblem-sub">SYARIAH</div>
                    </div>
                @endif
            </td>
            <td class="kop-identitas">
                <p class="kop-nama">{{ $koperasi['nama'] ?? 'KOPERASI SYARIAH' }}</p>
                <p class="kop-alamat">
                    {{ $koperasi['alamat'] ?? '' }}@if(!empty($koperasi['kota'])), {{ $koperasi['kota'] }}@endif
                    @if(!empty($koperasi['kode_pos'])) {{ $koperasi['kode_pos'] }}@endif
                </p>
                <p class="kop-alamat">
                    Telp. {{ $koperasi['telepon'] ?? '-' }} &nbsp;|&nbsp; {{ $koperasi['email'] ?? '' }}
                    @if(!empty($koperasi['website'])) &nbsp;|&nbsp; {{ $koperasi['website'] }} @endif
                </p>
                @if(!empty($koperasi['badan_hukum']))
                    <p class="kop-hukum">{{ $koperasi['badan_hukum'] }}</p>
                @endif
            </td>
        </tr>
    </table>

    <div class="ornament"></div>

    <div class="judul">
        <p class="judul-utama">Bukti Penutupan Keanggotaan &amp; Tanda Terima Uang</p>
        <p class="judul-sub">Pengembalian Simpanan Anggota — Penutupan CIF</p>
    </div>

    {{-- NOMOR REFERENSI --}}
    <table class="nomor">
        <tr>
            <td class="label">No. Referensi</td>
            <td class="nilai">{{ $no_referensi }}</td>
            <td class="label">Tanggal Penutupan</td>
            <td class="nilai" style="font-family:'DejaVu Sans',sans-serif;font-size:8.8pt;">
                {{ $tanggal_tutup->format('d/m/Y H:i') }}
            </td>
        </tr>
    </table>

    {{-- DATA ANGGOTA --}}
    <div class="blok">
        <p class="blok-judul">A. Data Anggota</p>
        <table class="data">
            <tr>
                <td class="label">Nama Lengkap</td><td class="pemisah">:</td>
                <td class="nilai">{{ $anggota?->nama_lengkap ?? '—' }}</td>
                <td class="label">No. CIF</td><td class="pemisah">:</td>
                <td class="nilai">{{ $anggota?->no_cif ?? '—' }}</td>
            </tr>
            <tr>
                <td class="label">NIK</td><td class="pemisah">:</td>
                <td class="nilai">{{ $nik ?? '—' }}</td>
                <td class="label">No. Anggota</td><td class="pemisah">:</td>
                <td class="nilai">{{ $anggota?->no_anggota ?? '—' }}</td>
            </tr>
            <tr>
                <td class="label">Alamat</td><td class="pemisah">:</td>
                <td class="nilai" colspan="3">{{ $alamat_lengkap }}</td>
            </tr>
            <tr>
                <td class="label">Metode Pembayaran</td><td class="pemisah">:</td>
                <td class="nilai">{{ $metode_label }}</td>
                <td class="label">No. Jurnal</td><td class="pemisah">:</td>
                <td class="nilai">{{ $no_jurnal ?? '—' }}</td>
            </tr>
            @if($rekening_tujuan)
            <tr>
                <td class="label">Rekening Tujuan</td><td class="pemisah">:</td>
                <td class="nilai" colspan="3">{{ $rekening_tujuan }}</td>
            </tr>
            @endif
        </table>
    </div>

    {{-- RINCIAN SALDO --}}
    <div class="blok">
        <p class="blok-judul">B. Rincian Simpanan yang Dicairkan</p>
        <table class="rincian">
            <thead>
                <tr>
                    <th style="width:8mm;">No</th>
                    <th>Komponen Simpanan</th>
                    <th style="width:18mm;">Akun CoA</th>
                    <th style="width:34mm;text-align:right;">Nominal</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>1</td><td>Simpanan Pokok</td><td>3-1001</td>
                    <td class="angka">{{ $rp($r['pokok']) }}</td>
                </tr>
                <tr>
                    <td>2</td><td>Simpanan Wajib</td><td>3-1002</td>
                    <td class="angka">{{ $rp($r['wajib']) }}</td>
                </tr>
                @if($r['sukarela'] > 0)
                <tr>
                    <td>3</td><td>Simpanan Sukarela / Wadiah</td><td>2-1001</td>
                    <td class="angka">{{ $rp($r['sukarela']) }}</td>
                </tr>
                @endif
                @if($r['deposito'] > 0)
                <tr>
                    <td>4</td><td>Simpanan Berjangka / Deposito (jatuh tempo)</td><td>2-1003</td>
                    <td class="angka">{{ $rp($r['deposito']) }}</td>
                </tr>
                @endif
                @if($r['akrual'] > 0)
                <tr>
                    <td>5</td><td>Akrual Hak Bagi Hasil s.d. tanggal pengunduran diri</td><td>5-3001</td>
                    <td class="angka">{{ $rp($r['akrual']) }}</td>
                </tr>
                @endif
                <tr>
                    <td colspan="3" style="text-align:right;font-weight:bold;">Subtotal Bruto</td>
                    <td class="angka" style="font-weight:bold;">{{ $rp($r['bruto']) }}</td>
                </tr>
                <tr class="potongan">
                    <td colspan="3" style="text-align:right;">Potongan Zakat (kewajiban syariah)</td>
                    <td class="angka">&minus; {{ $rp($r['zakat']) }}</td>
                </tr>
                <tr class="potongan">
                    <td colspan="3" style="text-align:right;">Biaya Administrasi Penutupan CIF</td>
                    <td class="angka">&minus; {{ $rp($r['biaya_admin']) }}</td>
                </tr>
                <tr class="total">
                    <td colspan="3" style="text-align:right;">TOTAL NET DITERIMA ANGGOTA</td>
                    <td class="angka">{{ $rp($r['total_net']) }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="pernyataan">
        Dengan menandatangani dokumen ini, anggota menyatakan telah menerima sejumlah uang
        sesuai Total Net di atas sebagai pengembalian seluruh simpanan, dan menyetujui
        penutupan seluruh rekening simpanan serta CIF keanggotaan. Segala hak dan kewajiban
        keanggotaan dinyatakan berakhir pada tanggal penutupan. Potongan zakat diserahkan
        kepada koperasi untuk disalurkan kepada mustahik sesuai ketentuan syariah.
    </div>

    {{-- TANDA TANGAN --}}
    <table class="ttd">
        <tr>
            <td>
                <div class="ket">Anggota yang menerima dana,</div>
                <div class="ruang"></div>
                <div class="nama">{{ $anggota?->nama_lengkap ?? '—' }}</div>
                <div class="ket">NIK: {{ $nik ?? '—' }}</div>
            </td>
            <td>
                <div class="ket">{{ $jabatan_pejabat }},</div>
                <div class="ruang"></div>
                <div class="nama">{{ $pejabat?->name ?? '—' }}</div>
                <div class="ket">{{ $koperasi['nama'] ?? '' }}</div>
                <div class="digital">
                    Ditandatangani secara elektronik &middot; Kode Verifikasi: {{ $kode_verifikasi }}
                </div>
            </td>
        </tr>
    </table>

    {{-- BARCODE --}}
    <div class="barcode">
        @if($barcode_uri !== '')
            <img src="{{ $barcode_uri }}" alt="Barcode {{ $no_referensi }}">
        @endif
        <div class="ref">{{ $no_referensi }}</div>
    </div>

    <div class="footer">
        Dokumen sah tanpa tanda tangan basah. Verifikasi keaslian melalui nomor referensi /
        barcode di atas. Dicetak {{ $tanggal_cetak->format('d/m/Y H:i') }}.
    </div>

</div>
</div>
</body>
</html>
