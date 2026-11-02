<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($kode ?? 500); ?> — <?php echo e($judul ?? 'Terjadi Kesalahan'); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Figtree', 'Segoe UI', sans-serif;
            background: #f1f5f9;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            color: #1e293b;
        }
        .kartu {
            background: #ffffff;
            border-radius: 1rem;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
            max-width: 640px;
            width: 100%;
            padding: 2.5rem 2rem;
            text-align: center;
            border-top: 6px solid #059669;
        }
        .kode {
            font-size: 4rem;
            font-weight: 800;
            color: #059669;
            line-height: 1;
            letter-spacing: -0.02em;
        }
        h1 {
            font-size: 1.35rem;
            font-weight: 700;
            color: #0f172a;
            margin: 1rem 0 0.5rem;
        }
        p.pesan {
            color: #64748b;
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }
        .catatan {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            font-size: 0.8rem;
            color: #475569;
            margin-bottom: 1.75rem;
            word-break: break-word;
        }
        .catatan strong { color: #059669; font-family: monospace; }
        .aksi { display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap; }
        .btn {
            display: inline-block;
            padding: 0.65rem 1.4rem;
            background: #059669;
            color: #ffffff;
            border-radius: 0.5rem;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.875rem;
            border: none;
            cursor: pointer;
        }
        .btn:hover { background: #047857; }
        .btn.secondary { background: #64748b; }
        .btn.secondary:hover { background: #475569; }

        /* --- Responsif --- */
        @media (max-width: 640px) {
            body { padding: 1rem; }
            .kartu { padding: 1.75rem 1.25rem; }
            .kode { font-size: 3rem; }
            h1 { font-size: 1.15rem; }
            .aksi { flex-direction: column; align-items: stretch; }
            .btn { width: 100%; box-sizing: border-box;
                display: flex; align-items: center; justify-content: center;
                min-height: 44px; }
        }
    </style>
</head>
<body>
    <div class="kartu">
        <div class="kode"><?php echo e($kode ?? 500); ?></div>
        <h1><?php echo e($judul ?? 'Terjadi Kesalahan'); ?></h1>
        <p class="pesan"><?php echo e($pesan ?? 'Terjadi kesalahan pada sistem. Silakan coba beberapa saat lagi.'); ?></p>

        <?php if(isset($referensi)): ?>
            <div class="catatan">
                Sampaikan kode referensi berikut kepada tim teknis bila masalah berulang:<br>
                <strong><?php echo e($referensi); ?></strong>
            </div>
        <?php endif; ?>

        <div class="aksi">
            <a href="<?php echo e(url('/')); ?>" class="btn">Ke Beranda</a>
            <a href="javascript:history.back()" class="btn secondary">Kembali</a>
        </div>
    </div>
</body>
</html>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\errors\umum.blade.php ENDPATH**/ ?>