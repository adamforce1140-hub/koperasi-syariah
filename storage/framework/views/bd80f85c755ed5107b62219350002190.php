<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Addendum Akad — <?php echo e($req->addendum_no); ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: "Times New Roman", Georgia, serif;
            font-size: 12pt; color: #000; background: #e5e7eb;
            margin: 0; padding: 1.5rem 0;
        }
        .sheet {
            width: 210mm; min-height: 297mm; background: #fff; margin: 0 auto;
            padding: 20mm 18mm; box-shadow: 0 2px 12px rgba(0,0,0,.15);
        }
        .toolbar {
            width: 210mm; margin: 0 auto 1rem; display: flex; justify-content: flex-end; gap: .5rem;
        }
        .toolbar button, .toolbar a {
            font-family: Arial, sans-serif; font-size: .85rem; font-weight: 600;
            padding: .5rem 1rem; border-radius: .4rem; border: none; cursor: pointer;
            text-decoration: none; display: inline-block;
        }
        .btn-print { background: #059669; color: #fff; }
        .btn-back  { background: #e2e8f0; color: #334155; }

        .doc-head { text-align: center; border-bottom: 3px double #000; padding-bottom: .6rem; margin-bottom: 1.2rem; }
        .doc-head .kop { font-size: 13pt; font-weight: bold; letter-spacing: .5px; text-transform: uppercase; }
        .doc-head .sub { font-size: 9.5pt; margin-top: .15rem; }
        .doc-title { text-align: center; margin: 1.2rem 0 .2rem; }
        .doc-title h2 { margin: 0; font-size: 13pt; text-decoration: underline; letter-spacing: 1px; }
        .doc-title .no { font-size: 11pt; margin-top: .2rem; }

        .sebab { margin: 1rem 0; text-align: justify; line-height: 1.65; }
        table { width: 100%; border-collapse: collapse; margin: .6rem 0 1rem; font-size: 10.5pt; }
        th, td { border: 1px solid #000; padding: .35rem .5rem; vertical-align: top; }
        th { background: #f3f4f6; text-align: center; font-weight: bold; }
        td.num { text-align: right; }
        td.center { text-align: center; }

        ol.pasal { padding-left: 1.2rem; line-height: 1.6; text-align: justify; }
        ol.pasal > li { margin-bottom: .55rem; }
        .note-syariah {
            border: 1px solid #000; background: #f9fafb; padding: .55rem .75rem;
            font-size: 10pt; margin: .8rem 0; line-height: 1.55;
        }
        .sign-grid { display: flex; justify-content: space-between; margin-top: 2.2rem; text-align: center; font-size: 11pt; }
        .sign-grid .col { width: 45%; }
        .sign-grid .space { height: 4.2rem; }
        .sign-grid .name { font-weight: bold; text-decoration: underline; }
        .footer-note { margin-top: 1.5rem; font-size: 9pt; color: #374151; border-top: 1px solid #9ca3af; padding-top: .4rem; }

        @media print {
            body { background: #fff; padding: 0; }
            .toolbar { display: none; }
            .sheet { width: auto; min-height: auto; box-shadow: none; margin: 0; padding: 0; }
            @page { size: A4; margin: 18mm; }
        }
    </style>
</head>
<body>

<div class="toolbar">
    <a class="btn-back" href="<?php echo e(route('manajer.approval-restrukturisasi.index', ['status' => 'APPROVED'])); ?>">← Kembali</a>
    <button class="btn-print" onclick="window.print()">🖨 Cetak / Simpan PDF</button>
</div>

<div class="sheet">
    <?php
        $pembiayaan = $req->pembiayaan;
        $anggota    = $pembiayaan?->anggota;
        $totalBaru  = $jadwalBaru->sum('jumlah_angsuran');
        $angsuranBaru = $jadwalBaru->first()?->jumlah_angsuran ?? 0;
        $tenorBaru  = $jadwalBaru->count() ?: (int) $req->tenor_baru;
    ?>

    <div class="doc-head">
        <div class="kop">Koperasi Syariah</div>
        <div class="sub">Melayani Anggota dengan Prinsip Syariah — Akad Murabahah / Mudharabah / Musyarakah</div>
    </div>

    <div class="doc-title">
        <h2>ADDENDUM AKAD PEMBIAYAAN SYARIAH</h2>
        <div class="no">Nomor: <?php echo e($req->addendum_no); ?></div>
    </div>

    <div class="sebab">
        Pada hari ini, <strong><?php echo e(optional($req->keputusan_at)->translatedFormat('l') ?? now()->translatedFormat('l')); ?></strong>,
        tanggal <strong><?php echo e(optional($req->keputusan_at)->translatedFormat('d F Y') ?? now()->translatedFormat('d F Y')); ?></strong>,
        bertempat di Kantor Koperasi Syariah, para pihak yang bertanda tangan di bawah ini sepakat mengadakan
        addendum (penyesuaian) atas akad pembiayaan yang telah disepakati sebelumnya, dengan dasar:
    </div>

    <ol class="pasal">
        <li>
            Bahwa Anggota telah memiliki akad pembiayaan <strong><?php echo e($pembiayaan?->akad_pembiayaan ?? '-'); ?></strong>
            dengan Nomor Pembiayaan <strong><?php echo e($pembiayaan?->no_pembiayaan ?? '-'); ?></strong> tertanggal
            <strong><?php echo e(optional($pembiayaan?->tanggal_akad)->translatedFormat('d F Y') ?? '-'); ?></strong>.
        </li>
        <li>
            Bahwa Anggota mengajukan permohonan <strong>Restrukturisasi (<?php echo e($req->skema_restrukturisasi); ?>)</strong>
            dengan alasan: <em><?php echo e($req->alasan_restrukturisasi); ?></em><?php echo e($req->catatan_ao ? ' — ' . $req->catatan_ao : ''); ?>.
        </li>
        <li>
            Bahwa berdasarkan analisis kemampuan bayar Account Officer dan keputusan Manajer, permohonan tersebut
            <strong>DISETUJUI</strong> dengan ketentuan sebagaimana diuraikan dalam Pasal-Pasal berikut.
        </li>
    </ol>

    <table>
        <tr>
            <th style="width:38%;">Pihak</th>
            <th>Keterangan</th>
        </tr>
        <tr>
            <td>Anggota (Pihak Pertama)</td>
            <td>
                Nama: <strong><?php echo e($anggota?->nama_lengkap ?? '-'); ?></strong><br>
                No. Anggota: <?php echo e($anggota?->no_anggota ?? '-'); ?> · NIK: <?php echo e($anggota?->nik ?? '-'); ?><br>
                Alamat: <?php echo e($anggota?->alamat ?? '-'); ?>

                <?php echo e($anggota?->kelurahan ? ', Kel. ' . $anggota->kelurahan : ''); ?>

                <?php echo e($anggota?->kecamatan ? ', Kec. ' . $anggota->kecamatan : ''); ?>

                <?php echo e($anggota?->kota ? ', ' . $anggota->kota : ''); ?>

            </td>
        </tr>
        <tr>
            <td>Koperasi Syariah (Pihak Kedua)</td>
            <td>
                Manajer: <strong><?php echo e($req->manajer?->name ?? '-'); ?></strong><br>
                Bertindak untuk dan atas nama Koperasi Syariah
            </td>
        </tr>
    </table>

    <h3 style="font-size:11.5pt;margin:1rem 0 .3rem;">PASAL 1 — PERUBAHAN JADWAL ANGSURAN</h3>
    <ol class="pasal">
        <li>
            Para pihak sepakat mengubah jadwal angsuran pembiayaan sebagai berikut:
            <table style="margin-top:.4rem;">
                <tr>
                    <th>Uraian</th>
                    <th>Sebelum Addendum</th>
                    <th>Setelah Addendum</th>
                </tr>
                <tr>
                    <td>Skema</td>
                    <td class="center">Akad Awal</td>
                    <td class="center"><?php echo e($req->skema_restrukturisasi); ?></td>
                </tr>
                <tr>
                    <td>Angsuran per Bulan</td>
                    <td class="num">Rp <?php echo e(number_format($req->angsuran_lama_snapshot, 0, ',', '.')); ?></td>
                    <td class="num">Rp <?php echo e(number_format($angsuranBaru, 0, ',', '.')); ?></td>
                </tr>
                <tr>
                    <td>Sisa Tenor</td>
                    <td class="center"><?php echo e($req->tenor_baru ? $req->tenor_baru . ' bulan' : '-'); ?></td>
                    <td class="center"><?php echo e($tenorBaru); ?> bulan</td>
                </tr>
                <tr>
                    <td>Jatuh Tempo Terakhir</td>
                    <td class="center"><?php echo e(optional($pembiayaan?->tanggal_jatuh_tempo)->format('d/m/Y') ?? '-'); ?></td>
                    <td class="center"><?php echo e($jadwalBaru->last()?->tanggal_jatuh_tempo?->format('d/m/Y') ?? '-'); ?></td>
                </tr>
            </table>
        </li>
        <li>
            <strong>Total sisa piutang TIDAK berubah</strong>, yaitu tetap sebesar
            <strong>Rp <?php echo e(number_format($req->sisa_piutang_snapshot, 0, ',', '.')); ?></strong>
            (sisa pokok Rp <?php echo e(number_format($req->sisa_pokok_snapshot, 0, ',', '.')); ?> +
            sisa margin Rp <?php echo e(number_format($req->sisa_margin_snapshot, 0, ',', '.')); ?>).
        </li>
        <li>
            Perpanjangan tenor (Rescheduling) hanya memperkecil nominal angsuran bulanan dengan cara
            memperpanjang jangka waktu, <strong>tanpa menambah harga jual, margin, atau denda apa pun</strong>.
        </li>
    </ol>

    <h3 style="font-size:11.5pt;margin:1rem 0 .3rem;">PASAL 2 — JADWAL ANGSURAN BARU</h3>
    <table>
        <thead>
            <tr>
                <th style="width:12%;">Ke-</th>
                <th style="width:24%;">Jatuh Tempo</th>
                <th style="width:21%;">Pokok</th>
                <th style="width:21%;">Margin</th>
                <th style="width:22%;">Jumlah Angsuran</th>
            </tr>
        </thead>
        <tbody>
            <?php $__empty_1 = true; $__currentLoopData = $jadwalBaru; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $a): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <tr>
                <td class="center"><?php echo e($a->angsuran_ke); ?></td>
                <td class="center"><?php echo e(optional($a->tanggal_jatuh_tempo)->format('d/m/Y')); ?></td>
                <td class="num"><?php echo e(number_format($a->pokok_angsuran, 0, ',', '.')); ?></td>
                <td class="num"><?php echo e(number_format($a->margin_ujroh, 0, ',', '.')); ?></td>
                <td class="num"><?php echo e(number_format($a->jumlah_angsuran, 0, ',', '.')); ?></td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <tr><td colspan="5" class="center">Tidak ada jadwal angsuran aktif.</td></tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <th colspan="2" style="text-align:right;">TOTAL</th>
                <th class="num"><?php echo e(number_format($jadwalBaru->sum('pokok_angsuran'), 0, ',', '.')); ?></th>
                <th class="num"><?php echo e(number_format($jadwalBaru->sum('margin_ujroh'), 0, ',', '.')); ?></th>
                <th class="num"><?php echo e(number_format($totalBaru, 0, ',', '.')); ?></th>
            </tr>
        </tfoot>
    </table>

    <h3 style="font-size:11.5pt;margin:1rem 0 .3rem;">PASAL 3 — KETENTUAN SYARIAH & LAIN-LAIN</h3>
    <ol class="pasal">
        <li>
            Addendum ini disusun dengan berpedoman pada <strong>Fatwa DSN-MUI No. 49/DSN-MUI/IV/2005</strong> tentang
            Penyelesaian Utang Piutang, serta ketentuan restrukturisasi pembiayaan syariah yang berlaku.
        </li>
        <li>
            Perpanjangan jangka waktu tidak boleh menjadi sarana penambahan utang/margin. Apabila Anggota mengalami
            kesulitan pembayaran, Koperasi dapat melakukan penjadwalan ulang tanpa imbalan tambahan.
        </li>
        <li>
            Status kolektibilitas pembiayaan ditetapkan menjadi <strong>Restrukturisasi (Lancar)</strong> terhitung
            sejak tanggal addendum ini ditandatangani, sepanjang Anggota disiplin memenuhi jadwal baru.
        </li>
        <li>
            Hal-hal yang belum diatur dalam addendum ini tetap mengacu pada akad pembiayaan awal dan peraturan
            Koperasi Syariah yang berlaku.
        </li>
    </ol>

    <div class="note-syariah">
        <strong>Catatan Syariah:</strong> Total kewajiban Anggota setelah addendum tetap
        <strong>Rp <?php echo e(number_format($req->sisa_piutang_snapshot, 0, ',', '.')); ?></strong> — sama persis dengan sisa
        piutang sebelum restrukturisasi. Tidak terdapat penambahan harga jual maupun margin baru atas perpanjangan
        waktu (bebas dari riba al-jahiliyyah).
    </div>

    <div class="sebab" style="margin-top:1.2rem;">
        Demikian addendum ini dibuat dan ditandatangani oleh kedua belah pihak dalam keadaan sadar, sehat, dan tanpa
        paksaan dari pihak mana pun, serta menjadi bagian yang tidak terpisahkan dari akad pembiayaan awal.
    </div>

    <div class="sign-grid">
        <div class="col">
            <div>Pihak Pertama — Anggota</div>
            <div class="space"></div>
            <div class="name"><?php echo e($anggota?->nama_lengkap ?? '..........................'); ?></div>
        </div>
        <div class="col">
            <div>Pihak Kedua — Koperasi Syariah</div>
            <div class="space"></div>
            <div class="name"><?php echo e($req->manajer?->name ?? '..........................'); ?></div>
            <div>Manajer</div>
        </div>
    </div>

    <div class="footer-note">
        Dokumen ini diterbitkan secara sistem oleh aplikasi Koperasi Syariah pada
        <?php echo e(now()->translatedFormat('d F Y, H:i')); ?> WIB · Nomor Addendum: <?php echo e($req->addendum_no); ?>

    </div>
</div>

</body>
</html>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\manajer\addendum-restrukturisasi.blade.php ENDPATH**/ ?>