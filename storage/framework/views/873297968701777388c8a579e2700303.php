<?php $__env->startSection('title', 'Detail Pembiayaan — Manajer'); ?>
<?php $__env->startSection('page-title', 'Detail Pembiayaan'); ?>

<?php $__env->startSection('content'); ?>

<?php
    $statusLabel = match($item->status_pembiayaan ?? '') {
        'pending_ao'                 => 'Menunggu AO',
        'pending_manajer'            => 'Menunggu Manajer',
        'pending_pengurus'           => 'Menunggu Pengurus',
        'pending_dps'                => 'Menunggu DPS',
        'direview'                   => 'Direview',
        'disetujui'                  => 'Disetujui',
        'disahkan'                   => 'Disahkan DPS',
        'pending_pencairan'          => 'Menunggu Pencairan',
        'dicairkan'                  => 'Dicairkan',
        'aktif'                      => 'Aktif',
        'restrukturisasi'            => 'Restrukturisasi',
        'pending_restrukturisasi'    => 'Menunggu Approval Restrukturisasi',
        'lunas'                      => 'Lunas',
        'macet'                      => 'Macet',
        'ditolak'                    => 'Ditolak',
        'dibatalkan'                 => 'Dibatalkan',
        default                      => ucfirst($item->status_pembiayaan ?? '-'),
    };
    $statusStyle = match($item->status_pembiayaan ?? '') {
        'disetujui', 'disahkan'      => 'background:#d1fae5;color:#065f46;',
        'dicairkan', 'aktif'         => 'background:#e0f2fe;color:#0369a1;',
        'restrukturisasi'            => 'background:#fef3c7;color:#92400e;',
        'pending_restrukturisasi'    => 'background:#ede9fe;color:#6d28d9;',
        'lunas'                      => 'background:#d1fae5;color:#065f46;',
        'macet', 'ditolak',
        'dibatalkan'                 => 'background:#fee2e2;color:#991b1b;',
        default                      => 'background:#fef3c7;color:#92400e;',
    };

    $anggota = $item->anggota;
    $progress = $item->persentase_terbayar;
?>


<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1.25rem;flex-wrap:wrap;gap:1rem;">
    <div>
        <a href="<?php echo e(route('manajer.npf.index')); ?>"
           style="color:#dc2626;text-decoration:none;font-size:0.85rem;">
            ← Kembali ke NPF
        </a>
        <h1 style="font-size:1.3rem;font-weight:700;color:#1e293b;margin:0.3rem 0 0;">
            Detail Pembiayaan
        </h1>
        <p style="color:#94a3b8;font-size:0.85rem;margin:0.15rem 0 0;">
            <?php echo e($item->no_pembiayaan ?? ('PMB-' . str_pad($item->id, 6, '0', STR_PAD_LEFT))); ?>

            &middot; Akad <?php echo e($item->tanggal_akad?->format('d/m/Y') ?? '-'); ?>

        </p>
    </div>
    <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
        <?php if($kolektibilitas): ?>
        <span style="padding:0.35rem 1rem;border-radius:9999px;font-size:0.8rem;font-weight:700;background:#fee2e2;color:#991b1b;">
            <?php echo e($kolektibilitas); ?>

        </span>
        <?php endif; ?>
        <span style="padding:0.35rem 1rem;border-radius:9999px;font-size:0.8rem;font-weight:700;<?php echo e($statusStyle); ?>">
            <?php echo e($statusLabel); ?>

        </span>
    </div>
</div>


<?php if($hariTunggakan > 0): ?>
<div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:0.75rem;padding:0.875rem 1.25rem;
            display:flex;align-items:center;gap:0.75rem;margin-bottom:1.25rem;">
    <span style="font-size:1.25rem;">⚠️</span>
    <div style="font-size:0.85rem;color:#9a3412;">
        Menunggak <strong><?php echo e($hariTunggakan); ?> hari</strong> sejak
        <?php echo e($angsuranTunggakan->first()?->tanggal_jatuh_tempo?->format('d/m/Y') ?? '-'); ?>

        &middot; Total tunggakan <strong>Rp <?php echo e(number_format($totalTunggakan, 0, ',', '.')); ?></strong>
        (<?php echo e($angsuranTunggakan->count()); ?> angsuran).
    </div>
</div>
<?php endif; ?>


<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">

    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;">
        <h3 style="font-size:0.875rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin:0 0 1rem;">
            Data Anggota
        </h3>
        <?php $__currentLoopData = [
            'Nama Lengkap' => $anggota?->nama_lengkap,
            'No. Anggota'  => $anggota?->no_anggota,
            'NIK'          => $anggota?->nik,
            'No. HP'       => $anggota?->no_hp,
            'Alamat'       => $anggota?->alamat,
        ]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $label => $value): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <div style="display:flex;justify-content:space-between;padding:0.45rem 0;border-bottom:1px solid #f1f5f9;font-size:0.825rem;">
            <span style="color:#64748b;"><?php echo e($label); ?></span>
            <span style="color:#1e293b;font-weight:500;text-align:right;max-width:60%;"><?php echo e($value ?? '—'); ?></span>
        </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>

    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;">
        <h3 style="font-size:0.875rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin:0 0 1rem;">
            Detail Pembiayaan
        </h3>
        <?php $__currentLoopData = [
            'Akad'          => $item->akad_pembiayaan,
            'Produk'        => $item->produk?->nama_produk,
            'Jumlah'        => 'Rp ' . number_format($item->jumlah_pembiayaan ?? 0, 0, ',', '.'),
            'Margin/Nisbah' => ($item->margin_nisbah ?? '—') . '%',
            'Total'         => 'Rp ' . number_format($item->total_pembiayaan ?? 0, 0, ',', '.'),
            'Angsuran/Bln'  => 'Rp ' . number_format($item->jumlah_angsuran ?? 0, 0, ',', '.'),
            'Tenor'         => ($item->tenor ?? '—') . ' bulan',
            'Tujuan'        => $item->tujuan_pembiayaan,
            'Agunan'        => $item->agunan,
        ]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $label => $value): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <div style="display:flex;justify-content:space-between;padding:0.45rem 0;border-bottom:1px solid #f1f5f9;font-size:0.825rem;">
            <span style="color:#64748b;"><?php echo e($label); ?></span>
            <span style="color:#1e293b;font-weight:500;text-align:right;max-width:60%;"><?php echo e($value ?? '—'); ?></span>
        </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>

</div>


<div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;margin-top:1.25rem;">
    <h3 style="font-size:0.875rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin:0 0 1rem;">
        Posisi Piutang
    </h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1rem;font-size:0.825rem;">
        <div>
            <div style="color:#64748b;">Sisa Pembiayaan</div>
            <div style="color:#dc2626;font-weight:700;font-size:1rem;">
                Rp <?php echo e(number_format($item->sisa_pembiayaan ?? 0, 0, ',', '.')); ?>

            </div>
        </div>
        <div>
            <div style="color:#64748b;">Total Tunggakan</div>
            <div style="color:#dc2626;font-weight:700;font-size:1rem;">
                Rp <?php echo e(number_format($totalTunggakan, 0, ',', '.')); ?>

            </div>
        </div>
        <div>
            <div style="color:#64748b;">Angsuran Terbayar</div>
            <div style="color:#1e293b;font-weight:700;font-size:1rem;">
                <?php echo e($item->angsuran_sudah_bayar); ?> / <?php echo e($item->jadwalAngsuran->count()); ?> kali
            </div>
        </div>
        <div>
            <div style="color:#64748b;">Progres</div>
            <div style="color:#1e293b;font-weight:700;font-size:1rem;"><?php echo e($progress); ?>%</div>
        </div>
    </div>
    <div style="margin-top:0.875rem;height:8px;background:#f1f5f9;border-radius:9999px;overflow:hidden;">
        <div style="height:100%;width:<?php echo e($progress); ?>%;background:#3b82f6;"></div>
    </div>
</div>


<div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;margin-top:1.25rem;">
    <h3 style="font-size:0.875rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin:0 0 1rem;">
        Riwayat Persetujuan
    </h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;font-size:0.825rem;">
        <div>
            <div style="color:#64748b;">Direview</div>
            <div style="color:#1e293b;font-weight:600;">
                <?php echo e($item->reviewedBy?->name ?? '—'); ?>

                <span style="color:#94a3b8;font-weight:400;">
                    <?php echo e($item->tanggal_direview ? '· ' . \Carbon\Carbon::parse($item->tanggal_direview)->format('d/m/Y') : ''); ?>

                </span>
            </div>
        </div>
        <div>
            <div style="color:#64748b;">Disetujui</div>
            <div style="color:#1e293b;font-weight:600;">
                <?php echo e($item->approvedFinalBy?->name ?? '—'); ?>

                <span style="color:#94a3b8;font-weight:400;">
                    <?php echo e($item->tanggal_disetujui ? '· ' . \Carbon\Carbon::parse($item->tanggal_disetujui)->format('d/m/Y') : ''); ?>

                </span>
            </div>
        </div>
        <div>
            <div style="color:#64748b;">Disahkan DPS</div>
            <div style="color:#1e293b;font-weight:600;">
                <?php echo e($item->disahkanOleh?->name ?? '—'); ?>

                <span style="color:#94a3b8;font-weight:400;">
                    <?php echo e($item->tanggal_disahkan ? '· ' . \Carbon\Carbon::parse($item->tanggal_disahkan)->format('d/m/Y') : ''); ?>

                </span>
            </div>
        </div>
        <div>
            <div style="color:#64748b;">Pencairan</div>
            <div style="color:#1e293b;font-weight:600;">
                <?php echo e($item->tgl_pencairan?->format('d/m/Y') ?? '—'); ?>

            </div>
        </div>
    </div>
    <?php if($item->keterangan): ?>
    <div style="margin-top:1rem;padding:0.75rem;background:#f8fafc;border-radius:0.5rem;font-size:0.825rem;color:#475569;">
        <strong>Catatan:</strong> <?php echo e($item->keterangan); ?>

    </div>
    <?php endif; ?>
</div>


<?php if($item->restrukturisasiRequests->count()): ?>
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;margin-top:1.25rem;">
    <h3 style="font-size:0.875rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin:0 0 1rem;">
        Riwayat Restrukturisasi
    </h3>
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                    <th style="padding:0.625rem 0.875rem;text-align:left;font-weight:600;color:#475569;">Skema</th>
                    <th style="padding:0.625rem 0.875rem;text-align:right;font-weight:600;color:#475569;">Piutang Saat Ajuan</th>
                    <th style="padding:0.625rem 0.875rem;text-align:center;font-weight:600;color:#475569;">Tenor Baru</th>
                    <th style="padding:0.625rem 0.875rem;text-align:right;font-weight:600;color:#475569;">Kemampuan Bayar</th>
                    <th style="padding:0.625rem 0.875rem;text-align:center;font-weight:600;color:#475569;">Status</th>
                    <th style="padding:0.625rem 0.875rem;text-align:left;font-weight:600;color:#475569;">Catatan</th>
                </tr>
            </thead>
            <tbody>
                <?php $__currentLoopData = $item->restrukturisasiRequests; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $req): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:0.625rem 0.875rem;font-weight:600;color:#1e293b;">
                        <?php echo e($req->skema_restrukturisasi ?? '—'); ?>

                        <?php if($req->addendum_no): ?>
                        <div style="font-size:0.72rem;color:#94a3b8;">Addendum <?php echo e($req->addendum_no); ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="padding:0.625rem 0.875rem;text-align:right;color:#1e293b;">
                        Rp <?php echo e(number_format($req->sisa_piutang_snapshot ?? 0, 0, ',', '.')); ?>

                    </td>
                    <td style="padding:0.625rem 0.875rem;text-align:center;color:#1e293b;"><?php echo e($req->tenor_baru ?? '—'); ?> bln</td>
                    <td style="padding:0.625rem 0.875rem;text-align:right;color:#1e293b;">
                        Rp <?php echo e(number_format($req->kemampuan_bayar_baru ?? 0, 0, ',', '.')); ?>

                    </td>
                    <td style="padding:0.625rem 0.875rem;text-align:center;">
                        <?php
                            $reqStyle = match($req->status) {
                                'APPROVED' => 'background:#d1fae5;color:#065f46;',
                                'REJECTED' => 'background:#fee2e2;color:#991b1b;',
                                default    => 'background:#fef3c7;color:#92400e;',
                            };
                        ?>
                        <span style="padding:0.2rem 0.625rem;border-radius:9999px;font-size:0.72rem;font-weight:600;<?php echo e($reqStyle); ?>">
                            <?php echo e($req->status); ?>

                        </span>
                    </td>
                    <td style="padding:0.625rem 0.875rem;color:#475569;">
                        <?php echo e($req->catatan_manajer ?? $req->catatan_ao ?? '—'); ?>

                    </td>
                </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>


<div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;overflow:hidden;margin-top:1.25rem;">
    <div style="padding:1.25rem 1.25rem 0.875rem;">
        <h3 style="font-size:0.875rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin:0;">
            Jadwal Angsuran
        </h3>
    </div>
    <?php if($item->jadwalAngsuran->count()): ?>
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                    <th style="padding:0.625rem 0.875rem;text-align:center;font-weight:600;color:#475569;">Ke-</th>
                    <th style="padding:0.625rem 0.875rem;text-align:left;font-weight:600;color:#475569;">Jatuh Tempo</th>
                    <th style="padding:0.625rem 0.875rem;text-align:right;font-weight:600;color:#475569;">Pokok</th>
                    <th style="padding:0.625rem 0.875rem;text-align:right;font-weight:600;color:#475569;">Margin</th>
                    <th style="padding:0.625rem 0.875rem;text-align:right;font-weight:600;color:#475569;">Jumlah</th>
                    <th style="padding:0.625rem 0.875rem;text-align:left;font-weight:600;color:#475569;">Tgl Bayar</th>
                    <th style="padding:0.625rem 0.875rem;text-align:center;font-weight:600;color:#475569;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php $__currentLoopData = $item->jadwalAngsuran; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $a): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <?php
                    $badge = $a->status_badge;
                    $aStyle = match($badge['color']) {
                        'green'  => 'background:#d1fae5;color:#065f46;',
                        'red'    => 'background:#fee2e2;color:#991b1b;',
                        'yellow' => 'background:#fef3c7;color:#92400e;',
                        default  => 'background:#f1f5f9;color:#475569;',
                    };
                ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:0.625rem 0.875rem;text-align:center;color:#475569;"><?php echo e($a->angsuran_ke); ?></td>
                    <td style="padding:0.625rem 0.875rem;color:#1e293b;"><?php echo e($a->tanggal_jatuh_tempo?->format('d/m/Y') ?? '—'); ?></td>
                    <td style="padding:0.625rem 0.875rem;text-align:right;color:#1e293b;">Rp <?php echo e(number_format($a->pokok_angsuran ?? 0, 0, ',', '.')); ?></td>
                    <td style="padding:0.625rem 0.875rem;text-align:right;color:#1e293b;">Rp <?php echo e(number_format($a->margin_ujroh ?? 0, 0, ',', '.')); ?></td>
                    <td style="padding:0.625rem 0.875rem;text-align:right;font-weight:600;color:#1e293b;">Rp <?php echo e(number_format($a->jumlah_angsuran ?? 0, 0, ',', '.')); ?></td>
                    <td style="padding:0.625rem 0.875rem;color:#475569;"><?php echo e($a->tanggal_bayar?->format('d/m/Y') ?? '—'); ?></td>
                    <td style="padding:0.625rem 0.875rem;text-align:center;">
                        <span style="padding:0.2rem 0.625rem;border-radius:9999px;font-size:0.72rem;font-weight:600;<?php echo e($aStyle); ?>">
                            <?php echo e($badge['label']); ?>

                        </span>
                    </td>
                </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div style="padding:0 1.25rem 1.5rem;font-size:0.825rem;color:#94a3b8;">
        Jadwal angsuran belum tersedia.
    </div>
    <?php endif; ?>
</div>


<?php if($item->dokumen->count()): ?>
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;margin-top:1.25rem;">
    <h3 style="font-size:0.875rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin:0 0 1rem;">
        Dokumen Pendukung
    </h3>
    <?php $__currentLoopData = $item->dokumen; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $dok): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:0.625rem 0.875rem;
                background:#f8fafc;border-radius:0.5rem;font-size:0.825rem;margin-bottom:0.5rem;">
        <div>
            <span style="font-weight:600;color:#1e293b;"><?php echo e($dok->nama_file); ?></span>
            <span style="color:#94a3b8;margin-left:0.5rem;"><?php echo e($dok->jenis_dokumen); ?></span>
        </div>
        <a href="<?php echo e(route('berkas.unduh', ['path' => $dok->path_file])); ?>" target="_blank"
           style="color:#3b82f6;font-size:0.75rem;font-weight:600;text-decoration:none;">Lihat →</a>
    </div>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
</div>
<?php endif; ?>


<div style="display:flex;justify-content:space-between;align-items:center;gap:0.75rem;flex-wrap:wrap;
            margin-top:1.25rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1rem 1.25rem;">
    <span style="font-size:0.825rem;color:#64748b;">
        Halaman monitoring (read-only) — keputusan approval dilakukan pada menu Approval Pembiayaan.
    </span>
    <div style="display:flex;gap:0.5rem;">
        <a href="<?php echo e(route('manajer.npf.index')); ?>"
           style="padding:0.5rem 1.125rem;background:#f1f5f9;color:#475569;font-size:0.825rem;
                  font-weight:600;border-radius:0.5rem;text-decoration:none;">
            Kembali
        </a>
        <?php if($hariTunggakan > 0): ?>
        <a href="<?php echo e(route('manajer.approval-restrukturisasi.index')); ?>"
           style="padding:0.5rem 1.125rem;background:#eab308;color:#fff;font-size:0.825rem;
                  font-weight:600;border-radius:0.5rem;text-decoration:none;">
            Ajukan Restrukturisasi
        </a>
        <?php endif; ?>
    </div>
</div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\manajer\pembiayaan-show.blade.php ENDPATH**/ ?>