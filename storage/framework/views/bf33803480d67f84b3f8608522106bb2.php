
<?php
    $kelasHak = [
        'lihat'      => '',
        'unduh'      => 'mh-unduh',
        'edit'       => 'mh-edit',
        'hapus'      => 'mh-hapus',
        'ekspor'     => 'mh-ekspor',
        'verifikasi' => 'mh-verifikasi',
    ];
    $hakDiizinkan = array_values(array_intersect(
        array_keys($labelAktivitas),
        $hakModul ?? []
    ));
?>
<?php $__empty_1 = true; $__currentLoopData = $hakDiizinkan; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $kodeAksi): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
    <span class="matriks-hak <?php echo e($kelasHak[$kodeAksi] ?? ''); ?>"><?php echo e($labelAktivitas[$kodeAksi]); ?></span>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
    <span class="matriks-hak mh-tidak">Tidak ada akses</span>
<?php endif; ?>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/laporan/partials/badge-matriks.blade.php ENDPATH**/ ?>