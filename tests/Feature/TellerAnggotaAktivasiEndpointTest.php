<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\Coa;
use App\Models\JenisSimpanan;
use App\Models\KebijakanKoperasi;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use App\Support\Coa as KodeCoa;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * END-TO-END ENDPOINT TELLER ANGGOTA (regresi anggota 8560).
 *
 * Mengunci dua jalur HTTP yang terlibat pada kasus anggota 8560:
 *  1. POST `teller.setor.store` — setelah KEDUA setoran awal (Simpanan Pokok &
 *     Simpanan Wajib) tersimpan sesuai nominal yang ditagih saat registrasi,
 *     status anggota PENDING harus otomatis berubah menjadi ACTIVE.
 *  2. GET `teller.anggota.show` — halaman profil anggota harus menampilkan
 *     badge status "Aktif" (bukan "Menunggu Setoran Awal").
 *
 * Skenario nyata yang diregresi: kebijakan koperasi menagih Pokok 50.000,
 * sedangkan `jenis_simpanans.min_deposit_amount` produk POKOK lebih tinggi
 * (100.000). Ambang aktivasi wajib mengikuti KEBIJAKAN, bukan ambang produk.
 *
 * Basis data dev memuat data nyata → memakai prefix unik + DatabaseTransactions.
 */
class TellerAnggotaAktivasiEndpointTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'TLRANG'.strtoupper(substr(uniqid(), -6));
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    private function userTeller(): User
    {
        $user = User::firstOrCreate(
            ['email' => "teller.{$this->prefix}@test.local"],
            [
                'name' => 'Teller '.$this->prefix,
                'password' => Hash::make('password'),
                'role' => 'teller',
                'is_active' => true,
            ]
        );

        // Rute `teller.setor.store` dijaga middleware `kas.terbuka`.
        $this->bukaSesiKas($user);

        return $user;
    }

    private function pastikanCoa(): void
    {
        foreach ([KodeCoa::KAS_TELLER, KodeCoa::SIMPANAN_POKOK, KodeCoa::SIMPANAN_WAJIB_MODAL] as $kode) {
            if (! Coa::findByKode($kode)) {
                $this->markTestSkipped("CoA {$kode} belum tersedia — jalankan CoaSeeder.");
            }
        }
    }

    private function anggotaPending(): Anggota
    {
        return Anggota::create([
            'no_anggota' => 'ANG-'.$this->prefix,
            'nama_lengkap' => 'Anggota Endpoint '.substr($this->prefix, -4),
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'jenis_kelamin' => 'Laki-laki',
            'no_hp' => '08'.str_pad((string) random_int(1, 9999999999), 10, '0', STR_PAD_LEFT),
            'alamat' => 'Jl. Uji Endpoint No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_PENDING,
        ]);
    }

    /** Isi tabel `akun` (FK wajib `rekening.akun_id`) — lewati bila seeder belum dijalankan. */
    private function akunId(): int
    {
        $id = Akun::query()->value('id');

        if (! $id) {
            $this->markTestSkipped('Tabel akun kosong — jalankan AkunSeeder.');
        }

        return (int) $id;
    }

    private function rekening(Anggota $anggota, string $jenis, int $saldo = 0): Rekening
    {
        return Rekening::create([
            'no_rekening' => Rekening::nomorRekeningBaru(),
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunId(),
            'jenis_rekening' => $jenis,
            'akad' => 'Wadiah',
            'saldo' => $saldo,
            'batas_minimum' => 0,
            'tanggal_buka' => today()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);
    }

    /* =====================================================================
     | UJI
     |===================================================================== */

    public function test_setoran_awal_kedua_kali_mengaktifkan_anggota_dan_halaman_menampilkan_aktif(): void
    {
        $this->pastikanCoa();

        $kebijakan = KebijakanKoperasi::getSetting();
        $asli = [
            'mandatory_principal_savings' => $kebijakan->mandatory_principal_savings,
            'mandatory_monthly_savings' => $kebijakan->mandatory_monthly_savings,
            'minimum_transaksi_teller' => $kebijakan->minimum_transaksi_teller,
        ];
        $produkPokok = JenisSimpanan::where('code', JenisSimpanan::CODE_POKOK)->first();
        $minProdukAsli = $produkPokok?->min_deposit_amount;

        // Kebijakan menagih Pokok 50.000 + Wajib 50.000; produk POKOK sengaja
        // berambang lebih tinggi agar perbedaan sumber kebenaran teruji.
        // Ambang minimum transaksi teller diturunkan agar setoran awal
        // (20.000/30.000) tidak tertahan validasi `min:` lebih dulu — nilai
        // asli dikembalikan pada blok finally di bawah.
        $kebijakan->update([
            'mandatory_principal_savings' => 50000.00,
            'mandatory_monthly_savings' => 50000.00,
            'minimum_transaksi_teller' => 1000.00,
        ]);
        Setting::flush();

        $produkPokok?->update(['min_deposit_amount' => 100000]);
        JenisSimpanan::flush();

        try {
            $anggota = $this->anggotaPending();
            $pokok = $this->rekening($anggota, 'Simpanan Pokok');
            $wajib = $this->rekening($anggota, 'Simpanan Wajib');
            $teller = $this->userTeller();

            // Setoran 1: Simpanan Pokok 20.000 (belum lengkap → tetap PENDING).
            $this->actingAs($teller)
                ->post(route('teller.setor.store'), [
                    'rekening_id' => $pokok->id,
                    'jumlah' => 20000,
                ])
                ->assertRedirect();

            $this->assertSame(
                Anggota::STATUS_PENDING,
                $anggota->fresh()->status_anggota,
                'Setoran belum lengkap tidak boleh mengaktifkan anggota.'
            );

            // Setoran 2: Simpanan Pokok 30.000 (total Pokok = 50.000 sesuai tagihan).
            $this->actingAs($teller)
                ->post(route('teller.setor.store'), [
                    'rekening_id' => $pokok->id,
                    'jumlah' => 30000,
                ])
                ->assertRedirect();

            // Setoran 3: Simpanan Wajib 50.000 — dengan ini KEDUA setoran awal lengkap.
            $this->actingAs($teller)
                ->post(route('teller.setor.store'), [
                    'rekening_id' => $wajib->id,
                    'jumlah' => 50000,
                ])
                ->assertRedirect();

            $this->assertSame(
                Anggota::STATUS_ACTIVE,
                $anggota->fresh()->status_anggota,
                'Status anggota harus otomatis ACTIVE setelah kedua setoran awal tersimpan & terjurnal.'
            );

            // Kedua setoran awal benar-benar tercatat & berstatus Sukses.
            $this->assertSame(3, Transaksi::where('anggota_id', $anggota->id)->count());
            $this->assertSame(
                3,
                Transaksi::where('anggota_id', $anggota->id)->where('status_transaksi', 'Sukses')->count()
            );

            // Halaman profil anggota menampilkan badge "Aktif".
            $this->actingAs($teller)
                ->get(route('teller.anggota.show', $anggota->id))
                ->assertOk()
                ->assertSee('Aktif')
                ->assertDontSee('Menunggu Setoran Awal');
        } finally {
            $kebijakan->update($asli);
            Setting::flush();

            if ($produkPokok && $minProdukAsli !== null) {
                $produkPokok->update(['min_deposit_amount' => $minProdukAsli]);
                JenisSimpanan::flush();
            }
        }
    }

    public function test_halaman_anggota_pending_menampilkan_menunggu_setoran_awal(): void
    {
        $anggota = $this->anggotaPending();

        $this->actingAs($this->userTeller())
            ->get(route('teller.anggota.show', $anggota->id))
            ->assertOk()
            ->assertSee('Menunggu Setoran Awal');
    }
}
