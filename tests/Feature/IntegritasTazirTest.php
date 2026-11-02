<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\KebijakanKoperasi;
use App\Models\Pembiayaan;
use App\Models\TazirPenangguhan;
use App\Models\User;
use App\Services\AngsuranTransactionService;
use App\Services\TazirService;
use App\Support\Coa as KodeCoa;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BATCH 11 — Modul TA'ZIR sebagai satu kesatuan.
 *
 * Batch 9 hanya menutup kanal AUTO-DEBIT ta'zir. Berkas ini menguji aturan
 * syariah ta'zir itu sendiri:
 *
 *   T1 — Saklar fitur: bila `financing_late_fee_per_day = 0` (default) atau
 *        `tazir_mode = DISABLED`, tidak boleh ada denda yang dihitung maupun
 *        dibebankan (Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000).
 *   T2 — Grace period: keterlambatan yang masih di dalam tenggang tidak dikenai
 *        ta'zir; lewat tenggang baru dikenai.
 *   T3 — Mode FIXED_PER_KEJADIAN: nominal tidak bertambah seiring lamanya
 *        keterlambatan (bukan denda harian).
 *   T4 — Ta'zir hanya dibebankan sekali per baris angsuran: `calculate()`
 *        berulang tidak menumpuk nominal dan angsuran yang sudah dibayar tidak
 *        dikenai ta'zir susulan.
 *   T5 — Keringanan (relief) APPROVED memaksa ta'zir Rp 0 pada pembayaran,
 *        meskipun klien mengirim `nominal_tazir` > 0.
 *   T6 — (F-01) Pelunasan TUNGGAKAN TA'ZIR: kas yang diterima teller mencakup
 *        tunggakan, sehingga jurnal wajib mengkredit Dana Kebajikan (2-3001)
 *        sebesar SELURUH ta'zir yang diterima — bukan hanya ta'zir periode
 *        berjalan. Sebelum perbaikan, `postJurnal()` hanya menerima
 *        `$tazirTerbayar` sehingga sisi kredit kurang sebesar tunggakan dan
 *        PostingJurnal menolak dengan JURNAL_TIDAK_BALANCE.
 *
 * Semua fixture bersifat lokal + prefix unik; memakai DatabaseTransactions.
 */
class IntegritasTazirTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'TZ'.strtoupper(substr(uniqid(), -6));

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

    /* =====================================================================
     | FIXTURE
     ===================================================================== */

    private function anggota(): Anggota
    {
        return Anggota::create([
            'no_anggota' => 'AGT-'.$this->prefix,
            'nik' => '3272'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Uji Ta\'zir '.$this->prefix,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Pengujian Ta\'zir No. 3',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_ACTIVE,
            'tanggal_gabung' => '2020-01-01',
            'is_auto_debit' => true,
        ]);
    }

    /**
     * @return array{0: Pembiayaan, 1: Angsuran}
     */
    private function pembiayaan(Anggota $anggota, int $hariTerlambat = 10): array
    {
        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => 'PMB-'.$this->prefix.'-'.strtoupper(substr(uniqid(), -4)),
            'anggota_id' => $anggota->id,
            'akun_id' => (int) (Coa::query()->value('id') ?? 1),
            'akad_pembiayaan' => 'Murabahah',
            'jumlah_pembiayaan' => 1000000.0,
            'total_pembiayaan' => 1200000.0,
            'total_ujroh' => 200000.0,
            'sisa_pembiayaan' => 900000.0,
            'tenor' => 10,
            'jumlah_angsuran' => 120000.0,
            'tanggal_akad' => now()->subMonths(3)->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonth()->toDateString(),
            'status_pembiayaan' => Pembiayaan::STATUS_AKTIF,
        ]);

        $angsuran = Angsuran::create([
            'pembiayaan_id' => $pembiayaan->id,
            'angsuran_ke' => 1,
            'tanggal_jatuh_tempo' => now()->subDays($hariTerlambat)->toDateString(),
            'pokok_angsuran' => 100000.0,
            'margin_ujroh' => 20000.0,
            'jumlah_angsuran' => 120000.0,
            'sisa_pokok' => 900000.0,
            'status_angsuran' => 'Terlambat',
        ]);

        return [$pembiayaan, $angsuran];
    }

    /** User teller/aktor yang dipakai sebagai `teller_id` pada fixture. */
    private function userId(): int
    {
        return (int) (DB::table('users')->value('id') ?? 0)
            ?: (int) User::factory()->create()->id;
    }

    private function aturKebijakan(array $nilai): void
    {
        KebijakanKoperasi::query()->update($nilai);
        Setting::flush();
    }

    private function tazir(?Angsuran $angsuran = null): TazirService
    {
        return app(TazirService::class);
    }

    private function saldoCoa(string $kode): float
    {
        $coa = Coa::findByKode($kode);

        $debet = (float) DB::table('jurnal_detail')->where('coa_id', $coa->id)->sum('debet');
        $kredit = (float) DB::table('jurnal_detail')->where('coa_id', $coa->id)->sum('kredit');

        return round($kredit - $debet, 2);
    }

    /**
     * Nominal baris jurnal pada kode CoA tertentu untuk satu jurnal.
     * Dipakai agar assert mengenai NILAI BARIS (piutang, kas, Dana Kebajikan),
     * bukan sekadar total header yang bisa balance meski sebarannya salah.
     */
    private function barisJurnal(Jurnal $jurnal, string $kode, string $kolom): float
    {
        $coa = Coa::findByKode($kode);

        return round((float) DB::table('jurnal_detail')
            ->where('jurnal_id', $jurnal->id)
            ->where('coa_id', $coa->id)
            ->sum($kolom), 2);
    }

    /* =====================================================================
     | T1 — SAKLAR FITUR
     ===================================================================== */

    public function test_tarif_nol_berarti_fitur_nonaktif_dan_takzir_nol(): void
    {
        $angsuran = $this->pembiayaan($this->anggota())[1];
        $this->aturKebijakan([
            'financing_late_fee_per_day' => 0,
            'tazir_mode' => 'FLAT_HARIAN',
            'tazir_grace_period_days' => 0,
        ]);

        $this->assertFalse($this->tazir()->fiturAktif(), 'Tarif 0 berarti fitur ta\'zir NONAKTIF.');
        $this->assertSame(
            0.0,
            $this->tazir()->calculate($angsuran),
            'Tanpa saklar aktif, tidak boleh ada ta\'zir yang dihitung.'
        );
    }

    public function test_mode_disabled_menutup_fitur_meski_tarif_positif(): void
    {
        $angsuran = $this->pembiayaan($this->anggota())[1];
        $this->aturKebijakan([
            'financing_late_fee_per_day' => 5000,
            'tazir_mode' => 'DISABLED',
            'tazir_grace_period_days' => 0,
        ]);

        $this->assertFalse($this->tazir()->fiturAktif(), 'Mode DISABLED menutup fitur ta\'zir.');
        $this->assertSame(0.0, $this->tazir()->calculate($angsuran));
    }

    /* =====================================================================
     | T2 — GRACE PERIOD
     ===================================================================== */

    public function test_keterlambatan_di_dalam_grace_period_tidak_dikenai_takzir(): void
    {
        $angsuran = $this->pembiayaan($this->anggota(), 5)[1];
        $this->aturKebijakan([
            'financing_late_fee_per_day' => 5000,
            'tazir_mode' => 'FLAT_HARIAN',
            'tazir_grace_period_days' => 7,
        ]);

        $this->assertSame(
            0.0,
            $this->tazir()->calculate($angsuran),
            'Keterlambatan 5 hari masih di dalam tenggang 7 hari → ta\'zir 0.'
        );
    }

    public function test_keterlambatan_melampaui_grace_period_dihitung_di_luar_tenggang(): void
    {
        $angsuran = $this->pembiayaan($this->anggota(), 10)[1];
        $this->aturKebijakan([
            'financing_late_fee_per_day' => 5000,
            'tazir_mode' => 'FLAT_HARIAN',
            'tazir_grace_period_days' => 7,
        ]);

        $this->assertGreaterThan(
            0.0,
            $this->tazir()->calculate($angsuran),
            'Keterlambatan yang melampaui tenggang wajib dikenai ta\'zir > 0.'
        );
        $this->assertSame(
            $this->tazir()->calculate($angsuran),
            $this->tazir()->calculate($angsuran),
            'Nominal ta\'zir harus deterministik untuk baris angsuran yang sama.'
        );
    }

    /* =====================================================================
     | T3 — MODE FIXED PER KEJADIAN
     | Hanya memakai hari yang jelas berada di luar / di dalam tenggang sesuai
     | implementasi (yang membaca `tazir_rate`, bukan `financing_late_fee_per_day`)
     | sehingga tidak bergantung pada pembulatan selisih hari.
     ===================================================================== */

    public function test_mode_fixed_per_kejadian_tidak_bertambah_meski_makin_terlambat(): void
    {
        [$pembiayaan, $angsuran] = $this->pembiayaan($this->anggota(), 10);
        $this->aturKebijakan([
            'financing_late_fee_per_day' => 5000,
            'tazir_mode' => 'FIXED_PER_KEJADIAN',
            'tazir_rate' => 2000,
            'tazir_grace_period_days' => 0,
        ]);

        $angsuran = Angsuran::create([
            'pembiayaan_id' => $pembiayaan->id,
            'angsuran_ke' => 2,
            'tanggal_jatuh_tempo' => now()->subDays(30)->toDateString(),
            'pokok_angsuran' => 100000.0,
            'margin_ujroh' => 20000.0,
            'jumlah_angsuran' => 120000.0,
            'sisa_pokok' => 800000.0,
            'status_angsuran' => 'Terlambat',
        ]);

        $this->assertSame(
            2000.0,
            $this->tazir()->calculate($angsuran),
            'Mode FIXED_PER_KEJADIAN membebankan nominal tetap, bukan denda harian.'
        );
    }

    /* =====================================================================
     | T4 — TIDAK ADA DENDA BERGANDA
     ===================================================================== */

    public function test_kalkulasi_berulang_tidak_menumpuk_dan_angsuran_lunas_bebas_takzir(): void
    {
        $angsuran = $this->pembiayaan($this->anggota(), 10)[1];
        $this->aturKebijakan([
            'financing_late_fee_per_day' => 5000,
            'tazir_mode' => 'FLAT_HARIAN',
            'tazir_grace_period_days' => 0,
        ]);

        $pertama = $this->tazir()->calculate($angsuran);
        $kedua = $this->tazir()->calculate($angsuran);
        $ketiga = $this->tazir()->calculate($angsuran);

        $this->assertGreaterThan(0.0, $pertama, 'Angsuran terlambat wajib dikenai ta\'zir.');
        $this->assertSame($pertama, $kedua, 'Kalkulasi ulang tidak boleh menumpuk nominal.');
        $this->assertSame($pertama, $ketiga);

        $angsuran->update(['tanggal_bayar' => now(), 'status_angsuran' => 'Sudah Dibayar']);

        $this->assertSame(
            0.0,
            $this->tazir()->calculate($angsuran->fresh()),
            'Angsuran yang sudah dibayar tidak boleh dikenai ta\'zir susulan.'
        );
    }

    /* =====================================================================
     | T5 — KERINGANAN (RELIEF) APPROVED MEMAKSA TAKZIR NOL
     ===================================================================== */

    public function test_keringanan_approved_memaksa_takzir_nol_meski_klien_mengirim_nominal(): void
    {
        [$pembiayaan, $angsuran] = $this->pembiayaan($this->anggota(), 10);
        $this->aturKebijakan([
            'financing_late_fee_per_day' => 5000,
            'tazir_mode' => 'FLAT_HARIAN',
            'tazir_grace_period_days' => 0,
        ]);

        DB::table('tazir_relief_requests')->insert([
            'jadwal_angsuran_id' => $angsuran->id,
            'pembiayaan_id' => $pembiayaan->id,
            'anggota_id' => $pembiayaan->anggota_id,
            'nominal_tazir_awal' => 50000.0,
            'nominal_tazir_disetujui' => 0,
            'alasan_teller' => 'Uji keringanan',
            'teller_id' => $this->userId(),
            'status' => 'APPROVED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $danaSebelum = $this->saldoCoa(KodeCoa::DANA_KEBAJIKAN);

        $hasil = app(AngsuranTransactionService::class)->proses($angsuran, [
            'jumlah_bayar' => 120000.0,
            // Klien mencoba memaksa ta'zir; keringanan APPROVED wajib menolkannya.
            'nominal_tazir' => 50000.0,
            'metode_bayar' => 'TUNAI',
        ]);

        $this->assertSame(
            0.0,
            (float) $hasil['nominal_tazir'],
            'Keringanan APPROVED memaksa ta\'zir Rp 0 meski nominal dikirim klien.'
        );
        $this->assertSame(
            0.0,
            round($this->saldoCoa(KodeCoa::DANA_KEBAJIKAN) - $danaSebelum, 2),
            'Tidak boleh ada ta\'zir yang dibukukan ke Dana Kebajikan (2-3001).'
        );
    }

    /* =====================================================================
     | T6 — F-01: JURNAL PELUNASAN TUNGGAKAN TAKZIR
     ===================================================================== */

    public function test_pelunasan_tunggakan_takzir_tercatat_penuh_ke_dana_kebajikan(): void
    {
        [$pembiayaan, $angsuran] = $this->pembiayaan($this->anggota(), 10);
        $this->aturKebijakan([
            'financing_late_fee_per_day' => 5000,
            'tazir_mode' => 'FLAT_HARIAN',
            'tazir_grace_period_days' => 0,
        ]);

        $tunggakan = TazirPenangguhan::create([
            'jadwal_angsuran_id' => $angsuran->id,
            'pembiayaan_id' => $pembiayaan->id,
            'anggota_id' => $pembiayaan->anggota_id,
            'nominal_tazir' => 40000.0,
            'is_tazir_paid' => false,
            'flag' => null,
        ]);

        $danaSebelum = $this->saldoCoa(KodeCoa::DANA_KEBAJIKAN);

        $hasil = null;
        $pesan = null;
        $danaSesudah = null;

        try {
            $hasil = app(AngsuranTransactionService::class)->proses($angsuran, [
                'jumlah_bayar' => 120000.0,
                'nominal_tazir' => 0,
                'tunggakan_tazir' => 40000.0,
                'metode_bayar' => 'TUNAI',
            ]);
            $danaSesudah = $this->saldoCoa(KodeCoa::DANA_KEBAJIKAN);
        } catch (\Throwable $e) {
            $pesan = $e->getMessage();
            $danaSesudah = $this->saldoCoa(KodeCoa::DANA_KEBAJIKAN);
        }

        $this->assertNull(
            $pesan,
            'F-01: pelunasan tunggakan ta\'zir tidak boleh gagal saat posting jurnal. '
            .'Pesan: '.$pesan
        );

        $this->assertNotNull($hasil, 'Pelunasan tunggakan ta\'zir harus berhasil.');

        /* Pembanding tautologis: danaSesudah - danaSebelum dengan saldo global
         | (lintas test) tidak dapat diandalkan bila ada sisa kredit pada fixture
         | sebelumnya. Nilai baris jurnal di bawah ini yang menjadi bukti utama. */
        $this->assertNotNull($danaSesudah, 'Pembacaan saldo Dana Kebajikan wajib berhasil.');

        $jurnal = Jurnal::where('transaksi_id', $hasil['transaksi_id'])->first();

        $this->assertNotNull($jurnal, 'Pelunasan tunggakan ta\'zir wajib memiliki jurnal.');

        /* Dana Kebajikan hanya boleh menerima ta'zir: Rp 40.000 — tidak lebih. */
        $this->assertEqualsWithDelta(
            40000.0,
            $this->barisJurnal($jurnal, KodeCoa::DANA_KEBAJIKAN, 'kredit'),
            0.01,
            'F-01: baris kredit Dana Kebajikan wajib sebesar SELURUH ta\'zir diterima, '
            .'yaitu tunggakan Rp 40.000.'
        );

        /* Piutang akad Murabahah = pokok + margin (margin paralel di akun
         | kontra 1-2101). Yang tertagih baris ini: pokok 100.000 + margin
         | 20.000 = 120.000 — tidak boleh melebihi porsi itu (F-04). */
        $this->assertEqualsWithDelta(
            120000.0,
            $this->barisJurnal($jurnal, KodeCoa::PIUTANG_MURABAHAH, 'kredit'),
            0.01,
            'F-04: piutang Murabahah wajib dikredit tepat sebesar porsi angsuran tertagih '
            .'(pokok 100.000 + margin 20.000), tidak lebih.'
        );

        /* Kas yang benar-benar diterima = angsuran + tunggakan ta'zir. */
        $this->assertEqualsWithDelta(
            160000.0,
            $this->barisJurnal($jurnal, KodeCoa::KAS_TELLER, 'debet'),
            0.01,
            'Kas teller wajib didebet tepat sebesar uang diterima '
            .'(angsuran Rp 120.000 + tunggakan ta\'zir Rp 40.000).'
        );

        $this->assertEqualsWithDelta(
            (float) $jurnal->total_debet,
            (float) $jurnal->total_kredit,
            0.01,
            'Jurnal wajib balance.'
        );

        $this->assertTrue(
            (bool) $tunggakan->fresh()->is_tazir_paid,
            'Tunggakan yang dilunasi wajib ditandai sudah dibayar.'
        );
    }

    /**
     * F-03 — PIUTANG TIDAK BOLEH DIKREDIT MELEBIHI POKOK YANG DIBAYAR.
     *
     * Regresi independen (tanpa ta'zir) agar bug ini tidak tertutup oleh bug
     * F-01. Fixture Murabahah: pokok Rp 100.000 + margin Rp 20.000, tetapi
     * anggota hanya menyerahkan Rp 100.000 (kurang Rp 20.000).
     *
     * Akibat bug: `jumlahBayar` (120.000) selalu lebih kecil dari
     * `pokok + margin` (120.000) bila tunggakan ikut ditagih → kekurangan
     * > 0; namun margin tetap diakui sehingga piutang dikredit melebihi
     * pokok yang diterima dan margin diakui atas hak yang belum tertagih.
     */
    public function test_margin_tak_tertagih_tidak_diakui_sebagai_pendapatan(): void
    {
        [$pembiayaan, $angsuran] = $this->pembiayaan($this->anggota(), 10);

        $sebelum = (float) $pembiayaan->sisa_pembiayaan;

        $hasil = app(AngsuranTransactionService::class)->proses($angsuran, [
            'jumlah_bayar' => 100000.0,
            'nominal_tazir' => 0,
            'metode_bayar' => 'TUNAI',
        ]);

        $jurnal = Jurnal::where('transaksi_id', $hasil['transaksi_id'])->first();

        $this->assertEqualsWithDelta(
            100000.0,
            $this->barisJurnal($jurnal, KodeCoa::KAS_TELLER, 'debet'),
            0.01,
            'Kas wajib didebet tepat sebesar uang yang diserahkan anggota (Rp 100.000).'
        );

        // Margin diakui HANYA bila uang benar-benar menutupnya.
        $debetMargin = (float) DB::table('jurnal_detail')
            ->where('jurnal_id', $jurnal->id)
            ->where('coa_id', Coa::findByKode(KodeCoa::MARGIN_DITANGGUHKAN)->id)
            ->sum('debet');

        $this->assertLessThanOrEqual(
            0.01,
            $debetMargin,
            'F-03: tidak boleh ada margin yang diakui (amortisasi 1-2101) bila uang '
            .'yang diterima habis untuk pokok — margin Rp 20.000 belum tertagih.'
        );

        $pembiayaan->refresh();
        $this->assertEqualsWithDelta(
            $sebelum - 100000.0,
            (float) $pembiayaan->sisa_pembiayaan,
            0.01,
            'Sisa kewajiban wajib turun tepat sebesar pokok yang benar-benar diterima.'
        );
    }

    /**
     * T7 — Saklar fitur juga menutup PENAGIHAN TUNGGAKAN ta'zir lama.
     * Tanpa ini, tunggakan yang sudah tersimpan tetap terpungut dari anggota
     * walau pengurus sudah mematikan fitur denda.
     */
    public function test_saklar_nonaktif_menutup_penagihan_tunggakan_takzir(): void
    {
        [$pembiayaan, $angsuran] = $this->pembiayaan($this->anggota(), 10);

        // Tunggakan dibuat saat fitur masih aktif, lalu pengurus mematikannya.
        $tunggakan = TazirPenangguhan::create([
            'jadwal_angsuran_id' => $angsuran->id,
            'pembiayaan_id' => $pembiayaan->id,
            'anggota_id' => $pembiayaan->anggota_id,
            'nominal_tazir' => 40000.0,
            'is_tazir_paid' => false,
            'flag' => null,
        ]);

        $this->aturKebijakan([
            'financing_late_fee_per_day' => 0,
            'tazir_mode' => 'FLAT_HARIAN',
            'tazir_grace_period_days' => 0,
        ]);

        $danaSebelum = $this->saldoCoa(KodeCoa::DANA_KEBAJIKAN);

        $hasil = app(AngsuranTransactionService::class)->proses($angsuran, [
            'jumlah_bayar' => 120000.0,
            'nominal_tazir' => 0,
            'tunggakan_tazir' => 40000.0,
            'metode_bayar' => 'TUNAI',
        ]);

        $this->assertSame(
            0.0,
            round($this->saldoCoa(KodeCoa::DANA_KEBAJIKAN) - $danaSebelum, 2),
            'Saat saklar ta\'zir nonaktif, tunggakan lama tidak boleh ikut terpungut.'
        );
        $this->assertEqualsWithDelta(
            120000.0,
            (float) $hasil['total_dibayar'],
            0.01,
            'Kas diterima hanya sebesar angsuran, tanpa tunggakan ta\'zir.'
        );
    }

    /**
     * Kontrol pembanding: pembayaran angsuran tanpa tunggakan tetap membukukan
     * ta'zir periode berjalan secara utuh.
     */
    public function test_takzir_periode_berjalan_tetap_tercatat_ke_dana_kebajikan(): void
    {
        $angsuran = $this->pembiayaan($this->anggota(), 10)[1];
        $this->aturKebijakan([
            'financing_late_fee_per_day' => 5000,
            'tazir_mode' => 'FLAT_HARIAN',
            'tazir_grace_period_days' => 0,
        ]);

        $danaSebelum = $this->saldoCoa(KodeCoa::DANA_KEBAJIKAN);

        app(AngsuranTransactionService::class)->proses($angsuran, [
            'jumlah_bayar' => 120000.0,
            'nominal_tazir' => 50000.0,
            'metode_bayar' => 'TUNAI',
        ]);

        $this->assertSame(
            50000.0,
            round($this->saldoCoa(KodeCoa::DANA_KEBAJIKAN) - $danaSebelum, 2),
            'Ta\'zir periode berjalan wajib masuk Dana Kebajikan (2-3001).'
        );
    }
}
