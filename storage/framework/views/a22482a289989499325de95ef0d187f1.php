
<?php $__env->startSection('page-title', "Input Pengajuan Pembiayaan"); ?>

<?php $__env->startSection('title', 'Input Pengajuan Pembiayaan'); ?>


<?php $__env->startSection('styles'); ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.bootstrap5.min.css">
<style>
    .ts-wrapper.single .ts-control {
        padding: .5rem .8rem;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: .9rem;
        background: #fff;
        cursor: text;
        width: 100%;
    }
    .ts-wrapper.single.focus .ts-control {
        border-color: #6366f1;
        box-shadow: 0 0 0 2px rgba(99,102,241,.2);
        outline: none;
    }
    .ts-wrapper { width: 100%; }
    .ts-dropdown .option { padding: .5rem .8rem; font-size: .85rem; }
    .ts-dropdown .option:hover, .ts-dropdown .option.active { background: #ede9fe; color: #4338ca; }
    .ts-dropdown .no-results { padding: .6rem .8rem; color: #6b7280; font-size: .85rem; }

    /* Kotak info finansial */
    #finansial-box { display:none; margin-top:1rem; padding:.9rem 1.1rem;
        background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; font-size:.85rem; }
    #finansial-box .fin-title { font-weight:700; color:#1d4ed8; margin-bottom:.5rem; }
    #finansial-box .fin-grid  { display:grid; grid-template-columns:1fr 1fr; gap:.4rem .8rem; }
    #finansial-box .fin-label { color:#6b7280; }
    #finansial-box .fin-value { font-weight:600; color:#1e3a5f; }

    /* Kotak deposito agunan */
    #deposito-box { display:none; margin-top:.8rem; padding:.9rem 1.1rem;
        background:#fefce8; border:1px solid #fde68a; border-radius:8px; font-size:.85rem; }
    #deposito-box .dep-title { font-weight:700; color:#b45309; margin-bottom:.5rem; }
    #deposito-box .dep-item  { display:flex; align-items:center; gap:.6rem; margin-bottom:.4rem; }
    #deposito-box label       { cursor:pointer; }
</style>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<div class="page-header">
    <h1 class="page-title">Input Pengajuan Pembiayaan</h1>
    <p class="page-subtitle">Form input pengajuan pembiayaan oleh AO</p>
</div>

<?php if($errors->any()): ?>
    <div class="alert alert-error">
        <ul style="margin:0;padding-left:1.2rem;">
            <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $e): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><li><?php echo e($e); ?></li><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </ul>
    </div>
<?php endif; ?>

<div class="data-card">
    <div class="card-header">
        <span class="card-title">Form Pengajuan</span>
    </div>

    <form method="POST" action="<?php echo e(route('ao.pengajuan.store')); ?>" enctype="multipart/form-data" style="padding:1.5rem;">
        <?php echo csrf_field(); ?>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.2rem;">

            
            <div style="grid-column:span 2;">
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">
                    Anggota <span style="color:#ef4444">*</span>
                    <span style="font-weight:400;color:#9ca3af;margin-left:.4rem;">— ketik nama, No. CIF, atau No. Anggota</span>
                </label>
                
                <select id="anggota_id" name="anggota_id" required>
                    <option value="">-- Pilih Anggota --</option>
                    <?php $__currentLoopData = $anggota; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $a): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <?php
                            $identifier = $a->no_cif ?: $a->no_anggota;
                            $extra      = $a->nik ?: $a->no_hp;
                        ?>
                        <option value="<?php echo e($a->id); ?>"
                            <?php echo e(old('anggota_id') == $a->id ? 'selected' : ''); ?>>
                            <?php echo e($identifier); ?> - <?php echo e($a->nama_lengkap); ?><?php echo e($extra ? ' - '.$extra : ''); ?>

                        </option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>

                
                <div id="finansial-box">
                    <div class="fin-title">Info Finansial Anggota</div>
                    <div class="fin-grid">
                        <div class="fin-label">Nama Anggota</div>
                        <div class="fin-value" id="fin-nama">-</div>
                        <div class="fin-label">No. Rek. Simpanan Sukarela</div>
                        <div class="fin-value" id="fin-no-rekening">-</div>
                        <div class="fin-label">Saldo Simpanan Sukarela</div>
                        <div class="fin-value" id="fin-simpanan">-</div>
                        <div class="fin-label" id="fin-deposito-label" style="display:none">Saldo Bilyet Deposito Aktif</div>
                        <div class="fin-value" id="fin-deposito-total" style="display:none">-</div>
                        <div class="fin-label">Status Kelayakan</div>
                        <div class="fin-value" id="fin-badge">-</div>
                    </div>
                    <div id="fin-alert-sukarela" style="display:none;margin-top:.7rem;padding:.6rem .9rem;background:#fef9c3;border:1px solid #fde047;border-radius:6px;font-size:.82rem;color:#854d0e;">
                        &#9888; Anggota belum memiliki Rekening Simpanan Sukarela. Sistem akan otomatis meregistrasikan rekening saat pengajuan disetujui.
                    </div>
                </div>

                
                <div id="deposito-box">
                    <div class="dep-title">Bilyet Deposito Aktif — Bisa dijadikan Agunan Back-to-Back</div>
                    <div id="deposito-list"></div>
                    <input type="hidden" name="agunan_deposito_rekening_id" id="agunan_deposito_rekening_id">
                </div>
            </div>

            <div>
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">
                    Produk Pembiayaan <span style="color:#ef4444">*</span>
                </label>
                <select name="produk_id" id="produk_id" required
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;"
                    onchange="updateAkad(this)">
                    <option value="">-- Pilih Produk --</option>
                    <?php $__currentLoopData = $produk; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($p->id); ?>"
                        data-akad="<?php echo e($p->akad_syariah); ?>"
                        data-margin="<?php echo e($p->margin_persen); ?>"
                        <?php echo e(old('produk_id') == $p->id ? 'selected' : ''); ?>>
                        <?php echo e($p->nama_produk); ?> (<?php echo e($p->akad_syariah); ?>)
                    </option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
            </div>

            <div>
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">
                    Jenis Akad <span style="color:#ef4444">*</span>
                </label>
                <select name="jenis_akad" id="jenis_akad" required style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;">
                    <option value="">-- Pilih Akad --</option>
                    <?php $__currentLoopData = ['Murabahah','Mudharabah','Musyarakah','Ijarah','Ijarah Muntahia Bittamlik','Qardh','Istishna','Salam']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $akad): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($akad); ?>" <?php echo e(old('jenis_akad') == $akad ? 'selected' : ''); ?>><?php echo e($akad); ?></option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
            </div>

            <div>
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">
                    Sumber Pengajuan
                </label>
                <select name="sumber_pengajuan" style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;">
                    <option value="Direct/AO" <?php echo e(old('sumber_pengajuan','Direct/AO') == 'Direct/AO' ? 'selected' : ''); ?>>Direct / AO</option>
                    <option value="Limpahan Loket/Teller" <?php echo e(old('sumber_pengajuan') == 'Limpahan Loket/Teller' ? 'selected' : ''); ?>>Limpahan Loket / Teller</option>
                </select>
            </div>

            <div>
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">
                    Jumlah Pengajuan (Rp) <span style="color:#ef4444">*</span>
                </label>
                <input type="number" name="jumlah_pengajuan" id="jumlah_pengajuan"
                    min="1000000" step="100000" value="<?php echo e(old('jumlah_pengajuan')); ?>" required
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;"
                    oninput="hitungSimulasi()">
            </div>

            <div>
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">
                    Tenor (bulan) <span style="color:#ef4444">*</span>
                </label>
                <input type="number" name="tenor" id="tenor"
                    min="1" max="360" value="<?php echo e(old('tenor')); ?>" required
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;"
                    oninput="hitungSimulasi()">
            </div>

            <div style="grid-column:span 2;">
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">
                    Tujuan Pembiayaan
                </label>
                <input type="text" name="tujuan" value="<?php echo e(old('tujuan')); ?>"
                    placeholder="Contoh: Modal usaha, renovasi rumah, dll."
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;">
            </div>

            <div style="grid-column:span 2;">
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">
                    Agunan / Jaminan
                </label>
                <input type="text" name="agunan" id="agunan" value="<?php echo e(old('agunan')); ?>"
                    placeholder="Contoh: BPKB Motor, Sertifikat Tanah — atau pilih Deposito di atas"
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;">
            </div>

            <div>
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">Penghasilan Bulanan (Rp)</label>
                <input type="number" name="penghasilan_bulanan" min="0" step="100000" value="<?php echo e(old('penghasilan_bulanan')); ?>"
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;">
            </div>

            <div>
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">Pengeluaran Bulanan (Rp)</label>
                <input type="number" name="pengeluaran_bulanan" min="0" step="100000" value="<?php echo e(old('pengeluaran_bulanan')); ?>"
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;">
            </div>

            <div>
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">Jumlah Tanggungan</label>
                <input type="number" name="jumlah_tanggungan" min="0" value="<?php echo e(old('jumlah_tanggungan', 0)); ?>"
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;">
            </div>

            <div>
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">Berkas Survei / Agunan (PDF/Gambar)</label>
                <input type="file" name="berkas_survei" accept=".pdf,.jpg,.jpeg,.png"
                    style="width:100%;padding:.4rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;">
            </div>
        </div>

        
        <div id="simulasi-box" style="display:none;margin-top:1.2rem;padding:1rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;">
            <div style="font-weight:600;color:#166534;margin-bottom:.6rem;">Estimasi Angsuran (Flat)</div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;text-align:center;">
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
            <button type="submit" class="btn btn-primary">Simpan Pengajuan</button>
            <a href="<?php echo e(route('ao.pengajuan.index')); ?>" class="btn btn-secondary">Batal</a>
        </div>
    </form>
</div>


<?php $__env->startPush('scripts'); ?>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
<script>
const FINANSIAL_URL = "<?php echo e(url('ao/anggota')); ?>";

// ── Tom Select: client-side filter ────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    const tomSelect = new TomSelect('#anggota_id', {
        create: false,
        sortField: { field: 'text', direction: 'asc' },
        placeholder: 'Ketik nama, No. CIF, atau No. Anggota...',
        render: {
            no_results() {
                return `<div class="no-results">Anggota tidak ditemukan. Coba kata kunci lain.</div>`;
            },
        },
        onChange(value) {
            if (!value) { sembunyikanFinansial(); return; }
            muatFinansial(value);
        },
    });

    // Re-load finansial jika ada old('anggota_id') dari validasi gagal
    <?php if(old('anggota_id')): ?>
    muatFinansial(<?php echo e(old('anggota_id')); ?>);
    <?php endif; ?>
});

// ── Auto-fill finansial ────────────────────────────────────────────────────
function muatFinansial(anggotaId) {
    fetch(`${FINANSIAL_URL}/${anggotaId}/finansial`)
        .then(r => r.json())
        .then(data => {
            // Nama & saldo simpanan
            document.getElementById('fin-nama').textContent     = data.nama_lengkap;
            document.getElementById('fin-simpanan').textContent = data.simpanan_formatted;

            // No. Rekening Sukarela
            const noRekEl = document.getElementById('fin-no-rekening');
            noRekEl.textContent = data.no_rekening_sukarela ?? '-';

            // Saldo Deposito (hanya tampil jika ada)
            const depLabel = document.getElementById('fin-deposito-label');
            const depTotal = document.getElementById('fin-deposito-total');
            if (data.total_deposito_formatted) {
                depTotal.textContent      = data.total_deposito_formatted;
                depLabel.style.display    = '';
                depTotal.style.display    = '';
            } else {
                depLabel.style.display    = 'none';
                depTotal.style.display    = 'none';
            }

            // Badge status kelayakan
            const badgeEl = document.getElementById('fin-badge');
            if (data.punya_sukarela) {
                badgeEl.innerHTML = '<span style="display:inline-block;padding:.2rem .7rem;background:#dcfce7;color:#166534;border-radius:999px;font-size:.78rem;font-weight:700;">&#10003; Siap Mengajukan</span>';
            } else {
                badgeEl.innerHTML = '<span style="display:inline-block;padding:.2rem .7rem;background:#fee2e2;color:#991b1b;border-radius:999px;font-size:.78rem;font-weight:700;">&#9888; Belum Ada Rekening Sukarela</span>';
            }

            // Alert warning jika belum punya rekening sukarela
            document.getElementById('fin-alert-sukarela').style.display = data.punya_sukarela ? 'none' : 'block';

            document.getElementById('finansial-box').style.display = 'block';

            // Tampilkan deposito jika ada
            if (data.punya_deposito) {
                const list = document.getElementById('deposito-list');
                list.innerHTML = '';
                data.deposito.forEach(dep => {
                    list.innerHTML += `
                        <div class="dep-item">
                            <input type="radio" name="_deposito_pilih" id="dep_${dep.id}"
                                value="${dep.id}"
                                onchange="pilihDeposito(${dep.id}, '${dep.no_rekening}', '${dep.saldo_formatted}')">
                            <label for="dep_${dep.id}">
                                <strong>${dep.no_rekening}</strong> &mdash;
                                ${dep.saldo_formatted} &mdash;
                                Akad: ${dep.akad} &mdash;
                                JT: ${dep.jatuh_tempo}
                            </label>
                        </div>`;
                });
                // Opsi "tidak pakai deposito"
                list.innerHTML += `
                    <div class="dep-item">
                        <input type="radio" name="_deposito_pilih" id="dep_none" value=""
                            checked onchange="pilihDeposito(null, null, null)">
                        <label for="dep_none">Tidak menggunakan Deposito sebagai agunan</label>
                    </div>`;
                document.getElementById('deposito-box').style.display = 'block';
            } else {
                document.getElementById('deposito-box').style.display = 'none';
                document.getElementById('agunan_deposito_rekening_id').value = '';
            }
        })
        .catch(() => sembunyikanFinansial());
}

function sembunyikanFinansial() {
    document.getElementById('finansial-box').style.display = 'none';
    document.getElementById('deposito-box').style.display  = 'none';
    document.getElementById('agunan_deposito_rekening_id').value = '';
}

function pilihDeposito(id, noRekening, saldoFormatted) {
    document.getElementById('agunan_deposito_rekening_id').value = id ?? '';
    const agunanInput = document.getElementById('agunan');
    if (id && noRekening) {
        agunanInput.value = `Deposito Back-to-Back ${noRekening} (${saldoFormatted})`;
    } else {
        agunanInput.value = '';
    }
}

// ── Kalkulasi produk & simulasi angsuran ──────────────────────────────────
let marginPersen = 0;

function updateAkad(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (opt.value) {
        document.getElementById('jenis_akad').value = opt.dataset.akad || '';
        marginPersen = parseFloat(opt.dataset.margin) || 0;
        hitungSimulasi();
    }
}

function hitungSimulasi() {
    const pokok = parseFloat(document.getElementById('jumlah_pengajuan').value) || 0;
    const tenor = parseInt(document.getElementById('tenor').value) || 0;
    if (pokok <= 0 || tenor <= 0 || marginPersen <= 0) {
        document.getElementById('simulasi-box').style.display = 'none';
        return;
    }
    const margin   = (marginPersen / 100) * pokok * (tenor / 12);
    const total    = pokok + margin;
    const perBulan = total / tenor;
    document.getElementById('sim-total').textContent    = 'Rp ' + total.toLocaleString('id-ID', {maximumFractionDigits:0});
    document.getElementById('sim-angsuran').textContent = 'Rp ' + perBulan.toLocaleString('id-ID', {maximumFractionDigits:0});
    document.getElementById('sim-margin').textContent   = 'Rp ' + margin.toLocaleString('id-ID', {maximumFractionDigits:0});
    document.getElementById('simulasi-box').style.display = 'block';
}

</script>
<?php $__env->stopPush(); ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\ao\pengajuan\create.blade.php ENDPATH**/ ?>