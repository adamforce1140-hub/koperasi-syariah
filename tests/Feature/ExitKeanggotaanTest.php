<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\AuditLog;
use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\MandatorySavingsBill;
use App\Models\MemberTermination;
use App\Models\Pembiayaan;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\AutoJournalingService;
use App\Services\ExitKeanggotaanService;
use App\Services\MandatorySavingsService;
use App\Support\Coa as KodeCoa;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * EXIT KEANGGOTAAN (`member_terminations`) — SATU-SATUNYA PINTU SAH
 * pengembalian dana Simpanan Pokok (CoA 3-1001) & Simpanan Wajib (CoA 3-1002).
 *
 * Berkas ini mengunci seluruh janji bisnis modul exit end-to-end:
 *  1. GAGAL bila syarat kelayakan belum terpenuhi (tagihan/pembiayaan) —
 *     proses dibatalkan otomatis, status anggota TIDAK berubah, jejak audit
 *     `EXIT_KEANGGOTAAN_DIBLOKIR` tercatat.
 *  2. SUKSES metode CASH → auto-journal PSAK 108:
 *     DEBET 3-1001 + DEBET 3-1002 / KREDIT 1-1001 (Kas Teller),
 *     baris `transaksi` ber-jenis `CASH_OUT`.
 *  3. SUKSES metode TRANSFER_BANK → DEBET 3-1001 + DEBET 3-1002 /
 *     KREDIT 1-1002 (Kas di Bank), baris `transaksi` ber-jenis `BANK_OUT`,
 *     wajib menyertakan nomor rekening tujuan.
 *  4. Perubahan status anggota otomatis menjadi TERMINATED + jejak audit
 *     (tanggal keluar, total dana dikembalikan, user pemroses).
 *  5. IDEMPOTENSI: jurnal hanya diposting SEKALI.
 *  6. APPEND-ONLY: dokumen yang jurnalnya sudah diposting tidak dapat
 *     diubah maupun dihapus (integritas buku besar PSAK 108).
 *
 * Basis data memuat data nyata → prefix unik + DatabaseTransactions.
 */
class ExitKeanggotaanTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'EXIT'.strtoupper(substr(uniqid(), -6));

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

    /**
     * Seluruh CoA yang dipakai alur exit harus ada & AKTIF.
     * Bila belum ter-seed, tes di-skip (bukan gagal) — jalankan CoaSeeder.
     */
    private function pastikanCoa(): void
    {
        foreach ([
            KodeCoa::SIMPANAN_POKOK,
            KodeCoa::SIMPANAN_WAJIB_MODAL,
            KodeCoa::KAS_TELLER,
            KodeCoa::BANK_KOPERASI,
        ] as $kode) {
            if (! Coa::findByKode($kode)) {
                $this->markTestSkipped("CoA {$kode} belum tersedia/aktif — jalankan CoaSeeder.");
            }
        }
    }

    private function anggota(string $status = Anggota::STATUS_ACTIVE): Anggota
    {
        return Anggota::create([
            'no_anggota' => 'AGT-'.$this->prefix,
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Exit '.$this->prefix,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Alamat Uji Exit Keanggotaan',
            'kota' => 'Jakarta',
            'provinsi' => 'DKI Jakarta',
            'status_anggota' => $status,
            'is_auto_debit' => false,
            'tanggal_gabung' => '2026-01-01',
        ]);
    }

    /** Bentuk saldo Simpanan Pokok (3-1001) lewat jalur produksi. */
    private function setorPokok(Anggota $anggota, float $nominal, int $userId): void
    {
        $jurnal = app(AutoJournalingService::class)->jurnalSetorSimpananPokok(
            $nominal,
            'Setoran Simpanan Pokok uji '.$this->prefix,
            $userId,
            null,
            null,
            $anggota->id
        );

        $this->assertNotNull($jurnal, 'Jurnal setoran Simpanan Pokok gagal diposting.');
    }

    /** Bentuk saldo Simpanan Wajib (3-1002) lewat jalur produksi (tagihan LUNAS). */
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

    /** Anggota siap exit: punya saldo Pokok + Wajib dan bebas kewajiban. */
    private function anggotaSiapExit(float $pokok = 100000, float $wajib = 50000): Anggota
    {
        $anggota = $this->anggota();
        $this->setorPokok($anggota, $pokok, $this->user()->id);
        $this->setorWajib($anggota, $wajib);

        return $anggota->refresh();
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
     | 1. GERBANG KELAYAKAN — EXIT GAGAL (SYARAT BELUM TERPENUHI)
     |===================================================================== */

    public function test_exit_gagal_bila_masih_ada_tagihan_simpanan_wajib_belum_lunas(): void
    {
        $this->pastikanCoa();

        $anggota = $this->anggotaSiapExit();
        $terminasi = $this->service()->ajukan($anggota, [], $this->user()->id);

        // Tunggakan: tagihan Simpanan Wajib bulan berjalan belum dibayar.
        MandatorySavingsBill::create([
            'member_id' => $anggota->id,
            'bill_period' => '2026-02',
            'amount' => 50000,
            'status' => MandatorySavingsBill::STATUS_UNPAID,
        ]);

        $auditSebelum = AuditLog::where('jenis_transaksi', 'EXIT_KEANGGOTAAN_DIBLOKIR')->count();

        try {
            $this->service()->eksekusi($terminasi, MemberTermination::METODE_CASH, $this->user()->id);
            $this->fail('Exit seharusnya DIBATALKAN karena masih ada tagihan belum lunas.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('kewajiban', $e->getMessage());
        }

        $this->assertSame(
            Anggota::STATUS_ACTIVE,
            $anggota->refresh()->status_anggota,
            'Status anggota TIDAK boleh berubah bila exit gagal.'
        );
        $this->assertNull($terminasi->refresh()->jurnal_id, 'Jurnal tidak boleh diposting saat exit gagal.');
        $this->assertSame(
            MemberTermination::STATUS_DIAJUKAN,
            $terminasi->status,
            'Dokumen harus tetap DIAJUKAN saat eksekusi gagal.'
        );
        $this->assertGreaterThan(
            $auditSebelum,
            AuditLog::where('jenis_transaksi', 'EXIT_KEANGGOTAAN_DIBLOKIR')->count(),
            'Upaya exit yang diblokir WAJIB tercatat untuk audit.'
        );
    }

    public function test_exit_gagal_bila_masih_ada_pembiayaan_berjalan(): void
    {
        $this->pastikanCoa();

        $anggota = $this->anggotaSiapExit();
        $terminasi = $this->service()->ajukan($anggota, [], $this->user()->id);

        $akunId = KodeCoa::akunLegacyId(KodeCoa::PIUTANG_MURABAHAH)
            ?? DB::table('akun')->orderBy('id')->value('id');

        if (! $akunId) {
            $this->markTestSkipped('Tabel `akun` legacy belum ter-seed.');
        }

        Pembiayaan::create([
            'no_pembiayaan' => 'PBY-'.$this->prefix,
            'anggota_id' => $anggota->id,
            'akun_id' => $akunId,
            'akad_pembiayaan' => Pembiayaan::AKAD_MURABAHAH,
            'tujuan_pembiayaan' => 'Modal Usaha',
            'jumlah_pembiayaan' => 1000000,
            'total_pembiayaan' => 1100000,
            'tenor' => 12,
            'jumlah_angsuran' => 100000,
            'tanggal_akad' => '2026-01-01',
            'tanggal_jatuh_tempo' => '2026-12-31',
            'status_pembiayaan' => Pembiayaan::STATUS_AKTIF,
            'sisa_pembiayaan' => 750000,
        ]);

        $this->expectException(\RuntimeException::class);

        $this->service()->eksekusi($terminasi, MemberTermination::METODE_CASH, $this->user()->id);
    }

    public function test_exit_gagal_bila_tidak_ada_saldo_ekuitas(): void
    {
        $this->pastikanCoa();

        // Anggota bersih (tanpa kewajiban) namun belum punya saldo Pokok/Wajib.
        $anggota = $this->anggota();
        $terminasi = $this->service()->ajukan($anggota, [], $this->user()->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Tidak ada dana/');

        $this->service()->eksekusi($terminasi, MemberTermination::METODE_CASH, $this->user()->id);
    }

    /* =====================================================================
     | 2. EKSEKUSI SUKSES — METODE CASH + JURNAL PSAK 108
     |===================================================================== */

    public function test_exit_sukses_metode_cash_memposting_jurnal_psak108_dan_status_terminated(): void
    {
        $this->pastikanCoa();

        $prosesor = $this->user('manajer');
        $anggota = $this->anggotaSiapExit(100000, 50000);

        $terminasi = $this->service()->ajukan($anggota, [
            'tanggal_pengunduran_diri' => '2026-03-15',
        ], $prosesor->id);

        $hasil = $this->service()->eksekusi(
            $terminasi,
            MemberTermination::METODE_CASH,
            $prosesor->id
        );

        // ── Dokumen terminasi ────────────────────────────────────────────
        $this->assertSame(MemberTermination::STATUS_DISETUJUI, $hasil->status);
        $this->assertSame(MemberTermination::METODE_CASH, $hasil->metode);
        $this->assertNotNull($hasil->jurnal_id, 'Jurnal PSAK 108 wajib ter-posted.');
        $this->assertSame(100000.0, (float) $hasil->nominal_simpanan_pokok);
        $this->assertSame(50000.0, (float) $hasil->nominal_simpanan_wajib);
        $this->assertSame(150000.0, (float) $hasil->total_dikembalikan);

        // ── Jurnal PSAK 108 ──────────────────────────────────────────────
        $jurnal = Jurnal::find($hasil->jurnal_id);
        $this->assertNotNull($jurnal);
        $this->assertTrue($jurnal->isPosted());

        $baris = $this->barisJurnal($jurnal->id);
        $this->assertSame(100000.0, $baris[KodeCoa::SIMPANAN_POKOK]['debet'] ?? null, 'DEBET 3-1001 Simpanan Pokok.');
        $this->assertSame(50000.0, $baris[KodeCoa::SIMPANAN_WAJIB_MODAL]['debet'] ?? null, 'DEBET 3-1002 Simpanan Wajib.');
        $this->assertSame(150000.0, $baris[KodeCoa::KAS_TELLER]['kredit'] ?? null, 'KREDIT 1-1001 Kas Teller = total dana.');

        // ── Ledger kas keluar (CASH_OUT) ─────────────────────────────────
        $transaksi = Transaksi::where('referensi', $hasil->no_terminasi)
            ->where('anggota_id', $anggota->id)
            ->get();

        $this->assertCount(2, $transaksi, 'Satu baris transaksi per akun ekuitas (Pokok & Wajib).');
        $this->assertTrue($transaksi->every(fn ($t) => $t->jenis_transaksi === 'CASH_OUT'));
        $this->assertSame(150000.0, (float) $transaksi->sum('jumlah'));

        // ── Status anggota → TERMINATED + jejak audit ────────────────────
        $anggota->refresh();
        $this->assertSame(Anggota::STATUS_TERMINATED, $anggota->status_anggota);
        $this->assertTrue($anggota->isTerminated());
        $this->assertSame(150000.0, (float) $anggota->total_dana_dikembalikan);
        $this->assertSame($prosesor->id, (int) $anggota->terminasi_diproses_oleh);
        $this->assertNotNull($anggota->tanggal_terminasi);
        $this->assertSame('2026-03-15', $anggota->tanggal_keluar?->toDateString());

        $this->assertTrue(
            AuditLog::where('jenis_transaksi', 'EXIT_KEANGGOTAAN')
                ->where('keterangan', 'like', '%'.$anggota->nama_lengkap.'%')
                ->exists(),
            'Jejak audit EXIT_KEANGGOTAAN wajib tercatat.'
        );
    }

    /* =====================================================================
     | 3. EKSEKUSI SUKSES — METODE TRANSFER_BANK + JURNAL PSAK 108
     |===================================================================== */

    public function test_exit_sukses_metode_transfer_bank_memposting_jurnal_psak108(): void
    {
        $this->pastikanCoa();

        $prosesor = $this->user('pengurus');
        $anggota = $this->anggotaSiapExit(200000, 60000);

        $terminasi = $this->service()->ajukan($anggota, [], $prosesor->id);

        // Data rekening tujuan WAJIB untuk TRANSFER_BANK.
        $terminasi->fill([
            'nomor_rekening_tujuan' => '1234567890',
            'nama_bank_tujuan' => 'Bank Syariah Uji',
        ])->save();

        $hasil = $this->service()->eksekusi(
            $terminasi->refresh(),
            MemberTermination::METODE_TRANSFER_BANK,
            $prosesor->id
        );

        $this->assertSame(MemberTermination::STATUS_DISETUJUI, $hasil->status);
        $this->assertSame(MemberTermination::METODE_TRANSFER_BANK, $hasil->metode);

        // ── Jurnal PSAK 108: KREDIT 1-1002 Kas di Bank ───────────────────
        $baris = $this->barisJurnal($hasil->jurnal_id);
        $this->assertSame(200000.0, $baris[KodeCoa::SIMPANAN_POKOK]['debet'] ?? null, 'DEBET 3-1001 Simpanan Pokok.');
        $this->assertSame(60000.0, $baris[KodeCoa::SIMPANAN_WAJIB_MODAL]['debet'] ?? null, 'DEBET 3-1002 Simpanan Wajib.');
        $this->assertSame(260000.0, $baris[KodeCoa::BANK_KOPERASI]['kredit'] ?? null, 'KREDIT 1-1002 Kas di Bank = total dana.');

        // ── Ledger bank keluar (BANK_OUT) + nomor rekening tujuan ────────
        $transaksi = Transaksi::where('referensi', $hasil->no_terminasi)->get();
        $this->assertCount(2, $transaksi);
        $this->assertTrue($transaksi->every(fn ($t) => $t->jenis_transaksi === 'BANK_OUT'));
        $this->assertTrue(
            $transaksi->every(fn ($t) => str_contains((string) $t->catatan, '1234567890')),
            'Keterangan transaksi wajib memuat nomor rekening tujuan.'
        );

        $this->assertSame(Anggota::STATUS_TERMINATED, $anggota->refresh()->status_anggota);
    }

    public function test_transfer_bank_tanpa_nomor_rekening_tujuan_ditolak(): void
    {
        $this->pastikanCoa();

        $anggota = $this->anggotaSiapExit();
        $terminasi = $this->service()->ajukan($anggota, [], $this->user()->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/rekening tujuan/');

        $this->service()->eksekusi($terminasi, MemberTermination::METODE_TRANSFER_BANK, $this->user()->id);
    }

    public function test_metode_pengembalian_tidak_dikenal_ditolak(): void
    {
        $this->pastikanCoa();

        $anggota = $this->anggotaSiapExit();
        $terminasi = $this->service()->ajukan($anggota, [], $this->user()->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/tidak dikenal/');

        $this->service()->eksekusi($terminasi, 'GOPAY', $this->user()->id);
    }

    /* =====================================================================
     | 4. IDEMPOTENSI — JURNAL HANYA DIPOSTING SEKALI
     |===================================================================== */

    public function test_eksekusi_idempoten_tidak_memposting_jurnal_kedua_kali(): void
    {
        $this->pastikanCoa();

        $anggota = $this->anggotaSiapExit(100000, 50000);
        $terminasi = $this->service()->ajukan($anggota, [], $this->user()->id);

        $pertama = $this->service()->eksekusi($terminasi, MemberTermination::METODE_CASH, $this->user()->id);

        $jurnalSebelum = Jurnal::where('sumber', AutoJournalingService::SUMBER_PENGEMBALIAN_EXIT)
            ->where('no_jurnal', $pertama->no_jurnal)
            ->count();
        $transaksiSebelum = Transaksi::where('referensi', $pertama->no_terminasi)->count();

        // Eksekusi ulang → harus mengembalikan dokumen yang SAMA, tanpa jurnal baru.
        $kedua = $this->service()->eksekusi(
            MemberTermination::find($pertama->id),
            MemberTermination::METODE_CASH,
            $this->user()->id
        );

        $this->assertSame($pertama->jurnal_id, $kedua->jurnal_id, 'Jurnal tidak boleh diposting ulang.');
        $this->assertSame(
            $jurnalSebelum,
            Jurnal::where('sumber', AutoJournalingService::SUMBER_PENGEMBALIAN_EXIT)
                ->where('no_jurnal', $pertama->no_jurnal)
                ->count(),
            'Tidak boleh ada jurnal tambahan pada eksekusi kedua.'
        );
        $this->assertSame(
            $transaksiSebelum,
            Transaksi::where('referensi', $pertama->no_terminasi)->count(),
            'Tidak boleh ada baris transaksi tambahan pada eksekusi kedua.'
        );
    }

    /* =====================================================================
     | 5. APPEND-ONLY — INTEGRITAS BUKU BESAR PSAK 108
     |===================================================================== */

    public function test_dokumen_terminasi_append_only_setelah_jurnal_diposting(): void
    {
        $this->pastikanCoa();

        $anggota = $this->anggotaSiapExit();
        $terminasi = $this->service()->ajukan($anggota, [], $this->user()->id);
        $hasil = $this->service()->eksekusi($terminasi, MemberTermination::METODE_CASH, $this->user()->id);

        $this->assertNotNull($hasil->jurnal_id);

        // ── Update dilarang ──────────────────────────────────────────────
        try {
            MemberTermination::find($hasil->id)->fill(['catatan' => 'manipulasi'])->save();
            $this->fail('Dokumen terminasi ter-posting seharusnya TIDAK dapat diubah.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permanen', $e->getMessage());
        }

        // ── Delete dilarang ──────────────────────────────────────────────
        try {
            MemberTermination::find($hasil->id)->delete();
            $this->fail('Dokumen terminasi ter-posting seharusnya TIDAK dapat dihapus.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permanen', $e->getMessage());
        }

        $this->assertDatabaseHas('member_terminations', [
            'id' => $hasil->id,
            'status' => MemberTermination::STATUS_DISETUJUI,
        ]);
    }

    /* =====================================================================
     | 6. PENGAJUAN — ANTI DUPLIKAT & ANGGOTA SUDAH KELUAR
     |===================================================================== */

    public function test_pengajuan_ganda_untuk_anggota_yang_sama_ditolak(): void
    {
        $anggota = $this->anggota();

        $this->service()->ajukan($anggota, [], $this->user()->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/masih menunggu/');

        $this->service()->ajukan($anggota, [], $this->user()->id);
    }

    public function test_anggota_yang_sudah_terminated_tidak_dapat_mengajukan_exit_lagi(): void
    {
        $anggota = $this->anggota(Anggota::STATUS_TERMINATED);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/TERMINATED/');

        $this->service()->ajukan($anggota, [], $this->user()->id);
    }
}
