@extends('layouts.app')

@section('title', 'Portofolio CIF — ' . $anggota->nama_lengkap)
@section('page-title', 'Portofolio CIF Anggota')

@section('content')
@php
    use App\Models\Anggota;

    $st        = Anggota::normalizeStatus($anggota->status_anggota ?? null)
                 ?? Anggota::STATUS_INACTIVE;
    $stLabel   = $anggota->status_label;
    $badge = match($st) {
        Anggota::STATUS_ACTIVE
                   => ['bg'=>'#d1fae5','color'=>'#065f46','dot'=>'#059669'],
        Anggota::STATUS_PENDING
                   => ['bg'=>'#fef3c7','color'=>'#92400e','dot'=>'#d97706'],
        Anggota::STATUS_SUSPENDED
                   => ['bg'=>'#ede9fe','color'=>'#5b21b6','dot'=>'#7c3aed'],
        Anggota::STATUS_KELUAR
                   => ['bg'=>'#e2e8f0','color'=>'#334155','dot'=>'#64748b'],
        default    => ['bg'=>'#fee2e2','color'=>'#991b1b','dot'=>'#dc2626'],
    };

    $totalSaldo   = $saldoPokok + $saldoWajib + $saldoSukarela;
    $tglBergabung = $anggota->tanggalMulaiKeanggotaan();
    $umurAnggota  = $anggota->member_age;   // mis. "1 Tahun 3 Bulan 5 Hari"
    $masaAktif    = $anggota->tenure_label; // mis. "8 Bulan"
@endphp
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- Header --}}
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
        <div>
            <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;margin-bottom:0.375rem;">
                <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;">{{ $anggota->nama_lengkap }}</h1>
                <span style="font-family:monospace;font-size:0.8rem;font-weight:700;color:#1e40af;
                             background:#dbeafe;padding:0.2rem 0.6rem;border-radius:0.375rem;">
                    {{ $anggota->no_cif ?? '—' }}
                </span>
                <span style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.2rem 0.6rem;
                             background:{{ $badge['bg'] }};color:{{ $badge['color'] }};
                             font-size:0.75rem;font-weight:600;border-radius:9999px;">
                    <span style="width:6px;height:6px;border-radius:50%;background:{{ $badge['dot'] }};"></span>
                    {{ $stLabel }}
                </span>
            </div>
            <p style="margin:0;font-size:0.83rem;color:#64748b;">
                {{ $anggota->no_anggota ?? '—' }} &bull;
                Bergabung {{ $tglBergabung ? $tglBergabung->format('d M Y') : '—' }}
            </p>

            {{-- Badge Umur Keanggotaan (Member Age) — POIN 4 --}}
            <div style="display:inline-flex;align-items:center;gap:0.4rem;flex-wrap:wrap;
                        margin-top:0.5rem;padding:0.3rem 0.7rem;background:#eff6ff;
                        border:1px solid #bfdbfe;border-radius:0.5rem;font-size:0.75rem;
                        color:#1e40af;font-weight:600;">
                <span style="font-size:0.85rem;">&#128197;</span>
                <span>
                    Bergabung sejak {{ $tglBergabung ? $tglBergabung->format('d M Y') : '—' }}
                    &mdash; Masa Aktif: {{ $masaAktif }}
                </span>
                <span style="color:#64748b;font-weight:500;">({{ $umurAnggota }})</span>
            </div>
        </div>
        <div style="display:flex;gap:0.5rem;align-items:center;">
            <a href="{{ route('manajer.cif.edit', $anggota->id) }}"
               style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.5rem 1rem;
                      background:#fef3c7;color:#92400e;font-size:0.8rem;font-weight:600;
                      border-radius:0.5rem;text-decoration:none;"
               onmouseover="this.style.background='#fde68a'" onmouseout="this.style.background='#fef3c7'">
                Edit Data
            </a>
            <a href="{{ route('manajer.cif.index') }}"
               style="display:inline-flex;align-items:center;padding:0.5rem 1rem;
                      background:#f1f5f9;color:#475569;font-size:0.8rem;font-weight:600;
                      border-radius:0.5rem;text-decoration:none;"
               onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                ← Kembali
            </a>
        </div>
    </div>

    @if(session('success'))
    <div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:0.75rem;padding:0.875rem 1.25rem;
                font-size:0.85rem;color:#065f46;font-weight:500;">
        {{ session('success') }}
    </div>
    @endif

    {{-- Penahanan SHU (HOLD) — notifikasi tunggakan Simpanan Wajib --}}
    @include('partials.shu-hold-banner')

    {{-- Stat Cards --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;">
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.125rem 1.25rem;
                    box-shadow:0 1px 3px rgba(0,0,0,.05);text-align:center;">
            <div style="font-size:0.75rem;color:#64748b;margin-bottom:0.375rem;">Total Saldo Simpanan</div>
            <div style="font-size:1.25rem;font-weight:700;color:#059669;">Rp {{ number_format($totalSaldo, 0, ',', '.') }}</div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.125rem 1.25rem;
                    box-shadow:0 1px 3px rgba(0,0,0,.05);text-align:center;">
            <div style="font-size:0.75rem;color:#64748b;margin-bottom:0.375rem;">Pembiayaan Aktif</div>
            <div style="font-size:1.25rem;font-weight:700;color:#1e40af;">{{ $pembiayaanAktif->count() }}</div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.125rem 1.25rem;
                    box-shadow:0 1px 3px rgba(0,0,0,.05);text-align:center;">
            <div style="font-size:0.75rem;color:#64748b;margin-bottom:0.375rem;">Total Mutasi (10 terakhir)</div>
            <div style="font-size:1.25rem;font-weight:700;color:#7c3aed;">{{ $mutasi->count() }}</div>
        </div>
    </div>

    {{-- Identitas --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <div style="font-size:0.85rem;font-weight:700;color:#1e293b;margin-bottom:1rem;padding-bottom:0.625rem;border-bottom:1px solid #f1f5f9;">
            Informasi Identitas
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
            @php
            $rows = [
                ['NIK', $anggota->nik ?? '—'],
                ['Tempat / Tgl Lahir', ($anggota->tempat_lahir ?? '—') . ', ' . ($anggota->tanggal_lahir ? $anggota->tanggal_lahir->format('d M Y') : '—')],
                ['Jenis Kelamin', $anggota->jenis_kelamin ?? '—'],
                ['Status Perkawinan', $anggota->status_perkawinan ?? '—'],
                ['No HP', $anggota->no_hp ?? '—'],
                ['Pekerjaan', $anggota->pekerjaan ?? '—'],
                ['Email', $anggota->email ?? '—'],
                ['Nama Ibu Kandung', $anggota->nama_ibu_kandung ?? '—'],
                ['Alamat Lengkap', implode(', ', array_filter([$anggota->alamat, $anggota->rt_rw ? 'RT/RW '.$anggota->rt_rw : null, $anggota->kelurahan, $anggota->kecamatan, $anggota->kota, $anggota->provinsi, $anggota->kode_pos]))],
            ];
            @endphp
            @foreach($rows as [$label, $value])
            <div style="padding:0.625rem 0;border-bottom:1px solid #f8fafc;">
                <div style="font-size:0.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.2rem;">{{ $label }}</div>
                <div style="font-size:0.85rem;color:#1e293b;">{{ $value }}</div>
            </div>
            @endforeach
        </div>
    </div>

    {{-- Rekening --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;box-shadow:0 1px 3px rgba(0,0,0,.05);overflow:hidden;">
        <div style="padding:1rem 1.25rem;border-bottom:1px solid #f1f5f9;">
            <div style="font-size:0.85rem;font-weight:700;color:#1e293b;">Rekening Simpanan</div>
        </div>
        @if($anggota->rekening->count() > 0)
        <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;">
            <thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                <th style="padding:0.65rem 1.25rem;text-align:left;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">No Rekening</th>
                <th style="padding:0.65rem 1rem;text-align:left;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Jenis</th>
                <th style="padding:0.65rem 1rem;text-align:left;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Akad</th>
                <th style="padding:0.65rem 1rem;text-align:right;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Saldo</th>
                <th style="padding:0.65rem 1rem;text-align:center;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Status</th>
            </tr></thead>
            <tbody>
                @foreach($anggota->rekening as $rek)
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:0.75rem 1.25rem;font-family:monospace;font-size:0.8rem;color:#1e40af;">{{ $rek->no_rekening }}</td>
                    <td style="padding:0.75rem 1rem;font-size:0.825rem;color:#1e293b;">{{ $rek->jenis_rekening }}</td>
                    <td style="padding:0.75rem 1rem;font-size:0.825rem;color:#475569;">{{ $rek->akad }}</td>
                    <td style="padding:0.75rem 1rem;text-align:right;font-size:0.825rem;font-weight:600;color:#059669;">Rp {{ number_format($rek->saldo, 0, ',', '.') }}</td>
                    <td style="padding:0.75rem 1rem;text-align:center;">
                        <span style="padding:0.2rem 0.6rem;background:{{ $rek->status_rekening === 'Aktif' ? '#d1fae5' : '#fee2e2' }};color:{{ $rek->status_rekening === 'Aktif' ? '#065f46' : '#991b1b' }};font-size:0.75rem;font-weight:600;border-radius:9999px;">{{ $rek->status_rekening }}</span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table></div>
        @else
        <div style="padding:2rem;text-align:center;font-size:0.825rem;color:#94a3b8;">Belum ada rekening simpanan.</div>
        @endif
    </div>

    {{-- Simpanan Berjangka (Bilyet Deposito) - tabel + tombol 'Cetak Bilyet PDF' --}}
    @include('partials.deposito-bilyet-table')

    {{-- Pembiayaan Aktif --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;box-shadow:0 1px 3px rgba(0,0,0,.05);overflow:hidden;">
        <div style="padding:1rem 1.25rem;border-bottom:1px solid #f1f5f9;">
            <div style="font-size:0.85rem;font-weight:700;color:#1e293b;">Pembiayaan Aktif</div>
        </div>
        @if($pembiayaanAktif->count() > 0)
        <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;">
            <thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                <th style="padding:0.65rem 1.25rem;text-align:left;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">No Pembiayaan</th>
                <th style="padding:0.65rem 1rem;text-align:left;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Akad</th>
                <th style="padding:0.65rem 1rem;text-align:right;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Jumlah</th>
                <th style="padding:0.65rem 1rem;text-align:right;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Sisa</th>
                <th style="padding:0.65rem 1rem;text-align:center;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Status</th>
                <th style="padding:0.65rem 1rem;text-align:center;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Angsuran</th>
            </tr></thead>
            <tbody>
                @foreach($pembiayaanAktif as $pmb)
                @php $angsuranTerakhir = $pmb->angsuran->where('status_angsuran','Sudah Dibayar')->count(); @endphp
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:0.75rem 1.25rem;font-family:monospace;font-size:0.8rem;color:#1e40af;">{{ $pmb->no_pembiayaan }}</td>
                    <td style="padding:0.75rem 1rem;font-size:0.825rem;color:#1e293b;">{{ $pmb->akad_pembiayaan }}</td>
                    <td style="padding:0.75rem 1rem;text-align:right;font-size:0.825rem;color:#475569;">Rp {{ number_format($pmb->jumlah_pembiayaan, 0, ',', '.') }}</td>
                    <td style="padding:0.75rem 1rem;text-align:right;font-size:0.825rem;font-weight:600;color:#dc2626;">Rp {{ number_format($pmb->sisa_pembiayaan, 0, ',', '.') }}</td>
                    <td style="padding:0.75rem 1rem;text-align:center;"><span style="padding:0.2rem 0.6rem;background:#d1fae5;color:#065f46;font-size:0.75rem;font-weight:600;border-radius:9999px;">{{ $pmb->status_pembiayaan }}</span></td>
                    <td style="padding:0.75rem 1rem;text-align:center;font-size:0.825rem;color:#475569;">ke-{{ $angsuranTerakhir + 1 }} / {{ $pmb->tenor }}</td>
                </tr>
                @endforeach
            </tbody>
        </table></div>
        @else
        <div style="padding:2rem;text-align:center;font-size:0.825rem;color:#94a3b8;">Tidak ada pembiayaan aktif.</div>
        @endif
    </div>

    {{-- Mutasi — DIBATASI PERAN.
         Partial memfilter sendiri: hanya teller/manajer/pengurus yang melihat
         tabel mutasi; role `ao` tidak menerima datanya sama sekali. --}}
    @include('partials.mutasi-terakhir')

</div>
@endsection
