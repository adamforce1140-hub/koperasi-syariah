<?php

use App\Models\Angsuran;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * REGRESI AUDIT OPERASIONAL 6 PERAN (a8).
 *
 * `Angsuran::hitungHariTerlambat()` sebelumnya memakai arah selisih yang
 * TERBALIK (`$sekarang->diffInDays($jatuhTempo)`), sehingga angsuran yang
 * sudah lewat jatuh tempo menghasilkan angka NEGATIF (mis. -68 hari) dan
 * memicu deprecation "Implicit conversion from float to int". Test ini
 * mengunci kontrak: POSITIF = jumlah hari lewat jatuh tempo, angsuran yang
 * sudah dibayar = 0, dan nilainya identik dengan accessor dinamis.
 */
class AngsuranHitungHariTerlambatTest extends TestCase
{
    use DatabaseTransactions;

    private function jadwal(string $jatuhTempo, ?string $tanggalBayar = null): Angsuran
    {
        return Angsuran::make([
            'angsuran_ke' => 1,
            'tanggal_jatuh_tempo' => $jatuhTempo,
            'tanggal_bayar' => $tanggalBayar,
            'pokok_angsuran' => 100000,
            'margin_ujroh' => 10000,
            'jumlah_angsuran' => 110000,
            'status_angsuran' => $tanggalBayar ? 'Sudah Dibayar' : 'Belum Jatuh Tempo',
        ]);
    }

    public function test_hari_terlambat_positif_untuk_jadwal_yang_sudah_lewat(): void
    {
        $angsuran = $this->jadwal(now()->subDays(68)->toDateString());

        $this->assertSame(68, $angsuran->hitungHariTerlambat());
        $this->assertSame($angsuran->hari_terlambat_dinamis, $angsuran->hitungHariTerlambat());
    }

    public function test_hari_terlambat_nol_untuk_jadwal_yang_belum_jatuh_tempo(): void
    {
        $this->assertSame(0, $this->jadwal(now()->addDays(10)->toDateString())->hitungHariTerlambat());
        $this->assertSame(0, $this->jadwal(now()->toDateString())->hitungHariTerlambat());
    }

    public function test_hari_terlambat_nol_untuk_angsuran_yang_sudah_dibayar(): void
    {
        $angsuran = $this->jadwal(now()->subDays(90)->toDateString(), now()->subDays(80)->toDateString());

        $this->assertSame(0, $angsuran->hitungHariTerlambat());
    }
}
