<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\MandatorySavingsBill;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\MandatorySavingsService;
use App\Support\Coa as KodeCoa;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * GERBANG KEPATUHAN PSAK 101/108 — `psak:validasi-simpanan-wajib`
 * (Tahap 12 / R4–R5).
 *
 * Kontrak yang dikunci:
 *  1. Pada data sehat, command LULUS (exit 0) — M4.2.
 *  2. Kredit ke akun berkategori Kewajiban terdeteksi — M4.1.
 *  3. Tagihan PAID tanpa jurnal / tanpa transaksi terdeteksi — M4.3.
 *  4. Nominal tagihan ≠ kredit jurnal terdeteksi — M4.3.
 *  5. Exit code 0/1 benar sesuai kondisi — M4.5.
 *  6. Command hanya MEMBACA — tidak menulis/mengubah data.
 */
class ValidasiKepatuhanSimpananWajibTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'PSAKWJB'.strtoupper(substr(uniqid(), -6));
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    private function coa(string $kode, string $nama, string $kategori, string $klasifikasi): Coa
    {
        return Coa::firstOrCreate(
            ['kode_akun' => $kode],
            [
                'nama_akun' => $nama,
                'kategori' => $kategori,
                'klasifikasi_neraca' => $klasifikasi,
                'tipe_saldo_normal' => in_array($kode[0], ['1', '5'], true) ? 'Debet' : 'Kredit',
                'is_active' => true,
            ]
        );
    }

    /** Akun kanonik Simpanan Wajib (modal anggota, PSAK 101) — WAJIB ada. */
    private function akunModalWajib(): Coa
    {
        $kode = app(MandatorySavingsService::class)->akunSimpananWajib();
        $coa = Coa::findByKode($kode);

        if (! $coa) {
            $this->markTestSkipped("CoA {$kode} belum tersedia — jalankan CoaSeeder.");
        }

        return $coa;
    }

    private function akunKas(): Coa
    {
        return $this->coa(KodeCoa::KAS_TELLER, 'Kas Teller', 'Aset', 'Aset Lancar');
    }

    /** Akun berkategori Kewajiban — untuk simulasi penyimpangan PSAK 101 ¶ 31. */
    private function akunKewajiban(): Coa
    {
        return $this->coa(
            '2-9001',
            'Simpanan Wajib (SALAH — Kewajiban) '.$this->prefix,
            'Kewajiban',
            'Kewajiban Jangka Pendek'
        );
    }

    private function anggota(): Anggota
    {
        return Anggota::create([
            'no_anggota' => 'AGT-'.$this->prefix,
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota PSAK '.$this->prefix,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Alamat Uji Validasi PSAK',
            'kota' => 'Jakarta',
            'provinsi' => 'DKI Jakarta',
            'status_anggota' => 'ACTIVE',
            'is_auto_debit' => false,
            'tanggal_gabung' => '2026-01-01',
        ]);
    }

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

    /**
     * Membangun pasangan jurnal + transaksi + tagihan PAID.
     *
     * @param  Coa  $akunKredit  Akun yang dikredit (sisi modal anggota).
     * @param  float  $nominalJurnal  Nominal yang dikredit ke $akunKredit.
     * @param  float|null  $nominalTagihan  Nominal tagihan (default = nominal jurnal).
     * @param  bool  $denganJurnal  false → tagihan PAID tanpa jurnal/transaksi.
     */
    private function tagihanPaid(
        Anggota $anggota,
        string $periode,
        Coa $akunKredit,
        float $nominalJurnal,
        ?float $nominalTagihan = null,
        bool $denganJurnal = true
    ): MandatorySavingsBill {
        $nominalTagihan ??= $nominalJurnal;

        if (! $denganJurnal) {
            return MandatorySavingsBill::create([
                'member_id' => $anggota->id,
                'bill_period' => $periode,
                'amount' => $nominalTagihan,
                'status' => MandatorySavingsBill::STATUS_PAID,
                'paid_at' => now(),
            ]);
        }

        $kas = $this->akunKas();

        $transaksi = Transaksi::create([
            'no_transaksi' => 'TRX-PSAK-'.$this->prefix.'-'.$periode,
            'waktu_transaksi' => now(),
            'anggota_id' => $anggota->id,
            'jenis_transaksi' => 'Setoran Simpanan Wajib',
            'posisi' => 'Debet',
            'jumlah' => $nominalJurnal,
            'saldo_setelah' => $nominalJurnal,
            'user_id' => $this->user()->id,
            'sumber_transaksi' => 'Teller',
            'status_transaksi' => 'Sukses',
            'status' => 'Sukses',
        ]);

        $jurnal = Jurnal::create([
            'no_jurnal' => 'JRN-PSAK-'.$this->prefix.'-'.$periode,
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Otomatis',
            'transaksi_id' => $transaksi->id,
            'sumber' => 'Setoran Simpanan Wajib (Uji PSAK)',
            'keterangan' => 'Uji kepatuhan PSAK 101',
            'status' => 'Draft',
            'user_id' => $this->user()->id,
        ]);

        JurnalDetail::create([
            'jurnal_id' => $jurnal->id,
            'coa_id' => $kas->id,
            'baris' => 1,
            'debet' => $nominalJurnal,
            'kredit' => 0,
            'keterangan' => 'Kas Teller',
        ]);

        JurnalDetail::create([
            'jurnal_id' => $jurnal->id,
            'coa_id' => $akunKredit->id,
            'baris' => 2,
            'debet' => 0,
            'kredit' => $nominalJurnal,
            'keterangan' => 'Simpanan Wajib',
        ]);

        $jurnal->update([
            'status' => 'Posted',
            'posted_at' => now(),
            'total_debet' => $nominalJurnal,
            'total_kredit' => $nominalJurnal,
            'is_balance' => true,
        ]);

        return MandatorySavingsBill::create([
            'member_id' => $anggota->id,
            'bill_period' => $periode,
            'amount' => $nominalTagihan,
            'status' => MandatorySavingsBill::STATUS_PAID,
            'paid_at' => now(),
            'transaksi_id' => $transaksi->id,
            'jurnal_id' => $jurnal->id,
        ]);
    }

    /* =====================================================================
     | 1. EXIT CODE & DATA SEHAT (M4.2, M4.5)
     |===================================================================== */

    public function test_lulus_pada_data_sehat_dan_tidak_mengubah_data(): void
    {
        $anggota = $this->anggota();
        $this->tagihanPaid($anggota, '2026-05', $this->akunModalWajib(), 50000);

        $tagihan = MandatorySavingsBill::where('member_id', $anggota->id)->firstOrFail();
        $sebelum = (array) DB::table('mandatory_savings_bills')->where('id', $tagihan->id)->first();
        $jumlahBillSebelum = DB::table('mandatory_savings_bills')->count();

        $this->artisan('psak:validasi-simpanan-wajib')
            ->assertExitCode(0);

        // Command bersifat READ-ONLY: tidak ada baris yang berubah/bertambah.
        $sesudah = (array) DB::table('mandatory_savings_bills')->where('id', $tagihan->id)->first();
        $this->assertSame($sebelum, $sesudah, 'Command validasi tidak boleh mengubah data.');
        $this->assertSame($jumlahBillSebelum, DB::table('mandatory_savings_bills')->count());
    }

    public function test_exit_code_gagal_bila_ada_tagihan_paid_tanpa_jurnal(): void
    {
        $anggota = $this->anggota();
        $this->tagihanPaid($anggota, '2026-06', $this->akunModalWajib(), 50000, null, false);

        $this->artisan('psak:validasi-simpanan-wajib')
            ->expectsOutputToContain('TAGIHAN_LUNAS_TANPA_JURNAL')
            ->assertExitCode(1);
    }

    /* =====================================================================
     | 2. PENYIMPANGAN PENYAJIAN AKUN (M4.1 — PSAK 101 ¶ 31)
     |===================================================================== */

    public function test_mendeteksi_kredit_ke_akun_kewajiban(): void
    {
        $anggota = $this->anggota();
        $this->tagihanPaid($anggota, '2026-07', $this->akunKewajiban(), 50000);

        $this->artisan('psak:validasi-simpanan-wajib')
            ->expectsOutputToContain('AKUN_SIMPANAN_WAJIB_KEWAJIBAN')
            ->assertExitCode(1);
    }

    public function test_tidak_melaporkan_kewajiban_bila_tidak_ada_penyimpangan(): void
    {
        $anggota = $this->anggota();
        $this->tagihanPaid($anggota, '2026-08', $this->akunModalWajib(), 50000);

        // Jurnal sehat mengkredit akun Ekuitas → tidak boleh muncul sebagai temuan.
        $kewajiban = DB::table('jurnal_detail as d')
            ->join('jurnal as j', 'j.id', '=', 'd.jurnal_id')
            ->join('coas as c', 'c.id', '=', 'd.coa_id')
            ->where('j.transaksi_id', function ($q) use ($anggota) {
                $q->select('transaksi_id')->from('mandatory_savings_bills')
                    ->where('member_id', $anggota->id)->limit(1);
            })
            ->where('c.kategori', 'Kewajiban')
            ->count();

        $this->assertSame(0, $kewajiban, 'Jurnal sehat tidak boleh menyentuh akun Kewajiban.');
        $this->artisan('psak:validasi-simpanan-wajib')->assertExitCode(0);
    }

    /* =====================================================================
     | 3. INTEGRITAS NOMINAL TAGIHAN vs JURNAL (M4.3)
     |===================================================================== */

    public function test_mendeteksi_nominal_tagihan_tidak_sesuai_jurnal(): void
    {
        $anggota = $this->anggota();

        // Tagihan 150.000 tetapi jurnal hanya mengkredit 50.000 — persis pola
        // bug bundling setoran awal yang pernah ditemukan di seeder.
        $this->tagihanPaid($anggota, '2026-09', $this->akunModalWajib(), 50000, 150000);

        $this->artisan('psak:validasi-simpanan-wajib')
            ->expectsOutputToContain('NOMINAL_TAGIHAN_TIDAK_SESUAI')
            ->assertExitCode(1);
    }

    public function test_lolos_bila_nominal_tagihan_sama_dengan_jurnal(): void
    {
        $anggota = $this->anggota();
        $this->tagihanPaid($anggota, '2026-10', $this->akunModalWajib(), 50000, 50000);

        $this->artisan('psak:validasi-simpanan-wajib')->assertExitCode(0);
    }

    /* =====================================================================
     | 4. INTEGRITAS JURNAL & TAGIHAN
     |===================================================================== */

    public function test_mendeteksi_tagihan_paid_tanpa_transaksi(): void
    {
        $anggota = $this->anggota();
        $tagihan = $this->tagihanPaid($anggota, '2026-11', $this->akunModalWajib(), 50000);

        // Hapus relasi transaksi/jurnal → tagihan PAID menggantung.
        DB::table('mandatory_savings_bills')->where('id', $tagihan->id)->update([
            'transaksi_id' => null,
            'jurnal_id' => null,
        ]);

        $this->artisan('psak:validasi-simpanan-wajib')->assertExitCode(1);
    }

    public function test_mendeteksi_jurnal_tidak_berimbang(): void
    {
        $anggota = $this->anggota();
        $tagihan = $this->tagihanPaid($anggota, '2026-12', $this->akunModalWajib(), 50000);

        // Rusak sisi kredit agar jurnal menjadi tidak berimbang (debet ≠ kredit).
        DB::table('jurnal_detail')
            ->where('jurnal_id', $tagihan->jurnal_id)
            ->where('coa_id', $this->akunModalWajib()->id)
            ->update(['kredit' => 30000]);

        DB::table('jurnal')->where('id', $tagihan->jurnal_id)->update([
            'total_kredit' => 30000,
            'is_balance' => false,
        ]);

        $this->artisan('psak:validasi-simpanan-wajib')
            ->expectsOutputToContain('JURNAL_TIDAK_BERIMBANG')
            ->assertExitCode(1);
    }

    public function test_indeks_unik_periode_mencegah_tagihan_duplikat(): void
    {
        $anggota = $this->anggota();
        $this->tagihanPaid($anggota, '2026-04', $this->akunModalWajib(), 50000);

        // Detektor TAGIHAN_DUPLIKAT_PERIODE bersifat DEFENSIF: skema sudah
        // menjamin satu tagihan per (anggota, periode) via indeks UNIQUE.
        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('mandatory_savings_bills')->insert([
            'member_id' => $anggota->id,
            'bill_period' => '2026-04',
            'amount' => 50000,
            'status' => MandatorySavingsBill::STATUS_UNPAID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /* =====================================================================
     | 5. OPSI --contoh
     |===================================================================== */

    public function test_opsi_contoh_nol_tetap_menjalankan_validasi(): void
    {
        $anggota = $this->anggota();
        $this->tagihanPaid($anggota, '2026-03', $this->akunModalWajib(), 50000);

        $this->artisan('psak:validasi-simpanan-wajib', ['--contoh' => 0])
            ->assertExitCode(0);
    }
}
