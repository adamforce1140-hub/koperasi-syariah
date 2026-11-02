<?php

namespace Tests\Feature;

use Database\Seeders\DemoDataSeeder;
use ReflectionClass;
use Tests\TestCase;

/**
 * REGRESI #1/#9 — data demo wajib menghasilkan SHU POSITIF.
 *
 * Seeder lama membukukan beban operasional Rp 2.000.000 sementara pendapatan
 * demo hanya Rp 439.167 (margin angsuran ke-1 dari 5 akad + biaya admin).
 * Tutup buku lalu memposting rugi Rp 1.560.833 ke akun 3-9000 (Ikhtisar SHU)
 * sehingga laporan menampilkan koperasi rugi dan alokasi SHU 20% tidak pernah
 * berjalan (lihat `DemoDataSeeder::alokasiShu()`).
 *
 * Penutupan itu sendiri sudah benar — yang salah adalah komposisi data demo.
 * Test ini mengunci invariannya: total beban operasional demo harus lebih
 * kecil dari pendapatan margin bulanan demo, sehingga:
 *   - 3-9000 berakhir positif (laba), dan
 *   - alokasi 20% ke Dana Cadangan (3-2001) benar-benar tereksekusi.
 */
class DemoDataSeederShuTest extends TestCase
{
    /** Biaya administrasi demo (LANGKAH 12). */
    private const BIAYA_ADMIN_DEMO = 150_000;

    /**
     * @return array<int, array<string, mixed>>
     */
    private function konstanta(string $nama): array
    {
        $ref = new ReflectionClass(DemoDataSeeder::class);

        return $ref->getConstant($nama);
    }

    /** Pendapatan margin yang diakui pada SATU angsuran untuk seluruh akad demo. */
    private function marginBulananDemo(): float
    {
        return array_sum(array_map(
            fn (array $cfg) => $cfg['jumlah'] * $cfg['margin'] / 100 / $cfg['tenor'],
            $this->konstanta('AKAD_DEMO')
        ));
    }

    private function totalBebanDemo(): float
    {
        return (float) array_sum(array_column($this->konstanta('BEBAN_OPERASIONAL_DEMO'), 'nominal'));
    }

    public function test_beban_operasional_demo_lebih_kecil_dari_pendapatan_margin(): void
    {
        $margin = $this->marginBulananDemo();

        $this->assertEqualsWithDelta(289_166.67, $margin, 0.01);
        $this->assertLessThan(
            $margin,
            $this->totalBebanDemo(),
            'Beban operasional demo tidak boleh melebihi pendapatan margin bulanan — '
            .'jika melebihi, tutup buku menghasilkan rugi pada akun 3-9000.'
        );
    }

    public function test_shu_demo_positif_dan_alokasi_20_persen_terbentuk(): void
    {
        $pendapatan = $this->marginBulananDemo() + self::BIAYA_ADMIN_DEMO;
        $shu = $pendapatan - $this->totalBebanDemo();

        $this->assertGreaterThan(0, $shu, 'SHU demo harus laba (positif).');
        $this->assertGreaterThan(0, (int) ($shu * 0.20), 'Alokasi 20% SHU harus > 0.');
    }

    public function test_setiap_item_beban_demo_bernilai_positif_dan_unik(): void
    {
        $items = $this->konstanta('BEBAN_OPERASIONAL_DEMO');

        $this->assertCount(3, $items);
        foreach ($items as $item) {
            $this->assertGreaterThan(0, $item['nominal'], 'Nominal beban demo harus > 0.');
        }

        $refs = array_column($items, 'ref');
        $this->assertSame($refs, array_unique($refs), 'Nomor referensi beban demo harus unik.');
    }
}
