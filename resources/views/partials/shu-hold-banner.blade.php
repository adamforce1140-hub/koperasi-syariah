{{--
    Banner peringatan penahanan SHU (HOLD) untuk halaman detail CIF/Anggota.

    Variabel yang dibutuhkan (dikirim oleh CifController::show()):
      $isShuHold            bool    — penahanan aktif (kebijakan + tunggakan)
      $alasanHold           ?string — penjelasan penahanan
      $tunggakanWajib       Collection<MandatorySavingsBill>
      $totalTunggakanWajib  float

    Dipakai bersama oleh view AO / Manajer / Pengurus / Teller agar tampilan
    notifikasi konsisten dan hanya ada satu sumber kebenaran data.

    Seluruh gaya ditulis inline di partial ini memakai awalan kelas "hold-"
    agar tidak bentrok dengan halaman pemanggil.
--}}
@if(!empty($isShuHold))
<style>
    .hold-banner {
        border: 1px solid #fcd34d; background: #fffbeb; color: #92400e;
        border-radius: 0.75rem; padding: 1rem; box-shadow: 0 1px 2px rgba(0,0,0,0.06);
        margin-bottom: 1.25rem; overflow-wrap: break-word;
    }
    .hold-banner-inner { display: flex; align-items: flex-start; gap: 0.75rem; }
    .hold-icon {
        flex: 0 0 auto; width: 2rem; height: 2rem; margin-top: 0.125rem;
        display: flex; align-items: center; justify-content: center;
        border-radius: 9999px; background: #fde68a; color: #92400e;
        font-size: 1rem; font-weight: 700;
    }
    .hold-body { flex: 1 1 auto; min-width: 0; }
    .hold-title { margin: 0; font-size: 0.9rem; font-weight: 700; color: #78350f; }
    .hold-alasan { margin: 0.25rem 0 0; font-size: 0.78rem; line-height: 1.6; color: #92400e; }
    .hold-table-wrap {
        margin-top: 0.75rem; overflow-x: auto; -webkit-overflow-scrolling: touch;
        border: 1px solid #fde68a; border-radius: 0.5rem; background: #fff;
    }
    .hold-table-wrap > table { width: 100%; }
    .hold-table-wrap th,
    .hold-table-wrap td { font-size: 0.75rem; padding: 0.4rem 0.65rem; }
    .hold-table-wrap th { text-align: left; background: #fef3c7; color: #78350f; }
    .hold-table-wrap td.num, .hold-table-wrap th.num { text-align: right; }
    .hold-table-wrap tfoot td { background: #fffbeb; font-weight: 700; color: #78350f; }
    .hold-tag {
        display: inline-block; padding: 0.1rem 0.5rem; border-radius: 9999px;
        background: #fee2e2; color: #991b1b; font-size: 0.68rem; font-weight: 700;
    }
    .hold-catatan { margin: 0.5rem 0 0; font-size: 0.72rem; color: #b45309; }
    @media (max-width: 640px) {
        .hold-banner { padding: 0.85rem; }
        .hold-title { font-size: 0.82rem; }
        .hold-alasan { font-size: 0.75rem; }
    }
</style>
<div class="hold-banner" role="alert">
    <div class="hold-banner-inner">
        <span class="hold-icon" aria-hidden="true">!</span>
        <div class="hold-body">
            <p class="hold-title">SHU Ditahan (HOLD) &mdash; Tunggakan Simpanan Wajib</p>
            <p class="hold-alasan">{{ $alasanHold }}</p>

            <div class="hold-table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Periode</th>
                            <th class="num">Nominal</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($tunggakanWajib as $tagihan)
                        <tr>
                            <td>{{ $tagihan->bill_period }}</td>
                            <td class="num">Rp {{ number_format((float) $tagihan->amount, 0, ',', '.') }}</td>
                            <td><span class="hold-tag">OVERDUE</span></td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="num">Total Tunggakan</td>
                            <td class="num">Rp {{ number_format($totalTunggakanWajib, 0, ',', '.') }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p class="hold-catatan">Pencairan SHU dilakukan otomatis setelah seluruh tunggakan dilunasi di Teller.</p>
        </div>
    </div>
</div>
@endif