<?php

namespace Tests\Feature;

use App\Exceptions\JournalPostingException;
use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\AutoJournalingService;
use App\Services\JurnalReconciliationService;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PENGUATAN SISTEM PENCATATAN KEUANGAN — verifikasi enam tuntutan inti.
 *
 *  1. HANYA transaksi sukses yang dicatat ke buku besar (gate status).
 *  2. Validasi ketat: kegagalan posting MELEMPAR exception (bukan silent null),
 *     sehingga DB::transaction pemanggil ikut rollback secara atomik.
 *  3. Keseimbangan AKTIVA–PASIVA terjaga; tidak ada jurnal timbang/orphan.
 *  4. Rekonsiliasi otomatis: inline (setiap posting) & menyeluruh (command).
 *  5. Logging terperinci alur transaksi (MULAI / SUKSES / GAGAL).
 *  6. Skenario GAGAL pun tidak meninggalkan jejak setengah jadi di buku besar.
 */
class PenguatanPencatatanKeuanganTest extends TestCase
{
    use DatabaseTransactions;

    private AutoJournalingService $jurnal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jurnal = app(AutoJournalingService::class);

        $this->pastikanCoa();
    }

    /* =====================================================================
     | FIXTURE
     ===================================================================== */

    private function pastikanCoa(): void
    {
        $daftar = [
            AutoJournalingService::KAS_TELLER => ['Kas Teller', 'Aset'],
            AutoJournalingService::SIMPANAN_SUKARELA => ['Simpanan Sukarela', 'Kewajiban'],
            AutoJournalingService::DANA_KEBAJIKAN => ['Dana Kebajikan', 'Kewajiban'],
            AutoJournalingService::PENDAPATAN_MARGIN => ['Pendapatan Margin', 'Pendapatan'],
        ];

        foreach ($daftar as $kode => [$nama, $kategori]) {
            $kepala = substr($kode, 0, 1);

            [$klasifikasi, $tipe] = match ($kepala) {
                '1' => ['Aset Lancar', 'Debet'],
                '2' => ['Kewajiban Jangka Pendek', 'Kredit'],
                default => ['Pendapatan', 'Kredit'],
            };

            Coa::firstOrCreate(
                ['kode_akun' => $kode],
                [
                    'nama_akun' => $nama,
                    'kategori' => $kategori,
                    'klasifikasi' => $klasifikasi,
                    'tipe_saldo' => $tipe,
                    'is_active' => true,
                ]
            );
        }
    }

    private function userTeller(): User
    {
        return User::firstOrCreate(
            ['email' => 'uji.penguatan.teller@test.local'],
            [
                'name' => 'Uji Penguatan Teller',
                'password' => Hash::make('password'),
                'role' => 'teller',
                'is_active' => true,
            ]
        );
    }

    private function transaksi(string $statusTransaksi, string $statusTeknis = 'success'): Transaksi
    {
        return Transaksi::create([
            'no_transaksi' => 'TRX-PK-'.strtoupper(bin2hex(random_bytes(4))),
            'waktu_transaksi' => now(),
            'jenis_transaksi' => 'Uji Penguatan',
            'posisi' => 'Debet',
            'jumlah' => 100_000,
            'sumber_transaksi' => 'Teller',
            'status_transaksi' => $statusTransaksi,
            'status' => $statusTeknis,
            'user_id' => $this->userTeller()->id,
        ]);
    }

    private function jumlahJurnal(): int
    {
        return Jurnal::count();
    }

    /* =====================================================================
     | 1. GATE STATUS KEBERHASILAN — hanya transaksi sukses yang dicatat
     ===================================================================== */

    public function test_transaksi_sukses_boleh_dicatat_ke_buku_besar(): void
    {
        $transaksi = $this->transaksi(Transaksi::STATUS_TRANSAKSI_SUKSES);

        $jurnal = $this->jurnal->post('Setoran tunai sukses', [
            ['kode' => AutoJournalingService::KAS_TELLER,        'debet' => 100_000],
            ['kode' => AutoJournalingService::SIMPANAN_SUKARELA, 'kredit' => 100_000],
        ], 'Setoran', $transaksi->id, null, $this->userTeller()->id);

        $this->assertNotNull($jurnal, 'Transaksi sukses WAJIB boleh dicatat.');
        $this->assertSame($transaksi->id, $jurnal->transaksi_id);
    }

    /**
     * Status transaksi yang belum boleh masuk buku besar.
     */
    #[DataProvider('statusGagalProvider')]
    public function test_transaksi_belum_sukses_ditolak_sebelum_masuk_buku_besar(
        string $statusTransaksi,
        string $statusTeknis
    ): void {
        $transaksi = $this->transaksi($statusTransaksi, $statusTeknis);
        $sebelum = $this->jumlahJurnal();

        try {
            $this->jurnal->post('Upaya catat transaksi belum sukses', [
                ['kode' => AutoJournalingService::KAS_TELLER,        'debet' => 100_000],
                ['kode' => AutoJournalingService::SIMPANAN_SUKARELA, 'kredit' => 100_000],
            ], 'Setoran', $transaksi->id, null, $this->userTeller()->id);

            $this->fail('Transaksi belum sukses HARUS ditolak dari buku besar.');
        } catch (JournalPostingException $e) {
            $this->assertSame('TRANSAKSI_BELUM_SUKSES', $e->aturan);
            $this->assertSame($transaksi->id, $e->transaksiId);
        }

        $this->assertSame(
            $sebelum,
            $this->jumlahJurnal(),
            'Tidak boleh ada jurnal terbentuk untuk transaksi gagal/pending/void.'
        );
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function statusGagalProvider(): array
    {
        return [
            'gagal' => [Transaksi::STATUS_TRANSAKSI_GAGAL, Transaksi::STATUS_TEKNIS_REJECTED],
            'pending' => [Transaksi::STATUS_TRANSAKSI_PENDING, Transaksi::STATUS_TEKNIS_PENDING_APPROVAL],
            'voided' => [Transaksi::STATUS_TRANSAKSI_VOIDED, 'voided'],
            'pending_void' => [Transaksi::STATUS_TRANSAKSI_PENDING_VOID, Transaksi::STATUS_TEKNIS_SUCCESS],
        ];
    }

    /* =====================================================================
     | 1b. KONTEKS REVERSAL — pengecualian eksplisit, terbatas, dapat diaudit
     ===================================================================== */

    /**
     * Jurnal PEMBALIK (`reversal: true`) HARUS tetap boleh diposting walau
     * transaksi asalnya sedang `Pending Void` — dan harus ditandai permanen.
     *
     * Tanpa pengecualian ini, seluruh alur void & penolakan AML mustahil karena
     * gate status akan menolak jurnal pembaliknya.
     */
    public function test_jurnal_pembalik_boleh_diposting_untuk_transaksi_pending_void(): void
    {
        $transaksi = $this->transaksi(Transaksi::STATUS_TRANSAKSI_PENDING_VOID, Transaksi::STATUS_TEKNIS_SUCCESS);

        $jurnal = $this->jurnal->post('REVERSAL - uji konteks pembalikan', [
            ['kode' => AutoJournalingService::SIMPANAN_SUKARELA, 'debet' => 100_000],
            ['kode' => AutoJournalingService::KAS_TELLER,        'kredit' => 100_000],
        ], 'Reversal Uji', $transaksi->id, null, $this->userTeller()->id,
            null, null, null, null, null, null, null, false, true);

        $this->assertNotNull($jurnal, 'Jurnal pembalik harus tetap boleh diposting.');
        $this->assertTrue(
            (bool) $jurnal->fresh()->is_reversal,
            'Jurnal pembalik WAJIB ditandai permanen is_reversal = true agar dapat diaudit.'
        );
    }

    /** Posting NORMAL untuk transaksi `Pending Void` tetap ditolak. */
    public function test_posting_normal_untuk_transaksi_pending_void_tetap_ditolak(): void
    {
        $transaksi = $this->transaksi(Transaksi::STATUS_TRANSAKSI_PENDING_VOID, Transaksi::STATUS_TEKNIS_SUCCESS);

        $this->expectException(JournalPostingException::class);

        $this->jurnal->post('Setoran normal saat void', [
            ['kode' => AutoJournalingService::KAS_TELLER,        'debet' => 100_000],
            ['kode' => AutoJournalingService::SIMPANAN_SUKARELA, 'kredit' => 100_000],
        ], 'Setoran', $transaksi->id, null, $this->userTeller()->id);
    }

    /** Posting normal tidak boleh meninggalkan tanda reversal. */
    public function test_posting_normal_tidak_ditandai_reversal(): void
    {
        $transaksi = $this->transaksi(Transaksi::STATUS_TRANSAKSI_SUKSES);

        $jurnal = $this->jurnal->post('Setoran normal', [
            ['kode' => AutoJournalingService::KAS_TELLER,        'debet' => 100_000],
            ['kode' => AutoJournalingService::SIMPANAN_SUKARELA, 'kredit' => 100_000],
        ], 'Setoran', $transaksi->id, null, $this->userTeller()->id);

        $this->assertFalse((bool) $jurnal->fresh()->is_reversal);
    }

    /**
     * Rekonsiliasi inline TIDAK boleh menolak jurnal pembalik yang sah, dan
     * rekonsiliasi menyeluruh TIDAK boleh melaporkannya sebagai temuan.
     */
    public function test_rekonsiliasi_menerima_jurnal_pembalik_yang_sah(): void
    {
        $transaksi = $this->transaksi(Transaksi::STATUS_TRANSAKSI_PENDING_VOID, Transaksi::STATUS_TEKNIS_SUCCESS);

        $jurnal = $this->jurnal->post('REVERSAL - uji rekonsiliasi', [
            ['kode' => AutoJournalingService::SIMPANAN_SUKARELA, 'debet' => 100_000],
            ['kode' => AutoJournalingService::KAS_TELLER,        'kredit' => 100_000],
        ], 'Reversal Uji', $transaksi->id, null, $this->userTeller()->id,
            null, null, null, null, null, null, null, false, true);

        $this->assertNotNull($jurnal);

        $rekonsiliasi = app(JurnalReconciliationService::class);

        $this->assertSame(
            [],
            $rekonsiliasi->periksaJurnal($jurnal),
            'Jurnal pembalik yang sah tidak boleh menghasilkan temuan rekonsiliasi.'
        );

        $hasil = $rekonsiliasi->rekonsiliasiBukuBesar();

        $temuanTransaksi = array_values(array_filter(
            $hasil['temuan'],
            static fn (array $t): bool => ($t['aturan'] ?? null) === 'TRANSAKSI_BELUM_SUKSES'
                && (int) ($t['konteks']['transaksi_id'] ?? 0) === (int) $transaksi->id
        ));

        $this->assertSame(
            [],
            $temuanTransaksi,
            'Rekonsiliasi menyeluruh tidak boleh melaporkan jurnal pembalik sebagai TRANSAKSI_BELUM_SUKSES.'
        );
    }

    public function test_transaksi_tidak_ditemukan_ditolak(): void
    {
        $sebelum = $this->jumlahJurnal();

        try {
            $this->jurnal->post('Jurnal ke transaksi hantu', [
                ['kode' => AutoJournalingService::KAS_TELLER,        'debet' => 50_000],
                ['kode' => AutoJournalingService::SIMPANAN_SUKARELA, 'kredit' => 50_000],
            ], 'Setoran', 999_999_999, null, $this->userTeller()->id);

            $this->fail('Transaksi tidak ditemukan HARUS ditolak.');
        } catch (JournalPostingException $e) {
            $this->assertSame('TRANSAKSI_TIDAK_DITEMUKAN', $e->aturan);
        }

        $this->assertSame($sebelum, $this->jumlahJurnal());
    }

    public function test_jurnal_manual_tanpa_transaksi_tetap_diizinkan(): void
    {
        // Penyesuaian/akrual manual tidak terikat transaksi kas → tetap sah.
        $jurnal = $this->jurnal->post('Penyesuaian manual', [
            ['kode' => AutoJournalingService::KAS_TELLER,        'debet' => 10_000],
            ['kode' => AutoJournalingService::SIMPANAN_SUKARELA, 'kredit' => 10_000],
        ], 'Jurnal Umum', null, null, $this->userTeller()->id);

        $this->assertNotNull($jurnal, 'Jurnal manual tanpa transaksi harus tetap diizinkan.');
    }

    /* =====================================================================
     | 2. VALIDASI KETAT — kegagalan MELEMPAR & tidak menyisakan jurnal
     ===================================================================== */

    public function test_coa_tidak_ditemukan_melempar_dan_tidak_menyisakan_jurnal(): void
    {
        $sebelum = $this->jumlahJurnal();

        try {
            $this->jurnal->post('Akun tidak dikenal', [
                ['kode' => '9-9999', 'debet' => 1_000],
                ['kode' => '9-9998', 'kredit' => 1_000],
            ], 'Jurnal Umum');

            $this->fail('Kode CoA tak dikenal HARUS ditolak.');
        } catch (JournalPostingException $e) {
            $this->assertSame('COA_TIDAK_DITEMUKAN', $e->aturan);
        }

        $this->assertSame($sebelum, $this->jumlahJurnal(), 'Tidak boleh ada jurnal orphan.');
    }

    public function test_jurnal_tidak_balance_melempar(): void
    {
        $sebelum = $this->jumlahJurnal();

        try {
            $this->jurnal->post('Debet bukan kredit', [
                ['kode' => AutoJournalingService::KAS_TELLER,        'debet' => 100_000],
                ['kode' => AutoJournalingService::SIMPANAN_SUKARELA, 'kredit' => 90_000],
            ], 'Jurnal Umum');

            $this->fail('Jurnal tidak balance HARUS ditolak.');
        } catch (JournalPostingException $e) {
            $this->assertSame('JURNAL_TIDAK_BALANCE', $e->aturan);
        }

        $this->assertSame($sebelum, $this->jumlahJurnal());
    }

    public function test_coa_nonaktif_ditolak(): void
    {
        // Akun dinonaktifkan → `Coa::findByKode()` (hanya is_active=true) tidak
        // mengembalikannya, sehingga posting ditolak sebagai COA_TIDAK_DITEMUKAN.
        // Intinya sama: akun nonaktif TIDAK boleh menerima mutasi baru.
        $coa = Coa::findByKode(AutoJournalingService::SIMPANAN_SUKARELA);
        $coa->update(['is_active' => false]);

        $sebelum = $this->jumlahJurnal();

        try {
            $this->jurnal->post('Pakai akun nonaktif', [
                ['kode' => AutoJournalingService::KAS_TELLER,        'debet' => 100_000],
                ['kode' => AutoJournalingService::SIMPANAN_SUKARELA, 'kredit' => 100_000],
            ], 'Jurnal Umum');

            $this->fail('Akun nonaktif HARUS ditolak.');
        } catch (JournalPostingException $e) {
            $this->assertSame('COA_TIDAK_DITEMUKAN', $e->aturan);
        }

        $this->assertSame($sebelum, $this->jumlahJurnal());
    }

    /**
     * Bukti rollback atomik: ketika posting gagal di tengah DB::transaction,
     * seluruh perubahan pemanggil dibatalkan — tidak ada data setengah jadi.
     */
    public function test_kegagalan_posting_memicu_rollback_transaksi_pemanggil(): void
    {
        $sebelum = $this->jumlahJurnal();

        try {
            DB::transaction(function () {
                Transaksi::create([
                    'no_transaksi' => 'TRX-ROLLBACK-'.strtoupper(bin2hex(random_bytes(4))),
                    'waktu_transaksi' => now(),
                    'jenis_transaksi' => 'Uji Rollback',
                    'posisi' => 'Debet',
                    'jumlah' => 100_000,
                    'sumber_transaksi' => 'Teller',
                    'status_transaksi' => Transaksi::STATUS_TRANSAKSI_SUKSES,
                    'status' => Transaksi::STATUS_TEKNIS_SUCCESS,
                    'user_id' => $this->userTeller()->id,
                ]);

                // Jurnal sengaja tidak balance → memicu JournalPostingException.
                $this->jurnal->post('Gagal di tengah transaksi', [
                    ['kode' => AutoJournalingService::KAS_TELLER,        'debet' => 100_000],
                    ['kode' => AutoJournalingService::SIMPANAN_SUKARELA, 'kredit' => 90_000],
                ], 'Jurnal Umum');
            });

            $this->fail('DB::transaction HARUS melempar karena posting gagal.');
        } catch (JournalPostingException $e) {
            $this->assertSame('JURNAL_TIDAK_BALANCE', $e->aturan);
        }

        $this->assertSame($sebelum, $this->jumlahJurnal());
        $this->assertSame(
            0,
            Transaksi::where('jenis_transaksi', 'Uji Rollback')->count(),
            'Transaksi pemanggil harus ikut ter-rollback — tidak ada data setengah jadi.'
        );
    }

    /* =====================================================================
     | 3. KESEIMBANGAN AKTIVA–PASIVA
     ===================================================================== */

    public function test_jurnal_sehat_menjaga_keseimbangan_aktiva_pasiva(): void
    {
        $transaksi = $this->transaksi(Transaksi::STATUS_TRANSAKSI_SUKSES);

        $jurnal = $this->jurnal->post('Setoran seimbang', [
            ['kode' => AutoJournalingService::KAS_TELLER,        'debet' => 250_000],
            ['kode' => AutoJournalingService::SIMPANAN_SUKARELA, 'kredit' => 250_000],
        ], 'Setoran', $transaksi->id, null, $this->userTeller()->id);

        $this->assertNotNull($jurnal);

        $detail = JurnalDetail::where('jurnal_id', $jurnal->id)->get();

        $this->assertSame(
            (float) $detail->sum('debet'),
            (float) $detail->sum('kredit'),
            'Total debet WAJIB sama dengan total kredit (double-entry).'
        );
    }

    /* =====================================================================
     | 4. REKONSILIASI OTOMATIS — inline & menyeluruh
     ===================================================================== */

    public function test_rekonsiliasi_inline_menolak_jurnal_cacat(): void
    {
        // Bangun jurnal valid, lalu rusak baris detailnya SETELAH tersimpan
        // untuk mensimulasikan korupsi data, lalu jalankan rekonsiliasi inline.
        $transaksi = $this->transaksi(Transaksi::STATUS_TRANSAKSI_SUKSES);

        $jurnal = $this->jurnal->post('Jurnal untuk diuji', [
            ['kode' => AutoJournalingService::KAS_TELLER,        'debet' => 100_000],
            ['kode' => AutoJournalingService::SIMPANAN_SUKARELA, 'kredit' => 100_000],
        ], 'Setoran', $transaksi->id, null, $this->userTeller()->id);

        // Korupsi: hapus baris kredit → jurnal jadi timbang.
        JurnalDetail::where('jurnal_id', $jurnal->id)
            ->where('kredit', '>', 0)
            ->delete();

        $this->expectException(JournalPostingException::class);
        app(JurnalReconciliationService::class)->pastikanJurnalSehat($jurnal->fresh());
    }

    public function test_periksa_jurnal_mendeteksi_jurnal_tanpa_detail(): void
    {
        $jurnal = Jurnal::create([
            'no_jurnal' => 'JU-KORUP-'.strtoupper(bin2hex(random_bytes(4))),
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'UJI',
            'keterangan' => 'Jurnal tanpa detail',
            'total_debet' => 0,
            'total_kredit' => 0,
            'is_balance' => true,
            'status' => 'Draft',
        ]);

        $temuan = app(JurnalReconciliationService::class)->periksaJurnal($jurnal);

        $aturan = array_column($temuan, 'aturan');

        $this->assertContains('JURNAL_TANPA_DETAIL', $aturan);
    }

    public function test_rekonsiliasi_menyeluruh_mendeteksi_transaksi_belum_sukses(): void
    {
        // Jurnal sehat namun tertaut transaksi pending → harus jadi temuan.
        $transaksi = $this->transaksi(Transaksi::STATUS_TRANSAKSI_PENDING, Transaksi::STATUS_TEKNIS_PENDING_APPROVAL);

        $jurnal = $this->jurnal->post('Jurnal transaksi pending', [
            ['kode' => AutoJournalingService::KAS_TELLER,        'debet' => 100_000],
            ['kode' => AutoJournalingService::SIMPANAN_SUKARELA, 'kredit' => 100_000],
        ], 'Jurnal Umum'); // tanpa transaksi_id → lolos gate, dibiarkan manual

        // Tautkan manual ke transaksi pending untuk mensimulasikan data lama.
        $jurnal->update(['transaksi_id' => $transaksi->id]);

        $temuan = app(JurnalReconciliationService::class)->periksaJurnal($jurnal->fresh());
        $aturan = array_column($temuan, 'aturan');

        $this->assertContains('TRANSAKSI_BELUM_SUKSES', $aturan);
    }

    public function test_command_rekonsiliasi_mengembalikan_success_saat_bersih(): void
    {
        $this->artisan('jurnal:rekonsiliasi')
            ->assertExitCode(0);
    }

    /* =====================================================================
     | 5. LOGGING TERPERINCI ALUR TRANSAKSI
     ===================================================================== */

    public function test_alur_posting_sukses_tercatat_ke_log(): void
    {
        Log::spy();

        $transaksi = $this->transaksi(Transaksi::STATUS_TRANSAKSI_SUKSES);

        $this->jurnal->post('Uji logging sukses', [
            ['kode' => AutoJournalingService::KAS_TELLER,        'debet' => 100_000],
            ['kode' => AutoJournalingService::SIMPANAN_SUKARELA, 'kredit' => 100_000],
        ], 'Setoran', $transaksi->id, null, $this->userTeller()->id);

        // Tahap MULAI & SUKSES harus ter-log dengan transaksi_id untuk penelusuran.
        Log::shouldHaveReceived('info')
            ->withArgs(fn ($pesan, $konteks = []) => str_contains($pesan, 'MULAI posting jurnal')
                && ($konteks['tahap'] ?? null) === 'MULAI'
                && ($konteks['transaksi_id'] ?? null) === $transaksi->id)
            ->atLeast()->once();

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($pesan, $konteks = []) => str_contains($pesan, 'SUKSES')
                && ($konteks['tahap'] ?? null) === 'SUKSES'
                && ($konteks['transaksi_id'] ?? null) === $transaksi->id)
            ->atLeast()->once();
    }

    public function test_alur_posting_gagal_tercatat_sebagai_error(): void
    {
        Log::spy();

        try {
            $this->jurnal->post('Uji logging gagal', [
                ['kode' => AutoJournalingService::KAS_TELLER,        'debet' => 100_000],
                ['kode' => AutoJournalingService::SIMPANAN_SUKARELA, 'kredit' => 90_000],
            ], 'Jurnal Umum');
        } catch (JournalPostingException) {
            // diharapkan
        }

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($pesan, $konteks = []) => str_contains($pesan, 'GAGAL posting jurnal')
                && ($konteks['tahap'] ?? null) === 'GAGAL'
                && ($konteks['aturan'] ?? null) === 'JURNAL_TIDAK_BALANCE')
            ->atLeast()->once();
    }

    /* =====================================================================
     | 6. PARAMETER KEBIJAKAN
     ===================================================================== */

    public function test_parameter_gate_dan_rekonsiliasi_aktif_secara_default(): void
    {
        $this->assertTrue(
            Setting::postingWajibTransaksiSukses(),
            'Default aman: gate status keberhasilan WAJIB aktif.'
        );
        $this->assertTrue(
            Setting::rekonsiliasiInlineAktif(),
            'Default aman: rekonsiliasi inline WAJIB aktif.'
        );
    }
}
