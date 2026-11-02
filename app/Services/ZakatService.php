<?php

namespace App\Services;

use App\Models\Anggota;
use App\Models\AuditLog;
use App\Models\Jurnal;
use App\Models\ZakatTransaksi;
use App\Support\Coa;
use App\Support\PemisahanTugas;
use App\Support\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Modul Zakat, Infak & Sedekah — PSAK 109 (Akuntansi Zakat, Infak, dan Sedekah).
 *
 * Landasan syariah & standar:
 *  - PSAK 109: dana zakat dan infak/sedekah adalah dana TITIPAN (amanah), bukan
 *    pendapatan entitas pengelola, dan WAJIB disajikan terpisah dalam laporan
 *    sumber & penggunaan dana.
 *  - QS At-Taubah 60: zakat hanya boleh disalurkan kepada 8 asnaf.
 *  - Fatwa DSN-MUI No. 08/DSN-MUI/IV/2000 tentang zakat, infak, dan sedekah.
 *
 * Aturan posisi jurnal (mengikuti pola DanaKebajikanService agar konsisten):
 *  - PENERIMAAN: DEBET kas/bank  | KREDIT 2-3002 (Zakat) atau 2-3004 (Infak & Sedekah)
 *  - PENYALURAN: DEBET 2-3002/2-3004 | KREDIT kas/bank
 *
 * Saldo dana SELALU dihitung dari mutasi jurnal akun CoA (sumber kebenaran
 * akuntansi), bukan dari tabel transaksi, lalu dicocokkan dengan buku besar
 * transaksi modul ini sebagai kontrol silang.
 *
 * Seluruh tarif/ambang dibaca dari parameter kebijakan ({@see Setting}).
 */
class ZakatService
{
    /** Kode asnaf penerima zakat (QS At-Taubah 60). */
    public const ASNAF = [
        'fakir' => 'Fakir',
        'miskin' => 'Miskin',
        'amil' => 'Amil (Pengelola)',
        'muallaf' => 'Muallaf',
        'riqab' => 'Riqab (Memerdekakan Budak)',
        'gharim' => 'Gharim (Terlilit Utang)',
        'fisabilillah' => 'Fisabilillah (Jalan Allah)',
        'ibnu_sabil' => 'Ibnu Sabil (Musafir)',
    ];

    public function __construct(
        private AutoJournalingService $jurnal
    ) {}

    /* =====================================================================
     | SUMBER DANA — akun CoA per jenis dana
     ===================================================================== */

    /**
     * Kode akun CoA penampung dana: 'zakat' | 'infak_sedekah'.
     *
     * Resolusi akun TIDAK dipatok di kode: memakai {@see CoaResolver} yang
     * membaca parameter kebijakan `account_dana_zakat_id` /
     * `account_dana_infak_id`, lalu fallback ke registri standar 2-3002 /
     * 2-3004. Resolver yang SAMA dipakai penyajian DIT
     * (`LaporanKeuanganSyariahService`), sehingga pencatatan modul zakat dan
     * laporan DIT selalu menunjuk akun yang identik — mencegah divergensi
     * saldo (temuan audit Z-05).
     */
    public static function akunDana(string $dana): string
    {
        $resolver = app(CoaResolver::class);

        return $dana === ZakatTransaksi::DANA_INFAK_SEDEKAH
            ? $resolver->kodeDanaInfakSedekah()
            : $resolver->kodeDanaZakat();
    }

    /** Apakah kode dana termasuk dana yang dikelola modul ini? */
    public static function danaSah(string $dana): bool
    {
        return in_array($dana, [ZakatTransaksi::DANA_ZAKAT, ZakatTransaksi::DANA_INFAK_SEDEKAH], true);
    }

    /* =====================================================================
     | PENERIMAAN DANA (dari muzakki / munfiq)
     ===================================================================== */

    /**
     * Catat penerimaan dana zakat atau infak/sedekah.
     *
     * Jurnal: DEBET kas (1-1001 default) | KREDIT 2-3002 / 2-3004
     *
     * @param  array{
     *     dana: string, kategori: string, pihak: string, jumlah: float,
     *     keterangan?: string, tanggal?: string, kode_kas?: string, no_referensi?: string
     * } $data
     *
     * @throws \InvalidArgumentException bila input tidak sah
     * @throws \RuntimeException bila jurnal gagal diposting (transaksi dibatalkan)
     */
    public function terima(array $data, ?int $userId = null): ZakatTransaksi
    {
        $dana = (string) ($data['dana'] ?? ZakatTransaksi::DANA_ZAKAT);
        $kategori = (string) ($data['kategori'] ?? '');
        $pihak = trim((string) ($data['pihak'] ?? ''));
        // R-08 (Z-07) — relasi OPSIONAL ke master anggota. Bila disertakan,
        // `pihak` tetap dipakai sebagai label snapshot; bila `pihak` kosong namun
        // `pihak_id` ada, nama diambil dari master anggota (fallback terstruktur).
        $pihakId = $data['pihak_id'] ?? null;

        if ($pihak === '' && $pihakId) {
            $anggota = Anggota::withTrashed()->find($pihakId);
            $pihak = trim((string) ($anggota?->nama_lengkap ?? ''));
        }
        // Pembulatan mengikuti kebijakan koperasi (Setting) — nilai buku besar
        // WAJIB identik dengan nilai jurnal agar kontrol silang PSAK 109 sahih.
        $jumlah = Setting::pembulatanNominal((float) ($data['jumlah'] ?? 0));

        if (! static::danaSah($dana)) {
            throw new \InvalidArgumentException("Jenis dana [{$dana}] tidak dikenal. Gunakan 'zakat' atau 'infak_sedekah'.");
        }

        if ($pihak === '') {
            throw new \InvalidArgumentException('Nama pihak penyetor (muzakki/munfiq) wajib diisi.');
        }

        if ($jumlah <= 0) {
            throw new \InvalidArgumentException('Nominal penerimaan dana harus lebih besar dari nol.');
        }

        // Dana zakat hanya sah dari jenis zakat yang terdaftar (PSAK 109).
        if ($dana === ZakatTransaksi::DANA_ZAKAT && ! array_key_exists($kategori, ZakatTransaksi::KATEGORI_PENERIMAAN)) {
            throw new \InvalidArgumentException("Jenis zakat [{$kategori}] tidak dikenal.");
        }

        $kodeKas = (string) ($data['kode_kas'] ?? Coa::KAS_TELLER);
        $tanggal = $data['tanggal'] ?? now()->toDateString();
        $keterangan = $data['keterangan']
            ?? sprintf('Penerimaan %s dari %s', $dana === ZakatTransaksi::DANA_ZAKAT ? 'zakat' : 'infak/sedekah', $pihak);

        return $this->withRetryReferensi('TERIMA', $dana, function (string $nomor) use ($dana, $kategori, $pihak, $pihakId, $jumlah, $kodeKas, $tanggal, $keterangan, $userId) {
            return DB::transaction(function () use ($nomor, $dana, $kategori, $pihak, $pihakId, $jumlah, $kodeKas, $tanggal, $keterangan, $userId) {
                $jurnal = $this->jurnal->post(
                    $keterangan,
                    [
                        ['kode' => $kodeKas,             'debet' => $jumlah],
                        ['kode' => static::akunDana($dana), 'kredit' => $jumlah],
                    ],
                    'Penerimaan Dana Zakat/Infak',
                    null,
                    null,
                    $userId,
                    null,
                    null,
                    $tanggal
                );

                $this->pastikanJurnal($jurnal, 'penerimaan', $pihak, $jumlah);

                return ZakatTransaksi::create([
                    'no_referensi' => $nomor,
                    'jenis' => ZakatTransaksi::JENIS_PENERIMAAN,
                    'status' => ZakatTransaksi::STATUS_POSTED,
                    'dana' => $dana,
                    'kategori' => $kategori,
                    'pihak' => $pihak,
                    'pihak_id' => $pihakId ?: null,
                    'jumlah' => $jumlah,
                    'keterangan' => $keterangan,
                    'tanggal' => $tanggal,
                    'jurnal_id' => $jurnal->id,
                    'created_by' => $userId,
                ]);
            });
        }, $data['no_referensi'] ?? null);
    }

    /* =====================================================================
     | PENYALURAN DANA (kepada mustahik / asnaf)
     ===================================================================== */

    /**
     * Catat penyaluran dana zakat atau infak/sedekah.
     *
     * Jurnal: DEBET 2-3002 / 2-3004 | KREDIT kas
     *
     * Penyaluran DITOLAK bila melebihi saldo dana yang tersedia — mencegah
     * penyaluran melebihi dana terkumpul (indikasi kesalahan pencatatan).
     *
     * @throws \InvalidArgumentException bila nominal melebihi saldo atau input tidak sah
     * @throws \RuntimeException bila jurnal gagal diposting (transaksi dibatalkan)
     */
    public function salurkan(array $data, ?int $userId = null): ZakatTransaksi
    {
        $dana = (string) ($data['dana'] ?? ZakatTransaksi::DANA_ZAKAT);
        $kategori = (string) ($data['kategori'] ?? '');
        $pihak = trim((string) ($data['pihak'] ?? ''));
        // R-08 (Z-07) — relasi OPSIONAL ke master anggota (mustahik anggota koperasi).
        $pihakId = $data['pihak_id'] ?? null;

        if ($pihak === '' && $pihakId) {
            $anggota = Anggota::withTrashed()->find($pihakId);
            $pihak = trim((string) ($anggota?->nama_lengkap ?? ''));
        }
        // Pembulatan sama dengan jalur penerimaan: buku besar = jurnal.
        $jumlah = Setting::pembulatanNominal((float) ($data['jumlah'] ?? 0));

        if (! static::danaSah($dana)) {
            throw new \InvalidArgumentException("Jenis dana [{$dana}] tidak dikenal. Gunakan 'zakat' atau 'infak_sedekah'.");
        }

        if ($pihak === '') {
            throw new \InvalidArgumentException('Nama penerima (mustahik) wajib diisi.');
        }

        if ($jumlah <= 0) {
            throw new \InvalidArgumentException('Nominal penyaluran dana harus lebih besar dari nol.');
        }

        // Kebijakan operasional (parameter `zakat_minimal_tarik`, dapat
        // dikonfigurasi Pengurus): tolak penyaluran bernilai sangat kecil yang
        // menyulitkan rekonsiliasi. Nilai 0 berarti tanpa batas minimum.
        $minimum = Setting::zakatMinimalTarik();
        if ($minimum > 0 && $jumlah < $minimum) {
            throw new \InvalidArgumentException(sprintf(
                'Nominal penyaluran (Rp %s) di bawah batas minimum penyaluran Rp %s.',
                number_format($jumlah, 2, ',', '.'),
                number_format($minimum, 2, ',', '.')
            ));
        }

        // Zakat HANYA boleh disalurkan kepada 8 asnaf (QS At-Taubah 60).
        if ($dana === ZakatTransaksi::DANA_ZAKAT && ! array_key_exists($kategori, static::ASNAF)) {
            throw new \InvalidArgumentException("Asnaf penerima zakat [{$kategori}] tidak dikenal. Zakat hanya boleh disalurkan kepada 8 asnaf.");
        }

        $kodeKas = (string) ($data['kode_kas'] ?? Coa::KAS_TELLER);
        $tanggal = $data['tanggal'] ?? now()->toDateString();
        $keterangan = $data['keterangan']
            ?? sprintf('Penyaluran %s kepada %s', $dana === ZakatTransaksi::DANA_ZAKAT ? 'zakat' : 'infak/sedekah', $pihak);

        // DUAL CONTROL (Z-02) — penyaluran dengan jumlah ≥ ambang kebijakan
        // WAJIB disetujui pengurus/manajer LAIN sebelum jurnal diposting. Baris
        // dibuat berstatus `menunggu_persetujuan` TANPA jurnal; saldo diperiksa
        // ulang secara otoritatif saat persetujuan. Ambang 0 = nonaktif (P4).
        $ambang = Setting::zakatAmbangPersetujuan();
        if ($ambang > 0 && $jumlah >= $ambang) {
            $this->pastikanSaldoCukup($dana, $jumlah, $tanggal);

            return $this->withRetryReferensi('SALUR', $dana, function (string $nomor) use ($dana, $kategori, $pihak, $pihakId, $jumlah, $keterangan, $kodeKas, $tanggal, $userId) {
                return ZakatTransaksi::create([
                    'no_referensi' => $nomor,
                    'jenis' => ZakatTransaksi::JENIS_PENYALURAN,
                    'status' => ZakatTransaksi::STATUS_MENUNGGU_PERSETUJUAN,
                    'dana' => $dana,
                    'kategori' => $kategori,
                    'pihak' => $pihak,
                    'pihak_id' => $pihakId ?: null,
                    'jumlah' => $jumlah,
                    'keterangan' => $keterangan,
                    'kode_kas' => $kodeKas,
                    'tanggal' => $tanggal,
                    'created_by' => $userId,
                ]);
            }, $data['no_referensi'] ?? null);
        }

        return $this->withRetryReferensi('SALUR', $dana, function (string $nomor) use ($dana, $kategori, $pihak, $pihakId, $jumlah, $kodeKas, $tanggal, $keterangan, $userId) {
            return DB::transaction(function () use ($nomor, $dana, $kategori, $pihak, $pihakId, $jumlah, $kodeKas, $tanggal, $keterangan, $userId) {
                // TAHAP 5 — INTEGRITAS SALDO (anti-TOCTOU): kunci baris CoA dana DI DALAM
                // transaksi sebelum membaca saldo. Cek saldo di luar transaksi + tanpa
                // penguncian memungkinkan dua penyaluran paralel sama-sama lolos cek dan
                // mengakibatkan saldo dana negatif (penyaluran melebihi dana terkumpul).
                $coaDana = \App\Models\Coa::findByKode(static::akunDana($dana));
                if ($coaDana) {
                    \App\Models\Coa::whereKey($coaDana->id)->lockForUpdate()->first();
                }

                // Saldo dibatasi sampai tanggal transaksi agar penyaluran bertanggal
                // mundur tidak memakai dana yang baru masuk SETELAH tanggal tersebut.
                $saldo = $this->saldo($dana, $tanggal);

                if ($jumlah > $saldo) {
                    throw new \InvalidArgumentException(sprintf(
                        'Nominal penyaluran (Rp %s) melebihi saldo dana (%s) sebesar Rp %s.',
                        number_format($jumlah, 2, ',', '.'),
                        static::labelDana($dana),
                        number_format($saldo, 2, ',', '.')
                    ));
                }

                $jurnal = $this->jurnal->post(
                    $keterangan,
                    [
                        ['kode' => static::akunDana($dana), 'debet' => $jumlah],
                        ['kode' => $kodeKas,                'kredit' => $jumlah],
                    ],
                    'Penyaluran Dana Zakat/Infak',
                    null,
                    null,
                    $userId,
                    null,
                    null,
                    $tanggal
                );

                $this->pastikanJurnal($jurnal, 'penyaluran', $pihak, $jumlah);

                return ZakatTransaksi::create([
                    'no_referensi' => $nomor,
                    'jenis' => ZakatTransaksi::JENIS_PENYALURAN,
                    'status' => ZakatTransaksi::STATUS_POSTED,
                    'dana' => $dana,
                    'kategori' => $kategori,
                    'pihak' => $pihak,
                    'pihak_id' => $pihakId ?: null,
                    'jumlah' => $jumlah,
                    'keterangan' => $keterangan,
                    'tanggal' => $tanggal,
                    'jurnal_id' => $jurnal->id,
                    'created_by' => $userId,
                ]);
            });
        }, $data['no_referensi'] ?? null);
    }

    /* =====================================================================
     | PEMBATALAN (VOID) TRANSAKSI — temuan audit Z-01
     ===================================================================== */

    /**
     * Batalkan (void) satu transaksi zakat/infak secara TERKENDALI.
     *
     * Menutup temuan audit Z-01: sebelumnya modul zakat tidak memiliki jalur
     * pembatalan, sehingga koreksi kesalahan pencatatan hanya dapat dilakukan
     * dengan menyunting/menghapus baris langsung di basis data — menghancurkan
     * jejak audit. Metode ini:
     *  (a) MEMBALIK jurnal asal (posting jurnal kontra, posisi debet/kredit
     *      ditukar) sehingga saldo dana kembali seperti sebelum transaksi;
     *  (b) menandai baris `status='void'` + `void_at`/`void_by`/`void_reason`
     *      TANPA MENGHAPUS baris, agar jejak audit tetap utuh;
     *  (c) mencatat peristiwa ke AuditLog (append-only, tersegel rantai hash);
     *  (d) menegakkan PEMISAHAN TUGAS: pembuat transaksi TIDAK BOLEH
     *      membatalkan transaksinya sendiri (maker ≠ checker).
     *
     * @param  int  $id  ID baris `zakat_transaksis`
     * @param  int|null  $userId  Pelaksana pembatalan (pemutus)
     * @param  string  $alasan  Alasan pembatalan (opsional, disimpan pada baris)
     *
     * @throws \InvalidArgumentException bila transaksi tidak ditemukan / sudah void
     * @throws \RuntimeException bila pemisahan tugas dilanggar atau jurnal pembalik gagal
     */
    public function batalkan(int $id, ?int $userId = null, string $alasan = ''): ZakatTransaksi
    {
        $trx = ZakatTransaksi::find($id);

        if (! $trx) {
            throw new \InvalidArgumentException("Transaksi zakat #{$id} tidak ditemukan.");
        }

        if ($trx->status === ZakatTransaksi::STATUS_VOID) {
            throw new \InvalidArgumentException("Transaksi {$trx->no_referensi} sudah dibatalkan sebelumnya.");
        }

        // GCG — pemisahan tugas: pembuat tidak boleh membatalkan transaksinya sendiri.
        if (PemisahanTugas::dilanggar($trx->created_by, $userId)) {
            throw new \RuntimeException(PemisahanTugas::PESAN);
        }

        $alasan = trim($alasan);

        return DB::transaction(function () use ($trx, $userId, $alasan) {
            // Anti-TOCTOU: kunci baris CoA dana sebelum jurnal pembalik diposting,
            // sejalan dengan jalur `salurkan()` — mencegah pembatalan paralel
            // menghasilkan saldo dana yang tidak konsisten.
            $coaDana = \App\Models\Coa::findByKode(static::akunDana($trx->dana));
            if ($coaDana) {
                \App\Models\Coa::whereKey($coaDana->id)->lockForUpdate()->first();
            }

            // (a) Balik jurnal asal (contra-entry) — saldo dana kembali.
            $jurnalPembalik = $this->postJurnalPembalik($trx, $userId);

            // (b) Tandai void TANPA menghapus baris.
            $trx->update([
                'status' => ZakatTransaksi::STATUS_VOID,
                'void_at' => now(),
                'void_by' => $userId,
                'void_reason' => $alasan !== '' ? mb_substr($alasan, 0, 500) : null,
            ]);

            // (c) Jejak audit append-only (tersegel rantai hash).
            AuditLog::record(
                'VOID_TRANSAKSI_ZAKAT',
                (float) $trx->jumlah,
                $trx->no_referensi,
                sprintf(
                    'Pembatalan %s %s %s sebesar Rp %s oleh user #%s%s',
                    static::labelDana($trx->dana),
                    $trx->jenis,
                    $trx->no_referensi,
                    number_format((float) $trx->jumlah, 2, ',', '.'),
                    $userId ?? 'sistem',
                    $alasan !== '' ? ' — alasan: '.$alasan : ''
                )
            );

            Log::info('ZakatService: transaksi zakat dibatalkan (void).', [
                'no_referensi' => $trx->no_referensi,
                'dana' => $trx->dana,
                'jenis' => $trx->jenis,
                'jumlah' => (float) $trx->jumlah,
                'void_by' => $userId,
                'jurnal_pembalik_id' => $jurnalPembalik->id,
            ]);

            return $trx->fresh();
        });
    }

    /* =====================================================================
     | DUAL CONTROL (maker-checker) PENYALURAN — temuan audit Z-02
     ===================================================================== */

    /**
     * Setujui (approve) pengajuan penyaluran dana amanah — dual control (Z-02).
     *
     * Menutup temuan audit Z-02: penyaluran dana titipan (PSAK 109) tidak boleh
     * diposting sepihak. Baris `menunggu_persetujuan` yang dibuat pengaju baru
     * di-posting jurnalnya SETELAH disetujui pengurus/manajer LAIN. Metode ini:
     *  (a) menegakkan PEMISAHAN TUGAS: penyetuju ≠ pengaju (maker ≠ checker);
     *  (b) memeriksa ulang saldo dana DI DALAM transaksi + penguncian baris CoA
     *      (anti-TOCTOU) sebelum jurnal penyaluran diposting;
     *  (c) memposting jurnal penyaluran & menandai baris `status='posted'`
     *      beserta `disetujui_oleh` / `disetujui_pada`;
     *  (d) mencatat peristiwa ke AuditLog (append-only, tersegel rantai hash).
     *
     * @throws \InvalidArgumentException bila baris tidak ada / tidak menunggu persetujuan
     * @throws \RuntimeException bila pemisahan tugas dilanggar atau jurnal gagal diposting
     */
    public function setujui(int $id, ?int $userId = null): ZakatTransaksi
    {
        $trx = ZakatTransaksi::find($id);

        if (! $trx) {
            throw new \InvalidArgumentException("Transaksi zakat #{$id} tidak ditemukan.");
        }

        if ($trx->status !== ZakatTransaksi::STATUS_MENUNGGU_PERSETUJUAN) {
            throw new \InvalidArgumentException("Transaksi {$trx->no_referensi} tidak dalam status menunggu persetujuan.");
        }

        // GCG — pemisahan tugas: pengaju tidak boleh menyetujui pengajuannya sendiri.
        if (PemisahanTugas::dilanggar($trx->created_by, $userId)) {
            throw new \RuntimeException(PemisahanTugas::PESAN);
        }

        return DB::transaction(function () use ($trx, $userId) {
            $dana = (string) $trx->dana;
            $jumlah = Setting::pembulatanNominal((float) $trx->jumlah);
            $kodeKas = (string) ($trx->kode_kas ?: Coa::KAS_TELLER);
            $tanggal = $trx->tanggal ? $trx->tanggal->toDateString() : now()->toDateString();

            // Anti-TOCTOU: kunci baris CoA dana sebelum membaca saldo & posting.
            $coaDana = \App\Models\Coa::findByKode(static::akunDana($dana));
            if ($coaDana) {
                \App\Models\Coa::whereKey($coaDana->id)->lockForUpdate()->first();
            }

            $saldo = $this->saldo($dana, $tanggal);

            if ($jumlah > $saldo) {
                throw new \InvalidArgumentException(sprintf(
                    'Nominal penyaluran (Rp %s) melebihi saldo dana (%s) sebesar Rp %s.',
                    number_format($jumlah, 2, ',', '.'),
                    static::labelDana($dana),
                    number_format($saldo, 2, ',', '.')
                ));
            }

            $jurnal = $this->jurnal->post(
                (string) $trx->keterangan,
                [
                    ['kode' => static::akunDana($dana), 'debet' => $jumlah],
                    ['kode' => $kodeKas,                'kredit' => $jumlah],
                ],
                'Penyaluran Dana Zakat/Infak',
                null,
                null,
                $userId,
                null,
                null,
                $tanggal
            );

            $this->pastikanJurnal($jurnal, 'penyaluran', (string) $trx->pihak, $jumlah);

            $trx->update([
                'status' => ZakatTransaksi::STATUS_POSTED,
                'jurnal_id' => $jurnal->id,
                'disetujui_oleh' => $userId,
                'disetujui_pada' => now(),
            ]);

            AuditLog::record(
                'PERSETUJUAN_PENYALURAN_ZAKAT',
                $jumlah,
                $trx->no_referensi,
                sprintf(
                    'Persetujuan penyaluran %s %s sebesar Rp %s oleh user #%s (pengaju user #%s)',
                    static::labelDana($dana),
                    $trx->no_referensi,
                    number_format($jumlah, 2, ',', '.'),
                    $userId ?? 'sistem',
                    $trx->created_by ?? 'sistem'
                )
            );

            Log::info('ZakatService: penyaluran zakat disetujui (dual control).', [
                'no_referensi' => $trx->no_referensi,
                'dana' => $dana,
                'jumlah' => $jumlah,
                'disetujui_oleh' => $userId,
                'jurnal_id' => $jurnal->id,
            ]);

            return $trx->fresh();
        });
    }

    /**
     * Tolak pengajuan penyaluran dana amanah — dual control (Z-02).
     *
     * Pengajuan yang ditolak TIDAK pernah diposting (jurnal tidak pernah dibuat);
     * baris ditandai `status='ditolak'` beserta `alasan_tolak` agar jejak audit
     * utuh. Pemisahan tugas tetap ditegakkan (penolak ≠ pengaju).
     *
     * @throws \InvalidArgumentException bila baris tidak ada / tidak menunggu persetujuan
     * @throws \RuntimeException bila pemisahan tugas dilanggar
     */
    public function tolak(int $id, ?int $userId = null, string $alasan = ''): ZakatTransaksi
    {
        $trx = ZakatTransaksi::find($id);

        if (! $trx) {
            throw new \InvalidArgumentException("Transaksi zakat #{$id} tidak ditemukan.");
        }

        if ($trx->status !== ZakatTransaksi::STATUS_MENUNGGU_PERSETUJUAN) {
            throw new \InvalidArgumentException("Transaksi {$trx->no_referensi} tidak dalam status menunggu persetujuan.");
        }

        if (PemisahanTugas::dilanggar($trx->created_by, $userId)) {
            throw new \RuntimeException(PemisahanTugas::PESAN);
        }

        $alasan = trim($alasan);

        return DB::transaction(function () use ($trx, $userId, $alasan) {
            $trx->update([
                'status' => ZakatTransaksi::STATUS_DITOLAK,
                'disetujui_oleh' => $userId,
                'disetujui_pada' => now(),
                'alasan_tolak' => $alasan !== '' ? mb_substr($alasan, 0, 500) : null,
            ]);

            AuditLog::record(
                'PENOLAKAN_PENYALURAN_ZAKAT',
                (float) $trx->jumlah,
                $trx->no_referensi,
                sprintf(
                    'Penolakan penyaluran %s %s sebesar Rp %s oleh user #%s%s',
                    static::labelDana((string) $trx->dana),
                    $trx->no_referensi,
                    number_format((float) $trx->jumlah, 2, ',', '.'),
                    $userId ?? 'sistem',
                    $alasan !== '' ? ' — alasan: '.$alasan : ''
                )
            );

            Log::info('ZakatService: pengajuan penyaluran zakat ditolak (dual control).', [
                'no_referensi' => $trx->no_referensi,
                'jumlah' => (float) $trx->jumlah,
                'ditolak_oleh' => $userId,
                'alasan' => $alasan,
            ]);

            return $trx->fresh();
        });
    }

    /**
     * Posting jurnal pembalik untuk transaksi zakat yang dibatalkan.
     *
     * Membalik PERSIS jurnal asal: setiap baris yang semula DEBET menjadi
     * KREDIT dan sebaliknya. Kode akun diambil dari jurnal asal (bukan
     * diasumsikan), sehingga tetap benar walau penerimaan/penyaluran memakai
     * akun bank alih-alih Kas Teller.
     *
     * @throws \RuntimeException bila jurnal asal tidak dapat dibaca / gagal diposting
     */
    private function postJurnalPembalik(ZakatTransaksi $trx, ?int $userId): Jurnal
    {
        $jurnalAsal = $trx->jurnal_id ? Jurnal::find($trx->jurnal_id) : null;

        $entries = [];

        if ($jurnalAsal) {
            foreach ($jurnalAsal->detail as $detail) {
                // `JurnalDetail` tidak menyimpan `kode_akun`; resolusi dari relasi CoA.
                $kode = (string) ($detail->coa?->kode_akun ?? '');
                if ($kode === '' && $detail->coa_id) {
                    $kode = (string) (\App\Models\Coa::find($detail->coa_id)?->kode_akun ?? '');
                }

                if ($kode === '') {
                    continue;
                }

                // Tukar posisi: debet ↔ kredit (contra-entry).
                $entries[] = [
                    'kode' => $kode,
                    'debet' => Setting::bulatkanUang((float) $detail->kredit),
                    'kredit' => Setting::bulatkanUang((float) $detail->debet),
                ];
            }
        }

        // Tanpa jurnal asal yang dapat dibaca, pembatalan TIDAK boleh mengubah
        // saldo tanpa jejak akuntansi — lempar agar DB::transaction rollback.
        if ($entries === []) {
            throw new \RuntimeException(
                "Jurnal asal transaksi {$trx->no_referensi} tidak dapat dibaca — pembatalan dibatalkan."
            );
        }

        $jurnal = $this->jurnal->post(
            'PEMBATALAN (VOID) - '.$trx->keterangan,
            $entries,
            'Pembatalan Dana Zakat/Infak',
            null,
            null,
            $userId,
            null,
            null,
            now()->toDateString(),
            null,
            null,
            null,
            null,
            true, // mode contra-entry: lewati aturan posisi normal.
            true // jurnal pembalik (void) — tandai permanen agar dapat diaudit.
        );

        if ($jurnal === null) {
            throw new \RuntimeException("Jurnal pembalik transaksi {$trx->no_referensi} gagal diposting.");
        }

        return $jurnal;
    }

    /* =====================================================================
     | SALDO & RINGKASAN (sumber kebenaran: mutasi jurnal CoA)
     ===================================================================== */

    /**
     * Saldo dana dari mutasi jurnal CoA (KREDIT - DEBET) sampai tanggal tertentu.
     *
     * @param  string|null  $sampai  Batas tanggal (Y-m-d); null = seluruh periode.
     */
    public function saldo(string $dana, ?string $sampai = null): float
    {
        $coa = \App\Models\Coa::findByKode(static::akunDana($dana));

        if (! $coa) {
            Log::warning('ZakatService: akun CoA dana tidak ditemukan — saldo dikembalikan nol.', [
                'dana' => $dana,
                'akun' => static::akunDana($dana),
            ]);

            return 0.0;
        }

        return Setting::bulatkanUang($coa->totalKredit($sampai) - $coa->totalDebet($sampai));
    }

    /**
     * Ringkasan sumber & penggunaan dana per jenis dana (PSAK 109).
     *
     * @return array<string, array{dana: string, label: string, akun: string, saldoAwal: float, penerimaan: float, penyaluran: float, saldoAkhir: float, akunTersedia: bool}>
     */
    public function ringkasanSemua(?string $dari = null, ?string $sampai = null): array
    {
        $hasil = [];

        foreach ([ZakatTransaksi::DANA_ZAKAT, ZakatTransaksi::DANA_INFAK_SEDEKAH] as $dana) {
            $hasil[$dana] = $this->ringkasan($dana, $dari, $sampai);
        }

        return $hasil;
    }

    /**
     * Ringkasan satu jenis dana: saldo awal, penerimaan, penyaluran, saldo akhir.
     *
     * @return array{dana: string, label: string, akun: string, saldoAwal: float, penerimaan: float, penyaluran: float, saldoAkhir: float, akunTersedia: bool}
     */
    public function ringkasan(string $dana, ?string $dari = null, ?string $sampai = null): array
    {
        $akun = static::akunDana($dana);
        $coa = \App\Models\Coa::findByKode($akun);

        if (! $coa) {
            return [
                'dana' => $dana,
                'label' => static::labelDana($dana),
                'akun' => $akun,
                'saldoAwal' => 0.0,
                'penerimaan' => 0.0,
                'penyaluran' => 0.0,
                'saldoAkhir' => 0.0,
                'akunTersedia' => false,
            ];
        }

        // Saldo awal = mutasi sebelum tanggal `dari` (bila periode dibatasi).
        //
        // PENTING: saldo awal WAJIB menjumlahkan mutasi SELURUH jurnal Posted
        // akun dana — bukan hanya yang terhubung ke tabel `zakat_transaksis`.
        // Koreksi/adjustment manual yang diposting langsung ke CoA tetap harus
        // ikut terhitung, agar PSAK 109 menyajikan saldo dana yang utuh.
        $saldoAwal = 0.0;

        if ($dari !== null) {
            $sebelum = Carbon::parse($dari)->subDay()->toDateString();

            $aggAwal = DB::table('jurnal_detail')
                ->join('jurnal', 'jurnal.id', '=', 'jurnal_detail.jurnal_id')
                ->where('jurnal_detail.coa_id', $coa->id)
                ->where('jurnal.status', 'Posted')
                ->whereDate('jurnal.tanggal_jurnal', '<=', $sebelum)
                ->selectRaw('COALESCE(SUM(jurnal_detail.kredit), 0) AS total_kredit, COALESCE(SUM(jurnal_detail.debet), 0) AS total_debet')
                ->first();

            $saldoAwal = Setting::bulatkanUang((float) ($aggAwal->total_kredit ?? 0) - (float) ($aggAwal->total_debet ?? 0));
        }

        $query = DB::table('jurnal_detail')
            ->join('jurnal', 'jurnal.id', '=', 'jurnal_detail.jurnal_id')
            ->where('jurnal_detail.coa_id', $coa->id)
            ->where('jurnal.status', 'Posted');

        if ($dari !== null) {
            $query->whereDate('jurnal.tanggal_jurnal', '>=', $dari);
        }

        if ($sampai !== null) {
            $query->whereDate('jurnal.tanggal_jurnal', '<=', $sampai);
        }

        $agg = (clone $query)
            ->selectRaw('COALESCE(SUM(jurnal_detail.kredit), 0) AS total_kredit, COALESCE(SUM(jurnal_detail.debet), 0) AS total_debet')
            ->first();

        $penerimaan = Setting::bulatkanUang((float) ($agg->total_kredit ?? 0));
        $penyaluran = Setting::bulatkanUang((float) ($agg->total_debet ?? 0));

        return [
            'dana' => $dana,
            'label' => static::labelDana($dana),
            'akun' => $akun,
            'saldoAwal' => $saldoAwal,
            'penerimaan' => $penerimaan,
            'penyaluran' => $penyaluran,
            'saldoAkhir' => Setting::bulatkanUang($saldoAwal + $penerimaan - $penyaluran),
            'akunTersedia' => true,
        ];
    }

    /* =====================================================================
     | KONTROL SILANG & VALIDASI (kepatuhan PSAK 109)
     ===================================================================== */

    /**
     * Validasi kepatuhan Modul Zakat.
     *
     * Memeriksa:
     *  1. Ketersediaan akun CoA 2-3002 / 2-3004.
     *  2. Saldo dana tidak negatif (penyaluran melebihi dana terkumpul).
     *  3. Setiap baris buku besar benar-benar terhubung ke jurnal yang diposting.
     *  4. Saldo per mutasi jurnal konsisten dengan buku besar transaksi modul.
     *  5. Seluruh penyaluran zakat memakai asnaf yang sah.
     *
     * @return array{valid: bool, catatan: array<int, string>}
     */
    public function validasi(): array
    {
        $catatan = [];

        foreach ([ZakatTransaksi::DANA_ZAKAT, ZakatTransaksi::DANA_INFAK_SEDEKAH] as $dana) {
            $ringkasan = $this->ringkasan($dana);

            if (! $ringkasan['akunTersedia']) {
                $catatan[] = "Akun CoA {$ringkasan['akun']} untuk {$ringkasan['label']} tidak ditemukan.";

                continue;
            }

            if ($ringkasan['saldoAkhir'] < -Setting::toleransiSelisihJurnal()) {
                $catatan[] = sprintf(
                    'Saldo %s negatif (Rp %s) — penyaluran melebihi dana terkumpul.',
                    $ringkasan['label'],
                    number_format($ringkasan['saldoAkhir'], 2, ',', '.')
                );
            }

            // Kontrol silang: mutasi jurnal vs buku besar transaksi modul.
            // Baris VOID dikecualikan dari buku besar karena jurnalnya sudah
            // dibalik (Z-01).
            $bukuBesar = DB::table('zakat_transaksis')
                ->where('dana', $dana)
                ->where('status', ZakatTransaksi::STATUS_POSTED)
                ->selectRaw("COALESCE(SUM(CASE WHEN jenis = 'penerimaan' THEN jumlah ELSE 0 END), 0) AS penerimaan, COALESCE(SUM(CASE WHEN jenis = 'penyaluran' THEN jumlah ELSE 0 END), 0) AS penyaluran, COUNT(*) AS baris")
                ->first();

            // Jurnal PEMBALIK baris void (contra-entry) menyentuh akun dana yang
            // SAMA, sehingga penerimaan/penyaluran bruto pada `ringkasan()` ikut
            // memuat nilai void (jurnal asal tetap Posted + jurnal pembalik).
            // Netokan dengan TOTAL nilai baris void pada kedua sisi agar kontrol
            // silang membandingkan hal yang setara — tanpa selisih palsu.
            $void = DB::table('zakat_transaksis')
                ->where('dana', $dana)
                ->where('status', ZakatTransaksi::STATUS_VOID)
                ->selectRaw("COALESCE(SUM(CASE WHEN jenis = 'penerimaan' THEN jumlah ELSE 0 END), 0) AS penerimaan, COALESCE(SUM(CASE WHEN jenis = 'penyaluran' THEN jumlah ELSE 0 END), 0) AS penyaluran")
                ->first();

            $voidTotal = Setting::bulatkanUang((float) ($void->penerimaan ?? 0) + (float) ($void->penyaluran ?? 0));

            $jurnalPenerimaan = Setting::bulatkanUang($ringkasan['penerimaan'] - $voidTotal);
            $jurnalPenyaluran = Setting::bulatkanUang($ringkasan['penyaluran'] - $voidTotal);

            $selisihPenerimaan = Setting::bulatkanUang((float) ($bukuBesar->penerimaan ?? 0) - $jurnalPenerimaan);
            $selisihPenyaluran = Setting::bulatkanUang((float) ($bukuBesar->penyaluran ?? 0) - $jurnalPenyaluran);

            if (abs($selisihPenerimaan) >= Setting::toleransiSelisihJurnal() || abs($selisihPenyaluran) >= Setting::toleransiSelisihJurnal()) {
                $catatan[] = sprintf(
                    'Selisih buku besar %s dengan mutasi jurnal — penerimaan Rp %s, penyaluran Rp %s.',
                    $ringkasan['label'],
                    number_format($selisihPenerimaan, 2, ',', '.'),
                    number_format($selisihPenyaluran, 2, ',', '.')
                );
            }
        }

        // Baris buku besar tanpa jurnal (jejak audit putus).
        // Hanya baris BERLAKU (status posted) yang wajib punya jurnal; baris
        // `menunggu_persetujuan`/`ditolak` (Z-02) memang belum/tidak berjurnal.
        // Dikelompokkan dalam closure agar klausa OR tidak mendahului (mengalahkan)
        // filter lain yang mungkin ditambahkan kemudian (precedence AND > OR).
        $tanpaJurnal = ZakatTransaksi::query()
            ->where('status', ZakatTransaksi::STATUS_POSTED)
            ->where(function ($q) {
                $q->whereNull('jurnal_id')
                    ->orWhereDoesntHave('jurnal');
            })
            ->count();

        if ($tanpaJurnal > 0) {
            $catatan[] = "{$tanpaJurnal} baris buku besar zakat tidak terhubung ke jurnal yang valid.";
        }

        // Penyaluran zakat wajib memakai asnaf yang sah. Baris VOID (Z-01)
        // dikecualikan karena sudah tidak berlaku (jurnalnya dibalik).
        $asnafTidakSah = ZakatTransaksi::query()
            ->where('dana', ZakatTransaksi::DANA_ZAKAT)
            ->where('jenis', ZakatTransaksi::JENIS_PENYALURAN)
            ->where('status', ZakatTransaksi::STATUS_POSTED)
            ->whereNotIn('kategori', array_keys(static::ASNAF))
            ->count();

        if ($asnafTidakSah > 0) {
            $catatan[] = "{$asnafTidakSah} penyaluran zakat memakai asnaf di luar 8 golongan (QS At-Taubah 60).";
        }

        if ($catatan !== []) {
            Log::error('ZakatService: validasi kepatuhan Modul Zakat gagal.', ['catatan' => $catatan]);
        }

        return ['valid' => $catatan === [], 'catatan' => $catatan];
    }

    /* =====================================================================
     | HELPER
     ===================================================================== */

    /** Label manusiawi jenis dana. */
    public static function labelDana(string $dana): string
    {
        return $dana === ZakatTransaksi::DANA_INFAK_SEDEKAH ? 'Dana Infak & Sedekah' : 'Dana Zakat';
    }

    /**
     * Nomor referensi unik: ZKT-TERIMA-YYYYMMDD-XXXX (aman terhadap penghapusan).
     *
     * Z-04 — anti race condition. Pola lama `MAX(no_referensi)+1` rentan tabrakan
     * bila dua permintaan konkuren membaca nilai maksimum yang sama. Nomor unik
     * dijamin kolom `no_referensi` (UNIQUE), maka penulisan konkuren akan gagal
     * dengan pelanggaran unique; {@see withRetryReferensi()} membuat ulang nomor
     * (dengan memperbarui `MAX` yang sudah memuat baris pemenang) lalu mencoba lagi.
     */
    private static function nomorReferensi(string $aksi, string $dana): string
    {
        $prefix = ($dana === ZakatTransaksi::DANA_INFAK_SEDEKAH ? 'IDS-' : 'ZKT-').$aksi.'-'.now()->format('Ymd').'-';
        $terakhir = (string) ZakatTransaksi::where('no_referensi', 'like', $prefix.'%')->max('no_referensi');
        $urut = $terakhir !== '' ? ((int) substr($terakhir, -4)) + 1 : 1;

        return $prefix.str_pad((string) $urut, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Z-04 — jalankan generator nomor referensi dengan RETRY bila terjadi
     * pelanggaran keunikan (`no_referensi` UNIQUE). Setiap percobaan ulang
     * menghitung ulang `MAX` sehingga nomor yang sudah terpakai pemenang lomba
     * konkuren tidak dipakai lagi. Error selain duplikat diteruskan apa adanya.
     *
     * @param  callable(string): ZakatTransaksi  $simpan  menerima nomor referensi, mengembalikan baris tersimpan
     * @param  string|null  $nomorEksplisit  Nomor dari pemanggil (bila ada) — dipakai apa adanya tanpa retry
     */
    private function withRetryReferensi(string $aksi, string $dana, callable $simpan, ?string $nomorEksplisit = null): ZakatTransaksi
    {
        // Nomor eksplisit dari pemanggil dihormati apa adanya (mis. impor/migrasi).
        if ($nomorEksplisit !== null && $nomorEksplisit !== '') {
            return $simpan($nomorEksplisit);
        }

        $percobaanMaks = 5;

        for ($percobaan = 1; ; $percobaan++) {
            try {
                return $simpan(static::nomorReferensi($aksi, $dana));
            } catch (QueryException $e) {
                if ($percobaan >= $percobaanMaks || ! static::galatDuplikatReferensi($e)) {
                    throw $e;
                }

                Log::warning('ZakatService: nomor referensi bentrok, mencoba ulang.', [
                    'aksi' => $aksi,
                    'dana' => $dana,
                    'percobaan' => $percobaan,
                ]);

                // Jeda mikro acak untuk memecah pola tabrakan dua permintaan konkuren.
                usleep(random_int(1000, 5000));
            }
        }
    }

    /** Apakah QueryException berasal dari pelanggaran keunikan `no_referensi`? */
    private static function galatDuplikatReferensi(QueryException $e): bool
    {
        $pesan = $e->getMessage();

        return str_contains($pesan, 'no_referensi')
            || str_contains($pesan, 'zakat_transaksis_no_referensi_unique');
    }

    /**
     * Jurnal WAJIB berhasil. `AutoJournalingService::post()` kini MELEMPAR
     * `JournalPostingException` saat CoA tidak ditemukan atau pemisahan dana
     * dilanggar (bukan lagi `return null`), sehingga DB::transaction pemanggil
     * ikut rollback. Guard ini dipertahankan sebagai pertahanan berlapis agar
     * transaksi tidak pernah tercatat tanpa jejak akuntansi.
     */
    private function pastikanJurnal(?Jurnal $jurnal, string $aksi, string $pihak, float $jumlah): void
    {
        if ($jurnal) {
            return;
        }

        Log::error("ZakatService: jurnal {$aksi} dana zakat/infak gagal diposting — pencatatan dibatalkan.", [
            'pihak' => $pihak,
            'jumlah' => $jumlah,
        ]);

        throw new \RuntimeException("Jurnal {$aksi} dana zakat/infak gagal dibuat. Transaksi tidak dicatat.");
    }

    /**
     * Pastikan saldo dana mencukupi (advisory, TANPA penguncian) — dipakai saat
     * PENGAJUAN penyaluran ber-ambang (dual control Z-02) agar pengaju mendapat
     * umpan balik awal. Pemeriksaan otoritatif tetap dilakukan saat persetujuan
     * di dalam transaksi dengan penguncian baris CoA (anti-TOCTOU).
     *
     * @throws \InvalidArgumentException bila jumlah melebihi saldo dana
     */
    private function pastikanSaldoCukup(string $dana, float $jumlah, string $tanggal): void
    {
        $saldo = $this->saldo($dana, $tanggal);

        if ($jumlah > $saldo) {
            throw new \InvalidArgumentException(sprintf(
                'Nominal penyaluran (Rp %s) melebihi saldo dana (%s) sebesar Rp %s.',
                number_format($jumlah, 2, ',', '.'),
                static::labelDana($dana),
                number_format($saldo, 2, ',', '.')
            ));
        }
    }
}
