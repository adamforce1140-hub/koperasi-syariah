
<?php $__env->startSection('page-title', "Detail Distribusi SHU"); ?>

<?php $__env->startSection('title', 'Detail Distribusi SHU ' . $shu->tahun_buku); ?>

<?php $__env->startSection('content'); ?>
<div class="container-fluid px-4 py-3">

    
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h4 class="fw-bold mb-1">Detail Distribusi SHU — Tahun Buku <?php echo e($shu->tahun_buku); ?></h4>
            <p class="text-muted mb-0 small">
                Status:
                <?php if($shu->status === 'Selesai'): ?>
                    <span class="badge bg-success">Selesai</span>
                <?php elseif($shu->status === 'Diproses'): ?>
                    <span class="badge bg-warning text-dark">Diproses</span>
                <?php else: ?>
                    <span class="badge bg-secondary">Draft</span>
                <?php endif; ?>
                <?php if($shu->is_distributed): ?>
                    <span class="badge bg-primary ms-1"><i class="bi bi-check2-all me-1"></i>Didistribusikan</span>
                <?php endif; ?>
                <?php if($shu->diproses_at): ?>
                    &mdash; Diproses: <?php echo e($shu->diproses_at->format('d/m/Y H:i')); ?>

                <?php endif; ?>
            </p>
        </div>
        <div class="d-flex gap-2">
            <?php if($shu->isDraft()): ?>
            <form action="<?php echo e(route('pengurus.shu.proses', $shu->id)); ?>" method="POST"
                  onsubmit="return confirm('Proses & bagikan SHU <?php echo e($shu->tahun_buku); ?>? Tindakan ini tidak dapat dibatalkan.')">
                <?php echo csrf_field(); ?>
                <button class="btn btn-success">
                    <i class="bi bi-play-fill me-1"></i> Proses & Bagikan SHU
                </button>
            </form>
            <?php endif; ?>
            <?php if($shu->isSelesai()): ?>
            <a href="<?php echo e(route('pengurus.shu.export-excel', $shu->id)); ?>" class="btn btn-outline-success">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export Excel / RAT
            </a>
            <?php endif; ?>
            <a href="<?php echo e(route('pengurus.shu.index')); ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Kembali
            </a>
        </div>
    </div>

    
    <?php if(session('success')): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo e(session('success')); ?>

            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if(session('error')): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo e(session('error')); ?>

            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center h-100">
                <div class="card-body py-3">
                    <div class="small text-muted mb-1">Total SHU Bersih</div>
                    <div class="fw-bold text-primary">Rp <?php echo e(number_format($shu->total_shu_bersih, 0, ',', '.')); ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center h-100 border-top border-4 border-info">
                <div class="card-body py-3">
                    <div class="small text-muted mb-1">Dana Cadangan (<?php echo e($shu->persen_cadangan); ?>%)</div>
                    <div class="fw-bold text-info">Rp <?php echo e(number_format($shu->nominal_cadangan, 0, ',', '.')); ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center h-100 border-top border-4 border-success">
                <div class="card-body py-3">
                    <div class="small text-muted mb-1">Jasa Modal (<?php echo e($shu->persen_jasa_modal); ?>%)</div>
                    <div class="fw-bold text-success">Rp <?php echo e(number_format($shu->nominal_jasa_modal, 0, ',', '.')); ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center h-100 border-top border-4 border-warning">
                <div class="card-body py-3">
                    <div class="small text-muted mb-1">Jasa Transaksi (<?php echo e($shu->persen_jasa_transaksi); ?>%)</div>
                    <div class="fw-bold text-warning">Rp <?php echo e(number_format($shu->nominal_jasa_transaksi, 0, ',', '.')); ?></div>
                </div>
            </div>
        </div>
    </div>

    
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold border-bottom d-flex align-items-center justify-content-between">
            <span><i class="bi bi-people me-2 text-primary"></i>Rincian Per Anggota</span>
            <span class="badge bg-primary rounded-pill"><?php echo e($distributions->count()); ?> anggota</span>
        </div>
        <div class="card-body p-0">
            <?php if($distributions->isEmpty()): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                    <?php if($shu->isDraft()): ?>
                        Belum diproses. Klik "Proses & Bagikan SHU" untuk menghitung distribusi.
                    <?php else: ?>
                        Tidak ada data distribusi.
                    <?php endif; ?>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">No</th>
                            <th>No Anggota</th>
                            <th>Nama Anggota</th>
                            <th class="text-end">Simpanan Modal</th>
                            <th class="text-end">Angsuran / Bagi Hasil</th>
                            <th class="text-end text-success">SHU Jasa Modal</th>
                            <th class="text-end text-warning">SHU Jasa Transaksi</th>
                            <th class="text-end text-primary fw-bold">Total SHU</th>
                            <th>Rekening</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $__currentLoopData = $distributions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $i => $d): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr>
                            <td class="ps-3 text-muted"><?php echo e($i + 1); ?></td>
                            <td class="fw-semibold"><?php echo e($d->anggota->no_anggota ?? '-'); ?></td>
                            <td><?php echo e($d->anggota->nama_lengkap ?? '-'); ?></td>
                            <td class="text-end">Rp <?php echo e(number_format($d->total_simpanan_modal, 0, ',', '.')); ?></td>
                            <td class="text-end">Rp <?php echo e(number_format($d->total_transaksi_bagi_hasil, 0, ',', '.')); ?></td>
                            <td class="text-end text-success">Rp <?php echo e(number_format($d->shu_jasa_modal, 0, ',', '.')); ?></td>
                            <td class="text-end text-warning">Rp <?php echo e(number_format($d->shu_jasa_transaksi, 0, ',', '.')); ?></td>
                            <td class="text-end fw-bold text-primary">Rp <?php echo e(number_format($d->total_shu, 0, ',', '.')); ?></td>
                            <td>
                                <?php if($d->rekening): ?>
                                    <span class="badge bg-success-subtle text-success"><?php echo e($d->rekening->no_rekening); ?></span>
                                    <?php if(str_contains($d->rekening->keterangan ?? '', 'otomatis dibuat')): ?>
                                        <span class="badge bg-info-subtle text-info ms-1" title="Rekening dibuat otomatis saat distribusi SHU">
                                            <i class="bi bi-magic me-1"></i>Auto
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-warning">Belum ada rekening</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="3" class="ps-3">TOTAL</td>
                            <td class="text-end">Rp <?php echo e(number_format($distributions->sum('total_simpanan_modal'), 0, ',', '.')); ?></td>
                            <td class="text-end">Rp <?php echo e(number_format($distributions->sum('total_transaksi_bagi_hasil'), 0, ',', '.')); ?></td>
                            <td class="text-end text-success">Rp <?php echo e(number_format($distributions->sum('shu_jasa_modal'), 0, ',', '.')); ?></td>
                            <td class="text-end text-warning">Rp <?php echo e(number_format($distributions->sum('shu_jasa_transaksi'), 0, ',', '.')); ?></td>
                            <td class="text-end text-primary">Rp <?php echo e(number_format($distributions->sum('total_shu'), 0, ',', '.')); ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    
    <?php if($shu->isSelesai() && $holdRows->isNotEmpty()): ?>
    <div class="card border-0 shadow-sm mt-4 border-start border-4 border-danger">
        <div class="card-header bg-white fw-semibold border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span class="text-danger">
                <i class="bi bi-shield-exclamation me-2"></i>SHU Ditahan (HOLD) — Tunggakan Simpanan Wajib
            </span>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-danger rounded-pill"><?php echo e($holdRows->count()); ?> anggota</span>
                <span class="badge bg-danger-subtle text-danger">
                    Rp <?php echo e(number_format($holdRows->sum('total_shu'), 0, ',', '.')); ?> tertahan (2-1004)
                </span>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="alert alert-danger border-0 rounded-0 mb-0 small">
                <i class="bi bi-info-circle me-1"></i>
                Pencairan SHU anggota di bawah ini <strong>ditahan</strong> sesuai kebijakan
                <code>shu_hold_on_overdue</code>. SHU akan dilepas
                <strong>otomatis</strong> setelah seluruh tagihan OVERDUE dilunasi di Teller
                (jurnal: Debet 2-1004 Utang SHU Ditahan / Kredit 2-1001 Simpanan Sukarela).
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">No</th>
                            <th>No Anggota</th>
                            <th>Nama Anggota</th>
                            <th class="text-end text-primary">Total SHU</th>
                            <th>Alasan Penahanan</th>
                            <th class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $__currentLoopData = $holdRows; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $i => $h): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr>
                            <td class="ps-3 text-muted"><?php echo e($i + 1); ?></td>
                            <td class="fw-semibold"><?php echo e($h->anggota->no_anggota ?? '-'); ?></td>
                            <td>
                                <a href="<?php echo e(route('pengurus.cif.show', $h->anggota_id)); ?>"
                                   class="text-decoration-none fw-semibold">
                                    <?php echo e($h->anggota->nama_lengkap ?? '-'); ?>

                                </a>
                            </td>
                            <td class="text-end fw-bold text-primary">
                                Rp <?php echo e(number_format($h->total_shu, 0, ',', '.')); ?>

                            </td>
                            <td class="text-danger" style="max-width:340px;">
                                <?php echo e($h->hold_reason ?? 'Ditahan karena tunggakan Simpanan Wajib.'); ?>

                            </td>
                            <td class="text-center">
                                <form action="<?php echo e(route('pengurus.shu.lepas-hold-anggota', [$shu->id, $h->id])); ?>"
                                      method="POST" class="d-inline"
                                      onsubmit="return confirm('Lepas penahanan SHU <?php echo e($h->anggota->nama_lengkap ?? ''); ?>? Pastikan seluruh tunggakan sudah dilunasi di Teller.')">
                                    <?php echo csrf_field(); ?>
                                    <button class="btn btn-sm btn-outline-danger" type="submit">
                                        <i class="bi bi-unlock me-1"></i> Lepas
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    
    <?php if($shu->jurnal_id): ?>
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white fw-semibold border-bottom">
            <i class="bi bi-journal-text me-2 text-primary"></i>Jurnal Akuntansi
        </div>
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-4">
                    <div class="small text-muted">No. Jurnal</div>
                    <div class="fw-semibold"><?php echo e($shu->jurnal->no_jurnal ?? '-'); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted">Tanggal</div>
                    <div class="fw-semibold"><?php echo e($shu->jurnal?->tanggal_jurnal?->format('d/m/Y') ?? '-'); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted">Status</div>
                    <span class="badge bg-success"><?php echo e($shu->jurnal->status ?? '-'); ?></span>
                </div>
            </div>
            <?php if($shu->jurnal && $shu->jurnal->detail->count()): ?>
            <div class="table-responsive mt-3">
                <table class="table table-sm table-bordered align-middle small mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Baris</th>
                            <th>Kode Akun</th>
                            <th>Nama Akun</th>
                            <th class="text-end">Debet</th>
                            <th class="text-end">Kredit</th>
                            <th>Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $__currentLoopData = $shu->jurnal->detail; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $det): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr>
                            <td><?php echo e($det->baris); ?></td>
                            <td class="fw-semibold"><?php echo e($det->coa->kode_akun ?? '-'); ?></td>
                            <td><?php echo e($det->coa->nama_akun ?? '-'); ?></td>
                            <td class="text-end"><?php echo e($det->debet > 0 ? 'Rp ' . number_format($det->debet, 0, ',', '.') : '-'); ?></td>
                            <td class="text-end"><?php echo e($det->kredit > 0 ? 'Rp ' . number_format($det->kredit, 0, ',', '.') : '-'); ?></td>
                            <td><?php echo e($det->keterangan); ?></td>
                        </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/pengurus/shu/detail.blade.php ENDPATH**/ ?>