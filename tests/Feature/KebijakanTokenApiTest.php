<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * T-2 — KEBIJAKAN TOKEN & PEMBATASAN LAJU PADA KANAL API MOBILE.
 *
 * Temuan audit, tiga celah:
 *
 *  1. BYPASS BATAS PERAN. `GET /api/approval/status` dijaga `auth:sanctum`
 *     tanpa `izin:` dan `batasiKeCakupanPeran()` memakai cabang terakhir
 *     `where('diajukan_oleh', $user->id)`. Peran `anggota` tidak terdaftar pada
 *     `role_hierarki.batas`, sehingga peran tersebut juga TIDAK tercakup
 *     `role_hierarki.frekuensi` / `akses_data`: penjaga di
 *     `TransactionLimitService` dan `HierarkiRole::ambilDenganBatas()` tak
 *     pernah menyala. Anggota yang memperoleh token Sanctum (atau sesi yang
 *     bocor) dapat memindai seluruh ID approval — termasuk ID di luar miliknya.
 *
 *  2. TOKEN TANPA PANTAU AKTIVITAS. `config/sanctum.php` mengklaim token
 *     berlaku "8 jam", padahal kunci Sanctum `expiration` adalah umur ABSOLUT
 *     sejak penerbitan dan `SANCTUM_EXPIRATION` tidak disetel di `.env`
 *     manapun. Token yang dipakai setiap hari tetap mati di tengah jam kerja;
 *     token 479 menit yang tidak pernah dipakai tetap diterima keesokan hari.
 *
 *  3. DUA RUTE API TANPA PEMBATAS LAJU. `transaksi/setor`,
 *     `transaksi/tarik`, dan `approval/status` tidak mendapat `throttle:*`,
 *     sehingga dapat dihujani permintaan tanpa batas (biaya basis data &
 *     peluang tebak-tebakan ID).
 */
class KebijakanTokenApiTest extends TestCase
{
    use DatabaseTransactions;

    /** Nama throttle yang wajib terpasang, beserta batas permintaan. */
    private const THROTTLE = [
        'api.transaksi.setor' => 'throttle:transaksi',
        'api.transaksi.tarik' => 'throttle:transaksi',
        'api.approval.status' => 'throttle:baca-api',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('rekening') || ! Schema::hasTable('approval_transaksi')) {
            $this->markTestSkipped('Tabel inti API belum tersedia.');
        }

        $this->withHeader('Accept', 'application/json');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* =====================================================================
     | Helper
     ===================================================================== */

    private function pengguna(string $kunci, string $role = 'anggota'): User
    {
        return User::firstOrCreate(
            ['email' => "token-api.{$kunci}@test.local"],
            [
                'name' => 'Token API '.ucfirst($kunci),
                'password' => Hash::make('password'),
                'role' => $role,
                'is_active' => true,
            ]
        );
    }

    private function rekeningMilik(User $pengguna): Rekening
    {
        $suffix = strtoupper(substr(uniqid(), -6));

        $anggota = Anggota::create([
            'user_id' => $pengguna->id,
            'no_anggota' => 'TK-'.$suffix,
            'nama_lengkap' => 'Anggota Token '.$suffix,
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji Token No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_ACTIVE,
            'tanggal_gabung' => now()->toDateString(),
        ]);

        $akunId = DB::table('akun')->value('id');

        if (! $akunId) {
            $akunId = DB::table('akun')->insertGetId([
                'kode_akun' => '1-1001',
                'nama_akun' => 'Kas Uji Token',
                'tipe_akun' => 'Aset',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return Rekening::create([
            'anggota_id' => $anggota->id,
            'akun_id' => $akunId,
            'no_rekening' => 'REK-TK-'.strtoupper(substr(uniqid(), -8)),
            'jenis_rekening' => 'Simpanan Sukarela',
            'status_rekening' => 'Aktif',
            'saldo' => 0,
            'tanggal_buka' => now()->toDateString(),
        ]);
    }

    private function setor(User $pengguna, Rekening $rekening): TestResponse
    {
        Sanctum::actingAs($pengguna);

        return $this->postJson('/api/transaksi/setor', [
            'rekening_id' => $rekening->id,
            'jumlah' => 50000,
        ]);
    }

    /**
     * Token Sanctum sungguhan (bukan `Sanctum::actingAs`) berumur $menit saat
     * dipakai — dipakai untuk menguji kebijakan kedaluwarsa. Peran dipilih
     * `teller` agar yang diukur benar-benar kebijakan token, bukan gerbang
     * peran pada kanal tersebut.
     */
    private function kirimDenganTokenBerumur(int $umurMenit): TestResponse
    {
        $pengguna = $this->pengguna('kedaluwarsa.teller', 'teller');

        $token = $pengguna->createToken('uji-t2')->plainTextToken;

        Carbon::setTestNow(now()->addMinutes($umurMenit));

        return $this->withToken($token)
            ->getJson('/api/approval/status');
    }

    /* =====================================================================
     | 1. BATAS PERAN BERLAKU PADA KANAL API
     ===================================================================== */

    public function test_peran_di_luar_batas_kebijakan_tidak_boleh_memakai_api_transaksi(): void
    {
        $anggota = $this->pengguna('anggota');
        $rekening = $this->rekeningMilik($anggota);

        $respons = $this->setor($anggota, $rekening);

        $respons->assertForbidden();

        $this->assertSame(
            0,
            Transaksi::where('rekening_id', $rekening->id)->count(),
            'Setoran anggota tanpa kebijakan batas (limit NULL = tanpa batas) tidak boleh diproses.'
        );
    }

    public function test_anggota_tidak_dapat_membaca_status_approval_milik_orang_lain(): void
    {
        $anggota = $this->pengguna('anggota.baca');

        Sanctum::actingAs($anggota);

        $this->getJson('/api/approval/status')
            ->assertForbidden();
    }

    /* =====================================================================
     | 2. TOKEN: MASA BERLAKU = TANPA AKTIVITAS
     ===================================================================== */

    public function test_token_yang_aktif_dipantau_tetap_dapat_dipakai(): void
    {
        $this->kirimDenganTokenBerumur(2 * 24 * 60)->assertOk();
    }

    public function test_token_yang_menganggur_melewati_masa_berlaku_ditolak(): void
    {
        $this->kirimDenganTokenBerumur((int) config('sanctum.expiration') + 1)
            ->assertUnauthorized();
    }

    /* =====================================================================
     | 3. PEMBATAS LAJU TERPASANG
     ===================================================================== */

    public function test_setiap_rute_api_sensitif_memiliki_pembatas_laju(): void
    {
        $this->assertGreaterThan(
            0,
            (int) config('keamanan.throttle.transaksi'),
            'Batas `throttle.transaksi` wajib dikonfigurasi.'
        );
        $this->assertGreaterThan(
            0,
            (int) config('keamanan.throttle.baca-api'),
            'Batas `throttle.baca-api` wajib dikonfigurasi.'
        );

        foreach (self::THROTTLE as $nama => $throttle) {
            $rute = app('router')->getRoutes()->getByName($nama);

            $this->assertNotNull($rute, "Rute `{$nama}` tidak terdaftar.");
            $this->assertContains(
                $throttle,
                $rute->gatherMiddleware(),
                "Rute `{$nama}` wajib dijaga `{$throttle}`."
            );
        }
    }
}
