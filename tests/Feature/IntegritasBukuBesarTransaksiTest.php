<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Jurnal;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * LANGKAH 4 — BATCH 3: INTEGRITAS BUKU BESAR PADA API TRANSAKSI.
 *
 * `DELETE /api/transaksi/{id}` dan `PUT /api/transaksi/{id}` adalah CRUD
 * generik yang terbuka untuk peran teller. Keduanya tidak memeriksa apakah
 * transaksi sudah diposting ke jurnal. Padahal alur yang benar untuk
 * membatalkan transaksi adalah `AjukanVoidAction`: status menjadi
 * "Pending Void" lalu menunggu otorisasi manajer, dan jurnal dibalik —
 * bukan dihapus.
 *
 * Tanpa penjagaan ini, satu panggilan DELETE menghapus bukti transaksi
 * sementara jurnalnya tetap terposting: buku besar menjadi tidak seimbang
 * dan jejak audit (PSAK 101) hilang. Batch ini mengunci aturan:
 * transaksi yang sudah terjurnal bersifat IMMUTABLE lewat CRUD generik.
 */
class IntegritasBukuBesarTransaksiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('transaksi') || ! Schema::hasTable('jurnal')) {
            $this->markTestSkipped('Tabel transaksi/jurnal belum tersedia.');
        }
    }

    /* =====================================================================
     | Helper
     ===================================================================== */

    private function pengguna(string $kunci, string $role = 'teller'): User
    {
        return User::firstOrCreate(
            ['email' => "buku.besar.{$kunci}@test.local"],
            [
                'name' => 'Uji Buku Besar '.ucfirst($kunci),
                'password' => Hash::make('password'),
                'role' => $role,
                'is_active' => true,
            ]
        );
    }

    private function anggota(User $pengguna): Anggota
    {
        $suffix = strtoupper(substr(uniqid(), -6));

        return Anggota::create([
            'user_id' => $pengguna->id,
            'no_anggota' => 'BB-'.$suffix,
            'nama_lengkap' => 'Anggota Buku Besar '.$suffix,
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji Buku Besar No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_ACTIVE,
            'tanggal_gabung' => now()->toDateString(),
        ]);
    }

    private function rekening(Anggota $anggota): Rekening
    {
        $akunId = DB::table('akun')->value('id');

        if (! $akunId) {
            $akunId = DB::table('akun')->insertGetId([
                'kode_akun' => '1-1001',
                'nama_akun' => 'Kas Uji Buku Besar',
                'tipe_akun' => 'Aset',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return Rekening::create([
            'anggota_id' => $anggota->id,
            'akun_id' => (int) $akunId,
            'no_rekening' => 'REK-BB-'.strtoupper(substr(uniqid(), -8)),
            'jenis_rekening' => 'Simpanan Sukarela',
            'status_rekening' => 'Aktif',
            'saldo' => 1000000,
            'tanggal_buka' => now()->toDateString(),
        ]);
    }

    private function transaksi(Rekening $rekening, User $pengguna, array $override = []): Transaksi
    {
        return Transaksi::create(array_merge([
            'no_transaksi' => 'TRX-BB-'.strtoupper(substr(uniqid(), -8)),
            'waktu_transaksi' => now(),
            'rekening_id' => $rekening->id,
            'anggota_id' => $rekening->anggota_id,
            'jenis_transaksi' => 'Setoran Tunai',
            'posisi' => 'Debet',
            'jumlah' => 250000,
            'saldo_setelah' => 1250000,
            'user_id' => $pengguna->id,
            'sumber_transaksi' => 'Teller',
            'status_transaksi' => Transaksi::STATUS_TRANSAKSI_SUKSES,
        ], $override));
    }

    /** Jurnal Posted yang menautkan transaksi — bukti transaksi sudah masuk buku besar. */
    private function jurnalPosted(Transaksi $transaksi, User $pengguna): Jurnal
    {
        return Jurnal::create([
            'no_jurnal' => 'JU-BB-'.strtoupper(substr(uniqid(), -8)),
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Transaksi',
            'transaksi_id' => $transaksi->id,
            'sumber' => 'Uji Buku Besar',
            'keterangan' => 'Jurnal uji integritas buku besar',
            'total_debet' => 250000,
            'total_kredit' => 250000,
            'is_balance' => true,
            'status' => 'Posted',
            'posted_at' => now(),
            'user_posted' => $pengguna->id,
            'user_id' => $pengguna->id,
        ]);
    }

    /** Payload PUT lengkap (semua kolom wajib) dengan nominal berbeda. */
    private function payloadUbah(Transaksi $transaksi, float $jumlah): array
    {
        return [
            'no_transaksi' => $transaksi->no_transaksi,
            'waktu_transaksi' => $transaksi->waktu_transaksi->toDateTimeString(),
            'rekening_id' => $transaksi->rekening_id,
            'anggota_id' => $transaksi->anggota_id,
            'jenis_transaksi' => $transaksi->jenis_transaksi,
            'posisi' => $transaksi->posisi,
            'jumlah' => $jumlah,
        ];
    }

    /* =====================================================================
     | 1. Penghapusan transaksi terjurnal
     ===================================================================== */

    public function test_transaksi_terjurnal_tidak_dapat_dihapus(): void
    {
        $teller = $this->pengguna('teller.hapus');
        $transaksi = $this->transaksi($this->rekening($this->anggota($teller)), $teller);
        $this->jurnalPosted($transaksi, $teller);

        $this->actingAs($teller)
            ->deleteJson("/api/transaksi/{$transaksi->id}")
            ->assertStatus(422);

        $this->assertNotNull(
            Transaksi::withTrashed()->find($transaksi->id),
            'Transaksi terjurnal tidak boleh hilang dari basis data.'
        );
        $this->assertNull(
            Transaksi::withTrashed()->find($transaksi->id)->deleted_at,
            'Transaksi terjurnal tidak boleh di-soft-delete.'
        );
    }

    public function test_jurnal_tetap_utuh_saat_penghapusan_ditolak(): void
    {
        $teller = $this->pengguna('teller.jurnal');
        $transaksi = $this->transaksi($this->rekening($this->anggota($teller)), $teller);
        $jurnal = $this->jurnalPosted($transaksi, $teller);

        $this->actingAs($teller)->deleteJson("/api/transaksi/{$transaksi->id}");

        $this->assertNotNull(Jurnal::find($jurnal->id), 'Jurnal Posted tidak boleh ikut terhapus.');
        $this->assertSame('Posted', $jurnal->fresh()->status);
    }

    /* =====================================================================
     | 2. Perubahan transaksi terjurnal
     ===================================================================== */

    public function test_transaksi_terjurnal_tidak_dapat_diubah_nominalnya(): void
    {
        $teller = $this->pengguna('teller.ubah');
        $transaksi = $this->transaksi($this->rekening($this->anggota($teller)), $teller);
        $this->jurnalPosted($transaksi, $teller);

        $this->actingAs($teller)
            ->putJson("/api/transaksi/{$transaksi->id}", $this->payloadUbah($transaksi, 999999))
            ->assertStatus(422);

        $this->assertSame('250000.00', (string) $transaksi->fresh()->jumlah,
            'Nominal transaksi terjurnal tidak boleh berubah.');
    }

    public function test_transaksi_terjurnal_tidak_dapat_dipindah_rekening(): void
    {
        $teller = $this->pengguna('teller.pindah');
        $anggota = $this->anggota($teller);
        $transaksi = $this->transaksi($this->rekening($anggota), $teller);
        $this->jurnalPosted($transaksi, $teller);

        $rekeningLain = $this->rekening($this->anggota($this->pengguna('anggota.lain', 'anggota')));

        $payload = $this->payloadUbah($transaksi, 250000);
        $payload['rekening_id'] = $rekeningLain->id;

        $this->actingAs($teller)
            ->putJson("/api/transaksi/{$transaksi->id}", $payload)
            ->assertStatus(422);

        $this->assertSame($transaksi->rekening_id, (int) $transaksi->fresh()->rekening_id,
            'Rekening transaksi terjurnal tidak boleh dialihkan.');
    }

    /* =====================================================================
     | 3. Transaksi dalam proses void juga tidak dapat dimutasi
     ===================================================================== */

    public function test_transaksi_pending_void_tidak_dapat_dihapus(): void
    {
        $teller = $this->pengguna('teller.pending');
        $transaksi = $this->transaksi($this->rekening($this->anggota($teller)), $teller, [
            'status_transaksi' => Transaksi::STATUS_TRANSAKSI_PENDING_VOID,
        ]);
        $this->jurnalPosted($transaksi, $teller);

        $this->actingAs($teller)
            ->deleteJson("/api/transaksi/{$transaksi->id}")
            ->assertStatus(422);

        $this->assertSame(
            Transaksi::STATUS_TRANSAKSI_PENDING_VOID,
            $transaksi->fresh()->status_transaksi,
            'Permohonan void yang sedang menunggu otorisasi tidak boleh dibatalkan lewat hapus.'
        );
    }

    /* =====================================================================
     | 4. Jalur sah tetap terbuka (kontrol positif)
     ===================================================================== */

    public function test_transaksi_tanpa_jurnal_tetap_dapat_dihapus(): void
    {
        $teller = $this->pengguna('teller.draf');
        $transaksi = $this->transaksi($this->rekening($this->anggota($teller)), $teller, [
            'status_transaksi' => Transaksi::STATUS_TRANSAKSI_PENDING,
        ]);

        $this->actingAs($teller)
            ->deleteJson("/api/transaksi/{$transaksi->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(
            Transaksi::withTrashed()->find($transaksi->id)->trashed(),
            'Transaksi draf tanpa jurnal tetap boleh dihapus.'
        );
    }

    public function test_transaksi_tanpa_jurnal_tetap_dapat_diubah(): void
    {
        $teller = $this->pengguna('teller.draf.ubah');
        $transaksi = $this->transaksi($this->rekening($this->anggota($teller)), $teller, [
            'status_transaksi' => Transaksi::STATUS_TRANSAKSI_PENDING,
        ]);

        $this->actingAs($teller)
            ->putJson("/api/transaksi/{$transaksi->id}", $this->payloadUbah($transaksi, 300000))
            ->assertOk();

        $this->assertSame('300000.00', (string) $transaksi->fresh()->jumlah,
            'Transaksi draf tanpa jurnal tetap boleh dikoreksi.');
    }
}
