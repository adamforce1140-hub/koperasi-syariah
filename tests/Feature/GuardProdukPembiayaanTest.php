<?php

namespace Tests\Feature;

use App\Models\MasterProdukPembiayaan;
use App\Models\ProdukSyaratDokumen;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * GUARD CRUD master produk pembiayaan (DynamicLoanController):
 *
 *  1. `destroy` — produk yang sudah DISERTIFIKASI DPS tidak boleh dihapus
 *     (menghapus jejak audit syariah), dan produk yang sudah dipakai
 *     pengajuan anggota tidak boleh dihapus.
 *  2. `update` — produk bagi hasil yang tidak mengirim field nisbah tidak
 *     boleh gagal (nilai di DB dipakai), dan `status_aktif` tidak boleh
 *     berubah saat form tidak mengirimnya.
 *  3. `update` — syarat dokumen tidak boleh terhapus saat form tidak
 *     mengirim field `syarat`.
 */
class GuardProdukPembiayaanTest extends TestCase
{
    use DatabaseTransactions;

    private function pengurus(): User
    {
        return User::firstOrCreate(
            ['email' => 'pengurus.guardproduk@test.local'],
            [
                'name' => 'Pengurus Guard Produk',
                'password' => Hash::make('rahasia'),
                'role' => 'pengurus',
                'is_active' => true,
            ]
        );
    }

    private function produk(array $override = []): MasterProdukPembiayaan
    {
        return MasterProdukPembiayaan::create(array_merge([
            'kode_produk' => 'GRD-'.strtoupper(uniqid()),
            'nama_produk' => 'Produk Uji Guard',
            'akad_syariah' => 'Murabahah',
            'min_plafon' => 1_000_000,
            'max_plafon' => 10_000_000,
            'margin_persen' => 12,
            'biaya_admin' => 0,
            'min_bulan_anggota' => 6,
            'min_tenor_bulan' => 6,
            'max_tenor_bulan' => 24,
            'status_aktif' => true,
            'status_sertifikasi_dps' => MasterProdukPembiayaan::SERTIFIKASI_BELUM,
        ], $override));
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'nama_produk' => 'Produk Uji Guard',
            'akad_syariah' => 'Murabahah',
            'min_plafon' => 1_000_000,
            'max_plafon' => 10_000_000,
            'margin_persen' => 13.5,
            'biaya_admin' => 0,
            'min_bulan_anggota' => 6,
            'min_tenor_bulan' => 6,
            'max_tenor_bulan' => 24,
        ], $override);
    }

    public function test_produk_tersertifikasi_dps_tidak_dapat_dihapus(): void
    {
        $produk = $this->produk([
            'status_sertifikasi_dps' => MasterProdukPembiayaan::SERTIFIKASI_VALID,
        ]);

        $res = $this->actingAs($this->pengurus())
            ->delete(route('pengelola.produk-pembiayaan.destroy', $produk));

        $res->assertRedirect(route('pengelola.produk-pembiayaan.index'));
        $res->assertSessionHas('error');
        $this->assertDatabaseHas('master_produk_pembiayaan', ['id' => $produk->id]);
    }

    public function test_produk_belum_disertifikasi_dapat_dihapus(): void
    {
        $produk = $this->produk();
        ProdukSyaratDokumen::create([
            'produk_id' => $produk->id,
            'nama_dokumen' => 'KTP',
            'is_required' => true,
        ]);

        $res = $this->actingAs($this->pengurus())
            ->delete(route('pengelola.produk-pembiayaan.destroy', $produk));

        $res->assertRedirect(route('pengelola.produk-pembiayaan.index'));
        $res->assertSessionHas('success');
        $this->assertDatabaseMissing('master_produk_pembiayaan', ['id' => $produk->id]);
        $this->assertDatabaseMissing('produk_syarat_dokumen', ['produk_id' => $produk->id]);
    }

    public function test_update_produk_bagi_hasil_tanpa_field_nisbah_tetap_berhasil(): void
    {
        $produk = $this->produk([
            'akad_syariah' => 'Mudharabah',
            'nisbah_anggota' => 60,
            'nisbah_koperasi' => 40,
        ]);

        $res = $this->actingAs($this->pengurus())
            ->put(route('pengelola.produk-pembiayaan.update', $produk), $this->payload([
                'akad_syariah' => 'Mudharabah',
                'margin_persen' => 11,
            ]));

        $res->assertRedirect(route('pengelola.produk-pembiayaan.index'));
        $res->assertSessionHas('success');
        $produk->refresh();
        $this->assertEquals('11.00', $produk->margin_persen);
        $this->assertEquals(60.0, (float) $produk->nisbah_anggota);
        $this->assertEquals(40.0, (float) $produk->nisbah_koperasi);
    }

    public function test_update_tanpa_field_status_aktif_tidak_mengubah_status(): void
    {
        $produk = $this->produk(['status_aktif' => false]);

        $this->actingAs($this->pengurus())
            ->put(route('pengelola.produk-pembiayaan.update', $produk), $this->payload());

        $produk->refresh();
        $this->assertFalse((bool) $produk->status_aktif);
    }

    public function test_update_dapat_menonaktifkan_produk(): void
    {
        $produk = $this->produk(['status_aktif' => true]);

        $this->actingAs($this->pengurus())
            ->put(route('pengelola.produk-pembiayaan.update', $produk), $this->payload([
                'status_aktif' => '0',
            ]));

        $produk->refresh();
        $this->assertFalse((bool) $produk->status_aktif);
    }

    public function test_update_tanpa_field_syarat_tidak_menghapus_syarat_dokumen(): void
    {
        $produk = $this->produk();
        foreach (['KTP', 'KK'] as $nama) {
            ProdukSyaratDokumen::create([
                'produk_id' => $produk->id,
                'nama_dokumen' => $nama,
                'is_required' => true,
            ]);
        }

        $this->actingAs($this->pengurus())
            ->put(route('pengelola.produk-pembiayaan.update', $produk), $this->payload());

        $this->assertSame(2, $produk->syaratDokumen()->count());
    }
}
