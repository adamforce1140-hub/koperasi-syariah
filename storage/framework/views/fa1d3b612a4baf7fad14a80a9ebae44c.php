<?php $__env->startSection('title', 'Data CIF Anggota — AO'); ?>
<?php $__env->startSection('page-title', 'Data CIF Anggota'); ?>

<?php $__env->startSection('content'); ?>
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
                Data CIF Anggota
            </h1>
            <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
                Kelola Customer Information File — pendaftaran & pembaruan data anggota koperasi.
            </p>
        </div>
        <div style="display:flex;gap:0.625rem;flex-shrink:0;">
            <a href="<?php echo e(route('ao.cif.create')); ?>"
               style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.5rem 1rem;
                      background:#059669;color:#fff;font-size:0.8rem;font-weight:600;
                      border-radius:0.5rem;text-decoration:none;box-shadow:0 1px 3px rgba(0,0,0,.12);"
               onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Daftarkan Anggota Baru
            </a>
        </div>
    </div>

    
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;">
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.125rem 1.25rem;
                    display:flex;align-items:center;gap:1rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="width:42px;height:42px;border-radius:0.625rem;background:#dbeafe;
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                     fill="none" stroke="#1e40af" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
            </div>
            <div>
                <div style="font-size:1.5rem;font-weight:700;color:#1e293b;line-height:1.1;"><?php echo e(number_format($totalAnggota)); ?></div>
                <div style="font-size:0.75rem;color:#64748b;margin-top:0.125rem;">Total Anggota</div>
            </div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.125rem 1.25rem;
                    display:flex;align-items:center;gap:1rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="width:42px;height:42px;border-radius:0.625rem;background:#d1fae5;
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                     fill="none" stroke="#059669" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
            </div>
            <div>
                <div style="font-size:1.5rem;font-weight:700;color:#059669;line-height:1.1;"><?php echo e(number_format($totalAktif)); ?></div>
                <div style="font-size:0.75rem;color:#64748b;margin-top:0.125rem;">Anggota Aktif</div>
            </div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.125rem 1.25rem;
                    display:flex;align-items:center;gap:1rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="width:42px;height:42px;border-radius:0.625rem;background:#fee2e2;
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                     fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="15" y1="9" x2="9" y2="15"/>
                    <line x1="9" y1="9" x2="15" y2="15"/>
                </svg>
            </div>
            <div>
                <div style="font-size:1.5rem;font-weight:700;color:#dc2626;line-height:1.1;"><?php echo e(number_format($totalAnggota - $totalAktif)); ?></div>
                <div style="font-size:0.75rem;color:#64748b;margin-top:0.125rem;">Tidak Aktif</div>
            </div>
        </div>
    </div>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1rem 1.25rem;
                box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <form method="GET" action="<?php echo e(route('ao.cif.index')); ?>"
              style="display:grid;grid-template-columns:1fr auto auto;gap:0.75rem;align-items:end;">
            <div>
                <label style="display:block;font-size:0.76rem;font-weight:600;color:#475569;margin-bottom:0.375rem;">
                    Cari Anggota
                </label>
                <div style="position:relative;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                         fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                         style="position:absolute;left:0.625rem;top:50%;transform:translateY(-50%);pointer-events:none;">
                        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="Nama, NIK, No Anggota, atau No CIF…"
                           style="width:100%;padding:0.5rem 0.75rem 0.5rem 2rem;border:1px solid #cbd5e1;
                                  border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                </div>
            </div>
            <div>
                <label style="display:block;font-size:0.76rem;font-weight:600;color:#475569;margin-bottom:0.375rem;">
                    Status
                </label>
                <select name="status"
                        style="padding:0.5rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;
                               font-size:0.825rem;color:#1e293b;background:#fff;outline:none;appearance:auto;"
                        onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                    <option value="">Semua Status</option>
                    <?php $__currentLoopData = \App\Models\Anggota::STATUS_LIST; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $optSt): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($optSt); ?>" <?php echo e($statusFilter === $optSt ? 'selected' : ''); ?>>
                            <?php echo e(\App\Models\Anggota::STATUS_LABEL[$optSt] ?? $optSt); ?>

                        </option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
            </div>
            <div style="display:flex;gap:0.5rem;">
                <button type="submit"
                        style="padding:0.5rem 1.125rem;background:#059669;color:#fff;font-size:0.825rem;font-weight:600;
                               border:none;border-radius:0.5rem;cursor:pointer;white-space:nowrap;"
                        onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'">
                    Terapkan
                </button>
                <?php if($search || $statusFilter): ?>
                <a href="<?php echo e(route('ao.cif.index')); ?>"
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

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;
                box-shadow:0 1px 3px rgba(0,0,0,.05);overflow:hidden;">

        <div style="padding:0.875rem 1.25rem;border-bottom:1px solid #f1f5f9;
                    display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;">
            <span style="font-size:0.825rem;color:#64748b;">
                Menampilkan <strong style="color:#1e293b;"><?php echo e($anggota->firstItem() ?? 0); ?>–<?php echo e($anggota->lastItem() ?? 0); ?></strong>
                dari <strong style="color:#1e293b;"><?php echo e($anggota->total()); ?></strong> data
                <?php if($search || $statusFilter): ?>
                    <span style="margin-left:0.375rem;padding:0.15rem 0.5rem;background:#fef3c7;color:#92400e;
                                 border-radius:9999px;font-size:0.75rem;font-weight:600;">Filter aktif</span>
                <?php endif; ?>
            </span>
            <span style="font-size:0.75rem;color:#94a3b8;">Hal. <?php echo e($anggota->currentPage()); ?> / <?php echo e($anggota->lastPage()); ?></span>
        </div>

        <?php if($anggota->count() > 0): ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                        <th style="padding:0.75rem 1.25rem;text-align:left;font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">No CIF</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">No Anggota</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Nama Lengkap</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">NIK</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">No HP</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Kota</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Status</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Aksi</th>
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
                        <td style="padding:0.875rem 1rem;font-size:0.825rem;color:#475569;">
                            <?php echo e($a->no_anggota ?? '—'); ?>

                        </td>
                        <td style="padding:0.875rem 1rem;">
                            <div style="font-size:0.875rem;font-weight:600;color:#1e293b;"><?php echo e($a->nama_lengkap); ?></div>
                            <div style="font-size:0.75rem;color:#94a3b8;margin-top:0.125rem;"><?php echo e($a->email ?? '—'); ?></div>
                        </td>
                        <td style="padding:0.875rem 1rem;font-size:0.825rem;color:#475569;"><?php echo e($a->nik ?? '—'); ?></td>
                        <td style="padding:0.875rem 1rem;font-size:0.825rem;color:#475569;"><?php echo e($a->no_hp ?? '—'); ?></td>
                        <td style="padding:0.875rem 1rem;font-size:0.825rem;color:#475569;"><?php echo e($a->kota ?? '—'); ?></td>
                        <td style="padding:0.875rem 1rem;text-align:center;">
                            <span style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.25rem 0.75rem;
                                         background:<?php echo e($badge['bg']); ?>;color:<?php echo e($badge['color']); ?>;
                                         font-size:0.75rem;font-weight:600;border-radius:9999px;">
                                <span style="width:6px;height:6px;border-radius:50%;background:<?php echo e($badge['dot']); ?>;"></span>
                                <?php echo e(\App\Models\Anggota::STATUS_LABEL[$st] ?? $st); ?>

                            </span>
                        </td>
                        <td style="padding:0.875rem 1rem;text-align:center;">
                            <div style="display:inline-flex;gap:0.375rem;">
                                <a href="<?php echo e(route('ao.cif.show', $a->id)); ?>"
                                   style="padding:0.3rem 0.7rem;background:#dbeafe;color:#1e40af;font-size:0.775rem;
                                          font-weight:600;border-radius:0.375rem;text-decoration:none;"
                                   onmouseover="this.style.background='#bfdbfe'" onmouseout="this.style.background='#dbeafe'">
                                    Lihat
                                </a>
                                <a href="<?php echo e(route('ao.cif.edit', $a->id)); ?>"
                                   style="padding:0.3rem 0.7rem;background:#fef3c7;color:#92400e;font-size:0.775rem;
                                          font-weight:600;border-radius:0.375rem;text-decoration:none;"
                                   onmouseover="this.style.background='#fde68a'" onmouseout="this.style.background='#fef3c7'">
                                    Edit
                                </a>
                            </div>
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

        <?php else: ?>
        <div style="padding:3.5rem 1.5rem;text-align:center;">
            <div style="width:64px;height:64px;border-radius:50%;background:#dbeafe;
                        display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24"
                     fill="none" stroke="#1e40af" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                </svg>
            </div>
            <div style="font-size:0.95rem;font-weight:600;color:#1e293b;margin-bottom:0.375rem;">
                Belum ada data CIF
            </div>
            <p style="font-size:0.825rem;color:#94a3b8;max-width:320px;margin:0 auto 1rem;">
                <?php if($search || $statusFilter): ?>
                    Tidak ada anggota yang cocok dengan filter yang dipilih.
                <?php else: ?>
                    Belum ada anggota yang terdaftar di sistem.
                <?php endif; ?>
            </p>
            <?php if($search || $statusFilter): ?>
            <a href="<?php echo e(route('ao.cif.index')); ?>"
               style="display:inline-flex;align-items:center;padding:0.5rem 1.125rem;background:#059669;
                      color:#fff;font-size:0.825rem;font-weight:600;border-radius:0.5rem;text-decoration:none;">
                Reset Filter
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\ao\cif\index.blade.php ENDPATH**/ ?>