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

            
            <div style="margin-bottom:1.1rem;" id="field-jatuh-tempo">
                <label style="display:block;font-weight:600;margin-bottom:.4rem;color:#374151;">
                    Tanggal Jatuh Tempo <span style="color:#ef4444;">*</span>
                </label>
                <input type="date" name="tanggal_jatuh_tempo" id="tanggal_jatuh_tempo"
                    min="<?php echo e(today()->addDay()->toDateString()); ?>"
                    value="<?php echo e(old('tanggal_jatuh_tempo')); ?>"
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;box-sizing:border-box;" required>
                <div id="jatuh-tempo-info" style="font-size:.82rem;color:#6b7280;margin-top:.25rem;"></div>
                <?php $__errorArgs = ['tanggal_jatuh_tempo'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div style="color:#ef4444;font-size:.82rem;margin-top:.25rem;"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
            </div>

            
            <div style="margin-bottom:1.1rem;">
                <label style="display:block;font-weight:600;margin-bottom:.5rem;color:#374151;">Pilih Tenor Cepat</label>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                    <?php $__currentLoopData = [1 => '1 Bulan', 3 => '3 Bulan', 6 => '6 Bulan', 12 => '12 Bulan', 24 => '24 Bulan']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $bulan => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <button type="button" onclick="setTenor(<?php echo e($bulan); ?>)"
                        style="padding:.4rem .9rem;border:1.5px solid #d1d5db;border-radius:6px;font-size:.82rem;cursor:pointer;background:white;transition:all .15s;"
                        class="tenor-btn" data-bulan="<?php echo e($bulan); ?>">
                        <?php echo e($label); ?>

                    </button>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </div>
            </div>

            
            <div style="margin-bottom:1.1rem;">
                <label style="display:block;font-weight:600;margin-bottom:.4rem;color:#374151;">Nisbah Nasabah (%)</label>
                <input type="number" name="nisbah_nasabah" id="nisbah_nasabah" min="0" max="100" step="1"
                    value="<?php echo e(old('nisbah_nasabah', 60)); ?>"
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;box-sizing:border-box;"
                    oninput="updateNisbah()">
                <div id="nisbah-info" style="font-size:.82rem;color:#6b7280;margin-top:.25rem;">
                    Nisbah koperasi: <span id="nisbah-koperasi">40</span>% | Nisbah nasabah: <span id="nisbah-nasabah-val">60</span>%
                </div>
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

function updateNisbah() {
    const val = parseInt(document.getElementById('nisbah_nasabah').value) || 0;
    document.getElementById('nisbah-nasabah-val').textContent = val;
    document.getElementById('nisbah-koperasi').textContent    = 100 - val;
}

function setTenor(bulan) {
    const tgl  = new Date();
    tgl.setMonth(tgl.getMonth() + bulan);
    const yyyy = tgl.getFullYear();
    const mm   = String(tgl.getMonth() + 1).padStart(2, '0');
    const dd   = String(tgl.getDate()).padStart(2, '0');
    const val  = `${yyyy}-${mm}-${dd}`;
    document.getElementById('tanggal_jatuh_tempo').value = val;

    // Update info jatuh tempo
    const opts = { day:'numeric', month:'long', year:'numeric' };
    document.getElementById('jatuh-tempo-info').textContent =
        'Jatuh tempo: ' + tgl.toLocaleDateString('id-ID', opts) + ' (' + bulan + ' bulan)';

    // Highlight tombol aktif
    document.querySelectorAll('.tenor-btn').forEach(btn => {
        btn.style.background     = btn.dataset.bulan == bulan ? '#059669' : 'white';
        btn.style.color          = btn.dataset.bulan == bulan ? 'white'   : '#374151';
        btn.style.borderColor    = btn.dataset.bulan == bulan ? '#059669' : '#d1d5db';
    });
}

// Update info saat tanggal diisi manual
document.getElementById('tanggal_jatuh_tempo').addEventListener('change', function() {
    if (this.value) {
        const tgl  = new Date(this.value);
        const opts = { day:'numeric', month:'long', year:'numeric' };
        document.getElementById('jatuh-tempo-info').textContent =
            'Jatuh tempo: ' + tgl.toLocaleDateString('id-ID', opts);
    }
});

// Init preview jika ada old value (validasi error)
updatePreview();
updateNisbah();
</script>
<?php $__env->stopSection(); ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\teller\deposito.blade.php ENDPATH**/ ?>