
<div class="sapaan sapaan--<?php echo e($tema ?? 'hijau'); ?>">
    <div class="sapaan__identitas">
        <div class="sapaan__avatar"><?php echo e(strtoupper(mb_substr($nama ?? auth()->user()->name, 0, 1))); ?></div>
        <div class="sapaan__teks">
            <div class="sapaan__salam"><?php echo e($salam ?? 'Assalamualaikum,'); ?></div>
            <div class="sapaan__nama"><?php echo e($nama ?? auth()->user()->name); ?></div>
            <div class="sapaan__meta"><?php echo e($peran); ?> · <?php echo e(now()->translatedFormat('l, d F Y')); ?></div>
        </div>
    </div>
    <?php if(! empty($badge)): ?>
        <div class="sapaan__badge">
            <div class="sapaan__badge-judul"><?php echo e($badge); ?></div>
            <?php if(! empty($badgeKetika)): ?>
                <div class="sapaan__badge-keterangan"><?php echo e($badgeKetika); ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/dashboard/partials/sapaan.blade.php ENDPATH**/ ?>