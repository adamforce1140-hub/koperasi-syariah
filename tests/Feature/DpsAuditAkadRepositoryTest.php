<?php

namespace Tests\Feature;

use App\Contracts\AuditAkadRepositoryInterface;
use App\Models\Coa;
use App\Models\Pembiayaan;
use App\Models\User;
use App\Repositories\AuditAkadRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * KARAKTERISASI — `AuditAkadRepository` (modul Audit Pembiayaan & Akad DPS).
 *
 * Mengunci perilaku hasil ekstraksi query dari `DpsController::auditAkad()`
 * agar refactor terbukti behavior-preserving:
 *   A. Kontrak terikat ke implementasi bawaan dan dapat ditukar.
 *   B. Basis populasi `pascaPersetujuan()` dihormati (status di luar daftar
 *      tidak masuk tabel maupun badge).
 *   C. Badge per akad & total memakai basis yang sama dengan tabel, tanpa
 *      filter akad.
 *   D. Filter akad hanya berlaku pada tabel utama.
 *   E. Ringkasan per akad & status mengelompokkan populasi yang sama.
 */
class DpsAuditAkadRepositoryTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'AKADUJI'.strtoupper(substr(uniqid(), -6));
    }

    private function repo(): AuditAkadRepositoryInterface
    {
        return app(AuditAkadRepositoryInterface::class);
    }

    private function penggunaDps(): User
    {
        return User::firstOrCreate(
            ['email' => 'dps.auditakad.repo@test.local'],
            [
                'name' => 'DPS Audit Akad Repo',
                'password' => Hash::make('password'),
                'role' => 'dps',
            ]
        );
    }

    private function buatPembiayaan(string $suffix, string $akad, string $status): Pembiayaan
    {
        static $anggotaId = null;

        if ($anggotaId === null) {
            $anggotaId = DB::table('anggota')->value('id')
                ?: DB::table('anggota')->insertGetId([
                    'no_anggota' => 'AGT-AKAD-'.strtoupper(substr(uniqid(), -6)),
                    'nik' => '327301010101'.rand(1000, 9999),
                    'nama_lengkap' => 'Anggota Uji Audit Akad',
                    'jenis_kelamin' => 'Laki-laki',
                    'alamat' => 'Jl. Uji Audit Akad',
                    'kota' => 'Bandung',
                    'provinsi' => 'Jawa Barat',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        $pokok = 1_000_000;

        return Pembiayaan::create([
            'no_pembiayaan' => $this->prefix.'-'.$suffix,
            'anggota_id' => $anggotaId,
            'akun_id' => Coa::query()->value('id') ?? 1,
            'akad_pembiayaan' => $akad,
            'tujuan_pembiayaan' => 'Uji repository audit akad',
            'jumlah_pembiayaan' => $pokok,
            'margin_nisbah' => 10,
            'total_ujroh' => $pokok * 0.1,
            'total_pembiayaan' => $pokok * 1.1,
            'jumlah_angsuran' => round($pokok * 1.1 / 12, 2),
            'tenor' => 12,
            'sisa_pembiayaan' => $pokok * 1.1,
            'status_pembiayaan' => $status,
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths(12)->toDateString(),
            'tgl_pencairan' => now()->subDay()->toDateString(),
            'is_disbursed' => false,
        ]);
    }

    public function test_kontrak_terikat_ke_implementasi_bawaan(): void
    {
        $this->assertInstanceOf(AuditAkadRepository::class, $this->repo());
    }

    public function test_kontrak_dapat_ditukar_tanpa_menyentuh_pemanggil(): void
    {
        $spy = \Mockery::mock(AuditAkadRepositoryInterface::class);
        $spy->shouldReceive('hitungPerAkad')->once()->andReturn(collect(['Murabahah' => 3]));
        $spy->shouldReceive('total')->once()->andReturn(3);
        $spy->shouldReceive('ringkasanPerAkadDanStatus')->once()->andReturn(collect());
        $spy->shouldReceive('daftar')->once()->andReturn(
            new LengthAwarePaginator([], 0, 25)
        );

        $this->app->instance(AuditAkadRepositoryInterface::class, $spy);

        $res = $this->actingAs($this->penggunaDps())
            ->get(route('dps.audit-akad', ['search' => $this->prefix]));

        $res->assertOk();
        $this->assertSame(3, (int) $res->viewData('totalSemua'));
        $this->assertSame(['Murabahah' => 3], $res->viewData('counterAkad')->all());
    }

    public function test_hanya_status_pasca_persetujuan_masuk_tabel_dan_badge(): void
    {
        $this->buatPembiayaan('P1', 'Murabahah', Pembiayaan::STATUS_AKTIF);
        $this->buatPembiayaan('P2', 'Murabahah', Pembiayaan::STATUS_DICAIRKAN);
        $this->buatPembiayaan('P3', 'Murabahah', Pembiayaan::STATUS_PENDING_MANAJER);
        $this->buatPembiayaan('P4', 'Murabahah', Pembiayaan::STATUS_DITOLAK);

        $repo = $this->repo();

        $nomor = $repo->daftar(null, null, $this->prefix)->pluck('no_pembiayaan')->sort()->values()->all();

        $this->assertSame(
            [$this->prefix.'-P1', $this->prefix.'-P2'],
            $nomor,
            'Hanya pembiayaan pasca-persetujuan yang boleh masuk tabel.'
        );
        $this->assertSame(2, $repo->total(null, $this->prefix));
        $this->assertSame(2, (int) $repo->hitungPerAkad(null, $this->prefix)['Murabahah']);
    }

    public function test_badge_tetap_menghitung_semua_akad_saat_tabel_difilter(): void
    {
        $this->buatPembiayaan('Q1', 'Murabahah', Pembiayaan::STATUS_AKTIF);
        $this->buatPembiayaan('Q2', 'Ijarah', Pembiayaan::STATUS_AKTIF);
        $this->buatPembiayaan('Q3', 'Ijarah', Pembiayaan::STATUS_LUNAS);

        $repo = $this->repo();

        $tabel = $repo->daftar('Murabahah', null, $this->prefix);
        $this->assertSame(1, $tabel->total(), 'Filter akad hanya berlaku pada tabel utama.');

        $counter = $repo->hitungPerAkad(null, $this->prefix);
        $this->assertSame(1, (int) $counter['Murabahah']);
        $this->assertSame(2, (int) $counter['Ijarah']);
        $this->assertSame(3, $repo->total(null, $this->prefix));
    }

    public function test_filter_status_mempersempit_tabel_dan_badge(): void
    {
        $this->buatPembiayaan('R1', 'Murabahah', Pembiayaan::STATUS_AKTIF);
        $this->buatPembiayaan('R2', 'Murabahah', Pembiayaan::STATUS_LUNAS);
        $this->buatPembiayaan('R3', 'Ijarah', Pembiayaan::STATUS_AKTIF);

        $repo = $this->repo();

        $nomor = $repo->daftar(null, Pembiayaan::STATUS_AKTIF, $this->prefix)
            ->pluck('no_pembiayaan')->sort()->values()->all();

        $this->assertSame([$this->prefix.'-R1', $this->prefix.'-R3'], $nomor);

        $counter = $repo->hitungPerAkad(Pembiayaan::STATUS_AKTIF, $this->prefix);
        $this->assertSame(1, (int) $counter['Murabahah']);
        $this->assertSame(1, (int) $counter['Ijarah']);
        $this->assertSame(2, $repo->total(Pembiayaan::STATUS_AKTIF, $this->prefix));
    }

    public function test_pencarian_mencocokkan_nomor_pembiayaan(): void
    {
        $this->buatPembiayaan('S1', 'Murabahah', Pembiayaan::STATUS_AKTIF);
        $this->buatPembiayaan('S2', 'Murabahah', Pembiayaan::STATUS_AKTIF);

        $repo = $this->repo();

        $this->assertSame(
            [$this->prefix.'-S1'],
            $repo->daftar(null, null, $this->prefix.'-S1')->pluck('no_pembiayaan')->all()
        );
    }

    public function test_ringkasan_mengelompokkan_per_akad_dan_status(): void
    {
        $this->buatPembiayaan('T1', 'Mudharabah', Pembiayaan::STATUS_AKTIF);
        $this->buatPembiayaan('T2', 'Mudharabah', Pembiayaan::STATUS_AKTIF);
        $this->buatPembiayaan('T3', 'Mudharabah', Pembiayaan::STATUS_LUNAS);
        $this->buatPembiayaan('T4', 'Mudharabah', Pembiayaan::STATUS_PENDING_MANAJER);

        $baris = $this->repo()->ringkasanPerAkadDanStatus()
            ->filter(fn ($r) => $r->akad_pembiayaan === 'Mudharabah')
            ->keyBy('status_pembiayaan');

        $this->assertCount(2, $baris, 'Status di luar pasca-persetujuan tidak boleh ikut diringkas.');

        $this->assertGreaterThanOrEqual(2, (int) $baris[Pembiayaan::STATUS_AKTIF]->jumlah);
        $this->assertGreaterThanOrEqual(1, (int) $baris[Pembiayaan::STATUS_LUNAS]->jumlah);
        // SUM di database mengembalikan string numerik.
        $this->assertGreaterThanOrEqual(
            2_000_000.0,
            (float) $baris[Pembiayaan::STATUS_AKTIF]->total
        );
    }

    public function test_halaman_audit_akad_memakai_repository_bawaan(): void
    {
        $this->buatPembiayaan('U1', 'Qardh', Pembiayaan::STATUS_AKTIF);

        $res = $this->actingAs($this->penggunaDps())
            ->get(route('dps.audit-akad', ['search' => $this->prefix]));

        $res->assertOk();
        $this->assertSame(1, $res->viewData('pembiayaan')->total());
        $this->assertSame(1, (int) $res->viewData('totalSemua'));
        $this->assertSame(1, (int) $res->viewData('counterAkad')['Qardh']);
    }
}
