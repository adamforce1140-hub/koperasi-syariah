@extends('layouts.app')

@section('title', 'Pencairan Pembiayaan — ' . config('app.name'))
@section('page-title', 'Pencairan Pembiayaan')

@section('content')

@php
    // View ini dipakai bersama beberapa controller: sebagian mengirim paginator
    // ($pembiayaans), sebagian mengirim collection ($pembiayaan). Deteksi bentuk
    // sumber daya agar tabel & paginasi tidak error.
    $daftarPembiayaan = $pembiayaans ?? $pembiayaan ?? collect();
    // $pembiayaans hanya paginator bila memang punya method links().
    $punyaPaginasi = ($pembiayaans ?? null) instanceof \Illuminate\Contracts\Pagination\Paginator;

    // Data dropdown DINAMIS (zero-hardcoding) dengan cadangan aman bila
    // controller lama/belum diperbarui tidak menyuplainya.
    $opsiMetode      = $metodePencairan ?? \App\Enums\DisbursementMethod::pilihan();
    $opsiBank        = $bankOperasional ?? collect();
    $opsiJenisSimpan = $jenisSimpananSukarela ?? array_merge(
        \App\Models\JenisSimpanan::aktifBertipe(\App\Models\JenisSimpanan::TIPE_SUKARELA),
        \App\Models\JenisSimpanan::aktifBertipe(\App\Models\JenisSimpanan::TIPE_LAIN),
    );

    // Pola URL aksi form mengikuti pintu masuk yang aktif, sehingga view yang
    // dipakai bersama tidak salah kirim ke endpoint lain.
    $polaAksi = request()->routeIs('teller.pencairan-disetujui.*')
        ? url('/teller/pencairan-disetujui/__ID__/cairkan')
        : url('/teller/pencairan/__ID__');
@endphp

@if(session('success'))
<div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:0.9rem 1.2rem;border-radius:8px;margin-bottom:1.25rem;display:flex;align-items:center;gap:0.6rem;">
    <span>✅</span> {{ session('success') }}
</div>
@endif

@if(session('error'))
<div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:0.9rem 1.2rem;border-radius:8px;margin-bottom:1.25rem;display:flex;align-items:center;gap:0.6rem;">
    <span>❌</span> {{ session('error') }}
</div>
@endif

{{-- Header Card --}}
<div class="card" style="margin-bottom:1.25rem;padding:1.25rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;">
        <div>
            <div style="font-weight:700;font-size:1.1rem;color:#1e293b;">Daftar Pembiayaan Siap Cair</div>
            <div style="font-size:0.85rem;color:#64748b;margin-top:0.25rem;">
                Daftar pengajuan pembiayaan berstatus <strong>Disetujui / Disahkan</strong> yang siap dicairkan ke rekening sukarela nasabah.
            </div>
        </div>
        <form method="GET" action="{{ route('teller.pencairan.index') }}" style="display:flex;gap:0.5rem;">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Cari No. Pembiayaan / Anggota..." 
                style="padding:0.5rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.875rem;outline:none;width:260px;">
            <button type="submit" style="padding:0.5rem 1rem;background:#0284c7;color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer;font-size:0.875rem;">
                Cari
            </button>
        </form>
    </div>
</div>

{{-- Tabel Pencairan --}}
<div class="card" style="padding:0;overflow:hidden;">
    @if($daftarPembiayaan->count())
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:0.875rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                    <th style="padding:0.875rem 1rem;text-align:left;font-size:0.78rem;font-weight:700;color:#64748b;text-transform:uppercase;">No. Pengajuan</th>
                    <th style="padding:0.875rem 1rem;text-align:left;font-size:0.78rem;font-weight:700;color:#64748b;text-transform:uppercase;">Anggota & Rekening</th>
                    <th style="padding:0.875rem 1rem;text-align:left;font-size:0.78rem;font-weight:700;color:#64748b;text-transform:uppercase;">Produk / Akad</th>
                    <th style="padding:0.875rem 1rem;text-align:right;font-size:0.78rem;font-weight:700;color:#64748b;text-transform:uppercase;">Nominal & Tenor</th>
                    <th style="padding:0.875rem 1rem;text-align:right;font-size:0.78rem;font-weight:700;color:#64748b;text-transform:uppercase;">Angsuran / Bln</th>
                    <th style="padding:0.875rem 1rem;text-align:center;font-size:0.78rem;font-weight:700;color:#64748b;text-transform:uppercase;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @foreach($daftarPembiayaan as $pbm)
                @php
                    // Rekening sukarela sudah di-eager load terfilter dari controller;
                    // fallback nama & no rekening dinamis tanpa error null.
                    $rekSukarela = $pbm->anggota?->rekening?->first();
                    // Fallback rapi & konsisten dengan penomoran controller (REK-XXXX-01)
                    $noRek = $rekSukarela?->no_rekening
                        ?? sprintf('REK-%04d-01', $pbm->anggota?->id ?? 0);
                    $totalWajib = $pbm->total_pembiayaan > 0 ? $pbm->total_pembiayaan : ($pbm->jumlah_pembiayaan + $pbm->total_ujroh);
                    $angsuranBln = $pbm->tenor > 0 ? round($totalWajib / $pbm->tenor, 2) : 0;
                @endphp
                <tr style="border-bottom:1px solid #f1f5f9;transition:background .15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                    
                    {{-- No. Pengajuan --}}
                    <td style="padding:0.875rem 1rem;">
                        <div style="font-weight:700;color:#1e293b;">{{ $pbm->no_pembiayaan }}</div>
                        <div style="font-size:0.75rem;color:#94a3b8;">{{ $pbm->created_at ? $pbm->created_at->format('d/m/Y') : '-' }}</div>
                    </td>

                    {{-- Anggota & Rekening --}}
                    <td style="padding:0.875rem 1rem;">
                        <div style="font-weight:600;color:#1e293b;">{{ $pbm->anggota?->nama_lengkap ?? '-' }}</div>
                        <div style="font-size:0.75rem;color:#0284c7;font-family:monospace;">{{ $noRek }}</div>
                    </td>

                    {{-- Produk / Akad --}}
                    <td style="padding:0.875rem 1rem;">
                        <span style="background:#e0e7ff;color:#3730a3;padding:0.2rem 0.6rem;border-radius:999px;font-size:0.75rem;font-weight:700;">
                            {{ $pbm->akad_pembiayaan ?? 'Mudharabah' }}
                        </span>
                        <div style="font-size:0.75rem;color:#64748b;margin-top:0.2rem;">{{ $pbm->tujuan_pembiayaan ?? '-' }}</div>
                    </td>

                    {{-- Nominal & Tenor --}}
                    <td style="padding:0.875rem 1rem;text-align:right;">
                        <div style="font-weight:700;color:#059669;font-size:0.95rem;">
                            Rp {{ number_format($pbm->jumlah_pembiayaan, 0, ',', '.') }}
                        </div>
                        <div style="font-size:0.75rem;color:#64748b;">{{ $pbm->tenor }} Bulan</div>
                    </td>

                    {{-- Angsuran / Bln --}}
                    <td style="padding:0.875rem 1rem;text-align:right;font-weight:600;color:#1e293b;">
                        Rp {{ number_format($angsuranBln, 0, ',', '.') }}
                    </td>

                    {{-- Aksi --}}
                    <td style="padding:0.875rem 1rem;text-align:center;">
                        <button type="button"
                            onclick="openModalCair(
                                {{ $pbm->id }},
                                '{{ addslashes($pbm->no_pembiayaan) }}',
                                '{{ addslashes($pbm->anggota?->nama_lengkap ?? "-") }}',
                                '{{ addslashes($noRek) }}',
                                {{ $rekSukarela ? 'true' : 'false' }},
                                {{ (float) $pbm->jumlah_pembiayaan }},
                                {{ (float) $angsuranBln }},
                                {{ (int) $pbm->tenor }},
                                {{ (int) ($pbm->anggota?->id ?? 0) }},
                                '{{ $pbm->disbursement_method?->value ?? "" }}'
                            )"
                            style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.45rem 0.95rem;border:none;border-radius:8px;background:#059669;color:#fff;font-size:0.8rem;font-weight:700;cursor:pointer;transition:background .15s;"
                            onmouseover="this.style.background='#047857'"
                            onmouseout="this.style.background='#059669'">
                            💸 Cairkan Dana
                        </button>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($punyaPaginasi)
    <div style="padding:1rem;">
        {{ $daftarPembiayaan->links() }}
    </div>
    @endif

    @else
    <div style="padding:4rem 2rem;text-align:center;color:#94a3b8;">
        <div style="font-size:3rem;margin-bottom:1rem;">💸</div>
        <div style="font-weight:600;font-size:1rem;margin-bottom:0.5rem;color:#64748b;">
            Tidak Ada Pembiayaan Siap Cair
        </div>
        <div style="font-size:0.85rem;">Seluruh pembiayaan berstatus disetujui telah dicairkan oleh Teller.</div>
    </div>
    @endif
</div>

{{-- ─── MODAL KONFIRMASI PENCAIRAN ─────────────────────────────── --}}
<div id="modalCair" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);backdrop-filter:blur(4px);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:2rem;width:100%;max-width:500px;margin:1rem;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">

        <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1.25rem;">
            <div style="width:48px;height:48px;border-radius:12px;background:#d1fae5;display:flex;align-items:center;justify-content:center;font-size:1.5rem;flex-shrink:0;">💸</div>
            <div>
                <div style="font-weight:700;font-size:1.1rem;color:#0f172a;">Konfirmasi Pencairan Dana</div>
                <div style="font-size:0.8rem;color:#64748b;">Pilih skema pencairan: tunai (laci teller), transfer bank operasional, atau pindah buku simpanan.</div>
            </div>
        </div>

        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:1.25rem;margin-bottom:1.25rem;">
            <div style="display:grid;grid-template-columns:140px 1fr;gap:0.6rem;font-size:0.875rem;">
                <span style="color:#64748b;">No. Pembiayaan</span>
                <span style="font-weight:700;color:#1e293b;" id="modalNoPembiayaan">-</span>

                <span style="color:#64748b;">Nama Anggota</span>
                <span style="font-weight:600;color:#1e293b;" id="modalNasabah">-</span>

                <span style="color:#64748b;">Rekening Sukarela</span>
                <span style="font-weight:600;color:#0284c7;font-family:monospace;" id="modalNoRekening">-</span>
                <span style="color:#64748b;">Status Rekening</span>
                <span id="modalStatusRekening">-</span>

                <span style="color:#64748b;">Nominal Cair</span>
                <span style="font-weight:800;color:#059669;font-size:1.05rem;" id="modalJumlah">-</span>

                <span style="color:#64748b;">Tenor & Tagihan</span>
                <span style="font-weight:600;color:#1e293b;" id="modalTenorTagihan">-</span>
            </div>
        </div>

        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:0.75rem 1rem;margin-bottom:1.25rem;font-size:0.8rem;color:#166534;">
            <strong>Catatan Sistem:</strong> Jadwal angsuran sebanyak tenor akan diterbitkan otomatis (sadar hari libur), dan jurnal PSAK 108 diposting ke akun sesuai akad pembiayaan.
        </div>

        <form id="formCair" method="POST">
            @csrf

            {{-- Skema pencairan — dinamis dari App\Enums\DisbursementMethod --}}
            <div style="margin-bottom:1.25rem;">
                <label for="disbursementMethod" style="display:block;font-size:0.8rem;font-weight:700;color:#334155;margin-bottom:0.4rem;">
                    Skema Pencairan <span style="color:#dc2626;">*</span>
                </label>
                <select id="disbursementMethod" name="disbursement_method" required
                    onchange="terapkanSkemaPencairan()"
                    style="width:100%;padding:0.6rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.875rem;outline:none;">
                    @foreach($opsiMetode as $nilai => $label)
                        <option value="{{ $nilai }}" @selected($nilai === \App\Enums\DisbursementMethod::TRANSFER_SAVINGS->value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Rekening bank operasional — hanya untuk TRANSFER_BANK --}}
            <div id="blokBankOperasional" style="margin-bottom:1.25rem;display:none;">
                <label for="bankAccountId" style="display:block;font-size:0.8rem;font-weight:700;color:#334155;margin-bottom:0.4rem;">
                    Rekening Bank Operasional <span style="color:#dc2626;">*</span>
                </label>
                <select id="bankAccountId" name="bank_account_id"
                    style="width:100%;padding:0.6rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.875rem;outline:none;">
                    <option value="">— Pilih Rekening Bank Koperasi —</option>
                    @forelse($opsiBank as $coa)
                        <option value="{{ $coa->id }}">{{ $coa->kode_akun }} — {{ $coa->nama_akun }}</option>
                    @empty
                        <option value="" disabled>Tidak ada akun bank operasional aktif — hubungi pengurus.</option>
                    @endforelse
                </select>
                <div style="font-size:0.75rem;color:#64748b;margin-top:0.35rem;">
                    Sumber dana berkurang sebesar nominal pokok; jurnal KREDIT ke akun bank terpilih.
                </div>
            </div>

            {{-- Rekening simpanan sukarela dalam koperasi — hanya untuk TRANSFER_SAVINGS --}}
            <div id="blokSimpananSukarela" style="margin-bottom:1.25rem;display:none;">
                <label for="savingsAccountId" style="display:block;font-size:0.8rem;font-weight:700;color:#334155;margin-bottom:0.4rem;">
                    Rekening Simpanan Sukarela Anggota <span style="color:#dc2626;">*</span>
                </label>
                <select id="savingsAccountId" name="savings_account_id"
                    style="width:100%;padding:0.6rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.875rem;outline:none;">
                    <option value="">— Pilih Rekening Simpanan Anggota —</option>
                    @php
                        // Daftar rekening simpanan seluruh anggota, dibatasi jenis
                        // simpanan yang diizinkan menerima pencairan (dinamis).
                        $rekeningsAnggota = \App\Models\Rekening::query()
                            ->whereIn('jenis_rekening', $opsiJenisSimpan)
                            ->orderBy('anggota_id')
                            ->orderBy('id')
                            ->get(['id', 'no_rekening', 'anggota_id', 'jenis_rekening', 'status_rekening']);
                    @endphp
                    @forelse($rekeningsAnggota as $rek)
                        <option value="{{ $rek->id }}"
                            data-anggota="{{ $rek->anggota_id }}"
                            data-status="{{ $rek->status_rekening }}">
                            {{ $rek->no_rekening }} — {{ $rek->jenis_rekening }} (anggota #{{ $rek->anggota_id }}, {{ $rek->status_rekening }})
                        </option>
                    @empty
                        <option value="" disabled>Tidak ada rekening simpanan anggota yang sesuai.</option>
                    @endforelse
                </select>
                <div id="infoRekeningSukarela" style="font-size:0.75rem;color:#64748b;margin-top:0.35rem;"></div>
            </div>
            <div style="display:flex;gap:0.75rem;">
                <button type="button" onclick="closeModalCair()"
                    style="flex:1;padding:0.75rem;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#64748b;font-weight:600;cursor:pointer;font-size:0.875rem;">
                    Batal
                </button>
                <button type="submit"
                    style="flex:1;padding:0.75rem;border:none;border-radius:8px;background:#059669;color:#fff;font-weight:700;cursor:pointer;font-size:0.875rem;">
                    ✅ Eksekusi Pencairan
                </button>
            </div>
        </form>
    </div>
</div>

@endsection

@section('scripts')
@push('scripts')
<script>
// Nilai enum disuntik dari PHP agar tidak ada string metode yang ditanam di JS.
const METODE_BANK    = '{{ \App\Enums\DisbursementMethod::TRANSFER_BANK->value }}';
const METODE_SAVINGS = '{{ \App\Enums\DisbursementMethod::TRANSFER_SAVINGS->value }}';
const POLA_AKSI      = '{{ $polaAksi }}';

function openModalCair(id, noPmb, nama, noRek, hasRek, jumlah, tagihan, tenor, anggotaId, metodeTersimpan) {
    document.getElementById('modalNoPembiayaan').textContent = noPmb;
    document.getElementById('modalNasabah').textContent      = nama;
    document.getElementById('modalNoRekening').textContent   = noRek;
    document.getElementById('modalJumlah').textContent       = 'Rp ' + jumlah.toLocaleString('id-ID');
    document.getElementById('modalTenorTagihan').textContent  = tenor + ' Bulan (Rp ' + tagihan.toLocaleString('id-ID') + ' / bulan)';

    // Status rekening tujuan pindah buku.
    const statusEl = document.getElementById('modalStatusRekening');
    statusEl.innerHTML = hasRek
        ? '<span style="background:#d1fae5;color:#065f46;padding:0.15rem 0.6rem;border-radius:999px;font-size:0.75rem;font-weight:700;">✅ Tersedia</span>'
        : '<span style="background:#fef3c7;color:#92400e;padding:0.15rem 0.6rem;border-radius:999px;font-size:0.75rem;font-weight:700;">⚠️ Belum ada rekening sukarela</span>';

    // Filter dropdown simpanan: hanya rekening milik anggota pembiayaan ini.
    const selectSimpanan = document.getElementById('savingsAccountId');
    let pilihan = null;

    Array.from(selectSimpanan.options).forEach(function (opt) {
        if (! opt.value) return;

        const cocok = String(opt.dataset.anggota) === String(anggotaId);
        opt.hidden   = ! cocok;
        opt.disabled = ! cocok;

        if (! cocok) return;

        if (pilihan === null || (opt.dataset.status === 'Aktif' && pilihan.dataset.status !== 'Aktif')) {
            pilihan = opt;
        }
    });

    selectSimpanan.value = pilihan ? pilihan.value : '';
    document.getElementById('infoRekeningSukarela').textContent = pilihan
        ? 'Saldo rekening akan bertambah sebesar nominal pokok pencairan.'
        : 'Anggota ini belum memiliki rekening simpanan yang diizinkan menerima pencairan.';

    // Metode: pakai yang tersimpan di pembiayaan, jika tidak ada gunakan pindah buku.
    const selectMetode = document.getElementById('disbursementMethod');
    selectMetode.value = metodeTersimpan || METODE_SAVINGS;
    terapkanSkemaPencairan();

    document.getElementById('formCair').action = POLA_AKSI.replace('__ID__', id);
    document.getElementById('modalCair').style.display = 'flex';
}

// Tampilkan hanya input yang relevan dengan skema terpilih + atur wajib-isi.
function terapkanSkemaPencairan() {
    const metode       = document.getElementById('disbursementMethod').value;
    const blokBank     = document.getElementById('blokBankOperasional');
    const blokSimpanan = document.getElementById('blokSimpananSukarela');
    const bank         = document.getElementById('bankAccountId');
    const simpanan     = document.getElementById('savingsAccountId');

    const perluBank     = metode === METODE_BANK;
    const perluSimpanan = metode === METODE_SAVINGS;

    blokBank.style.display     = perluBank ? 'block' : 'none';
    blokSimpanan.style.display = perluSimpanan ? 'block' : 'none';
    bank.required     = perluBank;
    simpanan.required = perluSimpanan;
}

function closeModalCair() {
    document.getElementById('modalCair').style.display = 'none';
}

document.getElementById('modalCair').addEventListener('click', function(e) {
    if (e.target === this) closeModalCair();
});
</script>
@endpush
@endsection
