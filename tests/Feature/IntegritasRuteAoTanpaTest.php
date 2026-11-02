<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\DokumenPembiayaan;
use App\Models\DokumenPengajuan;
use App\Models\MasterProdukPembiayaan;
use App\Models\Pembiayaan;
use App\Models\PengajuanPembiayaan;
use App\Models\Rekening;
use App\Models\RestrukturisasiRequest;
use App\Models\User;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BATCH 26 — RUTE `ao.*` YANG BELUM PERNAH DIRUJUK TEST (11 rute).
 *
 *   A1  ao.anggota.search            GET    /ao/anggota/search
 *   A2  ao.anggota.finansial         GET    /ao/anggota/{id}/finansial
 *   A3  ao.pengajuan.create          GET    /ao/pengajuan/create
 *   A4  ao.pengajuan.store           POST   /ao/pengajuan
 *   A5  ao.pengajuan.show            GET    /ao/pengajuan/{id}
 *   A6  ao.pengajuan.dokumen.upload  POST   /ao/pengajuan/{id}/dokumen
 *   A7  ao.pengajuan.dokumen.hapus   DELETE /ao/pengajuan/{id}/dokumen/{dokumenId}
 *   A8  ao.restrukturisasi.store     POST   /ao/restrukturisasi
 *   A9  ao.simulasi.index            GET    /ao/simulasi
 *   A10 ao.survei.store              POST   /ao/survei/{id}
 *   A11 ao.tunggakan.catatan         POST   /ao/tunggakan/{id}/catatan
 *
 * Fokus integritas:
 *  - Berkas survei pada `pengajuanStore` WAJIB tertaut ke PENGAJUAN
 *    (`dokumen_pengajuan_pembiayaan`), bukan ke tabel `pembiayaan` (FK salah tabel).
 *  - Pencarian anggota TIDAK boleh mengembalikan NIK penuh (UU PDP 27/2022);
 *    pencarian NIK tetap bekerja lewat blind index `nik_hash`.
 *  - Snapshot restrukturisasi memakai sisa piutang berjalan (Fatwa DSN-MUI 49).
 *  - Simulasi flat membebankan selisih pembulatan di angsuran terakhir sehingga
 *    Σ pokok tepat sama dengan plafon dan sisa pokok bulan terakhir = 0.
 *  - Pagar RBAC menolak peran tanpa izin `ao.pembiayaan` / `ao.cif`.
 */
class IntegritasRuteAoTanpaTest extends TestCase
{
    use DatabaseTransactions;

    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('dokumen');

        foreach (['anggota', 'pembiayaan', 'angsuran', 'rekening', 'akun',
            'pengajuan_pembiayaan', 'dokumen_pengajuan_pembiayaan',
            'dokumen_pembiayaan', 'master_produk_pembiayaan',
            'restrukturisasi_requests', 'catatan_penagihan'] as $tabel) {
            if (! Schema::hasTable($tabel)) {
                $this->markTestSkipped("Tabel {$tabel} belum tersedia pada basis data uji.");
            }
        }

        if (Akun::query()->count() === 0) {
            $this->markTestSkipped('Tabel `akun` kosong — jalankan AkunSeeder.');
        }

        if (! Akun::where('kode_akun', '121')->exists()) {
            $this->markTestSkipped('Akun piutang murabahah (121) belum tersedia.');
        }

        $this->suffix = strtoupper(substr(uniqid(), -6));
        Setting::flush();
    }

    protected function tearDown(): void
    {
        Setting::flush();

        parent::tearDown();
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    private function user(string $role, string $penanda): User
    {
        return User::firstOrCreate(
            ['email' => strtolower($role).'.'.$penanda.'.b26.'.strtolower($this->suffix).'@test.local'],
            [
                'name' => 'B26 '.$role.' '.$penanda,
                'password' => Hash::make('password'),
                'role' => $role,
                'is_active' => true,
            ]
        );
    }

    private function ao(string $penanda = 'petugas'): User
    {
        return $this->user('ao', $penanda);
    }

    private function anggota(string $kode = 'A', string $status = Anggota::STATUS_ACTIVE): Anggota
    {
        return Anggota::create([
            'no_anggota' => $kode.'-'.$this->suffix,
            'no_cif' => 'CIF-B26-'.$this->suffix.'-'.$kode,
            'nik' => '3275'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota AO '.$kode.' '.$this->suffix,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji Batch 26 No. '.$kode,
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'no_hp' => '0813'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status_anggota' => $status,
            'tanggal_gabung' => now()->subYear()->toDateString(),
        ]);
    }

    private function akunLegacyId(): int
    {
        return (int) Akun::query()->value('id');
    }

    private function rekening(Anggota $anggota, float $saldo = 0): Rekening
    {
        return Rekening::create([
            'no_rekening' => 'RKN-B26-'.$this->suffix.'-'.strtoupper(substr(uniqid(), -5)),
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunLegacyId(),
            'jenis_rekening' => 'Simpanan Sukarela',
            'akad' => 'Wadiah',
            'saldo' => $saldo,
            'batas_minimum' => 0,
            'tanggal_buka' => now()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);
    }

    private function produk(string $akad = 'Murabahah', float $margin = 12.0): MasterProdukPembiayaan
    {
        return MasterProdukPembiayaan::create([
            'kode_produk' => 'B26'.strtoupper(substr(uniqid(), -5)),
            'nama_produk' => 'Produk B26 '.$akad.' '.$this->suffix,
            'akad_syariah' => $akad,
            'min_plafon' => 1000000,
            'max_plafon' => 50000000,
            'margin_persen' => $margin,
            'biaya_admin' => 0,
            'min_bulan_anggota' => 0,
            'min_tenor_bulan' => 1,
            'max_tenor_bulan' => 60,
            'status_aktif' => true,
        ]);
    }

    /** Pembiayaan murabahah 10 tenor × Rp 120.000 (pokok 100.000 + margin 20.000). */
    private function pembiayaan(Anggota $anggota, string $status = Pembiayaan::STATUS_DICAIRKAN): Pembiayaan
    {
        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => 'PMB-B26-'.$this->suffix.'-'.strtoupper(substr(uniqid(), -4)),
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunLegacyId(),
            'akad_pembiayaan' => 'Murabahah',
            'tujuan_pembiayaan' => 'Modal Usaha',
            'jumlah_pembiayaan' => 1000000.0,
            'total_pembiayaan' => 1200000.0,
            'total_ujroh' => 200000.0,
            'sisa_pembiayaan' => 1200000.0,
            'tenor' => 10,
            'jumlah_angsuran' => 120000.0,
            'margin_deferred_amount' => 200000.0,
            'tanggal_akad' => now()->subMonths(6)->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths(10)->toDateString(),
            'status_pembiayaan' => $status,
        ]);

        for ($ke = 1; $ke <= 10; $ke++) {
            Angsuran::create([
                'pembiayaan_id' => $pembiayaan->id,
                'angsuran_ke' => $ke,
                'tanggal_jatuh_tempo' => now()->addMonths($ke)->toDateString(),
                'pokok_angsuran' => 100000.0,
                'margin_ujroh' => 20000.0,
                'jumlah_angsuran' => 120000.0,
                'sisa_pokok' => 0.0,
                'status_angsuran' => 'Belum Jatuh Tempo',
            ]);
        }

        return $pembiayaan;
    }

    /** Tandai angsuran pertama terlambat `$hari` hari (belum dibayar). */
    private function tandaiTerlambat(Pembiayaan $pembiayaan, int $hari): void
    {
        Angsuran::where('pembiayaan_id', $pembiayaan->id)
            ->where('angsuran_ke', 1)
            ->update([
                'tanggal_jatuh_tempo' => now()->subDays($hari)->toDateString(),
                'status_angsuran' => 'Terlambat',
            ]);
    }

    private function pengajuan(Anggota $anggota, ?MasterProdukPembiayaan $produk = null, ?int $aoId = null, ?Pembiayaan $pembiayaan = null, string $status = PengajuanPembiayaan::STATUS_MENUNGGU): PengajuanPembiayaan
    {
        $plafon = 1200000.0;
        $margin = 12.0;

        return PengajuanPembiayaan::create([
            'no_pengajuan' => 'PGJ-B26-'.$this->suffix.'-'.strtoupper(substr(uniqid(), -4)),
            'anggota_id' => $anggota->id,
            'produk_id' => $produk?->id,
            'jenis_akad' => 'Murabahah',
            'margin_persen' => $margin,
            'jumlah_pengajuan' => $plafon,
            'total_pembiayaan' => $plafon + ($plafon * $margin / 100),
            'angsuran_per_bulan' => ($plafon + ($plafon * $margin / 100)) / 12,
            'tenor' => 12,
            'tujuan' => 'Modal Usaha',
            'status' => $status,
            'ao_id' => $aoId,
            'created_by' => $aoId,
            'diproses_oleh' => $aoId,
            'pembiayaan_id' => $pembiayaan?->id,
        ]);
    }

    /* =====================================================================
     | A1 — PENCARIAN ANGGOTA
     |===================================================================== */

    public function test_pencarian_anggota_menemukan_lewat_nama_dan_blind_index_nik(): void
    {
        $ao = $this->ao();
        $anggota = $this->anggota('A');

        $this->actingAs($ao)
            ->getJson(route('ao.anggota.search', ['q' => $anggota->nama_lengkap]))
            ->assertOk()
            ->assertJsonPath('results.0.id', $anggota->id)
            ->assertJsonPath('results.0.no_cif', $anggota->no_cif);

        // Pencarian NIK tetap berfungsi walau kolom `nik` terenkripsi (blind index).
        $this->actingAs($ao)
            ->getJson(route('ao.anggota.search', ['q' => $anggota->nik]))
            ->assertOk()
            ->assertJsonPath('results.0.id', $anggota->id);
    }

    public function test_pencarian_anggota_tidak_membocorkan_nik_penuh(): void
    {
        $ao = $this->ao();
        $anggota = $this->anggota('B');

        $respons = $this->actingAs($ao)
            ->getJson(route('ao.anggota.search', ['q' => $anggota->nama_lengkap]));

        $respons->assertOk();

        $this->assertStringNotContainsString(
            $anggota->nik,
            $respons->getContent(),
            'Respons pencarian tidak boleh memuat NIK penuh (UU PDP 27/2022).'
        );
    }

    /* =====================================================================
     | A2 — INFO FINANSIAL ANGGOTA
     |===================================================================== */

    public function test_info_finansial_anggota_menghitung_simpanan_sukarela(): void
    {
        $ao = $this->ao();
        $anggota = $this->anggota('C');
        $rekening = $this->rekening($anggota, 250000);

        $this->actingAs($ao)
            ->getJson(route('ao.anggota.finansial', $anggota->id))
            ->assertOk()
            ->assertJsonPath('nama_lengkap', $anggota->nama_lengkap)
            ->assertJsonPath('no_anggota', $anggota->no_anggota)
            ->assertJsonPath('punya_sukarela', true)
            ->assertJsonPath('no_rekening_sukarela', $rekening->no_rekening)
            ->assertJsonPath('punya_deposito', false);

        $respons = $this->actingAs($ao)->getJson(route('ao.anggota.finansial', $anggota->id));
        $this->assertEqualsWithDelta(250000.0, (float) $respons->json('total_simpanan'), 0.01);
    }

    /* =====================================================================
     | A3 & A4 — FORM & SIMPAN PENGAJUAN
     |===================================================================== */

    public function test_form_pengajuan_dapat_dibuka(): void
    {
        $ao = $this->ao();
        $this->anggota('D');
        $this->produk();

        $this->actingAs($ao)
            ->get(route('ao.pengajuan.create'))
            ->assertOk()
            ->assertViewIs('ao.pengajuan.create');
    }

    public function test_simpan_pengajuan_menghitung_margin_dan_angsuran(): void
    {
        $ao = $this->ao();
        $anggota = $this->anggota('E');
        $produk = $this->produk('Murabahah', 12.0);

        $plafon = max(1200000.0, Setting::minimalPengajuanPembiayaan());
        $tenor = 12;
        $totalPembiayaan = $plafon + ($plafon * 0.12);

        $this->actingAs($ao)
            ->post(route('ao.pengajuan.store'), [
                'anggota_id' => $anggota->id,
                'produk_id' => $produk->id,
                'jenis_akad' => 'Murabahah',
                'jumlah_pengajuan' => $plafon,
                'tenor' => $tenor,
                'tujuan' => 'Modal Usaha',
                'sumber_pengajuan' => 'Direct/AO',
            ])
            ->assertRedirect(route('ao.pengajuan.index'))
            ->assertSessionHas('success');

        $item = PengajuanPembiayaan::where('anggota_id', $anggota->id)->latest('id')->first();

        $this->assertNotNull($item, 'Pengajuan wajib tersimpan.');
        $this->assertSame(PengajuanPembiayaan::STATUS_MENUNGGU, $item->status);
        $this->assertSame($ao->id, (int) $item->ao_id);
        $this->assertSame($ao->id, (int) $item->created_by);
        $this->assertEqualsWithDelta(12.0, (float) $item->margin_persen, 0.01);
        $this->assertEqualsWithDelta($totalPembiayaan, (float) $item->total_pembiayaan, 0.01);
        $this->assertEqualsWithDelta($totalPembiayaan / $tenor, (float) $item->angsuran_per_bulan, 0.01);
    }

    public function test_berkas_survei_pengajuan_tertaut_ke_pengajuan_bukan_pembiayaan(): void
    {
        $ao = $this->ao();
        $anggota = $this->anggota('F');
        $produk = $this->produk();
        $jumlahDokumenPembiayaanAwal = DokumenPembiayaan::query()->count();

        $this->actingAs($ao)
            ->post(route('ao.pengajuan.store'), [
                'anggota_id' => $anggota->id,
                'produk_id' => $produk->id,
                'jenis_akad' => 'Murabahah',
                'jumlah_pengajuan' => 1500000,
                'tenor' => 12,
                'tujuan' => 'Modal Usaha',
                'berkas_survei' => UploadedFile::fake()->create('survei-agunan.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect(route('ao.pengajuan.index'))
            ->assertSessionHas('success');

        $item = PengajuanPembiayaan::where('anggota_id', $anggota->id)->latest('id')->firstOrFail();

        $dokumen = DokumenPengajuan::where('pengajuan_id', $item->id)->first();
        $this->assertNotNull(
            $dokumen,
            'Berkas survei wajib tertaut ke pengajuan (tabel dokumen_pengajuan_pembiayaan).'
        );
        $this->assertSame('survei', $dokumen->jenis_dokumen);
        $this->assertSame('survei-agunan.pdf', $dokumen->nama_file);
        $this->assertSame($ao->id, (int) $dokumen->diupload_oleh);
        Storage::disk('dokumen')->assertExists($dokumen->path_file);

        $this->assertSame(
            $jumlahDokumenPembiayaanAwal,
            DokumenPembiayaan::query()->count(),
            'Berkas pengajuan tidak boleh ditulis ke tabel dokumen_pembiayaan (FK ke pembiayaan).'
        );
    }

    /* =====================================================================
     | A5, A6, A7 — DETAIL & DOKUMEN PENGAJUAN
     |===================================================================== */

    public function test_detail_pengajuan_menampilkan_nomor_pengajuan(): void
    {
        $ao = $this->ao();
        $anggota = $this->anggota('G');
        $item = $this->pengajuan($anggota, $this->produk(), $ao->id);

        $this->actingAs($ao)
            ->get(route('ao.pengajuan.show', $item->id))
            ->assertOk()
            ->assertViewIs('ao.pengajuan.show')
            ->assertSee($item->no_pengajuan);
    }

    public function test_unggah_dokumen_pengajuan_tersimpan_di_disk_dokumen(): void
    {
        $ao = $this->ao();
        $anggota = $this->anggota('H');
        $item = $this->pengajuan($anggota, $this->produk(), $ao->id);

        $this->actingAs($ao)
            ->from(route('ao.pengajuan.show', $item->id))
            ->post(route('ao.pengajuan.dokumen.upload', $item->id), [
                'jenis_dokumen' => 'KTP',
                'file_dokumen' => UploadedFile::fake()->create('ktp-anggota.pdf', 80, 'application/pdf'),
            ])
            ->assertRedirect(route('ao.pengajuan.show', $item->id))
            ->assertSessionHas('success');

        $dokumen = DokumenPengajuan::where('pengajuan_id', $item->id)->firstOrFail();
        $this->assertSame('KTP', $dokumen->jenis_dokumen);
        $this->assertSame('ktp-anggota.pdf', $dokumen->nama_file);
        Storage::disk('dokumen')->assertExists($dokumen->path_file);
    }

    public function test_hapus_dokumen_pengajuan_menghapus_baris_dan_berkas(): void
    {
        $ao = $this->ao();
        $anggota = $this->anggota('I');
        $item = $this->pengajuan($anggota, $this->produk(), $ao->id);

        $path = 'dokumen-pengajuan/'.$item->id.'/uji-hapus.pdf';
        Storage::disk('dokumen')->put($path, 'isi');

        $dokumen = DokumenPengajuan::create([
            'pengajuan_id' => $item->id,
            'jenis_dokumen' => 'KTP',
            'nama_file' => 'uji-hapus.pdf',
            'path_file' => $path,
            'ukuran_file' => 3,
            'diupload_oleh' => $ao->id,
        ]);

        $this->actingAs($ao)
            ->from(route('ao.pengajuan.show', $item->id))
            ->delete(route('ao.pengajuan.dokumen.hapus', [$item->id, $dokumen->id]))
            ->assertRedirect(route('ao.pengajuan.show', $item->id))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('dokumen_pengajuan_pembiayaan', ['id' => $dokumen->id]);
        Storage::disk('dokumen')->assertMissing($path);
    }

    /* =====================================================================
     | A8 — SIMPAN PENGAJUAN RESTRUKTURISASI
     |===================================================================== */

    public function test_simpan_restrukturisasi_mencatat_snapshot_sisa_piutang(): void
    {
        $ao = $this->ao();
        $anggota = $this->anggota('J');
        $pembiayaan = $this->pembiayaan($anggota);
        $this->tandaiTerlambat($pembiayaan, 120);
        $this->pengajuan($anggota, $this->produk(), $ao->id, $pembiayaan, PengajuanPembiayaan::STATUS_DISETUJUI);

        $this->actingAs($ao)
            ->post(route('ao.restrukturisasi.store'), [
                'pembiayaan_id' => $pembiayaan->id,
                'skema_restrukturisasi' => RestrukturisasiRequest::SKEMA_RESCHEDULING,
                'tenor_baru' => 12,
                'alasan_restrukturisasi' => 'Penurunan pendapatan usaha',
                'kemampuan_bayar_baru' => 240000,
            ])
            ->assertRedirect(route('ao.restrukturisasi.index'))
            ->assertSessionHas('success');

        $permintaan = RestrukturisasiRequest::where('pembiayaan_id', $pembiayaan->id)->firstOrFail();

        $this->assertSame(RestrukturisasiRequest::STATUS_PENDING_APPROVAL, $permintaan->status);
        $this->assertSame($ao->id, (int) $permintaan->ao_id);
        $this->assertEqualsWithDelta(1000000.0, (float) $permintaan->sisa_pokok_snapshot, 0.01);
        $this->assertEqualsWithDelta(200000.0, (float) $permintaan->sisa_margin_snapshot, 0.01);
        $this->assertEqualsWithDelta(1200000.0, (float) $permintaan->sisa_piutang_snapshot, 0.01);
        $this->assertEqualsWithDelta(120000.0, (float) $permintaan->angsuran_lama_snapshot, 0.01);
    }

    /* =====================================================================
     | A9 — SIMULASI
     |===================================================================== */

    public function test_simulasi_flat_membebankan_selisih_pembulatan_di_angsuran_terakhir(): void
    {
        $ao = $this->ao();

        $respons = $this->actingAs($ao)->get(route('ao.simulasi.index', [
            'jumlah' => 10000000,
            'margin' => 12,
            'tenor' => 12,
            'metode' => 'flat',
            'akad' => 'Murabahah',
        ]));

        $respons->assertOk()->assertViewIs('ao.simulasi.index');

        $hasil = $respons->viewData('hasil');
        $this->assertIsArray($hasil);
        $this->assertSame('flat', $hasil['metode']);
        $this->assertCount(12, $hasil['jadwal']);
        $this->assertEqualsWithDelta(1200000.0, (float) $hasil['totalMargin'], 0.01);

        $jadwal = $hasil['jadwal'];
        $terakhir = $jadwal[count($jadwal) - 1];

        $this->assertEqualsWithDelta(0.0, (float) $terakhir['sisaPokok'], 0.01);
        $this->assertEqualsWithDelta(10000000.0, (float) array_sum(array_column($jadwal, 'pokok')), 0.01);
    }

    /* =====================================================================
     | A10 — SIMPAN HASIL SURVEI 5C
     |===================================================================== */

    public function test_survei_teruskan_mengubah_status_dan_membuat_pembiayaan_pending_manajer(): void
    {
        $ao = $this->ao();
        $anggota = $this->anggota('K');
        $item = $this->pengajuan($anggota, $this->produk(), $ao->id);

        $this->actingAs($ao)
            ->from(route('ao.survei.index'))
            ->post(route('ao.survei.store', $item->id), [
                'character' => 5,
                'capacity' => 5,
                'capital' => 4,
                'collateral' => 4,
                'condition' => 4,
                'rekomendasi' => 'teruskan',
                'catatan_survei' => 'Usaha berjalan stabil.',
            ])
            ->assertRedirect(route('ao.survei.index'))
            ->assertSessionHas('success');

        $item->refresh();

        $this->assertSame(PengajuanPembiayaan::STATUS_DIREVIEW, $item->status);
        $this->assertSame(22, (int) $item->analisis_5c['skor_total']);
        $this->assertNotNull($item->pembiayaan_id, 'Survei yang diteruskan wajib menautkan pembiayaan.');

        $pembiayaan = Pembiayaan::findOrFail($item->pembiayaan_id);
        $this->assertSame(Pembiayaan::STATUS_PENDING_MANAJER, $pembiayaan->status_pembiayaan);
        $this->assertSame($ao->id, (int) $pembiayaan->direview_oleh);
    }

    /* =====================================================================
     | A11 — CATATAN PENAGIHAN
     |===================================================================== */

    public function test_catatan_penagihan_tersimpan_sebagai_riwayat(): void
    {
        $ao = $this->ao();
        $anggota = $this->anggota('L');
        $pembiayaan = $this->pembiayaan($anggota);
        $angsuran = Angsuran::where('pembiayaan_id', $pembiayaan->id)->orderBy('angsuran_ke')->firstOrFail();

        $this->actingAs($ao)
            ->from(route('ao.tunggakan.index'))
            ->post(route('ao.tunggakan.catatan', $angsuran->id), [
                'catatan' => 'Dihubungi, janji bayar minggu depan.',
            ])
            ->assertRedirect(route('ao.tunggakan.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('catatan_penagihan', [
            'angsuran_id' => $angsuran->id,
            'user_id' => $ao->id,
            'catatan' => 'Dihubungi, janji bayar minggu depan.',
        ]);

        $this->assertSame('Dihubungi, janji bayar minggu depan.', $angsuran->fresh()->catatan);
    }

    /* =====================================================================
     | PAGAR RBAC
     |===================================================================== */

    public function test_rute_ao_menolak_peran_tanpa_izin(): void
    {
        $rute = [
            ['GET', route('ao.anggota.search')],
            ['GET', route('ao.anggota.finansial', 1)],
            ['GET', route('ao.pengajuan.create')],
            ['POST', route('ao.pengajuan.store')],
            ['GET', route('ao.pengajuan.show', 1)],
            ['POST', route('ao.pengajuan.dokumen.upload', 1)],
            ['DELETE', route('ao.pengajuan.dokumen.hapus', [1, 1])],
            ['POST', route('ao.restrukturisasi.store')],
            ['GET', route('ao.simulasi.index')],
            ['POST', route('ao.survei.store', 1)],
            ['POST', route('ao.tunggakan.catatan', 1)],
        ];

        $this->assertCount(11, $rute, 'Batch 26 harus mencakup tepat 11 rute.');

        foreach (['teller', 'manajer', 'pengurus', 'dps'] as $peran) {
            $pengguna = $this->user($peran, 'rbac');

            foreach ($rute as [$metode, $uri]) {
                $this->actingAs($pengguna)->call($metode, $uri)->assertForbidden();
            }
        }
    }
}
