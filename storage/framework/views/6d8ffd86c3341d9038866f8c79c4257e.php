
<?php if($jumlahAnggotaHold > 0): ?>
<div class="card" style="border:none;border-left:4px solid #dc2626;background:#fef2f2;">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1rem;">
        <div>
            <div style="font-size:0.95rem;font-weight:700;color:#991b1b;display:flex;align-items:center;gap:0.5rem;">
                <span>⚠️</span> Penahanan SHU (HOLD) — Tunggakan Simpanan Wajib
            </div>
            <div style="font-size:0.78rem;color:#b91c1c;margin-top:0.25rem;">
                Tahun buku <?php echo e(now()->year); ?> &middot; Kebijakan <code>shu_hold_on_overdue</code> aktif
            </div>
        </div>
        <a href="<?php echo e(route('pengurus.shu.index')); ?>" class="btn btn-sm"
           style="background:#dc2626;color:#fff;text-decoration:none;padding:0.45rem 0.9rem;
                  border-radius:0.5rem;font-size:0.78rem;font-weight:600;">
            Kelola SHU →
        </a>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:0.875rem;margin-bottom:1rem;">
        <div style="background:#fff;border:1px solid #fecaca;border-radius:0.75rem;padding:0.875rem 1rem;">
            <div style="font-size:0.72rem;color:#b91c1c;font-weight:600;">Anggota Ditahan</div>
            <div style="font-size:1.35rem;font-weight:700;color:#dc2626;"><?php echo e($jumlahAnggotaHold); ?></div>
        </div>
        <div style="background:#fff;border:1px solid #fecaca;border-radius:0.75rem;padding:0.875rem 1rem;">
            <div style="font-size:0.72rem;color:#b91c1c;font-weight:600;">Total Tunggakan</div>
            <div style="font-size:1.35rem;font-weight:700;color:#dc2626;">
                Rp <?php echo e(number_format($totalTunggakanHold, 0, ',', '.')); ?>

            </div>
        </div>
        <?php if($totalShuHold > 0): ?>
        <div style="background:#fff;border:1px solid #fecaca;border-radius:0.75rem;padding:0.875rem 1rem;">
            <div style="font-size:0.72rem;color:#b91c1c;font-weight:600;">SHU Tertahan (2-1004)</div>
            <div style="font-size:1.35rem;font-weight:700;color:#dc2626;">
                Rp <?php echo e(number_format($totalShuHold, 0, ',', '.')); ?>

            </div>
        </div>
        <?php endif; ?>
    </div>

    <div style="overflow-x:auto;background:#fff;border:1px solid #fecaca;border-radius:0.75rem;">
        <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
            <thead>
                <tr style="background:#fee2e2;color:#991b1b;">
                    <th style="padding:0.6rem 0.75rem;text-align:left;font-weight:700;">No. Anggota</th>
                    <th style="padding:0.6rem 0.75rem;text-align:left;font-weight:700;">Nama Anggota</th>
                    <th style="padding:0.6rem 0.75rem;text-align:center;font-weight:700;">Tagihan</th>
                    <th style="padding:0.6rem 0.75rem;text-align:left;font-weight:700;">Periode</th>
                    <th style="padding:0.6rem 0.75rem;text-align:right;font-weight:700;">Nominal</th>
                    <th style="padding:0.6rem 0.75rem;text-align:center;font-weight:700;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php $__currentLoopData = $daftarHold; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $h): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <tr style="border-top:1px solid #fee2e2;">
                    <td style="padding:0.55rem 0.75rem;font-family:monospace;color:#475569;">
                        <?php echo e($h['no_anggota'] ?? '—'); ?>

                    </td>
                    <td style="padding:0.55rem 0.75rem;font-weight:600;color:#1e293b;">
                        <?php echo e($h['nama_anggota']); ?>

                    </td>
                    <td style="padding:0.55rem 0.75rem;text-align:center;color:#dc2626;font-weight:700;">
                        <?php echo e($h['jumlah_tunggakan']); ?>x
                    </td>
                    <td style="padding:0.55rem 0.75rem;font-family:monospace;color:#64748b;font-size:0.75rem;">
                        <?php echo e($h['periode_awal']); ?> s/d <?php echo e($h['periode_akhir']); ?>

                    </td>
                    <td style="padding:0.55rem 0.75rem;text-align:right;font-weight:700;color:#dc2626;">
                        Rp <?php echo e(number_format($h['total_tunggakan'], 0, ',', '.')); ?>

                    </td>
                    <td style="padding:0.55rem 0.75rem;text-align:center;">
                        <a href="<?php echo e(route('pengurus.cif.show', $h['anggota_id'])); ?>"
                           style="color:#1e40af;font-weight:600;text-decoration:none;font-size:0.75rem;">
                            Detail CIF
                        </a>
                    </td>
                </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </tbody>
        </table>
    </div>

    <div style="font-size:0.72rem;color:#b91c1c;margin-top:0.75rem;">
        Pencairan SHU dilepas <strong>otomatis</strong> begitu seluruh tunggakan dilunasi
        di Teller (jurnal: Debet 2-1004 Utang SHU Ditahan / Kredit 2-1001 Simpanan Sukarela).
    </div>
</div>
<?php endif; ?>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\dashboard\partials\pengurus-hold.blade.php ENDPATH**/ ?>