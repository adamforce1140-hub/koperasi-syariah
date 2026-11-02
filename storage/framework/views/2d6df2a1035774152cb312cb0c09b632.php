<?php $__env->startSection('title', 'Riwayat Transaksi'); ?>
<?php $__env->startSection('page-title', 'Riwayat Transaksi'); ?>

<?php $__env->startSection('styles'); ?>
<style>
    .filter-card { background: white; border-radius: 0.75rem; padding: 1.25rem 1.5rem;
        box-shadow: 0 1px 4px rgba(0,0,0,0.07); margin-bottom: 1.5rem; }
    .filter-row { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: flex-end; }
    .filter-group { display: flex; flex-direction: column; gap: 0.3rem; }
    .filter-group label { font-size: 0.75rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; }
    .filter-group input, .filter-group select {
        padding: 0.5rem 0.75rem; border: 1.5px solid #e2e8f0; border-radius: 0.5rem;
        font-size: 0.875rem; color: #1e293b; outline: none; background: white; }
    .filter-group input:focus, .filter-group select:focus { border-color: #059669; }
    .filter-group input[type="date"] { min-width: 140px; }
    .filter-group input[type="text"] { min-width: 220px; }
    .summary-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(180px,1fr)); gap: 1rem; margin-bottom: 1.5rem; }
    .summary-card { background: white; border-radius: 0.75rem; padding: 1rem 1.25rem;
        box-shadow: 0 1px 4px rgba(0,0,0,0.07); border-left: 4px solid var(--primary); }
    .summary-card.tarik { border-left-color: #ef4444; }
    .summary-card.angsuran { border-left-color: #3b82f6; }
    .summary-card.total { border-left-color: #f59e0b; }
    .summary-label { font-size: 0.72rem; color: #64748b; font-weight: 600; text-transform: uppercase; }
    .summary-value { font-size: 1.1rem; font-weight: 700; color: #1e293b; margin-top: 0.2rem; }
    .tbl-wrapper { background: white; border-radius: 0.75rem; box-shadow: 0 1px 4px rgba(0,0,0,0.07); overflow: hidden; }
    .tbl-header { padding: 1rem 1.5rem; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; }
    .tbl-header h3 { font-size: 0.95rem; font-weight: 700; }
    .badge-sukses { background: #dcfce7; color: #166534; padding: 0.2rem 0.6rem; border-radius: 9999px; font-size: 0.72rem; font-weight: 600; }
    .badge-pending-void { background: #fef3c7; color: #92400e; padding: 0.2rem 0.6rem; border-radius: 9999px; font-size: 0.72rem; font-weight: 600; }
    .badge-voided { background: #fee2e2; color: #991b1b; padding: 0.2rem 0.6rem; border-radius: 9999px; font-size: 0.72rem; font-weight: 600; }
    .badge-pending { background: #e0f2fe; color: #075985; padding: 0.2rem 0.6rem; border-radius: 9999px; font-size: 0.72rem; font-weight: 600; }
    .akad-tag { font-size: 0.7rem; background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0;
        padding: 0.1rem 0.4rem; border-radius: 0.25rem; display: inline-block; margin-top: 0.15rem; }
    .split-nominal { font-size: 0.72rem; color: #64748b; }
    .action-btns { display: flex; gap: 0.4rem; }
    .btn-xs { padding: 0.25rem 0.6rem; font-size: 0.75rem; border-radius: 0.375rem;
        border: none; cursor: pointer; font-weight: 500; text-decoration: none; display: inline-flex; align-items: center; gap: 0.25rem; }
    .btn-slip { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
    .btn-slip:hover { background: #dcfce7; }
    .btn-void { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }
    .btn-void:hover { background: #ffedd5; }
    .debet-val { color: #16a34a; font-weight: 600; }
    .kredit-val { color: #dc2626; font-weight: 600; }
    .pagination-wrap { padding: 1rem 1.5rem; border-top: 1px solid #f1f5f9; }

    /* Modal void */
    .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4);
        z-index: 1000; align-items: center; justify-content: center; }
    .modal-overlay.open { display: flex; }
    .modal-box { background: white; border-radius: 1rem; padding: 1.5rem; width: 420px; max-width: 95vw; }
    .modal-title { font-size: 1rem; font-weight: 700; margin-bottom: 1rem; color: #991b1b; }
    .modal-box textarea { width: 100%; border: 1.5px solid #e2e8f0; border-radius: 0.5rem;
        padding: 0.65rem; font-size: 0.875rem; resize: vertical; min-height: 80px; outline: none; }
    .modal-box textarea:focus { border-color: #059669; }
    .modal-footer { display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1rem; }

    /* ---- Responsif (WCAG 1.4.10 Reflow, 2.5.5 Target Size) ---- */
    @media (max-width: 900px) {
        .summary-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 640px) {
        .filter-card { padding: 1rem; }
        .filter-row { flex-direction: column; align-items: stretch; }
        .filter-group { width: 100%; }
        .filter-group input, .filter-group select,
        .filter-group input[type="text"], .filter-group input[type="date"] {
            min-width: 0; width: 100%; }
        .tbl-header { flex-wrap: wrap; gap: 0.5rem; padding: 0.85rem 1rem; }
        .modal-box { padding: 1.15rem; }
        .modal-footer { flex-wrap: wrap; }
        .modal-footer .btn { flex: 1 1 8rem; justify-content: center; }
    }
    @media (max-width: 420px) {
        .summary-grid { grid-template-columns: 1fr; }
    }
</style>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<div style="padding: 1.5rem;">

    
    <?php if(session('success')): ?>
        <div class="alert" style="background:#dcfce7;color:#166534;padding:0.75rem 1rem;border-radius:0.5rem;margin-bottom:1rem;font-size:0.875rem;">
            <?php echo e(session('success')); ?>

        </div>
    <?php endif; ?>
    <?php if(session('error')): ?>
        <div class="alert" style="background:#fee2e2;color:#991b1b;padding:0.75rem 1rem;border-radius:0.5rem;margin-bottom:1rem;font-size:0.875rem;">
            <?php echo e(session('error')); ?>

        </div>
    <?php endif; ?>

    
    <div style="background:linear-gradient(135deg,#065f46,#059669);border-radius:0.75rem;padding:1.25rem 1.5rem;margin-bottom:1.25rem;color:white;">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
            <div>
                <div style="font-size:0.75rem;opacity:0.8;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Posisi Kas — <?php echo e(now()->format('d M Y')); ?></div>
                <div style="font-size:1.5rem;font-weight:700;margin-top:0.25rem;">Rp <?php echo e(number_format($kasAkhirSystem, 0, ',', '.')); ?></div>
                <div style="font-size:0.8rem;opacity:0.75;margin-top:0.1rem;">Saldo Kas Sistem Akhir</div>
            </div>
            <div style="display:flex;gap:2rem;flex-wrap:wrap;">
                <div style="text-align:center;">
                    <div style="font-size:0.7rem;opacity:0.75;font-weight:600;text-transform:uppercase;">Kas Awal</div>
                    <div style="font-size:1rem;font-weight:700;">Rp <?php echo e(number_format($kasAwal, 0, ',', '.')); ?></div>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:0.7rem;opacity:0.75;font-weight:600;text-transform:uppercase;">Total Cash In</div>
                    <div style="font-size:1rem;font-weight:700;color:#a7f3d0;">+ Rp <?php echo e(number_format($totalCashIn, 0, ',', '.')); ?></div>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:0.7rem;opacity:0.75;font-weight:600;text-transform:uppercase;">Total Cash Out</div>
                    <div style="font-size:1rem;font-weight:700;color:#fca5a5;">- Rp <?php echo e(number_format($totalCashOut, 0, ',', '.')); ?></div>
                </div>
            </div>
        </div>
    </div>

    
    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-label">Setor Hari Ini</div>
            <div class="summary-value">Rp <?php echo e(number_format($totalSetorHariIni, 0, ',', '.')); ?></div>
        </div>
        <div class="summary-card tarik">
            <div class="summary-label">Tarik Hari Ini</div>
            <div class="summary-value">Rp <?php echo e(number_format($totalTarikHariIni, 0, ',', '.')); ?></div>
        </div>
        <div class="summary-card angsuran">
            <div class="summary-label">Angsuran Hari Ini</div>
            <div class="summary-value">Rp <?php echo e(number_format($totalAngsuranHariIni, 0, ',', '.')); ?></div>
        </div>
        <div class="summary-card total">
            <div class="summary-label">Total Transaksi</div>
            <div class="summary-value"><?php echo e($totalTransaksiHariIni); ?> Transaksi</div>
        </div>
    </div>

    
    <div class="filter-card">
        <form method="GET" action="<?php echo e(route('teller.riwayat')); ?>">
            <div class="filter-row">
                <div class="filter-group">
                    <label>Dari Tanggal</label>
                    <input type="date" name="dari" value="<?php echo e(request('dari', now()->format('Y-m-d'))); ?>">
                </div>
                <div class="filter-group">
                    <label>Sampai Tanggal</label>
                    <input type="date" name="sampai" value="<?php echo e(request('sampai', now()->format('Y-m-d'))); ?>">
                </div>
                <div class="filter-group">
                    <label>Cari (ID / Nama / No. Rekening)</label>
                    <input type="text" name="cari" value="<?php echo e(request('cari')); ?>" placeholder="Ketik untuk mencari...">
                </div>
                <div class="filter-group">
                    <label>Jenis Transaksi</label>
                    <select name="jenis">
                        <option value="">Semua Jenis</option>
                        <?php $__currentLoopData = $jenisOptions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $j): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="<?php echo e($j); ?>" <?php echo e(request('jenis') === $j ? 'selected' : ''); ?>><?php echo e($j); ?></option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="">Semua Status</option>
                        <option value="Sukses"       <?php echo e(request('status') === 'Sukses'       ? 'selected' : ''); ?>>Sukses</option>
                        <option value="Pending"      <?php echo e(request('status') === 'Pending'      ? 'selected' : ''); ?>>Pending Approval</option>
                        <option value="Gagal"        <?php echo e(request('status') === 'Gagal'        ? 'selected' : ''); ?>>Ditolak</option>
                        <option value="Pending Void" <?php echo e(request('status') === 'Pending Void' ? 'selected' : ''); ?>>Pending Void</option>
                        <option value="Voided"       <?php echo e(request('status') === 'Voided'       ? 'selected' : ''); ?>>Voided</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>AML</label>
                    <select name="aml">
                        <option value="">Semua</option>
                        <option value="high_value"   <?php echo e(request('aml') === 'high_value'   ? 'selected' : ''); ?>>High-Value</option>
                        <option value="menunggu"     <?php echo e(request('aml') === 'menunggu'     ? 'selected' : ''); ?>>Menunggu Verifikasi</option>
                        <option value="terverifikasi"<?php echo e(request('aml') === 'terverifikasi'? 'selected' : ''); ?>>Terverifikasi</option>
                        <option value="ditolak"      <?php echo e(request('aml') === 'ditolak'      ? 'selected' : ''); ?>>Ditolak</option>
                    </select>
                </div>
                <div class="filter-group" style="justify-content: flex-end;">
                    <label>&nbsp;</label>
                    <div style="display:flex;gap:0.5rem;">
                        <button type="submit" class="btn btn-primary btn-sm">Cari</button>
                        <a href="<?php echo e(route('teller.riwayat')); ?>" class="btn btn-sm" style="background:#f1f5f9;color:#475569;">Reset</a>
                    </div>
                </div>
            </div>
        </form>
    </div>

    
    <div class="tbl-wrapper">
        <div class="tbl-header">
            <h3>Daftar Transaksi (<?php echo e($transaksi->total()); ?> data)</h3>
        </div>
        <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th>ID Transaksi</th>
                        <th>Waktu</th>
                        <th>No. Anggota</th>
                        <th>Nama Nasabah</th>
                        <th>Jenis & Akad</th>
                        <th style="text-align:right;">Debet</th>
                        <th style="text-align:right;">Kredit</th>
                        <th style="text-align:right;">Saldo Akhir</th>
                        <th>Teller</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__empty_1 = true; $__currentLoopData = $transaksi; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $t): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <?php
                        // Deteksi akad syariah berdasarkan jenis transaksi
                        $akad = match(true) {
                            str_contains($t->jenis_transaksi, 'Angsuran Murabahah'),
                            str_contains($t->jenis_transaksi, 'Pembayaran Murabahah')    => 'Murabahah',
                            str_contains($t->jenis_transaksi, 'Angsuran Mudharabah'),
                            str_contains($t->jenis_transaksi, 'Bagi Hasil Mudharabah')   => 'Mudharabah',
                            str_contains($t->jenis_transaksi, 'Angsuran Musyarakah')     => 'Musyarakah',
                            str_contains($t->jenis_transaksi, 'Qardh')                  => 'Qardh',
                            str_contains($t->jenis_transaksi, 'Wadiah'),
                            str_contains($t->jenis_transaksi, 'Setoran Tunai'),
                            str_contains($t->jenis_transaksi, 'Penarikan Tunai')         => 'Wadiah',
                            str_contains($t->jenis_transaksi, 'Simpanan Pokok'),
                            str_contains($t->jenis_transaksi, 'Simpanan Wajib')          => null,
                            str_contains($t->jenis_transaksi, 'Bagi Hasil')              => 'Mudharabah',
                            default => null,
                        };

                        // Split nominal untuk angsuran pembiayaan
                        $angsuran   = $t->angsuran;
                        $isAngsuran = $angsuran !== null;

                        // Sumber transaksi: kas atau tabungan
                        $viaTabungan = $t->sumber_transaksi === 'Tabungan Anggota';

                        // Tentukan kolom rekening yang ditampilkan
                        // - Via Kas/Teller  : tampilkan "Kas Teller" atau sumber_transaksi
                        // - Via Tabungan    : tampilkan no_rekening tabungan anggota
                        if ($isAngsuran) {
                            $noRekeningTampil = $viaTabungan
                                ? ($t->rekening?->no_rekening ?? 'Tabungan Anggota')
                                : ($t->sumber_transaksi ?? 'Kas Teller');
                        } else {
                            $noRekeningTampil = $t->rekening?->no_rekening ?? '-';
                        }

                        // Arah tanda (+/-) mengikuti satu sumber kebenaran:
                        // Transaksi::isMasuk() (Debet = menambah simpanan).
                        $isPlus = $t->isMasuk();

                        // Transaksi batal/gagal/void TIDAK menimbulkan mutasi saldo apa pun
                        // (saldo rekening tidak berkurang) → kolom Debet/Kredit & Saldo kosong.
                        $tidakAdaMutasi = in_array($t->status_transaksi, ['Gagal', 'Dibatalkan', 'Voided', 'Pending Void'], true);

                        // Deteksi transaksi pending (belum dimutasi)
                        $isPending = in_array($t->status_transaksi, ['Pending', 'pending_approval'])
                            || $t->status === 'pending_approval';

                        // Saldo akhir:
                        // - Pending           : tampilkan saldo rekening saat ini (belum terpotong)
                        // - Angsuran via Kas  : sisa kewajiban pembiayaan
                        // - Angsuran via Tab  : sisa saldo tabungan
                        // - Lainnya           : saldo_setelah biasa
                        if ($isPending) {
                            // Saldo riil rekening saat ini — tidak terpengaruh pending
                            $labelSaldo = 'Saldo Saat Ini';
                            $saldoAkhir = $t->rekening?->saldo ?? $t->saldo_setelah ?? 0;
                        } elseif ($isAngsuran) {
                            $labelSaldo = $viaTabungan ? 'Sisa Tabungan' : 'Sisa Kewajiban';
                            // Sisa Kewajiban diambil dari sisa_pembiayaan — sumber yang sama
                            // dengan halaman Anggota & Nasabah. Sisa Tabungan dari saldo_setelah.
                            $saldoAkhir = $viaTabungan
                                ? ($t->saldo_setelah ?? 0)
                                : (float) ($angsuran->pembiayaan?->sisa_pembiayaan ?? $t->saldo_setelah ?? 0);
                        } else {
                            $labelSaldo = 'Saldo Akhir';
                            // Hitung ulang dari saldo_setelah sesuai posisi untuk memastikan konsistensi
                            $saldoAkhir = $t->saldo_setelah ?? 0;
                        }
                    ?>
                    <tr>
                        <td style="font-size:0.78rem;font-family:monospace;"><?php echo e($t->no_transaksi); ?></td>
                        <td style="font-size:0.8rem;white-space:nowrap;">
                            <?php echo e($t->waktu_transaksi->format('d/m/Y')); ?><br>
                            <span style="color:#94a3b8;"><?php echo e($t->waktu_transaksi->format('H:i:s')); ?></span>
                        </td>
                        <td style="font-size:0.82rem;"><?php echo e($t->anggota?->no_anggota ?? '-'); ?></td>
                        <td>
                            <div style="font-weight:600;font-size:0.875rem;"><?php echo e($t->anggota?->nama_lengkap ?? '-'); ?></div>
                            <div style="font-size:0.72rem;color:#94a3b8;"><?php echo e($noRekeningTampil); ?></div>
                        </td>
                        <td>
                            <div style="font-size:0.875rem;"><?php echo e($t->jenis_transaksi); ?></div>
                            <?php if($akad): ?>
                                <span class="akad-tag"><?php echo e($akad); ?></span>
                            <?php endif; ?>
                            <?php if($isAngsuran): ?>
                                <div class="split-nominal" style="margin-top:0.25rem;">
                                    Pokok: Rp <?php echo e(number_format($angsuran->pokok_angsuran ?? 0, 0, ',', '.')); ?><br>
                                    <?php if(($angsuran->margin_ujroh ?? 0) > 0): ?>
                                        Margin: Rp <?php echo e(number_format($angsuran->margin_ujroh, 0, ',', '.')); ?>

                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <?php if($tidakAdaMutasi): ?>
                                <span style="color:#94a3b8;">—</span>
                            <?php elseif($isPlus): ?>
                                <span class="debet-val">+ Rp <?php echo e(number_format($t->jumlah, 0, ',', '.')); ?></span>
                            <?php else: ?>
                                <span style="color:#94a3b8;">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <?php if($tidakAdaMutasi): ?>
                                <span style="color:#94a3b8;">—</span>
                            <?php elseif(!$isPlus): ?>
                                <span class="kredit-val">- Rp <?php echo e(number_format($t->jumlah, 0, ',', '.')); ?></span>
                            <?php else: ?>
                                <span style="color:#94a3b8;">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;font-weight:600;">
                            <?php if($tidakAdaMutasi): ?>
                                <span style="color:#94a3b8;">—</span>
                                <div style="font-size:0.68rem;color:#94a3b8;">Tidak Ada Mutasi</div>
                            <?php elseif($isPending): ?>
                                <div style="color:#92400e;">Rp <?php echo e(number_format($saldoAkhir, 0, ',', '.')); ?></div>
                                <div style="font-size:0.68rem;color:#b45309;background:#fef3c7;padding:0.1rem 0.4rem;border-radius:0.25rem;display:inline-block;margin-top:0.15rem;">
                                    <?php echo e($labelSaldo); ?> (Hold)
                                </div>
                            <?php else: ?>
                                <div>Rp <?php echo e(number_format($saldoAkhir, 0, ',', '.')); ?></div>
                                <div style="font-size:0.68rem;color:#94a3b8;"><?php echo e($labelSaldo); ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:0.82rem;"><?php echo e($t->dibuatOleh?->name ?? '-'); ?></td>
                        <td>
                            <?php if($t->status_transaksi === 'Sukses' || $t->status === 'success'): ?>
                                <span class="badge-sukses">Sukses</span>
                            <?php elseif($t->status_transaksi === 'Pending' || $t->status === 'pending_approval'): ?>
                                <span style="background:#fef3c7;color:#92400e;padding:0.2rem 0.6rem;border-radius:9999px;font-size:0.72rem;font-weight:600;display:inline-flex;align-items:center;gap:0.25rem;">
                                    <?php if($t->is_aml_high_value): ?>
                                        ⏳ Pending
                                    <?php else: ?>
                                        ⏳ Pending Over-limit
                                    <?php endif; ?>
                                </span>
                            <?php elseif($t->status_transaksi === 'Gagal' || $t->status === 'rejected'): ?>
                                <span style="background:#fee2e2;color:#991b1b;padding:0.2rem 0.6rem;border-radius:9999px;font-size:0.72rem;font-weight:600;">
                                    Ditolak
                                </span>
                            <?php elseif($t->status_transaksi === 'Pending Void'): ?>
                                <span class="badge-pending-void">Pending Void</span>
                            <?php elseif($t->status_transaksi === 'Voided'): ?>
                                <span class="badge-voided">Voided</span>
                            <?php else: ?>
                                <span class="badge-pending"><?php echo e($t->status_transaksi); ?></span>
                            <?php endif; ?>
                            <?php if($t->is_aml_high_value || $t->is_large_transaction): ?>
                                <?php
                                    $amlStatus = $t->aml_status_verifikasi ?? 'menunggu';
                                    $amlStyle = match($amlStatus) {
                                        'terverifikasi' => 'background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;',
                                        'ditolak'       => 'background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;',
                                        default         => 'background:#fef9c3;color:#854d0e;border:1px solid #fde68a;',
                                    };
                                    $amlLabel = match($amlStatus) {
                                        'terverifikasi' => '✅ AML Terverifikasi',
                                        'ditolak'       => '❌ AML Ditolak',
                                        default         => '⚠ AML High-Value',
                                    };
                                ?>
                                <div style="font-size:0.68rem;<?php echo e($amlStyle); ?>padding:0.1rem 0.45rem;border-radius:0.25rem;display:inline-block;margin-top:0.2rem;font-weight:600;">
                                    <?php echo e($amlLabel); ?>

                                </div>
                                <?php if($t->aml_sumber_dana): ?>
                                <div style="font-size:0.65rem;color:#94a3b8;margin-top:0.1rem;">
                                    Sumber: <?php echo e($t->aml_sumber_dana); ?>

                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="action-btns" id="action-btn-<?php echo e($t->id); ?>">
                                
                                <?php if($t->status_transaksi === 'Sukses' || $t->status === 'success'): ?>
                                    <a href="<?php echo e(route('teller.slip', $t->id)); ?>" target="_blank" class="btn-xs btn-slip">
                                        🖨 Slip
                                    </a>
                                <?php elseif($t->status === 'pending_approval'): ?>
                                    <span class="text-xs text-amber-600 font-medium" id="status-text-<?php echo e($t->id); ?>">
                                        <?php echo e($t->is_aml_high_value ? 'Menunggu Verifikasi AML' : 'Menunggu Manajer'); ?>

                                    </span>
                                <?php else: ?>
                                    <span class="text-xs text-slate-400 italic">-</span>
                                <?php endif; ?>
                                
                                <?php if(($t->status_transaksi === 'Sukses' || $t->status === 'success') && $t->waktu_transaksi->isToday()): ?>
                                    <button class="btn-xs btn-void"
                                        onclick="bukaModalVoid(<?php echo e($t->id); ?>, '<?php echo e($t->no_transaksi); ?>')">
                                        ✕ Void
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <tr>
                        <td colspan="11" style="text-align:center;padding:2rem;color:#94a3b8;">
                            Tidak ada transaksi ditemukan
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="pagination-wrap">
            <?php echo e($transaksi->links()); ?>

        </div>
    </div>
</div>


<div class="modal-overlay" id="voidModal">
    <div class="modal-box">
        <div class="modal-title">⚠ Request Void Transaksi</div>
        <p style="font-size:0.875rem;color:#475569;margin-bottom:0.75rem;">
            No. Transaksi: <strong id="voidNoTrx"></strong>
        </p>
        <p style="font-size:0.875rem;color:#475569;margin-bottom:0.5rem;">
            Permintaan void akan dikirim ke manajer untuk otorisasi. Masukkan alasan:
        </p>
        <textarea id="voidAlasan" placeholder="Contoh: Salah input nominal, nasabah membatalkan transaksi..."></textarea>
        <div class="modal-footer">
            <button onclick="tutupModal()" class="btn btn-sm" style="background:#f1f5f9;color:#475569;">Batal</button>
            <button onclick="kirimVoid()" class="btn btn-primary btn-sm" id="btnKirimVoid">Kirim Request Void</button>
        </div>
    </div>
</div>

<?php $__env->stopSection(); ?>

<?php $__env->startSection('scripts'); ?>
<script>
    let voidTrxId = null;

    function bukaModalVoid(id, noTrx) {
        voidTrxId = id;
        document.getElementById('voidNoTrx').textContent = noTrx;
        document.getElementById('voidAlasan').value = '';
        document.getElementById('voidModal').classList.add('open');
    }

    function tutupModal() {
        document.getElementById('voidModal').classList.remove('open');
        voidTrxId = null;
    }

    // Auto check status transaksi pending_approval via polling AJAX setiap 5 detik
    document.addEventListener('DOMContentLoaded', function() {
        // Basis URL rute slip & void dipakai agar tautan tetap sah bila prefix
        // rute berubah. Pola `__ID__` dipertahankan karena route() menolak
        // parameter kosong; penanda diganti saat ID transaksi diketahui.
        const slipUrl = '<?php echo e(route("teller.slip", ["transaksi" => "__ID__"])); ?>';
        const voidUrl = '<?php echo e(route("teller.void", ["transaksi" => "__ID__"])); ?>';
        const pendingRows = Array.from(document.querySelectorAll('[id^="status-text-"]'));
        if (pendingRows.length > 0) {
            setInterval(function() {
                fetch('<?php echo e(route("teller.riwayat")); ?>?ajax_check=1', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(res => res.json())
                .then(data => {
                    if (data && data.transaksi) {
                        data.transaksi.forEach(item => {
                            const target = document.getElementById('action-btn-' + item.id);
                            if (target && (item.status === 'success' || item.status_transaksi === 'Sukses')) {
                                const href = slipUrl.replace('__ID__', encodeURIComponent(item.id));
                                target.innerHTML = `<a href="${href}" target="_blank" class="btn-xs btn-slip">🖨 Slip</a>`;
                            }
                        });
                    }
                }).catch(() => {});
            }, 5000);
        }
    });

    async function kirimVoid() {
        if (!voidTrxId) return;
        const alasan = document.getElementById('voidAlasan').value.trim();
        if (!alasan) {
            alert('Mohon isi alasan void terlebih dahulu.');
            return;
        }

        const btn = document.getElementById('btnKirimVoid');
        btn.disabled = true;
        btn.textContent = 'Mengirim...';

        try {
            const res = await fetch(voidUrl.replace('__ID__', encodeURIComponent(voidTrxId)), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ alasan }),
            });
            const data = await res.json();
            if (data.status === 'success') {
                tutupModal();
                location.reload();
            } else {
                alert(data.message || 'Terjadi kesalahan.');
            }
        } catch (e) {
            alert('Gagal mengirim request. Coba lagi.');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Kirim Request Void';
        }
    }

    // Tutup modal jika klik di luar
    document.getElementById('voidModal').addEventListener('click', function(e) {
        if (e.target === this) tutupModal();
    });
</script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/teller/riwayat.blade.php ENDPATH**/ ?>