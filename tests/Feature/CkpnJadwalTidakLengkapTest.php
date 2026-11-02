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
 * CKPN-7 — Jaring pengaman klasifikasi saat jadwal angsuran tidak lengkap.
 *
 * Pembiayaan yang tidak memiliki baris jadwal angsuran sama sekali (data
 * historis/impor, atau jalur pencairan yang tidak membentuk jadwal) sebelumnya
 * SELALU dinilai lancar (kol1) karena `hariTunggakan()` mengembalikan 0 →
 * piutang yang menunggak hanya dicadangkan 0,5% (under-provisioning).
 *
 * Perbaikan: tanggal jatuh tempo kontraktual pada header dipakai sebagai acuan
 * tunggakan HANYA bila jadwal benar-benar kosong.
 */
class CkpnJadwalTidakLengkapTest extends TestCase
{
    use DatabaseTransactions;

    private CkpnService $ckpn;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ckpn = app(CkpnService::class);
        $this->prefix = 'KOSONG'.strtoupper(substr(uniqid(), -6));

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
                'nama_lengkap' => 'Anggota Uji Jadwal Kosong',
                'jenis_kelamin' => 'Laki-laki',
                'alamat' => 'Alamat Uji Jadwal Kosong',
                'kota' => 'Jakarta',
                'provinsi' => 'DKI Jakarta',
                'status_anggota' => 'Aktif',
                'tanggal_gabung' => now()->toDateString(),
            ]
        );
    }

    /**
     * Pembiayaan tercair TANPA satu pun baris jadwal angsuran di tabel `angsuran`.
     */
    private function pembiayaanTanpaJadwal(float $pokokSisa, ?int $jatuhTempoHariLalu): Pembiayaan
    {
        return Pembiayaan::create([
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
            'tanggal_jatuh_tempo' => now()->subDays(max(0, $jatuhTempoHariLalu))->toDateString(),
            'status_pembiayaan' => Pembiayaan::STATUS_AKTIF,
            'sisa_pembiayaan' => $pokokSisa,
            'is_disbursed' => true,
        ]);
    }

    public function test_tanpa_jadwal_dan_jatuh_tempo_terlewat_diklasifikasikan_menunggak(): void
    {
        $ambang = Setting::ambangKolektibilitas();
        $pembiayaan = $this->pembiayaanTanpaJadwal(1_000_000, max(1, $ambang['kol2'] - 1));

        // Tanpa jaring pengaman CKPN-7, ini akan salah dinilai kol1 (lancar).
        $this->assertSame(
            'kol2',
            $this->ckpn->kolektibilitasKode($pembiayaan->fresh()),
            'Piutang tanpa jadwal yang jatuh tempo terlewat harus diklasifikasikan menunggak.'
        );
        $this->assertGreaterThan(0, $this->ckpn->hariTunggakan($pembiayaan->fresh()));
    }

    public function test_tanpa_jadwal_dan_jatuh_tempo_jauh_di_depan_dianggap_lancar(): void
    {
        $pembiayaan = $this->pembiayaanTanpaJadwal(1_000_000, -30); // jatuh tempo 30 hari ke depan
        $this->assertSame(0, $this->ckpn->hariTunggakan($pembiayaan->fresh()));
        $this->assertSame('kol1', $this->ckpn->kolektibilitasKode($pembiayaan->fresh()));
    }

    public function test_tanpa_jadwal_dan_tanpa_jatuh_tempo_aman_lancar(): void
    {
        $pembiayaan = $this->pembiayaanTanpaJadwal(1_000_000, null);

        $this->assertSame(0, $this->ckpn->hariTunggakan($pembiayaan->fresh()));
        $this->assertSame('kol1', $this->ckpn->kolektibilitasKode($pembiayaan->fresh()));
    }

    public function test_jadwal_lengkap_tidak_memakai_fallback_header(): void
    {
        // Ada satu angsuran, sudah dibayar tepat waktu → header (jauh terlewat)
        // TIDAK boleh dipakai; klasifikasi tetap lancar (kol1).
        $pembiayaan = $this->pembiayaanTanpaJadwal(1_000_000, 400);

        Angsuran::create([
            'pembiayaan_id' => $pembiayaan->id,
            'angsuran_ke' => 1,
            'tanggal_jatuh_tempo' => now()->subDays(400)->toDateString(),
            'pokok_angsuran' => 1_000_000,
            'margin_ujroh' => 0,
            'jumlah_angsuran' => 1_000_000,
            'sisa_pokok' => 0,
            'status_angsuran' => 'Sudah Dibayar',
        ]);

        $this->assertSame(0, $this->ckpn->hariTunggakan($pembiayaan->fresh()),
            'Fallback header tidak boleh dipakai bila jadwal sudah ada.');
        $this->assertSame('kol1', $this->ckpn->kolektibilitasKode($pembiayaan->fresh()));
    }

    public function test_ckpn_terbentuk_atas_piutang_tanpa_jadwal_yang_menunggak(): void
    {
        $ambang = Setting::ambangKolektibilitas();
        $pembiayaan = $this->pembiayaanTanpaJadwal(1_000_000, $ambang['kol4'] + 20);

        $this->ckpn->sinkronkanStatusKolektibilitas(now());

        $baris = collect($this->ckpn->rincian(now()))
            ->firstWhere('pembiayaan_id', $pembiayaan->id);

        $this->assertNotNull($baris, 'Piutang tanpa jadwal yang menunggak harus masuk basis CKPN.');
        $this->assertSame('kol5', $baris['kolektibilitas']);
        $this->assertEqualsWithDelta(1_000_000, $baris['ckpn'], 0.01,
            'Piutang kol5 harus disisihkan 100%.');
    }
}
