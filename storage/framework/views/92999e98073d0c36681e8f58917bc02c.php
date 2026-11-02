
<?php $__env->startSection('page-title', "Laporan Neraca"); ?>

<?php $__env->startSection('title', 'Laporan Neraca'); ?>

<?php $__env->startSection('styles'); ?>
<style>
    /* Cetak / simpan PDF: sembunyikan kerangka aplikasi & kontrol filter. */
    @media print {
        .sidebar, .topbar, .no-print { display: none !important; }
        .main-wrapper { margin-left: 0 !important; }
        .content { padding: 0 !important; }
        body { background: #fff !important; }
        .card { box-shadow: none !important; border: 1px solid #cbd5e1 !important; }
        .row { display: block !important; }
        .col-md-6 { width: 100% !important; max-width: 100% !important; flex: 0 0 100% !important; }
        a[href]:after { content: ""; }
    }
</style>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<div class="container-fluid px-4 py-3">

    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-1">Laporan Neraca (Balance Sheet)</h4>
            <p class="text-muted mb-0 small">
                Per <?php echo e($tanggalFilter->translatedFormat('d F Y')); ?> &mdash; Standar PSAK 101
                <?php if($mode === 'akhir_bulan'): ?>
                    <span class="badge bg-secondary ms-1" style="font-size:.68rem;">Akhir Bulan</span>
                <?php endif; ?>
            </p>
        </div>
        <?php if(\App\Support\HierarkiRole::dalamKelompok(auth()->user()->role, 'pengurus')): ?>
        <a href="<?php echo e(route('pengurus.cadangan.index')); ?>" class="btn btn-outline-primary btn-sm no-print">
            Kelola Dana Cadangan
        </a>
        <?php endif; ?>
    </div>

    
    <div class="card border-0 shadow-sm mb-4 no-print">
        <div class="card-body py-3">
            <form method="GET" action="<?php echo e(route('laporan.neraca')); ?>"
                  class="d-flex align-items-end flex-wrap gap-2">
                <div>
                    <label for="mode" class="form-label mb-1 small fw-semibold text-muted">Mode Periode</label>
                    <select name="mode" id="mode" class="form-select form-select-sm" style="min-width:190px;">
                        <option value="tanggal"     <?php echo e($mode === 'tanggal' ? 'selected' : ''); ?>>Per Tanggal Spesifik</option>
                        <option value="akhir_bulan" <?php echo e($mode === 'akhir_bulan' ? 'selected' : ''); ?>>Per Akhir Bulan</option>
                    </select>
                </div>
                <div>
                    <label for="tanggal" class="form-label mb-1 small fw-semibold text-muted">Tanggal</label>
                    <input type="date" name="tanggal" id="tanggal"
                           class="form-control form-control-sm" style="min-width:170px;"
                           value="<?php echo e($tanggalInput); ?>" max="<?php echo e(now()->toDateString()); ?>">
                </div>
                <button type="submit" class="btn btn-primary btn-sm">
                    Tampilkan Laporan
                </button>
                <?php
                    // Tombol mengikuti matriks RBAC: peran yang tidak punya hak
                    // cetak/ekspor tidak ditawari tombol yang pasti ditolak server.
                    $peranSaya = auth()->user()->role;
                    $bolehUnduh  = \App\Support\LaporanKeuanganPermission::boleh($peranSaya, \App\Support\LaporanKeuanganPermission::MODUL_NERACA, \App\Support\LaporanKeuanganPermission::UNDUH);
                    $bolehEkspor = \App\Support\LaporanKeuanganPermission::boleh($peranSaya, \App\Support\LaporanKeuanganPermission::MODUL_NERACA, \App\Support\LaporanKeuanganPermission::EKSPOR);
                ?>
                <?php if($bolehUnduh): ?>
                <button type="button" class="btn btn-danger btn-sm" onclick="window.print()">
                    Cetak PDF
                </button>
                <?php endif; ?>
                <?php if($bolehEkspor): ?>
                <a href="<?php echo e(route('laporan.neraca', ['mode' => $mode, 'tanggal' => $tanggalInput, 'export' => 'excel'])); ?>"
                   class="btn btn-success btn-sm">
                    Export Excel
                </a>
                <?php endif; ?>
                <div class="ms-auto small text-muted">
                    Saldo diakumulasi dari jurnal <strong>Posted</strong> s/d
                    <strong><?php echo e($tanggalFilter->translatedFormat('d F Y')); ?></strong>.
                </div>
            </form>
        </div>
    </div>

    <?php
        /* Total Pasiva & selisih dihitung di controller (DanaCadanganController::neraca)
         | agar angka pada rincian, badge, dan peringatan selalu identik.
         | $balance = Aktiva − Pasiva: positif berarti sisi Aktiva lebih besar. */
        $balance = $selisihNeraca;

        $badgeLaba = [
            'untung' => ['label' => 'Untung', 'bg' => '#dcfce7', 'fg' => '#166534', 'border' => '#86efac'],
            'rugi'   => ['label' => 'Rugi',   'bg' => '#fee2e2', 'fg' => '#991b1b', 'border' => '#fca5a5'],
            'impas'  => ['label' => 'Impas',  'bg' => '#f1f5f9', 'fg' => '#475569', 'border' => '#cbd5e1'],
        ][$statusLaba];
    ?>
    <?php if(abs($balance) > 0.01): ?>
    <div class="alert alert-warning">
        <strong>Perhatian:</strong> Neraca belum seimbang. Selisih Aktiva &minus; Pasiva:
        Rp <?php echo e(number_format(abs($balance), 2, ',', '.')); ?>

        (<?php echo e($balance > 0 ? 'Aktiva lebih besar' : 'Pasiva lebih besar'); ?>).
        <div class="small mt-1 mb-0">
            Total Aset Rp <?php echo e(number_format($totalAset, 2, ',', '.')); ?> vs
            Total Pasiva Rp <?php echo e(number_format($totalPasiva, 2, ',', '.')); ?>.
            Periksa akun Ekuitas yang bersaldo minus atau jurnal yang tidak balance
            melalui tombol <em>Perbaiki Akun 3-9000</em> pada halaman Bagan Akun.
        </div>
    </div>
    <?php else: ?>
    <div class="alert alert-success py-2 small">
        <strong>Neraca seimbang.</strong> Total Aset = Kewajiban + Dana Syirkah Temporer + Ekuitas + Laba/Rugi Berjalan
        (Rp <?php echo e(number_format($totalAset, 2, ',', '.')); ?>).
        <span class="text-muted ms-1">Klasifikasi penyajian mengikuti PSAK 101.</span>
    </div>
    <?php endif; ?>

    <div class="row g-4">
        
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header fw-bold" style="background:#dbeafe;color:#1e40af;">ASET</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Kode</th><th>Nama Akun</th><th class="text-end">Saldo</th></tr></thead>
                        <tbody>
                            <?php $__currentLoopData = $asetGroups; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $grup): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr class="table-light">
                                <td colspan="3" class="fw-semibold text-uppercase small" style="letter-spacing:.03em;color:#1e40af;">
                                    <?php echo e($grup['label']); ?>

                                </td>
                            </tr>
                            <?php $__currentLoopData = $grup['akun']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $a): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr>
                                <td><code><?php echo e($a->kode_akun); ?></code></td>
                                <td><?php echo e($a->nama_akun); ?></td>
                                <td class="text-end">Rp <?php echo e(number_format($a->saldoBerjalan($sampai), 0, ',', '.')); ?></td>
                            </tr>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            <?php if(count($asetGroups) > 1): ?>
                            <tr>
                                <td colspan="2" class="text-muted fw-semibold small ps-4">Subtotal <?php echo e($grup['label']); ?></td>
                                <td class="text-end fw-semibold">Rp <?php echo e(number_format($grup['subtotal'], 0, ',', '.')); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </tbody>
                        <tfoot class="table-primary fw-bold">
                            <tr><td colspan="2">Total Aset</td><td class="text-end">Rp <?php echo e(number_format($totalAset, 0, ',', '.')); ?></td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        
        <div class="col-md-6">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header fw-bold" style="background:#fef3c7;color:#92400e;">KEWAJIBAN</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Kode</th><th>Nama Akun</th><th class="text-end">Saldo</th></tr></thead>
                        <tbody>
                            <?php $__currentLoopData = $kewajibanGroups; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $grup): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr class="table-light">
                                <td colspan="3" class="fw-semibold text-uppercase small" style="letter-spacing:.03em;color:#92400e;">
                                    <?php echo e($grup['label']); ?>

                                </td>
                            </tr>
                            <?php $__currentLoopData = $grup['akun']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $k): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr>
                                <td><code><?php echo e($k->kode_akun); ?></code></td>
                                <td><?php echo e($k->nama_akun); ?></td>
                                <td class="text-end">
                                    Rp <?php echo e(number_format($k->saldoBerjalan($sampai), 0, ',', '.')); ?>

                                    <?php if($k->kode_akun === '2-1003'): ?>
                                        <a href="<?php echo e(route('laporan.buku-besar', ['kode_akun' => '2-1003'])); ?>"
                                           class="ms-1 badge bg-success text-white text-decoration-none no-print" style="font-size:.7rem;">Buku Besar</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            <?php if(count($kewajibanGroups) > 1): ?>
                            <tr>
                                <td colspan="2" class="text-muted fw-semibold small ps-4">Subtotal <?php echo e($grup['label']); ?></td>
                                <td class="text-end fw-semibold">Rp <?php echo e(number_format($grup['subtotal'], 0, ',', '.')); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </tbody>
                        <tfoot class="fw-bold">
                            <tr><td colspan="2">Total Kewajiban</td><td class="text-end">Rp <?php echo e(number_format($totalKewajiban, 0, ',', '.')); ?></td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header fw-bold" style="background:#ccfbf1;color:#0f766e;">DANA SYIRKAH TEMPORER</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Kode</th><th>Nama Akun</th><th class="text-end">Saldo</th></tr></thead>
                        <tbody>
                            <?php $__currentLoopData = $dstGroups; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $grup): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr class="table-light">
                                <td colspan="3" class="fw-semibold text-uppercase small" style="letter-spacing:.03em;color:#0f766e;">
                                    <?php echo e($grup['label']); ?>

                                </td>
                            </tr>
                            <?php $__currentLoopData = $grup['akun']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $d): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr>
                                <td><code><?php echo e($d->kode_akun); ?></code></td>
                                <td><?php echo e($d->nama_akun); ?></td>
                                <td class="text-end">
                                    Rp <?php echo e(number_format($d->saldoBerjalan($sampai), 0, ',', '.')); ?>

                                    <?php if($d->kode_akun === '2-1003'): ?>
                                        <a href="<?php echo e(route('laporan.buku-besar', ['kode_akun' => '2-1003'])); ?>"
                                           class="ms-1 badge bg-success text-white text-decoration-none no-print" style="font-size:.7rem;">Buku Besar</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </tbody>
                        <tfoot class="fw-bold">
                            <tr><td colspan="2">Total Dana Syirkah Temporer</td><td class="text-end">Rp <?php echo e(number_format($totalDanaSyirkahTemporer, 0, ',', '.')); ?></td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header fw-bold" style="background:#e0e7ff;color:#3730a3;">EKUITAS / MODAL SENDIRI</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Kode</th><th>Nama Akun</th><th class="text-end">Saldo</th></tr></thead>
                        <tbody>
                            <?php $__currentLoopData = $ekuitasGroups; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $grup): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr class="table-light">
                                <td colspan="3" class="fw-semibold text-uppercase small" style="letter-spacing:.03em;color:#3730a3;">
                                    <?php echo e($grup['label']); ?>

                                </td>
                            </tr>
                            <?php $__currentLoopData = $grup['akun']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $e): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <?php $saldoEkuitas = round($e->saldoBerjalanLaporan($sampai), 2); ?>
                            <tr>
                                <td><code><?php echo e($e->kode_akun); ?></code></td>
                                <td>
                                    <?php echo e($e->nama_akun); ?>

                                    <?php if($e->kode_akun === '3-2001'): ?>
                                        <span class="badge ms-1" style="background:#6366f1!important;">Dana Cadangan</span>
                                    <?php endif; ?>
                                    <?php if($saldoEkuitas < -0.01): ?>
                                        <span class="badge ms-1" style="background:#fee2e2!important;color:#991b1b!important;" title="Akun Ekuitas bersaldo minus: ada mutasi Debet yang belum ditutup jurnal penutup lawan.">
                                            Saldo Minus
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end <?php echo e($e->kode_akun === '3-2001' ? 'fw-bold' : ''); ?> <?php echo e($saldoEkuitas < -0.01 ? 'text-danger fw-semibold' : ''); ?>">
                                    <?php echo e($saldoEkuitas < 0 ? '(' : ''); ?>Rp <?php echo e(number_format(abs($saldoEkuitas), 0, ',', '.')); ?><?php echo e($saldoEkuitas < 0 ? ')' : ''); ?>

                                </td>
                            </tr>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            <?php if(count($ekuitasGroups) > 1): ?>
                            <tr>
                                <td colspan="2" class="text-muted fw-semibold small ps-4">Subtotal <?php echo e($grup['label']); ?></td>
                                <td class="text-end fw-semibold">Rp <?php echo e(number_format($grup['subtotal'], 0, ',', '.')); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

                            
                            <tr style="background:#f8fafc;">
                                <td><code>&mdash;</code></td>
                                <td>
                                    Laba/Rugi Berjalan
                                    <span class="badge ms-1" style="background:<?php echo e($badgeLaba['bg']); ?>!important;color:<?php echo e($badgeLaba['fg']); ?>!important;border:1px solid <?php echo e($badgeLaba['border']); ?>!important;font-size:.7rem;">
                                        <?php echo e($badgeLaba['label']); ?>

                                    </span>
                                    <div class="small text-muted" style="font-size:.7rem;">
                                        Pendapatan Rp <?php echo e(number_format($totalPendapatan, 0, ',', '.')); ?>

                                        &minus; Beban Rp <?php echo e(number_format($totalBeban, 0, ',', '.')); ?>

                                    </div>
                                </td>
                                <td class="text-end fw-semibold <?php echo e($labaBersih < -0.01 ? 'text-danger' : ($labaBersih > 0.01 ? 'text-success' : 'text-muted')); ?>">
                                    <?php echo e($labaBersih < 0 ? '(' : ''); ?>Rp <?php echo e(number_format(abs($labaBersih), 0, ',', '.')); ?><?php echo e($labaBersih < 0 ? ')' : ''); ?>

                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="fw-bold">
                            <tr>
                                <td colspan="2">Total Ekuitas</td>
                                <td class="text-end">Rp <?php echo e(number_format($totalEkuitas, 0, ',', '.')); ?></td>
                            </tr>
                            <tr>
                                <td colspan="2">Laba/Rugi Berjalan
                                    <span class="badge bg-light text-dark border ms-1" style="font-size:.7rem;">Pendapatan &minus; Beban</span>
                                </td>
                                <td class="text-end <?php echo e($labaBersih < -0.01 ? 'text-danger' : 'text-success'); ?>">
                                    <?php echo e($labaBersih < 0 ? '(' : ''); ?>Rp <?php echo e(number_format(abs($labaBersih), 0, ',', '.')); ?><?php echo e($labaBersih < 0 ? ')' : ''); ?>

                                </td>
                            </tr>
                            <tr style="background:#eff6ff;">
                                <td colspan="2">TOTAL PASIVA
                                    <div class="small text-muted fw-normal" style="font-size:.7rem;">
                                        Kewajiban + Dana Syirkah Temporer + Ekuitas + Laba/Rugi Berjalan
                                    </div>
                                </td>
                                <td class="text-end" style="color:#1d4ed8;">Rp <?php echo e(number_format($totalPasiva, 0, ',', '.')); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    
    <?php
        /* Selisih harus benar-benar nol (bukan "dianggap nol" karena pembulatan).
         | Bila tidak nol, penyebabnya ditampilkan agar bisa ditindaklanjuti, bukan
         | disembunyikan dengan menyerap selisih ke salah satu sisi. */
        $akunEkuitasMinus = collect($ekuitasGroups)
            ->flatMap(fn ($g) => $g['akun'])
            ->filter(fn ($e) => round($e->saldoBerjalanLaporan($sampai), 2) < -0.01)
            ->values();
    ?>
    <div class="card border-0 shadow-sm mt-3">
        <div class="card-body py-3">
            <div class="row g-3 align-items-center">
                <div class="col-md-4">
                    <div class="small text-muted">Total Aset (Aktiva)</div>
                    <div class="fw-bold fs-5">Rp <?php echo e(number_format($totalAset, 0, ',', '.')); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted">Total Pasiva</div>
                    <div class="fw-bold fs-5" style="color:#1d4ed8;">Rp <?php echo e(number_format($totalPasiva, 0, ',', '.')); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted">Selisih (Aset &minus; Pasiva)</div>
                    <div class="fw-bold fs-5 <?php echo e(abs($balance) > 0.01 ? 'text-danger' : 'text-success'); ?>">
                        Rp <?php echo e(number_format(abs($balance), 2, ',', '.')); ?>

                        <?php if(abs($balance) <= 0.01): ?>
                            <span class="badge bg-success ms-1" style="font-size:.7rem;">BALANCE</span>
                        <?php else: ?>
                            <span class="badge bg-danger ms-1" style="font-size:.7rem;">TIDAK BALANCE</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if($akunEkuitasMinus->isNotEmpty()): ?>
            <div class="alert alert-warning mt-3 mb-0 py-2 small">
                <strong>Temuan:</strong> <?php echo e($akunEkuitasMinus->count()); ?> akun Ekuitas bersaldo minus
                (<?php echo e($akunEkuitasMinus->pluck('kode_akun')->implode(', ')); ?>).
                Saldo minus pada akun Ekuitas berarti ada mutasi Debet tanpa jurnal penutup lawan —
                biasanya sisa distribusi SHU sebelum Tutup Buku dijalankan.
                Bersihkan melalui tombol <em>Perbaiki Akun 3-9000</em> pada halaman
                

                <?php if(\App\Support\HierarkiRole::dalamKelompok(auth()->user()->role, 'pengelola_coa')): ?>
                <a href="<?php echo e(route('pengurus.coa.index')); ?>">Bagan Akun</a>.
                <?php else: ?>
                Bagan Akun (wewenang Super Admin).
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/laporan/neraca.blade.php ENDPATH**/ ?>