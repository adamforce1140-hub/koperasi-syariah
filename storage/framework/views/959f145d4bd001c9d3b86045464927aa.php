<nav class="sidebar" id="sidebarUtama" aria-label="Navigasi utama">
    <div class="sidebar-brand">
        
        <span class="brand-mark">
            <svg viewBox="0 0 44 44" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Logo Koperasi Syariah">
                <rect width="44" height="44" rx="13" fill="#fff"/>
                
                <path d="M15.5 12v20" stroke="var(--tema-ke)" stroke-width="3.4" stroke-linecap="round"/>
                
                <path d="M28.5 12l-9 10 9 10" stroke="var(--tema-dari)" stroke-width="3.4"
                      stroke-linecap="round" stroke-linejoin="round"/>
                
                <circle cx="28.5" cy="22" r="2.6" fill="var(--tema-aksen-solid)"/>
            </svg>
        </span>
        <div class="sidebar-brand-text">
            <strong><?php echo e(config('app.name', 'Koperasi Syariah')); ?></strong>
            <span>Sistem Digital</span>
        </div>
    </div>

    <div class="sidebar-nav">
        <?php
            // Gunakan $navMenu dari ShareNavigationMenu middleware,
            // fallback ke NavigationService langsung jika belum di-share
            $menu = $navMenu ?? \App\Services\NavigationService::getMenu(auth()->user()->role ?? '');
        ?>

        <?php $__empty_1 = true; $__currentLoopData = $menu; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <?php if(!empty($item['children'])): ?>
                
                <div class="nav-section">
                    <?php if(\App\Services\NavigationService::adaIkon($item['icon'] ?? null)): ?>
                        <span style="margin-right:0.35rem;"><?php echo e(\App\Services\NavigationService::ikon($item['icon'])); ?></span>
                    <?php endif; ?>
                    <?php echo e($item['label']); ?>

                </div>
                <?php $__currentLoopData = $item['children']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $child): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php
                        try {
                            $childUrl = route($child['route']);
                            $isActive = request()->routeIs($child['route']);
                        } catch (\Exception $e) {
                            $childUrl = '#';
                            $isActive = false;
                        }
                        $childIcon = \App\Services\NavigationService::adaIkon($child['icon'] ?? null)
                            ? \App\Services\NavigationService::ikon($child['icon'])
                            : '›';
                        $childBadge = !empty($child['badge'])
                            ? \App\Services\NavigationService::badge($child['badge'])
                            : 0;
                    ?>
                    <a href="<?php echo e($childUrl); ?>" class="nav-item <?php echo e($isActive ? 'active' : ''); ?>"
                       <?php if($isActive): ?> aria-current="page" <?php endif; ?>>
                        <span class="icon" aria-hidden="true"><?php echo e($childIcon); ?></span>
                        <?php echo e($child['label']); ?>

                        <?php if($childBadge > 0): ?>
                            <span aria-hidden="true" style="margin-left:auto;background:#ef4444;color:#fff;font-size:0.65rem;
                                font-weight:700;padding:0.1rem 0.45rem;border-radius:9999px;"><?php echo e($childBadge); ?></span>
                            <span class="sr-only">(<?php echo e($childBadge); ?> menunggu tindakan)</span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            <?php else: ?>
                
                <?php
                    try {
                        $itemUrl = route($item['route']);
                        $isActive = request()->routeIs($item['route']);
                    } catch (\Exception $e) {
                        $itemUrl = '#';
                        $isActive = false;
                    }
                    $itemIcon = \App\Services\NavigationService::ikon($item['icon'] ?? null);
                ?>
                <a href="<?php echo e($itemUrl); ?>" class="nav-item <?php echo e($isActive ? 'active' : ''); ?>"
                   <?php if($isActive): ?> aria-current="page" <?php endif; ?>>
                    <span class="icon" aria-hidden="true"><?php echo e($itemIcon); ?></span>
                    <?php echo e($item['label']); ?>

                </a>
            <?php endif; ?>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <div class="nav-section">Menu</div>
            
            <a href="<?php echo e(url('/')); ?>" class="nav-item">Dashboard</a>
        <?php endif; ?>
    </div>

    <div class="sidebar-footer">
        <div class="user-info">
            <div class="avatar" aria-hidden="true">
                <?php echo e(strtoupper(substr(auth()->user()->name, 0, 1))); ?>

            </div>
            <div>
                <div class="user-name"><?php echo e(auth()->user()->name); ?></div>
                <div class="user-role">
                    <?php echo e(\App\Support\HierarkiRole::label(auth()->user()->role)); ?>

                </div>
            </div>
        </div>
    </div>
</nav>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\layouts\sidebar.blade.php ENDPATH**/ ?>