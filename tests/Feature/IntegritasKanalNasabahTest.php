<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\Deposito;
use App\Models\MasterProdukPembiayaan;
use App\Models\Pembiayaan;
use App\Models\PengajuanPembiayaan;
use App\Models\User;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BATCH 22 — Modul Nasabah (kanal swalayan anggota).
 *
 * Menutup 11 rute `nasabah.*` yang sebelumnya NOL rujukan test:
 *   N1  nasabah.deposito.index              N7  nasabah.pengajuan-pinjaman.store
 *   N2  nasabah.pembiayaan.index            N8  nasabah.pengajuan-pinjaman.baru
 *   N3  nasabah.pengajuan.index             N9  nasabah.pengajuan-pinjaman.store.baru
 *   N4  nasabah.pengajuan.create            N10 nasabah.status-pengajuan.index
 *   N5  nasabah.pengajuan.store             N11 nasabah.angsuran.index
 *   N6  nasabah.pengajuan-pinjaman.index
 *
 * Fokus integritas:
 *  - isolasi data antaranggota (satu anggota tidak boleh melihat data anggota lain),
 *  - akun tanpa baris `anggota` ditolak 403 (bukan 500 / bukan data kosong),
 *  - kolom turunan pengajuan (margin, total, angsuran) terisi benar,
 *  - plafon di luar rentang produk ditolak sebelum tersimpan.
 */
class IntegritasKanalNasabahTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private Anggota $anggota;

    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['anggota', 'pengajuan_pembiayaan', 'master_produk_pembiayaan', 'depositos', 'pembiayaan', 'angsuran'] as $tabel) {
            if (! Schema::hasTable($tabel)) {
                $this->markTestSkipped("Tabel {$tabel} belum tersedia.");
            }
        }

        $this->suffix = strtoupper(substr(uniqid(), -6));

        $this->user = User::create([
            'name' => 'Nasabah Batch 22 '.$this->suffix,
            'email' => 'batch22.nasabah.'.strtolower($this->suffix).'@test.local',
            'password' => Hash::make('rahasiabatch22'),
            'role' => 'anggota',
        ]);

        $this->anggota = $this->buatAnggota($this->user, '22A');

        Setting::flush();
    }

    protected function tearDown(): void
    {
        Setting::flush();

        parent::tearDown();
    }

    /* ===================== HELPER ===================== */

    private function buatAnggota(?User $user, string $kode): Anggota
    {
        return Anggota::create([
            'no_anggota' => 'ANG-B22-'.$kode.'-'.$this->suffix,
            'user_id' => $user?->id,
            'nik' => '32730'.$kode.str_pad((string) random_int(0, 999999999), 11, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Batch 22 '.$kode,
            'tempat_lahir' => 'Bandung',
            'tanggal_lahir' => '1990-01-15',
            'jenis_kelamin' => 'Laki-laki',
            'status_perkawinan' => 'Menikah',
            'alamat' => 'Jl. Uji Batch 22 No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_ACTIVE,
            'tanggal_gabung' => now()->subYear()->toDateString(),
        ]);
    }

    private function produkUji(float $min = 1000000, float $max = 50000000, float $margin = 10): MasterProdukPembiayaan
    {
        return MasterProdukPembiayaan::create([
            'kode_produk' => 'B22-'.$this->suffix.'-'.strtoupper(substr(uniqid(), -4)),
            'nama_produk' => 'Produk Uji Batch 22 '.$this->suffix,
            'akad_syariah' => 'Murabahah',
            'min_plafon' => $min,
            'max_plafon' => $max,
            'margin_persen' => $margin,
            'biaya_admin' => 0,
            'min_tenor_bulan' => 1,
            'max_tenor_bulan' => 60,
            'status_aktif' => true,
        ]);
    }

    private function pengajuanMilik(Anggota $anggota, string $nomor): PengajuanPembiayaan
    {
        return PengajuanPembiayaan::create([
            'no_pengajuan' => $nomor,
            'anggota_id' => $anggota->id,
            'jenis_akad' => 'Murabahah',
            'jumlah_pengajuan' => 5000000,
            'tenor' => 10,
            'tujuan' => 'Modal usaha uji batch 22',
            'status' => PengajuanPembiayaan::STATUS_MENUNGGU,
            'step_terakhir' => 1,
        ]);
    }

    private function muatanPengajuan(int $produkId, float $nominal = 10000000, int $tenor = 10): array
    {
        return [
            'produk_id' => $produkId,
            'jenis_akad' => 'Murabahah',
            'jumlah_pengajuan' => $nominal,
            'tenor' => $tenor,
            'tujuan' => 'Modal usaha dagang',
            'agunan' => 'BPKB motor',
            'penghasilan_bulanan' => 8000000,
            'pengeluaran_bulanan' => 5000000,
            'jumlah_tanggungan' => 2,
        ];
    }

    /* =====================================================================
     | GERBANG: AKUN TANPA DATA ANGGOTA
     ===================================================================== */

    public function test_akun_tanpa_baris_anggota_ditolak_403_bukan_galat_server(): void
    {
        $tanpa = User::create([
            'name' => 'Tanpa Anggota '.$this->suffix,
            'email' => 'batch22.tanpa.'.strtolower($this->suffix).'@test.local',
            'password' => Hash::make('rahasiabatch22'),
            'role' => 'anggota',
        ]);

        $this->actingAs($tanpa)
            ->get(route('nasabah.pembiayaan.index'))
            ->assertForbidden();
    }

    /* =====================================================================
     | N1 — DEPOSITO
     ===================================================================== */

    public function test_deposito_index_hanya_menampilkan_bilyet_milik_anggota(): void
    {
        $produkId = DB::table('deposito_products')->value('id');
        $rekeningId = DB::table('rekening')->value('id');

        if ($produkId === null || $rekeningId === null) {
            $this->markTestSkipped('Produk deposito / rekening belum tersedia.');
        }

        $lain = $this->buatAnggota(null, '22Z');

        $milikSendiri = $this->buatDeposito((int) $this->anggota->id, (int) $produkId, (int) $rekeningId, 'DEP-B22-SENDIRI', 10000000, Deposito::STATUS_ACTIVE);
        $milikLain = $this->buatDeposito((int) $lain->id, (int) $produkId, (int) $rekeningId, 'DEP-B22-LAIN', 25000000, Deposito::STATUS_ACTIVE);

        $respon = $this->actingAs($this->user)->get(route('nasabah.deposito.index'));

        $respon->assertOk();

        $daftar = $respon->viewData('deposito');
        $nomor = collect($daftar)->pluck('deposito_number')->all();

        $this->assertContains($milikSendiri->deposito_number, $nomor,
            'Bilyet milik sendiri harus tampil.');
        $this->assertNotContains($milikLain->deposito_number, $nomor,
            'Bilyet milik anggota lain TIDAK boleh tampil (isolasi data).');
    }

    public function test_deposito_index_menghitung_pokok_hanya_dari_status_aktif(): void
    {
        $produkId = DB::table('deposito_products')->value('id');
        $rekeningId = DB::table('rekening')->value('id');

        if ($produkId === null || $rekeningId === null) {
            $this->markTestSkipped('Produk deposito / rekening belum tersedia.');
        }

        $this->buatDeposito((int) $this->anggota->id, (int) $produkId, (int) $rekeningId, 'DEP-B22-AKTIF', 10000000, Deposito::STATUS_ACTIVE);
        $this->buatDeposito((int) $this->anggota->id, (int) $produkId, (int) $rekeningId, 'DEP-B22-TUTUP', 5000000, Deposito::STATUS_CLOSED);

        $respon = $this->actingAs($this->user)->get(route('nasabah.deposito.index'));
        $respon->assertOk();

        $this->assertEqualsWithDelta(10000000, (float) $respon->viewData('totalPokok'), 0.01,
            'Total pokok hanya menghitung bilyet berstatus ACTIVE.');
    }

    private function buatDeposito(int $anggotaId, int $produkId, int $rekeningId, string $nomor, float $pokok, string $status): Deposito
    {
        return Deposito::create([
            'deposito_number' => $nomor.'-'.$this->suffix,
            'member_id' => $anggotaId,
            'product_id' => $produkId,
            'source_saving_account_id' => $rekeningId,
            'principal_amount' => $pokok,
            'nisbah_anggota' => 60,
            'nisbah_koperasi' => 40,
            'start_date' => now()->subMonths(3)->toDateString(),
            'maturity_date' => now()->addMonths(9)->toDateString(),
            'status' => $status,
        ]);
    }

    /* =====================================================================
     | N2 — PEMBIAYAAN
     ===================================================================== */

    public function test_pembiayaan_index_hanya_menampilkan_pembiayaan_milik_anggota(): void
    {
        $akunId = DB::table('akun')->value('id');

        if ($akunId === null) {
            $this->markTestSkipped('Akun legacy belum tersedia untuk fixture pembiayaan.');
        }

        $lain = $this->buatAnggota(null, '22Y');

        $milikSendiri = $this->buatPembiayaan((int) $this->anggota->id, (int) $akunId, 'PB-B22-SENDIRI');
        $milikLain = $this->buatPembiayaan((int) $lain->id, (int) $akunId, 'PB-B22-LAIN');

        $respon = $this->actingAs($this->user)->get(route('nasabah.pembiayaan.index'));
        $respon->assertOk();

        $nomor = collect($respon->viewData('items'))->pluck('no_pembiayaan')->all();

        $this->assertContains($milikSendiri->no_pembiayaan, $nomor);
        $this->assertNotContains($milikLain->no_pembiayaan, $nomor,
            'Pembiayaan anggota lain tidak boleh bocor ke kanal nasabah.');
    }

    private function buatPembiayaan(int $anggotaId, int $akunId, string $nomor): Pembiayaan
    {
        return Pembiayaan::create([
            'no_pembiayaan' => $nomor.'-'.$this->suffix,
            'anggota_id' => $anggotaId,
            'akun_id' => $akunId,
            'akad_pembiayaan' => 'Murabahah',
            'tujuan_pembiayaan' => 'Uji batch 22',
            'jumlah_pembiayaan' => 10000000,
            'total_pembiayaan' => 11000000,
            'tenor' => 10,
            'jumlah_angsuran' => 1100000,
            'sisa_pembiayaan' => 11000000,
            'tanggal_akad' => now()->subMonth()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths(9)->toDateString(),
            'status_pembiayaan' => Pembiayaan::STATUS_AKTIF,
        ]);
    }

    /* =====================================================================
     | N3–N5 — PENGAJUAN PEMBIAYAAN
     ===================================================================== */

    public function test_pengajuan_index_hanya_menampilkan_pengajuan_milik_anggota(): void
    {
        $lain = $this->buatAnggota(null, '22X');

        $milikSendiri = $this->pengajuanMilik($this->anggota, 'PJN-B22-SENDIRI-'.$this->suffix);
        $milikLain = $this->pengajuanMilik($lain, 'PJN-B22-LAIN-'.$this->suffix);

        $respon = $this->actingAs($this->user)->get(route('nasabah.pengajuan.index'));
        $respon->assertOk();

        $nomor = collect($respon->viewData('pengajuan')->items())->pluck('no_pengajuan')->all();

        $this->assertContains($milikSendiri->no_pengajuan, $nomor);
        $this->assertNotContains($milikLain->no_pengajuan, $nomor);
    }

    public function test_pengajuan_create_menampilkan_produk_yang_tersedia(): void
    {
        $produk = $this->produkUji();

        $respon = $this->actingAs($this->user)->get(route('nasabah.pengajuan.create'));
        $respon->assertOk();

        $this->assertContains($produk->id,
            collect($respon->viewData('produk'))->pluck('id')->all(),
            'Produk pembiayaan harus tersedia pada formulir pengajuan.');
    }

    public function test_pengajuan_store_menghitung_margin_total_dan_angsuran(): void
    {
        $produk = $this->produkUji(1000000, 50000000, 10);

        $this->actingAs($this->user)
            ->post(route('nasabah.pengajuan.store'), $this->muatanPengajuan($produk->id, 10000000, 10))
            ->assertRedirect(route('nasabah.pengajuan.index'))
            ->assertSessionHas('success');

        $pengajuan = PengajuanPembiayaan::where('anggota_id', $this->anggota->id)
            ->where('jumlah_pengajuan', 10000000)
            ->latest('id')
            ->first();

        $this->assertNotNull($pengajuan, 'Pengajuan nasabah harus tersimpan.');

        // margin 10% dari 10.000.000 = 1.000.000 → total 11.000.000; tenor 10 → 1.100.000/bulan
        $this->assertEqualsWithDelta(10, (float) $pengajuan->margin_persen, 0.01);
        $this->assertEqualsWithDelta(11000000, (float) $pengajuan->total_pembiayaan, 0.01);
        $this->assertEqualsWithDelta(1100000, (float) $pengajuan->angsuran_per_bulan, 0.01);
        $this->assertSame(PengajuanPembiayaan::STATUS_MENUNGGU, $pengajuan->status);
        $this->assertSame((int) $this->anggota->id, (int) $pengajuan->anggota_id,
            'Pengajuan harus tercatat atas anggota yang login, bukan dari input.');
    }

    public function test_pengajuan_store_menolak_nominal_di_bawah_plafon_minimum(): void
    {
        $produk = $this->produkUji(5000000, 50000000, 10);

        $this->actingAs($this->user)
            ->from(route('nasabah.pengajuan.create'))
            ->post(route('nasabah.pengajuan.store'), $this->muatanPengajuan($produk->id, 2000000, 10))
            ->assertRedirect(route('nasabah.pengajuan.create'))
            ->assertSessionHas('error');

        $this->assertFalse(
            PengajuanPembiayaan::where('anggota_id', $this->anggota->id)
                ->where('jumlah_pengajuan', 2000000)->exists(),
            'Pengajuan di bawah plafon minimum tidak boleh tersimpan.'
        );
    }

    public function test_pengajuan_store_menolak_nominal_di_atas_plafon_maksimum(): void
    {
        $produk = $this->produkUji(1000000, 5000000, 10);

        $this->actingAs($this->user)
            ->from(route('nasabah.pengajuan.create'))
            ->post(route('nasabah.pengajuan.store'), $this->muatanPengajuan($produk->id, 9000000, 10))
            ->assertRedirect(route('nasabah.pengajuan.create'))
            ->assertSessionHas('error');

        $this->assertFalse(
            PengajuanPembiayaan::where('anggota_id', $this->anggota->id)
                ->where('jumlah_pengajuan', 9000000)->exists(),
            'Pengajuan di atas plafon maksimum tidak boleh tersimpan.'
        );
    }

    /* =====================================================================
     | N6–N9 — WIZARD PENGAJUAN PINJAMAN
     ===================================================================== */

    public function test_pengajuan_pinjaman_index_menampilkan_produk_dan_riwayat(): void
    {
        $produk = $this->produkUji();
        $riwayat = $this->pengajuanMilik($this->anggota, 'PJN-B22-WIZ-'.$this->suffix);

        $respon = $this->actingAs($this->user)->get(route('nasabah.pengajuan-pinjaman.index'));
        $respon->assertOk();

        $this->assertContains($produk->id, collect($respon->viewData('produk'))->pluck('id')->all());
        $this->assertContains($riwayat->no_pengajuan,
            collect($respon->viewData('riwayat')->items())->pluck('no_pengajuan')->all());
    }

    public function test_pengajuan_pinjaman_store_mencatat_pengajuan_milik_anggota(): void
    {
        $produk = $this->produkUji(1000000, 50000000, 12);

        $this->actingAs($this->user)
            ->post(route('nasabah.pengajuan-pinjaman.store'), $this->muatanPengajuan($produk->id, 12000000, 12))
            ->assertRedirect(route('nasabah.pengajuan.index'))
            ->assertSessionHas('success');

        $pengajuan = PengajuanPembiayaan::where('anggota_id', $this->anggota->id)
            ->where('jumlah_pengajuan', 12000000)->latest('id')->first();

        $this->assertNotNull($pengajuan);
        // margin 12% dari 12.000.000 = 1.440.000 → total 13.440.000; tenor 12 → 1.120.000
        $this->assertEqualsWithDelta(13440000, (float) $pengajuan->total_pembiayaan, 0.01);
        $this->assertEqualsWithDelta(1120000, (float) $pengajuan->angsuran_per_bulan, 0.01);
    }

    public function test_pengajuan_pinjaman_baru_menampilkan_formulir_wizard(): void
    {
        $produk = $this->produkUji();

        $respon = $this->actingAs($this->user)->get(route('nasabah.pengajuan-pinjaman.baru'));
        $respon->assertOk();

        $this->assertContains($produk->id, collect($respon->viewData('produk'))->pluck('id')->all());
    }

    public function test_pengajuan_pinjaman_store_baru_menyimpan_pengajuan_dan_dokumen(): void
    {
        Storage::fake('dokumen');

        $produk = $this->produkUji(1000000, 50000000, 10);

        $muatan = [
            'produk_id' => $produk->id,
            'jumlah_pengajuan' => 8000000,
            'tenor' => 8,
            'tujuan' => 'Renovasi warung',
            'penghasilan_bulanan' => 7000000,
            'pengeluaran_bulanan' => 4000000,
            'jumlah_tanggungan' => 3,
            'agunan_tipe' => 'BPKB Motor',
            'agunan_deskripsi' => 'Honda Vario 2020',
            'dokumen_ktp' => UploadedFile::fake()->create('ktp.pdf', 100, 'application/pdf'),
            'dokumen_kk' => UploadedFile::fake()->create('kk.pdf', 100, 'application/pdf'),
            'dokumen_penghasilan' => UploadedFile::fake()->create('slip.pdf', 100, 'application/pdf'),
        ];

        $this->actingAs($this->user)
            ->post(route('nasabah.pengajuan-pinjaman.store.baru'), $muatan)
            ->assertRedirect(route('nasabah.status-pengajuan.index'))
            ->assertSessionHas('success');

        $pengajuan = PengajuanPembiayaan::where('anggota_id', $this->anggota->id)
            ->where('jumlah_pengajuan', 8000000)->latest('id')->first();

        $this->assertNotNull($pengajuan, 'Pengajuan wizard baru harus tersimpan.');
        $this->assertSame('Murabahah', $pengajuan->jenis_akad,
            'Jenis akad diambil dari produk, bukan dari input pengguna.');
        $this->assertStringContainsString('BPKB Motor', (string) $pengajuan->agunan);

        $this->assertSame(3, $pengajuan->dokumen()->count(),
            'Tiga dokumen wajib harus tersimpan sebagai lampiran pengajuan.');

        // Regresi BUG-25: kolom yang ditulis harus sesuai skema
        // `dokumen_pengajuan_pembiayaan` (nama_file/path_file/ukuran_file).
        $dokumen = $pengajuan->dokumen()->orderBy('id')->first();
        $this->assertSame('ktp.pdf', $dokumen->nama_file,
            'Nama berkas asli harus tersimpan pada kolom nama_file.');
        $this->assertNotEmpty($dokumen->path_file,
            'Path penyimpanan harus tersimpan pada kolom path_file.');
        $this->assertGreaterThan(0, (int) $dokumen->ukuran_file,
            'Ukuran berkas harus tercatat.');
        $this->assertSame((int) $this->user->id, (int) $dokumen->diupload_oleh,
            'Pengunggah dokumen harus tercatat untuk jejak audit.');

        Storage::disk('dokumen')->assertExists($dokumen->path_file);
    }

    public function test_pengajuan_pinjaman_store_baru_menolak_tanpa_dokumen_wajib(): void
    {
        $produk = $this->produkUji();

        $this->actingAs($this->user)
            ->from(route('nasabah.pengajuan-pinjaman.baru'))
            ->post(route('nasabah.pengajuan-pinjaman.store.baru'), [
                'produk_id' => $produk->id,
                'jumlah_pengajuan' => 8000000,
                'tenor' => 8,
                'tujuan' => 'Renovasi warung',
                'penghasilan_bulanan' => 7000000,
                'pengeluaran_bulanan' => 4000000,
                'jumlah_tanggungan' => 3,
                'agunan_tipe' => 'BPKB Motor',
                'agunan_deskripsi' => 'Honda Vario 2020',
            ])
            ->assertSessionHasErrors(['dokumen_ktp', 'dokumen_kk', 'dokumen_penghasilan']);

        $this->assertFalse(
            PengajuanPembiayaan::where('anggota_id', $this->anggota->id)
                ->where('jumlah_pengajuan', 8000000)->exists(),
            'Tanpa dokumen wajib, pengajuan tidak boleh tersimpan.'
        );
    }

    /* =====================================================================
     | N10–N11 — STATUS PENGAJUAN & ANGSURAN
     ===================================================================== */

    public function test_status_pengajuan_menampilkan_riwayat_milik_anggota_saja(): void
    {
        $lain = $this->buatAnggota(null, '22W');

        $milikSendiri = $this->pengajuanMilik($this->anggota, 'PJN-B22-STATUS-'.$this->suffix);
        $milikLain = $this->pengajuanMilik($lain, 'PJN-B22-STATUSLAIN-'.$this->suffix);

        $respon = $this->actingAs($this->user)->get(route('nasabah.status-pengajuan.index'));
        $respon->assertOk();

        $nomor = collect($respon->viewData('pengajuan')->items())->pluck('no_pengajuan')->all();

        $this->assertContains($milikSendiri->no_pengajuan, $nomor);
        $this->assertNotContains($milikLain->no_pengajuan, $nomor);
    }

    public function test_angsuran_index_menampilkan_tagihan_milik_anggota_saja(): void
    {
        $akunId = DB::table('akun')->value('id');

        if ($akunId === null) {
            $this->markTestSkipped('Akun legacy belum tersedia untuk fixture pembiayaan.');
        }

        $lain = $this->buatAnggota(null, '22V');

        $pembiayaanSendiri = $this->buatPembiayaan((int) $this->anggota->id, (int) $akunId, 'PB-B22-ANG');
        $pembiayaanLain = $this->buatPembiayaan((int) $lain->id, (int) $akunId, 'PB-B22-ANGLAIN');

        $this->buatAngsuran((int) $pembiayaanSendiri->id, 1, 'Belum Jatuh Tempo');
        $this->buatAngsuran((int) $pembiayaanLain->id, 1, 'Belum Jatuh Tempo');

        $respon = $this->actingAs($this->user)->get(route('nasabah.angsuran.index'));
        $respon->assertOk();

        $tagihan = $respon->viewData('angsuranTagihan');
        $this->assertCount(1, $tagihan,
            'Hanya tagihan angsuran milik anggota yang login yang boleh tampil.');
        $this->assertSame((int) $pembiayaanSendiri->id, (int) $tagihan->first()->pembiayaan_id);
    }

    private function buatAngsuran(int $pembiayaanId, int $ke, string $status): Angsuran
    {
        return Angsuran::create([
            'pembiayaan_id' => $pembiayaanId,
            'angsuran_ke' => $ke,
            'tanggal_jatuh_tempo' => now()->addMonth()->toDateString(),
            'pokok_angsuran' => 900000,
            'margin_ujroh' => 100000,
            'jumlah_angsuran' => 1000000,
            'sisa_pokok' => 9100000,
            'status_angsuran' => $status,
        ]);
    }
}
