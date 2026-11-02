<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Coa;
use App\Models\PengajuanRekening;
use App\Models\User;
use App\Services\CddService;
use App\Support\PiiHasher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * OJK-08 — Customer Due Diligence (CDD), Politically Exposed Person (PEP),
 * dan Pemilik Manfaat pada onboarding anggota.
 *
 * Dasar hukum:
 * - POJK No. 27/POJK.03/2022 (APU-PPT) — penentuan profil risiko & jenis CDD.
 * - UU No. 8/2010 Pasal 18 — kewajiban mengenali pemilik manfaat.
 * - Perpres No. 13/2018 — prinsip mengenali pemilik manfaat.
 *
 * Test ini mengunci:
 *   1. SSOT CddService: pemetaan PEP/pemilik manfaat → profil risiko → jenis CDD.
 *   2. CIF (AO): data CDD/PEP/PM tersimpan + profil risiko dihitung otomatis.
 *   3. `pep_status` wajib diisi (validasi onboarding).
 *   4. Pengajuan rekening publik menyimpan deklarasi PEP/PM.
 */
class CddPepOnboardingTest extends TestCase
{
    use DatabaseTransactions;

    private const URL_STORE = '/ao/cif';

    private string $prefix;

    private User $ao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'CDD'.strtoupper(substr(uniqid(), -6));

        $this->ao = User::firstOrCreate(
            ['email' => 'cdd.pep.ao@test.local'],
            [
                'name' => 'AO CDD/PEP Uji',
                'password' => Hash::make('password'),
                'role' => 'ao',
                'is_active' => true,
            ]
        );
    }

    /* =====================================================================
     | 1. SSOT — CddService (logika domain)
     |===================================================================== */

    public function test_pep_menghasilkan_profil_risiko_tinggi_dan_cdd_mendalam(): void
    {
        $layanan = app(CddService::class);

        $profil = $layanan->hitungProfilRisiko(Anggota::PEP_YA);

        $this->assertSame(Anggota::RISIKO_TINGGI, $profil);
        $this->assertSame(Anggota::CDD_MENDALAM, $layanan->jenisCdd($profil));
    }

    public function test_pemilik_manfaat_menghasilkan_profil_risiko_sedang(): void
    {
        $layanan = app(CddService::class);

        $profil = $layanan->hitungProfilRisiko(Anggota::PEP_BUKAN, true);

        $this->assertSame(Anggota::RISIKO_SEDANG, $profil);
        $this->assertSame(Anggota::CDD_SEDERHANA, $layanan->jenisCdd($profil));
    }

    public function test_bukan_pep_tanpa_pemilik_manfaat_menghasilkan_profil_risiko_rendah(): void
    {
        $layanan = app(CddService::class);

        $profil = $layanan->hitungProfilRisiko(Anggota::PEP_BUKAN, false);

        $this->assertSame(Anggota::RISIKO_RENDAH, $profil);
        $this->assertSame(Anggota::CDD_SEDERHANA, $layanan->jenisCdd($profil));
    }

    public function test_terapkan_menetapkan_jejak_verifikasi_dan_jenis_cdd(): void
    {
        $layanan = app(CddService::class);

        $atribut = $layanan->terapkan([
            'pep_status' => Anggota::PEP_YA,
            'pep_keterangan' => 'Kepala Daerah',
            'pemilik_manfaat_nama' => 'PT Contoh',
            'pemilik_manfaat_hubungan' => 'Pemilik',
        ], 99);

        $this->assertSame(Anggota::PEP_YA, $atribut['pep_status']);
        $this->assertSame(Anggota::RISIKO_TINGGI, $atribut['profil_risiko']);
        $this->assertSame(Anggota::CDD_MENDALAM, $atribut['cdd_jenis']);
        $this->assertSame(99, $atribut['cdd_verified_by']);
        $this->assertNotNull($atribut['cdd_verified_at']);
    }

    /* =====================================================================
     | 2. CIF (AO) — persistensi & perhitungan otomatis
     |===================================================================== */

    public function test_cif_menyimpan_pep_dan_menghitung_profil_risiko_tinggi(): void
    {
        $this->pastikanPrasyarat();

        $payload = $this->payload([
            'pep_status' => Anggota::PEP_YA,
            'pep_keterangan' => 'Anggota DPRD',
        ]);

        $this->actingAs($this->ao)->post(self::URL_STORE, $payload)->assertRedirect();

        $anggota = Anggota::denganNik($payload['nik'])->firstOrFail();

        $this->assertSame(Anggota::PEP_YA, $anggota->pep_status);
        $this->assertSame('Anggota DPRD', $anggota->pep_keterangan);
        $this->assertSame(Anggota::RISIKO_TINGGI, $anggota->profil_risiko);
        $this->assertSame(Anggota::CDD_MENDALAM, $anggota->cdd_jenis);
        $this->assertTrue($anggota->isPep());
        $this->assertTrue($anggota->sudahCdd());
        $this->assertTrue($anggota->cddLengkap());
        $this->assertNotNull($anggota->cdd_verified_at);
    }

    public function test_cif_menyimpan_pemilik_manfaat_dan_menghitung_profil_risiko_sedang(): void
    {
        $this->pastikanPrasyarat();

        $payload = $this->payload([
            'pep_status' => Anggota::PEP_BUKAN,
            'pemilik_manfaat_nama' => 'Budi Pemilik Manfaat',
            'pemilik_manfaat_hubungan' => 'Pemilik usaha',
        ]);

        $this->actingAs($this->ao)->post(self::URL_STORE, $payload)->assertRedirect();

        $anggota = Anggota::denganNik($payload['nik'])->firstOrFail();

        $this->assertSame(Anggota::RISIKO_SEDANG, $anggota->profil_risiko);
        $this->assertSame(Anggota::CDD_SEDERHANA, $anggota->cdd_jenis);
        $this->assertSame('Budi Pemilik Manfaat', $anggota->pemilik_manfaat_nama);
        $this->assertFalse($anggota->isPep());
        $this->assertTrue($anggota->cddLengkap());
    }

    public function test_cif_tanpa_pep_dan_tanpa_pemilik_manfaat_profil_risiko_rendah(): void
    {
        $this->pastikanPrasyarat();

        $payload = $this->payload(['pep_status' => Anggota::PEP_BUKAN]);

        $this->actingAs($this->ao)->post(self::URL_STORE, $payload)->assertRedirect();

        $anggota = Anggota::denganNik($payload['nik'])->firstOrFail();

        $this->assertSame(Anggota::RISIKO_RENDAH, $anggota->profil_risiko);
        $this->assertSame(Anggota::CDD_SEDERHANA, $anggota->cdd_jenis);
        $this->assertNull($anggota->pemilik_manfaat_nama);
    }

    /* =====================================================================
     | 3. VALIDASI — deklarasi PEP wajib
     |===================================================================== */

    public function test_cif_tanpa_status_pep_ditolak_validasi(): void
    {
        $this->hindariBentrokNoAnggota();

        $payload = $this->payload();
        unset($payload['pep_status']);

        $response = $this->actingAs($this->ao)
            ->from('/ao/cif/create')
            ->post(self::URL_STORE, $payload);

        $response->assertRedirect('/ao/cif/create');
        $response->assertSessionHasErrors('pep_status');
        $this->assertNull(Anggota::denganNik($payload['nik'])->first());
    }

    public function test_cif_status_pep_di_luar_daftar_ditolak(): void
    {
        $this->hindariBentrokNoAnggota();

        $response = $this->actingAs($this->ao)
            ->from('/ao/cif/create')
            ->post(self::URL_STORE, $this->payload(['pep_status' => 'Mungkin']));

        $response->assertRedirect('/ao/cif/create');
        $response->assertSessionHasErrors('pep_status');
    }

    /* =====================================================================
     | 4. PENGAJUAN PUBLIK — deklarasi tersimpan di pengajuan_rekening
     |===================================================================== */

    public function test_pengajuan_publik_menyimpan_deklarasi_pep_dan_pemilik_manfaat(): void
    {
        $nik = str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT);

        $response = $this->post(route('pengajuan-rekening.publik.store'), [
            'nama_lengkap' => 'Pengaju CDD '.$this->prefix,
            'nik' => $nik,
            'jenis_kelamin' => 'Perempuan',
            'alamat' => 'Jl. Uji CDD No. 1',
            'no_hp' => '08'.str_pad((string) random_int(1, 9999999999), 10, '0', STR_PAD_LEFT),
            'jenis_simpanan' => 'Simpanan Pokok',
            'pep_status' => Anggota::PEP_BUKAN,
            'pemilik_manfaat_nama' => 'Siti Pemilik',
            'pemilik_manfaat_hubungan' => 'Istri',
            // Berkas gambar kecil yang valid untuk lolos aturan `image`.
            'foto_ktp' => UploadedFile::fake()->image('ktp.jpg'),
            'foto_selfie_ktp' => UploadedFile::fake()->image('selfie.jpg'),
        ]);

        $response->assertRedirect(route('pengajuan-rekening.publik.form'));

        $pengajuan = PengajuanRekening::where('nik_hash', PiiHasher::hash($nik))->first();

        $this->assertNotNull($pengajuan, 'Pengajuan publik tidak tersimpan.');
        $this->assertSame(Anggota::PEP_BUKAN, $pengajuan->pep_status);
        $this->assertSame('Siti Pemilik', $pengajuan->pemilik_manfaat_nama);
        $this->assertSame('Istri', $pengajuan->pemilik_manfaat_hubungan);
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    /** Pastikan CoA bundling tersedia (lewati bila seeder absen). */
    private function pastikanPrasyarat(): void
    {
        $wajib = ['1-1001', '3-1001', '3-1002'];

        if (Coa::whereIn('kode_akun', $wajib)->count() < count($wajib)) {
            $this->markTestSkipped('CoA '.implode(', ', $wajib).' belum tersedia — jalankan seeder CoA.');
        }

        $this->hindariBentrokNoAnggota();
    }

    /** Geser baris penghalang bila nomor anggota auto-generate akan bertabrakan. */
    private function hindariBentrokNoAnggota(): void
    {
        $tahun = date('Y');
        $total = Anggota::withTrashed()->count();
        $calon = 'ANG-'.$tahun.'-'.str_pad((string) ($total + 1), 4, '0', STR_PAD_LEFT);

        if (! Anggota::withTrashed()->where('no_anggota', $calon)->exists()) {
            return;
        }

        for ($i = $total + 2; $i <= $total + 200; $i++) {
            $kandidat = 'ANG-'.$tahun.'-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            if (! Anggota::withTrashed()->where('no_anggota', $kandidat)->exists()) {
                DB::table('anggota')
                    ->where('no_anggota', $calon)
                    ->update(['no_anggota' => $kandidat]);

                return;
            }
        }
    }

    /**
     * Payload CIF valid (dengan deklarasi PEP default).
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        $nik = str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT);
        $hp = '08'.str_pad((string) random_int(1, 9999999999), 10, '0', STR_PAD_LEFT);

        return array_merge([
            'nama_lengkap' => 'Uji CDD '.$this->prefix,
            'nik' => $nik,
            'jenis_kelamin' => 'Laki-laki',
            'no_hp' => $hp,
            'alamat' => 'Jl. Uji CDD No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'pekerjaan' => 'Karyawan',
            'jenis_setoran' => 'Simpanan Pokok',
            'setoran_pertama' => 150000,
            'pep_status' => Anggota::PEP_BUKAN,
        ], $override);
    }
}
