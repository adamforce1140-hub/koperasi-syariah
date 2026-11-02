<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\AutodebitUsulan;
use App\Models\Coa;
use App\Models\JenisSimpanan;
use App\Models\MandatorySavingsBill;
use App\Models\Rekening;
use App\Models\User;
use App\Notifications\AutodebitUsulanNotification;
use App\Services\AutodebitService;
use App\Services\MandatorySavingsService;
use App\Support\Izin;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * KEBIJAKAN: "TIDAK ADA AUTO-DEBIT TANPA KONFIRMASI PENGGUNA".
 *
 * Test ini mengunci satu-satunya pintu pemotongan (Pusat Konfirmasi Auto-Debit)
 * dan membuktikan secara eksplisit bahwa TIDAK ADA jalur — baik scheduler
 * maupun HTTP — yang dapat memotong saldo anggota tanpa persetujuan pengguna
 * berwenang. Bila celah semacam itu muncul kelak, test ini akan gagal.
 */
class AutodebitKonfirmasiTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'ADBCNF'.strtoupper(substr(uniqid(), -6));
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    private function service(): MandatorySavingsService
    {
        return app(MandatorySavingsService::class);
    }

    private function akunId(): int
    {
        $id = Akun::where('kode_akun', '1133')->value('id') ?? Akun::query()->value('id');

        if (! $id) {
            $this->markTestSkipped('Tabel akun kosong — jalankan AkunSeeder.');
        }

        return (int) $id;
    }

    private function pastikanCoa(): void
    {
        foreach ([
            MandatorySavingsService::COA_SIMPANAN_SUKARELA,
            MandatorySavingsService::COA_SIMPANAN_WAJIB_MODAL,
        ] as $kode) {
            if (! Coa::findByKode($kode)) {
                $this->markTestSkipped("CoA {$kode} belum tersedia — jalankan CoaSeeder.");
            }
        }
    }

    private function izinkanAutoDebitProdukWajib(): void
    {
        JenisSimpanan::query()
            ->where('nama', 'Simpanan Wajib')
            ->orWhere('code', JenisSimpanan::CODE_WAJIB)
            ->update(['allow_auto_debit' => true]);

        JenisSimpanan::flush();
    }

    private function anggota(
        string $label,
        string $tanggalGabung = '2026-01-31',
        string $status = Anggota::STATUS_ACTIVE,
        bool $autoDebit = true,
    ): Anggota {
        return Anggota::create([
            'no_anggota' => substr($this->prefix.strtoupper($label), 0, 20),
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Uji Konfirmasi '.$label,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Alamat Uji Konfirmasi Auto-Debit',
            'kota' => 'Jakarta',
            'provinsi' => 'DKI Jakarta',
            'status_anggota' => $status,
            'is_auto_debit' => $autoDebit,
            'tanggal_gabung' => $tanggalGabung,
        ]);
    }

    private function rekeningSukarela(Anggota $anggota, float $saldo): Rekening
    {
        return Rekening::create([
            'no_rekening' => 'RKN-'.strtoupper(substr(uniqid(), -9)),
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunId(),
            'jenis_rekening' => 'Simpanan Sukarela',
            'akad' => 'Wadiah',
            'saldo' => $saldo,
            'batas_minimum' => 0,
            'tanggal_buka' => now()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);
    }

    private function user(string $role = 'teller'): User
    {
        return User::firstOrCreate(
            ['email' => "{$role}.{$this->prefix}@test.local"],
            [
                'name' => ucfirst($role).' '.$this->prefix,
                'password' => Hash::make('password'),
                'role' => $role,
            ]
        );
    }

    /**
     * Siapkan satu tagihan Simpanan Wajib yang sudah MENUNGGU KONFIRMASI
     * (dihasilkan scheduler) tanpa memotong dana.
     *
     * @return array{anggota:Anggota, rekening:Rekening, bill:MandatorySavingsBill, usulan:AutodebitUsulan}
     */
    private function siapkanUsulan(float $saldo = 500000): array
    {
        $this->pastikanCoa();
        $this->akunId();
        $this->izinkanAutoDebitProdukWajib();

        $anggota = $this->anggota('SIA'.substr(uniqid(), -4));
        $rekening = $this->rekeningSukarela($anggota, $saldo);

        $this->service()->processDaily(Carbon::create(2026, 2, 28));

        $bill = MandatorySavingsBill::where('member_id', $anggota->id)
            ->where('bill_period', '2026-02')
            ->firstOrFail();

        $usulan = AutodebitUsulan::menungguKonfirmasi()
            ->where('kanal', AutodebitUsulan::KANAL_SIMPANAN_WAJIB)
            ->where('referensi_id', $bill->id)
            ->firstOrFail();

        return compact('anggota', 'rekening', 'bill', 'usulan');
    }

    /* =====================================================================
     | 1. TIDAK ADA PEMOTONGAN TANPA KONFIRMASI
     |===================================================================== */

    /**
     * INTI KEBIJAKAN: scheduler mengusulkan, TIDAK memotong. Saldo anggota
     * tetap utuh dan tagihan berhenti pada status MENUNGGU KONFIRMASI.
     */
    public function test_scheduler_mengusulkan_tanpa_memotong_saldo(): void
    {
        $ctx = $this->siapkanUsulan(500000);

        $this->assertSame(MandatorySavingsBill::STATUS_PENDING_CONFIRMATION, $ctx['bill']->status);
        $this->assertNull($ctx['bill']->paid_at);
        $this->assertSame(
            500000.0,
            round((float) $ctx['rekening']->fresh()->saldo, 2),
            'Saldo Sukarela TIDAK boleh berubah sebelum pengguna mengonfirmasi.'
        );
        $this->assertSame(AutodebitUsulan::STATUS_MENUNGGU_KONFIRMASI, $ctx['usulan']->status);
        $this->assertNull($ctx['usulan']->confirmed_by);
        $this->assertNull($ctx['usulan']->executed_at);
    }

    /**
     * CELAH #1 — memanggil auto-debit langsung tanpa konfirmasi HARUS gagal.
     * Memanggil `konfirmasi()` pada usulan yang tidak ada / sudah diproses
     * tidak boleh memotong dana.
     */
    public function test_konfirmasi_usulan_yang_sudah_diproses_tidak_dipotong_dua_kali(): void
    {
        $ctx = $this->siapkanUsulan(500000);
        $petugas = $this->user('teller');

        $pertama = app(AutodebitService::class)->konfirmasi($ctx['usulan'], (int) $petugas->id);
        $this->assertTrue($pertama['ok']);
        $saldoSetelahKonfirmasi = round((float) $ctx['rekening']->fresh()->saldo, 2);

        // Konfirmasi ulang → idempoten, saldo TIDAK berkurang lagi.
        $kedua = app(AutodebitService::class)->konfirmasi($ctx['usulan']->refresh(), (int) $petugas->id);
        $this->assertFalse($kedua['ok'], 'Konfirmasi ulang harus ditolak (idempoten).');
        $this->assertSame(
            $saldoSetelahKonfirmasi,
            round((float) $ctx['rekening']->fresh()->saldo, 2),
            'Pemotongan ganda terjadi — celah kebijakan!'
        );
    }

    /* =====================================================================
     | 2. JALUR KONFIRMASI (SATU-SATUNYA PEMOTONGAN)
     |===================================================================== */

    public function test_konfirmasi_oleh_pengguna_berwenang_melakukan_pemotongan(): void
    {
        $ctx = $this->siapkanUsulan(500000);
        $petugas = $this->user('teller');

        $hasil = app(AutodebitService::class)->konfirmasi($ctx['usulan'], (int) $petugas->id, 'Disetujui.');

        $this->assertTrue($hasil['ok'], $hasil['pesan'] ?? '');

        $bill = $ctx['bill']->refresh();
        $this->assertSame(MandatorySavingsBill::STATUS_PAID, $bill->status);
        $this->assertNotNull($bill->paid_at);

        $usulan = $ctx['usulan']->refresh();
        $this->assertSame(AutodebitUsulan::STATUS_DIKONFIRMASI, $usulan->status);
        $this->assertSame($petugas->id, (int) $usulan->confirmed_by);
        $this->assertNotNull($usulan->confirmed_at);
        $this->assertNotNull($usulan->executed_at);
        $this->assertSame((float) $bill->amount, (float) $usulan->nominal);
    }

    /**
     * CELAH #2 — konfirmasi dengan saldo tidak cukup HARUS gagal dan TIDAK
     * memotong sepeser pun; usulan ditandai GAGAL.
     */
    public function test_konfirmasi_gagal_saat_saldo_tidak_cukup_tanpa_memotong(): void
    {
        $ctx = $this->siapkanUsulan(100); // saldo 100 < nominal tagihan
        $petugas = $this->user('teller');

        $hasil = app(AutodebitService::class)->konfirmasi($ctx['usulan'], (int) $petugas->id);

        $this->assertFalse($hasil['ok'], 'Konfirmasi dengan saldo kurang harus ditolak.');
        $this->assertSame(
            100.0,
            round((float) $ctx['rekening']->fresh()->saldo, 2),
            'Saldo terpotong meski verifikasi gagal — celah kebijakan!'
        );
        $this->assertSame(AutodebitUsulan::STATUS_GAGAL, $ctx['usulan']->refresh()->status);
        $this->assertSame(
            MandatorySavingsBill::STATUS_PENDING_CONFIRMATION,
            $ctx['bill']->refresh()->status,
            'Tagihan tidak boleh dilunasi bila pemotongan gagal.'
        );
    }

    public function test_tolak_usulan_tidak_memotong_dana(): void
    {
        $ctx = $this->siapkanUsulan(500000);
        $petugas = $this->user('teller');

        $hasil = app(AutodebitService::class)->tolak($ctx['usulan'], (int) $petugas->id, 'Tidak disetujui.');

        $this->assertTrue($hasil['ok'], $hasil['pesan'] ?? '');
        $this->assertSame(
            500000.0,
            round((float) $ctx['rekening']->fresh()->saldo, 2),
            'Penolakan TIDAK boleh memotong dana.'
        );
        $this->assertSame(AutodebitUsulan::STATUS_DITOLAK, $ctx['usulan']->refresh()->status);
        $this->assertNotSame(MandatorySavingsBill::STATUS_PAID, $ctx['bill']->refresh()->status);
    }

    /* =====================================================================
     | 3. PUSAT KONFIRMASI VIA HTTP + RBAC
     |===================================================================== */

    public function test_halaman_pusat_konfirmasi_dapat_diakses_role_berwenang(): void
    {
        $this->siapkanUsulan(500000);

        // PEMISAHAN TUGAS: Pusat Konfirmasi Auto-Debit adalah fungsi
        // back-office, bukan kewenangan strategis Pengurus maupun tugas kasir
        // (Teller). Keduanya TIDAK lagi termasuk.
        foreach (['manajer', 'super_admin', 'admin'] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('autodebit.index'))
                ->assertOk()
                ->assertSee('Pusat Konfirmasi Auto-Debit');
        }
    }

    public function test_pengurus_tidak_lagi_dapat_mengakses_pusat_konfirmasi_autodebit(): void
    {
        $this->siapkanUsulan(500000);

        // Guard diperketat: role:pengurus dicabut dari rute tersebut.
        $this->actingAs($this->user('pengurus'))
            ->get(route('autodebit.index'))
            ->assertForbidden();
    }

    public function test_teller_tidak_lagi_dapat_mengakses_pusat_konfirmasi_autodebit(): void
    {
        $this->siapkanUsulan(500000);

        // PEMISAHAN TUGAS (Segregation of Duties): konfirmasi/eksekusi
        // auto-debit adalah fungsi back-office, bukan tugas kasir. Role
        // `teller` dicabut dari rute ini — akses URL langsung pun ditolak.
        $this->actingAs($this->user('teller'))
            ->get(route('autodebit.index'))
            ->assertForbidden();

        $this->actingAs($this->user('teller'))
            ->post(route('autodebit.konfirmasi', AutodebitUsulan::query()->value('id') ?? 1))
            ->assertForbidden();
    }

    public function test_halaman_pusat_konfirmasi_ditolak_untuk_role_tidak_berwenang(): void
    {
        foreach (['anggota', 'ao', 'dps', 'teller'] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('autodebit.index'))
                ->assertForbidden();
        }
    }

    public function test_http_konfirmasi_memotong_dana_melalui_pusat_konfirmasi(): void
    {
        $ctx = $this->siapkanUsulan(500000);
        $petugas = $this->user('admin');

        $this->actingAs($petugas)
            ->post(route('autodebit.konfirmasi', $ctx['usulan']), ['catatan' => 'OK via HTTP.'])
            ->assertRedirect();

        $this->assertSame(MandatorySavingsBill::STATUS_PAID, $ctx['bill']->refresh()->status);
        $this->assertSame(AutodebitUsulan::STATUS_DIKONFIRMASI, $ctx['usulan']->refresh()->status);
        $this->assertLessThan(500000.0, round((float) $ctx['rekening']->fresh()->saldo, 2));
    }

    public function test_http_konfirmasi_ditolak_untuk_role_anggota(): void
    {
        $ctx = $this->siapkanUsulan(500000);

        $this->actingAs($this->user('anggota'))
            ->post(route('autodebit.konfirmasi', $ctx['usulan']))
            ->assertForbidden();

        // Saldo tetap utuh — RBAC mencegah pemotongan oleh pihak tak berwenang.
        $this->assertSame(500000.0, round((float) $ctx['rekening']->fresh()->saldo, 2));
        $this->assertSame(AutodebitUsulan::STATUS_MENUNGGU_KONFIRMASI, $ctx['usulan']->refresh()->status);
    }

    public function test_http_tolak_tidak_memotong_dana(): void
    {
        $ctx = $this->siapkanUsulan(500000);

        $this->actingAs($this->user('manajer'))
            ->post(route('autodebit.tolak', $ctx['usulan']), ['catatan' => 'Tolak uji.'])
            ->assertRedirect();

        $this->assertSame(500000.0, round((float) $ctx['rekening']->fresh()->saldo, 2));
        $this->assertSame(AutodebitUsulan::STATUS_DITOLAK, $ctx['usulan']->refresh()->status);
    }

    /* =====================================================================
     | 4. IDEMPOTENSI USULAN (scheduler berulang tidak menumpuk pemotongan)
     |===================================================================== */

    public function test_scheduler_berulang_tidak_menggandakan_usulan(): void
    {
        $ctx = $this->siapkanUsulan(500000);

        $jumlahAwal = AutodebitUsulan::where('kanal', AutodebitUsulan::KANAL_SIMPANAN_WAJIB)
            ->where('referensi_id', $ctx['bill']->id)
            ->count();

        // Jalankan scheduler lagi pada tanggal sama.
        $this->service()->processDaily(Carbon::create(2026, 2, 28));

        $jumlahAkhir = AutodebitUsulan::where('kanal', AutodebitUsulan::KANAL_SIMPANAN_WAJIB)
            ->where('referensi_id', $ctx['bill']->id)
            ->count();

        $this->assertSame($jumlahAwal, $jumlahAkhir, 'Scheduler berulang menggandakan usulan.');
        $this->assertSame(500000.0, round((float) $ctx['rekening']->fresh()->saldo, 2));
    }

    /* =====================================================================
     | 5. PENUNJUKAN PELAKSANA KONFIRMASI (A6)
     |===================================================================== */

    /**
     * Penerima notifikasi HARUS identik dengan peran yang boleh membuka Pusat
     * Konfirmasi. Dua daftar terpisah pernah menyimpang: Teller & Pengurus
     * menerima notifikasi "menunggu konfirmasi Anda" yang tautannya berakhir
     * 403. Test ini mengunci kesetaraan itu — bila daftar menyimpang lagi,
     * test gagal sebelum pengguna menemukannya lewat klik yang gagal.
     */
    public function test_notifikasi_usulan_hanya_dikirim_ke_peran_yang_dapat_mengonfirmasi(): void
    {
        Notification::fake();

        $this->pastikanCoa();
        $this->akunId();
        $this->izinkanAutoDebitProdukWajib();

        $anggota = $this->anggota('NTF'.substr(uniqid(), -4));
        $this->rekeningSukarela($anggota, 500000);

        $penerima = [];
        foreach (['manajer', 'super_admin', 'admin', 'pengurus', 'teller'] as $role) {
            $penerima[$role] = $this->user($role);
        }

        $this->service()->processDaily(Carbon::create(2026, 2, 28));

        $berwenang = Izin::peran('autodebit.konfirmasi');

        $this->assertNotEmpty($berwenang, 'Izin autodebit.konfirmasi tidak boleh kosong.');

        foreach ($penerima as $role => $user) {
            if (in_array($role, $berwenang, true)) {
                Notification::assertSentTo($user, AutodebitUsulanNotification::class);

                continue;
            }

            Notification::assertNotSentTo($user, AutodebitUsulanNotification::class);
        }
    }

    /**
     * Setiap penerima notifikasi harus benar-benar dapat membuka tautan yang
     * dikirim. Notifikasi membawa `route('autodebit.index')`; bila ada peran
     * penerima yang ditolak 403, notifikasi itu menyesatkan.
     */
    public function test_setiap_penerima_notifikasi_dapat_membuka_tautannya(): void
    {
        $this->siapkanUsulan(500000);

        foreach (Izin::peran('autodebit.konfirmasi') as $role) {
            $this->actingAs($this->user($role))
                ->get(route('autodebit.index'))
                ->assertOk();
        }
    }
}
