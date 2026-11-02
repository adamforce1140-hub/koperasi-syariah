<?php

namespace Tests\Feature;

use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\PengeluaranBeban;
use App\Models\User;
use App\Services\AutoJournalingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Modul DPS — Audit Beban Operasional (/dps/audit-beban)
 * + Pemetaan CoA spesifik per jenis beban operasional.
 *
 * Cakupan pengujian:
 *   A. Card 'Total Beban Disetujui' = SUM(nominal) eksklusif status 'Approved'
 *      dan selalu mengikuti periode (bulan / rentang tanggal) yang dipilih.
 *   B. Status 'Pending' & 'Ditolak' tidak pernah bocor ke total card.
 *   C. Setiap jenis beban tercatat pada kode akun CoA spesifiknya
 *      (Gaji 5-1001, ATK 5-1002, Utilitas 5-1003).
 *   D. Error handling: kode akun spesifik tidak ada di master CoA -> fallback
 *      ke akun general 5-1001 tanpa menggagalkan proses (tidak exception).
 *   E. Alur audit / approval / pencatatan jurnal berjalan tanpa error.
 *
 * Catatan: data uji dibuat dengan tanggal di tahun 2019 (di luar rentang data
 * dev) dan dibungkus DatabaseTransactions, sehingga tidak mengganggu data asli.
 * Assertion memakai DELTA (selisih sebelum/sesudah) agar tetap valid walau
 * basis data dev sudah berisi transaksi.
 */
class DpsAuditBebanOperasionalTest extends TestCase
{
    use DatabaseTransactions;

    /** Tahun uji yang jauh dari data dev (semua data dev 2026). */
    private const TAHUN_UJI = 2019;

    private function pengguna(string $role): User
    {
        return User::firstOrCreate(
            ['email' => "auditbeban.{$role}@test.local"],
            [
                'name' => 'Audit Beban '.ucfirst($role),
                'password' => Hash::make('password'),
                'role' => $role,
            ]
        );
    }

    /** Akun kas/bank sumber uang yang sah untuk transaksi uji. */
    private function coaKas(): Coa
    {
        return Coa::findByKode('1-1001') ?? Coa::where('kategori', 'Aset')->firstOrFail();
    }

    /**
     * Buat satu baris pengeluaran beban uji secara langsung (tanpa HTTP),
     * dengan status yang ditentukan agar bisa diuji dari sisi query audit.
     */
    private function buatBeban(string $kodeAkun, float $nominal, string $status, string $tanggal): PengeluaranBeban
    {
        $coaBeban = Coa::findByKode($kodeAkun) ?? Coa::where('kode_akun', $kodeAkun)->firstOrFail();

        return PengeluaranBeban::create([
            'no_pengeluaran' => PengeluaranBeban::buatNoPengeluaran(),
            'tanggal' => $tanggal,
            'coa_beban_id' => $coaBeban->id,
            'coa_kas_id' => $this->coaKas()->id,
            'nominal' => $nominal,
            'keterangan' => 'Uji audit beban operasional',
            'status' => $status,
            'created_by' => $this->pengguna('teller')->id,
        ]);
    }

    /** Buka halaman audit DPS dengan parameter filter tertentu. */
    private function bukaAudit(array $params = [])
    {
        return $this->actingAs($this->pengguna('dps'))
            ->get(route('dps.beban.index', $params));
    }

    /* ============================================================
     * A. AKURASI CARD 'TOTAL BEBAN DISETUJUI' PER PERIODE
     * ============================================================ */

    public function test_card_total_beban_disetujui_hanya_menghitung_status_approved_pada_periode_terpilih(): void
    {
        // Periode target: 2019-03
        $this->buatBeban('5-1001', 500000, PengeluaranBeban::STATUS_APPROVED, self::TAHUN_UJI.'-03-05');
        $this->buatBeban('5-1002', 700000, PengeluaranBeban::STATUS_APPROVED, self::TAHUN_UJI.'-03-20');

        // Status non-Approved di periode yang SAMA -> wajib dikecualikan
        $this->buatBeban('5-1003', 900000, PengeluaranBeban::STATUS_PENDING, self::TAHUN_UJI.'-03-10');
        $this->buatBeban('5-1001', 800000, PengeluaranBeban::STATUS_DITOLAK, self::TAHUN_UJI.'-03-15');

        // Approved tapi di LUAR periode -> wajib dikecualikan
        $this->buatBeban('5-1001', 300000, PengeluaranBeban::STATUS_APPROVED, self::TAHUN_UJI.'-04-01');

        $res = $this->bukaAudit(['bulan' => self::TAHUN_UJI.'-03']);
        $res->assertOk();

        $this->assertSame(
            1200000.0,
            (float) $res->viewData('totalApproved'),
            "Card 'Total Beban Disetujui' harus = SUM Approved pada bulan terpilih (500rb + 700rb), bukan termasuk Pending/Ditolak."
        );

        // Tabel hanya memuat baris periode terpilih (4 baris: 2 Approved, 1 Pending, 1 Ditolak)
        $this->assertSame(4, $res->viewData('pengeluarans')->total());

        // Ringkasan status memperjelas pemisahan
        $ringkasan = $res->viewData('ringkasanStatus');
        $this->assertSame(1200000.0, (float) $ringkasan[PengeluaranBeban::STATUS_APPROVED]);
        $this->assertSame(900000.0, (float) $ringkasan[PengeluaranBeban::STATUS_PENDING]);
        $this->assertSame(800000.0, (float) $ringkasan[PengeluaranBeban::STATUS_DITOLAK]);
    }

    public function test_card_total_beban_disetujui_mengikuti_rentang_tanggal_bebas(): void
    {
        $this->buatBeban('5-1001', 100000, PengeluaranBeban::STATUS_APPROVED, self::TAHUN_UJI.'-05-01');
        $this->buatBeban('5-1002', 200000, PengeluaranBeban::STATUS_APPROVED, self::TAHUN_UJI.'-05-15');
        $this->buatBeban('5-1003', 400000, PengeluaranBeban::STATUS_APPROVED, self::TAHUN_UJI.'-05-31');
        // Di luar rentang (baik sebelum maupun sesudah)
        $this->buatBeban('5-1001', 500000, PengeluaranBeban::STATUS_APPROVED, self::TAHUN_UJI.'-04-30');
        $this->buatBeban('5-1001', 600000, PengeluaranBeban::STATUS_APPROVED, self::TAHUN_UJI.'-06-01');

        $res = $this->bukaAudit([
            'dari' => self::TAHUN_UJI.'-05-01',
            'sampai' => self::TAHUN_UJI.'-05-31',
        ]);
        $res->assertOk();

        $this->assertSame(
            700000.0,
            (float) $res->viewData('totalApproved'),
            'Card harus = SUM Approved yang tanggalnya berada di dalam rentang dari/sampai.'
        );
        $this->assertSame(3, $res->viewData('pengeluarans')->total());
    }

    public function test_card_total_beban_disetujui_sama_dengan_breakdown_akun_periode_sama(): void
    {
        $this->buatBeban('5-1001', 150000, PengeluaranBeban::STATUS_APPROVED, self::TAHUN_UJI.'-07-02');
        $this->buatBeban('5-1002', 250000, PengeluaranBeban::STATUS_APPROVED, self::TAHUN_UJI.'-07-09');
        $this->buatBeban('5-1003', 350000, PengeluaranBeban::STATUS_APPROVED, self::TAHUN_UJI.'-07-17');
        $this->buatBeban('5-1002', 999999, PengeluaranBeban::STATUS_PENDING, self::TAHUN_UJI.'-07-20');

        $res = $this->bukaAudit(['bulan' => self::TAHUN_UJI.'-07']);
        $res->assertOk();

        $totalCard = (float) $res->viewData('totalApproved');
        $breakdownAkun = $res->viewData('breakdownAkun');

        $this->assertSame(750000.0, $totalCard);
        $this->assertSame(
            $totalCard,
            (float) $breakdownAkun->sum('total'),
            'Total pada breakdown akun wajib identik dengan nilai card pada periode yang sama.'
        );

        // Akun spesifik muncul di breakdown dengan kode yang benar
        $kodeBreakdown = $breakdownAkun->pluck('kode_akun')->all();
        foreach (['5-1001', '5-1002', '5-1003'] as $kode) {
            $this->assertContains($kode, $kodeBreakdown, "Breakdown akun harus memuat akun {$kode}.");
        }
    }

    public function test_tanpa_filter_card_menghitung_seluruh_periode(): void
    {
        $totalSemuaApproved = (float) PengeluaranBeban::where('status', PengeluaranBeban::STATUS_APPROVED)->sum('nominal');

        $this->buatBeban('5-1001', 111000, PengeluaranBeban::STATUS_APPROVED, self::TAHUN_UJI.'-08-08');
        $this->buatBeban('5-1002', 222000, PengeluaranBeban::STATUS_PENDING, self::TAHUN_UJI.'-08-09');

        $res = $this->bukaAudit();
        $res->assertOk();

        $this->assertSame(
            $totalSemuaApproved + 111000.0,
            (float) $res->viewData('totalApproved'),
            'Tanpa filter periode, card = SUM seluruh transaksi Approved (Pending tetap dikecualikan).'
        );
    }

    /* ============================================================
     * B. PEMETAAN KODE AKUN CoA SPESIFIK PER JENIS BEBAN
     * ============================================================ */

    public function test_master_coa_memisahkan_akun_beban_operasional_per_jenis(): void
    {
        foreach (['5-1001', '5-1002', '5-1003'] as $kode) {
            $coa = Coa::where('kode_akun', $kode)->first();

            $this->assertNotNull($coa, "Akun {$kode} harus tersedia di master CoA.");
            $this->assertSame('Beban', $coa->kategori, "Akun {$kode} harus berkategori Beban.");
            $this->assertTrue((bool) $coa->is_active, "Akun {$kode} harus aktif.");
        }
    }

    public function test_jurnal_pengeluaran_beban_atk_memakai_akun_5_1002(): void
    {
        $service = app(AutoJournalingService::class);

        $kode = $service->kodeAkunBebanByJenis('atk');

        $this->assertSame(AutoJournalingService::BEBAN_ATK, $kode);
        $this->assertSame('5-1002', $kode);
    }

    public function test_jurnal_pengeluaran_beban_utilitas_memakai_akun_5_1003(): void
    {
        $service = app(AutoJournalingService::class);

        $this->assertSame('5-1003', $service->kodeAkunBebanByJenis('utilitas'));
    }

    public function test_jurnal_pengeluaran_beban_gaji_memakai_akun_5_1001(): void
    {
        $service = app(AutoJournalingService::class);

        $this->assertSame('5-1001', $service->kodeAkunBebanByJenis('gaji'));
    }

    /**
     * Uji end-to-end: input beban ATK <= limit via HTTP harus menghasilkan jurnal
     * DEBET 5-1002 (bukan akun general) dan KREDIT kas.
     */
    public function test_input_beban_atk_lewat_form_membentuk_jurnal_pada_akun_spesifik(): void
    {
        $coaAtk = Coa::findByKode('5-1002');
        $coaKas = $this->coaKas();

        $res = $this->actingAs($this->pengguna('teller'))->post(route('pengeluaran-beban.store'), [
            'tanggal' => self::TAHUN_UJI.'-09-10',
            'jenis_beban' => 'atk',
            'coa_beban' => $coaAtk->id,
            'coa_kas' => $coaKas->id,
            'nominal' => 250000,
            'keterangan' => 'Pembelian ATK kantor — uji',
        ]);

        $res->assertRedirect(route('pengeluaran-beban.index'));
        $res->assertSessionHas('success');

        $record = PengeluaranBeban::where('keterangan', 'Pembelian ATK kantor — uji')->firstOrFail();

        // <= limit -> langsung Approved + jurnal terposting
        $this->assertSame(PengeluaranBeban::STATUS_APPROVED, $record->status);
        $this->assertNotNull($record->jurnal_id, 'Jurnal otomatis wajib terposting.');

        // Akun beban pada record & pada detail jurnal harus 5-1002
        $this->assertSame('5-1002', $record->coaBeban->kode_akun);

        $detailBeban = $record->jurnal->detail->firstWhere('coa_id', $coaAtk->id);
        $this->assertNotNull($detailBeban, 'Detail jurnal DEBET wajib memakai akun 5-1002.');
        $this->assertSame(250000.0, (float) $detailBeban->debet);
        $this->assertSame(0.0, (float) $detailBeban->kredit);

        // Sisi kredit harus akun kas/bank
        $detailKas = $record->jurnal->detail->firstWhere('coa_id', $coaKas->id);
        $this->assertNotNull($detailKas, 'Detail jurnal KREDIT wajib memakai akun kas/bank.');
        $this->assertSame(250000.0, (float) $detailKas->kredit);
    }

    /**
     * Uji jurnal langsung di service: setiap jenis beban memakai akun spesifiknya.
     */
    public function test_service_jurnal_pengeluaran_beban_memakai_akun_spesifik_per_jenis(): void
    {
        $service = app(AutoJournalingService::class);
        $coaKas = $this->coaKas();

        $harapan = [
            '5-1001' => 100000.0,
            '5-1002' => 200000.0,
            '5-1003' => 300000.0,
        ];

        foreach ($harapan as $kodeAkun => $nominal) {
            $jurnal = $service->jurnalPengeluaranBeban(
                $nominal,
                $kodeAkun,
                $coaKas->kode_akun,
                "Uji jurnal beban {$kodeAkun}",
                $this->pengguna('teller')->id,
                null
            );

            $this->assertInstanceOf(Jurnal::class, $jurnal, "Jurnal untuk akun {$kodeAkun} harus terbentuk.");

            $coaBeban = Coa::findByKode($kodeAkun);
            $detailBeban = $jurnal->detail->firstWhere('coa_id', $coaBeban->id);

            $this->assertNotNull($detailBeban, "Jurnal harus mendebet akun {$kodeAkun}.");
            $this->assertSame($nominal, (float) $detailBeban->debet);
        }
    }

    /* ============================================================
     * C. ERROR HANDLING — FALLBACK KE AKUN GENERAL 5-1001
     * ============================================================ */

    public function test_jenis_beban_tidak_dikenal_jatuh_ke_akun_general_tanpa_error(): void
    {
        $service = app(AutoJournalingService::class);

        // Tidak boleh exception; harus mengembalikan akun general
        $kode = $service->kodeAkunBebanByJenis('jenis-yang-tidak-ada');

        $this->assertSame('5-1001', $kode);
    }

    public function test_kode_akun_spesifik_tidak_ada_di_master_coa_dialihkan_ke_akun_general(): void
    {
        $service = app(AutoJournalingService::class);

        // '9-9999' tidak ada di master CoA -> fallback ke general 5-1001
        $kode = $service->kodeAkunBebanTerpakai('9-9999', 'uji fallback');
        $this->assertSame('5-1001', $kode);

        // Kode kosong juga harus fallback, bukan error
        $this->assertSame('5-1001', $service->kodeAkunBebanTerpakai('', 'uji fallback kosong'));
    }

    public function test_jurnal_dengan_kode_akun_tidak_dikenal_tetap_terbentuk_memakai_akun_general(): void
    {
        $service = app(AutoJournalingService::class);
        $coaKas = $this->coaKas();

        $jurnal = $service->jurnalPengeluaranBeban(
            150000.0,
            '9-9999', // kode akun tidak ada di master CoA
            $coaKas->kode_akun,
            'Uji fallback akun general',
            $this->pengguna('teller')->id,
            null
        );

        $this->assertInstanceOf(Jurnal::class, $jurnal, 'Proses jurnal tidak boleh gagal — wajib fallback ke akun general.');

        $coaGeneral = Coa::findByKode(AutoJournalingService::BEBAN_OPERASIONAL);
        $this->assertNotNull($jurnal->detail->firstWhere('coa_id', $coaGeneral->id), 'Jurnal harus memakai akun general 5-1001.');
    }

    /* ============================================================
     * D. ALUR APPROVAL & PENCATATAN JURNAL TANPA ERROR
     * ============================================================ */

    /**
     * Beban di atas limit: tersimpan Pending (belum ada jurnal), tidak masuk card,
     * lalu setelah disetujui Manajer menjadi Approved + jurnal terposting & masuk card.
     */
    public function test_alur_pending_disetujui_manajer_masuk_card_dan_jurnal_terposting(): void
    {
        $coaAtk = Coa::findByKode('5-1002');
        $coaKas = $this->coaKas();

        // 1) Input di atas limit -> Pending, belum ada jurnal
        $this->actingAs($this->pengguna('teller'))
            ->post(route('pengeluaran-beban.store'), [
                'tanggal' => self::TAHUN_UJI.'-10-05',
                'jenis_beban' => 'atk',
                'coa_beban' => $coaAtk->id,
                'coa_kas' => $coaKas->id,
                'nominal' => PengeluaranBeban::LIMIT_APPROVAL + 500000,
                'keterangan' => 'Pengadaan ATK besar — uji approval',
            ])
            ->assertRedirect(route('pengeluaran-beban.index'))
            ->assertSessionHas('warning');

        $record = PengeluaranBeban::where('keterangan', 'Pengadaan ATK besar — uji approval')->firstOrFail();
        $this->assertSame(PengeluaranBeban::STATUS_PENDING, $record->status);
        $this->assertNull($record->jurnal_id, 'Sebelum approval, jurnal belum boleh terposting.');

        // Card pada periode ini TIDAK boleh memuat transaksi Pending tersebut.
        $resSebelum = $this->bukaAudit(['bulan' => self::TAHUN_UJI.'-10']);
        $resSebelum->assertOk();
        $totalSebelum = (float) $resSebelum->viewData('totalApproved');

        // 2) Manajer menyetujui -> Approved + jurnal terposting
        $this->actingAs($this->pengguna('manajer'))
            ->post(route('manajer.beban.approve', $record->id), ['catatan' => 'Disetujui untuk operasional'])
            ->assertRedirect();

        $record->refresh();
        $this->assertSame(PengeluaranBeban::STATUS_APPROVED, $record->status);
        $this->assertNotNull($record->jurnal_id, 'Setelah approval, jurnal wajib terposting.');

        // Jurnal memakai akun spesifik 5-1002
        $detailBeban = $record->jurnal->detail->firstWhere('coa_id', $coaAtk->id);
        $this->assertNotNull($detailBeban, 'Jurnal hasil approval wajib memakai akun spesifik 5-1002.');
        $this->assertSame(1500000.0, (float) $detailBeban->debet);

        // 3) Card setelah approval naik tepat sebesar nominal transaksi
        $resSesudah = $this->bukaAudit(['bulan' => self::TAHUN_UJI.'-10']);
        $resSesudah->assertOk();

        $this->assertSame(
            $totalSebelum + 1500000.0,
            (float) $resSesudah->viewData('totalApproved'),
            'Setelah approval, card harus naik tepat sebesar nominal Pending yang baru disetujui.'
        );
    }

    /**
     * Beban di atas limit yang DITOLAK Manajer: tidak boleh punya jurnal dan
     * tidak boleh masuk ke card 'Total Beban Disetujui'.
     */
    public function test_alur_penolakan_manajer_tidak_membuat_jurnal_dan_tidak_masuk_card(): void
    {
        $coaUtil = Coa::findByKode('5-1003');
        $coaKas = $this->coaKas();

        $this->actingAs($this->pengguna('teller'))
            ->post(route('pengeluaran-beban.store'), [
                'tanggal' => self::TAHUN_UJI.'-11-11',
                'jenis_beban' => 'utilitas',
                'coa_beban' => $coaUtil->id,
                'coa_kas' => $coaKas->id,
                'nominal' => PengeluaranBeban::LIMIT_APPROVAL + 250000,
                'keterangan' => 'Tagihan listrik besar — uji penolakan',
            ])
            ->assertSessionHas('warning');

        $record = PengeluaranBeban::where('keterangan', 'Tagihan listrik besar — uji penolakan')->firstOrFail();
        $this->assertSame(PengeluaranBeban::STATUS_PENDING, $record->status);

        $this->actingAs($this->pengguna('manajer'))
            ->post(route('manajer.beban.reject', $record->id), ['catatan' => 'Tidak sesuai anggaran'])
            ->assertRedirect();

        $record->refresh();
        $this->assertSame(PengeluaranBeban::STATUS_DITOLAK, $record->status);
        $this->assertNull($record->jurnal_id, 'Transaksi Ditolak tidak boleh memiliki jurnal.');

        // Card pada periode itu harus 0 — transaksi Ditolak tidak dihitung.
        $res = $this->bukaAudit(['bulan' => self::TAHUN_UJI.'-11']);
        $res->assertOk();
        $this->assertSame(0.0, (float) $res->viewData('totalApproved'), "Transaksi Ditolak tidak boleh masuk card 'Total Beban Disetujui'.");
    }

    public function test_halaman_audit_dps_dapat_diakses_tanpa_error_untuk_berbagai_filter(): void
    {
        $this->bukaAudit()->assertOk();
        $this->bukaAudit(['bulan' => self::TAHUN_UJI.'-03'])->assertOk();
        $this->bukaAudit(['dari' => self::TAHUN_UJI.'-01-01', 'sampai' => self::TAHUN_UJI.'-12-31'])->assertOk();
        // Input filter tidak wajar tidak boleh menimbulkan error 500
        $this->bukaAudit(['bulan' => 'bukan-bulan', 'dari' => 'bukan-tanggal'])->assertOk();
    }

    /* ============================================================
     * E. KONSISTENSI TAMPILAN VIEW DENGAN LOGIKA BACKEND
     * ============================================================ */

    public function test_form_input_menampilkan_dropdown_jenis_beban_dengan_akun_coa_spesifik(): void
    {
        $res = $this->actingAs($this->pengguna('teller'))->get(route('pengeluaran-beban.index'));
        $res->assertOk();

        // Dropdown jenis beban tersedia dengan akun spesifiknya masing-masing
        $res->assertSee('jenis_beban', false);
        $res->assertSee('5-1001', false);
        $res->assertSee('5-1002', false);
        $res->assertSee('5-1003', false);

        // Field sumber uang/kas tetap ada (tidak hilang saat penambahan dropdown)
        $res->assertSee('coa_kas', false);

        $jenisBebanList = $res->viewData('jenisBebanList');
        $this->assertSame('5-1001', $jenisBebanList['gaji']['kode_akun']);
        $this->assertSame('5-1002', $jenisBebanList['atk']['kode_akun']);
        $this->assertSame('5-1003', $jenisBebanList['utilitas']['kode_akun']);
    }

    public function test_halaman_audit_menampilkan_kode_akun_spesifik_per_baris(): void
    {
        $this->buatBeban('5-1002', 123000, PengeluaranBeban::STATUS_APPROVED, self::TAHUN_UJI.'-12-12');

        $res = $this->bukaAudit(['bulan' => self::TAHUN_UJI.'-12']);
        $res->assertOk();

        // Kolom akun beban menampilkan kode CoA spesifik transaksi (bukan akun general)
        $res->assertSee('5-1002', false);

        // Breakdown akun pada halaman memuat akun spesifik tersebut
        $this->assertContains('5-1002', $res->viewData('breakdownAkun')->pluck('kode_akun')->all());
    }

    /* ============================================================
     * F. PERAPIAN UI — ELEMEN DIHAPUS / DIPERTAHANKAN DENGAN BENAR
     * ============================================================ */

    public function test_card_ringkasan_status_yang_redundan_sudah_dihapus_dari_halaman(): void
    {
        $res = $this->bukaAudit();
        $res->assertOk();

        // Card redundan tidak boleh dirender lagi
        $res->assertDontSee('Ringkasan Status', false);
        $res->assertDontSee('termasuk card', false);
        $res->assertDontSee('dikecualikan', false);
    }

    public function test_tabel_breakdown_tidak_lagi_memuat_teks_harus_sama_dengan_card(): void
    {
        $this->buatBeban('5-1003', 45000, PengeluaranBeban::STATUS_APPROVED, self::TAHUN_UJI.'-12-20');

        $res = $this->bukaAudit(['bulan' => self::TAHUN_UJI.'-12']);
        $res->assertOk();

        $res->assertDontSee('harus sama dengan card', false);
        // Baris Total tetap ada dan Breakdown tetap ditampilkan
        $res->assertSee('Breakdown Akun Beban (Approved)', false);
        $res->assertSee('Total', false);
    }

    public function test_card_total_beban_disetujui_memakai_keterangan_singkat_status_approved(): void
    {
        $res = $this->bukaAudit();
        $res->assertOk();

        $res->assertSee('Status Approved', false);
        // Keterangan lama yang panjang sudah tidak dipakai
        $res->assertDontSee('saja —', false);
    }

    public function test_form_filter_memiliki_skrip_sinkronisasi_bulan_dan_rentang_tanggal(): void
    {
        $res = $this->bukaAudit();
        $res->assertOk();

        $html = $res->getContent();

        // Ketiga input filter tetap ada dengan id yang dirujuk skrip
        foreach (['id="bulan"', 'id="dari"', 'id="sampai"'] as $id) {
            $this->assertStringContainsString($id, $html, "Input filter {$id} harus tetap ada.");
        }

        // Skrip interaktif: menonaktifkan input periode yang tidak dipakai
        $this->assertStringContainsString('aktifkanModeBulan', $html);
        $this->assertStringContainsString('aktifkanModeRentang', $html);
        $this->assertStringContainsString('.disabled', $html);

        // Field penting lain tidak hilang setelah perapian
        $this->assertStringContainsString('Terapkan Filter', $html);
        $this->assertStringContainsString('Daftar Mutasi Beban Operasional', $html);
        $this->assertStringContainsString('Periode aktif', $html);
    }
}
