<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\Coa as CoaModel;
use App\Models\JenisSimpanan;
use App\Models\KebijakanKoperasi;
use App\Models\MandatorySavingsBill;
use App\Models\Rekening;
use App\Models\ShuDistribution;
use App\Models\ShuPeriod;
use App\Models\Transaksi;
use App\Models\User;
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
 * BATCH 17 — integritas pelepasan penahanan SHU (Hold).
 *
 * `pengurus.shu.lepas-hold-anggota` dan `pengurus.shu.destroy` belum pernah
 * disebut satu test pun. Keduanya berada pada jalur uang: pelepasan HOLD
 * memindahkan saldo dari pos utang 2-1004 ke Simpanan Sukarela anggota.
 *
 * Kontrak yang dibuktikan di sini:
 *   P-01  Pelepasan HOLD bersifat sekali-jalan — aksi kedua tidak boleh
 *         menambah saldo Sukarela lagi (pencairan ganda).
 *   P-02  `pengurus.shu.destroy` tidak menghapus periode yang sudah
 *         didistribusikan.
 *   P-03  Preview persentase (P-04/P-05) tidak menjumlah > 100%.
 *   P-04  `pengurus.shu.destroy` menghapus baris distribusi bersama
 *         periode draft-nya.
 *   P-05  Pelepasan HOLD tetap ditolak bila anggota masih menunggak.
 *
 * Basis data dev memuat data nyata, sehingga seluruh uji memakai
 * DatabaseTransactions + prefix unik agar deterministik.
 */
class IntegritasPelepasanHoldShuTest extends TestCase
{
    use DatabaseTransactions;

    private User $pengurus;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['shu_periods', 'shu_distributions', 'mandatory_savings_bills'] as $tabel) {
            if (! Schema::hasTable($tabel)) {
                $this->markTestSkipped("Tabel {$tabel} belum tersedia — jalankan migrasi.");
            }
        }

        $this->pengurus = User::firstOrCreate(
            ['email' => 'batch17.shu.pengurus@test.local'],
            [
                'name' => 'Pengurus Batch 17 SHU',
                'password' => Hash::make('password'),
                'role' => 'pengurus',
                'is_active' => true,
            ]
        );

        Setting::flush();
    }

    /* =====================================================================
     | FIXTURE
     ===================================================================== */

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

    private function buatAnggota(string $suffix): Anggota
    {
        return Anggota::create([
            'no_anggota' => 'P17-'.strtoupper(substr(uniqid(), -5)).'-'.$suffix,
            'nama_lengkap' => 'Anggota Batch17 '.$suffix,
            'nik' => '3273'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'jenis_kelamin' => 'Laki-laki',
            'no_hp' => '08'.str_pad((string) random_int(0, 999999999), 10, '0', STR_PAD_LEFT),
            'alamat' => 'Jl. Uji Batch 17 No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'tanggal_lahir' => '1990-01-01',
            'tanggal_gabung' => now()->startOfYear()->toDateString(),
            'status_anggota' => Anggota::STATUS_ACTIVE,
        ]);
    }

    /**
     * Saldo pengendapan Simpanan Pokok + Wajib dalam bentuk mutasi
     * `transaksi` awal tahun (basis Jasa Modal), paritas dengan
     * `ShuHoldNotifikasiTest`.
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

    private function saldoSukarela(Anggota $anggota): float
    {
        return (float) Rekening::where('anggota_id', $anggota->id)
            ->where('jenis_rekening', 'Simpanan Sukarela')
            ->sum('saldo');
    }

    private function tunggakanAnggota(Anggota $anggota): int
    {
        return app(ShuCalculatorService::class)->tunggakanAnggota($anggota)->count();
    }

    private function pastikanCoaShu(): void
    {
        foreach ([
            Coa::IKHTISAR_SHU,
            Coa::CADANGAN_KOPERASI,
            Coa::SIMPANAN_SUKARELA,
            Coa::SHU_DITAHAN,
            Coa::UTANG_DANA_PENGURUS,
            Coa::DANA_SOSIAL,
        ] as $kode) {
            if (! CoaModel::findByKode($kode)) {
                $this->markTestSkipped("CoA {$kode} belum tersedia — jalankan CoaSeeder.");
            }
        }
    }

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
     * Tahun buku periode uji = tahun berjalan.
     *
     * `hitungDasar()` mengambil TAHUN dari string `tahun_buku` (regex 4 digit,
     * fallback `now()->year`) dan menghitung saldo pengendapan HANYA dalam
     * awal..akhir tahun buku itu. Label unik `B17-XXXXXX` tidak memuat 4
     * digit tahun sehingga perhitungannya bergantung pada fallback — dan
     * `tanggal_gabung` fixture harus berada di tahun yang sama.
     */
    private function labelTahunBuku(): string
    {
        return 'B17-'.now()->year.'-'.strtoupper(substr(uniqid(), -6));
    }

    private function buatPeriodeDraft(float $totalShuBersih): ShuPeriod
    {
        $this->pastikanSaldoIkhtisar($totalShuBersih);

        // Tahun buku unik per test agar `unique:shu_periods,tahun_buku` tidak
        // bertabrakan dengan data dev maupun antar-test di kelas ini.
        return ShuPeriod::create([
            'tahun_buku' => $this->labelTahunBuku(),
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
    }

    private function tagihanOverdue(Anggota $anggota, string $periode, float $nominal = 50000): MandatorySavingsBill
    {
        $bill = app(MandatorySavingsService::class)->issueBill($anggota, $periode, null, $nominal);

        $this->assertNotNull($bill, "Tagihan {$periode} gagal diterbitkan.");

        app(MandatorySavingsService::class)->tandaiOverdue($bill, $anggota);

        return $bill->fresh();
    }

    private function prosesShu(ShuPeriod $shu)
    {
        return $this->actingAs($this->pengurus)
            ->post(route('pengurus.shu.proses', $shu->id));
    }

    /**
     * Lunasi seluruh tunggakan OVERDUE anggota lewat jalur SAH yang dipakai
     * aplikasi, yaitu loket Teller (`MandatorySavingsService::bayarViaTeller`).
     *
     * `update(['status' => PAID])` mentah TIDAK sah: jalur itu melewati
     * pembersihan `deferred_*`, jurnal, dan subledger rekening Wajib sehingga
     * sistem tetap benar menolak pelepasan HOLD (false positive).
     */
    private function lunasiTunggakanLewatTeller(Anggota $anggota): void
    {
        $tagihan = MandatorySavingsBill::where('member_id', $anggota->id)
            ->orderBy('id')
            ->get()
            ->where('status', MandatorySavingsBill::STATUS_OVERDUE)
            ->values();

        $this->assertGreaterThan(0, $tagihan->count(),
            'Prasyarat: anggota harus memiliki tagihan OVERDUE sebelum dilunasi.');

        $teller = User::firstOrCreate(
            ['email' => 'batch17.shu.teller@test.local'],
            [
                'name' => 'Teller Batch 17 SHU',
                'password' => Hash::make('password'),
                'role' => 'teller',
                'is_active' => true,
            ]
        );

        $this->bukaSesiKas($teller);

        foreach ($tagihan as $bill) {
            $this->actingAs($teller)
                ->post(route('teller.simpanan-wajib.bayar', $bill->id), [
                    'metode' => MandatorySavingsBill::METODE_CASH,
                ])
                ->assertRedirect()
                ->assertSessionMissing('error');
        }

        $sisa = MandatorySavingsBill::where('member_id', $anggota->id)
            ->where('status', MandatorySavingsBill::STATUS_OVERDUE)->get();

        $this->assertSame(0, $sisa->count(),
            'Prasyarat: setelah pelunasan loket, tidak boleh ada tagihan OVERDUE tersisa. Sisa: '
            .$sisa->map(fn ($b) => '#'.$b->id.' '.$b->bill_period.' '.$b->amount)->implode(', '));
    }

    /**
     * Skenario HOLD lengkap: anggota dengan saldo modal + tunggakan OVERDUE
     * diproses distribusinya, lalu dikembalikan baris distribusi ter-HOLD.
     */
    private function siapkanDistribusiHold(float $totalShu = 1000000): array
    {
        $this->kebijakan(['shu_hold_on_overdue' => true]);
        $this->pastikanCoaShu();

        $anggota = $this->buatAnggota('HOLD');
        $this->beriSaldoModal($anggota);
        $this->tagihanOverdue($anggota, now()->subMonth()->format('Y-m'));

        $shu = $this->buatPeriodeDraft($totalShu);

        try {
            $this->prosesShu($shu)->assertRedirect();

            $distribusi = ShuDistribution::where('shu_period_id', $shu->id)
                ->where('anggota_id', $anggota->id)
                ->first();

            $this->assertNotNull($distribusi,
                'Prasyarat: distribusi anggota harus tercatat setelah proses SHU. error='
                .(string) session('error').' | success='.(string) session('success')
                .' | distribusi periode='.ShuDistribution::where('shu_period_id', $shu->id)->count());
            $this->assertTrue((bool) $distribusi->is_hold,
                'Prasyarat: SHU anggota menunggak harus ter-HOLD.');
            $this->assertGreaterThan(0, (float) $distribusi->total_shu,
                'Prasyarat: nominal SHU ter-HOLD harus > 0 agar pelepasan bermakna.');

            return [$shu, $distribusi, $anggota];
        } catch (\Throwable $e) {
            // Jangan biarkan periode setengah jadi menahan tahun buku unik
            // ini bila prasyarat gagal.
            $this->bersihkanPeriodeUji($shu);

            throw $e;
        }
    }

    private function lepasHold(ShuPeriod $shu, ShuDistribution $distribusi)
    {
        return $this->actingAs($this->pengurus)
            ->post(route('pengurus.shu.lepas-hold-anggota', [
                $shu->id,
                $distribusi->id,
            ]));
    }

    /**
     * Hapus TEGAS seluruh data periode uji.
     *
     * `ShuPeriod` memakai SoftDeletes dan FK `shu_distributions` bergantung
     * pada hard delete — sehingga `DatabaseTransactions` tidak dapat
     * membersihkan baris yang sudah di-soft-delete. Tanpa pembersih ini,
     * baris distribusi yatim menumpuk di basis data (dev & uji berbagi
     * koneksi) setiap kali test dijalankan.
     */
    private function bersihkanPeriodeUji(?ShuPeriod $shu): void
    {
        if (! $shu) {
            return;
        }

        ShuDistribution::where('shu_period_id', $shu->id)->delete();
        ShuPeriod::withTrashed()->whereKey($shu->id)->forceDelete();
    }

    /* =====================================================================
     | P-01 — PELEPASAN HOLD SEKALI-JALAN
     ===================================================================== */

    public function test_pelepasan_hold_tidak_boleh_mencairkan_shu_dua_kali(): void
    {
        [$shu, $distribusi, $anggota] = $this->siapkanDistribusiHold();

        try {
            $nominal = round((float) $distribusi->total_shu, 2);
            $saldoAwal = round($this->saldoSukarela($anggota), 2);

            // Tunggakan dilunasi agar pelepasan pertama sah.
            MandatorySavingsBill::where('member_id', $anggota->id)->update([
                'status' => MandatorySavingsBill::STATUS_PAID,
            ]);

            $this->lepasHold($shu, $distribusi)->assertRedirect();

            $saldoSetelahPertama = round($this->saldoSukarela($anggota), 2);

            $this->assertSame(round($saldoAwal + $nominal, 2), $saldoSetelahPertama,
                'Pelepasan sah wajib menambah saldo Sukarela tepat satu kali nominal SHU.');
            $this->assertFalse((bool) $distribusi->fresh()->is_hold,
                'Setelah dilepas, baris distribusi tidak boleh lagi berstatus HOLD.');

            $jumlahTransaksiPertama = Transaksi::where('no_transaksi', 'like', 'SHU-REL-%')
                ->where('anggota_id', $anggota->id)
                ->count();

            // Aksi kedua terhadap baris yang sudah tidak HOLD.
            $this->lepasHold($shu, $distribusi)->assertRedirect();

            $saldoSetelahKedua = round($this->saldoSukarela($anggota), 2);
            $jumlahTransaksiKedua = Transaksi::where('no_transaksi', 'like', 'SHU-REL-%')
                ->where('anggota_id', $anggota->id)
                ->count();

            $this->assertSame($saldoSetelahPertama, $saldoSetelahKedua,
                'Pelepasan HOLD kedua tidak boleh menambah saldo Sukarela lagi (pencairan ganda).');
            $this->assertSame($jumlahTransaksiPertama, $jumlahTransaksiKedua,
                'Pelepasan HOLD kedua tidak boleh membuat mutasi transaksi baru.');
        } finally {
            $this->bersihkanPeriodeUji($shu);
        }
    }

    /* =====================================================================
     | P-05 — PELEPASAN DITOLAK SAAT MASIH MENUNGGAK
     ===================================================================== */

    public function test_pelepasan_hold_ditolak_bila_masih_menunggak(): void
    {
        [$shu, $distribusi, $anggota] = $this->siapkanDistribusiHold();

        $saldoAwal = round($this->saldoSukarela($anggota), 2);

        $this->lepasHold($shu, $distribusi)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame($saldoAwal, round($this->saldoSukarela($anggota), 2),
            'Anggota yang masih menunggak tidak boleh menerima pencairan SHU.');
        $this->assertTrue((bool) $distribusi->fresh()->is_hold,
            'Penahanan harus tetap berlaku selama tunggakan belum lunas.');
    }

    /* =====================================================================
     | P-02 — PERIODE TERDISTRIBUSI TIDAK BOLEH DIHAPUS
     ===================================================================== */

    public function test_destroy_tidak_menghapus_periode_yang_sudah_didistribusikan(): void
    {
        [$shu] = $this->siapkanDistribusiHold();

        $segar = $shu->fresh();

        $this->assertTrue((bool) $segar->is_distributed || ! $segar->isDraft(),
            'Prasyarat: setelah proses, periode tidak lagi berstatus Draft murni.');

        $this->actingAs($this->pengurus)
            ->delete(route('pengurus.shu.destroy', $shu->id))
            ->assertRedirect();

        $this->assertNotNull(ShuPeriod::find($shu->id),
            'Periode SHU yang sudah didistribusikan tidak boleh terhapus.');
        $this->assertNull(ShuPeriod::withTrashed()->find($shu->id)?->deleted_at,
            'Periode terdistribusi tidak boleh bahkan di-soft-delete.');
        $this->assertGreaterThan(0,
            ShuDistribution::where('shu_period_id', $shu->id)->count(),
            'Baris distribusi periode terdistribusi tidak boleh terhapus.');
    }

    /* =====================================================================
     | P-04 — DRAFT DIHAPUS BERSAMA DISTRIBUSINYA
     ===================================================================== */

    public function test_destroy_menghapus_draft_beserta_distribusinya(): void
    {
        $shu = $this->buatPeriodeDraft(500000);
        $anggotaDraft = $this->buatAnggota('DRAFT');

        ShuDistribution::create([
            'shu_period_id' => $shu->id,
            'anggota_id' => $anggotaDraft->id,
            'total_simpanan_modal' => 100000,
            'monthly_average_balance' => 100000,
            'tenure_months' => 12,
            'total_transaksi_bagi_hasil' => 0,
            'shu_jasa_modal' => 10000,
            'shu_jasa_transaksi' => 0,
            'total_shu' => 10000,
            'is_hold' => false,
        ]);

        try {
            $this->actingAs($this->pengurus)
                ->delete(route('pengurus.shu.destroy', $shu->id))
                ->assertRedirect();

            $this->assertNull(ShuPeriod::find($shu->id),
                'Draft SHU wajib tidak lagi terlihat.');
            $this->assertSame(0,
                ShuDistribution::where('shu_period_id', $shu->id)->count(),
                'Baris distribusi draft wajib ikut terhapus agar tidak menjadi yatim.');
            $this->assertSame(0,
                DB::table('shu_distributions')
                    ->where('shu_period_id', $shu->id)->count(),
                'Baris distribusi draft tidak boleh tertinggal sebagai baris yatim.');
        } finally {
            $this->bersihkanPeriodeUji($shu);
        }
    }

    /* =====================================================================
     | P-08 — ANGGOTA TANPA REKENING SUKARELA
     | Jalur pelepasan HOLD menyediakan rekening Sukarela otomatis. Rekening
     | yang dibuat harus lengkap (`akun_id` & `tanggal_buka` adalah kolom
     | NOT NULL), agar anggota tanpa rekening Sukarela tetap menerima haknya.
     ===================================================================== */

    public function test_pelepasan_hold_membuat_rekening_sukarela_lengkap(): void
    {
        [$shu, $distribusi, $anggota] = $this->siapkanDistribusiHold();

        try {
            $this->assertSame(0,
                Rekening::where('anggota_id', $anggota->id)
                    ->whereIn('jenis_rekening', JenisSimpanan::namaRekening(
                        JenisSimpanan::TIPE_SUKARELA))
                    ->count(),
                'Prasyarat: anggota belum memiliki rekening Sukarela.');

            $nominal = round((float) $distribusi->total_shu, 2);

            $this->lunasiTunggakanLewatTeller($anggota);

            $this->lepasHold($shu, $distribusi)->assertRedirect();

            $this->assertFalse((bool) $distribusi->fresh()->is_hold,
                'Pelepasan HOLD harus berhasil untuk anggota tanpa rekening Sukarela.');

            $this->assertSame($nominal, round($this->saldoSukarela($anggota), 2),
                'Nominal SHU harus masuk ke rekening Sukarela yang baru dibuat.');

            $rekening = Rekening::where('anggota_id', $anggota->id)
                ->whereIn('jenis_rekening', JenisSimpanan::namaRekening(
                    JenisSimpanan::TIPE_SUKARELA))
                ->first();

            $this->assertNotNull($rekening, 'Rekening Sukarela harus dibuat otomatis.');
            $this->assertNotNull($rekening->akun_id,
                'Rekening otomatis wajib menunjuk akun legacy (kolom NOT NULL).');
            $this->assertNotNull($rekening->tanggal_buka,
                'Rekening otomatis wajib memiliki tanggal buka (kolom NOT NULL).');
            $this->assertNotEmpty($rekening->no_rekening);
        } finally {
            $this->bersihkanPeriodeUji($shu);
        }
    }

    /* =====================================================================
     | P-06 / P-07 — KONKURENSI: DUA PEMROSESAN BERSAMAAN
     ===================================================================== */

    public function test_proses_kedua_tidak_menggandakan_pencairan_shu(): void
    {
        [$shu, , $anggota] = $this->siapkanDistribusiHold();

        try {
            $this->lunasiTunggakanLewatTeller($anggota);

            $this->lepasHold($shu, $shu->distributions()->first())->assertRedirect();

            $saldoSebelum = round($this->saldoSukarela($anggota), 2);
            $jumlahTransaksiSebelum = Transaksi::where('sumber_transaksi', 'SHU')
                ->where('anggota_id', $anggota->id)->count();

            // Permintaan kedua atas periode yang sudah 'Selesai'.
            $this->prosesShu($shu)->assertRedirect();

            $this->assertSame($saldoSebelum, round($this->saldoSukarela($anggota), 2),
                'Proses SHU kedua tidak boleh menambah saldo anggota lagi.');
            $this->assertSame($jumlahTransaksiSebelum,
                Transaksi::where('sumber_transaksi', 'SHU')->where('anggota_id', $anggota->id)->count(),
                'Proses SHU kedua tidak boleh membuat mutasi transaksi baru.');
        } finally {
            $this->bersihkanPeriodeUji($shu);
        }
    }

    /* =====================================================================
     | P-09 — JALUR SATUAN: KLIK GANDA
     |
     | Jalur sah pelunasan tunggakan (`teller.simpanan-wajib.bayar`) SUDAH
     | melepas HOLD otomatis. Aksi `Pelepasan Penahanan` manual karena itu
     | ditolak ("tidak sedang dalam penahanan") pada alur normal — inilah yang
     | menjaga klik ganda tidak mencairkan dua kali. Skenario yang tersisa
     | adalah REKONSTRUKSI: HOLD dibuat ulang (mis. koreksi admin) atas baris
     | yang saldo Sukarelanya SUDAH diisi, lalu tombol satuan ditekan dua kali.
     | Pemeriksaan `is_hold` lama lolos dua kali dan menambah saldo dua kali.
     ===================================================================== */

    public function test_pelepasan_hold_satuan_klik_ganda_tidak_mencairkan_dua_kali(): void
    {
        [$shu, $distribusi, $anggota] = $this->siapkanDistribusiHold();

        try {
            // Pelunasan sah melepas HOLD dan mencairkan SHU sekali.
            $this->lunasiTunggakanLewatTeller($anggota);

            $this->assertFalse((bool) ShuDistribution::find($distribusi->id)->is_hold,
                'Prasyarat: pelunasan tunggakan melepas HOLD secara otomatis.');

            $saldoSetelahPelunasan = round($this->saldoSukarela($anggota), 2);
            $nominal = round((float) $distribusi->total_shu, 2);

            $this->assertSame($nominal, $saldoSetelahPelunasan,
                'Prasyarat: pelunasan tunggakan mencairkan TEPAT satu nominal SHU.');

            $jumlahMutasi = Transaksi::where('no_transaksi', 'like', 'SHU-REL-%')
                ->where('anggota_id', $anggota->id)
                ->count();

            // Rekonstruksi HOLD (koreksi admin) lalu klik ganda pada tombol
            // satuan. Kedua permintaan membawa keadaan HOLD yang sama.
            DB::table('shu_distributions')->where('id', $distribusi->id)
                ->update(['is_hold' => true, 'hold_reason' => 'Koreksi uji Batch 18']);

            $distribusiBasah = ShuDistribution::find($distribusi->id);

            $this->assertTrue((bool) $distribusiBasah->is_hold,
                'Prasyarat: HOLD hasil rekonstruksi harus terbaca dari basis data.');

            $this->lepasHold($shu, $distribusiBasah)->assertRedirect();
            $this->lepasHold($shu, $distribusiBasah)->assertRedirect();

            $this->assertSame(
                $saldoSetelahPelunasan,
                round($this->saldoSukarela($anggota), 2),
                'Pelepasan HOLD yang sudah pernah dicairkan tidak boleh menambah saldo Sukarela lagi.'
            );

            $this->assertSame($jumlahMutasi,
                Transaksi::where('no_transaksi', 'like', 'SHU-REL-%')
                    ->where('anggota_id', $anggota->id)->count(),
                'Pelepasan HOLD ulang tidak boleh membuat mutasi SHU tambahan.');
        } finally {
            $this->bersihkanPeriodeUji($shu);
        }
    }

    /* =====================================================================
     | Q-02 — HAPUS PERIODE TIDAK MENINGGALKAN DISTRIBUSI YATIM
     |
     | `ShuPeriod` memakai SoftDeletes sehingga FK
     | `shu_distributions.shu_period_id` yang ber-`onDelete('cascade')` TIDAK
     | pernah aktif untuk `delete()` lunak. Setiap jalur yang menghapus
     | periode (termasuk `forceDelete()` untuk periode uji/salah-input) wajib
     | membersihkan baris distribusi lebih dulu.
     ===================================================================== */

    public function test_force_delete_periode_shu_tidak_meninggalkan_distribusi(): void
    {
        [$shu, $distribusi] = $this->siapkanDistribusiHold();

        $idPeriode = $shu->id;

        // Perilaku model apa adanya (tanpa pembersih manual).
        $shu->forceDelete();

        $this->assertSame(0,
            DB::table('shu_distributions')->where('shu_period_id', $idPeriode)->count(),
            'Menghapus TEGAS periode SHU tidak boleh meninggalkan baris distribusi yatim.');

        $this->assertNull(ShuDistribution::find($distribusi->id),
            'Baris distribusi wajib ikut terhapus bersama periode induknya.');
    }

    /* =====================================================================
     | Q-01 — REKENING SUKARELA HASIL PROSES SHU MEMAKAI `akun.id`
     |
     | `proses()` membuat rekening Sukarela otomatis untuk anggota yang belum
     | punya. Kolom `rekening.akun_id` adalah FK ke tabel `akun` (legacy
     | chart-of-accounts, diisi `AkunSeeder`), BUKAN ke tabel `coas`.
     | Menulis id tabel `coas` ke kolom itu menghasilkan rekening yang
     | menunjuk akun tidak ada — saldo anggota tampil di bagan akun yang salah.
     ===================================================================== */

    public function test_rekening_sukarela_otomatis_dari_proses_shu_memakai_akun_legacy(): void
    {
        $this->kebijakan(['shu_hold_on_overdue' => false]);
        $this->pastikanCoaShu();

        if (! Schema::hasTable('akun')) {
            $this->markTestSkipped('Tabel akun belum tersedia — jalankan AkunSeeder.');
        }

        $akunLegacyId = JenisSimpanan::akunLegacyId(
            JenisSimpanan::namaUtama(JenisSimpanan::TIPE_SUKARELA)
        );

        if ($akunLegacyId === null) {
            $this->markTestSkipped('Akun legacy Simpanan Sukarela tidak ditemukan — jalankan AkunSeeder.');
        }

        $anggota = $this->buatAnggota('AKUN');
        $this->beriSaldoModal($anggota);

        $this->assertSame(0.0, $this->saldoSukarela($anggota),
            'Prasyarat: anggota belum memiliki rekening Sukarela.');

        $coaSukarelaId = (int) CoaModel::findByKode(Coa::SIMPANAN_SUKARELA)->id;

        $this->assertNotSame($akunLegacyId, $coaSukarelaId,
            'Prasyarat uji: id tabel coas dan id tabel akun harus berbeda agar salah-rujuk terdeteksi.');

        $shu = $this->buatPeriodeDraft(1000000);

        try {
            $this->prosesShu($shu)->assertRedirect()->assertSessionMissing('error');

            $rekening = Rekening::where('anggota_id', $anggota->id)
                ->where('jenis_rekening', JenisSimpanan::namaUtama(JenisSimpanan::TIPE_SUKARELA))
                ->first();

            $this->assertNotNull($rekening,
                'Prasyarat: proses SHU harus membuat rekening Sukarela otomatis.');

            $this->assertSame($akunLegacyId, (int) $rekening->akun_id,
                'rekening.akun_id wajib menunjuk tabel akun (legacy), bukan id tabel coas.');
        } finally {
            $this->bersihkanPeriodeUji($shu);
        }
    }

    /* =====================================================================
     | P-07 — GERBANG `proses` DIKUNCI DALAM TRANSAKSI
     ===================================================================== */

    public function test_proses_mengunci_periode_sebelum_distribusi(): void
    {
        $shu = $this->buatPeriodeDraft(1000000);

        try {
            // Gerbang status harus dibaca ulang dengan lockForUpdate DI DALAM
            // transaksi pemrosesan. Bila tidak, dua pengurus yang menekan
            // "Proses" hampir bersamaan sama-sama lolos gerbang Draft dan
            // SHU tercairkan dua kali.
            $sumber = file_get_contents(app_path('Http/Controllers/Pengurus/ShuController.php'));

            $awalProses = strpos($sumber, 'public function proses(');
            $this->assertNotFalse($awalProses, 'Method proses() wajib ada.');

            $tubuh = substr($sumber, $awalProses, 6000);

            $this->assertMatchesRegularExpression(
                '/lockForUpdate\s*\(/',
                $tubuh,
                'proses() wajib mengunci baris periode (lockForUpdate) sebelum memeriksa status & mendistribusikan SHU.'
            );

            $posLock = strpos($tubuh, 'lockForUpdate');
            $posTransaksi = strpos($tubuh, 'DB::transaction');

            $this->assertNotFalse($posTransaksi, 'proses() wajib membungkus distribusi dalam transaksi.');
            $this->assertGreaterThan($posTransaksi, $posLock,
                'Kunci periode wajib diambil DI DALAM DB::transaction agar konsisten.');
        } finally {
            $this->bersihkanPeriodeUji($shu);
        }
    }

    /* =====================================================================
     | P-03 — PELEPASAN HOLD MASSAL IDEMPOTEN
     ===================================================================== */
    public function test_pelepasan_hold_massal_tidak_mencairkan_dua_kali(): void
    {
        [$shu, , $anggota] = $this->siapkanDistribusiHold();

        try {
            $this->lunasiTunggakanLewatTeller($anggota);

            $saldoAwal = round($this->saldoSukarela($anggota), 2);

            // Pelepasan MASSAL memang memproses seluruh baris HOLD di sistem,
            // jadi patokan nominal diambil dari baris milik anggota uji saja.
            $nominalHarapan = round((float) ShuDistribution::where('anggota_id', $anggota->id)
                ->where('is_hold', true)->sum('total_shu'), 2);

            $this->actingAs($this->pengurus)
                ->post(route('pengurus.shu.lepas-hold'))
                ->assertRedirect();

            $saldoSetelahPertama = round($this->saldoSukarela($anggota), 2);

            $this->assertSame(round($saldoAwal + $nominalHarapan, 2), $saldoSetelahPertama,
                'Pelepasan massal wajib mencairkan TEPAT sekali seluruh SHU ter-HOLD anggota.');
            $this->assertFalse((bool) ShuDistribution::where('shu_period_id', $shu->id)
                ->where('anggota_id', $anggota->id)->first()->is_hold,
                'Setelah pelepasan massal, baris tidak boleh lagi HOLD.');

            $jumlahTransaksiPertama = Transaksi::where('no_transaksi', 'like', 'SHU-REL-%')
                ->where('anggota_id', $anggota->id)->count();

            // Dijalankan ulang: tidak ada lagi baris HOLD milik anggota ini.
            $this->actingAs($this->pengurus)
                ->post(route('pengurus.shu.lepas-hold'))
                ->assertRedirect();

            $this->assertSame($saldoSetelahPertama, round($this->saldoSukarela($anggota), 2),
                'Pelepasan massal berulang tidak boleh menambah saldo Sukarela lagi.');
            $this->assertSame($jumlahTransaksiPertama,
                Transaksi::where('no_transaksi', 'like', 'SHU-REL-%')
                    ->where('anggota_id', $anggota->id)->count(),
                'Pelepasan massal berulang tidak boleh membuat mutasi transaksi baru.');
        } finally {
            $this->bersihkanPeriodeUji($shu);
        }
    }
}
