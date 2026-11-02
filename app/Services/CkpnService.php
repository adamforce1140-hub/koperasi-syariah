<?php

namespace App\Services;

use App\Models\Angsuran;
use App\Models\AuditLog;
use App\Models\Coa as CoaModel;
use App\Models\Jurnal;
use App\Models\Pembiayaan;
use App\Support\Coa;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CKPN — Cadangan Kerugian Penurunan Nilai piutang pembiayaan
 * (PSAK 101 penyajian laporan keuangan syariah, PSAK 105 mudharabah,
 * PSAK 107 ijarah, dan PSAK 71 instrumen keuangan).
 *
 * Seluruh PERSENTASE penyisihan per tingkat kolektibilitas dibaca dari parameter
 * kebijakan koperasi ({@see Setting::persenCkpn()}), BUKAN di-hardcode — sehingga
 * koperasi dapat menyesuaikan tarif penyisihan mengikuti perubahan standar
 * PSAK/regulasi tanpa mengubah kode inti. Demikian pula ambang hari
 * kolektibilitas dibaca dari {@see Setting::ambangKolektibilitas()}.
 *
 * Akun yang dipakai:
 *   - 1-2100 Penyisihan Kerugian Piutang Pembiayaan (kontra-aset, saldo KREDIT)
 *   - 5-4001 Beban Penyisihan Kerugian Piutang Pembiayaan (beban, saldo DEBET)
 *
 * Jurnal penyesuaian saat penyisihan NAIK:
 *   DEBET  5-4001 Beban CKPN
 *   KREDIT 1-2100 Penyisihan CKPN
 * Saat penyisihan TURUN (pemulihan) posisi dibalik.
 */
class CkpnService
{
    public function __construct(
        private AutoJournalingService $journalService
    ) {}

    /**
     * Kode kolektibilitas efektif satu pembiayaan: 'kol1'..'kol5'.
     *
     * Kolom `status_kolektibilitas` adalah penetapan eksplisit manajemen, namun
     * BERSIFAT SATU ARAH: ia hanya boleh MENURUNKAN kualitas (kol yang lebih
     * buruk), TIDAK boleh menaikkan kualitas di atas yang dibenarkan oleh hari
     * tunggakan nyata. Ini mencegah override yang menyesatkan — mis. sisa 'kol1'
     * dari restrukturisasi lama — menutupi tunggakan berjalan dan menyebabkan
     * CKPN under-provisioning. Selaras POJK 12/2023 tentang kualitas aset
     * produktif & PSAK 71 (kualitas ditetapkan PALING RENDAH sesuai hari
     * tunggakan). Bila kolom kosong (null), klasifikasi murni dihitung dari hari.
     */
    public function kolektibilitasKode(Pembiayaan $pembiayaan, ?Carbon $perTanggal = null): string
    {
        $hari = $this->hariTunggakan($pembiayaan, $perTanggal);

        // Ambang dinamis memetakan tunggakan ke kol1..kol5; tunggakan dalam
        // batas Kol-1 (pola KSP syariah: ≤ 10 hari) tetap diklasifikasikan
        // LANCAR, bukan langsung menunggak.
        $dariHari = Setting::kolektibilitasDariHari($hari);

        $dariKolom = static::normalisasiKodeKolektibilitas($pembiayaan->status_kolektibilitas);

        if ($dariKolom === null) {
            return $dariHari;
        }

        // Ambil yang lebih konservatif: 'kol1' < 'kol2' < ... < 'kol5' secara
        // leksikografis, sehingga max() = kualitas terburuk.
        return max($dariKolom, $dariHari);
    }

    /** Hari tunggakan tertinggi (real-time) sebuah pembiayaan pada tanggal acuan. */
    public function hariTunggakan(Pembiayaan $pembiayaan, ?Carbon $perTanggal = null): int
    {
        // Piutang yang sudah dihapus buku tidak lagi diakui (PSAK 71), sehingga
        // tidak memiliki eksposur tunggakan apa pun.
        if ($pembiayaan->isHapusBuku()) {
            return 0;
        }

        $acuan = ($perTanggal ?? Carbon::today())->copy()->startOfDay();

        $jadwal = $pembiayaan->jadwalAngsuran();

        $terlama = (clone $jadwal)
            ->where('status_angsuran', '!=', 'Sudah Dibayar')
            ->whereNotNull('tanggal_jatuh_tempo')
            ->where('tanggal_jatuh_tempo', '<', $acuan)
            ->orderBy('tanggal_jatuh_tempo')
            ->value('tanggal_jatuh_tempo');

        if ($terlama) {
            return (int) max(0, round(Carbon::parse($terlama)->startOfDay()->diffInDays($acuan)));
        }

        /* CKPN-7 — Pembiayaan yang TIDAK memiliki baris jadwal angsuran sama
         | sekali (data historis/impor, atau jalur pencairan yang tidak membentuk
         | jadwal) sebelumnya selalu dinilai lancar karena `hariTunggakan()`
         | mengembalikan 0. Akibatnya piutang yang sebenarnya menunggak hanya
         | dicadangkan 0,5% (kol1) — under-provisioning. Sebagai jaring pengaman,
         | tanggal jatuh tempo kontraktual pada header dipakai sebagai acuan
         | tunggakan HANYA bila jadwal benar-benar kosong (agar angsuran yang
         | sudah dibayar tepat waktu tidak salah ditandai menunggak). */
        if (! $jadwal->exists()) {
            $jatuhTempo = $pembiayaan->tanggal_jatuh_tempo;

            if ($jatuhTempo) {
                return (int) max(0, round(Carbon::parse($jatuhTempo)->startOfDay()->diffInDays($acuan)));
            }
        }

        return 0;
    }

    /**
     * Rincian CKPN per pembiayaan berjalan (belum lunas).
     *
     * DASAR PERHITUNGAN (PSAK 71 / PSAK 102 / PSAK 101):
     * ECL dibentuk atas NILAI TERCATAT PIUTANG NETO, yaitu piutang POKOK bruto
     * dikurangi margin yang belum menjadi hak (margin ditangguhkan 1-2101).
     * Margin murabahah/istishna/salam yang masih ditangguhkan BUKAN piutang dan
     * disajikan sebagai akun kontra, sehingga TIDAK boleh dikenai penyisihan.
     *
     * Karena itu basis dihitung dari sisa POKOK kontraktual (Σ `pokok_angsuran`
     * yang belum dibayar) — bukan dari kolom `sisa_pembiayaan` yang pada saat
     * pencairan diisi sebesar total akad (pokok + margin).
     *
     * Piutang yang sudah DIHAPUS BUKU (status `hapus_buku`, CKPN-5) otomatis
     * keluar dari basis karena statusnya tidak lagi termasuk `statusTercair()`
     * dan `sisa_pembiayaan`-nya telah dinolkan saat penghapusan.
     *
     * @return array<int, array{
     *     pembiayaan_id: int,
     *     anggota: ?string,
     *     sisa_pokok: float,
     *     margin_ditangguhkan: float,
     *     kolektibilitas: string,
     *     persen: float,
     *     ckpn: float
     * }>
     */
    public function rincian(?Carbon $perTanggal = null): array
    {
        $persen = Setting::persenCkpn();

        return Pembiayaan::query()
            // Hanya pembiayaan yang DANANYA SUDAH DICAIRKAN. `statusBerjalan()`
            // juga mencakup status pra-pencairan (disetujui/disahkan/pending_*);
            // dana yang belum disalurkan bukan piutang sehingga tidak boleh
            // dikenai penyisihan (PSAK 71 — ECL hanya atas aset keuangan yang
            // telah diakui). Daftar status diambil dari satu sumber kebenaran
            // Pembiayaan::statusTercair() agar tidak berbeda antar modul.
            ->tercair()
            ->where('sisa_pembiayaan', '>', 0)
            ->with('anggota')
            ->get()
            ->map(function (Pembiayaan $pembiayaan) use ($persen, $perTanggal) {
                $kode = $this->kolektibilitasKode($pembiayaan, $perTanggal);
                $dasar = $this->dasarPiutang($pembiayaan);
                $rate = (float) ($persen[$kode] ?? 0.0);

                return [
                    'pembiayaan_id' => (int) $pembiayaan->id,
                    'anggota' => $pembiayaan->anggota?->nama_lengkap,
                    'sisa_pokok' => $dasar,
                    'margin_ditangguhkan' => $this->marginDitangguhkan($pembiayaan, $dasar),
                    'kolektibilitas' => $kode,
                    'persen' => $rate,
                    'ckpn' => Setting::bulatkanUang($dasar * $rate / 100),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Dasar piutang yang dikenai CKPN = sisa POKOK kontraktual (piutang bruto
     * POKOK) per tanggal acuan.
     *
     * Sumber utama: Σ `pokok_angsuran` angsuran yang belum lunas. Nilai ini
     * konsisten dengan kredit pada akun piutang per akad (yang hanya menerima
     * porsi pokok, bukan margin — margin masuk ke 1-2101).
     *
     * Fallback: bila jadwal angsuran belum terbentuk (data historis), dipakai
     * `sisa_pembiayaan` dikurangi margin ditangguhkan yang tersisa.
     */
    public function dasarPiutang(Pembiayaan $pembiayaan, ?Carbon $perTanggal = null): float
    {
        // Pokok angsuran yang belum dibayar sama sekali (tagihan penuh).
        $pokokPenuh = (float) Angsuran::where('pembiayaan_id', $pembiayaan->id)
            ->whereIn('status_angsuran', ['Belum Jatuh Tempo', 'Jatuh Tempo', 'Terlambat'])
            ->sum('pokok_angsuran');

        // Pokok yang masih menjadi kewajiban atas angsuran yang baru dibayar
        // sebagian (kolom `sisa_pokok` menyimpan kekurangan pokok).
        $pokokSebagian = (float) Angsuran::where('pembiayaan_id', $pembiayaan->id)
            ->where('status_angsuran', 'Dibayar Sebagian')
            ->sum('sisa_pokok');

        $sisaPokok = Setting::bulatkanUang($pokokPenuh + $pokokSebagian);

        if ($sisaPokok > 0) {
            return $sisaPokok;
        }

        // Fallback: sisa kewajiban dikurangi margin ditangguhkan yang tersisa.
        $marginSisa = $this->marginTersisa($pembiayaan);

        return Setting::bulatkanUang(max(0.0, (float) $pembiayaan->sisa_pembiayaan - $marginSisa));
    }

    /**
     * Margin ditangguhkan yang masih tercatat untuk sebuah pembiayaan — dipakai
     * untuk pelaporan/penyajian piutang bruto vs kontra margin.
     */
    public function marginDitangguhkan(Pembiayaan $pembiayaan, ?float $dasarPiutang = null): float
    {
        $dasar = $dasarPiutang ?? $this->dasarPiutang($pembiayaan);

        return Setting::bulatkanUang(max(0.0, (float) $pembiayaan->sisa_pembiayaan - $dasar));
    }

    /**
     * Margin kontrak sebuah pembiayaan: snapshot `margin_deferred_amount`
     * (diisi saat pencairan) diutamakan, lalu selisih total akad vs pokok.
     */
    private function marginTersisa(Pembiayaan $pembiayaan): float
    {
        $snapshot = $pembiayaan->margin_deferred_amount;

        if ($snapshot !== null && (float) $snapshot > 0) {
            return Setting::bulatkanUang((float) $snapshot);
        }

        return Setting::bulatkanUang(max(
            0.0,
            (float) $pembiayaan->total_pembiayaan - (float) $pembiayaan->jumlah_pembiayaan
        ));
    }

    /** Total penyisihan yang SEHARUSNYA (target) per tanggal acuan. */
    public function targetSaldo(?Carbon $perTanggal = null): float
    {
        return Setting::bulatkanUang(array_sum(array_column($this->rincian($perTanggal), 'ckpn')));
    }

    /**
     * Saldo penyisihan yang TERCATAT di akun 1-2100 per tanggal acuan.
     *
     * 1-2100 adalah akun KONTRA-ASET (saldo normal Kredit), sehingga saldonya
     * dihitung kredit − debet — bukan rumus aset biasa.
     */
    public function saldoTercatat(?Carbon $perTanggal = null): float
    {
        $coa = CoaModel::findByKode(Coa::CKPN_PIUTANG_PEMBIAYAAN);

        if (! $coa) {
            return 0.0;
        }

        $sampai = $perTanggal?->toDateString();

        return Setting::bulatkanUang($coa->totalKredit($sampai) - $coa->totalDebet($sampai));
    }

    /** Selisih target − tercatat. Positif = perlu menambah penyisihan. */
    public function selisih(?Carbon $perTanggal = null): float
    {
        return Setting::bulatkanUang($this->targetSaldo($perTanggal) - $this->saldoTercatat($perTanggal));
    }

    /**
     * Posting jurnal penyesuaian CKPN agar saldo tercatat = target.
     *
     * Tidak melakukan apa-apa bila selisih masih di dalam toleransi kebijakan
     * ({@see Setting::toleransiSelisihJurnal()}).
     *
     * R13 — penulisan jurnal dibungkus `DB::transaction` agar atomik, dan hasil
     * `post()` diperiksa: bila CoA beban/penyisihan tidak ditemukan atau
     * nonaktif, `post()` mengembalikan null (bukan melempar). Tanpa pemeriksaan
     * ini penyesuaian akan "dilewati" secara senyap sehingga saldo CKPN terus
     * berbeda dari target. Karena itu null dilempar sebagai RuntimeException
     * supaya transaksi digulung dan pemanggil mendapat sinyal kegagalan nyata.
     *
     * @return Jurnal|null Jurnal yang dibuat, atau null bila tidak ada penyesuaian.
     *
     * @throws \RuntimeException bila jurnal gagal diposting (CoA tidak siap).
     */
    public function catatPenyesuaian(?Carbon $perTanggal = null, ?int $userId = null): ?Jurnal
    {
        $selisih = $this->selisih($perTanggal);

        if (abs($selisih) < Setting::toleransiSelisihJurnal()) {
            return null;
        }

        $nominal = abs($selisih);
        $tanggal = ($perTanggal ?? Carbon::today())->toDateString();

        /* R13 — nominal yang menguap menjadi Rp 0,00 setelah pembulatan kebijakan
         | (mis. selisih Rp 0,01 dengan pembulatan rupiah penuh) tidak layak
         | dijurnal. Diperlakukan sebagai "tidak ada penyesuaian" agar pemanggil
         | tidak salah menyimpulkan adanya kegagalan CoA. */
        if (Setting::pembulatanNominal($nominal) <= 0.0) {
            return null;
        }

        if ($selisih > 0) {
            $keterangan = 'Penyesuaian CKPN piutang pembiayaan';
            $entries = [
                ['kode' => Coa::BEBAN_CKPN_PEMBIAYAAN,   'debet' => $nominal],
                ['kode' => Coa::CKPN_PIUTANG_PEMBIAYAAN, 'kredit' => $nominal],
            ];
        } else {
            $keterangan = 'Pemulihan CKPN piutang pembiayaan';
            $entries = [
                ['kode' => Coa::CKPN_PIUTANG_PEMBIAYAAN, 'debet' => $nominal],
                ['kode' => Coa::BEBAN_CKPN_PEMBIAYAAN,   'kredit' => $nominal],
            ];
        }

        return DB::transaction(function () use ($keterangan, $entries, $userId, $tanggal, $selisih, $nominal) {
            $jurnal = $this->journalService->post(
                $keterangan,
                $entries,
                'Penyesuaian CKPN',
                null,
                null,
                $userId,
                null,
                null,
                $tanggal
            );

            if ($jurnal === null) {
                throw new \RuntimeException(
                    'Penyesuaian CKPN gagal diposting: akun '.Coa::BEBAN_CKPN_PEMBIAYAAN
                    .' atau '.Coa::CKPN_PIUTANG_PEMBIAYAAN
                    .' tidak ditemukan/nonaktif (selisih Rp '.number_format($selisih, 2, ',', '.').').'
                );
            }

            /* Jejak audit penyesuaian CKPN (append-only + rantai hash) untuk
             | pelaporan ke regulator. Ditulis DI DALAM transaksi agar jejaknya
             | hanya ada bila jurnalnya benar-benar ter-commit — jejak audit yang
             | menyebut penyesuaian yang batal akan menyesatkan pemeriksa.
             | `rekening` diisi referensi jurnal + arah penyesuaian, bukan nomor
             | rekening anggota (CKPN adalah pos agregat, bukan mutasi rekening). */
            AuditLog::record(
                $selisih > 0 ? 'CKPN — Pembentukan Penyisihan' : 'CKPN — Pemulihan Penyisihan',
                $nominal,
                'JURNAL-CKPN-'.$jurnal->id,
                $keterangan.' per '.$tanggal
                .' sebesar Rp '.number_format($nominal, 2, ',', '.')
                .' (selisih '.($selisih > 0 ? 'menambah' : 'mengurangi')
                .' penyisihan; akun '.Coa::BEBAN_CKPN_PEMBIAYAAN.' / '
                .Coa::CKPN_PIUTANG_PEMBIAYAAN.').'
            );

            return $jurnal;
        });
    }

    /** Kode kolektibilitas eksplisit ('kol1'..'kol5'); null bila bukan kode. */
    private static function normalisasiKodeKolektibilitas(?string $nilai): ?string
    {
        $kode = strtolower(trim((string) $nilai));

        return in_array($kode, ['kol1', 'kol2', 'kol3', 'kol4', 'kol5'], true) ? $kode : null;
    }

    /**
     * CKPN-4 — Sinkronkan status `macet` dengan kualitas piutang efektif.
     *
     * Sebelumnya status `macet` (beserta sinonimnya NPF/terlambat/bermasalah)
     * TIDAK PERNAH di-assign otomatis: piutang yang menunggak berbulan-bulan
     * tetap berstatus `aktif`, sehingga laporan NPF, daftar penagihan, dan
     * kelayakan hapus buku tidak pernah mengenali aktiva bermasalah. Klasifikasi
     * kolektibilitas efektif kini dibaca dari {@see kolektibilitasKode()}
     * (yang tetap menghormati override MANUAL pada kolom `status_kolektibilitas`;
     * nilai status restrukturisasi tidak lagi ditulis ke kolom ini — lihat
     * RestrukturisasiService::approve()), lalu:
     *
     *   - kualitas kol5 → status pembiayaan dinaikkan menjadi MACET;
     *   - kualitas selain kol5 sementara status masih MACET (tunggakan lunas)
     *     → status dipulihkan ke AKTIF.
     *
     * Selaras POJK 12/2023 tentang kualitas aset produktif. Dipanggil pada
     * penyesuaian CKPN harian sehingga status selalu mutakhir.
     *
     * @param  bool  $terapkan  false = hanya menghitung (mode dry-run), tanpa menyimpan.
     * @return array{diperiksa: int, diubah: int, macet: int}
     */
    public function sinkronkanStatusKolektibilitas(?Carbon $perTanggal = null, bool $terapkan = true): array
    {
        $daftar = Pembiayaan::tercair()
            ->where('sisa_pembiayaan', '>', 0)
            ->whereNull('tanggal_hapus_buku')
            ->get();

        $diubah = 0;
        $macet = 0;

        foreach ($daftar as $item) {
            $kode = $this->kolektibilitasKode($item, $perTanggal);

            $target = null;

            if ($kode === 'kol5' && ! $item->isStatus(Pembiayaan::STATUS_MACET)) {
                $target = Pembiayaan::STATUS_MACET;
                $macet++;
            } elseif ($kode !== 'kol5' && $item->isStatus(Pembiayaan::STATUS_MACET)) {
                $target = Pembiayaan::STATUS_AKTIF;
            }

            if ($target === null) {
                continue;
            }

            $diubah++;

            if ($terapkan) {
                // Status asal dibaca SEBELUM update — setelah save(), Eloquent
                // menyinkronkan atribut asli sehingga getOriginal() akan
                // mengembalikan nilai BARU, bukan yang lama.
                $statusAsal = (string) $item->status_pembiayaan;

                $item->update(['status_pembiayaan' => $target]);

                /* Jejak audit perubahan kategori tunggakan (kol5 ↔ aktif).
                 | Diperlukan untuk pelaporan ke regulator: menyediakan bukti
                 | KAPAN sebuah piutang dinyatakan MACET (dan kapan dipulihkan),
                 | beserta dasar hari tunggakan & kualitas piutang saat itu.
                 | Hanya ditulis bila $terapkan = true (dry-run tidak menyentuh
                 | database sama sekali). */
                AuditLog::record(
                    'CKPN — Perubahan Kategori Tunggakan',
                    (float) $item->sisa_pembiayaan,
                    $item->no_pembiayaan,
                    'Pembiayaan #'.$item->no_pembiayaan.' ('.($item->anggota?->nama_lengkap ?? '-').') '
                    .'berubah dari '.$statusAsal.' menjadi '.$target
                    .' — kolektibilitas '.$kode.' (tunggakan '
                    .$this->hariTunggakan($item, $perTanggal).' hari) per '
                    .($perTanggal ?? Carbon::today())->toDateString()
                    .'; sisa pokok Rp '.number_format((float) $item->sisa_pembiayaan, 2, ',', '.').'.'
                );
            }
        }

        return ['diperiksa' => $daftar->count(), 'diubah' => $diubah, 'macet' => $macet];
    }

    /**
     * CKPN-5 — HAPUS BUKU (write-off) piutang pembiayaan macet.
     *
     * PSAK 71 ¶3.2.16 / POJK 12/2023: aset keuangan DIHENTIKAN PENGAKUANNYA
     * (derecognized) ketika tidak ada lagi ekspektasi wajar atas pemulihannya.
     * Sisa nilai tercatat dibebankan terhadap CADANGAN KERUGIAN yang telah
     * dibentuk (akun 1-2100) — bukan langsung ke beban pada saat penghapusan.
     *
     * Jurnal (PSAK 71 / PSAK 102):
     *   DEBET  1-2100 Penyisihan Kerugian Piutang Pembiayaan  (sebesar pokok,
     *                  maksimal sebesar saldo penyisihan yang tersedia)
     *   DEBET  5-4001 Beban Penyisihan Kerugian               (kekurangan bila
     *                  cadangan belum mencukupi 100% pokok)
     *   DEBET  1-2101 Margin Ditangguhkan                     (membatalkan akun
     *                  kontra margin atas sisa margin yang belum terealisasi)
     *   KREDIT 1-200x Piutang Pembiayaan per akad             (nilai bruto =
     *                  pokok + margin yang masih tercatat)
     *
     * Setelah jurnal, pembiayaan ditandai `status_pembiayaan = hapus_buku`,
     * `sisa_pembiayaan = 0`, margin ditangguhkan dinolkan, dan
     * `tanggal_hapus_buku` diisi sebagai bukti audit. Dengan begitu pembiayaan
     * keluar dari basis CKPN (rincian()) dan tidak lagi diproses autodebet.
     *
     * PRASYARAT (dijaga ketat agar tidak disalahgunakan):
     *   - pembiayaan sudah tercair (dananya telah disalurkan),
     *   - masih memiliki sisa piutang > 0,
     *   - kolektibilitas kol5 ATAU berstatus macet (hapus buku hanya untuk
     *     aktiva produktif golongan macet), dan
     *   - belum pernah dihapus buku.
     *
     * IDEMPOTEN: pemanggilan ulang atas pembiayaan yang sudah dihapus buku
     * mengembalikan null tanpa memposting jurnal baru.
     *
     * @return Jurnal|null Jurnal hapus buku, atau null bila tidak ada yang dihapus.
     *
     * @throws \RuntimeException bila prasyarat tidak terpenuhi atau jurnal gagal diposting.
     */
    public function hapusBuku(Pembiayaan $pembiayaan, ?Carbon $perTanggal = null, ?int $userId = null): ?Jurnal
    {
        if ($pembiayaan->isHapusBuku()) {
            return null;
        }

        if (! $pembiayaan->isStatus(Pembiayaan::statusTercair())) {
            throw new \RuntimeException(
                "Hapus buku ditolak: pembiayaan #{$pembiayaan->id} ({$pembiayaan->no_pembiayaan}) "
                .'belum tercair — yang belum diakui sebagai piutang tidak dapat dihapus buku.'
            );
        }

        $kodeKolektibilitas = $this->kolektibilitasKode($pembiayaan, $perTanggal);

        if ($kodeKolektibilitas !== 'kol5' && ! $pembiayaan->isStatus(Pembiayaan::STATUS_MACET)) {
            throw new \RuntimeException(
                "Hapus buku ditolak: pembiayaan #{$pembiayaan->id} ({$pembiayaan->no_pembiayaan}) "
                ."berkolektibilitas {$kodeKolektibilitas}, bukan kol5/macet."
            );
        }

        $tanggal = ($perTanggal ?? Carbon::today())->copy()->startOfDay();

        $resolver = app(CoaResolver::class);
        $akunPiutang = $resolver->kodePiutangByAkad($pembiayaan->akad_pembiayaan);
        $akunMargin = $resolver->kodeMarginDitangguhkanByAkad($pembiayaan->akad_pembiayaan);

        /* Nilai bruto yang tercatat di akun piutang = sisa_pembiayaan (pokok +
         | margin belum terealisasi). Nilai ini dibulatkan lebih dahulu agar
         | seluruh baris jurnal seimbang tepat setelah pembulatan kebijakan. */
        $bruto = Setting::pembulatanNominal((float) $pembiayaan->sisa_pembiayaan);

        if ($bruto <= 0) {
            return null;
        }

        // Akad bermargin: pisahkan porsi margin (kontra 1-2101) dari pokok.
        $margin = $akunMargin !== null
            ? Setting::pembulatanNominal($this->marginDitangguhkan($pembiayaan))
            : 0.0;

        $pokok = Setting::bulatkanUang($bruto - $margin);

        if ($pokok < 0) {
            // Data anomali (margin > bruto): jangan sampai terbentuk baris negatif.
            $pokok = 0.0;
            $margin = $bruto;
        }

        // Beban ditanggung cadangan lebih dahulu; kekurangannya menjadi beban periode.
        $saldoPenyisihan = Setting::pembulatanNominal(max(0.0, $this->saldoTercatat($tanggal)));
        $debetPenyisihan = min($pokok, $saldoPenyisihan);
        $debetBeban = Setting::bulatkanUang($pokok - $debetPenyisihan);

        $entries = [
            ['kode' => $akunPiutang, 'kredit' => $bruto],
        ];

        if ($margin > 0 && $akunMargin !== null) {
            $entries[] = ['kode' => $akunMargin, 'debet' => $margin];
        }

        if ($debetPenyisihan > 0) {
            $entries[] = ['kode' => Coa::CKPN_PIUTANG_PEMBIAYAAN, 'debet' => $debetPenyisihan];
        }

        if ($debetBeban > 0) {
            $entries[] = ['kode' => Coa::BEBAN_CKPN_PEMBIAYAAN, 'debet' => $debetBeban];
        }

        $keterangan = "Hapus buku piutang pembiayaan #{$pembiayaan->no_pembiayaan} "
            ."(akad {$pembiayaan->akad_pembiayaan}, kolektibilitas {$kodeKolektibilitas})";

        return DB::transaction(function () use ($pembiayaan, $entries, $keterangan, $tanggal, $userId, $bruto, $kodeKolektibilitas) {
            $jurnal = $this->journalService->post(
                $keterangan,
                $entries,
                'Hapus Buku Pembiayaan',
                null,
                (int) $pembiayaan->id,
                $userId,
                null,
                null,
                $tanggal->toDateString()
            );

            if ($jurnal === null) {
                throw new \RuntimeException(
                    "Hapus buku pembiayaan #{$pembiayaan->id} gagal diposting: akun piutang "
                    .'atau akun CKPN (1-2100 / 5-4001) tidak ditemukan/nonaktif '
                    .'(nilai bruto Rp '.number_format($bruto, 2, ',', '.').').'
                );
            }

            // Keluarkan aset dari neraca & tandai sebagai dihapus buku.
            $pembiayaan->update([
                'status_pembiayaan' => Pembiayaan::STATUS_HAPUS_BUKU,
                'sisa_pembiayaan' => 0,
                'margin_deferred_amount' => 0,
                'tanggal_hapus_buku' => $tanggal->toDateString(),
            ]);

            /* Jejak audit hapus buku — peristiwa paling material dalam siklus
             | hidup piutang (aset dihentikan pengakuannya, PSAK 71 ¶3.2.16) dan
             | wajib dapat dipertanggungjawabkan ke regulator: siapa yang
             | menghapus, kapan, berapa nilai bruto yang dibebankan ke cadangan. */
            AuditLog::record(
                'CKPN — Hapus Buku Pembiayaan',
                (float) $bruto,
                $pembiayaan->no_pembiayaan,
                'Hapus buku piutang pembiayaan #'.$pembiayaan->no_pembiayaan
                .' ('.($pembiayaan->anggota?->nama_lengkap ?? '-').') per '
                .$tanggal->toDateString().' — akad '.$pembiayaan->akad_pembiayaan
                .', kolektibilitas '.$kodeKolektibilitas.', nilai tercatat bruto Rp '
                .number_format($bruto, 2, ',', '.').' dibebankan ke cadangan '
                .Coa::CKPN_PIUTANG_PEMBIAYAAN.' (jurnal #'.$jurnal->id.').'
            );

            return $jurnal;
        });
    }
}
