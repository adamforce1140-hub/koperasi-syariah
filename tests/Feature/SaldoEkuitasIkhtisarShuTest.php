<?php

namespace Tests\Feature;

use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Perbaikan logika perhitungan saldo akun Ekuitas/Modal (Kepala 3),
 * khususnya akun 3-9000 Ikhtisar SHU.
 *
 * Cakupan pengujian:
 *  A. Akurasi rumus saldo untuk SELURUH akun kategori Ekuitas/Modal
 *     (Kredit − Debet, bukan Debet − Kredit).
 *  B. Ketepatan tampilan Saldo Ikhtisar SHU pada card atas untuk kondisi
 *     keuangan untung, rugi, dan impas — nilai tidak pernah minus saat untung.
 *  C. Alur jurnal tutup buku: pendapatan & beban harus pindah ke posisi
 *     Buku Besar yang benar dan SHU bermuara ke sisi Kredit akun 3-9000.
 *  D. Tidak ada penurunan fungsi lain (Buku Besar, Neraca, halaman cadangan).
 */
class SaldoEkuitasIkhtisarShuTest extends TestCase
{
    use DatabaseTransactions;

    private function pengurus(): User
    {
        return User::firstOrCreate(
            ['email' => 'pengurus.saldoekuitas@test.local'],
            ['name' => 'Pengurus Saldo Ekuitas', 'password' => Hash::make('password'), 'role' => 'pengurus']
        );
    }

    /**
     * Halaman & endpoint CoA (mapping akun, verifikasi jurnal, tutup buku)
     * adalah KONFIGURASI SISTEM ber-guard `role:super_admin`, bukan kewenangan
     * Pengurus — lihat prinsip Segregation of Duties.
     */
    private function superAdmin(): User
    {
        return User::firstOrCreate(
            ['email' => 'superadmin.saldoekuitas@test.local'],
            ['name' => 'Super Admin Saldo Ekuitas', 'password' => Hash::make('password'), 'role' => 'super_admin']
        );
    }

    /** Ambil (atau buat) akun CoA tertentu dengan kategori & posisi yang benar. */
    private function akun(string $kode, ?string $nama = null, ?string $kategori = null, ?string $klasifikasi = null): Coa
    {
        $kepala = substr($kode, 0, 1);
        $default = match ($kepala) {
            '1' => ['Aset', 'Aset Lancar', 'Debet'],
            '2' => ['Kewajiban', 'Kewajiban Jangka Pendek', 'Kredit'],
            '3' => ['Ekuitas', 'Saldo Laba / SHU', 'Kredit'],
            '4' => ['Pendapatan', 'Pendapatan', 'Kredit'],
            '5' => ['Beban', 'Beban Operasional', 'Debet'],
            default => ['Ekuitas', 'Saldo Laba / SHU', 'Kredit'],
        };

        return Coa::updateOrCreate(
            ['kode_akun' => $kode],
            [
                'nama_akun' => $nama ?? ('UJI '.$kode),
                'kategori' => $kategori ?? $default[0],
                'klasifikasi_neraca' => $klasifikasi ?? $default[1],
                'tipe_saldo_normal' => $default[2],
                'saldo_awal' => 0,
                'is_active' => true,
            ]
        );
    }

    /** Posting satu baris jurnal untuk sebuah akun. */
    private function jurnal(Coa $coa, float $debet = 0, float $kredit = 0, string $sumber = 'UJI', ?string $tanggal = null): JurnalDetail
    {
        $j = Jurnal::create([
            'no_jurnal' => 'UJI-'.uniqid(),
            'tanggal_jurnal' => $tanggal ?? now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => $sumber,
            'keterangan' => 'Uji saldo ekuitas',
            'total_debet' => $debet,
            'total_kredit' => $kredit,
            'is_balance' => true,
            'status' => 'Posted',
            'posted_at' => now(),
        ]);

        return JurnalDetail::create([
            'jurnal_id' => $j->id,
            'coa_id' => $coa->id,
            'baris' => 1,
            'debet' => $debet,
            'kredit' => $kredit,
            'keterangan' => 'Uji',
        ]);
    }

    /**
     * Posting jurnal untuk akun dengan nilai bertanda: positif = searah posisi
     * akun (menambah saldo), negatif = berlawanan posisi (mengurangi saldo).
     */
    private function jurnalSearah(Coa $coa, float $nilai, string $sumber = 'UJI'): JurnalDetail
    {
        if ($coa->posisiSaldo() === 'Kredit') {
            return $this->jurnal($coa, $nilai < 0 ? abs($nilai) : 0, $nilai > 0 ? $nilai : 0, $sumber);
        }

        return $this->jurnal($coa, $nilai > 0 ? $nilai : 0, $nilai < 0 ? abs($nilai) : 0, $sumber);
    }

    /** Bersihkan mutasi akun uji agar tiap test mulai dari saldo bersih. */
    private function bersihkan(Coa ...$akunList): void
    {
        foreach ($akunList as $a) {
            JurnalDetail::where('coa_id', $a->id)->delete();
            $a->update(['saldo_awal' => 0]);
        }
    }

    /**
     * Siapkan kondisi uji status keuangan yang deterministik.
     *
     * Coa::statusKeuangan() dan closeFiscalYear() bersifat AGREGAT atas seluruh
     * akun Pendapatan & Beban. Basis data uji bisa berisi jurnal nyata dari
     * seeder/modul lain, sehingga akun-akun itu harus dinolkan lebih dulu agar
     * hasil uji tidak bergantung pada data yang kebetulan ada.
     *
     * @param  array<string, string>  $akunUji  peta kode => nama akun uji
     * @return array<string, Coa>
     */
    private function siapkanPendapatanBeban(array $akunUji = []): array
    {
        foreach (Coa::whereIn('kategori', ['Pendapatan', 'Beban'])->get() as $a) {
            JurnalDetail::where('coa_id', $a->id)->delete();
            $a->update(['saldo_awal' => 0]);
        }

        $hasil = [];
        foreach ($akunUji as $kode => $nama) {
            $hasil[$kode] = $this->akun($kode, $nama);
        }

        return $hasil;
    }

    /**
     * Hitung total mutasi Pendapatan & Beban dari SELURUH akun berkategori itu
     * (setelah dinolkan), sebagai dasar ekspektasi nilai jurnal penutup.
     *
     * @return array{debet: float, kredit: float, shu: float}
     */
    private function ekspektasiPenutup(): array
    {
        $debet = 0.0;
        $kredit = 0.0;

        foreach (Coa::where('kategori', 'Pendapatan')->where('is_active', true)->get() as $c) {
            $debet += $c->mutasiPenambah() + (float) $c->saldo_awal;
        }
        foreach (Coa::where('kategori', 'Beban')->where('is_active', true)->get() as $c) {
            $kredit += $c->mutasiPenambah() + (float) $c->saldo_awal;
        }

        return [
            'debet' => round($debet, 2),
            'kredit' => round($kredit, 2),
            'shu' => round($debet - $kredit, 2),
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    // A. AKURASI RUMUS SALDO SELURUH AKUN EKUITAS/MODAL (KEPALA 3)
    // ══════════════════════════════════════════════════════════════════

    public function test_rumus_saldo_kepala_3_adalah_kredit_minus_debet(): void
    {
        $coa = $this->akun('3-2001', 'Dana Cadangan Koperasi', 'Ekuitas', 'Dana Cadangan');
        $this->bersihkan($coa);

        $this->jurnal($coa, 0, 50_000_000);   // penambahan ekuitas (Kredit)
        $this->jurnal($coa, 8_000_000, 0);    // pengurangan ekuitas (Debet)

        // Rumus yang BENAR untuk akun Ekuitas: Kredit − Debet
        $this->assertEqualsWithDelta(42_000_000, $coa->fresh()->saldoBerjalan(), 0.01);

        // Rumus terbalik (Debet − Kredit) HARUS TIDAK dipakai
        $this->assertNotEqualsWithDelta(-42_000_000, $coa->fresh()->saldoBerjalan(), 0.01);
    }

    public function test_seluruh_akun_ekuitas_memakai_rumus_kredit_minus_debet(): void
    {
        // Seluruh akun Ekuitas/Modal standar pada bagan akun koperasi.
        $daftar = [
            '3-1001' => 'Simpanan Pokok',
            '3-1002' => 'Simpanan Wajib',
            '3-2001' => 'Dana Cadangan Koperasi',
            '3-3001' => 'Modal Penyertaan',
            '3-9000' => 'Ikhtisar SHU / Laba Ditahan',
        ];

        foreach ($daftar as $kode => $nama) {
            $coa = $this->akun($kode, $nama);
            $this->bersihkan($coa);

            $this->jurnal($coa, 0, 10_000_000);
            $this->jurnal($coa, 4_000_000, 0);

            $this->assertEqualsWithDelta(
                6_000_000,
                $coa->fresh()->saldoBerjalan(),
                0.01,
                "Akun Ekuitas {$kode} ({$nama}) harus memakai rumus Kredit − Debet."
            );

            $this->assertSame('Kredit', $coa->fresh()->posisiSaldo(), "Posisi akun {$kode} harus Kredit.");
            $this->assertTrue($coa->fresh()->isEkuitas(), "Akun {$kode} harus dikenali sebagai Ekuitas.");
        }
    }

    public function test_akun_ekuitas_tetap_benar_meski_tipe_saldo_normal_keliru_debet(): void
    {
        // Skenario salah input: kolom tipe_saldo_normal terisi 'Debet' padahal
        // akun berkepala 3. Rumus WAJIB tetap mengikuti posisi Kepala akun.
        $coa = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $this->bersihkan($coa);
        $coa->update(['tipe_saldo_normal' => 'Debet']);

        $this->jurnal($coa, 0, 25_000_000);

        $this->assertEqualsWithDelta(
            25_000_000,
            $coa->fresh()->saldoBerjalan(),
            0.01,
            'Posisi akun harus ditentukan dari Kepala kode akun, bukan kolom tipe_saldo_normal.'
        );
    }

    public function test_mutasi_penambah_dan_pengurang_mengikuti_posisi_akun(): void
    {
        $ekuitas = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $this->bersihkan($ekuitas);
        $this->jurnal($ekuitas, 0, 30_000_000);
        $this->jurnal($ekuitas, 7_000_000, 0);

        $ekuitas = $ekuitas->fresh();
        $this->assertEqualsWithDelta(30_000_000, $ekuitas->mutasiPenambah(), 0.01, 'Penambah ekuitas = Kredit.');
        $this->assertEqualsWithDelta(7_000_000, $ekuitas->mutasiPengurang(), 0.01, 'Pengurang ekuitas = Debet.');
        $this->assertEqualsWithDelta(23_000_000, $ekuitas->saldoBerjalan(), 0.01);
    }

    public function test_akun_aset_dan_beban_tetap_memakai_debet_minus_kredit(): void
    {
        // Regresi: perbaikan akun Ekuitas tidak boleh mengubah akun posisi Debet.
        $kas = $this->akun('1-1001', 'Kas', 'Aset', 'Aset Lancar');
        $this->bersihkan($kas);
        $this->jurnal($kas, 12_000_000, 0);
        $this->jurnal($kas, 0, 3_000_000);
        $this->assertEqualsWithDelta(9_000_000, $kas->fresh()->saldoBerjalan(), 0.01);
        $this->assertSame('Debet', $kas->fresh()->posisiSaldo());

        $beban = $this->akun('5-1001', 'Beban Operasional', 'Beban', 'Beban Operasional');
        $this->bersihkan($beban);
        $this->jurnal($beban, 4_000_000, 0);
        $this->assertEqualsWithDelta(4_000_000, $beban->fresh()->saldoBerjalan(), 0.01);
        $this->assertSame('Debet', $beban->fresh()->posisiSaldo());
    }

    public function test_akun_kewajiban_dan_pendapatan_berposisi_kredit(): void
    {
        $kewajiban = $this->akun('2-1001', 'Simpanan Sukarela Anggota', 'Kewajiban', 'Kewajiban Jangka Pendek');
        $this->bersihkan($kewajiban);
        $this->jurnal($kewajiban, 0, 15_000_000);
        $this->assertEqualsWithDelta(15_000_000, $kewajiban->fresh()->saldoBerjalan(), 0.01);
        $this->assertSame('Kredit', $kewajiban->fresh()->posisiSaldo());

        $pendapatan = $this->akun('4-1001', 'Pendapatan Jasa', 'Pendapatan', 'Pendapatan');
        $this->bersihkan($pendapatan);
        $this->jurnal($pendapatan, 0, 20_000_000);
        $this->assertEqualsWithDelta(20_000_000, $pendapatan->fresh()->saldoBerjalan(), 0.01);
        $this->assertSame('Kredit', $pendapatan->fresh()->posisiSaldo());
    }

    // ══════════════════════════════════════════════════════════════════
    // B. TAMPILAN SALDO IKHTISAR SHU (KONDISI UNTUNG / RUGI / IMPAS)
    // ══════════════════════════════════════════════════════════════════

    public function test_status_keuangan_untung_rugi_dan_impas(): void
    {
        ['4-9001' => $pendapatan, '5-9001' => $beban] = $this->siapkanPendapatanBeban([
            '4-9001' => 'Pendapatan Uji Status',
            '5-9001' => 'Beban Uji Status',
        ]);

        // IMPAS: pendapatan == beban
        $this->jurnal($pendapatan, 0, 10_000_000);
        $this->jurnal($beban, 10_000_000, 0);
        $this->assertSame('impas', Coa::statusKeuangan());
        $this->assertEqualsWithDelta(0, Coa::labaBersihBerjalan(), 0.01);

        // UNTUNG: tambah pendapatan
        $this->jurnal($pendapatan, 0, 5_000_000);
        $this->assertSame('untung', Coa::statusKeuangan());
        $this->assertEqualsWithDelta(5_000_000, Coa::labaBersihBerjalan(), 0.01);

        // RUGI: tambah beban melebihi pendapatan
        $this->jurnal($beban, 9_000_000, 0);
        $this->assertSame('rugi', Coa::statusKeuangan());
        $this->assertEqualsWithDelta(-4_000_000, Coa::labaBersihBerjalan(), 0.01);
    }

    public function test_saldo_shu_tidak_ditampilkan_minus_saat_koperasi_untung(): void
    {
        /* Skenario yang dilaporkan: koperasi UNTUNG, tetapi akun 3-9000
         | tertekan ke posisi Debet karena ada jurnal distribusi SHU tanpa
         | jurnal penutup laba yang mengkreditnya. Nilai card TIDAK boleh
         | menampilkan angka minus. */
        ['4-9002' => $pendapatan, '5-9002' => $beban] = $this->siapkanPendapatanBeban([
            '4-9002' => 'Pendapatan Uji Untung',
            '5-9002' => 'Beban Uji Untung',
        ]);
        $shu = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $this->bersihkan($shu);

        // Koperasi untung 10 juta
        $this->jurnal($pendapatan, 0, 30_000_000);
        $this->jurnal($beban, 20_000_000, 0);
        $this->assertSame('untung', Coa::statusKeuangan());

        // Namun 3-9000 terlanjur didebet 25 juta (distribusi tanpa penutup laba)
        $this->jurnal($shu, 25_000_000, 0, 'Distribusi SHU');

        $shu = $shu->fresh();
        $this->assertLessThan(0, $shu->saldoBerjalan(), 'Prasyarat: saldo mentah memang negatif.');

        $tampil = $shu->saldoBerjalanTampil();

        // INTI PERBAIKAN: nilai yang ditampilkan tidak minus saat koperasi untung.
        $this->assertGreaterThanOrEqual(0, $tampil['nilai'], 'Saldo Ikhtisar SHU tidak boleh negatif pada kondisi untung.');
        $this->assertSame(0.0, $tampil['nilai']);
        $this->assertFalse($tampil['ditampilkan_negatif']);
        $this->assertTrue($tampil['akun_bermasalah'], 'Anomali harus ditandai, bukan disembunyikan.');
        $this->assertEqualsWithDelta(-25_000_000, $tampil['mentah'], 0.01, 'Nilai mentah tetap dilaporkan untuk audit.');
    }

    public function test_saldo_shu_bernilai_wajar_saat_koperasi_untung_dan_sudah_tutup_buku(): void
    {
        // Kondisi ideal: laba sudah dipindahkan ke sisi Kredit 3-9000.
        ['4-9003' => $pendapatan, '5-9003' => $beban] = $this->siapkanPendapatanBeban([
            '4-9003' => 'Pendapatan Uji Ideal',
            '5-9003' => 'Beban Uji Ideal',
        ]);
        $shu = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $this->bersihkan($shu);

        $this->jurnal($pendapatan, 0, 30_000_000);
        $this->jurnal($beban, 20_000_000, 0);

        // Penutup laba: 3-9000 dikredit sebesar laba 10 juta
        $this->jurnal($shu, 0, 10_000_000, 'TutupBuku');

        $tampil = $shu->fresh()->saldoBerjalanTampil();
        $this->assertEqualsWithDelta(10_000_000, $tampil['nilai'], 0.01);
        $this->assertEqualsWithDelta(10_000_000, $tampil['mentah'], 0.01);
        $this->assertFalse($tampil['akun_bermasalah']);
        $this->assertFalse($tampil['ditampilkan_negatif']);
    }

    public function test_halaman_cadangan_menampilkan_shu_tanpa_minus_dan_menandai_anomali(): void
    {
        ['4-9004' => $pendapatan, '5-9004' => $beban] = $this->siapkanPendapatanBeban([
            '4-9004' => 'Pendapatan Uji Halaman',
            '5-9004' => 'Beban Uji Halaman',
        ]);
        $shu = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $this->bersihkan($shu);

        $this->jurnal($pendapatan, 0, 40_000_000);
        $this->jurnal($beban, 10_000_000, 0);
        $this->jurnal($shu, 12_000_000, 0, 'Distribusi SHU');

        $this->actingAs($this->pengurus())
            ->get(route('pengurus.cadangan.index'))
            ->assertOk()
            ->assertViewHas('statusKeuangan', 'untung')
            ->assertViewHas('saldoShu', fn ($v) => $v >= 0)
            ->assertViewHas('catatanShu', fn ($v) => is_string($v) && $v !== '')
            ->assertSee('Ikhtisar SHU')
            // Nilai minus mentah tidak boleh muncul sebagai nominal di card
            ->assertDontSee('Rp -12.000.000');
    }

    public function test_halaman_cadangan_kondisi_impas_tanpa_catatan_anomali(): void
    {
        ['4-9005' => $pendapatan, '5-9005' => $beban] = $this->siapkanPendapatanBeban([
            '4-9005' => 'Pendapatan Uji Impas',
            '5-9005' => 'Beban Uji Impas',
        ]);
        $shu = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $this->bersihkan($shu);

        // Impas: pendapatan == beban. Penutup laba 3-9000 = 0.
        $this->jurnal($pendapatan, 0, 15_000_000);
        $this->jurnal($beban, 15_000_000, 0);

        $this->actingAs($this->pengurus())
            ->get(route('pengurus.cadangan.index'))
            ->assertOk()
            ->assertViewHas('statusKeuangan', 'impas')
            ->assertViewHas('labaBersih', fn ($v) => abs($v) < 0.01)
            ->assertViewHas('saldoShu', fn ($v) => $v >= 0);
    }

    public function test_halaman_cadangan_kondisi_rugi_tetap_tidak_menampilkan_minus(): void
    {
        ['4-9006' => $pendapatan, '5-9006' => $beban] = $this->siapkanPendapatanBeban([
            '4-9006' => 'Pendapatan Uji Rugi',
            '5-9006' => 'Beban Uji Rugi',
        ]);
        $shu = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $this->bersihkan($shu);

        $this->jurnal($pendapatan, 0, 5_000_000);
        $this->jurnal($beban, 9_000_000, 0);
        // Rugi 4 juta dipindahkan ke 3-9000 sebagai Debet (mengurangi ekuitas)
        $this->jurnal($shu, 4_000_000, 0, 'TutupBuku');

        $this->actingAs($this->pengurus())
            ->get(route('pengurus.cadangan.index'))
            ->assertOk()
            ->assertViewHas('statusKeuangan', 'rugi')
            ->assertViewHas('labaBersih', fn ($v) => $v < 0)
            ->assertViewHas('saldoShu', fn ($v) => $v >= 0);
    }

    public function test_pengunjung_belum_login_tidak_bisa_membuka_halaman_cadangan(): void
    {
        $this->get(route('pengurus.cadangan.index'))->assertRedirect(route('login'));
    }

    // ══════════════════════════════════════════════════════════════════
    // C. ALUR JURNAL TUTUP BUKU / IKHTISAR SHU
    // ══════════════════════════════════════════════════════════════════

    public function test_tutup_buku_memindahkan_pendapatan_dan_beban_ke_posisi_yang_benar(): void
    {
        ['4-9007' => $pendapatan, '5-9007' => $beban] = $this->siapkanPendapatanBeban([
            '4-9007' => 'Pendapatan Uji Penutup',
            '5-9007' => 'Beban Uji Penutup',
        ]);
        $shu = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $this->akun('3-2001', 'Dana Cadangan Koperasi', 'Ekuitas', 'Dana Cadangan');
        $this->bersihkan($shu);

        $this->jurnal($pendapatan, 0, 50_000_000);
        $this->jurnal($beban, 35_000_000, 0);

        // Ekspektasi dihitung dari seluruh akun Pendapatan & Beban yang aktif,
        // bukan hanya akun uji — karena jurnal penutup bersifat agregat.
        $harapan = $this->ekspektasiPenutup();

        $this->actingAs($this->superAdmin())
            ->post(route('pengurus.coa.close-fiscal-year'), ['tahun_buku' => (int) now()->year])
            ->assertRedirect();

        $this->assertEqualsWithDelta(
            $harapan['shu'],
            $shu->fresh()->saldoBerjalan(),
            0.01,
            'Laba bersih harus masuk sisi Kredit 3-9000.'
        );

        // Pendapatan & Beban harus ternol-kan setelah penutupan
        $this->assertEqualsWithDelta(0, $pendapatan->fresh()->saldoBerjalan(), 0.01, 'Saldo Pendapatan harus nol setelah penutup.');
        $this->assertEqualsWithDelta(0, $beban->fresh()->saldoBerjalan(), 0.01, 'Saldo Beban harus nol setelah penutup.');

        // Baris jurnal penutup: Pendapatan di DEBET, Beban di KREDIT
        $barisPendapatan = JurnalDetail::where('coa_id', $pendapatan->id)->orderByDesc('id')->first();
        $barisBeban = JurnalDetail::where('coa_id', $beban->id)->orderByDesc('id')->first();

        $this->assertGreaterThan(0, (float) $barisPendapatan->debet, 'Penutup Pendapatan (posisi Kredit) harus di sisi DEBET.');
        $this->assertEqualsWithDelta(0, (float) $barisPendapatan->kredit, 0.01);
        $this->assertGreaterThan(0, (float) $barisBeban->kredit, 'Penutup Beban (posisi Debet) harus di sisi KREDIT.');
        $this->assertEqualsWithDelta(0, (float) $barisBeban->debet, 0.01);
    }

    public function test_jurnal_penutup_selalu_balance_saat_untung_maupun_rugi(): void
    {
        $shu = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $this->akun('3-2001', 'Dana Cadangan Koperasi', 'Ekuitas', 'Dana Cadangan');

        foreach ([['2001', 60_000_000, 25_000_000], ['2002', 12_000_000, 30_000_000]] as [$tahun, $nilaiPendapatan, $nilaiBeban]) {
            ['4-90'.$tahun => $akunPendapatan, '5-90'.$tahun => $akunBeban] = $this->siapkanPendapatanBeban([
                '4-90'.$tahun => 'Pendapatan Uji '.$tahun,
                '5-90'.$tahun => 'Beban Uji '.$tahun,
            ]);
            $this->bersihkan($shu);

            $this->jurnal($akunPendapatan, 0, $nilaiPendapatan);
            $this->jurnal($akunBeban, $nilaiBeban, 0);

            $this->actingAs($this->superAdmin())
                ->post(route('pengurus.coa.close-fiscal-year'), ['tahun_buku' => (int) $tahun])
                ->assertRedirect();

            // Jurnal penutup terakhir untuk tahun ini harus balance
            $jurnal = Jurnal::where('sumber', 'TutupBuku')->orderByDesc('id')->first();
            $this->assertNotNull($jurnal);
            $this->assertEqualsWithDelta(
                (float) $jurnal->total_debet,
                (float) $jurnal->total_kredit,
                0.01,
                "Jurnal penutup tahun {$tahun} harus balance (debet = kredit)."
            );

            // Jumlah baris detail harus sama dengan total yang tercatat di header
            $this->assertEqualsWithDelta(
                (float) $jurnal->total_debet,
                (float) JurnalDetail::where('jurnal_id', $jurnal->id)->sum('debet'),
                0.01
            );
            $this->assertEqualsWithDelta(
                (float) $jurnal->total_kredit,
                (float) JurnalDetail::where('jurnal_id', $jurnal->id)->sum('kredit'),
                0.01
            );

            // Tahun pertama laba (60 − 25 = 35 juta), tahun kedua rugi (12 − 30 = −18 juta)
            $harapanShu = round($nilaiPendapatan - $nilaiBeban, 2);
            $this->assertEqualsWithDelta(
                $harapanShu,
                (float) JurnalDetail::where('jurnal_id', $jurnal->id)->where('coa_id', $shu->id)->sum('kredit')
                    - (float) JurnalDetail::where('jurnal_id', $jurnal->id)->where('coa_id', $shu->id)->sum('debet'),
                0.01,
                "Pengaruh ke 3-9000 pada tahun {$tahun} harus sama dengan laba/rugi bersih."
            );
        }
    }

    public function test_setelah_tutup_buku_distribusi_shu_tidak_menghasilkan_saldo_minus(): void
    {
        ['4-9008' => $pendapatan, '5-9008' => $beban] = $this->siapkanPendapatanBeban([
            '4-9008' => 'Pendapatan Uji Alur Penuh',
            '5-9008' => 'Beban Uji Alur Penuh',
        ]);
        $shu = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $this->akun('3-2001', 'Dana Cadangan Koperasi', 'Ekuitas', 'Dana Cadangan');
        $this->bersihkan($shu);

        $this->jurnal($pendapatan, 0, 80_000_000);
        $this->jurnal($beban, 30_000_000, 0);

        $labaBersih = $this->ekspektasiPenutup()['shu'];

        // Tutup buku → 3-9000 dikredit sebesar laba bersih
        $this->actingAs($this->superAdmin())
            ->post(route('pengurus.coa.close-fiscal-year'), ['tahun_buku' => (int) now()->year])
            ->assertRedirect();

        $this->assertEqualsWithDelta($labaBersih, $shu->fresh()->saldoBerjalan(), 0.01);
        $this->assertFalse($shu->fresh()->saldoBerjalanTampil()['akun_bermasalah'], 'Setelah tutup buku, akun tidak boleh bermasalah.');

        /* Alokasi 20% dari laba bersih ke Dana Cadangan. Nominal harus lebih
         | kecil dari saldo SHU agar alokasi lolos guard kecukupan saldo. */
        $nominalAlokasi = round(abs($labaBersih) * 0.2, 2);
        $this->assertGreaterThan(0, $nominalAlokasi, 'Prasyarat: laba bersih harus positif agar alokasi bisa diuji.');

        $sisaHarapan = round($labaBersih - $nominalAlokasi, 2);
        $saldoCadanganAwal = (float) Coa::findByKode('3-2001')->saldoBerjalan();

        $this->actingAs($this->pengurus())
            ->post(route('pengurus.cadangan.alokasi'), [
                'nominal' => $nominalAlokasi,
                'keterangan' => 'Uji alokasi alur penuh',
            ])
            ->assertRedirect();

        $this->assertEqualsWithDelta($sisaHarapan, $shu->fresh()->saldoBerjalan(), 0.01);
        // Diukur sebagai SELISIH, karena saldo_awal akun cadangan berasal dari data seeder
        $this->assertEqualsWithDelta(
            $nominalAlokasi,
            Coa::findByKode('3-2001')->saldoBerjalan() - $saldoCadanganAwal,
            0.01,
            'Dana Cadangan harus bertambah tepat sebesar nominal alokasi.'
        );
    }

    public function test_tutup_buku_dua_kali_tidak_menggandakan_shu(): void
    {
        $akunUji = $this->siapkanPendapatanBeban([
            '4-9009' => 'Pendapatan Uji Idempoten',
            '5-9009' => 'Beban Uji Idempoten',
        ]);
        $shu = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $this->akun('3-2001', 'Dana Cadangan Koperasi', 'Ekuitas', 'Dana Cadangan');
        $this->bersihkan($shu, $akunUji['4-9009'], $akunUji['5-9009']);

        $this->jurnal($akunUji['4-9009'], 0, 25_000_000);
        $this->jurnal($akunUji['5-9009'], 5_000_000, 0);

        // Ekspektasi dihitung agregat agar tahan terhadap akun Pendapatan/Beban bawaan seeder
        $labaBersih = $this->ekspektasiPenutup()['shu'];

        $this->actingAs($this->superAdmin())
            ->post(route('pengurus.coa.close-fiscal-year'), ['tahun_buku' => (int) now()->year]);
        $setelahPenutupanPertama = $shu->fresh()->saldoBerjalan();
        $this->assertEqualsWithDelta(
            $labaBersih,
            $setelahPenutupanPertama,
            0.01,
            'Penutupan pertama harus mengkredit 3-9000 tepat sebesar laba bersih.'
        );

        // Penutupan kedua tidak boleh menambah laba bersih sekali lagi
        $this->actingAs($this->superAdmin())
            ->post(route('pengurus.coa.close-fiscal-year'), ['tahun_buku' => (int) now()->year]);

        $this->assertEqualsWithDelta(
            $setelahPenutupanPertama,
            $shu->fresh()->saldoBerjalan(),
            0.01,
            'Penutupan ulang tahun buku yang sama tidak boleh menggandakan SHU.'
        );
    }

    public function test_akun_9000_menerima_tepat_sebesar_laba_bersih(): void
    {
        $akunUji = $this->siapkanPendapatanBeban([
            '4-9010' => 'Pendapatan Uji Presisi',
            '5-9010' => 'Beban Uji Presisi',
        ]);
        $shu = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $this->akun('3-2001', 'Dana Cadangan Koperasi', 'Ekuitas', 'Dana Cadangan');
        $this->bersihkan($shu, $akunUji['4-9010'], $akunUji['5-9010']);

        $this->jurnal($akunUji['4-9010'], 0, 33_333_333.33);
        $this->jurnal($akunUji['5-9010'], 11_111_111.11, 0);

        // Laba bersih dihitung agregat: mencakup akun Pendapatan/Beban lain yang masih bersaldo
        $labaBersih = $this->ekspektasiPenutup()['shu'];

        $this->actingAs($this->superAdmin())
            ->post(route('pengurus.coa.close-fiscal-year'), ['tahun_buku' => (int) now()->year]);

        $this->assertEqualsWithDelta(
            $labaBersih,
            $shu->fresh()->saldoBerjalan(),
            0.01,
            'Akun 3-9000 harus menerima tepat sebesar laba bersih hasil tutup buku.'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // D. GUARD SALDO, GUARD PROFIT/LOSS & TIDAK ADA REGRESI PADA FUNGSI LAIN
    // ══════════════════════════════════════════════════════════════════

    public function test_tombol_posting_jurnal_dinonaktifkan_dan_warning_tampil_saat_rugi(): void
    {
        // Paksa posisi berjalan RUGI: Pendapatan 0, Beban 15 juta
        $this->siapkanPendapatanBeban(['5-9011' => 'Beban Uji Rugi Tombol']);
        $this->jurnal($this->akun('5-9011', 'Beban Uji Rugi Tombol'), 15_000_000, 0);

        $this->assertSame('rugi', Coa::statusKeuangan(), 'Prasyarat: posisi harus rugi.');

        $html = $this->actingAs($this->pengurus())
            ->get(route('pengurus.cadangan.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'Alokasi Dana Cadangan tidak dapat dilakukan karena posisi keuangan berjalan sedang mengalami kerugian.',
            $html,
            'Warning alert kerugian harus tampil di halaman.'
        );

        $this->assertMatchesRegularExpression(
            '/<button[^>]*type="submit"[^>]*disabled[^>]*>/',
            $html,
            'Tombol "Posting Jurnal" harus dalam keadaan disabled saat rugi.'
        );

        $this->assertStringNotContainsString(
            'Posting Jurnal',
            preg_replace('/<button[^>]*type="submit"[^>]*>.*?<\/button>/s', '', $html),
            'Tidak boleh ada tombol submit aktif lain yang tersisa.'
        );
    }

    public function test_tombol_posting_jurnal_aktif_dan_tanpa_warning_saat_untung(): void
    {
        $this->siapkanPendapatanBeban(['4-9011' => 'Pendapatan Uji Untung Tombol']);
        $this->jurnal($this->akun('4-9011', 'Pendapatan Uji Untung Tombol'), 0, 15_000_000);

        $this->assertSame('untung', Coa::statusKeuangan(), 'Prasyarat: posisi harus untung.');

        $html = $this->actingAs($this->pengurus())
            ->get(route('pengurus.cadangan.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            'posisi keuangan berjalan sedang mengalami kerugian',
            $html,
            'Warning kerugian tidak boleh tampil saat posisi untung.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/<button[^>]*type="submit"[^>]*disabled[^>]*>/',
            $html,
            'Tombol "Posting Jurnal" tidak boleh disabled saat posisi untung.'
        );
    }

    public function test_backend_menolak_alokasi_saat_posisi_keuangan_rugi(): void
    {
        $this->siapkanPendapatanBeban(['5-9012' => 'Beban Uji Rugi Backend']);
        $shu = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $cadangan = $this->akun('3-2001', 'Dana Cadangan Koperasi', 'Ekuitas', 'Dana Cadangan');
        $this->bersihkan($shu, $cadangan);

        // Beban besar → rugi; saldo SHU sengaja dibuat cukup agar penolakan
        // benar-benar berasal dari guard profit/loss, bukan guard kecukupan saldo.
        $this->jurnal($this->akun('5-9012', 'Beban Uji Rugi Backend'), 15_000_000, 0);
        $this->jurnal($shu, 0, 40_000_000, 'TutupBuku');

        $this->assertSame('rugi', Coa::statusKeuangan(), 'Prasyarat: posisi harus rugi.');

        $saldoShuSebelum = $shu->fresh()->saldoBerjalan();
        $saldoCadanganSebelum = $cadangan->fresh()->saldoBerjalan();
        $jumlahJurnalSebelum = Jurnal::where('sumber', 'Alokasi SHU ke Cadangan')->count();

        $this->actingAs($this->pengurus())
            ->post(route('pengurus.cadangan.alokasi'), [
                'nominal' => 5_000_000,
                'keterangan' => 'Uji tolak alokasi saat rugi',
            ])
            ->assertSessionHasErrors([
                'nominal' => 'Alokasi Dana Cadangan tidak dapat dilakukan karena posisi keuangan berjalan sedang mengalami kerugian.',
            ]);

        $this->assertEqualsWithDelta($saldoShuSebelum, $shu->fresh()->saldoBerjalan(), 0.01, 'Saldo 3-9000 tidak boleh berubah.');
        $this->assertEqualsWithDelta($saldoCadanganSebelum, $cadangan->fresh()->saldoBerjalan(), 0.01, 'Saldo 3-2001 tidak boleh berubah.');
        $this->assertSame(
            $jumlahJurnalSebelum,
            Jurnal::where('sumber', 'Alokasi SHU ke Cadangan')->count(),
            'Tidak boleh ada jurnal terbentuk saat posisi rugi.'
        );
    }

    public function test_alokasi_shu_ditolak_bila_saldo_ikhtisar_tidak_mencukupi(): void
    {
        $shu = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $this->akun('3-2001', 'Dana Cadangan Koperasi', 'Ekuitas', 'Dana Cadangan');
        $this->bersihkan($shu);

        /* Bukti tidak ada jurnal yang terbentuk: dihitung sebagai SELISIH
         | jumlah jurnal sebelum vs sesudah request. Perhitungan selisih dipakai
         | agar test tidak dipengaruhi jurnal "Alokasi SHU ke Cadangan" yang
         | mungkin sudah dibuat oleh test lain di dalam transaksi yang sama. */
        $jumlahJurnalSebelum = Jurnal::where('sumber', 'Alokasi SHU ke Cadangan')->count();

        // Saldo 3-9000 kosong → alokasi 5 juta harus ditolak.
        // 'keterangan' tetap dikirim agar validasi form lolos dan error yang tersisa murni dari guard saldo.
        $this->actingAs($this->pengurus())
            ->post(route('pengurus.cadangan.alokasi'), [
                'nominal' => 5_000_000,
                'keterangan' => 'Uji tolak alokasi saldo tidak mencukupi',
            ])
            ->assertSessionHasErrors('nominal');

        $this->assertSame(
            $jumlahJurnalSebelum,
            Jurnal::where('sumber', 'Alokasi SHU ke Cadangan')->count(),
            'Tidak boleh ada jurnal terbentuk saat saldo tidak mencukupi.'
        );
    }

    public function test_alokasi_shu_berhasil_dan_saldo_cadangan_bertambah_sesuai_nominal(): void
    {
        /* Posisi keuangan harus UNTUNG agar guard profit/loss tidak menolak alokasi.
         | Saldo akun Pendapatan disiapkan agar laba berjalan positif. */
        $this->siapkanPendapatanBeban(['4-9013' => 'Pendapatan Uji Alokasi Berhasil']);
        $this->jurnal($this->akun('4-9013', 'Pendapatan Uji Alokasi Berhasil'), 0, 20_000_000);

        $shu = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $cadangan = $this->akun('3-2001', 'Dana Cadangan Koperasi', 'Ekuitas', 'Dana Cadangan');
        $this->bersihkan($shu, $cadangan);

        $this->jurnal($shu, 0, 40_000_000, 'TutupBuku');
        $sebelum = $cadangan->fresh()->saldoBerjalan();

        $this->assertSame('untung', Coa::statusKeuangan(), 'Prasyarat: posisi harus untung.');

        $this->actingAs($this->pengurus())
            ->post(route('pengurus.cadangan.alokasi'), [
                'nominal' => 12_500_000,
                'keterangan' => 'Uji alokasi ke Dana Cadangan',
            ])
            ->assertRedirect();

        $this->assertEqualsWithDelta(27_500_000, $shu->fresh()->saldoBerjalan(), 0.01);
        $this->assertEqualsWithDelta(
            $sebelum + 12_500_000,
            $cadangan->fresh()->saldoBerjalan(),
            0.01,
            'Dana Cadangan harus bertambah tepat sebesar nominal alokasi.'
        );
    }

    public function test_buku_besar_menampilkan_saldo_akun_ekuitas_dengan_rumus_benar(): void
    {
        $shu = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $this->bersihkan($shu);
        $this->jurnal($shu, 0, 5_000_000, 'TutupBuku');

        $this->actingAs($this->pengurus())
            ->get(route('laporan.buku-besar', ['coa_id' => $shu->id]))
            ->assertOk()
            ->assertSee('3-9000');
    }

    public function test_neraca_tetap_menampilkan_akun_ekuitas_setelah_perbaikan(): void
    {
        $shu = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $this->bersihkan($shu);
        $this->jurnal($shu, 0, 9_000_000, 'TutupBuku');

        $this->actingAs($this->pengurus())
            ->get(route('laporan.neraca'))
            ->assertOk();
    }

    public function test_verifikasi_jurnal_coa_tetap_berfungsi(): void
    {
        $kas = $this->akun('1-1001', 'Kas', 'Aset', 'Aset Lancar');
        $this->bersihkan($kas);
        $this->jurnal($kas, 1_000_000, 0);

        $this->actingAs($this->superAdmin())
            ->post(route('pengurus.coa.verify'))
            ->assertRedirect();
    }

    public function test_halaman_coa_tetap_dapat_dibuka(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('pengurus.coa.index'))
            ->assertOk();
    }
}
