
<?php $__env->startSection('page-title', "Simulasi Angsuran"); ?>

<?php $__env->startSection('title', 'Simulasi Angsuran'); ?>

<?php $__env->startSection('content'); ?>
<div class="page-header">
    <h1 class="page-title">Simulasi Angsuran</h1>
    <p class="page-subtitle">Kalkulator angsuran pembiayaan syariah</p>
</div>

<div style="display:grid;grid-template-columns:1fr 2fr;gap:1.5rem;">

    
    <div class="data-card">
        <div class="card-header"><span class="card-title">Parameter Simulasi</span></div>
        <form method="GET" action="<?php echo e(route('ao.simulasi.index')); ?>" style="padding:1.5rem;">
            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">Jenis Akad</label>
                <select name="akad" style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;">
                    <?php $__currentLoopData = \App\Models\Pembiayaan::daftarAkad(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $akad): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($akad); ?>" <?php echo e(request('akad', $hasil['akad'] ?? \App\Models\Pembiayaan::akadDefault()) == $akad ? 'selected' : ''); ?>><?php echo e($akad); ?></option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
            </div>
            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">Jumlah Pembiayaan (Rp)</label>
                <input type="number" name="jumlah" min="1000000" step="100000"
                    value="<?php echo e(request('jumlah', $hasil['jumlah'] ?? '')); ?>" required
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;">
            </div>
            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">Margin / Nisbah (% per tahun)</label>
                <input type="number" name="margin" min="0" max="100" step="0.01"
                    value="<?php echo e(request('margin', $hasil['margin'] ?? '')); ?>" required
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;">
            </div>
            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">Tenor (bulan)</label>
                <input type="number" name="tenor" min="1" max="360"
                    value="<?php echo e(request('tenor', $hasil['tenor'] ?? '')); ?>" required
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;">
            </div>
            <div style="margin-bottom:1.2rem;">
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">Metode Hitung</label>
                <select name="metode" style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;">
                    <option value="flat" <?php echo e(request('metode', $hasil['metode'] ?? 'flat') == 'flat' ? 'selected' : ''); ?>>Flat</option>
                    <option value="anuitas" <?php echo e(request('metode','flat') == 'anuitas' ? 'selected' : ''); ?>>Anuitas</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Hitung Simulasi</button>
        </form>
    </div>

    
    <div>
        <?php if($hasil): ?>
        <div class="data-card" style="margin-bottom:1.5rem;">
            <div class="card-header"><span class="card-title">Ringkasan — <?php echo e(strtoupper($hasil['metode'])); ?></span></div>
            <div style="padding:1.5rem;">
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;text-align:center;margin-bottom:1rem;">
                    <div style="padding:1rem;background:#f0fdf4;border-radius:8px;">
                        <div style="font-size:.75rem;color:#6b7280;">Total Piutang Murabahah</div>
                        <div style="font-size:1.1rem;font-weight:700;color:#166534;">Rp <?php echo e(number_format($hasil['totalPembiayaan'], 0, ',', '.')); ?></div>
                    </div>
                    <div style="padding:1rem;background:#eff6ff;border-radius:8px;">
                        <div style="font-size:.75rem;color:#6b7280;">Angsuran/Bulan</div>
                        <div style="font-size:1.1rem;font-weight:700;color:#1d4ed8;">Rp <?php echo e(number_format($hasil['angsuranPerBulan'], 0, ',', '.')); ?></div>
                    </div>
                    <div style="padding:1rem;background:#fff7ed;border-radius:8px;">
                        <div style="font-size:.75rem;color:#6b7280;">Total Margin</div>
                        <div style="font-size:1.1rem;font-weight:700;color:#c2410c;">Rp <?php echo e(number_format($hasil['totalMargin'], 0, ',', '.')); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="data-card">
            <div class="card-header"><span class="card-title">Jadwal Angsuran</span></div>
            <div style="max-height:400px;overflow-y:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Ke</th>
                            <th>Pokok</th>
                            <th>Margin</th>
                            <th>Total Angsuran</th>
                            <th>Sisa Pokok</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $__currentLoopData = $hasil['jadwal']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $baris): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr>
                            <td style="text-align:center;"><?php echo e($baris['ke']); ?></td>
                            <td>Rp <?php echo e(number_format($baris['pokok'], 0, ',', '.')); ?></td>
                            <td>Rp <?php echo e(number_format($baris['margin'], 0, ',', '.')); ?></td>
                            <td><strong>Rp <?php echo e(number_format($baris['angsuran'], 0, ',', '.')); ?></strong></td>
                            <td>Rp <?php echo e(number_format($baris['sisa_pokok'], 0, ',', '.')); ?></td>
                        </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
        <div class="data-card" style="padding:3rem;text-align:center;color:#94a3b8;">
            Isi parameter di sebelah kiri untuk menampilkan simulasi angsuran.
        </div>
        <?php endif; ?>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\ao\simulasi\index.blade.php ENDPATH**/ ?>