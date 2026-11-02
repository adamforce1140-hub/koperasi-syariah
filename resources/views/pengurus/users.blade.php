@extends('layouts.app')

@section('title', 'Manajemen User & Hak Akses — Pengurus')
@section('page-title', 'Manajemen User & Hak Akses')

@php
    // Format nominal batas operasional. Semantik yang dipakai mesin batas:
    // NULL = tanpa batas (unlimited), 0 = dilarang sepenuhnya.
    $formatLimit = function ($nilai) {
        if (is_null($nilai) || $nilai === '') {
            return '<span style="color:#059669;font-weight:600;">&#8734; Unlimited</span>';
        }

        if ((float) $nilai === 0.0) {
            return '<span style="color:#ef4444;font-weight:600;">&#128683; Dilarang</span>';
        }

        return '<strong>Rp ' . number_format((float) $nilai, 0, ',', '.') . '</strong>';
    };
@endphp

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- ── HEADER ─────────────────────────────────────── --}}
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
                Manajemen User & Hak Akses Staf
            </h1>
            <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
                Kelola akun pengguna, tinjau matriks wewenang per peran, dan atur batas operasional transaksi.
            </p>
        </div>
    </div>

    @if(session('success'))
        <div style="background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;padding:0.75rem 1rem;border-radius:0.5rem;font-size:0.85rem;font-weight:600;">
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div style="background:#fee2e2;color:#991b1b;border:1px solid #fecaca;padding:0.75rem 1rem;border-radius:0.5rem;font-size:0.85rem;font-weight:600;">
            {{ session('error') }}
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

    {{-- ── TABEL USERS ──────────────────────────────────── --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        @if(isset($users) && $users->count())
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">#</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Nama User</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Email</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;">Role / Peran</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;">Terdaftar</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($users as $u)
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:0.75rem 1rem;color:#94a3b8;">{{ ($users->currentPage() - 1) * $users->perPage() + $loop->iteration }}</td>
                        <td style="padding:0.75rem 1rem;font-weight:600;color:#1e293b;">{{ $u->name }}</td>
                        <td style="padding:0.75rem 1rem;color:#475569;">{{ $u->email }}</td>
                        <td style="padding:0.75rem 1rem;text-align:center;">
                            @if($u->id !== auth()->id())
                                {{-- Form peran tidak pernah menawarkan peran yang tidak
                                     boleh ditetapkan aktor ini (mis. peran puncak
                                     administratif bagi selain Super Admin). Server tetap
                                     memeriksa ulang di `ubahPeran()` dan model User. --}}
                                <form action="{{ route('pengurus.users.peran', $u->id) }}" method="POST"
                                      style="display:flex;gap:0.35rem;align-items:center;justify-content:center;">
                                    @csrf
                                    @method('PUT')
                                    <select name="role" style="padding:0.25rem 0.4rem;border:1px solid #cbd5e1;border-radius:0.375rem;font-size:0.75rem;">
                                        <option value="{{ $u->role }}">{{ \App\Support\HierarkiRole::label($u->role) }}</option>
                                        @foreach($peranDapatDitetapkan as $p)
                                            @continue($p === $u->role)
                                            <option value="{{ $p }}">{{ \App\Support\HierarkiRole::label($p) }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" style="padding:0.25rem 0.6rem;border:0;border-radius:0.375rem;background:#0369a1;color:#fff;font-size:0.72rem;font-weight:600;cursor:pointer;">
                                        Set
                                    </button>
                                </form>
                            @else
                                <span style="display:inline-block;padding:0.2rem 0.6rem;border-radius:0.375rem;font-size:0.75rem;font-weight:600;background:#e0f2fe;color:#0369a1;text-transform:uppercase;">
                                    {{ $u->role }}
                                </span>
                            @endif
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:center;color:#64748b;">{{ $u->created_at ? $u->created_at->format('d M Y') : '-' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="padding:1rem;border-top:1px solid #e2e8f0;">
            {{ $users->links() }}
        </div>
        @else
        <div style="padding:2rem;text-align:center;color:#64748b;">
            Belum ada data user.
        </div>
        @endif
    </div>

    {{-- ── MATRIKS HAK AKSES (READ-ONLY) ────────────────── --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <div style="border-bottom:1px solid #f1f5f9;padding-bottom:0.75rem;margin-bottom:1rem;">
            <h3 style="font-size:1rem;font-weight:700;color:#1e293b;margin:0;">
                Matriks Hak Akses per Peran
            </h3>
            <p style="margin:0.3rem 0 0;font-size:0.78rem;color:#64748b;">
                Sumber tunggal: <code style="background:#f1f5f9;padding:0.05rem 0.3rem;border-radius:0.25rem;">config/izin.php</code>.
                Hanya-baca &mdash; wewenang tidak dapat diubah dari antarmuka agar pemisahan tugas tetap dapat diaudit dari kode.
            </p>
        </div>

        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.78rem;">
                <thead>
                    <tr style="background:#f8fafc;">
                        <th style="padding:0.6rem 0.75rem;text-align:left;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Izin</th>
                        @foreach($peranTersedia as $peran)
                            <th style="padding:0.6rem 0.5rem;text-align:center;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap;">
                                {{ \App\Support\HierarkiRole::label($peran) }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($matriksIzin as $modul => $daftarIzin)
                        <tr style="background:#f1f5f9;">
                            <td colspan="{{ count($peranTersedia) + 1 }}" style="padding:0.4rem 0.75rem;font-size:0.7rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;">
                                {{ $modul }}
                            </td>
                        </tr>
                        @foreach($daftarIzin as $izin => $peranIzin)
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:0.45rem 0.75rem;color:#1e293b;white-space:nowrap;">
                                    <code style="font-size:0.75rem;">{{ $izin }}</code>
                                </td>
                                @foreach($peranTersedia as $peran)
                                    <td style="padding:0.45rem 0.5rem;text-align:center;">
                                        @if(in_array($peran, $peranIzin, true))
                                            <span title="Diizinkan" style="display:inline-block;width:1.15rem;height:1.15rem;line-height:1.15rem;border-radius:999px;background:#d1fae5;color:#065f46;font-size:0.72rem;font-weight:700;">&#10003;</span>
                                        @else
                                            <span style="color:#cbd5e1;">&middot;</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>

        <p style="font-size:0.74rem;color:#64748b;margin:0.9rem 0 0;">
            Beberapa izin pada satu rute bersifat <strong>DAN</strong> (semua harus lolos). Satu izin mewakili satu
            wewenang fungsional &mdash; bukan satu tombol.
        </p>
    </div>

    {{-- ── LIMIT TRANSAKSI PER PERAN (DAPAT DIUBAH) ─────── --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;border-bottom:1px solid #f1f5f9;padding-bottom:0.75rem;margin-bottom:1.25rem;">
            <div>
                <h3 style="font-size:1rem;font-weight:700;color:#1e293b;margin:0;">
                    Batas Operasional per Peran
                </h3>
                <p style="margin:0.3rem 0 0;font-size:0.78rem;color:#64748b;">
                    Override dari tabel <code style="background:#f1f5f9;padding:0.05rem 0.3rem;border-radius:0.25rem;">limit_transaksi_role</code>.
                    Pasangan peran &amp; jenis yang tidak tercantum memakai default konfigurasi.
                </p>
            </div>
            <button type="button" onclick="bukaModalLimitTambah()"
                style="padding:0.5rem 1rem;background:#059669;color:#fff;border:none;border-radius:0.5rem;font-size:0.8rem;font-weight:600;cursor:pointer;">
                + Tambah Override
            </button>
        </div>

        @if($limitTransaksi->isEmpty())
            <div style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:0.5rem;padding:1.25rem;text-align:center;font-size:0.82rem;color:#64748b;">
                Belum ada override. Seluruh peran memakai batas default dari
                <code>config/role_hierarki.php</code>.
            </div>
        @else
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
                    <thead>
                        <tr style="background:#f8fafc;text-align:left;">
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Peran</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Jenis Transaksi</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Limit / Transaksi</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Limit Harian</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;text-align:center;white-space:nowrap;">Butuh Approval</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Approval Oleh</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Berlaku</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;text-align:right;white-space:nowrap;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($limitTransaksi as $l)
                            @php
                                $berlaku = $batasEfektif[$l->role][$l->jenis_transaksi]['limit_per_transaksi'] ?? null;
                                $payloadLimit = [
                                    'id'                  => $l->id,
                                    'role'                => $l->role,
                                    'jenis_transaksi'     => $l->jenis_transaksi,
                                    'limit_per_transaksi' => is_null($l->limit_per_transaksi) ? '' : (float) $l->limit_per_transaksi,
                                    'limit_harian'        => is_null($l->limit_harian) ? '' : (float) $l->limit_harian,
                                    'butuh_approval'      => (bool) $l->butuh_approval,
                                    'approval_role'       => $l->approval_role,
                                ];
                            @endphp
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:0.6rem 0.75rem;color:#1e293b;font-weight:600;white-space:nowrap;">
                                    {{ \App\Support\HierarkiRole::label($l->role) }}
                                </td>
                                <td style="padding:0.6rem 0.75rem;color:#475569;white-space:nowrap;">
                                    {{ \App\Support\HierarkiRole::labelJenis($l->jenis_transaksi) }}
                                </td>
                                <td style="padding:0.6rem 0.75rem;color:#1e293b;white-space:nowrap;">
                                    {!! $formatLimit($l->limit_per_transaksi) !!}
                                </td>
                                <td style="padding:0.6rem 0.75rem;color:#1e293b;white-space:nowrap;">
                                    {!! $formatLimit($l->limit_harian) !!}
                                </td>
                                <td style="padding:0.6rem 0.75rem;text-align:center;">
                                    <span style="display:inline-block;font-size:0.7rem;font-weight:700;padding:0.15rem 0.45rem;border-radius:0.25rem;{{ $l->butuh_approval ? 'background:#fef3c7;color:#92400e;' : 'background:#d1fae5;color:#065f46;' }}">
                                        {{ $l->butuh_approval ? 'YA' : 'TIDAK' }}
                                    </span>
                                </td>
                                <td style="padding:0.6rem 0.75rem;color:#475569;white-space:nowrap;">
                                    {{ $l->approval_role ? \App\Support\HierarkiRole::label($l->approval_role) : '—' }}
                                </td>
                                <td style="padding:0.6rem 0.75rem;color:#475569;white-space:nowrap;">
                                    {!! $formatLimit($berlaku) !!}
                                </td>
                                <td style="padding:0.6rem 0.75rem;text-align:right;white-space:nowrap;">
                                    <button type="button" onclick='bukaModalLimitEdit(@json($payloadLimit))'
                                        style="padding:0.3rem 0.65rem;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:0.35rem;font-size:0.74rem;font-weight:600;cursor:pointer;">
                                        Edit
                                    </button>

                                    <form action="{{ route('pengurus.limit-role.destroy', $l->id) }}" method="POST"
                                        style="display:inline;" onsubmit="return confirm('Hapus override limit {{ \App\Support\HierarkiRole::label($l->role) }} — {{ \App\Support\HierarkiRole::labelJenis($l->jenis_transaksi) }}? Batas akan kembali ke default konfigurasi.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                            style="padding:0.3rem 0.65rem;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;border-radius:0.35rem;font-size:0.74rem;font-weight:600;cursor:pointer;">
                                            Hapus
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <p style="font-size:0.74rem;color:#64748b;margin:0.9rem 0 0;">
            <strong>Semantik nilai:</strong> kosongkan nominal untuk <em>Unlimited</em>; isi <code>0</code> untuk
            <em>melarang</em> transaksi. Kolom <strong>Berlaku</strong> menunjukkan nilai efektif setelah lapisan
            config &rarr; override &rarr; kebijakan koperasi (mis. limit teller dapat diatur dari menu Kebijakan).
        </p>
    </div>

</div>

{{-- ── MODAL: TAMBAH OVERRIDE ─────────────────────────── --}}
<div id="modal-limit-tambah" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:60;padding:1.5rem;overflow-y:auto;">
    <div style="max-width:32rem;margin:3rem auto;background:#fff;border-radius:0.875rem;padding:1.5rem;">
        <h3 style="font-size:1rem;font-weight:700;color:#1e293b;margin:0 0 1rem;">Tambah Override Batas</h3>
        <form action="{{ route('pengurus.limit-role.store') }}" method="POST">
            @csrf
            <div style="display:flex;flex-direction:column;gap:0.9rem;">
                <div>
                    <label for="tambah-limit-role" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.3rem;">Peran <span style="color:#dc2626;">*</span></label>
                    <select id="tambah-limit-role" name="role" required
                        style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.4rem;font-size:0.82rem;color:#1e293b;background:#fff;">
                        @foreach($peranTersedia as $peran)
                            <option value="{{ $peran }}">{{ \App\Support\HierarkiRole::label($peran) }} ({{ $peran }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="tambah-limit-jenis" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.3rem;">Jenis Transaksi <span style="color:#dc2626;">*</span></label>
                    <select id="tambah-limit-jenis" name="jenis_transaksi" required
                        style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.4rem;font-size:0.82rem;color:#1e293b;background:#fff;">
                        @foreach($jenisTersedia as $kodeJenis => $labelJenis)
                            <option value="{{ $kodeJenis }}">{{ $labelJenis }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="tambah-limit-per-transaksi" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.3rem;">Limit per Transaksi (Rp)</label>
                    <input type="number" id="tambah-limit-per-transaksi" name="limit_per_transaksi" min="0" step="0.01"
                        placeholder="Kosongkan = unlimited, 0 = dilarang"
                        style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.4rem;font-size:0.82rem;color:#1e293b;">
                </div>
                <div>
                    <label for="tambah-limit-harian" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.3rem;">Limit Harian (Rp)</label>
                    <input type="number" id="tambah-limit-harian" name="limit_harian" min="0" step="0.01"
                        placeholder="Kosongkan = unlimited"
                        style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.4rem;font-size:0.82rem;color:#1e293b;">
                </div>
                <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.8rem;color:#334155;background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.4rem;padding:0.55rem 0.7rem;cursor:pointer;">
                    <input type="checkbox" name="butuh_approval" value="1" style="width:1rem;height:1rem;">
                    Transaksi di atas limit wajib disetujui
                </label>
                <div>
                    <label for="tambah-limit-approval" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.3rem;">Peran Pemberi Approval</label>
                    <select id="tambah-limit-approval" name="approval_role"
                        style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.4rem;font-size:0.82rem;color:#1e293b;background:#fff;">
                        <option value="">— Tidak ada —</option>
                        @foreach($peranTersedia as $peran)
                            <option value="{{ $peran }}">{{ \App\Support\HierarkiRole::label($peran) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:0.5rem;margin-top:1.25rem;">
                <button type="button" onclick="tutupModalLimit('modal-limit-tambah')"
                    style="padding:0.55rem 1rem;background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;border-radius:0.5rem;font-size:0.8rem;font-weight:600;cursor:pointer;">
                    Batal
                </button>
                <button type="submit"
                    style="padding:0.55rem 1.1rem;background:#059669;color:#fff;border:none;border-radius:0.5rem;font-size:0.8rem;font-weight:600;cursor:pointer;">
                    Simpan Override
                </button>
            </div>
        </form>
    </div>
</div>

{{-- ── MODAL: EDIT OVERRIDE ───────────────────────────── --}}
<div id="modal-limit-edit" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:60;padding:1.5rem;overflow-y:auto;">
    <div style="max-width:32rem;margin:3rem auto;background:#fff;border-radius:0.875rem;padding:1.5rem;">
        <h3 style="font-size:1rem;font-weight:700;color:#1e293b;margin:0 0 1rem;">Edit Override Batas</h3>
        <form id="form-limit-edit" action="" method="POST">
            @csrf
            @method('PUT')
            <div style="display:flex;flex-direction:column;gap:0.9rem;">
                <div>
                    <label for="edit-limit-role" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.3rem;">Peran <span style="color:#dc2626;">*</span></label>
                    <select id="edit-limit-role" name="role" required
                        style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.4rem;font-size:0.82rem;color:#1e293b;background:#fff;">
                        @foreach($peranTersedia as $peran)
                            <option value="{{ $peran }}">{{ \App\Support\HierarkiRole::label($peran) }} ({{ $peran }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="edit-limit-jenis" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.3rem;">Jenis Transaksi <span style="color:#dc2626;">*</span></label>
                    <select id="edit-limit-jenis" name="jenis_transaksi" required
                        style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.4rem;font-size:0.82rem;color:#1e293b;background:#fff;">
                        @foreach($jenisTersedia as $kodeJenis => $labelJenis)
                            <option value="{{ $kodeJenis }}">{{ $labelJenis }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="edit-limit-per-transaksi" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.3rem;">Limit per Transaksi (Rp)</label>
                    <input type="number" id="edit-limit-per-transaksi" name="limit_per_transaksi" min="0" step="0.01"
                        placeholder="Kosongkan = unlimited, 0 = dilarang"
                        style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.4rem;font-size:0.82rem;color:#1e293b;">
                </div>
                <div>
                    <label for="edit-limit-harian" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.3rem;">Limit Harian (Rp)</label>
                    <input type="number" id="edit-limit-harian" name="limit_harian" min="0" step="0.01"
                        placeholder="Kosongkan = unlimited"
                        style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.4rem;font-size:0.82rem;color:#1e293b;">
                </div>
                <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.8rem;color:#334155;background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.4rem;padding:0.55rem 0.7rem;cursor:pointer;">
                    <input type="checkbox" id="edit-limit-butuh-approval" name="butuh_approval" value="1" style="width:1rem;height:1rem;">
                    Transaksi di atas limit wajib disetujui
                </label>
                <div>
                    <label for="edit-limit-approval" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.3rem;">Peran Pemberi Approval</label>
                    <select id="edit-limit-approval" name="approval_role"
                        style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.4rem;font-size:0.82rem;color:#1e293b;background:#fff;">
                        <option value="">— Tidak ada —</option>
                        @foreach($peranTersedia as $peran)
                            <option value="{{ $peran }}">{{ \App\Support\HierarkiRole::label($peran) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:0.5rem;margin-top:1.25rem;">
                <button type="button" onclick="tutupModalLimit('modal-limit-edit')"
                    style="padding:0.55rem 1rem;background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;border-radius:0.5rem;font-size:0.8rem;font-weight:600;cursor:pointer;">
                    Batal
                </button>
                <button type="submit"
                    style="padding:0.55rem 1.1rem;background:#059669;color:#fff;border:none;border-radius:0.5rem;font-size:0.8rem;font-weight:600;cursor:pointer;">
                    Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const ROUTE_LIMIT_UPDATE = @json(route('pengurus.limit-role.update', ['limit_role' => '__ID__']));

function bukaModalLimit(id) {
    document.getElementById(id).style.display = 'block';
}

function tutupModalLimit(id) {
    document.getElementById(id).style.display = 'none';
}

function bukaModalLimitTambah() {
    document.getElementById('tambah-limit-role').selectedIndex = 0;
    document.getElementById('tambah-limit-jenis').selectedIndex = 0;
    document.getElementById('tambah-limit-per-transaksi').value = '';
    document.getElementById('tambah-limit-harian').value = '';
    document.getElementById('tambah-limit-approval').value = '';

    const centang = document.querySelector('#modal-limit-tambah input[name="butuh_approval"]');
    if (centang) {
        centang.checked = false;
    }

    bukaModalLimit('modal-limit-tambah');
}

function bukaModalLimitEdit(data) {
    document.getElementById('form-limit-edit').action = ROUTE_LIMIT_UPDATE.replace('__ID__', data.id);
    document.getElementById('edit-limit-role').value = data.role ?? '';
    document.getElementById('edit-limit-jenis').value = data.jenis_transaksi ?? '';
    document.getElementById('edit-limit-per-transaksi').value = data.limit_per_transaksi ?? '';
    document.getElementById('edit-limit-harian').value = data.limit_harian ?? '';
    document.getElementById('edit-limit-butuh-approval').checked = !! data.butuh_approval;
    document.getElementById('edit-limit-approval').value = data.approval_role ?? '';

    bukaModalLimit('modal-limit-edit');
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        tutupModalLimit('modal-limit-tambah');
        tutupModalLimit('modal-limit-edit');
    }
});
</script>
@endsection
