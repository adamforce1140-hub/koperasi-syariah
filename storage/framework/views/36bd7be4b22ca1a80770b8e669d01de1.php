
<?php $__env->startSection('page-title', "Simpanan Wajib"); ?>

<?php $__env->startSection('title', 'Simpanan Wajib'); ?>

<?php $__env->startSection('sidebar'); ?>
<div class="nav-section">Kelola Anggota</div>
<a href="<?php echo e(route('teller.anggota.index')); ?>" class="nav-item <?php echo e(request()->routeIs('teller.anggota.*') ? 'active' : ''); ?>">
    <span class="icon">&#128101;</span> Data & Daftar Anggota
</a>
<div class="nav-section">Teller</div>
<a href="<?php echo e(route('dashboard.teller')); ?>" class="nav-item <?php echo e(request()->routeIs('dashboard.teller') ? 'active' : ''); ?>">
    <span class="icon">&#127968;</span> Dashboard
</a>
<a href="<?php echo e(route('teller.setor')); ?>" class="nav-item <?php echo e(request()->routeIs('teller.setor') ? 'active' : ''); ?>">
    <span class="icon">&#128176;</span> Setor Tunai
</a>
<a href="<?php echo e(route('teller.tarik')); ?>" class="nav-item <?php echo e(request()->routeIs('teller.tarik') ? 'active' : ''); ?>">
    <span class="icon">&#128184;</span> Tarik Tunai
</a>
<a href="<?php echo e(route('teller.angsuran')); ?>" class="nav-item <?php echo e(request()->routeIs('teller.angsuran') ? 'active' : ''); ?>">
    <span class="icon">&#128200;</span> Bayar Angsuran
</a>
<a href="<?php echo e(route('teller.simpanan-wajib.index')); ?>" class="nav-item active">
    <span class="icon">&#127974;</span> Simpanan Wajib
</a>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    
    <div>
        <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;">Simpanan Wajib Bulanan</h1>
        <p style="margin:.25rem 0 0;font-size:.83rem;color:#64748b;">
            Tagihan Simpanan Wajib seluruh anggota. Anggota tanpa auto-debit melunasi di loket ini.
            Jurnal otomatis: <strong>DEBET Kas Teller (1-1001)</strong> &rarr; <strong>KREDIT Simpanan Wajib (3-1002)</strong> — PSAK 101.
        </p>
    </div>

    
    <div aria-live="polite">
        <?php if(session('success')): ?>
        <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:.75rem 1rem;border-radius:8px;">
            &#10003; <?php echo e(session('success')); ?>

        </div>
        <?php endif; ?>
        <?php if(session('warning')): ?>
        <div style="background:#fef3c7;border:1px solid #fcd34d;color:#92400e;padding:.75rem 1rem;border-radius:8px;margin-top:.5rem;">
            &#9888; <?php echo e(session('warning')); ?>

        </div>
        <?php endif; ?>
        <?php if(session('error')): ?>
        <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.75rem 1rem;border-radius:8px;margin-top:.5rem;">
            &#9888; <?php echo e(session('error')); ?>

        </div>
        <?php endif; ?>
        <?php if($errors->any()): ?>
        <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.75rem 1rem;border-radius:8px;margin-top:.5rem;">
            <?php echo e($errors->first()); ?>

        </div>
        <?php endif; ?>
    </div>

    
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;">
        <div style="background:linear-gradient(135deg,#b45309,#92400e);color:#fff;border-radius:.875rem;padding:1.125rem 1.25rem;">
            <div style="font-size:1.2rem;font-weight:700;">Rp <?php echo e(number_format($ringkasan['total_tunggakan'], 0, ',', '.')); ?></div>
            <div style="font-size:.75rem;opacity:.85;margin-top:.2rem;">Total Tagihan Belum Lunas</div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;padding:1.125rem 1.25rem;">
            <div style="font-size:1.4rem;font-weight:700;color:#dc2626;"><?php echo e(number_format($ringkasan['overdue'], 0, ',', '.')); ?></div>
            <div style="font-size:.75rem;color:#64748b;margin-top:.2rem;">Tagihan OVERDUE (Tunggakan)</div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;padding:1.125rem 1.25rem;">
            <div style="font-size:1.4rem;font-weight:700;color:#d97706;"><?php echo e(number_format($ringkasan['unpaid'], 0, ',', '.')); ?></div>
            <div style="font-size:.75rem;color:#64748b;margin-top:.2rem;">Tagihan UNPAID (Belum Jatuh Tempo)</div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;padding:1.125rem 1.25rem;">
            <div style="font-size:1.4rem;font-weight:700;color:#7c3aed;"><?php echo e(number_format($ringkasan['ditangguhkan'] ?? 0, 0, ',', '.')); ?></div>
            <div style="font-size:.75rem;color:#64748b;margin-top:.2rem;">DITANGGUHKAN — Menunggu Konfirmasi</div>
        </div>
    </div>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;padding:1rem 1.25rem;">
        <form method="GET" action="<?php echo e(route('teller.simpanan-wajib.index')); ?>"
              style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;">
            <div style="flex:1;min-width:220px;">
                <label for="filter-cari" style="display:block;font-size:.76rem;font-weight:600;color:#475569;margin-bottom:.375rem;">Cari Anggota</label>
                <input type="text" id="filter-cari" name="cari" value="<?php echo e(request('cari')); ?>" placeholder="Nama atau No. Anggota…"
                       style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.825rem;box-sizing:border-box;">
            </div>
            <div style="min-width:150px;">
                <label for="filter-status" style="display:block;font-size:.76rem;font-weight:600;color:#475569;margin-bottom:.375rem;">Status</label>
                <select id="filter-status" name="status" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.825rem;">
                    <option value="">Semua Status</option>
                    <option value="UNPAID"  <?php echo e(request('status') === 'UNPAID'  ? 'selected' : ''); ?>>UNPAID</option>
                    <option value="OVERDUE" <?php echo e(request('status') === 'OVERDUE' ? 'selected' : ''); ?>>OVERDUE</option>
                    <option value="PENDING_CONFIRMATION" <?php echo e(request('status') === 'PENDING_CONFIRMATION' ? 'selected' : ''); ?>>DITANGGUHKAN</option>
                    <option value="PAID"    <?php echo e(request('status') === 'PAID'    ? 'selected' : ''); ?>>PAID</option>
                </select>
            </div>
            <div style="min-width:150px;">
                <label for="filter-periode" style="display:block;font-size:.76rem;font-weight:600;color:#475569;margin-bottom:.375rem;">Periode</label>
                <select id="filter-periode" name="periode" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.825rem;">
                    <option value="">Semua Periode</option>
                    <?php $__currentLoopData = $periodeTersedia; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($p); ?>" <?php echo e(request('periode') === $p ? 'selected' : ''); ?>><?php echo e($p); ?></option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
            </div>
            <div style="display:flex;gap:.5rem;">
                <button type="submit"
                        style="padding:.5rem 1.125rem;background:#b45309;color:#fff;font-size:.825rem;font-weight:600;border:none;border-radius:.5rem;cursor:pointer;">
                    Cari
                </button>
                <?php if(request()->hasAny(['cari', 'status', 'periode'])): ?>
                <a href="<?php echo e(route('teller.simpanan-wajib.index')); ?>"
                   style="display:inline-flex;align-items:center;padding:.5rem .875rem;background:#f1f5f9;color:#64748b;font-size:.825rem;font-weight:600;border-radius:.5rem;text-decoration:none;">
                    Reset
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;padding:1.125rem 1.25rem;">
        <h2 style="font-size:.95rem;font-weight:700;color:#1e293b;margin:0 0 .25rem;">Penarikan Simpanan Wajib</h2>
        <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:.75rem 1rem;border-radius:8px;font-size:.8rem;line-height:1.55;">
            &#128274; <strong>Terkunci &mdash; Modal/Ekuitas Koperasi.</strong>
            Simpanan Wajib (akun <code>3-1002</code>) tidak dapat ditarik selama anggota berstatus aktif.
            Pengembalian dana hanya dapat diproses melalui modul
            <strong>Pengunduran Diri / Exit Keanggotaan</strong>
            (<code>member_terminations</code>), yaitu setelah seluruh kewajiban anggota lunas
            (PSAK 101 ¶ 31 &amp; PSAK 108).
        </div>
    </div>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;overflow:hidden;">
        <div style="padding:.875rem 1.25rem;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;">
            <span style="font-size:.825rem;color:#64748b;">
                <strong style="color:#1e293b;"><?php echo e($tagihan->total()); ?></strong> tagihan ditemukan
            </span>
            <span style="font-size:.75rem;color:#94a3b8;">Hal. <?php echo e($tagihan->currentPage()); ?> / <?php echo e($tagihan->lastPage()); ?></span>
        </div>

        <?php if($tagihan->count() > 0): ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <caption class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">
                    Daftar tagihan Simpanan Wajib bulanan anggota koperasi
                </caption>
                <thead>
                    <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                        <th scope="col" style="padding:.75rem 1.25rem;text-align:left;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Periode</th>
                        <th scope="col" style="padding:.75rem 1rem;text-align:left;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Anggota</th>
                        <th scope="col" style="padding:.75rem 1rem;text-align:right;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Nominal</th>
                        <th scope="col" style="padding:.75rem 1rem;text-align:left;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Tenggat</th>
                        <th scope="col" style="padding:.75rem 1rem;text-align:center;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Status</th>
                        <th scope="col" style="padding:.75rem 1rem;text-align:left;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Pelunasan</th>
                        <th scope="col" style="padding:.75rem 1rem;text-align:center;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Aksi</th>
                    </tr>
                </thead>
                <tbody data-daftar-tagihan="simpanan-wajib">
                    <?php $__currentLoopData = $tagihan; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $t): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php
                        $statusColor = match($t->status) {
                            'PAID'    => ['bg' => '#d1fae5', 'text' => '#065f46'],
                            'OVERDUE' => ['bg' => '#fee2e2', 'text' => '#991b1b'],
                            'PENDING_CONFIRMATION' => ['bg' => '#ede9fe', 'text' => '#5b21b6'],
                            default   => ['bg' => '#fef3c7', 'text' => '#92400e'],
                        };
                        // Ringkasan penanganan dinamis tagihan ini (kekurangan saldo
                        // Sukarela, jadwal bertahap, reschedule) + tenggat bayar.
                        $p        = $penanganan[$t->id] ?? null;
                        $tenggat  = $p['tenggat'] ?? null;
                        $lewat    = $tenggat && $tenggat->isPast() && ! $t->isPaid();
                    ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:.875rem 1.25rem;">
                            <span style="font-family:monospace;font-size:.8rem;font-weight:600;color:#7c3aed;background:#ede9fe;padding:.2rem .5rem;border-radius:.375rem;">
                                <?php echo e($t->bill_period); ?>

                            </span>
                            <div style="font-size:.72rem;color:#94a3b8;margin-top:.2rem;"><?php echo e($t->nama_periode); ?></div>
                        </td>
                        <td style="padding:.875rem 1rem;">
                            <div style="font-size:.875rem;font-weight:600;color:#1e293b;"><?php echo e($t->member?->nama_lengkap ?? '—'); ?></div>
                            <div style="font-size:.75rem;color:#94a3b8;"><?php echo e($t->member?->no_anggota ?? ''); ?></div>
                        </td>
                        <td style="padding:.875rem 1rem;text-align:right;">
                            <span style="font-size:.875rem;font-weight:700;color:#b45309;">
                                Rp <?php echo e(number_format((float) $t->amount, 0, ',', '.')); ?>

                            </span>
                        </td>
                        <td style="padding:.875rem 1rem;font-size:.8rem;color:<?php echo e($lewat ? '#dc2626' : '#475569'); ?>;">
                            <?php if($tenggat): ?>
                                <span style="font-weight:600;"><?php echo e($tenggat->format('d/m/Y')); ?></span>
                                <?php if($lewat): ?>
                                <div style="font-size:.72rem;color:#dc2626;font-weight:600;">Lewat jatuh tempo</div>
                                <?php endif; ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td style="padding:.875rem 1rem;text-align:center;">
                            <span style="display:inline-flex;align-items:center;padding:.2rem .65rem;
                                         background:<?php echo e($statusColor['bg']); ?>;color:<?php echo e($statusColor['text']); ?>;
                                         font-size:.75rem;font-weight:600;border-radius:9999px;">
                                <?php echo e($t->status); ?>

                            </span>
                            <?php if($t->isPendingConfirmation()): ?>
                            <div style="font-size:.68rem;color:#7c3aed;margin-top:.3rem;line-height:1.35;">
                                Alasan: <?php echo e($t->deferred_reason ?? '—'); ?>

                                <?php if($t->deferred_at): ?><br><?php echo e($t->deferred_at->format('d/m/Y H:i')); ?><?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td style="padding:.875rem 1rem;font-size:.8rem;color:#64748b;">
                            <?php if($t->isPaid()): ?>
                                <?php echo e($t->paid_at?->format('d/m/Y H:i') ?? '—'); ?>

                                <div style="font-size:.72rem;color:#94a3b8;"><?php echo e($t->metode_label); ?></div>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td style="padding:.875rem 1rem;text-align:center;">
                            <?php if($t->isPaid()): ?>
                                <?php if($t->transaksi_id): ?>
                                <a href="<?php echo e(route('teller.nota', $t->transaksi_id)); ?>"
                                   style="display:inline-flex;align-items:center;padding:.35rem .75rem;background:#f1f5f9;color:#374151;font-size:.78rem;font-weight:600;border-radius:.375rem;text-decoration:none;">
                                    Nota
                                </a>
                                <?php else: ?>
                                <span style="font-size:.78rem;color:#94a3b8;">Lunas</span>
                                <?php endif; ?>
                            <?php else: ?>
                            <!-- Kolom aksi tunggal: pelunasan + panel penanganan dinamis -->
                            <div style="display:flex;flex-direction:column;gap:.5rem;align-items:stretch;min-width:230px;">

                                
                                <?php if($t->isPendingConfirmation()): ?>
                                <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('transaksi', \App\Models\User::class)): ?>
                                <div style="background:#faf5ff;border:1px solid #ddd6fe;border-radius:.5rem;padding:.5rem .6rem;text-align:left;">
                                    <div style="font-size:.72rem;font-weight:700;color:#5b21b6;margin-bottom:.35rem;">
                                        Setoran Otomatis Ditangguhkan — Butuh Konfirmasi
                                    </div>
                                    <div style="font-size:.7rem;color:#64748b;line-height:1.4;margin-bottom:.45rem;">
                                        Dana TIDAK dipotong. Konfirmasi hanya berhasil bila verifikasi status pembayaran lolos (saldo Sukarela cukup, anggota aktif).
                                    </div>
                                    <form method="POST" action="<?php echo e(route('teller.simpanan-wajib.konfirmasi', $t->id)); ?>" style="margin:0 0 .35rem;"
                                          onsubmit="return confirm('Konfirmasi & jalankan setoran otomatis periode <?php echo e($t->nama_periode); ?>?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="text" name="catatan" maxlength="500" placeholder="Catatan konfirmasi (opsional)"
                                               style="width:100%;padding:.3rem .45rem;border:1px solid #cbd5e1;border-radius:.375rem;font-size:.72rem;box-sizing:border-box;margin-bottom:.3rem;">
                                        <button type="submit"
                                                style="width:100%;padding:.35rem .6rem;background:#7c3aed;color:#fff;font-size:.74rem;font-weight:600;border:none;border-radius:.375rem;cursor:pointer;">
                                            Konfirmasi & Setor
                                        </button>
                                    </form>
                                    <form method="POST" action="<?php echo e(route('teller.simpanan-wajib.tolak', $t->id)); ?>" style="margin:0;"
                                          onsubmit="return confirm('Tolak konfirmasi untuk periode <?php echo e($t->nama_periode); ?>? Tagihan dialihkan ke penanganan manual tanpa denda.');">
                                        <?php echo csrf_field(); ?>
                                        <button type="submit"
                                                style="width:100%;padding:.35rem .6rem;background:#fff;color:#b91c1c;font-size:.74rem;font-weight:600;border:1px solid #fecaca;border-radius:.375rem;cursor:pointer;">
                                            Tolak Konfirmasi
                                        </button>
                                    </form>
                                </div>
                                <?php endif; ?>
                                <?php endif; ?>

                                
                                <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('transaksi', \App\Models\User::class)): ?>
                                <form method="POST" action="<?php echo e(route('teller.simpanan-wajib.bayar', $t->id)); ?>"
                                      style="display:flex;gap:.375rem;align-items:center;margin:0;"
                                      onsubmit="return confirm('Lunasi tagihan <?php echo e($t->nama_periode); ?> sebesar Rp <?php echo e(number_format((float) $t->amount, 0, ',', '.')); ?>?');">
                                    <?php echo csrf_field(); ?>
                                    <label for="metode-<?php echo e($t->id); ?>" class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">Metode</label>
                                    <select id="metode-<?php echo e($t->id); ?>" name="metode"
                                            style="flex:1;padding:.35rem .5rem;border:1px solid #cbd5e1;border-radius:.375rem;font-size:.78rem;">
                                        <option value="CASH">Tunai</option>
                                        <option value="TRANSFER">Transfer</option>
                                    </select>
                                    <button type="submit"
                                            style="padding:.35rem .75rem;background:#059669;color:#fff;font-size:.78rem;font-weight:600;border:none;border-radius:.375rem;cursor:pointer;">
                                        Lunasi
                                    </button>
                                </form>

                                
                                <?php if($p): ?>
                                <div style="font-size:.72rem;color:#64748b;text-align:left;line-height:1.4;background:#f8fafc;border:1px solid #e2e8f0;border-radius:.375rem;padding:.4rem .55rem;">
                                    Saldo Sukarela: <strong>Rp <?php echo e(number_format($p['saldo_sukarela'], 0, ',', '.')); ?></strong>
                                    <?php if($p['kekurangan'] > 0): ?>
                                    <br>Kekurangan: <strong style="color:#dc2626;">Rp <?php echo e(number_format($p['kekurangan'], 0, ',', '.')); ?></strong>
                                    <?php else: ?>
                                    <br><strong style="color:#059669;">Saldo mencukupi</strong>
                                    <?php endif; ?>
                                    <?php if($p['ada_jadwal']): ?>
                                    <br>Jadwal: <strong><?php echo e($p['total_cicilan']); ?> &times; Rp <?php echo e(number_format($p['nominal_cicilan'], 0, ',', '.')); ?></strong>
                                    (terbayar Rp <?php echo e(number_format($p['sudah_dicicil'], 0, ',', '.')); ?>)
                                    <?php endif; ?>
                                    <?php if($p['jumlah_reschedule'] > 0): ?>
                                    <br>Reschedule: <strong><?php echo e($p['jumlah_reschedule']); ?>&times;</strong>
                                    <?php endif; ?>
                                    <br><span style="color:#059669;">Tanpa denda (prinsip syariah)</span>
                                </div>
                                <?php endif; ?>

                                
                                <?php if($kebijakan['penanganan_dinamis_aktif']): ?>
                                <div style="display:flex;flex-wrap:wrap;gap:.35rem;">
                                    <?php if(! $p['ada_jadwal']): ?>
                                    <form method="POST" action="<?php echo e(route('teller.simpanan-wajib.jadwal', $t->id)); ?>" style="margin:0;"
                                          onsubmit="return confirm('Tetapkan jadwal pembayaran bertahap untuk periode <?php echo e($t->nama_periode); ?>?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="jumlah_cicilan" value="<?php echo e($kebijakan['maks_cicilan'] > 0 ? min($kebijakan['maks_cicilan'], 6) : 6); ?>">
                                        <button type="submit"
                                                style="padding:.3rem .6rem;background:#0ea5e9;color:#fff;font-size:.72rem;font-weight:600;border:none;border-radius:.375rem;cursor:pointer;"
                                                title="Pecah tagihan menjadi beberapa cicilan sesuai kemampuan anggota">
                                            Jadwal Bertahap
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <form method="POST" action="<?php echo e(route('teller.simpanan-wajib.cicilan', $t->id)); ?>" style="margin:0;"
                                          onsubmit="return confirm('Potong satu cicilan dari saldo Simpanan Sukarela anggota?');">
                                        <?php echo csrf_field(); ?>
                                        <button type="submit"
                                                style="padding:.3rem .6rem;background:#7c3aed;color:#fff;font-size:.72rem;font-weight:600;border:none;border-radius:.375rem;cursor:pointer;"
                                                title="Potong cicilan dari saldo Simpanan Sukarela">
                                            Bayar Cicilan
                                        </button>
                                    </form>
                                    <?php endif; ?>

                                    <?php if($p['boleh_reschedule']): ?>
                                    <form method="POST" action="<?php echo e(route('teller.simpanan-wajib.reschedule', $t->id)); ?>" style="margin:0;"
                                          onsubmit="return confirm('Geser tenggat tagihan periode <?php echo e($t->nama_periode); ?> TANPA DENDA?');">
                                        <?php echo csrf_field(); ?>
                                        <button type="submit"
                                                style="padding:.3rem .6rem;background:#f59e0b;color:#fff;font-size:.72rem;font-weight:600;border:none;border-radius:.375rem;cursor:pointer;"
                                                title="Penjadwalan ulang tanpa denda sesuai prinsip syariah">
                                            Reschedule
                                        </button>
                                    </form>
                                    <?php endif; ?>

                                    <a href="<?php echo e(route('teller.simpanan-wajib.riwayat', $t->id)); ?>"
                                       style="padding:.3rem .6rem;background:#f1f5f9;color:#374151;font-size:.72rem;font-weight:600;border-radius:.375rem;text-decoration:none;">
                                        Riwayat
                                    </a>
                                </div>
                                <?php else: ?>
                                <div style="font-size:.72rem;color:#94a3b8;text-align:left;">
                                    Penanganan dinamis dinonaktifkan Pengurus.
                                </div>
                                <?php endif; ?>
                                <?php endif; ?>

                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>
        <?php if($tagihan->hasPages()): ?>
        <div style="padding:.875rem 1.25rem;border-top:1px solid #f1f5f9;"><?php echo e($tagihan->links()); ?></div>
        <?php endif; ?>
        <?php else: ?>
        <div style="padding:3.5rem 1.5rem;text-align:center;">
            <div style="font-size:.95rem;font-weight:600;color:#1e293b;margin-bottom:.375rem;">Tidak ada tagihan</div>
            <p style="font-size:.825rem;color:#94a3b8;">Belum ada tagihan Simpanan Wajib yang sesuai filter.</p>
        </div>
        <?php endif; ?>
    </div>

</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\teller\simpanan-wajib.blade.php ENDPATH**/ ?>