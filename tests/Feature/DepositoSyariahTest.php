<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\Coa;
use App\Models\Deposito;
use App\Models\DepositoProduct;
use App\Models\DepositoProfitSharing;
use App\Models\JenisSimpanan;
use App\Models\Jurnal;
use App\Models\Rekening;
use App\Models\User;
use App\Services\AutoJournalingService;
use App\Services\DepositoService;
use App\Support\Setting;
use Barryvdh\DomPDF\PDF;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Modul Deposito / Simpanan Berjangka Syariah (akad Mudharabah Muthlaqah).
 *
 * Aturan bisnis & akuntansi PSAK 101/108 yang dikunci oleh test ini:
 *  1. Sumber dana pembukaan deposito adalah REKENING SIMPANAN SUKARELA
 *     (CoA 2-1001) — Kas Teller tidak boleh dipakai.
 *  2. `maturity_date` selalu dihitung sistem dari start_date + tenor produk
 *     (Carbon), tidak pernah diterima dari input pengguna.
 *  3. Skema bagi hasil memakai NISBAH, bukan bunga tetap, dan tidak
 *     mengaitkan komponen SHU.
 *  4. Jurnal otomatis:
 *       Pembukaan  DEBET 2-1001 | KREDIT 2-1003
 *       Pencairan  DEBET 2-1003 | KREDIT 2-1001
 *       Bagi hasil DEBET 5-3001 | KREDIT 2-1001
 *  5. Nisbah produk disalin ke bilyet deposito sebagai audit trail.
 *
 * Catatan: basis data dev memuat data nyata, sehingga pengujian memakai
 * SELISIH (delta) dan data uji berprefix unik agar deterministik.
 */
class DepositoSyariahTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'DEPSYR'.strtoupper(substr(uniqid(), -6));
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    private function service(): DepositoService
    {
        return app(DepositoService::class);
    }

    private function produk(int $tenor = 3, float $nisbahAnggota = 60.00, float $minAmount = 100000, bool $aktif = true): DepositoProduct
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
            'is_active' => $aktif,
        ]);
    }

    /** Produk simpanan (PSAK 101) — pokok/wajib/sukarela, tanpa tenor. */
    private function produkSimpanan(string $tipe, float $nisbahAnggota = 0.0): DepositoProduct
    {
        return DepositoProduct::updateOrCreate(
            ['name' => $this->prefix.' Simpanan '.ucfirst($tipe)],
            [
                'tipe_produk' => $tipe,
                'has_nisbah' => $tipe === DepositoProduct::TIPE_SUKARELA,
                'coa_kode' => DepositoProduct::COA_BY_TIPE[$tipe],
                'tenor_months' => null,
                'nisbah_anggota' => $nisbahAnggota,
                'nisbah_koperasi' => $nisbahAnggota > 0 ? 100.00 - $nisbahAnggota : 0.0,
                'min_amount' => null,
                'is_active' => true,
            ]
        );
    }

    private function anggota(): Anggota
    {
        return Anggota::firstOrCreate(
            ['no_anggota' => $this->prefix.'AG'],
            [
                'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
                'nama_lengkap' => 'Anggota Uji Deposito',
                'jenis_kelamin' => 'Laki-laki',
                'alamat' => 'Alamat Uji Deposito',
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

    /** Rekening Simpanan Sukarela anggota dengan saldo tertentu. */
    private function rekeningSukarela(Anggota $anggota, float $saldo = 10000000, string $jenis = 'Simpanan Sukarela'): Rekening
    {
        return Rekening::create([
            'no_rekening' => 'RKN-'.strtoupper(substr(uniqid(), -8)),
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunId(),
            'jenis_rekening' => $jenis,
            'akad' => $jenis === 'Simpanan Sukarela' ? 'Wadiah' : 'Mudharabah',
            'saldo' => $saldo,
            'batas_minimum' => 0,
            'tanggal_buka' => now()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);
    }

    private function pengguna(): User
    {
        $pengguna = User::firstOrCreate(
            ['email' => 'deposito.syariah@test.local'],
            [
                'name' => 'Petugas Deposito Uji',
                'password' => Hash::make('password'),
                'role' => 'teller',
                'is_active' => true,
            ]
        );

        // K-2: `/api/deposito/store` adalah transaksi loket yang dijaga
        // `kas.terbuka`. Sesi kas dibuka di sini (idempoten) supaya seluruh
        // test API pembukaan deposito berfokus pada perilaku validasi &
        // mutasi saldo, bukan tersandung guard laci.
        $this->bukaSesiKas($pengguna);

        return $pengguna;
    }

    /** Pengguna berperan AO — pemilik route form "Buka Rekening Simpanan". */
    private function penggunaAo(): User
    {
        return User::firstOrCreate(
            ['email' => 'deposito.ao@test.local'],
            [
                'name' => 'AO Deposito Uji',
                'password' => Hash::make('password'),
                'role' => 'ao',
                'is_active' => true,
            ]
        );
    }

    /**
     * Pengguna berperan MANAJER untuk jalur WEB loket (K-2).
     *
     * Grup web `teller.*` dijaga `izin:teller.loket`, jadi manajer boleh
     * mengeksekusi pembukaan deposito lewat form web (penyelia loket).
     * Hal ini SENGAJA berbeda dari API `/api/deposito/store` yang kini
     * dijaga `izin:kas.loket.eksekusi` (teller + super_admin saja).
     */
    private function penggunaManajer(): User
    {
        return User::firstOrCreate(
            ['email' => 'deposito.manajer@test.local'],
            [
                'name' => 'Manajer Deposito Uji',
                'password' => Hash::make('password'),
                'role' => 'manajer',
                'is_active' => true,
            ]
        );
    }

    /** Saldo akun CoA tertentu (dari jurnal_detail) — untuk uji mutasi akuntansi. */
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

    /* =====================================================================
     | 1. ATURAN BISNIS: MIGRASI, MODEL, SCOPE
     |===================================================================== */

    public function test_tabel_deposito_dan_relasinya_tersedia(): void
    {
        $this->assertTrue(\Schema::hasTable('deposito_products'), 'Tabel deposito_products belum ada.');
        $this->assertTrue(\Schema::hasTable('depositos'), 'Tabel depositos belum ada.');
        $this->assertTrue(\Schema::hasTable('deposito_profit_sharings'), 'Tabel deposito_profit_sharings belum ada.');

        foreach (['deposito_number', 'member_id', 'product_id', 'source_saving_account_id',
            'principal_amount', 'nisbah_anggota', 'nisbah_koperasi', 'start_date',
            'maturity_date', 'is_aro', 'status'] as $kolom) {
            $this->assertTrue(\Schema::hasColumn('depositos', $kolom), "Kolom depositos.{$kolom} tidak ditemukan.");
        }
    }

    public function test_akun_coa_deposito_wajib_tersedia_dan_aktif(): void
    {
        foreach ([
            Deposito::COA_SIMPANAN_SUKARELA,
            Deposito::COA_SIMPANAN_BERJANGKA,
            Deposito::COA_BEBAN_BAGI_HASIL,
        ] as $kode) {
            $coa = Coa::findByKode($kode);
            $this->assertNotNull($coa, "Akun CoA {$kode} tidak ditemukan / tidak aktif.");
        }
    }

    public function test_scope_active_hanya_mengembalikan_produk_aktif(): void
    {
        $aktif = $this->produk(3, 60, 100000, true);
        $nonAktif = $this->produk(6, 55, 100000, false);

        $ids = DepositoProduct::active()->pluck('id')->all();

        $this->assertContains($aktif->id, $ids);
        $this->assertNotContains($nonAktif->id, $ids);
    }

    public function test_tenor_produk_yang_diizinkan(): void
    {
        $this->assertSame([1, 3, 6, 12], DepositoProduct::TENOR_DIIZINKAN);
    }

    public function test_nisbah_valid_harus_berjumlah_seratus_persen(): void
    {
        $this->assertTrue($this->produk(3, 60)->nisbahValid());
        $this->assertTrue($this->produk(3, 70)->nisbahValid());
    }

    public function test_relasi_deposito_ke_member_produk_dan_rekening(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 1000000,
            'user_id' => $this->pengguna()->id,
        ]);

        $this->assertSame($anggota->id, $deposito->member->id);
        $this->assertSame($produk->id, $deposito->product->id);
        $this->assertSame($sumber->id, $deposito->sourceSavingAccount->id);
        $this->assertNotNull($deposito->depositoRekening);
        $this->assertSame('Simpanan Berjangka', $deposito->depositoRekening->jenis_rekening);
    }

    /* =====================================================================
     | 2. NOMOR BILYET & JATUH TEMPO PRESISI (Carbon)
     |===================================================================== */

    public function test_nomor_deposito_berformat_dep_yyyymmdd_xxxx_dan_unik(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 50000000);

        $a = $this->service()->createDeposito([
            'member_id' => $anggota->id, 'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id, 'principal_amount' => 1000000,
        ]);
        $b = $this->service()->createDeposito([
            'member_id' => $anggota->id, 'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id, 'principal_amount' => 1000000,
        ]);

        $this->assertMatchesRegularExpression('/^DEP-\d{8}-\d{4}$/', $a->deposito_number);
        $this->assertMatchesRegularExpression('/^DEP-\d{8}-\d{4}$/', $b->deposito_number);
        $this->assertNotSame($a->deposito_number, $b->deposito_number, 'Nomor bilyet harus unik.');
    }

    public function test_maturity_date_dihitung_carbon_start_plus_tenor(): void
    {
        $anggota = $this->anggota();
        $sumber = $this->rekeningSukarela($anggota);
        $start = '2026-01-31';

        foreach ([1, 3, 6, 12] as $tenor) {
            $produk = $this->produk($tenor, 60, 100000);
            $deposito = $this->service()->createDeposito([
                'member_id' => $anggota->id,
                'product_id' => $produk->id,
                'source_saving_account_id' => $sumber->id,
                'principal_amount' => 1000000,
                'start_date' => $start,
            ]);

            // Tanpa overflow: 31 Jan + 1 bulan = 28 Feb, bukan 3 Mar.
            $harapan = Carbon::parse($start)->addMonthsNoOverflow($tenor)->toDateString();

            $this->assertSame(
                $harapan,
                $deposito->maturity_date->toDateString(),
                "Tenor {$tenor} bulan: maturity_date harus start_date + {$tenor} bulan."
            );
            $this->assertSame($tenor, $deposito->tenorMonths());
        }
    }

    public function test_maturity_date_mengikuti_akhir_bulan_dengan_benar(): void
    {
        $anggota = $this->anggota();
        $sumber = $this->rekeningSukarela($anggota);
        $produk = $this->produk(1, 60, 100000);

        // 31 Januari + 1 bulan = 28 Februari (Carbon menyesuaikan akhir bulan).
        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 1000000,
            'start_date' => '2026-01-31',
        ]);

        $this->assertSame('2026-02-28', $deposito->maturity_date->toDateString());
    }

    public function test_input_manual_maturity_date_diabaikan_sistem(): void
    {
        $anggota = $this->anggota();
        $sumber = $this->rekeningSukarela($anggota);
        $produk = $this->produk(6, 60, 100000);

        // Data berisi maturity_date palsu — service tidak boleh memakainya.
        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 1000000,
            'start_date' => '2026-03-01',
            'maturity_date' => '2099-12-31',
        ]);

        $this->assertSame('2026-09-01', $deposito->maturity_date->toDateString());
    }

    /* =====================================================================
     | 3. SUMBER DANA: POTONG SIMPANAN SUKARELA (BUKAN KAS TELLER)
     |===================================================================== */

    public function test_pembukaan_memotong_saldo_rekening_simpanan_sukarela(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 5000000);

        $saldoAwal = (float) $sumber->fresh()->saldo;

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 2000000,
        ]);

        $this->assertSame(
            $saldoAwal - 2000000,
            (float) $sumber->fresh()->saldo,
            'Saldo Simpanan Sukarela harus berkurang sebesar pokok deposito.'
        );
        $this->assertSame('ACTIVE', $deposito->status);
    }

    public function test_saldo_kurang_ditolak_dan_tidak_mengubah_apa_pun(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 500000);

        $saldoAwal = (float) $sumber->fresh()->saldo;
        $jumlahDeposito = Deposito::milik($anggota->id)->count();

        try {
            $this->service()->createDeposito([
                'member_id' => $anggota->id,
                'product_id' => $produk->id,
                'source_saving_account_id' => $sumber->id,
                'principal_amount' => 1000000,
            ]);
            $this->fail('Pembukaan deposito seharusnya ditolak karena saldo tidak mencukupi.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('principal_amount', $e->errors());
            $this->assertStringContainsStringIgnoringCase('saldo', implode(' ', $e->errors()['principal_amount']));
        }

        $this->assertSame($saldoAwal, (float) $sumber->fresh()->saldo, 'Saldo tidak boleh berubah.');
        $this->assertSame($jumlahDeposito, Deposito::milik($anggota->id)->count(), 'Tidak boleh ada deposito baru.');
    }

    public function test_nominal_di_bawah_min_amount_ditolak(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 5000000);
        $sumber = $this->rekeningSukarela($anggota, 50000000);

        $this->expectException(ValidationException::class);

        $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 1000000, // < min_amount
        ]);
    }

    public function test_produk_non_aktif_ditolak(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000, false);
        $sumber = $this->rekeningSukarela($anggota);

        $this->expectException(ValidationException::class);

        $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 1000000,
        ]);
    }

    public function test_rekening_sumber_bukan_simpanan_sukarela_ditolak(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $wajib = $this->rekeningSukarela($anggota, 50000000, 'Simpanan Wajib');

        $this->expectException(ValidationException::class);

        $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $wajib->id,
            'principal_amount' => 1000000,
        ]);
    }

    public function test_rekening_sumber_milik_anggota_lain_ditolak(): void
    {
        $anggotaA = $this->anggota();
        $anggotaB = Anggota::create([
            'no_anggota' => $this->prefix.'AG2',
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Uji Deposito 2',
            'jenis_kelamin' => 'Perempuan',
            'alamat' => 'Alamat Uji 2',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => 'Aktif',
            'tanggal_gabung' => now()->toDateString(),
        ]);

        $produk = $this->produk(3, 60, 100000);
        $rekeningB = $this->rekeningSukarela($anggotaB, 50000000);

        $this->expectException(ValidationException::class);

        $this->service()->createDeposito([
            'member_id' => $anggotaA->id,   // A memakai rekening B
            'product_id' => $produk->id,
            'source_saving_account_id' => $rekeningB->id,
            'principal_amount' => 1000000,
        ]);
    }

    /* =====================================================================
     | 4. JURNAL OTOMATIS (PSAK 101/108)
     |===================================================================== */

    public function test_jurnal_pembukaan_debet_simpanan_sukarela_kredit_simpanan_berjangka(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 50000000);

        $sukarelaSebelum = $this->saldoCoa(Deposito::COA_SIMPANAN_SUKARELA);
        $berjangkaSebelum = $this->saldoCoa(Deposito::COA_SIMPANAN_BERJANGKA);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 3000000,
        ]);

        $deltaSukarela = $this->saldoCoa(Deposito::COA_SIMPANAN_SUKARELA) - $sukarelaSebelum;
        $deltaBerjangka = $this->saldoCoa(Deposito::COA_SIMPANAN_BERJANGKA) - $berjangkaSebelum;

        // 2-1001 kewajiban (saldo normal Kredit): debet mengurangi saldo.
        $this->assertSame(-3000000.0, round($deltaSukarela, 2), 'DEBET 2-1001 harus mengurangi Simpanan Sukarela.');
        $this->assertSame(3000000.0, round($deltaBerjangka, 2), 'KREDIT 2-1003 harus menambah Simpanan Berjangka.');

        // Keterangan header jurnal memakai format baku permintaan:
        // "Pembukaan Deposito a.n {nama_anggota}" (tanpa nomor bilyet).
        // Jurnal dikenali dari rekening Simpanan Berjangka pada detailnya.
        $jurnal = Jurnal::where('sumber', 'Pembukaan Deposito Mudharabah')
            ->where('keterangan', 'like', 'Pembukaan Deposito a.n '.$anggota->nama_lengkap.'%')
            ->whereHas('detail', fn ($q) => $q->where('rekening_id', $deposito->deposito_rekening_id))
            ->first();

        $this->assertNotNull($jurnal, 'Jurnal pembukaan deposito tidak terbentuk.');
        $this->assertTrue((bool) $jurnal->is_balance);
        $this->assertSame('Posted', $jurnal->status);
    }

    public function test_jurnal_pembukaan_tidak_memakai_kas_teller(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 50000000);

        $kasSebelum = $this->saldoCoa(AutoJournalingService::KAS_TELLER);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 2500000,
        ]);

        $this->assertSame(
            round($kasSebelum, 2),
            round($this->saldoCoa(AutoJournalingService::KAS_TELLER), 2),
            'Kas Teller TIDAK boleh tersentuh pada pembukaan deposito (PSAK: sumber dana = Simpanan Sukarela).'
        );

        $jurnal = Jurnal::where('sumber', 'Pembukaan Deposito Mudharabah')
            ->where('keterangan', 'like', 'Pembukaan Deposito a.n '.$anggota->nama_lengkap.'%')
            ->whereHas('detail', fn ($q) => $q->where('rekening_id', $deposito->deposito_rekening_id))
            ->first();

        $kodeDipakai = \DB::table('jurnal_detail')
            ->join('coas', 'coas.id', '=', 'jurnal_detail.coa_id')
            ->where('jurnal_detail.jurnal_id', $jurnal->id)
            ->pluck('coas.kode_akun')
            ->all();

        $this->assertNotContains(
            AutoJournalingService::KAS_TELLER,
            $kodeDipakai,
            'Jurnal pembukaan deposito tidak boleh melibatkan akun Kas Teller 1-1001.'
        );
    }

    public function test_nisbah_produk_disalin_ke_bilyet_sebagai_audit_trail(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(6, 62.50, 100000);
        $sumber = $this->rekeningSukarela($anggota);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 1000000,
        ]);

        $this->assertSame(62.50, (float) $deposito->nisbah_anggota);
        $this->assertSame(37.50, (float) $deposito->nisbah_koperasi);

        // Nisbah bilyet tidak boleh berubah walau produk diubah setelahnya.
        $produk->update(['nisbah_anggota' => 40, 'nisbah_koperasi' => 60]);

        $this->assertSame(62.50, (float) $deposito->fresh()->nisbah_anggota, 'Nisbah bilyet harus tetap (audit trail).');
    }

    /**
     * Jurnal pembukaan deposito harus terlihat pada BUKU BESAR dan NERACA
     * melalui agregasi `jurnal_detail` yang sama dipakai Coa::totalDebet()/
     * totalKredit() — bukan tabel saldo terpisah. Status `Posted` wajib,
     * karena laporan keuangan (mis. Neraca) hanya menghitung jurnal Posted.
     */
    public function test_jurnal_pembukaan_terposting_ke_buku_besar_dan_neraca(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(6, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 50000000);

        $sukarelaCoa = Coa::findByKode(Deposito::COA_SIMPANAN_SUKARELA);
        $berjangkaCoa = Coa::findByKode(Deposito::COA_SIMPANAN_BERJANGKA);

        $sukarelaSebelum = $sukarelaCoa->saldoBerjalan();
        $berjangkaSebelum = $berjangkaCoa->saldoBerjalan();

        $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 5000000,
        ]);

        // Buku Besar: saldo akun = selisih mutasi Posted, sesuai kepala akun.
        $sukarelaCoa->refresh();
        $berjangkaCoa->refresh();

        $this->assertSame(
            round($sukarelaSebelum - 5000000, 2),
            round($sukarelaCoa->saldoBerjalan(), 2),
            'Buku Besar 2-1001 harus turun Rp 5.000.000 (Kewajiban, saldo normal Kredit).'
        );
        $this->assertSame(
            round($berjangkaSebelum + 5000000, 2),
            round($berjangkaCoa->saldoBerjalan(), 2),
            'Buku Besar 2-1003 harus naik Rp 5.000.000.'
        );

        // Neraca: sisi Kewajiban terpengaruh saldo 2-1003, dan total pergeseran
        // antar-akun Kewajiban harus nol (reklasifikasi, bukan penambahan aset).
        $this->assertSame('Kredit', $sukarelaCoa->posisiSaldo());
        $this->assertSame('Kredit', $berjangkaCoa->posisiSaldo());

        $this->assertSame(
            0.0,
            round(($berjangkaCoa->saldoBerjalan() - $berjangkaSebelum) + ($sukarelaCoa->saldoBerjalan() - $sukarelaSebelum), 2),
            'Neraca: pergeseran antar-akun Kewajiban harus nol (jurnal balance).'
        );

        // Jurnal header wajib berstatus Posted agar ikut terhitung laporan.
        $jurnal = Jurnal::where('sumber', 'Pembukaan Deposito Mudharabah')
            ->where('status', 'Posted')
            ->latest('id')
            ->first();

        $this->assertNotNull($jurnal, 'Jurnal pembukaan deposito berstatus Posted tidak ditemukan.');
        $this->assertNotNull($jurnal->posted_at, 'Jurnal Posted wajib punya jejak waktu posting.');
        $this->assertSame(
            round((float) $jurnal->total_debet, 2),
            round((float) $jurnal->total_kredit, 2),
            'Total debet dan kredit jurnal pembukaan harus sama.'
        );
        $this->assertSame(
            'Pembukaan Deposito a.n Anggota Uji Deposito',
            $this->keteranganTanpaKode($jurnal->keterangan),
            'Keterangan jurnal memakai format "Pembukaan Deposito a.n {nama_anggota}".'
        );
    }

    /**
     * Atomisitas: bila SALAH SATU langkah gagal, SELURUH transaksi harus
     * di-rollback — saldo Simpanan Sukarela utuh, tidak ada Bilyet Deposito,
     * dan tidak ada jurnal menggantung.
     */
    public function test_pembukaan_deposito_rollback_penuh_saat_langkah_jurnal_gagal(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 9000000);

        $saldoSumberSebelum = (float) $sumber->fresh()->saldo;
        $jumlahDeposito = Deposito::count();
        $jumlahJurnal = Jurnal::count();
        $sukarelaSebelum = Coa::findByKode(Deposito::COA_SIMPANAN_SUKARELA)->saldoBerjalan();

        // Paksa langkah terakhir (jurnal) gagal, SETELAH saldo terpotong &
        // bilyet terbuat — kondisi paling rawan menghasilkan data menggantung.
        Jurnal::creating(function () {
            throw new \RuntimeException('Simulasi kegagalan penulisan jurnal.');
        });

        try {
            $this->service()->createDeposito([
                'member_id' => $anggota->id,
                'product_id' => $produk->id,
                'source_saving_account_id' => $sumber->id,
                'principal_amount' => 4000000,
            ]);

            $this->fail('Pembukaan deposito seharusnya gagal dan melempar exception.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulasi kegagalan penulisan jurnal.', $e->getMessage());
        } finally {
            Jurnal::flushEventListeners();
        }

        // Rollback: saldo Simpanan Sukarela tidak boleh berkurang.
        $this->assertSame(
            round($saldoSumberSebelum, 2),
            round((float) $sumber->fresh()->saldo, 2),
            'Saldo Simpanan Sukarela harus utuh setelah rollback.'
        );

        // Rollback: tidak ada Bilyet Deposito / rekening berjangka yang tertinggal.
        $this->assertSame($jumlahDeposito, Deposito::count(), 'Tidak boleh ada Bilyet Deposito baru setelah rollback.');
        $this->assertSame(
            0,
            Rekening::where('anggota_id', $anggota->id)
                ->where('jenis_rekening', 'Simpanan Berjangka')
                ->where('keterangan', 'like', '%'.$produk->name.'%')
                ->count(),
            'Rekening Simpanan Berjangka tidak boleh tertinggal setelah rollback.'
        );

        // Rollback: tidak ada jurnal menggantung & Buku Besar tidak berubah.
        $this->assertSame($jumlahJurnal, Jurnal::count(), 'Tidak boleh ada jurnal menggantung setelah rollback.');
        $this->assertSame(
            round($sukarelaSebelum, 2),
            round(Coa::findByKode(Deposito::COA_SIMPANAN_SUKARELA)->saldoBerjalan(), 2),
            'Buku Besar 2-1001 tidak boleh berubah setelah rollback.'
        );
    }

    /** Keterangan jurnal tanpa kode bilyet/nomor rekening — untuk uji format. */
    private function keteranganTanpaKode(?string $keterangan): string
    {
        return trim(preg_replace('/\s+(DP[-\w]+|DEP[-\w]+|\d{3}-\d{4}-\d{6}).*$/', '', (string) $keterangan));
    }

    /* =====================================================================
     | 5. SIMULASI BAGI HASIL
     |===================================================================== */

    public function test_simulasi_membagi_pendapatan_sesuai_nisbah_bukan_bunga_tetap(): void
    {
        $produk = $this->produk(12, 65.00, 100000);

        $hasil = $this->service()->calculateSimulation(12000000, $produk->id, 0.08, '2026-01-01');

        $this->assertSame(960000.0, (float) $hasil['estimated_gross_revenue'], '12.000.000 × 8% × (12/12).');
        $this->assertSame(624000.0, (float) $hasil['estimated_member_profit'], 'Untuk anggota: 65% dari 960.000.');
        $this->assertSame(336000.0, (float) $hasil['estimated_koperasi_profit'], 'Untuk koperasi: 35% dari 960.000.');
        $this->assertSame('2027-01-01', $hasil['maturity_date']);
        $this->assertStringContainsString('Mudharabah Muthlaqah', $hasil['skema']);
    }

    public function test_simulasi_tenor_pendek_proporsional(): void
    {
        $produk = $this->produk(3, 60.00, 100000);

        $hasil = $this->service()->calculateSimulation(12000000, $produk->id, 0.08, '2026-01-01');

        // 1 Jan → 1 Apr 2026 = 90 hari: 12.000.000 × 8% × (90/365) = 236.712,33
        // → anggota 60% = 142.027,40
        $this->assertEqualsWithDelta(236712.33, (float) $hasil['estimated_gross_revenue'], 0.01);
        $this->assertEqualsWithDelta(142027.40, (float) $hasil['estimated_member_profit'], 0.01);
        $this->assertEqualsWithDelta(94684.93, (float) $hasil['estimated_koperasi_profit'], 0.01);
        $this->assertEqualsWithDelta(47342.47, (float) $hasil['estimated_monthly_profit'], 0.01, '142.027,40 / 3 bulan.');
        $this->assertEqualsWithDelta(12142027.40, (float) $hasil['total_diterima_saat_jatuh_tempo'], 0.01);
    }

    public function test_simulasi_produk_non_aktif_ditolak(): void
    {
        $produk = $this->produk(3, 60, 100000, false);

        $this->expectException(ValidationException::class);

        $this->service()->calculateSimulation(1000000, $produk->id);
    }

    public function test_simulasi_di_bawah_min_amount_ditolak(): void
    {
        $produk = $this->produk(3, 60, 5000000);

        $this->expectException(ValidationException::class);

        $this->service()->calculateSimulation(1000000, $produk->id);
    }

    public function test_simulasi_tidak_mengandung_komponen_shu(): void
    {
        $produk = $this->produk(3, 60, 100000);

        $hasil = $this->service()->calculateSimulation(12000000, $produk->id);

        $kunci = array_map('strtolower', array_keys($hasil));
        foreach ($kunci as $k) {
            $this->assertStringNotContainsString('shu', $k, 'Simulasi deposito tidak boleh mengaitkan komponen SHU.');
        }
        $this->assertStringNotContainsString('sisa hasil usaha', strtolower($hasil['catatan']));
    }

    /**
     * Regresi: simulasi harus dialokasikan per bulan kalender dengan porsi hari
     * yang benar (Carbon 3 memakai diff bertanda → dulu menghasilkan nilai negatif).
     */
    public function test_simulasi_memakai_alokasi_bulan_kalender_dan_konsisten(): void
    {
        $produk = $this->produk(3, 70.00, 100000);

        // 15 Jan 2026 → 15 Apr 2026: 90 hari dari 365 hari setahun.
        $hasil = $this->service()->calculateSimulation(12000000, $produk->id, 0.08, '2026-01-15');

        $this->assertSame('2026-04-15', $hasil['maturity_date']);

        $bulanan = $hasil['pendapatan_bulanan'];
        $this->assertNotEmpty($bulanan);
        $this->assertSame(4, count($bulanan), 'Jan (paruh), Feb, Mar, Apr (paruh).');

        $totalPendapatan = 0.0;
        $totalAnggota = 0.0;
        $totalKoperasi = 0.0;
        foreach ($bulanan as $baris) {
            $this->assertGreaterThan(0.0, (float) $baris['pendapatan'], 'Porsi bulan tidak boleh negatif/nol.');
            $this->assertSame(
                Setting::bulatkanUang((float) $baris['pendapatan'] - (float) $baris['porsi_anggota']),
                (float) $baris['porsi_koperasi'],
                'Porsi koperasi = pendapatan − porsi anggota per bulan.'
            );
            $totalPendapatan += (float) $baris['pendapatan'];
            $totalAnggota += (float) $baris['porsi_anggota'];
            $totalKoperasi += (float) $baris['porsi_koperasi'];
        }

        $this->assertSame(Setting::bulatkanUang($totalPendapatan), (float) $hasil['estimated_gross_revenue']);
        $this->assertSame(Setting::bulatkanUang($totalAnggota), (float) $hasil['estimated_member_profit']);
        $this->assertSame(Setting::bulatkanUang($totalKoperasi), (float) $hasil['estimated_koperasi_profit']);

        // 12.000.000 × 8% × (90/365) = 236.712,33
        $this->assertEqualsWithDelta(236712.33, (float) $hasil['estimated_gross_revenue'], 1.0);
        $this->assertEqualsWithDelta(165698.63, (float) $hasil['estimated_member_profit'], 1.0);
    }

    /* =====================================================================
     | 5b. JALUR TELLER: NISBAH & TENOR DARI PRODUK (BUKAN INPUT BEBAS)
     |===================================================================== */

    public function test_form_teller_memakai_pemilihan_produk_bukan_nisbah_bebas(): void
    {
        $produk = $this->produk(6, 65.00, 100000);

        $respon = $this->actingAs($this->pengguna())->get(route('teller.deposito'));

        $respon->assertOk();
        $respon->assertSee('name="deposito_product_id"', false);
        $respon->assertSee($produk->name);
        $respon->assertDontSee('name="nisbah_nasabah"', false);
        $respon->assertDontSee('name="tanggal_jatuh_tempo"', false);
    }

    /**
     * Nisbah dan jatuh tempo TIDAK boleh berasal dari input pengguna: keduanya
     * diturunkan dari produk (akad). Nilai lama yang masih terkirim diabaikan.
     */
    public function test_teller_membuka_deposito_memakai_nisbah_dan_tenor_produk(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(6, 65.00, 100000);

        // Rute `teller.deposito.store` dijaga middleware `kas.terbuka`.
        // K-2: pembukaan deposito dieksekusi pemegang laci (teller) dengan
        // sesi kas yang sudah dibuka; AO hanya menyiapkan form rekening.
        $petugas = $this->pengguna();

        $this->actingAs($petugas)
            ->post(route('teller.deposito.store'), [
                'anggota_id' => $anggota->id,
                'nominal' => 2000000,
                'deposito_product_id' => $produk->id,
                // Nilai legacy yang HARUS diabaikan:
                'nisbah_nasabah' => 99,
                'tanggal_jatuh_tempo' => now()->addYear()->toDateString(),
            ])
            ->assertRedirect();

        $rekening = Rekening::where('anggota_id', $anggota->id)
            ->where('jenis_rekening', JenisSimpanan::namaUtama(JenisSimpanan::TIPE_BERJANGKA))
            ->latest('id')
            ->first();

        $this->assertNotNull($rekening, 'Rekening deposito harus terbentuk.');
        $this->assertSame(65.0, (float) $rekening->nisbah_nasabah, 'Nisbah dari produk, bukan input.');
        $this->assertSame(35.0, (float) $rekening->nisbah_bank);
        $this->assertSame(
            today()->addMonthsNoOverflow(6)->toDateString(),
            Carbon::parse($rekening->tanggal_jatuh_tempo)->toDateString(),
            'Jatuh tempo dihitung sistem dari tenor produk.'
        );
    }

    public function test_teller_menolak_produk_deposito_non_aktif(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60.00, 100000, false);

        // Rute `teller.deposito.store` dijaga middleware `kas.terbuka`.
        $petugas = $this->pengguna();
        $this->bukaSesiKas($petugas);

        $this->actingAs($petugas)
            ->from(route('teller.deposito'))
            ->post(route('teller.deposito.store'), [
                'anggota_id' => $anggota->id,
                'nominal' => 2000000,
                'deposito_product_id' => $produk->id,
            ])
            ->assertRedirect(route('teller.deposito'))
            ->assertSessionHas('error');

        $this->assertSame(0, Rekening::where('anggota_id', $anggota->id)
            ->where('jenis_rekening', JenisSimpanan::namaUtama(JenisSimpanan::TIPE_BERJANGKA))
            ->count());
    }

    /* =====================================================================
     | 6. PENCAIRAN JATUH TEMPO
     |===================================================================== */

    public function test_settle_matured_mengembalikan_pokok_ke_simpanan_sukarela(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 10000000);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 4000000,
        ]);

        $saldoSetelahBuka = (float) $sumber->fresh()->saldo; // 10.000.000 - 4.000.000

        $hasil = $this->service()->settleMaturedDeposito($deposito);

        $this->assertSame(Deposito::STATUS_MATURED, $hasil->status);
        $this->assertSame(
            $saldoSetelahBuka + 4000000,
            (float) $sumber->fresh()->saldo,
            'Pokok deposito harus kembali ke Simpanan Sukarela.'
        );
        $this->assertSame('0.00', (string) $deposito->fresh()->depositoRekening->saldo);
        $this->assertSame('Ditutup', $deposito->fresh()->depositoRekening->status_rekening);
    }

    public function test_jurnal_pencairan_debet_berjangka_kredit_sukarela(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 10000000);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 4000000,
        ]);

        $sukarelaSebelum = $this->saldoCoa(Deposito::COA_SIMPANAN_SUKARELA);
        $berjangkaSebelum = $this->saldoCoa(Deposito::COA_SIMPANAN_BERJANGKA);

        $this->service()->settleMaturedDeposito($deposito);

        $deltaSukarela = $this->saldoCoa(Deposito::COA_SIMPANAN_SUKARELA) - $sukarelaSebelum;
        $deltaBerjangka = $this->saldoCoa(Deposito::COA_SIMPANAN_BERJANGKA) - $berjangkaSebelum;

        $this->assertSame(4000000.0, round($deltaSukarela, 2), 'KREDIT 2-1001 harus menambah Simpanan Sukarela.');
        $this->assertSame(-4000000.0, round($deltaBerjangka, 2), 'DEBET 2-1003 harus mengurangi Simpanan Berjangka.');

        $jurnal = Jurnal::where('keterangan', 'like', '%'.$deposito->deposito_number.'%')
            ->where('sumber', 'Pencairan Deposito Mudharabah')
            ->first();

        $this->assertNotNull($jurnal, 'Jurnal pencairan deposito tidak terbentuk.');
        $this->assertTrue((bool) $jurnal->is_balance);
    }

    public function test_pencairan_deposito_yang_sudah_matured_ditolak(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 10000000);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 1000000,
        ]);

        $this->service()->settleMaturedDeposito($deposito);

        $this->expectException(\RuntimeException::class);

        $this->service()->settleMaturedDeposito($deposito->fresh());
    }

    public function test_scope_jatuh_tempo_mengambil_semua_active_yang_sudah_lewat(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(1, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 50000000);

        // Depsito dimulai setahun lalu → sudah jatuh tempo.
        $lama = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 1000000,
            'start_date' => now()->subYear()->toDateString(),
        ]);

        $aro = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 1000000,
            'start_date' => now()->subYear()->toDateString(),
            'aro_mode' => Deposito::ARO_POKOK,
        ]);

        $ids = Deposito::jatuhTempo(today())->pluck('id')->all();

        $this->assertContains($lama->id, $ids, 'Deposito non-ARO yang lewat jatuh tempo harus terambil.');
        $this->assertContains(
            $aro->id,
            $ids,
            'Deposito ARO yang jatuh tempo juga harus terambil agar dapat diperpanjang otomatis.'
        );
    }

    public function test_helper_sisa_hari_dan_sudah_jatuh_tempo(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 50000000);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 1000000,
            'start_date' => today()->toDateString(),
        ]);

        $this->assertFalse($deposito->sudahJatuhTempo());
        $this->assertGreaterThan(0, $deposito->sisaHari());
    }

    /* =====================================================================
     | 7. BAGI HASIL BULANAN (DEBET 5-3001 | KREDIT 2-1001)
     |===================================================================== */

    public function test_bagi_hasil_bulanan_memakai_akun_5_3001_dan_menambah_simpanan_sukarela(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(12, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 10000000);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 5000000,
        ]);

        $bebanSebelum = $this->saldoCoa(Deposito::COA_BEBAN_BAGI_HASIL);
        $sukarelaSebelum = $this->saldoCoa(Deposito::COA_SIMPANAN_SUKARELA);

        $bagiHasil = $this->service()->distributeProfitSharing($deposito, 2026, 1, 1000000);

        // 1.000.000 × 60% = 600.000
        $this->assertSame(600000.0, (float) $bagiHasil->member_profit_amount);
        $this->assertSame(1000000.0, (float) $bagiHasil->gross_koperasi_revenue);
        $this->assertSame(DepositoProfitSharing::STATUS_PAID, $bagiHasil->status);
        $this->assertNotNull($bagiHasil->journal_id);

        // 5-3001 akun Beban (saldo normal Debet) → debet menambah beban.
        $this->assertSame(
            600000.0,
            round($this->saldoCoa(Deposito::COA_BEBAN_BAGI_HASIL) - $bebanSebelum, 2),
            'DEBET 5-3001 Beban Bagi Hasil Deposito harus bertambah.'
        );
        // 2-1001 akun Kewajiban (saldo normal Kredit) → kredit MENAMBAH saldo
        // kewajiban koperasi kepada anggota, karena bagi hasil dibayakan ke
        // rekening simpanan sukarela anggota.
        $this->assertSame(
            600000.0,
            round($this->saldoCoa(Deposito::COA_SIMPANAN_SUKARELA) - $sukarelaSebelum, 2),
            'KREDIT 2-1001 harus menambah saldo kewajiban Simpanan Sukarela (bagi hasil dibayakan ke rekening anggota).'
        );
    }

    public function test_bagi_hasil_bulanan_menambah_saldo_rekening_anggota(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(12, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 10000000);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 5000000,
        ]);

        $saldoSebelum = (float) $sumber->fresh()->saldo;

        $this->service()->distributeProfitSharing($deposito, 2026, 2, 500000);

        $this->assertSame(
            round($saldoSebelum + 300000, 2),
            round((float) $sumber->fresh()->saldo, 2),
            'Bagi hasil 60% dari 500.000 = 300.000 harus masuk ke saldo rekening anggota.'
        );
    }

    public function test_bagi_hasil_periode_yang_sama_tidak_boleh_dobel(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(12, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 10000000);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 5000000,
        ]);

        $this->service()->distributeProfitSharing($deposito, 2026, 3, 500000);

        $this->expectException(\RuntimeException::class);

        $this->service()->distributeProfitSharing($deposito, 2026, 3, 500000);
    }

    public function test_bagi_hasil_bulan_tidak_valid_ditolak(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(12, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 10000000);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 5000000,
        ]);

        $this->expectException(ValidationException::class);

        $this->service()->distributeProfitSharing($deposito, 2026, 13, 500000);
    }

    public function test_relasi_riwayat_bagi_hasil_pada_deposito(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(12, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 10000000);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 5000000,
        ]);

        $this->service()->distributeProfitSharing($deposito, 2026, 1, 400000);
        $this->service()->distributeProfitSharing($deposito, 2026, 2, 400000);

        $riwayat = $deposito->fresh()->profitSharings;

        $this->assertCount(2, $riwayat);
        $this->assertNotNull($riwayat->first()->journal, 'Setiap bagi hasil harus punya jejak jurnal.');
        $this->assertSame('Januari 2026', $riwayat->firstWhere('period_month', 1)->nama_periode);
    }

    /* =====================================================================
     | 8. COMMAND & SCHEDULER
     |===================================================================== */

    public function test_command_dry_run_tidak_mengubah_status(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(1, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 10000000);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 1000000,
            'start_date' => now()->subYear()->toDateString(),
        ]);

        $this->artisan('deposito:process-maturity', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame(Deposito::STATUS_ACTIVE, $deposito->fresh()->status, 'Dry-run tidak boleh mengubah status.');
    }

    public function test_command_mencairkan_deposito_jatuh_tempo(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(1, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 10000000);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 1500000,
            'start_date' => now()->subYear()->toDateString(),
        ]);

        $saldoSebelum = (float) $sumber->fresh()->saldo;

        $this->artisan('deposito:process-maturity')->assertExitCode(0);

        $this->assertSame(
            Deposito::STATUS_CLOSED,
            $deposito->fresh()->status,
            'Mode NON_ARO harus menutup deposito.'
        );
        $this->assertSame(
            round($saldoSebelum + 1500000, 2),
            round((float) $sumber->fresh()->saldo, 2),
            'Pokok harus kembali ke Simpanan Sukarela lewat command.'
        );
    }

    public function test_command_memperpanjang_deposito_aro(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(1, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 10000000);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 1000000,
            'start_date' => now()->subYear()->toDateString(),
            'aro_mode' => Deposito::ARO_POKOK,
        ]);

        $jatuhTempoLama = $deposito->maturity_date->toDateString();

        $this->artisan('deposito:process-maturity')->assertExitCode(0);

        $segar = $deposito->fresh();

        $this->assertSame(Deposito::STATUS_ACTIVE, $segar->status, 'Deposito ARO harus tetap aktif.');
        $this->assertSame(1, (int) $segar->roll_count, 'roll_count harus bertambah 1.');
        $this->assertNotNull($segar->last_rolled_at, 'last_rolled_at harus terisi.');
        $this->assertTrue(
            $segar->maturity_date->toDateString() > $jatuhTempoLama,
            'Tanggal jatuh tempo harus diperpanjang satu tenor.'
        );
    }

    public function test_command_terdaftar_di_scheduler(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command ?? '', 'deposito:process-maturity'));

        $this->assertNotNull($event, 'Command deposito:process-maturity belum terdaftar di scheduler.');
        $this->assertSame('1 0 * * *', $event->expression, 'Jadwal harus dailyAt 00:01.');
    }

    /* =====================================================================
     | 8b. MODUL ARO (AUTOMATIC ROLL OVER)
     |===================================================================== */

    public function test_create_deposito_menyimpan_mode_aro(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 5000000);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 1000000,
            'aro_mode' => Deposito::ARO_PLUS,
        ]);

        $this->assertSame(Deposito::ARO_PLUS, $deposito->aro_mode);
        $this->assertTrue((bool) $deposito->is_aro, 'is_aro harus tercermin dari aro_mode.');
        $this->assertSame(0, (int) $deposito->roll_count);
        $this->assertNull($deposito->last_rolled_at);
        $this->assertSame(Deposito::ARO_LABEL[Deposito::ARO_PLUS], $deposito->aro_label);
    }

    public function test_create_deposito_bawaan_non_aro(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 5000000);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 1000000,
        ]);

        $this->assertSame(Deposito::ARO_NON, $deposito->aro_mode);
        $this->assertFalse((bool) $deposito->is_aro);
    }

    public function test_mode_non_aro_mencairkan_pokok_ke_sukarela_dan_menutup_deposito(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(1, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 5000000);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 2000000,
            'start_date' => now()->subYear()->toDateString(),
            'aro_mode' => Deposito::ARO_NON,
        ]);

        $saldoSebelum = (float) $sumber->fresh()->saldo;
        $berjangkaAwal = $this->saldoCoa(Deposito::COA_SIMPANAN_BERJANGKA);

        $hasil = $this->service()->processMaturity($deposito);

        $this->assertSame(Deposito::ARO_NON, $hasil['mode']);
        $this->assertSame(Deposito::STATUS_CLOSED, $hasil['status']);
        $this->assertSame(2000000.0, round($hasil['pokok'], 2));
        $this->assertSame(0.0, round($hasil['bagi_hasil_dikapitalisasi'], 2));

        $this->assertSame(
            round($saldoSebelum + 2000000, 2),
            round((float) $sumber->fresh()->saldo, 2),
            'Pokok harus cair ke Simpanan Sukarela.'
        );

        // DEBET 2-1003 / KREDIT 2-1001 → kewajiban Dana Syirkah Temporer berkurang.
        $this->assertSame(
            2000000.0,
            round($berjangkaAwal - $this->saldoCoa(Deposito::COA_SIMPANAN_BERJANGKA), 2),
            'DEBET 2-1003 harus mengurangi Dana Syirkah Temporer.'
        );
    }

    public function test_mode_non_aro_menyelesaikan_bagi_hasil_tertunda(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(12, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 5000000);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 1000000,
            'start_date' => now()->subYear()->toDateString(),
        ]);

        // Baris bagi hasil tercatat namun belum berjurnal.
        $belumDibayar = DepositoProfitSharing::create([
            'deposito_id' => $deposito->id,
            'period_year' => (int) now()->format('Y'),
            'period_month' => 1,
            'gross_koperasi_revenue' => 500000,
            'member_profit_amount' => 300000,
            'status' => DepositoProfitSharing::STATUS_PAID,
            'payout_date' => now(),
        ]);

        $saldoSebelum = (float) $sumber->fresh()->saldo;

        $hasil = $this->service()->processMaturity($deposito);

        $this->assertSame(300000.0, round($hasil['bagi_hasil_tertunda'], 2));
        $this->assertSame(
            round($saldoSebelum + 1000000 + 300000, 2),
            round((float) $sumber->fresh()->saldo, 2),
            'Bagi hasil tertunda + pokok harus masuk Simpanan Sukarela.'
        );

        $this->assertNotNull($belumDibayar->fresh()->journal_id, 'Baris bagi hasil harus terhubung ke jurnal.');
    }

    public function test_mode_aro_pokok_memperpanjang_tenor_dan_menaikkan_roll_count(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 5000000);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 3000000,
            'start_date' => now()->subYear()->toDateString(),
            'aro_mode' => Deposito::ARO_POKOK,
        ]);

        $jatuhTempoLama = $deposito->maturity_date->copy();
        $pokokAwal = round((float) $deposito->principal_amount, 2);

        $hasil = $this->service()->processMaturity($deposito);

        $this->assertSame(Deposito::ARO_POKOK, $hasil['mode']);
        $this->assertSame(Deposito::STATUS_ACTIVE, $hasil['status'], 'ARO_POKOK harus tetap ACTIVE.');

        $segar = $deposito->fresh();

        $this->assertSame(1, (int) $segar->roll_count);
        $this->assertNotNull($segar->last_rolled_at);
        $this->assertTrue($segar->maturity_date->greaterThan($jatuhTempoLama));
        $this->assertSame(
            $pokokAwal,
            round((float) $segar->principal_amount, 2),
            'ARO_POKOK tidak boleh mengubah nilai pokok.'
        );
        $this->assertSame(0.0, round($hasil['bagi_hasil_dikapitalisasi'], 2));
    }

    public function test_mode_aro_plus_mengapitalisasi_bagi_hasil_dan_membuat_jurnal_reklasifikasi(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 5000000);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 1000000,
            'start_date' => now()->subYear()->toDateString(),
            'aro_mode' => Deposito::ARO_PLUS,
        ]);

        $jatuhTempoLama = $deposito->maturity_date->copy();

        // Bagi hasil bulanan yang sudah benar-benar dikreditkan ke 2-1001.
        $this->service()->distributeProfitSharing($deposito, 2026, 1, 1000000);

        $sukarelaSebelum = (float) $sumber->fresh()->saldo;
        $berjangkaAwal = $this->saldoCoa(Deposito::COA_SIMPANAN_BERJANGKA);

        $hasil = $this->service()->processMaturity($deposito);

        $this->assertSame(Deposito::ARO_PLUS, $hasil['mode']);
        $this->assertSame(600000.0, round($hasil['bagi_hasil_dikapitalisasi'], 2));
        $this->assertSame(1600000.0, round($hasil['pokok_baru'], 2), 'Pokok baru = pokok + bagi hasil.');

        $segar = $deposito->fresh();

        $this->assertSame(Deposito::STATUS_ACTIVE, $segar->status);
        $this->assertSame(1600000.0, round((float) $segar->principal_amount, 2));
        $this->assertSame(1, (int) $segar->roll_count);
        $this->assertNotNull($segar->last_rolled_at);
        $this->assertTrue($segar->maturity_date->greaterThan($jatuhTempoLama));

        // Reklasifikasi kewajiban: 2-1001 berkurang, 2-1003 bertambah.
        $this->assertSame(
            round($sukarelaSebelum - 600000, 2),
            round((float) $sumber->fresh()->saldo, 2),
            'Bagi hasil ditarik dari Simpanan Sukarela untuk dikapitalisasi.'
        );
        // Pokok lama Rp 1.000.000 sudah tercatat di 2-1003 saat pembukaan;
        // kapitalisasi menambah Rp 600.000 sehingga total menjadi Rp 1.600.000.
        $this->assertSame(
            600000.0,
            round($this->saldoCoa(Deposito::COA_SIMPANAN_BERJANGKA) - $berjangkaAwal, 2),
            'KREDIT 2-1003 harus bertambah sebesar bagi hasil yang dikapitalisasi.'
        );
        $this->assertSame(
            1600000.0,
            round((float) $segar->depositoRekening()->value('saldo'), 2),
            'Saldo rekening bilyet harus sama dengan pokok baru.'
        );
    }

    public function test_aro_plus_tidak_mengapitalisasi_bagi_hasil_dua_kali(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 5000000);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 1000000,
            'start_date' => now()->subYear()->toDateString(),
            'aro_mode' => Deposito::ARO_PLUS,
        ]);

        $this->service()->distributeProfitSharing($deposito, 2026, 1, 1000000);

        $this->service()->processMaturity($deposito);

        // Jatuh tempo berikutnya tanpa bagi hasil baru → tidak ada kapitalisasi lagi.
        $deposito->forceFill(['maturity_date' => now()->subDay()->toDateString()])->save();

        $hasil = $this->service()->processMaturity($deposito->fresh());

        $this->assertSame(0.0, round($hasil['bagi_hasil_dikapitalisasi'], 2), 'Bagi hasil lama tidak boleh dikapitalisasi ulang.');
        $this->assertSame(2, (int) $deposito->fresh()->roll_count);
    }

    public function test_process_maturity_menolak_deposito_tidak_aktif(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(1, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 5000000);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 1000000,
            'start_date' => now()->subYear()->toDateString(),
        ]);

        $deposito->forceFill(['status' => Deposito::STATUS_CLOSED])->save();

        $this->expectException(\RuntimeException::class);

        $this->service()->processMaturity($deposito->fresh());
    }

    public function test_command_dry_run_tidak_mengubah_deposito_aro(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 5000000);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 1000000,
            'start_date' => now()->subYear()->toDateString(),
            'aro_mode' => Deposito::ARO_POKOK,
        ]);

        $jatuhTempoLama = $deposito->maturity_date->toDateString();

        $this->artisan('deposito:process-maturity', ['--dry-run' => true])->assertExitCode(0);

        $segar = $deposito->fresh();

        $this->assertSame(0, (int) $segar->roll_count, 'Dry-run tidak boleh menaikkan roll_count.');
        $this->assertSame($jatuhTempoLama, $segar->maturity_date->toDateString());
    }

    /* =====================================================================
     | 9. API ENDPOINT
     |===================================================================== */

    public function test_api_products_hanya_menampilkan_produk_aktif(): void
    {
        $aktif = $this->produk(3, 60, 100000, true);
        $nonAktif = $this->produk(9, 60, 100000, false);

        $respon = $this->actingAs($this->pengguna())->getJson('/api/deposito/products');

        $respon->assertOk()->assertJsonPath('success', true);

        $ids = collect($respon->json('data'))->pluck('id')->all();

        $this->assertContains($aktif->id, $ids);
        $this->assertNotContains($nonAktif->id, $ids);
    }

    public function test_api_simulate_mengembalikan_hasil_bagi_hasil(): void
    {
        $produk = $this->produk(12, 60, 100000);

        $respon = $this->actingAs($this->pengguna())->postJson('/api/deposito/simulate', [
            'amount' => 12000000,
            'product_id' => $produk->id,
            'start_date' => '2026-01-01',
        ]);

        $respon->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.estimated_gross_revenue', 960000)
            ->assertJsonPath('data.estimated_member_profit', 576000)
            ->assertJsonPath('data.maturity_date', '2027-01-01');
    }

    public function test_api_simulate_validasi_input(): void
    {
        $this->actingAs($this->pengguna())->postJson('/api/deposito/simulate', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount', 'product_id']);
    }

    public function test_api_store_membuat_deposito_dan_memotong_simpanan_sukarela(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 10000000);

        $respon = $this->actingAs($this->pengguna())->postJson('/api/deposito/store', [
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 2000000,
            'start_date' => '2026-04-10',
        ]);

        $respon->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.maturity_date', '2026-07-10')
            ->assertJsonPath('data.principal_amount', 2000000);

        $this->assertSame(8000000.0, (float) $sumber->fresh()->saldo);
    }

    public function test_api_store_menolak_saldo_tidak_cukup(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 100000);

        $this->actingAs($this->pengguna())->postJson('/api/deposito/store', [
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 5000000,
        ])->assertStatus(422)->assertJsonPath('success', false);

        $this->assertSame(100000.0, (float) $sumber->fresh()->saldo);
    }

    public function test_api_member_mengembalikan_bilyet_dan_riwayat_bagi_hasil(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(12, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 10000000);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 3000000,
        ]);
        $this->service()->distributeProfitSharing($deposito, 2026, 1, 1000000);

        $respon = $this->actingAs($this->pengguna())->getJson('/api/deposito/member/'.$anggota->id);

        $respon->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.member.id', $anggota->id);

        $bilyet = collect($respon->json('data.depositos'))->firstWhere('id', $deposito->id);

        $this->assertNotNull($bilyet, 'Bilyet deposito anggota tidak muncul di API.');
        $this->assertSame('ACTIVE', $bilyet['status']);
        $this->assertSame(600000, $bilyet['total_bagi_hasil_diterima']);
        $this->assertCount(1, $bilyet['profit_sharings']);
        $this->assertSame('Januari 2026', $bilyet['profit_sharings'][0]['nama_periode']);
    }

    public function test_api_member_tidak_ditemukan_mengembalikan_404(): void
    {
        $this->actingAs($this->pengguna())->getJson('/api/deposito/member/999999999')
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_api_member_tidak_mengembalikan_deposito_anggota_lain(): void
    {
        $anggotaA = $this->anggota();
        $anggotaB = Anggota::create([
            'no_anggota' => $this->prefix.'AGB',
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Uji Deposito B',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Alamat Uji B',
            'kota' => 'Surabaya',
            'provinsi' => 'Jawa Timur',
            'status_anggota' => 'Aktif',
            'tanggal_gabung' => now()->toDateString(),
        ]);

        $produk = $this->produk(3, 60, 100000);
        $sumberB = $this->rekeningSukarela($anggotaB, 10000000);

        $depositoB = $this->service()->createDeposito([
            'member_id' => $anggotaB->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumberB->id,
            'principal_amount' => 1000000,
        ]);

        $ids = collect($this->actingAs($this->pengguna())->getJson('/api/deposito/member/'.$anggotaA->id)->json('data.depositos'))
            ->pluck('id')
            ->all();

        $this->assertNotContains($depositoB->id, $ids, 'API tidak boleh membocorkan bilyet milik anggota lain.');
    }

    /* =====================================================================
     | 10. INTEGRASI FORM "BUKA REKENING SIMPANAN" (Portofolio CIF Anggota)
     |===================================================================== */

    /** Rekening Simpanan Sukarela untuk pengujian route (mis. Simpanan Wajib). */
    private function rekeningJenis(Anggota $anggota, string $jenis, float $saldo = 1000000): Rekening
    {
        return $this->rekeningSukarela($anggota, $saldo, $jenis);
    }

    public function test_form_cif_menampilkan_produk_deposito_dan_tidak_lagi_input_jatuh_tempo_manual(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(6, 60, 100000);
        $this->rekeningSukarela($anggota, 5000000);

        $respon = $this->actingAs($this->penggunaAo())->get(route('ao.cif.show', $anggota->id));

        $respon->assertOk();

        $html = $respon->getContent();

        // Produk deposito aktif tersedia sebagai pilihan (sumber tenor & nisbah).
        $this->assertStringContainsString('deposito_product_id', $html);
        $this->assertStringContainsString((string) $produk->id, $html);

        // Tanggal jatuh tempo tidak lagi diisi manual: field tersedia tetapi
        // read-only dan nilainya dihitung otomatis oleh JS dari tenor produk.
        $this->assertStringContainsString('id="inp-jatuh-tempo"', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*id="inp-jatuh-tempo"[^>]*readonly/', $html,
            'Input tanggal jatuh tempo harus read-only (dihitung otomatis dari tenor produk).');

        // Sumber dana ditampilkan sebagai rekening Simpanan Sukarela anggota.
        $this->assertStringContainsString('hint-saldo-sukarela', $html);
    }

    public function test_buka_rekening_deposito_via_form_memotong_simpanan_sukarela_dan_menghitung_jatuh_tempo(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 10000000);

        $respon = $this->actingAs($this->penggunaAo())->post(route('ao.cif.buka-rekening', $anggota->id), [
            'jenis_rekening' => 'Simpanan Berjangka',
            'setoran_awal' => 3000000,
            'deposito_product_id' => $produk->id,
            'tanggal_akad' => '2026-01-31',
        ]);

        $respon->assertRedirect(route('ao.cif.show', $anggota->id));
        $respon->assertSessionHasNoErrors();

        // Pokok dipotong dari Simpanan Sukarela (bukan dari Kas Teller).
        $this->assertSame(7000000.0, (float) $sumber->fresh()->saldo,
            'Saldo Simpanan Sukarela harus berkurang sebesar pokok deposito.');

        $deposito = Deposito::where('member_id', $anggota->id)->latest('id')->first();

        $this->assertNotNull($deposito, 'Bilyet deposito harus terbentuk dari form CIF.');
        $this->assertSame('ACTIVE', $deposito->status);
        $this->assertSame(3000000.0, (float) $deposito->principal_amount);

        // Tanggal Akad yang DIINPUT pengguna menjadi start_date deposito.
        $this->assertSame('2026-01-31', $deposito->start_date->toDateString(),
            'start_date harus mengikuti field Tanggal Akad, bukan tanggal hari ini.');

        // Jatuh tempo = Tanggal Akad + tenor produk (Carbon), bukan input pengguna.
        $this->assertSame(
            Carbon::parse($deposito->start_date)->addMonthsNoOverflow(3)->toDateString(),
            $deposito->maturity_date->toDateString()
        );
        $this->assertSame(3, $deposito->tenorMonths());

        // Nisbah produk disalin untuk audit trail.
        $this->assertSame(60.0, (float) $deposito->nisbah_anggota);
    }

    public function test_buka_rekening_deposito_tanpa_produk_ditolak(): void
    {
        $anggota = $this->anggota();
        $this->rekeningSukarela($anggota, 10000000);

        $this->actingAs($this->penggunaAo())
            ->post(route('ao.cif.buka-rekening', $anggota->id), [
                'jenis_rekening' => 'Simpanan Berjangka',
                'setoran_awal' => 1000000,
                'tanggal_akad' => '2026-02-01',
            ])
            ->assertSessionHasErrors('deposito_product_id');

        $this->assertSame(0, Deposito::where('member_id', $anggota->id)->count());
    }

    public function test_buka_rekening_deposito_tanpa_tanggal_akad_ditolak(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $this->rekeningSukarela($anggota, 10000000);

        // Tanggal Akad wajib: tanpa itu sistem tidak bisa menghitung jatuh tempo.
        $this->actingAs($this->penggunaAo())
            ->post(route('ao.cif.buka-rekening', $anggota->id), [
                'jenis_rekening' => 'Simpanan Berjangka',
                'setoran_awal' => 1000000,
                'deposito_product_id' => $produk->id,
            ])
            ->assertSessionHasErrors('tanggal_akad');

        $this->assertSame(0, Deposito::where('member_id', $anggota->id)->count());
    }

    public function test_buka_rekening_deposito_tanpa_simpanan_sukarela_ditolak(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);

        // Anggota hanya punya Simpanan Wajib — bukan Simpanan Sukarela.
        $this->rekeningJenis($anggota, 'Simpanan Wajib', 10000000);

        $this->actingAs($this->penggunaAo())
            ->post(route('ao.cif.buka-rekening', $anggota->id), [
                'jenis_rekening' => 'Simpanan Berjangka',
                'setoran_awal' => 1000000,
                'deposito_product_id' => $produk->id,
                'tanggal_akad' => '2026-02-01',
            ])
            ->assertSessionHasErrors('jenis_rekening');

        $this->assertSame(0, Deposito::where('member_id', $anggota->id)->count());
    }

    public function test_buka_rekening_deposito_dengan_saldo_kurang_ditolak_tanpa_mengubah_saldo(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 500000);

        $this->actingAs($this->penggunaAo())
            ->post(route('ao.cif.buka-rekening', $anggota->id), [
                'jenis_rekening' => 'Simpanan Berjangka',
                'setoran_awal' => 2000000,
                'deposito_product_id' => $produk->id,
                'tanggal_akad' => '2026-02-01',
            ])
            ->assertSessionHasErrors('principal_amount');

        $this->assertSame(500000.0, (float) $sumber->fresh()->saldo);
        $this->assertSame(0, Deposito::where('member_id', $anggota->id)->count());
    }

    public function test_buka_rekening_bukan_deposito_tetap_memakai_alur_lama(): void
    {
        $anggota = $this->anggota();

        $this->actingAs($this->penggunaAo())
            ->post(route('ao.cif.buka-rekening', $anggota->id), [
                'jenis_rekening' => 'Simpanan Sukarela',
                'akad' => 'Wadiah',
                'setoran_awal' => 150000,
            ])
            ->assertSessionHasNoErrors();

        $rekening = Rekening::where('anggota_id', $anggota->id)
            ->where('jenis_rekening', 'Simpanan Sukarela')
            ->first();

        $this->assertNotNull($rekening);
        $this->assertSame(150000.0, (float) $rekening->saldo);

        // Rekening biasa tidak menghasilkan bilyet deposito.
        $this->assertSame(0, Deposito::where('member_id', $anggota->id)->count());
    }

    /* =====================================================================
     | 11. INTEGRASI MODAL "BUKA REKENING SIMPANAN" DENGAN API DEPOSITO
     |     (event listener, nisbah & jatuh tempo otomatis, validasi
     |      min_amount, submit JSON, penanganan error, refresh daftar)
     |===================================================================== */

    public function test_modal_cif_memuat_seluruh_kontrak_integrasi_api_deposito(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(6, 60, 250000);
        $sumber = $this->rekeningSukarela($anggota, 5000000);

        $respon = $this->actingAs($this->penggunaAo())->get(route('ao.cif.show', $anggota->id));
        $respon->assertOk();

        $html = $respon->getContent();

        // 1. Formulir & wadah pesan error di bagian ATAS modal.
        $this->assertStringContainsString('id="form-buka-rekening"', $html);
        $this->assertStringContainsString('id="modal-error"', $html);
        $formPos = strpos($html, 'id="form-buka-rekening"');
        $errorPos = strpos($html, 'id="modal-error"');
        $this->assertLessThan(strpos($html, 'id="inp-setoran"'), $errorPos,
            'Wadah error API harus berada di atas field input (bagian atas modal).');
        $this->assertGreaterThan($formPos, $errorPos);

        // 2. Field interaktif yang dikendalikan JS.
        foreach ([
            'id="sel-jenis"',
            'id="sel-produk-deposito"',
            'id="wrap-produk-deposito"',
            'id="loading-produk"',
            'id="inp-nisbah"',
            'id="wrap-tanggal-akad"',
            'id="inp-tanggal-akad"',
            'id="wrap-jatuh-tempo"',
            'id="inp-jatuh-tempo"',
            'id="warn-setoran"',
            'id="btn-submit-rekening"',
            'id="info-jurnal-deposito"',
            'id="info-jurnal-biasa"',
        ] as $id) {
            $this->assertStringContainsString($id, $html, "Elemen {$id} harus ada di modal.");
        }

        // 2b. Dropdown produk hanya aktif saat jenis rekening deposito dipilih:
        //     secara default (server-render) harus disabled.
        $this->assertMatchesRegularExpression(
            '/<select[^>]*id="sel-produk-deposito"[^>]*disabled/',
            $html,
            'Dropdown produk deposito harus disabled sampai jenis rekening Deposito dipilih.'
        );

        // 2c. Tanggal Akad adalah basis perhitungan jatuh tempo (diinput pengguna).
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="tanggal_akad"[^>]*id="inp-tanggal-akad"/',
            $html,
            'Field Tanggal Akad harus ada sebagai basis perhitungan jatuh tempo.'
        );

        // 3. Field nisbah & jatuh tempo wajib read-only (diisi sistem).
        $this->assertMatchesRegularExpression('/<input[^>]*id="inp-nisbah"[^>]*readonly/', $html,
            'Nisbah Nasabah harus read-only saat jenis rekening Deposito.');
        $this->assertMatchesRegularExpression('/<input[^>]*id="inp-jatuh-tempo"[^>]*readonly/', $html);

        // 3b. Struktur modal tiga bagian: kartu dibatasi 90vh & disusun
        //     vertikal, header + footer sticky, body formulir punya scroll
        //     sendiri sehingga tidak ada elemen yang terpotong.
        $this->assertStringContainsString('class="max-h-[90vh] flex flex-col rounded-xl bg-white shadow-xl"', $html,
            'Kartu modal harus memakai class max-h-[90vh] flex flex-col rounded-xl bg-white shadow-xl.');
        $this->assertStringContainsString('display:flex;flex-direction:column;max-height:90vh', $html,
            'Kartu modal harus punya inline style padanan class Tailwind-nya '
            .'(halaman CIF tidak memuat Tailwind).');
        $this->assertStringContainsString('class="overflow-y-auto p-6 space-y-4 max-h-[calc(90vh-130px)]"', $html,
            'Body modal harus memakai class overflow-y-auto p-6 space-y-4 max-h-[calc(90vh-130px)].');
        $this->assertStringContainsString('max-height:calc(90vh - 130px);overflow-y:auto', $html,
            'Body modal harus punya inline style padanan class Tailwind-nya.');
        $this->assertMatchesRegularExpression(
            '/<div class="sticky top-0 z-10 flex items-center justify-between"\s+style="[^"]*position:sticky;top:0/',
            $html,
            'Header modal (judul + tombol close) harus sticky di bagian atas.'
        );
        $this->assertMatchesRegularExpression(
            '/<div class="sticky bottom-0 z-10 flex justify-end"\s+style="[^"]*position:sticky;bottom:0/',
            $html,
            'Footer modal (tombol Batal + Buka Rekening) harus sticky di bagian bawah.'
        );
        $this->assertStringContainsString('form="form-buka-rekening"', $html,
            'Tombol submit di footer wajib memakai form owner HTML5 karena berada di luar <form>.');

        // 3c. Otomatisasi nisbah & jatuh tempo yang tahan atribut data kosong.
        foreach ([
            'function angkaAman',
            'function nisbahProduk',
            'function produkTerpilih',
            'function hitungJatuhTempo',
            'function perbaruiJatuhTempo',
            'function terapkanProduk',
            'window.onProdukDepositoChange',
            'window.onTanggalAkadChange',
        ] as $fungsi) {
            $this->assertStringContainsString($fungsi, $html, "Fungsi {$fungsi} harus tersedia di modal.");
        }

        // Listener 'change' pada dropdown produk & input Tanggal Akad.
        $this->assertStringContainsString("\$('sel-produk-deposito').addEventListener('change'", $html,
            "Dropdown Produk Deposito harus memicu otomatisasi lewat event 'change'.");
        $this->assertStringContainsString("\$('inp-tanggal-akad').addEventListener('change'", $html,
            "Tanggal Akad harus memicu perhitungan ulang jatuh tempo lewat event 'change'.");

        // Reset nilai & status read-only saat dropdown kembali ke opsi default.
        $this->assertStringContainsString('inpNisbah.readOnly = false', $html,
            'Status read-only nisbah harus dilepas saat produk direset ke opsi default.');
        $this->assertStringContainsString('inpNisbah.readOnly = true', $html,
            'Nisbah harus dikunci read-only saat produk deposito dipilih.');

        // Guard: perhitungan jatuh tempo berhenti bila produk belum dipilih valid.
        $this->assertMatchesRegularExpression(
            '/window\.onTanggalAkadChange = function \(\) \{\s*var produk = produkTerpilih\(\);\s*'
            .'if \(!produk\) \{ return; \}/',
            $html,
            'Perhitungan jatuh tempo harus dihentikan bila dropdown produk belum punya pilihan valid.'
        );

        // 3d. Show/hide dinamis: field non-relevan disembunyikan sekaligus
        //     dinonaktifkan (disabled), sehingga nilainya tidak ikut terkirim
        //     bersama form saat pengguna memilih produk non-deposito.
        $this->assertStringContainsString('function setField(id, tampil, aktif, kosongkan)', $html);
        $this->assertStringContainsString('field.disabled = !aktif', $html,
            'Field yang disembunyikan harus dinonaktifkan agar nilainya tidak ter-submit.');
        $this->assertStringContainsString("setField('inp-tanggal-akad', false, false, true)", $html,
            'Tanggal Akad harus disembunyikan & dikosongkan untuk produk non-deposito.');
        $this->assertStringContainsString("setField('inp-jatuh-tempo', false, false, true)", $html,
            'Tanggal Jatuh Tempo harus disembunyikan & dikosongkan untuk produk non-deposito.');
        $this->assertStringContainsString("window.onJenisChange(\$('sel-jenis').value)", $html,
            'Visibilitas field harus diselaraskan dengan jenis rekening saat halaman dimuat.');

        // 4. Metadata produk untuk auto-fill nisbah, tenor, dan min_amount.
        $this->assertStringContainsString('data-nisbah="60.00"', $html);
        $this->assertStringContainsString('data-tenor="6"', $html);
        $this->assertStringContainsString('data-min="250000"', $html);
        $this->assertStringContainsString('data-name="', $html);

        // 5. Kontrak URL API yang dipakai fetch() di sisi klien.
        $this->assertStringContainsString('/api/deposito/products', $html);
        $this->assertStringContainsString('/api/deposito/store', $html);

        // 6. Konteks anggota & rekening sumber dana diteruskan ke JS.
        $this->assertStringContainsString('memberId:', $html);
        $this->assertStringContainsString('sumberSukarelaId:', $html);
        $this->assertStringContainsString('produkSudahDimuat', $html,
            'Harus ada penjaga agar GET /api/deposito/products tidak dipanggil berulang.');
        $this->assertStringContainsString('sedangKirim', $html,
            'Harus ada penjaga agar POST /api/deposito/store tidak terkirim ganda.');

        // 7. Keterangan jurnal deposito memakai kode akun sistem (2-1001 / 2-1003),
        //    bukan 2-2001 yang sudah terpakai "Utang Dana Pengurus & Karyawan".
        $this->assertStringContainsString('Jurnal PSAK 101/108', $html);
        $this->assertStringContainsString(Deposito::COA_SIMPANAN_SUKARELA.' Simpanan Sukarela', $html);
        $this->assertStringContainsString(Deposito::COA_SIMPANAN_BERJANGKA.' Simpanan Berjangka Mudharabah', $html);
        $this->assertStringNotContainsString('Kredit 2-2001', $html,
            'Kode 2-2001 bukan akun Simpanan Berjangka — jangan dipakai di UI.');

        // 8. Setelah sukses, daftar deposito disegarkan secara PARSIAL (AJAX):
        //    hanya fragmen tabel yang diminta ulang dari server (?partial=deposito)
        //    lalu ditukar di DOM — halaman TIDAK dimuat ulang.
        $this->assertStringContainsString('muatUlangPortofolio', $html);
        $this->assertStringContainsString('partial=deposito', $html);
        $this->assertStringContainsString('id="deposito-list-wrapper"', $html);
        $this->assertStringContainsString('id="deposito-bilyet-card"', $html);
        $this->assertStringNotContainsString('location.replace', $html,
            'Refresh daftar deposito tidak boleh memuat ulang seluruh halaman.');

        // 9. Rekening sumber dana anggota benar-benar dirender (bukan dummy).
        $this->assertStringContainsString($sumber->no_rekening, $html);
        $this->assertStringContainsString(route('ao.cif.buka-rekening', $anggota->id), $html);
    }

    /**
     * Dropdown "Produk Deposito" hanya boleh memuat master produk bertipe
     * deposito (PSAK 108). Produk simpanan (Pokok/Wajib/Sukarela — PSAK 101)
     * tidak punya tenor maupun minimum setoran sehingga wajib dikecualikan.
     */
    public function test_dropdown_produk_deposito_hanya_memuat_produk_bertipe_deposito(): void
    {
        $anggota = $this->anggota();

        $dep1 = $this->produk(1, 40, 100000);
        $dep2 = $this->produk(3, 50, 250000);
        $dep3 = $this->produk(6, 60, 500000);

        $pokok = $this->produkSimpanan(DepositoProduct::TIPE_POKOK);
        $wajib = $this->produkSimpanan(DepositoProduct::TIPE_WAJIB);
        $sukarela = $this->produkSimpanan(DepositoProduct::TIPE_SUKARELA, 15.0);

        $html = $this->actingAs($this->penggunaAo())
            ->get(route('ao.cif.show', $anggota->id))
            ->assertOk()
            ->getContent();

        // Seluruh tenor deposito terkonfigurasi tampil sebagai opsi dropdown,
        // lengkap dengan metadata nisbah & minimum setoran untuk auto-fill JS.
        $this->assertStringContainsString('data-name="'.$dep1->name.'"', $html);
        $this->assertStringContainsString('data-name="'.$dep2->name.'"', $html);
        $this->assertStringContainsString('data-name="'.$dep3->name.'"', $html);
        $this->assertStringContainsString('data-tenor="1"', $html);
        $this->assertStringContainsString('data-tenor="3"', $html);
        $this->assertStringContainsString('data-tenor="6"', $html);
        $this->assertStringContainsString('data-nisbah="40.00"', $html);
        $this->assertStringContainsString('data-min="100000"', $html);
        $this->assertStringContainsString('data-min="250000"', $html);
        $this->assertStringContainsString('data-min="500000"', $html);

        // Produk simpanan tidak boleh ikut ter-render sebagai opsi produk.
        foreach ([$pokok, $wajib, $sukarela] as $simpanan) {
            $this->assertStringNotContainsString(
                'data-name="'.$simpanan->name.'"',
                $html,
                "Produk simpanan {$simpanan->name} tidak boleh muncul di dropdown Produk Deposito."
            );
        }
    }

    /**
     * Nisbah Simpanan Sukarela berasal dari master produk (dinamis, satu
     * sumber kebenaran di DB) dan ditampilkan READ-ONLY — pengguna tidak
     * boleh mengubah bagi hasil secara manual (PSAK 101).
     */
    public function test_modal_cif_menampilkan_nisbah_sukarela_dinamis_read_only(): void
    {
        $anggota = $this->anggota();
        $this->produkSimpanan(DepositoProduct::TIPE_SUKARELA, 15.0);

        $html = $this->actingAs($this->penggunaAo())
            ->get(route('ao.cif.show', $anggota->id))
            ->assertOk()
            ->getContent();

        // Nilai nisbah diteruskan ke JS dari konfigurasi produk, bukan hardcode.
        $this->assertStringContainsString('nisbahSukarela:', $html);
        $this->assertMatchesRegularExpression(
            '/nisbahSukarela:\s*15(\.0+)?\b/',
            $html,
            'Nisbah sukarela harus diambil dari master produk (single source of truth).'
        );
        $this->assertStringContainsString(
            'var NISBAH_SUKARELA = angkaAman(CTX.nisbahSukarela, 0);',
            $html
        );

        // Field nisbah dikunci read-only disertai hint khusus Simpanan Sukarela.
        $this->assertStringContainsString('id="hint-nisbah-sukarela"', $html);
        $this->assertStringContainsString(
            'Nisbah default produk Simpanan Sukarela (tidak dapat diubah).',
            $html
        );
        $this->assertStringContainsString(
            "inpNisbah.value = NISBAH_SUKARELA > 0 ? NISBAH_SUKARELA : '';",
            $html,
            'Nisbah sukarela harus terisi otomatis dari produk.'
        );
        $this->assertStringContainsString('if (sukarela) {', $html);
        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="inp-nisbah"[^>]*readonly/',
            $html,
            'Nisbah Nasabah harus read-only (diisi sistem).'
        );
    }

    /**
     * Simpanan Pokok & Simpanan Wajib tidak memakai bagi hasil bulanan:
     * seluruh field Nisbah, Tenor, dan Tanggal Jatuh Tempo disembunyikan
     * sekaligus dinonaktifkan & dikosongkan. Pendapatannya dihitung lewat
     * SHU Tahunan pada bagian Ekuitas laporan keuangan (PSAK 101).
     */
    public function test_modal_cif_menyembunyikan_nisbah_tenor_dan_jatuh_tempo_untuk_pokok_dan_wajib(): void
    {
        $anggota = $this->anggota();

        $html = $this->actingAs($this->penggunaAo())
            ->get(route('ao.cif.show', $anggota->id))
            ->assertOk()
            ->getContent();

        // Cabang eksplisit Pokok/Wajib tanpa nisbah — kini diklasifikasi dari
        // parameter dinamis (atribut `data-tipe` pada opsi), bukan nama hardcoded.
        $this->assertStringContainsString('var tipeJenisVal = tipeJenis(val);', $html);
        $this->assertStringContainsString(
            "var tanpaNisbah  = (tipeJenisVal === 'pokok' || tipeJenisVal === 'wajib');",
            $html
        );
        $this->assertStringContainsString('if (tanpaNisbah) {', $html);
        $this->assertStringContainsString(
            "setField('inp-nisbah', false, false, true);",
            $html,
            'Nisbah harus disembunyikan, dinonaktifkan, dan dikosongkan untuk Pokok/Wajib.'
        );

        // Tenor/Tanggal Akad & Jatuh Tempo tersembunyi untuk semua jenis
        // simpanan non-deposito.
        $this->assertStringContainsString("setField('inp-tanggal-akad', false, false, true);", $html);
        $this->assertStringContainsString("setField('inp-jatuh-tempo', false, false, true);", $html);
        $this->assertStringContainsString('selProduk.disabled = true;', $html);
        $this->assertStringContainsString("selProduk.value = '';", $html);

        // Cabang Deposito & Sukarela tetap tersedia.
        $this->assertStringContainsString("var deposito     = (tipeJenisVal === 'berjangka');", $html);
        $this->assertStringContainsString("var sukarela     = (tipeJenisVal === 'sukarela');", $html);

        // Klasifikasi wajib mengikuti parameter master, bukan nama jenis.
        $this->assertStringContainsString('data-tipe="', $html);
    }

    public function test_respons_parsial_cif_hanya_mengirim_fragmen_tabel_bilyet(): void
    {
        $anggota = $this->anggota();
        $deposito = $this->depositoAktif($anggota);

        $respon = $this->actingAs($this->penggunaAo())
            ->get(route('ao.cif.show', $anggota->id).'?partial=deposito');

        $respon->assertOk();

        $html = $respon->getContent();

        // Fragmen tabel bilyet terkirim beserta bilyet anggota.
        $this->assertStringContainsString('id="deposito-bilyet-card"', $html);
        $this->assertStringContainsString($deposito->deposito_number, $html);

        // Kerangka halaman penuh TIDAK ikut dikirim (bukan reload halaman).
        $this->assertStringNotContainsString('<html', $html);
        $this->assertStringNotContainsString('id="form-buka-rekening"', $html);
        $this->assertStringNotContainsString('id="flash-deposito"', $html);

        // Script cetak bilyet tidak diulang pada respons parsial — event
        // delegation-nya sudah terpasang di halaman induk.
        $this->assertStringNotContainsString('URL_BILYET', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_api_store_sukses_membentuk_bilyet_dan_rekening_baru_untuk_cif(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 10000000);
        $mulai = '2026-01-31'; // uji clamp addMonthsNoOverflow (31 Jan + 3 bln = 30 Apr)

        // K-2: /api/deposito/store adalah EKSEKUSI KAS — dijaga
        // `izin:kas.loket.eksekusi` + `kas.terbuka`, sama seperti jalur web.
        // AO berwenang menyiapkan pembukaan rekening, tetapi pembukaan
        // deposito memindahkan dana anggota sehingga dieksekusi pemegang
        // laci (teller) dengan sesi kas yang sudah dibuka.
        $petugas = $this->pengguna();
        $this->bukaSesiKas($petugas);

        $respon = $this->actingAs($petugas)->postJson('/api/deposito/store', [
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 2500000,
            'start_date' => $mulai,
            'is_aro' => false,
        ]);

        $respon->assertStatus(201)->assertJson(['success' => true]);
        $this->assertNotEmpty($respon->json('message'));

        $deposito = Deposito::where('member_id', $anggota->id)->latest('id')->first();
        $this->assertNotNull($deposito, 'Bilyet deposito harus terbentuk dari API store.');

        // Jatuh tempo = start_date + tenor (Carbon addMonthsNoOverflow).
        $this->assertSame(
            Carbon::parse($mulai)->addMonthsNoOverflow(3)->toDateString(),
            $deposito->maturity_date->toDateString()
        );
        $this->assertSame('2026-04-30', $deposito->maturity_date->toDateString());

        // Pokok dipotong dari Simpanan Sukarela.
        $this->assertSame(7500000.0, (float) $sumber->fresh()->saldo);

        // Rekening deposito terbentuk sehingga muncul di daftar rekening CIF
        // setelah halaman di-refresh klien.
        $rekening = Rekening::where('anggota_id', $anggota->id)
            ->where('jenis_rekening', 'Simpanan Berjangka')
            ->first();

        $this->assertNotNull($rekening, 'Rekening Simpanan Berjangka harus muncul di Portofolio CIF.');

        $html = $this->actingAs($this->penggunaAo())
            ->get(route('ao.cif.show', $anggota->id))
            ->getContent();

        $this->assertStringContainsString($rekening->no_rekening, $html,
            'Daftar rekening pada Portofolio CIF harus memuat rekening deposito baru.');
    }

    /**
     * K-2 — API pembukaan deposito adalah EKSEKUSI KAS.
     *
     * Jalur web `teller.deposito.store` sengaja masih menerima manajer
     * penyelia loket, tetapi API hanya untuk pemegang laci. Bila kelak
     * `izin:kas.loket.eksekusi` melebar lagi ke `teller.loket`, rekening
     * anggota akan terdebit oleh penyetuju — inilah yang dijaga test ini.
     */
    public function test_api_store_menolak_manajer_dan_tidak_mendebit_rekening_anggota(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 10000000);

        $this->actingAs($this->penggunaManajer())->postJson('/api/deposito/store', [
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 2500000,
            'start_date' => '2026-02-10',
            'is_aro' => false,
        ])->assertForbidden();

        $this->assertSame(10000000.0, (float) $sumber->fresh()->saldo,
            'Manajer tidak boleh memindahkan dana anggota lewat API deposito.');
        $this->assertSame(0, Deposito::where('member_id', $anggota->id)->count());
    }

    /** Jalur WEB tetap menerima manajer (penyelia loket) — lihat test di atas. */
    public function test_web_store_tetap_menerima_manajer_dengan_sesi_kas(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $this->rekeningSukarela($anggota, 10000000);

        $manajer = $this->penggunaManajer();
        $this->bukaSesiKas($manajer);

        $respon = $this->actingAs($manajer)->post(route('teller.deposito.store'), [
            'anggota_id' => $anggota->id,
            'nominal' => 2500000,
            'deposito_product_id' => $produk->id,
        ]);

        $this->assertNotSame(403, $respon->status(),
            'Gerbang web `teller.loket` tidak boleh berubah oleh perbaikan K-2 (API saja).');
    }

    public function test_api_store_menolak_setoran_di_bawah_min_amount_dengan_pesan_jelas(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 1000000);
        $sumber = $this->rekeningSukarela($anggota, 10000000);

        $respon = $this->actingAs($this->pengguna())->postJson('/api/deposito/store', [
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 500000, // < min_amount 1.000.000
            'start_date' => '2026-02-10',
            'is_aro' => false,
        ]);

        $respon->assertStatus(422)->assertJson(['success' => false]);

        $pesan = $respon->json('errors.principal_amount.0') ?? $respon->json('message');

        $this->assertNotNull($pesan, 'Server harus mengirim pesan validasi principal_amount.');
        $this->assertStringContainsString('minimal', (string) $pesan);

        // Tidak ada efek samping: saldo tetap, bilyet tidak terbentuk.
        $this->assertSame(10000000.0, (float) $sumber->fresh()->saldo);
        $this->assertSame(0, Deposito::where('member_id', $anggota->id)->count());
    }

    public function test_api_store_menolak_saldo_sukarela_tidak_mencukupi_dengan_pesan_jelas(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota, 500000);

        $respon = $this->actingAs($this->pengguna())->postJson('/api/deposito/store', [
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 2000000, // > saldo 500.000
            'start_date' => '2026-02-10',
            'is_aro' => false,
        ]);

        $respon->assertStatus(422)->assertJson(['success' => false]);

        $pesan = (string) ($respon->json('errors.principal_amount.0') ?? $respon->json('message'));

        $this->assertStringContainsString('Sukarela', $pesan,
            'Pesan error harus menjelaskan saldo Simpanan Sukarela tidak mencukupi.');

        $this->assertSame(500000.0, (float) $sumber->fresh()->saldo);
        $this->assertSame(0, Deposito::where('member_id', $anggota->id)->count());
    }

    public function test_api_products_hanya_berisi_produk_aktif_dan_lengkap_untuk_auto_fill(): void
    {
        $aktif = $this->produk(6, 60, 250000);
        $nonAktif = $this->produk(12, 55, 500000, false);

        $respon = $this->actingAs($this->pengguna())->getJson('/api/deposito/products');

        $respon->assertOk()->assertJson(['success' => true]);

        $data = collect($respon->json('data'));

        $this->assertTrue($data->contains('id', $aktif->id));

        // Produk non-aktif tidak boleh muncul sebagai pilihan.
        // (ID-nya tidak boleh ada pada daftar.)
        $this->assertFalse(
            $data->contains(fn ($p) => (int) $p['id'] === (int) $nonAktif->id),
            'Produk deposito non-aktif tidak boleh dirender sebagai pilihan.'
        );

        // Kontrak field yang dipakai JS untuk auto-fill.
        $baris = $data->firstWhere('id', $aktif->id);
        foreach (['name', 'tenor_months', 'nisbah_anggota', 'min_amount'] as $kunci) {
            $this->assertArrayHasKey($kunci, $baris, "Field {$kunci} wajib ada pada respons produk.");
        }

        $this->assertSame(6, (int) $baris['tenor_months']);
        $this->assertSame(60.0, (float) $baris['nisbah_anggota']);
        $this->assertSame(250000.0, (float) $baris['min_amount']);
    }

    /* =====================================================================
     | 12. CETAK BILYET DEPOSITO DIGITAL (PDF)
     |===================================================================== */

    /** Pengguna dengan peran tertentu untuk pengujian otorisasi. */
    private function penggunaBerperan(string $role, ?string $email = null): User
    {
        $email ??= 'bilyet.'.$role.'@test.local';

        return User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Pengguna '.strtoupper($role).' Uji',
                'password' => Hash::make('password'),
                'role' => $role,
                'is_active' => true,
            ]
        );
    }

    /** Buka deposito ACTIVE milik anggota, kembalikan bilyetnya. */
    private function depositoAktif(Anggota $anggota, float $pokok = 2500000, int $tenor = 3, float $nisbah = 60.0): Deposito
    {
        $produk = $this->produk($tenor, $nisbah, 100000);
        $sumber = $this->rekeningSukarela($anggota, $pokok * 4);

        return $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => $pokok,
            'start_date' => '2026-03-15',
            'is_aro' => false,
        ]);
    }

    /**
     * Render HTML bilyet memakai data persis seperti yang dikirim ke dompdf,
     * sehingga isi dokumen dapat diperiksa tanpa mengurai PDF biner.
     */
    private function htmlBilyet(Deposito $deposito): string
    {
        return view('deposito.bilyet-pdf', $this->service()->dataBilyet($deposito))->render();
    }

    public function test_service_generate_bilyet_pdf_menghasilkan_pdf_a4_yang_valid(): void
    {
        $deposito = $this->depositoAktif($this->anggota());

        $pdf = $this->service()->generateBilyetPdf($deposito->id);

        $this->assertInstanceOf(PDF::class, $pdf);

        $keluaran = $pdf->output();

        // Header berkas PDF yang sah — dapat dibuka Adobe Reader, Foxit,
        // maupun viewer bawaan Chrome/Firefox/Edge.
        $this->assertStringStartsWith('%PDF-', $keluaran,
            'Keluaran harus berupa berkas PDF yang valid (magic number %PDF-).');

        $this->assertStringContainsString('%%EOF', $keluaran);

        // Ukuran halaman A4 portrait (595.28 x 841.89 pt) pada MediaBox.
        $dompdf = $pdf->getDomPDF();
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $this->assertEqualsWithDelta(595.28, $canvas->get_width(), 1.5, 'Lebar halaman harus A4 portrait.');
        $this->assertEqualsWithDelta(841.89, $canvas->get_height(), 1.5, 'Tinggi halaman harus A4 portrait.');
    }

    public function test_pdf_bilyet_memuat_seluruh_data_anggota_dan_rincian_deposito(): void
    {
        $anggota = $this->anggota();
        $anggota->forceFill([
            'nik' => '3276010101990001',
            'alamat' => 'Jl. Melati Indah No. 45',
            'kelurahan' => 'Sukamaju',
            'kecamatan' => 'Cilandak',
            'kota' => 'Jakarta Selatan',
            'provinsi' => 'DKI Jakarta',
            'kode_pos' => '12430',
        ])->save();

        $deposito = $this->depositoAktif($anggota, 2500000, 3, 60.0);

        // Isi dokumen diperiksa dari HTML view yang dirender dengan data yang sama
        // seperti yang dipakai generateBilyetPdf(): Dompdf::outputHtml() hanya
        // mengembalikan blok <head>, sehingga tidak dapat dipakai untuk verifikasi isi.
        $pdf = $this->service()->generateBilyetPdf($deposito->id);
        $this->assertStringStartsWith('%PDF-', $pdf->output(), 'Bilyet harus dihasilkan sebagai berkas PDF valid.');

        $html = $this->htmlBilyet($deposito);

        // Nomor bilyet.
        $this->assertStringContainsString($deposito->deposito_number, $html,
            'Nomor bilyet (deposito_number) wajib tercetak.');

        // Data anggota.
        $this->assertStringContainsString($anggota->nama_lengkap, $html, 'Nama lengkap anggota wajib tercetak.');
        $this->assertStringContainsString('3276010101990001', $html, 'NIK anggota wajib tercetak.');
        $this->assertStringContainsString('Jl. Melati Indah No. 45', $html, 'Alamat anggota wajib tercetak.');
        $this->assertStringContainsString('Sukamaju', $html, 'Kelurahan wajib ikut pada alamat lengkap.');

        // Nomor CIF / Nomor Anggota.
        $this->assertStringContainsString((string) $anggota->no_cif, $html, 'Nomor CIF wajib tercetak.');

        // Rincian deposito.
        $this->assertStringContainsString('2.500.000', $html, 'Nominal modal wajib terformat rupiah.');
        $this->assertStringContainsString('3 Bulan', $html, 'Tenor wajib tercetak dalam satuan bulan.');
        $this->assertStringContainsString('Anggota 60%', $html, 'Nisbah anggota wajib tercetak.');
        $this->assertStringContainsString('Koperasi 40%', $html, 'Nisbah koperasi wajib tercetak.');

        // Tanggal akad & jatuh tempo dalam format dd MMMM yyyy (Bahasa Indonesia),
        // diambil dari kolom start_date & maturity_date (bukan hasil render ulang).
        $this->assertStringContainsString(
            $deposito->start_date->translatedFormat('d F Y'),
            $html,
            'Tanggal akad harus mengikuti kolom start_date.'
        );
        $this->assertStringContainsString(
            $deposito->maturity_date->translatedFormat('d F Y'),
            $html,
            'Tanggal jatuh tempo harus mengikuti kolom maturity_date.'
        );

        // Judul dokumen & ketentuan akad.
        $this->assertStringContainsString('BILYET SIMPANAN BERJANGKA MUDHARABAH', $html);
        $this->assertStringContainsString('MUDHARABAH MUTHLAQAH', $html);
        $this->assertStringContainsString('Pengurus Koperasi', $html, 'Kolom tanda tangan pengurus wajib ada.');
        $this->assertStringContainsString('Teller', $html, 'Kolom tanda tangan teller wajib ada.');
    }

    public function test_pdf_bilyet_menerjemahkan_nominal_modal_ke_terbilang_bahasa_indonesia(): void
    {
        $deposito = $this->depositoAktif($this->anggota(), 2500000);

        $pdf = $this->service()->generateBilyetPdf($deposito->id);
        $this->assertStringStartsWith('%PDF-', $pdf->output(), 'Bilyet harus dihasilkan sebagai berkas PDF valid.');

        $html = $this->htmlBilyet($deposito);

        $this->assertStringContainsString(
            terbilang(2500000),
            $html,
            'Nominal modal wajib disertai tulisan terbilang dalam Bahasa Indonesia.'
        );
        $this->assertStringContainsString('Rupiah', $html, 'Terbilang wajib diikuti satuan Rupiah.');
    }

    public function test_pdf_bilyet_memakai_kop_koperasi_dari_konfigurasi(): void
    {
        $deposito = $this->depositoAktif($this->anggota());

        $pdf = $this->service()->generateBilyetPdf($deposito->id);
        $this->assertStringStartsWith('%PDF-', $pdf->output(), 'Bilyet harus dihasilkan sebagai berkas PDF valid.');

        $html = $this->htmlBilyet($deposito);

        $this->assertStringContainsString(config('pdf.koperasi.nama'), $html, 'Nama koperasi wajib pada kop.');
        $this->assertStringContainsString(config('pdf.koperasi.alamat'), $html, 'Alamat kantor pusat wajib pada kop.');
        $this->assertStringContainsString(config('pdf.koperasi.kota'), $html, 'Kota koperasi wajib pada kop.');
    }

    public function test_nama_berkas_bilyet_mengikuti_pola_deposito_number(): void
    {
        $deposito = $this->depositoAktif($this->anggota());

        $this->assertSame(
            'Bilyet-Deposito-'.$deposito->deposito_number.'.pdf',
            $this->service()->namaFileBilyet($deposito)
        );
    }

    public function test_generate_bilyet_untuk_deposito_tidak_dikenal_melempar_404(): void
    {
        $idTidakAda = (int) (Deposito::max('id') ?? 0) + 99999;

        $this->expectException(ModelNotFoundException::class);

        $this->service()->generateBilyetPdf($idTidakAda);
    }

    public function test_route_cetak_bilyet_mengembalikan_stream_pdf_dengan_nama_berkas_benar(): void
    {
        $anggota = $this->anggota();
        $deposito = $this->depositoAktif($anggota);

        $respon = $this->actingAs($this->pengguna())
            ->get('/api/deposito/'.$deposito->id.'/print-bilyet');

        $respon->assertOk();

        $this->assertSame('application/pdf', $respon->headers->get('content-type'));

        // Isi respons benar-benar berkas PDF (magic number) — bukan HTML/error,
        // sehingga berkas dapat langsung diunduh & dicetak tanpa error.
        $this->assertStringStartsWith('%PDF-', $respon->getContent(),
            'Endpoint cetak bilyet harus mengirim berkas PDF yang valid.');

        $disposisi = (string) $respon->headers->get('content-disposition');
        $this->assertStringContainsString('inline', $disposisi, 'PDF ditampilkan langsung di browser.');
        $this->assertStringContainsString(
            'Bilyet-Deposito-'.$deposito->deposito_number.'.pdf',
            $disposisi,
            'Nama berkas harus mengikuti pola Bilyet-Deposito-{deposito_number}.pdf.'
        );
    }

    public function test_route_cetak_bilyet_menolak_deposito_belum_terverifikasi(): void
    {
        $anggota = $this->anggota();
        $deposito = $this->depositoAktif($anggota);

        $deposito->forceFill(['status' => Deposito::STATUS_PENDING])->save();

        $respon = $this->actingAs($this->pengguna())
            ->get('/api/deposito/'.$deposito->id.'/print-bilyet');

        $respon->assertStatus(409)->assertJsonPath('success', false);
        $this->assertStringContainsString('belum dapat dicetak', (string) $respon->json('message'));
    }

    public function test_route_cetak_bilyet_menolak_deposito_yang_tidak_ada_dengan_404(): void
    {
        $idTidakAda = (int) (Deposito::max('id') ?? 0) + 99999;

        $this->actingAs($this->pengguna())
            ->get('/api/deposito/'.$idTidakAda.'/print-bilyet')
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_bilyet_tidak_bocor_ke_peran_tanpa_akses_modul_deposito(): void
    {
        $deposito = $this->depositoAktif($this->anggota());

        // DPS adalah pengawas syariah: bukan pemilik, bukan pengurus/teller.
        $this->actingAs($this->penggunaBerperan('dps'))
            ->get('/api/deposito/'.$deposito->id.'/print-bilyet')
            ->assertStatus(403);
    }

    public function test_bilyet_tidak_dapat_diakses_anggota_lain_dengan_manipulasi_id_url(): void
    {
        $pemilik = $this->anggota();
        $deposito = $this->depositoAktif($pemilik);

        // Anggota penyusup: profil terpisah dari pemilik, punya akun sendiri,
        // tetapi deposito tersebut bukan miliknya.
        $penyusup = Anggota::create([
            'no_anggota' => $this->prefix.'INTRUDER',
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Penyusup Uji',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Alamat Penyusup Uji',
            'kota' => 'Jakarta',
            'provinsi' => 'DKI Jakarta',
            'status_anggota' => 'Aktif',
            'tanggal_gabung' => now()->toDateString(),
        ]);

        $akunPenyusup = $this->penggunaBerperan('ao', 'penyusup.bilyet@test.local');
        $penyusup->forceFill([
            'user_id' => $akunPenyusup->id,
            'email' => 'penyusup.bilyet@test.local',
        ])->save();

        // Menebak/memanipulasi {id} pada URL tidak membuka akses bilyet milik orang lain.
        $this->actingAs($akunPenyusup)
            ->get('/api/deposito/'.$deposito->id.'/print-bilyet')
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        // Pemilik sah tetap dapat mencetak bilyetnya sendiri.
        $akunPemilik = $this->penggunaBerperan('ao', 'pemilik.bilyet@test.local');
        $pemilik->forceFill([
            'user_id' => $akunPemilik->id,
            'email' => 'pemilik.bilyet@test.local',
        ])->save();

        $this->actingAs($akunPemilik)
            ->get('/api/deposito/'.$deposito->id.'/print-bilyet')
            ->assertOk();
    }

    public function test_bilyet_pdf_selesai_dibawah_tiga_detik(): void
    {
        $deposito = $this->depositoAktif($this->anggota());

        $mulai = microtime(true);

        $respon = $this->actingAs($this->pengguna())
            ->get('/api/deposito/'.$deposito->id.'/print-bilyet');

        $durasi = microtime(true) - $mulai;

        $respon->assertOk();

        $this->assertLessThan(3.0, $durasi, 'Generate bilyet PDF harus selesai di bawah 3 detik.');
    }

    /* =====================================================================
     | #13 — TOMBOL 'CETAK BILYET PDF' PADA PORTOFOLIO CIF
     |===================================================================== */

    public function test_portofolio_cif_menampilkan_tombol_cetak_bilyet_pdf(): void
    {
        $anggota = $this->anggota();
        $deposito = $this->depositoAktif($anggota);

        $html = $this->actingAs($this->penggunaAo())
            ->get('/ao/cif/'.$anggota->id)
            ->assertOk()
            ->getContent();

        // Judul tabel Simpanan Berjangka (bilyet deposito).
        $this->assertStringContainsString('Simpanan Berjangka (Deposito)', $html);
        $this->assertStringContainsString($deposito->deposito_number, $html,
            'Nomor bilyet wajib tampil pada tabel portofolio CIF.');

        // Tombol aksi ada dan membawa id deposito yang benar untuk endpoint API.
        $this->assertStringContainsString('btn-bilyet', $html, 'Tombol Cetak Bilyet PDF wajib dirender.');
        $this->assertStringContainsString('data-id="'.$deposito->id.'"', $html);
        $this->assertStringContainsString('/api/deposito', $html,
            'Script harus memanggil endpoint /api/deposito/{id}/print-bilyet.');

        // Deposito ACTIVE => tombol tidak boleh dinonaktifkan.
        $this->assertStringNotContainsString('disabled', $this->lintasanTombolBilyet($html),
            'Deposito terverifikasi (ACTIVE) harus dapat dicetak bilyetnya.');
    }

    public function test_tombol_cetak_bilyet_nonaktif_untuk_deposito_belum_terverifikasi(): void
    {
        $anggota = $this->anggota();
        $deposito = $this->depositoAktif($anggota);

        $deposito->forceFill(['status' => Deposito::STATUS_PENDING])->save();

        $html = $this->actingAs($this->penggunaAo())
            ->get('/ao/cif/'.$anggota->id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('disabled', $this->lintasanTombolBilyet($html),
            'Tombol Cetak Bilyet PDF wajib nonaktif selama deposito belum terverifikasi.');

        // Wadah pesan error frontend tetap tersedia agar kegagalan muat PDF terlihat.
        $this->assertStringContainsString('flash-bilyet', $html);
    }

    /**
     * Ambil potongan HTML tepat pada tombol .btn-bilyet pertama sehingga
     * pemeriksaan atribut `disabled` tidak tertipu atribut lain di halaman.
     */
    private function lintasanTombolBilyet(string $html, int $panjang = 900): string
    {
        $posisi = strpos($html, 'btn-bilyet');

        $this->assertNotFalse($posisi, 'Tombol .btn-bilyet tidak ditemukan pada halaman portofolio CIF.');

        return substr($html, max(0, $posisi - 400), $panjang);
    }

    /* =====================================================================
     | #14 — ARSITEKTUR DINAMIS COA & MASTER DATA (PSAK 101/108)
     |===================================================================== */

    public function test_skema_parameter_persentase_dinamis_tersedia_di_tabel_coas(): void
    {
        foreach ([
            'has_percentage',
            'percentage_type',
            'default_percentage_member',
            'default_percentage_institution',
        ] as $kolom) {
            $this->assertTrue(
                \Schema::hasColumn('coas', $kolom),
                "Kolom coas.{$kolom} belum tersedia."
            );
        }
    }

    public function test_skema_aturan_penalti_tersedia_di_tabel_deposito_products(): void
    {
        foreach ([
            'allow_early_withdrawal',
            'penalty_fee_type',
            'penalty_fee_amount',
            'forfeit_monthly_profit',
            'penalty_coa_id',
        ] as $kolom) {
            $this->assertTrue(
                \Schema::hasColumn('deposito_products', $kolom),
                "Kolom deposito_products.{$kolom} belum tersedia."
            );
        }
    }

    public function test_skema_kolom_snapshot_persentase_tersedia_di_tabel_transaksi(): void
    {
        foreach (['rekening', 'depositos', 'pembiayaan'] as $tabel) {
            foreach (['applied_percentage_member', 'applied_percentage_institution'] as $kolom) {
                $this->assertTrue(
                    \Schema::hasColumn($tabel, $kolom),
                    "Kolom {$tabel}.{$kolom} belum tersedia untuk snapshot akad."
                );
            }
        }
    }

    /**
     * Master COA dikonfigurasi sesuai PSAK 101/108: Sukarela & Deposito
     * memakai nisbah, Piutang Murabahah memakai margin, sedangkan Simpanan
     * Pokok/Wajib ber-tipe 'shu' TANPA persentase bulanan.
     */
    public function test_master_coa_membawa_parameter_persentase_dinamis(): void
    {
        $sukarela = Coa::findByKode(Deposito::COA_SIMPANAN_SUKARELA);
        $this->assertNotNull($sukarela);
        $this->assertTrue($sukarela->has_percentage);
        $this->assertSame(Coa::PERCENTAGE_NISBAH, $sukarela->percentage_type);

        $deposito = Coa::findByKode(Deposito::COA_SIMPANAN_BERJANGKA);
        $this->assertNotNull($deposito);
        $this->assertSame(Coa::PERCENTAGE_NISBAH, $deposito->percentage_type);

        $murabahah = Coa::findByKode(AutoJournalingService::PIUTANG_MURABAHAH);
        $this->assertNotNull($murabahah);
        $this->assertTrue($murabahah->has_percentage);
        $this->assertSame(Coa::PERCENTAGE_MARGIN, $murabahah->percentage_type);

        foreach ([AutoJournalingService::SIMPANAN_POKOK, '3-1002'] as $kode) {
            $coa = Coa::findByKode($kode);
            $this->assertNotNull($coa, "CoA {$kode} tidak ditemukan.");
            $this->assertFalse($coa->has_percentage, "CoA {$kode} tidak boleh menerapkan nisbah bulanan.");
            $this->assertSame(Coa::PERCENTAGE_SHU, $coa->percentage_type,
                "CoA {$kode} harus ber-tipe 'shu' (SHU Tahunan pada Ekuitas).");
        }
    }

    public function test_snapshot_persentase_coa_dinonaktifkan_bila_has_percentage_false(): void
    {
        $coa = Coa::findByKode(AutoJournalingService::SIMPANAN_POKOK);
        $this->assertNotNull($coa);

        $this->assertSame(
            ['applied_percentage_member' => null, 'applied_percentage_institution' => null],
            $coa->snapshotPersentase(),
            'Akun tanpa persentase tidak boleh menitipkan nilai "hantu" ke akad.'
        );
    }

    public function test_perubahan_master_persentase_coa_tidak_mengubah_snapshot_bilyet_lama(): void
    {
        $anggota = $this->anggota();
        $produk = $this->produk(3, 60, 100000);
        $sumber = $this->rekeningSukarela($anggota);

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 1000000,
            'user_id' => $this->pengguna()->id,
        ]);

        $tersimpan = [(float) $deposito->applied_percentage_member, (float) $deposito->applied_percentage_institution];

        // Pengurus mengubah nisbah pada Master COA setelah akad tercatat.
        $coa = Coa::findByKode(Deposito::COA_SIMPANAN_BERJANGKA);
        $coa->forceFill(['default_percentage_member' => 12.34])->save();

        $deposito->refresh();

        $this->assertSame(
            $tersimpan,
            [(float) $deposito->applied_percentage_member, (float) $deposito->applied_percentage_institution],
            'Perubahan Master COA tidak boleh mengubah snapshot akad lama (anti korupsi data historis).'
        );
    }

    public function test_api_coa_config_mengirim_parameter_persentase_dan_produk_deposito(): void
    {
        $respon = $this->actingAs($this->pengguna())
            ->getJson('/api/deposito/coa-config')
            ->assertOk();

        $data = $respon->json('data');
        $this->assertIsArray($data);
        $this->assertArrayHasKey('coa', $data);
        $this->assertArrayHasKey('products', $data);

        foreach (['sukarela', 'deposito', 'margin'] as $kunci) {
            $this->assertArrayHasKey($kunci, $data['coa'], "Konfigurasi CoA '{$kunci}' tidak dikirim API.");
            foreach (['kode_akun', 'has_percentage', 'percentage_type',
                'default_percentage_member', 'default_percentage_institution'] as $field) {
                $this->assertArrayHasKey($field, $data['coa'][$kunci],
                    "Field '{$field}' hilang pada konfigurasi CoA '{$kunci}'.");
            }
        }
    }

    /**
     * Pencairan dini dengan penalti PERSENTASE dihitung dinamis dari
     * `penalty_fee_amount` produk: pokok x nilai / 100.
     */
    public function test_pencairan_dini_penalti_persentase_dihitung_dinamis(): void
    {
        $anggota = $this->anggota();
        $sumber = $this->rekeningSukarela($anggota, 10000000);
        $produk = $this->produk(3, 60, 100000);

        $produk->forceFill([
            'allow_early_withdrawal' => true,
            'penalty_fee_type' => DepositoProduct::PENALTI_PERCENTAGE,
            'penalty_fee_amount' => 1.00,
            'forfeit_monthly_profit' => true,
            'penalty_coa_id' => DepositoProduct::PENALTI_COA_DEFAULT,
        ])->save();

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 2000000,
            'user_id' => $this->pengguna()->id,
        ]);

        $deposito->forceFill(['status' => Deposito::STATUS_ACTIVE])->save();

        $hasil = $this->service()->earlyWithdrawal($deposito->id, $this->pengguna()->id);

        $this->assertSame(20000.0, round((float) $hasil['penalti'], 2),
            'Penalti persentase 1% x Rp 2.000.000 harus Rp 20.000.');
        $this->assertSame(1980000.0, round((float) $hasil['diterima_anggota'], 2),
            'Sisa bersih yang dikembalikan = pokok - penalti.');
    }

    /**
     * R7 — Ta'zir penalti pencairan dini WAJIB dikreditkan ke akun Dana Kebajikan
     * dan TIDAK BOLEH masuk akun pendapatan mana pun (Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000:
     * denda ta'zir bukan pendapatan koperasi).
     *
     * Test ini mengunci routing akun — bukan sekadar memakai konstanta
     * `PENALTI_COA_DEFAULT` yang akan tetap lulus walau nilainya diubah.
     */
    public function test_penalti_pencairan_dini_masuk_dana_kebajikan_bukan_pendapatan(): void
    {
        $anggota = $this->anggota();
        $sumber = $this->rekeningSukarela($anggota, 10000000);
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
            'user_id' => $this->pengguna()->id,
        ]);

        $deposito->forceFill(['status' => Deposito::STATUS_ACTIVE])->save();

        $hasil = $this->service()->earlyWithdrawal($deposito->id, $this->pengguna()->id);

        $this->assertNotNull($hasil['jurnal'], 'Pencairan dini wajib menghasilkan jurnal.');

        $jurnal = Jurnal::with('detail')->find($hasil['jurnal']['id']);
        $kodePerCoa = Coa::pluck('kode_akun', 'id');

        // Penalti harus TERKUNCI ke akun Dana Kebajikan (non-pendapatan).
        $kreditKebajikan = $jurnal->detail->filter(
            fn ($d) => ($kodePerCoa[$d->coa_id] ?? null) === \App\Support\Coa::DANA_KEBAJIKAN
                && (float) $d->kredit > 0
        );

        $this->assertCount(
            1,
            $kreditKebajikan,
            'Penalti ta\'zir wajib dikreditkan TEPAT SATU KALI ke akun Dana Kebajikan (2-3001).'
        );

        $this->assertSame(
            50000.0,
            round((float) $kreditKebajikan->first()->kredit, 2),
            'Nilai kredit Dana Kebajikan harus sama dengan nominal penalti.'
        );

        // Tidak boleh ada satu pun baris kredit yang mengarah ke akun Pendapatan (kepala 4-).
        foreach ($jurnal->detail as $d) {
            if ((float) $d->kredit > 0) {
                $kode = (string) ($kodePerCoa[$d->coa_id] ?? '');
                $this->assertFalse(
                    str_starts_with($kode, '4-'),
                    "Ta'zir DILARANG masuk akun pendapatan, ditemukan kredit ke {$kode}."
                );
            }
        }
    }

    /** Pencairan dini dengan penalti FIXED memakai nominal `penalty_fee_amount`. */
    public function test_pencairan_dini_penalti_fixed_memakai_nominal_produk(): void
    {
        $anggota = $this->anggota();
        $sumber = $this->rekeningSukarela($anggota, 10000000);
        $produk = $this->produk(6, 60, 100000);

        $produk->forceFill([
            'allow_early_withdrawal' => true,
            'penalty_fee_type' => DepositoProduct::PENALTI_FIXED,
            'penalty_fee_amount' => 75000,
            'forfeit_monthly_profit' => true,
            'penalty_coa_id' => DepositoProduct::PENALTI_COA_DEFAULT,
        ])->save();

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 1500000,
            'user_id' => $this->pengguna()->id,
        ]);

        $deposito->forceFill(['status' => Deposito::STATUS_ACTIVE])->save();

        $hasil = $this->service()->earlyWithdrawal($deposito->id, $this->pengguna()->id);

        $this->assertSame(75000.0, round((float) $hasil['penalti'], 2));
        $this->assertSame(1425000.0, round((float) $hasil['diterima_anggota'], 2));
    }

    /**
     * Auto-journal pelunasan dini PSAK 101/108 (R7 — Fatwa DSN-MUI 17/2000):
     *   DEBET 2-1003 (Total Pokok) | KREDIT 2-3001 (Penalti/Dana Kebajikan) | KREDIT 2-1001 (Pokok - Penalti)
     * dan status jurnal wajib POSTED. Ta'zir TIDAK BOLEH masuk akun pendapatan (4-xxxx).
     */
    public function test_auto_jurnal_pencairan_dini_sesuai_psak_dan_posted(): void
    {
        $anggota = $this->anggota();
        $sumber = $this->rekeningSukarela($anggota, 10000000);
        $produk = $this->produk(3, 60, 100000);

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
            'principal_amount' => 3000000,
            'user_id' => $this->pengguna()->id,
        ]);

        $deposito->forceFill(['status' => Deposito::STATUS_ACTIVE])->save();

        $hasil = $this->service()->earlyWithdrawal($deposito->id, $this->pengguna()->id);

        $jurnal = Jurnal::with('detail')->find($hasil['jurnal']['id']);
        $this->assertNotNull($jurnal, 'Jurnal pencairan dini wajib terbentuk.');
        $this->assertSame('Posted', $jurnal->status, 'Jurnal pencairan dini wajib berstatus POSTED.');
        $this->assertTrue((bool) $jurnal->is_balance);

        $coaId = fn (string $kode) => (int) Coa::findByKode($kode)->id;
        $baris = $jurnal->detail->keyBy('coa_id');

        // DEBET 2-1003 = total pokok yang dicairkan.
        $this->assertSame(3000000.0, round((float) $baris[$coaId(Deposito::COA_SIMPANAN_BERJANGKA)]->debet, 2));
        // KREDIT 4-2001 = biaya penalti.
        $this->assertSame(50000.0, round((float) $baris[$coaId(DepositoProduct::PENALTI_COA_DEFAULT)]->kredit, 2));
        // KREDIT 2-1001 = pokok - penalti.
        $this->assertSame(2950000.0, round((float) $baris[$coaId(Deposito::COA_SIMPANAN_SUKARELA)]->kredit, 2));
    }

    /** Bilyet tak boleh dicairkan dini bila produk melarangnya. */
    public function test_pencairan_dini_ditolak_bila_produk_melarang(): void
    {
        $anggota = $this->anggota();
        $sumber = $this->rekeningSukarela($anggota, 10000000);
        $produk = $this->produk(3, 60, 100000);

        $produk->forceFill(['allow_early_withdrawal' => false])->save();

        $deposito = $this->service()->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => 1000000,
            'user_id' => $this->pengguna()->id,
        ]);

        $deposito->forceFill(['status' => Deposito::STATUS_ACTIVE])->save();

        // Penolakan divalidasi sebagai ValidationException agar dapat
        // diterjemahkan controller API menjadi HTTP 422.
        try {
            $this->service()->earlyWithdrawal($deposito->id, $this->pengguna()->id);
            $this->fail('Pencairan dini seharusnya ditolak karena produk melarangnya.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('tidak mengizinkan pencairan', $e->getMessage());
        }

        $deposito->refresh();
        $this->assertSame(Deposito::STATUS_ACTIVE, $deposito->status,
            'Status bilyet tidak boleh berubah saat pencairan dini ditolak.');
    }

    /** Form Buka Rekening Simpanan memakai modal ber-scrollbar internal. */
    public function test_modal_buka_rekening_simpanan_memakai_scrollbar_internal(): void
    {
        $anggota = $this->anggota();

        $html = $this->actingAs($this->penggunaAo())
            ->get('/ao/cif/'.$anggota->id)
            ->assertOk()
            ->getContent();

        // Kartu modal dibatasi 90vh, disusun flex-column, dan dibagi menjadi
        // Header (sticky atas) / Body (scroll) / Footer (sticky bawah).
        $this->assertStringContainsString('max-h-[90vh] flex flex-col rounded-xl bg-white shadow-xl', $html,
            'Kartu modal wajib memakai class max-h-[90vh] flex flex-col rounded-xl bg-white shadow-xl.');
        $this->assertStringContainsString('overflow-y-auto p-6 space-y-4 max-h-[calc(90vh-130px)]', $html,
            'Kontainer body modal wajib memakai class overflow-y-auto p-6 space-y-4 '
            .'max-h-[calc(90vh-130px)].');

        // Tanpa Tailwind (halaman CIF), inline style-lah yang benar-benar bekerja.
        $this->assertStringContainsString('max-height:90vh', $html);
        $this->assertStringContainsString('max-height:calc(90vh - 130px);overflow-y:auto', $html);
        $this->assertStringContainsString('position:sticky;top:0', $html,
            'Header modal harus sticky di atas agar judul & tombol close selalu terlihat.');
        $this->assertStringContainsString('position:sticky;bottom:0', $html,
            'Footer modal harus sticky di bawah agar tombol Batal & Buka Rekening selalu terlihat.');

        // Header berada sebelum body, footer setelah body (urutan tiga bagian).
        $posHeader = strpos($html, 'position:sticky;top:0');
        $posBody = strpos($html, 'max-height:calc(90vh - 130px)');
        $posFooter = strpos($html, 'position:sticky;bottom:0');

        $this->assertTrue($posHeader < $posBody && $posBody < $posFooter,
            'Urutan modal harus Header (sticky atas) → Body (scroll) → Footer (sticky bawah).');
    }

    /**
     * Field "Tenor (Bulan)" read-only yang nilainya mengikuti produk deposito.
     * Tenor sengaja TIDAK dikirim ke server (tanpa atribut `name`) karena
     * backend mengambilnya dari master produk.
     */
    public function test_modal_buka_rekening_memiliki_field_tenor_read_only(): void
    {
        $anggota = $this->anggota();
        $this->produk(6, 60, 250000);

        $html = $this->actingAs($this->penggunaAo())
            ->get('/ao/cif/'.$anggota->id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="wrap-tenor"', $html,
            'Field Tenor harus punya wrapper `wrap-tenor` untuk show/hide dinamis.');
        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="inp-tenor"[^>]*readonly/',
            $html,
            'Field Tenor harus read-only karena ditentukan produk deposito.'
        );
        $this->assertMatchesRegularExpression(
            '/<input(?![^>]*name=)[^>]*id="inp-tenor"/',
            $html,
            'Field Tenor tidak boleh punya atribut `name` — nilainya tidak dikirim ke server.'
        );

        // Nilai tenor diisi dari metadata produk oleh `terapkanProduk`.
        $this->assertStringContainsString("inpTenor.value = angkaAman(produk.tenor_months, 0) + ' bulan'", $html,
            'Tenor harus diisi otomatis dari produk deposito terpilih.');
    }

    /**
     * Visibilitas dinamis per jenis rekening: Tenor, Tanggal Jatuh Tempo,
     * Mode ARO, dan Box Informasi Jurnal Deposito hanya tampil untuk
     * Simpanan Berjangka/Deposito, dengan transisi smooth.
     */
    public function test_modal_buka_rekening_menyembunyikan_field_khusus_deposito_untuk_simpanan_biasa(): void
    {
        $anggota = $this->anggota();

        $html = $this->actingAs($this->penggunaAo())
            ->get('/ao/cif/'.$anggota->id)
            ->assertOk()
            ->getContent();

        // Mesin transisi smooth (fade + slide) yang menghormati reduce-motion.
        $this->assertStringContainsString('function terapkanVisibilitas(blok, tampil)', $html,
            'Harus ada helper transisi smooth untuk show/hide field.');
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $html,
            'Transisi wajib menghormati preferensi reduce-motion.');

        // Untuk non-deposito: Tenor, Jatuh Tempo, ARO, dan jurnal deposito
        // disembunyikan (smooth) sekaligus dinonaktifkan & dikosongkan.
        $this->assertStringContainsString("setField('inp-tenor', false, false, true)", $html,
            'Tenor harus disembunyikan & dikosongkan untuk simpanan non-deposito.');
        $this->assertStringContainsString("terapkanVisibilitas(\$('wrap-aro'), false)", $html,
            'Mode ARO harus disembunyikan untuk simpanan non-deposito.');
        $this->assertStringContainsString("terapkanVisibilitas(\$('info-jurnal-deposito'), false)", $html,
            'Box Informasi Jurnal Deposito harus disembunyikan untuk simpanan non-deposito.');

        // Untuk deposito: seluruh field khusus deposito ditampilkan kembali.
        $this->assertStringContainsString("terapkanVisibilitas(\$('wrap-aro'), true)", $html,
            'Mode ARO harus ditampilkan untuk Simpanan Berjangka/Deposito.');
        $this->assertStringContainsString("terapkanVisibilitas(\$('info-jurnal-deposito'), true)", $html,
            'Box Informasi Jurnal Deposito harus ditampilkan untuk Simpanan Berjangka/Deposito.');
        $this->assertStringContainsString("terapkanVisibilitas(\$('wrap-produk-deposito'), true)", $html,
            'Dropdown Produk Deposito harus ditampilkan untuk Simpanan Berjangka/Deposito.');
    }

    /**
     * Jatuh tempo dihitung otomatis HANYA setelah Tanggal Akad diisi pengguna
     * DAN produk deposito dipilih; hasilnya read-only. Perhitungan mengikuti
     * kalender Masehi (Carbon::addMonthsNoOverflow) termasuk tahun kabisat.
     */
    public function test_modal_buka_rekening_menghitung_jatuh_tempo_secara_otomatis(): void
    {
        $anggota = $this->anggota();

        $html = $this->actingAs($this->penggunaAo())
            ->get('/ao/cif/'.$anggota->id)
            ->assertOk()
            ->getContent();

        // Tanpa fallback ke tanggal hari ini: fungsi mengembalikan null bila
        // Tanggal Akad belum diisi, sehingga perhitungan tidak berjalan sendiri.
        $this->assertMatchesRegularExpression(
            '/function tanggalAkad\(\) \{.*?if \(!cocok\) \{ return null; \}/s',
            $html,
            'Tanggal Akad belum diisi harus menghasilkan null (tanpa fallback hari ini).'
        );
        $this->assertStringNotContainsString('hariIniIso', $html,
            'Tidak boleh ada pengisian otomatis Tanggal Akad dengan tanggal hari ini.');

        // Guard: tenor <= 0 atau Tanggal Akad kosong → tidak menghitung.
        $this->assertMatchesRegularExpression(
            '/function hitungJatuhTempo\(tenorBulan\) \{\s*var tenor = angkaAman\(tenorBulan, 0\);\s*'
            .'if \(tenor <= 0\) \{ return null; \}\s*var mulai = tanggalAkad\(\);\s*'
            .'if \(!mulai\) \{ return null; \}/',
            $html,
            'Perhitungan jatuh tempo hanya boleh berjalan bila tenor > 0 DAN Tanggal Akad terisi.'
        );

        // Penanganan akhir bulan (addMonthsNoOverflow) memakai hari terakhir
        // bulan tujuan, sehingga 31 Jan + 1 bulan tidak meluap ke Maret.
        $this->assertStringContainsString('new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate()', $html,
            'Hari terakhir bulan tujuan harus dihitung agar tidak meluap ke bulan berikutnya.');
        $this->assertStringContainsString('target.setDate(Math.min(hari, hariTerakhir))', $html,
            'Tanggal hasil harus dibatasi pada hari terakhir bulan tujuan.');

        // Hasil perhitungan dikunci read-only agar tidak dapat diubah manual.
        $this->assertStringContainsString('inpJatuh.readOnly = true', $html,
            'Tanggal Jatuh Tempo hasil hitungan wajib read-only.');
        $this->assertStringContainsString("inpJatuh.value = jatuhTempo ? formatIso(jatuhTempo) : ''", $html,
            'Tanggal Jatuh Tempo dikosongkan bila perhitungan belum dapat dilakukan.');
    }

    /**
     * Tombol Pencairan Dini beserta data atribut penalti dirender pada
     * portofolio CIF — HANYA untuk teller (segregation of duties).
     * Pengujian lengkap matriks peran ada di DepositoCifRbacTest.
     */
    public function test_portofolio_cif_menampilkan_tombol_pencairan_dini_untuk_teller(): void
    {
        $anggota = $this->anggota();
        $deposito = $this->depositoAktif($anggota);

        $html = $this->actingAs($this->pengguna())
            ->get('/teller/cif/'.$anggota->id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('btn-cair-dini', $html, 'Tombol Pencairan Dini wajib dirender untuk teller.');
        $this->assertStringContainsString('early-withdrawal', $html, 'Script wajib memanggil endpoint early-withdrawal.');
        $this->assertStringContainsString('modal-cair-dini', $html, 'Modal konfirmasi pencairan dini wajib tersedia untuk teller.');
    }

    /** Endpoint API early-withdrawal sukses dan mengembalikan ringkasan penalti. */
    public function test_endpoint_early_withdrawal_mengembalikan_ringkasan_pencairan(): void
    {
        $anggota = $this->anggota();
        $sumber = $this->rekeningSukarela($anggota, 10000000);
        $produk = $this->produk(3, 60, 100000);

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
            'user_id' => $this->pengguna()->id,
        ]);

        $deposito->forceFill(['status' => Deposito::STATUS_ACTIVE])->save();

        $this->actingAs($this->pengguna())
            ->postJson('/api/deposito/'.$deposito->id.'/early-withdrawal')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.deposito.deposito_number', $deposito->deposito_number)
            ->assertJsonPath('data.penalti', 50000)
            ->assertJsonPath('data.diterima_anggota', 950000);
    }
}
