<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\AuditLog;
use App\Models\KebijakanKoperasi;
use App\Models\Pembiayaan;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\ExitKeanggotaanService;
use App\Support\GuardSimpananModal;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PENGUNCIAN EKUITAS — BAGIAN 4 (Penarikan Tunai Sukarela-only + NPF guard).
 *
 * Berkas ini mengunci dua janji bisnis:
 *
 *  A. PENARIKAN TUNAI HARIAN hanya sah untuk rekening Simpanan Sukarela
 *     (CoA 2-1001). Rekening Simpanan Pokok (3-1001) & Simpanan Wajib
 *     (3-1002) adalah MODAL/EKUITAS anggota (PSAK 101 ¶ 31) → DITOLAK dengan
 *     pesan standar yang IDENTIK di seluruh lapisan
 *     ({@see GuardSimpananModal::PESAN}).
 *
 *  B. PENGEMBALIAN dana Pokok/Wajib hanya sah lewat modul `member_terminations`
 *     dan HANYA setelah SELURUH pembiayaan anggota LUNAS (NPF = 0).
 *
 * Basis data dev memuat data nyata → prefix unik + DatabaseTransactions.
 */
class PenguncianEkuitasPenarikanTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'KUNCI'.strtoupper(substr(uniqid(), -6));

        // `minimum_transaksi_teller` adalah parameter kebijakan yang dapat
        // diubah dari UI Pengurus (mis. Rp 50.000). Uji ini mengirim nominal
        // Rp 10.000 agar benar-benar mencapai lapisan GUARD penarikan modal,
        // sehingga ambang minimum diturunkan sementara supaya tidak tertahan
        // validasi `min:` lebih dulu. Nilai asli otomatis dipulihkan oleh
        // DatabaseTransactions setelah tiap uji.
        KebijakanKoperasi::getSetting()->update(['minimum_transaksi_teller' => 1000.00]);
        Setting::flush();
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    private function user(string $role = 'teller'): User
    {
        $email = strtolower($this->prefix).'.'.$role.'@uji.test';

        $user = User::where('email', $email)->first();

        if ($user) {
            return $user;
        }

        $user = new User;
        $user->name = 'Uji Kunci '.$role;
        $user->email = $email;
        $user->password = Hash::make('rahasia123');
        $user->role = $role;
        $user->save();

        // Rute `teller.tarik.store` dijaga middleware `kas.terbuka`.
        if ($role === 'teller') {
            $this->bukaSesiKas($user);
        }

        return $user;
    }

    private function akunId(): int
    {
        $id = Akun::where('kode_akun', '1133')->value('id') ?? Akun::query()->value('id');

        if (! $id) {
            $this->markTestSkipped('Tabel akun kosong — jalankan AkunSeeder.');
        }

        return (int) $id;
    }

    private function anggota(string $label, string $status = Anggota::STATUS_ACTIVE): Anggota
    {
        return Anggota::create([
            'no_anggota' => substr($this->prefix.strtoupper($label), 0, 20),
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Uji Kunci '.$label,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Alamat Uji Penguncian Ekuitas',
            'kota' => 'Jakarta',
            'provinsi' => 'DKI Jakarta',
            'status_anggota' => $status,
            'tanggal_gabung' => '2020-01-01',
        ]);
    }

    private function rekening(Anggota $anggota, string $jenis, float $saldo): Rekening
    {
        return Rekening::create([
            'no_rekening' => 'RKN-'.strtoupper(substr(uniqid(), -9)),
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunId(),
            'jenis_rekening' => $jenis,
            'akad' => 'Wadiah',
            'saldo' => $saldo,
            'batas_minimum' => 0,
            'tanggal_buka' => now()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);
    }

    /* =====================================================================
     | A. PESAN STANDAR & ALLOWLIST
     |===================================================================== */

    public function test_pesan_penolakan_sesuai_spesifikasi(): void
    {
        $this->assertSame(
            'Penarikan tunai tidak diizinkan untuk rekening Simpanan Pokok/Wajib, silakan gunakan modul pengunduran diri anggota untuk proses pengembalian dana',
            GuardSimpananModal::PESAN
        );
    }

    public function test_allowlist_hanya_memuat_simpanan_sukarela(): void
    {
        $this->assertSame(
            ['Simpanan Sukarela'],
            GuardSimpananModal::JENIS_REKENING_DAPAT_DITARIK
        );
    }

    public function test_guard_mengizinkan_rekening_sukarela(): void
    {
        $anggota = $this->anggota('SUKARELA');
        $rekening = $this->rekening($anggota, 'Simpanan Sukarela', 500000);

        $this->assertTrue(GuardSimpananModal::rekeningDapatDitarikTunai($rekening));

        // Tidak boleh melempar exception.
        GuardSimpananModal::pastikanRekeningDapatDitarikTunai($rekening, 'uji');
        $this->assertTrue(true);
    }

    public function test_guard_menolak_rekening_pokok_dengan_pesan_persis(): void
    {
        $anggota = $this->anggota('POKOK');
        $rekening = $this->rekening($anggota, 'Simpanan Pokok', 500000);

        $this->assertFalse(GuardSimpananModal::rekeningDapatDitarikTunai($rekening));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(GuardSimpananModal::PESAN);

        GuardSimpananModal::pastikanRekeningDapatDitarikTunai($rekening, 'uji.pokok');
    }

    public function test_guard_menolak_rekening_wajib_dengan_pesan_persis(): void
    {
        $anggota = $this->anggota('WAJIB');
        $rekening = $this->rekening($anggota, 'Simpanan Wajib', 500000);

        $this->assertFalse(GuardSimpananModal::rekeningDapatDitarikTunai($rekening));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(GuardSimpananModal::PESAN);

        GuardSimpananModal::pastikanRekeningDapatDitarikTunai($rekening, 'uji.wajib');
    }

    /* =====================================================================
     | A2. ENDPOINT HTTP PENARIKAN TUNAI
     |===================================================================== */

    public function test_http_penarikan_pokok_ditolak_dan_saldo_tidak_berubah(): void
    {
        $anggota = $this->anggota('HTTPPOKOK');
        $rekening = $this->rekening($anggota, 'Simpanan Pokok', 500000);

        $transaksiSebelum = Transaksi::where('rekening_id', $rekening->id)->count();

        $this->actingAs($this->user('teller'))
            ->from(route('teller.tarik'))
            ->post(route('teller.tarik.store'), [
                'rekening_id' => $rekening->id,
                'jumlah' => 10000,
            ])
            ->assertRedirect(route('teller.tarik'))
            ->assertSessionHasErrors('jumlah');

        $this->assertSame(500000.0, round((float) $rekening->fresh()->saldo, 2));
        $this->assertSame(
            $transaksiSebelum,
            Transaksi::where('rekening_id', $rekening->id)->count(),
            'Penarikan yang ditolak tidak boleh menyisakan baris transaksi.'
        );
    }

    public function test_http_penarikan_wajib_ditolak_dan_tercatat_audit(): void
    {
        $anggota = $this->anggota('HTTPWAJIB');
        $rekening = $this->rekening($anggota, 'Simpanan Wajib', 500000);

        $auditSebelum = AuditLog::where('jenis_transaksi', 'PENARIKAN_MODAL_DIBLOKIR')->count();

        $this->actingAs($this->user('teller'))
            ->from(route('teller.tarik'))
            ->post(route('teller.tarik.store'), [
                'rekening_id' => $rekening->id,
                'jumlah' => 10000,
            ])
            ->assertRedirect(route('teller.tarik'))
            ->assertSessionHasErrors('jumlah');

        $this->assertSame(500000.0, round((float) $rekening->fresh()->saldo, 2));

        $this->assertGreaterThan(
            $auditSebelum,
            AuditLog::where('jenis_transaksi', 'PENARIKAN_MODAL_DIBLOKIR')->count(),
            'Upaya penarikan modal yang diblokir harus tercatat di audit_log.'
        );
    }

    public function test_dropdown_rekening_penarikan_hanya_menampilkan_sukarela(): void
    {
        $anggota = $this->anggota('DROPDOWN');
        $pokok = $this->rekening($anggota, 'Simpanan Pokok', 500000);
        $wajib = $this->rekening($anggota, 'Simpanan Wajib', 500000);
        $sukarela = $this->rekening($anggota, 'Simpanan Sukarela', 500000);

        $response = $this->actingAs($this->user('teller'))
            ->getJson(route('teller.rekening-by-anggota', ['anggota_id' => $anggota->id]));

        $response->assertOk();

        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($sukarela->id, $ids, 'Rekening Sukarela harus muncul di dropdown penarikan.');
        $this->assertNotContains($pokok->id, $ids, 'Rekening Pokok TIDAK boleh muncul di dropdown penarikan.');
        $this->assertNotContains($wajib->id, $ids, 'Rekening Wajib TIDAK boleh muncul di dropdown penarikan.');
    }

    /* =====================================================================
     | B. NPF GUARD PADA MODUL PENGUNDURAN DIRI ANGGOTA
     |===================================================================== */

    public function test_npf_nol_bila_tidak_ada_pembiayaan_berjalan(): void
    {
        $anggota = $this->anggota('NPFNOL');

        $service = app(ExitKeanggotaanService::class);

        $this->assertSame(0.0, $service->npfAnggota($anggota));

        // Tidak boleh melempar exception.
        $service->pastikanNpfNol($anggota);
        $this->assertTrue(true);
    }

    public function test_npf_lebih_dari_nol_ditolak_dengan_pesan_jelas(): void
    {
        $anggota = $this->anggota('NPFADA');

        Pembiayaan::create([
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunId(),
            'no_pembiayaan' => 'PBC-'.strtoupper(substr(uniqid(), -8)),
            'akad_pembiayaan' => 'Murabahah',
            'jumlah_pembiayaan' => 10000000,
            'total_pembiayaan' => 10000000,
            'tenor' => 12,
            'jumlah_angsuran' => 833334,
            'sisa_pembiayaan' => 4000000,
            'status_pembiayaan' => 'aktif',
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addYear()->toDateString(),
        ]);

        $service = app(ExitKeanggotaanService::class);

        $this->assertGreaterThan(0.0, $service->npfAnggota($anggota));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/NPF|LUNAS/');

        $service->pastikanNpfNol($anggota);
    }

    public function test_pembiayaan_lunas_tidak_menghalangi_npf_nol(): void
    {
        $anggota = $this->anggota('NPFLUNAS');

        Pembiayaan::create([
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunId(),
            'no_pembiayaan' => 'PBC-'.strtoupper(substr(uniqid(), -8)),
            'akad_pembiayaan' => 'Murabahah',
            'jumlah_pembiayaan' => 10000000,
            'total_pembiayaan' => 10000000,
            'tenor' => 12,
            'jumlah_angsuran' => 833334,
            'sisa_pembiayaan' => 0,
            'status_pembiayaan' => Pembiayaan::STATUS_LUNAS,
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addYear()->toDateString(),
        ]);

        $service = app(ExitKeanggotaanService::class);

        $this->assertSame(0.0, $service->npfAnggota($anggota));
        $service->pastikanNpfNol($anggota);
        $this->assertTrue(true);
    }
}
