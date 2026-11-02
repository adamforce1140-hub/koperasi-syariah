<?php

namespace Tests\Feature;

use App\Http\Controllers\DanaCadanganController;
use App\Models\AuditLog;
use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\KebijakanKoperasi;
use App\Models\User;
use App\Services\AutoJournalingService;
use App\Services\LaporanKeuanganSyariahService;
use App\Services\PeriodeAkuntansiService;
use App\Support\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regresi Tahap 5 — Prioritas 1 (Hardening teknis & integritas data).
 *
 * Mengunci perilaku perbaikan berikut agar tidak regresi:
 *
 *  - P5-01 : Gerbang cut-off tutup buku menolak posting jurnal bertanggal
 *            pada periode yang sudah ditutup (`PeriodeAkuntansiService`).
 *  - P5-02 : Jurnal penutup (sumber 'TutupBuku') DIKECUALIKAN dari gerbang,
 *            karena justru dialah yang menutup periode.
 *  - P5-03 : Bila kebijakan `tutup_buku_sampai` kosong (default), seluruh
 *            tanggal tetap sah — gerbang tidak boleh mengganggu operasional.
 *  - P5-04 : Endpoint `/api/transaksi` menolak transaksi bertanggal periode
 *            tertutup, dan menolak nominal yang menjadi 0 setelah pembulatan.
 *  - P5-05 : Foreign key jejak akuntansi memakai RESTRICT (bukan CASCADE),
 *            sehingga penghapusan induk tidak menghapus bukti secara senyap.
 *  - P5-06 : Login/logout meninggalkan jejak audit (R4.1 Tahap 4).
 */
class Tahap5HardeningTest extends TestCase
{
    use DatabaseTransactions;

    private AutoJournalingService $jurnal;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('kebijakan_koperasis')) {
            $this->markTestSkipped('Tabel kebijakan_koperasis belum tersedia.');
        }

        $this->jurnal = app(AutoJournalingService::class);

        $this->setCutOff(null);
    }

    /* =====================================================================
     | Helper
     ===================================================================== */

    /** Setel parameter kebijakan cut-off tutup buku (null = periode terbuka). */
    private function setCutOff(?string $tanggal): void
    {
        $kebijakan = KebijakanKoperasi::first() ?? KebijakanKoperasi::create([]);
        $kebijakan->tutup_buku_sampai = $tanggal;
        $kebijakan->save();

        Setting::flush();
    }

    private function user(string $role = 'teller', string $email = 'tahap5.teller@test.local'): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Uji Tahap 5 '.ucfirst($role),
                'password' => Hash::make('password'),
                'role' => $role,
                'is_active' => true,
            ]
        );
    }

    /** Dua kode CoA nyata untuk menyusun jurnal seimbang. */
    private function duaKode(): array
    {
        return [AutoJournalingService::KAS_TELLER, AutoJournalingService::SIMPANAN_SUKARELA];
    }

    /** Posting jurnal uji seimbang pada tanggal tertentu. */
    private function posting($tanggal, string $sumber = 'Jurnal Umum'): ?Jurnal
    {
        [$debet, $kredit] = $this->duaKode();

        return $this->jurnal->post(
            'Uji gerbang periode akuntansi',
            [
                ['kode' => $debet,  'debet' => 10_000],
                ['kode' => $kredit, 'kredit' => 10_000],
            ],
            $sumber,
            null,
            null,
            $this->user()->id,
            null,
            null,
            $tanggal
        );
    }

    /* =====================================================================
     | P5-01 .. P5-03 — Gerbang cut-off tutup buku
     ===================================================================== */

    public function test_tanpa_cut_off_seluruh_tanggal_tetap_dapat_diposting(): void
    {
        $this->setCutOff(null);

        $svc = app(PeriodeAkuntansiService::class);

        $this->assertNull($svc->cutOff());
        $this->assertFalse($svc->aktif());
        $this->assertFalse($svc->sudahDitutup('2020-01-01'));

        $jurnal = $this->posting('2020-01-01');
        $this->assertNotNull($jurnal, 'Tanpa cut-off, backdate harus tetap diizinkan (perilaku lama).');
    }

    public function test_posting_pada_periode_sudah_ditutup_ditolak(): void
    {
        $this->setCutOff('2026-06-30');
        $sebelum = Jurnal::count();

        $this->expectException(\RuntimeException::class);

        try {
            $this->posting('2026-06-30');
        } finally {
            $this->assertSame($sebelum, Jurnal::count(), 'Jurnal di periode tertutup TIDAK boleh tersimpan.');
        }
    }

    public function test_posting_setelah_cut_off_diterima(): void
    {
        $this->setCutOff('2026-06-30');

        $jurnal = $this->posting('2026-07-01');

        $this->assertNotNull($jurnal);
        $this->assertSame('2026-07-01', $jurnal->tanggal_jurnal->toDateString());
    }

    public function test_jurnal_penutup_dikecualikan_dari_gerbang_cut_off(): void
    {
        $this->setCutOff('2026-06-30');

        // Jurnal penutup bertanggal TEPAT pada cut-off wajib tetap bisa dibuat —
        // dialah yang menutup periode tersebut.
        $jurnal = $this->posting('2026-06-30', 'TutupBuku');

        $this->assertNotNull($jurnal, 'Jurnal penutup harus dikecualikan dari gerbang cut-off.');
    }

    public function test_pesan_penolakan_menyebut_tanggal_cut_off(): void
    {
        $this->setCutOff('2026-06-30');
        $svc = app(PeriodeAkuntansiService::class);

        $pesan = $svc->pesanPenolakan('2026-05-01');

        $this->assertStringContainsString('2026-06-30', $pesan);
        $this->assertStringContainsString('2026-05-01', $pesan);
    }

    public function test_tanggal_yang_tidak_dapat_diurai_tidak_diblokir_gerbang(): void
    {
        /* Tanggal yang tidak dapat diurai adalah tanggung jawab validasi format
         | (`rule:date`) di masing-masing modul. Gerbang periode sengaja
         | fail-open di sini agar kesalahan format tidak dilaporkan sebagai
         | "periode sudah ditutup" — pesan yang menyesatkan operator. */
        $this->setCutOff('2026-06-30');

        $svc = app(PeriodeAkuntansiService::class);

        $this->assertFalse($svc->sudahDitutup('bukan-tanggal'));
        $this->assertFalse($svc->sudahDitutup(null));
        $this->assertFalse($svc->sudahDitutup(''));

        // Tanggal sah pada periode tertutup tetap diblokir.
        $this->assertTrue($svc->sudahDitutup('2026-06-30'));
    }

    /* =====================================================================
     | P5-04 — Validasi periode & nominal pada endpoint /api/transaksi
     ===================================================================== */

    public function test_api_transaksi_menolak_tanggal_pada_periode_tertutup(): void
    {
        $this->setCutOff('2026-06-30');

        $respons = $this->actingAs($this->user('teller'))->postJson('/api/transaksi', [
            'no_transaksi' => 'TRX-T5-'.uniqid(),
            'waktu_transaksi' => '2026-06-15 10:00:00',
            'jenis_transaksi' => 'Setoran Tunai',
            'posisi' => 'Debet',
            'jumlah' => 50_000,
        ]);

        $respons->assertStatus(422)->assertJsonValidationErrors('waktu_transaksi');
    }

    public function test_api_transaksi_menerima_tanggal_setelah_cut_off(): void
    {
        $this->setCutOff('2026-06-30');

        $respons = $this->actingAs($this->user('teller'))->postJson('/api/transaksi', [
            'no_transaksi' => 'TRX-T5-'.uniqid(),
            'waktu_transaksi' => '2026-07-15 10:00:00',
            'jenis_transaksi' => 'Setoran Tunai',
            'posisi' => 'Debet',
            'jumlah' => 50_000,
        ]);

        $respons->assertStatus(201);
    }

    public function test_api_transaksi_menolak_nominal_nol(): void
    {
        $respons = $this->actingAs($this->user('teller'))->postJson('/api/transaksi', [
            'no_transaksi' => 'TRX-T5-'.uniqid(),
            'waktu_transaksi' => now()->toDateTimeString(),
            'jenis_transaksi' => 'Setoran Tunai',
            'posisi' => 'Debet',
            'jumlah' => 0,
        ]);

        $respons->assertStatus(422)->assertJsonValidationErrors('jumlah');
    }

    public function test_api_transaksi_menyimpan_nominal_terbulatkan(): void
    {
        $respons = $this->actingAs($this->user('teller'))->postJson('/api/transaksi', [
            'no_transaksi' => 'TRX-T5-'.uniqid(),
            'waktu_transaksi' => now()->toDateTimeString(),
            'jenis_transaksi' => 'Setoran Tunai',
            'posisi' => 'Debet',
            'jumlah' => 12_345.67,
        ]);

        $respons->assertStatus(201);

        $tersimpan = (float) $respons->json('data.jumlah');

        $this->assertSame(
            Setting::pembulatanNominal(12_345.67),
            $tersimpan,
            'Nominal tersimpan wajib sudah dibulatkan sesuai kebijakan koperasi.'
        );
    }

    /* =====================================================================
     | P5-05 — Foreign key jejak akuntansi memakai RESTRICT
     ===================================================================== */

    public function test_foreign_key_jejak_akuntansi_memakai_restrict(): void
    {
        $target = [
            ['teller_opnames', 'user_id'],
            ['angsuran', 'pembiayaan_id'],
            ['jurnal_detail', 'jurnal_id'],
            ['pengeluaran_beban', 'created_by'],
        ];

        foreach ($target as [$tabel, $kolom]) {
            if (! Schema::hasTable($tabel) || ! Schema::hasColumn($tabel, $kolom)) {
                continue;
            }

            $row = DB::selectOne(
                'SELECT rc.DELETE_RULE AS aturan
                   FROM information_schema.KEY_COLUMN_USAGE kcu
                   JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                     ON rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
                    AND rc.CONSTRAINT_NAME   = kcu.CONSTRAINT_NAME
                  WHERE kcu.TABLE_SCHEMA = DATABASE()
                    AND kcu.TABLE_NAME = ?
                    AND kcu.COLUMN_NAME = ?',
                [$tabel, $kolom]
            );

            $this->assertNotNull($row, "Foreign key {$tabel}.{$kolom} tidak ditemukan.");
            $this->assertSame(
                'RESTRICT',
                $row->aturan,
                "Foreign key {$tabel}.{$kolom} wajib RESTRICT agar bukti akuntansi tidak terhapus senyap."
            );
        }
    }

    public function test_menghapus_jurnal_yang_memiliki_detail_ditolak_database(): void
    {
        if (! Schema::hasTable('jurnal_detail')) {
            $this->markTestSkipped('Tabel jurnal_detail belum tersedia.');
        }

        $jurnal = $this->posting(now()->toDateString());
        $this->assertNotNull($jurnal);

        $jumlahDetail = DB::table('jurnal_detail')->where('jurnal_id', $jurnal->id)->count();
        $this->assertGreaterThan(0, $jumlahDetail, 'Prasyarat uji: jurnal harus memiliki baris detail.');

        try {
            DB::table('jurnal')->where('id', $jurnal->id)->delete();
            $this->fail('Penghapusan jurnal yang memiliki detail seharusnya DITOLAK oleh foreign key RESTRICT.');
        } catch (QueryException $e) {
            $this->assertTrue(true, 'Penghapusan induk ditolak sesuai RESTRICT.');
        }

        $this->assertSame(
            $jumlahDetail,
            DB::table('jurnal_detail')->where('jurnal_id', $jurnal->id)->count(),
            'Baris buku besar tidak boleh ikut terhapus.'
        );
    }

    /* =====================================================================
     | P5-06 — Jejak audit autentikasi (R4.1)
     ===================================================================== */

    public function test_login_berhasil_mencatat_jejak_audit(): void
    {
        $user = $this->user('teller', 'tahap5.login@test.local');
        $user->forceFill(['password' => Hash::make('rahasia-test')])->save();

        $this->post('/login', ['email' => $user->email, 'password' => 'rahasia-test'])
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'jenis_transaksi' => 'Login Berhasil',
        ]);
    }

    public function test_login_gagal_mencatat_jejak_audit(): void
    {
        $user = $this->user('teller', 'tahap5.logingagal@test.local');
        $user->forceFill(['password' => Hash::make('rahasia-test')])->save();

        $this->post('/login', ['email' => $user->email, 'password' => 'salah'])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => null,
            'jenis_transaksi' => 'Login Gagal',
        ]);
    }

    public function test_logout_mencatat_jejak_audit(): void
    {
        $user = $this->user('teller', 'tahap5.logout@test.local');

        $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'jenis_transaksi' => 'Logout',
        ]);
    }

    public function test_jejak_audit_login_tidak_memuat_password(): void
    {
        $user = $this->user('teller', 'tahap5.loginnopass@test.local');
        $user->forceFill(['password' => Hash::make('rahasia-test')])->save();

        $this->post('/login', ['email' => $user->email, 'password' => 'rahasia-test'])->assertRedirect();

        $log = AuditLog::where('user_id', $user->id)
            ->where('jenis_transaksi', 'Login Berhasil')
            ->first();

        $this->assertNotNull($log);
        $this->assertStringNotContainsString('rahasia-test', (string) $log->keterangan);
        $this->assertStringContainsString($user->email, (string) $log->keterangan);
    }

    /* =====================================================================
     | P5-07 .. P5-11 — Prioritas 2 (kinerja, satu sumber rumus, integritas)
     ===================================================================== */

    /**
     * Indeks komposit penopang jalur baca laporan wajib ada di database.
     *
     * Tanpa indeks ini, setiap laporan melakukan full table scan pada `jurnal`
     * dan `jurnal_detail` untuk SETIAP akun CoA — biaya yang baru terasa saat
     * data produksi menumpuk. Test ini mengunci keberadaannya agar tidak
     * hilang tanpa sengaja pada refaktor migrasi berikutnya.
     */
    public function test_indeks_kinerja_laporan_terpasang(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' && DB::connection()->getDriverName() !== 'mariadb') {
            $this->markTestSkipped('Pemeriksaan information_schema hanya untuk MySQL/MariaDB.');
        }

        $wajib = [
            'jurnal' => ['jurnal_status_tanggal_jurnal_index'],
            'jurnal_detail' => ['jurnal_detail_jurnal_id_coa_id_index', 'jurnal_detail_jurnal_id_baris_unique'],
            'transaksi' => ['transaksi_waktu_transaksi_index'],
            'angsuran' => ['angsuran_pembiayaan_id_tanggal_jatuh_tempo_index'],
            'pembiayaan' => ['pembiayaan_status_tanggal_akad_index'],
        ];

        foreach ($wajib as $tabel => $daftarIndeks) {
            foreach ($daftarIndeks as $nama) {
                $this->assertTrue(
                    DB::table('information_schema.STATISTICS')
                        ->whereRaw('TABLE_SCHEMA = DATABASE()')
                        ->where('TABLE_NAME', $tabel)
                        ->where('INDEX_NAME', $nama)
                        ->exists(),
                    "Indeks {$nama} pada tabel {$tabel} hilang — jalur baca laporan akan full table scan."
                );
            }
        }
    }

    /**
     * Indeks (status, tanggal_jurnal) BENAR-BENAR dipakai MySQL untuk pola
     * WHERE yang dijalankan kode (`status = 'Posted' AND tanggal_jurnal <= ?`).
     *
     * Keberadaan indeks saja tidak cukup: bila urutan kolomnya salah, MySQL
     * akan mengabaikannya. EXPLAIN membuktikan indeksnya terpakai.
     */
    public function test_indeks_jurnal_dipakai_oleh_query_laporan(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' && DB::connection()->getDriverName() !== 'mariadb') {
            $this->markTestSkipped('EXPLAIN information_schema hanya untuk MySQL/MariaDB.');
        }

        $rencana = DB::select(
            "EXPLAIN SELECT id FROM jurnal WHERE status = 'Posted' AND tanggal_jurnal <= ? AND deleted_at IS NULL",
            [now()->toDateString()]
        );

        $this->assertNotEmpty($rencana, 'EXPLAIN harus mengembalikan rencana eksekusi.');

        $kemungkinan = array_filter([
            (string) ($rencana[0]->possible_keys ?? ''),
            (string) ($rencana[0]->key ?? ''),
        ]);

        $this->assertStringContainsString(
            'jurnal_status_tanggal_jurnal_index',
            implode(' ', $kemungkinan),
            'MySQL tidak mempertimbangkan indeks (status, tanggal_jurnal) untuk query laporan.'
        );
    }

    /**
     * Rumus presisi sen hanya boleh ada SATU.
     *
     * Controller Neraca (halaman Pengurus) dan LaporanKeuanganSyariahService
     * (laporan lima-unsur PSAK 101) menampilkan angka yang sama kepada audiens
     * berbeda. Selama rumusnya disalin di dua tempat, keduanya dapat menyimpang
     * senyap. Test ini menyatakan: controller HARUS menghasilkan angka yang
     * identik dengan service untuk himpunan akun yang sama.
     */
    public function test_rumus_presisi_neraca_delegasi_ke_service(): void
    {
        $controller = app(DanaCadanganController::class);
        $service = app(LaporanKeuanganSyariahService::class);

        $metode = new \ReflectionMethod($controller, 'jumlahPresisi');
        $metode->setAccessible(true);

        // Kumpulan akun Aset nyata pada database.
        $akun = Coa::where('kategori', 'Aset')->where('is_active', true)->get();
        $sampai = now()->toDateString();

        $this->assertSame(
            $service->jumlahPresisi($akun, $sampai),
            $metode->invoke(null, $akun, $sampai),
            'Rumus presisi Controller Neraca menyimpang dari LaporanKeuanganSyariahService.'
        );
    }

    /** Pengelompokan penyajian (dan subtotalnya) juga hanya punya satu sumber. */
    public function test_pengelompokan_neraca_delegasi_ke_service(): void
    {
        $controller = app(DanaCadanganController::class);
        $service = app(LaporanKeuanganSyariahService::class);

        $metode = new \ReflectionMethod($controller, 'kelompokkanKlasifikasi');
        $metode->setAccessible(true);

        $akun = Coa::where('kategori', 'Aset')->where('is_active', true)->get();
        $urutan = Coa::KLASIFIKASI['Aset'];
        $sampai = now()->toDateString();

        $dariController = $metode->invoke($controller, $akun, $urutan, $sampai);
        $dariService = $service->kelompokkan($akun, $urutan, $sampai);

        $this->assertSame(
            array_map(fn (array $g): array => [$g['label'], $g['subtotal'], $g['akun']->pluck('kode_akun')->all()], $dariService),
            array_map(fn (array $g): array => [$g['label'], $g['subtotal'], $g['akun']->pluck('kode_akun')->all()], $dariController),
            'Pengelompokan Controller Neraca menyimpang dari LaporanKeuanganSyariahService.'
        );
    }

    /**
     * `jurnal_detail.baris` wajib punya DEFAULT di tingkat database.
     *
     * Kolom NOT NULL tanpa default membuat setiap jalur penulis detail jurnal
     * yang lupa mengisi `baris` gagal dengan galat MySQL mentah. Default
     * membuatnya fail-safe.
     */
    public function test_kolom_baris_jurnal_detail_punya_default(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' && DB::connection()->getDriverName() !== 'mariadb') {
            $this->markTestSkipped('Pemeriksaan DEFAULT information_schema hanya untuk MySQL/MariaDB.');
        }

        $kolom = DB::table('information_schema.COLUMNS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'jurnal_detail')
            ->where('COLUMN_NAME', 'baris')
            ->first();

        $this->assertNotNull($kolom, 'Kolom jurnal_detail.baris tidak ditemukan.');
        $this->assertSame('NO', (string) $kolom->IS_NULLABLE, 'Kolom baris harus NOT NULL.');
        $this->assertNotNull($kolom->COLUMN_DEFAULT, 'Kolom baris harus punya DEFAULT agar INSERT tidak gagal.');
    }

    /** Nomor baris dalam satu jurnal tidak boleh bertabrakan (UNIQUE). */
    public function test_baris_jurnal_detail_unik_per_jurnal(): void
    {
        $jurnal = $this->posting('2026-01-05');
        $this->assertNotNull($jurnal);

        $kode = DB::table('jurnal_detail')->where('jurnal_id', $jurnal->id)->value('coa_id');

        try {
            DB::table('jurnal_detail')->insert([
                'jurnal_id' => $jurnal->id,
                'coa_id' => $kode,
                'baris' => 1,
                'debet' => 1,
                'kredit' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Nomor baris duplikat dalam satu jurnal seharusnya ditolak database.');
        } catch (QueryException $e) {
            $this->assertTrue(true, 'Duplikat (jurnal_id, baris) ditolak sesuai UNIQUE.');
        }
    }

    /**
     * Angka Neraca halaman Pengurus harus dapat DIREKONSILIASI ke Neraca lima
     * unsur PSAK 101 — keduanya menyajikan posisi keuangan yang sama pada
     * tanggal laporan yang sama (satu sumber kebenaran angka).
     */
    public function test_neraca_pengurus_konsisten_dengan_posisi_keuangan_psak101(): void
    {
        $user = $this->user('pengurus', 'tahap5.neraca@test.local');

        $response = $this->actingAs($user)
            ->get(route('laporan.neraca', ['mode' => 'tanggal', 'tanggal' => now()->toDateString()]))
            ->assertOk();

        $sampai = $response->viewData('sampai');

        $posisi = app(LaporanKeuanganSyariahService::class)
            ->posisiKeuangan(['sampaiStr' => $sampai, 'sampaiLaluStr' => $sampai]);

        foreach (['totalAset', 'totalKewajiban', 'totalEkuitas', 'labaBersih', 'totalPasiva', 'selisihNeraca'] as $kunci) {
            $this->assertEqualsWithDelta(
                $posisi[$kunci],
                $response->viewData($kunci),
                0.01,
                "Neraca halaman Pengurus dan posisiKeuangan() PSAK 101 berbeda pada '{$kunci}'."
            );
        }

        // Dana Syirkah Temporer disajikan terpisah di kedua jalur.
        $this->assertEqualsWithDelta(
            $posisi['totalDanaSyirkahTemporer'],
            $response->viewData('totalDanaSyirkahTemporer'),
            0.01,
            'Total Dana Syirkah Temporer harus sama di kedua jalur penyajian.'
        );
    }

    /* =====================================================================
     | P5-12 .. P5-15 — Prioritas 3 (observability & kredensial demo)
     ===================================================================== */

    /**
     * Fail-open pada tanggal tak sah HARUS meninggalkan jejak.
     *
     * `LaporanKeuanganSyariahService::periode()` sengaja jatuh ke hari ini
     * bila input tak dapat diurai. Bila penelannya tanpa log, pengguna yang
     * salah mengisi tanggal akan melihat laporan periode berjalan tanpa tahu
     * bahwa inputnya diabaikan.
     */
    public function test_periode_tak_dapat_diurai_dicatat_ke_log(): void
    {
        Log::spy();

        $hasil = app(LaporanKeuanganSyariahService::class)->periode('bukan-tanggal');

        $this->assertArrayHasKey('sampaiStr', $hasil, 'Periode tetap harus menghasilkan rentang (fallback hari ini).');

        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    /** Jalur controller Neraca juga tidak boleh menelan tanggal tak sah secara senyap. */
    public function test_neraca_tanggal_tak_dapat_diurai_dicatat_ke_log(): void
    {
        $user = $this->user('pengurus', 'tahap5.neraca2@test.local');

        Log::spy();

        $this->actingAs($user)
            ->get(route('laporan.neraca', ['mode' => 'tanggal', 'tanggal' => 'bukan-tanggal']))
            ->assertOk();

        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    /**
     * REGRESI — Handler akses cepat harus selalu ada di halaman login.
     *
     * Penanda ini sengaja dipakai alih-alih label panel, karena label panel
     * juga disebut di komentar CSS/JS sehingga tidak dapat dipakai untuk
     * membuktikan panel benar-benar tampil atau tersembunyi.
     */
    private const PENANDA_PANEL_DEMO = 'data-quick-email="';

    /**
     * Panel akses cepat demo akun hanya boleh tampil bila diaktifkan.
     *
     * Panel ini membocorkan kredensial demo ke halaman publik; di produksi
     * ia harus hilang sepenuhnya dari HTML.
     */
    public function test_panel_demo_login_mengikuti_konfigurasi(): void
    {
        config(['app.demo.login_enabled' => false]);
        $this->get(route('login'))->assertOk()->assertDontSee(self::PENANDA_PANEL_DEMO, false);

        config(['app.demo.login_enabled' => true]);
        $this->get(route('login'))->assertOk()->assertSee(self::PENANDA_PANEL_DEMO, false);
    }

    /**
     * Kredensial demo tidak boleh tertulis harfiah di view.
     *
     * Sumber nilai tunggal adalah `config('app.demo.password')`; view hanya
     * membacanya. Dengan begitu rotasi password demo tidak menuntut perubahan
     * view, dan nilai tak pernah ikut ter-commit sebagai teks.
     */
    public function test_view_login_tidak_memuat_password_demo_literal(): void
    {
        $isi = file_get_contents(resource_path('views/auth/login.blade.php'));

        $this->assertStringNotContainsString(
            "'password123'",
            $isi,
            'Password demo harfiah tidak boleh tertulis di view login.'
        );
        $this->assertStringContainsString(
            "config('app.demo.login_enabled')",
            $isi,
            'Panel Akses Cepat Demo harus dikendalikan konfigurasi app.demo.login_enabled.'
        );
    }

    /** UserSeeder membaca password demo dari konfigurasi & menolak produksi. */
    public function test_seeder_membaca_password_demo_dari_konfigurasi(): void
    {
        $isi = file_get_contents(base_path('database/seeders/UserSeeder.php'));

        // Pencocokan dibuat longgar terhadap argumen default: yang penting
        // seeder membaca sumber tunggal `config('app.demo.password')`, bukan
        // menyalin nilai atau fallback-nya sendiri.
        $this->assertMatchesRegularExpression(
            "/config\(\s*'app\.demo\.password'/",
            $isi,
            'UserSeeder harus membaca password demo dari config, bukan menetapkan sendiri.'
        );
        $this->assertStringNotContainsString("Hash::make('password123')", $isi);
        $this->assertStringNotContainsString("'password123'", $isi, 'Fallback password tidak boleh digandakan di seeder.');
        $this->assertStringContainsString("environment('production')", $isi);
    }

    /**
     * REGRESI — Kredensial pada tombol "Akses Cepat Demo Akun" harus benar-benar
     * dapat dipakai login.
     *
     * Bug yang dikunci di sini: `config('app.demo.password')` sempat bernilai
     * NULL karena hanya membaca DEMO_USER_PASSWORD, sementara UserSeeder masih
     * punya fallback 'password123' sendiri. Akibatnya tombol mengisi password
     * KOSONG, panel tampak normal, tetapi setiap login selalu gagal. Test ini
     * memastikan nilai yang dipasang tombol identik dengan password akun demo.
     */
    public function test_kredensial_tombol_cepat_cocok_dengan_akun_demo(): void
    {
        config(['app.demo.login_enabled' => true, 'app.demo.password' => 'password123']);

        $halaman = $this->get(route('login'));
        $halaman->assertOk();

        foreach (['superadmin', 'teller', 'ao', 'manajer', 'pengurus', 'dps'] as $akun) {
            $email = $akun.'@koperasi.test';

            // Email harus dibawa lewat atribut data-* (aman dari escaping),
            // bukan lewat onclick inline yang rawan terpotong tanda kutip ganda.
            $halaman->assertSee('data-quick-email="'.$email.'"', false);
        }

        // REGRESI — handler tidak boleh kembali memakai onclick inline; kombinasi
        // onclick="..." + @json() memotong atribut dan mematikan tombol.
        $halaman->assertDontSee('onclick="quickFill', false);

        // Password demo dibaca handler dari kontainer data. Nilainya harus
        // benar-benar cocok dengan hash akun demo, bukan sekadar string yang
        // tampak benar di markup.
        preg_match('/data-demo-password="([^"]*)"/', $halaman->getContent(), $cocok);
        $this->assertNotEmpty($cocok, 'Kontainer data password demo tidak ditemukan pada halaman login.');
        $this->assertNotSame('', $cocok[1], 'Password demo pada kontainer data kosong.');

        foreach (['superadmin', 'teller', 'ao', 'manajer', 'pengurus', 'dps'] as $akun) {
            $email = $akun.'@koperasi.test';

            $user = User::where('email', $email)->first();
            $this->assertNotNull($user, "Akun demo {$email} tidak ditemukan.");
            $this->assertTrue(
                Hash::check($cocok[1], $user->password),
                "Password yang dipasang tombol untuk {$email} tidak cocok dengan hash akun."
            );
        }
    }

    /**
     * REGRESI — Panel demo tidak boleh tampil bila konfigurasi password kosong.
     *
     * Kredensial kosong membuat tombol hanya mengisi email dan meninggalkan
     * password blank; lebih baik panel disembunyikan agar tidak menyesatkan.
     */
    public function test_panel_demo_tersembunyi_bila_password_demo_kosong(): void
    {
        config(['app.demo.login_enabled' => true, 'app.demo.password' => '']);

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee(self::PENANDA_PANEL_DEMO, false)
            ->assertDontSee('data-demo-password', false);
    }

    /* =====================================================================
     |  REGRESI — Rate limiting login (HTTP 429)
     |
     |  Throttling `throttle:5,1` pada POST /login sudah benar dan harus
     |  dipertahankan. Yang dikunci di sini adalah kualitas respons 429-nya:
     |  pengguna harus tahu berapa lama menunggu, dan kode referensi yang
     |  ditampilkan harus benar-benar dapat ditelusuri di log.
     ===================================================================== */

    /**
     * Respons 429 wajib membawa header Retry-After beserta metadata pembatas laju.
     *
     * Bug yang dikunci: blok render terpusat membangun respons BARU dengan
     * `response()->view(...)`, sehingga header yang dititipkan middleware
     * ThrottleRequests (Retry-After, X-RateLimit-*) hilang. Tanpa header ini
     * klien tidak punya cara terprogram untuk mengetahui kapan boleh mencoba
     * lagi — satu-satunya petunjuk adalah kalimat di halaman.
     */
    public function test_respons_429_login_membawa_header_retry_after(): void
    {
        $respons = null;

        // Batas 5 per menit; percobaan ke-6 harus ditolak.
        for ($i = 1; $i <= 6; $i++) {
            $respons = $this->post('/login', [
                'email' => 'ratelimit@test.local',
                'password' => 'sengaja-salah',
            ]);
        }

        $respons->assertStatus(429);

        $this->assertTrue(
            $respons->headers->has('Retry-After'),
            'Respons 429 tidak membawa header Retry-After; klien tidak tahu kapan boleh mencoba lagi.'
        );
        $this->assertGreaterThan(0, (int) $respons->headers->get('Retry-After'));

        $this->assertTrue(
            $respons->headers->has('X-RateLimit-Limit'),
            'Respons 429 tidak membawa metadata batas laju.'
        );
    }

    /**
     * Halaman 429 harus memberi tahu sisa waktu tunggu, bukan pesan kabur.
     */
    public function test_pesan_429_menyebut_durasi_tunggu(): void
    {
        $respons = null;

        for ($i = 1; $i <= 6; $i++) {
            $respons = $this->post('/login', [
                'email' => 'ratelimit-pesan@test.local',
                'password' => 'sengaja-salah',
            ]);
        }

        $respons->assertStatus(429);

        $isi = $respons->getContent();
        $this->assertStringContainsString('Terlalu Banyak Permintaan', $isi);
        $this->assertMatchesRegularExpression(
            '/coba lagi dalam \d+ detik/i',
            $isi,
            'Pesan 429 tidak menyebut durasi tunggu yang konkret.'
        );

        // Kalimat lama yang tanpa informasi waktu tidak boleh dipakai lagi.
        $this->assertStringNotContainsString('Silakan tunggu beberapa saat', $isi);
    }

    /**
     * Kode referensi yang ditampilkan pada halaman 429 harus dapat ditelusuri.
     *
     * Bug yang dikunci: pembatasan laju dilempar sebagai
     * TooManyRequestsHttpException, dan HttpException dikecualikan Laravel dari
     * pelaporan otomatis. Akibatnya tidak ada satu pun entri log yang memuat
     * kode referensi tersebut, sehingga permintaan pengguna "sampaikan kode
     * ERR-XXXXXXXX ke tim teknis" mustahil ditindaklanjuti.
     */
    public function test_referensi_429_dapat_ditelusuri_di_log(): void
    {
        $catatanLog = [];

        Log::listen(function ($peristiwa) use (&$catatanLog) {
            $catatanLog[] = $peristiwa->message.' '.json_encode($peristiwa->context);
        });

        $respons = null;

        for ($i = 1; $i <= 6; $i++) {
            $respons = $this->post('/login', [
                'email' => 'ratelimit-log@test.local',
                'password' => 'sengaja-salah',
            ]);
        }

        $respons->assertStatus(429);

        preg_match('/ERR-[0-9A-F]{8}/', $respons->getContent(), $cocok);
        $this->assertNotEmpty($cocok, 'Halaman 429 tidak menampilkan kode referensi.');
        $referensi = $cocok[0];

        $gabungan = implode(PHP_EOL, $catatanLog);
        $this->assertStringContainsString(
            $referensi,
            $gabungan,
            "Kode referensi {$referensi} yang ditampilkan ke pengguna tidak tercatat di log, sehingga tidak dapat ditelusuri tim teknis."
        );
        $this->assertStringContainsString('rate limiting', strtolower($gabungan));
    }

    /**
     * Konfigurasi pembatas laju tidak boleh hilang tanpa sengaja.
     *
     * Ini bukan menguji Laravel, melainkan mengunci keputusan Tahap 4 (V4.15):
     * POST /login sengaja dibatasi 5 percobaan per menit untuk meredam
     * serangan tebak-password. Bila middleware ini terhapus, perlindungan
     * brute force ikut hilang tanpa ada test yang gagal.
     */
    public function test_rute_login_masih_dibatasi_lima_percobaan_per_menit(): void
    {
        $rute = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->getName() === 'login.post');

        $this->assertNotNull($rute, 'Rute login.post tidak ditemukan.');
        $this->assertContains('throttle:5,1', $rute->gatherMiddleware());

        $respons = null;
        for ($i = 1; $i <= 6; $i++) {
            $respons = $this->post('/login', [
                'email' => 'ratelimit-gerbang@test.local',
                'password' => 'sengaja-salah',
            ]);
        }

        $respons->assertStatus(429);
    }
}
