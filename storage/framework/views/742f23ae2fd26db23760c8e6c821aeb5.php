<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?php echo $__env->yieldContent('judul'); ?> — <?php echo e(config('app.name', 'Koperasi Syariah')); ?></title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --primary: #047857;
            --primary-dark: #065f46;
            --focus-ring: #b45309;
            --radius: 0.85rem;
        }

        html, body { height: 100%; }
        body {
            font-family: 'Figtree', sans-serif;
            background: #eef2f6; color: #1e293b;
            display: flex; align-items: center; justify-content: center;
            padding: 1.5rem; -webkit-text-size-adjust: 100%;
        }

        /* WCAG 2.4.7 — indikator fokus konsisten */
        a:focus-visible, button:focus-visible, input:focus-visible {
            outline: 3px solid var(--focus-ring); outline-offset: 2px;
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.001ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.001ms !important;
            }
        }

        .kartu {
            width: 100%; max-width: 440px;
            background: #fff; border-radius: 1.5rem;
            padding: 2.25rem 2rem;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.14);
        }

        .merek { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.75rem; }
        .merek__lambang {
            width: 44px; height: 44px; flex-shrink: 0;
            border-radius: 0.75rem; display: flex; align-items: center; justify-content: center;
            background: linear-gradient(150deg, #064e3b 0%, #047857 100%);
        }
        .merek__lambang svg { width: 24px; height: 24px; stroke: #a7f3d0; }
        .merek__teks small {
            display: block; font-size: 0.66rem; font-weight: 700;
            letter-spacing: 0.12em; text-transform: uppercase; color: #059669;
        }
        .merek__teks strong { display: block; font-size: 0.95rem; font-weight: 700; color: #0f172a; }

        .judul { font-size: 1.35rem; font-weight: 700; color: #0f172a; letter-spacing: -0.01em; }
        .ket { margin-top: 0.35rem; font-size: 0.85rem; line-height: 1.55; color: #64748b; }

        .pesan {
            display: flex; gap: 0.6rem; align-items: flex-start;
            margin-top: 1.25rem; padding: 0.8rem 0.95rem;
            border-radius: var(--radius); font-size: 0.82rem; line-height: 1.5;
        }
        .pesan svg { width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px; }
        .pesan strong { display: block; }
        .pesan ul { margin: 0.3rem 0 0 1rem; }
        .pesan--galat { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .pesan--sukses { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }

        .form { display: flex; flex-direction: column; gap: 1.05rem; margin-top: 1.5rem; }

        .kolom label {
            display: block; margin-bottom: 0.4rem;
            font-size: 0.72rem; font-weight: 700; letter-spacing: 0.06em;
            text-transform: uppercase; color: #475569;
        }
        .kendali { position: relative; }
        .kendali__ikon {
            position: absolute; left: 0.85rem; top: 50%; transform: translateY(-50%);
            width: 18px; height: 18px; color: #94a3b8; pointer-events: none;
        }
        .kendali input {
            width: 100%; padding: 0.75rem 0.9rem 0.75rem 2.6rem;
            font-family: inherit; font-size: 0.9rem; color: #1e293b;
            background: #fff; border: 1px solid #cbd5e1; border-radius: var(--radius);
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .kendali input::placeholder { color: #94a3b8; }
        .kendali input:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(4, 120, 87, 0.15);
        }
        .petunjuk { margin-top: 0.35rem; font-size: 0.75rem; color: #64748b; }

        .tombol {
            display: flex; align-items: center; justify-content: center; gap: 0.5rem;
            width: 100%; min-height: 46px; padding: 0.8rem 1rem;
            font-family: inherit; font-size: 0.9rem; font-weight: 700; color: #fff;
            background: var(--primary); border: 1px solid var(--primary);
            border-radius: var(--radius); cursor: pointer;
            transition: background 0.15s, transform 0.1s;
        }
        .tombol:hover { background: var(--primary-dark); border-color: var(--primary-dark); }
        .tombol:active { transform: scale(0.995); }
        .tombol svg { width: 16px; height: 16px; }

        .kembali { margin-top: 1.5rem; text-align: center; font-size: 0.8rem; }
        .kembali a { font-weight: 600; color: var(--primary); text-decoration: none; }
        .kembali a:hover { text-decoration: underline; }

        @media (max-width: 480px) {
            body { padding: 0; align-items: stretch; }
            .kartu {
                max-width: 100%; min-height: 100dvh;
                border-radius: 0; padding: 1.75rem 1.5rem 2.5rem;
            }
        }
    </style>
</head>
<body>

<main class="kartu" id="konten-utama">
    <div class="merek">
        <div class="merek__lambang" aria-hidden="true">
            <svg fill="none" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                <path d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5m0 0h5m-5 0V11m0 10V11m0 0h5m-5 0H7" />
            </svg>
        </div>
        <div class="merek__teks">
            <small>Sistem Internal</small>
            <strong><?php echo e(config('app.name', 'Koperasi Syariah')); ?></strong>
        </div>
    </div>

    <h1 class="judul"><?php echo $__env->yieldContent('judul'); ?></h1>
    <p class="ket"><?php echo $__env->yieldContent('keterangan'); ?></p>

    <?php if(session('status')): ?>
    <div class="pesan pesan--sukses" role="status" aria-live="polite">
        <svg fill="none" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <div><?php echo e(session('status')); ?></div>
    </div>
    <?php endif; ?>

    <?php if($errors->any()): ?>
    <div class="pesan pesan--galat" role="alert">
        <svg fill="none" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <div>
            <strong>Periksa kembali isian Anda</strong>
            <ul>
                <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <li><?php echo e($error); ?></li>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <?php echo $__env->yieldContent('konten'); ?>

    <p class="kembali">
        <a href="<?php echo e(route('login')); ?>">&larr; Kembali ke halaman masuk</a>
    </p>
</main>

</body>
</html>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\auth\layout.blade.php ENDPATH**/ ?>