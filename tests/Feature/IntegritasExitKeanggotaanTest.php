<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\MemberTermination;
use App\Models\Pembiayaan;
use App\Models\User;
use App\Services\ExitKeanggotaanService;
use App\Support\Coa as KodeCoa;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Batch 7 — Integritas modul Exit Keanggotaan (pengunduran diri & pengembalian dana).
 *
 * Gerbang yang diuji:
 *
 *  G1. Anggota dengan pembiayaan BERJALAN tidak boleh lolos gerbang kelayakan
 *      (dana Simpanan Pokok/Wajib adalah ekuitas — PSAK 101 ¶ 31).
 *
 *  G2. Anggota yang masih punya TUNGGAKAN ANGSURAN tidak boleh di-exit, meski
 *      kontrak pembiayaannya berstatus LUNAS.
 *
 *  G3. (A-07) Nominal yang dicairkan pada EKSEKUSI wajib memakai saldo SAAT
 *      EKSEKUSI, bukan pratinjau (`rincianSaldo()` dihitung di luar
 *      `DB::transaction`). Bila dana anggota berubah setelah pengajuan, sistem
 *      tidak boleh mencairkan nominal lama (dana hangus) maupun nominal
 *      berlebih (dana koperasi bocor).
 *
 * Catatan: berkas ini memakai `DatabaseTransactions` + prefix unik sehingga
 * tidak menyentuh data produksi dan dapat dijalankan berulang.
 */
class IntegritasExitKeanggotaanTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'EXIT'.strtoupper(substr(uniqid(), -6));
    }

    /* =====================================================================
     | FIXTURE
     ===================================================================== */

    private function anggota(): Anggota
    {
        return Anggota::create([
            'no_anggota' => 'AGT-'.$this->prefix,
            // NIK wajib 16 digit & unik — dibangkitkan dari prefix uji.
            'nik' => str_pad((string) hexdec(substr(md5($this->prefix), 0, 12)), 16, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Uji Exit '.$this->prefix,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Pengujian No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_ACTIVE,
            'tanggal_gabung' => '2020-01-01',
        ]);
    }

    private function pengurus(): User
    {
        return User::create([
            'name' => 'Pengurus '.$this->prefix,
            'email' => strtolower($this->prefix).'.pengurus@test.local',
            'password' => Hash::make('password'),
            'role' => 'pengurus',
            'is_active' => true,
        ]);
    }

    private function rekening(Anggota $anggota, string $jenis, float $saldo): Rekening
    {
        return Rekening::create([
            'no_rekening' => 'RKN-'.$this->prefix.'-'.strtoupper(substr(uniqid(), -4)),
            'anggota_id' => $anggota->id,
            'akun_id' => (int) (Coa::query()->value('id') ?? 1),
            'jenis_rekening' => $jenis,
            'akad' => 'Wadiah',
            'saldo' => $saldo,
            'batas_minimum' => 0,
            'tanggal_buka' => '2020-01-01',
            'status_rekening' => 'Aktif',
        ]);
    }

    /**
     * Sumber saldo Simpanan Pokok & Wajib adalah MUTASI JURNAL (bukan kolom
     * `rekening.saldo`) — lihat `ExitKeanggotaanService::rincianSaldo()`.
     * Jurnal dibuat berstatus Posted dengan `jurnal_detail.anggota_id` terisi.
     */
    private function jurnalEkuitas(Anggota $anggota, string $kodeAkun, float $kredit): void
    {
        $coa = Coa::where('kode_akun', $kodeAkun)->first();

        $this->assertNotNull($coa, "Akun CoA {$kodeAkun} wajib tersedia pada basis data uji.");

        $jurnal = Jurnal::create([
            'no_jurnal' => 'UJI-'.$this->prefix.'-'.strtoupper(substr(uniqid(), -5)),
            'tanggal_jurnal' => '2020-01-01',
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'UJI',
            'keterangan' => 'Saldo awal simpanan modal uji exit',
            'total_debet' => 0,
            'total_kredit' => $kredit,
            'is_balance' => true,
            'status' => 'Posted',
            'posted_at' => now(),
        ]);

        JurnalDetail::create([
            'jurnal_id' => $jurnal->id,
            'coa_id' => $coa->id,
            'anggota_id' => $anggota->id,
            'baris' => 1,
            'debet' => 0,
            'kredit' => $kredit,
            'keterangan' => 'Uji exit keanggotaan',
        ]);
    }

    /** Saldo Simpanan Pokok Rp 100.000 & Simpanan Wajib Rp 150.000. */
    private function simpananModal(Anggota $anggota): void
    {
        $this->jurnalEkuitas($anggota, KodeCoa::SIMPANAN_POKOK, 100000.0);
        $this->jurnalEkuitas($anggota, KodeCoa::SIMPANAN_WAJIB_MODAL, 150000.0);
    }

    private function service(): ExitKeanggotaanService
    {
        return app(ExitKeanggotaanService::class);
    }

    /* =====================================================================
     | G1 — GERBANG PEMBIAYAAN BERJALAN
     ===================================================================== */

    public function test_anggota_dengan_pembiayaan_berjalan_tidak_layak_exit(): void
    {
        $anggota = $this->anggota();
        $this->simpananModal($anggota);

        Pembiayaan::create([
            'no_pembiayaan' => 'PMB-'.$this->prefix,
            'anggota_id' => $anggota->id,
            'akun_id' => (int) (Coa::query()->value('id') ?? 1),
            'akad_pembiayaan' => 'Murabahah',
            'jumlah_pembiayaan' => 2000000.0,
            'total_pembiayaan' => 2400000.0,
            'total_ujroh' => 400000.0,
            'sisa_pembiayaan' => 2000000.0,
            'tenor' => 12,
            'jumlah_angsuran' => 200000.0,
            'tanggal_akad' => now()->subMonths(3)->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths(9)->toDateString(),
            'status_pembiayaan' => 'Aktif',
        ]);

        $this->assertFalse(
            $this->service()->layakExit($anggota),
            'Anggota dengan pembiayaan berjalan tidak boleh dianggap layak exit.'
        );

        $this->assertNotEmpty(
            $this->service()->kendalaExit($anggota),
            'Kendala exit wajib menyebut kewajiban pembiayaan yang belum lunas.'
        );

        $this->expectException(\RuntimeException::class);
        $this->service()->pastikanLayakExit($anggota);
    }

    public function test_anggota_tanpa_kewajiban_layak_exit(): void
    {
        $anggota = $this->anggota();
        $this->simpananModal($anggota);

        $this->assertTrue(
            $this->service()->layakExit($anggota),
            'Anggota tanpa pembiayaan & tanpa tunggakan harus layak exit.'
        );
    }

    /* =====================================================================
     | G2 — TUNGGAKAN ANGSURAN PADA KONTRAK BERSTATUS LUNAS
     ===================================================================== */

    public function test_tunggakan_angsuran_pada_kontrak_hapus_buku_menghalangi_exit(): void
    {
        $anggota = $this->anggota();
        $this->simpananModal($anggota);

        // Status pembiayaan sengaja dibuat "Dihapus Buku" — salah satu status
        // yang BUKAN berjalan, dan sisa_pembiayaan = 0 sehingga isLunas() true.
        // Namun masih ada angsuran terbuka: dana koperasi sudah dihapusbukukan,
        // utang anggota ke koperasi tetap ada → exit tidak boleh meloloskan
        // pengembalian ekuitas sebelum tunggakan diselesaikan.
        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => 'PMB-'.$this->prefix.'-L',
            'anggota_id' => $anggota->id,
            'akun_id' => (int) (Coa::query()->value('id') ?? 1),
            'akad_pembiayaan' => 'Qardh',
            'jumlah_pembiayaan' => 500000.0,
            'total_pembiayaan' => 500000.0,
            'total_ujroh' => 0,
            'sisa_pembiayaan' => 0,
            'tenor' => 1,
            'jumlah_angsuran' => 500000.0,
            'tanggal_akad' => now()->subMonth()->toDateString(),
            'tanggal_jatuh_tempo' => now()->subDays(10)->toDateString(),
            'status_pembiayaan' => Pembiayaan::STATUS_HAPUS_BUKU,
        ]);

        // Angsuran masih terbuka padahal kontrak sudah ditandai lunas.
        DB::table('angsuran')->insert([
            'pembiayaan_id' => $pembiayaan->id,
            'angsuran_ke' => 1,
            'tanggal_jatuh_tempo' => now()->subDays(10)->toDateString(),
            'pokok_angsuran' => 500000.0,
            'margin_ujroh' => 0,
            'jumlah_angsuran' => 500000.0,
            'sisa_pokok' => 500000.0,
            'status_angsuran' => 'Terlambat',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue(
            DB::table('angsuran')->where('pembiayaan_id', $pembiayaan->id)->exists(),
            'Prasyarat uji: baris angsuran harus benar-benar tersimpan.'
        );

        $this->assertFalse(
            $this->service()->layakExit($anggota),
            'Kontrak berstatus LUNAS tidak boleh menutupi tunggakan angsuran yang masih terbuka.'
        );
    }

    /* =====================================================================
     | G2b — TUNGGAKAN PADA KONTRAK BERJALAN (kasus utama, bukan tepi)
     ===================================================================== */

    public function test_tunggakan_pada_kontrak_berjalan_menghalangi_exit(): void
    {
        $anggota = $this->anggota();
        $this->simpananModal($anggota);

        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => 'PMB-'.$this->prefix.'-B',
            'anggota_id' => $anggota->id,
            'akun_id' => (int) (Coa::query()->value('id') ?? 1),
            'akad_pembiayaan' => 'Murabahah',
            'jumlah_pembiayaan' => 1000000.0,
            'total_pembiayaan' => 1200000.0,
            'total_ujroh' => 200000.0,
            'sisa_pembiayaan' => 1000000.0,
            'tenor' => 10,
            'jumlah_angsuran' => 120000.0,
            'tanggal_akad' => now()->subMonths(3)->toDateString(),
            'tanggal_jatuh_tempo' => now()->subMonth()->toDateString(),
            'status_pembiayaan' => Pembiayaan::STATUS_AKTIF,
        ]);

        // Satu angsuran lewat jatuh tempo & belum dibayar.
        DB::table('angsuran')->insert([
            'pembiayaan_id' => $pembiayaan->id,
            'angsuran_ke' => 1,
            'tanggal_jatuh_tempo' => now()->subDays(20)->toDateString(),
            'pokok_angsuran' => 100000.0,
            'margin_ujroh' => 20000.0,
            'jumlah_angsuran' => 120000.0,
            'sisa_pokok' => 900000.0,
            'status_angsuran' => 'Terlambat',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $kendala = $this->service()->kendalaExit($anggota);

        $this->assertNotEmpty($kendala, 'Tunggakan angsuran wajib menjadi kendala exit.');

        // Pertahanan berlapis: pada kontrak BERJALAN, kendalaAngsuran memang
        // ikut menyala bersama kendalaPembiayaan (NPF > 0).
        $this->assertNotEmpty(
            array_filter($kendala, fn (string $baris) => str_contains($baris, 'angsuran yang belum dibayar')),
            'Gerbang kendalaAngsuran wajib menyala untuk kontrak berjalan yang menunggak. '
            .'Kendala aktual: '.implode(' | ', $kendala)
        );

        $this->assertFalse(
            $this->service()->layakExit($anggota),
            'Tunggakan pada kontrak berjalan wajib memblokir exit.'
        );
    }

    /* =====================================================================
     | G3 — A-07: EKSEKUSI WAJIB MEMAKAI SALDO SAAT EKSEKUSI
     ===================================================================== */

    public function test_pencairan_exit_memakai_saldo_terbaru_bukan_pratinjau(): void
    {
        $anggota = $this->anggota();
        $this->simpananModal($anggota);

        $pengurus = $this->pengurus();

        $terminasi = $this->service()->ajukan($anggota, [
            'metode_pencairan' => MemberTermination::METODE_CASH,
            'tanggal_pengunduran_diri' => now()->toDateString(),
        ], $pengurus->id);

        // Manipulasi state: setelah pengajuan, saldo Simpanan Wajib anggota
        // berkurang Rp 50.000 (mis. penarikan modal disetujui).
        $coaWajib = Coa::where('kode_akun', KodeCoa::SIMPANAN_WAJIB_MODAL)->firstOrFail();
        $jurnalKoreksi = Jurnal::create([
            'no_jurnal' => 'UJI-'.$this->prefix.'-KOR',
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'UJI',
            'keterangan' => 'Simulasi pengurangan Simpanan Wajib',
            'total_debet' => 50000.0,
            'total_kredit' => 0,
            'is_balance' => true,
            'status' => 'Posted',
            'posted_at' => now(),
        ]);
        JurnalDetail::create([
            'jurnal_id' => $jurnalKoreksi->id,
            'coa_id' => $coaWajib->id,
            'anggota_id' => $anggota->id,
            'baris' => 1,
            'debet' => 50000.0,
            'kredit' => 0,
            'keterangan' => 'Simulasi pengurangan',
        ]);

        $terminasi->refresh();

        // Pratinjau lama harus masih tercatat Rp 250.000.
        $this->assertSame(
            250000.0,
            (float) $terminasi->total_net,
            'Prasyarat: pratinjau pengajuan dihitung dari saldo sebelum perubahan.'
        );

        if ($terminasi->status === MemberTermination::STATUS_MENUNGGU_APPROVAL) {
            $this->service()->setujui($terminasi, 'Disetujui untuk pengujian', $pengurus->id);
            $terminasi->refresh();
        }

        $this->service()->eksekusi($terminasi, MemberTermination::METODE_CASH, null, null, $pengurus->id);

        $terminasi->refresh();

        $this->assertSame(
            200000.0,
            (float) $terminasi->total_dikembalikan,
            'A-07: pencairan wajib memakai saldo SAAT EKSEKUSI (Rp 200.000), '
            .'bukan pratinjau lama (Rp 250.000) — selisih Rp 50.000 adalah dana koperasi.'
        );
    }
}
