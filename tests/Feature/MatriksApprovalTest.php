<?php

namespace Tests\Feature;

use App\Models\KebijakanKoperasi;
use App\Models\Pembiayaan;
use App\Support\MatriksApproval;
use App\Support\Setting;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Uji matriks persetujuan pembiayaan config-driven (Rekomendasi 4 — langkah 2).
 *
 * Fokus utama:
 *  1. Seluruh aturan berjenjang berada di `config/alur_pembiayaan.php`
 *     (0 literal ambang di controller) — sehingga kebijakan persetujuan dapat
 *     diubah tanpa menyentuh kode.
 *  2. Resolver menerapkan aturan secara benar: cabang dalam-limit vs luar-limit.
 *  3. DEFAULT AMAN: nilai default identik dengan perilaku sistem sebelumnya.
 */
class MatriksApprovalTest extends TestCase
{
    /* ══════════════════════════════════════════════════════════════════════
     | 1. Konfigurasi adalah satu-satunya sumber aturan
     ══════════════════════════════════════════════════════════════════════ */

    public function test_konfigurasi_memuat_tiga_tahap_persetujuan(): void
    {
        $konfigurasi = MatriksApproval::konfigurasi();

        foreach (['ao', 'manajer', 'pengurus'] as $peran) {
            $this->assertArrayHasKey($peran, $konfigurasi, "Tahap persetujuan peran {$peran} harus ada di config.");
        }
    }

    public function test_status_masuk_setiap_peran_sesuai_rantai_pembiayaan(): void
    {
        $this->assertSame(Pembiayaan::STATUS_PENDING_AO, MatriksApproval::statusMasuk('ao'));
        $this->assertSame(Pembiayaan::STATUS_PENDING_MANAJER, MatriksApproval::statusMasuk('manajer'));
        $this->assertSame(Pembiayaan::STATUS_PENDING_PENGURUS, MatriksApproval::statusMasuk('pengurus'));
    }

    public function test_peran_tak_dikenal_tidak_punya_tahap(): void
    {
        $this->assertSame([], MatriksApproval::tahap('teller'));
        $this->assertNull(MatriksApproval::statusMasuk('teller'));
        $this->assertNull(MatriksApproval::limitPeran('teller'));
    }

    /* ══════════════════════════════════════════════════════════════════════
     | 2. Ambang kewenangan (dua lapis: kebijakan DB → limit_default config)
     ══════════════════════════════════════════════════════════════════════ */

    public function test_limit_manajer_default_lima_puluh_juta(): void
    {
        // Default aman: sama dengan perilaku sistem sebelumnya.
        $this->assertSame(50000000.0, MatriksApproval::limitPeran('manajer'));
    }

    public function test_limit_mengikuti_parameter_kebijakan_bila_diubah(): void
    {
        // Simulasi Pengurus menurunkan kewenangan Manajer menjadi Rp 25 juta
        // dari UI tanpa deploy. Nilai default config harus digantikan.
        config(['job_operasional.aktif' => false]); // netralkan flag queue

        Cache::forget(Setting::CACHE_KEY);

        $kebijakan = KebijakanKoperasi::first() ?? new KebijakanKoperasi;
        $asli = $kebijakan->limit_manajer;

        try {
            // Override cache Setting secara langsung agar tidak mengubah DB
            // bersama (lihat catatan DB pengujian pada JobAsinkronTest).
            Cache::put(
                Setting::CACHE_KEY,
                array_merge(Setting::all(), ['limit_manajer' => 25000000.0]),
                Setting::CACHE_TTL
            );

            $this->assertSame(25000000.0, MatriksApproval::limitPeran('manajer'));
        } finally {
            if ($asli !== null) {
                $kebijakan->limit_manajer = $asli;
            }

            Cache::forget(Setting::CACHE_KEY);
        }
    }

    /* ══════════════════════════════════════════════════════════════════════
     | 3. Keputusan cabang dalam-limit vs luar-limit
     ══════════════════════════════════════════════════════════════════════ */

    public function test_keputusan_dalam_limit_disetujui_penuh(): void
    {
        $keputusan = MatriksApproval::keputusan('manajer', 40000000.0);

        $this->assertSame(Pembiayaan::STATUS_DISETUJUI, $keputusan['status']);
        $this->assertTrue($keputusan['final']);
        $this->assertSame(50000000.0, $keputusan['limit']);
    }

    public function test_keputusan_tepat_di_batas_masih_disetujui(): void
    {
        // Batas inklusif: nominal == limit tetap dianggap dalam kewenangan.
        $keputusan = MatriksApproval::keputusan('manajer', 50000000.0);

        $this->assertSame(Pembiayaan::STATUS_DISETUJUI, $keputusan['status']);
        $this->assertTrue($keputusan['final']);
    }

    public function test_keputusan_luar_limit_diteruskan_ke_pengurus(): void
    {
        $keputusan = MatriksApproval::keputusan('manajer', 60000000.0);

        $this->assertSame(Pembiayaan::STATUS_PENDING_PENGURUS, $keputusan['status']);
        $this->assertFalse($keputusan['final']);
        $this->assertSame(50000000.0, $keputusan['limit']);
    }

    public function test_keputusan_peran_tanpa_cabang_nominal_menuju_status_keluar(): void
    {
        $ao = MatriksApproval::keputusan('ao', 100000000.0);

        $this->assertSame(Pembiayaan::STATUS_PENDING_MANAJER, $ao['status']);
        $this->assertFalse($ao['final']);

        $pengurus = MatriksApproval::keputusan('pengurus', 100000000.0);

        $this->assertSame(Pembiayaan::STATUS_PENDING_DPS, $pengurus['status']);
        $this->assertFalse($pengurus['final']);
    }

    /* ══════════════════════════════════════════════════════════════════════
     | 4. Zero-hardcode: ambang tidak lagi berupa literal di controller
     ══════════════════════════════════════════════════════════════════════ */

    public function test_tidak_ada_literal_ambang_manajer_di_controller(): void
    {
        $berkas = app_path('Http/Controllers/Dashboard/ManajerController.php');
        $isi = (string) file_get_contents($berkas);

        $this->assertStringNotContainsString(
            '50000000',
            $isi,
            'Ambang kewenangan Manajer harus dibaca dari matriks persetujuan, '
            .'bukan literal di controller.'
        );

        $this->assertStringContainsString(
            'MatriksApproval::keputusan',
            $isi,
            'ManajerController harus memakai resolver MatriksApproval.'
        );
    }
}
