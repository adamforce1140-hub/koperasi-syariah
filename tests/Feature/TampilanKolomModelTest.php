<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\Pembiayaan;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regresi "kolom fiktif" pada view.
 *
 * Latar belakang: beberapa Blade memakai nama atribut yang TIDAK ADA pada
 * tabel/model sehingga halaman tetap tampil tanpa error tetapi menampilkan
 * data kosong atau angka nol yang menyesatkan:
 *
 *   - nasabah/profil  : `tanggal_masuk`, `status`, `jenis_anggota`,
 *                       `no_ktp`, `cabang`  → semua tidak ada.
 *   - nasabah/rekening: `jenis_tabungan` → kolomnya `jenis_rekening`.
 *   - teller/slip     : `pokok`, `margin`, `nisbah` pada angsuran
 *                       → kolomnya `pokok_angsuran`, `margin_ujroh`
 *                         (tidak ada padanan `nisbah` di tabel `angsuran`).
 *   - nasabah/mutasi  : `saldo_setelah` NULL ditampilkan "Rp 0".
 *
 * Test ini mengunci hasil render yang benar agar tidak kembali kosong.
 */
class TampilanKolomModelTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('anggota') || ! Schema::hasTable('rekening')) {
            $this->markTestSkipped('Tabel inti belum tersedia.');
        }
    }

    /* =====================================================================
     | Helper
     ===================================================================== */

    private function pengguna(string $kunci, string $role): User
    {
        return User::firstOrCreate(
            ['email' => "tampilankolom.{$kunci}@test.local"],
            [
                'name' => 'TampilanKolom '.ucfirst($kunci),
                'password' => Hash::make('password'),
                'role' => $role,
                'is_active' => true,
            ]
        );
    }

    private function anggota(?User $pengguna = null, array $override = []): Anggota
    {
        $suffix = strtoupper(substr(uniqid(), -6));

        return Anggota::create(array_merge([
            'user_id' => $pengguna?->id,
            'no_anggota' => 'TK-'.$suffix,
            'nama_lengkap' => 'Anggota TampilanKolom '.$suffix,
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji TampilanKolom No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_ACTIVE,
            'tanggal_gabung' => now()->toDateString(),
        ], $override));
    }

    private function akunId(): int
    {
        $id = DB::table('akun')->value('id');
        if ($id) {
            return (int) $id;
        }

        return (int) DB::table('akun')->insertGetId([
            'kode_akun' => '1-1001',
            'nama_akun' => 'Kas Uji TampilanKolom',
            'tipe_akun' => 'Aset',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function rekening(Anggota $anggota, string $jenis = 'Simpanan Sukarela', float $saldo = 0): Rekening
    {
        return Rekening::create([
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunId(),
            'no_rekening' => 'REK-TK-'.strtoupper(substr(uniqid(), -8)),
            'jenis_rekening' => $jenis,
            'status_rekening' => 'Aktif',
            'saldo' => $saldo,
            'tanggal_buka' => now()->toDateString(),
        ]);
    }

    /* =====================================================================
     | 1. Profil & Kartu Anggota
     ===================================================================== */

    public function test_profil_menampilkan_tanggal_gabung_status_dan_nik_yang_benar(): void
    {
        $pengguna = $this->pengguna('profil', 'anggota');
        $anggota = $this->anggota($pengguna, [
            'nik' => '3201234567890001',
            'tanggal_gabung' => '2024-03-05',
            'status_anggota' => Anggota::STATUS_ACTIVE,
            'kota' => 'Bandung',
        ]);
        $this->rekening($anggota, 'Simpanan Wajib', 250000);

        $respons = $this->actingAs($pengguna)->get(route('nasabah.profil.index'));

        $respons->assertOk();

        // `tanggal_gabung` harus ter-render sebagai tanggal, bukan "-".
        $respons->assertSee('05 Mar 2024');

        // `status_label` menormalkan enum ACTIVE → "Aktif".
        $respons->assertSee('Aktif');

        // NIK kanonik (sebelumnya `no_ktp` yang tidak ada → kosong).
        $respons->assertSee('3201234567890001');

        // Kota kanonik (sebelumnya `cabang` yang tidak ada → kosong).
        $respons->assertSee('Bandung');

        // Tidak boleh menampilkan label lama yang berasal dari kolom fiktif.
        $respons->assertDontSee('Jenis Anggota');
        $respons->assertDontSee('Cabang');
    }

    /* =====================================================================
     | 2. Rekening anggota
     ===================================================================== */

    public function test_rekening_menampilkan_jenis_rekening_bukan_kolom_kosong(): void
    {
        $pengguna = $this->pengguna('rekening', 'anggota');
        $anggota = $this->anggota($pengguna);
        $rekening = $this->rekening($anggota, 'Simpanan Berjangka', 1250000);

        $respons = $this->actingAs($pengguna)->get(route('nasabah.rekening.index'));

        $respons->assertOk();
        $respons->assertSee($rekening->no_rekening);
        $respons->assertSee('Simpanan Berjangka');
    }

    /* =====================================================================
     | 3. Slip / struk teller — rincian angsuran
     ===================================================================== */

    public function test_slip_teller_menampilkan_pokok_dan_margin_dari_kolom_kanonik(): void
    {
        if (! Schema::hasTable('angsuran') || ! Schema::hasTable('pembiayaan')) {
            $this->markTestSkipped('Tabel pembiayaan/angsuran belum tersedia.');
        }

        $teller = $this->pengguna('teller', 'teller');
        $pengguna = $this->pengguna('pemilik-slip', 'anggota');
        $anggota = $this->anggota($pengguna);
        $rekening = $this->rekening($anggota, 'Simpanan Sukarela', 500000);

        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => 'PEM-TK-'.strtoupper(substr(uniqid(), -8)),
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunId(),
            'akad_pembiayaan' => 'Murabahah',
            'tujuan_pembiayaan' => 'Modal Usaha',
            'jumlah_pembiayaan' => 10000000,
            'total_pembiayaan' => 11000000,
            'tenor' => 10,
            'jumlah_angsuran' => 1100000,
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths(10)->toDateString(),
            'sisa_pembiayaan' => 9000000,
            'status_pembiayaan' => Pembiayaan::STATUS_AKTIF,
        ]);

        $transaksi = Transaksi::create([
            'no_transaksi' => 'TRX-TK-'.strtoupper(substr(uniqid(), -8)),
            'waktu_transaksi' => now(),
            'rekening_id' => $rekening->id,
            'anggota_id' => $anggota->id,
            'jenis_transaksi' => 'Angsuran Murabahah',
            'posisi' => 'Debet',
            'jumlah' => 1100000,
            'saldo_setelah' => 500000,
            'sumber_transaksi' => 'Teller',
            'status_transaksi' => 'Sukses',
            'dibuat_oleh' => $teller->id,
        ]);

        Angsuran::create([
            'pembiayaan_id' => $pembiayaan->id,
            'transaksi_id' => $transaksi->id,
            'angsuran_ke' => 1,
            'tanggal_jatuh_tempo' => now()->toDateString(),
            'tanggal_bayar' => now()->toDateString(),
            'pokok_angsuran' => 1000000,
            'margin_ujroh' => 100000,
            'jumlah_angsuran' => 1100000,
            'denda' => 0,
            'total_bayar' => 1100000,
            'sisa_pokok' => 9000000,
            'status_angsuran' => 'Sudah Dibayar',
        ]);

        $respons = $this->actingAs($teller)->get(route('teller.slip', $transaksi));

        $respons->assertOk();
        $respons->assertSee('Rincian Angsuran');

        // Sebelumnya selalu "Pokok Rp 0" karena memakai kolom `pokok` yang tidak ada.
        $respons->assertSee('Rp 1.000.000');
        $respons->assertSee('Margin (Murabahah)');
        $respons->assertSee('Rp 100.000');

        // Baris nisbah dihapus: tabel `angsuran` tidak punya kolom nisbah.
        $respons->assertDontSee('Nisbah Bagi Hasil');
    }

    /* =====================================================================
     | 4. Mutasi — saldo_setelah NULL
     ===================================================================== */

    public function test_mutasi_menampilkan_dash_ketika_saldo_setelah_null(): void
    {
        $pengguna = $this->pengguna('mutasi', 'anggota');
        $anggota = $this->anggota($pengguna);
        $rekening = $this->rekening($anggota, 'Simpanan Sukarela', 100000);

        Transaksi::create([
            'no_transaksi' => 'TRX-TKM-'.strtoupper(substr(uniqid(), -8)),
            'waktu_transaksi' => now(),
            'rekening_id' => $rekening->id,
            'anggota_id' => $anggota->id,
            'jenis_transaksi' => 'Setoran Tunai',
            'posisi' => 'Debet',
            'jumlah' => 100000,
            'saldo_setelah' => null,
            'sumber_transaksi' => 'Teller',
            'status_transaksi' => 'Sukses',
        ]);

        $respons = $this->actingAs($pengguna)->get(route('nasabah.mutasi.index'));

        $respons->assertOk();

        // Saldo yang tidak tercatat dirender sebagai tanda pisah, bukan "Rp 0".
        $respons->assertSee('<span style="color: #cbd5e1;">—</span>', false);
    }
}
