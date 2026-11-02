<?php

namespace App\Services;

use App\Models\Anggota;
use App\Models\JenisSimpanan;
use App\Models\MandatorySavingsBill;
use App\Models\Rekening;
use App\Models\ShuDistribution;
use App\Models\Transaksi;
use App\Support\Coa;
use App\Support\NomorSequence;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Kalkulator SHU Akhir Tahun Buku (PSAK 101/109).
 *
 * Dua komponen yang dihitung:
 *
 *  1. JASA MODAL — dibagi proporsional terhadap RATA-RATA SALDO PENGENDAPAN
 *     (`monthly_average_balance`) akun Simpanan Pokok & Simpanan Wajib, lalu
 *     disesuaikan (prorated) dengan `tenure_months` anggota pada tahun buku
 *     berjalan. Anggota yang baru bergabung pertengahan tahun hanya menerima
 *     porsi bulan aktifnya.
 *
 *  2. JASA TRANSAKSI — dibagi proporsional terhadap nilai partisipasi usaha
 *     anggota (angsuran & bagi hasil) sepanjang tahun buku.
 *
 * SISTEM PENAHANAN SHU (HOLD):
 *  Bila anggota masih memiliki tagihan Simpanan Wajib berstatus OVERDUE pada
 *  saat penutupan tahun buku, SELURUH pencairan SHU anggota tersebut ditahan
 *  (`is_hold = true`) sampai tunggakan dilunasi sepenuhnya.
 *
 * Kelas ini murni kalkulasi — tidak menulis jurnal. Eksekusi jurnal &
 * pencairan dilakukan ShuController di dalam satu transaksi database.
 */
class ShuCalculatorService
{
    /**
     * Jenis transaksi yang dihitung sebagai partisipasi usaha anggota.
     *
     * @deprecated Dibaca dari parameter kebijakan lewat
     *             `Setting::jenisPartisipasiShu()`. Konstanta ini dipertahankan
     *             sebagai nilai default & kompatibilitas.
     */
    public const JENIS_TRANSAKSI_PARTISIPASI = [
        'Pembayaran Angsuran',
        'Bayar Angsuran',
        'Bagi Hasil Simpanan Berjangka',
        'Bagi Hasil',
    ];

    /**
     * Jenis rekening yang saldonya menjadi dasar Jasa Modal.
     *
     * Dibaca dari master produk (`JenisSimpanan`) agar rekening lama dengan
     * nama historis tetap terhitung setelah produk di-rename (DYN-10).
     *
     * @return list<string>
     */
    private static function rekeningModal(): array
    {
        return array_values(array_unique(array_merge(
            JenisSimpanan::namaRekening(JenisSimpanan::TIPE_POKOK),
            JenisSimpanan::namaRekening(JenisSimpanan::TIPE_WAJIB)
        )));
    }

    /** Sumber transaksi yang dipakai baris mutasi hasil distribusi SHU. */
    public const SUMBER_TRANSAKSI_SHU = 'Auto';

    /**
     * T-2 (TINGGI) — jalur tunggal pencatatan jurnal. Pelepasan penahanan SHU
     * kini memakai `AutoJournalingService::post()` agar tunduk pada validasi
     * CoA/PSAK 108-109/cut-off/gate transaksi yang sama dengan modul lain.
     */
    public function __construct(
        private AutoJournalingService $journalService,
    ) {}

    /**
     * Hitung dasar pembagian SHU seluruh anggota pada satu tahun buku.
     *
     * @param  string  $tahunBuku  Format bebas yang memuat 4 digit tahun (mis. "2026").
     * @param  string  $namaPeriode  Label periode untuk keterangan (mis. "SHU-T..."), boleh null.
     * @return array{
     *     tahun:int, awal_tahun:Carbon, akhir_tahun:Carbon,
     *     baris:array<int, array<string, mixed>>,
     *     total_bobot_modal:float, total_bobot_transaksi:float,
     *     total_modal:float, total_transaksi:float,
     *     jumlah_anggota:float, jumlah_anggota_hold:float, total_shu_hold:float
     * }
     */
    public function hitungDasar(string $tahunBuku, ?string $namaPeriode = null): array
    {
        preg_match('/\d{4}/', (string) $tahunBuku, $cocok);
        $tahun = (int) ($cocok[0] ?? now()->year);

        // Awal/akhir tahun buku mengikuti parameter kebijakan (fleksibilitas
        // tahun buku non-kalender) — bukan lagi hardcode 1 Jan – 31 Des.
        $tahunBukuSvc = app(TahunBukuService::class);
        $awalTahun = $tahunBukuSvc->awalPeriode($tahun);
        $akhirTahun = $tahunBukuSvc->akhirPeriode($tahun);

        $anggotaList = Anggota::aktif()->orderBy('id')->get();

        $baris = [];
        $totalBobotModal = 0.0;
        $totalBobotTransaksi = 0.0;
        $totalModal = 0.0;
        $totalTransaksi = 0.0;

        foreach ($anggotaList as $anggota) {
            $item = $this->hitungBaris($anggota, $tahun, $awalTahun, $akhirTahun);

            if ($item === null) {
                continue;
            }

            if ($item['total_shu'] <= 0 && $item['total_modal'] <= 0 && $item['bobot_transaksi'] <= 0) {
                continue;
            }

            $totalBobotModal += $item['bobot_modal'];
            $totalBobotTransaksi += $item['bobot_transaksi'];
            $totalModal += $item['total_modal'];
            $totalTransaksi += $item['total_transaksi'];

            $baris[] = $item;
        }

        return [
            'tahun' => $tahun,
            'awal_tahun' => $awalTahun,
            'akhir_tahun' => $akhirTahun,
            'baris' => $baris,
            'total_bobot_modal' => round($totalBobotModal, 4),
            'total_bobot_transaksi' => round($totalBobotTransaksi, 4),
            'total_modal' => Setting::bulatkanUang($totalModal),
            'total_transaksi' => Setting::bulatkanUang($totalTransaksi),
        ];
    }

    /**
     * Hitung dasar SHU satu anggota.
     *
     * @return array<string, mixed>|null null bila anggota belum menjadi anggota
     *                                   pada tahun buku tersebut.
     */
    public function hitungBaris(Anggota $anggota, int $tahun, Carbon $awalTahun, Carbon $akhirTahun): ?array
    {
        $tglGabung = $anggota->tanggalMulaiKeanggotaan();

        // Belum bergabung pada tahun buku ini → tidak berhak SHU tahun ini.
        if ($tglGabung && $tglGabung->copy()->startOfDay()->gt($akhirTahun)) {
            return null;
        }

        $tenureMonths = $anggota->getTenureMonthsAttribute($tahun);
        $rataRataSaldo = $this->rataRataSaldoModal($anggota, $awalTahun, $akhirTahun, $tenureMonths);

        // Bobot Jasa Modal  = rata-rata saldo pengendapan × proporsi masa keanggotaan.
        $bobotModal = round($rataRataSaldo * ($tenureMonths / 12), 4);

        $totalTransaksi = $this->totalPartisipasiTransaksi($anggota, $awalTahun, $akhirTahun);
        $bobotTransaksi = round($totalTransaksi * ($tenureMonths / 12), 4);

        $hold = $this->statusPenahanan($anggota, $akhirTahun);

        return [
            'anggota' => $anggota,
            'anggota_id' => $anggota->id,
            'nama_anggota' => $anggota->nama_lengkap,
            'no_anggota' => $anggota->no_anggota,
            'tgl_gabung' => $tglGabung,
            'tenure_months' => $tenureMonths,
            'monthly_average_balance' => $rataRataSaldo,
            'total_modal' => $rataRataSaldo,
            'total_transaksi' => $totalTransaksi,
            'bobot_modal' => $bobotModal,
            'bobot_transaksi' => $bobotTransaksi,
            'total_shu' => 0.0,
            'shu_jasa_modal' => 0.0,
            'shu_jasa_transaksi' => 0.0,
            'is_hold' => $hold['is_hold'],
            'hold_reason' => $hold['hold_reason'],
        ];
    }

    /**
     * Rata-rata saldo pengendapan Simpanan Pokok + Simpanan Wajib.
     *
     * Saldo dihitung dari mutasi `transaksi` bulanan (end-of-month balance),
     * dirata-ratakan hanya pada bulan-bulan anggota berstatus efektif aktif —
     * inilah `monthly_average_balance` yang menjadi basis Jasa Modal.
     */
    public function rataRataSaldoModal(Anggota $anggota, Carbon $awalTahun, Carbon $akhirTahun, ?int $tenureMonths = null): float
    {
        $tglGabung = $anggota->tanggalMulaiKeanggotaan() ?? $awalTahun;
        $mulai = $tglGabung->copy()->startOfDay()->lt($awalTahun) ? $awalTahun->copy() : $tglGabung->copy()->startOfDay();

        $tenureMonths ??= $anggota->getTenureMonthsAttribute((int) $awalTahun->year);

        if ($tenureMonths <= 0) {
            return 0.0;
        }

        // Saldo awal tahun (akumulasi seluruh mutasi sebelum tahun buku).
        $saldoBerjalan = $this->saldoModalKumulatif($anggota, $awalTahun->copy()->subSecond());

        $total = 0.0;
        $bulan = 0;

        $kursor = $mulai->copy()->startOfMonth();

        while ($kursor->lte($akhirTahun) && $bulan < $tenureMonths) {
            $akhirBulan = $kursor->copy()->endOfMonth();
            if ($akhirBulan->gt($akhirTahun)) {
                $akhirBulan = $akhirTahun->copy();
            }

            $mutasi = $this->mutasiModalAntara($anggota, $kursor->copy()->startOfMonth(), $akhirBulan);
            $saldoBerjalan = Setting::bulatkanUang($saldoBerjalan + $mutasi);

            $total += $saldoBerjalan;
            $bulan++;

            $kursor->addMonthNoOverflow()->startOfMonth();
        }

        return $bulan > 0 ? Setting::bulatkanUang($total / $bulan) : 0.0;
    }

    /**
     * Total nilai partisipasi usaha anggota pada tahun buku (dasar Jasa Transaksi).
     */
    public function totalPartisipasiTransaksi(Anggota $anggota, Carbon $awalTahun, Carbon $akhirTahun): float
    {
        return Setting::bulatkanUang((float) Transaksi::query()
            ->where('anggota_id', $anggota->id)
            ->where('status_transaksi', 'Sukses')
            ->whereIn('jenis_transaksi', Setting::jenisPartisipasiShu())
            ->whereBetween('waktu_transaksi', [$awalTahun, $akhirTahun])
            ->sum('jumlah'));
    }

    /**
     * Status penahanan SHU anggota (HOLD) pada saat penutupan tahun buku.
     *
     * Kebijakan `shu_hold_on_overdue` dibaca REAL-TIME dari Master Settings:
     * bila pengurus menonaktifkannya, tidak ada SHU yang ditahan meski
     * anggota menunggak — perilaku berubah tanpa menyentuh kode.
     *
     * @return array{is_hold:bool, hold_reason:?string}
     */
    public function statusPenahanan(Anggota $anggota, Carbon $akhirTahun): array
    {
        if (! Setting::tahanShuSaatMenunggak()) {
            return ['is_hold' => false, 'hold_reason' => null];
        }

        $tunggakan = $this->tunggakanAnggota($anggota);

        if ($tunggakan->isEmpty()) {
            return ['is_hold' => false, 'hold_reason' => null];
        }

        return [
            'is_hold' => true,
            'hold_reason' => $this->alasanPenahanan($tunggakan),
        ];
    }

    /**
     * Susun alasan penahanan dari koleksi tagihan OVERDUE.
     *
     * Satu sumber teks untuk `statusPenahanan()` dan `ringkasanPenahanan()`
     * agar pesan yang dilihat pengurus selalu identik di kedua jalur.
     *
     * @param  Collection<int, MandatorySavingsBill>  $tunggakan
     */
    private function alasanPenahanan(Collection $tunggakan): string
    {
        $jumlahPeriode = $tunggakan->count();
        $totalNominal = Setting::bulatkanUang((float) $tunggakan->sum('amount'));
        $periodeAwal = $tunggakan->first()->bill_period;
        $periodeAkhir = $tunggakan->last()->bill_period;

        return sprintf(
            'Ditahan: terdapat %d tagihan Simpanan Wajib OVERDUE (%s s/d %s) senilai Rp %s. '
            .'Pencairan SHU dilakukan setelah seluruh tunggakan dilunasi.',
            $jumlahPeriode,
            $periodeAwal,
            $periodeAkhir,
            number_format($totalNominal, 0, ',', '.')
        );
    }

    /**
     * Tagihan Simpanan Wajib yang masih menunggak (OVERDUE) milik anggota.
     *
     * Menjadi SATU-SATUNYA sumber kebenaran penahanan SHU. Begitu seluruh
     * baris OVERDUE dilunasi Teller (status berubah PAID), koleksi ini kosong
     * dan penahanan SHU LEPAS OTOMATIS — tanpa intervensi manual pengurus.
     *
     * @return Collection<int, MandatorySavingsBill>
     */
    public function tunggakanAnggota(Anggota $anggota): Collection
    {
        return $this->tunggakanPerAnggota([$anggota->id])[$anggota->id] ?? collect();
    }

    /**
     * Agregasi tunggakan OVERDUE untuk BANYAK anggota sekaligus.
     *
     * Menggantikan N+1: satu kueri `whereIn(member_id, …)` untuk seluruh
     * anggota, lalu dikelompokkan per anggota. Dipakai jalur ringkasan
     * (dashboard/daftar SHU) yang sebelumnya menembak satu kueri per anggota.
     *
     * @param  list<int|string>  $idAnggota
     * @return array<int|string, Collection<int, MandatorySavingsBill>>
     */
    public function tunggakanPerAnggota(array $idAnggota): array
    {
        if ($idAnggota === []) {
            return [];
        }

        return MandatorySavingsBill::query()
            ->whereIn('member_id', $idAnggota)
            ->where('status', MandatorySavingsBill::STATUS_OVERDUE)
            ->orderBy('bill_period')
            ->get()
            ->groupBy('member_id')
            ->all();
    }

    /**
     * Apakah SHU anggota masih ditahan pada tanggal penutupan buku tertentu.
     * Pintasan untuk kontrol UI (badge/tombol cair) agar tidak perlu membaca
     * struktur array status penahanan secara utuh.
     */
    public function shuDitahan(Anggota $anggota, Carbon $akhirTahun): bool
    {
        return $this->statusPenahanan($anggota, $akhirTahun)['is_hold'];
    }

    /* =====================================================================
     | PELEPASAN PENAHANAN SHU (HOLD RELEASE) — OTOMATIS SAAT LUNAS
     ===================================================================== */

    /** CoA Utang SHU Ditahan — sisi DEBET saat pelepasan. */
    public const COA_SHU_DITAHAN = Coa::SHU_DITAHAN;

    /** CoA Simpanan Sukarela — sisi KREDIT tujuan pencairan. */
    public const COA_SIMPANAN_SUKARELA = Coa::SIMPANAN_SUKARELA;

    /**
     * Lepas penahanan SHU anggota yang tunggakannya sudah BERSIH.
     *
     * Aman dipanggil berulang (idempoten): baris distribusi dengan
     * `is_hold = true` milik anggota tanpa tagihan OVERDUE yang diproses.
     * Penahanan manual (hold_reason tidak menyebut tunggakan) DIKECUALIKAN —
     * hanya penahanan sistemik akibat tunggakan yang boleh dilepas otomatis.
     *
     * @return int Jumlah baris distribusi yang dilepas.
     */
    public function lepasPenahananAnggota(Anggota $anggota): int
    {
        if (! Setting::tahanShuSaatMenunggak()) {
            return 0;
        }

        $terbuka = ShuDistribution::query()
            ->where('anggota_id', $anggota->id)
            ->where('is_hold', true)
            ->where(function ($q) {
                $q->whereNull('hold_reason')
                    ->orWhere('hold_reason', 'like', 'Ditahan:%');
            })
            ->get();

        if ($terbuka->isEmpty()) {
            return 0;
        }

        foreach ($terbuka as $distribusi) {
            $this->cairkanBilaDalamPenahanan($distribusi, $anggota);
        }

        return $terbuka->count();
    }

    /**
     * Reklasifikasi SHU ditahan menjadi hak anggota:
     * DEBET 2-1004 Utang SHU Ditahan / KREDIT 2-1001 Simpanan Sukarela.
     *
     * Tidak ada beban baru — murni pemindahan kewajiban menjadi simpanan
     * anggota yang benar-benar dapat dicairkan. Menandai baris distribusi
     * `is_hold = false` dan mencatat mutasi transaksi untuk jejak audit.
     */
    /**
     * Coba cairkan satu baris SHU yang masih ditahan.
     *
     * Satu-satunya jalur pencairan HOLD (reklasifikasi 2-1004 → 2-1001).
     * Controller WAJIB memanggil method ini, bukan menyalin logikanya:
     * salinan kedua di `ShuController` dulu sempat tertinggal saat klaim
     * atomik dan penomoran dokumen diperbaiki (utang #20/#24).
     *
     * Mengembalikan `true` bila baris benar-benar dicairkan oleh pemanggilan
     * ini, `false` bila baris tidak terklaim (sudah dilepas, sudah pernah
     * dicairkan, atau tidak ditemukan).
     *
     * Pemanggil bertanggung jawab membungkusnya dalam `DB::transaction()`.
     */
    public function cairkanBilaDalamPenahanan(ShuDistribution $distribusi, Anggota $anggota): bool
    {
        return DB::transaction(function () use ($distribusi, $anggota): bool {
            /* BUG-21 — Syarat PEMBAYARAN: baris masih ter-HOLD dan SHU ini
             | BELUM pernah dicairkan (`transaksi_id` kosong). Tanpa syarat ini,
             | melepas HOLD ulang atas baris yang saldonya sudah diisi akan
             | menambah saldo Sukarela DUA KALI. */
            $diklaim = ShuDistribution::whereKey($distribusi->getKey())
                ->where('is_hold', true)
                ->whereNull('transaksi_id')
                ->lockForUpdate()
                ->first();

            if (! $diklaim) {
                return false;
            }

            $nominal = Setting::bulatkanUang((float) $diklaim->total_shu);

            if ($nominal <= 0) {
                $diklaim->update(['is_hold' => false, 'hold_reason' => null]);

                return true;
            }

            // F-06 — pakai akun TERDAFTAR, bukan membuat akun otomatis.
            $coaHold = Coa::terdaftar(self::COA_SHU_DITAHAN, 'pelepasan penahanan SHU', 'account_shu_ditahan_id');

            $coaSukarela = Coa::terdaftar(self::COA_SIMPANAN_SUKARELA, 'pencairan SHU ke Simpanan Sukarela', 'account_simpanan_sukarela_id');

            // BUG-05 — Baris dikunci sebelum read-modify-write saldo agar
            // pelepasan SHU ditahan tidak bertabrakan dengan mutasi lain.
            $rekening = Rekening::query()
                ->where('anggota_id', $anggota->id)
                ->whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_SUKARELA))
                ->where('status_rekening', 'Aktif')
                ->lockForUpdate()
                ->first();

            if (! $rekening) {
                // BUG-18 — `rekening.akun_id` dan `rekening.tanggal_buka`
                // adalah kolom NOT NULL tanpa default. Tanpa keduanya, anggota
                // yang belum punya rekening Sukarela gagal dilepas HOLD-nya
                // (`Field 'akun_id' doesn't have a default value`) sehingga
                // hak SHU-nya macet.
                $akunId = JenisSimpanan::akunLegacyId(JenisSimpanan::namaUtama(JenisSimpanan::TIPE_SUKARELA));

                if ($akunId === null) {
                    throw new \RuntimeException(
                        'Akun legacy "Simpanan Sukarela Anggota" tidak ditemukan — pelepasan SHU ditahan dibatalkan.'
                    );
                }

                $rekening = Rekening::create([
                    'no_rekening' => 'SUK-'.str_pad((string) $anggota->id, 6, '0', STR_PAD_LEFT),
                    'anggota_id' => $anggota->id,
                    'akun_id' => $akunId,
                    'jenis_rekening' => JenisSimpanan::namaUtama(JenisSimpanan::TIPE_SUKARELA),
                    'akad' => 'Wadiah',
                    'saldo' => 0,
                    'batas_minimum' => 0,
                    'tanggal_buka' => now()->toDateString(),
                    'status_rekening' => 'Aktif',
                    'keterangan' => 'Rekening otomatis dibuat saat pelepasan SHU ditahan',
                ]);
            }

            $saldoBaru = Setting::bulatkanUang((float) $rekening->saldo + $nominal);
            $rekening->update(['saldo' => $saldoBaru]);

            $transaksi = Transaksi::create([
                /* BUG-22 — Nomor dokumen dulu `SHU-REL-YmdHis-{anggota}`. Dua
                 | pencairan pada detik yang sama untuk anggota yang sama
                 | menghasilkan nomor identik → `Duplicate entry` pada unique
                 | index `transaksi.no_transaksi` dan saldo sempat bertambah
                 | sebelum transaksi gagal. Kini memakai `NomorSequence`
                 | (SELECT ... FOR UPDATE) lewat prefix yang sama, sehingga
                 | format `SHU-REL-Ymd-000001` tetap kompatibel dengan
                 | pemanggil lama (`like 'SHU-REL-%'`). */
                'no_transaksi' => NomorSequence::berikutnya('SHU-REL-'.date('Ymd').'-'),
                'waktu_transaksi' => now(),
                'rekening_id' => $rekening->id,
                'anggota_id' => $anggota->id,
                'jenis_transaksi' => 'Pencairan SHU Ditahan',
                'posisi' => 'Debet',
                'jumlah' => $nominal,
                'saldo_setelah' => $saldoBaru,
                'user_id' => Auth::id(),
                'sumber_transaksi' => 'SHU',
                'status_transaksi' => 'Sukses',
                'status' => 'success',
                'referensi' => 'SHU-RELEASE-'.$diklaim->shu_period_id,
                'catatan' => 'Pelepasan penahanan SHU setelah tunggakan Simpanan Wajib dilunasi',
            ]);

            // T-2 (TINGGI) — satu jalur posting: pakai kode akun hasil resolusi
            // `Coa::terdaftar()` (menghormati override setting `account_*_id`)
            // agar jurnal tetap tunduk validasi CoA/PSAK 108-109/cut-off/gate
            // transaksi di `AutoJournalingService::post()`.
            $this->journalService->post(
                'Pelepasan SHU Ditahan — '.$anggota->nama_lengkap,
                [
                    ['kode' => $coaHold->kode_akun, 'debet' => $nominal, 'kredit' => 0],
                    ['kode' => $coaSukarela->kode_akun, 'debet' => 0, 'kredit' => $nominal],
                ],
                'SHU',
                $transaksi->id,
                null,
                Auth::id(),
                $rekening->id,
                $anggota->id
            );

            $diklaim->update([
                'is_hold' => false,
                'hold_reason' => null,
                'rekening_id' => $rekening->id,
                'transaksi_id' => $transaksi->id,
            ]);

            return true;
        });
    }

    /**
     * Bagi nominal SHU ke seluruh baris dasar secara proporsional.
     *
     * Mengembalikan baris dengan `shu_jasa_modal` & `shu_jasa_transaksi` terisi.
     * Pembagian memakai metode "largest remainder" dengan satuan SEN agar
     * SUM(hasil) === $nominalJasaModal tepat (jurnal distribusi tetap balance).
     *
     * @param  array<int, array<string, mixed>>  $baris
     * @return array<int, array<string, mixed>>
     */
    public function alokasikan(array $baris, float $nominalJasaModal, float $nominalJasaTransaksi): array
    {
        $bobotModal = array_map(fn ($b) => (float) $b['bobot_modal'], $baris);
        $bobotTransaksi = array_map(fn ($b) => (float) $b['bobot_transaksi'], $baris);

        $alokasiModal = $this->bagiProporsional($bobotModal, Setting::bulatkanUang($nominalJasaModal));
        $alokasiTransaksi = $this->bagiProporsional($bobotTransaksi, Setting::bulatkanUang($nominalJasaTransaksi));

        foreach ($baris as $i => &$b) {
            $b['shu_jasa_modal'] = $alokasiModal[$i] ?? 0.0;
            $b['shu_jasa_transaksi'] = $alokasiTransaksi[$i] ?? 0.0;
            $b['total_shu'] = Setting::bulatkanUang($b['shu_jasa_modal'] + $b['shu_jasa_transaksi']);
        }
        unset($b);

        return $baris;
    }

    /**
     * Bagi sejumlah uang proporsional terhadap bobot tiap penerima dengan
     * pembulatan ke sen menggunakan "largest remainder method".
     *
     * Jaminan: SUM(hasil) === $total (tepat, tanpa selisih sen) — syarat agar
     * jurnal distribusi SHU tetap balance.
     *
     * @param  array<int, float|int>  $bobot
     * @return array<int, float>
     */
    public function bagiProporsional(array $bobot, float $total): array
    {
        $hasil = array_fill(0, count($bobot), 0.0);

        if (empty($bobot) || $total <= 0) {
            return $hasil;
        }

        $jumlahBobot = array_sum(array_map(fn ($b) => max(0, (float) $b), $bobot));

        if ($jumlahBobot <= 0) {
            return $hasil;
        }

        $totalSen = (int) round($total * 100);
        $sisa = $totalSen;
        $pecahan = [];

        foreach ($bobot as $i => $b) {
            $idealSen = $totalSen * (max(0, (float) $b) / $jumlahBobot);
            $dasarSen = (int) floor($idealSen);
            $hasil[$i] = $dasarSen;
            $pecahan[$i] = $idealSen - $dasarSen;
            $sisa -= $dasarSen;
        }

        arsort($pecahan);

        foreach (array_keys($pecahan) as $i) {
            if ($sisa <= 0) {
                break;
            }
            $hasil[$i]++;
            $sisa--;
        }

        return array_map(fn ($sen) => Setting::bulatkanUang($sen / 100), $hasil);
    }

    /* =====================================================================
     | UTILITAS SALDO DARI RIWAYAT TRANSAKSI
     |===================================================================== */

    /**
     * Saldo kumulatif Simpanan Pokok + Simpanan Wajib sampai $sampaiDengan.
     *
     * Rumus mengikuti konvensi saldo anggota: jenis transaksi setoran
     * menambah saldo, penarikan mengurangi saldo.
     */
    public function saldoModalKumulatif(Anggota $anggota, Carbon $sampaiDengan): float
    {
        return $this->mutasiModalAntara($anggota, null, $sampaiDengan);
    }

    /**
     * Mutasi bersih saldo Simpanan Pokok + Simpanan Wajib dalam rentang waktu.
     *
     * @param  Carbon|null  $dari  null = sejak awal riwayat.
     */
    public function mutasiModalAntara(Anggota $anggota, ?Carbon $dari, Carbon $sampai): float
    {
        $rekeningIds = Rekening::withTrashed()
            ->where('anggota_id', $anggota->id)
            ->whereIn('jenis_rekening', self::rekeningModal())
            ->pluck('id');

        if ($rekeningIds->isEmpty()) {
            return 0.0;
        }

        $query = Transaksi::withTrashed()
            ->whereIn('rekening_id', $rekeningIds)
            ->where('status_transaksi', 'Sukses')
            ->where('waktu_transaksi', '<=', $sampai);

        if ($dari) {
            $query->where('waktu_transaksi', '>=', $dari);
        }

        $masuk = (float) (clone $query)->where('posisi', 'Debet')->sum('jumlah');
        $keluar = (float) (clone $query)->where('posisi', 'Kredit')->sum('jumlah');

        return Setting::bulatkanUang($masuk - $keluar);
    }

    /**
     * Daftar anggota beserta status penahanan SHU (untuk ringkasan periode).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function ringkasanPenahanan(int $tahun): Collection
    {
        // Kebijakan nonaktif → tidak ada penahanan; hindari kueri sama sekali.
        if (! Setting::tahanShuSaatMenunggak()) {
            return collect();
        }

        // SATU kueri untuk seluruh anggota (bukan N+1), lalu dikelompokkan.
        $anggotaAktif = Anggota::aktif()->get();
        $tunggakan = $this->tunggakanPerAnggota($anggotaAktif->pluck('id')->all());

        return $anggotaAktif
            ->map(function (Anggota $a) use ($tunggakan) {
                $baris = $tunggakan[$a->id] ?? collect();

                return [
                    'is_hold' => $baris->isNotEmpty(),
                    'hold_reason' => $baris->isEmpty() ? null : $this->alasanPenahanan($baris),
                    'anggota_id' => $a->id,
                    'nama_anggota' => $a->nama_lengkap,
                    'no_anggota' => $a->no_anggota,
                ];
            })
            ->filter(fn ($b) => $b['is_hold'])
            ->values();
    }
}
