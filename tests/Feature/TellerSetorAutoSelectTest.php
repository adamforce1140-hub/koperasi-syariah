<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Coa;
use App\Models\Rekening;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * SINKRONISASI TELLER — auto-select anggota & rekening.
 *
 * Spesifikasi Bagian 2 butir 2:
 *   Halaman `teller/setor?anggota_id={id}` WAJIB mengikat (auto-select)
 *   anggota beserta seluruh rekening simpanannya (Pokok, Wajib, Sukarela)
 *   ke dalam dropdown Rekening — tanpa menampilkan placeholder
 *   "-- Pilih Anggota Dulu --".
 */
class TellerSetorAutoSelectTest extends TestCase
{
    use DatabaseTransactions;

    private const URL_SETOR = '/teller/setor';

    private User $teller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teller = User::firstOrCreate(
            ['email' => 'teller.autoselect@test.local'],
            [
                'name' => 'Teller Auto-Select Uji',
                'password' => Hash::make('password'),
                'role' => 'teller',
                'is_active' => true,
            ]
        );
    }

    /** Anggota uji beserta 3 rekening simpanan (Pokok, Wajib, Sukarela). */
    private function anggotaDenganTigaRekening(): Anggota
    {
        $anggota = Anggota::create([
            'no_anggota' => 'ANG-TEST-'.strtoupper(substr(uniqid(), -6)),
            'nama_lengkap' => 'Anggota Auto Select '.substr(uniqid(), -4),
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'jenis_kelamin' => 'Laki-laki',
            'no_hp' => '08'.str_pad((string) random_int(1, 9999999999), 10, '0', STR_PAD_LEFT),
            'alamat' => 'Jl. Uji Auto Select No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_PENDING,
        ]);

        foreach (['Simpanan Pokok' => '3-1001', 'Simpanan Wajib' => '3-1002', 'Simpanan Sukarela' => '2-1001'] as $jenis => $kodeCoa) {
            $coa = Coa::where('kode_akun', $kodeCoa)->first();

            if (! $coa) {
                $this->markTestSkipped('CoA '.$kodeCoa.' belum tersedia — jalankan seeder CoA.');
            }

            Rekening::create([
                'no_rekening' => Rekening::nomorRekeningBaru(),
                'anggota_id' => $anggota->id,
                'akun_id' => $coa->id,
                'jenis_rekening' => $jenis,
                'akad' => 'Wadiah',
                'saldo' => 0,
                'batas_minimum' => 0,
                'tanggal_buka' => today()->toDateString(),
                'status_rekening' => 'Aktif',
            ]);
        }

        return $anggota->fresh();
    }

    public function test_form_setor_dengan_anggota_id_mengikat_anggota(): void
    {
        $anggota = $this->anggotaDenganTigaRekening();

        $response = $this->actingAs($this->teller)
            ->get(self::URL_SETOR.'?anggota_id='.$anggota->id);

        $response->assertOk();

        // Anggota terpilih harus tersedia sebagai `$anggotaTerpilih` — inilah
        // yang membuat dropdown langsung terisi tanpa placeholder.
        $response->assertViewHas('anggotaTerpilih', function ($terpilih) use ($anggota) {
            return $terpilih !== null && (int) $terpilih->id === (int) $anggota->id;
        });

        // Opsi anggota harus ditandai `selected` pada markup.
        $response->assertSee('value="'.$anggota->id.'" selected', false);
    }

    public function test_endpoint_rekening_mode_setor_mengembalikan_tiga_rekening(): void
    {
        $anggota = $this->anggotaDenganTigaRekening();

        $response = $this->actingAs($this->teller)
            ->getJson(route('teller.rekening-by-anggota').'?mode=setor&anggota_id='.$anggota->id);

        $response->assertOk();

        $jenis = collect($response->json())->pluck('jenis_rekening')->sort()->values()->all();

        $this->assertSame(
            ['Simpanan Pokok', 'Simpanan Sukarela', 'Simpanan Wajib'],
            $jenis,
            'Mode setor wajib mengembalikan seluruh rekening simpanan anggota.'
        );
    }

    /** Mode penarikan (default) tetap mengecualikan rekening Modal/Ekuitas. */
    public function test_endpoint_rekening_tanpa_mode_setor_hanya_sukarela(): void
    {
        $anggota = $this->anggotaDenganTigaRekening();

        $response = $this->actingAs($this->teller)
            ->getJson(route('teller.rekening-by-anggota').'?anggota_id='.$anggota->id);

        $response->assertOk();

        $jenis = collect($response->json())->pluck('jenis_rekening')->values()->all();

        $this->assertSame(
            ['Simpanan Sukarela'],
            $jenis,
            'Tanpa mode setor, hanya rekening non-modal (Sukarela) yang boleh tampil (PSAK 101 ¶ 31).'
        );
    }

    public function test_tanpa_anggota_id_dropdown_rekening_menampilkan_placeholder(): void
    {
        $response = $this->actingAs($this->teller)->get(self::URL_SETOR);

        $response->assertOk();
        $response->assertSee('-- Pilih Anggota Dulu --', false);
    }
}
