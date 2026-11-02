<?php

namespace Tests\Feature;

use App\Models\Coa;
use App\Models\DanaKebajikan;
use App\Models\DpsOpiniCatatan;
use App\Models\MasterProdukPembiayaan;
use App\Models\Pembiayaan;
use App\Models\ShuDistribution;
use App\Models\ShuPeriod;
use App\Models\User;
use App\Support\HierarkiRole;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * BATCH 20 — Rute Pengurus yang belum pernah disentuh test.
 *
 * Pemindaian `route:list` terhadap seluruh berkas test Feature menemukan 8
 * rute Pengurus dengan NOL rujukan test:
 *
 *   R1  `pengurus.shu.export-excel`      — unduhan CSV distribusi SHU
 *   R2  `pengurus.laporan-syariah.index` — arsip opini & catatan DPS
 *   R3  `pengurus.laporan.dana-kebajikan`— laporan sumber & penyaluran dana
 *                                          kebajikan (PSAK 109)
 *   R4  `pengurus.pengesahan-shu.index`  — rekap alokasi SHU per tahun buku
 *   R5  `pengurus.produk.update`         — ubah margin/plafon/tenor produk
 *   R6  `pengurus.coa.store`             — tambah akun bagan akun
 *   R7  `pengurus.aml.export`            — unduhan CSV laporan PPATK
 *   R8  `pengurus.pembiayaan.show`       — rincian pengajuan pembiayaan
 *
 * Yang diuji bukan "halaman terbuka", melainkan integritas angka dan wewenang:
 * laporan yang salah nominal dibaca Pengurus sebagai fakta, dan ekspor tanpa
 * batas peran membocorkan seluruh data anggota dalam satu berkas.
 */
class IntegritasRutePengurusTerbukaTest extends TestCase
{
    use DatabaseTransactions;

    private User $pengurus;

    /**
     * R5 (`pengurus.produk.update`) & R6 (`pengurus.coa.store`) dijaga
     * `izin:master.data` dan `izin:coa.kelola` — keduanya EKSKLUSIF Super Admin
     * (routes/web.php L338–345, L446). Pengurus memang TIDAK berwenang; itu
     * perilaku benar yang dikunci oleh dua test 403 di bawah, bukan cacat.
     */
    private User $superAdmin;

    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['shu_periods', 'shu_distributions', 'dana_kebajikans', 'dps_opini_catatan',
            'master_produk_pembiayaan', 'anggota', 'coas'] as $tabel) {
            if (! Schema::hasTable($tabel)) {
                $this->markTestSkipped("Tabel {$tabel} belum tersedia.");
            }
        }

        $this->suffix = strtoupper(substr(uniqid(), -6));

        $this->pengurus = User::firstOrCreate(
            ['email' => 'batch20.rute.pengurus@test.local'],
            [
                'name' => 'Pengurus Batch 20',
                'password' => Hash::make('rahasiabatch20'),
                'role' => 'pengurus',
            ]
        );

        $this->superAdmin = User::firstOrCreate(
            ['email' => 'batch20.rute.superadmin@test.local'],
            [
                'name' => 'Super Admin Batch 20',
                'password' => Hash::make('rahasiabatch20'),
                'role' => 'super_admin',
            ]
        );

        Setting::flush();
    }

    protected function tearDown(): void
    {
        Setting::flush();

        parent::tearDown();
    }

    /* =====================================================================
     | FIXTURE
     ===================================================================== */

    /** Periode SHU beserta dua baris distribusi milik anggota berbeda. */
    private function siapkanPeriodeShu(int $jumlahBaris = 2): ShuPeriod
    {
        $periode = new ShuPeriod;
        $periode->forceFill([
            'tahun_buku' => 'R20-'.$this->suffix.'-'.now()->year,
            'total_shu_bersih' => 1000000,
            'status' => 'Selesai',
            'is_distributed' => true,
            'diproses_at' => now(),
            'user_id' => $this->pengurus->id,
        ])->save();

        $anggotaIds = DB::table('anggota')->limit($jumlahBaris)->pluck('id')->all();

        if (count($anggotaIds) < $jumlahBaris) {
            $this->markTestSkipped('Data anggota tidak cukup untuk fixture distribusi SHU.');
        }

        foreach ($anggotaIds as $i => $anggotaId) {
            ShuDistribution::create([
                'shu_period_id' => $periode->id,
                'anggota_id' => (int) $anggotaId,
                'total_simpanan_modal' => 100000 * ($i + 1),
                'monthly_average_balance' => 0,
                'tenure_months' => 12,
                'total_transaksi_bagi_hasil' => 0,
                'shu_jasa_modal' => 30000 * ($i + 1),
                'shu_jasa_transaksi' => 10000,
                'total_shu' => 40000 * ($i + 1),
                'is_hold' => false,
            ]);
        }

        return $periode;
    }

    private function produkUji(): MasterProdukPembiayaan
    {
        return MasterProdukPembiayaan::create([
            'kode_produk' => 'R20-'.$this->suffix,
            'nama_produk' => 'Produk Uji Batch 20 '.$this->suffix,
            'akad_syariah' => Pembiayaan::AKAD_MURABAHAH ?? 'Murabahah',
            'min_plafon' => 1000000,
            'max_plafon' => 50000000,
            'margin_persen' => 10,
            'biaya_admin' => 0,
            'min_tenor_bulan' => 3,
            'max_tenor_bulan' => 24,
            'status_aktif' => true,
        ]);
    }

    /* =====================================================================
     | R1 — EKSPOR CSV DISTRIBUSI SHU
     ===================================================================== */

    public function test_ekspor_shu_menghasilkan_csv_berisi_seluruh_baris_distribusi(): void
    {
        $jumlah = 2;
        $periode = $this->siapkanPeriodeShu($jumlah);

        $respon = $this->actingAs($this->pengurus)
            ->get(route('pengurus.shu.export-excel', $periode->id));

        $respon->assertOk();
        $respon->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $isi = $respon->streamedContent();

        $this->assertStringContainsString('LAPORAN DISTRIBUSI SHU TAHUN BUKU '.$periode->tahun_buku, $isi,
            'Judul laporan wajib memuat tahun buku periode yang diekspor.');

        $barisData = ShuDistribution::where('shu_period_id', $periode->id)->count();
        $this->assertSame($jumlah, $barisData, 'Prasyarat: jumlah baris distribusi sesuai fixture.');

        // 4 baris kop (judul, total, tanggal, kosong) + 1 judul kolom + N data.
        $semuaBaris = preg_split("/\r\n|\n|\r/", trim($isi));
        $this->assertCount(4 + 1 + $barisData, $semuaBaris,
            'Jumlah baris CSV harus tepat = kop + judul kolom + jumlah distribusi.');
    }

    public function test_ekspor_shu_periode_tidak_ada_mengembalikan_galat_tanpa_unduhan(): void
    {
        $this->actingAs($this->pengurus)
            ->get(route('pengurus.shu.export-excel', 999999999))
            ->assertRedirect(route('pengurus.shu.index'))
            ->assertSessionHas('error');
    }

    /* =====================================================================
     | R2 — LAPORAN OPINI & CATATAN SYARIAH DPS
     ===================================================================== */

    public function test_laporan_syariah_menampilkan_opini_dps_terbaru(): void
    {
        $judul = 'Opini Syariah '.$this->suffix;

        DpsOpiniCatatan::create([
            'user_id' => $this->pengurus->id,
            'judul' => $judul,
            'kategori' => 'audit_berkala',
            'periode' => now()->format('Y-m'),
            'status_kepatuhan' => 'patuh',
            'isi' => 'Seluruh akad telah sesuai fatwa DSN-MUI.',
        ]);

        $this->actingAs($this->pengurus)
            ->get(route('pengurus.laporan-syariah.index'))
            ->assertOk()
            ->assertSee($judul)
            ->assertSee('Patuh Syariah');
    }

    public function test_laporan_syariah_kosong_tetap_terbuka(): void
    {
        DB::table('dps_opini_catatan')->delete();

        $this->actingAs($this->pengurus)
            ->get(route('pengurus.laporan-syariah.index'))
            ->assertOk()
            ->assertSee('Belum ada opini atau catatan syariah dari DPS.');
    }

    /* =====================================================================
     | R3 — LAPORAN SUMBER & PENYALURAN DANA KEBAJIKAN
     ===================================================================== */

    public function test_laporan_dana_kebajikan_menghitung_saldo_akhir_dari_periode(): void
    {
        $dari = now()->subDays(10)->toDateString();
        $sampai = now()->toDateString();

        // Saldo awal: penerimaan sebelum periode.
        $awal = DanaKebajikan::create([
            'no_referensi' => 'DK-AWAL-'.$this->suffix,
            'sumber_dana' => 'Infaq Anggota',
            'jenis' => 'Penerimaan',
            'jumlah' => 500000,
            'keterangan' => 'Saldo awal uji',
        ]);
        DB::table('dana_kebajikans')->where('id', $awal->id)->update(['created_at' => now()->subDays(30)]);

        DanaKebajikan::create([
            'no_referensi' => 'DK-INFAQ-'.$this->suffix,
            'sumber_dana' => 'Infaq Anggota',
            'jenis' => 'Penerimaan',
            'jumlah' => 200000,
            'keterangan' => 'Infaq periode uji',
        ]);

        DanaKebajikan::create([
            'no_referensi' => 'DK-SALUR-'.$this->suffix,
            'sumber_dana' => 'Dana Sosial',
            'jenis' => 'Penyaluran',
            'jumlah' => 150000,
            'keterangan' => 'Penyaluran periode uji',
        ]);

        $respon = $this->actingAs($this->pengurus)->get(route('pengurus.laporan.dana-kebajikan', [
            'dari' => $dari,
            'sampai' => $sampai,
        ]));

        $respon->assertOk();

        // Saldo awal 500.000 + 200.000 - 150.000 = 550.000.
        $respon->assertViewHas('saldoAwal', fn ($v) => (float) $v >= 500000);
        $respon->assertViewHas('totalPenerimaan', fn ($v) => (float) $v >= 200000);
        $respon->assertViewHas('totalPenyaluran', fn ($v) => (float) $v >= 150000);
        $respon->assertViewHas('saldoAkhir', fn ($v) => (float) $v === (float) $respon->viewData('saldoAwal')
            + (float) $respon->viewData('totalPenerimaan')
            - (float) $respon->viewData('totalPenyaluran'));
    }

    public function test_laporan_dana_kebajikan_memisahkan_periode_berdasarkan_tanggal(): void
    {
        DanaKebajikan::create([
            'no_referensi' => 'DK-LUAR-'.$this->suffix,
            'sumber_dana' => 'Infaq Anggota',
            'jenis' => 'Penerimaan',
            'jumlah' => 777000,
            'keterangan' => 'Di luar periode',
            'created_by' => $this->pengurus->id,
        ]);

        DB::table('dana_kebajikans')
            ->where('no_referensi', 'DK-LUAR-'.$this->suffix)
            ->update(['created_at' => now()->subDays(90)]);

        $respon = $this->actingAs($this->pengurus)->get(route('pengurus.laporan.dana-kebajikan', [
            'dari' => now()->startOfMonth()->toDateString(),
            'sampai' => now()->toDateString(),
        ]));

        $respon->assertOk();

        $this->assertSame(0.0, (float) $respon->viewData('totalPenerimaan'),
            'Penerimaan di luar rentang tanggal tidak boleh ikut terhitung.');
    }

    /* =====================================================================
     | R4 — PENGESAHAN SHU (REKAP ALOKASI)
     ===================================================================== */

    public function test_pengesahan_shu_menjumlahkan_alokasi_sesuai_persentase(): void
    {
        $respon = $this->actingAs($this->pengurus)
            ->get(route('pengurus.pengesahan-shu.index', ['tahun' => now()->year]));

        $respon->assertOk();

        $alokasi = $respon->viewData('alokasi');
        $this->assertIsArray($alokasi);

        $totalNominal = (float) $respon->viewData('totalNominal');
        $totalPersen = (float) $respon->viewData('totalPersen');

        $this->assertEqualsWithDelta(
            array_sum(array_column($alokasi, 'nominal')),
            $totalNominal,
            0.01,
            'Total nominal alokasi harus sama dengan jumlah nominal tiap baris.'
        );

        $this->assertEqualsWithDelta(
            array_sum(array_column($alokasi, 'persen')),
            $totalPersen,
            0.01,
            'Total persen alokasi harus sama dengan jumlah persen tiap baris.'
        );
    }

    /* =====================================================================
     | R5 — UBAH PARAMETER PRODUK PEMBIAYAAN
     ===================================================================== */

    public function test_produk_update_ditolak_bila_max_plafon_di_bawah_min_plafon(): void
    {
        $produk = $this->produkUji();

        $this->actingAs($this->superAdmin)
            ->from(route('pengurus.produk.edit', $produk->id))
            ->put(route('pengurus.produk.update', $produk->id), [
                'margin_persen' => 12,
                'min_plafon' => 5000000,
                'max_plafon' => 1000000,
                'min_tenor_bulan' => 3,
                'max_tenor_bulan' => 24,
                'status_aktif' => 1,
            ])
            ->assertSessionHasErrors('max_plafon');

        $produk->refresh();
        $this->assertSame('1000000.00', (string) $produk->min_plafon,
            'Validasi gagal tidak boleh mengubah data produk.');
    }

    public function test_produk_update_menyimpan_parameter_baru(): void
    {
        $produk = $this->produkUji();

        $this->actingAs($this->superAdmin)
            ->put(route('pengurus.produk.update', $produk->id), [
                'margin_persen' => 17.5,
                'min_plafon' => 2000000,
                'max_plafon' => 75000000,
                'min_tenor_bulan' => 6,
                'max_tenor_bulan' => 36,
                'biaya_admin' => 25000,
                'status_aktif' => 0,
            ])
            ->assertRedirect(route('pengurus.produk.index'))
            ->assertSessionHas('success');

        $produk->refresh();
        $this->assertSame('17.50', (string) $produk->margin_persen);
        $this->assertSame('75000000.00', (string) $produk->max_plafon);
        $this->assertSame(36, (int) $produk->max_tenor_bulan);
        $this->assertFalse((bool) $produk->status_aktif, 'Status aktif harus mengikuti input (0 = nonaktif).');
    }

    public function test_produk_update_ditolak_403_untuk_pengurus(): void
    {
        $produk = $this->produkUji();

        // Segregation of Duties: parameter produk adalah master data teknis,
        // bukan kebijakan strategis Pengurus.
        $this->actingAs($this->pengurus)
            ->put(route('pengurus.produk.update', $produk->id), [
                'margin_persen' => 99,
                'min_plafon' => 0,
                'max_plafon' => 999999999,
                'min_tenor_bulan' => 1,
                'max_tenor_bulan' => 60,
                'status_aktif' => 1,
            ])
            ->assertForbidden();

        $produk->refresh();
        $this->assertSame('10.00', (string) $produk->margin_persen,
            'Pengurus yang ditolak tidak boleh mengubah margin produk.');
    }

    /* =====================================================================
     | R6 — TAMBAH AKUN COA
     ===================================================================== */

    public function test_coa_store_ditolak_403_untuk_pengurus(): void
    {
        $this->actingAs($this->pengurus)
            ->post(route('pengurus.coa.store'), [
                'kode_akun' => '8-'.$this->suffix,
                'nama_akun' => 'Akun Uji Wewenang',
                'kategori' => 'Aset',
                'klasifikasi_neraca' => 'Aset Lancar',
                'tipe_saldo_normal' => 'Debet',
            ])
            ->assertForbidden();

        $this->assertFalse(Coa::where('kode_akun', '8-'.$this->suffix)->exists(),
            'Pengurus tidak boleh menambah akun CoA.');
    }

    public function test_coa_store_menolak_klasifikasi_yang_tidak_sesuai_kategori(): void
    {
        $this->actingAs($this->superAdmin)
            ->from(route('pengurus.coa.index'))
            ->post(route('pengurus.coa.store'), [
                'kode_akun' => '9-'.$this->suffix,
                'nama_akun' => 'Akun Uji Klasifikasi',
                'kategori' => 'Aset',
                'klasifikasi_neraca' => 'Kewajiban Jangka Pendek',
                'tipe_saldo_normal' => 'Debet',
            ])
            ->assertSessionHasErrors('klasifikasi_neraca');

        $this->assertFalse(Coa::where('kode_akun', '9-'.$this->suffix)->exists(),
            'Akun dengan klasifikasi salah tidak boleh tersimpan.');
    }

    public function test_coa_store_menolak_kode_akun_ganda(): void
    {
        $kode = (string) DB::table('coas')->value('kode_akun');
        $this->assertNotEmpty($kode, 'Prasyarat: minimal satu akun CoA sudah ada.');

        $this->actingAs($this->superAdmin)
            ->from(route('pengurus.coa.index'))
            ->post(route('pengurus.coa.store'), [
                'kode_akun' => $kode,
                'nama_akun' => 'Duplikat Uji',
                'kategori' => 'Aset',
                'klasifikasi_neraca' => 'Aset Lancar',
                'tipe_saldo_normal' => 'Debet',
            ])
            ->assertSessionHasErrors('kode_akun');
    }

    public function test_coa_store_menyimpan_akun_baru_yang_sah(): void
    {
        $kode = '9-'.$this->suffix;

        $this->actingAs($this->superAdmin)
            ->post(route('pengurus.coa.store'), [
                'kode_akun' => $kode,
                'nama_akun' => 'Akun Uji Batch 20',
                'kategori' => 'Aset',
                'klasifikasi_neraca' => 'Aset Lancar',
                'tipe_saldo_normal' => 'Debet',
                'saldo_awal' => 0,
            ])
            ->assertRedirect(route('pengurus.coa.index'))
            ->assertSessionHas('success');

        $this->assertTrue(Coa::where('kode_akun', $kode)->exists(),
            'Akun dengan klasifikasi sah harus tersimpan.');
    }

    /* =====================================================================
     | R7 — EKSPOR LAPORAN PPATK
     ===================================================================== */

    public function test_aml_export_jenis_tak_dikenal_ditolak_404(): void
    {
        // Rute membatasi `jenis` ke LTKT/LTKM/STR lewat `whereIn`, sehingga
        // nilai lain tidak pernah mencapai controller.
        $this->actingAs($this->pengurus)
            ->get('/pengurus/laporan-aml/export/XYZ')
            ->assertNotFound();
    }

    public function test_aml_export_ltkt_menghasilkan_csv_bertanda_kop_pelapor(): void
    {
        $respon = $this->actingAs($this->pengurus)->get(route('pengurus.aml.export', [
            'jenis' => 'LTKT',
            'dari' => now()->startOfMonth()->toDateString(),
            'sampai' => now()->toDateString(),
        ]));

        $respon->assertOk();
        $this->assertStringContainsString('csv', (string) $respon->headers->get('content-type'));

        $isi = $respon->getContent();
        $this->assertNotEmpty($isi, 'Berkas ekspor PPATK tidak boleh kosong.');
        $this->assertStringContainsString('LTKT', $isi,
            'Kop laporan harus menyebut jenis laporan LTKT.');
    }

    /* =====================================================================
     | R8 — RINCIAN PENGAJUAN PEMBIAYAAN
     ===================================================================== */

    public function test_pembiayaan_show_menampilkan_pengajuan_yang_diminta(): void
    {
        $pembiayaan = Pembiayaan::query()->first();

        if (! $pembiayaan) {
            $this->markTestSkipped('Belum ada data pembiayaan untuk fixture.');
        }

        $this->actingAs($this->pengurus)
            ->get(route('pengurus.pembiayaan.show', $pembiayaan->id))
            ->assertOk();
    }

    public function test_pembiayaan_show_id_tidak_ada_menghasilkan_404(): void
    {
        $this->actingAs($this->pengurus)
            ->get(route('pengurus.pembiayaan.show', 999999999))
            ->assertNotFound();
    }

    /* =====================================================================
     | PENJAGA WEWENANG — batas volume baris tetap berlaku pada rute ini
     ===================================================================== */

    public function test_ekspor_shu_menghormati_batas_baris_peran(): void
    {
        $periode = $this->siapkanPeriodeShu(2);

        $manajer = User::firstOrCreate(
            ['email' => 'batch20.rute.manajer@test.local'],
            [
                'name' => 'Manajer Batch 20',
                'password' => Hash::make('rahasiabatch20'),
                'role' => 'manajer',
            ]
        );

        $batas = HierarkiRole::aksesData($manajer->role, 'maks_baris_ekspor');

        if ($batas === null || $batas === 0) {
            $this->markTestSkipped('Peran manajer tidak memiliki batas baris ekspor pada konfigurasi.');
        }

        // Dua baris tidak boleh melampaui batas apa pun yang wajar; yang
        // diuji adalah mekanismenya, bukan angka konfigurasinya.
        $hasil = HierarkiRole::ambilDenganBatas(
            ShuDistribution::where('shu_period_id', $periode->id),
            $manajer->role
        );

        $this->assertSame($batas, $hasil['batas'],
            'Batas yang dilaporkan harus sama dengan konfigurasi peran.');
        $this->assertFalse($hasil['melampaui'],
            'Dua baris distribusi tidak boleh melampaui batas peran manajer.');
        $this->assertCount(2, $hasil['data']);
    }
}
