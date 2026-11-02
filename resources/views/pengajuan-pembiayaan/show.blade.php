@extends('layouts.app')
@section('page-title', "Detail Pengajuan Pembiayaan")

@section('title', 'Detail Pengajuan Pembiayaan')

@section('content')
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
    <div>
        <h1 class="page-title">Detail Pengajuan Pembiayaan</h1>
        <p class="page-subtitle">{{ $pembiayaan->no_pembiayaan }} — {{ $pembiayaan->akad_pembiayaan }}</p>
    </div>
    <a href="{{ route('pengajuan-pembiayaan.index') }}"
        style="padding:.5rem 1rem;background:#6b7280;color:#fff;border-radius:7px;text-decoration:none;font-size:.85rem;">
        ← Kembali
    </a>
</div>

@if(session('success'))
    <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:.8rem 1.1rem;border-radius:8px;margin-bottom:1rem;">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.8rem 1.1rem;border-radius:8px;margin-bottom:1rem;">{{ session('error') }}</div>
@endif
@if(session('info'))
    <div style="background:#dbeafe;border:1px solid #93c5fd;color:#1e40af;padding:.8rem 1.1rem;border-radius:8px;margin-bottom:1rem;">{{ session('info') }}</div>
@endif

@php
// Agunan deposito bersifat opsional: sebagian controller/pemanggil tidak
// mengirimkannya, jadi nilai bawaan null agar view tidak gagal render.
$depositoAgunan = $depositoAgunan ?? null;
$statusLabel = match(strtolower($pembiayaan->status_pembiayaan)) {
    'pengajuan','pending_ao','pending','pending_manajer','pending_pengurus','pending_dps' => ['text'=>'Menunggu Review','bg'=>'#fef3c7','color'=>'#92400e'],
    'direview' => ['text'=>'Sedang Direview','bg'=>'#dbeafe','color'=>'#1e40af'],
    'disetujui' => ['text'=>'Disetujui','bg'=>'#d1fae5','color'=>'#065f46'],
    'dicairkan' => ['text'=>'Dicairkan','bg'=>'#d1fae5','color'=>'#065f46'],
    'ditolak'   => ['text'=>'Ditolak','bg'=>'#fee2e2','color'=>'#991b1b'],
    default     => ['text'=>ucfirst($pembiayaan->status_pembiayaan),'bg'=>'#f3f4f6','color'=>'#374151'],
};
@endphp

<div style="display:grid;grid-template-columns:1.3fr 1fr;gap:1.4rem;align-items:start;">

{{-- ── Kolom Kiri: Info Pembiayaan ── --}}
<div>

{{-- Data Pokok --}}
<div class="data-card" style="margin-bottom:1.2rem;">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <span class="card-title">Informasi Pembiayaan</span>
        <span style="background:{{ $statusLabel['bg'] }};color:{{ $statusLabel['color'] }};padding:.3rem .8rem;border-radius:20px;font-size:.8rem;font-weight:600;">
            {{ $statusLabel['text'] }}
            @if($pembiayaan->is_low_risk)
                &nbsp;·&nbsp;<span style="color:#065f46;">🛡 Low Risk</span>
            @endif
        </span>
    </div>
    <div style="padding:1rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:.7rem;font-size:.87rem;">
        @php
        $rows = [
            ['No. Pembiayaan',  $pembiayaan->no_pembiayaan],
            ['Akad',            $pembiayaan->akad_pembiayaan],
            ['Tujuan',          $pembiayaan->tujuan_pembiayaan],
            ['Nominal Pokok',   'Rp '.number_format($pembiayaan->jumlah_pembiayaan,0,',','.')],
            ['Total Margin',    'Rp '.number_format($pembiayaan->total_ujroh ?? 0,0,',','.')],
            ['Total Pembiayaan','Rp '.number_format($pembiayaan->total_pembiayaan,0,',','.')],
            ['Tenor',           $pembiayaan->tenor.' Bulan'],
            ['Angsuran/Bulan',  'Rp '.number_format($pembiayaan->jumlah_angsuran,0,',','.')],
            ['Margin/Nisbah',   $pembiayaan->margin_nisbah.'% / tahun'],
            ['Tanggal Akad',    $pembiayaan->tanggal_akad?->format('d/m/Y') ?? '-'],
        ];
        @endphp
        @foreach($rows as [$label,$val])
        <div>
            <div style="color:#6b7280;font-size:.78rem;">{{ $label }}</div>
            <div style="font-weight:600;color:#111827;">{{ $val }}</div>
        </div>
        @endforeach
    </div>
</div>

{{-- Data Anggota --}}
<div class="data-card" style="margin-bottom:1.2rem;">
    <div class="card-header"><span class="card-title">Data Anggota / CIF</span></div>
    <div style="padding:1rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:.7rem;font-size:.87rem;">
        @php
        $aRows = [
            ['Nama Lengkap', $pembiayaan->anggota?->nama_lengkap ?? '-'],
            ['No. CIF / Anggota', ($pembiayaan->anggota?->no_cif ?? '-').' / '.($pembiayaan->anggota?->no_anggota ?? '-')],
            ['Status Anggota', $pembiayaan->anggota?->status_label ?? '-'],
            ['No. HP', $pembiayaan->anggota?->no_hp ?? '-'],
        ];
        @endphp
        @foreach($aRows as [$label,$val])
        <div>
            <div style="color:#6b7280;font-size:.78rem;">{{ $label }}</div>
            <div style="font-weight:600;color:#111827;">{{ $val }}</div>
        </div>
        @endforeach
    </div>
</div>

{{-- Agunan & Deposito Back-to-Back --}}
<div class="data-card" style="margin-bottom:1.2rem;">
    <div class="card-header"><span class="card-title">Agunan / Jaminan</span></div>
    <div style="padding:1rem;font-size:.87rem;">
        <div style="margin-bottom:.6rem;">
            <span style="color:#6b7280;font-size:.78rem;">Tipe Agunan</span><br>
            <strong>{{ $pembiayaan->agunan_tipe ?? 'Tidak ada / belum diisi' }}</strong>
        </div>

        @if($depositoAgunan)
        <div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:8px;padding:.8rem 1rem;margin-top:.5rem;">
            <div style="font-weight:700;color:#1d4ed8;margin-bottom:.5rem;">Deposito Back-to-Back</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:.5rem;">
                <div><div style="color:#6b7280;font-size:.75rem;">No. Rekening Deposito</div><div style="font-weight:600;">{{ $depositoAgunan->no_rekening }}</div></div>
                <div><div style="color:#6b7280;font-size:.75rem;">Saldo Deposito</div><div style="font-weight:600;color:#065f46;">Rp {{ number_format($depositoAgunan->saldo,0,',','.') }}</div></div>
                <div><div style="color:#6b7280;font-size:.75rem;">Status</div>
                    <div>
                        @if($depositoAgunan->is_locked)
                            <span style="background:#fee2e2;color:#991b1b;padding:.2rem .5rem;border-radius:4px;font-size:.75rem;font-weight:600;">🔒 Terkunci</span>
                        @else
                            <span style="background:#d1fae5;color:#065f46;padding:.2rem .5rem;border-radius:4px;font-size:.75rem;font-weight:600;">Aktif</span>
                        @endif
                    </div>
                </div>
                <div><div style="color:#6b7280;font-size:.75rem;">Jatuh Tempo</div><div style="font-weight:600;">{{ $depositoAgunan->tanggal_jatuh_tempo?->format('d/m/Y') ?? '-' }}</div></div>
            </div>
            @if($depositoAgunan->locked_reason)
                <div style="margin-top:.5rem;font-size:.76rem;color:#1d4ed8;">{{ $depositoAgunan->locked_reason }}</div>
            @endif
        </div>
        @else
            <div style="color:#6b7280;font-size:.82rem;">{{ $pembiayaan->agunan ?? $pembiayaan->keterangan ?? '-' }}</div>
        @endif
    </div>
</div>

{{-- Jadwal Angsuran --}}
@if($pembiayaan->angsuran->count())
<div class="data-card">
    <div class="card-header"><span class="card-title">Jadwal Angsuran ({{ $pembiayaan->angsuran->count() }} Bulan)</span></div>
    <div style="overflow-x:auto;max-height:320px;">
        <table style="width:100%;border-collapse:collapse;font-size:.8rem;">
            <thead>
                <tr style="background:#f9fafb;border-bottom:2px solid #e5e7eb;position:sticky;top:0;">
                    <th style="padding:.5rem .7rem;text-align:center;color:#374151;">ke-</th>
                    <th style="padding:.5rem .7rem;text-align:center;color:#374151;">Jatuh Tempo</th>
                    <th style="padding:.5rem .7rem;text-align:right;color:#374151;">Pokok</th>
                    <th style="padding:.5rem .7rem;text-align:right;color:#374151;">Margin</th>
                    <th style="padding:.5rem .7rem;text-align:right;color:#374151;">Total</th>
                    <th style="padding:.5rem .7rem;text-align:center;color:#374151;">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($pembiayaan->angsuran as $a)
                @php
                $aColor = match($a->status_angsuran) {
                    'Sudah Dibayar' => '#d1fae5',
                    'Terlambat'     => '#fee2e2',
                    'Jatuh Tempo'   => '#fef3c7',
                    default         => 'transparent',
                };
                @endphp
                <tr style="border-bottom:1px solid #f3f4f6;background:{{ $aColor }};">
                    <td style="padding:.45rem .7rem;text-align:center;font-weight:600;">{{ $a->angsuran_ke }}</td>
                    <td style="padding:.45rem .7rem;text-align:center;">{{ \Carbon\Carbon::parse($a->tanggal_jatuh_tempo)->format('d/m/Y') }}</td>
                    <td style="padding:.45rem .7rem;text-align:right;">{{ number_format($a->pokok_angsuran,0,',','.') }}</td>
                    <td style="padding:.45rem .7rem;text-align:right;">{{ number_format($a->margin_ujroh,0,',','.') }}</td>
                    <td style="padding:.45rem .7rem;text-align:right;font-weight:600;">{{ number_format($a->jumlah_angsuran,0,',','.') }}</td>
                    <td style="padding:.45rem .7rem;text-align:center;font-size:.75rem;">{{ $a->status_angsuran }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

</div>{{-- end kolom kiri --}}

{{-- ── Kolom Kanan: Approval Workflow ── --}}
<div>

{{-- Stepper Status --}}
<div class="data-card" style="margin-bottom:1.2rem;">
    <div class="card-header"><span class="card-title">Alur Persetujuan</span></div>
    <div style="padding:1rem;">
        @php
        $steps = [
            ['label'=>'Pengajuan AO','done'=> true,'user'=> null,'tgl'=> $pembiayaan->tanggal_akad?->format('d/m/Y')],
            ['label'=>'Review Manajer','done'=> !in_array(strtolower($pembiayaan->status_pembiayaan),['pengajuan','pending_ao','pending','pending_manajer']),
                'user'=> $pembiayaan->reviewedBy?->name,'tgl'=> $pembiayaan->tanggal_direview?->format('d/m/Y')],
            ['label'=>'Persetujuan Pengurus','done'=> in_array(strtolower($pembiayaan->status_pembiayaan),['disetujui','dicairkan']),
                'user'=> $pembiayaan->approvedFinalBy?->name,'tgl'=> $pembiayaan->tanggal_disetujui?->format('d/m/Y')],
            ['label'=>'Pencairan Dana','done'=> strtolower($pembiayaan->status_pembiayaan)==='dicairkan',
                'user'=> null,'tgl'=> $pembiayaan->tgl_pencairan],
        ];
        @endphp
        @foreach($steps as $i => $step)
        <div style="display:flex;align-items:flex-start;gap:.8rem;margin-bottom:{{ $loop->last ? '0' : '.8rem' }};">
            <div style="width:28px;height:28px;border-radius:50%;background:{{ $step['done'] ? '#10b981' : '#e5e7eb' }};
                color:{{ $step['done'] ? '#fff' : '#9ca3af' }};display:flex;align-items:center;justify-content:center;
                font-size:.8rem;font-weight:700;flex-shrink:0;">
                {{ $step['done'] ? '✓' : ($i+1) }}
            </div>
            <div>
                <div style="font-weight:600;font-size:.85rem;color:{{ $step['done'] ? '#111827' : '#9ca3af' }};">{{ $step['label'] }}</div>
                @if($step['user'] || $step['tgl'])
                    <div style="font-size:.75rem;color:#6b7280;">
                        {{ $step['user'] ? 'Oleh: '.$step['user'].' ' : '' }}{{ $step['tgl'] ? '('.$step['tgl'].')' : '' }}
                    </div>
                @endif
            </div>
        </div>
        @endforeach
    </div>
</div>

{{-- Catatan Manajer --}}
@if($pembiayaan->catatan_manajer)
<div class="data-card" style="margin-bottom:1.2rem;border-left:3px solid #3b82f6;">
    <div style="padding:.8rem 1rem;font-size:.85rem;">
        <div style="font-weight:600;color:#1d4ed8;margin-bottom:.3rem;">Catatan Manajer</div>
        <div>{{ $pembiayaan->catatan_manajer }}</div>
    </div>
</div>
@endif

{{-- Catatan Pengurus --}}
@if($pembiayaan->catatan_pengurus)
<div class="data-card" style="margin-bottom:1.2rem;border-left:3px solid #10b981;">
    <div style="padding:.8rem 1rem;font-size:.85rem;">
        <div style="font-weight:600;color:#065f46;margin-bottom:.3rem;">Catatan Pengurus</div>
        <div>{{ $pembiayaan->catatan_pengurus }}</div>
    </div>
</div>
@endif

{{-- ═══ ACTION: MANAJER — Review & Tolak ═══ --}}
@if(auth()->user()->isManajer() && in_array(strtolower($pembiayaan->status_pembiayaan), ['pengajuan','pending_ao','pending','pending_manajer']))
<div class="data-card" style="margin-bottom:1rem;">
    <div class="card-header"><span class="card-title">Aksi Manajer</span></div>
    <div style="padding:1rem;">

        {{-- Review / Teruskan ke Pengurus --}}
        <form method="POST" action="{{ route('pengajuan-pembiayaan.review', $pembiayaan->id) }}" style="margin-bottom:1rem;">
            @csrf
            <label style="display:block;font-size:.82rem;font-weight:600;color:#374151;margin-bottom:.4rem;">Catatan (opsional)</label>
            <textarea name="catatan_manajer" rows="2" placeholder="Catatan review..."
                style="width:100%;padding:.5rem .7rem;border:1px solid #d1d5db;border-radius:6px;font-size:.83rem;resize:vertical;margin-bottom:.6rem;"></textarea>
            <button type="submit"
                style="width:100%;padding:.6rem;background:#3b82f6;color:#fff;border:none;border-radius:7px;font-size:.88rem;font-weight:600;cursor:pointer;">
                ✓ Direview — Teruskan ke Pengurus
            </button>
        </form>

        {{-- Tolak --}}
        <form method="POST" action="{{ route('pengajuan-pembiayaan.tolak-manajer', $pembiayaan->id) }}">
            @csrf
            <label style="display:block;font-size:.82rem;font-weight:600;color:#374151;margin-bottom:.4rem;">Alasan Penolakan <span style="color:#ef4444">*</span></label>
            <textarea name="catatan_manajer" rows="2" placeholder="Wajib diisi jika menolak..." required
                style="width:100%;padding:.5rem .7rem;border:1px solid #fca5a5;border-radius:6px;font-size:.83rem;resize:vertical;margin-bottom:.6rem;"></textarea>
            <button type="submit"
                style="width:100%;padding:.6rem;background:#ef4444;color:#fff;border:none;border-radius:7px;font-size:.88rem;font-weight:600;cursor:pointer;"
                onclick="return confirm('Yakin menolak pengajuan ini?')">
                ✗ Tolak Pengajuan
            </button>
        </form>
    </div>
</div>
@endif

{{-- ═══ ACTION: PENGURUS — Approve / Tolak ═══ --}}
@if(auth()->user()->isPengurus() && in_array(strtolower($pembiayaan->status_pembiayaan), ['pengajuan','pending_ao','direview','pending_pengurus']))
<div class="data-card" style="margin-bottom:1rem;">
    <div class="card-header">
        <span class="card-title">Aksi Pengurus</span>
        @if($pembiayaan->is_low_risk)
            <span style="background:#d1fae5;color:#065f46;padding:.2rem .6rem;border-radius:20px;font-size:.75rem;font-weight:600;margin-left:.5rem;">🛡 LOW RISK — Agunan Deposito</span>
        @endif
    </div>
    <div style="padding:1rem;">

        {{-- Ringkasan auto-journal yang akan di-generate --}}
        <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:.8rem;margin-bottom:1rem;font-size:.8rem;">
            <div style="font-weight:600;color:#166534;margin-bottom:.4rem;">Auto-Journaling yang Akan Dibuat:</div>
            <table style="width:100%;border-collapse:collapse;">
                <tr>
                    <td style="padding:.2rem .5rem;color:#374151;">DEBET</td>
                    <td style="padding:.2rem .5rem;font-weight:600;">
                        @php
                        $akunDebet = match($pembiayaan->akad_pembiayaan) {
                            'Murabahah'  => '1-2001 Piutang Murabahah',
                            'Mudharabah' => '1-2002 Pembiayaan Mudharabah',
                            'Musyarakah' => '1-2003 Pembiayaan Musyarakah',
                            'Ijarah'     => '1-2004 Piutang Ijarah',
                            'Istishna'   => '1-2006 Piutang Istishna',
                            'Salam'      => '1-2007 Piutang Salam',
                            'Qardh'      => '1-2005 Pinjaman Qardh',
                            // IMBT berakhir dengan perpindahan kepemilikan → turunan Murabahah.
                            'Ijarah Muntahia Bittamlik' => '1-2001 Piutang Murabahah',
                            default      => '1-2005 Pinjaman Qardh',
                        };
                        @endphp
                        {{ $akunDebet }}
                    </td>
                    <td style="padding:.2rem .5rem;text-align:right;font-weight:600;">Rp {{ number_format($pembiayaan->jumlah_pembiayaan,0,',','.') }}</td>
                </tr>
                <tr>
                    <td style="padding:.2rem .5rem;color:#374151;">KREDIT</td>
                    <td style="padding:.2rem .5rem;font-weight:600;">2-1001 Simpanan Sukarela Anggota</td>
                    <td style="padding:.2rem .5rem;text-align:right;font-weight:600;">Rp {{ number_format($pembiayaan->jumlah_pembiayaan,0,',','.') }}</td>
                </tr>
                @if($pembiayaan->akad_pembiayaan === 'Murabahah' && ($pembiayaan->total_ujroh ?? 0) > 0)
                <tr style="border-top:1px dashed #86efac;">
                    <td style="padding:.2rem .5rem;color:#374151;">KREDIT</td>
                    <td style="padding:.2rem .5rem;font-weight:600;">4-1001 Pendapatan Margin Ditangguhkan</td>
                    <td style="padding:.2rem .5rem;text-align:right;font-weight:600;">Rp {{ number_format($pembiayaan->total_ujroh,0,',','.') }}</td>
                </tr>
                @endif
            </table>
        </div>

        {{-- Approve --}}
        <form method="POST" action="{{ route('pengajuan-pembiayaan.approve', $pembiayaan->id) }}" style="margin-bottom:1rem;">
            @csrf
            <label style="display:block;font-size:.82rem;font-weight:600;color:#374151;margin-bottom:.4rem;">Catatan Persetujuan (opsional)</label>
            <textarea name="catatan_pengurus" rows="2" placeholder="Catatan pengurus..."
                style="width:100%;padding:.5rem .7rem;border:1px solid #d1d5db;border-radius:6px;font-size:.83rem;resize:vertical;margin-bottom:.6rem;"></textarea>
            <button type="submit"
                style="width:100%;padding:.65rem;background:#10b981;color:#fff;border:none;border-radius:7px;font-size:.9rem;font-weight:700;cursor:pointer;"
                onclick="return confirm('Setujui pembiayaan Rp {{ number_format($pembiayaan->jumlah_pembiayaan, 0, ',', '.') }}? Pencairan dilakukan oleh pengurus lain.')">
                ✓ Setujui Pembiayaan
            </button>
        </form>

        {{-- Tolak --}}
        <form method="POST" action="{{ route('pengajuan-pembiayaan.tolak-pengurus', $pembiayaan->id) }}">
            @csrf
            <label style="display:block;font-size:.82rem;font-weight:600;color:#374151;margin-bottom:.4rem;">Alasan Penolakan <span style="color:#ef4444">*</span></label>
            <textarea name="catatan_pengurus" rows="2" placeholder="Wajib diisi..." required
                style="width:100%;padding:.5rem .7rem;border:1px solid #fca5a5;border-radius:6px;font-size:.83rem;resize:vertical;margin-bottom:.6rem;"></textarea>
            <button type="submit"
                style="width:100%;padding:.6rem;background:#ef4444;color:#fff;border:none;border-radius:7px;font-size:.88rem;font-weight:600;cursor:pointer;"
                onclick="return confirm('Yakin menolak pengajuan ini?')">
                ✗ Tolak Pengajuan
            </button>
        </form>
    </div>
</div>
@endif

{{-- ACTION: PENCAIRAN — hanya untuk pengurus yang BUKAN penyetuju (GCG) --}}
@if(auth()->user()->isPengurus() && strtolower($pembiayaan->status_pembiayaan) === 'disetujui')
<div class="data-card" style="margin-bottom:1rem;">
    <div class="card-header">
        <span class="card-title">Pencairan Pembiayaan</span>
    </div>
    <div style="padding:1rem;font-size:.85rem;color:#374151;">
        <div style="margin-bottom:.6rem;">Pembiayaan sudah disetujui. Pencairan (kas keluar, jadwal angsuran, dan jurnal) hanya dapat dilakukan oleh pengurus yang berbeda dari penyetuju.</div>
        <form method="POST" action="{{ route('pengajuan-pembiayaan.cairkan', $pembiayaan->id) }}">
            @csrf
            <button type="submit"
                style="width:100%;padding:.65rem;background:#0d9488;color:#fff;border:none;border-radius:7px;font-size:.9rem;font-weight:700;cursor:pointer;"
                onclick="return confirm('Cairkan pembiayaan Rp {{ number_format($pembiayaan->jumlah_pembiayaan, 0, ',', '.') }}? Jadwal angsuran & jurnal akan dibuat otomatis.')">
                Cairkan Pembiayaan
            </button>
        </form>
    </div>
</div>
@endif

{{-- Info: sudah dicairkan --}}
@if(strtolower($pembiayaan->status_pembiayaan) === 'dicairkan')
<div class="data-card" style="border-left:4px solid #10b981;">
    <div style="padding:1rem;font-size:.87rem;">
        <div style="font-weight:700;color:#065f46;font-size:1rem;margin-bottom:.4rem;">✓ Pembiayaan Sudah Dicairkan</div>
        <div style="color:#374151;">Dicairkan pada: <strong>{{ $pembiayaan->tgl_pencairan ?? $pembiayaan->tanggal_cair }}</strong></div>
        @if($pembiayaan->rekening_id)
        <div style="color:#374151;margin-top:.3rem;">Ke rekening: <strong>{{ optional(App\Models\Rekening::find($pembiayaan->rekening_id))->no_rekening }}</strong></div>
        @endif
        <div style="margin-top:.5rem;color:#065f46;font-size:.82rem;">
            Jurnal double-entry, mutasi rekening, dan jadwal angsuran {{ $pembiayaan->tenor }} bulan telah di-generate otomatis.
        </div>
    </div>
</div>
@endif

{{-- Info: ditolak --}}
@if(strtolower($pembiayaan->status_pembiayaan) === 'ditolak')
<div class="data-card" style="border-left:4px solid #ef4444;">
    <div style="padding:1rem;font-size:.87rem;">
        <div style="font-weight:700;color:#991b1b;margin-bottom:.3rem;">✗ Pengajuan Ditolak</div>
        <div>{{ $pembiayaan->catatan_manajer ?? $pembiayaan->catatan_pengurus ?? $pembiayaan->keterangan ?? '-' }}</div>
    </div>
</div>
@endif

</div>{{-- end kolom kanan --}}
</div>{{-- end grid --}}

@endsection
