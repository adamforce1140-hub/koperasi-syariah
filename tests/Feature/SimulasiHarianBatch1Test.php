<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\Coa;
use App\Models\KasHarian;
use App\Models\Rekening;
use App\Models\TellerOpname;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * SIMULASI HARIAN — BATCH 1: TELLER DAN MANAJER.
 *
 * Alur harian lewat HTTP nyata:
 *   Teller buka kas -> setor tunai -> ajukan closing (opname) -> Manajer approve.
 *
 * Tanggal dikunci ke hari kerja (Asia/Jakarta) agar deterministik.
 * Basis data uji: koperasi_syariah_test (phpunit.xml).
 */
class SimulasiHarianBatch1Test extends TestCase
{
    use DatabaseTransactions;

    private const TANGGAL = '2026-10-19';

    private ?int $akunLegacyId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::TANGGAL.' 09:00:00', 'Asia/Jakarta'));
    }

    private function pengguna(string $peran, string $suffix): User
    {
        return User::firstOrCreate(
            ['email' => "simulasi.{$suffix}@test.local"],
            [
                'name' => 'Simulasi '.ucfirst($suffix),
                'password' => Hash::make('password'),
                'role' => $peran,
                'is_active' => true,
            ],
        );
    }

    private function akunLegacyId(): int
    {
        if ($this->akunLegacyId === null) {
            $id = Akun::query()->value('id');

            if (! $id) {
                $this->markTestSkipped('Tabel akun (legacy) belum berisi data.');
            }

            $this->akunLegacyId = (int) $id;
        }

        return $this->akunLegacyId;
    }

    private function rekeningSukarela(float $saldo = 0.0): Rekening
    {
        $anggota = Anggota::create([
            'no_anggota' => 'SIM-'.strtoupper(substr(uniqid(), -6)),
            'nik' => 'NIK'.substr(md5(uniqid('', true)), 0, 12),
            'nama_lengkap' => 'Anggota Simulasi Batch1',
            'jenis_kelamin' => 'Perempuan',
            'alamat' => 'Jl. Simulasi No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'no_telepon' => '081200000001',
            'status_anggota' => 'Aktif',
            'tanggal_gabung' => self::TANGGAL,
        ]);

        return Rekening::create([
            'no_rekening' => 'SIM-'.strtoupper(substr(uniqid(), -8)),
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunLegacyId(),
            'jenis_rekening' => 'Simpanan Sukarela',
            'akad' => 'Wadiah',
            'saldo' => $saldo,
            'batas_minimum' => 0,
            'tanggal_buka' => self::TANGGAL,
            'status_rekening' => 'Aktif',
        ]);
    }

    /** Rincian pecahan persis sama dengan nominal (hanya pecahan 100.000). */
    private function pecahanUntuk(int $nominal): array
    {
        return ['100000' => intdiv($nominal, 100000)];
    }

    /* ═══════════════════════════════════════════════════════════════════
     * 1. Teller membuka kas harian (POST teller.kas.opening.store)
     * ═══════════════════════════════════════════════════════════════════ */
    public function test_teller_dapat_membuka_kas_harian(): void
    {
        $teller = $this->pengguna('teller', 'teller1');
        KasHarian::where('user_id', $teller->id)->whereDate('tanggal', self::TANGGAL)->delete();

        $this->actingAs($teller)
            ->post(route('teller.kas.opening.store'), ['modal_awal' => 1000000])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('kas_harian', [
            'user_id' => $teller->id,
            'tanggal' => self::TANGGAL,
            'modal_awal' => 1000000,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════
     * 2. Setor tunai setelah kas dibuka (POST teller.setor.store)
     * ═══════════════════════════════════════════════════════════════════ */
    public function test_teller_dapat_menyetor_tunai_setelah_kas_dibuka(): void
    {
        $teller = $this->pengguna('teller', 'teller2');
        KasHarian::where('user_id', $teller->id)->whereDate('tanggal', self::TANGGAL)->delete();
        KasHarian::create([
            'user_id' => $teller->id,
            'tanggal' => self::TANGGAL,
            'modal_awal' => 1000000,
            'tambah_modal' => [],
            'waktu_opening' => '09:00:00',
            'status_opname' => KasHarian::STATUS_OPEN,
        ]);
        $rekening = $this->rekeningSukarela();

        $this->actingAs($teller)
            ->post(route('teller.setor.store'), [
                'rekening_id' => $rekening->id,
                'jumlah' => 500000,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(500000.0, (float) $rekening->fresh()->saldo);
    }

    /* ═══════════════════════════════════════════════════════════════════
     * 3. Teller mengajukan closing sesuai saldo sistem -> PENDING_APPROVAL
     * ═══════════════════════════════════════════════════════════════════ */
    public function test_teller_mengajukan_closing_sesuai_saldo_sistem(): void
    {
        $teller = $this->pengguna('teller', 'teller3');
        KasHarian::where('user_id', $teller->id)->whereDate('tanggal', self::TANGGAL)->delete();
        KasHarian::create([
            'user_id' => $teller->id,
            'tanggal' => self::TANGGAL,
            'modal_awal' => 1000000,
            'tambah_modal' => [],
            'waktu_opening' => '09:00:00',
            'status_opname' => KasHarian::STATUS_OPEN,
        ]);

        $this->actingAs($teller)
            ->post(route('teller.kas.closing.store'), [
                'tanggal' => self::TANGGAL,
                'rincian_pecahan' => $this->pecahanUntuk(1000000),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('teller_opnames', [
            'user_id' => $teller->id,
            'status' => TellerOpname::STATUS_PENDING,
        ]);
        $this->assertSame(KasHarian::STATUS_PENDING_APPROVAL, KasHarian::where('user_id', $teller->id)
            ->whereDate('tanggal', self::TANGGAL)->value('status_opname'));
    }

    /* ═══════════════════════════════════════════════════════════════════
     * 4. Manajer menyetujui closing -> jurnal terbentuk, sesi CLOSED
     * ═══════════════════════════════════════════════════════════════════ */
    public function test_manajer_menyetujui_closing_dan_membentuk_jurnal(): void
    {
        $teller = $this->pengguna('teller', 'teller4');
        $manajer = $this->pengguna('manajer', 'manajer4');

        if (Coa::findByKode('1-1003') === null || Coa::findByKode('1-1001') === null) {
            $this->markTestSkipped('CoA kas (1-1001 / 1-1003) belum tersedia.');
        }

        KasHarian::where('user_id', $teller->id)->whereDate('tanggal', self::TANGGAL)->delete();
        $kas = KasHarian::create([
            'user_id' => $teller->id,
            'tanggal' => self::TANGGAL,
            'modal_awal' => 1000000,
            'tambah_modal' => [],
            'waktu_opening' => '09:00:00',
            'status_opname' => KasHarian::STATUS_PENDING_APPROVAL,
        ]);

        $opname = TellerOpname::create([
            'kas_harian_id' => $kas->id,
            'user_id' => $teller->id,
            'tanggal' => self::TANGGAL,
            'rincian_pecahan' => $this->pecahanUntuk(1000000),
            'total_fisik' => 1000000,
            'saldo_sistem' => 1000000,
            'selisih' => 0,
            'status' => TellerOpname::STATUS_PENDING,
        ]);

        $this->actingAs($manajer)
            ->post(route('manajer.closing.approve', $opname->id), ['catatan_approval' => 'Sesuai'])
            ->assertSessionHas('success');

        $this->assertSame(TellerOpname::STATUS_CLOSED, $opname->fresh()->status);
        $this->assertNotNull($opname->fresh()->jurnal_id);
        $this->assertSame(KasHarian::STATUS_CLOSED, KasHarian::where('user_id', $teller->id)
            ->whereDate('tanggal', self::TANGGAL)->value('status_opname'));
    }

    /* ═══════════════════════════════════════════════════════════════════
     * 5. Teller tidak boleh menyetujui closing kasnya sendiri
     * ═══════════════════════════════════════════════════════════════════ */
    public function test_teller_tidak_boleh_menyetujui_closing_sendiri(): void
    {
        $teller = $this->pengguna('teller', 'teller5');
        KasHarian::where('user_id', $teller->id)->whereDate('tanggal', self::TANGGAL)->delete();
        $kas = KasHarian::create([
            'user_id' => $teller->id,
            'tanggal' => self::TANGGAL,
            'modal_awal' => 1000000,
            'tambah_modal' => [],
            'waktu_opening' => '09:00:00',
            'status_opname' => KasHarian::STATUS_PENDING_APPROVAL,
        ]);

        $opname = TellerOpname::create([
            'kas_harian_id' => $kas->id,
            'user_id' => $teller->id,
            'tanggal' => self::TANGGAL,
            'rincian_pecahan' => $this->pecahanUntuk(1000000),
            'total_fisik' => 1000000,
            'saldo_sistem' => 1000000,
            'selisih' => 0,
            'status' => TellerOpname::STATUS_PENDING,
        ]);

        $this->actingAs($teller)
            ->post(route('manajer.closing.approve', $opname->id))
            ->assertStatus(403);

        $this->assertSame(TellerOpname::STATUS_PENDING, $opname->fresh()->status);
    }
}
