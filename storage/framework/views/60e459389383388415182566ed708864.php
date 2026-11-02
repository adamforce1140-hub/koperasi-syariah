
<?php
    $rp = fn ($a) => 'Rp ' . number_format((float) $a, 0, ',', '.');
    $seimbang = $posisi['seimbang'] ?? false;
?>
<div class="card" style="margin-bottom:0;">
    <div class="card-header">
        <span class="card-title">📘 Laporan Keuangan Syariah (PSAK 101)</span>
        <span style="font-size:0.74rem;color:#94a3b8;">
            Posisi per <?php echo e($periode['sampaiStr'] ?? now()->translatedFormat('d M Y')); ?>

        </span>
    </div>

    <div class="fin-grid">
        <div class="fin-cell">
            <div class="fin-cap">Total Aset</div>
            <div class="fin-val"><?php echo e($rp($posisi['totalAset'] ?? 0)); ?></div>
            <div class="fin-note">Neraca · <?php echo e($seimbang ? 'seimbang' : 'belum seimbang'); ?></div>
        </div>
        <div class="fin-cell">
            <div class="fin-cap">Kewajiban</div>
            <div class="fin-val"><?php echo e($rp($posisi['totalKewajiban'] ?? 0)); ?></div>
            <div class="fin-note">Di luar Dana Syirkah Temporer</div>
        </div>
        <div class="fin-cell">
            <div class="fin-cap">Dana Syirkah Temporer</div>
            <div class="fin-val"><?php echo e($rp($posisi['totalDanaSyirkahTemporer'] ?? 0)); ?></div>
            <div class="fin-note">Dana investasi pemilik dana</div>
        </div>
        <div class="fin-cell">
            <div class="fin-cap">Ekuitas</div>
            <div class="fin-val"><?php echo e($rp($posisi['totalEkuitas'] ?? 0)); ?></div>
            <div class="fin-note">Modal anggota &amp; cadangan</div>
        </div>
        <div class="fin-cell">
            <div class="fin-cap">Total Pendapatan (YTD)</div>
            <div class="fin-val"><?php echo e($rp($labaRugi['totalPendapatan'] ?? 0)); ?></div>
            <div class="fin-note">Akun usaha + lain-lain</div>
        </div>
        <div class="fin-cell">
            <div class="fin-cap">Total Beban (YTD)</div>
            <div class="fin-val"><?php echo e($rp($labaRugi['totalBeban'] ?? 0)); ?></div>
            <div class="fin-note">Akun usaha + lain-lain</div>
        </div>
        <div class="fin-cell" style="background:<?php echo e(($labaRugi['labaBersih'] ?? 0) < 0 ? '#fef2f2' : '#f0fdf4'); ?>;">
            <div class="fin-cap">Laba Bersih (YTD)</div>
            <div class="fin-val" style="color:<?php echo e(($labaRugi['labaBersih'] ?? 0) < 0 ? '#dc2626' : '#15803d'); ?>;">
                <?php echo e($rp($labaRugi['labaBersih'] ?? 0)); ?>

            </div>
            <div class="fin-note">Margin <?php echo e(number_format($labaRugi['marginLabaBersih'] ?? 0, 2, ',', '.')); ?>%</div>
        </div>
        <div class="fin-cell">
            <div class="fin-cap">Uji Kepatuhan</div>
            <div class="fin-val" style="font-size:0.95rem;">
                <?php if($kepatuhan['patuh'] ?? false): ?>
                    <span class="badge-patuh">Patuh</span>
                <?php else: ?>
                    <span class="badge-temuan"><?php echo e(count($kepatuhan['temuan'] ?? [])); ?> temuan</span>
                <?php endif; ?>
            </div>
            <div class="fin-note">Kesesuaian penyajian PSAK 101</div>
        </div>
    </div>

    <?php if(! empty($kepatuhan['temuan'])): ?>
        <details style="margin-top:1rem;">
            <summary style="cursor:pointer;font-size:0.8rem;font-weight:600;color:#b91c1c;">
                Lihat <?php echo e(count($kepatuhan['temuan'])); ?> temuan kepatuhan
            </summary>
            <ul style="margin:0.6rem 0 0 1.2rem;font-size:0.78rem;color:#7f1d1d;line-height:1.6;">
                <?php $__currentLoopData = $kepatuhan['temuan']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $t): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <li><?php echo e($t); ?></li>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </ul>
        </details>
    <?php endif; ?>

    <div style="margin-top:1rem;display:flex;flex-wrap:wrap;gap:0.6rem;">
        <a href="<?php echo e(route('laporan.psak101')); ?>" class="btn btn-primary" style="font-size:0.78rem;padding:0.45rem 0.9rem;">
            Laporan PSAK 101 Lengkap →
        </a>
        <a href="<?php echo e(route('laporan.neraca')); ?>" class="btn" style="font-size:0.78rem;padding:0.45rem 0.9rem;background:#f1f5f9;color:#334155;">
            Neraca
        </a>
    </div>
</div>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/dashboard/partials/super-admin-keuangan.blade.php ENDPATH**/ ?>