<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\DepositoProduct;
use App\Models\JenisSimpanan;
use App\Models\Rekening;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * VALIDASI & NORMALISASI `status_anggota`.
 *
 * Latar belakang (bug yang diperbaiki):
 * Form edit anggota menawarkan pilihan dari `Anggota::STATUS_LIST` (nilai
 * kanonik: PENDING/ACTIVE/INACTIVE/SUSPENDED/Keluar), tetapi aturan validasi
 * backend masih memakai literal alias legacy:
 *   - TellerAnggotaController : 'required|in:Aktif,Nonaktif'
 *   - CifController           : 'required|in:Aktif,Tidak Aktif,Keluar,Dibekukan'
 * Akibatnya memilih "Aktif" dari dropdown mengirim 'ACTIVE' dan ditolak
 * dengan pesan yang tidak informatif: "The selected status anggota is invalid."
 *
 * Kontrak yang dikunci test ini:
 *   1. Semua nilai kanonik (STATUS_LIST) tetap dikenali model & filter.
 *   2. Alias legacy tetap diterima (kompatibilitas tautan/kiriman lama) dan
 *      disimpan dalam bentuk kanonik — satu makna, satu nilai di kolom enum.
 *   3. Nilai benar-benar tidak dikenal tetap ditolak (HTTP 422) dengan pesan
 *      yang menyebutkan pilihan valid, bukan pesan generik Laravel.
 *   4. Perubahan status ke SUSPENDED/INACTIVE/Keluar HANYA kewenangan
 *      Super Admin & Pengurus. AO/Manajer/Teller yang mengirim status akan
 *      diabaikan (nilai lama dipertahankan), dan form mereka tidak lagi
 *      menawarkan dropdown status manual.
 *   5. Filter `?status=` menerima kanonik maupun alias legacy, dan menolak
 *      dengan aman (tanpa error) nilai yang tidak dikenal.
 */
class StatusAnggotaValidasiTest extends TestCase
{
    use DatabaseTransactions;

    private User $teller;

    private User $ao;

    private User $manajer;

    private User $pengurus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teller = $this->buatUser('status.teller@test.local', 'Teller Status', 'teller');
        $this->ao = $this->buatUser('status.ao@test.local', 'AO Status', 'ao');

        // View CIF manajer & pengurus diuji render-nya (badge status).
        $this->manajer = $this->buatUser('status.manajer@test.local', 'Manajer Status', 'manajer');
        $this->pengurus = $this->buatUser('status.pengurus@test.local', 'Pengurus Status', 'pengurus');
    }

    private function buatUser(string $email, string $name, string $role): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make('password'),
                'role' => $role,
                'is_active' => true,
            ]
        );
    }

    /** User sesuai peran untuk uji render halaman. */
    private function userPeran(string $peran): User
    {
        return match ($peran) {
            'teller' => $this->teller,
            'ao' => $this->ao,
            'manajer' => $this->manajer,
            'pengurus' => $this->pengurus,
        };
    }

    /* =====================================================================
     | FIXTURE
     ===================================================================== */

    /** Anggota uji dengan NIK/no_hp unik agar tidak bentrok constraint. */
    private function buatAnggota(string $suffix, string $status = Anggota::STATUS_ACTIVE): Anggota
    {
        // `no_anggota` berukuran pendek — suffix dipotong agar muat.
        $kode = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($suffix)), 0, 8);

        return Anggota::create([
            'no_anggota' => 'TST-'.$kode.'-'.random_int(100, 999),
            'nama_lengkap' => 'Anggota Uji '.$suffix,
            'nik' => '3273'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'jenis_kelamin' => 'Laki-laki',
            'no_hp' => '08'.str_pad((string) random_int(0, 999999999), 10, '0', STR_PAD_LEFT),
            'alamat' => 'Jl. Uji Status No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'tanggal_lahir' => '1990-01-01',
            'tanggal_gabung' => now()->toDateString(),
            'status_anggota' => $status,
        ]);
    }

    /** Payload edit anggota lengkap (field wajib kedua controller). */
    private function payloadEdit(Anggota $anggota, string $status): array
    {
        return [
            'nama_lengkap' => $anggota->nama_lengkap,
            'nik' => $anggota->nik,
            'jenis_kelamin' => $anggota->jenis_kelamin,
            'no_hp' => $anggota->no_hp,
            'alamat' => $anggota->alamat,
            'kota' => $anggota->kota,
            'provinsi' => $anggota->provinsi,
            'status_anggota' => $status,
        ];
    }

    /* =====================================================================
     | (1) UNIT — sumber tunggal kebenaran di model
     ===================================================================== */

    public function test_daftar_nilai_kanonik_konsisten(): void
    {
        $this->assertCount(5, Anggota::STATUS_LIST);
        $this->assertSame(Anggota::STATUS_LIST, array_keys(Anggota::STATUS_LABEL));
    }

    public function test_status_valid_values_memuat_kanonik_dan_alias(): void
    {
        $valid = Anggota::statusValidValues();

        foreach (Anggota::STATUS_LIST as $kanonik) {
            $this->assertContains($kanonik, $valid, "Nilai kanonik {$kanonik} harus valid.");
        }

        foreach (array_keys(Anggota::STATUS_ALIASES) as $alias) {
            $this->assertContains($alias, $valid, "Alias legacy {$alias} harus tetap valid.");
        }

        // Tidak ada duplikat (case-sensitive).
        $this->assertSame($valid, array_values(array_unique($valid)));
    }

    public static function kasusNormalisasi(): array
    {
        return [
            'kanonik apa adanya' => ['ACTIVE', Anggota::STATUS_ACTIVE],
            'huruf kecil' => ['active', Anggota::STATUS_ACTIVE],
            'huruf campur' => ['AcTiVe', Anggota::STATUS_ACTIVE],
            'alias Aktif' => ['Aktif', Anggota::STATUS_ACTIVE],
            'alias Tidak Aktif' => ['Tidak Aktif', Anggota::STATUS_INACTIVE],
            'alias Nonaktif' => ['Nonaktif', Anggota::STATUS_INACTIVE],
            'alias Dibekukan' => ['Dibekukan', Anggota::STATUS_SUSPENDED],
            'spasi berlebih' => ['  PENDING  ', Anggota::STATUS_PENDING],
            'pembanding 1' => ['tidak aktif', Anggota::STATUS_INACTIVE],
            'pembanding 2' => ['NONAKTIF', Anggota::STATUS_INACTIVE],
            'pembanding 3' => ['dibekukan', Anggota::STATUS_SUSPENDED],
            'keluar' => ['keluar', Anggota::STATUS_KELUAR],
        ];
    }

    #[DataProvider('kasusNormalisasi')]
    public function test_normalize_status(string $input, string $harapan): void
    {
        $this->assertSame($harapan, Anggota::normalizeStatus($input));
    }

    public static function kasusTidakDikenal(): array
    {
        return [
            'kosong' => [''],
            'spasi' => ['   '],
            'ngawur' => ['ngawur'],
            'status asing' => ['VERIFIED'],
            'mirip tapi beda' => ['AKTIFF'],
            'angka' => ['1'],
        ];
    }

    #[DataProvider('kasusTidakDikenal')]
    public function test_normalize_status_mengembalikan_null_untuk_nilai_tidak_dikenal(string $input): void
    {
        $this->assertNull(Anggota::normalizeStatus($input));
    }

    public function test_status_storage_values_mencakup_alias_dari_kanonik_yang_sama(): void
    {
        $inactive = Anggota::statusStorageValues(Anggota::STATUS_INACTIVE);

        $this->assertContains(Anggota::STATUS_INACTIVE, $inactive);
        $this->assertContains('Tidak Aktif', $inactive);
        $this->assertContains('Nonaktif', $inactive);

        // Nilai tidak dikenal → tidak ada yang dicari (query tidak dibatasi).
        $this->assertSame([], Anggota::statusStorageValues('ngawur'));
    }

    public function test_status_label_attribut_menangani_data_legacy(): void
    {
        $anggota = $this->buatAnggota('LABEL1', Anggota::STATUS_ACTIVE);
        $this->assertSame('Aktif', $anggota->status_label);

        // Simulasi data lama yang masih berupa alias.
        $anggota->status_anggota = 'Aktif';
        $this->assertSame('Aktif', $anggota->status_label);

        $anggota->status_anggota = 'Tidak Aktif';
        $this->assertSame('Tidak Aktif', $anggota->status_label);
    }

    /* =====================================================================
     | (2) TELLER — teller.anggota.update
     ===================================================================== */

    public static function semuaNilaiSah(): array
    {
        $kasus = [];
        foreach (Anggota::STATUS_LIST as $kanonik) {
            $kasus['kanonik '.$kanonik] = [$kanonik, $kanonik];
        }
        foreach (Anggota::STATUS_ALIASES as $alias => $kanonikTujuan) {
            $kasus['alias '.$alias] = [$alias, $kanonikTujuan];
        }

        return $kasus;
    }

    /**
     * Teller tidak berwenang mengubah status keanggotaan: form teller tidak
     * lagi merender dropdown status dan kiriman status apa pun diabaikan.
     */
    #[DataProvider('semuaNilaiSah')]
    public function test_teller_tidak_dapat_mengubah_status(string $kiriman): void
    {
        $anggota = $this->buatAnggota('TLR', Anggota::STATUS_PENDING);

        $response = $this->actingAs($this->teller)
            ->put(route('teller.anggota.update', $anggota->id), $this->payloadEdit($anggota, $kiriman));

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $this->assertSame(
            Anggota::STATUS_PENDING,
            $anggota->fresh()->status_anggota,
            'Teller tidak berwenang mengubah status keanggotaan.'
        );
    }

    /* =====================================================================
     | (3) CIF (AO) — ao.cif.update
     ===================================================================== */

    /**
     * Spesifikasi modul keanggotaan: perubahan status ke SUSPENDED / INACTIVE /
     * Keluar hanya kewenangan Super Admin & Pengurus. AO tidak boleh
     * "melompati" alur PENDING → ACTIVE, sehingga kiriman status dari AO
     * diabaikan sepenuhnya dan nilai lama dipertahankan.
     */
    #[DataProvider('semuaNilaiSah')]
    public function test_ao_tidak_dapat_mengubah_status_via_cif(string $kiriman): void
    {
        $anggota = $this->buatAnggota('CIF', Anggota::STATUS_PENDING);

        $response = $this->actingAs($this->ao)
            ->put(route('ao.cif.update', $anggota->id), $this->payloadEdit($anggota, $kiriman));

        // Tidak ada galat: field status memang tidak dirender untuk AO,
        // namun datanya tetap tidak boleh berubah dari sisi server.
        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $this->assertSame(
            Anggota::STATUS_PENDING,
            $anggota->fresh()->status_anggota,
            'AO tidak berwenang mengubah status keanggotaan.'
        );
    }

    public function test_pengurus_dapat_mengubah_status_via_cif(): void
    {
        $anggota = $this->buatAnggota('CIF-PRG', Anggota::STATUS_ACTIVE);

        $response = $this->actingAs($this->pengurus)
            ->put(route('pengurus.cif.update', $anggota->id), $this->payloadEdit($anggota, Anggota::STATUS_SUSPENDED));

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $this->assertSame(Anggota::STATUS_SUSPENDED, $anggota->fresh()->status_anggota);
    }

    public function test_ao_mengabaikan_nilai_status_tidak_dikenal_via_cif(): void
    {
        $anggota = $this->buatAnggota('CIF-BAD', Anggota::STATUS_ACTIVE);

        // AO tidak berwenang atas status keanggotaan: nilai apa pun yang dikirim
        // diabaikan tanpa galat dan status lama tetap dipertahankan.
        $response = $this->actingAs($this->ao)
            ->put(route('ao.cif.update', $anggota->id), $this->payloadEdit($anggota, 'VERIFIED'));

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $this->assertSame(Anggota::STATUS_ACTIVE, $anggota->fresh()->status_anggota);
    }

    /* =====================================================================
     | (4) FRONTEND — kewenangan perubahan status per peran
     ===================================================================== */

    public function test_form_edit_teller_tidak_menyediakan_dropdown_status(): void
    {
        $anggota = $this->buatAnggota('VIEW-TLR', Anggota::STATUS_ACTIVE);

        $html = $this->actingAs($this->teller)
            ->get(route('teller.anggota.edit', $anggota->id))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('name="status_anggota"', $html,
            'Teller tidak berwenang mengubah status — dropdown harus dihilangkan.');
    }

    public function test_form_edit_ao_tidak_menyediakan_dropdown_status(): void
    {
        $anggota = $this->buatAnggota('VIEW-CIF', Anggota::STATUS_ACTIVE);

        $html = $this->actingAs($this->ao)
            ->get(route('ao.cif.edit', $anggota->id))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('name="status_anggota"', $html,
            'AO tidak berwenang mengubah status — dropdown harus dihilangkan.');
    }

    public function test_form_edit_pengurus_menawarkan_pilihan_status_manual(): void
    {
        $anggota = $this->buatAnggota('VIEW-PRG', Anggota::STATUS_ACTIVE);

        $html = $this->actingAs($this->pengurus)
            ->get(route('pengurus.cif.edit', $anggota->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="status_anggota"', $html,
            'Pengurus berwenang mengubah status — dropdown harus tersedia.');

        // Hanya status manual yang ditawarkan; ACTIVE & PENDING lahir dari alur sistem.
        foreach (Anggota::STATUS_PILIHAN_MANUAL as $manual) {
            $this->assertStringContainsString('value="'.$manual.'"', $html,
                "Pengurus harus dapat memilih status {$manual}.");
        }

        $this->assertStringNotContainsString('value="'.Anggota::STATUS_PENDING.'"', $html);
    }

    public function test_form_edit_menyediakan_toggle_auto_debit(): void
    {
        $anggota = $this->buatAnggota('VIEW-ADB', Anggota::STATUS_ACTIVE);

        $html = $this->actingAs($this->ao)
            ->get(route('ao.cif.edit', $anggota->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="is_auto_debit"', $html,
            'Form edit harus menyediakan preferensi pembayaran Simpanan Wajib.');
    }

    /* =====================================================================
     | (5) MIDDLEWARE — pembatasan transaksi anggota non-ACTIVE
     ===================================================================== */

    public function test_anggota_pending_diblokir_membuka_rekening_baru(): void
    {
        $anggota = $this->buatAnggota('MW-PND', Anggota::STATUS_PENDING);

        $this->actingAs($this->ao)
            ->post(route('ao.cif.buka-rekening', $anggota->id), [
                'jenis_rekening' => 'Simpanan Sukarela',
                'setoran_awal' => 50000,
                'akad' => 'Wadiah',
            ])
            ->assertForbidden();
    }

    public function test_anggota_suspended_diblokir_membuka_rekening_baru(): void
    {
        $anggota = $this->buatAnggota('MW-SUS', Anggota::STATUS_SUSPENDED);

        $this->actingAs($this->ao)
            ->post(route('ao.cif.buka-rekening', $anggota->id), [
                'jenis_rekening' => 'Simpanan Sukarela',
                'setoran_awal' => 50000,
                'akad' => 'Wadiah',
            ])
            ->assertForbidden();
    }

    public function test_anggota_pending_diblokir_menarik_simpanan_via_teller(): void
    {
        $anggota = $this->buatAnggota('MW-TRK', Anggota::STATUS_PENDING);

        $rekening = Rekening::create([
            'no_rekening' => 'RKN-UJI-TRK-'.$anggota->id,
            'anggota_id' => $anggota->id,
            'akun_id' => Akun::first()?->id ?? 1,
            'jenis_rekening' => 'Simpanan Sukarela',
            'akad' => 'Wadiah',
            'saldo' => 500000,
            'batas_minimum' => 0,
            'tanggal_buka' => now()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);

        $this->actingAs($this->teller)
            ->post(route('teller.tarik.store'), [
                'rekening_id' => $rekening->id,
                'jumlah' => 100000,
            ])
            ->assertForbidden();

        $this->assertSame(500000.0, (float) $rekening->fresh()->saldo,
            'Saldo tidak boleh berkurang saat transaksi diblokir.');
    }

    public function test_anggota_pending_diblokir_membuka_deposito_via_teller(): void
    {
        $anggota = $this->buatAnggota('MW-DEP', Anggota::STATUS_PENDING);

        // Rute dijaga `kas.terbuka` lalu `anggota.aktif`; laci harus OPEN agar
        // penolakan yang teruji benar-benar berasal dari status anggota (403).
        $this->bukaSesiKas($this->teller);

        // Nisbah & tenor kini wajib berasal dari produk deposito (akad).
        $produk = DepositoProduct::firstOrCreate(
            ['name' => 'Deposito Uji Status Anggota'],
            [
                'tipe_produk' => DepositoProduct::TIPE_DEPOSITO,
                'has_nisbah' => true,
                'coa_kode' => DepositoProduct::COA_BY_TIPE[DepositoProduct::TIPE_DEPOSITO],
                'tenor_months' => 3,
                'nisbah_anggota' => 60.00,
                'nisbah_koperasi' => 40.00,
                'min_amount' => 100000,
                'is_active' => true,
            ]
        );

        $this->actingAs($this->teller)
            ->post(route('teller.deposito.store'), [
                'anggota_id' => $anggota->id,
                'nominal' => 5000000,
                'deposito_product_id' => $produk->id,
            ])
            ->assertForbidden();
    }

    /* =====================================================================
     | (5) FILTER — kanonik & alias legacy harus sama-sama bekerja
     ===================================================================== */

    public function test_filter_daftar_anggota_menerima_nilai_kanonik(): void
    {
        $this->actingAs($this->teller)
            ->get(route('teller.anggota.index', ['status' => Anggota::STATUS_ACTIVE]))
            ->assertOk();
    }

    #[DataProvider('semuaNilaiSah')]
    public function test_filter_teller_tidak_error_untuk_setiap_nilai_sah(string $nilai): void
    {
        $this->actingAs($this->teller)
            ->get(route('teller.anggota.index', ['status' => $nilai]))
            ->assertOk();
    }

    public function test_filter_teller_aman_untuk_nilai_tidak_dikenal(): void
    {
        // Nilai ngawur diabaikan (tidak memfilter / tidak error), bukan 500.
        $this->actingAs($this->teller)
            ->get(route('teller.anggota.index', ['status' => 'ngawur']))
            ->assertOk();
    }

    public function test_filter_cif_aman_untuk_nilai_tidak_dikenal(): void
    {
        $this->actingAs($this->ao)
            ->get(route('ao.cif.index', ['status' => 'ngawur']))
            ->assertOk();
    }

    /* =====================================================================
     | (6) SCOPE — agregat & query lain ikut konsisten
     ===================================================================== */

    public function test_scope_aktif_menghitung_kanonik_dan_alias(): void
    {
        $kanonik = $this->buatAnggota('SCOPE-K', Anggota::STATUS_ACTIVE);
        $alias = $this->buatAnggota('SCOPE-A', 'Aktif');

        $ids = Anggota::aktif()->pluck('id');

        $this->assertTrue($ids->contains($kanonik->id));
        $this->assertTrue($ids->contains($alias->id), 'Data legacy "Aktif" harus tetap terhitung aktif.');
    }

    public function test_scope_status_mencakup_alias_dari_kanonik(): void
    {
        $this->buatAnggota('SCOPE-TA', 'Tidak Aktif');
        $this->buatAnggota('SCOPE-N', 'Nonaktif');
        $this->buatAnggota('SCOPE-KAN', Anggota::STATUS_INACTIVE);

        $jumlah = Anggota::status(Anggota::STATUS_INACTIVE)->count();

        $this->assertGreaterThanOrEqual(3, $jumlah);
    }

    public function test_is_active_mengenali_data_legacy(): void
    {
        $anggota = $this->buatAnggota('ISACT', 'Aktif');

        $this->assertTrue($anggota->isActive());
        $this->assertFalse($anggota->isPending());
    }

    /* =====================================================================
     | (7) GUARD PEMBIAYAAN — tidak boleh menolak anggota kanonik 'ACTIVE'
     ===================================================================== */

    /**
     * Bug terkait: guard memakai `strtolower($status) !== 'aktif'` sehingga
     * anggota berstatus kanonik 'ACTIVE' (hasil 'ACTIVE' → 'active' ≠ 'aktif')
     * ikut ditolak. Sekarang guard memakai Anggota::isActive().
     */
    public static function kasusGuardPembiayaan(): array
    {
        return [
            'kanonik ACTIVE' => [Anggota::STATUS_ACTIVE, true],
            'alias legacy Aktif' => ['Aktif', true],
            'huruf kecil active' => ['active', true],
            'PENDING ditolak' => [Anggota::STATUS_PENDING, false],
            'INACTIVE ditolak' => [Anggota::STATUS_INACTIVE, false],
            'Nonaktif ditolak' => ['Nonaktif', false],
        ];
    }

    #[DataProvider('kasusGuardPembiayaan')]
    public function test_guard_kelayakan_pembiayaan_hanya_lolos_untuk_status_aktif(string $status, bool $lolos): void
    {
        $anggota = $this->buatAnggota('GUARD', $status);

        $response = $this->actingAs($this->ao)
            ->getJson(route('pengajuan-pembiayaan.eligibility-check', ['anggota_id' => $anggota->id]));

        $response->assertOk();

        $issues = $response->json('issues') ?? [];
        $isuStatus = array_values(array_filter(
            $issues,
            fn (string $i) => str_contains($i, 'Status anggota tidak AKTIF')
        ));

        if ($lolos) {
            $this->assertSame(
                [],
                $isuStatus,
                "Status '{$status}' seharusnya dikenali aktif, tetapi ditolak guard kelayakan."
            );
        } else {
            $this->assertCount(
                1,
                $isuStatus,
                "Status '{$status}' seharusnya ditolak guard kelayakan."
            );
        }
    }

    /* =====================================================================
     | (8) RENDER VIEW — label & badge status konsisten di semua peran
     ===================================================================== */

    /**
     * Daftar halaman (peran + route) yang menampilkan status anggota.
     * Kolom ke-3: nama query pencarian yang wajib diisi (teller CIF = search).
     */
    public static function halamanStatusPerPeran(): array
    {
        return [
            'daftar nasabah AO' => ['ao', 'ao.nasabah.index', null],
            'daftar CIF AO' => ['ao', 'ao.cif.index', null],
            'daftar CIF teller' => ['teller', 'teller.cif.index', 'q'],
            'daftar CIF manajer' => ['manajer', 'manajer.cif.index', null],
            'daftar CIF pengurus' => ['pengurus', 'pengurus.cif.index', null],
            'daftar anggota' => ['pengurus', 'pengurus.anggota.index', null],
            'daftar anggota teller' => ['teller', 'teller.anggota.index', null],
        ];
    }

    /**
     * Nilai kanonik 'ACTIVE' harus tampil dengan label ramah "Aktif" — bukan
     * badge merah / teks mentah "ACTIVE" seperti sebelum normalisasi.
     */
    #[DataProvider('halamanStatusPerPeran')]
    public function test_halaman_daftar_menampilkan_label_status_kanonik(string $peran, string $route, ?string $queryCari): void
    {
        $anggota = $this->buatAnggota('RENDER-'.$peran, Anggota::STATUS_ACTIVE);

        // Halaman CIF teller hanya menampilkan hasil setelah dicari.
        $params = $queryCari ? [$queryCari => $anggota->nama_lengkap] : [];

        $html = $this->actingAs($this->userPeran($peran))
            ->get(route($route, $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            $this->labelAktif(),
            $html,
            "Halaman {$route} harus menampilkan label \"{$this->labelAktif()}\" untuk status ACTIVE."
        );
    }

    private function labelAktif(): string
    {
        return Anggota::STATUS_LABEL[Anggota::STATUS_ACTIVE];
    }

    /** Data legacy 'Aktif' harus menghasilkan label yang sama dengan 'ACTIVE'. */
    public function test_halaman_daftar_menampilkan_label_sama_untuk_data_legacy(): void
    {
        $this->buatAnggota('LEGACY-AO', 'Aktif');

        $this->actingAs($this->ao)
            ->get(route('ao.cif.index'))
            ->assertOk()
            ->assertSee($this->labelAktif());
    }

    /** Halaman detail anggota merender label, bukan nilai enum mentah. */
    #[DataProvider('halamanDetailPerPeran')]
    public function test_halaman_detail_menampilkan_label_status(string $peran, string $route): void
    {
        $anggota = $this->buatAnggota('DETAIL-'.$peran, Anggota::STATUS_ACTIVE);

        $this->actingAs($this->userPeran($peran))
            ->get(route($route, $anggota->id))
            ->assertOk()
            ->assertSee($this->labelAktif());
    }

    public static function halamanDetailPerPeran(): array
    {
        return [
            'detail CIF AO' => ['ao', 'ao.cif.show'],
            'detail CIF teller' => ['teller', 'teller.cif.show'],
            'detail CIF pengurus' => ['pengurus', 'pengurus.cif.show'],
            'detail CIF manajer' => ['manajer', 'manajer.cif.show'],
            'detail nasabah AO' => ['ao', 'ao.nasabah.show'],
        ];
    }

    /** Semua halaman tetap render normal untuk anggota berstatus non-aktif. */
    #[DataProvider('statusNonAktif')]
    public function test_halaman_daftar_aman_untuk_status_non_aktif(string $status): void
    {
        $this->buatAnggota('NONAKTIF', $status);

        $this->actingAs($this->ao)->get(route('ao.cif.index'))->assertOk();
        $this->actingAs($this->teller)->get(route('teller.cif.index'))->assertOk();
        $this->actingAs($this->pengurus)->get(route('pengurus.anggota.index'))->assertOk();
    }

    public static function statusNonAktif(): array
    {
        return [
            'PENDING' => [Anggota::STATUS_PENDING],
            'INACTIVE' => [Anggota::STATUS_INACTIVE],
            'SUSPENDED' => [Anggota::STATUS_SUSPENDED],
            'Keluar' => [Anggota::STATUS_KELUAR],
            'alias Nonaktif' => ['Nonaktif'],
        ];
    }

    /**
     * Setiap status punya label ramah. Khusus 'Keluar' nilai kanonik memang
     * sama dengan labelnya, jadi pembandingnya label itu sendiri.
     */
    public function test_semua_status_memiliki_label_ramah(): void
    {
        $anggota = $this->buatAnggota('ALL-LABEL', Anggota::STATUS_ACTIVE);

        foreach (Anggota::STATUS_LIST as $kanonik) {
            $anggota->status_anggota = $kanonik;

            $label = (string) $anggota->status_label;

            $this->assertNotSame('', $label, "Status {$kanonik} harus punya label ramah.");
            $this->assertSame(
                Anggota::STATUS_LABEL[$kanonik],
                $label,
                "Label untuk status {$kanonik} tidak sesuai STATUS_LABEL."
            );
        }
    }

    /**
     * Nilai kanonik harus punya label berbeda dari nilai mentah enum — kalau
     * tidak, pengguna melihat "ACTIVE" di layar (gejala bug lama).
     */
    public function test_label_kanonik_bukan_nilai_enum_mentah(): void
    {
        $anggota = $this->buatAnggota('RAW-LABEL', Anggota::STATUS_ACTIVE);

        foreach ([Anggota::STATUS_ACTIVE, Anggota::STATUS_PENDING, Anggota::STATUS_INACTIVE, Anggota::STATUS_SUSPENDED] as $kanonik) {
            $anggota->status_anggota = $kanonik;

            $this->assertNotSame(
                $kanonik,
                $anggota->status_label,
                "Status {$kanonik} harus ditampilkan dengan label ramah, bukan nilai enum mentah."
            );
        }
    }

    /** Data tak dikenal tetap tampil apa adanya (tidak error/500). */
    public function test_label_status_tidak_dikenal_tidak_error(): void
    {
        $anggota = $this->buatAnggota('ODD-LABEL', Anggota::STATUS_ACTIVE);
        $anggota->status_anggota = 'VERIFIED';

        $this->assertSame('VERIFIED', $anggota->status_label);
    }

    /* =====================================================================
     | (7) ANTI-DUPLIKASI REKENING POKOK/WAJIB
     |
     | Aturan koperasi: Simpanan Pokok & Simpanan Wajib hanya boleh dimiliki
     | SATU KALI per anggota. Pengunciannya berlapis dua:
     |   - FRONTEND : opsi ditandai "(sudah ada ...)" dan `disabled`.
     |   - BACKEND  : guard `jenisTunggalSudahDimiliki()` menolak upaya bypass
     |                (kirim ulang via POST langsung / API call).
     ===================================================================== */

    /** Rekening Simpanan Pokok aktif untuk anggota uji. */
    private function rekeningPokok(Anggota $anggota, float $saldo = 100000): Rekening
    {
        return Rekening::create([
            'no_rekening' => 'RKN-UJI-POKOK-'.$anggota->id.'-'.random_int(100, 999),
            'anggota_id' => $anggota->id,
            'akun_id' => Akun::first()?->id ?? 1,
            'jenis_rekening' => 'Simpanan Pokok',
            'akad' => 'Wadiah',
            'saldo' => $saldo,
            'batas_minimum' => 0,
            'tanggal_buka' => now()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);
    }

    public function test_dropdown_menandai_dan_menonaktifkan_jenis_tunggal_yang_sudah_dimiliki(): void
    {
        $anggota = $this->buatAnggota('DD-POKOK', Anggota::STATUS_ACTIVE);
        $this->rekeningPokok($anggota);

        $html = $this->actingAs($this->ao)
            ->get(route('ao.cif.show', $anggota->id))
            ->assertOk()
            ->getContent();

        // Opsi Simpanan Pokok harus ditandai "(sudah ada" dan dinonaktifkan,
        // sedangkan Simpanan Sukarela tetap dapat dipilih.
        $this->assertMatchesRegularExpression(
            '/<option[^>]*value="Simpanan Pokok"[^>]*>/',
            $html,
            'Dropdown jenis rekening harus memuat opsi Simpanan Pokok.'
        );

        $potonganPokok = $this->potonganOpsi($html, 'Simpanan Pokok');

        $this->assertStringContainsString('(sudah ada', $potonganPokok,
            'Opsi Simpanan Pokok yang sudah dimiliki wajib ditandai "(sudah ada)".');
        $this->assertStringContainsString('disabled', $potonganPokok,
            'Opsi Simpanan Pokok yang sudah dimiliki wajib dinonaktifkan (disabled).');

        $this->assertStringNotContainsString('(sudah ada', $this->potonganOpsi($html, 'Simpanan Sukarela'),
            'Simpanan Sukarela bersifat majemuk — tidak boleh ditandai "(sudah ada)".');
    }

    public function test_backend_memblokir_pembukaan_rekening_pokok_kedua(): void
    {
        $anggota = $this->buatAnggota('BP-POKOK', Anggota::STATUS_ACTIVE);
        $this->rekeningPokok($anggota);

        $response = $this->actingAs($this->ao)
            ->from(route('ao.cif.show', $anggota->id))
            ->post(route('ao.cif.buka-rekening', $anggota->id), [
                'jenis_rekening' => 'Simpanan Pokok',
                'setoran_awal' => 100000,
                'akad' => 'Wadiah',
            ]);

        $response->assertSessionHasErrors('jenis_rekening');

        $pesan = session('errors')->first('jenis_rekening');
        $this->assertStringContainsString('sudah memiliki rekening Simpanan Pokok', $pesan,
            'Pesan penolakan harus menyebut jenis rekening yang sudah dimiliki.');

        // Tidak ada rekening kedua yang boleh terbentuk.
        $this->assertSame(
            1,
            Rekening::where('anggota_id', $anggota->id)
                ->where('jenis_rekening', 'Simpanan Pokok')
                ->count(),
            'Upaya bypass pembukaan rekening Pokok kedua harus ditolak backend.'
        );
    }

    public function test_pesan_sudah_ada_konsisten_dengan_model(): void
    {
        $pesan = JenisSimpanan::pesanSudahAda('Simpanan Wajib');

        $this->assertStringContainsString('Anggota sudah memiliki rekening Simpanan Wajib yang aktif.', $pesan);
        $this->assertStringContainsString('pembukaan rekening kedua tidak diizinkan.', $pesan);
    }

    /** Potongan HTML tepat pada satu <option> berdasarkan nilainya. */
    private function potonganOpsi(string $html, string $nilai): string
    {
        $penanda = 'value="'.$nilai.'"';
        $posisi = strpos($html, $penanda);

        if ($posisi === false) {
            return '';
        }

        $akhir = strpos($html, '</option>', $posisi);

        if ($akhir === false) {
            return substr($html, $posisi, 400);
        }

        // Mundur hanya sampai awal tag <option> PEMBUKA milik nilai ini agar
        // atribut (mis. `disabled`) ikut terbaca tanpa menyerempet opsi
        // sebelumnya. Bila tag pembuka tidak ditemukan di antara batas yang
        // wajar, cukup mulai dari posisi `value=` itu sendiri.
        $jendela = substr($html, max(0, $posisi - 200), min(200, $posisi));
        $awalOpsi = strrpos($jendela, '<option');
        $awal = $awalOpsi === false ? $posisi : max(0, $posisi - 200) + $awalOpsi;

        return substr($html, $awal, ($akhir - $awal) + 9);
    }
}
