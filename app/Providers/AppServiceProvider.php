<?php

namespace App\Providers;

use App\Contracts\ApprovalGuardInterface;
use App\Contracts\AuditAkadRepositoryInterface;
use App\Contracts\CoaResolverInterface;
use App\Contracts\JournalPosterInterface;
use App\Contracts\KinerjaStafRepositoryInterface;
use App\Contracts\PembiayaanBermasalahRepositoryInterface;
use App\Contracts\PeriodResolverInterface;
use App\Contracts\PolicySourceInterface;
use App\Models\Anggota;
use App\Models\PengajuanRekening;
use App\Observers\DenyutObserver;
use App\Observers\ObserverAuditPii;
use App\Repositories\AuditAkadRepository;
use App\Repositories\KinerjaStafRepository;
use App\Repositories\PembiayaanBermasalahRepository;
use App\Services\AutoJournalingService;
use App\Services\CoaResolver;
use App\Services\PemisahanTugasGuard;
use App\Services\SettingPolicySource;
use App\Services\TahunBukuService;
use Carbon\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /* ================================================================
         | KONTRAK & DEPENDENCY INJECTION (arsitektur fleksibel)
         |
         | Mengikat antarmuka domain ke implementasi konkretnya agar modul
         | dapat bergantung pada KONTRAK, bukan kelas konkret. Semua binding
         | bersifat ADITIF (bukan `singleton` paksa) sehingga perilaku lama
         | tidak berubah: pemanggil yang memakai `app(CoaResolver::class)`
         | atau `Setting::…()` tetap bekerja seperti sebelumnya.
         |
         | Manfaat: menukar implementasi (mis. pada pengujian) cukup lewat
         | satu titik ini, tanpa menyentuh seluruh pemanggil.
         ================================================================ */
        $this->app->bind(JournalPosterInterface::class, AutoJournalingService::class);
        $this->app->bind(CoaResolverInterface::class, CoaResolver::class);
        $this->app->bind(PeriodResolverInterface::class, TahunBukuService::class);
        $this->app->bind(PolicySourceInterface::class, SettingPolicySource::class);
        $this->app->bind(ApprovalGuardInterface::class, PemisahanTugasGuard::class);
        $this->app->bind(KinerjaStafRepositoryInterface::class, KinerjaStafRepository::class);
        $this->app->bind(PembiayaanBermasalahRepositoryInterface::class, PembiayaanBermasalahRepository::class);
        $this->app->bind(AuditAkadRepositoryInterface::class, AuditAkadRepository::class);
    }

    public function boot(): void
    {
        Carbon::setLocale('id');
        App::setLocale('id');

        $this->daftarkanDenyutObserver();
        $this->daftarkanAuditPii();
    }

    /**
     * Pasang observer perubahan data untuk sinkronisasi real-time.
     *
     * Setiap model yang perubahannya memengaruhi angka dashboard dibungkus
     * satu observer generik; begitu ada baris tersimpan/dihapus (dari jalur
     * mana pun), versi domain terkait naik sehingga peramban lain tahu data
     * sudah berubah tanpa harus membandingkan seluruh isi tabel.
     *
     * Pemetaan model → domain ada di DenyutObserver::petaModel() agar satu
     * sumber kebenaran.
     */
    private function daftarkanDenyutObserver(): void
    {
        $observer = app(DenyutObserver::class);

        foreach (array_keys(DenyutObserver::petaModel()) as $model) {
            if (class_exists($model)) {
                $model::observe($observer);
            }
        }
    }

    /**
     * Pasang observer jejak audit perubahan data pribadi (UU PDP No. 27/2022).
     *
     * Hanya model yang memuat kolom data pribadi yang dipasangi observer ini.
     * Dipasang di level model agar seluruh jalur tulis ikut terekam, termasuk
     * command Artisan, seeder, dan tinker — bukan hanya lewat controller.
     *
     * Dapat dimatikan lewat env `KEAMANAN_AUDIT_PII=false` tanpa mengubah kode.
     */
    private function daftarkanAuditPii(): void
    {
        if (! (bool) config('keamanan.audit_pii', true)) {
            return;
        }

        $observer = app(ObserverAuditPii::class);

        foreach ([
            Anggota::class,
            PengajuanRekening::class,
        ] as $model) {
            if (class_exists($model)) {
                $model::observe($observer);
            }
        }
    }
}
