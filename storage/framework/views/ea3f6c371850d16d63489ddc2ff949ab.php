<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo $__env->yieldContent('title', 'Formulir Publik'); ?> — <?php echo e(config('app.name')); ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #134e4a 100%);
            background-attachment: fixed;
            min-height: 100vh;
            min-height: 100dvh;
            color: white;
            padding: 2rem 1rem;
            overflow-x: hidden;
        }
        /* Media & tabel tidak pernah melampaui lebar layar (WCAG 1.4.10) */
        img, svg, video, canvas { max-width: 100%; height: auto; }
        table { max-width: 100%; }
        input, select, textarea { max-width: 100%; font-family: inherit; }
        @media (max-width: 640px) {
            body { padding: 1.25rem 0.75rem; }
        }
        @media print {
            body { background: white; color: #0f172a; }
        }
        input[type="file"] {
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 8px;
            padding: 0.5rem;
            color: #94a3b8;
            font-size: 0.85rem;
            width: 100%;
        }

        /* Aksesibilitas: hanya untuk pembaca layar (WCAG 1.1.1) */
        .sr-only {
            position: absolute;
            width: 1px; height: 1px;
            padding: 0; margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        /* Aksesibilitas: lewati ke konten utama (WCAG 2.4.1) */
        .skip-link {
            position: absolute;
            left: 1rem; top: -3rem;
            z-index: 1000;
            background: #0d9488;
            color: #ffffff;
            padding: 0.6rem 1rem;
            border-radius: 0 0 8px 8px;
            text-decoration: none;
            font-weight: 600;
            transition: top 0.2s;
        }
        .skip-link:focus { top: 0; }

        /* Aksesibilitas: indikator fokus terlihat (WCAG 2.4.7) */
        a:focus-visible,
        button:focus-visible,
        input:focus-visible,
        select:focus-visible,
        textarea:focus-visible,
        [tabindex]:focus-visible {
            outline: 3px solid #f0c040;
            outline-offset: 2px;
        }
        a:focus, button:focus, input:focus, select:focus, textarea:focus {
            outline: 3px solid #f0c040;
            outline-offset: 2px;
        }

        /* Aksesibilitas: hormati preferensi gerak minimal (WCAG 2.3.3) */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.001ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.001ms !important;
                scroll-behavior: auto !important;
            }
        }

        /* T-1 — halaman publik ini juga dibuka oleh pengguna yang SUDAH login
           (peran `nasabah` diarahkan ke formulir pengajuan keanggotaan).
           Tanpa tombol keluar mereka tidak punya jalan keluar dari sesi. */
        .keluar-publik {
            position: fixed;
            top: 1rem; right: 1rem;
            z-index: 900;
            display: flex; align-items: center; gap: 0.75rem;
            background: rgba(15, 23, 42, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 10px;
            padding: 0.5rem 0.75rem;
            font-size: 0.8rem;
            color: #cbd5e1;
        }
        .keluar-publik button {
            background: #0d9488;
            color: #ffffff;
            border: 0;
            border-radius: 6px;
            padding: 0.35rem 0.75rem;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
        }
        .keluar-publik button:hover { background: #0f766e; }
        @media (max-width: 640px) {
            .keluar-publik {
                position: static;
                margin: 0 auto 1rem;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <a href="#konten-utama" class="skip-link">Lewati ke konten utama</a>
    <?php if(auth()->guard()->check()): ?>
        <form method="POST" action="<?php echo e(route('logout')); ?>" class="keluar-publik">
            <?php echo csrf_field(); ?>
            <span>Masuk sebagai <?php echo e(auth()->user()->name); ?></span>
            <button type="submit">Keluar</button>
        </form>
    <?php endif; ?>
    <main id="konten-utama" tabindex="-1">
        <?php echo $__env->yieldContent('content'); ?>
    </main>
</body>
</html>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\layouts\publik.blade.php ENDPATH**/ ?>