<?php $__env->startSection('title', 'Pembukaan Rekening Baru'); ?>

<?php $__env->startSection('content'); ?>
<div style="max-width:700px;margin:0 auto;padding:2rem 1rem;">

    
    <div style="text-align:center;margin-bottom:2rem;">
        <svg viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false" style="width:70px;height:70px;margin:0 auto 1rem;">
            <defs>
                <linearGradient id="rg" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" style="stop-color:#c9a227"/>
                    <stop offset="50%" style="stop-color:#f0c040"/>
                    <stop offset="100%" style="stop-color:#0d9488"/>
                </linearGradient>
                <linearGradient id="ig" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" style="stop-color:#0f766e"/>
                    <stop offset="100%" style="stop-color:#0d9488"/>
                </linearGradient>
            </defs>
            <circle cx="100" cy="100" r="96" fill="url(#rg)"/>
            <circle cx="100" cy="100" r="80" fill="url(#ig)"/>
            <circle cx="100" cy="100" r="68" fill="white" fill-opacity="0.12"/>
            <text x="100" y="92" text-anchor="middle" font-family="Georgia,serif" font-size="26" fill="white" font-weight="bold">بِسْمِ اللَّهِ</text>
            <line x1="55" y1="102" x2="145" y2="102" stroke="rgba(255,255,255,0.4)" stroke-width="1"/>
            <text x="100" y="118" text-anchor="middle" font-family="sans-serif" font-size="12" fill="white" letter-spacing="1">KOPERASI</text>
            <text x="100" y="133" text-anchor="middle" font-family="sans-serif" font-size="12" fill="#f0c040" font-weight="bold" letter-spacing="1">SYARIAH</text>
            <text x="100" y="150" text-anchor="middle" font-family="sans-serif" font-size="8" fill="rgba(255,255,255,0.7)" letter-spacing="0.5">BANGKA BELITUNG</text>
        </svg>
        <h1 style="color:white;font-size:1.5rem;margin:0 0 0.5rem;">Pembukaan Rekening Simpanan</h1>
        <p style="color:#94a3b8;margin:0;">Isi formulir di bawah untuk mengajukan pembukaan rekening baru</p>
    </div>

    
    <?php if(session('success')): ?>
    <div role="status" aria-live="polite" style="background:rgba(16,185,129,0.15);border:1px solid #10b981;color:#10b981;
                padding:1rem 1.25rem;border-radius:10px;margin-bottom:1.5rem;text-align:center;">
        <div aria-hidden="true" style="font-size:1.5rem;margin-bottom:0.5rem;">✓</div>
        <strong>Pengajuan Berhasil Dikirim!</strong><br>
        <span style="font-size:0.9rem;"><?php echo e(session('success')); ?></span><br>
        <?php if(session('kode')): ?>
        <div style="margin-top:0.75rem;background:rgba(0,0,0,0.2);border-radius:8px;padding:0.5rem;">
            Kode Pengajuan: <strong style="font-family:monospace;font-size:1.1rem;color:white;"><?php echo e(session('kode')); ?></strong>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    
    <?php if($errors->any()): ?>
    <div role="alert" style="background:rgba(239,68,68,0.15);border:1px solid #ef4444;color:#ef4444;
                padding:1rem 1.25rem;border-radius:10px;margin-bottom:1.5rem;">
        <strong>Harap periksa isian berikut:</strong>
        <ul style="margin:0.5rem 0 0;padding-left:1.25rem;">
            <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $err): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <li style="font-size:0.85rem;"><?php echo e($err); ?></li>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </ul>
    </div>
    <?php endif; ?>

    <form action="<?php echo e(route('pengajuan-rekening.publik.store')); ?>" method="POST" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>

        
        <div class="form-card">
            <h3 class="form-section-title">Data Diri</h3>
            <div class="form-grid">
                <div class="form-group full">
                    <label for="nama_lengkap">Nama Lengkap <span class="required" aria-hidden="true">*</span></label>
                    <input type="text" id="nama_lengkap" name="nama_lengkap" value="<?php echo e(old('nama_lengkap')); ?>"
                           placeholder="Sesuai e-KTP" required />
                </div>
                <div class="form-group">
                    <label for="nik">NIK (16 digit) <span class="required" aria-hidden="true">*</span></label>
                    <input type="text" id="nik" name="nik" value="<?php echo e(old('nik')); ?>"
                           placeholder="Nomor Induk Kependudukan" maxlength="16" required />
                </div>
                <div class="form-group">
                    <label for="no_hp">No. HP <span class="required" aria-hidden="true">*</span></label>
                    <input type="text" id="no_hp" name="no_hp" value="<?php echo e(old('no_hp')); ?>"
                           placeholder="Contoh: 08123456789" required />
                </div>
                <div class="form-group">
                    <label for="tempat_lahir">Tempat Lahir</label>
                    <input type="text" id="tempat_lahir" name="tempat_lahir" value="<?php echo e(old('tempat_lahir')); ?>"
                           placeholder="Kota tempat lahir" />
                </div>
                <div class="form-group">
                    <label for="tanggal_lahir">Tanggal Lahir</label>
                    <input type="date" id="tanggal_lahir" name="tanggal_lahir" value="<?php echo e(old('tanggal_lahir')); ?>" />
                </div>
                <div class="form-group">
                    <label for="jenis_kelamin">Jenis Kelamin <span class="required" aria-hidden="true">*</span></label>
                    <select id="jenis_kelamin" name="jenis_kelamin" required>
                        <option value="">-- Pilih --</option>
                        <option value="Laki-laki" <?php echo e(old('jenis_kelamin')==='Laki-laki' ? 'selected' : ''); ?>>Laki-laki</option>
                        <option value="Perempuan" <?php echo e(old('jenis_kelamin')==='Perempuan' ? 'selected' : ''); ?>>Perempuan</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?php echo e(old('email')); ?>"
                           placeholder="email@contoh.com" />
                </div>
                <div class="form-group">
                    <label for="pekerjaan">Pekerjaan</label>
                    <input type="text" id="pekerjaan" name="pekerjaan" value="<?php echo e(old('pekerjaan')); ?>"
                           placeholder="Pekerjaan saat ini" />
                </div>
                <div class="form-group full">
                    <label for="alamat">Alamat Lengkap <span class="required" aria-hidden="true">*</span></label>
                    <textarea id="alamat" name="alamat" rows="3" placeholder="Alamat sesuai e-KTP" required><?php echo e(old('alamat')); ?></textarea>
                </div>
                <div class="form-group">
                    <label for="kota">Kota</label>
                    <input type="text" id="kota" name="kota" value="<?php echo e(old('kota')); ?>" placeholder="Kota/Kabupaten" />
                </div>
                <div class="form-group">
                    <label for="provinsi">Provinsi</label>
                    <input type="text" id="provinsi" name="provinsi" value="<?php echo e(old('provinsi')); ?>" placeholder="Provinsi" />
                </div>
            </div>
        </div>

        
        <div class="form-card">
            <h3 class="form-section-title">Jenis Simpanan</h3>
            <fieldset style="border:none;padding:0;margin:0;">
                <legend class="form-section-title" style="padding:0 0 0.5rem;">Jenis Simpanan</legend>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;">
                    <?php $__currentLoopData = ['Simpanan Pokok','Simpanan Wajib','Simpanan Sukarela']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $jenis): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <label class="simpanan-option <?php echo e(old('jenis_simpanan',$jenis==='Simpanan Pokok'?'Simpanan Pokok':'') === $jenis ? 'selected' : ''); ?>">
                        <input type="radio" name="jenis_simpanan" value="<?php echo e($jenis); ?>"
                               <?php echo e(old('jenis_simpanan','Simpanan Pokok') === $jenis ? 'checked' : ''); ?>

                               class="sr-only" onfocus="this.closest('label').classList.add('fokus');"
                               onblur="this.closest('label').classList.remove('fokus');"
                               onchange="this.closest('label').classList.add('selected');
                                      document.querySelectorAll('.simpanan-option').forEach(el=>{ if(el!==this.closest('label')) el.classList.remove('selected'); })">
                        <div aria-hidden="true" style="font-size:1.5rem;margin-bottom:0.4rem;">
                            <?php if($jenis==='Simpanan Pokok'): ?> 🏦
                            <?php elseif($jenis==='Simpanan Wajib'): ?> 📅
                            <?php else: ?> 💰
                            <?php endif; ?>
                        </div>
                        <div style="font-weight:600;font-size:0.9rem;"><?php echo e($jenis); ?></div>
                    </label>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </div>
            </fieldset>
        </div>

        
        <div class="form-card">
            <h3 class="form-section-title">Deklarasi Kepatuhan (APU-PPT)</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label for="pep_status">Status PEP <span class="required" aria-hidden="true">*</span></label>
                    <select id="pep_status" name="pep_status" required>
                        <option value="" disabled <?php echo e(old('pep_status') ? '' : 'selected'); ?>>— Pilih —</option>
                        <?php $__currentLoopData = \App\Models\Anggota::PEP_PILIHAN; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $pep): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($pep); ?>" <?php echo e(old('pep_status') === $pep ? 'selected' : ''); ?>><?php echo e($pep); ?></option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </select>
                    <small style="color:#94a3b8;">Politically Exposed Person: pejabat publik/penyedia jasa keuangan.</small>
                </div>
                <div class="form-group">
                    <label for="pep_keterangan">Keterangan PEP / Jabatan</label>
                    <input type="text" id="pep_keterangan" name="pep_keterangan" value="<?php echo e(old('pep_keterangan')); ?>"
                           placeholder="Jabatan/instansi (bila PEP)" />
                </div>
                <div class="form-group">
                    <label for="pemilik_manfaat_nama">Nama Pemilik Manfaat</label>
                    <input type="text" id="pemilik_manfaat_nama" name="pemilik_manfaat_nama" value="<?php echo e(old('pemilik_manfaat_nama')); ?>"
                           placeholder="Nama pemilik manfaat (opsional)" />
                </div>
                <div class="form-group">
                    <label for="pemilik_manfaat_hubungan">Hubungan Pemilik Manfaat</label>
                    <input type="text" id="pemilik_manfaat_hubungan" name="pemilik_manfaat_hubungan" value="<?php echo e(old('pemilik_manfaat_hubungan')); ?>"
                           placeholder="cth: Pemilik perusahaan" />
                </div>
            </div>
        </div>

        
        <div class="form-card">
            <h3 class="form-section-title">Upload Dokumen</h3>
                    <input type="file" id="foto_ktp" name="foto_ktp" accept="image/*" required />
                    <small id="petunjuk_foto_ktp" style="color:#64748b;">Format: JPG/PNG, maks. 5MB</small>
                </div>
                <div class="form-group">
                    <label for="foto_selfie_ktp">Foto Selfie + KTP <span class="required" aria-hidden="true">*</span></label>
                    <input type="file" id="foto_selfie_ktp" name="foto_selfie_ktp" accept="image/*" required />
                    <small id="petunjuk_foto_selfie" style="color:#64748b;">Foto wajah sambil memegang KTP</small>
                </div>
            </div>
        </div>

        
        <div style="text-align:center;margin-top:1.5rem;">
            <button type="submit" class="btn-submit">
                Kirim Pengajuan Rekening
            </button>
            <div style="margin-top:1rem;">
                <a href="<?php echo e(url('/')); ?>" style="color:#94a3b8;font-size:0.85rem;text-decoration:none;">
                    <span aria-hidden="true">←</span> Kembali ke Halaman Utama
                </a>
            </div>
        </div>
    </form>

</div>

<style>
body { background: linear-gradient(135deg,#0f172a 0%,#134e4a 100%); min-height:100vh; }
.form-card {
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.25rem;
}
.form-section-title {
    color: #0d9488;
    font-size: 0.95rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin: 0 0 1.25rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid rgba(13,148,136,0.3);
}
.form-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(240px,1fr)); gap: 1rem; }
.form-group { display: flex; flex-direction: column; gap: 0.4rem; }
.form-group.full { grid-column: 1 / -1; }
.form-group label { color: #cbd5e1; font-size: 0.85rem; font-weight: 500; }
.required { color: #ef4444; }
.form-group input,
.form-group select,
.form-group textarea {
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: 8px;
    padding: 0.6rem 0.75rem;
    color: white;
    font-size: 0.9rem;
    transition: border-color 0.2s;
}
/* WCAG 2.4.7 — fokus terlihat jelas, jangan hapus outline default. */
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    border-color: #0d9488;
    outline: 3px solid #f0c040;
    outline-offset: 2px;
}
.form-group select option { background: #1e293b; }
.simpanan-option {
    background: rgba(255,255,255,0.05);
    border: 2px solid rgba(255,255,255,0.1);
    border-radius: 10px;
    padding: 1rem;
    text-align: center;
    cursor: pointer;
    color: white;
    transition: all 0.2s;
}
.simpanan-option:hover,
.simpanan-option.selected {
    border-color: #0d9488;
    background: rgba(13,148,136,0.15);
}
/* Aksesibilitas: kartu pilihan menampilkan fokus saat radio di dalamnya difokus (WCAG 2.4.7) */
.simpanan-option.fokus {
    border-color: #f0c040;
    box-shadow: 0 0 0 3px rgba(240,192,64,0.5);
}
.sr-only {
    position: absolute;
    width: 1px; height: 1px;
    padding: 0; margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: 0.001ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.001ms !important;
    }
    .btn-submit:hover { transform: none; }
}
.btn-submit {
    background: linear-gradient(135deg, #0d9488, #0f766e);
    color: white;
    border: none;
    border-radius: 10px;
    padding: 0.9rem 3rem;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    transition: transform 0.2s, box-shadow 0.2s;
    letter-spacing: 0.5px;
}
.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(13,148,136,0.4);
}
@media (max-width: 600px) {
    .form-grid { grid-template-columns: 1fr; }
    .form-group.full { grid-column: 1; }
    div[style*="grid-template-columns:repeat(3"] { grid-template-columns: 1fr !important; }
}
</style>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.publik', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/pengajuan/form.blade.php ENDPATH**/ ?>