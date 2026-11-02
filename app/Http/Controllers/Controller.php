<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * S-03 — Batas jumlah baris per halaman yang aman (cegah DoS).
     *
     * Sebelumnya controller memakai `paginate($request->per_page)` mentah,
     * sehingga klien dapat meminta `per_page` sangat besar (mis. 1.000.000)
     * dan membebani server. Nilai kini divalidasi bilangan bulat dan dibatasi
     * 1..$maks. Mengembalikan null bila parameter tidak dikirim (agar pemanggil
     * tetap memakai `get()` seperti perilaku sebelumnya).
     */
    protected function perPage(Request $request, int $maks = 100): ?int
    {
        if (! $request->filled('per_page')) {
            return null;
        }

        $data = $request->validate([
            'per_page' => ['integer', 'min:1', 'max:'.$maks],
        ]);

        return (int) $data['per_page'];
    }
}
