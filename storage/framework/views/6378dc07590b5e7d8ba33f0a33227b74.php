
<?php if($pengajuanBelumSinkron > 0): ?>
<div class="card" style="border-left:3px solid #dc2626;background:#fef2f2;">
    <div style="display:flex;align-items:flex-start;gap:0.75rem;">
        <div style="font-size:1.25rem;line-height:1;">⚠️</div>
        <div>
            <div style="font-weight:700;color:#b91c1c;">
                Validasi data: <?php echo e($pengajuanBelumSinkron); ?> pengajuan disetujui belum tersinkron
            </div>
            <div style="font-size:0.82rem;color:#7f1d1d;margin-top:0.35rem;">
                Pengajuan berstatus <strong>Disetujui</strong> tetapi belum memiliki record pembiayaan
                (<code>pembiayaan_id</code> kosong), sehingga tidak terhitung pada statistik di atas.
                Jalankan <code>php artisan simulate:transaksi-hari-ini</code> atau hubungi admin untuk
                menyinkronkan ulang data.
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\dashboard\partials\pengurus-validasi.blade.php ENDPATH**/ ?>