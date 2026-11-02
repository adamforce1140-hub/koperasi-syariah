{{--
    Tabel "10 Mutasi Terakhir" pada halaman Portofolio CIF.

    PARTIAL INI DIBATASI MENURUT PERAN (segregation of duties / privasi data
    perbankan):
      - `teller`, `manajer`, `pengurus` → tabel mutasi dirender.
      - `ao` dan peran lain              → tabel TIDAK dirender sama sekali
        (data transaksi anggota tidak dikirim ke browser).

    Kontrol berada DI DALAM partial — bukan di view pemanggil — agar seluruh
    pemanggil (ao/teller/manajer/pengurus/cif show) otomatis tunduk pada aturan
    yang sama dan tidak bisa lupa menerapkannya.

    Data `$mutasi` disediakan oleh CifController::show().
--}}
@php
    $rolePengguna     = auth()->user()?->role;
    $bolehLihatMutasi = \App\Support\HierarkiRole::dalamKelompok($rolePengguna, 'lihat_mutasi');
@endphp

@if($bolehLihatMutasi)
<div id="tabel-mutasi-terakhir"
     style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;
            box-shadow:0 1px 3px rgba(0,0,0,.05);overflow:hidden;">
    <div style="padding:1rem 1.25rem;border-bottom:1px solid #f1f5f9;">
        <div style="font-size:0.85rem;font-weight:700;color:#1e293b;">10 Mutasi Terakhir</div>
    </div>
    @if($mutasi->count() > 0)
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                    <th style="padding:0.65rem 1.25rem;text-align:left;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Tanggal</th>
                    <th style="padding:0.65rem 1rem;text-align:left;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Jenis</th>
                    <th style="padding:0.65rem 1rem;text-align:center;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Posisi</th>
                    <th style="padding:0.65rem 1rem;text-align:right;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Nominal</th>
                </tr>
            </thead>
            <tbody>
                @foreach($mutasi as $trx)
                @php $isMasuk = $trx->isMasuk(); @endphp
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:0.75rem 1.25rem;font-size:0.8rem;color:#64748b;">
                        {{ $trx->waktu_transaksi ? \Carbon\Carbon::parse($trx->waktu_transaksi)->format('d M Y') : '—' }}
                    </td>
                    <td style="padding:0.75rem 1rem;font-size:0.825rem;color:#1e293b;">{{ $trx->jenis_transaksi }}</td>
                    <td style="padding:0.75rem 1rem;text-align:center;">
                        <span style="padding:0.2rem 0.6rem;
                                     background:{{ $isMasuk ? '#d1fae5' : '#fee2e2' }};
                                     color:{{ $isMasuk ? '#065f46' : '#991b1b' }};
                                     font-size:0.75rem;font-weight:600;border-radius:9999px;">
                            {{ $trx->posisi }}
                        </span>
                    </td>
                    <td style="padding:0.75rem 1rem;text-align:right;font-size:0.825rem;font-weight:600;
                               color:{{ $isMasuk ? '#059669' : '#dc2626' }};">
                        {{-- Arah mutasi mengikuti Transaksi::isMasuk(). --}}
                        {{ $isMasuk ? '+' : '-' }} Rp {{ number_format($trx->jumlah, 0, ',', '.') }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @else
    <div style="padding:2rem;text-align:center;font-size:0.825rem;color:#94a3b8;">Belum ada mutasi transaksi.</div>
    @endif
</div>
@endif
