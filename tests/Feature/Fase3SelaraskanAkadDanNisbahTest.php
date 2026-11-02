<?php

namespace Tests\Feature;

use App\Models\MasterProdukPembiayaan;
use App\Models\Pembiayaan;
use App\Models\PengajuanPembiayaan;
use App\Models\User;
use Database\Seeders\AkunSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Test pengunci Fase 3 — R10 & R11.
 *
 * R11: produk pembiayaan harus mendukung 8 akad kanonik (termasuk IMBT,
 *      Istishna, Salam) dan seluruh akun piutang legacy terkait harus ada.
 * R10: produk berakad bagi hasil (Mudharabah/Musyarakah) wajib memiliki
 *      nisbah anggota + koperasi yang berjumlah tepat 100%.
 */
class Fase3SelaraskanAkadDanNisbahTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AkunSeeder::class);
    }

    protected function pengurus(): User
    {
        return User::firstOrCreate(
            ['email' => 'pengurus.nisbah.fase3@test.local'],
            ['name' => 'Pengurus Nisbah Fase3', 'password' => Hash::make('password'), 'role' => 'pengurus']
        );
    }

    /* ---------- R11 ---------- */

    public function test_daftar_akad_kanonik_berjumlah_delapan(): void
    {
        $akad = Pembiayaan::daftarAkad();

        $this->assertCount(8, $akad);
        foreach (['Murabahah', 'Mudharabah', 'Musyarakah', 'Ijarah', 'Ijarah Muntahia Bittamlik', 'Qardh', 'Istishna', 'Salam'] as $nama) {
            $this->assertContains($nama, $akad, "Akad {$nama} harus ada pada daftar akad kanonik.");
        }
    }

    public function test_produk_dapat_disimpan_dengan_akad_imbt_istishna_salam(): void
    {
        foreach (['Ijarah Muntahia Bittamlik', 'Istishna', 'Salam'] as $akad) {
            $produk = MasterProdukPembiayaan::create([
                'nama_produk' => "Produk {$akad}",
                'akad_syariah' => $akad,
                'min_plafon' => 1_000_000,
                'max_plafon' => 10_000_000,
                'margin_persen' => 5,
                'status_aktif' => true,
            ]);

            $this->assertSame($akad, $produk->fresh()->akad_syariah);
        }
    }

    public function test_database_menolak_akad_yang_tidak_kanonik(): void
    {
        $this->expectException(QueryException::class);

        // CHECK constraint hanya mengizinkan 8 akad kanonik.
        DB::table('master_produk_pembiayaan')->insert([
            'nama_produk' => 'Produk Ilegal',
            'akad_syariah' => 'Riba',
            'status_aktif' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_akun_piutang_legacy_untuk_istishna_dan_salam_tersedia(): void
    {
        $this->assertDatabaseHas('akun', ['kode_akun' => '126', 'nama_akun' => 'Pembiayaan Istishna']);
        $this->assertDatabaseHas('akun', ['kode_akun' => '127', 'nama_akun' => 'Pembiayaan Salam']);
    }

    public function test_peta_akun_piutang_per_akad_lengkap_delapan_akad(): void
    {
        $refleksi = new \ReflectionClassConstant(PengajuanPembiayaan::class, 'AKUN_PIUTANG_PER_AKAD');
        $peta = $refleksi->getValue();

        $this->assertCount(8, $peta);
        foreach (Pembiayaan::daftarAkad() as $akad) {
            $this->assertArrayHasKey($akad, $peta, "Akad {$akad} belum memiliki pemetaan akun piutang.");
        }
        // IMBT berakhir dengan perpindahan kepemilikan → turunan Murabahah (121).
        $this->assertSame('121', $peta['Ijarah Muntahia Bittamlik']);
    }

    /* ---------- R10 ---------- */

    public function test_produk_bagi_hasil_wajib_memiliki_nisbah(): void
    {
        $produk = new MasterProdukPembiayaan([
            'nama_produk' => 'Mudharabah Tanpa Nisbah',
            'akad_syariah' => Pembiayaan::AKAD_MUDHARABAH,
        ]);

        $this->assertTrue($produk->memerlukanNisbah());
        // Keduanya kosong → valid secara model; kewajiban ditegakkan di controller.
        $this->assertTrue($produk->nisbahValid());
    }

    public function test_nisbah_anggota_dan_koperasi_totalnya_harus_seratus_persen(): void
    {
        $sah = new MasterProdukPembiayaan([
            'akad_syariah' => Pembiayaan::AKAD_MUDHARABAH,
            'nisbah_anggota' => 40,
            'nisbah_koperasi' => 60,
        ]);
        $this->assertTrue($sah->nisbahValid());

        $tidakSeimbang = new MasterProdukPembiayaan([
            'akad_syariah' => Pembiayaan::AKAD_MUDHARABAH,
            'nisbah_anggota' => 40,
            'nisbah_koperasi' => 50,
        ]);
        $this->assertFalse($tidakSeimbang->nisbahValid());

        $hanyaSatu = new MasterProdukPembiayaan([
            'akad_syariah' => Pembiayaan::AKAD_MUDHARABAH,
            'nisbah_anggota' => 40,
        ]);
        $this->assertFalse($hanyaSatu->nisbahValid());
    }

    public function test_akad_bagi_hasil_hanya_mudharabah_dan_musyarakah(): void
    {
        $this->assertSame(
            [Pembiayaan::AKAD_MUDHARABAH, Pembiayaan::AKAD_MUSYARAKAH],
            MasterProdukPembiayaan::akadBagiHasil()
        );

        $murabahah = new MasterProdukPembiayaan(['akad_syariah' => Pembiayaan::AKAD_MURABAHAH]);
        $this->assertFalse($murabahah->memerlukanNisbah());
    }

    public function test_kolom_nisbah_tersimpan_dengan_presisi_tiga_desimal(): void
    {
        $produk = MasterProdukPembiayaan::create([
            'nama_produk' => 'Musyarakah Nisbah',
            'akad_syariah' => Pembiayaan::AKAD_MUSYARAKAH,
            'min_plafon' => 1_000_000,
            'max_plafon' => 5_000_000,
            'margin_persen' => 0,
            'nisbah_anggota' => 55.5,
            'nisbah_koperasi' => 44.5,
            'status_aktif' => true,
        ]);

        $segar = $produk->fresh();
        $this->assertSame('55.500', (string) $segar->nisbah_anggota);
        $this->assertSame('44.500', (string) $segar->nisbah_koperasi);
        $this->assertTrue($segar->nisbahValid());
    }

    public function test_controller_menolak_produk_bagi_hasil_tanpa_nisbah(): void
    {
        $respons = $this->actingAs($this->pengurus())->post(route('pengelola.produk-pembiayaan.store'), [
            'nama_produk' => 'Mudharabah Tanpa Nisbah',
            'akad_syariah' => Pembiayaan::AKAD_MUDHARABAH,
            'min_plafon' => 1_000_000,
            'max_plafon' => 5_000_000,
            'margin_persen' => 0,
            'biaya_admin' => 0,
            'min_bulan_anggota' => 0,
            'min_tenor_bulan' => 1,
            'max_tenor_bulan' => 12,
            'status_aktif' => 1,
            // nisbah sengaja dikosongkan
        ]);

        $respons->assertSessionHasErrors(['nisbah_anggota']);
        $this->assertDatabaseMissing('master_produk_pembiayaan', ['nama_produk' => 'Mudharabah Tanpa Nisbah']);
    }

    public function test_controller_menolak_nisbah_yang_tidak_berjumlah_seratus(): void
    {
        $respons = $this->actingAs($this->pengurus())->post(route('pengelola.produk-pembiayaan.store'), [
            'nama_produk' => 'Mudharabah Nisbah Salah',
            'akad_syariah' => Pembiayaan::AKAD_MUDHARABAH,
            'min_plafon' => 1_000_000,
            'max_plafon' => 5_000_000,
            'margin_persen' => 0,
            'biaya_admin' => 0,
            'min_bulan_anggota' => 0,
            'min_tenor_bulan' => 1,
            'max_tenor_bulan' => 12,
            'status_aktif' => 1,
            'nisbah_anggota' => 40,
            'nisbah_koperasi' => 50,
        ]);

        $respons->assertSessionHasErrors(['nisbah_anggota']);
        $this->assertDatabaseMissing('master_produk_pembiayaan', ['nama_produk' => 'Mudharabah Nisbah Salah']);
    }

    public function test_controller_menerima_nisbah_yang_sah(): void
    {
        $respons = $this->actingAs($this->pengurus())->post(route('pengelola.produk-pembiayaan.store'), [
            'nama_produk' => 'Mudharabah Nisbah Sah',
            'akad_syariah' => Pembiayaan::AKAD_MUDHARABAH,
            'min_plafon' => 1_000_000,
            'max_plafon' => 5_000_000,
            'margin_persen' => 0,
            'biaya_admin' => 0,
            'min_bulan_anggota' => 0,
            'min_tenor_bulan' => 1,
            'max_tenor_bulan' => 12,
            'status_aktif' => 1,
            'nisbah_anggota' => 45,
            'nisbah_koperasi' => 55,
        ]);

        $respons->assertSessionHasNoErrors();
        $produk = MasterProdukPembiayaan::where('nama_produk', 'Mudharabah Nisbah Sah')->firstOrFail();
        $this->assertTrue($produk->nisbahValid());
    }
}
