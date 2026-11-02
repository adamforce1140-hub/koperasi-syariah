<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\AuditLog;
use App\Models\AutodebitUsulan;
use App\Models\Coa;
use App\Models\JenisSimpanan;
use App\Models\Jurnal;
use App\Models\KebijakanKoperasi;
use App\Models\MandatorySavingsBill;
use App\Models\Rekening;
use App\Models\SimpananWajibTindakan;
use App\Models\User;
use App\Notifications\SimpananWajibDitangguhkanNotification;
use App\Services\AutodebitService;
use App\Services\MandatorySavingsService;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Penanganan DINAMIS Tunggakan Simpanan Wajib (spesifikasi 5 poin).
 *
 * Yang dikunci oleh test ini:
 *  1. PELACAKAN (spec #1): tagihan belum bayar memiliki identitas anggota,
 *     nominal, dan tenggat waktu pembayaran yang akurat.
 *  2. AUTO-DEBIT ON/OFF (spec #2): saklar kebijakan menghentikan/menjalankan
 *     pemotongan saldo Simpanan Sukarela secara menyeluruh.
 *  3. PENANGANAN DINAMIS (spec #3): jadwal bertahap, cicilan, reschedule TANPA
 *     DENDA — menyesuaikan kekurangan saldo (ringan vs berat), bukan statis.
 *  4. AUDIT TRAIL (spec #5): setiap pemotongan & penjadwalan ulang tercatat
 *     permanen pada `simpanan_wajib_tindakans` dengan kolom denda = 0.
 *
 * Basis data dev memuat data nyata → pengujian memakai SELISIH (delta) &
 * prefix unik agar deterministik (DatabaseTransactions + rollback otomatis).
 */
class MandatorySavingsPenangananDinamisTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'PNDYN'.strtoupper(substr(uniqid(), -6));
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    private function service(): MandatorySavingsService
    {
        return app(MandatorySavingsService::class);
    }

    private function akunId(): int
    {
        $id = Akun::where('kode_akun', '1133')->value('id') ?? Akun::query()->value('id');

        if (! $id) {
            $this->markTestSkipped('Tabel akun kosong — jalankan AkunSeeder.');
        }

        return (int) $id;
    }

    private function pastikanCoa(): void
    {
        foreach ([
            MandatorySavingsService::COA_SIMPANAN_SUKARELA,
            MandatorySavingsService::COA_SIMPANAN_WAJIB_MODAL,
        ] as $kode) {
            if (! Coa::findByKode($kode)) {
                $this->markTestSkipped("CoA {$kode} belum tersedia — jalankan CoaSeeder.");
            }
        }
    }

    private function izinkanAutoDebitProdukWajib(): void
    {
        JenisSimpanan::query()
            ->where('nama', 'Simpanan Wajib')
            ->orWhere('code', JenisSimpanan::CODE_WAJIB)
            ->update(['allow_auto_debit' => true]);

        JenisSimpanan::flush();
    }

    private function anggota(
        string $label,
        string $tanggalGabung,
        string $status = Anggota::STATUS_ACTIVE,
        bool $autoDebit = false,
    ): Anggota {
        return Anggota::create([
            'no_anggota' => substr($this->prefix.strtoupper($label), 0, 20),
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Uji Dinamis '.$label,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Alamat Uji Penanganan Dinamis',
            'kota' => 'Jakarta',
            'provinsi' => 'DKI Jakarta',
            'status_anggota' => $status,
            'is_auto_debit' => $autoDebit,
            'tanggal_gabung' => $tanggalGabung,
        ]);
    }

    private function rekeningSukarela(Anggota $anggota, float $saldo): Rekening
    {
        return Rekening::create([
            'no_rekening' => 'RKN-'.strtoupper(substr(uniqid(), -9)),
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunId(),
            'jenis_rekening' => 'Simpanan Sukarela',
            'akad' => 'Wadiah',
            'saldo' => $saldo,
            'batas_minimum' => 0,
            'tanggal_buka' => now()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);
    }

    private function nominal(): float
    {
        return KebijakanKoperasi::simpananWajibBulanan();
    }

    /**
     * Terbitkan satu tagihan Simpanan Wajib riil untuk anggota & periode uji.
     */
    private function terbitkanTagihan(Anggota $anggota, string $periode = '2026-02'): MandatorySavingsBill
    {
        return $this->service()->issueBill($anggota, $periode, Carbon::create(2026, 2, 1));
    }

    /**
     * Ubah saklar kebijakan pada baris aktif `kebijakan_koperasis` lalu
     * bersihkan cache Setting — di-rollback otomatis oleh DatabaseTransactions.
     */
    private function setKebijakan(string $key, mixed $value): void
    {
        KebijakanKoperasi::query()->update([$key => $value]);
        Setting::flush();
    }

    /* =====================================================================
     | 1. PELACAKAN TAGIHAN (SPEC #1)
     |===================================================================== */

    public function test_pelacakan_tagihan_menampilkan_identitas_nominal_dan_tenggat(): void
    {
        $this->pastikanCoa();
        $this->akunId();

        $anggota = $this->anggota('LACAK', '2026-01-10'); // jatuh tempo tgl 10
        $bill = $this->terbitkanTagihan($anggota, '2026-02');

        // Identitas anggota dapat ditelusuri dari tagihan (relasi member).
        $this->assertSame($anggota->id, $bill->member->id);
        $this->assertSame($anggota->nama_lengkap, $bill->member->nama_lengkap);
        $this->assertSame((float) $this->nominal(), (float) $bill->amount);

        // Tenggat pembayaran akurat: tanggal gabung 10 → jatuh tempo 10 Feb 2026.
        $tenggat = $this->service()->tanggalJatuhTempo($anggota, $this->service()->periodeTagihan($bill));
        $this->assertSame('2026-02-10', $tenggat->toDateString());

        // Konversi bill_period 'YYYY-MM' konsisten.
        $this->assertSame('2026-02-01', $this->service()->periodeTagihan($bill)->toDateString());
    }

    public function test_ringkasan_penanganan_menghitung_kekurangan_saldo_secara_akurat(): void
    {
        $this->pastikanCoa();
        $this->akunId();

        $nominal = $this->nominal();
        $anggota = $this->anggota('RING', '2026-01-05');
        $this->rekeningSukarela($anggota, 10000);
        $bill = $this->terbitkanTagihan($anggota, '2026-02');

        $ringkasan = $this->service()->ringkasanPenanganan($bill);

        $this->assertSame(10000.0, round($ringkasan['saldo_sukarela'], 2));
        $this->assertSame(round($nominal - 10000, 2), round($ringkasan['kekurangan'], 2));
        $this->assertFalse($ringkasan['ada_jadwal']);
        $this->assertSame(0, $ringkasan['jumlah_reschedule']);
        $this->assertTrue($ringkasan['boleh_reschedule']);
        $this->assertTrue($ringkasan['penanganan_dinamis_aktif']);
        $this->assertSame(0.0, (float) $ringkasan['total_denda'], 'Koperasi syariah tidak mengenakan denda.');
    }

    /* =====================================================================
     | 2. AUTO-DEBIT ON/OFF (SPEC #2)
     |===================================================================== */

    public function test_menonaktifkan_auto_debit_menghentikan_pemotongan_sukarela(): void
    {
        $this->pastikanCoa();
        $this->akunId();
        $this->izinkanAutoDebitProdukWajib();

        $anggota = $this->anggota('OFF', '2026-01-31', Anggota::STATUS_ACTIVE, true);
        $rekening = $this->rekeningSukarela($anggota, 500000);

        // Saklar induk auto-debit DIMATIKAN pengurus.
        $this->setKebijakan('simpanan_wajib_auto_debit_aktif', false);

        $hasil = $this->service()->processDaily(Carbon::create(2026, 2, 28));

        $bill = MandatorySavingsBill::where('member_id', $anggota->id)
            ->where('bill_period', '2026-02')->first();

        $this->assertNotNull($bill, 'Tagihan tetap diterbitkan walau auto-debit nonaktif.');
        // Inti spec #2: TIDAK ada pemotongan saldo & tagihan TIDAK lunas.
        // (Status bisa UNPAID atau OVERDUE bila tanggal proses = jatuh tempo —
        //  yang penting bukan PAID dan saldo Sukarela utuh.)
        $this->assertNotSame(MandatorySavingsBill::STATUS_PAID, $bill->status);
        $this->assertNull($bill->payment_method);
        $this->assertNull($bill->paid_at);
        $this->assertSame(0, $hasil['paid']);
        $this->assertSame(
            500000.0,
            round((float) $rekening->fresh()->saldo, 2),
            'Saldo Simpanan Sukarela TIDAK boleh terpotong saat auto-debit nonaktif.'
        );
        // Audit trail auto-debit tidak boleh muncul sama sekali.
        $this->assertSame(0, SimpananWajibTindakan::where('bill_id', $bill->id)
            ->where('jenis', SimpananWajibTindakan::JENIS_AUTO_DEBIT_BERHASIL)->count());
    }

    public function test_mengaktifkan_auto_debit_menjalankan_pemotongan_sesuai_jadwal(): void
    {
        $this->pastikanCoa();
        $this->akunId();
        $this->izinkanAutoDebitProdukWajib();

        // Pastikan saklar induk AKTIF (default aman = true).
        $this->setKebijakan('simpanan_wajib_auto_debit_aktif', true);

        $nominal = $this->nominal();
        $anggota = $this->anggota('ON', '2026-01-31', Anggota::STATUS_ACTIVE, true);
        $rekening = $this->rekeningSukarela($anggota, 500000);

        $hasil = $this->service()->processDaily(Carbon::create(2026, 2, 28));

        $bill = MandatorySavingsBill::where('member_id', $anggota->id)
            ->where('bill_period', '2026-02')->first();

        $this->assertNotNull($bill);

        // KEBIJAKAN BARU: scheduler TIDAK memotong dana — hanya mengusulkan.
        $this->assertSame(MandatorySavingsBill::STATUS_PENDING_CONFIRMATION, $bill->status);
        $this->assertNull($bill->paid_at);
        $this->assertSame(0, $hasil['paid'], 'processDaily tidak boleh memotong dana tanpa konfirmasi.');
        $this->assertSame(
            round(500000, 2),
            round((float) $rekening->fresh()->saldo, 2),
            'Saldo tidak boleh berubah sebelum konfirmasi.'
        );

        $usulan = AutodebitUsulan::menungguKonfirmasi()
            ->where('kanal', AutodebitUsulan::KANAL_SIMPANAN_WAJIB)
            ->where('referensi_id', $bill->id)
            ->first();
        $this->assertNotNull($usulan, 'Usulan auto-debit harus dibuat.');

        // === KONFIRMASI PENGGUNA (satu-satunya jalur pemotongan) ===
        $petugas = User::whereIn('role', ['teller', 'pengurus', 'manajer'])->first();
        if (! $petugas) {
            $this->markTestSkipped('Tidak ada user berwenang untuk mengonfirmasi.');
        }

        $konfirmasi = app(AutodebitService::class)->konfirmasi($usulan, (int) $petugas->id, 'Disetujui uji.');
        $this->assertTrue($konfirmasi['ok'], 'Konfirmasi harus berhasil: '.($konfirmasi['pesan'] ?? ''));

        $bill->refresh();

        $this->assertSame(MandatorySavingsBill::STATUS_PAID, $bill->status);
        $this->assertSame(MandatorySavingsBill::METODE_AUTO_DEBIT, $bill->payment_method);
        $this->assertGreaterThanOrEqual(1, $hasil['ditangguhkan']);
        $this->assertSame(
            round(500000 - $nominal, 2),
            round((float) $rekening->fresh()->saldo, 2)
        );

        // Audit trail pemotongan auto-debit tercatat (spec #5).
        $this->assertTrue(
            SimpananWajibTindakan::where('bill_id', $bill->id)
                ->where('jenis', SimpananWajibTindakan::JENIS_AUTO_DEBIT_BERHASIL)
                ->where('status_hasil', SimpananWajibTindakan::STATUS_BERHASIL)
                ->exists(),
            'Pemotongan auto-debit berhasil tidak tercatat pada riwayat tindakan.'
        );
    }

    public function test_saldo_kosong_menghasilkan_penanganan_dinamis_bukan_penolakan_kaku(): void
    {
        $this->pastikanCoa();
        $this->akunId();
        $this->izinkanAutoDebitProdukWajib();

        // Anggota TIDAK punya rekening Sukarela sama sekali (saldo kosong).
        $anggota = $this->anggota('KOSONG', '2026-01-31', Anggota::STATUS_ACTIVE, true);

        $this->service()->processDaily(Carbon::create(2026, 2, 28));

        $bill = MandatorySavingsBill::where('member_id', $anggota->id)
            ->where('bill_period', '2026-02')->first();

        $this->assertNotNull($bill);
        // BUG-03: setoran otomatis TIDAK dijalankan tanpa verifikasi — saldo
        // kosong kini menghasilkan penangguhan + konfirmasi manual, bukan
        // langsung OVERDUE. Dana anggota TIDAK dipotong.
        $this->assertSame(MandatorySavingsBill::STATUS_PENDING_CONFIRMATION, $bill->status);
        $this->assertSame('SALDO_TIDAK_CUKUP', $bill->deferred_reason);
        $this->assertNotNull($bill->deferred_at);
        $this->assertNull($bill->paid_at);

        // Verifikasi gagal + penangguhan + notifikasi administrator tercatat —
        // bukan sekadar ditolak, dan bukan dipotong tanpa verifikasi.
        $this->assertTrue(
            SimpananWajibTindakan::where('bill_id', $bill->id)
                ->where('jenis', SimpananWajibTindakan::JENIS_VERIFIKASI_GAGAL)
                ->exists()
        );
        $this->assertTrue(
            SimpananWajibTindakan::where('bill_id', $bill->id)
                ->where('jenis', SimpananWajibTindakan::JENIS_DITANGGUHKAN)
                ->exists()
        );
        $this->assertTrue(
            SimpananWajibTindakan::where('bill_id', $bill->id)
                ->where('jenis', SimpananWajibTindakan::JENIS_NOTIFIKASI)
                ->exists()
        );
    }

    /* =====================================================================
     | 3. PENANGANAN DINAMIS: JADWAL BERTAHAP / CICILAN / RESCHEDULE
     |===================================================================== */

    public function test_jadwal_bertahap_memecah_tagihan_sesuai_kemampuan_anggota(): void
    {
        $this->pastikanCoa();
        $this->akunId();

        $anggota = $this->anggota('JADWAL', '2026-01-05');
        $this->rekeningSukarela($anggota, 5000); // kekurangan berat
        $bill = $this->terbitkanTagihan($anggota, '2026-02');

        // Anggota mengajukan 4 cicilan (kemampuan sendiri) — dinamik.
        $hasil = $this->service()->jadwalkanBertahap($bill, 4);

        $this->assertTrue($hasil['ok'], 'Penjadwalan bertahap seharusnya diterima.');
        $this->assertSame(4, $hasil['cicilan']);
        $this->assertSame(
            round((float) $this->nominal() / 4, 2),
            round((float) $hasil['nominal_cicilan'], 2)
        );

        // Riwayat penjadwalan tercatat (spec #5) & tanpa denda (syariah).
        $riwayat = SimpananWajibTindakan::where('bill_id', $bill->id)
            ->where('jenis', SimpananWajibTindakan::JENIS_JADWAL_BERTAHAP)->first();
        $this->assertNotNull($riwayat, 'Penjadwalan bertahap tidak tercatat pada riwayat.');
        $this->assertSame(4, (int) $riwayat->total_cicilan);
        $this->assertSame(0.0, (float) $riwayat->denda);
    }

    public function test_jadwal_bertahap_dibatasi_maksimal_cicilan_dari_kebijakan(): void
    {
        $this->pastikanCoa();
        $this->akunId();

        $maks = Setting::simpananWajibMaksCicilan();
        if ($maks < 2) {
            $this->markTestSkipped('Parameter simpanan_wajib_maks_cicilan < 2 — tidak dapat diuji.');
        }

        $anggota = $this->anggota('MAKS', '2026-01-06');
        $this->rekeningSukarela($anggota, 5000);
        $bill = $this->terbitkanTagihan($anggota, '2026-02');

        // Permintaan 99 cicilan dijepit ke batas kebijakan (dinamis, bukan kaku).
        $hasil = $this->service()->jadwalkanBertahap($bill, 99);

        $this->assertTrue($hasil['ok']);
        $this->assertLessThanOrEqual($maks, (int) $hasil['cicilan']);
        $this->assertSame($maks, (int) $hasil['cicilan']);
    }

    public function test_cicilan_memotong_saldo_sukarela_dan_mencatat_riwayat(): void
    {
        $this->pastikanCoa();
        $this->akunId();

        $nominal = $this->nominal();
        // Cicilan dipotong dari saldo Sukarela = auto-debit, sehingga anggota
        // fixture WAJIB menyetujui auto-debit (kebijakan wajib konfirmasi).
        $anggota = $this->anggota('CICIL', '2026-01-07', Anggota::STATUS_ACTIVE, true);
        $rekening = $this->rekeningSukarela($anggota, round($nominal * 0.75, 2));
        $bill = $this->terbitkanTagihan($anggota, '2026-02');

        // Tetapkan 4 cicilan, lalu bayar cicilan pertama dari saldo Sukarela.
        $jadwal = $this->service()->jadwalkanBertahap($bill, 4);
        $this->assertTrue($jadwal['ok']);

        $saldoAwal = (float) $rekening->fresh()->saldo;
        $hasil = $this->service()->bayarCicilan($bill);

        $this->assertTrue($hasil['ok'], 'Pembayaran cicilan seharusnya berhasil.');
        $this->assertGreaterThan(0, $hasil['dibayar']);
        $this->assertSame(
            round($saldoAwal - $hasil['dibayar'], 2),
            round((float) $rekening->fresh()->saldo, 2),
            'Saldo Simpanan Sukarela harus terpotong sebesar cicilan.'
        );

        // Riwayat cicilan tercatat (spec #5).
        $this->assertTrue(
            SimpananWajibTindakan::where('bill_id', $bill->id)
                ->where('jenis', SimpananWajibTindakan::JENIS_CICILAN_DIBAYAR)
                ->exists(),
            'Pemotongan cicilan tidak tercatat pada riwayat tindakan.'
        );
    }

    public function test_reschedule_digeser_tanpa_denda_dan_tercatat(): void
    {
        $this->pastikanCoa();
        $this->akunId();

        $anggota = $this->anggota('RESCH', '2026-01-08');
        $this->rekeningSukarela($anggota, 0);
        $bill = $this->terbitkanTagihan($anggota, '2026-02');

        $this->assertTrue($this->service()->bolehReschedule($bill));

        $hasil = $this->service()->reschedule($bill, null, null);

        $this->assertTrue($hasil['ok'], 'Reschedule seharusnya diterima (belum lewat batas).');
        $this->assertArrayHasKey('tenggat_baru', $hasil);

        // Riwayat reschedule tercatat, TANPA DENDA (prinsip syariah).
        $riwayat = SimpananWajibTindakan::where('bill_id', $bill->id)
            ->where('jenis', SimpananWajibTindakan::JENIS_RESCHEDULE)->first();
        $this->assertNotNull($riwayat, 'Reschedule tidak tercatat pada riwayat.');
        $this->assertSame(0.0, (float) $riwayat->denda, 'Reschedule TIDAK boleh dikenakan denda.');
        $this->assertNotNull($riwayat->tenggat_lama);
        $this->assertNotNull($riwayat->tenggat_baru);
    }

    public function test_notifikasi_anonim_tidak_mengekspos_identitas_anggota(): void
    {
        $this->pastikanCoa();
        $this->akunId();

        $anggota = $this->anggota('ANON', '2026-01-09');
        $this->rekeningSukarela($anggota, 0);
        $bill = $this->terbitkanTagihan($anggota, '2026-02');

        $pesan = $this->service()->pesanNotifikasiAnonim($bill, $anggota);

        // Tidak mengekspos identitas (PDP / UU 27/2022).
        $this->assertStringNotContainsString($anggota->nama_lengkap, $pesan);
        $this->assertStringNotContainsString((string) $anggota->no_anggota, $pesan);
        $this->assertStringNotContainsString((string) $anggota->nik, $pesan);

        // Informatif: memuat periode, nominal, tenggat, dan opsi pemulihan.
        $this->assertStringContainsString($bill->nama_periode, $pesan);
        $this->assertStringContainsString('Rp', $pesan);
        $this->assertStringContainsString('denda', strtolower($pesan));
        $this->assertStringContainsString('bertahap', strtolower($pesan));
        $this->assertStringContainsString('penjadwalan ulang', strtolower($pesan));
    }

    /* =====================================================================
     | 4. AUDIT TRAIL & TRANSPARANSI (SPEC #5)
     |===================================================================== */

    public function test_riwayat_tindakan_bersifat_append_only_dan_lengkap(): void
    {
        $this->pastikanCoa();
        $this->akunId();

        $anggota = $this->anggota('AUDIT', '2026-01-11');
        $this->rekeningSukarela($anggota, 5000);
        $bill = $this->terbitkanTagihan($anggota, '2026-02');

        // Jalani alur lengkap: jadwal bertahap → cicilan → reschedule.
        $this->service()->jadwalkanBertahap($bill, 3);
        $this->service()->bayarCicilan($bill);
        $this->service()->reschedule($bill);

        $jenis = SimpananWajibTindakan::where('bill_id', $bill->id)->pluck('jenis')->all();

        $this->assertContains(SimpananWajibTindakan::JENIS_JADWAL_BERTAHAP, $jenis);
        $this->assertContains(SimpananWajibTindakan::JENIS_CICILAN_DIBAYAR, $jenis);
        $this->assertContains(SimpananWajibTindakan::JENIS_RESCHEDULE, $jenis);

        // Setiap baris riwayat memuat periode & member untuk audit & transparansi.
        foreach (SimpananWajibTindakan::where('bill_id', $bill->id)->get() as $t) {
            $this->assertSame($anggota->id, (int) $t->member_id);
            $this->assertSame('2026-02', $t->periode);
            $this->assertNotNull($t->waktu_tindakan);
        }

        // NOMINAL DENDA selalu 0 (bukti kepatuhan syariah untuk audit).
        $this->assertSame(
            0.0,
            (float) DB::table('simpanan_wajib_tindakans')->where('bill_id', $bill->id)->sum('denda'),
            'Sistem koperasi syariah tidak boleh mencatat denda keterlambatan.'
        );
    }

    public function test_riwayat_tindakan_terhubung_ke_tagihan_dan_anggota(): void
    {
        $this->pastikanCoa();
        $this->akunId();

        $anggota = $this->anggota('REL', '2026-01-12');
        $this->rekeningSukarela($anggota, 5000);
        $bill = $this->terbitkanTagihan($anggota, '2026-02');

        $this->service()->jadwalkanBertahap($bill, 2);

        // Relasi model: bill → tindakan, dan tindakan → bill/member.
        $this->assertGreaterThanOrEqual(1, $bill->tindakan()->count());

        $tindakan = $bill->tindakan()->first();
        $this->assertSame($bill->id, (int) $tindakan->bill->id);
        $this->assertSame($anggota->id, (int) $tindakan->member->id);
        $this->assertNotNull($bill->tindakanTerakhir, 'Relasi tindakanTerakhir tidak berfungsi.');
    }

    public function test_notifikasi_overdue_tetap_mencatat_audit_log_dengan_pesan_anonim(): void
    {
        $this->pastikanCoa();
        $this->akunId();
        $this->izinkanAutoDebitProdukWajib();

        $nominal = $this->nominal();
        $anggota = $this->anggota('OVDAUDIT', '2026-01-31', Anggota::STATUS_ACTIVE, true);
        $this->rekeningSukarela($anggota, max(0, $nominal - 1000));

        $this->service()->processDaily(Carbon::create(2026, 2, 28));

        $bill = MandatorySavingsBill::where('member_id', $anggota->id)
            ->where('bill_period', '2026-02')->firstOrFail();

        // BUG-03: saldo kurang → tagihan DITANGGUHKAN (bukan langsung OVERDUE).
        $this->assertSame(MandatorySavingsBill::STATUS_PENDING_CONFIRMATION, $bill->status);

        // Audit log eksternal tetap tercatat dengan jenis PENANGGUHAN (kompatibilitas + audit pengurus).
        $log = AuditLog::where('jenis_transaksi', 'SIMPANAN_WAJIB_DITANGGUHKAN')
            ->where('nominal', '>=', $nominal)
            ->where('created_at', '>=', now()->subMinute())
            ->first();

        $this->assertNotNull($log, 'Audit log penangguhan tidak tercatat.');

        // Pesan anonim tidak mengekspos identitas anggota (PDP).
        $this->assertStringNotContainsString($anggota->nama_lengkap, (string) $log->keterangan);
        $this->assertStringNotContainsString((string) $anggota->no_anggota, (string) $log->keterangan);
    }

    /* =====================================================================
     | 6. PERMUKAAN HTTP PANEL TELLER (spec #1, #3, #5)
     |===================================================================== */

    private function user(string $role = 'teller'): User
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

    public function test_teller_melihat_daftar_tertagih_dengan_identitas_dan_tenggat(): void
    {
        $this->pastikanCoa();
        $this->akunId();

        $anggota = $this->anggota('HTTPLACAK', '2026-01-07'); // jatuh tempo tgl 7
        $bill = $this->terbitkanTagihan($anggota, '2026-02');

        $res = $this->actingAs($this->user())
            ->get(route('teller.simpanan-wajib.index', ['cari' => $anggota->no_anggota]));

        $res->assertOk();
        // Identitas anggota (spec #1) + nominal tagihan + tenggat pembayaran.
        $res->assertSee($anggota->nama_lengkap);
        $res->assertSee($anggota->no_anggota);
        $res->assertSee(number_format((float) $bill->amount, 0, ',', '.'));
        // Tenggat = tanggal gabung (7) pada periode tagihan (Feb 2026).
        $res->assertSee('07/02/2026');
    }

    public function test_teller_menetapkan_jadwal_bertahap_via_http(): void
    {
        $this->pastikanCoa();
        $this->akunId();

        $anggota = $this->anggota('HTTPJADWAL', '2026-01-10');
        $this->rekeningSukarela($anggota, 0); // saldo kosong → memicu penanganan dinamis
        $bill = $this->terbitkanTagihan($anggota, '2026-02');

        $this->actingAs($this->user())
            ->post(route('teller.simpanan-wajib.jadwal', $bill->id), ['jumlah_cicilan' => 4])
            ->assertRedirect();

        // Jadwal bertahap tercatat pada riwayat tindakan (spec #5).
        $this->assertTrue(
            SimpananWajibTindakan::where('bill_id', $bill->id)
                ->where('jenis', SimpananWajibTindakan::JENIS_JADWAL_BERTAHAP)
                ->exists(),
            'Penetapan jadwal bertahap via loket tidak tercatat pada riwayat tindakan.'
        );
    }

    public function test_teller_membuka_riwayat_tindakan_audit(): void
    {
        $this->pastikanCoa();
        $this->akunId();

        $anggota = $this->anggota('HTTPRIWAYAT', '2026-01-10');
        $this->rekeningSukarela($anggota, 0);
        $bill = $this->terbitkanTagihan($anggota, '2026-02');

        $this->actingAs($this->user())
            ->post(route('teller.simpanan-wajib.jadwal', $bill->id), ['jumlah_cicilan' => 3]);

        $res = $this->actingAs($this->user())
            ->get(route('teller.simpanan-wajib.riwayat', $bill->id));

        $res->assertOk();
        $res->assertSee($anggota->nama_lengkap);
        // Transparansi tanpa denda (prinsip syariah) tampil pada halaman audit.
        $res->assertSee('prinsip syariah');
    }

    public function test_teller_reschedule_via_http_tanpa_denda_tercatat(): void
    {
        $this->pastikanCoa();
        $this->akunId();

        $anggota = $this->anggota('HTTPRESCH', '2026-01-10');
        $this->rekeningSukarela($anggota, 0);
        $bill = $this->terbitkanTagihan($anggota, '2026-02');

        $this->actingAs($this->user())
            ->post(route('teller.simpanan-wajib.reschedule', $bill->id))
            ->assertRedirect();

        $tindakan = SimpananWajibTindakan::where('bill_id', $bill->id)
            ->where('jenis', SimpananWajibTindakan::JENIS_RESCHEDULE)
            ->first();

        $this->assertNotNull($tindakan, 'Reschedule tanpa denda tidak tercatat pada riwayat tindakan.');
        $this->assertSame(0.0, (float) $tindakan->denda, 'Koperasi syariah tidak boleh mengenakan denda.');
    }

    public function test_panel_penanganan_dinamis_ditolak_untuk_role_anggota(): void
    {
        $this->pastikanCoa();
        $this->akunId();

        $anggota = $this->anggota('HTTPRBAC', '2026-01-10');
        $this->rekeningSukarela($anggota, 0);
        $bill = $this->terbitkanTagihan($anggota, '2026-02');

        // RBAC: anggota tidak boleh menetapkan jadwal / membuka panel teller.
        $this->actingAs($this->user('anggota'))
            ->post(route('teller.simpanan-wajib.jadwal', $bill->id), ['jumlah_cicilan' => 3])
            ->assertForbidden();

        $this->actingAs($this->user('anggota'))
            ->get(route('teller.simpanan-wajib.index'))
            ->assertForbidden();
    }

    /* =====================================================================
     | 5. VERIFIKASI STATUS PEMBAYARAN & KONFIRMASI MANUAL (BUG-03)
     |     Root cause: setoran otomatis dijalankan pada tanggal jatuh tempo
     |     TANPA verifikasi. Test berikut mengunci gerbang verifikasi,
     |     penangguhan, notifikasi administrator, dan konfirmasi manual.
     |===================================================================== */

    /**
     * UNIT: `verifikasiStatusPembayaran()` menolak setoran otomatis untuk
     * SETIAP prasyarat yang gagal, dan mengizinkan hanya saat semua lolos.
     */
    public function test_verifikasi_status_pembayaran_menolak_setiap_prasyarat_yang_gagal(): void
    {
        $this->pastikanCoa();
        $this->akunId();
        $this->izinkanAutoDebitProdukWajib();

        $nominal = $this->nominal();

        // (a) Periode kosong → PERIODE_TIDAK_VALID.
        $anggotaA = $this->anggota('VERA', '2026-01-10', Anggota::STATUS_ACTIVE, true);
        $this->rekeningSukarela($anggotaA, $nominal * 2);
        $billA = $this->terbitkanTagihan($anggotaA, '2026-02');
        $billA->forceFill(['bill_period' => ''])->save();
        $this->assertSame(
            'PERIODE_TIDAK_VALID',
            $this->service()->verifikasiStatusPembayaran($billA->fresh(), $anggotaA)['alasan']
        );

        // (b) Tagihan sudah lunas → SUDAH_LUNAS (idempoten; tidak dipotong ulang).
        $anggotaB = $this->anggota('VERB', '2026-01-10', Anggota::STATUS_ACTIVE, true);
        $this->rekeningSukarela($anggotaB, $nominal * 2);
        $billB = $this->terbitkanTagihan($anggotaB, '2026-02');
        $billB->update(['status' => MandatorySavingsBill::STATUS_PAID]);
        $this->assertSame(
            'SUDAH_LUNAS',
            $this->service()->verifikasiStatusPembayaran($billB->fresh(), $anggotaB)['alasan']
        );

        // (c) Anggota tidak berhak auto-debit → TIDAK_BERHAK_AUTO_DEBIT.
        $anggotaC = $this->anggota('VERC', '2026-01-10', Anggota::STATUS_ACTIVE, false);
        $this->rekeningSukarela($anggotaC, $nominal * 2);
        $billC = $this->terbitkanTagihan($anggotaC, '2026-02');
        $this->assertSame(
            'TIDAK_BERHAK_AUTO_DEBIT',
            $this->service()->verifikasiStatusPembayaran($billC, $anggotaC)['alasan']
        );

        // (d) Keanggotaan NONAKTIF → ANGGOTA_TIDAK_AKTIF.
        $anggotaD = $this->anggota('VERD', '2026-01-10', Anggota::STATUS_INACTIVE, true);
        $this->rekeningSukarela($anggotaD, $nominal * 2);
        $billD = $this->terbitkanTagihan($anggotaD, '2026-02');
        $this->assertSame(
            'ANGGOTA_TIDAK_AKTIF',
            $this->service()->verifikasiStatusPembayaran($billD, $anggotaD)['alasan']
        );

        // (e) Nominal tagihan tidak valid (≤ 0) → NOMINAL_TIDAK_VALID.
        $anggotaE = $this->anggota('VERE', '2026-01-10', Anggota::STATUS_ACTIVE, true);
        $this->rekeningSukarela($anggotaE, $nominal * 2);
        $billE = $this->terbitkanTagihan($anggotaE, '2026-02');
        $billE->update(['amount' => 0]);
        $this->assertSame(
            'NOMINAL_TIDAK_VALID',
            $this->service()->verifikasiStatusPembayaran($billE->fresh(), $anggotaE)['alasan']
        );

        // (f) Saldo Sukarela TIDAK mencukupi → SALDO_TIDAK_CUKUP (sebab utama).
        $anggotaF = $this->anggota('VERF', '2026-01-10', Anggota::STATUS_ACTIVE, true);
        $this->rekeningSukarela($anggotaF, $nominal - 1000);
        $billF = $this->terbitkanTagihan($anggotaF, '2026-02');
        $verifikasi = $this->service()->verifikasiStatusPembayaran($billF, $anggotaF);
        $this->assertFalse($verifikasi['boleh']);
        $this->assertSame('SALDO_TIDAK_CUKUP', $verifikasi['alasan']);
        $this->assertArrayHasKey('saldo', $verifikasi);

        // (g) Seluruh prasyarat lolos → TERVERIFIKASI (setoran boleh jalan).
        $anggotaG = $this->anggota('VERG', '2026-01-10', Anggota::STATUS_ACTIVE, true);
        $this->rekeningSukarela($anggotaG, $nominal * 2);
        $billG = $this->terbitkanTagihan($anggotaG, '2026-02');
        $verifikasiG = $this->service()->verifikasiStatusPembayaran($billG, $anggotaG);
        $this->assertTrue($verifikasiG['boleh']);
        $this->assertSame('TERVERIFIKASI', $verifikasiG['alasan']);
    }

    /**
     * INTEGRASI: saldo tidak cukup → tagihan DITANGGUHKAN (PENDING_CONFIRMATION)
     * dan dana anggota TIDAK dipotong — bukan dipaksa setor.
     */
    public function test_saldo_tidak_cukup_menangguhkan_setoran_tanpa_memotong_dana(): void
    {
        $this->pastikanCoa();
        $this->akunId();
        $this->izinkanAutoDebitProdukWajib();

        $nominal = $this->nominal();
        $anggota = $this->anggota('TGH', '2026-01-10', Anggota::STATUS_ACTIVE, true);
        $rekening = $this->rekeningSukarela($anggota, $nominal - 5000); // kurang Rp5.000
        $saldoAwal = (float) $rekening->saldo;

        $hasil = $this->service()->processDaily(Carbon::create(2026, 2, 10));

        $bill = MandatorySavingsBill::where('member_id', $anggota->id)
            ->where('bill_period', '2026-02')->first();

        $this->assertNotNull($bill);
        $this->assertSame(MandatorySavingsBill::STATUS_PENDING_CONFIRMATION, $bill->status);
        $this->assertSame('SALDO_TIDAK_CUKUP', $bill->deferred_reason);
        $this->assertNotNull($bill->deferred_at);
        $this->assertNull($bill->paid_at);
        $this->assertNull($bill->payment_method);
        $this->assertGreaterThanOrEqual(1, $hasil['ditangguhkan']);

        // INTI mitigasi risiko: saldo anggota TIDAK berkurang sama sekali.
        $this->assertSame(
            round($saldoAwal, 2),
            round((float) $rekening->fresh()->saldo, 2),
            'Dana anggota tidak boleh dipotong ketika verifikasi status pembayaran gagal.'
        );
    }

    /**
     * INTEGRASI: notifikasi administrator (pengurus & manajer) terkirim lewat
     * kanal database ketika ada anggota belum melunasi pada jatuh tempo.
     */
    public function test_notifikasi_administrator_terkirim_saat_setoran_ditangguhkan(): void
    {
        $this->pastikanCoa();
        $this->akunId();
        $this->izinkanAutoDebitProdukWajib();

        $nominal = $this->nominal();
        $anggota = $this->anggota('NOTIF', '2026-01-10', Anggota::STATUS_ACTIVE, true);
        $this->rekeningSukarela($anggota, 0);

        // Penerima: pengurus (role sesuai query service) — deterministik.
        $pengurus = $this->user('pengurus');

        $sebelum = DB::table('notifications')
            ->where('notifiable_id', $pengurus->id)
            ->where('notifiable_type', User::class)
            ->where('type', SimpananWajibDitangguhkanNotification::class)
            ->count();

        $this->service()->processDaily(Carbon::create(2026, 2, 10));

        $sesudah = DB::table('notifications')
            ->where('notifiable_id', $pengurus->id)
            ->where('notifiable_type', User::class)
            ->where('type', SimpananWajibDitangguhkanNotification::class)
            ->get();

        $this->assertGreaterThan(
            $sebelum,
            $sesudah->count(),
            'Administrator wajib dinotifikasi ketika tagihan belum lunas pada jatuh tempo.'
        );

        $payload = json_decode((string) $sesudah->last()->data, true);
        $this->assertSame('SIMPANAN_WAJIB_DITANGGUHKAN', $payload['tipe']);
        $this->assertSame($anggota->id, $payload['member_id']);
        $this->assertSame('SALDO_TIDAK_CUKUP', $payload['alasan']);
        $this->assertArrayHasKey('url', $payload);
    }

    /**
     * INTEGRASI: notifikasi jatuh tempo memuat tagihan dalam status
     * PENDING_CONFIRMATION sehingga administrator dapat menindaklanjuti.
     */
    public function test_notifikasi_penangguhan_menunjuk_status_menunggu_konfirmasi(): void
    {
        $this->pastikanCoa();
        $this->akunId();
        $this->izinkanAutoDebitProdukWajib();

        $anggota = $this->anggota('NU', '2026-01-10', Anggota::STATUS_ACTIVE, false);
        $this->rekeningSukarela($anggota, $this->nominal());
        $bill = $this->terbitkanTagihan($anggota, '2026-02');

        $this->service()->tangguhkanMenungguKonfirmasi($bill, $anggota, 'SALDO_TIDAK_CUKUP');

        $notifikasi = SimpananWajibDitangguhkanNotification::dariTagihan(
            $bill->fresh(),
            'SALDO_TIDAK_CUKUP'
        );

        $data = $notifikasi->toArray(new User);
        $this->assertSame('SIMPANAN_WAJIB_DITANGGUHKAN', $data['tipe']);
        $this->assertSame($bill->id, $data['bill_id']);
        $this->assertStringContainsString('DITANGGUHKAN', $data['pesan']);
        $this->assertStringContainsString(
            MandatorySavingsBill::STATUS_PENDING_CONFIRMATION,
            (string) $data['url']
        );
    }

    /**
     * INTEGRASI: konfirmasi manual oleh petugas MENJALANKAN setoran saat saldo
     * sudah mencukupi — status menjadi PAID + jurnal terposting.
     */
    public function test_konfirmasi_manual_menjalankan_setoran_saat_saldo_mencukupi(): void
    {
        $this->pastikanCoa();
        $this->akunId();
        $this->izinkanAutoDebitProdukWajib();

        $nominal = $this->nominal();
        $petugas = $this->user('teller');
        $anggota = $this->anggota('KONFOK', '2026-01-10', Anggota::STATUS_ACTIVE, true);
        $rekening = $this->rekeningSukarela($anggota, $nominal * 2);

        $bill = $this->terbitkanTagihan($anggota, '2026-02');
        $this->service()->tangguhkanMenungguKonfirmasi($bill, $anggota, 'SALDO_TIDAK_CUKUP');

        // Saldo ditambahkan (anggota menabung), lalu petugas mengonfirmasi manual.
        $berhasil = $this->service()->konfirmasiPenangguhan(
            $bill->fresh(), $petugas->id, 'Saldo sudah mencukupi.'
        );

        $this->assertTrue($berhasil, 'Konfirmasi manual seharusnya berhasil saat saldo mencukupi.');

        $bill->refresh();
        $this->assertSame(MandatorySavingsBill::STATUS_PAID, $bill->status);
        $this->assertSame(MandatorySavingsBill::METODE_AUTO_DEBIT, $bill->payment_method);
        $this->assertNotNull($bill->paid_at);
        $this->assertSame($petugas->id, (int) $bill->confirmed_by);
        $this->assertSame(
            round(($nominal * 2) - $nominal, 2),
            round((float) $rekening->fresh()->saldo, 2)
        );

        $this->assertTrue(
            SimpananWajibTindakan::where('bill_id', $bill->id)
                ->where('jenis', SimpananWajibTindakan::JENIS_KONFIRMASI_MANUAL)
                ->where('status_hasil', SimpananWajibTindakan::STATUS_BERHASIL)
                ->exists(),
            'Konfirmasi manual berhasil tidak tercatat pada riwayat tindakan.'
        );
    }

    /**
     * INTEGRASI: konfirmasi manual DITOLAK sistem bila saldo masih kurang —
     * dana TIDAK dipotong paksa (mitigasi risiko kerugian).
     */
    public function test_konfirmasi_manual_ditolak_saat_saldo_masih_kurang(): void
    {
        $this->pastikanCoa();
        $this->akunId();
        $this->izinkanAutoDebitProdukWajib();

        $nominal = $this->nominal();
        $petugas = $this->user('teller');
        $anggota = $this->anggota('KONFGAGAL', '2026-01-10', Anggota::STATUS_ACTIVE, true);
        $rekening = $this->rekeningSukarela($anggota, $nominal - 1000); // kurang
        $saldoAwal = (float) $rekening->saldo;

        $bill = $this->terbitkanTagihan($anggota, '2026-02');
        $this->service()->tangguhkanMenungguKonfirmasi($bill, $anggota, 'SALDO_TIDAK_CUKUP');

        $berhasil = $this->service()->konfirmasiPenangguhan($bill->fresh(), $petugas->id);

        $this->assertFalse($berhasil, 'Konfirmasi manual harus ditolak bila saldo belum mencukupi.');
        $this->assertSame(
            round($saldoAwal, 2),
            round((float) $rekening->fresh()->saldo, 2),
            'Dana anggota tidak boleh dipotong paksa oleh konfirmasi yang gagal verifikasi.'
        );
        $this->assertNotSame(
            MandatorySavingsBill::STATUS_PAID,
            $bill->fresh()->status,
            'Tagihan tidak boleh berstatus PAID ketika konfirmasi gagal verifikasi.'
        );
    }

    /**
     * INTEGRASI: TOLAK konfirmasi → tagihan dialihkan ke penanganan manual
     * (OVERDUE) tanpa memotong dana, dan jejak audit tercatat.
     */
    public function test_tolak_konfirmasi_mengalihkan_ke_manual_tanpa_memotong_dana(): void
    {
        $this->pastikanCoa();
        $this->akunId();
        $this->izinkanAutoDebitProdukWajib();

        $nominal = $this->nominal();
        $petugas = $this->user('teller');
        $anggota = $this->anggota('TOLAK', '2026-01-10', Anggota::STATUS_ACTIVE, true);
        $rekening = $this->rekeningSukarela($anggota, $nominal * 2);
        $saldoAwal = (float) $rekening->saldo;

        $bill = $this->terbitkanTagihan($anggota, '2026-02');
        $this->service()->tangguhkanMenungguKonfirmasi($bill, $anggota, 'SALDO_TIDAK_CUKUP');

        $hasil = $this->service()->tolakKonfirmasi($bill->fresh(), $petugas->id, 'Tidak disetujui.');

        $this->assertSame(MandatorySavingsBill::STATUS_OVERDUE, $hasil->status);
        $this->assertSame($petugas->id, (int) $hasil->confirmed_by);
        $this->assertSame(
            round($saldoAwal, 2),
            round((float) $rekening->fresh()->saldo, 2),
            'Penolakan konfirmasi tidak boleh memotong dana anggota.'
        );
        $this->assertTrue(
            SimpananWajibTindakan::where('bill_id', $bill->id)
                ->where('jenis', SimpananWajibTindakan::JENIS_TOLAK_KONFIRMASI)
                ->exists(),
            'Penolakan konfirmasi tidak tercatat pada riwayat tindakan.'
        );
    }

    /**
     * INTEGRASI HTTP: endpoint konfirmasi manual mengubah status tagihan dan
     * mengarahkan kembali dengan pesan sukses.
     */
    public function test_http_konfirmasi_manual_mengubah_status_tagihan(): void
    {
        $this->pastikanCoa();
        $this->akunId();
        $this->izinkanAutoDebitProdukWajib();

        $nominal = $this->nominal();
        $petugas = $this->user('teller');
        $anggota = $this->anggota('HTTPKONF', '2026-01-10', Anggota::STATUS_ACTIVE, true);
        $this->rekeningSukarela($anggota, $nominal * 2);

        $bill = $this->terbitkanTagihan($anggota, '2026-02');
        $this->service()->tangguhkanMenungguKonfirmasi($bill, $anggota, 'SALDO_TIDAK_CUKUP');

        $this->actingAs($petugas)
            ->post(route('teller.simpanan-wajib.konfirmasi', $bill->id), ['catatan' => 'Saldo cukup.'])
            ->assertRedirect();

        $this->assertSame(MandatorySavingsBill::STATUS_PAID, $bill->fresh()->status);
    }

    /**
     * INTEGRASI HTTP: endpoint tolak konfirmasi mengalihkan tagihan ke OVERDUE.
     */
    public function test_http_tolak_konfirmasi_mengalihkan_tagihan_ke_overdue(): void
    {
        $this->pastikanCoa();
        $this->akunId();
        $this->izinkanAutoDebitProdukWajib();

        $petugas = $this->user('teller');
        $anggota = $this->anggota('HTTPTOLAK', '2026-01-10', Anggota::STATUS_ACTIVE, true);
        $this->rekeningSukarela($anggota, $this->nominal() * 2);

        $bill = $this->terbitkanTagihan($anggota, '2026-02');
        $this->service()->tangguhkanMenungguKonfirmasi($bill, $anggota, 'SALDO_TIDAK_CUKUP');

        $this->actingAs($petugas)
            ->post(route('teller.simpanan-wajib.tolak', $bill->id), ['catatan' => 'Tunda dulu.'])
            ->assertRedirect();

        $this->assertSame(MandatorySavingsBill::STATUS_OVERDUE, $bill->fresh()->status);
    }

    /**
     * RBAC: role anggota tidak boleh mengakses endpoint konfirmasi manual.
     */
    public function test_konfirmasi_manual_ditolak_untuk_role_anggota(): void
    {
        $this->pastikanCoa();
        $this->akunId();
        $this->izinkanAutoDebitProdukWajib();

        $anggota = $this->anggota('RBACKONF', '2026-01-10', Anggota::STATUS_ACTIVE, true);
        $this->rekeningSukarela($anggota, $this->nominal() * 2);

        $bill = $this->terbitkanTagihan($anggota, '2026-02');
        $this->service()->tangguhkanMenungguKonfirmasi($bill, $anggota, 'SALDO_TIDAK_CUKUP');

        $this->actingAs($this->user('anggota'))
            ->post(route('teller.simpanan-wajib.konfirmasi', $bill->id))
            ->assertForbidden();

        // Status tagihan tidak boleh berubah oleh percobaan tanpa hak akses.
        $this->assertSame(MandatorySavingsBill::STATUS_PENDING_CONFIRMATION, $bill->fresh()->status);
    }
}
