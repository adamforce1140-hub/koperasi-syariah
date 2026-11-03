@extends('layouts.app')

@section('title', "Keringanan Ta'zir — Manajer")
@section('page-title', "Keringanan Ta'zir")

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- ── HEADER ─────────────────────────────────────── --}}
    <div>
        <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
            Keringanan Ta'zir (Denda Keterlambatan)
        </h1>
        <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
            Persetujuan pengajuan pembebasan ta'zir yang diajukan oleh Teller.
        </p>
    </div>

    {{-- ── FLASH ───────────────────────────────────────── --}}
    @foreach(['success'=>['#ecfdf5','#a7f3d0','#065f46','✅'], 'warning'=>['#fffbeb','#fde68a','#92400e','⚠️'], 'error'=>['#fef2f2','#fecaca','#991b1b','❌']] as $type=>$s)
    @if(session($type))
    <div style="padding:0.875rem 1.25rem;background:{{ $s[0] }};border:1px solid {{ $s[1] }};border-radius:0.625rem;color:{{ $s[2] }};font-size:0.875rem;display:flex;align-items:center;gap:0.5rem;">
        <span>{{ $s[3] }}</span> {{ session($type) }}
    </div>
    @endif
    @endforeach

    {{-- ── STAT CARDS ──────────────────────────────────── --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;">
        @php
        $cards = [
            ['label' => 'Menunggu Approval', 'value' => $totalPending,   'color' => '#d97706', 'bg' => '#fef3c7', 'icon' => '⏳'],
            ['label' => 'Disetujui',          'value' => $totalApproved,  'color' => '#059669', 'bg' => '#d1fae5', 'icon' => '✅'],
            ['label' => 'Ditolak',            'value' => $totalRejected,  'color' => '#dc2626', 'bg' => '#fee2e2', 'icon' => '❌'],
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
    <form method="GET" action="{{ route('manajer.keringanan-tazir.index') }}"
          style="display:flex;gap:0.625rem;flex-wrap:wrap;align-items:flex-end;">
        <input type="text" name="search" value="{{ $search }}" aria-label="Cari nama / no anggota / no pembiayaan" placeholder="Cari nama / no anggota / no pembiayaan…"
               style="flex:1;min-width:220px;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                      border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;"
               onfocus="this.style.borderColor='#047857'" onblur="this.style.borderColor='#cbd5e1'">
        <select name="status"
                style="padding:0.5rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;
                       font-size:0.825rem;color:#1e293b;background:#fff;outline:none;"
                onfocus="this.style.borderColor='#047857'" onblur="this.style.borderColor='#cbd5e1'">
            <option value="">Semua Status</option>
            <option value="PENDING"  {{ $status === 'PENDING'  ? 'selected' : '' }}>Menunggu</option>
            <option value="APPROVED" {{ $status === 'APPROVED' ? 'selected' : '' }}>Disetujui</option>
            <option value="REJECTED" {{ $status === 'REJECTED' ? 'selected' : '' }}>Ditolak</option>
        </select>
        <button type="submit"
                style="padding:0.5rem 1.125rem;background:#047857;color:#fff;font-size:0.825rem;
                       font-weight:600;border:none;border-radius:0.5rem;cursor:pointer;"
                onmouseover="this.style.background='#065f46'" onmouseout="this.style.background='#047857'">
            Filter
        </button>
        @if($search || $status)
        <a href="{{ route('manajer.keringanan-tazir.index') }}"
           style="padding:0.5rem 1rem;background:#f1f5f9;color:#475569;font-size:0.825rem;
                  font-weight:600;border-radius:0.5rem;text-decoration:none;">
            Reset
        </a>
        @endif
    </form>

    {{-- ── TABEL ────────────────────────────────────────── --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;
                overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        @if($keringanans->count())
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Tgl Pengajuan</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Anggota</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">No Pembiayaan</th>
                        <th style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#475569;white-space:nowrap;">Tazir Hitung</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Alasan Teller</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Diajukan Oleh</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;white-space:nowrap;">Status</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;white-space:nowrap;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($keringanans as $k)
                @php
                $statusStyle = match($k->status) {
                    'PENDING'  => 'background:#fef3c7;color:#92400e;',
                    'APPROVED' => 'background:#d1fae5;color:#065f46;',
                    'REJECTED' => 'background:#fee2e2;color:#991b1b;',
                    default    => '',
                };
                $statusLabel = match($k->status) {
                    'PENDING'  => '⏳ Menunggu',
                    'APPROVED' => '✅ Disetujui',
                    'REJECTED' => '❌ Ditolak',
                    default    => $k->status,
                };
                @endphp
                <tr style="border-bottom:1px solid #f1f5f9;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                    <td style="padding:0.75rem 1rem;color:#475569;white-space:nowrap;">
                        {{ $k->created_at->format('d/m/Y H:i') }}
                    </td>
                    <td style="padding:0.75rem 1rem;">
                        <div style="font-weight:600;color:#1e293b;">{{ $k->anggota->nama_lengkap ?? '-' }}</div>
                        <div style="font-size:0.75rem;color:#64748b;">{{ $k->anggota->no_anggota ?? '-' }}</div>
                    </td>
                    <td style="padding:0.75rem 1rem;color:#475569;white-space:nowrap;">
                        {{ $k->pembiayaan->no_pembiayaan ?? '-' }}
                        <div style="font-size:0.75rem;color:#94a3b8;">Ang. ke-{{ $k->angsuran->angsuran_ke ?? '?' }}</div>
                    </td>
                    <td style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#dc2626;white-space:nowrap;">
                        Rp {{ number_format($k->nominal_tazir_hitung, 0, ',', '.') }}
                    </td>
                    <td style="padding:0.75rem 1rem;color:#475569;max-width:220px;">
                        <div style="white-space:normal;line-height:1.4;">{{ Str::limit($k->alasan_pengajuan, 80) }}</div>
                    </td>
                    <td style="padding:0.75rem 1rem;color:#475569;white-space:nowrap;">
                        {{ $k->diajukanOleh->name ?? '-' }}
                    </td>
                    <td style="padding:0.75rem 1rem;text-align:center;">
                        <span style="padding:0.25rem 0.625rem;border-radius:9999px;font-size:0.75rem;font-weight:600;{{ $statusStyle }}">
                            {{ $statusLabel }}
                        </span>
                        @if($k->diputuskan_at)
                        <div style="font-size:0.7rem;color:#94a3b8;margin-top:0.2rem;">{{ $k->diputuskan_at->format('d/m/Y') }}</div>
                        @endif
                    </td>
                    <td style="padding:0.75rem 1rem;text-align:center;white-space:nowrap;">
                        @if($k->isPending())
                        {{-- APPROVE --}}
                        <button onclick="openApprove({{ $k->id }}, '{{ addslashes($k->anggota->nama_lengkap ?? '') }}', '{{ number_format($k->nominal_tazir_hitung, 0, ',', '.') }}')"
                                style="padding:0.35rem 0.75rem;background:#059669;color:#fff;border:none;border-radius:0.4rem;
                                       font-size:0.775rem;font-weight:600;cursor:pointer;margin-right:0.25rem;"
                                onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'">
                            Setujui
                        </button>
                        {{-- REJECT --}}
                        <button onclick="openReject({{ $k->id }}, '{{ addslashes($k->anggota->nama_lengkap ?? '') }}', '{{ number_format($k->nominal_tazir_hitung, 0, ',', '.') }}')"
                                style="padding:0.35rem 0.75rem;background:#dc2626;color:#fff;border:none;border-radius:0.4rem;
                                       font-size:0.775rem;font-weight:600;cursor:pointer;"
                                onmouseover="this.style.background='#b91c1c'" onmouseout="this.style.background='#dc2626'">
                            Tolak
                        </button>
                        @else
                        <span style="font-size:0.775rem;color:#94a3b8;">
                            {{ $k->diputuskanOleh->name ?? '-' }}
                        </span>
                        @if($k->catatan_manajer)
                        <div style="font-size:0.7rem;color:#64748b;max-width:140px;white-space:normal;margin-top:0.2rem;">
                            {{ Str::limit($k->catatan_manajer, 50) }}
                        </div>
                        @endif
                        @endif
                    </td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        {{-- PAGINATION --}}
        @if($keringanans->hasPages())
        <div style="padding:0.875rem 1rem;border-top:1px solid #f1f5f9;">
            {{ $keringanans->links() }}
        </div>
        @endif

        @else
        <div style="padding:3rem;text-align:center;color:#94a3b8;">
            <div style="font-size:2rem;margin-bottom:0.5rem;">📋</div>
            <div style="font-weight:600;color:#64748b;">Tidak ada pengajuan keringanan ta'zir</div>
            <div style="font-size:0.825rem;margin-top:0.25rem;">
                @if($search || $status) Coba ubah filter pencarian. @else Belum ada pengajuan dari Teller. @endif
            </div>
        </div>
        @endif
    </div>

</div>

{{-- ── MODAL APPROVE ─────────────────────────────────────── --}}
<div id="modal-approve" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:1rem;padding:2rem;max-width:440px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.2);">
        <h3 style="margin:0 0 0.5rem;font-size:1.1rem;font-weight:700;color:#1e293b;">Setujui Keringanan Ta'zir</h3>
        <p style="font-size:0.85rem;color:#64748b;margin:0 0 1rem;">
            Anggota: <strong id="approve-nama"></strong><br>
            Ta'zir dihitung: <strong style="color:#dc2626;">Rp <span id="approve-nominal"></span></strong><br>
            Jika disetujui, denda anggota akan menjadi <strong style="color:#059669;">Rp 0</strong>.
        </p>
        <form id="form-approve" method="POST">
            @csrf
            <div style="margin-bottom:1rem;">
                <label style="font-size:0.825rem;font-weight:600;color:#374151;display:block;margin-bottom:0.35rem;" for="catatan_manajer">
                    Catatan Manajer <span style="font-weight:400;color:#94a3b8;">(opsional)</span>
                </label>
                <textarea id="catatan_manajer" name="catatan_manajer" rows="3" placeholder="Misal: disetujui karena musibah kesehatan…"
                          style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;
                                 font-size:0.825rem;resize:vertical;outline:none;box-sizing:border-box;"
                          onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'"></textarea>
            </div>
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button type="button" onclick="closeModals()"
                        style="padding:0.5rem 1.25rem;background:#f1f5f9;color:#475569;border:none;
                               border-radius:0.5rem;font-weight:600;font-size:0.825rem;cursor:pointer;">
                    Batal
                </button>
                <button type="submit"
                        style="padding:0.5rem 1.25rem;background:#059669;color:#fff;border:none;
                               border-radius:0.5rem;font-weight:600;font-size:0.825rem;cursor:pointer;"
                        onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'">
                    Ya, Setujui
                </button>
            </div>
        </form>
    </div>
</div>

{{-- ── MODAL REJECT ──────────────────────────────────────── --}}
<div id="modal-reject" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:1rem;padding:2rem;max-width:440px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.2);">
        <h3 style="margin:0 0 0.5rem;font-size:1.1rem;font-weight:700;color:#1e293b;">Tolak Keringanan Ta'zir</h3>
        <p style="font-size:0.85rem;color:#64748b;margin:0 0 1rem;">
            Anggota: <strong id="reject-nama"></strong><br>
            Ta'zir dihitung: <strong style="color:#dc2626;">Rp <span id="reject-nominal"></span></strong><br>
            Jika ditolak, denda <strong>tetap berlaku</strong> sesuai kalkulasi.
        </p>
        <form id="form-reject" method="POST">
            @csrf
            <div style="margin-bottom:1rem;">
                <label style="font-size:0.825rem;font-weight:600;color:#374151;display:block;margin-bottom:0.35rem;" for="catatan_manajer_tolak">
                    Alasan Penolakan <span style="color:#dc2626;">*</span>
                </label>
                <textarea id="catatan_manajer_tolak" name="catatan_manajer" rows="3" required placeholder="Jelaskan alasan penolakan…"
                          style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;
                                 font-size:0.825rem;resize:vertical;outline:none;box-sizing:border-box;"
                          onfocus="this.style.borderColor='#dc2626'" onblur="this.style.borderColor='#cbd5e1'"></textarea>
            </div>
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button type="button" onclick="closeModals()"
                        style="padding:0.5rem 1.25rem;background:#f1f5f9;color:#475569;border:none;
                               border-radius:0.5rem;font-weight:600;font-size:0.825rem;cursor:pointer;">
                    Batal
                </button>
                <button type="submit"
                        style="padding:0.5rem 1.25rem;background:#dc2626;color:#fff;border:none;
                               border-radius:0.5rem;font-weight:600;font-size:0.825rem;cursor:pointer;"
                        onmouseover="this.style.background='#b91c1c'" onmouseout="this.style.background='#dc2626'">
                    Ya, Tolak
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// URL aksi diturunkan dari nama rute (bukan hardcode) agar tetap benar
// bila aplikasi dipasang di subdirektori atau domain berbeda.
const URL_APPROVE = "{{ route('manajer.keringanan-tazir.approve', ['relief' => '__ID__']) }}";
const URL_REJECT  = "{{ route('manajer.keringanan-tazir.reject', ['relief' => '__ID__']) }}";

function openApprove(id, nama, nominal) {
    document.getElementById('approve-nama').textContent    = nama;
    document.getElementById('approve-nominal').textContent = nominal;
    document.getElementById('form-approve').action =
        URL_APPROVE.replace('__ID__', encodeURIComponent(id));
    const m = document.getElementById('modal-approve');
    m.style.display = 'flex';
}
function openReject(id, nama, nominal) {
    document.getElementById('reject-nama').textContent    = nama;
    document.getElementById('reject-nominal').textContent = nominal;
    document.getElementById('form-reject').action =
        URL_REJECT.replace('__ID__', encodeURIComponent(id));
    const m = document.getElementById('modal-reject');
    m.style.display = 'flex';
}
function closeModals() {
    document.getElementById('modal-approve').style.display = 'none';
    document.getElementById('modal-reject').style.display  = 'none';
}
// Tutup modal klik overlay
['modal-approve','modal-reject'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) closeModals();
    });
});
</script>
@endsection
