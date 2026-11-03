@extends('layouts.app')

@section('title', 'Verifikasi Keringanan Penalti Deposito — AO')
@section('page-title', 'Verifikasi Keringanan Penalti Deposito')

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- ── HEADER ─────────────────────────────────────── --}}
    <div>
        <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
            Verifikasi Keringanan Penalti Deposito
        </h1>
        <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
            Tinjau pengajuan keringanan penalti pencairan dini deposito dari Teller dan berikan rekomendasi lapangan.
        </p>
    </div>

    {{-- ── FLASH ───────────────────────────────────────── --}}
    @foreach(['success'=>['#ecfdf5','#a7f3d0','#065f46','✅'], 'error'=>['#fef2f2','#fecaca','#991b1b','❌'], 'warning'=>['#fffbeb','#fde68a','#92400e','⚠️']] as $type=>$s)
    @if(session($type))
    <div style="padding:0.875rem 1.25rem;background:{{ $s[0] }};border:1px solid {{ $s[1] }};border-radius:0.625rem;color:{{ $s[2] }};font-size:0.875rem;display:flex;align-items:center;gap:0.5rem;">
        <span>{{ $s[3] }}</span> {{ session($type) }}
    </div>
    @endif
    @endforeach
    @if($errors->any())
    <div style="padding:0.875rem 1.25rem;background:#fef2f2;border:1px solid #fecaca;border-radius:0.625rem;color:#991b1b;font-size:0.875rem;">
        <ul style="margin:0;padding-left:1.1rem;">
            @foreach($errors->all() as $err) <li>{{ $err }}</li> @endforeach
        </ul>
    </div>
    @endif

    {{-- ── STAT CARDS ──────────────────────────────────── --}}
    @php
        $statCards = [
            ['label' => 'Menunggu Verifikasi AO', 'value' => $totalPendingAo,      'color' => '#f59e0b'],
            ['label' => 'Menunggu Manajer',       'value' => $totalPendingManajer, 'color' => '#2563eb'],
            ['label' => 'Disetujui',              'value' => $totalApproved,       'color' => '#16a34a'],
            ['label' => 'Ditolak',                'value' => $totalRejected,       'color' => '#dc2626'],
        ];
    @endphp
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;">
        @foreach($statCards as $card)
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:1rem 1.1rem;">
            <div style="font-size:.8rem;color:#64748b;margin-bottom:.35rem;">{{ $card['label'] }}</div>
            <div style="font-size:1.5rem;font-weight:700;color:{{ $card['color'] }};">{{ $card['value'] }}</div>
        </div>
        @endforeach
    </div>

    {{-- ── DAFTAR PENGAJUAN ────────────────────────────── --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <div style="padding:1rem 1.25rem;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;">
            <span style="font-weight:600;color:#1e293b;">Daftar Pengajuan ({{ $pengajuan->total() }})</span>
            <form method="GET" action="{{ route('ao.keringanan-penalti.index') }}" style="display:flex;gap:.5rem;flex-wrap:wrap;">
                <select name="status" style="padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;">
                    <option value="PENDING_AO"      {{ $status === 'PENDING_AO'      ? 'selected' : '' }}>Menunggu Verifikasi AO</option>
                    <option value="PENDING_MANAJER" {{ $status === 'PENDING_MANAJER' ? 'selected' : '' }}>Menunggu Manajer</option>
                    <option value="APPROVED"        {{ $status === 'APPROVED'        ? 'selected' : '' }}>Disetujui</option>
                    <option value="REJECTED"        {{ $status === 'REJECTED'        ? 'selected' : '' }}>Ditolak</option>
                </select>
                <input type="text" name="search" value="{{ $search }}" aria-label="Cari nama / no. anggota / bilyet"
                    placeholder="Cari nama / no. anggota / bilyet..."
                    style="padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;width:240px;">
                <button type="submit" class="btn btn-secondary" style="font-size:.85rem;padding:.5rem .9rem;">Filter</button>
            </form>
        </div>

        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">#</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Anggota</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">No. Bilyet</th>
                        <th style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#475569;">Penalti Awal</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Alasan Teller</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Rekomendasi</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;">Status</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($pengajuan as $item)
                @php
                    $badgeStyle = [
                        'yellow' => 'background:#fef3c7;color:#92400e;',
                        'green'  => 'background:#d1fae5;color:#065f46;',
                        'red'    => 'background:#fee2e2;color:#991b1b;',
                        'gray'   => 'background:#f1f5f9;color:#475569;',
                    ][$item->status_badge_color] ?? 'background:#f1f5f9;color:#475569;';
                @endphp
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:0.75rem 1rem;color:#64748b;">{{ $item->id }}</td>
                    <td style="padding:0.75rem 1rem;">
                        <div style="font-weight:600;color:#1e293b;">{{ $item->anggota?->nama_lengkap ?? '-' }}</div>
                        <div style="font-size:0.75rem;color:#64748b;">{{ $item->anggota?->no_anggota }}</div>
                    </td>
                    <td style="padding:0.75rem 1rem;color:#475569;">{{ $item->deposito?->deposito_number ?? '-' }}</td>
                    <td style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#b45309;white-space:nowrap;">
                        Rp {{ number_format((float) $item->nominal_penalti_awal, 0, ',', '.') }}
                    </td>
                    <td style="padding:0.75rem 1rem;font-size:.8rem;color:#475569;max-width:220px;">
                        {{ \Illuminate\Support\Str::limit($item->alasan_pengaju, 70) }}
                    </td>
                    <td style="padding:0.75rem 1rem;">
                        @if($item->rekomendasi_ao === 'SETUJU')
                            <span style="padding:.2rem .6rem;border-radius:12px;font-size:.78rem;font-weight:600;background:#d1fae5;color:#065f46;">SETUJU</span>
                        @elseif($item->rekomendasi_ao === 'TOLAK')
                            <span style="padding:.2rem .6rem;border-radius:12px;font-size:.78rem;font-weight:600;background:#fee2e2;color:#991b1b;">TOLAK</span>
                        @else
                            <span style="color:#94a3b8;">—</span>
                        @endif
                    </td>
                    <td style="padding:0.75rem 1rem;text-align:center;">
                        <span style="padding:.2rem .6rem;border-radius:12px;font-size:.78rem;font-weight:600;{{ $badgeStyle }}">
                            {{ $item->status_label }}
                        </span>
                    </td>
                    <td style="padding:0.75rem 1rem;text-align:center;white-space:nowrap;">
                        @if($item->isPendingAo())
                            <button type="button" class="btn btn-primary"
                                style="font-size:.75rem;padding:.35rem .7rem;"
                                onclick="openRekomendasi({{ $item->id }}, '{{ addslashes($item->anggota?->nama_lengkap ?? '-') }}', '{{ number_format((float) $item->nominal_penalti_awal, 0, ',', '.') }}')">
                                Beri Rekomendasi
                            </button>
                        @else
                            <span style="color:#94a3b8;font-size:.8rem;">Selesai</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:2rem;">Tidak ada pengajuan keringanan penalti deposito</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @if($pengajuan->hasPages())
        <div style="padding:1rem;border-top:1px solid #f1f5f9;">{{ $pengajuan->links() }}</div>
        @endif
    </div>

</div>

{{-- Modal Rekomendasi AO --}}
<div id="modal-rekomendasi" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;max-width:480px;width:92%;padding:1.5rem;">
        <h3 style="margin:0 0 .5rem;color:#1e293b;">Rekomendasi Keringanan Penalti</h3>
        <p id="rekomendasi-text" style="font-size:.9rem;color:#475569;margin-bottom:1rem;"></p>
        <form method="POST" id="form-rekomendasi">
            @csrf
            <label style="display:block;font-weight:600;margin-bottom:.35rem;font-size:.9rem;">
                Rekomendasi <span style="color:#dc2626;">*</span>
            </label>
            <div style="display:flex;gap:1rem;margin-bottom:1rem;">
                <label style="display:flex;align-items:center;gap:.4rem;font-size:.9rem;color:#065f46;">
                    <input type="radio" name="rekomendasi_ao" value="SETUJU" required> SETUJU (usulkan bebas penalti)
                </label>
                <label style="display:flex;align-items:center;gap:.4rem;font-size:.9rem;color:#991b1b;">
                    <input type="radio" name="rekomendasi_ao" value="TOLAK" required> TOLAK
                </label>
            </div>
            <label for="catatan_ao" style="display:block;font-weight:600;margin-bottom:.35rem;font-size:.9rem;">
                Catatan Analisis AO <span style="color:#dc2626;">*</span>
            </label>
            <textarea name="catatan_ao" id="catatan_ao" rows="3" required
                style="width:100%;padding:.6rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;resize:vertical;"
                placeholder="Hasil verifikasi lapangan & pertimbangan..."></textarea>
            <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Batal</button>
                <button type="submit" class="btn btn-primary">Kirim ke Manajer</button>
            </div>
        </form>
    </div>
</div>
@endsection

@section('scripts')
<script>
// URL aksi diturunkan dari nama rute (bukan hardcode) agar tetap benar
// bila aplikasi dipasang di subdirektori atau domain berbeda.
const URL_REKOMENDASI = "{{ route('ao.keringanan-penalti.rekomendasi', ['relief' => '__ID__']) }}";

function openRekomendasi(id, nama, nominal) {
    document.getElementById('rekomendasi-text').innerHTML =
        'Pengajuan keringanan untuk <strong>' + nama + '</strong> dengan penalti awal <strong>Rp ' + nominal +
        '</strong>. Rekomendasi akan diteruskan ke Manajer untuk keputusan akhir.';
    document.getElementById('form-rekomendasi').action = URL_REKOMENDASI.replace('__ID__', encodeURIComponent(id));
    document.getElementById('modal-rekomendasi').style.display = 'flex';
}
function closeModal() {
    document.getElementById('modal-rekomendasi').style.display = 'none';
}
</script>
@endsection
