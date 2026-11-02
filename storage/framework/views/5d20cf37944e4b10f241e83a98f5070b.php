<?php $__env->startSection('title', 'Portofolio CIF — ' . $anggota->nama_lengkap); ?>
<?php $__env->startSection('page-title', 'Portofolio CIF Anggota'); ?>

<?php $__env->startSection('content'); ?>
<?php
    use App\Models\Anggota;

    $st        = Anggota::normalizeStatus($anggota->status_anggota ?? null)
                 ?? Anggota::STATUS_INACTIVE;
    $stLabel   = $anggota->status_label;
    $badge = match($st) {
        Anggota::STATUS_ACTIVE
                   => ['bg'=>'#d1fae5','color'=>'#065f46','dot'=>'#059669'],
        Anggota::STATUS_PENDING
                   => ['bg'=>'#fef3c7','color'=>'#92400e','dot'=>'#d97706'],
        Anggota::STATUS_SUSPENDED
                   => ['bg'=>'#ede9fe','color'=>'#5b21b6','dot'=>'#7c3aed'],
        Anggota::STATUS_KELUAR
                   => ['bg'=>'#e2e8f0','color'=>'#334155','dot'=>'#64748b'],
        default    => ['bg'=>'#fee2e2','color'=>'#991b1b','dot'=>'#dc2626'],
    };

    $totalSaldo   = $saldoPokok + $saldoWajib + $saldoSukarela;
    $tglBergabung = $anggota->tanggalMulaiKeanggotaan();
    $umurAnggota  = $anggota->member_age;   // mis. "1 Tahun 3 Bulan 5 Hari"
    $masaAktif    = $anggota->tenure_label; // mis. "8 Bulan"
?>
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
        <div>
            <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;margin-bottom:0.375rem;">
                <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;"><?php echo e($anggota->nama_lengkap); ?></h1>
                <span style="font-family:monospace;font-size:0.8rem;font-weight:700;color:#1e40af;
                             background:#dbeafe;padding:0.2rem 0.6rem;border-radius:0.375rem;">
                    <?php echo e($anggota->no_cif ?? '—'); ?>

                </span>
                <span style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.2rem 0.6rem;
                             background:<?php echo e($badge['bg']); ?>;color:<?php echo e($badge['color']); ?>;
                             font-size:0.75rem;font-weight:600;border-radius:9999px;">
                    <span style="width:6px;height:6px;border-radius:50%;background:<?php echo e($badge['dot']); ?>;"></span>
                    <?php echo e($stLabel); ?>

                </span>
            </div>
            <p style="margin:0;font-size:0.83rem;color:#64748b;">
                <?php echo e($anggota->no_anggota ?? '—'); ?> &bull;
                Bergabung <?php echo e($tglBergabung ? $tglBergabung->format('d M Y') : '—'); ?>

            </p>

            
            <div style="display:inline-flex;align-items:center;gap:0.4rem;flex-wrap:wrap;
                        margin-top:0.5rem;padding:0.3rem 0.7rem;background:#eff6ff;
                        border:1px solid #bfdbfe;border-radius:0.5rem;font-size:0.75rem;
                        color:#1e40af;font-weight:600;">
                <span style="font-size:0.85rem;">&#128197;</span>
                <span>
                    Bergabung sejak <?php echo e($tglBergabung ? $tglBergabung->format('d M Y') : '—'); ?>

                    &mdash; Masa Aktif: <?php echo e($masaAktif); ?>

                </span>
                <span style="color:#64748b;font-weight:500;">(<?php echo e($umurAnggota); ?>)</span>
            </div>
        </div>
        <div style="display:flex;gap:0.5rem;align-items:center;">
            <button onclick="document.getElementById('modal-buka-rekening').style.display='flex'"
               style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.5rem 1rem;
                      background:#dbeafe;color:#1e40af;font-size:0.8rem;font-weight:600;
                      border-radius:0.5rem;border:none;cursor:pointer;"
               onmouseover="this.style.background='#bfdbfe'" onmouseout="this.style.background='#dbeafe'">
                + Buka Rekening
            </button>
            <a href="<?php echo e(route('ao.cif.edit', $anggota->id)); ?>"
               style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.5rem 1rem;
                      background:#fef3c7;color:#92400e;font-size:0.8rem;font-weight:600;
                      border-radius:0.5rem;text-decoration:none;"
               onmouseover="this.style.background='#fde68a'" onmouseout="this.style.background='#fef3c7'">
                Edit Data
            </a>
            <a href="<?php echo e(route('ao.cif.index')); ?>"
               style="display:inline-flex;align-items:center;padding:0.5rem 1rem;
                      background:#f1f5f9;color:#475569;font-size:0.8rem;font-weight:600;
                      border-radius:0.5rem;text-decoration:none;"
               onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                ← Kembali
            </a>
        </div>
    </div>

    
    <?php if(session('success')): ?>
    <div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:0.75rem;padding:0.875rem 1.25rem;
                font-size:0.85rem;color:#065f46;font-weight:500;">
        <?php echo e(session('success')); ?>

    </div>
    <?php endif; ?>

    
    <div id="flash-deposito" style="display:none;background:#d1fae5;border:1px solid #6ee7b7;
                border-radius:0.75rem;padding:0.875rem 1.25rem;font-size:0.85rem;
                color:#065f46;font-weight:500;"></div>

    
    <?php echo $__env->make('partials.shu-hold-banner', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

    
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;">
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.125rem 1.25rem;
                    box-shadow:0 1px 3px rgba(0,0,0,.05);text-align:center;">
            <div style="font-size:0.75rem;color:#64748b;margin-bottom:0.375rem;">Total Saldo Simpanan</div>
            <div style="font-size:1.25rem;font-weight:700;color:#059669;">
                Rp <?php echo e(number_format($totalSaldo, 0, ',', '.')); ?>

            </div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.125rem 1.25rem;
                    box-shadow:0 1px 3px rgba(0,0,0,.05);text-align:center;">
            <div style="font-size:0.75rem;color:#64748b;margin-bottom:0.375rem;">Pembiayaan Aktif</div>
            <div style="font-size:1.25rem;font-weight:700;color:#1e40af;"><?php echo e($pembiayaanAktif->count()); ?></div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.125rem 1.25rem;
                    box-shadow:0 1px 3px rgba(0,0,0,.05);text-align:center;">
            <div style="font-size:0.75rem;color:#64748b;margin-bottom:0.375rem;">Total Mutasi (10 terakhir)</div>
            <div style="font-size:1.25rem;font-weight:700;color:#7c3aed;"><?php echo e($mutasi->count()); ?></div>
        </div>
    </div>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;
                box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <div style="font-size:0.85rem;font-weight:700;color:#1e293b;margin-bottom:1rem;
                    padding-bottom:0.625rem;border-bottom:1px solid #f1f5f9;">
            Informasi Identitas
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
            <?php
            $rows = [
                ['NIK',                $anggota->nik ?? '—'],
                ['Tempat / Tgl Lahir', ($anggota->tempat_lahir ?? '—') . ', ' . ($anggota->tanggal_lahir ? $anggota->tanggal_lahir->format('d M Y') : '—')],
                ['Jenis Kelamin',      $anggota->jenis_kelamin ?? '—'],
                ['Status Perkawinan',  $anggota->status_perkawinan ?? '—'],
                ['No HP',              $anggota->no_hp ?? '—'],
                ['Pekerjaan',          $anggota->pekerjaan ?? '—'],
                ['Email',              $anggota->email ?? '—'],
                ['Nama Ibu Kandung',   $anggota->nama_ibu_kandung ?? '—'],
                ['Alamat Lengkap',     implode(', ', array_filter([
                    $anggota->alamat,
                    $anggota->rt_rw ? 'RT/RW ' . $anggota->rt_rw : null,
                    $anggota->kelurahan,
                    $anggota->kecamatan,
                    $anggota->kota,
                    $anggota->provinsi,
                    $anggota->kode_pos,
                ]))],
            ];
            ?>
            <?php $__currentLoopData = $rows; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as [$label, $value]): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <div style="padding:0.625rem 0;border-bottom:1px solid #f8fafc;">
                <div style="font-size:0.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.2rem;">
                    <?php echo e($label); ?>

                </div>
                <div style="font-size:0.85rem;color:#1e293b;"><?php echo e($value); ?></div>
            </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </div>
    </div>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;
                box-shadow:0 1px 3px rgba(0,0,0,.05);overflow:hidden;">
        <div style="padding:1rem 1.25rem;border-bottom:1px solid #f1f5f9;">
            <div style="font-size:0.85rem;font-weight:700;color:#1e293b;">Rekening Simpanan</div>
        </div>
        <?php if($anggota->rekening->count() > 0): ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                        <th style="padding:0.65rem 1.25rem;text-align:left;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">No Rekening</th>
                        <th style="padding:0.65rem 1rem;text-align:left;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Jenis</th>
                        <th style="padding:0.65rem 1rem;text-align:left;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Akad</th>
                        <th style="padding:0.65rem 1rem;text-align:right;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Saldo</th>
                        <th style="padding:0.65rem 1rem;text-align:center;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__currentLoopData = $anggota->rekening; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $rek): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:0.75rem 1.25rem;font-family:monospace;font-size:0.8rem;color:#1e40af;"><?php echo e($rek->no_rekening); ?></td>
                        <td style="padding:0.75rem 1rem;font-size:0.825rem;color:#1e293b;"><?php echo e($rek->jenis_rekening); ?></td>
                        <td style="padding:0.75rem 1rem;font-size:0.825rem;color:#475569;"><?php echo e($rek->akad); ?></td>
                        <td style="padding:0.75rem 1rem;text-align:right;font-size:0.825rem;font-weight:600;color:#059669;">
                            Rp <?php echo e(number_format($rek->saldo, 0, ',', '.')); ?>

                        </td>
                        <td style="padding:0.75rem 1rem;text-align:center;">
                            <span style="padding:0.2rem 0.6rem;background:<?php echo e($rek->status_rekening === 'Aktif' ? '#d1fae5' : '#fee2e2'); ?>;
                                         color:<?php echo e($rek->status_rekening === 'Aktif' ? '#065f46' : '#991b1b'); ?>;
                                         font-size:0.75rem;font-weight:600;border-radius:9999px;">
                                <?php echo e($rek->status_rekening); ?>

                            </span>
                        </td>
                    </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="padding:2rem;text-align:center;font-size:0.825rem;color:#94a3b8;">Belum ada rekening simpanan.</div>
        <?php endif; ?>
    </div>

    
    <div id="deposito-list-wrapper">
        <?php echo $__env->make('partials.deposito-bilyet-table', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    </div>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;
                box-shadow:0 1px 3px rgba(0,0,0,.05);overflow:hidden;">
        <div style="padding:1rem 1.25rem;border-bottom:1px solid #f1f5f9;">
            <div style="font-size:0.85rem;font-weight:700;color:#1e293b;">Pembiayaan Aktif</div>
        </div>
        <?php if($pembiayaanAktif->count() > 0): ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                        <th style="padding:0.65rem 1.25rem;text-align:left;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">No Pembiayaan</th>
                        <th style="padding:0.65rem 1rem;text-align:left;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Akad</th>
                        <th style="padding:0.65rem 1rem;text-align:right;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Jumlah</th>
                        <th style="padding:0.65rem 1rem;text-align:right;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Sisa</th>
                        <th style="padding:0.65rem 1rem;text-align:center;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Status</th>
                        <th style="padding:0.65rem 1rem;text-align:center;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Angsuran</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__currentLoopData = $pembiayaanAktif; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $pmb): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php
                        $angsuranTerakhir = $pmb->angsuran->where('status_angsuran', 'Sudah Dibayar')->count();
                    ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:0.75rem 1.25rem;font-family:monospace;font-size:0.8rem;color:#1e40af;"><?php echo e($pmb->no_pembiayaan); ?></td>
                        <td style="padding:0.75rem 1rem;font-size:0.825rem;color:#1e293b;"><?php echo e($pmb->akad_pembiayaan); ?></td>
                        <td style="padding:0.75rem 1rem;text-align:right;font-size:0.825rem;color:#475569;">
                            Rp <?php echo e(number_format($pmb->jumlah_pembiayaan, 0, ',', '.')); ?>

                        </td>
                        <td style="padding:0.75rem 1rem;text-align:right;font-size:0.825rem;font-weight:600;color:#dc2626;">
                            Rp <?php echo e(number_format($pmb->sisa_pembiayaan, 0, ',', '.')); ?>

                        </td>
                        <td style="padding:0.75rem 1rem;text-align:center;">
                            <span style="padding:0.2rem 0.6rem;background:#d1fae5;color:#065f46;
                                         font-size:0.75rem;font-weight:600;border-radius:9999px;">
                                <?php echo e($pmb->status_pembiayaan); ?>

                            </span>
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:center;font-size:0.825rem;color:#475569;">
                            ke-<?php echo e($angsuranTerakhir + 1); ?> / <?php echo e($pmb->tenor); ?>

                        </td>
                    </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="padding:2rem;text-align:center;font-size:0.825rem;color:#94a3b8;">Tidak ada pembiayaan aktif.</div>
        <?php endif; ?>
    </div>

    
    <?php echo $__env->make('partials.mutasi-terakhir', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

</div>


<div id="modal-buka-rekening"
     style="display:none;position:fixed;inset:0;z-index:999;background:rgba(0,0,0,0.45);
            align-items:center;justify-content:center;padding:1rem;">
    
    <div class="max-h-[90vh] flex flex-col rounded-xl bg-white shadow-xl"
         style="background:#fff;border-radius:0.75rem;width:100%;max-width:480px;
                box-shadow:0 20px 60px rgba(0,0,0,0.2);overflow:hidden;
                display:flex;flex-direction:column;max-height:90vh;">

        
        <div class="sticky top-0 z-10 flex items-center justify-between"
             style="position:sticky;top:0;z-index:10;display:flex;align-items:center;
                    justify-content:space-between;padding:1rem 1.5rem;background:#fff;
                    border-bottom:1px solid #e2e8f0;border-radius:0.75rem 0.75rem 0 0;
                    flex:0 0 auto;">
            <div style="font-size:0.9rem;font-weight:700;color:#1e293b;">Buka Rekening Simpanan</div>
            <button type="button" aria-label="Tutup modal"
                    onclick="document.getElementById('modal-buka-rekening').style.display='none'"
                    style="background:none;border:none;cursor:pointer;font-size:1.25rem;color:#94a3b8;line-height:1;"
                    onmouseover="this.style.color='#475569'" onmouseout="this.style.color='#94a3b8'">
                &times;
            </button>
        </div>

        
        <form method="POST" action="<?php echo e(route('ao.cif.buka-rekening', $anggota->id)); ?>"
              id="form-buka-rekening"
              class="overflow-y-auto p-6 space-y-4 max-h-[calc(90vh-130px)]"
              style="padding:1.5rem;display:flex;flex-direction:column;gap:1rem;
                     max-height:calc(90vh - 130px);overflow-y:auto;min-height:0;flex:1 1 auto;
                     -webkit-overflow-scrolling:touch;overscroll-behavior:contain;">
            <?php echo csrf_field(); ?>

            
            <div id="modal-error" style="display:none;background:#fee2e2;border:1px solid #fca5a5;
                        border-radius:0.5rem;padding:0.75rem 1rem;font-size:0.8rem;color:#991b1b;"></div>

            
            <?php if($errors->any()): ?>
            <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:0.5rem;
                        padding:0.75rem 1rem;font-size:0.8rem;color:#991b1b;">
                <ul style="margin:0;padding-left:1.25rem;">
                    <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $err): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <li><?php echo e($err); ?></li>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </ul>
            </div>
            <?php endif; ?>

            
            <div>
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                    Jenis Rekening <span style="color:#dc2626;">*</span>
                </label>
                <?php
                    // Daftar rekening aktif dibaca dari SUMBER ACUAN YANG SAMA
                    // dengan validasi backend (JenisSimpanan::jenisSudahDimiliki),
                    // sehingga UI dan penolakan server tidak mungkin berbeda.
                    $jenisAda = \App\Models\JenisSimpanan::jenisSudahDimiliki($anggota->id, $anggota->rekening);
                    $oldJenis = old('jenis_rekening', '');
                    // Daftar jenis simpanan dibaca dari PARAMETER DINAMIS
                    // (`jenis_simpanans`, dikelola Pengurus) — bukan hardcoded.
                    // `data-tipe` dipakai JavaScript untuk mengklasifikasi
                    // (pokok/wajib/sukarela/berjangka) tanpa menanam nama jenis,
                    // sehingga jenis baru/berganti nama tetap dikenali benar.
                    $daftarJenis = \App\Models\JenisSimpanan::semua()->where('is_active', true)->values();
                    // Jenis yang dikunci keras (satu rekening per anggota) —
                    // ditandai khusus agar penginput tahu ini bukan sekadar
                    // "sudah ada", melainkan tidak boleh diulang sama sekali.
                    $jenisTerkunci = \App\Models\JenisSimpanan::TIPE_TUNGGAL;
                ?>
                <select name="jenis_rekening" required id="sel-jenis"
                        onchange="onJenisChange(this.value)"
                        style="width:100%;padding:0.55rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.875rem;font-family:inherit;background:#f8fafc;color:#1e293b;outline:none;">
                    <option value="">-- Pilih Jenis --</option>
                    <?php $__currentLoopData = $daftarJenis; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $j): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php
                        $u = in_array($j['nama'], $jenisAda, true);
                        $kunci = $u && in_array($j['tipe'], $jenisTerkunci, true);
                    ?>
                    <option value="<?php echo e($j['nama']); ?>" data-tipe="<?php echo e($j['tipe']); ?>"
                        <?php echo e($u ? 'disabled' : ''); ?>

                        style="<?php echo e($u ? 'color:#94a3b8' : ''); ?>"
                        <?php echo e($oldJenis === $j['nama'] ? 'selected' : ''); ?>>
                        <?php echo e($j['nama']); ?><?php echo e($u ? ($kunci ? ' (sudah ada — tidak dapat diulang)' : ' (sudah ada)') : ''); ?>

                    </option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
            </div>

            
            <div>
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                    Akad <span style="color:#dc2626;">*</span>
                </label>
                <select name="akad" id="sel-akad" required
                        style="width:100%;padding:0.55rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.875rem;font-family:inherit;background:#f8fafc;color:#1e293b;outline:none;">
                    <option value="Wadiah"     <?php echo e(old('akad','Wadiah') === 'Wadiah'     ? 'selected' : ''); ?>>Wadiah</option>
                    <option value="Mudharabah" <?php echo e(old('akad') === 'Mudharabah' ? 'selected' : ''); ?>>Mudharabah</option>
                </select>
            </div>

            
            
            <?php
                $minPembukaan = (float) ($batasPembukaan['minimum'] ?? 10000);
                $maksPembukaan = (float) ($batasPembukaan['maksimum'] ?? 50000000);
            ?>
            <div>
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                    <span id="label-setoran">Setoran Awal (Rp)</span> <span style="color:#dc2626;">*</span>
                </label>
                <input type="number" name="setoran_awal" id="inp-setoran"
                       min="<?php echo e($minPembukaan); ?>" max="<?php echo e($maksPembukaan); ?>" step="1000"
                       data-min="<?php echo e($minPembukaan); ?>" data-max="<?php echo e($maksPembukaan); ?>"
                       value="<?php echo e(old('setoran_awal')); ?>" required placeholder="Contoh: <?php echo e((int) $minPembukaan); ?>"
                       style="width:100%;padding:0.55rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.875rem;font-family:inherit;background:#f8fafc;color:#1e293b;outline:none;box-sizing:border-box;">
                
                <div id="warn-setoran" style="display:none;font-size:0.75rem;color:#b91c1c;
                            font-weight:600;margin-top:0.25rem;"></div>
                <div style="font-size:0.72rem;color:#64748b;margin-top:0.25rem;">
                    Nominal pembukaan rekening yang diizinkan:
                    Rp <?php echo e(number_format($minPembukaan, 0, ',', '.')); ?> –
                    Rp <?php echo e(number_format($maksPembukaan, 0, ',', '.')); ?>.
                </div>
                <div id="hint-saldo-sukarela" style="display:none;font-size:0.72rem;color:#64748b;margin-top:0.25rem;">
                    <?php if($sumberSukarela ?? null): ?>
                        Sumber dana: Simpanan Sukarela <strong><?php echo e($sumberSukarela->no_rekening); ?></strong>
                        — saldo tersedia Rp <?php echo e(number_format((float) $sumberSukarela->saldo, 0, ',', '.')); ?>.
                        Pokok deposito dipotong langsung dari saldo ini.
                    <?php else: ?>
                        <span style="color:#b91c1c;">Anggota belum memiliki Rekening Simpanan Sukarela aktif —
                        deposito tidak dapat dibuka.</span>
                    <?php endif; ?>
                </div>
            </div>

            
            <div id="wrap-nisbah" style="display:none;">
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                    Nisbah Nasabah (%)
                </label>
                <input type="number" name="nisbah_nasabah" id="inp-nisbah" min="0" max="100" step="0.01"
                       value="<?php echo e(old('nisbah_nasabah')); ?>" placeholder="Contoh: 40" readonly
                       style="width:100%;padding:0.55rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.875rem;font-family:inherit;background:#f1f5f9;color:#475569;outline:none;box-sizing:border-box;cursor:not-allowed;">
                <div id="hint-nisbah-sukarela" style="display:none;font-size:0.72rem;color:#94a3b8;margin-top:0.25rem;">
                    Nisbah default produk Simpanan Sukarela (tidak dapat diubah).
                </div>
                <div id="hint-nisbah-deposito" style="display:none;font-size:0.72rem;color:#94a3b8;margin-top:0.25rem;">
                    Nisbah mengikuti produk deposito yang dipilih (tidak dapat diubah).
                </div>
            </div>

            
            <div id="wrap-produk-deposito" style="display:none;">
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                    Produk Deposito <span style="color:#dc2626;">*</span>
                </label>
                <select name="deposito_product_id" id="sel-produk-deposito" disabled
                        style="width:100%;padding:0.55rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.875rem;font-family:inherit;background:#f8fafc;color:#1e293b;outline:none;">
                    <option value="">-- Pilih Produk --</option>
                    <?php $__currentLoopData = ($produkDeposito ?? collect()); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($p->id); ?>"
                            data-name="<?php echo e($p->name); ?>"
                            data-tenor="<?php echo e($p->tenor_months); ?>"
                            data-nisbah="<?php echo e($p->nisbah_anggota); ?>"
                            data-min="<?php echo e((float) $p->min_amount); ?>"
                            <?php echo e((string) old('deposito_product_id') === (string) $p->id ? 'selected' : ''); ?>>
                        <?php echo e($p->name); ?> — tenor <?php echo e($p->tenor_months); ?> bln, nisbah anggota <?php echo e(rtrim(rtrim(number_format((float) $p->nisbah_anggota, 2, ',', '.'), '0'), ',')); ?>%, min Rp <?php echo e(number_format((float) $p->min_amount, 0, ',', '.')); ?>

                    </option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
                
                <div id="loading-produk" style="display:none;font-size:0.72rem;color:#1e40af;margin-top:0.25rem;">
                    Memuat produk deposito&hellip;
                </div>
                <div id="info-produk-deposito" style="font-size:0.72rem;color:#64748b;margin-top:0.25rem;">
                    Produk deposito menentukan tenor dan nisbah bagi hasil.
                </div>
            </div>

            
            <div id="wrap-tenor" style="display:none;">
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                    Tenor (Bulan)
                </label>
                <input type="text" id="inp-tenor" readonly placeholder="Mengikuti produk deposito"
                       style="width:100%;padding:0.55rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.875rem;font-family:inherit;background:#f1f5f9;color:#475569;outline:none;box-sizing:border-box;cursor:not-allowed;">
                <div style="font-size:0.72rem;color:#94a3b8;margin-top:0.25rem;">
                    Ditentukan produk deposito yang dipilih (tidak dapat diubah).
                </div>
            </div>

            
            <div id="wrap-tanggal-akad" style="display:none;">
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                    Tanggal Akad <span style="color:#dc2626;">*</span>
                </label>
                <input type="date" name="tanggal_akad" id="inp-tanggal-akad" required
                       value="<?php echo e(old('tanggal_akad')); ?>"
                       style="width:100%;padding:0.55rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.875rem;font-family:inherit;background:#f8fafc;color:#1e293b;outline:none;box-sizing:border-box;">
                <div style="font-size:0.72rem;color:#94a3b8;margin-top:0.25rem;">
                    Tanggal mulai deposito. Jatuh tempo dihitung otomatis dari tanggal ini + tenor produk.
                </div>
            </div>

            
            <div id="wrap-jatuh-tempo" style="display:none;">
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                    Tanggal Jatuh Tempo
                </label>
                <input type="date" name="tanggal_jatuh_tempo" id="inp-jatuh-tempo" readonly
                       style="width:100%;padding:0.55rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.875rem;font-family:inherit;background:#f1f5f9;color:#475569;outline:none;box-sizing:border-box;cursor:not-allowed;">
                <div style="font-size:0.72rem;color:#94a3b8;margin-top:0.25rem;">
                    Dihitung otomatis dari Tanggal Akad + tenor produk (tidak dapat diubah manual).
                </div>
            </div>

            
            <div id="wrap-aro" style="display:none;">
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                    Mode Perpanjangan Otomatis (ARO)
                </label>
                <div style="display:flex;flex-direction:column;gap:0.4rem;">
                    <label style="display:flex;align-items:flex-start;gap:0.5rem;padding:0.55rem 0.75rem;
                                  border:1px solid #cbd5e1;border-radius:8px;background:#f8fafc;cursor:pointer;">
                        <input type="radio" name="aro_mode" value="<?php echo e(\App\Models\Deposito::ARO_NON); ?>"
                               class="inp-aro" onchange="onAroChange()"
                               <?php echo e(old('aro_mode', \App\Models\Deposito::ARO_NON) === \App\Models\Deposito::ARO_NON ? 'checked' : ''); ?>

                               style="margin-top:0.15rem;">
                        <span style="font-size:0.82rem;color:#1e293b;">
                            Tidak Perpanjang / Non-ARO
                            <span style="display:block;font-size:0.72rem;color:#94a3b8;">
                                Pokok + bagi hasil dicairkan ke Simpanan Sukarela saat jatuh tempo.
                            </span>
                        </span>
                    </label>
                    <label style="display:flex;align-items:flex-start;gap:0.5rem;padding:0.55rem 0.75rem;
                                  border:1px solid #cbd5e1;border-radius:8px;background:#f8fafc;cursor:pointer;">
                        <input type="radio" name="aro_mode" value="<?php echo e(\App\Models\Deposito::ARO_POKOK); ?>"
                               class="inp-aro" onchange="onAroChange()"
                               <?php echo e(old('aro_mode') === \App\Models\Deposito::ARO_POKOK ? 'checked' : ''); ?>

                               style="margin-top:0.15rem;">
                        <span style="font-size:0.82rem;color:#1e293b;">
                            Perpanjang Pokok
                            <span style="display:block;font-size:0.72rem;color:#94a3b8;">
                                Bagi hasil masuk Simpanan Sukarela; pokok diperpanjang tenor awal.
                            </span>
                        </span>
                    </label>
                    <label style="display:flex;align-items:flex-start;gap:0.5rem;padding:0.55rem 0.75rem;
                                  border:1px solid #cbd5e1;border-radius:8px;background:#f8fafc;cursor:pointer;">
                        <input type="radio" name="aro_mode" value="<?php echo e(\App\Models\Deposito::ARO_PLUS); ?>"
                               class="inp-aro" onchange="onAroChange()"
                               <?php echo e(old('aro_mode') === \App\Models\Deposito::ARO_PLUS ? 'checked' : ''); ?>

                               style="margin-top:0.15rem;">
                        <span style="font-size:0.82rem;color:#1e293b;">
                            Perpanjang Pokok + Bagi Hasil
                            <span style="display:block;font-size:0.72rem;color:#94a3b8;">
                                Bagi hasil dikapitalisasi menjadi pokok baru, lalu diperpanjang.
                            </span>
                        </span>
                    </label>
                </div>
                <div id="info-aro" style="font-size:0.72rem;color:#64748b;margin-top:0.35rem;"></div>
            </div>

            
            <div id="info-jurnal-biasa" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:0.5rem;
                        padding:0.75rem 1rem;font-size:0.78rem;color:#166534;">
                Jurnal otomatis: <strong>Debet Kas</strong> / <strong>Kredit Simpanan</strong> (status: Posted)
            </div>
            <div id="info-jurnal-deposito" style="display:none;background:#eff6ff;border:1px solid #bfdbfe;border-radius:0.5rem;
                        padding:0.75rem 1rem;font-size:0.78rem;color:#1e40af;">
                Jurnal PSAK 101/108: <strong>Debet <?php echo e(\App\Models\Deposito::COA_SIMPANAN_SUKARELA); ?> Simpanan Sukarela</strong>
                / <strong>Kredit <?php echo e(\App\Models\Deposito::COA_SIMPANAN_BERJANGKA); ?> Simpanan Berjangka Mudharabah</strong>
                (status: Posted). Pokok dipotong dari Simpanan Sukarela anggota — Kas Teller tidak digunakan.
            </div>

        </form>

        
        <div class="sticky bottom-0 z-10 flex justify-end"
             style="position:sticky;bottom:0;z-index:10;display:flex;gap:0.75rem;
                    justify-content:flex-end;padding:1rem 1.5rem;background:#fff;
                    border-top:1px solid #e2e8f0;border-radius:0 0 0.75rem 0.75rem;
                    flex:0 0 auto;">
            <button type="button"
                    onclick="document.getElementById('modal-buka-rekening').style.display='none'"
                    style="padding:0.55rem 1.25rem;background:#f1f5f9;color:#475569;
                           font-size:0.83rem;font-weight:600;border-radius:0.5rem;border:none;cursor:pointer;"
                    onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                Batal
            </button>
            <button type="submit" form="form-buka-rekening" id="btn-submit-rekening"
                    style="padding:0.55rem 1.25rem;background:#1e40af;color:#fff;
                           font-size:0.83rem;font-weight:600;border-radius:0.5rem;border:none;cursor:pointer;"
                    onmouseover="this.style.background='#1d4ed8'" onmouseout="this.style.background='#1e40af'">
                Buka Rekening
            </button>
        </div>
    </div>
</div>

<script>
/* =====================================================================
 | Integrasi modal "Buka Rekening Simpanan" dengan API Deposito Syariah
 |   GET  /api/deposito/products  → produk aktif (tenor, nisbah, minimum)
 |   POST /api/deposito/store     → buka deposito (potong Simpanan Sukarela)
 |
 | Aturan yang dijaga di sisi klien:
 |  - Tanggal jatuh tempo TIDAK PERNAH diinput manual: dihitung dari
 |    Tanggal Akad + tenor bulan produk, lalu dikunci read-only.
 |  - Nisbah nasabah diambil dari produk dan dikunci read-only.
 |  - Setoran awal di bawah min_amount produk memblokir pengiriman form.
 |===================================================================== */
window.DEPOSITO_CTX = {
    memberId:         <?php echo e((int) $anggota->id); ?>,
    sumberSukarelaId: <?php echo e(($sumberSukarela ?? null) ? (int) $sumberSukarela->id : 'null'); ?>,
    // Nisbah default produk Simpanan Sukarela (dinamis dari master produk).
    nisbahSukarela:   <?php echo e((float) ($nisbahSukarela ?? 0)); ?>,
    // Kode CoA acuan parameter persentase. Nilai PERSENTASE-nya tidak
    // ditanam di sini — selalu ditarik dari /api/deposito/coa-config agar
    // perubahan di Master COA langsung terpakai tanpa menyentuh Blade.
    kodeCoa: {
        sukarela: <?php echo json_encode(\App\Models\Deposito::COA_SIMPANAN_SUKARELA, 15, 512) ?>,
        deposito: <?php echo json_encode(\App\Models\Deposito::COA_SIMPANAN_BERJANGKA, 15, 512) ?>,
        margin:   <?php echo json_encode(\App\Services\AutoJournalingService::PIUTANG_MURABAHAH, 15, 512) ?>,
        pokok:    <?php echo json_encode(\App\Services\AutoJournalingService::SIMPANAN_POKOK, 15, 512) ?>,
        wajib:    <?php echo json_encode(\App\Services\AutoJournalingService::SIMPANAN_WAJIB, 15, 512) ?>
    },
    urlProduk:        <?php echo json_encode(url('/api/deposito/products'), 15, 512) ?>,
    urlCoaConfig:     <?php echo json_encode(url('/api/deposito/coa-config'), 15, 512) ?>,
    urlStore:         <?php echo json_encode(url('/api/deposito/store'), 15, 512) ?>,
    urlPortofolio:    <?php echo json_encode(url()->current(), 15, 512) ?>,
};

(function () {
    'use strict';

    var CTX = window.DEPOSITO_CTX;
    var NISBAH_SUKARELA = angkaAman(CTX.nisbahSukarela, 0);
    var produkList = [];              // produk dari API (fallback: opsi Blade)
    var produkSudahDimuat = false;    // cegah permintaan API berulang
    var sedangMemuatProduk = false;
    var sedangKirim = false;          // cegah pengiriman ganda
    var coaConfig = null;             // snapshot parameter CoA dari API
    var sedangMemuatCoa = false;
    var coaSudahDiterapkan = false;   // cegah render ulang berulang pasca-API

    function $(id) { return document.getElementById(id); }

    function rupiah(nilai) { return Number(nilai || 0).toLocaleString('id-ID'); }

    function escapeHtml(teks) {
        return String(teks == null ? '' : teks)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /* Tanggal akad dari field input, diparse sebagai tanggal lokal
       (menghindari pergeseran zona waktu yang terjadi pada Date('YYYY-MM-DD')).
       Mengembalikan null bila field belum diisi / tidak sah — TIDAK ada
       fallback ke tanggal hari ini, karena perhitungan jatuh tempo memang
       hanya boleh berjalan setelah pengguna mengisi Tanggal Akad. */
    function tanggalAkad() {
        var nilai = $('inp-tanggal-akad').value;
        var cocok = /^(\d{4})-(\d{2})-(\d{2})$/.exec(nilai || '');

        if (!cocok) { return null; }

        return new Date(parseInt(cocok[1], 10), parseInt(cocok[2], 10) - 1, parseInt(cocok[3], 10));
    }

    /* Jatuh tempo = Tanggal Akad + tenor bulan. Meniru Carbon::addMonthsNoOverflow()
       di backend: bila tanggal akhir bulan tidak ada di bulan tujuan
       (31 Jan + 1 bulan), dipakai hari terakhir bulan tujuan (28/29 Feb)
       agar tidak meluap ke bulan berikutnya. Transisi tahun ditangani oleh
       objek Date (mis. Nov + 3 bulan → Feb tahun berikutnya). Akhir bulan &
       tahun kabisat dihitung oleh Date (mis. 31 Jan 2024 + 1 bulan = 29 Feb 2024).
       Mengembalikan null bila tenor tidak sah atau Tanggal Akad belum diisi,
       agar tidak menghasilkan tanggal "NaN-NaN-NaN". */
    function hitungJatuhTempo(tenorBulan) {
        var tenor = angkaAman(tenorBulan, 0);
        if (tenor <= 0) { return null; }

        var mulai = tanggalAkad();
        if (!mulai) { return null; }

        var hari = mulai.getDate();

        var target = new Date(mulai.getFullYear(), mulai.getMonth() + tenor, 1);
        var hariTerakhir = new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate();

        target.setDate(Math.min(hari, hariTerakhir));

        return target;
    }

    /* Tampilkan/sembunyikan blok field dengan transisi smooth (fade + slide)
       agar perubahan tata letak tidak melompat. Memakai transisi opacity +
       transform (bukan max-height) supaya isi di dalamnya tidak pernah
       terpotong di layar sempit. Hormati preferensi reduce-motion. */
    var KURANG_GERAK = (window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    function terapkanVisibilitas(blok, tampil) {
        if (!blok) { return; }

        // Token menandai permintaan terakhir untuk blok ini. Callback transisi
        // yang datang terlambat (pengguna men-toggle jenis rekening dengan
        // cepat) diabaikan agar tidak menyembunyikan blok yang seharusnya
        // sudah tampil kembali.
        var token = (blok.__visToken = (blok.__visToken || 0) + 1);

        if (tampil) {
            blok.style.display = 'block';
            blok.setAttribute('aria-hidden', 'false');

            if (KURANG_GERAK) { return; }

            blok.style.transition = 'none';
            blok.style.opacity = '0';
            blok.style.transform = 'translateY(-4px)';

            window.requestAnimationFrame(function () {
                if (blok.__visToken !== token) { return; }
                blok.style.transition = 'opacity 0.18s ease, transform 0.18s ease';
                blok.style.opacity = '1';
                blok.style.transform = 'translateY(0)';
            });

            return;
        }

        blok.setAttribute('aria-hidden', 'true');

        var sembunyikan = function () {
            if (blok.__visToken !== token) { return; }
            blok.style.display = 'none';
            blok.style.transition = '';
            blok.style.opacity = '';
            blok.style.transform = '';
        };

        if (KURANG_GERAK || blok.style.display === 'none') {
            sembunyikan();
            return;
        }

        blok.style.transition = 'opacity 0.15s ease, transform 0.15s ease';
        blok.style.opacity = '0';
        blok.style.transform = 'translateY(-4px)';

        window.setTimeout(sembunyikan, 150);
    }

    function formatIso(tanggal) {
        return tanggal.getFullYear() + '-'
            + String(tanggal.getMonth() + 1).padStart(2, '0') + '-'
            + String(tanggal.getDate()).padStart(2, '0');
    }

    /* Konversi angka yang tahan nilai kosong / tidak terdefinisi / "abc":
       mengembalikan `bawaan` bila hasil parse bukan angka yang sah. */
    function angkaAman(nilai, bawaan) {
        var angka = parseFloat(nilai);
        return isNaN(angka) ? bawaan : angka;
    }

    /* Opsi produk yang sedang dipilih (null bila masih di opsi default). */
    function opsiProdukTerpilih() {
        var sel = $('sel-produk-deposito');
        if (!sel || sel.selectedIndex < 0) { return null; }

        var opt = sel.options[sel.selectedIndex];
        return (opt && opt.value) ? opt : null;
    }

    /* Produk terpilih: diambil dari data API, dengan fallback membaca atribut
       data pada opsi (data-tenor / data-nisbah / data-min) bila API belum/gagal
       dimuat. Mengembalikan null bila tidak ada pilihan yang sah — sehingga
       pemanggil tidak pernah menghitung dari nilai yang tidak terdefinisi. */
    function produkTerpilih() {
        var opt = opsiProdukTerpilih();
        if (!opt) { return null; }

        for (var i = 0; i < produkList.length; i++) {
            if (String(produkList[i].id) === String(opt.value)) { return produkList[i]; }
        }

        var tenor  = angkaAman(opt.dataset.tenor, 0);
        var nisbah = angkaAman(opt.dataset.nisbah, 0);
        var min    = angkaAman(opt.dataset.min, 0);

        // Opsi tanpa satu pun atribut data dianggap tidak sah.
        if (!tenor && !nisbah && !min) { return null; }

        return {
            id: parseInt(opt.value, 10),
            name: opt.dataset.name || opt.textContent.trim(),
            tenor_months: tenor,
            nisbah_anggota: nisbah,
            min_amount: min
        };
    }

    /* Nisbah nasabah produk. Endpoint mengirim `nisbah_anggota`; `nisbah`
       tetap didukung bila kontrak respons berubah. Nilai kosong/tidak
       terdefinisi dikembalikan sebagai '' agar input tidak berisi "undefined". */
    function nisbahProduk(produk) {
        if (!produk) { return ''; }

        var kandidat = [produk.nisbah_anggota, produk.nisbah];

        for (var i = 0; i < kandidat.length; i++) {
            if (kandidat[i] === undefined || kandidat[i] === null || kandidat[i] === '') { continue; }
            if (isNaN(parseFloat(kandidat[i]))) { continue; }
            return kandidat[i];
        }

        return '';
    }

    /* Produk awal dari opsi yang dirender Blade — agar form tetap berfungsi
       bila permintaan API gagal. */
    function muatProdukDariOpsi() {
        var sel = $('sel-produk-deposito');
        produkList = [];

        for (var i = 0; i < sel.options.length; i++) {
            var opt = sel.options[i];
            if (!opt.value) { continue; }

            produkList.push({
                id: parseInt(opt.value, 10),
                name: opt.dataset.name || opt.textContent.trim(),
                tenor_months: parseInt(opt.dataset.tenor, 10) || 0,
                nisbah_anggota: parseFloat(opt.dataset.nisbah) || 0,
                min_amount: parseFloat(opt.dataset.min) || 0
            });
        }
    }

    function renderOpsiProduk(produk) {
        var sel = $('sel-produk-deposito');
        var terpilih = sel.value;

        sel.innerHTML = '<option value="">-- Pilih Produk --</option>';

        produk.forEach(function (p) {
            var opt = document.createElement('option');
            opt.value = p.id;
            opt.dataset.name = p.name;
            opt.dataset.tenor = p.tenor_months;
            opt.dataset.nisbah = nisbahProduk(p);
            opt.dataset.min = p.min_amount;
            opt.textContent = p.name + ' — tenor ' + p.tenor_months + ' bln, nisbah anggota '
                + nisbahProduk(p) + '%, min Rp ' + rupiah(p.min_amount);
            sel.appendChild(opt);
        });

        if (terpilih) { sel.value = terpilih; }
    }

    /* GET /api/deposito/coa-config — tarik parameter persentase terkini dari
       Master COA (Simpanan Sukarela 2-1001, Deposito 2-1003, Margin 1-2001,
       Pokok 3-1001, Wajib 3-1002). Dipanggil setiap kali jenis transaksi
       berubah, sehingga nisbah di layar selalu sinkron dengan master.
       Idempoten: hanya satu permintaan berjalan pada satu waktu. */
    function muatKonfigurasiCoa(lanjut) {
        if (coaConfig || sedangMemuatCoa) {
            if (typeof lanjut === 'function') { lanjut(); }
            return;
        }

        sedangMemuatCoa = true;

        fetch(CTX.urlCoaConfig, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (res) {
            if (!res.ok) { throw new Error('HTTP ' + res.status); }
            return res.json();
        })
        .then(function (body) {
            var data = (body && body.data) ? body.data : null;
            if (!data || !data.coa) { throw new Error('Payload CoA tidak lengkap.'); }

            coaConfig = data;

            // Nisbah Simpanan Sukarela kini bersumber dari Master COA
            // (2-1001). Fallback ke master produk hanya bila CoA belum
            // menerapkan persentase.
            var coa = coaUntuk('sukarela');
            if (coa && coa.has_percentage) {
                NISBAH_SUKARELA = angkaAman(coa.default_percentage_member, NISBAH_SUKARELA);
            }

            if (data.products && data.products.length) {
                produkList    = data.products;
                produkSudahDimuat = true;
                renderOpsiProduk(data.products);
            }

            if (typeof lanjut === 'function') { lanjut(); }
        })
        .catch(function () {
            // Parameter CoA yang sudah dirender Blade tetap dipakai.
            if (typeof lanjut === 'function') { lanjut(); }
        })
        .finally(function () {
            sedangMemuatCoa = false;
        });
    }

    /* Ambil konfigurasi CoA menurut kunci ('sukarela', 'deposito', 'margin',
       'pokok', 'wajib'). Mengembalikan null bila belum dimuat. */
    function coaUntuk(kunci) {
        if (!coaConfig || !coaConfig.coa) { return null; }
        return coaConfig.coa[kunci] || null;
    }

    /* Tipe jenis simpanan (pokok/wajib/sukarela/berjangka/lain) menurut
       PARAMETER DINAMIS yang dirender Blade pada atribut `data-tipe` opsi
       terpilih. Tidak ada nama jenis yang ditanam di JavaScript, sehingga
       jenis baru atau yang berganti nama dikenali otomatis.
       Mengembalikan '' bila belum ada pilihan. */
    function tipeJenis(val) {
        var sel = $('sel-jenis');
        if (!sel || !sel.options || !sel.selectedIndex || sel.selectedIndex < 0) { return ''; }
        var opsi = sel.options[sel.selectedIndex];
        if (!opsi || (val && opsi.value !== val)) { return ''; }
        return opsi.getAttribute('data-tipe') || '';
    }

    /* Apakah jenis rekening menerapkan persentase (nisbah/margin)?
       Bila false (Simpanan Pokok/Wajib), seluruh field Nisbah/Tenor/Jatuh
       Tempo disembunyikan karena imbalannya lewat SHU Tahunan.
       Klasifikasi mengikuti TIPE parameter dinamis, bukan nama jenis. */
    function jenisMenerapkanPersentase(val) {
        var tipe = tipeJenis(val);

        if (tipe !== 'pokok' && tipe !== 'wajib' && tipe !== 'sukarela') { return null; }

        var coa = coaUntuk(tipe);

        return coa ? !!coa.has_percentage : null;
    }

    /* GET /api/deposito/products — dengan indikator loading. */
    function muatProdukDeposito() {
        if (produkSudahDimuat || sedangMemuatProduk) { return; }

        sedangMemuatProduk = true;
        $('loading-produk').style.display = 'block';
        $('sel-produk-deposito').disabled = true;

        fetch(CTX.urlProduk, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (res) {
            if (!res.ok) { throw new Error('HTTP ' + res.status); }
            return res.json();
        })
        .then(function (body) {
            var produk = (body && body.data) ? body.data : [];

            if (!produk.length) {
                tampilkanError({ message: 'Belum ada produk deposito aktif. Silakan hubungi pengurus koperasi.' });
                return;
            }

            produkList = produk;
            produkSudahDimuat = true;
            renderOpsiProduk(produk);
            terapkanProduk();
        })
        .catch(function () {
            // Opsi hasil render Blade tetap dipakai sebagai fallback.
            tampilkanError({ message: 'Gagal memuat produk deposito dari server. '
                + 'Daftar produk yang tersedia tetap dapat dipilih.' });
        })
        .finally(function () {
            sedangMemuatProduk = false;
            $('loading-produk').style.display = 'none';
            // Dropdown produk hanya aktif untuk jenis rekening bertipe deposito.
            $('sel-produk-deposito').disabled = tipeJenis($('sel-jenis').value) !== 'berjangka';
        });
    }

    /* Terapkan produk terpilih: tenor & nisbah (read-only), jatuh tempo
       (read-only). Bila dropdown dikembalikan ke opsi default, nilai & status
       read-only pada ketiga field dibersihkan agar pengguna dapat mengisinya
       kembali. */
    function terapkanProduk() {
        var produk = produkTerpilih();
        var inpNisbah = $('inp-nisbah');
        var inpTenor = $('inp-tenor');
        var inpJatuh = $('inp-jatuh-tempo');

        if (!produk) {
            inpNisbah.value = '';
            inpNisbah.readOnly = false;
            inpTenor.value = '';
            // Jatuh tempo kosong & terkunci; nilainya akan muncul kembali
            // begitu produk dipilih.
            inpJatuh.value = '';
            inpJatuh.readOnly = true;
            $('info-produk-deposito').textContent = 'Produk deposito menentukan tenor dan nisbah bagi hasil.';
            validasiSetoran();
            return;
        }

        var nisbah = nisbahProduk(produk);

        inpNisbah.value = nisbah;
        inpNisbah.readOnly = true;

        // Tenor mengikuti produk (read-only, tidak dapat diubah pengguna).
        inpTenor.value = angkaAman(produk.tenor_months, 0) + ' bulan';

        // Jatuh tempo dihitung dari Tanggal Akad + tenor produk.
        var jatuhTempo = hitungJatuhTempo(produk.tenor_months);
        inpJatuh.value = jatuhTempo ? formatIso(jatuhTempo) : '';
        inpJatuh.readOnly = true;

        $('info-produk-deposito').innerHTML = 'Tenor <strong>' + angkaAman(produk.tenor_months, 0) + ' bulan</strong> &middot; '
            + 'nisbah anggota <strong>' + (nisbah === '' ? '—' : nisbah) + '%</strong> &middot; '
            + 'minimum Rp ' + rupiah(produk.min_amount) + '.';

        validasiSetoran();
    }

    /* Perbarui Tanggal Jatuh Tempo saja (dipakai saat Tanggal Akad berubah).
       Tidak menyentuh nisbah, dan tidak menghitung bila produk belum dipilih
       valid — mencegah perhitungan dari nilai yang tidak terdefinisi.
       Bila Tanggal Akad dikosongkan, hasilnya juga dikosongkan (read-only),
       bukan menyisakan tanggal lama yang menyesatkan. */
    function perbaruiJatuhTempo() {
        var inpJatuh = $('inp-jatuh-tempo');
        var produk = produkTerpilih();

        if (!produk) { return; }

        var jatuhTempo = hitungJatuhTempo(produk.tenor_months);
        inpJatuh.value = jatuhTempo ? formatIso(jatuhTempo) : '';
        inpJatuh.readOnly = true;
    }

    /* Validasi real-time nominal pembukaan rekening:
       - Batas min/max parameter koperasi (dinamis, berlaku semua jenis rekening).
       - Untuk deposito: juga tidak boleh kurang dari min_amount produk. */
    function validasiSetoran() {
        var warn = $('warn-setoran');
        var tombol = $('btn-submit-rekening');
        var input = $('inp-setoran');
        var nilai = parseFloat(input.value || '0');
        var minParam = parseFloat(input.dataset.min || '0');
        var maksParam = parseFloat(input.dataset.max || '0');

        // 1. Batas parameter koperasi — berlaku untuk SEMUA jenis rekening.
        if (nilai > 0 && minParam > 0 && nilai < minParam) {
            warn.textContent = 'Nominal pembukaan rekening tidak boleh kurang dari Rp '
                + rupiah(minParam) + ' (nilai nominal di bawah batas minimum yang diizinkan).';
            warn.style.display = 'block';
            tombol.disabled = true;
            return false;
        }

        if (nilai > 0 && maksParam > 0 && nilai > maksParam) {
            warn.textContent = 'Nominal pembukaan rekening tidak boleh melebihi Rp '
                + rupiah(maksParam) + ' (nilai nominal di atas batas maksimum yang diizinkan).';
            warn.style.display = 'block';
            tombol.disabled = true;
            return false;
        }

        // 2. Khusus deposito: tidak boleh kurang dari min_amount produk.
        if (tipeJenis($('sel-jenis').value) === 'berjangka') {
            var produk = produkTerpilih();

            if (produk && nilai < produk.min_amount) {
                warn.textContent = 'Setoran awal kurang dari batas minimal produk ' + produk.name
                    + ' sebesar Rp ' + rupiah(produk.min_amount) + '.';
                warn.style.display = 'block';
                tombol.disabled = true;
                return false;
            }
        }

        warn.style.display = 'none';
        tombol.disabled = sedangKirim;
        return true;
    }

    /* Tampilkan pesan error respons API di bagian atas modal. */
    function tampilkanError(body) {
        var kotak = $('modal-error');
        var daftar = [];

        if (body && body.errors) {
            Object.keys(body.errors).forEach(function (kunci) {
                (body.errors[kunci] || []).forEach(function (pesan) { daftar.push(pesan); });
            });
        }

        var html;

        if (daftar.length) {
            html = '<ul style="margin:0;padding-left:1.25rem;">';
            daftar.forEach(function (pesan) { html += '<li>' + escapeHtml(pesan) + '</li>'; });
            html += '</ul>';
        } else {
            html = escapeHtml((body && body.message)
                ? body.message
                : 'Terjadi kesalahan saat memproses pembukaan deposito.');
        }

        kotak.innerHTML = html;
        kotak.style.display = 'block';
        kotak.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function sembunyikanError() {
        var kotak = $('modal-error');
        kotak.style.display = 'none';
        kotak.innerHTML = '';
    }

    /* Pesan hasil pembukaan deposito pada halaman Portofolio CIF — ditampilkan
       langsung tanpa memuat ulang halaman. */
    function tampilkanFlash(pesan, galat) {
        var kotak = $('flash-deposito');
        if (!kotak) { return; }

        kotak.textContent        = pesan;
        kotak.style.background   = galat ? '#fee2e2' : '#d1fae5';
        kotak.style.borderColor  = galat ? '#fca5a5' : '#6ee7b7';
        kotak.style.color        = galat ? '#991b1b' : '#065f46';
        kotak.style.display      = 'block';
        kotak.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    /* Kembalikan modal ke keadaan awal setelah deposito berhasil dibuka,
       agar pembukaan berikutnya tidak membawa sisa isian sebelumnya. */
    function resetFormDeposito() {
        var form = $('form-buka-rekening');
        if (form) { form.reset(); }

        sembunyikanError();
        $('warn-setoran').style.display = 'none';
        $('sel-jenis').value = '';
        window.onJenisChange('');

        // `form.reset()` tidak mengembalikan nilai `value` yang ditulis JS
        // (nisbah & tenor dari produk), jadi keduanya dibersihkan eksplisit.
        $('inp-tenor').value = '';
        $('inp-nisbah').value = '';
        $('inp-nisbah').readOnly = false;
        $('inp-jatuh-tempo').value = '';
    }

    /* POST /api/deposito/store — kirim data form sebagai JSON. */
    function kirimDeposito(event) {
        event.preventDefault();

        if (sedangKirim) { return; }
        if (!validasiSetoran()) { return; }

        var produk = produkTerpilih();

        if (!produk) {
            tampilkanError({ message: 'Pilih produk deposito terlebih dahulu.' });
            return;
        }

        if (!CTX.sumberSukarelaId) {
            tampilkanError({ message: 'Anggota belum memiliki Rekening Simpanan Sukarela aktif. '
                + 'Deposito hanya dapat dibuka dengan sumber dana dari Simpanan Sukarela.' });
            return;
        }

        // Tanggal Akad wajib terisi: dipakai sebagai `start_date` sekaligus
        // basis perhitungan jatuh tempo. Tanpa ini, perhitungan tidak sah.
        var akad = tanggalAkad();

        if (!akad) {
            tampilkanError({ message: 'Lengkapi Tanggal Akad terlebih dahulu.' });
            return;
        }

        var radioAro = document.querySelector('input[name="aro_mode"]:checked');

        var muatan = {
            member_id: CTX.memberId,
            product_id: produk.id,
            source_saving_account_id: CTX.sumberSukarelaId,
            principal_amount: parseFloat($('inp-setoran').value),
            start_date: formatIso(akad),
            // Mode ARO wajib salah satu dari NON_ARO / ARO_POKOK / ARO_PLUS.
            aro_mode: radioAro ? radioAro.value : <?php echo json_encode(\App\Models\Deposito::ARO_NON, 15, 512) ?>
        };

        sedangKirim = true;
        sembunyikanError();

        var tombol = $('btn-submit-rekening');
        var labelAsli = tombol.textContent;
        tombol.disabled = true;
        tombol.textContent = 'Memproses…';

        fetch(CTX.urlStore, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(muatan)
        })
        .then(function (res) {
            return res.text().then(function (teks) {
                var body = {};
                try { body = teks ? JSON.parse(teks) : {}; } catch (e) { body = {}; }
                return { ok: res.ok, status: res.status, body: body };
            });
        })
        .then(function (hasil) {
            if (!hasil.ok) {
                if (hasil.body && (hasil.body.message || hasil.body.errors)) {
                    tampilkanError(hasil.body);
                } else {
                    tampilkanError({ message: 'Pembukaan deposito gagal (kode ' + hasil.status + '). Silakan coba lagi.' });
                }
                return;
            }

            // Sukses: tutup modal, tampilkan konfirmasi, lalu segarkan HANYA
            // daftar bilyet deposito pada Portofolio CIF (refresh parsial —
            // tanpa memuat ulang seluruh halaman).
            $('modal-buka-rekening').style.display = 'none';
            resetFormDeposito();
            tampilkanFlash((hasil.body && hasil.body.message)
                ? hasil.body.message
                : 'Deposito berhasil dibuka.', false);
            muatUlangPortofolio();
        })
        .catch(function () {
            tampilkanError({ message: 'Tidak dapat menghubungi server. Periksa koneksi Anda lalu coba lagi.' });
        })
        .finally(function () {
            sedangKirim = false;
            tombol.textContent = labelAsli;
            validasiSetoran();
        });
    }

    /* Cetak bilyet PDF ditangani oleh script pada partial
       resources/views/partials/deposito-bilyet-table.blade.php. */

    /* REFRESH PARSIAL daftar bilyet deposito pada Portofolio CIF.
       Hanya fragmen tabel yang diminta ulang dari server (?partial=deposito)
       lalu ditukar di DOM — halaman TIDAK dimuat ulang, sehingga posisi
       scroll, modal, dan bagian lain halaman tetap utuh. */
    function muatUlangPortofolio() {
        var wadah = $('deposito-list-wrapper');
        if (!wadah) { return; }

        fetch(CTX.urlPortofolio + '?partial=deposito&_=' + Date.now(), {
            headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
        .then(function (res) {
            if (!res.ok) { throw new Error('HTTP ' + res.status); }
            return res.text();
        })
        .then(function (html) {
            var dokumen = new DOMParser().parseFromString(html, 'text/html');
            var kartu   = dokumen.getElementById('deposito-bilyet-card');

            if (!kartu) { throw new Error('Fragmen daftar deposito tidak ditemukan.'); }

            wadah.innerHTML = kartu.outerHTML;
        })
        .catch(function () {
            tampilkanFlash('Daftar deposito gagal dimuat ulang otomatis. '
                + 'Muat ulang halaman untuk melihat data terbaru.', true);
        });
    }

    /* Tampilkan/sembunyikan field sesuai jenis rekening yang dipilih.
       Aturan PSAK 101/108 yang ditegakkan:
        - Simpanan Pokok & Simpanan Wajib → Nisbah, Tenor, dan Tanggal Jatuh
          Tempo disembunyikan: pendapatannya dihitung lewat SHU Tahunan pada
          bagian Ekuitas, bukan bagi hasil bulanan.
        - Simpanan Sukarela → nisbah default produk simpanan sukarela tampil
          READ-ONLY; Tenor & Tanggal Jatuh Tempo disembunyikan (tanpa tenor).
        - Simpanan Berjangka / Deposito → dropdown Produk Deposito tampil;
          nisbah & jatuh tempo mengikuti produk (read-only). */
    window.onJenisChange = function (val) {
        // Setiap kali jenis transaksi berubah, parameter CoA ditarik ulang
        // dari API. Bila belum selesai dimuat, tampilan sementara memakai
        // nilai fallback Blade, lalu disegarkan sekali setelah API menjawab.
        if (!coaConfig) {
            muatKonfigurasiCoa(function () {
                if (coaSudahDiterapkan) { return; }
                coaSudahDiterapkan = true;
                window.onJenisChange($('sel-jenis').value);
            });
        }

        // Klasifikasi perilaku form mengikuti TIPE dari PARAMETER DINAMIS
        // (atribut `data-tipe` opsi terpilih), bukan nama jenis hardcoded:
        //  - berjangka → alur deposito (produk, tenor, ARO)
        //  - sukarela  → titipan dengan nisbah default
        //  - pokok/wajib → tanpa nisbah (imbalan via SHU tahunan)
        var tipeJenisVal = tipeJenis(val);
        var deposito     = (tipeJenisVal === 'berjangka');
        var sukarela     = (tipeJenisVal === 'sukarela');
        var tanpaNisbah  = (tipeJenisVal === 'pokok' || tipeJenisVal === 'wajib');
        var selAkad      = $('sel-akad');
        var selProduk    = $('sel-produk-deposito');
        var inpNisbah    = $('inp-nisbah');

        // Field mana pun yang tidak relevan dinonaktifkan, sehingga nilainya
        // tidak terkirim bersama form dan tidak memicu validasi palsu.
        // `kosongkan` dipakai untuk field turunan yang harus ikut dibersihkan.
        function setField(id, tampil, aktif, kosongkan) {
            // Wrapper memakai id `wrap-<nama>` tanpa awalan `inp-`
            // (mis. field `inp-tanggal-akad` → wrapper `wrap-tanggal-akad`).
            var wrap = $('wrap-' + id.replace(/^inp-/, ''));
            var field = $(id);

            // Tampil/sembunyi memakai transisi smooth, bukan lompatan layout.
            terapkanVisibilitas(wrap, tampil);
            if (!field) { return; }

            field.disabled = !aktif;
            if (kosongkan) { field.value = ''; }
        }

        // Field khusus deposito (Produk/Tenor, Tanggal Akad, Jatuh Tempo, ARO)
        // selalu disembunyikan & dibersihkan untuk jenis simpanan non-deposito
        // (Pokok/Wajib/Sukarela) — tanpa tenor, sehingga tidak memakai field ini.
        if (!deposito) {
            setField('inp-tenor', false, false, true);
            setField('inp-tanggal-akad', false, false, true);
            setField('inp-jatuh-tempo', false, false, true);
            terapkanVisibilitas($('wrap-aro'), false);
            terapkanVisibilitas($('wrap-produk-deposito'), false);
            selProduk.required = false;
            selProduk.disabled = true;
            selProduk.value = '';
            $('hint-nisbah-deposito').style.display = 'none';
            $('label-setoran').textContent = 'Setoran Awal (Rp)';
            $('hint-saldo-sukarela').style.display = 'none';
            terapkanVisibilitas($('info-jurnal-biasa'), true);
            terapkanVisibilitas($('info-jurnal-deposito'), false);
        }

        if (deposito) {
            // Akad deposito selalu Mudharabah Muthlaqah.
            selAkad.value = 'Mudharabah';
            selAkad.disabled = true;

            // Tenor (read-only, mengikuti produk), Tanggal Akad & Jatuh Tempo
            // tampil dan aktif. Tanggal Akad TIDAK diisi otomatis: perhitungan
            // jatuh tempo baru berjalan setelah pengguna mengisinya sendiri.
            setField('inp-tenor', true, true, false);
            setField('inp-tanggal-akad', true, true, false);
            setField('inp-jatuh-tempo', true, true, false);

            terapkanVisibilitas($('wrap-produk-deposito'), true);
            selProduk.required = true;
            // Dropdown produk hanya aktif untuk deposito (dan sementara
            // dinonaktifkan oleh muatProdukDeposito selama permintaan API).
            selProduk.disabled = sedangMemuatProduk;

            // Nisbah tampil namun terkunci karena mengikuti produk (read-only,
            // bukan disabled, agar dapat dibuka kembali saat produk direset).
            $('wrap-nisbah').style.display = 'block';
            inpNisbah.disabled = false;
            inpNisbah.readOnly = true;
            $('hint-nisbah-sukarela').style.display = 'none';
            $('hint-nisbah-deposito').style.display = 'block';

            $('label-setoran').textContent = 'Pokok Deposito (Rp)';
            $('hint-saldo-sukarela').style.display = 'block';

            // Mode ARO: pilihan perpanjangan otomatis saat jatuh tempo.
            terapkanVisibilitas($('wrap-aro'), true);
            window.onAroChange();

            terapkanVisibilitas($('info-jurnal-biasa'), false);
            terapkanVisibilitas($('info-jurnal-deposito'), true);

            muatProdukDeposito();
            terapkanProduk();

            return;
        }

        // Jenis simpanan biasa memakai akad sebagaimana dipilih pengguna.
        selAkad.disabled = false;

        // `has_percentage = false` pada CoA (Simpanan Pokok 3-1001 & Simpanan
        // Wajib 3-1002) berarti imbalannya lewat SHU Tahunan — seluruh field
        // Nisbah/Tenor/Jatuh Tempo disembunyikan, dinonaktifkan, dan
        // dikosongkan agar tidak ikut terkirim saat form disubmit.
        if (tanpaNisbah) {
            inpNisbah.readOnly = false;
            setField('inp-nisbah', false, false, true);
            $('hint-nisbah-sukarela').style.display = 'none';
            validasiSetoran();
            return;
        }

        // Simpanan Sukarela: nisbah ditarik dari Master COA 2-1001 (dinamis,
        // read-only); Tenor & Jatuh Tempo tetap tersembunyi karena tanpa tenor.
        // Bila `has_percentage` pada CoA 2-1001 dimatikan pengurus, nisbah juga
        // disembunyikan (jenisMenerapkanPersentase() === false).
        if (sukarela) {
            if (jenisMenerapkanPersentase(val) === false) {
                inpNisbah.readOnly = false;
                setField('inp-nisbah', false, false, true);
                $('hint-nisbah-sukarela').style.display = 'none';
                validasiSetoran();
                return;
            }

            $('wrap-nisbah').style.display = 'block';
            inpNisbah.disabled = false;
            inpNisbah.readOnly = true;
            inpNisbah.value = NISBAH_SUKARELA > 0 ? NISBAH_SUKARELA : '';
            $('hint-nisbah-sukarela').style.display = 'block';
            validasiSetoran();
            return;
        }

        // Jenis rekening belum dipilih: seluruh field turunan disembunyikan.
        inpNisbah.readOnly = false;
        setField('inp-nisbah', false, false, true);
        $('hint-nisbah-sukarela').style.display = 'none';
        validasiSetoran();
    };

    /* Listener 'change' dropdown Produk Deposito: menarik `tenor_months` dan
       `nisbah_anggota` dari opsi terpilih, mengisi Nisbah Nasabah (read-only)
       dan menghitung Tanggal Jatuh Tempo. `terapkanProduk` sudah menangani
       nilai atribut yang kosong / tidak terdefinisi tanpa melempar error. */
    window.onProdukDepositoChange = function () {
        terapkanProduk();
    };

    /* Listener 'change' Tanggal Akad: hanya menghitung ulang jatuh tempo.
       Perhitungan baru berjalan bila kedua syarat terpenuhi — Tanggal Akad
       sudah diisi pengguna DAN produk deposito (1/3/6 bulan) sudah dipilih.
       Bila produk belum dipilih valid, perhitungan dihentikan agar tidak
       memakai nilai yang tidak terdefinisi. */
    window.onTanggalAkadChange = function () {
        var produk = produkTerpilih();

        if (!produk) { return; }

        perbaruiJatuhTempo();
    };

    /* Listener radio Mode ARO: perbarui ringkasan perilaku yang dipilih. */
    window.onAroChange = function () {
        var terpilih = document.querySelector('input[name="aro_mode"]:checked');
        var info = $('info-aro');

        if (!info) { return; }

        var nilai = terpilih ? terpilih.value : <?php echo json_encode(\App\Models\Deposito::ARO_NON, 15, 512) ?>;
        var label = <?php echo json_encode(\App\Models\Deposito::ARO_LABEL, 15, 512) ?>;

        info.textContent = 'Saat jatuh tempo: ' + (label[nilai] || label[<?php echo json_encode(\App\Models\Deposito::ARO_NON, 15, 512) ?>]) + '.';
    };

    document.addEventListener('DOMContentLoaded', function () {
        // Fallback: pakai opsi produk yang dirender Blade sampai API menjawab.
        muatProdukDariOpsi();

        // Otomatisasi nisbah & jatuh tempo mengikuti produk deposito terpilih.
        $('sel-produk-deposito').addEventListener('change', window.onProdukDepositoChange);
        $('inp-setoran').addEventListener('input', validasiSetoran);

        // Jatuh tempo diperbarui real-time saat Tanggal Akad diubah.
        $('inp-tanggal-akad').addEventListener('change', window.onTanggalAkadChange);
        $('inp-tanggal-akad').addEventListener('input', window.onTanggalAkadChange);

        $('form-buka-rekening').addEventListener('submit', function (event) {
            // Jenis selain deposito tetap memakai alur POST biasa ke server.
            if (tipeJenis($('sel-jenis').value) !== 'berjangka') { return; }
            kirimDeposito(event);
        });

        // Selaraskan visibilitas & status seluruh field dengan jenis rekening
        // yang sedang terpilih — termasuk pemulihan old input saat validasi
        // server gagal (Blade sudah menandai opsi `selected`).
        window.onJenisChange($('sel-jenis').value);

        <?php if($errors->any()): ?>
            $('modal-buka-rekening').style.display = 'flex';
        <?php endif; ?>

        // Selaraskan tombol dengan validasi awal (mis. deposito belum dipilih).
        validasiSetoran();
    });
})();
</script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\ao\cif\show.blade.php ENDPATH**/ ?>