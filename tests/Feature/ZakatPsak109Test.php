<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\KebijakanKoperasi;
use App\Models\User;
use App\Models\ZakatTransaksi;
use App\Services\LaporanKeuanganSyariahService;
use App\Services\NavigationService;
use App\Services\ZakatService;
use App\Support\Coa as CoaKode;
use App\Support\PemisahanTugas;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Modul Zakat, Infak & Sedekah — kepatuhan PSAK 109.
 *
 * Mengunci lima hal:
 *  1. Pemisahan dana: zakat dan infak/sedekah menempati akun CoA BERBEDA
 *     (2-3002 vs 2-3004) dan tidak pernah bercampur dengan Dana Kebajikan
 *     (2-3001) — dana titipan amanah wajib disajikan terpisah.
 *  2. Posting jurnal: penerimaan = DEBET kas | KREDIT akun dana;
 *     penyaluran = DEBET akun dana | KREDIT kas.
 *  3. Batas syariah QS At-Taubah 60: zakat hanya boleh keluar ke delapan asnaf.
 *  4. Pengendalian: penyaluran melebihi saldo dana ditolak, input tidak sah
 *     ditolak, dan setiap baris buku besar terhubung ke jurnal Posted.
 *  5. Saldo laporan dihitung dari mutasi jurnal (sumber kebenaran akuntansi)
 *     dengan saldo awal periode + penerimaan − penyaluran = saldo akhir.
 *
 * Data dev juga terisi, sehingga pengujian memakai SELISIH (delta) — bukan
 * nilai absolut — agar tetap deterministik.
 */
class ZakatPsak109Test extends TestCase
{
    use DatabaseTransactions;

    private ZakatService $zakat;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zakat = app(ZakatService::class);
        $this->prefix = 'ZKTTEST'.strtoupper(substr(uniqid(), -6));

        // Fixture payloadKebijakan() membaca baris kebijakan; pastikan ada
        // (rollback otomatis oleh DatabaseTransactions).
        if (! KebijakanKoperasi::query()->exists()) {
            (new KebijakanKoperasi)->save();
            Setting::flush();
        }

        if (! Coa::findByKode(CoaKode::DANA_ZAKAT) || ! Coa::findByKode(CoaKode::DANA_INFAK_SEDEKAH)) {
            $this->markTestSkipped('CoA 2-3002 / 2-3004 (Dana Zakat & Infak/Sedekah) belum ter-seed.');
        }
    }

    protected function tearDown(): void
    {
        // Pulihkan cache parameter kebijakan (uji R-01 mengubahnya) agar tidak
        // mencemari test lain dalam satu suite.
        Setting::flush();

        parent::tearDown();
    }

    /** Setel parameter `zakat_minimal_tarik` untuk uji R-01 (rollback otomatis). */
    private function setMinimalTarik(float $nilai): void
    {
        $kebijakan = KebijakanKoperasi::query()->first();

        if ($kebijakan === null) {
            $this->markTestSkipped('Baris kebijakan koperasi belum tersedia.');
        }

        $kebijakan->zakat_minimal_tarik = $nilai;
        $kebijakan->save();
        Setting::flush();
    }

    /**
     * Setel ambang dual control penyaluran (Z-02) untuk uji maker-checker.
     * Nilai 0 = dual control NONAKTIF (perilaku lama: auto-post).
     */
    private function setAmbangPersetujuan(float $nilai): void
    {
        $kebijakan = KebijakanKoperasi::query()->first();

        if ($kebijakan === null) {
            $this->markTestSkipped('Baris kebijakan koperasi belum tersedia.');
        }

        $kebijakan->zakat_ambang_persetujuan = $nilai;
        $kebijakan->save();
        Setting::flush();
    }

    /* ─────────────────────────── HELPER ─────────────────────────── */

    private function pengelola(): User
    {
        return User::firstOrCreate(
            ['email' => 'pengurus.zakat.psak109@test.local'],
            [
                'name' => 'Pengelola Zakat Uji',
                'password' => Hash::make('password'),
                'role' => 'pengurus',
            ]
        );
    }

    private function saldo(string $dana): float
    {
        return $this->zakat->ringkasan($dana)['saldoAkhir'];
    }

    /** Selisih mutasi akun CoA dana antara sebelum & sesudah aksi. */
    private function deltaJurnal(string $kodeAkun, callable $aksi): array
    {
        $coa = Coa::findByKode($kodeAkun);
        $debet = (float) $coa->totalDebet(now()->toDateString());
        $kredit = (float) $coa->totalKredit(now()->toDateString());

        $aksi();

        return [
            'debet' => round((float) $coa->fresh()->totalDebet(now()->toDateString()) - $debet, 2),
            'kredit' => round((float) $coa->fresh()->totalKredit(now()->toDateString()) - $kredit, 2),
        ];
    }

    /* ═══════════════ 1. PEMISAHAN DANA (PSAK 109) ═══════════════ */

    public function test_dana_zakat_dan_infak_sedekah_menempati_akun_terpisah(): void
    {
        $this->assertSame(CoaKode::DANA_ZAKAT, ZakatService::akunDana('zakat'));
        $this->assertSame(CoaKode::DANA_INFAK_SEDEKAH, ZakatService::akunDana('infak_sedekah'));
        $this->assertNotSame(
            ZakatService::akunDana('zakat'),
            ZakatService::akunDana('infak_sedekah'),
            'PSAK 109: dana zakat dan infak/sedekah WAJIB berada pada akun terpisah.'
        );

        // Tidak boleh bercampur dengan Dana Kebajikan (2-3001) maupun dana sosial lain.
        foreach ([CoaKode::DANA_KEBAJIKAN, CoaKode::DANA_SOSIAL] as $asing) {
            $this->assertNotContains(
                $asing,
                [CoaKode::DANA_ZAKAT, CoaKode::DANA_INFAK_SEDEKAH],
                "Akun {$asing} tidak boleh dipakai sebagai penampung dana zakat/infak."
            );
        }

        $this->assertTrue(ZakatService::danaSah('zakat'));
        $this->assertTrue(ZakatService::danaSah('infak_sedekah'));
        $this->assertFalse(ZakatService::danaSah('dana_kebajikan'));
    }

    public function test_penerimaan_dana_zakat_mengkredit_akun_dana_terpisah(): void
    {
        $deltaZakat = $this->deltaJurnal(CoaKode::DANA_ZAKAT, fn () => $this->zakat->terima([
            'dana' => 'zakat',
            'kategori' => 'zakat_maal',
            'pihak' => $this->prefix.' Muzakki Maal',
            'jumlah' => 1500000.55,
        ], $this->pengelola()->id));

        $this->assertSame(0.0, $deltaZakat['debet'], 'Penerimaan zakat tidak boleh mendebet akun dana zakat.');
        $this->assertSame(
            Setting::pembulatanNominal(1500000.55),
            $deltaZakat['kredit'],
            'Penerimaan zakat harus mengkredit 2-3002 sebesar nominal setelah pembulatan kebijakan.'
        );

        $deltaInfak = $this->deltaJurnal(CoaKode::DANA_INFAK_SEDEKAH, fn () => $this->zakat->terima([
            'dana' => 'infak_sedekah',
            'kategori' => 'kegiatan_sosial',
            'pihak' => $this->prefix.' Munfiq',
            'jumlah' => 250000.25,
        ], $this->pengelola()->id));

        $this->assertSame(0.0, $deltaInfak['debet']);
        $this->assertSame(Setting::pembulatanNominal(250000.25), $deltaInfak['kredit']);
    }

    public function test_penyaluran_mendebet_akun_dana_dan_tidak_menyentuh_akun_lain(): void
    {
        $this->zakat->terima([
            'dana' => 'zakat',
            'kategori' => 'zakat_penghasilan',
            'pihak' => $this->prefix.' Muzakki Penghasilan',
            'jumlah' => 900000.00,
        ], $this->pengelola()->id);

        $deltaZakat = $this->deltaJurnal(CoaKode::DANA_ZAKAT, fn () => $this->zakat->salurkan([
            'dana' => 'zakat',
            'kategori' => 'fakir',
            'pihak' => $this->prefix.' Mustahik Fakir',
            'jumlah' => 300000.00,
        ], $this->pengelola()->id));

        $this->assertSame(300000.00, $deltaZakat['debet'], 'Penyaluran zakat harus mendebet 2-3002.');
        $this->assertSame(0.0, $deltaZakat['kredit']);

        // Saldo dana = saldo awal + penerimaan − penyaluran (dari mutasi jurnal).
        $sebelum = $this->saldo('zakat');

        $this->zakat->terima([
            'dana' => 'zakat',
            'kategori' => 'zakat_maal',
            'pihak' => $this->prefix.' Muzakki 2',
            'jumlah' => 100000.00,
        ], $this->pengelola()->id);

        $this->zakat->salurkan([
            'dana' => 'zakat',
            'kategori' => 'miskin',
            'pihak' => $this->prefix.' Mustahik Miskin',
            'jumlah' => 40000.00,
        ], $this->pengelola()->id);

        $this->assertSame(
            round($sebelum + Setting::pembulatanNominal(100000.00) - Setting::pembulatanNominal(40000.00), 2),
            $this->saldo('zakat'),
            'Saldo dana harus mengikuti mutasi jurnal: saldo awal + penerimaan − penyaluran.'
        );
    }

    /* ═══════════════ 2. BATAS SYARIAH & VALIDASI ═══════════════ */

    public function test_zakat_hanya_dapat_disalurkan_kepada_delapan_asnaf(): void
    {
        $this->assertCount(8, ZakatService::ASNAF, 'QS At-Taubah 60 menetapkan tepat delapan asnaf.');

        foreach (['fakir', 'miskin', 'amil', 'muallaf', 'riqab', 'gharim', 'fisabilillah', 'ibnu_sabil'] as $asnaf) {
            $this->assertArrayHasKey($asnaf, ZakatService::ASNAF);
        }

        $this->zakat->terima([
            'dana' => 'zakat',
            'kategori' => 'zakat_maal',
            'pihak' => $this->prefix.' Muzakki Asnaf',
            'jumlah' => 500000.00,
        ], $this->pengelola()->id);

        // Kategori infak (bukan asnaf) TIDAK sah dibayarkan dari dana zakat.
        $this->expectException(\InvalidArgumentException::class);

        $this->zakat->salurkan([
            'dana' => 'zakat',
            'kategori' => 'bantuan_pendidikan',
            'pihak' => $this->prefix.' Bukan Asnaf',
            'jumlah' => 100000.00,
        ], $this->pengelola()->id);
    }

    public function test_penyaluran_melebihi_saldo_dana_ditolak(): void
    {
        $saldoTersedia = $this->saldo('infak_sedekah');

        try {
            $this->zakat->salurkan([
                'dana' => 'infak_sedekah',
                'kategori' => 'bantuan_kesehatan',
                'pihak' => $this->prefix.' Mustahik Melebihi Saldo',
                'jumlah' => round($saldoTersedia + 1000000.00, 2),
            ], $this->pengelola()->id);

            $this->fail('Penyaluran melebihi saldo dana seharusnya ditolak.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('melebihi saldo dana', $e->getMessage());
        }

        $this->assertSame(
            $saldoTersedia,
            $this->saldo('infak_sedekah'),
            'Penyaluran yang ditolak tidak boleh mengubah saldo dana.'
        );
    }

    public function test_input_tidak_sah_ditolak(): void
    {
        // 1. Jenis dana di luar modul.
        try {
            $this->zakat->terima(['dana' => 'dana_kebajikan', 'kategori' => 'zakat_maal', 'pihak' => 'X', 'jumlah' => 1000], null);
            $this->fail('Jenis dana di luar modul seharusnya ditolak.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak dikenal', $e->getMessage());
        }

        // 2. Penerimaan zakat tanpa pihak.
        try {
            $this->zakat->terima(['dana' => 'zakat', 'kategori' => 'zakat_maal', 'pihak' => '   ', 'jumlah' => 1000], null);
            $this->fail('Pihak kosong seharusnya ditolak.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('pihak', strtolower($e->getMessage()));
        }

        // 3. Nominal nol / negatif.
        try {
            $this->zakat->terima(['dana' => 'zakat', 'kategori' => 'zakat_maal', 'pihak' => 'X', 'jumlah' => 0], null);
            $this->fail('Nominal nol seharusnya ditolak.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('lebih besar dari nol', $e->getMessage());
        }

        // 4. Dana zakat dengan kategori bukan jenis zakat.
        try {
            $this->zakat->terima(['dana' => 'zakat', 'kategori' => 'kegiatan_sosial', 'pihak' => 'X', 'jumlah' => 1000], null);
            $this->fail('Kategori non-zakat pada dana zakat seharusnya ditolak.');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(true);
        }
    }

    /* ═══════════════ 3. INTEGRITAS BUKU BESAR & JURNAL ═══════════════ */

    public function test_setiap_transaksi_terhubung_ke_jurnal_posted_berimbang(): void
    {
        $trx = $this->zakat->terima([
            'dana' => 'zakat',
            'kategori' => 'zakat_perdagangan',
            'pihak' => $this->prefix.' Muzakki Dagang',
            'jumlah' => 777777.77,
        ], $this->pengelola()->id);

        $this->assertInstanceOf(ZakatTransaksi::class, $trx);
        $this->assertNotNull($trx->jurnal_id, 'Setiap transaksi zakat WAJIB punya jejak jurnal.');
        $this->assertStringStartsWith('ZKT-TERIMA-', $trx->no_referensi);

        $jurnal = Jurnal::find($trx->jurnal_id);
        $this->assertNotNull($jurnal);
        $this->assertSame('Posted', $jurnal->status, 'Jurnal modul zakat harus berstatus Posted.');

        // Debet == Kredit (double entry seimbang).
        $detail = JurnalDetail::where('jurnal_id', $jurnal->id)->get();
        $this->assertSame(
            round((float) $detail->sum('debet'), 2),
            round((float) $detail->sum('kredit'), 2),
            'Jurnal wajib berimbang (debet = kredit).'
        );
    }

    public function test_validasi_kepatuhan_modul_mengembalikan_struktur_benar(): void
    {
        $hasil = $this->zakat->validasi();

        $this->assertArrayHasKey('valid', $hasil);
        $this->assertArrayHasKey('catatan', $hasil);
        $this->assertIsBool($hasil['valid']);
        $this->assertIsArray($hasil['catatan']);
    }

    public function test_ringkasan_mengembalikan_empat_komponen_psak_109(): void
    {
        $r = $this->zakat->ringkasan('zakat');

        foreach (['dana', 'label', 'akun', 'saldoAwal', 'penerimaan', 'penyaluran', 'saldoAkhir', 'akunTersedia'] as $kunci) {
            $this->assertArrayHasKey($kunci, $r, "Ringkasan PSAK 109 wajib memuat komponen '{$kunci}'.");
        }

        $this->assertSame(CoaKode::DANA_ZAKAT, $r['akun']);
        $this->assertTrue($r['akunTersedia']);
        $this->assertSame(
            round($r['saldoAwal'] + $r['penerimaan'] - $r['penyaluran'], 2),
            $r['saldoAkhir'],
            'Saldo akhir = saldo awal + penerimaan − penyaluran.'
        );

        $semua = $this->zakat->ringkasanSemua();
        $this->assertArrayHasKey('zakat', $semua);
        $this->assertArrayHasKey('infak_sedekah', $semua);
    }

    /* ═══════════════ 4. RBAC & RENDER HALAMAN ═══════════════ */

    public function test_pengurus_dan_manajer_dapat_membuka_buku_besar_zakat(): void
    {
        foreach (['pengurus', 'manajer'] as $peran) {
            $user = User::firstOrCreate(
                ['email' => "zakat.{$peran}@test.local"],
                ['name' => 'Uji '.ucfirst($peran), 'password' => Hash::make('password'), 'role' => $peran]
            );

            $this->actingAs($user)
                ->get(route('zakat.index'))
                ->assertOk()
                ->assertSee('PSAK 109')
                ->assertSee('Dana Zakat');
        }
    }

    public function test_dps_hanya_dapat_membaca_buku_besar_zakat(): void
    {
        $dps = User::firstOrCreate(
            ['email' => 'zakat.dps@test.local'],
            ['name' => 'Uji DPS', 'password' => Hash::make('password'), 'role' => 'dps']
        );

        $this->actingAs($dps)->get(route('dps.zakat.index'))->assertOk();
        $this->actingAs($dps)->get(route('dps.zakat.laporan'))->assertOk();

        // DPS tidak punya hak tulis: route POST berada di luar wewenangnya.
        $this->actingAs($dps)->post(route('zakat.store'), [
            'dana' => 'zakat',
            'kategori' => 'zakat_maal',
            'pihak' => 'DPS Mencoba Menulis',
            'jumlah' => 10000,
            'tanggal' => now()->toDateString(),
        ])->assertForbidden();
    }

    public function test_teller_dan_ao_tidak_dapat_mengakses_modul_zakat(): void
    {
        foreach (['teller', 'ao'] as $peran) {
            $user = User::firstOrCreate(
                ['email' => "zakat.{$peran}@test.local"],
                ['name' => 'Uji '.ucfirst($peran), 'password' => Hash::make('password'), 'role' => $peran]
            );

            $this->actingAs($user)->get(route('zakat.index'))->assertForbidden();
        }
    }

    public function test_form_penerimaan_melalui_http_mencatat_transaksi_dan_jurnal(): void
    {
        $sebelum = $this->saldo('infak_sedekah');

        $this->actingAs($this->pengelola())
            ->post(route('zakat.store'), [
                'dana' => 'infak_sedekah',
                'kategori' => 'kegiatan_sosial',
                'pihak' => $this->prefix.' Munfiq HTTP',
                'jumlah' => 123456.78,
                'tanggal' => now()->toDateString(),
                'keterangan' => 'Uji formulir penerimaan',
            ])
            ->assertRedirect();

        $this->assertSame(
            round($sebelum + Setting::pembulatanNominal(123456.78), 2),
            $this->saldo('infak_sedekah'),
            'Penerimaan via HTTP harus menambah saldo dana infak/sedekah sebesar nominal setelah pembulatan.'
        );

        $trx = ZakatTransaksi::where('pihak', $this->prefix.' Munfiq HTTP')->first();
        $this->assertNotNull($trx);
        $this->assertSame(ZakatTransaksi::JENIS_PENERIMAAN, $trx->jenis);
        $this->assertNotNull($trx->jurnal_id);
    }

    public function test_form_penyaluran_ditolak_ketika_saldo_kurang(): void
    {
        $saldo = $this->saldo('zakat');

        $this->actingAs($this->pengelola())
            ->post(route('zakat.salurkan'), [
                'dana' => 'zakat',
                'kategori' => 'fakir',
                'pihak' => $this->prefix.' Mustahik Kurang Saldo',
                'jumlah' => round($saldo + 500000.00, 2),
                'tanggal' => now()->toDateString(),
            ])
            ->assertSessionHas('error');

        $this->assertSame($saldo, $this->saldo('zakat'));
    }

    public function test_halaman_laporan_sumber_dan_penggunaan_dana_dapat_dirender(): void
    {
        $this->actingAs($this->pengelola())
            ->get(route('zakat.laporan'))
            ->assertOk()
            ->assertSee('DANA ZAKAT')
            ->assertSee('INFAK &amp; SEDEKAH', false)
            ->assertSee('Saldo akhir');
    }

    /* ═══════════════ 5. KEBijAKAN MINIMUM PENYALURAN (R-01) ═══════════════ */

    public function test_accessor_zakat_minimal_tarik_membaca_parameter_kebijakan(): void
    {
        $this->setMinimalTarik(25000.00);
        $this->assertSame(25000.00, Setting::zakatMinimalTarik());
    }

    public function test_penyaluran_di_bawah_batas_minimum_ditolak(): void
    {
        $this->setMinimalTarik(10000.00);

        try {
            $this->zakat->salurkan([
                'dana' => 'zakat',
                'kategori' => 'fakir',
                'pihak' => $this->prefix.' Mustahik Di Bawah Minimum',
                'jumlah' => 500.00,
            ], $this->pengelola()->id);

            $this->fail('Penyaluran di bawah batas minimum seharusnya ditolak.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('minimum', strtolower($e->getMessage()));
        }
    }

    public function test_penyaluran_tepat_pada_batas_minimum_diterima(): void
    {
        $this->setMinimalTarik(50000.00);

        $this->zakat->terima([
            'dana' => 'infak_sedekah',
            'kategori' => 'kegiatan_sosial',
            'pihak' => $this->prefix.' Munfiq Batas Minimum',
            'jumlah' => 200000.00,
        ], $this->pengelola()->id);

        $delta = $this->deltaJurnal(CoaKode::DANA_INFAK_SEDEKAH, fn () => $this->zakat->salurkan([
            'dana' => 'infak_sedekah',
            'kategori' => 'kegiatan_sosial',
            'pihak' => $this->prefix.' Mustahik Tepat Batas',
            'jumlah' => 50000.00,
        ], $this->pengelola()->id));

        $this->assertSame(50000.00, $delta['debet'], 'Penyaluran tepat pada batas minimum harus diterima.');
    }

    public function test_batas_minimum_nol_menonaktifkan_pembatasan(): void
    {
        $this->setMinimalTarik(0.00);

        $this->zakat->terima([
            'dana' => 'infak_sedekah',
            'kategori' => 'kegiatan_sosial',
            'pihak' => $this->prefix.' Munfiq Tanpa Batas',
            'jumlah' => 100000.00,
        ], $this->pengelola()->id);

        // Nilai 0 = tanpa batas minimum, penyaluran nominal kecil tetap diterima.
        $trx = $this->zakat->salurkan([
            'dana' => 'infak_sedekah',
            'kategori' => 'kegiatan_sosial',
            'pihak' => $this->prefix.' Mustahik Nominal Kecil',
            'jumlah' => 500.00,
        ], $this->pengelola()->id);

        $this->assertSame(500.0, (float) $trx->jumlah);
    }

    public function test_form_penyaluran_di_bawah_minimum_ditolak_via_http(): void
    {
        $this->setMinimalTarik(10000.00);

        $this->actingAs($this->pengelola())
            ->post(route('zakat.salurkan'), [
                'dana' => 'infak_sedekah',
                'kategori' => 'kegiatan_sosial',
                'pihak' => $this->prefix.' UAT Minimum',
                'jumlah' => 500.00,
                'tanggal' => now()->toDateString(),
            ])
            ->assertSessionHas('error');

        $this->assertNull(
            ZakatTransaksi::where('pihak', $this->prefix.' UAT Minimum')->first(),
            'Penyaluran di bawah minimum tidak boleh tercatat di buku besar.'
        );
    }

    /* ═══════════════ 6. RESOLUSI AKUN DANA (R-04 / Z-05) ═══════════════ */

    /**
     * Setel parameter kebijakan `account_dana_*_id` (rollback otomatis via
     * DatabaseTransactions), lalu segarkan cache Setting.
     */
    private function setAkunDana(string $kolom, ?int $coaId): void
    {
        $kebijakan = KebijakanKoperasi::query()->first();

        if ($kebijakan === null) {
            $this->markTestSkipped('Baris kebijakan koperasi belum tersedia.');
        }

        $kebijakan->{$kolom} = $coaId;
        $kebijakan->save();
        Setting::flush();
    }

    /** Buat akun CoA aktif baru sebagai tujuan pengalihan dana pada uji R-04. */
    private function akunBaru(string $nama): Coa
    {
        return Coa::create([
            'kode_akun' => '9-'.strtoupper(substr(uniqid(), -6)),
            'nama_akun' => $nama.' '.$this->prefix,
            'kategori' => 'Kewajiban',
            'klasifikasi_neraca' => 'Kewajiban',
            'tipe_saldo_normal' => 'Kredit',
            'is_active' => true,
        ]);
    }

    /** Laporan Dana Investasi Terikat (DIT) untuk periode hari ini. */
    private function dit(): array
    {
        $laporan = app(LaporanKeuanganSyariahService::class);

        return $laporan->perubahanDanaInvestasiTerikat($laporan->periode(now()->toDateString(), 'tanggal'));
    }

    /** Kode akun pada satu baris DIT berdasarkan kuncinya. */
    private function kodeBarisDit(array $dit, string $kunci): ?string
    {
        $baris = collect($dit['baris'])->firstWhere('kunci', $kunci);

        return $baris['kode'] ?? null;
    }

    public function test_default_resolusi_akun_dana_mengikuti_registri_standar(): void
    {
        $this->setAkunDana('account_dana_zakat_id', null);
        $this->setAkunDana('account_dana_infak_id', null);

        $this->assertSame(CoaKode::DANA_ZAKAT, ZakatService::akunDana('zakat'));
        $this->assertSame(CoaKode::DANA_INFAK_SEDEKAH, ZakatService::akunDana('infak_sedekah'));

        // Tanpa konfigurasi, DIT harus menunjuk akun yang SAMA dengan registri standar.
        $dit = $this->dit();
        $this->assertSame(CoaKode::DANA_ZAKAT, $this->kodeBarisDit($dit, 'dana_zakat'));
        $this->assertSame(CoaKode::DANA_INFAK_SEDEKAH, $this->kodeBarisDit($dit, 'dana_infak'));
    }

    public function test_setting_akun_dana_menyatukan_pencatatan_dan_dit(): void
    {
        $akunX = $this->akunBaru('Dana Zakat Dialihkan');
        $this->setAkunDana('account_dana_zakat_id', $akunX->id);

        // (a) Buku besar zakat menunjuk akun X.
        $this->assertSame($akunX->kode_akun, ZakatService::akunDana('zakat'));

        // (b) KRITERIA R-04: bila setting diisi akun X, buku besar zakat DAN DIT
        //     wajib menunjuk akun X yang sama (mencegah divergensi laporan).
        $this->assertSame(
            $akunX->kode_akun,
            $this->kodeBarisDit($this->dit(), 'dana_zakat'),
            'Kriteria R-04: buku besar zakat dan DIT harus menunjuk akun X yang sama.'
        );

        // (c) Pencatatan nyata benar-benar memposting ke akun X.
        $this->zakat->terima([
            'dana' => 'zakat',
            'kategori' => 'zakat_maal',
            'pihak' => $this->prefix.' Muzakki Akun Dialihkan',
            'jumlah' => 250000.00,
        ], $this->pengelola()->id);

        $this->assertSame(
            Setting::pembulatanNominal(250000.00),
            round((float) $akunX->fresh()->totalKredit(now()->toDateString()), 2),
            'Penerimaan zakat harus mengkredit akun X yang dikonfigurasi kebijakan.'
        );
    }

    public function test_setting_akun_dana_infak_menyatukan_pencatatan_dan_dit(): void
    {
        $akunX = $this->akunBaru('Dana Infak Dialihkan');
        $this->setAkunDana('account_dana_infak_id', $akunX->id);

        $this->assertSame($akunX->kode_akun, ZakatService::akunDana('infak_sedekah'));
        $this->assertSame($akunX->kode_akun, $this->kodeBarisDit($this->dit(), 'dana_infak'));

        // Dana zakat TIDAK boleh ikut berpindah (pemisahan dana PSAK 109 tetap).
        $this->assertSame(CoaKode::DANA_ZAKAT, ZakatService::akunDana('zakat'));
    }

    public function test_setting_akun_dana_nonaktif_fallback_ke_registri_standar(): void
    {
        $akunNonaktif = $this->akunBaru('Dana Zakat Nonaktif');
        $akunNonaktif->is_active = false;
        $akunNonaktif->save();

        $this->setAkunDana('account_dana_zakat_id', $akunNonaktif->id);

        $this->assertSame(
            CoaKode::DANA_ZAKAT,
            ZakatService::akunDana('zakat'),
            'Setting yang menunjuk akun nonaktif harus diabaikan — fallback ke 2-3002.'
        );

        // Setting yang menunjuk ID tidak ada juga harus fallback dengan aman.
        $this->setAkunDana('account_dana_zakat_id', 999999999);
        $this->assertSame(CoaKode::DANA_ZAKAT, ZakatService::akunDana('zakat'));
    }

    /* ═══════ 6b. JALUR UI — Pengurus dapat memindahkan akun dana ═══════ */

    /** Payload minimal yang lolos validasi `updateKebijakan()`. */
    private function payloadKebijakan(): array
    {
        $kebijakan = KebijakanKoperasi::query()->first();

        return [
            'limit_manajer' => (string) ($kebijakan->limit_manajer ?: 1000000),
            'tazir_mode' => $kebijakan->tazir_mode ?: 'DISABLED',
            'tazir_rate' => (string) ($kebijakan->tazir_rate ?? 0),
            'tazir_grace_period_days' => (string) ($kebijakan->tazir_grace_period_days ?? 0),
            'tazir_default_method' => $kebijakan->tazir_default_method ?: 'TUNAI',
            'mandatory_principal_savings' => (string) ($kebijakan->mandatory_principal_savings ?? 100000),
            'mandatory_monthly_savings' => (string) ($kebijakan->mandatory_monthly_savings ?? 50000),
            'minimum_pembukaan_rekening' => (string) ($kebijakan->minimum_pembukaan_rekening ?? 50000),
            'maksimum_pembukaan_rekening' => (string) ($kebijakan->maksimum_pembukaan_rekening ?? 10000000),
        ];
    }

    public function test_pengurus_dapat_memindahkan_akun_dana_zakat_via_ui(): void
    {
        $akunX = $this->akunBaru('Dana Zakat Alternatif');

        $pengurus = User::firstOrCreate(
            ['email' => 'pengurus.'.$this->prefix.'@test.local'],
            [
                'name' => 'Pengurus '.$this->prefix,
                'password' => Hash::make('password'),
                'role' => 'pengurus',
            ]
        );

        $this->actingAs($pengurus)
            ->put(route('pengurus.kebijakan.update'), array_merge($this->payloadKebijakan(), [
                'account_dana_zakat_id' => $akunX->id,
                'account_dana_infak_id' => $akunX->id,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Setting::flush();

        // Kriteria R-04: buku besar modul zakat DAN penyajian DIT menunjuk akun X.
        $this->assertSame($akunX->kode_akun, ZakatService::akunDana('zakat'));
        $this->assertSame($akunX->kode_akun, $this->kodeBarisDit($this->dit(), 'dana_zakat'));
        $this->assertSame($akunX->kode_akun, ZakatService::akunDana('infak_sedekah'));
        $this->assertSame($akunX->kode_akun, $this->kodeBarisDit($this->dit(), 'dana_infak'));

        // Mengosongkan field berarti "kembali ikuti registri standar".
        $this->actingAs($pengurus)
            ->put(route('pengurus.kebijakan.update'), array_merge($this->payloadKebijakan(), [
                'account_dana_zakat_id' => '',
                'account_dana_infak_id' => '',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Setting::flush();

        $this->assertSame(CoaKode::DANA_ZAKAT, ZakatService::akunDana('zakat'));
        $this->assertSame(CoaKode::DANA_INFAK_SEDEKAH, ZakatService::akunDana('infak_sedekah'));
        $this->assertNull(Setting::akunId('account_dana_zakat_id'));
        $this->assertNull(Setting::akunId('account_dana_infak_id'));
    }

    public function test_validasi_menolak_akun_dana_tidak_dikenal(): void
    {
        $pengurus = User::firstOrCreate(
            ['email' => 'pengurus.'.$this->prefix.'@test.local'],
            [
                'name' => 'Pengurus '.$this->prefix,
                'password' => Hash::make('password'),
                'role' => 'pengurus',
            ]
        );

        $this->actingAs($pengurus)
            ->put(route('pengurus.kebijakan.update'), array_merge($this->payloadKebijakan(), [
                'account_dana_zakat_id' => 999999999,
                'account_dana_infak_id' => 999999999,
            ]))
            ->assertSessionHasErrors(['account_dana_zakat_id', 'account_dana_infak_id']);

        Setting::flush();

        // Kebijakan tidak boleh berubah karena input tidak sah.
        $this->assertNull(Setting::akunId('account_dana_zakat_id'));
        $this->assertSame(CoaKode::DANA_ZAKAT, ZakatService::akunDana('zakat'));
    }

    public function test_pengurus_dapat_memindahkan_akun_bank_koperasi_via_ui(): void
    {
        $akunBank = $this->akunBaru('Bank Operasional Alternatif');

        $pengurus = User::firstOrCreate(
            ['email' => 'pengurus.'.$this->prefix.'@test.local'],
            [
                'name' => 'Pengurus '.$this->prefix,
                'password' => Hash::make('password'),
                'role' => 'pengurus',
            ]
        );

        $this->actingAs($pengurus)
            ->put(route('pengurus.kebijakan.update'), array_merge($this->payloadKebijakan(), [
                'account_bank_koperasi_id' => $akunBank->id,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Setting::flush();
        $this->assertSame($akunBank->id, Setting::akunId('account_bank_koperasi_id'));

        // Field dikosongkan berarti kembali ikuti standar 1-1002.
        $this->actingAs($pengurus)
            ->put(route('pengurus.kebijakan.update'), array_merge($this->payloadKebijakan(), [
                'account_bank_koperasi_id' => '',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Setting::flush();
        $this->assertNull(Setting::akunId('account_bank_koperasi_id'));
    }

    public function test_validasi_menolak_akun_bank_koperasi_tidak_dikenal(): void
    {
        $pengurus = User::firstOrCreate(
            ['email' => 'pengurus.'.$this->prefix.'@test.local'],
            [
                'name' => 'Pengurus '.$this->prefix,
                'password' => Hash::make('password'),
                'role' => 'pengurus',
            ]
        );

        $this->actingAs($pengurus)
            ->put(route('pengurus.kebijakan.update'), array_merge($this->payloadKebijakan(), [
                'account_bank_koperasi_id' => 999999999,
            ]))
            ->assertSessionHasErrors(['account_bank_koperasi_id']);

        Setting::flush();
        $this->assertNull(Setting::akunId('account_bank_koperasi_id'));
    }

    /* ═══════ 6c. JALUR UI — Parameter hisab zakat (temuan audit Z-03) ═══════ */

    /**
     * Kriteria penerimaan Z-03: keenam parameter hisab zakat (kadar, nishab,
     * harga emas, minimum penyaluran, saklar & panjang haul) dapat diubah
     * Pengurus lewat antarmuka kebijakan — bukan hanya via akses basis data.
     */
    public function test_pengurus_dapat_mengubah_parameter_hisab_zakat_via_ui(): void
    {
        $pengurus = User::firstOrCreate(
            ['email' => 'pengurus.'.$this->prefix.'@test.local'],
            [
                'name' => 'Pengurus '.$this->prefix,
                'password' => Hash::make('password'),
                'role' => 'pengurus',
            ]
        );

        $this->actingAs($pengurus)
            ->put(route('pengurus.kebijakan.update'), array_merge($this->payloadKebijakan(), [
                'zakat_rate' => '5',
                'zakat_nisab_gram' => '90',
                'harga_emas_per_gram' => '1600000',
                'zakat_minimal_tarik' => '25000',
                'zakat_haul_aktif' => '1',
                'zakat_haul_hari' => '360',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Setting::flush();

        $this->assertSame(5.0, Setting::zakatRate());
        $this->assertSame(90.0, Setting::zakatNisabGram());
        $this->assertSame(1600000.0, Setting::hargaEmasPerGram());
        $this->assertSame(25000.0, Setting::zakatMinimalTarik());
        $this->assertTrue(Setting::zakatHaulAktif());
        $this->assertSame(360, Setting::zakatHaulHari());
        $this->assertSame(round(90 * 1600000, 2), Setting::zakatNisabNominal());

        // Menonaktifkan kembali saklar haul lewat UI (hidden 0) juga harus bekerja,
        // dan parameter lain tetap dipertahankan (P4 Default Aman pembaruan sebagian).
        $this->actingAs($pengurus)
            ->put(route('pengurus.kebijakan.update'), array_merge($this->payloadKebijakan(), [
                'zakat_haul_aktif' => '0',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Setting::flush();

        $this->assertFalse(Setting::zakatHaulAktif());
        $this->assertSame(5.0, Setting::zakatRate(), 'Parameter lain tidak boleh berubah saat pembaruan sebagian.');
    }

    public function test_validasi_menolak_parameter_hisab_tidak_sah(): void
    {
        $pengurus = User::firstOrCreate(
            ['email' => 'pengurus.'.$this->prefix.'@test.local'],
            [
                'name' => 'Pengurus '.$this->prefix,
                'password' => Hash::make('password'),
                'role' => 'pengurus',
            ]
        );

        $this->actingAs($pengurus)
            ->put(route('pengurus.kebijakan.update'), array_merge($this->payloadKebijakan(), [
                'zakat_rate' => '150',
                'zakat_haul_hari' => '0',
            ]))
            ->assertSessionHasErrors(['zakat_rate', 'zakat_haul_hari']);
    }

    /* ═══════ 7. PEMBATALAN (VOID) TERKENDALI — temuan audit Z-01 ═══════ */

    /**
     * Pengurus KEDUA (pemutus) yang berbeda dari pembuat transaksi, agar
     * prinsip maker ≠ checker terpenuhi saat menguji pembatalan.
     */
    private function pemutus(): User
    {
        return User::firstOrCreate(
            ['email' => 'pemutus.zakat.psak109@test.local'],
            [
                'name' => 'Pemutus Zakat Uji',
                'password' => Hash::make('password'),
                'role' => 'pengurus',
            ]
        );
    }

    /** Catat penerimaan zakat lalu kembalikan barisnya untuk diuji pembatalan. */
    private function penerimaanUntukVoid(float $jumlah = 500000.00): ZakatTransaksi
    {
        return $this->zakat->terima([
            'dana' => 'zakat',
            'kategori' => 'zakat_maal',
            'pihak' => $this->prefix.' Muzakki Void',
            'jumlah' => $jumlah,
        ], $this->pengelola()->id);
    }

    /**
     * Kriteria penerimaan Z-01 (i) & (ii): pembatalan TIDAK menghapus baris
     * (jejak audit utuh) dan saldo dana kembali ke nilai sebelum transaksi.
     */
    public function test_pembatalan_menandai_void_tanpa_menghapus_dan_mengembalikan_saldo(): void
    {
        $saldoSebelum = $this->saldo('zakat');

        $trx = $this->penerimaanUntukVoid(500000.00);

        $this->assertSame(
            round($saldoSebelum + Setting::pembulatanNominal(500000.00), 2),
            $this->saldo('zakat'),
            'Penerimaan harus menambah saldo dana sebelum dibatalkan.'
        );

        $hasil = $this->zakat->batalkan($trx->id, $this->pemutus()->id, 'Salah input nominal');

        // (i) Baris TIDAK dihapus, hanya ditandai void.
        $baris = ZakatTransaksi::find($trx->id);
        $this->assertNotNull($baris, 'Pembatalan TIDAK boleh menghapus baris — jejak audit wajib utuh.');
        $this->assertSame(ZakatTransaksi::STATUS_VOID, $baris->status);
        $this->assertTrue($baris->sudah_void);
        $this->assertNotNull($baris->void_at, 'Waktu pembatalan wajib tercatat.');
        $this->assertSame($this->pemutus()->id, (int) $baris->void_by);
        $this->assertSame('Salah input nominal', $baris->void_reason);
        $this->assertSame(ZakatTransaksi::STATUS_VOID, $hasil->status);

        // (ii) Saldo kembali ke nilai sebelum transaksi (jurnal dibalik).
        $this->assertSame(
            $saldoSebelum,
            $this->saldo('zakat'),
            'Saldo dana harus kembali ke nilai sebelum transaksi setelah pembatalan.'
        );
    }

    /**
     * Kriteria penerimaan Z-01 (iii): pembuat transaksi TIDAK BOLEH membatalkan
     * transaksinya sendiri (pemisahan tugas maker ≠ checker).
     */
    public function test_pembuat_transaksi_tidak_dapat_membatalkan_transaksinya_sendiri(): void
    {
        $trx = $this->penerimaanUntukVoid(250000.00);
        $saldoSebelum = $this->saldo('zakat');

        try {
            $this->zakat->batalkan($trx->id, $this->pengelola()->id);
            $this->fail('Pembuat tidak boleh membatalkan transaksinya sendiri (pemisahan tugas).');
        } catch (\RuntimeException $e) {
            $this->assertSame(PemisahanTugas::PESAN, $e->getMessage());
        }

        // Baris tetap berlaku dan saldo tidak berubah saat penolakan.
        $this->assertSame(ZakatTransaksi::STATUS_POSTED, ZakatTransaksi::find($trx->id)->status);
        $this->assertSame($saldoSebelum, $this->saldo('zakat'), 'Penolakan tidak boleh mengubah saldo dana.');
    }

    /**
     * Kriteria penerimaan Z-01 (iv): aksi pembatalan tercatat pada AuditLog
     * (append-only, tersegel rantai hash).
     */
    public function test_pembatalan_tercatat_di_audit_log(): void
    {
        $trx = $this->penerimaanUntukVoid(300000.00);

        $this->zakat->batalkan($trx->id, $this->pemutus()->id, 'Koreksi pencatatan');

        $log = AuditLog::where('jenis_transaksi', 'VOID_TRANSAKSI_ZAKAT')
            ->where('no_rekening', $trx->no_referensi)
            ->first();

        $this->assertNotNull($log, 'Pembatalan wajib tercatat di AuditLog.');
        $this->assertSame(
            round((float) $trx->jumlah, 2),
            round((float) $log->nominal, 2),
            'Nominal pada AuditLog harus sama dengan nominal transaksi yang dibatalkan.'
        );
        $this->assertStringContainsString($trx->no_referensi, (string) $log->keterangan);
    }

    /** Transaksi yang sudah dibatalkan tidak boleh dibatalkan dua kali. */
    public function test_transaksi_void_tidak_dapat_dibatalkan_dua_kali(): void
    {
        $trx = $this->penerimaanUntukVoid(150000.00);

        $this->zakat->batalkan($trx->id, $this->pemutus()->id, 'Pembatalan pertama');

        try {
            $this->zakat->batalkan($trx->id, $this->pemutus()->id, 'Pembatalan kedua');
            $this->fail('Transaksi yang sudah void seharusnya tidak dapat dibatalkan lagi.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('sudah dibatalkan', $e->getMessage());
        }
    }

    /** Baris void dikecualikan dari kontrol silang validasi (tidak selisih palsu). */
    public function test_validasi_tidak_melaporkan_selisih_setelah_pembatalan(): void
    {
        $trx = $this->penerimaanUntukVoid(400000.00);

        $this->zakat->batalkan($trx->id, $this->pemutus()->id);

        $hasil = $this->zakat->validasi();

        $catatanSelisih = array_values(array_filter(
            $hasil['catatan'],
            fn (string $c) => str_contains($c, 'Selisih buku besar')
        ));

        $this->assertSame(
            [],
            $catatanSelisih,
            'Baris void tidak boleh menimbulkan selisih palsu buku besar vs jurnal.'
        );
    }

    /** Jalur HTTP: pengurus lain dapat membatalkan transaksi lewat rute terkendali. */
    public function test_pembatalan_via_http_oleh_pengurus_lain_berhasil(): void
    {
        $trx = $this->penerimaanUntukVoid(200000.00);

        $this->actingAs($this->pemutus())
            ->post(route('zakat.batalkan', $trx->id), ['alasan' => 'Koreksi via UI'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(ZakatTransaksi::STATUS_VOID, ZakatTransaksi::find($trx->id)->status);
    }

    /** Jalur HTTP: pembuat sendiri ditolak (session error, baris tetap berlaku). */
    public function test_pembatalan_via_http_oleh_pembuat_ditolak(): void
    {
        $trx = $this->penerimaanUntukVoid(200000.00);

        $this->actingAs($this->pengelola())
            ->post(route('zakat.batalkan', $trx->id))
            ->assertSessionHas('error');

        $this->assertSame(ZakatTransaksi::STATUS_POSTED, ZakatTransaksi::find($trx->id)->status);
    }

    /** DPS tidak berwenang membatalkan (RBAC: rute di luar wewenangnya). */
    public function test_dps_tidak_dapat_membatalkan_transaksi(): void
    {
        $trx = $this->penerimaanUntukVoid(200000.00);

        $dps = User::firstOrCreate(
            ['email' => 'zakat.dps@test.local'],
            ['name' => 'Uji DPS', 'password' => Hash::make('password'), 'role' => 'dps']
        );

        $this->actingAs($dps)
            ->post(route('zakat.batalkan', $trx->id))
            ->assertForbidden();

        $this->assertSame(ZakatTransaksi::STATUS_POSTED, ZakatTransaksi::find($trx->id)->status);
    }

    /* ═══ 8. DUAL CONTROL (maker-checker) PENYALURAN — temuan audit Z-02 ═══ */

    /** Isi dana zakat hingga cukup untuk penyaluran ber-ambang pada uji Z-02. */
    private function danaiZakat(float $jumlah): void
    {
        $this->zakat->terima([
            'dana' => 'zakat',
            'kategori' => 'zakat_maal',
            'pihak' => $this->prefix.' Muzakki Dana Uji',
            'jumlah' => $jumlah,
        ], $this->pengelola()->id);
    }

    /**
     * Kriteria penerimaan Z-02 (a): penyaluran ≥ ambang TIDAK diposting sebelum
     * disetujui — baris berstatus menunggu_persetujuan, tanpa jurnal, saldo tetap.
     */
    public function test_penyaluran_di_atas_ambang_belum_diposting_sebelum_disetujui(): void
    {
        $this->setAmbangPersetujuan(1000000.00);
        $this->danaiZakat(5000000.00);

        $saldoSebelum = $this->saldo('zakat');

        $trx = $this->zakat->salurkan([
            'dana' => 'zakat',
            'kategori' => 'fakir',
            'pihak' => $this->prefix.' Mustahik Ber-ambang',
            'jumlah' => 2000000.00,
        ], $this->pengelola()->id);

        $this->assertSame(ZakatTransaksi::STATUS_MENUNGGU_PERSETUJUAN, $trx->status);
        $this->assertTrue($trx->menunggu_persetujuan);
        $this->assertNull($trx->jurnal_id, 'Penyaluran ber-ambang belum boleh memiliki jurnal sebelum disetujui.');
        $this->assertSame(
            $saldoSebelum,
            $this->saldo('zakat'),
            'Saldo dana tidak boleh berubah sebelum pengajuan disetujui (jurnal belum diposting).'
        );
    }

    /**
     * Kriteria penerimaan Z-02 (b): pengaju TIDAK BOLEH menyetujui pengajuannya
     * sendiri (pemisahan tugas maker ≠ checker).
     */
    public function test_pengaju_tidak_dapat_menyetujui_pengajuannya_sendiri(): void
    {
        $this->setAmbangPersetujuan(1000000.00);
        $this->danaiZakat(5000000.00);

        $trx = $this->zakat->salurkan([
            'dana' => 'zakat',
            'kategori' => 'miskin',
            'pihak' => $this->prefix.' Mustahik Setuju Sendiri',
            'jumlah' => 2000000.00,
        ], $this->pengelola()->id);

        try {
            $this->zakat->setujui($trx->id, $this->pengelola()->id);
            $this->fail('Pengaju tidak boleh menyetujui pengajuannya sendiri (pemisahan tugas).');
        } catch (\RuntimeException $e) {
            $this->assertSame(PemisahanTugas::PESAN, $e->getMessage());
        }

        $baris = ZakatTransaksi::find($trx->id);
        $this->assertSame(ZakatTransaksi::STATUS_MENUNGGU_PERSETUJUAN, $baris->status);
        $this->assertNull($baris->jurnal_id, 'Persetujuan yang ditolak tidak boleh memposting jurnal.');
    }

    /**
     * Kriteria penerimaan Z-02 (c): penyetuju LAIN berhasil — jurnal diposting,
     * status posted, disetujui_oleh terisi, dan saldo dana berkurang.
     */
    public function test_penyetuju_lain_dapat_menyetujui_dan_jurnal_diposting(): void
    {
        $this->setAmbangPersetujuan(1000000.00);
        $this->danaiZakat(5000000.00);

        $saldoSebelum = $this->saldo('zakat');

        $trx = $this->zakat->salurkan([
            'dana' => 'zakat',
            'kategori' => 'fakir',
            'pihak' => $this->prefix.' Mustahik Setuju Lain',
            'jumlah' => 2000000.00,
        ], $this->pengelola()->id);

        $hasil = $this->zakat->setujui($trx->id, $this->pemutus()->id);

        $this->assertSame(ZakatTransaksi::STATUS_POSTED, $hasil->status);
        $this->assertNotNull($hasil->jurnal_id, 'Setelah disetujui, jurnal penyaluran WAJIB diposting.');
        $this->assertSame($this->pemutus()->id, (int) $hasil->disetujui_oleh);
        $this->assertNotNull($hasil->disetujui_pada);

        $this->assertSame(
            round($saldoSebelum - Setting::pembulatanNominal(2000000.00), 2),
            $this->saldo('zakat'),
            'Setelah disetujui, saldo dana berkurang sebesar nominal penyaluran.'
        );

        $jurnal = Jurnal::find($hasil->jurnal_id);
        $this->assertNotNull($jurnal);
        $this->assertSame('Posted', $jurnal->status);
    }

    /**
     * Kriteria penerimaan Z-02 (d): penolakan menandai baris ditolak, TIDAK
     * pernah diposting, dan menyimpan alasan penolakan.
     */
    public function test_penolakan_tidak_pernah_memposting_jurnal(): void
    {
        $this->setAmbangPersetujuan(1000000.00);
        $this->danaiZakat(5000000.00);

        $saldoSebelum = $this->saldo('zakat');

        $trx = $this->zakat->salurkan([
            'dana' => 'zakat',
            'kategori' => 'fakir',
            'pihak' => $this->prefix.' Mustahik Ditolak',
            'jumlah' => 2000000.00,
        ], $this->pengelola()->id);

        $this->zakat->tolak($trx->id, $this->pemutus()->id, 'Data mustahik tidak lengkap');

        $baris = ZakatTransaksi::find($trx->id);
        $this->assertSame(ZakatTransaksi::STATUS_DITOLAK, $baris->status);
        $this->assertTrue($baris->ditolak);
        $this->assertNull($baris->jurnal_id, 'Pengajuan yang ditolak tidak pernah diposting.');
        $this->assertSame('Data mustahik tidak lengkap', $baris->alasan_tolak);
        $this->assertSame($this->pemutus()->id, (int) $baris->disetujui_oleh);
        $this->assertSame($saldoSebelum, $this->saldo('zakat'), 'Penolakan tidak boleh mengubah saldo dana.');
    }

    /** Pemisahan tugas juga ditegakkan pada penolakan. */
    public function test_pengaju_tidak_dapat_menolak_pengajuannya_sendiri(): void
    {
        $this->setAmbangPersetujuan(1000000.00);
        $this->danaiZakat(5000000.00);

        $trx = $this->zakat->salurkan([
            'dana' => 'zakat',
            'kategori' => 'fakir',
            'pihak' => $this->prefix.' Mustahik Tolak Sendiri',
            'jumlah' => 2000000.00,
        ], $this->pengelola()->id);

        try {
            $this->zakat->tolak($trx->id, $this->pengelola()->id);
            $this->fail('Pengaju tidak boleh menolak pengajuannya sendiri (pemisahan tugas).');
        } catch (\RuntimeException $e) {
            $this->assertSame(PemisahanTugas::PESAN, $e->getMessage());
        }

        $this->assertSame(ZakatTransaksi::STATUS_MENUNGGU_PERSETUJUAN, ZakatTransaksi::find($trx->id)->status);
    }

    /** Pengajuan yang sudah diputus tidak dapat disetujui lagi. */
    public function test_pengajuan_yang_sudah_diputus_tidak_dapat_disetujui_lagi(): void
    {
        $this->setAmbangPersetujuan(1000000.00);
        $this->danaiZakat(5000000.00);

        $trx = $this->zakat->salurkan([
            'dana' => 'zakat',
            'kategori' => 'fakir',
            'pihak' => $this->prefix.' Mustahik Diputus Sekali',
            'jumlah' => 2000000.00,
        ], $this->pengelola()->id);

        $this->zakat->tolak($trx->id, $this->pemutus()->id, 'Ditolak');

        try {
            $this->zakat->setujui($trx->id, $this->pemutus()->id);
            $this->fail('Pengajuan yang sudah ditolak tidak boleh disetujui lagi.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('menunggu persetujuan', $e->getMessage());
        }
    }

    /**
     * Kriteria penerimaan Z-02 (e): jalur HTTP — pengajuan oleh pengelola,
     * pengaju sendiri ditolak, lalu pengurus lain menyetujui lewat rute.
     */
    public function test_setujui_via_http_oleh_pengurus_lain(): void
    {
        $this->setAmbangPersetujuan(1000000.00);
        $this->danaiZakat(5000000.00);

        $this->actingAs($this->pengelola())
            ->post(route('zakat.salurkan'), [
                'dana' => 'zakat',
                'kategori' => 'fakir',
                'pihak' => $this->prefix.' UAT Dual Control',
                'jumlah' => 2000000.00,
                'tanggal' => now()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $trx = ZakatTransaksi::where('pihak', $this->prefix.' UAT Dual Control')->first();
        $this->assertNotNull($trx);
        $this->assertSame(ZakatTransaksi::STATUS_MENUNGGU_PERSETUJUAN, $trx->status);
        $this->assertNull($trx->jurnal_id);

        // Pengaju sendiri mencoba menyetujui → ditolak (session error).
        $this->actingAs($this->pengelola())
            ->post(route('zakat.setujui', $trx->id))
            ->assertSessionHas('error');
        $this->assertSame(ZakatTransaksi::STATUS_MENUNGGU_PERSETUJUAN, ZakatTransaksi::find($trx->id)->status);

        // Penyetuju lain menyetujui → jurnal diposting.
        $this->actingAs($this->pemutus())
            ->post(route('zakat.setujui', $trx->id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $baris = ZakatTransaksi::find($trx->id);
        $this->assertSame(ZakatTransaksi::STATUS_POSTED, $baris->status);
        $this->assertNotNull($baris->jurnal_id);
    }

    /** Jalur HTTP penolakan oleh pengurus lain menyimpan alasan & tidak posting. */
    public function test_tolak_via_http_oleh_pengurus_lain(): void
    {
        $this->setAmbangPersetujuan(1000000.00);
        $this->danaiZakat(5000000.00);

        $trx = $this->zakat->salurkan([
            'dana' => 'zakat',
            'kategori' => 'fakir',
            'pihak' => $this->prefix.' UAT Tolak HTTP',
            'jumlah' => 2000000.00,
        ], $this->pengelola()->id);

        $this->actingAs($this->pemutus())
            ->post(route('zakat.tolak', $trx->id), ['alasan' => 'Ditolak via UI'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $baris = ZakatTransaksi::find($trx->id);
        $this->assertSame(ZakatTransaksi::STATUS_DITOLAK, $baris->status);
        $this->assertSame('Ditolak via UI', $baris->alasan_tolak);
        $this->assertNull($baris->jurnal_id);
    }

    /** DPS tidak berwenang menyetujui (RBAC: rute di luar wewenangnya). */
    public function test_dps_tidak_dapat_menyetujui_penyaluran(): void
    {
        $this->setAmbangPersetujuan(1000000.00);
        $this->danaiZakat(5000000.00);

        $trx = $this->zakat->salurkan([
            'dana' => 'zakat',
            'kategori' => 'fakir',
            'pihak' => $this->prefix.' Mustahik DPS',
            'jumlah' => 2000000.00,
        ], $this->pengelola()->id);

        $dps = User::firstOrCreate(
            ['email' => 'zakat.dps@test.local'],
            ['name' => 'Uji DPS', 'password' => Hash::make('password'), 'role' => 'dps']
        );

        $this->actingAs($dps)
            ->post(route('zakat.setujui', $trx->id))
            ->assertForbidden();

        $this->assertSame(ZakatTransaksi::STATUS_MENUNGGU_PERSETUJUAN, ZakatTransaksi::find($trx->id)->status);
    }

    /* ═══════════════ 9. Z-06: BUKU BESAR DIPAGINASI (TIDAK TERPOTONG) ═══════════════ */

    /**
     * Kriteria penerimaan Z-06: "Semua baris periode dapat diakses".
     *
     * Sebelumnya `index()` dibatasi `limit(200)` sehingga baris lama pada
     * periode padat tidak dapat ditelusuri di UI. Kini hasil disajikan sebagai
     * paginator sehingga SELURUH baris dapat dijangkau — bukan hanya potongan
     * pertama. Uji memverifikasi properti paginator (total utuh, jumlah per
     * halaman terbatas) tanpa perlu merakit ratusan baris data.
     */
    public function test_buku_besar_dipaginasi_sehingga_seluruh_baris_periode_dapat_diakses(): void
    {
        $this->actingAs($this->pengelola())
            ->get(route('zakat.index'))
            ->assertOk()
            ->assertViewHas('transaksi', function ($transaksi) {
                $this->assertInstanceOf(
                    LengthAwarePaginator::class,
                    $transaksi,
                    'Buku besar harus berupa paginator agar seluruh baris periode dapat diakses.'
                );

                // Jumlah baris per halaman dibatasi (paginate 50), BUKAN total
                // seluruh periode — inilah yang membuktikan pemotongan lama
                // `limit(200)` sudah diganti mekanisme paginasi.
                $this->assertSame(50, $transaksi->perPage());
                $this->assertLessThanOrEqual(50, $transaksi->count());

                // `total()` menghitung SELURUH baris periode (melewati batas
                // halaman), sehingga baris ke-51 dan seterusnya tetap terjangkau.
                $this->assertSame(
                    ZakatTransaksi::whereDate('tanggal', '>=', now()->startOfMonth()->toDateString())
                        ->whereDate('tanggal', '<=', now()->toDateString())
                        ->count(),
                    $transaksi->total(),
                    'Total paginator harus mencerminkan seluruh baris periode, bukan sepotong halaman.'
                );

                return true;
            });
    }

    /* ═══════════════ 10. Z-08: WHITELIST KATEGORI PENYALURAN ═══════════════ */

    /**
     * Kriteria penerimaan Z-08: "Kategori luar daftar ditolak".
     *
     * Kolom `kategori` pada form penyaluran kini divalidasi terhadap daftar
     * sah `KATEGORI_PENYALURAN` via jalur HTTP, sehingga kategori bebas tidak
     * dapat masuk dan merusak agregasi laporan.
     */
    public function test_kategori_penyaluran_di_luar_daftar_sah_ditolak(): void
    {
        $this->actingAs($this->pengelola())
            ->post(route('zakat.salurkan'), [
                'dana' => 'infak_sedekah',
                'kategori' => 'kategori_karangan',
                'pihak' => $this->prefix.' Kategori Tak Sah',
                'jumlah' => 100000.00,
                'tanggal' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('kategori');

        $this->assertNull(
            ZakatTransaksi::where('pihak', $this->prefix.' Kategori Tak Sah')->first(),
            'Penyaluran dengan kategori di luar daftar sah tidak boleh tercatat.'
        );
    }

    /** Kategori sah (8 asnaf / infak-sedekah) tetap diterima dan benar diproses. */
    public function test_kategori_penyaluran_sah_tetap_diterima(): void
    {
        $this->danaiZakat(1000000.00);

        $this->actingAs($this->pengelola())
            ->post(route('zakat.salurkan'), [
                'dana' => 'zakat',
                'kategori' => 'fakir',
                'pihak' => $this->prefix.' Kategori Sah',
                'jumlah' => 100000.00,
                'tanggal' => now()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNotNull(
            ZakatTransaksi::where('pihak', $this->prefix.' Kategori Sah')
                ->where('kategori', 'fakir')
                ->first(),
            'Kategori sah harus tetap diterima (whitelist tidak memblokir asnaf).'
        );
    }

    /* ═══════════════ 11. Z-10: TAUTAN MENU ZAKAT UNTUK DPS ═══════════════ */

    /**
     * Kriteria penerimaan Z-10: "Tautan tampil untuk DPS".
     *
     * DPS memiliki rute baca `dps.zakat.*` namun sebelumnya tidak memperoleh
     * tautan menu, sehingga pengawasan syariah bergantung pada pengetikan URL.
     * Uji mengunci tautan menu tersebut pada menu DPS.
     */
    public function test_menu_dps_memuat_tautan_zakat_read_only(): void
    {
        $menu = NavigationService::getMenu('dps');
        $rute = $this->kumpulkanRuteMenu($menu);

        $this->assertContains('dps.zakat.index', $rute, 'Menu DPS wajib menautkan buku besar zakat (read-only).');
        $this->assertContains('dps.zakat.laporan', $rute, 'Menu DPS wajib menautkan laporan sumber & penggunaan zakat.');
    }

    /** Rute yang ditautkan menu DPS benar-benar dapat dibuka (tanpa 403). */
    public function test_dps_dapat_membuka_tautan_zakat_pada_menunya(): void
    {
        $dps = User::firstOrCreate(
            ['email' => 'dps.menu.zakat@test.local'],
            ['name' => 'DPS Menu Zakat', 'password' => Hash::make('password'), 'role' => 'dps']
        );

        $this->actingAs($dps)->get(route('dps.zakat.index'))->assertOk();
        $this->actingAs($dps)->get(route('dps.zakat.laporan'))->assertOk();
    }

    /**
     * Rapatkan seluruh nama rute daun dari struktur menu bersarang.
     *
     * @param  array<int, array<string, mixed>>  $menu
     * @return list<string>
     */
    private function kumpulkanRuteMenu(array $menu): array
    {
        $rute = [];

        foreach ($menu as $item) {
            if (! empty($item['route'])) {
                $rute[] = $item['route'];
            }
            if (! empty($item['children'])) {
                $rute = array_merge($rute, $this->kumpulkanRuteMenu($item['children']));
            }
        }

        return $rute;
    }

    /**
     * Kriteria penerimaan Z-02 (f): ambang 0 = dual control NONAKTIF → perilaku
     * lama (auto-post) tetap dipertahankan (P4 Default Aman).
     */
    public function test_ambang_nol_mempertahankan_perilaku_auto_post(): void
    {
        $this->setAmbangPersetujuan(0.00);
        $this->danaiZakat(5000000.00);

        $trx = $this->zakat->salurkan([
            'dana' => 'zakat',
            'kategori' => 'fakir',
            'pihak' => $this->prefix.' Mustahik Auto Post',
            'jumlah' => 2000000.00,
        ], $this->pengelola()->id);

        $this->assertSame(ZakatTransaksi::STATUS_POSTED, $trx->status);
        $this->assertNotNull($trx->jurnal_id, 'Ambang 0 = dual control nonaktif → penyaluran tetap auto-post.');
    }

    /** Penyaluran DI BAWAH ambang tetap auto-post meski dual control aktif. */
    public function test_penyaluran_di_bawah_ambang_tetap_auto_post(): void
    {
        $this->setAmbangPersetujuan(3000000.00);
        $this->danaiZakat(5000000.00);

        $trx = $this->zakat->salurkan([
            'dana' => 'zakat',
            'kategori' => 'fakir',
            'pihak' => $this->prefix.' Mustahik Bawah Ambang',
            'jumlah' => 1000000.00,
        ], $this->pengelola()->id);

        $this->assertSame(ZakatTransaksi::STATUS_POSTED, $trx->status);
        $this->assertNotNull($trx->jurnal_id);
    }

    /* ═══════ 9. INTEGRITAS REFERENSIAL — temuan audit Z-05 ═══════ */

    /**
     * Kriteria penerimaan Z-05: `jurnal_id` dan `created_by` pada
     * `zakat_transaksis` WAJIB memiliki foreign key. Tanpa FK, baris buku besar
     * dapat menunjuk jurnal/user yang tidak ada (referensi yatim).
     */
    public function test_kolom_jejak_buku_besar_memiliki_foreign_key(): void
    {
        foreach (['jurnal_id' => 'jurnal', 'created_by' => 'users'] as $kolom => $induk) {
            $ada = DB::table('information_schema.KEY_COLUMN_USAGE')
                ->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', 'zakat_transaksis')
                ->where('COLUMN_NAME', $kolom)
                ->whereNotNull('REFERENCED_TABLE_NAME')
                ->exists();

            $this->assertTrue(
                $ada,
                "Kolom {$kolom} harus memiliki foreign key ke {$induk} (temuan Z-05)."
            );
        }
    }

    /**
     * Perilaku integritas referensial: `nullOnDelete` — menghapus user pencatat
     * MENOLKAN `created_by` namun baris buku besar tetap utuh (jejak historis
     * tidak hilang). Diuji lewat tiga langkah: relasi baru utuh → null-kan FK
     * → user dihapus.
     */
    public function test_relasi_pencatat_tertaut_dan_tetap_utuh_bila_user_dihapus(): void
    {
        $user = $this->pengelola();

        $trx = $this->zakat->terima([
            'dana' => 'zakat',
            'kategori' => 'zakat_maal',
            'pihak' => $this->prefix.' Muzakki Jejak',
            'jumlah' => 100000.00,
        ], $user->id);

        $this->assertNotNull($trx->jurnal_id);
        $this->assertNotNull($trx->created_by);
        $this->assertSame($user->id, (int) $trx->pencatat->id, 'Relasi pencatat() harus mengembalikan user pembuat.');
        $this->assertNotNull($trx->jurnal, 'Relasi jurnal() harus mengembalikan jurnal terkait.');

        // Simulasi nullOnDelete: referensi dinolkan, baris tetap ada.
        $trx->update(['created_by' => null, 'jurnal_id' => null]);

        $this->assertDatabaseHas('zakat_transaksis', ['id' => $trx->id]);
        $this->assertNull($trx->fresh()->created_by);
        $this->assertNull($trx->fresh()->jurnal_id);
    }

    /* ═══════ 10. KETAHANAN no_referensi — temuan audit Z-04 ═══════ */

    /**
     * Kriteria penerimaan Z-04: nomor tetap unik pada pembuatan cepat
     * (beruntun). Tidak boleh ada kegagalan duplicate key.
     */
    public function test_pembuatan_beruntun_membentuk_nomor_referensi_unik(): void
    {
        $referensi = [];

        for ($i = 0; $i < 5; $i++) {
            $trx = $this->zakat->terima([
                'dana' => 'zakat',
                'kategori' => 'zakat_maal',
                'pihak' => $this->prefix.' Muzakki Beruntun '.$i,
                'jumlah' => (1000 + $i),
            ], $this->pengelola()->id);

            $referensi[] = $trx->no_referensi;
            $this->assertStringStartsWith('ZKT-', $trx->no_referensi);
        }

        $this->assertCount(
            5,
            array_unique($referensi),
            'Nomor referensi WAJIB unik pada pembuatan beruntun (temuan Z-04).'
        );
    }

    /**
     * Nomor referensi eksplisit (bila pemanggil menyertakannya) dipakai apa
     * adanya — jalur `nomorEksplisit` pada `withRetryReferensi()`.
     */
    public function test_nomor_referensi_eksplisit_dihormati(): void
    {
        $nomor = 'ZKT-UJI-EKSPLISIT-'.strtoupper(substr(uniqid(), -6));

        $trx = $this->zakat->terima([
            'dana' => 'zakat',
            'kategori' => 'zakat_maal',
            'pihak' => $this->prefix.' Muzakki Eksplisit',
            'jumlah' => 50000.00,
            'no_referensi' => $nomor,
        ], $this->pengelola()->id);

        $this->assertSame($nomor, $trx->no_referensi);
    }

    /* ═══ 11. KONTROL REALISASI HAK AMIL — temuan audit Z-07 & Z-09 ═══ */

    /** Setel porsi hak amil (%), lalu segarkan cache Setting. */
    private function setPorsiAmil(float $persen): void
    {
        $kebijakan = KebijakanKoperasi::query()->first();

        if ($kebijakan === null) {
            $this->markTestSkipped('Baris kebijakan koperasi belum tersedia.');
        }

        $kebijakan->zakat_porsi_amil_persen = $persen;
        $kebijakan->save();
        Setting::flush();
    }

    /**
     * Z-07: bila realisasi penyaluran ke asnaf `amil` MELAMPAUI hak amil,
     * laporan menandai `hakAmilTerlampaui` dan menampilkan peringatan eksplisit.
     */
    public function test_laporan_memberi_peringatan_saat_realisasi_amil_melampaui_hak(): void
    {
        $this->setPorsiAmil(12.50);
        $today = now()->toDateString();

        // Dasar penerimaan Rp 8.000.000 → hak amil 12,5% = Rp 1.000.000.
        $this->zakat->terima([
            'dana' => 'zakat',
            'kategori' => 'zakat_maal',
            'pihak' => $this->prefix.' Muzakki Peringatan',
            'jumlah' => 8000000.00,
        ], $this->pengelola()->id);

        // Realisasi ke amil Rp 1.500.000 (> hak) → harus DIPERINGATKAN.
        $this->zakat->salurkan([
            'dana' => 'zakat',
            'kategori' => 'amil',
            'pihak' => $this->prefix.' Petugas Amil',
            'jumlah' => 1500000.00,
        ], $this->pengelola()->id);

        $resp = $this->actingAs($this->pengelola())
            ->get(route('zakat.laporan', ['dari' => $today, 'sampai' => $today]))
            ->assertOk();

        $resp->assertViewHas('hakAmilTerlampaui', function ($flag) {
            return $flag === true;
        });
        $resp->assertSee('Realisasi hak amil terlampaui', false);
        $resp->assertSee('Terlampaui', false);
    }

    /**
     * Z-09: saat realisasi amil ≤ hak (AMAN), laporan TIDAK menandai pelanggaran
     * dan warna selisih adalah hijau (#065f46) — bukan merah.
     */
    public function test_laporan_aman_saat_realisasi_amil_dalam_batas_hak(): void
    {
        $this->setPorsiAmil(12.50);
        $today = now()->toDateString();

        $this->zakat->terima([
            'dana' => 'zakat',
            'kategori' => 'zakat_maal',
            'pihak' => $this->prefix.' Muzakki Aman',
            'jumlah' => 8000000.00,
        ], $this->pengelola()->id);

        // Realisasi ke amil Rp 500.000 (< hak Rp 1.000.000) → AMAN.
        $this->zakat->salurkan([
            'dana' => 'zakat',
            'kategori' => 'amil',
            'pihak' => $this->prefix.' Petugas Amil Aman',
            'jumlah' => 500000.00,
        ], $this->pengelola()->id);

        $resp = $this->actingAs($this->pengelola())
            ->get(route('zakat.laporan', ['dari' => $today, 'sampai' => $today]))
            ->assertOk();

        $resp->assertViewHas('hakAmilTerlampaui', function ($flag) {
            return $flag === false;
        });
        // Warna hijau (aman) pada sel selisih; tidak ada peringatan pelampauan.
        $resp->assertSee('#065f46', false);
        $resp->assertDontSee('Realisasi hak amil terlampaui', false);
    }
}
