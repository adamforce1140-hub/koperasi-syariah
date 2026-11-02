<?php
    /**
     * BUKTI PENUTUPAN KEANGGOTAAN — THERMAL RECEIPT 80 mm.
     *
     * Dirender sebagai HTML mandiri (bukan layout aplikasi) agar dapat
     * langsung dicetak dari dialog print browser ke printer thermal 80 mm
     * (lebar kertas 80 mm, area cetak ±72 mm).
     *
     * NIK sengaja DISAMARKAN pada struk: struk mudah tercecer/terlihat pihak
     * lain, sedangkan NIK penuh sudah tercetak pada dokumen PDF resmi
     * (UU PDP No. 27/2022 — minimalisasi data).
     */

    $rp = fn ($v) => number_format((float) $v, 0, ',', '.');
    $r = $rincian;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Struk {{ $no_referensi }}</title>
    <style>
        @page { size: 80mm auto; margin: 3mm; }

        * { box-sizing: border-box; }

        body {
            width: 74mm;
            margin: 0 auto;
            font-family: "DejaVu Sans Mono", "Courier New", monospace;
            font-size: 9px;
            line-height: 1.35;
            color: #000;
            background: #fff;
        }

        .center { text-align: center; }
        .bold { font-weight: bold; }
        .kecil { font-size: 8px; }

        .nama-koperasi { font-size: 11px; font-weight: bold; letter-spacing: .3px; }
        .alamat { font-size: 7.5px; }

        hr { border: none; border-top: 1px dashed #000; margin: 4px 0; }
        hr.solid { border-top: 1px solid #000; }

        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 1px 0; font-size: 9px; }
        td.kanan { text-align: right; white-space: nowrap; }
        td.label { width: 26mm; }

        .baris-total {
            font-size: 11px;
            font-weight: bold;
        }

        .barcode { text-align: center; margin-top: 4px; }
        .barcode svg { max-width: 100%; height: 11mm; }
        .ref { font-size: 8px; letter-spacing: .8px; }

        .verifikasi { font-size: 7px; word-break: break-all; }

        .toolbar { margin: 6px 0; text-align: center; }
        .toolbar button {
            font: inherit; font-size: 11px; padding: 6px 14px;
            border: 1px solid #000; background: #fff; cursor: pointer;
        }

        @media print {
            .toolbar { display: none; }
            body { width: 74mm; }
        }
    </style>
</head>
<body>

<div class="toolbar">
    <button type="button" onclick="window.print()">Cetak Struk</button>
</div>

<div class="center">
    <div class="nama-koperasi">{{ $koperasi['nama'] ?? 'KOPERASI SYARIAH' }}</div>
    <div class="alamat">
        {{ $koperasi['alamat'] ?? '' }}@if(!empty($koperasi['kota'])), {{ $koperasi['kota'] }}@endif
    </div>
    <div class="alamat">Telp. {{ $koperasi['telepon'] ?? '-' }}</div>
</div>

<hr class="solid">

<div class="center bold" style="font-size:10px;">
    BUKTI PENUTUPAN KEANGGOTAAN<br>&amp; TANDA TERIMA UANG
</div>

<hr>

<table>
    <tr><td class="label">No. Referensi</td><td class="kanan bold">{{ $no_referensi }}</td></tr>
    <tr><td class="label">Tanggal</td><td class="kanan">{{ $tanggal_tutup->format('d/m/Y H:i') }}</td></tr>
    <tr><td class="label">No. Jurnal</td><td class="kanan">{{ $no_jurnal ?? '—' }}</td></tr>
</table>

<hr>

<table>
    <tr><td class="label">Nama</td><td class="kanan bold">{{ $anggota?->nama_lengkap ?? '—' }}</td></tr>
    <tr><td class="label">No. CIF</td><td class="kanan">{{ $anggota?->no_cif ?? '—' }}</td></tr>
    <tr><td class="label">No. Anggota</td><td class="kanan">{{ $anggota?->no_anggota ?? '—' }}</td></tr>
    <tr><td class="label">NIK</td><td class="kanan">{{ $nik_masked ?? '—' }}</td></tr>
    <tr><td class="label">Alamat</td><td class="kanan kecil">{{ $alamat_lengkap }}</td></tr>
</table>

<hr>

<table>
    <tr><td>Simpanan Pokok</td><td class="kanan">{{ $rp($r['pokok']) }}</td></tr>
    <tr><td>Simpanan Wajib</td><td class="kanan">{{ $rp($r['wajib']) }}</td></tr>
    @if($r['sukarela'] > 0)
    <tr><td>Simpanan Sukarela</td><td class="kanan">{{ $rp($r['sukarela']) }}</td></tr>
    @endif
    @if($r['deposito'] > 0)
    <tr><td>Simpanan Berjangka</td><td class="kanan">{{ $rp($r['deposito']) }}</td></tr>
    @endif
    @if($r['akrual'] > 0)
    <tr><td>Akrual Bagi Hasil</td><td class="kanan">{{ $rp($r['akrual']) }}</td></tr>
    @endif
    <tr><td class="bold">Subtotal Bruto</td><td class="kanan bold">{{ $rp($r['bruto']) }}</td></tr>
    <tr><td>Potongan Zakat</td><td class="kanan">&minus; {{ $rp($r['zakat']) }}</td></tr>
    <tr><td>Biaya Admin CIF</td><td class="kanan">&minus; {{ $rp($r['biaya_admin']) }}</td></tr>
</table>

<hr class="solid">

<table>
    <tr class="baris-total">
        <td>TOTAL NET DITERIMA</td>
        <td class="kanan">Rp {{ $rp($r['total_net']) }}</td>
    </tr>
    <tr>
        <td class="kecil">Metode</td>
        <td class="kanan kecil">{{ $metode_label }}</td>
    </tr>
    @if($rekening_tujuan)
    <tr>
        <td class="kecil">Rekening Tujuan</td>
        <td class="kanan kecil">{{ $rekening_tujuan }}</td>
    </tr>
    @endif
</table>

<hr>

<div class="center kecil">
    Dana telah diterima dengan baik oleh anggota.<br>
    Seluruh rekening simpanan &amp; CIF ditutup.<br>
    Status keanggotaan: <span class="bold">RESIGNED / TERMINATED</span>
</div>

<hr>

<table>
    <tr>
        <td class="kecil">Petugas</td>
        <td class="kanan kecil bold">{{ $pejabat?->name ?? '—' }}</td>
    </tr>
    <tr>
        <td class="kecil">Jabatan</td>
        <td class="kanan kecil">{{ $jabatan_pejabat }}</td>
    </tr>
    <tr>
        <td class="kecil">Dicetak</td>
        <td class="kanan kecil">{{ $tanggal_cetak->format('d/m/Y H:i') }}</td>
    </tr>
</table>

<div class="barcode">
    {!! $barcode_svg !!}
</div>

<div class="center verifikasi">
    Kode Verifikasi Digital: {{ $kode_verifikasi }}
</div>

<div class="center kecil" style="margin-top:4px;">
    Dokumen sah — simpan sebagai bukti penutupan keanggotaan.
</div>

<script>
    // Struk thermal langsung dibuka dalam mode cetak; pengguna tetap dapat
    // menutup dialog untuk menyimpan/memeriksa terlebih dahulu.
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 300);
    });
</script>

</body>
</html>
