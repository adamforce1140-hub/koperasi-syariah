<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Anggota;
use App\Models\AutodebitUsulan;
use App\Models\Coa;
use App\Models\JenisSimpanan;
use App\Models\KebijakanKoperasi;
use App\Models\LimitTransaksiRole;
use App\Models\MasterProdukPembiayaan;
use App\Models\MemberTermination;
use App\Models\Pembiayaan;
use App\Models\PengajuanPembiayaan;
use App\Models\PengajuanRekening;
use App\Models\Rekening;
use App\Models\TazirReliefRequest;
use App\Models\TellerOpname;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\CkpnService;
use App\Services\LaporanKeuanganSyariahService;
use App\Support\HierarkiRole;
use App\Support\JenisTransaksi;
use App\Support\RentangPeriode;
use App\Support\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboard Super Admin — PUNCAK ADMINISTRATIF: peran dengan hierarki
 * tertinggi (level 100, di atas `dps` dan seluruh peran administratif lain).
 * Dasbor ini menyajikan ringkasan tata kelola koperasi secara real-time
 * (mesin denyut), visualisasi grafik, antrean kegiatan yang menunggu
 * persetujuan, serta laporan keuangan syariah yang komprehensif.
 *
 * Catatan tata kelola: Super Admin memegang SELURUH modul administratif
 * (baca/tulis) tetapi tetap BUKAN pengambil keputusan syariah maupun pemutus
 * transaksi. Pengawasan & audit independen tetap milik DPS, dan pemisahan
 * tugas maker-checker operasional (teller→ao→manajer→pengurus) tetap utuh.
 */
class SuperAdminController extends Controller
{
    /**
     * Render penuh dashboard. Grafik (dataGrafik) hanya dihitung sekali di
     * sini agar mesin denyut (ringkas) tidak membebani server dengan puluhan
     * kueri count tiap polling.
     */
    public function index()
    {
        return view('dashboard.super-admin', array_merge($this->data(), $this->dataGrafik()));
    }

    /**
     * Potongan HTML yang disegarkan mesin denyut (real-time tanpa reload
     * penuh). Setiap kunci pada `html` dipetakan ke atribut
     * `data-denyut-sasaran` pada view dashboard.
     */
    public function ringkas(): JsonResponse
    {
        $data = $this->data();

        return response()->json([
            'html' => [
                'stats' => view('dashboard.partials.super-admin-stats', $data)->render(),
                'notifikasi' => view('dashboard.partials.super-admin-notifikasi', $data)->render(),
                'keuangan' => view('dashboard.partials.super-admin-keuangan', $data)->render(),
                'kolektibilitas' => view('dashboard.partials.super-admin-kolektibilitas', $data)->render(),
                'aktivitas' => view('dashboard.partials.super-admin-aktivitas', $data)->render(),
                'sistem' => view('dashboard.partials.super-admin-sistem', $data)->render(),
            ],
            'waktu' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store, must-revalidate');
    }

    /**
     * Laporan Kinerja Seluruh Unit/Cabang — diturunkan dari data yang ada
     * (per produk pembiayaan, per Account Officer, per jenis simpanan). Tidak
     * memakai tabel cabang baru sesuai keputusan ruang lingkup.
     */
    public function kinerjaUnit()
    {
        return view('dashboard.super-admin-kinerja', $this->dataKinerjaUnit());
    }

    /* =====================================================================
     | DATA RINGKASAN — dipakai index() maupun ringkas()
     ===================================================================== */

    /**
     * @return array<string, mixed>
     */
    private function data(): array
    {
        $laporan = app(LaporanKeuanganSyariahService::class);
        $periode = $laporan->periode('', 'akhir_bulan');

        // ── 1. Ringkasan utama ──────────────────────────────────────────
        $totalAnggota = Anggota::count();
        $anggotaAktif = Anggota::aktif()->count();

        $totalAset = $this->totalAset($laporan, $periode);
        $pembiayaanDisalurkan = $this->totalPembiayaanDisalurkan();
        $kolektibilitas = $this->kolektibilitas();

        $totalSimpanan = $this->totalSimpanan();

        // ── 2. Laporan keuangan syariah ringkas ────────────────────────
        $labRugi = $laporan->labaRugi($periode);
        $posisi = $laporan->posisiKeuangan($periode);
        $kepatuhan = $laporan->kepatuhan($periode);

        // ── 3. Antrean persetujuan (notifikasi) ─────────────────────────
        $antrean = $this->antreanPersetujuan();

        // ── 4. Aktivitas transaksi terakhir ─────────────────────────────
        $transaksiTerakhir = Transaksi::with(['rekening.anggota', 'user'])
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        $totalTransaksiHariIni = Transaksi::query()
            ->tap(fn ($q) => RentangPeriode::padaHari($q, 'created_at'))
            ->count();

        return [
            'periode' => $periode,

            // ringkasan
            'totalAnggota' => $totalAnggota,
            'anggotaAktif' => $anggotaAktif,
            'totalAset' => $totalAset,
            'pembiayaanDisalurkan' => $pembiayaanDisalurkan,
            'totalSimpanan' => $totalSimpanan,

            // kolektibilitas
            'kolektibilitas' => $kolektibilitas,
            'totalPembiayaanBerjalan' => $kolektibilitas['total'],

            // keuangan syariah
            'labaRugi' => $labRugi,
            'posisi' => $posisi,
            'kepatuhan' => $kepatuhan,

            // notifikasi / antrean
            'antrean' => $antrean,
            'totalMenunggu' => $antrean['total'],

            // aktivitas
            'transaksiTerakhir' => $transaksiTerakhir,
            'totalTransaksiHariIni' => $totalTransaksiHariIni,

            // pengaturan sistem
            'sistem' => $this->ringkasanSistem(),
        ];
    }

    /**
     * Total aset koperasi dari Posisi Keuangan (PSAK 101, komponen Aset).
     * Dihitung dari mutasi jurnal — satu sumber kebenaran.
     */
    private function totalAset(LaporanKeuanganSyariahService $laporan, array $periode): float
    {
        $aset = Coa::where('kategori', 'Aset')->where('is_active', true)->get();

        return $laporan->jumlahPresisi($aset, $periode['sampaiStr']);
    }

    /**
     * Total pembiayaan yang telah disalurkan (seluruh akad yang sudah cair,
     * termasuk yang macet). Ini mencerminkan portofolio yang benar-benar
     * keluar dari kas koperasi, bukan hanya yang masih berjalan.
     */
    private function totalPembiayaanDisalurkan(): array
    {
        $tercair = Pembiayaan::tercair();
        $berjalan = Pembiayaan::berjalan();

        return [
            'jumlah' => Setting::bulatkanUang((float) $tercair->sum('jumlah_pembiayaan')),
            'akad' => $tercair->count(),
            'berjalan' => Setting::bulatkanUang((float) $berjalan->sum('jumlah_pembiayaan')),
            'akadBerjalan' => $berjalan->count(),
        ];
    }

    /**
     * Tingkat kolektibilitas pembiayaan berjalan: sebaran kol-1..kol-5
     * berdasarkan hari keterlambatan, beserta rasio NPF (kol-3 ke atas).
     */
    private function kolektibilitas(): array
    {
        $hasil = [
            'kol1' => 0.0, 'kol2' => 0.0, 'kol3' => 0.0, 'kol4' => 0.0, 'kol5' => 0.0,
            'jumlah' => ['kol1' => 0, 'kol2' => 0, 'kol3' => 0, 'kol4' => 0, 'kol5' => 0],
            'total' => 0.0,
            'npf' => 0.0,
        ];

        // Kode kolektibilitas efektif diambil dari sumber tunggal CkpnService
        // (menghormati override manajemen namun tetap memakai hari tunggakan
        // nyata sebagai batas bawah kualitas aset — POJK 12/2023).
        $ckpn = app(CkpnService::class);
        $berjalan = Pembiayaan::berjalan()->with('jadwalAngsuran')->get();

        foreach ($berjalan as $p) {
            $kode = strtolower($ckpn->kolektibilitasKode($p));
            if (! isset($hasil[$kode])) {
                continue;
            }
            $sisa = (float) $p->sisa_pembiayaan;
            $hasil[$kode] += $sisa;
            $hasil['jumlah'][$kode]++;
            $hasil['total'] += $sisa;
        }

        $hasil['total'] = Setting::bulatkanUang($hasil['total']);
        foreach (['kol1', 'kol2', 'kol3', 'kol4', 'kol5'] as $k) {
            $hasil[$k] = Setting::bulatkanUang($hasil[$k]);
        }

        if ($hasil['total'] > 0) {
            $npf = $hasil['kol3'] + $hasil['kol4'] + $hasil['kol5'];
            $hasil['npf'] = Setting::bulatkanUang($npf / $hasil['total'] * 100);
        }

        return $hasil;
    }

    /**
     * Total simpanan anggota dari seluruh rekening aktif, dikelompokkan
     * menurut tipe produk simpanan (pokok/wajib/sukarela/berjangka/lain).
     * `Rekening.jenis_rekening` menyimpan NAMA produk; tipenya diturunkan
     * lewat registri tunggal JenisSimpanan agar tidak ada pemetaan lokal.
     */
    private function totalSimpanan(): array
    {
        $rekening = Rekening::aktif()->get();

        $perTipe = [];
        $total = 0.0;

        foreach ($rekening as $r) {
            $tipe = JenisSimpanan::tipe((string) $r->jenis_rekening) ?? JenisSimpanan::TIPE_LAIN;
            $saldo = (float) $r->saldo;
            $total += $saldo;
            $perTipe[$tipe] = ($perTipe[$tipe] ?? 0) + $saldo;
        }

        return [
            'total' => Setting::bulatkanUang($total),
            'rekening' => $rekening->count(),
            'perTipe' => array_map(fn ($v) => Setting::bulatkanUang($v), $perTipe),
        ];
    }

    /**
     * Antrean kegiatan penting yang memerlukan persetujuan/penanganan.
     * Dipakai untuk panel notifikasi Super Admin.
     *
     * @return array{total: int, item: array<int, array{label: string, jumlah: int, route: string|null, ikon: string, warna: string}>}
     */
    private function antreanPersetujuan(): array
    {
        $item = [];

        $tambah = function (string $label, int $jumlah, ?string $route, string $ikon, string $warna) use (&$item) {
            $item[] = compact('label', 'jumlah', 'route', 'ikon', 'warna');
        };

        // Approval pembiayaan yang menunggu keputusan pengurus (puncak operasional).
        if (Schema::hasTable('pembiayaans')) {
            $tambah(
                'Pembiayaan menunggu persetujuan akhir',
                Pembiayaan::query()->whereIn('status', Pembiayaan::statusPascaPersetujuan())->count(),
                'pengurus.pembiayaan.index',
                '💳',
                'blue'
            );
        }

        // Pengajuan pembiayaan baru yang belum tuntas diproses (AO → pengurus).
        if (Schema::hasTable('pengajuan_pembiayaans')) {
            $tambah(
                'Pengajuan pembiayaan baru',
                PengajuanPembiayaan::menunggu()->count(),
                null,
                '📄',
                'amber'
            );
        }

        // Pengajuan rekening baru (verifikasi teller/manajer).
        if (Schema::hasTable('pengajuan_rekenings')) {
            $tambah(
                'Pengajuan pembukaan rekening',
                PengajuanRekening::query()->statusIn([
                    PengajuanRekening::STATUS_MENUNGGU_VERIFIKASI,
                    PengajuanRekening::STATUS_MENUNGGU_PEMBAYARAN,
                    PengajuanRekening::STATUS_MENUNGGU_KONFIRMASI,
                ])->count(),
                'pengelola.pengajuan-rekening.index',
                '🔑',
                'purple'
            );
        }

        // Usulan autodebit menunggu konfirmasi.
        if (Schema::hasTable('autodebit_usulans')) {
            $tambah(
                'Usulan autodebit',
                AutodebitUsulan::menungguKonfirmasi()->count(),
                null,
                '🔁',
                'blue'
            );
        }

        // Permohonan keringanan/tazir menunggu AO.
        if (Schema::hasTable('tazir_relief_requests')) {
            $tambah(
                'Permohonan keringanan (tazir)',
                TazirReliefRequest::pendingAo()->count(),
                null,
                '⚠️',
                'red'
            );
        }

        // Opname kas teller menunggu persetujuan manajer.
        if (Schema::hasTable('teller_opnames')) {
            $tambah(
                'Opname kas menunggu persetujuan',
                TellerOpname::where('status', TellerOpname::STATUS_PENDING)->count(),
                null,
                '🧾',
                'amber'
            );
        }

        // Pengajuan penghentian keanggotaan (exit) menunggu keputusan.
        if (Schema::hasTable('member_terminations')) {
            $tambah(
                'Pengajuan penghentian keanggotaan',
                MemberTermination::menunggu()->count(),
                null,
                '🚪',
                'red'
            );
        }

        $total = array_sum(array_column($item, 'jumlah'));

        return ['total' => $total, 'item' => $item];
    }

    /**
     * Ringkasan konfigurasi sistem: jumlah pengguna per peran, produk aktif,
     * jenis simpanan, dan limit teller — untuk kartu "Pengaturan Sistem".
     */
    private function ringkasanSistem(): array
    {
        $penggunaPerPeran = [];
        if (Schema::hasTable('users')) {
            $penggunaPerPeran = User::query()
                ->select('role', DB::raw('COUNT(*) as jumlah'))
                ->groupBy('role')
                ->pluck('jumlah', 'role')
                ->all();
        }

        // BUG LATEN (diperbaiki): `HierarkiRole::peran()` mengembalikan DAFTAR
        // NAMA peran (`list<string>`), bukan peta slug => meta. Versi lama
        // memakai `foreach (... as $slug => $meta)` lalu membaca
        // `$meta['label']` — dengan $slug berupa indeks integer dan $meta
        // berupa string itu memicu "Illegal string offset 'label'" pada PHP 8.
        // Metadata dibaca dari sumbernya langsung agar label & level benar.
        $metaPeran = (array) (HierarkiRole::konfigurasi()['peran'] ?? []);

        $peran = [];
        foreach (HierarkiRole::peran() as $slug) {
            $peran[$slug] = [
                'label' => HierarkiRole::label($slug),
                'level' => HierarkiRole::level($slug),
                'jumlah' => (int) ($penggunaPerPeran[$slug] ?? 0),
                'meta' => (array) ($metaPeran[$slug] ?? []),
            ];
        }

        return [
            'peran' => $peran,
            'totalPengguna' => array_sum($penggunaPerPeran),
            'produkAktif' => Schema::hasTable('master_produk_pembiayaan')
                ? MasterProdukPembiayaan::where('status_aktif', true)->count()
                : 0,
            'jenisSimpanan' => Schema::hasTable('jenis_simpanans')
                ? JenisSimpanan::semua()->count()
                : 0,
            'limitTeller' => Schema::hasTable('limit_transaksi_role')
                ? LimitTransaksiRole::count()
                : 0,
            'kebijakanAktif' => Schema::hasTable('kebijakan_koperasis')
                ? KebijakanKoperasi::query()->count()
                : 0,
        ];
    }

    /* =====================================================================
     | DATA GRAFIK — hanya pada render penuh (Chart.js)
     ===================================================================== */

    /**
     * @return array<string, mixed>
     */
    private function dataGrafik(): array
    {
        $opsiJson = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

        // (a) Tren 6 bulan: pembiayaan cair vs setoran angsuran.
        $bulanLabels = [];
        $dataPembiayaan = [];
        $dataAngsuran = [];

        for ($i = 5; $i >= 0; $i--) {
            $acuan = now()->copy()->subMonths($i);
            $bulanLabels[] = $acuan->translatedFormat('M Y');

            $dataPembiayaan[] = Setting::bulatkanUang((float) Transaksi::query()
                ->whereIn('jenis_transaksi', JenisTransaksi::cashOut())
                ->tap(fn ($q) => RentangPeriode::padaBulan($q, 'created_at', $acuan->month, $acuan->year))
                ->sum('jumlah'));

            $dataAngsuran[] = Setting::bulatkanUang((float) Transaksi::query()
                ->whereIn('jenis_transaksi', JenisTransaksi::angsuran())
                ->tap(fn ($q) => RentangPeriode::padaBulan($q, 'created_at', $acuan->month, $acuan->year))
                ->sum('jumlah'));
        }

        // (b) Komposisi kolektibilitas (donut).
        $kol = $this->kolektibilitas();
        $urutanKol = ['kol1', 'kol2', 'kol3', 'kol4', 'kol5'];
        $labelKol = [
            'kol1' => 'Lancar (Kol-1)',
            'kol2' => 'Perhatian Khusus (Kol-2)',
            'kol3' => 'Kurang Lancar (Kol-3)',
            'kol4' => 'Diragukan (Kol-4)',
            'kol5' => 'Macet (Kol-5)',
        ];
        $warnaKol = ['#16a34a', '#ca8a04', '#ea580c', '#dc2626', '#7f1d1d'];

        // (c) Sebaran anggota per status.
        $statusAnggota = Anggota::query()
            ->select('status_anggota', DB::raw('COUNT(*) as jumlah'))
            ->groupBy('status_anggota')
            ->pluck('jumlah', 'status_anggota')
            ->all();

        // (d) Pembiayaan per produk (top 6) — untuk grafik batang kinerja unit.
        // Tabel `pembiayaan` tidak menyimpan produk_id; produk diturunkan dari
        // pengajuan_pembiayaan.produk_id (relasi pembiayaan_id) lalu diagregasi
        // di PHP agar tidak bergantung pada join mentah lintas tabel.
        $perProduk = $this->agregatProduk(
            Pembiayaan::tercair()->with('produk')->get()
        )->take(6);

        $produkLabels = $perProduk->pluck('nama')->all();
        $produkData = $perProduk->pluck('total')->all();

        return [
            'grafikBulanLabels' => json_encode($bulanLabels, $opsiJson),
            'grafikPembiayaan' => json_encode($dataPembiayaan, $opsiJson),
            'grafikAngsuran' => json_encode($dataAngsuran, $opsiJson),
            'grafikKolLabel' => json_encode(array_values($labelKol), $opsiJson),
            'grafikKolData' => json_encode(array_map(fn ($k) => $kol[$k], $urutanKol), $opsiJson),
            'grafikKolWarna' => json_encode($warnaKol, $opsiJson),
            'grafikStatusLabel' => json_encode(array_map(fn ($s) => $s ?: 'Lainnya', array_keys($statusAnggota)), $opsiJson),
            'grafikStatusData' => json_encode(array_values($statusAnggota), $opsiJson),
            'grafikProdukLabel' => json_encode($produkLabels, $opsiJson),
            'grafikProdukData' => json_encode($produkData, $opsiJson),
        ];
    }

    /* =====================================================================
     | KINERJA SELURUH UNIT — per produk, per AO, per jenis simpanan
     ===================================================================== */

    /**
     * @return array<string, mixed>
     */
    private function dataKinerjaUnit(): array
    {
        // (a) Kinerja per produk pembiayaan (produk diturunkan via pengajuan).
        $perProduk = $this->agregatProduk(
            Pembiayaan::tercair()->with('produk')->get()
        );

        // (b) Kinerja per Account Officer. Tabel `pembiayaan` tidak menyimpan
        // ao_id; keterkaitan AO ada di pengajuan_pembiayaan.ao_id yang menunjuk
        // ke pembiayaan lewat `pembiayaan_id`.
        $perAO = PengajuanPembiayaan::query()
            ->select(['id', 'ao_id', 'pembiayaan_id'])
            ->whereNotNull('ao_id')
            ->whereNotNull('pembiayaan_id')
            ->with(['ao:id,name', 'pembiayaan:id,jumlah_pembiayaan,status_pembiayaan'])
            ->get()
            ->filter(fn ($p) => $p->pembiayaan && $p->pembiayaan->isStatus(Pembiayaan::statusTercair()))
            ->groupBy('ao_id')
            ->map(function ($rows) {
                $pertama = $rows->first();

                return [
                    'nama' => optional($pertama->ao)->name ?? ('AO #'.$pertama->ao_id),
                    'akad' => $rows->count(),
                    'total' => Setting::bulatkanUang((float) $rows->sum(fn ($p) => (float) $p->pembiayaan->jumlah_pembiayaan)),
                ];
            })
            ->sortByDesc('total')
            ->values();

        // (c) Kinerja per jenis simpanan.
        $perSimpanan = $this->totalSimpanan()['perTipe'];
        $labelTipe = JenisSimpanan::LABEL_TIPE;

        $simpanan = collect($perSimpanan)->map(fn ($saldo, $tipe) => [
            'nama' => $labelTipe[$tipe] ?? ucfirst((string) $tipe),
            'saldo' => Setting::bulatkanUang((float) $saldo),
        ])->sortByDesc('saldo')->values();

        return [
            'perProduk' => $perProduk,
            'perAO' => $perAO,
            'perSimpanan' => $simpanan,
            'ringkasan' => [
                'totalProduk' => $perProduk->count(),
                'totalAO' => $perAO->count(),
                'totalCabangTipe' => $simpanan->count(),
            ],
        ];
    }

    /**
     * Agregasi portofolio pembiayaan yang sudah cair menurut produk.
     *
     * Produk tidak tersimpan di tabel `pembiayaan`, melainkan di
     * `pengajuan_pembiayaan.produk_id`; karena itu dipakai relasi
     * HasOneThrough `Pembiayaan::produk()`. Hasil diurutkan menurun menurut
     * total penyaluran. Pembiayaan tanpa pengajuan/produk dikelompokkan ke
     * "Tanpa Produk" agar totalnya tetap utuh (tidak ada angka yang hilang).
     *
     * @param  Collection<int, Pembiayaan>  $pembiayaan
     * @return Collection<int, array{nama: string, akad: int, total: float}>
     */
    private function agregatProduk(Collection $pembiayaan): Collection
    {
        return $pembiayaan
            ->groupBy(fn (Pembiayaan $p) => optional($p->produk)->nama_produk ?? 'Tanpa Produk')
            ->map(fn ($rows, $nama) => [
                'nama' => (string) $nama,
                'akad' => $rows->count(),
                'total' => Setting::bulatkanUang((float) $rows->sum(fn (Pembiayaan $p) => (float) $p->jumlah_pembiayaan)),
            ])
            ->sortByDesc('total')
            ->values();
    }
}
