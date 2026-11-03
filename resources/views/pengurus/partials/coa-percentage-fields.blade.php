{{--
    Parameter Persentase Dinamis Akun CoA (PSAK 101/108).

    Satu sumber kebenaran untuk nisbah bagi hasil, rate margin pembiayaan, dan
    penanda akun yang imbalannya lewat SHU Tahunan. Diubah lewat Master COA;
    modul transaksi membacanya secara real-time (Sukarela, Deposito, Margin).

    @param string     $prefix  'tambah' | 'edit'
    @param \App\Models\Coa|null $coa  Akun yang diedit (null saat tambah)
--}}
@php
    $prefix = $prefix ?? 'tambah';
    $coa    = $coa ?? null;

    $checked     = old('has_percentage', $coa?->has_percentage ?? false) ? true : false;
    $tipe       = old('percentage_type', $coa?->percentage_type);
    $anggota    = old('default_percentage_member', $coa?->default_percentage_member ?? 0);
    $institusi  = old('default_percentage_institution', $coa?->default_percentage_institution ?? 0);
@endphp

<div style="border:1px solid #ddd6fe;background:#faf5ff;border-radius:0.625rem;padding:0.875rem;">
    <div style="font-size:0.8rem;font-weight:700;color:#6d28d9;margin-bottom:0.5rem;">
        Parameter Persentase Dinamis
    </div>
    <div style="font-size:0.7rem;color:#7c3aed;margin-bottom:0.75rem;">
        Dipakai modul transaksi sebagai nilai awal nisbah/margin. Nilai yang sudah
        terikat pada akad lama tidak ikut berubah (snapshot transaksi).
    </div>

    <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.8rem;font-weight:600;color:#475569;cursor:pointer;">
        <input type="checkbox" name="has_percentage" id="{{ $prefix }}HasPercentage" value="1"
               @checked($checked)
               style="width:1rem;height:1rem;cursor:pointer;">
        Terapkan persentase pada akun ini
    </label>

    <div id="{{ $prefix }}PersentaseFields" style="margin-top:0.75rem;">
        <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Tipe Persentase</label>
        <select name="percentage_type" id="{{ $prefix }}PercentageType"
                style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;box-sizing:border-box;">
            <option value="">— Tidak ada —</option>
            @foreach(\App\Models\Coa::PERCENTAGE_LABEL as $nilai => $label)
                <option value="{{ $nilai }}" @selected($tipe === $nilai)>{{ $label }}</option>
            @endforeach
        </select>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-top:0.75rem;">
            <div>
                <label for="{{ $prefix }}PctMember" style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">
                    <span id="{{ $prefix }}LabelAnggota">Nisbah Anggota</span> (%)
                </label>
                <input type="number" name="default_percentage_member" id="{{ $prefix }}PctMember"
                       min="0" max="100" step="0.01" value="{{ $anggota }}"
                       style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;box-sizing:border-box;">
            </div>
            <div id="{{ $prefix }}WrapInstitusi">
                <label for="{{ $prefix }}PctInstitution" style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Nisbah Koperasi (%)</label>
                <input type="number" name="default_percentage_institution" id="{{ $prefix }}PctInstitution"
                       min="0" max="100" step="0.01" value="{{ $institusi }}"
                       style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;box-sizing:border-box;">
            </div>
        </div>

        <div id="{{ $prefix }}PctHint" style="font-size:0.7rem;color:#7c3aed;margin-top:0.5rem;"></div>
    </div>
</div>
