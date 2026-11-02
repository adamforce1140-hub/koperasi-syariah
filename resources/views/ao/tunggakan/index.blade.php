@extends('layouts.app')
@section('page-title', "Monitoring Tunggakan")

@section('title', 'Monitoring Tunggakan')

@section('styles')
<style>
    /* Kolom catatan pada tabel */
    .catatan-cell { font-size: .8rem; color: #475569; max-width: 230px; }
    .catatan-latest { display: block; line-height: 1.35; }
    .catatan-meta {
        display: inline-block; margin-top: .25rem; font-size: .7rem; font-weight: 600;
        color: #047857; background: #ecfdf5; border: 1px solid #a7f3d0;
        padding: .05rem .4rem; border-radius: 9999px;
    }

    /* Tombol aksi catatan */
    .btn-catatan {
        display: inline-flex; align-items: center; gap: .35rem;
        padding: .45rem .75rem; font-size: .78rem; font-weight: 600;
        color: #047857; background: #ecfdf5; border: 1px solid #a7f3d0;
        border-radius: 6px; cursor: pointer; white-space: nowrap;
    }
    .btn-catatan:hover { background: #d1fae5; }

    /* Modal catatan penagihan */
    .modal-overlay {
        display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, .5);
        z-index: 1000; align-items: center; justify-content: center; padding: 1rem;
    }
    .modal-overlay.open { display: flex; }
    .modal-box {
        background: #fff; border-radius: 1rem; padding: 1.5rem;
        width: 540px; max-width: 100%; max-height: 90vh; overflow-y: auto;
    }
    .modal-title { font-size: 1.05rem; font-weight: 700; color: #065f46; margin-bottom: .35rem; }
    .modal-sub { font-size: .8rem; color: #64748b; margin-bottom: 1rem; line-height: 1.4; }
    .modal-label {
        display: block; font-size: .75rem; font-weight: 700; color: #475569;
        text-transform: uppercase; letter-spacing: .03em; margin-bottom: .4rem;
    }
    .riwayat-list {
        display: flex; flex-direction: column; gap: .6rem;
        max-height: 240px; overflow-y: auto; margin-bottom: 1.25rem;
        background: #f8fafc; border: 1px solid #e2e8f0; border-radius: .6rem; padding: .75rem;
    }
    .riwayat-item {
        background: #fff; border: 1px solid #e2e8f0; border-left: 3px solid #059669;
        border-radius: .45rem; padding: .55rem .7rem;
    }
    .riwayat-head {
        display: flex; justify-content: space-between; gap: .5rem;
        font-size: .72rem; color: #64748b; margin-bottom: .2rem;
    }
    .riwayat-body { font-size: .82rem; color: #334155; line-height: 1.4; white-space: pre-wrap; }
    .riwayat-empty { font-size: .8rem; color: #94a3b8; text-align: center; padding: .5rem 0; }

    .modal-box textarea {
        width: 100%; border: 1.5px solid #e2e8f0; border-radius: .5rem; padding: .65rem;
        font-size: .85rem; font-family: inherit; resize: vertical; min-height: 90px; outline: none;
    }
    .modal-box textarea:focus { border-color: #059669; }
    .modal-footer {
        display: flex; align-items: center; justify-content: flex-end;
        gap: .75rem; margin-top: 1rem;
    }
    .modal-footer .btn {
        padding: .55rem 1.1rem; font-size: .85rem; font-weight: 600;
        border: none; border-radius: .5rem; cursor: pointer;
    }
</style>
@endsection

@section('content')
<div class="page-header">
    <h1 class="page-title">Monitoring Tunggakan</h1>
    <p class="page-subtitle">Daftar angsuran yang terlambat bayar</p>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

<div class="data-card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <span class="card-title">Daftar Tunggakan ({{ $tunggakan->total() }})</span>
        <form method="GET" style="display:flex;gap:.5rem;">
            <input type="text" name="search" value="{{ $search }}"
                placeholder="Cari nama / no. anggota..."
                style="padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;width:240px;">
            <button type="submit" class="btn btn-secondary" style="font-size:.85rem;padding:.5rem .9rem;">Cari</button>
        </form>
    </div>

    <table>
        <thead>
            <tr>
                <th>No. Pembiayaan</th>
                <th>Anggota</th>
                <th>Angsuran Ke</th>
                <th>Jatuh Tempo</th>
                <th>Hari Terlambat</th>
                <th>Status</th>
                <th>Jumlah Tagihan</th>
                <th>Catatan</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($tunggakan as $item)
            @php
                $hariTerlambat = $item->hari_terlambat_dinamis;
                $badge         = $item->status_badge;
                $badgeStyle    = [
                    'red'    => 'background:#fee2e2;color:#991b1b;',
                    'yellow' => 'background:#fef3c7;color:#92400e;',
                    'green'  => 'background:#d1fae5;color:#065f46;',
                    'gray'   => 'background:#f1f5f9;color:#475569;',
                ][$badge['color']] ?? 'background:#f1f5f9;color:#475569;';

                $jumlahRiwayat = $item->catatanPenagihan->count();
            @endphp
            <tr>
                <td><strong>{{ $item->pembiayaan?->no_pembiayaan ?? '-' }}</strong></td>
                <td>
                    <div>{{ $item->pembiayaan?->anggota?->nama_lengkap ?? '-' }}</div>
                    <div style="font-size:.75rem;color:#6b7280;">{{ $item->pembiayaan?->anggota?->no_hp }}</div>
                </td>
                <td style="text-align:center;">{{ $item->angsuran_ke }}</td>
                <td>{{ $item->tanggal_jatuh_tempo?->format('d/m/Y') ?? '-' }}</td>
                <td style="text-align:center;">
                    <span style="font-weight:700;color:{{ $hariTerlambat > 30 ? '#ef4444' : '#f59e0b' }};">
                        Terlambat {{ $hariTerlambat }} Hari
                    </span>
                </td>
                <td>
                    <span style="padding:.2rem .6rem;border-radius:12px;font-size:.78rem;font-weight:600;{{ $badgeStyle }}">
                        {{ $badge['label'] }}
                    </span>
                </td>
                <td>Rp {{ number_format($item->jumlah_angsuran ?? 0, 0, ',', '.') }}</td>
                <td class="catatan-cell">
                    @if($item->catatan)
                        <span class="catatan-latest">{{ \Illuminate\Support\Str::limit($item->catatan, 70) }}</span>
                    @else
                        <span style="color:#cbd5e1;">Belum ada catatan</span>
                    @endif
                    @if($jumlahRiwayat > 0)
                        <span class="catatan-meta">🗒 {{ $jumlahRiwayat }} riwayat</span>
                    @endif
                </td>
                <td>
                    <button type="button" class="btn-catatan" onclick="bukaModalCatatan({{ $item->id }})">
                        ✎ Catatan
                    </button>
                </td>
            </tr>
            @empty
            <tr><td colspan="9" style="text-align:center;color:#94a3b8;padding:2rem;">Tidak ada tunggakan</td></tr>
            @endforelse
        </tbody>
    </table>
    @if($tunggakan->hasPages())
    <div style="padding:1rem;">{{ $tunggakan->links() }}</div>
    @endif
</div>

{{-- Modal Catatan Penagihan (satu per baris) --}}
@foreach($tunggakan as $item)
<div class="modal-overlay" id="modalCatatan-{{ $item->id }}">
    <div class="modal-box">
        <div class="modal-title">📝 Catatan Penagihan</div>
        <div class="modal-sub">
            {{ $item->pembiayaan?->no_pembiayaan ?? '-' }} ·
            {{ $item->pembiayaan?->anggota?->nama_lengkap ?? '-' }} ·
            Angsuran ke-{{ $item->angsuran_ke }} ·
            Terlambat {{ $item->hari_terlambat_dinamis }} hari
        </div>

        <span class="modal-label">Riwayat Penagihan ({{ $item->catatanPenagihan->count() }})</span>
        <div class="riwayat-list">
            @forelse($item->catatanPenagihan as $c)
                <div class="riwayat-item">
                    <div class="riwayat-head">
                        <strong>{{ $c->user?->name ?? 'Sistem' }}</strong>
                        <span>{{ $c->created_at?->format('d/m/Y H:i') ?? '-' }}</span>
                    </div>
                    <div class="riwayat-body">{{ $c->catatan }}</div>
                </div>
            @empty
                <div class="riwayat-empty">Belum ada catatan penagihan untuk angsuran ini.</div>
            @endforelse
        </div>

        <form method="POST" action="{{ route('ao.tunggakan.catatan', $item->id) }}">
            @csrf
            <label class="modal-label" for="catatan-{{ $item->id }}">Catatan Baru</label>
            <textarea id="catatan-{{ $item->id }}" name="catatan" rows="3" maxlength="1000" required
                placeholder="Tulis hasil penagihan, mis. nasabah berjanji bayar tanggal ..."></textarea>
            <div class="modal-footer">
                <button type="button" class="btn" style="background:#f1f5f9;color:#475569;"
                    onclick="tutupModalCatatan({{ $item->id }})">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Catatan</button>
            </div>
        </form>
    </div>
</div>
@endforeach
@endsection

@section('scripts')
<script>
    function bukaModalCatatan(id) {
        document.getElementById('modalCatatan-' + id).classList.add('open');
    }

    function tutupModalCatatan(id) {
        document.getElementById('modalCatatan-' + id).classList.remove('open');
    }

    // Klik di luar modal untuk menutup
    document.querySelectorAll('.modal-overlay').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (e.target === this) this.classList.remove('open');
        });
    });

    // Tutup dengan tombol Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.open')
                .forEach(el => el.classList.remove('open'));
        }
    });
</script>
@endsection
