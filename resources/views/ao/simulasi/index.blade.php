@extends('layouts.app')
@section('page-title', "Simulasi Angsuran")

@section('title', 'Simulasi Angsuran')

@section('content')
<div class="page-header">
    <h1 class="page-title">Simulasi Angsuran</h1>
    <p class="page-subtitle">Kalkulator angsuran pembiayaan syariah</p>
</div>

<div style="display:grid;grid-template-columns:1fr 2fr;gap:1.5rem;">

    {{-- Form Simulasi --}}
    <div class="data-card">
        <div class="card-header"><span class="card-title">Parameter Simulasi</span></div>
        <form method="GET" action="{{ route('ao.simulasi.index') }}" style="padding:1.5rem;">
            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">Jenis Akad</label>
                <select name="akad" style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;">
                    @foreach(\App\Models\Pembiayaan::daftarAkad() as $akad)
                    <option value="{{ $akad }}" {{ request('akad', $hasil['akad'] ?? \App\Models\Pembiayaan::akadDefault()) == $akad ? 'selected' : '' }}>{{ $akad }}</option>
                    @endforeach
                </select>
            </div>
            <div style="margin-bottom:1rem;">
                <label for="jumlah" style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">Jumlah Pembiayaan (Rp)</label>
                <input type="number" name="jumlah" id="jumlah" min="1000000" step="100000"
                    value="{{ request('jumlah', $hasil['jumlah'] ?? '') }}" required
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;">
            </div>
            <div style="margin-bottom:1rem;">
                <label for="margin" style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">Margin / Nisbah (% per tahun)</label>
                <input type="number" name="margin" id="margin" min="0" max="100" step="0.01"
                    value="{{ request('margin', $hasil['margin'] ?? '') }}" required
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;">
            </div>
            <div style="margin-bottom:1rem;">
                <label for="tenor" style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">Tenor (bulan)</label>
                <input type="number" name="tenor" id="tenor" min="1" max="360"
                    value="{{ request('tenor', $hasil['tenor'] ?? '') }}" required
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;">
            </div>
            <div style="margin-bottom:1.2rem;">
                <label style="display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.4rem;">Metode Hitung</label>
                <select name="metode" style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;">
                    <option value="flat" {{ request('metode', $hasil['metode'] ?? 'flat') == 'flat' ? 'selected' : '' }}>Flat</option>
                    <option value="anuitas" {{ request('metode','flat') == 'anuitas' ? 'selected' : '' }}>Anuitas</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Hitung Simulasi</button>
        </form>
    </div>

    {{-- Hasil --}}
    <div>
        @if($hasil)
        <div class="data-card" style="margin-bottom:1.5rem;">
            <div class="card-header"><span class="card-title">Ringkasan — {{ strtoupper($hasil['metode']) }}</span></div>
            <div style="padding:1.5rem;">
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;text-align:center;margin-bottom:1rem;">
                    <div style="padding:1rem;background:#f0fdf4;border-radius:8px;">
                        <div style="font-size:.75rem;color:#6b7280;">Total Piutang Murabahah</div>
                        <div style="font-size:1.1rem;font-weight:700;color:#166534;">Rp {{ number_format($hasil['totalPembiayaan'], 0, ',', '.') }}</div>
                    </div>
                    <div style="padding:1rem;background:#eff6ff;border-radius:8px;">
                        <div style="font-size:.75rem;color:#6b7280;">Angsuran/Bulan</div>
                        <div style="font-size:1.1rem;font-weight:700;color:#1d4ed8;">Rp {{ number_format($hasil['angsuranPerBulan'], 0, ',', '.') }}</div>
                    </div>
                    <div style="padding:1rem;background:#fff7ed;border-radius:8px;">
                        <div style="font-size:.75rem;color:#6b7280;">Total Margin</div>
                        <div style="font-size:1.1rem;font-weight:700;color:#c2410c;">Rp {{ number_format($hasil['totalMargin'], 0, ',', '.') }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="data-card">
            <div class="card-header"><span class="card-title">Jadwal Angsuran</span></div>
            <div style="max-height:400px;overflow-y:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Ke</th>
                            <th>Pokok</th>
                            <th>Margin</th>
                            <th>Total Angsuran</th>
                            <th>Sisa Pokok</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($hasil['jadwal'] as $baris)
                        <tr>
                            <td style="text-align:center;">{{ $baris['ke'] }}</td>
                            <td>Rp {{ number_format($baris['pokok'], 0, ',', '.') }}</td>
                            <td>Rp {{ number_format($baris['margin'], 0, ',', '.') }}</td>
                            <td><strong>Rp {{ number_format($baris['angsuran'], 0, ',', '.') }}</strong></td>
                            <td>Rp {{ number_format($baris['sisa_pokok'], 0, ',', '.') }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @else
        <div class="data-card" style="padding:3rem;text-align:center;color:#94a3b8;">
            Isi parameter di sebelah kiri untuk menampilkan simulasi angsuran.
        </div>
        @endif
    </div>
</div>
@endsection
