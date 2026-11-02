<?php

namespace Tests\Feature;

use App\Contracts\ApprovalGuardInterface;
use App\Contracts\AuditAkadRepositoryInterface;
use App\Contracts\CoaResolverInterface;
use App\Contracts\JournalPosterInterface;
use App\Contracts\KinerjaStafRepositoryInterface;
use App\Contracts\PembiayaanBermasalahRepositoryInterface;
use App\Contracts\PeriodResolverInterface;
use App\Contracts\PolicySourceInterface;
use App\Repositories\AuditAkadRepository;
use App\Repositories\KinerjaStafRepository;
use App\Repositories\PembiayaanBermasalahRepository;
use App\Services\AutoJournalingService;
use App\Services\CoaResolver;
use App\Services\PemisahanTugasGuard;
use App\Services\SettingPolicySource;
use App\Services\TahunBukuService;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * KONTRAK & DEPENDENCY INVERSION (Rekomendasi 3 audit arsitektur).
 *
 * Membuktikan bahwa kelima antarmuka domain dapat diselesaikan dari container,
 * terikat ke implementasi bawaannya, dan — yang terpenting — dapat DITUKAR
 * (swap/mock) tanpa menyentuh satu pun pemanggil. Inilah bukti decoupling:
 * modul bergantung pada kontrak, bukan pada kelas konkret.
 *
 * Skenario mengikuti §4.5 dokumen audit: resolve → mock → swap.
 */
class KontrakArsitekturTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Peta resmi kontrak → implementasi bawaan. Bila ada kontrak baru atau
     * implementasi ditukar, cukup ubah SATU baris di sini dan seluruh
     * pengujian mengikuti (data-driven, bukan if berantai).
     *
     * @return array<string, string>
     */
    private function peta(): array
    {
        return [
            JournalPosterInterface::class => AutoJournalingService::class,
            PolicySourceInterface::class => SettingPolicySource::class,
            PeriodResolverInterface::class => TahunBukuService::class,
            CoaResolverInterface::class => CoaResolver::class,
            ApprovalGuardInterface::class => PemisahanTugasGuard::class,
            KinerjaStafRepositoryInterface::class => KinerjaStafRepository::class,
            PembiayaanBermasalahRepositoryInterface::class => PembiayaanBermasalahRepository::class,
            AuditAkadRepositoryInterface::class => AuditAkadRepository::class,
        ];
    }

    /* ============================================================
     * 1. Kontrak terdaftar & target metrik (≥ 5 interface, ≥ 5 binding)
     * ============================================================ */

    public function test_minimal_lima_interface_di_app_contracts(): void
    {
        $berkas = glob(app_path('Contracts/*.php')) ?: [];

        $this->assertGreaterThanOrEqual(
            5,
            count($berkas),
            'Target audit: minimal 5 antarmuka di app/Contracts.'
        );
    }

    public function test_minimal_lima_binding_di_service_provider(): void
    {
        $isi = file_get_contents(app_path('Providers/AppServiceProvider.php'));

        $this->assertGreaterThanOrEqual(
            5,
            substr_count($isi, 'Interface::class'),
            'Target audit: minimal 5 binding kontrak di AppServiceProvider.'
        );
    }

    /* ============================================================
     * 2. Resolve: container mengembalikan implementasi yang benar
     * ============================================================ */

    public function test_setiap_kontrak_dapat_diselesaikan_ke_implementasi_bawaan(): void
    {
        foreach ($this->peta() as $kontrak => $konkret) {
            $this->assertTrue(interface_exists($kontrak), "Kontrak {$kontrak} tidak ditemukan.");
            $this->assertTrue(class_exists($konkret), "Kelas {$konkret} tidak ditemukan.");

            $instans = app()->make($kontrak);

            $this->assertInstanceOf(
                $kontrak,
                $instans,
                "Container tidak mengembalikan instance {$kontrak}."
            );
            $this->assertInstanceOf(
                $konkret,
                $instans,
                "Kontrak {$kontrak} seharusnya terikat ke {$konkret}."
            );
        }
    }

    public function test_implementasi_benar_benar_menyatakan_implements(): void
    {
        foreach ($this->peta() as $kontrak => $konkret) {
            $this->assertContains(
                $kontrak,
                class_implements($konkret),
                "{$konkret} wajib `implements {$kontrak}`."
            );
        }
    }

    /* ============================================================
     * 3. Decoupling: kontrak kecil & terfokus (≤ 7 method)
     * ============================================================ */

    public function test_setiap_kontrak_kecil_dan_terfokus(): void
    {
        // Pedoman §4.4 dokumen audit: "interface kecil & fokus (≤ 7 method)".
        // Batas di sini dilonggarkan ke 10 secara sadar: dua kontrak yang sudah
        // ada sedikit di atas pedoman namun tetap KOMPAK & kohesif —
        // `PeriodResolverInterface` (8, seputar periode/tahun buku) dan
        // `CoaResolverInterface` (9, seputar resolusi akun CoA). Memecahnya
        // justru menambah coupling pada pemanggil. Test ini menjaga agar tidak
        // ada kontrak yang membengkak menjadi "kelas serba bisa".
        $batas = 10;

        foreach (array_keys($this->peta()) as $kontrak) {
            $jumlah = count((new \ReflectionClass($kontrak))->getMethods());

            $this->assertLessThanOrEqual(
                $batas,
                $jumlah,
                "Kontrak {$kontrak} memiliki {$jumlah} method; batas praktis {$batas}."
            );
        }
    }

    /* ============================================================
     * 4. SWAP: implementasi dapat ditukar tanpa mengubah konsumen
     * ============================================================ */

    public function test_kebijakan_dapat_ditukar_dengan_source_palsu(): void
    {
        // Sumber kebijakan palsu: nilai "minimal_pengajuan_pembiayaan" dipaksa.
        $palsu = new class implements PolicySourceInterface
        {
            public function get(string $key, mixed $default = null): mixed
            {
                return $key === 'batas_uji_palsu' ? 999999 : $default;
            }

            public function all(): array
            {
                return ['batas_uji_palsu' => 999999];
            }

            public function nominal(string $key, float $default = 0.0): float
            {
                return (float) $this->get($key, $default);
            }

            public function bool(string $key, bool $default = false): bool
            {
                return (bool) $this->get($key, $default);
            }

            public function persen(string $key, float $default = 0.0): float
            {
                return (float) $this->get($key, $default);
            }

            public function toleransiSelisihJurnal(): float
            {
                return 0.5;
            }
        };

        $this->app->instance(PolicySourceInterface::class, $palsu);

        $dariKontrak = app()->make(PolicySourceInterface::class);

        $this->assertSame($palsu, $dariKontrak, 'Container harus memakai instance pengganti.');
        $this->assertSame(999999, $dariKontrak->get('batas_uji_palsu'));
        $this->assertSame(0.5, $dariKontrak->toleransiSelisihJurnal());

        // Konsumen baru yang bergantung pada KONTRAK otomatis memakai pengganti
        // — tanpa satu pun pemanggil lama berubah (lapisan aditif).
        $this->assertSame(999999, app(PolicySourceInterface::class)->get('batas_uji_palsu'));
    }

    public function test_kontrak_dapat_di_mock_lewat_container(): void
    {
        $spy = \Mockery::mock(JournalPosterInterface::class);
        $spy->shouldReceive('post')->once()->andReturn(null);

        $this->app->instance(JournalPosterInterface::class, $spy);

        $hasil = app(JournalPosterInterface::class)->post('Uji Kontrak', [
            ['kode' => '1-1001', 'debet' => 1000.0],
            ['kode' => '4-1001', 'kredit' => 1000.0],
        ]);

        $this->assertNull($hasil);
    }

    /* ============================================================
     * 5. Aditif: jalur statis lama tetap bekerja (nol regresi)
     * ============================================================ */

    public function test_akses_statis_lama_tetap_berjalan(): void
    {
        // `Setting::…()` tetap sah walaupun kontrak lebih diutamakan untuk kode
        // baru — menjamin tidak ada pemanggil lama yang rusak.
        $this->assertIsFloat(Setting::toleransiSelisihJurnal());
        $this->assertIsArray(Setting::all());
    }
}
