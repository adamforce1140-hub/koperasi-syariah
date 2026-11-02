<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Pembiayaan;
use App\Models\PengajuanPembiayaan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Dashboard Pengurus — konsistensi data pembiayaan aktif.
 *
 * Cakupan pengujian:
 *   A. Kartu "Pembiayaan Aktif" menghitung SELURUH kontrak berjalan, termasuk
 *      'restrukturisasi' — bukan hanya aktif/disetujui/dicairkan.
 *   B. Badge kartu selalu sama dengan jumlah baris tabel pada halaman
 *      pembiayaan (regresi yang dicegah: badge 4 sementara data 5, atau
 *      badge 5 sementara tabel kosong karena filter status lokal).
 *   C. Kelima akad (Murabahah, Mudharabah, Musyarakah, Ijarah, Qardh) tampil
 *      di dashboard tanpa terhalang filter status.
 *   D. Invarian sinkronisasi: setiap pengajuan berstatus Disetujui memiliki
 *      record Pembiayaan (kolom pembiayaan_id terisi).
 *   E. Rekonsiliasi mengisi tautan yang hilang dan bersifat idempoten.
 */
class DashboardPengurusPembiayaanAktifTest extends TestCase
{
    use DatabaseTransactions;

    /** Kelima produk pembiayaan yang wajib tampil di Dashboard Pengurus. */
    private const AKAD = ['Murabahah', 'Mudharabah', 'Musyarakah', 'Ijarah', 'Qardh'];

    /** Prefix unik agar data uji terisolasi dari data dev. */
    private string $prefix;

    /** Anggota uji khusus, dipakai sebagai kata kunci pencarian tabel. */
    private ?int $anggotaId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'PGSUJI'.strtoupper(substr(uniqid(), -6));
    }

    private function penggunaPengurus(): User
    {
        return User::firstOrCreate(
            ['email' => 'pengurus.dashboard@test.local'],
            [
                'name' => 'Pengurus Dashboard Uji',
                'password' => Hash::make('password'),
                'role' => 'pengurus',
            ]
        );
    }

    private function anggotaUji(): int
    {
        if ($this->anggotaId === null) {
            $this->anggotaId = (int) DB::table('anggota')->insertGetId([
                'no_anggota' => 'AGT-'.$this->prefix,
                'nik' => '9999'.substr((string) random_int(10000000, 99999999), 0, 8),
                'nama_lengkap' => 'Anggota '.$this->prefix,
                'jenis_kelamin' => 'Laki-laki',
                'alamat' => 'Jl. Uji Dashboard No. 1',
                'kota' => 'Bandung',
                'provinsi' => 'Jawa Barat',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $this->anggotaId;
    }

    /**
     * Buat pembiayaan uji ber-prefix agar mudah diisolasi dari data dev.
     */
    private function buatPembiayaan(string $suffix, string $akad, string $status): Pembiayaan
    {
        $pokok = 1_000_000;

        return Pembiayaan::create([
            'no_pembiayaan' => $this->prefix.'-'.$suffix,
            'anggota_id' => $this->anggotaUji(),
            'akun_id' => DB::table('akun')->value('id') ?? 1,
            'akad_pembiayaan' => $akad,
            'tujuan_pembiayaan' => 'Uji konsistensi dashboard pengurus',
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
        ]);
    }

    /**
     * Selisih angka dashboard terhadap kondisi sebenarnya di database.
     *
     * Data dev tidak dihapus (test memakai transaksi), jadi pengujian dilakukan
     * dengan mengukur selisih sebelum dan sesudah data uji dibuat.
     */
    private function selisih(callable $aksi): array
    {
        $sebelum = [
            'badge' => (int) Pembiayaan::berjalan()->count(),
            'tabel' => (int) Pembiayaan::statusIn(Pembiayaan::statusBerjalan())->count(),
            'belum' => (int) PengajuanPembiayaan::belumSinkron()->count(),
        ];

        $aksi();

        return [
            'badge' => (int) Pembiayaan::berjalan()->count() - $sebelum['badge'],
            'tabel' => (int) Pembiayaan::statusIn(Pembiayaan::statusBerjalan())->count() - $sebelum['tabel'],
            'belum' => (int) PengajuanPembiayaan::belumSinkron()->count() - $sebelum['belum'],
        ];
    }

    /**
     * A. 'restrukturisasi' harus ikut terhitung sebagai pembiayaan berjalan.
     * Regresi yang dicegah: kartu menampilkan 4 dari 5 kontrak yang berjalan.
     */
    public function test_restrukturisasi_ikut_terhitung_sebagai_pembiayaan_berjalan(): void
    {
        $delta = $this->selisih(function () {
            $this->buatPembiayaan('A1', 'Murabahah', Pembiayaan::STATUS_AKTIF);
            $this->buatPembiayaan('A2', 'Mudharabah', Pembiayaan::STATUS_AKTIF);
            $this->buatPembiayaan('A3', 'Musyarakah', Pembiayaan::STATUS_AKTIF);
            $this->buatPembiayaan('A4', 'Ijarah', Pembiayaan::STATUS_AKTIF);
            $this->buatPembiayaan('A5', 'Qardh', Pembiayaan::STATUS_RESTRUKTURISASI);
        });

        $this->assertSame(5, $delta['badge'], 'Kartu "Pembiayaan Aktif" harus menghitung kelima kontrak berjalan, termasuk restrukturisasi.');
        $this->assertSame(5, $delta['tabel'], 'Tabel pengurus harus memuat kelima kontrak berjalan.');

        // Daftar status berjalan wajib memuat restrukturisasi.
        $this->assertContains(
            Pembiayaan::STATUS_RESTRUKTURISASI,
            Pembiayaan::statusBerjalan(),
            "'restrukturisasi' harus ada di daftar status berjalan."
        );

        // Pembiayaan lunas / ditolak tidak boleh ikut terhitung.
        $this->buatPembiayaan('A6', 'Murabahah', Pembiayaan::STATUS_LUNAS);
        $this->assertSame(
            5,
            (int) Pembiayaan::berjalan()
                ->where('no_pembiayaan', 'like', $this->prefix.'%')->count(),
            'Pembiayaan lunas tidak boleh dihitung sebagai pembiayaan berjalan.'
        );
    }

    /**
     * B. Kartu tidak boleh menyembunyikan data: seluruh pembiayaan berjalan
     *    wajib tercakup oleh query kartu (tanpa filter status lokal yang sempit).
     */
    public function test_status_berjalan_menutup_seluruh_kontrak_berjalan(): void
    {
        $jenisKontrakBerjalan = [
            Pembiayaan::STATUS_DISETUJUI,
            Pembiayaan::STATUS_DISAHKAN,
            Pembiayaan::STATUS_PENDING_PENCAIRAN,
            Pembiayaan::STATUS_DICAIRKAN,
            Pembiayaan::STATUS_AKTIF,
            Pembiayaan::STATUS_RESTRUKTURISASI,
            Pembiayaan::STATUS_PENDING_RESTRUKTURISASI,
        ];

        foreach ($jenisKontrakBerjalan as $i => $status) {
            $this->buatPembiayaan('B'.$i, 'Murabahah', $status);
        }

        $this->assertSame(
            count($jenisKontrakBerjalan),
            (int) Pembiayaan::berjalan()
                ->where('no_pembiayaan', 'like', $this->prefix.'%')->count(),
            'Seluruh status kontrak berjalan harus terambil oleh scope berjalan().'
        );
    }

    /**
     * C. Kelima akad tampil di halaman pembiayaan pengurus, dan angka kartu
     *    konsisten dengan jumlah baris tabel.
     */
    public function test_kelima_akad_tampil_dan_kartu_konsisten_dengan_tabel(): void
    {
        $delta = $this->selisih(function () {
            foreach (self::AKAD as $i => $akad) {
                $this->buatPembiayaan('C'.$i, $akad, Pembiayaan::STATUS_AKTIF);
            }
        });

        $this->assertSame(5, $delta['badge']);
        $this->assertSame(5, $delta['tabel']);

        // Halaman tabel: kartu "Disetujui" == jumlah baris tabel (5 data uji + data dev).
        $res = $this->actingAs($this->penggunaPengurus())
            ->get(route('pengurus.pembiayaan.index', ['search' => $this->prefix]));

        $res->assertOk();

        $tabel = $res->viewData('pengajuan');
        $this->assertSame(
            5,
            $tabel->total(),
            'Kelima akad pembiayaan berjalan harus tampil di tabel pengurus.'
        );

        foreach (self::AKAD as $i => $akad) {
            $this->assertStringContainsString(
                $akad,
                substr($res->getContent(), strpos($res->getContent(), '<tbody>')),
                "Kontrak akad {$akad} tidak tampil di halaman pembiayaan pengurus."
            );
        }
    }

    /**
     * D. Invarian sinkronisasi: pengajuan Disetujui wajib punya record
     *    Pembiayaan. Regresi yang dicegah: pengajuan menggantung dengan
     *    pembiayaan_id NULL sehingga hilang dari statistik dashboard.
     */
    public function test_pengajuan_disetujui_tanpa_kontrak_terdeteksi_sebagai_belum_sinkron(): void
    {
        $delta = $this->selisih(function () {
            PengajuanPembiayaan::create([
                'no_pengajuan' => $this->prefix.'-P1',
                'anggota_id' => $this->anggotaUji(),
                'jenis_akad' => 'Murabahah',
                'jumlah_pengajuan' => 15_000_000,
                'margin_persen' => 10,
                'total_pembiayaan' => 16_500_000,
                'tenor' => 12,
                'tujuan' => 'Uji pengajuan menggantung',
                'status' => PengajuanPembiayaan::STATUS_DISETUJUI,
                'step_terakhir' => 4,
            ]);
        });

        $this->assertSame(
            1,
            $delta['belum'],
            'Pengajuan Disetujui tanpa pembiayaan_id harus terdeteksi belum tersinkron.'
        );

        // Data menggantung tidak boleh ikut menambah kartu pembiayaan aktif.
        $this->assertSame(
            0,
            $delta['badge'],
            'Pengajuan yang belum tersinkron tidak boleh dihitung sebagai pembiayaan aktif.'
        );

        // Dashboard menandai kondisi ini lewat banner validasi.
        $res = $this->actingAs($this->penggunaPengurus())->get(route('dashboard.pengurus'));
        $res->assertOk();
        $this->assertGreaterThanOrEqual(1, $res->viewData('pengajuanBelumSinkron'));
    }

    /**
     * E. Rekonsiliasi harus mengisi tautan yang hilang, membuat kontrak, dan
     *    bersifat idempoten (dipanggil dua kali tidak menggandakan kontrak).
     */
    public function test_rekonsiliasi_mengisi_tautan_dan_idempoten(): void
    {
        $pengajuan = PengajuanPembiayaan::create([
            'no_pengajuan' => $this->prefix.'-P2',
            'anggota_id' => $this->anggotaUji(),
            'jenis_akad' => 'Mudharabah',
            'jumlah_pengajuan' => 12_000_000,
            'margin_persen' => 12,
            'total_pembiayaan' => 13_440_000,
            'tenor' => 24,
            'tujuan' => 'Uji rekonsiliasi sinkronisasi',
            'status' => PengajuanPembiayaan::STATUS_DISETUJUI,
            'step_terakhir' => 4,
        ]);

        $this->assertNull(
            $pengajuan->fresh()->pembiayaan_id,
            'Prasyarat uji: pengajuan awal harus belum punya tautan pembiayaan.'
        );

        $pembiayaan = $pengajuan->sinkronkanKePembiayaan();

        $this->assertNotNull($pembiayaan, 'Sinkronisasi harus berhasil untuk pengajuan yang layak.');
        $this->assertSame(
            $pembiayaan->id,
            $pengajuan->fresh()->pembiayaan_id,
            'Kolom pembiayaan_id harus terisi setelah sinkronisasi.'
        );
        $this->assertSame(
            13_440_000.0,
            (float) $pembiayaan->total_pembiayaan,
            'Nilai kontrak harus mengikuti total pembiayaan pada pengajuan.'
        );
        // Kontrak baru menunggu pengesahan DPS — belum menanggung angsuran,
        // sehingga belum dihitung pada kartu "Pembiayaan Aktif".
        $this->assertSame(
            Pembiayaan::STATUS_PENDING_DPS,
            $pembiayaan->status_pembiayaan,
            'Kontrak hasil sinkronisasi menunggu pengesahan DPS.'
        );
        $this->assertNotContains(
            $pembiayaan->status_pembiayaan,
            Pembiayaan::statusBerjalan(),
            'Kontrak yang belum diakadkan tidak boleh dihitung sebagai pembiayaan aktif.'
        );
        $this->assertSame(
            $pembiayaan->id,
            $pembiayaan->fresh()->id,
            'Kontrak hasil sinkronisasi harus tersimpan di tabel pembiayaan.'
        );

        // Idempoten: pemanggilan kedua mengembalikan kontrak yang sama.
        $ulang = $pengajuan->fresh()->sinkronkanKePembiayaan();

        $this->assertSame(
            $pembiayaan->id,
            $ulang->id,
            'Sinkronisasi ulang tidak boleh membuat kontrak baru.'
        );
        $this->assertSame(
            1,
            (int) Pembiayaan::where('no_pembiayaan', $pembiayaan->no_pembiayaan)->count(),
            'Tidak boleh ada kontrak duplikat dengan nomor yang sama.'
        );
    }

    /**
     * F. Pengajuan yang datanya tidak lengkap tidak boleh disinkronkan,
     *    agar tidak membuat kontrak cacat di tabel pembiayaan.
     */
    public function test_pengajuan_tidak_lengkap_ditolak_saat_sinkronisasi(): void
    {
        $pengajuan = PengajuanPembiayaan::create([
            'no_pengajuan' => $this->prefix.'-P3',
            'anggota_id' => $this->anggotaUji(),
            'jenis_akad' => 'Murabahah',
            'jumlah_pengajuan' => 0,
            'tenor' => 0,
            'tujuan' => 'Uji pengajuan tidak lengkap',
            'status' => PengajuanPembiayaan::STATUS_DISETUJUI,
            'step_terakhir' => 4,
        ]);

        $this->assertNotSame(
            [],
            $pengajuan->alasanTidakLayakSinkron(),
            'Pengajuan tanpa jumlah dan tenor harus dinyatakan tidak layak disinkronkan.'
        );

        $this->assertNull(
            $pengajuan->sinkronkanKePembiayaan(),
            'Sinkronisasi harus menolak pengajuan yang datanya tidak lengkap.'
        );
        $this->assertNull(
            $pengajuan->fresh()->pembiayaan_id,
            'Tautan tidak boleh terisi bila sinkronisasi ditolak.'
        );
    }

    /**
     * G. Render penuh dashboard wajib menyertakan SELURUH variabel grafik dan
     *    metadata status.
     *
     *    Mengunci dua perbaikan audit:
     *      - P1: index() harus menggabungkan dataGrafik() dengan data(); bila
     *        wiring ini hilang, grafik donut/bar/line kehilangan datanya dan
     *        view gagal render (Undefined variable).
     *      - F2: legend & warna donut dihasilkan dinamis dari Pembiayaan::
     *        statusMeta() — tiga variabel (urutanStatus, labelStatus,
     *        warnaStatus) harus ada dan panjangnya sinkron satu sama lain.
     */
    public function test_dashboard_index_menyertakan_variabel_grafik_dan_meta_status(): void
    {
        $res = $this->actingAs($this->penggunaPengurus())->get(route('dashboard.pengurus'));

        $res->assertOk();

        $variabelGrafik = [
            'bulanLabels', 'dataPembiayaan', 'dataAngsuran',
            'hariLabels', 'dataTransaksi', 'statusPembiayaan',
            'urutanStatus', 'labelStatus', 'warnaStatus',
        ];

        $data = $res->viewData();

        foreach ($variabelGrafik as $kunci) {
            $this->assertArrayHasKey(
                $kunci,
                $data,
                "Variabel grafik '{$kunci}' harus tersedia pada render index()."
            );
        }

        // Panjang ketiga larik legend harus sinkron agar loop @forelse tidak
        // pernah kehabisan warna/label di tengah jalan (regresi F2).
        $urutan = $res->viewData('urutanStatus');
        $label = $res->viewData('labelStatus');
        $warna = json_decode($res->viewData('warnaStatus'), true);

        $this->assertCount(
            count($urutan),
            $label,
            'Jumlah label legend harus sama dengan jumlah irisan donut.'
        );
        $this->assertCount(
            count($urutan),
            $warna,
            'Jumlah warna legend harus sama dengan jumlah irisan donut.'
        );

        // Setiap status yang muncul di donut wajib punya metadata kanonik
        // (label + warna) sehingga tidak ada fallback "ucwords" yang bocor.
        $metaStatus = Pembiayaan::statusMeta();
        foreach ($urutan as $status) {
            $this->assertArrayHasKey(
                $status,
                $metaStatus,
                "Status '{$status}' tampil di donut tetapi tidak ada di statusMeta() (sumber label/warna)."
            );
        }
    }
}
