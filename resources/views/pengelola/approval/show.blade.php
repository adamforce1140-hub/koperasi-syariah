@extends('layouts.app')
@section('page-title', "Detail Approval")
@section('title', 'Detail Approval')
@section('content')
<div style="max-width:700px;margin:0 auto;">

    <div style="margin-bottom:1rem;">
        <a href="{{ route('pengelola.approval.index') }}"
            style="color:#2563eb;text-decoration:none;font-size:.9rem;">&larr; Kembali ke Daftar</a>
    </div>

    @if(session('success'))
    <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
        {{ session('success') }}
    </div>
    @endif
    @if(session('error'))
    <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
        {{ session('error') }}
    </div>
    @endif

    {{-- Header status --}}
    <div class="data-card" style="margin-bottom:1.5rem;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <span class="card-title">Detail Approval Transaksi</span>
            @php
                $statusColor = match($approval->status) {
                    'APPROVED'        => ['bg'=>'#dcfce7','color'=>'#16a34a'],
                    'REJECTED'        => ['bg'=>'#fee2e2','color'=>'#dc2626'],
                    'PENDING_AO'      => ['bg'=>'#ede9fe','color'=>'#6d28d9'],
                    'PENDING_MANAJER' => ['bg'=>'#fef9c3','color'=>'#a16207'],
                    'PENDING_PENGURUS'=> ['bg'=>'#e0f2fe','color'=>'#0369a1'],
                    default           => ['bg'=>'#f1f5f9','color'=>'#64748b'],
                };
                $statusLabel = match(true) {
                    $approval->status === 'APPROVED' => 'Disetujui',
                    $approval->status === 'REJECTED' => 'Ditolak',
                    // Tahap menunggu diturunkan dari hierarki peran, sehingga
                    // tingkat otorisasi baru langsung tampil tanpa ubah view.
                    ! empty(\App\Support\HierarkiRole::peranUntukStatus($approval->status))
                        => 'Menunggu ' . \App\Support\HierarkiRole::label(
                            \App\Support\HierarkiRole::peranUntukStatus($approval->status)[0]
                        ),
                    default => $approval->status,
                };
            @endphp
            <span style="padding:.3rem .9rem;border-radius:9999px;font-size:.85rem;font-weight:700;
                background:{{ $statusColor['bg'] }};color:{{ $statusColor['color'] }};">
                {{ $statusLabel }}
            </span>
        </div>

        {{-- Info Transaksi --}}
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1rem;padding:1.25rem;">
            <div>
                <div style="font-size:.8rem;color:#64748b;margin-bottom:.2rem;">Kode Approval</div>
                <div style="font-family:monospace;font-weight:600;">{{ $approval->kode_approval }}</div>
            </div>
            <div>
                <div style="font-size:.8rem;color:#64748b;margin-bottom:.2rem;">Jenis Transaksi</div>
                <div style="font-weight:600;">{{ str_replace('_',' ', ucwords($approval->jenis_transaksi)) }}</div>
            </div>
            <div>
                <div style="font-size:.8rem;color:#64748b;margin-bottom:.2rem;">Nominal</div>
                <div style="font-weight:700;font-size:1.1rem;color:#2563eb;">
                    Rp {{ number_format($approval->nominal, 0, ',', '.') }}
                </div>
            </div>
            <div>
                <div style="font-size:.8rem;color:#64748b;margin-bottom:.2rem;">Jenis Pembayaran</div>
                <div>{{ ucfirst($approval->jenis_pembayaran) }}</div>
            </div>
            <div>
                <div style="font-size:.8rem;color:#64748b;margin-bottom:.2rem;">Tanggal Pengajuan</div>
                <div>{{ $approval->created_at->format('d/m/Y H:i:s') }}</div>
            </div>
            <div>
                <div style="font-size:.8rem;color:#64748b;margin-bottom:.2rem;">Catatan Pengaju</div>
                <div>{{ $approval->catatan_pengaju ?: '-' }}</div>
            </div>
        </div>

        {{-- Pengaju --}}
        <div style="border-top:1px solid #e2e8f0;padding:1.25rem;">
            <div style="font-size:.8rem;color:#64748b;font-weight:600;margin-bottom:.75rem;text-transform:uppercase;letter-spacing:.05em;">
                Diajukan Oleh
            </div>
            <div style="display:flex;align-items:center;gap:.75rem;">
                <div style="width:40px;height:40px;border-radius:50%;background:#2563eb;color:#fff;
                    display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1rem;">
                    {{ strtoupper(substr($approval->pengaju->name ?? 'U', 0, 1)) }}
                </div>
                <div>
                    <div style="font-weight:600;">{{ $approval->pengaju->name ?? '-' }}</div>
                    <div style="font-size:.85rem;color:#64748b;">
                        {{ ucfirst($approval->role_pengaju) }} &bull; {{ $approval->pengaju->email ?? '' }}
                    </div>
                    <div style="font-size:.8rem;color:#94a3b8;">
                        {{ $approval->created_at->format('d/m/Y') }} pukul {{ $approval->created_at->format('H:i:s') }}
                    </div>
                </div>
            </div>
        </div>

        {{-- Approver --}}
        <div style="border-top:1px solid #e2e8f0;padding:1.25rem;">
            <div style="font-size:.8rem;color:#64748b;font-weight:600;margin-bottom:.75rem;text-transform:uppercase;letter-spacing:.05em;">
                Disetujui / Ditolak Oleh
            </div>
            @if($approval->approver)
            <div style="display:flex;align-items:center;gap:.75rem;">
                <div style="width:40px;height:40px;border-radius:50%;
                    background:{{ $approval->status === 'APPROVED' ? '#16a34a' : ($approval->status === 'REJECTED' ? '#dc2626' : '#94a3b8') }};
                    color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1rem;">
                    {{ strtoupper(substr($approval->approver->name, 0, 1)) }}
                </div>
                <div>
                    <div style="font-weight:600;">{{ $approval->approver->name }}</div>
                    <div style="font-size:.85rem;color:#64748b;">
                        {{ ucfirst($approval->role_approver) }} &bull; {{ $approval->approver->email ?? '' }}
                    </div>
                    <div style="font-size:.8rem;color:#94a3b8;">
                        {{ $approval->approved_at?->format('d/m/Y') }} pukul {{ $approval->approved_at?->format('H:i:s') }}
                    </div>
                    @if($approval->catatan_approver)
                    <div style="margin-top:.4rem;padding:.5rem .75rem;background:#f8fafc;border-radius:6px;font-size:.85rem;border-left:3px solid {{ $approval->status === 'APPROVED' ? '#16a34a' : '#dc2626' }};">
                        "{{ $approval->catatan_approver }}"
                    </div>
                    @endif
                </div>
            </div>
            @else
            <div style="color:#94a3b8;font-size:.9rem;">Belum ada yang menyetujui/menolak.</div>
            @endif
        </div>

        {{-- Tombol approve/reject jika masih pending --}}
        @php $peranPemutus = \App\Support\HierarkiRole::peranUntukStatus($approval->status); @endphp
        @if(!empty($peranPemutus) && in_array(auth()->user()->role, $peranPemutus, true))
            <div style="border-top:1px solid #e2e8f0;padding:1.25rem;display:flex;gap:.75rem;flex-wrap:wrap;">
                <form method="POST" action="{{ route('pengelola.approval.approve', $approval) }}">
                    @csrf
                    <div style="margin-bottom:.5rem;">
                        <input type="text" name="catatan" placeholder="Catatan (opsional)"
                            style="padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;width:280px;">
                    </div>
                    <button type="submit" onclick="return confirm('Setujui transaksi ini?')"
                        style="background:#16a34a;color:#fff;border:none;padding:.55rem 1.5rem;border-radius:8px;cursor:pointer;font-weight:600;">
                        &#10003; Setujui
                    </button>
                </form>
                <form method="POST" action="{{ route('pengelola.approval.reject', $approval) }}">
                    @csrf
                    <div style="margin-bottom:.5rem;">
                        <input type="text" name="catatan" placeholder="Alasan penolakan (wajib)" required
                            style="padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;width:280px;">
                    </div>
                    <button type="submit" onclick="return confirm('Tolak transaksi ini?')"
                        style="background:#dc2626;color:#fff;border:none;padding:.55rem 1.5rem;border-radius:8px;cursor:pointer;font-weight:600;">
                        &#10007; Tolak
                    </button>
                </form>
            </div>
        @endif
    </div>
</div>
@endsection
