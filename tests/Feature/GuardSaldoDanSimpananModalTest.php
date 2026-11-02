<?php

namespace Tests\Feature;

use App\Actions\Teller\TarikTunaiAction;
use App\Models\Akun;
use App\Models\Anggota;
use App\Models\AuditLog;
use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\KebijakanKoperasi;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use App\Support\Coa as KodeCoa;
use App\Support\GuardSimpananModal;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * LANGKAH 4 — BATCH 4: GUARD SALDO & SIMPANAN MODAL PADA PENARIKAN TUNAI.
 *
 * Jalur `POST teller/tarik` ({@see TarikTunaiAction})
 * memiliki tiga gerbang yang harus tetap utuh:
 *
 *   1. ALLOWLIST  — hanya rekening Simpanan Sukarela (CoA 2-1001) yang boleh
 *                   ditarik tunai; Simpanan Pokok/Wajib = MODAL/EKUITAS
 *                   anggota (PSAK 101 ¶ 31) dan hanya boleh dikembalikan lewat
 *                   modul pengunduran diri anggota.
 *   2. KAS FISIK  — nominal penarikan tidak boleh melebihi saldo kas laci
 *                   teller (Kas Awal + Cash In − Cash Out).
 *   3. SALDO      — penarikan tidak boleh melebihi saldo rekening dan tidak
 *                   boleh menyisakan saldo di bawah `batas_minimum`.
 *
 * Penolakan pada gerbang mana pun WAJIB: saldo rekening utuh, tidak ada baris
 * `transaksi` baru, dan tidak ada jurnal baru. Berkas ini juga mengunci
 * kontrol positifnya (penarikan sah tetap berhasil & terjurnal) agar
 * penguncian tidak berubah menjadi "aman tetapi memblokir yang sah".
 *
 * Basis data uji memuat data nyata → prefix unik + DatabaseTransactions.
 */
class GuardSaldoDanSimpananModalTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'SALDO'.strtoupper(substr(uniqid(), -6));

        // Ambang minimum transaksi teller dibaca dari kebijakan yang dapat
        // diubah Pengurus. Diturunkan sementara agar nominal uji kecil tetap
        // mencapai lapisan GUARD (bukan tertahan validasi `min:` lebih dulu).
        KebijakanKoperasi::getSetting()->update(['minimum_transaksi_teller' => 1000.00]);
        Setting::flush();
    }

    protected function tearDown(): void
    {
        Setting::flush();

        parent::tearDown();
    }

    /* =====================================================================
     | FIXTURE
     ===================================================================== */

    private function pastikanCoa(): void
    {
        foreach ([KodeCoa::KAS_TELLER, KodeCoa::SIMPANAN_SUKARELA] as $kode) {
            if (! Coa::findByKode($kode)) {
                $this->markTestSkipped("CoA {$kode} belum tersedia — jalankan CoaSeeder.");
            }
        }
    }

    private function teller(float $modalAwal = 2000000): User
    {
        $user = User::create([
            'name' => 'Teller Uji Saldo',
            'email' => strtolower($this->prefix).'.teller@uji.test',
            'password' => Hash::make('rahasia123'),
            'role' => 'teller',
            'is_active' => true,
        ]);

        // Rute `teller.tarik.store` dijaga middleware `kas.terbuka`.
        $this->bukaSesiKas($user, null, $modalAwal);

        return $user;
    }

    private function akunId(): int
    {
        $id = Akun::query()->value('id');

        if (! $id) {
            $this->markTestSkipped('Tabel akun kosong — jalankan AkunSeeder.');
        }

        return (int) $id;
    }

    private function anggota(): Anggota
    {
        return Anggota::create([
            'no_anggota' => substr('AGT-'.$this->prefix, 0, 20),
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Uji Saldo '.$this->prefix,
            'jenis_kelamin' => 'Perempuan',
            'alamat' => 'Jl. Uji Guard Saldo No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_ACTIVE,
            'tanggal_gabung' => '2020-01-01',
        ]);
    }

    private function rekening(
        Anggota $anggota,
        string $jenis = 'Simpanan Sukarela',
        float $saldo = 1000000,
        float $batasMinimum = 0,
        string $status = 'Aktif'
    ): Rekening {
        return Rekening::create([
            'no_rekening' => 'RKN-'.strtoupper(substr(uniqid(), -9)),
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunId(),
            'jenis_rekening' => $jenis,
            'akad' => 'Wadiah',
            'saldo' => $saldo,
            'batas_minimum' => $batasMinimum,
            'tanggal_buka' => now()->toDateString(),
            'status_rekening' => $status,
        ]);
    }

    private function tarik(User $teller, Rekening $rekening, float $jumlah)
    {
        return $this->actingAs($teller)
            ->from(route('teller.tarik'))
            ->post(route('teller.tarik.store'), [
                'rekening_id' => $rekening->id,
                'jumlah' => $jumlah,
            ]);
    }

    /* =====================================================================
     | 1. KONTROL POSITIF — penarikan sah tetap berjalan & terjurnal
     ===================================================================== */

    public function test_penarikan_sukarela_yang_sah_berhasil_dan_terjurnal(): void
    {
        $this->pastikanCoa();

        $teller = $this->teller(2000000);
        $rekening = $this->rekening($this->anggota(), 'Simpanan Sukarela', 1000000);

        $this->tarik($teller, $rekening, 100000)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(900000.0, round((float) $rekening->fresh()->saldo, 2),
            'Saldo rekening harus berkurang tepat sebesar nominal penarikan.');

        $transaksi = Transaksi::where('rekening_id', $rekening->id)
            ->where('jenis_transaksi', 'Penarikan Tunai')
            ->latest('id')
            ->first();

        $this->assertNotNull($transaksi, 'Penarikan sah harus mencatat baris transaksi.');
        $this->assertSame('100000.00', (string) $transaksi->jumlah);
        $this->assertSame(Transaksi::STATUS_TRANSAKSI_SUKSES, $transaksi->status_transaksi);
        $this->assertSame('900000.00', (string) $transaksi->saldo_setelah);

        $jurnal = Jurnal::where('transaksi_id', $transaksi->id)->first();

        $this->assertNotNull($jurnal, 'Penarikan sah wajib menghasilkan jurnal.');
        $this->assertSame('Posted', $jurnal->status);
    }

    /* =====================================================================
     | 2. GUARD SALDO — melebihi saldo / menyisakan di bawah batas minimum
     ===================================================================== */

    public function test_penarikan_melebihi_saldo_ditolak_dan_saldo_utuh(): void
    {
        $this->pastikanCoa();

        $teller = $this->teller(2000000);
        $rekening = $this->rekening($this->anggota(), 'Simpanan Sukarela', 1000000);

        $transaksiSebelum = Transaksi::where('rekening_id', $rekening->id)->count();

        $this->tarik($teller, $rekening, 1500000)
            ->assertRedirect(route('teller.tarik'))
            ->assertSessionHasErrors('jumlah');

        $this->assertSame(1000000.0, round((float) $rekening->fresh()->saldo, 2),
            'Saldo tidak boleh berubah saat penarikan melebihi saldo ditolak.');
        $this->assertSame(
            $transaksiSebelum,
            Transaksi::where('rekening_id', $rekening->id)->count(),
            'Penarikan yang ditolak tidak boleh menyisakan baris transaksi.'
        );
    }

    public function test_penarikan_yang_menyisakan_di_bawah_batas_minimum_ditolak(): void
    {
        $this->pastikanCoa();

        $teller = $this->teller(2000000);
        $rekening = $this->rekening($this->anggota(), 'Simpanan Sukarela', 1000000, 200000);

        $this->tarik($teller, $rekening, 900000)
            ->assertRedirect(route('teller.tarik'))
            ->assertSessionHasErrors('jumlah');

        $this->assertSame(1000000.0, round((float) $rekening->fresh()->saldo, 2),
            'Penarikan yang menembus batas minimum tidak boleh mengurangi saldo.');
    }

    public function test_penarikan_sampai_batas_minimum_tetap_diizinkan(): void
    {
        $this->pastikanCoa();

        $teller = $this->teller(2000000);
        $rekening = $this->rekening($this->anggota(), 'Simpanan Sukarela', 1000000, 200000);

        $this->tarik($teller, $rekening, 800000)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(200000.0, round((float) $rekening->fresh()->saldo, 2),
            'Sisa saldo tepat di batas minimum masih sah.');
    }

    /* =====================================================================
     | 3. GUARD KAS FISIK TELLER
     ===================================================================== */

    public function test_penarikan_melebihi_kas_fisik_teller_ditolak(): void
    {
        $this->pastikanCoa();

        // Kas laci hanya Rp 50.000, sementara saldo rekening Rp 1.000.000.
        $teller = $this->teller(50000);
        $rekening = $this->rekening($this->anggota(), 'Simpanan Sukarela', 1000000);

        $this->tarik($teller, $rekening, 100000)
            ->assertRedirect(route('teller.tarik'))
            ->assertSessionHasErrors('jumlah');

        $this->assertSame(1000000.0, round((float) $rekening->fresh()->saldo, 2),
            'Kas teller tidak cukup → saldo anggota tidak boleh berkurang.');
    }

    /* =====================================================================
     | 4. REKENING TIDAK AKTIF
     ===================================================================== */

    public function test_penarikan_dari_rekening_tidak_aktif_ditolak(): void
    {
        $this->pastikanCoa();

        $teller = $this->teller(2000000);
        $anggota = $this->anggota();

        // `tarikForm()` menyaring status Aktif, tetapi `tarikStore()` hanya
        // memvalidasi keberadaan rekening. Status non-Aktif wajib ditolak di
        // lapisan Action agar request langsung tidak menembusnya (A-03).
        foreach (['Ditutup', 'Dibekukan', 'Jatuh Tempo'] as $status) {
            $rekening = $this->rekening($anggota, 'Simpanan Sukarela', 500000, 0, $status);

            $this->tarik($teller, $rekening, 100000)
                ->assertRedirect(route('teller.tarik'))
                ->assertSessionHasErrors('jumlah');

            $this->assertSame(500000.0, round((float) $rekening->fresh()->saldo, 2),
                "Rekening berstatus {$status} tidak boleh ditarik.");
            $this->assertSame(0, Transaksi::where('rekening_id', $rekening->id)->count(),
                "Penarikan pada rekening {$status} tidak boleh meninggalkan baris transaksi.");
        }
    }

    /* =====================================================================
     | 5. GUARD SIMPANAN MODAL (regresi terpusat)
     ===================================================================== */

    public function test_penarikan_rekening_modal_ditolak_dan_tercatat_audit(): void
    {
        $this->pastikanCoa();

        $teller = $this->teller(2000000);
        $anggota = $this->anggota();

        foreach (['Simpanan Pokok', 'Simpanan Wajib'] as $jenis) {
            $rekening = $this->rekening($anggota, $jenis, 500000);
            $auditSebelum = AuditLog::where('jenis_transaksi', 'PENARIKAN_MODAL_DIBLOKIR')->count();

            $this->tarik($teller, $rekening, 100000)
                ->assertRedirect(route('teller.tarik'))
                ->assertSessionHasErrors(['jumlah' => GuardSimpananModal::PESAN]);

            $this->assertSame(500000.0, round((float) $rekening->fresh()->saldo, 2),
                "Rekening {$jenis} adalah MODAL/EKUITAS dan tidak boleh berkurang.");
            $this->assertGreaterThan(
                $auditSebelum,
                AuditLog::where('jenis_transaksi', 'PENARIKAN_MODAL_DIBLOKIR')->count(),
                "Upaya penarikan {$jenis} wajib tercatat di audit_log."
            );
        }

        // Allowlist tetap sempit: hanya Simpanan Sukarela yang boleh ditarik.
        $this->assertSame(
            ['Simpanan Sukarela'],
            GuardSimpananModal::jenisRekeningDapatDitarik(),
            'Allowlist penarikan tunai tidak boleh melebar.'
        );
    }
}
