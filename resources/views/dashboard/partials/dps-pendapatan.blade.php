{{-- ═══════════════════════════════════════════════════════
     Pendapatan Margin vs Bagi Hasil
     Nilai dari PendapatanService — sumber kebenaran yang sama dengan
     halaman Laporan Keuangan Syariah (akumulasi `angsuran.margin_ujroh`).
═══════════════════════════════════════════════════════ --}}
<div class="card">
    <div class="card-header">
        <span class="card-title">💹 Pendapatan Margin vs Bagi Hasil</span>
        <a href="{{ route('dps.laporan-keuangan') }}" style="font-size:0.78rem;color:#7c3aed;text-decoration:none;">Lihat Laporan →</a>
    </div>

    @php
        $pct = fn ($nilai) => $totalPendapatan > 0
            ? round(($nilai / $totalPendapatan) * 100, 1)
            : 0;

        $pctMargin    = $pct($totalMargin);
        $pctBagiHasil = $pct($totalBagiHasil);
        $pctUjroh     = $pct($totalUjroh);
    @endphp

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-bottom:1.25rem;">
        <div style="background:linear-gradient(135deg,#ede9fe,#ddd6fe);border-radius:0.75rem;padding:1.25rem;">
            <div style="font-size:0.75rem;font-weight:600;color:#7c3aed;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.5rem;">
                Pendapatan Margin
            </div>
            <div style="font-size:1.4rem;font-weight:700;color:#4c1d95;margin-bottom:0.25rem;word-break:break-word;">
                Rp {{ number_format($totalMargin, 0, ',', '.') }}
            </div>
            <div style="font-size:0.78rem;color:#6d28d9;">
                {{ $pctMargin }}% dari total pendapatan
            </div>
            <div style="margin-top:0.75rem;background:rgba(255,255,255,0.5);border-radius:999px;height:6px;">
                <div style="width:{{ $pctMargin }}%;height:100%;background:#7c3aed;border-radius:999px;"></div>
            </div>
            <div style="font-size:0.7rem;color:#7c3aed;margin-top:0.35rem;">Murabahah · IMBT · Istishna · Salam</div>
        </div>

        <div style="background:linear-gradient(135deg,#d1fae5,#a7f3d0);border-radius:0.75rem;padding:1.25rem;">
            <div style="font-size:0.75rem;font-weight:600;color:#059669;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.5rem;">
                Pendapatan Bagi Hasil
            </div>
            <div style="font-size:1.4rem;font-weight:700;color:#065f46;margin-bottom:0.25rem;word-break:break-word;">
                Rp {{ number_format($totalBagiHasil, 0, ',', '.') }}
            </div>
            <div style="font-size:0.78rem;color:#047857;">
                {{ $pctBagiHasil }}% dari total pendapatan
            </div>
            <div style="margin-top:0.75rem;background:rgba(255,255,255,0.5);border-radius:999px;height:6px;">
                <div style="width:{{ $pctBagiHasil }}%;height:100%;background:#059669;border-radius:999px;"></div>
            </div>
            <div style="font-size:0.7rem;color:#059669;margin-top:0.35rem;">Mudharabah · Musyarakah</div>
        </div>

        <div style="background:linear-gradient(135deg,#fef3c7,#fde68a);border-radius:0.75rem;padding:1.25rem;">
            <div style="font-size:0.75rem;font-weight:600;color:#b45309;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.5rem;">
                Pendapatan Ujroh / Fee
            </div>
            <div style="font-size:1.4rem;font-weight:700;color:#92400e;margin-bottom:0.25rem;word-break:break-word;">
                Rp {{ number_format($totalUjroh, 0, ',', '.') }}
            </div>
            <div style="font-size:0.78rem;color:#b45309;">
                {{ $pctUjroh }}% dari total pendapatan
            </div>
            <div style="margin-top:0.75rem;background:rgba(255,255,255,0.5);border-radius:999px;height:6px;">
                <div style="width:{{ $pctUjroh }}%;height:100%;background:#d97706;border-radius:999px;"></div>
            </div>
            <div style="font-size:0.7rem;color:#b45309;margin-top:0.35rem;">Ijarah · Biaya Administrasi</div>
        </div>
    </div>

    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.5rem;padding:0.875rem 1rem;display:flex;justify-content:space-between;align-items:center;gap:0.75rem;flex-wrap:wrap;">
        <span style="font-size:0.875rem;color:#64748b;">Total Pendapatan Tercatat</span>
        <span style="font-size:1.1rem;font-weight:700;color:#1e293b;">
            Rp {{ number_format($totalPendapatan, 0, ',', '.') }}
        </span>
    </div>

    @if($totalPendapatan <= 0)
    <div style="text-align:center;color:#94a3b8;font-size:0.8rem;margin-top:0.75rem;">
        Belum ada transaksi margin/bagi hasil tercatat
    </div>
    @endif
</div>
