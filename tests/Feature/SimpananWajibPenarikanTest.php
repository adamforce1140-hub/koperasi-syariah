<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\AuditLog;
use App\Models\Coa;
use App\Models\KebijakanKoperasi;
use App\Models\MandatorySavingsBill;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\ExitKeanggotaanService;
use App\Services\MandatorySavingsService;
use App\Support\Coa as KodeCoa;
use App\Support\GuardSimpananModal;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PENARIKAN RUTIN Simpanan Wajib (CoA 3-1002) — WAJIB DIBLOKIR TOTAL.
 *
 * Simpanan Wajib adalah MODAL/EKUITAS anggota koperasi syariah, bukan
 * kewajiban jangka pendek yang dapat ditarik kapan saja (PSAK 101 ¶ 31).
 * Sejak penguncian permanen, TIDAK ADA saklar kebijakan, status anggota,
 * maupun jalur HTTP/service lain yang dapat mengurangi saldo 3-1002 secara
 * rutin. Satu-satunya jalur sah adalah modul `member_terminations`
 * (lihat {@see ExitKeanggotaanService}).
 *
 * Berkas ini mengunci janji tersebut:
 *  1. Pesan penolakan identik di seluruh lapisan (GuardSimpananModal::PESAN).
 *  2. Endpoint loket `teller.simpanan-wajib.tarik` selalu ditolak:
 *     HTTP 302 + flash `error`; saldo TIDAK berubah; tidak ada Transaksi baru.
 *  3. Service `tarikSimpananWajib()` melempar RuntimeException — sehingga
 *     jalur non-HTTP (job, tinker, command) ikut terkunci.
 *  4. Upaya tersebut TERLACAK: baris `audit_log` jenis
 *     `PENARIKAN_MODAL_DIBLOKIR` (audit keamanan terpusat).
 *  5. Saklar kebijakan warisan (`simpanan_wajib_boleh_ditarik` = true) TIDAK
 *     lagi mampu membuka gerbang → bukti penguncian permanen.
 *  6. RBAC: pengguna tanpa hak loket tetap mendapat 403.
 *
 * Basis data memuat data nyata → prefix unik + DatabaseTransactions.
 */
class SimpananWajibPenarikanTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'TARIKWJB'.strtoupper(substr(uniqid(), -6));

        // Saklar kebijakan TIDAK boleh bocor antar-test.
        Setting::flush();
    }

    protected function tearDown(): void
    {
        Setting::flush();

        parent::tearDown();
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    private function user(string $role = 'teller'): User
    {
        $user = User::firstOrCreate(
            ['email' => "{$role}.{$this->prefix}@test.local"],
            [
                'name' => ucfirst($role).' '.$this->prefix,
                'password' => Hash::make('password'),
                'role' => $role,
            ]
        );

        // Rute `teller.simpanan-wajib.tarik` dijaga middleware `kas.terbuka`.
        if ($role === 'teller') {
            $this->bukaSesiKas($user);
        }

        return $user;
    }

    private function pastikanCoa(): void
    {
        foreach ([KodeCoa::KAS_TELLER, KodeCoa::SIMPANAN_WAJIB_MODAL] as $kode) {
            if (! Coa::findByKode($kode)) {
                $this->markTestSkipped("CoA {$kode} belum tersedia — jalankan CoaSeeder.");
            }
        }
    }

    private function anggota(string $status = Anggota::STATUS_ACTIVE): Anggota
    {
        return Anggota::create([
            'no_anggota' => 'AGT-'.$this->prefix,
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Tarik '.$this->prefix,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Alamat Uji Penarikan Simpanan Wajib',
            'kota' => 'Jakarta',
            'provinsi' => 'DKI Jakarta',
            'status_anggota' => $status,
            'is_auto_debit' => false,
            'tanggal_gabung' => '2026-01-01',
        ]);
    }

    /**
     * Buka gembok kebijakan WARISAN (`simpanan_wajib_boleh_ditarik` = true).
     *
     * Sejak penguncian permanen, saklar ini TIDAK lagi berpengaruh — justru
     * dipakai di sini sebagai bukti bahwa penguncian benar-benar permanen.
     */
    private function izinkanPenarikan(string $hariPenarikan = 'KAPAN_SAJA'): void
    {
        $kebijakan = KebijakanKoperasi::first() ?? new KebijakanKoperasi;
        $kebijakan->simpanan_wajib_boleh_ditarik = true;
        $kebijakan->simpanan_wajib_hari_penarikan = $hariPenarikan;
        $kebijakan->save();

        Setting::flush();
    }

    /**
     * Setor Simpanan Wajib untuk membentuk saldo Modal Anggota.
     * Memakai jalur produksi `bayarViaTeller()` agar saldo yang diuji
     * benar-benar berasal dari jurnal PSAK 101, bukan data sintetis.
     */
    private function setor(Anggota $anggota, float $nominal, string $periode = '2026-01'): MandatorySavingsBill
    {
        $tagihan = MandatorySavingsBill::create([
            'member_id' => $anggota->id,
            'bill_period' => $periode,
            'amount' => $nominal,
            'status' => MandatorySavingsBill::STATUS_UNPAID,
        ]);

        app(MandatorySavingsService::class)->bayarViaTeller(
            $tagihan,
            MandatorySavingsBill::METODE_CASH,
            null,
            $this->user()->id
        );

        return $tagihan->refresh();
    }

    private function saldo(Anggota $anggota): float
    {
        return app(MandatorySavingsService::class)->saldoSimpananWajib($anggota);
    }

    /* =====================================================================
     | 1. BLOKIR ENDPOINT LOKET (teller.simpanan-wajib.tarik)
     |===================================================================== */

    public function test_penarikan_ditolak_saat_kebijakan_masih_nonaktif(): void
    {
        $this->pastikanCoa();
        $anggota = $this->anggota();
        $this->setor($anggota, 50000);

        // Saklar default NONAKTIF — jangan diubah.
        $this->assertFalse(Setting::simpananWajibBolehDitarik(), 'Default kebijakan harus NONAKTIF.');

        $saldoAwal = $this->saldo($anggota);
        $this->assertSame(50000.0, $saldoAwal);

        $this->actingAs($this->user())
            ->post(route('teller.simpanan-wajib.tarik', $anggota->id), [
                'metode' => MandatorySavingsBill::METODE_CASH,
            ])
            ->assertSessionHas('error');

        $this->assertSame($saldoAwal, $this->saldo($anggota), 'Saldo tidak boleh berubah saat penarikan ditolak.');
    }

    public function test_service_menolak_penarikan_saat_kebijakan_nonaktif(): void
    {
        $this->pastikanCoa();
        $anggota = $this->anggota();
        $this->setor($anggota, 50000);

        $this->expectException(\RuntimeException::class);

        app(MandatorySavingsService::class)->tarikSimpananWajib($anggota, 10000);
    }

    /**
     * 🔒 INTI PENGUNCIAN PERMANEN.
     *
     * Meski SELURUH gerbang warisan dibuka (saklar `boleh_ditarik` = true dan
     * waktu penarikan `KAPAN_SAJA` untuk anggota AKTIF), penarikan tetap
     * DITOLAK dengan pesan standar GuardSimpananModal.
     */
    public function test_anggota_aktif_tetap_diblokir_meski_kebijakan_dibuka(): void
    {
        $this->pastikanCoa();
        $this->izinkanPenarikan('KAPAN_SAJA');

        $anggota = $this->anggota(Anggota::STATUS_ACTIVE);
        $this->setor($anggota, 50000);

        $this->actingAs($this->user())
            ->post(route('teller.simpanan-wajib.tarik', $anggota->id), [
                'jumlah' => 10000,
                'metode' => MandatorySavingsBill::METODE_CASH,
            ])
            ->assertSessionHas('error', GuardSimpananModal::PESAN);

        $this->assertSame(50000.0, $this->saldo($anggota), 'Saldo Modal Anggota tidak boleh berkurang.');
    }

    /**
     * 🔒 Anggota yang sudah berstatus Keluar pun tetap ditolak pada jalur
     * penarikan RUTIN — pengembalian dananya hanya sah lewat
     * `member_terminations` (PSAK 108).
     */
    public function test_anggota_keluar_tetap_diblokir_di_jalur_penarikan_rutin(): void
    {
        $this->pastikanCoa();
        $this->izinkanPenarikan('SETELAH_KELUAR');

        $anggota = $this->anggota(Anggota::STATUS_KELUAR);
        $this->setor($anggota, 50000);

        $this->actingAs($this->user())
            ->post(route('teller.simpanan-wajib.tarik', $anggota->id), [
                'jumlah' => 10000,
                'metode' => MandatorySavingsBill::METODE_CASH,
            ])
            ->assertSessionHas('error', GuardSimpananModal::PESAN);

        $this->assertSame(
            50000.0,
            $this->saldo($anggota),
            'Pengembalian dana wajib lewat modul member_terminations, bukan loket.'
        );
    }

    public function test_service_tetap_menolak_meski_kebijakan_dibuka(): void
    {
        $this->pastikanCoa();
        $this->izinkanPenarikan('KAPAN_SAJA');

        $anggota = $this->anggota(Anggota::STATUS_ACTIVE);
        $this->setor($anggota, 50000);

        try {
            app(MandatorySavingsService::class)->tarikSimpananWajib($anggota, 10000);
            $this->fail('Penarikan rutin Simpanan Wajib seharusnya DITOLAK.');
        } catch (\RuntimeException $e) {
            $this->assertSame(GuardSimpananModal::PESAN, $e->getMessage());
        }

        $this->assertSame(50000.0, $this->saldo($anggota));
    }

    /* =====================================================================
     | 2. AUDIT KEAMANAN TERPUSAT
     |===================================================================== */

    public function test_upaya_penarikan_yang_diblokir_tercatat_di_audit_log(): void
    {
        $this->pastikanCoa();
        $anggota = $this->anggota();
        $this->setor($anggota, 50000);

        $before = AuditLog::where('jenis_transaksi', 'PENARIKAN_MODAL_DIBLOKIR')->count();

        $this->actingAs($this->user())
            ->post(route('teller.simpanan-wajib.tarik', $anggota->id), [
                'jumlah' => 10000,
                'metode' => MandatorySavingsBill::METODE_CASH,
            ])
            ->assertSessionHas('error');

        $this->assertGreaterThan(
            $before,
            AuditLog::where('jenis_transaksi', 'PENARIKAN_MODAL_DIBLOKIR')->count(),
            'Setiap upaya penarikan modal yang diblokir WAJIB tercatat untuk audit keamanan.'
        );
    }

    /* =====================================================================
     | 3. KEGAGALAN TIDAK MENINGGALKAN JEJAK
     |===================================================================== */

    public function test_penarikan_diblokir_tidak_menyisakan_baris_transaksi(): void
    {
        $this->pastikanCoa();
        $this->izinkanPenarikan('KAPAN_SAJA');

        $anggota = $this->anggota();
        $this->setor($anggota, 50000);

        $transaksiSebelum = Transaksi::where('anggota_id', $anggota->id)->count();

        $this->actingAs($this->user())
            ->post(route('teller.simpanan-wajib.tarik', $anggota->id), [
                'jumlah' => 10000,
                'metode' => MandatorySavingsBill::METODE_CASH,
            ])
            ->assertSessionHas('error');

        $this->assertSame(
            $transaksiSebelum,
            Transaksi::where('anggota_id', $anggota->id)->count(),
            'Penarikan yang diblokir tidak boleh menyisakan baris transaksi.'
        );
        $this->assertSame(50000.0, $this->saldo($anggota));
    }

    /* =====================================================================
     | 4. VALIDASI MASUKAN & RBAC
     |===================================================================== */

    public function test_metode_penarikan_tidak_sah_ditolak(): void
    {
        $this->pastikanCoa();
        $this->izinkanPenarikan();

        $anggota = $this->anggota();
        $this->setor($anggota, 50000);

        $this->actingAs($this->user())
            ->post(route('teller.simpanan-wajib.tarik', $anggota->id), [
                'jumlah' => 10000,
                'metode' => MandatorySavingsBill::METODE_AUTO_DEBIT,
            ])
            ->assertSessionHasErrors('metode');

        $this->assertSame(50000.0, $this->saldo($anggota));
    }

    public function test_anggota_tidak_berhak_mengakses_penarikan(): void
    {
        $this->pastikanCoa();
        $this->izinkanPenarikan();

        $anggota = $this->anggota();
        $this->setor($anggota, 50000);

        $this->actingAs($this->user('anggota'))
            ->post(route('teller.simpanan-wajib.tarik', $anggota->id), [
                'metode' => MandatorySavingsBill::METODE_CASH,
            ])
            ->assertForbidden();

        $this->assertSame(50000.0, $this->saldo($anggota));
    }
}
