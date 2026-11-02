@extends('layouts.app')

@section('title', 'Dana Zakat, Infak & Sedekah — PSAK 109')
@section('page-title', 'Dana Zakat, Infak & Sedekah (PSAK 109)')

@section('content')

@php
    // DPS mengakses halaman ini lewat jalur pengawasan (read-only) dengan nama route berbeda.
    $routeLaporan = $readOnly ? 'dps.zakat.laporan' : 'zakat.laporan';
@endphp

@if(session('success'))
    <div class="alert" style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:.8rem 1.1rem;border-radius:8px;margin-bottom:1rem;">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert" style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.8rem 1.1rem;border-radius:8px;margin-bottom:1rem;">{{ session('error') }}</div>
@endif
@if(session('info'))
    <div class="alert" style="background:#dbeafe;border:1px solid #93c5fd;color:#1e40af;padding:.8rem 1.1rem;border-radius:8px;margin-bottom:1rem;">{{ session('info') }}</div>
@endif

{{-- Catatan kepatuhan: dana ini adalah dana titipan (amanah), bukan pendapatan koperasi --}}
<div class="card" style="background:#fffbeb;border:1px solid #fde68a;">
    <div style="font-size:.85rem;color:#92400e;line-height:1.6;">
        <strong>PSAK 109 &amp; QS At-Taubah 60.</strong>
        Dana zakat serta infak/sedekah merupakan <strong>dana titipan (amanah)</strong> — bukan pendapatan koperasi
        dan tidak diperhitungkan dalam SHU. Dana zakat hanya boleh disalurkan kepada <strong>8 asnaf</strong>.
        Saldo pada halaman ini dihitung dari mutasi jurnal akun
        <code>{{ \App\Support\Coa::DANA_ZAKAT }}</code> dan <code>{{ \App\Support\Coa::DANA_INFAK_SEDEKAH }}</code>.
    </div>
</div>

{{-- Kartu saldo --}}
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon green">🕌</div>
        <div>
            <div class="stat-value">Rp {{ number_format($ringkasan['zakat']['saldoAkhir'] ?? 0, 2, ',', '.') }}</div>
            <div class="stat-label">Saldo Dana Zakat</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">🤲</div>
        <div>
            <div class="stat-value">Rp {{ number_format($ringkasan['infak_sedekah']['saldoAkhir'] ?? 0, 2, ',', '.') }}</div>
            <div class="stat-label">Saldo Dana Infak &amp; Sedekah</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple">📥</div>
        <div>
            <div class="stat-value">
                Rp {{ number_format(($ringkasan['zakat']['penerimaan'] ?? 0) + ($ringkasan['infak_sedekah']['penerimaan'] ?? 0), 2, ',', '.') }}
            </div>
            <div class="stat-label">Penerimaan Periode</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon amber">📤</div>
        <div>
            <div class="stat-value">
                Rp {{ number_format(($ringkasan['zakat']['penyaluran'] ?? 0) + ($ringkasan['infak_sedekah']['penyaluran'] ?? 0), 2, ',', '.') }}
            </div>
            <div class="stat-label">Penyaluran Periode</div>
        </div>
    </div>
</div>

{{-- Kontrol integritas --}}
@if(! $validasi['valid'])
    <div class="card" style="background:#fef2f2;border:1px solid #fca5a5;">
        <div class="card-title" style="color:#991b1b;">⚠️ Temuan Validasi Integritas</div>
        <ul style="margin:.6rem 0 0 1.2rem;font-size:.85rem;color:#991b1b;line-height:1.7;">
            @foreach($validasi['catatan'] as $catatan)
                <li>{{ $catatan }}</li>
            @endforeach
        </ul>
    </div>
@endif

{{-- Filter periode --}}
<div class="card">
    <form method="GET" style="display:flex;gap:1rem;align-items:flex-end;flex-wrap:wrap;">
        <div>
            <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Dari Tanggal</label>
            <input type="date" name="dari" value="{{ $dari }}" style="padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
        </div>
        <div>
            <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Sampai Tanggal</label>
            <input type="date" name="sampai" value="{{ $sampai }}" style="padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
        </div>
        <button type="submit" class="btn btn-primary">Terapkan</button>
        <a href="{{ route($routeLaporan, ['dari' => $dari, 'sampai' => $sampai]) }}" class="btn" style="background:#f1f5f9;color:#334155;">
            Laporan Sumber &amp; Penggunaan Dana
        </a>
    </form>
</div>

@unless($readOnly)
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1.5rem;">
    {{-- Form Penerimaan --}}
    <div class="card">
        <div class="card-header"><div class="card-title">📥 Catat Penerimaan Dana</div></div>
        <form method="POST" action="{{ route('zakat.store') }}">
            @csrf
            <div style="display:grid;gap:.9rem;">
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Jenis Dana</label>
                    <select name="dana" id="terima-dana" required style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                        <option value="zakat">Zakat</option>
                        <option value="infak_sedekah">Infak &amp; Sedekah</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Kategori / Jenis Zakat</label>
                    <select name="kategori" id="terima-kategori" required style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                        @foreach($kategoriTerima as $kode => $label)
                            <option value="{{ $kode }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Muzakki / Munfiq</label>
                    <input type="text" name="pihak" id="terima-pihak" maxlength="150" placeholder="Nama pihak (boleh kosong bila memilih anggota)" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                </div>
                {{-- R-08 (Z-07) — relasi OPSIONAL ke master anggota; `pihak` tetap fallback teks --}}
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Atau Pilih Anggota (opsional)</label>
                    <select name="pihak_id" id="terima-pihak-id" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                        <option value="">— Pihak eksternal (isi nama di atas) —</option>
                        @foreach($anggotaList as $a)
                            <option value="{{ $a->id }}">{{ $a->nama_lengkap }} ({{ $a->no_anggota }})</option>
                        @endforeach
                    </select>
                    <div style="font-size:.7rem;color:#94a3b8;margin-top:.25rem;">Bila anggota dipilih, nama teks boleh dikosongkan.</div>
                </div>
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Jumlah (Rp)</label>
                    <input type="number" name="jumlah" id="terima-jumlah" required min="0.01" step="0.01" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                </div>
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Kas / Bank Penerimaan</label>
                    <select name="kode_kas" id="terima-kode-kas" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                        @forelse($akunKas as $akun)
                            <option value="{{ $akun->kode_akun }}" @selected($akun->kode_akun === $kodeKasDefault)>
                                {{ $akun->kode_akun }} — {{ $akun->nama_akun }}
                            </option>
                        @empty
                            <option value="{{ $kodeKasDefault }}">{{ $kodeKasDefault }} — Kas Teller (default)</option>
                        @endforelse
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Tanggal</label>
                    <input type="date" name="tanggal" required value="{{ now()->toDateString() }}" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                </div>
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Keterangan</label>
                    <input type="text" name="keterangan" maxlength="500" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                </div>
                <button type="submit" class="btn btn-primary">Posting Penerimaan</button>
            </div>
        </form>
    </div>

    {{-- Form Penyaluran --}}
    <div class="card">
        <div class="card-header"><div class="card-title">📤 Catat Penyaluran Dana</div></div>
        <form method="POST" action="{{ route('zakat.salurkan') }}">
            @csrf
            <div style="display:grid;gap:.9rem;">
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Jenis Dana</label>
                    <select name="dana" required style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                        <option value="zakat">Zakat</option>
                        <option value="infak_sedekah">Infak &amp; Sedekah</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Golongan Penerima</label>
                    <select name="kategori" required style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                        <optgroup label="8 Asnaf (zakat)">
                            @foreach($asnaf as $kode => $label)
                                <option value="{{ $kode }}">{{ $label }}</option>
                            @endforeach
                        </optgroup>
                        <optgroup label="Program Sosial (infak/sedekah)">
                            @foreach($kategoriSalur as $kode => $label)
                                @continue(array_key_exists($kode, $asnaf))
                                <option value="{{ $kode }}">{{ $label }}</option>
                            @endforeach
                        </optgroup>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Mustahik / Penerima</label>
                    <input type="text" name="pihak" id="salur-pihak" maxlength="150" placeholder="Nama pihak (boleh kosong bila memilih anggota)" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                </div>
                {{-- R-08 (Z-07) — relasi OPSIONAL ke master anggota; `pihak` tetap fallback teks --}}
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Atau Pilih Anggota (opsional)</label>
                    <select name="pihak_id" id="salur-pihak-id" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                        <option value="">— Pihak eksternal (isi nama di atas) —</option>
                        @foreach($anggotaList as $a)
                            <option value="{{ $a->id }}">{{ $a->nama_lengkap }} ({{ $a->no_anggota }})</option>
                        @endforeach
                    </select>
                    <div style="font-size:.7rem;color:#94a3b8;margin-top:.25rem;">Bila anggota dipilih, nama teks boleh dikosongkan.</div>
                </div>
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Jumlah (Rp)</label>
                    <input type="number" name="jumlah" required min="0.01" step="0.01" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                </div>
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Kas / Bank Penyaluran</label>
                    <select name="kode_kas" id="salur-kode-kas" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                        @forelse($akunKas as $akun)
                            <option value="{{ $akun->kode_akun }}" @selected($akun->kode_akun === $kodeKasDefault)>
                                {{ $akun->kode_akun }} — {{ $akun->nama_akun }}
                            </option>
                        @empty
                            <option value="{{ $kodeKasDefault }}">{{ $kodeKasDefault }} — Kas Teller (default)</option>
                        @endforelse
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Tanggal</label>
                    <input type="date" name="tanggal" required value="{{ now()->toDateString() }}" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                </div>
                <div>
                    <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Keterangan</label>
                    <input type="text" name="keterangan" maxlength="500" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
                </div>
                <button type="submit" class="btn btn-primary">Posting Penyaluran</button>
            </div>
        </form>
    </div>
</div>
@endunless

{{-- Kalkulator Zakat Penghasilan (profesi) — pra-isi form penerimaan --}}
@unless($readOnly)
<div class="card">
    <div class="card-header"><div class="card-title">🧮 Kalkulator Zakat Penghasilan (Pra-isi Penerimaan)</div></div>
    <p style="font-size:.8rem;color:#64748b;margin:0 0 1rem;">
        Zakat penghasilan = <strong>{{ rtrim(rtrim(number_format(\App\Support\Setting::zakatRate(), 2, ',', '.'), '0'), ',') }}%</strong>
        dari penghasilan neto (bruto − kebutuhan pokok) yang telah mencapai nishab bulanan
        <strong>Rp {{ number_format(round(\App\Support\Setting::zakatNisabNominal() / 12, 2), 2, ',', '.') }}</strong>
        (nishab {{ rtrim(rtrim(number_format(\App\Support\Setting::zakatNisabGram(), 2, ',', '.'), '0'), ',') }} gram emas ÷ 12 bulan).
        Hasil hanya <strong>mengisi</strong> form penerimaan — posting tetap dilakukan manual oleh Pengurus.
    </p>
    <form id="form-hisab-penghasilan" onsubmit="return false;" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end;">
        <div>
            <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Penghasilan Bruto Bulanan (Rp)</label>
            <input type="number" id="penghasilan-bruto" min="0" step="0.01" style="padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
        </div>
        <div>
            <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Kebutuhan Pokok Bulanan (Rp)</label>
            <input type="number" id="penghasilan-kebutuhan" min="0" step="0.01" value="0" style="padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
        </div>
        <button type="button" class="btn btn-primary" onclick="hitungZakatPenghasilan()">Hitung &amp; Isi Form</button>
    </form>
    <div id="penghasilan-hasil" style="display:none;margin-top:1rem;padding:.9rem 1.1rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.85rem;line-height:1.8;"></div>
</div>
<script>
    async function hitungZakatPenghasilan() {
        const bruto    = document.getElementById('penghasilan-bruto').value;
        const kebutuhan = document.getElementById('penghasilan-kebutuhan').value || '0';
        const box      = document.getElementById('penghasilan-hasil');

        if (bruto === '') {
            box.style.display = 'block';
            box.style.background = '#fee2e2';
            box.style.borderColor = '#fca5a5';
            box.textContent = 'Masukkan penghasilan bruto terlebih dahulu.';
            return;
        }

        const rp = (n) => 'Rp ' + Number(n).toLocaleString('id-ID', { minimumFractionDigits: 2 });

        try {
            const resp = await fetch('{{ route('zakat.hitung.penghasilan') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                },
                body: JSON.stringify({ bruto: bruto, kebutuhan_pokok: kebutuhan }),
            });
            const data = await resp.json();

            const rincian = '<div>Penghasilan neto: <strong>' + rp(data.penghasilan_neto) + '</strong></div>'
                          + '<div>Nishab bulanan: <strong>' + rp(data.nishab_bulanan) + '</strong></div>'
                          + '<div>Kadar: <strong>' + data.kadar_persen + '%</strong></div>'
                          + '<div>Zakat penghasilan: <strong>' + rp(data.zakat) + '</strong></div>';

            box.style.display = 'block';
            box.style.background = data.kena_zakat ? '#d1fae5' : '#fef9c3';
            box.style.borderColor = data.kena_zakat ? '#6ee7b7' : '#fde68a';
            box.innerHTML = '<div style="font-weight:600;margin-bottom:.4rem;">' + data.pesan + '</div>' + rincian;

            // Pra-isi form penerimaan (posting tetap manual oleh Pengurus).
            if (data.kena_zakat) {
                document.getElementById('terima-dana').value = 'zakat';
                document.getElementById('terima-kategori').value = 'zakat_penghasilan';
                document.getElementById('terima-jumlah').value = data.zakat;
            }
        } catch (e) {
            box.style.display = 'block';
            box.style.background = '#fee2e2';
            box.style.borderColor = '#fca5a5';
            box.textContent = 'Gagal menghitung zakat penghasilan. Silakan coba lagi.';
        }
    }
</script>
@endunless

{{-- Kalkulator Hisab Zakat Maal (nishab & haul) — memakai endpoint zakat.hitung --}}
@unless($readOnly)
<div class="card">
    <div class="card-header"><div class="card-title">🧮 Kalkulator Hisab Zakat Maal</div></div>
    <p style="font-size:.8rem;color:#64748b;margin:0 0 1rem;">
        Nishab dihitung dari {{ rtrim(rtrim(number_format(\App\Support\Setting::zakatNisabGram(), 2, ',', '.'), '0'), ',') }} gram emas
        × Rp {{ number_format(\App\Support\Setting::hargaEmasPerGram(), 0, ',', '.') }}/gram.
        @if(\App\Support\Setting::zakatHaulAktif())
            Syarat <strong>haul</strong> aktif: harta baru wajib zakat setelah dimiliki genap
            {{ \App\Support\Setting::zakatHaulHari() }} hari.
        @else
            Syarat <strong>haul</strong> saat ini nonaktif (hisab berbasis nishab saja).
        @endif
    </p>
    <form id="form-hisab-zakat" onsubmit="return false;" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end;">
        <div>
            <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Total Harta (Rp)</label>
            <input type="number" id="hisab-harta" min="0" step="0.01" style="padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
        </div>
        <div>
            <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">
                Tanggal Mulai Kepemilikan{{ \App\Support\Setting::zakatHaulAktif() ? ' (wajib)' : ' (opsional)' }}
            </label>
            <input type="date" id="hisab-tanggal" style="padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
        </div>
        <button type="button" class="btn btn-primary" onclick="hitungZakat()">Hitung Zakat</button>
    </form>
    <div id="hisab-hasil" style="display:none;margin-top:1rem;padding:.9rem 1.1rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.85rem;line-height:1.8;"></div>
</div>
<script>
    async function hitungZakat() {
        const harta   = document.getElementById('hisab-harta').value;
        const tanggal = document.getElementById('hisab-tanggal').value;
        const box     = document.getElementById('hisab-hasil');

        if (harta === '') {
            box.style.display = 'block';
            box.style.background = '#fee2e2';
            box.style.borderColor = '#fca5a5';
            box.textContent = 'Masukkan total harta terlebih dahulu.';
            return;
        }

        const rp = (n) => 'Rp ' + Number(n).toLocaleString('id-ID', { minimumFractionDigits: 2 });

        try {
            const resp = await fetch('{{ route('zakat.hitung') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                },
                body: JSON.stringify({ harta: harta, tanggal_kepemilikan: tanggal || null }),
            });
            const data = await resp.json();

            let rincian = '<div>Nishab: <strong>' + rp(data.nishab) + '</strong></div>'
                        + '<div>Kadar: <strong>' + data.kadar_persen + '%</strong></div>';
            if (data.haul_aktif) {
                rincian += '<div>Haul: <strong>' + (data.haul_terpenuhi ? 'terpenuhi' : 'belum terpenuhi') + '</strong>'
                         + (data.hari_kepemilikan !== null ? ' (' + data.hari_kepemilikan + ' dari ' + data.haul_hari + ' hari)' : '') + '</div>';
            }
            rincian += '<div>Zakat terutang: <strong>' + rp(data.zakat) + '</strong></div>';

            box.style.display = 'block';
            box.style.background = data.kena_zakat ? '#d1fae5' : '#fef9c3';
            box.style.borderColor = data.kena_zakat ? '#6ee7b7' : '#fde68a';
            box.innerHTML = '<div style="font-weight:600;margin-bottom:.4rem;">' + data.pesan + '</div>' + rincian;
        } catch (e) {
            box.style.display = 'block';
            box.style.background = '#fee2e2';
            box.style.borderColor = '#fca5a5';
            box.textContent = 'Gagal menghitung zakat. Silakan coba lagi.';
        }
    }
</script>
@endunless

{{-- Buku besar --}}
<div class="card">
    <div class="card-header">
        <div class="card-title">Buku Besar Transaksi ({{ $dari }} s/d {{ $sampai }})</div>
        <span class="badge badge-gray">{{ $transaksi->total() }} baris</span>
    </div>
    <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>No. Referensi</th>
                    <th>Tanggal</th>
                    <th>Dana</th>
                    <th>Jenis</th>
                    <th>Kategori</th>
                    <th>Pihak</th>
                    <th style="text-align:right;">Jumlah</th>
                    <th>Jurnal</th>
                    <th>Status</th>
                    @unless($readOnly)<th>Aksi</th>@endunless
                </tr>
            </thead>
            <tbody>
                @forelse($transaksi as $t)
                    <tr @if($t->sudah_void) style="opacity:.6;" @endif>
                        <td style="font-family:monospace;font-size:.8rem;">{{ $t->no_referensi }}</td>
                        <td>{{ $t->tanggal?->format('d/m/Y') }}</td>
                        <td>
                            <span class="badge {{ $t->dana === \App\Models\ZakatTransaksi::DANA_ZAKAT ? 'badge-green' : 'badge-blue' }}">
                                {{ $t->dana_label }}
                            </span>
                        </td>
                        <td>
                            <span class="badge {{ $t->jenis === \App\Models\ZakatTransaksi::JENIS_PENERIMAAN ? 'badge-teal' : 'badge-amber' }}">
                                {{ $t->jenis_label }}
                            </span>
                        </td>
                        <td>{{ $t->kategori_label }}</td>
                        <td>
                            {{ $t->pihak_tampil }}
                            @if($t->tertaut_anggota)
                                <span class="badge badge-blue" title="Tertaut ke master anggota">anggota</span>
                            @endif
                        </td>
                        <td style="text-align:right;font-variant-numeric:tabular-nums;">
                            Rp {{ number_format((float) $t->jumlah, 2, ',', '.') }}
                        </td>
                        <td>
                            @if($t->jurnal_id)
                                <span class="badge badge-green">#{{ $t->jurnal_id }}</span>
                            @elseif($t->menunggu_persetujuan)
                                <span class="badge badge-amber">belum diposting</span>
                            @else
                                <span class="badge badge-red">tanpa jurnal</span>
                            @endif
                        </td>
                        <td>
                            @if($t->sudah_void)
                                <span class="badge badge-red" title="{{ $t->void_reason ?: 'Dibatalkan' }}">VOID</span>
                            @elseif($t->menunggu_persetujuan)
                                <span class="badge badge-amber" title="Menunggu persetujuan pengurus/manajer lain (dual control)">Menunggu Persetujuan</span>
                            @elseif($t->ditolak)
                                <span class="badge badge-red" title="{{ $t->alasan_tolak ?: 'Ditolak' }}">Ditolak</span>
                            @else
                                <span class="badge badge-green">Berlaku</span>
                            @endif
                        </td>
                        @unless($readOnly)
                        <td>
                            @if($t->menunggu_persetujuan)
                                <form method="POST" action="{{ route('zakat.setujui', $t->id) }}"
                                      onsubmit="return confirm('Setujui penyaluran {{ $t->no_referensi }}? Jurnal penyaluran akan diposting.');"
                                      style="display:inline;">
                                    @csrf
                                    <button type="submit" class="btn" style="background:#d1fae5;color:#065f46;padding:.25rem .6rem;font-size:.75rem;">Setujui</button>
                                </form>
                                <form method="POST" action="{{ route('zakat.tolak', $t->id) }}"
                                      onsubmit="return confirm('Tolak pengajuan penyaluran {{ $t->no_referensi }}?');"
                                      style="display:inline;">
                                    @csrf
                                    <input type="hidden" name="alasan" value="Ditolak oleh {{ auth()->user()->name }}">
                                    <button type="submit" class="btn" style="background:#fee2e2;color:#991b1b;padding:.25rem .6rem;font-size:.75rem;">Tolak</button>
                                </form>
                            @elseif(! $t->sudah_void && ! $t->ditolak)
                                <form method="POST" action="{{ route('zakat.batalkan', $t->id) }}"
                                      onsubmit="return confirm('Batalkan transaksi {{ $t->no_referensi }}? Jurnal akan dibalik dan baris ditandai void (TIDAK dihapus).');"
                                      style="display:inline;">
                                    @csrf
                                    <input type="hidden" name="alasan" value="Dibatalkan oleh {{ auth()->user()->name }}">
                                    <button type="submit" class="btn" style="background:#fee2e2;color:#991b1b;padding:.25rem .6rem;font-size:.75rem;">Batalkan</button>
                                </form>
                            @endif
                        </td>
                        @endunless
                    </tr>
                @empty
                    <tr><td colspan="{{ $readOnly ? 9 : 10 }}" style="text-align:center;color:#94a3b8;padding:2rem;">Belum ada transaksi pada periode ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($transaksi->hasPages())
    <div style="padding:1rem 1.25rem;border-top:1px solid #f1f5f9;">
        {{ $transaksi->links() }}
    </div>
    @endif
</div>

@endsection
