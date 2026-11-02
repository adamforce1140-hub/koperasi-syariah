<?php

namespace Tests\Feature;

use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\MasterProdukPembiayaan;
use App\Models\Pembiayaan;
use App\Models\PemetaanAkadCoa;
use App\Models\User;
use App\Services\AutoJournalingService;
use App\Services\CoaResolver;
use Database\Seeders\AkunSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PEMETAAN AKAD → COA PER PRODUK (Tahap 11) — kunci regresi.
 *
 * Melengkapi `FleksibilitasAkadPsakTest` (yang menguji pemetaan universal
 * `kode_produk = '*'`). Berkas ini mengunci sifat per-produk yang baru:
 *
 *   1. `pemetaan_akad_coa.kode_produk` mengalahkan baris universal '*'
 *      ketika produk diminta secara eksplisit, dan jatuh ke '*' bila tidak.
 *   2. `CoaResolver::kodePiutangByAkad($akad, $kodeProduk)` meneruskan kode
 *      produk itu sampai ke akun piutang yang dipakai jurnal pencairan.
 *   3. Identitas produk (`kode_produk`) selalu tersimpan huruf besar tanpa
 *      spasi tepi dan kode baru berpola `PRD-YYYYMM-NNNN`.
 *   4. Logika bisnis produk (plafon/tenor/margin/nisbah) menolak nilai
 *      mustahil sebelum disimpan.
 *   5. Pengurus dapat memetakan per produk lewat UI dan kode produk ber-pola
 *      salah ditolak validasi.
 */
class PemetaanAkadCoaPerProdukTest extends TestCase
{
    use DatabaseTransactions;

    private User $pengurus;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('pemetaan_akad_coa')) {
            $this->markTestSkipped('Tabel pemetaan_akad_coa belum tersedia.');
        }

        if (! Schema::hasColumn('master_produk_pembiayaan', 'kode_produk')) {
            $this->markTestSkipped('Kolom master_produk_pembiayaan.kode_produk belum tersedia.');
        }

        $this->seed(AkunSeeder::class);

        PemetaanAkadCoa::flush();

        $this->pengurus = User::firstOrCreate(
            ['email' => 'pemetaan.perproduk@test.local'],
            [
                'name' => 'Uji Pemetaan Per Produk',
                'password' => Hash::make('password'),
                'role' => 'pengurus',
                'is_active' => true,
            ]
        );
    }

    /** Akun CoA aktif dengan penomoran tertentu (dipakai sebagai target pemetaan). */
    private function akunCoa(int $offset = 0): ?Coa
    {
        return Coa::query()
            ->where('is_active', true)
            ->orderBy('kode_akun')
            ->skip($offset)
            ->first();
    }

    /** Produk pembiayaan Murabahah dengan kode produk tertentu (untuk pemetaan). */
    private function produk(string $kode = 'MRB-TEST-01'): MasterProdukPembiayaan
    {
        return MasterProdukPembiayaan::create([
            'kode_produk' => $kode,
            'nama_produk' => "Produk Uji {$kode}",
            'akad_syariah' => Pembiayaan::AKAD_MURABAHAH,
            'min_plafon' => 1_000_000,
            'max_plafon' => 10_000_000,
            'margin_persen' => 5,
            'status_aktif' => true,
        ]);
    }

    /**
     * Tetapkan pemetaan akad → CoA untuk kombinasi produk+akad tertentu.
     *
     * Memakai `updateOrCreate` agar aman terhadap baris bawaan hasil seeding
     * (basis data uji ter-seed) yang sudah memuat pemetaan universal akad.
     */
    private function petakan(string $akad, string $kodeProduk, Coa $akun): PemetaanAkadCoa
    {
        $pemetaan = PemetaanAkadCoa::updateOrCreate(
            ['kode_produk' => $kodeProduk, 'akad_pembiayaan' => $akad],
            ['account_piutang_id' => $akun->id, 'status_aktif' => true]
        );

        PemetaanAkadCoa::flush();

        return $pemetaan;
    }

    /* ── 1. Identitas kode produk ─────────────────────────────────────── */

    public function test_mutator_kode_produk_menormalkan_huruf_besar_dan_spasi(): void
    {
        $produk = $this->produk('  mrb-test-99  ');

        $this->assertSame('MRB-TEST-99', $produk->kode_produk);
    }

    public function test_kode_produk_kosong_disimpan_null_bukan_string_kosong(): void
    {
        $produk = MasterProdukPembiayaan::create([
            'kode_produk' => '   ',
            'nama_produk' => 'Produk Tanpa Kode',
            'akad_syariah' => Pembiayaan::AKAD_MURABAHAH,
            'min_plafon' => 1_000_000,
            'max_plafon' => 10_000_000,
            'margin_persen' => 5,
            'status_aktif' => true,
        ]);

        $this->assertNull($produk->kode_produk);
    }

    public function test_generate_kode_berpola_prd_tahunbulan_urut(): void
    {
        $kode = MasterProdukPembiayaan::generateKode();

        $this->assertMatchesRegularExpression('/^PRD-\d{6}-\d{4}$/', $kode);
        $this->assertStringStartsWith('PRD-'.date('Ym').'-', $kode);
    }

    public function test_generate_kode_menaikkan_nomor_urut_per_prefix_bulan(): void
    {
        $prefix = 'PRD-'.date('Ym').'-';

        // ID terbesar produk ber-prefix sama menentukan nomor berikutnya.
        $tertinggi = (int) MasterProdukPembiayaan::query()
            ->where('kode_produk', 'like', $prefix.'%')
            ->max('id');

        $kode = MasterProdukPembiayaan::generateKode();

        $this->assertSame(
            $prefix.str_pad((string) ($tertinggi + 1), 4, '0', STR_PAD_LEFT),
            $kode
        );
    }

    /* ── 2. Prioritas pemetaan: khusus produk > universal '*' ─────────── */

    public function test_pemetaan_khusus_produk_mengalahkan_universal(): void
    {
        $akunUniversal = $this->akunCoa(0);
        $akunProduk = $this->akunCoa(1);

        $this->assertNotNull($akunUniversal, 'Butuh minimal 2 akun CoA aktif untuk pengujian.');
        $this->assertNotNull($akunProduk, 'Butuh minimal 2 akun CoA aktif untuk pengujian.');

        $this->petakan(Pembiayaan::AKAD_MURABAHAH, PemetaanAkadCoa::SEMUA_PRODUK, $akunUniversal);
        $this->petakan(Pembiayaan::AKAD_MURABAHAH, 'MRB-TEST-01', $akunProduk);

        $khusus = PemetaanAkadCoa::untukAkad(Pembiayaan::AKAD_MURABAHAH, 'MRB-TEST-01');
        $this->assertSame($akunProduk->id, $khusus->account_piutang_id);

        // Produk lain pada akad sama jatuh ke baris universal '*'.
        $universal = PemetaanAkadCoa::untukAkad(Pembiayaan::AKAD_MURABAHAH, 'MRB-LAIN-02');
        $this->assertSame($akunUniversal->id, $universal->account_piutang_id);
    }

    public function test_resolver_coa_mengembalikan_akun_piutang_per_produk(): void
    {
        $akunUniversal = $this->akunCoa(0);
        $akunProduk = $this->akunCoa(1);

        $this->assertNotNull($akunUniversal);
        $this->assertNotNull($akunProduk);

        $this->petakan(Pembiayaan::AKAD_MURABAHAH, PemetaanAkadCoa::SEMUA_PRODUK, $akunUniversal);
        $this->petakan(Pembiayaan::AKAD_MURABAHAH, 'MRB-TEST-01', $akunProduk);

        $resolver = app(CoaResolver::class);

        $this->assertSame(
            $akunProduk->kode_akun,
            $resolver->kodePiutangByAkad(Pembiayaan::AKAD_MURABAHAH, 'MRB-TEST-01')
        );

        $this->assertSame(
            $akunUniversal->kode_akun,
            $resolver->kodePiutangByAkad(Pembiayaan::AKAD_MURABAHAH, 'MRB-LAIN-02')
        );
    }

    public function test_model_produk_memakai_pemetaan_khususnya(): void
    {
        $akunUniversal = $this->akunCoa(0);
        $akunProduk = $this->akunCoa(1);

        $this->assertNotNull($akunUniversal);
        $this->assertNotNull($akunProduk);

        $produk = $this->produk('MRB-TEST-01');

        $this->petakan(Pembiayaan::AKAD_MURABAHAH, PemetaanAkadCoa::SEMUA_PRODUK, $akunUniversal);
        $this->petakan(Pembiayaan::AKAD_MURABAHAH, 'MRB-TEST-01', $akunProduk);

        $this->assertSame($akunProduk->id, $produk->pemetaanAkadCoa()->account_piutang_id);
    }

    /* ── 3. Jurnal pencairan memakai akun piutang per produk ──────────── */

    public function test_jurnal_pencairan_memakai_akun_piutang_khusus_produk(): void
    {
        $akunUniversal = $this->akunCoa(0);
        $akunProduk = $this->akunCoa(1);
        $akunSumber = $this->akunCoa(2);

        $this->assertNotNull($akunUniversal);
        $this->assertNotNull($akunProduk);
        $this->assertNotNull($akunSumber);

        $this->petakan(Pembiayaan::AKAD_MURABAHAH, PemetaanAkadCoa::SEMUA_PRODUK, $akunUniversal);
        $this->petakan(Pembiayaan::AKAD_MURABAHAH, 'MRB-TEST-01', $akunProduk);

        $jurnal = app(AutoJournalingService::class)->jurnalPencairan(
            nominal: 1_000_000,
            akad: Pembiayaan::AKAD_MURABAHAH,
            keterangan: 'Uji pencairan per produk',
            userId: $this->pengurus->id,
            akunSumberDana: $akunSumber->kode_akun,
            kodeProduk: 'MRB-TEST-01',
        );

        $this->assertInstanceOf(Jurnal::class, $jurnal);

        $barisDebet = $jurnal->detail()->get()->firstWhere('debet', '>', 0);

        $this->assertNotNull($barisDebet, 'Jurnal pencairan harus memiliki baris debet.');
        $this->assertSame(
            $akunProduk->id,
            $barisDebet->coa_id,
            'Baris debet harus memakai akun piutang pemetaan khusus produk.'
        );
    }

    /* ── 4. Validasi logika bisnis produk ─────────────────────────────── */

    public function test_nilai_bisnis_valid_untuk_produk_wajar(): void
    {
        $produk = $this->produk('MRB-SEHAT-01');

        $this->assertSame([], $produk->nilaiBisnisTidakValid());
    }

    public function test_nilai_bisnis_menolak_plafon_terbalik(): void
    {
        $produk = $this->produk('MRB-PLAFON-01');
        $produk->min_plafon = 9_000_000;
        $produk->max_plafon = 1_000_000;

        $galat = $produk->nilaiBisnisTidakValid();

        $this->assertNotEmpty($galat);
        $this->assertStringContainsString('Plafon minimum', implode(' | ', $galat));
    }

    public function test_nilai_bisnis_menolak_tenor_terbalik(): void
    {
        $produk = $this->produk('MRB-TENOR-01');
        $produk->min_tenor_bulan = 24;
        $produk->max_tenor_bulan = 6;

        $galat = $produk->nilaiBisnisTidakValid();

        $this->assertNotEmpty($galat);
        $this->assertStringContainsString('Tenor minimum', implode(' | ', $galat));
    }

    public function test_nilai_bisnis_menolak_margin_di_luar_rentang(): void
    {
        $produk = $this->produk('MRB-MARGIN-01');
        $produk->margin_persen = 150;

        $galat = $produk->nilaiBisnisTidakValid();

        $this->assertNotEmpty($galat);
        $this->assertStringContainsString('Margin/persentase', implode(' | ', $galat));
    }

    public function test_nilai_bisnis_menolak_nisbah_bagi_hasil_tidak_utuh(): void
    {
        $produk = MasterProdukPembiayaan::create([
            'kode_produk' => 'MDR-NISBAH-01',
            'nama_produk' => 'Mudharabah Nisbah Tidak Utuh',
            'akad_syariah' => Pembiayaan::AKAD_MUDHARABAH,
            'min_plafon' => 1_000_000,
            'max_plafon' => 10_000_000,
            'margin_persen' => 0,
            'nisbah_anggota' => 40,
            'nisbah_koperasi' => 40,
            'status_aktif' => true,
        ]);

        $galat = $produk->nilaiBisnisTidakValid();

        $this->assertNotEmpty($galat);
        $this->assertStringContainsString('100%', implode(' | ', $galat));
    }

    /* ── 5. Penyimpanan pemetaan per produk lewat UI pengurus ─────────── */

    public function test_pengurus_dapat_menyimpan_pemetaan_khusus_produk(): void
    {
        $akun = $this->akunCoa(0);
        $this->assertNotNull($akun);

        $respons = $this->actingAs($this->pengurus)->post(
            route('pengurus.kebijakan.pemetaan-akad'),
            [
                'pemetaan_akad' => [
                    [
                        'kode_produk' => ' mrb-test-01 ',
                        'akad_pembiayaan' => Pembiayaan::AKAD_MURABAHAH,
                        'account_piutang_id' => $akun->id,
                    ],
                ],
            ]
        );

        $respons->assertRedirect(route('pengurus.kebijakan.index'));
        $respons->assertSessionHasNoErrors();

        // Kode produk dinormalkan menjadi huruf besar tanpa spasi tepi.
        $this->assertDatabaseHas('pemetaan_akad_coa', [
            'kode_produk' => 'MRB-TEST-01',
            'akad_pembiayaan' => Pembiayaan::AKAD_MURABAHAH,
        ]);
    }

    public function test_kode_produk_tanpa_isi_jatuh_ke_universal(): void
    {
        $akun = $this->akunCoa(0);
        $this->assertNotNull($akun);

        $respons = $this->actingAs($this->pengurus)->post(
            route('pengurus.kebijakan.pemetaan-akad'),
            [
                'pemetaan_akad' => [
                    [
                        'kode_produk' => '',
                        'akad_pembiayaan' => Pembiayaan::AKAD_MURABAHAH,
                        'account_piutang_id' => $akun->id,
                    ],
                ],
            ]
        );

        $respons->assertSessionHasNoErrors();

        $this->assertDatabaseHas('pemetaan_akad_coa', [
            'kode_produk' => PemetaanAkadCoa::SEMUA_PRODUK,
            'akad_pembiayaan' => Pembiayaan::AKAD_MURABAHAH,
        ]);
    }

    public function test_kode_produk_berpola_salah_ditolak(): void
    {
        $akun = $this->akunCoa(0);
        $this->assertNotNull($akun);

        $respons = $this->actingAs($this->pengurus)->post(
            route('pengurus.kebijakan.pemetaan-akad'),
            [
                'pemetaan_akad' => [
                    [
                        'kode_produk' => 'mrb test 01',
                        'akad_pembiayaan' => Pembiayaan::AKAD_MURABAHAH,
                        'account_piutang_id' => $akun->id,
                    ],
                ],
            ]
        );

        $respons->assertSessionHasErrors('pemetaan_akad.0.kode_produk');
    }

    public function test_baris_tanpa_akad_diabaikan(): void
    {
        $akun = $this->akunCoa(0);
        $this->assertNotNull($akun);

        $jumlahSebelum = PemetaanAkadCoa::count();

        $respons = $this->actingAs($this->pengurus)->post(
            route('pengurus.kebijakan.pemetaan-akad'),
            [
                'pemetaan_akad' => [
                    [
                        'kode_produk' => 'MRB-TEST-01',
                        'akad_pembiayaan' => '',
                        'account_piutang_id' => $akun->id,
                    ],
                ],
            ]
        );

        $respons->assertSessionHasNoErrors();
        $this->assertSame($jumlahSebelum, PemetaanAkadCoa::count());
    }
}
