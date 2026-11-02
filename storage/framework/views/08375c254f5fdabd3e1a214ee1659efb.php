<!DOCTYPE html>
<html lang="id"
      data-denyut-url="<?php echo e(route('denyut')); ?>"
      data-denyut-jeda-aktif="<?php echo e(config('denyut.jeda_aktif')); ?>"
      data-denyut-jeda-idle="<?php echo e(config('denyut.jeda_idle')); ?>"
      data-denyut-sse="<?php echo e(config('denyut.sse') ? '1' : '0'); ?>"
      data-denyut-tunggu="<?php echo e(config('denyut.tunggu') ? '1' : '0'); ?>"
      data-denyut-alur-cepat="<?php echo e((config('denyut.sse') || config('denyut.tunggu')) ? '1' : '0'); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <title><?php echo $__env->yieldContent('title', config('app.name', 'Koperasi Syariah')); ?></title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary: #047857;
            --primary-dark: #047857;
            --primary-darker: #065f46;
            --gold: #d97706;
            --gold-light: #f59e0b;
            --sidebar-w: 260px;
            --focus-ring: #b45309;
            /* Tema warna per peran: hanya sidebar & header profil yang memakainya */
            --tema-dari: #065f46;
            --tema-ke: #059669;
            --tema-aksen: var(--gold-light);
            --tema-aksen-solid: #f59e0b;
        }
        body[data-role="super_admin"] { --tema-dari: #0f172a; --tema-ke: #475569; --tema-aksen: #cbd5e1; --tema-aksen-solid: #94a3b8; }
        body[data-role="admin"]       { --tema-dari: #881337; --tema-ke: #e11d48; --tema-aksen: #fecdd3; --tema-aksen-solid: #fb7185; }
        body[data-role="dps"]         { --tema-dari: #78350f; --tema-ke: #b45309; --tema-aksen: #fde68a; --tema-aksen-solid: #f59e0b; }
        body[data-role="pengurus"]    { --tema-dari: #4c1d95; --tema-ke: #7c3aed; --tema-aksen: #ddd6fe; --tema-aksen-solid: #a78bfa; }
        body[data-role="manajer"]     { --tema-dari: #1e3a8a; --tema-ke: #2563eb; --tema-aksen: #bfdbfe; --tema-aksen-solid: #60a5fa; }
        body[data-role="ao"]          { --tema-dari: #134e4a; --tema-ke: #0f766e; --tema-aksen: #99f6e4; --tema-aksen-solid: #2dd4bf; }
        body { font-family: 'Figtree', sans-serif; background: #f1f5f9; color: #1e293b;
            overflow-x: hidden; }

        /* WCAG 2.4.1 Bypass Blocks — tautan lompat ke konten (tampil saat fokus) */
        .skip-link {
            position: absolute; left: 0.75rem; top: -3.5rem; z-index: 999;
            background: #065f46; color: #fff; padding: 0.65rem 1.1rem;
            border-radius: 0 0 0.5rem 0.5rem; font-size: 0.875rem; font-weight: 600;
            text-decoration: none; transition: top 0.15s ease-in-out;
        }
        .skip-link:focus { top: 0; }

        /* WCAG 2.4.7 Fokus Terlihat — indikator fokus konsisten di seluruh elemen interaktif */
        a:focus-visible, button:focus-visible, input:focus-visible,
        select:focus-visible, textarea:focus-visible, [tabindex]:focus-visible {
            outline: 3px solid var(--focus-ring);
            outline-offset: 2px;
            border-radius: 0.25rem;
        }
        /* Fallback untuk peramban tanpa dukungan :focus-visible */
        a:focus, button:focus, input:focus, select:focus, textarea:focus {
            outline: 3px solid var(--focus-ring);
            outline-offset: 2px;
        }
        .nav-item:focus-visible, .profile-btn:focus-visible { outline-color: #fde68a; }

        /* WCAG 2.3.3 / 1.4.13 — hormati preferensi gerak minimal pengguna */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.001ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.001ms !important;
                scroll-behavior: auto !important;
            }
        }

        /* WCAG 2.4.1 / 4.1.2 — teks khusus pembaca layar (tetap terbaca) */
        .sr-only {
            position: absolute !important; width: 1px; height: 1px; padding: 0;
            margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0);
            white-space: nowrap; border: 0;
        }

        /* SIDEBAR */
        .sidebar {
            position: fixed; top: 0; left: 0; height: 100vh; height: 100dvh;
            width: var(--sidebar-w);
            background: linear-gradient(180deg, var(--tema-dari) 0%, var(--tema-ke) 100%);
            color: white; display: flex; flex-direction: column; z-index: 100;
            box-shadow: 4px 0 20px rgba(0,0,0,0.15);
            overscroll-behavior: contain;
        }
        .sidebar-brand {
            padding: 1.25rem 1.5rem; display: flex; align-items: center; gap: 0.75rem;
            border-bottom: 1px solid rgba(255,255,255,0.1); flex-shrink: 0;
        }
        .brand-mark { display: grid; place-items: center; width: 44px; height: 44px;
            flex-shrink: 0; background: #fff; border-radius: 13px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.18); }
        .brand-mark svg { width: 30px; height: 30px; flex-shrink: 0; }
        .sidebar-brand-text { line-height: 1.2; }
        .sidebar-brand-text strong { display: block; font-size: 0.95rem; font-weight: 700; }
        .sidebar-brand-text span { font-size: 0.7rem; opacity: 0.75; }
        /* min-height:0 wajib agar daftar menu benar-benar bisa digulir di dalam
           flex column; tanpa itu daftar mendorong footer keluar dari layar. */
        .sidebar-nav { flex: 1 1 auto; min-height: 0; padding: 1rem 0; overflow-y: auto; }
        .nav-section { padding: 0.5rem 1.25rem 0.25rem; font-size: 0.65rem; font-weight: 600;
            letter-spacing: 0.1em; opacity: 0.55; text-transform: uppercase; }
        .nav-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.65rem 1.5rem;
            color: rgba(255,255,255,0.8); text-decoration: none; font-size: 0.875rem;
            transition: all 0.15s; border-left: 3px solid transparent; }
        .nav-item:hover { background: rgba(255,255,255,0.1); color: white; }
        .nav-item.active { background: rgba(255,255,255,0.15); color: white;
            border-left-color: var(--tema-aksen); }
        .nav-item .icon { width: 18px; text-align: center; font-size: 1rem; }
        .sidebar-footer { padding: 1rem 1.5rem; border-top: 1px solid rgba(255,255,255,0.1);
            flex-shrink: 0; }
        .sidebar-footer .user-info { display: flex; align-items: center; gap: 0.75rem; }
        .avatar { width: 36px; height: 36px; border-radius: 50%; background: rgba(255,255,255,0.2);
            display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.875rem; }
        .user-name { font-size: 0.875rem; font-weight: 600; }
        .user-role { font-size: 0.7rem; opacity: 0.7; }

        /* MAIN */
        .main-wrapper { margin-left: var(--sidebar-w); min-height: 100vh; min-height: 100dvh;
            display: flex; flex-direction: column; min-width: 0; max-width: 100%; }
        .topbar { background: white; padding: 1rem 2rem; display: flex; align-items: center;
            justify-content: space-between; gap: 0.75rem; flex-wrap: wrap;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
        .topbar-title { font-size: 1.25rem; font-weight: 600; color: #1e293b;
            flex: 1 1 12rem; min-width: 0; overflow-wrap: anywhere; }
        .topbar-right { display: flex; align-items: center; gap: 1rem; font-size: 0.875rem;
            color: #64748b; flex-wrap: wrap; min-width: 0; }

        /* PROFILE DROPDOWN */
        .profile-wrap { position: relative; }
        .profile-btn { display: flex; align-items: center; gap: 0.6rem; padding: 0.4rem 0.75rem;
            border-radius: 0.65rem; cursor: pointer; border: 1.5px solid #e2e8f0;
            background: white; transition: all 0.15s; user-select: none; min-width: 0; }
        .profile-btn:hover { background: #f8fafc; border-color: #cbd5e1; }
        .profile-avatar { width: 32px; height: 32px; border-radius: 50%;
            background: linear-gradient(135deg, var(--tema-ke), var(--tema-dari));
            display: flex; align-items: center; justify-content: center;
            font-size: 0.82rem; font-weight: 700; color: white; flex-shrink: 0; }
        .profile-name { display: block; font-size: 0.85rem; font-weight: 600; color: #1e293b; max-width: 120px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .profile-role { display: block; font-size: 0.7rem; color: #64748b; }
        .profile-chevron { font-size: 0.65rem; color: #94a3b8; transition: transform 0.2s; }
        .profile-wrap.open .profile-chevron { transform: rotate(180deg); }
        .profile-dropdown { display: none; position: absolute; right: 0; top: calc(100% + 8px);
            background: white; border: 1px solid #e2e8f0; border-radius: 0.9rem;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12); min-width: 220px; z-index: 200;
            max-width: min(92vw, 320px); overflow: hidden; }
        .profile-wrap.open .profile-dropdown { display: block; }
        .profile-header { padding: 1rem 1.1rem; background: linear-gradient(135deg, var(--tema-dari), var(--tema-ke));
            color: white; }
        .profile-header .ph-name { font-size: 0.95rem; font-weight: 700; }
        .profile-header .ph-email { font-size: 0.75rem; opacity: 0.8; margin-top: 0.15rem; word-break: break-all; }
        .profile-header .ph-role { display: inline-block; margin-top: 0.5rem; font-size: 0.7rem;
            background: rgba(255,255,255,0.2); padding: 0.15rem 0.5rem; border-radius: 9999px; }
        .profile-menu { padding: 0.5rem 0; }
        .profile-menu a, .profile-menu button { display: flex; align-items: center; gap: 0.6rem;
            width: 100%; padding: 0.65rem 1.1rem; font-size: 0.85rem; color: #374151;
            text-decoration: none; background: none; border: none; cursor: pointer;
            font-family: inherit; transition: background 0.12s; text-align: left; }
        .profile-menu a:hover, .profile-menu button:hover { background: #f8fafc; }
        .profile-menu .menu-danger { color: #dc2626; }
        .profile-menu .menu-danger:hover { background: #fef2f2; }
        .profile-divider { height: 1px; background: #f1f5f9; margin: 0.25rem 0; }
        .content { padding: 2rem; flex: 1; min-width: 0; max-width: 100%; }

        /* Tombol pemicu sidebar — hanya tampil pada layar sempit */
        .sidebar-toggle {
            display: none; align-items: center; justify-content: center;
            width: 40px; height: 40px; margin-right: 0.75rem; flex-shrink: 0;
            background: #f1f5f9; color: #065f46; border: 1.5px solid #e2e8f0;
            border-radius: 0.5rem; font-size: 1.15rem; cursor: pointer;
        }
        .sidebar-toggle:hover { background: #e2e8f0; }
        #sidebarBackdrop { position: fixed; inset: 0; background: rgba(15,23,42,0.5); z-index: 99; }

        /* WCAG 1.4.10 Reflow — layout tetap dapat dipakai pada lebar 320 CSS px
           tanpa gulir dua arah. Sidebar berubah menjadi panel geser. */
        @media (max-width: 900px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.2s ease-in-out;
            }
            .sidebar.terbuka { transform: translateX(0); }
            .sidebar-toggle { display: inline-flex; }
            .main-wrapper { margin-left: 0; }
            .content { padding: 1.25rem 1rem; }
            .topbar { padding: 0.875rem 1rem; gap: 0.5rem; }
            .topbar-title { font-size: 1.05rem; }
            .topbar-right > span { display: none; }
            .profile-name { max-width: 90px; }
            /* Dropdown tidak boleh keluar dari tepi layar */
            .profile-dropdown { max-width: calc(100vw - 2rem); }
        }

        @media (max-width: 480px) {
            .content { padding: 1rem 0.75rem; }
            .profile-btn { padding: 0.35rem 0.5rem; }
            .profile-name, .profile-role { display: none; }
        }

        /* WCAG 1.4.4 Resize Text — tabel digeser di dalam wadahnya sendiri
           (disediakan oleh .tabel-geser), bukan dengan memotong halaman. */
        @media (max-width: 900px) {
            .content > .card, .content .card { overflow-wrap: break-word; }
            .content > .card, .content .card, .content > div { max-width: 100%; }
            /* Elemen flex anak tidak boleh memaksa induk melebar */
            .content > *, .card > * { min-width: 0; }
        }

        /* CARDS */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.25rem; margin-bottom: 2rem; }
        /* Anak grid harus boleh menyusut. Tanpa min-width:0, min-content kartu
           (mis. tabel lebar di dalam .tabel-geser) menjadi lebar minimum track dan
           grid ikut melebar melewati containernya — lalu kolom kanan terpotong.
           Aturan yang sama dipakai .chart-row dan .chart-row-2. */
        .stats-grid > *, .chart-row > *, .chart-row-2 > * { min-width: 0; }
        /* Varian 6 kartu (mis. ringkasan Super Admin): dipaksa 6 kolom pada layar
           lebar agar tidak menyisakan satu kartu menggantung di baris kedua. */
        .stats-grid.stats-grid--6 { grid-template-columns: repeat(6, minmax(0, 1fr)); }
        @media (max-width: 1500px) { .stats-grid.stats-grid--6 { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 900px)  { .stats-grid.stats-grid--6 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 560px)  { .stats-grid.stats-grid--6 { grid-template-columns: 1fr; } }
        .stat-card { background: white; border-radius: 1rem; padding: 1.5rem;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06); display: flex; align-items: center; gap: 1rem;
            transition: transform 0.15s, box-shadow 0.15s;
            /* Jadikan kartu sebagai container agar ukuran angka bisa menyesuaikan
               lebar kartu (bukan lebar layar) ketika kartu tampil 5–6 per baris. */
            container-type: inline-size; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,0.09); }
        .stat-icon { width: 52px; height: 52px; border-radius: 0.75rem; display: flex;
            align-items: center; justify-content: center; font-size: 1.4rem; flex-shrink: 0; }
        .stat-icon.green { background: #dcfce7; }
        .stat-icon.blue { background: #dbeafe; }
        .stat-icon.amber { background: #fef3c7; }
        .stat-icon.purple { background: #f3e8ff; }
        .stat-icon.red { background: #fee2e2; }
        .stat-value { font-size: 1.5rem; font-weight: 700; color: #1e293b; line-height: 1.25; }
        .stat-label { font-size: 0.8rem; color: #64748b; margin-top: 0.15rem; }
        /* Kartu sempit (mis. 5–6 kartu dalam satu baris): kecilkan angka agar
           nominal seperti "Rp 127,79 jt" tidak pecah menjadi tiga baris. */
        @container (max-width: 280px) {
            .stat-value { font-size: 1.2rem; }
            .stat-label { font-size: 0.75rem; }
        }
        @container (max-width: 235px) {
            .stat-value { font-size: 1.05rem; }
            .stat-icon { width: 44px; height: 44px; font-size: 1.2rem; }
        }
        /* Varian compact: .stat-info adalah pembungkus teks di dalam .stat-card
           agar teks membungkus dengan baik di layar sempit. */
        .stat-info { min-width: 0; flex: 1 1 auto; overflow-wrap: break-word; }
        @media (max-width: 420px) {
            .stat-card { padding: 1rem; gap: 0.75rem; border-radius: 0.85rem; }
            .stat-icon { width: 44px; height: 44px; font-size: 1.2rem; }
            .stat-value { font-size: 1.25rem; }
        }

        /* Pembungkus tabel agnostic + kepala halaman */
        .table-wrapper { overflow-x: auto; -webkit-overflow-scrolling: touch;
            max-width: 100%; min-width: 0; }
        .table-wrapper > table, .table-wrapper > .data-table { width: 100%; }
        .data-table { width: 100%; border-collapse: collapse; }
        .page-header { display: flex; align-items: flex-start; justify-content: space-between;
            gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; min-width: 0; }
        .page-header > * { min-width: 0; }

        .card { background: white; border-radius: 1rem; padding: 1.5rem; box-shadow: 0 1px 4px rgba(0,0,0,0.06); margin-bottom: 1.5rem; }
        /* Wadah kartu ringkas yang dipakai luas di modul AO/pengajuan.
           Tanpa definisi ini blok tampil tanpa padding/bingkai kartu. */
        .data-card { background: white; border: 1px solid #e2e8f0; border-radius: 0.9rem;
            padding: 1.25rem; margin-bottom: 1.25rem; max-width: 100%; min-width: 0;
            overflow-wrap: break-word; }
        .data-card > table { display: block; width: 100%; overflow-x: auto;
            -webkit-overflow-scrolling: touch; }
        /* Tabel di dalam .card ikut bisa digeser pada layar sempit. Tanpa aturan
           ini, `body { overflow-x: hidden }` membuat kolom yang melebar terpotong
           permanen: pengguna tidak bisa melihat sekaligus menggulir kolom kanan.
           Perlakuan disamakan dengan .data-card di atas. */
        .card > table { display: block; width: 100%; overflow-x: auto;
            -webkit-overflow-scrolling: touch; }
        .card-header { display: flex; align-items: center; justify-content: space-between;
            gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
        .card-header > * { min-width: 0; }
        .card-title { font-size: 1rem; font-weight: 600; color: #1e293b; }
        /* BANNER SAPAAN — dipakai keenam dashboard lewat partial
           dashboard.partials.sapaan (audit T6). Sebelumnya setiap role
           menulis style inline sendiri sehingga tampilannya berbeda-beda. */
        .sapaan { display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 1rem; padding: 1.5rem 2rem; margin-bottom: 1.5rem;
            border-radius: 1rem; color: white;
            background: linear-gradient(135deg, #065f46, #059669);
            box-shadow: 0 4px 16px rgba(5,150,105,0.22); }
        .sapaan--emas { background: linear-gradient(135deg, #78350f, #d97706);
            box-shadow: 0 4px 16px rgba(217,119,6,0.22); }
        .sapaan--ungu { background: linear-gradient(135deg, #4c1d95, #7c3aed);
            box-shadow: 0 4px 16px rgba(124,58,237,0.22); }
        .sapaan__identitas { display: flex; align-items: center; gap: 1.5rem; min-width: 0; }
        .sapaan__avatar { width: 56px; height: 56px; border-radius: 50%; flex-shrink: 0;
            background: rgba(255,255,255,0.2); display: flex; align-items: center;
            justify-content: center; font-size: 1.5rem; font-weight: 700; }
        .sapaan__teks { min-width: 0; }
        .sapaan__salam { font-size: 0.8rem; opacity: 0.8; }
        .sapaan__nama { font-size: 1.3rem; font-weight: 700; overflow-wrap: break-word; }
        .sapaan__meta { font-size: 0.78rem; opacity: 0.8; margin-top: 0.2rem; }
        .sapaan__badge { background: rgba(255,255,255,0.15); padding: 0.5rem 1rem;
            border-radius: 0.5rem; font-size: 0.78rem; text-align: center; flex-shrink: 0; }
        .sapaan__badge-judul { font-weight: 700; font-size: 1rem; }
        .sapaan__badge-keterangan { opacity: 0.8; }
        @media (max-width: 420px) {
            .sapaan { padding: 1.25rem 1.1rem; }
            .sapaan__identitas { gap: 1rem; }
            .sapaan__avatar { width: 46px; height: 46px; font-size: 1.2rem; }
            .sapaan__nama { font-size: 1.1rem; }
        }

        /* BADGE */
        .badge { display: inline-block; padding: 0.2rem 0.6rem; border-radius: 9999px; font-size: 0.72rem; font-weight: 600; }
        .badge-green { background: #dcfce7; color: #166534; }
        .badge-amber { background: #fef3c7; color: #92400e; }
        .badge-red { background: #fee2e2; color: #991b1b; }
        .badge-blue   { background: #dbeafe; color: #1e40af; }
        .badge-gray   { background: #f1f5f9; color: #475569; }
        .badge-teal   { background: #ccfbf1; color: #0f766e; }
        .badge-purple { background: #f3e8ff; color: #7e22ce; }

        /* TABLE */
        table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        th { text-align: left; padding: 0.75rem 1rem; color: #64748b; font-weight: 600;
            font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;
            border-bottom: 2px solid #e2e8f0; }
        td { padding: 0.875rem 1rem; border-bottom: 1px solid #f1f5f9; color: #334155; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f8fafc; }

        /* BUTTON */
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem;
            padding: 0.5rem 1rem; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 500;
            cursor: pointer; border: 1px solid transparent; text-decoration: none;
            font-family: inherit; line-height: 1.25; white-space: nowrap;
            transition: background 0.15s, border-color 0.15s, color 0.15s; }
        .btn-primary { background: var(--primary); color: white; border-color: var(--primary); }
        .btn-primary:hover { background: var(--primary-dark); border-color: var(--primary-dark); }
        .btn-sm { padding: 0.35rem 0.75rem; font-size: 0.8rem; }

        /* ============================================================
           LAPISAN RESPONSIF GLOBAL
           Standar: mobile-first, WCAG 2.1 §1.4.10 Reflow, §2.5.5 Target Size,
           §1.4.4 Resize Text, §2.5.8 Target Size (Minimum).
           Karena proyek tanpa build tool, aturan ini berada di dalam
           <style> shell sehingga otomatis berlaku pada seluruh halaman.
           ============================================================ */

        /* Cegah luapan horizontal pada media & teks panjang */
        img, svg, video, canvas, iframe { max-width: 100%; }
        img, video { height: auto; }
        .content { overflow-wrap: break-word; }
        .content p, .content td, .content th, .content li, .content span, .content div { overflow-wrap: break-word; }

        /* Wadah tabel bergulir mendatar (fallback ketika tabel tidak
           dibungkus secara eksplisit oleh view). Dibuat otomatis oleh
           JS bungkusTabelGeser() + MutationObserver. */
        .tabel-geser {
            overflow-x: auto; -webkit-overflow-scrolling: touch;
            max-width: 100%; min-width: 0; scrollbar-width: thin;
        }
        .tabel-geser > table { width: 100%; }

        /* View laporan memakai class Bootstrap "table-responsive" sebagai
           wadah bergulir mendatar, namun Bootstrap tidak dimuat pada
           proyek ini sehingga class itu belum punya gaya. Tanpa
           overflow-x, tabel lebar memaksa halaman ikut melebar.
           min-width:0 wajib agar wadah bisa menyusut di layar sempit. */
        .table-responsive {
            overflow-x: auto; -webkit-overflow-scrolling: touch;
            max-width: 100%; min-width: 0; scrollbar-width: thin; }
        .table-responsive > table { width: 100%; }
        /* Wadah bergulir lain yang dipakai view */
        .table-wrap, .overflow-x-auto, .scroll-x {
            overflow-x: auto; -webkit-overflow-scrolling: touch;
            max-width: 100%; min-width: 0; }

        /* Dekorasi MODE LAYAR KECIL */
        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; }
        }

        /* ---- Layar sedang & kecil: judul & kartu lebih rapat ---- */
        @media (max-width: 900px) {
            .content { padding: 1.25rem 1rem 2rem; }
            .stats-grid { margin-bottom: 1.5rem; }
            /* Judul kartu & kelompok tombol tidak lagi bergantung lebar */
            .card-header, .card-head, .modal-header, .modal-foot, .modal-footer,
            .page-header, .tbl-header, .section-head, [class$="-header"], [class$="-head"] {
                flex-wrap: wrap; row-gap: 0.5rem; }
            /* Cegah teks tombol panjang memaksa melebar */
            .btn { max-width: 100%; text-align: center; }
        }

        /* ---- Ponsel: satu kolom & kontrol sentuh ---- */
        @media (max-width: 640px) {
            html { -webkit-text-size-adjust: 100%; text-size-adjust: 100%; }
            .topbar { padding: 0.75rem 1rem; gap: 0.5rem; flex-wrap: nowrap; }
            /* Judul tetap sebaris dengan tombol menu & profil; tanpa ini judul
               terdorong ke baris sendiri dan topbar terlihat berantakan. */
            .topbar-title { font-size: 1rem; flex: 1 1 auto; order: 0; }
            .topbar-right { font-size: 0.8rem; gap: 0.5rem; flex: 0 0 auto; flex-wrap: nowrap; }
            .topbar-right > span { display: none; }
            .sidebar-toggle { margin-right: 0.25rem; }
            .card { padding: 1rem; }

            /* Kisi tetap (inline style) dipaksa menjadi satu kolom.
               Selector atribut menangkap semua varian penulisan
               (tanpa spasi, dengan spasi, nilai desimal, dsb.) —
               pola mengikuti pengajuan/form.blade.php:315. */
            div[style*="grid-template-columns:repeat(2"],
            div[style*="grid-template-columns: repeat(2"],
            div[style*="grid-template-columns:repeat(3"],
            div[style*="grid-template-columns: repeat(3"],
            div[style*="grid-template-columns:repeat(4"],
            div[style*="grid-template-columns: repeat(4"],
            div[style*="grid-template-columns:repeat(5"],
            div[style*="grid-template-columns: repeat(5"],
            div[style*="grid-template-columns:repeat(6"],
            div[style*="grid-template-columns: repeat(6"],
            div[style*="grid-template-columns: 1fr 1fr"],
            div[style*="grid-template-columns:1fr 1fr"],
            div[style*="grid-template-columns: 1fr 1fr 1fr"],
            div[style*="grid-template-columns:1fr 1fr 1fr"],
            div[style*="grid-template-columns: 2fr 1fr"],
            div[style*="grid-template-columns:2fr 1fr"],
            div[style*="grid-template-columns: 1fr 2fr"],
            div[style*="grid-template-columns:1fr 2fr"],
            div[style*="grid-template-columns: 2fr 1fr 1fr 1fr"],
            div[style*="grid-template-columns:2fr 1fr 1fr 1fr"],
            div[style*="grid-template-columns: 1fr 1.2fr"],
            div[style*="grid-template-columns:1fr 1.2fr"],
            div[style*="grid-template-columns: 1fr 1.35fr"],
            div[style*="grid-template-columns:1fr 1.35fr"],
            div[style*="grid-template-columns: 1fr 1.4fr"],
            div[style*="grid-template-columns:1fr 1.4fr"],
            div[style*="grid-template-columns: 1.3fr 1fr"],
            div[style*="grid-template-columns:1.3fr 1fr"],
            div[style*="grid-template-columns: 1fr auto auto"],
            div[style*="grid-template-columns:1fr auto auto"],
            div[style*="grid-template-columns: 1fr auto"],
            div[style*="grid-template-columns:1fr auto"] {
                grid-template-columns: 1fr !important; }

            /* Grid responsif: 2 kolom pada ponsel lebar, 1 kolom di bawahnya */
            @media (min-width: 480px) {
                div[style*="grid-template-columns:repeat(2"],
                div[style*="grid-template-columns: repeat(2"] { grid-template-columns: 1fr 1fr !important; }
                div[style*="grid-template-columns: 1fr 1fr"],
                div[style*="grid-template-columns:1fr 1fr"] { grid-template-columns: 1fr 1fr !important; }
            }

            /* Tabel tetap dapat digeser, judul kolom tidak terpotong */
            th, td { padding: 0.7rem 0.75rem; }
            .tabel-geser > table th { white-space: nowrap; }

            /* Formulir melebar penuh agar mudah dijangkau jari */
            input[type=text], input[type=number], input[type=email],
            input[type=password], input[type=date], input[type=month],
            input[type=search], input[type=tel], input[type=time],
            select, textarea {
                max-width: 100% !important; }
            /* Lebar/min-lebar piksel tetap pada bidang isian, filter, dan
               wadah ber-flex tidak boleh melebihi lebar layar. Ini pola
               paling umum di view (mis. style="width:240px"). */
            [style*="width:240px"], [style*="width: 240px"],
            [style*="width:260px"], [style*="width: 260px"],
            [style*="width:220px"], [style*="width: 220px"],
            [style*="width:200px"], [style*="width: 200px"],
            [style*="min-width:260px"], [style*="min-width: 260px"],
            [style*="min-width:240px"], [style*="min-width: 240px"],
            [style*="min-width:220px"], [style*="min-width: 220px"],
            [style*="min-width:200px"], [style*="min-width: 200px"],
            [style*="min-width:190px"], [style*="min-width: 190px"],
            [style*="min-width:180px"], [style*="min-width: 180px"],
            [style*="min-width:170px"], [style*="min-width: 170px"],
            [style*="min-width:160px"], [style*="min-width: 160px"],
            [style*="min-width:150px"], [style*="min-width: 150px"],
            [style*="min-width:140px"], [style*="min-width: 140px"],
            [style*="min-width:130px"], [style*="min-width: 130px"],
            [style*="min-width:120px"], [style*="min-width: 120px"],
            [style*="min-width:110px"], [style*="min-width: 110px"],
            [style*="min-width:100px"], [style*="min-width: 100px"] {
                min-width: 0 !important; max-width: 100% !important;
                box-sizing: border-box; }
            /* Bidang isian/select bertag */ 
            input[style*="width:"], select[style*="width:"], textarea[style*="width:"],
            input[style*="min-width:"], select[style*="min-width:"] {
                width: 100% !important; flex: 1 1 100%; }

            /* Modal menjadi panel bawah (bottom sheet) agar tidak terpotong
               pada ponsel: overlay diberi bantalan & dapat digulir,
               panel dalam dibatasi lebar layar. Berlaku untuk modal
               ber-inline-style maupun ber-class. */
            [style*="position:fixed"][style*="inset:0"],
            [style*="position:fixed"][style*="inset: 0"],
            [style*="position: fixed"][style*="inset:0"],
            [style*="position: fixed"][style*="inset: 0"] {
                padding: 0.75rem !important; overflow-y: auto;
                -webkit-overflow-scrolling: touch; }
            [style*="position:fixed"][style*="inset:0"] > div,
            [style*="position:fixed"][style*="inset: 0"] > div,
            [style*="position: fixed"][style*="inset:0"] > div,
            [style*="position: fixed"][style*="inset: 0"] > div {
                max-width: 100% !important; width: 100% !important;
                margin: auto !important; }
            [style*="position:fixed"][style*="inset:0"] > div > div,
            [style*="position: fixed"][style*="inset: 0"] > div > div {
                max-height: none !important; }
            .modal-overlay, .modal-backdrop {
                padding: 0.75rem !important; overflow-y: auto; }
            .modal-overlay > div, .modal-backdrop > div,
            .modal-box, .modal-panel, .modal-card, .modal-content, .modal {
                max-width: 100% !important; width: 100% !important;
                margin: auto !important; }
            .modal-body, .modal-scroll {
                max-height: 68vh; overflow-y: auto;
                -webkit-overflow-scrolling: touch; }
            .modal-foot, .modal-footer { flex-wrap: wrap; row-gap: 0.5rem; }

            /* Vimeo/canvas grafik tetap terukur */
            .chart-container, .chart-box { max-width: 100% !important; height: auto !important; }
            canvas { max-width: 100% !important; }
        }

        /* ---- Ponsel kecil: 320px ---- */
        @media (max-width: 420px) {
            .stats-grid { grid-template-columns: 1fr; }
            .topbar-right { gap: 0.5rem; }
            .card { padding: 0.85rem; border-radius: 0.75rem; }
            .btn { padding: 0.5rem 0.8rem; font-size: 0.8rem; }
            .sidebar { width: min(88vw, var(--sidebar-w)); }
        }

        /* ---- Target sentuh minimal 44×44 px (WCAG 2.5.5) ---- */
        @media (pointer: coarse) {
            .btn, .profile-btn, .sidebar-toggle { min-height: 44px; }
            .nav-item { min-height: 44px; }
            .avatar, .profile-avatar { width: 40px; height: 40px; }
            select { min-height: 44px; }
            label:has(> input[type=checkbox]) { padding-block: 0.5rem; }
        }

        /* ---- Orientation landscape ponsel: hemat tinggi layar ---- */
        @media (max-height: 480px) and (orientation: landscape) {
            .sidebar-nav { padding: 0.5rem 0; }
            .nav-item { padding: 0.5rem 1.5rem; min-height: 40px; }
        }

        /* ---- Cetak: hilangkan navigasi, pertahankan isi dokumen ---- */
        @media print {
            .sidebar, .topbar, #sidebarBackdrop, .sidebar-toggle { display: none !important; }
            .main-wrapper { margin-left: 0 !important; }
            .card { box-shadow: none; border: 1px solid #cbd5e1; break-inside: avoid; }
        }

        /* ---- iPhone bertakik: hormati safe-area (standar iOS 11+) ---- */
        @supports (padding: env(safe-area-inset-left)) {
            .topbar {
                padding-left: max(2rem, env(safe-area-inset-left));
                padding-right: max(2rem, env(safe-area-inset-right)); }
            .content {
                padding-left: max(2rem, env(safe-area-inset-left));
                padding-right: max(2rem, env(safe-area-inset-right));
                padding-bottom: max(2rem, env(safe-area-inset-bottom)); }
            @media (max-width: 640px) {
                .topbar { padding-left: max(1rem, env(safe-area-inset-left));
                    padding-right: max(1rem, env(safe-area-inset-right)); }
                .content { padding-left: max(1rem, env(safe-area-inset-left));
                    padding-right: max(1rem, env(safe-area-inset-right));
                    padding-bottom: max(2rem, env(safe-area-inset-bottom)); }
            }
        }
    /* ============================================================
           UTILITAS KOMPATIBILITAS
           Halaman laporan & sebagian view masih memakai penamaan
           kelas gaya-gaya (row, col-md-*, d-flex, gap-*, text-end,
           mb-*, form-control, table-sm, alert, ...), padahal proyek
           ini tidak memuat pustaka CSS pihak ketiga. Tanpa definisi di
           bawah, kolom tidak pernah berdampingan, header tidak pernah
           sejajar, dan jarak antar bagian hilang.
           Blok ini hanya mengisinya; setiap view tetap bebas menimpa
           lewat bagian "styles" yang dimuat setelah blok ini.
           (Jangan menulis nama direktif Blade seperti yield di dalam
           komentar CSS ini — Blade akan tetap mengeksekusinya.)
           ============================================================ */

        /* --- Wadah & sistem kisi --- */
        .container-fluid { width: 100%; max-width: 100%; padding-inline: 0; margin-inline: auto; }
        .container { width: 100%; max-width: 1200px; margin-inline: auto; }
        .row { display: flex; flex-wrap: wrap; margin-inline: -0.5rem; min-width: 0; }
        .row > [class*="col-"], .row > .col { flex: 1 0 100%; min-width: 0; padding-inline: 0.5rem; }
        .row > .col-6 { flex: 0 0 50%; max-width: 50%; }
        .row > .col-auto { flex: 0 0 auto; max-width: 100%; width: auto; }
        @media (min-width: 768px) {
            .row > .col-md-2 { flex: 0 0 16.6667%; max-width: 16.6667%; }
            .row > .col-md-3 { flex: 0 0 25%;     max-width: 25%; }
            .row > .col-md-4 { flex: 0 0 33.3333%; max-width: 33.3333%; }
            .row > .col-md-5 { flex: 0 0 41.6667%; max-width: 41.6667%; }
            .row > .col-md-6 { flex: 0 0 50%;     max-width: 50%; }
            .row > .col-lg-6 { flex: 0 0 50%;     max-width: 50%; }
        }

        /* --- Utilitas flex & jarak --- */
        .d-flex { display: flex; }
        .d-inline-flex { display: inline-flex; }
        .d-block { display: block; }
        .d-none { display: none; }
        .flex { display: flex; }
        .flex-wrap { flex-wrap: wrap; }
        .flex-column { flex-direction: column; }
        .align-items-start { align-items: flex-start; }
        .align-items-center { align-items: center; }
        .align-items-end { align-items: flex-end; }
        .align-items-stretch { align-items: stretch; }
        .justify-content-between { justify-content: space-between; }
        .justify-content-end { justify-content: flex-end; }
        .justify-content-center { justify-content: center; }
        .gap-1 { gap: 0.25rem; } .gap-2 { gap: 0.5rem; }
        .gap-3 { gap: 0.75rem; } .gap-4 { gap: 1rem; }

        /* --- Utilitas jarak (hanya varian yang benar-benar dipakai) --- */
        .m-0 { margin: 0; }
        .mb-0 { margin-bottom: 0; }   .mb-1 { margin-bottom: 0.25rem; }
        .mb-2 { margin-bottom: 0.5rem; } .mb-3 { margin-bottom: 1rem; }
        .mb-4 { margin-bottom: 1.5rem; } .mb-5 { margin-bottom: 3rem; }
        .mt-0 { margin-top: 0; }      .mt-1 { margin-top: 0.25rem; }
        .mt-2 { margin-top: 0.5rem; } .mt-3 { margin-top: 1rem; }
        .mt-4 { margin-top: 1.5rem; } .mt-5 { margin-top: 3rem; }
        .my-3 { margin-block: 1rem; }
        .p-0 { padding: 0; }
        .p-2 { padding: 0.5rem; } .p-3 { padding: 1rem; } .p-4 { padding: 1.5rem; }
        .px-2 { padding-inline: 0.5rem; } .px-3 { padding-inline: 1rem; }
        .px-4 { padding-inline: 1.5rem; }
        .py-1 { padding-block: 0.25rem; } .py-2 { padding-block: 0.5rem; }
        .py-3 { padding-block: 1rem; }    .py-4 { padding-block: 1.5rem; }
        .ps-3 { padding-inline-start: 1rem; } .ps-4 { padding-inline-start: 1.5rem; }
        .pe-3 { padding-inline-end: 1rem; }
        .ms-1 { margin-inline-start: 0.25rem; } .ms-2 { margin-inline-start: 0.5rem; }
        .ms-3 { margin-inline-start: 1rem; }     .ms-auto { margin-inline-start: auto; }
        .me-1 { margin-inline-end: 0.25rem; }    .me-2 { margin-inline-end: 0.5rem; }
        .me-3 { margin-inline-end: 1rem; }        .me-auto { margin-inline-end: auto; }
        .mx-auto { margin-inline: auto; }

        /* Jarak antar kolom pada .row (g-2/g-3/g-4).
           PENTING: gutter mendatar sudah disediakan oleh padding kolom
           (0.5rem kiri+kanan) di atas. Bila .row juga diberi `gap`, lebar
           dua kolom 50% ditambah gap melebihi 100% sehingga kolom tidak
           pernah berdampingan dan selalu turun ke baris baru.
           Karena itu untuk .row hanya jarak vertikal yang diterapkan. */
        .g-2 { gap: 0.5rem; } .g-3 { gap: 0.75rem; } .g-4 { gap: 1.5rem; }
        .row.g-1, .row.g-2, .row.g-3, .row.g-4 { column-gap: 0; }
        .row.g-1 { row-gap: 0.25rem; } .row.g-2 { row-gap: 0.5rem; }
        .row.g-3 { row-gap: 0.75rem; } .row.g-4 { row-gap: 1.5rem; }
        .h-100 { height: 100%; }

        /* --- Utilitas teks --- */
        .text-start, .text-left { text-align: left; }
        .text-end, .text-right { text-align: right; }
        .text-center { text-align: center; }
        .text-nowrap { white-space: nowrap; }
        .text-uppercase { text-transform: uppercase; }
        .text-muted { color: var(--muted, #64748b); }
        .text-success { color: #16a34a; } .text-danger { color: #dc2626; }
        .text-warning { color: #d97706; } .text-info { color: #0284c7; }
        .text-primary { color: var(--primary, #0f766e); }
        .text-secondary { color: #475569; } .text-dark { color: #1e293b; }
        .text-light { color: #f8fafc; } .text-white { color: #fff; }
        .text-xs { font-size: 0.75rem; }
        .text-base { font-size: 1rem; }
        .small, .fs-6 { font-size: 0.875rem; }
        .fs-1 { font-size: 2.5rem; } .fs-2 { font-size: 2rem; }
        .fs-3 { font-size: 1.75rem; } .fs-4 { font-size: 1.5rem; }
        .fs-5 { font-size: 1.25rem; } .fs-7 { font-size: 0.75rem; }
        .fw-bold, .font-bold { font-weight: 700; }
        .fw-semibold, .font-semibold { font-weight: 600; }
        .fw-normal { font-weight: 400; }
        .fw-light { font-weight: 300; }
        .fst-italic { font-style: italic; }
        .text-decoration-none { text-decoration: none; }
        .lh-1 { line-height: 1; } .lh-sm { line-height: 1.25; }

        /* --- Kepala halaman --- */
        .page-title { margin: 0 0 0.25rem; font-size: 1.35rem; font-weight: 700;
            color: #0f172a; line-height: 1.3; overflow-wrap: break-word; }
        .page-subtitle { margin: 0 0 1rem; font-size: 0.875rem; color: #64748b;
            line-height: 1.5; overflow-wrap: break-word; }
        @media (max-width: 480px) {
            .page-title { font-size: 1.15rem; }
            .page-subtitle { font-size: 0.82rem; margin-bottom: 0.75rem; }
        }

        /* --- Komponen yang belum terdefinisi --- */
        .btn-secondary { background: #64748b; color: #fff; border-color: #64748b; }
        .btn-secondary:hover { background: #475569; border-color: #475569; }
        .btn-success { background: #16a34a; color: #fff; border-color: #16a34a; }
        .btn-success:hover { background: #15803d; border-color: #15803d; }
        .btn-danger { background: #dc2626; color: #fff; border-color: #dc2626; }
        .btn-danger:hover { background: #b91c1c; border-color: #b91c1c; }
        .btn-xs { padding: 0.15rem 0.4rem; font-size: 0.7rem; }

        /* Tombol bertepi (outline) — dipakai laporan & halaman SHU */
        .btn-outline { background: transparent; border: 1px solid #cbd5e1; color: #475569; }
        .btn-outline:hover { background: #f1f5f9; border-color: #94a3b8; }
        .btn-outline-primary, .btn-outline-success, .btn-outline-danger,
        .btn-outline-warning, .btn-outline-secondary, .btn-outline-info {
            background: transparent; border: 1px solid currentColor; }
        .btn-outline-primary   { color: var(--primary); }
        .btn-outline-success   { color: #16a34a; }
        .btn-outline-danger    { color: #dc2626; }
        .btn-outline-warning   { color: #b45309; }
        .btn-outline-secondary { color: #475569; }
        .btn-outline-info      { color: #0369a1; }
        .btn-outline-primary:hover   { background: var(--primary); color: #fff; }
        .btn-outline-success:hover   { background: #16a34a; color: #fff; }
        .btn-outline-danger:hover    { background: #dc2626; color: #fff; }
        .btn-outline-warning:hover   { background: #f59e0b; color: #fff; }
        .btn-outline-secondary:hover { background: #475569; color: #fff; }
        .btn-outline-info:hover      { background: #0369a1; color: #fff; }
        .btn.active { box-shadow: inset 0 0 0 2px currentColor; font-weight: 600; }
        .btn:disabled, .btn.disabled { opacity: 0.55; pointer-events: none; }
        .btn-group { display: inline-flex; flex-wrap: wrap; gap: 0; max-width: 100%; }
        .btn-group > .btn { border-radius: 0; }
        .btn-group > .btn:first-child { border-start-start-radius: 0.5rem;
            border-end-start-radius: 0.5rem; }
        .btn-group > .btn:last-child { border-start-end-radius: 0.5rem;
            border-end-end-radius: 0.5rem; }
        .btn-close { background: none; border: 0; font-size: 1.25rem;
            line-height: 1; cursor: pointer; padding: 0.25rem; min-width: 44px;
            min-height: 44px; color: inherit; }
        .card-body { padding: 1.25rem; }
        .card-footer { padding: 0.85rem 1.25rem; border-top: 1px solid #f1f5f9;
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 0.5rem; }
        .shadow-sm { box-shadow: 0 1px 2px rgba(0,0,0,0.06); }
        .shadow-xl { box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .border { border: 1px solid #e2e8f0; }
        .border-0 { border: 0 !important; }
        .border-2 { border-width: 2px; } .border-4 { border-width: 4px; }
        .border-start { border-inline-start: 1px solid #e2e8f0; }
        .border-info    { border-color: #bae6fd; }
        .border-success { border-color: #bbf7d0; }
        .border-warning { border-color: #fde68a; }
        .border-danger  { border-color: #fecaca; }
        .border-top { border-top: 1px solid #e2e8f0; }
        .border-bottom { border-bottom: 1px solid #e2e8f0; }
        .rounded-0 { border-radius: 0; }
        .opacity-75 { opacity: 0.75; }
        .w-100 { width: 100%; }
        .rounded-full { border-radius: 9999px; }
        .rounded-pill { border-radius: 9999px; }
        .bg-white { background: #fff; } .bg-light { background: #f8fafc; }
        .bg-success { background: #16a34a; } .bg-danger { background: #dc2626; }
        .bg-warning { background: #f59e0b; } .bg-secondary { background: #64748b; }
        .bg-primary { background: var(--primary, #0f766e); }
        .bg-indigo { background: #4f46e5; }
        .bg-opacity-10 { background-color: rgba(15, 118, 110, 0.08); }
        .bg-info-subtle    { background: #f0f9ff; }
        .bg-success-subtle { background: #f0fdf4; }
        .bg-warning-subtle { background: #fffbeb; }
        .bg-danger-subtle  { background: #fef2f2; }
        .text-indigo { color: #4f46e5; }
        .text-amber-600 { color: #d97706; }
        .text-slate-400 { color: #94a3b8; }
        .italic { font-style: italic; }
        .font-medium { font-weight: 500; }
        .overflow-y-auto { overflow-y: auto; -webkit-overflow-scrolling: touch; }
        .fade { transition: opacity 0.15s ease; }
        .align-middle { vertical-align: middle; }

        .form-group { margin-bottom: 1rem; min-width: 0; }
        .form-label { display: block; margin-bottom: 0.35rem; font-size: 0.85rem;
            font-weight: 500; color: #475569; }
        .form-control { display: block; width: 100%; max-width: 100%;
            box-sizing: border-box; padding: 0.5rem 0.75rem; font-size: 0.875rem;
            border: 1px solid #cbd5e1; border-radius: 0.5rem; background: #fff;
            color: #1e293b; }
        .form-select { display: block; width: 100%; max-width: 100%;
            box-sizing: border-box; padding: 0.5rem 2rem 0.5rem 0.75rem;
            font-size: 0.875rem; line-height: 1.4;
            border: 1px solid #cbd5e1; border-radius: 0.5rem; background: #fff;
            color: #1e293b; appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><path fill='%2364748b' d='M8 11L3 6h10z'/></svg>");
            background-repeat: no-repeat; background-position: right 0.6rem center;
            background-size: 0.8rem 0.8rem; }
        .form-control-sm, .form-select-sm { padding: 0.3rem 0.6rem;
            font-size: 0.8rem; border-radius: 0.4rem; }
        .form-select-sm { padding-right: 1.9rem; }
        .form-control:focus, .form-select:focus { outline: 2px solid var(--primary, #0f766e);
            outline-offset: 1px; border-color: var(--primary, #0f766e); }

        /* --- Ikon garis (substitusi Bootstrap Icons yang tidak dimuat) ---
           View masih menandai ikon dengan <i class="bi bi-nama">. Tanpa
           stylesheet ikon, elemen <i> itu kosong tak terlihat. Blok ini
           menggantinya dengan glif teks seukuran font, memakai currentColor
           sehingga otomatis mengikuti warna teks induknya. */
        .bi { display: inline-block; width: 1em; height: 1em; line-height: 1;
            font-style: normal; vertical-align: -0.125em; text-align: center;
            flex: 0 0 auto; }
        .bi::before { font-weight: 700; }
        .bi-check2-all::before { content: "\2713\2713"; font-size: .8em; }
        .bi-play-fill::before { content: "\25B6"; font-size: .8em; }
        .bi-arrow-left::before { content: "\2190"; }
        .bi-info-circle::before { content: "\2139"; }
        .bi-exclamation-triangle-fill::before { content: "\26A0"; }
        .bi-shield-exclamation::before { content: "\26E8"; }
        .bi-people::before { content: "\263A"; }
        .bi-inbox::before { content: "\2610"; }
        .bi-journal-text::before { content: "\2637"; }
        .bi-unlock::before { content: "\2327"; }
        .bi-magic::before { content: "\2728"; }
        .bi-file-earmark-spreadsheet::before { content: "\1F4C4"; font-size: .9em; }
        .bi-dash-lg, .bi-three-dots::before { content: "\22EF"; }
        @media (pointer: coarse) { .bi { min-width: 1em; min-height: 1em; } }

        .table-sm { font-size: 0.8rem; }
        .table-sm th, .table-sm td { padding: 0.5rem 0.65rem; }
        .table-light thead th { background: #f8fafc; }
        .table-hover tbody tr:hover td { background: #f1f5f9; }
        .table-primary tbody tr { background: #eff6ff; }

        .alert { padding: 0.85rem 1.1rem; border-radius: 0.75rem;
            margin-bottom: 1.25rem; font-size: 0.875rem; border: 1px solid transparent; }
        .alert-success { background: #f0fdf4; color: #166534; border-color: #bbf7d0; }
        .alert-danger  { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
        .alert-warning { background: #fffbeb; color: #92400e; border-color: #fde68a; }
        .alert-info    { background: #f0f9ff; color: #075985; border-color: #bae6fd; }
        .alert-secondary, .alert-light { background: #f8fafc; color: #475569; border-color: #e2e8f0; }
        .alert-error   { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
        .alert-dismissible { display: flex; align-items: flex-start; gap: 0.75rem; }

        /* Pelengkap kecil: badge bulat, pesan validasi, tabel bergaris */
        .badge-warning { background: #fef3c7; color: #92400e; border-color: #fde68a; }
        .invalid-feedback { display: block; margin-top: 0.3rem;
            font-size: 0.78rem; color: #dc2626; }
        /* Penanda galat pada kendali formulir (dipakai direktif error Blade) */
        .is-invalid, .form-control.is-invalid, .form-input.is-invalid,
        .form-select.is-invalid { border-color: #dc2626 !important;
            background-color: #fff5f5; }
        .is-invalid:focus { outline-color: #dc2626; }
        .table-bordered th, .table-bordered td { border: 1px solid #e2e8f0; }
        .input-group-sm .form-control, .input-group-sm .form-select,
        .input-group-sm .btn { padding: 0.3rem 0.6rem; font-size: 0.8rem; }
        .py-5 { padding-block: 2rem; }
        .px-2\.5 { padding-inline: 0.75rem; }
        .d-inline { display: inline; }
    </style>
    <?php echo $__env->yieldContent('styles'); ?>
</head>
<body data-role="<?php echo e(auth()->user()->role); ?>">
    
    <a href="#konten-utama" class="skip-link">Lewati ke konten utama</a>

    <?php echo $__env->make('layouts.sidebar', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    
    <div id="sidebarBackdrop" hidden onclick="toggleSidebar()"></div>
    <div class="main-wrapper">
        <div class="topbar">
            
            <button type="button" class="sidebar-toggle" id="sidebarToggle"
                    aria-controls="sidebarUtama" aria-expanded="false"
                    onclick="toggleSidebar()">
                <span aria-hidden="true">&#9776;</span>
                <span class="sr-only">Buka menu navigasi</span>
            </button>
            <div class="topbar-title"><?php echo $__env->yieldContent('page-title', 'Dashboard'); ?></div>
            <div class="topbar-right">
                <span><?php echo e(now()->format('d/m/Y')); ?></span>

                <!-- Profile Dropdown -->
                <div class="profile-wrap" id="profileWrap">
                    <button type="button" class="profile-btn" id="profileBtn"
                            aria-haspopup="true" aria-expanded="false" aria-controls="profileMenu"
                            onclick="toggleProfile()">
                        <span class="profile-avatar" aria-hidden="true">
                            <?php echo e(strtoupper(substr(auth()->user()->name, 0, 1))); ?>

                        </span>
                        <span>
                            <span class="profile-name"><?php echo e(auth()->user()->name); ?></span>
                            <span class="profile-role">
                                <?php echo e(\App\Support\HierarkiRole::label(auth()->user()->role)); ?>

                            </span>
                        </span>
                        <span class="profile-chevron" aria-hidden="true">&#9660;</span>
                    </button>

                    <div class="profile-dropdown" id="profileMenu">
                        <!-- Header info -->
                        <div class="profile-header">
                            <div class="ph-name"><?php echo e(auth()->user()->name); ?></div>
                            <div class="ph-email"><?php echo e(auth()->user()->email); ?></div>
                            <span class="ph-role">
                                <?php echo e(\App\Support\HierarkiRole::label(auth()->user()->role)); ?>

                            </span>
                        </div>

                        <!-- Menu items -->
                        <div class="profile-menu">
                            <?php
                                $userRole = auth()->user()->role;
                                $ruteDasbor = \App\Support\HierarkiRole::dashboardRoute($userRole)
                                    ?? 'dashboard.nasabah';
                            ?>
                            <a href="<?php echo e(route($ruteDasbor)); ?>">
                                <span aria-hidden="true">&#127968;</span> Dashboard
                            </a>

                            <div class="profile-divider"></div>

                            <form method="POST" action="<?php echo e(route('logout')); ?>" style="margin:0;padding:0;">
                                <?php echo csrf_field(); ?>
                                <button type="submit" class="menu-danger">
                                    <span aria-hidden="true">&#128682;</span> Keluar
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <main class="content" id="konten-utama" tabindex="-1">
            <?php echo $__env->yieldContent('content'); ?>
        </main>
    </div>
    <?php echo $__env->yieldContent('scripts'); ?>
    <?php echo $__env->yieldPushContent('scripts'); ?>
<?php echo $__env->make('partials.denyut', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<script>
    /* Tombol profil: jaga aria-expanded tetap sinkron dengan kelas .open */
    function toggleProfile() {
        const wrap = document.getElementById('profileWrap');
        const btn  = document.getElementById('profileBtn');
        if (! wrap) return;
        const terbuka = wrap.classList.toggle('open');
        if (btn) btn.setAttribute('aria-expanded', terbuka ? 'true' : 'false');
    }

    document.addEventListener('click', function(e) {
        const wrap = document.getElementById('profileWrap');
        if (wrap && !wrap.contains(e.target) && wrap.classList.contains('open')) {
            wrap.classList.remove('open');
            const btn = document.getElementById('profileBtn');
            if (btn) btn.setAttribute('aria-expanded', 'false');
        }
    });

    /* Tutup dropdown profil dengan tombol Escape (WCAG 2.1.2 Tanpa Jebakan Keyboard) */
    document.addEventListener('keydown', function(e) {
        if (e.key !== 'Escape') return;
        const wrap = document.getElementById('profileWrap');
        if (wrap && wrap.classList.contains('open')) {
            wrap.classList.remove('open');
            const btn = document.getElementById('profileBtn');
            if (btn) btn.setAttribute('aria-expanded', 'false');
            if (btn) btn.focus();
        }
    });

    /* Sidebar layar sempit (WCAG 1.4.10 Reflow & 2.1.2) */
    function toggleSidebar() {
        const sb  = document.getElementById('sidebarUtama');
        const btn = document.getElementById('sidebarToggle');
        if (! sb) return;
        const terbuka = sb.classList.toggle('terbuka');
        if (btn) btn.setAttribute('aria-expanded', terbuka ? 'true' : 'false');

        const backdrop = document.getElementById('sidebarBackdrop');
        if (backdrop) backdrop.hidden = ! terbuka;
    }

    document.addEventListener('keydown', function(e) {
        if (e.key !== 'Escape') return;
        const sb  = document.getElementById('sidebarUtama');
        const btn = document.getElementById('sidebarToggle');
        if (sb && sb.classList.contains('terbuka')) {
            sb.classList.remove('terbuka');
            if (btn) { btn.setAttribute('aria-expanded', 'false'); btn.focus(); }
            const backdrop = document.getElementById('sidebarBackdrop');
            if (backdrop) backdrop.hidden = true;
        }
    });

    /* ==============================================================
       Tabel responsif otomatis (WCAG 1.4.10 Reflow)
       Setiap <table> yang belum berada di dalam wadah bergulir
       dibungkus <div class="tabel-geser"> agar tidak memotong kolom
       pada layar sempit. Dijalankan ulang oleh MutationObserver
       karena polling "denyut" menyuntik HTML baru ke halaman.
       ============================================================== */
    (function bungkusTabelGeser() {
        function punyaGulirSendiri(n) {
            if (!n || !n.getAttribute) return false;
            const gaya = n.getAttribute('style') || '';
            return /overflow(-x)?\s*:\s*(auto|scroll)/.test(gaya);
        }
        function perluDibungkus(t) {
            let n = t.parentElement;
            while (n && n !== document.body) {
                if (n.classList && (n.classList.contains('tabel-geser')
                    || n.classList.contains('table-responsive')
                    || n.classList.contains('stepper-wrap')
                    || n.hasAttribute('data-tanpa-geser'))) return false;
                /* View yang sudah menyediakan gulir horizontal sendiri */
                if (punyaGulirSendiri(n)) return false;
                n = n.parentElement;
            }
            return true;
        }
        function proses() {
            document.querySelectorAll('table').forEach(function(t) {
                if (!perluDibungkus(t)) return;
                /* Tanpa overflow pada induk, div pembungkus tidak akan
                   menggulir (mis. di dalam modal ber-overflow-y) */
                if (t.parentElement && t.parentElement.clientWidth === 0) return;
                const w = document.createElement('div');
                w.className = 'tabel-geser';
                w.setAttribute('role', 'region');
                w.setAttribute('tabindex', '0');
                w.setAttribute('aria-label', 'Tabel data, dapat digeser mendatar');
                t.parentNode.insertBefore(w, t);
                w.appendChild(t);
            });
        }
        proses();
        if (typeof MutationObserver !== 'undefined') {
            let tunda = null;
            new MutationObserver(function() {
                clearTimeout(tunda);
                tunda = setTimeout(proses, 120);
            }).observe(document.body, { childList: true, subtree: true });
        }
    })();
</script>
</body>
</html>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\layouts\app.blade.php ENDPATH**/ ?>