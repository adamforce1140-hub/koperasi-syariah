@extends('layouts.app')

@section('title', 'Opini & Catatan Syariah — DPS')
@section('page-title', 'Opini & Catatan Syariah')

@section('content')

@if(session('success'))
    <div style="background:#d1fae5;border:1px solid #a7f3d0;color:#065f46;padding:0.875rem 1.25rem;border-radius:0.5rem;font-size:0.85rem;font-weight:600;margin-bottom:1.25rem;">
        {{ session('success') }}
    </div>
@endif
@if(session('error'))
    <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:0.875rem 1.25rem;border-radius:0.5rem;font-size:0.85rem;font-weight:600;margin-bottom:1.25rem;">
        {{ session('error') }}
    </div>
@endif
@if($errors->any())
    {{-- Form di halaman ini memvalidasi judul, isi, dan berkas lampiran.
         Tanpa blok ini kegagalan validasi tidak terlihat sama sekali. --}}
    <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:0.875rem 1.25rem;border-radius:0.5rem;font-size:0.85rem;margin-bottom:1.25rem;">
        <div style="font-weight:600;margin-bottom:0.35rem;">Periksa kembali isian berikut:</div>
        <ul style="margin:0;padding-left:1.1rem;">
            @foreach($errors->all() as $pesan)
                <li>{{ $pesan }}</li>
            @endforeach
        </ul>
    </div>
@endif

{{-- Keterangan Fitur --}}
<div style="background:#ede9fe;border:1px solid #ddd6fe;border-left:4px solid #7c3aed;border-radius:0.5rem;padding:0.875rem 1.25rem;margin-bottom:1.5rem;font-size:0.875rem;color:#4c1d95;">
    Halaman ini digunakan DPS untuk mendokumentasikan <strong>opini kepatuhan syariah</strong>, temuan audit berkala, dan rekomendasi perbaikan kepada manajemen koperasi.
</div>

{{-- ── FORM UNGGAH OPINI / CATATAN BARU ─────────────────────── --}}
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <span class="card-title">✍️ Tambah Opini / Catatan Baru</span>
    </div>

    <form method="POST" action="{{ route('dps.opini-catatan.store') }}" enctype="multipart/form-data"
          style="display:grid;gap:1rem;padding:0.25rem 0;">
        @csrf

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            {{-- Judul --}}
            <div style="display:flex;flex-direction:column;gap:0.35rem;">
                <label style="font-size:0.82rem;font-weight:600;color:#374151;" for="judul">Judul Opini / Catatan <span style="color:#dc2626;">*</span></label>
                <input id="judul" type="text" name="judul" placeholder="cth. Temuan Akad Murabahah Q3 2025"
                       style="padding:0.5rem 0.75rem;border:1px solid #d1d5db;border-radius:0.375rem;font-size:0.875rem;width:100%;box-sizing:border-box;" required>
            </div>

            {{-- Kategori --}}
            <div style="display:flex;flex-direction:column;gap:0.35rem;">
                <label style="font-size:0.82rem;font-weight:600;color:#374151;">Kategori</label>
                <select name="kategori"
                        style="padding:0.5rem 0.75rem;border:1px solid #d1d5db;border-radius:0.375rem;font-size:0.875rem;background:white;width:100%;box-sizing:border-box;">
                    <option value="opini_tahunan">Opini Tahunan</option>
                    <option value="temuan_audit">Temuan Audit</option>
                    <option value="rekomendasi">Rekomendasi Perbaikan</option>
                    <option value="fatwa_internal">Fatwa / Pendapat Syariah</option>
                    <option value="catatan_berkala">Catatan Berkala</option>
                </select>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            {{-- Periode --}}
            <div style="display:flex;flex-direction:column;gap:0.35rem;">
                <label style="font-size:0.82rem;font-weight:600;color:#374151;" for="periode">Periode</label>
                <input id="periode" type="month" name="periode" value="{{ now()->format('Y-m') }}"
                       style="padding:0.5rem 0.75rem;border:1px solid #d1d5db;border-radius:0.375rem;font-size:0.875rem;width:100%;box-sizing:border-box;">
            </div>

            {{-- Status Kepatuhan --}}
            <div style="display:flex;flex-direction:column;gap:0.35rem;">
                <label style="font-size:0.82rem;font-weight:600;color:#374151;">Status Kepatuhan</label>
                <select name="status_kepatuhan"
                        style="padding:0.5rem 0.75rem;border:1px solid #d1d5db;border-radius:0.375rem;font-size:0.875rem;background:white;width:100%;box-sizing:border-box;">
                    <option value="patuh" style="color:#059669;">✅ Patuh Syariah</option>
                    <option value="perlu_perbaikan" style="color:#d97706;">⚠️ Perlu Perbaikan</option>
                    <option value="tidak_patuh" style="color:#dc2626;">❌ Tidak Patuh</option>
                    <option value="dalam_kajian">🔍 Dalam Kajian</option>
                </select>
            </div>
        </div>

        {{-- Isi Catatan --}}
        <div style="display:flex;flex-direction:column;gap:0.35rem;">
            <label style="font-size:0.82rem;font-weight:600;color:#374151;" for="isi">Isi Opini / Catatan <span style="color:#dc2626;">*</span></label>
            <textarea id="isi" name="isi" rows="5" placeholder="Tuliskan temuan, analisis, dan rekomendasi DPS..."
                      style="padding:0.5rem 0.75rem;border:1px solid #d1d5db;border-radius:0.375rem;font-size:0.875rem;width:100%;box-sizing:border-box;resize:vertical;" required></textarea>
        </div>

        {{-- Upload Dokumen --}}
        <div style="display:flex;flex-direction:column;gap:0.35rem;">
            <label style="font-size:0.82rem;font-weight:600;color:#374151;">Lampiran Dokumen (opsional)</label>
            <div style="border:2px dashed #d1d5db;border-radius:0.5rem;padding:1.25rem;text-align:center;background:#f9fafb;">
                <input type="file" name="lampiran" accept=".pdf,.doc,.docx,.xls,.xlsx"
                       style="display:none;" id="file-lampiran">
                <label for="file-lampiran"
                       style="cursor:pointer;font-size:0.82rem;color:#7c3aed;font-weight:600;">
                    📎 Klik untuk pilih file
                </label>
                <div style="font-size:0.75rem;color:#94a3b8;margin-top:0.35rem;">PDF, DOC, DOCX, XLS, XLSX — maks. 5 MB</div>
            </div>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:0.75rem;padding-top:0.25rem;">
            <button type="reset"
                    style="padding:0.5rem 1.25rem;border:1px solid #d1d5db;border-radius:0.375rem;background:white;font-size:0.875rem;cursor:pointer;color:#374151;">
                Reset
            </button>
            <button type="submit"
                    style="padding:0.5rem 1.5rem;background:#7c3aed;color:white;border:none;border-radius:0.375rem;font-size:0.875rem;font-weight:600;cursor:pointer;">
                Simpan Opini
            </button>
        </div>
    </form>
</div>

{{-- ── DAFTAR CATATAN / OPINI TERSIMPAN ─────────────────────── --}}
<div class="card">
    <div class="card-header">
        <span class="card-title">Arsip Opini & Catatan Syariah</span>
        <span class="badge" style="background:#ede9fe;color:#7c3aed;font-size:0.7rem;">TERDOKUMENTASI</span>
    </div>

    @if(isset($opiniList) && $opiniList->count() > 0)
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;color:#64748b;font-size:0.75rem;text-transform:uppercase;">
                    <th style="padding:0.75rem 1rem;text-align:left;">Judul & Periode</th>
                    <th style="padding:0.75rem 1rem;text-align:left;">Kategori</th>
                    <th style="padding:0.75rem 1rem;text-align:center;">Status Kepatuhan</th>
                    <th style="padding:0.75rem 1rem;text-align:left;">Isi Opini</th>
                    <th style="padding:0.75rem 1rem;text-align:center;">Lampiran</th>
                    <th style="padding:0.75rem 1rem;text-align:left;">Oleh / Tanggal</th>
                </tr>
            </thead>
            <tbody>
                @foreach($opiniList as $item)
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:0.75rem 1rem;">
                        <div style="font-weight:600;color:#1e293b;">{{ $item->judul }}</div>
                        <div style="font-size:0.75rem;color:#64748b;">Periode: {{ $item->periode ?? '-' }}</div>
                    </td>
                    <td style="padding:0.75rem 1rem;">
                        <span class="badge badge-gray" style="text-transform:capitalize;">
                            {{ str_replace('_', ' ', $item->kategori) }}
                        </span>
                    </td>
                    <td style="padding:0.75rem 1rem;text-align:center;">
                        @if($item->status_kepatuhan === 'patuh')
                            <span class="badge badge-green">✅ Patuh Syariah</span>
                        @elseif($item->status_kepatuhan === 'perlu_perbaikan')
                            <span class="badge badge-amber">⚠️ Perlu Perbaikan</span>
                        @elseif($item->status_kepatuhan === 'tidak_patuh')
                            <span class="badge badge-red">❌ Tidak Patuh</span>
                        @else
                            <span class="badge badge-blue">🔍 Dalam Kajian</span>
                        @endif
                    </td>
                    <td style="padding:0.75rem 1rem;max-width:300px;color:#334155;">
                        {{ Str::limit($item->isi, 120) }}
                    </td>
                    <td style="padding:0.75rem 1rem;text-align:center;">
                        @if($item->lampiran)
                            <a href="{{ route('berkas.unduh', ['path' => $item->lampiran]) }}" target="_blank"
                               style="color:#7c3aed;font-weight:600;text-decoration:none;font-size:0.8rem;">
                                📄 Unduh File
                            </a>
                        @else
                            <span style="color:#94a3b8;">—</span>
                        @endif
                    </td>
                    <td style="padding:0.75rem 1rem;font-size:0.75rem;color:#64748b;">
                        <div>{{ $item->user->name ?? 'DPS' }}</div>
                        <div>{{ $item->created_at?->format('d/m/Y H:i') }}</div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($opiniList->hasPages())
    <div style="padding:1rem;border-top:1px solid #f1f5f9;">
        {{ $opiniList->links() }}
    </div>
    @endif

    @else
    <div style="padding:3rem 2rem;text-align:center;color:#94a3b8;">
        <div style="font-size:2.5rem;margin-bottom:0.75rem;">📝</div>
        <div style="font-weight:600;font-size:0.95rem;color:#64748b;margin-bottom:0.35rem;">Belum ada opini tersimpan</div>
        <div style="font-size:0.8rem;">Opini yang disimpan akan muncul di sini sebagai arsip terdokumentasi secara permanen.</div>
    </div>
    @endif
</div>

@push('scripts')
<script>
// Tampilkan nama file yang dipilih
document.getElementById('file-lampiran')?.addEventListener('change', function() {
    const label = this.previousElementSibling;
    if (this.files.length > 0) {
        label.textContent = '📎 ' + this.files[0].name;
    } else {
        label.textContent = '📎 Klik untuk pilih file';
    }
});
</script>
@endpush
@endsection
