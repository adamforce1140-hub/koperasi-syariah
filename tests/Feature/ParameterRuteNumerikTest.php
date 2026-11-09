<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * T-1 — PARAMETER RUTE NUMERIK WAJIB DIVALIDASI.
 *
 * Temuan audit `AuditKetahananParameterRuteTest`: 130 kombinasi peran×rute
 * membalas 500 ketika parameter `{id}` diisi string non-numerik (mis.
 * `GET /manajer/approval-pembiayaan/abc`) — controller menerima `int $id`
 * lalu PHP melempar TypeError. Masukan pengguna tidak boleh menjatuhkan
 * sistem.
 *
 * Perbaikan: `bootstrap/app.php` mendaftarkan pola global `Route::pattern()`
 * untuk parameter bernama `id`, `anggota_id`, dan `*_id`, sehingga router
 * menolak nilai non-numerik sebagai 404 sebelum controller dipanggil.
 *
 * Test ini memeriksa KONTRAK rute (bukan perilaku per peran) agar cepat dan
 * deterministik; perilaku HTTP-nya diuji `ParameterRuteKetahananHttpTest`.
 */
class ParameterRuteNumerikTest extends TestCase
{
    use DatabaseTransactions;

    /** Nilai non-numerik yang dipakai audit. */
    private const NILAI_TIDAK_SAH = ['abc', '99999999x'];

    /** Pola yang wajib terpasang di level global (kunci literal). */
    private const POLA_GLOBAL = ['id', 'anggota_id', 'pembiayaan_id'];

    /**
     * Nama parameter yang dianggap ID: `id` atau berakhiran `_id`.
     *
     * Diurai dari tabel rute (bukan daftar tetap) agar parameter baru
     * otomatis tercakup.
     */
    private function adalahId(string $nama): bool
    {
        return $nama === 'id' || str_ends_with($nama, '_id');
    }

    /** Ganti seluruh parameter pada URI dengan satu nilai. */
    private function uriDengan(RoutingRoute $rute, string $nilai): string
    {
        return '/'.preg_replace('/\{[^}]+\}/', $nilai, $rute->uri());
    }

    /** Apakah URI cocok ke rute ini saat dicocokkan router sungguhan? */
    private function cocok(RoutingRoute $rute, string $uri, string $metode): bool
    {
        try {
            $this->app['router']->getRoutes()->match(Request::create($uri, $metode));

            return true;
        } catch (NotFoundHttpException) {
            return false;
        }
    }

    /**
     * Setiap parameter `_id` yang benar-benar dipakai rute harus terdaftar
     * pada pola global. Tanpa ini, rute baru dengan `{foo_id}` lolos tanpa
     * validasi hingga audit HTTP berikutnya.
     */
    public function test_setiap_parameter_berakhiran_id_terdaftar_pada_pola_global(): void
    {
        $terdaftar = $this->polaGlobal();
        $tidakTerdaftar = [];

        foreach (Route::getRoutes()->getRoutes() as $rute) {
            foreach ($rute->parameterNames() as $nama) {
                if (str_ends_with($nama, '_id') && ! isset($terdaftar[$nama])) {
                    $tidakTerdaftar[$nama] = $rute->uri();
                }
            }
        }

        $this->assertSame(
            [],
            $tidakTerdaftar,
            'Parameter ID belum terdaftar pada pola global bootstrap/app.php: '.json_encode($tidakTerdaftar)
        );
    }

    /** Isi `Router::$patterns` (properti protected) — pola global aktif. */
    private function polaGlobal(): array
    {
        $refleksi = new \ReflectionProperty($this->app['router'], 'patterns');
        $refleksi->setAccessible(true);

        return $refleksi->getValue($this->app['router']);
    }

    public function test_setiap_parameter_id_menolak_masukan_non_numerik(): void
    {
        $diuji = 0;
        $meloloskan = [];

        foreach (Route::getRoutes()->getRoutes() as $rute) {
            $parameterId = array_values(array_filter($rute->parameterNames(), fn (string $n): bool => $this->adalahId($n)));

            if ($parameterId === []) {
                continue;
            }

            $diuji++;
            $metode = $rute->methods()[0];

            foreach (self::NILAI_TIDAK_SAH as $nilai) {
                // Semua parameter diisi nilai yang sama: cukup membuktikan
                // bahwa SETIAP posisi ID menolak masukan non-numerik.
                if ($this->cocok($rute, $this->uriDengan($rute, $nilai), $metode)) {
                    $meloloskan[] = sprintf('%s %s menerima "%s"', $metode, $rute->uri(), $nilai);
                }
            }
        }

        $this->assertGreaterThan(50, $diuji, 'Jumlah rute berparameter ID yang diperiksa terlalu sedikit.');
        $this->assertSame(
            [],
            array_values(array_unique($meloloskan)),
            "Rute meloloskan parameter ID non-numerik (menyebabkan 500):\n"
            .implode("\n", array_values(array_unique($meloloskan)))
        );
    }

    public function test_parameter_id_numerik_tetap_dicocokkan(): void
    {
        // Regresi: pola global tidak boleh menolak ID yang sah.
        $rute = collect(Route::getRoutes()->getRoutes())
            ->first(fn (RoutingRoute $r): bool => $r->uri() === 'manajer/approval-pembiayaan/{id}');

        $this->assertNotNull($rute, 'Rute manajer/approval-pembiayaan/{id} tidak ditemukan.');
        $this->assertTrue($this->cocok($rute, '/manajer/approval-pembiayaan/42', 'GET'));
        $this->assertTrue($this->cocok($rute, '/manajer/approval-pembiayaan/0', 'GET'));
    }

    public function test_pola_global_terpasang(): void
    {
        $global = $this->polaGlobal();

        foreach (self::POLA_GLOBAL as $nama) {
            $this->assertSame(
                '[0-9]+',
                $global[$nama] ?? null,
                "Pola global untuk parameter '{$nama}' tidak terpasang di bootstrap/app.php."
            );
        }
    }

    public function test_parameter_non_id_tidak_mendapat_batasan_numerik(): void
    {
        // Jenis laporan AML adalah kode huruf (LTKT/LTKM/STR), bukan ID.
        $jenis = collect(Route::getRoutes()->getRoutes())
            ->first(fn (RoutingRoute $r): bool => str_ends_with($r->uri(), 'laporan-aml/export/{jenis}'));

        $this->assertNotNull($jenis, 'Rute ekspor laporan AML {jenis} tidak ditemukan.');
        $this->assertTrue(
            $this->cocok($jenis, '/pengurus/laporan-aml/export/LTKT', 'GET'),
            'Parameter {jenis} bukan ID — tidak boleh mendapat batasan numerik.'
        );

        // Path berkas dokumen bukan angka.
        $berkas = collect(Route::getRoutes()->getRoutes())
            ->first(fn (RoutingRoute $r): bool => str_ends_with($r->uri(), 'berkas/{path}'));

        $this->assertNotNull($berkas, 'Rute unduh berkas {path} tidak ditemukan.');
        $this->assertTrue(
            $this->cocok($berkas, '/berkas/bukti/2026/akad.pdf', 'GET'),
            'Parameter {path} bukan ID — tidak boleh mendapat batasan numerik.'
        );
    }
}
