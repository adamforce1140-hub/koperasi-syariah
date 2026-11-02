<?php $__env->startSection('page-title', "Buka Rekening Deposito"); ?>

<?php $__env->startSection('title', 'Buka Rekening Deposito'); ?>

<?php $__env->startSection('sidebar'); ?>
<div class="nav-section">Kelola Anggota</div>
<a href="<?php echo e(route('teller.anggota.index')); ?>" class="nav-item <?php echo e(request()->routeIs('teller.anggota.*') ? 'active' : ''); ?>">
    <span class="icon">👥</span> Data & Daftar Anggota
</a>
<div class="nav-section">Teller</div>
<a href="<?php echo e(route('dashboard.teller')); ?>" class="nav-item <?php echo e(request()->routeIs('dashboard.teller') ? 'active' : ''); ?>">
    <span class="icon">🏠</span> Dashboard
</a>
<a href="<?php echo e(route('teller.setor')); ?>" class="nav-item <?php echo e(request()->routeIs('teller.setor') ? 'active' : ''); ?>">
    <span class="icon">💰</span> Setor Tunai
</a>
<a href="<?php echo e(route('teller.tarik')); ?>" class="nav-item <?php echo e(request()->routeIs('teller.tarik') ? 'active' : ''); ?>">
    <span class="icon">💸</span> Tarik Tunai
</a>
<a href="<?php echo e(route('teller.angsuran')); ?>" class="nav-item <?php echo e(request()->routeIs('teller.angsuran') ? 'active' : ''); ?>">
    <span class="icon">📊</span> Bayar Angsuran
</a>
<a href="<?php echo e(route('teller.deposito')); ?>" class="nav-item active">
    <span class="icon">🏦</span> Buka Deposito
</a>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<div style="max-width:640px;margin:0 auto;">

    
    <div style="background:linear-gradient(135deg,#065f46,#059669);color:white;border-radius:1rem;padding:1.5rem 2rem;margin-bottom:1.5rem;">
        <div style="font-size:1.25rem;font-weight:700;">🏦 Buka Rekening Simpanan Berjangka</div>
        <div style="font-size:.85rem;opacity:.85;margin-top:.3rem;">Deposito Mudharabah — PSAK 105 | Jurnal: D: Kas Teller (1-1001) / K: Simpanan Berjangka (2-1003)</div>
    </div>

    
    <?php if(session('success')): ?>
    <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
        ✅ <?php echo e(session('success')); ?>

        
        <?php
            $bolehBukuBesar = \App\Support\LaporanKeuanganPermission::boleh(
                auth()->user()->role,
                \App\Support\LaporanKeuanganPermission::MODUL_BUKU_BESAR,
                \App\Support\LaporanKeuanganPermission::LIHAT
            );
            $bolehNeraca = \App\Support\LaporanKeuanganPermission::boleh(
                auth()->user()->role,
                \App\Support\LaporanKeuanganPermission::MODUL_NERACA,
                \App\Support\LaporanKeuanganPermission::LIHAT
            );
        ?>
        <?php if($bolehBukuBesar || $bolehNeraca): ?>
        <div style="margin-top:.4rem;font-size:.82rem;">
            <?php if($bolehBukuBesar): ?>
            <a href="<?php echo e(route('laporan.buku-besar', ['kode_akun' => '2-1003'])); ?>" style="color:#065f46;font-weight:600;">
                → Lihat Buku Besar Deposito (2-1003)
            </a>
            <?php endif; ?>
            <?php if($bolehBukuBesar && $bolehNeraca): ?>
                &nbsp;|&nbsp;
            <?php endif; ?>
            <?php if($bolehNeraca): ?>
            <a href="<?php echo e(route('laporan.neraca')); ?>" style="color:#065f46;font-weight:600;">
                → Lihat Laporan Neraca
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if($errors->any()): ?>
    <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
        <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $err): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <div>• <?php echo e($err); ?></div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <span class="card-title">Form Pembukaan Deposito Mudharabah</span>
        </div>

        <form method="POST" action="<?php echo e(route('teller.deposito.store')); ?>" id="formDeposito">
            <?php echo csrf_field(); ?>

            
            <div style="margin-bottom:1.1rem;">
                <label style="display:block;font-weight:600;margin-bottom:.4rem;color:#374151;">
                    Anggota <span style="color:#ef4444;">*</span>
                </label>
                <select name="anggota_id" id="anggota_id"
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;" required>
                    <option value="">-- Pilih Anggota --</option>
                    <?php $__currentLoopData = $anggota; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $a): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($a->id); ?>" <?php echo e(old('anggota_id') == $a->id ? 'selected' : ''); ?>>
                        <?php echo e($a->nama_lengkap); ?> (<?php echo e($a->no_anggota); ?>)
                    </option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
                <?php $__errorArgs = ['anggota_id'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div style="color:#ef4444;font-size:.82rem;margin-top:.25rem;"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
            </div>

            
            <div style="margin-bottom:1.1rem;">
                <label style="display:block;font-weight:600;margin-bottom:.4rem;color:#374151;">Jenis Rekening</label>
                <input type="text" value="Simpanan Berjangka / Deposito" readonly
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;background:#f3f4f6;color:#6b7280;box-sizing:border-box;">
            </div>

            
            <div style="margin-bottom:1.1rem;">
                <label style="display:block;font-weight:600;margin-bottom:.4rem;color:#374151;">Akad Syariah</label>
                <input type="text" value="Mudharabah (Otomatis — PSAK 105)" readonly
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;background:#f3f4f6;color:#059669;font-weight:600;box-sizing:border-box;">
                <div style="font-size:.78rem;color:#6b7280;margin-top:.25rem;">
                    Akad deposito mudharabah ditetapkan secara otomatis sesuai PSAK 105.
                </div>
            </div>

            
            <?php
                $minPembukaan = (float) ($batasPembukaan['minimum'] ?? 10000);
                $maksPembukaan = (float) ($batasPembukaan['maksimum'] ?? 50000000);
            ?>
            <div style="margin-bottom:1.1rem;">
                <label style="display:block;font-weight:600;margin-bottom:.4rem;color:#374151;">
                    Nominal Setoran Awal (Rp) <span style="color:#ef4444;">*</span>
                </label>
                <input type="number" name="nominal" id="nominal"
                    min="<?php echo e($minPembukaan); ?>" max="<?php echo e($maksPembukaan); ?>" step="1000"
                    data-min="<?php echo e($minPembukaan); ?>" data-max="<?php echo e($maksPembukaan); ?>"
                    data-min-kebijakan="<?php echo e($minPembukaan); ?>"
                    value="<?php echo e(old('nominal')); ?>"
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;box-sizing:border-box;"
                    placeholder="Contoh: <?php echo e((int) $minPembukaan); ?>" required
                    oninput="updatePreview()">
                <div style="font-size:.78rem;color:#6b7280;margin-top:.25rem;">
                    Nominal pembukaan rekening yang diizinkan:
                    Rp <?php echo e(number_format($minPembukaan, 0, ',', '.')); ?> – Rp <?php echo e(number_format($maksPembukaan, 0, ',', '.')); ?>

                    (batas dapat diubah pengurus melalui Parameter Kebijakan Koperasi).
                </div>
                <div id="nominal-preview" style="font-size:.82rem;color:#059669;margin-top:.25rem;font-weight:600;"></div>
                <div id="nominal-warn" style="color:#ef4444;font-size:.82rem;margin-top:.25rem;display:none;"></div>
                <?php $__errorArgs = ['nominal'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div style="color:#ef4444;font-size:.82rem;margin-top:.25rem;"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
            </div>

            
            <div style="margin-bottom:1.1rem;">
                <label style="display:block;font-weight:600;margin-bottom:.4rem;color:#374151;">
                    Produk / Tenor Deposito <span style="color:#ef4444;">*</span>
                </label>
                <?php if($produkDeposito->isEmpty()): ?>
                    <div style="background:#fef3c7;border:1px solid #fcd34d;color:#92400e;padding:.75rem 1rem;border-radius:8px;font-size:.85rem;">
                        Belum ada produk deposito aktif. Pengurus harus mengaktifkan produk
                        deposito (tenor &amp; nisbah) terlebih dahulu.
                    </div>
                <?php else: ?>
                <select name="deposito_product_id" id="deposito_product_id"
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;" required
                    onchange="updateProduk()">
                    <option value="">-- Pilih Produk --</option>
                    <?php $__currentLoopData = $produkDeposito; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($p->id); ?>"
                        data-tenor="<?php echo e((int) $p->tenor_months); ?>"
                        data-nisbah="<?php echo e((float) $p->nisbah_anggota); ?>"
                        data-nisbah-koperasi="<?php echo e((float) $p->nisbah_koperasi); ?>"
                        data-min="<?php echo e((float) $p->min_amount); ?>"
                        <?php echo e(old('deposito_product_id') == $p->id ? 'selected' : ''); ?>>
                        <?php echo e($p->name); ?> — <?php echo e((int) $p->tenor_months); ?> bulan
                    </option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
                <?php $__errorArgs = ['deposito_product_id'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div style="color:#ef4444;font-size:.82rem;margin-top:.25rem;"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                <div style="font-size:.78rem;color:#6b7280;margin-top:.25rem;">
                    Tenor dan nisbah ditetapkan oleh produk (akad Mudharabah) dan tidak dapat diubah manual.
                </div>
                <div id="jatuh-tempo-info" style="font-size:.82rem;color:#374151;margin-top:.35rem;font-weight:600;"></div>
                <div id="nisbah-info" style="font-size:.82rem;color:#6b7280;margin-top:.2rem;">
                    Nisbah anggota: <span id="nisbah-nasabah-val">-</span>% | Nisbah koperasi: <span id="nisbah-koperasi">-</span>%
                </div>
                <?php endif; ?>
            </div>

            
            <div style="margin-bottom:1.5rem;">
                <label style="display:block;font-weight:600;margin-bottom:.4rem;color:#374151;">Catatan (opsional)</label>
                <input type="text" name="catatan" value="<?php echo e(old('catatan')); ?>"
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;box-sizing:border-box;"
                    placeholder="Keterangan tambahan...">
            </div>

            
            <div id="jurnal-preview" style="background:#f0fdf4;border:1px solid #86efac;border-radius:.75rem;padding:1rem;margin-bottom:1.5rem;display:none;">
                <div style="font-size:.82rem;font-weight:700;color:#166534;margin-bottom:.5rem;">📒 Preview Jurnal PSAK 105 (Double-Entry)</div>
                <table style="width:100%;font-size:.82rem;border-collapse:collapse;">
                    <tr style="border-bottom:1px solid #bbf7d0;">
                        <th style="text-align:left;padding:.3rem .5rem;color:#374151;">Akun</th>
                        <th style="text-align:right;padding:.3rem .5rem;color:#374151;">Debet</th>
                        <th style="text-align:right;padding:.3rem .5rem;color:#374151;">Kredit</th>
                    </tr>
                    <tr>
                        <td style="padding:.3rem .5rem;">1-1001 Kas Teller</td>
                        <td style="text-align:right;padding:.3rem .5rem;color:#059669;font-weight:600;" id="preview-debet">-</td>
                        <td style="text-align:right;padding:.3rem .5rem;">-</td>
                    </tr>
                    <tr>
                        <td style="padding:.3rem .5rem;">2-1003 Simpanan Berjangka/Deposito</td>
                        <td style="text-align:right;padding:.3rem .5rem;">-</td>
                        <td style="text-align:right;padding:.3rem .5rem;color:#2563eb;font-weight:600;" id="preview-kredit">-</td>
                    </tr>
                </table>
            </div>

            
            <div style="display:flex;gap:.75rem;">
                <button type="submit"
                    style="flex:1;background:#059669;color:#fff;padding:.75rem 1.5rem;border:none;border-radius:8px;font-size:1rem;font-weight:600;cursor:pointer;">
                    ✅ Buka Rekening Deposito
                </button>
                <a href="<?php echo e(route('dashboard.teller')); ?>"
                    style="flex:1;text-align:center;background:#f3f4f6;color:#374151;padding:.75rem 1.5rem;border-radius:8px;font-size:1rem;font-weight:600;text-decoration:none;display:flex;align-items:center;justify-content:center;">
                    Batal
                </a>
            </div>
        </form>
    </div>
</div>

<?php $__env->startSection('scripts'); ?>
<script>
function formatRp(val) {
    return 'Rp ' + new Intl.NumberFormat('id-ID').format(val);
}

function updatePreview() {
    const input   = document.getElementById('nominal');
    const nominal = parseFloat(input.value) || 0;
    const prev    = document.getElementById('nominal-preview');
    const jprev   = document.getElementById('jurnal-preview');
    const warn    = document.getElementById('nominal-warn');

    // Batas minimum/maksimum dari parameter kebijakan koperasi (dinamis).
    const minParam  = parseFloat(input.dataset.min || '0');
    const maksParam = parseFloat(input.dataset.max || '0');
    let pesan = '';

    if (nominal > 0 && minParam > 0 && nominal < minParam) {
        pesan = 'Nominal tidak boleh kurang dari ' + formatRp(minParam)
              + ' (nilai nominal di bawah batas minimum yang diizinkan).';
    } else if (nominal > 0 && maksParam > 0 && nominal > maksParam) {
        pesan = 'Nominal tidak boleh melebihi ' + formatRp(maksParam)
              + ' (nilai nominal di atas batas maksimum yang diizinkan).';
    }

    if (pesan) {
        warn.textContent = pesan;
        warn.style.display = 'block';
    } else {
        warn.style.display = 'none';
    }

    if (nominal >= 1000) {
        prev.textContent = '= ' + formatRp(nominal);
        document.getElementById('preview-debet').textContent  = formatRp(nominal);
        document.getElementById('preview-kredit').textContent = formatRp(nominal);
        jprev.style.display = 'block';
    } else {
        prev.textContent    = '';
        jprev.style.display = 'none';
    }
}

// Tenor & nisbah dibaca dari produk terpilih (data-attribute), bukan input bebas.
function updateProduk() {
    const sel = document.getElementById('deposito_product_id');
    if (!sel) return;

    const opt = sel.options[sel.selectedIndex];
    const info = document.getElementById('jatuh-tempo-info');
    const nasabah = document.getElementById('nisbah-nasabah-val');
    const koperasi = document.getElementById('nisbah-koperasi');

    if (!sel.value || !opt) {
        info.textContent = '';
        nasabah.textContent = '-';
        koperasi.textContent = '-';
        return;
    }

    const tenor = parseInt(opt.dataset.tenor || '0');
    const nisbahNasabah = parseFloat(opt.dataset.nisbah || '0');
    const nisbahKoperasi = parseFloat(opt.dataset.nisbahKoperasi || '0');
    const minProduk = parseFloat(opt.dataset.min || '0');

    // Jatuh tempo dihitung sistem: hari ini + tenor produk (tanpa overflow bulan).
    const tgl = new Date();
    const hari = tgl.getDate();
    tgl.setDate(1);
    tgl.setMonth(tgl.getMonth() + tenor);
    const akhirBulan = new Date(tgl.getFullYear(), tgl.getMonth() + 1, 0).getDate();
    tgl.setDate(Math.min(hari, akhirBulan));

    const opts = { day: 'numeric', month: 'long', year: 'numeric' };
    info.textContent = 'Jatuh tempo (dihitung sistem): ' + tgl.toLocaleDateString('id-ID', opts)
        + ' (' + tenor + ' bulan)';

    nasabah.textContent = nisbahNasabah;
    koperasi.textContent = nisbahKoperasi;

    // Minimum produk dapat lebih tinggi dari batas kebijakan koperasi.
    // Selalu dihitung ulang dari batas kebijakan (bukan dari nilai sebelumnya).
    const input = document.getElementById('nominal');
    if (input && minProduk > 0) {
        const minKebijakan = parseFloat(input.dataset.minKebijakan || '0');
        const batas = Math.max(minKebijakan, minProduk);
        input.dataset.min = batas;
        input.setAttribute('min', batas);
        updatePreview();
    }
}

// Init preview jika ada old value (validasi error)
updatePreview();
updateProduk();
</script>
<?php $__env->stopSection(); ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/teller/deposito.blade.php ENDPATH**/ ?>