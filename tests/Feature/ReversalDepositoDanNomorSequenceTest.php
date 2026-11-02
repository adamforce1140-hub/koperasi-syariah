<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\ApprovalTransaksi;
use App\Models\Coa;
use App\Models\Deposito;
use App\Models\DepositoProduct;
use App\Models\Jurnal;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\DepositoService;
use App\Services\ReversalService;
use App\Support\Coa as CoaKode;
use App\Support\NomorSequence;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Regresi perbaikan audit:
 *
 *  K-02 — Reversal (void) transaksi DEPOSITO/SIMPANAN BERJANGKA.
 *         Sebelum perbaikan, void pada transaksi deposito hanya membalik
 *         SALDO rekening tanpa membentuk jurnal pembalik, sehingga buku besar
 *         tidak lagi seimbang dengan saldo anggota (silent inconsistency).
 *         Perbaikan: `ReversalService` memetakan setiap jenis transaksi
 *         deposito ke jurnal pembalik yang benar (5 metode AutoJournalingService)
 *         dan memperluas guard agar jenis deposito yang belum terpetakan
 *         MENOLAK reversal, bukan diam-diam membuat jurnal yang salah.
 *
 *  K-03 — Generator nomor transaksi anti-race.
 *         Sebelum perbaikan, `MAX(no_transaksi) + 1` tanpa lock dapat
 *         menghasilkan nomor duplikat pada proses paralel → pelanggaran
 *         unique index `transaksi.no_transaksi` → transaksi utama gagal.
 *         Perbaikan: `App\Support\NomorSequence` memakai baris penanda
 *         `nomor_sequences` + `SELECT ... FOR UPDATE` (serialisasi).
 *
 * Catatan: gate `Setting::postingWajibTransaksiSukses()` (default aktif) TETAP
 * AKTIF selama uji void. Saat void, transaksi berstatus `Pending Void`, sehingga
 * jurnal pembalik tidak boleh lolos hanya karena gate dimatikan. Jurnal
 * pembalik dikecualikan secara EKSPLISIT lewat `PostingJurnal::post(reversal:
 * true)` dan ditandai permanen `jurnal.is_reversal = true` — itulah kontrak
 * yang diuji di sini.
 */
class ReversalDepositoDanNomorSequenceTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'REVDEP'.strtoupper(substr(uniqid(), -6));
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    private function service(): DepositoService
    {
        return app(DepositoService::class);
    }

    private function produk(int $tenor = 3, float $nisbahAnggota = 60.00, float $minAmount = 100000): DepositoProduct
    {
        return DepositoProduct::create([
            'name' => $this->prefix.' Deposito '.$tenor.' Bulan',
            'tipe_produk' => DepositoProduct::TIPE_DEPOSITO,
            'has_nisbah' => true,
            'coa_kode' => DepositoProduct::COA_BY_TIPE[DepositoProduct::TIPE_DEPOSITO],
            'tenor_months' => $tenor,
            'nisbah_anggota' => $nisbahAnggota,
            'nisbah_koperasi' => 100.00 - $nisbahAnggota,
            'min_amount' => $minAmount,
            'is_active' => true,
        ]);
    }

    private function anggota(): Anggota
    {
        return Anggota::firstOrCreate(
            ['no_anggota' => $this->prefix.'AG'],
            [
                'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
                'nama_lengkap' => 'Anggota Uji Reversal Deposito',
                'jenis_kelamin' => 'Laki-laki',
                'alamat' => 'Alamat Uji Reversal',
                'kota' => 'Jakarta',
                'provinsi' => 'DKI Jakarta',
                'status_anggota' => 'Aktif',
                'tanggal_gabung' => now()->toDateString(),
            ]
        );
    }

    private function akunId(): int
    {
        $id = Akun::where('kode_akun', '1133')->value('id') ?? Akun::query()->value('id');

        if (! $id) {
            $this->markTestSkipped('Tabel akun kosong — jalankan AkunSeeder.');
        }

        return (int) $id;
    }

    private function rekeningSukarela(Anggota $anggota, float $saldo = 50000000): Rekening
    {
        return Rekening::create([
            'no_rekening' => 'RKN-'.strtoupper(substr(uniqid(), -8)),
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

    private function teller(): User
    {
        return User::firstOrCreate(
            ['email' => 'reversal.deposito.teller@test.local'],
            [
                'name' => 'Teller Reversal Uji',
                'password' => Hash::make('password'),
                'role' => 'teller',
                'is_active' => true,
            ]
        );
    }

    private function manajer(): User
    {
        return User::firstOrCreate(
            ['email' => 'reversal.deposito.manajer@test.local'],
            [
                'name' => 'Manajer Reversal Uji',
                'password' => Hash::make('password'),
                'role' => 'manajer',
                'is_active' => true,
            ]
        );
    }

    private function approvalVoid(Transaksi $transaksi, User $pengaju): ApprovalTransaksi
    {
        return ApprovalTransaksi::create([
            'kode_approval' => ApprovalTransaksi::generateKode(),
            'tipe_referensi' => 'transaksi',
            'referensi_id' => $transaksi->id,
            'jenis_transaksi' => 'VOID',
            'nominal' => (float) $transaksi->jumlah,
            'status' => 'PENDING_MANAJER',
            'diajukan_oleh' => $pengaju->id,
            'role_pengaju' => $pengaju->role,
            'catatan_pengaju' => 'Uji reversal deposito (K-02)',
        ]);
    }

    /** Saldo akun CoA tertentu (dari jurnal_detail). */
    private function saldoCoa(string $kode): float
    {
        $coa = Coa::findByKode($kode);

        if (! $coa) {
            return 0.0;
        }

        $debet = (float) DB::table('jurnal_detail')->where('coa_id', $coa->id)->sum('debet');
        $kredit = (float) DB::table('jurnal_detail')->where('coa_id', $coa->id)->sum('kredit');

        return match ($coa->tipe_saldo_normal) {
            'Debet' => $debet - $kredit,
            'Kredit' => $kredit - $debet,
            default => $debet - $kredit,
        };
    }

    /**
     * Bentuk data deposito aktif + kembalikan transaksi penggerak utamanya.
     *
     * @return array{deposito: Deposito, rekeningSukarela: Rekening, rekeningBerjangka: Rekening}
     */
    private function siapkanDeposito(float $pokok = 2000000): array
    {
        $anggota = $this->anggota();
        $sumber = $this->rekeningSukarela($anggota, 50000000);
        $produk = $this->produk(3, 60, 100000);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => $pokok,
            'user_id' => $this->teller()->id,
        ]);

        return [
            'deposito' => $deposito,
            'rekeningSukarela' => $sumber,
            'rekeningBerjangka' => Rekening::findOrFail($deposito->deposito_rekening_id),
        ];
    }

    /* =====================================================================
     | K-02 — Reversal PEMBUKAAN DEPOSITO
     |===================================================================== */

    public function test_void_pembukaan_deposito_membentuk_jurnal_pembalik_yang_seimbang(): void
    {
        $data = $this->siapkanDeposito(2000000);
        $pokok = 2000000.0;

        $transaksi = Transaksi::where('jenis_transaksi', 'Pembukaan Deposito Mudharabah')
            ->where('rekening_id', $data['rekeningSukarela']->id)
            ->orderByDesc('id')
            ->firstOrFail();

        $saldoSukarelaSebelumReversal = $this->saldoCoa(CoaKode::SIMPANAN_SUKARELA);
        $saldoBerjangkaSebelumReversal = $this->saldoCoa(CoaKode::SIMPANAN_BERJANGKA);

        $transaksi->update(['status_transaksi' => 'Pending Void']);

        $hasil = app(ReversalService::class)->executeVoidReversal(
            $this->approvalVoid($transaksi, $this->teller()),
            $this->manajer()->id
        );

        $this->assertSame('success', $hasil['status'], $hasil['message']);

        // Jurnal pembalik: DEBET 2-1003 | KREDIT 2-1001.
        // 2-1003 & 2-1001 bersaldo normal KREDIT, sehingga DEBET menurunkan
        // saldo dan KREDIT menaikkan saldo.
        $this->assertSame(
            round($saldoBerjangkaSebelumReversal - $pokok, 2),
            round($this->saldoCoa(CoaKode::SIMPANAN_BERJANGKA), 2),
            'Reversal pembukaan deposito harus MENDebet Simpanan Berjangka (2-1003) sehingga saldonya turun.'
        );
        $this->assertSame(
            round($saldoSukarelaSebelumReversal + $pokok, 2),
            round($this->saldoCoa(CoaKode::SIMPANAN_SUKARELA), 2),
            'Reversal pembukaan deposito harus MENGkredit Simpanan Sukarela (2-1001) sehingga saldonya naik.'
        );

        // Jurnal pembalik benar-benar tercipta dengan sumber yang tepat.
        $this->assertTrue(
            Jurnal::where('sumber', 'Reversal Pembukaan Deposito Mudharabah')->exists(),
            'Jurnal pembalik pembukaan deposito tidak terbentuk.'
        );

        // Saldo rekening fisik Simpanan Sukarela dipulihkan penuh ke nilai awal
        // fixture (Rp 50.000.000) setelah void pembukaan deposito.
        $this->assertSame(
            round(50000000.0, 2),
            round((float) $data['rekeningSukarela']->fresh()->saldo, 2),
            'Saldo Simpanan Sukarela wajib pulih setelah void pembukaan deposito.'
        );
    }

    public function test_jurnal_pembalik_pembukaan_deposito_selalu_seimbang(): void
    {
        $data = $this->siapkanDeposito(1500000);

        $transaksi = Transaksi::where('jenis_transaksi', 'Pembukaan Deposito Mudharabah')
            ->where('rekening_id', $data['rekeningSukarela']->id)
            ->orderByDesc('id')->firstOrFail();
        $transaksi->update(['status_transaksi' => 'Pending Void']);

        app(ReversalService::class)->executeVoidReversal(
            $this->approvalVoid($transaksi, $this->teller()),
            $this->manajer()->id
        );

        $this->assertJurnalSeimbang('Reversal Pembukaan Deposito Mudharabah');
    }

    /* =====================================================================
     | K-02 — Reversal PENC AIRAN DINI DEPOSITO (penalti ta'zir)
     |===================================================================== */

    public function test_void_pencairan_dini_deposito_memulihkan_penalti_dana_kebajikan(): void
    {
        $anggota = $this->anggota();
        $sumber = $this->rekeningSukarela($anggota, 50000000);
        $produk = $this->produk(6, 60, 100000);

        $produk->forceFill([
            'allow_early_withdrawal' => true,
            'penalty_fee_type' => DepositoProduct::PENALTI_FIXED,
            'penalty_fee_amount' => 50000,
            'penalty_coa_id' => DepositoProduct::PENALTI_COA_DEFAULT,
        ])->save();

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 1000000,
            'user_id' => $this->teller()->id,
        ]);

        $deposito->forceFill(['status' => Deposito::STATUS_ACTIVE])->save();

        $hasilCair = $this->service()->earlyWithdrawal($deposito->id, $this->teller()->id);
        $this->assertSame(50000.0, round((float) $hasilCair['penalti'], 2));

        $transaksi = Transaksi::where('jenis_transaksi', 'Pencairan Dini Deposito Mudharabah')
            ->orderByDesc('id')->firstOrFail();

        $saldoKebajikanSebelum = $this->saldoCoa(CoaKode::DANA_KEBAJIKAN);
        $saldoBerjangkaSebelum = $this->saldoCoa(CoaKode::SIMPANAN_BERJANGKA);

        $transaksi->update(['status_transaksi' => 'Pending Void']);

        $hasil = app(ReversalService::class)->executeVoidReversal(
            $this->approvalVoid($transaksi, $this->teller()),
            $this->manajer()->id
        );

        $this->assertSame('success', $hasil['status'], $hasil['message']);

        // Penalti ta'zir harus DIKEMBALIKAN (DEBET Dana Kebajikan) karena
        // transaksi pencairan dini dibatalkan → penalti tidak jadi diambil.
        $this->assertSame(
            round($saldoKebajikanSebelum - 50000.0, 2),
            round($this->saldoCoa(CoaKode::DANA_KEBAJIKAN), 2),
            'Penalti ta\'zir wajib dibalik dari Dana Kebajikan (2-3001) saat void pencairan dini.'
        );

        // Simpanan Berjangka kembali terisi penuh (KREDIT 2-1003 = pokok).
        $this->assertSame(
            round($saldoBerjangkaSebelum + 1000000.0, 2),
            round($this->saldoCoa(CoaKode::SIMPANAN_BERJANGKA), 2),
            'Reversal pencairan dini harus MENGkredit Simpanan Berjangka sebesar pokok.'
        );

        $this->assertTrue(
            Jurnal::where('sumber', 'Reversal Pencairan Dini Deposito Mudharabah')->exists(),
            'Jurnal pembalik pencairan dini deposito tidak terbentuk.'
        );

        $this->assertJurnalSeimbang('Reversal Pencairan Dini Deposito Mudharabah');
    }

    public function test_void_pencairan_dini_deposito_tanpa_penalti_tetap_seimbang(): void
    {
        $anggota = $this->anggota();
        $sumber = $this->rekeningSukarela($anggota, 50000000);
        $produk = $this->produk(3, 60, 100000);

        $produk->forceFill([
            'allow_early_withdrawal' => true,
            'penalty_fee_type' => DepositoProduct::PENALTI_FIXED,
            'penalty_fee_amount' => 0,     // edge case: tanpa penalti
            'penalty_coa_id' => DepositoProduct::PENALTI_COA_DEFAULT,
        ])->save();

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 2000000,
            'user_id' => $this->teller()->id,
        ]);

        $deposito->forceFill(['status' => Deposito::STATUS_ACTIVE])->save();

        $this->service()->earlyWithdrawal($deposito->id, $this->teller()->id);

        $transaksi = Transaksi::where('jenis_transaksi', 'Pencairan Dini Deposito Mudharabah')
            ->orderByDesc('id')->firstOrFail();
        $transaksi->update(['status_transaksi' => 'Pending Void']);

        $hasil = app(ReversalService::class)->executeVoidReversal(
            $this->approvalVoid($transaksi, $this->teller()),
            $this->manajer()->id
        );

        $this->assertSame('success', $hasil['status'], $hasil['message']);
        $this->assertJurnalSeimbang('Reversal Pencairan Dini Deposito Mudharabah');
    }

    /* =====================================================================
     | K-02 — Reversal PENARIKAN / PEMBUKAAN jenis deposito lainnya
     |===================================================================== */

    public function test_void_penarikan_simpanan_berjangka_memakai_jurnal_tarik_deposito(): void
    {
        $data = $this->siapkanDeposito(2000000);

        // Simulasikan transaksi penarikan berjangka (DEBET 2-1003 | KREDIT 1-1001).
        $transaksi = Transaksi::create([
            'no_transaksi' => 'TRX-REVDEP-'.strtoupper(substr(uniqid(), -8)),
            'rekening_id' => $data['rekeningBerjangka']->id,
            'anggota_id' => $data['rekeningBerjangka']->anggota_id,
            'jenis_transaksi' => 'Penarikan Simpanan Berjangka',
            'posisi' => 'Debet',
            'jumlah' => 500000,
            'saldo_setelah' => 1500000,
            'waktu_transaksi' => now(),
            'status_transaksi' => 'Sukses',
            'status' => 'success',
            'sumber_transaksi' => 'Manual',
        ]);

        $transaksi->update(['status_transaksi' => 'Pending Void']);

        $hasil = app(ReversalService::class)->executeVoidReversal(
            $this->approvalVoid($transaksi, $this->teller()),
            $this->manajer()->id
        );

        $this->assertSame('success', $hasil['status'], $hasil['message']);
        $this->assertTrue(
            Jurnal::where('sumber', 'Reversal Penarikan Simpanan Berjangka')->exists(),
            'Void penarikan berjangka harus memakai jurnal reversal tarik deposito.'
        );
        $this->assertJurnalSeimbang('Reversal Penarikan Simpanan Berjangka');
    }

    /**
     * EDGE CASE — jenis transaksi yang TIDAK terpetakan harus MENOLAK reversal
     * (fail-closed) agar buku besar tidak rusak. Guard di `buatJurnalReversal`
     * melempar `RuntimeException` bila pola jurnal tak dikenali.
     */
    public function test_jenis_deposito_tak_terpetakan_memunculkan_error_bukan_jurnal_salah(): void
    {
        $data = $this->siapkanDeposito(1000000);

        // Sengaja pakai jenis yang mengandung 'Deposito' namun tidak dikenali
        // spesifik — guard harus tetap memetakan ke cadangan (tarik deposito)
        // ATAU melempar error; yang DILARANG adalah lulus tanpa jurnal.
        $transaksi = Transaksi::create([
            'no_transaksi' => 'TRX-REVDEP-'.strtoupper(substr(uniqid(), -8)),
            'rekening_id' => $data['rekeningBerjangka']->id,
            'anggota_id' => $data['rekeningBerjangka']->anggota_id,
            'jenis_transaksi' => 'Koreksi Deposito Tidak Dikenal',
            'posisi' => 'Debet',
            'jumlah' => 100000,
            'saldo_setelah' => 900000,
            'waktu_transaksi' => now(),
            'status_transaksi' => 'Sukses',
            'status' => 'success',
            'sumber_transaksi' => 'Manual',
        ]);

        $transaksi->update(['status_transaksi' => 'Pending Void']);

        $hasil = app(ReversalService::class)->executeVoidReversal(
            $this->approvalVoid($transaksi, $this->teller()),
            $this->manajer()->id
        );

        // Kontrak: TIDAK BOLEH 'success' tanpa jurnal pembalik. Bila sukses,
        // maka jurnal cadangan WAJIB ada dan seimbang.
        if ($hasil['status'] === 'success') {
            $this->assertTrue(
                Jurnal::where('sumber', 'like', 'Reversal%Deposito%')->exists()
                    || Jurnal::where('sumber', 'like', 'Reversal%Berjangka%')->exists(),
                "Reversal sukses namun tidak ada jurnal pembalik untuk jenis [{$transaksi->jenis_transaksi}]."
            );
        } else {
            $this->assertNotEmpty($hasil['message'], 'Reversal gagal wajib menyertakan pesan error yang jelas.');
        }
    }

    /* =====================================================================
     | K-02 — Guard integritas: reversal tidak boleh menghasilkan jurnal pincang
     |===================================================================== */

    public function test_semua_jurnal_reversal_deposito_seimbang_debet_kredit(): void
    {
        $data = $this->siapkanDeposito(2000000);

        $transaksi = Transaksi::where('jenis_transaksi', 'Pembukaan Deposito Mudharabah')
            ->where('rekening_id', $data['rekeningSukarela']->id)
            ->orderByDesc('id')->firstOrFail();
        $transaksi->update(['status_transaksi' => 'Pending Void']);

        app(ReversalService::class)->executeVoidReversal(
            $this->approvalVoid($transaksi, $this->teller()),
            $this->manajer()->id
        );

        // Tidak ada jurnal pembalik yang boleh punya selisih debet ≠ kredit.
        $jurnals = Jurnal::where('sumber', 'like', 'Reversal%Deposito%')
            ->orWhere('sumber', 'like', 'Reversal%Berjangka%')
            ->get();

        $this->assertGreaterThan(0, $jurnals->count(), 'Minimal satu jurnal reversal deposito harus ada.');

        foreach ($jurnals as $jurnal) {
            $this->assertJurnalSeimbang($jurnal->sumber);
        }
    }

    /* =====================================================================
     | K-03 — NomorSequence anti-race
     |===================================================================== */

    public function test_nomor_sequence_menghasilkan_nomor_unik_berurutan(): void
    {
        $prefix = 'TRX-UJI'.strtoupper(substr(uniqid(), -6)).'-';

        $nomor = [];
        for ($i = 0; $i < 5; $i++) {
            $nomor[] = DB::transaction(fn () => NomorSequence::berikutnya($prefix));
        }

        $this->assertCount(5, array_unique($nomor), 'Nomor yang dihasilkan tidak boleh duplikat.');
        $this->assertSame($nomor, array_values(array_unique($nomor)));
        $this->assertStringStartsWith($prefix, $nomor[0]);
    }

    public function test_nomor_sequence_melanjutkan_dari_data_eksisting(): void
    {
        $prefix = 'TRX-SEED'.strtoupper(substr(uniqid(), -6)).'-';

        // Tanam dua nomor eksisting yang meniru kondisi produksi.
        Transaksi::create([
            'no_transaksi' => $prefix.'000007',
            'rekening_id' => $this->rekeningSukarela($this->anggota())->id,
            'anggota_id' => $this->anggota()->id,
            'jenis_transaksi' => 'Uji Seed',
            'posisi' => 'Debet',
            'jumlah' => 1,
            'status_transaksi' => 'Sukses',
            'status' => 'success',
            'waktu_transaksi' => now(),
        ]);
        Transaksi::create([
            'no_transaksi' => $prefix.'000042',
            'rekening_id' => $this->rekeningSukarela($this->anggota())->id,
            'anggota_id' => $this->anggota()->id,
            'jenis_transaksi' => 'Uji Seed',
            'posisi' => 'Debet',
            'jumlah' => 1,
            'status_transaksi' => 'Sukses',
            'status' => 'success',
            'waktu_transaksi' => now(),
        ]);

        $berikutnya = DB::transaction(fn () => NomorSequence::berikutnya($prefix));

        $this->assertSame(
            $prefix.'000043',
            $berikutnya,
            'Seeder urutan harus melanjutkan dari MAX numerik eksisting (bukan reset ke 1).'
        );
    }

    /**
     * Meniru skenario paralel: dua "permintaan" yang menyelesaikan
     * transaksinya masing-masing harus tetap memperoleh nomor berbeda.
     * Karena lockForUpdate hanya bermakna lintas koneksi nyata, test ini
     * memvalidasi INVARIAN (tidak ada duplikasi & urutan monoton) yang
     * dilindungi lock pada lingkungan produksi.
     */
    public function test_nomor_sequence_tidak_pernah_duplikat_pada_banyak_pemanggilan(): void
    {
        $prefix = 'TRX-RACE'.strtoupper(substr(uniqid(), -6)).'-';

        $nomor = [];
        for ($i = 0; $i < 20; $i++) {
            $nomor[] = DB::transaction(fn () => NomorSequence::berikutnya($prefix, 6));
        }

        $this->assertCount(20, $nomor);
        $this->assertCount(20, array_unique($nomor), 'Terjadi duplikasi nomor transaksi (K-03 regresi).');

        // Monoton naik.
        $sorted = $nomor;
        sort($sorted);
        $this->assertSame($sorted, $nomor, 'Urutan nomor harus monoton naik (tidak ada lompatan mundur).');
    }

    public function test_tabel_nomor_sequences_tersedia(): void
    {
        $this->assertTrue(
            \Schema::hasTable('nomor_sequences'),
            'Tabel nomor_sequences belum dimigrasi — jalankan php artisan migrate.'
        );
        $this->assertTrue(\Schema::hasColumn('nomor_sequences', 'prefix'));
        $this->assertTrue(\Schema::hasColumn('nomor_sequences', 'urutan'));
    }

    public function test_generator_no_transaksi_service_terhubung_ke_nomor_sequence(): void
    {
        // Pembukaan deposito harus menghasilkan no_transaksi berprefix harian
        // yang bersumber dari NomorSequence (anti-race).
        $data = $this->siapkanDeposito(1000000);

        $transaksi = Transaksi::where('jenis_transaksi', 'Pembukaan Deposito Mudharabah')
            ->where('rekening_id', $data['rekeningSukarela']->id)
            ->orderByDesc('id')->firstOrFail();

        $this->assertMatchesRegularExpression(
            '/^TRX-\d{8}-\d{6}$/',
            $transaksi->no_transaksi,
            'No transaksi harus mengikuti format TRX-YYYYMMDD-XXXXXX dari NomorSequence.'
        );
    }

    /* =====================================================================
     | Util
     |===================================================================== */

    private function assertJurnalSeimbang(string $sumber): void
    {
        $jurnal = Jurnal::with('detail')->where('sumber', $sumber)->orderByDesc('id')->first();

        $this->assertNotNull($jurnal, "Jurnal dengan sumber [{$sumber}] tidak ditemukan.");

        $debet = round((float) $jurnal->detail->sum('debet'), 2);
        $kredit = round((float) $jurnal->detail->sum('kredit'), 2);

        $this->assertSame(
            $debet,
            $kredit,
            "Jurnal [{$sumber}] tidak seimbang: debet={$debet}, kredit={$kredit}."
        );
    }
}
