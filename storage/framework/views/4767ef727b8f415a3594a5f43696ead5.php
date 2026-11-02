<?php $__env->startSection('title', 'Simpanan Anggota — Pengurus'); ?>
<?php $__env->startSection('page-title', 'Simpanan Anggota'); ?>

<?php $__env->startSection('content'); ?>
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
                Simpanan Anggota
            </h1>
            <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
                Rekap simpanan pokok, wajib, dan sukarela seluruh anggota koperasi.
            </p>
        </div>
        <a href="<?php echo e(route('pengurus.simpanan.index')); ?>?export=excel"
           style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.5rem 1rem;
                  background:#059669;color:#fff;font-size:0.8rem;font-weight:600;
                  border-radius:0.5rem;text-decoration:none;box-shadow:0 1px 3px rgba(0,0,0,.12);"
           onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>
                <line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/>
            </svg>
            Export Excel
        </a>
    </div>

    
    <?php
        // Label kartu mengikuti master produk aktif (bukan nama literal),
        // sehingga penggantian nama jenis simpanan langsung tampil di sini.
        $warnaTipe = [
            \App\Models\JenisSimpanan::TIPE_POKOK    => ['color' => '#7c3aed', 'bg' => '#ede9fe'],
            \App\Models\JenisSimpanan::TIPE_WAJIB    => ['color' => '#059669', 'bg' => '#d1fae5'],
            \App\Models\JenisSimpanan::TIPE_SUKARELA => ['color' => '#d97706', 'bg' => '#fef3c7'],
        ];
        $jenisLabel = [];
        foreach (($jenisList ?? []) as $namaJenis) {
            $tipe  = \App\Models\JenisSimpanan::tipe($namaJenis) ?? \App\Models\JenisSimpanan::TIPE_LAIN;
            $warna = $warnaTipe[$tipe] ?? ['color' => '#64748b', 'bg' => '#f1f5f9'];
            $jenisLabel[$namaJenis] = ['label' => $namaJenis, 'color' => $warna['color'], 'bg' => $warna['bg']];
        }
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;">
        
        <div style="background:linear-gradient(135deg,#059669,#047857);color:#fff;
                    border-radius:0.875rem;padding:1.125rem 1.25rem;
                    display:flex;align-items:center;gap:1rem;box-shadow:0 2px 8px rgba(5,150,105,.3);">
            <div style="width:42px;height:42px;border-radius:0.625rem;background:rgba(255,255,255,.2);
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"
                     fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                    <line x1="1" y1="10" x2="23" y2="10"/>
                </svg>
            </div>
            <div>
                <div style="font-size:1.35rem;font-weight:700;line-height:1.1;">
                    Rp <?php echo e(number_format($totalSaldo, 0, ',', '.')); ?>

                </div>
                <div style="font-size:0.75rem;opacity:0.85;margin-top:0.125rem;">Total Saldo Simpanan</div>
            </div>
        </div>

        <?php $__currentLoopData = $jenisLabel; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $info): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <?php $data = $counterJenis->get($key); ?>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.125rem 1.25rem;
                    display:flex;align-items:center;gap:1rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="width:42px;height:42px;border-radius:0.625rem;background:<?php echo e($info['bg']); ?>;
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                     fill="none" stroke="<?php echo e($info['color']); ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 2a10 10 0 1 0 10 10H12V2z"/>
                    <path d="M12 2a10 10 0 0 1 10 10"/>
                    <path d="M12 12l0-10"/>
                </svg>
            </div>
            <div>
                <div style="font-size:1.2rem;font-weight:700;color:<?php echo e($info['color']); ?>;line-height:1.1;">
                    Rp <?php echo e(number_format($data?->total ?? 0, 0, ',', '.')); ?>

                </div>
                <div style="font-size:0.75rem;color:#64748b;margin-top:0.125rem;">
                    <?php echo e($info['label']); ?> (<?php echo e(number_format($data?->jumlah ?? 0)); ?> rek.)
                </div>
            </div>
        </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>

    
    <?php
        $tabList = array_merge(
            ['' => ['label' => 'Semua Simpanan', 'count' => $counterJenis->sum('jumlah')]],
            array_map(fn($v, $k) => ['label' => $v['label'], 'count' => $counterJenis->get($k)?->jumlah ?? 0],
                      $jenisLabel, array_keys($jenisLabel))
        );
        // rebuild properly
        $tabs = ['' => ['label' => 'Semua Simpanan', 'count' => $counterJenis->sum('jumlah')]];
        foreach($jenisLabel as $k => $v) {
            $tabs[$k] = ['label' => $v['label'], 'count' => $counterJenis->get($k)?->jumlah ?? 0];
        }
    ?>
    <div style="display:flex;flex-wrap:wrap;gap:0.5rem;">
        <?php $__currentLoopData = $tabs; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $tab): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <?php
            $isActive = $jenis === $key || ($key === '' && !$jenis);
            $color    = $jenisLabel[$key] ?? ['color' => '#059669', 'bg' => '#d1fae5'];
            $activeColor = $key === '' ? '#059669' : $color['color'];
            $activeBg    = $key === '' ? '#d1fae5' : $color['bg'];
            $params = array_filter(['search' => $search, 'jenis' => $key ?: null]);
            $url = route('pengurus.simpanan.index') . ($params ? '?' . http_build_query($params) : '');
        ?>
        <a href="<?php echo e($url); ?>"
           style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.45rem 0.875rem;
                  font-size:0.8rem;font-weight:600;border-radius:9999px;text-decoration:none;
                  border:1.5px solid <?php echo e($isActive ? $activeColor : '#e2e8f0'); ?>;
                  background:<?php echo e($isActive ? $activeColor : '#fff'); ?>;
                  color:<?php echo e($isActive ? '#fff' : '#475569'); ?>;
                  transition:all .15s;">
            <?php echo e($tab['label']); ?>

            <span style="display:inline-flex;align-items:center;justify-content:center;
                         min-width:1.25rem;height:1.25rem;padding:0 0.3rem;
                         background:<?php echo e($isActive ? 'rgba(255,255,255,.25)' : $activeBg); ?>;
                         color:<?php echo e($isActive ? '#fff' : $activeColor); ?>;
                         font-size:0.7rem;font-weight:700;border-radius:9999px;">
                <?php echo e($tab['count']); ?>

            </span>
        </a>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1rem 1.25rem;
                box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <form method="GET" action="<?php echo e(route('pengurus.simpanan.index')); ?>"
              style="display:flex;gap:0.75rem;align-items:flex-end;flex-wrap:wrap;">
            <input type="hidden" name="jenis" value="<?php echo e($jenis); ?>">
            <div style="flex:1;min-width:220px;">
                <label style="display:block;font-size:0.76rem;font-weight:600;color:#475569;margin-bottom:0.375rem;">
                    Cari Anggota
                </label>
                <div style="position:relative;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                         fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                         style="position:absolute;left:0.625rem;top:50%;transform:translateY(-50%);pointer-events:none;">
                        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="No. Anggota atau nama…"
                           style="width:100%;padding:0.5rem 0.75rem 0.5rem 2rem;border:1px solid #cbd5e1;
                                  border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;"
                           onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                </div>
            </div>
            <div style="display:flex;gap:0.5rem;">
                <button type="submit"
                        style="padding:0.5rem 1.125rem;background:#059669;color:#fff;font-size:0.825rem;font-weight:600;
                               border:none;border-radius:0.5rem;cursor:pointer;"
                        onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'">
                    Cari
                </button>
                <?php if($search): ?>
                <a href="<?php echo e(route('pengurus.simpanan.index')); ?><?php echo e($jenis ? '?jenis='.$jenis : ''); ?>"
                   style="display:inline-flex;align-items:center;padding:0.5rem 0.875rem;background:#f1f5f9;
                          color:#64748b;font-size:0.825rem;font-weight:600;border-radius:0.5rem;text-decoration:none;">
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
                <strong style="color:#1e293b;"><?php echo e($rekening->total()); ?></strong> rekening ditemukan
            </span>
            <span style="font-size:0.75rem;color:#94a3b8;">Hal. <?php echo e($rekening->currentPage()); ?> / <?php echo e($rekening->lastPage()); ?></span>
        </div>

        <?php if($rekening->count() > 0): ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                        <th style="padding:0.75rem 1.25rem;text-align:left;font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">No. Rekening</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Nama Anggota</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Jenis Simpanan</th>
                        <th style="padding:0.75rem 1rem;text-align:right;font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Saldo</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Dibuka</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__currentLoopData = $rekening; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php
                        $jenisBadge = $jenisLabel[$r->jenis_rekening] ?? ['label' => $r->jenis_rekening, 'color' => '#64748b', 'bg' => '#f1f5f9'];
                    ?>
                    <tr style="border-bottom:1px solid #f1f5f9;"
                        onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">
                        <td style="padding:0.875rem 1.25rem;">
                            <span style="font-family:monospace;font-size:0.8rem;font-weight:600;
                                         color:#7c3aed;background:#ede9fe;padding:0.2rem 0.5rem;border-radius:0.375rem;">
                                <?php echo e($r->no_rekening ?? '—'); ?>

                            </span>
                        </td>
                        <td style="padding:0.875rem 1rem;">
                            <div style="font-size:0.875rem;font-weight:600;color:#1e293b;">
                                <?php echo e($r->anggota?->nama_lengkap ?? '—'); ?>

                            </div>
                            <div style="font-size:0.75rem;color:#94a3b8;"><?php echo e($r->anggota?->no_anggota ?? ''); ?></div>
                        </td>
                        <td style="padding:0.875rem 1rem;">
                            <span style="display:inline-flex;align-items:center;padding:0.2rem 0.65rem;
                                         background:<?php echo e($jenisBadge['bg']); ?>;color:<?php echo e($jenisBadge['color']); ?>;
                                         font-size:0.75rem;font-weight:600;border-radius:9999px;">
                                <?php echo e($jenisBadge['label']); ?>

                            </span>
                        </td>
                        <td style="padding:0.875rem 1rem;text-align:right;">
                            <span style="font-size:0.875rem;font-weight:700;color:#059669;">
                                Rp <?php echo e(number_format($r->saldo ?? 0, 0, ',', '.')); ?>

                            </span>
                        </td>
                        <td style="padding:0.875rem 1rem;font-size:0.825rem;color:#64748b;">
                            <?php echo e($r->created_at?->format('d M Y') ?? '—'); ?>

                        </td>
                    </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>
        <?php if($rekening->hasPages()): ?>
        <div style="padding:0.875rem 1.25rem;border-top:1px solid #f1f5f9;"><?php echo e($rekening->links()); ?></div>
        <?php endif; ?>
        <?php else: ?>
        <div style="padding:3.5rem 1.5rem;text-align:center;">
            <div style="width:64px;height:64px;border-radius:50%;background:#d1fae5;
                        display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24"
                     fill="none" stroke="#059669" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                    <line x1="1" y1="10" x2="23" y2="10"/>
                </svg>
            </div>
            <div style="font-size:0.95rem;font-weight:600;color:#1e293b;margin-bottom:0.375rem;">Belum ada data simpanan</div>
            <p style="font-size:0.825rem;color:#94a3b8;">Tidak ada rekening simpanan yang sesuai filter.</p>
        </div>
        <?php endif; ?>
    </div>

</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\pengurus\simpanan.blade.php ENDPATH**/ ?>