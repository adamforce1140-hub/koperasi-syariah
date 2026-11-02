{{-- ═══════════════════════════════════════════════════════
     Akumulasi Dana Kebajikan (Ta'zir)
═══════════════════════════════════════════════════════ --}}
<div class="card">
    <div class="card-header">
        <span class="card-title">🤝 Dana Kebajikan / Ta'zir</span>
        <a href="{{ route('dps.dana-kebajikan') }}" style="font-size:0.78rem;color:#7c3aed;text-decoration:none;">Lihat Detail →</a>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;">
        <div style="background:linear-gradient(135deg,#fef3c7,#fde68a);border-radius:0.75rem;padding:1.25rem;">
            <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.5rem;">
                <span style="font-size:1.25rem;">📥</span>
                <span style="font-size:0.75rem;font-weight:600;color:#92400e;text-transform:uppercase;letter-spacing:0.05em;">Dana Terkumpul</span>
            </div>
            <div style="font-size:1.4rem;font-weight:700;color:#78350f;word-break:break-word;">
                Rp {{ number_format($danaKebajikan, 0, ',', '.') }}
            </div>
            <div style="font-size:0.75rem;color:#92400e;margin-top:0.35rem;">
                Akumulasi KREDIT akun 2-3001
            </div>
        </div>

        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.75rem;padding:1.25rem;">
            <div style="font-size:0.78rem;font-weight:600;color:#334155;margin-bottom:0.5rem;">Ketentuan Syariah</div>
            <ul style="margin:0;padding-left:1.1rem;font-size:0.78rem;color:#64748b;line-height:1.7;">
                <li>Dana ta'zir <strong>tidak boleh</strong> menjadi pendapatan koperasi</li>
                <li>Wajib disalurkan sebagai dana sosial/kebajikan</li>
                <li>Diaudit secara berkala oleh DPS</li>
                <li>Sesuai fatwa DSN-MUI No. 17/DSN-MUI/IX/2000</li>
            </ul>
        </div>
    </div>

    @if($danaKebajikan <= 0)
    <div style="text-align:center;color:#94a3b8;font-size:0.8rem;margin-top:0.75rem;">
        Belum ada mutasi kredit pada akun 2-3001 (dana kebajikan)
    </div>
    @endif
</div>
