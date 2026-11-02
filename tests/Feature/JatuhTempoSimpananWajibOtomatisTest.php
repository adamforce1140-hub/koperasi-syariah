<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\Coa;
use App\Models\KebijakanKoperasi;
use App\Models\MandatorySavingsBill;
use App\Services\MandatorySavingsService;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Pengujian menyeluruh penghapusan field "Tanggal Jatuh Tempo" (kebijakan)
 * dan pembuktian bahwa logika OTOMATIS tanggal jatuh tempo Simpanan Wajib
 * tetap berjalan benar untuk anggota lama maupun anggota baru.
 *
 * Jatuh tempo kini dihitung sebagai anniversary bulanan sejak anggota
 * bergabung aktif (MandatorySavingsService::tanggalJatuhTempo()), tanpa
 * parameter kebijakan apa pun.
 */
class JatuhTempoSimpananWajibOtomatisTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'JTTMP'.strtoupper(substr(uniqid(), -6));
    }

    /* =====================================================================
     | A. PENGHAPUSAN FIELD KONFIGURASI (MENYELURUH)
     |===================================================================== */

    public function test_kolom_kebijakan_simpanan_wajib_overdue_day_sudah_dihapus(): void
    {
        $this->assertFalse(
            Schema::hasColumn('kebijakan_koperasis', 'simpanan_wajib_overdue_day'),
            'Kolom simpanan_wajib_overdue_day harus sudah dihapus dari kebijakan_koperasis.'
        );
    }

    public function test_accessor_dan_field_model_sudah_dihapus(): void
    {
        $this->assertFalse(
            method_exists(Setting::class, 'simpananWajibOverdueDay'),
            'Accessor Setting::simpananWajibOverdueDay() harus dihapus.'
        );
        $this->assertFalse(
            method_exists(KebijakanKoperasi::class, 'simpananWajibOverdueDay'),
            'Accessor KebijakanKoperasi::simpananWajibOverdueDay() harus dihapus.'
        );
        $this->assertFalse(
            method_exists(MandatorySavingsService::class, 'jatuhTempoHari'),
            'Method MandatorySavingsService::jatuhTempoHari() harus dihapus.'
        );

        $kebijakan = new KebijakanKoperasi;
        $this->assertNotContains(
            'simpanan_wajib_overdue_day',
            $kebijakan->getFillable(),
            'Field simpanan_wajib_overdue_day harus keluar dari $fillable.'
        );
        $this->assertArrayNotHasKey(
            'simpanan_wajib_overdue_day',
            $kebijakan->getCasts(),
            'Field simpanan_wajib_overdue_day harus keluar dari $casts.'
        );
    }

    public function test_baris_kebijakan_tidak_lagi_memuat_atribut_jatuh_tempo(): void
    {
        $kebijakan = KebijakanKoperasi::getSetting();

        $this->assertFalse(
            array_key_exists('simpanan_wajib_overdue_day', $kebijakan->getAttributes()),
            'Baris kebijakan tidak boleh lagi memiliki atribut simpanan_wajib_overdue_day.'
        );
    }

    /* =====================================================================
     | B. LOGIKA OTOMATIS TANGGAL JATUH TEMPO
     |===================================================================== */

    public function test_tanggal_jatuh_tempo_mengikuti_anniversary_bulanan(): void
    {
        $service = $this->service();
        $anggota = $this->anggota('ANNIV', '2026-03-12');

        $this->assertSame(
            '2026-03-12',
            $service->tanggalJatuhTempo($anggota, Carbon::create(2026, 3, 1))->toDateString()
        );
        $this->assertSame(
            '2026-04-12',
            $service->tanggalJatuhTempo($anggota, Carbon::create(2026, 4, 1))->toDateString()
        );
        $this->assertSame(
            '2026-05-12',
            $service->tanggalJatuhTempo($anggota, Carbon::create(2026, 5, 1))->toDateString()
        );
    }

    public function test_tanggal_jatuh_tempo_di_clamp_untuk_bulan_lebih_pendek(): void
    {
        $service = $this->service();
        $anggota = $this->anggota('CLAMP', '2026-01-31');

        $this->assertSame(
            '2026-02-28',
            $service->tanggalJatuhTempo($anggota, Carbon::create(2026, 2, 1))->toDateString(),
            'Bulan lebih pendek harus di-clamp ke hari terakhir.'
        );
    }

    public function test_anggota_bergabung_akhir_bulan_memakai_akhir_bulan(): void
    {
        $service = $this->service();
        $anggota = $this->anggota('EOM', '2026-04-30');

        $this->assertSame(
            '2026-05-31',
            $service->tanggalJatuhTempo($anggota, Carbon::create(2026, 5, 1))->toDateString(),
            'Anggota akhir bulan → jatuh tempo mengikuti akhir bulan.'
        );
    }

    public function test_penandaan_overdue_mengikuti_jatuh_tempo_otomatis_per_anggota(): void
    {
        $this->pastikanCoa();
        $this->akunId();
        $service = $this->service();

        $anggotaA = $this->anggota('JTA', '2026-01-05'); // jatuh tempo tgl 5
        $anggotaB = $this->anggota('JTB', '2026-01-25'); // jatuh tempo tgl 25

        // Terbitkan tagihan periode 2026-02 untuk keduanya.
        $service->issueBill($anggotaA, '2026-02', Carbon::create(2026, 2, 1));
        $service->issueBill($anggotaB, '2026-02', Carbon::create(2026, 2, 1));

        $billA = MandatorySavingsBill::where('member_id', $anggotaA->id)->where('bill_period', '2026-02')->firstOrFail();
        $billB = MandatorySavingsBill::where('member_id', $anggotaB->id)->where('bill_period', '2026-02')->firstOrFail();

        // 10 Feb: A (tgl 5) lewat jatuh tempo; B (tgl 25) belum.
        $service->tandaiOverdueJatuhTempo(Carbon::create(2026, 2, 10));
        $this->assertSame(
            MandatorySavingsBill::STATUS_OVERDUE,
            $billA->refresh()->status,
            'Anggota bergabung tgl 5 harus OVERDUE setelah jatuh tempo tgl 5.'
        );
        $this->assertSame(
            MandatorySavingsBill::STATUS_UNPAID,
            $billB->refresh()->status,
            'Anggota bergabung tgl 25 belum OVERDUE sebelum jatuh tempo tgl 25.'
        );

        // 26 Feb: B kini juga lewat jatuh tempo.
        $service->tandaiOverdueJatuhTempo(Carbon::create(2026, 2, 26));
        $this->assertSame(MandatorySavingsBill::STATUS_OVERDUE, $billB->refresh()->status);
    }

    /* =====================================================================
     | C. ALUR PEMBAYARAN TETAP BERJALAN (ANGGOTA LAMA & BARU)
     |===================================================================== */

    public function test_pembayaran_tagihan_overdue_anggota_lama_tetap_berhasil(): void
    {
        $this->pastikanCoa();
        $this->akunId();
        $service = $this->service();

        // Anggota lama (bergabung bertahun-tahun lalu).
        $anggota = $this->anggota('LAMA', '2024-01-10');

        $bill = MandatorySavingsBill::create([
            'member_id' => $anggota->id,
            'bill_period' => '2026-02',
            'amount' => 50000,
            'status' => MandatorySavingsBill::STATUS_OVERDUE,
        ]);

        $this->assertTrue(
            $service->bayarViaTeller($bill, MandatorySavingsBill::METODE_CASH, Carbon::create(2026, 2, 20))
        );

        $bill->refresh();
        $this->assertSame(MandatorySavingsBill::STATUS_PAID, $bill->status);
        $this->assertNotNull($bill->paid_at, 'Tanggal bayar harus terisi setelah pelunasan.');
    }

    public function test_alur_pembayaran_anggota_baru_tanpa_error(): void
    {
        $this->pastikanCoa();
        $this->akunId();
        $service = $this->service();

        // Anggota baru bergabung tgl 3 → tagihan terbit pada proses tgl 3
        // (anniversary), belum OVERDUE karena tepat di tanggal jatuh tempo.
        $anggota = $this->anggota('BARU', '2026-02-03');

        $hasil = $service->processDaily(Carbon::create(2026, 2, 3));
        $this->assertGreaterThanOrEqual(
            0,
            $hasil['overdue'],
            'Proses harian harus mengembalikan agregat overdue tanpa error.'
        );

        $bill = MandatorySavingsBill::where('member_id', $anggota->id)
            ->where('bill_period', '2026-02')->firstOrFail();
        $this->assertSame(MandatorySavingsBill::STATUS_UNPAID, $bill->status);

        // Bayar tagihan tersebut tanpa gangguan.
        $this->assertTrue(
            $service->bayarViaTeller($bill, MandatorySavingsBill::METODE_CASH, Carbon::create(2026, 2, 5))
        );

        $this->assertSame(MandatorySavingsBill::STATUS_PAID, $bill->refresh()->status);
    }

    /* =====================================================================
     | HELPER
     |===================================================================== */

    private function service(): MandatorySavingsService
    {
        return app(MandatorySavingsService::class);
    }

    private function anggota(string $label, string $tanggalGabung, string $status = Anggota::STATUS_ACTIVE): Anggota
    {
        return Anggota::create([
            'no_anggota' => substr($this->prefix.strtoupper($label), 0, 20),
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Uji Jatuh Tempo '.$label,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Alamat Uji Jatuh Tempo',
            'kota' => 'Jakarta',
            'provinsi' => 'DKI Jakarta',
            'status_anggota' => $status,
            'is_auto_debit' => false,
            'tanggal_gabung' => $tanggalGabung,
        ]);
    }

    private function pastikanCoa(): void
    {
        if (! Coa::findByKode(MandatorySavingsService::COA_KAS_TELLER)) {
            $this->markTestSkipped('CoA '.MandatorySavingsService::COA_KAS_TELLER.' belum tersedia — jalankan CoaSeeder.');
        }
    }

    private function akunId(): int
    {
        $id = Akun::where('kode_akun', '1133')->value('id') ?? Akun::query()->value('id');

        if (! $id) {
            $this->markTestSkipped('Tabel akun kosong — jalankan seeder.');
        }

        return (int) $id;
    }
}
