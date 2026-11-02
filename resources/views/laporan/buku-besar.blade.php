@extends('layouts.app')
@section('page-title', "Buku Besar Akun")

@section('title', 'Buku Besar Akun')

@section('content')
<div class="container-fluid px-4 py-3">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h4 class="fw-bold mb-1">Buku Besar Akun</h4>
            <p class="text-muted mb-0 small">
                {{-- Judul akun: identik dengan teks opsi terpilih di dropdown --}}
                <span id="judul-akun">{{ $judulHalaman }}</span>
                @if($coa)
                    <span class="badge ms-1 bg-secondary">Saldo Normal: {{ $coa->posisiSaldo() }}</span>
                @endif
            </p>
        </div>
        <a href="{{ route('laporan.neraca') }}" class="btn btn-outline-secondary btn-sm">Kembali ke Neraca</a>
    </div>

    {{-- Filter Akun --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('laporan.buku-besar') }}" class="row g-2 align-items-end" id="form-buku-besar">
                <div class="col-md-5">
                    <label class="form-label fw-semibold small">Pilih Akun CoA</label>
                    <select name="kode_akun" id="pilih-akun" class="form-select form-select-sm">
                        @foreach($daftarAkun as $ak)
                        <option value="{{ $ak->kode_akun }}" {{ $kodeAkun === $ak->kode_akun ? 'selected' : '' }}>
                            {{ $ak->kode_akun }} — {{ $ak->nama_akun }} [{{ $ak->kategori }}]
                        </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm" id="tombol-tampilkan">Tampilkan</button>
                </div>
                {{-- Shortcut Deposito --}}
                <div class="col-auto">
                    <a href="{{ route('laporan.buku-besar', ['kode_akun' => '2-1003']) }}"
                       class="btn btn-outline-success btn-sm {{ $kodeAkun === '2-1003' ? 'active' : '' }}">
                        🏦 Deposito (2-1003)
                    </a>
                </div>
            </form>
        </div>
    </div>

    <script>
    (function () {
        var form      = document.getElementById('form-buku-besar');
        var select    = document.getElementById('pilih-akun');
        var judul     = document.getElementById('judul-akun');
        var tombol    = document.getElementById('tombol-tampilkan');

        if (!form || !select || !judul) { return; }

        // Label yang sedang tampil di server = teks opsi terpilih saat halaman dimuat.
        var labelTampil = judul.textContent.trim();

        /**
         * Ambil teks opsi terpilih apa adanya (termasuk "kode — nama [kategori]"),
         * dinormalisasi hanya pada spasi tepi agar benar-benar identik dengan
         * teks yang dilihat pengguna pada dropdown.
         */
        function labelOpsiTerpilih() {
            var opsi = select.options[select.selectedIndex];
            return opsi ? opsi.textContent.replace(/\s+/g, ' ').trim() : '';
        }

        /**
         * Validasi: judul hanya boleh berubah setelah tombol 'Tampilkan' diklik.
         * Selama pengguna masih mengganti pilihan tanpa submit, judul dikembalikan
         * ke label yang sedang tampil sehingga tidak pernah menampilkan nilai
         * default maupun nilai akun lama yang menyesatkan.
         */
        function judulSesuaiTampilan() {
            judul.textContent = labelTampil;
        }

        // Real-time: ganti judul SEGERA saat 'Tampilkan' diklik, memakai teks opsi
        // yang benar-benar dipilih — tanpa perbedaan karakter, spasi, atau format.
        if (tombol) {
            tombol.addEventListener('click', function () {
                var labelBaru = labelOpsiTerpilih();
                if (labelBaru !== '') {
                    judul.textContent = labelBaru;
                }
            });
        }

        // Submit lewat Enter di dalam form juga dianggap "menampilkan".
        form.addEventListener('submit', function () {
            var labelBaru = labelOpsiTerpilih();
            if (labelBaru !== '') {
                judul.textContent = labelBaru;
            }
        });

        // Pilihan berubah tapi halaman belum ditampilkan ulang → kembalikan judul
        // ke kondisi tampil, supaya judul tidak pernah mendahului data tabel.
        select.addEventListener('change', judulSesuaiTampilan);
        ['keydown', 'keyup'].forEach(function (evt) {
            select.addEventListener(evt, function (e) {
                if (e.key === 'Enter') { return; } // Enter = submit, biarkan berjalan
                judulSesuaiTampilan();
            });
        });
    })();
    </script>

    @if($coa)
    {{-- Saldo Summary --}}
    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="text-muted small">Saldo Awal</div>
                <div class="fw-bold">Rp {{ number_format($coa->saldo_awal, 0, ',', '.') }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="text-muted small">Total Mutasi Debet</div>
                <div class="fw-bold text-danger">Rp {{ number_format($coa->totalDebet(), 0, ',', '.') }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="text-muted small">Total Mutasi Kredit</div>
                <div class="fw-bold text-success">Rp {{ number_format($coa->totalKredit(), 0, ',', '.') }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e0e7ff;">
                <div class="text-muted small">Saldo Berjalan</div>
                <div class="fw-bold" style="color:#3730a3;">Rp {{ number_format($coa->saldoBerjalan(), 0, ',', '.') }}</div>
            </div>
        </div>
    </div>

    {{-- Tabel Mutasi --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
            <h6 class="mb-0 fw-semibold">Mutasi Akun {{ $coa->kode_akun }} — {{ $coa->nama_akun }}</h6>
            <span class="badge bg-secondary">Saldo Normal: {{ $coa->posisiSaldo() }}</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. Jurnal</th>
                        <th>Tanggal</th>
                        <th>Keterangan</th>
                        <th>Sumber</th>
                        <th class="text-end">Debet</th>
                        <th class="text-end">Kredit</th>
                        <th class="text-end">Saldo Kumulatif</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        /* Saldo kumulatif dihitung dari POSISI akun:
                         |   posisi Kredit (Kewajiban/Ekuitas/Pendapatan) → Kredit − Debet
                         |   posisi Debet  (Aset/Beban)                   → Debet − Kredit
                         |
                         | Saldo awal baris diambil dari Coa::saldoBerjalan($sampai)
                         | di mana $sampai = tanggal jurnal terakhir SEBELUM baris
                         | pertama halaman ini. Sebelumnya saldo selalu dimulai dari
                         | hanya `saldo_awal` lalu mengakumulasi ulang seluruh baris
                         | halaman aktif, sehingga pada halaman ke-2 dan seterusnya
                         | saldo kumulatif kehilangan seluruh mutasi halaman
                         | sebelumnya dan angkanya salah. */
                        $halamanIni     = method_exists($details, 'items') ? collect($details->items()) : collect($details);
                        $barisPertama   = $halamanIni->first();
                        $tanggalSebelum = $barisPertama
                            ? \Carbon\Carbon::parse($barisPertama->jurnal->tanggal_jurnal ?? $barisPertama->created_at)
                                ->subDay()->toDateString()
                            : null;
                        $saldoKumulatif = $barisPertama
                            ? (float) $coa->saldoBerjalan($tanggalSebelum)
                            : (float) $coa->saldoBerjalan();
                    @endphp
                    @forelse($details as $d)
                    @php
                        /* Rumus saldo berjalan MENGIKUTI POSISI SALDO NORMAL akun,
                         | yang dideteksi otomatis dari awalan kode akun (Coa::posisiSaldo()):
                         |   • 1-xxxx (Aset) & 5-xxxx (Beban) → Saldo Akhir = Saldo Awal + Debet − Kredit
                         |   • 2/3/4-xxxx (Kewajiban/Ekuitas/Pendapatan) → Saldo Akhir = Saldo Awal + Kredit − Debet
                         | Tidak ada input manual: rumus dipilih murni dari klasifikasi akun. */
                        $saldoKumulatif = $coa->saldoAkhirBaris($saldoKumulatif, (float) $d->debet, (float) $d->kredit);
                    @endphp
                    <tr>
                        <td><code>{{ $d->jurnal->no_jurnal ?? '-' }}</code></td>
                        <td>{{ $d->jurnal ? \Carbon\Carbon::parse($d->jurnal->tanggal_jurnal)->format('d/m/Y') : '-' }}</td>
                        <td>{{ $d->jurnal->keterangan ?? $d->keterangan }}</td>
                        <td><span class="badge bg-light text-dark border">{{ $d->jurnal->sumber ?? '-' }}</span></td>
                        <td class="text-end text-danger">{{ $d->debet > 0 ? 'Rp '.number_format($d->debet, 0, ',', '.') : '-' }}</td>
                        <td class="text-end text-success">{{ $d->kredit > 0 ? 'Rp '.number_format($d->kredit, 0, ',', '.') : '-' }}</td>
                        <td class="text-end fw-semibold">Rp {{ number_format($saldoKumulatif, 0, ',', '.') }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">Belum ada mutasi untuk akun ini.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if(method_exists($details, 'hasPages') && $details->hasPages())
        <div class="card-footer bg-white">
            {{ $details->links() }}
        </div>
        @endif
    </div>
    @endif

</div>
@endsection
