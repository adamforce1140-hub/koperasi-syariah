@extends('layouts.app')
@section('page-title', "Restrukturisasi Pembiayaan")

@section('title', 'Restrukturisasi Pembiayaan')

@section('styles')
<style>
    /* Card ringkas */
    .stat-strip { display:flex; gap:1rem; flex-wrap:wrap; margin-bottom:1.25rem; }
    .stat-box {
        flex:1; min-width:180px; background:#fff; border:1px solid #e2e8f0;
        border-radius:.75rem; padding:1rem 1.25rem;
    }
    .stat-box .num { font-size:1.4rem; font-weight:800; color:#0f172a; }
    .stat-box .lbl { font-size:.72rem; color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:.04em; }

    .btn-ajukan {
        display:inline-flex; align-items:center; gap:.35rem;
        padding:.45rem .8rem; font-size:.78rem; font-weight:600; color:#fff;
        background:#059669; border:none; border-radius:6px; cursor:pointer; white-space:nowrap;
    }
    .btn-ajukan:hover { background:#047857; }
    .btn-ajukan:disabled { background:#cbd5e1; cursor:not-allowed; }

    .kol-badge {
        display:inline-block; padding:.12rem .55rem; border-radius:9999px;
        font-size:.72rem; font-weight:700;
    }
    .days-badge {
        display:inline-block; margin-left:.35rem; padding:.08rem .5rem; border-radius:9999px;
        font-size:.68rem; font-weight:700; background:#fee2e2; color:#b91c1c;
    }

    /* Modal pengajuan */
    .modal-overlay {
        display:none; position:fixed; inset:0; background:rgba(15,23,42,.55);
        z-index:1000; align-items:flex-start; justify-content:center;
        padding:2rem 1rem; overflow-y:auto;
    }
    .modal-overlay.open { display:flex; }
    .modal-box {
        background:#fff; border-radius:1rem; width:640px; max-width:100%;
        margin:auto 0; overflow:hidden;
    }
    .modal-head {
        background:linear-gradient(135deg,#065f46,#047857); color:#fff;
        padding:1.1rem 1.4rem;
    }
    .modal-head h3 { margin:0; font-size:1.05rem; font-weight:800; }
    .modal-head p { margin:.2rem 0 0; font-size:.78rem; opacity:.85; }
    .modal-body { padding:1.25rem 1.4rem; max-height:85vh; overflow-y:auto; }
    .modal-foot {
        display:flex; align-items:center; justify-content:space-between; gap:.75rem;
        padding:1rem 1.4rem; border-top:1px solid #f1f5f9; background:#f8fafc;
    }
    .modal-foot .info-preview { font-size:.8rem; color:#475569; }
    .modal-foot .info-preview strong { color:#059669; }

    .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:.85rem 1rem; }
    .form-group { display:flex; flex-direction:column; gap:.3rem; }
    .form-group.full { grid-column:1 / -1; }
    .form-group label { font-size:.72rem; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.03em; }
    .form-group input, .form-group select, .form-group textarea {
        border:1.5px solid #e2e8f0; border-radius:.5rem; padding:.55rem .65rem;
        font-size:.85rem; font-family:inherit; outline:none; background:#fff; width:100%;
    }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color:#059669; }
    .form-group .hint { font-size:.72rem; color:#94a3b8; }

    .rs-info {
        display:grid; grid-template-columns:repeat(4,1fr); gap:.6rem;
        background:#f0fdf4; border:1px solid #bbf7d0; border-radius:.6rem; padding:.75rem;
        margin-bottom:1rem;
    }
    .rs-info div { text-align:center; }
    .rs-info .v { font-size:.95rem; font-weight:800; color:#065f46; }
    .rs-info .k { font-size:.66rem; font-weight:700; color:#047857; text-transform:uppercase; letter-spacing:.03em; }

    .svc-note {
        display:flex; gap:.55rem; align-items:flex-start;
        background:#fffbeb; border:1px solid #fde68a; border-radius:.6rem;
        padding:.7rem .9rem; font-size:.76rem; color:#92400e; line-height:1.45; margin-top:1rem;
    }

    .btn { display:inline-flex; align-items:center; justify-content:center; gap:.4rem;
        padding:.55rem 1.1rem; font-size:.85rem; font-weight:600; border:none;
        border-radius:.5rem; cursor:pointer; }
    .btn-primary { background:#059669; color:#fff; }
    .btn-primary:hover { background:#047857; }
    .btn-secondary { background:#e2e8f0; color:#334155; }
    .btn-secondary:hover { background:#cbd5e1; }

    /* ---- Responsif: satu kolom pada tablet & ponsel ---- */
    @media (max-width: 900px) {
        .form-grid { grid-template-columns: 1fr; }
        .modal-body { max-height: 70vh; }
        .modal-foot { flex-wrap: wrap; row-gap: .5rem; }
    }
    @media (max-width: 640px) {
        .rs-info { grid-template-columns: repeat(2, 1fr); }
        .modal-body { padding: 1rem; }
        .modal-foot { padding: .85rem 1rem; }
        .btn { width: 100%; }
    }
    @media (max-width: 400px) {
        .rs-info { grid-template-columns: 1fr; }
    }
</style>
@endsection

@section('content')
<div class="page-header">
    <h1 class="page-title">Restrukturisasi Pembiayaan</h1>
    <p class="page-subtitle">Pembiayaan bermasalah nasabah binaan yang layak diajukan restrukturisasi (kualitas Kurang Lancar / Diragukan / Macet atau keterlambatan &gt; 30 hari)</p>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert" style="background:#fee2e2;color:#991b1b;border:1px solid #fecaca;border-radius:.5rem;padding:.8rem 1rem;margin-bottom:1rem;">{{ session('error') }}</div>
@endif

<div class="stat-strip">
    <div class="stat-box">
        <div class="num">{{ $list->total() }}</div>
        <div class="lbl">Pembiayaan Layak Restrukturisasi</div>
    </div>
    <div class="stat-box">
        <div class="num">{{ $riwayat->where('status', 'PENDING_APPROVAL')->count() }}</div>
        <div class="lbl">Menunggu Persetujuan Manajer</div>
    </div>
    <div class="stat-box">
        <div class="num">{{ $riwayat->where('status', 'APPROVED')->count() }}</div>
        <div class="lbl">Disetujui Manajer</div>
    </div>
</div>

<div class="data-card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;">
        <span class="card-title">Daftar Pembiayaan Aktif — Layak Restrukturisasi</span>
        <form method="GET" style="display:flex;gap:.5rem;">
            <input type="text" name="search" value="{{ $search }}"
                placeholder="Cari nama / no. anggota..."
                style="padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;width:240px;">
            <button type="submit" class="btn btn-secondary" style="font-size:.85rem;padding:.5rem .9rem;">Cari</button>
        </form>
    </div>

    <table>
        <thead>
            <tr>
                <th>No. Pembiayaan</th>
                <th>Anggota</th>
                <th>Akad</th>
                <th>Sisa Piutang</th>
                <th>Angsuran / Bln</th>
                <th>Kualitas & Tunggakan</th>
                <th>Jatuh Tempo</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($list as $item)
            @php
                $rk  = $item->ringkasan_restrukturisasi;
                $jangkaSisa = (int) $item->tenor - (int) $item->angsuran_sudah_bayar;
            @endphp
            <tr>
                <td><strong>{{ $item->no_pembiayaan }}</strong></td>
                <td>
                    <div>{{ $item->anggota?->nama_lengkap ?? '-' }}</div>
                    <div style="font-size:.75rem;color:#6b7280;">{{ $item->anggota?->no_anggota }}</div>
                </td>
                <td>{{ $item->akad_pembiayaan ?? '-' }}</td>
                <td>
                    <div style="font-weight:700;color:#0f172a;">Rp {{ number_format($rk['sisaPiutang'], 0, ',', '.') }}</div>
                    <div style="font-size:.72rem;color:#64748b;">Pokok Rp {{ number_format($rk['sisaPokok'], 0, ',', '.') }} + Margin Rp {{ number_format($rk['sisaMargin'], 0, ',', '.') }}</div>
                </td>
                <td>
                    <div>Rp {{ number_format($rk['angsuranLama'], 0, ',', '.') }}</div>
                    <div style="font-size:.72rem;color:#64748b;">sisa {{ $rk['tenorSisa'] }} tenor</div>
                </td>
                <td>
                    <span class="kol-badge" style="{{ $item->kolektibilitas_style }}">{{ $item->kolektibilitas }}</span>
                    <span class="days-badge">{{ $item->hari_tunggakan }} hari</span>
                </td>
                <td>{{ $item->tanggal_jatuh_tempo?->format('d/m/Y') ?? '-' }}</td>
                <td>
                    <button type="button" class="btn-ajukan" onclick="openModal(this)"
                        data-id="{{ $item->id }}"
                        data-no="{{ $item->no_pembiayaan }}"
                        data-anggota="{{ $item->anggota?->nama_lengkap ?? '-' }}"
                        data-akad="{{ $item->akad_pembiayaan ?? '-' }}"
                        data-pokok="{{ number_format($rk['sisaPokok'], 0, ',', '.') }}"
                        data-margin="{{ number_format($rk['sisaMargin'], 0, ',', '.') }}"
                        data-piutang="{{ number_format($rk['sisaPiutang'], 0, ',', '.') }}"
                        data-piutang-raw="{{ $rk['sisaPiutang'] }}"
                        data-lama="{{ number_format($rk['angsuranLama'], 0, ',', '.') }}"
                        data-lama-raw="{{ $rk['angsuranLama'] }}"
                        data-sisa="{{ $rk['tenorSisa'] }}">
                        Ajukan Restrukturisasi
                    </button>
                </td>
            </tr>
            @empty
            <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:2rem;">
                Tidak ada pembiayaan binaan yang layak direstrukturisasi saat ini.
            </td></tr>
            @endforelse
        </tbody>
    </table>
    @if($list->hasPages())
    <div style="padding:1rem;">{{ $list->links() }}</div>
    @endif
</div>

{{-- ─── RIWAYAT PENGAJUAN AO ─────────────────────────────────────────── --}}
<div class="data-card" style="margin-top:1.5rem;">
    <div class="card-header">
        <span class="card-title">Riwayat Pengajuan Restrukturisasi</span>
    </div>
    <table>
        <thead>
            <tr>
                <th>No. Pembiayaan</th>
                <th>Anggota</th>
                <th>Skema</th>
                <th>Tenor Baru</th>
                <th>Angsuran Lama → Baru</th>
                <th>Kemampuan Bayar</th>
                <th>Status</th>
                <th>Catatan Manajer</th>
            </tr>
        </thead>
        <tbody>
            @forelse($riwayat as $r)
            @php
                $statusStyle = match($r->status) {
                    'APPROVED'         => 'background:#d1fae5;color:#065f46;',
                    'REJECTED'         => 'background:#fee2e2;color:#991b1b;',
                    default            => 'background:#fef3c7;color:#92400e;',
                };
                $angsuranBaru = $r->status === 'APPROVED'
                    ? ($r->pembiayaan?->jumlah_angsuran ?? 0)
                    : \App\Services\RestrukturisasiService::simulasiAngsuranBaru((float) $r->sisa_piutang_snapshot, (int) $r->tenor_baru);
            @endphp
            <tr>
                <td><strong>{{ $r->pembiayaan?->no_pembiayaan ?? '-' }}</strong></td>
                <td>
                    <div>{{ $r->pembiayaan?->anggota?->nama_lengkap ?? '-' }}</div>
                    <div style="font-size:.72rem;color:#64748b;">{{ $r->pembiayaan?->anggota?->no_anggota }}</div>
                </td>
                <td>{{ $r->skema_restrukturisasi }}</td>
                <td>{{ $r->tenor_baru ? $r->tenor_baru . ' bln' : '-' }}</td>
                <td>
                    <span style="text-decoration:line-through;color:#b45309;">Rp {{ number_format($r->angsuran_lama_snapshot, 0, ',', '.') }}</span>
                    → <strong style="color:#059669;">Rp {{ number_format(max($angsuranBaru, 0), 0, ',', '.') }}</strong>
                </td>
                <td>Rp {{ number_format($r->kemampuan_bayar_baru, 0, ',', '.') }}</td>
                <td>
                    <span class="kol-badge" style="{{ $statusStyle }}">{{ $r->status }}</span>
                    @if($r->status === 'PENDING_APPROVAL')
                    <div style="font-size:.68rem;color:#64748b;">{{ $r->created_at?->diffForHumans() }}</div>
                    @endif
                </td>
                <td style="font-size:.8rem;color:#475569;max-width:220px;">
                    {{ $r->catatan_manajer ?: ($r->manajer ? 'Diproses ' . $r->manajer->name : '-') }}
                </td>
            </tr>
            @empty
            <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:2rem;">Belum ada pengajuan restrukturisasi.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- ─── MODAL PENGAJUAN ──────────────────────────────────────────────── --}}
<div class="modal-overlay" id="rsModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3>Form Pengajuan Restrukturisasi</h3>
            <p id="rsModalSub">Pengisian usulan skema restrukturisasi pembiayaan</p>
        </div>
        <form method="POST" action="{{ route('ao.restrukturisasi.store') }}" id="rsForm">
            @csrf
            <input type="hidden" name="pembiayaan_id" id="rsPembiayaanId">

            <div class="modal-body">
                <div class="rs-info">
                    <div><div class="k">Sisa Pokok</div><div class="v" id="rsPokok">-</div></div>
                    <div><div class="k">Sisa Margin</div><div class="v" id="rsMargin">-</div></div>
                    <div><div class="k">Sisa Piutang</div><div class="v" id="rsPiutang">-</div></div>
                    <div><div class="k">Angsuran Lama</div><div class="v" id="rsLama">-</div></div>
                </div>

                <div class="form-grid">
                    <div class="form-group full">
                        <label>Skema Restrukturisasi *</label>
                        <select name="skema_restrukturisasi" id="rsSkema" required onchange="toggleSkema()">
                            <option value="">— Pilih Skema —</option>
                            <option value="Rescheduling">Rescheduling — Perpanjangan Tenor</option>
                            <option value="Reconditioning">Reconditioning — Penundaan Pokok / Ubah Jatuh Tempo</option>
                        </select>
                    </div>

                    <div class="form-group" id="rsGroupTenor">
                        <label>Tenor Baru (bulan) *</label>
                        <input type="number" name="tenor_baru" id="rsTenor" min="1" max="240" placeholder="cth: 24">
                        <span class="hint" id="rsTenorHint">Angsuran baru = Sisa Piutang ÷ Tenor Baru (tanpa margin tambahan).</span>
                    </div>

                    <div class="form-group" id="rsGroupGrace">
                        <label>Grace Period (bulan)</label>
                        <input type="number" name="grace_period" id="rsGrace" min="1" max="24" placeholder="cth: 3">
                        <span class="hint">Penundaan bayar pokok (opsional, Reconditioning).</span>
                    </div>

                    <div class="form-group" id="rsGroupTanggal">
                        <label>Tanggal Jatuh Tempo Baru</label>
                        <input type="date" name="tanggal_jatuh_tempo_baru" id="rsTglJatuhTempo">
                        <span class="hint">Perubahan tanggal jatuh tempo (opsional).</span>
                    </div>

                    <div class="form-group">
                        <label>Alasan Restrukturisasi *</label>
                        <select name="alasan_restrukturisasi" required>
                            <option value="">— Pilih Alasan —</option>
                            <option>Penurunan Omzet Usaha</option>
                            <option>Musibah / Bencana Alam</option>
                            <option>Sakit Berkepanjangan</option>
                            <option>Kehilangan Pekerjaan</option>
                            <option>Kondisi Ekonomi Keluarga</option>
                            <option>Lainnya</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Kemampuan Bayar Baru (Rp/bln) *</label>
                        <input type="text" name="kemampuan_bayar_baru" id="rsMampu" inputmode="numeric" autocomplete="off"
                            required placeholder="cth: 200.000" oninput="formatMampu(this)">
                        <span class="hint">Ketik angka saja — otomatis diformat rupiah (cth: 200.000).</span>
                    </div>

                    <div class="form-group full">
                        <label>Catatan Analisis AO</label>
                        <textarea name="catatan_ao" rows="3" placeholder="Ringkasan analisa ulang AO ..."></textarea>
                    </div>
                </div>

                <div class="svc-note">
                    <span>⚖️</span>
                    <span><strong>Safeguard Syariah (Fatwa DSN-MUI No. 49/DSN-MUI/IV/2005):</strong>
                    perpanjangan tenor TIDAK menambah nominal sisa margin / sisa piutang yang telah disepakati.
                    Tidak ada harga jual / margin baru. Seluruh proses disetujui Manajer dalam transaksi database.</span>
                </div>
            </div>

            <div class="modal-foot">
                <div class="info-preview" style="flex:1;">
                    <div id="rsFooterLines">Pilih skema restrukturisasi untuk menghitung simulasi.</div>
                </div>
                <div style="display:flex;gap:.6rem;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Batal</button>
                    <button type="submit" class="btn btn-primary">Kirim ke Manajer</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection

@section('scripts')
<script>
    (function () {
        const overlay  = document.getElementById('rsModal');
        const form     = document.getElementById('rsForm');
        const idEl     = document.getElementById('rsPembiayaanId');
        let sisaPiutangRaw  = 0;
        let sisaTenorAktif  = 0;
        let angsuranLamaRaw = 0;

        window.openModal = function (btn) {
            idEl.value = btn.dataset.id;
            document.getElementById('rsPokok').textContent     = 'Rp ' + btn.dataset.pokok;
            document.getElementById('rsMargin').textContent    = 'Rp ' + btn.dataset.margin;
            document.getElementById('rsPiutang').textContent   = 'Rp ' + btn.dataset.piutang;
            document.getElementById('rsLama').textContent      = 'Rp ' + btn.dataset.lama;
            sisaPiutangRaw  = parseFloat(btn.dataset.piutangRaw || 0);
            sisaTenorAktif  = parseInt(btn.dataset.sisa || '0', 10);
            angsuranLamaRaw = parseFloat(btn.dataset.lamaRaw || 0);

            document.getElementById('rsTenorHint').textContent =
                'Sisa tenor saat ini ' + sisaTenorAktif + ' bulan — tenor baru WAJIB lebih besar '
                + '(angsuran baru = Sisa Piutang ÷ Tenor Baru, tanpa margin tambahan).';

            document.getElementById('rsModalSub').textContent =
                btn.dataset.no + ' — ' + btn.dataset.anggota + ' (' + btn.dataset.akad + ')';

            form.reset();
            document.getElementById('rsTenor').value = btn.dataset.sisa; // default = tenor sisa
            document.getElementById('rsSkema').value = '';
            toggleSkema();
            updatePreview();
            overlay.classList.add('open');
        };

        window.closeModal = function () { overlay.classList.remove('open'); };
        overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });

        window.toggleSkema = function () {
            const skema = document.getElementById('rsSkema').value;
            document.getElementById('rsGroupTenor').style.display   = skema === 'Rescheduling' ? 'flex' : 'none';
            document.getElementById('rsGroupGrace').style.display   = skema === 'Reconditioning' ? 'flex' : 'none';
            document.getElementById('rsGroupTanggal').style.display = skema === 'Reconditioning' ? 'flex' : 'none';

            // Reset nilai yang tidak relevan dengan skema terpilih agar footer ikut ter-reset.
            if (skema === 'Rescheduling') {
                document.getElementById('rsGrace').value = '';
                document.getElementById('rsTglJatuhTempo').value = '';
            } else if (skema === 'Reconditioning') {
                document.getElementById('rsTenor').value = '';
            }

            updatePreview();
        };

        // Footer dinamis sesuai skema: Rescheduling (angsuran baru) vs Reconditioning (grace / jatuh tempo baru).
        function updatePreview() {
            const skema = document.getElementById('rsSkema').value;
            const tenor = parseInt(document.getElementById('rsTenor').value || '0', 10);
            const fmt   = new Intl.NumberFormat('id-ID');
            const rupiah = function (v) { return 'Rp ' + fmt.format(Math.round(v)); };
            const lines = [];

            if (skema === 'Rescheduling') {
                // Angsuran Baru = Sisa Piutang ÷ Tenor Baru (tanpa margin tambahan).
                lines.push(tenor > 0 && sisaPiutangRaw > 0
                    ? 'Angsuran Baru: <strong>' + rupiah(sisaPiutangRaw / tenor) + ' / bulan</strong>'
                    : 'Angsuran Baru: <strong>—</strong>');
            } else if (skema === 'Reconditioning') {
                const grace = parseInt(document.getElementById('rsGrace').value || '0', 10);
                const tgl   = document.getElementById('rsTglJatuhTempo').value;

                if (grace > 0) {
                    lines.push('Masa Grace Period: <strong>Bebas Bayar Pokok selama '
                        + grace + ' Bulan</strong>');
                }
                if (tgl) {
                    const dd = tgl.split('-')[2];
                    lines.push('Jatuh Tempo Baru: <strong>Tanggal ' + dd + ' setiap bulan</strong>'
                        + ' (Angsuran Tetap: ' + rupiah(angsuranLamaRaw) + ')');
                }
                if (lines.length === 0) {
                    lines.push('Isi Grace Period dan/atau Tanggal Jatuh Tempo Baru untuk melihat simulasi.');
                }
            } else {
                lines.push('Pilih skema restrukturisasi untuk menghitung simulasi.');
            }

            document.getElementById('rsFooterLines').innerHTML =
                lines.map(function (l) { return '<div>' + l + '</div>'; }).join('');
        }

        // Re-calculate real-time saat nilai apa pun berubah.
        document.getElementById('rsTenor').addEventListener('input', updatePreview);
        document.getElementById('rsGrace').addEventListener('input', updatePreview);
        document.getElementById('rsTglJatuhTempo').addEventListener('change', updatePreview);
        document.getElementById('rsTglJatuhTempo').addEventListener('input', updatePreview);

        // Format Rupiah real-time pada input 'Kemampuan Bayar Baru' (200000 → 200.000).
        window.formatMampu = function (el) {
            const digits = String(el.value || '').replace(/[^\d]/g, '');
            el.value = digits ? new Intl.NumberFormat('id-ID').format(parseInt(digits, 10)) : '';
        };

        // Ubah nilai berformat rupiah menjadi angka murni (untuk validasi & pengiriman).
        function parseRupiah(v) {
            return parseFloat(String(v || '').replace(/[^\d]/g, '') || '0');
        }

        // Validasi sebelum kirim: tenor baru & kemampuan bayar.
        form.addEventListener('submit', function (e) {
            const skema   = document.getElementById('rsSkema').value;
            const tenor   = parseInt(document.getElementById('rsTenor').value || '0', 10);
            const mampuEl = document.getElementById('rsMampu');
            const mampu   = parseRupiah(mampuEl.value);

            // 1. Tenor baru wajib lebih besar dari sisa tenor saat ini.
            if (tenor > 0 && tenor <= sisaTenorAktif) {
                e.preventDefault();
                alert('Tenor baru harus lebih besar dari sisa tenor saat ini untuk memperkecil angsuran.');
                document.getElementById('rsTenor').focus();
                return;
            }

            if (skema === 'Rescheduling' && tenor <= 0) {
                e.preventDefault();
                alert('Untuk skema Rescheduling, tenor baru wajib diisi.');
                document.getElementById('rsTenor').focus();
                return;
            }

            // 2. Angsuran baru tidak boleh melebihi kemampuan bayar baru AO.
            const angsuranBaru = tenor > 0
                ? (sisaPiutangRaw / tenor)
                : angsuranLamaRaw;

            if (angsuranBaru > mampu) {
                e.preventDefault();
                alert('Angsuran baru (Rp ' + new Intl.NumberFormat('id-ID').format(Math.round(angsuranBaru))
                    + ') tidak boleh lebih besar dari kemampuan bayar baru (Rp '
                    + new Intl.NumberFormat('id-ID').format(Math.round(mampu)) + ').');
                mampuEl.focus();
                return;
            }

            // Kirim angka murni (tanpa pemisah ribuan) ke controller.
            mampuEl.value = String(Math.round(mampu));
        });
    })();
</script>
@endsection