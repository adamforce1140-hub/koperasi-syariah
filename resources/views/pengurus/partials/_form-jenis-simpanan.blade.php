{{-- Field form jenis simpanan — dipakai modal Tambah & Edit.
     Variabel wajib: $prefix (awalan id field), mis. 'form-tambah' / 'form-edit'. --}}
@php
    $prefix = $prefix ?? 'form';
    $isEdit = $isEdit ?? false;
@endphp

<div style="display:flex;flex-direction:column;gap:1rem;">

    <div>
        <label for="{{ $prefix }}-nama" style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
            Nama Jenis Simpanan <span style="color:#dc2626;">*</span>
        </label>
        <select id="{{ $prefix }}-nama" name="nama" required
            style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;background:#fff;">
            <option value="">-- Pilih Jenis --</option>
            @foreach($enumRekening as $namaEnum)
                <option value="{{ $namaEnum }}">{{ $namaEnum }}</option>
            @endforeach
        </select>
        <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
            Pilihan dibatasi pada klasifikasi rekening yang dikenal sistem agar seluruh alur lama tetap kompatibel.
        </span>
    </div>

    <div>
        <label for="{{ $prefix }}-kode_akun" style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
            Kode Akun COA Terkait
        </label>
        <select id="{{ $prefix }}-kode_akun" name="kode_akun"
            style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;background:#fff;">
            <option value="">-- Tanpa Pemetaan Akun --</option>
            @foreach($coas->groupBy('kategori') as $kategori => $daftarAkun)
                <optgroup label="{{ $kategori }}">
                    @foreach($daftarAkun as $akun)
                        <option value="{{ $akun->kode_akun }}">{{ $akun->kode_akun }} — {{ $akun->nama_akun }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
        <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
            Akun inilah yang dikreditkan/didebet saat transaksi jenis simpanan ini dijurnal otomatis.
        </span>
    </div>

    <div>
        <label for="{{ $prefix }}-tipe" style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
            Tipe / Pengelompokan
        </label>
        <select id="{{ $prefix }}-tipe" name="tipe"
            style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;background:#fff;">
            @foreach($labelTipe as $nilaiTipe => $label)
                <option value="{{ $nilaiTipe }}">{{ $label }}</option>
            @endforeach
        </select>
        <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
            Menentukan perilaku alur: <em>berjangka</em> diarahkan ke pembukaan deposito, lainnya ke rekening simpanan.
        </span>
    </div>

    <div>
        <label for="{{ $prefix }}-keterangan" style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
            Keterangan
        </label>
        <input type="text" id="{{ $prefix }}-keterangan" name="keterangan" maxlength="255"
            style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
    </div>

    {{-- ── Konfigurasi dinamis produk (Zero-Hardcoding) ─────────────────── --}}
    <div style="border-top:1px dashed #e2e8f0;padding-top:1rem;display:flex;flex-direction:column;gap:1rem;">
        <span style="font-size:0.72rem;font-weight:700;color:#1d4ed8;text-transform:uppercase;letter-spacing:.04em;">
            Konfigurasi Produk Simpanan
        </span>

        <div>
            <label for="{{ $prefix }}-min_deposit_amount" style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                Nominal Minimal Setoran (Rp)
            </label>
            <input type="number" id="{{ $prefix }}-min_deposit_amount" name="min_deposit_amount" min="0" step="1000" value="0"
                style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;">
            <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                Dipakai sebagai nominal tagihan jatuh tempo Simpanan Wajib (mis. Pokok 100.000, Wajib 50.000).
            </span>
        </div>

        <div>
            <label for="{{ $prefix }}-account_coa_id" style="display:block;font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                Akun COA (Integritas Referensial)
            </label>
            <select id="{{ $prefix }}-account_coa_id" name="account_coa_id"
                style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;background:#fff;">
                <option value="">-- Ikuti Kode Akun di Atas --</option>
                @foreach($coas->groupBy('kategori') as $kategori => $daftarAkun)
                    <optgroup label="{{ $kategori }}">
                        @foreach($daftarAkun as $akun)
                            <option value="{{ $akun->id }}">{{ $akun->kode_akun }} — {{ $akun->nama_akun }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
            <span style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;display:block;">
                Terhubung ke tabel CoA resmi. Bila dipilih, kode akun di atas otomatis diselaraskan.
            </span>
        </div>

        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:0.5rem;padding:0.85rem 1rem;">
            <label style="display:flex;align-items:flex-start;gap:0.75rem;cursor:pointer;">
                <input type="hidden" name="allow_auto_debit" value="0">
                <input type="checkbox" id="{{ $prefix }}-allow_auto_debit" name="allow_auto_debit" value="1"
                    style="margin-top:0.15rem;width:1.05rem;height:1.05rem;cursor:pointer;">
                <span>
                    <span style="display:block;font-size:0.85rem;font-weight:700;color:#92400e;">Izinkan Auto-Debit Pindah Buku</span>
                    <span style="display:block;font-size:0.78rem;color:#78350f;margin-top:0.2rem;">
                        Hanya <strong>Manager/Pengurus</strong> yang berwenang mengaktifkan, dan hanya untuk produk
                        <strong>Simpanan Wajib</strong>. Bila aktif, tagihan bulanan dapat dipotong otomatis dari
                        Simpanan Sukarela anggota yang telah menyetujui (tanpa menyentuh Kas Teller).
                    </span>
                </span>
            </label>
        </div>
    </div>

    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.5rem;padding:0.85rem 1rem;">
        <label style="display:flex;align-items:flex-start;gap:0.75rem;cursor:pointer;">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" id="{{ $prefix }}-is_active" name="is_active" value="1"
                style="margin-top:0.15rem;width:1.05rem;height:1.05rem;cursor:pointer;">
            <span>
                <span style="display:block;font-size:0.85rem;font-weight:700;color:#1e293b;">Aktifkan jenis simpanan ini</span>
                <span style="display:block;font-size:0.78rem;color:#64748b;margin-top:0.2rem;">
                    Jenis nonaktif tidak muncul pada pembukaan rekening baru dan jurnal barunya tidak diposting,
                    namun seluruh riwayat tetap tersimpan.
                </span>
            </span>
        </label>
    </div>

</div>
