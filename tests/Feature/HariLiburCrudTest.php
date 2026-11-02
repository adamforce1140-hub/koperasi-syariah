<?php

namespace Tests\Feature;

use App\Models\HariLibur;
use App\Models\KebijakanKoperasi;
use App\Models\User;
use App\Services\HariLiburService;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * KALENDER HARI LIBUR — pengelolaan oleh Pengurus.
 *
 * Mengunci tiga hal:
 *   1. Otorisasi: hanya peran yang memegang izin `kebijakan.koperasi` yang
 *      boleh membuka & mengubah kalender (Pengurus). Teller ditolak.
 *   2. CRUD berfungsi: tambah, ubah, aktif/nonaktif, hapus — dan tanggal
 *      ganda ditolak validasi.
 *   3. Efek nyata pada penjadwalan: hari libur aktif menggeser jatuh tempo,
 *      hari libur nonaktif tidak, dan strategi MAJU/MUNDUR dihormati.
 */
class HariLiburCrudTest extends TestCase
{
    use DatabaseTransactions;

    /** Tanggal uji: Senin, sehingga bukan akhir pekan. */
    private const TANGGAL_UJI = '2027-03-15';

    private User $pengurus;

    private User $teller;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('hari_liburs')) {
            $this->markTestSkipped('Tabel hari_liburs belum tersedia.');
        }

        $this->pengurus = User::firstOrCreate(
            ['email' => 'hari.libur.pengurus@test.local'],
            [
                'name' => 'Uji Hari Libur Pengurus',
                'password' => Hash::make('password'),
                'role' => 'pengurus',
                'is_active' => true,
            ]
        );

        $this->teller = User::firstOrCreate(
            ['email' => 'hari.libur.teller@test.local'],
            [
                'name' => 'Uji Hari Libur Teller',
                'password' => Hash::make('password'),
                'role' => 'teller',
                'is_active' => true,
            ]
        );

        // Bersihkan tanggal uji agar tidak bertabrakan dengan data seed.
        HariLibur::query()
            ->whereIn('tanggal', [self::TANGGAL_UJI, '2027-03-12', '2027-03-16', '2027-03-17'])
            ->delete();

        Setting::flush();
    }

    /** Ubah kolom kebijakan lalu segarkan cache Setting (pola test lain). */
    private function aturKebijakan(array $kolom): void
    {
        $kebijakan = KebijakanKoperasi::first() ?? KebijakanKoperasi::create([]);
        $kebijakan->forceFill($kolom)->save();
        Setting::flush();
    }

    protected function tearDown(): void
    {
        Setting::flush();
        parent::tearDown();
    }

    /** Muatan form kebijakan lengkap (field wajib) untuk menembak rute update. */
    private function muatanKebijakan(array $override = []): array
    {
        $kebijakan = KebijakanKoperasi::first() ?? KebijakanKoperasi::create([]);

        return array_merge([
            'limit_manajer' => (string) $kebijakan->limit_manajer,
            'tazir_mode' => $kebijakan->tazir_mode ?: 'FLAT_HARIAN',
            'tazir_rate' => (string) ($kebijakan->tazir_rate ?? 0),
            'tazir_grace_period_days' => (string) ($kebijakan->tazir_grace_period_days ?? 0),
            'tazir_default_method' => $kebijakan->tazir_default_method ?: 'TUNAI',
            'mandatory_principal_savings' => (string) $kebijakan->mandatory_principal_savings,
            'mandatory_monthly_savings' => (string) $kebijakan->mandatory_monthly_savings,
            'minimum_pembukaan_rekening' => (string) $kebijakan->minimum_pembukaan_rekening,
            'maksimum_pembukaan_rekening' => (string) $kebijakan->maksimum_pembukaan_rekening,
            'shu_pct_cadangan_modal' => (string) Setting::get('shu_pct_cadangan_modal', 30),
            'shu_pct_jasa_anggota' => (string) Setting::get('shu_pct_jasa_anggota', 40),
            'shu_pct_jasa_modal' => (string) Setting::get('shu_pct_jasa_modal', 20),
            'shu_pct_dana_pendidikan' => (string) Setting::get('shu_pct_dana_pendidikan', 10),
        ], $override);
    }

    private function liburUji(string $tanggal = self::TANGGAL_UJI): HariLibur
    {
        return HariLibur::create([
            'tanggal' => $tanggal,
            'keterangan' => 'Libur Uji Otomatis',
            'jenis' => HariLibur::JENIS_NASIONAL,
            'is_active' => true,
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════
     |  1. OTORISASI
     ══════════════════════════════════════════════════════════════════ */

    public function test_pengurus_dapat_membuka_halaman_kalender(): void
    {
        $this->actingAs($this->pengurus)
            ->get(route('pengurus.hari-libur.index'))
            ->assertOk()
            ->assertSee('Kalender Hari Libur')
            ->assertSee('Pratinjau penjadwalan');
    }

    public function test_teller_ditolak_membuka_dan_mengubah_kalender(): void
    {
        $libur = $this->liburUji();

        $this->actingAs($this->teller)
            ->get(route('pengurus.hari-libur.index'))
            ->assertForbidden();

        $this->actingAs($this->teller)
            ->post(route('pengurus.hari-libur.store'), [
                'tanggal' => '2027-03-16',
                'keterangan' => 'Percobaan Teller',
            ])
            ->assertForbidden();

        $this->actingAs($this->teller)
            ->delete(route('pengurus.hari-libur.destroy', $libur->id))
            ->assertForbidden();

        $this->assertDatabaseHas('hari_liburs', ['id' => $libur->id]);
    }

    /* ══════════════════════════════════════════════════════════════════
     |  2. CRUD
     ══════════════════════════════════════════════════════════════════ */

    public function test_pengurus_dapat_menambah_hari_libur(): void
    {
        $this->actingAs($this->pengurus)
            ->post(route('pengurus.hari-libur.store'), [
                'tanggal' => self::TANGGAL_UJI,
                'keterangan' => 'Hari Raya Uji',
                'jenis' => HariLibur::JENIS_CUTI_BERSAMA,
                'is_active' => '1',
            ])
            ->assertRedirect(route('pengurus.hari-libur.index', ['tahun' => 2027]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('hari_liburs', [
            'tanggal' => self::TANGGAL_UJI,
            'keterangan' => 'Hari Raya Uji',
            'jenis' => HariLibur::JENIS_CUTI_BERSAMA,
            'is_active' => true,
        ]);
    }

    public function test_jenis_kosong_menjadi_nasional(): void
    {
        $this->actingAs($this->pengurus)
            ->post(route('pengurus.hari-libur.store'), [
                'tanggal' => self::TANGGAL_UJI,
                'keterangan' => 'Libur Tanpa Jenis',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('hari_liburs', [
            'tanggal' => self::TANGGAL_UJI,
            'jenis' => HariLibur::JENIS_NASIONAL,
        ]);
    }

    public function test_tanggal_ganda_ditolak(): void
    {
        $this->liburUji();

        $this->actingAs($this->pengurus)
            ->post(route('pengurus.hari-libur.store'), [
                'tanggal' => self::TANGGAL_UJI,
                'keterangan' => 'Duplikat',
            ])
            ->assertSessionHasErrors('tanggal');

        $this->assertSame(
            1,
            HariLibur::query()->whereDate('tanggal', self::TANGGAL_UJI)->count()
        );
    }

    public function test_update_mempertahankan_tanggal_ganda_pada_baris_yang_sama(): void
    {
        $libur = $this->liburUji();

        // Mengirim tanggal yang sama untuk baris yang sama tidak boleh
        // memicu aturan unique.
        $this->actingAs($this->pengurus)
            ->put(route('pengurus.hari-libur.update', $libur->id), [
                'tanggal' => self::TANGGAL_UJI,
                'keterangan' => 'Keterangan Baru',
                'jenis' => HariLibur::JENIS_KOPERASI,
                'is_active' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('hari_liburs', [
            'id' => $libur->id,
            'keterangan' => 'Keterangan Baru',
            'jenis' => HariLibur::JENIS_KOPERASI,
        ]);
    }

    public function test_toggle_menonaktifkan_dan_mengaktifkan_kembali(): void
    {
        $libur = $this->liburUji();

        $this->actingAs($this->pengurus)
            ->post(route('pengurus.hari-libur.toggle', $libur->id))
            ->assertSessionHasNoErrors();

        $this->assertFalse($libur->fresh()->is_active);

        $this->actingAs($this->pengurus)
            ->post(route('pengurus.hari-libur.toggle', $libur->id))
            ->assertSessionHasNoErrors();

        $this->assertTrue($libur->fresh()->is_active);
    }

    public function test_hapus_menghilangkan_baris(): void
    {
        $libur = $this->liburUji();

        $this->actingAs($this->pengurus)
            ->delete(route('pengurus.hari-libur.destroy', $libur->id))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('hari_liburs', ['id' => $libur->id]);
    }

    /* ══════════════════════════════════════════════════════════════════
     |  3. EFEK PADA PENJADWALAN
     ══════════════════════════════════════════════════════════════════ */

    public function test_hari_libur_aktif_menggeser_jatuh_tempo_sesuai_strategi(): void
    {
        $libur = $this->liburUji();
        $service = app(HariLiburService::class);
        $tanggal = Carbon::parse(self::TANGGAL_UJI);

        $this->assertFalse($service->isHariKerja($tanggal));

        $this->aturKebijakan(['hari_libur_aktif' => true, 'hari_libur_strategi' => 'MAJU']);
        $this->assertSame('2027-03-16', $service->keHariKerja($tanggal)->toDateString());

        $this->aturKebijakan(['hari_libur_strategi' => 'MUNDUR']);
        $this->assertSame('2027-03-12', $service->keHariKerja($tanggal)->toDateString());

        // Menonaktifkan baris mengembalikan tanggal menjadi hari kerja.
        $libur->update(['is_active' => false]);
        $this->assertTrue($service->isHariKerja($tanggal));

        // Menghapus baris juga mengembalikan tanggal menjadi hari kerja.
        $libur->update(['is_active' => true]);
        $this->assertFalse($service->isHariKerja($tanggal));

        $libur->delete();
        $this->assertTrue($service->isHariKerja($tanggal));
    }

    public function test_fitur_nonaktif_membuat_tanggal_libur_diperlakukan_sebagai_hari_kerja(): void
    {
        $this->liburUji();

        $this->aturKebijakan(['hari_libur_aktif' => false]);

        $tanggal = Carbon::parse(self::TANGGAL_UJI);
        $service = app(HariLiburService::class);

        // isHariKerja tetap melaporkan libur (data kalender), tetapi
        // penyesuaian tanggal tidak dilakukan saat fitur dimatikan.
        $this->assertFalse($service->isHariKerja($tanggal));
        $this->assertSame(
            self::TANGGAL_UJI,
            $service->keHariKerja($tanggal)->toDateString()
        );
    }

    /* ══════════════════════════════════════════════════════════════════
     |  4. PARAMETER PERIODE & KALENDER DI FORM KEBIJAKAN
     ══════════════════════════════════════════════════════════════════ */

    public function test_parameter_periode_kalender_tersimpan_dari_form_kebijakan(): void
    {
        $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), $this->muatanKebijakan([
                'tanggal_jatuh_tempo_default' => '10',
                'hari_libur_strategi' => 'MUNDUR',
                'hari_libur_aktif' => '0',
            ]))
            ->assertSessionHasNoErrors();

        Setting::flush();

        $kebijakan = KebijakanKoperasi::first();

        $this->assertSame(10, (int) $kebijakan->tanggal_jatuh_tempo_default);
        $this->assertSame('MUNDUR', $kebijakan->hari_libur_strategi);
        $this->assertFalse((bool) $kebijakan->hari_libur_aktif);
        $this->assertSame(10, Setting::tanggalJatuhTempoDefault());
        $this->assertSame('MUNDUR', Setting::hariLiburStrategi());
        $this->assertFalse(Setting::hariLiburAktif());
    }

    public function test_parameter_periode_kalender_tidak_dikirim_tidak_mengubah_nilai(): void
    {
        $this->aturKebijakan([
            'tanggal_jatuh_tempo_default' => 7,
            'hari_libur_strategi' => 'MUNDUR',
            'hari_libur_aktif' => false,
        ]);

        // Pembaruan sebagian: ketiga kunci tidak dikirim sama sekali (P4).
        $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), $this->muatanKebijakan())
            ->assertSessionHasNoErrors();

        Setting::flush();

        $kebijakan = KebijakanKoperasi::first();

        $this->assertSame(7, (int) $kebijakan->tanggal_jatuh_tempo_default);
        $this->assertSame('MUNDUR', $kebijakan->hari_libur_strategi);
        $this->assertFalse((bool) $kebijakan->hari_libur_aktif);
    }

    public function test_tanggal_jatuh_tempo_di_luar_1_sampai_28_ditolak(): void
    {
        foreach (['0', '29', '31'] as $tidakValid) {
            $this->actingAs($this->pengurus)
                ->put(route('pengurus.kebijakan.update'), $this->muatanKebijakan([
                    'tanggal_jatuh_tempo_default' => $tidakValid,
                ]))
                ->assertSessionHasErrors(['tanggal_jatuh_tempo_default']);
        }

        $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), $this->muatanKebijakan([
                'hari_libur_strategi' => 'LURUS',
            ]))
            ->assertSessionHasErrors(['hari_libur_strategi']);
    }
}
