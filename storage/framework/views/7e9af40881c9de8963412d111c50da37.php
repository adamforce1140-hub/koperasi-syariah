
<?php $__env->startSection('page-title', "Ajukan Pembiayaan"); ?>

<?php $__env->startSection('title', 'Ajukan Pembiayaan'); ?>

<?php $__env->startSection('content'); ?>
<div class="page-header">
    <h1 class="page-title">Ajukan Pembiayaan</h1>
    <p class="page-subtitle">Isi form berikut untuk mengajukan pembiayaan syariah</p>
</div>

<?php if($errors->any()): ?>
    <div class="alert alert-error">
        <ul style="margin:0;padding-left:1.2rem;">
            <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $e): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <li><?php echo e($e); ?></li>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </ul>
    </div>
<?php endif; ?>

<div class="data-card">
    <div class="card-header">
        <span class="card-title">Form Pengajuan Pembiayaan</span>
    </div>

    <form method="POST" action="<?php echo e(route('nasabah.pengajuan.store')); ?>" style="padding:1.5rem;">
        <?php echo csrf_field(); ?>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1.2rem;">

            <div>
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">
                    Produk Pembiayaan <span style="color:#ef4444">*</span>
                </label>
                <select name="produk_id" id="produk_id" required
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;"
                    onchange="updateInfo(this)">
                    <option value="">-- Pilih Produk --</option>
                    <?php $__currentLoopData = $produk; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($p->id); ?>"
                        data-akad="<?php echo e($p->akad_syariah); ?>"
                        data-min="<?php echo e($p->min_plafon); ?>"
                        data-max="<?php echo e($p->max_plafon); ?>"
                        data-margin="<?php echo e($p->margin_persen); ?>"
                        data-min-tenor="<?php echo e($p->min_tenor_bulan); ?>"
                        data-max-tenor="<?php echo e($p->max_tenor_bulan); ?>"
                        <?php echo e(old('produk_id') == $p->id ? 'selected' : ''); ?>>
                        <?php echo e($p->nama_produk); ?> (<?php echo e($p->akad_syariah); ?>)
                    </option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
                <div id="produk-info" style="margin-top:.5rem;font-size:.78rem;color:#6b7280;display:none;">
                    <span id="info-akad"></span> &bull;
                    Plafon: <span id="info-plafon"></span> &bull;
                    Margin: <span id="info-margin"></span>%/th &bull;
                    Tenor: <span id="info-tenor"></span> bulan
                </div>
            </div>

            <div>
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">
                    Jenis Akad <span style="color:#ef4444">*</span>
                </label>
                <input type="text" name="jenis_akad" id="jenis_akad"
                    value="<?php echo e(old('jenis_akad')); ?>" required readonly
                    placeholder="Otomatis terisi sesuai produk"
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;background:#f9fafb;">
            </div>

            <div>
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">
                    Jumlah Pengajuan (Rp) <span style="color:#ef4444">*</span>
                </label>
                <input type="number" name="jumlah_pengajuan" id="jumlah_pengajuan"
                    min="<?php echo e(\App\Support\Setting::minimalPengajuanPembiayaan()); ?>" step="100000" value="<?php echo e(old('jumlah_pengajuan')); ?>" required
                    placeholder="Contoh: 5000000"
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;"
                    oninput="hitungSimulasi()">
            </div>

            <div>
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">
                    Tenor (bulan) <span style="color:#ef4444">*</span>
                </label>
                <input type="number" name="tenor" id="tenor"
                    min="1" max="360" value="<?php echo e(old('tenor')); ?>" required
                    placeholder="Contoh: 12"
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;"
                    oninput="hitungSimulasi()">
            </div>

            <div style="grid-column:span 2;">
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">
                    Tujuan Penggunaan Dana <span style="color:#ef4444">*</span>
                </label>
                <input type="text" name="tujuan" value="<?php echo e(old('tujuan')); ?>" required
                    placeholder="Contoh: Modal usaha warung makan"
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;">
            </div>

            <div style="grid-column:span 2;">
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">
                    Agunan / Jaminan
                </label>
                <input type="text" name="agunan" value="<?php echo e(old('agunan')); ?>"
                    placeholder="Contoh: BPKB Motor Honda Beat 2022"
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;">
            </div>

            <div>
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">
                    Penghasilan Bulanan (Rp)
                </label>
                <input type="number" name="penghasilan_bulanan" min="0" step="100000"
                    value="<?php echo e(old('penghasilan_bulanan')); ?>"
                    placeholder="Contoh: 3000000"
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;">
            </div>

            <div>
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">
                    Pengeluaran Bulanan (Rp)
                </label>
                <input type="number" name="pengeluaran_bulanan" min="0" step="100000"
                    value="<?php echo e(old('pengeluaran_bulanan')); ?>"
                    placeholder="Contoh: 1500000"
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;">
            </div>

            <div>
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">
                    Jumlah Tanggungan
                </label>
                <input type="number" name="jumlah_tanggungan" min="0"
                    value="<?php echo e(old('jumlah_tanggungan', 0)); ?>"
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;">
            </div>
        </div>

        
        <div id="simulasi-box" style="display:none;margin-top:1.2rem;padding:1rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;">
            <div style="font-weight:600;color:#166534;margin-bottom:.6rem;">Simulasi Angsuran</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;text-align:center;">
                <div>
                    <div style="font-size:.75rem;color:#6b7280;">Total Pembiayaan</div>
                    <div style="font-size:1.1rem;font-weight:700;color:#166534;" id="sim-total"></div>
                </div>
                <div>
                    <div style="font-size:.75rem;color:#6b7280;">Angsuran/Bulan</div>
                    <div style="font-size:1.1rem;font-weight:700;color:#0ea5e9;" id="sim-angsuran"></div>
                </div>
                <div>
                    <div style="font-size:.75rem;color:#6b7280;">Total Margin</div>
                    <div style="font-size:1.1rem;font-weight:700;color:#f59e0b;" id="sim-margin"></div>
                </div>
            </div>
        </div>

        <div style="margin-top:1.5rem;display:flex;gap:1rem;">
            <button type="submit" class="btn btn-primary">Kirim Pengajuan</button>
            <a href="<?php echo e(route('nasabah.pengajuan.index')); ?>" class="btn btn-secondary">Batal</a>
        </div>
    </form>
</div>

<script>
let selectedProduk = null;

function updateInfo(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (!opt.value) {
        document.getElementById('produk-info').style.display = 'none';
        document.getElementById('jenis_akad').value = '';
        selectedProduk = null;
        return;
    }
    selectedProduk = {
        margin: parseFloat(opt.dataset.margin),
        minTenor: parseInt(opt.dataset.minTenor),
        maxTenor: parseInt(opt.dataset.maxTenor),
    };
    document.getElementById('jenis_akad').value = opt.dataset.akad;
    document.getElementById('info-akad').textContent = opt.dataset.akad;
    document.getElementById('info-plafon').textContent =
        'Rp ' + parseInt(opt.dataset.min).toLocaleString('id-ID') +
        ' - Rp ' + parseInt(opt.dataset.max).toLocaleString('id-ID');
    document.getElementById('info-margin').textContent = opt.dataset.margin;
    document.getElementById('info-tenor').textContent = opt.dataset.minTenor + ' - ' + opt.dataset.maxTenor;
    document.getElementById('produk-info').style.display = 'block';
    hitungSimulasi();
}

function hitungSimulasi() {
    if (!selectedProduk) return;
    const pokok  = parseFloat(document.getElementById('jumlah_pengajuan').value) || 0;
    const tenor  = parseInt(document.getElementById('tenor').value) || 0;
    if (pokok <= 0 || tenor <= 0) {
        document.getElementById('simulasi-box').style.display = 'none';
        return;
    }
    const margin   = (selectedProduk.margin / 100) * pokok * (tenor / 12);
    const total    = pokok + margin;
    const perBulan = total / tenor;
    document.getElementById('sim-total').textContent    = 'Rp ' + total.toLocaleString('id-ID', {maximumFractionDigits:0});
    document.getElementById('sim-angsuran').textContent = 'Rp ' + perBulan.toLocaleString('id-ID', {maximumFractionDigits:0});
    document.getElementById('sim-margin').textContent   = 'Rp ' + margin.toLocaleString('id-ID', {maximumFractionDigits:0});
    document.getElementById('simulasi-box').style.display = 'block';
}
</script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\nasabah\pengajuan\create.blade.php ENDPATH**/ ?>