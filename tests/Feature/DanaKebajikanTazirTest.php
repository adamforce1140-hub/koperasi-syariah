<?php

namespace Tests\Feature;

use App\Exceptions\JournalPostingException;
use App\Models\Anggota;
use App\Models\ApprovalTransaksi;
use App\Models\Coa;
use App\Models\DanaKebajikan;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\KebijakanKoperasi;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\AutoJournalingService;
use App\Services\DanaKebajikanService;
use App\Services\ReversalService;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Uji modul Dana Kebajikan / Ta'zir.
 *
 * Memverifikasi:
 *  1. Posisi jurnal akun 2-3001 — KREDIT untuk semua penerimaan (auto-debet & tunai
 *     teller), DEBET HANYA untuk penyaluran dana sosial keluar, plus validasi sistem
 *     yang menolak posisi tidak sah.
 *  2. Integrasi transaksi tunai teller ke riwayat + pengecekan duplikasi + logging.
 *  3. Card summary dihitung dari mutasi jurnal 2-3001 (Terkumpul − Disalurkan).
 */
class DanaKebajikanTazirTest extends TestCase
{
    use DatabaseTransactions;

    private AutoJournalingService $jurnal;

    private DanaKebajikanService $service;

    private Coa $coaDana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jurnal = app(AutoJournalingService::class);
        $this->service = app(DanaKebajikanService::class);
        $this->coaDana = Coa::findByKode(AutoJournalingService::DANA_KEBAJIKAN);

        if (! $this->coaDana) {
            $this->markTestSkipped('CoA 2-3001 (Dana Kebajikan) belum ter-seed.');
        }

        /* PSAK 108/109 — denda Ta'zir hanya sah bila fitur diaktifkan pengurus
         | melalui parameter `financing_late_fee_per_day`. Suite ini menguji
         | alur ta'zir, sehingga fitur tersebut WAJIB dinyalakan lebih dulu.
         | (Pengujian penolakan saat fitur NONAKTIF ada di Psak108PemisahanTest.) */
        $this->aktifkanFiturDenda(2500.00);
    }

    /** Nyalakan fitur denda keterlambatan & sinkronkan cache Setting. */
    private function aktifkanFiturDenda(float $perHari = 2500.00): void
    {
        $kebijakan = KebijakanKoperasi::first()
            ?? new KebijakanKoperasi;

        $kebijakan->tazir_mode = 'FLAT_HARIAN';
        $kebijakan->financing_late_fee_per_day = $perHari;
        $kebijakan->save();

        Setting::flush();
    }

    private function teller(): User
    {
        return User::firstOrCreate(
            ['email' => 'teller.danakebajikan@example.test'],
            ['name' => 'Teller Dana Kebajikan Test', 'password' => Hash::make('secret'), 'role' => 'teller', 'is_active' => true]
        );
    }

    /** Checker void — GCG: pengaju void tidak boleh mengeksekusi void-nya sendiri. */
    private function manajer(): User
    {
        return User::firstOrCreate(
            ['email' => 'manajer.danakebajikan@example.test'],
            ['name' => 'Manajer Dana Kebajikan Test', 'password' => Hash::make('secret'), 'role' => 'manajer', 'is_active' => true]
        );
    }

    /** Total debet/kredit mutasi akun 2-3001 pada satu jurnal tertentu. */
    private function mutasi(Jurnal $jurnal): array
    {
        $rows = JurnalDetail::where('jurnal_id', $jurnal->id)
            ->where('coa_id', $this->coaDana->id)
            ->get();

        return [
            'debet' => (float) $rows->sum('debet'),
            'kredit' => (float) $rows->sum('kredit'),
        ];
    }

    /* =====================================================================
     | 1. POSISI JURNAL DENDA TA'ZIR
     ===================================================================== */

    public function test_jurnal_ta_zir_mencatat_kredit_pada_akun_dana_kebajikan(): void
    {
        $jurnal = $this->jurnal->jurnalTazir(50_000, "Ta'zir uji otomatis", $this->teller()->id);

        $this->assertNotNull($jurnal, 'Jurnal ta\'zir harus berhasil diposting.');

        $mutasi = $this->mutasi($jurnal);

        $this->assertSame(0.0, $mutasi['debet'], 'Akun 2-3001 TIDAK boleh berposisi Debet untuk pemotongan ta\'zir.');
        $this->assertSame(50_000.0, $mutasi['kredit'], 'Pemotongan ta\'zir wajib KREDIT pada akun 2-3001.');
    }

    public function test_auto_debet_ta_zir_mencatat_kredit_pada_akun_dana_kebajikan(): void
    {
        // Skenario auto-debet: KREDIT 2-3001 | DEBET simpanan anggota.
        $jurnal = $this->jurnal->post("Pemotongan ta'zir autodebet", [
            ['kode' => AutoJournalingService::SIMPANAN_SUKARELA, 'debet' => 75_000],
            ['kode' => AutoJournalingService::DANA_KEBAJIKAN,    'kredit' => 75_000],
        ], "Ta'zir", null, null, $this->teller()->id);

        $this->assertNotNull($jurnal);

        $mutasi = $this->mutasi($jurnal);
        $this->assertSame(0.0, $mutasi['debet']);
        $this->assertSame(75_000.0, $mutasi['kredit']);
    }

    public function test_debet_akun_dana_kebajikan_ditolak_untuk_penerimaan(): void
    {
        // Pelanggaran aturan: ta'zir (dana masuk) dicatat DEBET pada 2-3001.
        // Sistem kini MELEMPAR exception (bukan mengembalikan null) sehingga
        // DB::transaction pemanggil ikut rollback — jurnal tidak masuk buku besar.
        $this->expectException(JournalPostingException::class);

        $this->jurnal->post("Ta'zir salah posisi", [
            ['kode' => AutoJournalingService::DANA_KEBAJIKAN,    'debet' => 40_000],
            ['kode' => AutoJournalingService::SIMPANAN_SUKARELA, 'kredit' => 40_000],
        ], "Ta'zir", null, null, $this->teller()->id);
    }

    public function test_validasi_posisi_dana_kebajikan_langsung(): void
    {
        $svc = $this->jurnal;

        // KREDIT → selalu sah
        $this->assertTrue($svc->posisiDanaKebajikanValid('2-3001', 0, 10_000, "Ta'zir"));
        // DEBET dengan sumber sembarang → tidak sah
        $this->assertFalse($svc->posisiDanaKebajikanValid('2-3001', 10_000, 0, "Ta'zir"));
        $this->assertFalse($svc->posisiDanaKebajikanValid('2-3001', 10_000, 0, 'Jurnal Umum'));
        // DEBET dengan sumber penyaluran → sah
        $this->assertTrue($svc->posisiDanaKebajikanValid(
            '2-3001', 10_000, 0, AutoJournalingService::SUMBER_PENYALURAN_DANA_KEBAJIKAN
        ));
        // Akun lain tidak terkena aturan
        $this->assertTrue($svc->posisiDanaKebajikanValid('1-1001', 10_000, 0, 'Jurnal Umum'));
    }

    public function test_penyaluran_dana_menghasilkan_debet_pada_akun_dana_kebajikan(): void
    {
        $jurnal = $this->jurnal->jurnalPenyaluranDanaKebajikan(
            100_000,
            'Penyaluran bantuan sosial kepada mustahiq uji',
            $this->teller()->id
        );

        $this->assertNotNull($jurnal, 'Jurnal penyaluran harus berhasil diposting.');

        $mutasi = $this->mutasi($jurnal);
        $this->assertSame(100_000.0, $mutasi['debet'], 'Penyaluran wajib DEBET pada akun 2-3001.');
        $this->assertSame(0.0, $mutasi['kredit']);
    }

    /* =====================================================================
     | 2. INTEGRASI TRANSAKSI TUNAI TELLER
     ===================================================================== */

    public function test_transaksi_tunai_teller_tersinkron_ke_riwayat_dan_jurnal(): void
    {
        $teller = $this->teller();
        $transaksi = $this->buatTransaksiTunai($teller, "Pemotongan Ta'zir Keterlambatan", 30_000);

        $hasil = $this->service->sinkronkanTransaksiTunaiTeller($transaksi);

        $this->assertNotNull($hasil, 'Transaksi tunai teller harus tercatat di Dana Kebajikan.');
        $this->assertSame('Penerimaan', $hasil->jenis);
        $this->assertSame(30_000.0, (float) $hasil->jumlah);

        // Riwayat modul ini harus memuat transaksi tunai tersebut.
        $adaDiRiwayat = $this->service->riwayat(25)
            ->getCollection()
            ->contains(fn ($t) => $t->id === $transaksi->id);
        $this->assertTrue($adaDiRiwayat, 'Transaksi tunai teller harus muncul di riwayat Dana Kebajikan.');

        $this->assertTrue($this->service->sudahTercatat($transaksi));
    }

    public function test_sinkronisasi_tidak_menduplikasi_pencatatan(): void
    {
        $teller = $this->teller();
        $transaksi = $this->buatTransaksiTunai($teller, 'Infak Tunai', 25_000);

        $pertama = $this->service->sinkronkanTransaksiTunaiTeller($transaksi);
        $kedua = $this->service->sinkronkanTransaksiTunaiTeller($transaksi);
        $ketiga = $this->service->sinkronkanTransaksiTunaiTeller($transaksi);

        $this->assertNotNull($pertama);
        $this->assertSame($pertama->id, $kedua->id, 'Sinkronisasi ulang harus mengembalikan baris yang sama.');
        $this->assertSame($pertama->id, $ketiga->id);

        $jumlahBaris = DanaKebajikan::where('no_referensi', 'DK-TRX-'.$transaksi->no_transaksi)->count();
        $this->assertSame(1, $jumlahBaris, 'Pengecekan duplikasi harus mencegah baris ganda.');
    }

    public function test_transaksi_non_kebajikan_tidak_disinkronkan(): void
    {
        $teller = $this->teller();
        $transaksi = $this->buatTransaksiTunai($teller, 'Setoran Tunai', 500_000);

        $this->assertNull($this->service->sinkronkanTransaksiTunaiTeller($transaksi));
        $this->assertFalse($this->service->sudahTercatat($transaksi));
    }

    public function test_transaksi_tunai_void_tidak_disinkronkan(): void
    {
        $teller = $this->teller();
        $transaksi = $this->buatTransaksiTunai($teller, "Pemotongan Ta'zir Keterlambatan", 20_000);
        $transaksi->update(['status_transaksi' => 'Voided']);

        $this->assertNull($this->service->sinkronkanTransaksiTunaiTeller($transaksi->fresh()));
    }

    public function test_kegagalan_sinkronisasi_tercatat_di_log_dan_audit(): void
    {
        $teller = $this->teller();
        $transaksi = $this->buatTransaksiTunai($teller, "Pemotongan Ta'zir Keterlambatan", 10_000);

        // Paksa kegagalan: akun 2-3001 dinonaktifkan sementara → posting jurnal gagal.
        $this->coaDana->update(['is_active' => false]);

        try {
            // Pencatatan Dana Kebajikan tanpa jurnal = dana masuk tanpa pengakuan
            // double-entry (PSAK 109). Kegagalan jurnal WAJIB melempar exception
            // agar pemanggil (DB::transaction) me-rollback seluruh perubahan,
            // bukan ditelan diam-diam.
            $this->expectException(\RuntimeException::class);
            $this->service->sinkronkanTransaksiTunaiTeller($transaksi);
        } finally {
            $this->coaDana->update(['is_active' => true]);
        }

        // Tanpa paksaan, sinkronisasi harus berhasil penuh.
        DanaKebajikan::where('no_referensi', 'DK-TRX-'.$transaksi->no_transaksi)->delete();
        $this->assertNotNull($this->service->sinkronkanTransaksiTunaiTeller($transaksi->fresh()));
    }

    public function test_sinkronisasi_tertunda_melewati_yang_sudah_tercatat(): void
    {
        $teller = $this->teller();

        $a = $this->buatTransaksiTunai($teller, "Pemotongan Ta'zir Keterlambatan", 11_000);
        $b = $this->buatTransaksiTunai($teller, 'Infak Tunai', 12_000);

        // Satu sudah tersinkron lebih dulu.
        $this->service->sinkronkanTransaksiTunaiTeller($a);

        $hasil = $this->service->sinkronkanTunaiTellerTertunda(200);

        $this->assertTrue($this->service->sudahTercatat($a));
        $this->assertTrue($this->service->sudahTercatat($b));
        $this->assertGreaterThanOrEqual(1, $hasil['dilewati'], 'Transaksi yang sudah tercatat harus dilewati, bukan digandakan.');
    }

    public function test_auto_debet_memakai_simpanan_anggota_bukan_kas_teller(): void
    {
        $teller = $this->teller();
        $anggota = $this->anggotaDenganRekening();
        $transaksi = $this->buatTransaksiTunai($teller, "Pemotongan Ta'zir Keterlambatan", 30_000, $anggota->rekening_id, $anggota->id);

        $this->assertNotNull($this->service->sinkronkanTransaksiTunaiTeller($transaksi));

        $jurnal = Jurnal::where('transaksi_id', $transaksi->id)->first();
        $this->assertNotNull($jurnal, 'Auto-debet harus menghasilkan jurnal.');

        $coaKas = Coa::findByKode(AutoJournalingService::KAS_TELLER);
        $coaSimpanan = Coa::findByKode(AutoJournalingService::SIMPANAN_SUKARELA);

        $debetSimpanan = (float) JurnalDetail::where('jurnal_id', $jurnal->id)->where('coa_id', $coaSimpanan->id)->sum('debet');
        $debetKas = (float) JurnalDetail::where('jurnal_id', $jurnal->id)->where('coa_id', $coaKas->id)->sum('debet');

        $this->assertSame(30_000.0, $debetSimpanan, 'Auto-debet harus mendebet Simpanan Sukarela anggota.');
        $this->assertSame(0.0, $debetKas, 'Auto-debet TIDAK boleh mendebet Kas Teller.');
        $this->assertSame(30_000.0, $this->mutasi($jurnal)['kredit'], 'Penerimaan auto-debet harus mengkredit Dana Kebajikan.');
        $this->assertSame(0.0, $this->mutasi($jurnal)['debet'], 'Penerimaan ta\'zir tidak boleh mendebet Dana Kebajikan.');
    }

    public function test_void_transaksi_tazir_membalik_jurnal_dana_kebajikan(): void
    {
        $teller = $this->teller();
        $anggota = $this->anggotaDenganRekening();
        $transaksi = $this->buatTransaksiTunai($teller, "Pemotongan Ta'zir Keterlambatan", 40_000, $anggota->rekening_id, $anggota->id);

        // Transaksi sudah tercatat lebih dulu (KREDIT 2-3001).
        $this->assertNotNull($this->service->sinkronkanTransaksiTunaiTeller($transaksi));

        $sebelum = $this->service->ringkasan();

        // Simulasikan void via ReversalService (transaksi ditandai Pending Void).
        $transaksi->update(['status_transaksi' => 'Pending Void']);

        $approval = ApprovalTransaksi::create([
            'kode_approval' => 'APV'.strtoupper(bin2hex(random_bytes(4))),
            'tipe_referensi' => 'transaksi',
            'referensi_id' => $transaksi->id,
            'jenis_transaksi' => "Pemotongan Ta'zir Keterlambatan",
            'nominal' => 40_000,
            'status' => 'PENDING_MANAJER',
            'diajukan_oleh' => $teller->id,
            'role_pengaju' => 'teller',
        ]);

        $hasil = app(ReversalService::class)->executeVoidReversal($approval, $this->manajer()->id);
        $this->assertSame('success', $hasil['status'], 'Void reversal harus berhasil: '.$hasil['message']);

        $sesudah = $this->service->ringkasan();

        // Jurnal pembalik: DEBET 2-3001 | KREDIT Simpanan → saldo turun 40.000.
        $jurnalPembalik = Jurnal::where('transaksi_id', $transaksi->id)
            ->where('sumber', 'Reversal Dana Kebajikan')
            ->latest('id')
            ->first();

        $this->assertNotNull($jurnalPembalik, 'Void harus memposting jurnal pembalik Dana Kebajikan.');
        $this->assertSame(40_000.0, $this->mutasi($jurnalPembalik)['debet'], 'Jurnal pembalik wajib DEBET 2-3001.');
        $this->assertSame(0.0, $this->mutasi($jurnalPembalik)['kredit']);

        $this->assertSame(
            round($sebelum['saldoKebajikan'] - 40_000, 2),
            $sesudah['saldoKebajikan'],
            'Void harus menurunkan saldo Dana Kebajikan melalui DEBET pembalik pada 2-3001.'
        );

        $pencatatan = DanaKebajikan::where('no_referensi', 'DK-TRX-'.$transaksi->no_transaksi)->first();
        $this->assertNotNull($pencatatan);
        $this->assertSame('Void', $pencatatan->jenis, 'Pencatatan harus ditandai Void agar tidak terhitung ganda di laporan.');
    }

    /* =====================================================================
     | 3. SINKRONISASI CARD SUMMARY
     ===================================================================== */

    public function test_ringkasan_dihitung_dari_mutasi_jurnal_akun_dana_kebajikan(): void
    {
        $userId = $this->teller()->id;

        $sebelum = $this->service->ringkasan();

        // Penerimaan: pindah saldo anggota ke dana kebajikan (KREDIT 2-3001).
        $jurnalMasuk = $this->jurnal->post("Ta'zir ringkasan uji", [
            ['kode' => AutoJournalingService::SIMPANAN_SUKARELA, 'debet' => 60_000],
            ['kode' => AutoJournalingService::DANA_KEBAJIKAN,    'kredit' => 60_000],
        ], "Ta'zir", null, null, $userId);

        // Penyaluran: dana keluar (DEBET 2-3001).
        $jurnalKeluar = $this->jurnal->jurnalPenyaluranDanaKebajikan(20_000, 'Penyaluran uji ringkasan', $userId);

        $this->assertNotNull($jurnalMasuk);
        $this->assertNotNull($jurnalKeluar);

        $sesudah = $this->service->ringkasan();

        $this->assertSame(
            round($sebelum['totalTerkumpul'] + 60_000, 2),
            $sesudah['totalTerkumpul'],
            'TOTAL TERKUMPUL harus bertambah dari mutasi KREDIT 2-3001.'
        );
        $this->assertSame(
            round($sebelum['totalDisalurkan'] + 20_000, 2),
            $sesudah['totalDisalurkan'],
            'TOTAL DISALURKAN harus bertambah dari mutasi DEBET 2-3001.'
        );
        $this->assertSame(
            round($sesudah['totalTerkumpul'] - $sesudah['totalDisalurkan'], 2),
            $sesudah['saldoKebajikan'],
            'SALDO = TOTAL TERKUMPUL - TOTAL DISALURKAN.'
        );
    }

    public function test_validasi_saldo_konsisten_dengan_seluruh_mutasi(): void
    {
        $userId = $this->teller()->id;

        $this->jurnal->post('Ta\'zir validasi saldo', [
            ['kode' => AutoJournalingService::SIMPANAN_SUKARELA, 'debet' => 90_000],
            ['kode' => AutoJournalingService::DANA_KEBAJIKAN,    'kredit' => 90_000],
        ], "Ta'zir", null, null, $userId);

        $validasi = $this->service->validasiSaldo();

        $this->assertSame(0.0, $validasi['selisih'], 'Saldo rumus harus sama dengan penjumlahan mutasi.');
        $this->assertSame($validasi['saldoMutasi'], $validasi['saldoRumus']);
        $this->assertTrue($validasi['valid'], 'Validasi saldo harus lolos: '.implode(' | ', $validasi['catatan']));
    }

    public function test_salurkan_menolak_nominal_melebihi_saldo(): void
    {
        $saldo = $this->service->ringkasan()['saldoKebajikan'];

        $this->expectException(\InvalidArgumentException::class);

        $this->service->salurkan([
            'penerima' => 'Mustahiq Uji',
            'jumlah' => $saldo + 1_000_000,
        ], $this->teller()->id);
    }

    public function test_salurkan_mencatat_penyaluran_dan_menurunkan_saldo(): void
    {
        $userId = $this->teller()->id;

        // Siapkan dana masuk agar saldo cukup.
        $this->jurnal->post("Ta'zir persiapan penyaluran", [
            ['kode' => AutoJournalingService::SIMPANAN_SUKARELA, 'debet' => 500_000],
            ['kode' => AutoJournalingService::DANA_KEBAJIKAN,    'kredit' => 500_000],
        ], "Ta'zir", null, null, $userId);

        $sebelum = $this->service->ringkasan();

        $pencatatan = $this->service->salurkan([
            'penerima' => 'Mustahiq Uji Salur',
            'jumlah' => 150_000,
            'keterangan' => 'Bantuan sosial uji salur',
        ], $userId);

        $this->assertSame('Penyaluran', $pencatatan->jenis);

        $sesudah = $this->service->ringkasan();

        $this->assertSame(round($sebelum['saldoKebajikan'] - 150_000, 2), $sesudah['saldoKebajikan']);
        $this->assertSame(round($sebelum['totalDisalurkan'] + 150_000, 2), $sesudah['totalDisalurkan']);
    }

    public function test_endpoint_ringkasan_mengembalikan_nilai_konsisten(): void
    {
        $dps = User::firstOrCreate(
            ['email' => 'dps.danakebajikan@example.test'],
            ['name' => 'DPS Dana Kebajikan Test', 'password' => Hash::make('secret'), 'role' => 'dps', 'is_active' => true]
        );

        $harapan = $this->service->ringkasan();

        $respon = $this->actingAs($dps)->getJson(route('dps.dana-kebajikan.ringkasan'));

        $respon->assertOk()
            ->assertJson([
                'totalTerkumpul' => $harapan['totalTerkumpul'],
                'totalDisalurkan' => $harapan['totalDisalurkan'],
                'saldoKebajikan' => $harapan['saldoKebajikan'],
            ]);
    }

    public function test_halaman_dana_kebajikan_dps_dapat_diakses(): void
    {
        $dps = User::firstOrCreate(
            ['email' => 'dps.danakebajikan.halaman@example.test'],
            ['name' => 'DPS Halaman Test', 'password' => Hash::make('secret'), 'role' => 'dps', 'is_active' => true]
        );

        $this->actingAs($dps)->get(route('dps.dana-kebajikan'))->assertOk();
    }

    /* =====================================================================
     | BADGE POSISI PADA TABEL RIWAYAT
     ===================================================================== */

    public function test_posisi_ta_zir_dan_autodebet_adalah_kredit(): void
    {
        $jenisPenerimaan = [
            "Pemotongan Ta'zir Keterlambatan",
            'AUTODEBET_TAZIR',
            "Ta'zir Tunai",
            'Infak Tunai',
            'Penerimaan Denda Keterlambatan',
            'Kontribusi Kebajikan Tunai',
        ];

        foreach ($jenisPenerimaan as $jenis) {
            $this->assertSame(
                'Kredit',
                $this->service->posisiKebajikan($jenis),
                "Penerimaan '{$jenis}' harus berposisi Kredit (dana masuk ke 2-3001)."
            );
        }
    }

    public function test_posisi_penyaluran_dana_sosial_adalah_debet(): void
    {
        $jenisPenyaluran = [
            'Penyaluran Dana Sosial',
            'Penyaluran Dana Kebajikan',
            'Bantuan Sosial Mustahiq',
            'Santunan Yatim',
        ];

        foreach ($jenisPenyaluran as $jenis) {
            $this->assertSame(
                'Debet',
                $this->service->posisiKebajikan($jenis),
                "Penyaluran '{$jenis}' harus berposisi Debet (dana keluar dari 2-3001)."
            );
        }
    }

    public function test_riwayat_mengoreksi_posisi_autodebet_yang_terbalik(): void
    {
        $teller = $this->teller();
        $anggota = $this->anggotaDenganRekening();

        // Transaksi auto-debet: kolom `posisi` mentah berisi 'Debet' (sisi simpanan anggota).
        $trx = $this->buatTransaksiTunai($teller, "Pemotongan Ta'zir Keterlambatan", 25_000, $anggota->rekening_id, $anggota->id);
        $trx->update(['posisi' => 'Debet']);

        $riwayat = $this->service->riwayat(50);
        $baris = $riwayat->getCollection()->firstWhere('id', $trx->id);

        $this->assertNotNull($baris, 'Transaksi ta\'zir tidak muncul di riwayat.');
        $this->assertSame('Debet', $baris->posisi, 'Nilai mentah transaksi.posisi tetap Debet.');
        $this->assertSame('Kredit', $baris->posisi_kebajikan, 'Badge POSISI harus Kredit untuk ta\'zir.');
    }

    public function test_halaman_riwayat_menampilkan_badge_posisi_sesuai_aturan(): void
    {
        $dps = User::firstOrCreate(
            ['email' => 'dps.badge.danakebajikan@example.test'],
            ['name' => 'DPS Badge Test', 'password' => Hash::make('secret'), 'role' => 'dps', 'is_active' => true]
        );
        $teller = $this->teller();
        $suffix = strtoupper(bin2hex(random_bytes(4)));

        // Penerimaan ta'zir auto-debet (posisi mentah Debet) — badge harus Kredit/hijau.
        $anggota = $this->anggotaDenganRekening();
        $trxTazir = $this->buatTransaksiTunai($teller, "Pemotongan Ta'zir Keterlambatan", 20_000, $anggota->rekening_id, $anggota->id);
        $trxTazir->update(['posisi' => 'Debet']);

        // Penyaluran dana sosial — badge harus Debet/merah.
        $trxSalur = $this->buatTransaksiTunai($teller, 'Penyaluran Dana Sosial '.$suffix, 15_000);
        $trxSalur->update(['posisi' => 'Debet']);

        $html = $this->actingAs($dps)->get(route('dps.dana-kebajikan'))->assertOk()->getContent();

        // Ambil baris tabel masing-masing transaksi untuk verifikasi presisi.
        $barisTazir = $this->barisTabel($html, $trxTazir->no_transaksi);
        $barisSalur = $this->barisTabel($html, $trxSalur->no_transaksi);

        $this->assertNotNull($barisTazir, 'Baris ta\'zir tidak ditemukan pada tabel riwayat.');
        $this->assertNotNull($barisSalur, 'Baris penyaluran tidak ditemukan pada tabel riwayat.');

        // Aturan 1: ta'zir (posisi mentah 'Debet') → badge Kredit hijau.
        $posisiTazir = $this->selPosisi($barisTazir);
        $this->assertMatchesRegularExpression('/badge-green"[^>]*>\s*Kredit\s*</', $posisiTazir);
        $this->assertStringNotContainsString('Debet', $posisiTazir);
        $this->assertStringNotContainsString('badge-red', $posisiTazir);

        // Aturan 2: penyaluran dana sosial → badge Debet merah (eksklusif).
        $posisiSalur = $this->selPosisi($barisSalur);
        $this->assertMatchesRegularExpression('/badge-red"[^>]*>\s*Debet\s*</', $posisiSalur);
        $this->assertStringNotContainsString('Kredit', $posisiSalur);
        $this->assertStringNotContainsString('badge-green', $posisiSalur);
    }

    /** Ekstrak sel POSISI (kolom ke-5) dari satu baris tabel riwayat. */
    private function selPosisi(string $baris): string
    {
        preg_match_all('/<td\b[^>]*>(.*?)<\/td>/s', $baris, $cocok);

        return $cocok[1][4] ?? '';
    }

    /** Ekstrak satu baris <tr> tabel riwayat berdasarkan nomor transaksi. */
    private function barisTabel(string $html, string $noTransaksi): ?string
    {
        foreach (explode('<tr', $html) as $baris) {
            if (str_contains($baris, $noTransaksi)) {
                return $baris;
            }
        }

        return null;
    }

    /* =====================================================================
     | HELPER
     ===================================================================== */

    private function buatTransaksiTunai(
        User $teller,
        string $jenis,
        float $jumlah,
        ?int $rekeningId = null,
        ?int $anggotaId = null
    ): Transaksi {
        return Transaksi::create([
            'no_transaksi' => 'TRXUJI'.strtoupper(bin2hex(random_bytes(5))),
            'jenis_transaksi' => $jenis,
            'posisi' => 'Kredit',
            'jumlah' => $jumlah,
            'sumber_transaksi' => 'Teller',
            'status_transaksi' => 'Sukses',
            'status' => 'success',
            'catatan' => 'Uji otomatis modul Dana Kebajikan',
            'waktu_transaksi' => now(),
            'user_id' => $teller->id,
            'rekening_id' => $rekeningId,
            'anggota_id' => $anggotaId,
        ]);
    }

    /**
     * Anggota beserta rekening Simpanan Sukarela bersaldo cukup.
     * Mengembalikan Anggota (atribut dinamis rekening_id menunjuk rekening sukarela-nya).
     */
    private function anggotaDenganRekening(float $saldo = 1_000_000): Anggota
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $anggota = Anggota::create([
            'no_anggota' => 'AGT'.$suffix,
            'nik' => (string) random_int(1000000000000000, 9999999999999999),
            'nama_lengkap' => 'Anggota Uji Dana Kebajikan '.$suffix,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Pengujian No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => 'Aktif',
            'tanggal_gabung' => now()->toDateString(),
        ]);

        $coaSimpanan = Coa::findByKode(AutoJournalingService::SIMPANAN_SUKARELA)
            ?? Coa::query()->orderBy('id')->firstOrFail();

        $rekening = Rekening::create([
            'no_rekening' => 'REK-UJI-'.$suffix,
            'anggota_id' => $anggota->id,
            'akun_id' => $coaSimpanan->id,
            'jenis_rekening' => 'Simpanan Sukarela',
            'akad' => 'Wadiah',
            'saldo' => $saldo,
            'batas_minimum' => 0,
            'tanggal_buka' => now()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);

        $anggota->rekening_id = $rekening->id;

        return $anggota;
    }
}
