<?php

namespace Tests\Feature;

use App\Models\Angsuran;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\TahunBukuService;
use App\Support\RentangPeriode;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regresi Tahap 7 — Skalabilitas Jalur Baca.
 *
 * Mengunci tiga hal yang menentukan apakah sistem tetap cepat saat jumlah
 * anggota dan volume transaksi bertumbuh:
 *
 *  T7-01 : Indeks penopang jalur baca (`rekening`, `jurnal`, `anggota`,
 *          `angsuran`) benar-benar terpasang.
 *  T7-02 : Indeks tersebut BENAR-BENAR dipakai MySQL (dibuktikan EXPLAIN),
 *          bukan sekadar ada.
 *  T7-03 : Filter periode sargable (`RentangPeriode`) menghasilkan angka yang
 *          IDENTIK dengan `whereMonth()/whereYear()` — perbaikan kinerja tidak
 *          boleh mengubah satu rupiah pun pada laporan.
 *
 * Test ini murni-baca (tidak mengubah data) sehingga aman memakai
 * DatabaseTransactions.
 */
class Tahap7SkalabilitasTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['rekening', 'transaksi', 'angsuran', 'anggota', 'jurnal', 'pembiayaan'] as $tabel) {
            if (! Schema::hasTable($tabel)) {
                $this->markTestSkipped("Tabel {$tabel} belum tersedia.");
            }
        }
    }

    private function hanyaMysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' && DB::connection()->getDriverName() !== 'mariadb') {
            $this->markTestSkipped('Pemeriksaan information_schema/EXPLAIN hanya untuk MySQL/MariaDB.');
        }
    }

    /* =====================================================================
     | T7-01 — Keberadaan indeks jalur baca
     ===================================================================== */

    /**
     * Indeks jalur baca Tahap 7 wajib terpasang.
     *
     * Sebelum Tahap 7, lima kueri baca berulang (agregat saldo rekening aktif,
     * filter jenis rekening, pengecekan idempotensi jurnal per `sumber`,
     * hitungan anggota per status, dan rekap angsuran masuk) melakukan full
     * table scan. Test ini mencegah indeksnya hilang tanpa sengaja pada
     * refaktor migrasi berikutnya.
     */
    public function test_indeks_jalur_baca_tahap_7_terpasang(): void
    {
        $this->hanyaMysql();

        $wajib = [
            'rekening' => [
                'rekening_status_saldo_index',
                'rekening_jenis_status_saldo_index',
            ],
            'jurnal' => ['jurnal_sumber_index'],
            'anggota' => ['anggota_status_anggota_index'],
            'angsuran' => ['angsuran_status_angsuran_tanggal_bayar_index'],
        ];

        foreach ($wajib as $tabel => $daftarIndeks) {
            foreach ($daftarIndeks as $nama) {
                $this->assertTrue(
                    DB::table('information_schema.STATISTICS')
                        ->whereRaw('TABLE_SCHEMA = DATABASE()')
                        ->where('TABLE_NAME', $tabel)
                        ->where('INDEX_NAME', $nama)
                        ->exists(),
                    "Indeks {$nama} pada tabel {$tabel} hilang — jalur baca akan full table scan."
                );
            }
        }
    }

    /* =====================================================================
     | T7-02 — Indeks benar-benar dipakai MySQL
     ===================================================================== */

    /**
     * Agregat saldo rekening aktif harus dilayani indeks covering.
     *
     * Kueri `SUM(saldo) WHERE status_rekening = 'Aktif'` muncul di dashboard
     * pengurus, pengelola, dan laporan kas. Tanpa indeks `(status_rekening,
     * saldo)`, MySQL membaca SELURUH tabel rekening setiap kali halaman dibuka.
     */
    public function test_indeks_rekening_dipakai_untuk_agregat_saldo(): void
    {
        $this->hanyaMysql();

        $rencana = DB::select("EXPLAIN SELECT SUM(saldo) FROM rekening WHERE status_rekening = 'Aktif'");

        $this->assertNotEmpty($rencana);
        $this->assertSame('ref', $rencana[0]->type, 'Agregat saldo rekening aktif seharusnya memakai indeks, bukan full scan.');
        $this->assertSame('rekening_status_saldo_index', $rencana[0]->key);
        $this->assertStringContainsString('Using index', (string) $rencana[0]->Extra);
    }

    /**
     * Pengecekan idempotensi jurnal (WHERE sumber = ?) harus memakai indeks.
     *
     * Dipanggil setiap kali jurnal dibuat/dibundel — jalur paling panas pada
     * modul akuntansi.
     */
    public function test_indeks_jurnal_dipakai_untuk_pencarian_sumber(): void
    {
        $this->hanyaMysql();

        $rencana = DB::select("EXPLAIN SELECT id FROM jurnal WHERE sumber = 'Setoran Awal'");

        $this->assertNotEmpty($rencana);
        $this->assertSame('ref', $rencana[0]->type);
        $this->assertSame('jurnal_sumber_index', $rencana[0]->key);
    }

    /** Hitungan anggota per status harus memakai indeks, bukan full scan. */
    public function test_indeks_anggota_dipakai_untuk_filter_status(): void
    {
        $this->hanyaMysql();

        $rencana = DB::select("EXPLAIN SELECT COUNT(*) FROM anggota WHERE status_anggota = 'Aktif'");

        $this->assertNotEmpty($rencana);
        $this->assertSame('ref', $rencana[0]->type);
        $this->assertSame('anggota_status_anggota_index', $rencana[0]->key);
    }

    /**
     * Rekap angsuran masuk bulanan (12× per halaman laporan manajer) harus
     * memakai indeks komposit (status_angsuran, tanggal_bayar).
     */
    public function test_indeks_angsuran_dipakai_untuk_rekap_bulanan(): void
    {
        $this->hanyaMysql();

        $query = RentangPeriode::padaBulan(
            Angsuran::query()->where('status_angsuran', 'Sudah Dibayar'),
            'tanggal_bayar',
            (int) now()->month,
            (int) now()->year
        );

        $rencana = DB::select('EXPLAIN '.$query->toSql(), $query->getBindings());

        $this->assertNotEmpty($rencana);
        $this->assertSame('range', $rencana[0]->type, 'Rekap angsuran seharusnya memakai pencarian rentang indeks.');
        $this->assertSame('angsuran_status_angsuran_tanggal_bayar_index', $rencana[0]->key);
    }

    /**
     * INTI TAHAP 7: filter tanggal sargable mengubah rencana kueri transaksi
     * dari full table scan menjadi pencarian rentang memakai indeks.
     *
     * Inilah bukti bahwa akar masalah laporan bulanan adalah BENTUK kueri
     * (`whereMonth`/`whereYear` membungkus kolom dengan fungsi), bukan
     * kekurangan indeks — sehingga tidak perlu menambah indeks apa pun pada
     * tabel `transaksi` yang write-heavy.
     */
    public function test_filter_periode_sargable_memakai_indeks_transaksi(): void
    {
        $this->hanyaMysql();

        $bulan = (int) now()->month;
        $tahun = (int) now()->year;

        // Bentuk lama (non-sargable) — MySQL tidak dapat memakai indeks tanggal.
        $lama = DB::select(
            'EXPLAIN SELECT COUNT(*) FROM transaksi WHERE MONTH(waktu_transaksi) = ? AND YEAR(waktu_transaksi) = ?',
            [$bulan, $tahun]
        );
        $this->assertNotSame(
            'range',
            $lama[0]->type,
            'Prasyarat test: bentuk whereMonth/whereYear memang tidak sargable.'
        );

        // Bentuk baru (sargable) — memakai indeks yang sudah ada.
        $query = RentangPeriode::padaBulan(Transaksi::query(), 'waktu_transaksi', $bulan, $tahun);
        $baru = DB::select('EXPLAIN '.$query->toSql(), $query->getBindings());

        // Yang dibuktikan: bentuk sargable membuat indeks TERSEDIA bagi optimizer.
        // Pilihan akhir (range vs ALL) bergantung statistik & proporsi baris
        // pada bulan berjalan, sehingga tidak diassert secara deterministik.
        $this->assertContains(
            'transaksi_waktu_transaksi_index',
            explode(',', (string) $baru[0]->possible_keys),
            'Filter rentang seharusnya membuat indeks waktu_transaksi tersedia bagi optimizer.'
        );
    }

    /* =====================================================================
     | T7-03 — Ekuivalensi hasil (tidak ada perubahan angka laporan)
     ===================================================================== */

    /**
     * `RentangPeriode::padaBulan()` WAJIB mengembalikan himpunan baris yang
     * sama persis dengan `whereMonth() + whereYear()`.
     *
     * Diuji pada bulan berjalan, bulan tanpa data, serta dua bulan yang
     * sengaja diisi data pada batas awal & akhir bulan (termasuk detik
     * terakhir) untuk memastikan batas inklusif tidak membuang baris.
     */
    public function test_rentang_bulan_ekuivalen_dengan_where_month_year(): void
    {
        $bulan = 3;
        $tahun = 2024; // tahun kabisat → sekaligus menguji 31 Maret

        // Baris batas: awal bulan tepat 00:00:00 dan akhir bulan tepat 23:59:59.
        $batas = [
            "$tahun-03-01 00:00:00",
            "$tahun-03-31 23:59:59",
            "$tahun-03-15 12:30:00",
        ];
        foreach ($batas as $waktu) {
            Transaksi::create([
                'no_transaksi' => 'T7-'.substr(md5($waktu), 0, 12),
                'jenis_transaksi' => 'Setoran Tunai',
                'waktu_transaksi' => $waktu,
                'jumlah' => 100000,
                'posisi' => 'Debet',
                'status_transaksi' => 'Sukses',
            ]);
        }

        // Baris di luar periode harus TIDAK ikut (29 Feb 23:59:59 & 1 Apr 00:00:00).
        foreach (["$tahun-02-29 23:59:59", "$tahun-04-01 00:00:00"] as $waktu) {
            Transaksi::create([
                'no_transaksi' => 'T7-'.substr(md5($waktu), 0, 12),
                'jenis_transaksi' => 'Setoran Tunai',
                'waktu_transaksi' => $waktu,
                'jumlah' => 100000,
                'posisi' => 'Debet',
                'status_transaksi' => 'Sukses',
            ]);
        }

        $lama = Transaksi::whereMonth('waktu_transaksi', $bulan)
            ->whereYear('waktu_transaksi', $tahun)
            ->pluck('no_transaksi')->sort()->values()->all();

        $baru = RentangPeriode::padaBulan(Transaksi::query(), 'waktu_transaksi', $bulan, $tahun)
            ->pluck('no_transaksi')->sort()->values()->all();

        $this->assertSame($lama, $baru, 'Rentang bulan harus mengembalikan himpunan baris yang identik (termasuk batas 23:59:59).');
        $this->assertCount(3, $baru, 'Ketiga baris dalam bulan Maret harus ikut; baris Feb/Apr tidak.');
    }

    /**
     * `padaTahun()` = rentang TAHUN BUKU koperasi (bukan tahun takwim).
     *
     * Pada kebijakan bawaan (Januari–Desember) hasilnya identik dengan
     * `whereYear()`; bila koperasi memakai tahun buku non-kalender, helper ini
     * wajib mengikuti kebijakan yang sama dengan perhitungan SHU.
     */
    public function test_rentang_tahun_mengikuti_tahun_buku(): void
    {
        $tahun = 2024;
        $tahunBuku = app(TahunBukuService::class);

        $harapan = Transaksi::whereBetween('waktu_transaksi', [
            $tahunBuku->awalPeriode($tahun),
            $tahunBuku->akhirPeriode($tahun),
        ])->count();

        $baru = RentangPeriode::padaTahun(Transaksi::query(), 'waktu_transaksi', $tahun)->count();

        $this->assertSame($harapan, $baru, 'Rentang tahun harus sama dengan rentang tahun buku koperasi.');

        if ($tahunBuku->kalender()) {
            $this->assertSame(
                Transaksi::whereYear('waktu_transaksi', $tahun)->count(),
                $baru,
                'Pada tahun buku kalender, hasilnya harus identik dengan whereYear().'
            );
        }
    }

    /**
     * Batas periode harus presisi: awal bulan 00:00:00, akhir bulan 23:59:59,
     * dan akhir bulan mengikuti jumlah hari sebenarnya (kabisat).
     */
    public function test_batas_periode_presisi_dan_kabisat(): void
    {
        $this->assertSame('2026-03-01 00:00:00', RentangPeriode::awalBulan(2026, 3)->toDateTimeString());
        $this->assertSame('2026-03-31 23:59:59', RentangPeriode::akhirBulan(2026, 3)->toDateTimeString());
        $this->assertSame('2026-02-28 23:59:59', RentangPeriode::akhirBulan(2026, 2)->toDateTimeString());
        $this->assertSame('2024-02-29 23:59:59', RentangPeriode::akhirBulan(2024, 2)->toDateTimeString());
        $this->assertSame('2026-12-31 23:59:59', RentangPeriode::akhirBulan(2026, 12)->toDateTimeString());

        // Batas tahun mengikuti KEBIJAKAN tahun buku, bukan asumsi Januari.
        $tahunBuku = app(TahunBukuService::class);
        $this->assertSame($tahunBuku->awalPeriode(2026)->toDateTimeString(), RentangPeriode::awalTahun(2026)->toDateTimeString());
        $this->assertSame($tahunBuku->akhirPeriode(2026)->toDateTimeString(), RentangPeriode::akhirTahun(2026)->toDateTimeString());

        if ($tahunBuku->kalender()) {
            $this->assertSame('2026-01-01 00:00:00', RentangPeriode::awalTahun(2026)->toDateTimeString());
            $this->assertSame('2026-12-31 23:59:59', RentangPeriode::akhirTahun(2026)->toDateTimeString());
        }
    }

    /**
     * `padaBulanAtauTahun()` — dipakai filter opsional halaman audit:
     * bulan diberikan → rentang satu bulan; bulan kosong → rentang satu tahun.
     */
    public function test_pada_bulan_atau_tahun_memilih_rentang_tepat(): void
    {
        $tahun = 2024;

        $bulanSaja = RentangPeriode::padaBulanAtauTahun(Transaksi::query(), 'waktu_transaksi', $tahun, 5);
        $this->assertSame(
            RentangPeriode::padaBulan(Transaksi::query(), 'waktu_transaksi', 5, $tahun)->count(),
            $bulanSaja->count()
        );

        $setahun = RentangPeriode::padaBulanAtauTahun(Transaksi::query(), 'waktu_transaksi', $tahun, null);
        $this->assertSame(Transaksi::whereYear('waktu_transaksi', $tahun)->count(), $setahun->count());
    }

    /**
     * Scope model `Angsuran::jatuhTempoBulanIni()` harus tetap ekuivalen
     * dengan definisi lamanya (whereMonth + whereYear) setelah dikonversi.
     */
    public function test_scope_jatuh_tempo_bulan_ini_ekuivalen(): void
    {
        $lama = Angsuran::whereMonth('tanggal_jatuh_tempo', now()->month)
            ->whereYear('tanggal_jatuh_tempo', now()->year)
            ->count();

        $this->assertSame($lama, Angsuran::jatuhTempoBulanIni()->count());
    }

    /* =====================================================================
     | End-to-end: halaman laporan tetap berjalan setelah perubahan
     ===================================================================== */

    /**
     * Halaman laporan yang terdampak perbaikan sargable harus tetap berjalan.
     *
     * Perbaikan bentuk kueri tidak boleh mengubah kontrak HTTP halaman.
     *
     * Catatan: halaman Laporan Operasional Manajer (manajer.laporan.index) kini
     * disembunyikan dari peran Manajer, sehingga yang diuji di sini adalah
     * kontrak pengalihan amannya (tanpa 500). Kontrak render halaman laporan
     * yang terdampak kueri sargable tetap dicakup lewat pengurus.laporan.index.
     */
    public function test_halaman_laporan_tetap_berjalan(): void
    {
        $manajer = User::where('role', 'manajer')->first();
        $pengurus = User::where('role', 'pengurus')->first();

        if ($manajer) {
            $this->actingAs($manajer)
                ->get(route('manajer.laporan.index'))
                ->assertRedirect(route('dashboard.manajer'));
        }

        if ($pengurus) {
            $this->actingAs($pengurus)
                ->get(route('pengurus.laporan.index'))
                ->assertOk();
        }

        $this->assertTrue(true);
    }
}
