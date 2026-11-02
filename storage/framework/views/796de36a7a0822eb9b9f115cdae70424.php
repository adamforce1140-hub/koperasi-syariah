
<?php $__env->startSection('page-title', "Laporan Keuangan Syariah (PSAK 101)"); ?>

<?php $__env->startSection('title', 'Laporan Keuangan Syariah (PSAK 101)'); ?>

<?php $__env->startSection('styles'); ?>
<style>
    /* Cetak / simpan PDF: sembunyikan kerangka aplikasi & kontrol filter. */
    @media print {
        .sidebar, .topbar, .no-print { display: none !important; }
        .main-wrapper { margin-left: 0 !important; }
        .content { padding: 0 !important; }
        body { background: #fff !important; }
        .card { box-shadow: none !important; border: 1px solid #cbd5e1 !important; }
        a[href]:after { content: ""; }
        .bagian-laporan { page-break-inside: avoid; }
    }

    .bagian-laporan { scroll-margin-top: 90px; }

    .judul-bagian {
        font-size: .95rem;
        font-weight: 700;
        letter-spacing: .02em;
        text-transform: uppercase;
    }

    .baris-kelompok {
        font-weight: 600;
        font-size: .78rem;
        letter-spacing: .03em;
        text-transform: uppercase;
    }

    .tabel-laporan td, .tabel-laporan th { vertical-align: middle; }
    .tabel-laporan tfoot .baris-total-utama { background: #eff6ff; font-weight: 700; }
    .tabel-laporan .baris-subtotal { background: #f8fafc; font-weight: 600; }
    .tabel-laporan .baris-sub { font-size: .9rem; }

    .nav-bagian .nav-link {
        border-radius: 999px;
        font-size: .8rem;
        padding: .3rem .8rem;
        color: #475569;
        border: 1px solid #e2e8f0;
        background: #fff;
    }
    .nav-bagian .nav-link:hover { background: #f1f5f9; }

    .angka-negatif { color: #b91c1c; }
</style>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<?php
    /* Format rupiah seragam untuk seluruh komponen. Nilai minus disajikan
     | dalam tanda kurung — konvensi akuntansi — bukan dengan tanda minus di
     | depan "Rp", yang mudah terlewat saat dibaca cepat. */
    $fmt = function ($nilai, int $desimal = 0): string {
        $n = (float) $nilai;
        return ($n < 0 ? '(' : '')
            . 'Rp ' . number_format(abs($n), $desimal, ',', '.')
            . ($n < 0 ? ')' : '');
    };

    $p     = $komponen['posisi'];
    $lr    = $komponen['labaRugi'];
    $pe    = $komponen['perubahanEkuitas'];
    $ak    = $komponen['arusKas'];
    $dit   = $komponen['dit'];
    $calk  = $komponen['kepatuhan'];
    $keb   = $komponen['kebijakan'];
    $cat   = $komponen['catatan'];
    $diag  = $komponen['diagnostik'];
    $zkt   = $komponen['zakat'];

    $sampai = $periode['sampai'];

    $badgeLaba = [
        'untung' => ['label' => 'Surplus', 'bg' => '#dcfce7', 'fg' => '#166534', 'border' => '#86efac'],
        'rugi'   => ['label' => 'Defisit', 'bg' => '#fee2e2', 'fg' => '#991b1b', 'border' => '#fca5a5'],
        'impas'  => ['label' => 'Impas',   'bg' => '#f1f5f9', 'fg' => '#475569', 'border' => '#cbd5e1'],
    ][$lr['statusLaba']];

    $bagian = [
        ['id' => 'posisi',   'label' => '1. Posisi Keuangan'],
        ['id' => 'labarugi', 'label' => '2. Laba Rugi'],
        ['id' => 'ekuitas',  'label' => '3. Perubahan Ekuitas'],
        ['id' => 'aruskas',  'label' => '4. Arus Kas'],
        ['id' => 'dit',      'label' => '5. Perubahan DIT'],
        ['id' => 'calk',     'label' => '6. Catatan (CaLK)'],
    ];
?>

<div class="container-fluid px-4 py-3">

    
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-1">Laporan Keuangan Syariah</h4>
            <p class="text-muted mb-0 small">
                Periode <?php echo e($periode['dari']->translatedFormat('d F Y')); ?>

                &ndash; <?php echo e($sampai->translatedFormat('d F Y')); ?>

                &mdash; Standar PSAK 101
                <?php if($mode === 'akhir_bulan'): ?>
                    <span class="badge bg-secondary ms-1" style="font-size:.68rem;">Akhir Bulan</span>
                <?php endif; ?>
            </p>
        </div>
        <div class="text-end">
            <?php if($calk['patuh']): ?>
                <span class="badge" style="background:#dcfce7;color:#166534;border:1px solid #86efac;">
                    Penyajian Patuh PSAK 101
                </span>
            <?php else: ?>
                <span class="badge" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;">
                    <?php echo e(count($calk['temuan'])); ?> Temuan Penyajian
                </span>
            <?php endif; ?>
        </div>
    </div>

    
    <div class="card border-0 shadow-sm mb-4 no-print">
        <div class="card-body py-3">
            <form method="GET" action="<?php echo e(route('laporan.psak101')); ?>"
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
                <button type="submit" class="btn btn-primary btn-sm">Tampilkan Laporan</button>

                <?php if($bolehUnduh): ?>
                <button type="button" class="btn btn-danger btn-sm" onclick="window.print()">Cetak PDF</button>
                <?php endif; ?>
                <?php if($bolehEkspor): ?>
                <a href="<?php echo e(route('laporan.psak101', ['mode' => $mode, 'tanggal' => $tanggalInput, 'export' => 'excel'])); ?>"
                   class="btn btn-success btn-sm">Export Excel</a>
                <?php endif; ?>

                <div class="ms-auto small text-muted">
                    Seluruh angka dihitung dari jurnal <strong>Posted</strong>
                    s/d <strong><?php echo e($sampai->translatedFormat('d F Y')); ?></strong>
                    (batas <code>tanggal_jurnal</code>).
                </div>
            </form>
        </div>
    </div>

    
    <?php if(! $calk['patuh']): ?>
    <div class="alert alert-warning">
        <strong>Temuan penyajian:</strong> laporan tetap disajikan lengkap, namun
        <?php echo e(count($calk['temuan'])); ?> hal berikut perlu ditindaklanjuti agar penyajian
        sepenuhnya patuh PSAK 101.
        <ul class="mb-0 mt-2 small">
            <?php $__currentLoopData = $calk['temuan']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $temuan): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <li><?php echo e($temuan); ?></li>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </ul>
    </div>
    <?php else: ?>
    <div class="alert alert-success py-2 small">
        <strong>Penyajian patuh.</strong> Seluruh akun aktif telah dipetakan ke klasifikasi
        PSAK 101, kelompok penyajian wajib terisi, dan laporan disusun dari buku yang seimbang.
    </div>
    <?php endif; ?>

    
    <nav class="nav nav-bagian gap-2 mb-4 no-print">
        <?php $__currentLoopData = $bagian; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $b): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <a class="nav-link" href="#<?php echo e($b['id']); ?>"><?php echo e($b['label']); ?></a>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </nav>

    
    <div class="card border-0 shadow-sm mb-4 bagian-laporan" id="posisi">
        <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
            <span class="judul-bagian mb-0">1. Laporan Posisi Keuangan</span>
            <span class="small text-muted">Per <?php echo e($sampai->translatedFormat('d F Y')); ?></span>
        </div>
        <div class="card-body">
            <?php if(! $p['seimbang']): ?>
            <div class="alert alert-warning py-2 small">
                <strong>Perhatian:</strong> Total Aset &minus; Total Pasiva =
                <?php echo e($fmt($p['selisihNeraca'], 2)); ?>. Laporan belum seimbang.
            </div>
            <?php endif; ?>

            <div class="row g-4">
                
                <div class="col-lg-6">
                    <table class="table table-sm tabel-laporan mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Kode</th>
                                <th>Nama Akun</th>
                                <th class="text-end">Saldo</th>
                                <th class="text-end">Saldo Awal Tahun</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $__currentLoopData = $p['asetGroups']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $grup): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr class="table-light">
                                <td colspan="4" class="baris-kelompok" style="color:#1e40af;"><?php echo e($grup['label']); ?></td>
                            </tr>
                            <?php $__currentLoopData = $grup['akun']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $a): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr class="baris-sub">
                                <td><code><?php echo e($a->kode_akun); ?></code></td>
                                <td><?php echo e($a->nama_akun); ?></td>
                                <td class="text-end"><?php echo e($fmt($a->saldoBerjalan($periode['sampaiStr']))); ?></td>
                                <td class="text-end text-muted"><?php echo e($fmt($a->saldoBerjalan($periode['sampaiLaluStr']))); ?></td>
                            </tr>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            <tr class="baris-subtotal">
                                <td colspan="3" class="text-muted small ps-4">Subtotal <?php echo e($grup['label']); ?></td>
                                <td class="text-end"></td>
                            </tr>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </tbody>
                        <tfoot>
                            <tr class="baris-total-utama">
                                <td colspan="3">TOTAL ASET</td>
                                <td class="text-end"><?php echo e($fmt($p['totalAset'])); ?></td>
                            </tr>
                            <tr class="small text-muted">
                                <td colspan="3">Pembanding tahun lalu</td>
                                <td class="text-end"><?php echo e($fmt($p['totalAsetLalu'])); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                
                <div class="col-lg-6">
                    <table class="table table-sm tabel-laporan mb-3">
                        <thead class="table-light">
                            <tr><th>Kode</th><th>Nama Akun</th><th class="text-end">Saldo</th></tr>
                        </thead>
                        <tbody>
                            <?php $__currentLoopData = $p['kewajibanGroups']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $grup): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr class="table-light">
                                <td colspan="3" class="baris-kelompok" style="color:#92400e;"><?php echo e($grup['label']); ?></td>
                            </tr>
                            <?php $__currentLoopData = $grup['akun']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $k): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr class="baris-sub">
                                <td><code><?php echo e($k->kode_akun); ?></code></td>
                                <td><?php echo e($k->nama_akun); ?></td>
                                <td class="text-end"><?php echo e($fmt($k->saldoBerjalan($periode['sampaiStr']))); ?></td>
                            </tr>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </tbody>
                        <tfoot>
                            <tr class="baris-subtotal">
                                <td colspan="2">Total Kewajiban</td>
                                <td class="text-end"><?php echo e($fmt($p['totalKewajiban'])); ?></td>
                            </tr>
                        </tfoot>
                    </table>

                    <table class="table table-sm tabel-laporan mb-3">
                        <thead class="table-light">
                            <tr><th>Kode</th><th>Nama Akun</th><th class="text-end">Saldo</th></tr>
                        </thead>
                        <tbody>
                            <tr class="table-light">
                                <td colspan="3" class="baris-kelompok" style="color:#0f766e;">Dana Syirkah Temporer</td>
                            </tr>
                            <?php $__currentLoopData = $p['dstGroups']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $grup): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <?php $__currentLoopData = $grup['akun']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $d): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <tr class="baris-sub">
                                    <td><code><?php echo e($d->kode_akun); ?></code></td>
                                    <td>
                                        <?php echo e($d->nama_akun); ?>

                                        <span class="badge ms-1" style="background:#ccfbf1;color:#0f766e;font-size:.68rem;"
                                              title="Dana investasi pemilik dana — disajikan terpisah dari Kewajiban (PSAK 101).">
                                            Dana Investasi
                                        </span>
                                    </td>
                                    <td class="text-end"><?php echo e($fmt($d->saldoBerjalan($periode['sampaiStr']))); ?></td>
                                </tr>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </tbody>
                        <tfoot>
                            <tr class="baris-subtotal">
                                <td colspan="2">Total Dana Syirkah Temporer</td>
                                <td class="text-end"><?php echo e($fmt($p['totalDanaSyirkahTemporer'])); ?></td>
                            </tr>
                        </tfoot>
                    </table>

                    <table class="table table-sm tabel-laporan mb-0">
                        <thead class="table-light">
                            <tr><th>Kode</th><th>Nama Akun</th><th class="text-end">Saldo</th></tr>
                        </thead>
                        <tbody>
                            <?php $__currentLoopData = $p['ekuitasGroups']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $grup): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr class="table-light">
                                <td colspan="3" class="baris-kelompok" style="color:#3730a3;"><?php echo e($grup['label']); ?></td>
                            </tr>
                            <?php $__currentLoopData = $grup['akun']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $e): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <?php $saldoEkuitas = round((float) $e->saldoBerjalanLaporan($periode['sampaiStr']), 2); ?>
                            <tr class="baris-sub">
                                <td><code><?php echo e($e->kode_akun); ?></code></td>
                                <td><?php echo e($e->nama_akun); ?></td>
                                <td class="text-end <?php echo e($saldoEkuitas < -0.01 ? 'angka-negatif' : ''); ?>">
                                    <?php echo e($fmt($saldoEkuitas)); ?>

                                </td>
                            </tr>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            <tr style="background:#f8fafc;">
                                <td><code>&mdash;</code></td>
                                <td>
                                    Laba/Rugi Berjalan
                                    <span class="badge ms-1" style="background:<?php echo e($badgeLaba['bg']); ?>;color:<?php echo e($badgeLaba['fg']); ?>;border:1px solid <?php echo e($badgeLaba['border']); ?>;font-size:.68rem;">
                                        <?php echo e($badgeLaba['label']); ?>

                                    </span>
                                    <div class="text-muted" style="font-size:.72rem;">
                                        Pendapatan <?php echo e($fmt($lr['totalPendapatan'])); ?>

                                        &minus; Beban <?php echo e($fmt($lr['totalBeban'])); ?>

                                    </div>
                                </td>
                                <td class="text-end fw-semibold"><?php echo e($fmt($p['labaBersih'])); ?></td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="baris-subtotal">
                                <td colspan="2">Total Ekuitas</td>
                                <td class="text-end"><?php echo e($fmt($p['totalEkuitas'])); ?></td>
                            </tr>
                            <tr class="baris-total-utama">
                                <td colspan="2">
                                    TOTAL PASIVA
                                    <div class="fw-normal text-muted" style="font-size:.7rem;">
                                        Kewajiban + Dana Syirkah Temporer + Ekuitas + Laba/Rugi Berjalan
                                    </div>
                                </td>
                                <td class="text-end"><?php echo e($fmt($p['totalPasiva'])); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <hr class="my-3">
            <div class="row g-3 text-center">
                <div class="col-md-3">
                    <div class="small text-muted">Total Aset</div>
                    <div class="fw-bold"><?php echo e($fmt($p['totalAset'])); ?></div>
                    <div class="small text-muted">Tahun lalu <?php echo e($fmt($p['totalAsetLalu'])); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="small text-muted">Total Pasiva</div>
                    <div class="fw-bold" style="color:#1d4ed8;"><?php echo e($fmt($p['totalPasiva'])); ?></div>
                    <div class="small text-muted">Tahun lalu <?php echo e($fmt($p['totalPasivaLalu'])); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="small text-muted">Selisih (Aset &minus; Pasiva)</div>
                    <div class="fw-bold <?php echo e($p['seimbang'] ? 'text-success' : 'text-danger'); ?>">
                        <?php echo e($fmt($p['selisihNeraca'], 2)); ?>

                    </div>
                    <div class="small">
                        <?php if($p['seimbang']): ?>
                            <span class="badge bg-success" style="font-size:.68rem;">SEIMBANG</span>
                        <?php else: ?>
                            <span class="badge bg-danger" style="font-size:.68rem;">TIDAK SEIMBANG</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="small text-muted">Laba/Rugi Berjalan</div>
                    <div class="fw-bold <?php echo e($p['labaBersih'] < -0.01 ? 'text-danger' : ($p['labaBersih'] > 0.01 ? 'text-success' : 'text-muted')); ?>">
                        <?php echo e($fmt($p['labaBersih'])); ?>

                    </div>
                    <div class="small text-muted">Tahun lalu <?php echo e($fmt($p['labaBersihLalu'])); ?></div>
                </div>
            </div>
        </div>
    </div>

    
    <div class="card border-0 shadow-sm mb-4 bagian-laporan" id="labarugi">
        <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
            <span class="judul-bagian mb-0">2. Laporan Laba Rugi</span>
            <span class="small text-muted">
                Periode <?php echo e($periode['dari']->translatedFormat('d M Y')); ?>

                &ndash; <?php echo e($sampai->translatedFormat('d M Y')); ?>

            </span>
        </div>
        <div class="card-body">
            <?php if($lr['adaTakKlasifikasi']): ?>
            <div class="alert alert-warning py-2 small">
                Terdapat akun Pendapatan/Beban yang belum dipetakan klasifikasinya.
                Akun tersebut tetap disajikan pada kelompok <em>Belum Diklasifikasi</em>
                agar tidak hilang dari laporan.
            </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table table-sm tabel-laporan mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Kode</th>
                            <th>Uraian</th>
                            <th class="text-end">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="table-light">
                            <td colspan="3" class="baris-kelompok" style="color:#166534;">Pendapatan Usaha</td>
                        </tr>
                        <?php $__currentLoopData = $lr['pendapatanUsaha']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr class="baris-sub">
                            <td><code><?php echo e($r['kode']); ?></code></td>
                            <td><?php echo e($r['nama']); ?></td>
                            <td class="text-end"><?php echo e($fmt($r['saldo'])); ?></td>
                        </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        <tr class="baris-subtotal">
                            <td colspan="2">Jumlah Pendapatan Usaha</td>
                            <td class="text-end"><?php echo e($fmt($lr['totalPendapatanUsaha'])); ?></td>
                        </tr>

                        <?php if($lr['pendapatanLain']->isNotEmpty()): ?>
                        <tr class="table-light">
                            <td colspan="3" class="baris-kelompok" style="color:#166534;">Pendapatan Lain-lain</td>
                        </tr>
                        <?php $__currentLoopData = $lr['pendapatanLain']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr class="baris-sub">
                            <td><code><?php echo e($r['kode']); ?></code></td>
                            <td><?php echo e($r['nama']); ?></td>
                            <td class="text-end"><?php echo e($fmt($r['saldo'])); ?></td>
                        </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        <tr class="baris-subtotal">
                            <td colspan="2">Jumlah Pendapatan Lain-lain</td>
                            <td class="text-end"><?php echo e($fmt($lr['totalPendapatanLain'])); ?></td>
                        </tr>
                        <?php endif; ?>

                        <?php if($lr['pendapatanTakKlasifikasi']->isNotEmpty()): ?>
                        <tr class="table-light">
                            <td colspan="3" class="baris-kelompok" style="color:#92400e;">Pendapatan — Belum Diklasifikasi</td>
                        </tr>
                        <?php $__currentLoopData = $lr['pendapatanTakKlasifikasi']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr class="baris-sub">
                            <td><code><?php echo e($r['kode']); ?></code></td>
                            <td><?php echo e($r['nama']); ?></td>
                            <td class="text-end"><?php echo e($fmt($r['saldo'])); ?></td>
                        </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        <?php endif; ?>

                        <tr class="baris-total-utama">
                            <td colspan="2">TOTAL PENDAPATAN</td>
                            <td class="text-end"><?php echo e($fmt($lr['totalPendapatan'])); ?></td>
                        </tr>

                        <tr class="table-light">
                            <td colspan="3" class="baris-kelompok" style="color:#92400e;">Beban Usaha</td>
                        </tr>
                        <?php $__currentLoopData = $lr['bebanUsaha']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr class="baris-sub">
                            <td><code><?php echo e($r['kode']); ?></code></td>
                            <td><?php echo e($r['nama']); ?></td>
                            <td class="text-end"><?php echo e($fmt($r['saldo'])); ?></td>
                        </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        <tr class="baris-subtotal">
                            <td colspan="2">Jumlah Beban Usaha</td>
                            <td class="text-end"><?php echo e($fmt($lr['totalBebanUsaha'])); ?></td>
                        </tr>

                        <?php if($lr['bebanLain']->isNotEmpty()): ?>
                        <tr class="table-light">
                            <td colspan="3" class="baris-kelompok" style="color:#92400e;">Beban Lain-lain</td>
                        </tr>
                        <?php $__currentLoopData = $lr['bebanLain']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr class="baris-sub">
                            <td><code><?php echo e($r['kode']); ?></code></td>
                            <td><?php echo e($r['nama']); ?></td>
                            <td class="text-end"><?php echo e($fmt($r['saldo'])); ?></td>
                        </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        <tr class="baris-subtotal">
                            <td colspan="2">Jumlah Beban Lain-lain</td>
                            <td class="text-end"><?php echo e($fmt($lr['totalBebanLain'])); ?></td>
                        </tr>
                        <?php endif; ?>

                        <?php if($lr['bebanTakKlasifikasi']->isNotEmpty()): ?>
                        <tr class="table-light">
                            <td colspan="3" class="baris-kelompok" style="color:#92400e;">Beban — Belum Diklasifikasi</td>
                        </tr>
                        <?php $__currentLoopData = $lr['bebanTakKlasifikasi']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr class="baris-sub">
                            <td><code><?php echo e($r['kode']); ?></code></td>
                            <td><?php echo e($r['nama']); ?></td>
                            <td class="text-end"><?php echo e($fmt($r['saldo'])); ?></td>
                        </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        <?php endif; ?>

                        <tr class="baris-total-utama">
                            <td colspan="2">TOTAL BEBAN</td>
                            <td class="text-end"><?php echo e($fmt($lr['totalBeban'])); ?></td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="baris-subtotal">
                            <td colspan="2">Laba Kotor (Pendapatan Usaha &minus; Beban Usaha)</td>
                            <td class="text-end"><?php echo e($fmt($lr['labaKotor'])); ?></td>
                        </tr>
                        <tr class="baris-total-utama">
                            <td colspan="2">
                                LABA (RUGI) BERSIH
                                <span class="badge ms-1" style="background:<?php echo e($badgeLaba['bg']); ?>;color:<?php echo e($badgeLaba['fg']); ?>;border:1px solid <?php echo e($badgeLaba['border']); ?>;font-size:.68rem;">
                                    <?php echo e($badgeLaba['label']); ?>

                                </span>
                            </td>
                            <td class="text-end <?php echo e($lr['labaBersih'] < -0.01 ? 'angka-negatif' : ''); ?>">
                                <?php echo e($fmt($lr['labaBersih'])); ?>

                            </td>
                        </tr>
                        <tr class="small text-muted">
                            <td colspan="2">Margin Laba Kotor / Laba Bersih</td>
                            <td class="text-end">
                                <?php echo e(number_format($lr['marginLabaKotor'], 2, ',', '.')); ?>%
                                / <?php echo e(number_format($lr['marginLabaBersih'], 2, ',', '.')); ?>%
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="alert alert-light border mt-3 mb-0 py-2 small">
                <strong>Catatan:</strong> Dana zakat, infak/sedekah, kebajikan, dan sosial
                <strong>tidak</strong> diperhitungkan sebagai pendapatan koperasi karena
                berstatus dana titipan (amanah) — lihat bagian 5 (PSAK 109).
            </div>
        </div>
    </div>

    
    <div class="card border-0 shadow-sm mb-4 bagian-laporan" id="ekuitas">
        <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
            <span class="judul-bagian mb-0">3. Laporan Perubahan Ekuitas</span>
            <span class="small text-muted">
                Periode <?php echo e($periode['dari']->translatedFormat('d M Y')); ?>

                &ndash; <?php echo e($sampai->translatedFormat('d M Y')); ?>

            </span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm tabel-laporan mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Kode</th>
                            <th>Komponen Ekuitas</th>
                            <th class="text-end">Saldo Awal</th>
                            <th class="text-end">Penambah</th>
                            <th class="text-end">Pengurang</th>
                            <th class="text-end">Saldo Akhir</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $__currentLoopData = $pe['baris']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr class="baris-sub">
                            <td><code><?php echo e($r['kode']); ?></code></td>
                            <td>
                                <?php echo e($r['nama']); ?>

                                <?php if($r['klasifikasi']): ?>
                                    <span class="badge bg-light text-dark border ms-1" style="font-size:.66rem;"><?php echo e($r['klasifikasi']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?php echo e($fmt($r['saldoAwal'])); ?></td>
                            <td class="text-end text-success"><?php echo e($fmt($r['penambah'])); ?></td>
                            <td class="text-end text-danger"><?php echo e($fmt($r['pengurang'])); ?></td>
                            <td class="text-end fw-semibold"><?php echo e($fmt($r['saldoAkhir'])); ?></td>
                        </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                    <tfoot>
                        <tr class="baris-subtotal">
                            <td colspan="2">Subtotal</td>
                            <td class="text-end"><?php echo e($fmt($pe['totalAwal'])); ?></td>
                            <td class="text-end"><?php echo e($fmt($pe['totalPenambah'])); ?></td>
                            <td class="text-end"><?php echo e($fmt($pe['totalPengurang'])); ?></td>
                            <td class="text-end"><?php echo e($fmt($pe['totalAkhir'])); ?></td>
                        </tr>
                        <tr>
                            <td colspan="2" class="ps-4 small">
                                Laba (Rugi) Bersih Periode Berjalan
                                <div class="text-muted" style="font-size:.7rem;">
                                    Belum ditutup ke akun ekuitas; dipindahkan saat Tutup Buku.
                                </div>
                            </td>
                            <td class="text-end"></td>
                            <td class="text-end <?php echo e($pe['labaBersih'] < -0.01 ? 'angka-negatif' : ''); ?>"><?php echo e($fmt($pe['labaBersih'])); ?></td>
                            <td class="text-end"></td>
                            <td class="text-end <?php echo e($pe['labaBersih'] < -0.01 ? 'angka-negatif' : ''); ?>"><?php echo e($fmt($pe['labaBersih'])); ?></td>
                        </tr>
                        <tr class="baris-total-utama">
                            <td colspan="2">TOTAL EKUITAS (termasuk laba berjalan)</td>
                            <td class="text-end"><?php echo e($fmt($pe['totalAwalTermasukLaba'])); ?></td>
                            <td class="text-end"></td>
                            <td class="text-end"></td>
                            <td class="text-end"><?php echo e($fmt($pe['totalAkhirTermasukLaba'])); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="small text-muted mt-3 mb-0">
                Saldo awal dihitung per <?php echo e($periode['dari']->copy()->subDay()->translatedFormat('d F Y')); ?>

                (hari sebelum periode berjalan), sehingga angka pada tabel ini dapat
                direkonsiliasi dengan Total Ekuitas pada Laporan Posisi Keuangan.
            </p>
        </div>
    </div>

    
    <div class="card border-0 shadow-sm mb-4 bagian-laporan" id="aruskas">
        <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
            <span class="judul-bagian mb-0">4. Laporan Arus Kas (Metode Langsung)</span>
            <span class="small text-muted">
                Periode <?php echo e($periode['dari']->translatedFormat('d M Y')); ?>

                &ndash; <?php echo e($sampai->translatedFormat('d M Y')); ?>

            </span>
        </div>
        <div class="card-body">
            <?php if(! $ak['rekonsiliasiCocok']): ?>
            <div class="alert alert-warning py-2 small">
                <strong>Selisih rekonsiliasi:</strong> <?php echo e($fmt($ak['selisihRekonsiliasi'], 2)); ?>.
                Selisih tidak ditutup dengan akun penyeimbang fiktif; penyebabnya
                diungkap pada bagian Catatan (CaLK).
            </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table table-sm tabel-laporan mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Uraian</th>
                            <th class="text-end">Masuk</th>
                            <th class="text-end">Keluar</th>
                            <th class="text-end">Neto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $__currentLoopData = ['operasi' => $ak['operasi'], 'investasi' => $ak['investasi'], 'pendanaan' => $ak['pendanaan'], 'lainnya' => $ak['lainnya']]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $kunci => $bag): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <?php if($bag['baris']->isEmpty() && $kunci === 'lainnya'): ?>
                                <?php continue; ?>
                            <?php endif; ?>
                            <tr class="table-light">
                                <td colspan="4" class="baris-kelompok"
                                    style="color:<?php echo e($kunci === 'operasi' ? '#166534' : ($kunci === 'investasi' ? '#1e40af' : ($kunci === 'pendanaan' ? '#7e22ce' : '#92400e'))); ?>;">
                                    <?php echo e($bag['label']); ?>

                                </td>
                            </tr>
                            <?php $__empty_1 = true; $__currentLoopData = $bag['baris']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $b): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                            <tr class="baris-sub">
                                <td>
                                    <?php echo e($b['label']); ?>

                                    <code class="ms-1 text-muted" style="font-size:.72rem;"><?php echo e($b['akun']); ?></code>
                                </td>
                                <td class="text-end"><?php echo e($b['masuk'] > 0 ? $fmt($b['masuk']) : '&mdash;'); ?></td>
                                <td class="text-end"><?php echo e($b['keluar'] > 0 ? $fmt($b['keluar']) : '&mdash;'); ?></td>
                                <td class="text-end <?php echo e($b['neto'] < 0 ? 'angka-negatif' : ''); ?>"><?php echo e($fmt($b['neto'])); ?></td>
                            </tr>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                            <tr class="baris-sub text-muted">
                                <td colspan="4"><em>Tidak ada mutasi kas pada aktivitas ini.</em></td>
                            </tr>
                            <?php endif; ?>
                            <tr class="baris-subtotal">
                                <td colspan="3">Jumlah <?php echo e($bag['label']); ?></td>
                                <td class="text-end <?php echo e($bag['total'] < 0 ? 'angka-negatif' : ''); ?>"><?php echo e($fmt($bag['total'])); ?></td>
                            </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                    <tfoot>
                        <tr class="baris-subtotal">
                            <td colspan="3">Kenaikan (Penurunan) Kas Bersih</td>
                            <td class="text-end <?php echo e($ak['kenaikanKas'] < 0 ? 'angka-negatif' : ''); ?>"><?php echo e($fmt($ak['kenaikanKas'])); ?></td>
                        </tr>
                        <tr>
                            <td colspan="3">Saldo Kas &amp; Bank Awal Periode</td>
                            <td class="text-end"><?php echo e($fmt($ak['saldoAwalKas'])); ?></td>
                        </tr>
                        <tr class="baris-total-utama">
                            <td colspan="3">SALDO KAS &amp; BANK AKHIR PERIODE</td>
                            <td class="text-end"><?php echo e($fmt($ak['saldoAkhirKas'])); ?></td>
                        </tr>
                        <tr class="small">
                            <td colspan="3" class="text-muted">
                                Verifikasi: kenaikan menurut mutasi saldo kas
                                (<?php echo e($fmt($ak['kenaikanMenurutSaldo'])); ?>) vs jumlah aktivitas
                                (<?php echo e($fmt($ak['kenaikanKas'])); ?>)
                            </td>
                            <td class="text-end">
                                <?php if($ak['rekonsiliasiCocok']): ?>
                                    <span class="badge bg-success" style="font-size:.68rem;">COCOK</span>
                                <?php else: ?>
                                    <span class="badge bg-danger" style="font-size:.68rem;">SELISIH <?php echo e($fmt($ak['selisihRekonsiliasi'], 2)); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="small text-muted mt-3 mb-0">
                Disusun dari mutasi akun kas &amp; bank pada jurnal; setiap mutasi
                diatribusikan ke aktivitas berdasarkan akun lawannya dalam jurnal yang sama.
                Perpindahan antar-kas (mis. setor tunai ke bank) tidak dihitung ganda.
            </p>
        </div>
    </div>

    
    <div class="card border-0 shadow-sm mb-4 bagian-laporan" id="dit">
        <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
            <span class="judul-bagian mb-0">5. Laporan Perubahan Dana Investasi Terikat</span>
            <span class="small text-muted">PSAK 109</span>
        </div>
        <div class="card-body">
            <p class="small text-muted">
                Dana zakat, infak/sedekah, kebajikan, dan sosial adalah dana <strong>titipan
                (amanah)</strong> — bukan pendapatan koperasi dan bukan bagian ekuitas koperasi.
                Penyaluran dana zakat hanya kepada 8 asnaf (QS At-Taubah 60 jo. Fatwa DSN-MUI
                No. 08/DSN-MUI/IV/2000).
            </p>

            <?php if($dit['adaAkunHilang']): ?>
            <div class="alert alert-warning py-2 small">
                Sebagian akun dana titipan tidak ditemukan pada Bagan Akun, sehingga
                disajikan bernilai nol. Periksa pemetaan akun dana titipan.
            </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table table-sm tabel-laporan mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Jenis Dana</th>
                            <th>Kode</th>
                            <th class="text-end">Saldo Awal</th>
                            <th class="text-end">Penerimaan</th>
                            <th class="text-end">Penyaluran</th>
                            <th class="text-end">Saldo Akhir</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $__currentLoopData = $dit['baris']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr class="baris-sub <?php echo e($r['akunTersedia'] ? '' : 'text-muted'); ?>">
                            <td>
                                <?php echo e($r['label']); ?>

                                <?php if (! ($r['akunTersedia'])): ?>
                                    <span class="badge bg-warning text-dark ms-1" style="font-size:.66rem;">Akun tidak ditemukan</span>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo e($r['kode']); ?></code></td>
                            <td class="text-end"><?php echo e($fmt($r['saldoAwal'])); ?></td>
                            <td class="text-end text-success"><?php echo e($fmt($r['penerimaan'])); ?></td>
                            <td class="text-end text-danger"><?php echo e($fmt($r['penyaluran'])); ?></td>
                            <td class="text-end fw-semibold"><?php echo e($fmt($r['saldoAkhir'])); ?></td>
                        </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                    <tfoot>
                        <tr class="baris-total-utama">
                            <td colspan="2">TOTAL</td>
                            <td class="text-end"><?php echo e($fmt($dit['totalAwal'])); ?></td>
                            <td class="text-end"><?php echo e($fmt($dit['totalPenerimaan'])); ?></td>
                            <td class="text-end"><?php echo e($fmt($dit['totalPenyaluran'])); ?></td>
                            <td class="text-end"><?php echo e($fmt($dit['totalAkhir'])); ?></td>
                        </tr>
                        <tr class="small">
                            <td colspan="6">
                                <?php if($dit['mutasiSeimbang']): ?>
                                    <span class="badge bg-success" style="font-size:.68rem;">MUTASI SEIMBANG</span>
                                    <span class="text-muted ms-1">Saldo Awal + Penerimaan &minus; Penyaluran = Saldo Akhir.</span>
                                <?php else: ?>
                                    <span class="badge bg-danger" style="font-size:.68rem;">MUTASI TIDAK SEIMBANG</span>
                                    <span class="text-muted ms-1">Periksa jurnal dana titipan yang belum diposting.</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="small text-muted mt-3 mb-0">
                Saldo dihitung dari mutasi jurnal (kredit &minus; debet), bukan dari akumulasi
                tabel transaksi modul. Perbedaan keduanya merupakan temuan audit: menandakan
                penerimaan/penyaluran yang tercatat di modul tetapi jurnalnya belum diposting.
            </p>
        </div>
    </div>

    
    <div class="card border-0 shadow-sm mb-4 bagian-laporan" id="calk">
        <div class="card-header bg-white py-3">
            <span class="judul-bagian mb-0">6. Catatan atas Laporan Keuangan (CaLK)</span>
        </div>
        <div class="card-body">

            
            <h6 class="fw-bold mb-3">6.a Kebijakan Akuntansi &amp; Asumsi</h6>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <table class="table table-sm mb-0">
                        <tbody>
                            <tr><th class="text-muted fw-semibold small" style="width:38%;">Dasar penyusunan</th><td class="small"><?php echo e($keb['dasarPenyusunan']); ?></td></tr>
                            <tr><th class="text-muted fw-semibold small">Mata uang</th><td class="small"><?php echo e($keb['mataUang']); ?></td></tr>
                            <tr><th class="text-muted fw-semibold small">Pembulatan</th><td class="small"><?php echo e($keb['pembulatan']); ?></td></tr>
                            <tr><th class="text-muted fw-semibold small">Sumber kebenaran nilai</th><td class="small"><?php echo e($keb['sumberKebenaran']); ?></td></tr>
                            <tr><th class="text-muted fw-semibold small">Kolom kanonik</th><td class="small"><?php echo e($keb['kolomKanonik']); ?></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm mb-0">
                        <tbody>
                            <tr><th class="text-muted fw-semibold small" style="width:38%;">Dana titipan (PSAK 109)</th><td class="small"><?php echo e($keb['danaTitipan']); ?></td></tr>
                            <tr><th class="text-muted fw-semibold small">Dana Syirkah Temporer</th><td class="small"><?php echo e($keb['danaSyirkahTemporer']); ?></td></tr>
                            <tr><th class="text-muted fw-semibold small">Batas penyaluran zakat</th><td class="small"><?php echo e($keb['batasZakat']); ?></td></tr>
                            <tr>
                                <th class="text-muted fw-semibold small">Toleransi selisih jurnal</th>
                                <td class="small"><?php echo e($fmt($keb['toleransiSelisih'], 2)); ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted fw-semibold small">Nisbah / Margin default</th>
                                <td class="small">
                                    <?php echo e(number_format($keb['nisbahDefaultPersen'], 2, ',', '.')); ?>%
                                    / <?php echo e(number_format($keb['marginDefaultPersen'], 2, ',', '.')); ?>%
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="alert alert-light border py-2 small mb-4">
                <strong>Standar acuan:</strong> <?php echo e($keb['standar']); ?>

            </div>

            
            <h6 class="fw-bold mb-3">6.b Rincian Piutang Pembiayaan per Akad</h6>
            <div class="table-responsive mb-4">
                <table class="table table-sm tabel-laporan mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Akad</th>
                            <th>Kode</th>
                            <th>Nama Akun</th>
                            <th class="text-end">Saldo</th>
                            <th class="text-end">Mutasi Debet</th>
                            <th class="text-end">Mutasi Kredit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $__currentLoopData = $cat['piutang']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr class="baris-sub">
                            <td><?php echo e($r['akad']); ?></td>
                            <td><code><?php echo e($r['kode']); ?></code></td>
                            <td><?php echo e($r['nama']); ?></td>
                            <td class="text-end"><?php echo e($fmt($r['saldo'])); ?></td>
                            <td class="text-end"><?php echo e($fmt($r['mutasi']['debet'])); ?></td>
                            <td class="text-end"><?php echo e($fmt($r['mutasi']['kredit'])); ?></td>
                        </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                    <tfoot>
                        <tr class="baris-total-utama">
                            <td colspan="3">TOTAL PIUTANG PEMBIAYAAN</td>
                            <td class="text-end"><?php echo e($fmt($cat['totalPiutang'])); ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            
            <h6 class="fw-bold mb-3">6.c Penyisihan Kerugian (CKPN) &amp; Margin Ditangguhkan</h6>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th colspan="2">Cadangan Kerugian Penurunan Nilai</th></tr></thead>
                        <tbody>
                            <tr><th class="text-muted fw-semibold small">Akun</th><td class="small"><code><?php echo e($cat['ckpn']['kode']); ?></code> <?php echo e($cat['ckpn']['nama']); ?></td></tr>
                            <tr><th class="text-muted fw-semibold small">Saldo</th><td class="small fw-semibold"><?php echo e($fmt($cat['ckpn']['saldo'])); ?></td></tr>
                            <tr><th class="text-muted fw-semibold small">Mutasi periode</th><td class="small">D <?php echo e($fmt($cat['ckpn']['mutasi']['debet'])); ?> / K <?php echo e($fmt($cat['ckpn']['mutasi']['kredit'])); ?></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th colspan="2">Persentase CKPN per Kolektibilitas</th></tr></thead>
                        <tbody>
                            <?php $__currentLoopData = $cat['ckpn']['persen']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $kode => $persen): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr>
                                <th class="text-muted fw-semibold small text-uppercase"><?php echo e($kode); ?></th>
                                <td class="small"><?php echo e(number_format((float) $persen, 2, ',', '.')); ?>%</td>
                            </tr>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </tbody>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th colspan="2">Margin Murabahah Ditangguhkan</th></tr></thead>
                        <tbody>
                            <tr><th class="text-muted fw-semibold small">Akun</th><td class="small"><code><?php echo e($cat['marginDitangguhkan']['kode']); ?></code> <?php echo e($cat['marginDitangguhkan']['nama']); ?></td></tr>
                            <tr><th class="text-muted fw-semibold small">Saldo</th><td class="small fw-semibold"><?php echo e($fmt($cat['marginDitangguhkan']['saldo'])); ?></td></tr>
                            <tr><th class="text-muted fw-semibold small">Mutasi periode</th><td class="small">D <?php echo e($fmt($cat['marginDitangguhkan']['mutasi']['debet'])); ?> / K <?php echo e($fmt($cat['marginDitangguhkan']['mutasi']['kredit'])); ?></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th colspan="2">Ambang Kolektibilitas (hari tunggakan)</th></tr></thead>
                        <tbody>
                            
                            <?php $batasBawah = 0; ?>
                            <?php $__currentLoopData = $keb['ambangKolektibilitas']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $kode => $hari): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr>
                                <th class="text-muted fw-semibold small text-uppercase"><?php echo e($kode); ?></th>
                                <td class="small"><?php echo e((int) $batasBawah); ?>–<?php echo e((int) $hari); ?> hari</td>
                            </tr>
                            <?php $batasBawah = (int) $hari + 1; ?>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </tbody>
                    </table>
                </div>
            </div>

            
            <h6 class="fw-bold mb-3">6.d Rincian Kas &amp; Bank</h6>
            <div class="table-responsive mb-4">
                <table class="table table-sm tabel-laporan mb-0">
                    <thead class="table-light">
                        <tr><th>Kode</th><th>Nama Akun</th><th class="text-end">Saldo</th></tr>
                    </thead>
                    <tbody>
                        <?php $__currentLoopData = $cat['kas']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr class="baris-sub">
                            <td><code><?php echo e($r['kode']); ?></code></td>
                            <td><?php echo e($r['nama']); ?></td>
                            <td class="text-end"><?php echo e($fmt($r['saldo'])); ?></td>
                        </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                    <tfoot>
                        <tr class="baris-total-utama">
                            <td colspan="2">TOTAL KAS &amp; BANK</td>
                            <td class="text-end"><?php echo e($fmt($ak['saldoAkhirKas'])); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            
            <h6 class="fw-bold mb-3">6.e Kelompok Penyajian &amp; Uji Kepatuhan</h6>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Kelompok Penyajian</th><th class="text-end">Jumlah Akun Aktif</th></tr></thead>
                        <tbody>
                            <?php $__currentLoopData = $calk['kelompokDisajikan']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $nama => $jumlah): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr class="<?php echo e($jumlah === 0 ? 'text-muted' : ''); ?>">
                                <td class="small"><?php echo e($nama); ?></td>
                                <td class="text-end small">
                                    <?php echo e($jumlah); ?>

                                    <?php if($jumlah === 0): ?>
                                        <span class="badge bg-light text-secondary border ms-1" style="font-size:.64rem;">kosong</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </tbody>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th colspan="2">Keseimbangan Buku Periode</th></tr></thead>
                        <tbody>
                            <tr><th class="text-muted fw-semibold small">Jumlah jurnal</th><td class="small"><?php echo e((int) $calk['mutasi']['jumlah_jurnal']); ?></td></tr>
                            <tr><th class="text-muted fw-semibold small">Total debet</th><td class="small"><?php echo e($fmt($calk['mutasi']['debet'])); ?></td></tr>
                            <tr><th class="text-muted fw-semibold small">Total kredit</th><td class="small"><?php echo e($fmt($calk['mutasi']['kredit'])); ?></td></tr>
                            <tr>
                                <th class="text-muted fw-semibold small">Status</th>
                                <td class="small">
                                    <?php if($calk['mutasi']['seimbang']): ?>
                                        <span class="badge bg-success" style="font-size:.68rem;">SEIMBANG</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger" style="font-size:.68rem;">SELISIH <?php echo e($fmt($calk['mutasi']['selisih'], 2)); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <?php if($diag['terdeteksi']): ?>
                    <div class="alert alert-warning py-2 small mt-3 mb-0">
                        <strong>Diagnostik arus kas:</strong> <?php echo e($diag['keterangan']); ?>

                        <div class="mt-1" style="font-size:.72rem;">
                            Mutasi kas bersih <?php echo e($fmt($diag['mutasiKasBersih'])); ?>;
                            laba bersih <?php echo e($fmt($diag['labaBersih'])); ?>;
                            mutasi non-kas terpantau <?php echo e($fmt($diag['mutasiNonKas'])); ?>;
                            selisih <?php echo e($fmt($diag['selisih'], 2)); ?>.
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-success py-2 small mt-3 mb-0">
                        <strong>Diagnostik arus kas:</strong> <?php echo e($diag['keterangan']); ?>

                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if($calk['akunTakKlasifikasi']->isNotEmpty()): ?>
            <h6 class="fw-bold mb-2">6.f Akun Aktif Belum Diklasifikasi</h6>
            <div class="alert alert-warning py-2 small mb-0">
                <?php echo e($calk['akunTakKlasifikasi']->count()); ?> akun aktif belum memiliki
                klasifikasi penyajian PSAK 101:
                <?php $__currentLoopData = $calk['akunTakKlasifikasi']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $a): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <code><?php echo e($a->kode_akun); ?></code><?php echo e(! $loop->last ? ',' : ''); ?>

                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                <div class="mt-1">
                    Selama belum dipetakan, akun-akun ini disajikan pada kelompok
                    "Belum Diklasifikasi" dan menimbulkan temuan penyajian.
                </div>
            </div>
            <?php endif; ?>

            
            <h6 class="fw-bold mb-2">6.g Laporan Sumber &amp; Penggunaan Dana Zakat (PSAK 109)</h6>
            <p class="small text-muted mb-3">
                Dana zakat adalah <strong>dana titipan (amanah)</strong>, bukan pendapatan
                koperasi, dan karena itu disajikan terpisah dari Laba Rugi serta tidak
                memengaruhi SHU. Rincian di bawah ini adalah pengungkapan yang
                diwajibkan PSAK 109 bagi pemangku kepentingan eksternal — DPS,
                pembina, dan pengawas.
            </p>

            <div class="table-responsive mb-4">
                <table class="table table-sm tabel-laporan mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Uraian</th>
                            <th class="text-end">Saldo Awal</th>
                            <th class="text-end">Penerimaan</th>
                            <th class="text-end">Penyaluran</th>
                            <th class="text-end">Saldo Akhir</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="baris-sub">
                            <td>
                                <strong>Dana Zakat</strong>
                                <code class="ms-1"><?php echo e($zkt['akun']); ?></code>
                            </td>
                            <td class="text-end"><?php echo e($fmt($zkt['saldoAwal'], 2)); ?></td>
                            <td class="text-end"><?php echo e($fmt($zkt['penerimaan'], 2)); ?></td>
                            <td class="text-end"><?php echo e($fmt($zkt['penyaluran'], 2)); ?></td>
                            <td class="text-end"><?php echo e($fmt($zkt['saldoAkhir'], 2)); ?></td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="baris-total-utama">
                            <td>Saldo Dana Zakat Ending</td>
                            <td class="text-end"></td>
                            <td class="text-end"></td>
                            <td class="text-end"></td>
                            <td class="text-end"><?php echo e($fmt($zkt['saldoAkhir'], 2)); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <table class="table table-sm tabel-laporan mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>6.g.1 Sumber Dana — per Jenis Zakat</th>
                                <th class="text-end">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $__currentLoopData = $zkt['rincianPenerimaan']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr class="<?php echo e($r['jumlah'] == 0 ? 'text-muted' : ''); ?>">
                                <td class="small">
                                    <?php echo e($r['label']); ?>

                                    <?php if($r['kode'] === '__lainnya'): ?>
                                        <span class="badge bg-warning text-dark ms-1" style="font-size:.62rem;">di luar daftar baku</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end small"><?php echo e($fmt($r['jumlah'], 2)); ?></td>
                            </tr>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </tbody>
                        <tfoot>
                            <tr class="baris-total-utama">
                                <td>Total Penerimaan</td>
                                <td class="text-end"><?php echo e($fmt($zkt['totalRincianPenerimaan'], 2)); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="col-md-6">
                    <table class="table table-sm tabel-laporan mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>6.g.2 Penggunaan Dana — per Asnaf</th>
                                <th class="text-end">Jumlah</th>
                                <th class="text-end">%</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $__currentLoopData = $zkt['rincianPenyaluran']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr class="<?php echo e($r['jumlah'] == 0 ? 'text-muted' : ''); ?>">
                                <td class="small">
                                    <?php echo e($r['label']); ?>

                                    <?php if($r['kode'] === '__lainnya'): ?>
                                        <span class="badge bg-warning text-dark ms-1" style="font-size:.62rem;">di luar 8 asnaf</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end small"><?php echo e($fmt($r['jumlah'], 2)); ?></td>
                                <td class="text-end small"><?php echo e(number_format($r['persen'], 2, ',', '.')); ?>%</td>
                            </tr>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </tbody>
                        <tfoot>
                            <tr class="baris-total-utama">
                                <td>Total Penyaluran</td>
                                <td class="text-end"><?php echo e($fmt($zkt['totalRincianPenyaluran'], 2)); ?></td>
                                <td class="text-end">
                                    <?php echo e($zkt['totalRincianPenyaluran'] > 0 ? '100,00%' : '—'); ?>

                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr><th colspan="2">6.g.3 Rekonsiliasi Hak Amil (<?php echo e(number_format($zkt['amil']['persen'], 2, ',', '.')); ?>%)</th></tr>
                        </thead>
                        <tbody>
                            <tr><th class="text-muted fw-semibold small">Dasar hak (penerimaan)</th><td class="text-end small"><?php echo e($fmt($zkt['amil']['dasar'], 2)); ?></td></tr>
                            <tr><th class="text-muted fw-semibold small">Hak amil</th><td class="text-end small"><?php echo e($fmt($zkt['amil']['hak'], 2)); ?></td></tr>
                            <tr><th class="text-muted fw-semibold small">Realisasi (asnaf amil)</th><td class="text-end small"><?php echo e($fmt($zkt['amil']['realisasi'], 2)); ?></td></tr>
                            <tr>
                                <th class="text-muted fw-semibold small">Sisa hak belum direalisasikan</th>
                                <td class="text-end small">
                                    <?php echo e($fmt($zkt['amil']['sisa'], 2)); ?>

                                    <?php if(! $zkt['amil']['terpakai']): ?>
                                        <span class="badge bg-light text-secondary border ms-1" style="font-size:.62rem;">belum dipakai</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="col-md-6">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th colspan="2">6.g.4 Rekonsiliasi Jurnal vs Buku Besar Modul</th></tr></thead>
                        <tbody>
                            <tr><th class="text-muted fw-semibold small">Selisih penerimaan</th><td class="text-end small"><?php echo e($fmt($zkt['kontrol']['selisihPenerimaan'], 2)); ?></td></tr>
                            <tr><th class="text-muted fw-semibold small">Selisih dana disalurkan</th><td class="text-end small"><?php echo e($fmt($zkt['kontrol']['selisihPenyaluran'], 2)); ?></td></tr>
                            <tr><th class="text-muted fw-semibold small">Baris tanpa jurnal</th><td class="text-end small"><?php echo e((int) $zkt['kontrol']['tanpaJurnal']); ?></td></tr>
                            <tr><th class="text-muted fw-semibold small">Penyaluran di luar 8 asnaf</th><td class="text-end small"><?php echo e((int) $zkt['kontrol']['asnafDiLuar']); ?></td></tr>
                            <tr>
                                <th class="text-muted fw-semibold small">Status</th>
                                <td class="text-end small">
                                    <?php if($zkt['kontrol']['selaras']): ?>
                                        <span class="badge bg-success" style="font-size:.68rem;">SELARAS</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger" style="font-size:.68rem;">SELISIH</span>
                                    <?php endif; ?>
                                    <?php if($zkt['mutasiSeimbang']): ?>
                                        <span class="badge bg-success" style="font-size:.68rem;">MUTASI SEIMBANG</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger" style="font-size:.68rem;">MUTASI TIDAK SEIMBANG</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if(! $zkt['akunTersedia']): ?>
            <div class="alert alert-danger py-2 small mb-0">
                Akun DIT Dana Zakat <code><?php echo e($zkt['akun']); ?></code> belum ada pada CoA.
                Laporan ini tidak dapat direkonsiliasi sampai akun tersebut terdaftar.
            </div>
            <?php elseif(! $zkt['kontrol']['selaras'] || $zkt['kontrol']['tanpaJurnal'] > 0 || $zkt['kontrol']['asnafDiLuar'] > 0): ?>
            <div class="alert alert-warning py-2 small mb-0">
                <strong>Temuan rekonsiliasi zakat:</strong>
                <?php if(! $zkt['kontrol']['selaras']): ?>
                    nilai pada jurnal dan buku besar modul tidak identik dalam toleransi —
                    selisih penerimaan <?php echo e($fmt($zkt['kontrol']['selisihPenerimaan'], 2)); ?>,
                    selisih dana tersalurkan <?php echo e($fmt($zkt['kontrol']['selisihPenyaluran'], 2)); ?>.
                <?php endif; ?>
                <?php if($zkt['kontrol']['tanpaJurnal'] > 0): ?>
                    terdapat <?php echo e((int) $zkt['kontrol']['tanpaJurnal']); ?> baris transaksi tanpa jurnal —
                    dana bergerak di luar pembukuan.
                <?php endif; ?>
                <?php if($zkt['kontrol']['asnafDiLuar'] > 0): ?>
                    terdapat <?php echo e((int) $zkt['kontrol']['asnafDiLuar']); ?> baris penyaluran yang kategorinya di luar 8 asnaf.
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <p class="text-muted small text-center mb-4">
        Laporan ini dihasilkan otomatis dari jurnal berstatus Posted.
        Kelima komponen dan Catatan disusun dari satu sumber data yang sama
        sehingga saling dapat direkonsiliasi.
    </p>

</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\laporan\psak101.blade.php ENDPATH**/ ?>