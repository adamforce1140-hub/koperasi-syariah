<?php

namespace Tests\Feature;

use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\User;
use App\Services\AutoJournalingService;
use App\Services\JurnalReconciliationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AUDIT PENGISIAN NILAI CHART OF ACCOUNTS (CoA).
 *
 * Menguji bahwa SETIAP akun CoA yang dibebani oleh beragam skenario transaksi
 * benar-benar terisi nilai dengan benar: baris jurnal_detail tertaut ke
 * `coa_id` (bukan null), debet/kredit seimbang, dan saldo akun yang berubah
 * SAMA PERSIS dengan perhitungan manual (double-entry).
 *
 * Cakupan skenario (mewakili seluruh alur pembebanan akun):
 *   1. Penerimaan kas / setoran simpanan   -> 1-1001 D / 2-1001 K
 *   2. Pengeluaran beban operasional (ATK) -> 5-1002 D / 1-1001 K
 *   3. Pengeluaran beban operasional (Util)-> 5-1003 D / 1-1001 K
 *   4. Transfer antar akun (injeksi kas)   -> 1-1001 D / 1-1003 K
 *   5. Penyusutan/penyisihan aset (CKPN)   -> 5-4001 D / 1-2100 K
 *   6. Akrual/penerimaan beban (ta'zir)    -> 2-1001 D / 2-3001 K
 *   7. Angsuran pembiayaan (amortisasi)    -> 1-1001 D / piutang K / 1-2101 D / 4-1001 K
 *
 * Memakai DatabaseTransactions agar tidak mengotori basis data pengembangan.
 */
class AuditPengisianNilaiCoaTest extends TestCase
{
    use DatabaseTransactions;

    /* ==================================================================
     | FIXTURE
     ================================================================== */

    private function pengurus(): User
    {
        return User::firstOrCreate(
            ['email' => 'pengurus.auditcoa@test.local'],
            [
                'name' => 'Pengurus Audit CoA',
                'password' => Hash::make('password'),
                'role' => 'pengurus',
                'is_active' => true,
            ]
        );
    }

    /** Buat/ambil akun CoA; kategori & klasifikasi diturunkan dari Kepala kode. */
    private function akun(string $kode, string $nama, ?string $tipe = null): Coa
    {
        [$kategori, $klasifikasi, $tipeDefault] = match (substr($kode, 0, 1)) {
            '1' => ['Aset', 'Aset Lancar', 'Debet'],
            '2' => ['Kewajiban', 'Kewajiban Jangka Pendek', 'Kredit'],
            '3' => ['Ekuitas', 'Saldo Laba / SHU', 'Kredit'],
            '4' => ['Pendapatan', 'Pendapatan', 'Kredit'],
            default => ['Beban', 'Beban Operasional', 'Debet'],
        };

        return Coa::updateOrCreate(
            ['kode_akun' => $kode],
            [
                'nama_akun' => $nama,
                'kategori' => $kategori,
                'klasifikasi_neraca' => $klasifikasi,
                'tipe_saldo_normal' => $tipe ?? $tipeDefault,
                'saldo_awal' => 0,
                'is_active' => true,
            ]
        );
    }

    /** Bersihkan seluruh jurnal & detail agar setiap test deterministik. */
    private function bersihkanBukuBesar(): void
    {
        JurnalDetail::query()->delete();
        Jurnal::query()->delete();
    }

    private function service(): AutoJournalingService
    {
        return app(AutoJournalingService::class);
    }

    /* ==================================================================
     | ASSERTION BERSAMA
     ================================================================== */

    /**
     * Pastikan jurnal sehat: Posted, balance, dan SEMUA barisnya tertaut ke
     * `coa_id` yang valid (tidak ada akun yang gagal terisi).
     */
    private function assertJurnalSehat(?Jurnal $jurnal, int $jumlahBarisDiharapkan): void
    {
        $this->assertNotNull($jurnal, 'Jurnal wajib terbentuk (tidak null).');

        $segar = $jurnal->fresh();

        $this->assertSame('Posted', $segar->status, 'Jurnal wajib berstatus Posted.');
        $this->assertTrue((bool) $segar->is_balance, 'Jurnal wajib balance (debet = kredit).');

        $this->assertCount(
            $jumlahBarisDiharapkan,
            $segar->detail,
            "Jurnal wajib memiliki {$jumlahBarisDiharapkan} baris detail."
        );

        foreach ($segar->detail as $baris) {
            $this->assertNotNull(
                $baris->coa_id,
                "Baris jurnal id={$baris->id} WAJIB tertaut ke coa_id (bukan null) — pengisian nilai CoA gagal."
            );
            $this->assertNotNull(
                Coa::find($baris->coa_id),
                "coa_id={$baris->coa_id} pada baris id={$baris->id} wajib menunjuk akun CoA yang benar-benar ada."
            );
            $this->assertGreaterThan(
                0.0,
                (float) $baris->debet + (float) $baris->kredit,
                "Baris id={$baris->id} wajib memiliki nilai debet atau kredit > 0 (tidak kosong)."
            );
        }

        // Total debet & kredit header wajib sama dengan jumlah baris — tidak ada
        // nilai yang 'hilang' antara header dan detail.
        $this->assertEquals(
            round($segar->detail->sum('debet'), 2),
            round((float) $segar->total_debet, 2),
            'total_debet header wajib sama dengan Σ debet baris detail.'
        );
        $this->assertEquals(
            round($segar->detail->sum('kredit'), 2),
            round((float) $segar->total_kredit, 2),
            'total_kredit header wajib sama dengan Σ kredit baris detail.'
        );
    }

    /**
     * Assert perubahan saldo satu sisi akun (Debet = +debet−kredit,
     * Kredit = +kredit−debet) sama dengan nilai manual yang diharapkan.
     */
    private function assertPerubahanSaldo(Coa $coa, float $mutasiDebet, float $mutasiKredit, string $label = ''): void
    {
        $saldo = (float) $coa->fresh()->saldoBerjalan();
        $harapan = $coa->fresh()->posisiSaldo() === 'Debet'
            ? $mutasiDebet - $mutasiKredit
            : $mutasiKredit - $mutasiDebet;

        $this->assertEquals(
            round($harapan, 2),
            round($saldo, 2),
            "Saldo akun {$coa->kode_akun} ({$coa->nama_akun}) wajib sama dengan perhitungan manual {$harapan}. {$label}"
        );
    }

    /* ==================================================================
     | SKENARIO 1 — PENERIMAAN KAS / SETORAN SIMPANAN
     ================================================================== */

    public function test_skenario_1_penerimaan_kas_mengisi_kas_teller_dan_simpanan_sukarela(): void
    {
        $this->bersihkanBukuBesar();
        $user = $this->pengurus();

        $kas = $this->akun('1-1001', 'Kas Teller');
        $sukarela = $this->akun('2-1001', 'Simpanan Sukarela Anggota');

        $jurnal = $this->service()->jurnalSetorTunai(250_000, 'Setoran sukarela anggota', $user->id);

        $this->assertJurnalSehat($jurnal, 2);

        // 1-1001 Kas Teller (Debet) +250.000 ; 2-1001 Sukarela (Kredit) +250.000.
        $this->assertPerubahanSaldo($kas, 250_000, 0, 'SKENARIO 1 kas bertambah.');
        $this->assertPerubahanSaldo($sukarela, 0, 250_000, 'SKENARIO 1 simpanan bertambah.');
    }

    /* ==================================================================
     | SKENARIO 2 & 3 — PENGELUARAN BEBAN OPERASIONAL (ATK / UTILITAS)
     ================================================================== */

    public function test_skenario_2_pengeluaran_beban_atk_mengisi_akun_5_1002(): void
    {
        $this->bersihkanBukuBesar();
        $user = $this->pengurus();

        $kas = $this->akun('1-1001', 'Kas Teller');
        $atk = $this->akun('5-1002', 'Beban ATK & Perlengkapan');

        $jurnal = $this->service()->jurnalPengeluaranBeban(
            75_000,
            '5-1002',
            '1-1001',
            'Pembelian alat tulis kantor',
            $user->id
        );

        $this->assertJurnalSehat($jurnal, 2);

        // 5-1002 Beban ATK (Debet) +75.000 ; 1-1001 Kas (Kredit) −75.000.
        $this->assertPerubahanSaldo($atk, 75_000, 0, 'SKENARIO 2 beban ATK bertambah.');
        $this->assertPerubahanSaldo($kas, 0, 75_000, 'SKENARIO 2 kas berkurang.');
    }

    public function test_skenario_3_pengeluaran_beban_utilitas_mengisi_akun_5_1003(): void
    {
        $this->bersihkanBukuBesar();
        $user = $this->pengurus();

        $kas = $this->akun('1-1001', 'Kas Teller');
        $utilitas = $this->akun('5-1003', 'Beban Utilitas / Listrik');

        $jurnal = $this->service()->jurnalPengeluaranBeban(
            320_000,
            '5-1003',
            '1-1001',
            'Pembayaran listrik kantor',
            $user->id
        );

        $this->assertJurnalSehat($jurnal, 2);

        $this->assertPerubahanSaldo($utilitas, 320_000, 0, 'SKENARIO 3 beban utilitas bertambah.');
        $this->assertPerubahanSaldo($kas, 0, 320_000, 'SKENARIO 3 kas berkurang.');
    }

    /* ==================================================================
     | SKENARIO 4 — TRANSFER ANTAR AKUN (INJEKSI KAS BRANKAS → TELLER)
     ================================================================== */

    public function test_skenario_4_transfer_antar_akun_mengisi_kas_teller_dan_brankas(): void
    {
        $this->bersihkanBukuBesar();
        $user = $this->pengurus();

        $kasTeller = $this->akun('1-1001', 'Kas Teller');
        $brankas = $this->akun('1-1003', 'Kas Brankas Utama / Vault');

        $jurnal = $this->service()->jurnalInjeksiKasBrankas(5_000_000, 'Injeksi kas ke laci teller', $user->id);

        $this->assertJurnalSehat($jurnal, 2);

        // 1-1001 (Debet) +5.000.000 ; 1-1003 (Kredit) −5.000.000.
        $this->assertPerubahanSaldo($kasTeller, 5_000_000, 0, 'SKENARIO 4 kas teller bertambah.');
        $this->assertPerubahanSaldo($brankas, 0, 5_000_000, 'SKENARIO 4 brankas berkurang.');
    }

    /* ==================================================================
     | SKENARIO 5 — PENYUSUTAN / PENYISIHAN ASET (CKPN PSAK 71)
     ================================================================== */

    public function test_skenario_5_penyisihan_aset_ckpn_mengisi_5_4001_dan_1_2100(): void
    {
        $this->bersihkanBukuBesar();
        $user = $this->pengurus();

        $bebanCkpn = $this->akun('5-4001', 'Beban Penyisihan Kerugian Piutang Pembiayaan');
        $ckpn = $this->akun('1-2100', 'Penyisihan Kerugian Piutang Pembiayaan', 'Kredit');

        // Jurnal penyesuaian CKPN: DEBET 5-4001 | KREDIT 1-2100 (kontra aset).
        $jurnal = $this->service()->post(
            'Penyesuaian CKPN piutang pembiayaan',
            [
                ['kode' => '5-4001', 'debet' => 1_500_000],
                ['kode' => '1-2100', 'kredit' => 1_500_000],
            ],
            'Penyesuaian',
            null,
            null,
            $user->id
        );

        $this->assertJurnalSehat($jurnal, 2);

        // Beban CKPN bertambah (Debet) ; akun kontra 1-2100 bertambah kredit →
        // saldo NEGATIF (mengurangi total Aset) sesuai PSAK 71.
        $this->assertPerubahanSaldo($bebanCkpn, 1_500_000, 0, 'SKENARIO 5 beban CKPN bertambah.');

        $ckpnSegar = $ckpn->fresh();
        $this->assertTrue($ckpnSegar->isKontra(), 'Akun 1-2100 wajib dikenali sebagai akun KONTRA.');
        $this->assertEquals(
            -1_500_000.0,
            round((float) $ckpnSegar->saldoBerjalan(), 2),
            'Saldo akun kontra 1-2100 wajib NEGATIF (mengurangi total Aset).'
        );
    }

    /* ==================================================================
     | SKENARIO 6 — AKRUAL / PENERIMAAN BEBAN (TA'ZIR KE DANA KEBAJIKAN)
     ================================================================== */

    public function test_skenario_6_akrual_penerimaan_mengisi_dana_kebajikan(): void
    {
        $this->bersihkanBukuBesar();
        $user = $this->pengurus();

        $sukarela = $this->akun('2-1001', 'Simpanan Sukarela Anggota');
        $kebajikan = $this->akun('2-3001', 'Dana Kebajikan');

        // Ta'zir: potongan denda keterlambatan → DEBET 2-1001 | KREDIT 2-3001.
        $jurnal = $this->service()->jurnalTazir(50_000, "Ta'zir keterlambatan angsuran", $user->id);

        $this->assertJurnalSehat($jurnal, 2);

        $this->assertPerubahanSaldo($sukarela, 50_000, 0, "SKENARIO 6 sukarela berkurang (debet ta'zir).");
        $this->assertPerubahanSaldo($kebajikan, 0, 50_000, 'SKENARIO 6 dana kebajikan bertambah.');
    }

    /* ==================================================================
     | SKENARIO 7 — ANGSURAN PEMBIAYAAN (AMORTISASI MARGIN DITANGGUHKAN)
     ================================================================== */

    public function test_skenario_7_angsuran_mengisi_piutang_margin_ditangguhkan_dan_pendapatan(): void
    {
        $this->bersihkanBukuBesar();
        $user = $this->pengurus();

        $kas = $this->akun('1-1001', 'Kas Teller');
        $piutang = $this->akun('1-2001', 'Piutang Murabahah');
        $margin = $this->akun('1-2101', 'Margin Murabahah Ditangguhkan', 'Kredit');
        $pendapatan = $this->akun('4-1001', 'Pendapatan Margin Murabahah');

        // Pokok 1.000.000 + margin 200.000, pembayaran tunai 1.200.000.
        $jurnal = $this->service()->jurnalAngsuran(
            1_000_000,
            200_000,
            0,
            'Angsuran murabahah ke-1',
            $user->id,
            null,
            null,
            'Murabahah'
        );

        $this->assertJurnalSehat($jurnal, 4);

        $this->assertPerubahanSaldo($kas, 1_200_000, 0, 'SKENARIO 7 kas bertambah sebesar total angsuran.');
        // Piutang Murabahah dikredit 1.200.000 (pokok+margin) → saldo turun 1.200.000.
        $this->assertPerubahanSaldo($piutang, 0, 1_200_000, 'SKENARIO 7 piutang berkurang.');
        // Margin ditangguhkan didebet 200.000 (akun posisi Kredit) → saldo turun 200.000.
        $this->assertPerubahanSaldo($margin, 200_000, 0, 'SKENARIO 7 margin ditangguhkan diamortisasi.');
        // Pendapatan margin diakui (Kredit) +200.000.
        $this->assertPerubahanSaldo($pendapatan, 0, 200_000, 'SKENARIO 7 pendapatan margin diakui.');
    }

    /* ==================================================================
     | AUDIT REKONSILIASI & KESETARAAN MANUAL
     ================================================================== */

    /**
     * Setelah SELURUH skenario dijalankan berturut-turut, buku besar wajib
     * SEIMBANG (Σ debet = Σ kredit) dan rekonsiliasi tidak menemukan temuan.
     */
    public function test_rekonsiliasi_buku_besar_seimbang_setelah_semua_skenario(): void
    {
        $this->bersihkanBukuBesar();
        $user = $this->pengurus();

        $this->akun('1-1001', 'Kas Teller');
        $this->akun('1-1003', 'Kas Brankas Utama / Vault');
        $this->akun('2-1001', 'Simpanan Sukarela Anggota');
        $this->akun('2-3001', 'Dana Kebajikan');
        $this->akun('1-2001', 'Piutang Murabahah');
        $this->akun('1-2100', 'Penyisihan Kerugian Piutang Pembiayaan', 'Kredit');
        $this->akun('1-2101', 'Margin Murabahah Ditangguhkan', 'Kredit');
        $this->akun('4-1001', 'Pendapatan Margin Murabahah');
        $this->akun('5-1002', 'Beban ATK & Perlengkapan');
        $this->akun('5-1003', 'Beban Utilitas / Listrik');
        $this->akun('5-4001', 'Beban Penyisihan Kerugian Piutang Pembiayaan');

        $svc = $this->service();

        // Skenario 1 — penerimaan kas.
        $svc->jurnalSetorTunai(250_000, 'Setoran sukarela', $user->id);
        // Skenario 2 — beban ATK.
        $svc->jurnalPengeluaranBeban(75_000, '5-1002', '1-1001', 'Beli ATK', $user->id);
        // Skenario 3 — beban utilitas.
        $svc->jurnalPengeluaranBeban(320_000, '5-1003', '1-1001', 'Bayar listrik', $user->id);
        // Skenario 4 — transfer antar akun.
        $svc->jurnalInjeksiKasBrankas(5_000_000, 'Injeksi kas', $user->id);
        // Skenario 5 — penyisihan aset (CKPN).
        $svc->post('Penyesuaian CKPN', [
            ['kode' => '5-4001', 'debet' => 1_500_000],
            ['kode' => '1-2100', 'kredit' => 1_500_000],
        ], 'Penyesuaian', null, null, $user->id);
        // Skenario 6 — akrual beban (ta'zir).
        $svc->jurnalTazir(50_000, "Ta'zir", $user->id);
        // Skenario 7 — angsuran pembiayaan.
        $svc->jurnalAngsuran(1_000_000, 200_000, 0, 'Angsuran', $user->id, null, null, 'Murabahah');

        // ── 1. Σ debet = Σ kredit buku besar (selisih ≤ toleransi kebijakan).
        $totalDebet = (float) JurnalDetail::query()
            ->join('jurnal', 'jurnal.id', '=', 'jurnal_detail.jurnal_id')
            ->where('jurnal.status', 'Posted')
            ->sum('jurnal_detail.debet');
        $totalKredit = (float) JurnalDetail::query()
            ->join('jurnal', 'jurnal.id', '=', 'jurnal_detail.jurnal_id')
            ->where('jurnal.status', 'Posted')
            ->sum('jurnal_detail.kredit');

        $this->assertEquals(
            round($totalDebet, 2),
            round($totalKredit, 2),
            'Σ debet WAJIB sama dengan Σ kredit pada buku besar.'
        );

        // ── 2. Tidak ada baris detail yang gagal terisi coa_id.
        $detailTanpaCoa = JurnalDetail::query()->whereNull('coa_id')->count();
        $this->assertSame(0, $detailTanpaCoa, 'Tidak boleh ada baris jurnal tanpa coa_id.');

        // ── 3. Rekonsiliasi menyeluruh tidak menemukan temuan.
        $hasil = app(JurnalReconciliationService::class)->rekonsiliasiBukuBesar();

        $this->assertTrue(
            $hasil['seimbang'],
            'Buku besar wajib seimbang. Temuan: '.json_encode($hasil['temuan'])
        );
        $this->assertSame(
            [],
            $hasil['temuan'],
            'Rekonsiliasi buku besar wajib bersih. Temuan: '.json_encode($hasil['temuan'])
        );
        $this->assertEquals(
            round($hasil['total_debet'], 2),
            round($hasil['total_kredit'], 2),
            'Total debet dan kredit rekonsiliasi wajib sama.'
        );
    }

    /**
     * Saldo akhir setiap akun yang dibebani sama dengan perhitungan manual
     * langsung dari agregat jurnal_detail (verifikasi ganda CoA).
     */
    public function test_saldo_akhir_semua_akun_sama_dengan_hitung_ulang_manual(): void
    {
        $this->bersihkanBukuBesar();
        $user = $this->pengurus();

        $kodeList = [
            '1-1001' => 'Kas Teller',
            '2-1001' => 'Simpanan Sukarela Anggota',
            '5-1002' => 'Beban ATK & Perlengkapan',
            '2-3001' => 'Dana Kebajikan',
        ];

        foreach ($kodeList as $kode => $nama) {
            $this->akun($kode, $nama);
        }

        $svc = $this->service();
        $svc->jurnalSetorTunai(1_000_000, 'Setoran', $user->id);
        $svc->jurnalPengeluaranBeban(75_000, '5-1002', '1-1001', 'ATK', $user->id);
        $svc->jurnalTazir(50_000, "Ta'zir", $user->id);

        foreach ($kodeList as $kode => $nama) {
            $coa = Coa::findByKode($kode);

            $sumDebet = (float) JurnalDetail::query()
                ->join('jurnal', 'jurnal.id', '=', 'jurnal_detail.jurnal_id')
                ->where('jurnal.status', 'Posted')
                ->where('jurnal_detail.coa_id', $coa->id)
                ->sum('jurnal_detail.debet');
            $sumKredit = (float) JurnalDetail::query()
                ->join('jurnal', 'jurnal.id', '=', 'jurnal_detail.jurnal_id')
                ->where('jurnal.status', 'Posted')
                ->where('jurnal_detail.coa_id', $coa->id)
                ->sum('jurnal_detail.kredit');

            $hitungUlangManual = $coa->posisiSaldo() === 'Debet'
                ? (float) $coa->saldo_awal + $sumDebet - $sumKredit
                : (float) $coa->saldo_awal + $sumKredit - $sumDebet;

            $this->assertEquals(
                round($hitungUlangManual, 2),
                round((float) $coa->fresh()->saldoBerjalan(), 2),
                "Saldo akun {$kode} ({$nama}) dari jalur model wajib sama dengan hitung ulang manual dari jurnal_detail."
            );
        }
    }
}
