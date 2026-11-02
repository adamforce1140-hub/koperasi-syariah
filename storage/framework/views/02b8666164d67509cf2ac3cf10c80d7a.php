<?php $__env->startSection('title', 'Dana Zakat, Infak & Sedekah — PSAK 109'); ?>
<?php $__env->startSection('page-title', 'Dana Zakat, Infak & Sedekah (PSAK 109)'); ?>

<?php $__env->startSection('content'); ?>

<?php
    // DPS mengakses halaman ini lewat jalur pengawasan (read-only) dengan nama route berbeda.
    $routeLaporan = $readOnly ? 'dps.zakat.laporan' : 'zakat.laporan';
?>

<?php if(session('success')): ?>
    <div class="alert" style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:.8rem 1.1rem;border-radius:8px;margin-bottom:1rem;"><?php echo e(session('success')); ?></div>
<?php endif; ?>
<?php if(session('error')): ?>
    <div class="alert" style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.8rem 1.1rem;border-radius:8px;margin-bottom:1rem;"><?php echo e(session('error')); ?></div>
<?php endif; ?>
<?php if(session('info')): ?>
    <div class="alert" style="background:#dbeafe;border:1px solid #93c5fd;color:#1e40af;padding:.8rem 1.1rem;border-radius:8px;margin-bottom:1rem;"><?php echo e(session('info')); ?></div>
<?php endif; ?>


<div class="card" style="background:#fffbeb;border:1px solid #fde68a;">
    <div style="font-size:.85rem;color:#92400e;line-height:1.6;">
        <strong>PSAK 109 &amp; QS At-Taubah 60.</strong>
        Dana zakat serta infak/sedekah merupakan <strong>dana titipan (amanah)</strong> — bukan pendapatan koperasi
        dan tidak diperhitungkan dalam SHU. Dana zakat hanya boleh disalurkan kepada <strong>8 asnaf</strong>.
        Saldo pada halaman ini dihitung dari mutasi jurnal akun
        <code><?php echo e(\App\Support\Coa::DANA_ZAKAT); ?></code> dan <code><?php echo e(\App\Support\Coa::DANA_INFAK_SEDEKAH); ?></code>.
    </div>
</div>


<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon green">🕌</div>
        <div>
            <div class="stat-value">Rp <?php echo e(number_format($ringkasan['zakat']['saldoAkhir'] ?? 0, 2, ',', '.')); ?></div>
            <div class="stat-label">Saldo Dana Zakat</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">🤲</div>
        <div>
            <div class="stat-value">Rp <?php echo e(number_format($ringkasan['infak_sedekah']['saldoAkhir'] ?? 0, 2, ',', '.')); ?></div>
            <div class="stat-label">Saldo Dana Infak &amp; Sedekah</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple">📥</div>
        <div>
            <div class="stat-value">
                Rp <?php echo e(number_format(($ringkasan['zakat']['penerimaan'] ?? 0) + ($ringkasan['infak_sedekah']['penerimaan'] ?? 0), 2, ',', '.')); ?>

            </div>
            <div class="stat-label">Penerimaan Periode</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon amber">📤</div>
        <div>
            <div class="stat-value">
                Rp <?php echo e(number_format(($ringkasan['zakat']['penyaluran'] ?? 0) + ($ringkasan['infak_sedekah']['penyaluran'] ?? 0), 2, ',', '.')); ?>

            </div>
            <div class="stat-label">Penyaluran Periode</div>
        </div>
    </div>
</div>


<?php if(! $validasi['valid']): ?>
    <div class="card" style="background:#fef2f2;border:1px solid #fca5a5;">
        <div class="card-title" style="color:#991b1b;">⚠️ Temuan Validasi Integritas</div>
        <ul style="margin:.6rem 0 0 1.2rem;font-size:.85rem;color:#991b1b;line-height:1.7;">
            <?php $__currentLoopData = $validasi['catatan']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $catatan): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <li><?php echo e($catatan); ?></li>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </ul>
    </div>
<?php endif; ?>


<div class="card">
    <form method="GET" style="display:flex;gap:1rem;align-items:flex-end;flex-wrap:wrap;">
        <div>
            <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Dari Tanggal</label>
            <input type="date" name="dari" value="<?php echo e($dari); ?>" style="padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
        </div>
        <div>
            <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Sampai Tanggal</label>
            <input type="date" name="sampai" value="<?php echo e($sampai); ?>" style="padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
        </div>
        <button type="submit" class="btn btn-primary">Terapkan</button>
        <a href="<?php echo e(route($routeLaporan, ['dari' => $dari, 'sampai' => $sampai])); ?>" class="btn" style="background:#f1f5f9;color:#334155;">
            Laporan Sumber &amp; Penggunaan Dana
        </a>
    </form>
</div>

<?php if (! ($readOnly)): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1.5rem;">
    
    <div class="card">
        <div class="card-header"><div class="card-title">📥 Catat Penerimaan Dana</div></div>
        <form method="POST" action="<?php echo e(route('zakat.store')); ?>">
            <?php echo csrf_field(); ?>
            <div style="display:grid;gap:.9rem;">
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Jenis Dana</label>
                    <select name="dana" id="terima-dana" required style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                        <option value="zakat">Zakat</option>
                        <option value="infak_sedekah">Infak &amp; Sedekah</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Kategori / Jenis Zakat</label>
                    <select name="kategori" id="terima-kategori" required style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                        <?php $__currentLoopData = $kategoriTerima; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $kode => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="<?php echo e($kode); ?>"><?php echo e($label); ?></option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Muzakki / Munfiq</label>
                    <input type="text" name="pihak" id="terima-pihak" maxlength="150" placeholder="Nama pihak (boleh kosong bila memilih anggota)" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                </div>
                
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Atau Pilih Anggota (opsional)</label>
                    <select name="pihak_id" id="terima-pihak-id" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                        <option value="">— Pihak eksternal (isi nama di atas) —</option>
                        <?php $__currentLoopData = $anggotaList; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $a): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="<?php echo e($a->id); ?>"><?php echo e($a->nama_lengkap); ?> (<?php echo e($a->no_anggota); ?>)</option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </select>
                    <div style="font-size:.7rem;color:#94a3b8;margin-top:.25rem;">Bila anggota dipilih, nama teks boleh dikosongkan.</div>
                </div>
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Jumlah (Rp)</label>
                    <input type="number" name="jumlah" id="terima-jumlah" required min="0.01" step="0.01" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                </div>
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Kas / Bank Penerimaan</label>
                    <select name="kode_kas" id="terima-kode-kas" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                        <?php $__empty_1 = true; $__currentLoopData = $akunKas; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $akun): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                            <option value="<?php echo e($akun->kode_akun); ?>" <?php if($akun->kode_akun === $kodeKasDefault): echo 'selected'; endif; ?>>
                                <?php echo e($akun->kode_akun); ?> — <?php echo e($akun->nama_akun); ?>

                            </option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                            <option value="<?php echo e($kodeKasDefault); ?>"><?php echo e($kodeKasDefault); ?> — Kas Teller (default)</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Tanggal</label>
                    <input type="date" name="tanggal" required value="<?php echo e(now()->toDateString()); ?>" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                </div>
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Keterangan</label>
                    <input type="text" name="keterangan" maxlength="500" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                </div>
                <button type="submit" class="btn btn-primary">Posting Penerimaan</button>
            </div>
        </form>
    </div>

    
    <div class="card">
        <div class="card-header"><div class="card-title">📤 Catat Penyaluran Dana</div></div>
        <form method="POST" action="<?php echo e(route('zakat.salurkan')); ?>">
            <?php echo csrf_field(); ?>
            <div style="display:grid;gap:.9rem;">
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Jenis Dana</label>
                    <select name="dana" required style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                        <option value="zakat">Zakat</option>
                        <option value="infak_sedekah">Infak &amp; Sedekah</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Golongan Penerima</label>
                    <select name="kategori" required style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                        <optgroup label="8 Asnaf (zakat)">
                            <?php $__currentLoopData = $asnaf; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $kode => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <option value="<?php echo e($kode); ?>"><?php echo e($label); ?></option>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </optgroup>
                        <optgroup label="Program Sosial (infak/sedekah)">
                            <?php $__currentLoopData = $kategoriSalur; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $kode => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <?php if(array_key_exists($kode, $asnaf)) continue; ?>
                                <option value="<?php echo e($kode); ?>"><?php echo e($label); ?></option>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </optgroup>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Mustahik / Penerima</label>
                    <input type="text" name="pihak" id="salur-pihak" maxlength="150" placeholder="Nama pihak (boleh kosong bila memilih anggota)" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                </div>
                
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Atau Pilih Anggota (opsional)</label>
                    <select name="pihak_id" id="salur-pihak-id" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                        <option value="">— Pihak eksternal (isi nama di atas) —</option>
                        <?php $__currentLoopData = $anggotaList; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $a): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="<?php echo e($a->id); ?>"><?php echo e($a->nama_lengkap); ?> (<?php echo e($a->no_anggota); ?>)</option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </select>
                    <div style="font-size:.7rem;color:#94a3b8;margin-top:.25rem;">Bila anggota dipilih, nama teks boleh dikosongkan.</div>
                </div>
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Jumlah (Rp)</label>
                    <input type="number" name="jumlah" required min="0.01" step="0.01" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                </div>
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Kas / Bank Penyaluran</label>
                    <select name="kode_kas" id="salur-kode-kas" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                        <?php $__empty_1 = true; $__currentLoopData = $akunKas; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $akun): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                            <option value="<?php echo e($akun->kode_akun); ?>" <?php if($akun->kode_akun === $kodeKasDefault): echo 'selected'; endif; ?>>
                                <?php echo e($akun->kode_akun); ?> — <?php echo e($akun->nama_akun); ?>

                            </option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                            <option value="<?php echo e($kodeKasDefault); ?>"><?php echo e($kodeKasDefault); ?> — Kas Teller (default)</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Tanggal</label>
                    <input type="date" name="tanggal" required value="<?php echo e(now()->toDateString()); ?>" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                </div>
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Keterangan</label>
                    <input type="text" name="keterangan" maxlength="500" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                </div>
                <button type="submit" class="btn btn-primary">Posting Penyaluran</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>


<?php if (! ($readOnly)): ?>
<div class="card">
    <div class="card-header"><div class="card-title">🧮 Kalkulator Zakat Penghasilan (Pra-isi Penerimaan)</div></div>
    <p style="font-size:.8rem;color:#64748b;margin:0 0 1rem;">
        Zakat penghasilan = <strong><?php echo e(rtrim(rtrim(number_format(\App\Support\Setting::zakatRate(), 2, ',', '.'), '0'), ',')); ?>%</strong>
        dari penghasilan neto (bruto − kebutuhan pokok) yang telah mencapai nishab bulanan
        <strong>Rp <?php echo e(number_format(round(\App\Support\Setting::zakatNisabNominal() / 12, 2), 2, ',', '.')); ?></strong>
        (nishab <?php echo e(rtrim(rtrim(number_format(\App\Support\Setting::zakatNisabGram(), 2, ',', '.'), '0'), ',')); ?> gram emas ÷ 12 bulan).
        Hasil hanya <strong>mengisi</strong> form penerimaan — posting tetap dilakukan manual oleh Pengurus.
    </p>
    <form id="form-hisab-penghasilan" onsubmit="return false;" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end;">
        <div>
            <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Penghasilan Bruto Bulanan (Rp)</label>
            <input type="number" id="penghasilan-bruto" min="0" step="0.01" style="padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
        </div>
        <div>
            <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Kebutuhan Pokok Bulanan (Rp)</label>
            <input type="number" id="penghasilan-kebutuhan" min="0" step="0.01" value="0" style="padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
        </div>
        <button type="button" class="btn btn-primary" onclick="hitungZakatPenghasilan()">Hitung &amp; Isi Form</button>
    </form>
    <div id="penghasilan-hasil" style="display:none;margin-top:1rem;padding:.9rem 1.1rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.85rem;line-height:1.8;"></div>
</div>
<script>
    async function hitungZakatPenghasilan() {
        const bruto    = document.getElementById('penghasilan-bruto').value;
        const kebutuhan = document.getElementById('penghasilan-kebutuhan').value || '0';
        const box      = document.getElementById('penghasilan-hasil');

        if (bruto === '') {
            box.style.display = 'block';
            box.style.background = '#fee2e2';
            box.style.borderColor = '#fca5a5';
            box.textContent = 'Masukkan penghasilan bruto terlebih dahulu.';
            return;
        }

        const rp = (n) => 'Rp ' + Number(n).toLocaleString('id-ID', { minimumFractionDigits: 2 });

        try {
            const resp = await fetch('<?php echo e(route('zakat.hitung.penghasilan')); ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '<?php echo e(csrf_token()); ?>',
                },
                body: JSON.stringify({ bruto: bruto, kebutuhan_pokok: kebutuhan }),
            });
            const data = await resp.json();

            const rincian = '<div>Penghasilan neto: <strong>' + rp(data.penghasilan_neto) + '</strong></div>'
                          + '<div>Nishab bulanan: <strong>' + rp(data.nishab_bulanan) + '</strong></div>'
                          + '<div>Kadar: <strong>' + data.kadar_persen + '%</strong></div>'
                          + '<div>Zakat penghasilan: <strong>' + rp(data.zakat) + '</strong></div>';

            box.style.display = 'block';
            box.style.background = data.kena_zakat ? '#d1fae5' : '#fef9c3';
            box.style.borderColor = data.kena_zakat ? '#6ee7b7' : '#fde68a';
            box.innerHTML = '<div style="font-weight:600;margin-bottom:.4rem;">' + data.pesan + '</div>' + rincian;

            // Pra-isi form penerimaan (posting tetap manual oleh Pengurus).
            if (data.kena_zakat) {
                document.getElementById('terima-dana').value = 'zakat';
                document.getElementById('terima-kategori').value = 'zakat_penghasilan';
                document.getElementById('terima-jumlah').value = data.zakat;
            }
        } catch (e) {
            box.style.display = 'block';
            box.style.background = '#fee2e2';
            box.style.borderColor = '#fca5a5';
            box.textContent = 'Gagal menghitung zakat penghasilan. Silakan coba lagi.';
        }
    }
</script>
<?php endif; ?>


<?php if (! ($readOnly)): ?>
<div class="card">
    <div class="card-header"><div class="card-title">🧮 Kalkulator Hisab Zakat Maal</div></div>
    <p style="font-size:.8rem;color:#64748b;margin:0 0 1rem;">
        Nishab dihitung dari <?php echo e(rtrim(rtrim(number_format(\App\Support\Setting::zakatNisabGram(), 2, ',', '.'), '0'), ',')); ?> gram emas
        × Rp <?php echo e(number_format(\App\Support\Setting::hargaEmasPerGram(), 0, ',', '.')); ?>/gram.
        <?php if(\App\Support\Setting::zakatHaulAktif()): ?>
            Syarat <strong>haul</strong> aktif: harta baru wajib zakat setelah dimiliki genap
            <?php echo e(\App\Support\Setting::zakatHaulHari()); ?> hari.
        <?php else: ?>
            Syarat <strong>haul</strong> saat ini nonaktif (hisab berbasis nishab saja).
        <?php endif; ?>
    </p>
    <form id="form-hisab-zakat" onsubmit="return false;" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end;">
        <div>
            <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Total Harta (Rp)</label>
            <input type="number" id="hisab-harta" min="0" step="0.01" style="padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
        </div>
        <div>
            <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">
                Tanggal Mulai Kepemilikan<?php echo e(\App\Support\Setting::zakatHaulAktif() ? ' (wajib)' : ' (opsional)'); ?>

            </label>
            <input type="date" id="hisab-tanggal" style="padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
        </div>
        <button type="button" class="btn btn-primary" onclick="hitungZakat()">Hitung Zakat</button>
    </form>
    <div id="hisab-hasil" style="display:none;margin-top:1rem;padding:.9rem 1.1rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.85rem;line-height:1.8;"></div>
</div>
<script>
    async function hitungZakat() {
        const harta   = document.getElementById('hisab-harta').value;
        const tanggal = document.getElementById('hisab-tanggal').value;
        const box     = document.getElementById('hisab-hasil');

        if (harta === '') {
            box.style.display = 'block';
            box.style.background = '#fee2e2';
            box.style.borderColor = '#fca5a5';
            box.textContent = 'Masukkan total harta terlebih dahulu.';
            return;
        }

        const rp = (n) => 'Rp ' + Number(n).toLocaleString('id-ID', { minimumFractionDigits: 2 });

        try {
            const resp = await fetch('<?php echo e(route('zakat.hitung')); ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '<?php echo e(csrf_token()); ?>',
                },
                body: JSON.stringify({ harta: harta, tanggal_kepemilikan: tanggal || null }),
            });
            const data = await resp.json();

            let rincian = '<div>Nishab: <strong>' + rp(data.nishab) + '</strong></div>'
                        + '<div>Kadar: <strong>' + data.kadar_persen + '%</strong></div>';
            if (data.haul_aktif) {
                rincian += '<div>Haul: <strong>' + (data.haul_terpenuhi ? 'terpenuhi' : 'belum terpenuhi') + '</strong>'
                         + (data.hari_kepemilikan !== null ? ' (' + data.hari_kepemilikan + ' dari ' + data.haul_hari + ' hari)' : '') + '</div>';
            }
            rincian += '<div>Zakat terutang: <strong>' + rp(data.zakat) + '</strong></div>';

            box.style.display = 'block';
            box.style.background = data.kena_zakat ? '#d1fae5' : '#fef9c3';
            box.style.borderColor = data.kena_zakat ? '#6ee7b7' : '#fde68a';
            box.innerHTML = '<div style="font-weight:600;margin-bottom:.4rem;">' + data.pesan + '</div>' + rincian;
        } catch (e) {
            box.style.display = 'block';
            box.style.background = '#fee2e2';
            box.style.borderColor = '#fca5a5';
            box.textContent = 'Gagal menghitung zakat. Silakan coba lagi.';
        }
    }
</script>
<?php endif; ?>


<div class="card">
    <div class="card-header">
        <div class="card-title">Buku Besar Transaksi (<?php echo e($dari); ?> s/d <?php echo e($sampai); ?>)</div>
        <span class="badge badge-gray"><?php echo e($transaksi->total()); ?> baris</span>
    </div>
    <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>No. Referensi</th>
                    <th>Tanggal</th>
                    <th>Dana</th>
                    <th>Jenis</th>
                    <th>Kategori</th>
                    <th>Pihak</th>
                    <th style="text-align:right;">Jumlah</th>
                    <th>Jurnal</th>
                    <th>Status</th>
                    <?php if (! ($readOnly)): ?><th>Aksi</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php $__empty_1 = true; $__currentLoopData = $transaksi; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $t): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <tr <?php if($t->sudah_void): ?> style="opacity:.6;" <?php endif; ?>>
                        <td style="font-family:monospace;font-size:.8rem;"><?php echo e($t->no_referensi); ?></td>
                        <td><?php echo e($t->tanggal?->format('d/m/Y')); ?></td>
                        <td>
                            <span class="badge <?php echo e($t->dana === \App\Models\ZakatTransaksi::DANA_ZAKAT ? 'badge-green' : 'badge-blue'); ?>">
                                <?php echo e($t->dana_label); ?>

                            </span>
                        </td>
                        <td>
                            <span class="badge <?php echo e($t->jenis === \App\Models\ZakatTransaksi::JENIS_PENERIMAAN ? 'badge-teal' : 'badge-amber'); ?>">
                                <?php echo e($t->jenis_label); ?>

                            </span>
                        </td>
                        <td><?php echo e($t->kategori_label); ?></td>
                        <td>
                            <?php echo e($t->pihak_tampil); ?>

                            <?php if($t->tertaut_anggota): ?>
                                <span class="badge badge-blue" title="Tertaut ke master anggota">anggota</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;font-variant-numeric:tabular-nums;">
                            Rp <?php echo e(number_format((float) $t->jumlah, 2, ',', '.')); ?>

                        </td>
                        <td>
                            <?php if($t->jurnal_id): ?>
                                <span class="badge badge-green">#<?php echo e($t->jurnal_id); ?></span>
                            <?php elseif($t->menunggu_persetujuan): ?>
                                <span class="badge badge-amber">belum diposting</span>
                            <?php else: ?>
                                <span class="badge badge-red">tanpa jurnal</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($t->sudah_void): ?>
                                <span class="badge badge-red" title="<?php echo e($t->void_reason ?: 'Dibatalkan'); ?>">VOID</span>
                            <?php elseif($t->menunggu_persetujuan): ?>
                                <span class="badge badge-amber" title="Menunggu persetujuan pengurus/manajer lain (dual control)">Menunggu Persetujuan</span>
                            <?php elseif($t->ditolak): ?>
                                <span class="badge badge-red" title="<?php echo e($t->alasan_tolak ?: 'Ditolak'); ?>">Ditolak</span>
                            <?php else: ?>
                                <span class="badge badge-green">Berlaku</span>
                            <?php endif; ?>
                        </td>
                        <?php if (! ($readOnly)): ?>
                        <td>
                            <?php if($t->menunggu_persetujuan): ?>
                                <form method="POST" action="<?php echo e(route('zakat.setujui', $t->id)); ?>"
                                      onsubmit="return confirm('Setujui penyaluran <?php echo e($t->no_referensi); ?>? Jurnal penyaluran akan diposting.');"
                                      style="display:inline;">
                                    <?php echo csrf_field(); ?>
                                    <button type="submit" class="btn" style="background:#d1fae5;color:#065f46;padding:.25rem .6rem;font-size:.75rem;">Setujui</button>
                                </form>
                                <form method="POST" action="<?php echo e(route('zakat.tolak', $t->id)); ?>"
                                      onsubmit="return confirm('Tolak pengajuan penyaluran <?php echo e($t->no_referensi); ?>?');"
                                      style="display:inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="alasan" value="Ditolak oleh <?php echo e(auth()->user()->name); ?>">
                                    <button type="submit" class="btn" style="background:#fee2e2;color:#991b1b;padding:.25rem .6rem;font-size:.75rem;">Tolak</button>
                                </form>
                            <?php elseif(! $t->sudah_void && ! $t->ditolak): ?>
                                <form method="POST" action="<?php echo e(route('zakat.batalkan', $t->id)); ?>"
                                      onsubmit="return confirm('Batalkan transaksi <?php echo e($t->no_referensi); ?>? Jurnal akan dibalik dan baris ditandai void (TIDAK dihapus).');"
                                      style="display:inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="alasan" value="Dibatalkan oleh <?php echo e(auth()->user()->name); ?>">
                                    <button type="submit" class="btn" style="background:#fee2e2;color:#991b1b;padding:.25rem .6rem;font-size:.75rem;">Batalkan</button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <tr><td colspan="<?php echo e($readOnly ? 9 : 10); ?>" style="text-align:center;color:#94a3b8;padding:2rem;">Belum ada transaksi pada periode ini.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if($transaksi->hasPages()): ?>
    <div style="padding:1rem 1.25rem;border-top:1px solid #f1f5f9;">
        <?php echo e($transaksi->links()); ?>

    </div>
    <?php endif; ?>
</div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/zakat/index.blade.php ENDPATH**/ ?>