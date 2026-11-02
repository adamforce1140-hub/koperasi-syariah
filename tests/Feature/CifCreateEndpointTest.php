<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\KebijakanKoperasi;
use App\Models\MandatorySavingsBill;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ENDPOINT PENDAFTARAN CIF — /ao/cif/create.
 *
 * Latar belakang (perbaikan endpoint):
 *   URL `POST /ao/cif/create` sebelumnya mengembalikan HTTP 405
 *   "The POST method is not supported for route ao.cif.create. Supported
 *   methods: GET, HEAD." karena hanya `GET /ao/cif/create` yang terdaftar —
 *   handler simpan berada di `POST /ao/cif` (action form).
 *
 * Perbaikan yang dikunci oleh test ini:
 *   1. `POST /ao/cif/create` kini terdaftar dan diarahkan ke handler simpan
 *      yang sama (tidak lagi 405), tanpa mengubah `GET` (form tetap tampil).
 *   2. Validasi input menghasilkan redirect-back dengan pesan galat yang
 *      informatif (bukan 500/trace), untuk input tidak valid & edge case.
 *   3. Input valid menyimpan data lengkap: anggota ACTIVE, 2 rekening
 *      bundling, 2 transaksi, jurnal PSAK 101/108, tagihan wajib PAID.
 *   4. Format respons mengikuti standar proyek: sukses = redirect 302 +
 *      flash `success`; galat = redirect 302 + flash `error`/`errors`.
 *
 * Basis data dev memuat data nyata, sehingga seluruh uji memakai
 * DatabaseTransactions + data berprefix unik agar deterministik.
 */
class CifCreateEndpointTest extends TestCase
{
    use DatabaseTransactions;

    private const URL_FORM = '/ao/cif/create';

    private const URL_STORE = '/ao/cif';

    private string $prefix;

    private User $ao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'CIFE'.strtoupper(substr(uniqid(), -6));

        $this->ao = User::firstOrCreate(
            ['email' => 'cif.endpoint.ao@test.local'],
            [
                'name' => 'AO CIF Endpoint Uji',
                'password' => Hash::make('password'),
                'role' => 'ao',
                'is_active' => true,
            ]
        );
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    /** Prasyarat jurnal: CoA minimal harus tersedia (lewati bila seeder absen). */
    private function pastikanCoa(): void
    {
        $wajib = ['1-1001', '3-1001', '3-1002'];

        $ada = Coa::whereIn('kode_akun', $wajib)->count();

        if ($ada < count($wajib)) {
            $this->markTestSkipped('CoA '.implode(', ', $wajib).' belum tersedia — jalankan seeder CoA.');
        }
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
                DB::table('anggota')->where('no_anggota', $calon)->update(['no_anggota' => $kandidat]);

                return;
            }
        }
    }

    /**
     * Payload pendaftaran valid.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        $nik = str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT);
        $hp = '08'.str_pad((string) random_int(1, 9999999999), 10, '0', STR_PAD_LEFT);

        return array_merge([
            'nama_lengkap' => 'Uji CIF '.$this->prefix,
            'nik' => $nik,
            'jenis_kelamin' => 'Laki-laki',
            'no_hp' => $hp,
            'alamat' => 'Jl. Uji Endpoint No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'tempat_lahir' => 'Bandung',
            'tanggal_lahir' => '1995-05-05',
            'status_perkawinan' => 'Belum Menikah',
            'email' => strtolower($this->prefix).'@test.local',
            'pekerjaan' => 'Karyawan Swasta',
            'nama_ibu_kandung' => 'Ibu Uji',
            'rt_rw' => '001/002',
            'kelurahan' => 'Sukajadi',
            'kecamatan' => 'Sukajadi',
            'kode_pos' => '40162',
            'keterangan' => 'Pendaftaran via feature test',
            'is_auto_debit' => '1',
            'jenis_setoran' => 'Simpanan Pokok',
            'setoran_pertama' => 150000,
            // OJK-08: deklarasi PEP wajib pada onboarding.
            'pep_status' => Anggota::PEP_BUKAN,
        ], $override);
    }

    /* =====================================================================
     | 1. ROUTE — keluhan utama: POST /ao/cif/create tidak boleh 405
     |===================================================================== */

    public function test_form_pendaftaran_tampil_via_get(): void
    {
        $this->actingAs($this->ao)
            ->get(self::URL_FORM)
            ->assertOk()
            ->assertSee('Pendaftaran', false);
    }

    public function test_post_ke_url_halaman_form_tidak_lagi_405(): void
    {
        $this->pastikanCoa();
        $this->hindariBentrokNoAnggota();

        $response = $this->actingAs($this->ao)->post(self::URL_FORM, $this->payload());

        $this->assertNotSame(
            405,
            $response->getStatusCode(),
            'POST /ao/cif/create masih mengembalikan 405 Method Not Allowed.'
        );

        // Alias harus menuju perilaku simpan yang identik.
        $response->assertRedirect();
    }

    public function test_post_via_url_halaman_form_menyimpan_anggota(): void
    {
        $this->pastikanCoa();
        $this->hindariBentrokNoAnggota();

        $payload = $this->payload();

        $this->actingAs($this->ao)
            ->post(self::URL_FORM, $payload)
            ->assertRedirect();

        $anggota = Anggota::denganNik($payload['nik'])->first();

        $this->assertNotNull($anggota, 'Anggota tidak tersimpan via alias POST /ao/cif/create.');
        $this->assertSame(Anggota::STATUS_ACTIVE, $anggota->status_anggota);
    }

    /* =====================================================================
     | 2. INPUT VALID — data lengkap & konsisten
     |===================================================================== */

    public function test_input_valid_menyimpan_seluruh_artefak_pendaftaran(): void
    {
        $this->pastikanCoa();
        $this->hindariBentrokNoAnggota();

        $payload = $this->payload();

        $response = $this->actingAs($this->ao)->post(self::URL_STORE, $payload);

        $anggota = Anggota::denganNik($payload['nik'])->firstOrFail();

        // Format respons standar: redirect 302 ke portofolio + flash success.
        $response->assertRedirect(route('ao.cif.show', $anggota->id));
        $response->assertSessionHas('success');
        $this->assertStringContainsString('berhasil didaftarkan', session('success'));

        // Identitas & nomor dokumen terbentuk otomatis.
        $this->assertSame(Anggota::STATUS_ACTIVE, $anggota->status_anggota);
        $this->assertNotEmpty($anggota->no_anggota);
        $this->assertNotEmpty($anggota->no_cif);
        $this->assertSame(1, (int) $anggota->is_auto_debit);
        $this->assertSame($payload['nama_lengkap'], $anggota->nama_lengkap);

        // Bundling 3 rekening: Pokok & Wajib bersaldo, Sukarela bersaldo Rp 0.
        $pokok = KebijakanKoperasi::simpananPokokWajib();
        $wajib = KebijakanKoperasi::simpananWajibBulanan();

        $rekening = Rekening::where('anggota_id', $anggota->id)->get();
        $this->assertCount(3, $rekening, 'Pendaftaran harus membuka 3 rekening (Pokok, Wajib, Sukarela).');

        $this->assertSame(
            round($pokok, 2),
            round((float) $rekening->firstWhere('jenis_rekening', 'Simpanan Pokok')->saldo, 2)
        );
        $this->assertSame(
            round($wajib, 2),
            round((float) $rekening->firstWhere('jenis_rekening', 'Simpanan Wajib')->saldo, 2)
        );

        // Rekening Simpanan Sukarela otomatis dibuka dengan saldo Rp 0.
        $sukarela = $rekening->firstWhere('jenis_rekening', 'Simpanan Sukarela');
        $this->assertNotNull($sukarela, 'Rekening Simpanan Sukarela tidak terbuka otomatis.');
        $this->assertSame(0.0, round((float) $sukarela->saldo, 2));

        // Dua mutasi setoran awal.
        $this->assertSame(2, Transaksi::where('anggota_id', $anggota->id)->count());

        // Jurnal bundling PSAK 101/108: total debet = total kredit = pokok + wajib.
        // Jurnal ditelusuri lewat tagihan Simpanan Wajib periode pertama
        // (tabel `jurnal` tidak menyimpan kolom anggota_id).
        $bill = MandatorySavingsBill::where('member_id', $anggota->id)->first();
        $this->assertNotNull($bill, 'Tagihan Simpanan Wajib periode berjalan tidak terbentuk.');
        $this->assertSame(MandatorySavingsBill::STATUS_PAID, $bill->status);

        $jurnal = Jurnal::find($bill->jurnal_id);
        $this->assertNotNull($jurnal, 'Jurnal bundling setoran awal tidak terbentuk.');

        $debet = (float) DB::table('jurnal_detail')->where('jurnal_id', $jurnal->id)->sum('debet');
        $kredit = (float) DB::table('jurnal_detail')->where('jurnal_id', $jurnal->id)->sum('kredit');

        $this->assertSame(round($pokok + $wajib, 2), round($debet, 2));
        $this->assertSame(round($pokok + $wajib, 2), round($kredit, 2));
    }

    public function test_pendaftaran_tetap_berhasil_saat_sudah_ada_nomor_rekening_bersufiks_panjang(): void
    {
        $this->pastikanCoa();
        $this->hindariBentrokNoAnggota();

        // Regresi bug `Duplicate entry` pada nomor rekening:
        // pola lama mengambil nomor TERBESAR secara STRING lalu menghitung urutan
        // dengan `substr($max, -6)`. Bila ada nomor bersufiks LEBIH PANJANG
        // (mis. `1000699` — sisa penggabungan yang pernah dibuat manual), sufiks
        // itu terpotong menjadi `000699` dan nomor berikutnya menjadi `000700`
        // yang SUDAH terpakai. Generator terpusat
        // `Rekening::nomorRekeningBaru()` menghitung sufiks secara NUMERIK
        // sehingga panjang berbeda tidak lagi merusak urutan.
        $prefix = 'RKN-'.date('Y').'-';
        $sudahAda6 = $prefix.'000700';
        $pemancing = $prefix.'1000699'; // > '000700' secara string, < secara numerik

        // `rekening.anggota_id` & `akun_id` NOT NULL → pancingan ditautkan ke
        // anggota sekali pakai yang bersih (tanpa rekening), sehingga tidak
        // mengganggu perhitungan bundling milik anggota uji.
        $penampung = Anggota::create([
            'no_anggota' => 'ANG-'.date('Y').'-'.substr($this->prefix, -4),
            'nama_lengkap' => 'Penampung Nomor Panjang '.$this->prefix,
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'jenis_kelamin' => 'Laki-laki',
            'no_hp' => '08'.str_pad((string) random_int(1, 9999999999), 10, '0', STR_PAD_LEFT),
            'alamat' => 'Jl. Uji Nomor Panjang No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_ACTIVE,
        ]);

        $akunId = Coa::where('kode_akun', '1-1001')->firstOrFail()->id;

        foreach ([$sudahAda6, $pemancing] as $noRekening) {
            Rekening::create([
                'no_rekening' => $noRekening,
                'anggota_id' => $penampung->id,
                'akun_id' => $akunId,
                'jenis_rekening' => 'Simpanan Sukarela',
                'saldo' => 0,
                'tanggal_buka' => date('Y-m-d'),
                'status_rekening' => 'Aktif',
            ]);
        }

        // Nomor yang dihasilkan wajib berbeda dari nomor pancingan & belum terpakai.
        $nomorBaru = Rekening::nomorRekeningBaru();
        $this->assertNotSame($sudahAda6, $nomorBaru);
        $this->assertNotSame($pemancing, $nomorBaru);
        $this->assertFalse(
            Rekening::withTrashed()->where('no_rekening', $nomorBaru)->exists(),
            'Nomor rekening hasil generator tidak boleh menabrak nomor yang sudah ada.'
        );

        $payload = $this->payload();

        // Tanpa generator terpusat, langkah ini melempar UniqueConstraintViolation.
        $response = $this->actingAs($this->ao)->post(self::URL_STORE, $payload);

        $anggota = Anggota::denganNik($payload['nik'])->firstOrFail();

        $response->assertRedirect(route('ao.cif.show', $anggota->id));
        $response->assertSessionHasNoErrors();

        $rekening = Rekening::where('anggota_id', $anggota->id)->get();
        $this->assertCount(3, $rekening, 'Pendaftaran harus tetap membuka 3 rekening.');
        $this->assertCount(
            3,
            $rekening->pluck('no_rekening')->unique(),
            'Ketiga nomor rekening harus berbeda satu sama lain.'
        );

        // Nomor pancingan tidak boleh tertimpa/terpakai ulang.
        $this->assertTrue(
            Rekening::withTrashed()->where('no_rekening', $pemancing)->exists(),
            'Nomor rekening lama yang bersufiks panjang harus tetap utuh.'
        );
    }

    /* =====================================================================
     | 3. INPUT TIDAK VALID — pesan informatif, tanpa 500
     |===================================================================== */

    public function test_input_kosong_mengembalikan_galat_validasi_informatif(): void
    {
        $response = $this->actingAs($this->ao)->from(self::URL_FORM)->post(self::URL_STORE, []);

        $response->assertRedirect(self::URL_FORM);
        $response->assertSessionHasErrors([
            'nama_lengkap', 'nik', 'jenis_kelamin', 'no_hp', 'alamat', 'kota', 'provinsi',
        ]);

        $errors = session('errors')->getBag('default');
        $this->assertStringContainsString('nama lengkap', strtolower($errors->first('nama_lengkap')));
    }

    public function test_nik_kurang_dari_16_digit_ditolak(): void
    {
        $this->hindariBentrokNoAnggota();

        $response = $this->actingAs($this->ao)
            ->from(self::URL_FORM)
            ->post(self::URL_STORE, $this->payload(['nik' => '32730101']));

        $response->assertRedirect(self::URL_FORM);
        $response->assertSessionHasErrors('nik');
        $this->assertSame(0, Anggota::denganNik('32730101')->count());
    }

    public function test_nik_duplikat_ditolak(): void
    {
        $this->pastikanCoa();
        $this->hindariBentrokNoAnggota();

        $payload = $this->payload();

        $this->actingAs($this->ao)->post(self::URL_STORE, $payload)->assertRedirect();
        $this->assertSame(1, Anggota::denganNik($payload['nik'])->count());

        // Pendaftaran kedua dengan NIK yang sama harus ditolak, bukan 500.
        $payload2 = $this->payload(['nik' => $payload['nik']]);
        $response = $this->actingAs($this->ao)->from(self::URL_FORM)->post(self::URL_STORE, $payload2);

        $response->assertRedirect(self::URL_FORM);
        $response->assertSessionHasErrors('nik');
        $response->assertStatus(302);
        $this->assertSame(1, Anggota::denganNik($payload['nik'])->count());
    }

    public function test_no_hp_tidak_valid_ditolak(): void
    {
        $this->hindariBentrokNoAnggota();

        $response = $this->actingAs($this->ao)
            ->from(self::URL_FORM)
            ->post(self::URL_STORE, $this->payload(['no_hp' => '123']));

        $response->assertRedirect(self::URL_FORM);
        $response->assertSessionHasErrors('no_hp');
    }

    public function test_email_tidak_valid_ditolak(): void
    {
        $this->hindariBentrokNoAnggota();

        $response = $this->actingAs($this->ao)
            ->from(self::URL_FORM)
            ->post(self::URL_STORE, $this->payload(['email' => 'bukan-email']));

        $response->assertRedirect(self::URL_FORM);
        $response->assertSessionHasErrors('email');
    }

    /* =====================================================================
     | 4. EDGE CASE — batas aturan bisnis
     |===================================================================== */

    public function test_setoran_di_bawah_minimum_bundling_ditolak_dengan_pesan_jelas(): void
    {
        $this->pastikanCoa();
        $this->hindariBentrokNoAnggota();

        $totalMinimum = round(
            KebijakanKoperasi::simpananPokokWajib() + KebijakanKoperasi::simpananWajibBulanan(),
            2
        );

        $payload = $this->payload(['setoran_pertama' => max(1, $totalMinimum - 1000)]);
        $response = $this->actingAs($this->ao)->from(self::URL_FORM)->post(self::URL_STORE, $payload);

        $response->assertRedirect(self::URL_FORM);
        $response->assertSessionHas('error');
        $this->assertStringContainsString('Setoran awal minimal', session('error'));
        $this->assertNull(Anggota::denganNik($payload['nik'])->first());
    }

    public function test_setoran_pertama_kosong_memakai_bundling_dinamis(): void
    {
        $this->pastikanCoa();
        $this->hindariBentrokNoAnggota();

        $payload = $this->payload(['setoran_pertama' => '']);
        unset($payload['setoran_pertama']);

        $this->actingAs($this->ao)->post(self::URL_STORE, $payload);

        $anggota = Anggota::denganNik($payload['nik'])->firstOrFail();

        $total = round(
            KebijakanKoperasi::simpananPokokWajib() + KebijakanKoperasi::simpananWajibBulanan(),
            2
        );

        $this->assertSame($total, round((float) Rekening::where('anggota_id', $anggota->id)->sum('saldo'), 2));
    }

    public function test_setoran_tidak_numerik_ditolak_informatif(): void
    {
        $this->hindariBentrokNoAnggota();

        $response = $this->actingAs($this->ao)
            ->from(self::URL_FORM)
            ->post(self::URL_STORE, $this->payload(['setoran_pertama' => 'seratus ribu']));

        $response->assertRedirect(self::URL_FORM);
        $response->assertSessionHasErrors('setoran_pertama');
    }

    public function test_jenis_kelamin_di_luar_daftar_ditolak(): void
    {
        $this->hindariBentrokNoAnggota();

        $response = $this->actingAs($this->ao)
            ->from(self::URL_FORM)
            ->post(self::URL_STORE, $this->payload(['jenis_kelamin' => 'Lainnya']));

        $response->assertRedirect(self::URL_FORM);
        $response->assertSessionHasErrors('jenis_kelamin');
    }

    /** Status dari form tidak boleh dipakai: anggota baru selalu dievaluasi sistem. */
    public function test_status_yang_dikirim_form_tidak_dapat_melompati_aktivasi(): void
    {
        $this->pastikanCoa();
        $this->hindariBentrokNoAnggota();

        $payload = $this->payload(['status_anggota' => 'SUSPENDED']);

        $this->actingAs($this->ao)->post(self::URL_STORE, $payload);

        $anggota = Anggota::denganNik($payload['nik'])->firstOrFail();

        $this->assertSame(
            Anggota::STATUS_ACTIVE,
            $anggota->status_anggota,
            'Status kiriman form harus diabaikan; aktivasi hanya lewat setoran awal.'
        );
    }

    /** Data opsional boleh kosong; tidak boleh memicu galat basis data. */
    public function test_field_opsional_kosong_tetap_tersimpan(): void
    {
        $this->pastikanCoa();
        $this->hindariBentrokNoAnggota();

        $payload = $this->payload([
            'tempat_lahir' => null,
            'tanggal_lahir' => null,
            'status_perkawinan' => null,
            'email' => null,
            'pekerjaan' => null,
            'nama_ibu_kandung' => null,
            'rt_rw' => null,
            'kelurahan' => null,
            'kecamatan' => null,
            'kode_pos' => null,
            'keterangan' => null,
        ]);

        $this->actingAs($this->ao)->post(self::URL_STORE, $payload);

        $this->assertNotNull(Anggota::denganNik($payload['nik'])->first());
    }

    /* =====================================================================
     | 5. KEAMANAN & FORMAT RESPONS
     |===================================================================== */

    public function test_tamu_tidak_dapat_mendaftarkan_anggota(): void
    {
        $payload = $this->payload();

        $this->post(self::URL_STORE, $payload)->assertRedirect('/login');
        $this->assertNull(Anggota::denganNik($payload['nik'])->first());
    }

    public function test_peran_selain_ao_tidak_dapat_mendaftarkan_anggota(): void
    {
        $teller = User::firstOrCreate(
            ['email' => 'cif.endpoint.teller@test.local'],
            [
                'name' => 'Teller CIF Endpoint Uji',
                'password' => Hash::make('password'),
                'role' => 'teller',
                'is_active' => true,
            ]
        );

        $payload = $this->payload();

        $status = $this->actingAs($teller)->post(self::URL_STORE, $payload)->getStatusCode();

        $this->assertContains($status, [403, 302], 'Peran non-AO tidak boleh menembus endpoint simpan.');
        $this->assertNull(Anggota::denganNik($payload['nik'])->first());
    }
}
