<?php $__env->startSection('title', 'Rincian Bagi Hasil Deposito'); ?>
<?php $__env->startSection('page-title', 'Rincian Bagi Hasil Deposito'); ?>
<?php $__env->startSection('content'); ?>

<?php
    $labelStatus = [
        \App\Models\Deposito::STATUS_PENDING => ['Menunggu', 'badge-amber'],
        \App\Models\Deposito::STATUS_ACTIVE  => ['Aktif', 'badge-green'],
        \App\Models\Deposito::STATUS_MATURED => ['Jatuh Tempo', 'badge-blue'],
        \App\Models\Deposito::STATUS_CLOSED  => ['Ditutup', 'badge-gray'],
    ];
    [$statusLabel, $statusBadge] = $labelStatus[$deposito->status] ?? [ucfirst((string) $deposito->status), 'badge-gray'];

    $labelSharing = [
        \App\Models\DepositoProfitSharing::STATUS_PAID      => ['Dibayarkan', 'badge-green'],
        \App\Models\DepositoProfitSharing::STATUS_FAILED    => ['Tertunda', 'badge-amber'],
        \App\Models\DepositoProfitSharing::STATUS_FORFEITED => ['Hangus', 'badge-red'],
    ];
?>

<div style="margin-bottom: 1.25rem;">
    <a href="<?php echo e(route('nasabah.deposito.index')); ?>" class="btn btn-sm" style="background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;">
        &larr; Kembali ke Deposito Saya
    </a>
</div>


<div class="card">
    <div class="card-header">
        <span class="card-title">Bilyet <?php echo e($deposito->deposito_number); ?></span>
        <span class="badge <?php echo e($statusBadge); ?>"><?php echo e($statusLabel); ?></span>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem;">
        <div>
            <div style="font-size: 0.7rem; color: #94a3b8; text-transform: uppercase;">Pokok</div>
            <div style="font-size: 1.15rem; font-weight: 700; color: #1e293b;"><?php echo e($deposito->principal_formatted); ?></div>
        </div>
        <div>
            <div style="font-size: 0.7rem; color: #94a3b8; text-transform: uppercase;">Tenor</div>
            <div style="font-size: 1.15rem; font-weight: 700; color: #1e293b;"><?php echo e($deposito->tenorMonths()); ?> bulan</div>
        </div>
        <div>
            <div style="font-size: 0.7rem; color: #94a3b8; text-transform: uppercase;">Mulai</div>
            <div style="font-size: 0.95rem; color: #334155;"><?php echo e($deposito->start_date?->format('d/m/Y') ?? '-'); ?></div>
        </div>
        <div>
            <div style="font-size: 0.7rem; color: #94a3b8; text-transform: uppercase;">Jatuh Tempo</div>
            <div style="font-size: 0.95rem; color: #334155;"><?php echo e($deposito->maturity_date?->format('d/m/Y') ?? '-'); ?></div>
        </div>
        <div>
            <div style="font-size: 0.7rem; color: #94a3b8; text-transform: uppercase;">Mode Perpanjangan</div>
            <div style="font-size: 0.95rem; color: #334155;"><?php echo e($deposito->aro_label); ?></div>
        </div>
    </div>
</div>


<div class="card" style="background:#f0fdf4;border:1px solid #bbf7d0;">
    <div class="card-header" style="margin-bottom:0.75rem;">
        <span class="card-title" style="color:#065f46;">Akad Mudharabah Muthlaqah — Nisbah Bagi Hasil</span>
    </div>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 0.75rem;">
        <div>
            <div style="font-size: 0.7rem; color: #166534; text-transform: uppercase;">Nisbah Anggota (Shahibul Maal)</div>
            <div style="font-size: 1.5rem; font-weight: 700; color: #065f46;"><?php echo e(rtrim(rtrim(number_format((float) $deposito->nisbah_anggota, 2, ',', '.'), '0'), ',')); ?>%</div>
        </div>
        <div>
            <div style="font-size: 0.7rem; color: #166534; text-transform: uppercase;">Nisbah Koperasi (Mudharib)</div>
            <div style="font-size: 1.5rem; font-weight: 700; color: #065f46;"><?php echo e(rtrim(rtrim(number_format((float) $deposito->nisbah_koperasi, 2, ',', '.'), '0'), ',')); ?>%</div>
        </div>
        <div>
            <div style="font-size: 0.7rem; color: #166534; text-transform: uppercase;">Produk</div>
            <div style="font-size: 1rem; font-weight: 600; color: #065f46;"><?php echo e($deposito->product?->name ?? '-'); ?></div>
        </div>
    </div>
    <p style="font-size: 0.82rem; color: #166534; margin: 0; line-height: 1.6;">
        Bagi hasil dihitung dari <strong>pendapatan riil koperasi</strong> atas penempatan dana pada periode berjalan,
        dikalikan nisbah anggota di atas. Besarnya bagi hasil tidak dijamin di muka dan mengikuti hasil usaha
        (bukan bunga tetap), sesuai prinsip Mudharabah.
    </p>
</div>


<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
    <div style="background:white;border:1px solid #e2e8f0;border-radius:1rem;padding:1.25rem;">
        <div style="font-size: 0.75rem; color:#64748b;">Total Pendapatan Koperasi (dasar hitung)</div>
        <div style="font-size: 1.35rem; font-weight:700; color:#1e293b;">Rp <?php echo e(number_format($totalPendapatan, 0, ',', '.')); ?></div>
    </div>
    <div style="background:white;border:1px solid #e2e8f0;border-radius:1rem;padding:1.25rem;">
        <div style="font-size: 0.75rem; color:#64748b;">Bagi Hasil Diterima Anggota</div>
        <div style="font-size: 1.35rem; font-weight:700; color:#047857;">Rp <?php echo e(number_format($totalDibayar, 0, ',', '.')); ?></div>
    </div>
    <div style="background:white;border:1px solid #e2e8f0;border-radius:1rem;padding:1.25rem;">
        <div style="font-size: 0.75rem; color:#64748b;">Bagi Hasil Hangus (Pencairan Dini)</div>
        <div style="font-size: 1.35rem; font-weight:700; color:#b91c1c;">Rp <?php echo e(number_format($totalHangus, 0, ',', '.')); ?></div>
    </div>
</div>


<div class="card">
    <div class="card-header">
        <span class="card-title">Rincian Bagi Hasil per Periode</span>
    </div>
    <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>Periode</th>
                    <th style="text-align:right;">Pendapatan Koperasi</th>
                    <th style="text-align:right;">Nisbah Anggota</th>
                    <th style="text-align:right;">Bagi Hasil Anggota</th>
                    <th>Tanggal Bayar</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php $__empty_1 = true; $__currentLoopData = $rincian; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <?php
                    [$sLabel, $sBadge] = $labelSharing[$r->status] ?? [ucfirst((string) $r->status), 'badge-gray'];
                ?>
                <tr>
                    <td><strong><?php echo e($r->nama_periode); ?></strong></td>
                    <td style="text-align:right;">Rp <?php echo e(number_format((float) $r->gross_koperasi_revenue, 0, ',', '.')); ?></td>
                    <td style="text-align:right;"><?php echo e(rtrim(rtrim(number_format((float) $deposito->nisbah_anggota, 2, ',', '.'), '0'), ',')); ?>%</td>
                    <td style="text-align:right;font-weight:600;color:#047857;"><?php echo e($r->member_profit_formatted); ?></td>
                    <td><?php echo e($r->payout_date?->format('d/m/Y') ?? '-'); ?></td>
                    <td><span class="badge <?php echo e($sBadge); ?>"><?php echo e($sLabel); ?></span></td>
                </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <tr>
                    <td colspan="6" style="text-align:center;color:#94a3b8;padding:2.5rem;">
                        Belum ada pembagian bagi hasil untuk bilyet ini.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\nasabah\deposito-bagi-hasil.blade.php ENDPATH**/ ?>