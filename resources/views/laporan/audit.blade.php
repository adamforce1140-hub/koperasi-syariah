@extends('layouts.app')

@section('title', 'Audit Laporan Keuangan')
@section('page-title', 'Audit Laporan Keuangan')

@section('styles')
<style>
    .badge-aktivitas {
        display: inline-block; padding: .2rem .55rem; border-radius: 999px;
        font-size: 0.7rem; font-weight: 600; color: #fff; white-space: nowrap;
    }
    .badge-peran {
        display: inline-block; padding: .15rem .5rem; border-radius: 4px;
        font-size: 0.7rem; font-weight: 600;
        background: #eef2ff; color: #3730a3; white-space: nowrap;
    }
    .tabel-audit { font-size: .8rem; }
    .tabel-audit th {
        font-size: .7rem; text-transform: uppercase; letter-spacing: .03em;
        color: #64748b; white-space: nowrap; background: #f8fafc;
    }
    .tabel-audit td { vertical-align: top; }
    .uraian { max-width: 340px; }
    .uraian-detail {
        font-family: ui-monospace, Menlo, Consolas, monospace;
        font-size: 0.7rem; color: #475569; word-break: break-all;
    }
    .gagal { background: #fef2f2 !important; }
    /* Badge Matriks Hak Akses — standar seragam: radius pil .badge global,
       warna mengikuti makna hak (Lihat hijau, Unduh biru, Edit amber,
       Hapus merah, Ekspor teal, Verifikasi ungu, tanpa hak abu). */
    .matriks-hak {
        font-size: 0.7rem; display: inline-block; padding: .15rem .5rem;
        border-radius: 9999px; margin: 0 .15rem .15rem 0;
        background: #dcfce7; color: #166534; font-weight: 600; white-space: nowrap;
    }
    .matriks-hak.mh-unduh      { background: #dbeafe; color: #1e40af; }
    .matriks-hak.mh-edit       { background: #fef3c7; color: #92400e; }
    .matriks-hak.mh-hapus      { background: #fee2e2; color: #991b1b; }
    .matriks-hak.mh-ekspor     { background: #ccfbf1; color: #0f766e; }
    .matriks-hak.mh-verifikasi { background: #f3e8ff; color: #7e22ce; }
    .matriks-hak.mh-tidak      { background: #f1f5f9; color: #475569; font-weight: 500; }
    #kotakIntegritas { font-size: .82rem; }
</style>
@endsection

@section('content')
<div class="container-fluid px-4 py-3">

    {{-- ── JUDUL ─────────────────────────────────────────────── --}}
    <div class="d-flex align-items-start justify-content-between mb-3 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-1">Audit Laporan Keuangan</h4>
            <p class="text-muted mb-0 small">
                Riwayat lengkap aktivitas modul Laporan Keuangan — Neraca, Buku Besar,
                dan Laporan Operasional. Mencatat juga percobaan akses yang ditolak.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-outline-primary btn-sm" id="tombolVerifikasi">
                Verifikasi Integritas Log
            </button>
            @if(\App\Support\LaporanKeuanganPermission::boleh(auth()->user()->role, \App\Support\LaporanKeuanganPermission::MODUL_AUDIT, \App\Support\LaporanKeuanganPermission::EKSPOR))
            <a href="{{ route('audit.laporan.ekspor', array_filter(request()->query())) }}"
               class="btn btn-success btn-sm">
                Ekspor Log (CSV)
            </a>
            @endif
        </div>
    </div>

    {{-- ── HASIL VERIFIKASI INTEGRITAS ─────────────────────────── --}}
    <div id="kotakIntegritas" class="alert alert-secondary d-none" role="alert"></div>

    {{-- ── RINGKASAN ─────────────────────────────────────────── --}}
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="text-muted small">Total Aktivitas Tercatat</div>
                    <div class="fw-bold fs-5">{{ number_format($ringkasan['total'], 0, ',', '.') }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="text-muted small">Aktivitas Hari Ini</div>
                    <div class="fw-bold fs-5">{{ number_format($ringkasan['hari_ini'], 0, ',', '.') }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="text-muted small">Percobaan Ditolak</div>
                    <div class="fw-bold fs-5 {{ $ringkasan['ditolak'] > 0 ? 'text-danger' : '' }}">
                        {{ number_format($ringkasan['ditolak'], 0, ',', '.') }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── FILTER ────────────────────────────────────────────── --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <form method="GET" action="{{ route('audit.laporan.index') }}"
                  class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label mb-1 small fw-semibold text-muted">Peran</label>
                    <select name="peran" class="form-select form-select-sm">
                        <option value="">Semua Peran</option>
                        @foreach($labelPeran as $nilai => $label)
                            <option value="{{ $nilai }}" {{ $filter['peran'] === $nilai ? 'selected' : '' }}>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1 small fw-semibold text-muted">Jenis Aktivitas</label>
                    <select name="aktivitas" class="form-select form-select-sm">
                        <option value="">Semua Aktivitas</option>
                        @foreach($labelAktivitas as $nilai => $label)
                            <option value="{{ $nilai }}" {{ $filter['aktivitas'] === $nilai ? 'selected' : '' }}>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1 small fw-semibold text-muted">Modul</label>
                    <select name="modul" class="form-select form-select-sm">
                        <option value="">Semua Modul</option>
                        @foreach($labelModul as $nilai => $label)
                            <option value="{{ $nilai }}" {{ $filter['modul'] === $nilai ? 'selected' : '' }}>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1 small fw-semibold text-muted" for="dari">Dari Tanggal</label>
                    <input id="dari" type="date" name="dari" class="form-control form-control-sm"
                           value="{{ $filter['dari'] }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1 small fw-semibold text-muted" for="sampai">Sampai Tanggal</label>
                    <input id="sampai" type="date" name="sampai" class="form-control form-control-sm"
                           value="{{ $filter['sampai'] }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1 small fw-semibold text-muted">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">Semua Status</option>
                        <option value="berhasil" {{ $filter['status'] === 'berhasil' ? 'selected' : '' }}>Berhasil</option>
                        <option value="ditolak"  {{ $filter['status'] === 'ditolak'  ? 'selected' : '' }}>Ditolak</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label mb-1 small fw-semibold text-muted" for="cari">Pencarian</label>
                    <input id="cari" type="text" name="cari" class="form-control form-control-sm"
                           placeholder="Nama pengguna, deskripsi, URL, atau IP…"
                           value="{{ $filter['cari'] }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label mb-1 small fw-semibold text-muted">Baris per Halaman</label>
                    <select name="per_halaman" class="form-select form-select-sm">
                        @foreach([25, 50, 100, 200] as $opsi)
                            <option value="{{ $opsi }}" {{ $perHalaman === $opsi ? 'selected' : '' }}>{{ $opsi }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-5 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">Terapkan Filter</button>
                    <a href="{{ route('audit.laporan.index') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
                </div>
            </form>
        </div>
    </div>

    {{-- ── TABEL RIWAYAT ─────────────────────────────────────── --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
            <span class="fw-semibold small">
                Riwayat Aktivitas
                <span class="text-muted">({{ number_format($logs->total(), 0, ',', '.') }} baris)</span>
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover tabel-audit mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Waktu</th>
                        <th>Pengguna</th>
                        <th>Peran</th>
                        <th>Modul</th>
                        <th>Aktivitas</th>
                        <th>Keterangan</th>
                        <th>Status</th>
                        <th>Perubahan Data</th>
                        <th>Hash</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                    <tr class="{{ $log->berhasil ? '' : 'gagal' }}">
                        <td class="text-muted">{{ $log->id }}</td>
                        <td class="text-nowrap">
                            {{ $log->created_at?->format('d/m/Y') }}<br>
                            <span class="text-muted">{{ $log->created_at?->format('H:i:s') }}</span>
                        </td>
                        <td class="text-nowrap">
                            {{ $log->user_nama ?? '(anonim)' }}
                            @if($log->ip_address)
                                <br><span class="text-muted uraian-detail">{{ $log->ip_address }}</span>
                            @endif
                        </td>
                        <td><span class="badge-peran">{{ $labelPeran[$log->role] ?? ($log->role ?? '—') }}</span></td>
                        <td class="text-nowrap">{{ $labelModul[$log->modul] ?? $log->modul }}</td>
                        <td>
                            <span class="badge-aktivitas"
                                  style="background: {{ $warnaAktivitas[$log->aktivitas] ?? '#64748b' }};">
                                {{ $labelAktivitas[$log->aktivitas] ?? $log->aktivitas }}
                            </span>
                        </td>
                        <td class="uraian">
                            {{ $log->deskripsi }}
                            @if($log->parameter)
                                <div class="uraian-detail mt-1">
                                    {{ json_encode($log->parameter, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}
                                </div>
                            @endif
                        </td>
                        <td class="text-nowrap">
                            @if($log->berhasil)
                                <span class="text-success fw-semibold">Berhasil</span>
                            @else
                                <span class="text-danger fw-semibold">Ditolak</span>
                            @endif
                            <br><span class="text-muted uraian-detail">HTTP {{ $log->status_http }}</span>
                        </td>
                        <td class="uraian">
                            @if($log->perubahan_data)
                                <span class="uraian-detail">
                                    {{ json_encode($log->perubahan_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}
                                </span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="uraian-detail" title="{{ $log->hash }}">
                            {{ \Illuminate\Support\Str::limit($log->hash, 16, '…') }}
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="10" class="text-center text-muted py-4">
                            Belum ada aktivitas yang cocok dengan filter ini.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($logs->hasPages())
        <div class="card-footer bg-white py-2">
            {{ $logs->links() }}
        </div>
        @endif
    </div>

    {{-- ── MATRIKS HAK AKSES ─────────────────────────────────── --}}
    <div class="card border-0 shadow-sm mb-4"
         id="kartuMatriks"
         data-refresh-url="{{ route('audit.laporan.matriks') }}">
        <div class="card-header bg-white py-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span class="fw-semibold small">Matriks Hak Akses Modul Laporan Keuangan</span>
            <span class="text-muted" style="font-size:0.7rem;">
                sinkron dengan middleware
                <span id="statusMatriks" class="ms-1">•</span>
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm tabel-audit mb-0" id="tabelMatriks"
                   data-signature="{{ $signatureMatriks ?? '' }}">
                <thead>
                    <tr>
                        <th>Peran</th>
                        @foreach(\App\Support\LaporanKeuanganPermission::MODUL as $modul)
                            <th>{{ \App\Support\LaporanKeuanganPermission::labelModul($modul) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($labelPeran as $kode => $namaPeran)
                    <tr>
                        <td class="fw-semibold text-nowrap">{{ $namaPeran }}</td>
                        @foreach(\App\Support\LaporanKeuanganPermission::MODUL as $modul)
                            <td>
                                @include('laporan.partials.badge-matriks', [
                                    'hakModul'      => $matriks[$kode][$modul] ?? [],
                                    'labelAktivitas' => $labelAktivitas,
                                ])
                            </td>
                        @endforeach
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white py-2">
            <span class="text-muted small">
                Badge = hak yang benar-benar diizinkan; warna menandai jenis haknya.
                Sel bertanda <span class="matriks-hak mh-tidak">Tidak ada akses</span>
                berarti peran tersebut tidak punya hak apa pun pada modul itu.
                Matriks ini dibaca dari sumber kebenaran yang sama dengan middleware,
                dan disegarkan otomatis tanpa reload halaman.
            </span>
        </div>
    </div>

</div>
@endsection

@section('scripts')
<script>
document.getElementById('tombolVerifikasi')?.addEventListener('click', function () {
    const tombol = this;
    const kotak  = document.getElementById('kotakIntegritas');

    tombol.disabled = true;
    tombol.textContent = 'Memeriksa…';
    kotak.className = 'alert alert-secondary';
    kotak.textContent = 'Memverifikasi rantai hash seluruh baris log…';
    kotak.classList.remove('d-none');

    fetch('{{ route('audit.laporan.verifikasi') }}', {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    })
    .then(data => {
        if (data.utuh) {
            kotak.className = 'alert alert-success';
            kotak.innerHTML = '<strong>Integritas terjaga.</strong> Seluruh '
                + data.diperiksa.toLocaleString('id-ID')
                + ' baris log utuh — tidak ada baris yang disunting atau dihapus.';
        } else {
            kotak.className = 'alert alert-danger';
            kotak.innerHTML = '<strong>Integritas terganggu!</strong> '
                + (data.alasan || '') + ' Baris bermasalah: #' + data.rusak_di
                + ' (diperiksa ' + data.diperiksa.toLocaleString('id-ID') + ' baris).';
        }
    })
    .catch(err => {
        kotak.className = 'alert alert-warning';
        kotak.textContent = 'Gagal memverifikasi: ' + err.message;
    })
    .finally(() => {
        tombol.disabled = false;
        tombol.textContent = 'Verifikasi Integritas Log';
    });
});

/* ── Matriks Hak Akses: binding data dinamis ────────────────────────────────
   Matriks di-render ulang dari data yang sama dengan yang dipakai middleware
   (LaporanKeuanganPermission). Penyegaran dipicu oleh:
     1) polling berkala,
     2) tab/browser kembali aktif (visibilitychange),
     3) event 'permission:changed' — dipicu manual saat konfigurasi permission
        berubah, tanpa perlu menunggu polling.
   DOM hanya disentuh bila sidik jari konfigurasi (tanda) benar-benar berubah,
   sehingga tidak ada kedipan tabel yang tidak perlu. */
(function () {
    const kartu = document.getElementById('kartuMatriks');
    if (!kartu) return;

    const tabel = document.getElementById('tabelMatriks');
    const status = document.getElementById('statusMatriks');
    if (!tabel) return;

    const url = kartu.dataset.refreshUrl;
    if (!url) return;

    const kelasHak = {
        lihat:      '',
        unduh:      'mh-unduh',
        edit:       'mh-edit',
        hapus:      'mh-hapus',
        ekspor:     'mh-ekspor',
        verifikasi: 'mh-verifikasi',
    };

    const INTERVAL = 60000; // polling 1 menit
    let tandaTerakhir = tabel.dataset.signature || null;
    let sedangMemuat  = false;

    function tandaiStatus(teks, warna) {
        if (!status) return;
        status.textContent = '• ' + teks;
        status.style.color = warna;
    }

    /* Markup badge harus identik dengan partial laporan.partials.badge-matriks. */
    function badge(kodeAksi, labelAktivitas) {
        const span = document.createElement('span');
        span.className = 'matriks-hak ' + (kelasHak[kodeAksi] || '');
        span.textContent = labelAktivitas[kodeAksi];
        return span;
    }

    function gambarUlang(data) {
        const tbody = tabel.querySelector('tbody');
        if (!tbody) return;

        const fragmen = document.createDocumentFragment();

        Object.keys(data.peran).forEach(function (kodePeran) {
            const tr = document.createElement('tr');

            const tdPeran = document.createElement('td');
            tdPeran.className = 'fw-semibold text-nowrap';
            tdPeran.textContent = data.peran[kodePeran];
            tr.appendChild(tdPeran);

            data.modul.forEach(function (modul) {
                const td = document.createElement('td');
                const hak = (data.matriks[kodePeran] || {})[modul] || [];

                if (hak.length === 0) {
                    const span = document.createElement('span');
                    span.className = 'matriks-hak mh-tidak';
                    span.textContent = 'Tidak ada akses';
                    td.appendChild(span);
                } else {
                    hak.forEach(function (kodeAksi) {
                        td.appendChild(badge(kodeAksi, data.labelAktivitas));
                    });
                }

                tr.appendChild(td);
            });

            fragmen.appendChild(tr);
        });

        tbody.replaceChildren(fragmen);
        gambarUlangKepala(data);
        tandaTerakhir = data.tanda;
        tabel.dataset.signature = data.tanda;
    }

    /* Judul kolom mengikuti daftar & label modul terbaru dari server. */
    function gambarUlangKepala(data) {
        const barisKepala = tabel.querySelector('thead tr');
        if (!barisKepala) return;

        const fragmen = document.createDocumentFragment();

        const thPeran = document.createElement('th');
        thPeran.textContent = 'Peran';
        fragmen.appendChild(thPeran);

        data.modul.forEach(function (modul) {
            const th = document.createElement('th');
            th.textContent = (data.labelModul || {})[modul] || modul;
            fragmen.appendChild(th);
        });

        barisKepala.replaceChildren(fragmen);
    }

    async function segarkanMatriks() {
        if (sedangMemuat || document.hidden) return;
        sedangMemuat = true;
        tandaiStatus('memeriksa…', '#94a3b8');

        try {
            const respon = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                cache: 'no-store',
            });
            if (!respon.ok) throw new Error('HTTP ' + respon.status);

            const data = await respon.json();
            if (!data || typeof data.tanda !== 'string') throw new Error('format tak dikenal');

            // Sidik jari sama → konfigurasi permission tidak berubah, jangan sentuh DOM.
            if (tandaTerakhir === data.tanda) {
                tandaiStatus('sinkron', '#16a34a');
            } else {
                gambarUlang(data);
                tandaiStatus('diperbarui', '#2563eb');
            }
        } catch (e) {
            // Kegagalan refresh tidak mengganggu halaman; matriks terakhir tetap tampil.
            tandaiStatus('gagal menyegarkan', '#dc2626');
        } finally {
            sedangMemuat = false;
        }
    }

    // 1) Polling berkala.
    setInterval(segarkanMatriks, INTERVAL);

    // 2) Tab/browser kembali aktif.
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) segarkanMatriks();
    });

    // 3) Perubahan state permission — pemicu instan tanpa menunggu polling.
    document.addEventListener('permission:changed', segarkanMatriks);

    // Tandai status awal sebagai sinkron selama satu siklus penuh belum lewat.
    tandaiStatus('sinkron', '#16a34a');
})();
</script>
@endsection
