<?php $__env->startSection('title', 'Dashboard Teller — ' . config('app.name')); ?>
<?php $__env->startSection('page-title', 'Dashboard Teller'); ?>

<?php $__env->startSection('content'); ?>

<?php echo $__env->make('dashboard.partials.sapaan', ['peran' => 'Teller'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>


<div id="teller-stats" class="stats-grid"
     data-denyut-domain="transaksi approval"
     data-denyut-url="<?php echo e(route('dashboard.teller.ringkas')); ?>"
     data-denyut-sasaran="stats">
          <?php echo $__env->make('dashboard.partials.teller-stats', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</div>


<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <span class="card-title">Menu Operasional</span>
        
        <span style="font-size:0.78rem;color:#64748b;">Batas mandiri: Rp <?php echo e(number_format(\App\Support\HierarkiRole::batas('teller', 'tarik_tunai')['limit_per_transaksi'] ?? 0, 0, ',', '.')); ?></span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;padding:1rem 0 0.5rem;">
        <a href="<?php echo e(route('teller.setor')); ?>" style="display:flex;flex-direction:column;align-items:center;gap:0.75rem;
            padding:1.5rem 1rem;border-radius:0.75rem;border:2px solid #e2e8f0;text-decoration:none;
            color:#1e293b;transition:all 0.15s;text-align:center;" onmouseover="this.style.borderColor='#059669';this.style.background='#f0fdf4'" onmouseout="this.style.borderColor='#e2e8f0';this.style.background='white'">
            <span style="font-size:2rem;">📥</span>
            <div>
                <div style="font-weight:600;font-size:0.9rem;">Setoran Tunai</div>
                <div style="font-size:0.75rem;color:#64748b;margin-top:0.2rem;">Input setor anggota</div>
            </div>
        </a>
        <a href="<?php echo e(route('teller.tarik')); ?>" style="display:flex;flex-direction:column;align-items:center;gap:0.75rem;
            padding:1.5rem 1rem;border-radius:0.75rem;border:2px solid #e2e8f0;text-decoration:none;
            color:#1e293b;transition:all 0.15s;text-align:center;" onmouseover="this.style.borderColor='#059669';this.style.background='#f0fdf4'" onmouseout="this.style.borderColor='#e2e8f0';this.style.background='white'">
            <span style="font-size:2rem;">📤</span>
            <div>
                <div style="font-weight:600;font-size:0.9rem;">Penarikan Tunai</div>
                <div style="font-size:0.75rem;color:#64748b;margin-top:0.2rem;">Proses penarikan dana</div>
            </div>
        </a>
        <a href="<?php echo e(route('teller.angsuran')); ?>" style="display:flex;flex-direction:column;align-items:center;gap:0.75rem;
            padding:1.5rem 1rem;border-radius:0.75rem;border:2px solid #e2e8f0;text-decoration:none;
            color:#1e293b;transition:all 0.15s;text-align:center;" onmouseover="this.style.borderColor='#059669';this.style.background='#f0fdf4'" onmouseout="this.style.borderColor='#e2e8f0';this.style.background='white'">
            <span style="font-size:2rem;">💳</span>
            <div>
                <div style="font-weight:600;font-size:0.9rem;">Bayar Angsuran</div>
                <div style="font-size:0.75rem;color:#64748b;margin-top:0.2rem;">Pembayaran angsuran</div>
            </div>
        </a>
    </div>
</div>


<div class="card">
    <div class="card-header">
        <span class="card-title">Transaksi Terakhir</span>
        <a href="<?php echo e(route('teller.riwayat')); ?>" class="btn btn-primary" style="font-size:0.8rem;padding:0.4rem 0.9rem;">Lihat Semua →</a>
    </div>
    <div id="teller-transaksi"
         data-denyut-domain="transaksi"
         data-denyut-url="<?php echo e(route('dashboard.teller.ringkas')); ?>"
         data-denyut-sasaran="transaksi">
        <?php echo $__env->make('dashboard.partials.teller-transaksi', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    </div>
</div>


<div class="card">
    <div class="card-header">
        <span class="card-title">Pengeluaran Beban Saya</span>
        <a href="<?php echo e(route('pengeluaran-beban.index')); ?>" class="btn btn-primary" style="font-size:0.8rem;padding:0.4rem 0.9rem;">Lihat Semua →</a>
    </div>
    <?php if($bebanTerakhir->count() > 0): ?>
    <table>
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>No. Bukti</th>
                <th>Akun Beban</th>
                <th>Nominal</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php $__currentLoopData = $bebanTerakhir; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $bb): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <tr>
                <td style="white-space:nowrap;font-size:0.8rem;color:#64748b;"><?php echo e($bb->tanggal?->format('d/m/Y')); ?></td>
                <td style="font-family:monospace;font-size:0.8rem;"><?php echo e($bb->no_pengeluaran); ?></td>
                <td style="font-size:0.82rem;"><?php echo e($bb->coaBeban?->nama_akun ?? '-'); ?></td>
                <td style="font-weight:600;">Rp <?php echo e(number_format($bb->nominal, 0, ',', '.')); ?></td>
                <td>
                    <span class="badge <?php echo e($bb->status === 'Approved' ? 'badge-green' : ($bb->status === 'Ditolak' ? 'badge-red' : 'badge-amber')); ?>">
                        <?php echo e($bb->status); ?>

                    </span>
                </td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </tbody>
    </table>
    <?php else: ?>
    <div style="padding:2rem;text-align:center;color:#94a3b8;">
        <div>Belum ada pengeluaran beban</div>
    </div>
    <?php endif; ?>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\dashboard\teller.blade.php ENDPATH**/ ?>