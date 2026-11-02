<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\AuditLog;
use App\Models\Coa;
use App\Models\Deposito;
use App\Models\Jurnal;
use App\Models\KebijakanKoperasi;
use App\Models\MandatorySavingsBill;
use App\Models\MemberTermination;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use App\Notifications\PengunduranDiriApprovalNotification;
use App\Services\AutoJournalingService;
use App\Services\ExitKeanggotaanService;
use App\Services\MandatorySavingsService;
use App\Support\Coa as KodeCoa;
use App\Support\Izin as KodeIzin;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * UJI END-TO-END MODUL PENGUNDURAN DIRI ANGGOTA.
 *
 * Dua skenario utama yang dikunci berkas ini:
 *
 *  SKENARIO 1 — Anggota mengajukan pengunduran diri dan keluar organisasi.
 *    - Pengajuan lewat HTTP menyimpan dokumen + pratinjau saldo apa adanya.
 *    - Status benar: DIAJUKAN (limit 0) atau MENUNGGU_APPROVAL (high value).
 *    - Pengajuan ganda & anggota TERMINATED ditolak tanpa data ganda.
 *    - RBAC: peran tanpa izin ditolak (403).
 *
 *  SKENARIO 2 — Pencairan SELURUH aset/kepentingan anggota setelah keluar.
 *    - Pokok + Wajib + Sukarela + Deposito + Akrual − Zakat − Biaya Admin
 *      dicairkan dalam SATU transaksi atomik.
 *    - Jurnal PSAK 108 seimbang, ledger `transaksi` lengkap per komponen.
 *    - Semua rekening Ditutup (saldo 0), semua deposito CLOSED, CIF
 *      TERMINATED dengan total dana dikembalikan.
 *    - Idempoten: pencairan ulang tidak menggandakan jurnal/transaksi.
 *
 * Ditambah uji regresi untuk temuan audit:
 *    - tolakApproval() menolak dokumen yang sudah disetujui Manajer.
 *    - tolak()/batalkan() menolak status terminal & dokumen terposting,
 *      serta meninggalkan jejak audit.
 */
class ExitEndToEndTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    /** Penomoran fixture agar satu test boleh membuat >1 anggota. */
    private int $urutanAnggota = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'E2E'.strtoupper(substr(uniqid(), -6));

        Setting::flush();

        // Baseline deterministik: jangan bergantung pada nilai kebijakan
        // yang kebetulan tersimpan di basis data pengembangan.
        $this->aturKebijakan([
            'closure_admin_fee' => 0,
            'high_value_withdrawal_limit' => 0,
            'zakat_rate' => 2.50,
            'zakat_nisab_gram' => 85.00,
            'harga_emas_per_gram' => 1500000.00,
            'zakat_haul_aktif' => false,
            'deposito_rate_pa' => 0.08,
        ]);
    }

    protected function tearDown(): void
    {
        Setting::flush();

        parent::tearDown();
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    private function user(string $role = 'manajer'): User
    {
        return User::firstOrCreate(
            ['email' => "{$role}.{$this->prefix}@test.local"],
            [
                'name' => ucfirst($role).' '.$this->prefix,
                'password' => Hash::make('password'),
                'role' => $role,
            ]
        );
    }

    private function aturKebijakan(array $kolom): void
    {
        $kebijakan = KebijakanKoperasi::first() ?? KebijakanKoperasi::create([]);
        $kebijakan->forceFill($kolom)->save();
        Setting::flush();
    }

    private function pastikanCoa(): void
    {
        foreach ([
            KodeCoa::SIMPANAN_POKOK,
            KodeCoa::SIMPANAN_WAJIB_MODAL,
            KodeCoa::SIMPANAN_SUKARELA,
            KodeCoa::SIMPANAN_BERJANGKA,
            KodeCoa::BEBAN_BAGI_HASIL_DEPOSITO,
            KodeCoa::DANA_ZAKAT,
            KodeCoa::PENDAPATAN_ADMIN,
            KodeCoa::KAS_TELLER,
            KodeCoa::BANK_KOPERASI,
        ] as $kode) {
            if (! Coa::findByKode($kode)) {
                $this->markTestSkipped("CoA {$kode} belum tersedia/aktif — jalankan CoaSeeder.");
            }
        }
    }

    private function anggota(): Anggota
    {
        $urut = ++$this->urutanAnggota;

        return Anggota::create([
            'no_anggota' => sprintf('AGT-%s-%02d', $this->prefix, $urut),
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota E2E '.$this->prefix.' #'.$urut,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Alamat Uji E2E Exit',
            'kota' => 'Jakarta',
            'provinsi' => 'DKI Jakarta',
            'status_anggota' => Anggota::STATUS_ACTIVE,
            'is_auto_debit' => false,
            'tanggal_gabung' => '2025-01-01',
        ]);
    }

    private function setorPokok(Anggota $anggota, float $nominal, int $userId, ?string $tanda = null): void
    {
        $jurnal = app(AutoJournalingService::class)->jurnalSetorSimpananPokok(
            $nominal,
            'Setoran Simpanan Pokok uji E2E '.$this->prefix.' #'.$anggota->id.($tanda ? ' '.$tanda : ''),
            $userId,
            null,
            null,
            $anggota->id
        );

        $this->assertNotNull($jurnal, 'Jurnal setoran Simpanan Pokok gagal diposting.');
    }

    private function setorWajib(Anggota $anggota, float $nominal, string $periode = '2026-01'): void
    {
        $tagihan = MandatorySavingsBill::create([
            'member_id' => $anggota->id,
            'bill_period' => $periode,
            'amount' => $nominal,
            'status' => MandatorySavingsBill::STATUS_UNPAID,
        ]);

        app(MandatorySavingsService::class)->bayarViaTeller(
            $tagihan,
            MandatorySavingsBill::METODE_CASH,
            null,
            $this->user()->id
        );
    }

    /** Anggota siap exit: Pokok + Wajib, bebas kewajiban. */
    private function anggotaSiapExit(float $pokok = 100000, float $wajib = 50000): Anggota
    {
        $anggota = $this->anggota();
        $this->setorPokok($anggota, $pokok, $this->user()->id);
        $this->setorWajib($anggota, $wajib);

        return $anggota->refresh();
    }

    /**
     * Anggota dengan SELURUH jenis aset:
     *  - Simpanan Pokok  Rp 100.000
     *  - Simpanan Wajib  Rp  50.000
     *  - Sukarela        Rp  25.000
     *  - Deposito        Rp 200.000 (nisbah anggota 60%)
     *  - Akrual deposito Rp   9.600 (365 hari × 8%/tahun × 60%)
     *
     * @return array{0: Anggota, 1: Rekening, 2: Deposito}
     */
    private function anggotaDenganSeluruhAset(): array
    {
        $teller = $this->user('teller');
        $anggota = $this->anggota();

        $this->setorPokok($anggota, 100000, $teller->id);
        $this->setorWajib($anggota, 50000);

        $akunId = (int) DB::table('akun')->value('id');

        if ($akunId === 0) {
            $akunId = (int) DB::table('akun')->insertGetId([
                'kode_akun' => 'E2E-'.$this->prefix,
                'nama_akun' => 'Akun Uji E2E '.$this->prefix,
                'klasifikasi' => 'Modal',
                'jenis_akun' => 'Detail',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $sukarela = Rekening::create([
            'no_rekening' => 'SUK-'.$this->prefix.'-'.$anggota->id,
            'anggota_id' => $anggota->id,
            'akun_id' => $akunId,
            'jenis_rekening' => 'Simpanan Sukarela',
            'akad' => 'Wadiah',
            'saldo' => 25000,
            'tanggal_buka' => '2025-01-01',
            'status_rekening' => 'Aktif',
        ]);

        $productId = DB::table('deposito_products')->value('id');

        if (! $productId) {
            $productId = DB::table('deposito_products')->insertGetId([
                'name' => 'Deposito Uji E2E '.$this->prefix,
                'tenor_months' => 12,
                'nisbah_anggota' => 60,
                'nisbah_koperasi' => 40,
                'min_amount' => 0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $deposito = Deposito::create([
            'deposito_number' => 'DEP-'.$this->prefix.'-'.$anggota->id,
            'member_id' => $anggota->id,
            'product_id' => $productId,
            'source_saving_account_id' => $sukarela->id,
            'principal_amount' => 200000,
            'nisbah_anggota' => 60,
            'nisbah_koperasi' => 40,
            'start_date' => '2025-01-01',
            'maturity_date' => '2027-01-01',
            'is_aro' => false,
            'status' => Deposito::STATUS_ACTIVE,
        ]);

        return [$anggota->refresh(), $sukarela, $deposito];
    }

    private function service(): ExitKeanggotaanService
    {
        return app(ExitKeanggotaanService::class);
    }

    /** Peta kode akun => [debet, kredit] untuk satu jurnal. */
    private function barisJurnal(int $jurnalId): array
    {
        return DB::table('jurnal_detail as d')
            ->join('coas as c', 'c.id', '=', 'd.coa_id')
            ->where('d.jurnal_id', $jurnalId)
            ->get(['c.kode_akun', 'd.debet', 'd.kredit'])
            ->mapWithKeys(fn ($row) => [
                $row->kode_akun => [
                    'debet' => round((float) $row->debet, 2),
                    'kredit' => round((float) $row->kredit, 2),
                ],
            ])
            ->all();
    }

    /* =====================================================================
     | SKENARIO 1 — PENGAJUAN & KELUAR ORGANISASI
     |===================================================================== */

    public function test_skenario1_pengajuan_via_http_menyimpan_pratinjau_dan_status_diajukan(): void
    {
        $this->pastikanCoa();

        $teller = $this->user('teller');
        $anggota = $this->anggotaSiapExit();

        $response = $this->actingAs($teller)->post(route('member-terminations.store'), [
            'anggota_id' => $anggota->id,
            'tanggal_pengunduran_diri' => '2026-03-15',
            'catatan' => 'Pindah domisili ke luar kota.',
        ]);

        $terminasi = MemberTermination::where('anggota_id', $anggota->id)->firstOrFail();

        $response->assertRedirect(route('member-terminations.show', $terminasi->id));
        $response->assertSessionHas('success');
        $this->assertStringContainsString('Diajukan', (string) session('success'));
        $this->assertStringContainsString('Lanjutkan ke proses pengembalian dana', (string) session('success'));

        // Dokumen tersimpan lengkap dengan pratinjau saldo (tidak ada data hilang).
        $this->assertSame(MemberTermination::STATUS_DIAJUKAN, $terminasi->status);
        $this->assertFalse((bool) $terminasi->butuh_approval);
        $this->assertSame(100000.0, (float) $terminasi->nominal_simpanan_pokok);
        $this->assertSame(50000.0, (float) $terminasi->nominal_simpanan_wajib);
        $this->assertSame(0.0, (float) $terminasi->nominal_sukarela);
        $this->assertSame(0.0, (float) $terminasi->nominal_deposito);
        $this->assertSame(0.0, (float) $terminasi->nominal_zakat);
        $this->assertSame(0.0, (float) $terminasi->nominal_biaya_admin);
        $this->assertSame(150000.0, (float) $terminasi->total_net);
        $this->assertSame(0.0, (float) $terminasi->total_dikembalikan);
        $this->assertSame($teller->id, (int) $terminasi->diajukan_oleh);
        $this->assertNotNull($terminasi->diajukan_pada);
        $this->assertSame('2026-03-15', $terminasi->tanggal_pengunduran_diri?->toDateString());
        $this->assertMatchesRegularExpression('/^EXIT\/\d{6}\/\d{6}$/', (string) $terminasi->no_referensi);

        // Pengajuan belum mengubah status keanggotaan maupun memposting jurnal.
        $this->assertSame(Anggota::STATUS_ACTIVE, $anggota->refresh()->status_anggota);
        $this->assertNull($terminasi->jurnal_id);
        $this->assertSame(0, Transaksi::where('referensi', $terminasi->no_terminasi)->count());
    }

    public function test_skenario1_pengajuan_ganda_dan_anggota_terminated_ditolak_tanpa_data_ganda(): void
    {
        $this->pastikanCoa();

        $teller = $this->user('teller');
        $anggota = $this->anggotaSiapExit();

        $this->actingAs($teller)
            ->post(route('member-terminations.store'), ['anggota_id' => $anggota->id])
            ->assertSessionHas('success');

        // Pengajuan kedua untuk anggota yang sama → ditolak, tidak ada duplikat.
        $response = $this->actingAs($teller)
            ->post(route('member-terminations.store'), ['anggota_id' => $anggota->id]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('masih menunggu proses', (string) session('error'));
        $this->assertSame(1, MemberTermination::where('anggota_id', $anggota->id)->count());

        // Anggota yang sudah TERMINATED tidak dapat mengajukan exit lagi.
        $anggota->forceFill(['status_anggota' => Anggota::STATUS_TERMINATED])->save();

        $response = $this->actingAs($teller)
            ->post(route('member-terminations.store'), ['anggota_id' => $anggota->id]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('TERMINATED', (string) session('error'));
        $this->assertSame(1, MemberTermination::where('anggota_id', $anggota->id)->count());
    }

    public function test_skenario1_high_value_wajib_approval_dan_pencairan_terblokir(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['high_value_withdrawal_limit' => 100000]);

        $teller = $this->user('teller');
        $anggota = $this->anggotaSiapExit(); // bruto 150.000 > limit 100.000

        $response = $this->actingAs($teller)->post(route('member-terminations.store'), [
            'anggota_id' => $anggota->id,
        ]);

        $terminasi = MemberTermination::where('anggota_id', $anggota->id)->firstOrFail();

        $response->assertRedirect(route('member-terminations.show', $terminasi->id));
        $response->assertSessionHas('success');
        $this->assertStringContainsString('Menunggu Persetujuan Manajer', (string) session('success'));
        $this->assertStringContainsString('menunggu persetujuan Manajer', (string) session('success'));

        $this->assertSame(MemberTermination::STATUS_MENUNGGU_APPROVAL, $terminasi->status);
        $this->assertTrue((bool) $terminasi->butuh_approval);
        $this->assertNull($terminasi->approved_at);
        $this->assertSame(150000.0, (float) $terminasi->total_net);

        // Pencairan DIBLOKIR sampai Manajer menyetujui.
        try {
            $this->service()->eksekusi($terminasi, MemberTermination::METODE_CASH, $teller->id);
            $this->fail('Eksekusi seharusnya diblokir sebelum persetujuan Manajer.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('MENUNGGU PERSETUJUAN MANAJER', $e->getMessage());
        }

        $terminasi->refresh();
        $this->assertNull($terminasi->jurnal_id);
        $this->assertSame(0.0, (float) $terminasi->total_dikembalikan);
        $this->assertSame(0, Transaksi::where('referensi', $terminasi->no_terminasi)->count());
        $this->assertSame(Anggota::STATUS_ACTIVE, $anggota->refresh()->status_anggota);
    }

    public function test_skenario1_rbac_peran_tanpa_izin_ditolak(): void
    {
        $this->pastikanCoa();

        $anggota = $this->anggotaSiapExit();

        // Peran `anggota` tidak punya izin `terminasi.pengajuan`.
        $this->actingAs($this->user('anggota'))
            ->post(route('member-terminations.store'), ['anggota_id' => $anggota->id])
            ->assertForbidden();

        $this->assertSame(0, MemberTermination::where('anggota_id', $anggota->id)->count());

        // Teller boleh mengajukan, tetapi TIDAK boleh menyetujui (approval).
        $teller = $this->user('teller');
        $this->actingAs($teller)
            ->post(route('member-terminations.store'), ['anggota_id' => $anggota->id])
            ->assertSessionHas('success');

        $terminasi = MemberTermination::where('anggota_id', $anggota->id)->firstOrFail();

        $this->actingAs($teller)
            ->post(route('member-terminations.setujui', $terminasi->id), ['catatan' => 'coba'])
            ->assertForbidden();

        $this->assertNull($terminasi->refresh()->approved_at);
    }

    /* =====================================================================
     | SKENARIO 2 — PENCAIRAN SELURUH ASET & PENUTUPAN KEPENTINGAN
     |===================================================================== */

    public function test_skenario2_pencairan_seluruh_aset_menutup_rekening_deposito_dan_cif(): void
    {
        $this->pastikanCoa();

        // Nishab dibuat rendah agar komponen zakat ikut teruji (2,5%).
        $this->aturKebijakan([
            'closure_admin_fee' => 5000,
            'zakat_nisab_gram' => 1,
            'harga_emas_per_gram' => 1000,
        ]);

        $teller = $this->user('teller');
        [$anggota, $sukarela, $deposito] = $this->anggotaDenganSeluruhAset();

        $terminasi = $this->service()->ajukan($anggota, [
            'tanggal_pengunduran_diri' => '2026-01-01',
            'catatan' => 'Mengundurkan diri — uji pencairan penuh.',
        ], $teller->id);

        // Pratinjau pengajuan sudah memuat SELURUH komponen aset.
        $this->assertSame(100000.0, (float) $terminasi->nominal_simpanan_pokok);
        $this->assertSame(50000.0, (float) $terminasi->nominal_simpanan_wajib);
        $this->assertSame(25000.0, (float) $terminasi->nominal_sukarela);
        $this->assertSame(200000.0, (float) $terminasi->nominal_deposito);
        $this->assertSame(9600.0, (float) $terminasi->nominal_akrual_bagi_hasil);
        $this->assertSame(9615.0, (float) $terminasi->nominal_zakat);
        $this->assertSame(5000.0, (float) $terminasi->nominal_biaya_admin);
        $this->assertSame(369985.0, (float) $terminasi->total_net);

        $response = $this->actingAs($teller)->post(
            route('member-terminations.eksekusi', $terminasi->id),
            ['metode' => MemberTermination::METODE_CASH]
        );

        $response->assertRedirect(route('member-terminations.show', $terminasi->id));
        $response->assertSessionHas('success');
        $this->assertStringContainsString('Pengembalian dana BERHASIL', (string) session('success'));

        $hasil = $terminasi->refresh();

        // ── Dokumen terminasi ────────────────────────────────────────────
        $this->assertSame(MemberTermination::STATUS_DISETUJUI, $hasil->status);
        $this->assertSame(MemberTermination::METODE_CASH, $hasil->metode);
        $this->assertNotNull($hasil->jurnal_id);
        $this->assertNotNull($hasil->no_jurnal);
        $this->assertNotNull($hasil->transaksi_id);
        $this->assertSame($teller->id, (int) $hasil->diproses_oleh);
        $this->assertNotNull($hasil->diproses_pada);
        $this->assertSame(369985.0, (float) $hasil->total_dikembalikan);

        // ── Jurnal PSAK 108 seimbang & memuat SEMUA komponen ─────────────
        $jurnal = Jurnal::findOrFail($hasil->jurnal_id);
        $this->assertTrue($jurnal->isPosted());

        $baris = $this->barisJurnal($jurnal->id);

        $this->assertSame(100000.0, $baris[KodeCoa::SIMPANAN_POKOK]['debet'] ?? null, 'DEBET Simpanan Pokok.');
        $this->assertSame(50000.0, $baris[KodeCoa::SIMPANAN_WAJIB_MODAL]['debet'] ?? null, 'DEBET Simpanan Wajib.');
        $this->assertSame(25000.0, $baris[KodeCoa::SIMPANAN_SUKARELA]['debet'] ?? null, 'DEBET Simpanan Sukarela.');
        $this->assertSame(200000.0, $baris[KodeCoa::SIMPANAN_BERJANGKA]['debet'] ?? null, 'DEBET Simpanan Berjangka.');
        $this->assertSame(9600.0, $baris[KodeCoa::BEBAN_BAGI_HASIL_DEPOSITO]['debet'] ?? null, 'DEBET Akrual Bagi Hasil.');
        $this->assertSame(9615.0, $baris[KodeCoa::DANA_ZAKAT]['kredit'] ?? null, 'KREDIT Dana Zakat.');
        $this->assertSame(5000.0, $baris[KodeCoa::PENDAPATAN_ADMIN]['kredit'] ?? null, 'KREDIT Pendapatan Admin.');
        $this->assertSame(369985.0, $baris[KodeCoa::KAS_TELLER]['kredit'] ?? null, 'KREDIT Kas Teller = net.');

        $totalDebet = array_sum(array_column($baris, 'debet'));
        $totalKredit = array_sum(array_column($baris, 'kredit'));
        $this->assertSame(384600.0, round($totalDebet, 2), 'Total debet = seluruh bruto aset.');
        $this->assertSame(round($totalDebet, 2), round($totalKredit, 2), 'Jurnal wajib seimbang.');

        // ── Ledger transaksi: satu baris per komponen ────────────────────
        $transaksi = Transaksi::where('referensi', $hasil->no_terminasi)->get();
        $this->assertCount(5, $transaksi, 'Pokok, Wajib, Sukarela, Berjangka, Akrual.');
        $this->assertTrue($transaksi->every(fn ($t) => $t->jenis_transaksi === 'CASH_OUT'));
        $this->assertSame(384600.0, (float) $transaksi->sum('jumlah'));
        $this->assertTrue(
            $transaksi->every(fn ($t) => (int) $t->user_id === $teller->id),
            'Setiap baris transaksi wajib mencatat operator pemroses.'
        );

        // ── SELURUH rekening ditutup, saldo nol, jejak audit penutupan ───
        $semuaRekening = Rekening::where('anggota_id', $anggota->id)->get();
        $this->assertTrue($semuaRekening->isNotEmpty());
        $this->assertTrue(
            $semuaRekening->every(fn ($r) => $r->status_rekening === 'Ditutup'),
            'Semua rekening anggota wajib Ditutup.'
        );
        $this->assertTrue(
            $semuaRekening->every(fn ($r) => (float) $r->saldo === 0.0),
            'Saldo seluruh rekening wajib nol setelah pencairan.'
        );
        $this->assertTrue(
            $semuaRekening->every(fn ($r) => $r->closed_at !== null && (int) $r->closed_by === $teller->id),
            'closed_at/closed_by wajib terisi pada setiap rekening.'
        );

        // ── Deposito ditutup (tidak ada ARO setelah keluar) ──────────────
        $this->assertSame(Deposito::STATUS_CLOSED, $deposito->refresh()->status);

        // ── CIF → TERMINATED + seluruh jejak exit ────────────────────────
        $anggota->refresh();
        $this->assertSame(Anggota::STATUS_TERMINATED, $anggota->status_anggota);
        $this->assertTrue($anggota->isTerminated());
        $this->assertSame(369985.0, (float) $anggota->total_dana_dikembalikan);
        $this->assertSame($teller->id, (int) $anggota->terminasi_diproses_oleh);
        $this->assertSame('2026-01-01', $anggota->tanggal_keluar?->toDateString());
        $this->assertSame('2026-01-01', $anggota->tanggal_terminasi?->toDateString());
        $this->assertSame('2026-01-01', $anggota->exit_date?->toDateString());
        $this->assertSame('Mengundurkan diri — uji pencairan penuh.', $anggota->exit_reason);

        $this->assertTrue(
            AuditLog::where('jenis_transaksi', 'EXIT_KEANGGOTAAN')
                ->where('keterangan', 'like', '%'.$anggota->nama_lengkap.'%')
                ->exists(),
            'Jejak audit EXIT_KEANGGOTAAN wajib tercatat.'
        );
    }

    public function test_skenario2_transfer_bank_menyimpan_tujuan_dan_mengkredit_kas_bank(): void
    {
        $this->pastikanCoa();

        $pengurus = $this->user('pengurus');
        $anggota = $this->anggotaSiapExit(200000, 60000);

        $terminasi = $this->service()->ajukan($anggota, [], $pengurus->id);

        $response = $this->actingAs($pengurus)->post(
            route('member-terminations.eksekusi', $terminasi->id),
            [
                'metode' => MemberTermination::METODE_TRANSFER_BANK,
                'nomor_rekening_tujuan' => '9876543210',
                'nama_bank_tujuan' => 'Bank Syariah Indonesia',
            ]
        );

        $response->assertRedirect(route('member-terminations.show', $terminasi->id));
        $response->assertSessionHas('success');

        $hasil = $terminasi->refresh();

        // Data rekening tujuan dari form TERSIMPAN di dokumen (bukan hilang).
        $this->assertSame('9876543210', $hasil->nomor_rekening_tujuan);
        $this->assertSame('Bank Syariah Indonesia', $hasil->nama_bank_tujuan);
        $this->assertSame(MemberTermination::STATUS_DISETUJUI, $hasil->status);

        $baris = $this->barisJurnal($hasil->jurnal_id);
        $this->assertSame(260000.0, $baris[KodeCoa::BANK_KOPERASI]['kredit'] ?? null, 'KREDIT Kas di Bank = net.');
        $this->assertArrayNotHasKey(KodeCoa::KAS_TELLER, $baris, 'Transfer bank tidak boleh menyentuh Kas Teller.');

        $transaksi = Transaksi::where('referensi', $hasil->no_terminasi)->get();
        $this->assertCount(2, $transaksi);
        $this->assertTrue($transaksi->every(fn ($t) => $t->jenis_transaksi === 'BANK_OUT'));
        $this->assertTrue(
            $transaksi->every(fn ($t) => str_contains((string) $t->catatan, '9876543210')),
            'Keterangan transaksi wajib memuat nomor rekening tujuan.'
        );

        $this->assertSame(Anggota::STATUS_TERMINATED, $anggota->refresh()->status_anggota);
    }

    public function test_skenario2_pencairan_ulang_idempoten_tidak_menggandakan_jurnal_dan_transaksi(): void
    {
        $this->pastikanCoa();

        $teller = $this->user('teller');
        $anggota = $this->anggotaSiapExit();

        $terminasi = $this->service()->ajukan($anggota, [], $teller->id);

        $pertama = $this->service()->eksekusi($terminasi, MemberTermination::METODE_CASH, $teller->id);

        $jumlahJurnal = Jurnal::where('no_jurnal', $pertama->no_jurnal)->count();
        $jumlahTransaksi = Transaksi::where('referensi', $pertama->no_terminasi)->count();
        $saldoTercatat = (float) $anggota->refresh()->total_dana_dikembalikan;

        // Eksekusi ulang (mis. klik ganda / retry jaringan).
        $kedua = $this->service()->eksekusi(
            $pertama->fresh(),
            MemberTermination::METODE_CASH,
            $teller->id
        );

        $this->assertSame($pertama->id, $kedua->id);
        $this->assertSame($pertama->jurnal_id, $kedua->jurnal_id, 'ID jurnal tidak boleh berubah.');
        $this->assertSame($pertama->no_jurnal, $kedua->no_jurnal);
        $this->assertSame(1, Jurnal::where('no_jurnal', $pertama->no_jurnal)->count(), 'Jurnal tidak boleh ganda.');
        $this->assertSame($jumlahJurnal, Jurnal::where('no_jurnal', $pertama->no_jurnal)->count());
        $this->assertSame($jumlahTransaksi, Transaksi::where('referensi', $pertama->no_terminasi)->count());
        $this->assertSame($saldoTercatat, (float) $anggota->refresh()->total_dana_dikembalikan);
        $this->assertSame(1, AuditLog::where('jenis_transaksi', 'EXIT_KEANGGOTAAN')
            ->where('keterangan', 'like', '%'.$anggota->nama_lengkap.'%')
            ->count(), 'Audit exit tidak boleh tercatat dua kali.');
    }

    /* =====================================================================
     | REGRESI TEMUAN AUDIT
     |===================================================================== */

    /**
     * E-1 — Saldo Simpanan Berjangka yang TIDAK punya bilyet `depositos`
     * WAJIB ikut dicairkan.
     *
     * Sebelum perbaikan, `rincianDeposito()` hanya membaca tabel `depositos`.
     * Rekening berjenis Simpanan Berjangka yang bersaldo tanpa baris bilyet
     * (mis. input rekening langsung / migrasi data) tidak terlihat, sehingga
     * komponen Deposito = 0 — padahal penutupan rekening tetap menol-kan
     * saldonya. Akibatnya dana anggota hangus dan GL 2-1003 tidak ter-debit.
     */
    public function test_e1_berjangka_tanpa_bilyet_wajib_ikut_dicairkan(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['closure_admin_fee' => 0]);

        $teller = $this->user('teller');
        $anggota = $this->anggotaSiapExit();

        $berjangka = $this->rekeningBerjangkaTanpaBilyet($anggota, 300000);

        $rincian = $this->service()->rincianSaldo($anggota, now());

        $this->assertSame(
            300000.0,
            (float) $rincian['deposito'],
            'Saldo Simpanan Berjangka tanpa bilyet wajib masuk komponen Deposito.'
        );

        $terminasi = $this->service()->ajukan($anggota, [], $teller->id);
        $this->assertSame(300000.0, (float) $terminasi->nominal_deposito);

        $hasil = $this->service()->eksekusi($terminasi, MemberTermination::METODE_CASH, $teller->id);

        // Total bruto = Pokok 100.000 + Wajib 50.000 + Berjangka 300.000.
        $this->assertSame(450000.0, (float) $hasil->total_dikembalikan);

        $baris = $this->barisJurnal((int) $hasil->jurnal_id);
        $this->assertSame(
            300000.0,
            $baris[KodeCoa::SIMPANAN_BERJANGKA]['debet'] ?? null,
            'GL 2-1003 wajib ter-debit sebesar saldo berjangka.'
        );

        $totalDebet = array_sum(array_column($baris, 'debet'));
        $totalKredit = array_sum(array_column($baris, 'kredit'));
        $this->assertSame(round($totalDebet, 2), round($totalKredit, 2), 'Jurnal wajib seimbang.');

        // Rekening berjangka ditutup & saldonya nol — sejalan dengan nominal
        // yang dibayar, sehingga subledger tetap sama dengan GL.
        $berjangka->refresh();
        $this->assertSame('Ditutup', $berjangka->status_rekening);
        $this->assertSame(0.0, (float) $berjangka->saldo);
    }

    /**
     * E-2 — Rekening berstatus selain `Aktif` (Jatuh Tempo / Dibekukan) juga
     * harus ditutup saat anggota keluar. Bila tidak, kewajiban kepada mantan
     * anggota tertinggal di subledger dan ikut terhitung pada total simpanan.
     */
    public function test_e2_rekening_jatuh_tempo_dan_dibekukan_ikut_ditutup(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['closure_admin_fee' => 0]);

        $teller = $this->user('teller');
        $anggota = $this->anggotaSiapExit();

        $jatuhTempo = $this->rekeningBerjangkaTanpaBilyet(
            $anggota,
            200000,
            ['status_rekening' => 'Jatuh Tempo']
        );

        $dibekukan = Rekening::create([
            'no_rekening' => 'SUK-'.$this->prefix.'-BEKU-'.$anggota->id,
            'anggota_id' => $anggota->id,
            'akun_id' => (int) DB::table('akun')->value('id'),
            'jenis_rekening' => 'Simpanan Sukarela',
            'akad' => 'Wadiah',
            'saldo' => 0,
            'tanggal_buka' => '2025-01-01',
            'status_rekening' => 'Dibekukan',
        ]);

        $terminasi = $this->service()->ajukan($anggota, [], $teller->id);
        $this->service()->eksekusi($terminasi, MemberTermination::METODE_CASH, $teller->id);

        foreach ([$jatuhTempo, $dibekukan] as $rekening) {
            $rekening->refresh();
            $this->assertSame(
                'Ditutup',
                $rekening->status_rekening,
                "Rekening {$rekening->no_rekening} ({$rekening->jenis_rekening}) wajib ditutup saat exit."
            );
            $this->assertSame(0.0, (float) $rekening->saldo);
            $this->assertSame($teller->id, (int) $rekening->closed_by);
        }
    }

    /**
     * Rekening Simpanan Berjangka bersaldo TANPA bilyet `depositos`.
     *
     * Meniru keadaan data nyata: saldo & kewajiban tercatat di subledger
     * `rekening` dan GL 2-1003, tetapi tabel bilyet `depositos` kosong.
     */
    private function rekeningBerjangkaTanpaBilyet(
        Anggota $anggota,
        float $saldo,
        array $atribut = []
    ): Rekening {
        return Rekening::create(array_merge([
            'no_rekening' => 'DEP-'.$this->prefix.'-'.$anggota->id,
            'anggota_id' => $anggota->id,
            'akun_id' => (int) DB::table('akun')->value('id'),
            'jenis_rekening' => 'Simpanan Berjangka',
            'akad' => 'Mudharabah',
            'saldo' => $saldo,
            'tanggal_buka' => now()->subMonths(3)->toDateString(),
            'status_rekening' => 'Aktif',
        ], $atribut));
    }

    public function test_regresi_tolak_approval_setelah_disetujui_tidak_boleh(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['high_value_withdrawal_limit' => 100000]);

        $manajer = $this->user('manajer');
        $anggota = $this->anggotaSiapExit();

        $terminasi = $this->service()->ajukan($anggota, [], $manajer->id);
        $this->assertSame(MemberTermination::STATUS_MENUNGGU_APPROVAL, $terminasi->status);

        $this->service()->setujui($terminasi, 'Disetujui untuk dicairkan.', $manajer->id);

        try {
            $this->service()->tolakApproval($terminasi->fresh(), 'Batal approve.', $manajer->id);
            $this->fail('Dokumen yang sudah disetujui tidak boleh ditolak lagi.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('sudah DISETUJUI Manajer', $e->getMessage());
        }

        $terminasi->refresh();
        $this->assertSame(MemberTermination::STATUS_MENUNGGU_APPROVAL, $terminasi->status);
        $this->assertNotNull($terminasi->approved_at, 'Persetujuan awal tidak boleh hilang.');
    }

    public function test_regresi_tolak_dan_batalkan_status_terminal_ditolak_dengan_jejak_audit(): void
    {
        $this->pastikanCoa();

        $teller = $this->user('teller');

        // ── Tolak pengajuan DIAJUKAN ─────────────────────────────────────
        $anggotaA = $this->anggotaSiapExit();
        $terminasiA = $this->service()->ajukan($anggotaA, [], $teller->id);

        $ditolak = $this->service()->tolak($terminasiA, 'Dokumen tidak lengkap.', $teller->id);
        $this->assertSame(MemberTermination::STATUS_DITOLAK, $ditolak->status);
        $this->assertSame('Dokumen tidak lengkap.', $ditolak->alasan_penolakan);
        $this->assertSame($teller->id, (int) $ditolak->diproses_oleh);
        $this->assertTrue(
            AuditLog::where('jenis_transaksi', 'EXIT_KEANGGOTAAN_DITOLAK')->exists(),
            'Audit EXIT_KEANGGOTAAN_DITOLAK wajib tercatat.'
        );

        // Status terminal: tidak dapat ditolak ulang maupun dibatalkan.
        foreach (['tolak' => 'ditolak lagi', 'batalkan' => 'dibatalkan'] as $aksi => $frasa) {
            try {
                $aksi === 'tolak'
                    ? $this->service()->tolak($ditolak->fresh(), 'Ulang.', $teller->id)
                    : $this->service()->batalkan($ditolak->fresh(), 'Ulang.', $teller->id);
                $this->fail("Dokumen DITOLAK tidak boleh {$aksi} lagi.");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Ditolak', $e->getMessage());
            }
        }

        // ── Batalkan pengajuan DIAJUKAN ──────────────────────────────────
        $anggotaB = $this->anggotaSiapExit();
        $terminasiB = $this->service()->ajukan($anggotaB, [], $teller->id);

        $dibatalkan = $this->service()->batalkan($terminasiB, 'Anggota menarik permohonan.', $teller->id);
        $this->assertSame(MemberTermination::STATUS_DIBATALKAN, $dibatalkan->status);
        $this->assertTrue(
            AuditLog::where('jenis_transaksi', 'EXIT_KEANGGOTAAN_DIBATALKAN')->exists(),
            'Audit EXIT_KEANGGOTAAN_DIBATALKAN wajib tercatat.'
        );

        try {
            $this->service()->tolak($dibatalkan->fresh(), 'Ulang.', $teller->id);
            $this->fail('Dokumen DIBATALKAN tidak boleh ditolak.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Dibatalkan', $e->getMessage());
        }

        // Anggota tetap aktif — penolakan/pembatalan tidak mengubah CIF.
        $this->assertSame(Anggota::STATUS_ACTIVE, $anggotaA->refresh()->status_anggota);
        $this->assertSame(Anggota::STATUS_ACTIVE, $anggotaB->refresh()->status_anggota);
    }

    public function test_regresi_tolak_dan_batalkan_ditolak_setelah_jurnal_diposting(): void
    {
        $this->pastikanCoa();

        $teller = $this->user('teller');
        $anggota = $this->anggotaSiapExit();

        $terminasi = $this->service()->ajukan($anggota, [], $teller->id);
        $hasil = $this->service()->eksekusi($terminasi, MemberTermination::METODE_CASH, $teller->id);

        foreach (['tolak', 'batalkan'] as $aksi) {
            try {
                $aksi === 'tolak'
                    ? $this->service()->tolak($hasil->fresh(), 'Ulang.', $teller->id)
                    : $this->service()->batalkan($hasil->fresh(), 'Ulang.', $teller->id);
                $this->fail("Dokumen terposting tidak boleh {$aksi}.");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('jurnalnya sudah diposting', $e->getMessage());
            }
        }

        $hasil->refresh();
        $this->assertSame(MemberTermination::STATUS_DISETUJUI, $hasil->status);
        $this->assertNotNull($hasil->jurnal_id);
        $this->assertSame(Anggota::STATUS_TERMINATED, $anggota->refresh()->status_anggota);
    }

    /**
     * TEMUAN AUDIT #7 — penerima notifikasi approval wajib mengikuti SSOT
     * RBAC `terminasi.approval`, bukan peran 'manajer' yang di-hardcode.
     */
    public function test_regresi_notifikasi_approval_mengikuti_ssot_izin(): void
    {
        $this->pastikanCoa();
        $this->aturKebijakan(['high_value_withdrawal_limit' => 100000]);

        Notification::fake();

        $penyetuju = array_map(fn (string $r) => $this->user($r), KodeIzin::peran('terminasi.approval'));
        $this->assertNotEmpty($penyetuju, 'SSOT izin terminasi.approval tidak boleh kosong.');

        // Peran di luar SSOT approval tidak boleh menerima permintaan.
        $teller = $this->user('teller');
        $anggota = $this->anggotaSiapExit();

        $terminasi = $this->service()->ajukan($anggota, [], $teller->id);
        $this->assertSame(MemberTermination::STATUS_MENUNGGU_APPROVAL, $terminasi->status);

        Notification::assertSentTo($penyetuju, PengunduranDiriApprovalNotification::class);
        Notification::assertNotSentTo($teller, PengunduranDiriApprovalNotification::class);
        $this->assertContains(
            'pengurus',
            KodeIzin::peran('terminasi.approval'),
            'pengurus adalah penyetuju menurut SSOT; notifikasi harus ikut terkirim.'
        );
    }
}
