<?php

namespace Tests\Feature;

use App\Contracts\PembiayaanBermasalahRepositoryInterface;
use App\Models\Pembiayaan;
use App\Models\User;
use App\Repositories\PembiayaanBermasalahRepository;
use App\Support\Coa;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * KARAKTERISASI — daftar Pembiayaan Bermasalah (NPF) Manajer.
 *
 * Kueri NPF dipindahkan dari `ManajerController::npf()` ke
 * `PembiayaanBermasalahRepository`. Test ini mengunci perilaku yang WAJIB
 * tetap sama:
 *   1. Hanya status berjalan yang masuk daftar (lunas/ditolak tidak).
 *   2. Angsuran yang sudah dibayar TIDAK dihitung sebagai tunggakan.
 *   3. Urutan: tunggakan terlama lebih dulu.
 *   4. Pencarian mencocokkan nama / nomor anggota.
 *   5. Kontrak dapat ditukar tanpa menyentuh pemanggil.
 *
 * Semua data memakai prefix unik + DatabaseTransactions agar deterministik
 * walau basis data dev berisi data nyata. Tanggal acuan dipatok (bukan
 * `now()`) supaya hasil tidak bergantung waktu berjalannya pengujian.
 */
class PembiayaanBermasalahRepositoryTest extends TestCase
{
    use DatabaseTransactions;

    /** Tanggal acuan NPF. */
    private const HARI_INI = '2024-06-30';

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'NPFUJI'.strtoupper(substr(uniqid(), -6));
    }

    private function repo(): PembiayaanBermasalahRepositoryInterface
    {
        return app(PembiayaanBermasalahRepositoryInterface::class);
    }

    private function acuan(): Carbon
    {
        return Carbon::parse(self::HARI_INI);
    }

    /**
     * Buat satu pembiayaan beserta jadwal angsurannya.
     *
     * @param  array<int, array{tanggal: string, status: string, jumlah: float}>  $jadwal
     */
    private function pembiayaan(
        string $label,
        string $status,
        array $jadwal,
        ?string $namaAnggota = null
    ): Pembiayaan {
        $anggotaId = DB::table('anggota')->insertGetId([
            'no_anggota' => $this->prefix.'-'.strtoupper(substr(uniqid(), -6)),
            'nik' => 'NIK'.strtoupper(substr(uniqid(), -10)),
            'nama_lengkap' => $namaAnggota ?? ($this->prefix.' Anggota '.$label),
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jalan Uji No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => 'Aktif',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => $this->prefix.'-'.strtoupper(substr(uniqid(), -8)),
            'anggota_id' => $anggotaId,
            'akun_id' => DB::table('akun')->where('kode_akun', Coa::PIUTANG_MURABAHAH)->value('id')
                ?? DB::table('akun')->value('id'),
            'akad_pembiayaan' => 'Murabahah',
            'jumlah_pembiayaan' => 1200000,
            'total_pembiayaan' => 1320000,
            'tenor' => 12,
            'jumlah_angsuran' => 110000,
            'tanggal_akad' => '2024-01-15',
            'tanggal_jatuh_tempo' => '2025-01-15',
            'sisa_pembiayaan' => 1200000,
            'status_pembiayaan' => $status,
        ]);

        foreach ($jadwal as $i => $baris) {
            /* `tanggal_bayar` adalah sumber kebenaran pembayaran (audit #30):
             | baris berstatus 'Sudah Dibayar' pada data uji WAJIB juga mengisi
             | tanggal_bayar — persis seperti hasil `AngsuranTransactionService`.
             | Baris 'Dibayar Sebagian' tetap dianggap belum lunas walau sudah
             | ada pembayaran sebagian. */
            $sudahDibayar = $baris['status'] === 'Sudah Dibayar' || ($baris['dibayar'] ?? false);

            DB::table('angsuran')->insert([
                'pembiayaan_id' => $pembiayaan->id,
                'angsuran_ke' => $i + 1,
                'tanggal_jatuh_tempo' => $baris['tanggal'],
                'tanggal_bayar' => $sudahDibayar ? self::HARI_INI : null,
                'jumlah_angsuran' => $baris['jumlah'],
                'total_bayar' => $sudahDibayar ? $baris['jumlah'] : 0,
                'sisa_pokok' => $baris['jumlah'],
                'status_angsuran' => $baris['status'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $pembiayaan;
    }

    /** @return array<int, int> */
    private function idDalamHasil(?string $cari = null): array
    {
        return $this->repo()->menunggak($this->acuan(), $cari)
            ->getCollection()->pluck('id')->all();
    }

    /* ============================================================
     * 1. Kontrak terikat & dapat ditukar
     * ============================================================ */

    public function test_kontrak_terikat_ke_implementasi_bawaan(): void
    {
        $this->assertInstanceOf(PembiayaanBermasalahRepository::class, $this->repo());
    }

    public function test_status_berjalan_sesuai_definisi(): void
    {
        $status = $this->repo()->statusBerjalan();

        foreach ([
            Pembiayaan::STATUS_DICAIRKAN,
            Pembiayaan::STATUS_AKTIF,
            Pembiayaan::STATUS_RESTRUKTURISASI,
            Pembiayaan::STATUS_PENDING_RESTRUKTURISASI,
            Pembiayaan::STATUS_MACET,
        ] as $harus) {
            $this->assertContains($harus, $status, "Status {$harus} wajib dinilai NPF.");
        }

        $this->assertNotContains(Pembiayaan::STATUS_LUNAS, $status);
        $this->assertNotContains(Pembiayaan::STATUS_DITOLAK, $status);
    }

    /* ============================================================
     * 2. Seleksi: menunggak, status berjalan, angsuran lunas diabaikan
     * ============================================================ */

    public function test_hanya_pembiayaan_menunggak_berstatus_berjalan_yang_masuk_daftar(): void
    {
        $menunggak = $this->pembiayaan('A', Pembiayaan::STATUS_AKTIF, [
            ['tanggal' => '2024-05-10', 'status' => 'Belum Jatuh Tempo', 'jumlah' => 100000],
        ]);

        // Semua angsuran sudah dibayar — bukan tunggakan.
        $lunasJadwal = $this->pembiayaan('B', Pembiayaan::STATUS_AKTIF, [
            ['tanggal' => '2024-05-10', 'status' => 'Sudah Dibayar', 'jumlah' => 100000],
        ]);

        // Belum jatuh tempo — bukan tunggakan.
        $belumJatuhTempo = $this->pembiayaan('C', Pembiayaan::STATUS_AKTIF, [
            ['tanggal' => '2024-09-10', 'status' => 'Belum Jatuh Tempo', 'jumlah' => 100000],
        ]);

        // Menunggak tetapi status Lunas — dikecualikan.
        $statusLunas = $this->pembiayaan('D', Pembiayaan::STATUS_LUNAS, [
            ['tanggal' => '2024-05-10', 'status' => 'Belum Jatuh Tempo', 'jumlah' => 100000],
        ]);

        $hasil = $this->idDalamHasil();

        $this->assertContains($menunggak->id, $hasil, 'Pembiayaan menunggak berstatus aktif harus masuk.');
        $this->assertNotContains($lunasJadwal->id, $hasil, 'Angsuran yang sudah dibayar bukan tunggakan.');
        $this->assertNotContains($belumJatuhTempo->id, $hasil, 'Angsuran belum jatuh tempo bukan tunggakan.');
        $this->assertNotContains($statusLunas->id, $hasil, 'Status lunas tidak dinilai NPF.');
    }

    /* ============================================================
     * 3. Urutan: tunggakan terlama lebih dulu
     * ============================================================ */

    public function test_urutan_dari_tunggakan_terlama(): void
    {
        $baru = $this->pembiayaan('BARU', Pembiayaan::STATUS_AKTIF, [
            ['tanggal' => '2024-06-20', 'status' => 'Belum Jatuh Tempo', 'jumlah' => 100000],
        ]);

        $lama = $this->pembiayaan('LAMA', Pembiayaan::STATUS_MACET, [
            ['tanggal' => '2024-01-05', 'status' => 'Belum Jatuh Tempo', 'jumlah' => 100000],
            ['tanggal' => '2024-02-05', 'status' => 'Sudah Dibayar', 'jumlah' => 100000],
            ['tanggal' => '2024-03-05', 'status' => 'Belum Jatuh Tempo', 'jumlah' => 100000],
        ]);

        $hasil = $this->idDalamHasil();

        $posLama = array_search($lama->id, $hasil, true);
        $posBaru = array_search($baru->id, $hasil, true);

        $this->assertNotFalse($posLama);
        $this->assertNotFalse($posBaru);
        $this->assertLessThan($posBaru, $posLama, 'Tunggakan terlama harus tampil lebih dulu.');
    }

    /**
     * Relasi `jadwalAngsuran` pada hasil HANYA memuat angsuran menunggak,
     * sehingga penjumlahan langsung = total tunggakan.
     */
    public function test_relasi_jadwal_hanya_memuat_angsuran_menunggak(): void
    {
        $item = $this->pembiayaan('TOTAL', Pembiayaan::STATUS_AKTIF, [
            ['tanggal' => '2024-02-05', 'status' => 'Belum Jatuh Tempo', 'jumlah' => 100000],
            ['tanggal' => '2024-03-05', 'status' => 'Sudah Dibayar', 'jumlah' => 100000],
            ['tanggal' => '2024-04-05', 'status' => 'Belum Jatuh Tempo', 'jumlah' => 150000],
            ['tanggal' => '2024-12-05', 'status' => 'Belum Jatuh Tempo', 'jumlah' => 999000],
        ]);

        $baris = $this->repo()->menunggak($this->acuan())
            ->getCollection()->firstWhere('id', $item->id);

        $this->assertNotNull($baris, 'Pembiayaan uji harus muncul di daftar.');

        $this->assertSame(2, $baris->jadwalAngsuran->count(), 'Hanya 2 angsuran menunggak (lunas & belum jatuh tempo disaring).');
        $this->assertEqualsWithDelta(250000.0, (float) $baris->jadwalAngsuran->sum('jumlah_angsuran'), 0.01);
        $this->assertTrue($baris->relationLoaded('anggota'));
        $this->assertTrue($baris->relationLoaded('produk'));
    }

    /**
     * REGRESI #30 — `status_angsuran` yang BASI tidak boleh membuat NPF
     * overstated.
     *
     * Kasus nyata pada data demo: angsuran sudah dibayar (`tanggal_bayar`
     * terisi) tetapi kolom `status_angsuran` masih tertulis 'Jatuh Tempo'
     * karena statusnya tidak pernah diselaraskan. Bila penentuan tunggakan
     * memakai kolom status, baris itu ikut dijumlah sebagai tunggakan —
     * padahal uangnya sudah masuk. Sumber kebenaran pembayaran adalah
     * `tanggal_bayar`.
     */
    public function test_angsuran_berstatus_basi_tetapi_sudah_dibayar_bukan_tunggakan(): void
    {
        $pembiayaan = $this->pembiayaan('STALE', Pembiayaan::STATUS_AKTIF, [
            // tanggal_bayar terisi (dibayar) walau status masih 'Jatuh Tempo'.
            ['tanggal' => '2024-02-05', 'status' => 'Jatuh Tempo', 'jumlah' => 100000, 'dibayar' => true],
            // Tunggakan sungguhan: lewat JT dan belum dibayar.
            ['tanggal' => '2024-03-05', 'status' => 'Terlambat', 'jumlah' => 200000],
        ]);

        $hasil = $this->idDalamHasil();
        $this->assertContains($pembiayaan->id, $hasil, 'Masih ada satu angsuran menunggak.');

        $baris = $this->repo()->menunggak($this->acuan())
            ->getCollection()->firstWhere('id', $pembiayaan->id);

        $this->assertNotNull($baris);
        $this->assertSame(1, $baris->jadwalAngsuran->count(), 'Baris basi (sudah dibayar) tidak boleh ikut dihitung.');
        $this->assertEqualsWithDelta(200000.0, (float) $baris->jadwalAngsuran->sum('jumlah_angsuran'), 0.01);
    }

    /**
     * REGRESI #30 — `'Dibayar Sebagian'` tetap dianggap menunggak walau
     * `tanggal_bayar` sudah terisi, karena pokoknya belum tertagih penuh.
     */
    public function test_angsuran_dibayar_sebagian_tetap_dihitung_tunggakan(): void
    {
        $pembiayaan = $this->pembiayaan('SEBAGIAN', Pembiayaan::STATUS_AKTIF, [
            ['tanggal' => '2024-03-05', 'status' => 'Dibayar Sebagian', 'jumlah' => 300000, 'dibayar' => true],
        ]);

        $hasil = $this->idDalamHasil();
        $this->assertContains($pembiayaan->id, $hasil, 'Pembayaran sebagian belum melunasi pokok.');

        $baris = $this->repo()->menunggak($this->acuan())
            ->getCollection()->firstWhere('id', $pembiayaan->id);

        $this->assertNotNull($baris);
        $this->assertSame(1, $baris->jadwalAngsuran->count());
        $this->assertEqualsWithDelta(300000.0, (float) $baris->jadwalAngsuran->sum('jumlah_angsuran'), 0.01);
    }

    /* ============================================================
     * 4. Pencarian anggota
     * ============================================================ */

    public function test_pencarian_mencocokkan_nama_dan_nomor_anggota(): void
    {
        $kunci = strtoupper(substr($this->prefix, -6));

        $cocokNama = $this->pembiayaan('N', Pembiayaan::STATUS_AKTIF, [
            ['tanggal' => '2024-05-10', 'status' => 'Belum Jatuh Tempo', 'jumlah' => 100000],
        ], 'Nasabah '.$kunci.' Sejahtera');

        $cocokNomor = $this->pembiayaan('M', Pembiayaan::STATUS_AKTIF, [
            ['tanggal' => '2024-05-10', 'status' => 'Belum Jatuh Tempo', 'jumlah' => 100000],
        ]);

        $tidakCocok = $this->pembiayaan('X', Pembiayaan::STATUS_AKTIF, [
            ['tanggal' => '2024-05-10', 'status' => 'Belum Jatuh Tempo', 'jumlah' => 100000],
        ], 'Sama Sekali Lain');

        // Pencarian nama.
        $hasilNama = $this->idDalamHasil('Nasabah '.$kunci);
        $this->assertContains($cocokNama->id, $hasilNama);
        $this->assertNotContains($tidakCocok->id, $hasilNama);

        // Pencarian nomor anggota.
        $noAnggota = DB::table('anggota')->where('id', $cocokNomor->anggota_id)->value('no_anggota');
        $hasilNomor = $this->idDalamHasil($noAnggota);
        $this->assertContains($cocokNomor->id, $hasilNomor);
        $this->assertNotContains($tidakCocok->id, $hasilNomor);
    }

    /* ============================================================
     * 5. Halaman NPF tetap dapat dirender Manajer
     * ============================================================ */

    public function test_halaman_npf_dapat_diakses_manajer(): void
    {
        $manajer = User::firstOrCreate(
            ['email' => 'manajer.npf.uji@test.local'],
            [
                'name' => 'Manajer NPF Uji',
                'password' => Hash::make('password'),
                'role' => 'manajer',
                'is_active' => true,
            ]
        );

        $this->actingAs($manajer)
            ->get(route('manajer.npf.index'))
            ->assertOk()
            ->assertViewHas('pembiayaanBermasalah')
            ->assertViewHas('totalNpf');
    }
}
