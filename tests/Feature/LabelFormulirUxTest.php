<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * S-2 — SETIAP KOLOM ISIAN YANG DAPAT DIISI PENGGUNA WAJIB PUNYA LABEL
 * YANG TERTAUT.
 *
 * Latar belakang: penanda `placeholder` sering dipakai sebagai "label" visual
 * karena mudah, padahal ia bukan label (WCAG 3.3.2 / 1.3.1 / HTML-AAM):
 * placeholder hilang begitu pengguna mulai mengetik, kontrasnya rendah, dan
 * tidak dibacakan pembaca layar secara andal. Begitu pula `<label>` yang
 * ditulis sebagai teks di atas kolom tanpa `for`: secara visual tampak benar,
 * tetapi tidak terhubung secara programatik ke kolom mana pun, sehingga
 * pembaca layar membacakan "kolom isian" tanpa nama.
 *
 * Pemeriksaan ini sengaja hanya menandai hal yang dapat DIBUKTIKAN dari
 * markup, agar tidak menghasilkan temuan palsu:
 *
 *   - hanya `<input>` dan `<textarea>` pada berkas yang memuat `<form>`;
 *   - `type` hidden/submit/button/reset/image dilewati;
 *   - `readonly` dilewati: nilainya tidak dapat diubah pengguna dan tidak
 *     dikirim ke server; nama aksesibelnya menyusul pada kelompok S-3;
 *   - `<input>` tanpa atribut `name` yang bukan checkbox/radio dilewati
 *     (umumnya kolom tampilan yang lebih tepat dijadikan `<output>`; dibahas
 *     pada S-4);
 *   - sebuah kolom dianggap berlabel bila: dibungkus `<label>` yang belum
 *     ditutup; memuat `aria-label` / `aria-labelledby`; atau memiliki `id`
 *     yang ditunjuk `<label for="…">` di berkas yang sama.
 *
 * Temuan awal (S-2) diukur dengan aturan ini: lihat konstanta HUTANG.
 */
class LabelFormulirUxTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Berkas yang masih menjadi hutang UX (dikurangi bertahap).
     * Sengaja DIDAFTARKAN NAMA PER NAMA agar penambahan temuan baru langsung
     * merah. Bila kosong, seluruh view wajib bersih.
     *
     * @var list<string>
     */
    private const HUTANG = [
        // diisi bertahap; dikosongkan saat S-2 tuntas
    ];

    private const TIPE_LEWATI = ['hidden', 'submit', 'button', 'reset', 'image'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutExceptionHandling();
    }

    /** Pengguna uji; dipakai test render halaman (S-3 ke atas). */
    private function pengguna(string $role): User
    {
        return User::firstOrCreate(
            ['email' => "label.ux.{$role}@test.local"],
            [
                'name' => 'Label UX '.ucfirst($role),
                'password' => Hash::make('password'),
                'role' => $role,
                'is_active' => true,
            ]
        );
    }

    /**
     * Kumpulkan nama berkas view yang memuat `<form>`.
     *
     * @return list<string>
     */
    private function berkasView(): array
    {
        $akar = base_path('resources/views');
        $hasil = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($akar, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $berkas) {
            if (! $berkas->isFile() || ! str_ends_with($berkas->getFilename(), '.blade.php')) {
                continue;
            }

            $isi = file_get_contents($berkas->getPathname());

            if (preg_match('/<form\b/i', $isi)) {
                $hasil[] = str_replace('\\', '/', substr($berkas->getPathname(), strlen(base_path()) + 1));
            }
        }

        sort($hasil);

        return $hasil;
    }

    /**
     * Apakah kolom pada posisi $offset sudah punya label yang tertaut?
     */
    private function punyaLabel(string $isi, int $offset, string $tag): bool
    {
        $sebelum = strtolower(substr($isi, 0, $offset));

        // 1. Dibungkus <label> yang belum ditutup.
        if (substr_count($sebelum, '<label') > substr_count($sebelum, '</label>')) {
            return true;
        }

        // 2. Nama aksesibel langsung pada tag.
        if (preg_match('/\baria-label(?:ledby)?\s*=/i', $tag)) {
            return true;
        }

        // 3. id yang ditunjuk <label for="…"> (atau komponen <x-label-berkas for>).
        if (preg_match('/\bid\s*=\s*["\']([^"\']+)["\']/i', $tag, $m)) {
            $id = preg_quote($m[1], '/');

            return (bool) preg_match(
                '/<(?:label|x-label-berkas)\b[^>]*\bfor\s*=\s*["\']'.$id.'["\']/i',
                $isi
            );
        }

        return false;
    }

    /**
     * Daftar kolom tanpa label pada satu isi view.
     *
     * @return list<string>
     */
    private function kolomTanpaLabel(string $isi): array
    {
        // `{{ … }}` boleh memuat `>` (mis. `$item->id`); jangan memotong tag di sana.
        preg_match_all('/<(input|textarea)\b(?:[^>{]|\{\{.*?\}\})*>/is', $isi, $semua, PREG_OFFSET_CAPTURE);

        $temuan = [];

        foreach ($semua[0] as $i => [$tag, $offset]) {
            $elemen = strtolower($semua[1][$i][0]);

            if ($elemen === 'input') {
                $tipe = 'text';

                if (preg_match('/\btype\s*=\s*["\']?([a-z]+)/i', $tag, $m)) {
                    $tipe = strtolower($m[1]);
                }

                if (in_array($tipe, self::TIPE_LEWATI, true)) {
                    continue;
                }

                if (preg_match('/\breadonly\b/i', $tag)) {
                    continue;
                }

                $bolehTanpaNama = in_array($tipe, ['checkbox', 'radio'], true);

                if (! $bolehTanpaNama && ! preg_match('/\bname\s*=/i', $tag)) {
                    continue;
                }
            }

            if ($this->punyaLabel($isi, $offset, $tag)) {
                continue;
            }

            $nama = preg_match('/\bname\s*=\s*["\']([^"\']+)["\']/i', $tag, $m)
                ? $m[1]
                : $elemen.'('.(
                    preg_match('/\btype\s*=\s*["\']?([a-z]+)/i', $tag, $t) ? $t[1] : '?'
                ).')';

            $baris = substr_count(substr($isi, 0, $offset), "\n") + 1;

            $temuan[] = "{$nama} (baris {$baris})";
        }

        return $temuan;
    }

    /* =====================================================================
     | Test
     ===================================================================== */

    public function test_tidak_ada_berkas_view_baru_yang_menjadi_hutang_ux(): void
    {
        $bermasalah = [];

        foreach ($this->berkasView() as $berkas) {
            $temuan = $this->kolomTanpaLabel(file_get_contents(base_path($berkas)));

            if ($temuan !== []) {
                $bermasalah[$berkas] = $temuan;
            }
        }

        $baru = array_diff(array_keys($bermasalah), self::HUTANG);

        $this->assertSame(
            [],
            array_values($baru),
            "Berkas berikut memiliki kolom isian tanpa label tertaut:\n".json_encode(
                array_intersect_key($bermasalah, array_flip($baru)),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            )
        );
    }

    /**
     * Penjaga: bila pengecualian dicabut tanpa perbaikan, test ini memaksa
     * berkasnya benar-benar bersih.
     */
    public function test_setiap_berkas_hutang_sudah_bersih(): void
    {
        $masih = [];

        foreach (self::HUTANG as $berkas) {
            $this->assertFileExists(base_path($berkas));

            $temuan = $this->kolomTanpaLabel(file_get_contents(base_path($berkas)));

            if ($temuan !== []) {
                $masih[$berkas] = $temuan;
            }
        }

        $this->assertSame([], $masih, 'Berkas hutang UX belum diperbaiki.');
    }
}
