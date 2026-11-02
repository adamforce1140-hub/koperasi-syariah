<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\ShuDistribution;
use App\Models\ShuPeriod;
use App\Models\User;
use App\Support\Coa as CoaSupport;
use App\Support\HierarkiRole;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * BATCH 21 — Sisa rute Pengurus tanpa rujukan test.
 *
 *   R9  `pengurus.shu.preview`  — pratinjau nominal alokasi (JSON)
 *   R10 `pengurus.shu.store`    — simpan draft periode SHU
 *   R11 `pengurus.shu.show`     — rincian distribusi + seksi penahanan HOLD
 *   R12 `pengurus.cif.export`   — unduhan CSV data anggota (CIF)
 *
 * Yang dikunci adalah integritas angka dan pagar kebijakan:
 * alokasi SHU wajib tepat 100% dan tidak boleh melebihi laba riil pada
 * Ikhtisar SHU (anti-over-distribution), serta ekspor CIF tidak boleh
 * melampaui batas baris peran.
 */
class IntegritasDraftShuDanEksporCifTest extends TestCase
{
    use DatabaseTransactions;

    private User $pengurus;

    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['shu_periods', 'shu_distributions', 'anggota', 'coas'] as $tabel) {
            if (! Schema::hasTable($tabel)) {
                $this->markTestSkipped("Tabel {$tabel} belum tersedia.");
            }
        }

        $this->suffix = strtoupper(substr(uniqid(), -6));

        $this->pengurus = User::firstOrCreate(
            ['email' => 'batch21.draft.shu@test.local'],
            [
                'name' => 'Pengurus Batch 21',
                'password' => Hash::make('rahasiabatch21'),
                'role' => 'pengurus',
            ]
        );

        Setting::flush();
    }

    protected function tearDown(): void
    {
        Setting::flush();

        parent::tearDown();
    }

    /** Label tahun buku unik agar tidak bertabrakan dengan data lain. */
    private function labelTahunBuku(): string
    {
        return 'B21-'.now()->year.'-'.$this->suffix;
    }

    private function muatanDraft(array $ganti = []): array
    {
        return array_merge([
            'tahun_buku' => $this->labelTahunBuku(),
            'total_shu_bersih' => 1000,
            'persen_cadangan' => 20,
            'persen_jasa_modal' => 30,
            'persen_jasa_transaksi' => 30,
            'persen_pengurus' => 10,
            'persen_sosial' => 10,
        ], $ganti);
    }

    /* =====================================================================
     | R9 — PRATINJAU NOMINAL ALOKASI
     ===================================================================== */

    public function test_preview_menghitung_nominal_tiap_porsi_dari_persentase(): void
    {
        $respon = $this->actingAs($this->pengurus)
            ->postJson(route('pengurus.shu.preview'), [
                'total_shu_bersih' => 1000000,
                'persen_cadangan' => 20,
                'persen_jasa_modal' => 30,
                'persen_jasa_transaksi' => 25,
                'persen_pengurus' => 15,
                'persen_sosial' => 10,
            ])
            ->assertOk();

        $data = $respon->json();

        $this->assertEqualsWithDelta(200000, (float) $data['nominal_cadangan'], 0.01);
        $this->assertEqualsWithDelta(300000, (float) $data['nominal_jasa_modal'], 0.01);
        $this->assertEqualsWithDelta(250000, (float) $data['nominal_jasa_transaksi'], 0.01);
        $this->assertEqualsWithDelta(150000, (float) $data['nominal_pengurus'], 0.01);
        $this->assertEqualsWithDelta(100000, (float) $data['nominal_sosial'], 0.01);
        $this->assertEqualsWithDelta(100, (float) $data['total_persen'], 0.01);

        $jumlahNominal = (float) $data['nominal_cadangan']
            + (float) $data['nominal_jasa_modal']
            + (float) $data['nominal_jasa_transaksi']
            + (float) $data['nominal_pengurus']
            + (float) $data['nominal_sosial'];

        $this->assertEqualsWithDelta(1000000, $jumlahNominal, 0.01,
            'Pratinjau alokasi 100% harus habis tepat sebesar total SHU.');
    }

    /* =====================================================================
     | R10 — SIMPAN DRAFT PERIODE SHU
     ===================================================================== */

    public function test_store_menolak_alokasi_yang_tidak_tepat_seratus_persen(): void
    {
        $this->actingAs($this->pengurus)
            ->from(route('pengurus.shu.index'))
            ->post(route('pengurus.shu.store'), $this->muatanDraft([
                'persen_cadangan' => 20,
                'persen_jasa_modal' => 20,
                'persen_jasa_transaksi' => 20,
                'persen_pengurus' => 20,
                'persen_sosial' => 10,
            ]))
            ->assertSessionHasErrors('persen');

        $this->assertFalse(
            ShuPeriod::where('tahun_buku', $this->labelTahunBuku())->exists(),
            'Draft dengan alokasi != 100% tidak boleh tersimpan.'
        );
    }

    public function test_store_menolak_total_shu_melebihi_laba_riil_ikhtisar_shu(): void
    {
        // Pagar anti-over-distribution: SHU tidak boleh melebihi saldo Ikhtisar
        // SHU (laba riil dari Tutup Buku). Nilai sangat besar pasti melampaui.
        $this->actingAs($this->pengurus)
            ->from(route('pengurus.shu.index'))
            ->post(route('pengurus.shu.store'), $this->muatanDraft([
                'total_shu_bersih' => 999999999999,
            ]))
            ->assertSessionHasErrors('total_shu_bersih');

        $this->assertFalse(
            ShuPeriod::where('tahun_buku', $this->labelTahunBuku())->exists(),
            'SHU melebihi laba riil tidak boleh menjadi draft.'
        );
    }

    public function test_store_menolak_tahun_buku_ganda(): void
    {
        $label = $this->labelTahunBuku();

        $existing = new ShuPeriod;
        $existing->forceFill([
            'tahun_buku' => $label,
            'total_shu_bersih' => 1000,
            'status' => 'Draft',
            'is_distributed' => false,
            'user_id' => $this->pengurus->id,
        ])->save();

        $this->actingAs($this->pengurus)
            ->from(route('pengurus.shu.index'))
            ->post(route('pengurus.shu.store'), $this->muatanDraft([
                'tahun_buku' => $label,
            ]))
            ->assertSessionHasErrors('tahun_buku');

        $this->assertSame(1,
            ShuPeriod::where('tahun_buku', $label)->count(),
            'Tahun buku ganda tidak boleh menghasilkan periode kedua.');
    }

    public function test_store_menyimpan_draft_dengan_nominal_alokasi_tersimpan(): void
    {
        $coaIkhtisar = CoaSupport::terdaftar(
            CoaSupport::IKHTISAR_SHU,
            'fixture batch 21 (saldo laba riil)',
            'account_ikhtisar_shu_id'
        );

        if ($coaIkhtisar === null) {
            $this->markTestSkipped('Akun Ikhtisar SHU belum terdaftar di CoA.');
        }

        // Total SHU 0 tidak lolos `min:1`, jadi pagar laba riil diuji dengan
        // batas atas tepat di saldo berjalan.
        $batas = Setting::bulatkanUang((float) $coaIkhtisar->saldoBerjalan());

        if ($batas < 1) {
            $this->markTestSkipped('Saldo Ikhtisar SHU nol — draft tidak dapat diuji.');
        }

        $total = min($batas, 1000.0);
        $label = $this->labelTahunBuku();

        $this->actingAs($this->pengurus)
            ->post(route('pengurus.shu.store'), $this->muatanDraft([
                'tahun_buku' => $label,
                'total_shu_bersih' => $total,
            ]))
            ->assertRedirect(route('pengurus.shu.index'))
            ->assertSessionHas('success');

        $shu = ShuPeriod::where('tahun_buku', $label)->first();
        $this->assertNotNull($shu, 'Draft SHU dengan alokasi 100% harus tersimpan.');
        $this->assertSame('Draft', $shu->status);
        $this->assertEqualsWithDelta($total * 0.20, (float) $shu->nominal_cadangan, 0.01);
        $this->assertEqualsWithDelta($total * 0.10, (float) $shu->nominal_sosial, 0.01);
        $this->assertSame($this->pengurus->id, (int) $shu->user_id,
            'Draft harus tercatat atas nama pembuatnya.');
    }

    /* =====================================================================
     | R11 — RINCIAN DISTRIBUSI (SHOW)
     ===================================================================== */

    public function test_show_menampilkan_baris_distribusi_dan_seksi_penahanan(): void
    {
        $anggotaIds = DB::table('anggota')->limit(2)->pluck('id')->all();

        if (count($anggotaIds) < 2) {
            $this->markTestSkipped('Data anggota tidak cukup untuk fixture distribusi.');
        }

        $periode = new ShuPeriod;
        $periode->forceFill([
            'tahun_buku' => $this->labelTahunBuku(),
            'total_shu_bersih' => 100000,
            'nominal_jasa_modal' => 40000,
            'nominal_jasa_transaksi' => 60000,
            'status' => 'Selesai',
            'is_distributed' => true,
            'diproses_at' => now(),
            'user_id' => $this->pengurus->id,
        ])->save();

        foreach ($anggotaIds as $i => $anggotaId) {
            ShuDistribution::create([
                'shu_period_id' => $periode->id,
                'anggota_id' => (int) $anggotaId,
                'total_simpanan_modal' => 100000 * ($i + 1),
                'monthly_average_balance' => 0,
                'tenure_months' => 12,
                'total_transaksi_bagi_hasil' => 0,
                'shu_jasa_modal' => 20000 * ($i + 1),
                'shu_jasa_transaksi' => 30000,
                'total_shu' => 50000 * ($i + 1),
                'is_hold' => $i === 1,
                'hold_reason' => $i === 1 ? 'Menunggak Simpanan Wajib' : null,
            ]);
        }

        $respon = $this->actingAs($this->pengurus)
            ->get(route('pengurus.shu.show', $periode->id));

        $respon->assertOk();
        $respon->assertSee($periode->tahun_buku);

        $variabel = $respon->viewData('distributions');
        $this->assertCount(2, $variabel, 'Seluruh baris distribusi harus dikirim ke tampilan.');
        $this->assertCount(1, $respon->viewData('holdRows'),
            'Hanya baris berstatus HOLD yang masuk seksi penahanan.');
    }

    public function test_show_id_tidak_ada_mengalihkan_dengan_galat(): void
    {
        $this->actingAs($this->pengurus)
            ->get(route('pengurus.shu.show', 999999999))
            ->assertRedirect(route('pengurus.shu.index'))
            ->assertSessionHas('error');
    }

    /* =====================================================================
     | R12 — EKSPOR CIF (DATA ANGGOTA)
     ===================================================================== */

    public function test_cif_export_tanpa_parameter_excel_hanya_kembali_ke_halaman_sebelumnya(): void
    {
        $this->actingAs($this->pengurus)
            ->from(route('pengurus.pembiayaan.index'))
            ->get(route('pengurus.cif.export'))
            ->assertRedirect(route('pengurus.pembiayaan.index'));
    }

    public function test_cif_export_menghasilkan_csv_berisi_kolom_identitas_anggota(): void
    {
        $this->assertTrue(DB::table('anggota')->exists(),
            'Prasyarat: minimal satu anggota ada.');

        $respon = $this->actingAs($this->pengurus)
            ->get(route('pengurus.cif.export', ['export' => 'excel']));

        $respon->assertOk();
        $this->assertStringContainsString('text/csv', (string) $respon->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $respon->headers->get('Content-Disposition'));

        $isi = $respon->streamedContent();

        $this->assertStringContainsString('No CIF', $isi, 'Judul kolom CIF harus ada.');
        $this->assertStringContainsString('Nama Lengkap', $isi);

        $anggota = DB::table('anggota')->orderBy('no_anggota')->first();
        $this->assertStringContainsString((string) $anggota->no_anggota, $isi,
            'Baris anggota pertama harus ikut terekspor.');
    }

    public function test_cif_export_menolak_unduhan_bila_melebihi_batas_baris_peran(): void
    {
        $jumlah = (int) DB::table('anggota')->count();

        if ($jumlah < 1) {
            $this->markTestSkipped('Prasyarat: minimal satu anggota ada.');
        }

        // Pagar batas baris dibuktikan dengan menurunkan batas peran sementara
        // ke bawah jumlah anggota — bukan menambah ribuan data ke basis uji.
        // Nilai konfigurasi asli dipulihkan tanpa syarat.
        $asli = config('role_hierarki');
        $this->assertIsArray($asli, 'Konfigurasi role_hierarki harus tersedia.');

        try {
            $salinan = $asli;
            $salinan['akses_data']['pengurus']['maks_baris_ekspor'] = 1;
            config(['role_hierarki' => $salinan]);

            $this->assertSame(1, HierarkiRole::aksesData('pengurus', 'maks_baris_ekspor'),
                'Prasyarat: batas ekspor pengurus harus terbaca 1.');

            $this->actingAs($this->pengurus)
                ->from(route('pengurus.pembiayaan.index'))
                ->get(route('pengurus.cif.export', ['export' => 'excel']))
                ->assertRedirect(route('pengurus.pembiayaan.index'))
                ->assertSessionHas('error');
        } finally {
            config(['role_hierarki' => $asli]);
        }

        $this->assertGreaterThan(1, HierarkiRole::aksesData('pengurus', 'maks_baris_ekspor'),
            'Batas ekspor asli harus pulih setelah test.');
    }
}
