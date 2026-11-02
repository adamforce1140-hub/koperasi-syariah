<?php

namespace Tests\Feature;

use App\Contracts\KinerjaStafRepositoryInterface;
use App\Models\PengajuanPembiayaan;
use App\Models\Transaksi;
use App\Models\User;
use App\Repositories\KinerjaStafRepository;
use App\Support\HierarkiRole;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * KARAKTERISASI — laporan Kinerja Staf Manajer (butir 4 rencana arsitektur).
 *
 * Kueri agregat dipindahkan dari `ManajerController` ke
 * `KinerjaStafRepository` (dibalik `KinerjaStafRepositoryInterface`).
 * Test ini mengunci perilaku yang WAJIB tetap sama:
 *   1. Kontrak terikat ke implementasi bawaan dan dapat ditukar.
 *   2. Penanggung jawab pengajuan dibaca berurutan diproses_oleh → ao_id → created_by.
 *   3. Hanya transaksi 'Sukses' yang dihitung sebagai kinerja Teller.
 *   4. Transaksi sistem otomatis (AUTODEBET_TAZIR, 'Setoran SHU …') dikecualikan.
 *   5. Halaman /manajer/kinerja-staf tetap dialihkan (fitur tersembunyi),
 *      sementara repository tetap memasok data laporan.
 *
 * Semua pengujian memakai SELISIH (delta) dan data ber-prefix unik agar
 * deterministik walau basis data dev berisi data nyata.
 */
class ManajerKinerjaStafRepositoryTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'KINUJI'.strtoupper(substr(uniqid(), -6));
    }

    private function repo(): KinerjaStafRepositoryInterface
    {
        return app(KinerjaStafRepositoryInterface::class);
    }

    private function staf(string $peran, string $suffix): User
    {
        return User::create([
            'name' => $this->prefix.' '.$suffix,
            'email' => strtolower($this->prefix.'.'.$suffix).'@test.local',
            'password' => Hash::make('password'),
            'role' => $peran,
            'is_active' => true,
        ]);
    }

    private function transaksi(User $user, string $jenis, float $jumlah, string $status, string $waktu): void
    {
        DB::table('transaksi')->insert([
            'no_transaksi' => $this->prefix.'-'.strtoupper(substr(uniqid(), -8)),
            'user_id' => $user->id,
            'jenis_transaksi' => $jenis,
            'jumlah' => $jumlah,
            'posisi' => 'Debet',
            'saldo_setelah' => $jumlah,
            'sumber_transaksi' => 'Teller',
            'status_transaksi' => $status,
            'status' => 'success',
            'waktu_transaksi' => $waktu,
        ]);
    }

    /* ============================================================
     * 1. Kontrak terikat & dapat ditukar
     * ============================================================ */

    public function test_kontrak_terikat_ke_implementasi_bawaan(): void
    {
        $this->assertInstanceOf(KinerjaStafRepository::class, $this->repo());
    }

    public function test_kontrak_dapat_ditukar_tanpa_menyentuh_pemanggil(): void
    {
        $spy = \Mockery::mock(KinerjaStafRepositoryInterface::class);
        $spy->shouldReceive('penggunaKelompok')->once()->with('ao')->andReturn(collect());

        $this->app->instance(KinerjaStafRepositoryInterface::class, $spy);

        $this->assertSame([], app(KinerjaStafRepositoryInterface::class)
            ->penggunaKelompok('ao')->all());
    }

    /* ============================================================
     * 2. Agregat transaksi: status, pengecualian sistem, delta
     * ============================================================ */

    public function test_agregat_transaksi_hanya_status_sukses_dan_mengecualikan_transaksi_sistem(): void
    {
        $teller = $this->staf('teller', 'T1');
        $bulan = 3;
        $tahun = 2024;
        $tanggal = sprintf('%04d-%02d-15 10:00:00', $tahun, $bulan);

        $sebelum = $this->repo()->agregatTransaksi($bulan, $tahun);

        // Dihitung: dua transaksi pelayanan normal berstatus Sukses.
        $this->transaksi($teller, $this->prefix.' Setor Tunai', 100000.0, 'Sukses', $tanggal);
        $this->transaksi($teller, $this->prefix.' Tarik Tunai', 40000.0, 'Sukses', $tanggal);
        // Tidak dihitung: status selain Sukses.
        $this->transaksi($teller, $this->prefix.' Setor Tunai', 999999.0, 'Voided', $tanggal);
        // Tidak dihitung: transaksi sistem otomatis (dua bentuk pengecualian).
        $this->transaksi($teller, 'AUTODEBET_TAZIR', 777777.0, 'Sukses', $tanggal);
        $this->transaksi($teller, 'Setoran SHU Tahun Buku 2024', 888888.0, 'Sukses', $tanggal);

        $sesudah = $this->repo()->agregatTransaksi($bulan, $tahun);

        $baris = $sesudah[$teller->id] ?? null;

        $this->assertNotNull($baris, 'Agregat harus memuat staf yang memiliki transaksi.');

        $sebelumT = (int) ($sebelum[$teller->id]->total_transaksi ?? 0);
        $sebelumV = (float) ($sebelum[$teller->id]->volume_transaksi ?? 0);

        $this->assertSame(2, (int) $baris->total_transaksi - $sebelumT);
        $this->assertSame(140000.0, (float) $baris->volume_transaksi - $sebelumV);
    }

    /* ============================================================
     * 3. Agregat pengajuan: urutan penanggung jawab
     * ============================================================ */

    public function test_agregat_pengajuan_memakai_urutan_diproses_oleh_ao_id_created_by(): void
    {
        $ao = $this->staf('ao', 'A1');
        $aoCadangan = $this->staf('ao', 'A2');
        $pembuat = $this->staf('admin', 'ADM');

        $bulan = 4;
        $tahun = 2024;

        $sebelum = $this->repo()->agregatPengajuan($bulan, $tahun);

        $dasar = [
            'no_pengajuan' => $this->prefix.'-'.strtoupper(substr(uniqid(), -8)),
            'anggota_id' => DB::table('anggota')->value('id'),
            'jenis_akad' => 'Murabahah',
            'tenor' => 12,
            'tujuan' => 'Modal usaha uji kinerja',
            'jumlah_pengajuan' => 500000.0,
            'status' => PengajuanPembiayaan::STATUS_DIREVIEW,
            'created_at' => sprintf('%04d-%02d-10 09:00:00', $tahun, $bulan),
            'updated_at' => sprintf('%04d-%02d-10 09:00:00', $tahun, $bulan),
        ];

        // diproses_oleh diutamakan.
        DB::table('pengajuan_pembiayaan')->insert($dasar + [
            'diproses_oleh' => $ao->id, 'ao_id' => $aoCadangan->id, 'created_by' => $pembuat->id,
        ]);
        // Tanpa diproses_oleh → jatuh ke ao_id.
        DB::table('pengajuan_pembiayaan')->insert(array_merge($dasar, [
            'no_pengajuan' => $this->prefix.'-'.strtoupper(substr(uniqid(), -8)),
            'diproses_oleh' => null, 'ao_id' => $aoCadangan->id, 'created_by' => $pembuat->id,
        ]));

        $sesudah = $this->repo()->agregatPengajuan($bulan, $tahun);

        $deltaA1 = (int) ($sesudah[$ao->id]->total_pengajuan ?? 0) - (int) ($sebelum[$ao->id]->total_pengajuan ?? 0);
        $deltaA2 = (int) ($sesudah[$aoCadangan->id]->total_pengajuan ?? 0) - (int) ($sebelum[$aoCadangan->id]->total_pengajuan ?? 0);

        $this->assertSame(1, $deltaA1, 'diproses_oleh harus diutamakan.');
        $this->assertSame(1, $deltaA2, 'ao_id dipakai bila diproses_oleh kosong.');
    }

    /* ============================================================
     * 4. Perilaku halaman laporan tetap terjaga
     * ============================================================ */

    /**
     * `kinerjaStaf` termasuk FITUR_DISEMBUNYIKAN — halaman ini memang
     * dialihkan (302) ke dashboard Manajer dengan pesan ramah, bukan 403.
     * Test ini mengunci perilaku tersebut agar refactor repository tidak
     * diam-diam membukanya kembali.
     */
    public function test_halaman_kinerja_staf_dialihkan_untuk_manajer(): void
    {
        $manajer = User::firstOrCreate(
            ['email' => 'manajer.kinerja.uji@test.local'],
            [
                'name' => 'Manajer Kinerja Uji',
                'password' => Hash::make('password'),
                'role' => 'manajer',
                'is_active' => true,
            ]
        );

        $this->actingAs($manajer)
            ->get(route('manajer.kinerja-staf.index'))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    /**
     * Repository tetap dapat memasok baris laporan untuk periode berjalan:
     * daftar staf AO & Teller (kelompok `kinerja_ao`/`kinerja_teller`) dan
     * agregat transaksi yang berkunci `user_id`.
     */
    public function test_repository_menghasilkan_data_kinerja_untuk_periode_berjalan(): void
    {
        $bulan = (int) now()->month;
        $tahun = (int) now()->year;

        foreach (['kinerja_ao', 'kinerja_teller'] as $kelompok) {
            $this->assertNotEmpty(
                HierarkiRole::kelompok($kelompok),
                "Kelompok '{$kelompok}' harus terdefinisi di config/role_hierarki.php."
            );
            $this->assertInstanceOf(
                Collection::class,
                $this->repo()->penggunaKelompok($kelompok)
            );
        }

        $agregat = $this->repo()->agregatTransaksi($bulan, $tahun);

        foreach ($agregat as $kunci => $baris) {
            $this->assertSame((int) $baris->user_id, $kunci);
            $this->assertGreaterThanOrEqual(0, (float) $baris->volume_transaksi);
        }
    }
}
