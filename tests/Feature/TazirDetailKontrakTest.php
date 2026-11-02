<?php

namespace Tests\Feature;

use App\Models\Angsuran;
use App\Models\Coa;
use App\Models\KebijakanKoperasi;
use App\Models\Pembiayaan;
use App\Services\TazirService;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * S-1 — Kontrak `TazirService::detail()` saat fitur denda NONAKTIF.
 *
 * `detail()` dipakai UI teller (JSON `detailAngsuran`) dan konsumen API lain.
 * Sebelum perbaikan, `hari_terlambat` & `hari_efektif` tetap dikirim walau
 * `fiturAktif()` false, sehingga tampak seolah ada keterlambatan yang bisa
 * ditagihkan padahal nominal ta'zir 0.
 *
 * Aturan yang ditegakkan:
 *  - Fitur nonaktif (tarif 0 atau mode DISABLED) → hari 0, efektif 0, mode 'DISABLED'.
 *  - Fitur aktif → hari & nominal dilaporkan apa adanya (grace period dihormati).
 *  - Angsuran sudah dibayar → tidak pernah terlambat.
 */
class TazirDetailKontrakTest extends TestCase
{
    use DatabaseTransactions;

    private TazirService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TazirService::class);
    }

    /** Atur kebijakan ta'zir: mode + tarif per hari + grace period. */
    private function aturKebijakan(string $mode, float $tarifPerHari, int $grace = 0): void
    {
        $kebijakan = KebijakanKoperasi::first() ?? new KebijakanKoperasi;
        $kebijakan->tazir_mode = $mode;
        $kebijakan->financing_late_fee_per_day = $tarifPerHari;
        $kebijakan->tazir_rate = 2500.00;
        $kebijakan->tazir_grace_period_days = $grace;
        $kebijakan->save();

        Setting::flush();
    }

    /** Angsuran menunggak $hariTerlambat hari pada pembiayaan milik anggota yang ada. */
    private function angsuranTerlambat(int $hariTerlambat, ?string $tanggalBayar = null): Angsuran
    {
        $anggotaId = DB::table('anggota')->value('id');

        if (! $anggotaId) {
            $anggotaId = DB::table('anggota')->insertGetId([
                'nama_lengkap' => 'Anggota Uji Ta\'zir',
                'no_anggota' => 'AGT-TZ-'.strtoupper(substr(uniqid(), -6)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => 'PMB-TZ-'.strtoupper(substr(uniqid(), -6)),
            'anggota_id' => $anggotaId,
            'akun_id' => Coa::query()->value('id') ?? 1,
            'akad_pembiayaan' => 'Qardh',
            'jumlah_pembiayaan' => 1_200_000,
            'total_pembiayaan' => 1_200_000,
            'total_ujroh' => 0,
            'sisa_pembiayaan' => 1_200_000,
            'tenor' => 12,
            'jumlah_angsuran' => 100_000,
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths(12)->toDateString(),
            'status_pembiayaan' => 'Aktif',
        ]);

        return Angsuran::create([
            'pembiayaan_id' => $pembiayaan->id,
            'angsuran_ke' => 1,
            'tanggal_jatuh_tempo' => now()->subDays($hariTerlambat)->toDateString(),
            'tanggal_bayar' => $tanggalBayar,
            'pokok_angsuran' => 100_000,
            'margin_ujroh' => 0,
            'jumlah_angsuran' => 100_000,
            'sisa_pokok' => 100_000,
            'status_angsuran' => $tanggalBayar ? 'Sudah Dibayar' : 'Terlambat',
        ]);
    }

    public function test_tarif_nol_tidak_membocorkan_hari_terlambat(): void
    {
        $this->aturKebijakan('FLAT_HARIAN', 0.00);

        $detail = $this->service->detail($this->angsuranTerlambat(30));

        $this->assertSame(0.0, (float) $detail['nominal'], 'Fitur nonaktif tidak boleh menagih ta\'zir.');
        $this->assertSame(0, $detail['hari_terlambat'], 'Hari terlambat tidak boleh dilaporkan saat fitur nonaktif.');
        $this->assertSame(0, $detail['hari_efektif'], 'Hari efektif tidak boleh dilaporkan saat fitur nonaktif.');
        $this->assertSame('DISABLED', $detail['mode'], 'Mode efektif harus DISABLED agar UI menyatakan tidak ada denda.');
    }

    public function test_mode_disabled_menolkan_hari_walau_tarif_positif(): void
    {
        $this->aturKebijakan('DISABLED', 2500.00);

        $detail = $this->service->detail($this->angsuranTerlambat(30));

        $this->assertSame(0.0, (float) $detail['nominal']);
        $this->assertSame(0, $detail['hari_terlambat']);
        $this->assertSame(0, $detail['hari_efektif']);
        $this->assertSame('DISABLED', $detail['mode']);
    }

    public function test_fitur_aktif_melaporkan_hari_dan_nominal_setelah_grace_period(): void
    {
        $this->aturKebijakan('FLAT_HARIAN', 2500.00, grace: 5);

        $detail = $this->service->detail($this->angsuranTerlambat(30));

        $this->assertSame(30, $detail['hari_terlambat']);
        $this->assertSame(5, $detail['grace_period']);
        $this->assertSame(25, $detail['hari_efektif']);
        $this->assertSame(62_500.0, (float) $detail['nominal'], '25 hari × Rp2.500 = Rp62.500.');
        $this->assertSame('FLAT_HARIAN', $detail['mode']);
    }

    public function test_angsuran_sudah_dibayar_tidak_dihitung_terlambat(): void
    {
        $this->aturKebijakan('FLAT_HARIAN', 2500.00);

        $detail = $this->service->detail(
            $this->angsuranTerlambat(30, now()->subDays(2)->toDateString())
        );

        $this->assertSame(0.0, (float) $detail['nominal']);
        $this->assertSame(0, $detail['hari_terlambat']);
        $this->assertSame(0, $detail['hari_efektif']);
        $this->assertSame('FLAT_HARIAN', $detail['mode'], 'Mode tetap dilaporkan bila fitur aktif.');
    }
}
