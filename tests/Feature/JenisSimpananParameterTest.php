<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\Coa;
use App\Models\JenisSimpanan;
use App\Models\Rekening;
use App\Models\User;
use App\Services\AutoJournalingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PARAMETER DINAMIS JENIS SIMPANAN — pengujian menyeluruh (Langkah 5 & 6).
 *
 * Mengunci seluruh syarat penerimaan permintaan:
 *   1. Tabel master `jenis_simpanans` + log `jenis_simpanan_logs` (Langkah 1).
 *   2. Antarmuka administratif penuh via rute `pengurus.jenis-simpanan.*` (Langkah 2).
 *   3. Integrasi: perubahan parameter langsung berlaku di alur kritis — jurnal
 *      setoran (AutoJournalingService) membaca akun COA dari parameter (Langkah 3).
 *   4. Audit trail: setiap perubahan tercatat siapa/kapan/kolom/nilai (Langkah 4).
 *   5. Tidak ada regresi pada modul lain + guard integritas (Langkah 5).
 *   6. Hanya `super_admin` yang dapat mengakses & memodifikasi parameter
 *      (Langkah 6). Parameter jenis simpanan = konfigurasi teknis aplikasi,
 *      sehingga berada di Super Admin — bukan kewenangan kebijakan Pengurus.
 *
 * Catatan: basis data dev memuat data nyata, sehingga pengujian memakai data
 * berprefix unik + assertion berbasis DELTA agar deterministik.
 */
class JenisSimpananParameterTest extends TestCase
{
    use DatabaseTransactions;

    /** Role yang TIDAK berwenang atas parameter jenis simpanan. */
    private const PERAN_TIDAK_BERWENANG = ['admin', 'teller', 'manajer', 'ao', 'dps', 'anggota', 'pengurus'];

    private string $prefix;

    /** @var array<string, User> */
    private array $pengguna = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'JSP'.strtoupper(substr(uniqid(), -6));

        foreach (['super_admin', 'admin', 'teller', 'manajer', 'ao', 'dps', 'anggota', 'pengurus'] as $peran) {
            $this->pengguna[$peran] = User::firstOrCreate(
                ['email' => "jenis.simpanan.{$peran}@test.local"],
                [
                    'name' => 'Uji JenisSimpanan '.ucfirst($peran),
                    'password' => Hash::make('password'),
                    'role' => $peran,
                    'is_active' => true,
                ]
            );
        }
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    private function sebagai(string $peran): User
    {
        return $this->pengguna[$peran];
    }

    private function akunId(): int
    {
        $id = Akun::where('kode_akun', '1133')->value('id') ?? Akun::query()->value('id');

        if (! $id) {
            $this->markTestSkipped('Tabel akun kosong — jalankan AkunSeeder.');
        }

        return (int) $id;
    }

    private function anggota(): Anggota
    {
        return Anggota::create([
            'no_anggota' => $this->prefix.'-'.strtoupper(substr(uniqid(), -6)),
            'nama_lengkap' => 'Anggota Uji '.$this->prefix,
            'nik' => 'NIK'.substr(md5(uniqid()), 0, 12),
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji Parameter No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'no_telepon' => '081200000000',
            'status_anggota' => 'Aktif',
            'tanggal_gabung' => now()->toDateString(),
        ]);
    }

    private function rekening(Anggota $anggota, string $jenis, string $status = 'Aktif'): Rekening
    {
        return Rekening::create([
            'no_rekening' => 'RKN-'.strtoupper(substr(uniqid(), -8)),
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunId(),
            'jenis_rekening' => $jenis,
            'akad' => 'Wadiah',
            'saldo' => 0,
            'batas_minimum' => 0,
            'tanggal_buka' => now()->toDateString(),
            'status_rekening' => $status,
        ]);
    }

    /**
     * Buat jenis simpanan uji secara deterministik.
     *
     * Sisa data nyata dengan nama sama dibersihkan lebih dulu (seluruh perubahan
     * di-rollback oleh DatabaseTransactions, sehingga basis data dev aman).
     */
    private function jenisBaru(array $atribut = []): JenisSimpanan
    {
        $nama = $atribut['nama'] ?? 'Simpanan Hari Raya';

        JenisSimpanan::where('nama', $nama)->delete();

        return JenisSimpanan::create(array_merge([
            'nama' => $nama,
            'kode_akun' => '2-1001',
            'tipe' => JenisSimpanan::TIPE_SUKARELA,
            'is_active' => true,
            'keterangan' => 'Fixture uji '.$this->prefix,
        ], $atribut));
    }

    /** Akun COA yang benar-benar ada & aktif (dipakai untuk uji validasi). */
    private function coaAktif(): Coa
    {
        $coa = Coa::where('is_active', true)->orderBy('kode_akun')->first();

        if (! $coa) {
            $this->markTestSkipped('Tabel coas kosong — jalankan CoaSeeder.');
        }

        return $coa;
    }

    /** Buat akun COA uji dengan kode unik (deterministik untuk uji pemetaan dinamis). */
    private function coaUjiBaru(): Coa
    {
        do {
            $kode = '2-9'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (Coa::where('kode_akun', $kode)->exists());

        return Coa::create([
            'kode_akun' => $kode,
            'nama_akun' => 'Akun Uji Dinamis '.$this->prefix,
            'kategori' => 'Kewajiban',
            'klasifikasi_neraca' => 'Kewajiban Jangka Pendek',
            'tipe_saldo_normal' => 'Kredit',
            'saldo_awal' => 0,
            'is_active' => true,
        ]);
    }

    private function jurnalBisaDibuat(): bool
    {
        return Coa::findByKode(AutoJournalingService::KAS_TELLER) !== null;
    }

    /** Kode akun COA yang terisi pada sebuah jurnal. */
    private function kodeCoaJurnal(int $jurnalId): array
    {
        $coaId = DB::table('jurnal_detail')
            ->where('jurnal_id', $jurnalId)
            ->whereNotNull('coa_id')
            ->pluck('coa_id');

        if ($coaId->isEmpty()) {
            return [];
        }

        return Coa::whereIn('id', $coaId)->pluck('kode_akun')->all();
    }

    /* =====================================================================
     | LANGKAH 6 — KEAMANAN: HANYA SUPER ADMIN YANG TEROTORISASI
     |===================================================================== */

    public function test_tamu_tidak_dapat_mengakses_parameter_jenis_simpanan(): void
    {
        $this->get(route('pengurus.jenis-simpanan.index'))->assertRedirect(route('login'));

        $this->post(route('pengurus.jenis-simpanan.store'), [
            'nama' => 'Simpanan Hari Raya',
            'tipe' => JenisSimpanan::TIPE_LAIN,
        ])->assertRedirect(route('login'));
    }

    #[DataProvider('peranTidakBerwenangProvider')]
    public function test_role_tidak_berwenang_ditolak_403_pada_semua_aksi(string $peran): void
    {
        $user = $this->sebagai($peran);
        $jenis = $this->jenisBaru(['nama' => 'Modal Penyertaan']);

        $this->actingAs($user)->get(route('pengurus.jenis-simpanan.index'))->assertForbidden();

        $this->actingAs($user)->post(route('pengurus.jenis-simpanan.store'), [
            'nama' => 'Simpanan Hari Raya',
            'tipe' => JenisSimpanan::TIPE_LAIN,
        ])->assertForbidden();

        $this->actingAs($user)->put(route('pengurus.jenis-simpanan.update', $jenis->id), [
            'nama' => 'Modal Penyertaan',
            'tipe' => JenisSimpanan::TIPE_LAIN,
        ])->assertForbidden();

        $this->actingAs($user)
            ->post(route('pengurus.jenis-simpanan.toggle', $jenis->id))
            ->assertForbidden();

        $this->actingAs($user)
            ->delete(route('pengurus.jenis-simpanan.destroy', $jenis->id))
            ->assertForbidden();

        // Bukti tidak ada efek samping: parameter tidak berubah.
        $this->assertTrue($jenis->fresh()->is_active);
    }

    public static function peranTidakBerwenangProvider(): array
    {
        $data = [];

        foreach (self::PERAN_TIDAK_BERWENANG as $peran) {
            $data["role {$peran}"] = [$peran];
        }

        return $data;
    }

    public function test_super_admin_dapat_membuka_halaman_parameter(): void
    {
        $this->actingAs($this->sebagai('super_admin'))
            ->get(route('pengurus.jenis-simpanan.index'))
            ->assertOk()
            ->assertSee('Jenis Simpanan', false);
    }

    /* =====================================================================
     | LANGKAH 2 & 4 — CRUD + AUDIT TRAIL
     |===================================================================== */

    public function test_super_admin_dapat_menambah_jenis_simpanan_dan_tercatat_di_log(): void
    {
        $superAdmin = $this->sebagai('super_admin');

        JenisSimpanan::where('nama', 'Modal Penyertaan')->delete();

        $this->actingAs($superAdmin)->post(route('pengurus.jenis-simpanan.store'), [
            'nama' => 'Modal Penyertaan',
            'kode_akun' => '2-1001',
            'tipe' => JenisSimpanan::TIPE_LAIN,
            'is_active' => 1,
            'keterangan' => 'Uji tambah '.$this->prefix,
        ])->assertRedirect(route('pengurus.jenis-simpanan.index'))
            ->assertSessionHas('success');

        $baru = JenisSimpanan::where('nama', 'Modal Penyertaan')->first();

        $this->assertNotNull($baru, 'Jenis simpanan baru harus tersimpan.');
        $this->assertSame('2-1001', $baru->kode_akun);
        $this->assertTrue($baru->is_active);

        // Audit trail: aksi CREATE + pengguna + nilai baru + waktu.
        $log = DB::table('jenis_simpanan_logs')
            ->where('jenis_simpanan_id', $baru->id)
            ->where('aksi', 'CREATE')
            ->where('kolom', 'nama')
            ->first();

        $this->assertNotNull($log, 'Penambahan harus tercatat di jenis_simpanan_logs.');
        $this->assertSame($superAdmin->id, (int) $log->user_id, 'Log harus mencatat pengguna yang mengubah.');
        $this->assertSame('super_admin', $log->role_user);
        $this->assertSame('Modal Penyertaan', $log->nilai_baru);
        $this->assertNotNull($log->created_at, 'Log harus mencatat waktu perubahan.');
    }

    public function test_perubahan_parameter_mencatat_detail_per_kolom(): void
    {
        $superAdmin = $this->sebagai('super_admin');
        $jenis = $this->jenisBaru(['nama' => 'Simpanan Hari Raya', 'kode_akun' => '2-1001']);

        $this->actingAs($superAdmin)->put(route('pengurus.jenis-simpanan.update', $jenis->id), [
            'nama' => $jenis->nama,
            'kode_akun' => '2-1002',
            'tipe' => JenisSimpanan::TIPE_LAIN,
            'is_active' => 1,
            'keterangan' => 'Diubah untuk uji '.$this->prefix,
        ])->assertRedirect(route('pengurus.jenis-simpanan.index'));

        // Dua kolom berubah → dua baris log, masing-masing menyimpan nilai lama & baru.
        $logAkun = DB::table('jenis_simpanan_logs')
            ->where('jenis_simpanan_id', $jenis->id)
            ->where('aksi', 'UPDATE')
            ->where('kolom', 'kode_akun')
            ->first();

        $this->assertNotNull($logAkun, 'Perubahan kode akun harus tercatat.');
        $this->assertSame('2-1001', $logAkun->nilai_lama);
        $this->assertSame('2-1002', $logAkun->nilai_baru);
        $this->assertSame($superAdmin->id, (int) $logAkun->user_id);
        $this->assertNotNull($logAkun->created_at, 'Log harus mencatat waktu perubahan.');

        $logTipe = DB::table('jenis_simpanan_logs')
            ->where('jenis_simpanan_id', $jenis->id)
            ->where('aksi', 'UPDATE')
            ->where('kolom', 'tipe')
            ->first();

        $this->assertNotNull($logTipe, 'Perubahan tipe harus tercatat.');
        $this->assertSame(
            JenisSimpanan::LABEL_TIPE[JenisSimpanan::TIPE_LAIN],
            $logTipe->nilai_baru,
            'Nilai tipe dicatat dalam label manusiawi.'
        );
    }

    public function test_super_admin_dapat_menonaktifkan_dan_mengaktifkan_kembali(): void
    {
        $superAdmin = $this->sebagai('super_admin');
        $jenis = $this->jenisBaru(['nama' => 'Simpanan Hari Raya']);

        $this->actingAs($superAdmin)
            ->post(route('pengurus.jenis-simpanan.toggle', $jenis->id))
            ->assertRedirect(route('pengurus.jenis-simpanan.index'));

        $this->assertFalse($jenis->fresh()->is_active, 'Toggle harus menonaktifkan jenis.');

        $this->actingAs($superAdmin)
            ->post(route('pengurus.jenis-simpanan.toggle', $jenis->id))
            ->assertRedirect(route('pengurus.jenis-simpanan.index'));

        $this->assertTrue($jenis->fresh()->is_active, 'Toggle kedua harus mengaktifkan kembali.');

        $this->assertGreaterThanOrEqual(
            2,
            DB::table('jenis_simpanan_logs')
                ->where('jenis_simpanan_id', $jenis->id)
                ->where('kolom', 'is_active')
                ->count(),
            'Setiap perubahan status harus tercatat.'
        );
    }

    public function test_jenis_yang_sudah_dipakai_rekening_tidak_dapat_dihapus(): void
    {
        $anggota = $this->anggota();
        $jenis = $this->jenisBaru(['nama' => 'Modal Penyertaan']);
        $this->rekening($anggota, $jenis->nama);

        $this->actingAs($this->sebagai('super_admin'))
            ->delete(route('pengurus.jenis-simpanan.destroy', $jenis->id))
            ->assertRedirect(route('pengurus.jenis-simpanan.index'))
            ->assertSessionHas('error');

        $this->assertNotNull($jenis->fresh(), 'Jenis yang terpakai rekening harus tetap ada (riwayat utuh).');
    }

    public function test_jenis_yang_belum_dipakai_dapat_dihapus_dan_tercatat(): void
    {
        $jenis = $this->jenisBaru(['nama' => 'Modal Penyertaan']);
        $jenisId = $jenis->id;

        // Log DELETE memakai FK nullOnDelete → baris log tetap ada setelah
        // master terhapus, sehingga dibuktikan lewat snapshot kolom lain.
        $this->actingAs($this->sebagai('super_admin'))
            ->delete(route('pengurus.jenis-simpanan.destroy', $jenis->id))
            ->assertRedirect(route('pengurus.jenis-simpanan.index'))
            ->assertSessionHas('success');

        $this->assertNull($jenis->fresh(), 'Jenis tanpa pemakai harus terhapus.');

        // FK `nullOnDelete` mengosongkan jenis_simpanan_id setelah master terhapus;
        // snapshot nama_jenis + nilai_lama-lah yang menjaga jejak audit tetap utuh.
        $log = DB::table('jenis_simpanan_logs')
            ->where('aksi', 'DELETE')
            ->where('nama_jenis', 'Modal Penyertaan')
            ->where('kolom', 'nama')
            ->first();

        $this->assertNotNull($log, 'Penghapusan harus tercatat di jenis_simpanan_logs.');
        $this->assertSame('Modal Penyertaan', $log->nilai_lama);
        $this->assertNotNull($log->created_at, 'Log harus mencatat waktu penghapusan.');
        $this->assertNull($log->jenis_simpanan_id, 'FK dikosongkan agar insert log tidak melanggar constraint.');
        $this->assertSame('DELETE', $log->aksi);
    }

    /* =====================================================================
     | LANGKAH 5 — GUARD INTEGRITAS (tidak mengganggu fungsi lain)
     |===================================================================== */

    public function test_kode_akun_tidak_dikenal_ditolak(): void
    {
        JenisSimpanan::where('nama', 'Simpanan Hari Raya')->delete();

        $this->actingAs($this->sebagai('super_admin'))->post(route('pengurus.jenis-simpanan.store'), [
            'nama' => 'Simpanan Hari Raya',
            'kode_akun' => 'X-9999',
            'tipe' => JenisSimpanan::TIPE_LAIN,
        ])->assertSessionHasErrors('kode_akun');

        $this->assertNull(JenisSimpanan::where('nama', 'Simpanan Hari Raya')->first());
    }

    public function test_kode_akun_nonaktif_ditolak(): void
    {
        $coa = $this->coaAktif();
        $coa->update(['is_active' => false]);

        JenisSimpanan::where('nama', 'Simpanan Hari Raya')->delete();

        $this->actingAs($this->sebagai('super_admin'))->post(route('pengurus.jenis-simpanan.store'), [
            'nama' => 'Simpanan Hari Raya',
            'kode_akun' => $coa->kode_akun,
            'tipe' => JenisSimpanan::TIPE_LAIN,
        ])->assertSessionHasErrors('kode_akun');

        $this->assertNull(JenisSimpanan::where('nama', 'Simpanan Hari Raya')->first());
    }

    public function test_nama_di_luar_enum_rekening_ditolak(): void
    {
        $this->actingAs($this->sebagai('super_admin'))->post(route('pengurus.jenis-simpanan.store'), [
            'nama' => 'Simpanan Karangan Sendiri',
            'tipe' => JenisSimpanan::TIPE_LAIN,
        ])->assertSessionHasErrors('nama');
    }

    public function test_jenis_terakhir_yang_aktif_tidak_dapat_dinonaktifkan(): void
    {
        $superAdmin = $this->sebagai('super_admin');
        $tersisa = $this->jenisBaru(['nama' => 'Simpanan Hari Raya']);

        // Nonaktifkan semua jenis lain → menyisakan satu jenis aktif.
        DB::table('jenis_simpanans')
            ->where('id', '!=', $tersisa->id)
            ->update(['is_active' => false]);

        JenisSimpanan::flush();

        $this->actingAs($superAdmin)
            ->post(route('pengurus.jenis-simpanan.toggle', $tersisa->id))
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($tersisa->fresh()->is_active, 'Minimal satu jenis simpanan harus tetap aktif.');
    }

    public function test_jenis_satu_satunya_bertipe_yang_dipakai_rekening_aktif_tidak_dapat_dinonaktifkan(): void
    {
        $anggota = $this->anggota();

        $jenis = $this->jenisBaru(['nama' => 'Simpanan Hari Raya', 'tipe' => JenisSimpanan::TIPE_LAIN]);
        $this->rekening($anggota, $jenis->nama, 'Aktif');

        // Pastikan jenis ini satu-satunya yang aktif pada tipe tersebut.
        DB::table('jenis_simpanans')
            ->where('tipe', JenisSimpanan::TIPE_LAIN)
            ->where('id', '!=', $jenis->id)
            ->update(['is_active' => false]);
        JenisSimpanan::flush();

        $this->actingAs($this->sebagai('super_admin'))
            ->post(route('pengurus.jenis-simpanan.toggle', $jenis->id))
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($jenis->fresh()->is_active);
    }

    public function test_ganti_nama_yang_sudah_dipakai_rekening_ditolak(): void
    {
        $anggota = $this->anggota();
        $jenis = $this->jenisBaru(['nama' => 'Simpanan Hari Raya']);
        $this->rekening($anggota, 'Simpanan Hari Raya', 'Ditutup');

        $this->actingAs($this->sebagai('super_admin'))->put(route('pengurus.jenis-simpanan.update', $jenis->id), [
            'nama' => 'Modal Penyertaan',
            'tipe' => JenisSimpanan::TIPE_LAIN,
        ])->assertSessionHasErrors('nama');

        $this->assertSame('Simpanan Hari Raya', $jenis->fresh()->nama);
    }

    /* =====================================================================
     | LANGKAH 3 & 5 — INTEGRASI: PERUBAHAN PARAMETER LANGSUNG BERLAKU
     |===================================================================== */

    public function test_ubah_akun_coa_jenis_langsung_mengubah_akun_kredit_jurnal(): void
    {
        if (! $this->jurnalBisaDibuat()) {
            $this->markTestSkipped('CoA belum ter-seed — jurnal tidak dapat diposting.');
        }

        $jenis = $this->jenisBaru([
            'nama' => 'Simpanan Hari Raya',
            'kode_akun' => AutoJournalingService::SIMPANAN_SUKARELA,
            'tipe' => JenisSimpanan::TIPE_SUKARELA,
        ]);

        // 1) Sebelum perubahan → akun kredit mengikuti parameter awal.
        $jurnalAwal = app(AutoJournalingService::class)
            ->jurnalSetorSimpanan(100000, 'Uji jurnal awal', $jenis->nama, null, null, null, null, $jenis->tipe);

        $this->assertNotNull($jurnalAwal, 'Jurnal setoran harus terbentuk.');
        $this->assertContains(
            AutoJournalingService::SIMPANAN_SUKARELA,
            $this->kodeCoaJurnal($jurnalAwal->id),
            'Akun kredit harus mengikuti parameter awal.'
        );

        // 2) Super Admin mengubah pemetaan akun lewat UI → parameter baru berlaku.
        $coaBaru = $this->coaUjiBaru();

        $this->actingAs($this->sebagai('super_admin'))->put(route('pengurus.jenis-simpanan.update', $jenis->id), [
            'nama' => $jenis->nama,
            'kode_akun' => $coaBaru->kode_akun,
            'tipe' => $jenis->tipe,
            'is_active' => 1,
        ])->assertRedirect(route('pengurus.jenis-simpanan.index'));

        // 3) Jurnal berikutnya OTOMATIS memakai akun baru — tanpa ubah kode.
        $jurnalBaru = app(AutoJournalingService::class)
            ->jurnalSetorSimpanan(50000, 'Uji jurnal setelah perubahan', $jenis->nama, null, null, null, null, $jenis->tipe);

        $this->assertNotNull($jurnalBaru, 'Jurnal harus tetap terbentuk setelah parameter diubah.');

        $kodeKredit = $this->kodeCoaJurnal($jurnalBaru->id);
        $this->assertContains($coaBaru->kode_akun, $kodeKredit, 'Akun kredit harus mengikuti parameter baru.');
        $this->assertNotContains(
            AutoJournalingService::SIMPANAN_SUKARELA,
            $kodeKredit,
            'Akun kredit lama tidak boleh lagi dipakai.'
        );
    }

    public function test_nonaktifkan_jenis_menghentikan_pemakaian_akun_lamanya(): void
    {
        // Tipe 'lain' sengaja dipakai karena tidak memiliki akun fallback bawaan,
        // sehingga benar-benar membuktikan resolver MELEWATKAN jenis nonaktif.
        JenisSimpanan::query()->where('tipe', JenisSimpanan::TIPE_LAIN)->update(['is_active' => false]);
        JenisSimpanan::flush();

        $jenis = $this->jenisBaru([
            'nama' => 'Simpanan Hari Raya',
            'kode_akun' => AutoJournalingService::SIMPANAN_WAJIB,
            'tipe' => JenisSimpanan::TIPE_LAIN,
        ]);

        $this->assertSame(
            AutoJournalingService::SIMPANAN_WAJIB,
            JenisSimpanan::coaKodeBertipe(JenisSimpanan::TIPE_LAIN),
            'Sebelum dinonaktifkan, resolver bertipe memakai akun jenis ini.'
        );

        $this->actingAs($this->sebagai('super_admin'))
            ->post(route('pengurus.jenis-simpanan.toggle', $jenis->id))
            ->assertRedirect(route('pengurus.jenis-simpanan.index'));

        $this->assertFalse($jenis->fresh()->is_active);
        $this->assertNull(JenisSimpanan::coaKode($jenis->nama), 'Jenis nonaktif tidak boleh lagi memberi kode akun.');

        JenisSimpanan::flush();

        $this->assertNull(
            JenisSimpanan::coaKodeBertipe(JenisSimpanan::TIPE_LAIN),
            'Resolver bertipe harus melewatkan jenis nonaktif.'
        );
    }

    public function test_cache_parameter_terinvalidasi_setelah_perubahan(): void
    {
        $jenis = $this->jenisBaru(['nama' => 'Simpanan Hari Raya']);

        // Pemanasan cache.
        $this->assertTrue(JenisSimpanan::aktif('Simpanan Hari Raya'));

        // Perubahan langsung (event `saved`) harus menghapus cache.
        $jenis->update(['is_active' => false]);

        $this->assertFalse(
            JenisSimpanan::aktif('Simpanan Hari Raya'),
            'Perubahan parameter harus langsung berlaku (cache ter-invalidasi).'
        );
        $this->assertNotContains('Simpanan Hari Raya', JenisSimpanan::daftarAktif());
    }

    public function test_resolver_bertipe_mengikuti_perubahan_parameter(): void
    {
        $jenis = $this->jenisBaru([
            'nama' => 'Simpanan Hari Raya',
            'kode_akun' => '2-1002',
            'tipe' => JenisSimpanan::TIPE_LAIN,
        ]);

        $this->assertSame('2-1002', JenisSimpanan::coaKodeBertipe(JenisSimpanan::TIPE_LAIN));

        $jenis->update(['kode_akun' => '2-1001']);

        $this->assertSame(
            '2-1001',
            JenisSimpanan::coaKodeBertipe(JenisSimpanan::TIPE_LAIN),
            'Resolver bertipe harus mengikuti pemetaan akun terbaru.'
        );
    }

    /* =====================================================================
     | LANGKAH 5 — TIDAK MENGGANGGU FUNGSI SISTEM LAIN (REGRESI)
     |===================================================================== */

    public function test_setoran_jenis_berjangka_tetap_diarahkan_ke_jurnal_setor_tunai(): void
    {
        if (! $this->jurnalBisaDibuat()) {
            $this->markTestSkipped('CoA belum ter-seed — jurnal tidak dapat diposting.');
        }

        $jurnal = app(AutoJournalingService::class)
            ->jurnalSetorSimpanan(
                250000,
                'Uji penempatan deposito',
                'Simpanan Berjangka',
                null, null, null, null,
                JenisSimpanan::TIPE_BERJANGKA
            );

        if ($jurnal === null) {
            $this->markTestSkipped('Jurnal tidak terbentuk (akun CoA deposito belum tersedia).');
        }

        $this->assertSame(
            'Setoran Tunai',
            $jurnal->sumber,
            'Penempatan berjangka harus memakai alur setoran tunai (bukan titipan biasa).'
        );
    }

    public function test_jenis_historis_tetap_punya_akun_lewat_fallback(): void
    {
        // Jenis yang belum/tidak terdaftar di master harus tetap punya akun agar
        // data historis tidak kehilangan jurnal.
        $this->assertNotNull(
            JenisSimpanan::coaKode('Simpanan Pokok'),
            'Jenis historis harus tetap punya akun (fallback).'
        );
    }

    public function test_daftar_jenis_aktif_selalu_konsisten_dengan_status_master(): void
    {
        $jenis = $this->jenisBaru(['nama' => 'Simpanan Hari Raya', 'is_active' => false]);

        $this->assertNotContains($jenis->nama, JenisSimpanan::daftarAktif());
        $this->assertContains($jenis->nama, JenisSimpanan::daftarSemua());

        $jenis->update(['is_active' => true]);

        $this->assertContains($jenis->nama, JenisSimpanan::daftarAktif());
    }
}
