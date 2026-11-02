<?php $__env->startSection('title', 'Kas & Rekening — Pengurus'); ?>
<?php $__env->startSection('page-title', 'Kas & Rekening'); ?>

<?php $__env->startSection('content'); ?>
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
                Kas & Rekening
            </h1>
            <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
                Arus kas operasional koperasi — pemantauan seluruh transaksi harian.
            </p>
        </div>
        
        <form method="GET" action="<?php echo e(route('pengurus.kas.index')); ?>"
              style="display:flex;gap:0.5rem;align-items:center;flex-shrink:0;flex-wrap:wrap;">
            <div>
                <label style="display:block;font-size:0.7rem;font-weight:600;color:#64748b;margin-bottom:0.2rem;">Dari</label>
                <input type="date" name="tgl_mulai" value="<?php echo e($tglMulai); ?>"
                       style="padding:0.4rem 0.625rem;border:1px solid #cbd5e1;border-radius:0.5rem;
                              font-size:0.8rem;color:#1e293b;outline:none;"
                       onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
            </div>
            <div>
                <label style="display:block;font-size:0.7rem;font-weight:600;color:#64748b;margin-bottom:0.2rem;">Sampai</label>
                <input type="date" name="tgl_selesai" value="<?php echo e($tglSelesai); ?>"
                       style="padding:0.4rem 0.625rem;border:1px solid #cbd5e1;border-radius:0.5rem;
                              font-size:0.8rem;color:#1e293b;outline:none;"
                       onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
            </div>
            <div style="padding-top:1.25rem;">
                <button type="submit"
                        style="padding:0.45rem 1rem;background:#059669;color:#fff;font-size:0.825rem;font-weight:600;
                               border:none;border-radius:0.5rem;cursor:pointer;"
                        onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'">
                    Tampilkan
                </button>
            </div>
        </form>
    </div>

    
    <?php
        // $kartu, $totalMasuk, $totalKeluar dihitung di PengurusController::kas()
        // memakai registri tunggal App\Support\JenisTransaksi, sehingga seluruh
        // varian nama historis (mis. "Setoran Simpanan Sukarela" vs
        // "Simpanan Sukarela") ikut terhitung.
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;">
        
        <div style="background:linear-gradient(135deg,#059669,#047857);color:#fff;
                    border-radius:0.875rem;padding:1.125rem 1.25rem;
                    display:flex;align-items:center;gap:1rem;box-shadow:0 2px 8px rgba(5,150,105,.3);">
            <div style="width:42px;height:42px;border-radius:0.625rem;background:rgba(255,255,255,.2);
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"
                     fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="1" x2="12" y2="23"/>
                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                </svg>
            </div>
            <div>
                <div style="font-size:1.2rem;font-weight:700;line-height:1.1;">
                    Rp <?php echo e(number_format($totalRekening, 0, ',', '.')); ?>

                </div>
                <div style="font-size:0.72rem;opacity:0.85;margin-top:0.125rem;">Total Saldo Rekening</div>
            </div>
        </div>

        
        <div style="background:#fff;border:1px solid #e2e8f0;border-top:3px solid #059669;
                    border-radius:0.875rem;padding:1rem 1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;
                        letter-spacing:0.05em;margin-bottom:0.375rem;">Kas Masuk</div>
            <div style="font-size:1.2rem;font-weight:700;color:#059669;">
                Rp <?php echo e(number_format($totalMasuk, 0, ',', '.')); ?>

            </div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:0.2rem;">Setoran + Angsuran</div>
        </div>

        
        <div style="background:#fff;border:1px solid #e2e8f0;border-top:3px solid #dc2626;
                    border-radius:0.875rem;padding:1rem 1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;
                        letter-spacing:0.05em;margin-bottom:0.375rem;">Kas Keluar</div>
            <div style="font-size:1.2rem;font-weight:700;color:#dc2626;">
                Rp <?php echo e(number_format($totalKeluar, 0, ',', '.')); ?>

            </div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:0.2rem;">Penarikan + Pencairan</div>
        </div>

        
        <?php $__currentLoopData = $kartu; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $k): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1rem 1.25rem;
                    box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.375rem;">
                <div style="width:8px;height:8px;border-radius:50%;background:<?php echo e($k['color']); ?>;flex-shrink:0;"></div>
                <span style="font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">
                    <?php echo e($k['label']); ?>

                </span>
            </div>
            <div style="font-size:1rem;font-weight:700;color:<?php echo e($k['color']); ?>;">
                Rp <?php echo e(number_format($k['total'], 0, ',', '.')); ?>

            </div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:0.1rem;">
                <?php echo e(number_format($k['jumlah'])); ?> transaksi
            </div>
        </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;
                box-shadow:0 1px 3px rgba(0,0,0,.05);overflow:hidden;">
        <div style="padding:0.875rem 1.25rem;border-bottom:1px solid #f1f5f9;
                    display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;">
            <div style="font-size:0.875rem;font-weight:600;color:#1e293b;">
                Riwayat Transaksi
                <span style="font-size:0.75rem;font-weight:400;color:#64748b;margin-left:0.375rem;">
                    <?php echo e($tglMulai); ?> s/d <?php echo e($tglSelesai); ?>

                </span>
            </div>
            <span style="font-size:0.75rem;color:#94a3b8;">
                <?php echo e($transaksi->total()); ?> transaksi — Hal. <?php echo e($transaksi->currentPage()); ?>/<?php echo e($transaksi->lastPage()); ?>

            </span>
        </div>

        <?php if($transaksi->count() > 0): ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                        <th style="padding:0.75rem 1.25rem;text-align:left;font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Waktu</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Nama Anggota</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Jenis Transaksi</th>
                        <th style="padding:0.75rem 1rem;text-align:right;font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Jumlah</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__currentLoopData = $transaksi; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $t): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php
                        $isKeluar   = in_array($t->jenis_transaksi, \App\Support\JenisTransaksi::cashOut(), true);
                    ?>
                    <tr style="border-bottom:1px solid #f1f5f9;"
                        onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">
                        <td style="padding:0.75rem 1.25rem;">
                            <div style="font-size:0.825rem;color:#1e293b;">
                                <?php echo e($t->waktu_transaksi ? \Carbon\Carbon::parse($t->waktu_transaksi)->format('d M Y') : '—'); ?>

                            </div>
                            <div style="font-size:0.72rem;color:#94a3b8;">
                                <?php echo e($t->waktu_transaksi ? \Carbon\Carbon::parse($t->waktu_transaksi)->format('H:i') : ''); ?>

                            </div>
                        </td>
                        <td style="padding:0.75rem 1rem;">
                            <div style="font-size:0.875rem;font-weight:600;color:#1e293b;">
                                <?php echo e($t->anggota?->nama_lengkap 
                                   ?? $t->pembiayaan?->anggota?->nama_lengkap 
                                   ?? $t->rekening?->anggota?->nama_lengkap 
                                   ?? '—'); ?>

                            </div>
                            <div style="font-size:0.72rem;color:#94a3b8;">
                                <?php echo e($t->rekening?->no_rekening ?? ($t->pembiayaan?->no_pembiayaan ?? '')); ?>

                            </div>
                        </td>
                        <td style="padding:0.75rem 1rem;">
                            <span style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.2rem 0.65rem;
                                         background:<?php echo e($isKeluar ? '#fee2e2' : '#d1fae5'); ?>;color:<?php echo e($isKeluar ? '#dc2626' : '#059669'); ?>;
                                         font-size:0.75rem;font-weight:600;border-radius:9999px;">
                                <span style="width:5px;height:5px;border-radius:50%;background:<?php echo e($isKeluar ? '#dc2626' : '#059669'); ?>;"></span>
                                <?php echo e($t->jenis_transaksi); ?>

                            </span>
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:right;">
                            <span style="font-size:0.875rem;font-weight:700;
                                         color:<?php echo e($isKeluar ? '#dc2626' : '#059669'); ?>;">
                                <?php echo e($isKeluar ? '-' : '+'); ?> Rp <?php echo e(number_format($t->jumlah ?? 0, 0, ',', '.')); ?>

                            </span>
                        </td>
                        <td style="padding:0.75rem 1rem;font-size:0.8rem;color:#64748b;max-width:200px;">
                            <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <?php echo e($t->catatan ?? '—'); ?>

                            </div>
                        </td>
                    </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>
        <?php if($transaksi->hasPages()): ?>
        <div style="padding:0.875rem 1.25rem;border-top:1px solid #f1f5f9;"><?php echo e($transaksi->links()); ?></div>
        <?php endif; ?>
        <?php else: ?>
        <div style="padding:3.5rem 1.5rem;text-align:center;">
            <div style="width:64px;height:64px;border-radius:50%;background:#d1fae5;
                        display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24"
                     fill="none" stroke="#059669" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="1" x2="12" y2="23"/>
                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                </svg>
            </div>
            <div style="font-size:0.95rem;font-weight:600;color:#1e293b;margin-bottom:0.375rem;">
                Belum ada transaksi
            </div>
            <p style="font-size:0.825rem;color:#94a3b8;">
                Tidak ada transaksi dalam periode <?php echo e($tglMulai); ?> — <?php echo e($tglSelesai); ?>.
            </p>
        </div>
        <?php endif; ?>
    </div>

</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\pengurus\kas.blade.php ENDPATH**/ ?>