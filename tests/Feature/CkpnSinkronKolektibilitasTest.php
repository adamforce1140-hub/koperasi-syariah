<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\Coa as CoaModel;
use App\Models\Pembiayaan;
use App\Services\CkpnService;
use App\Support\Coa;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * CKPN-4 — Sinkronisasi status kolektibilitas & status MACET otomatis.
 *
 * Mengunci agar piutang yang menunggak melewati ambang kualitas Macet (kol5)
 * benar-benar dikenali sistem — sebelumnya status `macet` tidak pernah
 * di-assign otomatis sehingga NPF & daftar penagihan tidak akurat, dan CKPN
 * tidak pernah terbentuk atas eksposur terbesar.
 */
class CkpnSinkronKolektibilitasTest extends TestCase
{
    use DatabaseTransactions;

    private CkpnService $ckpn;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ckpn = app(CkpnService::class);
        $this->prefix = 'SINKRON'.strtoupper(substr(uniqid(), -6));

        if (! CoaModel::findByKode(Coa::PIUTANG_MURABAHAH)) {
            $this->markTestSkipped('CoA 1-2001 belum ter-seed.');
        }
    }

    private function anggota(): Anggota
    {
        return Anggota::firstOrCreate(
            ['no_anggota' => $this->prefix.'AG'],
            [
                'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
                'nama_lengkap' => 'Anggota Uji Sinkron',
                'jenis_kelamin' => 'Laki-laki',
                'alamat' => 'Alamat Uji Sinkron',
                'kota' => 'Jakarta',
                'provinsi' => 'DKI Jakarta',
                'status_anggota' => 'Aktif',
                'tanggal_gabung' => now()->toDateString(),
            ]
        );
    }

    /**
     * Pembiayaan Murabahah tercair dengan satu angsuran menunggak $hari hari.
     */
    private function pembiayaanMenunggak(
        float $pokokSisa,
        int $hari,
        string $status = Pembiayaan::STATUS_AKTIF
    ): Pembiayaan {
        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => $this->prefix.'-'.random_int(1000, 9999),
            'anggota_id' => $this->anggota()->id,
            'akun_id' => CoaModel::query()->value('id') ?? 1,
            'akad_pembiayaan' => Pembiayaan::AKAD_MURABAHAH,
            'jumlah_pembiayaan' => $pokokSisa,
            'total_pembiayaan' => $pokokSisa,
            'tenor' => 12,
            'jumlah_angsuran' => round($pokokSisa / 12, 2),
            'tanggal_akad' => now()->subMonths(12)->toDateString(),
            'tanggal_cair' => now()->subMonths(12)->toDateString(),
            'tanggal_jatuh_tempo' => now()->subDays($hari)->toDateString(),
            'status_pembiayaan' => $status,
            'sisa_pembiayaan' => $pokokSisa,
            'is_disbursed' => true,
        ]);

        if ($hari > 0) {
            Angsuran::create([
                'pembiayaan_id' => $pembiayaan->id,
                'angsuran_ke' => 1,
                'tanggal_jatuh_tempo' => now()->subDays($hari)->toDateString(),
                'pokok_angsuran' => $pokokSisa,
                'margin_ujroh' => 0,
                'jumlah_angsuran' => $pokokSisa,
                'sisa_pokok' => $pokokSisa,
                'status_angsuran' => 'Jatuh Tempo',
            ]);
        }

        return $pembiayaan->fresh();
    }

    public function test_tunggakan_melewati_ambang_kol5_dinaikkan_menjadi_macet(): void
    {
        $ambang = Setting::ambangKolektibilitas();
        $pembiayaan = $this->pembiayaanMenunggak(1_000_000, $ambang['kol4'] + 20);

        $hasil = $this->ckpn->sinkronkanStatusKolektibilitas(now());

        $this->assertSame(1, $hasil['macet'], 'Satu pembiayaan harus dikenai status macet.');
        $this->assertSame(Pembiayaan::STATUS_MACET, $pembiayaan->fresh()->status_pembiayaan);
        $this->assertSame('kol5', $this->ckpn->kolektibilitasKode($pembiayaan->fresh()));
    }

    public function test_piutang_lancar_tetap_aktif(): void
    {
        $pembiayaan = $this->pembiayaanMenunggak(1_000_000, 0);

        $this->ckpn->sinkronkanStatusKolektibilitas(now());

        $this->assertSame(Pembiayaan::STATUS_AKTIF, $pembiayaan->fresh()->status_pembiayaan);
    }

    public function test_piutang_macet_tetap_masuk_basis_ckpn_seratus_persen(): void
    {
        $ambang = Setting::ambangKolektibilitas();
        $pembiayaan = $this->pembiayaanMenunggak(1_000_000, $ambang['kol4'] + 20);

        $this->ckpn->sinkronkanStatusKolektibilitas(now());

        $baris = collect($this->ckpn->rincian(now()))
            ->firstWhere('pembiayaan_id', $pembiayaan->id);

        $this->assertNotNull($baris, 'Piutang macet tidak boleh hilang dari basis CKPN.');
        $this->assertSame('kol5', $baris['kolektibilitas']);
        $this->assertEqualsWithDelta(1_000_000, $baris['ckpn'], 0.01,
            'Piutang macet harus disisihkan 100%.');
    }

    public function test_tunggakan_lunas_memulihkan_status_macet_ke_aktif(): void
    {
        $pembiayaan = $this->pembiayaanMenunggak(1_000_000, 0, Pembiayaan::STATUS_MACET);

        $this->ckpn->sinkronkanStatusKolektibilitas(now());

        $this->assertSame(Pembiayaan::STATUS_AKTIF, $pembiayaan->fresh()->status_pembiayaan);
    }

    public function test_dry_run_tidak_mengubah_status(): void
    {
        $ambang = Setting::ambangKolektibilitas();
        $pembiayaan = $this->pembiayaanMenunggak(1_000_000, $ambang['kol4'] + 20);

        $hasil = $this->ckpn->sinkronkanStatusKolektibilitas(now(), false);

        $this->assertSame(1, $hasil['macet'], 'Dry-run tetap melaporkan rencana perubahan.');
        $this->assertSame(Pembiayaan::STATUS_AKTIF, $pembiayaan->fresh()->status_pembiayaan,
            'Dry-run tidak boleh menyimpan perubahan.');
    }

    public function test_command_penyesuaian_dry_run_tidak_mengubah_status(): void
    {
        $ambang = Setting::ambangKolektibilitas();
        $pembiayaan = $this->pembiayaanMenunggak(1_000_000, $ambang['kol4'] + 20);

        $this->artisan('ckpn:hitung-penyesuaian', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(Pembiayaan::STATUS_AKTIF, $pembiayaan->fresh()->status_pembiayaan);
    }
}
