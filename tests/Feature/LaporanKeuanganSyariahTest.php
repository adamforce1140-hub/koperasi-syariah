<?php

namespace Tests\Feature;

use App\Models\Angsuran;
use App\Models\Coa;
use App\Models\Pembiayaan;
use App\Models\User;
use App\Services\AutoJournalingService;
use App\Services\DanaKebajikanService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Modul Laporan Keuangan Syariah (/dps/laporan-keuangan) — DPS.
 *
 * Cakupan pengujian:
 *   A. PSAK 107 — akad Ijarah berkategori UJROH (badge tabel 'Rincian Pendapatan
 *      per Akad' dan pengelompokan baris 'Pendapatan Ujroh / Fee'), tanpa
 *      mengubah kategori akad lain dan tanpa mengubah TOTAL pendapatan.
 *   B. Neraca Pembiayaan hanya memuat status aktif/restrukturisasi/lunas —
 *      'ditolak' & 'pengajuan' dikecualikan dari seluruh perhitungan
 *      (termasuk % tertagih negatif), dan TOTAL DANA DISALURKAN = jumlah plafon
 *      data yang benar-benar ditampilkan.
 *   C. Box 'Dana Kebajikan (Ta'zir/Denda)' terhubung real-time ke akumulasi
 *      mutasi KREDIT akun 2-3001 melalui endpoint JSON + polling.
 *
 * Catatan: nilai absolut tidak dijadikan patokan karena basis data dev juga
 * berisi data nyata; pengujian memakai SELISIH (delta) agar tetap deterministik.
 */
class LaporanKeuanganSyariahTest extends TestCase
{
    use DatabaseTransactions;

    /** Status pembiayaan yang sah masuk neraca (PSAK 107). */
    private const STATUS_NERACA = [
        Pembiayaan::STATUS_AKTIF,
        Pembiayaan::STATUS_RESTRUKTURISASI,
        Pembiayaan::STATUS_LUNAS,
    ];

    /** Prefix unik agar data uji mudah diisolasi dari data dev. */
    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'LKPSAK'.strtoupper(substr(uniqid(), -6));
    }

    private function penggunaDps(string $suffix = ''): User
    {
        return User::firstOrCreate(
            ['email' => 'dps.laporankeuangan'.$suffix.'@test.local'],
            [
                'name' => 'DPS Laporan Keuangan',
                'password' => Hash::make('password'),
                'role' => 'dps',
                'is_active' => true,
            ]
        );
    }

    private function teller(): User
    {
        return User::firstOrCreate(
            ['email' => 'teller.laporankeuangan@test.local'],
            [
                'name' => 'Teller Laporan Keuangan',
                'password' => Hash::make('password'),
                'role' => 'teller',
                'is_active' => true,
            ]
        );
    }

    private function anggotaId(): int
    {
        $id = DB::table('anggota')->value('id');

        if (! $id) {
            $id = DB::table('anggota')->insertGetId([
                'nama_lengkap' => 'Anggota Uji Laporan Keuangan',
                'no_anggota' => 'AGT-LK-'.strtoupper(substr(uniqid(), -6)),
            ]);
        }

        return (int) $id;
    }

    /** Pembiayaan uji dengan nomor ber-prefix agar terisolasi. */
    private function buatPembiayaan(string $suffix, string $akad, string $status, float $plafon, float $sisa): Pembiayaan
    {
        return Pembiayaan::create([
            'no_pembiayaan' => $this->prefix.'-'.$suffix,
            'anggota_id' => $this->anggotaId(),
            'akun_id' => Coa::query()->value('id') ?? 1,
            'akad_pembiayaan' => $akad,
            'tujuan_pembiayaan' => 'Uji Laporan Keuangan Syariah',
            'jumlah_pembiayaan' => $plafon,
            'total_pembiayaan' => $plafon,
            'sisa_pembiayaan' => $sisa,
            'margin_nisbah' => 12.00,
            'total_ujroh' => 0,
            'tenor' => 12,
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addYear()->toDateString(),
            'frekuensi_angsuran' => 'Bulanan',
            'jumlah_angsuran' => round($plafon / 12, 2),
            'status_pembiayaan' => $status,
        ]);
    }

    /** Angsuran berstatus 'Sudah Dibayar' — sumber pendapatan margin/ujroh. */
    private function buatAngsuran(Pembiayaan $pembiayaan, float $margin, float $pokok): Angsuran
    {
        $ke = (int) Angsuran::where('pembiayaan_id', $pembiayaan->id)->max('angsuran_ke') + 1;

        return Angsuran::create([
            'pembiayaan_id' => $pembiayaan->id,
            'angsuran_ke' => $ke,
            'tanggal_jatuh_tempo' => now()->toDateString(),
            'tanggal_bayar' => now()->toDateString(),
            'pokok_angsuran' => $pokok,
            'margin_ujroh' => $margin,
            'jumlah_angsuran' => $margin + $pokok,
            'total_bayar' => $margin + $pokok,
            'sisa_pokok' => 0,
            'status_angsuran' => 'Sudah Dibayar',
            'user_id' => $this->teller()->id,
        ]);
    }

    /** GET halaman laporan keuangan sebagai user DPS. */
    private function bukaHalaman()
    {
        return $this->actingAs($this->penggunaDps())->get(route('dps.laporan-keuangan'));
    }

    /** Total pendapatan (margin + bagi hasil + ujroh) dari data view. */
    private function totalPendapatan($respon): float
    {
        return (float) $respon->viewData('pendapatanMargin')
            + (float) $respon->viewData('pendapatanBagiHasil')
            + (float) $respon->viewData('pendapatanUjroh');
    }

    /** Helper: ekstrak blok <tbody> tabel yang ditandai judul tertentu. */
    private function tbodySetelah(string $html, string $penanda): string
    {
        $pos = strpos($html, $penanda);
        $this->assertNotFalse($pos, "Penanda '{$penanda}' tidak ditemukan di halaman.");

        $awal = strpos($html, '<tbody>', $pos);
        $this->assertNotFalse($awal, "Tabel setelah '{$penanda}' tidak memiliki <tbody>.");

        $akhir = strpos($html, '</tbody>', $awal);

        return substr($html, $awal, $akhir - $awal);
    }

    /** Helper: ambil blok <tr> milik satu akad tertentu. */
    private function barisAkad(string $html, string $akad): ?string
    {
        if (! preg_match_all('/<tr\b.*?<\/tr>/s', $html, $m)) {
            return null;
        }

        foreach ($m[0] as $tr) {
            if (str_contains($tr, '<strong>'.$akad.'</strong>')) {
                return $tr;
            }
        }

        return null;
    }

    /** Helper: badge kategori terakhir (span) pada satu baris akad. */
    private function badgeKategori(string $baris): ?string
    {
        if (! preg_match_all('/<span[^>]*>\s*([A-Za-z \/\-]+?)\s*<\/span>/', $baris, $m)) {
            return null;
        }

        return trim(end($m[1]));
    }

    /* =====================================================================
     | A. PSAK 107 — KATEGORI & PENGELOMPOKAN AKAD IJARAH
     ===================================================================== */

    public function test_badge_kategori_akad_ijarah_adalah_ujroh_bukan_margin(): void
    {
        $ijarah = $this->buatPembiayaan('IJARAH', 'Ijarah', Pembiayaan::STATUS_AKTIF, 10_000_000, 5_000_000);
        $this->buatAngsuran($ijarah, 100_000, 500_000);

        $html = $this->bukaHalaman()->assertOk()->getContent();
        $tbody = $this->tbodySetelah($html, 'Rincian Pendapatan per Akad');

        $baris = $this->barisAkad($tbody, 'Ijarah');

        $this->assertNotNull($baris, 'Baris akad Ijarah harus tampil di tabel Rincian Pendapatan per Akad.');
        $this->assertSame('Ujroh', $this->badgeKategori($baris), 'PSAK 107: badge kategori akad Ijarah wajib "Ujroh", bukan "Margin".');
    }

    public function test_kategori_akad_lain_tidak_ikut_berubah(): void
    {
        $murabahah = $this->buatPembiayaan('MURAB', 'Murabahah', Pembiayaan::STATUS_AKTIF, 10_000_000, 5_000_000);
        $this->buatAngsuran($murabahah, 200_000, 500_000);

        $mudharabah = $this->buatPembiayaan('MUDHA', 'Mudharabah', Pembiayaan::STATUS_AKTIF, 10_000_000, 5_000_000);
        $this->buatAngsuran($mudharabah, 150_000, 500_000);

        $ijarah = $this->buatPembiayaan('IJARAH2', 'Ijarah', Pembiayaan::STATUS_AKTIF, 10_000_000, 5_000_000);
        $this->buatAngsuran($ijarah, 100_000, 500_000);

        $html = $this->bukaHalaman()->assertOk()->getContent();
        $tbody = $this->tbodySetelah($html, 'Rincian Pendapatan per Akad');

        $this->assertSame('Margin', $this->badgeKategori($this->barisAkad($tbody, 'Murabahah')), 'Akad Murabahah tetap berkategori Margin.');
        $this->assertSame('Bagi Hasil', $this->badgeKategori($this->barisAkad($tbody, 'Mudharabah')), 'Akad Mudharabah tetap berkategori Bagi Hasil.');
        $this->assertSame('Ujroh', $this->badgeKategori($this->barisAkad($tbody, 'Ijarah')), 'Hanya akad Ijarah yang berubah menjadi Ujroh.');
    }

    public function test_pendapatan_ijarah_dipisahkan_dari_margin_dan_total_tetap_konsisten(): void
    {
        $sebelum = $this->bukaHalaman()->assertOk();

        // Satu angsuran Murabahah (margin) + satu angsuran Ijarah (ujroh).
        $murabahah = $this->buatPembiayaan('MURAB2', 'Murabahah', Pembiayaan::STATUS_AKTIF, 10_000_000, 5_000_000);
        $this->buatAngsuran($murabahah, 400_000, 500_000);

        $ijarah = $this->buatPembiayaan('IJARAH3', 'Ijarah', Pembiayaan::STATUS_AKTIF, 10_000_000, 5_000_000);
        $this->buatAngsuran($ijarah, 300_000, 500_000);

        $sesudah = $this->bukaHalaman()->assertOk();

        $deltaMargin = round((float) $sesudah->viewData('pendapatanMargin') - (float) $sebelum->viewData('pendapatanMargin'), 2);
        $deltaUjroh = round((float) $sesudah->viewData('pendapatanUjroh') - (float) $sebelum->viewData('pendapatanUjroh'), 2);

        // Margin hanya bertambah dari Murabahah (400.000) — Ijarah TIDAK ikut.
        $this->assertSame(
            400_000.0,
            $deltaMargin,
            'Pendapatan Margin wajib bersih dari akad Ijarah (PSAK 107): hanya margin Murabahah yang boleh masuk.'
        );

        // Nilai Ijarah berpindah (bukan hilang) ke baris Ujroh/Fee.
        $this->assertSame(
            300_000.0,
            $deltaUjroh,
            'Margin akad Ijarah wajib berpindah ke agregasi Pendapatan Ujroh / Fee.'
        );

        // Pemisahan tidak mengubah TOTAL: hanya perpindahan baris.
        $this->assertSame(
            700_000.0,
            round($this->totalPendapatan($sesudah) - $this->totalPendapatan($sebelum), 2),
            'Total pendapatan harus tetap konsisten setelah pemisahan akad Ijarah (400.000 + 300.000).'
        );
    }

    /* =====================================================================
     | B. NERACA PEMBIAYAAN — FILTER STATUS & TOTAL DANA DISALURKAN
     ===================================================================== */

    public function test_neraca_hanya_memuat_status_valid_dan_mengecualikan_ditolak_pengajuan(): void
    {
        $this->buatPembiayaan('AKTIF', 'Murabahah', Pembiayaan::STATUS_AKTIF, 10_000_000, 6_000_000);
        $this->buatPembiayaan('LUNAS', 'Murabahah', Pembiayaan::STATUS_LUNAS, 8_000_000, 0);
        $this->buatPembiayaan('RESTRUK', 'Murabahah', Pembiayaan::STATUS_RESTRUKTURISASI, 5_000_000, 4_000_000);
        $this->buatPembiayaan('DITOLAK', 'Murabahah', Pembiayaan::STATUS_DITOLAK, 15_000_000, 17_700_000);
        $this->buatPembiayaan('PENGAJUAN', 'Murabahah', Pembiayaan::STATUS_PENDING_AO, 12_000_000, 9_000_000);

        $respon = $this->bukaHalaman()->assertOk();
        $status = $respon->viewData('pembiayaanPerStatus')->pluck('status_pembiayaan')->all();

        // Seluruh baris yang dirender wajib berstatus sah.
        foreach ($status as $s) {
            $this->assertContains(
                Pembiayaan::normalizeStatus($s),
                self::STATUS_NERACA,
                "Status '{$s}' tidak boleh muncul di Neraca Pembiayaan."
            );
        }

        // Fixture berstatus sah harus muncul; yang tidak sah harus hilang.
        $this->assertContains('lunas', $status, 'Status lunas wajib tampil di neraca.');
        $this->assertNotContains(Pembiayaan::STATUS_DITOLAK, $status, "Status 'ditolak' wajib dikecualikan dari neraca.");
        $this->assertNotContains(Pembiayaan::STATUS_PENDING_AO, $status, "Status 'pengajuan' (pending_ao) wajib dikecualikan dari neraca.");

        // Persentase tertagih tidak boleh negatif untuk data yang ditampilkan.
        foreach ($respon->viewData('pembiayaanPerStatus') as $row) {
            $tertagih = $row->total_plafon > 0
                ? (($row->total_plafon - $row->total_sisa) / $row->total_plafon) * 100
                : 0;

            $this->assertGreaterThanOrEqual(
                0,
                $tertagih,
                "Persentase tertagih status {$row->status_pembiayaan} tidak boleh negatif."
            );
        }

        $tbodyNeraca = $this->tbodySetelah($respon->getContent(), 'Neraca Pembiayaan (Posisi Piutang/Aset)');

        $this->assertStringNotContainsString('ditolak', $tbodyNeraca, "Status 'ditolak' harus dikecualikan dari tabel neraca.");
        $this->assertStringNotContainsString('pengajuan', $tbodyNeraca, "Status 'pengajuan' harus dikecualikan dari tabel neraca.");
        $this->assertStringNotContainsString('pending_ao', $tbodyNeraca, "Status 'pending_ao' harus dikecualikan dari tabel neraca.");
        $this->assertStringNotContainsString('-18.0', $tbodyNeraca, 'Persentase tertagih negatif tidak boleh tampil di neraca.');
    }

    public function test_total_dana_disalurkan_hanya_menjumlahkan_plafon_status_valid(): void
    {
        $sebelum = round((float) $this->bukaHalaman()->assertOk()->viewData('totalDisalurkan'), 2);

        // Status sah: 10jt + 5jt + 8jt = 23jt. Status tidak sah: 15jt + 12jt.
        $this->buatPembiayaan('TD1', 'Murabahah', Pembiayaan::STATUS_AKTIF, 10_000_000, 6_000_000);
        $this->buatPembiayaan('TD2', 'Murabahah', Pembiayaan::STATUS_RESTRUKTURISASI, 5_000_000, 4_000_000);
        $this->buatPembiayaan('TD3', 'Murabahah', Pembiayaan::STATUS_LUNAS, 8_000_000, 0);
        $this->buatPembiayaan('TD4', 'Murabahah', Pembiayaan::STATUS_DITOLAK, 15_000_000, 17_700_000);
        $this->buatPembiayaan('TD5', 'Murabahah', Pembiayaan::STATUS_PENDING_AO, 12_000_000, 9_000_000);

        $respon = $this->bukaHalaman()->assertOk();
        $sesudah = round((float) $respon->viewData('totalDisalurkan'), 2);

        // Penjumlahan manual dari baris yang benar-benar dirender di tabel neraca.
        $manual = round((float) $respon->viewData('pembiayaanPerStatus')->sum('total_plafon'), 2);

        $this->assertSame(
            round($sebelum + 23_000_000, 2),
            $sesudah,
            'TOTAL DANA DISALURKAN hanya boleh bertambah dari plafon status sah (23jt), bukan dari ditolak/pengajuan.'
        );

        $this->assertSame(
            $manual,
            $sesudah,
            'TOTAL DANA DISALURKAN harus sama dengan penjumlahan plafon baris neraca yang ditampilkan.'
        );

        $this->assertSame(
            round((float) Pembiayaan::statusIn(self::STATUS_NERACA)->sum('jumlah_pembiayaan'), 2),
            $sesudah,
            'TOTAL DANA DISALURKAN tidak boleh memuat plafon status ditolak/pengajuan.'
        );
    }

    /* =====================================================================
     | C. REAL-TIME BOX 'DANA KEBAJIKAN (TA'ZIR/DENDA)' — AKUN 2-3001
     ===================================================================== */

    public function test_box_dana_kebajikan_terhubung_endpoint_real_time(): void
    {
        $html = $this->bukaHalaman()->assertOk()->getContent();

        $this->assertStringContainsString('id="box-dana-kebajikan"', $html, 'Box Dana Kebajikan harus punya id untuk pembaruan real-time.');
        $this->assertStringContainsString('data-field="dana-kebajikan"', $html, 'Nilai box harus punya penanda data-field.');
        $this->assertMatchesRegularExpression(
            '/data-refresh-url="[^"]*laporan-keuangan\/dana-kebajikan"/',
            $html,
            'Box harus menunjuk ke endpoint JSON dana kebajikan.'
        );
        $this->assertStringContainsString(
            'setInterval(segarkanDanaKebajikan, 300000)',
            $html,
            'Box harus memiliki mekanisme refresh otomatis (polling 5 menit).'
        );
    }

    public function test_nilai_box_dana_kebajikan_sama_dengan_akumulasi_kredit_akun_2_3001(): void
    {
        $coa = Coa::findByKode(AutoJournalingService::DANA_KEBAJIKAN);

        if (! $coa) {
            $this->markTestSkipped('CoA 2-3001 (Dana Kebajikan) belum ter-seed.');
        }

        $terkumpulAkun = (float) DB::table('jurnal_detail')->where('coa_id', $coa->id)->sum('kredit');

        $respon = $this->bukaHalaman()->assertOk();

        $this->assertSame(
            round($terkumpulAkun, 2),
            round((float) $respon->viewData('danaKebajikan'), 2),
            'Nilai box Dana Kebajikan wajib = total akumulasi mutasi KREDIT akun 2-3001.'
        );
    }

    public function test_endpoint_real_time_naik_saat_mutasi_kredit_baru_ditambahkan(): void
    {
        $coa = Coa::findByKode(AutoJournalingService::DANA_KEBAJIKAN);

        if (! $coa) {
            $this->markTestSkipped('CoA 2-3001 (Dana Kebajikan) belum ter-seed.');
        }

        $dps = $this->penggunaDps();
        $endpoint = route('dps.laporan-keuangan.dana-kebajikan');

        $sebelum = $this->actingAs($dps)->getJson($endpoint)->assertOk()->json();

        // Mutasi KREDIT baru pada akun 2-3001 (ta'zir/denda tunai).
        $jurnal = app(AutoJournalingService::class)
            ->jurnalTazir(125_000, "Ta'zir uji real-time laporan keuangan", $this->teller()->id);

        $this->assertNotNull($jurnal, "Jurnal ta'zir harus berhasil diposting.");

        $sesudah = $this->actingAs($dps)->getJson($endpoint)->assertOk()->json();

        $this->assertSame(
            round((float) $sebelum['totalKebajikan'] + 125_000, 2),
            round((float) $sesudah['totalKebajikan'], 2),
            'Nilai box Dana Kebajikan wajib langsung naik sebesar mutasi KREDIT baru pada akun 2-3001.'
        );

        $this->assertGreaterThan(
            (int) $sebelum['jumlahMutasi'],
            (int) $sesudah['jumlahMutasi'],
            'Jumlah mutasi akun 2-3001 wajib bertambah agar polling frontend mendeteksi perubahan.'
        );

        // Nilai pada halaman HTML juga harus mencerminkan akumulasi terbaru.
        $html = $this->bukaHalaman()->assertOk()->getContent();

        $this->assertStringContainsString(
            'Rp '.number_format((float) $sesudah['totalKebajikan'], 0, ',', '.'),
            $html,
            'Halaman laporan keuangan harus menampilkan akumulasi dana kebajikan terbaru.'
        );
    }

    public function test_endpoint_real_time_konsisten_dengan_service_dana_kebajikan(): void
    {
        $harapan = app(DanaKebajikanService::class)->ringkasan();

        $respon = $this->actingAs($this->penggunaDps())->getJson(route('dps.laporan-keuangan.dana-kebajikan'));

        $respon->assertOk()->assertJson([
            'totalKebajikan' => $harapan['totalTerkumpul'],
            'jumlahMutasi' => $harapan['jumlahMutasi'],
        ]);
    }
}
