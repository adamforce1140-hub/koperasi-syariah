<?php

namespace Tests\Feature;

use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\Rekening;
use App\Support\Coa as KodeCoa;
use Database\Seeders\RekeningSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regresi #26/#2 — saldo awal simpanan yang ditanam `RekeningSeeder` WAJIB
 * punya pasangan di buku besar.
 *
 * Tanpa jurnal saldo awal, `rekening.saldo` (subledger) lebih besar daripada
 * saldo akun kontrol 2-1001 / 3-1001 / 3-1002 sehingga laporan tidak
 * direkonsiliasi (`simpanan:audit-subledger` melaporkan selisih).
 */
class RekeningSeederJurnalSaldoAwalTest extends TestCase
{
    use DatabaseTransactions;

    private const NO_JURNAL = 'JRN-SALDO-AWAL-REK';

    private const NOMOR_REKENING = [
        'REK-0001-01', 'REK-0001-02', 'REK-0001-03',
        'REK-0002-01', 'REK-0002-02',
        'REK-0003-01', 'REK-0003-02',
        'REK-0004-01', 'REK-0004-02',
    ];

    private function jalankanSeeder(): void
    {
        // Seeder hanya menyentuh 9 rekening tetap anggota 1–4 (idempoten),
        // jadi aman dijalankan di atas basis data pengembangan.
        (new RekeningSeeder)->run();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Pastikan seeder benar-benar membuat jurnal pada uji ini, bukan
        // melewatinya karena jurnal sudah ada di basis data pengembangan.
        // Seluruh perubahan dibatalkan `DatabaseTransactions`.
        $lama = Jurnal::where('no_jurnal', self::NO_JURNAL)->pluck('id');
        if ($lama->isNotEmpty()) {
            JurnalDetail::whereIn('jurnal_id', $lama)->delete();
            Jurnal::whereIn('id', $lama)->forceDelete();
        }
    }

    private function jurnal(): ?Jurnal
    {
        return Jurnal::where('no_jurnal', self::NO_JURNAL)->first();
    }

    private function baris(): Collection
    {
        $jurnal = $this->jurnal();

        return $jurnal
            ? JurnalDetail::where('jurnal_id', $jurnal->id)->orderBy('baris')->get()
            : collect();
    }

    public function test_saldo_awal_rekening_memiliki_jurnal_penyeimbang(): void
    {
        $this->jalankanSeeder();

        $jurnal = $this->jurnal();
        $this->assertNotNull($jurnal, 'Jurnal saldo awal simpanan harus dibuat.');
        $this->assertSame('Posted', $jurnal->status);
        $this->assertTrue((bool) $jurnal->is_balance);

        $totalSaldo = (float) Rekening::whereIn('no_rekening', self::NOMOR_REKENING)->sum('saldo');
        $this->assertEqualsWithDelta($totalSaldo, (float) $jurnal->total_debet, 0.01);
        $this->assertEqualsWithDelta($totalSaldo, (float) $jurnal->total_kredit, 0.01);
    }

    public function test_baris_kas_tidak_diatribusikan_ke_rekening_anggota(): void
    {
        $this->jalankanSeeder();

        $kasId = DB::table('coas')->where('kode_akun', KodeCoa::KAS_TELLER)->value('id');
        $kas = $this->baris()->firstWhere('coa_id', $kasId);

        $this->assertNotNull($kas, 'Baris Debet Kas Teller harus ada.');
        $this->assertNull($kas->rekening_id);
        $this->assertNull($kas->anggota_id);
        $this->assertGreaterThan(0, (float) $kas->debet);
    }

    public function test_setiap_rekening_saldo_awal_punya_baris_kredit_dengan_atribusi_lengkap(): void
    {
        $this->jalankanSeeder();

        /* `debet` di-cast `decimal:2` menjadi string "0.00", bukan NULL,
         | sehingga baris kredit disaring lewat nilai kreditnya. */
        $baris = $this->baris()->where('kredit', '>', 0)->values();
        $rekening = Rekening::whereIn('no_rekening', self::NOMOR_REKENING)
            ->where('saldo', '>', 0)
            ->orderBy('id')
            ->get();

        $this->assertCount($rekening->count(), $baris);

        foreach ($rekening as $i => $rek) {
            $row = $baris[$i];

            $this->assertSame($rek->id, $row->rekening_id, "Rekening {$rek->no_rekening} harus tertaut.");
            $this->assertSame($rek->anggota_id, $row->anggota_id);
            $this->assertEqualsWithDelta((float) $rek->saldo, (float) $row->kredit, 0.01);

            $kodeCoa = [
                'Simpanan Pokok' => KodeCoa::SIMPANAN_POKOK,
                'Simpanan Wajib' => KodeCoa::SIMPANAN_WAJIB_MODAL,
                'Simpanan Sukarela' => KodeCoa::SIMPANAN_SUKARELA,
            ][$rek->jenis_rekening];

            $this->assertSame(
                DB::table('coas')->where('kode_akun', $kodeCoa)->value('id'),
                $row->coa_id,
                "Baris {$rek->no_rekening} harus mengkredit akun {$kodeCoa}."
            );
        }
    }

    public function test_seeder_idempoten_tidak_menggandakan_jurnal(): void
    {
        $this->jalankanSeeder();
        $jumlahBaris = $this->baris()->count();
        $total = (float) $this->jurnal()->total_debet;

        $this->jalankanSeeder();

        $this->assertSame(1, Jurnal::where('no_jurnal', self::NO_JURNAL)->count());
        $this->assertSame($jumlahBaris, $this->baris()->count());
        $this->assertEqualsWithDelta($total, (float) $this->jurnal()->total_debet, 0.01);
    }
}
