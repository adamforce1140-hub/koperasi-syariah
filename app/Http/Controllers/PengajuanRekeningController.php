<?php

namespace App\Http\Controllers;

use App\Models\Anggota;
use App\Models\JenisSimpanan;
use App\Models\KebijakanKoperasi;
use App\Models\PembayaranSimpanan;
use App\Models\PengajuanRekening;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Rules\NikUnik;
use App\Services\AutoJournalingService;
use App\Services\CddService;
use App\Support\JenisTransaksi;
use App\Support\NomorDokumen;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PengajuanRekeningController extends Controller
{
    // ─── PENGELOLA ─────────────────────────────────────────────────────────

    /** Daftar semua pengajuan (dashboard pengelola) */
    public function index(Request $request)
    {
        $status = $request->query('status', '');
        $query = PengajuanRekening::with(['user', 'pembayaran'])
            ->latest();

        if ($status) {
            $query->statusIn([$status]);
        }

        $pengajuan = $query->paginate(15);
        $counts = [
            'menunggu_verifikasi' => PengajuanRekening::statusIn([PengajuanRekening::STATUS_MENUNGGU_VERIFIKASI])->count(),
            'menunggu_konfirmasi' => PengajuanRekening::statusIn([PengajuanRekening::STATUS_MENUNGGU_KONFIRMASI])->count(),
            'total' => PengajuanRekening::count(),
        ];

        return view('pengelola.pengajuan-rekening.index', compact('pengajuan', 'counts', 'status'));
    }

    /** Detail pengajuan */
    public function show(PengajuanRekening $pengajuan)
    {
        $pengajuan->load(['user', 'pembayaran.konfirmator', 'anggota', 'verifikator']);

        // Batas nominal pembukaan rekening mengikuti parameter kebijakan koperasi
        // (dinamis, dapat diubah pengurus tanpa modifikasi kode inti).
        $batasPembukaan = KebijakanKoperasi::batasPembukaanRekening();

        return view('pengelola.pengajuan-rekening.show', compact('pengajuan', 'batasPembukaan'));
    }

    /** Setujui pengajuan */
    public function setujui(Request $request, PengajuanRekening $pengajuan)
    {
        $request->validate([
            // Batas minimum/maksimum nominal pembukaan rekening bersumber dari
            // parameter kebijakan koperasi (dapat diubah pengurus; tanpa hardcode).
            'nominal_simpanan_awal' => KebijakanKoperasi::aturanNominalPembukaan(),
        ], KebijakanKoperasi::pesanValidasiNominalPembukaan('Nominal simpanan awal'));

        $pengajuan->update([
            'status' => PengajuanRekening::STATUS_MENUNGGU_PEMBAYARAN,
            'nominal_simpanan_awal' => $request->nominal_simpanan_awal,
            'diverifikasi_oleh' => Auth::id(),
            'waktu_verifikasi' => now(),
        ]);

        return redirect()->route('pengelola.pengajuan-rekening.show', $pengajuan)
            ->with('success', 'Pengajuan disetujui. Nasabah diminta melakukan pembayaran simpanan awal.');
    }

    /** Tolak pengajuan */
    public function tolak(Request $request, PengajuanRekening $pengajuan)
    {
        $request->validate([
            'alasan_tolak' => 'required|string|max:500',
        ]);

        $pengajuan->update([
            'status' => PengajuanRekening::STATUS_DITOLAK,
            'alasan_tolak' => $request->alasan_tolak,
            'diverifikasi_oleh' => Auth::id(),
            'waktu_verifikasi' => now(),
        ]);

        return redirect()->route('pengelola.pengajuan-rekening.show', $pengajuan)
            ->with('success', 'Pengajuan ditolak.');
    }

    /** Konfirmasi pembayaran & aktivasi rekening */
    public function konfirmasiPembayaran(Request $request, PengajuanRekening $pengajuan)
    {
        $pembayaran = $pengajuan->pembayaran;
        if (! $pembayaran) {
            return back()->with('error', 'Data pembayaran tidak ditemukan.');
        }

        // Idempotensi: konfirmasi ganda (klik dua kali / retry jaringan) tidak
        // boleh menerbitkan rekening kedua dan mencatat setoran awal dua kali.
        if ($pengajuan->isStatus(PengajuanRekening::STATUS_AKTIF)) {
            return redirect()->route('pengelola.pengajuan-rekening.show', $pengajuan)
                ->with('error', 'Pengajuan ini sudah aktif. Konfirmasi pembayaran tidak diproses ulang.');
        }

        try {
            DB::transaction(function () use (&$pengajuan, $pembayaran) {
                // Kunci baris pengajuan agar dua permintaan bersamaan tidak sama-sama
                // lolos pemeriksaan status di atas.
                $pengajuan = PengajuanRekening::whereKey($pengajuan->id)->lockForUpdate()->firstOrFail();

                if ($pengajuan->isStatus(PengajuanRekening::STATUS_AKTIF)) {
                    throw new \RuntimeException('Pengajuan ini sudah aktif — konfirmasi ganda ditolak.');
                }
                // 1. Konfirmasi pembayaran
                $pembayaran->update([
                    'status' => 'Dikonfirmasi',
                    'dikonfirmasi_oleh' => Auth::id(),
                    'waktu_konfirmasi' => now(),
                ]);

                // 2. Buat record Anggota
                // R-04: nomor dari MAX sufiks numerik (bukan count()+1) agar tidak
                // bertabrakan saat ada baris terhapus / pendaftaran bersamaan.
                $noAnggota = Anggota::nomorAnggotaBaru('ANG-'.now()->format('Ym').'-');

                $anggota = Anggota::create(array_merge([
                    'no_anggota' => $noAnggota,
                    'user_id' => $pengajuan->user_id,
                    'nik' => $pengajuan->nik,
                    'nama_lengkap' => $pengajuan->nama_lengkap,
                    'tempat_lahir' => $pengajuan->tempat_lahir,
                    'tanggal_lahir' => $pengajuan->tanggal_lahir,
                    'jenis_kelamin' => $pengajuan->jenis_kelamin,
                    'alamat' => $pengajuan->alamat,
                    'kota' => $pengajuan->kota ?? '-',
                    'provinsi' => $pengajuan->provinsi ?? '-',
                    'no_hp' => $pengajuan->no_hp,
                    'email' => $pengajuan->email,
                    'pekerjaan' => $pengajuan->pekerjaan,
                    'status_anggota' => Anggota::STATUS_ACTIVE,
                    'tanggal_gabung' => now()->toDateString(),
                    'simpanan_pokok' => JenisSimpanan::tipe((string) $pengajuan->jenis_simpanan) === JenisSimpanan::TIPE_POKOK ? $pembayaran->nominal : 0,
                    'simpanan_wajib' => JenisSimpanan::tipe((string) $pengajuan->jenis_simpanan) === JenisSimpanan::TIPE_WAJIB ? $pembayaran->nominal : 0,
                    // OJK-08: data CDD/PEP/PM dibawa dari pengajuan (diisi nasabah saat
                    // mengajukan) dan dihitung ulang oleh CddService saat aktivasi.
                ], app(CddService::class)->terapkan([
                    'pep_status' => $pengajuan->pep_status,
                    'pep_keterangan' => $pengajuan->pep_keterangan,
                    'pemilik_manfaat_nama' => $pengajuan->pemilik_manfaat_nama,
                    'pemilik_manfaat_hubungan' => $pengajuan->pemilik_manfaat_hubungan,
                ], Auth::id())));

                // 3. Generate nomor rekening: KSB-YYYYMM-XXXX (4 digit, sesuai format lama)
                // R-04: berbasis MAX sufiks numerik agar tidak ada tabrakan nomor.
                $noRekening = Rekening::nomorRekeningBaru('KSB-'.now()->format('Ym').'-', 4);

                // Ambil akun legacy yang sesuai JENIS simpanan yang diajukan
                // (ZERO-HARDCODING). Sebelumnya memakai LIKE '%Simpanan%' + fallback
                // `Akun::first()` yang bisa menunjuk akun sembarang.
                $akunId = JenisSimpanan::akunLegacyId((string) $pengajuan->jenis_simpanan);

                if ($akunId === null) {
                    throw new \RuntimeException(
                        'Akun simpanan untuk "'.$pengajuan->jenis_simpanan.'" tidak ditemukan: '
                        .'pastikan jenis simpanan & AkunSeeder tersedia.'
                    );
                }

                $rekening = Rekening::create([
                    'no_rekening' => $noRekening,
                    'anggota_id' => $anggota->id,
                    'akun_id' => $akunId,
                    'jenis_rekening' => $pengajuan->jenis_simpanan,
                    'akad' => 'Wadiah',
                    'saldo' => $pembayaran->nominal,
                    // Jejak nominal pembukaan rekening (tidak berubah saat saldo berjalan berubah).
                    'nominal_pembukaan' => $pembayaran->nominal,
                    'tanggal_buka' => now()->toDateString(),
                    'status_rekening' => 'Aktif',
                ]);

                // 4. Catat transaksi setoran awal
                //
                // Sebelumnya baris ini TIDAK mengisi kolom NOT NULL sehingga insert
                // selalu gagal: `no_transaksi`, `posisi`, dan `jenis_transaksi`
                // (yang notabene string literal) tidak pernah ada. Selain itu
                // `saldo_sebelum`/`saldo_sesudah`/`keterangan` bukan kolom tabel.
                // Kolom yang dipakai sekarang mengikuti skema sebenarnya:
                // saldo awal dicatat pada `saldo_setelah` (konvensi S-2), posisi
                // 'Debet' = uang masuk (sudut pandang kas), dan status diisi
                // eksplisit karena pembayaran sudah dikonfirmasi pengelola.
                $transaksiSetoranAwal = Transaksi::create([
                    'no_transaksi' => NomorDokumen::buat('transaksi'),
                    'rekening_id' => $rekening->id,
                    'anggota_id' => $anggota->id,
                    'jenis_transaksi' => JenisTransaksi::SETORAN_TUNAI,
                    'posisi' => 'Debet',
                    'jumlah' => $pembayaran->nominal,
                    'saldo_setelah' => $pembayaran->nominal,
                    'user_id' => Auth::id(),
                    'dibuat_oleh' => Auth::id(),
                    'sumber_transaksi' => 'Transfer',
                    'jenis_pembayaran' => 'transfer',
                    'status_transaksi' => Transaksi::STATUS_TRANSAKSI_SUKSES,
                    'status' => Transaksi::STATUS_TEKNIS_SUCCESS,
                    'waktu_transaksi' => now(),
                    'catatan' => 'Setoran simpanan awal pembukaan rekening',
                ]);

                // 4b. Posting jurnal setoran awal.
                //
                // Tanpa jurnal, saldo rekening bertambah tetapi kas/bank dan modal
                // simpanan tidak pernah tercatat — neraca jadi tidak seimbang dan
                // rekonsiliasi buku besar melaporkan selisih permanen. Disamakan
                // dengan jalur `CifController` (pembukaan rekening via AO): jurnal
                // gagal = transaksi digagalkan, bukan dibiarkan setengah jadi.
                $jurnal = app(AutoJournalingService::class)->jurnalSetorSimpanan(
                    (float) $pembayaran->nominal,
                    'Setoran awal pembukaan rekening '.$pengajuan->jenis_simpanan
                        .' atas nama '.$anggota->nama_lengkap,
                    (string) $pengajuan->jenis_simpanan,
                    Auth::id(),
                    $transaksiSetoranAwal->id,
                    $rekening->id,
                    $anggota->id
                );

                if (! $jurnal) {
                    throw new \RuntimeException(
                        'Auto-journaling setoran awal gagal: pastikan akun CoA kas & simpanan tersedia.'
                    );
                }

                // 5. Update status pengajuan
                $pengajuan->update([
                    'status' => PengajuanRekening::STATUS_AKTIF,
                    'anggota_id' => $anggota->id,
                    'no_rekening_terbit' => $noRekening,
                ]);
            });
        } catch (\RuntimeException $e) {
            // Kegagalan bisnis pada jalur aktivasi:
            //  - race konfirmasi ganda (baris pengajuan sudah Aktif),
            //  - akun simpanan tidak ditemukan,
            //  - jurnal setoran awal ditolak (JournalPostingException).
            // Semuanya sudah di-rollback oleh DB::transaction, jadi tidak ada
            // data setengah jadi. Kembalikan ke halaman pengajuan dengan pesan
            // yang bisa ditindaklanjuti — bukan halaman 500 generik.
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('pengelola.pengajuan-rekening.show', $pengajuan)
            ->with('success', 'Pembayaran dikonfirmasi. Rekening '.$pengajuan->fresh()->no_rekening_terbit.' berhasil diterbitkan.');
    }

    /** Tolak pembayaran */
    public function tolakPembayaran(Request $request, PengajuanRekening $pengajuan)
    {
        $request->validate(['catatan' => 'required|string|max:500']);

        $pengajuan->pembayaran?->update([
            'status' => 'Ditolak',
            'catatan' => $request->catatan,
        ]);

        $pengajuan->update(['status' => PengajuanRekening::STATUS_MENUNGGU_PEMBAYARAN]);

        return redirect()->route('pengelola.pengajuan-rekening.show', $pengajuan)
            ->with('success', 'Pembayaran ditolak. Nasabah diminta upload ulang bukti.');
    }

    // ─── API MOBILE ────────────────────────────────────────────────────────

    /** API: Buat pengajuan baru dari mobile */
    public function apiStore(Request $request)
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
            'no_hp' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:150',
            'pekerjaan' => 'nullable|string|max:100',
            'jenis_simpanan' => 'required|in:Simpanan Pokok,Simpanan Wajib,Simpanan Sukarela',
            'foto_ktp' => 'nullable|file|image|max:5120',
            'foto_selfie_ktp' => 'nullable|file|image|max:5120',
            // OJK-08: deklarasi PEP & pemilik manfaat saat pengajuan (mobile/API).
            'pep_status' => 'required|in:'.implode(',', Anggota::PEP_PILIHAN),
            'pep_keterangan' => 'nullable|string|max:255',
            'pemilik_manfaat_nama' => 'nullable|string|max:255',
            'pemilik_manfaat_hubungan' => 'nullable|string|max:100',
        ]);

        // Simpan file upload
        if ($request->hasFile('foto_ktp')) {
            $data['foto_ktp'] = $request->file('foto_ktp')->store('pengajuan/ktp', 'dokumen');
        }
        if ($request->hasFile('foto_selfie_ktp')) {
            $data['foto_selfie_ktp'] = $request->file('foto_selfie_ktp')->store('pengajuan/selfie', 'dokumen');
        }

        $data['kode_pengajuan'] = PengajuanRekening::generateKode();
        $data['user_id'] = Auth::id();
        $data['status'] = PengajuanRekening::STATUS_MENUNGGU_VERIFIKASI;

        $pengajuan = PengajuanRekening::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan berhasil dikirim. Menunggu verifikasi admin.',
            'kode_pengajuan' => $pengajuan->kode_pengajuan,
            'status' => $pengajuan->status,
        ], 201);
    }

    /** API: Cek status pengajuan */
    public function apiStatus(Request $request)
    {
        $pengajuan = PengajuanRekening::where('user_id', Auth::id())
            ->latest('id')->first();

        if (! $pengajuan) {
            return response()->json(['success' => false, 'message' => 'Belum ada pengajuan.'], 404);
        }

        return response()->json([
            'success' => true,
            'kode_pengajuan' => $pengajuan->kode_pengajuan,
            'status' => $pengajuan->status,
            'jenis_simpanan' => $pengajuan->jenis_simpanan,
            'nominal_awal' => $pengajuan->nominal_simpanan_awal,
            'no_rekening_terbit' => $pengajuan->no_rekening_terbit,
            'alasan_tolak' => $pengajuan->alasan_tolak,
            'updated_at' => $pengajuan->updated_at,
        ]);
    }

    /** API: Upload bukti pembayaran */
    public function apiUploadBukti(Request $request)
    {
        $request->validate([
            'metode_bayar' => 'required|in:Transfer Bank,Virtual Account,QRIS,Setor Tunai',
            'bukti_bayar' => 'nullable|file|image|max:5120',
        ]);

        $pengajuan = PengajuanRekening::where('user_id', Auth::id())
            ->statusIn([PengajuanRekening::STATUS_MENUNGGU_PEMBAYARAN])
            ->latest('id')->firstOrFail();

        $buktiPath = null;
        if ($request->hasFile('bukti_bayar')) {
            $buktiPath = $request->file('bukti_bayar')->store('pengajuan/bukti', 'dokumen');
        }

        PembayaranSimpanan::updateOrCreate(
            ['pengajuan_id' => $pengajuan->id],
            [
                'nominal' => $pengajuan->nominal_simpanan_awal,
                'metode_bayar' => $request->metode_bayar,
                'bukti_bayar' => $buktiPath,
                'status' => 'Menunggu Konfirmasi',
            ]
        );

        $pengajuan->update(['status' => PengajuanRekening::STATUS_MENUNGGU_KONFIRMASI]);

        return response()->json([
            'success' => true,
            'message' => 'Bukti pembayaran berhasil dikirim. Menunggu konfirmasi teller.',
        ]);
    }
}
