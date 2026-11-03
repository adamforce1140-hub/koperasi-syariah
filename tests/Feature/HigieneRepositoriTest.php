<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Pagar higiene repositori: berkas hasil kompilasi Blade tidak boleh
 * dilacak Git. Cache view dibangkitkan ulang otomatis setiap aplikasi
 * berjalan, sehingga bila ter-track setiap test run mengotori `git status`
 * dan riwayat commit berisi perubahan yang tidak bermakna.
 */
class HigieneRepositoriTest extends TestCase
{
    private function git(string ...$argumen): string
    {
        $process = new Process(['git', ...$argumen], base_path());
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            'Perintah git gagal: '.implode(' ', $argumen).PHP_EOL.$process->getErrorOutput()
        );

        return trim($process->getOutput());
    }

    public function test_berkas_cache_view_tidak_dilacak_git(): void
    {
        $terlacak = $this->git('ls-files', 'storage/framework/views');

        $this->assertSame(
            '',
            $terlacak,
            'Cache view ter-track Git. Keluarkan dengan: git rm --cached -r storage/framework/views'
        );
    }

    public function test_cache_view_diabaikan_gitignore(): void
    {
        $proses = new Process(
            ['git', 'check-ignore', '-v', 'storage/framework/views/contoh.blade.php'],
            base_path()
        );
        $proses->run();

        $this->assertSame(
            0,
            $proses->getExitCode(),
            'Cache view tidak diabaikan Git. Tambahkan /storage/framework/views/*.php ke .gitignore.'
        );
        $this->assertStringContainsString(
            '.gitignore:',
            $proses->getOutput(),
            'Aturan pengabaian cache view harus berasal dari .gitignore'
        );
        $this->assertStringContainsString(
            'storage/framework/views/*.php',
            $proses->getOutput(),
            'Pola /storage/framework/views/*.php hilang dari .gitignore'
        );
    }
}
