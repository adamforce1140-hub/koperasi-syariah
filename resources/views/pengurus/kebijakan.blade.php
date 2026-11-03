@extends('layouts.app')

@section('title', 'Profil & Kebijakan Koperasi — Pengurus')
@section('page-title', 'Profil & Kebijakan Koperasi')

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- ── HEADER ─────────────────────────────────────── --}}
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
                Profil & Kebijakan Koperasi
            </h1>
            <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
                Pengaturan parameter Simpanan (Pokok, Wajib, Sukarela), limit kewenangan approval pembiayaan &amp; teller, profil kelembagaan, dan aturan rasio kesehatan.
            </p>
        </div>
        <div style="display:flex;align-items:center;gap:0.5rem;background:#eff6ff;border:1px solid #bfdbfe;border-radius:0.5rem;padding:0.5rem 0.75rem;">
            <span style="font-size:0.72rem;font-weight:700;color:#1d4ed8;text-transform:uppercase;letter-spacing:.04em;">Master Settings</span>
            <span style="font-size:0.72rem;color:#3b82f6;">Dinamis • tanpa ubah kode</span>
        </div>
    </div>

    @if(session('success'))
        <div style="background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;padding:0.75rem 1rem;border-radius:0.5rem;font-size:0.85rem;font-weight:600;">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div style="background:#fee2e2;color:#991b1b;border:1px solid #fecaca;padding:0.75rem 1rem;border-radius:0.5rem;font-size:0.85rem;">
            <strong>Validasi gagal:</strong>
            <ul style="margin:0.35rem 0 0 1rem;padding:0;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ── CARD KEBIJAKAN ──────────────────────────────── --}}
    @if(\App\Support\HierarkiRole::dalamKelompok(auth()->user()->role, 'pengurus'))
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <h3 style="font-size:1rem;font-weight:700;color:#1e293b;margin-top:0;margin-bottom:1.25rem;border-bottom:1px solid #f1f5f9;padding-bottom:0.75rem;">
            Limit Otorisasi Persetujuan Pembiayaan
        </h3>

        <form action="{{ route('pengurus.kebijakan.update') }}" method="POST">
            @csrf
            @method('PUT')

            {{-- ── SECTION 0: Parameter Simpanan (Pokok, Wajib, Sukarela) ── --}}
            <h4 style="font-size:0.875rem;font-weight:700;color:#065f46;margin:0 0 0.5rem;text-transform:uppercase;letter-spacing:.04em;">
                Parameter Simpanan Anggota
                <span style="font-weight:400;font-size:0.75rem;color:#047857;text-transform:none;letter-spacing:0;margin-left:.4rem;">(PSAK 101/108 — Simpanan Pokok 3-1001 &amp; Simpanan Wajib 3-1002, akun &amp; parameter dinamis)</span>
            </h4>
            <p style="font-size:0.78rem;color:#78716c;margin:0 0 1rem;">
                Nilai berikut dipakai otomatis oleh pendaftaran anggota baru (bundling setoran awal) dan penagihan Simpanan Wajib bulanan — tanpa perlu mengubah kode program.
            </p>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1.25rem;margin-bottom:1.25rem;">
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="mandatory_principal_savings">
                        Simpanan Pokok — Setoran Awal (Rp)
                    </label>
                    <input id="mandatory_principal_savings" type="text" name="mandatory_principal_savings" inputmode="numeric"
                        value="{{ old('mandatory_principal_savings', number_format((float) ($kebijakan->mandatory_principal_savings ?? 100000), 0, ',', '.')) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;"
                        required>
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Sekali bayar saat pendaftaran. Dikreditkan ke akun <strong>3-1001</strong>.
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="mandatory_monthly_savings">
                        Simpanan Wajib — Per Bulan (Rp)
                    </label>
                    <input id="mandatory_monthly_savings" type="text" name="mandatory_monthly_savings" inputmode="numeric"
                        value="{{ old('mandatory_monthly_savings', number_format((float) ($kebijakan->mandatory_monthly_savings ?? 50000), 0, ',', '.')) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;"
                        required>
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Ditagih bulanan (bulan ke-1 ikut bundling setoran awal). Dikreditkan ke akun <strong>3-1002</strong>.
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                        Simpanan Wajib — Akun COA Kanonik (PSAK 101)
                    </label>
                    <select name="account_simpanan_wajib_id"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                        <option value="">— Ikuti master produk / standar 3-1002 —</option>
                        @foreach ($akunCoa as $akun)
                            <option value="{{ $akun->id }}"
                                {{ (int) old('account_simpanan_wajib_id', $kebijakan->account_simpanan_wajib_id) === (int) $akun->id ? 'selected' : '' }}>
                                {{ $akun->kode_akun }} — {{ $akun->nama_akun }}
                            </option>
                        @endforeach
                    </select>
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Akun kredit seluruh jalur setoran Simpanan Wajib (teller, auto-debit, bundling setoran awal).
                        Dibiarkan kosong = mengikuti master <em>Jenis Simpanan</em>; standar <strong>3-1002</strong> (Modal Anggota).
                    </span>
                </div>
            </div>

            {{-- ── Parameter operasional Simpanan Wajib (Tahap 12 / R1) ── --}}
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1.25rem;margin-bottom:1.25rem;">
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                        Simpanan Wajib — Kanal Pembayaran Default
                    </label>
                    <select name="simpanan_wajib_kanal_default"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                        @foreach (['AUTO_DEBIT' => 'Auto-Debit Simpanan Sukarela', 'CASH' => 'Tunai (Kas Teller)', 'TRANSFER' => 'Transfer Bank'] as $nilai => $labelKanal)
                            <option value="{{ $nilai }}"
                                {{ old('simpanan_wajib_kanal_default', $kebijakan->simpanan_wajib_kanal_default ?? 'AUTO_DEBIT') === $nilai ? 'selected' : '' }}>
                                {{ $labelKanal }}
                            </option>
                        @endforeach
                    </select>
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Kanal yang ditawarkan lebih dulu pada penagihan anggota baru.
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                        Simpanan Wajib — Waktu Penarikan
                    </label>
                    <select name="simpanan_wajib_hari_penarikan"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                        @foreach (['SETELAH_KELUAR' => 'Hanya setelah anggota keluar', 'KAPAN_SAJA' => 'Kapan saja sesuai kebijakan Pengurus'] as $nilai => $labelWaktu)
                            <option value="{{ $nilai }}"
                                {{ old('simpanan_wajib_hari_penarikan', $kebijakan->simpanan_wajib_hari_penarikan ?? 'SETELAH_KELUAR') === $nilai ? 'selected' : '' }}>
                                {{ $labelWaktu }}
                            </option>
                        @endforeach
                    </select>
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Berlaku hanya bila penarikan diizinkan. Sesuai UU No. 25/1992 Pasal 30 &amp; Permen KUKM 09/2018.
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="jam_posting_simpanan_wajib">
                        Simpanan Wajib — Jam Posting Auto-Debit
                    </label>
                    <input id="jam_posting_simpanan_wajib" type="text" name="jam_posting_simpanan_wajib" placeholder="00:01"
                        pattern="^([01][0-9]|2[0-3]):[0-5][0-9]$"
                        title="Format HH:MM 24 jam, contoh 00:01 atau 08:30"
                        value="{{ old('jam_posting_simpanan_wajib', \App\Support\Setting::jamPostingSimpananWajib()) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Jam pencatatan otomatis transaksi Simpanan Wajib tanggal jatuh tempo. Kosong = 00:01.
                    </span>
                </div>
            </div>

            {{-- Kebijakan penarikan Simpanan Wajib (PSAK 101 — Modal Anggota) --}}
            <div style="background:#fef9c3;border:1px solid #fde68a;border-radius:0.5rem;padding:1rem;margin-bottom:1.5rem;">
                <label style="display:flex;align-items:flex-start;gap:0.75rem;cursor:pointer;">
                    <input type="hidden" name="simpanan_wajib_boleh_ditarik" value="0">
                    <input type="checkbox" name="simpanan_wajib_boleh_ditarik" value="1"
                        {{ old('simpanan_wajib_boleh_ditarik', $kebijakan->simpanan_wajib_boleh_ditarik ?? false) ? 'checked' : '' }}
                        style="margin-top:0.15rem;width:1.05rem;height:1.05rem;cursor:pointer;">
                    <span>
                        <span style="display:block;font-size:0.85rem;font-weight:700;color:#78350f;">
                            Izinkan Penarikan Saldo Simpanan Wajib
                        </span>
                        <span style="display:block;font-size:0.78rem;color:#92400e;margin-top:0.25rem;">
                            Bila aktif, saldo Simpanan Wajib anggota dapat ditarik (mis. saat keluar keanggotaan) sehingga
                            posisi Modal Anggota anggota tersebut kembali nol. Bila nonaktif, tidak ada jalur penarikan yang tersedia.
                        </span>
                    </span>
                </label>
            </div>

            {{-- Kebijakan penahanan SHU --}}
            <div style="background:#fef9c3;border:1px solid #fde68a;border-radius:0.5rem;padding:1rem;margin-bottom:1.5rem;">
                <label style="display:flex;align-items:flex-start;gap:0.75rem;cursor:pointer;">
                    <input type="hidden" name="shu_hold_on_overdue" value="0">
                    <input type="checkbox" name="shu_hold_on_overdue" value="1"
                        {{ old('shu_hold_on_overdue', $kebijakan->shu_hold_on_overdue ?? true) ? 'checked' : '' }}
                        style="margin-top:0.15rem;width:1.05rem;height:1.05rem;cursor:pointer;">
                    <span>
                        <span style="display:block;font-size:0.85rem;font-weight:700;color:#78350f;">
                            Tahan Pencairan SHU bagi Anggota yang Menunggak Simpanan Wajib
                        </span>
                        <span style="display:block;font-size:0.78rem;color:#92400e;margin-top:0.25rem;">
                            Bila aktif, SHU anggota yang masih memiliki tagihan Simpanan Wajib berstatus <strong>OVERDUE</strong> pada penutupan buku akhir tahun ditahan seluruhnya.
                            Penahanan dilepas <strong>otomatis</strong> begitu seluruh tunggakan dilunasi — tanpa intervensi manual.
                        </span>
                    </span>
                </label>
            </div>

            {{-- ── SECTION: Alokasi SHU (persentase dinamis) ── --}}
            <h4 style="font-size:0.875rem;font-weight:700;color:#065f46;margin:0 0 0.5rem;text-transform:uppercase;letter-spacing:.04em;border-top:1px solid #f1f5f9;padding-top:1.25rem;">
                Alokasi SHU Akhir Tahun
                <span style="font-weight:400;font-size:0.75rem;color:#047857;text-transform:none;letter-spacing:0;margin-left:.4rem;">(total harus tepat 100%)</span>
            </h4>
            <p style="font-size:0.78rem;color:#78716c;margin:0 0 1rem;">
                Persentase ini dipakai otomatis oleh perhitungan &amp; pengesahan SHU pada dashboard Pengurus — tanpa mengubah kode program.
            </p>

            @php
                // SSOT alokasi SHU kebijakan (4 pos). Fallback field memakai
                // nilai SSOT yang sama agar tidak ada literal ganda yang
                // menyimpang dari Setting::alokasiShu().
                $shuKebijakan = \App\Support\Setting::alokasiShu();
            @endphp

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1.25rem;margin-bottom:0.75rem;">
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="shu_pct_cadangan_modal">
                        Cadangan Modal (%)
                    </label>
                    <input id="shu_pct_cadangan_modal" type="number" class="input-shu" name="shu_pct_cadangan_modal" min="0" max="100" step="{{ \App\Support\Setting::langkah('shu_pct_cadangan_modal') }}"
                        value="{{ old('shu_pct_cadangan_modal', (float) ($kebijakan->shu_pct_cadangan_modal ?? $shuKebijakan['cadangan_modal'])) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;"
                        required>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="shu_pct_jasa_anggota">
                        Jasa Anggota (%)
                    </label>
                    <input id="shu_pct_jasa_anggota" type="number" class="input-shu" name="shu_pct_jasa_anggota" min="0" max="100" step="{{ \App\Support\Setting::langkah('shu_pct_jasa_anggota') }}"
                        value="{{ old('shu_pct_jasa_anggota', (float) ($kebijakan->shu_pct_jasa_anggota ?? $shuKebijakan['jasa_anggota'])) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;"
                        required>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="shu_pct_jasa_modal">
                        Jasa Modal (%)
                    </label>
                    <input id="shu_pct_jasa_modal" type="number" class="input-shu" name="shu_pct_jasa_modal" min="0" max="100" step="{{ \App\Support\Setting::langkah('shu_pct_jasa_modal') }}"
                        value="{{ old('shu_pct_jasa_modal', (float) ($kebijakan->shu_pct_jasa_modal ?? $shuKebijakan['jasa_modal'])) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;"
                        required>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="shu_pct_dana_pendidikan">
                        Dana Pendidikan (%)
                    </label>
                    <input id="shu_pct_dana_pendidikan" type="number" class="input-shu" name="shu_pct_dana_pendidikan" min="0" max="100" step="{{ \App\Support\Setting::langkah('shu_pct_dana_pendidikan') }}"
                        value="{{ old('shu_pct_dana_pendidikan', (float) ($kebijakan->shu_pct_dana_pendidikan ?? $shuKebijakan['dana_pendidikan'])) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;"
                        required>
                </div>
            </div>

            <div id="preview-shu" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:0.5rem;padding:0.75rem 1rem;margin-bottom:1.25rem;font-size:0.82rem;color:#065f46;">
                <strong>Total alokasi SHU:</strong> <span id="preview-shu-text">—</span>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1.25rem;margin-bottom:1.25rem;">
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                        Metode Perhitungan Partisipasi SHU
                    </label>
                    <select name="metode_perhitungan_shu"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                        @php $metodeShu = old('metode_perhitungan_shu', $kebijakan->metode_perhitungan_shu ?? 'SRH'); @endphp
                        <option value="SRH" @selected($metodeShu === 'SRH')>SRH — Saldo Rata-rata Harian</option>
                    </select>
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Satu-satunya metode perhitungan partisipasi SHU yang didukung sistem.
                    </span>
                </div>
            </div>

            {{-- ── SECTION: Batas Nominal Pembukaan Rekening (dinamis) ── --}}
            <h4 style="font-size:0.875rem;font-weight:700;color:#475569;margin:0 0 0.5rem;text-transform:uppercase;letter-spacing:.04em;border-top:1px solid #f1f5f9;padding-top:1.25rem;">
                Batas Nominal Pembukaan Rekening
                <span style="font-weight:400;font-size:0.75rem;color:#047857;text-transform:none;letter-spacing:0;margin-left:.4rem;">(berlaku untuk SEMUA jalur pembukaan rekening)</span>
            </h4>
            <p style="font-size:0.78rem;color:#78716c;margin:0 0 1rem;">
                Batas minimum &amp; maksimum ini dipakai otomatis oleh seluruh jalur pembukaan rekening — AO/Portofolio CIF,
                teller deposito, konfirmasi pengajuan nasabah, dan API — tanpa perlu mengubah kode program.
                Nominal aktual dikaitkan ke akun COA sesuai jenis simpanan yang dipilih (lihat master Jenis Simpanan).
            </p>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1.25rem;margin-bottom:1.25rem;">
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="minimum_pembukaan_rekening">
                        Batas Minimum Nominal Pembukaan Rekening (Rp)
                    </label>
                    <input id="minimum_pembukaan_rekening" type="text" name="minimum_pembukaan_rekening" inputmode="numeric"
                        value="{{ old('minimum_pembukaan_rekening', number_format((float) ($kebijakan->minimum_pembukaan_rekening ?? 10000), 0, ',', '.')) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;"
                        required>
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Nominal di bawah nilai ini akan <strong>ditolak</strong> di semua jalur pembukaan rekening.
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="maksimum_pembukaan_rekening">
                        Batas Maksimum Nominal Pembukaan Rekening (Rp)
                    </label>
                    <input id="maksimum_pembukaan_rekening" type="text" name="maksimum_pembukaan_rekening" inputmode="numeric"
                        value="{{ old('maksimum_pembukaan_rekening', number_format((float) ($kebijakan->maksimum_pembukaan_rekening ?? 50000000), 0, ',', '.')) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;"
                        required>
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Harus &ge; batas minimum. Nominal di atas nilai ini akan <strong>ditolak</strong>.
                    </span>
                </div>
            </div>

            {{-- ── SECTION: Penomoran Dokumen (awalan dapat diubah) ── --}}
            <h4 style="font-size:0.875rem;font-weight:700;color:#475569;margin:0 0 0.5rem;text-transform:uppercase;letter-spacing:.04em;border-top:1px solid #f1f5f9;padding-top:1.25rem;">
                Penomoran Dokumen
            </h4>
            <p style="font-size:0.78rem;color:#78716c;margin:0 0 1rem;">
                Awalan (prefix) nomor dokumen yang dipakai koperasi Anda. Hanya huruf &amp; angka, maksimal 8 karakter.
                Kosongkan bila ingin memakai awalan bawaan. Nomor yang <strong>sudah terbit tidak berubah</strong> —
                pengaturan ini hanya berlaku untuk dokumen baru.
            </p>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1.25rem;margin-bottom:1.75rem;">
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="prefix_nomor_transaksi">
                        Nomor Transaksi
                    </label>
                    <input id="prefix_nomor_transaksi" type="text" name="prefix_nomor_transaksi" maxlength="8" autocomplete="off"
                        value="{{ old('prefix_nomor_transaksi', $kebijakan->prefix_nomor_transaksi ?? '') }}"
                        placeholder="TRX"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;text-transform:uppercase;">
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Bawaan <strong>TRX</strong> — contoh hasil: TRX-20261021-000001.
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="prefix_nomor_rekening">
                        Nomor Rekening
                    </label>
                    <input id="prefix_nomor_rekening" type="text" name="prefix_nomor_rekening" maxlength="8" autocomplete="off"
                        value="{{ old('prefix_nomor_rekening', $kebijakan->prefix_nomor_rekening ?? '') }}"
                        placeholder="RKN"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;text-transform:uppercase;">
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Bawaan <strong>RKN</strong> — contoh hasil: RKN-2026-000001.
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="prefix_nomor_jurnal">
                        Nomor Jurnal
                    </label>
                    <input id="prefix_nomor_jurnal" type="text" name="prefix_nomor_jurnal" maxlength="8" autocomplete="off"
                        value="{{ old('prefix_nomor_jurnal', $kebijakan->prefix_nomor_jurnal ?? '') }}"
                        placeholder="JRN"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;text-transform:uppercase;">
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Bawaan <strong>JRN</strong> — contoh hasil: JRN-20261021-000001.
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="prefix_nomor_pengeluaran_beban">
                        Bukti Kas/Bank Keluar
                    </label>
                    <input id="prefix_nomor_pengeluaran_beban" type="text" name="prefix_nomor_pengeluaran_beban" maxlength="8" autocomplete="off"
                        value="{{ old('prefix_nomor_pengeluaran_beban', $kebijakan->prefix_nomor_pengeluaran_beban ?? '') }}"
                        placeholder="BBK"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;text-transform:uppercase;">
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Bawaan <strong>BBK</strong> — contoh hasil: BBK-20261021-000001.
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="prefix_nomor_terminasi">
                        Nomor Terminasi Anggota
                    </label>
                    <input id="prefix_nomor_terminasi" type="text" name="prefix_nomor_terminasi" maxlength="8" autocomplete="off"
                        value="{{ old('prefix_nomor_terminasi', $kebijakan->prefix_nomor_terminasi ?? '') }}"
                        placeholder="TRM"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;text-transform:uppercase;">
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Bawaan <strong>TRM</strong> — contoh hasil: TRM-20261021-000001.
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="prefix_nomor_deposito">
                        Nomor Bilyet Deposito
                    </label>
                    <input id="prefix_nomor_deposito" type="text" name="prefix_nomor_deposito" maxlength="8" autocomplete="off"
                        value="{{ old('prefix_nomor_deposito', $kebijakan->prefix_nomor_deposito ?? '') }}"
                        placeholder="DEP"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;text-transform:uppercase;">
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Bawaan <strong>DEP</strong> — contoh hasil: DEP-20261021-000001.
                    </span>
                </div>
            </div>

            {{-- ── SECTION: Periode & Kalender (penjadwalan angsuran) ── --}}
            <h4 style="font-size:0.875rem;font-weight:700;color:#475569;margin:0 0 0.5rem;text-transform:uppercase;letter-spacing:.04em;border-top:1px solid #f1f5f9;padding-top:1.25rem;">
                Periode &amp; Kalender
            </h4>
            <p style="font-size:0.78rem;color:#78716c;margin:0 0 1rem;">
                Aturan penjadwalan angsuran pembiayaan. Tanggal jatuh tempo yang jatuh pada akhir pekan
                atau hari libur akan digeser ke hari kerja terdekat. Daftar hari libur dikelola di
                menu <strong>Kalender Hari Libur</strong>.
            </p>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1.25rem;margin-bottom:1.5rem;">
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="tanggal_jatuh_tempo_default">
                        Tanggal Jatuh Tempo Bulanan
                    </label>
                    <input id="tanggal_jatuh_tempo_default" type="number" name="tanggal_jatuh_tempo_default"
                        value="{{ old('tanggal_jatuh_tempo_default', (int) ($kebijakan->tanggal_jatuh_tempo_default ?? \App\Support\Setting::tanggalJatuhTempoDefault())) }}"
                        min="1" max="28" step="{{ \App\Support\Setting::langkah('tanggal_jatuh_tempo_default') }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Tanggal angsuran setiap bulan (1&ndash;28, agar aman untuk Februari).
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                        Arah Penggeseran Hari Libur
                    </label>
                    <select name="hari_libur_strategi"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;background:#fff;">
                        <option value="MAJU" {{ old('hari_libur_strategi', $kebijakan->hari_libur_strategi ?? 'MAJU') === 'MAJU' ? 'selected' : '' }}>
                            MAJU &mdash; hari kerja berikutnya
                        </option>
                        <option value="MUNDUR" {{ old('hari_libur_strategi', $kebijakan->hari_libur_strategi ?? 'MAJU') === 'MUNDUR' ? 'selected' : '' }}>
                            MUNDUR &mdash; hari kerja sebelumnya
                        </option>
                    </select>
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Arah penggeseran bila jatuh tempo bertepatan dengan hari libur.
                    </span>
                </div>
            </div>

            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:0.5rem;padding:1rem;margin-bottom:1.5rem;">
                <label style="display:flex;align-items:flex-start;gap:0.75rem;cursor:pointer;">
                    <input type="hidden" name="hari_libur_aktif" value="0">
                    <input type="checkbox" name="hari_libur_aktif" value="1"
                        {{ old('hari_libur_aktif', $kebijakan->hari_libur_aktif ?? true) ? 'checked' : '' }}
                        style="margin-top:0.15rem;width:1.05rem;height:1.05rem;cursor:pointer;">
                    <span>
                        <span style="display:block;font-size:0.85rem;font-weight:700;color:#1e40af;">
                            Sesuaikan Jatuh Tempo ke Hari Kerja
                        </span>
                        <span style="display:block;font-size:0.78rem;color:#1d4ed8;margin-top:0.25rem;">
                            Bila dimatikan, tanggal jatuh tempo dipakai apa adanya meskipun jatuh pada Sabtu, Minggu, atau hari libur.
                        </span>
                    </span>
                </label>
            </div>

            {{-- ── SECTION: Pemeliharaan & Cadangan Basis Data ── --}}
            <h4 style="font-size:0.875rem;font-weight:700;color:#475569;margin:0 0 0.5rem;text-transform:uppercase;letter-spacing:.04em;border-top:1px solid #f1f5f9;padding-top:1.25rem;">
                Pemeliharaan &amp; Cadangan Basis Data
            </h4>
            <p style="font-size:0.78rem;color:#78716c;margin:0 0 1rem;">
                Cadangan basis data dibuat otomatis setiap hari pukul 01:00 WIB. Berkas cadangan yang
                lebih tua dari batas retensi akan dihapus otomatis agar ruang penyimpanan tidak penuh.
            </p>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1.25rem;margin-bottom:1.5rem;">
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="backup_retensi_hari">
                        Retensi Berkas Cadangan (Hari)
                    </label>
                    <input id="backup_retensi_hari" type="number" name="backup_retensi_hari"
                        value="{{ old('backup_retensi_hari', (int) ($kebijakan->backup_retensi_hari ?? \App\Support\Setting::backupRetensiHari())) }}"
                        min="1" max="365" step="{{ \App\Support\Setting::langkah('backup_retensi_hari') }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Umur maksimum berkas cadangan sebelum dihapus (1&ndash;365 hari). Default 14 hari.
                    </span>
                </div>
            </div>

            {{-- ── SECTION 1: Limit Otorisasi ── --}}
            <h4 style="font-size:0.875rem;font-weight:700;color:#475569;margin:0 0 1rem;text-transform:uppercase;letter-spacing:.04em;border-top:1px solid #f1f5f9;padding-top:1.25rem;">
                Limit Otorisasi Pembiayaan
            </h4>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1.25rem;margin-bottom:1.75rem;">
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="limit_manajer">
                        Limit Kewenangan Manajer (Max)
                    </label>
                    <input type="text"
                        id="limit_manajer"
                        name="limit_manajer"
                        value="{{ old('limit_manajer', (int) ($kebijakan->limit_manajer ?? \App\Support\Setting::limitManajerPembiayaan())) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;"
                        required>
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Pengajuan sampai nilai ini dapat disetujui penuh oleh Manajer.
                    </span>
                </div>
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="limit_pembiayaan_manajer">
                        Limit Pembiayaan Skala Besar (Pengurus)
                    </label>
                    <input type="text"
                        id="limit_pembiayaan_manajer"
                        name="limit_pembiayaan_manajer"
                        value="{{ old('limit_pembiayaan_manajer', (int) ($kebijakan->limit_pembiayaan_manajer ?? \App\Support\Setting::limitPembiayaanManajer())) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;"
                        required>
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Pengajuan di atas limit Manajer wajib diteruskan &amp; disetujui Pengurus
                        (maksimal sampai nilai ini).
                    </span>
                </div>
            </div>

            {{-- ── SECTION: Limit Kas & Transaksi Teller ── --}}
            <h4 style="font-size:0.875rem;font-weight:700;color:#475569;margin:0 0 0.5rem;text-transform:uppercase;letter-spacing:.04em;border-top:1px solid #f1f5f9;padding-top:1.25rem;">
                Limit Kas &amp; Otorisasi Transaksi Teller
            </h4>
            <p style="font-size:0.78rem;color:#78716c;margin:0 0 1rem;">
                Parameter ini dipakai otomatis oleh modul Teller (validasi transaksi dan kontrol kas laci)
                melalui helper <code>setting()</code> — tanpa mengubah kode program.
            </p>

            {{-- ── Limit Otorisasi Berjenjang: Teller → Manajer ── --}}
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1.25rem;margin-bottom:1.5rem;">
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="limit_penarikan_teller">
                        Limit Penarikan Tunai Teller (Rp)
                    </label>
                    <input type="text"
                        id="limit_penarikan_teller"
                        name="limit_penarikan_teller"
                        inputmode="numeric"
                        value="{{ old('limit_penarikan_teller', number_format((float) ($kebijakan->limit_penarikan_teller ?? \App\Support\Setting::limitPenarikanTeller()), 0, ',', '.')) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;"
                        required>
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Penarikan tunai <strong>sampai nilai ini per transaksi</strong> dapat diproses penuh oleh Teller.
                    </span>
                </div>
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                        Ambang Approval Manajer — Penarikan
                    </label>
                    <input type="text"
                        id="ambang_penarikan_teller"
                        value="> Rp {{ number_format((float) ($kebijakan->limit_penarikan_teller ?? \App\Support\Setting::limitPenarikanTeller()), 0, ',', '.') }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;background:#f8fafc;color:#334155;font-weight:600;"
                        readonly>
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Penarikan di atas limit Teller wajib approval Manajer.
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="limit_setoran_teller">
                        Limit Setoran Tunai Teller (Rp)
                    </label>
                    <input type="text"
                        id="limit_setoran_teller"
                        name="limit_setoran_teller"
                        inputmode="numeric"
                        value="{{ old('limit_setoran_teller', number_format((float) ($kebijakan->limit_setoran_teller ?? \App\Support\Setting::limitSetoranTeller()), 0, ',', '.')) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;"
                        required>
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Setoran tunai <strong>sampai nilai ini per transaksi</strong> dapat diproses penuh oleh Teller.
                    </span>
                </div>
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                        Ambang Approval Manajer — Setoran
                    </label>
                    <input type="text"
                        id="ambang_setoran_teller"
                        value="> Rp {{ number_format((float) ($kebijakan->limit_setoran_teller ?? \App\Support\Setting::limitSetoranTeller()), 0, ',', '.') }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;background:#f8fafc;color:#334155;font-weight:600;"
                        readonly>
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Setoran di atas limit Teller wajib approval Manajer.
                    </span>
                </div>
            </div>

            {{-- ── Kontrol Kas Laci & Batas Minimum ── --}}
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1.25rem;margin-bottom:1.75rem;">
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="teller_max_cash_limit">
                        Batas Maksimum Kas Laci Teller (Rp)
                    </label>
                    <input id="teller_max_cash_limit" type="text" name="teller_max_cash_limit" inputmode="numeric"
                        value="{{ old('teller_max_cash_limit', number_format((float) ($kebijakan->teller_max_cash_limit ?? \App\Support\Setting::tellerMaxCashLimit()), 0, ',', '.')) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Batas maksimum kas fisik yang boleh mengendap di laci teller sebelum wajib disetor ke Brankas Utama.
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="minimum_transaksi_teller">
                        Minimum Transaksi Tunai Teller (Rp)
                    </label>
                    <input id="minimum_transaksi_teller" type="text" name="minimum_transaksi_teller" inputmode="numeric"
                        value="{{ old('minimum_transaksi_teller', number_format((float) ($kebijakan->minimum_transaksi_teller ?? \App\Support\Setting::minimumTransaksiTeller()), 0, ',', '.')) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Nominal terkecil yang diterima untuk transaksi tunai di Teller (setoran maupun injeksi kas).
                    </span>
                </div>
            </div>

            {{-- ── SECTION: Limit Approval Beban & Ambang Kolektibilitas ── --}}
            <h4 style="font-size:0.875rem;font-weight:700;color:#475569;margin:0 0 0.5rem;text-transform:uppercase;letter-spacing:.04em;border-top:1px solid #f1f5f9;padding-top:1.25rem;">
                Limit Approval Beban &amp; Kolektibilitas Pembiayaan
            </h4>
            <p style="font-size:0.78rem;color:#78716c;margin:0 0 1rem;">
                Ambang ini dipakai otomatis oleh modul Beban Operasional (kewajiban approval Manajer) dan analisis NPF
                (klasifikasi Kol-2 s.d. Kol-5) — tanpa mengubah kode program.
            </p>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1.25rem;margin-bottom:1.75rem;">
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="limit_approval_beban">
                        Limit Approval Beban Operasional (Rp)
                    </label>
                    <input id="limit_approval_beban" type="text" name="limit_approval_beban" inputmode="numeric"
                        value="{{ old('limit_approval_beban', number_format((float) ($kebijakan->limit_approval_beban ?? 1000000), 0, ',', '.')) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;"
                        required>
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Pengeluaran <strong>di atas</strong> nominal ini wajib approval Manajer sebelum dijurnal.
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="minimal_pengajuan_pembiayaan">
                        Nominal Minimum Pengajuan Pembiayaan (Rp)
                    </label>
                    <input id="minimal_pengajuan_pembiayaan" type="text" name="minimal_pengajuan_pembiayaan" inputmode="numeric"
                        value="{{ old('minimal_pengajuan_pembiayaan', number_format((float) ($kebijakan->minimal_pengajuan_pembiayaan ?? 1000000), 0, ',', '.')) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Pengajuan pembiayaan baru <strong>di bawah</strong> nominal ini ditolak otomatis.
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="kolektibilitas_kol1_hari">
                        Ambang Kol-1 — Lancar (hari)
                    </label>
                    <input id="kolektibilitas_kol1_hari" type="number" name="kolektibilitas_kol1_hari" min="0" max="3650" step="{{ \App\Support\Setting::langkah('kolektibilitas_kol1_hari') }}"
                        value="{{ old('kolektibilitas_kol1_hari', (int) ($kebijakan->kolektibilitas_kol1_hari ?? 10)) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;"
                        required>
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Tunggakan sampai jumlah hari ini tetap <strong>Lancar (Kol-1)</strong>. Pola KSP syariah: 10 hari.
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="kolektibilitas_kol2_hari">
                        Ambang Kol-2 — Dalam Perhatian Khusus (hari)
                    </label>
                    <input id="kolektibilitas_kol2_hari" type="number" name="kolektibilitas_kol2_hari" min="1" max="3650" step="{{ \App\Support\Setting::langkah('kolektibilitas_kol2_hari') }}"
                        value="{{ old('kolektibilitas_kol2_hari', (int) ($kebijakan->kolektibilitas_kol2_hari ?? 90)) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;"
                        required>
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Tunggakan sampai jumlah hari ini diklasifikasikan <strong>Kol-2</strong>. Pola KSP syariah: 90 hari.
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="kolektibilitas_kol3_hari">
                        Ambang Kol-3 — Kurang Lancar (hari)
                    </label>
                    <input id="kolektibilitas_kol3_hari" type="number" name="kolektibilitas_kol3_hari" min="1" max="3650" step="{{ \App\Support\Setting::langkah('kolektibilitas_kol3_hari') }}"
                        value="{{ old('kolektibilitas_kol3_hari', (int) ($kebijakan->kolektibilitas_kol3_hari ?? 120)) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;"
                        required>
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Harus lebih besar dari ambang Kol-2. Pola KSP syariah: 120 hari.
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="kolektibilitas_kol4_hari">
                        Ambang Kol-4 — Diragukan (hari)
                    </label>
                    <input id="kolektibilitas_kol4_hari" type="number" name="kolektibilitas_kol4_hari" min="1" max="3650" step="{{ \App\Support\Setting::langkah('kolektibilitas_kol4_hari') }}"
                        value="{{ old('kolektibilitas_kol4_hari', (int) ($kebijakan->kolektibilitas_kol4_hari ?? 180)) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;"
                        required>
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Harus lebih besar dari ambang Kol-3. Di atas ini menjadi <strong>Kol-5 (Macet)</strong>.
                    </span>
                </div>
            </div>

            {{-- ── SECTION 2: Konfigurasi Ta'zir (PSAK 109) ── --}}
            <h4 style="font-size:0.875rem;font-weight:700;color:#92400e;margin:0 0 0.5rem;text-transform:uppercase;letter-spacing:.04em;border-top:1px solid #f1f5f9;padding-top:1.25rem;">
                Konfigurasi Ta'zir / Denda Syariah
                <span style="font-weight:400;font-size:0.75rem;color:#a16207;text-transform:none;letter-spacing:0;margin-left:.4rem;">(PSAK 109 — Dana Kebajikan Akun 2-3001)</span>
            </h4>
            <p style="font-size:0.78rem;color:#78716c;margin:0 0 1rem;">
                Seluruh nominal ta'zir dialokasikan ke Dana Kebajikan, bukan ke pendapatan operasional koperasi.
            </p>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1.25rem;margin-bottom:1.25rem;">
                {{-- Mode Ta'zir --}}
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                        Mode Denda
                    </label>
                    <select name="tazir_mode"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;background:#fff;">
                        <option value="FLAT_HARIAN"        {{ old('tazir_mode', $kebijakan->tazir_mode ?? 'FLAT_HARIAN') === 'FLAT_HARIAN'        ? 'selected' : '' }}>
                            Flat Harian (per hari terlambat)
                        </option>
                        <option value="FIXED_PER_KEJADIAN" {{ old('tazir_mode', $kebijakan->tazir_mode ?? 'FLAT_HARIAN') === 'FIXED_PER_KEJADIAN' ? 'selected' : '' }}>
                            Fixed Per Kejadian (sekali jatuh tempo)
                        </option>
                        <option value="DISABLED"           {{ old('tazir_mode', $kebijakan->tazir_mode ?? 'FLAT_HARIAN') === 'DISABLED'           ? 'selected' : '' }}>
                            Nonaktif (tidak ada denda)
                        </option>
                    </select>
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        FLAT_HARIAN: tarif &times; hari terlambat. FIXED: tarif flat per kejadian.
                    </span>
                </div>

                {{-- Tarif Ta'zir --}}
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="tazir_rate">
                        Tarif / Rate (Rp)
                    </label>
                    <input id="tazir_rate" type="number" name="tazir_rate" min="0" step="100"
                        value="{{ old('tazir_rate', (float) ($kebijakan->tazir_rate ?? 2000)) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Jumlah rupiah per hari (FLAT) atau per kejadian (FIXED).
                    </span>
                </div>

                {{-- Tarif Denda Keterlambatan Angsuran Pembiayaan per Hari
                     (saklar tunggal fitur Ta'zir — PSAK 108/109, zero-hardcoding) --}}
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="financing_late_fee_per_day">
                        Denda Keterlambatan per Hari (Rp)
                    </label>
                    <input id="financing_late_fee_per_day" type="text" inputmode="numeric" name="financing_late_fee_per_day"
                        value="{{ old('financing_late_fee_per_day', number_format((float) ($kebijakan->financing_late_fee_per_day ?? \App\Support\Setting::financingLateFeePerDay()), 0, ',', '.')) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Saklar fitur denda: isi 0 untuk menonaktifkan. Seluruh nominal denda
                        wajib masuk Dana Kebajikan (2-3001), tidak boleh menjadi pendapatan koperasi.
                    </span>
                </div>

                {{-- Grace Period --}}
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="tazir_grace_period_days">
                        Grace Period (hari)
                    </label>
                    <input id="tazir_grace_period_days" type="number" name="tazir_grace_period_days" min="0" step="{{ \App\Support\Setting::langkah('tazir_grace_period_days') }}"
                        value="{{ old('tazir_grace_period_days', (int) ($kebijakan->tazir_grace_period_days ?? 0)) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Toleransi hari setelah jatuh tempo sebelum denda mulai dihitung.
                    </span>
                </div>

                {{-- Nominal Default Ta'zir (zero-hardcoding: pengganti ?? 250000) --}}
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="tazir_default_nominal">
                        Nominal Default Ta'zir (Rp)
                    </label>
                    <input id="tazir_default_nominal" type="text" inputmode="numeric" name="tazir_default_nominal"
                        value="{{ old('tazir_default_nominal', number_format((float) ($kebijakan->tazir_default_nominal ?? \App\Support\Setting::nominalTazirDefault()), 0, ',', '.')) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Nominal ta'zir yang dipakai bila Teller tidak mengisi nominal secara eksplisit.
                    </span>
                </div>

                {{-- Metode Default --}}
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                        Metode Pembayaran Default
                    </label>
                    <select name="tazir_default_method"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;background:#fff;">
                        <option value="TUNAI"      {{ old('tazir_default_method', $kebijakan->tazir_default_method ?? 'TUNAI') === 'TUNAI'      ? 'selected' : '' }}>
                            Tunai di Loket
                        </option>
                        <option value="AUTODEBET"  {{ old('tazir_default_method', $kebijakan->tazir_default_method ?? 'TUNAI') === 'AUTODEBET'  ? 'selected' : '' }}>
                            Autodebet Simpanan Sukarela
                        </option>
                    </select>
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Metode yang dipilih otomatis saat form bayar angsuran dibuka.
                    </span>
                </div>
            </div>

            {{-- Preview kalkulasi --}}
            <div id="preview-tazir" style="background:#fef9c3;border:1px solid #fde68a;border-radius:0.5rem;padding:0.75rem 1rem;margin-bottom:1.25rem;font-size:0.82rem;color:#78350f;">
                <strong>Preview:</strong> <span id="preview-text">—</span>
            </div>

            {{-- ── SECTION 3: Parameter PSAK & Regulasi (dinamis) ── --}}
            <h4 style="font-size:0.875rem;font-weight:700;color:#1e40af;margin:0 0 0.5rem;text-transform:uppercase;letter-spacing:.04em;border-top:1px solid #f1f5f9;padding-top:1.25rem;">
                Parameter PSAK &amp; Regulasi
                <span style="font-weight:400;font-size:0.75rem;color:#1d4ed8;text-transform:none;letter-spacing:0;margin-left:.4rem;">(PSAK 101/105/107/109/71 — asumsi, penyisihan &amp; pembulatan)</span>
            </h4>
            <p style="font-size:0.78rem;color:#78716c;margin:0 0 1rem;">
                Parameter berikut dipakai otomatis oleh kalkulator deposito, simulasi pembiayaan, modul AML/PPATK,
                mesin jurnal otomatis, dan perhitungan CKPN (penyisihan kerugian piutang) — sehingga penyesuaian
                standar/regulasi dapat dilakukan tanpa mengubah kode program.
            </p>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.25rem;margin-bottom:1.25rem;">
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="deposito_rate_pa">
                        Asumsi Rate Bagi Hasil Deposito (desimal)
                    </label>
                    <input id="deposito_rate_pa" type="number" name="deposito_rate_pa" min="0" max="1" step="{{ \App\Support\Setting::langkah('deposito_rate_pa') }}"
                        value="{{ old('deposito_rate_pa', (float) ($kebijakan->deposito_rate_pa ?? 0.08)) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Dipakai simulasi deposito (PSAK 105). Contoh <strong>0.08</strong> = 8% p.a.
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="nisbah_default_persen">
                        Nisbah Bagi Hasil Default Anggota (%)
                    </label>
                    <input id="nisbah_default_persen" type="number" name="nisbah_default_persen" min="0" max="100" step="{{ \App\Support\Setting::langkah('nisbah_default_persen') }}"
                        value="{{ old('nisbah_default_persen', (float) ($kebijakan->nisbah_default_persen ?? 60)) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Dipakai bila produk belum menetapkan nisbah. Sisanya menjadi porsi koperasi.
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="margin_default_persen">
                        Margin / Ujrah Default (% p.a.)
                    </label>
                    <input id="margin_default_persen" type="number" name="margin_default_persen" min="0" max="100" step="{{ \App\Support\Setting::langkah('margin_default_persen') }}"
                        value="{{ old('margin_default_persen', (float) ($kebijakan->margin_default_persen ?? 12)) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Dipakai simulasi &amp; pengajuan pembiayaan bila produk belum menetapkan margin.
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="aml_threshold">
                        Ambang Transaksi Mencurigakan / AML (Rp)
                    </label>
                    <input id="aml_threshold" type="text" name="aml_threshold" inputmode="numeric"
                        value="{{ old('aml_threshold', number_format((float) ($kebijakan->aml_threshold ?? 100000000), 0, ',', '.')) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Transaksi <strong>&ge;</strong> nominal ini ditandai wajib lapor (PPATK).
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="pembulatan_desimal">
                        Pembulatan Nominal Jurnal (jumlah desimal)
                    </label>
                    <input id="pembulatan_desimal" type="number" name="pembulatan_desimal" min="0" max="4" step="{{ \App\Support\Setting::langkah('pembulatan_desimal') }}"
                        value="{{ old('pembulatan_desimal', (int) ($kebijakan->pembulatan_desimal ?? 0)) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        <strong>0</strong> = rupiah penuh (disarankan). Maksimal 4 desimal.
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="toleransi_selisih_jurnal">
                        Toleransi Selisih Jurnal (Rp)
                    </label>
                    <input id="toleransi_selisih_jurnal" type="number" name="toleransi_selisih_jurnal" min="0" max="999999.99" step="{{ \App\Support\Setting::langkah('toleransi_selisih_jurnal') }}"
                        value="{{ old('toleransi_selisih_jurnal', (float) ($kebijakan->toleransi_selisih_jurnal ?? 0.01)) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Selisih debet-kredit di bawah nilai ini masih dianggap seimbang.
                    </span>
                </div>
            </div>

            {{-- Tahun Buku Dinamis & Integritas Periode (fleksibilitas temporal) --}}
            <h5 style="font-size:0.8rem;font-weight:700;color:#0f766e;margin:0 0 0.35rem;text-transform:uppercase;letter-spacing:.03em;">
                Tahun Buku &amp; Integritas Periode
                <span style="font-weight:400;font-size:0.73rem;color:#0d9488;text-transform:none;letter-spacing:0;margin-left:.4rem;">(tahun buku dapat non-kalender)</span>
            </h5>
            <p style="font-size:0.78rem;color:#78716c;margin:0 0 1rem;">
                Seluruh perhitungan periode (pendapatan investasi, SHU, lama keanggotaan, laporan keuangan)
                mengikuti parameter di bawah ini — <strong>tanpa</strong> mengasumsikan Januari–Desember.
                Biarkan bernilai bawaan bila koperasi memakai tahun buku kalender.
            </p>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.25rem;margin-bottom:1.25rem;">
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="tahun_buku_mulai_bulan">
                        Bulan Awal Tahun Buku (1-12)
                    </label>
                    <input id="tahun_buku_mulai_bulan" type="number" name="tahun_buku_mulai_bulan" min="1" max="12" step="{{ \App\Support\Setting::langkah('tahun_buku_mulai_bulan') }}"
                        value="{{ old('tahun_buku_mulai_bulan', (int) ($kebijakan->tahun_buku_mulai_bulan ?? 1)) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        <strong>1</strong> = Januari. Contoh <strong>7</strong> = Juli (tahun buku Juli–Juni).
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="jumlah_bulan_tahun_buku">
                        Jumlah Bulan dalam Tahun Buku (1-12)
                    </label>
                    <input id="jumlah_bulan_tahun_buku" type="number" name="jumlah_bulan_tahun_buku" min="1" max="12" step="{{ \App\Support\Setting::langkah('jumlah_bulan_tahun_buku') }}"
                        value="{{ old('jumlah_bulan_tahun_buku', (int) ($kebijakan->jumlah_bulan_tahun_buku ?? 12)) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        <strong>12</strong> = setahun penuh. Turunkan untuk tahun buku pertama yang lebih pendek.
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                        Mode Cut-off Periode
                    </label>
                    <select name="periode_cutoff_mode"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                        @foreach (['fail_open' => 'Fail-Open — izinkan posting bila cut-off tak terurai', 'fail_closed' => 'Fail-Closed — tolak posting bila cut-off tak terurai'] as $nilai => $label)
                            <option value="{{ $nilai }}" {{ old('periode_cutoff_mode', $kebijakan->periode_cutoff_mode ?? 'fail_open') === $nilai ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Menentukan perilaku sistem saat nilai cut-off tutup buku tidak dapat diurai.
                    </span>
                </div>
            </div>

            {{-- Klasifikasi Akad & Partisipasi SHU (PSAK 107/108/109) --}}
            <h5 style="font-size:0.8rem;font-weight:700;color:#7e22ce;margin:0 0 0.35rem;text-transform:uppercase;letter-spacing:.03em;">
                Klasifikasi Akad &amp; Partisipasi SHU
                <span style="font-weight:400;font-size:0.73rem;color:#9333ea;text-transform:none;letter-spacing:0;margin-left:.4rem;">(pisahkan dengan koma)</span>
            </h5>
            <p style="font-size:0.78rem;color:#78716c;margin:0 0 1rem;">
                Menentukan pengakuan imbalan per akad (PSAK 107) dan dasar Jasa Transaksi SHU.
                Ubah di sini bila koperasi memakai akad lain — <strong>tanpa</strong> mengubah kode.
            </p>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.25rem;margin-bottom:1.5rem;">
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="akad_margin">
                        Akad Margin (jual-beli)
                    </label>
                    <input id="akad_margin" type="text" name="akad_margin"
                        value="{{ old('akad_margin', $kebijakan->akad_margin ?? 'Murabahah,Ijarah Muntahia Bittamlik,Istishna,Salam') }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="akad_bagi_hasil">
                        Akad Bagi Hasil (kerja sama usaha)
                    </label>
                    <input id="akad_bagi_hasil" type="text" name="akad_bagi_hasil"
                        value="{{ old('akad_bagi_hasil', $kebijakan->akad_bagi_hasil ?? 'Mudharabah,Musyarakah') }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="akad_ujroh">
                        Akad Ujroh (sewa/jasa)
                    </label>
                    <input id="akad_ujroh" type="text" name="akad_ujroh"
                        value="{{ old('akad_ujroh', $kebijakan->akad_ujroh ?? 'Ijarah') }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="shu_jenis_partisipasi">
                        Jenis Transaksi Partisipasi SHU
                    </label>
                    <input id="shu_jenis_partisipasi" type="text" name="shu_jenis_partisipasi"
                        value="{{ old('shu_jenis_partisipasi', $kebijakan->shu_jenis_partisipasi ?? 'Pembayaran Angsuran,Bayar Angsuran,Bagi Hasil Simpanan Berjangka,Bagi Hasil') }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                </div>
            </div>

            {{-- Persentase CKPN per kolektibilitas --}}
            <h5 style="font-size:0.8rem;font-weight:700;color:#7e22ce;margin:0 0 0.35rem;text-transform:uppercase;letter-spacing:.03em;">
                Persentase CKPN per Kolektibilitas
                <span style="font-weight:400;font-size:0.73rem;color:#9333ea;text-transform:none;letter-spacing:0;margin-left:.4rem;">(harus menaik — akun 1-2100 &amp; beban 5-4001)</span>
            </h5>
            <p style="font-size:0.78rem;color:#78716c;margin:0 0 0.5rem;">
                Penyisihan kerugian piutang pembiayaan (PSAK 101/105/107) dihitung sebagai persentase dari sisa
                piutang sesuai tingkat kolektibilitas. Nilai harus <strong>menaik</strong> seiring memburuknya kualitas.
            </p>
            <p style="font-size:0.74rem;color:#7c3aed;background:#faf5ff;border:1px solid #e9d5ff;border-radius:0.5rem;padding:0.55rem 0.7rem;margin:0 0 1rem;line-height:1.5;">
                <strong>Acuan regulasi (tinjau sebelum mengubah):</strong> persentase penyisihan wajib mengikuti
                ketentuan yang berlaku bagi badan usaha Anda — bukan nilai bawaan sistem.
                <br>&bull; <strong>Koperasi Simpan Pinjam syariah</strong> (PMK 74/2024 Lampiran): 0,5% lancar &middot;
                10% kurang lancar &middot; 50% diragukan &middot; 100% macet (tanpa golongan perhatian khusus).
                <br>&bull; <strong>BPR Syariah</strong> (POJK 24/2024): 0,5% lancar (umum) &middot; 3% dalam perhatian
                khusus &middot; 10% kurang lancar &middot; 50% diragukan &middot; 100% macet (khusus).
                <br>&bull; <strong>Bank Umum Syariah</strong> (POJK 2/2022 &amp; PMK 74/2024): 1% lancar &middot; 5%
                dalam perhatian khusus &middot; 15% kurang lancar &middot; 50% diragukan &middot; 100% macet.
                <br>Nilai CKPN akuntansi (SAK) dan batas fiskal dapat berbeda; konsultasikan dengan akuntan/DPS.
            </p>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1.25rem;margin-bottom:1.5rem;">
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="ckpn_persen_kol1">
                        Kol-1 — Lancar (%)
                    </label>
                    <input id="ckpn_persen_kol1" type="number" name="ckpn_persen_kol1" min="0" max="100" step="{{ \App\Support\Setting::langkah('ckpn_persen_kol1') }}"
                        value="{{ old('ckpn_persen_kol1', (float) ($kebijakan->ckpn_persen_kol1 ?? 0.5)) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                </div>
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="ckpn_persen_kol2">
                        Kol-2 — Perhatian Khusus (%)
                    </label>
                    <input id="ckpn_persen_kol2" type="number" name="ckpn_persen_kol2" min="0" max="100" step="{{ \App\Support\Setting::langkah('ckpn_persen_kol2') }}"
                        value="{{ old('ckpn_persen_kol2', (float) ($kebijakan->ckpn_persen_kol2 ?? 10)) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                </div>
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="ckpn_persen_kol3">
                        Kol-3 — Kurang Lancar (%)
                    </label>
                    <input id="ckpn_persen_kol3" type="number" name="ckpn_persen_kol3" min="0" max="100" step="{{ \App\Support\Setting::langkah('ckpn_persen_kol3') }}"
                        value="{{ old('ckpn_persen_kol3', (float) ($kebijakan->ckpn_persen_kol3 ?? 50)) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                </div>
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="ckpn_persen_kol4">
                        Kol-4 — Diragukan (%)
                    </label>
                    <input id="ckpn_persen_kol4" type="number" name="ckpn_persen_kol4" min="0" max="100" step="{{ \App\Support\Setting::langkah('ckpn_persen_kol4') }}"
                        value="{{ old('ckpn_persen_kol4', (float) ($kebijakan->ckpn_persen_kol4 ?? 75)) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                </div>
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="ckpn_persen_kol5">
                        Kol-5 — Macet (%)
                    </label>
                    <input id="ckpn_persen_kol5" type="number" name="ckpn_persen_kol5" min="0" max="100" step="{{ \App\Support\Setting::langkah('ckpn_persen_kol5') }}"
                        value="{{ old('ckpn_persen_kol5', (float) ($kebijakan->ckpn_persen_kol5 ?? 100)) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                </div>
            </div>

            {{-- ── Parameter Penutupan Keanggotaan (Member Exit) ─────────────
                 Dua parameter ini dibaca ExitKeanggotaanService saat menghitung
                 pencairan: biaya administrasi penutupan CIF dan ambang total net
                 yang mewajibkan persetujuan Manajer. 0 = biaya nihil / approval
                 nonaktif (P4 Default Aman). --}}
            <h5 style="font-size:0.8rem;font-weight:700;color:#0f766e;margin:0 0 0.35rem;text-transform:uppercase;letter-spacing:.04em;">
                Penutupan Keanggotaan (Member Exit)
            </h5>
            <p style="font-size:0.78rem;color:#78716c;margin:0 0 0.75rem;">
                Dipakai saat anggota mengundurkan diri dan seluruh simpanannya dicairkan.
            </p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.25rem;margin-bottom:1.25rem;">
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="closure_admin_fee">
                        Biaya Administrasi Penutupan (Rp)
                    </label>
                    <input id="closure_admin_fee" type="number" name="closure_admin_fee" min="0" step="{{ \App\Support\Setting::langkah('closure_admin_fee') }}"
                        value="{{ old('closure_admin_fee', (float) ($kebijakan->closure_admin_fee ?? 0)) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Dipotong dari dana exit anggota dan dibukukan sebagai Pendapatan Administrasi.
                        <strong>0</strong> = tidak ada biaya.
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="high_value_withdrawal_limit">
                        Ambang Persetujuan Pencairan Exit (Rp)
                    </label>
                    <input id="high_value_withdrawal_limit" type="number" name="high_value_withdrawal_limit" min="0" step="{{ \App\Support\Setting::langkah('high_value_withdrawal_limit') }}"
                        value="{{ old('high_value_withdrawal_limit', (float) ($kebijakan->high_value_withdrawal_limit ?? 0)) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Total net di atas ambang ini wajib disetujui Manajer sebelum dana dibayarkan.
                        <strong>0</strong> = approval nonaktif.
                    </span>
                </div>
            </div>

            {{-- Akun penampung ta'zir (opsional) --}}
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1.25rem;margin-bottom:1.25rem;">
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                        Akun Penampung Ta'zir (opsional)
                    </label>
                    <select name="akun_tazir_id"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;background:#fff;">
                        <option value="">— Ikuti Dana Kebajikan (2-3001) —</option>
                        @foreach(($akunCoa ?? collect()) as $akun)
                            <option value="{{ $akun->id }}"
                                {{ (string) old('akun_tazir_id', $kebijakan->akun_tazir_id ?? '') === (string) $akun->id ? 'selected' : '' }}>
                                {{ $akun->kode_akun }} — {{ $akun->nama_akun }}
                            </option>
                        @endforeach
                    </select>
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Biarkan kosong agar ta'zir tetap dibukukan ke Dana Kebajikan (PSAK 109).
                    </span>
                </div>
            </div>

            {{-- ── Akun COA Dana Zakat & Infak/Sedekah (PSAK 109 / temuan Z-05) ──
                 Dana zakat dan infak/sedekah adalah dana TITIPAN (amanah) yang
                 wajib disajikan terpisah. Bila akun dipindahkan di sini, buku besar
                 modul zakat DAN penyajian Dana Investasi Terikat (DIT) menunjuk
                 akun yang sama. Dibiarkan kosong = ikuti registri standar. --}}
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1.25rem;margin-bottom:1.25rem;">
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                        Akun COA Dana Zakat (PSAK 109)
                    </label>
                    <select name="account_dana_zakat_id"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;background:#fff;">
                        <option value="">— Ikuti standar 2-3002 —</option>
                        @foreach(($akunCoa ?? collect()) as $akun)
                            <option value="{{ $akun->id }}"
                                {{ (string) old('account_dana_zakat_id', $kebijakan->account_dana_zakat_id ?? '') === (string) $akun->id ? 'selected' : '' }}>
                                {{ $akun->kode_akun }} — {{ $akun->nama_akun }}
                            </option>
                        @endforeach
                    </select>
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Akun titipan dana zakat. Dipakai bersama oleh pencatatan penerimaan/penyaluran zakat
                        dan penyajian DIT. Kosong = <strong>2-3002</strong>.
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                        Akun COA Dana Infak &amp; Sedekah (PSAK 109)
                    </label>
                    <select name="account_dana_infak_id"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;background:#fff;">
                        <option value="">— Ikuti standar 2-3004 —</option>
                        @foreach(($akunCoa ?? collect()) as $akun)
                            <option value="{{ $akun->id }}"
                                {{ (string) old('account_dana_infak_id', $kebijakan->account_dana_infak_id ?? '') === (string) $akun->id ? 'selected' : '' }}>
                                {{ $akun->kode_akun }} — {{ $akun->nama_akun }}
                            </option>
                        @endforeach
                    </select>
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Akun titipan dana infak &amp; sedekah. Kosong = <strong>2-3004</strong>.
                    </span>
                </div>

                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                        Akun COA Bank Operasional Koperasi
                    </label>
                    <select name="account_bank_koperasi_id"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;background:#fff;">
                        <option value="">— Ikuti standar 1-1002 —</option>
                        @foreach(($akunCoa ?? collect()) as $akun)
                            <option value="{{ $akun->id }}"
                                {{ (string) old('account_bank_koperasi_id', $kebijakan->account_bank_koperasi_id ?? '') === (string) $akun->id ? 'selected' : '' }}>
                                {{ $akun->kode_akun }} — {{ $akun->nama_akun }}
                            </option>
                        @endforeach
                    </select>
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Akun bank tempat dana operasional koperasi. Kosong = <strong>1-1002</strong>.
                    </span>
                </div>

                {{-- Porsi/hak amil atas dana zakat (Fatwa DSN-MUI 08 / temuan Z-08).
                     Amil termasuk 8 asnaf dan berhak atas bagian dana zakat,
                     umumnya maksimum 1/8 (12,5%). Dipakai merekap hak amil pada
                     laporan; penyaluran ke amil tetap dicatat manual ber-asnaf Amil. --}}
                <div>
                    <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="zakat_porsi_amil_persen">
                        Porsi Hak Amil atas Dana Zakat (%)
                    </label>
                    <input id="zakat_porsi_amil_persen" type="number" name="zakat_porsi_amil_persen" step="{{ \App\Support\Setting::langkah('zakat_porsi_amil_persen') }}" min="0" max="100"
                        value="{{ old('zakat_porsi_amil_persen', $kebijakan->zakat_porsi_amil_persen ?? 12.50) }}"
                        style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;background:#fff;">
                    <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                        Fatwa DSN-MUI 08: batas umum maksimum <strong>1/8 (12,5%)</strong>. Nilai 0 = rekap hak amil dinolkan.
                    </span>
                </div>
            </div>

            {{-- ── Parameter Hisab Zakat (temuan audit Z-03) ──────────────────
                 Kadar, nishab, harga emas, batas minimum penyaluran, dan syarat
                 haul. Sebelumnya kolom-kolom ini ada di basis data dan dibaca
                 Setting, tetapi TIDAK dapat diubah lewat UI sehingga hanya bisa
                 diubah via akses DB langsung. Kini dibuka agar Pengurus dapat
                 menyesuaikan hisab tanpa mengubah kode (zero-hardcode). --}}
            <div style="margin-top:1.25rem;padding-top:1.25rem;border-top:1px dashed #e2e8f0;">
                <div style="font-size:0.8rem;font-weight:700;color:#334155;margin-bottom:0.85rem;">
                    Parameter Hisab Zakat
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.25rem;">
                    <div>
                        <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="zakat_rate">
                            Kadar Zakat Maal (%)
                        </label>
                        <input id="zakat_rate" type="number" name="zakat_rate" step="{{ \App\Support\Setting::langkah('zakat_rate') }}" min="0" max="100"
                            value="{{ old('zakat_rate', $kebijakan->zakat_rate ?? 2.50) }}"
                            style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;background:#fff;">
                        <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                            Standar syariah <strong>2,5%</strong> (1/40).
                        </span>
                    </div>

                    <div>
                        <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="zakat_nisab_gram">
                            Nishab Zakat (gram emas)
                        </label>
                        <input id="zakat_nisab_gram" type="number" name="zakat_nisab_gram" step="{{ \App\Support\Setting::langkah('zakat_nisab_gram') }}" min="0"
                            value="{{ old('zakat_nisab_gram', $kebijakan->zakat_nisab_gram ?? 85.00) }}"
                            style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;background:#fff;">
                        <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                            Standar syariah <strong>85 gram</strong> emas.
                        </span>
                    </div>

                    <div>
                        <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="harga_emas_per_gram">
                            Harga Emas per Gram (Rp)
                        </label>
                        <input id="harga_emas_per_gram" type="number" name="harga_emas_per_gram" step="{{ \App\Support\Setting::langkah('harga_emas_per_gram') }}" min="0"
                            value="{{ old('harga_emas_per_gram', $kebijakan->harga_emas_per_gram ?? 1500000.00) }}"
                            style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;background:#fff;">
                        <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                            Dipakai menghitung nishab nominal (gram × harga).
                        </span>
                    </div>

                    <div>
                        <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="zakat_minimal_tarik">
                            Minimum Penyaluran Zakat (Rp)
                        </label>
                        <input id="zakat_minimal_tarik" type="number" name="zakat_minimal_tarik" step="{{ \App\Support\Setting::langkah('zakat_minimal_tarik') }}" min="0"
                            value="{{ old('zakat_minimal_tarik', $kebijakan->zakat_minimal_tarik ?? 10000.00) }}"
                            style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;background:#fff;">
                        <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                            Batas bawah per transaksi penyaluran. 0 = tanpa batas.
                        </span>
                    </div>

                    <div>
                        <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="zakat_ambang_persetujuan">
                            Ambang Persetujuan Penyaluran (Rp)
                        </label>
                        <input id="zakat_ambang_persetujuan" type="number" name="zakat_ambang_persetujuan" step="{{ \App\Support\Setting::langkah('zakat_ambang_persetujuan') }}" min="0"
                            value="{{ old('zakat_ambang_persetujuan', $kebijakan->zakat_ambang_persetujuan ?? 0) }}"
                            style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;background:#fff;">
                        <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                            Penyaluran ≥ nominal ini wajib disetujui pengurus/manajer LAIN (dual control) sebelum jurnal diposting. 0 = nonaktif.
                        </span>
                    </div>

                    <div>
                        <label style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="zakat_haul_hari">
                            Panjang Haul (hari)
                        </label>
                        <input id="zakat_haul_hari" type="number" name="zakat_haul_hari" step="{{ \App\Support\Setting::langkah('zakat_haul_hari') }}" min="1" max="1000"
                            value="{{ old('zakat_haul_hari', $kebijakan->zakat_haul_hari ?? 354) }}"
                            style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;background:#fff;">
                        <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                            Standar <strong>354 hari</strong> (satu tahun hijriah).
                        </span>
                    </div>

                    <div style="display:flex;align-items:flex-start;gap:0.6rem;padding-top:1.6rem;">
                        {{-- Hidden 0 memastikan nilai selalu terkirim saat kotak
                             tidak dicentang, agar dapat menonaktifkan saklar. --}}
                        <input type="hidden" name="zakat_haul_aktif" value="0">
                        <input type="checkbox" name="zakat_haul_aktif" value="1" id="zakat_haul_aktif"
                            {{ old('zakat_haul_aktif', $kebijakan->zakat_haul_aktif ?? false) ? 'checked' : '' }}
                            style="margin-top:0.15rem;width:1.05rem;height:1.05rem;">
                        <label for="zakat_haul_aktif" style="font-size:0.825rem;font-weight:600;color:#475569;cursor:pointer;">
                            Wajibkan syarat Haul
                            <span style="display:block;font-weight:400;font-size:0.75rem;color:#64748b;margin-top:0.2rem;">
                                Bila aktif, harta yang mencapai nishab baru wajib zakat setelah kepemilikan penuh satu haul. Default nonaktif.
                            </span>
                        </label>
                    </div>
                </div>
            </div>

            <div style="margin-top:0.5rem;padding-top:1rem;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-end;">
                <button type="submit" style="padding:0.6rem 1.25rem;background:#059669;color:#fff;border:none;border-radius:0.5rem;font-size:0.825rem;font-weight:600;cursor:pointer;">
                    Simpan Semua Perubahan Kebijakan
                </button>
            </div>
        </form>
    </div>
    @endif

    {{-- ── CARD PEMETAAN AKAD → AKUN COA (FLEKSIBILITAS PSAK) ──────────────
         Akad syariah sebelumnya dipatok pada konstanta PHP, sehingga akad baru
         menuntut perubahan kode. Panel ini memindahkan pemetaan ke basis data:
         entri ber-akad "— Akad Baru —" membuat akad baru tanpa deploy ulang,
         dan setiap akad dapat diarahkan ke akun CoA pilihan pengurus.
         Kosongkan akun = ikuti pemetaan standar (kebijakan/registri CoA). --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.05);margin-top:1.25rem;">
        <h3 style="font-size:1rem;font-weight:700;color:#1e293b;margin-top:0;margin-bottom:0.35rem;border-bottom:1px solid #f1f5f9;padding-bottom:0.75rem;">
            Pemetaan Akad Syariah ke Akun CoA
        </h3>
        <p style="font-size:0.78rem;color:#64748b;margin:0 0 1.25rem;">
            Tentukan akun piutang/penyertaan, pendapatan, dan margin ditangguhkan untuk setiap akad
            (PSAK 105/106/107/108/109). Biarkan akun <strong>kosong</strong> agar sistem mengikuti pemetaan
            standar. Kolom <strong>Produk</strong> memungkinkan pemetaan khusus per produk — pilih
            <strong>“— Semua Produk —”</strong> untuk berlaku umum, atau produk tertentu untuk menimpanya.
            Tambahkan akad baru dengan memilih <strong>“— Akad Baru —”</strong> pada kolom Akad —
            tanpa mengubah kode sumber.
        </p>

        @if(session('success_pemetaan'))
            <div role="status" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:0.5rem;padding:0.75rem 1rem;font-size:0.82rem;margin-bottom:1rem;">
                {{ session('success_pemetaan') }}
            </div>
        @endif
        @if(session('error_pemetaan'))
            <div role="alert" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:0.5rem;padding:0.75rem 1rem;font-size:0.82rem;margin-bottom:1rem;">
                {{ session('error_pemetaan') }}
            </div>
        @endif

        @if($errors->has('pemetaan_akad'))
            <div role="alert" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:0.5rem;padding:0.75rem 1rem;font-size:0.82rem;margin-bottom:1rem;">
                {{ $errors->first('pemetaan_akad') }}
            </div>
        @endif

        @if(\App\Support\HierarkiRole::dalamKelompok(auth()->user()->role, 'pengurus'))
        <form method="POST" action="{{ route('pengurus.kebijakan.pemetaan-akad') }}">
            @csrf
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
                    <caption class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">
                        Pemetaan akad syariah ke akun CoA
                    </caption>
                    <thead>
                        <tr style="background:#f8fafc;text-align:left;">
                            <th scope="col" style="padding:0.6rem;border-bottom:1px solid #e2e8f0;">Produk</th>
                            <th scope="col" style="padding:0.6rem;border-bottom:1px solid #e2e8f0;">Akad</th>
                            <th scope="col" style="padding:0.6rem;border-bottom:1px solid #e2e8f0;">Akun Piutang/Penyertaan</th>
                            <th scope="col" style="padding:0.6rem;border-bottom:1px solid #e2e8f0;">Akun Pendapatan</th>
                            <th scope="col" style="padding:0.6rem;border-bottom:1px solid #e2e8f0;">Akun Margin Ditangguhkan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(($pemetaanAkad ?? collect()) as $i => $baris)
                            @php
                                $akadBaris = (string) old("pemetaan_akad.$i.akad_pembiayaan", $baris->akad_pembiayaan);
                                $akadBaru  = $akadBaris !== '' && ! in_array($akadBaris, ($akadTersedia ?? []), true);
                                $produkBaris = (string) old("pemetaan_akad.$i.kode_produk", $baris->kode_produk);
                                $produkKhusus = $produkBaris !== '' && $produkBaris !== \App\Models\PemetaanAkadCoa::SEMUA_PRODUK;
                            @endphp
                            <tr>
                                <td style="padding:0.6rem;border-bottom:1px solid #f1f5f9;vertical-align:top;">
                                    <select name="pemetaan_akad[{{ $i }}][kode_produk]"
                                        aria-label="Produk baris {{ $i + 1 }}"
                                        style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.8rem;background:#fff;">
                                        <option value="*" {{ ! $produkKhusus ? 'selected' : '' }}>— Semua Produk —</option>
                                        @foreach(($produkPemetaan ?? collect()) as $produk)
                                            <option value="{{ $produk->kode_produk }}"
                                                {{ $produkBaris === $produk->kode_produk ? 'selected' : '' }}>
                                                {{ $produk->kode_produk }} — {{ $produk->nama_produk }}
                                            </option>
                                        @endforeach
                                        @if($produkKhusus && ! ($produkPemetaan ?? collect())->contains('kode_produk', $produkBaris))
                                            <option value="{{ $produkBaris }}" selected>{{ $produkBaris }} (tidak lagi terdaftar)</option>
                                        @endif
                                    </select>
                                </td>
                                <td style="padding:0.6rem;border-bottom:1px solid #f1f5f9;vertical-align:top;">
                                    <input type="hidden" name="pemetaan_akad[{{ $i }}][id]" value="{{ $baris->id }}">
                                    <select name="pemetaan_akad[{{ $i }}][akad_pembiayaan]"
                                        aria-label="Akad syariah baris {{ $i + 1 }}"
                                        style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.8rem;background:#fff;">
                                        <option value="">— Pilih akad —</option>
                                        @foreach(($akadTersedia ?? []) as $akadPilihan)
                                            <option value="{{ $akadPilihan }}"
                                                {{ $akadBaris === $akadPilihan ? 'selected' : '' }}>
                                                {{ $akadPilihan }}
                                            </option>
                                        @endforeach
                                        <option value="__BARU__" {{ $akadBaru ? 'selected' : '' }}>— Akad Baru —</option>
                                    </select>
                                    <input type="text" name="pemetaan_akad[{{ $i }}][akad_baru]"
                                        value="{{ old("pemetaan_akad.$i.akad_baru") }}"
                                        placeholder="Nama akad baru…"
                                        aria-label="Nama akad baru baris {{ $i + 1 }}"
                                        style="margin-top:0.35rem;width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.8rem;">
                                </td>
                                <td style="padding:0.6rem;border-bottom:1px solid #f1f5f9;vertical-align:top;">
                                    <select name="pemetaan_akad[{{ $i }}][account_piutang_id]"
                                        aria-label="Akun piutang untuk akad baris {{ $i + 1 }}"
                                        style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.8rem;background:#fff;">
                                        <option value="">— Ikuti standar —</option>
                                        @foreach(($akunCoa ?? collect()) as $akun)
                                            <option value="{{ $akun->id }}"
                                                {{ (string) old("pemetaan_akad.$i.account_piutang_id", $baris->account_piutang_id ?? '') === (string) $akun->id ? 'selected' : '' }}>
                                                {{ $akun->kode_akun }} — {{ $akun->nama_akun }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td style="padding:0.6rem;border-bottom:1px solid #f1f5f9;vertical-align:top;">
                                    <select name="pemetaan_akad[{{ $i }}][account_pendapatan_id]"
                                        aria-label="Akun pendapatan untuk akad baris {{ $i + 1 }}"
                                        style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.8rem;background:#fff;">
                                        <option value="">— Ikuti standar —</option>
                                        @foreach(($akunCoa ?? collect()) as $akun)
                                            <option value="{{ $akun->id }}"
                                                {{ (string) old("pemetaan_akad.$i.account_pendapatan_id", $baris->account_pendapatan_id ?? '') === (string) $akun->id ? 'selected' : '' }}>
                                                {{ $akun->kode_akun }} — {{ $akun->nama_akun }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td style="padding:0.6rem;border-bottom:1px solid #f1f5f9;vertical-align:top;">
                                    <select name="pemetaan_akad[{{ $i }}][account_margin_ditangguhkan_id]"
                                        aria-label="Akun margin ditangguhkan untuk akad baris {{ $i + 1 }}"
                                        style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.8rem;background:#fff;">
                                        <option value="">— Ikuti standar —</option>
                                        @foreach(($akunCoa ?? collect()) as $akun)
                                            <option value="{{ $akun->id }}"
                                                {{ (string) old("pemetaan_akad.$i.account_margin_ditangguhkan_id", $baris->account_margin_ditangguhkan_id ?? '') === (string) $akun->id ? 'selected' : '' }}>
                                                {{ $akun->kode_akun }} — {{ $akun->nama_akun }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @endforeach

                        {{-- BARIS KOSONG: untuk MENAMBAH pemetaan baru, termasuk
                             pemetaan khusus per produk. Baris tanpa akad diabaikan
                             oleh controller. --}}
                        @php $iKosong = count($pemetaanAkad ?? []); @endphp
                        <tr style="background:#fcfdff;">
                            <td style="padding:0.6rem;border-bottom:1px solid #f1f5f9;vertical-align:top;">
                                <select name="pemetaan_akad[{{ $iKosong }}][kode_produk]"
                                    aria-label="Produk baris baru"
                                    style="width:100%;padding:0.5rem 0.65rem;border:1px dashed #cbd5e1;border-radius:0.5rem;font-size:0.8rem;background:#fff;">
                                    <option value="*" selected>— Semua Produk —</option>
                                    @foreach(($produkPemetaan ?? collect()) as $produk)
                                        <option value="{{ $produk->kode_produk }}">
                                            {{ $produk->kode_produk }} — {{ $produk->nama_produk }}
                                        </option>
                                    @endforeach
                                </select>
                            </td>
                            <td style="padding:0.6rem;border-bottom:1px solid #f1f5f9;vertical-align:top;">
                                <select name="pemetaan_akad[{{ $iKosong }}][akad_pembiayaan]"
                                    aria-label="Akad baris baru"
                                    style="width:100%;padding:0.5rem 0.65rem;border:1px dashed #cbd5e1;border-radius:0.5rem;font-size:0.8rem;background:#fff;">
                                    <option value="" selected>— Tambah pemetaan… —</option>
                                    @foreach(($akadTersedia ?? []) as $akadPilihan)
                                        <option value="{{ $akadPilihan }}">{{ $akadPilihan }}</option>
                                    @endforeach
                                    <option value="__BARU__">— Akad Baru —</option>
                                </select>
                                <input type="text" name="pemetaan_akad[{{ $iKosong }}][akad_baru]"
                                    value=""
                                    placeholder="Nama akad baru…"
                                    aria-label="Nama akad baru baris baru"
                                    style="margin-top:0.35rem;width:100%;padding:0.5rem 0.65rem;border:1px dashed #cbd5e1;border-radius:0.5rem;font-size:0.8rem;">
                            </td>
                            <td style="padding:0.6rem;border-bottom:1px solid #f1f5f9;vertical-align:top;">
                                <select name="pemetaan_akad[{{ $iKosong }}][account_piutang_id]"
                                    aria-label="Akun piutang baris baru"
                                    style="width:100%;padding:0.5rem 0.65rem;border:1px dashed #cbd5e1;border-radius:0.5rem;font-size:0.8rem;background:#fff;">
                                    <option value="">— Ikuti standar —</option>
                                    @foreach(($akunCoa ?? collect()) as $akun)
                                        <option value="{{ $akun->id }}">{{ $akun->kode_akun }} — {{ $akun->nama_akun }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td style="padding:0.6rem;border-bottom:1px solid #f1f5f9;vertical-align:top;">
                                <select name="pemetaan_akad[{{ $iKosong }}][account_pendapatan_id]"
                                    aria-label="Akun pendapatan baris baru"
                                    style="width:100%;padding:0.5rem 0.65rem;border:1px dashed #cbd5e1;border-radius:0.5rem;font-size:0.8rem;background:#fff;">
                                    <option value="">— Ikuti standar —</option>
                                    @foreach(($akunCoa ?? collect()) as $akun)
                                        <option value="{{ $akun->id }}">{{ $akun->kode_akun }} — {{ $akun->nama_akun }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td style="padding:0.6rem;border-bottom:1px solid #f1f5f9;vertical-align:top;">
                                <select name="pemetaan_akad[{{ $iKosong }}][account_margin_ditangguhkan_id]"
                                    aria-label="Akun margin ditangguhkan baris baru"
                                    style="width:100%;padding:0.5rem 0.65rem;border:1px dashed #cbd5e1;border-radius:0.5rem;font-size:0.8rem;background:#fff;">
                                    <option value="">— Ikuti standar —</option>
                                    @foreach(($akunCoa ?? collect()) as $akun)
                                        <option value="{{ $akun->id }}">{{ $akun->kode_akun }} — {{ $akun->nama_akun }}</option>
                                    @endforeach
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-end;">
                <button type="submit" style="padding:0.6rem 1.25rem;background:#0369a1;color:#fff;border:none;border-radius:0.5rem;font-size:0.825rem;font-weight:600;cursor:pointer;">
                    Simpan Pemetaan Akad
                </button>
            </div>
        </form>
        @endif
    </div>

    {{-- ── CARD RIWAYAT PERUBAHAN (AUDIT TRAIL) ────────── --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <h3 style="font-size:1rem;font-weight:700;color:#1e293b;margin-top:0;margin-bottom:0.35rem;border-bottom:1px solid #f1f5f9;padding-bottom:0.75rem;">
            Riwayat Perubahan Konfigurasi
        </h3>
        <p style="font-size:0.78rem;color:#64748b;margin:0 0 1.25rem;">
            Jejak audit setiap perubahan parameter — siapa mengubah, kapan, dan nilai sebelum/sesudah.
        </p>

        @if(($riwayat ?? collect())->isEmpty())
            <div style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:0.5rem;padding:1.25rem;text-align:center;font-size:0.82rem;color:#64748b;">
                Belum ada riwayat perubahan konfigurasi. Setiap penyimpanan parameter akan tercatat otomatis di sini.
            </div>
        @else
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
                    <thead>
                        <tr style="background:#f8fafc;text-align:left;">
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Waktu</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;">Parameter</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;">Nilai Lama</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;">Nilai Baru</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;">Diubah Oleh</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($riwayat as $log)
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:0.6rem 0.75rem;color:#64748b;white-space:nowrap;">
                                    {{ \Carbon\Carbon::parse($log->created_at)->locale('id')->translatedFormat('d M Y, H:i') }}
                                </td>
                                <td style="padding:0.6rem 0.75rem;font-weight:600;color:#1e293b;">
                                    {{ \App\Models\KebijakanKoperasi::LABEL_KOLOM[$log->kolom] ?? $log->kolom }}
                                    <span style="display:inline-block;font-size:0.7rem;font-weight:700;padding:0.1rem 0.35rem;border-radius:0.25rem;margin-left:0.35rem;{{ $log->aksi === 'CREATE' ? 'background:#dbeafe;color:#1d4ed8;' : 'background:#fef3c7;color:#92400e;' }}">
                                        {{ $log->aksi }}
                                    </span>
                                </td>
                                <td style="padding:0.6rem 0.75rem;color:#94a3b8;font-family:monospace;">
                                    {{ $log->nilai_lama ?? '—' }}
                                </td>
                                <td style="padding:0.6rem 0.75rem;color:#065f46;font-weight:600;font-family:monospace;">
                                    {{ $log->nilai_baru ?? '—' }}
                                </td>
                                <td style="padding:0.6rem 0.75rem;color:#475569;">
                                    {{ $log->nama_user ?? 'Sistem' }}
                                    @if($log->role_user)
                                        <span style="font-size:0.72rem;color:#94a3b8;">({{ strtoupper($log->role_user) }})</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const inputManajer  = document.getElementById('limit_manajer');
    const inputPengurus = document.getElementById('limit_pembiayaan_manajer');
    const previewText   = document.getElementById('preview-text');
    const modeEl        = document.querySelector('[name=tazir_mode]');
    const rateEl        = document.querySelector('[name=tazir_rate]');
    const lateFeeEl     = document.querySelector('[name=financing_late_fee_per_day]');
    const graceEl       = document.querySelector('[name=tazir_grace_period_days]');

    function formatRupiah(val) {
        let num = val.toString().replace(/[^0-9]/g, '');
        if (!num) return '0';
        return new Intl.NumberFormat('id-ID').format(num);
    }

    if (inputManajer && inputPengurus) {
        inputManajer.addEventListener('input', function() {
            let cleanVal = this.value.replace(/[^0-9]/g, '');
            inputPengurus.value = '> Rp ' + formatRupiah(cleanVal);
        });
    }

    // Limit Otorisasi Teller → kotak ambang approval Manajer (live, seperti Limit Manajer).
    const pasanganTeller = [
        ['limit_penarikan_teller', 'ambang_penarikan_teller'],
        ['limit_setoran_teller',   'ambang_setoran_teller'],
    ];
    pasanganTeller.forEach(function([idLimit, idAmbang]) {
        const elLimit  = document.getElementById(idLimit);
        const elAmbang = document.getElementById(idAmbang);
        if (!elLimit || !elAmbang) return;
        elLimit.addEventListener('input', function() {
            const bersih = this.value.replace(/[^0-9]/g, '');
            elAmbang.value = '> Rp ' + formatRupiah(bersih);
        });
    });

    function updatePreview() {
        if (!modeEl || !rateEl || !graceEl || !previewText) return;
        const mode  = modeEl.value;
        const rate  = parseFloat(rateEl.value) || 0;
        // Saklar SESUNGGUHNYA fitur denda adalah `financing_late_fee_per_day`
        // (lihat TazirService::fiturAktif()). `tazir_rate` hanya dipakai bila
        // saklar bernilai > 0, sehingga preview wajib membaca keduanya agar
        // tidak memberi gambaran denda yang tidak akan pernah ditagihkan.
        const lateFee = lateFeeEl
            ? (parseFloat(lateFeeEl.value.replace(/[^0-9]/g, '')) || 0)
            : 0;
        const grace = parseInt(graceEl.value)  || 0;
        const contohHari = 5;

        if (mode === 'DISABLED' || lateFee <= 0) {
            previewText.textContent = mode === 'DISABLED'
                ? 'Mode NONAKTIF — tidak ada ta\'zir yang dikenakan.'
                : 'Denda keterlambatan NONAKTIF (Denda Keterlambatan per Hari = 0) — tidak ada ta\'zir yang dikenakan.';
            return;
        }
        const efektif = Math.max(0, contohHari - grace);
        let nominal = 0;
        if (mode === 'FLAT_HARIAN')        nominal = efektif * rate;
        if (mode === 'FIXED_PER_KEJADIAN') nominal = rate;

        const modeLabel = mode === 'FLAT_HARIAN' ? 'Flat Harian' : 'Fixed Per Kejadian';
        previewText.innerHTML =
            `Contoh keterlambatan <strong>${contohHari} hari</strong> (grace ${grace} hari → efektif ${efektif} hari) | ` +
            `Mode: <strong>${modeLabel}</strong> | Tarif: Rp ${formatRupiah(rate)} | ` +
            `<strong>Nominal Ta'zir = Rp ${formatRupiah(nominal)}</strong>`;
    }

    if (modeEl)     modeEl.addEventListener('change', updatePreview);
    if (rateEl)     rateEl.addEventListener('input',  updatePreview);
    if (lateFeeEl)  lateFeeEl.addEventListener('input', updatePreview);
    if (graceEl)    graceEl.addEventListener('input', updatePreview);
    updatePreview();

    // Preview total alokasi SHU — memberi peringatan dini bila total ≠ 100%.
    const inputShu = document.querySelectorAll('.input-shu');
    const shuText  = document.getElementById('preview-shu-text');
    const shuBox   = document.getElementById('preview-shu');

    function updateShuTotal() {
        if (!shuText || !inputShu.length) return;
        let total = 0;
        inputShu.forEach(el => { total += parseFloat(el.value) || 0; });
        total = Math.round(total * 100) / 100;

        const pas = Math.abs(total - 100) < 0.01;
        shuText.textContent = total.toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + '%'
            + (pas ? ' — sesuai (100%).' : ' — belum tepat 100%, periksa kembali sebelum menyimpan.');

        if (shuBox) {
            shuBox.style.background = pas ? '#f0fdf4' : '#fef2f2';
            shuBox.style.borderColor = pas ? '#bbf7d0' : '#fecaca';
            shuBox.style.color = pas ? '#065f46' : '#991b1b';
        }
    }

    inputShu.forEach(el => el.addEventListener('input', updateShuTotal));
    updateShuTotal();
});
</script>
@endsection
