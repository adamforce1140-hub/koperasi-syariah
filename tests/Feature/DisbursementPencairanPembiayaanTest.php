<?php

namespace Tests\Feature;

use App\Actions\Teller\ProsesPencairanAction;
use App\Enums\DisbursementMethod;
use App\Models\Akun;
use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\Coa;
use App\Models\HariLibur;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\KasHarian;
use App\Models\KebijakanKoperasi;
use App\Models\Pembiayaan;
use App\Models\PengajuanPembiayaan;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\CoaResolver;
use App\Services\DisbursementService;
use App\Services\HariLiburService;
use App\Support\PemisahanTugas;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PENCAIRAN (DROPPING) PEMBIAYAAN — ZERO-HARDCODING & PSAK 108.
 *
 * Mengunci seluruh syarat modul pencairan:
 *   1. Pemetaan CoA dinamis (kebijakan_koperasis → fallback registri Coa),
 *      tidak ada nomor akun yang ditanam di Service.
 *   2. Skema CASH via Laci Teller: hak akses teller, sesi OPEN, anti-deficit,
 *      buku pembantu (transaksi) + jurnal 3 baris.
 *   3. Skema TRANSFER_BANK: rekening operasional wajib & saldo buku besar cukup.
 *   4. Skema TRANSFER_SAVINGS: rekening sukarela anggota aktif, saldo bertambah,
 *      dan TIDAK ada kas fisik keluar dari laci.
 *   5. Penjadwalan: first_due_date & jadwal angsuran mengikuti setting +
 *      pergeseran hari libur (nasional/akhir pekan).
 *   6. Atomik: kegagalan di tengah proses me-rollback seluruh perubahan.
 *
 * Basis data dev memuat data nyata, sehingga setiap fixture memakai penanda
 * unik dan assertion berbasis DELTA agar deterministik.
 */
class DisbursementPencairanPembiayaanTest extends TestCase
{
    use DatabaseTransactions;

    /** Nomor akun legacy (tabel `akun`) untuk rekening simpanan uji. */
    private ?int $akunLegacyId = null;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['tables' => ['pembiayaan', 'rekening', 'kas_harian', 'hari_liburs', 'kebijakan_koperasis']] as $tabel) {
            foreach ($tabel as $nama) {
                if (! Schema::hasTable($nama)) {
                    $this->markTestSkipped("Tabel {$nama} belum tersedia.");
                }
            }
        }

        Setting::flush();
    }

    protected function tearDown(): void
    {
        Setting::flush();

        parent::tearDown();
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    private function pengguna(string $peran, string $suffix = ''): User
    {
        $suffix = $suffix ?: $peran;

        return User::firstOrCreate(
            ['email' => "disbursement.{$suffix}@test.local"],
            [
                'name' => 'Uji Disbursement '.ucfirst($peran),
                'password' => Hash::make('password'),
                'role' => $peran,
                'is_active' => true,
            ]
        );
    }

    private function anggota(): Anggota
    {
        return Anggota::create([
            'no_anggota' => 'DSB-'.strtoupper(substr(uniqid(), -6)),
            'nik' => 'NIK'.substr(md5(uniqid('', true)), 0, 12),
            'nama_lengkap' => 'Anggota Uji Disbursement',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji Pencairan No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'no_telepon' => '081200000001',
            'status_anggota' => 'Aktif',
            'tanggal_gabung' => now()->toDateString(),
        ]);
    }

    private function akunLegacyId(): int
    {
        if ($this->akunLegacyId === null) {
            $id = Akun::query()->value('id');

            if (! $id) {
                $this->markTestSkipped('Tabel akun (legacy) belum berisi data.');
            }

            $this->akunLegacyId = (int) $id;
        }

        return $this->akunLegacyId;
    }

    private function rekening(Anggota $anggota, string $jenis = 'Simpanan Sukarela', string $status = 'Aktif', float $saldo = 0.0): Rekening
    {
        return Rekening::create([
            'no_rekening' => 'DSB-'.strtoupper(substr(uniqid(), -8)),
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunLegacyId(),
            'jenis_rekening' => $jenis,
            'akad' => 'Wadiah',
            'saldo' => $saldo,
            'batas_minimum' => 0,
            'tanggal_buka' => now()->toDateString(),
            'status_rekening' => $status,
        ]);
    }

    /**
     * Pembiayaan siap dicairkan (status disetujui).
     * Pokok 10.000.000 + margin 1.000.000 (Murabahah) → total 11.000.000.
     */
    private function pembiayaan(
        Anggota $anggota,
        string $akad = Pembiayaan::AKAD_MURABAHAH,
        float $pokok = 10000000.0,
        float $margin = 1000000.0,
        int $tenor = 12,
        string $status = 'disetujui'
    ): Pembiayaan {
        return Pembiayaan::create([
            'no_pembiayaan' => 'DSB-'.strtoupper(substr(uniqid(), -8)),
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunLegacyId(),
            'akad_pembiayaan' => $akad,
            'tujuan_pembiayaan' => 'Modal Usaha',
            'jumlah_pembiayaan' => $pokok,
            'margin_nisbah' => $margin,
            'total_ujroh' => $margin,
            'total_pembiayaan' => $pokok + $margin,
            'tenor' => $tenor,
            'frekuensi_angsuran' => 'Bulanan',
            'jumlah_angsuran' => round(($pokok + $margin) / $tenor, 2),
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonth()->toDateString(),
            'status_pembiayaan' => $status,
            'sisa_pembiayaan' => $pokok + $margin,
        ]);
    }

    /** Sesi Laci Teller (kas_harian) hari ini dengan status OPEN. */
    private function sesiLaci(User $teller, float $modalAwal = 0.0): KasHarian
    {
        KasHarian::where('user_id', $teller->id)->where('tanggal', now()->toDateString())->delete();

        return KasHarian::create([
            'user_id' => $teller->id,
            'tanggal' => now()->toDateString(),
            'modal_awal' => $modalAwal,
            'tambah_modal' => [],
            'waktu_opening' => now()->format('H:i:s'),
            'status_opname' => KasHarian::STATUS_OPEN,
        ]);
    }

    /**
     * Isi laci teller dengan setoran tunai fiktif sehingga saldo sistemnya
     * mencapai nominal tertentu (dipakai untuk menguji guard anti-deficit).
     */
    private function isiLaci(User $teller, float $nominal): KasHarian
    {
        $sesi = $this->sesiLaci($teller, $nominal);

        return $sesi;
    }

    /** Beri saldo buku besar pada sebuah CoA lewat jurnal Posted. */
    private function danaiCoa(Coa $coa, float $nominal, User $user): void
    {
        $lawan = Coa::findByKode(\App\Support\Coa::KAS_BRANKAS) ?? $coa;

        $jurnal = Jurnal::create([
            'no_jurnal' => 'DSB-'.strtoupper(bin2hex(random_bytes(5))),
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'Uji Disbursement',
            'keterangan' => 'Pengisian saldo uji pencairan pembiayaan',
            'status' => 'Posted',
            'user_id' => $user->id,
        ]);

        JurnalDetail::create([
            'jurnal_id' => $jurnal->id, 'coa_id' => $coa->id,
            'baris' => 1, 'debet' => $nominal, 'kredit' => 0,
        ]);
        JurnalDetail::create([
            'jurnal_id' => $jurnal->id, 'coa_id' => $lawan->id,
            'baris' => 2, 'debet' => 0, 'kredit' => $nominal,
        ]);

        $jurnal->hitungBalance();
        $jurnal->save();
    }

    /** CoA bank operasional aktif generik (kategori Aset, nama memuat 'Bank'). */
    private function coaBank(): Coa
    {
        $coa = Coa::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('nama_akun', 'like', '%Bank%')
                    ->orWhere('nama_akun', 'like', '%Rekening%');
            })
            ->orderBy('kode_akun')
            ->first();

        if (! $coa) {
            $this->markTestSkipped('Tidak ada CoA bank operasional pada bagan akun.');
        }

        return $coa;
    }

    private function service(): DisbursementService
    {
        return app(DisbursementService::class);
    }

    /** Jurnal terakhir untuk sebuah pembiayaan. */
    private function jurnalPembiayaan(Pembiayaan $pembiayaan): ?Jurnal
    {
        return Jurnal::where('pembiayaan_id', $pembiayaan->id)
            ->where('sumber', 'Pencairan Pembiayaan')
            ->latest('id')
            ->first();
    }

    /**
     * Rincian jurnal dalam format ['KODE' => ['debet' => x, 'kredit' => y]].
     *
     * @return array<string, array{debet: float, kredit: float}>
     */
    private function rincianJurnal(Jurnal $jurnal): array
    {
        $hasil = [];

        foreach ($jurnal->detail()->with('coa')->get() as $baris) {
            $kode = $baris->coa?->kode_akun ?? $baris->coa_id;

            $hasil[$kode] = [
                'debet' => round((float) $baris->debet, 2),
                'kredit' => round((float) $baris->kredit, 2),
            ];
        }

        return $hasil;
    }

    private function jaduhkanLibur(string $tanggal, string $keterangan = 'Libur Uji'): HariLibur
    {
        return HariLibur::create([
            'tanggal' => $tanggal,
            'keterangan' => $keterangan,
            'jenis' => HariLibur::JENIS_NASIONAL,
            'is_active' => true,
        ]);
    }

    private function aturKebijakan(array $kolom): void
    {
        $kebijakan = KebijakanKoperasi::first() ?? KebijakanKoperasi::create([]);
        $kebijakan->forceFill($kolom)->save();
        Setting::flush();
    }

    /* =====================================================================
     | 1. ENUM & KONTRAK METODE
     |===================================================================== */

    public function test_enum_metode_pencairan_menyediakan_tiga_opsi_yang_sah(): void
    {
        $this->assertSame(
            ['CASH', 'TRANSFER_BANK', 'TRANSFER_SAVINGS'],
            DisbursementMethod::nilai()
        );

        $this->assertTrue(DisbursementMethod::CASH->mengeluarkanKasFisik());
        $this->assertFalse(DisbursementMethod::TRANSFER_BANK->mengeluarkanKasFisik());
        $this->assertFalse(DisbursementMethod::TRANSFER_SAVINGS->mengeluarkanKasFisik());

        $this->assertFalse(DisbursementMethod::CASH->memerlukanRekeningBank());
        $this->assertTrue(DisbursementMethod::TRANSFER_BANK->memerlukanRekeningBank());
        $this->assertTrue(DisbursementMethod::TRANSFER_SAVINGS->memerlukanRekeningSimpanan());
    }

    public function test_metode_liar_ditolak_oleh_enum(): void
    {
        $this->expectException(\ValueError::class);

        DisbursementMethod::from('TRANSFER_EWALLET');
    }

    /* =====================================================================
     | 2. RESOLVER COA DINAMIS (ZERO-HARDCODING)
     |===================================================================== */

    public function test_resolver_memakai_pemetaan_dinamis_dari_kebijakan_koperasi(): void
    {
        // Akun piutang khusus (bukan standar) → resolver WAJIB mengikutinya.
        $coa = Coa::query()->where('is_active', true)->orderByDesc('kode_akun')->first();

        if (! $coa) {
            $this->markTestSkipped('Bagan akun kosong.');
        }

        $this->aturKebijakan(['account_piutang_murabahah_id' => $coa->id]);

        $resolver = app(CoaResolver::class);

        $this->assertSame($coa->kode_akun, $resolver->kodePiutangByAkad('Murabahah'));
    }

    public function test_resolver_jatuh_ke_registri_standar_bila_setting_kosong(): void
    {
        $this->aturKebijakan([
            'account_piutang_murabahah_id' => null,
            'account_investasi_mudharabah_id' => null,
            'account_piutang_mudharabah_id' => null,
            'account_investasi_musyarakah_id' => null,
            'account_piutang_musyarakah_id' => null,
            'account_piutang_qardh_id' => null,
        ]);

        $resolver = app(CoaResolver::class);

        $this->assertSame(\App\Support\Coa::PIUTANG_MURABAHAH, $resolver->kodePiutangByAkad('Murabahah'));
        $this->assertSame(\App\Support\Coa::PIUTANG_MUDHARABAH, $resolver->kodePiutangByAkad('Mudharabah'));
        $this->assertSame(\App\Support\Coa::PIUTANG_MUSYARAKAH, $resolver->kodePiutangByAkad('Musyarakah'));
        $this->assertSame(\App\Support\Coa::PINJAMAN_QARDH, $resolver->kodePiutangByAkad('Qardh'));
    }

    public function test_resolver_mengabaikan_setting_yang_menunjuk_akun_nonaktif(): void
    {
        $coa = Coa::query()->where('is_active', true)->orderBy('id')->first();

        if (! $coa) {
            $this->markTestSkipped('Bagan akun kosong.');
        }

        $this->aturKebijakan(['account_piutang_murabahah_id' => $coa->id]);
        Coa::whereKey($coa->id)->update(['is_active' => false]);

        $resolver = app(CoaResolver::class);

        // Setting menunjuk akun nonaktif → WAJIB jatuh ke registri standar.
        $this->assertSame(\App\Support\Coa::PIUTANG_MURABAHAH, $resolver->kodePiutangByAkad('Murabahah'));

        Coa::whereKey($coa->id)->update(['is_active' => true]);
    }

    public function test_akun_sumber_dana_mengikuti_metode_pencairan(): void
    {
        $resolver = app(CoaResolver::class);

        $this->assertSame(\App\Support\Coa::KAS_TELLER, $resolver->kodeSumberDanaByMetode('CASH'));
        $this->assertSame(\App\Support\Coa::SIMPANAN_SUKARELA, $resolver->kodeSumberDanaByMetode('TRANSFER_SAVINGS'));

        $bank = $this->coaBank();
        $this->assertSame($bank->kode_akun, $resolver->kodeSumberDanaByMetode('TRANSFER_BANK', $bank->id));
    }

    /* =====================================================================
     | 3. SKEMA CASH — LACI TELLER
     |===================================================================== */

    public function test_cash_ditolak_bila_pemroses_bukan_teller(): void
    {
        $manajer = $this->pengguna('manajer');
        $anggota = $this->anggota();
        $pb = $this->pembiayaan($anggota);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Hanya Teller aktif yang berhak memproses pencairan tunai.');

        $this->service()->cairkan($pb, DisbursementMethod::CASH, $manajer->id);
    }

    public function test_cash_ditolak_bila_sesi_laci_belum_dibuka(): void
    {
        $teller = $this->pengguna('teller');
        $anggota = $this->anggota();
        $pb = $this->pembiayaan($anggota);

        KasHarian::where('user_id', $teller->id)->where('tanggal', now()->toDateString())->delete();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Laci Teller belum dibuka atau sudah ditutup');

        $this->service()->cairkan($pb, DisbursementMethod::CASH, $teller->id);
    }

    public function test_cash_ditolak_bila_saldo_laci_tidak_mencukupi(): void
    {
        $teller = $this->pengguna('teller');
        $anggota = $this->anggota();
        // Pokok 10 juta, laci hanya 1 juta.
        $pb = $this->pembiayaan($anggota, Pembiayaan::AKAD_MURABAHAH, 10000000.0, 1000000.0);

        $this->sesiLaci($teller, 1000000.0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Saldo Kas Teller Tidak Mencukupi untuk Dropping Tunai');

        $this->service()->cairkan($pb, DisbursementMethod::CASH, $teller->id);
    }

    public function test_cash_sukses_membukukan_laci_buku_pembantu_dan_jurnal_psak108(): void
    {
        $teller = $this->pengguna('teller');
        $anggota = $this->anggota();
        $pb = $this->pembiayaan($anggota, Pembiayaan::AKAD_MURABAHAH, 10000000.0, 1000000.0);

        $sesi = $this->sesiLaci($teller, 15000000.0);

        $hasil = $this->service()->cairkan($pb, DisbursementMethod::CASH, $teller->id);

        // ── Status & jejak pencairan ────────────────────────────────────
        $this->assertSame('dicairkan', $hasil->status_pembiayaan);
        $this->assertInstanceOf(DisbursementMethod::class, $hasil->disbursement_method);
        $this->assertSame(DisbursementMethod::CASH, $hasil->disbursement_method);
        $this->assertNotNull($hasil->tgl_dicairkan);
        $this->assertNotNull($hasil->tanggal_cair);
        $this->assertTrue((bool) $hasil->is_disbursed, 'Penanda anti double-pencairan wajib aktif.');
        $this->assertNotNull($hasil->tgl_pencairan, 'Tanggal pencairan wajib mengisi rencana DPS sekaligus jejak cair.');

        // ── Buku pembantu kas teller ────────────────────────────────────
        $transaksi = Transaksi::where('pembiayaan_id', $pb->id)
            ->where('jenis_transaksi', 'Pencairan Pembiayaan')
            ->first();

        $this->assertNotNull($transaksi, 'Transaksi kas teller (CASH_OUT) wajib tercatat.');
        $this->assertSame('Debet', $transaksi->posisi);
        $this->assertSame('Teller', $transaksi->sumber_transaksi);
        $this->assertSame('Sukses', $transaksi->status_transaksi);
        $this->assertSame($anggota->id, (int) $transaksi->anggota_id);
        $this->assertEqualsWithDelta(10000000.0, (float) $transaksi->jumlah, 0.01);
        // Kolom referensi berisi kode akun sumber hasil resolver (dinamis).
        $this->assertSame(\App\Support\Coa::KAS_TELLER, $transaksi->referensi);

        // ── Saldo laci berkurang atomik ─────────────────────────────────
        $this->assertEqualsWithDelta(
            5000000.0,
            (float) $sesi->fresh()->saldo_sistem,
            0.01,
            'Saldo Laci Teller wajib berkurang sebesar nominal pokok.'
        );

        // ── Jadwal angsuran terbentuk sejumlah tenor ────────────────────
        $jadwal = Angsuran::where('pembiayaan_id', $pb->id)->orderBy('angsuran_ke')->get();
        $this->assertCount(12, $jadwal);
        $this->assertSame('Belum Jatuh Tempo', $jadwal->first()->status_angsuran);
        $this->assertSame(1, (int) $jadwal->first()->angsuran_ke);

        // ── Jurnal PSAK 108: 3 baris, status Posted ─────────────────────
        $jurnal = $this->jurnalPembiayaan($hasil);

        $this->assertNotNull($jurnal, 'Jurnal pencairan wajib terbentuk.');
        $this->assertSame('Posted', $jurnal->status);
        $this->assertTrue((bool) $jurnal->is_balance);
        $this->assertEqualsWithDelta(11000000.0, (float) $jurnal->total_debet, 0.01);

        $rincian = $this->rincianJurnal($jurnal);

        // DEBET Piutang Murabahah = Pokok + Margin
        $this->assertArrayHasKey(\App\Support\Coa::PIUTANG_MURABAHAH, $rincian);
        $this->assertEqualsWithDelta(11000000.0, $rincian[\App\Support\Coa::PIUTANG_MURABAHAH]['debet'], 0.01);

        // KREDIT Kas Teller = Pokok saja
        $this->assertEqualsWithDelta(10000000.0, $rincian[\App\Support\Coa::KAS_TELLER]['kredit'], 0.01);

        // KREDIT Margin Murabahah Ditangguhkan = Margin
        $akunMargin = \App\Support\Coa::MARGIN_DITANGGUHKAN;
        $this->assertArrayHasKey($akunMargin, $rincian);
        $this->assertEqualsWithDelta(1000000.0, $rincian[$akunMargin]['kredit'], 0.01);

        $this->assertCount(3, $rincian, 'Jurnal pencairan Murabahah wajib 3 baris.');
    }

    public function test_cash_menolak_pembiayaan_yang_belum_disetujui(): void
    {
        $teller = $this->pengguna('teller');
        $anggota = $this->anggota();
        $pb = $this->pembiayaan($anggota, Pembiayaan::AKAD_MURABAHAH, 10000000.0, 1000000.0, 12, 'pending_manajer');

        $this->sesiLaci($teller, 15000000.0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Pembiayaan ini tidak dalam status siap dicairkan.');

        $this->service()->cairkan($pb, DisbursementMethod::CASH, $teller->id);
    }

    public function test_pencairan_kedua_kali_ditolak_karena_sudah_pernah_dicairkan(): void
    {
        $teller = $this->pengguna('teller');
        $anggota = $this->anggota();
        $pb = $this->pembiayaan($anggota, Pembiayaan::AKAD_MURABAHAH, 5000000.0, 500000.0);

        $this->sesiLaci($teller, 20000000.0);

        $this->service()->cairkan($pb, DisbursementMethod::CASH, $teller->id);

        $jurnalSetelahCair = Jurnal::where('pembiayaan_id', $pb->id)
            ->where('sumber', 'Pencairan Pembiayaan')->count();
        $trxSetelahCair = Transaksi::where('pembiayaan_id', $pb->id)
            ->where('jenis_transaksi', 'Pencairan Pembiayaan')->count();

        // Percobaan kedua — status sudah 'dicairkan' & is_disbursed = true.
        try {
            $this->service()->cairkan($pb->fresh(), DisbursementMethod::CASH, $teller->id);
            $this->fail('Pencairan kedua seharusnya ditolak.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('sudah pernah dicairkan', $e->getMessage());
        }

        $this->assertSame(
            $jurnalSetelahCair,
            Jurnal::where('pembiayaan_id', $pb->id)->where('sumber', 'Pencairan Pembiayaan')->count(),
            'Jurnal pencairan tidak boleh bertambah pada percobaan kedua.'
        );
        $this->assertSame(
            $trxSetelahCair,
            Transaksi::where('pembiayaan_id', $pb->id)->where('jenis_transaksi', 'Pencairan Pembiayaan')->count(),
            'Kas tidak boleh keluar dua kali untuk satu akad.'
        );
    }

    public function test_proses_pencairan_aksi_menolak_akad_yang_sudah_dicairkan(): void
    {
        $teller = $this->pengguna('teller');
        $anggota = $this->anggota();

        // Akad yang statusnya MASIH 'dicairkan' tetapi sudah punya jejak cair —
        // inilah kondisi yang dulu membuka celah double disbursement.
        $pb = $this->pembiayaan($anggota, Pembiayaan::AKAD_MURABAHAH, 5000000.0, 500000.0);
        $pb->update([
            'status_pembiayaan' => Pembiayaan::STATUS_DICAIRKAN,
            'tgl_dicairkan' => now(),
            'is_disbursed' => true,
        ]);

        $this->actingAs($teller);

        $response = app(ProsesPencairanAction::class)
            ->execute(Request::create('/teller/pencairan/'.$pb->id, 'POST'), $pb->fresh());

        $this->assertSame(0, Transaksi::where('pembiayaan_id', $pb->id)
            ->where('jenis_transaksi', 'Pencairan Pembiayaan')->count());
        $this->assertTrue(session()->has('error'), 'Aksi wajib menolak dengan pesan error: '.$response->getTargetUrl());
    }

    /**
     * Regresi inkonsistensi status antar jalur pencairan:
     * DisbursementService menulis 'dicairkan' (sesuai peta transisi
     * config/alur_pembiayaan.php), sedangkan ProsesPencairanAction dulu
     * menulis 'aktif' sehingga melompati satu tahap state machine.
     */
    public function test_proses_pencairan_aksi_menetapkan_status_dicairkan(): void
    {
        $teller = $this->pengguna('teller', 'aksi-cair');
        $anggota = $this->anggota();
        $pb = $this->pembiayaan($anggota, Pembiayaan::AKAD_MURABAHAH, 5000000.0, 500000.0);

        $this->actingAs($teller);

        app(ProsesPencairanAction::class)
            ->execute(Request::create('/teller/pencairan/'.$pb->id, 'POST'), $pb->fresh());

        $pb->refresh();

        $this->assertSame(
            Pembiayaan::STATUS_DICAIRKAN,
            $pb->status_pembiayaan,
            'Jalur aksi teller harus menulis status yang sama dengan DisbursementService.'
        );
        $this->assertTrue((bool) $pb->is_disbursed);
        $this->assertNotNull($pb->tgl_dicairkan, 'Tanggal cair wajib diisi agar guard double-pencairan bekerja.');
    }

    /* =====================================================================
     | 3b. PEMISAHAN TUGAS JALUR TELLER (GCG — maker ≠ checker)
     |===================================================================== */

    public function test_teller_penyetuju_tidak_dapat_mencairkan_akadnya_sendiri(): void
    {
        $teller = $this->pengguna('teller', 'sod-teller-self');
        $anggota = $this->anggota();
        $pb = $this->pembiayaan($anggota, Pembiayaan::AKAD_MURABAHAH, 5000000.0, 500000.0);
        $pb->update(['disetujui_oleh' => $teller->id]);
        $this->sesiLaci($teller, 20000000.0);

        try {
            $this->service()->cairkan($pb->fresh(), DisbursementMethod::CASH, $teller->id);
            $this->fail('Penyetuju tidak boleh mencairkan akadnya sendiri.');
        } catch (\RuntimeException $e) {
            $this->assertSame(PemisahanTugas::PESAN, $e->getMessage());
        }

        $this->assertSame(Pembiayaan::STATUS_DISETUJUI, $pb->fresh()->status_pembiayaan);
        $this->assertFalse((bool) $pb->fresh()->is_disbursed);
    }

    public function test_teller_berbeda_dari_penyetuju_dapat_mencairkan(): void
    {
        $penyetuju = $this->pengguna('pengurus', 'sod-setuju-teller');
        $teller = $this->pengguna('teller', 'sod-teller-ok');
        $anggota = $this->anggota();
        $pb = $this->pembiayaan($anggota, Pembiayaan::AKAD_MURABAHAH, 5000000.0, 500000.0);
        $pb->update(['disetujui_oleh' => $penyetuju->id]);
        $this->sesiLaci($teller, 20000000.0);

        $this->service()->cairkan($pb->fresh(), DisbursementMethod::CASH, $teller->id);

        $this->assertSame(Pembiayaan::STATUS_DICAIRKAN, $pb->fresh()->status_pembiayaan);
        $this->assertTrue((bool) $pb->fresh()->is_disbursed);
    }

    public function test_teller_pengaju_pengajuan_tidak_dapat_mencairkan_sendiri(): void
    {
        $teller = $this->pengguna('teller', 'sod-teller-pengaju');
        $anggota = $this->anggota();
        $pb = $this->pembiayaan($anggota, Pembiayaan::AKAD_MURABAHAH, 5000000.0, 500000.0);
        PengajuanPembiayaan::create([
            'no_pengajuan' => 'PGJ-SODT-'.uniqid(),
            'anggota_id' => $anggota->id,
            'jenis_akad' => 'Murabahah',
            'jumlah_pengajuan' => 5000000,
            'total_pembiayaan' => 5500000,
            'tenor' => 12,
            'tujuan' => 'Uji pemisahan jalur teller',
            'status' => PengajuanPembiayaan::STATUS_DISETUJUI,
            'pembiayaan_id' => $pb->id,
            'ao_id' => $teller->id,
            'created_by' => $teller->id,
        ]);
        $this->sesiLaci($teller, 20000000.0);

        try {
            $this->service()->cairkan($pb->fresh(), DisbursementMethod::CASH, $teller->id);
            $this->fail('Pengaju tidak boleh mencairkan pengajuannya sendiri.');
        } catch (\RuntimeException $e) {
            $this->assertSame(PemisahanTugas::PESAN, $e->getMessage());
        }

        $this->assertSame(Pembiayaan::STATUS_DISETUJUI, $pb->fresh()->status_pembiayaan);
    }

    /* =====================================================================
     | 4. SKEMA TRANSFER_BANK
     |===================================================================== */
    public function test_transfer_bank_ditolak_tanpa_rekening_bank(): void
    {
        $manajer = $this->pengguna('manajer');
        $anggota = $this->anggota();
        $pb = $this->pembiayaan($anggota);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Rekening bank operasional wajib dipilih untuk pencairan transfer bank.');

        $this->service()->cairkan($pb, DisbursementMethod::TRANSFER_BANK, $manajer->id);
    }

    public function test_transfer_bank_ditolak_bila_saldo_buku_besar_kurang(): void
    {
        $manajer = $this->pengguna('manajer');
        $anggota = $this->anggota();
        $pb = $this->pembiayaan($anggota, Pembiayaan::AKAD_MURABAHAH, 10000000.0, 1000000.0);

        $bank = $this->coaBank();
        // Saldo buku besar bank sengaja dikosongkan (0 < pokok).
        DB::table('jurnal_detail')->where('coa_id', $bank->id)->delete();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Saldo rekening bank operasional tidak mencukupi');

        $this->service()->cairkan($pb, DisbursementMethod::TRANSFER_BANK, $manajer->id, $bank->id);
    }

    public function test_transfer_bank_ditolak_bila_rekening_bank_nonaktif(): void
    {
        $manajer = $this->pengguna('manajer');
        $anggota = $this->anggota();
        $pb = $this->pembiayaan($anggota);

        $bank = $this->coaBank();
        Coa::whereKey($bank->id)->update(['is_active' => false]);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Rekening bank operasional tidak ditemukan atau tidak aktif.');

            $this->service()->cairkan($pb, DisbursementMethod::TRANSFER_BANK, $manajer->id, $bank->id);
        } finally {
            Coa::whereKey($bank->id)->update(['is_active' => true]);
        }
    }

    public function test_transfer_bank_sukses_membukukan_bank_out_dan_jurnal_psak108(): void
    {
        $manajer = $this->pengguna('manajer');
        $anggota = $this->anggota();
        $pb = $this->pembiayaan($anggota, Pembiayaan::AKAD_MURABAHAH, 10000000.0, 1000000.0);

        $bank = $this->coaBank();
        $this->danaiCoa($bank, 50000000.0, $manajer);

        $hasil = $this->service()->cairkan($pb, DisbursementMethod::TRANSFER_BANK, $manajer->id, $bank->id);

        $this->assertSame('dicairkan', $hasil->status_pembiayaan);
        $this->assertSame(DisbursementMethod::TRANSFER_BANK, $hasil->disbursement_method);
        $this->assertSame($bank->id, (int) $hasil->bank_account_id);

        $transaksi = Transaksi::where('pembiayaan_id', $pb->id)
            ->where('jenis_transaksi', 'Transfer Keluar')
            ->first();

        $this->assertNotNull($transaksi, 'Buku pembantu BANK_OUT wajib tercatat.');
        $this->assertSame('Kredit', $transaksi->posisi);
        $this->assertSame('Transfer', $transaksi->sumber_transaksi);
        $this->assertEqualsWithDelta(10000000.0, (float) $transaksi->jumlah, 0.01);
        $this->assertStringContainsString($bank->kode_akun, (string) $transaksi->referensi);

        $jurnal = $this->jurnalPembiayaan($hasil);
        $rincian = $this->rincianJurnal($jurnal);

        $this->assertSame('Posted', $jurnal->status);
        $this->assertEqualsWithDelta(11000000.0, $rincian[\App\Support\Coa::PIUTANG_MURABAHAH]['debet'], 0.01);
        $this->assertEqualsWithDelta(10000000.0, $rincian[$bank->kode_akun]['kredit'], 0.01);
        $this->assertEqualsWithDelta(1000000.0, $rincian[\App\Support\Coa::MARGIN_DITANGGUHKAN]['kredit'], 0.01);
        $this->assertCount(3, $rincian);

        // Skema non-tunai: tidak boleh ada kas fisik keluar dari laci.
        $this->assertSame(0, Transaksi::where('pembiayaan_id', $pb->id)
            ->where('jenis_transaksi', 'Pencairan Pembiayaan')
            ->count());
    }

    /* =====================================================================
     | 5. SKEMA TRANSFER_SAVINGS (PINDAH BUKU)
     |===================================================================== */

    public function test_transfer_savings_ditolak_tanpa_rekening_simpanan(): void
    {
        $manajer = $this->pengguna('manajer');
        $anggota = $this->anggota();
        $pb = $this->pembiayaan($anggota);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Rekening Simpanan Sukarela wajib dipilih untuk pencairan pindah buku.');

        $this->service()->cairkan($pb, DisbursementMethod::TRANSFER_SAVINGS, $manajer->id);
    }

    public function test_transfer_savings_ditolak_bila_rekening_bukan_milik_anggota(): void
    {
        $manajer = $this->pengguna('manajer');
        $anggota = $this->anggota();
        $lainOrang = $this->anggota();
        $pb = $this->pembiayaan($anggota);

        $rekening = $this->rekening($lainOrang, 'Simpanan Sukarela');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Rekening simpanan bukan milik anggota pemohon pembiayaan.');

        $this->service()->cairkan($pb, DisbursementMethod::TRANSFER_SAVINGS, $manajer->id, null, $rekening->id);
    }

    public function test_transfer_savings_ditolak_bila_rekening_tidak_aktif(): void
    {
        $manajer = $this->pengguna('manajer');
        $anggota = $this->anggota();
        $pb = $this->pembiayaan($anggota);

        $rekening = $this->rekening($anggota, 'Simpanan Sukarela', 'Dibekukan');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Rekening Simpanan Sukarela tujuan tidak aktif.');

        $this->service()->cairkan($pb, DisbursementMethod::TRANSFER_SAVINGS, $manajer->id, null, $rekening->id);
    }

    public function test_transfer_savings_ditolak_bila_jenis_simpanan_tidak_diizinkan(): void
    {
        $manajer = $this->pengguna('manajer');
        $anggota = $this->anggota();
        $pb = $this->pembiayaan($anggota);

        // Simpanan Wajib bukan jenis yang diizinkan menerima pencairan.
        $rekening = $this->rekening($anggota, 'Simpanan Wajib', 'Aktif');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Jenis simpanan rekening tujuan tidak diizinkan menerima pencairan pembiayaan.');

        $this->service()->cairkan($pb, DisbursementMethod::TRANSFER_SAVINGS, $manajer->id, null, $rekening->id);
    }

    public function test_transfer_savings_sukses_menambah_saldo_tanpa_kas_fisik(): void
    {
        $manajer = $this->pengguna('manajer');
        $anggota = $this->anggota();
        $pb = $this->pembiayaan($anggota, Pembiayaan::AKAD_MURABAHAH, 10000000.0, 1000000.0);

        $rekening = $this->rekening($anggota, 'Simpanan Sukarela', 'Aktif', 250000.0);

        $hasil = $this->service()->cairkan($pb, DisbursementMethod::TRANSFER_SAVINGS, $manajer->id, null, $rekening->id);

        $this->assertSame('dicairkan', $hasil->status_pembiayaan);
        $this->assertSame(DisbursementMethod::TRANSFER_SAVINGS, $hasil->disbursement_method);
        $this->assertSame($rekening->id, (int) $hasil->savings_account_id);

        // Saldo simpanan sukarela bertambah sebesar pokok cair.
        $this->assertEqualsWithDelta(
            10250000.0,
            (float) $rekening->fresh()->saldo,
            0.01,
            'Saldo Simpanan Sukarela wajib bertambah sebesar nominal pokok.'
        );

        $transaksi = Transaksi::where('pembiayaan_id', $pb->id)
            ->where('jenis_transaksi', 'Simpanan Sukarela')
            ->first();

        $this->assertNotNull($transaksi, 'Buku pembantu SAVINGS_CREDIT wajib tercatat.');
        $this->assertSame('Kredit', $transaksi->posisi);
        $this->assertSame($rekening->id, (int) $transaksi->rekening_id);
        $this->assertEqualsWithDelta(10000000.0, (float) $transaksi->jumlah, 0.01);

        // Tidak ada uang fisik keluar: dilarang ada CASH_OUT dari laci.
        $this->assertSame(
            0,
            Transaksi::where('pembiayaan_id', $pb->id)->where('jenis_transaksi', 'Pencairan Pembiayaan')->count(),
            'Skema pindah buku TIDAK BOLEH menghasilkan transaksi kas keluar dari Laci Teller.'
        );
        $this->assertSame(
            0,
            Transaksi::where('pembiayaan_id', $pb->id)->where('sumber_transaksi', 'Teller')->count()
        );

        $jurnal = $this->jurnalPembiayaan($hasil);
        $rincian = $this->rincianJurnal($jurnal);

        $this->assertSame('Posted', $jurnal->status);
        $this->assertEqualsWithDelta(11000000.0, $rincian[\App\Support\Coa::PIUTANG_MURABAHAH]['debet'], 0.01);
        $this->assertEqualsWithDelta(10000000.0, $rincian[\App\Support\Coa::SIMPANAN_SUKARELA]['kredit'], 0.01);
        $this->assertEqualsWithDelta(1000000.0, $rincian[\App\Support\Coa::MARGIN_DITANGGUHKAN]['kredit'], 0.01);
        $this->assertCount(3, $rincian);
    }

    /* =====================================================================
     | 6. JURNAL PER AKAD (PSAK 108 DINAMIS)
     |===================================================================== */

    /**
     * Setiap akad wajib memakai akun piutang/investasi yang benar, sementara
     * hanya Murabahah yang memiliki akun Margin Ditangguhkan.
     *
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function penyediaAkad(): array
    {
        return [
            'Murabahah memiliki margin ditangguhkan' => ['Murabahah', '1-2001', true],
            'Mudharabah tanpa margin ditangguhkan' => ['Mudharabah', '1-2002', false],
            'Musyarakah tanpa margin ditangguhkan' => ['Musyarakah', '1-2003', false],
            'Ijarah tanpa margin ditangguhkan' => ['Ijarah', '1-2004', false],
            'Qardh tanpa margin (kebajikan)' => ['Qardh', '1-2005', false],
        ];
    }

    #[DataProvider('penyediaAkad')]
    public function test_pencairan_memakai_akun_akad_yang_benar(string $akad, string $kodePiutang, bool $punyaMargin): void
    {
        // Pastikan pemetaan dinamis kosong agar registri standar yang dipakai.
        $this->aturKebijakan([
            'account_piutang_murabahah_id' => null,
            'account_piutang_mudharabah_id' => null,
            'account_investasi_mudharabah_id' => null,
            'account_piutang_musyarakah_id' => null,
            'account_investasi_musyarakah_id' => null,
            'account_piutang_ijarah_id' => null,
            'account_piutang_qardh_id' => null,
        ]);

        $manajer = $this->pengguna('manajer');
        $anggota = $this->anggota();
        $pb = $this->pembiayaan($anggota, $akad, 5000000.0, 500000.0);

        $rekening = $this->rekening($anggota, 'Simpanan Sukarela', 'Aktif');

        $hasil = $this->service()->cairkan($pb, DisbursementMethod::TRANSFER_SAVINGS, $manajer->id, null, $rekening->id);
        $jurnal = $this->jurnalPembiayaan($hasil);
        $rincian = $this->rincianJurnal($jurnal);

        $this->assertNotNull($jurnal, "Jurnal pencairan akad {$akad} wajib terbentuk.");
        $this->assertArrayHasKey($kodePiutang, $rincian, "Akun piutang/investasi {$kodePiutang} wajib didebet untuk akad {$akad}.");

        if ($punyaMargin) {
            $this->assertEqualsWithDelta(5500000.0, $rincian[$kodePiutang]['debet'], 0.01);
            $this->assertArrayHasKey(\App\Support\Coa::MARGIN_DITANGGUHKAN, $rincian);
            $this->assertCount(3, $rincian);
        } else {
            $this->assertEqualsWithDelta(5000000.0, $rincian[$kodePiutang]['debet'], 0.01);
        }

        $this->assertEqualsWithDelta(
            (float) $jurnal->total_debet,
            (float) $jurnal->total_kredit,
            0.01,
            'Jurnal pencairan wajib seimbang (debet = kredit).'
        );
    }

    public function test_qardh_dilarang_memiliki_margin_pada_jurnal(): void
    {
        $manajer = $this->pengguna('manajer');
        $anggota = $this->anggota();
        // Qardh sengaja diberi total_ujroh agar diuji pembersihan marginnya.
        $pb = $this->pembiayaan($anggota, Pembiayaan::AKAD_QARDH, 3000000.0, 300000.0);

        $rekening = $this->rekening($anggota, 'Simpanan Sukarela', 'Aktif');

        $hasil = $this->service()->cairkan($pb, DisbursementMethod::TRANSFER_SAVINGS, $manajer->id, null, $rekening->id);
        $jurnal = $this->jurnalPembiayaan($hasil);
        $rincian = $this->rincianJurnal($jurnal);

        $this->assertArrayNotHasKey(
            \App\Support\Coa::MARGIN_DITANGGUHKAN,
            $rincian,
            'Qardhul Hasan tidak boleh memiliki margin ditangguhkan.'
        );
        $this->assertEqualsWithDelta(3000000.0, $rincian[\App\Support\Coa::PINJAMAN_QARDH]['debet'], 0.01);
        $this->assertEqualsWithDelta(3000000.0, $rincian[\App\Support\Coa::SIMPANAN_SUKARELA]['kredit'], 0.01);
    }

    /* =====================================================================
     | 7. PENJADWALAN & HARI LIBUR
     |===================================================================== */

    public function test_jadwal_angsuran_sejumlah_tenor_dengan_status_belum_jatuh_tempo(): void
    {
        $manajer = $this->pengguna('manajer');
        $anggota = $this->anggota();
        $pb = $this->pembiayaan($anggota, Pembiayaan::AKAD_MURABAHAH, 12000000.0, 1200000.0, 6);

        $rekening = $this->rekening($anggota, 'Simpanan Sukarela', 'Aktif');

        $hasil = $this->service()->cairkan($pb, DisbursementMethod::TRANSFER_SAVINGS, $manajer->id, null, $rekening->id);

        $jadwal = Angsuran::where('pembiayaan_id', $hasil->id)->orderBy('angsuran_ke')->get();

        $this->assertCount(6, $jadwal);
        $this->assertSame([1, 2, 3, 4, 5, 6], $jadwal->pluck('angsuran_ke')->map(fn ($v) => (int) $v)->all());
        $this->assertTrue($jadwal->every(fn ($a) => $a->status_angsuran === 'Belum Jatuh Tempo'));
        $this->assertTrue($jadwal->every(fn ($a) => $a->tanggal_jatuh_tempo !== null));

        // Tanggal jatuh tempo tersimpan pada header pembiayaan = angsuran ke-1.
        $this->assertNotNull($hasil->tanggal_jatuh_tempo);
        $this->assertSame(
            $jadwal->first()->tanggal_jatuh_tempo->toDateString(),
            $hasil->tanggal_jatuh_tempo->toDateString(),
            'tanggal_jatuh_tempo pembiayaan wajib sama dengan jatuh tempo angsuran pertama.'
        );

        // Jadwal wajib menaik secara kronologis.
        $tanggal = $jadwal->pluck('tanggal_jatuh_tempo')->map(fn ($d) => $d->toDateString())->all();
        $urut = $tanggal;
        sort($urut);
        $this->assertSame($urut, $tanggal);
    }

    public function test_hari_libur_service_menggeser_tanggal_ke_hari_kerja(): void
    {
        $service = app(HariLiburService::class);

        // Sabtu → wajib digeser (ke Senin bila MAJU).
        $sabtu = Carbon::parse('2026-01-03'); // Sabtu
        $this->assertSame(6, (int) $sabtu->dayOfWeek, 'Prasyarat: 2026-01-03 adalah Sabtu.');
        $this->assertFalse($service->isHariKerja($sabtu));

        Setting::flush();
        $hasil = $service->keHariKerja($sabtu);
        $this->assertFalse($hasil->isWeekend(), 'Hasil penggeseran wajib hari kerja.');
        $this->assertTrue($hasil->greaterThan($sabtu), 'Strategi MAJU wajib bergerak ke depan.');
    }

    public function test_tanggal_jatuh_tempo_digeser_bila_terdaftar_hari_libur(): void
    {
        // Senin 2026-01-05 didaftarkan sebagai libur koperasi.
        $libur = '2026-01-05';
        HariLibur::whereDate('tanggal', $libur)->delete();
        $this->jaduhkanLibur($libur, 'Libur Uji Pencairan');

        Setting::flush();
        $service = app(HariLiburService::class);

        $this->assertFalse($service->isHariKerja($libur));

        $hasil = $service->keHariKerja($libur);
        $this->assertNotSame($libur, $hasil->toDateString());
        $this->assertTrue($service->isHariKerja($hasil));
    }

    public function test_jatuh_tempo_pertama_mengikuti_setting_tanggal_bulanan(): void
    {
        $this->aturKebijakan(['tanggal_jatuh_tempo_default' => 10, 'hari_libur_aktif' => true]);

        $service = app(HariLiburService::class);
        $tanggal = Carbon::parse('2026-03-15');

        $jatuhTempo = $service->jatuhTempoPertama($tanggal);

        // Basis = satu bulan setelah pencairan, tanggal 10 (10 April 2026 = Jumat).
        $this->assertSame('2026-04-10', $jatuhTempo->toDateString());
        $this->assertTrue($service->isHariKerja($jatuhTempo));
    }

    public function test_strategi_mundur_menggeser_ke_hari_kerja_sebelumnya(): void
    {
        $this->aturKebijakan(['hari_libur_strategi' => 'MUNDUR', 'hari_libur_aktif' => true]);

        Setting::flush();
        $service = app(HariLiburService::class);

        $sabtu = Carbon::parse('2026-01-03');
        $hasil = $service->keHariKerja($sabtu);

        $this->assertFalse($hasil->isWeekend());
        $this->assertTrue($hasil->lessThan($sabtu), 'Strategi MUNDUR wajib bergerak ke belakang.');
    }

    public function test_penyesuaian_hari_libur_dapat_dinonaktifkan(): void
    {
        $this->aturKebijakan(['hari_libur_aktif' => false]);

        Setting::flush();
        $service = app(HariLiburService::class);

        $sabtu = Carbon::parse('2026-01-03');

        // Fitur dimatikan → tanggal dibiarkan apa adanya (tanpa pergeseran).
        $this->assertSame('2026-01-03', $service->keHariKerja($sabtu)->toDateString());
    }

    public function test_jadwal_jatuh_tempo_mengembalikan_sejumlah_tenor(): void
    {
        $this->aturKebijakan(['tanggal_jatuh_tempo_default' => 5]);

        Setting::flush();
        $service = app(HariLiburService::class);

        $jadwal = $service->jadwalJatuhTempo('2026-01-15', 4);

        $this->assertCount(4, $jadwal);
        $this->assertSame([1, 2, 3, 4], array_keys($jadwal));

        foreach ($jadwal as $tanggal) {
            $this->assertFalse($tanggal->isWeekend(), 'Setiap jatuh tempo wajib hari kerja.');
        }
    }

    /* =====================================================================
     | 8. ATOMIK & ROLLBACK
     |===================================================================== */

    public function test_pencairan_rollback_penuh_saat_jurnal_gagal_dibentuk(): void
    {
        $manajer = $this->pengguna('manajer');
        $anggota = $this->anggota();
        $pb = $this->pembiayaan($anggota, Pembiayaan::AKAD_MURABAHAH, 7000000.0, 700000.0);

        $rekening = $this->rekening($anggota, 'Simpanan Sukarela', 'Aktif', 100000.0);
        $saldoAwal = (float) $rekening->fresh()->saldo;
        $statusAwal = $pb->status_pembiayaan;

        // Lumpuhkan akun Margin Ditangguhkan → pembentukan jurnal MUSTAHIL.
        $akunMargin = Coa::findByKode(\App\Support\Coa::MARGIN_DITANGGUHKAN);

        if (! $akunMargin) {
            $this->markTestSkipped('Akun Margin Ditangguhkan tidak tersedia pada bagan akun.');
        }

        Coa::whereKey($akunMargin->id)->update(['is_active' => false]);
        Setting::flush();

        try {
            $this->expectException(\RuntimeException::class);

            $this->service()->cairkan($pb, DisbursementMethod::TRANSFER_SAVINGS, $manajer->id, null, $rekening->id);
        } finally {
            Coa::whereKey($akunMargin->id)->update(['is_active' => true]);
            Setting::flush();
        }

        // ── Seluruh perubahan wajib di-rollback ─────────────────────────
        $this->assertSame(
            $statusAwal,
            $pb->fresh()->status_pembiayaan,
            'Status pembiayaan TIDAK BOLEH berubah bila jurnal gagal.'
        );
        $this->assertNull($pb->fresh()->tgl_dicairkan);

        $this->assertEqualsWithDelta(
            $saldoAwal,
            (float) $rekening->fresh()->saldo,
            0.01,
            'Saldo simpanan wajib dikembalikan ke nilai semula (rollback).'
        );

        $this->assertSame(0, Angsuran::where('pembiayaan_id', $pb->id)->count(), 'Jadwal angsuran wajib di-rollback.');
        $this->assertSame(0, Transaksi::where('pembiayaan_id', $pb->id)->count(), 'Buku pembantu wajib di-rollback.');
        $this->assertSame(0, Jurnal::where('pembiayaan_id', $pb->id)->count(), 'Jurnal wajib di-rollback.');
    }

    public function test_cash_rollback_tidak_mengubah_saldo_laci_saat_gagal(): void
    {
        $teller = $this->pengguna('teller');
        $anggota = $this->anggota();
        $pb = $this->pembiayaan($anggota, Pembiayaan::AKAD_MURABAHAH, 9000000.0, 900000.0);

        $sesi = $this->sesiLaci($teller, 10000000.0);
        $asalSesi = (float) $sesi->saldo_sistem;

        // Lumpuhkan akun piutang Murabahah → jurnal mustahil dibentuk.
        $akunPiutang = Coa::findByKode(\App\Support\Coa::PIUTANG_MURABAHAH);

        if (! $akunPiutang) {
            $this->markTestSkipped('Akun piutang Murabahah tidak tersedia.');
        }

        Coa::whereKey($akunPiutang->id)->update(['is_active' => false]);
        Setting::flush();

        try {
            $this->expectException(\RuntimeException::class);
            $this->service()->cairkan($pb, DisbursementMethod::CASH, $teller->id);
        } finally {
            Coa::whereKey($akunPiutang->id)->update(['is_active' => true]);
            Setting::flush();
        }

        $this->assertEqualsWithDelta($asalSesi, (float) $sesi->fresh()->saldo_sistem, 0.01);
        $this->assertSame(0, Transaksi::where('pembiayaan_id', $pb->id)->count());
        $this->assertSame('disetujui', $pb->fresh()->status_pembiayaan);
    }

    /* =====================================================================
     | 9. JEJAK AUDIT & LOG PENOLAKAN
     |===================================================================== */

    public function test_penolakan_tercatat_di_log_sistem(): void
    {
        Log::spy();

        $manajer = $this->pengguna('manajer');
        $anggota = $this->anggota();
        $pb = $this->pembiayaan($anggota);

        try {
            $this->service()->cairkan($pb, DisbursementMethod::CASH, $manajer->id);
        } catch (\RuntimeException $e) {
            // diharapkan
        }

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($pesan) => str_contains($pesan, 'Pencairan pembiayaan ditolak'))
            ->atLeast()->once();
    }
}
