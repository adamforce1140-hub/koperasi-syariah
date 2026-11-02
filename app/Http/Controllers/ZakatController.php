<?php

namespace App\Http\Controllers;

use App\Models\Anggota;
use App\Models\Coa as CoaModel;
use App\Models\ZakatTransaksi;
use App\Services\TahunBukuService;
use App\Services\ZakatService;
use App\Support\Coa;
use App\Support\Setting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Modul Zakat, Infak & Sedekah — PSAK 109.
 *
 * Dana zakat dan infak/sedekah adalah dana TITIPAN (amanah), bukan pendapatan
 * koperasi. Karena itu halaman ini disajikan terpisah dari pendapatan
 * operasional dan tidak memengaruhi SHU.
 *
 * Pengurus  : mencatat penerimaan & penyaluran, membuka laporan.
 * DPS       : read-only (pengawasan syariah) + laporan.
 */
class ZakatController extends Controller
{
    /** Halaman utama modul: buku besar + kartu saldo + form pencatatan. */
    public function index(Request $request, ZakatService $zakat)
    {
        // S-05 — Validasi filter tanggal. Sebelumnya nilai mentah dari query
        // string langsung dipakai pada whereDate tanpa penyaringan, sehingga
        // masukan non-tanggal dapat memicu galat SQL / perilaku tak terduga.
        $filter = $request->validate([
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);

        $dari = $filter['dari'] ?? now()->startOfMonth()->toDateString();
        $sampai = $filter['sampai'] ?? now()->toDateString();

        // Z-06 — sebelumnya buku besar dibatasi `limit(200)` sehingga baris lama
        // pada periode padat tidak dapat ditelusuri di UI. Kini dipaginasi agar
        // SELURUH transaksi periode dapat diakses tanpa terpotong.
        $transaksi = ZakatTransaksi::query()
            ->with('pihakRelasi')
            ->whereDate('tanggal', '>=', $dari)
            ->whereDate('tanggal', '<=', $sampai)
            ->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $ringkasan = $zakat->ringkasanSemua($dari, $sampai);

        return view('zakat.index', [
            'dari' => $dari,
            'sampai' => $sampai,
            'transaksi' => $transaksi,
            'ringkasan' => $ringkasan,
            'kategoriTerima' => ZakatTransaksi::KATEGORI_PENERIMAAN,
            'kategoriSalur' => ZakatTransaksi::KATEGORI_PENYALURAN,
            'asnaf' => ZakatService::ASNAF,
            // R-07 (Z-06) — Kas/bank penerimaan & penyaluran wajib dapat dipilih
            // Pengurus. Sebelumnya nilai tersembunyi selalu Kas Teller (1-1001)
            // sehingga setoran via bank tercatat keliru.
            'akunKas' => $this->akunKasList(),
            'kodeKasDefault' => Coa::KAS_TELLER,
            // R-08 (Z-07) — daftar anggota aktif untuk relasi pihak terstruktur.
            'anggotaList' => $this->anggotaList(),
            'readOnly' => $this->readOnly(),
            'validasi' => $zakat->validasi(),
        ]);
    }

    /** Catat penerimaan dana zakat / infak-sedekah dari muzakki atau munfiq. */
    public function store(Request $request, ZakatService $zakat)
    {
        $data = $request->validate([
            'dana' => ['required', 'in:zakat,infak_sedekah'],
            'kategori' => ['required', 'string', 'max:40'],
            // R-08 (Z-07) — `pihak` menjadi opsional bila `pihak_id` (anggota) dipilih.
            // Pemeriksaan "salah satu wajib terisi" dilakukan di service (fail-closed).
            'pihak' => ['nullable', 'string', 'max:150'],
            'pihak_id' => $this->rulesPihakId(),
            'jumlah' => ['required', 'numeric', 'min:0.01'],
            'tanggal' => ['required', 'date'],
            'kode_kas' => $this->rulesKodeKas(),
            'keterangan' => ['nullable', 'string', 'max:500'],
        ], array_merge($this->pesanValidasiKodeKas(), $this->pesanValidasiPihak()));

        try {
            $zakat->terima($data, Auth::id());
        } catch (\Throwable $e) {
            Log::error('ZakatController: gagal mencatat penerimaan dana.', ['pesan' => $e->getMessage()]);

            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Penerimaan dana berhasil dicatat dan jurnal telah diposting.');
    }

    /**
     * Kalkulator hisab zakat maal (PSAK 109 & ketentuan syariah).
     *
     * Menghitung zakat berdasarkan kadar (`zakat_rate`, default 2,5%) dan
     * nishab (85 gram emas × harga emas per gram) yang seluruhnya dapat
     * dikonfigurasi Pengurus — bukan input nominal manual. Bila syarat HAUL
     * diaktifkan (`zakat_haul_aktif`), `tanggal_kepemilikan` menjadi penentu
     * tambahan: harta baru wajib zakat setelah kepemilikan genap satu haul.
     * Mengembalikan JSON agar dapat dipakai form pencatatan maupun API.
     */
    public function hitung(Request $request)
    {
        $data = $request->validate([
            'harta' => ['required', 'numeric', 'min:0'],
            'tanggal_kepemilikan' => ['nullable', 'date'],
        ]);

        $hasil = Setting::hitungZakat(
            (float) $data['harta'],
            $data['tanggal_kepemilikan'] ?? null
        );

        return response()->json([
            'sukses' => true,
            'kena_zakat' => $hasil['kena_zakat'],
            'nishab' => $hasil['nishab'],
            'nishab_terpenuhi' => $hasil['nishab_terpenuhi'],
            'kadar_persen' => $hasil['kadar_persen'],
            'zakat' => $hasil['zakat'],
            'haul_aktif' => $hasil['haul_aktif'],
            'haul_terpenuhi' => $hasil['haul_terpenuhi'],
            'haul_hari' => $hasil['haul_hari'],
            'haul_sejak' => $hasil['haul_sejak'],
            'hari_kepemilikan' => $hasil['hari_kepemilikan'],
            'pesan' => $this->pesanHisab($hasil),
        ]);
    }

    /**
     * Kalkulator zakat PENGHASILAN (profesi) bulanan — pra-isi form penerimaan.
     *
     * Menghitung zakat atas penghasilan neto (bruto − kebutuhan pokok) yang
     * telah mencapai nishab bulanan. Hasil HANYA dipakai mengisi form
     * penerimaan; posting tetap dilakukan manual oleh Pengurus (manual-approve).
     * Mengembalikan JSON agar dapat dipakai form pencatatan maupun API.
     */
    public function hitungPenghasilan(Request $request)
    {
        $data = $request->validate([
            'bruto' => ['required', 'numeric', 'min:0'],
            'kebutuhan_pokok' => ['nullable', 'numeric', 'min:0'],
        ]);

        $hasil = Setting::hitungZakatPenghasilan(
            (float) $data['bruto'],
            (float) ($data['kebutuhan_pokok'] ?? 0)
        );

        return response()->json([
            'sukses' => true,
            'kena_zakat' => $hasil['kena_zakat'],
            'bruto' => $hasil['bruto'],
            'kebutuhan_pokok' => $hasil['kebutuhan_pokok'],
            'penghasilan_neto' => $hasil['penghasilan_neto'],
            'nishab_bulanan' => $hasil['nishab_bulanan'],
            'nishab_terpenuhi' => $hasil['nishab_terpenuhi'],
            'kadar_persen' => $hasil['kadar_persen'],
            'zakat' => $hasil['zakat'],
            'pesan' => $this->pesanHisabPenghasilan($hasil),
        ]);
    }

    /**
     * Pesan hisab zakat penghasilan: membedakan sebab belum wajibnya
     * (neto di bawah nishab bulanan) dengan wajib zakat.
     *
     * @param  array<string, mixed>  $hasil  keluaran Setting::hitungZakatPenghasilan()
     */
    private function pesanHisabPenghasilan(array $hasil): string
    {
        if ($hasil['kena_zakat']) {
            return 'Penghasilan neto telah mencapai nishab bulanan, zakat penghasilan wajib dikeluarkan.';
        }

        return 'Penghasilan neto belum mencapai nishab bulanan, belum wajib zakat penghasilan.';
    }

    /**
     * Susun pesan hisab yang membedakan penyebab belum wajibnya zakat:
     * nishab belum tercapai, haul belum genap, atau tanggal kepemilikan
     * belum diisi saat syarat haul aktif.
     *
     * @param  array<string, mixed>  $hasil  keluaran Setting::hitungZakat()
     */
    private function pesanHisab(array $hasil): string
    {
        if ($hasil['kena_zakat']) {
            return 'Harta telah mencapai nishab dan haul, zakat wajib dikeluarkan.';
        }

        if (! $hasil['nishab_terpenuhi']) {
            return 'Harta belum mencapai nishab, belum wajib zakat.';
        }

        // Nishab tercapai tetapi haul belum dapat dinilai / belum genap.
        if ($hasil['haul_aktif'] && $hasil['hari_kepemilikan'] === null) {
            return 'Syarat haul aktif: isi tanggal kepemilikan harta untuk menilai kewajiban zakat.';
        }

        if ($hasil['haul_aktif'] && ! $hasil['haul_terpenuhi']) {
            return sprintf(
                'Harta telah mencapai nishab, namun haul (kepemilikan %d hari) belum genap — belum wajib zakat.',
                $hasil['haul_hari']
            );
        }

        return 'Harta belum wajib zakat.';
    }

    /** Catat penyaluran dana kepada mustahik / 8 asnaf. */
    public function salurkan(Request $request, ZakatService $zakat)
    {
        $data = $request->validate([
            'dana' => ['required', 'in:zakat,infak_sedekah'],
            // Z-08 — kategori penyaluran divalidasi terhadap daftar sah
            // `KATEGORI_PENYALURAN` (8 asnaf + kategori infak/sedekah) agar
            // tidak ada kategori bebas yang menyulitkan agregasi pelaporan.
            // Batas 8 asnaf khusus zakat tetap ditegakkan di service.
            'kategori' => ['required', 'string', 'max:40', Rule::in(array_keys(ZakatTransaksi::KATEGORI_PENYALURAN))],
            // R-08 (Z-07) — `pihak` opsional bila `pihak_id` (anggota) dipilih.
            'pihak' => ['nullable', 'string', 'max:150'],
            'pihak_id' => $this->rulesPihakId(),
            'jumlah' => ['required', 'numeric', 'min:0.01'],
            'tanggal' => ['required', 'date'],
            'kode_kas' => $this->rulesKodeKas(),
            'keterangan' => ['nullable', 'string', 'max:500'],
        ], array_merge($this->pesanValidasiKodeKas(), $this->pesanValidasiPihak()));

        try {
            $trx = $zakat->salurkan($data, Auth::id());
        } catch (\Throwable $e) {
            Log::error('ZakatController: gagal mencatat penyaluran dana.', ['pesan' => $e->getMessage()]);

            return back()->withInput()->with('error', $e->getMessage());
        }

        // Z-02 — bila jumlah ≥ ambang kebijakan, penyaluran TIDAK langsung
        // diposting; baris dibuat menunggu persetujuan pengurus/manajer lain.
        if ($trx->status === ZakatTransaksi::STATUS_MENUNGGU_PERSETUJUAN) {
            return back()->with('success', "Penyaluran {$trx->no_referensi} diajukan dan menunggu persetujuan pengurus/manajer lain (dual control).");
        }

        return back()->with('success', 'Penyaluran dana berhasil dicatat dan jurnal telah diposting.');
    }

    /**
     * Setujui pengajuan penyaluran dana amanah — dual control (temuan audit Z-02).
     *
     * Penyetuju WAJIB berbeda dari pengaju (pemisahan tugas ditegakkan di
     * service); setelah disetujui, jurnal penyaluran baru diposting.
     */
    public function setujui(ZakatService $zakat, int $id)
    {
        try {
            $trx = $zakat->setujui($id, Auth::id());
        } catch (\Throwable $e) {
            Log::error('ZakatController: gagal menyetujui penyaluran zakat.', [
                'id' => $id,
                'pesan' => $e->getMessage(),
            ]);

            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Penyaluran {$trx->no_referensi} disetujui dan jurnal telah diposting.");
    }

    /**
     * Tolak pengajuan penyaluran dana amanah — dual control (temuan audit Z-02).
     *
     * Pengajuan yang ditolak tidak pernah diposting; penolak WAJIB berbeda dari
     * pengaju (pemisahan tugas ditegakkan di service).
     */
    public function tolak(Request $request, ZakatService $zakat, int $id)
    {
        $data = $request->validate([
            'alasan' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $trx = $zakat->tolak($id, Auth::id(), (string) ($data['alasan'] ?? ''));
        } catch (\Throwable $e) {
            Log::error('ZakatController: gagal menolak penyaluran zakat.', [
                'id' => $id,
                'pesan' => $e->getMessage(),
            ]);

            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Pengajuan penyaluran {$trx->no_referensi} ditolak.");
    }

    /**
     * Batalkan (void) transaksi zakat/infak secara terkendali — temuan audit Z-01.
     *
     * Baris TIDAK dihapus: hanya ditandai `void` + jurnalnya dibalik, sehingga
     * jejak audit tetap utuh dan saldo dana kembali. Pemisahan tugas ditegakkan
     * di service (pembuat tidak boleh membatalkan transaksinya sendiri).
     */
    public function batalkan(Request $request, ZakatService $zakat, int $id)
    {
        $data = $request->validate([
            'alasan' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $trx = $zakat->batalkan($id, Auth::id(), (string) ($data['alasan'] ?? ''));
        } catch (\Throwable $e) {
            Log::error('ZakatController: gagal membatalkan transaksi zakat.', [
                'id' => $id,
                'pesan' => $e->getMessage(),
            ]);

            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Transaksi {$trx->no_referensi} berhasil dibatalkan (void) dan jurnal telah dibalik.");
    }

    /**
     * Laporan Sumber & Penggunaan Dana Zakat / Infak-Sedekah (PSAK 109).
     *
     * Menyajikan saldo awal, penerimaan, penyaluran per asnaf, dan saldo akhir
     * untuk setiap jenis dana — terpisah dari laporan pendapatan koperasi.
     */
    public function laporan(Request $request, ZakatService $zakat)
    {
        // S-05 — Validasi filter tanggal (lihat catatan pada index()).
        $filter = $request->validate([
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);

        // Default periode mengikuti awal TAHUN BUKU (fleksibilitas tahun buku
        // non-kalender), bukan hardcode 1 Januari.
        $tahunBukuSvc = app(TahunBukuService::class);
        $dari = $filter['dari'] ?? $tahunBukuSvc->awalPeriodeStr($tahunBukuSvc->tahunBukuDari(now()));
        $sampai = $filter['sampai'] ?? now()->toDateString();

        $ringkasan = $zakat->ringkasanSemua($dari, $sampai);

        // Rincian penyaluran zakat per asnaf (QS At-Taubah 60) pada periode ini.
        // Baris VOID (Z-01) dikecualikan karena jurnalnya sudah dibalik.
        $perAsnaf = ZakatTransaksi::query()
            ->where('dana', ZakatTransaksi::DANA_ZAKAT)
            ->where('jenis', ZakatTransaksi::JENIS_PENYALURAN)
            ->where('status', ZakatTransaksi::STATUS_POSTED)
            ->whereDate('tanggal', '>=', $dari)
            ->whereDate('tanggal', '<=', $sampai)
            ->selectRaw('kategori, COALESCE(SUM(jumlah), 0) AS total')
            ->groupBy('kategori')
            ->pluck('total', 'kategori');

        // Rincian penerimaan per jenis zakat.
        $perKategori = ZakatTransaksi::query()
            ->where('dana', ZakatTransaksi::DANA_ZAKAT)
            ->where('jenis', ZakatTransaksi::JENIS_PENERIMAAN)
            ->where('status', ZakatTransaksi::STATUS_POSTED)
            ->whereDate('tanggal', '>=', $dari)
            ->whereDate('tanggal', '<=', $sampai)
            ->selectRaw('kategori, COALESCE(SUM(jumlah), 0) AS total')
            ->groupBy('kategori')
            ->pluck('total', 'kategori');

        $mutasi = ZakatTransaksi::query()
            ->with('pihakRelasi')
            ->whereDate('tanggal', '>=', $dari)
            ->whereDate('tanggal', '<=', $sampai)
            ->orderBy('tanggal')
            ->orderBy('id')
            ->get();

        // Rekap hak amil (Fatwa DSN-MUI 08): porsi amil atas penerimaan dana
        // zakat periode ini, disandingkan dengan realisasi penyaluran ke asnaf
        // `amil`. Bersifat informatif (entitlement) — tidak memotong jurnal.
        $dasarHakAmil = (float) ($ringkasan[ZakatTransaksi::DANA_ZAKAT]['penerimaan'] ?? 0);
        $hakAmil = Setting::zakatHakAmil($dasarHakAmil);
        $realisasiAmil = (float) ($perAsnaf['amil'] ?? 0);

        // Z-07 — kontrol realisasi hak amil: tandai bila penyaluran ke asnaf
        // `amil` MELAMPAUI hak yang dihitung dari porsi amil. Nilai tetap
        // informatif (tidak memotong jurnal), namun laporan menampilkan
        // peringatan eksplisit agar Pengurus/DPS dapat menindaklanjuti.
        $hakAmilTerlampaui = $realisasiAmil > $hakAmil;

        return view('zakat.laporan', [
            'dari' => $dari,
            'sampai' => $sampai,
            'ringkasan' => $ringkasan,
            'perAsnaf' => $perAsnaf,
            'perKategori' => $perKategori,
            'asnaf' => ZakatService::ASNAF,
            'kategoriLabel' => ZakatTransaksi::KATEGORI_PENERIMAAN,
            'mutasi' => $mutasi,
            'validasi' => $zakat->validasi(),
            'readOnly' => $this->readOnly(),
            'porsiAmil' => Setting::zakatPorsiAmilPersen(),
            'dasarHakAmil' => $dasarHakAmil,
            'hakAmil' => $hakAmil,
            'realisasiAmil' => $realisasiAmil,
            'hakAmilTerlampaui' => $hakAmilTerlampaui,
        ]);
    }

    /** Ringkasan JSON untuk pembaruan berkala kartu saldo. */
    public function ringkasan(Request $request, ZakatService $zakat)
    {
        // S-05 — Validasi filter tanggal (lihat catatan pada index()).
        $filter = $request->validate([
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);

        $dari = $filter['dari'] ?? now()->startOfMonth()->toDateString();
        $sampai = $filter['sampai'] ?? now()->toDateString();

        return response()->json([
            'ringkasan' => $zakat->ringkasanSemua($dari, $sampai),
            'valid' => $zakat->validasi()['valid'],
            'diperbarui_pada' => now()->toIso8601String(),
        ]);
    }

    /** DPS hanya boleh melihat — form pencatatan disembunyikan. */
    private function readOnly(): bool
    {
        return Auth::user()?->isDPS() ?? false;
    }

    /**
     * R-07 (Z-06) — Daftar akun kas/bank yang sah sebagai sumber atau tujuan
     * dana: Kas Teller (1-1001) & Bank (1-1002) yang aktif. Kas di Brankas
     * (1-1003) sengaja TIDAK disertakan karena bukan sumber pembayaran
     * langsung (lihat `Coa::AKUN_KAS_LIKUID`).
     *
     * @return Collection<int, CoaModel>
     */
    private function akunKasList()
    {
        return CoaModel::where('kategori', 'Aset')
            ->whereIn('kode_akun', Coa::AKUN_KAS_LIKUID)
            ->where('is_active', true)
            ->orderBy('kode_akun')
            ->get();
    }

    /**
     * Aturan validasi `kode_kas`: bila diisi WAJIB akun kas/bank likuid yang
     * aktif. Opsional agar kompatibel dengan pemanggil lama — bila kosong,
     * ZakatService memakai default Kas Teller (1-1001).
     *
     * @return array<int, mixed>
     */
    private function rulesKodeKas(): array
    {
        return [
            'nullable',
            'string',
            Rule::exists('coas', 'kode_akun')->where(function ($query) {
                return $query->whereIn('kode_akun', Coa::AKUN_KAS_LIKUID)
                    ->where('is_active', true);
            }),
        ];
    }

    /**
     * Pesan galat khusus untuk `kode_kas` agar Pengurus memahami pilihan sah
     * (Kas Teller / Bank aktif) alih-alih pesan bawaan "selected kode kas is invalid".
     *
     * @return array<string, string>
     */
    private function pesanValidasiKodeKas(): array
    {
        return [
            'kode_kas.exists' => 'Akun kas tidak valid — pilih Kas Teller (1-1001) atau Bank (1-1002) yang aktif.',
            'kode_kas.string' => 'Akun kas tidak valid — pilih Kas Teller (1-1001) atau Bank (1-1002) yang aktif.',
        ];
    }

    /**
     * R-08 (Z-07) — daftar anggota untuk opsi relasi pihak terstruktur.
     *
     * Memuat anggota aktif maupun yang menunggu setoran awal (PENDING) karena
     * keduanya sah tercatat sebagai muzakki/mustahik, namun TIDAK memuat
     * anggota yang sudah keluar/terminasi agar pilihan master tetap relevan.
     * Kolom yang dipilih dibatasi agar ringan; relasi teks `pihak` tetap
     * tersedia sebagai fallback bagi pihak eksternal.
     *
     * @return Collection<int, Anggota>
     */
    private function anggotaList()
    {
        return Anggota::query()
            ->whereIn('status_anggota', [
                Anggota::STATUS_ACTIVE, 'Aktif',
                Anggota::STATUS_PENDING,
            ])
            ->orderBy('nama_lengkap')
            ->get(['id', 'no_anggota', 'nama_lengkap']);
    }

    /**
     * Aturan validasi `pihak_id`: opsional, namun bila diisi WAJIB menunjuk
     * anggota yang ada. Relasi bersifat opsional (P4 Default Aman) sehingga
     * pemanggil lama tanpa `pihak_id` tetap sah.
     *
     * @return array<int, mixed>
     */
    private function rulesPihakId(): array
    {
        return [
            'nullable',
            'integer',
            Rule::exists('anggota', 'id'),
        ];
    }

    /**
     * Pesan galat khusus untuk relasi pihak terstruktur.
     *
     * @return array<string, string>
     */
    private function pesanValidasiPihak(): array
    {
        return [
            'pihak_id.exists' => 'Anggota yang dipilih tidak ditemukan pada master anggota.',
            'pihak_id.integer' => 'Pilihan anggota tidak valid.',
        ];
    }
}
