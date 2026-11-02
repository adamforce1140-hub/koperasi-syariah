<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Coa;
use App\Models\JenisSimpanan;
use App\Models\MandatorySavingsBill;
use App\Models\Rekening;
use App\Models\SimpananWajibTindakan;
use App\Services\MandatorySavingsService;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * BATCH 8 — Kebijakan "TIDAK ADA AUTO-DEBIT TANPA KONFIRMASI PENGGUNA".
 *
 * Kebijakan tetap sistem ini menuntut: saldo anggota TIDAK BOLEH dipotong
 * otomatis pada jalur pemotongan LANGSUNG. Setiap potongan wajib melewati
 * usulan (AutodebitUsulan) + konfirmasi manusia.
 *
 * Jalur aman yang sudah teruji di AutodebitKonfirmasiTest:
 *   scheduler → usulan (tanpa potong) → konfirmasi → potong.
 *
 * Berkas ini menembak DUA jalur pemotongan langsung yang diduga tidak
 * melewati kebijakan tersebut:
 *   C1 — MandatorySavingsService::autoDebit()
 *   C2 — MandatorySavingsService::bayarCicilan() (endpoint teller)
 */
class IntegritasKebijakanAutodebitTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = strtoupper(substr(uniqid(), -8));
    }

    private function service(): MandatorySavingsService
    {
        return app(MandatorySavingsService::class);
    }

    private function anggota(): Anggota
    {
        return Anggota::create([
            'no_anggota' => 'AGT-'.$this->prefix,
            'nik' => str_pad((string) hexdec(substr(md5($this->prefix), 0, 12)), 16, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Uji Autodebit '.$this->prefix,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Pengujian No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_ACTIVE,
            'tanggal_gabung' => '2020-01-01',
            'is_auto_debit' => true,
        ]);
    }

    /** Rekening Simpanan Sukarela Aktif dengan saldo tertentu. */
    private function rekeningSukarela(Anggota $anggota, float $saldo = 1000000.0): Rekening
    {
        return Rekening::create([
            'anggota_id' => $anggota->id,
            'akun_id' => (int) (Coa::query()->value('id') ?? 1),
            'no_rekening' => 'SUK-'.$this->prefix,
            'jenis_rekening' => JenisSimpanan::namaRekening(JenisSimpanan::TIPE_SUKARELA)[0],
            'saldo' => $saldo,
            'status_rekening' => 'Aktif',
            'tanggal_buka' => '2020-01-01',
        ]);
    }

    /* =====================================================================
     | C1 — autoDebit() memotong saldo walau kebijakan konfirmasi aktif
     ===================================================================== */

    public function test_auto_debit_langsung_tidak_memotong_saldo_saat_kebijakan_konfirmasi_aktif(): void
    {
        $this->assertTrue(
            Setting::autodebitWajibKonfirmasi(),
            'Prasyarat uji: kebijakan konfirmasi manual harus AKTIF. '
            .'Bila ternyata default-nya nonaktif, test ini perlu disesuaikan.'
        );

        $anggota = $this->anggota();
        $rekening = $this->rekeningSukarela($anggota, 1000000.0);

        $tagihan = MandatorySavingsBill::create([
            'member_id' => $anggota->id,
            'bill_period' => '2026-01',
            'amount' => 200000.0,
            'status' => MandatorySavingsBill::STATUS_UNPAID,
        ]);

        $hasil = $this->service()->autoDebit($tagihan, $anggota);

        $rekening->refresh();

        $this->assertSame(
            1000000.0,
            (float) $rekening->saldo,
            'C1: saldo Simpanan Sukarela TIDAK BOLEH terpotong oleh pemanggilan '
            .'langsung autoDebit() selama konfirmasi pengguna belum ada. '
            .'Selisih yang terpotong adalah dana anggota yang diambil tanpa persetujuan.'
        );

        $this->assertFalse(
            $hasil,
            'C1: autoDebit() langsung tidak boleh melaporkan keberhasilan '
            .'ketika kebijakan konfirmasi manual aktif.'
        );
    }

    /* =====================================================================
     | C2 — bayarCicilan() (endpoint teller) memotong tanpa cek is_auto_debit
     ===================================================================== */

    public function test_cicilan_teller_tidak_memotong_saldo_anggota_yang_menolak_autodebit(): void
    {
        $anggota = $this->anggota();
        $anggota->update(['is_auto_debit' => false]);

        $rekening = $this->rekeningSukarela($anggota, 1000000.0);

        $tagihan = MandatorySavingsBill::create([
            'member_id' => $anggota->id,
            'bill_period' => '2026-02',
            'amount' => 200000.0,
            'status' => MandatorySavingsBill::STATUS_OVERDUE,
        ]);

        // Jadwal bertahap wajib ada agar bayarCicilan() mau jalan.
        SimpananWajibTindakan::create([
            'bill_id' => $tagihan->id,
            'member_id' => $anggota->id,
            'jenis' => SimpananWajibTindakan::JENIS_JADWAL_BERTAHAP,
            'nominal' => 200000.0,
            'nominal_cicilan' => 100000.0,
            'status_hasil' => SimpananWajibTindakan::STATUS_INFO,
            'waktu_tindakan' => now(),
        ]);

        $hasil = $this->service()->bayarCicilan($tagihan);

        $rekening->refresh();

        $this->assertSame(
            1000000.0,
            (float) $rekening->saldo,
            'C2: saldo TIDAK BOLEH terpotong. Jalur cicilan teller tidak memanggil '
            .'bolehAutoDebit() sehingga anggota yang menolak auto-debit tetap '
            .'dipotong — melanggar kebijakan tanpa konfirmasi pengguna.'
        );

        $this->assertFalse(
            $hasil['ok'] ?? false,
            'C2: cicilan tidak boleh dipotong dari saldo anggota yang '
            .'is_auto_debit = false (menolak potong otomatis).'
        );
    }
}
