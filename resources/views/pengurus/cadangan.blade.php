@extends('layouts.app')
@section('page-title', "Dana Cadangan Koperasi")

@section('title', 'Dana Cadangan Koperasi')

@section('content')
<div class="container-fluid px-4 py-3">

    {{-- Header --}}
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h4 class="fw-bold mb-1">Dana Cadangan Koperasi</h4>
            <p class="text-muted mb-0 small">Akun CoA 3-2001 &mdash; Ekuitas / Modal Sendiri</p>
        </div>
        <a href="{{ route('laporan.neraca') }}" class="btn btn-outline-primary btn-sm">
            Lihat Neraca
        </a>
    </div>

    {{-- Alert --}}
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            @foreach($errors->all() as $e) <div>{{ $e }}</div> @endforeach
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- Saldo Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100" style="border-left:4px solid #6366f1 !important;">
                <div class="card-body">
                    <p class="text-muted small mb-1">Saldo Dana Cadangan (3-2001)</p>
                    <h5 class="fw-bold text-indigo mb-0">Rp {{ number_format($saldoCadangan, 0, ',', '.') }}</h5>
                    <span class="badge bg-light text-dark border mt-2">Ekuitas — Kredit</span>
                    @if(!empty($cadanganTampil['akun_bermasalah']))
                        <div class="alert alert-warning py-1 px-2 mt-2 mb-0" style="font-size:0.75rem;">
                            Saldo akun ini tertekan ke posisi Debet — nilai mentah
                            Rp {{ number_format($cadanganTampil['mentah'], 0, ',', '.') }} tidak ditampilkan.
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100" style="border-left:4px solid #10b981 !important;">
                <div class="card-body">
                    <p class="text-muted small mb-1">Saldo Ikhtisar SHU (3-9000)</p>
                    {{-- Akun Ekuitas (Kepala 3) memakai rumus Kredit − Debet dan
                         tidak pernah ditampilkan minus. Bila akun tertekan ke
                         posisi Debet, nilai dijaga di 0 dan penyebabnya
                         dijelaskan pada catatan di bawah card. --}}
                    <h5 class="fw-bold text-success mb-0">Rp {{ number_format($saldoShu, 0, ',', '.') }}</h5>
                    <span class="badge bg-light text-dark border mt-2">Tersedia untuk Alokasi</span>
                    @php
                        $badgeStatus = [
                            'untung' => ['bg-success', 'Kondisi: Untung'],
                            'rugi'   => ['bg-danger',  'Kondisi: Rugi'],
                            'impas'  => ['bg-secondary', 'Kondisi: Impas'],
                        ][$statusKeuangan ?? 'impas'] ?? ['bg-secondary', 'Kondisi: —'];
                    @endphp
                    <span class="badge {{ $badgeStatus[0] }} ms-1 mt-2">{{ $badgeStatus[1] }}</span>
                    @if(!empty($catatanShu))
                        <div class="alert alert-warning py-1 px-2 mt-2 mb-0" style="font-size:0.75rem;">
                            {{ $catatanShu }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100 bg-warning bg-opacity-10" style="border-left:4px solid #f59e0b !important;">
                <div class="card-body">
                    <p class="text-muted small mb-1">Status Akun</p>
                    <h6 class="fw-bold mb-1">3-2001 Dana Cadangan Koperasi</h6>
                    <span class="badge bg-success">Aktif</span>
                    <span class="badge bg-secondary ms-1">Tidak bisa ditarik di Loket Teller</span>
                    <div class="small text-muted mt-2">
                        Laba (SHU) berjalan:
                        <strong class="{{ ($labaBersih ?? 0) < 0 ? 'text-danger' : 'text-success' }}">
                            {{ ($labaBersih ?? 0) < 0 ? '-' : '' }}Rp {{ number_format(abs($labaBersih ?? 0), 0, ',', '.') }}
                        </strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Form Alokasi SHU --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-indigo text-white py-3" style="background:#6366f1 !important;">
            <h6 class="mb-0 fw-semibold">Alokasi SHU ke Dana Cadangan</h6>
            <small class="opacity-75">DEBET: 3-9000 Ikhtisar SHU &rarr; KREDIT: 3-2001 Dana Cadangan</small>
        </div>
        <div class="card-body">
            @php
                /* VALIDASI PROFIT/LOSS: alokasi SHU hanya boleh dijalankan bila
                 | posisi keuangan berjalan tidak merugi. Nilai < 0 berarti Rugi,
                 | sehingga form dikunci di sisi tampilan — dan tetap diperiksa
                 | ulang di backend (DanaCadanganController::alokasiShu). */
                $sedangRugi = ($labaBersih ?? 0) < 0;
            @endphp

            @if($sedangRugi)
                <div class="alert alert-warning d-flex align-items-start" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2 mt-1"></i>
                    <div>
                        Alokasi Dana Cadangan tidak dapat dilakukan karena posisi keuangan berjalan sedang mengalami kerugian.
                    </div>
                </div>
            @endif

            <form action="{{ route('pengurus.cadangan.alokasi') }}" method="POST">
                @csrf
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Nominal Alokasi (Rp)</label>
                        <input type="number" name="nominal" class="form-control @error('nominal') is-invalid @enderror"
                               placeholder="Contoh: 2500000" min="1" step="1" value="{{ old('nominal') }}"
                               {{ $sedangRugi ? 'disabled' : 'required' }}>
                        @error('nominal')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Keterangan Jurnal</label>
                        <input type="text" name="keterangan" class="form-control @error('keterangan') is-invalid @enderror"
                               placeholder="Contoh: Alokasi SHU Tahun 2025 berdasarkan RAT" value="{{ old('keterangan') }}"
                               {{ $sedangRugi ? 'disabled' : 'required' }}>
                        @error('keterangan')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100"
                                @if($sedangRugi)
                                    disabled title="Alokasi tidak tersedia saat posisi keuangan rugi"
                                @else
                                    onclick="return confirm('Konfirmasi: Alokasi SHU ke Dana Cadangan?\n\nJurnal yang akan diposting:\nDEBET  : 3-9000 Ikhtisar SHU\nKREDIT : 3-2001 Dana Cadangan\n\nProses tidak dapat dibatalkan setelah diposting.')"
                                @endif>
                            Posting Jurnal
                        </button>
                    </div>
                </div>
                <div class="alert alert-info mt-3 mb-0 small">
                    <strong>Catatan:</strong> Aksi ini akan memposting jurnal permanen ke Buku Besar.
                    Pastikan nominal sudah disetujui dalam Rapat Anggota Tahunan (RAT).
                    Saldo Dana Cadangan <strong>tidak dapat ditarik</strong> melalui loket Teller.
                </div>
            </form>
        </div>
    </div>

    {{-- Riwayat Alokasi --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 border-bottom">
            <h6 class="mb-0 fw-semibold">Riwayat Jurnal Alokasi SHU</h6>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. Jurnal</th>
                        <th>Tanggal</th>
                        <th>Keterangan</th>
                        <th class="text-end">Nominal</th>
                        <th class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($riwayat as $j)
                    <tr>
                        <td><code>{{ $j->no_jurnal }}</code></td>
                        <td>{{ \Carbon\Carbon::parse($j->tanggal_jurnal)->format('d/m/Y') }}</td>
                        <td>{{ $j->keterangan }}</td>
                        <td class="text-end fw-semibold">Rp {{ number_format($j->total_kredit, 0, ',', '.') }}</td>
                        <td class="text-center">
                            <span class="badge {{ $j->status === 'Posted' ? 'bg-success' : 'bg-secondary' }}">
                                {{ $j->status }}
                            </span>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">Belum ada riwayat alokasi SHU.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($riwayat->hasPages())
        <div class="card-footer bg-white">
            {{ $riwayat->links() }}
        </div>
        @endif
    </div>

</div>
@endsection
