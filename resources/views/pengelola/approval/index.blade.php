@extends('layouts.app')
@section('page-title', "Approval Transaksi")

@section('title', 'Approval Transaksi')

@section('content')
<div class="page-header">
    <h1 class="page-title">Approval Transaksi
        @if($pendingCount > 0)
            <span style="display:inline-block;margin-left:.5rem;background:#dc2626;color:#fff;font-size:.75rem;font-weight:700;padding:.15rem .55rem;border-radius:9999px;vertical-align:middle;">
                {{ $pendingCount }}
            </span>
        @endif
    </h1>
    <p class="page-subtitle">Transaksi yang memerlukan persetujuan</p>
</div>

@if(session('success'))
<div class="alert-success">{{ session('success') }}</div>
@endif
@if(session('error'))
<div class="alert-error">{{ session('error') }}</div>
@endif

<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <span>Daftar Pengajuan</span>
        <form method="GET" style="display:flex;gap:0.5rem;">
            @php
                // Opsi status mengikuti cakupan peran: pengurus memegang seluruh
                // rantai, penyetuju lain hanya tahapnya sendiri. Tanpa ini menu
                // menampilkan tahap yang memang tidak dapat ia lihat.
                $peranPengguna = auth()->user()->role;
                $statusTersedia = auth()->user()->isPengurus()
                    ? \App\Support\HierarkiRole::semuaStatusMenunggu()
                    : array_filter([\App\Support\HierarkiRole::penyetuju($peranPengguna)
                        ? \App\Support\HierarkiRole::statusMenunggu($peranPengguna) : null]);
            @endphp
            <select name="status" onchange="this.form.submit()" style="padding:0.4rem 0.7rem;border-radius:6px;border:1px solid rgba(255,255,255,0.15);background:rgba(255,255,255,0.08);color:white;font-size:0.85rem;">
                <option value="">Semua Status</option>
                @foreach($statusTersedia as $statusMenunggu)
                    <option value="{{ $statusMenunggu }}" {{ request('status')===$statusMenunggu?'selected':'' }}>
                        Menunggu {{ \App\Support\HierarkiRole::label(\App\Support\HierarkiRole::peranUntukStatus($statusMenunggu)[0] ?? '') }}
                    </option>
                @endforeach
                <option value="APPROVED" {{ request('status')==='APPROVED'?'selected':'' }}>Disetujui</option>
                <option value="REJECTED" {{ request('status')==='REJECTED'?'selected':'' }}>Ditolak</option>
            </select>
        </form>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Kode Approval</th>
                    <th>Jenis Transaksi</th>
                    <th>Nominal</th>
                    <th>Diajukan Oleh</th>
                    <th>Disetujui Oleh</th>
                    <th>Status</th>
                    <th>Tanggal</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($approvals as $apr)
                <tr>
                    <td><code style="font-size:0.8rem;">{{ $apr->kode_approval }}</code></td>
                    <td>{{ str_replace('_',' ', ucwords($apr->jenis_transaksi)) }}</td>
                    <td>Rp {{ number_format($apr->nominal,0,',','.') }}</td>
                    <td>{{ $apr->pengaju->name ?? '-' }} <small style="color:#64748b;">({{ $apr->role_pengaju }})</small></td>
                    <td>
                        @if($apr->approver)
                            <strong>{{ $apr->approver->name }}</strong><br>
                            <small style="color:#64748b;">{{ $apr->role_approver }}</small><br>
                            <small style="color:#94a3b8;">{{ $apr->approved_at?->format('d/m/Y H:i') }}</small>
                        @else
                            <span style="color:#94a3b8;">-</span>
                        @endif
                    </td>
                    <td>
                        @php $peranPemutus = \App\Support\HierarkiRole::peranUntukStatus($apr->status); @endphp
                        @if(!empty($peranPemutus)) <span class="badge badge-warning">Menunggu {{ \App\Support\HierarkiRole::label($peranPemutus[0]) }}</span>
                        @elseif($apr->status==='APPROVED') <span class="badge badge-green">Disetujui</span>
                        @else <span class="badge badge-red">Ditolak</span>
                        @endif
                    </td>
                    <td style="font-size:0.8rem;">{{ $apr->created_at->format('d/m/Y H:i') }}</td>
                    <td style="white-space:nowrap;">
                        <a href="{{ route('pengelola.approval.show', $apr) }}" class="btn-sm btn-primary">Detail</a>
                        @php $peranBerwenang = \App\Support\HierarkiRole::peranUntukStatus($apr->status); @endphp
                        @if(!empty($peranBerwenang) && in_array(auth()->user()->role, $peranBerwenang, true))
                            <form method="POST" action="{{ route('pengelola.approval.approve', $apr) }}" style="display:inline;">
                                @csrf
                                <button type="submit" class="btn-sm btn-success" onclick="return confirm('Setujui transaksi ini?')">Setujui</button>
                            </form>
                            <button onclick="openTolakModal({{ $apr->id }},'{{ $apr->kode_approval }}','{{ route('pengelola.approval.reject', $apr) }}')"
                                class="btn-sm" style="background:#dc2626;color:#fff;border:none;border-radius:4px;padding:.3rem .7rem;font-size:.78rem;cursor:pointer;">
                                Tolak
                            </button>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" style="text-align:center;color:#64748b;padding:2rem;">Tidak ada data approval</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div style="padding:1rem;">{{ $approvals->links() }}</div>
</div>
{{-- Modal Tolak --}}
<div id="modalTolak" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#1e293b;border-radius:8px;padding:1.5rem;width:420px;max-width:95vw;">
        <h3 style="margin:0 0 1rem;color:#f1f5f9;">Tolak Transaksi</h3>
        <p style="color:#94a3b8;font-size:.9rem;">Kode: <strong id="tolakKode" style="color:#f1f5f9;"></strong></p>
        <form method="POST" id="formTolak">
            @csrf
            @method('POST')
            <div style="margin-bottom:1rem;">
                <label style="display:block;color:#94a3b8;font-size:.85rem;margin-bottom:.4rem;" for="catatan">Alasan Penolakan <span style="color:#ef4444;">*</span></label>
                <textarea id="catatan" name="catatan" rows="3" required
                    style="width:100%;padding:.6rem;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.15);border-radius:6px;color:#f1f5f9;font-size:.9rem;resize:vertical;"
                    placeholder="Masukkan alasan penolakan..."></textarea>
            </div>
            <div style="display:flex;gap:.7rem;justify-content:flex-end;">
                <button type="button" onclick="closeTolakModal()"
                    style="padding:.5rem 1rem;background:transparent;border:1px solid rgba(255,255,255,.2);border-radius:6px;color:#94a3b8;cursor:pointer;">
                    Batal
                </button>
                <button type="submit"
                    style="padding:.5rem 1rem;background:#dc2626;border:none;border-radius:6px;color:#fff;cursor:pointer;">
                    Tolak Transaksi
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openTolakModal(id, kode, url) {
    document.getElementById('tolakKode').textContent = kode;
    document.getElementById('formTolak').action = url;
    document.getElementById('modalTolak').style.display = 'flex';
}
function closeTolakModal() {
    document.getElementById('modalTolak').style.display = 'none';
}
</script>
@endsection
