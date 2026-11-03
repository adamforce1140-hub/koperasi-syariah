@extends('layouts.app')

@section('title', 'Laporan Keuangan Syariah — DPS')
@section('page-title', 'Laporan Keuangan Syariah')

@section('content')
<div style="background:#fef3c7;border:1px solid #fde68a;border-left:4px solid #d97706;border-radius:0.5rem;padding:0.875rem 1.25rem;margin-bottom:1.5rem;font-size:0.875rem;color:#92400e;">
    Laporan ini bersifat <strong>indikatif</strong> berdasarkan data transaksi tercatat. Untuk laporan keuangan resmi, gunakan laporan yang diterbitkan bagian akuntansi.
</div>

{{-- ── BAGIAN 1: Pendapatan ─────────────────────────────── --}}
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <span class="card-title">Laporan Pendapatan Syariah</span>
        <span class="badge" style="background:#ede9fe;color:#7c3aed;font-size:0.7rem;">READ-ONLY</span>
    </div>

    @php
        $totalPendapatan = $pendapatanMargin + $pendapatanBagiHasil + $pendapatanUjroh;
    @endphp

    <table style="font-size:0.875rem;">
        <thead>
            <tr>
                <th>Sumber Pendapatan</th>
                <th>Jenis Akad Terkait</th>
                <th style="text-align:right;">Jumlah (Rp)</th>
                <th style="text-align:right;">% Komposisi</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>Pendapatan Margin</strong></td>
                <td style="font-size:0.8rem;color:#64748b;">Murabahah, IMBT, Istishna, Salam</td>
                <td style="text-align:right;font-weight:600;">{{ number_format($pendapatanMargin, 0, ',', '.') }}</td>
                <td style="text-align:right;">
                    <span style="color:#7c3aed;font-weight:600;">
                        {{ $totalPendapatan > 0 ? number_format(($pendapatanMargin / $totalPendapatan) * 100, 1) : 0 }}%
                    </span>
                </td>
            </tr>
            <tr>
                <td><strong>Pendapatan Bagi Hasil</strong></td>
                <td style="font-size:0.8rem;color:#64748b;">Mudharabah, Musyarakah</td>
                <td style="text-align:right;font-weight:600;">{{ number_format($pendapatanBagiHasil, 0, ',', '.') }}</td>
                <td style="text-align:right;">
                    <span style="color:#059669;font-weight:600;">
                        {{ $totalPendapatan > 0 ? number_format(($pendapatanBagiHasil / $totalPendapatan) * 100, 1) : 0 }}%
                    </span>
                </td>
            </tr>
            <tr>
                <td><strong>Pendapatan Ujroh / Fee</strong></td>
                <td style="font-size:0.8rem;color:#64748b;">Ijarah &amp; biaya administrasi/fee layanan</td>
                <td style="text-align:right;font-weight:600;">{{ number_format($pendapatanUjroh, 0, ',', '.') }}</td>
                <td style="text-align:right;">
                    <span style="color:#d97706;font-weight:600;">
                        {{ $totalPendapatan > 0 ? number_format(($pendapatanUjroh / $totalPendapatan) * 100, 1) : 0 }}%
                    </span>
                </td>
            </tr>
        </tbody>
        <tfoot>
            <tr style="font-weight:700;background:#f8fafc;border-top:2px solid #e2e8f0;">
                <td colspan="2">Total Pendapatan Tercatat</td>
                <td style="text-align:right;font-size:1rem;">Rp {{ number_format($totalPendapatan, 0, ',', '.') }}</td>
                <td style="text-align:right;">100%</td>
            </tr>
        </tfoot>
    </table>

    {{-- Rekonsiliasi: pemisahan pokok, dana kebajikan, dan pendapatan --}}
    <div style="padding:1rem 1.25rem;border-top:1px solid #e2e8f0;background:#f8fafc;">
        <div style="font-size:0.78rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.6rem;">
            Rekonsiliasi Arus Angsuran
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.75rem;">
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.5rem;padding:0.75rem 0.9rem;">
                <div style="font-size:0.72rem;color:#64748b;">Pengembalian Pokok</div>
                <div style="font-size:1rem;font-weight:700;color:#1e293b;">Rp {{ number_format($pengembalianPokok, 0, ',', '.') }}</div>
                <div style="font-size:0.7rem;color:#94a3b8;margin-top:0.15rem;">bukan pendapatan</div>
            </div>
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.5rem;padding:0.75rem 0.9rem;">
                <div style="font-size:0.72rem;color:#64748b;">Pendapatan Margin + Bagi Hasil</div>
                <div style="font-size:1rem;font-weight:700;color:#7c3aed;">Rp {{ number_format($pendapatanMargin + $pendapatanBagiHasil, 0, ',', '.') }}</div>
                <div style="font-size:0.7rem;color:#94a3b8;margin-top:0.15rem;">dari margin angsuran</div>
            </div>
            <div style="background:#fff;border:1px solid #fde68a;border-radius:0.5rem;padding:0.75rem 0.9rem;"
                 id="box-dana-kebajikan"
                 data-refresh-url="{{ route('dps.laporan-keuangan.dana-kebajikan') }}"
                 data-jumlah-mutasi="">
                <div style="font-size:0.72rem;color:#92400e;">Dana Kebajikan (Ta'zir/Denda)</div>
                <div style="font-size:1rem;font-weight:700;color:#d97706;" data-field="dana-kebajikan">Rp {{ number_format($danaKebajikan, 0, ',', '.') }}</div>
                <div style="font-size:0.7rem;color:#94a3b8;margin-top:0.15rem;">bukan pendapatan koperasi</div>
            </div>
        </div>
    </div>
</div>

{{-- ─ BAGIAN 1B: Rincian Pendapatan per Akad ───────────── --}}
@if($pendapatanPerAkad->count() > 0)
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <span class="card-title">Rincian Pendapatan per Akad</span>
        <span class="badge" style="background:#dbeafe;color:#1e40af;font-size:0.7rem;">DARI ANGSURAN DIBAYAR</span>
    </div>
    <table style="font-size:0.875rem;">
        <thead>
            <tr>
                <th>Akad</th>
                <th style="text-align:right;">Angsuran Dibayar</th>
                <th style="text-align:right;">Pokok</th>
                <th style="text-align:right;">Margin / Bagi Hasil</th>
                <th style="text-align:right;">Kategori</th>
            </tr>
        </thead>
        <tbody>
            @foreach($pendapatanPerAkad as $row)
            @php
                // PSAK 107: kategori akad dibaca dari PendapatanService supaya badge
                // di tabel ini tidak bisa menyimpang dari angka ringkasan di atasnya.
                $kategoriAkad  = \App\Services\PendapatanService::kategoriAkad($row->akad);
                $akadUjrohRow  = $kategoriAkad === \App\Services\PendapatanService::HAK_UJROH;
                $akadMarginRow = $kategoriAkad === \App\Services\PendapatanService::HAK_MARGIN;
                $akadBagiHasil = $kategoriAkad === \App\Services\PendapatanService::HAK_BAGI_HASIL;
            @endphp
            <tr>
                <td><strong>{{ $row->akad }}</strong></td>
                <td style="text-align:right;">{{ number_format($row->jumlah_angsuran) }}x</td>
                <td style="text-align:right;color:#64748b;">Rp {{ number_format($row->total_pokok, 0, ',', '.') }}</td>
                <td style="text-align:right;font-weight:600;color:#7c3aed;">Rp {{ number_format($row->total_margin, 0, ',', '.') }}</td>
                <td style="text-align:right;">
                    @if($akadUjrohRow)
                        <span style="display:inline-block;padding:0.15rem 0.55rem;border-radius:9999px;font-size:0.7rem;font-weight:600;background:#fef3c7;color:#92400e;">Ujroh</span>
                    @elseif($akadMarginRow)
                        <span style="display:inline-block;padding:0.15rem 0.55rem;border-radius:9999px;font-size:0.7rem;font-weight:600;background:#ede9fe;color:#6d28d9;">Margin</span>
                    @elseif($akadBagiHasil)
                        <span style="display:inline-block;padding:0.15rem 0.55rem;border-radius:9999px;font-size:0.7rem;font-weight:600;background:#d1fae5;color:#065f46;">Bagi Hasil</span>
                    @else
                        <span style="display:inline-block;padding:0.15rem 0.55rem;border-radius:9999px;font-size:0.7rem;font-weight:600;background:#f1f5f9;color:#64748b;">Non-Pendapatan</span>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr style="font-weight:700;background:#f8fafc;border-top:2px solid #e2e8f0;">
                <td>Total</td>
                <td style="text-align:right;">{{ number_format($pendapatanPerAkad->sum('jumlah_angsuran')) }}x</td>
                <td style="text-align:right;color:#64748b;">Rp {{ number_format($pengembalianPokok, 0, ',', '.') }}</td>
                <td style="text-align:right;color:#7c3aed;">Rp {{ number_format($pendapatanPerAkad->sum('total_margin'), 0, ',', '.') }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    <div style="padding:0.75rem 1.25rem;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:0.75rem;color:#64748b;">
        Pendapatan dihitung dari komponen <strong>margin/bagi hasil</strong> pada angsuran yang sudah dibayar.
        Komponen pokok merupakan pengembalian aset, bukan pendapatan. Akad <em>Qardh</em> tidak menghasilkan margin.
    </div>
</div>
@endif

{{-- ── BAGIAN 2: Neraca Pembiayaan ──────────────────────── --}}
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <span class="card-title">Neraca Pembiayaan (Posisi Piutang/Aset)</span>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.25rem;">
        <div style="background:linear-gradient(135deg,#ede9fe,#ddd6fe);border-radius:0.75rem;padding:1.25rem;">
            <div style="font-size:0.75rem;font-weight:600;color:#7c3aed;text-transform:uppercase;margin-bottom:0.4rem;">Total Dana Disalurkan</div>
            <div style="font-size:1.4rem;font-weight:700;color:#4c1d95;">Rp {{ number_format($totalDisalurkan, 0, ',', '.') }}</div>
            <div style="font-size:0.75rem;color:#6d28d9;margin-top:0.25rem;">Akad Aktif + Restrukturisasi + Lunas</div>
        </div>
        <div style="background:linear-gradient(135deg,#d1fae5,#a7f3d0);border-radius:0.75rem;padding:1.25rem;">
            <div style="font-size:0.75rem;font-weight:600;color:#059669;text-transform:uppercase;margin-bottom:0.4rem;">Total Dana Dihimpun</div>
            <div style="font-size:1.4rem;font-weight:700;color:#065f46;">Rp {{ number_format($totalSetoran, 0, ',', '.') }}</div>
            <div style="font-size:0.75rem;color:#047857;margin-top:0.25rem;">Simpanan / Setoran Anggota</div>
        </div>
    </div>

    @if($pembiayaanPerStatus->count() > 0)
    <table style="font-size:0.82rem;">
        <thead>
            <tr>
                <th>Status Pembiayaan</th>
                <th style="text-align:right;">Jumlah Kontrak</th>
                <th style="text-align:right;">Total Plafon</th>
                <th style="text-align:right;">Sisa Pokok</th>
                <th style="text-align:right;">% Tertagih</th>
            </tr>
        </thead>
        <tbody>
            @foreach($pembiayaanPerStatus as $row)
            @php
                $tertagih = $row->total_plafon > 0
                    ? (($row->total_plafon - $row->total_sisa) / $row->total_plafon) * 100
                    : 0;
                $sc = match(\App\Models\Pembiayaan::normalizeStatus($row->status_pembiayaan)) {
                    'aktif' => 'badge-green', 'lunas' => 'badge-blue',
                    'macet' => 'badge-red', default => 'badge-amber',
                };
            @endphp
            <tr>
                <td><span class="badge {{ $sc }}" style="font-size:0.72rem;">{{ $row->status_pembiayaan }}</span></td>
                <td style="text-align:right;">{{ number_format($row->jumlah) }}</td>
                <td style="text-align:right;">Rp {{ number_format($row->total_plafon, 0, ',', '.') }}</td>
                <td style="text-align:right;">Rp {{ number_format($row->total_sisa, 0, ',', '.') }}</td>
                <td style="text-align:right;font-weight:600;">{{ number_format($tertagih, 1) }}%</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <div style="padding:0.75rem 1.25rem;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:0.75rem;color:#64748b;">
        Hanya pembiayaan berstatus <strong>aktif</strong>, <strong>restrukturisasi</strong>, dan <strong>lunas</strong> yang dihitung
        (PSAK 107: kontrak yang belum atau tidak pernah cair bukan piutang dan bukan aset).
        Status <em>ditolak</em> dan <em>pengajuan</em> dikecualikan sepenuhnya dari total plafon, sisa pokok, dan % tertagih.
    </div>
    @endif
</div>

{{-- ── BAGIAN 3: Distribusi Bagi Hasil ─────────────────── --}}
@if($distribusiBagiHasil->count() > 0)
<div class="card">
    <div class="card-header">
        <span class="card-title">Distribusi Bagi Hasil per Akad</span>
    </div>
    <table style="font-size:0.875rem;">
        <thead>
            <tr>
                <th>Akad</th>
                <th style="text-align:right;">Total Pembiayaan</th>
                <th style="text-align:right;">Rata-rata Nisbah Pengelola</th>
                <th style="text-align:right;">Rata-rata Nisbah Pemilik Dana</th>
            </tr>
        </thead>
        <tbody>
            @foreach($distribusiBagiHasil as $row)
            <tr>
                <td><strong>{{ $row->akad_pembiayaan }}</strong></td>
                <td style="text-align:right;">Rp {{ number_format($row->total, 0, ',', '.') }}</td>
                <td style="text-align:right;">
                    <span style="font-weight:600;color:#7c3aed;">
                        {{ number_format($row->avg_nisbah_pengelola ?? 0, 1) }}%
                    </span>
                </td>
                <td style="text-align:right;">
                    <span style="font-weight:600;color:#059669;">
                        {{ number_format($row->avg_nisbah_pemilik ?? 0, 1) }}%
                    </span>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <div style="padding:0.75rem 1.25rem;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:0.75rem;color:#64748b;">
        Nisbah bagi hasil ditetapkan dalam akad dan tidak boleh diubah sepihak sesuai prinsip syariah.
    </div>
</div>
@endif

{{-- ── Real-time: box Dana Kebajikan (akumulasi mutasi KREDIT akun 2-3001) ── --}}
<script>
(function () {
    const box = document.getElementById('box-dana-kebajikan');
    if (!box) return;

    const url   = box.dataset.refreshUrl;
    const nilai = box.querySelector('[data-field="dana-kebajikan"]');
    if (!url || !nilai) return;

    const rupiah = (angka) => 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.round(angka));
    let mutasiTerakhir = null;

    async function segarkanDanaKebajikan() {
        try {
            const respon = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                cache: 'no-store',
            });
            if (!respon.ok) return;

            const data = await respon.json();

            // Hanya sentuh DOM bila akumulasi mutasi 2-3001 benar-benar berubah.
            if (mutasiTerakhir !== null && data.jumlahMutasi === mutasiTerakhir) return;
            mutasiTerakhir = data.jumlahMutasi;

            nilai.textContent = rupiah(data.totalKebajikan);
        } catch (e) {
            // Kegagalan refresh tidak mengganggu halaman; nilai terakhir tetap tampil.
        }
    }

    segarkanDanaKebajikan();
    setInterval(segarkanDanaKebajikan, 300000); // polling 5 menit
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) segarkanDanaKebajikan();
    });
})();
</script>
@endsection
