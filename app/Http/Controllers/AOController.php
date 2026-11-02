<?php

namespace App\Http\Controllers;

use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\AuditLog;
use App\Models\CatatanPenagihan;
use App\Models\DokumenPengajuan;
use App\Models\JenisSimpanan;
use App\Models\MasterProdukPembiayaan;
use App\Models\Pembiayaan;
use App\Models\PengajuanPembiayaan;
use App\Models\Rekening;
use App\Models\RestrukturisasiRequest;
use App\Models\Transaksi;
use App\Rules\NikUnik;
use App\Services\CddService;
use App\Services\RestrukturisasiService;
use App\Support\PemisahanTugas;
use App\Support\PiiHasher;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AOController extends Controller
{
    // ─── DASHBOARD ────────────────────────────────────────────────────────────
    // Ditangani oleh Dashboard\AoDashboardController@index

    // ─── PEMBIAYAAN: DAFTAR PENGAJUAN ─────────────────────────────────────────
    public function pengajuanIndex(Request $request)
    {
        $search = $request->input('search');
        $status = $request->input('status');
        $user = auth()->user();

        $statusList = [
            'Menunggu' => 'Menunggu',
            'Perlu Revisi' => 'Perlu Revisi (Dari Manajer)',
            'Direview' => 'Direview (Di Manajer)',
            'Diproses' => 'Diproses',
            'Disetujui' => 'Disetujui',
            'Ditolak' => 'Ditolak',
        ];

        $query = PengajuanPembiayaan::with(['anggota', 'produk'])
            ->where(function ($q) use ($user) {
                $q->where('ao_id', $user->id)
                    ->orWhere('created_by', $user->id)
                    ->orWhere('diproses_oleh', $user->id)
                    ->orWhereNull('ao_id');
            });

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('no_pengajuan', 'like', "%{$search}%")
                    ->orWhereHas('anggota', fn ($sub) => $sub
                        ->where('nama_lengkap', 'like', "%{$search}%")
                        ->orWhere('no_anggota', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('status')) {
            $query->statusIn([$request->status]);
        }

        $pengajuan = $query->latest()->paginate(10)->withQueryString();

        $stats = [
            'menunggu' => PengajuanPembiayaan::where(function ($q) use ($user) {
                $q->where('ao_id', $user->id)->orWhere('created_by', $user->id)->orWhere('diproses_oleh', $user->id)->orWhereNull('ao_id');
            })->statusIn([PengajuanPembiayaan::STATUS_MENUNGGU, PengajuanPembiayaan::STATUS_DIREVIEW])->count(),

            'disetujui' => PengajuanPembiayaan::where(function ($q) use ($user) {
                $q->where('ao_id', $user->id)->orWhere('created_by', $user->id)->orWhere('diproses_oleh', $user->id)->orWhereNull('ao_id');
            })->statusIn([PengajuanPembiayaan::STATUS_DISETUJUI])->count(),

            'ditolak' => PengajuanPembiayaan::where(function ($q) use ($user) {
                $q->where('ao_id', $user->id)->orWhere('created_by', $user->id)->orWhere('diproses_oleh', $user->id)->orWhereNull('ao_id');
            })->statusIn([PengajuanPembiayaan::STATUS_DITOLAK])->count(),
        ];

        return view('ao.pengajuan.index', compact('pengajuan', 'stats', 'search', 'status', 'statusList'));
    }

    // ─── PEMBIAYAAN: FORM INPUT PENGAJUAN ─────────────────────────────────────
    public function pengajuanCreate()
    {
        $anggota = Anggota::aktif()->orderBy('nama_lengkap')->get();
        $produk = MasterProdukPembiayaan::where('status_aktif', true)->orderBy('nama_produk')->get();

        return view('ao.pengajuan.create', compact('anggota', 'produk'));
    }

    // ─── PEMBIAYAAN: SIMPAN PENGAJUAN BARU ────────────────────────────────────
    public function pengajuanStore(Request $request)
    {
        $validated = $request->validate([
            'anggota_id' => 'required|exists:anggota,id',
            'produk_id' => 'required|exists:master_produk_pembiayaan,id',
            'sumber_pengajuan' => 'nullable|string|in:Direct/AO,Limpahan Loket/Teller',
            'jenis_akad' => Pembiayaan::aturanValidasiAkad(),
            'jumlah_pengajuan' => 'required|numeric|min:'.Setting::minimalPengajuanPembiayaan(),
            'tenor' => 'required|integer|min:1|max:360',
            'tujuan' => 'nullable|string|max:500',
            'tujuan_pembiayaan' => 'nullable|string|max:500',
            'agunan' => 'nullable|string|max:500',
            'penghasilan_bulanan' => 'nullable|numeric|min:0',
            'pengeluaran_bulanan' => 'nullable|numeric|min:0',
            'jumlah_tanggungan' => 'nullable|integer|min:0',
            'berkas_survei' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        $tujuan = $request->input('tujuan') ?: $request->input('tujuan_pembiayaan');
        if (empty($tujuan)) {
            $tujuan = '-';
        }
        $sumber = $request->input('sumber_pengajuan', 'Direct/AO');

        // Ambil produk master untuk mendapatkan margin_persen yang sah dari kebijakan Pengurus
        $produk = MasterProdukPembiayaan::findOrFail($validated['produk_id']);
        $marginPersen = (float) ($produk->margin_persen ?? Setting::marginDefaultPersen());

        $plafon = (float) $validated['jumlah_pengajuan'];
        $tenor = (int) $validated['tenor'];

        // Hitung total pembiayaan dan angsuran per bulan
        $totalMargin = $plafon * ($marginPersen / 100);
        $totalPembiayaan = $plafon + $totalMargin;
        $angsuranPerBulan = $tenor > 0 ? ($totalPembiayaan / $tenor) : $totalPembiayaan;

        // R-04: berbasis MAX sufiks numerik (bukan count()+1) agar tidak
        // bertabrakan saat ada baris terhapus / pengajuan bersamaan.
        $no = PengajuanPembiayaan::nomorPengajuanBaru('PGJ-'.date('Ymd').'-');

        $pengajuan = PengajuanPembiayaan::create([
            'no_pengajuan' => $no,
            'anggota_id' => $validated['anggota_id'],
            'produk_id' => $validated['produk_id'],
            'jenis_akad' => $validated['jenis_akad'],
            'margin_persen' => $marginPersen,
            'jumlah_pengajuan' => $plafon,
            'total_pembiayaan' => $totalPembiayaan,
            'angsuran_per_bulan' => $angsuranPerBulan,
            'tenor' => $tenor,
            'tujuan' => $tujuan,
            'agunan' => $validated['agunan'] ?? null,
            'penghasilan_bulanan' => $validated['penghasilan_bulanan'] ?? null,
            'pengeluaran_bulanan' => $validated['pengeluaran_bulanan'] ?? null,
            'jumlah_tanggungan' => $validated['jumlah_tanggungan'] ?? 0,
            'status' => PengajuanPembiayaan::STATUS_MENUNGGU,
            'diproses_oleh' => auth()->id(),
            'ao_id' => auth()->id(),
            'created_by' => auth()->id(),
        ]);

        // Opsional simpan berkas survei / agunan ke dokumen PENGAJUAN.
        // Catatan: `dokumen_pembiayaan.pembiayaan_id` ber-FK ke tabel `pembiayaan`,
        // sehingga berkas pengajuan tidak boleh ditulis ke sana (id pengajuan ≠ id pembiayaan).
        if ($request->hasFile('berkas_survei')) {
            $berkas = $request->file('berkas_survei');
            $path = $berkas->store('dokumen-pengajuan/'.$pengajuan->id, 'dokumen');
            DokumenPengajuan::create([
                'pengajuan_id' => $pengajuan->id,
                'jenis_dokumen' => 'survei',
                'nama_file' => $berkas->getClientOriginalName(),
                'path_file' => $path,
                'ukuran_file' => $berkas->getSize(),
                'diupload_oleh' => auth()->id(),
            ]);
        }

        return redirect()->route('ao.pengajuan.index')
            ->with('success', "Pengajuan {$no} (Sumber: {$sumber}) berhasil disimpan.");
    }

    // ─── AJAX: SEARCH ANGGOTA (TOM SELECT AUTOCOMPLETE) ─────────────────────
    public function searchAnggota(Request $request)
    {
        $q = trim($request->input('q', ''));

        $query = Anggota::aktif()
            ->select('id', 'no_anggota', 'no_cif', 'nama_lengkap', 'no_hp')
            ->orderBy('nama_lengkap');

        if ($q !== '') {
            // NIK kini tersimpan sebagai ciphertext (non-deterministik) sehingga
            // tidak dapat dicari dengan LIKE. Pencarian NIK dialihkan ke blind index
            // `nik_hash` (exact-match) — lihat App\Support\PiiHasher (UU PDP No. 27/2022).
            $hashNik = PiiHasher::hash($q);

            $query->where(function ($sub) use ($q, $hashNik) {
                $sub->where('nama_lengkap', 'like', "%{$q}%")
                    ->orWhere('no_anggota', 'like', "%{$q}%")
                    ->orWhere('no_cif', 'like', "%{$q}%")
                    ->orWhere('no_hp', 'like', "%{$q}%");

                if ($hashNik !== null) {
                    $sub->orWhere('nik_hash', $hashNik);
                }
            });
        }

        $results = $query->limit(30)->get()->map(function ($a) {
            $identifier = $a->no_cif ?: $a->no_anggota;
            // NIK tidak pernah ditampilkan (UU PDP No. 27/2022) — cukup nomor HP.
            $extra = $a->no_hp;

            return [
                'id' => $a->id,
                'text' => "{$identifier} - {$a->nama_lengkap}".($extra ? " - {$extra}" : ''),
                'nama' => $a->nama_lengkap,
                'no_anggota' => $a->no_anggota,
                'no_cif' => $a->no_cif,
            ];
        });

        return response()->json(['results' => $results]);
    }

    // ─── AJAX: INFO FINANSIAL ANGGOTA (SALDO + DEPOSITO) ─────────────────────
    public function anggotaFinansial(int $id)
    {
        $anggota = Anggota::with(['rekening'])->findOrFail($id);

        // Simpanan sukarela = rekening bertipe sukarela menurut master produk
        // (termasuk alias historis "Tabungan"/"Wadiah").
        $jenisSukarela = JenisSimpanan::namaRekening(JenisSimpanan::TIPE_SUKARELA);
        $simpananSukarela = $anggota->rekening
            ->filter(fn ($r) => in_array($r->jenis_rekening, $jenisSukarela, true))
            ->where('status_rekening', 'Aktif');

        $totalSimpanan = $simpananSukarela->sum('saldo');
        $rekeningUtama = $simpananSukarela->first();
        $puyaSukarela = $rekeningUtama !== null;

        // Deposito aktif = rekening bertipe berjangka yang belum jatuh tempo
        $jenisDeposito = JenisSimpanan::namaRekening(JenisSimpanan::TIPE_BERJANGKA);
        $deposito = $anggota->rekening
            ->filter(fn ($r) => in_array($r->jenis_rekening, $jenisDeposito, true))
            ->where('status_rekening', 'Aktif')
            ->map(fn ($r) => [
                'id' => $r->id,
                'no_rekening' => $r->no_rekening,
                'saldo' => (float) $r->saldo,
                'saldo_formatted' => 'Rp '.number_format($r->saldo, 0, ',', '.'),
                'jatuh_tempo' => $r->tanggal_jatuh_tempo?->format('d/m/Y') ?? '-',
                'akad' => $r->akad ?? '-',
            ])->values();

        $totalDeposito = $deposito->sum('saldo');

        return response()->json([
            'nama_lengkap' => $anggota->nama_lengkap,
            'no_anggota' => $anggota->no_anggota,
            'total_simpanan' => (float) $totalSimpanan,
            'simpanan_formatted' => 'Rp '.number_format($totalSimpanan, 0, ',', '.'),
            'no_rekening_sukarela' => $puyaSukarela ? $rekeningUtama->no_rekening : null,
            'punya_sukarela' => $puyaSukarela,
            'deposito' => $deposito,
            'punya_deposito' => $deposito->count() > 0,
            'total_deposito' => (float) $totalDeposito,
            'total_deposito_formatted' => $totalDeposito > 0 ? 'Rp '.number_format($totalDeposito, 0, ',', '.') : null,
        ]);
    }

    // ─── AJAX STORE ANGGOTA BARU VIA MODAL AO ───────────────────────────────
    public function quickStoreAnggota(Request $request)
    {
        // B-02: endpoint ini murni AJAX (dipanggil fetch/XHR modal AO). Tanpa
        // `expectsJson()`, galat validasi akan menjadi redirect 302 sehingga
        // klien hanya menerima HTML/redirect, bukan daftar galat per-field.
        // `validate()` di bawah otomatis membalas 422 + JSON setelah ini.
        $request->headers->set('Accept', 'application/json');

        $validated = $request->validate([
            'nama_lengkap' => 'required|string|max:255',
            'nik' => ['required', 'numeric', 'digits:16', new NikUnik(Anggota::class)],
            'jenis_kelamin' => 'required|in:L,P,Laki-laki,Perempuan',
            'pekerjaan' => 'required|string|max:100',
            'no_hp' => 'required|string|max:20',
            'alamat' => 'required|string|max:500',
            // B-02: `kota`/`provinsi` adalah kolom NOT NULL tanpa default pada
            // tabel `anggota`; rute cepat wajib memvalidasinya agar tidak
            // menabrak 500 saat insert (pembanding: CifController).
            'kota' => 'required|string|max:100',
            'provinsi' => 'required|string|max:100',
            'foto_ktp' => 'required|image|mimes:jpeg,png,jpg|max:2048',
            // OJK-08: penilaian PEP & pemilik manfaat saat pendaftaran cepat AO.
            'pep_status' => 'required|in:'.implode(',', Anggota::PEP_PILIHAN),
            'pep_keterangan' => 'nullable|string|max:255',
            'pemilik_manfaat_nama' => 'nullable|string|max:255',
            'pemilik_manfaat_hubungan' => 'nullable|string|max:100',
        ]);

        $fotoKtpPath = null;
        if ($request->hasFile('foto_ktp')) {
            $fotoKtpPath = $request->file('foto_ktp')->store('ktp', 'dokumen');
        }

        // R-04: berbasis MAX sufiks numerik agar tidak ada tabrakan nomor anggota.
        $noAnggota = Anggota::nomorAnggotaBaru('ANG-'.date('Ym').'-');

        try {
            $anggota = Anggota::create(array_merge([
                'no_anggota' => $noAnggota,
                'nama_lengkap' => $validated['nama_lengkap'],
                'nik' => $validated['nik'],
                'foto_ktp' => $fotoKtpPath,
                'jenis_kelamin' => in_array($validated['jenis_kelamin'], ['L', 'Laki-laki']) ? 'Laki-laki' : 'Perempuan',
                'pekerjaan' => $validated['pekerjaan'],
                'no_hp' => $validated['no_hp'],
                'alamat' => $validated['alamat'],
                'kota' => $validated['kota'],
                'provinsi' => $validated['provinsi'],
                'status_anggota' => Anggota::STATUS_ACTIVE,
                'tanggal_gabung' => now(),
                // OJK-08: profil risiko, jenis CDD, & jejak verifikasi (SSOT CddService).
            ], app(CddService::class)->terapkan($validated, auth()->id())));
        } catch (\Throwable $e) {
            // B-02: jangan tinggalkan berkas KTP (dokumen identitas, UU PDP
            // 27/2022) tanpa pemilik bila baris anggota gagal dibuat.
            if ($fotoKtpPath !== null) {
                Storage::disk('dokumen')->delete($fotoKtpPath);
            }

            throw $e;
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Anggota berhasil terdaftar',
            'data' => [
                'id' => $anggota->id,
                'nik' => $anggota->nik,
                'no_anggota' => $anggota->no_anggota,
                'nama_lengkap' => $anggota->nama_lengkap,
            ],
        ]);
    }

    // ─── MONITORING: PENGAJUAN RESTRUKTURISASI ──────────────────────────────
    public function restrukturisasiIndex(Request $request)
    {
        $search = $request->input('search');
        $aoId = auth()->id();

        // Nasabah binaan AO terkait (via pengajuan yang ia buat / proses).
        $pembiayaanBinaan = PengajuanPembiayaan::where(function ($q) use ($aoId) {
            $q->where('ao_id', $aoId)
                ->orWhere('created_by', $aoId)
                ->orWhere('diproses_oleh', $aoId);
        })
            ->whereNotNull('pembiayaan_id')
            ->pluck('pembiayaan_id');

        // Kualitas Kurang Lancar/Diragukan/Macet ATAU keterlambatan > 30 hari
        // → keduanya ekuivalen dengan angsuran belum bayar yang jatuh tempo > 30 hari lalu.
        $batasTanggal = Carbon::today()->subDays(RestrukturisasiService::batasHariTerlambat());

        $query = Pembiayaan::with(['anggota', 'restrukturisasiRequests'])
            ->statusIn([Pembiayaan::STATUS_DICAIRKAN, Pembiayaan::STATUS_AKTIF])
            ->whereIn('id', $pembiayaanBinaan)
            ->whereHas('angsuran', function ($q) use ($batasTanggal) {
                $q->where('status_angsuran', '!=', 'Sudah Dibayar')
                    ->where('tanggal_jatuh_tempo', '<', $batasTanggal);
            })
            // Sembunyikan yang sudah punya pengajuan berjalan / sudah disetujui.
            ->whereDoesntHave('restrukturisasiRequests', function ($q) {
                $q->whereIn('status', [
                    RestrukturisasiRequest::STATUS_PENDING_APPROVAL,
                    RestrukturisasiRequest::STATUS_APPROVED,
                ]);
            })
            ->latest();

        if ($search) {
            $query->whereHas('anggota', fn ($q) => $q
                ->where('nama_lengkap', 'like', "%{$search}%")
                ->orWhere('no_anggota', 'like', "%{$search}%"));
        }

        $list = $query->paginate(15)->withQueryString();

        // Lengkapi kolektibilitas + ringkasan piutang untuk form pengajuan.
        $list->getCollection()->transform(function ($item) {
            $hariTunggakan = RestrukturisasiService::hariTunggakan($item);
            $kolektibilitas = RestrukturisasiService::kolektibilitas($hariTunggakan);

            $item->hari_tunggakan = $hariTunggakan;
            $item->kolektibilitas = $kolektibilitas['label'];
            $item->kualitas = $kolektibilitas['kualitas'];
            $item->kolektibilitas_style = $kolektibilitas['style'];
            $item->ringkasan_restrukturisasi = RestrukturisasiService::ringkasan($item);

            return $item;
        });

        // Riwayat pengajuan restrukturisasi milik AO yang login (semua status).
        $riwayat = RestrukturisasiRequest::with(['pembiayaan.anggota', 'manajer'])
            ->where('ao_id', $aoId)
            ->latest()
            ->limit(20)
            ->get();

        return view('ao.restrukturisasi.index', compact('list', 'search', 'riwayat'));
    }

    // ─── RESTRUKTURISASI: SIMPAN PENGAJUAN AO ─────────────────────────────────
    public function restrukturisasiStore(Request $request)
    {
        $validated = $request->validate([
            'pembiayaan_id' => 'required|exists:pembiayaan,id',
            'skema_restrukturisasi' => 'required|in:Rescheduling,Reconditioning',
            'tenor_baru' => 'nullable|integer|min:1|max:240',
            'grace_period' => 'nullable|integer|min:1|max:24',
            'tanggal_jatuh_tempo_baru' => 'nullable|date',
            'alasan_restrukturisasi' => 'required|string|max:255',
            'kemampuan_bayar_baru' => 'required|numeric|min:1',
            'catatan_ao' => 'nullable|string|max:500',
        ]);

        $pembiayaan = Pembiayaan::with('anggota')->findOrFail($validated['pembiayaan_id']);
        $aoId = auth()->id();

        // Pastikan pembiayaan ini benar nasabah binaan AO yang login.
        $binaan = PengajuanPembiayaan::where(function ($q) use ($aoId) {
            $q->where('ao_id', $aoId)
                ->orWhere('created_by', $aoId)
                ->orWhere('diproses_oleh', $aoId);
        })
            ->where('pembiayaan_id', $pembiayaan->id)
            ->exists();

        if (! $binaan) {
            return back()->with('error', 'Pembiayaan ini bukan nasabah binaan Anda.');
        }

        // Cegah pengajuan ganda.
        $adaPengajuan = RestrukturisasiRequest::where('pembiayaan_id', $pembiayaan->id)
            ->whereIn('status', [
                RestrukturisasiRequest::STATUS_PENDING_APPROVAL,
                RestrukturisasiRequest::STATUS_APPROVED,
            ])
            ->exists();

        if ($adaPengajuan) {
            return back()->with('error', 'Pembiayaan ini sudah memiliki pengajuan restrukturisasi yang sedang/sudah diproses.');
        }

        if ($validated['skema_restrukturisasi'] === RestrukturisasiRequest::SKEMA_RESCHEDULING
            && empty($validated['tenor_baru'])) {
            return back()->with('error', 'Untuk skema Rescheduling, tenor baru wajib diisi.');
        }

        // Syarat kelayakan: kualitas Kol-3/4/5 atau keterlambatan > 30 hari.
        $hariTunggakan = RestrukturisasiService::hariTunggakan($pembiayaan);
        if ($hariTunggakan <= RestrukturisasiService::batasHariTerlambat()) {
            return back()->with('error', 'Pembiayaan ini belum memenuhi syarat restrukturisasi (keterlambatan harus lebih dari '.RestrukturisasiService::batasHariTerlambat().' hari).');
        }

        // Snapshot piutang saat pengajuan — dasar pembagian jadwal baru (tanpa margin tambahan).
        $ringkasan = RestrukturisasiService::ringkasan($pembiayaan);

        // Safeguard 1: tenor baru WAJIB lebih besar dari sisa tenor aktif agar angsuran mengecil.
        $sisaTenor = (int) $ringkasan['tenorSisa'];
        $tenorBaru = (int) ($validated['tenor_baru'] ?? 0);

        if ($tenorBaru > 0 && $tenorBaru <= $sisaTenor) {
            return back()->with('error', 'Tenor baru harus lebih besar dari sisa tenor saat ini untuk memperkecil angsuran.');
        }

        // Safeguard 2: angsuran baru TIDAK BOLEH melebihi kemampuan bayar baru hasil analisa AO.
        $angsuranBaru = $tenorBaru > 0
            ? RestrukturisasiService::simulasiAngsuranBaru($ringkasan['sisaPiutang'], $tenorBaru)
            : $ringkasan['angsuranLama'];

        if ($angsuranBaru > (float) $validated['kemampuan_bayar_baru']) {
            return back()->with('error', 'Angsuran baru (Rp '
                .number_format($angsuranBaru, 0, ',', '.')
                .') tidak boleh lebih besar dari kemampuan bayar baru (Rp '
                .number_format((float) $validated['kemampuan_bayar_baru'], 0, ',', '.').').');
        }

        RestrukturisasiRequest::create([
            'pembiayaan_id' => $pembiayaan->id,
            'ao_id' => $aoId,
            'skema_restrukturisasi' => $validated['skema_restrukturisasi'],
            'tenor_baru' => $validated['tenor_baru'] ?? null,
            'grace_period' => $validated['grace_period'] ?? null,
            'tanggal_jatuh_tempo_baru' => $validated['tanggal_jatuh_tempo_baru'] ?? null,
            'alasan_restrukturisasi' => $validated['alasan_restrukturisasi'],
            'kemampuan_bayar_baru' => $validated['kemampuan_bayar_baru'],
            'catatan_ao' => $validated['catatan_ao'] ?? null,
            'sisa_pokok_snapshot' => $ringkasan['sisaPokok'],
            'sisa_margin_snapshot' => $ringkasan['sisaMargin'],
            'sisa_piutang_snapshot' => $ringkasan['sisaPiutang'],
            'angsuran_lama_snapshot' => $ringkasan['angsuranLama'],
            'status' => RestrukturisasiRequest::STATUS_PENDING_APPROVAL,
        ]);

        AuditLog::record(
            'Restrukturisasi Pembiayaan',
            $ringkasan['sisaPiutang'],
            $pembiayaan->no_pembiayaan,
            'Pengajuan restrukturisasi '.$validated['skema_restrukturisasi'].' diajukan AO untuk '
            .$pembiayaan->no_pembiayaan.' — sisa piutang Rp '.number_format($ringkasan['sisaPiutang'], 0, ',', '.')
        );

        return redirect()->route('ao.restrukturisasi.index')
            ->with('success', 'Pengajuan restrukturisasi '.$validated['skema_restrukturisasi'].' untuk '
                .$pembiayaan->no_pembiayaan.' berhasil dikirim ke Manajer (menunggu persetujuan).');
    }

    // ─── PEMBIAYAAN: DETAIL ────────────────────────────────────────────────────
    public function pengajuanShow(int $id)
    {
        $item = PengajuanPembiayaan::with(['anggota', 'produk', 'diprosesOleh', 'dokumen'])
            ->find($id);

        if (! $item) {
            $item = Pembiayaan::with(['anggota', 'dokumen'])->findOrFail($id);
        }

        return view('ao.pengajuan.show', compact('item'));
    }

    // ─── PEMBIAYAAN: APPROVE (teruskan ke Manajer) ────────────────────────────
    public function pengajuanApprove(Request $request, int $id)
    {
        $request->validate([
            'catatan_ao' => 'nullable|string|max:1000',
        ]);

        $item = PengajuanPembiayaan::find($id);
        if ($item && PemisahanTugas::dilanggarSalahSatu([$item->ao_id, $item->created_by], auth()->id())) {
            return back()->with('error', PemisahanTugas::PESAN);
        }
        if ($item) {
            $item->update([
                'status' => PengajuanPembiayaan::STATUS_DIREVIEW,
                'diproses_oleh' => auth()->id(),
                'tanggal_diproses' => now(),
                'catatan_pengelola' => $request->catatan_ao,
            ]);

            // Pastikan record Pembiayaan ada agar muncul di antrian manajer
            $pembiayaan = $item->pembiayaan_id
                ? Pembiayaan::find($item->pembiayaan_id)
                : Pembiayaan::where('anggota_id', $item->anggota_id)
                    ->statusIn([Pembiayaan::STATUS_PENDING_AO, Pembiayaan::STATUS_PENDING_MANAJER])
                    // Tie-breaker `id`: created_at hanya berpresisi detik sehingga
                    // `latest()` saja dapat memilih baris TERLAMA.
                    ->latest('id')->first();

            if ($pembiayaan) {
                $pembiayaan->update([
                    'status_pembiayaan' => Pembiayaan::STATUS_PENDING_MANAJER,
                    'direview_oleh' => auth()->id(),
                    'tanggal_direview' => now(),
                    'keterangan' => $request->catatan_ao,
                ]);
            } else {
                // Buat record Pembiayaan baru jika belum ada
                $prefix = 'PMB-'.date('Ym').'-';
                $last = Pembiayaan::where('no_pembiayaan', 'like', $prefix.'%')
                    ->orderByDesc('no_pembiayaan')->value('no_pembiayaan');
                $seq = $last ? (intval(substr($last, -4)) + 1) : 1;
                $defaultAkunId = PengajuanPembiayaan::akunLegacyIdUntukAkad($item->jenis_akad);
                $rekeningId = Rekening::where('anggota_id', $item->anggota_id)->value('id');

                $newPembiayaan = Pembiayaan::create([
                    'no_pembiayaan' => $prefix.str_pad($seq, 4, '0', STR_PAD_LEFT),
                    'anggota_id' => $item->anggota_id,
                    'akun_id' => $defaultAkunId,
                    'rekening_id' => $rekeningId,
                    'akad_pembiayaan' => $item->jenis_akad ?? Pembiayaan::akadDefault(),
                    'tujuan_pembiayaan' => $item->tujuan ?? 'Modal Usaha',
                    'jumlah_pembiayaan' => $item->jumlah_pengajuan ?? 0,
                    // `total_ujroh` = selisih total akad dengan pokok. Wajib diisi
                    // agar tampilan audit akad / rincian margin tidak "Rp 0".
                    'total_ujroh' => Setting::bulatkanUang(max(0.0,
                        (float) ($item->total_pembiayaan ?? $item->jumlah_pengajuan ?? 0)
                        - (float) ($item->jumlah_pengajuan ?? 0))),
                    'margin_nisbah' => $item->margin_persen,
                    'total_pembiayaan' => $item->total_pembiayaan ?? $item->jumlah_pengajuan ?? 0,
                    'sisa_pembiayaan' => $item->total_pembiayaan ?? $item->jumlah_pengajuan ?? 0,
                    'jumlah_angsuran' => $item->angsuran_per_bulan ?? 0,
                    'tenor' => $item->tenor ?? 12,
                    'tanggal_akad' => now()->toDateString(),
                    'tanggal_jatuh_tempo' => now()->addMonths((int) ($item->tenor ?? 12))->toDateString(),
                    'status_pembiayaan' => Pembiayaan::STATUS_PENDING_MANAJER,
                    'keterangan' => $request->catatan_ao ?? 'Diteruskan oleh AO',
                ]);
                $item->update(['pembiayaan_id' => $newPembiayaan->id]);
            }

            return back()->with('success', 'Pengajuan berhasil diteruskan ke Manajer.');
        }

        $pembiayaan = Pembiayaan::findOrFail($id);
        $pembiayaan->update([
            'status_pembiayaan' => Pembiayaan::STATUS_PENDING_MANAJER,
            'direview_oleh' => auth()->id(),
            'tanggal_direview' => now(),
            'keterangan' => $request->catatan_ao,
        ]);

        // Sinkronisasi PengajuanPembiayaan terkait agar tidak muncul lagi di "Menunggu".
        // Tie-breaker `id` wajib: `latest()` = ORDER BY created_at DESC yang hanya
        // berpresisi detik, sehingga dua pengajuan pada detik yang sama dapat
        // membuat baris TERLAMA terpilih.
        $pengajuanTerkait = PengajuanPembiayaan::where('anggota_id', $pembiayaan->anggota_id)
            ->statusIn([PengajuanPembiayaan::STATUS_MENUNGGU])
            ->latest('id')
            ->first();
        if ($pengajuanTerkait) {
            $pengajuanTerkait->update([
                'status' => PengajuanPembiayaan::STATUS_DIREVIEW,
                'diproses_oleh' => auth()->id(),
                'tanggal_diproses' => now(),
            ]);
        }

        return back()->with('success', 'Pengajuan berhasil diteruskan ke Manajer.');
    }

    // ─── PEMBIAYAAN: REJECT ────────────────────────────────────────────────────
    public function pengajuanReject(Request $request, int $id)
    {
        $request->validate([
            'catatan_tolak' => 'required|string|max:1000',
        ]);

        $item = PengajuanPembiayaan::find($id);
        if ($item && PemisahanTugas::dilanggarSalahSatu([$item->ao_id, $item->created_by], auth()->id())) {
            return back()->with('error', PemisahanTugas::PESAN);
        }
        if ($item) {
            $item->update([
                'status' => PengajuanPembiayaan::STATUS_DITOLAK,
                'diproses_oleh' => auth()->id(),
                'tanggal_diproses' => now(),
                'catatan_pengelola' => $request->catatan_tolak,
            ]);

            return back()->with('success', 'Pengajuan berhasil ditolak.');
        }

        $pembiayaan = Pembiayaan::findOrFail($id);
        $pembiayaan->update([
            'status_pembiayaan' => Pembiayaan::STATUS_DITOLAK,
            'direview_oleh' => auth()->id(),
            'tanggal_direview' => now(),
            'keterangan' => $request->catatan_tolak,
        ]);

        return back()->with('success', 'Pengajuan berhasil ditolak.');
    }

    // ─── DOKUMEN: UPLOAD ──────────────────────────────────────────────────────
    public function uploadDokumen(Request $request, int $id)
    {
        $request->validate([
            'jenis_dokumen' => 'required|string|max:100',
            'file_dokumen' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120', // 5MB
        ]);

        $item = PengajuanPembiayaan::findOrFail($id);

        $file = $request->file('file_dokumen');
        $namaFile = $file->getClientOriginalName();
        $path = $file->store("dokumen-pengajuan/{$item->id}", 'dokumen');

        DokumenPengajuan::create([
            'pengajuan_id' => $item->id,
            'jenis_dokumen' => $request->jenis_dokumen,
            'nama_file' => $namaFile,
            'path_file' => $path,
            'ukuran_file' => $file->getSize(),
            'diupload_oleh' => auth()->id(),
        ]);

        return back()->with('success', "Dokumen \"{$namaFile}\" berhasil diunggah.");
    }

    // ─── DOKUMEN: HAPUS ───────────────────────────────────────────────────────
    public function hapusDokumen(int $pengajuanId, int $dokumenId)
    {
        $dokumen = DokumenPengajuan::where('pengajuan_id', $pengajuanId)
            ->findOrFail($dokumenId);

        Storage::disk('dokumen')->delete($dokumen->path_file);
        $dokumen->delete();

        return back()->with('success', 'Dokumen berhasil dihapus.');
    }

    // ─── SURVEI & ANALISIS KELAYAKAN ──────────────────────────────────────────
    public function surveiIndex(Request $request)
    {
        $search = $request->input('search');
        $user = auth()->user();

        $query = PengajuanPembiayaan::with(['anggota', 'produk'])
            ->where(function ($q) use ($user) {
                $q->where('ao_id', $user->id)
                    ->orWhere('created_by', $user->id)
                    ->orWhere('diproses_oleh', $user->id)
                    ->orWhereNull('ao_id');
            })
            // Tampilkan pengajuan baru atau yang dikembalikan oleh Manajer untuk revisi
            ->statusIn([PengajuanPembiayaan::STATUS_MENUNGGU])
            ->whereNull('analisis_5c');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('no_pengajuan', 'like', "%{$search}%")
                    ->orWhereHas('anggota', fn ($sub) => $sub
                        ->where('nama_lengkap', 'like', "%{$search}%")
                        ->orWhere('no_anggota', 'like', "%{$search}%"));
            });
        }

        $pengajuanSurvei = $query->latest()->paginate(15)->withQueryString();

        return view('ao.survei.index', [
            'pengajuan' => $pengajuanSurvei,
            'pengajuanSurvei' => $pengajuanSurvei,
            'search' => $search,
        ]);
    }

    public function surveiStore(Request $request, int $id)
    {
        $validated = $request->validate([
            'character' => 'required|integer|min:1|max:5',
            'capacity' => 'required|integer|min:1|max:5',
            'capital' => 'required|integer|min:1|max:5',
            'collateral' => 'required|integer|min:1|max:5',
            'condition' => 'required|integer|min:1|max:5',
            'rekomendasi' => 'required|in:teruskan,tolak',
            'catatan_survei' => 'nullable|string|max:2000',
        ]);

        $item = PengajuanPembiayaan::with('anggota')->findOrFail($id);

        $analisis5C = [
            'character' => (int) $validated['character'],
            'capacity' => (int) $validated['capacity'],
            'capital' => (int) $validated['capital'],
            'collateral' => (int) $validated['collateral'],
            'condition' => (int) $validated['condition'],
            'skor_total' => array_sum([
                (int) $validated['character'],
                (int) $validated['capacity'],
                (int) $validated['capital'],
                (int) $validated['collateral'],
                (int) $validated['condition'],
            ]),
        ];

        // Jika diteruskan, ubah status ke 'Direview' (atau 'pending_manajer') agar masuk antrean Manajer
        // dan secara otomatis keluar dari antrean survei AO
        $statusBaru = $validated['rekomendasi'] === 'teruskan' ? 'Direview' : 'Ditolak';

        $item->update([
            'analisis_5c' => $analisis5C,
            'catatan_survei' => $validated['catatan_survei'] ?? null,
            'status' => $statusBaru,
            'diproses_oleh' => auth()->id(),
            'tanggal_diproses' => now(),
            'catatan_pengelola' => $validated['catatan_survei'] ?? null,
        ]);

        // Cari record pembiayaan terkait jika ada untuk update status SoD Manajer
        // Atau buatkan record Pembiayaan baru jika belum ada
        $pembiayaan = null;
        if ($item->pembiayaan_id) {
            $pembiayaan = Pembiayaan::find($item->pembiayaan_id);
        }

        if (! $pembiayaan) {
            $pembiayaan = Pembiayaan::where('anggota_id', $item->anggota_id)
                ->statusIn([Pembiayaan::STATUS_PENDING_AO, Pembiayaan::STATUS_PENDING_MANAJER])
                ->latest()
                ->first();
        }

        $statusManajer = $validated['rekomendasi'] === 'teruskan'
            ? Pembiayaan::STATUS_PENDING_MANAJER
            : Pembiayaan::STATUS_DITOLAK;

        if ($pembiayaan) {
            $pembiayaan->update([
                'status_pembiayaan' => $statusManajer,
                'direview_oleh' => auth()->id(),
                'tanggal_direview' => now(),
                'keterangan' => $validated['catatan_survei'] ?? null,
            ]);
            $item->update(['pembiayaan_id' => $pembiayaan->id]);
        } else {
            $prefix = 'PMB-'.date('Ym').'-';
            $last = Pembiayaan::where('no_pembiayaan', 'like', $prefix.'%')->orderByDesc('no_pembiayaan')->value('no_pembiayaan');
            $seq = $last ? (intval(substr($last, -4)) + 1) : 1;
            $noPembiayaan = $prefix.str_pad($seq, 4, '0', STR_PAD_LEFT);

            $defaultAkunId = PengajuanPembiayaan::akunLegacyIdUntukAkad($item->jenis_akad);
            $rekeningId = Rekening::where('anggota_id', $item->anggota_id)->value('id');

            $newPembiayaan = Pembiayaan::create([
                'no_pembiayaan' => $noPembiayaan,
                'anggota_id' => $item->anggota_id,
                'akun_id' => $defaultAkunId,
                'rekening_id' => $rekeningId,
                'akad_pembiayaan' => $item->jenis_akad ?? Pembiayaan::akadDefault(),
                'tujuan_pembiayaan' => $item->tujuan ?? 'Modal Usaha',
                'jumlah_pembiayaan' => $item->jumlah_pengajuan ?? $item->total_pembiayaan ?? 0,
                'total_pembiayaan' => $item->total_pembiayaan ?? $item->jumlah_pengajuan ?? 0,
                'sisa_pembiayaan' => $item->total_pembiayaan ?? $item->jumlah_pengajuan ?? 0,
                'jumlah_angsuran' => $item->angsuran_per_bulan ?? 0,
                'tenor' => $item->tenor ?? 12,
                'tanggal_akad' => now()->toDateString(),
                'tanggal_jatuh_tempo' => now()->addMonths((int) ($item->tenor ?? 12))->toDateString(),
                'status_pembiayaan' => $statusManajer,
                'direview_oleh' => auth()->id(),
                'tanggal_direview' => now(),
                'keterangan' => $validated['catatan_survei'] ?? null,
            ]);

            $item->update(['pembiayaan_id' => $newPembiayaan->id]);
        }

        $namaAnggota = $item->anggota?->nama_lengkap ?? 'Anggota';

        if ($validated['rekomendasi'] === 'teruskan') {
            return back()->with('success', "Hasil survei 5C untuk {$namaAnggota} berhasil disimpan dan diteruskan ke Manajer.");
        } else {
            return back()->with('success', "Hasil survei 5C untuk {$namaAnggota} telah disimpan dengan keputusan ditolak.");
        }
    }

    // ─── TRACKING STATUS KREDIT ───────────────────────────────────────────────
    public function trackingIndex(Request $request)
    {
        $search = $request->input('search');

        $query = PengajuanPembiayaan::with(['anggota', 'produk'])
            ->latest();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('no_pengajuan', 'like', "%{$search}%")
                    ->orWhereHas('anggota', fn ($sub) => $sub
                        ->where('nama_lengkap', 'like', "%{$search}%")
                        ->orWhere('no_anggota', 'like', "%{$search}%"));
            });
        }

        $pengajuan = $query->paginate(15)->withQueryString();

        return view('ao.tracking.index', compact('pengajuan', 'search'));
    }

    // ─── MONITORING: DATA NASABAH BINAAN ──────────────────────────────────────
    public function nasabahIndex(Request $request)
    {
        $search = $request->input('search');

        // Ambil anggota yang punya pembiayaan
        $query = Anggota::with(['pembiayaan'])
            ->whereHas('pembiayaan');

        if ($search) {
            $query->where(fn ($q) => $q
                ->where('nama_lengkap', 'like', "%{$search}%")
                ->orWhere('no_anggota', 'like', "%{$search}%")
                ->orWhere('no_hp', 'like', "%{$search}%"));
        }

        $nasabah = $query->orderBy('nama_lengkap')->paginate(20)->withQueryString();

        return view('ao.nasabah.index', compact('nasabah', 'search'));
    }

    public function nasabahShow(int $id)
    {
        $anggota = Anggota::with([
            'user',
            'rekening',
            'pembiayaan' => fn ($q) => $q->with(['angsuran'])->latest(),
        ])->findOrFail($id);

        // Ambil semua transaksi via anggota_id (mencakup semua jenis termasuk angsuran kas)
        $riwayatTransaksi = Transaksi::where('anggota_id', $id)
            ->with('rekening')
            ->orderByDesc('waktu_transaksi')
            ->limit(20)
            ->get();

        // 'dicairkan' dan 'aktif' sama-sama akad yang sudah cair — lihat
        // Pembiayaan::statusTercair() agar angka tidak berbeda dengan modul lain.
        $totalPlafonAktif = $anggota->pembiayaan
            ->filter(fn ($p) => $p->isStatus(Pembiayaan::statusTercair()))
            ->sum('jumlah_pembiayaan');

        $totalSisaPembiayaan = $anggota->pembiayaan
            ->filter(fn ($p) => $p->isStatus(Pembiayaan::statusTercair()))
            ->sum('sisa_pembiayaan');

        $riwayatPembiayaan = $anggota->pembiayaan;

        return view('ao.nasabah.show', compact(
            'anggota',
            'totalPlafonAktif',
            'totalSisaPembiayaan',
            'riwayatPembiayaan',
            'riwayatTransaksi'
        ));
    }

    // ─── MONITORING: DAFTAR TUNGGAKAN & PENAGIHAN ─────────────────────────────
    public function tunggakanIndex(Request $request)
    {
        $search = $request->input('search');

        // Ambil semua angsuran belum bayar (tanggal_bayar null) yang sudah lewat jatuh tempo
        // berdasarkan tanggal real-time (bukan kolom status_angsuran di DB yang mungkin stale)
        $query = Angsuran::with(['pembiayaan.anggota', 'catatanPenagihan.user'])
            ->whereNull('tanggal_bayar')
            ->whereNotNull('tanggal_jatuh_tempo')
            ->where('tanggal_jatuh_tempo', '<', Carbon::today())
            ->whereHas('pembiayaan');

        if ($search) {
            $query->whereHas('pembiayaan.anggota', fn ($q) => $q
                ->where('nama_lengkap', 'like', "%{$search}%")
                ->orWhere('no_anggota', 'like', "%{$search}%"));
        }

        // Urutkan berdasarkan hari terlambat terbanyak (jatuh tempo paling lama)
        $query->orderBy('tanggal_jatuh_tempo', 'asc');

        $tunggakan = $query->paginate(20)->withQueryString();

        // Hitung total tunggakan per halaman untuk ringkasan
        $totalTunggakan = $query->count();

        return view('ao.tunggakan.index', compact('tunggakan', 'search', 'totalTunggakan'));
    }

    // ─── TUNGGAKAN: SIMPAN CATATAN PENAGIHAN ──────────────────────────────────
    public function tunggakanCatatan(Request $request, int $id)
    {
        $validated = $request->validate(['catatan' => 'required|string|max:1000']);

        $angsuran = Angsuran::findOrFail($id);
        $catatan = trim($validated['catatan']);

        // Simpan sebagai riwayat baru — tidak menimpa catatan penagihan sebelumnya.
        CatatanPenagihan::create([
            'angsuran_id' => $angsuran->id,
            'user_id' => auth()->id(),
            'catatan' => $catatan,
        ]);

        // Kolom angsuran.catatan tetap disinkronkan sebagai catatan terakhir
        // (kompatibel dengan tampilan lain yang membacanya).
        $angsuran->update(['catatan' => $catatan]);

        return back()->with('success', 'Catatan penagihan berhasil disimpan.');
    }

    // ─── TOOLS: SIMULASI ANGSURAN ─────────────────────────────────────────────
    public function simulasiIndex(Request $request)
    {
        $hasil = null;

        if ($request->filled('jumlah') && $request->filled('margin') && $request->filled('tenor')) {
            $jumlah = (float) $request->input('jumlah', 0);
            $margin = (float) $request->input('margin', 0);   // % per tahun
            $tenor = (int) $request->input('tenor', 12);
            $akad = $request->input('akad', Pembiayaan::akadDefault());
            $metode = strtolower($request->input('metode', 'anuitas')); // 'anuitas' atau 'flat'

            if ($metode === 'flat') {
                $totalMargin = $jumlah * ($margin / 100) * ($tenor / 12);
                $totalPembiayaan = $jumlah + $totalMargin;
                $marginPerBulan = $tenor > 0 ? $totalMargin / $tenor : 0;
                $pokokPerBulan = $tenor > 0 ? $jumlah / $tenor : 0;

                // Bulatkan angsuran per bulan ke rupiah penuh; selisih pembulatan
                // diakumulasi lalu dibebankan pada angsuran bulan TERAKHIR agar
                // total pokok pas sama dengan jumlah pembiayaan dan Sisa Pokok
                // pada bulan terakhir tepat Rp 0.
                $pokokPerBulanRata = round($pokokPerBulan);
                $marginPerBulanRata = round($marginPerBulan);

                $selisihPokokTotal = $jumlah - ($pokokPerBulanRata * $tenor);
                $selisihMarginTotal = $totalMargin - ($marginPerBulanRata * $tenor);

                $angsuranPerBulan = $pokokPerBulanRata + $marginPerBulanRata;

                $jadwal = [];
                $sisaPokok = $jumlah;

                for ($i = 1; $i <= $tenor; $i++) {
                    $isTerakhir = ($i === $tenor);

                    $pokok = $isTerakhir ? $pokokPerBulanRata + $selisihPokokTotal : $pokokPerBulanRata;
                    $margin = $isTerakhir ? $marginPerBulanRata + $selisihMarginTotal : $marginPerBulanRata;
                    $totalBulan = $pokok + $margin;

                    $sisaPokok = Setting::bulatkanUang(max(0, $sisaPokok - $pokok));

                    $jadwal[] = [
                        'bulan' => $i,
                        'ke' => $i,
                        'angsuranPokok' => $pokok,
                        'pokok' => $pokok,
                        'margin' => $margin,
                        'angsuranMargin' => $margin,
                        'totalAngsuran' => $totalBulan,
                        'angsuran' => $totalBulan,
                        'sisaPokok' => $sisaPokok,
                        'sisa_pokok' => $sisaPokok,
                    ];
                }
            } else {
                // Default: Anuitas
                $metode = 'anuitas';
                $r = ($margin / 100) / 12; // margin per bulan desimal

                if ($r > 0 && $tenor > 0) {
                    $factor = pow(1 + $r, $tenor);
                    $angsuranPerBulan = $jumlah * ($r * $factor) / ($factor - 1);
                } else {
                    $angsuranPerBulan = $tenor > 0 ? $jumlah / $tenor : 0;
                }

                $jadwal = [];
                $sisaPokok = $jumlah;
                $totalMargin = 0;

                for ($i = 1; $i <= $tenor; $i++) {
                    $marginBulan = $sisaPokok * $r;
                    $pokokBulan = $angsuranPerBulan - $marginBulan;

                    if ($i === $tenor || $pokokBulan > $sisaPokok) {
                        $pokokBulan = $sisaPokok;
                        $totalAngsuranBulan = $pokokBulan + $marginBulan;
                        $sisaPokok = 0;
                    } else {
                        $sisaPokok -= $pokokBulan;
                        $totalAngsuranBulan = $angsuranPerBulan;
                    }

                    $totalMargin += $marginBulan;

                    $jadwal[] = [
                        'bulan' => $i,
                        'ke' => $i,
                        'angsuranPokok' => Setting::bulatkanUang($pokokBulan),
                        'pokok' => Setting::bulatkanUang($pokokBulan),
                        'margin' => Setting::bulatkanUang($marginBulan),
                        'angsuranMargin' => Setting::bulatkanUang($marginBulan),
                        'totalAngsuran' => Setting::bulatkanUang($totalAngsuranBulan),
                        'angsuran' => Setting::bulatkanUang($totalAngsuranBulan),
                        'sisaPokok' => Setting::bulatkanUang(max(0, $sisaPokok)),
                        'sisa_pokok' => Setting::bulatkanUang(max(0, $sisaPokok)),
                    ];
                }

                $totalPembiayaan = $jumlah + $totalMargin;
            }

            $hasil = compact('jumlah', 'margin', 'tenor', 'akad', 'metode',
                'totalMargin', 'totalPembiayaan', 'angsuranPerBulan', 'jadwal');
        }

        return view('ao.simulasi.index', compact('hasil'));
    }
}
