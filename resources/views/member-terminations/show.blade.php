@extends('layouts.app')
@section('page-title', "Detail Exit Keanggotaan")

@section('title', 'Detail Exit Keanggotaan — ' . $terminasi->no_terminasi)

@section('sidebar')
<div class="nav-section">Exit Keanggotaan</div>
<a href="{{ route('member-terminations.index') }}" class="nav-item {{ request()->routeIs('member-terminations.index') ? 'active' : '' }}">
    <span class="icon">&#128196;</span> Pengunduran Diri
</a>
<a href="{{ route('member-terminations.create') }}" class="nav-item {{ request()->routeIs('member-terminations.create') ? 'active' : '' }}">
    <span class="icon">&#128221;</span> Ajukan Exit Baru
</a>
@endsection

@php
    use App\Models\MemberTermination;
    $errors = $errors ?? new \Illuminate\Support\ViewErrorBag();
    $pengguna = auth()->user();
    $bolehEksekusi = \App\Support\HierarkiRole::dalamKelompok($pengguna->role ?? null, 'eksekusi_terminasi');
    $bolehApproval = in_array((string) ($pengguna->role ?? ''), \App\Support\Izin::peran('terminasi.approval'), true);
    $sudahDiposting = $terminasi->sudahDiposting();
    $menungguApproval = $terminasi->menungguApproval();
    $sudahDisetujui = $terminasi->approved_at !== null;
    $bolehCairkan = $sudahDisetujui || ! $menungguApproval;

    // NIK disamarkan (UU PDP No. 27/2022) — operator tidak perlu NIK penuh.
    $nikMasked = null;
    if ($anggota?->nik) {
        $nik = (string) $anggota->nik;
        $nikMasked = strlen($nik) <= 4
            ? str_repeat('*', strlen($nik))
            : str_repeat('*', strlen($nik) - 4) . substr($nik, -4);
    }

    $warna = match($terminasi->status) {
        MemberTermination::STATUS_DIAJUKAN          => ['#fef3c7', '#92400e'],
        MemberTermination::STATUS_MENUNGGU_APPROVAL => ['#dbeafe', '#1e40af'],
        MemberTermination::STATUS_DISETUJUI         => ['#d1fae5', '#065f46'],
        MemberTermination::STATUS_DITOLAK           => ['#fee2e2', '#991b1b'],
        default                                     => ['#f1f5f9', '#475569'],
    };
@endphp

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;max-width:900px;">

    {{-- Header --}}
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;font-family:monospace;">{{ $terminasi->no_terminasi }}</h1>
            <p style="margin:.25rem 0 0;font-size:.83rem;color:#64748b;">
                Dokumen Pengunduran Diri / Exit Keanggotaan &mdash; diajukan {{ $terminasi->created_at?->format('d/m/Y H:i') }}
            </p>
        </div>
        <span style="display:inline-block;padding:.35rem .8rem;border-radius:999px;font-size:.78rem;font-weight:700;background:{{ $warna[0] }};color:{{ $warna[1] }};">
            {{ $terminasi->status_label }}
        </span>
    </div>

    {{-- Notifikasi --}}
    <div aria-live="polite">
        @if(session('success'))
        <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:.75rem 1rem;border-radius:8px;font-size:.83rem;white-space:pre-line;">
            &#10003; {{ session('success') }}
        </div>
        @endif
        @if(session('error'))
        <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.75rem 1rem;border-radius:8px;font-size:.83rem;white-space:pre-line;">
            &#9888; {{ session('error') }}
        </div>
        @endif
        @if($errors->any())
        <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.75rem 1rem;border-radius:8px;font-size:.83rem;">
            <ul style="margin:0;padding-left:1.1rem;">
                @foreach($errors->all() as $err) <li>{{ $err }}</li> @endforeach
            </ul>
        </div>
        @endif
    </div>

    {{-- Data Anggota --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;padding:1.25rem;">
        <h2 style="font-size:.95rem;font-weight:700;color:#1e293b;margin:0 0 .75rem;">Data Anggota</h2>
        @if($anggota)
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.75rem;font-size:.83rem;">
            <div><div style="color:#94a3b8;font-size:.72rem;">Nama</div><div style="font-weight:600;">{{ $anggota->nama_lengkap }}</div></div>
            <div><div style="color:#94a3b8;font-size:.72rem;">No. Anggota</div><div style="font-weight:600;">{{ $anggota->no_anggota }}</div></div>
            <div><div style="color:#94a3b8;font-size:.72rem;">No. CIF</div><div style="font-family:monospace;font-weight:600;">{{ $anggota->no_cif ?? '-' }}</div></div>
            <div><div style="color:#94a3b8;font-size:.72rem;">NIK (disamarkan)</div><div style="font-family:monospace;font-weight:600;">{{ $nikMasked ?? '-' }}</div></div>
            <div><div style="color:#94a3b8;font-size:.72rem;">Alamat</div><div style="font-weight:600;">{{ $anggota->alamat ?: '-' }}</div></div>
            <div>
                <div style="color:#94a3b8;font-size:.72rem;">Status Keanggotaan</div>
                <div style="font-weight:600;color:{{ $anggota->isTerminated() ? '#991b1b' : '#047857' }};">
                    {{ $anggota->status_label }}
                </div>
            </div>
            <div><div style="color:#94a3b8;font-size:.72rem;">Tanggal Pengunduran Diri</div><div style="font-weight:600;">{{ $terminasi->tanggal_pengunduran_diri?->format('d/m/Y') ?? '-' }}</div></div>
            <div><div style="color:#94a3b8;font-size:.72rem;">No. Referensi Dokumen</div><div style="font-family:monospace;font-weight:600;">{{ $terminasi->no_referensi ?? '-' }}</div></div>
        </div>
        @else
        <div style="color:#94a3b8;font-size:.83rem;">Data anggota tidak ditemukan.</div>
        @endif
    </div>

    {{-- Kelayakan Exit --}}
    @if(in_array($terminasi->status, [MemberTermination::STATUS_DIAJUKAN, MemberTermination::STATUS_MENUNGGU_APPROVAL], true) && ! $sudahDiposting)
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;padding:1.25rem;">
        <h2 style="font-size:.95rem;font-weight:700;color:#1e293b;margin:0 0 .75rem;">Pemeriksaan Kelayakan Exit</h2>

        @if($layak)
        <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:.7rem .9rem;border-radius:8px;font-size:.82rem;">
            &#10003; Anggota <strong>BERSIH</strong> dari seluruh kewajiban. Seluruh pembiayaan berstatus
            PAID_OFF/Lunas (NPF = 0), tidak ada tunggakan angsuran, ta&rsquo;zir/denda, tagihan Simpanan Wajib,
            maupun biaya administrasi. Pengembalian dana dapat dieksekusi.
        </div>
        @else
        <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.7rem .9rem;border-radius:8px;font-size:.82rem;">
            <strong>&#9888; Exit belum dapat dieksekusi.</strong> Anggota masih memiliki kewajiban berikut:
            <ul style="margin:.5rem 0 0;padding-left:1.1rem;line-height:1.7;">
                @foreach($kendala as $k) <li>{{ $k }}</li> @endforeach
            </ul>
            <div style="margin-top:.5rem;">Selesaikan seluruh kewajiban di atas terlebih dahulu, lalu ulangi pemeriksaan.</div>
        </div>
        @endif

        {{-- Rincian saldo yang akan dicairkan --}}
        @if($errorRincian)
        <div style="margin-top:1rem;background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.7rem .9rem;border-radius:8px;font-size:.82rem;">
            <strong>&#9888; Rincian saldo tidak dapat dihitung.</strong> {{ $errorRincian }}
        </div>
        @elseif($rincian)
        <div style="margin-top:1rem;">
            <h3 style="font-size:.85rem;font-weight:700;color:#1e293b;margin:0 0 .5rem;">Rincian Saldo yang Dicairkan</h3>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:.82rem;">
                    <tbody>
                        <tr>
                            <td style="padding:.45rem .6rem;border-bottom:1px solid #f1f5f9;">Simpanan Pokok <code style="font-size:.72rem;color:#94a3b8;">3-1001</code></td>
                            <td style="padding:.45rem .6rem;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:600;">Rp {{ number_format((float) $rincian['pokok'], 2, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td style="padding:.45rem .6rem;border-bottom:1px solid #f1f5f9;">Simpanan Wajib <code style="font-size:.72rem;color:#94a3b8;">3-1002</code></td>
                            <td style="padding:.45rem .6rem;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:600;">Rp {{ number_format((float) $rincian['wajib'], 2, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td style="padding:.45rem .6rem;border-bottom:1px solid #f1f5f9;">Simpanan Sukarela / Wadiah <code style="font-size:.72rem;color:#94a3b8;">2-1001</code></td>
                            <td style="padding:.45rem .6rem;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:600;">Rp {{ number_format((float) $rincian['sukarela'], 2, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td style="padding:.45rem .6rem;border-bottom:1px solid #f1f5f9;">Simpanan Berjangka / Deposito <code style="font-size:.72rem;color:#94a3b8;">2-1003</code></td>
                            <td style="padding:.45rem .6rem;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:600;">Rp {{ number_format((float) $rincian['deposito'], 2, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td style="padding:.45rem .6rem;border-bottom:1px solid #f1f5f9;">Akrual Hak Bagi Hasil <span style="font-size:.72rem;color:#94a3b8;">(proporsional s.d. {{ \Carbon\Carbon::parse($rincian['tanggal'])->format('d/m/Y') }})</span></td>
                            <td style="padding:.45rem .6rem;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:600;">Rp {{ number_format((float) $rincian['akrual_bagi_hasil'], 2, ',', '.') }}</td>
                        </tr>
                        <tr style="background:#f8fafc;">
                            <td style="padding:.45rem .6rem;border-bottom:1px solid #e2e8f0;font-weight:700;">Bruto (simpanan + akrual)</td>
                            <td style="padding:.45rem .6rem;border-bottom:1px solid #e2e8f0;text-align:right;font-weight:700;">Rp {{ number_format((float) $rincian['bruto'], 2, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td style="padding:.45rem .6rem;border-bottom:1px solid #f1f5f9;color:#991b1b;">Potongan Zakat <code style="font-size:.72rem;">2-3002</code></td>
                            <td style="padding:.45rem .6rem;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:600;color:#991b1b;">&minus; Rp {{ number_format((float) $rincian['zakat'], 2, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td style="padding:.45rem .6rem;border-bottom:1px solid #f1f5f9;color:#991b1b;">Biaya Administrasi Penutupan CIF <code style="font-size:.72rem;">4-2001</code></td>
                            <td style="padding:.45rem .6rem;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:600;color:#991b1b;">&minus; Rp {{ number_format((float) $rincian['biaya_admin'], 2, ',', '.') }}</td>
                        </tr>
                        <tr style="background:#ecfdf5;">
                            <td style="padding:.65rem .6rem;font-weight:800;color:#065f46;">TOTAL NET DITERIMA ANGGOTA</td>
                            <td style="padding:.65rem .6rem;text-align:right;font-weight:800;font-size:1rem;color:#065f46;">Rp {{ number_format((float) $rincian['total_net'], 2, ',', '.') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- Detail deposito --}}
            @if(! empty($rincian['deposito_detail']))
            <div style="margin-top:.75rem;font-size:.78rem;color:#475569;">
                <strong>Rincian Deposito:</strong>
                <ul style="margin:.35rem 0 0;padding-left:1.1rem;line-height:1.7;">
                    @foreach($rincian['deposito_detail'] as $d)
                    <li>
                        <span style="font-family:monospace;">{{ $d['nomor'] ?? '-' }}</span> &mdash;
                        pokok Rp {{ number_format((float) $d['pokok'], 2, ',', '.') }},
                        akrual Rp {{ number_format((float) $d['akrual'], 2, ',', '.') }}
                        ({{ (int) $d['hari_akrual'] }} hari, nisbah {{ (float) $d['nisbah_anggota'] }}%)
                        @if(! empty($d['jatuh_tempo']))
                        <span style="color:#047857;font-weight:600;">&mdash; jatuh tempo {{ \Carbon\Carbon::parse($d['maturity_date'])->format('d/m/Y') }}</span>
                        @endif
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif

            {{-- Dasar perhitungan zakat --}}
            @if(! empty($rincian['zakat_detail']))
            <div style="margin-top:.5rem;font-size:.75rem;color:#64748b;line-height:1.6;">
                Zakat: {{ $rincian['zakat_detail']['kena_zakat'] ? 'WAJIB' : 'tidak wajib' }}
                (nishab Rp {{ number_format((float) $rincian['zakat_detail']['nishab'], 2, ',', '.') }},
                kadar {{ (float) $rincian['zakat_detail']['kadar_persen'] }}%,
                haul {{ $rincian['zakat_detail']['haul_aktif'] ? ((int) $rincian['zakat_detail']['haul_hari'] . ' hari') : 'nonaktif' }}).
            </div>
            @endif
        </div>
        @else
        <div style="margin-top:1rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem;font-size:.83rem;">
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.75rem .9rem;">
                <div style="color:#64748b;font-size:.72rem;">Simpanan Pokok (<code>3-1001</code>)</div>
                <div style="font-weight:700;font-size:1.05rem;">Rp {{ number_format($saldoPokok, 2, ',', '.') }}</div>
            </div>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.75rem .9rem;">
                <div style="color:#64748b;font-size:.72rem;">Simpanan Wajib (<code>3-1002</code>)</div>
                <div style="font-weight:700;font-size:1.05rem;">Rp {{ number_format($saldoWajib, 2, ',', '.') }}</div>
            </div>
            <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;padding:.75rem .9rem;">
                <div style="color:#065f46;font-size:.72rem;">Subtotal Pokok + Wajib (estimasi)</div>
                <div style="font-weight:700;font-size:1.05rem;color:#065f46;">Rp {{ number_format($total, 2, ',', '.') }}</div>
            </div>
        </div>
        @endif
    </div>
    @endif

    {{-- Panel PERSETUJUAN MANAJER (high-value withdrawal) --}}
    @if($menungguApproval && ! $sudahDiposting)
    <div style="background:#fff;border:1px solid #bfdbfe;border-radius:.875rem;padding:1.25rem;">
        <h2 style="font-size:.95rem;font-weight:700;color:#1e293b;margin:0 0 .25rem;">Persetujuan Manajer &mdash; Pencairan Nilai Tinggi</h2>
        <p style="font-size:.78rem;color:#64748b;margin:0 0 .9rem;">
            Total net pencairan melampaui limit <code>high_value_withdrawal_limit</code>
            (Rp {{ number_format((float) \App\Support\Setting::highValueWithdrawalLimit(), 2, ',', '.') }}).
            Dana <strong>tidak boleh dicairkan</strong> sebelum disetujui oleh Manajer.
        </p>

        @if($sudahDisetujui)
        <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:.7rem .9rem;border-radius:8px;font-size:.82rem;">
            &#10003; Telah <strong>DISETUJUI</strong> oleh
            <strong>{{ $terminasi->disetujuiOleh->name ?? ('user #' . $terminasi->disetujui_oleh) }}</strong>
            pada {{ $terminasi->approved_at?->format('d/m/Y H:i') }}.
            Teller kini dapat mencairkan dana.
        </div>
        @elseif($bolehApproval)
        <div style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;padding:.7rem .9rem;border-radius:8px;font-size:.82rem;margin-bottom:.9rem;">
            &#128274; Periksa rincian saldo di atas sebelum memberikan persetujuan. Persetujuan ini
            tercatat permanen pada <code>exit_approval_logs</code>.
        </div>

        <div style="display:flex;gap:.9rem;flex-wrap:wrap;">
            <form method="POST" action="{{ route('member-terminations.setujui', $terminasi->id) }}"
                  style="display:flex;flex-direction:column;gap:.5rem;flex:1;min-width:260px;"
                  onsubmit="return confirm('Setujui pencairan dana ini? Tindakan ini tercatat pada jejak audit.');">
                @csrf
                <label for="catatan_approval" style="font-size:.75rem;font-weight:600;color:#065f46;">Catatan Persetujuan (opsional)</label>
                <input type="text" name="catatan" id="catatan_approval" maxlength="1000"
                       style="padding:.5rem .6rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.82rem;">
                <button type="submit" style="padding:.6rem 1.2rem;background:#047857;color:#fff;border:0;border-radius:6px;font-size:.83rem;font-weight:700;cursor:pointer;">
                    Setujui Pencairan
                </button>
            </form>

            <form method="POST" action="{{ route('member-terminations.tolak-approval', $terminasi->id) }}"
                  style="display:flex;flex-direction:column;gap:.5rem;flex:1;min-width:260px;"
                  onsubmit="return confirm('Tolak permohonan pencairan ini?');">
                @csrf
                <label for="alasan_penolakan_approval" style="font-size:.75rem;font-weight:600;color:#991b1b;">Alasan Penolakan <span style="color:#dc2626;">*</span></label>
                <input type="text" name="alasan_penolakan" id="alasan_penolakan_approval" required maxlength="1000"
                       style="padding:.5rem .6rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.82rem;">
                <button type="submit" style="padding:.6rem 1.2rem;background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;border-radius:6px;font-size:.83rem;font-weight:700;cursor:pointer;">
                    Tolak Permohonan
                </button>
            </form>
        </div>
        @else
        <div style="background:#f1f5f9;border:1px solid #cbd5e1;color:#475569;padding:.7rem .9rem;border-radius:8px;font-size:.82rem;">
            &#9203; Menunggu persetujuan <strong>Manajer</strong>. Notifikasi in-app &amp; email telah dikirim
            ke seluruh pengguna berperan Manajer. Pencairan dana diblokir sampai persetujuan diberikan.
        </div>
        @endif
    </div>
    @endif

    {{-- Hasil Eksekusi (bila sudah diposting) --}}
    @if($sudahDiposting)
    <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:.875rem;padding:1.25rem;">
        <h2 style="font-size:.95rem;font-weight:700;color:#065f46;margin:0 0 .75rem;">&#128274; Pengembalian Dana Selesai &mdash; Jurnal Terkunci (Append-Only)</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:.75rem;font-size:.83rem;">
            <div><div style="color:#64748b;font-size:.72rem;">Metode</div><div style="font-weight:600;">{{ $terminasi->metode_label }}</div></div>
            <div><div style="color:#64748b;font-size:.72rem;">No. Jurnal</div><div style="font-family:monospace;font-weight:600;">{{ $terminasi->no_jurnal ?? '-' }}</div></div>
            <div><div style="color:#64748b;font-size:.72rem;">Simpanan Pokok</div><div style="font-weight:600;">Rp {{ number_format((float) $terminasi->nominal_simpanan_pokok, 2, ',', '.') }}</div></div>
            <div><div style="color:#64748b;font-size:.72rem;">Simpanan Wajib</div><div style="font-weight:600;">Rp {{ number_format((float) $terminasi->nominal_simpanan_wajib, 2, ',', '.') }}</div></div>
            <div><div style="color:#64748b;font-size:.72rem;">Simpanan Sukarela</div><div style="font-weight:600;">Rp {{ number_format((float) $terminasi->nominal_sukarela, 2, ',', '.') }}</div></div>
            <div><div style="color:#64748b;font-size:.72rem;">Simpanan Berjangka</div><div style="font-weight:600;">Rp {{ number_format((float) $terminasi->nominal_deposito, 2, ',', '.') }}</div></div>
            <div><div style="color:#64748b;font-size:.72rem;">Akrual Bagi Hasil</div><div style="font-weight:600;">Rp {{ number_format((float) $terminasi->nominal_akrual_bagi_hasil, 2, ',', '.') }}</div></div>
            <div><div style="color:#64748b;font-size:.72rem;">Potongan Zakat</div><div style="font-weight:600;color:#991b1b;">&minus; Rp {{ number_format((float) $terminasi->nominal_zakat, 2, ',', '.') }}</div></div>
            <div><div style="color:#64748b;font-size:.72rem;">Biaya Admin CIF</div><div style="font-weight:600;color:#991b1b;">&minus; Rp {{ number_format((float) $terminasi->nominal_biaya_admin, 2, ',', '.') }}</div></div>
            <div><div style="color:#64748b;font-size:.72rem;">Total Net Diterima</div><div style="font-weight:700;color:#065f46;">Rp {{ number_format((float) $terminasi->total_dikembalikan, 2, ',', '.') }}</div></div>
            <div><div style="color:#64748b;font-size:.72rem;">Diproses Oleh</div><div style="font-weight:600;">{{ $terminasi->diprosesOleh->name ?? '-' }}</div></div>
            <div><div style="color:#64748b;font-size:.72rem;">Waktu Proses</div><div style="font-weight:600;">{{ $terminasi->diproses_pada?->format('d/m/Y H:i') ?? '-' }}</div></div>
        </div>
        @if($terminasi->membutuhkanRekeningTujuan())
        <div style="margin-top:.75rem;font-size:.8rem;color:#475569;">
            Rekening Tujuan: <strong>{{ $terminasi->nomor_rekening_tujuan }}</strong>
            @if($terminasi->nama_bank_tujuan) &mdash; {{ $terminasi->nama_bank_tujuan }} @endif
        </div>
        @endif

        <div style="margin-top:1rem;background:#fff;border:1px solid #d1fae5;border-radius:8px;padding:.85rem 1rem;font-size:.8rem;line-height:1.8;">
            <strong>Jurnal Syariah (terposted &amp; tidak dapat diubah):</strong><br>
            DEBET&nbsp;&nbsp;<code>3-1001</code> Simpanan Pokok &mdash; Rp {{ number_format((float) $terminasi->nominal_simpanan_pokok, 2, ',', '.') }}<br>
            DEBET&nbsp;&nbsp;<code>3-1002</code> Simpanan Wajib &mdash; Rp {{ number_format((float) $terminasi->nominal_simpanan_wajib, 2, ',', '.') }}<br>
            @if((float) $terminasi->nominal_sukarela > 0)
            DEBET&nbsp;&nbsp;<code>2-1001</code> Simpanan Sukarela &mdash; Rp {{ number_format((float) $terminasi->nominal_sukarela, 2, ',', '.') }}<br>
            @endif
            @if((float) $terminasi->nominal_deposito > 0)
            DEBET&nbsp;&nbsp;<code>2-1003</code> Simpanan Berjangka &mdash; Rp {{ number_format((float) $terminasi->nominal_deposito, 2, ',', '.') }}<br>
            @endif
            @if((float) $terminasi->nominal_akrual_bagi_hasil > 0)
            DEBET&nbsp;&nbsp;<code>5-3001</code> Beban Bagi Hasil Deposito &mdash; Rp {{ number_format((float) $terminasi->nominal_akrual_bagi_hasil, 2, ',', '.') }}<br>
            @endif
            @if((float) $terminasi->nominal_zakat > 0)
            DEBET&nbsp;&nbsp;<code>2-3002</code> Dana Zakat (kewajiban zakat) &mdash; Rp {{ number_format((float) $terminasi->nominal_zakat, 2, ',', '.') }}<br>
            @endif
            KREDIT <code>{{ $terminasi->metode === MemberTermination::METODE_CASH ? '1-1001' : '1-1002' }}</code>
            {{ $terminasi->metode === MemberTermination::METODE_CASH ? 'Kas Teller' : 'Kas di Bank' }}
            &mdash; Rp {{ number_format((float) $terminasi->total_dikembalikan, 2, ',', '.') }}<br>
            @if((float) $terminasi->nominal_biaya_admin > 0)
            KREDIT <code>4-2001</code> Pendapatan Administrasi Penutupan CIF
            &mdash; Rp {{ number_format((float) $terminasi->nominal_biaya_admin, 2, ',', '.') }}
            @endif
        </div>

        <div style="margin-top:1rem;display:flex;flex-wrap:wrap;gap:.6rem;">
            <a href="{{ route('member-terminations.bukti-pdf', $terminasi->id) }}"
               style="display:inline-flex;align-items:center;gap:.4rem;background:#0d5c3f;color:#fff;text-decoration:none;padding:.6rem 1.1rem;border-radius:.5rem;font-size:.82rem;font-weight:600;">
                &#128196; Unduh Bukti Penutupan (PDF)
            </a>
            <a href="{{ route('member-terminations.bukti-thermal', $terminasi->id) }}" target="_blank" rel="noopener"
               style="display:inline-flex;align-items:center;gap:.4rem;background:#fff;color:#0d5c3f;border:1.5px solid #0d5c3f;text-decoration:none;padding:.6rem 1.1rem;border-radius:.5rem;font-size:.82rem;font-weight:600;">
                &#128424; Cetak Struk Thermal 80mm
            </a>
        </div>
        <div style="margin-top:.45rem;font-size:.72rem;color:#64748b;">
            No. Referensi dokumen: <strong style="font-family:monospace;">{{ $terminasi->no_referensi ?? $terminasi->no_terminasi }}</strong>
            &middot; kedua dokumen memuat barcode untuk pemindaian &amp; audit.
        </div>

        <div style="margin-top:.75rem;font-size:.78rem;color:#065f46;">
            &#10003; Status keanggotaan telah diubah menjadi <strong>TERMINATED (Keluar)</strong>.
            Catatan audit: tanggal pengunduran diri {{ $terminasi->tanggal_pengunduran_diri?->format('d/m/Y') ?? '-' }},
            total dana dikembalikan Rp {{ number_format((float) $terminasi->total_dikembalikan, 2, ',', '.') }},
            diproses oleh user #{{ $terminasi->diproses_oleh ?? '-' }}.
        </div>
    </div>
    @endif

    {{-- Panel EKSEKUSI (Teller/Manajer/Pengurus; status DIAJUKAN, atau
         MENUNGGU_APPROVAL yang sudah disetujui Manajer) --}}
    @if(! $sudahDiposting && ($terminasi->status === MemberTermination::STATUS_DIAJUKAN || ($menungguApproval && $sudahDisetujui)))
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;padding:1.25rem;">
        <h2 style="font-size:.95rem;font-weight:700;color:#1e293b;margin:0 0 .25rem;">Eksekusi Pengembalian Dana</h2>
        <p style="font-size:.78rem;color:#64748b;margin:0 0 .9rem;">
            Pilih metode pengembalian. Jurnal PSAK 108 akan diposting otomatis dan hanya SATU KALI;
            setelah terposted jurnal tidak dapat diubah.
        </p>

        @if(! $bolehEksekusi)
        <div style="background:#f1f5f9;border:1px solid #cbd5e1;color:#475569;padding:.7rem .9rem;border-radius:8px;font-size:.82rem;">
            &#128274; Eksekusi pengembalian dana hanya dapat dilakukan oleh <strong>Teller</strong>,
            <strong>Manajer</strong>, atau <strong>Pengurus</strong> (segregation of duties).
            Ajukan ke petugas berwenang untuk memproses.
        </div>
        @elseif(! $layak)
        <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.7rem .9rem;border-radius:8px;font-size:.82rem;">
            &#9888; Tombol eksekusi dinonaktifkan sampai seluruh kewajiban anggota lunas.
        </div>
        @else
        <form method="POST" action="{{ route('member-terminations.eksekusi', $terminasi->id) }}"
              style="display:flex;flex-direction:column;gap:.9rem;"
              onsubmit="return confirm('Eksekusi pengembalian dana? Jurnal PSAK 108 akan diposting permanen dan status anggota menjadi TERMINATED.');">
            @csrf

            <fieldset style="border:1px solid #e2e8f0;border-radius:8px;padding:.75rem .9rem;display:flex;flex-direction:column;gap:.55rem;">
                <legend style="font-size:.78rem;font-weight:700;color:#475569;padding:0 .35rem;">Metode Pengembalian Dana</legend>

                <label style="display:flex;align-items:flex-start;gap:.6rem;font-size:.83rem;cursor:pointer;">
                    <input type="radio" name="metode" value="{{ MemberTermination::METODE_CASH }}" checked
                           onchange="document.getElementById('blokTransfer').style.display='none';">
                    <span>
                        <strong>CASH</strong> &mdash; Tunai via Teller<br>
                        <span style="font-size:.75rem;color:#64748b;">
                            Mencatat transaksi CASH_OUT. Jurnal: DEBET <code>3-1001</code> + DEBET <code>3-1002</code>,
                            KREDIT <code>1-1001</code> Kas Teller.
                        </span>
                    </span>
                </label>

                <label style="display:flex;align-items:flex-start;gap:.6rem;font-size:.83rem;cursor:pointer;">
                    <input type="radio" name="metode" value="{{ MemberTermination::METODE_TRANSFER_BANK }}"
                           onchange="document.getElementById('blokTransfer').style.display='block';">
                    <span>
                        <strong>TRANSFER_BANK</strong> &mdash; Transfer via Rekening Bank Koperasi<br>
                        <span style="font-size:.75rem;color:#64748b;">
                            Mencatat transaksi BANK_OUT. Jurnal: DEBET <code>3-1001</code> + DEBET <code>3-1002</code>,
                            KREDIT <code>1-1002</code> Kas di Bank.
                        </span>
                    </span>
                </label>
            </fieldset>

            <div id="blokTransfer" style="display:none;flex-direction:column;gap:.6rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.75rem .9rem;">
                <div style="display:flex;flex-direction:column;gap:.25rem;">
                    <label for="nomor_rekening_tujuan" style="font-size:.78rem;font-weight:600;color:#475569;">Nomor Rekening Tujuan <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="nomor_rekening_tujuan" id="nomor_rekening_tujuan" maxlength="60"
                           value="{{ old('nomor_rekening_tujuan') }}"
                           style="padding:.55rem .7rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.85rem;">
                </div>
                <div style="display:flex;flex-direction:column;gap:.25rem;">
                    <label for="nama_bank_tujuan" style="font-size:.78rem;font-weight:600;color:#475569;">Nama Bank Tujuan <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="nama_bank_tujuan" id="nama_bank_tujuan" maxlength="100"
                           value="{{ old('nama_bank_tujuan') }}"
                           style="padding:.55rem .7rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.85rem;">
                </div>
            </div>

            <div>
                <button type="submit" style="padding:.65rem 1.5rem;background:#047857;color:#fff;border:0;border-radius:6px;font-size:.85rem;font-weight:700;cursor:pointer;">
                    Eksekusi &amp; Posting Jurnal PSAK 108
                </button>
            </div>
        </form>

        <hr style="border:0;border-top:1px solid #f1f5f9;margin:1rem 0;">

        {{-- Tolak / Batalkan --}}
        <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
            <form method="POST" action="{{ route('member-terminations.tolak', $terminasi->id) }}"
                  style="display:flex;gap:.5rem;align-items:flex-end;flex:1;min-width:260px;"
                  onsubmit="return confirm('Tolak pengajuan exit ini?');">
                @csrf
                <div style="display:flex;flex-direction:column;gap:.25rem;flex:1;">
                    <label for="alasan_penolakan" style="font-size:.75rem;font-weight:600;color:#991b1b;">Alasan Penolakan</label>
                    <input type="text" name="alasan_penolakan" id="alasan_penolakan" required maxlength="1000"
                           style="padding:.5rem .6rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.82rem;">
                </div>
                <button type="submit" style="padding:.55rem 1rem;background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;border-radius:6px;font-size:.82rem;font-weight:600;cursor:pointer;">
                    Tolak
                </button>
            </form>

            <form method="POST" action="{{ route('member-terminations.batalkan', $terminasi->id) }}"
                  style="display:flex;gap:.5rem;align-items:flex-end;flex:1;min-width:260px;"
                  onsubmit="return confirm('Batalkan pengajuan exit ini?');">
                @csrf
                <div style="display:flex;flex-direction:column;gap:.25rem;flex:1;">
                    <label for="alasan_batal" style="font-size:.75rem;font-weight:600;color:#475569;">Alasan Pembatalan (opsional)</label>
                    <input type="text" name="alasan_penolakan" id="alasan_batal" maxlength="1000"
                           style="padding:.5rem .6rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.82rem;">
                </div>
                <button type="submit" style="padding:.55rem 1rem;background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;border-radius:6px;font-size:.82rem;font-weight:600;cursor:pointer;">
                    Batalkan
                </button>
            </form>
        </div>
        @endif
    </div>
    @endif

    {{-- Jejak Audit / Catatan --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;padding:1.25rem;">
        <h2 style="font-size:.95rem;font-weight:700;color:#1e293b;margin:0 0 .75rem;">Jejak Audit</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:.75rem;font-size:.82rem;">
            <div><div style="color:#94a3b8;font-size:.72rem;">Diajukan Oleh</div><div style="font-weight:600;">{{ $terminasi->diajukanOleh->name ?? '-' }}</div></div>
            <div><div style="color:#94a3b8;font-size:.72rem;">Diproses Oleh</div><div style="font-weight:600;">{{ $terminasi->diprosesOleh->name ?? '-' }}</div></div>
            <div><div style="color:#94a3b8;font-size:.72rem;">Disetujui Oleh</div><div style="font-weight:600;">{{ $terminasi->disetujuiOleh->name ?? '-' }}</div></div>
        </div>
        @if($terminasi->catatan)
        <div style="margin-top:.75rem;font-size:.82rem;">
            <div style="color:#94a3b8;font-size:.72rem;">Catatan</div>
            <div style="white-space:pre-line;">{{ $terminasi->catatan }}</div>
        </div>
        @endif
        @if($terminasi->alasan_penolakan)
        <div style="margin-top:.75rem;font-size:.82rem;color:#991b1b;">
            <div style="font-size:.72rem;">Alasan Penolakan / Pembatalan</div>
            <div style="white-space:pre-line;">{{ $terminasi->alasan_penolakan }}</div>
        </div>
        @endif
        @if($terminasi->transaksi)
        <div style="margin-top:.75rem;font-size:.82rem;">
            <div style="color:#94a3b8;font-size:.72rem;">Transaksi Ledger</div>
            <div style="font-family:monospace;font-weight:600;">{{ $terminasi->transaksi->no_transaksi ?? '-' }}</div>
        </div>
        @endif
    </div>

    {{-- Riwayat Persetujuan Manajer --}}
    @if($terminasi->approvalLogs->isNotEmpty())
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;padding:1.25rem;">
        <h2 style="font-size:.95rem;font-weight:700;color:#1e293b;margin:0 0 .75rem;">Riwayat Persetujuan Manajer</h2>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.8rem;">
                <thead>
                    <tr style="text-align:left;color:#64748b;">
                        <th style="padding:.4rem .6rem;border-bottom:1px solid #e2e8f0;">Waktu</th>
                        <th style="padding:.4rem .6rem;border-bottom:1px solid #e2e8f0;">Pejabat</th>
                        <th style="padding:.4rem .6rem;border-bottom:1px solid #e2e8f0;">Aksi</th>
                        <th style="padding:.4rem .6rem;border-bottom:1px solid #e2e8f0;">Catatan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($terminasi->approvalLogs as $log)
                    <tr>
                        <td style="padding:.45rem .6rem;border-bottom:1px solid #f1f5f9;white-space:nowrap;">{{ $log->created_at?->format('d/m/Y H:i') ?? '-' }}</td>
                        <td style="padding:.45rem .6rem;border-bottom:1px solid #f1f5f9;">{{ $log->user->name ?? ('user #' . $log->user_id) }}</td>
                        <td style="padding:.45rem .6rem;border-bottom:1px solid #f1f5f9;">
                            @if($log->aksi === \App\Models\ExitApprovalLog::AKSI_APPROVED)
                            <span style="color:#065f46;font-weight:700;">DISETUJUI</span>
                            @else
                            <span style="color:#991b1b;font-weight:700;">DITOLAK</span>
                            @endif
                        </td>
                        <td style="padding:.45rem .6rem;border-bottom:1px solid #f1f5f9;white-space:pre-line;">{{ $log->catatan ?: '-' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    <div>
        <a href="{{ route('member-terminations.index') }}" style="font-size:.83rem;color:#047857;font-weight:600;text-decoration:none;">
            &larr; Kembali ke Daftar
        </a>
    </div>
</div>
@endsection
