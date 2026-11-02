@extends('layouts.app')

@section('title', 'Master Data Produk & Akad — Pengurus')
@section('page-title', 'Master Data Produk & Akad')

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- ── HEADER ─────────────────────────────────────── --}}
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
                Produk Pembiayaan & Akad
            </h1>
            <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
                Atur margin, tenor, dan jenis akad untuk setiap produk pembiayaan.
            </p>
        </div>
    </div>

    @if(session('success'))
    <div style="padding:0.875rem 1.25rem;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:0.625rem;color:#065f46;font-size:0.875rem;">
        ✅ {{ session('success') }}
    </div>
    @endif

    {{-- ── TABEL ────────────────────────────────────────── --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;
                overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        @if($produk->count())
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">#</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Nama Produk</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Jenis Akad</th>
                        <th style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#475569;">Margin / Nisbah</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;">Min Tenor</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;">Maks Tenor</th>
                        <th style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#475569;">Maks Plafon</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;">Status</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($produk as $p)
                    <tr style="border-bottom:1px solid #f1f5f9;{{ $loop->even ? 'background:#fafafa;' : '' }}"
                        onmouseover="this.style.background='#f0fdf4'" onmouseout="this.style.background='{{ $loop->even ? '#fafafa' : '#fff' }}'">
                        <td style="padding:0.75rem 1rem;color:#94a3b8;">
                            {{ ($produk->currentPage() - 1) * $produk->perPage() + $loop->iteration }}
                        </td>
                        <td style="padding:0.75rem 1rem;">
                            <div style="font-weight:600;color:#1e293b;">{{ $p->nama_produk }}</div>
                            @if($p->deskripsi)
                            <div style="font-size:0.72rem;color:#94a3b8;margin-top:0.15rem;max-width:220px;
                                        white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                {{ $p->deskripsi }}
                            </div>
                            @endif
                        </td>
                        <td style="padding:0.75rem 1rem;">
                            <span style="display:inline-block;padding:0.2rem 0.625rem;border-radius:9999px;
                                         font-size:0.72rem;font-weight:600;background:#ede9fe;color:#5b21b6;">
                                {{ $p->akad_syariah ?? '—' }}
                            </span>
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#059669;">
                            {{ $p->margin_persen ?? '—' }}%
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:center;color:#475569;">
                            {{ $p->min_tenor_bulan ?? '—' }} bln
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:center;color:#475569;">
                            {{ $p->max_tenor_bulan ?? '—' }} bln
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:right;color:#1e293b;">
                            @if($p->max_plafon)
                            Rp {{ number_format($p->max_plafon, 0, ',', '.') }}
                            @else
                            —
                            @endif
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:center;">
                            <span style="display:inline-block;padding:0.2rem 0.625rem;border-radius:9999px;
                                         font-size:0.72rem;font-weight:600;
                                         background:{{ $p->status_aktif ? '#d1fae5' : '#f1f5f9' }};
                                         color:{{ $p->status_aktif ? '#065f46' : '#64748b' }};">
                                {{ $p->status_aktif ? 'Aktif' : 'Nonaktif' }}
                            </span>
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:center;">
                            <a href="{{ route('pengurus.produk.edit', $p->id) }}"
                               style="display:inline-flex;align-items:center;padding:0.3rem 0.75rem;
                                      background:#059669;color:#fff;font-size:0.775rem;font-weight:600;
                                      border-radius:0.4rem;text-decoration:none;">
                                ✏ Edit Margin
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($produk->hasPages())
        <div style="padding:0.875rem 1.25rem;border-top:1px solid #f1f5f9;">{{ $produk->links() }}</div>
        @endif
        @else
        <div style="padding:3.5rem 1.5rem;text-align:center;">
            <div style="width:64px;height:64px;border-radius:50%;background:#ede9fe;
                        display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.75rem;">📦</div>
            <div style="font-size:0.95rem;font-weight:600;color:#1e293b;margin-bottom:0.375rem;">Belum ada produk pembiayaan</div>
            <p style="font-size:0.825rem;color:#94a3b8;">Tambah produk pertama untuk mulai mengelola akad.</p>
            <a href="{{ route('pengelola.produk-pembiayaan.create') }}"
               style="display:inline-flex;align-items:center;padding:0.5rem 1.125rem;background:#059669;
                      color:#fff;font-size:0.825rem;font-weight:600;border-radius:0.5rem;text-decoration:none;margin-top:0.5rem;">
                + Tambah Produk
            </a>
        </div>
        @endif
    </div>

</div>
@endsection
