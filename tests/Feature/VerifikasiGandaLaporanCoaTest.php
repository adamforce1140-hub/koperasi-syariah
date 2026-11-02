<?php

namespace Tests\Feature;

use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\User;
use App\Services\LaporanKeuanganSyariahService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * VERIFIKASI GANDA laporan Chart of Accounts (CoA).
 *
 * Mengunci perbaikan atas ketidaksesuaian antara kolom mentah
 * `tipe_saldo_normal` dan POSISI EFEKTIF akun yang diturunkan dari Kepala
 * kode akun (`Coa::posisiSaldo()`), khususnya untuk akun KONTRA
 * (1-2100 Penyisihan Kerugian Piutang Pembiayaan / CKPN).
 *
 * Lapis 1 — konsistensi internal: saldo hasil hitung ulang dari agregat
 *           `jurnal_detail` Posted harus sama dengan `Coa::saldoBerjalan()`.
 * Lapis 2 — keseimbangan: Σ debet = Σ kredit jurnal Posted dan
 *           Σ saldo pos Debet = Σ saldo pos Kredit (neraca saldo seimbang),
 *           serta persamaan dasar Aset = Kewajiban + DST + Ekuitas + L/R.
 */
class VerifikasiGandaLaporanCoaTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Halaman & endpoint CoA adalah KONFIGURASI SISTEM (mapping akun) sehingga
     * ber-guard `role:super_admin` — bukan kewenangan Pengurus. Aktor uji
     * disesuaikan menjadi Super Admin.
     */
    private function superAdmin(): User
    {
        return User::firstOrCreate(
            ['email' => 'superadmin.verifikasiganda@test.local'],
            ['name' => 'Super Admin Verifikasi Ganda', 'password' => Hash::make('password'), 'role' => 'super_admin']
        );
    }

    /** Ambil akun CoA apa adanya; buat bila belum ada. */
    private function akun(string $kode, string $nama, string $kategori, string $klasifikasi, string $tipe): Coa
    {
        return Coa::updateOrCreate(
            ['kode_akun' => $kode],
            [
                'nama_akun' => $nama,
                'kategori' => $kategori,
                'klasifikasi_neraca' => $klasifikasi,
                'tipe_saldo_normal' => $tipe,
                'saldo_awal' => 0,
                'is_active' => true,
            ]
        );
    }

    /** Bersihkan mutasi akun uji agar tiap test deterministik. */
    private function bersihkan(Coa ...$akunList): void
    {
        foreach ($akunList as $a) {
            JurnalDetail::where('coa_id', $a->id)->delete();
            $a->update(['saldo_awal' => 0]);
        }
    }

    /** Posting satu jurnal (satu baris) untuk sebuah akun. */
    private function jurnal(Coa $coa, float $debet = 0, float $kredit = 0, string $sumber = 'UJI-VG', string $status = 'Posted'): JurnalDetail
    {
        $j = Jurnal::create([
            'no_jurnal' => 'VG-'.uniqid(),
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => $sumber,
            'keterangan' => 'Uji verifikasi ganda CoA',
            'total_debet' => $debet,
            'total_kredit' => $kredit,
            'is_balance' => true,
            'status' => $status,
            'posted_at' => $status === 'Posted' ? now() : null,
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
     * Posting satu jurnal BERpasangan (debet & kredit dalam satu jurnal)
     * sehingga keseimbangan double-entry dapat diuji per jurnal.
     */
    private function jurnalPasangan(Coa $debet, Coa $kredit, float $nominal): Jurnal
    {
        $j = Jurnal::create([
            'no_jurnal' => 'VGP-'.uniqid(),
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'UJI-VG-PASANGAN',
            'keterangan' => 'Uji keseimbangan jurnal CoA',
            'total_debet' => $nominal,
            'total_kredit' => $nominal,
            'is_balance' => true,
            'status' => 'Posted',
            'posted_at' => now(),
        ]);

        JurnalDetail::create([
            'jurnal_id' => $j->id,
            'coa_id' => $debet->id,
            'baris' => 1,
            'debet' => $nominal,
            'kredit' => 0,
            'keterangan' => 'Uji sisi debet',
        ]);

        JurnalDetail::create([
            'jurnal_id' => $j->id,
            'coa_id' => $kredit->id,
            'baris' => 2,
            'debet' => 0,
            'kredit' => $nominal,
            'keterangan' => 'Uji sisi kredit',
        ]);

        return $j;
    }

    /* ======================= LAPIS 1 — KONSISTENSI ======================= */

    /**
     * Saldo hasil hitung ulang dari agregat SQL HARUS sama dengan
     * Coa::saldoBerjalan() untuk SETIAP akun, apa pun posisinya.
     */
    public function test_hitung_ulang_saldo_dari_jurnal_sama_dengan_jalur_model(): void
    {
        $kas = $this->akun('1-1001', 'Kas Teller', 'Aset', 'Aset Lancar', 'Debet');
        $simpanan = $this->akun('2-1001', 'Simpanan Sukarela', 'Kewajiban', 'Kewajiban Jangka Pendek', 'Kredit');
        $modal = $this->akun('3-1001', 'Simpanan Pokok', 'Ekuitas', 'Modal Anggota', 'Kredit');
        $ckpn = $this->akun('1-2100', 'Penyisihan Kerugian Piutang Pembiayaan (CKPN)', 'Aset', 'Aset Lancar', 'Kredit');

        $this->bersihkan($kas, $simpanan, $modal, $ckpn);

        $this->jurnal($kas, 5_000_000, 0);
        $this->jurnal($simpanan, 0, 5_000_000);
        $this->jurnal($modal, 0, 1_000_000);
        $this->jurnal($ckpn, 0, 250_000);
        foreach ([$kas, $simpanan, $modal, $ckpn] as $coa) {
            $coa->refresh();

            $d = round($coa->totalDebet(), 2);
            $k = round($coa->totalKredit(), 2);

            $hitungUlang = round(
                $coa->posisiSaldo() === 'Debet'
                    ? (float) $coa->saldo_awal + $d - $k
                    : (float) $coa->saldo_awal + $k - $d,
                2
            );

            $this->assertSame(
                $hitungUlang,
                round($coa->saldoBerjalan(), 2),
                "Saldo hitung ulang akun {$coa->kode_akun} harus sama dengan Coa::saldoBerjalan()."
            );
        }
    }

    /**
     * Akun kontra 1-2100 CKPN: kolom `tipe_saldo_normal` = 'Kredit' (disengaja),
     * namun POSISI EFEKTIF tetap 'Debet' (Kepala 1) sehingga saldonya NEGATIF
     * dan otomatis mengurangi total Aset.
     */
    public function test_akun_kontra_ckpn_dikenali_dan_mengurangi_total_aset(): void
    {
        $ckpn = $this->akun('1-2100', 'Penyisihan Kerugian Piutang Pembiayaan (CKPN)', 'Aset', 'Aset Lancar', 'Kredit');
        $this->bersihkan($ckpn);

        $this->jurnal($ckpn, 0, 400_000);
        $ckpn->refresh();

        $this->assertSame('Kredit', $ckpn->tipe_saldo_normal, 'Kolom label CKPN disengaja Kredit.');
        $this->assertSame('Debet', $ckpn->posisiSaldo(), 'Posisi efektif CKPN harus turunan Kepala 1 = Debet.');
        $this->assertTrue($ckpn->isKontra(), 'CKPN harus dikenali sebagai akun kontra.');
        $this->assertLessThan(0, $ckpn->saldoBerjalan(), 'Saldo CKPN harus negatif agar mengurangi Aset.');
        $this->assertSame(-400_000.0, round($ckpn->saldoBerjalan(), 2));
    }

    /**
     * Akun non-kontra TIDAK boleh ditandai sebagai kontra, meskipun kolom
     * mentahnya keliru diisi berlawanan (mis. Ekuitas bertipe 'Debet').
     */
    public function test_akun_non_kontra_tidak_ditandai_kontra(): void
    {
        $kas = $this->akun('1-1001', 'Kas Teller', 'Aset', 'Aset Lancar', 'Debet');
        $modal = $this->akun('3-1002', 'Simpanan Wajib', 'Ekuitas', 'Modal Anggota', 'Kredit');

        $this->assertFalse($kas->isKontra());
        $this->assertFalse($modal->isKontra());
    }

    /* ================== LAPIS 2 — KESEIMBANGAN NERACA ================== */

    /**
     * Σ debet = Σ kredit pada tingkat JURNAL (double-entry). Diuji pada
     * jurnal berpasangan yang dibuat test ini, agar tidak terpengaruh data
     * anggota lain yang dipakai bersama dalam transaksi database.
     */
    public function test_total_mutasi_jurnal_posted_seimbang(): void
    {
        $kas = $this->akun('1-1001', 'Kas Teller', 'Aset', 'Aset Lancar', 'Debet');
        $simpanan = $this->akun('2-1001', 'Simpanan Sukarela', 'Kewajiban', 'Kewajiban Jangka Pendek', 'Kredit');
        $ckpn = $this->akun('1-2100', 'Penyisihan Kerugian Piutang Pembiayaan (CKPN)', 'Aset', 'Aset Lancar', 'Kredit');
        $bebanCkpn = $this->akun('5-4001', 'Beban Penyisihan Kerugian Piutang Pembiayaan', 'Beban', 'Beban Usaha', 'Debet');

        /* Isolasi deterministik: buang mutasi CKPN yang sudah ada di basis
         | data uji bersama (dihapus DUA SISI agar buku tetap seimbang),
         | supaya totalKredit() akun kontra hanya mencerminkan jurnal uji. */
        $this->bersihkan($ckpn, $bebanCkpn);

        // Setoran simpanan: Debet Kas / Kredit Simpanan Sukarela.
        $setoran = $this->jurnalPasangan($kas, $simpanan, 3_000_000.0);

        // Pembentukan CKPN: Debet Beban CKPN / Kredit Penyisihan (kontra-aset).
        $pembentukan = $this->jurnalPasangan($bebanCkpn, $ckpn, 300_000.0);

        foreach ([$setoran, $pembentukan] as $j) {
            $debet = round((float) JurnalDetail::where('jurnal_id', $j->id)->sum('debet'), 2);
            $kredit = round((float) JurnalDetail::where('jurnal_id', $j->id)->sum('kredit'), 2);

            $this->assertSame(
                $debet,
                $kredit,
                'Setiap jurnal Posted harus seimbang: Σ debet = Σ kredit.'
            );

            $this->assertSame(
                round((float) $j->total_debet, 2),
                round((float) $j->total_kredit, 2),
                'Header jurnal harus mencatat total debet = total kredit.'
            );
        }

        // Akun kontra CKPN menampung sisi Kredit, bukan Debet.
        $this->assertSame(300_000.0, round((float) $ckpn->refresh()->totalKredit(), 2));
        $this->assertSame(0.0, round((float) $ckpn->totalDebet(), 2));
    }

    /**
     * Endpoint verifikasi jurnal: harus mengembalikan hasil verifikasi ganda
     * yang menyatakan neraca saldo seimbang dan tanpa anomali.
     */
    public function test_verifikasi_ganda_menghasilkan_neraca_seimbang(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('pengurus.coa.verify'))
            ->assertRedirect();

        $hasil = session('verify_result');

        $this->assertIsArray($hasil, 'Hasil verifikasi ganda harus dikirim ke view.');
        $this->assertTrue($hasil['mutasi_seimbang'], 'Σ mutasi Debet harus sama dengan Σ mutasi Kredit.');
        $this->assertTrue($hasil['neraca_seimbang'], 'Neraca saldo harus seimbang.');
        $this->assertSame([], $hasil['anomali'], 'Tidak boleh ada anomali pencatatan.');
        $this->assertSame(
            round($hasil['total_saldo_debet'], 2),
            round($hasil['total_saldo_kredit'], 2),
            'Total saldo pos Debet harus sama dengan total saldo pos Kredit.'
        );
    }

    /**
     * Neraca saldo tidak boleh memuat akun 1-2100 pada kelompok Kredit —
     * akun kontra harus ikut kelompok posisinya (Debet) dan bernilai negatif.
     */
    public function test_akun_kontra_dilaporkan_pada_posisi_debet_dengan_saldo_negatif(): void
    {
        $ckpn = $this->akun('1-2100', 'Penyisihan Kerugian Piutang Pembiayaan (CKPN)', 'Aset', 'Aset Lancar', 'Kredit');
        $this->bersihkan($ckpn);
        $this->jurnal($ckpn, 0, 150_000);

        $this->actingAs($this->superAdmin())
            ->post(route('pengurus.coa.verify'))
            ->assertRedirect();

        $baris = collect(session('verify_result')['baris'])
            ->firstWhere('kode', '1-2100');

        $this->assertNotNull($baris, 'Akun 1-2100 harus muncul pada hasil verifikasi.');
        $this->assertSame('Debet', $baris['posisi'], 'Posisi laporan 1-2100 harus Debet (turunan Kepala 1).');
        $this->assertTrue($baris['kontra'], 'Akun 1-2100 harus ditandai kontra.');
        $this->assertTrue($baris['cocok'], 'Hitung ulang 1-2100 harus cocok dengan jalur model.');
        $this->assertLessThan(0, $baris['saldo'], 'Saldo 1-2100 harus negatif (mengurangi Aset).');
    }

    /**
     * Halaman CoA harus menampilkan hasil verifikasi ganda (panel render).
     */
    public function test_halaman_coa_menampilkan_panel_hasil_verifikasi(): void
    {
        $this->actingAs($this->superAdmin())
            ->followingRedirects()
            ->post(route('pengurus.coa.verify'))
            ->assertOk()
            ->assertSee('Hasil Verifikasi Ganda');
    }

    /* ============ AUDIT AKURASI DATA — BASIS MUTASI ============ */

    /**
     * Label "(N mutasi)" pada halaman CoA HARUS memakai basis yang sama dengan
     * saldo berjalan, yaitu hanya baris jurnal berstatus 'Posted'.
     *
     * Regresi: sebelumnya `withCount('jurnalDetail')` menghitung SELURUH baris
     * (termasuk Draft), sehingga jumlah mutasi yang ditampilkan tidak konsisten
     * dengan saldo yang benar-benar dibentuk jurnal Posted.
     */
    public function test_jumlah_mutasi_hanya_menghitung_jurnal_posted(): void
    {
        $kas = $this->akun('1-1001', 'Kas Teller', 'Aset', 'Aset Lancar', 'Debet');
        $this->bersihkan($kas);

        // Satu jurnal Posted (membentuk saldo) + satu jurnal Draft (belum posting).
        $this->jurnal($kas, 1_000_000, 0);
        $this->jurnal($kas, 500_000, 0, 'UJI-VG-DRAFT', 'Draft');

        // Sisi data mentah: dua baris jurnal_detail memang ada.
        $this->assertSame(2, $kas->jurnalDetail()->count(), 'Ada 2 baris jurnal_detail (Posted + Draft).');
        $this->assertSame(
            1,
            $kas->jurnalDetail()->whereHas('jurnal', fn ($q) => $q->where('status', 'Posted'))->count(),
            'Hanya 1 baris jurnal_detail yang Posted.'
        );

        // Sisi tampilan: jumlah mutasi yang dipakai halaman harus 1 (Posted saja).
        $res = $this->actingAs($this->superAdmin())->get(route('pengurus.coa.index'));
        $res->assertOk();

        $baris = collect($res->viewData()['grouped'])->flatten(1)->firstWhere('kode_akun', '1-1001');

        $this->assertNotNull($baris, 'Akun 1-1001 harus muncul pada data halaman CoA.');
        $this->assertSame(
            1,
            (int) $baris->jurnal_detail_count,
            'Jumlah mutasi pada halaman CoA harus hanya menghitung jurnal Posted.'
        );
        $this->assertSame(
            1_000_000.0,
            round((float) $baris->saldoBerjalan(), 2),
            'Saldo berjalan harus hanya dibentuk jurnal Posted (Draft diabaikan).'
        );
    }

    /* ============ LAPIS 2 — PERSAMAAN DASAR AKUNTANSI ============ */

    /**
     * Aset = Kewajiban + Dana Syirkah Temporer + Ekuitas + Laba/Rugi Berjalan.
     * Akun kontra CKPN harus benar-benar mengurangi total Aset.
     */
    public function test_persamaan_dasar_akuntansi_terpenuhi(): void
    {
        $app = app(LaporanKeuanganSyariahService::class);

        $periode = [
            'sampaiStr' => now()->toDateString(),
            'sampaiLaluStr' => now()->subYear()->toDateString(),
        ];

        $posisi = $app->posisiKeuangan($periode);

        $hitungUlangPasiva = round(
            $posisi['totalKewajiban'] + $posisi['totalDanaSyirkahTemporer']
            + $posisi['totalEkuitas'] + $posisi['labaBersih'],
            2
        );

        $this->assertSame(
            round($posisi['totalAset'], 2),
            $hitungUlangPasiva,
            'Aset harus sama dengan Kewajiban + DST + Ekuitas + Laba/Rugi berjalan.'
        );

        $this->assertTrue(
            $posisi['seimbang'],
            'Laporan Posisi Keuangan harus berstatus seimbang.'
        );
    }
}
