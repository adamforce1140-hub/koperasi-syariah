<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\ApprovalTransaksi;
use App\Models\KebijakanKoperasi;
use App\Models\MasterProdukPembiayaan;
use App\Models\PembayaranSimpanan;
use App\Models\Pembiayaan;
use App\Models\PengajuanPembiayaan;
use App\Models\PengajuanRekening;
use App\Models\Transaksi;
use App\Models\User;
use App\Support\PemisahanTugas;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BATCH 28 — RUTE `pengelola.*` YANG BELUM PERNAH DIRUJUK TEST (11 rute).
 *
 *   P1  pengelola.approval.reject                                POST   /pengelola/approval/{approval}/reject
 *   P2  pengelola.pengajuan-rekening.index                       GET    /pengelola/pengajuan-rekening
 *   P3  pengelola.pengajuan-rekening.show                        GET    /pengelola/pengajuan-rekening/{pengajuan}
 *   P4  pengelola.pengajuan-rekening.setujui                     POST   /pengelola/pengajuan-rekening/{pengajuan}/setujui
 *   P5  pengelola.pengajuan-rekening.tolak                       POST   /pengelola/pengajuan-rekening/{pengajuan}/tolak
 *   P6  pengelola.pengajuan-rekening.tolak-pembayaran            POST   /pengelola/pengajuan-rekening/{pengajuan}/tolak-pembayaran
 *   P7  pengelola.pengajuan-pinjaman.show                        GET    /pengelola/pengajuan-pinjaman/{pengajuan}
 *   P8  pengelola.pengajuan-pinjaman.review                      PATCH  /pengelola/pengajuan-pinjaman/{pengajuan}/review
 *   P9  pengelola.pengajuan-pinjaman.tolak                       PATCH  /pengelola/pengajuan-pinjaman/{pengajuan}/tolak
 *   P10 pengelola.produk-pembiayaan.create                       GET    /pengelola/produk-pembiayaan/create
 *   P11 pengelola.produk-pembiayaan.edit                         GET    /pengelola/produk-pembiayaan/{produk}/edit
 *
 * Fokus integritas:
 *  - Penolakan approval WAJIB beralasan dan hanya boleh diputus peran yang
 *    berwenang atas status antrean; pengaju tidak boleh memutus dirinya sendiri.
 *  - Keputusan approval mengubah transaksi referensi menjadi Gagal + alasan,
 *    bukan sekadar menandai baris approval.
 *  - Validasi nominal pembukaan rekening mengikuti kebijakan koperasi
 *    (batas minimum/maksimum), bukan angka tetap di kode.
 *  - Rute review/tolak pengajuan pinjaman memakai binding `Pembiayaan`
 *    sedangkan `show` memakai `PengajuanPembiayaan` pada segmen URI yang sama.
 *  - Pagar RBAC diuji per kelompok izin, bukan hanya satu daftar peran.
 */
class IntegritasRutePengelolaTanpaTest extends TestCase
{
    use DatabaseTransactions;

    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('dokumen');

        foreach (['users', 'anggota', 'akun', 'pembiayaan', 'pengajuan_pembiayaan',
            'dokumen_pengajuan_pembiayaan', 'master_produk_pembiayaan', 'produk_syarat_dokumen',
            'pengajuan_rekening', 'pembayaran_simpanan', 'approval_transaksi',
            'approval_transaksi_logs', 'transaksi'] as $tabel) {
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
            ['email' => strtolower($role).'.'.$penanda.'.b28.'.strtolower($this->suffix).'@test.local'],
            [
                'name' => 'B28 '.$role.' '.$penanda,
                'password' => Hash::make('password'),
                'role' => $role,
                'is_active' => true,
            ]
        );
    }

    private function anggota(string $kode = 'A'): Anggota
    {
        return Anggota::create([
            'no_anggota' => $kode.'-B28-'.$this->suffix,
            'no_cif' => 'CIF-B28-'.$this->suffix.'-'.$kode,
            'nik' => '3275'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Pengelola '.$kode.' '.$this->suffix,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji Batch 28 No. '.$kode,
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
            'kode_produk' => 'B28'.strtoupper(substr(uniqid(), -5)),
            'nama_produk' => 'Produk B28 '.$akad.' '.$this->suffix,
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
            'no_pembiayaan' => 'PMB-B28-'.$this->suffix.'-'.strtoupper(substr(uniqid(), -4)),
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

    private function pengajuanPembiayaan(Anggota $anggota): PengajuanPembiayaan
    {
        return PengajuanPembiayaan::create([
            'no_pengajuan' => 'PJN-B28-'.$this->suffix.'-'.strtoupper(substr(uniqid(), -4)),
            'anggota_id' => $anggota->id,
            'jenis_akad' => 'Murabahah',
            'jumlah_pengajuan' => 5000000.0,
            'tenor' => 12,
            'tujuan' => 'Modal Usaha',
            'agunan' => 'BPKB Motor',
            'status' => PengajuanPembiayaan::STATUS_MENUNGGU,
        ]);
    }

    private function pengajuanRekening(string $status, float $nominal = 50000.0): PengajuanRekening
    {
        return PengajuanRekening::create([
            'kode_pengajuan' => 'PGJ-B28-'.$this->suffix.'-'.strtoupper(substr(uniqid(), -4)),
            'nama_lengkap' => 'Calon Anggota B28 '.$this->suffix,
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'tempat_lahir' => 'Bandung',
            'tanggal_lahir' => '1995-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji Batch 28 No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'no_hp' => '08'.str_pad((string) random_int(1, 9999999999), 10, '0', STR_PAD_LEFT),
            'email' => 'calon.b28.'.strtolower($this->suffix).'.'.strtolower(substr(uniqid(), -4)).'@test.local',
            'pekerjaan' => 'Karyawan',
            'jenis_simpanan' => 'Simpanan Pokok',
            'nominal_simpanan_awal' => $nominal,
            'status' => $status,
        ]);
    }

    private function pembayaran(PengajuanRekening $pengajuan, string $status = 'Menunggu Konfirmasi'): PembayaranSimpanan
    {
        return PembayaranSimpanan::create([
            'pengajuan_id' => $pengajuan->id,
            'nominal' => 50000.0,
            'metode_bayar' => 'Transfer Bank',
            'status' => $status,
        ]);
    }

    private function transaksi(User $pengguna, string $statusTransaksi = Transaksi::STATUS_TRANSAKSI_SUKSES): Transaksi
    {
        return Transaksi::create([
            'no_transaksi' => 'TRX-B28-'.$this->suffix.'-'.strtoupper(substr(uniqid(), -5)),
            'waktu_transaksi' => now(),
            'jenis_transaksi' => 'Setoran Tunai',
            'posisi' => 'Debet',
            'jumlah' => 75000000.0,
            'user_id' => $pengguna->id,
            'status_transaksi' => $statusTransaksi,
            'status' => Transaksi::STATUS_TEKNIS_SUCCESS,
        ]);
    }

    private function approvalTransaksi(string $status, User $pengaju, ?Transaksi $transaksi = null): ApprovalTransaksi
    {
        $transaksi ??= $this->transaksi($pengaju);

        return ApprovalTransaksi::create([
            'kode_approval' => ApprovalTransaksi::generateKode(),
            'tipe_referensi' => 'transaksi',
            'referensi_id' => $transaksi->id,
            'jenis_transaksi' => 'Setoran Tunai',
            'nominal' => 75000000.0,
            'jenis_pembayaran' => 'tunai',
            'status' => $status,
            'diajukan_oleh' => $pengaju->id,
            'role_pengaju' => $pengaju->role,
        ]);
    }

    private function batasNominal(): array
    {
        return KebijakanKoperasi::batasPembukaanRekening();
    }

    /* =====================================================================
     | P1 — TOLAK APPROVAL TRANSAKSI
     |===================================================================== */

    public function test_tolak_approval_mewajibkan_catatan(): void
    {
        $pengurus = $this->user('pengurus', 'penolak');
        $approval = $this->approvalTransaksi('PENDING_PENGURUS', $this->user('teller', 'pengaju'));

        $respons = $this->actingAs($pengurus)
            ->from(route('pengelola.approval.index'))
            ->post(route('pengelola.approval.reject', $approval), []);

        $respons->assertSessionHasErrors('catatan');
        $this->assertSame('PENDING_PENGURUS', $approval->fresh()->status);
    }

    public function test_tolak_approval_menolak_transaksi_dan_mencatat_jejak(): void
    {
        $pengurus = $this->user('pengurus', 'penolak');
        $transaksi = $this->transaksi($this->user('teller', 'pengaju'));
        $approval = $this->approvalTransaksi('PENDING_PENGURUS', $this->user('teller', 'pengaju'), $transaksi);

        $respons = $this->actingAs($pengurus)
            ->from(route('pengelola.approval.index'))
            ->post(route('pengelola.approval.reject', $approval), [
                'catatan' => 'Nominal tidak sesuai dokumen setoran.',
            ]);

        $respons->assertRedirect(route('pengelola.approval.index'));
        $respons->assertSessionHas('success', 'Transaksi berhasil ditolak.');

        $approval->refresh();
        $this->assertSame('REJECTED', $approval->status);
        $this->assertSame($pengurus->id, $approval->disetujui_oleh);
        $this->assertSame('pengurus', $approval->role_approver);
        $this->assertSame('Nominal tidak sesuai dokumen setoran.', $approval->catatan_approver);
        $this->assertNotNull($approval->approved_at);

        $transaksi->refresh();
        $this->assertSame('Gagal', $transaksi->status_transaksi);
        $this->assertSame('rejected', $transaksi->status);
        $this->assertSame('Nominal tidak sesuai dokumen setoran.', $transaksi->alasan_penolakan);
        $this->assertSame($pengurus->id, $transaksi->approved_by);

        $this->assertDatabaseHas('approval_transaksi_logs', [
            'approval_id' => $approval->id,
            'peristiwa' => 'ditolak',
            'user_id' => $pengurus->id,
        ]);
    }

    public function test_tolak_approval_menolak_peran_tidak_berwenang(): void
    {
        $manajer = $this->user('manajer', 'penolak');
        $approval = $this->approvalTransaksi('PENDING_PENGURUS', $this->user('teller', 'pengaju'));

        $respons = $this->actingAs($manajer)
            ->from(route('pengelola.approval.index'))
            ->post(route('pengelola.approval.reject', $approval), ['catatan' => 'Coba tolak lintas peran.']);

        $respons->assertSessionHas('error', 'Anda tidak memiliki hak untuk menolak transaksi ini.');
        $this->assertSame('PENDING_PENGURUS', $approval->fresh()->status);
    }

    public function test_tolak_approval_menolak_pengaju_sendiri(): void
    {
        $pengurus = $this->user('pengurus', 'pengaju');
        $approval = $this->approvalTransaksi('PENDING_PENGURUS', $pengurus);

        $respons = $this->actingAs($pengurus)
            ->from(route('pengelola.approval.index'))
            ->post(route('pengelola.approval.reject', $approval), ['catatan' => 'Menolak pengajuan sendiri.']);

        $respons->assertSessionHas('error', PemisahanTugas::PESAN);
        $this->assertSame('PENDING_PENGURUS', $approval->fresh()->status);
    }

    /* =====================================================================
     | P2 & P3 — DAFTAR & DETAIL PENGAJUAN REKENING
     |===================================================================== */

    public function test_daftar_pengajuan_rekening_menampilkan_hitungan_dan_filter(): void
    {
        $teller = $this->user('teller', 'verifikator');

        $menungguVerifikasi = $this->pengajuanRekening(PengajuanRekening::STATUS_MENUNGGU_VERIFIKASI);
        $menungguKonfirmasi = $this->pengajuanRekening(PengajuanRekening::STATUS_MENUNGGU_KONFIRMASI);

        $respons = $this->actingAs($teller)
            ->get(route('pengelola.pengajuan-rekening.index'));

        $respons->assertOk()->assertViewIs('pengelola.pengajuan-rekening.index');
        $respons->assertSee($menungguVerifikasi->kode_pengajuan);
        $respons->assertSee($menungguKonfirmasi->kode_pengajuan);

        $respons->assertViewHas('counts', function (array $counts) {
            return $counts['total'] >= 2
                && $counts['menunggu_verifikasi'] >= 1
                && $counts['menunggu_konfirmasi'] >= 1;
        });

        $tersaring = $this->actingAs($teller)
            ->get(route('pengelola.pengajuan-rekening.index', ['status' => PengajuanRekening::STATUS_MENUNGGU_VERIFIKASI]));

        $tersaring->assertOk();
        $tersaring->assertViewHas('status', PengajuanRekening::STATUS_MENUNGGU_VERIFIKASI);
        $tersaring->assertSee($menungguVerifikasi->kode_pengajuan);
        $tersaring->assertDontSee($menungguKonfirmasi->kode_pengajuan);
    }

    public function test_detail_pengajuan_rekening_menampilkan_batas_pembukaan(): void
    {
        $teller = $this->user('teller', 'verifikator');
        $pengajuan = $this->pengajuanRekening(PengajuanRekening::STATUS_MENUNGGU_VERIFIKASI);

        $respons = $this->actingAs($teller)
            ->get(route('pengelola.pengajuan-rekening.show', $pengajuan));

        $respons->assertOk()->assertViewIs('pengelola.pengajuan-rekening.show');
        $respons->assertSee($pengajuan->kode_pengajuan);
        $respons->assertViewHas('batasPembukaan', function (array $batas) {
            return array_key_exists('minimum', $batas) && array_key_exists('maksimum', $batas);
        });
    }

    /* =====================================================================
     | P4 — SETUJUI PENGAJUAN REKENING
     |===================================================================== */

    public function test_setujui_pengajuan_rekening_mewajibkan_nominal_dalam_batas(): void
    {
        $teller = $this->user('teller', 'verifikator');
        $pengajuan = $this->pengajuanRekening(PengajuanRekening::STATUS_MENUNGGU_VERIFIKASI);
        $batas = $this->batasNominal();

        $tanpaNominal = $this->actingAs($teller)
            ->from(route('pengelola.pengajuan-rekening.show', $pengajuan))
            ->post(route('pengelola.pengajuan-rekening.setujui', $pengajuan), []);

        $tanpaNominal->assertSessionHasErrors('nominal_simpanan_awal');

        $this->actingAs($teller)
            ->from(route('pengelola.pengajuan-rekening.show', $pengajuan))
            ->post(route('pengelola.pengajuan-rekening.setujui', $pengajuan), [
                'nominal_simpanan_awal' => ((float) $batas['minimum']) - 1,
            ])
            ->assertSessionHasErrors('nominal_simpanan_awal');

        $this->assertSame(
            PengajuanRekening::STATUS_MENUNGGU_VERIFIKASI,
            $pengajuan->fresh()->status,
            'Nominal di luar batas kebijakan tidak boleh mengubah status pengajuan.'
        );
    }

    public function test_setujui_pengajuan_rekening_mengubah_status_ke_menunggu_pembayaran(): void
    {
        $teller = $this->user('teller', 'verifikator');
        $pengajuan = $this->pengajuanRekening(PengajuanRekening::STATUS_MENUNGGU_VERIFIKASI);
        $nominal = (float) $this->batasNominal()['minimum'];

        $respons = $this->actingAs($teller)
            ->from(route('pengelola.pengajuan-rekening.show', $pengajuan))
            ->post(route('pengelola.pengajuan-rekening.setujui', $pengajuan), [
                'nominal_simpanan_awal' => $nominal,
            ]);

        $respons->assertRedirect(route('pengelola.pengajuan-rekening.show', $pengajuan));
        $respons->assertSessionHas('success');

        $pengajuan->refresh();
        $this->assertSame(PengajuanRekening::STATUS_MENUNGGU_PEMBAYARAN, $pengajuan->status);
        $this->assertSame($teller->id, $pengajuan->diverifikasi_oleh);
        $this->assertNotNull($pengajuan->waktu_verifikasi);
        $this->assertSame(number_format($nominal, 2, '.', ''), (string) $pengajuan->nominal_simpanan_awal);
    }

    /* =====================================================================
     | P5 — TOLAK PENGAJUAN REKENING
     |===================================================================== */

    public function test_tolak_pengajuan_rekening_mewajibkan_alasan(): void
    {
        $teller = $this->user('teller', 'verifikator');
        $pengajuan = $this->pengajuanRekening(PengajuanRekening::STATUS_MENUNGGU_VERIFIKASI);

        $respons = $this->actingAs($teller)
            ->from(route('pengelola.pengajuan-rekening.show', $pengajuan))
            ->post(route('pengelola.pengajuan-rekening.tolak', $pengajuan), []);

        $respons->assertSessionHasErrors('alasan_tolak');
        $this->assertSame(PengajuanRekening::STATUS_MENUNGGU_VERIFIKASI, $pengajuan->fresh()->status);
    }

    public function test_tolak_pengajuan_rekening_menyimpan_alasan(): void
    {
        $teller = $this->user('teller', 'verifikator');
        $pengajuan = $this->pengajuanRekening(PengajuanRekening::STATUS_MENUNGGU_VERIFIKASI);

        $respons = $this->actingAs($teller)
            ->from(route('pengelola.pengajuan-rekening.show', $pengajuan))
            ->post(route('pengelola.pengajuan-rekening.tolak', $pengajuan), [
                'alasan_tolak' => 'Foto KTP tidak terbaca.',
            ]);

        $respons->assertRedirect(route('pengelola.pengajuan-rekening.show', $pengajuan));
        $respons->assertSessionHas('success');

        $pengajuan->refresh();
        $this->assertSame(PengajuanRekening::STATUS_DITOLAK, $pengajuan->status);
        $this->assertSame('Foto KTP tidak terbaca.', $pengajuan->alasan_tolak);
        $this->assertSame($teller->id, $pengajuan->diverifikasi_oleh);
    }

    /* =====================================================================
     | P6 — TOLAK BUKTI PEMBAYARAN
     |===================================================================== */

    public function test_tolak_pembayaran_mewajibkan_catatan(): void
    {
        $teller = $this->user('teller', 'verifikator');
        $pengajuan = $this->pengajuanRekening(PengajuanRekening::STATUS_MENUNGGU_KONFIRMASI);
        $pembayaran = $this->pembayaran($pengajuan);

        $respons = $this->actingAs($teller)
            ->from(route('pengelola.pengajuan-rekening.show', $pengajuan))
            ->post(route('pengelola.pengajuan-rekening.tolak-pembayaran', $pengajuan), []);

        $respons->assertSessionHasErrors('catatan');
        $this->assertSame('Menunggu Konfirmasi', $pembayaran->fresh()->status);
    }

    public function test_tolak_pembayaran_menolak_bukti_dan_mengembalikan_status(): void
    {
        $teller = $this->user('teller', 'verifikator');
        $pengajuan = $this->pengajuanRekening(PengajuanRekening::STATUS_MENUNGGU_KONFIRMASI);
        $pembayaran = $this->pembayaran($pengajuan);

        $respons = $this->actingAs($teller)
            ->from(route('pengelola.pengajuan-rekening.show', $pengajuan))
            ->post(route('pengelola.pengajuan-rekening.tolak-pembayaran', $pengajuan), [
                'catatan' => 'Bukti transfer bukan atas nama pemohon.',
            ]);

        $respons->assertRedirect(route('pengelola.pengajuan-rekening.show', $pengajuan));
        $respons->assertSessionHas('success');

        $pembayaran->refresh();
        $this->assertSame('Ditolak', $pembayaran->status);
        $this->assertSame('Bukti transfer bukan atas nama pemohon.', $pembayaran->catatan);

        $this->assertSame(PengajuanRekening::STATUS_MENUNGGU_PEMBAYARAN, $pengajuan->fresh()->status);
    }

    /* =====================================================================
     | P7 — DETAIL PENGAJUAN PINJAMAN
     |===================================================================== */

    public function test_detail_pengajuan_pinjaman_menampilkan_ringkasan(): void
    {
        $ao = $this->user('ao', 'pengaju');
        $pengajuan = $this->pengajuanPembiayaan($this->anggota());

        $respons = $this->actingAs($ao)
            ->get(route('pengelola.pengajuan-pinjaman.show', $pengajuan));

        $respons->assertOk()->assertViewIs('pengelola.pengajuan-pinjaman-detail');
        $respons->assertSee($pengajuan->no_pengajuan);
        $respons->assertSee('Modal Usaha');
    }

    /* =====================================================================
     | P8 — REVIEW PENGAJUAN PINJAMAN
     |===================================================================== */

    public function test_review_pengajuan_pinjaman_menolak_status_bukan_pending_ao(): void
    {
        $manajer = $this->user('manajer', 'peninjau');
        $pembiayaan = $this->pembiayaan($this->anggota(), Pembiayaan::STATUS_DITOLAK);

        $respons = $this->actingAs($manajer)
            ->from(route('pengelola.pengajuan-pinjaman.index'))
            ->patch(route('pengelola.pengajuan-pinjaman.review', $pembiayaan), [
                'catatan_manajer' => 'Mencoba review pengajuan yang sudah ditolak.',
            ]);

        $respons->assertRedirect(route('pengelola.pengajuan-pinjaman.index'));
        $respons->assertSessionHas('error');
        $this->assertSame(Pembiayaan::STATUS_DITOLAK, $pembiayaan->fresh()->status_pembiayaan);
    }

    public function test_review_pengajuan_pinjaman_meneruskan_ke_pengurus(): void
    {
        $manajer = $this->user('manajer', 'peninjau');
        $pembiayaan = $this->pembiayaan($this->anggota(), Pembiayaan::STATUS_PENDING_AO);

        $respons = $this->actingAs($manajer)
            ->from(route('pengelola.pengajuan-pinjaman.index'))
            ->patch(route('pengelola.pengajuan-pinjaman.review', $pembiayaan), [
                'catatan_manajer' => 'Analisis 5C lengkap, diteruskan.',
            ]);

        $respons->assertRedirect(route('pengelola.pengajuan-pinjaman.index'));
        $respons->assertSessionHas('success');

        $pembiayaan->refresh();
        $this->assertSame(Pembiayaan::STATUS_DIREVIEW, $pembiayaan->status_pembiayaan);
        $this->assertSame($manajer->id, $pembiayaan->direview_oleh);
        $this->assertNotNull($pembiayaan->tanggal_direview);
        $this->assertSame('Analisis 5C lengkap, diteruskan.', $pembiayaan->catatan_manajer);
    }

    /* =====================================================================
     | P9 — TOLAK PENGAJUAN PINJAMAN
     |===================================================================== */

    public function test_tolak_pengajuan_pinjaman_mewajibkan_catatan(): void
    {
        $pengurus = $this->user('pengurus', 'pemutus');
        $pembiayaan = $this->pembiayaan($this->anggota(), Pembiayaan::STATUS_PENDING_AO);

        $respons = $this->actingAs($pengurus)
            ->from(route('pengelola.pengajuan-pinjaman.index'))
            ->patch(route('pengelola.pengajuan-pinjaman.tolak', $pembiayaan), []);

        $respons->assertSessionHasErrors('catatan_pengelola');
        $this->assertSame(Pembiayaan::STATUS_PENDING_AO, $pembiayaan->fresh()->status_pembiayaan);
    }

    public function test_tolak_pengajuan_pinjaman_menyimpan_alasan(): void
    {
        $pengurus = $this->user('pengurus', 'pemutus');
        $pembiayaan = $this->pembiayaan($this->anggota(), Pembiayaan::STATUS_PENDING_AO);

        $respons = $this->actingAs($pengurus)
            ->from(route('pengelola.pengajuan-pinjaman.index'))
            ->patch(route('pengelola.pengajuan-pinjaman.tolak', $pembiayaan), [
                'catatan_pengelola' => 'Agunan tidak memenuhi syarat.',
            ]);

        $respons->assertRedirect(route('pengelola.pengajuan-pinjaman.index'));
        $respons->assertSessionHas('info');

        $pembiayaan->refresh();
        $this->assertSame(Pembiayaan::STATUS_DITOLAK, $pembiayaan->status_pembiayaan);
        $this->assertSame('Agunan tidak memenuhi syarat.', $pembiayaan->keterangan);
    }

    /* =====================================================================
     | P10 & P11 — FORM PRODUK PEMBIAYAAN
     |===================================================================== */

    public function test_form_produk_pembiayaan_baru(): void
    {
        $pengurus = $this->user('pengurus', 'produk');

        $respons = $this->actingAs($pengurus)
            ->get(route('pengelola.produk-pembiayaan.create'));

        $respons->assertOk()->assertViewIs('pengelola.produk-pembiayaan.form');
        $respons->assertViewHas('produk', null);
    }

    public function test_form_edit_produk_pembiayaan_memuat_data(): void
    {
        $pengurus = $this->user('pengurus', 'produk');
        $produk = $this->produk();

        $respons = $this->actingAs($pengurus)
            ->get(route('pengelola.produk-pembiayaan.edit', $produk));

        $respons->assertOk()->assertViewIs('pengelola.produk-pembiayaan.form');
        $respons->assertViewHas('produk', fn ($nilai) => $nilai->is($produk));
        $respons->assertSee($produk->nama_produk);
    }

    /* =====================================================================
     | PAGAR RBAC
     |===================================================================== */

    public function test_rute_pengelola_menolak_peran_tanpa_izin(): void
    {
        $rekening = $this->pengajuanRekening(PengajuanRekening::STATUS_MENUNGGU_VERIFIKASI);
        $anggota = $this->anggota();
        $pembiayaan = $this->pembiayaan($anggota, Pembiayaan::STATUS_PENDING_AO);
        // Rute `show` ber-binding PengajuanPembiayaan, sedangkan review/tolak ber-binding
        // Pembiayaan pada segmen URI yang sama. SubstituteBindings (grup `web`) berjalan
        // sebelum middleware `izin:`, jadi fixture harus benar-benar ada di tabel binding
        // masing-masing — kalau tidak hasilnya 404, bukan 403.
        $pengajuan = $this->pengajuanPembiayaan($anggota);
        $produk = $this->produk();
        $approval = $this->approvalTransaksi('PENDING_PENGURUS', $this->user('teller', 'pengaju'));

        $rute = [
            ['POST', route('pengelola.approval.reject', $approval)],
            ['GET', route('pengelola.pengajuan-rekening.index')],
            ['GET', route('pengelola.pengajuan-rekening.show', $rekening)],
            ['POST', route('pengelola.pengajuan-rekening.setujui', $rekening)],
            ['POST', route('pengelola.pengajuan-rekening.tolak', $rekening)],
            ['POST', route('pengelola.pengajuan-rekening.tolak-pembayaran', $rekening)],
            ['GET', route('pengelola.pengajuan-pinjaman.show', $pengajuan)],
            ['PATCH', route('pengelola.pengajuan-pinjaman.review', $pembiayaan)],
            ['PATCH', route('pengelola.pengajuan-pinjaman.tolak', $pembiayaan)],
            ['GET', route('pengelola.produk-pembiayaan.create')],
            ['GET', route('pengelola.produk-pembiayaan.edit', $produk)],
        ];

        $this->assertCount(11, $rute, 'Batch 28 harus mencakup tepat 11 rute.');

        // Ketiga peran ini tidak ada pada satu pun kelompok izin pengelola.*
        foreach (['admin', 'dps', 'anggota'] as $peran) {
            $pengguna = $this->user($peran, 'rbac');

            foreach ($rute as [$metode, $uri]) {
                $this->actingAs($pengguna)->call($metode, $uri)->assertForbidden();
            }
        }
    }

    public function test_rute_pengelola_menolak_akses_silang_kelompok_izin(): void
    {
        $rekening = $this->pengajuanRekening(PengajuanRekening::STATUS_MENUNGGU_VERIFIKASI);
        $anggota = $this->anggota();
        $pembiayaan = $this->pembiayaan($anggota, Pembiayaan::STATUS_PENDING_AO);
        $pengajuan = $this->pengajuanPembiayaan($anggota);
        $produk = $this->produk();
        $approval = $this->approvalTransaksi('PENDING_PENGURUS', $this->user('teller', 'pengaju'));

        $kasus = [
            ['teller', 'POST', route('pengelola.approval.reject', $approval)],
            ['ao', 'POST', route('pengelola.approval.reject', $approval)],
            ['teller', 'GET', route('pengelola.pengajuan-pinjaman.show', $pengajuan)],
            ['ao', 'GET', route('pengelola.pengajuan-rekening.index')],
            ['ao', 'GET', route('pengelola.produk-pembiayaan.edit', $produk)],
            ['manajer', 'GET', route('pengelola.produk-pembiayaan.create')],
            ['teller', 'PATCH', route('pengelola.pengajuan-pinjaman.review', $pembiayaan)],
            ['teller', 'PATCH', route('pengelola.pengajuan-pinjaman.tolak', $pembiayaan)],
            ['ao', 'POST', route('pengelola.pengajuan-rekening.setujui', $rekening)],
            ['manajer', 'GET', route('pengelola.produk-pembiayaan.edit', $produk)],
        ];

        foreach ($kasus as [$peran, $metode, $uri]) {
            $pengguna = $this->user($peran, 'silang');

            $this->actingAs($pengguna)->call($metode, $uri)->assertForbidden();
        }
    }

    public function test_tamu_diarahkan_ke_halaman_login(): void
    {
        $this->get(route('pengelola.pengajuan-rekening.index'))
            ->assertRedirect(route('login'));
    }
}
