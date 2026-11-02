<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Aksesibilitas Antarmuka — WCAG 2.1 Level AA (Poin 4.2 — item tertunda).
 *
 * Test ini mengunci kontrak aksesibilitas pada layout bersama
 * (`layouts.app` + `layouts.sidebar`) dan halaman Teller Simpanan Wajib,
 * sehingga regresi markup langsung tertangkap:
 *
 *  1. 1.3.1 Informasi & Hubungan   → landmark `main`, `nav`, tabel semantik.
 *  2. 2.4.1 Bypass Blocks          → tautan "Lewati ke konten utama".
 *  3. 2.4.6 Headings & Labels      → tepat satu <h1> per halaman.
 *  4. 3.1.1 Bahasa Halaman         → <html lang="id">.
 *  5. 1.4.10 Reflow                → meta viewport + media query <=900px + pemicu sidebar.
 *  6. 4.1.2 Nama, Peran, Nilai     → tombol tidak tanpa nama, aria-expanded/controls,
 *                                    label eksplisit pada field formulir.
 *  7. 2.2.2 / 2.3.3                → dukungan prefers-reduced-motion.
 *  8. 2.4.7 Fokus Terlihat         → gaya :focus-visible global.
 *
 * Basis data memuat data nyata → memakai prefix unik + DatabaseTransactions.
 */
class AksesibilitasLayoutTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'A11Y'.strtoupper(substr(uniqid(), -6));
    }

    private function user(string $role = 'teller'): User
    {
        return User::firstOrCreate(
            ['email' => "{$role}.{$this->prefix}@test.local"],
            [
                'name' => ucfirst($role).' '.$this->prefix,
                'password' => Hash::make('password'),
                'role' => $role,
            ]
        );
    }

    private function html(string $route, array $params = []): string
    {
        return $this->actingAs($this->user())
            ->get(route($route, $params))
            ->assertOk()
            ->getContent();
    }

    /** Halaman publik (tanpa login) — mis. formulir pembukaan rekening. */
    private function htmlPublik(string $route): string
    {
        return $this->get(route($route))->assertOk()->getContent();
    }

    /* =====================================================================
     | 1. STRUKTUR & BAHASA
     |===================================================================== */

    public function test_halaman_menyatakan_bahasa_indonesia(): void
    {
        $this->assertStringContainsString(
            '<html lang="id"',
            $this->html('teller.simpanan-wajib.index'),
            'WCAG 3.1.1 — bahasa halaman harus dinyatakan (lang="id").'
        );
    }

    public function test_layout_menyediakan_landmark_dan_tautan_lewati_konten(): void
    {
        $html = $this->html('teller.simpanan-wajib.index');

        $this->assertMatchesRegularExpression(
            '/<a[^>]*href="#konten-utama"[^>]*class="[^"]*skip-link/',
            $html,
            'WCAG 2.4.1 — tautan "lewati ke konten utama" harus tersedia bagi pengguna papan tik.'
        );
        $this->assertMatchesRegularExpression(
            '/<main[^>]*id="konten-utama"/',
            $html,
            'WCAG 1.3.1 — konten utama harus ditandai dengan landmark <main>.'
        );
        $this->assertMatchesRegularExpression(
            '/<nav[^>]*aria-label="Navigasi utama"/',
            $html,
            'WCAG 1.3.1 — navigasi harus berupa landmark <nav> berlabel.'
        );
    }

    public function test_halaman_hanya_memiliki_satu_heading_utama(): void
    {
        $html = $this->html('teller.simpanan-wajib.index');

        $jumlahH1 = preg_match_all('/<h1[\s>]/i', $html);

        $this->assertSame(
            1,
            $jumlahH1,
            'WCAG 2.4.6 — setiap halaman harus memiliki tepat satu <h1> sebagai judul utama.'
        );
    }

    /* =====================================================================
     | 2. REFLOW / RESPONSIF
     |===================================================================== */

    public function test_meta_viewport_mendukung_reflow(): void
    {
        $html = $this->html('teller.simpanan-wajib.index');

        $this->assertStringContainsString(
            'name="viewport"',
            $html,
            'WCAG 1.4.10 — meta viewport wajib ada agar konten dapat direflow.'
        );
        // `maximum-scale`/`user-scalable=no` melanggar WCAG 1.4.4 (Resize Text).
        $this->assertStringNotContainsString('user-scalable=no', $html, 'Zoom tidak boleh diblokir (WCAG 1.4.4).');
        $this->assertStringNotContainsString('maximum-scale=1', $html, 'Zoom tidak boleh dibatasi (WCAG 1.4.4).');
    }

    public function test_layout_menyediakan_media_query_reflow_dan_pemicu_sidebar(): void
    {
        $html = $this->html('teller.simpanan-wajib.index');

        $this->assertStringContainsString('@media (max-width: 900px)', $html, 'WCAG 1.4.10 — media query reflow hilang.');
        $this->assertStringContainsString('@media (max-width: 480px)', $html, 'WCAG 1.4.10 — breakpoint ponsel hilang.');
        $this->assertMatchesRegularExpression(
            '/<button[^>]*id="sidebarToggle"[^>]*aria-controls="sidebarUtama"[^>]*aria-expanded="false"/s',
            $html,
            'WCAG 1.4.10/4.1.2 — pemicu sidebar harus berupa tombol dengan aria-controls & aria-expanded awal false.'
        );
        $this->assertMatchesRegularExpression(
            '/<nav[^>]*id="sidebarUtama"/',
            $html,
            'Pemicu sidebar harus menunjuk ke elemen <nav id="sidebarUtama">.'
        );
    }

    /* =====================================================================
     | 3. KOMPONEN INTERAKTIF (NAMA, PERAN, NILAI)
     |===================================================================== */

    public function test_tombol_profil_dapat_diakses_papan_tik_dan_pembaca_layar(): void
    {
        $html = $this->html('teller.simpanan-wajib.index');

        $this->assertMatchesRegularExpression(
            '/<button[^>]*id="profileBtn"[^>]*aria-haspopup="true"[^>]*aria-expanded="false"/s',
            $html,
            'WCAG 4.1.2 — pemicu profil harus <button> dengan aria-haspopup & aria-expanded.'
        );
        $this->assertStringContainsString(
            'aria-controls="profileMenu"',
            $html,
            'WCAG 4.1.2 — pemicu profil harus mengendalikan menu dropdown secara eksplisit.'
        );
        $this->assertStringNotContainsString(
            'onclick="toggleProfile()"></div>',
            $html,
            'WCAG 4.1.2/2.1.1 — pemicu profil tidak boleh berupa <div> (tidak dapat difokus papan tik).'
        );
    }

    public function test_skrip_menutup_menu_dengan_tombol_escape(): void
    {
        $html = $this->html('teller.simpanan-wajib.index');

        $this->assertStringContainsString(
            "e.key !== 'Escape'",
            $html,
            'WCAG 2.1.2 — menu harus dapat ditutup tanpa papan tik (tombol Escape).'
        );
    }

    public function test_formulir_memiliki_label_eksplisit(): void
    {
        $html = $this->html('teller.simpanan-wajib.index');

        // Setiap `<label>` harus menunjuk id kontrol lewat atribut `for`.
        preg_match_all('/<label\b[^>]*>/i', $html, $labels);
        $this->assertNotEmpty($labels[0], 'Halaman filter harus memakai elemen <label>.');

        foreach ($labels[0] as $label) {
            $this->assertMatchesRegularExpression(
                '/\bfor="[^"]+"/',
                $label,
                "WCAG 1.3.1/4.1.2 — setiap <label> wajib menunjuk kontrolnya via `for`. Label bermasalah: {$label}"
            );
        }
    }

    public function test_tabel_daftar_memiliki_caption_dan_header_bercakupan(): void
    {
        $html = $this->html('teller.simpanan-wajib.index');

        $this->assertMatchesRegularExpression(
            '/<caption[^>]*class="[^"]*sr-only[^"]*"[^>]*>/',
            $html,
            'WCAG 1.3.1 — tabel menampilkan caption bagi pembaca layar (sr-only).'
        );
        $this->assertMatchesRegularExpression(
            '/<th[^>]*scope="col"/',
            $html,
            'WCAG 1.3.1 — header kolom tabel harus memakai scope="col".'
        );
    }

    public function test_ikon_dan_badge_dinamis_disembunyikan_dari_pembaca_layar(): void
    {
        $html = $this->html('teller.simpanan-wajib.index');

        $this->assertMatchesRegularExpression(
            '/<span class="icon" aria-hidden="true">/',
            $html,
            'WCAG 1.1.1 — ikon dekoratif harus aria-hidden agar tidak dibacakan ganda.'
        );
    }

    /* =====================================================================
     | 4. PREFERENSI PENGGUNA & FOKUS
     |===================================================================== */

    public function test_layout_menghormati_prefers_reduced_motion(): void
    {
        $html = $this->html('teller.simpanan-wajib.index');

        $this->assertStringContainsString(
            '@media (prefers-reduced-motion: reduce)',
            $html,
            'WCAG 2.3.3 — animasi harus dinonaktifkan bila pengguna meminta gerak minimal.'
        );
    }

    public function test_layout_menyediakan_indikator_fokus_terlihat(): void
    {
        $html = $this->html('teller.simpanan-wajib.index');

        $this->assertStringContainsString(':focus-visible', $html, 'WCAG 2.4.7 — indikator fokus terlihat wajib ada.');
        $this->assertStringContainsString('--focus-ring', $html, 'WCAG 2.4.7 — warna cincin fokus harus konsisten.');
    }

    public function test_wilayah_notifikasi_diumumkan_secara_sopan(): void
    {
        $html = $this->html('teller.simpanan-wajib.index');

        $this->assertStringContainsString(
            'aria-live="polite"',
            $html,
            'WCAG 4.1.3 — pesan status (sukses/peringatan/galat) harus diumumkan ke pembaca layar.'
        );
    }

    /* =====================================================================
     | 5. HALAMAN PUBLIK (FORMULIR PEMBUKAAN REKENING)
     |===================================================================== */

    public function test_halaman_publik_menyediakan_landmark_dan_tautan_lewati_konten(): void
    {
        $html = $this->htmlPublik('pengajuan-rekening.publik.form');

        $this->assertStringContainsString('<html lang="id"', $html, 'WCAG 3.1.1 — bahasa halaman publik.');
        $this->assertMatchesRegularExpression(
            '/<a[^>]*href="#konten-utama"[^>]*class="[^"]*skip-link/',
            $html,
            'WCAG 2.4.1 — halaman publik juga butuh tautan lewati ke konten utama.'
        );
        $this->assertMatchesRegularExpression(
            '/<main[^>]*id="konten-utama"/',
            $html,
            'WCAG 1.3.1 — konten publik harus dibungkus landmark <main>.'
        );
    }

    public function test_formulir_publik_memiliki_label_eksplisit(): void
    {
        $html = $this->htmlPublik('pengajuan-rekening.publik.form');

        preg_match_all('/<label\b[^>]*>/i', $html, $labels);
        $this->assertNotEmpty($labels[0], 'Formulir publik harus memakai elemen <label>.');

        // Dua teknik pelabelan yang sah menurut WCAG 1.3.1/4.1.2:
        //  (a) label eksplisit  → <label for="id">, dan
        //  (b) label implisit   → kontrol berada DI DALAM <label> (mis. kartu pilihan radio).
        // Label implisit memang tidak memakai `for`; itu benar, bukan cacat.
        $eksplisit = 0;
        foreach ($labels[0] as $label) {
            if (preg_match('/\bfor="[^"]+"/', $label)) {
                $eksplisit++;

                continue;
            }

            $this->assertMatchesRegularExpression(
                '/class="[^"]*simpanan-option/',
                $label,
                "WCAG 1.3.1/4.1.2 — <label> tanpa `for` hanya sah bila membungkus kontrolnya langsung. Bermasalah: {$label}"
            );
        }

        $this->assertGreaterThan(
            0,
            $eksplisit,
            'WCAG 1.3.1 — minimal label field teks harus memakai pelabelan eksplisit (`for`).'
        );
    }

    public function test_formulir_publik_radio_dapat_difokus_papan_tik(): void
    {
        $html = $this->htmlPublik('pengajuan-rekening.publik.form');

        $this->assertStringContainsString(
            '<legend',
            $html,
            'WCAG 1.3.1 — kelompok radio jenis simpanan harus dikelompokkan dengan <fieldset>/<legend>.'
        );
        $this->assertStringNotContainsString(
            'style="display:none;"',
            $html,
            'WCAG 2.1.1 — radio pilihan tidak boleh disembunyikan dengan display:none (tidak dapat difokus papan tik).'
        );
        $this->assertStringContainsString(
            'class="sr-only"',
            $html,
            'WCAG 2.1.1 — radio tersembunyi secara visual harus tetap dapat difokus (sr-only).'
        );
    }

    public function test_formulir_publik_mengumumkan_pesan_status(): void
    {
        // Blok notifikasi hanya dirender saat ada flash message; suntikkan agar dapat diuji.
        $html = $this->withSession(['success' => 'Pengajuan diterima'])
            ->get(route('pengajuan-rekening.publik.form'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'role="status"',
            $html,
            'WCAG 4.1.3 — notifikasi sukses publik harus diumumkan (role=status).'
        );
        $this->assertStringContainsString(
            'aria-live="polite"',
            $html,
            'WCAG 4.1.3 — notifikasi sukses publik harus diumumkan tanpa menyela.'
        );
    }

    public function test_halaman_publik_menghormati_prefers_reduced_motion(): void
    {
        $html = $this->htmlPublik('pengajuan-rekening.publik.form');

        $this->assertStringContainsString(
            '@media (prefers-reduced-motion: reduce)',
            $html,
            'WCAG 2.3.3 — halaman publik harus menghormati preferensi gerak minimal.'
        );
        $this->assertStringContainsString(
            ':focus-visible',
            $html,
            'WCAG 2.4.7 — halaman publik harus punya indikator fokus terlihat.'
        );
    }
}
