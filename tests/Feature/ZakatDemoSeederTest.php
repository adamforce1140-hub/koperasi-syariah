<?php

namespace Tests\Feature;

use App\Models\ZakatTransaksi;
use App\Services\ZakatService;
use Database\Seeders\ZakatDemoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Integrasi data demo operasional modul Zakat (menutup temuan audit Z-02).
 *
 * Sebelum perbaikan, tabel `zakat_transaksis` KOSONG sehingga modul tidak
 * memiliki bukti operasi. Pengujian ini mengunci bahwa seeder demo:
 *  1. mengisi alur lengkap — penerimaan (pengumpulan) & penyaluran (distribusi);
 *  2. seluruh baris terhubung ke jurnal Posted (jejak audit PSAK 109);
 *  3. saldo buku besar modul IDENTIK dengan mutasi jurnal akun CoA dana
 *     (kontrol silang `ZakatService::validasi()` tidak menemukan selisih);
 *  4. zakat & infak memakai akun terpisah (2-3002 vs 2-3004);
 *  5. idempoten — dijalankan ulang tidak menggandakan data.
 *
 * CATATAN: pengujian ini TIDAK menghapus baris (menghapus buku besar tanpa
 * menghapus jurnal akan memicu catatan selisih pada `validasi()`); seeder
 * bersifat idempoten sehingga pengisian berulang aman.
 */
class ZakatDemoSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_seeder_mengisi_penerimaan_dan_penyaluran(): void
    {
        $this->seed(ZakatDemoSeeder::class);

        $zakatTerima = ZakatTransaksi::where('dana', ZakatTransaksi::DANA_ZAKAT)
            ->where('jenis', ZakatTransaksi::JENIS_PENERIMAAN)->count();
        $zakatSalur = ZakatTransaksi::where('dana', ZakatTransaksi::DANA_ZAKAT)
            ->where('jenis', ZakatTransaksi::JENIS_PENYALURAN)->count();
        $infakTerima = ZakatTransaksi::where('dana', ZakatTransaksi::DANA_INFAK_SEDEKAH)
            ->where('jenis', ZakatTransaksi::JENIS_PENERIMAAN)->count();

        $this->assertGreaterThanOrEqual(2, $zakatTerima, 'Minimal 2 penerimaan zakat demo.');
        $this->assertGreaterThanOrEqual(1, $zakatSalur, 'Minimal 1 penyaluran zakat demo.');
        $this->assertGreaterThanOrEqual(2, $infakTerima, 'Minimal 2 penerimaan infak/sedekah demo.');
    }

    public function test_setiap_baris_demo_terhubung_ke_jurnal_posted(): void
    {
        $this->seed(ZakatDemoSeeder::class);

        $baris = ZakatTransaksi::whereIn('no_referensi', [
            'ZKTD-2026-0001', 'ZKTD-2026-0002', 'ZKTD-2026-0003', 'ZKTD-2026-0004',
            'ZKTD-2026-0101', 'ZKTD-2026-0102', 'ZKTD-2026-0103',
        ])->get();

        $this->assertGreaterThanOrEqual(7, $baris->count(), 'Seluruh 7 baris demo harus ada.');
        $this->assertSame(0, $baris->whereNull('jurnal_id')->count(), 'Tidak ada baris tanpa jurnal.');

        foreach ($baris as $b) {
            $this->assertSame('Posted', $b->jurnal?->status, "Jurnal {$b->no_referensi} harus Posted.");
        }
    }

    public function test_saldo_buku_besar_konsisten_dengan_mutasi_jurnal(): void
    {
        $this->seed(ZakatDemoSeeder::class);

        // Validasi modul = kontrol silang buku besar vs mutasi jurnal CoA.
        $validasi = app(ZakatService::class)->validasi();

        $this->assertTrue($validasi['valid'], 'Integritas zakat harus valid: '
            .json_encode($validasi['catatan'] ?? []));
        $this->assertEmpty($validasi['catatan'] ?? [], 'Tidak boleh ada catatan selisih.');
    }

    public function test_zakat_dan_infak_memakai_akun_terpisah(): void
    {
        $this->seed(ZakatDemoSeeder::class);

        $this->assertNotSame(
            ZakatService::akunDana(ZakatTransaksi::DANA_ZAKAT),
            ZakatService::akunDana(ZakatTransaksi::DANA_INFAK_SEDEKAH),
            'Dana zakat & infak harus memakai akun CoA berbeda (PSAK 109).'
        );
    }

    public function test_seeder_idempoten_tidak_menggandakan_data(): void
    {
        $this->seed(ZakatDemoSeeder::class);
        $jumlahPertama = ZakatTransaksi::where('no_referensi', 'like', 'ZKTD-%')->count();

        $this->seed(ZakatDemoSeeder::class);
        $jumlahKedua = ZakatTransaksi::where('no_referensi', 'like', 'ZKTD-%')->count();

        $this->assertSame($jumlahPertama, $jumlahKedua, 'Menjalankan ulang seeder tidak boleh menambah baris.');
    }
}
