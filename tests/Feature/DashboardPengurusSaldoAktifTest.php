<?php

namespace Tests\Feature;

use App\Models\JenisSimpanan;
use App\Models\Rekening;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Dashboard Pengurus — agregat saldo simpanan hanya rekening AKTIF (X-4).
 *
 * Rekening berstatus `Ditutup` (anggota exit), `Jatuh Tempo`, dan `Dibekukan`
 * tetap tersimpan di tabel `rekening` sebagai jejak audit. Bila ikut
 * dijumlahkan, kartu total simpanan pengurus melebihi kewajiban riil di GL:
 * rekening yang sudah ditutup saldonya nol, tetapi rekening beku/jatuh tempo
 * masih bisa bersaldo dan tidak bisa ditarik anggota.
 *
 * Pengujian memakai selisih (delta) sebelum & sesudah data uji dibuat agar
 * tidak bergantung pada data dev yang tidak boleh dihapus.
 */
class DashboardPengurusSaldoAktifTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    private ?int $anggotaId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'SALDO'.strtoupper(substr(uniqid(), -6));
    }

    private function pengurus(): User
    {
        return User::firstOrCreate(
            ['email' => 'pengurus.saldo@test.local'],
            [
                'name' => 'Pengurus Saldo Uji',
                'password' => Hash::make('password'),
                'role' => 'pengurus',
                'is_active' => true,
            ]
        );
    }

    private function anggotaUji(): int
    {
        if ($this->anggotaId === null) {
            $this->anggotaId = (int) DB::table('anggota')->insertGetId([
                'no_anggota' => 'AGT-'.$this->prefix,
                'nik' => '8888'.random_int(10000000, 99999999),
                'nama_lengkap' => 'Anggota '.$this->prefix,
                'jenis_kelamin' => 'Laki-laki',
                'alamat' => 'Jl. Uji Saldo No. 1',
                'kota' => 'Bandung',
                'provinsi' => 'Jawa Barat',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $this->anggotaId;
    }

    private function rekening(string $suffix, float $saldo, string $status): Rekening
    {
        return Rekening::create([
            'no_rekening' => 'SUK-'.$this->prefix.'-'.$suffix,
            'anggota_id' => $this->anggotaUji(),
            'akun_id' => (int) DB::table('akun')->value('id'),
            'jenis_rekening' => JenisSimpanan::NAMA_SUKARELA,
            'akad' => 'Wadiah',
            'saldo' => $saldo,
            'tanggal_buka' => now()->subMonth()->toDateString(),
            'status_rekening' => $status,
        ]);
    }

    /**
     * Halaman Simpanan Pengurus: kartu total & rekap per jenis hanya
     * menjumlahkan rekening aktif.
     */
    public function test_halaman_simpanan_pengurus_mengabaikan_rekening_non_aktif(): void
    {
        $pengurus = $this->pengurus();

        $sebelum = $this->actingAs($pengurus)->get(route('pengurus.simpanan.index'))->assertOk();
        $totalSebelum = (float) $sebelum->viewData('totalSaldo');
        $rekapSebelum = (float) $sebelum->viewData('counterJenis')
            ->get(JenisSimpanan::NAMA_SUKARELA)?->total;

        $this->rekening('AKTIF', 1_000_000, 'Aktif');
        $this->rekening('TUTUP', 500_000, 'Ditutup');
        $this->rekening('JATUH', 300_000, 'Jatuh Tempo');
        $this->rekening('BEKU', 200_000, 'Dibekukan');

        $sesudah = $this->actingAs($pengurus)->get(route('pengurus.simpanan.index'))->assertOk();

        $this->assertSame(
            1_000_000.0,
            (float) $sesudah->viewData('totalSaldo') - $totalSebelum,
            'Kartu total simpanan hanya boleh bertambah dari rekening aktif.'
        );

        $rekap = $sesudah->viewData('counterJenis')->get(JenisSimpanan::NAMA_SUKARELA);
        $this->assertNotNull($rekap, 'Rekap per jenis harus memuat Simpanan Sukarela.');
        $this->assertSame(
            1_000_000.0,
            (float) $rekap->total - $rekapSebelum,
            'Rekap per jenis hanya menjumlahkan rekening aktif.'
        );
    }

    /**
     * Halaman Laporan Pengurus: "total simpanan" adalah kewajiban berjalan,
     * sehingga rekening non-aktif tidak boleh menambah angkanya.
     */
    public function test_laporan_pengurus_mengabaikan_rekening_non_aktif(): void
    {
        $pengurus = $this->pengurus();

        $sebelum = (float) $this->actingAs($pengurus)
            ->get(route('pengurus.laporan.index'))
            ->assertOk()
            ->viewData('totalSimpanan');

        $this->rekening('AKTIF', 1_000_000, 'Aktif');
        $this->rekening('TUTUP', 500_000, 'Ditutup');
        $this->rekening('BEKU', 200_000, 'Dibekukan');

        $sesudah = (float) $this->actingAs($pengurus)
            ->get(route('pengurus.laporan.index'))
            ->assertOk()
            ->viewData('totalSimpanan');

        $this->assertSame(
            1_000_000.0,
            $sesudah - $sebelum,
            'Total simpanan pada laporan pengurus hanya boleh bertambah dari rekening aktif.'
        );
    }

    /**
     * Halaman Kas Pengurus: angka "saldo rekening" pada kartu tidak boleh
     * memasukkan rekening yang sudah ditutup/dibekukan.
     */
    public function test_kas_pengurus_mengabaikan_rekening_non_aktif(): void
    {
        $pengurus = $this->pengurus();

        $sebelum = (float) $this->actingAs($pengurus)
            ->get(route('pengurus.kas.index'))
            ->assertOk()
            ->viewData('totalRekening');

        $this->rekening('AKTIF', 1_000_000, 'Aktif');
        $this->rekening('TUTUP', 500_000, 'Ditutup');
        $this->rekening('DIBEKUKAN', 200_000, 'Dibekukan');

        $sesudah = (float) $this->actingAs($pengurus)
            ->get(route('pengurus.kas.index'))
            ->assertOk()
            ->viewData('totalRekening');

        $this->assertSame(
            1_000_000.0,
            $sesudah - $sebelum,
            'Saldo rekening pada halaman kas pengurus hanya boleh berasal dari rekening aktif.'
        );
    }
}
