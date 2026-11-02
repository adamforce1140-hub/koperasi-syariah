<?php $__env->startSection('title', 'Status Pengajuan Pembiayaan'); ?>
<?php $__env->startSection('page-title', 'Status Pengajuan Pembiayaan'); ?>

<?php $__env->startSection('styles'); ?>
<style>
    /* ===== PAGE HEADER ===== */
    .sp-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 1.75rem;
        flex-wrap: wrap;
        gap: 0.75rem;
    }
    .sp-header-left h2 {
        font-size: 1.35rem;
        font-weight: 700;
        color: #065f46;
        margin: 0;
    }
    .sp-header-left p {
        font-size: 0.82rem;
        color: #64748b;
        margin-top: 0.2rem;
    }
    .btn-ajukan {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.55rem 1.15rem;
        background: #059669;
        color: white;
        border-radius: 0.6rem;
        font-size: 0.875rem;
        font-weight: 600;
        text-decoration: none;
        border: none;
        cursor: pointer;
        transition: background 0.15s;
        white-space: nowrap;
    }
    .btn-ajukan:hover { background: #047857; color: white; }

    /* ===== EMPTY STATE ===== */
    .empty-state {
        background: white;
        border-radius: 1rem;
        box-shadow: 0 1px 6px rgba(0,0,0,0.07);
        padding: 4rem 2rem;
        text-align: center;
    }
    .empty-icon {
        width: 80px;
        height: 80px;
        background: #f0fdf4;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1.25rem;
        font-size: 2.2rem;
    }
    .empty-state h3 {
        font-size: 1.1rem;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 0.5rem;
    }
    .empty-state p {
        font-size: 0.875rem;
        color: #64748b;
        margin-bottom: 1.5rem;
    }

    /* ===== PENGAJUAN CARD ===== */
    .pengajuan-card {
        background: white;
        border-radius: 1rem;
        box-shadow: 0 1px 6px rgba(0,0,0,0.07);
        margin-bottom: 1.25rem;
        overflow: hidden;
        border: 1px solid #e2e8f0;
        transition: box-shadow 0.15s;
    }
    .pengajuan-card:hover {
        box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    }

    /* Card Header */
    .pc-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 1rem 1.5rem;
        border-bottom: 1px solid #f1f5f9;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    .pc-header-left {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
    }
    .pc-no-pengajuan {
        font-size: 0.95rem;
        font-weight: 700;
        color: #065f46;
    }
    .pc-tanggal {
        font-size: 0.78rem;
        color: #94a3b8;
    }

    /* Status badges */
    .badge-status {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.25rem 0.7rem;
        border-radius: 9999px;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.02em;
    }
    .badge-menunggu  { background: #fef3c7; color: #92400e; }
    .badge-diproses  { background: #dbeafe; color: #1e40af; }
    .badge-disetujui { background: #dcfce7; color: #166534; }
    .badge-ditolak   { background: #fee2e2; color: #991b1b; }
    .badge-draft     { background: #f1f5f9; color: #475569; }

    /* Card info row */
    .pc-info {
        display: flex;
        gap: 2rem;
        padding: 0.9rem 1.5rem;
        background: #fafafa;
        border-bottom: 1px solid #f1f5f9;
        flex-wrap: wrap;
    }
    .pc-info-item { display: flex; flex-direction: column; gap: 0.15rem; }
    .pc-info-label { font-size: 0.7rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
    .pc-info-value { font-size: 0.9rem; font-weight: 600; color: #1e293b; }

    /* ===== STEPPER ===== */
    .stepper-wrap {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid #f1f5f9;
        overflow-x: auto;
    }
    .stepper {
        display: flex;
        align-items: flex-start;
        min-width: 360px;
    }
    .stepper-step {
        display: flex;
        flex-direction: column;
        align-items: center;
        flex: 1;
        position: relative;
    }
    /* connecting line */
    .stepper-step:not(:last-child)::after {
        content: '';
        position: absolute;
        top: 18px;
        left: calc(50% + 18px);
        right: calc(-50% + 18px);
        height: 2px;
        background: #e2e8f0;
        z-index: 0;
    }
    .stepper-step.done:not(:last-child)::after  { background: #059669; }
    .stepper-step.active:not(:last-child)::after { background: #e2e8f0; }
    .stepper-step.rejected:not(:last-child)::after { background: #fca5a5; }

    .stepper-circle {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.82rem;
        font-weight: 700;
        position: relative;
        z-index: 1;
        flex-shrink: 0;
    }
    .stepper-circle.pending  { background: #f1f5f9; color: #94a3b8; border: 2px solid #e2e8f0; }
    .stepper-circle.done     { background: #059669; color: white; border: 2px solid #059669; }
    .stepper-circle.active   { background: white; color: #059669; border: 2px solid #059669; }
    .stepper-circle.rejected { background: #dc2626; color: white; border: 2px solid #dc2626; }

    /* pulse on active */
    .stepper-circle.active {
        animation: stepper-pulse 1.8s ease-in-out infinite;
    }
    @keyframes stepper-pulse {
        0%   { box-shadow: 0 0 0 0 rgba(5,150,105,0.35); }
        60%  { box-shadow: 0 0 0 8px rgba(5,150,105,0); }
        100% { box-shadow: 0 0 0 0 rgba(5,150,105,0); }
    }

    .stepper-label {
        margin-top: 0.45rem;
        font-size: 0.72rem;
        font-weight: 600;
        color: #94a3b8;
        text-align: center;
        white-space: nowrap;
    }
    .stepper-label.done     { color: #059669; }
    .stepper-label.active   { color: #065f46; }
    .stepper-label.rejected { color: #dc2626; }

    /* ===== ACCORDION DETAIL ===== */
    .pc-accordion-toggle {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.75rem 1.5rem;
        cursor: pointer;
        user-select: none;
        background: none;
        border: none;
        width: 100%;
        font-family: inherit;
        transition: background 0.12s;
    }
    .pc-accordion-toggle:hover { background: #f8fafc; }
    .pc-accordion-toggle span {
        font-size: 0.82rem;
        font-weight: 600;
        color: #059669;
    }
    .accordion-icon {
        font-size: 0.7rem;
        color: #94a3b8;
        transition: transform 0.25s;
    }
    .pc-accordion-toggle.open .accordion-icon { transform: rotate(180deg); }

    .pc-detail {
        display: none;
        padding: 0 1.5rem 1.25rem;
        border-top: 1px solid #f1f5f9;
    }
    .pc-detail.open { display: block; }

    .detail-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-top: 1rem;
    }
    .detail-item { display: flex; flex-direction: column; gap: 0.2rem; }
    .detail-label { font-size: 0.72rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
    .detail-value { font-size: 0.875rem; color: #334155; font-weight: 500; }

    .catatan-box {
        margin-top: 1rem;
        padding: 0.9rem 1rem;
        background: #fffbeb;
        border: 1px solid #fde68a;
        border-radius: 0.6rem;
        font-size: 0.875rem;
        color: #92400e;
    }
    .catatan-box strong { display: block; margin-bottom: 0.25rem; color: #78350f; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.04em; }

    .tolak-box {
        margin-top: 1rem;
        padding: 0.9rem 1rem;
        background: #fff1f2;
        border: 1px solid #fecdd3;
        border-radius: 0.6rem;
        font-size: 0.875rem;
        color: #9f1239;
    }
    .tolak-box strong { display: block; margin-bottom: 0.25rem; color: #881337; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.04em; }

    /* Dokumen list */
    .dokumen-section { margin-top: 1rem; }
    .dokumen-section-title { font-size: 0.8rem; font-weight: 700; color: #374151; margin-bottom: 0.6rem; }
    .dokumen-list { display: flex; flex-direction: column; gap: 0.4rem; }
    .dokumen-item {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        padding: 0.5rem 0.75rem;
        background: #f8fafc;
        border-radius: 0.5rem;
        border: 1px solid #e2e8f0;
    }
    .dokumen-icon { font-size: 1rem; flex-shrink: 0; }
    .dokumen-name { font-size: 0.82rem; font-weight: 600; color: #334155; }
    .dokumen-jenis { font-size: 0.72rem; color: #94a3b8; }
    .dokumen-empty { font-size: 0.82rem; color: #94a3b8; font-style: italic; }

    /* ===== PAGINATION ===== */
    .pagination-wrap {
        margin-top: 1.5rem;
        display: flex;
        justify-content: center;
    }
    .pagination-wrap nav { display: inline-block; }

    /* Override default Laravel pagination */
    .pagination-wrap .pagination {
        display: flex;
        gap: 0.25rem;
        list-style: none;
        padding: 0;
        margin: 0;
        align-items: center;
    }
    .pagination-wrap .pagination li span,
    .pagination-wrap .pagination li a {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 36px;
        height: 36px;
        padding: 0 0.6rem;
        border-radius: 0.45rem;
        font-size: 0.82rem;
        font-weight: 500;
        text-decoration: none;
        border: 1px solid #e2e8f0;
        color: #475569;
        background: white;
        transition: all 0.12s;
    }
    .pagination-wrap .pagination li a:hover { background: #f0fdf4; border-color: #059669; color: #065f46; }
    .pagination-wrap .pagination li.active span { background: #059669; border-color: #059669; color: white; }
    .pagination-wrap .pagination li.disabled span { color: #cbd5e1; cursor: not-allowed; background: #f8fafc; }

    /* ===== RESPONSIVE ===== */
    @media (max-width: 640px) {
        .sp-header { flex-direction: column; align-items: flex-start; }
        .pc-header  { flex-direction: column; align-items: flex-start; }
        .pc-info    { gap: 1rem; }
        .stepper-wrap { padding: 1rem; }
        .pc-accordion-toggle, .pc-detail { padding-left: 1rem; padding-right: 1rem; }
    }
</style>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>


<div class="sp-header">
    <div class="sp-header-left">
        <h2>Status Pengajuan Pembiayaan</h2>
        <p>Pantau perkembangan pengajuan pembiayaan Anda secara real-time</p>
    </div>
    <a href="<?php echo e(route('nasabah.pengajuan-pinjaman.baru')); ?>" class="btn-ajukan">
        <span>&#43;</span> Ajukan Pembiayaan Baru
    </a>
</div>


<?php if($pengajuan->isEmpty()): ?>
    <div class="empty-state">
        <div class="empty-icon">&#128196;</div>
        <h3>Belum ada pengajuan pembiayaan</h3>
        <p>Anda belum pernah mengajukan pembiayaan. Mulai ajukan sekarang untuk mendapatkan pembiayaan syariah.</p>
        <a href="<?php echo e(route('nasabah.pengajuan-pinjaman.baru')); ?>" class="btn-ajukan">
            <span>&#43;</span> Ajukan Pembiayaan Sekarang
        </a>
    </div>

<?php else: ?>

    
    <?php $__currentLoopData = $pengajuan; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $index => $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <?php
        $status = $item->status; // Menunggu | Direview | Diproses | Disetujui | Ditolak

        // Tentukan state tiap step: 'done' | 'active' | 'pending' | 'rejected'
        // Step: 1=Draft, 2=Menunggu, 3=Diproses, 4=Disetujui/Ditolak
        if ($status === 'Menunggu') {
            $steps = ['done', 'active', 'pending', 'pending'];
            $step4Label = 'Disetujui';
            $step4State = 'pending';
        } elseif (in_array($status, ['Diproses', 'Direview'])) {
            $steps = ['done', 'done', 'active', 'pending'];
            $step4Label = 'Disetujui';
            $step4State = 'pending';
        } elseif ($status === 'Disetujui') {
            $steps = ['done', 'done', 'done', 'done'];
            $step4Label = 'Disetujui';
            $step4State = 'done';
        } elseif ($status === 'Ditolak') {
            $steps = ['done', 'done', 'done', 'rejected'];
            $step4Label = 'Ditolak';
            $step4State = 'rejected';
        } else {
            // Draft atau status lain
            $steps = ['active', 'pending', 'pending', 'pending'];
            $step4Label = 'Disetujui';
            $step4State = 'pending';
        }

        // Line state antara step (warna garis = state step sebelumnya)
        // Step 1→2: selesai jika $steps[0]=done
        // Step 2→3: selesai jika $steps[1]=done
        // Step 3→4: selesai jika $steps[2]=done, rejected jika $steps[3]=rejected

        $accordionId = 'detail-' . $item->id;
        $toggleId    = 'toggle-' . $item->id;

        // Badge class
        $badgeClass = match($status) {
            'Menunggu'  => 'badge-menunggu',
            'Diproses'  => 'badge-diproses',
            'Direview'  => 'badge-diproses',
            'Disetujui' => 'badge-disetujui',
            'Ditolak'   => 'badge-ditolak',
            default     => 'badge-draft',
        };

        // Badge icon
        $badgeIcon = match($status) {
            'Menunggu'  => '&#9203;',
            'Diproses'  => '&#128260;',
            'Direview'  => '&#128260;',
            'Disetujui' => '&#10003;',
            'Ditolak'   => '&#10005;',
            default     => '&#128221;',
        };
    ?>

    <div class="pengajuan-card">

        
        <div class="pc-header">
            <div class="pc-header-left">
                <span class="pc-no-pengajuan"><?php echo e($item->no_pengajuan); ?></span>
                <span class="badge-status <?php echo e($badgeClass); ?>">
                    <?php echo $badgeIcon; ?> <?php echo e($status ?? 'Draft'); ?>

                </span>
            </div>
            <span class="pc-tanggal">
                &#128197; <?php echo e($item->created_at->format('d M Y')); ?>

            </span>
        </div>

        
        <div class="pc-info">
            <div class="pc-info-item">
                <span class="pc-info-label">Produk / Akad</span>
                <span class="pc-info-value">
                    <?php echo e($item->produk?->nama_produk ?? '-'); ?>

                    <?php if($item->jenis_akad): ?>
                        <span style="font-size:0.75rem;color:#64748b;font-weight:400;">(<?php echo e($item->jenis_akad); ?>)</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="pc-info-item">
                <span class="pc-info-label">Nominal</span>
                <span class="pc-info-value">Rp <?php echo e(number_format($item->jumlah_pengajuan, 0, ',', '.')); ?></span>
            </div>
            <div class="pc-info-item">
                <span class="pc-info-label">Tenor</span>
                <span class="pc-info-value"><?php echo e($item->tenor); ?> bulan</span>
            </div>
        </div>

        
        <div class="stepper-wrap">
            <div class="stepper">

                
                <div class="stepper-step <?php echo e($steps[0]); ?>">
                    <div class="stepper-circle <?php echo e($steps[0]); ?>">
                        <?php if($steps[0] === 'done'): ?> &#10003; <?php else: ?> 1 <?php endif; ?>
                    </div>
                    <span class="stepper-label <?php echo e($steps[0]); ?>">Draft</span>
                </div>

                
                <div class="stepper-step <?php echo e($steps[1]); ?>">
                    <div class="stepper-circle <?php echo e($steps[1]); ?>">
                        <?php if($steps[1] === 'done'): ?> &#10003; <?php else: ?> 2 <?php endif; ?>
                    </div>
                    <span class="stepper-label <?php echo e($steps[1]); ?>">Menunggu</span>
                </div>

                
                <div class="stepper-step <?php echo e($steps[2]); ?>">
                    <div class="stepper-circle <?php echo e($steps[2]); ?>">
                        <?php if($steps[2] === 'done'): ?> &#10003; <?php else: ?> 3 <?php endif; ?>
                    </div>
                    <span class="stepper-label <?php echo e($steps[2]); ?>">Diproses</span>
                </div>

                
                <div class="stepper-step <?php echo e($step4State); ?>">
                    <div class="stepper-circle <?php echo e($step4State); ?>">
                        <?php if($step4State === 'done'): ?> &#10003;
                        <?php elseif($step4State === 'rejected'): ?> &#10005;
                        <?php else: ?> 4
                        <?php endif; ?>
                    </div>
                    <span class="stepper-label <?php echo e($step4State); ?>"><?php echo e($step4Label); ?></span>
                </div>

            </div>
        </div>

        
        <button
            class="pc-accordion-toggle"
            id="<?php echo e($toggleId); ?>"
            onclick="toggleDetail('<?php echo e($item->id); ?>')"
            aria-expanded="false"
            aria-controls="<?php echo e($accordionId); ?>"
        >
            <span>&#128196; Lihat Detail</span>
            <span class="accordion-icon">&#9660;</span>
        </button>

        
        <div class="pc-detail" id="<?php echo e($accordionId); ?>" role="region" aria-labelledby="<?php echo e($toggleId); ?>">

            <div class="detail-grid">
                <div class="detail-item">
                    <span class="detail-label">Tujuan Pembiayaan</span>
                    <span class="detail-value"><?php echo e($item->tujuan ?? '-'); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Nama Produk</span>
                    <span class="detail-value"><?php echo e($item->produk?->nama_produk ?? '-'); ?></span>
                </div>
                <?php if($item->agunan): ?>
                <div class="detail-item">
                    <span class="detail-label">Agunan / Jaminan</span>
                    <span class="detail-value"><?php echo e($item->agunan); ?></span>
                </div>
                <?php endif; ?>
                <?php if($item->tanggal_diproses): ?>
                <div class="detail-item">
                    <span class="detail-label">Tanggal Diproses</span>
                    <span class="detail-value"><?php echo e($item->tanggal_diproses->format('d M Y')); ?></span>
                </div>
                <?php endif; ?>
            </div>

            
            <?php if($item->catatan_pengelola && $status !== 'Ditolak'): ?>
            <div class="catatan-box">
                <strong>&#128221; Catatan Pengelola</strong>
                <?php echo e($item->catatan_pengelola); ?>

            </div>
            <?php endif; ?>

            
            <?php if($status === 'Ditolak'): ?>
            <div class="tolak-box">
                <strong>&#10060; Alasan Penolakan</strong>
                <?php echo e($item->catatan_pengelola ?? 'Tidak ada keterangan lebih lanjut dari pengelola.'); ?>

            </div>
            <?php endif; ?>

            
            <div class="dokumen-section">
                <div class="dokumen-section-title">&#128196; Dokumen yang Diupload</div>
                <?php if($item->dokumen && $item->dokumen->count() > 0): ?>
                    <div class="dokumen-list">
                        <?php $__currentLoopData = $item->dokumen; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $dok): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <div class="dokumen-item">
                            <span class="dokumen-icon">&#128196;</span>
                            <div>
                                <div class="dokumen-name"><?php echo e($dok->nama_dokumen ?? $dok->nama ?? 'Dokumen'); ?></div>
                                <?php if(isset($dok->jenis_dokumen) || isset($dok->jenis)): ?>
                                <div class="dokumen-jenis"><?php echo e($dok->jenis_dokumen ?? $dok->jenis); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </div>
                <?php else: ?>
                    <p class="dokumen-empty">Belum ada dokumen yang diupload.</p>
                <?php endif; ?>
            </div>

        </div>

    </div>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

    
    <?php if($pengajuan->hasPages()): ?>
    <div class="pagination-wrap">
        <?php echo e($pengajuan->links()); ?>

    </div>
    <?php endif; ?>

<?php endif; ?> 

<?php $__env->stopSection(); ?>

<?php $__env->startSection('scripts'); ?>
<script>
function toggleDetail(id) {
    const detail = document.getElementById('detail-' + id);
    const toggle = document.getElementById('toggle-' + id);
    if (!detail || !toggle) return;

    const isOpen = detail.classList.contains('open');
    detail.classList.toggle('open', !isOpen);
    toggle.classList.toggle('open', !isOpen);
    toggle.setAttribute('aria-expanded', String(!isOpen));

    // Update toggle label
    const label = toggle.querySelector('span:first-child');
    if (label) {
        label.innerHTML = isOpen
            ? '&#128196; Lihat Detail'
            : '&#128196; Sembunyikan Detail';
    }
}
</script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\nasabah\status-pengajuan.blade.php ENDPATH**/ ?>