<?php $__env->startSection('title', 'Keringanan Penalti Deposito — Teller'); ?>
<?php $__env->startSection('page-title', 'Pengajuan Keringanan Penalti Deposito'); ?>

<?php $__env->startSection('content'); ?>
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
                Pengajuan Keringanan Penalti Deposito
            </h1>
            <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
                Penalti pencairan dini deposito adalah <strong>ta'zir</strong> (dana sosial, bukan pendapatan koperasi).
                Anggota dapat mengajukan keringanan — alur berjenjang Teller → AO → Manajer.
            </p>
        </div>
        <a href="<?php echo e(route('teller.keringanan-penalti.index')); ?>"
           style="padding:0.55rem 1.1rem;background:#f1f5f9;color:#334155;border-radius:0.5rem;
                  font-size:0.83rem;font-weight:600;text-decoration:none;white-space:nowrap;">
            Riwayat Pengajuan →
        </a>
    </div>

    
    <?php $__currentLoopData = ['success'=>['#ecfdf5','#a7f3d0','#065f46','✅'], 'error'=>['#fef2f2','#fecaca','#991b1b','❌'], 'warning'=>['#fffbeb','#fde68a','#92400e','⚠️']]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $type=>$s): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <?php if(session($type)): ?>
    <div style="padding:0.875rem 1.25rem;background:<?php echo e($s[0]); ?>;border:1px solid <?php echo e($s[1]); ?>;border-radius:0.625rem;color:<?php echo e($s[2]); ?>;font-size:0.875rem;display:flex;align-items:center;gap:0.5rem;">
        <span><?php echo e($s[3]); ?></span> <?php echo e(session($type)); ?>

    </div>
    <?php endif; ?>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    <?php if($errors->any()): ?>
    <div style="padding:0.875rem 1.25rem;background:#fef2f2;border:1px solid #fecaca;border-radius:0.625rem;color:#991b1b;font-size:0.875rem;">
        <ul style="margin:0;padding-left:1.1rem;">
            <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $err): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?> <li><?php echo e($err); ?></li> <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </ul>
    </div>
    <?php endif; ?>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem 1.5rem;">
        <div style="font-size:0.95rem;font-weight:700;color:#1e293b;margin-bottom:0.75rem;">
            Langkah 1 — Pilih Anggota
        </div>
        <form method="GET" action="<?php echo e(route('teller.keringanan-penalti.create')); ?>"
              style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:flex-end;">
            <div style="flex:1;min-width:240px;">
                <label for="anggota_id" style="display:block;font-size:0.8rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                    Nomor / Nama Anggota
                </label>
                <input type="number" name="anggota_id" id="anggota_id" required
                       value="<?php echo e(old('anggota_id', $anggota->id ?? '')); ?>"
                       placeholder="Masukkan ID anggota"
                       style="width:100%;padding:0.6rem 0.8rem;border:1px solid #d1d5db;border-radius:0.5rem;font-size:0.875rem;">
                <div style="font-size:0.75rem;color:#94a3b8;margin-top:0.3rem;">
                    ID internal anggota dapat dilihat pada halaman Data Anggota.
                </div>
            </div>
            <button type="submit"
                    style="padding:0.6rem 1.25rem;background:#2563eb;color:#fff;border:none;border-radius:0.5rem;
                           font-size:0.85rem;font-weight:600;cursor:pointer;">
                Tampilkan Bilyet Deposito
            </button>
        </form>
    </div>

    <?php if($anggota): ?>
    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem 1.5rem;">
        <div style="font-size:0.95rem;font-weight:700;color:#1e293b;margin-bottom:0.25rem;">
            Langkah 2 — Pilih Bilyet &amp; Ajukan Keringanan
        </div>
        <div style="font-size:0.825rem;color:#64748b;margin-bottom:1rem;">
            Anggota: <strong><?php echo e($anggota->no_anggota); ?></strong> — <?php echo e($anggota->nama_lengkap); ?>

        </div>

        <?php if($deposito->isEmpty()): ?>
        <div style="padding:2.5rem;text-align:center;color:#94a3b8;background:#f8fafc;border-radius:0.625rem;">
            <div style="font-size:1.75rem;margin-bottom:0.5rem;">📭</div>
            <div style="font-weight:600;color:#64748b;">Tidak ada bilyet deposito aktif</div>
            <div style="font-size:0.825rem;margin-top:0.25rem;">
                Keringanan hanya dapat diajukan atas deposito berstatus AKTIF.
            </div>
        </div>
        <?php else: ?>
        <form method="POST" action="<?php echo e(route('teller.keringanan-penalti.store')); ?>">
            <?php echo csrf_field(); ?>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
                    <thead>
                        <tr style="background:#f8fafc;text-align:left;">
                            <th style="padding:0.6rem 0.75rem;font-size:0.75rem;color:#64748b;font-weight:600;"></th>
                            <th style="padding:0.6rem 0.75rem;font-size:0.75rem;color:#64748b;font-weight:600;">No. Bilyet</th>
                            <th style="padding:0.6rem 0.75rem;font-size:0.75rem;color:#64748b;font-weight:600;">Produk</th>
                            <th style="padding:0.6rem 0.75rem;font-size:0.75rem;color:#64748b;font-weight:600;text-align:right;">Pokok</th>
                            <th style="padding:0.6rem 0.75rem;font-size:0.75rem;color:#64748b;font-weight:600;text-align:right;">Estimasi Penalti</th>
                            <th style="padding:0.6rem 0.75rem;font-size:0.75rem;color:#64748b;font-weight:600;">Jatuh Tempo</th>
                            <th style="padding:0.6rem 0.75rem;font-size:0.75rem;color:#64748b;font-weight:600;">Pengajuan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $__currentLoopData = $deposito; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $d): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <?php
                            $penalti = $d->product ? $d->product->hitungPenalti((float) $d->principal_amount) : 0;
                            $sudahDiajukan = $d->depositoSudahDiajukanKeringanan();
                            $keringananSiap = $d->keringananSiapDipakai();
                        ?>
                        <tr style="border-top:1px solid #f1f5f9;">
                            <td style="padding:0.6rem 0.75rem;">
                                <input type="radio" name="deposito_id" value="<?php echo e($d->id); ?>" required
                                       <?php echo e($sudahDiajukan || $keringananSiap ? 'disabled' : ''); ?>>
                            </td>
                            <td style="padding:0.6rem 0.75rem;font-weight:600;color:#1e293b;"><?php echo e($d->deposito_number); ?></td>
                            <td style="padding:0.6rem 0.75rem;color:#475569;"><?php echo e($d->product?->name ?? '—'); ?></td>
                            <td style="padding:0.6rem 0.75rem;text-align:right;font-variant-numeric:tabular-nums;">
                                Rp <?php echo e(number_format((float) $d->principal_amount, 0, ',', '.')); ?>

                            </td>
                            <td style="padding:0.6rem 0.75rem;text-align:right;font-variant-numeric:tabular-nums;color:#b45309;font-weight:600;">
                                Rp <?php echo e(number_format((float) $penalti, 0, ',', '.')); ?>

                            </td>
                            <td style="padding:0.6rem 0.75rem;color:#475569;">
                                <?php echo e($d->maturity_date ? \Carbon\Carbon::parse($d->maturity_date)->format('d/m/Y') : '—'); ?>

                            </td>
                            <td style="padding:0.6rem 0.75rem;">
                                <?php if($keringananSiap): ?>
                                    <span style="padding:0.2rem 0.55rem;border-radius:12px;font-size:0.72rem;font-weight:600;background:#dcfce7;color:#166534;">
                                        Keringanan siap dipakai
                                    </span>
                                <?php elseif($sudahDiajukan): ?>
                                    <span style="padding:0.2rem 0.55rem;border-radius:12px;font-size:0.72rem;font-weight:600;background:#fef3c7;color:#92400e;">
                                        Sedang diproses
                                    </span>
                                <?php else: ?>
                                    <span style="color:#cbd5e1;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top:1.25rem;">
                <label for="alasan_pengaju" style="display:block;font-size:0.8rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                    Alasan Pengajuan Keringanan <span style="color:#dc2626;">*</span>
                </label>
                <textarea name="alasan_pengaju" id="alasan_pengaju" rows="4" required maxlength="1000"
                          placeholder="Contoh: anggota sakit keras dan membutuhkan dana untuk biaya pengobatan; pencairan dini tidak dapat dihindari."
                          style="width:100%;padding:0.6rem 0.8rem;border:1px solid #d1d5db;border-radius:0.5rem;
                                 font-size:0.875rem;font-family:inherit;resize:vertical;"><?php echo e(old('alasan_pengaju')); ?></textarea>
                <div style="font-size:0.75rem;color:#94a3b8;margin-top:0.3rem;">
                    Jelaskan kondisi force majeure / kebutuhan mendesak anggota. Alasan ini menjadi pertimbangan AO &amp; Manajer.
                </div>
            </div>

            <div style="margin-top:1.25rem;display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
                <button type="submit"
                        style="padding:0.65rem 1.5rem;background:#2563eb;color:#fff;border:none;border-radius:0.5rem;
                               font-size:0.875rem;font-weight:600;cursor:pointer;">
                    Ajukan Keringanan ke AO
                </button>
                <span style="font-size:0.78rem;color:#64748b;">
                    Bilyet yang sudah memiliki pengajuan berjalan tidak dapat diajukan ulang.
                </span>
            </div>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:0.875rem;padding:1.1rem 1.4rem;">
        <div style="font-size:0.875rem;font-weight:700;color:#166534;margin-bottom:0.35rem;">
            Ketentuan Syariah Penalti Deposito
        </div>
        <ul style="margin:0;padding-left:1.1rem;font-size:0.825rem;color:#14532d;line-height:1.7;">
            <li>Penalti pencairan dini merupakan <strong>ta'zir</strong> yang dibukukan ke akun <strong>Dana Kebajikan</strong> — bukan pendapatan koperasi (Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000).</li>
            <li>Keringanan hanya berlaku bila <strong>disetujui Manajer</strong>, dan <strong>dipakai satu kali</strong> pada saat pencairan dini dieksekusi.</li>
            <li>Nilai keringanan tidak pernah melebihi nilai penalti awal dan tidak boleh mengurangi pokok deposito.</li>
        </ul>
    </div>

</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\teller\keringanan-penalti-deposito\create.blade.php ENDPATH**/ ?>