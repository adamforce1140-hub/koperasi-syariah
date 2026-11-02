<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\KebijakanKoperasi;
use App\Models\MandatorySavingsBill;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Services\ShuCalculatorService;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Umur Keanggotaan, Tenure Prorated & Penahanan SHU (Poin 3).
 *
 * Aturan bisnis yang dikunci oleh test ini:
 *  1. `member_age`  — durasi keanggotaan diformat "1 Tahun 3 Bulan 5 Hari"
 *     (bagian bernilai nol dihilangkan; tanggal masa depan → '0 Hari').
 *  2. `tenure_months` — total bulan keanggotaan AKTIF, dapat dibatasi pada
 *     satu tahun buku → dasar kalkulasi SHU Prorated.
 *  3. Jasa Modal = rata-rata saldo pengendapan (`monthly_average_balance`)
 *     Simpanan Pokok + Simpanan Wajib × proporsi (tenure_months / 12).
 *  4. Anggota dengan tagihan Simpanan Wajib OVERDUE pada akhir tahun buku
 *     → SELURUH pencairan SHU ditahan (`is_hold = true`).
 *
 * Catatan: basis data dev memuat data nyata, sehingga data uji dibuat dengan
 * prefix unik + `Carbon::setTestNow` agar deterministik.
 */
class ShuProratedMasaKeanggotaanTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'SHUPRT'.strtoupper(substr(uniqid(), -6));

        // Kebijakan penahanan SHU bersifat DAPAT DIKONFIGURASI (kolom
        // `shu_hold_on_overdue`). Basis data dev dapat berisi nilai apa pun
        // hasil pengaturan operator, sehingga test yang mengunci aturan
        // penahanan harus mengaktifkannya secara eksplisit agar deterministik.
        $kebijakan = KebijakanKoperasi::first() ?? new KebijakanKoperasi;
        $kebijakan->shu_hold_on_overdue = true;
        $kebijakan->save();

        Setting::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    private function service(): ShuCalculatorService
    {
        return app(ShuCalculatorService::class);
    }

    /** Isi tabel `akun` (FK wajib `rekening.akun_id`). */
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
        ?string $tanggalGabung,
        string $status = Anggota::STATUS_ACTIVE,
        ?string $tanggalKeluar = null,
    ): Anggota {
        return Anggota::create([
            'no_anggota' => substr($this->prefix.strtoupper($label), 0, 20),
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Uji SHU '.$label,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Alamat Uji SHU',
            'kota' => 'Jakarta',
            'provinsi' => 'DKI Jakarta',
            'status_anggota' => $status,
            'tanggal_gabung' => $tanggalGabung,
            'tanggal_keluar' => $tanggalKeluar,
        ]);
    }

    /** Rekening modal (Simpanan Pokok / Simpanan Wajib) milik anggota. */
    private function rekeningModal(Anggota $anggota, string $jenis): Rekening
    {
        return Rekening::create([
            'no_rekening' => 'RKN-'.strtoupper(substr(uniqid(), -9)),
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunId(),
            'jenis_rekening' => $jenis,
            'akad' => 'Wadiah',
            'saldo' => 0,
            'batas_minimum' => 0,
            'tanggal_buka' => '2026-01-01',
            'status_rekening' => 'Aktif',
        ]);
    }

    /** Setoran modal (Debet = menambah saldo) pada tanggal tertentu. */
    private function setoranModal(Rekening $rekening, float $jumlah, string $waktu): Transaksi
    {
        return Transaksi::create([
            'no_transaksi' => 'TRX-'.strtoupper(substr(uniqid(), -10)),
            'waktu_transaksi' => $waktu,
            'rekening_id' => $rekening->id,
            'anggota_id' => $rekening->anggota_id,
            'jenis_transaksi' => 'Setoran Simpanan Pokok',
            'posisi' => 'Debet',
            'jumlah' => $jumlah,
            'saldo_setelah' => $jumlah,
            'sumber_transaksi' => 'Tunai',
            'status_transaksi' => 'Sukses',
            'status' => 'Sukses',
        ]);
    }

    private function tagihanOverdue(Anggota $anggota, string $periode, float $amount): MandatorySavingsBill
    {
        return MandatorySavingsBill::create([
            'member_id' => $anggota->id,
            'bill_period' => $periode,
            'amount' => $amount,
            'status' => MandatorySavingsBill::STATUS_OVERDUE,
        ]);
    }

    /* =====================================================================
     | 1. UMUR KEANGGOTAAN (member_age)
     |===================================================================== */

    public function test_umur_keanggotaan_diformat_tahun_bulan_hari(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 23, 10, 0, 0));

        $anggota = $this->anggota('A', '2024-06-18');

        // 18 Jun 2024 → 23 Sep 2026 = 2 tahun 3 bulan 5 hari.
        $this->assertSame('2 Tahun 3 Bulan 5 Hari', $anggota->member_age);
    }

    public function test_umur_keanggotaan_menghilangkan_bagian_bernilai_nol(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 23, 10, 0, 0));

        // Tepat 1 tahun → hanya bagian tahun.
        $this->assertSame('1 Tahun', $this->anggota('B', '2025-09-23')->member_age);

        // Tepat 8 bulan → hanya bagian bulan.
        $this->assertSame('8 Bulan', $this->anggota('C', '2026-01-23')->member_age);

        // Tepat 1 bulan 5 hari → tanpa bagian tahun.
        $this->assertSame('1 Bulan 5 Hari', $this->anggota('D', '2026-08-18')->member_age);

        // Hari ini juga → '0 Hari' (bukan string kosong).
        $this->assertSame('0 Hari', $this->anggota('E', '2026-09-23')->member_age);
    }

    public function test_umur_keanggotaan_tanggal_gabung_masa_depan_dianggap_nol_hari(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 23, 10, 0, 0));

        $anggota = $this->anggota('F', '2027-01-01');

        $this->assertSame('0 Hari', $anggota->member_age);
        $this->assertSame(0, $anggota->member_age_months);
    }

    /* =====================================================================
     | 2. TENURE MONTHS — DASAR SHU PRORATED
     |===================================================================== */

    public function test_tenure_months_total_dan_per_tahun_buku(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 23, 10, 0, 0));

        $anggota = $this->anggota('G', '2026-01-31');

        // Total sejak bergabung (tanpa argumen tahun buku): 31 Jan → 23 Sep
        // = 7 bulan penuh — inilah angka yang dipakai label "Masa Aktif".
        $this->assertSame(7, $anggota->tenure_months);

        // Tahun buku 2026: Jan (bulan bergabung) s/d Sep (bulan berjalan) = 9 bulan.
        $this->assertSame(9, $anggota->getTenureMonthsAttribute(2026));

        // Sebelum bergabung → 0.
        $this->assertSame(0, $anggota->getTenureMonthsAttribute(2025));
    }

    public function test_tenure_months_tahun_buku_penuh_tepat_dua_belas_bulan(): void
    {
        // Penutupan buku dijalankan setelah tahun buku berakhir.
        Carbon::setTestNow(Carbon::create(2027, 1, 15, 10, 0, 0));

        $lama = $this->anggota('H', '2020-03-15');

        // Anggota aktif penuh satu tahun buku → tepat 12 bulan, sehingga
        // faktor prorata Jasa Modal 12/12 = 1,0 (tanpa pemotongan tersembunyi).
        $this->assertSame(12, $lama->getTenureMonthsAttribute(2026));

        // Bergabung di tengah tahun buku → dihitung sejak bulan bergabung.
        $tengah = $this->anggota('H2', '2026-07-01');
        $this->assertSame(6, $tengah->getTenureMonthsAttribute(2026));

        // Tahun buku berjalan (2027) → Januari saja.
        $this->assertSame(1, $lama->getTenureMonthsAttribute(2027));
    }

    public function test_tenure_months_dibatasi_tanggal_keluar(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 23, 10, 0, 0));

        $anggota = $this->anggota('I', '2026-01-31', Anggota::STATUS_KELUAR, '2026-05-10');

        // Masa aktif berhenti di tanggal keluar → Jan s/d Mei = 5 bulan.
        $this->assertSame(5, $anggota->getTenureMonthsAttribute(2026));

        // Label "Masa Aktif" memakai bulan penuh: 31 Jan → 10 Mei = 3 bulan.
        $this->assertSame('3 Bulan', $anggota->tenure_label);
    }

    /* =====================================================================
     | 3. RATA-RATA SALDO PENGENDAPAN (monthly_average_balance)
     |===================================================================== */

    public function test_rata_rata_saldo_modal_dihitung_dari_mutasi_bulanan(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 12, 31, 10, 0, 0));

        $anggota = $this->anggota('J', '2026-01-31');
        $pokok = $this->rekeningModal($anggota, 'Simpanan Pokok');
        $wajib = $this->rekeningModal($anggota, 'Simpanan Wajib');

        // Setoran Pokok 100.000 pada 31 Januari (bertahan sepanjang tahun).
        $this->setoranModal($pokok, 100000.00, '2026-01-31 09:00:00');

        // Simpanan Wajib 50.000/bulan dibayar tepat waktu (Feb–Des = 11 bulan).
        for ($bulan = 2; $bulan <= 12; $bulan++) {
            $this->setoranModal(
                $wajib,
                50000.00,
                Carbon::create(2026, $bulan, 1)->format('Y-m-d').' 09:00:00'
            );
        }

        $awal = Carbon::create(2026, 1, 1)->startOfDay();
        $akhir = Carbon::create(2026, 12, 31)->endOfDay();

        $tenure = $anggota->getTenureMonthsAttribute(2026);
        $this->assertSame(12, $tenure, 'Jan–Des 2026 = 12 bulan keanggotaan.');

        $rataRata = $this->service()->rataRataSaldoModal($anggota, $awal, $akhir, $tenure);

        // Saldo akhir tiap bulan: Jan 100.000; Feb–Des bertambah 50.000/bulan.
        // Σ saldo = 100.000 + Σ(n=1..11)(100.000 + n×50.000)
        //         = 100.000 + 1.100.000 + 50.000×66 = 4.500.000
        // Rata-rata = 4.500.000 / 12 bulan = 375.000,00
        $this->assertSame(375000.00, $rataRata);
    }

    public function test_rata_rata_saldo_modal_nol_bila_tenure_nol(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 12, 31, 10, 0, 0));

        $anggota = $this->anggota('K', '2020-01-01');

        $awal = Carbon::create(2026, 1, 1)->startOfDay();
        $akhir = Carbon::create(2026, 12, 31)->endOfDay();

        // Tenure 0 (mis. belum efektif aktif) → tidak berhak Jasa Modal.
        $this->assertSame(0.0, $this->service()->rataRataSaldoModal($anggota, $awal, $akhir, 0));
    }

    /* =====================================================================
     | 4. HITUNG BARIS: PRORATED JASA MODAL
     |===================================================================== */

    public function test_bobot_modal_diprorata_berdasarkan_tenure_months(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 12, 31, 10, 0, 0));

        $anggota = $this->anggota('L', '2026-07-01');
        $pokok = $this->rekeningModal($anggota, 'Simpanan Pokok');
        $this->setoranModal($pokok, 1200000.00, '2026-07-01 09:00:00');

        $awal = Carbon::create(2026, 1, 1)->startOfDay();
        $akhir = Carbon::create(2026, 12, 31)->endOfDay();

        $baris = $this->service()->hitungBaris($anggota, 2026, $awal, $akhir);

        $this->assertNotNull($baris);
        $this->assertSame(6, $baris['tenure_months'], 'Jul–Des = 6 bulan.');
        $this->assertSame(1200000.00, $baris['monthly_average_balance']);
        $this->assertSame(1200000.00, $baris['total_modal']);

        // Prorata: 1.200.000 × (6/12) = 600.000
        $this->assertSame(600000.0, $baris['bobot_modal']);
    }

    public function test_hitung_baris_null_bila_belum_bergabung_pada_tahun_buku(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 12, 31, 10, 0, 0));

        $anggota = $this->anggota('M', '2027-01-05');

        $baris = $this->service()->hitungBaris(
            $anggota,
            2026,
            Carbon::create(2026, 1, 1)->startOfDay(),
            Carbon::create(2026, 12, 31)->endOfDay(),
        );

        $this->assertNull($baris);
    }

    /* =====================================================================
     | 5. SISTEM PENAHANAN SHU (HOLD)
     |===================================================================== */

    public function test_status_penahanan_hold_bila_ada_tagihan_overdue(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 12, 31, 10, 0, 0));

        $anggota = $this->anggota('N', '2026-01-31');
        $akhir = Carbon::create(2026, 12, 31)->endOfDay();

        // Belum ada tagihan OVERDUE → tidak ditahan.
        $this->assertFalse($this->service()->statusPenahanan($anggota, $akhir)['is_hold']);

        $this->tagihanOverdue($anggota, '2026-11', 50000.00);
        $this->tagihanOverdue($anggota, '2026-12', 50000.00);

        $status = $this->service()->statusPenahanan($anggota, $akhir);

        $this->assertTrue($status['is_hold']);
        $this->assertStringContainsString('Ditahan:', (string) $status['hold_reason']);
        $this->assertStringContainsString('2 tagihan', (string) $status['hold_reason']);
    }

    public function test_hold_menahan_seluruh_shu_meski_sudah_dialokasikan(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 12, 31, 10, 0, 0));

        $anggota = $this->anggota('O', '2026-01-31');
        $pokok = $this->rekeningModal($anggota, 'Simpanan Pokok');
        $this->setoranModal($pokok, 100000.00, '2026-01-31 09:00:00');

        $this->tagihanOverdue($anggota, '2026-12', 50000.00);

        $dasar = $this->service()->hitungDasar('2026');
        $baris = collect($dasar['baris'])->firstWhere('anggota_id', $anggota->id);

        $this->assertNotNull($baris, 'Anggota harus masuk dasar pembagian SHU.');
        $this->assertTrue($baris['is_hold']);

        // Meski sudah dialokasikan, pencairan tetap ditahan.
        $alokasi = collect($this->service()->alokasikan([$baris], 1000000.00, 0.0))->first();
        $this->assertGreaterThan(0, $alokasi['total_shu']);
        $this->assertTrue($alokasi['is_hold']);
    }

    public function test_ringkasan_penahanan_memuat_anggota_bermasalah(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 12, 31, 10, 0, 0));

        $anggota = $this->anggota('P', '2026-01-31');
        $this->tagihanOverdue($anggota, '2026-10', 50000.00);

        $ringkasan = $this->service()->ringkasanPenahanan(2026);

        $baris = $ringkasan->firstWhere('anggota_id', $anggota->id);

        $this->assertNotNull($baris, 'Anggota ber-HOLD harus muncul di ringkasan penahanan.');
        $this->assertTrue($baris['is_hold']);
        $this->assertSame($anggota->nama_lengkap, $baris['nama_anggota']);
        $this->assertSame($anggota->no_anggota, $baris['no_anggota']);
    }

    /* =====================================================================
     | 6. ALOKASI PROPORSIONAL (menjaga jurnal distribusi tetap balance)
     |===================================================================== */

    public function test_alokasi_proporsional_menjumlah_tepat_nominal(): void
    {
        $baris = [
            ['bobot_modal' => 600000.0, 'bobot_transaksi' => 0.0, 'is_hold' => false],
            ['bobot_modal' => 300000.0, 'bobot_transaksi' => 0.0, 'is_hold' => false],
            ['bobot_modal' => 100000.0, 'bobot_transaksi' => 0.0, 'is_hold' => true],
        ];

        $alokasi = $this->service()->alokasikan($baris, 1000000.00, 0.0);

        $this->assertSame(600000.00, $alokasi[0]['shu_jasa_modal']);
        $this->assertSame(300000.00, $alokasi[1]['shu_jasa_modal']);
        $this->assertSame(100000.00, $alokasi[2]['shu_jasa_modal']);
        $this->assertFalse($alokasi[0]['is_hold']);
        $this->assertTrue($alokasi[2]['is_hold']);

        // SUM(alokasi) === nominal (syarat jurnal distribusi tetap balance).
        $this->assertSame(
            1000000.00,
            round(array_sum(array_column($alokasi, 'shu_jasa_modal')), 2)
        );
    }

    public function test_bagi_proporsional_pembulatan_sen_tetap_utuh(): void
    {
        // 100 dibagi 3 penerima bersisa → largest remainder method menjaga total.
        $hasil = $this->service()->bagiProporsional([1, 1, 1], 100.00);

        $this->assertCount(3, $hasil);
        $this->assertSame(100.00, round(array_sum($hasil), 2));
    }
}
