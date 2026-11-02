<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Masuk — <?php echo e(config('app.name', 'Koperasi Syariah')); ?></title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --primary: #047857;
            --primary-dark: #065f46;
            --primary-light: #059669;
            --gold: #d97706;
            --focus-ring: #b45309;
            --radius: 0.85rem;
        }

        html, body { height: 100%; }
        body {
            font-family: 'Figtree', sans-serif;
            background: #eef2f6;
            color: #1e293b;
            display: flex; align-items: center; justify-content: center;
            padding: 1.5rem;
            -webkit-text-size-adjust: 100%;
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

        .masuk {
            width: 100%; max-width: 1040px;
            display: grid; grid-template-columns: 1fr 1fr;
            background: #fff; border-radius: 1.5rem; overflow: hidden;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.14);
        }

        /* ── Panel kiri: identitas ───────────────────────────── */
        .masuk__identitas {
            position: relative; overflow: hidden;
            background: linear-gradient(150deg, #064e3b 0%, #065f46 45%, #047857 100%);
            color: #fff; padding: 2.5rem;
            display: flex; flex-direction: column; justify-content: space-between; gap: 2rem;
        }
        .masuk__identitas::after {
            content: ""; position: absolute; inset: 0; pointer-events: none;
            background-image: radial-gradient(rgba(255,255,255,0.16) 1px, transparent 1px);
            background-size: 18px 18px; opacity: 0.5;
        }
        .masuk__identitas > * { position: relative; z-index: 1; }

        .merek { display: flex; align-items: center; gap: 0.85rem; }
        .merek__lambang {
            width: 48px; height: 48px; flex-shrink: 0;
            border-radius: 0.85rem; display: flex; align-items: center; justify-content: center;
            background: rgba(255,255,255,0.14); border: 1px solid rgba(255,255,255,0.25);
        }
        .merek__lambang svg { width: 26px; height: 26px; stroke: #a7f3d0; }
        .merek__teks small {
            display: block; font-size: 0.68rem; font-weight: 700;
            letter-spacing: 0.12em; text-transform: uppercase; color: #a7f3d0;
        }
        .merek__teks strong { display: block; font-size: 1rem; font-weight: 700; letter-spacing: 0.01em; }

        .identitas__judul { font-size: 1.85rem; line-height: 1.2; font-weight: 700; letter-spacing: -0.01em; }
        .identitas__sub {
            margin-top: 0.75rem; font-size: 0.9rem; line-height: 1.6;
            color: rgba(255,255,255,0.78); max-width: 26rem;
        }
        .lencana-versi {
            display: inline-flex; align-items: center; gap: 0.5rem;
            padding: 0.35rem 0.75rem; margin-bottom: 1rem;
            border-radius: 9999px; font-size: 0.7rem; font-weight: 600;
            color: #a7f3d0; background: rgba(255,255,255,0.10);
            border: 1px solid rgba(167,243,208,0.35);
        }
        .lencana-versi span.titik {
            width: 7px; height: 7px; border-radius: 50%; background: #34d399;
            animation: denyut-titik 2s ease-in-out infinite;
        }
        @keyframes denyut-titik {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.35; transform: scale(0.8); }
        }

        .fitur { display: flex; flex-direction: column; gap: 0.6rem; }
        .fitur__item {
            display: flex; align-items: center; gap: 0.75rem;
            padding: 0.7rem 0.9rem; border-radius: 0.7rem;
            background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.12);
            font-size: 0.8rem; color: rgba(255,255,255,0.9);
        }
        .fitur__item svg { width: 18px; height: 18px; flex-shrink: 0; stroke: #a7f3d0; }

        /* ── Panel kanan: formulir ───────────────────────────── */
        .masuk__form { padding: 2.5rem; display: flex; flex-direction: column; justify-content: center; }
        .form__judul { font-size: 1.4rem; font-weight: 700; color: #0f172a; letter-spacing: -0.01em; }
        .form__ket { margin-top: 0.3rem; font-size: 0.86rem; color: #64748b; }

        .pesan-galat {
            display: flex; gap: 0.65rem; align-items: flex-start;
            margin: 1.25rem 0 0; padding: 0.85rem 1rem;
            border-radius: var(--radius); font-size: 0.82rem;
            background: #fef2f2; color: #991b1b; border: 1px solid #fecaca;
        }
        .pesan-galat svg { width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px; }
        .pesan-galat ul { margin: 0.35rem 0 0 1rem; }
        .pesan-galat strong { display: block; }

        .pesan-sukses {
            display: flex; gap: 0.65rem; align-items: flex-start;
            margin: 1.25rem 0 0; padding: 0.85rem 1rem;
            border-radius: var(--radius); font-size: 0.82rem; line-height: 1.5;
            background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0;
        }
        .pesan-sukses svg { width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px; }

        .form { display: flex; flex-direction: column; gap: 1.1rem; margin-top: 1.5rem; }

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
        .kendali--sandi input { padding-right: 3rem; }

        .tombol-lihat {
            position: absolute; right: 0.35rem; top: 50%; transform: translateY(-50%);
            width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;
            background: none; border: 0; border-radius: 0.5rem;
            color: #94a3b8; cursor: pointer;
        }
        .tombol-lihat:hover { color: #475569; background: #f1f5f9; }
        .tombol-lihat svg { width: 18px; height: 18px; }
        .hidden { display: none !important; }

        .baris-ingat {
            display: flex; align-items: center; justify-content: space-between;
            gap: 0.75rem; flex-wrap: wrap; font-size: 0.8rem; color: #475569;
        }
        .baris-ingat label { display: inline-flex; align-items: center; gap: 0.5rem; cursor: pointer; }
        .baris-ingat input[type="checkbox"] {
            width: 16px; height: 16px; accent-color: var(--primary); cursor: pointer;
        }
        .baris-ingat a { font-weight: 600; color: var(--primary); text-decoration: none; }
        .baris-ingat a:hover { text-decoration: underline; }

        .tombol-masuk {
            display: flex; align-items: center; justify-content: center; gap: 0.5rem;
            width: 100%; min-height: 46px; padding: 0.8rem 1rem;
            font-family: inherit; font-size: 0.9rem; font-weight: 700; color: #fff;
            background: var(--primary); border: 1px solid var(--primary);
            border-radius: var(--radius); cursor: pointer;
            transition: background 0.15s, transform 0.1s;
        }
        .tombol-masuk:hover { background: var(--primary-dark); border-color: var(--primary-dark); }
        .tombol-masuk:active { transform: scale(0.995); }
        .tombol-masuk svg { width: 16px; height: 16px; }

        /* ── Akses cepat demo ───────────────────────────────── */
        .demo { margin-top: 1.75rem; padding-top: 1.5rem; border-top: 1px solid #e2e8f0; }
        .demo__kepala {
            display: flex; align-items: center; justify-content: space-between;
            gap: 0.5rem; margin-bottom: 0.75rem;
        }
        .demo__judul {
            font-size: 0.7rem; font-weight: 700; letter-spacing: 0.07em;
            text-transform: uppercase; color: #475569;
        }
        .demo__petunjuk { font-size: 0.7rem; color: #94a3b8; }

        .demo__grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem; }
        .quick-account {
            text-align: left; cursor: pointer; font-family: inherit;
            padding: 0.6rem 0.7rem; background: #fff;
            border: 1px solid #e2e8f0; border-radius: 0.7rem;
            transition: border-color 0.15s, background 0.15s;
        }
        .quick-account:hover { border-color: #94a3b8; background: #f8fafc; }
        .quick-account:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
        .quick-account[aria-pressed="true"] { border-color: var(--primary); background: #f0fdf4; }
        .quick-account .peran {
            display: flex; align-items: center; gap: 0.4rem;
            font-size: 0.75rem; font-weight: 700; color: #334155;
        }
        .quick-account .peran i {
            width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; background: #94a3b8;
        }
        .quick-account .tugas {
            margin-top: 0.15rem; font-size: 0.68rem; color: #64748b;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .quick-account--teller  .peran i { background: #059669; }
        .quick-account--ao      .peran i { background: #0284c7; }
        .quick-account--manajer .peran i { background: #7c3aed; }
        .quick-account--pengurus .peran i { background: #e11d48; }
        .quick-account--dps     .peran i { background: #d97706; }

        /* Sorotan singkat saat kolom terisi otomatis */
        .sorot { box-shadow: 0 0 0 3px rgba(4, 120, 87, 0.3) !important; }

        /* ── Responsif ──────────────────────────────────────── */
        @media (max-width: 900px) {
            body { padding: 0; align-items: stretch; }
            .masuk { grid-template-columns: 1fr; border-radius: 0; min-height: 100dvh; max-width: 100%; }
            .masuk__identitas { padding: 1.75rem 1.5rem; gap: 1.5rem; }
            .identitas__judul { font-size: 1.4rem; }
            .fitur { display: none; }
            .masuk__form { padding: 1.75rem 1.5rem 2.5rem; }
        }
        @media (max-width: 480px) {
            .demo__grid { grid-template-columns: repeat(2, 1fr); }
            .identitas__sub { font-size: 0.84rem; }
        }
    </style>
</head>
<body>

<div class="masuk">

    
    <section class="masuk__identitas" aria-label="Identitas aplikasi">
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

        <div>
            <div class="lencana-versi">
                <span class="titik" aria-hidden="true"></span>
                Versi 2.5 &mdash; Terintegrasi
            </div>
            <h1 class="identitas__judul">Sistem Informasi<br>Koperasi Syariah</h1>
            <p class="identitas__sub">
                Pengelolaan keuangan berbasis syariah yang amanah, transparan,
                dan terhubung langsung ke jurnal serta laporan keuangan.
            </p>
        </div>

        <ul class="fitur">
            <li class="fitur__item">
                <svg fill="none" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                </svg>
                Pemisahan tugas antar peran (segregation of duties)
            </li>
            <li class="fitur__item">
                <svg fill="none" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 3v18M5 8h9a3 3 0 010 6H5" />
                </svg>
                Pengawasan kepatuhan syariah oleh DPS
            </li>
            <li class="fitur__item">
                <svg fill="none" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="4" y="10" width="16" height="11" rx="2" />
                    <path d="M8 10V7a4 4 0 018 0v3" />
                </svg>
                Otorisasi berjenjang dan batas kewenangan
            </li>
        </ul>
    </section>

    
    <section class="masuk__form" aria-label="Formulir masuk">
        <div>
            <h2 class="form__judul">Assalamu&rsquo;alaikum</h2>
            <p class="form__ket">Silakan masuk menggunakan akun kantor Anda.</p>
        </div>

        <?php if($errors->any()): ?>
        <div class="pesan-galat" role="alert">
            <svg fill="none" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <div>
                <strong>Autentikasi gagal</strong>
                <ul>
                    <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <?php if(session('status')): ?>
        <div class="pesan-sukses" role="status" aria-live="polite">
            <svg fill="none" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <div><?php echo e(session('status')); ?></div>
        </div>
        <?php endif; ?>

        <form action="<?php echo e(route('login')); ?>" method="POST" class="form">
            <?php echo csrf_field(); ?>

            <div class="kolom">
                <label for="email">Alamat Email</label>
                <div class="kendali">
                    <svg class="kendali__ikon" fill="none" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9" />
                    </svg>
                    <input type="email" id="email" name="email" value="<?php echo e(old('email')); ?>"
                           required autofocus autocomplete="username"
                           placeholder="nama@koperasi.com">
                </div>
            </div>

            <div class="kolom">
                <label for="password">Kata Sandi</label>
                <div class="kendali kendali--sandi">
                    <svg class="kendali__ikon" fill="none" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="4" y="10" width="16" height="11" rx="2" />
                        <path d="M8 10V7a4 4 0 018 0v3" />
                    </svg>
                    <input type="password" id="password" name="password" required
                           autocomplete="current-password" placeholder="••••••••">
                    <button type="button" class="tombol-lihat" onclick="togglePasswordVisibility()"
                            aria-label="Tampilkan atau sembunyikan kata sandi">
                        <svg id="eyeIcon" fill="none" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                        <svg id="eyeOffIcon" class="hidden" fill="none" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858-5.908a8.959 8.959 0 013.122-.613c4.478 0 8.268 2.943 9.542 7a9.97 9.97 0 01-2.001 3.447M9.88 9.88a3 3 0 104.24 4.24m-4.24-4.24L3 3l18 18" />
                        </svg>
                    </button>
                </div>
            </div>

            <div class="baris-ingat">
                <label>
                    <input type="checkbox" name="remember">
                    <span>Ingat saya</span>
                </label>
                <?php if(Route::has('password.request')): ?>
                <a href="<?php echo e(route('password.request')); ?>">Lupa kata sandi?</a>
                <?php endif; ?>
            </div>

            <button type="submit" class="tombol-masuk">
                <span>Masuk ke Sistem</span>
                <svg fill="none" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M14 5l7 7m0 0l-7 7m7-7H3" />
                </svg>
            </button>
        </form>

        <?php if(config('app.demo.login_enabled') && (string) config('app.demo.password', '') !== ''): ?>
        <?php ($demoPassword = (string) config('app.demo.password', '')); ?>

        
        <span id="data-demo-login" data-demo-password="<?php echo e($demoPassword); ?>" hidden></span>

        <div class="demo">
            <div class="demo__kepala">
                <span class="demo__judul">Akses cepat akun demo</span>
                <span class="demo__petunjuk">Klik untuk mengisi otomatis</span>
            </div>

            <div class="demo__grid">
                <button type="button" data-quick-email="superadmin@koperasi.test" class="quick-account">
                    <span class="peran"><i aria-hidden="true"></i> Super Admin</span>
                    <span class="tugas">Administrator sistem</span>
                </button>
                <button type="button" data-quick-email="teller@koperasi.test" class="quick-account quick-account--teller">
                    <span class="peran"><i aria-hidden="true"></i> Teller</span>
                    <span class="tugas">Kasir operasional</span>
                </button>
                <button type="button" data-quick-email="ao@koperasi.test" class="quick-account quick-account--ao">
                    <span class="peran"><i aria-hidden="true"></i> AO</span>
                    <span class="tugas">Analis pembiayaan</span>
                </button>
                <button type="button" data-quick-email="manajer@koperasi.test" class="quick-account quick-account--manajer">
                    <span class="peran"><i aria-hidden="true"></i> Manajer</span>
                    <span class="tugas">Approval standar</span>
                </button>
                <button type="button" data-quick-email="pengurus@koperasi.test" class="quick-account quick-account--pengurus">
                    <span class="peran"><i aria-hidden="true"></i> Pengurus</span>
                    <span class="tugas">Kebijakan &amp; master</span>
                </button>
                <button type="button" data-quick-email="dps@koperasi.test" class="quick-account quick-account--dps">
                    <span class="peran"><i aria-hidden="true"></i> DPS</span>
                    <span class="tugas">Pengawas syariah</span>
                </button>
            </div>
        </div>
        <?php endif; ?>
    </section>

</div>

<script>
    function togglePasswordVisibility() {
        const pwdInput = document.getElementById('password');
        const eyeIcon = document.getElementById('eyeIcon');
        const eyeOffIcon = document.getElementById('eyeOffIcon');

        if (pwdInput.type === 'password') {
            pwdInput.type = 'text';
            eyeIcon.classList.add('hidden');
            eyeOffIcon.classList.remove('hidden');
        } else {
            pwdInput.type = 'password';
            eyeIcon.classList.remove('hidden');
            eyeOffIcon.classList.add('hidden');
        }
    }

    function quickFill(email, password, btnElement) {
        const emailField = document.getElementById('email');
        const passwordField = document.getElementById('password');

        if (!emailField || !passwordField) {
            console.error('Login cepat gagal: field email/password tidak ditemukan di halaman.');
            return false;
        }
        if (!email) {
            console.error('Login cepat gagal: email tujuan tidak terdefinisi.');
            return false;
        }

        // Password demo diambil dari kontainer data, bukan dari atribut
        // onclick. Atribut onclick memakai tanda kutip ganda sebagai
        // pembatas, sehingga direktif json yang juga menghasilkan tanda
        // kutip ganda akan MEMOTONG atribut dan membuat handler error
        // sintaks (tombol tampak mati).
        const dataEl = document.getElementById('data-demo-login');
        const passwordDemo = (password !== undefined && password !== null && password !== '')
            ? password
            : (dataEl ? (dataEl.dataset.demoPassword || '') : '');

        if (!passwordDemo) {
            console.warn('Login cepat: password demo belum dikonfigurasi; hanya email yang diisi.');
        }

        emailField.value = email;
        passwordField.value = passwordDemo;

        // Fokuskan email agar pengguna melihat perubahan & dapat menyesuaikan.
        try {
            emailField.focus({ preventScroll: true });
        } catch (e) {
            emailField.focus();
        }

        // Sorotan singkat sebagai umpan balik visual bahwa kolom terisi.
        [emailField, passwordField].forEach(el => {
            el.classList.remove('sorot');
            void el.offsetWidth;
            el.classList.add('sorot');
            setTimeout(() => el.classList.remove('sorot'), 900);
        });

        if (btnElement && btnElement.classList) {
            btnElement.setAttribute('aria-pressed', 'true');
            setTimeout(() => btnElement.removeAttribute('aria-pressed'), 600);
        }

        return true;
    }

    /* Delegasi event: satu listener untuk seluruh tombol akses cepat.
     | Pendekatan ini tetap bekerja meski tombol ditambahkan ulang ke DOM,
     | dan tidak bergantung pada atribut onclick inline.
     |
     | Aksesibilitas keyboard tidak perlu listener tambahan: elemennya
     | <button> asli, sehingga Enter/Space sudah memicu event click yang
     | menggelembung (bubble) ke listener ini. */
    document.addEventListener('click', function (event) {
        const tombol = event.target.closest ? event.target.closest('.quick-account') : null;
        if (!tombol) {
            return;
        }

        const email = tombol.dataset.quickEmail || '';
        quickFill(email, undefined, tombol);
    });
</script>
</body>
</html>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/auth/login.blade.php ENDPATH**/ ?>