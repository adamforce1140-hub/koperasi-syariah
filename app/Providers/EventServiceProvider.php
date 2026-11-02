<?php

namespace App\Providers;

use App\Events\AngsuranLunas;
use App\Events\DepositoJatuhTempo;
use App\Events\KebijakanBerubah;
use App\Events\PembiayaanDicairkan;
use App\Events\ShuDidistribusikan;
use App\Listeners\CatatPeristiwaDomain;
use App\Listeners\InvalidasiCacheKebijakan;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

/**
 * Registrasi peristiwa domain → listener.
 *
 * Discovery event SENGAJA dimatikan agar pemetaan bersifat eksplisit, dapat
 * dibaca sekilas, dan deterministik di semua lingkungan (termasuk pengujian).
 *
 * Seluruh listener bersifat IDEMPOTEN dan fail-safe: tidak ada listener yang
 * boleh melempar exception ke pemanggil (lihat implementasi masing-masing).
 */
class EventServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        // Pencairan pembiayaan selesai → catatan audit domain.
        PembiayaanDicairkan::class => [
            CatatPeristiwaDomain::class.'@handlePembiayaanDicairkan',
        ],

        // Angsuran lunas → catatan audit domain.
        AngsuranLunas::class => [
            CatatPeristiwaDomain::class.'@handleAngsuranLunas',
        ],

        // SHU didistribusikan → catatan audit domain.
        ShuDidistribusikan::class => [
            CatatPeristiwaDomain::class.'@handleShuDidistribusikan',
        ],

        // Deposito jatuh tempo → catatan audit domain.
        DepositoJatuhTempo::class => [
            CatatPeristiwaDomain::class.'@handleDepositoJatuhTempo',
        ],

        // Kebijakan berubah → invalidasi cache pengaturan lintas-node.
        KebijakanBerubah::class => [
            InvalidasiCacheKebijakan::class,
        ],
    ];

    public function boot(): void
    {
        //
    }

    /**
     * Discovery dimatikan: seluruh pemetaan event→listener bersifat eksplisit
     * di properti `$listen` di atas.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
