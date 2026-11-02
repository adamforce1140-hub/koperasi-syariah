{{-- ═══════════════════════════════════════════════════════
     Breakdown Pembiayaan per Jenis Akad
═══════════════════════════════════════════════════════ --}}
<div class="card">
    <div class="card-header">
        <span class="card-title">📋 Total Pembiayaan per Jenis Akad</span>
        <a href="{{ route('dps.audit-akad') }}" style="font-size:0.78rem;color:#7c3aed;text-decoration:none;">Lihat Detail →</a>
    </div>

    @if($pembiayaanPerAkad->count() > 0)
        @php
            $maxPlafon = $pembiayaanPerAkad->max('total_plafon') ?: 1;
            $warnaPalette = ['#7c3aed','#2563eb','#059669','#d97706','#dc2626','#0891b2'];
        @endphp

        <div style="margin-bottom:1.25rem;">
            @foreach($pembiayaanPerAkad as $idx => $akad)
            @php $warna = $warnaPalette[$idx % count($warnaPalette)]; @endphp
            <div style="margin-bottom:0.85rem;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.3rem;">
                    <div style="display:flex;align-items:center;gap:0.5rem;">
                        <span style="width:10px;height:10px;border-radius:50%;background:{{ $warna }};display:inline-block;flex-shrink:0;"></span>
                        <span style="font-weight:600;font-size:0.875rem;">{{ $akad->akad_pembiayaan }}</span>
                        <span style="font-size:0.75rem;color:#64748b;background:#f1f5f9;padding:0.1rem 0.5rem;border-radius:999px;">{{ $akad->jumlah_kontrak }} kontrak</span>
                    </div>
                    <span style="font-weight:700;font-size:0.875rem;color:#1e293b;">
                        Rp {{ number_format($akad->total_plafon, 0, ',', '.') }}
                    </span>
                </div>
                <div style="background:#f1f5f9;border-radius:999px;height:8px;overflow:hidden;">
                    <div style="width:{{ round(($akad->total_plafon / $maxPlafon) * 100) }}%;height:100%;background:{{ $warna }};border-radius:999px;transition:width 0.4s;"></div>
                </div>
            </div>
            @endforeach
        </div>

        <table style="font-size:0.82rem;">
            <thead>
                <tr>
                    <th>Jenis Akad</th>
                    <th style="text-align:right;">Jumlah Kontrak</th>
                    <th style="text-align:right;">Total Plafon</th>
                    <th style="text-align:right;">% Portofolio</th>
                </tr>
            </thead>
            <tbody>
                @php $grandTotal = $pembiayaanPerAkad->sum('total_plafon') ?: 1; @endphp
                @foreach($pembiayaanPerAkad as $akad)
                <tr>
                    <td><strong>{{ $akad->akad_pembiayaan }}</strong></td>
                    <td style="text-align:right;">{{ number_format($akad->jumlah_kontrak) }}</td>
                    <td style="text-align:right;">Rp {{ number_format($akad->total_plafon, 0, ',', '.') }}</td>
                    <td style="text-align:right;">
                        <span style="font-weight:600;color:#7c3aed;">
                            {{ number_format(($akad->total_plafon / $grandTotal) * 100, 1) }}%
                        </span>
                    </td>
                </tr>
                @endforeach
                <tr style="font-weight:700;border-top:2px solid #e2e8f0;background:#f8fafc;">
                    <td>Total</td>
                    <td style="text-align:right;">{{ number_format($pembiayaanPerAkad->sum('jumlah_kontrak')) }}</td>
                    <td style="text-align:right;">Rp {{ number_format($grandTotal, 0, ',', '.') }}</td>
                    <td style="text-align:right;">100%</td>
                </tr>
            </tbody>
        </table>
    @else
        <div style="padding:2rem;text-align:center;color:#94a3b8;">
            <div style="font-size:2rem;margin-bottom:0.5rem;">📂</div>
            <div>Belum ada data pembiayaan</div>
        </div>
    @endif
</div>
