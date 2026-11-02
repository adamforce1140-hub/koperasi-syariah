<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\Coa;
use App\Models\JenisSimpanan;
use App\Models\Jurnal;
use App\Models\KasHarian;
use App\Models\Pembiayaan;
use App\Models\Rekening;
use App\Models\User;
use App\Services\AngsuranTransactionService;
use App\Support\Coa as KodeCoa;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * BATCH 5 — Integritas pembayaran angsuran (modul pembiayaan/angsuran).
 *
 * Gerbang yang dikunci berkas ini:
 *
 *   G1. Jalur TUNAI  — uang masuk ke laci teller, jurnal balance, pokok
 *       berkurang tepat sebesar uang yang benar-benar diterima.
 *   G2. Jalur AUTODEBET — dana dipindahkan dari Simpanan Sukarela anggota,
 *       saldo rekening turun tepat, jurnal memakai 2-1001 sebagai sisi debet.
 *   G3. (A-04) Autodebet WAJIB menghormati `batas_minimum` rekening.
 *       `TazirService::prosesAutodebetSusulan()` sudah memakai
 *       `Rekening::isDapatDitarik()` di dalam `lockForUpdate()`;
 *       `AngsuranTransactionService` hanya membandingkan `saldo < tagihan`
 *       pada baris TAK TERKUNCI. Tanpa re-cek di dalam kunci, autodebet dapat
 *       menembus batas minimum (dan, pada eksekusi konkuren, saldo negatif).
 *   G4. (A-05) Autodebet TIDAK boleh menambah kas fisik laci teller.
 *       Transaksi autodebet ditulis dengan `jenis_transaksi = 'Pembayaran
 *       Angsuran'` yang terdaftar pada `JenisTransaksi::CASH_IN`, sehingga
 *       `KasHarian::hitungSaldoKasTeller()` menghitungnya sebagai uang masuk
 *       laci — padahal tidak ada uang tunai yang diterima. Guard penarikan
 *       tunai (`TarikTunaiAction`) memakai angka yang sama, jadi kelebihan
 *       hitung ini memperlonggar gerbang penarikan.
 *   G5. Ta'zir wajib masuk 2-3001 (Dana Kebajikan) dan TIDAK PERNAH ke akun
 *       pendapatan kelas 4 (PSAK 109).
 *
 * Catatan lingkungan: basis data uji memuat data nyata, sehingga setiap
 * fixture memakai prefix unik dan seluruh test dibungkus DatabaseTransactions.
 */
class IntegritasPembayaranAngsuranTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'ANG'.strtoupper(substr(uniqid(), -6));

        $this->pastikanCoa();
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    /** Hentikan test bila CoA wajib belum tersedia (jangan buat akun otomatis). */
    private function pastikanCoa(): void
    {
        foreach ([
            KodeCoa::KAS_TELLER,
            KodeCoa::SIMPANAN_SUKARELA,
            KodeCoa::PINJAMAN_QARDH,
            KodeCoa::DANA_KEBAJIKAN,
        ] as $kode) {
            if (Coa::findByKode($kode) === null) {
                $this->markTestSkipped("CoA {$kode} belum tersedia pada basis data uji.");
            }
        }
    }

    private function service(): AngsuranTransactionService
    {
        return app(AngsuranTransactionService::class);
    }

    private function anggota(): Anggota
    {
        return Anggota::create([
            'no_anggota' => 'AGT-'.$this->prefix,
            'nik' => '3271'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Uji Angsuran '.$this->prefix,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji Angsuran No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_ACTIVE,
            'tanggal_gabung' => '2020-01-01',
        ]);
    }

    private function rekening(Anggota $anggota, float $saldo = 500000.0, float $batasMinimum = 0.0): Rekening
    {
        return Rekening::create([
            'no_rekening' => 'RKN-'.$this->prefix.'-'.strtoupper(substr(uniqid(), -4)),
            'anggota_id' => $anggota->id,
            'akun_id' => (int) DB::table('akun')->value('id'),
            'jenis_rekening' => JenisSimpanan::NAMA_SUKARELA,
            'akad' => 'Wadiah',
            'saldo' => $saldo,
            'batas_minimum' => $batasMinimum,
            'tanggal_buka' => now()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);
    }

    /**
     * Pembiayaan Qardh (tanpa margin) dengan satu baris angsuran.
     *
     * @return array{0: Pembiayaan, 1: Angsuran}
     */
    private function buatPembiayaan(Anggota $anggota, float $pokok = 125000.0): array
    {
        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => 'PMB-'.$this->prefix.'-'.strtoupper(substr(uniqid(), -4)),
            'anggota_id' => $anggota->id,
            'akun_id' => Coa::query()->value('id') ?? 1,
            'akad_pembiayaan' => 'Qardh',
            'jumlah_pembiayaan' => $pokok,
            'total_pembiayaan' => $pokok,
            'total_ujroh' => 0,
            'sisa_pembiayaan' => $pokok,
            'tenor' => 1,
            'jumlah_angsuran' => $pokok,
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonth()->toDateString(),
            'status_pembiayaan' => 'Aktif',
        ]);

        $angsuran = Angsuran::create([
            'pembiayaan_id' => $pembiayaan->id,
            'angsuran_ke' => 1,
            'tanggal_jatuh_tempo' => now()->addMonth()->toDateString(),
            'pokok_angsuran' => $pokok,
            'margin_ujroh' => 0,
            'jumlah_angsuran' => $pokok,
            'sisa_pokok' => $pokok,
            'status_angsuran' => 'Belum Jatuh Tempo',
        ]);

        return [$pembiayaan, $angsuran];
    }

    private function teller(float $modalAwal = 1000000.0): User
    {
        $user = User::create([
            'name' => 'Teller '.$this->prefix,
            'email' => strtolower($this->prefix).'.teller@test.local',
            'password' => Hash::make('password'),
            'role' => 'teller',
            'is_active' => true,
        ]);

        $this->bukaSesiKas($user, null, $modalAwal);

        return $user;
    }

    /* =====================================================================
     | G1 — JALUR TUNAI (KONTROL POSITIF)
     |===================================================================== */

    public function test_pembayaran_tunai_penuh_menghasilkan_jurnal_balance_dan_pokok_turun_tepat(): void
    {
        $anggota = $this->anggota();
        [$pembiayaan, $angsuran] = $this->buatPembiayaan($anggota);
        $teller = $this->teller();

        $hasil = $this->service()->proses($angsuran, [
            'jumlah_bayar' => 125000,
            'metode_bayar' => 'TUNAI',
            'user_id' => $teller->id,
        ]);

        $jurnal = Jurnal::where('transaksi_id', $hasil['transaksi_id'])->first();

        $this->assertNotNull($jurnal, 'Pembayaran angsuran tunai wajib menghasilkan jurnal.');
        $this->assertSame('Posted', $jurnal->status, 'Jurnal angsuran harus berstatus Posted.');
        $this->assertSame(
            round((float) $jurnal->total_debet, 2),
            round((float) $jurnal->total_kredit, 2),
            'Jurnal angsuran wajib balance (debet = kredit).'
        );

        $pembiayaan->refresh();
        $angsuran->refresh();

        $this->assertSame(0.0, round((float) $pembiayaan->sisa_pembiayaan, 2),
            'Sisa pembiayaan harus turun tepat sebesar pokok yang diterima.');
        $this->assertSame('Sudah Dibayar', $angsuran->status_angsuran);
        $this->assertSame(Pembiayaan::STATUS_LUNAS, $pembiayaan->status_pembiayaan,
            'Pembiayaan tanpa kewajiban tersisa harus berstatus Lunas.');

        $transaksi = $pembiayaan->transaksi()->latest('id')->first();
        $this->assertSame('tunai', $transaksi->jenis_pembayaran);
        $this->assertSame('Debet', $transaksi->posisi);
    }

    /* =====================================================================
     | G2 — JALUR AUTODEBET (KONTROL POSITIF)
     |===================================================================== */

    public function test_autodebet_saldo_cukup_memotong_rekening_dan_memakai_akun_simpanan_sukarela(): void
    {
        $anggota = $this->anggota();
        $rekening = $this->rekening($anggota, 500000.0);
        [$pembiayaan, $angsuran] = $this->buatPembiayaan($anggota);
        $teller = $this->teller();

        $hasil = $this->service()->proses($angsuran, [
            'jumlah_bayar' => 125000,
            'metode_bayar' => 'AUTODEBET',
            'user_id' => $teller->id,
            // Batch 9: autodebet langsung kini ditolak kebijakan wajib konfirmasi;
            // test ini menguji jalur yang SAH, yaitu eksekusi usulan terkonfirmasi.
            'via_konfirmasi' => true,
        ]);

        $rekening->refresh();
        $this->assertSame(375000.0, round((float) $rekening->saldo, 2),
            'Saldo Simpanan Sukarela harus turun tepat sebesar tagihan.');

        $jurnal = Jurnal::where('transaksi_id', $hasil['transaksi_id'])->firstOrFail();
        $debetSimpanan = (float) $jurnal->detail()
            ->whereHas('coa', fn ($q) => $q->where('kode_akun', KodeCoa::SIMPANAN_SUKARELA))
            ->sum('debet');

        $this->assertSame(125000.0, round($debetSimpanan, 2),
            'Sisi debet autodebet wajib memakai akun Simpanan Sukarela (2-1001).');

        $pembiayaan->refresh();
        $this->assertSame(0.0, round((float) $pembiayaan->sisa_pembiayaan, 2));

        $transaksi = $pembiayaan->transaksi()->latest('id')->first();
        $this->assertSame('autodebet', $transaksi->jenis_pembayaran,
            'Transaksi autodebet harus ditandai jenis_pembayaran = autodebet agar tidak dihitung sebagai kas fisik.');
    }

    /* =====================================================================
     | G3 — A-04: BATAS MINIMUM REKENING PADA AUTODEBET
     |===================================================================== */

    /**
     * Autodebet yang menyisakan saldo DI BAWAH `batas_minimum` wajib ditolak.
     *
     * Aturan yang sama sudah ditegakkan `TazirService` lewat
     * `Rekening::isDapatDitarik()` di dalam `lockForUpdate()`; jalur angsuran
     * harus konsisten. Bila test ini gagal, autodebet menembus batas minimum
     * dan (pada konkurensi) berpotensi membuat saldo negatif.
     */
    public function test_autodebet_tidak_boleh_menembus_batas_minimum_rekening(): void
    {
        $anggota = $this->anggota();
        $rekening = $this->rekening($anggota, 600000.0, 500000.0);
        [, $angsuran] = $this->buatPembiayaan($anggota, 125000.0);
        $teller = $this->teller();

        $this->assertFalse(
            $rekening->isDapatDitarik(125000.0),
            'Prasyarat test: penarikan Rp 125.000 dari saldo Rp 600.000 (minimum Rp 500.000) tidak boleh diizinkan.'
        );

        $gagal = null;
        try {
            $this->service()->proses($angsuran, [
                'jumlah_bayar' => 125000,
                'metode_bayar' => 'AUTODEBET',
                'user_id' => $teller->id,
            ]);
        } catch (\Throwable $e) {
            $gagal = $e;
        }

        $this->assertNotNull($gagal,
            'Autodebet yang menembus batas_minimum wajib ditolak (bukan dipotong diam-diam).');

        $this->assertSame(600000.0, round((float) $rekening->fresh()->saldo, 2),
            'Saldo rekening tidak boleh berkurang saat autodebet ditolak.');

        $angsuran->refresh();
        $this->assertNull($angsuran->tanggal_bayar,
            'Angsuran tidak boleh ditandai dibayar saat autodebet ditolak.');
    }

    /* =====================================================================
     | G4 — A-05: AUTODEBET BUKAN KAS FISIK LACI
     |===================================================================== */

    /** Autodebet tidak menerima uang tunai → tidak boleh menambah cash-in laci. */
    public function test_autodebet_tidak_menambah_kas_fisik_laci_teller(): void
    {
        $anggota = $this->anggota();
        $this->rekening($anggota, 500000.0);
        [, $angsuran] = $this->buatPembiayaan($anggota);
        $teller = $this->teller(1000000.0);

        $this->service()->proses($angsuran, [
            'jumlah_bayar' => 125000,
            'metode_bayar' => 'AUTODEBET',
            'user_id' => $teller->id,
            // Batch 9: autodebet langsung kini ditolak kebijakan wajib konfirmasi;
            // test ini menguji jalur yang SAH, yaitu eksekusi usulan terkonfirmasi.
            'via_konfirmasi' => true,
        ]);

        $kas = KasHarian::hitungSaldoKasTeller($teller->id);

        $this->assertSame(0.0, round((float) $kas['cash_in'], 2),
            'Autodebet memindahkan dana dari simpanan anggota — tidak ada kas masuk ke laci teller.');
        $this->assertSame(1000000.0, round((float) $kas['saldo'], 2),
            'Saldo kas fisik laci tidak boleh bertambah karena autodebet.');

        $posisi = KasHarian::ringkasanPosisiKas($teller->id);
        $this->assertSame(0.0, round((float) $posisi['cash_in'], 2),
            'Ringkasan posisi kas harus konsisten: autodebet bukan cash-in.');
    }

    /** Kontrol positif: pembayaran TUNAI memang menambah cash-in laci. */
    public function test_pembayaran_tunai_menambah_kas_fisik_laci_teller(): void
    {
        $anggota = $this->anggota();
        [, $angsuran] = $this->buatPembiayaan($anggota);
        $teller = $this->teller(1000000.0);

        $this->service()->proses($angsuran, [
            'jumlah_bayar' => 125000,
            'metode_bayar' => 'TUNAI',
            'user_id' => $teller->id,
        ]);

        $kas = KasHarian::hitungSaldoKasTeller($teller->id);

        $this->assertSame(125000.0, round((float) $kas['cash_in'], 2),
            'Pembayaran tunai harus menambah cash-in laci sebesar uang yang diterima.');
        $this->assertSame(1125000.0, round((float) $kas['saldo'], 2));
    }

    /* =====================================================================
     | G5 — TA'ZIR KE DANA KEBAJIKAN (PSAK 109)
     |===================================================================== */

    public function test_tazir_masuk_dana_kebajikan_dan_tidak_pernah_ke_akun_pendapatan(): void
    {
        $anggota = $this->anggota();
        [, $angsuran] = $this->buatPembiayaan($anggota);
        $teller = $this->teller();

        $hasil = $this->service()->proses($angsuran, [
            'jumlah_bayar' => 125000,
            'nominal_tazir' => 5000,
            'metode_bayar' => 'TUNAI',
            'user_id' => $teller->id,
        ]);

        $jurnal = Jurnal::where('transaksi_id', $hasil['transaksi_id'])->firstOrFail();

        $kreditDanaKebajikan = (float) $jurnal->detail()
            ->whereHas('coa', fn ($q) => $q->where('kode_akun', KodeCoa::DANA_KEBAJIKAN))
            ->sum('kredit');

        $this->assertSame(5000.0, round($kreditDanaKebajikan, 2),
            "Ta'zir wajib dikreditkan ke Dana Kebajikan (2-3001) sebesar nominal yang diterima.");

        $kreditPendapatan = (float) $jurnal->detail()
            ->whereHas('coa', fn ($q) => $q->where('kode_akun', 'like', '4-%'))
            ->sum('kredit');

        $this->assertSame(0.0, round($kreditPendapatan, 2),
            "Ta'zir DILARANG masuk akun pendapatan kelas 4 (PSAK 109).");

        $this->assertSame(
            round((float) $jurnal->total_debet, 2),
            round((float) $jurnal->total_kredit, 2),
            'Jurnal ber-ta\'zir harus tetap balance.'
        );
    }
}
