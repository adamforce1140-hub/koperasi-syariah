<?php $__env->startSection('title', 'CKPN Piutang Pembiayaan'); ?>
<?php $__env->startSection('page-title', 'CKPN — Cadangan Kerugian Penurunan Nilai Piutang Pembiayaan'); ?>

<?php $__env->startSection('content'); ?>

<?php if(session('success')): ?>
    <div class="alert" style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:.8rem 1.1rem;border-radius:8px;margin-bottom:1rem;"><?php echo e(session('success')); ?></div>
<?php endif; ?>
<?php if(session('error')): ?>
    <div class="alert" style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.8rem 1.1rem;border-radius:8px;margin-bottom:1rem;"><?php echo e(session('error')); ?></div>
<?php endif; ?>
<?php if(session('info')): ?>
    <div class="alert" style="background:#dbeafe;border:1px solid #93c5fd;color:#1e40af;padding:.8rem 1.1rem;border-radius:8px;margin-bottom:1rem;"><?php echo e(session('info')); ?></div>
<?php endif; ?>

<div class="card" style="background:#eff6ff;border:1px solid #bfdbfe;">
    <div style="font-size:.85rem;color:#1e40af;line-height:1.6;">
        <strong>PSAK 71 &amp; PSAK 101.</strong>
        Penyisihan kerugian dihitung dari <em>expected credit loss</em> berbasis tingkat kolektibilitas
        setiap pembiayaan berjalan. Persentase dan ambang hari diambil dari parameter kebijakan koperasi
        (bukan hardcode), sehingga tarif dapat disesuaikan tanpa mengubah kode.
        Penyesuaian diposting otomatis setiap hari pukul 00:01 WIB; tombol di bawah untuk posting manual.
    </div>
</div>


<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue">🎯</div>
        <div>
            <div class="stat-value">Rp <?php echo e(number_format($targetSaldo, 2, ',', '.')); ?></div>
            <div class="stat-label">Target Saldo Penyisihan</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">📒</div>
        <div>
            <div class="stat-value">Rp <?php echo e(number_format($saldoTercatat, 2, ',', '.')); ?></div>
            <div class="stat-label">Saldo Tercatat (<?php echo e(\App\Support\Coa::CKPN_PIUTANG_PEMBIAYAAN); ?>)</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon <?php echo e(abs($selisih) < $toleransi ? 'green' : 'amber'); ?>"><?php echo e(abs($selisih) < $toleransi ? '✅' : '⚠️'); ?></div>
        <div>
            <div class="stat-value">Rp <?php echo e(number_format($selisih, 2, ',', '.')); ?></div>
            <div class="stat-label">Selisih (Target − Tercatat)</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple">📅</div>
        <div>
            <div class="stat-value"><?php echo e($tanggal->format('d/m/Y')); ?></div>
            <div class="stat-label">Tanggal Acuan</div>
        </div>
    </div>
</div>

<div class="card">
    <form method="GET" style="display:flex;gap:1rem;align-items:flex-end;flex-wrap:wrap;">
        <div>
            <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Tanggal Acuan</label>
            <input type="date" name="tanggal" value="<?php echo e($tanggal->toDateString()); ?>" style="padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
        </div>
        <button type="submit" class="btn btn-primary">Hitung Ulang</button>
    </form>

    <?php if(auth()->user()->isPengurus()): ?>
        <form method="POST" action="<?php echo e(route('laporan.ckpn.posting')); ?>" style="margin-top:1rem;"
              onsubmit="return confirm('Posting jurnal penyesuaian CKPN untuk tanggal <?php echo e($tanggal->toDateString()); ?>?');">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="tanggal" value="<?php echo e($tanggal->toDateString()); ?>">
            <button type="submit" class="btn btn-primary"
                    <?php if(abs($selisih) < $toleransi): ?> disabled style="opacity:.55;cursor:not-allowed;" <?php endif; ?>>
                Posting Penyesuaian CKPN
            </button>
            <?php if(abs($selisih) < $toleransi): ?>
                <span style="font-size:.8rem;color:#64748b;margin-left:.75rem;">
                    Selisih berada dalam batas toleransi Rp <?php echo e(number_format($toleransi, 2, ',', '.')); ?> — tidak perlu penyesuaian.
                </span>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</div>


<div class="card">
    <div class="card-header"><div class="card-title">Parameter Kebijakan Penyisihan</div></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1.5rem;">
        <div>
            <div style="font-size:.75rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.5rem;">Persentase Penyisihan</div>
            <table>
                <thead><tr><th>Tingkat</th><th style="text-align:right;">Persentase</th></tr></thead>
                <tbody>
                    <?php $__currentLoopData = $persen; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $kode => $nilai): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr>
                            <td><?php echo e(strtoupper(str_replace('kol', 'Kolektibilitas ', $kode))); ?></td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums;"><?php echo e(number_format((float) $nilai, 2, ',', '.')); ?> %</td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>
        <div>
            <div style="font-size:.75rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.5rem;">Ambang Hari Tunggakan</div>
            <table>
                <thead><tr><th>Tingkat</th><th style="text-align:right;">Mulai Hari Ke-</th></tr></thead>
                <tbody>
                    <?php $__currentLoopData = $ambang; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $kode => $hari): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr>
                            <td><?php echo e(strtoupper(str_replace('kol', 'Kolektibilitas ', $kode))); ?></td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums;"><?php echo e((int) $hari); ?></td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<div class="card">
    <div class="card-header"><div class="card-title">Ringkasan per Tingkat Kolektibilitas</div></div>
    <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>Tingkat</th>
                    <th style="text-align:right;">Jumlah Pembiayaan</th>
                    <th style="text-align:right;">Sisa Pokok</th>
                    <th style="text-align:right;">Persentase</th>
                    <th style="text-align:right;">CKPN</th>
                </tr>
            </thead>
            <tbody>
                <?php $__empty_1 = true; $__currentLoopData = $agregat; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $a): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <tr>
                        <td style="font-weight:600;"><?php echo e(strtoupper(str_replace('kol', 'Kolektibilitas ', $a['kode']))); ?></td>
                        <td style="text-align:right;font-variant-numeric:tabular-nums;"><?php echo e($a['jumlah']); ?></td>
                        <td style="text-align:right;font-variant-numeric:tabular-nums;">Rp <?php echo e(number_format($a['sisa_pokok'], 2, ',', '.')); ?></td>
                        <td style="text-align:right;font-variant-numeric:tabular-nums;"><?php echo e(number_format($a['persen'], 2, ',', '.')); ?> %</td>
                        <td style="text-align:right;font-variant-numeric:tabular-nums;">Rp <?php echo e(number_format($a['ckpn'], 2, ',', '.')); ?></td>
                    </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:2rem;">Tidak ada pembiayaan berjalan.</td></tr>
                <?php endif; ?>
            </tbody>
            <?php if($agregat->isNotEmpty()): ?>
                <tfoot>
                    <tr style="background:#f8fafc;">
                        <td style="font-weight:700;">TOTAL</td>
                        <td style="text-align:right;font-weight:700;"><?php echo e($agregat->sum('jumlah')); ?></td>
                        <td style="text-align:right;font-weight:700;font-variant-numeric:tabular-nums;">Rp <?php echo e(number_format($agregat->sum('sisa_pokok'), 2, ',', '.')); ?></td>
                        <td></td>
                        <td style="text-align:right;font-weight:700;font-variant-numeric:tabular-nums;">Rp <?php echo e(number_format($agregat->sum('ckpn'), 2, ',', '.')); ?></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>


<div class="card">
    <div class="card-header">
        <div class="card-title">Rincian per Pembiayaan</div>
        <span class="badge badge-gray"><?php echo e(count($rincian)); ?> pembiayaan</span>
    </div>
    <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Anggota</th>
                    <th style="text-align:right;">Sisa Pokok</th>
                    <th>Kolektibilitas</th>
                    <th style="text-align:right;">Persentase</th>
                    <th style="text-align:right;">CKPN</th>
                </tr>
            </thead>
            <tbody>
                <?php $__empty_1 = true; $__currentLoopData = $rincian; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <tr>
                        <td style="font-family:monospace;font-size:.8rem;">#<?php echo e($r['pembiayaan_id']); ?></td>
                        <td><?php echo e($r['anggota']); ?></td>
                        <td style="text-align:right;font-variant-numeric:tabular-nums;">Rp <?php echo e(number_format((float) $r['sisa_pokok'], 2, ',', '.')); ?></td>
                        <td>
                            <span class="badge <?php echo e(in_array($r['kolektibilitas'], ['kol1','kol2'], true) ? 'badge-green' : ($r['kolektibilitas'] === 'kol3' ? 'badge-amber' : 'badge-red')); ?>">
                                <?php echo e(strtoupper($r['kolektibilitas'])); ?>

                            </span>
                        </td>
                        <td style="text-align:right;font-variant-numeric:tabular-nums;"><?php echo e(number_format((float) $r['persen'], 2, ',', '.')); ?> %</td>
                        <td style="text-align:right;font-variant-numeric:tabular-nums;">Rp <?php echo e(number_format((float) $r['ckpn'], 2, ',', '.')); ?></td>
                    </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:2rem;">Tidak ada pembiayaan berjalan pada tanggal acuan.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/laporan/ckpn.blade.php ENDPATH**/ ?>