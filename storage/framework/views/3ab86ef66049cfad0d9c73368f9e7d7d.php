
<?php
    $pintasan = [
        ['label' => 'Kelola Pengguna &amp; Role', 'route' => 'pengurus.users.index',        'ikon' => '👤'],
        ['label' => 'Produk Pembiayaan &amp; Akad', 'route' => 'pengurus.produk.index',    'ikon' => '💳'],
        ['label' => 'Jenis Simpanan',             'route' => 'pengurus.jenis-simpanan.index', 'ikon' => '🏦'],
        ['label' => 'Kebijakan Operasional',      'route' => 'pengurus.kebijakan.index',   'ikon' => '📜'],
        ['label' => 'Pengaturan Aplikasi',        'route' => 'admin.settings.index',       'ikon' => '⚙️'],
    ];
?>
<div class="card" style="margin-bottom:0;">
    <div class="card-header">
        <span class="card-title">⚙️ Pengaturan Sistem</span>
        <span style="font-size:0.74rem;color:#94a3b8;">
            <?php echo e(number_format($sistem['totalPengguna'] ?? 0, 0, ',', '.')); ?> pengguna terdaftar
        </span>
    </div>

    
    <div class="sys-grid">
        <div class="sys-cell">
            <div class="sys-val"><?php echo e(number_format($sistem['produkAktif'] ?? 0, 0, ',', '.')); ?></div>
            <div class="sys-cap">Produk Pembiayaan Aktif</div>
        </div>
        <div class="sys-cell">
            <div class="sys-val"><?php echo e(number_format($sistem['jenisSimpanan'] ?? 0, 0, ',', '.')); ?></div>
            <div class="sys-cap">Jenis Simpanan</div>
        </div>
        <div class="sys-cell">
            <div class="sys-val"><?php echo e(number_format($sistem['limitTeller'] ?? 0, 0, ',', '.')); ?></div>
            <div class="sys-cap">Parameter Limit</div>
        </div>
        <div class="sys-cell">
            <div class="sys-val"><?php echo e(number_format($sistem['kebijakanAktif'] ?? 0, 0, ',', '.')); ?></div>
            <div class="sys-cap">Kebijakan Tercatat</div>
        </div>
    </div>

    
    <div style="margin-top:1rem;">
        <div class="fin-cap" style="margin-bottom:0.5rem;">Pengguna per Peran</div>
        <div class="peran-list">
            <?php $__currentLoopData = ($sistem['peran'] ?? []); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $slug => $meta): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <span class="peran-chip"><?php echo e($meta['label']); ?> · <?php echo e(number_format($meta['jumlah'], 0, ',', '.')); ?></span>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </div>
    </div>

    
    <div style="margin-top:1.1rem;display:flex;flex-wrap:wrap;gap:0.5rem;">
        <?php $__currentLoopData = $pintasan; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <?php if(\Illuminate\Support\Facades\Route::has($p['route'])): ?>
                <a href="<?php echo e(route($p['route'])); ?>" class="btn"
                   style="font-size:0.76rem;padding:0.42rem 0.8rem;background:#f5f3ff;color:#5b21b6;border:1px solid #ddd6fe;">
                    <?php echo e($p['ikon']); ?> <?php echo $p['label']; ?>

                </a>
            <?php endif; ?>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>
</div>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/dashboard/partials/super-admin-sistem.blade.php ENDPATH**/ ?>