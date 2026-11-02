<?php

namespace Tests\Feature;

use App\Models\KebijakanKoperasi;
use App\Models\User;
use App\Models\ZakatTransaksi;
use App\Services\ZakatService;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * R-06 (temuan Z-08) — Porsi/hak AMIL atas dana zakat.
 *
 * Fatwa DSN-MUI 08: amil termasuk salah satu dari 8 asnaf dan berhak atas
 * bagian dana zakat (umumnya maksimum 1/8 = 12,5%). Mengunci perilaku:
 *  - parameter `zakat_porsi_amil_persen` dapat dikonfigurasi (default 12,5%);
 *  - hak amil (Rp) = porsi (%) × dasar penerimaan dana zakat;
 *  - porsi dijepit pada rentang 0–100 (nilai tak masuk akal tidak diterima);
 *  - laporan menampilkan rekap "Hak Amil" terpisah dari realisasi penyaluran.
 */
class ZakatAmilPorsiTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        // Pulihkan cache parameter kebijakan agar tidak mencemari test lain.
        Setting::flush();

        parent::tearDown();
    }

    private function pengelola(): User
    {
        return User::updateOrCreate(
            ['email' => 'pengurus.zakat.amil@test.local'],
            [
                'name' => 'Pengurus Zakat Amil',
                'password' => Hash::make('rahasia'),
                'role' => 'pengurus',
                'is_active' => true,
            ]
        );
    }

    /** Mengeset porsi amil pada kebijakan lalu menyegarkan cache Setting. */
    private function setPorsi(?float $porsi): void
    {
        $kebijakan = KebijakanKoperasi::query()->first();
        if ($kebijakan === null) {
            $this->markTestSkipped('Baris kebijakan koperasi belum tersedia.');
        }

        $kebijakan->zakat_porsi_amil_persen = $porsi;
        $kebijakan->save();
        Setting::flush();
    }

    public function test_default_porsi_amil_duabelas_setengah_persen(): void
    {
        $this->assertSame(12.50, Setting::zakatPorsiAmilPersen());
    }

    public function test_hak_amil_adalah_porsi_dari_penerimaan_zakat(): void
    {
        $this->setPorsi(12.50);

        // 12,5% dari Rp 10.000.000 = Rp 1.250.000.
        $this->assertSame(1250000.00, Setting::zakatHakAmil(10_000_000));
    }

    public function test_porsi_amil_dapat_dikonfigurasi(): void
    {
        $this->setPorsi(10.00);

        $this->assertSame(10.00, Setting::zakatPorsiAmilPersen());
        // 10% dari Rp 5.000.000 = Rp 500.000.
        $this->assertSame(500000.00, Setting::zakatHakAmil(5_000_000));
    }

    public function test_porsi_amil_dijepit_pada_rentang_nol_sampai_seratus(): void
    {
        $this->setPorsi(150.00);
        $this->assertSame(100.00, Setting::zakatPorsiAmilPersen());

        $this->setPorsi(-5.00);
        $this->assertSame(0.00, Setting::zakatPorsiAmilPersen());
    }

    public function test_hak_amil_dasar_negatif_dianggap_nol(): void
    {
        $this->setPorsi(12.50);

        $this->assertSame(0.00, Setting::zakatHakAmil(-1_000_000));
    }

    public function test_laporan_menampilkan_rekap_hak_amil_terpisah(): void
    {
        $this->setPorsi(12.50);

        $today = now()->toDateString();

        // Penerimaan zakat Rp 8.000.000 → hak amil = Rp 1.000.000.
        // WAJIB lewat ZakatService agar jurnal ikut terposting: laporan membaca
        // penerimaan dari jurnal berstatus 'Posted' pada akun dana (PSAK 109),
        // bukan langsung dari tabel `zakat_transaksis`.
        $service = app(ZakatService::class);
        $service->terima([
            'tanggal' => $today,
            'dana' => ZakatTransaksi::DANA_ZAKAT,
            'kategori' => 'zakat_maal',
            'pihak' => 'Muzakki Uji',
            'jumlah' => 8_000_000,
            'no_referensi' => 'ZKT-AMIL-TEST-1',
        ]);

        // Realisasi penyaluran ke asnaf amil Rp 250.000.
        $service->salurkan([
            'tanggal' => $today,
            'dana' => ZakatTransaksi::DANA_ZAKAT,
            'kategori' => 'amil',
            'pihak' => 'Petugas Amil',
            'jumlah' => 250_000,
            'no_referensi' => 'ZKT-AMIL-TEST-2',
        ]);

        $resp = $this->actingAs($this->pengelola())
            ->get(route('zakat.laporan', ['dari' => $today, 'sampai' => $today]))
            ->assertOk();

        $resp->assertViewHas('porsiAmil', 12.50);
        $resp->assertViewHas('dasarHakAmil', 8_000_000.0);
        $resp->assertViewHas('hakAmil', 1_000_000.0);
        $resp->assertViewHas('realisasiAmil', 250_000.0);

        // Kolom "Hak Amil" tersaji terpisah pada laporan.
        $resp->assertSee('Hak Amil', false);
    }
}
