<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\TellerPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        // BUG-08 — Otorisasi operasional loket teller (defense-in-depth).
        // Memberi lapisan `@can` pada view teller sehingga tombol aksi hanya
        // dirender untuk peran yang berwenang; middleware rute & controller
        // tetap menjadi penentu akhir.
        User::class => TellerPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        // K-1 — ability `role` dipakai view lewat
        // `@can('role', Izin::peran('pembiayaan.pengajuan.masukan'))`.
        // Tanpa definisi ini Gate tidak menemukan callback sama sekali dan
        // selalu mengembalikan false, sehingga blok `@can` tidak pernah
        // dirender untuk peran mana pun (termasuk AO & Manajer).
        //
        // Ability ini hanya menerjemahkan daftar peran yang diminta pemanggil
        // menjadi keputusan boolean. Sumber kewenangan tetap `config/izin.php`
        // (view membaca daftar perannya dari sana) + middleware `izin:*` pada
        // rute; tidak ada bypass implisit.
        Gate::define('role', function (User $user, mixed ...$peran): bool {
            $peran = array_filter(
                Arr::flatten($peran),
                fn ($p): bool => is_string($p) && $p !== ''
            );

            return in_array((string) $user->role, $peran, true);
        });
    }
}
