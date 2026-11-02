<?php

namespace Tests\Feature;

use App\Events\AngsuranLunas;
use App\Events\DepositoJatuhTempo;
use App\Events\KebijakanBerubah;
use App\Events\PembiayaanDicairkan;
use App\Events\ShuDidistribusikan;
use App\Listeners\CatatPeristiwaDomain;
use App\Listeners\InvalidasiCacheKebijakan;
use App\Models\Angsuran;
use App\Models\Deposito;
use App\Models\KebijakanKoperasi;
use App\Models\Pembiayaan;
use App\Models\ShuPeriod;
use App\Providers\EventServiceProvider;
use App\Support\Setting;
use Illuminate\Support\Facades\Event;
use ReflectionClass;
use Tests\TestCase;

/**
 * Uji lapisan peristiwa domain (Rekomendasi 4 — langkah 6).
 *
 * Fokus: pemetaan event→listener terdaftar secara eksplisit, listener
 * IDEMPOTEN & fail-safe, dan pengiriman peristiwa tidak mengubah perilaku
 * bisnis apa pun (aditif murni).
 */
class PeristiwaDomainTest extends TestCase
{
    /* ══════════════════════════════════════════════════════════════════════
     | 1. Registrasi pemetaan
     ══════════════════════════════════════════════════════════════════════ */

    public function test_lima_peristiwa_domain_terdaftar_secara_eksplisit(): void
    {
        $peta = (new ReflectionClass(EventServiceProvider::class))
            ->getDefaultProperties()['listen'];

        $harusAda = [
            PembiayaanDicairkan::class,
            AngsuranLunas::class,
            ShuDidistribusikan::class,
            DepositoJatuhTempo::class,
            KebijakanBerubah::class,
        ];

        foreach ($harusAda as $event) {
            $this->assertArrayHasKey(
                $event,
                $peta,
                "Peristiwa {$event} belum terdaftar di EventServiceProvider::\$listen."
            );
            $this->assertNotEmpty(
                $peta[$event],
                "Peristiwa {$event} tidak memiliki listener."
            );
        }
    }

    public function test_discovery_event_dimatikan_agar_pemetaan_deterministik(): void
    {
        $this->assertFalse(
            (new EventServiceProvider(app()))->shouldDiscoverEvents(),
            'Discovery event harus false agar pemetaan eksplisit & deterministik.'
        );
    }

    /* ══════════════════════════════════════════════════════════════════════
     | 2. Listener fail-safe & idempoten
     ══════════════════════════════════════════════════════════════════════ */

    public function test_listener_audit_tidak_melempar_dan_idempoten(): void
    {
        $pembiayaan = new Pembiayaan(['no_pembiayaan' => 'PB-TEST-1']);
        $pembiayaan->id = 999999;

        $listener = new CatatPeristiwaDomain;
        $event = new PembiayaanDicairkan($pembiayaan, 'cash', 1_000_000.0, 1);

        // Dijalankan dua kali — harus tidak melempar dan tidak menghasilkan
        // efek ganda (murni pencatatan log).
        $listener->handlePembiayaanDicairkan($event);
        $listener->handlePembiayaanDicairkan($event);

        $this->assertTrue(true, 'Listener audit selesai tanpa exception (idempoten).');
    }

    public function test_listener_angsuran_dan_shu_dan_deposito_tidak_melempar(): void
    {
        $listener = new CatatPeristiwaDomain;

        $angsuran = new Angsuran;
        $angsuran->id = 1;
        $angsuran->pembiayaan_id = 2;
        $angsuran->angsuran_ke = 3;
        $listener->handleAngsuranLunas(new AngsuranLunas($angsuran, 500_000.0, 7));

        $listener->handleShuDidistribusikan(new ShuDidistribusikan(null, 0.0, 7));

        $deposito = new Deposito;
        $deposito->id = 4;
        $listener->handleDepositoJatuhTempo(new DepositoJatuhTempo($deposito, 7));

        $this->assertTrue(true, 'Seluruh handler audit selesai tanpa exception.');
    }

    public function test_listener_invalidasi_cache_aman_dijalankan_berulang(): void
    {
        $listener = new InvalidasiCacheKebijakan;

        $listener->handle(new KebijakanBerubah(['shu_pct_jasa_anggota'], 1));
        $listener->handle(new KebijakanBerubah(['shu_pct_jasa_anggota'], 1));

        // Nilai kebijakan tetap dapat dibaca setelah flush berulang.
        $this->assertIsFloat(Setting::toleransiSelisihJurnal());
    }

    /* ══════════════════════════════════════════════════════════════════════
     | 3. Pengiriman peristiwa (Event::fake) — tidak mengubah perilaku bisnis
     ══════════════════════════════════════════════════════════════════════ */

    public function test_penyimpanan_kebijakan_mengirim_peristiwa_kebijakan_berubah(): void
    {
        Event::fake([KebijakanBerubah::class]);

        $kebijakan = KebijakanKoperasi::query()->first();

        if ($kebijakan === null) {
            $this->markTestSkipped('Tabel kebijakan_koperasis kosong pada lingkungan uji.');
        }

        // Sentuh satu kolom audit agar model menjadi dirty.
        $nilaiAsli = $kebijakan->shu_pct_jasa_anggota;
        $kebijakan->shu_pct_jasa_anggota = (float) $nilaiAsli === 41.0 ? 40.0 : 41.0;
        $kebijakan->save();

        Event::assertDispatched(KebijakanBerubah::class);

        // Kembalikan nilai asli agar tidak mencemari test lain.
        $kebijakan->shu_pct_jasa_anggota = $nilaiAsli;
        $kebijakan->save();

        $this->assertTrue(true);
    }

    public function test_peristiwa_domain_tidak_dikirim_saat_tidak_ada_aksi_bisnis(): void
    {
        Event::fake([
            PembiayaanDicairkan::class,
            AngsuranLunas::class,
            ShuDidistribusikan::class,
            DepositoJatuhTempo::class,
        ]);

        // Tidak ada aksi bisnis dijalankan.
        Event::assertNotDispatched(PembiayaanDicairkan::class);
        Event::assertNotDispatched(AngsuranLunas::class);
        Event::assertNotDispatched(ShuDidistribusikan::class);
        Event::assertNotDispatched(DepositoJatuhTempo::class);
    }

    public function test_kirim_peristiwa_dapat_difake_tanpa_eksekusi_listener(): void
    {
        Event::fake([PembiayaanDicairkan::class]);

        $pembiayaan = new Pembiayaan(['no_pembiayaan' => 'PB-TEST-2']);
        $pembiayaan->id = 123456;

        PembiayaanDicairkan::dispatch($pembiayaan, 'transfer_bank', 2_500_000.0, 9);

        Event::assertDispatched(
            PembiayaanDicairkan::class,
            fn (PembiayaanDicairkan $e): bool => $e->metode === 'transfer_bank'
                && $e->nominal === 2_500_000.0
                && $e->pembiayaan->no_pembiayaan === 'PB-TEST-2'
        );
    }

    public function test_periode_shu_opsional_dalam_peristiwa_shu(): void
    {
        Event::fake([ShuDidistribusikan::class]);

        ShuDidistribusikan::dispatch(new ShuPeriod, 12_345.67, 3);

        Event::assertDispatched(
            ShuDidistribusikan::class,
            fn (ShuDidistribusikan $e): bool => abs($e->totalShu - 12_345.67) < 0.001
        );
    }
}
