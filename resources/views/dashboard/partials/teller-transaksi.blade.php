{{--
    Tabel "Transaksi Terakhir" dashboard teller (potongan real-time).
    Dipakai oleh:
      • resources/views/dashboard/teller.blade.php      → render awal
      • TellerDashboardController::ringkas()            → penyegaran via Denyut
    Variabel yang dibutuhkan: $transaksiTerakhir.
--}}
@if($transaksiTerakhir->count() > 0)
<div style="overflow-x:auto;">
<table>
    <thead>
        <tr>
            <th>Tanggal & Waktu</th>
            <th>No. Transaksi</th>
            <th>Anggota</th>
            <th>Jenis</th>
            <th>Posisi Mutasi</th>
            <th>Jumlah</th>
            <th>Saldo Setelah</th>
            <th>Status</th>
            <th>Nota</th>
        </tr>
    </thead>
    <tbody>
        @foreach($transaksiTerakhir as $trx)
        @php
            $namaAnggota = $trx->rekening?->anggota?->nama_lengkap
                ?? $trx->anggota?->nama_lengkap
                ?? $trx->pembiayaan?->anggota?->nama_lengkap
                ?? '-';

            // Label & nilai kolom "Saldo Setelah" — mengikuti logika halaman Riwayat:
            // Angsuran via Kas  : sisa kewajiban pembiayaan
            // Angsuran via Tab  : sisa saldo tabungan
            // Lainnya           : saldo rekening setelah transaksi
            $angsuran = $trx->angsuran;
            if ($angsuran !== null) {
                $viaTabungan = $trx->sumber_transaksi === 'Tabungan Anggota';
                $labelSaldo = $viaTabungan ? 'Sisa Tabungan' : 'Sisa Kewajiban';
                $saldoSetelah = $viaTabungan
                    ? ($trx->saldo_setelah ?? 0)
                    : (float) ($angsuran->pembiayaan?->sisa_pembiayaan ?? $trx->saldo_setelah ?? 0);
            } else {
                $labelSaldo = 'Saldo Setelah';
                $saldoSetelah = $trx->saldo_setelah;
            }

            // Arah tanda (+/-) mengikuti satu sumber kebenaran:
            // Transaksi::isMasuk() (Debet = menambah simpanan).
            $mutasiMasuk = $trx->isMasuk();

            // Transaksi batal/gagal/void TIDAK menimbulkan mutasi saldo apa pun
            // (saldo rekening tidak berkurang) → kolom Posisi/Jumlah/Saldo kosong.
            $tidakAdaMutasi = in_array($trx->status_transaksi, ['Gagal', 'Dibatalkan', 'Voided', 'Pending Void'], true);
        @endphp
        <tr>
            <td style="white-space:nowrap;font-size:0.8rem;color:#64748b;">
                {{ $trx->waktu_transaksi ? $trx->waktu_transaksi->format('d/m/Y H:i') : '-' }}
            </td>
            <td style="font-family:monospace;font-size:0.8rem;">{{ $trx->no_transaksi }}</td>
            <td style="font-size:0.85rem;">{{ $namaAnggota }}</td>
            <td>
                <span class="badge {{ str_contains($trx->jenis_transaksi,'Setor') || str_contains($trx->jenis_transaksi,'Masuk') ? 'badge-green' : (str_contains($trx->jenis_transaksi,'Tarik') || str_contains($trx->jenis_transaksi,'Keluar') ? 'badge-amber' : 'badge-blue') }}">
                    {{ $trx->jenis_transaksi }}
                </span>
            </td>
            <td>
                @if($tidakAdaMutasi)
                    <span style="color:#94a3b8;">—</span>
                @else
                    <span class="badge {{ $mutasiMasuk ? 'badge-green' : 'badge-amber' }}">
                        {{ $mutasiMasuk ? 'Mutasi Masuk' : 'Mutasi Keluar' }}
                    </span>
                @endif
            </td>
            <td style="font-weight:600;white-space:nowrap;color:{{ $tidakAdaMutasi ? '#94a3b8' : ($mutasiMasuk ? '#16a34a' : '#dc2626') }};">
                @if($tidakAdaMutasi)
                    —
                @else
                    {{ $mutasiMasuk ? '+' : '-' }} Rp {{ number_format($trx->jumlah, 0, ',', '.') }}
                @endif
            </td>
            <td style="white-space:nowrap;color:#64748b;font-size:0.85rem;">
                @if($tidakAdaMutasi)
                    <span style="color:#94a3b8;">—</span>
                    <div style="font-size:0.7rem;color:#94a3b8;">Tidak Ada Mutasi</div>
                @elseif($saldoSetelah !== null)
                    <div style="font-weight:600;color:#334155;">Rp {{ number_format($saldoSetelah, 0, ',', '.') }}</div>
                    <div style="font-size:0.7rem;color:#94a3b8;">{{ $labelSaldo }}</div>
                @else
                    -
                @endif
            </td>
            <td>
                @php
                    $stClass = match($trx->status_transaksi) {
                        'Sukses'      => 'badge-green',
                        'Pending'     => 'badge-amber',
                        'Pending Void'=> 'badge-amber',
                        'Gagal'       => 'badge-red',
                        'Ditolak'     => 'badge-red',
                        'Voided'      => 'badge-red',
                        'Dibatalkan'  => 'badge-red',
                        default       => 'badge-blue',
                    };
                @endphp
                <span class="badge {{ $stClass }}">{{ $trx->status_transaksi }}</span>
            </td>
            <td>
                {{-- Nota hanya tersedia bila transaksi sudah tercatat (Sukses) --}}
                @if($trx->status_transaksi === 'Sukses' || $trx->status === 'success')
                    <a href="{{ route('teller.nota', $trx->id) }}" class="btn btn-primary" style="padding:0.3rem 0.7rem;font-size:0.78rem;">
                        🖨 Nota
                    </a>
                @elseif($trx->status === 'pending_approval')
                    <span style="font-size:0.75rem;color:#b45309;font-weight:600;">
                        {{ $trx->is_aml_high_value ? 'Menunggu Verifikasi AML' : 'Menunggu Manajer' }}
                    </span>
                @else
                    <span style="font-size:0.75rem;color:#94a3b8;">-</span>
                @endif
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
@else
<div style="padding:2rem;text-align:center;color:#94a3b8;">
    <div style="font-size:2rem;margin-bottom:0.5rem;">📋</div>
    <div>Belum ada transaksi</div>
</div>
@endif
