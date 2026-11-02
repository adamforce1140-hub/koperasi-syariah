<?php

namespace Tests\Feature;

use App\Http\Middleware\PastikanKasTerbuka;
use App\Models\KasHarian;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * TAHAP 1 — PENGUJIAN GUARD SESI KAS TELLER.
 *
 * Cakupan:
 *   A. Unit test  : seluruh cabang `PastikanKasTerbuka::handle()` dipanggil
 *                   langsung (tanpa HTTP) sehingga setiap baris keputusan
 *                   dieksekusi terpisah dan dapat dipetakan ke satu test.
 *   B. Integration: rantai middleware produksi (`auth` → `izin:teller.loket`
 *                   → `kas.terbuka` → controller) diuji lewat rute nyata.
 *
 * Istilah "token" pada permintaan pengguna diterjemahkan ke mekanisme
 * autentikasi sesi Laravel yang dipakai sistem ini:
 *   • sesi valid        → pengguna login + `password_hash_web` cocok.
 *   • sesi kedaluwarsa  → `password_hash_web` tidak cocok (AuthenticateSession
 *                         memaksa logout).
 *   • token palsu       → ID pengguna pada sesi tidak terdaftar di tabel users.
 *   • tanpa token       → permintaan tanpa autentikasi sama sekali.
 */
class KasGuardSesiTest extends TestCase
{
    use DatabaseTransactions;

    /* =====================================================================
     | HELPER
     |===================================================================== */

    private function teller(): User
    {
        return User::firstOrCreate(
            ['email' => 'teller.guardsesi@test.local'],
            [
                'name' => 'Teller Guard Sesi',
                'password' => Hash::make('password'),
                'role' => 'teller',
                'is_active' => true,
            ]
        );
    }

    private function manajer(): User
    {
        return User::firstOrCreate(
            ['email' => 'manajer.guardsesi@test.local'],
            [
                'name' => 'Manajer Guard Sesi',
                'password' => Hash::make('password'),
                'role' => 'manajer',
                'is_active' => true,
            ]
        );
    }

    /** Hapus sesi kas hari ini agar skenario "tanpa sesi" deterministik. */
    private function hapusSesiHariIni(User $user): void
    {
        KasHarian::where('user_id', $user->getKey())
            ->whereDate('tanggal', now()->toDateString())
            ->delete();
    }

    /** Bangun Request untuk pemanggilan middleware secara langsung. */
    private function requestSebagai(?User $user): Request
    {
        $request = Request::create('/teller/setor', 'POST');

        if ($user) {
            $request->setUserResolver(static fn () => $user);
        }

        return $request;
    }

    /** Jalankan middleware dengan penerus yang selalu mengembalikan "LOLOS". */
    private function jalankanGuard(Request $request)
    {
        return (new PastikanKasTerbuka)->handle(
            $request,
            static fn () => response('LOLOS', 200)
        );
    }

    /**
     * Jalankan guard dan kembalikan HttpException yang dilempar.
     * Gagal jika guard justru meloloskan permintaan.
     */
    private function tangkapPenolakan(Request $request): HttpException
    {
        try {
            $this->jalankanGuard($request);
        } catch (HttpException $e) {
            return $e;
        }

        $this->fail('Guard seharusnya menolak permintaan, tetapi permintaan diloloskan.');
    }

    /* =====================================================================
     | A. UNIT TEST — CABANG MIDDLEWARE
     |===================================================================== */

    public function test_unit_permintaan_tanpa_pengguna_ditolak_401(): void
    {
        $e = $this->tangkapPenolakan($this->requestSebagai(null));

        $this->assertSame(401, $e->getStatusCode());
        $this->assertStringContainsString('Sesi autentikasi tidak valid', $e->getMessage());
    }

    public function test_unit_pengguna_non_teller_diloloskan_tanpa_sesi_kas(): void
    {
        $manajer = new User(['role' => 'manajer']);
        $this->assertFalse($manajer->isTeller(), 'Prasyarat: peran manajer bukan teller.');

        $response = $this->jalankanGuard($this->requestSebagai($manajer));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('LOLOS', $response->getContent());
    }

    public function test_unit_sesi_open_diloloskan(): void
    {
        $teller = $this->teller();
        $this->bukaSesiKas($teller);

        $response = $this->jalankanGuard($this->requestSebagai($teller));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('LOLOS', $response->getContent());
    }

    public function test_unit_tanpa_sesi_kas_ditolak_403(): void
    {
        $teller = $this->teller();
        $this->hapusSesiHariIni($teller);

        $e = $this->tangkapPenolakan($this->requestSebagai($teller));

        $this->assertSame(403, $e->getStatusCode());
        $this->assertStringContainsString('laci Teller belum dibuka', $e->getMessage());
        $this->assertStringContainsString(now()->toDateString(), $e->getMessage());
    }

    public function test_unit_sesi_pending_approval_ditolak_403(): void
    {
        $teller = $this->teller();
        $sesi = $this->bukaSesiKas($teller);
        $sesi->update(['status_opname' => KasHarian::STATUS_PENDING_APPROVAL]);

        $e = $this->tangkapPenolakan($this->requestSebagai($teller));

        $this->assertSame(403, $e->getStatusCode());
        $this->assertStringContainsString('menunggu verifikasi opname', $e->getMessage());
    }

    public function test_unit_sesi_closed_ditolak_403(): void
    {
        $teller = $this->teller();
        $sesi = $this->bukaSesiKas($teller);
        $sesi->update(['status_opname' => KasHarian::STATUS_CLOSED]);

        $e = $this->tangkapPenolakan($this->requestSebagai($teller));

        $this->assertSame(403, $e->getStatusCode());
        $this->assertStringContainsString('sudah ditutup', $e->getMessage());
    }

    public function test_unit_sesi_kemarin_tidak_dianggap_sah(): void
    {
        $teller = $this->teller();
        $this->hapusSesiHariIni($teller);
        $this->bukaSesiKas($teller, now()->subDay()->toDateString());

        $e = $this->tangkapPenolakan($this->requestSebagai($teller));

        $this->assertSame(403, $e->getStatusCode());
        $this->assertStringContainsString('laci Teller belum dibuka', $e->getMessage());
    }

    public function test_unit_penolakan_tercatat_pada_log_audit(): void
    {
        Log::spy();

        $teller = $this->teller();
        $this->hapusSesiHariIni($teller);

        $this->tangkapPenolakan($this->requestSebagai($teller));

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $pesan, array $konteks) use ($teller): bool {
                return $pesan === 'Guard sesi kas menolak transaksi.'
                    && (int) $konteks['user_id'] === (int) $teller->getKey()
                    && $konteks['status'] === 'TANPA_SESI'
                    && $konteks['rute'] === 'teller/setor'
                    && $konteks['metode'] === 'POST';
            });
    }

    /* =====================================================================
     | B. INTEGRATION TEST — RANTAI MIDDLEWARE PRODUKSI
     |===================================================================== */

    public function test_http_sesi_valid_meloloskan_transaksi_sampai_controller(): void
    {
        $teller = $this->teller();
        $this->bukaSesiKas($teller);

        // Payload kosong: controller menolak lewat validasi (302 + errors),
        // bukan 403. Ini membuktikan guard sesi sudah dilalui.
        $response = $this->actingAs($teller)
            ->post(route('teller.setor.store'), []);

        $response->assertRedirect();
        $response->assertSessionHasErrors('rekening_id');
    }

    public function test_http_tanpa_sesi_kas_ditolak_403(): void
    {
        $teller = $this->teller();
        $this->hapusSesiHariIni($teller);

        $this->actingAs($teller)
            ->post(route('teller.setor.store'), [])
            ->assertForbidden();
    }

    public function test_http_sesi_pending_approval_ditolak_403(): void
    {
        $teller = $this->teller();
        $sesi = $this->bukaSesiKas($teller);
        $sesi->update(['status_opname' => KasHarian::STATUS_PENDING_APPROVAL]);

        $this->actingAs($teller)
            ->post(route('teller.setor.store'), [])
            ->assertForbidden();
    }

    public function test_http_sesi_closed_ditolak_403(): void
    {
        $teller = $this->teller();
        $sesi = $this->bukaSesiKas($teller);
        $sesi->update(['status_opname' => KasHarian::STATUS_CLOSED]);

        $this->actingAs($teller)
            ->post(route('teller.setor.store'), [])
            ->assertForbidden();
    }

    public function test_http_pengguna_non_teller_tidak_dihalangi_guard(): void
    {
        $manajer = $this->manajer();
        KasHarian::where('user_id', $manajer->getKey())->delete();

        $response = $this->actingAs($manajer)
            ->post(route('teller.setor.store'), []);

        // 302 dari validasi controller — bukan 403 dari guard sesi.
        $response->assertRedirect();
        $response->assertSessionHasErrors('rekening_id');
    }

    public function test_http_tanpa_autentikasi_dialihkan_ke_login(): void
    {
        $this->post(route('teller.setor.store'), [])
            ->assertRedirect(route('login'));
    }

    public function test_http_tanpa_autentikasi_json_mendapat_401(): void
    {
        $response = $this->postJson(route('teller.setor.store'), []);

        $response->assertUnauthorized();
        $response->assertJsonPath('message', 'Sesi Anda telah berakhir. Silakan masuk kembali untuk melanjutkan.');
        $response->assertJsonPath('error.status', 401);
    }

    public function test_http_sesi_kedaluwarsa_hash_tidak_cocok_dialihkan_ke_login(): void
    {
        $teller = $this->teller();
        $this->bukaSesiKas($teller);
        $this->actingAs($teller);

        // Simulasikan hash password lama pada sesi: AuthenticateSession
        // menganggap sesi tidak sah lalu memaksa logout.
        $this->app['session']->driver()->put('password_hash_web', 'hash-sesi-kedaluwarsa');

        $this->post(route('teller.setor.store'), [])
            ->assertRedirect(route('login'));
    }

    public function test_http_token_palsu_id_pengguna_tidak_terdaftar_dialihkan_ke_login(): void
    {
        $teller = $this->teller();
        $this->bukaSesiKas($teller);
        $this->actingAs($teller);

        // ID pengguna yang tidak ada di tabel users — sesi tidak dapat
        // diresolusi menjadi pengguna sah.
        $this->app['session']->driver()->put(
            $this->app['auth']->guard()->getName(),
            999999999
        );

        // `actingAs()` menaruh objek User pada guard; lupakan cache itu agar
        // guard benar-benar membaca ulang ID dari sesi (seperti request nyata).
        $this->app['auth']->forgetGuards();

        $this->post(route('teller.setor.store'), [])
            ->assertRedirect(route('login'));
    }

    public function test_http_tanpa_sesi_json_mendapat_403(): void
    {
        $teller = $this->teller();
        $this->hapusSesiHariIni($teller);

        $response = $this->actingAs($teller)
            ->postJson(route('teller.setor.store'), []);

        $response->assertForbidden();
        $response->assertJsonPath('error.status', 403);
    }
}
