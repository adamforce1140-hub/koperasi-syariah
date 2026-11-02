<?php

namespace App\Http\Controllers;

use App\Models\Anggota;
use App\Models\PengajuanRekening;
use App\Rules\NikUnik;
use Illuminate\Http\Request;

class PengajuanPublikController extends Controller
{
    /** Tampilkan form pengajuan publik */
    public function form()
    {
        return view('pengajuan.form');
    }

    /** Simpan pengajuan dari form publik */
    public function store(Request $request)
    {
        $data = $request->validate([
            'nama_lengkap' => 'required|string|max:150',
            'nik' => ['required', 'string', 'size:16', new NikUnik(PengajuanRekening::class, pesan: 'NIK ini sudah pernah mengajukan. Hubungi petugas jika ada masalah.')],
            'tempat_lahir' => 'nullable|string|max:100',
            'tanggal_lahir' => 'nullable|date',
            'jenis_kelamin' => 'required|in:Laki-laki,Perempuan',
            'alamat' => 'required|string',
            'kota' => 'nullable|string|max:100',
            'provinsi' => 'nullable|string|max:100',
            'no_hp' => 'required|string|max:20',
            'email' => 'nullable|email|max:150',
            'pekerjaan' => 'nullable|string|max:100',
            'jenis_simpanan' => 'required|in:Simpanan Pokok,Simpanan Wajib,Simpanan Sukarela',
            'foto_ktp' => 'required|file|image|max:5120',
            'foto_selfie_ktp' => 'required|file|image|max:5120',
            // OJK-08 (POJK 27/2022, UU 8/2010 Pasal 18): deklarasi PEP & pemilik
            // manfaat wajib disertakan pada pengajuan pembukaan rekening.
            'pep_status' => 'required|in:'.implode(',', Anggota::PEP_PILIHAN),
            'pep_keterangan' => 'nullable|string|max:255',
            'pemilik_manfaat_nama' => 'nullable|string|max:255',
            'pemilik_manfaat_hubungan' => 'nullable|string|max:100',
        ], [
            'nik.size' => 'NIK harus 16 digit.',
            'foto_ktp.required' => 'Foto e-KTP wajib diunggah.',
            'foto_selfie_ktp.required' => 'Foto selfie + KTP wajib diunggah.',
        ]);

        // Simpan file foto
        $data['foto_ktp'] = $request->file('foto_ktp')->store('pengajuan/ktp', 'dokumen');
        $data['foto_selfie_ktp'] = $request->file('foto_selfie_ktp')->store('pengajuan/selfie', 'dokumen');

        $data['kode_pengajuan'] = PengajuanRekening::generateKode();
        $data['status'] = PengajuanRekening::STATUS_MENUNGGU_VERIFIKASI;

        $pengajuan = PengajuanRekening::create($data);

        return redirect()->route('pengajuan-rekening.publik.form')
            ->with('success', 'Pengajuan Anda telah diterima dan sedang menunggu verifikasi admin.')
            ->with('kode', $pengajuan->kode_pengajuan);
    }
}
