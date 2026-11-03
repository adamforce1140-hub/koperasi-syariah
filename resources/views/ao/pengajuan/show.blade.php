@extends('layouts.app')
@section('page-title', "Detail Pengajuan")

@section('title', 'Detail Pengajuan')

@section('content')
<div class="page-header">
    <h1 class="page-title">Detail Pengajuan</h1>
    <p class="page-subtitle">{{ $item->no_pengajuan ?? $item->no_pembiayaan ?? '-' }}</p>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif

<div style="display:grid;grid-template-columns:2fr 1fr;gap:1.5rem;">

    {{-- Detail Pengajuan --}}
    <div>
        <div class="data-card" style="margin-bottom:1.5rem;">
            <div class="card-header"><span class="card-title">Informasi Pengajuan</span></div>
            <div style="padding:1.5rem;">
                <table style="width:100%;border-collapse:collapse;">
                    @php
                        $rows = [
                            'No. Pengajuan'   => $item->no_pengajuan ?? $item->no_pembiayaan ?? '-',
                            'Produk / Akad'   => ($item->produk?->nama_produk ?? '-') . ' / ' . ($item->jenis_akad ?? $item->akad_pembiayaan ?? '-'),
                            'Jumlah'          => 'Rp ' . number_format($item->jumlah_pengajuan ?? $item->jumlah_pembiayaan ?? 0, 0, ',', '.'),
                            'Tenor'           => ($item->tenor ?? '-') . ' bulan',
                            'Tujuan'          => $item->tujuan ?? $item->tujuan_pembiayaan ?? '-',
                            'Agunan'          => $item->agunan ?? '-',
                            'Status'          => $item->status ?? $item->status_pembiayaan ?? '-',
                            'Tanggal Input'   => $item->created_at?->format('d/m/Y H:i') ?? '-',
                        ];
                    @endphp
                    @foreach($rows as $label => $val)
                    <tr style="border-bottom:1px solid #f3f4f6;">
                        <td style="padding:.6rem 0;font-size:.85rem;color:#6b7280;width:160px;">{{ $label }}</td>
                        <td style="padding:.6rem 0;font-size:.9rem;font-weight:500;">{{ $val }}</td>
                    </tr>
                    @endforeach
                </table>
            </div>
        </div>

        {{-- Analisis 5C --}}
        @if($item->analisis_5c)
        <div class="data-card" style="margin-bottom:1.5rem;">
            <div class="card-header"><span class="card-title">Hasil Analisis 5C</span></div>
            <div style="padding:1.5rem;">
                @php $c = is_array($item->analisis_5c) ? $item->analisis_5c : json_decode($item->analisis_5c, true); @endphp
                <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:1rem;text-align:center;margin-bottom:1rem;">
                    @foreach(['character'=>'Character','capacity'=>'Capacity','capital'=>'Capital','collateral'=>'Collateral','condition'=>'Condition'] as $key => $label)
                    <div style="padding:.8rem;background:#f9fafb;border-radius:8px;">
                        <div style="font-size:1.5rem;font-weight:700;color:#0ea5e9;">{{ $c[$key] ?? '-' }}</div>
                        <div style="font-size:.7rem;color:#6b7280;">{{ $label }}</div>
                    </div>
                    @endforeach
                </div>
                <div style="text-align:center;font-size:1rem;font-weight:600;color:#166534;">
                    Skor Total: {{ $c['skor_total'] ?? array_sum(array_intersect_key($c ?? [], array_flip(['character','capacity','capital','collateral','condition']))) }} / 25
                </div>
                @if($item->catatan_survei)
                <div style="margin-top:1rem;padding:.8rem;background:#fafafa;border-radius:6px;font-size:.85rem;color:#374151;">
                    <strong>Catatan Survei:</strong> {{ $item->catatan_survei }}
                </div>
                @endif
            </div>
        </div>
        @endif

        {{-- Dokumen --}}
        @if($item->dokumen && $item->dokumen->count())
        <div class="data-card">
            <div class="card-header"><span class="card-title">Dokumen Terlampir</span></div>
            <div style="padding:1rem;">
                @foreach($item->dokumen as $dok)
                <div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem 0;border-bottom:1px solid #f3f4f6;">
                    <div style="font-size:.85rem;">{{ $dok->nama_dokumen ?? $dok->nama_file ?? '-' }}</div>
                    @if($dok->path_file ?? $dok->path ?? null)
                    <a href="{{ route('berkas.unduh', ['path' => $dok->path_file ?? $dok->path]) }}" target="_blank"
                        class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .6rem;">Lihat</a>
                    @endif
                </div>
                @endforeach
            </div>
        </div>
        @endif
    </div>

    {{-- Sidebar: Anggota + Aksi --}}
    <div>
        <div class="data-card" style="margin-bottom:1.5rem;">
            <div class="card-header"><span class="card-title">Data Anggota</span></div>
            <div style="padding:1.5rem;">
                <div style="font-weight:600;font-size:1rem;">{{ $item->anggota?->nama_lengkap ?? '-' }}</div>
                <div style="font-size:.85rem;color:#6b7280;margin-top:.3rem;">{{ $item->anggota?->no_anggota }}</div>
                <div style="font-size:.85rem;color:#6b7280;">NIK: {{ $item->anggota?->nik ?? '-' }}</div>
                <div style="font-size:.85rem;color:#6b7280;">HP: {{ $item->anggota?->no_hp ?? '-' }}</div>
                <div style="font-size:.85rem;color:#6b7280;margin-top:.3rem;">{{ $item->anggota?->alamat ?? '-' }}</div>
            </div>
        </div>

        @php
            $status = $item->status ?? \App\Models\Pembiayaan::normalizeStatus($item->status_pembiayaan) ?? '';
            $canAct = in_array(strtolower($status), ['menunggu', 'pending', 'pending_ao', 'direview', 'menunggu_approval_manajer']);
        @endphp

        @if($canAct)
        <div class="data-card">
            <div class="card-header"><span class="card-title">Tindakan</span></div>
            <div style="padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
                <form method="POST" action="{{ route('ao.pengajuan.approve', $item->id) }}">
                    @csrf
                    <textarea name="catatan_ao" rows="3" placeholder="Catatan (opsional)..." aria-label="Catatan AO"
                        style="width:100%;padding:.6rem;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;margin-bottom:.6rem;"></textarea>
                    <button type="submit" class="btn btn-primary" style="width:100%;">Teruskan ke Manajer</button>
                </form>

                <form method="POST" action="{{ route('ao.pengajuan.reject', $item->id) }}">
                    @csrf
                    <textarea name="catatan_tolak" rows="3" placeholder="Alasan penolakan..." required aria-label="Alasan penolakan"
                        style="width:100%;padding:.6rem;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;margin-bottom:.6rem;"></textarea>
                    <button type="submit" class="btn btn-danger" style="width:100%;"
                        onclick="return confirm('Tolak pengajuan ini?')">Tolak Pengajuan</button>
                </form>
            </div>
        </div>
        @endif

        <div style="margin-top:1rem;">
            <a href="{{ route('ao.pengajuan.index') }}" class="btn btn-secondary" style="width:100%;text-align:center;">Kembali ke Daftar</a>
        </div>
    </div>
</div>
@endsection
