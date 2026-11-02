@extends('layouts.app')

@section('title', 'Review & Sertifikasi Produk Baru — DPS')
@section('page-title', 'Review & Sertifikasi Produk Baru')

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- ── HEADER ─────────────────────────────────────── --}}
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
                Review & Sertifikasi Produk Pembiayaan
            </h1>
            <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
                Pemeriksaan kepatuhan akad syariah dan penerbitan rekomendasi/sertifikasi opini syariah untuk produk baru.
            </p>
        </div>
        <span style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.55rem 1.125rem;
                  background:#ede9fe;color:#6d28d9;font-size:0.825rem;font-weight:600;
                  border-radius:0.5rem;white-space:nowrap;">
            🛡️ Dewan Pengawas Syariah (DPS)
        </span>
    </div>

    @if(session('success'))
        <div style="background:#d1fae5;border:1px solid #a7f3d0;color:#065f46;padding:0.875rem 1.25rem;border-radius:0.5rem;font-size:0.85rem;font-weight:600;">
            {{ session('success') }}
        </div>
    @endif

    @if(session('info'))
        {{-- `reviewProdukApprove` mengirim `info` saat produk sudah tersertifikasi.
             Sebelumnya pesan ini tidak pernah tampil sehingga tombol seolah
             tidak bereaksi. --}}
        <div style="background:#dbeafe;border:1px solid #93c5fd;color:#1e40af;padding:0.875rem 1.25rem;border-radius:0.5rem;font-size:0.85rem;font-weight:600;">
            {{ session('info') }}
        </div>
    @endif

    @if($errors->any())
        {{-- `reviewProdukApprove` memvalidasi `catatan_dps` (maks 1000 karakter).
             Bila gagal, pengguna dialihkan kembali ke sini; tanpa blok ini
             catatan yang terlalu panjang gagal tanpa penjelasan apa pun. --}}
        <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:0.875rem 1.25rem;border-radius:0.5rem;font-size:0.85rem;">
            <div style="font-weight:600;margin-bottom:0.35rem;">Periksa kembali isian berikut:</div>
            <ul style="margin:0;padding-left:1.1rem;">
                @foreach($errors->all() as $pesan)
                    <li>{{ $pesan }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ── TABEL REVIEWS ────────────────────────────────── --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        @if(isset($produk) && $produk->count())
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">#</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Nama Produk</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Akad Syariah</th>
                        <th style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#475569;">Margin / Admin</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;">Plafon</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;">Status Kepatuhan</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;">Aksi DPS</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($produk as $p)
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:0.75rem 1rem;color:#94a3b8;">{{ ($produk->currentPage() - 1) * $produk->perPage() + $loop->iteration }}</td>
                        <td style="padding:0.75rem 1rem;font-weight:600;color:#1e293b;">{{ $p->nama_produk }}</td>
                        <td style="padding:0.75rem 1rem;">
                            <span style="display:inline-block;padding:0.2rem 0.5rem;border-radius:0.25rem;font-size:0.75rem;font-weight:600;background:#dbeafe;color:#1e40af;">
                                {{ $p->akad_syariah ?? \App\Models\Pembiayaan::akadDefault() }}
                            </span>
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:right;">{{ $p->margin_persen }}% / Rp {{ number_format($p->biaya_admin, 0, ',', '.') }}</td>
                        <td style="padding:0.75rem 1rem;text-align:center;">Rp {{ number_format($p->min_plafon, 0, ',', '.') }} - Rp {{ number_format($p->max_plafon, 0, ',', '.') }}</td>
                        <td style="padding:0.75rem 1rem;text-align:center;">
                            @if($p->status_sertifikasi_dps === \App\Models\MasterProdukPembiayaan::SERTIFIKASI_VALID)
                                <span style="display:inline-block;padding:0.25rem 0.6rem;border-radius:0.375rem;font-size:0.75rem;font-weight:600;background:#dcfce7;color:#166534;"
                                      title="Disertifikasi {{ optional($p->tanggal_disahkan)->format('d/m/Y H:i') }} oleh {{ $p->disahkanOleh?->name ?? '-' }}">
                                    ✅ Sertifikasi Syariah Valid
                                </span>
                                @if($p->tanggal_disahkan)
                                    <div style="font-size:0.7rem;color:#94a3b8;margin-top:0.25rem;">
                                        {{ optional($p->tanggal_disahkan)->format('d/m/Y') }} — {{ $p->disahkanOleh?->name ?? '-' }}
                                    </div>
                                @endif
                            @elseif($p->status_sertifikasi_dps === \App\Models\MasterProdukPembiayaan::SERTIFIKASI_DITOLAK)
                                <span style="display:inline-block;padding:0.25rem 0.6rem;border-radius:0.375rem;font-size:0.75rem;font-weight:600;background:#fee2e2;color:#991b1b;">
                                    ✖ Tidak Disetujui DPS
                                </span>
                            @else
                                <span style="display:inline-block;padding:0.25rem 0.6rem;border-radius:0.375rem;font-size:0.75rem;font-weight:600;background:#fef3c7;color:#92400e;">
                                    ⏳ Belum Disertifikasi
                                </span>
                            @endif
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:center;">
                            @if($p->status_sertifikasi_dps === \App\Models\MasterProdukPembiayaan::SERTIFIKASI_VALID)
                                <span style="font-size:0.75rem;color:#059669;font-weight:600;">&#10003; Tersertifikasi</span>
                            @else
                            <form method="POST" action="{{ route('dps.review-produk.approve', $p->id) }}" style="display:inline;">
                                @csrf
                                <button type="submit" style="padding:0.35rem 0.75rem;background:#7c3aed;color:#fff;border:none;border-radius:0.375rem;font-size:0.78rem;font-weight:600;cursor:pointer;">
                                    Sertifikasi Akad
                                </button>
                            </form>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="padding:1rem;border-top:1px solid #e2e8f0;">
            {{ $produk->links() }}
        </div>
        @else
        <div style="padding:2rem;text-align:center;color:#64748b;">
            Belum ada produk yang memerlukan review DPS.
        </div>
        @endif
    </div>

</div>
@endsection
