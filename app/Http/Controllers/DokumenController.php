<?php

namespace App\Http\Controllers;

use App\Support\HierarkiRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Pelayan dokumen berisi data pribadi (PII) yang tersimpan pada disk privat
 * `dokumen`. Dokumen TIDAK dapat diakses langsung lewat URL publik; setiap
 * permintaan harus melewati autentikasi dan pemeriksaan peran di sini.
 *
 * Peta prefiks folder → peran pembaca dipusatkan pada
 * `config/role_hierarki.php` (`izin_dokumen`) dan dibaca lewat
 * `HierarkiRole::izinDokumen()` / `bolehUnduhDokumen()`, sehingga menambah
 * peran baru tidak lagi mengubah berkas ini.
 */
class DokumenController extends Controller
{
    /**
     * Unduh dokumen privat setelah memverifikasi peran pengguna.
     *
     * @param  string  $path  Jalur relatif dokumen di dalam disk `dokumen`.
     */
    public function unduh(Request $request, string $path): BinaryFileResponse
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        // Tolak jalur kosong, traversal (`..`), atau jalur absolut.
        if ($path === '' || str_contains($path, '..')) {
            abort(404);
        }

        $prefiks = explode('/', $path)[0];

        // Prefiks di luar peta dianggap tidak ada (404) sehingga tidak ada
        // berkas yang dapat diunduh secara tak sengaja.
        if (HierarkiRole::izinDokumen($prefiks) === []) {
            abort(404);
        }

        if (! HierarkiRole::bolehUnduhDokumen($request->user()->role, $prefiks)) {
            abort(403, 'Akses ditolak. Anda tidak berhak mengunduh dokumen ini.');
        }

        $disk = Storage::disk('dokumen');

        if (! $disk->exists($path)) {
            abort(404);
        }

        return response()->file($disk->path($path));
    }
}
