<?php

namespace App\Services;

use App\Contracts\PolicySourceInterface;
use App\Support\Setting;

/**
 * Adaptor kontrak `PolicySourceInterface` → sumber kebijakan statis `Setting`.
 *
 * `App\Support\Setting` menyajikan kebijakan lewat method statis (dipakai
 * ratusan pemanggil). Agar modul baru dapat bergantung pada KONTRAK dan
 * menukar sumber kebijakan lewat container (mis. mock pada test), adaptor ini
 * mendelegasikan setiap operasi baca ke `Setting` tanpa mengubah perilaku.
 *
 * Ini lapisan aditif: tidak ada satu pun pemanggil lama yang berubah.
 */
class SettingPolicySource implements PolicySourceInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        return Setting::get($key, $default);
    }

    public function all(): array
    {
        return Setting::all();
    }

    public function nominal(string $key, float $default = 0.0): float
    {
        return Setting::nominal($key, $default);
    }

    public function bool(string $key, bool $default = false): bool
    {
        return Setting::bool($key, $default);
    }

    public function persen(string $key, float $default = 0.0): float
    {
        return Setting::persen($key, $default);
    }

    public function toleransiSelisihJurnal(): float
    {
        return Setting::toleransiSelisihJurnal();
    }
}
