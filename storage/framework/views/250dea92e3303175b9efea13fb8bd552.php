
<?php $__env->startSection('page-title', "Ajukan Exit Keanggotaan"); ?>

<?php $__env->startSection('title', 'Ajukan Exit Keanggotaan'); ?>

<?php ($errors = $errors ?? new \Illuminate\Support\ViewErrorBag()); ?>

<?php $__env->startSection('sidebar'); ?>
<div class="nav-section">Exit Keanggotaan</div>
<a href="<?php echo e(route('member-terminations.index')); ?>" class="nav-item <?php echo e(request()->routeIs('member-terminations.index') ? 'active' : ''); ?>">
    <span class="icon">&#128196;</span> Pengunduran Diri
</a>
<a href="<?php echo e(route('member-terminations.create')); ?>" class="nav-item active">
    <span class="icon">&#128221;</span> Ajukan Exit Baru
</a>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<div style="display:flex;flex-direction:column;gap:1.25rem;max-width:820px;">

    <div>
        <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;">Ajukan Pengunduran Diri Anggota</h1>
        <p style="margin:.25rem 0 0;font-size:.83rem;color:#64748b;line-height:1.6;">
            Pengajuan ini membuka proses exit keanggotaan. Pengembalian dana Simpanan Pokok (<code>3-1001</code>) &amp;
            Simpanan Wajib (<code>3-1002</code>) baru dapat dieksekusi setelah seluruh kewajiban anggota lunas.
        </p>
    </div>

    <?php if(session('error')): ?>
    <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.75rem 1rem;border-radius:8px;font-size:.83rem;white-space:pre-line;">
        &#9888; <?php echo e(session('error')); ?>

    </div>
    <?php endif; ?>

    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;padding:1.25rem;">
        <form method="POST" action="<?php echo e(route('member-terminations.store')); ?>" id="form-exit"
              style="display:flex;flex-direction:column;gap:1rem;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="anggota_id" id="anggota_id" value="<?php echo e(old('anggota_id', $anggotaTerpilih->id ?? '')); ?>">

            
            <div style="display:flex;flex-direction:column;gap:.35rem;">
                <label for="cari_anggota" style="font-size:.78rem;font-weight:600;color:#475569;">
                    Cari Anggota (NIK / No. CIF) <span style="color:#dc2626;">*</span>
                </label>

                <div style="position:relative;">
                    <input type="text" id="cari_anggota" autocomplete="off" maxlength="50"
                           placeholder="Contoh: 3273010101010001 atau CIF-20260101-0001"
                           data-initial="<?php echo e($anggotaTerpilih->no_cif ?? $anggotaTerpilih->no_anggota ?? ''); ?>"
                           style="width:100%;padding:.6rem 2.4rem .6rem .7rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.85rem;">
                    <span id="cari-spinner" aria-hidden="true"
                          style="display:none;position:absolute;right:.75rem;top:50%;transform:translateY(-50%);
                                 width:16px;height:16px;border:2px solid #cbd5e1;border-top-color:#047857;
                                 border-radius:50%;animation:spin .7s linear infinite;"></span>
                </div>

                <span style="font-size:.73rem;color:#94a3b8;">
                    Pencarian berjalan otomatis (debounce 300&nbsp;ms). NIK hanya ditampilkan tersamar (UU PDP No. 27/2022).
                </span>
                <?php $__errorArgs = ['anggota_id'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <span style="font-size:.75rem;color:#dc2626;"><?php echo e($message); ?></span> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
            </div>

            
            <div id="hasil-cari" style="display:none;flex-direction:column;gap:.85rem;">

                
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.85rem 1rem;">
                    <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                        <div>
                            <div id="a-nama" style="font-size:.95rem;font-weight:700;color:#0f172a;"></div>
                            <div style="font-size:.78rem;color:#64748b;margin-top:.15rem;">
                                <span id="a-no-anggota"></span> &middot; CIF <span id="a-cif"></span>
                                &middot; NIK <span id="a-nik"></span>
                            </div>
                            <div id="a-alamat" style="font-size:.76rem;color:#94a3b8;margin-top:.2rem;"></div>
                        </div>
                        <div style="text-align:right;">
                            <span id="a-status"
                                  style="display:inline-block;padding:.2rem .6rem;border-radius:999px;background:#e0f2fe;color:#075985;font-size:.72rem;font-weight:600;"></span>
                        </div>
                    </div>
                </div>

                
                <div id="kendala-box" style="display:none;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;
                                             padding:.75rem .9rem;border-radius:8px;font-size:.8rem;line-height:1.6;">
                    <strong style="display:block;margin-bottom:.35rem;">&#9888; Pengunduran diri tidak dapat diproses</strong>
                    <ul id="kendala-list" style="margin:0;padding-left:1.1rem;"></ul>
                </div>

                
                <div id="rincian-box" style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
                    <div style="background:#ecfdf5;padding:.55rem .9rem;font-size:.78rem;font-weight:700;color:#065f46;">
                        Rincian Saldo yang Akan Dicairkan
                    </div>
                    <table style="width:100%;border-collapse:collapse;font-size:.8rem;">
                        <tbody id="rincian-body"></tbody>
                    </table>
                </div>

                
                <div id="approval-box" style="display:none;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;
                                              padding:.7rem .9rem;border-radius:8px;font-size:.79rem;line-height:1.55;">
                    &#9432; Total pencairan melampaui limit transaksi bernilai tinggi. Pengajuan akan berstatus
                    <strong>MENUNGGU PERSETUJUAN MANAJER</strong>; dana baru dapat dicairkan setelah disetujui.
                </div>
            </div>

            
            <div id="pesan-cari" style="display:none;padding:.65rem .85rem;border-radius:8px;font-size:.8rem;line-height:1.5;"></div>

            <div style="display:flex;flex-direction:column;gap:.25rem;">
                <label for="tanggal_pengunduran_diri" style="font-size:.78rem;font-weight:600;color:#475569;">Tanggal Pengunduran Diri</label>
                <input type="date" name="tanggal_pengunduran_diri" id="tanggal_pengunduran_diri"
                       value="<?php echo e(old('tanggal_pengunduran_diri', now()->toDateString())); ?>"
                       style="padding:.55rem .7rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.85rem;">
                <?php $__errorArgs = ['tanggal_pengunduran_diri'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <span style="font-size:.75rem;color:#dc2626;"><?php echo e($message); ?></span> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
            </div>

            <div style="display:flex;flex-direction:column;gap:.25rem;">
                <label for="catatan" style="font-size:.78rem;font-weight:600;color:#475569;">Alasan Pengunduran Diri / Catatan</label>
                <textarea name="catatan" id="catatan" rows="3" maxlength="1000"
                          style="padding:.55rem .7rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.85rem;"><?php echo e(old('catatan')); ?></textarea>
                <?php $__errorArgs = ['catatan'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <span style="font-size:.75rem;color:#dc2626;"><?php echo e($message); ?></span> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
            </div>

            <div style="background:#fef3c7;border:1px solid #fde68a;color:#92400e;padding:.65rem .85rem;border-radius:8px;font-size:.78rem;line-height:1.55;">
                &#9432; Pengajuan akan ditolak otomatis bila anggota masih memiliki pembiayaan aktif, tunggakan
                angsuran/ta&rsquo;zir/denda, tagihan Simpanan Wajib tertunggak, atau menjadi penjamin pembiayaan bermasalah.
            </div>

            <div style="display:flex;gap:.6rem;align-items:center;">
                <button type="submit" id="btn-ajukan" disabled
                        style="padding:.6rem 1.25rem;background:#047857;color:#fff;border:0;border-radius:6px;font-size:.85rem;
                               font-weight:600;cursor:pointer;opacity:.5;">
                    Ajukan Pengunduran Diri
                </button>
                <a href="<?php echo e(route('member-terminations.index')); ?>"
                   style="padding:.6rem 1.25rem;background:#e2e8f0;color:#475569;border-radius:6px;font-size:.85rem;font-weight:600;text-decoration:none;">
                    Batal
                </a>
                <span id="loading-teks" style="display:none;font-size:.78rem;color:#047857;">Memproses&hellip;</span>
            </div>
        </form>
    </div>
</div>

<style>
@keyframes spin { to { transform: translateY(-50%) rotate(360deg); } }
</style>
<?php $__env->stopSection(); ?>

<?php $__env->startPush('scripts'); ?>
<script>
(function () {
    'use strict';

    var input     = document.getElementById('cari_anggota');
    var spinner   = document.getElementById('cari-spinner');
    var hasil     = document.getElementById('hasil-cari');
    var pesan     = document.getElementById('pesan-cari');
    var kendalaBox = document.getElementById('kendala-box');
    var kendalaList = document.getElementById('kendala-list');
    var rincianBox = document.getElementById('rincian-box');
    var rincianBody = document.getElementById('rincian-body');
    var approvalBox = document.getElementById('approval-box');
    var hiddenId  = document.getElementById('anggota_id');
    var btn       = document.getElementById('btn-ajukan');
    var form      = document.getElementById('form-exit');
    var loading   = document.getElementById('loading-teks');

    if (!input || !form) { return; }

    var timer = null;
    var controller = null;
    var url = <?php echo json_encode(route('member-terminations.cari-anggota'), 15, 512) ?>;

    function rupiah(n) {
        return 'Rp ' + new Intl.NumberFormat('id-ID', {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        }).format(Number(n || 0));
    }

    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function setSpinner(aktif) {
        spinner.style.display = aktif ? 'block' : 'none';
    }

    function tampilPesan(teks, jenis) {
        var warna = {
            error: ['#fee2e2', '#fca5a5', '#991b1b'],
            info:  ['#f1f5f9', '#cbd5e1', '#475569'],
            sukses:['#ecfdf5', '#a7f3d0', '#065f46']
        }[jenis] || ['#f1f5f9', '#cbd5e1', '#475569'];

        pesan.style.display = 'block';
        pesan.style.background = warna[0];
        pesan.style.border = '1px solid ' + warna[1];
        pesan.style.color = warna[2];
        pesan.textContent = teks;
    }

    function sembunyikanPesan() { pesan.style.display = 'none'; }

    function kunciTombol(pesanTeks) {
        hiddenId.value = '';
        btn.disabled = true;
        btn.style.opacity = '.5';
        btn.style.cursor = 'not-allowed';
        if (pesanTeks) { tampilPesan(pesanTeks, 'error'); }
    }

    function bukaTombol() {
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
    }

    function resetHasil() {
        hasil.style.display = 'none';
        kendalaBox.style.display = 'none';
        rincianBox.style.display = 'none';
        approvalBox.style.display = 'none';
        kendalaList.innerHTML = '';
        rincianBody.innerHTML = '';
    }

    function renderRincian(r) {
        if (!r) { rincianBox.style.display = 'none'; return; }

        var baris = [
            ['Simpanan Pokok', r.pokok, false],
            ['Simpanan Wajib', r.wajib, false],
            ['Simpanan Sukarela / Wadiah', r.sukarela, false],
            ['Simpanan Berjangka / Deposito', r.deposito, false],
            ['Akrual Hak Bagi Hasil', r.akrual_bagi_hasil, false],
            ['Subtotal Simpanan', r.subtotal_simpanan, true],
            ['Bruto (termasuk akrual)', r.bruto, true],
            ['Potongan Zakat', r.zakat, false],
            ['Biaya Administrasi Penutupan CIF', r.biaya_admin, false]
        ];

        var html = '';
        baris.forEach(function (b) {
            html += '<tr>'
                 + '<td style="padding:.45rem .9rem;border-top:1px solid #f1f5f9;color:#475569;">' + esc(b[0]) + '</td>'
                 + '<td style="padding:.45rem .9rem;border-top:1px solid #f1f5f9;text-align:right;'
                 + (b[1] < 0 ? 'color:#b91c1c;' : 'color:#0f172a;')
                 + (b[2] ? 'font-weight:600;' : '') + '">' + rupiah(b[1]) + '</td>'
                 + '</tr>';
        });

        html += '<tr style="background:#ecfdf5;">'
             + '<td style="padding:.6rem .9rem;font-weight:700;color:#065f46;">TOTAL NET DITERIMA</td>'
             + '<td style="padding:.6rem .9rem;text-align:right;font-weight:700;color:#065f46;">' + rupiah(r.total_net) + '</td>'
             + '</tr>';

        rincianBody.innerHTML = html;
        rincianBox.style.display = 'block';
    }

    function renderHasil(data) {
        resetHasil();
        sembunyikanPesan();

        if (!data.found) {
            kunciTombol(null);
            tampilPesan(data.message || 'Anggota tidak ditemukan.', 'error');
            return;
        }

        var a = data.anggota;
        document.getElementById('a-nama').textContent = a.nama_lengkap || '-';
        document.getElementById('a-no-anggota').textContent = a.no_anggota || '-';
        document.getElementById('a-cif').textContent = a.no_cif || '-';
        document.getElementById('a-nik').textContent = a.nik_masked || '-';
        document.getElementById('a-alamat').textContent = a.alamat || '';
        document.getElementById('a-status').textContent = a.status_label || a.status || '-';

        hasil.style.display = 'flex';

        var kendala = Array.isArray(data.kendala) ? data.kendala : [];
        if (kendala.length) {
            kendala.forEach(function (k) {
                var li = document.createElement('li');
                li.textContent = k;
                kendalaList.appendChild(li);
            });
            kendalaBox.style.display = 'block';
        }

        renderRincian(data.rincian);

        if (data.butuh_approval) {
            approvalBox.style.display = 'block';
        }

        if (!data.boleh_ajukan) {
            hiddenId.value = '';
            kunciTombol(kendala.length
                ? 'Pengunduran diri tidak dapat diproses karena anggota masih memiliki kewajiban / kendala di atas.'
                : 'Pengunduran diri tidak dapat diproses untuk anggota ini.');
            return;
        }

        hiddenId.value = a.id;
        bukaTombol();
        tampilPesan('Anggota ditemukan dan layak diproses. Periksa rincian saldo sebelum mengajukan.', 'sukses');
    }

    function cari(q) {
        if (controller) { controller.abort(); }
        controller = new AbortController();

        setSpinner(true);
        tampilPesan('Memeriksa data anggota, kewajiban, dan rincian saldo…', 'info');
        kunciTombol(null);

        fetch(url + '?q=' + encodeURIComponent(q), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: controller.signal
        })
        .then(function (res) {
            if (res.status === 422) {
                return res.json().then(function (j) {
                    var msg = (j.errors && j.errors.q) ? j.errors.q[0] : 'Input pencarian tidak valid.';
                    throw new Error(msg);
                });
            }
            if (res.status === 403) { throw new Error('Anda tidak berwenang mengakses data anggota.'); }
            if (!res.ok) { throw new Error('Gagal memuat data anggota (HTTP ' + res.status + ').'); }
            return res.json();
        })
        .then(function (data) { renderHasil(data); })
        .catch(function (err) {
            if (err.name === 'AbortError') { return; }
            resetHasil();
            kunciTombol(null);
            tampilPesan(err.message || 'Terjadi kesalahan saat mencari anggota.', 'error');
        })
        .then(function () { setSpinner(false); });
    }

    input.addEventListener('input', function () {
        var q = input.value.trim();

        clearTimeout(timer);
        resetHasil();
        kunciTombol(null);
        hiddenId.value = '';

        if (q.length < 3) {
            setSpinner(false);
            if (q.length === 0) {
                sembunyikanPesan();
            } else {
                tampilPesan('Ketik minimal 3 karakter NIK / No. CIF.', 'info');
            }
            return;
        }

        timer = setTimeout(function () { cari(q); }, 300);
    });

    form.addEventListener('submit', function (e) {
        if (!hiddenId.value) {
            e.preventDefault();
            tampilPesan('Pilih anggota yang layak terlebih dahulu sebelum mengajukan.', 'error');
            return;
        }
        btn.disabled = true;
        btn.style.opacity = '.5';
        loading.style.display = 'inline';
    });

    // Bila halaman dibuka dengan ?anggota_id=... (mis. dari Portofolio CIF),
    // jalankan pencarian otomatis agar validasi tetap berjalan.
    var initial = input.getAttribute('data-initial');
    if (initial) {
        input.value = initial;
        cari(initial);
    }
})();
</script>
<?php $__env->stopPush(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\member-terminations\create.blade.php ENDPATH**/ ?>