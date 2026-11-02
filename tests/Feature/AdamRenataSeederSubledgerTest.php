<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Coa;
use App\Models\JenisSimpanan;
use App\Models\MandatorySavingsBill;
use App\Models\Rekening;
use Database\Seeders\AdamRenataSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Konsistensi subledger studi kasus Adam Renata (ANG-2026-0017).
 *
 * Regresi yang dikunci:
 *  1. Seeder membuka rekening Simpanan Pokok & Simpanan Wajib — bukan hanya
 *     Sukarela — sehingga setiap KREDIT 3-1001 / 3-1002 punya wadah subledger.
 *  2. Saldo rekening Pokok/Wajib sama persis dengan mutasi buku besar anggota.
 *  3. Baris jurnal bundling setoran awal teratribusi ke rekening yang benar.
 *  4. Saldo rekening Wajib mencerminkan seluruh tagihan yang sudah lunas
 *     (bundling + auto-debit), bukan hanya setoran awal.
 *  5. Seeder idempoten: eksekusi ulang tidak menggandakan saldo.
 */
class AdamRenataSeederSubledgerTest extends TestCase
{
    use DatabaseTransactions;

    private Anggota $anggota;

    protected function setUp(): void
    {
        parent::setUp();

        $anggota = Anggota::where('no_anggota', 'ANG-2026-0017')->first();

        if (! $anggota) {
            $this->markTestSkipped('Anggota ANG-2026-0017 belum tersedia — jalankan AnggotaSeeder.');
        }

        $this->anggota = $anggota;

        $this->seed(AdamRenataSeeder::class);
    }

    /** Saldo buku besar akun tertentu untuk satu anggota (orientasi kredit). */
    private function saldoGlAnggota(string $kodeCoa): float
    {
        $coa = Coa::findByKode($kodeCoa);

        if (! $coa) {
            return 0.0;
        }

        $mutasi = DB::table('jurnal_detail as d')
            ->join('jurnal as j', 'j.id', '=', 'd.jurnal_id')
            ->whereNull('j.deleted_at')
            ->where('j.status', 'Posted')
            ->where('d.anggota_id', $this->anggota->id)
            ->where('d.coa_id', $coa->id)
            ->selectRaw('COALESCE(SUM(d.debet), 0) AS debet, COALESCE(SUM(d.kredit), 0) AS kredit')
            ->first();

        return round((float) $mutasi->kredit - (float) $mutasi->debet, 2);
    }

    private function rekening(string $tipe): ?Rekening
    {
        return Rekening::where('anggota_id', $this->anggota->id)
            ->whereIn('jenis_rekening', JenisSimpanan::namaRekening($tipe))
            ->where('status_rekening', 'Aktif')
            ->orderBy('id')
            ->first();
    }

    public function test_seeder_membuka_rekening_pokok_dan_wajib(): void
    {
        $this->assertNotNull($this->rekening(JenisSimpanan::TIPE_POKOK), 'Rekening Simpanan Pokok tidak dibuka.');
        $this->assertNotNull($this->rekening(JenisSimpanan::TIPE_WAJIB), 'Rekening Simpanan Wajib tidak dibuka.');
    }

    public function test_saldo_rekening_sama_dengan_buku_besar(): void
    {
        $pokok = $this->rekening(JenisSimpanan::TIPE_POKOK);
        $wajib = $this->rekening(JenisSimpanan::TIPE_WAJIB);

        $this->assertSame(
            $this->saldoGlAnggota(\App\Support\Coa::SIMPANAN_POKOK),
            round((float) $pokok->saldo, 2),
            'Saldo rekening Pokok tidak sama dengan buku besar 3-1001.'
        );

        $this->assertSame(
            $this->saldoGlAnggota(\App\Support\Coa::SIMPANAN_WAJIB_MODAL),
            round((float) $wajib->saldo, 2),
            'Saldo rekening Wajib tidak sama dengan buku besar 3-1002.'
        );
    }

    public function test_jurnal_bundling_teratribusi_ke_rekening(): void
    {
        $jurnalId = MandatorySavingsBill::where('member_id', $this->anggota->id)
            ->orderBy('id')
            ->value('jurnal_id');

        $this->assertNotNull($jurnalId, 'Jurnal bundling setoran awal tidak tercatat.');

        foreach ([
            \App\Support\Coa::SIMPANAN_POKOK => $this->rekening(JenisSimpanan::TIPE_POKOK),
            \App\Support\Coa::SIMPANAN_WAJIB_MODAL => $this->rekening(JenisSimpanan::TIPE_WAJIB),
        ] as $kode => $rekening) {
            $coa = Coa::findByKode($kode);
            $this->assertNotNull($coa, "CoA {$kode} tidak tersedia.");

            $baris = DB::table('jurnal_detail')
                ->where('jurnal_id', $jurnalId)
                ->where('coa_id', $coa->id)
                ->first();

            $this->assertNotNull($baris, "Baris kredit {$kode} tidak ditemukan pada jurnal bundling.");
            $this->assertSame($rekening->id, (int) $baris->rekening_id, "Baris {$kode} tidak teratribusi ke rekening.");
        }
    }

    public function test_saldo_wajib_mencerminkan_seluruh_tagihan_lunas(): void
    {
        $wajib = $this->rekening(JenisSimpanan::TIPE_WAJIB);

        $totalLunas = (float) MandatorySavingsBill::where('member_id', $this->anggota->id)
            ->where('status', MandatorySavingsBill::STATUS_PAID)
            ->sum('amount');

        $this->assertGreaterThan(0, $totalLunas, 'Tidak ada tagihan Simpanan Wajib yang lunas.');
        $this->assertSame($totalLunas, round((float) $wajib->saldo, 2));

        // Porsi auto-debit benar-benar bertambah ke subledger, bukan hanya
        // setoran awal dari bundling.
        $totalAutoDebit = (float) MandatorySavingsBill::where('member_id', $this->anggota->id)
            ->where('status', MandatorySavingsBill::STATUS_PAID)
            ->where('payment_method', MandatorySavingsBill::METODE_AUTO_DEBIT)
            ->sum('amount');

        $this->assertGreaterThan(0, $totalAutoDebit, 'Riwayat auto-debit tidak terbentuk.');
        $this->assertSame(
            $totalLunas - $totalAutoDebit,
            round((float) $wajib->saldo - $totalAutoDebit, 2),
            'Saldo Wajib tidak memuat porsi auto-debit.'
        );
    }

    public function test_seeder_idempoten_tidak_menggandakan_saldo(): void
    {
        $wajibSebelum = round((float) $this->rekening(JenisSimpanan::TIPE_WAJIB)->saldo, 2);
        $pokokSebelum = round((float) $this->rekening(JenisSimpanan::TIPE_POKOK)->saldo, 2);
        $jurnalSebelum = MandatorySavingsBill::where('member_id', $this->anggota->id)->count();

        $this->seed(AdamRenataSeeder::class);

        $this->assertSame($wajibSebelum, round((float) $this->rekening(JenisSimpanan::TIPE_WAJIB)->refresh()->saldo, 2));
        $this->assertSame($pokokSebelum, round((float) $this->rekening(JenisSimpanan::TIPE_POKOK)->refresh()->saldo, 2));
        $this->assertSame($jurnalSebelum, MandatorySavingsBill::where('member_id', $this->anggota->id)->count());
    }
}
