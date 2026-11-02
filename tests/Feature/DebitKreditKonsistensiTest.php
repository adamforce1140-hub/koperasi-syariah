<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Konsistensi perhitungan & tampilan DEBIT / KREDIT.
 *
 * KONVENSI YANG DIKUNCI TEST INI
 * ------------------------------
 * Mutasi simpanan memakai konvensi posisi: `posisi = 'Debet'` berarti uang
 * MASUK (menambah saldo rekening) dan `posisi = 'Kredit'` berarti uang KELUAR
 * (mengurangi saldo rekening). Ini konsisten dengan:
 *   - TellerController  (Debet → increment saldo, Kredit → decrement saldo)
 *   - TransactionLimitService
 *   - ShuCalculatorService
 *
 * Tampilan yang benar (aturan tanda terpadu, lihat teller/riwayat.blade.php):
 *   - Kolom DEBET  → "+" (hijau) — uang masuk
 *   - Kolom KREDIT → "−" (merah) — uang keluar
 *
 * AKAR MASALAH YANG DIKUNCI
 * --------------------------
 *   1. `Transaksi::isMasuk()` sempat mendahulukan `jenis_transaksi` di atas
 *      `posisi`, sehingga baris ber-posisi eksplisit bisa salah arah.
 *   2. `NasabahController::mutasiIndex()` pernah menukar total masuk/keluar
 *      (menganggap Debet = keluar).
 *   3. Beberapa view tidak menampilkan simbol +/− atau memakai logika
 *      `jenis_transaksi === 'kredit'` yang keliru.
 */
class DebitKreditKonsistensiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('transaksi') || ! Schema::hasTable('rekening')) {
            $this->markTestSkipped('Tabel inti belum tersedia.');
        }
    }

    /* =====================================================================
     | Helper
     ===================================================================== */

    private function pengguna(string $role = 'anggota'): User
    {
        return User::firstOrCreate(
            ['email' => "debitkredit.{$role}.@test.local"],
            [
                'name' => 'DebitKredit '.ucfirst($role),
                'password' => Hash::make('password'),
                'role' => $role,
                'is_active' => true,
            ]
        );
    }

    private function anggota(?User $pengguna = null): Anggota
    {
        $suffix = strtoupper(substr(uniqid(), -6));

        return Anggota::create([
            'user_id' => $pengguna?->id,
            'no_anggota' => 'DK-'.$suffix,
            'nama_lengkap' => 'Anggota DebitKredit '.$suffix,
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji DebitKredit No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_ACTIVE,
            'tanggal_gabung' => now()->toDateString(),
        ]);
    }

    private function akunId(): int
    {
        $id = DB::table('akun')->value('id');
        if ($id) {
            return (int) $id;
        }

        return (int) DB::table('akun')->insertGetId([
            'kode_akun' => '1-1001',
            'nama_akun' => 'Kas Uji DebitKredit',
            'tipe_akun' => 'Aset',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function rekening(Anggota $anggota, float $saldo = 0): Rekening
    {
        return Rekening::create([
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunId(),
            'no_rekening' => 'REK-DK-'.strtoupper(substr(uniqid(), -8)),
            'jenis_rekening' => 'Simpanan Sukarela',
            'status_rekening' => 'Aktif',
            'saldo' => $saldo,
            'tanggal_buka' => now()->toDateString(),
        ]);
    }

    private function transaksi(Anggota $anggota, Rekening $rekening, array $override = []): Transaksi
    {
        return Transaksi::create(array_merge([
            'no_transaksi' => 'TRX-DK-'.strtoupper(substr(uniqid(), -8)),
            'waktu_transaksi' => now(),
            'rekening_id' => $rekening->id,
            'anggota_id' => $anggota->id,
            'jenis_transaksi' => 'Setoran Tunai',
            'posisi' => 'Debet',
            'jumlah' => 100000,
            'saldo_setelah' => 100000,
            'sumber_transaksi' => 'Teller',
            'status_transaksi' => 'Sukses',
        ], $override));
    }

    /* =====================================================================
     | 1. Arah mutasi model — isMasuk() / isKeluar()
     |===================================================================== */

    /** posisi = Debet → uang MASUK. */
    public function test_is_masuk_true_untuk_posisi_debet(): void
    {
        $trx = new Transaksi(['posisi' => 'Debet', 'jenis_transaksi' => 'Setoran Tunai']);

        $this->assertTrue($trx->isMasuk(), 'Debet harus dianggap mutasi masuk.');
        $this->assertFalse($trx->isKeluar(), 'Debet bukan mutasi keluar.');
    }

    /** posisi = Kredit → uang KELUAR. */
    public function test_is_masuk_false_untuk_posisi_kredit(): void
    {
        $trx = new Transaksi(['posisi' => 'Kredit', 'jenis_transaksi' => 'Penarikan Tunai']);

        $this->assertFalse($trx->isMasuk(), 'Kredit harus dianggap mutasi keluar.');
        $this->assertTrue($trx->isKeluar(), 'Kredit adalah mutasi keluar.');
    }

    /**
     * Posisi eksplisit MENANG atas jenis_transaksi — mengunci akar masalah
     * `Pencairan Pembiayaan` (posisi Debet) yang dulu bisa salah arah.
     */
    public function test_posisi_eksplisit_menang_atas_jenis_transaksi(): void
    {
        $debetWalauJenisKeluar = new Transaksi([
            'posisi' => 'Debet',
            'jenis_transaksi' => 'Penarikan Tunai',
        ]);
        $this->assertTrue($debetWalauJenisKeluar->isMasuk());

        $kreditWalauJenisMasuk = new Transaksi([
            'posisi' => 'Kredit',
            'jenis_transaksi' => 'Setoran Tunai',
        ]);
        $this->assertFalse($kreditWalauJenisMasuk->isMasuk());
    }

    /** Tanpa posisi → fallback ke daftar jenis_transaksi masuk. */
    public function test_tanpa_posisi_fallback_ke_jenis_transaksi(): void
    {
        $setoran = new Transaksi(['posisi' => null, 'jenis_transaksi' => 'Setoran Tunai']);
        $this->assertTrue($setoran->isMasuk(), 'Setoran Tunai tanpa posisi dianggap masuk.');

        $penarikan = new Transaksi(['posisi' => null, 'jenis_transaksi' => 'Penarikan Tunai']);
        $this->assertFalse($penarikan->isMasuk(), 'Penarikan Tunai tanpa posisi dianggap keluar.');
    }

    /* =====================================================================
     | 2. Perhitungan total mutasi — NasabahController::mutasiIndex()
     |===================================================================== */

    /** Total "masuk" dihitung dari posisi Debet; "keluar" dari posisi Kredit. */
    public function test_total_masuk_keluar_mengikuti_konvensi_posisi(): void
    {
        $pengguna = $this->pengguna();
        $anggota = $this->anggota($pengguna);
        $rekening = $this->rekening($anggota);

        $this->transaksi($anggota, $rekening, ['posisi' => 'Debet', 'jumlah' => 250000, 'jenis_transaksi' => 'Setoran Tunai']);
        $this->transaksi($anggota, $rekening, ['posisi' => 'Debet', 'jumlah' => 150000, 'jenis_transaksi' => 'Setoran Tunai']);
        $this->transaksi($anggota, $rekening, ['posisi' => 'Kredit', 'jumlah' => 50000, 'jenis_transaksi' => 'Penarikan Tunai']);

        $respons = $this->actingAs($pengguna)->get(route('nasabah.mutasi.index'));
        $respons->assertOk();

        $data = $respons->original->getData();

        $this->assertEquals(400000, (float) $data['totalMasuk'], 'Total masuk = jumlah baris Debet.');
        $this->assertEquals(50000, (float) $data['totalKeluar'], 'Total keluar = jumlah baris Kredit.');
    }

    /* =====================================================================
     | 3. Tampilan simbol pada halaman mutasi nasabah
     |===================================================================== */

    /** Baris Debet menampilkan "+ Rp" dan label "Mutasi Masuk". */
    public function test_view_mutasi_menampilkan_plus_untuk_debet(): void
    {
        $pengguna = $this->pengguna();
        $anggota = $this->anggota($pengguna);
        $rekening = $this->rekening($anggota);

        $this->transaksi($anggota, $rekening, [
            'posisi' => 'Debet',
            'jenis_transaksi' => 'Setoran Tunai',
            'jumlah' => 123456,
            'saldo_setelah' => 123456,
        ]);

        $this->actingAs($pengguna)
            ->get(route('nasabah.mutasi.index'))
            ->assertOk()
            ->assertSee('+ Rp 123.456', false)
            ->assertSee('Debet (Mutasi Masuk)', false);
    }

    /** Baris Kredit menampilkan "- Rp" dan label "Mutasi Keluar". */
    public function test_view_mutasi_menampilkan_minus_untuk_kredit(): void
    {
        $pengguna = $this->pengguna();
        $anggota = $this->anggota($pengguna);
        $rekening = $this->rekening($anggota);

        $this->transaksi($anggota, $rekening, [
            'posisi' => 'Kredit',
            'jenis_transaksi' => 'Penarikan Tunai',
            'jumlah' => 654321,
            'saldo_setelah' => 100000,
        ]);

        $this->actingAs($pengguna)
            ->get(route('nasabah.mutasi.index'))
            ->assertOk()
            ->assertSee('- Rp 654.321', false)
            ->assertSee('Kredit (Mutasi Keluar)', false);
    }

    /* =====================================================================
     | 4. Tampilan simbol pada potongan dashboard real-time
     |===================================================================== */

    /** Potongan dashboard-transaksi memberi "+" untuk Debet dan "−" untuk Kredit. */
    public function test_potongan_dashboard_memberi_tanda_sesuai_posisi(): void
    {
        $pengguna = $this->pengguna();
        $anggota = $this->anggota($pengguna);
        $rekening = $this->rekening($anggota);

        $this->transaksi($anggota, $rekening, [
            'posisi' => 'Debet',
            'jenis_transaksi' => 'Setoran Tunai',
            'jumlah' => 111111,
            'waktu_transaksi' => now(),
        ]);
        $this->transaksi($anggota, $rekening, [
            'posisi' => 'Kredit',
            'jenis_transaksi' => 'Penarikan Tunai',
            'jumlah' => 222222,
            'waktu_transaksi' => now()->subMinute(),
        ]);

        $respons = $this->actingAs($pengguna)->getJson(route('nasabah.dashboard.ringkas'));
        $respons->assertOk();

        $html = $respons->json('html.transaksi');

        $this->assertStringContainsString('+Rp 111.111', $html, 'Baris Debet harus bertanda +.');
        $this->assertStringContainsString('-Rp 222.222', $html, 'Baris Kredit harus bertanda -.');

        // Warna: hijau untuk masuk (#16a34a), merah untuk keluar (#dc2626).
        $this->assertStringContainsString('#16a34a', $html, 'Mutasi masuk harus berwarna hijau.');
        $this->assertStringContainsString('#dc2626', $html, 'Mutasi keluar harus berwarna merah.');
    }
}
