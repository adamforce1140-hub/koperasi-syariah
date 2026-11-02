<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nota Transaksi - <?php echo e($transaksi->no_transaksi ?? $transaksi->id); ?></title>
    <style>
        /* ===== SCREEN STYLES ===== */
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Courier New', Courier, monospace;
            background: #f3f4f6;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 2rem 1rem;
            min-height: 100vh;
        }

        .action-bar {
            display: flex;
            gap: .75rem;
            margin-bottom: 1.5rem;
            width: 100%;
            max-width: 380px;
        }

        .btn {
            flex: 1;
            padding: .65rem 1rem;
            border: none;
            border-radius: 6px;
            font-size: .95rem;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            display: inline-block;
        }
        .btn-print  { background: #1d4ed8; color: #fff; }
        .btn-new    { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
        .btn:hover  { opacity: .9; }

        /* ===== NOTA / STRUK ===== */
        .nota-wrapper {
            background: #fff;
            width: 100%;
            max-width: 380px;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
            padding: 1.25rem 1rem;
        }

        .nota-header { text-align: center; margin-bottom: .75rem; }
        .nota-header .kop-nama {
            font-size: 1rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .nota-header .kop-alamat {
            font-size: .72rem;
            color: #6b7280;
            margin-top: .2rem;
        }

        .divider {
            border: none;
            border-top: 1px dashed #9ca3af;
            margin: .6rem 0;
        }
        .divider-solid {
            border: none;
            border-top: 2px solid #111827;
            margin: .6rem 0;
        }

        .nota-title {
            text-align: center;
            font-size: .9rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            margin: .4rem 0;
        }

        .nota-meta { font-size: .72rem; color: #374151; margin-bottom: .3rem; }
        .nota-meta table { width: 100%; }
        .nota-meta td:first-child { width: 40%; color: #6b7280; }
        .nota-meta td:last-child  { font-weight: 600; }

        .nota-body { font-size: .75rem; }
        .nota-body table { width: 100%; border-collapse: collapse; }
        .nota-body tr td { padding: .2rem 0; vertical-align: top; }
        .nota-body .label { color: #6b7280; width: 44%; }
        .nota-body .value { font-weight: 600; }

        .nota-nominal {
            text-align: center;
            margin: .5rem 0;
            padding: .4rem 0;
            border-top: 1px dashed #9ca3af;
            border-bottom: 1px dashed #9ca3af;
        }
        .nota-nominal .label-nom { font-size: .7rem; color: #6b7280; }
        .nota-nominal .amount {
            font-size: 1.3rem;
            font-weight: 700;
            letter-spacing: .03em;
        }
        .nota-nominal .terbilang {
            font-size: .68rem;
            color: #374151;
            font-style: italic;
            margin-top: .2rem;
        }

        .nota-footer { font-size: .68rem; text-align: center; color: #6b7280; margin-top: .5rem; }

        .ttd-row {
            display: flex;
            gap: 1rem;
            margin-top: .75rem;
        }
        .ttd-box {
            flex: 1;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: .5rem;
            text-align: center;
            font-size: .68rem;
            color: #374151;
        }
        .ttd-box .ttd-label { font-weight: 600; margin-bottom: 2.5rem; }
        .ttd-box .ttd-name  { border-top: 1px solid #9ca3af; padding-top: .25rem; color: #6b7280; }

        /* badge jenis transaksi */
        .badge-trx {
            display: inline-block;
            padding: .15rem .5rem;
            border-radius: 3px;
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
        }
        .badge-setor   { background: #d1fae5; color: #065f46; }
        .badge-tarik   { background: #fee2e2; color: #991b1b; }
        .badge-angsuran{ background: #dbeafe; color: #1e40af; }

        /* ===== PRINT STYLES ===== */
        @media print {
            body {
                background: #fff;
                padding: 0;
            }
            .action-bar { display: none !important; }
            .nota-wrapper {
                box-shadow: none;
                border: none;
                max-width: 80mm;
                width: 80mm;
                padding: .5rem .3rem;
            }
            /* sembunyikan semua elemen di luar .nota-wrapper */
            body > *:not(.nota-wrapper) { display: none !important; }
        }
    </style>
</head>
<body>


<div class="action-bar">
    <button class="btn btn-print" onclick="window.print()">🖨️ Cetak Nota</button>
    <a href="<?php echo e(route('teller.setor')); ?>" class="btn btn-new">+ Transaksi Baru</a>
</div>


<div class="nota-wrapper" id="nota">

    
    <?php
        $kop = config('pdf.koperasi');
    ?>
    <div class="nota-header">
        <div class="kop-nama"><?php echo e($kop['nama']); ?></div>
        <div class="kop-alamat"><?php echo e($kop['alamat']); ?></div>
        <div class="kop-alamat">
            <?php echo e(trim(implode(' ', array_filter([$kop['kota'] ?? null, $kop['kode_pos'] ?? null])))); ?>

            <?php if(! empty($kop['telepon'])): ?>
                | Telp: <?php echo e($kop['telepon']); ?>

            <?php endif; ?>
            <?php if(! empty($kop['website'])): ?>
                | <?php echo e($kop['website']); ?>

            <?php endif; ?>
        </div>
    </div>

    <hr class="divider-solid">

    
    <?php
        $jenisLabel = match($transaksi->jenis_transaksi ?? '') {
            'setor_tunai', 'Setoran Tunai'           => 'SETOR TUNAI',
            'tarik_tunai', 'Penarikan Tunai'          => 'TARIK TUNAI',
            'angsuran', 'Pembayaran Angsuran'         => 'BAYAR ANGSURAN',
            default                                   => strtoupper(str_replace('_', ' ', $transaksi->jenis_transaksi ?? 'TRANSAKSI'))
        };
        $badgeClass = match($transaksi->jenis_transaksi ?? '') {
            'setor_tunai', 'Setoran Tunai'           => 'badge-setor',
            'tarik_tunai', 'Penarikan Tunai'          => 'badge-tarik',
            'angsuran', 'Pembayaran Angsuran'         => 'badge-angsuran',
            default                                   => 'badge-setor'
        };
    ?>
    <div style="text-align:center;margin:.3rem 0;">
        <span class="badge-trx <?php echo e($badgeClass); ?>"><?php echo e($jenisLabel); ?></span>
    </div>

    <hr class="divider">

    
    <div class="nota-meta">
        <table>
            <tr>
                <td>No. Transaksi</td>
                <td><?php echo e($transaksi->no_transaksi ?? 'TRX-' . str_pad($transaksi->id, 6, '0', STR_PAD_LEFT)); ?></td>
            </tr>
            <tr>
                <td>Tanggal</td>
                <td><?php echo e(\Carbon\Carbon::parse($transaksi->created_at)->format('d/m/Y H:i:s')); ?></td>
            </tr>
            <tr>
                <td>Kasir/Teller</td>
                <td><?php echo e($transaksi->dibuatOleh?->name ?? Auth::user()->name); ?></td>
            </tr>
        </table>
    </div>

    <hr class="divider">

    
    <div class="nota-body">
        <table>
            <tr>
                <td class="label">Nama Anggota</td>
                <td class="value"><?php echo e($anggota?->nama_lengkap ?? '-'); ?></td>
            </tr>
            <tr>
                <td class="label">No. Rekening</td>
                <td class="value"><?php echo e($rekening?->no_rekening ?? '-'); ?></td>
            </tr>
            <tr>
                <td class="label">Jenis Rekening</td>
                <td class="value"><?php echo e($rekening?->jenis_rekening ?? '-'); ?></td>
            </tr>
        </table>
    </div>

    <hr class="divider">

    
    <?php
        $nominal = max($transaksi->debet ?? 0, $transaksi->kredit ?? 0, $transaksi->nominal ?? 0, $transaksi->jumlah ?? 0);
        // Ambang AML dibaca dari kebijakan koperasi (Setting::amlThreshold()),
        // sumber yang sama dengan TransactionLimitService — tanpa angka tetap.
        $amlThreshold = \App\Support\Setting::amlThreshold();
        $isHighValue  = $nominal >= $amlThreshold || ($transaksi->is_aml_high_value ?? false);
    ?>
    <div class="nota-nominal">
        <div class="label-nom">NOMINAL TRANSAKSI</div>
        <div class="amount">Rp <?php echo e(number_format($nominal, 0, ',', '.')); ?></div>
        <div class="terbilang"><?php echo e(terbilang($nominal)); ?> Rupiah</div>
    </div>

    <?php if($isHighValue): ?>
    <div style="margin: 0.5rem 0; padding: 0.5rem; background: #fef2f2; border: 1px dashed #ef4444; border-radius: 4px; text-align: center; font-size: 0.68rem; color: #991b1b; line-height: 1.3;">
        <strong>[ CATATAN KEPATUHAN / COMPLIANCE ]</strong><br>
        Transaksi Terkategori AML High-Value (Nominal &ge; Rp <?php echo e(number_format($amlThreshold, 0, ',', '.')); ?>). Wajib Melampirkan Dokumen Pendukung Sumber Dana.
    </div>
    <?php endif; ?>

    
    <?php if(in_array($transaksi->jenis_transaksi ?? '', ['setor_tunai', 'tarik_tunai', 'Setoran Tunai', 'Penarikan Tunai', 'Pembayaran Angsuran'])): ?>
    <div class="nota-body" style="margin-top:.4rem;">
        <table>
            <tr>
                <td class="label">Saldo Akhir</td>
                <td class="value" style="color:#059669;">Rp <?php echo e(number_format($saldoAkhir, 0, ',', '.')); ?></td>
            </tr>
        </table>
    </div>
    <?php endif; ?>

    
    <?php if(($transaksi->jenis_transaksi ?? '') === 'Pembayaran Angsuran'): ?>
    <?php $angsuranInfo = $transaksi->angsuran; ?>
    <div class="nota-body" style="margin-top:.4rem;">
        <table>
            <tr>
                <td class="label">No. Pembiayaan</td>
                <td class="value"><?php echo e($transaksi->pembiayaan?->no_pembiayaan ?? '-'); ?></td>
            </tr>
            <?php if($angsuranInfo): ?>
            <tr>
                <td class="label">Angsuran Ke-</td>
                <td class="value"><?php echo e($angsuranInfo->angsuran_ke); ?></td>
            </tr>
            <tr>
                <td class="label">Sisa Pembiayaan</td>
                <td class="value" style="color:#dc2626;">Rp <?php echo e(number_format($transaksi->pembiayaan?->sisa_pembiayaan ?? 0, 0, ',', '.')); ?></td>
            </tr>
            <?php endif; ?>
        </table>
    </div>
    <?php endif; ?>

    <hr class="divider">

    
    <div class="nota-footer">
        <p>Jazakumullah Khairan atas kepercayaan Anda.</p>
        <p>Simpan struk ini sebagai bukti transaksi yang sah.</p>
        <p style="margin-top:.3rem;font-style:italic;">"Barakallahu fi amalikum"</p>
    </div>

    
    <div class="ttd-row">
        <div class="ttd-box">
            <div class="ttd-label">Tanda Tangan Anggota</div>
            <div class="ttd-name">( ........................ )</div>
        </div>
        <div class="ttd-box">
            <div class="ttd-label">Tanda Tangan Teller</div>
            <div class="ttd-name">( ........................ )</div>
        </div>
    </div>

</div>

</body>
</html>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\teller\nota.blade.php ENDPATH**/ ?>