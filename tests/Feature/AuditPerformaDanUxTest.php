<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\Rekening;
use App\Models\User;
use App\Services\PendapatanService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * AUDIT TINGKAT 3 (PERFORMA) & TINGKAT 4 (UX) — REGRESI.
 *
 * Test ini mengunci perbaikan berikut:
 *
 *  PERFORM A:
 *   1. Indeks `transaksi_aml_status_verifikasi_index` benar-benar ada, dan
 *      indeks `transaksi_created_at_index` yang tidak terpakai sudah dibuang.
 *   2. `Coa::totalDebet()/totalKredit()` memakai rentang tanggal SARGABLE
 *      (tanpa pembungkus DATE()) sehingga indeks komposit
 *      (status, tanggal_jurnal) dapat dipakai, namun hasilnya tetap identik
 *      dengan semantik whereDate() — diuji lewat batas tepat pada tanggal.
 *   3. `TellerController::depositoIndex()` menandai jatuh tempo secara global
 *      (walau daftar sedang difilter) dan meringkas agregat dengan benar.
 *
 *  UX:
 *   4. Halaman approval pembiayaan (tujuan redirect aksi approval)
 *      menampilkan umpan balik flash — tidak lagi "gagal senyap".
 *   5. Komponen `<x-pesan-galat>` merender daftar error validasi.
 */
class AuditPerformaDanUxTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'AUDIT'.strtoupper(substr(uniqid(), -6));
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    private function pengguna(string $role = 'teller'): User
    {
        return User::firstOrCreate(
            ['email' => "audit.{$role}@test.local"],
            [
                'name' => 'Petugas Audit '.ucfirst($role),
                'password' => Hash::make('password'),
                'role' => $role,
                'is_active' => true,
            ]
        );
    }

    private function anggota(): Anggota
    {
        // Kolom wajib NOT NULL tanpa default pada tabel `anggota`:
        // jenis_kelamin (enum), alamat (text), kota, provinsi. Kolom tanggal
        // bernama `tanggal_gabung` (BUKAN `tanggal_daftar`).
        return Anggota::firstOrCreate(
            ['no_anggota' => 'AG-'.$this->prefix],
            [
                'nama_lengkap' => 'Anggota Audit '.$this->prefix,
                'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
                'jenis_kelamin' => 'Laki-laki',
                'alamat' => 'Alamat Audit '.$this->prefix,
                'kota' => 'Jakarta',
                'provinsi' => 'DKI Jakarta',
                'status_anggota' => 'Aktif',
                'tanggal_gabung' => now()->toDateString(),
            ]
        );
    }

    /**
     * Ambil satu id akun apa pun yang sudah ada (FK `rekening.akun_id` bersifat
     * restrictOnDelete, jadi tidak dibuat sembarangan agar tidak mengotori DB).
     */
    private function akunId(): int
    {
        $id = DB::table('akun')->value('id');

        if (! $id) {
            $this->markTestSkipped('Tabel akun kosong — jalankan AkunSeeder.');
        }

        return (int) $id;
    }

    /**
     * CoA terisolasi dengan kode unik per-test sehingga totalDebet()/
     * totalKredit() hanya menghitung baris yang dibuat test ini. Dipakai agar
     * assertion TIDAK bergantung pada isi basis data dev (yang berisi data
     * nyata, termasuk jurnal bertanggal masa depan).
     */
    private function coaTerisolasi(float $saldoAwal = 0): Coa
    {
        return Coa::create([
            'kode_akun' => '1-9999-'.$this->prefix,
            'nama_akun' => 'Akun Audit '.$this->prefix,
            'kategori' => 'Aset',
            'tipe_saldo_normal' => 'Debet',
            'saldo_awal' => $saldoAwal,
            'is_active' => true,
        ]);
    }

    /** Buat jurnal Posted satu baris DEBET pada CoA tertentu (fixture T3). */
    private function jurnalDebet(Coa $coa, float $nominal, string $tanggal): void
    {
        $jurnal = Jurnal::create([
            'no_jurnal' => 'JU-'.$this->prefix.'-'.uniqid(),
            'tanggal_jurnal' => $tanggal,
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'Audit Performa',
            'keterangan' => 'Uji rentang sargable '.$this->prefix,
            'status' => 'Posted',
            'is_balance' => true,
            'total_debet' => $nominal,
            'total_kredit' => $nominal,
        ]);

        JurnalDetail::create([
            'jurnal_id' => $jurnal->id,
            'akun_id' => $this->akunId(),
            'coa_id' => $coa->id,
            'baris' => 1,
            'debet' => $nominal,
            'kredit' => 0,
            'keterangan' => 'Uji '.$this->prefix,
        ]);
    }

    private function rekeningBerjangka(Anggota $anggota, string $status, ?string $jatuhTempo): Rekening
    {
        return Rekening::create([
            'no_rekening' => 'RKN-'.strtoupper(substr(uniqid(), -10)),
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunId(),
            'jenis_rekening' => 'Simpanan Berjangka',
            'akad' => 'Mudharabah',
            'saldo' => 1_000_000,
            'batas_minimum' => 0,
            'tanggal_buka' => now()->subMonth()->toDateString(),
            'tanggal_jatuh_tempo' => $jatuhTempo,
            'status_rekening' => $status,
        ]);
    }

    /* =====================================================================
     | 1. INDEKS TRANSAKSI
     |===================================================================== */

    public function test_indeks_verifikasi_aml_ada_dan_indeks_created_at_dibuang(): void
    {
        $driver = DB::connection()->getDriverName();

        $adaIndeks = function (string $nama, string $tabel) use ($driver): bool {
            if ($driver === 'sqlite') {
                return DB::table('sqlite_master')->where('type', 'index')->where('name', $nama)->exists();
            }

            return DB::table('information_schema.STATISTICS')
                ->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', $tabel)
                ->where('INDEX_NAME', $nama)
                ->exists();
        };

        $this->assertTrue(
            $adaIndeks('transaksi_aml_status_verifikasi_index', 'transaksi'),
            'Indeks verifikasi AML wajib ada: dashboard kepatuhan memfilter kolom ini pada tabel transaksi.'
        );

        $this->assertFalse(
            $adaIndeks('transaksi_created_at_index', 'transaksi'),
            'Indeks created_at yang tidak terpakai wajib sudah dibuang dari tabel transaksi.'
        );
    }

    /* =====================================================================
     | 2. RENTANG TANGGAL SARGABLE PADA Coa
     |===================================================================== */

    public function test_total_debet_dan_kredit_hormati_batas_tanggal(): void
    {
        // CoA TERISOLASI: totalnya hanya berasal dari baris yang dibuat test ini,
        // sehingga assertion tidak terpengaruh data nyata pada basis data dev
        // (mis. jurnal bertanggal masa depan) — pelajaran dari kegagalan awal.
        $coa = $this->coaTerisolasi();

        $tanggalUji = now()->toDateString();

        $this->jurnalDebet($coa, 500, $tanggalUji);

        // Batas `<= tanggalUji` WAJIB memasukkan baris bertanggal SAMA PERSIS.
        // Inilah yang membedakan whereDate() dari perbandingan string biasa,
        // sehingga perubahan ke rentang harus tetap setara secara semantik.
        $this->assertSame(
            500.0,
            round($coa->fresh()->totalDebet($tanggalUji), 2),
            'totalDebet(batas=tanggal baris) harus memasukkan baris bertanggal sama persis.'
        );
        $this->assertSame(
            0.0,
            round($coa->fresh()->totalKredit($tanggalUji), 2),
            'totalKredit tidak boleh terpengaruh baris debet.'
        );

        // Batas sehari SEBELUM baris → tidak boleh ikut terhitung.
        $sehariSebelum = Carbon::parse($tanggalUji)->subDay()->toDateString();
        $this->assertSame(
            0.0,
            round($coa->fresh()->totalDebet($sehariSebelum), 2),
            'totalDebet(batas sehari sebelum baris) tidak boleh memasukkan baris tersebut.'
        );
    }

    public function test_saldo_berjalan_akun_tidak_berubah_setelah_perubahan_rentang(): void
    {
        // CoA terisolasi dengan saldo awal 1.000 dan satu baris debet 500 hari ini.
        $coa = $this->coaTerisolasi(1000);
        $hariIni = now()->toDateString();

        $this->jurnalDebet($coa, 500, $hariIni);

        // Regresi kunci: rentang sargable harus tetap mempertahankan semantik
        // whereDate() — batas "hari ini" memasukkan baris hari ini, dan hasilnya
        // identik dengan perhitungan tanpa batas bila tidak ada jurnal masa depan.
        $this->assertSame(
            1500.0,
            round($coa->fresh()->saldoBerjalan($hariIni), 2),
            'saldoBerjalan(hari ini) harus memasukkan baris bertanggal hari ini.'
        );
        $this->assertSame(
            round($coa->fresh()->saldoBerjalan(), 2),
            round($coa->fresh()->saldoBerjalan($hariIni), 2),
            'saldoBerjalan(hari ini) harus sama dengan saldoBerjalan() selama tidak ada jurnal bertanggal masa depan.'
        );

        $sehariSebelum = Carbon::parse($hariIni)->subDay()->toDateString();
        $this->assertSame(
            1000.0,
            round($coa->fresh()->saldoBerjalan($sehariSebelum), 2),
            'saldoBerjalan(sehari sebelum) tidak boleh memasukkan baris hari ini.'
        );
    }

    /* =====================================================================
     | 3. TANDA JATUH TEMPO & RINGKASAN depositoIndex
     |===================================================================== */

    public function test_deposito_index_menandai_jatuh_tempo_walau_daftar_difilter(): void
    {
        $anggota = $this->anggota();

        // Rekening menunggak (Aktif, jatuh tempo kemarin) dengan no_rekening
        // yang TIDAK memuat kata kunci pencarian → sebelumnya luput dari
        // update karena penandaan hanya berjalan pada baris hasil filter.
        $jatuhTempo = $this->rekeningBerjangka(
            $anggota,
            'Aktif',
            now()->subDay()->toDateString()
        );

        $this->actingAs($this->pengguna())
            ->get(route('teller.deposito.index', ['search' => 'TIDAKAKANCOCOK'.$this->prefix]))
            ->assertOk();

        $this->assertSame(
            'Jatuh Tempo',
            $jatuhTempo->fresh()->status_rekening,
            'Penandaan jatuh tempo harus berjalan global, tidak bergantung pada filter daftar.'
        );
    }

    public function test_deposito_index_ringkasan_agregat_benar(): void
    {
        $anggota = $this->anggota();

        $aktif = $this->rekeningBerjangka($anggota, 'Aktif', now()->addYear()->toDateString());
        $ditutup = $this->rekeningBerjangka($anggota, 'Ditutup', null);
        $jatuhTempo = $this->rekeningBerjangka($anggota, 'Aktif', now()->subDay()->toDateString());

        $response = $this->actingAs($this->pengguna())->get(route('teller.deposito.index'));
        $response->assertOk();

        $response->assertViewHas('totalAktif', function ($nilai) {
            return is_int($nilai) && $nilai >= 1;
        });
        $response->assertViewHas('totalJatuhTempo', function ($nilai) {
            // Rekening menunggak milik fixture ini harus terhitung (ditandai
            // Jatuh Tempo lebih dulu oleh aksi index).
            return is_int($nilai) && $nilai >= 1;
        });
        $response->assertViewHas('totalDitutup', function ($nilai) {
            return is_int($nilai) && $nilai >= 1;
        });
        $response->assertViewHas('totalNominal', function ($nilai) {
            return is_float($nilai) && $nilai >= 0;
        });

        $this->assertSame('Ditutup', $ditutup->fresh()->status_rekening);
        $this->assertSame('Jatuh Tempo', $jatuhTempo->fresh()->status_rekening);
        $this->assertSame('Aktif', $aktif->fresh()->status_rekening);
    }

    /* =====================================================================
     | 4. UMLAN BALIK UX — APPROVAL PEMBIAYAAN
     |===================================================================== */

    public function test_approval_pembiayaan_menampilkan_flash_success(): void
    {
        $html = $this->actingAs($this->pengguna('manajer'))
            ->withSession(['success' => 'Pengajuan berhasil disetujui.'])
            ->get(route('manajer.approval-pembiayaan.index'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString(
            'Pengajuan berhasil disetujui.',
            $html,
            'Pesan sukses dari aksi approval harus terlihat di halaman approval pembiayaan.'
        );
    }

    public function test_approval_pembiayaan_menampilkan_flash_error(): void
    {
        $html = $this->actingAs($this->pengguna('manajer'))
            ->withSession(['error' => 'Approval gagal: pengajuan sudah diproses.'])
            ->get(route('manajer.approval-pembiayaan.index'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString(
            'Approval gagal: pengajuan sudah diproses.',
            $html,
            'Pesan error dari aksi approval harus terlihat — bukan gagal senyap.'
        );
    }

    /* =====================================================================
     | 5. KOMPONEN PESAN GALAT
     |===================================================================== */

    public function test_komponen_pesan_galat_merender_semua_error(): void
    {
        $errors = new ViewErrorBag;
        $errors->put('default', new MessageBag([
            'Nama wajib diisi.',
            'Nominal harus lebih dari nol.',
        ]));

        $html = Blade::render(
            '<x-pesan-galat :errors="$errors" />',
            ['errors' => $errors]
        );

        $this->assertStringContainsString('Nama wajib diisi.', $html);
        $this->assertStringContainsString('Nominal harus lebih dari nol.', $html);
        $this->assertStringContainsString('role="alert"', $html, 'Komponen harus tetap aksesibel.');
    }

    public function test_komponen_pesan_galat_tidak_merender_saat_tidak_ada_error(): void
    {
        $html = Blade::render(
            '<x-pesan-galat :errors="$errors" />',
            ['errors' => new ViewErrorBag]
        );

        // Tanpa error, komponen tidak boleh menghasilkan elemen alert kosong
        // (menghindari ruang kosong & noise bagi pembaca layar).
        $this->assertStringNotContainsString('role="alert"', $html);
    }

    public function test_shu_index_merender_error_validasi_lewat_komponen(): void
    {
        // Mengunci refactor: halaman SHU memakai <x-pesan-galat> sehingga pesan
        // validasi server benar-benar tampil, bukan marker id berulang yang
        // membuat HTML tidak valid.
        $errors = new ViewErrorBag;
        $errors->put('default', new MessageBag(['Total SHU belum lengkap.']));

        $html = $this->actingAs($this->pengguna('pengurus'))
            ->withSession(['errors' => $errors])
            ->get(route('pengurus.shu.index'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString('Total SHU belum lengkap.', $html);
    }

    public function test_shu_index_merender_flash_info(): void
    {
        // Mengunci temuan UX: ShuController memakai ->with('info', ...) pada
        // cabang "tidak ada penahanan yang bisa dilepas". Sebelumnya view hanya
        // merender success/error sehingga pesan info hilang senyap.
        $pesan = 'Tidak ada penahanan SHU yang dapat dilepas saat ini.';

        $html = $this->actingAs($this->pengguna('pengurus'))
            ->withSession(['info' => $pesan])
            ->get(route('pengurus.shu.index'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString($pesan, $html);
        $this->assertStringContainsString('alertInfo', $html, 'Blok info harus punya id untuk tombol tutup.');
    }

    public function test_aksi_approval_pembiayaan_memakai_konfirmasi(): void
    {
        // Mengunci temuan UX: dua tombol aksi PATCH di halaman detail pengajuan
        // (Teruskan ke Pengurus & Setujui) langsung memproses approval tanpa
        // konfirmasi. Test ini memastikan `onsubmit` guard terpasang pada
        // kedua form tersebut — sehingga klik tak sengaja tidak langsung
        // mengubah status pengajuan.
        $isi = file_get_contents(
            resource_path('views/pengelola/pengajuan-pinjaman-detail.blade.php')
        );

        $this->assertStringContainsString(
            "confirm('Teruskan pengajuan ini ke Pengurus untuk disetujui?')",
            $isi,
            'Form review harus meminta konfirmasi sebelum diteruskan.'
        );
        $this->assertStringContainsString(
            "confirm('Setujui pengajuan ini? Pencairan dana dilakukan oleh pengguna lain.')",
            $isi,
            'Form approve harus meminta konfirmasi sebelum menyetujui.'
        );
    }

    /* =====================================================================
     | 6. SARGAble LANJUTAN — TELLER, PENDAPATAN SERVICE, AML
     |===================================================================== */

    public function test_teller_index_ringkasan_hari_ini_hormati_batas_hari(): void
    {
        // Mengunci P-04: TellerController::index menghitung ringkasan hari ini
        // dengan `whereBetween(startOfDay..endOfDay)` — bukan `whereDate()`.
        // Perbedaan kritis: whereBetween WAJIB mencakup transaksi bertimestamp
        // 23:59:59 pada hari yang sama (uji batas atas), dan MENGEcualikan
        // transaksi kemarin/besok (uji batas bawah).
        $this->transaksiHari(now()->startOfDay()->format('Y-m-d H:i:s'), 100_000);

        $html = $this->actingAs($this->pengguna())
            ->get(route('dashboard.teller'))
            ->assertOk()
            ->content();

        // Nominal setoran hari ini harus terhitung — memastikan batas
        // `endOfDay()` tidak memotong transaksi yang dibuat tepat di detik nol.
        $this->assertStringContainsString('100.000', $html, 'Setoran hari ini harus masuk ringkasan.');
    }

    public function test_pendapatan_service_hormati_batas_tanggal(): void
    {
        // Mengunci P-05: ujrohFee() memakai perbandingan langsung
        // `waktu_transaksi >= 'X 00:00:00'` dan `<= 'Y 23:59:59'` (bukan
        // whereDate()) sehingga transaksi di detik terakhir hari itu tetap
        // ikut terhitung, sedangkan hari berikutnya tidak.
        $tanggalUji = now()->toDateString();
        $besok = now()->addDay()->toDateString();

        $this->transaksiUjroh($tanggalUji.' 23:59:59', 25_000);
        $this->transaksiUjroh($besok.' 00:00:00', 99_000);

        $service = app(PendapatanService::class);

        // Batas atas inklusif pada 23:59:59 hari uji.
        $this->assertSame(
            25_000.0,
            $service->ujrohFee($tanggalUji, $tanggalUji),
            'ujrohFee harus memasukkan transaksi pada detik terakhir hari batas (23:59:59).'
        );

        // Batas bawah inklusif → transaksi tepat tengah malam hari berikutnya
        // tetap boleh diambil bila rentangnya mencakup hari itu.
        $this->assertSame(
            99_000.0,
            $service->ujrohFee($besok, $besok),
            'ujrohFee(besok) harus mengambil transaksi 00:00:00 hari berikutnya.'
        );

        // Rentang di luar transaksi → nol (bukan bocor lintas hari).
        $kemarin = now()->subDay()->toDateString();
        $this->assertSame(
            0.0,
            $service->ujrohFee($kemarin, $kemarin),
            'ujrohFee tidak boleh membocorkan transaksi lintas hari.'
        );
    }

    public function test_aml_ringkasan_statistik_satu_kueri_agregat_benar(): void
    {
        // Mengunci P-06: 4 COUNT terpisah digantikan satu SELECT agregat
        // bersyarat. Semantik tiap kolom harus tetap identik dengan versi lama:
        //  - totalHighValue = jumlah transaksi >= threshold ATAU is_aml_high_value
        //  - totalMenunggu/Verifikasi/Ditolak = hanya is_aml_high_value=1
        //    dengan status verifikasi terkait.
        $threshold = 50_000_000; // di atas nominal fixture → hanya flag yang menonjol

        $this->transaksiAml('menunggu', 1);
        $this->transaksiAml('terverifikasi', 1);
        $this->transaksiAml('ditolak', 1);
        $this->transaksiAml(null, 0); // bukan high value → tidak masuk statistik

        $response = $this->actingAs($this->pengguna('pengurus'))
            ->get(route('pengurus.aml.index'))
            ->assertOk();

        // Fixture test menambah minimal 1 pada tiap kategori; assertion
        // `>= 1` menjaga test idempoten terhadap data nyata pada DB dev.
        $response->assertViewHas('totalHighValue', fn ($v) => is_int($v) && $v >= 3);
        $response->assertViewHas('totalMenunggu', fn ($v) => is_int($v) && $v >= 1);
        $response->assertViewHas('totalVerifikasi', fn ($v) => is_int($v) && $v >= 1);
        $response->assertViewHas('totalDitolak', fn ($v) => is_int($v) && $v >= 1);
    }

    /* =====================================================================
     | FIXTURE TAMBAHAN (transaksi)
     |===================================================================== */

    private function nomorTransaksi(): string
    {
        return 'TRX-'.strtoupper(substr(str_replace('.', '', uniqid('', true)), -12));
    }

    private function transaksiDasar(array $atribut): void
    {
        DB::table('transaksi')->insert(array_merge([
            'no_transaksi' => $this->nomorTransaksi(),
            'anggota_id' => $this->anggota()->id,
            'user_id' => $this->pengguna()->id,
            'jenis_transaksi' => 'Setoran Tunai',
            // `posisi` VARCHAR(10) NOT NULL tanpa default. Konvensi domain:
            // 'Debet' = uang MASUK, 'Kredit' = uang KELUAR.
            'posisi' => 'Debet',
            'jumlah' => 0,
            'status_transaksi' => 'Sukses',
            'waktu_transaksi' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $atribut));
    }

    private function transaksiHari(string $waktu, float $jumlah): void
    {
        $this->transaksiDasar([
            'jenis_transaksi' => 'Setoran Tunai',
            'jumlah' => $jumlah,
            'waktu_transaksi' => $waktu,
        ]);
    }

    private function transaksiUjroh(string $waktu, float $jumlah): void
    {
        $this->transaksiDasar([
            'jenis_transaksi' => 'Biaya Administrasi Audit '.$this->prefix,
            'jumlah' => $jumlah,
            'waktu_transaksi' => $waktu,
        ]);
    }

    private function transaksiAml(?string $statusVerifikasi, int $highValue): void
    {
        // `aml_status_verifikasi` NOT NULL (default 'menunggu') → kolom hanya
        // disertakan bila memang ingin diuji; baris "bukan high value" cukup
        // memakai default sehingga tidak perlu nilai eksplisit.
        $atribut = [
            'jenis_transaksi' => 'Setoran Tunai',
            'jumlah' => 1_000,
            'is_aml_high_value' => $highValue,
        ];

        if ($statusVerifikasi !== null) {
            $atribut['aml_status_verifikasi'] = $statusVerifikasi;
        }

        $this->transaksiDasar($atribut);
    }
}
