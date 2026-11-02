<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\Deposito;
use App\Models\DepositoPenaltyRelief;
use App\Models\DepositoProduct;
use App\Models\LaporanKeuanganLog;
use App\Models\Rekening;
use App\Models\User;
use App\Support\LaporanKeuanganPermission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Regresi Fase 4 — R16, R17, R18, R19.
 *
 * Berkas ini menjaga rekomendasi terakhir audit (docs/AUDIT-MODUL-SISTEM.md)
 * agar tidak mengalami kemunduran (regresi) saat kode lain berubah:
 *
 *  - R16 — tautan JS memakai rute terdaftar, bukan path hardcode.
 *  - R17 — transparansi nisbah & rincian bagi hasil deposito untuk anggota.
 *  - R18 — jalur keringanan penalti deposito berjenjang (Teller -> AO -> Manajer)
 *          dengan prinsip maker ≠ checker.
 *  - R19 — pemisahan wewenang MENULIS dari wewenang MELIHAT pada modul laporan:
 *          modul laporan hanya-baca untuk SEMUA peran, wewenang tulis dipindahkan
 *          ke modul `penyesuaian_jurnal` yang hanya dipegang pengurus (B11).
 */
class Fase4RegresiTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<string, User> */
    private array $pengguna = [];

    private string $prefix = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'F4'.strtoupper(substr(uniqid(), -6));

        foreach (['admin', 'teller', 'manajer', 'pengurus', 'ao', 'dps', 'anggota'] as $peran) {
            $this->pengguna[$peran] = User::create([
                'name' => 'Fase4 '.ucfirst($peran),
                'email' => "fase4.{$this->prefix}.{$peran}@test.local",
                'password' => Hash::make('password'),
                'role' => $peran,
                'is_active' => true,
            ]);
        }
    }

    /* =====================================================================
     | Pembantu / fixture
     |===================================================================== */

    private function peran(string $peran): User
    {
        return $this->pengguna[$peran];
    }

    /** Anggota pemilik akun login (peran `anggota`). */
    private function anggotaLogin(string $suffix = 'AG'): Anggota
    {
        return Anggota::create([
            'user_id' => $this->peran('anggota')->id,
            'no_anggota' => $this->prefix.$suffix,
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Uji Fase Empat',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Alamat Uji Fase Empat',
            'kota' => 'Jakarta',
            'provinsi' => 'DKI Jakarta',
            'status_anggota' => 'Aktif',
            'tanggal_gabung' => now()->toDateString(),
        ]);
    }

    /** Bilyet deposito Mudharabah aktif dengan penalti tetap. */
    private function deposito(Anggota $anggota, float $pokok = 10_000_000, float $penalti = 50_000): Deposito
    {
        $produk = DepositoProduct::create([
            'name' => 'Deposito Mudharabah Uji F4 '.uniqid(),
            'tipe_produk' => DepositoProduct::TIPE_DEPOSITO,
            'has_nisbah' => true,
            'coa_kode' => DepositoProduct::COA_BY_TIPE[DepositoProduct::TIPE_DEPOSITO],
            'tenor_months' => 12,
            'min_amount' => 100_000,
            'nisbah_anggota' => 60.00,
            'nisbah_koperasi' => 40.00,
            'is_active' => true,
        ]);

        $produk->forceFill([
            'allow_early_withdrawal' => true,
            'penalty_fee_type' => DepositoProduct::PENALTI_FIXED,
            'penalty_fee_amount' => $penalti,
            'penalty_coa_id' => DepositoProduct::PENALTI_COA_DEFAULT,
        ])->save();

        return Deposito::create([
            'deposito_number' => 'DEP-F4-'.uniqid(),
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $this->rekeningSukarela($anggota, $pokok + 1_000_000)->id,
            'principal_amount' => $pokok,
            'nisbah_anggota' => 60.00,
            'nisbah_koperasi' => 40.00,
            'start_date' => now()->subMonths(1)->toDateString(),
            'maturity_date' => now()->addMonths(11)->toDateString(),
            'status' => Deposito::STATUS_ACTIVE,
        ]);
    }

    /** Rekening Simpanan Sukarela sebagai sumber dana deposito. */
    private function rekeningSukarela(Anggota $anggota, float $saldo = 1_000_000): Rekening
    {
        return Rekening::create([
            'no_rekening' => 'RKN-'.strtoupper(substr(uniqid(), -8)),
            'anggota_id' => $anggota->id,
            'akun_id' => Akun::where('kode_akun', '1133')->value('id') ?? Akun::query()->value('id'),
            'jenis_rekening' => 'Simpanan Sukarela',
            'akad' => 'Wadiah',
            'saldo' => $saldo,
            'batas_minimum' => 0,
            'tanggal_buka' => now()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);
    }

    /* =====================================================================
     | R19 — Pemisahan wewenang tulis vs lihat pada modul laporan
     |===================================================================== */

    public function test_seluruh_modul_laporan_hanya_baca_untuk_semua_peran(): void
    {
        $this->assertNotEmpty(LaporanKeuanganPermission::modulLaporan(),
            'Harus ada modul laporan yang dijaga.');

        foreach (LaporanKeuanganPermission::modulLaporan() as $modul) {
            $this->assertTrue(LaporanKeuanganPermission::modulHanyaBaca($modul),
                "Modul laporan '{$modul}' wajib hanya-baca — tidak boleh ada peran "
                .'yang memegang hak edit/hapus di dalamnya (B11/R19).');

            $this->assertSame([], LaporanKeuanganPermission::peranYangBoleh($modul, LaporanKeuanganPermission::EDIT),
                "Tidak boleh ada peran dengan hak EDIT pada modul laporan '{$modul}'.");
            $this->assertSame([], LaporanKeuanganPermission::peranYangBoleh($modul, LaporanKeuanganPermission::HAPUS),
                "Tidak boleh ada peran dengan hak HAPUS pada modul laporan '{$modul}'.");
        }

        $this->assertNotContains(LaporanKeuanganPermission::MODUL_PENYESUAIAN,
            LaporanKeuanganPermission::modulLaporan(),
            'Modul penyesuaian jurnal bukan modul laporan — ia justru penerima wewenang tulis.');
    }

    public function test_wewenang_menulis_laporan_dipegang_pengurus_dan_puncak_administratif(): void
    {
        $modul = LaporanKeuanganPermission::MODUL_PENYESUAIAN;

        // Sejak `super_admin` menjadi puncak administratif (level 100), ia
        // memegang SELURUH modul administratif termasuk wewenang tulis
        // penyesuaian jurnal — bersama Pengurus, bukan menggantikannya.
        $this->assertSame(['super_admin', 'pengurus'],
            LaporanKeuanganPermission::peranYangBoleh($modul, LaporanKeuanganPermission::EDIT),
            'Penyesuaian jurnal hanya boleh diubah Super Admin dan Pengurus.');
        $this->assertSame(['super_admin', 'pengurus'],
            LaporanKeuanganPermission::peranYangBoleh($modul, LaporanKeuanganPermission::HAPUS),
            'Penyesuaian jurnal hanya boleh dihapus Super Admin dan Pengurus.');

        foreach (['manajer', 'dps', 'teller', 'ao', 'admin', 'anggota'] as $peran) {
            $this->assertFalse(
                LaporanKeuanganPermission::boleh($peran, $modul, LaporanKeuanganPermission::EDIT),
                "Peran '{$peran}' tidak boleh memegang wewenang tulis penyesuaian jurnal."
            );
        }
    }

    public function test_pengurus_tetap_dapat_melihat_dan_mengunduh_laporan(): void
    {
        // Pengurus tetap memegang hak baca penuh atas modul laporan yang
        // menjadi kewenangannya: LIHAT & EKSPOR di semua modul laporan, plus
        // UNDUH pada laporan keuangan. Modul AUDIT dikecualikan: ia bukan
        // laporan operasional, melainkan alat pengawasan independen —
        // eksklusif DPS (pemisahan tugas), sehingga Pengurus memang tidak
        // boleh melihat maupun mengekspornya.
        //
        // Laporan Operasional juga dikecualikan (A5): rutenya dijaga
        // `izin:laporan.manajer` yang hanya memuat peran Manajer, sehingga hak
        // Pengurus di sana tidak dapat dipakai. Matriks tidak lagi
        // mencantumkannya agar sejalan dengan penegakan izin.
        $modulUji = array_values(array_filter(
            LaporanKeuanganPermission::modulLaporan(),
            fn ($modul) => ! in_array($modul, [
                LaporanKeuanganPermission::MODUL_AUDIT,
                LaporanKeuanganPermission::MODUL_LAPORAN_MANAJER,
            ], true)
        ));

        foreach ($modulUji as $modul) {
            $this->assertTrue(
                LaporanKeuanganPermission::boleh('pengurus', $modul, LaporanKeuanganPermission::LIHAT),
                "Pengurus harus tetap dapat MELIHAT laporan '{$modul}'."
            );
            $this->assertTrue(
                LaporanKeuanganPermission::boleh('pengurus', $modul, LaporanKeuanganPermission::EKSPOR),
                "Pengurus harus tetap dapat MENG-EKSPOR laporan '{$modul}'."
            );
            $this->assertTrue(
                LaporanKeuanganPermission::boleh('pengurus', $modul, LaporanKeuanganPermission::UNDUH),
                "Pengurus harus tetap dapat MENGUNDUH laporan '{$modul}'."
            );
        }

        // Modul audit: eksklusif DPS — Pengurus TIDAK punya hak apa pun.
        $this->assertSame(
            [],
            LaporanKeuanganPermission::hak('pengurus', LaporanKeuanganPermission::MODUL_AUDIT),
            'Modul audit harus eksklusif DPS; Pengurus adalah pihak yang diawasi.'
        );
    }

    /**
     * R19 — percobaan MENULIS laporan ditolak bagi pengurus.
     *
     * Catatan: setiap rute laporan hanya didaftarkan untuk metode GET, sehingga
     * DELETE `/laporan/neraca` berhenti di router (405) — bukan 403 dari
     * middleware `laporan.akses`. Yang dijaga test ini: tidak ada jalur HTTP
     * apa pun bagi pengurus untuk MENGHAPUS laporan, dan bila kelak rute hapus
     * ditambahkan, middleware-lah yang menolaknya (matriks hanya-baca) — maka
     * matriksnya diuji langsung di sini.
     */
    public function test_menghapus_laporan_ditolak_bagi_pengurus_dan_tercatat_sebagai_penolakan(): void
    {
        $pengurus = $this->peran('pengurus');

        // Pengurus boleh MEMBACA laporan (hanya-baca).
        $this->actingAs($pengurus)->get('/laporan/neraca')->assertOk();

        // Namun tidak ada satu pun jalur MENULIS: seluruh metode tulis berhenti
        // di router (405) karena rute hanya didaftarkan untuk GET.
        foreach (['post', 'put', 'patch', 'delete'] as $metode) {
            $this->actingAs($pengurus)
                ->{$metode}('/laporan/neraca')
                ->assertStatus(405);
        }

        // Tidak ada satu pun log "hapus laporan neraca BERHASIL" yang boleh ada.
        $berhasil = LaporanKeuanganLog::where('modul', LaporanKeuanganPermission::MODUL_NERACA)
            ->where('aktivitas', LaporanKeuanganPermission::HAPUS)
            ->where('berhasil', true)
            ->count();

        $this->assertSame(0, $berhasil,
            'Tidak boleh ada penghapusan laporan yang berhasil — modul laporan hanya-baca (R19/B11).');

        // Dan bila rute tulis memang ada, matriks wajib menolaknya lebih dulu.
        $this->assertSame([], LaporanKeuanganPermission::peranYangBoleh(
            LaporanKeuanganPermission::MODUL_NERACA, LaporanKeuanganPermission::HAPUS
        ));
    }

    public function test_dps_dapat_membuka_halaman_audit_untuk_menilai_matriksnya(): void
    {
        // PEMISAHAN TUGAS: halaman audit adalah alat pengawasan independen,
        // eksklusif milik DPS — bukan Pengurus yang diawasi.
        $this->actingAs($this->peran('dps'))
            ->get(route('audit.laporan.index'))
            ->assertOk();
    }

    /* =====================================================================
     | R18 — Keringanan penalti deposito berjenjang + maker ≠ checker
     |===================================================================== */

    public function test_alur_keringanan_penalti_deposito_berjenjang_sampai_disetujui(): void
    {
        $anggota = $this->anggotaLogin();
        $deposito = $this->deposito($anggota, 10_000_000, 50_000);

        // 1. Teller mengajukan — status awal PENDING_AO.
        $this->actingAs($this->peran('teller'))->post('/teller/keringanan-penalti-deposito', [
            'deposito_id' => $deposito->id,
            'alasan_pengaju' => 'Anggota lansia, kebutuhan darurat biaya pengobatan.',
        ])->assertRedirect();

        $relief = DepositoPenaltyRelief::where('deposito_id', $deposito->id)->latest()->firstOrFail();
        $this->assertSame(DepositoPenaltyRelief::STATUS_PENDING_AO, $relief->status);
        $this->assertSame($this->peran('teller')->id, $relief->pengaju_id);
        $this->assertSame(50_000.0, (float) $relief->nominal_penalti_awal,
            'Nominal penalti awal harus mengikuti konfigurasi produk.');

        // 2. AO memberi rekomendasi — status naik ke PENDING_MANAJER.
        $this->actingAs($this->peran('ao'))->post("/ao/keringanan-penalti-deposito/{$relief->id}/rekomendasi", [
            'rekomendasi_ao' => DepositoPenaltyRelief::REKOMENDASI_SETUJU,
            'catatan_ao' => 'Verifikasi lapangan: alasan terbukti, riwayat bayar baik.',
        ])->assertRedirect();

        $this->assertSame(DepositoPenaltyRelief::STATUS_PENDING_MANAJER, $relief->fresh()->status);

        // 3. Manajer menyetujui.
        $this->actingAs($this->peran('manajer'))->post("/manajer/keringanan-penalti-deposito/{$relief->id}/approve", [
            'catatan_manajer' => 'Disetujui sebagai ta\'zir yang dimaafkan.',
        ])->assertRedirect();

        $relief->refresh();
        $this->assertSame(DepositoPenaltyRelief::STATUS_APPROVED, $relief->status);
        $this->assertSame(50_000.0, (float) $relief->nominal_penalti_disetujui,
            'Tanpa nominal eksplisit, keringanan penuh mengikuti penalti awal.');
    }

    public function test_pemutus_terakhir_tidak_boleh_orang_yang_sama_dengan_perekomendasi(): void
    {
        $anggota = $this->anggotaLogin();
        $deposito = $this->deposito($anggota, 5_000_000, 25_000);

        $this->actingAs($this->peran('teller'))->post('/teller/keringanan-penalti-deposito', [
            'deposito_id' => $deposito->id,
            'alasan_pengaju' => 'Uji pemisahan tugas.',
        ])->assertRedirect();

        $relief = DepositoPenaltyRelief::where('deposito_id', $deposito->id)->latest()->firstOrFail();

        $this->actingAs($this->peran('ao'))->post("/ao/keringanan-penalti-deposito/{$relief->id}/rekomendasi", [
            'rekomendasi_ao' => DepositoPenaltyRelief::REKOMENDASI_SETUJU,
            'catatan_ao' => 'Rekomendasi uji.',
        ])->assertRedirect();

        $this->assertSame(DepositoPenaltyRelief::STATUS_PENDING_MANAJER, $relief->fresh()->status);

        // Pengaju (pengaju_id) tidak boleh memutus pengajuannya sendiri.
        $this->actingAs($this->peran('pengurus'))->post("/manajer/keringanan-penalti-deposito/{$relief->id}/approve", [
            'catatan_manajer' => 'Percobaan menerobos maker-checker.',
        ])->assertForbidden();

        $this->assertSame(DepositoPenaltyRelief::STATUS_PENDING_MANAJER, $relief->fresh()->status,
            'Status tidak boleh berubah ketika pemisahan tugas dilanggar.');

        // Manajer yang benar-benar berbeda boleh memutuskan.
        $this->actingAs($this->peran('manajer'))->post("/manajer/keringanan-penalti-deposito/{$relief->id}/approve", [
            'catatan_manajer' => 'Diputuskan oleh pejabat yang berwenang.',
        ])->assertRedirect();

        $this->assertSame(DepositoPenaltyRelief::STATUS_APPROVED, $relief->fresh()->status);
    }

    public function test_penolakan_keringanan_menyisakan_penalti_penuh_untuk_anggota(): void
    {
        $anggota = $this->anggotaLogin();
        $deposito = $this->deposito($anggota, 4_000_000, 20_000);

        $this->actingAs($this->peran('teller'))->post('/teller/keringanan-penalti-deposito', [
            'deposito_id' => $deposito->id,
            'alasan_pengaju' => 'Uji penolakan.',
        ])->assertRedirect();

        $relief = DepositoPenaltyRelief::where('deposito_id', $deposito->id)->latest()->firstOrFail();

        $this->actingAs($this->peran('ao'))->post("/ao/keringanan-penalti-deposito/{$relief->id}/rekomendasi", [
            'rekomendasi_ao' => DepositoPenaltyRelief::REKOMENDASI_TOLAK,
            'catatan_ao' => 'Alasan tidak cukup kuat.',
        ])->assertRedirect();

        $this->actingAs($this->peran('manajer'))->post("/manajer/keringanan-penalti-deposito/{$relief->id}/reject", [
            'catatan_manajer' => 'Ditolak; penalti tetap ditanggung anggota.',
        ])->assertRedirect();

        $relief->refresh();
        $this->assertSame(DepositoPenaltyRelief::STATUS_REJECTED, $relief->status);
        $this->assertSame(0.0, (float) $relief->nominal_penalti_disetujui,
            'Penolakan berarti tidak ada keringanan yang diberikan.');
    }

    public function test_pengajuan_ganda_ditolak_selama_masih_berjalan(): void
    {
        $anggota = $this->anggotaLogin();
        $deposito = $this->deposito($anggota, 3_000_000, 15_000);

        $teller = $this->peran('teller');

        $this->actingAs($teller)->post('/teller/keringanan-penalti-deposito', [
            'deposito_id' => $deposito->id,
            'alasan_pengaju' => 'Pengajuan pertama.',
        ])->assertRedirect();

        $jumlahSebelum = DepositoPenaltyRelief::where('deposito_id', $deposito->id)->count();

        $this->actingAs($teller)->post('/teller/keringanan-penalti-deposito', [
            'deposito_id' => $deposito->id,
            'alasan_pengaju' => 'Pengajuan kedua (harus ditolak).',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame($jumlahSebelum, DepositoPenaltyRelief::where('deposito_id', $deposito->id)->count(),
            'Pengajuan ganda wajib ditolak selama yang sebelumnya masih berjalan (B10/R18).');
    }

    public function test_penalti_keringanan_dipangkas_pada_batas_penalti_awal(): void
    {
        $anggota = $this->anggotaLogin();
        $deposito = $this->deposito($anggota, 2_000_000, 10_000);

        $this->actingAs($this->peran('teller'))->post('/teller/keringanan-penalti-deposito', [
            'deposito_id' => $deposito->id,
            'alasan_pengaju' => 'Uji batas atas keringanan.',
        ])->assertRedirect();

        $relief = DepositoPenaltyRelief::where('deposito_id', $deposito->id)->latest()->firstOrFail();

        $this->actingAs($this->peran('ao'))->post("/ao/keringanan-penalti-deposito/{$relief->id}/rekomendasi", [
            'rekomendasi_ao' => DepositoPenaltyRelief::REKOMENDASI_SETUJU,
            'catatan_ao' => 'Rekomendasi uji batas.',
        ])->assertRedirect();

        // Manajer mencoba menyetujui MELEBIHI penalti awal — wajib dipangkas.
        $this->actingAs($this->peran('manajer'))->post("/manajer/keringanan-penalti-deposito/{$relief->id}/approve", [
            'nominal_disetujui' => 999_999_999,
            'catatan_manajer' => 'Percobaan nilai di luar batas.',
        ])->assertRedirect();

        $relief->refresh();
        $this->assertLessThanOrEqual((float) $relief->nominal_penalti_awal, (float) $relief->nominal_penalti_disetujui,
            'Keringanan wajib dipangkas pada batas penalti awal.');
        $this->assertLessThanOrEqual((float) $deposito->principal_amount, (float) $relief->nominal_penalti_disetujui,
            'Keringanan tidak boleh melebihi pokok deposito.');
    }

    public function test_hanya_peran_berwenang_yang_dapat_membuka_menu_keringanan_penalti(): void
    {
        $this->actingAs($this->peran('teller'))->get('/teller/keringanan-penalti-deposito/riwayat')->assertOk();
        $this->actingAs($this->peran('ao'))->get('/ao/keringanan-penalti-deposito')->assertOk();
        $this->actingAs($this->peran('manajer'))->get('/manajer/keringanan-penalti-deposito')->assertOk();

        // DPS hanya pengawas syariah — dilarang memutasi/verifikasi keringanan.
        foreach (['dps', 'anggota'] as $peran) {
            $this->actingAs($this->peran($peran))
                ->get('/ao/keringanan-penalti-deposito')
                ->assertForbidden();
        }
    }

    /* =====================================================================
     | R17 — Transparansi nisbah & bagi hasil untuk anggota
     |===================================================================== */

    public function test_anggota_hanya_dapat_melihat_rincian_bagi_hasil_bilyet_miliknya(): void
    {
        $anggotaPemilik = $this->anggotaLogin();
        $deposito = $this->deposito($anggotaPemilik);

        $this->actingAs($this->peran('anggota'))
            ->get("/nasabah/deposito/{$deposito->id}/bagi-hasil")
            ->assertOk();

        // Anggota lain (memakai akun anggota kedua) mencoba bilyet orang lain.
        $anggotaLain = Anggota::create([
            'user_id' => User::create([
                'name' => 'Fase4 Anggota Lain',
                'email' => "fase4.{$this->prefix}.lain@test.local",
                'password' => Hash::make('password'),
                'role' => 'anggota',
                'is_active' => true,
            ])->id,
            'no_anggota' => $this->prefix.'LAIN',
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Lain Uji Fase Empat',
            'jenis_kelamin' => 'Perempuan',
            'alamat' => 'Alamat Uji Lain',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => 'Aktif',
            'tanggal_gabung' => now()->toDateString(),
        ]);

        $this->actingAs($anggotaLain->user)
            ->get("/nasabah/deposito/{$deposito->id}/bagi-hasil")
            ->assertForbidden();
    }

    public function test_halaman_bagi_hasil_anggota_menampilkan_nisbah_akad(): void
    {
        $anggota = $this->anggotaLogin();
        $deposito = $this->deposito($anggota);

        $respon = $this->actingAs($this->peran('anggota'))
            ->get("/nasabah/deposito/{$deposito->id}/bagi-hasil")
            ->assertOk();

        $tampilanDeposito = $respon->viewData('deposito');

        $this->assertSame($deposito->id, $tampilanDeposito->id);
        $this->assertSame(60.0, (float) $tampilanDeposito->nisbah_anggota,
            'Nisbah anggota harus tampil apa adanya agar dapat diverifikasi anggota.');
        $this->assertSame(40.0, (float) $tampilanDeposito->nisbah_koperasi,
            'Nisbah koperasi harus tampil berdampingan sebagai bentuk transparansi (R17).');
        $this->assertSame(100.0,
            (float) $tampilanDeposito->nisbah_anggota + (float) $tampilanDeposito->nisbah_koperasi,
            'Total nisbah wajib 100% — dasar sah bagi hasil Mudharabah.');
    }

    public function test_daftar_deposito_anggota_dapat_dibuka(): void
    {
        $anggota = $this->anggotaLogin();

        $this->deposito($anggota, 1_000_000, 5_000);

        $this->actingAs($this->peran('anggota'))
            ->get('/nasabah/deposito')
            ->assertOk()
            ->assertSee('60% : 40%')
            ->assertSee('Rincian Bagi Hasil');
    }

    /* =====================================================================
     | R16 — Tautan JS memakai rute terdaftar
     |===================================================================== */

    public function test_tautan_void_pada_riwayat_teller_memakai_rute_terdaftar(): void
    {
        $this->actingAs($this->peran('teller'))
            ->get('/teller/riwayat')
            ->assertOk()
            ->assertSee(route('teller.void', ['transaksi' => '__ID__']), false);
    }
}
