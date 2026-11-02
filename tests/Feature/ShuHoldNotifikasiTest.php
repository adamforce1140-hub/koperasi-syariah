<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\Coa as CoaModel;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\KebijakanKoperasi;
use App\Models\MandatorySavingsBill;
use App\Models\Rekening;
use App\Models\ShuDistribution;
use App\Models\ShuPeriod;
use App\Models\Transaksi;
use App\Models\User;
use App\Notifications\ShuDitahanNotification;
use App\Services\MandatorySavingsService;
use App\Services\ShuCalculatorService;
use App\Support\Coa;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PENAHANAN SHU (HOLD) — §6 & §7 matriks pengujian.
 *
 * Kontrak yang dikunci test ini:
 *   1. Anggota dengan tagihan Simpanan Wajib OVERDUE pada akhir tahun buku
 *      dan kebijakan `shu_hold_on_overdue = true` TIDAK menerima pencairan
 *      SHU: saldo Simpanan Sukarela tidak bertambah, tidak ada mutasi
 *      Transaksi, dan baris distribusinya ditandai `is_hold = true` beserta
 *      `hold_reason` yang bisa ditindaklanjuti.
 *   2. Nilai SHU tertahan dikreditkan ke akun utang 2-1004 (SHU Ditahan),
 *      bukan ke 2-1001 — sehingga kewajiban koperasi tetap terlihat di
 *      neraca sesuai PSAK 101/108.
 *   3. Kebijakan `shu_hold_on_overdue = false` menonaktifkan penahanan:
 *      seluruh anggota tercairkan meski menunggak.
 *   4. Setelah tunggakan dilunasi di Teller, pelepasan penahanan
 *      mereklasifikasi 2-1004 → 2-1001 dan SHU benar-benar cair.
 *   5. Notifikasi database `ShuDitahanNotification` terkirim ke seluruh
 *      Pengurus saat penahanan terjadi (banner dashboard & halaman SHU).
 *
 * Basis data dev memuat data nyata, sehingga seluruh uji memakai
 * DatabaseTransactions + data berprefix unik agar deterministik.
 */
class ShuHoldNotifikasiTest extends TestCase
{
    use DatabaseTransactions;

    private User $pengurus;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['shu_periods', 'shu_distributions', 'mandatory_savings_bills', 'notifications'] as $tabel) {
            if (! Schema::hasTable($tabel)) {
                $this->markTestSkipped("Tabel {$tabel} belum tersedia — jalankan migrasi.");
            }
        }

        $this->pengurus = User::firstOrCreate(
            ['email' => 'hold.shu.pengurus@test.local'],
            [
                'name' => 'Pengurus Hold SHU',
                'password' => Hash::make('password'),
                'role' => 'pengurus',
                'is_active' => true,
            ]
        );

        // Cache parameter kebijakan harus bersih agar perubahan
        // `shu_hold_on_overdue` pada tiap skenario langsung terbaca.
        Setting::flush();
    }

    /* =====================================================================
     | FIXTURE
     ===================================================================== */

    /** Baris kebijakan koperasi (dibuat bila belum ada agar update bermakna). */
    private function kebijakan(array $override = []): KebijakanKoperasi
    {
        $kebijakan = KebijakanKoperasi::query()->first()
            ?? KebijakanKoperasi::create([]);

        if (! empty($override)) {
            $kebijakan->update($override);
        }

        Setting::flush();

        return $kebijakan->fresh();
    }

    /** Anggota aktif dengan masa keanggotaan sejak awal tahun buku. */
    private function buatAnggota(string $suffix): Anggota
    {
        return Anggota::create([
            'no_anggota' => 'HLD-'.strtoupper(substr(uniqid(), -5)).'-'.$suffix,
            'nama_lengkap' => 'Anggota Hold '.$suffix,
            'nik' => '3273'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'jenis_kelamin' => 'Laki-laki',
            'no_hp' => '08'.str_pad((string) random_int(0, 999999999), 10, '0', STR_PAD_LEFT),
            'alamat' => 'Jl. Uji Hold No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'tanggal_lahir' => '1990-01-01',
            'tanggal_gabung' => now()->startOfYear()->toDateString(),
            'status_anggota' => Anggota::STATUS_ACTIVE,
        ]);
    }

    /**
     * Beri anggota saldo Simpanan Pokok + Wajib yang nyata.
     *
     * SHU Jasa Modal hanya dibagi kepada anggota yang punya SALDO
     * PENGENDAPAN (basis kalkulasi > 0). Anggota tanpa saldo modal memang
     * tidak memperoleh baris distribusi — bukan karena penahanan. Karena itu
     * seluruh skenario penahanan wajib memakai anggota yang benar-benar
     * menyimpan dana, sesuai perilaku nyata Modul Simpanan.
     *
     * Basis kalkulasi dibaca dari mutasi `transaksi` (bukan kolom
     * `rekening.saldo`), sehingga setoran dicatat sebagai transaksi Debet
     * ber-tanggal awal tahun buku agar mengendap sepanjang tahun.
     */
    private function beriSaldoModal(Anggota $anggota, float $pokok = 500000, float $wajib = 600000): void
    {
        $akunId = Akun::query()->value('id');

        if (! $akunId) {
            $this->markTestSkipped('Tabel akun kosong — jalankan AkunSeeder.');
        }

        $waktu = now()->startOfYear()->addDay()->setTime(9, 0);

        foreach ([['Simpanan Pokok', $pokok], ['Simpanan Wajib', $wajib]] as [$jenis, $saldo]) {
            $rekening = Rekening::create([
                'no_rekening' => 'RKN-'.strtoupper(substr(uniqid(), -6)).'-'.random_int(100, 999),
                'anggota_id' => $anggota->id,
                'akun_id' => $akunId,
                'jenis_rekening' => $jenis,
                'akad' => 'Wadiah',
                'saldo' => $saldo,
                'batas_minimum' => 0,
                'tanggal_buka' => $waktu->toDateString(),
                'status_rekening' => 'Aktif',
            ]);

            Transaksi::create([
                'no_transaksi' => 'TRX-'.strtoupper(substr(uniqid(), -8)),
                'waktu_transaksi' => $waktu,
                'rekening_id' => $rekening->id,
                'anggota_id' => $anggota->id,
                'jenis_transaksi' => 'Setoran '.$jenis,
                'posisi' => 'Debet',
                'jumlah' => $saldo,
                'saldo_setelah' => $saldo,
                'sumber_transaksi' => 'Tunai',
                'status_transaksi' => 'Sukses',
                'status' => 'Sukses',
            ]);
        }
    }

    /** Saldo Simpanan Sukarela anggota (tujuan pencairan SHU normal). */
    private function saldoSukarela(Anggota $anggota): float
    {
        return (float) Rekening::where('anggota_id', $anggota->id)
            ->where('jenis_rekening', 'Simpanan Sukarela')
            ->sum('saldo');
    }

    /** Akun CoA untuk modul SHU — lewati bila seeder CoA belum dijalankan. */
    private function pastikanCoaShu(): void
    {
        foreach ([
            Coa::IKHTISAR_SHU,          // 3-9000 — sumber ekuitas yang didebet
            Coa::CADANGAN_KOPERASI,     // 3-2001
            Coa::SIMPANAN_SUKARELA,     // 2-1001 — pencairan normal
            Coa::SHU_DITAHAN,           // 2-1004 — penampung SHU ter-HOLD
            Coa::UTANG_DANA_PENGURUS,   // 2-2001
            Coa::DANA_SOSIAL,           // 2-3003
        ] as $kode) {
            if (! CoaModel::findByKode($kode)) {
                $this->markTestSkipped("CoA {$kode} belum tersedia — jalankan CoaSeeder.");
            }
        }
    }

    /**
     * Saldo berjalan akun CoA (agregat kredit − debet sesuai posisi normal).
     */
    private function saldoCoa(string $kode): float
    {
        $coa = CoaModel::findByKode($kode);

        return $coa ? (float) $coa->saldoBerjalan() : 0.0;
    }

    /**
     * Pastikan akun 3-9000 Ikhtisar SHU punya saldo cukup untuk distribusi.
     *
     * Basis data dev bisa saja sudah ditutup buku pada posisi RUGI sehingga
     * saldo 3-9000 negatif. Tanpa penambahan, seluruh skenario penahanan
     * ter-skip dan kontraknya tidak pernah benar-benar diuji. Penambahan
     * dilakukan pada kolom `saldo_awal` di dalam DatabaseTransactions,
     * sehingga tidak mengubah data permanen.
     */
    private function pastikanSaldoIkhtisar(float $minimal): void
    {
        $coa = CoaModel::findByKode(Coa::IKHTISAR_SHU);

        if (! $coa) {
            $this->markTestSkipped('CoA '.Coa::IKHTISAR_SHU.' belum tersedia — jalankan CoaSeeder.');
        }

        $kekurangan = $minimal - (float) $coa->saldoBerjalan();

        if ($kekurangan > 0) {
            $coa->update(['saldo_awal' => (float) $coa->saldo_awal + $kekurangan]);
        }
    }

    /**
     * Periode SHU draft dengan total SHU bersih yang cukup kecil sehingga
     * saldo akun 3-9000 pasti mencukupi.
     *
     * @return array{0: ShuPeriod, 1: array<string, float>}
     */
    private function buatPeriodeDraft(float $totalShuBersih, ?string $tahunBuku = null): array
    {
        $this->pastikanSaldoIkhtisar($totalShuBersih);

        $tahun = $tahunBuku ?? (string) now()->year;

        $shu = ShuPeriod::create([
            'tahun_buku' => $tahun,
            'total_shu_bersih' => $totalShuBersih,
            'persen_cadangan' => 30,
            'persen_jasa_modal' => 20,
            'persen_jasa_transaksi' => 40,
            'persen_pengurus' => 10,
            'persen_sosial' => 0,
            'nominal_cadangan' => round($totalShuBersih * 0.30, 2),
            'nominal_jasa_modal' => round($totalShuBersih * 0.20, 2),
            'nominal_jasa_transaksi' => round($totalShuBersih * 0.40, 2),
            'nominal_pengurus' => round($totalShuBersih * 0.10, 2),
            'nominal_sosial' => 0,
            'status' => 'Draft',
            'is_distributed' => false,
            'user_id' => $this->pengurus->id,
        ]);

        return [$shu, [
            'jasa_modal' => (float) $shu->nominal_jasa_modal,
            'jasa_transaksi' => (float) $shu->nominal_jasa_transaksi,
        ]];
    }

    /** Tagihan Simpanan Wajib yang sudah lewat jatuh tempo (OVERDUE). */
    private function tagihanOverdue(Anggota $anggota, string $periode, float $nominal = 50000): MandatorySavingsBill
    {
        $bill = app(MandatorySavingsService::class)->issueBill($anggota, $periode, null, $nominal);

        $this->assertNotNull($bill, "Tagihan {$periode} gagal diterbitkan.");

        app(MandatorySavingsService::class)->tandaiOverdue($bill, $anggota);

        return $bill->fresh();
    }

    /** Jalankan aksi distribusi SHU sebagai Pengurus. */
    private function prosesShu(ShuPeriod $shu)
    {
        return $this->actingAs($this->pengurus)
            ->post(route('pengurus.shu.proses', $shu->id));
    }

    /* =====================================================================
     | (1) PENAHANAN AKTIF — tagihan OVERDUE + kebijakan true
     ===================================================================== */

    public function test_anggota_menunggak_shunya_ditahan_bukan_dicairkan(): void
    {
        $this->kebijakan(['shu_hold_on_overdue' => true]);

        $anggota = $this->buatAnggota('TUNGGAK');
        $this->beriSaldoModal($anggota);
        $this->tagihanOverdue($anggota, now()->subMonth()->format('Y-m'));

        [$shu] = $this->buatPeriodeDraft(1000000);

        $saldoSukarelaSebelum = $this->saldoSukarela($anggota);

        $this->prosesShu($shu)->assertRedirect();

        $distribusi = ShuDistribution::where('shu_period_id', $shu->id)
            ->where('anggota_id', $anggota->id)
            ->first();

        $this->assertNotNull($distribusi, 'Distribusi anggota harus tetap dicatat sebagai dokumen audit.');
        $this->assertGreaterThan(0, (float) $distribusi->total_shu,
            'Anggota dengan saldo modal wajib memperoleh porsi SHU agar penahanan bermakna.');
        $this->assertTrue((bool) $distribusi->is_hold,
            'SHU anggota menunggak wajib ditandai HOLD.');
        $this->assertStringContainsString('Ditahan', (string) $distribusi->hold_reason,
            'Alasan penahanan wajib diisi agar bisa ditindaklanjuti pengurus.');
        $this->assertNull($distribusi->rekening_id,
            'Baris HOLD tidak boleh terikat rekening pencairan.');
        $this->assertNull($distribusi->transaksi_id,
            'Baris HOLD tidak boleh menghasilkan mutasi transaksi.');

        $this->assertSame(0.0, round($this->saldoSukarela($anggota), 2),
            'Saldo Simpanan Sukarela anggota menunggak tidak boleh bertambah — SHU-nya ditahan.');
        $this->assertSame(round($saldoSukarelaSebelum, 2), round($this->saldoSukarela($anggota), 2),
            'Tidak boleh ada pencairan SHU sebelum tunggakan dilunasi.');
    }

    public function test_shu_tertahan_dikreditkan_ke_akun_21004(): void
    {
        $this->pastikanCoaShu();
        $this->kebijakan(['shu_hold_on_overdue' => true]);

        $anggota = $this->buatAnggota('AKUN1004');
        $this->beriSaldoModal($anggota);
        $this->tagihanOverdue($anggota, now()->subMonth()->format('Y-m'));

        [$shu] = $this->buatPeriodeDraft(1000000);

        $deltaHold = $this->saldoCoa(Coa::SHU_DITAHAN);
        $deltaCair = $this->saldoCoa(Coa::SIMPANAN_SUKARELA);

        $this->prosesShu($shu)->assertRedirect();

        $shu->refresh();

        $this->assertGreaterThan(0, (float) $shu->total_shu_hold,
            'Anggota menunggak dengan saldo modal wajib menghasilkan SHU tertahan.');

        $jurnal = Jurnal::find($shu->jurnal_id);
        $this->assertNotNull($jurnal, 'Distribusi SHU wajib menghasilkan jurnal.');

        $barisHold = JurnalDetail::where('jurnal_id', $jurnal->id)
            ->whereHas('coa', fn ($q) => $q->where('kode_akun', Coa::SHU_DITAHAN))
            ->first();

        $this->assertNotNull($barisHold, 'Jurnal wajib memuat kredit akun 2-1004 SHU Ditahan.');
        $this->assertSame(
            round((float) $shu->total_shu_hold, 2),
            round((float) $barisHold->kredit, 2),
            'Nominal kredit 2-1004 harus sama persis dengan total SHU yang ditahan.'
        );

        $this->assertSame(
            round($deltaHold + (float) $shu->total_shu_hold, 2),
            round($this->saldoCoa(Coa::SHU_DITAHAN), 2),
            'Kewajiban SHU ditahan harus bertambah di neraca.'
        );

        $this->assertGreaterThanOrEqual(
            $deltaCair,
            $this->saldoCoa(Coa::SIMPANAN_SUKARELA),
            'Pencairan sukarela tidak boleh berkurang karena penahanan.'
        );
    }

    public function test_jurnal_distribusi_tetap_balance_dengan_penahanan(): void
    {
        $this->kebijakan(['shu_hold_on_overdue' => true]);

        $anggota = $this->buatAnggota('BALANCE');
        $this->beriSaldoModal($anggota);
        $this->tagihanOverdue($anggota, now()->subMonth()->format('Y-m'));

        [$shu] = $this->buatPeriodeDraft(1000000);

        $this->prosesShu($shu)->assertRedirect();

        $shu->refresh();
        $jurnal = Jurnal::find($shu->jurnal_id);

        $this->assertNotNull($jurnal, 'Jurnal distribusi harus terbentuk.');

        $totalDebet = round((float) JurnalDetail::where('jurnal_id', $jurnal->id)->sum('debet'), 2);
        $totalKredit = round((float) JurnalDetail::where('jurnal_id', $jurnal->id)->sum('kredit'), 2);

        $this->assertSame($totalDebet, $totalKredit,
            'Jurnal distribusi SHU wajib balance meski ada penahanan (PSAK 101/108).');
        $this->assertSame(round((float) $shu->total_shu_bersih, 2), $totalDebet,
            'Total debet harus sebesar SHU bersih periode.');
        $this->assertTrue((bool) $jurnal->is_balance);
        $this->assertSame('Posted', $jurnal->status);
    }

    /* =====================================================================
     | (2) KEBIJAKAN NONAKTIF — shu_hold_on_overdue = false
     ===================================================================== */

    public function test_kebijakan_hold_nonaktif_tidak_menahan_shu(): void
    {
        $this->kebijakan(['shu_hold_on_overdue' => false]);

        $this->assertFalse(Setting::tahanShuSaatMenunggak(),
            'Kebijakan harus terbaca false setelah cache di-flush.');

        $anggota = $this->buatAnggota('NONAKTIF');
        $this->tagihanOverdue($anggota, now()->subMonth()->format('Y-m'));

        [$shu] = $this->buatPeriodeDraft(1000000);

        $this->prosesShu($shu)->assertRedirect();

        $shu->refresh();

        $this->assertSame(0.0, round((float) $shu->total_shu_hold, 2),
            'Tanpa kebijakan hold, tidak boleh ada SHU tertahan.');
        $this->assertSame(0, (int) $shu->jumlah_anggota_hold);

        $this->assertSame(
            0,
            ShuDistribution::where('shu_period_id', $shu->id)->where('is_hold', true)->count(),
            'Tidak boleh ada baris distribusi berstatus HOLD.'
        );
    }

    public function test_ringkasan_penahanan_mengabaikan_kebijakan_nonaktif(): void
    {
        $this->kebijakan(['shu_hold_on_overdue' => false]);

        $anggota = $this->buatAnggota('RINGKAS');
        $this->tagihanOverdue($anggota, now()->subMonth()->format('Y-m'));

        $ringkasan = app(ShuCalculatorService::class)
            ->ringkasanPenahanan((int) now()->year);

        $this->assertNotContains(
            $anggota->id,
            $ringkasan->pluck('anggota_id')->all(),
            'Kebijakan nonaktif harus mengosongkan ringkasan penahanan.'
        );
    }

    /* =====================================================================
     | (3) PELEPASAN PENAHANAN SETELAH TUNGGAKAN LUNAS
     ===================================================================== */

    public function test_pelepasan_penahanan_setelah_pelunasan_tunggakan(): void
    {
        $this->kebijakan(['shu_hold_on_overdue' => true]);

        $anggota = $this->buatAnggota('LEPAS');
        $bill = $this->tagihanOverdue($anggota, now()->subMonth()->format('Y-m'));

        $this->assertSame(MandatorySavingsBill::STATUS_OVERDUE, $bill->status);

        // Tunggakan dilunasi → tidak ada lagi tagihan OVERDUE.
        $bill->update([
            'status' => MandatorySavingsBill::STATUS_PAID,
            'paid_at' => now(),
            'payment_method' => MandatorySavingsBill::METODE_CASH,
        ]);

        $kalkulator = app(ShuCalculatorService::class);
        $status = $kalkulator->statusPenahanan($anggota->fresh(), now());

        $this->assertFalse((bool) $status['is_hold'],
            'Setelah tunggakan lunas, penahanan harus lepas otomatis.');
        $this->assertNull($status['hold_reason']);
    }

    /* =====================================================================
     | (4) NOTIFIKASI DATABASE KE PENGURUS
     ===================================================================== */

    public function test_notifikasi_database_terkirim_ke_pengurus_saat_penahanan(): void
    {
        $this->kebijakan(['shu_hold_on_overdue' => true]);

        $anggota = $this->buatAnggota('NOTIF');
        $this->beriSaldoModal($anggota);
        $this->tagihanOverdue($anggota, now()->subMonth()->format('Y-m'));

        [$shu] = $this->buatPeriodeDraft(1000000);

        $sebelum = DB::table('notifications')
            ->where('notifiable_id', $this->pengurus->id)
            ->where('notifiable_type', User::class)
            ->count();

        $this->prosesShu($shu)->assertRedirect();

        $shu->refresh();

        $this->assertGreaterThan(0, (float) $shu->total_shu_hold,
            'Skenario notifikasi memerlukan penahanan nyata agar pesan benar-benar terkirim.');

        $sesudah = DB::table('notifications')
            ->where('notifiable_id', $this->pengurus->id)
            ->where('notifiable_type', User::class)
            ->where('type', ShuDitahanNotification::class)
            ->get();

        $this->assertGreaterThan($sebelum, $sesudah->count(),
            'Setiap penahanan wajib menotifikasi Pengurus lewat kanal database.');

        $payload = json_decode((string) $sesudah->last()->data, true);

        $this->assertSame('shu_hold', $payload['tipe']);
        $this->assertSame('SHU Ditahan — Tunggakan Simpanan Wajib', $payload['judul']);
        $this->assertArrayHasKey('anggota_id', $payload);
        $this->assertArrayHasKey('total_tunggakan', $payload);
        $this->assertArrayHasKey('url', $payload);
    }

    public function test_payload_notifikasi_memuat_ringkasan_tunggakan(): void
    {
        $this->kebijakan(['shu_hold_on_overdue' => true]);

        $anggota = $this->buatAnggota('PAYLOAD');
        $this->tagihanOverdue($anggota, now()->subMonths(2)->format('Y-m'), 60000);
        $this->tagihanOverdue($anggota, now()->subMonth()->format('Y-m'), 60000);

        $kalkulator = app(ShuCalculatorService::class);
        $status = $kalkulator->statusPenahanan($anggota, now());
        $tunggakan = $kalkulator->tunggakanAnggota($anggota);

        $this->assertTrue((bool) $status['is_hold'], 'Anggota dengan 2 tagihan OVERDUE wajib ditahan.');
        $this->assertCount(2, $tunggakan);

        $notifikasi = ShuDitahanNotification::dariAnggota($anggota, $status, $tunggakan, 150000.0, '2026');
        $payload = $notifikasi->toArray($this->pengurus);

        $this->assertSame('shu_hold', $payload['tipe']);
        $this->assertSame($anggota->id, $payload['anggota_id']);
        $this->assertSame($anggota->nama_lengkap, $payload['nama_anggota']);
        $this->assertSame(2, $payload['jumlah_tunggakan']);
        $this->assertSame(120000.0, $payload['total_tunggakan']);
        $this->assertSame(150000.0, $payload['total_shu']);
        $this->assertSame($status['hold_reason'], $payload['pesan']);
        $this->assertSame(['database'], $notifikasi->via($this->pengurus));
    }

    /* =====================================================================
     | (5) TAMPILAN — banner & kartu ringkasan pengurus
     ===================================================================== */

    public function test_halaman_detail_cif_menampilkan_banner_penahanan(): void
    {
        $this->kebijakan(['shu_hold_on_overdue' => true]);

        $anggota = $this->buatAnggota('BANNER');
        $this->tagihanOverdue($anggota, now()->subMonth()->format('Y-m'));

        $html = $this->actingAs($this->pengurus)
            ->get(route('pengurus.cif.show', $anggota->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('SHU Ditahan (HOLD)', $html,
            'Detail CIF anggota menunggak wajib menampilkan banner penahanan SHU.');
    }

    public function test_dashboard_pengurus_menampilkan_kartu_penahanan(): void
    {
        $this->kebijakan(['shu_hold_on_overdue' => true]);

        $anggota = $this->buatAnggota('KARTU');
        $this->tagihanOverdue($anggota, now()->subMonth()->format('Y-m'));

        $html = $this->actingAs($this->pengurus)
            ->get(route('dashboard.pengurus'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Penahanan SHU (HOLD)', $html,
            'Dashboard Pengurus wajib menampilkan kartu ringkasan penahanan SHU.');
    }
}
