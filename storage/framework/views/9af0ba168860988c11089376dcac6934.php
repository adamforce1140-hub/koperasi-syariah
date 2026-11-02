
<?php
    $daftarDeposito = $deposito ?? collect();

    // Saat partial ini dimuat ulang via AJAX (refresh parsial setelah membuka
    // deposito), script cetak bilyet tidak perlu disertakan karena event
    // delegation-nya sudah terpasang di halaman induk.
    $sertakanScript = $sertakanScript ?? true;

    // SEGREGATION OF DUTIES — pencairan dini hanya kewenangan TELLER.
    // Partial ini dipakai bersama halaman Portofolio CIF semua peran
    // (ao/teller/manajer/pengurus), sehingga kontrol peran wajib ada di sini:
    // tombol, modal konfirmasi, dan skrip pemanggil endpoint tidak dirender
    // untuk peran selain teller. Middleware route `role:teller` pada
    // POST /api/deposito/{id}/early-withdrawal tetap menjadi penjaga utama.
    $bolehPencairanDini = \App\Support\HierarkiRole::dalamKelompok(auth()->user()?->role, 'pencairan_dini');
?>

<div id="deposito-bilyet-card"
     style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;
            box-shadow:0 1px 3px rgba(0,0,0,.05);overflow:hidden;">
    <div style="padding:1rem 1.25rem;border-bottom:1px solid #f1f5f9;
                display:flex;align-items:center;justify-content:space-between;">
        <div style="font-size:0.85rem;font-weight:700;color:#1e293b;">Simpanan Berjangka (Deposito)</div>
        <div style="font-size:0.72rem;color:#94a3b8;">Bilyet digital &mdash; akad Mudharabah Muthlaqah</div>
    </div>

    
    <div id="flash-bilyet" style="display:none;margin:0.75rem 1.25rem 0;background:#fee2e2;
                border:1px solid #fca5a5;border-radius:0.5rem;padding:0.65rem 0.9rem;
                font-size:0.78rem;color:#991b1b;"></div>

    <?php if($daftarDeposito->count() > 0): ?>
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                    <th style="padding:0.65rem 1.25rem;text-align:left;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">No Bilyet</th>
                    <th style="padding:0.65rem 1rem;text-align:left;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Produk</th>
                    <th style="padding:0.65rem 1rem;text-align:right;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Pokok</th>
                    <th style="padding:0.65rem 1rem;text-align:center;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Tenor</th>
                    <th style="padding:0.65rem 1rem;text-align:center;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Nisbah</th>
                    <th style="padding:0.65rem 1rem;text-align:left;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Jatuh Tempo</th>
                    <th style="padding:0.65rem 1rem;text-align:center;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Status</th>
                    <th style="padding:0.65rem 1rem;text-align:center;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php $__currentLoopData = $daftarDeposito; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $dep): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <?php
                    // Bilyet hanya boleh dicetak setelah deposito terverifikasi (ACTIVE).
                    $terverifikasi = $dep->status === \App\Models\Deposito::STATUS_ACTIVE;

                    $formatNisbah = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');

                    $warnaStatus = [
                        \App\Models\Deposito::STATUS_ACTIVE  => ['#d1fae5', '#065f46'],
                        \App\Models\Deposito::STATUS_PENDING => ['#fef3c7', '#92400e'],
                        \App\Models\Deposito::STATUS_MATURED => ['#dbeafe', '#1e40af'],
                        \App\Models\Deposito::STATUS_CLOSED  => ['#e2e8f0', '#475569'],
                    ][$dep->status] ?? ['#e2e8f0', '#475569'];
                ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:0.75rem 1.25rem;font-family:monospace;font-size:0.8rem;color:#1e40af;"><?php echo e($dep->deposito_number); ?></td>
                    <td style="padding:0.75rem 1rem;font-size:0.825rem;color:#1e293b;"><?php echo e($dep->product->name ?? '—'); ?></td>
                    <td style="padding:0.75rem 1rem;text-align:right;font-size:0.825rem;font-weight:600;color:#059669;">
                        Rp <?php echo e(number_format((float) $dep->principal_amount, 0, ',', '.')); ?>

                    </td>
                    <td style="padding:0.75rem 1rem;text-align:center;font-size:0.825rem;color:#475569;"><?php echo e($dep->tenorMonths()); ?> bln</td>
                    <td style="padding:0.75rem 1rem;text-align:center;font-size:0.8rem;color:#475569;">
                        <?php echo e($formatNisbah($dep->nisbah_anggota)); ?>% : <?php echo e($formatNisbah($dep->nisbah_koperasi)); ?>%
                    </td>
                    <td style="padding:0.75rem 1rem;font-size:0.825rem;color:#475569;">
                        <?php echo e(optional($dep->maturity_date)->translatedFormat('d M Y') ?? '—'); ?>

                    </td>
                    <td style="padding:0.75rem 1rem;text-align:center;">
                        <span style="padding:0.2rem 0.6rem;background:<?php echo e($warnaStatus[0]); ?>;color:<?php echo e($warnaStatus[1]); ?>;
                                     font-size:0.75rem;font-weight:600;border-radius:9999px;">
                            <?php echo e($dep->status); ?>

                        </span>
                    </td>
                    <td style="padding:0.75rem 1rem;text-align:center;">
                        <div style="display:inline-flex;gap:0.35rem;flex-wrap:wrap;justify-content:center;">
                        <button type="button"
                                class="btn-bilyet"
                                data-id="<?php echo e($dep->id); ?>"
                                data-nomor="<?php echo e($dep->deposito_number); ?>"
                                <?php if(! $terverifikasi): echo 'disabled'; endif; ?>
                                title="<?php echo e($terverifikasi
                                    ? 'Cetak bilyet deposito dalam format PDF'
                                    : 'Bilyet hanya dapat dicetak setelah deposito terverifikasi (status ACTIVE).'); ?>"
                                style="display:inline-flex;align-items:center;gap:0.35rem;
                                       padding:0.35rem 0.7rem;border-radius:0.5rem;border:1px solid transparent;
                                       font-size:0.75rem;font-weight:600;white-space:nowrap;
                                       <?php echo e($terverifikasi
                                            ? 'background:#0d5c3f;color:#fff;cursor:pointer;'
                                            : 'background:#e2e8f0;color:#94a3b8;cursor:not-allowed;'); ?>">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <polyline points="6 9 6 2 18 2 18 9"></polyline>
                                <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                                <rect x="6" y="14" width="12" height="8"></rect>
                            </svg>
                            Cetak Bilyet PDF
                        </button>
                        <?php if($bolehPencairanDini): ?>
                        <?php $bolehCairDini = $terverifikasi && $dep->product?->allow_early_withdrawal !== false; ?>
                        <button type="button"
                                class="btn-cair-dini"
                                data-id="<?php echo e($dep->id); ?>"
                                data-nomor="<?php echo e($dep->deposito_number); ?>"
                                data-pokok="<?php echo e((float) $dep->principal_amount); ?>"
                                data-penalti-tipe="<?php echo e($dep->product->penalty_fee_type ?? 'fixed'); ?>"
                                data-penalti-nilai="<?php echo e((float) ($dep->product->penalty_fee_amount ?? 0)); ?>"
                                data-penalti-coa="<?php echo e($dep->product?->coaPenalti() ?? \App\Support\Coa::DANA_KEBAJIKAN); ?>"
                                data-forfeit="<?php echo e(($dep->product->forfeit_monthly_profit ?? false) ? '1' : '0'); ?>"
                                <?php if(! $bolehCairDini): echo 'disabled'; endif; ?>
                                title="<?php echo e($bolehCairDini
                                    ? 'Cairkan deposito sebelum jatuh tempo (dikenakan biaya penalti)'
                                    : 'Pencairan dini tidak tersedia untuk deposito ini.'); ?>"
                                style="display:inline-flex;align-items:center;gap:0.35rem;
                                       padding:0.35rem 0.7rem;border-radius:0.5rem;border:1px solid transparent;
                                       font-size:0.75rem;font-weight:600;white-space:nowrap;
                                       <?php echo e($bolehCairDini
                                            ? 'background:#b45309;color:#fff;cursor:pointer;'
                                            : 'background:#e2e8f0;color:#94a3b8;cursor:not-allowed;'); ?>">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M3 12a9 9 0 1 0 9-9"></path>
                                <polyline points="3 3 3 8 8 8"></polyline>
                            </svg>
                            Pencairan Dini
                        </button>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div style="padding:2rem;text-align:center;font-size:0.825rem;color:#94a3b8;">Belum ada bilyet Simpanan Berjangka.</div>
    <?php endif; ?>
</div>

<?php if($sertakanScript): ?>

<?php if($bolehPencairanDini): ?>

<div id="modal-cair-dini"
     style="display:none;position:fixed;inset:0;z-index:60;background:rgba(15,23,42,.55);
            align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;border-radius:0.875rem;width:100%;max-width:460px;
                max-height:85vh;display:flex;flex-direction:column;overflow:hidden;">
        <div style="padding:1rem 1.25rem;border-bottom:1px solid #f1f5f9;display:flex;
                    align-items:center;justify-content:space-between;">
            <div style="font-size:0.9rem;font-weight:700;color:#1e293b;">Konfirmasi Pencairan Dini</div>
            <button type="button" id="btn-tutup-cair-dini"
                    style="background:none;border:none;font-size:1.2rem;line-height:1;color:#94a3b8;cursor:pointer;">&times;</button>
        </div>

        <div class="max-h-[85vh] overflow-y-auto"
             style="padding:1.1rem 1.25rem;max-height:85vh;overflow-y:auto;min-height:0;flex:1 1 auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;">
            <div id="cair-dini-error" style="display:none;margin-bottom:0.75rem;background:#fee2e2;border:1px solid #fca5a5;
                        border-radius:0.5rem;padding:0.6rem 0.85rem;font-size:0.78rem;color:#991b1b;"></div>

            <table style="width:100%;border-collapse:collapse;font-size:0.82rem;color:#334155;">
                <tr>
                    <td style="padding:0.3rem 0;">No Bilyet</td>
                    <td style="padding:0.3rem 0;text-align:right;font-family:monospace;color:#1e40af;" id="cd-nomor">—</td>
                </tr>
                <tr>
                    <td style="padding:0.3rem 0;">Pokok Deposito</td>
                    <td style="padding:0.3rem 0;text-align:right;font-weight:600;" id="cd-pokok">—</td>
                </tr>
                <tr>
                    <td style="padding:0.3rem 0;">Biaya Penalti</td>
                    <td style="padding:0.3rem 0;text-align:right;font-weight:600;color:#b45309;" id="cd-penalti">—</td>
                </tr>
                <tr style="border-top:1px solid #e2e8f0;">
                    <td style="padding:0.5rem 0;font-weight:700;">Diterima ke Simpanan Sukarela</td>
                    <td style="padding:0.5rem 0;text-align:right;font-weight:700;color:#059669;" id="cd-bersih">—</td>
                </tr>
            </table>

            <div id="cd-forfeit" style="display:none;margin-top:0.6rem;background:#fef3c7;border:1px solid #fcd34d;
                        border-radius:0.5rem;padding:0.55rem 0.8rem;font-size:0.75rem;color:#92400e;">
                Bagi hasil bulan berjalan akan dihanguskan (forfeit) sesuai ketentuan produk.
            </div>

            <div style="margin-top:0.75rem;font-size:0.75rem;color:#64748b;">
                Jurnal otomatis:
                <strong>Debet <?php echo e(\App\Models\Deposito::COA_SIMPANAN_BERJANGKA); ?></strong> /
                <strong>Kredit <span id="cd-coa-penalti"><?php echo e(\App\Models\DepositoProduct::PENALTI_COA_DEFAULT); ?></span></strong> /
                <strong>Kredit <?php echo e(\App\Models\Deposito::COA_SIMPANAN_SUKARELA); ?></strong>
                (status POSTED).
            </div>
        </div>

        <div style="padding:0.85rem 1.25rem;border-top:1px solid #f1f5f9;display:flex;gap:0.5rem;justify-content:flex-end;">
            <button type="button" id="btn-batal-cair-dini"
                    style="padding:0.45rem 0.9rem;border:1px solid #d1d5db;background:#fff;border-radius:0.5rem;font-size:0.8rem;cursor:pointer;">
                Batal
            </button>
            <button type="button" id="btn-proses-cair-dini"
                    style="padding:0.45rem 0.9rem;border:none;background:#b45309;color:#fff;border-radius:0.5rem;font-size:0.8rem;font-weight:600;cursor:pointer;">
                Proses Pencairan Dini
            </button>
        </div>
    </div>
</div>

<script>
/* =====================================================================
 | PENCAIRAN DINI DEPOSITO (BREAK) — PSAK 101/108
 |   POST /api/deposito/{id}/early-withdrawal
 | Biaya penalti mengikuti master produk: 'fixed' → nominal rupiah,
 | 'percentage' → pokok × nilai / 100. Sisa bersih dikembalikan ke
 | Rekening Simpanan Sukarela (2-1001).
 |
 | Skrip ini HANYA dirender untuk teller; peran lain tidak menerima
 | tombol maupun handler-nya (segregation of duties).
 |==================================================================== */
(function () {
    'use strict';

    var URL_DEPOSITO = <?php echo json_encode(url('/api/deposito'), 15, 512) ?>;
    var dataAktif = null;

    function $(id) { return document.getElementById(id); }
    function rp(n) { return 'Rp ' + Math.round(n).toLocaleString('id-ID'); }

    function tampilkanError(pesan) {
        var el = $('cair-dini-error');
        if (!el) { return; }
        el.textContent = pesan;
        el.style.display = 'block';
    }

    function sembunyikanError() {
        var el = $('cair-dini-error');
        if (el) { el.style.display = 'none'; }
    }

    function tutupModal() {
        var m = $('modal-cair-dini');
        if (m) { m.style.display = 'none'; }
        dataAktif = null;
        sembunyikanError();
    }

    function bukaModal(tombol) {
        var pokok  = parseFloat(tombol.getAttribute('data-pokok')) || 0;
        var tipe   = tombol.getAttribute('data-penalti-tipe') || 'fixed';
        var nilai  = parseFloat(tombol.getAttribute('data-penalti-nilai')) || 0;
        var penalti = tipe === 'percentage' ? (pokok * nilai / 100) : nilai;
        penalti = Math.min(penalti, pokok);

        dataAktif = { id: tombol.getAttribute('data-id'), nomor: tombol.getAttribute('data-nomor') };

        $('cd-nomor').textContent   = dataAktif.nomor;
        $('cd-pokok').textContent   = rp(pokok);
        $('cd-penalti').textContent = rp(penalti) + (tipe === 'percentage' ? ' (' + nilai + '%)' : ' (nominal tetap)');
        $('cd-bersih').textContent  = rp(pokok - penalti);
        // Fallback HARUS sama dengan default server-side (data-penalti-coa di
        // atas): Coa::DANA_KEBAJIKAN, bukan akun pendapatan admin.
        $('cd-coa-penalti').textContent = tombol.getAttribute('data-penalti-coa') || '<?php echo e(\App\Support\Coa::DANA_KEBAJIKAN); ?>';

        var forfeit = $('cd-forfeit');
        if (forfeit) {
            forfeit.style.display = tombol.getAttribute('data-forfeit') === '1' ? 'block' : 'none';
        }

        sembunyikanError();
        $('modal-cair-dini').style.display = 'flex';
    }

    function proses() {
        if (!dataAktif) { return; }

        var btn = $('btn-proses-cair-dini');
        var labelAsli = btn.textContent;

        btn.disabled = true;
        btn.textContent = 'Memproses…';
        sembunyikanError();

        fetch(URL_DEPOSITO + '/' + encodeURIComponent(dataAktif.id) + '/early-withdrawal', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': <?php echo json_encode(csrf_token(), 15, 512) ?>
            },
            credentials: 'same-origin',
            body: JSON.stringify({})
        })
        .then(function (respon) {
            return respon.json().catch(function () { return {}; }).then(function (body) {
                if (!respon.ok || !body.success) {
                    throw new Error(body.message || ('Gagal memproses pencairan dini (kode ' + respon.status + ').'));
                }
                return body;
            });
        })
        .then(function (body) {
            tutupModal();

            var info = body.data || {};
            var nomor = (info.deposito && info.deposito.deposito_number) ? info.deposito.deposito_number : '';
            var pesan = 'Pencairan dini ' + nomor + ' berhasil. '
                + 'Penalti ' + rp(info.penalti || 0) + ', diterima ' + rp(info.diterima_anggota || 0) + '.';

            var flash = document.getElementById('flash-deposito') || document.getElementById('flash-bilyet');
            if (flash) {
                flash.textContent = pesan;
                flash.style.display = 'block';
            } else {
                window.alert(pesan);
            }

            // Muat ulang halaman agar saldo & status deposito ter-refresh.
            window.location.reload();
        })
        .catch(function (err) {
            tampilkanError((err && err.message) ? err.message : 'Terjadi kesalahan. Silakan coba lagi.');
        })
        .finally(function () {
            btn.disabled = false;
            btn.textContent = labelAsli;
        });
    }

    document.addEventListener('click', function (event) {
        var tombol = event.target.closest ? event.target.closest('.btn-cair-dini') : null;

        if (tombol && ! tombol.disabled) {
            event.preventDefault();
            bukaModal(tombol);
            return;
        }

        if (event.target.closest && event.target.closest('#btn-tutup-cair-dini, #btn-batal-cair-dini')) {
            event.preventDefault();
            tutupModal();
            return;
        }

        if (event.target.closest && event.target.closest('#btn-proses-cair-dini')) {
            event.preventDefault();
            proses();
            return;
        }

        // Klik di area gelap (overlay) menutup modal.
        if (event.target.id === 'modal-cair-dini') {
            tutupModal();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { tutupModal(); }
    });
})();
</script>
<?php endif; ?>


<?php if (! $__env->hasRenderedOnce('6ca444f9-5422-40da-87c8-b40704c4b0cf')): $__env->markAsRenderedOnce('6ca444f9-5422-40da-87c8-b40704c4b0cf'); ?>
<script>
/* =====================================================================
 | CETAK BILYET DEPOSITO (PDF)
 |   GET /api/deposito/{id}/print-bilyet → stream PDF di tab baru.
 |
 | Respons diambil lewat fetch() agar kegagalan (403/404/409/500) dapat
 | dideteksi — endpoint mengirim JSON, bukan PDF, saat gagal. Tab kosong
 | dibuka lebih dulu di dalam konteks klik pengguna agar tidak diblokir
 | pop-up blocker; isinya diisi setelah PDF benar-benar diterima.
 |==================================================================== */
(function () {
    'use strict';

    var URL_BILYET = <?php echo json_encode(url('/api/deposito'), 15, 512) ?>;

    function kotak() { return document.getElementById('flash-bilyet'); }

    function tampilkanError(pesan) {
        var el = kotak();
        if (!el) { return; }

        el.textContent = pesan;
        el.style.display = 'block';
    }

    function sembunyikanError() {
        var el = kotak();
        if (el) { el.style.display = 'none'; }
    }

    function cetakBilyet(tombol) {
        var id  = tombol.getAttribute('data-id');
        var url = URL_BILYET + '/' + encodeURIComponent(id) + '/print-bilyet';

        sembunyikanError();

        var tab = window.open('', '_blank');
        var labelAsli = tombol.innerHTML;

        tombol.disabled = true;
        tombol.style.opacity = '0.7';

        fetch(url, {
            method: 'GET',
            headers: {
                'Accept': 'application/pdf, application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        })
        .then(function (respon) {
            var tipe = respon.headers.get('content-type') || '';

            // Gagal: server mengirim JSON berisi pesan kesalahan, bukan PDF.
            if (!respon.ok || tipe.indexOf('application/pdf') === -1) {
                return respon.json().catch(function () { return {}; })
                    .then(function (body) {
                        throw new Error(body.message
                            || ('Gagal memuat bilyet deposito (kode ' + respon.status + ').'));
                    });
            }

            return respon.blob().then(function (blob) {
                var objectUrl = URL.createObjectURL(blob);

                if (!tab) {
                    URL.revokeObjectURL(objectUrl);
                    throw new Error('Tab baru diblokir browser. Izinkan pop-up untuk situs ini lalu coba lagi.');
                }

                tab.location.href = objectUrl;

                // Bebaskan memori setelah tab selesai memuat dokumen.
                setTimeout(function () { URL.revokeObjectURL(objectUrl); }, 60000);
            });
        })
        .catch(function (err) {
            if (tab) { tab.close(); }

            tampilkanError((err && err.message)
                ? err.message
                : 'Terjadi kesalahan saat memuat bilyet PDF. Silakan coba lagi.');
        })
        .finally(function () {
            tombol.disabled = false;
            tombol.style.opacity = '';
            tombol.innerHTML = labelAsli;
        });
    }

    // Event delegation agar tetap bekerja pada baris tabel yang dirender ulang.
    document.addEventListener('click', function (event) {
        var tombol = event.target.closest ? event.target.closest('.btn-bilyet') : null;

        if (!tombol || tombol.disabled) { return; }

        event.preventDefault();
        cetakBilyet(tombol);
    });
})();
</script>
<?php endif; ?>
<?php endif; ?>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\partials\deposito-bilyet-table.blade.php ENDPATH**/ ?>