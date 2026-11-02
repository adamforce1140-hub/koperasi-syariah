<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Akses Ditolak</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', sans-serif;
            background: #f0fdf4;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            text-align: center;
            padding: 3rem;
        }
        .code {
            font-size: 6rem;
            font-weight: 800;
            color: #059669;
            line-height: 1;
        }
        h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1f2937;
            margin: 1rem 0 0.5rem;
        }
        p {
            color: #6b7280;
            margin-bottom: 2rem;
        }
        .role-info { margin-bottom: 1rem; }
        .actions { display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap; }
        .btn {
            display: inline-block;
            padding: 0.65rem 1.5rem;
            background: #059669;
            color: white;
            border-radius: 0.5rem;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
        }
        .btn:hover { background: #047857; }
        .btn-secondary { background: #6b7280; }
        .btn-secondary:hover { background: #4b5563; }
        .btn-danger { background: #dc2626; }
        .btn-danger:hover { background: #b91c1c; }

        /* --- Responsif (WCAG 1.4.10 Reflow, 2.5.5 Target Size) --- */
        @media (max-width: 640px) {
            .container { padding: 2rem 1.25rem; width: 100%; }
            .code { font-size: 4.5rem; }
            h1 { font-size: 1.25rem; }
            p { margin-bottom: 1.5rem; }
            .actions { flex-direction: column; align-items: stretch; }
            .actions form { display: block !important; }
            .btn { width: 100%; box-sizing: border-box;
                padding: 0.85rem 1.25rem; min-height: 44px;
                display: flex; align-items: center; justify-content: center; }
        }
        @media (max-width: 380px) {
            .code { font-size: 3.5rem; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="code">403</div>
        <h1>Akses Ditolak</h1>
        <p>Anda tidak memiliki izin untuk mengakses halaman ini.</p>
        <?php if(auth()->guard()->check()): ?>
            <?php
                // Label peran & tujuan dasbor dibaca dari sumber pusat
                // (config/role_hierarki.php) — mencakup SELURUH peran sah,
                // termasuk super_admin/admin yang sebelumnya tidak terdaftar.
                $role = auth()->user()->role;
                $roleLabel = \App\Support\HierarkiRole::label($role);
                $dash = \App\Support\HierarkiRole::dashboardRoute($role);
            ?>
            <p class="role-info">Anda login sebagai: <strong><?php echo e($roleLabel); ?></strong></p>
            <div class="actions">
                <?php if($dash && \Illuminate\Support\Facades\Route::has($dash)): ?>
                    <a href="<?php echo e(route($dash)); ?>" class="btn">Ke Dashboard <?php echo e($roleLabel); ?></a>
                <?php endif; ?>
                <a href="javascript:history.back()" class="btn btn-secondary">Kembali</a>
                <form action="<?php echo e(route('logout')); ?>" method="POST" style="display:inline">
                    <?php echo csrf_field(); ?>
                    <button type="submit" class="btn btn-danger">Ganti Akun</button>
                </form>
            </div>
        <?php else: ?>
            <a href="<?php echo e(route('login')); ?>" class="btn">Login</a>
        <?php endif; ?>
    </div>
</body>
</html>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/errors/403.blade.php ENDPATH**/ ?>