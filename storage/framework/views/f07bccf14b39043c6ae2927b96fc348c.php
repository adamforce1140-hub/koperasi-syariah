<?php $__env->startSection('title', 'Dana Kebajikan / Ta\'zir — DPS'); ?>
<?php $__env->startSection('page-title', 'Dana Kebajikan / Ta\'zir'); ?>

<?php $__env->startSection('content'); ?>

<div style="background:#fef3c7;border:1px solid #fde68a;border-left:4px solid #d97706;border-radius:0.5rem;padding:0.875rem 1.25rem;margin-bottom:1.5rem;font-size:0.875rem;color:#92400e;">
    <strong>Landasan:</strong> Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000 — Dana ta'zir (denda keterlambatan) tidak boleh diakui sebagai pendapatan koperasi, wajib disalurkan sebagai dana sosial/kebajikan.
</div>


<div id="card-summary-dana-kebajikan"
     data-refresh-url="<?php echo e(route('dps.dana-kebajikan')); ?>"
     data-jumlah-mutasi="<?php echo e($validasiSaldo['valid'] ? 1 : 0); ?>"
     style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <div style="background:linear-gradient(135deg,#fef3c7,#fde68a);border-radius:0.75rem;padding:1.25rem;">
        <div style="font-size:0.75rem;font-weight:600;color:#92400e;text-transform:uppercase;margin-bottom:0.4rem;">Total Terkumpul</div>
        <div style="font-size:1.5rem;font-weight:700;color:#78350f;" data-field="total-terkumpul">Rp <?php echo e(number_format($totalTerkumpul, 0, ',', '.')); ?></div>
        <div style="font-size:0.75rem;color:#92400e;margin-top:0.25rem;">Mutasi KREDIT 2-3001 (dana masuk)</div>
    </div>
    <div style="background:linear-gradient(135deg,#dcfce7,#bbf7d0);border-radius:0.75rem;padding:1.25rem;">
        <div style="font-size:0.75rem;font-weight:600;color:#166534;text-transform:uppercase;margin-bottom:0.4rem;">Total Disalurkan</div>
        <div style="font-size:1.5rem;font-weight:700;color:#14532d;" data-field="total-disalurkan">Rp <?php echo e(number_format($totalDisalurkan, 0, ',', '.')); ?></div>
        <div style="font-size:0.75rem;color:#166534;margin-top:0.25rem;">Mutasi DEBET 2-3001 (dana keluar)</div>
    </div>
    <div style="background:linear-gradient(135deg,#fee2e2,#fecaca);border-radius:0.75rem;padding:1.25rem;">
        <div style="font-size:0.75rem;font-weight:600;color:#991b1b;text-transform:uppercase;margin-bottom:0.4rem;">Saldo Dana Kebajikan</div>
        <div style="font-size:1.5rem;font-weight:700;color:#7f1d1d;" data-field="saldo-kebajikan">Rp <?php echo e(number_format($saldoKebajikan, 0, ',', '.')); ?></div>
        <div style="font-size:0.75rem;color:#991b1b;margin-top:0.25rem;">Total Terkumpul − Total Disalurkan</div>
    </div>
</div>


<?php if(! ($validasiSaldo['valid'] ?? true)): ?>
<div style="background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #dc2626;border-radius:0.5rem;padding:0.75rem 1.25rem;margin-bottom:1.5rem;font-size:0.8125rem;color:#991b1b;">
    <strong>Peringatan validasi saldo:</strong>
    <ul style="margin:0.35rem 0 0 1.1rem;">
        <?php $__currentLoopData = $validasiSaldo['catatan']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $catatan): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <li><?php echo e($catatan); ?></li>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </ul>
</div>
<?php endif; ?>


<div class="card">
    <div class="card-header">
        <span class="card-title">Riwayat Transaksi Dana Kebajikan</span>
        <span style="font-size:0.78rem;color:#64748b;"><?php echo e($transaksi->total()); ?> transaksi</span>
    </div>

    <?php if($transaksi->count() > 0): ?>
    <table style="font-size:0.82rem;">
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>No. Transaksi</th>
                <th>Anggota</th>
                <th>Jenis</th>
                <th>Posisi</th>
                <th style="text-align:right;">Jumlah</th>
                <th>Status</th>
                <th>Input Oleh</th>
                <th>Catatan</th>
            </tr>
        </thead>
        <tbody>
            <?php $__currentLoopData = $transaksi; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $trx): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <tr>
                <td style="font-size:0.78rem;color:#64748b;white-space:nowrap;">
                    <?php echo e($trx->waktu_transaksi ? $trx->waktu_transaksi->format('d/m/Y H:i') : '-'); ?>

                </td>
                <td style="font-family:monospace;font-size:0.75rem;"><?php echo e($trx->no_transaksi ?? '-'); ?></td>
                <td><?php echo e($trx->rekening?->anggota?->nama_lengkap ?? ($trx->anggota?->nama_lengkap ?? '-')); ?></td>
                <td>
                    <span class="badge badge-amber" style="font-size:0.72rem;"><?php echo e($trx->jenis_transaksi ?? '-'); ?></span>
                </td>
                <td>
                    
                    <?php $posisiKebajikan = $trx->posisi_kebajikan ?? 'Kredit'; ?>
                    <span class="badge <?php echo e($posisiKebajikan === 'Kredit' ? 'badge-green' : 'badge-red'); ?>" style="font-size:0.72rem;">
                        <?php echo e($posisiKebajikan); ?>

                    </span>
                </td>
                <td style="text-align:right;font-weight:600;">Rp <?php echo e(number_format($trx->jumlah, 0, ',', '.')); ?></td>
                <td>
                    <span class="badge <?php echo e(($trx->status_transaksi ?? '') === 'Voided' ? 'badge-red' : 'badge-green'); ?>" style="font-size:0.72rem;">
                        <?php echo e($trx->status_transaksi ?? 'Sukses'); ?>

                    </span>
                </td>
                <td style="font-size:0.78rem;"><?php echo e($trx->dibuatOleh?->name ?? '-'); ?></td>
                <td style="font-size:0.78rem;color:#64748b;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo e($trx->catatan); ?>">
                    <?php echo e($trx->catatan ?? '—'); ?>

                </td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </tbody>
    </table>

    <?php if($transaksi->hasPages()): ?>
    <div style="padding:1rem 1.25rem;border-top:1px solid #e2e8f0;">
        <?php echo e($transaksi->links()); ?>

    </div>
    <?php endif; ?>

    <?php else: ?>
    <div style="padding:2rem;text-align:center;color:#94a3b8;">
        <div style="font-size:2rem;margin-bottom:0.5rem;">🎉</div>
        <div style="font-weight:600;">Tidak ada transaksi denda/ta'zir</div>
        <div style="font-size:0.8rem;margin-top:0.25rem;">Semua anggota membayar tepat waktu.</div>
    </div>
    <?php endif; ?>
</div>


<script>
(function () {
    const kartu = document.getElementById('card-summary-dana-kebajikan');
    if (!kartu) return;

    const url = kartu.dataset.refreshUrl + '/ringkasan';
    const rupiah = (nilai) => 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.round(nilai));
    let jumlahMutasiTerakhir = null;

    async function segarkanRingkasan() {
        try {
            const respon = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                cache: 'no-store',
            });
            if (!respon.ok) return;

            const data = await respon.json();

            // Refresh hanya bila ada transaksi baru (jumlah mutasi berubah).
            if (jumlahMutasiTerakhir !== null && data.jumlahMutasi === jumlahMutasiTerakhir) return;
            const transaksiBaru = jumlahMutasiTerakhir !== null;
            jumlahMutasiTerakhir = data.jumlahMutasi;

            kartu.querySelector('[data-field="total-terkumpul"]').textContent  = rupiah(data.totalTerkumpul);
            kartu.querySelector('[data-field="total-disalurkan"]').textContent = rupiah(data.totalDisalurkan);
            kartu.querySelector('[data-field="saldo-kebajikan"]').textContent  = rupiah(data.saldoKebajikan);

            // Transaksi baru → muat ulang daftar riwayat agar ikut tersinkron.
            if (transaksiBaru) window.location.reload();
        } catch (e) {
            // Kegagalan refresh tidak mengganggu halaman; nilai terakhir tetap tampil.
        }
    }

    segarkanRingkasan();
    setInterval(segarkanRingkasan, 15000);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) segarkanRingkasan();
    });
})();
</script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/dps/dana-kebajikan.blade.php ENDPATH**/ ?>