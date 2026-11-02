<?php

namespace Tests\Feature;

use App\Models\Transaksi;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Kop nota struk teller mengikuti identitas koperasi terpusat.
 *
 * Sebelumnya kop ditulis literal ("Koperasi Syariah Digital", alamat & telepon
 * contoh) sehingga nota yang dicetak tidak memakai identitas koperasi
 * sebenarnya. Sumber tunggal identitas dokumen cetak adalah
 * `config/pdf.php` → `koperasi`, yang dapat diubah lewat `.env`
 * (KOPERASI_NAMA, KOPERASI_ALAMAT, dst.) tanpa mengubah kode.
 */
class NotaKopIdentitasTest extends TestCase
{
    use DatabaseTransactions;

    private function teller(): User
    {
        return User::firstOrCreate(
            ['email' => 'nota.kop.teller@test.local'],
            [
                'name' => 'Uji Nota Teller',
                'password' => Hash::make('password'),
                'role' => 'teller',
                'is_active' => true,
            ]
        );
    }

    public function test_kop_nota_memakai_identitas_dari_config(): void
    {
        $transaksi = Transaksi::query()->first();
        $this->assertNotNull($transaksi, 'Butuh minimal satu transaksi untuk menguji nota.');

        $respons = $this->actingAs($this->teller())
            ->get(route('teller.nota', $transaksi->id));

        $respons->assertOk();

        $kop = config('pdf.koperasi');
        $respons->assertSee($kop['nama'], false);
        $respons->assertSee($kop['alamat'], false);
        $respons->assertSee($kop['telepon'], false);

        // Literal lama tidak boleh kembali tertanam di nota.
        $respons->assertDontSee('Koperasi Syariah Digital', false);
        $respons->assertDontSee('Jl. Koperasi No. 1, Jakarta Pusat 10110', false);
    }
}
