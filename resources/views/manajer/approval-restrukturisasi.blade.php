@extends('layouts.app')
@section('page-title', "Approval Restrukturisasi Pembiayaan")

@section('title', 'Approval Restrukturisasi Pembiayaan')

@section('styles')
<style>
    .stat-strip { display:flex; gap:1rem; flex-wrap:wrap; margin-bottom:1.25rem; }
    .stat-box {
        flex:1; min-width:170px; background:#fff; border:1px solid #e2e8f0;
        border-radius:.75rem; padding:1rem 1.25rem; cursor:pointer;
    }
    .stat-box.active { border-color:#059669; box-shadow:0 0 0 1px #059669; }
    .stat-box .num { font-size:1.4rem; font-weight:800; color:#0f172a; }
    .stat-box .lbl { font-size:.72rem; color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:.04em; }

    .status-tabs { display:flex; gap:.5rem; margin-bottom:1rem; flex-wrap:wrap; }
    .status-tab {
        padding:.45rem 1rem; border-radius:9999px; font-size:.8rem; font-weight:600;
        background:#f1f5f9; color:#475569; text-decoration:none; border:1px solid transparent;
    }
    .status-tab.active { background:#059669; color:#fff; }
    .status-tab:hover { border-color:#059669; }

    .kol-badge {
        display:inline-block; padding:.12rem .55rem; border-radius:9999px;
        font-size:.72rem; font-weight:700; white-space:nowrap;
    }
    .rs-compare { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:.6rem; padding:.6rem .75rem; font-size:.8rem; line-height:1.55; }
    .rs-compare .arrow { color:#059669; font-weight:700; }

    .modal-overlay {
        display:none; position:fixed; inset:0; background:rgba(15,23,42,.55);
        z-index:1000; align-items:flex-start; justify-content:center;
        padding:2rem 1rem; overflow-y:auto;
    }
    .modal-overlay.open { display:flex; }
    .modal-box { background:#fff; border-radius:1rem; width:560px; max-width:100%; overflow:hidden; }
    .modal-head { background:linear-gradient(135deg,#065f46,#059669); color:#fff; padding:1.1rem 1.4rem; }
    .modal-head h3 { margin:0; font-size:1.05rem; font-weight:800; }
    .modal-head p { margin:.2rem 0 0; font-size:.78rem; opacity:.85; }
    .modal-body { padding:1.25rem 1.4rem; max-height:85vh; overflow-y:auto; }
    .modal-foot { display:flex; justify-content:flex-end; gap:.6rem; padding:1rem 1.4rem; border-top:1px solid #f1f5f9; background:#f8fafc; }
    .detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:.5rem .9rem; font-size:.82rem; }
    .detail-grid .d { background:#f8fafc; border:1px solid #e2e8f0; border-radius:.5rem; padding:.5rem .7rem; }
    .detail-grid .d .k { font-size:.66rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.03em; }
    .detail-grid .d .v { font-size:.85rem; font-weight:700; color:#0f172a; margin-top:.1rem; }
    .detail-grid .d.full { grid-column:1/-1; }
    label.m-label { font-size:.72rem; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.03em; }
    .m-textarea {
        width:100%; border:1.5px solid #e2e8f0; border-radius:.5rem; padding:.55rem .65rem;
        font-size:.85rem; font-family:inherit; outline:none; resize:vertical; margin-top:.3rem;
    }
    .m-textarea:focus { border-color:#059669; }
    .svc-note {
        display:flex; gap:.55rem; align-items:flex-start;
        background:#fffbeb; border:1px solid #fde68a; border-radius:.6rem;
        padding:.7rem .9rem; font-size:.74rem; color:#92400e; line-height:1.45; margin-top:1rem;
    }
    .btn { display:inline-flex; align-items:center; justify-content:center; gap:.4rem;
        padding:.55rem 1.1rem; font-size:.85rem; font-weight:600; border:none; border-radius:.5rem; cursor:pointer; }
    .btn-primary { background:#059669; color:#fff; }
    .btn-primary:hover { background:#047857; }
    .btn-danger { background:#dc2626; color:#fff; }
    .btn-danger:hover { background:#b91c1c; }
    .btn-secondary { background:#e2e8f0; color:#334155; }
    .btn-secondary:hover { background:#cbd5e1; }
    .btn-print { background:#f1f5f9; color:#334155; text-decoration:none; }
    .btn-print:hover { background:#e2e8f0; }
    .btn-sm { padding:.3rem .7rem; font-size:.75rem; }

    /* ---- Responsif: satu kolom pada tablet & ponsel ---- */
    @media (max-width: 900px) {
        .detail-grid { grid-template-columns: 1fr; }
        .modal-body { max-height: 70vh; }
        .modal-foot { flex-wrap: wrap; }
    }
    @media (max-width: 640px) {
        .modal-body { padding: 1rem; }
        .modal-foot { padding: .85rem 1rem; }
        .modal-foot .btn { flex: 1 1 auto; }
    }
</style>
@endsection

@section('content')
<div class="page-header" style="margin-bottom:1.25rem;">
    <div>
        <h1 class="page-title">Approval Restrukturisasi Pembiayaan</h1>
        <p class="page-subtitle">Keputusan Manajer atas pengajuan restrukturisasi (Rescheduling / Reconditioning) — seluruh eksekusi jadwal baru dikunci dalam transaksi database dengan sisa piutang tetap (Fatwa DSN-MUI No. 49/DSN-MUI/IV/2005)</p>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert" style="background:#fee2e2;color:#991b1b;border:1px solid #fecaca;border-radius:.5rem;padding:.8rem 1rem;margin-bottom:1rem;">{{ session('error') }}</div>
@endif

<div class="stat-strip">
    <a class="stat-box {{ $statusFilter === 'PENDING_APPROVAL' ? 'active' : '' }}" href="{{ route('manajer.approval-restrukturisasi.index', ['status' => 'PENDING_APPROVAL']) }}">
        <div class="num">{{ $totalPending }}</div>
        <div class="lbl">Menunggu Persetujuan</div>
    </a>
    <a class="stat-box {{ $statusFilter === 'APPROVED' ? 'active' : '' }}" href="{{ route('manajer.approval-restrukturisasi.index', ['status' => 'APPROVED']) }}">
        <div class="num">{{ $totalApproved }}</div>
        <div class="lbl">Disetujui</div>
    </a>
    <a class="stat-box {{ $statusFilter === 'REJECTED' ? 'active' : '' }}" href="{{ route('manajer.approval-restrukturisasi.index', ['status' => 'REJECTED']) }}">
        <div class="num">{{ $totalRejected }}</div>
        <div class="lbl">Ditolak</div>
    </a>
</div>

<div class="data-card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;">
        <span class="card-title">Daftar Pengajuan <span style="color:#94a3b8;font-weight:500;">({{ $statusFilter }})</span></span>
        <form method="GET" style="display:flex;gap:.5rem;">
            <input type="hidden" name="status" value="{{ $statusFilter }}">
            <input type="text" name="search" value="{{ $search }}"
                placeholder="Cari no. pembiayaan / anggota..."
                style="padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;width:240px;">
            <button type="submit" class="btn btn-secondary" style="font-size:.85rem;padding:.5rem .9rem;">Cari</button>
        </form>
    </div>

    <table>
        <thead>
            <tr>
                <th>Pembiayaan / Anggota</th>
                <th>Skema</th>
                <th>Komparasi Angsuran</th>
                <th>AO Pengusul</th>
                <th>Status</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($pengajuan as $r)
            @php
                $statusStyle = match($r->status) {
                    'APPROVED'         => 'background:#d1fae5;color:#065f46;',
                    'REJECTED'         => 'background:#fee2e2;color:#991b1b;',
                    default            => 'background:#fef3c7;color:#92400e;',
                };
                $angsuranBaru = $r->simulasi_angsuran_baru
                    ?? ($r->pembiayaan?->jumlah_angsuran ?? 0);
            @endphp
            <tr>
                <td>
                    <div style="font-weight:600;color:#059669;">{{ $r->pembiayaan?->no_pembiayaan ?? '-' }}</div>
                    <div>{{ $r->pembiayaan?->anggota?->nama_lengkap ?? '-' }} <small style="color:#94a3b8;">({{ $r->pembiayaan?->anggota?->no_anggota ?? '-' }})</small></div>
                    <div style="font-size:.72rem;color:#64748b;">Akad: {{ $r->pembiayaan?->akad_pembiayaan ?? '-' }} · Jatuh tempo: {{ optional($r->pembiayaan?->tanggal_jatuh_tempo)?->format('d/m/Y') ?? '-' }}</div>
                </td>
                <td>
                    <span class="kol-badge" style="background:#e0f2fe;color:#0369a1;">{{ $r->skema_restrukturisasi }}</span>
                    @if($r->tenor_baru)
                    <div style="font-size:.75rem;color:#64748b;margin-top:.25rem;">Tenor baru: {{ $r->tenor_baru }} bln</div>
                    @endif
                    @if($r->grace_period)
                    <div style="font-size:.75rem;color:#64748b;">Grace: {{ $r->grace_period }} bln</div>
                    @endif
                    @if($r->tanggal_jatuh_tempo_baru)
                    <div style="font-size:.75rem;color:#64748b;">JT baru: {{ $r->tanggal_jatuh_tempo_baru->format('d/m/Y') }}</div>
                    @endif
                </td>
                <td>
                    <div class="rs-compare">
                        <div>Sisa piutang: <strong>Rp {{ number_format($r->sisa_piutang_snapshot, 0, ',', '.') }}</strong>
                            <span style="color:#64748b;">(pokok Rp {{ number_format($r->sisa_pokok_snapshot, 0, ',', '.') }} + margin Rp {{ number_format($r->sisa_margin_snapshot, 0, ',', '.') }})</span>
                        </div>
                        <div>
                            Angsuran: <s style="color:#b45309;">Rp {{ number_format($r->angsuran_lama_snapshot, 0, ',', '.') }}</s>
                            <span class="arrow">→</span>
                            <strong style="color:#059669;">Rp {{ number_format(max($angsuranBaru, 0), 0, ',', '.') }}<strong>
                        </div>
                        <div style="color:#64748b;">Kemampuan bayar AO: Rp {{ number_format($r->kemampuan_bayar_baru, 0, ',', '.') }}/bln</div>
                    </div>
                </td>
                <td>
                    <div>{{ $r->ao?->name ?? '-' }}</div>
                    <div style="font-size:.72rem;color:#64748b;">{{ $r->created_at?->diffForHumans() }}</div>
                    <div style="font-size:.72rem;color:#475569;max-width:160px;" title="{{ $r->catatan_ao }}">📝 {{ Str::limit($r->catatan_ao ?: 'Alasan: '.$r->alasan_restrukturisasi, 60) }}</div>
                </td>
                <td>
                    <span class="kol-badge" style="{{ $statusStyle }}">{{ $r->status }}</span>
                    @if($r->catatan_manajer)
                    <div style="font-size:.72rem;color:#64748b;margin-top:.25rem;max-width:150px;" title="{{ $r->catatan_manajer }}">{{ Str::limit($r->catatan_manajer, 45) }}</div>
                    @endif
                </td>
                <td>
                    @if($r->status === 'PENDING_APPROVAL')
                    <div style="display:flex;flex-direction:column;gap:.4rem;align-items:flex-start;">
                        <button type="button" class="btn btn-primary btn-sm" onclick="openApprove({{ $r->id }}, '{{ $r->pembiayaan?->no_pembiayaan }}')">✓ Setujui</button>
                        <button type="button" class="btn btn-danger btn-sm" onclick="openReject({{ $r->id }}, '{{ $r->pembiayaan?->no_pembiayaan }}')">✕ Tolak</button>
                    </div>
                    @elseif($r->status === 'APPROVED')
                    <a class="btn btn-print btn-sm" href="{{ route('manajer.approval-restrukturisasi.addendum', $r->id) }}" target="_blank">
                        🖨 Addendum
                    </a>
                    <div style="font-size:.72rem;color:#64748b;margin-top:.3rem;">No: {{ $r->addendum_no }}</div>
                    @else
                    <span style="font-size:.75rem;color:#64748b;">Jadwal lama berlaku</span>
                    @endif
                </td>
            </tr>
            @empty
            <tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:2rem;">
                Tidak ada pengajuan dengan status {{ $statusFilter }}.
            </td></tr>
            @endforelse
        </tbody>
    </table>
    @if($pengajuan->hasPages())
    <div style="padding:1rem;">{{ $pengajuan->links() }}</div>
    @endif
</div>

{{-- ─── MODAL KEPUTUSAN MANAJER ─────────────────────────────────────── --}}
<div class="modal-overlay" id="mjModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3 id="mjTitle">Keputusan Manajer</h3>
            <p id="mjSub">Pengajuan restrukturisasi pembiayaan</p>
        </div>
        <form method="POST" action="" id="mjForm">
            @csrf
            <div class="modal-body">
                <div class="detail-grid">
                    <div class="d"><div class="k">Sisa Piutang (TETAP)</div><div class="v" id="mjSisa">-</div></div>
                    <div class="d"><div class="k">Angsuran Lama</div><div class="v" id="mjLama">-</div></div>
                    <div class="d"><div class="k">Tenor Baru</div><div class="v" id="mjTenor">-</div></div>
                    <div class="d"><div class="k">Proyeksi Angsuran Baru</div><div class="v" id="mjBaru">-</div></div>
                    <div class="d full"><div class="k">Kemampuan Bayar (Analisis AO)</div><div class="v" id="mjKemampuan">-</div></div>
                </div>

                <div style="margin-top:1rem;">
                    <label class="m-label" id="mjCatatanLabel">Catatan Manajer *</label>
                    <textarea class="m-textarea" name="catatan_manajer" id="mjCatatan" rows="3"
                        placeholder="Isi catatan / pertimbangan Manajer..."></textarea>
                </div>

                <div class="svc-note">
                    <span>⚖️</span>
                    <span><strong>Safeguard Syariah (Fatwa DSN-MUI No. 49/DSN-MUI/IV/2005):</strong>
                    Persetujuan TIDAK menambah sisa margin / harga jual. Jadwal lama yang belum dibayar diganti dengan
                    pembagian rata sisa piutang ke tenor baru, seluruhnya dalam satu transaksi database.</span>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-secondary" onclick="closeMj()">Batal</button>
                <button type="submit" class="btn btn-primary" id="mjSubmit">Kirim Keputusan</button>
            </div>
        </form>
    </div>
</div>
@endsection

@section('scripts')
<script>
    (function () {
        const overlay = document.getElementById('mjModal');
        const form    = document.getElementById('mjForm');

        // Data pengajuan disuntikkan dari blade ke JS (helper rendering rupiah di JS).
        @php
            $dataMap = $pengajuan->getCollection()->map(function ($r) {
                return [
                    'id'       => $r->id,
                    'no'       => $r->pembiayaan?->no_pembiayaan,
                    'anggota'  => $r->pembiayaan?->anggota?->nama_lengkap,
                    'sisa'     => 'Rp ' . number_format($r->sisa_piutang_snapshot, 0, ',', '.'),
                    'lama'     => 'Rp ' . number_format($r->angsuran_lama_snapshot, 0, ',', '.'),
                    'tenor'    => $r->tenor_baru ? $r->tenor_baru . ' bln' : ($r->grace_period ? 'sisa + ' . $r->grace_period . ' bln' : '-'),
                    'baru'     => $r->simulasi_angsuran_baru ? 'Rp ' . number_format($r->simulasi_angsuran_baru, 0, ',', '.') : '-',
                    'kemampuan'=> 'Rp ' . number_format($r->kemampuan_bayar_baru, 0, ',', '.') . ' / bln (analisis AO)',
                ];
            })->values();
        @endphp
        const dataMap = @json($dataMap);

        const approveUrl = '{{ route('manajer.approval-restrukturisasi.approve', ['id' => '__ID__']) }}';
        const rejectUrl  = '{{ route('manajer.approval-restrukturisasi.reject', ['id' => '__ID__']) }}';

        window.openApprove = function (id, noPembiayaan) {
            const d = dataMap.find(x => x.id === id) || {};
            form.action = approveUrl.replace('__ID__', id);
            document.getElementById('mjTitle').textContent = 'Setujui Restrukturisasi';
            document.getElementById('mjSub').textContent = noPembiayaan + ' — ' + (d.anggota || '-');
            fillDetail(d);
            document.getElementById('mjCatatan').value = '';
            document.getElementById('mjCatatan').required = false;
            document.getElementById('mjCatatanLabel').textContent = 'Catatan Manajer (opsional, untuk approval)';
            document.getElementById('mjSubmit').className = 'btn btn-primary';
            document.getElementById('mjSubmit').textContent = '✓ Setujui & Generate Jadwal Baru';
            overlay.classList.add('open');
        };

        window.openReject = function (id, noPembiayaan) {
            const d = dataMap.find(x => x.id === id) || {};
            form.action = rejectUrl.replace('__ID__', id);
            document.getElementById('mjTitle').textContent = 'Tolak Restrukturisasi';
            document.getElementById('mjSub').textContent = noPembiayaan + ' — ' + (d.anggota || '-');
            fillDetail(d);
            document.getElementById('mjCatatan').value = '';
            document.getElementById('mjCatatan').required = true;
            document.getElementById('mjCatatanLabel').textContent = 'Alasan Penolakan *';
            document.getElementById('mjSubmit').className = 'btn btn-danger';
            document.getElementById('mjSubmit').textContent = '✕ Tolak Pengajuan';
            overlay.classList.add('open');
        };

        function fillDetail(d) {
            document.getElementById('mjSisa').textContent        = d.sisa || '-';
            document.getElementById('mjLama').textContent        = d.lama || '-';
            document.getElementById('mjTenor').textContent       = d.tenor || '-';
            document.getElementById('mjBaru').textContent        = d.baru || '-';
            document.getElementById('mjKemampuan').textContent   = d.kemampuan || '-';
        }

        window.closeMj = function () { overlay.classList.remove('open'); };
        overlay.addEventListener('click', function (e) { if (e.target === overlay) closeMj(); });
    })();
</script>
@endsection