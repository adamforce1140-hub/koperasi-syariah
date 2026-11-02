<?php $__env->startSection('title', 'Pengesahan Akad — DPS'); ?>
<?php $__env->startSection('page-title', 'Pengesahan Akad Pembiayaan'); ?>

<?php $__env->startSection('content'); ?>
<div style="display:flex;flex-direction:column;gap:1.25rem;">


<?php if(session('success')): ?>
<div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:0.9rem 1.2rem;
            border-radius:8px;display:flex;align-items:center;gap:0.6rem;">
    ✅ <?php echo e(session('success')); ?>

</div>
<?php endif; ?>
<?php if(session('error')): ?>
<div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:0.9rem 1.2rem;
            border-radius:8px;display:flex;align-items:center;gap:0.6rem;">
    ❌ <?php echo e(session('error')); ?>

</div>
<?php endif; ?>
<?php if($errors->any()): ?>
<div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:0.9rem 1.2rem;border-radius:8px;">
    <strong>Gagal menyimpan:</strong>
    <ul style="margin:0.25rem 0 0 1.2rem;padding:0;">
        <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $err): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <li><?php echo e($err); ?></li>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </ul>
</div>
<?php endif; ?>


<div>
    <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;">
        Pengesahan Akad Pembiayaan
    </h1>
    <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
        Daftar pengajuan <strong>pending_dps</strong> — audit kepatuhan syariah dan pengesahan akad.
    </p>
</div>


<div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;
            box-shadow:0 1px 4px rgba(0,0,0,.06);overflow:hidden;">

    <div style="padding:0.875rem 1.25rem;border-bottom:1px solid #f1f5f9;
                font-size:0.8rem;color:#64748b;">
        Menampilkan <strong style="color:#1e293b;"><?php echo e($pembiayaan->total()); ?></strong>
        pengajuan menunggu pengesahan DPS
    </div>

    <?php if($pembiayaan->count()): ?>
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                    <th style="padding:0.75rem 1rem;text-align:left;font-size:0.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">No. Pembiayaan</th>
                    <th style="padding:0.75rem 1rem;text-align:left;font-size:0.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Anggota</th>
                    <th style="padding:0.75rem 1rem;text-align:left;font-size:0.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Akad</th>
                    <th style="padding:0.75rem 1rem;text-align:right;font-size:0.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Jumlah</th>
                    <th style="padding:0.75rem 1rem;text-align:center;font-size:0.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Tenor</th>
                    <th style="padding:0.75rem 1rem;text-align:left;font-size:0.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Catatan Pengurus</th>
                    <th style="padding:0.75rem 1rem;text-align:center;font-size:0.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php $__currentLoopData = $pembiayaan; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $pb): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <tr style="border-bottom:1px solid #f1f5f9;transition:background .15s;"
                    onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">

                    <td style="padding:0.75rem 1rem;">
                        <div style="font-weight:600;color:#1e293b;">
                            <?php echo e($pb->no_pembiayaan ?? 'PMB-' . str_pad($pb->id, 6, '0', STR_PAD_LEFT)); ?>

                        </div>
                        <div style="font-size:0.72rem;color:#94a3b8;">
                            <?php echo e($pb->created_at?->format('d/m/Y')); ?>

                        </div>
                    </td>

                    <td style="padding:0.75rem 1rem;">
                        <div style="font-weight:600;color:#1e293b;"><?php echo e($pb->anggota?->nama_lengkap ?? '-'); ?></div>
                        <div style="font-size:0.72rem;color:#94a3b8;"><?php echo e($pb->anggota?->no_anggota); ?></div>
                    </td>

                    <td style="padding:0.75rem 1rem;">
                        <span style="display:inline-block;padding:0.2rem 0.55rem;border-radius:0.375rem;
                                     background:#f0fdf4;color:#166534;font-size:0.75rem;font-weight:600;">
                            <?php echo e($pb->akad_pembiayaan ?? '-'); ?>

                        </span>
                    </td>

                    <td style="padding:0.75rem 1rem;text-align:right;font-weight:700;color:#065f46;">
                        Rp <?php echo e(number_format($pb->jumlah_pembiayaan, 0, ',', '.')); ?>

                    </td>

                    <td style="padding:0.75rem 1rem;text-align:center;color:#374151;">
                        <?php echo e($pb->tenor); ?> bln
                    </td>

                    <td style="padding:0.75rem 1rem;font-size:0.78rem;color:#64748b;max-width:180px;">
                        <?php echo e(Str::limit($pb->catatan_pengurus, 60) ?? '—'); ?>

                    </td>

                    <td style="padding:0.75rem 1rem;text-align:center;">
                        <button type="button"
                            onclick="showDetailSahkan(<?php echo e($pb->id); ?>)"
                            style="display:inline-flex;align-items:center;gap:0.3rem;
                                   padding:0.35rem 0.8rem;border:1px solid #7c3aed;
                                   background:#f5f3ff;color:#7c3aed;font-size:0.75rem;
                                   font-weight:600;border-radius:0.4rem;cursor:pointer;
                                   transition:all .15s;"
                            onmouseover="this.style.background='#7c3aed';this.style.color='#fff'"
                            onmouseout="this.style.background='#f5f3ff';this.style.color='#7c3aed'">
                            🔍 Detail &amp; Sahkan
                        </button>
                    </td>
                </tr>

                
                <template id="data-<?php echo e($pb->id); ?>">
                    <div data-no="<?php echo e($pb->no_pembiayaan ?? 'PMB-' . str_pad($pb->id, 6, '0', STR_PAD_LEFT)); ?>"
                         data-nama="<?php echo e($pb->anggota?->nama_lengkap); ?>"
                         data-no-anggota="<?php echo e($pb->anggota?->no_anggota); ?>"
                         data-akad="<?php echo e($pb->akad_pembiayaan); ?>"
                         data-jumlah="<?php echo e(number_format($pb->jumlah_pembiayaan, 0, ',', '.')); ?>"
                         data-tenor="<?php echo e($pb->tenor); ?>"
                         data-tujuan="<?php echo e($pb->tujuan_pembiayaan); ?>"
                         data-agunan="<?php echo e($pb->agunan); ?>"
                         data-catatan-manajer="<?php echo e($pb->catatan_manajer); ?>"
                         data-catatan-pengurus="<?php echo e($pb->catatan_pengurus); ?>"
                         data-url-approve="<?php echo e(route('dps.pengesahan-akad.approve', $pb->id)); ?>"
                         data-url-reject="<?php echo e(route('dps.pengesahan-akad.reject', $pb->id)); ?>">
                    </div>
                </template>

                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </tbody>
        </table>
    </div>

    <?php if($pembiayaan->hasPages()): ?>
    <div style="padding:1rem 1.25rem;border-top:1px solid #f1f5f9;">
        <?php echo e($pembiayaan->links()); ?>

    </div>
    <?php endif; ?>

    <?php else: ?>
    <div style="padding:4rem 2rem;text-align:center;">
        <div style="font-size:2.5rem;margin-bottom:1rem;">📋</div>
        <div style="font-size:1rem;font-weight:700;color:#1e293b;margin-bottom:0.375rem;">
            Tidak ada pengajuan yang perlu disahkan
        </div>
        <div style="font-size:0.825rem;color:#94a3b8;">
            Pengajuan yang telah disetujui Pengurus akan muncul di sini.
        </div>
    </div>
    <?php endif; ?>
</div>

</div>


<div id="modal-sahkan"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);
            z-index:1000;align-items:center;justify-content:center;padding:1rem;">
    <div class="max-h-[85vh] overflow-y-auto"
         style="background:#fff;border-radius:1rem;width:100%;max-width:600px;
                max-height:90vh;overflow-y:auto;box-shadow:0 24px 64px rgba(0,0,0,.25);">

        
        <div style="display:flex;align-items:center;justify-content:space-between;
                    padding:1.25rem 1.5rem;border-bottom:1px solid #e2e8f0;position:sticky;top:0;
                    background:#fff;z-index:1;">
            <div>
                <div style="font-size:1.05rem;font-weight:700;color:#1e293b;">Detail & Pengesahan Akad</div>
                <div style="font-size:0.78rem;color:#64748b;" id="modal-no-pembiayaan">—</div>
            </div>
            <button onclick="closeModalSahkan()"
                style="width:32px;height:32px;border:none;background:#f1f5f9;border-radius:50%;
                       cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center;">
                ✕
            </button>
        </div>

        
        <div style="padding:1.25rem 1.5rem;border-bottom:1px solid #f1f5f9;">
            <div style="font-size:0.72rem;font-weight:700;color:#94a3b8;text-transform:uppercase;
                        letter-spacing:.05em;margin-bottom:0.75rem;">Data Pembiayaan</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                <div>
                    <div style="font-size:0.72rem;color:#94a3b8;">Anggota</div>
                    <div style="font-weight:600;font-size:0.875rem;color:#1e293b;" id="modal-nama">—</div>
                    <div style="font-size:0.72rem;color:#64748b;" id="modal-no-anggota">—</div>
                </div>
                <div>
                    <div style="font-size:0.72rem;color:#94a3b8;">Akad Pembiayaan</div>
                    <div style="font-weight:600;font-size:0.875rem;color:#1e293b;" id="modal-akad">—</div>
                </div>
                <div>
                    <div style="font-size:0.72rem;color:#94a3b8;">Jumlah Pembiayaan</div>
                    <div style="font-weight:700;font-size:1rem;color:#065f46;" id="modal-jumlah">—</div>
                </div>
                <div>
                    <div style="font-size:0.72rem;color:#94a3b8;">Tenor</div>
                    <div style="font-weight:600;font-size:0.875rem;color:#1e293b;" id="modal-tenor">—</div>
                </div>
                <div>
                    <div style="font-size:0.72rem;color:#94a3b8;">Tujuan</div>
                    <div style="font-size:0.8rem;color:#374151;" id="modal-tujuan">—</div>
                </div>
                <div>
                    <div style="font-size:0.72rem;color:#94a3b8;">Agunan</div>
                    <div style="font-size:0.8rem;color:#374151;" id="modal-agunan">—</div>
                </div>
            </div>
        </div>

        
        <div style="padding:1rem 1.5rem;border-bottom:1px solid #f1f5f9;display:flex;flex-direction:column;gap:0.5rem;">
            <div id="wrap-catatan-manajer" style="background:#eff6ff;border-radius:0.5rem;padding:0.625rem 0.875rem;">
                <div style="font-size:0.7rem;font-weight:600;color:#1d4ed8;margin-bottom:0.2rem;">Catatan Manajer</div>
                <div style="font-size:0.8rem;color:#1e3a8a;" id="modal-catatan-manajer">—</div>
            </div>
            <div id="wrap-catatan-pengurus" style="background:#f0fdf4;border-radius:0.5rem;padding:0.625rem 0.875rem;">
                <div style="font-size:0.7rem;font-weight:600;color:#166534;margin-bottom:0.2rem;">Catatan Pengurus</div>
                <div style="font-size:0.8rem;color:#14532d;" id="modal-catatan-pengurus">—</div>
            </div>
        </div>

        
        <form id="form-sahkan" method="POST" style="padding:1.25rem 1.5rem;">
            <?php echo csrf_field(); ?>
            <div style="font-size:0.72rem;font-weight:700;color:#94a3b8;text-transform:uppercase;
                        letter-spacing:.05em;margin-bottom:0.875rem;">Pengesahan DPS</div>

            
                    <div style="margin-bottom:1rem;">
                        <label style="display:block;font-size:0.82rem;font-weight:600;color:#374151;margin-bottom:0.375rem;">
                            Tanggal Rencana Pencairan <span style="color:#e11d48;">*</span>
                        </label>
                        <input type="date" name="tgl_pencairan" id="input-tgl-pencairan"
                            value="<?php echo e(date('Y-m-d')); ?>"
                            required
                            style="width:100%;padding:0.625rem 0.75rem;border:1px solid #d1d5db;
                                   border-radius:0.5rem;font-size:0.875rem;box-sizing:border-box;
                                   color:#1e293b;">
                        <div style="font-size:0.72rem;color:#64748b;margin-top:0.25rem;">
                            Tanggal ini akan digunakan sebagai kendali pencairan oleh Teller.
                        </div>
                    </div>

            
            <div style="margin-bottom:1.25rem;">
                <label style="display:block;font-size:0.82rem;font-weight:600;color:#374151;margin-bottom:0.375rem;">
                    Catatan / Rekomendasi DPS
                </label>
                <textarea name="catatan_dps" id="input-catatan-dps" rows="3"
                    placeholder="Tuliskan catatan atau rekomendasi syariah (opsional)..."
                    style="width:100%;padding:0.625rem 0.75rem;border:1px solid #d1d5db;
                           border-radius:0.5rem;font-size:0.875rem;box-sizing:border-box;
                           resize:vertical;"></textarea>
            </div>

            
            <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
                <button type="button" onclick="closeModalSahkan()"
                    style="flex:1;min-width:120px;padding:0.75rem;border:1px solid #e2e8f0;
                           border-radius:8px;background:#fff;color:#64748b;
                           font-weight:600;cursor:pointer;font-size:0.875rem;">
                    Batal
                </button>
                <button type="submit" id="btn-sahkan"
                    style="flex:2;min-width:160px;padding:0.75rem;border:none;
                           border-radius:8px;background:#7c3aed;color:#fff;
                           font-weight:700;cursor:pointer;font-size:0.875rem;">
                    ✅ Sahkan Akad & Tetapkan Tgl Cair
                </button>
                <button type="button" onclick="submitTolak()"
                    style="flex:1;min-width:100px;padding:0.75rem;border:1px solid #e11d48;
                           border-radius:8px;background:#fff;color:#e11d48;
                           font-weight:600;cursor:pointer;font-size:0.875rem;">
                    ❌ Tolak
                </button>
            </div>
        </form>

        
        <form id="form-tolak" method="POST" style="display:none;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="catatan_dps" id="tolak-catatan-dps">
        </form>

    </div>
</div>

<?php $__env->stopSection(); ?>

<?php $__env->startPush('scripts'); ?>
<script>
// ── Buka modal pengesahan ──────────────────────────────────────────
function showDetailSahkan(id) {
    const tpl  = document.getElementById('data-' + id);
    if (!tpl) return;
    const data = tpl.content.firstElementChild.dataset;

    document.getElementById('modal-no-pembiayaan').textContent   = data.no;
    document.getElementById('modal-nama').textContent             = data.nama          || '—';
    document.getElementById('modal-no-anggota').textContent       = data.noAnggota     || '—';
    document.getElementById('modal-akad').textContent             = data.akad          || '—';
    document.getElementById('modal-jumlah').textContent           = 'Rp ' + data.jumlah;
    document.getElementById('modal-tenor').textContent            = data.tenor + ' bulan';
    document.getElementById('modal-tujuan').textContent           = data.tujuan        || '—';
    document.getElementById('modal-agunan').textContent           = data.agunan        || '—';
    document.getElementById('modal-catatan-manajer').textContent  = data.catatanManajer  || '—';
    document.getElementById('modal-catatan-pengurus').textContent = data.catatanPengurus || '—';

    // Set action URL untuk kedua form
    document.getElementById('form-sahkan').action = data.urlApprove;
    document.getElementById('form-tolak').action  = data.urlReject;

    // Reset input
    document.getElementById('input-tgl-pencairan').value = '';
    document.getElementById('input-catatan-dps').value   = '';

    document.getElementById('modal-sahkan').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

// ── Tutup modal ───────────────────────────────────────────────────
function closeModalSahkan() {
    document.getElementById('modal-sahkan').style.display = 'none';
    document.body.style.overflow = '';
}

// ── Submit penolakan ──────────────────────────────────────────────
function submitTolak() {
    const catatan = document.getElementById('input-catatan-dps').value.trim();
    if (!catatan) {
        alert('Isi catatan/alasan penolakan terlebih dahulu.');
        document.getElementById('input-catatan-dps').focus();
        return;
    }
    if (!confirm('Yakin ingin MENOLAK pengajuan ini? Tindakan tidak dapat dibatalkan.')) return;
    document.getElementById('tolak-catatan-dps').value = catatan;
    document.getElementById('form-tolak').submit();
}

// ── Tutup dengan Escape ───────────────────────────────────────────
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModalSahkan();
});

// ── Tutup saat klik backdrop ──────────────────────────────────────
document.getElementById('modal-sahkan').addEventListener('click', function(e) {
    if (e.target === this) closeModalSahkan();
});
</script>
<?php $__env->stopPush(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/dps/pengesahan-akad.blade.php ENDPATH**/ ?>