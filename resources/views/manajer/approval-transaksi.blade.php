@extends('layouts.app')

@section('title', 'Approval Transaksi Over-limit — Manajer')
@section('page-title', 'Approval Transaksi Over-limit')

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- ── HEADER ─────────────────────────────────────── --}}
    <div>
        <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
            Approval Transaksi Over-limit
        </h1>
        <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
            Persetujuan transaksi penarikan/setoran di atas limit kewenangan Teller secara real-time.
        </p>
    </div>

    {{-- ── FLASH MESSAGE ───────────────────────────────── --}}
    @if(session('success'))
    <div style="padding:0.875rem 1.25rem;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:0.625rem;color:#065f46;font-size:0.875rem;display:flex;align-items:center;gap:0.5rem;">
        <span>✅</span> {{ session('success') }}
    </div>
    @endif
    @if(session('error'))
    <div style="padding:0.875rem 1.25rem;background:#fef2f2;border:1px solid #fecaca;border-radius:0.625rem;color:#991b1b;font-size:0.875rem;display:flex;align-items:center;gap:0.5rem;">
        <span>⚠️</span> {{ session('error') }}
    </div>
    @endif

    {{-- ── STAT CARDS ──────────────────────────────────── --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;">
        @php
        $cards = [
            ['label' => 'Menunggu Approval', 'value' => $totalPending,   'color' => '#d97706', 'bg' => '#fef3c7', 'icon' => '⏳'],
            ['label' => 'Disetujui',          'value' => $totalDisetujui, 'color' => '#059669', 'bg' => '#d1fae5', 'icon' => '✅'],
            ['label' => 'Ditolak',            'value' => $totalDitolak,   'color' => '#dc2626', 'bg' => '#fee2e2', 'icon' => '❌'],
        ];
        @endphp
        @foreach($cards as $c)
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.125rem 1.25rem;
                    display:flex;align-items:center;gap:0.875rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="width:40px;height:40px;border-radius:0.625rem;background:{{ $c['bg'] }};
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.1rem;">
                {{ $c['icon'] }}
            </div>
            <div>
                <div style="font-size:1.35rem;font-weight:700;color:{{ $c['color'] }};line-height:1;">
                    {{ number_format($c['value']) }}
                </div>
                <div style="font-size:0.75rem;color:#64748b;margin-top:0.2rem;">{{ $c['label'] }}</div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- ── FILTER ───────────────────────────────────────── --}}
    <form method="GET" action="{{ route('manajer.approval-transaksi.index') }}"
          style="display:flex;gap:0.625rem;flex-wrap:wrap;align-items:flex-end;">
        <input type="text" name="search" value="{{ $search }}" aria-label="Cari nama pengaju" placeholder="Cari nama pengaju…"
               style="flex:1;min-width:200px;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                      border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;"
               onfocus="this.style.borderColor='#047857'" onblur="this.style.borderColor='#cbd5e1'">
        <select name="status"
                style="padding:0.5rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;
                       font-size:0.825rem;color:#1e293b;background:#fff;outline:none;"
                onfocus="this.style.borderColor='#047857'" onblur="this.style.borderColor='#cbd5e1'">
            <option value="">Semua Status</option>
            <option value="{{ \App\Support\HierarkiRole::statusMenunggu('manajer') }}" {{ $status === \App\Support\HierarkiRole::statusMenunggu('manajer') ? 'selected' : '' }}>Menunggu Approval</option>
            <option value="APPROVED"        {{ $status === 'APPROVED'        ? 'selected' : '' }}>Disetujui</option>
            <option value="REJECTED"        {{ $status === 'REJECTED'        ? 'selected' : '' }}>Ditolak</option>
        </select>
        <button type="submit"
                style="padding:0.5rem 1.125rem;background:#047857;color:#fff;font-size:0.825rem;
                       font-weight:600;border:none;border-radius:0.5rem;cursor:pointer;"
                onmouseover="this.style.background='#065f46'" onmouseout="this.style.background='#047857'">
            Filter
        </button>
        @if($search || $status)
        <a href="{{ route('manajer.approval-transaksi.index') }}"
           style="padding:0.5rem 1rem;background:#f1f5f9;color:#475569;font-size:0.825rem;
                  font-weight:600;border-radius:0.5rem;text-decoration:none;">
            Reset
        </a>
        @endif
    </form>

    {{-- ── TABEL TRANSAKSI OVER-LIMIT ─────────────────────── --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;
                overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        @if($approvals->count())
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Waktu / Kode</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Teller Pengaju</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Anggota & No Rekening</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Jenis Transaksi</th>
                        <th style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#475569;white-space:nowrap;">Nominal</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;white-space:nowrap;">Status</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;white-space:nowrap;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($approvals as $ap)
                    @php
                        $transaksi = \App\Models\Transaksi::with(['rekening.anggota', 'anggota'])->find($ap->referensi_id);
                        $namaAnggota = $transaksi?->anggota?->nama_lengkap 
                            ?? $transaksi?->rekening?->anggota?->nama_lengkap 
                            ?? '—';
                        $noRekening = $transaksi?->rekening?->no_rekening ?? '—';
                        
                        $badge = match($ap->status) {
                            'APPROVED', 'DISETUJUI' => ['bg' => '#d1fae5', 'color' => '#065f46', 'label' => 'Disetujui'],
                            'REJECTED', 'DITOLAK'   => ['bg' => '#fee2e2', 'color' => '#991b1b', 'label' => 'Ditolak'],
                            default                 => ['bg' => '#fef3c7', 'color' => '#92400e', 'label' => 'Pending Approval'],
                        };
                    @endphp
                    <tr style="border-bottom:1px solid #f1f5f9;{{ $loop->even ? 'background:#fafafa;' : '' }}"
                        onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background='{{ $loop->even ? '#fafafa' : '#fff' }}'">
                        <td style="padding:0.75rem 1rem;white-space:nowrap;">
                            <div style="font-weight:600;color:#1e293b;">{{ $ap->created_at?->format('d/m/Y H:i') ?? '—' }}</div>
                            <div style="font-family:monospace;font-size:0.72rem;color:#64748b;">{{ $ap->kode_approval }}</div>
                        </td>
                        <td style="padding:0.75rem 1rem;">
                            <div style="font-weight:600;color:#1e293b;">{{ $ap->pengaju?->name ?? 'Teller' }}</div>
                            <div style="font-size:0.75rem;color:#94a3b8;">{{ $ap->pengaju?->email ?? '' }}</div>
                        </td>
                        <td style="padding:0.75rem 1rem;">
                            <div style="font-weight:600;color:#1e293b;">{{ $namaAnggota }}</div>
                            <div style="font-size:0.75rem;font-family:monospace;color:#64748b;">Rek: {{ $noRekening }}</div>
                        </td>
                        <td style="padding:0.75rem 1rem;">
                            <span style="display:inline-block;padding:0.2rem 0.5rem;border-radius:0.375rem;font-size:0.75rem;font-weight:600;
                                         background:{{ str_contains(strtolower($ap->jenis_transaksi), 'setor') ? '#dcfce7;color:#15803d;' : '#fef3c7;color:#b45309;' }}">
                                {{ ucwords(str_replace('_', ' ', $ap->jenis_transaksi)) }}
                            </span>
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:right;font-weight:700;color:#1e293b;white-space:nowrap;">
                            Rp {{ number_format($ap->nominal ?? 0, 0, ',', '.') }}
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:center;">
                            <span style="display:inline-block;padding:0.2rem 0.625rem;border-radius:9999px;
                                         font-size:0.72rem;font-weight:600;
                                         background:{{ $badge['bg'] }};color:{{ $badge['color'] }};">
                                {{ $badge['label'] }}
                            </span>
                            @if($ap->catatan_approver)
                            <div style="font-size:0.7rem;color:#dc2626;margin-top:0.25rem;">
                                {{ $ap->catatan_approver }}
                            </div>
                            @endif
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:center;white-space:nowrap;">
                            @php $peranPemutus = \App\Support\HierarkiRole::peranUntukStatus($ap->status); @endphp
                            @if(!empty($peranPemutus) && in_array(auth()->user()->role, $peranPemutus, true))
                            <div style="display:inline-flex;gap:0.375rem;align-items:center;">
                                <form action="{{ route('pengelola.approval.approve', $ap->id) }}" method="POST" onsubmit="return confirm('Setujui transaksi over-limit ini dan potong saldo anggota secara otomatis?');" style="margin:0;">
                                    @csrf
                                    <button type="submit" style="padding:0.375rem 0.75rem;background:#059669;color:#fff;border:none;border-radius:0.375rem;font-size:0.75rem;font-weight:600;cursor:pointer;">
                                        Approve
                                    </button>
                                </form>
                                <button type="button" onclick="openRejectModal('{{ $ap->id }}', '{{ $ap->kode_approval }}', '{{ number_format($ap->nominal,0,',','.') }}')" style="padding:0.375rem 0.75rem;background:#dc2626;color:#fff;border:none;border-radius:0.375rem;font-size:0.75rem;font-weight:600;cursor:pointer;">
                                    Reject
                                </button>
                            </div>
                            @else
                            <span style="font-size:0.75rem;color:#94a3b8;font-style:italic;">Selesai</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($approvals->hasPages())
        <div style="padding:0.875rem 1.25rem;border-top:1px solid #f1f5f9;">{{ $approvals->links() }}</div>
        @endif
        @else
        <div style="padding:3.5rem 1.5rem;text-align:center;">
            <div style="width:64px;height:64px;border-radius:50%;background:#fef3c7;
                        display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.75rem;">🛡️</div>
            <div style="font-size:0.95rem;font-weight:600;color:#1e293b;margin-bottom:0.375rem;">Tidak ada transaksi over-limit yang menunggu</div>
            <div style="font-size:0.825rem;color:#64748b;">Semua transaksi Teller berada dalam batas kewenangan normal atau telah diproses.</div>
        </div>
        @endif
    </div>
</div>

{{-- ── REJECT MODAL ──────────────────────────────────── --}}
<div id="rejectModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);z-index:9999;align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;border-radius:0.875rem;max-width:440px;width:100%;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);overflow:hidden;">
        <div style="padding:1.25rem;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;">
            <h3 style="margin:0;font-size:1.05rem;font-weight:700;color:#1e293b;">Tolak Transaksi Over-limit</h3>
            <button onclick="closeRejectModal()" style="background:none;border:none;font-size:1.25rem;cursor:pointer;color:#94a3b8;">&times;</button>
        </div>
        <form id="rejectForm" method="POST" action="">
            @csrf
            <div style="padding:1.25rem;display:flex;flex-direction:column;gap:1rem;">
                <div style="font-size:0.825rem;color:#475569;">
                    Kode: <strong id="modalKode" style="font-family:monospace;color:#1e293b;"></strong><br>
                    Nominal: <strong id="modalNominal" style="color:#dc2626;"></strong>
                </div>
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:600;color:#334155;margin-bottom:0.375rem;" for="catatan">Alasan Penolakan <span style="color:#dc2626;">*</span></label>
                    <textarea id="catatan" name="catatan" rows="3" required placeholder="Masukkan alasan penolakan untuk Teller..."
                              style="width:100%;padding:0.625rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.825rem;outline:none;box-sizing:border-box;"></textarea>
                </div>
            </div>
            <div style="padding:1rem 1.25rem;background:#f8fafc;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:0.625rem;">
                <button type="button" onclick="closeRejectModal()" style="padding:0.5rem 1rem;background:#e2e8f0;color:#475569;border:none;border-radius:0.5rem;font-size:0.825rem;font-weight:600;cursor:pointer;">
                    Batal
                </button>
                <button type="submit" style="padding:0.5rem 1.125rem;background:#dc2626;color:#fff;border:none;border-radius:0.5rem;font-size:0.825rem;font-weight:600;cursor:pointer;">
                    Tolak Transaksi
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openRejectModal(id, kode, nominal) {
    const form = document.getElementById('rejectForm');
    form.action = `/pengelola/approval/${id}/reject`;
    document.getElementById('modalKode').textContent = kode;
    document.getElementById('modalNominal').textContent = 'Rp ' + nominal;
    const modal = document.getElementById('rejectModal');
    modal.style.display = 'flex';
}
function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
}
</script>
@endsection
