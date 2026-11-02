<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\Pembiayaan;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * REGRESI #29 — jadwal angsuran yang dibuat `DemoDataSeeder`.
 *
 * Seeder lama mengisi `total_bayar` untuk SEMUA baris jadwal, termasuk yang
 * belum dibayar. Akibatnya portal nasabah menampilkan angsuran sebagai sudah
 * lunas (nominal tagihan diambil dari `total_bayar`) dan
 * `AngsuranController::terhubungTransaksi()` menganggap baris itu sudah
 * terhubung transaksi, sehingga tidak bisa dibayar/diubah.
 *
 * Yang dikunci di sini:
 *   1. `total_bayar` = 0 untuk setiap baris yang belum dibayar.
 *   2. Hanya angsuran ke-1 yang dibayar (oleh LANGKAH 11 `bayarAngsuran()`).
 *   3. `status_angsuran` diturunkan dari `tanggal_jatuh_tempo`, bukan dari
 *      indeks tenor — harus sama dengan `angsuran:sinkronkan-status`.
 *
 * Seeder dijalankan lewat refleksi (bukan `run()`) karena `run()` idempoten:
 * pada DB yang sudah berisi data demo ia langsung keluar. DatabaseTransactions
 * memastikan tidak ada data yang tertinggal.
 */
class DemoDataSeederJadwalAngsuranTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'SEED'.strtoupper(substr(uniqid(), -6));
    }

    private function jadwalDemo(): Collection
    {
        $anggotaId = DB::table('anggota')->insertGetId([
            'no_anggota' => $this->prefix.'-ANG',
            'nik' => 'NIK'.strtoupper(substr(uniqid(), -10)),
            'nama_lengkap' => $this->prefix.' Anggota',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jalan Uji No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => 'Aktif',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => $this->prefix.'-PMB',
            'anggota_id' => $anggotaId,
            'akun_id' => DB::table('akun')->value('id'),
            'akad_pembiayaan' => 'Murabahah',
            'jumlah_pembiayaan' => 12000000,
            'total_pembiayaan' => 13200000,
            'tenor' => 12,
            'jumlah_angsuran' => 1100000,
            'tanggal_akad' => now()->subMonths(5)->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths(7)->toDateString(),
            'sisa_pembiayaan' => 12000000,
            'status_pembiayaan' => Pembiayaan::STATUS_AKTIF,
        ]);

        $seeder = new DemoDataSeeder;

        // `$this->teller` dipakai untuk `user_id` angsuran ke-1.
        $teller = new ReflectionProperty(DemoDataSeeder::class, 'teller');
        $teller->setAccessible(true);
        $teller->setValue($seeder, User::firstOrCreate(
            ['email' => 'teller.seed.uji@test.local'],
            [
                'name' => 'Teller Seed Uji',
                'password' => Hash::make('password'),
                'role' => 'teller',
                'is_active' => true,
            ]
        ));

        $metode = new ReflectionMethod(DemoDataSeeder::class, 'buatJadwalAngsuran');
        $metode->setAccessible(true);
        $metode->invoke(
            $seeder,
            $pembiayaan,
            ['jumlah' => 12000000, 'tenor' => 12, 'margin' => 10],
            Anggota::find($anggotaId)
        );

        return Angsuran::where('pembiayaan_id', $pembiayaan->id)->orderBy('angsuran_ke')->get();
    }

    public function test_jadwal_terbentuk_selengkap_tenor(): void
    {
        $jadwal = $this->jadwalDemo();

        $this->assertCount(12, $jadwal);
        $this->assertSame(range(1, 12), $jadwal->pluck('angsuran_ke')->all());
    }

    public function test_total_bayar_hanya_untuk_angsuran_yang_dibayar(): void
    {
        $jadwal = $this->jadwalDemo();

        $belumDibayar = $jadwal->filter(fn ($a) => $a->tanggal_bayar === null);

        $this->assertCount(11, $belumDibayar, 'Hanya angsuran ke-1 yang dibayar seeder.');
        $this->assertSame(
            0.0,
            (float) $belumDibayar->sum('total_bayar'),
            'Angsuran yang belum dibayar tidak boleh punya total_bayar > 0.'
        );
    }

    public function test_angsuran_pertama_dibayar_tanpa_dianggap_lunas_oleh_total_bayar(): void
    {
        $pertama = $this->jadwalDemo()->first();

        $this->assertSame('Sudah Dibayar', $pertama->status_angsuran);
        $this->assertNotNull($pertama->tanggal_bayar, 'tanggal_bayar wajib terisi.');
        $this->assertSame(
            Carbon::parse($pertama->tanggal_jatuh_tempo)->toDateString(),
            Carbon::parse($pertama->tanggal_bayar)->toDateString(),
            'Dibayar tepat pada tanggal jatuh tempo — bukan sebelum/sesudahnya.'
        );
        // Nilai awal 0; nominal diisi LANGKAH 11 (`bayarAngsuran()`).
        $this->assertSame(0.0, (float) $pertama->total_bayar);
    }

    public function test_status_sejalan_dengan_tanggal_jatuh_tempo(): void
    {
        $hariIni = now()->startOfDay();

        foreach ($this->jadwalDemo() as $angsuran) {
            if ($angsuran->tanggal_bayar !== null) {
                $this->assertSame('Sudah Dibayar', $angsuran->status_angsuran);

                continue;
            }

            $jt = Carbon::parse($angsuran->tanggal_jatuh_tempo)->startOfDay();
            $harapan = $jt->lt($hariIni)
                ? 'Terlambat'
                : ($jt->equalTo($hariIni) ? 'Jatuh Tempo' : 'Belum Jatuh Tempo');

            $this->assertSame(
                $harapan,
                $angsuran->status_angsuran,
                "Angsuran ke-{$angsuran->angsuran_ke} (JT {$jt->toDateString()}) salah status."
            );
        }
    }
}
