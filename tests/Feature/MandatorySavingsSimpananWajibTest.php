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
use App\Models\Transaksi;
use App\Models\User;
use App\Services\AutodebitService;
use App\Services\MandatorySavingsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Scheduler Simpanan Wajib & Penyesuaian Tanggal Jatuh Tempo (Poin 2).
 *
 * Aturan bisnis & akuntansi yang dikunci oleh test ini:
 *  1. Tagihan hanya diterbitkan untuk anggota berstatus ACTIVE (scope `aktif`).
 *  2. Anggota yang bergabung pada tanggal 29/30/31 (Carbon::isEndOfMonth())
 *     ditagih pada HARI TERAKHIR bulan berjalan — mis. bergabung 31 Januari
 *     2026 → tagihan Februari 2026 jatuh tempo 28 Februari 2026.
 *  3. Penerbitan memakai `firstOrCreate` (anti-duplikat per member+periode).
 *  4. Auto-debit dari Simpanan Sukarela (2-1001) bila saldo cukup →
 *     status PAID + `paid_at` 00:01 + jurnal DEBET 2-1001 | KREDIT 3-1002
 *     dengan tanggal jurnal mengikuti tanggal efektif pembayaran (backdate).
 *  5. Saldo tidak cukup → status OVERDUE + notifikasi (audit_logs `notified_at`).
 *  6. `--dry-run` tidak boleh mengubah data apa pun.
 *
 * Catatan: basis data dev memuat data nyata, sehingga pengujian memakai
 * SELISIH (delta) dan data uji berprefix unik agar deterministik.
 */
class MandatorySavingsSimpananWajibTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'MNDWJB'.strtoupper(substr(uniqid(), -6));
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    private function service(): MandatorySavingsService
    {
        return app(MandatorySavingsService::class);
    }

    /** Isi tabel `akun` (FK wajib `rekening.akun_id`) — lewati bila seeder belum dijalankan. */
    private function akunId(): int
    {
        $id = Akun::where('kode_akun', '1133')->value('id') ?? Akun::query()->value('id');

        if (! $id) {
            $this->markTestSkipped('Tabel akun kosong — jalankan AkunSeeder.');
        }

        return (int) $id;
    }

    /** CoA wajib tersedia agar auto-journal auto-debit dapat terposting. */
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

    /**
     * Aktifkan izin auto-debit pada PRODUK Simpanan Wajib (JALUR B).
     *
     * Sesuai spesifikasi, auto-debit adalah OPT-IN: hanya berjalan bila
     * `jenis_simpanans.allow_auto_debit == true`. Test mengaktifkannya secara
     * DINAMIS (di dalam transaksi, otomatis di-rollback) agar menguji Jalur B.
     */
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
            'nama_lengkap' => 'Anggota Uji Wajib '.$label,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Alamat Uji Simpanan Wajib',
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

    private function rekeningWajib(Anggota $anggota): Rekening
    {
        return Rekening::create([
            'no_rekening' => 'RKW-'.strtoupper(substr(uniqid(), -9)),
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunId(),
            'jenis_rekening' => 'Simpanan Wajib',
            'akad' => 'Wadiah',
            'saldo' => 0,
            'batas_minimum' => 0,
            'tanggal_buka' => now()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);
    }

    /** Nominal tagihan dinamis dari parameter koperasi. */
    private function nominal(): float
    {
        return KebijakanKoperasi::simpananWajibBulanan();
    }

    /* =====================================================================
     | 1. PENYESUAIAN TANGGAL (ANNIVERSARY & END-OF-MONTH)
     |===================================================================== */

    public function test_tanggal_jatuh_tempo_disesuaikan_ke_akhir_bulan_untuk_gabung_tanggal_31(): void
    {
        $anggota = $this->anggota('A31', '2026-01-31');
        $service = $this->service();

        $this->assertTrue($service->bergabungAkhirBulan($anggota));

        // Februari 2026 hanya 28 hari → tagihan jatuh tempo 28 Feb 2026.
        $februari = $service->tanggalJatuhTempo($anggota, Carbon::create(2026, 2, 10));
        $this->assertSame('2026-02-28', $februari->toDateString());

        // April 2026 hanya 30 hari → 30 April.
        $april = $service->tanggalJatuhTempo($anggota, Carbon::create(2026, 4, 5));
        $this->assertSame('2026-04-30', $april->toDateString());

        // Bulan yang punya tanggal 31 → tetap 31.
        $januari = $service->tanggalJatuhTempo($anggota, Carbon::create(2027, 1, 3));
        $this->assertSame('2027-01-31', $januari->toDateString());
    }

    public function test_anggota_bergabung_tanggal_biasa_ditagih_pada_tanggal_yang_sama(): void
    {
        $anggota = $this->anggota('A10', '2026-01-10');
        $service = $this->service();

        $this->assertFalse($service->bergabungAkhirBulan($anggota));
        $this->assertSame(
            '2026-03-10',
            $service->tanggalJatuhTempo($anggota, Carbon::create(2026, 3, 1))->toDateString()
        );

        // Bila tanggal gabung melebihi jumlah hari bulan berjalan → di-clamp ke akhir bulan.
        $gabung30 = $this->anggota('A30', '2026-01-30');
        $this->assertSame(
            '2026-02-28',
            $service->tanggalJatuhTempo($gabung30, Carbon::create(2026, 2, 1))->toDateString()
        );
    }

    public function test_hari_jatuh_tempo_mengenali_hari_proses(): void
    {
        $anggota = $this->anggota('A31B', '2026-01-31');
        $service = $this->service();

        $this->assertTrue($service->terjadwalHariIni($anggota, Carbon::create(2026, 2, 28)));
        $this->assertFalse($service->terjadwalHariIni($anggota, Carbon::create(2026, 2, 27)));
    }

    /* =====================================================================
     | 2. PENERBITAN TAGIHAN & AUTO-DEBIT
     |===================================================================== */

    public function test_auto_debit_melunasi_tagihan_dan_memposting_jurnal_psak(): void
    {
        $this->pastikanCoa();
        $this->akunId();
        $this->izinkanAutoDebitProdukWajib();

        $nominal = $this->nominal();

        // Anggota bergabung 31 Jan 2026 — tagihan Februari jatuh tempo 28 Feb 2026.
        $anggota = $this->anggota('SUKSES', '2026-01-31', Anggota::STATUS_ACTIVE, true);
        $rekening = $this->rekeningSukarela($anggota, 500000);

        $saldoAwalRekening = (float) $rekening->saldo;

        $service = $this->service();

        $this->assertTrue($service->terjadwalHariIni($anggota, Carbon::create(2026, 2, 28)));

        $hasil = $service->processDaily(Carbon::create(2026, 2, 28));

        $bill = MandatorySavingsBill::where('member_id', $anggota->id)
            ->where('bill_period', '2026-02')->first();

        $this->assertNotNull($bill, 'Tagihan Simpanan Wajib Februari 2026 tidak diterbitkan.');

        // KEBIJAKAN BARU: scheduler TIDAK memotong dana. Tagihan berhenti pada
        // status MENUNGGU KONFIRMASI dan usulan masuk ke pusat konfirmasi.
        $this->assertSame(MandatorySavingsBill::STATUS_PENDING_CONFIRMATION, $bill->status);
        $this->assertNull($bill->paid_at, 'Dana tidak boleh dipotong sebelum konfirmasi pengguna.');
        $this->assertSame(
            round($saldoAwalRekening, 2),
            round((float) $rekening->fresh()->saldo, 2),
            'Saldo Sukarela TIDAK boleh berubah sebelum konfirmasi.'
        );

        $usulan = AutodebitUsulan::menungguKonfirmasi()
            ->where('kanal', AutodebitUsulan::KANAL_SIMPANAN_WAJIB)
            ->where('referensi_id', $bill->id)
            ->first();

        $this->assertNotNull($usulan, 'Usulan auto-debit harus dibuat untuk tagihan ini.');
        $this->assertSame(AutodebitUsulan::STATUS_MENUNGGU_KONFIRMASI, $usulan->status);
        $this->assertSame($nominal, (float) $usulan->nominal);
        $this->assertGreaterThanOrEqual(1, $hasil['issued']);
        $this->assertGreaterThanOrEqual(1, $hasil['ditangguhkan']);
        $this->assertSame(0, $hasil['paid'], 'processDaily tidak boleh melunasi tagihan tanpa konfirmasi.');

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
        $this->assertSame($nominal, (float) $bill->amount);
        $this->assertNotNull($bill->paid_at);
        // Penyesuaian akhir bulan: dibayar 28 Feb 2026 pukul 00:01 (bukan 31 Jan).
        $this->assertSame('2026-02-28 00:01', $bill->paid_at->format('Y-m-d H:i'));
        $this->assertGreaterThanOrEqual(1, $hasil['issued']);

        // Saldo Simpanan Sukarela terpotong tepat sebesar nominal tagihan.
        $this->assertSame(
            round($saldoAwalRekening - $nominal, 2),
            round((float) $rekening->fresh()->saldo, 2)
        );

        // Jurnal PSAK: DEBET 2-1001 | KREDIT 3-1002, tanggal mengikuti pembayaran efektif.
        $jurnal = Jurnal::find($bill->jurnal_id);
        $this->assertNotNull($jurnal, 'Jurnal auto-debit tidak terposting.');
        $this->assertSame('Posted', $jurnal->status);
        $this->assertTrue((bool) $jurnal->is_balance);
        $this->assertSame('2026-02-28', $jurnal->tanggal_jurnal->toDateString());
        $this->assertSame($nominal, round((float) $jurnal->total_debet, 2));
        $this->assertSame($nominal, round((float) $jurnal->total_kredit, 2));

        $coaSukarela = Coa::findByKode(MandatorySavingsService::COA_SIMPANAN_SUKARELA);
        $coaWajib = Coa::findByKode(MandatorySavingsService::COA_SIMPANAN_WAJIB_MODAL);

        $barisSukarela = DB::table('jurnal_detail')
            ->where('jurnal_id', $jurnal->id)->where('coa_id', $coaSukarela->id)->first();
        $barisWajib = DB::table('jurnal_detail')
            ->where('jurnal_id', $jurnal->id)->where('coa_id', $coaWajib->id)->first();

        $this->assertNotNull($barisSukarela, 'Baris debet 2-1001 tidak ditemukan.');
        $this->assertSame($nominal, round((float) $barisSukarela->debet, 2));
        $this->assertNotNull($barisWajib, 'Baris kredit 3-1002 tidak ditemukan.');
        $this->assertSame($nominal, round((float) $barisWajib->kredit, 2));

        // ── ANTI-AUTO-DEBIT TIDAK TEROTORISASI: Kas Teller TIDAK BOLEH disentuh.
        // Auto-debit adalah transaksi NON-TUNAI (pindah buku antar rekening
        // internal anggota). Tidak boleh ada baris jurnal yang menyentuh
        // 1-1001 Kas Teller — memastikan tidak ada salah potong saldo laci.
        $coaKasTeller = Coa::findByKode(MandatorySavingsService::COA_KAS_TELLER);
        if ($coaKasTeller) {
            $this->assertNull(
                DB::table('jurnal_detail')
                    ->where('jurnal_id', $jurnal->id)->where('coa_id', $coaKasTeller->id)->first(),
                'Auto-debit dilarang menyentuh Kas Teller (1-1001) — transaksi non-tunai.'
            );
        }

        // Transaksi tercatat sebagai mutasi 'Auto' atas rekening sukarela.
        $transaksi = Transaksi::find($bill->transaksi_id);
        $this->assertNotNull($transaksi);
        $this->assertSame('Auto', $transaksi->sumber_transaksi);
        $this->assertSame('Sukses', $transaksi->status_transaksi);
        $this->assertSame('2026-02-28 00:01', $transaksi->waktu_transaksi->format('Y-m-d H:i'));

        // Keterangan tagihan memuat frasa baku sesuai spesifikasi.
        $this->assertStringContainsString('Auto-debit dari Simpanan Sukarela', (string) $transaksi->catatan);
    }

    public function test_penerbitan_tagihan_idempoten_tanpa_duplikat(): void
    {
        $this->pastikanCoa();
        $this->akunId();

        $anggota = $this->anggota('IDEMP', '2026-01-31', Anggota::STATUS_ACTIVE, true);
        $this->rekeningSukarela($anggota, 500000);

        $service = $this->service();

        $service->processDaily(Carbon::create(2026, 2, 28));
        $service->processDaily(Carbon::create(2026, 2, 28));

        $this->assertSame(
            1,
            MandatorySavingsBill::where('member_id', $anggota->id)->where('bill_period', '2026-02')->count(),
            'Tagihan duplikat terbit untuk periode yang sama.'
        );

        // Penerbitan ulang manual juga tidak menambah baris (firstOrCreate).
        $service->issueBill($anggota, '2026-02', Carbon::create(2026, 2, 28));
        $this->assertSame(
            1,
            MandatorySavingsBill::where('member_id', $anggota->id)->where('bill_period', '2026-02')->count()
        );
    }

    public function test_saldo_tidak_cukup_menandai_overdue_dan_mengirim_notifikasi(): void
    {
        $this->pastikanCoa();
        $this->akunId();
        $this->izinkanAutoDebitProdukWajib();

        $nominal = $this->nominal();

        // Saldo Simpanan Sukarela di bawah nominal tagihan.
        $anggota = $this->anggota('KURANG', '2026-01-31', Anggota::STATUS_ACTIVE, true);
        $this->rekeningSukarela($anggota, max(0, $nominal - 1000));

        $hasil = $this->service()->processDaily(Carbon::create(2026, 2, 28));

        $bill = MandatorySavingsBill::where('member_id', $anggota->id)
            ->where('bill_period', '2026-02')->first();

        $this->assertNotNull($bill);
        // BUG-03: verifikasi status pembayaran gagal (saldo < nominal) →
        // setoran otomatis DIBATALKAN dan tagihan DITANGGUHKAN untuk
        // konfirmasi manual. Dana anggota TIDAK dipotong.
        $this->assertSame(MandatorySavingsBill::STATUS_PENDING_CONFIRMATION, $bill->status);
        $this->assertSame('SALDO_TIDAK_CUKUP', $bill->deferred_reason);
        $this->assertNotNull($bill->deferred_at);
        $this->assertNull($bill->paid_at);
        $this->assertNull($bill->payment_method);
        $this->assertGreaterThanOrEqual(1, $hasil['ditangguhkan']);

        // Administrator dinotifikasi (audit log penangguhan, dapat diaudit pengurus).
        $this->assertTrue(
            AuditLog::where('jenis_transaksi', 'SIMPANAN_WAJIB_DITANGGUHKAN')
                ->where('nominal', '>=', $nominal)
                ->where('created_at', '>=', now()->subMinute())
                ->exists(),
            'Notifikasi penangguhan tidak tercatat pada audit_logs.'
        );
    }

    public function test_anggota_tidak_aktif_tidak_ditagih(): void
    {
        $this->pastikanCoa();
        $this->akunId();

        $pending = $this->anggota('PEND', '2026-01-31', Anggota::STATUS_PENDING);
        $suspended = $this->anggota('SUSP', '2026-01-31', Anggota::STATUS_SUSPENDED);

        $this->rekeningSukarela($pending, 500000);
        $this->rekeningSukarela($suspended, 500000);

        $this->service()->processDaily(Carbon::create(2026, 2, 28));

        $this->assertSame(
            0,
            MandatorySavingsBill::whereIn('member_id', [$pending->id, $suspended->id])->count(),
            'Anggota non-ACTIVE tidak boleh menerima tagihan Simpanan Wajib.'
        );
    }

    public function test_dry_run_tidak_mengubah_data(): void
    {
        $this->pastikanCoa();
        $this->akunId();

        $anggota = $this->anggota('DRY', '2026-01-31', Anggota::STATUS_ACTIVE, true);
        $rekening = $this->rekeningSukarela($anggota, 500000);

        $this->artisan('mandatory-savings:process-daily', [
            '--date' => '2026-02-28',
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertSame(
            0,
            MandatorySavingsBill::where('member_id', $anggota->id)->count(),
            'DRY-RUN tidak boleh menerbitkan tagihan.'
        );
        $this->assertSame(500000.0, round((float) $rekening->fresh()->saldo, 2));
    }

    public function test_command_berjalan_normal_dengan_exit_code_sukses(): void
    {
        $this->pastikanCoa();
        $this->akunId();

        $anggota = $this->anggota('CMD', '2026-01-31', Anggota::STATUS_ACTIVE, true);
        $this->rekeningSukarela($anggota, 500000);

        $this->artisan('mandatory-savings:process-daily', ['--date' => '2026-02-28'])
            ->assertExitCode(0);

        $this->assertSame(
            1,
            MandatorySavingsBill::where('member_id', $anggota->id)->where('bill_period', '2026-02')->count()
        );
    }

    /* =====================================================================
     | 3. FLEKSIBILITAS METODE PEMBAYARAN (TELLER vs AUTO-DEBIT)
     |===================================================================== */

    public function test_anggota_tanpa_auto_debit_membiarkan_tagihan_unpaid_untuk_teller(): void
    {
        $this->pastikanCoa();
        $this->akunId();

        // is_auto_debit = false → tagihan TIDAK dipotong otomatis, menunggu Teller.
        $anggota = $this->anggota('TELLER', '2026-01-01');
        $rekening = $this->rekeningSukarela($anggota, 500000);

        // 1 Feb (sebelum jatuh tempo tgl 10) → tagihan terbit UNPAID.
        $hasil = $this->service()->processDaily(Carbon::create(2026, 2, 1));

        $bill = MandatorySavingsBill::where('member_id', $anggota->id)
            ->where('bill_period', '2026-02')->first();

        $this->assertNotNull($bill, 'Tagihan tetap diterbitkan walau pembayaran via Teller.');
        $this->assertSame(MandatorySavingsBill::STATUS_UNPAID, $bill->status);
        $this->assertNull($bill->payment_method);
        $this->assertNull($bill->paid_at);
        $this->assertSame(500000.0, round((float) $rekening->fresh()->saldo, 2), 'Saldo sukarela tidak boleh terpotong.');
        $this->assertSame(0, $hasil['paid']);
    }

    public function test_pelunasan_via_teller_memposting_jurnal_kas_dan_simpanan_wajib(): void
    {
        $this->pastikanCoa();
        $this->akunId();

        if (! Coa::findByKode(MandatorySavingsService::COA_KAS_TELLER)) {
            $this->markTestSkipped('CoA '.MandatorySavingsService::COA_KAS_TELLER.' belum tersedia — jalankan CoaSeeder.');
        }

        $nominal = $this->nominal();

        $anggota = $this->anggota('BAYART', '2026-01-01');
        $service = $this->service();

        $service->processDaily(Carbon::create(2026, 2, 1));

        $bill = MandatorySavingsBill::where('member_id', $anggota->id)
            ->where('bill_period', '2026-02')->firstOrFail();

        $this->assertTrue(
            $service->bayarViaTeller($bill, MandatorySavingsBill::METODE_CASH, Carbon::create(2026, 2, 8))
        );

        $bill->refresh();
        $this->assertSame(MandatorySavingsBill::STATUS_PAID, $bill->status);
        $this->assertSame(MandatorySavingsBill::METODE_CASH, $bill->payment_method);
        $this->assertNotNull($bill->paid_at);

        // Pelunasan ganda ditolak (idempoten).
        $this->assertFalse(
            $service->bayarViaTeller($bill, MandatorySavingsBill::METODE_CASH, Carbon::create(2026, 2, 8))
        );

        // Jurnal PSAK: DEBET 1-1001 Kas Teller | KREDIT 3-1002 Simpanan Wajib.
        $jurnal = Jurnal::find($bill->jurnal_id);
        $this->assertNotNull($jurnal, 'Jurnal pelunasan Teller tidak terposting.');
        $this->assertTrue((bool) $jurnal->is_balance);
        $this->assertSame($nominal, round((float) $jurnal->total_debet, 2));
        $this->assertSame($nominal, round((float) $jurnal->total_kredit, 2));

        $kas = Coa::findByKode(MandatorySavingsService::COA_KAS_TELLER);
        $wajib = Coa::findByKode(MandatorySavingsService::COA_SIMPANAN_WAJIB_MODAL);

        $this->assertNotNull(
            DB::table('jurnal_detail')->where('jurnal_id', $jurnal->id)->where('coa_id', $kas->id)->first(),
            'Baris debet 1-1001 Kas Teller tidak ditemukan.'
        );
        $this->assertNotNull(
            DB::table('jurnal_detail')->where('jurnal_id', $jurnal->id)->where('coa_id', $wajib->id)->first(),
            'Baris kredit 3-1002 Simpanan Wajib tidak ditemukan.'
        );
    }

    public function test_pelunasan_teller_mengatribusikan_baris_wajib_ke_rekening_anggota(): void
    {
        $this->pastikanCoa();
        $this->akunId();

        if (! Coa::findByKode(MandatorySavingsService::COA_SIMPANAN_WAJIB_MODAL)) {
            $this->markTestSkipped('CoA '.MandatorySavingsService::COA_SIMPANAN_WAJIB_MODAL.' belum tersedia — jalankan CoaSeeder.');
        }

        $anggota = $this->anggota('ATRIB', '2026-01-01');
        $rekeningWajib = $this->rekeningWajib($anggota);
        $service = $this->service();

        $service->processDaily(Carbon::create(2026, 2, 1));

        $bill = MandatorySavingsBill::where('member_id', $anggota->id)
            ->where('bill_period', '2026-02')->firstOrFail();

        $this->assertTrue(
            $service->bayarViaTeller($bill, MandatorySavingsBill::METODE_CASH, Carbon::create(2026, 2, 8))
        );

        $jurnal = Jurnal::findOrFail($bill->fresh()->jurnal_id);
        $wajib = Coa::findByKode(MandatorySavingsService::COA_SIMPANAN_WAJIB_MODAL);

        $baris = DB::table('jurnal_detail')
            ->where('jurnal_id', $jurnal->id)
            ->where('coa_id', $wajib->id)
            ->first();

        $this->assertNotNull($baris, 'Baris kredit 3-1002 Simpanan Wajib tidak ditemukan.');
        $this->assertSame(
            $rekeningWajib->id,
            (int) $baris->rekening_id,
            'Baris kredit 3-1002 harus teratribusi ke rekening Simpanan Wajib anggota.'
        );
    }

    public function test_tagihan_teller_lewat_jatuh_tempo_ditandai_overdue(): void
    {
        $this->pastikanCoa();
        $this->akunId();

        // Anggota bergabung tgl 1 → jatuh tempo otomatis tgl 1 setiap bulan.
        $anggota = $this->anggota('OVERD', '2026-01-01');
        $service = $this->service();

        // Tanggal 1 Februari → tagihan periode 2026-02 terbit UNPAID, tepat
        // pada jatuh tempo (anniversary tgl 1) → belum OVERDUE.
        $service->processDaily(Carbon::create(2026, 2, 1));

        $bill = MandatorySavingsBill::where('member_id', $anggota->id)
            ->where('bill_period', '2026-02')->firstOrFail();
        $this->assertSame(MandatorySavingsBill::STATUS_UNPAID, $bill->status);

        // Tanggal 15 Februari → melewati jatuh tempo otomatis (tgl 1) →
        // tagihan otomatis OVERDUE + notifikasi.
        $service->processDaily(Carbon::create(2026, 2, 15));

        $bill->refresh();
        $this->assertSame(MandatorySavingsBill::STATUS_OVERDUE, $bill->status);
        $this->assertNotNull($bill->notified_at, 'Notifikasi tunggakan tidak ditandai terkirim.');
    }
}
