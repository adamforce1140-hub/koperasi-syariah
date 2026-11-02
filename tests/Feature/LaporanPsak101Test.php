<?php

namespace Tests\Feature;

use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\LaporanKeuanganLog;
use App\Models\User;
use App\Services\LaporanKeuanganSyariahService;
use App\Support\LaporanKeuanganPermission;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tahap 3 — Pelaporan: Laporan Keuangan Syariah lengkap (PSAK 101).
 *
 * Halaman yang diuji: GET /laporan/keuangan-syariah (route `laporan.psak101`).
 *
 * Cakupan pengujian:
 *   A. Kelengkapan penyajian — kelima komponen PSAK 101 + CaLK benar-benar
 *      ada dan berjudul benar, tidak ada komponen yang hilang.
 *   B. Rekonsiliasi antar-komponen — angka yang sama dihitung dari satu sumber
 *      (mutasi jurnal) harus identik di seluruh komponen:
 *        • Laba/rugi bersih di Neraca = Laba Rugi = Perubahan Ekuitas;
 *        • Total Aset = Total Pasiva (Kewajiban + DST + Ekuitas + Laba berjalan);
 *        • Arus Kas: saldo akhir − saldo awal = Σ(operasi + investasi + pendanaan).
 *   C. Identitas arus kas tahan data nyata — menjumlahkan jurnal kas yang baru
 *      dibuat menggeser `kenaikanKas` dan `saldoAkhirKas` dengan besar yang sama,
 *      sehingga selisih rekonsiliasi tidak berubah (deteksi jurnal tak terlacak).
 *   D. Kemurnian PSAK 109 — dana zakat/infak/sedekah/kebajikan TIDAK pernah
 *      masuk kolom Pendapatan pada Laporan Laba Rugi.
 *   E. RBAC — modul `laporan_psak101` terdaftar di matriks; peran berwenang
 *      boleh membuka halaman; peran tak berwenang ditolak 403 namun tetap
 *      tercatat di log audit; tombol Export hanya muncul bila berhak ekspor.
 *   F. Ekspor Excel — berkas terkirim sebagai attachment dengan 6 komponen.
 *   G. Periode — tanggal di masa depan dijepit ke hari ini (tidak ada laporan
 *      untuk periode yang belum terjadi).
 *
 * Catatan: nilai absolut tidak dijadikan patokan karena basis data dev juga
 * berisi data nyata; pengujian memakai SELISIH (delta) agar deterministik.
 */
class LaporanPsak101Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Peran pada ENUM users.role yang berhak melihat modul PSAK 101.
     *
     * Daftar ini WAJIB sejalan dengan middleware rute
     * `role:manajer,pengurus,dps` — admin memang punya entri `lihat` di matriks
     * RBAC, tetapi rute ini sengaja tidak membuka laporan keuangan konsolidasi
     * untuk admin. Menambah 'admin' ke konstanta ini tanpa mengubah rute akan
     * menghasilkan 403 dan menandakan ketidaksejalanan matriks vs rute.
     */
    private const PERAN_BERWENANG = ['pengurus', 'manajer', 'dps'];

    /** Kode akun kas/bank likuid yang dipantau Laporan Arus Kas. */
    private const KODE_KAS = ['1-1001', '1-1002'];

    /** Judul keenam bagian yang wajib tampil. */
    private const JUDUL_BAGIAN = [
        '1. Laporan Posisi Keuangan',
        '2. Laporan Laba Rugi',
        '3. Laporan Perubahan Ekuitas',
        '4. Laporan Arus Kas',
        '5. Laporan Perubahan Dana Investasi Terikat',
        '6. Catatan atas Laporan Keuangan',
    ];

    private array $pengguna = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teller', 'manajer', 'pengurus', 'anggota', 'ao', 'dps'] as $peran) {
            $this->pengguna[$peran] = User::firstOrCreate(
                ['email' => "psak101.{$peran}@test.local"],
                [
                    'name' => 'Uji PSAK101 '.ucfirst($peran),
                    'password' => Hash::make('password'),
                    'role' => $peran,
                    'is_active' => true,
                ]
            );
        }
    }

    private function sebagai(string $peran): self
    {
        $this->actingAs($this->pengguna[$peran]);

        return $this;
    }

    /** Buka halaman laporan sebagai peran tertentu. */
    private function buka(string $peran = 'pengurus', array $query = [])
    {
        return $this->sebagai($peran)->get(route('laporan.psak101', $query));
    }

    /** Service agregator, dipakai untuk membandingkan UI dengan sumber kebenaran. */
    private function layanan(): LaporanKeuanganSyariahService
    {
        return app(LaporanKeuanganSyariahService::class);
    }

    private function komponen(array $query = []): array
    {
        $periode = $this->layanan()->periode(
            (string) ($query['tanggal'] ?? ''),
            (string) ($query['mode'] ?? '')
        );

        return $this->layanan()->semuaKomponen($periode);
    }

    /** Posting satu jurnal seimbang dua baris (kas ↔ akun lawan). */
    private function jurnalKas(Coa $akunLawan, float $nominal, bool $kasMasuk, string $keterangan = 'Uji PSAK 101'): void
    {
        $kas = Coa::whereIn('kode_akun', self::KODE_KAS)->orderBy('kode_akun')->firstOrFail();

        $jurnal = Jurnal::create([
            'no_jurnal' => 'PSAK101-'.uniqid(),
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'UJI',
            'keterangan' => $keterangan,
            'total_debet' => $nominal,
            'total_kredit' => $nominal,
            'status' => 'Posted',
        ]);

        // Kas masuk  → Debet kas, Kredit lawan.
        // Kas keluar → Kredit kas, Debet lawan.
        JurnalDetail::create([
            'jurnal_id' => $jurnal->id,
            'coa_id' => $kasMasuk ? $kas->id : $akunLawan->id,
            'baris' => 1,
            'debet' => $nominal,
            'kredit' => 0,
        ]);
        JurnalDetail::create([
            'jurnal_id' => $jurnal->id,
            'coa_id' => $kasMasuk ? $akunLawan->id : $kas->id,
            'baris' => 2,
            'debet' => 0,
            'kredit' => $nominal,
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // A. KELENGKAPAN PENYAJIAN — lima komponen + CaLK
    // ═════════════════════════════════════════════════════════════════════

    public function test_halaman_menyajikan_keenam_bagian_psak_101(): void
    {
        $html = $this->buka()->assertOk()->getContent();

        foreach (self::JUDUL_BAGIAN as $judul) {
            $this->assertStringContainsString(
                $judul,
                $html,
                "Bagian \"{$judul}\" wajib tampil pada halaman PSAK 101."
            );
        }
    }

    public function test_service_mengembalikan_seluruh_komponen_yang_dibutuhkan_view(): void
    {
        $k = $this->komponen();

        foreach ([
            'periode', 'posisi', 'labaRugi', 'perubahanEkuitas',
            'arusKas', 'dit', 'kepatuhan', 'kebijakan', 'catatan', 'diagnostik',
            'zakat',
        ] as $kunci) {
            $this->assertArrayHasKey(
                $kunci,
                $k,
                "Service harus menyediakan komponen \"{$kunci}\" agar view tidak kehilangan data."
            );
        }
    }

    public function test_periode_laporan_selalu_setahun_berjalan(): void
    {
        $k = $this->komponen();
        $p = $k['periode'];

        $this->assertSame(
            $p['dari']->toDateString(),
            $p['dari']->copy()->startOfYear()->toDateString(),
            'Awal periode laporan harus 1 Januari tahun berjalan.'
        );
        $this->assertSame(
            $p['dari']->year,
            $p['sampai']->year,
            'Periode laporan tidak boleh melewati batas tahun.'
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // B. REKONSILIASI ANTAR-KOMPONEN
    // ═════════════════════════════════════════════════════════════════════

    public function test_laba_bersih_identik_di_neraca_laba_rugi_dan_perubahan_ekuitas(): void
    {
        $k = $this->komponen();

        $this->assertSame(
            round((float) $k['labaRugi']['labaBersih'], 2),
            round((float) $k['posisi']['labaBersih'], 2),
            'Laba bersih di Neraca harus sama dengan Laba Rugi — keduanya satu sumber.'
        );
        $this->assertSame(
            round((float) $k['labaRugi']['labaBersih'], 2),
            round((float) $k['perubahanEkuitas']['labaBersih'], 2),
            'Laba bersih di Laporan Perubahan Ekuitas harus sama dengan Laba Rugi.'
        );
    }

    public function test_total_aset_sama_dengan_total_pasiva(): void
    {
        $p = $this->komponen()['posisi'];

        $this->assertSame(
            round((float) $p['totalAset'], 2),
            round((float) $p['totalPasiva'], 2),
            'Total Aset harus sama dengan Total Pasiva (Kewajiban + DST + Ekuitas + Laba berjalan).'
        );
        $this->assertTrue(
            $p['seimbang'],
            'Neraca harus dinyatakan seimbang oleh service, bukan hanya kebetulan angkanya sama.'
        );
    }

    public function test_total_pasiva_menjumlahkan_kewajiban_dst_ekuitas_dan_laba_berjalan(): void
    {
        $p = $this->komponen()['posisi'];

        $harapan = round(
            (float) $p['totalKewajiban']
            + (float) $p['totalDanaSyirkahTemporer']
            + (float) $p['totalEkuitas']
            + (float) $p['labaBersih'],
            2
        );

        $this->assertSame(
            $harapan,
            round((float) $p['totalPasiva'], 2),
            'Total Pasiva wajib menjumlahkan keempat komponennya secara eksplisit.'
        );
    }

    public function test_arus_kas_rekonsiliasi_dengan_perubahan_saldo_kas(): void
    {
        $ak = $this->komponen()['arusKas'];

        $this->assertSame(
            round((float) $ak['saldoAkhirKas'] - (float) $ak['saldoAwalKas'], 2),
            round((float) $ak['kenaikanMenurutSaldo'], 2),
            'Kenaikan kas menurut saldo harus = saldo akhir − saldo awal.'
        );

        $this->assertSame(
            round(
                (float) $ak['totalOperasi']
                + (float) $ak['totalInvestasi']
                + (float) $ak['totalPendanaan']
                + (float) $ak['totalLainnya'],
                2
            ),
            round((float) $ak['kenaikanKas'], 2),
            'Kenaikan kas menurut arus harus = Σ(operasi + investasi + pendanaan + lainnya).'
        );
    }

    public function test_dit_mutasi_seimbang_dengan_saldo_akhir(): void
    {
        $dit = $this->komponen()['dit'];

        foreach ($dit['baris'] as $b) {
            $harapan = round(
                (float) $b['saldoAwal'] + (float) $b['penerimaan'] - (float) $b['penyaluran'],
                2
            );
            $this->assertSame(
                $harapan,
                round((float) $b['saldoAkhir'], 2),
                "Baris DIT \"{$b['label']}\": saldo akhir harus = saldo awal + penerimaan − penyaluran."
            );
        }

        $this->assertSame(
            round((float) $dit['totalAwal'] + (float) $dit['totalPenerimaan'] - (float) $dit['totalPenyaluran'], 2),
            round((float) $dit['totalAkhir'], 2),
            'Total DIT harus konsisten dengan total per kolomnya.'
        );
    }

    public function test_ekuitas_saldo_akhir_konsisten_dengan_penambah_pengurang(): void
    {
        $pe = $this->komponen()['perubahanEkuitas'];

        foreach ($pe['baris'] as $r) {
            $harapan = round(
                (float) $r['saldoAwal'] + (float) $r['penambah'] - (float) $r['pengurang'],
                2
            );
            $this->assertSame(
                $harapan,
                round((float) $r['saldoAkhir'], 2),
                "Baris ekuitas \"{$r['nama']}\": saldo akhir harus = saldo awal + penambah − pengurang."
            );
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // C. IDENTITAS ARUS KAS TAHAN DATA NYATA (DELTA)
    // ═════════════════════════════════════════════════════════════════════

    public function test_jurnal_kas_baru_menggeser_arus_kas_sebesar_nominalnya(): void
    {
        $sebelum = $this->komponen()['arusKas'];

        $lawan = Coa::where('kode_akun', '4-1001')->firstOrFail();
        $this->jurnalKas($lawan, 250000.00, true);

        $sesudah = $this->komponen()['arusKas'];

        $this->assertSame(
            round((float) $sebelum['saldoAkhirKas'] + 250000.00, 2),
            round((float) $sesudah['saldoAkhirKas'], 2),
            'Kas masuk harus menambah Saldo Akhir Kas tepat sebesar nominal jurnal.'
        );

        $this->assertSame(
            round((float) $sebelum['kenaikanKas'] + 250000.00, 2),
            round((float) $sesudah['kenaikanKas'], 2),
            'Kas masuk harus menambah Kenaikan Kas menurut Arus Kas tepat sebesar nominal jurnal.'
        );

        $this->assertSame(
            round((float) $sebelum['kenaikanMenurutSaldo'] + 250000.00, 2),
            round((float) $sesudah['kenaikanMenurutSaldo'], 2),
            'Kenaikan menurut saldo harus bergerak sama agar rekonsiliasi tetap utuh.'
        );
    }

    public function test_jurnal_kas_keluar_menggeser_arus_kas_negatif(): void
    {
        $sebelum = $this->komponen()['arusKas'];

        $lawan = Coa::where('kode_akun', '5-1001')->firstOrFail();
        $this->jurnalKas($lawan, 175000.00, false);

        $sesudah = $this->komponen()['arusKas'];

        $this->assertSame(
            round((float) $sebelum['saldoAkhirKas'] - 175000.00, 2),
            round((float) $sesudah['saldoAkhirKas'], 2),
            'Kas keluar harus mengurangi Saldo Akhir Kas tepat sebesar nominal jurnal.'
        );
        $this->assertSame(
            round((float) $sebelum['kenaikanKas'] - 175000.00, 2),
            round((float) $sesudah['kenaikanKas'], 2),
            'Kas keluar harus mengurangi Kenaikan Kas tepat sebesar nominal jurnal.'
        );
    }

    public function test_selisih_rekonsiliasi_tidak_berubah_oleh_jurnal_kas_seimbang(): void
    {
        $sebelum = $this->komponen();

        $this->jurnalKas(Coa::where('kode_akun', '4-1001')->firstOrFail(), 90000.00, true);

        $sesudah = $this->komponen();

        $this->assertSame(
            round((float) $sebelum['arusKas']['selisihRekonsiliasi'], 2),
            round((float) $sesudah['arusKas']['selisihRekonsiliasi'], 2),
            'Jurnal seimbang tidak boleh mengubah selisih rekonsiliasi arus kas.'
        );
        $this->assertSame(
            $sebelum['arusKas']['rekonsiliasiCocok'],
            $sesudah['arusKas']['rekonsiliasiCocok'],
            'Status kecocokan rekonsiliasi tidak boleh goyah oleh jurnal seimbang.'
        );
    }

    public function test_diagnostik_arus_kas_menyatakan_keterangan_yang_dapat_dibaca(): void
    {
        $d = $this->komponen()['diagnostik'];

        $this->assertIsBool($d['terdeteksi']);
        $this->assertNotSame('', trim((string) $d['keterangan']), 'Diagnostik harus punya keterangan.');
        $this->assertSame(
            abs(round((float) $d['selisih'], 2)) > Setting::toleransiSelisihJurnal(),
            $d['terdeteksi'],
            'Status deteksi harus konsisten dengan besarnya selisih terhadap toleransi jurnal.'
        );
    }

    /**
     * TEMUAN E — diagnostik tidak boleh melaporkan "kas tak terjelaskan"
     * selama buku seimbang, APA PUN jenis akun lawannya.
     *
     * Versi lama hanya memantau daftar akun tetap (piutang, margin ditangguhkan,
     * simpanan berjangka), sehingga pendanaan lewat Simpanan Sukarela/Wajib/Pokok,
     * SHU belum dibagi, modal penyertaan, atau dana kebajikan membuat selisih
     * PALSU pada buku yang seimbang.
     */
    public function test_diagnostik_tetap_nol_untuk_pendanaan_yang_tidak_ada_di_daftar_akun_tetap(): void
    {
        $sebelum = $this->komponen()['diagnostik']['selisih'];

        // Akun pendanaan yang tidak pernah masuk daftar akun tetap versi lama.
        $akunPendanaan = ['2-1001', '2-1002', '3-1001', '3-1002', '3-2001', '3-9000', '2-3001'];

        foreach ($akunPendanaan as $kode) {
            $lawan = Coa::where('kode_akun', $kode)->first();

            if (! $lawan) {
                continue;
            }

            $this->jurnalKas($lawan, 1_000_000, true, "Pendanaan {$kode} — uji diagnostik.");
        }

        $sesudah = $this->komponen()['diagnostik'];

        $this->assertSame(
            round((float) $sebelum, 2),
            round((float) $sesudah['selisih'], 2),
            'Selisih diagnostik tidak boleh berubah oleh jurnal yang seimbang '
            .'sekalipun akun lawannya adalah pendanaan/ekuitas/dana titipan.'
        );

        $this->assertFalse(
            $sesudah['terdeteksi'],
            'Buku yang seimbang tidak boleh dilaporkan memiliki mutasi kas tak terjelaskan.'
        );
    }

    /**
     * TEMUAN A — satu jurnal yang memuat DUA baris kas (mis. beban dibayar
     * sebagian tunai dan sebagian transfer) tidak boleh menggandakan atribusi
     * arus kas. Versi lama memasangkan SETIAP baris kas dengan SETIAP baris
     * lawan, sehingga nilai lawan terhitung dua kali dan selisihnya ditutup
     * baris sintetik "Mutasi kas bersih yang belum teratribusi".
     */
    public function test_jurnal_dua_baris_kas_tidak_menggandakan_atribusi_arus_kas(): void
    {
        $kasTeller = Coa::where('kode_akun', '1-1001')->firstOrFail();
        $bank = Coa::where('kode_akun', '1-1002')->firstOrFail();
        $beban = Coa::where('kode_akun', '5-1001')->firstOrFail();

        $dariTeller = 300_000.0;
        $dariBank = 200_000.0;
        $total = $dariTeller + $dariBank;

        $sebelum = $this->komponen()['arusKas'];

        $jurnal = Jurnal::create([
            'no_jurnal' => 'PSAK101-'.uniqid(),
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'UJI',
            'keterangan' => 'Beban dibayar sebagian tunai dan sebagian transfer (uji anti double-count).',
            'total_debet' => $total,
            'total_kredit' => $total,
            'status' => 'Posted',
        ]);

        foreach ([
            [$beban->id, $total, 0],
            [$kasTeller->id, 0, $dariTeller],
            [$bank->id, 0, $dariBank],
        ] as $i => [$coaId, $debet, $kredit]) {
            JurnalDetail::create([
                'jurnal_id' => $jurnal->id,
                'coa_id' => $coaId,
                'baris' => $i + 1,
                'debet' => $debet,
                'kredit' => $kredit,
            ]);
        }

        $sesudah = $this->komponen()['arusKas'];

        $this->assertSame(
            round((float) $sebelum['totalLainnya'], 2),
            round((float) $sesudah['totalLainnya'], 2),
            'Dua baris kas dalam satu jurnal tidak boleh memunculkan baris "Mutasi kas bersih yang belum teratribusi".'
        );

        $this->assertSame(
            round((float) $sebelum['kenaikanMenurutSaldo'] - $total, 2),
            round((float) $sesudah['kenaikanKas'], 2),
            'Kenaikan kas menurut arus harus turun tepat sebesar total pembayaran, bukan dua kali lipatnya.'
        );

        $this->assertSame(
            round((float) $sebelum['selisihRekonsiliasi'], 2),
            round((float) $sesudah['selisihRekonsiliasi'], 2),
            'Rekonsiliasi arus kas vs saldo kas tidak boleh goyah oleh jurnal seimbang.'
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // D. KEMURNIAN PSAK 109 — dana titipan bukan pendapatan
    // ═════════════════════════════════════════════════════════════════════

    public function test_akun_dana_titipan_tidak_pernah_masuk_pendapatan_laba_rugi(): void
    {
        $lr = $this->komponen()['labaRugi'];

        $kodeTerlarang = Coa::whereIn('kode_akun', ['2-3001', '2-3002', '2-3003', '2-3004'])
            ->pluck('kode_akun')
            ->all();

        $kodePendapatan = collect([
            ...$lr['pendapatanUsaha']->pluck('kode')->all(),
            ...$lr['pendapatanLain']->pluck('kode')->all(),
            ...$lr['pendapatanTakKlasifikasi']->pluck('kode')->all(),
        ]);

        foreach ($kodeTerlarang as $kode) {
            $this->assertNotContains(
                $kode,
                $kodePendapatan->all(),
                "Akun dana titipan {$kode} tidak boleh disajikan sebagai pendapatan koperasi (PSAK 109)."
            );
        }
    }

    public function test_mutasi_dana_kebajikan_tidak_mengubah_total_pendapatan(): void
    {
        $sebelum = $this->komponen()['labaRugi'];

        // Dana kebajikan (2-3001) bertambah lewat KREDIT — dana titipan.
        $dana = Coa::where('kode_akun', '2-3001')->firstOrFail();
        $kas = Coa::whereIn('kode_akun', self::KODE_KAS)->orderBy('kode_akun')->firstOrFail();

        $jurnal = Jurnal::create([
            'no_jurnal' => 'PSAK101-DANA-'.uniqid(),
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'UJI',
            'keterangan' => 'Uji kemurnian dana titipan',
            'total_debet' => 120000,
            'total_kredit' => 120000,
            'status' => 'Posted',
        ]);
        JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $kas->id,  'baris' => 1, 'debet' => 120000, 'kredit' => 0]);
        JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $dana->id, 'baris' => 2, 'debet' => 0, 'kredit' => 120000]);

        $sesudah = $this->komponen()['labaRugi'];

        $this->assertSame(
            round((float) $sebelum['totalPendapatan'], 2),
            round((float) $sesudah['totalPendapatan'], 2),
            'Penerimaan dana kebajikan tidak boleh menambah total pendapatan (dana amanah, PSAK 109).'
        );
        $this->assertSame(
            round((float) $sebelum['labaBersih'], 2),
            round((float) $sesudah['labaBersih'], 2),
            'Penerimaan dana titipan tidak boleh mengubah laba bersih koperasi.'
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // E. RBAC & LOG AUDIT
    // ═════════════════════════════════════════════════════════════════════

    public function test_modul_laporan_psak101_terdaftar_di_matriks_hak_akses(): void
    {
        $matriks = LaporanKeuanganPermission::matriksLengkap();

        $this->assertArrayHasKey(
            LaporanKeuanganPermission::MODUL_LAPORAN_PSAK101,
            LaporanKeuanganPermission::LABEL_MODUL,
            'Modul PSAK 101 harus punya label agar tampil benar di matriks.'
        );

        foreach (self::PERAN_BERWENANG as $peran) {
            $this->assertContains(
                LaporanKeuanganPermission::LIHAT,
                $matriks[$peran][LaporanKeuanganPermission::MODUL_LAPORAN_PSAK101] ?? [],
                "Peran {$peran} harus berhak melihat laporan PSAK 101."
            );
        }
    }

    public function test_peran_tak_berwenang_ditolak_403(): void
    {
        foreach (['teller', 'ao', 'anggota'] as $peran) {
            $this->buka($peran)->assertForbidden();
        }
    }

    public function test_peran_berwenang_dapat_membuka_halaman(): void
    {
        foreach (self::PERAN_BERWENANG as $peran) {
            $this->sebagai($peran)
                ->get(route('laporan.psak101'))
                ->assertOk();
        }
    }

    public function test_akses_ditolak_tetap_tercatat_di_log_audit(): void
    {
        $sebelum = LaporanKeuanganLog::where('modul', LaporanKeuanganPermission::MODUL_LAPORAN_PSAK101)->count();

        $this->buka('teller')->assertForbidden();

        $this->assertGreaterThan(
            $sebelum,
            LaporanKeuanganLog::where('modul', LaporanKeuanganPermission::MODUL_LAPORAN_PSAK101)->count(),
            'Percobaan akses tidak sah harus meninggalkan jejak audit meski ditolak.'
        );
    }

    public function test_akses_sah_tercatat_sebagai_lihat(): void
    {
        $this->buka('manajer')->assertOk();

        $log = LaporanKeuanganLog::where('modul', LaporanKeuanganPermission::MODUL_LAPORAN_PSAK101)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($log, 'Akses sah wajib tercatat di log audit.');
        $this->assertSame(LaporanKeuanganPermission::LIHAT, $log->aktivitas);
        $this->assertSame('manajer', $log->role);
    }

    public function test_tombol_ekspor_hanya_tampil_bagi_yang_berhak(): void
    {
        // DPS hanya berhak lihat → tanpa tombol Export Excel.
        $htmlDps = $this->buka('dps')->assertOk()->getContent();
        $this->assertStringNotContainsString(
            'Export Excel',
            $htmlDps,
            'DPS tidak berhak ekspor, tombol Export Excel tidak boleh tampil.'
        );

        // Manajer berhak ekspor → tombol tampil.
        $htmlManajer = $this->buka('manajer')->assertOk()->getContent();
        $this->assertStringContainsString(
            'Export Excel',
            $htmlManajer,
            'Manajer berhak ekspor, tombol Export Excel harus tersedia.'
        );
    }

    public function test_pengunjung_belum_login_diarahkan_ke_halaman_masuk(): void
    {
        $this->get(route('laporan.psak101'))->assertRedirect(route('login'));
    }

    // ═════════════════════════════════════════════════════════════════════
    // F. EKSPOR EXCEL
    // ═════════════════════════════════════════════════════════════════════

    public function test_ekspor_excel_terkirim_sebagai_berkas_unduhan(): void
    {
        $respon = $this->sebagai('pengurus')
            ->get(route('laporan.psak101', ['export' => 'excel']));

        $respon->assertOk();
        $this->assertStringContainsString(
            'attachment',
            (string) $respon->headers->get('Content-Disposition'),
            'Ekspor Excel harus dikirim sebagai lampiran berkas.'
        );
        $this->assertStringContainsString(
            '.xls',
            (string) $respon->headers->get('Content-Disposition'),
            'Nama berkas ekspor harus berekstensi .xls.'
        );
    }

    public function test_ekspor_excel_memuat_seluruh_komponen(): void
    {
        $html = $this->sebagai('pengurus')
            ->get(route('laporan.psak101', ['export' => 'excel']))
            ->assertOk()
            ->getContent();

        // Berkas Excel menulis judul bagian dengan HURUF BESAR (gaya cetak
        // laporan), sedangkan halaman memakai Title Case. Perbandingan karena
        // itu dinormalkan ke huruf kecil agar menguji keberadaan komponen,
        // bukan gaya penulisan judulnya.
        $htmlKecil = mb_strtolower($html);

        foreach ([
            'Laporan Posisi Keuangan',
            'Laporan Laba Rugi',
            'Laporan Perubahan Ekuitas',
            'Laporan Arus Kas',
            'Perubahan Dana Investasi Terikat',
            'Catatan atas Laporan Keuangan',
        ] as $komponen) {
            $this->assertStringContainsString(
                mb_strtolower($komponen),
                $htmlKecil,
                "Berkas ekspor harus memuat komponen \"{$komponen}\"."
            );
        }
    }

    public function test_ekspor_ditolak_bagi_peran_tanpa_hak_ekspor(): void
    {
        // DPS hanya berhak lihat; permintaan ekspor harus dinilai sebagai aksi
        // `ekspor` oleh middleware dan ditolak.
        $this->sebagai('dps')
            ->get(route('laporan.psak101', ['export' => 'excel']))
            ->assertForbidden();
    }

    // ═════════════════════════════════════════════════════════════════════
    // G. PERIODE — perlindungan masa depan
    // ═════════════════════════════════════════════════════════════════════

    public function test_tanggal_masa_depan_dijepit_ke_hari_ini(): void
    {
        $depan = now()->addYears(3)->toDateString();
        $periode = $this->layanan()->periode($depan, 'tanggal');

        $this->assertTrue(
            $periode['sampai']->lte(now()),
            'Laporan tidak boleh disusun untuk periode yang belum terjadi.'
        );
        $this->assertSame(
            now()->toDateString(),
            $periode['tanggalInput'],
            'Tanggal acuan masa depan harus dijepit ke hari ini.'
        );
    }

    public function test_tanggal_tidak_valid_jatuh_kembali_ke_hari_ini(): void
    {
        $periode = $this->layanan()->periode('bukan-tanggal', 'tanggal');

        $this->assertSame(now()->toDateString(), $periode['tanggalInput']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // H. PENYAJIAN AKUN TAK TERKLASIFIKASI — tidak boleh hilang
    // ═════════════════════════════════════════════════════════════════════

    public function test_akun_tak_terklasifikasi_tetap_disajikan_dan_menjadi_temuan(): void
    {
        $kode = '1-9'.substr((string) abs(crc32(uniqid())), 0, 3);

        $coa = Coa::updateOrCreate(
            ['kode_akun' => $kode],
            [
                'nama_akun' => 'UJI TANPA KLASIFIKASI',
                'kategori' => 'Aset',
                'posisi_saldo' => 'Debet',
                'klasifikasi_neraca' => '',
                'saldo_awal' => 0,
                'is_active' => true,
            ]
        );

        try {
            $k = $this->komponen();

            $kodeAset = collect($k['posisi']['aset'])->pluck('kode_akun')->all();
            $this->assertContains(
                $kode,
                $kodeAset,
                'Akun aktif tanpa klasifikasi harus tetap muncul di Neraca (kelompok "Belum Diklasifikasi").'
            );

            $this->assertFalse(
                $k['kepatuhan']['patuh'],
                'Akun tanpa klasifikasi wajib memunculkan temuan penyajian.'
            );
            $this->assertContains(
                $kode,
                $k['kepatuhan']['akunTakKlasifikasi']->pluck('kode_akun')->all(),
                'Akun tanpa klasifikasi harus terdaftar pada daftar temuan CaLK.'
            );
        } finally {
            JurnalDetail::where('coa_id', $coa->id)->delete();
            $coa->delete();
        }
    }
}
