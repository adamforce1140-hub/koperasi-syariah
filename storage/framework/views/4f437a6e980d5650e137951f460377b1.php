<?php $__env->startSection('title', 'Laporan Kepatuhan AML (Anti-Money Laundering)'); ?>
<?php $__env->startSection('page-title', 'Laporan Kepatuhan AML'); ?>

<?php $__env->startSection('content'); ?>
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
                Laporan Kepatuhan AML & High-Value Transaction
            </h1>
            <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
                Monitoring transaksi bernilai besar (&ge; Rp <?php echo e(number_format($threshold, 0, ',', '.')); ?>) dan verifikasi kepatuhan regulasi PPATK.
            </p>
        </div>

        
        <form method="GET" action="<?php echo e(route('pengurus.aml.index')); ?>" style="display:flex;gap:0.5rem;align-items:center;">
            <select name="status_verifikasi" onchange="this.form.submit()"
                    style="padding:0.45rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.825rem;background:#fff;color:#1e293b;outline:none;">
                <option value="">Semua Status Verifikasi</option>
                <option value="menunggu" <?php echo e(request('status_verifikasi') == 'menunggu' ? 'selected' : ''); ?>>Menunggu Dokumen / Verifikasi</option>
                <option value="terverifikasi" <?php echo e(request('status_verifikasi') == 'terverifikasi' ? 'selected' : ''); ?>>Terverifikasi</option>
                <option value="ditolak" <?php echo e(request('status_verifikasi') == 'ditolak' ? 'selected' : ''); ?>>Ditolak</option>
            </select>
        </form>
    </div>

    
    <div class="card" style="padding:1rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;">
            <div style="max-width:38rem;">
                <div style="font-size:0.9rem;font-weight:600;color:#1e293b;">Ekspor Pelaporan PPATK (goAML)</div>
                <div style="font-size:0.78rem;color:#64748b;margin-top:0.15rem;">
                    Unduh berkas CSV siap unggah: <strong>LTKT</strong> (transaksi tunai &ge; Rp <?php echo e(number_format(\App\Support\Setting::nominal('ltkt_threshold', 500000000), 0, ',', '.')); ?>),
                    <strong>LTKM</strong> (transaksi mencurigakan), <strong>STR</strong> (rekap tindak lanjut).
                </div>
            </div>
            <form method="GET" action="<?php echo e(route('pengurus.aml.export', ['jenis' => 'LTKT'])); ?>"
                  style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
                <input type="date" name="dari" value="<?php echo e(request('dari', now()->startOfMonth()->toDateString())); ?>"
                       style="padding:0.45rem 0.6rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.8rem;color:#1e293b;background:#fff;">
                <span style="color:#94a3b8;font-size:0.8rem;">s.d.</span>
                <input type="date" name="sampai" value="<?php echo e(request('sampai', now()->toDateString())); ?>"
                       style="padding:0.45rem 0.6rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.8rem;color:#1e293b;background:#fff;">
                <button type="submit" formaction="<?php echo e(route('pengurus.aml.export', ['jenis' => 'LTKT'])); ?>"
                        style="padding:0.45rem 0.85rem;border:1px solid #2563eb;background:#2563eb;color:#fff;border-radius:0.5rem;font-size:0.8rem;font-weight:600;cursor:pointer;">LTKT</button>
                <button type="submit" formaction="<?php echo e(route('pengurus.aml.export', ['jenis' => 'LTKM'])); ?>"
                        style="padding:0.45rem 0.85rem;border:1px solid #d97706;background:#d97706;color:#fff;border-radius:0.5rem;font-size:0.8rem;font-weight:600;cursor:pointer;">LTKM</button>
                <button type="submit" formaction="<?php echo e(route('pengurus.aml.export', ['jenis' => 'STR'])); ?>"
                        style="padding:0.45rem 0.85rem;border:1px solid #7c3aed;background:#7c3aed;color:#fff;border-radius:0.5rem;font-size:0.8rem;font-weight:600;cursor:pointer;">STR</button>
            </form>
        </div>
    </div>

    <?php if(session('success')): ?>
        <div style="padding:0.75rem 1rem;background:#dcfce7;border:1px solid #86efac;color:#166534;border-radius:0.5rem;font-size:0.875rem;">
            <?php echo e(session('success')); ?>

        </div>
    <?php endif; ?>

    
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;">
        <div class="card" style="padding:1rem;text-align:center;">
            <div style="font-size:1.5rem;font-weight:700;color:#dc2626;"><?php echo e($totalHighValue); ?></div>
            <div style="font-size:0.78rem;color:#64748b;margin-top:0.25rem;">Total High-Value</div>
        </div>
        <div class="card" style="padding:1rem;text-align:center;">
            <div style="font-size:1.5rem;font-weight:700;color:#d97706;"><?php echo e($totalMenunggu); ?></div>
            <div style="font-size:0.78rem;color:#64748b;margin-top:0.25rem;">Menunggu Verifikasi</div>
        </div>
        <div class="card" style="padding:1rem;text-align:center;">
            <div style="font-size:1.5rem;font-weight:700;color:#059669;"><?php echo e($totalVerifikasi); ?></div>
            <div style="font-size:0.78rem;color:#64748b;margin-top:0.25rem;">Terverifikasi</div>
        </div>
        <div class="card" style="padding:1rem;text-align:center;">
            <div style="font-size:1.5rem;font-weight:700;color:#7c3aed;"><?php echo e($totalDitolak); ?></div>
            <div style="font-size:0.78rem;color:#64748b;margin-top:0.25rem;">Ditolak / STR</div>
        </div>
    </div>

    
    <div class="card" style="margin-bottom:0;padding:0;overflow:hidden;">
        <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th>No. Transaksi / Waktu</th>
                        <th>Nasabah</th>
                        <th>Jenis Transaksi</th>
                        <th style="text-align:right;">Nominal Transaksi</th>
                        <th>Sumber Dana</th>
                        <th style="text-align:center;">Status Verifikasi</th>
                        <th style="text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__empty_1 = true; $__currentLoopData = $transaksis; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $t): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <?php
                        $nominal = max($t->jumlah ?? 0, $t->nominal ?? 0);
                        $namaNasabah = $t->anggota->nama_lengkap ?? $t->rekening->anggota->nama_lengkap ?? $t->nama_nasabah ?? 'Anggota';
                        $noAnggota   = $t->anggota->no_anggota ?? $t->rekening->anggota->no_anggota ?? '-';
                    ?>
                    <tr>
                        <td>
                            <div style="font-weight:600;color:#1e293b;"><?php echo e($t->no_transaksi ?? ('TRX-' . $t->id)); ?></div>
                            <span style="font-size:0.75rem;color:#64748b;"><?php echo e(\Carbon\Carbon::parse($t->waktu_transaksi ?? $t->created_at)->format('d/m/Y H:i')); ?></span>
                        </td>
                        <td>
                            <div style="font-weight:600;color:#1e293b;"><?php echo e($namaNasabah); ?></div>
                            <span style="font-size:0.75rem;color:#64748b;">No: <?php echo e($noAnggota); ?></span>
                        </td>
                        <td>
                            <span class="badge badge-blue"><?php echo e($t->jenis_transaksi ?? 'Setoran Tunai'); ?></span>
                        </td>
                        <td style="text-align:right;font-weight:700;color:#dc2626;font-family:monospace;font-size:0.95rem;">
                            Rp <?php echo e(number_format($nominal, 0, ',', '.')); ?>

                        </td>
                        <td>
                            <span style="color:<?php echo e($t->aml_sumber_dana ? '#1e293b' : '#94a3b8'); ?>;font-size:0.85rem;">
                                <?php echo e($t->aml_sumber_dana ?? 'Belum Diisi'); ?>

                            </span>
                        </td>
                        <td style="text-align:center;">
                            <?php if($t->aml_status_verifikasi == 'terverifikasi'): ?>
                                <span class="badge badge-green">Terverifikasi</span>
                            <?php elseif($t->aml_status_verifikasi == 'ditolak'): ?>
                                <span class="badge badge-red">Ditolak</span>
                            <?php else: ?>
                                <span class="badge badge-amber">Menunggu Dokumen</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <button type="button" 
                                onclick='openAmlModal(<?php echo json_encode($t, 15, 512) ?>, "<?php echo e($namaNasabah); ?>", <?php echo e($nominal); ?>)' 
                                class="btn btn-primary btn-sm" style="background:#1e293b;color:#fff;">
                                Verifikasi / Detail
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <tr>
                        <td colspan="7" style="text-align:center;padding:2.5rem;color:#94a3b8;">
                            Tidak ada transaksi bernilai besar (AML High-Value) yang ditemukan.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if($transaksis->hasPages()): ?>
            <div style="padding:1rem;border-top:1px solid #f1f5f9;">
                <?php echo e($transaksis->links()); ?>

            </div>
        <?php endif; ?>
    </div>

</div>


<div id="amlModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);z-index:9999;align-items:center;justify-content:center;padding:1rem;">
    <div class="max-h-[85vh] overflow-y-auto" style="background:#fff;border-radius:1rem;max-width:580px;width:100%;box-shadow:0 20px 25px -5px rgba(0,0,0,0.2);overflow:hidden;max-height:90vh;overflow-y:auto;">
        <div style="padding:1.25rem 1.5rem;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:#fff;z-index:1;">
            <h3 style="font-size:1.1rem;font-weight:700;color:#1e293b;margin:0;">Verifikasi Kepatuhan AML (High-Value)</h3>
            <button type="button" onclick="closeAmlModal()" style="background:none;border:none;font-size:1.5rem;color:#64748b;cursor:pointer;">&times;</button>
        </div>

        <div style="padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
            
            <div style="padding:0.85rem;background:#f8fafc;border-radius:0.5rem;border:1px solid #e2e8f0;">
                <div style="font-size:0.78rem;color:#64748b;margin-bottom:0.25rem;">Informasi Transaksi:</div>
                <div style="font-weight:700;color:#1e293b;font-size:1rem;" id="modalNamaNasabah">-</div>
                <div style="font-size:0.9rem;color:#dc2626;font-weight:700;font-family:monospace;" id="modalNominal">-</div>
                <div style="font-size:0.78rem;color:#64748b;margin-top:0.25rem;" id="modalStatusBadge"></div>
            </div>

            
            <form id="amlForm" method="POST" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <div style="display:flex;flex-direction:column;gap:0.85rem;">
                    <div>
                        <label style="display:block;font-size:0.85rem;font-weight:600;color:#334155;margin-bottom:0.35rem;">
                            Sumber Dana (Source of Funds) <span style="color:#dc2626;">*</span>
                        </label>
                        <input type="text" name="aml_sumber_dana" id="modalSumberDana" required
                               placeholder="Contoh: Hasil Usaha Dagang, Penjualan Aset, Tabungan Pribadi"
                               style="width:100%;padding:0.6rem 0.85rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.875rem;outline:none;box-sizing:border-box;">
                    </div>

                    <div>
                        <label style="display:block;font-size:0.85rem;font-weight:600;color:#334155;margin-bottom:0.35rem;">
                            Unggah Dokumen Pendukung (KTP/NPWP/Bukti Transfer/Akta)
                        </label>
                        <input type="file" name="aml_dokumen" accept=".pdf,.jpg,.jpeg,.png"
                               style="width:100%;padding:0.5rem 0;font-size:0.85rem;color:#475569;">
                        <div id="modalExistingDoc" style="font-size:0.75rem;color:#059669;margin-top:0.25rem;"></div>
                    </div>

                    <div>
                        <label style="display:block;font-size:0.85rem;font-weight:600;color:#334155;margin-bottom:0.35rem;">
                            Catatan Tim Compliance / PPATK
                        </label>
                        <textarea name="aml_catatan" id="modalCatatan" rows="3"
                                  placeholder="Catatan hasil verifikasi..."
                                  style="width:100%;padding:0.6rem 0.85rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.875rem;outline:none;box-sizing:border-box;"></textarea>
                    </div>

                    <input type="hidden" name="aml_status_verifikasi" value="terverifikasi">

                    <div style="display:flex;justify-content:flex-end;gap:0.75rem;padding-top:0.5rem;border-top:1px solid #f1f5f9;">
                        <button type="button" onclick="closeAmlModal()"
                            style="padding:0.55rem 1.2rem;background:#e2e8f0;color:#475569;border:none;border-radius:0.5rem;font-size:0.875rem;cursor:pointer;">
                            Batal
                        </button>
                        <button type="submit"
                            style="padding:0.55rem 1.2rem;background:#059669;color:#fff;border:none;border-radius:0.5rem;font-size:0.875rem;font-weight:600;cursor:pointer;">
                            ✅ Verifikasi & Setujui
                        </button>
                    </div>
                </div>
            </form>

            
            <div style="border-top:2px dashed #fee2e2;padding-top:1rem;">
                <div style="font-size:0.8rem;font-weight:600;color:#dc2626;margin-bottom:0.75rem;">⚠ Zona Penolakan — Transaksi akan ditolak</div>
                <form id="amlTolakForm" method="POST">
                    <?php echo csrf_field(); ?>
                    <div style="display:flex;gap:0.75rem;align-items:flex-end;">
                        <div style="flex:1;">
                            <label style="display:block;font-size:0.82rem;font-weight:600;color:#334155;margin-bottom:0.35rem;">
                                Alasan Penolakan <span style="color:#dc2626;">*</span>
                            </label>
                            <input type="text" name="aml_catatan" required
                                   placeholder="Contoh: Indikasi pencucian uang, dokumen tidak valid..."
                                   style="width:100%;padding:0.55rem 0.75rem;border:1px solid #fca5a5;border-radius:0.5rem;font-size:0.85rem;outline:none;box-sizing:border-box;">
                        </div>
                        <button type="submit" onclick="return confirm('Yakin tolak transaksi ini? Status transaksi akan menjadi Ditolak.')"
                            style="padding:0.55rem 1.1rem;background:#dc2626;color:#fff;border:none;border-radius:0.5rem;font-size:0.875rem;font-weight:600;cursor:pointer;white-space:nowrap;">
                            ❌ Tolak Transaksi
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function openAmlModal(transaksi, namaNasabah, nominal) {
    const modal = document.getElementById('amlModal');

    // Set action form verifikasi
    document.getElementById('amlForm').action = `/pengurus/laporan-aml/${transaksi.id}/verify`;
    // Set action form tolak
    document.getElementById('amlTolakForm').action = `/pengurus/laporan-aml/${transaksi.id}/tolak`;

    document.getElementById('modalNamaNasabah').innerText = namaNasabah + ' (' + (transaksi.no_transaksi || ('TRX-' + transaksi.id)) + ')';
    document.getElementById('modalNominal').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(nominal);

    // Badge status AML
    const statusMap = {
        'menunggu':     '<span style="background:#fef9c3;color:#854d0e;padding:0.15rem 0.5rem;border-radius:9999px;font-size:0.72rem;font-weight:600;">⏳ Menunggu Verifikasi</span>',
        'terverifikasi':'<span style="background:#d1fae5;color:#065f46;padding:0.15rem 0.5rem;border-radius:9999px;font-size:0.72rem;font-weight:600;">✅ Terverifikasi</span>',
        'ditolak':      '<span style="background:#fee2e2;color:#991b1b;padding:0.15rem 0.5rem;border-radius:9999px;font-size:0.72rem;font-weight:600;">❌ Ditolak</span>',
    };
    document.getElementById('modalStatusBadge').innerHTML = statusMap[transaksi.aml_status_verifikasi] || '';

    document.getElementById('modalSumberDana').value = transaksi.aml_sumber_dana || '';
    document.getElementById('modalCatatan').value = transaksi.aml_catatan_compliance || '';

    const docContainer = document.getElementById('modalExistingDoc');
    if (transaksi.aml_dokumen_pendukung) {
        docContainer.innerHTML = `<a href="<?php echo e(url('/berkas')); ?>/${transaksi.aml_dokumen_pendukung}" target="_blank" style="color:#0284c7;text-decoration:underline;">&#128196; Lihat Dokumen Tersimpan</a>`;
    } else {
        docContainer.innerHTML = '';
    }

    // Jika sudah ditolak, disable semua form
    const sudahDitolak = transaksi.aml_status_verifikasi === 'ditolak';
    document.getElementById('amlForm').querySelectorAll('input,textarea,button[type=submit]').forEach(el => el.disabled = sudahDitolak);
    document.getElementById('amlTolakForm').querySelectorAll('input,button').forEach(el => el.disabled = sudahDitolak);

    modal.style.display = 'flex';
}

function closeAmlModal() {
    document.getElementById('amlModal').style.display = 'none';
}
</script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/pengurus/aml/index.blade.php ENDPATH**/ ?>