<?php

namespace Tests\Feature;

use App\Models\KasHarian;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * REGRESI #33 — closing kas demo harus memakai saldo sistem yang NYATA.
 *
 * Seeder lama menulis `fisik_kas` & `saldo_sistem` = Rp 12.000.000 secara
 * hardcode, padahal transaksi demo bertanggal beberapa bulan lalu sehingga
 * sesi kas hari ini hanya berisi modal awal. Akibatnya laporan kas harian
 * menampilkan angka fiktif dan `selisih = 0` yang tidak berdasar.
 *
 * Perbaikan: `tutupKasHarian()` menghitung saldo sistem lewat
 * `KasHarian::ringkasanPosisiKas()` — sumber yang sama dengan
 * `TutupKasAction` — lalu menyamakan kas fisik dengannya, sehingga
 * `status_closing` bernilai 'Balanced' (string sesuai skema), bukan boolean.
 */
class DemoDataSeederKasClosingTest extends TestCase
{
    use DatabaseTransactions;

    private function siapkanSesi(User $teller, float $modalAwal): KasHarian
    {
        return KasHarian::updateOrCreate(
            ['user_id' => $teller->id, 'tanggal' => now()->toDateString()],
            [
                'modal_awal' => $modalAwal,
                'tambah_modal' => null,
                'catatan_opening' => 'Opening uji',
                'waktu_opening' => now()->format('H:i:s'),
                'status_opname' => KasHarian::STATUS_OPEN,
                'fisik_kas' => null,
                'saldo_sistem' => null,
                'selisih' => null,
                'status_closing' => null,
                'waktu_closing' => null,
            ]
        );
    }

    public function test_closing_memakai_saldo_sistem_hasil_perhitungan(): void
    {
        $teller = User::firstOrCreate(
            ['email' => 'teller.kas.uji@test.local'],
            [
                'name' => 'Teller Kas Uji',
                'password' => Hash::make('password'),
                'role' => 'teller',
                'is_active' => true,
            ]
        );

        $kas = $this->siapkanSesi($teller, 10_000_000);

        $seeder = new DemoDataSeeder;
        $prop = new ReflectionProperty(DemoDataSeeder::class, 'teller');
        $prop->setAccessible(true);
        $prop->setValue($seeder, $teller);

        $metode = new ReflectionMethod(DemoDataSeeder::class, 'tutupKasHarian');
        $metode->setAccessible(true);
        $metode->invoke($seeder);

        $kas->refresh();
        $saldoSistem = (float) KasHarian::ringkasanPosisiKas($teller->id, now()->toDateString())['saldo'];

        $this->assertEqualsWithDelta($saldoSistem, (float) $kas->saldo_sistem, 0.01);
        $this->assertEqualsWithDelta((float) $kas->saldo_sistem, (float) $kas->fisik_kas, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $kas->selisih, 0.01);
        $this->assertSame('Balanced', $kas->status_closing);
        $this->assertNotNull($kas->waktu_closing);
    }

    public function test_saldo_sistem_bukan_angka_hardcode_dua_belas_juta(): void
    {
        $teller = User::firstOrCreate(
            ['email' => 'teller.kas.uji.2@test.local'],
            [
                'name' => 'Teller Kas Uji 2',
                'password' => Hash::make('password'),
                'role' => 'teller',
                'is_active' => true,
            ]
        );

        $kas = $this->siapkanSesi($teller, 7_500_000);

        $seeder = new DemoDataSeeder;
        $prop = new ReflectionProperty(DemoDataSeeder::class, 'teller');
        $prop->setAccessible(true);
        $prop->setValue($seeder, $teller);

        (new ReflectionMethod(DemoDataSeeder::class, 'tutupKasHarian'))
            ->invoke($seeder);

        $kas->refresh();

        $this->assertEqualsWithDelta(7_500_000.0, (float) $kas->saldo_sistem, 0.01);
        $this->assertNotEquals(12_000_000.0, (float) $kas->saldo_sistem);
    }
}
