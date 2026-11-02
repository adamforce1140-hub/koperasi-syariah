@extends('layouts.app')

@section('title', 'Riwayat Pengujian — Pengurus')
@section('page-title', 'Riwayat Pengujian')

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- ── HEADER ─────────────────────────────────────── --}}
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
                Riwayat Pengujian Sistem
            </h1>
            <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
                Jalankan pengujian otomatis per modul dan lihat hasilnya langsung di sini — tanpa keluar dari aplikasi.
            </p>
        </div>
    </div>

    {{-- ── NOTIFIKASI ──────────────────────────────────── --}}
    @if(session('success'))
    <div style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:0.75rem 1rem;border-radius:0.75rem;font-size:0.85rem;">
        {{ session('success') }}
    </div>
    @endif
    @if(session('error'))
    <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:0.75rem 1rem;border-radius:0.75rem;font-size:0.85rem;">
        {{ session('error') }}
    </div>
    @endif
    @if($errors->any())
    <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:0.75rem 1rem;border-radius:0.75rem;font-size:0.85rem;">
        <strong>Periksa kembali masukan:</strong>
        <ul style="margin:0.4rem 0 0 1rem;">
            @foreach($errors->all() as $e)
                <li>{{ $e }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- ── KARTU STATISTIK ─────────────────────────────── --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;">
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1rem 1.15rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="font-size:0.75rem;color:#64748b;text-transform:uppercase;letter-spacing:.03em;">Total Sesi Uji</div>
            <div style="font-size:1.6rem;font-weight:700;color:#1e293b;margin-top:0.35rem;">{{ $ringkasan['total'] }}</div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1rem 1.15rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="font-size:0.75rem;color:#64748b;text-transform:uppercase;letter-spacing:.03em;">Lulus</div>
            <div style="font-size:1.6rem;font-weight:700;color:#047857;margin-top:0.35rem;">{{ $ringkasan['lulus'] }}</div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1rem 1.15rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="font-size:0.75rem;color:#64748b;text-transform:uppercase;letter-spacing:.03em;">Gagal / Error</div>
            <div style="font-size:1.6rem;font-weight:700;color:#b91c1c;margin-top:0.35rem;">{{ $ringkasan['gagal'] }}</div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1rem 1.15rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="font-size:0.75rem;color:#64748b;text-transform:uppercase;letter-spacing:.03em;">Pengujian Terakhir</div>
            <div style="font-size:0.9rem;font-weight:600;color:#1e293b;margin-top:0.5rem;">
                @if($ringkasan['terakhir'])
                    {{ $ringkasan['terakhir']->created_at?->format('d M Y H:i') }}
                @else
                    <span style="color:#94a3b8;font-weight:400;">Belum ada</span>
                @endif
            </div>
        </div>
    </div>

    {{-- ── FORMULIR RUN TEST ───────────────────────────── --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <h2 style="font-size:1rem;font-weight:700;color:#1e293b;margin:0 0 0.25rem;">Jalankan Pengujian Baru</h2>
        <p style="font-size:0.8rem;color:#64748b;margin:0 0 1rem;">
            Pilih tanggal, modul, dan (opsional) berkas uji spesifik, lalu tekan <strong>Run Test</strong>.
            Proses berjalan di latar server dan hasilnya muncul pada tabel di bawah.
        </p>

        <form method="POST" action="{{ route('pengurus.riwayat-pengujian.jalankan') }}"
              onsubmit="this.querySelector('button[type=submit]').disabled=true;this.querySelector('button[type=submit]').textContent='Menjalankan…';">
            @csrf
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
                <div>
                    <label for="tanggal_uji" style="display:block;font-size:0.8rem;font-weight:600;color:#334155;margin-bottom:0.35rem;">
                        Tanggal Pengujian
                    </label>
                    <input type="date" id="tanggal_uji" name="tanggal_uji" required
                           value="{{ old('tanggal_uji', now()->toDateString()) }}"
                           style="width:100%;padding:0.55rem 0.7rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;color:#1e293b;">
                </div>
                <div>
                    <label for="modul" style="display:block;font-size:0.8rem;font-weight:600;color:#334155;margin-bottom:0.35rem;">
                        Modul yang Diuji
                    </label>
                    <select id="modul" name="modul" required
                            style="width:100%;padding:0.55rem 0.7rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;color:#1e293b;background:#fff;">
                        <option value="" disabled {{ old('modul') ? '' : 'selected' }}>— Pilih modul —</option>
                        @foreach($modul as $kunci => $m)
                            <option value="{{ $kunci }}" @selected(old('modul') === $kunci)>{{ $m['label'] }}</option>
                        @endforeach
                    </select>
                    <p style="font-size:0.72rem;color:#94a3b8;margin:0.35rem 0 0;" id="deskripsi-modul"></p>
                </div>
                <div>
                    <label for="target_uji" style="display:block;font-size:0.8rem;font-weight:600;color:#334155;margin-bottom:0.35rem;">
                        Berkas Uji Tertentu <span style="font-weight:400;color:#94a3b8;">(opsional)</span>
                    </label>
                    <input type="text" id="target_uji" name="target_uji"
                           value="{{ old('target_uji') }}"
                           placeholder="tests/Feature/ContohTest.php"
                           style="width:100%;padding:0.55rem 0.7rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;color:#1e293b;">
                    <p style="font-size:0.72rem;color:#94a3b8;margin:0.35rem 0 0;">
                        Kosongkan untuk menjalankan seluruh berkas pada modul terpilih.
                    </p>
                </div>
            </div>
            <div style="margin-top:1.1rem;">
                <button type="submit"
                        style="background:#047857;color:#fff;border:none;border-radius:0.5rem;padding:0.6rem 1.4rem;font-size:0.85rem;font-weight:600;cursor:pointer;">
                    Run Test
                </button>
            </div>
        </form>
    </div>

    {{-- ── TABEL RIWAYAT ───────────────────────────────── --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <div style="padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;">
            <h2 style="font-size:1rem;font-weight:700;color:#1e293b;margin:0;">Hasil &amp; Riwayat Pengujian</h2>
        </div>

        @if($riwayat->count())
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:0.7rem 0.9rem;text-align:left;font-weight:600;color:#475569;">#</th>
                        <th style="padding:0.7rem 0.9rem;text-align:left;font-weight:600;color:#475569;">Tanggal Uji</th>
                        <th style="padding:0.7rem 0.9rem;text-align:left;font-weight:600;color:#475569;">Modul</th>
                        <th style="padding:0.7rem 0.9rem;text-align:center;font-weight:600;color:#475569;">Status</th>
                        <th style="padding:0.7rem 0.9rem;text-align:center;font-weight:600;color:#475569;">Total</th>
                        <th style="padding:0.7rem 0.9rem;text-align:center;font-weight:600;color:#475569;">Lulus</th>
                        <th style="padding:0.7rem 0.9rem;text-align:center;font-weight:600;color:#475569;">Gagal</th>
                        <th style="padding:0.7rem 0.9rem;text-align:center;font-weight:600;color:#475569;">Dilewati</th>
                        <th style="padding:0.7rem 0.9rem;text-align:center;font-weight:600;color:#475569;">Assertions</th>
                        <th style="padding:0.7rem 0.9rem;text-align:center;font-weight:600;color:#475569;">Durasi</th>
                        <th style="padding:0.7rem 0.9rem;text-align:left;font-weight:600;color:#475569;">Oleh</th>
                        <th style="padding:0.7rem 0.9rem;text-align:center;font-weight:600;color:#475569;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($riwayat as $run)
                    @php
                        $warna = match($run->status) {
                            \App\Models\TestRun::STATUS_PASSED  => ['bg' => '#ecfdf5', 'fg' => '#047857'],
                            \App\Models\TestRun::STATUS_FAILED  => ['bg' => '#fef2f2', 'fg' => '#b91c1c'],
                            \App\Models\TestRun::STATUS_ERROR   => ['bg' => '#fff7ed', 'fg' => '#c2410c'],
                            default                             => ['bg' => '#eff6ff', 'fg' => '#1d4ed8'],
                        };
                    @endphp
                    <tr style="border-bottom:1px solid #f1f5f9;vertical-align:top;">
                        <td style="padding:0.7rem 0.9rem;color:#94a3b8;">{{ ($riwayat->currentPage() - 1) * $riwayat->perPage() + $loop->iteration }}</td>
                        <td style="padding:0.7rem 0.9rem;white-space:nowrap;color:#334155;">
                            {{ $run->tanggal_uji?->format('d M Y') }}
                            <div style="font-size:0.7rem;color:#94a3b8;">{{ $run->created_at?->format('H:i') }}</div>
                        </td>
                        <td style="padding:0.7rem 0.9rem;color:#1e293b;">
                            <div style="font-weight:600;">{{ $run->modul }}</div>
                            @if($run->target_uji)
                                <div style="font-size:0.7rem;color:#64748b;word-break:break-all;">{{ $run->target_uji }}</div>
                            @endif
                        </td>
                        <td style="padding:0.7rem 0.9rem;text-align:center;">
                            <span style="display:inline-block;padding:0.2rem 0.6rem;border-radius:0.375rem;font-size:0.72rem;font-weight:700;background:{{ $warna['bg'] }};color:{{ $warna['fg'] }};">
                                {{ $run->labelStatus() }}
                            </span>
                        </td>
                        <td style="padding:0.7rem 0.9rem;text-align:center;color:#334155;">{{ $run->jumlah_tes }}</td>
                        <td style="padding:0.7rem 0.9rem;text-align:center;color:#047857;font-weight:600;">{{ $run->lulus }}</td>
                        <td style="padding:0.7rem 0.9rem;text-align:center;color:{{ $run->gagal ? '#b91c1c' : '#94a3b8' }};font-weight:{{ $run->gagal ? '700' : '400' }};">{{ $run->gagal }}</td>
                        <td style="padding:0.7rem 0.9rem;text-align:center;color:{{ $run->dilewati ? '#c2410c' : '#94a3b8' }};">{{ $run->dilewati }}</td>
                        <td style="padding:0.7rem 0.9rem;text-align:center;color:#475569;">{{ number_format($run->assertions) }}</td>
                        <td style="padding:0.7rem 0.9rem;text-align:center;white-space:nowrap;color:#475569;">
                            {{ $run->durasi_ms ? number_format($run->durasi_ms / 1000, 2) . 's' : '-' }}
                        </td>
                        <td style="padding:0.7rem 0.9rem;color:#475569;">{{ $run->nama_pelaksana ?? ($run->pelaksana->name ?? '-') }}</td>
                        <td style="padding:0.7rem 0.9rem;text-align:center;">
                            <form method="POST" action="{{ route('pengurus.riwayat-pengujian.hapus', $run->id) }}"
                                  onsubmit="return confirm('Hapus entri riwayat ini?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        style="background:none;border:1px solid #fecaca;color:#b91c1c;border-radius:0.375rem;padding:0.3rem 0.6rem;font-size:0.72rem;font-weight:600;cursor:pointer;">
                                    Hapus
                                </button>
                            </form>
                        </td>
                    </tr>
                    {{-- Detail kegagalan / cuplikan keluaran --}}
                    @if($run->status !== \App\Models\TestRun::STATUS_PASSED && $run->cuplikan_keluaran)
                    <tr style="background:#fffbfb;border-bottom:1px solid #f1f5f9;">
                        <td colspan="12" style="padding:0 0.9rem 0.9rem;">
                            <details>
                                <summary style="cursor:pointer;font-size:0.75rem;color:#b91c1c;font-weight:600;">
                                    Lihat cuplikan keluaran pengujian
                                </summary>
                                <pre style="margin:0.5rem 0 0;background:#0f172a;color:#e2e8f0;padding:0.75rem;border-radius:0.5rem;font-size:0.7rem;line-height:1.45;max-height:22rem;overflow:auto;white-space:pre-wrap;word-break:break-word;">{{ $run->cuplikan_keluaran }}</pre>
                            </details>
                        </td>
                    </tr>
                    @endif
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="padding:1rem;border-top:1px solid #e2e8f0;">
            {{ $riwayat->links() }}
        </div>
        @else
        <div style="padding:2.5rem 1rem;text-align:center;color:#64748b;">
            <p style="margin:0;font-size:0.9rem;">Belum ada riwayat pengujian.</p>
            <p style="margin:0.35rem 0 0;font-size:0.8rem;color:#94a3b8;">Jalankan pengujian pertama Anda melalui formulir di atas.</p>
        </div>
        @endif
    </div>

</div>

<script>
    // Tampilkan deskripsi modul terpilih sebagai bantuan.
    (function () {
        var data = @json(collect($modul)->map(fn ($m) => $m['deskripsi'] ?? ''));
        var select = document.getElementById('modul');
        var info = document.getElementById('deskripsi-modul');
        if (!select || !info) return;
        function perbarui() { info.textContent = data[select.value] || ''; }
        select.addEventListener('change', perbarui);
        perbarui();
    })();
</script>
@endsection
