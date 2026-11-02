<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\Pembiayaan;
use App\Services\ExitKeanggotaanService;
use App\Support\Coa as KodeCoa;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Batch 10 — Integritas gerbang PENJAMIN pada modul Exit Keanggotaan.
 *
 * `ExitKeanggotaanService::kendalaPenjamin()` adalah satu-satunya pembaca tabel
 * `pembiayaan_penjamin` di seluruh aplikasi. Gerbang ini menentukan apakah
 * seorang anggota boleh keluar (dan menerima kembali ekuitas Simpanan
 * Pokok/Wajib) padahal ia masih menjamin pembiayaan anggota lain.
 *
 * Kebijakan yang diuji (psiko-legal sesuai praktik koperasi syariah):
 *
 *  G1. Penjamin atas pembiayaan BERJALAN yang sehat (aktif & tanpa tunggakan
 *      > 3 bulan) → TIDAK memblokir exit.
 *
 *  G2. Penjamin atas pembiayaan dengan status BERMASALAH (macet / diragukan /
 *      hapus buku) → MEMBLOKIR exit.
 *
 *  G3. Penjamin atas pembiayaan yang menunggak lebih dari 3 bulan (kontrak
 *      masih berjalan) → MEMBLOKIR exit.
 *
 *  G4. Penjaminan pada pembiayaan yang sudah LUNAS / DITOLAK / DIBATALKAN
 *      → TIDAK memblokir exit (penjaminan sudah berakhir).
 *
 *  G5. (E-01) Pembiayaan PRA-PENCAIRAN (`pending_ao`, `pending_manajer`,
 *      `pending_pengurus`, `disetujui`, `disahkan`, `pending_pencairan`) belum
 *      menyalurkan dana sehingga belum ada risiko yang dapat dibebankan ke
 *      penjamin → TIDAK boleh memblokir exit. Sebelum perbaikan, query gerbang
 *      hanya mengecualikan lunas/ditolak/dibatalkan sehingga pengajuan yang
 *      masih menunggu keputusan memblokir penjamin keluar.
 *
 * Catatan: `DatabaseTransactions` + prefix unik sehingga aman diulang.
 */
class IntegritasPenjaminanExitTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'PJN'.strtoupper(substr(uniqid(), -6));
    }

    /* =====================================================================
     | FIXTURE
     ===================================================================== */

    private function anggota(string $suffix = ''): Anggota
    {
        $kunci = $this->prefix.$suffix;

        return Anggota::create([
            'no_anggota' => 'AGT-'.$kunci,
            // NIK wajib 16 digit & unik.
            'nik' => str_pad((string) hexdec(substr(md5($kunci), 0, 12)), 16, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Uji Penjamin '.$kunci,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Pengujian No. 2',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_ACTIVE,
            'tanggal_gabung' => '2020-01-01',
        ]);
    }

    private function pembiayaan(Anggota $debitur, string $status, float $sisa = 1000000.0): Pembiayaan
    {
        return Pembiayaan::create([
            'no_pembiayaan' => 'PMB-'.$this->prefix.'-'.strtoupper(substr(uniqid(), -4)),
            'anggota_id' => $debitur->id,
            'akun_id' => (int) (Coa::query()->value('id') ?? 1),
            'akad_pembiayaan' => 'Murabahah',
            'jumlah_pembiayaan' => 1000000.0,
            'total_pembiayaan' => 1200000.0,
            'total_ujroh' => 200000.0,
            'sisa_pembiayaan' => $sisa,
            'tenor' => 10,
            'jumlah_angsuran' => 120000.0,
            'tanggal_akad' => now()->subMonths(3)->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonth()->toDateString(),
            'status_pembiayaan' => $status,
        ]);
    }

    /** Daftarkan $penjamin pada kontrak tersebut. */
    private function jadikanPenjamin(Pembiayaan $pembiayaan, Anggota $penjamin): void
    {
        DB::table('pembiayaan_penjamin')->insert([
            'pembiayaan_id' => $pembiayaan->id,
            'anggota_id' => $penjamin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Angsuran belum dibayar dengan jatuh tempo $hariLalu hari yang lalu. */
    private function angsuranTertunggak(Pembiayaan $pembiayaan, int $hariLalu): void
    {
        DB::table('angsuran')->insert([
            'pembiayaan_id' => $pembiayaan->id,
            'angsuran_ke' => 1,
            'tanggal_jatuh_tempo' => now()->subDays($hariLalu)->toDateString(),
            'pokok_angsuran' => 100000.0,
            'margin_ujroh' => 20000.0,
            'jumlah_angsuran' => 120000.0,
            'sisa_pokok' => 900000.0,
            'status_angsuran' => 'Terlambat',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Saldo Simpanan Pokok & Wajib berasal dari MUTASI JURNAL (bukan kolom
     * `rekening.saldo`) — lihat `ExitKeanggotaanService::rincianSaldo()`.
     */
    private function simpananModal(Anggota $anggota): void
    {
        foreach ([KodeCoa::SIMPANAN_POKOK => 100000.0, KodeCoa::SIMPANAN_WAJIB_MODAL => 150000.0] as $kode => $nominal) {
            $coa = Coa::where('kode_akun', $kode)->first();

            $this->assertNotNull($coa, "Akun CoA {$kode} wajib tersedia pada basis data uji.");

            $jurnal = Jurnal::create([
                'no_jurnal' => 'UJI-'.$this->prefix.'-'.strtoupper(substr(uniqid(), -5)),
                'tanggal_jurnal' => '2020-01-01',
                'jenis_jurnal' => 'Jurnal Umum',
                'sumber' => 'UJI',
                'keterangan' => 'Saldo awal simpanan modal uji penjamin',
                'total_debet' => 0,
                'total_kredit' => $nominal,
                'is_balance' => true,
                'status' => 'Posted',
                'posted_at' => now(),
            ]);

            JurnalDetail::create([
                'jurnal_id' => $jurnal->id,
                'coa_id' => $coa->id,
                'anggota_id' => $anggota->id,
                'baris' => 1,
                'debet' => 0,
                'kredit' => $nominal,
                'keterangan' => 'Uji gerbang penjamin exit',
            ]);
        }
    }

    private function service(): ExitKeanggotaanService
    {
        return app(ExitKeanggotaanService::class);
    }

    /** @return array<int, string> */
    private function kendalaPenjamin(Anggota $penjamin): array
    {
        return array_values(array_filter(
            $this->service()->kendalaExit($penjamin),
            fn (string $baris) => str_contains($baris, 'PENJAMIN')
        ));
    }

    /* =====================================================================
     | G1 — PENJAMIN PEMBIAYAAN BERJALAN SEHAT: TIDAK MEMBLOKIR
     ===================================================================== */

    public function test_penjamin_pembiayaan_aktif_sehat_tidak_memblokir_exit(): void
    {
        $penjamin = $this->anggota('-PG');
        $this->simpananModal($penjamin);

        $debitur = $this->anggota('-DB');
        $pembiayaan = $this->pembiayaan($debitur, Pembiayaan::STATUS_AKTIF);
        $this->jadikanPenjamin($pembiayaan, $penjamin);

        $this->assertSame(
            [],
            $this->kendalaPenjamin($penjamin),
            'Penjamin kontrak aktif sehat tidak boleh menjadi kendala exit. '
            .'Kendala aktual: '.implode(' | ', $this->service()->kendalaExit($penjamin))
        );

        $this->assertTrue(
            $this->service()->layakExit($penjamin),
            'Anggota yang menjamin kontrak sehat harus tetap layak exit.'
        );
    }

    /* =====================================================================
     | G2 — PENJAMIN PEMBIAYAAN BERMASALAH: MEMBLOKIR
     ===================================================================== */

    public function test_penjamin_pembiayaan_macet_memblokir_exit(): void
    {
        $penjamin = $this->anggota('-PG');
        $this->simpananModal($penjamin);

        $debitur = $this->anggota('-DB');
        $pembiayaan = $this->pembiayaan($debitur, Pembiayaan::STATUS_MACET);
        $this->jadikanPenjamin($pembiayaan, $penjamin);

        $kendala = $this->kendalaPenjamin($penjamin);

        $this->assertNotEmpty($kendala, 'Penjamin kontrak macet wajib memblokir exit.');
        $this->assertStringContainsString(
            $pembiayaan->no_pembiayaan,
            $kendala[0],
            'Pesan kendala wajib menyebut nomor pembiayaan yang dijamin.'
        );
        $this->assertFalse(
            $this->service()->layakExit($penjamin),
            'Anggota penjamin kontrak macet tidak layak exit.'
        );
    }

    public function test_penjamin_pembiayaan_diragukan_memblokir_exit(): void
    {
        $penjamin = $this->anggota('-PG');
        $this->simpananModal($penjamin);

        $debitur = $this->anggota('-DB');
        $pembiayaan = $this->pembiayaan($debitur, 'diragukan');
        $this->jadikanPenjamin($pembiayaan, $penjamin);

        $this->assertNotEmpty(
            $this->kendalaPenjamin($penjamin),
            'Penjamin kontrak berkolektibilitas diragukan wajib memblokir exit.'
        );
    }

    public function test_penjamin_pembiayaan_hapus_buku_memblokir_exit(): void
    {
        $penjamin = $this->anggota('-PG');
        $this->simpananModal($penjamin);

        $debitur = $this->anggota('-DB');
        $pembiayaan = $this->pembiayaan($debitur, Pembiayaan::STATUS_HAPUS_BUKU);
        $this->jadikanPenjamin($pembiayaan, $penjamin);

        $this->assertNotEmpty(
            $this->kendalaPenjamin($penjamin),
            'Penjamin kontrak hapus buku wajib memblokir exit (kewajiban penjamin tetap hidup).'
        );
    }

    /* =====================================================================
     | G3 — PENJAMIN KONTRAK BERJALAN YANG MENUNGGAK > 3 BULAN: MEMBLOKIR
     ===================================================================== */

    public function test_penjamin_pembiayaan_menunggak_lebih_tiga_bulan_memblokir_exit(): void
    {
        $penjamin = $this->anggota('-PG');
        $this->simpananModal($penjamin);

        $debitur = $this->anggota('-DB');
        $pembiayaan = $this->pembiayaan($debitur, Pembiayaan::STATUS_AKTIF);
        $this->angsuranTertunggak($pembiayaan, 120); // ± 4 bulan
        $this->jadikanPenjamin($pembiayaan, $penjamin);

        $kendala = $this->kendalaPenjamin($penjamin);

        $this->assertNotEmpty(
            $kendala,
            'Tunggakan > 3 bulan pada kontrak yang dijamin wajib memblokir exit penjamin.'
        );
        $this->assertStringContainsString(
            'tunggakan sejak',
            $kendala[0],
            'Pesan kendala wajib mencantumkan tanggal tunggakan tertua.'
        );
    }

    /* =====================================================================
     | G4 — PENJAMINAN PADA KONTRAK BERAKHIR: TIDAK MEMBLOKIR
     ===================================================================== */

    public function test_penjaminan_pada_kontrak_lunas_tidak_memblokir_exit(): void
    {
        $penjamin = $this->anggota('-PG');
        $this->simpananModal($penjamin);

        $debitur = $this->anggota('-DB');

        foreach ([
            Pembiayaan::STATUS_LUNAS,
            Pembiayaan::STATUS_DITOLAK,
            Pembiayaan::STATUS_DIBATALKAN,
        ] as $status) {
            $pembiayaan = $this->pembiayaan($debitur, $status, 0.0);
            $this->jadikanPenjamin($pembiayaan, $penjamin);
        }

        $this->assertSame(
            [],
            $this->kendalaPenjamin($penjamin),
            'Penjaminan pada kontrak lunas/ditolak/dibatalkan tidak boleh memblokir exit.'
        );
        $this->assertTrue(
            $this->service()->layakExit($penjamin),
            'Anggota yang seluruh penjaminannya sudah berakhir tetap layak exit.'
        );
    }

    /* =====================================================================
     | G5 — E-01: PRA-PENCAIRAN BELUM MENIMBULKAN RISIKO PENJAMIN
     ===================================================================== */

    /**
     * @return array<string, array{0: string}>
     */
    public static function statusPraPencairan(): array
    {
        return [
            'pending ao' => [Pembiayaan::STATUS_PENDING_AO],
            'pending manajer' => [Pembiayaan::STATUS_PENDING_MANAJER],
            'pending pengurus' => [Pembiayaan::STATUS_PENDING_PENGURUS],
            'pending dps' => [Pembiayaan::STATUS_PENDING_DPS],
            'direview' => [Pembiayaan::STATUS_DIREVIEW],
            'disetujui' => [Pembiayaan::STATUS_DISETUJUI],
            'disahkan' => [Pembiayaan::STATUS_DISAHKAN],
            'pending pencairan' => [Pembiayaan::STATUS_PENDING_PENCAIRAN],
        ];
    }

    #[DataProvider('statusPraPencairan')]
    public function test_penjaminan_pada_pengajuan_pra_pencairan_tidak_memblokir_exit(string $status): void
    {
        $penjamin = $this->anggota('-PG');
        $this->simpananModal($penjamin);

        $debitur = $this->anggota('-DB');
        $pembiayaan = $this->pembiayaan($debitur, $status);
        $this->jadikanPenjamin($pembiayaan, $penjamin);

        $kendala = $this->kendalaPenjamin($penjamin);

        $this->assertSame(
            [],
            $kendala,
            "E-01: status '{$status}' belum menyalurkan dana sehingga tidak boleh "
            .'memblokir exit penjamin. Kendala aktual: '.implode(' | ', $kendala)
        );
    }

    /**
     * Skenario yang mengungkap E-01 sebagaimana terjadi di lapangan: debitur
     * sedang berjalan dengan satu pinjaman AKTIF yang sudah dicairkan, lalu
     * mengajukan pinjaman KEDUA yang masih diproses. Pada ajuan ini penjamin
     * didaftarkan. Kewajiban langsung debitur tetap tertangkap
     * `kendalaPembiayaan()`; gerbang penjamin hanya boleh menyala untuk kontrak
     * yang dananya SUDAH disalurkan.
     */
    #[DataProvider('statusPraPencairan')]
    public function test_penjamin_ajuan_kedua_pra_pencairan_tidak_menambah_kendala_di_luar_kewajiban_debitur(string $status): void
    {
        $penjamin = $this->anggota('-PG');

        $debitur = $this->anggota('-DB');
        // Utang lama debitur yang sudah dicairkan (agar ajuan kedua realistis).
        $this->pembiayaan($debitur, Pembiayaan::STATUS_AKTIF);
        $ajuanBaru = $this->pembiayaan($debitur, $status);
        $this->jadikanPenjamin($ajuanBaru, $penjamin);

        $kendala = $this->service()->kendalaExit($penjamin);
        $kendalaPenjamin = array_values(array_filter(
            $kendala,
            fn (string $baris) => str_contains($baris, 'PENJAMIN')
        ));

        $this->assertSame(
            [],
            $kendalaPenjamin,
            "E-01: ajuan baru berstatus '{$status}' belum menyalurkan dana; penjamin tidak "
            .'boleh ditahan karenanya. Kendala aktual: '.implode(' | ', $kendala)
        );
    }
}
