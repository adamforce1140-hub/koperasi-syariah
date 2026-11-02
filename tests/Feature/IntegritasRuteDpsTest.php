<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\AuditLog;
use App\Models\DpsOpiniCatatan;
use App\Models\MasterProdukPembiayaan;
use App\Models\Pembiayaan;
use App\Models\User;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BATCH 27 — RUTE `dps.*` YANG BELUM PERNAH DIRUJUK TEST (6 rute).
 *
 *   D1 dps.opini-catatan              GET    /dps/opini-catatan
 *   D2 dps.opini-catatan.store        POST   /dps/opini-catatan
 *   D3 dps.pengesahan-akad.detail     GET    /dps/pengesahan-akad/{id}/detail
 *   D4 dps.pengesahan-akad.approve    POST   /dps/pengesahan-akad/{id}/approve
 *   D5 dps.pengesahan-akad.reject     POST   /dps/pengesahan-akad/{id}/reject
 *   D6 dps.review-produk.approve      POST   /dps/review-produk/{id}/approve
 *
 * Fokus integritas:
 *  - Opini & catatan DPS tersimpan sebagai arsip permanen; lampiran masuk disk
 *    `dokumen` dan default kategori/status kepatuhan terisi.
 *  - Pengesahan akad HANYA untuk pembiayaan `pending_dps`; status lain ditolak
 *    404 agar DPS tidak bisa mengubah pembiayaan yang belum sampai ke meja DPS.
 *  - Approve wajib `tgl_pencairan`; reject wajib `catatan_dps` (jejak audit).
 *  - Sertifikasi produk idempoten: sertifikasi ulang tidak menduplikasi audit log.
 *  - Pagar RBAC: hanya peran `dps` (izin `dps.pengawasan`) yang boleh masuk.
 */
class IntegritasRuteDpsTest extends TestCase
{
    use DatabaseTransactions;

    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('dokumen');

        foreach (['users', 'anggota', 'akun', 'pembiayaan', 'master_produk_pembiayaan',
            'dps_opini_catatan', 'audit_logs'] as $tabel) {
            if (! Schema::hasTable($tabel)) {
                $this->markTestSkipped("Tabel {$tabel} belum tersedia pada basis data uji.");
            }
        }

        if (Akun::query()->count() === 0) {
            $this->markTestSkipped('Tabel `akun` kosong — jalankan AkunSeeder.');
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
            ['email' => strtolower($role).'.'.$penanda.'.b27.'.strtolower($this->suffix).'@test.local'],
            [
                'name' => 'B27 '.$role.' '.$penanda,
                'password' => Hash::make('password'),
                'role' => $role,
                'is_active' => true,
            ]
        );
    }

    private function dps(string $penanda = 'dewan'): User
    {
        return $this->user('dps', $penanda);
    }

    private function anggota(string $kode = 'A'): Anggota
    {
        return Anggota::create([
            'no_anggota' => $kode.'-'.$this->suffix,
            'no_cif' => 'CIF-B27-'.$this->suffix.'-'.$kode,
            'nik' => '3275'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota DPS '.$kode.' '.$this->suffix,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji Batch 27 No. '.$kode,
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'no_hp' => '0813'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status_anggota' => Anggota::STATUS_ACTIVE,
            'tanggal_gabung' => now()->subYear()->toDateString(),
        ]);
    }

    private function akunLegacyId(): int
    {
        return (int) Akun::query()->value('id');
    }

    private function produk(string $akad = 'Murabahah'): MasterProdukPembiayaan
    {
        return MasterProdukPembiayaan::create([
            'kode_produk' => 'B27'.strtoupper(substr(uniqid(), -5)),
            'nama_produk' => 'Produk B27 '.$akad.' '.$this->suffix,
            'akad_syariah' => $akad,
            'min_plafon' => 1000000,
            'max_plafon' => 50000000,
            'margin_persen' => 12.0,
            'biaya_admin' => 0,
            'min_bulan_anggota' => 0,
            'min_tenor_bulan' => 1,
            'max_tenor_bulan' => 60,
            'status_aktif' => true,
            'status_sertifikasi_dps' => MasterProdukPembiayaan::SERTIFIKASI_BELUM,
        ]);
    }

    private function pembiayaan(Anggota $anggota, string $status): Pembiayaan
    {
        return Pembiayaan::create([
            'no_pembiayaan' => 'PMB-B27-'.$this->suffix.'-'.strtoupper(substr(uniqid(), -4)),
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
    }

    /* =====================================================================
     | D1 — HALAMAN OPINI & CATATAN
     |===================================================================== */

    public function test_halaman_opini_catatan_menampilkan_arsip(): void
    {
        $dps = $this->dps();

        $opini = DpsOpiniCatatan::create([
            'user_id' => $dps->id,
            'judul' => 'Temuan Akad Murabahah '.$this->suffix,
            'kategori' => 'opini_tahunan',
            'periode' => '2026-Q3',
            'status_kepatuhan' => 'patuh',
            'isi' => 'Akad murabahah telah sesuai Fatwa DSN-MUI No. 04/DSN-MUI/IV/2000.',
        ]);

        $respons = $this->actingAs($dps)->get(route('dps.opini-catatan'));

        $respons->assertOk()->assertViewIs('dps.opini-catatan');
        $respons->assertSee($opini->judul);
    }

    /* =====================================================================
     | D2 — SIMPAN OPINI & CATATAN
     |===================================================================== */

    public function test_simpan_opini_catatan_mengisi_default_dan_menyimpan_lampiran(): void
    {
        $dps = $this->dps();
        $judul = 'Catatan Pengawasan '.$this->suffix;

        $respons = $this->actingAs($dps)
            ->from(route('dps.opini-catatan'))
            ->post(route('dps.opini-catatan.store'), [
                'judul' => $judul,
                'isi' => 'Tidak ditemukan pelanggaran prinsip syariah pada sampel akad.',
                'lampiran' => UploadedFile::fake()->create('catatan-dps.pdf', 120, 'application/pdf'),
            ]);

        $respons->assertRedirect(route('dps.opini-catatan'));
        $respons->assertSessionHas('success');

        $opini = DpsOpiniCatatan::where('judul', $judul)->firstOrFail();

        $this->assertSame($dps->id, $opini->user_id);
        $this->assertSame('opini_tahunan', $opini->kategori, 'Kategori default harus opini_tahunan.');
        $this->assertSame('patuh', $opini->status_kepatuhan, 'Status kepatuhan default harus patuh.');
        $this->assertNotNull($opini->lampiran);
        Storage::disk('dokumen')->assertExists($opini->lampiran);
    }

    public function test_simpan_opini_catatan_mewajibkan_judul_dan_isi(): void
    {
        $dps = $this->dps();

        $respons = $this->actingAs($dps)
            ->from(route('dps.opini-catatan'))
            ->post(route('dps.opini-catatan.store'), []);

        $respons->assertSessionHasErrors(['judul', 'isi']);
        $this->assertSame(0, DpsOpiniCatatan::where('user_id', $dps->id)->count());
    }

    /* =====================================================================
     | D3 — ANTRIAN & DETAIL PENGESAHAN AKAD
     |===================================================================== */

    public function test_pengesahan_akad_hanya_menampilkan_pembiayaan_pending_dps(): void
    {
        $dps = $this->dps();
        $anggota = $this->anggota();

        $pending = $this->pembiayaan($anggota, Pembiayaan::STATUS_PENDING_DPS);
        $disetujui = $this->pembiayaan($anggota, Pembiayaan::STATUS_DISETUJUI);

        $respons = $this->actingAs($dps)
            ->get(route('dps.pengesahan-akad', ['search' => $pending->no_pembiayaan]));

        $respons->assertOk()->assertViewIs('dps.pengesahan-akad');
        $respons->assertSee($pending->no_pembiayaan);
        $respons->assertDontSee($disetujui->no_pembiayaan);
    }

    public function test_detail_pengesahan_akad_mengembalikan_json(): void
    {
        $dps = $this->dps();
        $anggota = $this->anggota();
        $pb = $this->pembiayaan($anggota, Pembiayaan::STATUS_PENDING_DPS);

        $respons = $this->actingAs($dps)
            ->get(route('dps.pengesahan-akad.detail', $pb->id));

        $respons->assertOk();
        $respons->assertJsonPath('id', $pb->id);
        $respons->assertJsonPath('no_pembiayaan', $pb->no_pembiayaan);
        $respons->assertJsonPath('akad_pembiayaan', 'Murabahah');
        $respons->assertJsonPath('anggota.nama_lengkap', $anggota->nama_lengkap);
        $respons->assertJsonPath('anggota.no_anggota', $anggota->no_anggota);
    }

    public function test_detail_pengesahan_akad_menolak_status_bukan_pending_dps(): void
    {
        $dps = $this->dps();
        $pb = $this->pembiayaan($this->anggota(), Pembiayaan::STATUS_DISETUJUI);

        $this->actingAs($dps)
            ->get(route('dps.pengesahan-akad.detail', $pb->id))
            ->assertNotFound();
    }

    /* =====================================================================
     | D4 — APPROVE PENGESAHAN AKAD
     |===================================================================== */

    public function test_approve_pengesahan_akad_mewajibkan_tanggal_pencairan(): void
    {
        $dps = $this->dps();
        $pb = $this->pembiayaan($this->anggota(), Pembiayaan::STATUS_PENDING_DPS);

        $respons = $this->actingAs($dps)
            ->from(route('dps.pengesahan-akad'))
            ->post(route('dps.pengesahan-akad.approve', $pb->id), []);

        $respons->assertSessionHasErrors('tgl_pencairan');
        $this->assertSame(Pembiayaan::STATUS_PENDING_DPS, $pb->fresh()->status_pembiayaan);
    }

    public function test_approve_pengesahan_akad_mencatat_pengesahan_dps(): void
    {
        $dps = $this->dps();
        $pb = $this->pembiayaan($this->anggota(), Pembiayaan::STATUS_PENDING_DPS);
        $tglPencairan = now()->addDays(7)->toDateString();

        $respons = $this->actingAs($dps)
            ->from(route('dps.pengesahan-akad'))
            ->post(route('dps.pengesahan-akad.approve', $pb->id), [
                'tgl_pencairan' => $tglPencairan,
                'catatan_dps' => 'Akad sesuai prinsip syariah.',
            ]);

        $respons->assertRedirect(route('dps.pengesahan-akad'));
        $respons->assertSessionHas('success');

        $pb->refresh();
        $this->assertSame(Pembiayaan::STATUS_DISETUJUI, $pb->status_pembiayaan);
        $this->assertSame($dps->id, $pb->disahkan_oleh);
        $this->assertNotNull($pb->tanggal_disahkan);
        $this->assertSame('Akad sesuai prinsip syariah.', $pb->catatan_dps);
        $this->assertSame($tglPencairan, $pb->tgl_pencairan->toDateString());
    }

    public function test_approve_pengesahan_akad_menolak_pembiayaan_bukan_pending_dps(): void
    {
        $dps = $this->dps();
        $pb = $this->pembiayaan($this->anggota(), Pembiayaan::STATUS_DICAIRKAN);

        $this->actingAs($dps)
            ->from(route('dps.pengesahan-akad'))
            ->post(route('dps.pengesahan-akad.approve', $pb->id), [
                'tgl_pencairan' => now()->addDays(3)->toDateString(),
            ])
            ->assertNotFound();
    }

    /* =====================================================================
     | D5 — REJECT PENGESAHAN AKAD
     |===================================================================== */

    public function test_reject_pengesahan_akad_mewajibkan_alasan(): void
    {
        $dps = $this->dps();
        $pb = $this->pembiayaan($this->anggota(), Pembiayaan::STATUS_PENDING_DPS);

        $respons = $this->actingAs($dps)
            ->from(route('dps.pengesahan-akad'))
            ->post(route('dps.pengesahan-akad.reject', $pb->id), []);

        $respons->assertSessionHasErrors('catatan_dps');
        $this->assertSame(Pembiayaan::STATUS_PENDING_DPS, $pb->fresh()->status_pembiayaan);
    }

    public function test_reject_pengesahan_akad_menolak_dan_menyimpan_alasan(): void
    {
        $dps = $this->dps();
        $pb = $this->pembiayaan($this->anggota(), Pembiayaan::STATUS_PENDING_DPS);

        $respons = $this->actingAs($dps)
            ->from(route('dps.pengesahan-akad'))
            ->post(route('dps.pengesahan-akad.reject', $pb->id), [
                'catatan_dps' => 'Agunan belum memenuhi syarat.',
            ]);

        $respons->assertRedirect(route('dps.pengesahan-akad'));
        $respons->assertSessionHas('error');

        $pb->refresh();
        $this->assertSame(Pembiayaan::STATUS_DITOLAK, $pb->status_pembiayaan);
        $this->assertSame('Agunan belum memenuhi syarat.', $pb->catatan_dps);
        $this->assertSame($dps->id, $pb->disahkan_oleh);
        $this->assertNotNull($pb->tanggal_disahkan);
    }

    /* =====================================================================
     | D6 — SERTIFIKASI PRODUK OLEH DPS
     |===================================================================== */

    public function test_sertifikasi_produk_dps_menyimpan_status_dan_audit_log(): void
    {
        $dps = $this->dps();
        $produk = $this->produk();

        $respons = $this->actingAs($dps)
            ->from(route('dps.review-produk.index'))
            ->post(route('dps.review-produk.approve', $produk->id), [
                'catatan_dps' => 'Akad murabahah sesuai Fatwa DSN-MUI.',
            ]);

        $respons->assertRedirect(route('dps.review-produk.index'));
        $respons->assertSessionHas('success');

        $produk->refresh();
        $this->assertSame(MasterProdukPembiayaan::SERTIFIKASI_VALID, $produk->status_sertifikasi_dps);
        $this->assertSame($dps->id, $produk->disahkan_oleh);
        $this->assertNotNull($produk->tanggal_disahkan);
        $this->assertSame('Akad murabahah sesuai Fatwa DSN-MUI.', $produk->catatan_dps);

        $this->assertDatabaseHas('audit_logs', [
            'jenis_transaksi' => 'SERTIFIKASI_PRODUK_DPS',
            'user_id' => $dps->id,
        ]);
    }

    public function test_sertifikasi_produk_dps_idempoten_tidak_menduplikasi_audit_log(): void
    {
        $dps = $this->dps();
        $produk = $this->produk();

        $this->actingAs($dps)
            ->post(route('dps.review-produk.approve', $produk->id), ['catatan_dps' => 'Sertifikasi pertama.'])
            ->assertSessionHas('success');

        $jumlahAudit = AuditLog::where('jenis_transaksi', 'SERTIFIKASI_PRODUK_DPS')->count();
        $this->assertSame(1, $jumlahAudit);

        $respons = $this->actingAs($dps)
            ->from(route('dps.review-produk.index'))
            ->post(route('dps.review-produk.approve', $produk->id), ['catatan_dps' => 'Sertifikasi ulang.']);

        $respons->assertRedirect(route('dps.review-produk.index'));
        $respons->assertSessionHas('info');

        $this->assertSame($jumlahAudit, AuditLog::where('jenis_transaksi', 'SERTIFIKASI_PRODUK_DPS')->count());
        $this->assertSame('Sertifikasi pertama.', $produk->fresh()->catatan_dps, 'Sertifikasi ulang tidak boleh menimpa catatan.');
    }

    /* =====================================================================
     | PAGAR RBAC
     |===================================================================== */

    public function test_rute_dps_menolak_peran_tanpa_izin(): void
    {
        $rute = [
            ['GET', route('dps.opini-catatan')],
            ['POST', route('dps.opini-catatan.store')],
            ['GET', route('dps.pengesahan-akad.detail', 1)],
            ['POST', route('dps.pengesahan-akad.approve', 1)],
            ['POST', route('dps.pengesahan-akad.reject', 1)],
            ['POST', route('dps.review-produk.approve', 1)],
        ];

        $this->assertCount(6, $rute, 'Batch 27 harus mencakup tepat 6 rute.');

        foreach (['admin', 'manajer', 'pengurus', 'ao', 'teller'] as $peran) {
            $pengguna = $this->user($peran, 'rbac');

            foreach ($rute as [$metode, $uri]) {
                $this->actingAs($pengguna)->call($metode, $uri)->assertForbidden();
            }
        }
    }
}
