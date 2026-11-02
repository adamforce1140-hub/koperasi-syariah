@extends('layouts.app')
@section('page-title', "Pengajuan Pembiayaan")

@section('title', 'Pengajuan Pembiayaan')

@section('content')
<div class="page-header">
    <h1 class="page-title">Pengajuan Pembiayaan Nasabah</h1>
    <p class="page-subtitle">Review dan proses pengajuan pembiayaan</p>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('info'))
    <div class="alert alert-info" style="background:#dbeafe;border:1px solid #93c5fd;color:#1e40af;padding:.8rem 1rem;border-radius:6px;margin-bottom:1rem;">{{ session('info') }}</div>
@endif
@if(session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif

{{-- STAT CARDS --}}
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <div class="stat-card" style="background:#fef3c7;">
        <div style="font-size:.8rem;color:#92400e;">Menunggu Review</div>
        <div style="font-size:2rem;font-weight:700;color:#d97706;">{{ $stats['menunggu'] }}</div>
    </div>
    <div class="stat-card" style="background:#dbeafe;">
        <div style="font-size:.8rem;color:#1e40af;">Sedang Diproses</div>
        <div style="font-size:2rem;font-weight:700;color:#2563eb;">{{ $stats['diproses'] }}</div>
    </div>
    <div class="stat-card" style="background:#d1fae5;">
        <div style="font-size:.8rem;color:#065f46;">Disetujui</div>
        <div style="font-size:2rem;font-weight:700;color:#059669;">{{ $stats['disetujui'] }}</div>
    </div>
    <div class="stat-card" style="background:#fee2e2;">
        <div style="font-size:.8rem;color:#991b1b;">Ditolak</div>
        <div style="font-size:2rem;font-weight:700;color:#dc2626;">{{ $stats['ditolak'] }}</div>
    </div>
</div>

{{-- FILTER STATUS --}}
<div style="margin-bottom:1rem;display:flex;gap:.5rem;flex-wrap:wrap;">
    @foreach(['Menunggu','Diproses','Disetujui','Ditolak','semua'] as $s)
    <a href="{{ route('pengelola.pengajuan-pinjaman.index', ['status' => $s]) }}"
       style="padding:.4rem .9rem;border-radius:20px;font-size:.82rem;text-decoration:none;font-weight:600;
              {{ $status === $s ? 'background:#3b82f6;color:#fff;' : 'background:#f1f5f9;color:#475569;' }}">
        {{ $s === 'semua' ? 'Semua' : $s }}
    </a>
    @endforeach
</div>

{{-- TABEL PENGAJUAN --}}
<div class="data-card">
    <div class="card-header">
        <span class="card-title">Daftar Pengajuan — <span style="text-transform:capitalize;">{{ $status }}</span></span>
        <span style="font-size:.78rem;color:#94a3b8;">{{ $pengajuan->total() }} pengajuan</span>
    </div>
    <table>
        <thead>
            <tr>
                <th>No. Pengajuan</th>
                <th>Nasabah / CIF</th>
                <th>Produk</th>
                <th>Jumlah</th>
                <th>Tenor</th>
                <th>Tujuan</th>
                <th>Status</th>
                <th>Tanggal</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($pengajuan as $item)
            <tr>
                <td><strong>{{ $item->no_pembiayaan }}</strong></td>
                <td>
                    <div style="font-weight:600;">{{ $item->anggota?->nama_lengkap ?? '-' }}</div>
                    <div style="font-size:.75rem;color:#94a3b8;">{{ $item->anggota?->no_cif ?? '-' }}</div>
                </td>
                <td>{{ $item->akad_pembiayaan }}</td>
                <td><strong>Rp {{ number_format($item->jumlah_pembiayaan, 0, ',', '.') }}</strong></td>
                <td>{{ $item->tenor }} bln</td>
                <td style="max-width:150px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $item->tujuan_pembiayaan }}</td>
                <td>
                    @php $st = strtolower($item->status_pembiayaan); @endphp
                    @if(in_array($st, ['pengajuan', 'pending_ao', 'pending_manajer', 'pending_pengurus', 'pending_dps', 'pending']))
                        <span class="badge badge-amber">Menunggu Review</span>
                    @elseif(in_array($st, ['direview', 'dikaji']))
                        <span class="badge badge-blue">Direview</span>
                    @elseif(in_array($st, ['aktif', 'disetujui', 'disetujui_pengurus', 'disetujui_manajer', 'dicairkan']))
                        <span class="badge badge-green">Disetujui</span>
                    @elseif(in_array($st, ['ditolak']))
                        <span class="badge badge-red">Ditolak</span>
                    @else
                        <span class="badge">{{ $item->status_pembiayaan }}</span>
                    @endif
                </td>
                <td>{{ $item->created_at->format('d/m/Y') }}</td>
                <td>
                    <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                    @php
                        $role = auth()->user()->role ?? '';
                        // Daftar peran berwenang berasal dari config/role_hierarki.php.
                        $peranPeninjau = \App\Support\HierarkiRole::dalamKelompok($role, 'peninjau_pembiayaan');
                        $peranPemutus  = \App\Support\HierarkiRole::dalamKelompok($role, 'pemutus_pembiayaan');
                    @endphp

                    {{-- MANAJER: bisa review (Pengajuan) atau tolak --}}
                    @if($peranPeninjau && in_array($st, ['pengajuan', 'pending_ao', 'pending_manajer']))
                        <button onclick="openReview({{ $item->id }}, '{{ $item->no_pembiayaan }}')"
                            style="padding:.3rem .7rem;background:#2563eb;color:#fff;border:none;border-radius:4px;font-size:.78rem;cursor:pointer;">
                            Review
                        </button>
                        <button onclick="openTolak({{ $item->id }}, '{{ $item->no_pembiayaan }}')"
                            style="padding:.3rem .7rem;background:#dc2626;color:#fff;border:none;border-radius:4px;font-size:.78rem;cursor:pointer;">
                            Tolak
                        </button>

                    {{-- PENGURUS: bisa setujui final (Direview / Pending Pengurus) atau tolak --}}
                    @elseif($peranPemutus && in_array($st, ['direview', 'pending_pengurus']))
                        <button onclick="openApprove({{ $item->id }}, '{{ $item->no_pembiayaan }}')"
                            style="padding:.3rem .7rem;background:#059669;color:#fff;border:none;border-radius:4px;font-size:.78rem;cursor:pointer;">
                            Setujui
                        </button>
                        <button onclick="openTolak({{ $item->id }}, '{{ $item->no_pembiayaan }}')"
                            style="padding:.3rem .7rem;background:#dc2626;color:#fff;border:none;border-radius:4px;font-size:.78rem;cursor:pointer;">
                            Tolak
                        </button>

                    {{-- Status info untuk yang sudah selesai --}}
                    @elseif(in_array($st, ['aktif', 'disetujui', 'disetujui_pengurus', 'disetujui_manajer', 'dicairkan']))
                        <span style="font-size:.78rem;color:#059669;">&#10003; {{ $item->status_pembiayaan }}</span>
                    @elseif(in_array($st, ['ditolak']))
                        <span style="font-size:.78rem;color:#dc2626;">&#10007; {{ $item->keterangan ?? 'Ditolak' }}</span>
                    @elseif(in_array($st, ['direview', 'dikaji']) && $peranPeninjau)
                        <span style="font-size:.78rem;color:#6b7280;">Menunggu Pengurus</span>
                    @elseif(in_array($st, ['pengajuan', 'pending_ao', 'pending_dps']) && $peranPemutus)
                        <span style="font-size:.78rem;color:#6b7280;">Menunggu DPS/Review</span>
                    @else
                        <span style="font-size:.78rem;color:#6b7280;">-</span>
                    @endif
                    </div>
                </td>
            </tr>
            @empty
            <tr><td colspan="9" style="text-align:center;color:#94a3b8;padding:2rem;">Tidak ada pengajuan dengan status ini</td></tr>
            @endforelse
        </tbody>
    </table>
    @if($pengajuan->hasPages())
    <div style="padding:1rem;">{{ $pengajuan->links() }}</div>
    @endif
</div>

{{-- MODAL REVIEW (Manajer) --}}
<div id="modal-review" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:2rem;width:440px;max-width:95vw;">
        <h3 style="margin:0 0 1rem;color:#2563eb;">Review Pengajuan</h3>
        <p id="review-info" style="font-size:.9rem;color:#6b7280;margin-bottom:1rem;"></p>
        <p style="font-size:.85rem;color:#374151;margin-bottom:1rem;">Pengajuan akan diteruskan ke <strong>Pengurus</strong> untuk persetujuan final.</p>
        <form id="form-review" method="POST">
            @csrf
            @method('PATCH')
            <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:.4rem;">Catatan Manajer (opsional)</label>
            <textarea name="catatan_manajer" rows="3" placeholder="Misal: Sudah dicek kelayakan, layak diproses..." style="width:100%;padding:.6rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;resize:vertical;box-sizing:border-box;"></textarea>
            <div style="display:flex;gap:.8rem;margin-top:1.2rem;">
                <button type="submit" style="flex:1;padding:.7rem;background:#2563eb;color:#fff;border:none;border-radius:6px;font-weight:600;cursor:pointer;">Teruskan ke Pengurus</button>
                <button type="button" onclick="closeModal('modal-review')" style="flex:1;padding:.7rem;background:#f1f5f9;color:#374151;border:none;border-radius:6px;cursor:pointer;">Batal</button>
            </div>
        </form>
    </div>
</div>

{{-- MODAL SETUJUI --}}
<div id="modal-approve" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:2rem;width:440px;max-width:95vw;">
        <h3 style="margin:0 0 1rem;">Setujui Pengajuan</h3>
        <p id="approve-info" style="font-size:.9rem;color:#6b7280;margin-bottom:1rem;"></p>
        <form id="form-approve" method="POST">
            @csrf
            @method('PATCH')
            <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:.4rem;">Catatan (opsional)</label>
            <textarea name="catatan_pengelola" rows="3" placeholder="Misal: Disetujui sesuai plafon..." style="width:100%;padding:.6rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;resize:vertical;"></textarea>
            <div style="display:flex;gap:.8rem;margin-top:1.2rem;">
                <button type="submit" style="flex:1;padding:.7rem;background:#059669;color:#fff;border:none;border-radius:6px;font-weight:600;cursor:pointer;">Konfirmasi Setujui</button>
                <button type="button" onclick="closeModal('modal-approve')" style="flex:1;padding:.7rem;background:#f1f5f9;color:#374151;border:none;border-radius:6px;cursor:pointer;">Batal</button>
            </div>
        </form>
    </div>
</div>

{{-- MODAL TOLAK --}}
<div id="modal-tolak" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:2rem;width:440px;max-width:95vw;">
        <h3 style="margin:0 0 1rem;color:#dc2626;">Tolak Pengajuan</h3>
        <p id="tolak-info" style="font-size:.9rem;color:#6b7280;margin-bottom:1rem;"></p>
        <form id="form-tolak" method="POST">
            @csrf
            @method('PATCH')
            <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:.4rem;">Alasan Penolakan <span style="color:#ef4444">*</span></label>
            <textarea name="catatan_pengelola" rows="3" required placeholder="Misal: Riwayat angsuran kurang baik..." style="width:100%;padding:.6rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;resize:vertical;"></textarea>
            <div style="display:flex;gap:.8rem;margin-top:1.2rem;">
                <button type="submit" style="flex:1;padding:.7rem;background:#dc2626;color:#fff;border:none;border-radius:6px;font-weight:600;cursor:pointer;">Konfirmasi Tolak</button>
                <button type="button" onclick="closeModal('modal-tolak')" style="flex:1;padding:.7rem;background:#f1f5f9;color:#374151;border:none;border-radius:6px;cursor:pointer;">Batal</button>
            </div>
        </form>
    </div>
</div>

<script>
// URL aksi diturunkan dari nama rute (bukan hardcode) agar tetap benar
// bila aplikasi dipasang di subdirektori atau domain berbeda.
const URL_REVIEW  = "{{ route('pengelola.pengajuan-pinjaman.review', ['pengajuan' => '__ID__']) }}";
const URL_APPROVE = "{{ route('pengelola.pengajuan-pinjaman.approve', ['pengajuan' => '__ID__']) }}";
const URL_TOLAK   = "{{ route('pengelola.pengajuan-pinjaman.tolak', ['pengajuan' => '__ID__']) }}";

function urlAksi(template, id) {
    return template.replace('__ID__', encodeURIComponent(id));
}
function openReview(id, no) {
    document.getElementById('review-info').textContent = 'Pengajuan: ' + no;
    document.getElementById('form-review').action = urlAksi(URL_REVIEW, id);
    document.getElementById('modal-review').style.display = 'flex';
}
function openApprove(id, no) {
    document.getElementById('approve-info').textContent = 'Pengajuan: ' + no;
    document.getElementById('form-approve').action = urlAksi(URL_APPROVE, id);
    document.getElementById('modal-approve').style.display = 'flex';
}
function openTolak(id, no) {
    document.getElementById('tolak-info').textContent = 'Pengajuan: ' + no;
    document.getElementById('form-tolak').action = urlAksi(URL_TOLAK, id);
    document.getElementById('modal-tolak').style.display = 'flex';
}
function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}
</script>
@endsection
