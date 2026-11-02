<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Coa;
use App\Models\JenisSimpanan;
use App\Models\Jurnal;
use App\Models\KebijakanKoperasi;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\AutoJournalingService;
use App\Services\SetoranAwalService;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AKTIVASI ANGGOTA OTOMATIS SETELAH SETORAN AWAL TERVERIFIKASI.
 *
 * Spesifikasi Bagian 2 butir 3: status anggota PENDING ("Menunggu Setoran
 * Awal") hanya boleh berubah menjadi ACTIVE bila setoran awal Simpanan Pokok
 * DAN Simpanan Wajib tercatat benar di sistem akuntansi (saldo buku + jurnal).
 */
class AktivasiSetoranAwalTest extends TestCase
{
    use DatabaseTransactions;

    private User $teller;

    private SetoranAwalService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teller = User::firstOrCreate(
            ['email' => 'teller.aktivasi@test.local'],
            [
                'name' => 'Teller Aktivasi Uji',
                'password' => Hash::make('password'),
                'role' => 'teller',
                'is_active' => true,
            ]
        );

        $this->service = app(SetoranAwalService::class);
    }

    /** CoA wajib tersedia agar jurnal dapat terposting. */
    private function pastikanCoa(): void
    {
        $wajib = ['1-1001', '3-1001', '3-1002', '2-1001'];

        if (Coa::whereIn('kode_akun', $wajib)->count() < count($wajib)) {
            $this->markTestSkipped('CoA '.implode(', ', $wajib).' belum tersedia — jalankan seeder CoA.');
        }
    }

    private function anggotaPending(): Anggota
    {
        return Anggota::create([
            'no_anggota' => 'ANG-AKT-'.strtoupper(substr(uniqid(), -6)),
            'nama_lengkap' => 'Anggota Aktivasi '.substr(uniqid(), -4),
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'jenis_kelamin' => 'Laki-laki',
            'no_hp' => '08'.str_pad((string) random_int(1, 9999999999), 10, '0', STR_PAD_LEFT),
            'alamat' => 'Jl. Uji Aktivasi No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_PENDING,
        ]);
    }

    private function rekening(Anggota $anggota, string $jenis, string $kodeCoa, float $saldo): Rekening
    {
        return Rekening::create([
            'no_rekening' => Rekening::nomorRekeningBaru(),
            'anggota_id' => $anggota->id,
            'akun_id' => Coa::where('kode_akun', $kodeCoa)->firstOrFail()->id,
            'jenis_rekening' => $jenis,
            'akad' => 'Wadiah',
            'saldo' => $saldo,
            'batas_minimum' => 0,
            'tanggal_buka' => today()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);
    }

    /** Setoran + jurnal untuk sebuah rekening (meniru jalur Teller). */
    private function setorDenganJurnal(Rekening $rekening, float $nominal): Transaksi
    {
        $transaksi = Transaksi::create([
            'no_transaksi' => 'TRX-TEST-'.strtoupper(substr(uniqid(), -8)),
            'waktu_transaksi' => now(),
            'rekening_id' => $rekening->id,
            'anggota_id' => $rekening->anggota_id,
            'jenis_transaksi' => $rekening->jenis_rekening,
            'posisi' => 'Debet',
            'jumlah' => $nominal,
            'saldo_setelah' => $rekening->saldo + $nominal,
            'user_id' => $this->teller->id,
            'catatan' => 'Setoran uji aktivasi',
            // Meniru jalur teller sungguhan: setoran loket sudah berhasil dan
            // jurnalnya diposting pada detik yang sama. Tanpa status eksplisit,
            // `Transaksi::booted()` menurunkannya menjadi Pending sehingga gate
            // `posting_wajib_transaksi_sukses` menolak jurnal.
            'status_transaksi' => Transaksi::STATUS_TRANSAKSI_SUKSES,
            'status' => Transaksi::STATUS_TEKNIS_SUCCESS,
        ]);

        app(AutoJournalingService::class)->jurnalSetorSimpanan(
            $nominal,
            'Setoran uji '.$rekening->jenis_rekening,
            $rekening->jenis_rekening,
            $this->teller->id,
            $transaksi->id,
            $rekening->id,
            $rekening->anggota_id
        );

        return $transaksi;
    }

    /** Mutasi setoran tanpa jurnal sendiri (untuk skenario bundling). */
    private function transaksiSetoran(Rekening $rekening, float $nominal): Transaksi
    {
        return Transaksi::create([
            'no_transaksi' => 'TRX-TEST-'.strtoupper(substr(uniqid(), -8)),
            'waktu_transaksi' => now(),
            'rekening_id' => $rekening->id,
            'anggota_id' => $rekening->anggota_id,
            'jenis_transaksi' => $rekening->jenis_rekening,
            'posisi' => 'Debet',
            'jumlah' => $nominal,
            'saldo_setelah' => $rekening->saldo + $nominal,
            'user_id' => $this->teller->id,
            'catatan' => 'Setoran uji aktivasi',
            // Mutasi loket yang sah — lihat `setorDenganJurnal()`.
            'status_transaksi' => Transaksi::STATUS_TRANSAKSI_SUKSES,
            'status' => Transaksi::STATUS_TEKNIS_SUCCESS,
        ]);
    }

    /**
     * REGRESI S-4: jurnal bundling setoran awal hanya menautkan `transaksi_id`
     * ke transaksi Simpanan Pokok. Komponen Wajib tetap harus diakui terjurnal
     * karena satu jurnal yang sama mengkredit rekening Wajib anggota.
     */
    public function test_bundling_setoran_awal_diakui_menutup_komponen_wajib(): void
    {
        $this->pastikanCoa();

        $anggota = $this->anggotaPending();
        $pokok = $this->rekening($anggota, 'Simpanan Pokok', '3-1001', 0);
        $wajib = $this->rekening($anggota, 'Simpanan Wajib', '3-1002', 0);

        $trxPokok = $this->transaksiSetoran($pokok, 100000);
        $this->transaksiSetoran($wajib, 50000); // tanpa jurnal sendiri

        app(AutoJournalingService::class)->jurnalBundlingSetoranAwal(
            100000,
            50000,
            'Setoran awal bundling uji',
            $this->teller->id,
            $trxPokok->id,
            $pokok->id,
            $anggota->id,
            $wajib->id
        );

        $pokok->update(['saldo' => 100000]);
        $wajib->update(['saldo' => 50000]);

        $hasil = $this->service->periksa($anggota->fresh());

        $this->assertTrue($hasil['pokok']['terjurnal']);
        $this->assertTrue($hasil['wajib']['terjurnal'], 'Baris kredit bundling harus menutup komponen Wajib.');
        $this->assertTrue($hasil['lengkap']);
    }

    public function test_aktivasi_ditolak_bila_setoran_awal_belum_lengkap(): void
    {
        $this->pastikanCoa();

        $anggota = $this->anggotaPending();
        $pokok = $this->rekening($anggota, 'Simpanan Pokok', '3-1001', 0);
        $this->rekening($anggota, 'Simpanan Wajib', '3-1002', 0);

        // Hanya Pokok yang disetor — Wajib masih nol.
        $this->setorDenganJurnal($pokok, 100000);
        $pokok->update(['saldo' => 100000]);

        $hasil = $this->service->aktivasiJikaLengkap($anggota->fresh());

        $this->assertFalse($hasil['diaktivasi'], 'Aktivasi harus ditolak bila Simpanan Wajib belum disetor.');
        $this->assertNotEmpty($hasil['alasan']);
        $this->assertSame(Anggota::STATUS_PENDING, $anggota->fresh()->status_anggota);
    }

    public function test_aktivasi_berhasil_bila_kedua_setoran_awal_terjurnal(): void
    {
        $this->pastikanCoa();

        $anggota = $this->anggotaPending();
        $pokok = $this->rekening($anggota, 'Simpanan Pokok', '3-1001', 0);
        $wajib = $this->rekening($anggota, 'Simpanan Wajib', '3-1002', 0);

        $this->setorDenganJurnal($pokok, 100000);
        $pokok->update(['saldo' => 100000]);

        $this->setorDenganJurnal($wajib, 50000);
        $wajib->update(['saldo' => 50000]);

        $hasil = $this->service->aktivasiJikaLengkap($anggota->fresh());

        $this->assertTrue($hasil['diaktivasi'], 'Aktivasi harus berhasil bila kedua setoran awal terverifikasi.');
        $this->assertSame(Anggota::STATUS_ACTIVE, $anggota->fresh()->status_anggota);
    }

    /**
     * REGRESI anggota 8560: ambang aktivasi WAJIB mengikuti kebijakan koperasi
     * (nominal yang benar-benar ditagih saat registrasi), BUKAN
     * `jenis_simpanans.min_deposit_amount` produk POKOK yang bisa lebih tinggi.
     *
     * Skenario nyata: kebijakan Pokok = 50.000, tetapi produk POKOK berambang
     * 100.000. Anggota melunasi Pokok 50.000 + Wajib 50.000 sesuai tagihan —
     * status harus berubah ACTIVE, bukan tertahan PENDING.
     */
    public function test_aktivasi_mengikuti_kebijakan_koperasi_bukan_ambang_produk(): void
    {
        $this->pastikanCoa();

        $kebijakan = KebijakanKoperasi::getSetting();
        $kebijakanAsli = [
            'mandatory_principal_savings' => $kebijakan->mandatory_principal_savings,
            'mandatory_monthly_savings' => $kebijakan->mandatory_monthly_savings,
        ];

        $produkPokok = JenisSimpanan::where('code', JenisSimpanan::CODE_POKOK)->first();
        $minProdukAsli = $produkPokok?->min_deposit_amount;

        // Kebijakan: Pokok 50.000 & Wajib 50.000.
        $kebijakan->update([
            'mandatory_principal_savings' => 50000.00,
            'mandatory_monthly_savings' => 50000.00,
        ]);
        Setting::flush();

        // Produk POKOK sengaja berambang LEBIH TINGGI (100.000) — harus DIABAIKAN.
        $produkPokok?->update(['min_deposit_amount' => 100000]);
        JenisSimpanan::flush();

        try {
            $this->assertSame(50000.0, $this->service->ambangSetoranAwal()['pokok']);

            $anggota = $this->anggotaPending();
            $pokok = $this->rekening($anggota, 'Simpanan Pokok', '3-1001', 0);
            $wajib = $this->rekening($anggota, 'Simpanan Wajib', '3-1002', 0);

            $this->setorDenganJurnal($pokok, 50000);
            $pokok->update(['saldo' => 50000]);

            $this->setorDenganJurnal($wajib, 50000);
            $wajib->update(['saldo' => 50000]);

            $hasil = $this->service->aktivasiJikaLengkap($anggota->fresh());

            $this->assertTrue(
                $hasil['diaktivasi'],
                'Ambang aktivasi harus mengikuti kebijakan koperasi, bukan min_deposit_amount produk.'
            );
            $this->assertSame(Anggota::STATUS_ACTIVE, $anggota->fresh()->status_anggota);
        } finally {
            $kebijakan->update($kebijakanAsli);
            Setting::flush();

            if ($produkPokok && $minProdukAsli !== null) {
                $produkPokok->update(['min_deposit_amount' => $minProdukAsli]);
                JenisSimpanan::flush();
            }
        }
    }

    public function test_aktivasi_ditolak_bila_setoran_belum_terjurnal(): void
    {
        $this->pastikanCoa();

        $anggota = $this->anggotaPending();
        $pokok = $this->rekening($anggota, 'Simpanan Pokok', '3-1001', 0);
        $wajib = $this->rekening($anggota, 'Simpanan Wajib', '3-1002', 0);

        // Saldo buku sudah cukup, tetapi TIDAK ada mutasi/jurnal — tidak sah.
        $pokok->update(['saldo' => 100000]);
        $wajib->update(['saldo' => 50000]);

        $hasil = $this->service->aktivasiJikaLengkap($anggota->fresh());

        $this->assertFalse($hasil['diaktivasi'], 'Saldo tanpa jurnal tidak boleh mengaktifkan anggota.');
        $this->assertSame(Anggota::STATUS_PENDING, $anggota->fresh()->status_anggota);
    }

    public function test_aktivasi_ditolak_bila_saldo_di_bawah_ambang_produk(): void
    {
        $this->pastikanCoa();

        $anggota = $this->anggotaPending();
        $pokok = $this->rekening($anggota, 'Simpanan Pokok', '3-1001', 0);
        $wajib = $this->rekening($anggota, 'Simpanan Wajib', '3-1002', 0);

        $this->setorDenganJurnal($pokok, 10000);
        $pokok->update(['saldo' => 10000]);

        $this->setorDenganJurnal($wajib, 50000);
        $wajib->update(['saldo' => 50000]);

        $hasil = $this->service->aktivasiJikaLengkap($anggota->fresh());

        $this->assertFalse($hasil['diaktivasi'], 'Saldo Pokok di bawah ambang produk tidak boleh mengaktifkan anggota.');
        $this->assertSame(Anggota::STATUS_PENDING, $anggota->fresh()->status_anggota);
    }

    public function test_anggota_aktif_tidak_diproses_ulang(): void
    {
        $this->pastikanCoa();

        $anggota = $this->anggotaPending();
        $anggota->update(['status_anggota' => Anggota::STATUS_ACTIVE]);

        $hasil = $this->service->aktivasiJikaLengkap($anggota->fresh());

        $this->assertFalse($hasil['diaktivasi']);
        $this->assertSame(Anggota::STATUS_ACTIVE, $anggota->fresh()->status_anggota);
    }
}
