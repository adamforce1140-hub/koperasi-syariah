<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * Proxy tepercaya diambil dari env `TRUSTED_PROXIES` agar dapat disetel
     * tanpa mengubah kode. Beberapa alamat dapat dipisah dengan koma.
     * Nilai `*` mempercayai seluruh proxy (hanya aman bila aplikasi selalu
     * berada di belakang load balancer terkelola); kosong berarti tidak ada
     * proxy yang dipercaya (aman untuk akses langsung).
     */
    protected $proxies;

    public function __construct()
    {
        $this->proxies = $this->resolveProxies();
    }

    /**
     * Baca daftar proxy tepercaya dari env. Mengembalikan null bila tidak
     * disetel sehingga header X-Forwarded-* tidak dipercaya (aman default).
     */
    private function resolveProxies(): mixed
    {
        $proxies = env('TRUSTED_PROXIES');

        if ($proxies === null || $proxies === '') {
            return null;
        }

        if (trim((string) $proxies) === '*') {
            return '*';
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $proxies))));
    }

    protected $headers = Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
