<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\KebijakanKoperasi;
use App\Models\MandatorySavingsBill;
use App\Models\ShuDistribution;
use App\Models\ShuPeriod;
use App\Models\User;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Modul Perhitungan & Distribusi SHU:
 *  (A) Ringkasan nominal per pos saat input — kalkulasi real-time & format rupiah.
 *  (B) Aksi Detail pada tabel riwayat — modal rincian SHU per anggota.
 */
class ShuModalDetailTest extends TestCase
{
    use DatabaseTransactions;

    private function pengurus(): User
    {
        return User::firstOrCreate(
            ['email' => 'pengurus.shumodal@test.local'],
            [
                'name' => 'Pengurus SHU Modal',
                'password' => Hash::make('password'),
                'role' => 'pengurus',
            ]
        );
    }

    /**
     * Bangun periode SHU + sejumlah baris distribusi per anggota.
     *
     * @param  array<int, array{nama:string, simpanan:float, margin:float, modal:float, trx:float}>  $baris
     */
    private function buatPeriode(array $baris, array $atribut = []): ShuPeriod
    {
        $shu = ShuPeriod::create(array_merge([
            'tahun_buku' => 'SHU-T'.now()->format('His').rand(10, 99),
            'total_shu_bersih' => 100000000,
            'persen_cadangan' => 25,
            'persen_jasa_modal' => 40,
            'persen_jasa_transaksi' => 20,
            'persen_pengurus' => 10,
            'persen_sosial' => 5,
            'nominal_cadangan' => 25000000,
            'nominal_jasa_modal' => 40000000,
            'nominal_jasa_transaksi' => 20000000,
            'nominal_pengurus' => 10000000,
            'nominal_sosial' => 5000000,
            'status' => 'Draft',
            'is_distributed' => false,
            'user_id' => $this->pengurus()->id,
        ], $atribut));

        foreach ($baris as $b) {
            // Pakai anggota yang benar-benar ada agar relasi nama dapat diuji.
            // Kolom wajib pada tabel `anggota` (NOT NULL): nik (unique),
            // jenis_kelamin, alamat, kota, provinsi.
            $anggota = Anggota::create([
                'no_anggota' => 'T'.rand(100000, 999999),
                'nik' => str_pad((string) rand(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
                'nama_lengkap' => $b['nama'],
                'jenis_kelamin' => 'Laki-laki',
                'alamat' => 'Jl. Pengujian SHU No. 1',
                'kota' => 'Bandung',
                'provinsi' => 'Jawa Barat',
                'status_anggota' => 'Aktif',
                'tanggal_gabung' => now()->startOfYear()->toDateString(),
            ]);

            ShuDistribution::create([
                'shu_period_id' => $shu->id,
                'anggota_id' => $anggota->id,
                'total_simpanan_modal' => $b['simpanan'],
                'total_transaksi_bagi_hasil' => $b['margin'],
                'shu_jasa_modal' => $b['modal'],
                'shu_jasa_transaksi' => $b['trx'],
                'total_shu' => $b['modal'] + $b['trx'],
            ]);
        }

        return $shu->fresh();
    }

    // ══════════════════════════════════════════════════════════
    //  BAGIAN B — Endpoint rincian per anggota (data modal Detail)
    // ══════════════════════════════════════════════════════════

    /** Endpoint mengembalikan JSON rincian yang sesuai dengan data periode terpilih. */
    public function test_endpoint_detail_mengembalikan_rincian_sesuai_periode(): void
    {
        $shu = $this->buatPeriode([
            ['nama' => 'Ahmad Fauzi',   'simpanan' => 5000000, 'margin' => 1200000, 'modal' => 2000000, 'trx' => 600000],
            ['nama' => 'Siti Aminah',   'simpanan' => 3000000, 'margin' => 800000,  'modal' => 1200000, 'trx' => 400000],
        ]);

        $res = $this->actingAs($this->pengurus())
            ->getJson(route('pengurus.shu.detail-anggota', $shu->id));

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('periode.id', $shu->id)
            ->assertJsonPath('periode.jumlah_anggota', 2)
            ->assertJsonCount(2, 'data');

        // Total SHU per anggota = modal + transaksi
        $data = collect($res->json('data'));
        $ahmad = $data->firstWhere('nama_anggota', 'Ahmad Fauzi');

        $this->assertNotNull($ahmad, 'Anggota Ahmad Fauzi harus ada di rincian.');
        $this->assertEquals(2000000.0, $ahmad['raw']['shu_modal']);
        $this->assertEquals(600000.0, $ahmad['raw']['shu_transaksi']);
        $this->assertEquals(2600000.0, $ahmad['raw']['total_shu']);
    }

    /** Data modal Detail harus terpisah per periode (tidak tercampur periode lain). */
    public function test_detail_tidak_mencampur_data_periode_lain(): void
    {
        $shuA = $this->buatPeriode([
            ['nama' => 'Anggota Periode A', 'simpanan' => 1000000, 'margin' => 100000, 'modal' => 200000, 'trx' => 50000],
        ]);
        $this->buatPeriode([
            ['nama' => 'Anggota Periode B', 'simpanan' => 9000000, 'margin' => 900000, 'modal' => 800000, 'trx' => 400000],
        ]);

        $res = $this->actingAs($this->pengurus())
            ->getJson(route('pengurus.shu.detail-anggota', $shuA->id));

        $res->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Anggota Periode A', $res->json('data.0.nama_anggota'));
        $this->assertSame(
            'Anggota Periode A',
            collect($res->json('data'))->pluck('nama_anggota')->first()
        );
        $this->assertNotContains(
            'Anggota Periode B',
            collect($res->json('data'))->pluck('nama_anggota')->all()
        );
    }

    /** Nilai nominal diformat Rupiah dengan pemisah ribuan dan 2 desimal. */
    public function test_nominal_diformat_rupiah_dengan_benar(): void
    {
        $shu = $this->buatPeriode([
            ['nama' => 'Budi Santoso', 'simpanan' => 12500000, 'margin' => 3456789.5, 'modal' => 2500000, 'trx' => 1250000.75],
        ]);

        $res = $this->actingAs($this->pengurus())
            ->getJson(route('pengurus.shu.detail-anggota', $shu->id));

        $res->assertOk();

        $this->assertSame('Rp 12.500.000,00', $res->json('data.0.simpanan'));
        $this->assertSame('Rp 3.456.789,50', $res->json('data.0.margin'));
        $this->assertSame('Rp 2.500.000,00', $res->json('data.0.shu_modal'));
        $this->assertSame('Rp 1.250.000,75', $res->json('data.0.shu_transaksi'));
        $this->assertSame('Rp 3.750.000,75', $res->json('data.0.total_shu'));

        // Total periode juga terformat
        $this->assertSame('Rp 100.000.000,00', $res->json('periode.total_shu_bersih'));
        $this->assertSame('Rp 40.000.000,00', $res->json('periode.total_jasa_modal'));
        $this->assertSame('Rp 20.000.000,00', $res->json('periode.total_jasa_transaksi'));
    }

    /** Default urutan dari backend adalah berdasarkan nama anggota (A→Z, case-insensitive). */
    public function test_data_terurut_berdasarkan_nama_anggota_secara_default(): void
    {
        $shu = $this->buatPeriode([
            ['nama' => 'Zulkifli',    'simpanan' => 1000000, 'margin' => 100000, 'modal' => 100000, 'trx' => 50000],
            ['nama' => 'ahmad kecil', 'simpanan' => 1000000, 'margin' => 100000, 'modal' => 100000, 'trx' => 50000],
            ['nama' => 'Budi',        'simpanan' => 1000000, 'margin' => 100000, 'modal' => 100000, 'trx' => 50000],
        ]);

        $res = $this->actingAs($this->pengurus())
            ->getJson(route('pengurus.shu.detail-anggota', $shu->id));

        $nama = collect($res->json('data'))->pluck('nama_anggota')->all();

        // Urut abjad, huruf kecil diperlakukan sama dengan huruf besar
        $this->assertSame(['ahmad kecil', 'Budi', 'Zulkifli'], $nama);
    }

    /** Periode yang tidak ada ditolak dengan 404 dan pesan yang jelas. */
    public function test_detail_periode_tidak_ditemukan_mengembalikan_404(): void
    {
        $res = $this->actingAs($this->pengurus())
            ->getJson(route('pengurus.shu.detail-anggota', 999999));

        $res->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    /** Periode draf tanpa distribusi mengembalikan daftar kosong, bukan error. */
    public function test_periode_tanpa_distribusi_mengembalikan_daftar_kosong(): void
    {
        $shu = $this->buatPeriode([]);

        $res = $this->actingAs($this->pengurus())
            ->getJson(route('pengurus.shu.detail-anggota', $shu->id));

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('periode.jumlah_anggota', 0)
            ->assertJsonCount(0, 'data');
    }

    /**
     * Jumlah seluruh SHU anggota pada endpoint sama dengan alokasi
     * jasa modal + jasa transaksi periode (konsistensi dengan jurnal).
     */
    public function test_jumlah_shu_anggota_konsisten_dengan_alokasi_periode(): void
    {
        $shu = $this->buatPeriode([
            ['nama' => 'Anggota Satu',  'simpanan' => 6000000, 'margin' => 2000000, 'modal' => 24000000, 'trx' => 12000000],
            ['nama' => 'Anggota Dua',   'simpanan' => 4000000, 'margin' => 1000000, 'modal' => 16000000, 'trx' => 8000000],
        ]);

        $res = $this->actingAs($this->pengurus())
            ->getJson(route('pengurus.shu.detail-anggota', $shu->id));

        $jumlahModal = collect($res->json('data'))->sum(fn ($r) => $r['raw']['shu_modal']);
        $jumlahTrx = collect($res->json('data'))->sum(fn ($r) => $r['raw']['shu_transaksi']);

        $this->assertEquals((float) $shu->nominal_jasa_modal, $jumlahModal);
        $this->assertEquals((float) $shu->nominal_jasa_transaksi, $jumlahTrx);
    }

    // ══════════════════════════════════════════════════════════
    //  BAGIAN A — Elemen kalkulasi real-time & modal di halaman indeks
    // ══════════════════════════════════════════════════════════

    /** Halaman indeks memuat modal Detail beserta elemen kontrolnya. */
    public function test_halaman_indeks_memuat_modal_detail(): void
    {
        $shu = $this->buatPeriode([
            ['nama' => 'Anggota Modal', 'simpanan' => 1000000, 'margin' => 100000, 'modal' => 200000, 'trx' => 50000],
        ]);

        $res = $this->actingAs($this->pengurus())->get(route('pengurus.shu.index'));

        $res->assertOk();
        // Wadah modal + kontrol tutup/cari
        $res->assertSee('id="modalDetailShu"', false);
        $res->assertSee('id="modalDetailTutup"', false);
        $res->assertSee('id="modalDetailCari"', false);
        $res->assertSee('id="modalDetailBody"', false);
        $res->assertSee('id="modalDetailJudul"', false);
        // Tombol Detail memicu modal dan membawa URL rincian periode ini
        $res->assertSee('bukaModalDetail(this)', false);
        $res->assertSee(
            route('pengurus.shu.detail-anggota', $shu->id),
            false
        );
    }

    /** Tombol Detail TIDAK lagi berpindah halaman (aksi modal, bukan navigasi). */
    public function test_tombol_detail_membuka_modal_bukan_pindah_halaman(): void
    {
        $this->buatPeriode([
            ['nama' => 'Anggota Tombol', 'simpanan' => 1000000, 'margin' => 100000, 'modal' => 200000, 'trx' => 50000],
        ]);

        $html = $this->actingAs($this->pengurus())
            ->get(route('pengurus.shu.index'))
            ->getContent();

        // Tombol Detail harus <button>, dipicu handler modal
        $this->assertMatchesRegularExpression(
            '/<button[^>]*onclick="bukaModalDetail\(this\)"[^>]*>/',
            $html,
            'Tombol Detail harus berupa button dengan handler bukaModalDetail.'
        );
        // Tidak boleh ada lagi anchor Detail lama yang langsung ke halaman show
        $this->assertStringNotContainsString('Lihat Detail">&#128065; Detail</a>', $html);
    }

    /** Modal menampilkan seluruh kolom yang diminta. */
    public function test_modal_menampilkan_seluruh_kolom_rincian(): void
    {
        $this->buatPeriode([
            ['nama' => 'Anggota Kolom', 'simpanan' => 1000000, 'margin' => 100000, 'modal' => 200000, 'trx' => 50000],
        ]);

        $res = $this->actingAs($this->pengurus())->get(route('pengurus.shu.index'));
        $res->assertOk();

        foreach ([
            'Nama Anggota',
            'Simpanan',
            'Margin Transaksi',
            'Dapat SHU Modal',
            'Dapat SHU Transaksi',
            'Total SHU Diterima',
        ] as $kolom) {
            $res->assertSee($kolom, false);
        }
    }

    /** Modal mendukung pencarian & pengurutan di sisi klien. */
    public function test_modal_mendukung_pencarian_dan_pengurutan(): void
    {
        $this->buatPeriode([
            ['nama' => 'Anggota Cari', 'simpanan' => 1000000, 'margin' => 100000, 'modal' => 200000, 'trx' => 50000],
        ]);

        $html = $this->actingAs($this->pengurus())
            ->get(route('pengurus.shu.index'))
            ->getContent();

        // Fungsi pencarian & pengurutan tersedia
        $this->assertStringContainsString('function barisTersaring()', $html);
        $this->assertStringContainsString('function urutkanDetail(kunci)', $html);
        $this->assertStringContainsString('function renderTabelDetail()', $html);
        // Default pengurutan: nama anggota, menaik
        $this->assertStringContainsString("kunciUrut: 'nama_anggota'", $html);
        $this->assertStringContainsString("arahUrut: 'asc'", $html);
        // Pencarian mencocokkan nama maupun nomor anggota
        $this->assertStringContainsString('nama_anggota', $html);
        $this->assertStringContainsString('no_anggota', $html);
    }

    /** Modal dapat ditutup dengan klik di luar area dan tombol Escape. */
    public function test_modal_dapat_ditutup_dengan_klik_luar_dan_escape(): void
    {
        $this->buatPeriode([
            ['nama' => 'Anggota Tutup', 'simpanan' => 1000000, 'margin' => 100000, 'modal' => 200000, 'trx' => 50000],
        ]);

        $html = $this->actingAs($this->pengurus())
            ->get(route('pengurus.shu.index'))
            ->getContent();

        // Klik pada backdrop (bukan kartu) menutup modal
        $this->assertStringContainsString('if (e.target === modalDetail) tutupModalDetail();', $html);
        // Tombol silang
        $this->assertStringContainsString("getElementById('modalDetailTutup').addEventListener('click', tutupModalDetail)", $html);
        // Tombol Escape
        $this->assertStringContainsString("e.key === 'Escape'", $html);
        $this->assertStringContainsString('tutupModalDetail()', $html);
    }

    /** Modal responsif: ada aturan CSS untuk layar kecil. */
    public function test_modal_responsif_untuk_layar_kecil(): void
    {
        $this->buatPeriode([
            ['nama' => 'Anggota Responsif', 'simpanan' => 1000000, 'margin' => 100000, 'modal' => 200000, 'trx' => 50000],
        ]);

        $html = $this->actingAs($this->pengurus())
            ->get(route('pengurus.shu.index'))
            ->getContent();

        $this->assertStringContainsString('@media (max-width: 720px)', $html);
        $this->assertStringContainsString('.shu-modal-backdrop', $html);
        $this->assertStringContainsString('kol-opsional', $html);
        // Modal dibatasi tinggi viewport agar tetap muat di layar kecil
        $this->assertStringContainsString('max-height: 94vh', $html);
    }

    // ══════════════════════════════════════════════════════════
    //  BAGIAN A — Kalkulasi real-time & format rupiah di sisi klien
    // ══════════════════════════════════════════════════════════

    /** JS menyediakan perhitungan nominal per pos & format rupiah konsisten. */
    public function test_js_kalkulasi_real_time_dan_format_rupiah(): void
    {
        $html = $this->actingAs($this->pengurus())
            ->get(route('pengurus.shu.index'))
            ->getContent();

        // Rumus: Nominal Pos = Total SHU × (Persen Pos / 100)
        $this->assertStringContainsString('total * persen / 100', $html);
        $this->assertStringContainsString('function hitungPreview()', $html);
        // Format rupiah id-ID dengan pemisah ribuan
        $this->assertStringContainsString("toLocaleString('id-ID'", $html);
        // Perlindungan terhadap input non-numerik (tidak menghasilkan NaN)
        $this->assertStringContainsString('function angkaDari(', $html);
        $this->assertStringContainsString('function bersihkanInputNumerik(', $html);
        $this->assertStringContainsString('isFinite(n) ? n : 0', $html);
        // Helper format tidak boleh lagi menghasilkan "Rp NaN"
        $this->assertStringContainsString('if (!isFinite(angka)', $html);
    }

    /** Setiap pos alokasi punya elemen nominal inline dan di ringkasan. */
    public function test_setiap_pos_alokasi_punya_target_nominal(): void
    {
        $html = $this->actingAs($this->pengurus())
            ->get(route('pengurus.shu.index'))
            ->getContent();

        foreach (['cadangan', 'jasa_modal', 'jasa_transaksi', 'pengurus', 'sosial'] as $pos) {
            // Target inline di bawah input
            $this->assertStringContainsString('id="sum_'.$pos.'_inline"', $html, "Target inline pos {$pos} tidak ada.");
            // Target di ringkasan box
            $this->assertStringContainsString('id="sum_'.$pos.'"', $html, "Target ringkasan pos {$pos} tidak ada.");
        }
    }

    /** Refresh saat input dibersihkan harus mengembalikan tampilan ke Rp 0. */
    public function test_js_menangani_input_kosong_dengan_aman(): void
    {
        $html = $this->actingAs($this->pengurus())
            ->get(route('pengurus.shu.index'))
            ->getContent();

        // formatRp(0) harus 'Rp 0' dan input kosong tidak menjadi NaN
        $this->assertStringContainsString("return 'Rp 0';", $html);
        $this->assertStringContainsString('bersihkanInputNumerik(this, false)', $html);
        $this->assertStringContainsString('bersihkanInputNumerik(this, true)', $html);
    }

    // ══════════════════════════════════════════════════════════
    //  BAGIAN C — Notifikasi penahanan SHU (HOLD) pada halaman modul SHU
    // ══════════════════════════════════════════════════════════

    /**
     * Buat anggota + tagihan Simpanan Wajib OVERDUE sebagai pemicu HOLD.
     */
    private function anggotaMenunggak(string $nama, float $nominal = 50000, int $jumlahPeriode = 2): Anggota
    {
        $anggota = Anggota::create([
            'no_anggota' => 'H'.rand(100000, 999999),
            'nik' => str_pad((string) rand(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'nama_lengkap' => $nama,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Pengujian HOLD SHU No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_ACTIVE,
            'tanggal_gabung' => now()->subYear()->startOfYear()->toDateString(),
        ]);

        for ($i = 1; $i <= $jumlahPeriode; $i++) {
            MandatorySavingsBill::create([
                'member_id' => $anggota->id,
                'bill_period' => now()->subMonths($i)->format('Y-m'),
                'amount' => $nominal,
                'status' => MandatorySavingsBill::STATUS_OVERDUE,
                'payment_method' => MandatorySavingsBill::METODE_CASH,
            ]);
        }

        return $anggota;
    }

    /**
     * Halaman modul SHU harus menampilkan seksi penahanan HOLD secara
     * REAL-TIME ketika ada anggota dengan tagihan Simpanan Wajib OVERDUE —
     * tidak bergantung pada periode/baris distribusi yang sudah diproses.
     */
    public function test_index_shu_menampilkan_seksi_penahanan_saat_ada_tunggakan_overdue(): void
    {
        $anggota = $this->anggotaMenunggak('Wahyu Menunggak', 75000, 2);

        $res = $this->actingAs($this->pengurus())->get(route('pengurus.shu.index'));

        $res->assertOk();
        $res->assertSee('Penahanan SHU (HOLD)', false);
        $res->assertSee('Tunggakan Simpanan Wajib', false);
        $res->assertSee($anggota->no_anggota, false);
        $res->assertSee('Wahyu Menunggak', false);
        // Nominal tunggakan 2 × 75.000 harus tampil terformat.
        $res->assertSee('150.000', false);
    }

    /**
     * Bila kebijakan `shu_hold_on_overdue` dimatikan pengurus, seksi HOLD
     * tidak boleh tampil meski anggota tetap menunggak (zero-hardcoding:
     * perilaku sepenuhnya dikendalikan setting).
     */
    public function test_seksi_penahanan_tidak_tampil_bila_kebijakan_hold_dinonaktifkan(): void
    {
        $this->anggotaMenunggak('Anggota Tunggakan', 50000, 1);

        $this->setSettingKoperasi(['shu_hold_on_overdue' => false]);

        $res = $this->actingAs($this->pengurus())->get(route('pengurus.shu.index'));

        $res->assertOk();
        $res->assertDontSee('Penahanan SHU (HOLD)', false);
    }

    /**
     * Tulis langsung nilai setting ke model kebijakan + segarkan cache,
     * meniru aksi pengurus di UI Pengaturan Kebijakan Koperasi.
     */
    private function setSettingKoperasi(array $nilai): void
    {
        $kebijakan = KebijakanKoperasi::query()->first();

        if (! $kebijakan) {
            $kebijakan = KebijakanKoperasi::create([]);
        }

        foreach ($nilai as $kunci => $isi) {
            $kebijakan->{$kunci} = $isi;
        }

        $kebijakan->save();
        Setting::refresh();
    }
}
