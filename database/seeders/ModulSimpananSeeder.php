<?php

namespace Database\Seeders;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\AutodebitUsulan;
use App\Models\Coa;
use App\Models\JenisSimpanan;
use App\Models\JurnalDetail;
use App\Models\KebijakanKoperasi;
use App\Models\MandatorySavingsBill;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\AutodebitService;
use App\Services\AutoJournalingService;
use App\Services\MandatorySavingsService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeder komprehensif modul Simpanan — Bagian 7 spesifikasi.
 *
 * Menyiapkan TIGA skenario uji yang saling melengkapi:
 *
 *  ┌── Skenario A — Anggota Baru (PENDING) ────────────────────────────────
 *  │    CIF terdaftar, rekening Pokok & Wajib dibuka, tagihan Simpanan
 *  │    Wajib bulan ke-1 terbit, namun BELUM dibayar → `status_anggota`
 *  │    tetap PENDING (menunggu setoran awal di Teller). Tanpa jurnal.
 *  │
 *  ├── Skenario B — Anggota Aktif AUTO-DEBIT ──────────────────────────────
 *  │    Setoran awal dilunasi Teller (jurnal PSAK 101: DEBET 1-1001
 *  │    | KREDIT 3-1001 + 3-1002) → status ACTIVE. Simpanan Sukarela
 *  │    didanai, lalu tagihan bulanan diusulkan (Pusat Konfirmasi Auto-
 *  │    Debit) dan BARU dilunasi setelah dikonfirmasi petugas — seeder
 *  │    TIDAK memotong saldo secara langsung (jurnal DEBET 2-1001 |
 *  │    KREDIT 3-1002).
 *  │
 *  └── Skenario C — Anggota Aktif PEMBAYARAN MANUAL via Teller ────────────
 *       `is_auto_debit = false` → tagihan dibayar tunai di Teller
 *       (jurnal DEBET 1-1001 | KREDIT 3-1002, `payment_method = CASH`).
 *       Periode terakhir sengaja dibiarkan OVERDUE sebagai bahan uji
 *       penahanan SHU (HOLD).
 *
 * Seluruh nominal dibaca dinamis dari `kebijakan_koperasis` melalui
 * `KebijakanKoperasi`/`Setting` — tidak ada angka yang di-hardcode.
 *
 * Seeder bersifat IDEMPOTEN: operasi memakai `updateOrCreate`/`firstOrCreate`
 * dan service resmi (`MandatorySavingsService::issueBill()` yang memakai
 * `firstOrCreate` pada (member_id, bill_period), serta guard anti double-posting
 * pada `jurnalBundlingSetoranAwal()`), sehingga `db:seed` boleh diulang
 * tanpa menggandakan tagihan, jurnal, maupun saldo.
 */
class ModulSimpananSeeder extends Seeder
{
    /** CoA Kas Teller / Bank — lawan setoran tunai (PSAK 101). */
    private const COA_KAS = '1-1001';

    /** CoA Simpanan Sukarela — sumber dana auto-debit. */
    private const COA_SUKARELA = '2-1001';

    /** CoA Simpanan Pokok — Modal Anggota. */
    private const COA_POKOK = '3-1001';

    /** CoA Simpanan Wajib — Modal Anggota (Ekuitas). */
    private const COA_WAJIB = '3-1002';

    /** Saldo awal Simpanan Sukarela skenario auto-debit (sumber dana). */
    private const SALDO_SUKARELA_AUTO_DEBIT = 5000000.00;

    /** Saldo awal Simpanan Sukarela skenario manual. */
    private const SALDO_SUKARELA_MANUAL = 2000000.00;

    /** Jumlah bulan tagihan yang digenerate untuk skenario B & C. */
    private const JUMLAH_BULAN = 6;

    private ?User $teller = null;

    private ?User $pencatat = null;

    public function run(): void
    {
        $this->teller = User::where('role', 'teller')->first()
            ?? User::where('role', 'admin')->first();

        $this->pencatat = $this->teller
            ?? User::where('role', 'manajer')->first();

        $this->command?->info('══ ModulSimpananSeeder — 3 skenario uji modul Simpanan ══');

        $this->skenarioA_anggotaBaruPending();
        $this->skenarioB_anggotaAktifAutoDebit();
        $this->skenarioC_anggotaAktifTeller();

        // Tambal mandiri: pastikan transaksi demo lama tidak bertanggal "hari ini".
        $this->rapikanTanggalDemo();

        $this->laporkan();
    }

    /**
     * Aktifkan izin auto-debit pada master produk Simpanan Wajib (DINAMIS).
     *
     * Skenario B mengandalkan JALUR B (auto-debit pindah buku), yang hanya
     * aktif bila `jenis_simpanans.allow_auto_debit == true` untuk produk
     * Simpanan Wajib. Idempoten: tidak melakukan apa pun bila sudah aktif.
     */
    private function aktifkanAutoDebitProdukWajib(): void
    {
        $produk = JenisSimpanan::where('nama', 'Simpanan Wajib')
            ->orWhere('code', JenisSimpanan::CODE_WAJIB)
            ->first();

        if ($produk !== null && ! $produk->allow_auto_debit) {
            $produk->update(['allow_auto_debit' => true]);
            $this->command?->line('  [produk] allow_auto_debit Simpanan Wajib diaktifkan (Jalur B).');
        }
    }

    /* =====================================================================
     | SKENARIO A — ANGGOTA BARU BERSTATUS PENDING
     |===================================================================== */

    /**
     * Anggota baru: CIF + rekening Pokok/Wajib sudah dibuka dan tagihan
     * Simpanan Wajib bulan ke-1 sudah diterbitkan, TETAPI belum dibayar.
     * Status keanggotaan WAJIB tetap PENDING tanpa jurnal apa pun.
     */
    private function skenarioA_anggotaBaruPending(): void
    {
        $anggota = $this->pastikanAnggota([
            'no_anggota' => 'ANG-2026-0021',
            'nama_lengkap' => 'Nadia Puspita',
            'nik' => '3201014103960021',
            'tempat_lahir' => 'Bogor',
            'tanggal_lahir' => '1996-03-01',
            'jenis_kelamin' => 'Perempuan',
            'status_perkawinan' => 'Belum Menikah',
            'alamat' => 'Jl. Pajajaran No. 21',
            'rt_rw' => '004/009',
            'kelurahan' => 'Tegallega',
            'kecamatan' => 'Bogor Tengah',
            'kota' => 'Bogor',
            'provinsi' => 'Jawa Barat',
            'kode_pos' => '16129',
            'no_telepon' => '0251-334455',
            'no_hp' => '081234500021',
            'email' => 'nadia.puspita@koperasi.test',
            'pekerjaan' => 'Karyawan Swasta',
            'nama_ibu_kandung' => 'Retno Wulandari',
            // Pendaftar baru: WAJIB PENDING sampai setoran awal dibayar Teller.
            'status_anggota' => Anggota::STATUS_PENDING,
            'is_auto_debit' => false,
            'tanggal_gabung' => now()->subDays(3)->toDateString(),
            'simpanan_pokok' => KebijakanKoperasi::simpananPokokWajib(),
            'simpanan_wajib' => KebijakanKoperasi::simpananWajibBulanan(),
            'keterangan' => 'Skenario A — anggota baru, menunggu setoran awal di Teller (PENDING).',
        ]);

        // Rekening Pokok & Wajib dibuka dengan saldo 0 (setoran awal belum dibayar).
        $this->pastikanRekening($anggota, 'Simpanan Pokok', 'Wadiah', '01');
        $this->pastikanRekening($anggota, 'Simpanan Wajib', 'Mudharabah', '02');

        // Tagihan Simpanan Wajib bulan ke-1 terbit, nominal DINAMIS dari
        // `kebijakan_koperasis.mandatory_monthly_savings`, status UNPAID.
        $periode = MandatorySavingsBill::periodDari(Carbon::parse($anggota->tanggal_gabung));
        $this->pastikanTagihan($anggota, $periode, MandatorySavingsBill::STATUS_UNPAID);

        $this->command?->line(sprintf(
            '  [A] %s %s — status %s, tagihan %s UNPAID, tanpa jurnal.',
            $anggota->no_anggota,
            $anggota->nama_lengkap,
            $anggota->fresh()->status_anggota,
            $periode
        ));
    }

    /* =====================================================================
     | SKENARIO B — ANGGOTA AKTIF DENGAN AUTO-DEBIT
     |===================================================================== */

    /**
     * Anggota aktif dengan preferensi `is_auto_debit = true`.
     *
     * Alur: setoran awal dibayar Teller → ACTIVE → Simpanan Sukarela didanai
     * → bulan-bulan berikutnya dipotong otomatis dari saldo Sukarela.
     */
    private function skenarioB_anggotaAktifAutoDebit(): void
    {
        /* Produk Simpanan Wajib WAJIB mengizinkan auto-debit agar skenario ini
         | benar-benar memakai JALUR B. Diaktifkan DINAMIS lewat master produk
         | (`jenis_simpanans.allow_auto_debit`), bukan hardcode di kode. */
        $this->aktifkanAutoDebitProdukWajib();

        $anggota = $this->pastikanAnggota([
            'no_anggota' => 'ANG-2026-0022',
            'nama_lengkap' => 'Gilang Prasetyo',
            'nik' => '3201011207900022',
            'tempat_lahir' => 'Bandung',
            'tanggal_lahir' => '1990-07-12',
            'jenis_kelamin' => 'Laki-laki',
            'status_perkawinan' => 'Menikah',
            'alamat' => 'Jl. Setiabudi No. 88',
            'rt_rw' => '007/003',
            'kelurahan' => 'Gegerkalong',
            'kecamatan' => 'Sukasari',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'kode_pos' => '40153',
            'no_telepon' => '022-7788990',
            'no_hp' => '081234500022',
            'email' => 'gilang.prasetyo@koperasi.test',
            'pekerjaan' => 'Teknisi',
            'nama_ibu_kandung' => 'Sri Handayani',
            'status_anggota' => Anggota::STATUS_ACTIVE,
            // Preferensi auto-debit: saldo Sukarela dipotong otomatis tiap bulan.
            'is_auto_debit' => true,
            'tanggal_gabung' => Carbon::today()->subMonths(self::JUMLAH_BULAN - 1)->startOfMonth()->toDateString(),
            'simpanan_pokok' => KebijakanKoperasi::simpananPokokWajib(),
            'simpanan_wajib' => KebijakanKoperasi::simpananWajibBulanan(),
            'keterangan' => 'Skenario B — anggota aktif dengan auto-debit dari Simpanan Sukarela.',
        ]);

        /* Data lama yang mungkin sudah ada: pastikan tidak ada tagihan yang
         | “tertinggal” dari generasi sebelumnya agar perhitungan periode bersih. */

        $this->lunasiSetoranAwalTeller($anggota);

        $rekeningSukarela = $this->pastikanRekening($anggota, 'Simpanan Sukarela', 'Mudharabah', '03');
        $this->pastikanSaldoSukarela($anggota, $rekeningSukarela, self::SALDO_SUKARELA_AUTO_DEBIT);

        $periodeList = $this->periodeTagihan($anggota);
        $sudahDibayar = [];

        foreach ($periodeList as $periode) {
            $bill = $this->pastikanTagihan($anggota, $periode);

            if ($bill->isPaid()) {
                $sudahDibayar[] = $periode;

                continue;
            }

            /* KEBIJAKAN "TIDAK ADA AUTO-DEBIT TANPA KONFIRMASI":
             | Seeder TIDAK BOLEH memotong saldo secara langsung. Ia berlaku
             | seperti scheduler: hanya MENGUSULKAN usulan ke Pusat Konfirmasi,
             | lalu seeder mengonfirmasi usulan itu sebagai pengguna berwenang
             | (simulasi tindakan petugas), baru dana dipotong. Dengan begitu
             | tetap konsisten dengan gerbang konfirmasi di produksi. */
            app(AutodebitService::class)->usulkan(
                AutodebitUsulan::KANAL_SIMPANAN_WAJIB,
                (int) $bill->id,
                $anggota,
                (float) $bill->amount,
                [
                    'periode' => $periode,
                    'alasan' => 'SEEDER_SKENARIO_B',
                    'catatan' => 'Usulan auto-debit periode '.$periode.' (seeder).',
                    'referensi_tipe' => MandatorySavingsBill::class,
                ]
            );

            $usulan = AutodebitUsulan::menungguKonfirmasi()
                ->where('kanal', AutodebitUsulan::KANAL_SIMPANAN_WAJIB)
                ->where('referensi_id', $bill->id)
                ->first();

            if ($usulan && $this->pencatat) {
                app(AutodebitService::class)->konfirmasi(
                    $usulan,
                    (int) $this->pencatat->id,
                    'Konfirmasi simulasi petugas (seeder skenario B).'
                );
            }

            if ($bill->fresh()->isPaid()) {
                $sudahDibayar[] = $periode;
            }
        }

        $this->command?->line(sprintf(
            '  [B] %s %s — status %s, auto-debit %d periode lunas, sisa saldo Sukarela Rp %s.',
            $anggota->no_anggota,
            $anggota->nama_lengkap,
            $anggota->fresh()->status_anggota,
            count($sudahDibayar),
            number_format((float) $rekeningSukarela->fresh()->saldo, 0, ',', '.')
        ));
    }

    /* =====================================================================
     | SKENARIO C — ANGGOTA AKTIF DENGAN PEMBAYARAN MANUAL VIA TELLER
     |===================================================================== */

    /**
     * Anggota aktif tanpa auto-debit: tagihan disetor TUNAI ke Teller.
     * Periode terakhir sengaja dibiarkan OVERDUE sebagai bahan uji
     * kalkulasi SHU prorated & penahanan SHU (HOLD).
     */
    private function skenarioC_anggotaAktifTeller(): void
    {
        $anggota = $this->pastikanAnggota([
            'no_anggota' => 'ANG-2026-0023',
            'nama_lengkap' => 'Rina Kusumawati',
            'nik' => '3201015508930023',
            'tempat_lahir' => 'Cimahi',
            'tanggal_lahir' => '1993-08-15',
            'jenis_kelamin' => 'Perempuan',
            'status_perkawinan' => 'Menikah',
            'alamat' => 'Jl. Cibabat No. 45',
            'rt_rw' => '002/011',
            'kelurahan' => 'Cibabat',
            'kecamatan' => 'Cimahi Utara',
            'kota' => 'Cimahi',
            'provinsi' => 'Jawa Barat',
            'kode_pos' => '40513',
            'no_telepon' => '022-6655443',
            'no_hp' => '081234500023',
            'email' => 'rina.kusumawati@koperasi.test',
            'pekerjaan' => 'Wirausaha',
            'nama_ibu_kandung' => 'Endang Suryani',
            'status_anggota' => Anggota::STATUS_ACTIVE,
            // Menyetor manual ke Teller setiap bulan.
            'is_auto_debit' => false,
            'tanggal_gabung' => Carbon::today()->subMonths(self::JUMLAH_BULAN - 1)->startOfMonth()->toDateString(),
            'simpanan_pokok' => KebijakanKoperasi::simpananPokokWajib(),
            'simpanan_wajib' => KebijakanKoperasi::simpananWajibBulanan(),
            'keterangan' => 'Skenario C — anggota aktif, Simpanan Wajib disetor manual via Teller.',
        ]);

        $this->lunasiSetoranAwalTeller($anggota);

        $rekeningWajib = $this->pastikanRekening($anggota, 'Simpanan Wajib', 'Mudharabah', '02');
        $rekeningSukarela = $this->pastikanRekening($anggota, 'Simpanan Sukarela', 'Mudharabah', '03');
        $this->pastikanSaldoSukarela($anggota, $rekeningSukarela, self::SALDO_SUKARELA_MANUAL);

        $periodeList = $this->periodeTagihan($anggota);
        $sudahDibayar = [];
        $periodeTerakhir = $periodeList[array_key_last($periodeList)] ?? null;

        foreach ($periodeList as $periode) {
            $bill = $this->pastikanTagihan($anggota, $periode);

            if ($bill->isPaid()) {
                $sudahDibayar[] = $periode;

                continue;
            }

            /* Periode TERAKHIR sengaja dilewatkan jatuh temponya agar menjadi
             | OVERDUE — bahan uji penahanan SHU (§6). */
            if ($periode === $periodeTerakhir) {
                if (! $bill->isOverdue()) {
                    app(MandatorySavingsService::class)->tandaiOverdue($bill, $anggota, $rekeningWajib);
                }

                continue;
            }

            $tanggal = app(MandatorySavingsService::class)
                ->tanggalJatuhTempo($anggota, Carbon::parse($periode.'-01'));

            // Jalur TELLER: kas masuk + jurnal DEBET 1-1001 | KREDIT 3-1002.
            app(MandatorySavingsService::class)->bayarViaTeller(
                $bill,
                MandatorySavingsBill::METODE_CASH,
                $tanggal,
                $this->teller?->id
            );

            if ($bill->fresh()->isPaid()) {
                $sudahDibayar[] = $periode;
            }
        }

        $overdue = MandatorySavingsBill::where('member_id', $anggota->id)
            ->where('status', MandatorySavingsBill::STATUS_OVERDUE)
            ->count();

        $this->command?->line(sprintf(
            '  [C] %s %s — status %s, %d periode lunas manual (CASH), %d tagihan OVERDUE.',
            $anggota->no_anggota,
            $anggota->nama_lengkap,
            $anggota->fresh()->status_anggota,
            count($sudahDibayar),
            $overdue
        ));
    }

    /* =====================================================================
     | HELPER — DATA MASTER
     |===================================================================== */

    /**
     * Upsert anggota berdasarkan `no_anggota` (idempoten).
     * `no_cif` mengikuti `no_anggota` agar CIF selalu tersedia (kolom unik).
     */
    private function pastikanAnggota(array $atribut): Anggota
    {
        $atribut['no_cif'] = $atribut['no_cif'] ?? $atribut['no_anggota'];

        return Anggota::updateOrCreate(
            ['no_anggota' => $atribut['no_anggota']],
            $atribut
        );
    }

    /**
     * Pastikan rekening anggota tersedia (idempoten; saldo berjalan TIDAK
     * ditimpa bila rekening sudah ada).
     *
     * `akun_id` dipetakan dari kode CoA master jenis simpanan ke tabel `akun`
     * (kode legacy 4 digit) — konsisten dengan data hasil `RekeningSeeder`.
     */
    private function pastikanRekening(Anggota $anggota, string $jenis, string $akad, string $suffix): Rekening
    {
        $noRekening = sprintf('REK-%s-%s', $this->kodeRekening($anggota), $suffix);

        return Rekening::firstOrCreate(
            ['no_rekening' => $noRekening],
            [
                'anggota_id' => $anggota->id,
                'akun_id' => $this->akunId($jenis),
                'jenis_rekening' => $jenis,
                'akad' => $akad,
                'saldo' => 0,
                'batas_minimum' => 0,
                'tanggal_buka' => $anggota->tanggal_gabung,
                'status_rekening' => 'Aktif',
                'keterangan' => sprintf('%s %s', $jenis, $anggota->nama_lengkap),
            ]
        );
    }

    /** Potongan `no_anggota` untuk membentuk `no_rekening`, mis. ANG-2026-0022 → 0022-2026. */
    private function kodeRekening(Anggota $anggota): string
    {
        $bagian = explode('-', (string) $anggota->no_anggota);

        if (count($bagian) === 3) {
            return sprintf('%s-%s', $bagian[2], $bagian[1]);
        }

        return (string) $anggota->id;
    }

    /** Id akun tabel `akun` untuk jenis simpanan tertentu (peta CoA → kode legacy). */
    private function akunId(string $jenis): ?int
    {
        $kodeAkun = match ($jenis) {
            'Simpanan Pokok' => '1131',
            'Simpanan Wajib' => '1132',
            'Simpanan Sukarela' => '1133',
            default => null,
        };

        return $kodeAkun === null
            ? null
            : Akun::where('kode_akun', $kodeAkun)->value('id');
    }

    /* =====================================================================
     | HELPER — SETORAN AWAL, TAGIHAN & SALDO
     |===================================================================== */

    /**
     * Pastikan rekening Pokok & Wajib terbentuk dan setoran awal bundling
     * sudah dilunasi.
     *
     * Meniru alur `CifController::store()` (jalur resmi yang sama dipakai UI):
     *  - rekening Pokok & Wajib dibuat dengan `batas_minimum` mengikuti
     *    `kebijakan_koperasis.minimum_pembukaan_rekening` bila jenis simpanan
     *    mensyaratkannya;
     *  - transaksi setoran dicatat per komponen (Pokok & Wajib);
     *  - jurnal PSAK 101 diposting via `jurnalBundlingSetoranAwal()`
     *    (DEBET 1-1001 | KREDIT 3-1001 + 3-1002) — memiliki guard anti
     *    double-posting sehingga aman dijalankan berulang;
     *  - status anggota dinormalisasi menjadi ACTIVE.
     */
    private function lunasiSetoranAwalTeller(Anggota $anggota): void
    {
        $pokok = KebijakanKoperasi::simpananPokokWajib();
        $wajib = KebijakanKoperasi::simpananWajibBulanan();

        if ($pokok <= 0 && $wajib <= 0) {
            return;
        }

        $rekeningPokok = $this->pastikanRekening($anggota, 'Simpanan Pokok', 'Wadiah', '01');
        $rekeningWajib = $this->pastikanRekening($anggota, 'Simpanan Wajib', 'Mudharabah', '02');

        // Sudah pernah dilunasi (saldo Pokok sudah terisi sesuai parameter).
        if ((float) $rekeningPokok->fresh()->saldo >= $pokok && $pokok > 0) {
            $this->pastikanStatusAktif($anggota);

            return;
        }

        DB::transaction(function () use ($anggota, $rekeningPokok, $rekeningWajib, $pokok, $wajib) {
            $keterangan = 'Setoran awal anggota (bundling) — '.$anggota->nama_lengkap;

            /* ⚠️ TANGGAL HISTORIS DETERMINISTIK — JANGAN `now()`.
             | Data demo TIDAK BOLEH tercatat sebagai transaksi "hari ini",
             | karena akan mencemari kartu ringkasan dasbor Teller
             | (`Transaksi Hari Ini`, setoran/penarikan hari ini). Tanggal
             | diturunkan dari `tanggal_gabung` anggota (pola yang sama dipakai
             | `MandatorySavingsBill::periodDari()`), sehingga stabil di setiap
             | `db:seed` dan selalu berada di masa lampau untuk demo. */
            $waktuSetoranAwal = Carbon::parse($anggota->tanggal_gabung)
                ->startOfMonth()
                ->setTime(9, 15, 0);

            $transaksiPokok = Transaksi::firstOrCreate([
                'no_transaksi' => $this->noTransaksi('POKOK', $anggota->id),
            ], [
                'waktu_transaksi' => $waktuSetoranAwal,
                'anggota_id' => $anggota->id,
                'rekening_id' => $rekeningPokok->id,
                'jenis_transaksi' => 'Setoran Simpanan Pokok',
                'posisi' => 'Debet',
                'jumlah' => $pokok,
                'nominal' => $pokok,
                // Saldo berjalan WAJIB terisi: portal anggota & slip transaksi
                // membacanya langsung (lihat migrasi 2026_10_28_000100).
                'saldo_setelah' => round((float) $rekeningPokok->saldo + $pokok, 2),
                'sumber_transaksi' => 'Teller',
                'status_transaksi' => 'Sukses',
                'status' => 'Sukses',
                'user_id' => $this->teller?->id,
                'dibuat_oleh' => $this->teller?->id,
                'catatan' => $keterangan,
            ]);

            if ($pokok > 0 && $transaksiPokok->wasRecentlyCreated) {
                $rekeningPokok->update(['saldo' => round((float) $rekeningPokok->saldo + $pokok, 2)]);
            }

            $transaksiWajib = Transaksi::firstOrCreate([
                'no_transaksi' => $this->noTransaksi('WAJIB1', $anggota->id),
            ], [
                // Tanggal historis deterministik (lihat `$waktuSetoranAwal`).
                'waktu_transaksi' => $waktuSetoranAwal,
                'anggota_id' => $anggota->id,
                'rekening_id' => $rekeningWajib->id,
                'jenis_transaksi' => 'Setoran Simpanan Wajib',
                'posisi' => 'Debet',
                'jumlah' => $wajib,
                'nominal' => $wajib,
                // Saldo berjalan WAJIB terisi: portal anggota & slip transaksi
                // membacanya langsung (lihat migrasi 2026_10_28_000100).
                'saldo_setelah' => round((float) $rekeningWajib->saldo + $wajib, 2),
                'sumber_transaksi' => 'Teller',
                'status_transaksi' => 'Sukses',
                'status' => 'Sukses',
                'user_id' => $this->teller?->id,
                'dibuat_oleh' => $this->teller?->id,
                'catatan' => $keterangan,
            ]);

            if ($wajib > 0 && $transaksiWajib->wasRecentlyCreated) {
                $rekeningWajib->update(['saldo' => round((float) $rekeningWajib->saldo + $wajib, 2)]);
            }

            $jurnal = app(AutoJournalingService::class)->jurnalBundlingSetoranAwal(
                $pokok,
                $wajib,
                $keterangan,
                $this->pencatat?->id,
                $transaksiPokok->id,
                $rekeningPokok->id,
                $anggota->id,
                $rekeningWajib->id
            );

            if (! $jurnal) {
                throw new \RuntimeException(
                    'Jurnal bundling setoran awal gagal diposting untuk '.$anggota->no_anggota
                    .'. Pastikan CoA 1-1001, 3-1001, dan 3-1002 tersedia serta aktif.'
                );
            }

            /* Bulan ke-1 (bulan `tanggal_gabung`) sudah dibayar lewat bundling →
             | tagihannya PAID, sehingga scheduler tidak menagih ulang periode yang
             | sama. Meniru `CifController::store()` yang memakai `periodDari(today())`
             | saat pendaftaran (join_date = tanggal daftar). */
            MandatorySavingsBill::updateOrCreate(
                [
                    'member_id' => $anggota->id,
                    'bill_period' => MandatorySavingsBill::periodDari($anggota->tanggal_gabung),
                ],
                [
                    'amount' => $wajib,
                    'status' => MandatorySavingsBill::STATUS_PAID,
                    // Tanggal bayar historis (bulan `tanggal_gabung`), bukan `now()`.
                    'paid_at' => $waktuSetoranAwal,
                    'payment_method' => MandatorySavingsBill::METODE_CASH,
                    'transaksi_id' => $transaksiWajib->id,
                    'jurnal_id' => $jurnal->id,
                ]
            );
        });

        $this->pastikanStatusAktif($anggota);
    }

    /** Normalisasi status anggota menjadi ACTIVE (PENDING → ACTIVE setelah setoran awal). */
    private function pastikanStatusAktif(Anggota $anggota): void
    {
        if ($anggota->status_anggota !== Anggota::STATUS_ACTIVE) {
            $anggota->update(['status_anggota' => Anggota::STATUS_ACTIVE]);
        }
    }

    /** Pastikan tagihan Simpanan Wajib ada (firstOrCreate via service resmi). */
    private function pastikanTagihan(
        Anggota $anggota,
        string $periode,
        string $status = MandatorySavingsBill::STATUS_UNPAID
    ): MandatorySavingsBill {
        $bill = app(MandatorySavingsService::class)->issueBill($anggota, $periode);

        if ($bill && $status !== MandatorySavingsBill::STATUS_UNPAID && $bill->status !== $status) {
            $bill->update(['status' => $status]);
        }

        return $bill ?? MandatorySavingsBill::firstOrCreate(
            ['member_id' => $anggota->id, 'bill_period' => $periode],
            [
                'amount' => KebijakanKoperasi::simpananWajibBulanan(),
                'status' => $status,
            ]
        );
    }

    /**
     * Daftar periode tagihan anggota — dihitung dari `join_date` mengikuti
     * aturan anniversary / end-of-month (`Carbon::isEndOfMonth()`).
     */
    private function periodeTagihan(Anggota $anggota): array
    {
        $mulai = $anggota->tanggalMulaiKeanggotaan() ?? Carbon::today();
        $periode = [];

        for ($i = 0; $i < self::JUMLAH_BULAN; $i++) {
            $periode[] = $mulai->copy()->addMonths($i)->format('Y-m');
        }

        return array_values(array_unique($periode));
    }

    /**
     * Danai rekening Simpanan Sukarela dari Kas Teller.
     *
     * Jurnal: DEBET 1-1001 Kas | KREDIT 2-1001 Simpanan Sukarela (setoran tunai).
     * Idempoten — hanya menambah selisih menuju target saldo.
     */
    private function pastikanSaldoSukarela(Anggota $anggota, Rekening $rekening, float $target): void
    {
        $rekening->refresh();
        $selisih = round($target - (float) $rekening->saldo, 2);

        if ($selisih <= 0) {
            return;
        }

        DB::transaction(function () use ($anggota, $rekening, $selisih) {
            $keterangan = 'Setoran Simpanan Sukarela — sumber dana auto-debit '.$anggota->nama_lengkap;

            /* ⚠️ TANGGAL HISTORIS DETERMINISTIK — JANGAN `now()`.
             | Dana Sukarela disetor di awal keanggotaan (bulan `tanggal_gabung`),
             | sebelum tagihan bulanan berikutnya diusulkan. Menjaga data demo
             | tidak pernah muncul sebagai transaksi "hari ini" di dasbor. */
            $waktuSetoran = Carbon::parse($anggota->tanggal_gabung)
                ->startOfMonth()
                ->setTime(9, 30, 0);

            $transaksi = Transaksi::firstOrCreate([
                'no_transaksi' => $this->noTransaksi('SUKARELA', $anggota->id),
            ], [
                'waktu_transaksi' => $waktuSetoran,
                'anggota_id' => $anggota->id,
                'rekening_id' => $rekening->id,
                'jenis_transaksi' => 'Setoran Simpanan Sukarela',
                'posisi' => 'Debet',
                'jumlah' => $selisih,
                'nominal' => $selisih,
                // Saldo berjalan WAJIB terisi: portal anggota & slip transaksi
                // membacanya langsung (lihat migrasi 2026_10_28_000100).
                'saldo_setelah' => round((float) $rekening->saldo + $selisih, 2),
                'sumber_transaksi' => 'Teller',
                'status_transaksi' => 'Sukses',
                'status' => 'Sukses',
                'user_id' => $this->teller?->id,
                'dibuat_oleh' => $this->teller?->id,
                'catatan' => $keterangan,
            ]);

            if (! $transaksi->wasRecentlyCreated) {
                return;
            }

            $rekening->update(['saldo' => round((float) $rekening->saldo + $selisih, 2)]);

            $jurnal = app(AutoJournalingService::class)->jurnalSetorTunai(
                $selisih,
                $keterangan,
                $this->pencatat?->id,
                $transaksi->id,
                $rekening->id,
                $anggota->id
            );

            if (! $jurnal) {
                throw new \RuntimeException(
                    'Jurnal setoran Sukarela gagal diposting untuk '.$anggota->no_anggota.'.'
                );
            }
        });
    }

    /**
     * Nomor transaksi unik & deterministik (kolom `no_transaksi` maks. 30 char):
     * TRX-SEED-<slug>-<anggotaId> — mis. `TRX-SEED-POKOK-8558`.
     */
    private function noTransaksi(string $slug, int $anggotaId): string
    {
        return sprintf('TRX-SEED-%s-%d', $slug, $anggotaId);
    }

    /* =====================================================================
     | LAPORAN AKHIR (REKONSILIASI BUKU BESAR)
     |===================================================================== */

    /** Cetak ringkasan rekonsiliasi buku besar + status tagihan. */
    private function laporkan(): void
    {
        $this->command?->newLine();
        $this->command?->info('Ringkasan buku besar (saldo CoA):');

        foreach ([self::COA_KAS, self::COA_SUKARELA, self::COA_POKOK, self::COA_WAJIB] as $kode) {
            $coa = Coa::findByKode($kode);

            if (! $coa) {
                continue;
            }

            $debet = (float) JurnalDetail::where('coa_id', $coa->id)->sum('debet');
            $kredit = (float) JurnalDetail::where('coa_id', $coa->id)->sum('kredit');
            $saldo = round($debet - $kredit, 2);

            $this->command?->line(sprintf(
                '  %s %-42s Rp %s',
                $kode,
                $coa->nama_akun ?? '',
                number_format($saldo, 0, ',', '.')
            ));
        }

        $ids = $this->anggotaIds();

        $total = MandatorySavingsBill::whereIn('member_id', $ids)->count();
        $lunas = MandatorySavingsBill::whereIn('member_id', $ids)->where('status', MandatorySavingsBill::STATUS_PAID)->count();
        $overdue = MandatorySavingsBill::whereIn('member_id', $ids)->where('status', MandatorySavingsBill::STATUS_OVERDUE)->count();

        $this->command?->line(sprintf(
            '  Tagihan 3 skenario: %d total — %d PAID, %d OVERDUE, %d UNPAID.',
            $total,
            $lunas,
            $overdue,
            max(0, $total - $lunas - $overdue)
        ));
        $this->command?->info('══════════════════════════════════════════════════════════════');
    }

    /** @return array<int, int> */
    private function anggotaIds(): array
    {
        return Anggota::whereIn('no_anggota', ['ANG-2026-0021', 'ANG-2026-0022', 'ANG-2026-0023'])
            ->pluck('id')
            ->all();
    }

    /**
     * TAMBAL MANDIRI (self-healing): rapikan tanggal transaksi demo lama.
     *
     * Versi seeder sebelumnya memakai `now()` sehingga transaksi demo
     * `TRX-SEED-*` tercatat pada tanggal `db:seed` dijalankan. Karena seeder
     * bersifat idempoten (`firstOrCreate`), baris lama TIDAK ikut diperbarui
     * dan tetap muncul sebagai "transaksi hari ini" di dasbor Teller.
     *
     * Method ini menyentuh KOLOM TANGGAL (`waktu_transaksi`) milik transaksi
     * demo 3 anggota skenario, lalu MENGHITUNG ULANG `saldo_setelah` pada
     * rekening yang terdampak. Nominal, status, rekening, jurnal, dan
     * `rekening.saldo` TIDAK diubah (tanpa dampak akuntansi) — hanya saldo
     * berjalan yang disesuaikan dengan urutan tanggal barunya.
     */
    private function rapikanTanggalDemo(): void
    {
        $anggotaIds = $this->anggotaIds();

        if ($anggotaIds === []) {
            return;
        }

        $transaksiDemo = Transaksi::whereIn('anggota_id', $anggotaIds)
            ->where('no_transaksi', 'like', 'TRX-SEED-%')
            ->get();

        if ($transaksiDemo->isEmpty()) {
            return;
        }

        $dirapikan = 0;
        $rekeningTerdampak = [];

        foreach ($transaksiDemo as $transaksi) {
            $anggota = Anggota::find($transaksi->anggota_id);

            if (! $anggota?->tanggal_gabung) {
                continue;
            }

            // Acuan sama dengan seeder: awal bulan `tanggal_gabung` pukul 09:15.
            $waktuHistoris = Carbon::parse($anggota->tanggal_gabung)
                ->startOfMonth()
                ->setTime(9, 15, 0);

            if ($transaksi->waktu_transaksi?->equalTo($waktuHistoris)) {
                continue;
            }

            // Hanya perbarui kolom tanggal — kolom lain tidak disentuh.
            Transaksi::whereKey($transaksi->id)->update(['waktu_transaksi' => $waktuHistoris]);
            $dirapikan++;

            if ($transaksi->rekening_id !== null) {
                $rekeningTerdampak[$transaksi->rekening_id] = true;
            }
        }

        if ($dirapikan > 0) {
            // Tanggal bergeser → urutan kronologis berubah → saldo berjalan
            // wajib dihitung ulang agar tidak melompat antar baris.
            $this->hitungUlangSaldoSetelah(array_keys($rekeningTerdampak));

            $this->command?->line(sprintf(
                '  [tambal] %d transaksi demo dirapikan tanggalnya (bukan `now()`); saldo berjalan %d rekening dihitung ulang.',
                $dirapikan,
                count($rekeningTerdampak)
            ));
        }
    }

    /**
     * Hitung ulang `saldo_setelah` satu rekening secara kronologis.
     *
     * Algoritma identik dengan migrasi reparasi
     * `2026_10_28_000100_rapikan_saldo_setelah_transaksi`:
     * `saldo_awal = saldo_sekarang - net_seluruh_mutasi`, lalu tiap baris
     * ditambahkan efeknya (`Debet` menambah, `Kredit` mengurangi) sehingga
     * baris terakhir berakhir tepat di `rekening.saldo`.
     *
     * @param  array<int, int|string>  $rekeningIds
     */
    private function hitungUlangSaldoSetelah(array $rekeningIds): void
    {
        foreach ($rekeningIds as $rekeningId) {
            $saldoSekarang = (float) Rekening::whereKey($rekeningId)->value('saldo');

            $transaksi = Transaksi::where('rekening_id', $rekeningId)
                ->whereNull('deleted_at')
                ->where('status_transaksi', 'Sukses')
                ->orderBy('waktu_transaksi')
                ->orderBy('id')
                ->get(['id', 'posisi', 'jumlah', 'saldo_setelah']);

            if ($transaksi->isEmpty()) {
                continue;
            }

            $net = 0.0;
            foreach ($transaksi as $trx) {
                $net += $this->efekSaldo($trx);
            }

            $saldo = round($saldoSekarang - $net, 2);

            foreach ($transaksi as $trx) {
                $saldo = round($saldo + $this->efekSaldo($trx), 2);

                $lama = $trx->saldo_setelah === null ? null : (float) $trx->saldo_setelah;

                // Baris yang sudah benar tidak disentuh.
                if ($lama === null || abs($lama - $saldo) > 0.009) {
                    Transaksi::whereKey($trx->id)->update(['saldo_setelah' => $saldo]);
                }
            }
        }
    }

    /** Efek satu transaksi terhadap saldo rekening: Debet menambah, Kredit mengurangi. */
    private function efekSaldo(object $trx): float
    {
        $jumlah = (float) $trx->jumlah;

        return $trx->posisi === 'Debet' ? $jumlah : -$jumlah;
    }
}
