<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Coa;
use App\Models\User;
use App\Models\ZakatTransaksi;
use App\Services\ZakatService;
use App\Support\Coa as CoaKode;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * R-08 (Z-07) — Relasi OPSIONAL pihak penerima/muzakki ke master anggota.
 *
 * Sebelum perbaikan, kolom `pihak` hanya string bebas tanpa tautan terstruktur
 * ke master anggota. Test ini mengunci:
 *  1. Form penerimaan & penyaluran menyediakan dropdown `pihak_id` (opsional)
 *     serta tetap menyediakan input teks `pihak` sebagai fallback.
 *  2. Penerimaan/penyaluran dengan `pihak_id` menyimpan relasi ke master anggota.
 *  3. Bila `pihak` kosong tetapi `pihak_id` diisi, nama diambil dari master.
 *  4. `pihak_id` harus menunjuk anggota yang ada (fail-closed bila tidak dikenal).
 *  5. Kompatibilitas mundur: tanpa `pihak_id`, teks `pihak` tetap tersimpan.
 *  6. Label tampilan `pihak_tampil` aman & menandai keterkaitan anggota.
 */
class ZakatPihakRelasiTest extends TestCase
{
    use DatabaseTransactions;

    private ZakatService $zakat;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zakat = app(ZakatService::class);
        $this->prefix = 'ZKTPHK'.strtoupper(substr(uniqid(), -6));

        if (! Coa::findByKode(CoaKode::DANA_ZAKAT) || ! Coa::findByKode(CoaKode::KAS_TELLER)) {
            $this->markTestSkipped('CoA dana zakat / kas teller belum ter-seed.');
        }
    }

    protected function tearDown(): void
    {
        Setting::flush();

        parent::tearDown();
    }

    /* ─────────────────────────── HELPER ─────────────────────────── */

    private function pengelola(): User
    {
        return User::firstOrCreate(
            ['email' => 'pengurus.zakat.pihak@test.local'],
            [
                'name' => 'Pengelola Zakat Pihak Uji',
                'password' => Hash::make('password'),
                'role' => 'pengurus',
            ]
        );
    }

    /**
     * Buat anggota uji — tidak bergantung pada factory.
     *
     * Kolom wajib skema `anggota` (nik, jenis_kelamin, alamat, kota, provinsi)
     * ikut diisi agar konsisten dengan batasan database.
     */
    private function anggota(string $nama = 'Anggota Uji Zakat'): Anggota
    {
        $unik = strtoupper(substr(uniqid(), -8));

        return Anggota::create([
            'no_anggota' => 'AGT'.$unik,
            'nik' => (string) random_int(10_000_000_000_000, 99_999_999_999_999),
            'nama_lengkap' => $this->prefix.' '.$nama,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji Zakat No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_ACTIVE,
            'tanggal_gabung' => now()->toDateString(),
        ]);
    }

    /* ─────────────── 1. DROPDOWN PIHAK TERSEDIA ─────────────── */

    public function test_form_menyediakan_dropdown_pihak_id_dan_input_teks_pihak(): void
    {
        $html = $this->actingAs($this->pengelola())
            ->get(route('zakat.index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            2,
            substr_count($html, 'name="pihak_id"'),
            'Dropdown pihak_id wajib ada pada form penerimaan DAN penyaluran.'
        );
        // Input teks `pihak` tetap tersedia sebagai fallback pihak eksternal.
        $this->assertSame(
            2,
            substr_count($html, 'name="pihak"'),
            'Input teks pihak wajib tetap ada pada kedua form (fallback).'
        );
    }

    /* ─────────────── 2. PENERIMAAN DENGAN RELASI ANGGOTA ─────────────── */

    public function test_penerimaan_dengan_pihak_id_menyimpan_relasi_anggota(): void
    {
        $anggota = $this->anggota('Muzakki Anggota');

        $this->actingAs($this->pengelola())
            ->post(route('zakat.store'), [
                'dana' => 'zakat',
                'kategori' => 'zakat_maal',
                'pihak' => $this->prefix.' Muzakki Anggota',
                'pihak_id' => $anggota->id,
                'jumlah' => 400000.00,
                'tanggal' => now()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $trx = ZakatTransaksi::where('pihak', $this->prefix.' Muzakki Anggota')->first();

        $this->assertNotNull($trx, 'Transaksi penerimaan wajib tercatat.');
        $this->assertSame($anggota->id, (int) $trx->pihak_id);
        $this->assertTrue($trx->tertaut_anggota);
        $this->assertSame($anggota->id, (int) $trx->pihakRelasi->id);
    }

    /* ─────────────── 3. NAMA DIAMBIL DARI MASTER BILA TEKS KOSONG ─────────────── */

    public function test_pihak_teks_kosong_diambil_dari_master_anggota(): void
    {
        $anggota = $this->anggota('Mustahik Anggota');

        $this->zakat->salurkan([
            'dana' => 'infak_sedekah',
            'kategori' => 'kegiatan_sosial',
            'pihak' => '', // sengaja kosong → harus diisi dari master
            'pihak_id' => $anggota->id,
            'jumlah' => 50000.00,
        ], $this->pengelola()->id);

        $trx = ZakatTransaksi::where('pihak_id', $anggota->id)->first();

        $this->assertNotNull($trx, 'Penyaluran dengan pihak_id wajib tercatat.');
        $this->assertSame($anggota->nama_lengkap, $trx->pihak, 'Nama pihak wajib diambil dari master anggota.');
    }

    /* ─────────────── 4. VALIDASI: pihak_id TIDAK DIKENAL ─────────────── */

    public function test_validasi_menolak_pihak_id_tidak_dikenal(): void
    {
        $this->actingAs($this->pengelola())
            ->post(route('zakat.store'), [
                'dana' => 'zakat',
                'kategori' => 'zakat_maal',
                'pihak' => $this->prefix.' Pihak Fiktif',
                'pihak_id' => 99999999,
                'jumlah' => 100000.00,
                'tanggal' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('pihak_id');

        $this->assertNull(
            ZakatTransaksi::where('pihak', $this->prefix.' Pihak Fiktif')->first(),
            'pihak_id tak dikenal harus ditolak — transaksi tidak boleh tercatat.'
        );
    }

    /* ─────────────── 5. KOMPATIBILITAS MUNDUR: TANPA pihak_id ─────────────── */

    public function test_tanpa_pihak_id_teks_pihak_tetap_tersimpan(): void
    {
        $this->actingAs($this->pengelola())
            ->post(route('zakat.store'), [
                'dana' => 'zakat',
                'kategori' => 'zakat_fitrah',
                'pihak' => $this->prefix.' Muzakki Eksternal',
                'jumlah' => 250000.00,
                'tanggal' => now()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $trx = ZakatTransaksi::where('pihak', $this->prefix.' Muzakki Eksternal')->first();

        $this->assertNotNull($trx);
        $this->assertNull($trx->pihak_id, 'Tanpa pihak_id, relasi anggota harus NULL (pihak eksternal).');
        $this->assertFalse($trx->tertaut_anggota);
        $this->assertSame($this->prefix.' Muzakki Eksternal', $trx->pihak_tampil);
    }

    /* ─────────────── 6. LABEL TAMPILAN AMAN ─────────────── */

    public function test_pihak_tampil_aman_terhadap_anggota_terhapus(): void
    {
        $anggota = $this->anggota('Anggota Terhapus');
        $id = $anggota->id;

        $this->zakat->terima([
            'dana' => 'zakat',
            'kategori' => 'zakat_maal',
            'pihak' => $this->prefix.' Snapshot Nama',
            'pihak_id' => $id,
            'jumlah' => 120000.00,
        ], $this->pengelola()->id);

        // Soft delete anggota — snapshot teks `pihak` tetap menjadi label aman.
        $anggota->delete();

        $trx = ZakatTransaksi::where('pihak', $this->prefix.' Snapshot Nama')->first();

        $this->assertNotNull($trx);
        $this->assertSame($this->prefix.' Snapshot Nama', $trx->pihak_tampil);
        $this->assertTrue($trx->tertaut_anggota);
    }
}
