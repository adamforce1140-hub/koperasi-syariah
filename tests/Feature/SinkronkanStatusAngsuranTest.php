<?php

namespace Tests\Feature;

use App\Models\Pembiayaan;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * REGRESI #32 — command `angsuran:sinkronkan-status`.
 *
 * Kolom `angsuran.status_angsuran` DISIMPAN (bukan dihitung saat query) karena
 * dipakai sebagai filter SQL oleh teller, portal nasabah, CKPN, restrukturisasi,
 * dan autodebet. Tanpa penyelarasan, nilainya membeku pada saat jadwal dibuat:
 * angsuran yang jatuh tempo bulan lalu tetap tertulis 'Jatuh Tempo', sehingga
 * daftar tunggakan dan NPF tidak akurat.
 *
 * Yang dikunci di sini:
 *   1. Status diturunkan dari tanggal_bayar + tanggal_jatuh_tempo.
 *   2. 'Dibayar Sebagian' tidak pernah ditimpa (pokok belum tertagih penuh).
 *   3. `--dry-run` tidak menyimpan apa pun.
 *   4. Idempoten: jalan kedua tidak mengubah apa pun.
 */
class SinkronkanStatusAngsuranTest extends TestCase
{
    use DatabaseTransactions;

    /** Tanggal acuan command — dipatok agar hasil tidak bergantung waktu uji. */
    private const ACUAN = '2024-06-30';

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'SINKRON'.strtoupper(substr(uniqid(), -6));
    }

    /**
     * Buat pembiayaan + satu baris angsuran dengan status awal tertentu.
     *
     * @return int id angsuran
     */
    private function angsuran(
        string $label,
        ?string $jatuhTempo,
        ?string $statusAwal,
        ?string $tanggalBayar = null
    ): int {
        $anggotaId = DB::table('anggota')->insertGetId([
            'no_anggota' => $this->prefix.'-'.strtoupper(substr(uniqid(), -6)),
            'nik' => 'NIK'.strtoupper(substr(uniqid(), -10)),
            'nama_lengkap' => $this->prefix.' '.$label,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jalan Uji No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => 'Aktif',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => $this->prefix.'-'.strtoupper(substr(uniqid(), -8)),
            'anggota_id' => $anggotaId,
            'akun_id' => DB::table('akun')->value('id'),
            'akad_pembiayaan' => 'Murabahah',
            'jumlah_pembiayaan' => 1200000,
            'total_pembiayaan' => 1320000,
            'tenor' => 12,
            'jumlah_angsuran' => 110000,
            'tanggal_akad' => '2024-01-15',
            'tanggal_jatuh_tempo' => '2025-01-15',
            'sisa_pembiayaan' => 1200000,
            'status_pembiayaan' => Pembiayaan::STATUS_AKTIF,
        ]);

        return DB::table('angsuran')->insertGetId([
            'pembiayaan_id' => $pembiayaan->id,
            'angsuran_ke' => 1,
            'tanggal_jatuh_tempo' => $jatuhTempo,
            'tanggal_bayar' => $tanggalBayar,
            'pokok_angsuran' => 100000,
            'margin_ujroh' => 10000,
            'jumlah_angsuran' => 110000,
            'total_bayar' => $tanggalBayar ? 110000 : 0,
            'sisa_pokok' => 100000,
            'status_angsuran' => $statusAwal,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function statusDb(int $id): ?string
    {
        return DB::table('angsuran')->where('id', $id)->value('status_angsuran');
    }

    /* ============================================================
     * 1. Turunan status dari tanggal
     * ============================================================ */

    public function test_status_diturunkan_dari_tanggal_bayar_dan_jatuh_tempo(): void
    {
        $lewat = $this->angsuran('LEWAT', '2024-05-10', 'Jatuh Tempo');
        $hariIni = $this->angsuran('HARIINI', self::ACUAN, 'Belum Jatuh Tempo');
        $masaDepan = $this->angsuran('DEPAN', '2024-08-10', 'Jatuh Tempo');
        $sudahDibayar = $this->angsuran('DIBAYAR', '2024-05-10', 'Terlambat', '2024-05-08');

        $this->artisan('angsuran:sinkronkan-status', ['--date' => self::ACUAN])
            ->assertExitCode(0);

        $this->assertSame('Terlambat', $this->statusDb($lewat), 'JT sudah lewat → Terlambat.');
        $this->assertSame('Jatuh Tempo', $this->statusDb($hariIni), 'JT hari ini → Jatuh Tempo.');
        $this->assertSame('Belum Jatuh Tempo', $this->statusDb($masaDepan), 'JT masih depan → Belum Jatuh Tempo.');
        $this->assertSame('Sudah Dibayar', $this->statusDb($sudahDibayar), 'tanggal_bayar terisi → Sudah Dibayar.');
    }

    /* ============================================================
     * 2. Baris yang TIDAK boleh disentuh
     * ============================================================ */

    public function test_dibayar_sebagian_tidak_ditimpa(): void
    {
        // Pokok belum tertagih penuh — status harus tetap, apa pun tanggalnya.
        $sebagian = $this->angsuran('SEBAGIAN', '2024-01-05', 'Dibayar Sebagian', '2024-01-10');

        $this->artisan('angsuran:sinkronkan-status', ['--date' => self::ACUAN])
            ->assertExitCode(0);

        $this->assertSame('Dibayar Sebagian', $this->statusDb($sebagian));
    }

    /* ============================================================
     * 3. Dry-run & idempotensi
     * ============================================================ */

    public function test_dry_run_tidak_menyimpan_perubahan(): void
    {
        $basi = $this->angsuran('BASIDRY', '2024-05-10', 'Belum Jatuh Tempo');

        $this->artisan('angsuran:sinkronkan-status', [
            '--date' => self::ACUAN,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertSame('Belum Jatuh Tempo', $this->statusDb($basi), 'Dry-run tidak boleh menulis.');
    }

    public function test_command_idempoten(): void
    {
        $basi = $this->angsuran('BASIDUA', '2024-05-10', 'Belum Jatuh Tempo');

        $this->artisan('angsuran:sinkronkan-status', ['--date' => self::ACUAN])->assertExitCode(0);
        $this->assertSame('Terlambat', $this->statusDb($basi));

        $sebelum = DB::table('angsuran')->where('id', $basi)->first();

        $this->artisan('angsuran:sinkronkan-status', ['--date' => self::ACUAN])->assertExitCode(0);
        $sesudah = DB::table('angsuran')->where('id', $basi)->first();

        $this->assertSame($sebelum->status_angsuran, $sesudah->status_angsuran);
        $this->assertEquals($sebelum->updated_at, $sesudah->updated_at, 'Baris yang sudah sesuai tidak ditulis ulang.');
    }
}
