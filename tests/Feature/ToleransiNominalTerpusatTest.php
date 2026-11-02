<?php

namespace Tests\Feature;

use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\KebijakanKoperasi;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A11 — toleransi pembandingan nominal hidup di satu tempat (SSOT).
 *
 * Sebelumnya literal `0,005` tersebar di tiga titik dengan dua makna berbeda.
 * Test ini mengunci pemisahan tersebut:
 *
 *  1. `Setting::toleransiUang()` = setengah satuan presisi uang, diturunkan
 *     dari `DESIMAL_UANG` — murni matematis, bukan kebijakan operator.
 *  2. `denyut:pemeriksaan` membaca ambang keseimbangan jurnal dari KEBIJAKAN
 *     (`toleransi_selisih_jurnal`), sehingga status jurnal berselisih satu sen
 *     berubah saat parameternya diubah — bukti tidak ada literal tersisa.
 *  3. Tidak ada lagi literal `0.005` di `app/`.
 */
class ToleransiNominalTerpusatTest extends TestCase
{
    use DatabaseTransactions;

    public function test_toleransi_uang_diturunkan_dari_presisi_uang(): void
    {
        $this->assertEqualsWithDelta(0.005, Setting::toleransiUang(), 1e-12);

        $this->assertSame(
            0.5 * (10 ** -Setting::DESIMAL_UANG),
            Setting::toleransiUang(),
            'Toleransi uang wajib diturunkan dari DESIMAL_UANG, bukan literal.'
        );
    }

    public function test_pemeriksaan_jurnal_memakai_toleransi_dari_kebijakan(): void
    {
        $this->buatJurnalSelisihSatuSen();

        // Ambang 0,01: selisih tepat 0,01 belum MELEWATI ambang → dianggap sehat.
        $this->aturToleransi(0.01);
        $this->artisan('denyut:pemeriksaan')
            ->expectsOutputToContain('[OK]   Integritas jurnal')
            ->run();

        // Ambang 0: selisih 0,01 melewati ambang → dilaporkan gagal.
        $this->aturToleransi(0.0);
        $this->artisan('denyut:pemeriksaan')
            ->expectsOutputToContain('[GAGAL] Integritas jurnal')
            ->run();
    }

    public function test_tidak_ada_literal_setengah_sen_di_kode_produksi(): void
    {
        $pelanggaran = [];

        foreach (File::allFiles(app_path()) as $berkas) {
            if (! str_ends_with($berkas->getFilename(), '.php')) {
                continue;
            }

            if (str_contains($berkas->getContents(), '0.005')) {
                $pelanggaran[] = $berkas->getRelativePathname();
            }
        }

        $this->assertSame(
            [],
            $pelanggaran,
            'Literal 0,005 harus dialirkan ke Setting::toleransiUang(): '.implode(', ', $pelanggaran)
        );
    }

    /* ==================================================================
     | FIXTURE
     ================================================================== */

    private function aturToleransi(float $nilai): void
    {
        $kebijakan = KebijakanKoperasi::query()->first() ?? KebijakanKoperasi::create([]);

        // Event `saved` pada model sudah memanggil Setting::flush().
        $kebijakan->forceFill(['toleransi_selisih_jurnal' => $nilai])->save();
    }

    /** Jurnal Posted dengan selisih debet-kredit tepat satu sen (0,01). */
    private function buatJurnalSelisihSatuSen(): void
    {
        $debet = $this->coa('9-9911');
        $kredit = $this->coa('9-9912');

        $jurnal = Jurnal::create([
            'no_jurnal' => 'UJI-A11-'.strtoupper(substr(uniqid(), -8)),
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'UJI-TOLERANSI',
            'keterangan' => 'Uji toleransi selisih satu sen',
            'total_debet' => 100.00,
            'total_kredit' => 100.01,
            'is_balance' => false,
            'status' => 'Posted',
            'posted_at' => now(),
        ]);

        JurnalDetail::create([
            'jurnal_id' => $jurnal->id,
            'coa_id' => $debet->id,
            'baris' => 1,
            'debet' => 100.00,
            'kredit' => 0,
            'keterangan' => 'Sisi debet uji toleransi',
        ]);

        JurnalDetail::create([
            'jurnal_id' => $jurnal->id,
            'coa_id' => $kredit->id,
            'baris' => 2,
            'debet' => 0,
            'kredit' => 100.01,
            'keterangan' => 'Sisi kredit uji toleransi',
        ]);
    }

    private function coa(string $kode): Coa
    {
        return Coa::updateOrCreate(
            ['kode_akun' => $kode],
            [
                'nama_akun' => 'Akun uji '.$kode,
                'kategori' => 'Beban',
                'klasifikasi_neraca' => 'Beban Operasional',
                'tipe_saldo_normal' => 'Debet',
                'saldo_awal' => 0,
                'is_active' => true,
            ]
        );
    }
}
