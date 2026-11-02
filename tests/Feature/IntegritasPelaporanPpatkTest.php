<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Coa;
use App\Models\KebijakanKoperasi;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\PpatkReportService;
use App\Support\Coa as KodeCoa;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * BATCH 13 — Modul PELAPORAN PPATK (LTKT / LTKM / STR) utuh.
 *
 * `PpatkReportService` — inti kewajiban pelaporan APU-PPT (UU No. 8/2010 &
 * POJK No. 27/POJK.03/2022) — sebelumnya TIDAK memiliki satu pun test,
 * padahal berkasnya diunduh Pengurus dan diserahkan ke PPATK melalui goAML.
 * Kesalahan di sini bukan kesalahan tampilan: koperasi dapat melaporkan
 * transaksi yang tidak pernah terjadi, atau justru gagal melaporkan transaksi
 * yang wajib dilaporkan.
 *
 * Fokus batch:
 *   P1  Batas periode — LTKT/LTKM/STR hanya memuat transaksi di dalam
 *       rentang `dari`..`sampai` (inklusif pada kedua ujung). Baris di luar
 *       periode tidak boleh bocor ke laporan.
 *   P2  Ambang LTKT — hanya transaksi TUNAI dengan nominal >= ambang.
 *       Transaksi non-tunai yang lebih besar tidak masuk LTKT.
 *   P3  Jenis transaksi tunai mengikuti kebijakan (dinamis dari Setting),
 *       bukan daftar literal di kode.
 *   P4  LTKM — memuat penanda AML (`is_aml_high_value` / `is_large_transaction`)
 *       dan nominal >= ambang AML; transaksi biasa tidak ikut.
 *   P5  STR — hanya transaksi yang SUDAH diputus kepatuhan (terverifikasi /
 *       ditolak); yang masih `menunggu` tidak boleh ikut terkirim.
 *   P6  CSV — kop identitas pelapor, judul kolom sesuai jenis, jumlah baris
 *       = 5 baris kop + 1 baris kosong + 1 judul + N data.
 */
class IntegritasPelaporanPpatkTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    private PpatkReportService $ppatk;

    private Carbon $hariIni;

    /**
     * Nilai asli kolom kebijakan yang akan diubah test, disimpan sebelum
     * perubahan agar dapat dipulihkan pada `tearDown()`.
     *
     * @var array<string, mixed>|null
     */
    private ?array $kebijakanAsli = null;

    /** ID baris kebijakan yang nilainya disimpan untuk dipulihkan. */
    private ?int $kebijakanId = null;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         | Penjaga integritas lintas-berkas.
         |
         | Tabel `kebijakan_koperasis` adalah baris TUNGGAL (singleton) berisi
         | 100+ kolom parameter yang dibaca `Setting::` oleh SELURUH modul.
         | Bila sebuah test mengubah kolom di baris itu lalu transaksinya
         | di-rollback sebagian / cache `Setting` ikut termaterialisasi,
         | nilai baru dapat bertahan dan mencemari berkas test lain pada gate
         | penuh (pernah terjadi: `akad_margin` terpotong sehingga
         | DashboardDpsPendapatanTest gagal).
         |
         | Karena itu test ini WAJIB menyimpan nilai asli sebelum menulis dan
         | memulihkannya di tearDown(), termasuk kolom kebijakan lain yang
         | mungkin tersentuh test.
         */
        $kebijakan = KebijakanKoperasi::query()->first();

        if ($kebijakan !== null) {
            $this->kebijakanId = (int) $kebijakan->getKey();
            $this->kebijakanAsli = [
                'ppatk_jenis_tunai' => $kebijakan->getRawOriginal('ppatk_jenis_tunai'),
                'akad_margin' => $kebijakan->getRawOriginal('akad_margin'),
            ];
        }

        foreach ([KodeCoa::KAS_TELLER, '3-1001'] as $kode) {
            if (Coa::findByKode($kode) === null) {
                $this->markTestSkipped("CoA {$kode} belum tersedia pada basis data uji.");
            }
        }

        $this->prefix = 'PA'.strtoupper(substr(uniqid(), -6));
        $this->ppatk = app(PpatkReportService::class);
        $this->hariIni = Carbon::today();

        // Agregasi transaksi uji terpisah dari baris yang mungkin sudah ada
        // pada basis data uji: seluruh laporan disaring lewat `catatan`.
        Setting::flush();
    }

    /**
     * Pulihkan kolom kebijakan yang diubah test, lalu buang cache Setting.
     *
     * Dijalankan walau test di-skip.
     */
    protected function tearDown(): void
    {
        if ($this->kebijakanAsli !== null && $this->kebijakanId !== null) {
            KebijakanKoperasi::query()->whereKey($this->kebijakanId)->update($this->kebijakanAsli);
        }

        $this->kebijakanAsli = null;
        $this->kebijakanId = null;

        Setting::flush();

        parent::tearDown();
    }

    /* =====================================================================
     | FIXTURE
     ===================================================================== */

    private function anggota(): Anggota
    {
        return Anggota::create([
            'no_anggota' => 'AGT-'.$this->prefix,
            'nama_lengkap' => 'Anggota PPATK '.$this->prefix,
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'jenis_kelamin' => 'Laki-laki',
            'no_hp' => '08'.str_pad((string) random_int(1, 9999999999), 10, '0', STR_PAD_LEFT),
            'alamat' => 'Jl. Uji Pelaporan No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_ACTIVE,
        ]);
    }

    private function teller(): User
    {
        return User::firstOrCreate(
            ['email' => 'ppatk.teller@test.local'],
            [
                'name' => 'Teller Pelaporan Uji',
                'password' => Hash::make('password'),
                'role' => 'teller',
                'is_active' => true,
            ]
        );
    }

    private function rekening(Anggota $anggota): Rekening
    {
        return Rekening::create([
            'no_rekening' => Rekening::nomorRekeningBaru(),
            'anggota_id' => $anggota->id,
            'akun_id' => Coa::findByKode('3-1001')->id,
            'jenis_rekening' => 'Simpanan Pokok',
            'akad' => 'Wadiah',
            'saldo' => 0,
            'batas_minimum' => 0,
            'tanggal_buka' => today()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);
    }

    /**
     * Transaksi uji dengan penanda `catatan` unik agar dapat disaring.
     *
     * @param  array<string, mixed>  $ganti
     */
    private function transaksi(array $ganti = []): Transaksi
    {
        $default = [
            'no_transaksi' => 'TRX-'.$this->prefix.'-'.strtoupper(substr(uniqid(), -5)),
            'jenis_transaksi' => 'Setoran Tunai',
            'jumlah' => 600000000.0,
            'posisi' => 'Debet',
            'status_transaksi' => 'Sukses',
            'status' => 'success',
            'waktu_transaksi' => $this->hariIni->copy()->setTime(10, 0),
            'sumber_transaksi' => 'Teller',
            'catatan' => $this->prefix,
        ];

        return Transaksi::create(array_merge($default, $ganti));
    }

    /** Nomor transaksi dari koleksi laporan (dipakai berulang). */
    private function nomorPada($baris): array
    {
        return $baris->pluck('NO_TRANSAKSI')->all();
    }

    /* =====================================================================
     | P1 — BATAS PERIODE
     ===================================================================== */

    public function test_ltkt_hanya_memuat_transaksi_di_dalam_periode(): void
    {
        $anggota = $this->anggota();
        $rekening = $this->rekening($anggota);

        $dalam = $this->transaksi(['anggota_id' => $anggota->id, 'rekening_id' => $rekening->id]);
        $sebelum = $this->transaksi([
            'anggota_id' => $anggota->id,
            'rekening_id' => $rekening->id,
            'waktu_transaksi' => $this->hariIni->copy()->subDay()->setTime(23, 59),
        ]);
        $sesudah = $this->transaksi([
            'anggota_id' => $anggota->id,
            'rekening_id' => $rekening->id,
            'waktu_transaksi' => $this->hariIni->copy()->addDay()->setTime(0, 1),
        ]);

        $nomor = $this->nomorPada(
            $this->ppatk->ltkt($this->hariIni->copy(), $this->hariIni->copy())
                ->filter(fn (array $b): bool => str_contains((string) $b['NO_TRANSAKSI'], $this->prefix))
        );

        $this->assertContains($dalam->no_transaksi, $nomor,
            'P1: transaksi pada hari laporan WAJIB masuk LTKT.');
        $this->assertNotContains($sebelum->no_transaksi, $nomor,
            'P1: transaksi SEBELUM periode tidak boleh bocor ke laporan.');
        $this->assertNotContains($sesudah->no_transaksi, $nomor,
            'P1: transaksi SESUDAH periode tidak boleh bocor ke laporan.');
    }

    public function test_batas_periode_inklusif_pada_kedua_ujung_hari(): void
    {
        $anggota = $this->anggota();
        $rekening = $this->rekening($anggota);

        $awal = $this->transaksi([
            'anggota_id' => $anggota->id,
            'rekening_id' => $rekening->id,
            'waktu_transaksi' => $this->hariIni->copy()->setTime(0, 0, 0),
        ]);
        $akhir = $this->transaksi([
            'anggota_id' => $anggota->id,
            'rekening_id' => $rekening->id,
            'waktu_transaksi' => $this->hariIni->copy()->setTime(23, 59, 59),
        ]);

        $nomor = $this->nomorPada(
            $this->ppatk->ltkt($this->hariIni->copy(), $this->hariIni->copy())
                ->filter(fn (array $b): bool => str_contains((string) $b['NO_TRANSAKSI'], $this->prefix))
        );

        $this->assertContains($awal->no_transaksi, $nomor,
            'P1: transaksi pukul 00:00:00 pada hari pertama periode harus ikut (inklusif).');
        $this->assertContains($akhir->no_transaksi, $nomor,
            'P1: transaksi pukul 23:59:59 pada hari terakhir periode harus ikut (inklusif).');
    }

    /* =====================================================================
     | P2 — AMBANG LTKT & HANYA TUNAI
     ===================================================================== */

    public function test_ltkt_hanya_nominal_mencapai_ambang_dan_hanya_tunai(): void
    {
        $anggota = $this->anggota();
        $rekening = $this->rekening($anggota);
        $ambang = $this->ppatk->ambangTunai();

        $pas = $this->transaksi([
            'anggota_id' => $anggota->id,
            'rekening_id' => $rekening->id,
            'jumlah' => $ambang,
        ]);
        $kurang = $this->transaksi([
            'anggota_id' => $anggota->id,
            'rekening_id' => $rekening->id,
            'jumlah' => $ambang - 1,
        ]);
        $nonTunai = $this->transaksi([
            'anggota_id' => $anggota->id,
            'rekening_id' => $rekening->id,
            'jenis_transaksi' => 'Transfer Masuk',
            'jumlah' => $ambang * 10,
        ]);

        $nomor = $this->nomorPada(
            $this->ppatk->ltkt($this->hariIni->copy(), $this->hariIni->copy())
                ->filter(fn (array $b): bool => str_contains((string) $b['NO_TRANSAKSI'], $this->prefix))
        );

        $this->assertContains($pas->no_transaksi, $nomor,
            'P2: nominal TEPAT di ambang (=) wajib dilaporkan LTKT.');
        $this->assertNotContains($kurang->no_transaksi, $nomor,
            'P2: nominal di bawah ambang tidak masuk LTKT.');
        $this->assertNotContains($nonTunai->no_transaksi, $nomor,
            'P2: transaksi NON-TUNAI tidak boleh masuk LTKT walau nominalnya jauh lebih besar.');
    }

    public function test_ambang_ltkt_terpisah_dari_ambang_aml(): void
    {
        $ambangTunai = $this->ppatk->ambangTunai();
        $ambangAml = $this->ppatk->ambangMencurigakan();

        $this->assertNotSame($ambangTunai, $ambangAml,
            'P2: ambang LTKT (transaksi tunai) dan ambang AML (nilai besar/mencurigakan) '
            .'adalah dua kebijakan berbeda dan tidak boleh tertukar. Nilai bawaan '
            .'LTKT Rp 500 juta, AML Rp 100 juta.');
    }

    /* =====================================================================
     | P3 — JENIS TRANSAKSI TUNAI DINAMIS
     ===================================================================== */

    public function test_jenis_transaksi_tunai_mengikuti_kebijakan(): void
    {
        $anggota = $this->anggota();
        $rekening = $this->rekening($anggota);

        // Kebijakan diubah: "Pencairan Pembiayaan" ditetapkan sebagai tunai.
        //
        // Bentuk penyimpanan RESMI adalah string ber-koma (bukan array/JSON):
        // PengurusController menyimpan `trim((string) $request->akad_margin)`
        // untuk parameter daftar, dan Setting::daftar() memecah nilai dengan
        // explode(',') sebelum casting. Menulis array ke kolom ini akan
        // tersimpan sebagai JSON dan terbaca sebagai satu item mentah
        // '["Setoran Tunai","Pencairan Pembiayaan"]' — itu cacat pemanggil,
        // bukan perilaku sistem.
        $kebijakan = KebijakanKoperasi::first() ?? new KebijakanKoperasi;
        $kebijakan->ppatk_jenis_tunai = 'Setoran Tunai,Pencairan Pembiayaan';
        $kebijakan->save();
        Setting::flush();

        $pencairan = $this->transaksi([
            'anggota_id' => $anggota->id,
            'rekening_id' => $rekening->id,
            'jenis_transaksi' => 'Pencairan Pembiayaan',
            'jumlah' => $this->ppatk->ambangTunai(),
        ]);

        $nomor = $this->nomorPada(
            $this->ppatk->ltkt($this->hariIni->copy(), $this->hariIni->copy())
                ->filter(fn (array $b): bool => str_contains((string) $b['NO_TRANSAKSI'], $this->prefix))
        );

        $this->assertContains($pencairan->no_transaksi, $nomor,
            'P3: jenis transaksi tunai WAJIB dibaca dari kebijakan. Setelah Pengurus '
            .'menetapkan "Pencairan Pembiayaan" sebagai tunai, transaksi itu harus '
            .'masuk LTKT tanpa mengubah kode.');
        $this->assertContains('Pencairan Pembiayaan', $this->ppatk->jenisTunai(),
            'P3: daftar jenis tunai dari service harus sama dengan kebijakan.');
    }

    /* =====================================================================
     | P4 — LTKM
     | ===================================================================== */

    public function test_ltkm_memuat_penanda_aml_dan_nominal_besar(): void
    {
        $anggota = $this->anggota();
        $rekening = $this->rekening($anggota);

        $bertanda = $this->transaksi([
            'anggota_id' => $anggota->id,
            'rekening_id' => $rekening->id,
            'jenis_transaksi' => 'Setoran Tunai',
            'jumlah' => 10000.0,
            'is_aml_high_value' => true,
            'aml_status_verifikasi' => 'menunggu',
        ]);
        $besar = $this->transaksi([
            'anggota_id' => $anggota->id,
            'rekening_id' => $rekening->id,
            'jenis_transaksi' => 'Transfer Masuk',
            'jumlah' => $this->ppatk->ambangMencurigakan(),
        ]);
        $biasa = $this->transaksi([
            'anggota_id' => $anggota->id,
            'rekening_id' => $rekening->id,
            'jumlah' => 50000.0,
        ]);

        $nomor = $this->nomorPada(
            $this->ppatk->ltkm($this->hariIni->copy(), $this->hariIni->copy())
                ->filter(fn (array $b): bool => str_contains((string) $b['NO_TRANSAKSI'], $this->prefix))
        );

        $this->assertContains($bertanda->no_transaksi, $nomor,
            'P4: transaksi penanda AML wajib masuk LTKM walau nominalnya kecil.');
        $this->assertContains($besar->no_transaksi, $nomor,
            'P4: nominal >= ambang AML wajib masuk LTKM walau jenisnya bukan tunai.');
        $this->assertNotContains($biasa->no_transaksi, $nomor,
            'P4: transaksi biasa tidak boleh membanjiri LTKM.');
    }

    /* =====================================================================
     | P5 — STR
     | ===================================================================== */

    public function test_str_hanya_memuat_transaksi_yang_sudah_diputus(): void
    {
        $anggota = $this->anggota();
        $rekening = $this->rekening($anggota);

        $dasar = [
            'anggota_id' => $anggota->id,
            'rekening_id' => $rekening->id,
            'is_aml_high_value' => true,
        ];

        $terverifikasi = $this->transaksi($dasar + ['aml_status_verifikasi' => 'terverifikasi']);
        $ditolak = $this->transaksi($dasar + ['aml_status_verifikasi' => 'ditolak']);
        $menunggu = $this->transaksi($dasar + ['aml_status_verifikasi' => 'menunggu']);

        $nomor = $this->nomorPada(
            $this->ppatk->str($this->hariIni->copy(), $this->hariIni->copy())
                ->filter(fn (array $b): bool => str_contains((string) $b['NO_TRANSAKSI'], $this->prefix))
        );

        $this->assertContains($terverifikasi->no_transaksi, $nomor,
            'P5: transaksi terverifikasi wajib masuk STR.');
        $this->assertContains($ditolak->no_transaksi, $nomor,
            'P5: transaksi ditolak juga wajib masuk STR (bahan tindak lanjut).');
        $this->assertNotContains($menunggu->no_transaksi, $nomor,
            'P5: transaksi yang belum diputus TIDAK boleh ikut terkirim ke PPATK lewat STR.');
    }

    /* =====================================================================
     | P6 — CSV
     | ===================================================================== */

    public function test_csv_memuat_kop_identitas_judul_kolom_dan_baris_data(): void
    {
        $anggota = $this->anggota();
        $rekening = $this->rekening($anggota);
        $trx = $this->transaksi([
            'anggota_id' => $anggota->id,
            'rekening_id' => $rekening->id,
            'jumlah' => $this->ppatk->ambangTunai(),
        ]);

        $csv = $this->ppatk->csv('LTKT', $this->hariIni->copy(), $this->hariIni->copy());
        $baris = array_map('str_getcsv', preg_split('/\r\n|\n|\r/', trim($csv)));

        $this->assertSame(['JENIS_LAPORAN', 'LTKT'], $baris[0],
            'P6: baris pertama CSV adalah kop jenis laporan.');
        $this->assertSame(['PELAPOR', $this->ppatk->identitasPelapor()['nama']], $baris[1],
            'P6: baris kedua CSV adalah identitas pelapor.');
        $this->assertSame('PERIODE_DARI', $baris[3][0],
            'P6: kop periode harus lengkap.');
        $this->assertSame($this->ppatk->judulKolom('LTKT'), $baris[6],
            'P6: baris judul kolom harus sesuai jenis laporan (5 kop + 1 kosong + judul).');

        $data = array_slice($baris, 7);
        $barisData = collect($data)->firstWhere(0, $trx->no_transaksi);

        $this->assertNotNull($barisData,
            'P6: transaksi di dalam periode harus muncul sebagai baris data CSV.');
        $this->assertCount(count($this->ppatk->judulKolom('LTKT')), $barisData,
            'P6: jumlah kolom baris data harus SAMA dengan jumlah judul kolom — '
            .'tanpa ini berkas ditolak validator goAML.');
    }

    public function test_csv_menolak_jenis_laporan_tidak_dikenal(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->ppatk->csv('LAPORAN_PALSU', $this->hariIni->copy(), $this->hariIni->copy());
    }

    /* =====================================================================
     | P7 — IDEMPOTENSI PENOLAKAN AML (terminal state)
     ===================================================================== */

    /**
     * G-01 — Transaksi yang sudah berstatus TERMINAL (Gagal / Voided) TIDAK
     * boleh ditolak lagi.
     *
     * Dampak yang TERBUKTI (skenario penarikan, `posisi = 'Kredit'`): transaksi
     * yang sudah `Voided` ditolak sekali lagi → `tolakAml()` masuk cabang
     * "sudah tercatat" → `ReversalService::reverseTransactionEffect()`
     * menambah saldo rekening KEDUA KALI. Saldo rekening uji Rp 1.000.000
     * naik menjadi Rp 601.000.000 — dana yang tidak pernah ada — sementara
     * layar menampilkan "dana telah dikembalikan dan saldo rekening
     * dipulihkan". Untuk penarikan (posisi Debet) pemeriksaan saldo
     * kebetulan menggagalkan reversal kedua, sehingga tidak semua kasus
     * terlihat — justru itu yang membuat cacat ini lolos dari mata.
     *
     * Penjagaan lama hanya memeriksa `aml_status_verifikasi === 'ditolak'`,
     * padahal transaksi bisa berstatus terminal lewat jalur lain (void,
     * pembatalan, impor data lama) dengan `aml_status_verifikasi` masih
     * 'menunggu'.
     */
    public function test_penolakan_aml_tidak_boleh_mengulang_transaksi_terminal(): void
    {
        $anggota = $this->anggota();
        $rekening = $this->rekening($anggota);
        $rekening->update(['saldo' => 1000000]);

        $sudahGagal = $this->transaksi([
            'anggota_id' => $anggota->id,
            'rekening_id' => $rekening->id,
            'status_transaksi' => Transaksi::STATUS_TRANSAKSI_GAGAL,
            'status' => 'rejected',
            'aml_status_verifikasi' => 'menunggu',
        ]);
        $sudahVoided = $this->transaksi([
            'anggota_id' => $anggota->id,
            'rekening_id' => $rekening->id,
            'posisi' => 'Kredit',
            'status_transaksi' => Transaksi::STATUS_TRANSAKSI_VOIDED,
            'status' => 'rejected',
            'aml_status_verifikasi' => 'menunggu',
        ]);
        $pengurus = $this->pengurusAml();

        $this->actingAs($pengurus)
            ->post(route('pengurus.aml.tolak', $sudahGagal->id), [
                'aml_catatan' => 'Uji idempotensi G-01 (Gagal).',
            ])
            ->assertSessionHas('error');

        $this->actingAs($pengurus)
            ->post(route('pengurus.aml.tolak', $sudahVoided->id), [
                'aml_catatan' => 'Uji idempotensi G-01 (Voided).',
            ])
            ->assertSessionHas('error');

        $this->assertSame('menunggu', $sudahGagal->refresh()->aml_status_verifikasi,
            'G-01: penolakan atas transaksi yang sudah Gagal harus ditolak tanpa mengubah status kepatuhan.');
        $this->assertSame('menunggu', $sudahVoided->refresh()->aml_status_verifikasi,
            'G-01: penolakan atas transaksi yang sudah Voided harus ditolak tanpa mengubah status kepatuhan.');
        $this->assertSame(1000000.0, (float) $rekening->refresh()->saldo,
            'G-01: saldo rekening TIDAK BOLEH berubah. Penolakan berganda atas transaksi '
            .'terminal menambah saldo Rp 600.000.000 yang tidak pernah ada (reversal kedua).');
    }

    /* =====================================================================
     | P8 — KONSISTENSI KARTU STATISTIK DENGAN DAFTAR
     ===================================================================== */

    /**
     * G-02 — Kartu "Total High Value" WAJIB menghitung baris yang SAMA dengan
     * daftar di halaman yang sama.
     *
     * Daftar memakai `jumlah >= ambang OR is_aml_high_value OR
     * is_large_transaction`; statistik lama hanya
     * `jumlah >= ambang OR is_aml_high_value`, sehingga transaksi tunai
     * bernilai besar (`is_large_transaction`, di bawah ambang AML) tampil di
     * daftar tetapi tidak terhitung di kartu — angka pengawasan PPATK jadi
     * lebih kecil dari daftar yang sedang dilihat Pengurus.
     */
    public function test_kartu_total_high_value_konsisten_dengan_daftar(): void
    {
        $sebelum = $this->statistikAml();

        $this->transaksi([
            'jumlah' => 1000.0,
            'is_aml_high_value' => false,
            'is_large_transaction' => true,
        ]);

        $sesudah = $this->statistikAml();

        $this->assertSame(
            $sebelum['totalHighValue'] + 1,
            $sesudah['totalHighValue'],
            'G-02: transaksi bertanda is_large_transaction tampil di daftar, maka kartu '
            .'Total High Value WAJIB ikut menghitungnya (jumlah < ambang AML).'
        );
        $this->assertSame(
            $sebelum['barisDaftar'] + 1,
            $sesudah['barisDaftar'],
            'G-02: kontrol silang — baris daftar memang bertambah satu.'
        );
        $this->assertSame(
            $sesudah['barisDaftar'],
            $sesudah['totalHighValue'],
            'G-02: angka kartu harus SAMA dengan jumlah baris daftar pada kondisi yang sama.'
        );
    }

    /* ---------------------------------------------------------------------
     | Helper P7/P8
     --------------------------------------------------------------------- */

    /** Pengurus yang berwenang membuka halaman kepatuhan AML. */
    private function pengurusAml(): User
    {
        return User::firstOrCreate(
            ['email' => 'ppatk.pengurus@test.local'],
            [
                'name' => 'Pengurus Kepatuhan PPATK',
                'password' => Hash::make('rahasia123'),
                'role' => 'pengurus',
                'is_active' => true,
            ]
        );
    }

    /**
     * Baca angka kartu statistik & jumlah baris daftar dari halaman AML.
     *
     * @return array{totalHighValue: int, barisDaftar: int}
     */
    private function statistikAml(): array
    {
        $response = $this->actingAs($this->pengurusAml())
            ->get(route('pengurus.aml.index'))
            ->assertOk();

        $totalHighValue = null;
        $response->assertViewHas('totalHighValue', function ($v) use (&$totalHighValue): bool {
            $totalHighValue = (int) $v;

            return true;
        });

        $barisDaftar = null;
        $response->assertViewHas('transaksis', function ($v) use (&$barisDaftar): bool {
            $barisDaftar = (int) $v->total();

            return true;
        });

        // Ambil total() dari paginator — bukan jumlah halaman terlihat.
        return ['totalHighValue' => (int) $totalHighValue, 'barisDaftar' => (int) $barisDaftar];
    }
}
