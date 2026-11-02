<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\AutodebitUsulan;
use App\Models\Coa;
use App\Models\KebijakanKoperasi;
use App\Models\Pembiayaan;
use App\Models\Rekening;
use App\Models\TazirPenangguhan;
use App\Services\AngsuranTransactionService;
use App\Services\AutodebitService;
use App\Services\TazirService;
use App\Support\Coa as KodeCoa;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BATCH 9 — Kebijakan "TIDAK ADA AUTO-DEBIT TANPA KONFIRMASI PENGGUNA"
 * pada kanal ANGSURAN dan TA'ZIR.
 *
 * Batch 8 menutup kebocoran pada kanal SIMPANAN WAJIB. Doktrin Setting
 * menyebut SELURUH kanal auto-debit (Simpanan Wajib, Angsuran, Ta'zir)
 * DILARANG memotong saldo secara mandiri. Berkas ini membuktikan bahwa dua
 * kanal sisanya masih memotong:
 *
 *   D1 — AngsuranTransactionService::proses(metode AUTODEBET): dipanggil
 *        langsung (seeder, command, tinker) tanpa memeriksa kebijakan induk.
 *   D2 — TazirService::prosesAutodebetSusulan(): hanya memeriksa saklar
 *        fitur denda, TIDAK memeriksa kebijakan induk.
 *
 * Jalur yang SAH (harus tetap memotong setelah perbaikan):
 *   AutodebitService::konfirmasi() → kanal ANGSURAN/TAZIR.
 */
class IntegritasKebijakanAutodebitKanalTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'KD'.strtoupper(substr(uniqid(), -6));
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    private function pastikanCoa(): void
    {
        foreach ([
            KodeCoa::KAS_TELLER,
            KodeCoa::SIMPANAN_SUKARELA,
            KodeCoa::PINJAMAN_QARDH,
            KodeCoa::DANA_KEBAJIKAN,
        ] as $kode) {
            if (Coa::findByKode($kode) === null) {
                $this->markTestSkipped("CoA {$kode} belum tersedia pada basis data uji.");
            }
        }
    }

    private function anggota(bool $autoDebit = true): Anggota
    {
        return Anggota::create([
            'no_anggota' => 'AGT-'.$this->prefix,
            'nik' => '3271'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Uji Kanal '.$this->prefix,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji Kanal No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_ACTIVE,
            'tanggal_gabung' => '2020-01-01',
            'is_auto_debit' => $autoDebit,
        ]);
    }

    private function rekening(Anggota $anggota, float $saldo = 1000000.0): Rekening
    {
        return Rekening::create([
            'no_rekening' => 'RKN-'.$this->prefix,
            'anggota_id' => $anggota->id,
            'akun_id' => (int) Coa::query()->value('id'),
            'jenis_rekening' => 'Simpanan Sukarela',
            'akad' => 'Wadiah',
            'saldo' => $saldo,
            'batas_minimum' => 0,
            'tanggal_buka' => now()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);
    }

    /**
     * Pembiayaan Qardh berjalan dengan satu baris angsuran yang SUDAH jatuh
     * tempo (agar ta'zir punya dasar hitung).
     *
     * @return array{0: Pembiayaan, 1: Angsuran}
     */
    private function pembiayaan(Anggota $anggota, float $pokok = 125000.0): array
    {
        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => 'PMB-'.$this->prefix,
            'anggota_id' => $anggota->id,
            'akun_id' => Coa::query()->value('id') ?? 1,
            'akad_pembiayaan' => 'Qardh',
            'jumlah_pembiayaan' => $pokok,
            'total_pembiayaan' => $pokok,
            'total_ujroh' => 0,
            'sisa_pembiayaan' => $pokok,
            'tenor' => 1,
            'jumlah_angsuran' => $pokok,
            'tanggal_akad' => now()->subMonth()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonth()->toDateString(),
            'status_pembiayaan' => Pembiayaan::STATUS_AKTIF,
        ]);

        $angsuran = Angsuran::create([
            'pembiayaan_id' => $pembiayaan->id,
            'angsuran_ke' => 1,
            'tanggal_jatuh_tempo' => now()->subDays(10)->toDateString(),
            'pokok_angsuran' => $pokok,
            'margin_ujroh' => 0,
            'jumlah_angsuran' => $pokok,
            'sisa_pokok' => $pokok,
            'status_angsuran' => 'Terlambat',
        ]);

        return [$pembiayaan, $angsuran];
    }

    /** Aktifkan fitur denda (prasyarat sah sebelum ta'zir boleh dipotong). */
    private function aktifkanFiturDenda(): void
    {
        KebijakanKoperasi::query()->update([
            'financing_late_fee_per_day' => 5000,
            'tazir_mode' => 'FLAT_HARIAN',
            'tazir_grace_period_days' => 0,
        ]);
        Setting::flush();
    }

    /* =====================================================================
     | D1 — Kanal ANGSURAN: pemotongan langsung tanpa konfirmasi
     |===================================================================== */

    public function test_autodebet_angsuran_langsung_tidak_memotong_saldo_anggota(): void
    {
        $this->pastikanCoa();

        $this->assertTrue(
            Setting::autodebitWajibKonfirmasi(),
            'Prasyarat uji: kebijakan wajib konfirmasi harus AKTIF secara bawaan.'
        );

        $anggota = $this->anggota();
        $rekening = $this->rekening($anggota, 1000000.0);
        [, $angsuran] = $this->pembiayaan($anggota);

        $ditolak = false;

        try {
            app(AngsuranTransactionService::class)->proses($angsuran, [
                'jumlah_bayar' => 125000.0,
                'nominal_tazir' => 0,
                'metode_bayar' => 'AUTODEBET',
            ]);
        } catch (\Throwable $e) {
            $ditolak = true;
        }

        $this->assertSame(
            1000000.0,
            round((float) $rekening->fresh()->saldo, 2),
            'D1: saldo Simpanan Sukarela TIDAK BOLEH terpotong oleh pemanggilan '
            .'langsung proses(metode AUTODEBET) selama konfirmasi pengguna belum ada.'
        );

        $this->assertTrue(
            $ditolak,
            'D1: pemotongan langsung harus DITOLAK (exception) agar pemanggil '
            .'tahu pemindahan dana tidak terjadi, bukan diam-diam dibatalkan.'
        );
    }

    /* =====================================================================
     | D2 — Kanal TA'ZIR: pemotongan susulan tanpa konfirmasi
     |===================================================================== */

    public function test_autodebet_tazir_susulan_tidak_memotong_saldo_anggota(): void
    {
        $this->pastikanCoa();
        $this->aktifkanFiturDenda();

        $this->assertTrue(
            Setting::autodebitWajibKonfirmasi(),
            'Prasyarat uji: kebijakan wajib konfirmasi harus AKTIF secara bawaan.'
        );

        $anggota = $this->anggota();
        $rekening = $this->rekening($anggota, 1000000.0);
        [$pembiayaan, $angsuran] = $this->pembiayaan($anggota);

        $tunggakan = TazirPenangguhan::create([
            'jadwal_angsuran_id' => $angsuran->id,
            'pembiayaan_id' => $pembiayaan->id,
            'anggota_id' => $anggota->id,
            'nominal_tazir' => 50000.0,
            'is_tazir_paid' => false,
            'flag' => TazirPenangguhan::FLAG_PENDING_AUTODEBET_SUSULAN,
        ]);

        $dipotong = app(TazirService::class)->prosesAutodebetSusulan($rekening, null, $tunggakan->id);

        $this->assertSame(
            1000000.0,
            round((float) $rekening->fresh()->saldo, 2),
            'D2: saldo TIDAK BOLEH terpotong oleh prosesAutodebetSusulan() langsung '
            .'selama konfirmasi pengguna belum ada. '
            .'(Nominal yang dilaporkan terpotong: Rp '.number_format($dipotong, 0, ',', '.').')'
        );

        $this->assertSame(
            0.0,
            (float) $dipotong,
            'D2: fungsi tidak boleh melaporkan pemotongan yang tidak dilakukan.'
        );

        $this->assertFalse(
            (bool) $tunggakan->fresh()->is_tazir_paid,
            "D2: tunggakan ta'zir tidak boleh dianggap lunas tanpa konfirmasi."
        );
    }

    /* =====================================================================
     | Kontrol positif — jalur konfirmasi tetap memotong
     |===================================================================== */

    public function test_konfirmasi_usulan_angsuran_tetap_memotong_saldo(): void
    {
        $this->pastikanCoa();

        $anggota = $this->anggota();
        $rekening = $this->rekening($anggota, 1000000.0);
        [, $angsuran] = $this->pembiayaan($anggota);

        $usulan = app(AutodebitService::class)->usulkan(
            AutodebitUsulan::KANAL_ANGSURAN,
            (int) $angsuran->id,
            $anggota,
            125000.0,
            ['periode' => now()->format('Y-m')]
        );

        $hasil = app(AutodebitService::class)->konfirmasi($usulan, (int) ($this->tellerId()), 'Uji konfirmasi');

        $this->assertTrue($hasil['ok'], 'Kontrol: konfirmasi usulan harus berhasil. '.($hasil['pesan'] ?? ''));
        $this->assertSame(
            875000.0,
            round((float) $rekening->fresh()->saldo, 2),
            'Kontrol: jalur konfirmasi SAH memotong saldo sebesar tagihan angsuran.'
        );
    }

    private function tellerId(): ?int
    {
        return DB::table('users')->where('role', 'teller')->value('id');
    }
}
