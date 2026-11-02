<?php

namespace Tests\Feature;

use App\Models\Coa;
use App\Models\Pembiayaan;
use App\Models\PemetaanAkadCoa;
use App\Models\User;
use App\Services\CoaResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FLEKSIBILITAS AKAD & PSAK — sistem harus "tidak kaku".
 *
 * Sebelumnya pemetaan akad → akun CoA ditanam sebagai konstanta PHP di
 * App\Support\Coa, sehingga menambah akad syariah baru selalu menuntut
 * perubahan kode + deploy. Berkas ini mengunci sifat dinamis yang baru:
 *
 *   1. Akad baru dapat ditambahkan lewat UI PENGURUS (tanpa mengubah kode).
 *   2. Akad baru itu langsung dikenali validasi & resolver CoA.
 *   3. Pemetaan akun berbasis data mengalahkan registri standar.
 *   4. Bila pemetaan kosong / menunjuk akun nonaktif → jatuh ke standar
 *      (tanpa galat), sesuai prinsip zero-hardcoding.
 */
class FleksibilitasAkadPsakTest extends TestCase
{
    use DatabaseTransactions;

    private User $pengurus;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('pemetaan_akad_coa')) {
            $this->markTestSkipped('Tabel pemetaan_akad_coa belum tersedia.');
        }

        PemetaanAkadCoa::flush();

        $this->pengurus = User::firstOrCreate(
            ['email' => 'fleksibilitas.akad@test.local'],
            [
                'name' => 'Uji Fleksibilitas Akad',
                'password' => Hash::make('password'),
                'role' => 'pengurus',
                'is_active' => true,
            ]
        );
    }

    /** Akun CoA aktif pertama untuk dipakai sebagai target pemetaan uji. */
    private function akunCoa(int $offset = 0): ?Coa
    {
        return Coa::query()
            ->where('is_active', true)
            ->orderBy('kode_akun')
            ->skip($offset)
            ->first();
    }

    /* ── 1. Daftar akad dinamis ───────────────────────────────────────── */

    public function test_akad_kanonik_selalu_dikenali_sistem(): void
    {
        $kanonik = Pembiayaan::daftarAkadKanonik();
        $dinamis = Pembiayaan::daftarAkad();

        $this->assertNotEmpty($kanonik, 'Registri akad kanonik tidak boleh kosong.');

        foreach ($kanonik as $akad) {
            $this->assertContains(
                $akad,
                $dinamis,
                "Akad kanonik [{$akad}] harus tetap dikenali walau tabel pemetaan kosong."
            );
        }
    }

    public function test_akad_baru_dapat_ditambahkan_tanpa_mengubah_kode(): void
    {
        $akadBaru = 'Musyarakah Mutanaqisah';

        $this->assertNotContains(
            $akadBaru,
            Pembiayaan::daftarAkad(),
            'Prasyarat uji: akad ini belum terdaftar.'
        );

        // Pengurus menambahkan akad baru lewat pemetaan (basis data).
        PemetaanAkadCoa::create([
            'kode_produk' => PemetaanAkadCoa::SEMUA_PRODUK,
            'akad_pembiayaan' => $akadBaru,
            'status_aktif' => true,
        ]);

        PemetaanAkadCoa::flush();

        $this->assertContains(
            $akadBaru,
            Pembiayaan::daftarAkad(),
            'Akad baru harus langsung dikenali sistem tanpa deploy.'
        );
    }

    public function test_akad_nonaktif_tidak_dianggap_akad_sah(): void
    {
        $akad = 'Akad Nonaktif Uji';

        PemetaanAkadCoa::create([
            'kode_produk' => PemetaanAkadCoa::SEMUA_PRODUK,
            'akad_pembiayaan' => $akad,
            'status_aktif' => false,
        ]);

        PemetaanAkadCoa::flush();

        $this->assertNotContains($akad, Pembiayaan::daftarAkad());
    }

    /* ── 2. Resolver CoA menghormati pemetaan berbasis data ───────────── */

    public function test_pemetaan_kosong_memakai_registri_standar(): void
    {
        // Backfill migrasi mengisi akad kanonik dengan akun NULL → perilaku
        // default tidak boleh berubah: Murabahah tetap PIUTANG_MURABAHAH.
        $this->assertNull(
            PemetaanAkadCoa::untukAkad('Murabahah')->account_piutang_id,
            'Backfill standar harus kosong agar tidak mengubah perilaku jurnal.'
        );

        $this->assertSame(
            \App\Support\Coa::PIUTANG_MURABAHAH,
            (new CoaResolver)->kodePiutangByAkad('Murabahah')
        );
    }

    public function test_pemetaan_berbasis_data_mengalahkan_registri_standar(): void
    {
        $akun = $this->akunCoa();

        if ($akun === null) {
            $this->markTestSkipped('Tidak ada akun CoA aktif untuk diuji.');
        }

        $akad = 'Murabahah';

        PemetaanAkadCoa::updateOrCreate(
            ['kode_produk' => PemetaanAkadCoa::SEMUA_PRODUK, 'akad_pembiayaan' => $akad],
            [
                'account_piutang_id' => $akun->id,
                'account_pendapatan_id' => $akun->id,
                'status_aktif' => true,
            ]
        );

        PemetaanAkadCoa::flush();

        $resolver = new CoaResolver;

        $this->assertSame(
            $akun->kode_akun,
            $resolver->kodePiutangByAkad($akad),
            'Akun piutang harus mengikuti pemetaan pengurus, bukan konstanta bawaan.'
        );

        $this->assertSame(
            $akun->kode_akun,
            $resolver->kodePendapatanByAkad($akad),
            'Akun pendapatan harus mengikuti pemetaan pengurus.'
        );
    }

    public function test_akad_baru_dapat_dipetakan_ke_akun_coa(): void
    {
        $akun = $this->akunCoa();

        if ($akun === null) {
            $this->markTestSkipped('Tidak ada akun CoA aktif untuk diuji.');
        }

        $akadBaru = 'Akad Kemitraan Uji';

        PemetaanAkadCoa::updateOrCreate(
            ['kode_produk' => PemetaanAkadCoa::SEMUA_PRODUK, 'akad_pembiayaan' => $akadBaru],
            ['account_piutang_id' => $akun->id, 'status_aktif' => true]
        );

        PemetaanAkadCoa::flush();

        $this->assertSame(
            $akun->kode_akun,
            (new CoaResolver)->kodePiutangByAkad($akadBaru),
            'Akad yang bahkan tidak ada di konstanta PHP harus dapat dipetakan ke akun CoA.'
        );
    }

    public function test_akun_nonaktif_diabaikan_dan_jatuh_ke_standar(): void
    {
        $akun = Coa::query()->where('is_active', false)->first()
            ?? Coa::query()->first();

        if ($akun === null) {
            $this->markTestSkipped('Tidak ada akun CoA untuk diuji.');
        }

        $akun->forceFill(['is_active' => false])->save();

        PemetaanAkadCoa::updateOrCreate(
            ['kode_produk' => PemetaanAkadCoa::SEMUA_PRODUK, 'akad_pembiayaan' => 'Murabahah'],
            ['account_piutang_id' => $akun->id, 'status_aktif' => true]
        );

        PemetaanAkadCoa::flush();

        $this->assertNotSame(
            $akun->kode_akun,
            (new CoaResolver)->kodePiutangByAkad('Murabahah'),
            'Akun nonaktif tidak boleh dipakai; resolver harus beralih ke pemetaan standar.'
        );

        $this->assertSame(
            \App\Support\Coa::PIUTANG_MURABAHAH,
            (new CoaResolver)->kodePiutangByAkad('Murabahah')
        );
    }

    public function test_perubahan_pemetaan_membersihkan_cache(): void
    {
        $akadBaru = 'Akad Cache Uji';

        PemetaanAkadCoa::create([
            'kode_produk' => PemetaanAkadCoa::SEMUA_PRODUK,
            'akad_pembiayaan' => $akadBaru,
            'status_aktif' => true,
        ]);

        // Tanpa memanggil flush() manual: event model harus membersihkan cache.
        $this->assertContains(
            $akadBaru,
            Pembiayaan::daftarAkad(),
            'Perubahan pemetaan harus langsung terlihat (cache dibersihkan otomatis).'
        );
    }

    /* ── 3. Alur HTTP pengurus: tambah akad baru lewat UI ─────────────── */

    public function test_pengurus_dapat_menyimpan_pemetaan_akad_baru_lewat_ui(): void
    {
        $akun = $this->akunCoa();

        if ($akun === null) {
            $this->markTestSkipped('Tidak ada akun CoA aktif untuk diuji.');
        }

        $akadBaru = 'Ijarah Muntahia Bittamlik Uji';

        $respons = $this->actingAs($this->pengurus)->post(
            route('pengurus.kebijakan.pemetaan-akad'),
            [
                'pemetaan_akad' => [
                    [
                        'akad_pembiayaan' => '__BARU__',
                        'akad_baru' => $akadBaru,
                        'account_piutang_id' => $akun->id,
                        'account_pendapatan_id' => null,
                        'account_margin_ditangguhkan_id' => null,
                    ],
                ],
            ]
        );

        $respons->assertRedirect(route('pengurus.kebijakan.index'));
        $respons->assertSessionHas('success_pemetaan');

        PemetaanAkadCoa::flush();

        $this->assertContains(
            $akadBaru,
            Pembiayaan::daftarAkad(),
            'Akad baru hasil input pengurus harus langsung tersedia di seluruh sistem.'
        );

        $this->assertSame(
            $akun->kode_akun,
            (new CoaResolver)->kodePiutangByAkad($akadBaru)
        );
    }

    public function test_halaman_kebijakan_menampilkan_panel_pemetaan_akad(): void
    {
        $respons = $this->actingAs($this->pengurus)->get(route('pengurus.kebijakan.index'));

        $respons->assertOk();
        $respons->assertSee('Pemetaan Akad Syariah', false);
        $respons->assertSee('— Akad Baru —', false);
    }

    public function test_pemetaan_akun_tidak_valid_ditolak(): void
    {
        $respons = $this->actingAs($this->pengurus)
            ->from(route('pengurus.kebijakan.index'))
            ->post(
                route('pengurus.kebijakan.pemetaan-akad'),
                [
                    'pemetaan_akad' => [
                        [
                            'akad_pembiayaan' => 'Murabahah',
                            'account_piutang_id' => 999999999,
                        ],
                    ],
                ]
            );

        $respons->assertSessionHasErrors('pemetaan_akad.0.account_piutang_id');
    }

    public function test_pengguna_tanpa_peran_pengurus_tidak_dapat_menyimpan(): void
    {
        $teller = User::firstOrCreate(
            ['email' => 'fleksibilitas.teller@test.local'],
            [
                'name' => 'Uji Teller',
                'password' => Hash::make('password'),
                'role' => 'teller',
                'is_active' => true,
            ]
        );

        $respons = $this->actingAs($teller)->post(
            route('pengurus.kebijakan.pemetaan-akad'),
            [
                'pemetaan_akad' => [
                    ['akad_pembiayaan' => 'Murabahah'],
                ],
            ]
        );

        $respons->assertForbidden();
    }
}
