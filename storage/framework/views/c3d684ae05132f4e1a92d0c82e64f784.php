<?php $__env->startSection('page-title', "Setor Tunai"); ?>

<?php $__env->startSection('title', 'Setor Tunai'); ?>

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
<?php $__env->stopSection(); ?>

<?php $__env->startSection('styles'); ?>
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<style>
.ts-wrapper { width:100% !important; }
.ts-wrapper.single .ts-control { border-radius:6px !important; padding:.6rem .8rem !important; font-size:.95rem !important; border-color:#d1d5db !important; min-height:auto !important; }
.ts-wrapper.focus.single .ts-control { border-color:#047857 !important; box-shadow:0 0 0 2px rgba(4,120,87,.15) !important; }
</style>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<div style="max-width:600px;margin:0 auto;">
    <div class="data-card">
        <div class="card-header">
            <span class="card-title">💰 Setor Tunai</span>
        </div>

        <?php if(session('success')): ?>
        <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
            <?php echo e(session('success')); ?>

        </div>
        <?php endif; ?>
        <?php if(session('warning')): ?>
        <div style="background:#fef3c7;border:1px solid #fcd34d;color:#92400e;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
            ⚠️ <?php echo e(session('warning')); ?>

        </div>
        <?php endif; ?>

        <?php if(session('error')): ?>
        <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
            ⛔ <?php echo e(session('error')); ?>

        </div>
        <?php endif; ?>

        <form method="POST" action="<?php echo e(route('teller.setor.store')); ?>">
            <?php echo csrf_field(); ?>

            
            <div style="margin-bottom:1rem;">
                <label style="display:block;font-weight:600;margin-bottom:.4rem;color:#374151;">Anggota</label>
                <select name="anggota_id" id="anggota_id" style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;" required>
                    <option value="">-- Pilih Anggota --</option>
                    <?php $anggotaTerpilihId = old('anggota_id', $anggotaTerpilih->id ?? null); ?>
                    <?php $__currentLoopData = $anggota; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $a): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($a->id); ?>" <?php echo e((string) $anggotaTerpilihId === (string) $a->id ? 'selected' : ''); ?>>
                        <?php echo e($a->nama_lengkap); ?> (<?php echo e($a->no_anggota); ?>)<?php if($a->status_anggota === \App\Models\Anggota::STATUS_PENDING): ?> — Menunggu Setoran Awal <?php endif; ?>
                    </option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
                <div id="anggota-info" style="margin-top:.4rem;font-size:.85rem;color:#6b7280;"></div>
                <?php $__errorArgs = ['anggota_id'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div style="color:#ef4444;font-size:.85rem;margin-top:.3rem;"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
            </div>

            
            <div style="margin-bottom:1rem;">
                <label style="display:block;font-weight:600;margin-bottom:.4rem;color:#374151;">Rekening</label>
                <select name="rekening_id" id="rekening_id" style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;" required
                    data-terpilih="<?php echo e(old('rekening_id')); ?>">
                    <?php $rekeningTerpilih = $rekeningTerpilih ?? collect(); ?>
                    <?php if($rekeningTerpilih->isNotEmpty()): ?>
                        
                        <?php $__currentLoopData = $rekeningTerpilih; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $rek): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($rek->id); ?>" data-saldo="<?php echo e($rek->saldo); ?>"
                            <?php echo e((string) old('rekening_id') === (string) $rek->id ? 'selected' : ''); ?>>
                            <?php echo e($rek->no_rekening); ?> (<?php echo e($rek->jenis_rekening); ?>)
                        </option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    <?php else: ?>
                        <option value="">-- Pilih Anggota Dulu --</option>
                    <?php endif; ?>
                </select>
                <div id="saldo-info" style="margin-top:.4rem;font-size:.85rem;color:#6b7280;"></div>
                <?php $__errorArgs = ['rekening_id'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div style="color:#ef4444;font-size:.85rem;margin-top:.3rem;"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
            </div>

            
            <div style="margin-bottom:1rem;">
                <label style="display:block;font-weight:600;margin-bottom:.4rem;color:#374151;">Jumlah Setor (Rp)</label>
                <input type="number" name="jumlah" id="jumlah" min="1000" step="1000"
                    value="<?php echo e(old('jumlah')); ?>"
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;box-sizing:border-box;"
                    placeholder="Contoh: 500000" required>
                <?php $__errorArgs = ['jumlah'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div style="color:#ef4444;font-size:.85rem;margin-top:.3rem;"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
            </div>

            
            <div style="margin-bottom:1.5rem;">
                <label style="display:block;font-weight:600;margin-bottom:.4rem;color:#374151;">Catatan (opsional)</label>
                <input type="text" name="catatan" value="<?php echo e(old('catatan')); ?>"
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;box-sizing:border-box;"
                    placeholder="Keterangan tambahan...">
            </div>

            <div style="display:flex;gap:.75rem;">
                <button type="submit" style="flex:1;background:#047857;color:#fff;padding:.7rem 1.5rem;border:none;border-radius:6px;font-size:1rem;font-weight:600;cursor:pointer;">
                    &#9989; Proses Setor
                </button>
                
                <a href="<?php echo e(route('dashboard.teller')); ?>" style="flex:1;text-align:center;background:#f3f4f6;color:#374151;padding:.7rem 1.5rem;border-radius:6px;font-size:1rem;font-weight:600;text-decoration:none;">
                    Batal
                </a>
            </div>
        </form>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('scripts'); ?>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const el = document.getElementById('anggota_id');
    if (el) {
        if (el.tomselect) el.tomselect.destroy();
        new TomSelect('#anggota_id', {
            create: false,
            maxOptions: 100,
            placeholder: 'Ketik nama, No. CIF, atau NIK...',
            onChange: function (anggotaId) {
                loadRekening(anggotaId);
            }
        });
    }

    function loadRekening(anggotaId) {
        const rekeningSelect = document.getElementById('rekening_id');
        const saldoInfo = document.getElementById('saldo-info');
        const anggotaInfo = document.getElementById('anggota-info');

        rekeningSelect.innerHTML = '<option value="">Memuat...</option>';
        saldoInfo.textContent = '';
        if (anggotaInfo) anggotaInfo.textContent = '';

        if (!anggotaId) {
            rekeningSelect.innerHTML = '<option value="">-- Pilih Anggota Dulu --</option>';
            return;
        }

        fetch(`<?php echo e(route('teller.rekening-by-anggota')); ?>?mode=setor&anggota_id=${anggotaId}`)
            .then(r => r.json())
            .then(data => {
                const terpilih = rekeningSelect.dataset.terpilih || '';
                rekeningSelect.innerHTML = '<option value="">-- Pilih Rekening --</option>';
                data.forEach(rek => {
                    const selected = String(terpilih) === String(rek.id) ? ' selected' : '';
                    rekeningSelect.innerHTML += `<option value="${rek.id}" data-saldo="${rek.saldo}"${selected}>
                        ${rek.no_rekening} (${rek.jenis_rekening})
                    </option>`;
                });
                if (data.length === 0) {
                    rekeningSelect.innerHTML = '<option value="">Tidak ada rekening aktif</option>';
                    if (anggotaInfo) {
                        anggotaInfo.textContent = 'Anggota ini belum memiliki rekening aktif — setoran tidak dapat dilanjutkan.';
                        anggotaInfo.style.color = '#b91c1c';
                    }
                } else if (anggotaInfo) {
                    anggotaInfo.textContent = 'Rekening aktif ditemukan. Silakan pilih rekening tujuan setoran.';
                    anggotaInfo.style.color = '#059669';
                }
            })
            .catch(() => {
                rekeningSelect.innerHTML = '<option value="">Gagal memuat rekening</option>';
                if (anggotaInfo) {
                    anggotaInfo.textContent = 'Gagal memuat rekening anggota. Muat ulang halaman.';
                    anggotaInfo.style.color = '#b91c1c';
                }
            });
    }

    document.getElementById('rekening_id').addEventListener('change', function () {
        const opt = this.options[this.selectedIndex];
        const saldo = opt.dataset.saldo;
        const info = document.getElementById('saldo-info');
        if (saldo !== undefined && saldo !== '') {
            const fmt = new Intl.NumberFormat('id-ID').format(saldo);
            info.textContent = `Saldo saat ini: Rp ${fmt}`;
            info.style.color = '#059669';
        } else {
            info.textContent = '';
        }
    });

    /* Praseleksi dari tautan profil anggota (`?anggota_id=...`): TomSelect
     | menampilkan nilai terpilih, tetapi `onChange` TIDAK terpicu saat
     | inisialisasi. Karena itu daftar rekening dimuat manual di sini agar
     | nama anggota beserta rekeningnya langsung siap dipakai teller. */
    const preselected = el ? el.value : '';
    if (preselected) {
        loadRekening(preselected);
    }
});
</script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/teller/setor.blade.php ENDPATH**/ ?>