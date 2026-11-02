
<?php $__env->startSection('page-title', "Detail Deposito"); ?>

<?php $__env->startSection('title', 'Detail Deposito ' . $rekening->no_rekening); ?>

<?php $__env->startSection('sidebar'); ?>
<div class="nav-section">Kelola Anggota</div>
<a href="<?php echo e(route('teller.anggota.index')); ?>" class="nav-item <?php echo e(request()->routeIs('teller.anggota.*') ? 'active' : ''); ?>">
    <span class="icon">👥</span> Data & Daftar Anggota
</a>
<div class="nav-section">Teller</div>
<a href="<?php echo e(route('dashboard.teller')); ?>" class="nav-item">
    <span class="icon">🏠</span> Dashboard
</a>
<a href="<?php echo e(route('teller.setor')); ?>" class="nav-item">
    <span class="icon">💰</span> Setor Tunai
</a>
<a href="<?php echo e(route('teller.tarik')); ?>" class="nav-item">
    <span class="icon">💸</span> Tarik Tunai
</a>
<a href="<?php echo e(route('teller.angsuran')); ?>" class="nav-item">
    <span class="icon">📊</span> Bayar Angsuran
</a>
<a href="<?php echo e(route('teller.deposito')); ?>" class="nav-item">
    <span class="icon">🏦</span> Buka Deposito
</a>
<a href="<?php echo e(route('teller.deposito.index')); ?>" class="nav-item active">
    <span class="icon">📋</span> Daftar Deposito
</a>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    
    <div style="display:flex;align-items:center;gap:.5rem;font-size:.8rem;color:#94a3b8;">
        <a href="<?php echo e(route('teller.deposito.index')); ?>" style="color:#059669;text-decoration:none;font-weight:600;">Daftar Deposito</a>
        <span>/</span>
        <span style="color:#374151;"><?php echo e($rekening->no_rekening); ?></span>
    </div>

    
    <?php if(session('success')): ?>
    <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:.75rem 1rem;border-radius:8px;">
        ✅ <?php echo e(session('success')); ?>

    </div>
    <?php endif; ?>
    <?php if(session('error')): ?>
    <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.75rem 1rem;border-radius:8px;">
        ⚠️ <?php echo e(session('error')); ?>

    </div>
    <?php endif; ?>

    
    <?php
        $statusColor = match($rekening->status_rekening) {
            'Aktif'       => ['bg' => '#d1fae5', 'text' => '#065f46'],
            'Jatuh Tempo' => ['bg' => '#fef3c7', 'text' => '#92400e'],
            'Ditutup'     => ['bg' => '#f1f5f9', 'text' => '#64748b'],
            default       => ['bg' => '#f1f5f9', 'text' => '#64748b'],
        };
        $hariSisa = $rekening->tanggal_jatuh_tempo ? now()->diffInDays($rekening->tanggal_jatuh_tempo, false) : null;
    ?>

    <div style="background:linear-gradient(135deg,#059669,#047857);border-radius:1rem;padding:1.5rem;color:#fff;display:flex;flex-wrap:wrap;gap:1.5rem;justify-content:space-between;align-items:flex-start;">
        <div>
            <div style="font-size:.78rem;opacity:.8;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.25rem;">Simpanan Berjangka / Deposito Mudharabah</div>
            <div style="font-size:1.5rem;font-weight:700;font-family:monospace;"><?php echo e($rekening->no_rekening); ?></div>
            <div style="font-size:1rem;margin-top:.35rem;"><?php echo e($rekening->anggota?->nama_lengkap ?? '—'); ?></div>
            <div style="font-size:.78rem;opacity:.75;margin-top:.1rem;"><?php echo e($rekening->anggota?->no_anggota ?? ''); ?></div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:.78rem;opacity:.8;margin-bottom:.25rem;">Saldo</div>
            <div style="font-size:1.75rem;font-weight:700;">Rp <?php echo e(number_format($rekening->saldo, 0, ',', '.')); ?></div>
            <span style="display:inline-flex;align-items:center;margin-top:.5rem;padding:.25rem .75rem;
                         background:<?php echo e($statusColor['bg']); ?>;color:<?php echo e($statusColor['text']); ?>;
                         font-size:.75rem;font-weight:700;border-radius:9999px;">
                <?php echo e($rekening->status_rekening); ?>

            </span>
        </div>
    </div>

    
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1rem;">

        
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;padding:1.25rem;">
            <h3 style="font-size:.9rem;font-weight:700;color:#1e293b;margin:0 0 1rem;">Informasi Deposito</h3>
            <table style="width:100%;border-collapse:collapse;font-size:.825rem;">
                <tr>
                    <td style="padding:.45rem 0;color:#64748b;width:45%;">Akad</td>
                    <td style="padding:.45rem 0;font-weight:600;color:#374151;"><?php echo e($rekening->akad ?? 'Mudharabah'); ?></td>
                </tr>
                <tr style="border-top:1px solid #f1f5f9;">
                    <td style="padding:.45rem 0;color:#64748b;">Nisbah Nasabah</td>
                    <td style="padding:.45rem 0;font-weight:600;color:#374151;"><?php echo e($rekening->nisbah_nasabah ?? 60); ?>%</td>
                </tr>
                <tr style="border-top:1px solid #f1f5f9;">
                    <td style="padding:.45rem 0;color:#64748b;">Nisbah Koperasi</td>
                    <td style="padding:.45rem 0;font-weight:600;color:#374151;"><?php echo e($rekening->nisbah_bank ?? 40); ?>%</td>
                </tr>
                <tr style="border-top:1px solid #f1f5f9;">
                    <td style="padding:.45rem 0;color:#64748b;">Tanggal Buka</td>
                    <td style="padding:.45rem 0;font-weight:600;color:#374151;"><?php echo e($rekening->tanggal_buka?->format('d M Y') ?? '—'); ?></td>
                </tr>
                <tr style="border-top:1px solid #f1f5f9;">
                    <td style="padding:.45rem 0;color:#64748b;">Jatuh Tempo</td>
                    <td style="padding:.45rem 0;">
                        <span style="font-weight:600;color:#374151;"><?php echo e($rekening->tanggal_jatuh_tempo?->format('d M Y') ?? '—'); ?></span>
                        <?php if($hariSisa !== null && $rekening->status_rekening === 'Aktif'): ?>
                            <?php if($hariSisa <= 7 && $hariSisa >= 0): ?>
                            <span style="font-size:.72rem;color:#d97706;font-weight:600;display:block;">⚠ <?php echo e($hariSisa); ?> hari lagi</span>
                            <?php elseif($hariSisa < 0): ?>
                            <span style="font-size:.72rem;color:#dc2626;font-weight:600;display:block;">Lewat <?php echo e(abs((int)$hariSisa)); ?> hari</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr style="border-top:1px solid #f1f5f9;">
                    <td style="padding:.45rem 0;color:#64748b;">Total Bagi Hasil</td>
                    <td style="padding:.45rem 0;font-weight:600;color:#059669;">Rp <?php echo e(number_format($bagiHasilTotal, 0, ',', '.')); ?></td>
                </tr>
                <?php if($rekening->keterangan): ?>
                <tr style="border-top:1px solid #f1f5f9;">
                    <td style="padding:.45rem 0;color:#64748b;">Keterangan</td>
                    <td style="padding:.45rem 0;color:#374151;"><?php echo e($rekening->keterangan); ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        
        <div style="display:flex;flex-direction:column;gap:.75rem;">

            <?php if(in_array($rekening->status_rekening, ['Aktif', 'Jatuh Tempo'])): ?>
            
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;padding:1.25rem;">
                <h3 style="font-size:.9rem;font-weight:700;color:#1e293b;margin:0 0 .5rem;">Cairkan Deposito</h3>
                <p style="font-size:.78rem;color:#64748b;margin:0 0 .875rem;">
                    Pencairan akan menutup rekening ini dan memposting jurnal PSAK 105 secara otomatis.
                </p>
                <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('transaksi', \App\Models\User::class)): ?>
                <form method="POST" action="<?php echo e(route('teller.deposito.cair', $rekening->id)); ?>"
                      onsubmit="return confirm('Cairkan deposito Rp <?php echo e(number_format($rekening->saldo, 0, ',', '.')); ?>? Tindakan ini tidak dapat dibatalkan.')">
                    <?php echo csrf_field(); ?>
                    <div style="margin-bottom:.625rem;">
                        <label style="display:block;font-size:.76rem;font-weight:600;color:#475569;margin-bottom:.3rem;">Catatan (opsional)</label>
                        <input type="text" name="catatan" placeholder="Misal: Pencairan atas permintaan anggota"
                               style="width:100%;padding:.45rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.8rem;box-sizing:border-box;">
                    </div>
                    <button type="submit"
                            style="width:100%;padding:.55rem;background:#dc2626;color:#fff;font-size:.825rem;font-weight:600;border:none;border-radius:.5rem;cursor:pointer;">
                        Cairkan Sekarang — Rp <?php echo e(number_format($rekening->saldo, 0, ',', '.')); ?>

                    </button>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:.875rem;padding:1.25rem;">
                <h3 style="font-size:.875rem;font-weight:700;color:#1e293b;margin:0 0 .75rem;">Data Anggota</h3>
                <div style="font-size:.825rem;color:#374151;">
                    <div><strong><?php echo e($rekening->anggota?->nama_lengkap ?? '—'); ?></strong></div>
                    <div style="color:#64748b;margin-top:.25rem;"><?php echo e($rekening->anggota?->no_anggota ?? ''); ?></div>
                    <div style="color:#64748b;"><?php echo e($rekening->anggota?->no_hp ?? ''); ?></div>
                    <div style="color:#64748b;"><?php echo e($rekening->anggota?->email ?? ''); ?></div>
                </div>
                <?php if($rekening->anggota): ?>
                <a href="<?php echo e(route('teller.anggota.show', $rekening->anggota->id)); ?>"
                   style="display:inline-block;margin-top:.75rem;font-size:.78rem;color:#059669;font-weight:600;text-decoration:none;">
                    Lihat Profil Anggota →
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;overflow:hidden;">
        <div style="padding:.875rem 1.25rem;border-bottom:1px solid #f1f5f9;">
            <h3 style="font-size:.9rem;font-weight:700;color:#1e293b;margin:0;">Riwayat Mutasi</h3>
        </div>
        <?php if($mutasi->count() > 0): ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                        <th style="padding:.65rem 1.25rem;text-align:left;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;">No. Transaksi</th>
                        <th style="padding:.65rem 1rem;text-align:left;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;">Jenis</th>
                        <th style="padding:.65rem 1rem;text-align:left;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;">Waktu</th>
                        <th style="padding:.65rem 1rem;text-align:right;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;">Jumlah</th>
                        <th style="padding:.65rem 1rem;text-align:right;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;">Saldo Akhir</th>
                        <th style="padding:.65rem 1rem;text-align:center;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__currentLoopData = $mutasi; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $m): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php
                        $isDebet = $m->isMasuk();
                    ?>
                    <tr style="border-bottom:1px solid #f1f5f9;"
                        onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">
                        <td style="padding:.75rem 1.25rem;">
                            <span style="font-family:monospace;font-size:.78rem;color:#7c3aed;"><?php echo e($m->no_transaksi); ?></span>
                        </td>
                        <td style="padding:.75rem 1rem;font-size:.825rem;color:#374151;"><?php echo e($m->jenis_transaksi); ?></td>
                        <td style="padding:.75rem 1rem;font-size:.8rem;color:#64748b;">
                            <?php echo e($m->waktu_transaksi?->format('d M Y H:i')); ?>

                        </td>
                        <td style="padding:.75rem 1rem;text-align:right;">
                            <span style="font-weight:700;color:<?php echo e($isDebet ? '#059669' : '#dc2626'); ?>;">
                                <?php echo e($isDebet ? '+' : '-'); ?>Rp <?php echo e(number_format($m->jumlah, 0, ',', '.')); ?>

                            </span>
                        </td>
                        <td style="padding:.75rem 1rem;text-align:right;font-size:.825rem;color:#374151;">
                            Rp <?php echo e(number_format($m->saldo_setelah, 0, ',', '.')); ?>

                        </td>
                        <td style="padding:.75rem 1rem;text-align:center;">
                            <?php
                                $sc = match(strtolower($m->status_transaksi ?? $m->status ?? '')) {
                                    'sukses','success','berhasil' => ['bg'=>'#d1fae5','text'=>'#065f46','label'=>'Sukses'],
                                    'pending'                     => ['bg'=>'#fef3c7','text'=>'#92400e','label'=>'Pending'],
                                    default                        => ['bg'=>'#f1f5f9','text'=>'#64748b','label'=>ucfirst($m->status_transaksi ?? $m->status ?? '—')],
                                };
                            ?>
                            <span style="display:inline-flex;align-items:center;padding:.2rem .6rem;
                                         background:<?php echo e($sc['bg']); ?>;color:<?php echo e($sc['text']); ?>;
                                         font-size:.72rem;font-weight:600;border-radius:9999px;">
                                <?php echo e($sc['label']); ?>

                            </span>
                        </td>
                    </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="padding:2.5rem;text-align:center;color:#94a3b8;font-size:.85rem;">
            Belum ada mutasi pada rekening ini.
        </div>
        <?php endif; ?>
    </div>

</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/teller/deposito-show.blade.php ENDPATH**/ ?>