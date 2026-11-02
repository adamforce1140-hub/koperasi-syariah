<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\JenisSimpanan;
use App\Models\MandatorySavingsBill;
use App\Models\Rekening;
use App\Support\Setting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * JOB BULANAN JATUH TEMPO SIMPANAN WAJIB (`wajib-dues:process`).
 *
 * Berkas ini mengunci spesifikasi BAGIAN 3 poin 1 end-to-end:
 *  1. Command `wajib-dues:process` menerbitkan tagihan untuk SELURUH anggota
 *     ACTIVE pada awal bulan — nominal 100% DINAMIS dari master produk
 *     Simpanan Wajib (`jenis_simpanans.min_deposit_amount`), bukan hardcode.
 *  2. Status awal tagihan baru WAJIB 'UNPAID'.
 *  3. IDEMPOTEN: pemanggilan berulang pada periode yang sama TIDAK PERNAH
 *     menghasilkan tagihan ganda (`firstOrCreate` member+periode).
 *  4. Anggota NON-ACTIVE (PENDING/SUSPENDED/INACTIVE/TERMINATED) TIDAK ditagih.
 *  5. `--dry-run` tidak mengubah data apa pun.
 *  6. Command terdaftar di scheduler: `monthlyOn(1)` — dijalankan setiap
 *     tanggal 1 pukul 00:01 WIB.
 *  7. Perubahan nominal produk langsung berlaku tanpa deploy ulang.
 *
 * Basis data dev memuat data nyata → prefix unik + DatabaseTransactions,
 * sehingga pengujian deterministik dan tidak mengganggu data produksi.
 */
class ProcessWajibDuesJobTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'WJBDUE'.strtoupper(substr(uniqid(), -6));

        JenisSimpanan::flush();
        Setting::flush();
    }

    protected function tearDown(): void
    {
        JenisSimpanan::flush();
        Setting::flush();

        parent::tearDown();
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    private function akunId(): int
    {
        $id = Akun::where('kode_akun', '1133')->value('id') ?? Akun::query()->value('id');

        if (! $id) {
            $this->markTestSkipped('Tabel akun kosong — jalankan AkunSeeder.');
        }

        return (int) $id;
    }

    private function anggota(
        string $label,
        string $status,
        string $tanggalGabung = '2020-01-01',
    ): Anggota {
        return Anggota::create([
            'no_anggota' => substr($this->prefix.strtoupper($label), 0, 20),
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Uji Dues '.$label,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Alamat Uji Wajib Dues',
            'kota' => 'Jakarta',
            'provinsi' => 'DKI Jakarta',
            'status_anggota' => $status,
            'tanggal_gabung' => $tanggalGabung,
        ]);
    }

    private function rekening(Anggota|int $anggota, string $jenis, float $saldo = 0): Rekening
    {
        $anggotaId = $anggota instanceof Anggota ? $anggota->id : $anggota;

        return Rekening::create([
            'no_rekening' => 'RKN-'.strtoupper(substr(uniqid(), -9)),
            'anggota_id' => $anggotaId,
            'akun_id' => $this->akunId(),
            'jenis_rekening' => $jenis,
            'akad' => 'Wadiah',
            'saldo' => $saldo,
            'batas_minimum' => 0,
            'tanggal_buka' => now()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);
    }

    /* =====================================================================
     | 1. PENERBITAN TAGIHAN BULANAN
     |===================================================================== */

    public function test_job_menerbitkan_tagihan_unpaid_dengan_nominal_dinamis_dari_produk(): void
    {
        $this->akunId();

        $nominalProduk = JenisSimpanan::minimalSetoranWajib(0);

        if ($nominalProduk <= 0) {
            $this->markTestSkipped('Master produk Simpanan Wajib belum dikonfigurasi (min_deposit_amount).');
        }

        $anggota = $this->anggota('AKTIF', Anggota::STATUS_ACTIVE);

        $exitCode = Artisan::call('wajib-dues:process');

        $this->assertSame(0, $exitCode, 'Command wajib-dues:process harus berakhir sukses.');

        // Periode berjalan (bulan ini) dengan format 'YYYY-MM'.
        $periode = MandatorySavingsBill::periodDari(now());

        $bill = MandatorySavingsBill::query()
            ->where('member_id', $anggota->id)
            ->where('bill_period', $periode)
            ->first();

        $this->assertNotNull($bill, 'Tagihan Simpanan Wajib bulanan tidak diterbitkan untuk anggota ACTIVE.');
        $this->assertSame(MandatorySavingsBill::STATUS_UNPAID, $bill->status);
        // Nominal WAJIB persis dari master produk (Zero-Hardcoding).
        $this->assertSame((float) $nominalProduk, round((float) $bill->amount, 2));
    }

    public function test_nominal_tagihan_mengikuti_perubahan_konfigurasi_produk(): void
    {
        $this->akunId();

        // Ubah nominal produk secara DINAMIS di dalam transaksi (auto-rollback).
        $target = 123000;

        JenisSimpanan::query()
            ->where('nama', 'Simpanan Wajib')
            ->orWhere('code', JenisSimpanan::CODE_WAJIB)
            ->update(['min_deposit_amount' => $target]);

        JenisSimpanan::flush();

        $this->assertSame($target, JenisSimpanan::minimalSetoranWajib(0));

        $anggota = $this->anggota('DINAMIS', Anggota::STATUS_ACTIVE);

        Artisan::call('wajib-dues:process');

        $periode = MandatorySavingsBill::periodDari(now());

        $bill = MandatorySavingsBill::query()
            ->where('member_id', $anggota->id)
            ->where('bill_period', $periode)
            ->first();

        $this->assertNotNull($bill);
        $this->assertSame((float) $target, round((float) $bill->amount, 2));
    }

    public function test_job_idempoten_tidak_menghasilkan_tagihan_ganda(): void
    {
        $this->akunId();

        $anggota = $this->anggota('IDEMPOTEN', Anggota::STATUS_ACTIVE);

        Artisan::call('wajib-dues:process');
        Artisan::call('wajib-dues:process');
        Artisan::call('wajib-dues:process');

        $periode = MandatorySavingsBill::periodDari(now());

        $jumlah = MandatorySavingsBill::query()
            ->where('member_id', $anggota->id)
            ->where('bill_period', $periode)
            ->count();

        $this->assertSame(1, $jumlah, 'Pemanggilan berulang harus tetap menghasilkan SATU tagihan per periode.');
    }

    public function test_anggota_non_aktif_tidak_ditagih(): void
    {
        $this->akunId();

        $aktif = $this->anggota('ACTIVE', Anggota::STATUS_ACTIVE);
        $pending = $this->anggota('PENDING', Anggota::STATUS_PENDING);
        $suspended = $this->anggota('SUSPEND', Anggota::STATUS_SUSPENDED);
        $keluar = $this->anggota('KELUAR', Anggota::STATUS_TERMINATED);

        Artisan::call('wajib-dues:process');

        $periode = MandatorySavingsBill::periodDari(now());

        $this->assertTrue(
            MandatorySavingsBill::query()
                ->where('member_id', $aktif->id)->where('bill_period', $periode)->exists(),
            'Anggota ACTIVE harus ditagih.'
        );

        foreach ([$pending, $suspended, $keluar] as $nonAktif) {
            $this->assertFalse(
                MandatorySavingsBill::query()
                    ->where('member_id', $nonAktif->id)->where('bill_period', $periode)->exists(),
                sprintf('Anggota NON-ACTIVE (status %s) tidak boleh ditagih.', $nonAktif->status_anggota)
            );
        }
    }

    public function test_dry_run_tidak_mengubah_data(): void
    {
        $this->akunId();

        $anggota = $this->anggota('DRYRUN', Anggota::STATUS_ACTIVE);

        $periode = MandatorySavingsBill::periodDari(now());

        $sebelum = MandatorySavingsBill::query()
            ->where('member_id', $anggota->id)
            ->where('bill_period', $periode)
            ->count();

        $exitCode = Artisan::call('wajib-dues:process', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode);

        $sesudah = MandatorySavingsBill::query()
            ->where('member_id', $anggota->id)
            ->where('bill_period', $periode)
            ->count();

        $this->assertSame($sebelum, $sesudah, 'Dry-run tidak boleh menerbitkan tagihan.');
    }

    /* =====================================================================
     | 2. PENJADWALAN (SCHEDULER)
     |===================================================================== */

    public function test_command_terdaftar_dalam_scheduler_bulanan(): void
    {
        $events = collect(app(Schedule::class)->events());

        $match = $events->first(function ($event) {
            return str_contains($event->command ?? '', 'wajib-dues:process');
        });

        $this->assertNotNull($match, 'Command wajib-dues:process harus terdaftar di scheduler.');

        // monthlyOn(1, '00:01') → ekspresi cron "1 0 1 * *".
        $this->assertSame('1 0 1 * *', $match->expression);
    }

    /* =====================================================================
     | 3. PEMILIHAN JALUR (A DEFAULT MANUAL / B OPT-IN AUTO-DEBIT)
     |===================================================================== */

    public function test_jalur_a_default_manual_produk_tanpa_auto_debit(): void
    {
        $this->akunId();

        // Pastikan produk Simpanan Wajib TIDAK mengizinkan auto-debit.
        JenisSimpanan::query()
            ->where('nama', 'Simpanan Wajib')
            ->orWhere('code', JenisSimpanan::CODE_WAJIB)
            ->update(['allow_auto_debit' => false]);

        JenisSimpanan::flush();

        $anggota = $this->anggota('JALURA', Anggota::STATUS_ACTIVE);
        $this->rekening($anggota, 'Simpanan Sukarela', 1000000);

        Artisan::call('wajib-dues:process');

        $periode = MandatorySavingsBill::periodDari(now());

        $bill = MandatorySavingsBill::query()
            ->where('member_id', $anggota->id)
            ->where('bill_period', $periode)
            ->first();

        $this->assertNotNull($bill);
        // JALUR A: tagihan dibiarkan UNPAID (menunggu pembayaran manual Teller).
        $this->assertSame(MandatorySavingsBill::STATUS_UNPAID, $bill->status);
    }
}
