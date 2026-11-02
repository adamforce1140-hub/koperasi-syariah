{{-- Alert angsuran mendatang (hanya tampil bila ada) --}}
@if(isset($angsuranMendatang) && $angsuranMendatang)
<div style="background: #fffbeb; border: 1px solid #f59e0b; border-left: 4px solid #f59e0b; border-radius: 0.75rem; padding: 1rem 1.25rem; display: flex; align-items: center; gap: 0.75rem;">
    <span style="font-size: 1.25rem;">⚠️</span>
    <div>
        <div style="font-weight: 600; color: #92400e; font-size: 0.9rem;">Angsuran Segera Jatuh Tempo</div>
        <div style="font-size: 0.85rem; color: #b45309; margin-top: 0.15rem;">
            Jatuh tempo: {{ \Carbon\Carbon::parse($angsuranMendatang->tanggal_jatuh_tempo)->locale('id')->isoFormat('D MMMM Y') }}
            &mdash; <strong>Rp {{ number_format($angsuranMendatang->jumlah_angsuran, 0, ',', '.') }}</strong>
        </div>
    </div>
</div>
@endif
