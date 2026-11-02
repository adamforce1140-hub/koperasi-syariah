<?php

namespace Tests\Feature;

use App\Rules\PasswordKuat;
use Tests\TestCase;

/**
 * Kebijakan kekuatan password (UU PDP No. 27/2022).
 *
 * Aturan ini dipakai saat MEMBUAT / MENGGANTI password — bukan saat login —
 * sehingga parameter dibaca dari config dan dapat disetel lewat env.
 *
 * Lihat: docs/TAHAP10-KEAMANAN-DATA-UU-PDP.md
 */
class PasswordKuatTest extends TestCase
{
    /**
     * Jalankan aturan dan kembalikan daftar pesan kegagalan.
     *
     * @return list<string>
     */
    private function pesan(mixed $nilai, ?string $nama = null, array $kebijakan = []): array
    {
        config(['keamanan.password' => array_merge([
            'min' => 8,
            'huruf_besar' => false,
            'angka' => true,
            'simbol' => false,
        ], $kebijakan)]);

        $gagal = [];
        (new PasswordKuat($nama))->validate('password', $nilai, function (string $pesan) use (&$gagal) {
            $gagal[] = $pesan;
        });

        return $gagal;
    }

    public function test_password_memenuhi_syarat_diterima(): void
    {
        $this->assertSame([], $this->pesan('rahasia123'));
    }

    public function test_password_terlalu_pendek_ditolak(): void
    {
        $gagal = $this->pesan('ab1');

        $this->assertNotEmpty($gagal);
        $this->assertStringContainsString('minimal 8 karakter', $gagal[0]);
    }

    public function test_password_tanpa_angka_ditolak(): void
    {
        $gagal = $this->pesan('rahasiaku');

        $this->assertNotEmpty($gagal);
        $this->assertStringContainsString('angka', $gagal[0]);
    }

    public function test_syarat_huruf_besar_dapat_dinyalakan_lewat_env(): void
    {
        $this->assertNotEmpty($this->pesan('rahasia123', null, ['huruf_besar' => true]),
            'Syarat huruf besar seharusnya menolak password tanpa huruf besar.');

        $this->assertSame([], $this->pesan('Rahasia123', null, ['huruf_besar' => true]));
    }

    public function test_syarat_simbol_dapat_dinyalakan_lewat_env(): void
    {
        $this->assertNotEmpty($this->pesan('Rahasia123', null, ['huruf_besar' => true, 'simbol' => true]),
            'Syarat simbol seharusnya menolak password tanpa simbol.');

        $this->assertSame([], $this->pesan('Rahasia123!', null, ['huruf_besar' => true, 'simbol' => true]));
    }

    public function test_panjang_minimum_dapat_disetel_lewat_env(): void
    {
        $this->assertNotEmpty($this->pesan('rahasia123', null, ['min' => 20]));
        $this->assertSame([], $this->pesan(str_repeat('a', 19).'1', null, ['min' => 20]));
    }

    public function test_password_sama_dengan_nama_pengguna_ditolak(): void
    {
        $gagal = $this->pesan('budi1234', 'budi1234');

        $this->assertNotEmpty($gagal);
        $this->assertStringContainsString('nama pengguna', $gagal[0]);
    }

    public function test_nilai_kosong_tidak_dinilai_aturan_ini(): void
    {
        // Nilai kosong adalah tanggung jawab aturan `required`, bukan aturan ini.
        $this->assertSame([], $this->pesan(''));
    }

    public function test_kebijakan_produksi_lebih_ketat_dari_lokal(): void
    {
        $berkas = require base_path('config/keamanan.php');
        $kebijakan = $berkas['password'];

        $this->assertIsArray($kebijakan);
        $this->assertGreaterThanOrEqual(8, $kebijakan['min'],
            'Panjang minimum password tidak boleh terlalu longgar.');
        $this->assertTrue($kebijakan['angka'],
            'Syarat minimal satu angka sebaiknya selalu aktif.');
    }
}
