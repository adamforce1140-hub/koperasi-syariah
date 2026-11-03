@extends('layouts.app')
@section('page-title', "Simpanan Wajib")

@section('title', 'Simpanan Wajib')

@section('sidebar')
<div class="nav-section">Kelola Anggota</div>
<a href="{{ route('teller.anggota.index') }}" class="nav-item {{ request()->routeIs('teller.anggota.*') ? 'active' : '' }}">
    <span class="icon">&#128101;</span> Data & Daftar Anggota
</a>
<div class="nav-section">Teller</div>
<a href="{{ route('dashboard.teller') }}" class="nav-item {{ request()->routeIs('dashboard.teller') ? 'active' : '' }}">
    <span class="icon">&#127968;</span> Dashboard
</a>
<a href="{{ route('teller.setor') }}" class="nav-item {{ request()->routeIs('teller.setor') ? 'active' : '' }}">
    <span class="icon">&#128176;</span> Setor Tunai
</a>
<a href="{{ route('teller.tarik') }}" class="nav-item {{ request()->routeIs('teller.tarik') ? 'active' : '' }}">
    <span class="icon">&#128184;</span> Tarik Tunai
</a>
<a href="{{ route('teller.angsuran') }}" class="nav-item {{ request()->routeIs('teller.angsuran') ? 'active' : '' }}">
    <span class="icon">&#128200;</span> Bayar Angsuran
</a>
<a href="{{ route('teller.simpanan-wajib.index') }}" class="nav-item active">
    <span class="icon">&#127974;</span> Simpanan Wajib
</a>
@endsection

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- Header --}}
    <div>
        <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;">Simpanan Wajib Bulanan</h1>
        <p style="margin:.25rem 0 0;font-size:.83rem;color:#64748b;">
            Tagihan Simpanan Wajib seluruh anggota. Anggota tanpa auto-debit melunasi di loket ini.
            Jurnal otomatis: <strong>DEBET Kas Teller (1-1001)</strong> &rarr; <strong>KREDIT Simpanan Wajib (3-1002)</strong> — PSAK 101.
        </p>
    </div>

    {{-- Notifikasi --}}
    <div aria-live="polite">
        @if(session('success'))
        <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:.75rem 1rem;border-radius:8px;">
            &#10003; {{ session('success') }}
        </div>
        @endif
        @if(session('warning'))
        <div style="background:#fef3c7;border:1px solid #fcd34d;color:#92400e;padding:.75rem 1rem;border-radius:8px;margin-top:.5rem;">
            &#9888; {{ session('warning') }}
        </div>
        @endif
        @if(session('error'))
        <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.75rem 1rem;border-radius:8px;margin-top:.5rem;">
            &#9888; {{ session('error') }}
        </div>
        @endif
        @if($errors->any())
        <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.75rem 1rem;border-radius:8px;margin-top:.5rem;">
            {{ $errors->first() }}
        </div>
        @endif
    </div>

    {{-- Stat Cards --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;">
        <div style="background:linear-gradient(135deg,#b45309,#92400e);color:#fff;border-radius:.875rem;padding:1.125rem 1.25rem;">
            <div style="font-size:1.2rem;font-weight:700;">Rp {{ number_format($ringkasan['total_tunggakan'], 0, ',', '.') }}</div>
            <div style="font-size:.75rem;opacity:.85;margin-top:.2rem;">Total Tagihan Belum Lunas</div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;padding:1.125rem 1.25rem;">
            <div style="font-size:1.4rem;font-weight:700;color:#dc2626;">{{ number_format($ringkasan['overdue'], 0, ',', '.') }}</div>
            <div style="font-size:.75rem;color:#64748b;margin-top:.2rem;">Tagihan OVERDUE (Tunggakan)</div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;padding:1.125rem 1.25rem;">
            <div style="font-size:1.4rem;font-weight:700;color:#d97706;">{{ number_format($ringkasan['unpaid'], 0, ',', '.') }}</div>
            <div style="font-size:.75rem;color:#64748b;margin-top:.2rem;">Tagihan UNPAID (Belum Jatuh Tempo)</div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;padding:1.125rem 1.25rem;">
            <div style="font-size:1.4rem;font-weight:700;color:#7c3aed;">{{ number_format($ringkasan['ditangguhkan'] ?? 0, 0, ',', '.') }}</div>
            <div style="font-size:.75rem;color:#64748b;margin-top:.2rem;">DITANGGUHKAN — Menunggu Konfirmasi</div>
        </div>
    </div>

    {{-- Filter --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;padding:1rem 1.25rem;">
        <form method="GET" action="{{ route('teller.simpanan-wajib.index') }}"
              style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;">
            <div style="flex:1;min-width:220px;">
                <label for="filter-cari" style="display:block;font-size:.76rem;font-weight:600;color:#475569;margin-bottom:.375rem;">Cari Anggota</label>
                <input type="text" id="filter-cari" name="cari" value="{{ request('cari') }}" placeholder="Nama atau No. Anggota…"
                       style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.825rem;box-sizing:border-box;">
            </div>
            <div style="min-width:150px;">
                <label for="filter-status" style="display:block;font-size:.76rem;font-weight:600;color:#475569;margin-bottom:.375rem;">Status</label>
                <select id="filter-status" name="status" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.825rem;">
                    <option value="">Semua Status</option>
                    <option value="UNPAID"  {{ request('status') === 'UNPAID'  ? 'selected' : '' }}>UNPAID</option>
                    <option value="OVERDUE" {{ request('status') === 'OVERDUE' ? 'selected' : '' }}>OVERDUE</option>
                    <option value="PENDING_CONFIRMATION" {{ request('status') === 'PENDING_CONFIRMATION' ? 'selected' : '' }}>DITANGGUHKAN</option>
                    <option value="PAID"    {{ request('status') === 'PAID'    ? 'selected' : '' }}>PAID</option>
                </select>
            </div>
            <div style="min-width:150px;">
                <label for="filter-periode" style="display:block;font-size:.76rem;font-weight:600;color:#475569;margin-bottom:.375rem;">Periode</label>
                <select id="filter-periode" name="periode" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.825rem;">
                    <option value="">Semua Periode</option>
                    @foreach($periodeTersedia as $p)
                    <option value="{{ $p }}" {{ request('periode') === $p ? 'selected' : '' }}>{{ $p }}</option>
                    @endforeach
                </select>
            </div>
            <div style="display:flex;gap:.5rem;">
                <button type="submit"
                        style="padding:.5rem 1.125rem;background:#b45309;color:#fff;font-size:.825rem;font-weight:600;border:none;border-radius:.5rem;cursor:pointer;">
                    Cari
                </button>
                @if(request()->hasAny(['cari', 'status', 'periode']))
                <a href="{{ route('teller.simpanan-wajib.index') }}"
                   style="display:inline-flex;align-items:center;padding:.5rem .875rem;background:#f1f5f9;color:#64748b;font-size:.825rem;font-weight:600;border-radius:.5rem;text-decoration:none;">
                    Reset
                </a>
                @endif
            </div>
        </form>
    </div>

    {{-- PANEL TERKUNCI — Simpanan Wajib (3-1002) adalah MODAL/EKUITAS anggota
         (PSAK 101 ¶ 31). Penarikan rutin DIHAPUS dari antarmuka; pengembalian
         dana hanya melalui modul Pengunduran Diri (Exit Keanggotaan). --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;padding:1.125rem 1.25rem;">
        <h2 style="font-size:.95rem;font-weight:700;color:#1e293b;margin:0 0 .25rem;">Penarikan Simpanan Wajib</h2>
        <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:.75rem 1rem;border-radius:8px;font-size:.8rem;line-height:1.55;">
            &#128274; <strong>Terkunci &mdash; Modal/Ekuitas Koperasi.</strong>
            Simpanan Wajib (akun <code>3-1002</code>) tidak dapat ditarik selama anggota berstatus aktif.
            Pengembalian dana hanya dapat diproses melalui modul
            <strong>Pengunduran Diri / Exit Keanggotaan</strong>
            (<code>member_terminations</code>), yaitu setelah seluruh kewajiban anggota lunas
            (PSAK 101 ¶ 31 &amp; PSAK 108).
        </div>
    </div>

    {{-- Tabel Tagihan --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;overflow:hidden;">
        <div style="padding:.875rem 1.25rem;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;">
            <span style="font-size:.825rem;color:#64748b;">
                <strong style="color:#1e293b;">{{ $tagihan->total() }}</strong> tagihan ditemukan
            </span>
            <span style="font-size:.75rem;color:#94a3b8;">Hal. {{ $tagihan->currentPage() }} / {{ $tagihan->lastPage() }}</span>
        </div>

        @if($tagihan->count() > 0)
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <caption class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">
                    Daftar tagihan Simpanan Wajib bulanan anggota koperasi
                </caption>
                <thead>
                    <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                        <th scope="col" style="padding:.75rem 1.25rem;text-align:left;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Periode</th>
                        <th scope="col" style="padding:.75rem 1rem;text-align:left;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Anggota</th>
                        <th scope="col" style="padding:.75rem 1rem;text-align:right;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Nominal</th>
                        <th scope="col" style="padding:.75rem 1rem;text-align:left;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Tenggat</th>
                        <th scope="col" style="padding:.75rem 1rem;text-align:center;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Status</th>
                        <th scope="col" style="padding:.75rem 1rem;text-align:left;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Pelunasan</th>
                        <th scope="col" style="padding:.75rem 1rem;text-align:center;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Aksi</th>
                    </tr>
                </thead>
                <tbody data-daftar-tagihan="simpanan-wajib">
                    @foreach($tagihan as $t)
                    @php
                        $statusColor = match($t->status) {
                            'PAID'    => ['bg' => '#d1fae5', 'text' => '#065f46'],
                            'OVERDUE' => ['bg' => '#fee2e2', 'text' => '#991b1b'],
                            'PENDING_CONFIRMATION' => ['bg' => '#ede9fe', 'text' => '#5b21b6'],
                            default   => ['bg' => '#fef3c7', 'text' => '#92400e'],
                        };
                        // Ringkasan penanganan dinamis tagihan ini (kekurangan saldo
                        // Sukarela, jadwal bertahap, reschedule) + tenggat bayar.
                        $p        = $penanganan[$t->id] ?? null;
                        $tenggat  = $p['tenggat'] ?? null;
                        $lewat    = $tenggat && $tenggat->isPast() && ! $t->isPaid();
                    @endphp
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:.875rem 1.25rem;">
                            <span style="font-family:monospace;font-size:.8rem;font-weight:600;color:#7c3aed;background:#ede9fe;padding:.2rem .5rem;border-radius:.375rem;">
                                {{ $t->bill_period }}
                            </span>
                            <div style="font-size:.72rem;color:#94a3b8;margin-top:.2rem;">{{ $t->nama_periode }}</div>
                        </td>
                        <td style="padding:.875rem 1rem;">
                            <div style="font-size:.875rem;font-weight:600;color:#1e293b;">{{ $t->member?->nama_lengkap ?? '—' }}</div>
                            <div style="font-size:.75rem;color:#94a3b8;">{{ $t->member?->no_anggota ?? '' }}</div>
                        </td>
                        <td style="padding:.875rem 1rem;text-align:right;">
                            <span style="font-size:.875rem;font-weight:700;color:#b45309;">
                                Rp {{ number_format((float) $t->amount, 0, ',', '.') }}
                            </span>
                        </td>
                        <td style="padding:.875rem 1rem;font-size:.8rem;color:{{ $lewat ? '#dc2626' : '#475569' }};">
                            @if($tenggat)
                                <span style="font-weight:600;">{{ $tenggat->format('d/m/Y') }}</span>
                                @if($lewat)
                                <div style="font-size:.72rem;color:#dc2626;font-weight:600;">Lewat jatuh tempo</div>
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        <td style="padding:.875rem 1rem;text-align:center;">
                            <span style="display:inline-flex;align-items:center;padding:.2rem .65rem;
                                         background:{{ $statusColor['bg'] }};color:{{ $statusColor['text'] }};
                                         font-size:.75rem;font-weight:600;border-radius:9999px;">
                                {{ $t->status }}
                            </span>
                            @if($t->isPendingConfirmation())
                            <div style="font-size:0.7rem;color:#7c3aed;margin-top:.3rem;line-height:1.35;">
                                Alasan: {{ $t->deferred_reason ?? '—' }}
                                @if($t->deferred_at)<br>{{ $t->deferred_at->format('d/m/Y H:i') }}@endif
                            </div>
                            @endif
                        </td>
                        <td style="padding:.875rem 1rem;font-size:.8rem;color:#64748b;">
                            @if($t->isPaid())
                                {{ $t->paid_at?->format('d/m/Y H:i') ?? '—' }}
                                <div style="font-size:.72rem;color:#94a3b8;">{{ $t->metode_label }}</div>
                            @else
                                —
                            @endif
                        </td>
                        <td style="padding:.875rem 1rem;text-align:center;">
                            @if($t->isPaid())
                                @if($t->transaksi_id)
                                <a href="{{ route('teller.nota', $t->transaksi_id) }}"
                                   style="display:inline-flex;align-items:center;padding:.35rem .75rem;background:#f1f5f9;color:#374151;font-size:.78rem;font-weight:600;border-radius:.375rem;text-decoration:none;">
                                    Nota
                                </a>
                                @else
                                <span style="font-size:.78rem;color:#94a3b8;">Lunas</span>
                                @endif
                            @else
                            <!-- Kolom aksi tunggal: pelunasan + panel penanganan dinamis -->
                            <div style="display:flex;flex-direction:column;gap:.5rem;align-items:stretch;min-width:230px;">

                                {{-- JALUR 0 (BUG-03): KONFIRMASI MANUAL setoran yang DITANGGUHKAN --}}
                                @if($t->isPendingConfirmation())
                                @can('transaksi', \App\Models\User::class)
                                <div style="background:#faf5ff;border:1px solid #ddd6fe;border-radius:.5rem;padding:.5rem .6rem;text-align:left;">
                                    <div style="font-size:.72rem;font-weight:700;color:#5b21b6;margin-bottom:.35rem;">
                                        Setoran Otomatis Ditangguhkan — Butuh Konfirmasi
                                    </div>
                                    <div style="font-size:.7rem;color:#64748b;line-height:1.4;margin-bottom:.45rem;">
                                        Dana TIDAK dipotong. Konfirmasi hanya berhasil bila verifikasi status pembayaran lolos (saldo Sukarela cukup, anggota aktif).
                                    </div>
                                    <form method="POST" action="{{ route('teller.simpanan-wajib.konfirmasi', $t->id) }}" style="margin:0 0 .35rem;"
                                          onsubmit="return confirm('Konfirmasi & jalankan setoran otomatis periode {{ $t->nama_periode }}?');">
                                        @csrf
                                        <input type="text" name="catatan" maxlength="500" aria-label="Catatan konfirmasi (opsional)" placeholder="Catatan konfirmasi (opsional)"
                                               style="width:100%;padding:.3rem .45rem;border:1px solid #cbd5e1;border-radius:.375rem;font-size:.72rem;box-sizing:border-box;margin-bottom:.3rem;">
                                        <button type="submit"
                                                style="width:100%;padding:.35rem .6rem;background:#7c3aed;color:#fff;font-size:.74rem;font-weight:600;border:none;border-radius:.375rem;cursor:pointer;">
                                            Konfirmasi & Setor
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('teller.simpanan-wajib.tolak', $t->id) }}" style="margin:0;"
                                          onsubmit="return confirm('Tolak konfirmasi untuk periode {{ $t->nama_periode }}? Tagihan dialihkan ke penanganan manual tanpa denda.');">
                                        @csrf
                                        <button type="submit"
                                                style="width:100%;padding:.35rem .6rem;background:#fff;color:#b91c1c;font-size:.74rem;font-weight:600;border:1px solid #fecaca;border-radius:.375rem;cursor:pointer;">
                                            Tolak Konfirmasi
                                        </button>
                                    </form>
                                </div>
                                @endcan
                                @endif

                                {{-- JALUR 1: Pelunasan penuh di loket (tunai/transfer) --}}
                                @can('transaksi', \App\Models\User::class)
                                <form method="POST" action="{{ route('teller.simpanan-wajib.bayar', $t->id) }}"
                                      style="display:flex;gap:.375rem;align-items:center;margin:0;"
                                      onsubmit="return confirm('Lunasi tagihan {{ $t->nama_periode }} sebesar Rp {{ number_format((float) $t->amount, 0, ',', '.') }}?');">
                                    @csrf
                                    <label for="metode-{{ $t->id }}" class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">Metode</label>
                                    <select id="metode-{{ $t->id }}" name="metode"
                                            style="flex:1;padding:.35rem .5rem;border:1px solid #cbd5e1;border-radius:.375rem;font-size:.78rem;">
                                        <option value="CASH">Tunai</option>
                                        <option value="TRANSFER">Transfer</option>
                                    </select>
                                    <button type="submit"
                                            style="padding:.35rem .75rem;background:#059669;color:#fff;font-size:.78rem;font-weight:600;border:none;border-radius:.375rem;cursor:pointer;">
                                        Lunasi
                                    </button>
                                </form>

                                {{-- Ringkasan penanganan dinamis: saldo Sukarela vs kekurangan --}}
                                @if($p)
                                <div style="font-size:.72rem;color:#64748b;text-align:left;line-height:1.4;background:#f8fafc;border:1px solid #e2e8f0;border-radius:.375rem;padding:.4rem .55rem;">
                                    Saldo Sukarela: <strong>Rp {{ number_format($p['saldo_sukarela'], 0, ',', '.') }}</strong>
                                    @if($p['kekurangan'] > 0)
                                    <br>Kekurangan: <strong style="color:#dc2626;">Rp {{ number_format($p['kekurangan'], 0, ',', '.') }}</strong>
                                    @else
                                    <br><strong style="color:#059669;">Saldo mencukupi</strong>
                                    @endif
                                    @if($p['ada_jadwal'])
                                    <br>Jadwal: <strong>{{ $p['total_cicilan'] }} &times; Rp {{ number_format($p['nominal_cicilan'], 0, ',', '.') }}</strong>
                                    (terbayar Rp {{ number_format($p['sudah_dicicil'], 0, ',', '.') }})
                                    @endif
                                    @if($p['jumlah_reschedule'] > 0)
                                    <br>Reschedule: <strong>{{ $p['jumlah_reschedule'] }}&times;</strong>
                                    @endif
                                    <br><span style="color:#059669;">Tanpa denda (prinsip syariah)</span>
                                </div>
                                @endif

                                {{-- JALUR 2: Penanganan dinamis (bertahap / reschedule / cicilan) --}}
                                @if($kebijakan['penanganan_dinamis_aktif'])
                                <div style="display:flex;flex-wrap:wrap;gap:.35rem;">
                                    @if(! $p['ada_jadwal'])
                                    <form method="POST" action="{{ route('teller.simpanan-wajib.jadwal', $t->id) }}" style="margin:0;"
                                          onsubmit="return confirm('Tetapkan jadwal pembayaran bertahap untuk periode {{ $t->nama_periode }}?');">
                                        @csrf
                                        <input type="hidden" name="jumlah_cicilan" value="{{ $kebijakan['maks_cicilan'] > 0 ? min($kebijakan['maks_cicilan'], 6) : 6 }}">
                                        <button type="submit"
                                                style="padding:.3rem .6rem;background:#0ea5e9;color:#fff;font-size:.72rem;font-weight:600;border:none;border-radius:.375rem;cursor:pointer;"
                                                title="Pecah tagihan menjadi beberapa cicilan sesuai kemampuan anggota">
                                            Jadwal Bertahap
                                        </button>
                                    </form>
                                    @else
                                    <form method="POST" action="{{ route('teller.simpanan-wajib.cicilan', $t->id) }}" style="margin:0;"
                                          onsubmit="return confirm('Potong satu cicilan dari saldo Simpanan Sukarela anggota?');">
                                        @csrf
                                        <button type="submit"
                                                style="padding:.3rem .6rem;background:#7c3aed;color:#fff;font-size:.72rem;font-weight:600;border:none;border-radius:.375rem;cursor:pointer;"
                                                title="Potong cicilan dari saldo Simpanan Sukarela">
                                            Bayar Cicilan
                                        </button>
                                    </form>
                                    @endif

                                    @if($p['boleh_reschedule'])
                                    <form method="POST" action="{{ route('teller.simpanan-wajib.reschedule', $t->id) }}" style="margin:0;"
                                          onsubmit="return confirm('Geser tenggat tagihan periode {{ $t->nama_periode }} TANPA DENDA?');">
                                        @csrf
                                        <button type="submit"
                                                style="padding:.3rem .6rem;background:#f59e0b;color:#fff;font-size:.72rem;font-weight:600;border:none;border-radius:.375rem;cursor:pointer;"
                                                title="Penjadwalan ulang tanpa denda sesuai prinsip syariah">
                                            Reschedule
                                        </button>
                                    </form>
                                    @endif

                                    <a href="{{ route('teller.simpanan-wajib.riwayat', $t->id) }}"
                                       style="padding:.3rem .6rem;background:#f1f5f9;color:#374151;font-size:.72rem;font-weight:600;border-radius:.375rem;text-decoration:none;">
                                        Riwayat
                                    </a>
                                </div>
                                @else
                                <div style="font-size:.72rem;color:#94a3b8;text-align:left;">
                                    Penanganan dinamis dinonaktifkan Pengurus.
                                </div>
                                @endif
                                @endcan

                            </div>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($tagihan->hasPages())
        <div style="padding:.875rem 1.25rem;border-top:1px solid #f1f5f9;">{{ $tagihan->links() }}</div>
        @endif
        @else
        <div style="padding:3.5rem 1.5rem;text-align:center;">
            <div style="font-size:.95rem;font-weight:600;color:#1e293b;margin-bottom:.375rem;">Tidak ada tagihan</div>
            <p style="font-size:.825rem;color:#94a3b8;">Belum ada tagihan Simpanan Wajib yang sesuai filter.</p>
        </div>
        @endif
    </div>

</div>
@endsection
