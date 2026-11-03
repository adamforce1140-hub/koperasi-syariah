<?php

namespace Tests\Unit;

use App\Support\TeksLabelAman;
use PHPUnit\Framework\TestCase;

/**
 * S-2 — penyaring markup label `<x-label-berkas>`.
 *
 * Komponen label menerima teks berisi HTML agar halaman lama yang menulis
 * penanda wajib (`<span style="color:#dc2626;">*</span>`) tidak perlu dirombak.
 * Penyaringnya wajib ALLOWLIST sempit; test ini mengunci semua pintu yang
 * biasanya disalahgunakan.
 */
class TeksLabelAmanTest extends TestCase
{
    public function test_teks_biasa_di_escape(): void
    {
        $this->assertSame(
            'Keterangan &lt;script&gt; &amp; Biaya',
            TeksLabelAman::saring('Keterangan <script> & Biaya')
        );
    }

    public function test_span_berwarna_diizinkan(): void
    {
        $this->assertSame(
            'Nominal <span style="color:#dc2626">*</span>',
            TeksLabelAman::saring('Nominal <span style="color:#dc2626;">*</span>')
        );

        $this->assertSame(
            'Nominal <span style="color:#dc2626">*</span>',
            TeksLabelAman::saring('Nominal <span style="color: #dc2626">*</span>')
        );
    }

    public function test_penangan_kejadian_dibuang(): void
    {
        $hasil = TeksLabelAman::saring('<span style="color:#dc2626;" onclick="alert(1)">*</span>');

        // Keseluruhan tag dibiarkan ter-escape (tampil sebagai teks), bukan
        // menjadi `<span>` hidup dengan atribut yang hilang sebagian.
        $this->assertStringNotContainsString('<span', $hasil);
        $this->assertStringContainsString('&lt;span', $hasil);
        $this->assertDoesNotMatchRegularExpression(
            '/<[a-z]+[^>]*\bonclick/i',
            $hasil,
            'Tidak boleh ada tag hidup yang membawa atribut penangan kejadian.'
        );
    }

    public function test_style_yang_bukan_warna_heksadesimal_dibuang(): void
    {
        foreach ([
            '<span style="background:url(javascript:alert(1))">x</span>',
            '<span style="color:expression(alert(1))">x</span>',
            '<span style="color:red">x</span>',
            '<span style="color:#12345">x</span>',
        ] as $masukan) {
            $hasil = TeksLabelAman::saring($masukan);

            $this->assertStringNotContainsString('<span', $hasil, $masukan);
            $this->assertStringNotContainsString('style="', $hasil, $masukan);
            $this->assertStringContainsString('&lt;span', $hasil, $masukan);
        }
    }

    public function test_gaya_warna_sah_masih_dipulihkan(): void
    {
        $this->assertSame(
            '<small style="color:#f00">wajib</small>',
            TeksLabelAman::saring('<small style="color:#f00">wajib</small>')
        );

        $this->assertSame(
            '<span style="color:#dc2626">*</span>',
            TeksLabelAman::saring('<span style="color:#dc2626;">*</span>')
        );
    }

    public function test_tag_di_luar_allowlist_di_escape(): void
    {
        foreach (['<b>', '<img src=x onerror=alert(1)>', '<iframe>', '<svg>', '<a href="#">'] as $tag) {
            $hasil = TeksLabelAman::saring($tag.'isi</a>');

            $this->assertStringNotContainsString('<'.$tag[1], preg_replace('/&lt;\/?a&gt;/', '', $hasil), $tag);
        }
    }

    public function test_tag_tak_berpasangan_stay_aman(): void
    {
        $hasil = TeksLabelAman::saring('<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script', $hasil);
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $hasil);
    }
}
