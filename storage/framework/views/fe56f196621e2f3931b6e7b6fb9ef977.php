<?php $__env->startSection('title', 'Laporan Keuangan — Manajer'); ?>
<?php $__env->startSection('page-title', 'Laporan Keuangan'); ?>

<?php $__env->startSection('content'); ?>
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
                Laporan Keuangan
            </h1>
            <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
                Ringkasan neraca, laba/rugi, dan arus bagi hasil koperasi.
            </p>
        </div>

        
        <form method="GET" action="<?php echo e(route('manajer.laporan.index')); ?>"
              style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
            <select name="bulan"
                    style="padding:0.45rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;
                           font-size:0.825rem;color:#1e293b;background:#fff;outline:none;">
                <?php $__currentLoopData = range(1,12); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $m): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <option value="<?php echo e($m); ?>" <?php echo e($bulan == $m ? 'selected' : ''); ?>>
                    <?php echo e(\Carbon\Carbon::create()->month($m)->translatedFormat('F')); ?>

                </option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </select>
            <select name="tahun"
                    style="padding:0.45rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;
                           font-size:0.825rem;color:#1e293b;background:#fff;outline:none;">
                <?php $__currentLoopData = range(now()->year - 2, now()->year); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $y): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <option value="<?php echo e($y); ?>" <?php echo e($tahun == $y ? 'selected' : ''); ?>><?php echo e($y); ?></option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </select>
            <button type="submit"
                    style="padding:0.45rem 1rem;background:#047857;color:#fff;font-size:0.825rem;
                           font-weight:600;border:none;border-radius:0.5rem;cursor:pointer;"
                    onmouseover="this.style.background='#065f46'" onmouseout="this.style.background='#047857'">
                Tampilkan
            </button>
        </form>
    </div>

    
    <div style="font-size:0.875rem;color:#64748b;font-weight:500;">
        Periode:
        <strong style="color:#1e293b;">
            <?php echo e(\Carbon\Carbon::create()->month($bulan)->translatedFormat('F')); ?> <?php echo e($tahun); ?>

        </strong>
    </div>

    
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;">
        <?php
        $cards = [
            ['label' => 'Total Transaksi',        'value' => number_format($totalTransaksi),                              'sub' => 'transaksi sukses',  'color' => '#2563eb', 'bg' => '#dbeafe', 'icon' => '🔄'],
            ['label' => 'Total Nominal Transaksi','value' => 'Rp ' . number_format($totalNominal, 0, ',', '.'),          'sub' => 'periode ini',       'color' => '#059669', 'bg' => '#d1fae5', 'icon' => '💰'],
            ['label' => 'Pembiayaan Cair',        'value' => 'Rp ' . number_format($totalPembiayaanCair, 0, ',', '.'),   'sub' => 'akad baru',         'color' => '#7c3aed', 'bg' => '#ede9fe', 'icon' => '📤'],
            ['label' => 'Angsuran Masuk',         'value' => 'Rp ' . number_format($totalAngsuranMasuk, 0, ',', '.'),    'sub' => 'angsuran lunas',    'color' => '#0891b2', 'bg' => '#cffafe', 'icon' => '📥'],
        ];
        ?>
        <?php $__currentLoopData = $cards; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $c): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem 1.375rem;
                    box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.75rem;">
                <div style="width:38px;height:38px;border-radius:0.5rem;background:<?php echo e($c['bg']); ?>;
                            display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;">
                    <?php echo e($c['icon']); ?>

                </div>
                <div style="font-size:0.775rem;color:#64748b;font-weight:500;"><?php echo e($c['label']); ?></div>
            </div>
            <div style="font-size:1.3rem;font-weight:700;color:<?php echo e($c['color']); ?>;line-height:1.2;">
                <?php echo e($c['value']); ?>

            </div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:0.25rem;"><?php echo e($c['sub']); ?></div>
        </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;overflow:hidden;
                box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <div style="padding:1.25rem 1.5rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;">
            <div style="font-size:0.9rem;font-weight:700;color:#1e293b;">Rincian per Jenis Transaksi</div>
            <div style="font-size:0.78rem;color:#64748b;margin-top:0.2rem;">
                Seluruh jenis transaksi pada periode ini, termasuk yang nominalnya kecil — agar tidak ada pencatatan yang terlewat.
            </div>
        </div>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.835rem;">
                <thead>
                    <tr style="background:#f1f5f9;text-align:left;">
                        <th style="padding:0.7rem 1rem;font-weight:600;color:#475569;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;">Jenis Transaksi</th>
                        <th style="padding:0.7rem 1rem;font-weight:600;color:#475569;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;">Posisi</th>
                        <th style="padding:0.7rem 1rem;font-weight:600;color:#475569;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;text-align:right;">Jumlah</th>
                        <th style="padding:0.7rem 1rem;font-weight:600;color:#475569;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;text-align:right;">Nominal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__empty_1 = true; $__currentLoopData = $rincianTransaksi; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <?php $debet = strtolower($r->posisi ?? '') === 'debet'; ?>
                    <tr style="border-top:1px solid #f1f5f9;">
                        <td style="padding:0.7rem 1rem;color:#1e293b;font-weight:500;"><?php echo e($r->jenis_transaksi); ?></td>
                        <td style="padding:0.7rem 1rem;">
                            <span style="display:inline-block;padding:0.15rem 0.55rem;border-radius:9999px;font-size:0.7rem;font-weight:600;
                                         background:<?php echo e($debet ? '#dbeafe' : '#fef3c7'); ?>;color:<?php echo e($debet ? '#1e40af' : '#92400e'); ?>;">
                                <?php echo e($r->posisi); ?>

                            </span>
                        </td>
                        <td style="padding:0.7rem 1rem;text-align:right;color:#475569;"><?php echo e(number_format($r->jumlah_transaksi)); ?> trx</td>
                        <td style="padding:0.7rem 1rem;text-align:right;font-weight:600;color:<?php echo e($debet ? '#2563eb' : '#b45309'); ?>;">
                            
                            <?php echo e($debet ? '+' : '−'); ?> Rp <?php echo e(number_format($r->total_nominal, 0, ',', '.')); ?>

                        </td>
                    </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <tr>
                        <td colspan="4" style="text-align:center;padding:1.5rem;color:#94a3b8;">Tidak ada transaksi sukses pada periode ini.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
                <?php if($rincianTransaksi->isNotEmpty()): ?>
                <tfoot>
                    <tr style="background:#f8fafc;border-top:2px solid #e2e8f0;">
                        <td colspan="2" style="padding:0.7rem 1rem;font-weight:700;color:#475569;">Total</td>
                        <td style="padding:0.7rem 1rem;text-align:right;font-weight:700;color:#475569;"><?php echo e(number_format($totalTransaksi)); ?> trx</td>
                        <td style="padding:0.7rem 1rem;text-align:right;font-weight:700;color:#1e293b;">Rp <?php echo e(number_format($totalNominal, 0, ',', '.')); ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
        <?php if($jumlahTransaksiBermasalah > 0): ?>
        <div style="padding:0.85rem 1.5rem;background:#fffbeb;border-top:1px solid #fde68a;font-size:0.8rem;color:#92400e;">
            <strong><?php echo e($jumlahTransaksiBermasalah); ?> transaksi</strong> berstatus tidak sukses
            (Voided/Gagal/Dibatalkan) senilai Rp <?php echo e(number_format($nominalTransaksiBermasalah, 0, ',', '.')); ?>

            <strong>tidak dihitung</strong> dalam nominal laporan periode ini.
        </div>
        <?php endif; ?>
    </div>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;overflow:hidden;
                box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <div style="padding:1.25rem 1.5rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;">
            <div style="font-size:0.9rem;font-weight:700;color:#1e293b;">Rekap Bulanan <?php echo e($tahun); ?></div>
            <div style="font-size:0.78rem;color:#64748b;margin-top:0.2rem;">
                Pencatatan seluruh bulan dalam satu tahun — bulan tanpa transaksi tetap ditampilkan sebagai 0.
            </div>
        </div>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.835rem;">
                <thead>
                    <tr style="background:#f1f5f9;text-align:left;">
                        <th style="padding:0.7rem 1rem;font-weight:600;color:#475569;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;">Bulan</th>
                        <th style="padding:0.7rem 1rem;font-weight:600;color:#475569;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;text-align:right;">Transaksi</th>
                        <th style="padding:0.7rem 1rem;font-weight:600;color:#475569;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;text-align:right;">Nominal</th>
                        <th style="padding:0.7rem 1rem;font-weight:600;color:#475569;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;text-align:right;">Angsuran Masuk</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__currentLoopData = $rekapBulanan; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $rb): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php
                        $aktif   = $rb->bulan == $bulan;
                        $kosong  = $rb->jumlah === 0 && $rb->angsuran == 0;
                    ?>
                    <tr style="border-top:1px solid #f1f5f9;
                               <?php echo e($aktif ? 'background:#eff6ff;' : ''); ?>

                               <?php echo e($kosong ? 'opacity:0.5;' : ''); ?>">
                        <td style="padding:0.7rem 1rem;font-weight:<?php echo e($aktif ? '700' : '500'); ?>;color:<?php echo e($aktif ? '#1d4ed8' : '#1e293b'); ?>;">
                            <?php echo e($rb->label); ?>

                            <?php if($aktif): ?>
                                <span style="display:inline-block;margin-left:0.375rem;padding:0.1rem 0.5rem;border-radius:9999px;
                                             font-size:0.68rem;font-weight:600;background:#dbeafe;color:#1d4ed8;">Periode ini</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:0.7rem 1rem;text-align:right;color:#475569;"><?php echo e(number_format($rb->jumlah)); ?></td>
                        <td style="padding:0.7rem 1rem;text-align:right;color:#059669;font-weight:600;">Rp <?php echo e(number_format($rb->nominal, 0, ',', '.')); ?></td>
                        <td style="padding:0.7rem 1rem;text-align:right;color:#0891b2;font-weight:600;">Rp <?php echo e(number_format($rb->angsuran, 0, ',', '.')); ?></td>
                    </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f8fafc;border-top:2px solid #e2e8f0;">
                        <td style="padding:0.7rem 1rem;font-weight:700;color:#475569;">Total <?php echo e($tahun); ?></td>
                        <td style="padding:0.7rem 1rem;text-align:right;font-weight:700;color:#475569;"><?php echo e(number_format($rekapBulanan->sum('jumlah'))); ?></td>
                        <td style="padding:0.7rem 1rem;text-align:right;font-weight:700;color:#059669;">Rp <?php echo e(number_format($rekapBulanan->sum('nominal'), 0, ',', '.')); ?></td>
                        <td style="padding:0.7rem 1rem;text-align:right;font-weight:700;color:#0891b2;">Rp <?php echo e(number_format($rekapBulanan->sum('angsuran'), 0, ',', '.')); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem 1.5rem;">
        <div style="font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.75rem;
                    text-transform:uppercase;letter-spacing:0.05em;">
            Catatan Laporan
        </div>
        <ul style="margin:0;padding-left:1.25rem;color:#64748b;font-size:0.825rem;line-height:1.8;">
            <li>Angka dikelompokkan berdasarkan <strong>tanggal transaksi</strong>, bukan tanggal input data.</li>
            <li>Hanya transaksi berstatus <strong>Sukses</strong> yang dihitung; Voided/Gagal/Dibatalkan dikecualikan namun tetap ditampilkan jumlahnya.</li>
            <li>Angsuran masuk dihitung dari angsuran berstatus <strong>Sudah Dibayar</strong> sesuai tanggal bayar.</li>
            <li>Laporan neraca lengkap dapat diakses melalui modul akuntansi.</li>
            <li>Untuk ekspor laporan, hubungi administrator sistem.</li>
        </ul>
    </div>

</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/manajer/laporan.blade.php ENDPATH**/ ?>