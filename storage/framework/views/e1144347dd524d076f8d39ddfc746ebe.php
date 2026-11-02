<?php $__env->startSection('title', 'Cari Data CIF Anggota — Teller'); ?>
<?php $__env->startSection('page-title', 'Cari Data CIF Anggota'); ?>

<?php $__env->startSection('content'); ?>
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    
    <div>
        <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;">Cari Data CIF Anggota</h1>
        <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
            Cari berdasarkan nama, NIK, No Anggota, atau No CIF untuk melihat portofolio anggota.
        </p>
    </div>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;
                box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <form method="GET" action="<?php echo e(route('teller.cif.index')); ?>"
              style="display:flex;gap:0.75rem;align-items:flex-end;flex-wrap:wrap;">
            <div style="flex:1;min-width:240px;">
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.375rem;">
                    Kata Kunci
                </label>
                <div style="position:relative;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                         fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                         style="position:absolute;left:0.625rem;top:50%;transform:translateY(-50%);pointer-events:none;">
                        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input type="text" name="q" value="<?php echo e($q); ?>"
                           placeholder="Nama, NIK, No Anggota, atau No CIF…"
                           autofocus
                           style="width:100%;padding:0.5rem 0.75rem 0.5rem 2rem;border:1px solid #cbd5e1;
                                  border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                </div>
            </div>
            <div style="display:flex;gap:0.5rem;">
                <button type="submit"
                        style="padding:0.5rem 1.25rem;background:#059669;color:#fff;font-size:0.825rem;font-weight:600;
                               border:none;border-radius:0.5rem;cursor:pointer;white-space:nowrap;"
                        onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'">
                    Cari
                </button>
                <?php if($q): ?>
                <a href="<?php echo e(route('teller.cif.index')); ?>"
                   style="display:inline-flex;align-items:center;padding:0.5rem 0.875rem;background:#f1f5f9;
                          color:#64748b;font-size:0.825rem;font-weight:600;border-radius:0.5rem;
                          text-decoration:none;white-space:nowrap;"
                   onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                    Reset
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    
    <?php if(is_null($anggota)): ?>
    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:3.5rem 1.5rem;
                box-shadow:0 1px 3px rgba(0,0,0,.05);text-align:center;">
        <div style="width:64px;height:64px;border-radius:50%;background:#dbeafe;
                    display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24"
                 fill="none" stroke="#1e40af" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
        </div>
        <div style="font-size:0.95rem;font-weight:600;color:#1e293b;margin-bottom:0.375rem;">Cari Data CIF</div>
        <p style="font-size:0.825rem;color:#94a3b8;max-width:340px;margin:0 auto;">
            Masukkan nama, NIK, atau No CIF untuk mencari data anggota.
        </p>
    </div>

    <?php elseif($anggota->count() === 0): ?>
    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:3.5rem 1.5rem;
                box-shadow:0 1px 3px rgba(0,0,0,.05);text-align:center;">
        <div style="width:64px;height:64px;border-radius:50%;background:#fee2e2;
                    display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24"
                 fill="none" stroke="#dc2626" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/>
                <line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
            </svg>
        </div>
        <div style="font-size:0.95rem;font-weight:600;color:#1e293b;margin-bottom:0.375rem;">Data Tidak Ditemukan</div>
        <p style="font-size:0.825rem;color:#94a3b8;max-width:340px;margin:0 auto;">
            Tidak ada anggota yang cocok dengan kata kunci "<strong><?php echo e($q); ?></strong>".
        </p>
    </div>

    <?php else: ?>
    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;
                box-shadow:0 1px 3px rgba(0,0,0,.05);overflow:hidden;">
        <div style="padding:0.875rem 1.25rem;border-bottom:1px solid #f1f5f9;
                    display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;">
            <span style="font-size:0.825rem;color:#64748b;">
                Ditemukan <strong style="color:#1e293b;"><?php echo e($anggota->total()); ?></strong> data
                untuk kata kunci "<strong style="color:#1e40af;"><?php echo e($q); ?></strong>"
            </span>
            <span style="font-size:0.75rem;color:#94a3b8;">
                Hal. <?php echo e($anggota->currentPage()); ?> / <?php echo e($anggota->lastPage()); ?>

            </span>
        </div>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                        <th style="padding:0.75rem 1.25rem;text-align:left;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">No CIF</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">No Anggota</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Nama</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">NIK</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">No HP</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Status</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__currentLoopData = $anggota; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $a): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php
                        // Normalisasi dulu agar nilai kanonik (ACTIVE/INACTIVE/…)
                        // dan alias legacy (Aktif/Nonaktif/…) memakai badge sama.
                        $st = \App\Models\Anggota::normalizeStatus($a->status_anggota ?? null)
                              ?? \App\Models\Anggota::STATUS_INACTIVE;
                        $badge = match($st) {
                            \App\Models\Anggota::STATUS_ACTIVE    => ['bg'=>'#d1fae5','color'=>'#065f46','dot'=>'#059669'],
                            \App\Models\Anggota::STATUS_KELUAR    => ['bg'=>'#fef3c7','color'=>'#92400e','dot'=>'#d97706'],
                            \App\Models\Anggota::STATUS_SUSPENDED => ['bg'=>'#ede9fe','color'=>'#5b21b6','dot'=>'#7c3aed'],
                            default                               => ['bg'=>'#fee2e2','color'=>'#991b1b','dot'=>'#dc2626'],
                        };
                    ?>
                    <tr style="border-bottom:1px solid #f1f5f9;"
                        onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">
                        <td style="padding:0.875rem 1.25rem;">
                            <span style="font-family:monospace;font-size:0.78rem;font-weight:600;
                                         color:#1e40af;background:#dbeafe;padding:0.2rem 0.5rem;border-radius:0.375rem;">
                                <?php echo e($a->no_cif ?? '—'); ?>

                            </span>
                        </td>
                        <td style="padding:0.875rem 1rem;font-size:0.825rem;color:#475569;"><?php echo e($a->no_anggota ?? '—'); ?></td>
                        <td style="padding:0.875rem 1rem;">
                            <div style="font-size:0.875rem;font-weight:600;color:#1e293b;"><?php echo e($a->nama_lengkap); ?></div>
                            <div style="font-size:0.75rem;color:#94a3b8;"><?php echo e($a->email ?? ''); ?></div>
                        </td>
                        <td style="padding:0.875rem 1rem;font-size:0.825rem;color:#475569;"><?php echo e($a->nik ?? '—'); ?></td>
                        <td style="padding:0.875rem 1rem;font-size:0.825rem;color:#475569;"><?php echo e($a->no_hp ?? '—'); ?></td>
                        <td style="padding:0.875rem 1rem;text-align:center;">
                            <span style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.25rem 0.75rem;
                                         background:<?php echo e($badge['bg']); ?>;color:<?php echo e($badge['color']); ?>;
                                         font-size:0.75rem;font-weight:600;border-radius:9999px;">
                                <span style="width:6px;height:6px;border-radius:50%;background:<?php echo e($badge['dot']); ?>;"></span>
                                <?php echo e(\App\Models\Anggota::STATUS_LABEL[$st] ?? $st); ?>

                            </span>
                        </td>
                        <td style="padding:0.875rem 1rem;text-align:center;">
                            <a href="<?php echo e(route('teller.cif.show', $a->id)); ?>"
                               style="padding:0.3rem 0.75rem;background:#dbeafe;color:#1e40af;font-size:0.775rem;
                                      font-weight:600;border-radius:0.375rem;text-decoration:none;"
                               onmouseover="this.style.background='#bfdbfe'" onmouseout="this.style.background='#dbeafe'">
                                Lihat Portofolio
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>
        <?php if($anggota->hasPages()): ?>
        <div style="padding:0.875rem 1.25rem;border-top:1px solid #f1f5f9;">
            <?php echo e($anggota->links()); ?>

        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/teller/cif/index.blade.php ENDPATH**/ ?>