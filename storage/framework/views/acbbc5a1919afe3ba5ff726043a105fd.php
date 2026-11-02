<?php
    /**
     * Bilyet Simpanan Berjangka Mudharabah — dokumen PDF (dompdf).
     *
     * Variabel: $deposito, $anggota, $produk, $koperasi, $logo, $nomorBilyet,
     *           $namaFile, $margin.
     *
     * Catatan teknis dompdf:
     *  - Hanya CSS 2.1 yang didukung (tanpa flex/grid). Tata letak memakai
     *    `table` agar konsisten.
     *  - Font default DejaVu Sans agar karakter unicode (·, ±, —) tercetak.
     *  - Tidak ada aset gambar wajib: bila logo tidak tersedia, dipakai
     *    emblem monogram HTML/CSS.
     */

    use Carbon\Carbon;

    $fmtRupiah = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');

    $tanggalAkad    = $deposito->start_date ? Carbon::parse($deposito->start_date) : null;
    $tanggalTempo   = $deposito->maturity_date ? Carbon::parse($deposito->maturity_date) : null;
    $tanggalTerbit  = Carbon::now();

    $tenor = $deposito->tenorMonths();

    // Alamat anggota disusun lengkap dari kolom yang tersedia.
    $alamatParts = array_filter([
        $anggota?->alamat,
        $anggota?->rt_rw ? 'RT/RW ' . $anggota->rt_rw : null,
        $anggota?->kelurahan,
        $anggota?->kecamatan,
        $anggota?->kota,
        $anggota?->provinsi,
        $anggota?->kode_pos,
    ], fn ($v) => filled($v));
    $alamatLengkap = $alamatParts ? implode(', ', $alamatParts) : '—';

    $nomorIdentitas = $anggota?->no_cif ?: ($anggota?->no_anggota ?: '—');

    $nisbahAnggota  = (float) $deposito->nisbah_anggota;
    $nisbahKoperasi = (float) $deposito->nisbah_koperasi;

    $marginTop    = (int) ($margin['top'] ?? 20);
    $marginRight  = (int) ($margin['right'] ?? 20);
    $marginBottom = (int) ($margin['bottom'] ?? 20);
    $marginLeft   = (int) ($margin['left'] ?? 20);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title><?php echo e($namaFile ?? 'Bilyet Deposito'); ?></title>
    <style>
        @page {
            size: A4 portrait;
            margin: <?php echo e($marginTop); ?>mm <?php echo e($marginRight); ?>mm <?php echo e($marginBottom); ?>mm <?php echo e($marginLeft); ?>mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 10pt;
            color: #14261c;
            margin: 0;
            padding: 0;
            line-height: 1.45;
        }

        /* ============ BINGKAI GANDA BERGAYA KOPERASI SYARIAH ============ */

        .frame-outer {
            border: 2pt solid #0d5c3f;
            padding: 3mm;
        }

        .frame-inner {
            border: 0.7pt solid #0d5c3f;
            padding: 6mm 6mm 5mm 6mm;
        }

        /* Ornamen islami sederhana: garis bergerigi di bawah kop. */
        .ornament {
            border-top: 0.7pt solid #0d5c3f;
            border-bottom: 0.7pt solid #0d5c3f;
            height: 2mm;
            margin: 3mm 0 4mm 0;
            background-color: #f2f8f4;
        }

        /* ============================ KOP ============================ */

        .kop {
            width: 100%;
            border-collapse: collapse;
        }

        .kop td {
            vertical-align: middle;
            border: none;
            padding: 0;
        }

        .kop-logo {
            width: 26mm;
            text-align: center;
        }

        .emblem {
            width: 20mm;
            height: 20mm;
            margin: 0 auto;
            border: 1.4pt solid #0d5c3f;
            border-radius: 50%;
            background-color: #f2f8f4;
            text-align: center;
        }

        .emblem-inner {
            margin: 1.6mm auto 0 auto;
            width: 15mm;
            height: 15mm;
            border: 0.7pt solid #b48a2f;
            border-radius: 50%;
            text-align: center;
        }

        .emblem-text {
            font-size: 12pt;
            font-weight: bold;
            color: #0d5c3f;
            padding-top: 3.6mm;
            letter-spacing: 0.5pt;
        }

        .emblem-sub {
            font-size: 5pt;
            color: #b48a2f;
            padding-top: 0.4mm;
            letter-spacing: 0.4pt;
        }

        .kop-identitas {
            padding-left: 4mm !important;
        }

        .kop-nama {
            font-size: 13pt;
            font-weight: bold;
            color: #0d5c3f;
            letter-spacing: 0.3pt;
            margin: 0;
        }

        .kop-alamat {
            font-size: 7.6pt;
            color: #33513f;
            margin: 0.8mm 0 0 0;
        }

        .kop-badan-hukum {
            font-size: 7.2pt;
            font-style: italic;
            color: #6b6b6b;
            margin: 0.6mm 0 0 0;
        }

        /* ============================ JUDUL ============================ */

        .judul {
            text-align: center;
            margin: 0 0 1mm 0;
        }

        .judul-utama {
            font-size: 14pt;
            font-weight: bold;
            color: #0d5c3f;
            letter-spacing: 1pt;
            margin: 0;
            text-transform: uppercase;
        }

        .judul-sub {
            font-size: 8.4pt;
            color: #33513f;
            margin: 1mm 0 0 0;
            letter-spacing: 0.3pt;
        }

        /* ======================= NOMOR BILYET ======================= */

        .nomor-bilyet {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4mm;
        }

        .nomor-bilyet td {
            border: 0.7pt solid #0d5c3f;
            background-color: #f2f8f4;
            padding: 2mm 3mm;
            font-size: 9.4pt;
        }

        .nomor-bilyet .label {
            width: 34mm;
            font-weight: bold;
            color: #0d5c3f;
        }

        .nomor-bilyet .nilai {
            font-family: "DejaVu Sans Mono", monospace;
            font-size: 11pt;
            font-weight: bold;
            letter-spacing: 0.8pt;
            color: #14261c;
        }

        /* ========================= BLOK DATA ========================= */

        .blok {
            margin-bottom: 4mm;
        }

        .blok-judul {
            font-size: 9.4pt;
            font-weight: bold;
            color: #ffffff;
            background-color: #0d5c3f;
            padding: 1.6mm 3mm;
            letter-spacing: 0.5pt;
        }

        table.data {
            width: 100%;
            border-collapse: collapse;
            border: 0.7pt solid #b9cfc3;
            border-top: none;
        }

        table.data td {
            border: 0.5pt solid #d9e6de;
            padding: 1.7mm 3mm;
            font-size: 9.2pt;
            vertical-align: top;
        }

        table.data td.label {
            width: 46mm;
            background-color: #f7fbf9;
            color: #33513f;
        }

        table.data td.nilai {
            font-weight: bold;
            color: #14261c;
        }

        .terbilang {
            font-style: italic;
            font-weight: normal;
            color: #0d5c3f;
            font-size: 8.8pt;
        }

        .catatan-kecil {
            font-size: 7.6pt;
            color: #6b6b6b;
            margin: 1.4mm 0 0 0;
        }

        /* ========================== FOOTER ========================== */

        .terbit {
            font-size: 9.2pt;
            text-align: right;
            margin: 2mm 0 5mm 0;
        }

        table.ttd {
            width: 100%;
            border-collapse: collapse;
            margin-top: 2mm;
        }

        table.ttd td {
            width: 50%;
            text-align: center;
            border: none;
            padding: 0 4mm;
            font-size: 9pt;
            vertical-align: top;
        }

        .ttd-jabatan {
            margin-bottom: 16mm;
        }

        .ttd-garis {
            border-top: 0.7pt solid #14261c;
            padding-top: 1.4mm;
            font-weight: bold;
        }

        .ttd-nama {
            font-size: 8.4pt;
            color: #33513f;
        }

        /* ==================== KETENTUAN AKAD ==================== */

        .ketentuan {
            margin-top: 6mm;
            border: 0.7pt solid #b48a2f;
            background-color: #fdfaf2;
            padding: 3mm 3.5mm;
        }

        .ketentuan-judul {
            font-size: 8.6pt;
            font-weight: bold;
            color: #8a6420;
            margin: 0 0 1.6mm 0;
            letter-spacing: 0.4pt;
        }

        .ketentuan ol {
            margin: 0;
            padding-left: 5mm;
        }

        .ketentuan li {
            font-size: 7.6pt;
            color: #3d3d3d;
            margin-bottom: 0.9mm;
            text-align: justify;
        }

        .kaki {
            margin-top: 4mm;
            font-size: 6.8pt;
            color: #8a8a8a;
            text-align: center;
        }
    </style>
</head>
<body>

<div class="frame-outer">
    <div class="frame-inner">

        
        <table class="kop">
            <tr>
                <td class="kop-logo">
                    <?php if(!empty($logo)): ?>
                        <img src="<?php echo e($logo); ?>" alt="Logo <?php echo e($koperasi['nama'] ?? 'Koperasi'); ?>"
                             style="width: 22mm; height: auto;">
                    <?php else: ?>
                        <div class="emblem">
                            <div class="emblem-inner">
                                <div class="emblem-text">KS</div>
                                <div class="emblem-sub">SYARIAH</div>
                            </div>
                        </div>
                    <?php endif; ?>
                </td>
                <td class="kop-identitas">
                    <p class="kop-nama"><?php echo e($koperasi['nama'] ?? 'KOPERASI SYARIAH'); ?></p>
                    <p class="kop-alamat">
                        <?php echo e($koperasi['alamat'] ?? '-'); ?><br>
                        <?php echo e($koperasi['kota'] ?? ''); ?> <?php echo e($koperasi['kode_pos'] ?? ''); ?>

                        &middot; Telp. <?php echo e($koperasi['telepon'] ?? '-'); ?>

                        &middot; <?php echo e($koperasi['email'] ?? '-'); ?>

                    </p>
                    <?php if(!empty($koperasi['badan_hukum'])): ?>
                        <p class="kop-badan-hukum"><?php echo e($koperasi['badan_hukum']); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <div class="ornament"></div>

        
        <div class="judul">
            <p class="judul-utama">BILYET SIMPANAN BERJANGKA MUDHARABAH</p>
            <p class="judul-sub">
                Bukti Penempatan Dana Syirkah Temporer &middot; Akad Mudharabah Muthlaqah
            </p>
        </div>

        
        <table class="nomor-bilyet">
            <tr>
                <td class="label">NOMOR BILYET</td>
                <td class="nilai"><?php echo e($nomorBilyet ?: '—'); ?></td>
            </tr>
        </table>

        
        <div class="blok">
            <div class="blok-judul">A. DATA PEMILIK (ANGGOTA)</div>
            <table class="data">
                <tr>
                    <td class="label">Nama Lengkap</td>
                    <td class="nilai"><?php echo e($anggota?->nama_lengkap ?? '—'); ?></td>
                </tr>
                <tr>
                    <td class="label">Nomor CIF / Nomor Anggota</td>
                    <td class="nilai">
                        <?php echo e($nomorIdentitas); ?>

                        <?php if($anggota?->no_cif && $anggota?->no_anggota): ?>
                            <span style="font-weight: normal; color:#33513f;">
                                (CIF: <?php echo e($anggota->no_cif); ?> / Anggota: <?php echo e($anggota->no_anggota); ?>)
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td class="label">NIK</td>
                    <td class="nilai"><?php echo e($anggota?->nik ?: '—'); ?></td>
                </tr>
                <tr>
                    <td class="label">Alamat Tempat Tinggal</td>
                    <td class="nilai"><?php echo e($alamatLengkap); ?></td>
                </tr>
                <tr>
                    <td class="label">Nomor Telepon</td>
                    <td class="nilai"><?php echo e($anggota?->no_hp ?: ($anggota?->no_telepon ?: '—')); ?></td>
                </tr>
            </table>
        </div>

        
        <div class="blok">
            <div class="blok-judul">B. RINCIAN SIMPANAN BERJANGKA</div>
            <table class="data">
                <tr>
                    <td class="label">Nominal Modal</td>
                    <td class="nilai">
                        <?php echo e($fmtRupiah($deposito->principal_amount)); ?>

                    </td>
                </tr>
                <tr>
                    <td class="label">Terbilang</td>
                    <td class="nilai">
                        <span class="terbilang">
                            (<?php echo e(terbilang((float) $deposito->principal_amount)); ?> Rupiah)
                        </span>
                    </td>
                </tr>
                <tr>
                    <td class="label">Jenis Produk</td>
                    <td class="nilai"><?php echo e($produk?->name ?? 'Simpanan Berjangka Mudharabah'); ?></td>
                </tr>
                <tr>
                    <td class="label">Tenor</td>
                    <td class="nilai"><?php echo e($tenor); ?> Bulan</td>
                </tr>
                <tr>
                    <td class="label">Nisbah Bagi Hasil</td>
                    <td class="nilai">
                        Anggota <?php echo e(rtrim(rtrim(number_format($nisbahAnggota, 2, ',', '.'), '0'), ',')); ?>%
                        &nbsp;:&nbsp;
                        Koperasi <?php echo e(rtrim(rtrim(number_format($nisbahKoperasi, 2, ',', '.'), '0'), ',')); ?>%
                    </td>
                </tr>
                <tr>
                    <td class="label">Tanggal Akad</td>
                    <td class="nilai">
                        <?php echo e($tanggalAkad ? $tanggalAkad->translatedFormat('d F Y') : '—'); ?>

                    </td>
                </tr>
                <tr>
                    <td class="label">Tanggal Jatuh Tempo</td>
                    <td class="nilai">
                        <?php echo e($tanggalTempo ? $tanggalTempo->translatedFormat('d F Y') : '—'); ?>

                    </td>
                </tr>
                <tr>
                    <td class="label">Perpanjangan Otomatis (ARO)</td>
                    <td class="nilai"><?php echo e($deposito->aro_label ?? 'Tidak Perpanjang / Non-ARO'); ?></td>
                </tr>
                <?php if((int) $deposito->roll_count > 0): ?>
                <tr>
                    <td class="label">Riwayat Perpanjangan</td>
                    <td class="nilai">
                        Roll ke-<?php echo e((int) $deposito->roll_count); ?>

                        <?php if($deposito->last_rolled_at): ?>
                            — terakhir <?php echo e($deposito->last_rolled_at->translatedFormat('d F Y')); ?>

                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td class="label">Status</td>
                    <td class="nilai"><?php echo e($deposito->status); ?></td>
                </tr>
            </table>
            <p class="catatan-kecil">
                Nominal modal di atas merupakan pokok penempatan dana syirkah temporer dan
                tidak dijanjikan imbalan tetap. Bagi hasil dihitung atas nisbah kesepakatan
                terhadap pendapatan koperasi yang riil, bukan atas dasar bunga.
            </p>
        </div>

        
        <p class="terbit">
            <?php echo e($koperasi['kota'] ?? ''); ?>, <?php echo e($tanggalTerbit->translatedFormat('d F Y')); ?>

        </p>

        <table class="ttd">
            <tr>
                <td>
                    <div class="ttd-jabatan">Pengurus Koperasi</div>
                    <div class="ttd-garis">(&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;)</div>
                    <div class="ttd-nama">Nama &amp; Tanda Tangan</div>
                </td>
                <td>
                    <div class="ttd-jabatan">Teller / Petugas</div>
                    <div class="ttd-garis">(&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;)</div>
                    <div class="ttd-nama">Nama &amp; Tanda Tangan</div>
                </td>
            </tr>
        </table>

        
        <div class="ketentuan">
            <p class="ketentuan-judul">KETENTUAN AKAD MUDHARABAH MUTHLAQAH</p>
            <ol>
                <li>
                    Anggota bertindak sebagai <strong>shahibul maal</strong> (pemilik dana) dan
                    Koperasi bertindak sebagai <strong>mudharib</strong> (pengelola dana) dengan
                    akad Mudharabah Muthlaqah, yaitu penyerahan dana tanpa pembatasan jenis usaha,
                    tempat, maupun waktu pengelolaan.
                </li>
                <li>
                    Keuntungan dibagi sesuai nisbah yang telah disepakati pada bilyet ini.
                    Nisbah bersifat mengikat dan tidak dapat diubah secara sepihak tanpa
                    addendum tertulis.
                </li>
                <li>
                    Kerugian pengelolaan dana pada harta syirkah ditanggung sepenuhnya oleh
                    pemilik dana (anggota), dan semata-mata menjadi beban pengelola apabila
                    terbukti disebabkan kelalaian, kesengajaan, atau pelanggaran prinsip syariah.
                </li>
                <li>
                    Pengelola tidak menjanjikan pemberian imbalan atau pembagian keuntungan
                    tetap dalam bentuk apa pun (bebas riba), serta tidak mewakilkan dana pada
                    objek yang bertentangan dengan prinsip syariah.
                </li>
                <li>
                    Pencairan pokok dilakukan pada saat jatuh tempo. Pencairan sebelum jatuh
                    tempo dikenakan pengurangan bagi hasil yang belum accrued dan/atau biaya
                    administrasi sesuai ketentuan koperasi yang berlaku.
                </li>
                <li>
                    Bilyet ini merupakan bukti kepemilikan yang sah dan hanya dapat dicairkan
                    dengan menyerahkan bilyet asli serta dokumen identitas anggota. Bilyet
                    tidak dapat dipindahtangankan kecuali dengan addendum tertulis.
                </li>
                <li>
                    Hal-hal yang belum diatur dalam bilyet ini diselesaikan secara musyawarah
                    dan/atau mengikuti ketentuan internal koperasi, PSAK 101/108, serta fatwa
                    DSN-MUI yang berlaku.
                </li>
            </ol>
        </div>

        <p class="kaki">
            Dicetak otomatis oleh Sistem Informasi <?php echo e($koperasi['nama'] ?? 'Koperasi Syariah'); ?>

            &middot; <?php echo e($namaFile); ?>

        </p>

    </div>
</div>

</body>
</html>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\deposito\bilyet-pdf.blade.php ENDPATH**/ ?>