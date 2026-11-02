<?php

namespace Tests\Feature;

use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\User;
use App\Services\IkhtisarShuService;
use App\Support\Coa as CoaKode;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Pengujian perbaikan Laporan Neraca & akun 3-9000 (Ikhtisar SHU):
 *
 *  A. Pengaman balance jurnal — jurnal Posted yang tidak balance harus DITOLAK
 *     (RuntimeException) baik lewat pastikanBalance() maupun lewat jalur UPDATE.
 *  B. Pembersihan saldo minus 3-9000 — preview mendeteksi pencatatan gantung,
 *     eksekusi membuat jurnal penyesuaian Debet 3-2001 / Kredit 3-9000 yang
 *     BALANCE dan menolkan saldo, serta IDEMPOTEN saat dijalankan ulang.
 *  C. Tutup Buku — memindahkan Pendapatan & Beban ke 3-9000 secara presisi,
 *     idempoten, dan selalu balance.
 *  D. Neraca — Total Aset = Total Pasiva (selisih 0,00) dan Laba/Rugi Berjalan
 *     tampil pada rincian Pasiva dengan badge status.
 */
class NeracaIkhtisarShuPerbaikanTest extends TestCase
{
    use DatabaseTransactions;

    private function pengurus(): User
    {
        return User::firstOrCreate(
            ['email' => 'pengurus.neraca.perbaikan@test.local'],
            ['name' => 'Pengurus Neraca Perbaikan', 'password' => Hash::make('password'), 'role' => 'pengurus']
        );
    }

    /**
     * Halaman & endpoint CoA (ikhtisar SHU, verifikasi, tutup buku) adalah
     * KONFIGURASI SISTEM ber-guard `role:super_admin`, bukan kewenangan
     * Pengurus — lihat prinsip Segregation of Duties.
     */
    private function superAdmin(): User
    {
        return User::firstOrCreate(
            ['email' => 'superadmin.neraca.perbaikan@test.local'],
            ['name' => 'Super Admin Neraca Perbaikan', 'password' => Hash::make('password'), 'role' => 'super_admin']
        );
    }

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

    /** Hapus mutasi akun uji agar tiap test mulai dari kondisi bersih. */
    private function bersihkan(Coa ...$akunList): void
    {
        foreach ($akunList as $a) {
            JurnalDetail::where('coa_id', $a->id)->delete();
            $a->update(['saldo_awal' => 0]);
        }
    }

    /** Nolkan seluruh akun Pendapatan & Beban agar agregat Tutup Buku deterministik. */
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

    /** Posting satu baris jurnal Posted untuk sebuah akun. */
    private function jurnal(Coa $coa, float $debet = 0, float $kredit = 0, string $sumber = 'UJI'): JurnalDetail
    {
        $j = Jurnal::create([
            'no_jurnal' => 'UJI-'.uniqid(),
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => $sumber,
            'keterangan' => 'Uji neraca perbaikan',
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
     * Posting jurnal dua sisi (Debet akun A, Kredit akun B).
     *
     * Dipakai pada uji Neraca: buku besar harus tetap seimbang secara GLOBAL,
     * sehingga menambah mutasi satu sisi saja akan membuat selisih Neraca
     * palsu yang bukan berasal dari rumus yang diuji.
     */
    private function jurnalPasangan(Coa $debet, Coa $kredit, float $nominal, string $sumber = 'UJI'): Jurnal
    {
        $j = Jurnal::create([
            'no_jurnal' => 'UJI-PAS-'.uniqid(),
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => $sumber,
            'keterangan' => 'Uji neraca berpasangan',
            'total_debet' => $nominal,
            'total_kredit' => $nominal,
            'is_balance' => true,
            'status' => 'Posted',
            'posted_at' => now(),
        ]);

        JurnalDetail::create(['jurnal_id' => $j->id, 'coa_id' => $debet->id, 'baris' => 1, 'debet' => $nominal, 'kredit' => 0]);
        JurnalDetail::create(['jurnal_id' => $j->id, 'coa_id' => $kredit->id, 'baris' => 2, 'debet' => 0, 'kredit' => $nominal]);

        return $j;
    }

    /* ==================================================================
     | A. PENGAMAN BALANCE JURNAL
     ================================================================== */

    public function test_pastikan_balance_menolak_jurnal_tidak_balance(): void
    {
        $this->siapkanPendapatanBeban();

        $akun = $this->akun('1-9021', 'Kas Uji Balance');
        $this->bersihkan($akun);

        $jurnal = Jurnal::create([
            'no_jurnal' => 'UJI-NB-'.uniqid(),
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'UJI',
            'keterangan' => 'Uji jurnal tidak balance',
            'total_debet' => 1_000_000,
            'total_kredit' => 0,
            'is_balance' => true,
            'status' => 'Posted',
            'posted_at' => now(),
        ]);

        // Hanya sisi debet yang ditulis → jurnal tidak balance.
        JurnalDetail::create([
            'jurnal_id' => $jurnal->id,
            'coa_id' => $akun->id,
            'baris' => 1,
            'debet' => 1_000_000,
            'kredit' => 0,
        ]);

        $this->expectException(RuntimeException::class);

        $jurnal->pastikanBalance();
    }

    public function test_pastikan_balance_menerima_jurnal_balance_dan_menyimpan_totals(): void
    {
        $this->siapkanPendapatanBeban();

        $kas = $this->akun('1-9022', 'Kas Uji Balance OK');
        $this->bersihkan($kas);

        $jurnal = Jurnal::create([
            'no_jurnal' => 'UJI-B-'.uniqid(),
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'UJI',
            'keterangan' => 'Uji jurnal balance',
            'total_debet' => 0,
            'total_kredit' => 0,
            'is_balance' => false,
            'status' => 'Posted',
            'posted_at' => now(),
        ]);

        JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $kas->id, 'baris' => 1, 'debet' => 750_000, 'kredit' => 0]);
        JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $kas->id, 'baris' => 2, 'debet' => 0, 'kredit' => 750_000]);

        $hasil = $jurnal->pastikanBalance();

        $this->assertEqualsWithDelta(750_000, (float) $hasil->total_debet, 0.01);
        $this->assertEqualsWithDelta(750_000, (float) $hasil->total_kredit, 0.01);
        $this->assertTrue((bool) $hasil->is_balance);
    }

    public function test_pastikan_balance_menolak_jurnal_tanpa_detail(): void
    {
        $jurnal = Jurnal::create([
            'no_jurnal' => 'UJI-KOSONG-'.uniqid(),
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'UJI',
            'keterangan' => 'Uji jurnal tanpa detail',
            'total_debet' => 0,
            'total_kredit' => 0,
            'is_balance' => true,
            'status' => 'Posted',
            'posted_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);

        $jurnal->pastikanBalance();
    }

    /* ==================================================================
     | B. PEMBERSIHAN SALDO MINUS 3-9000
     ================================================================== */

    public function test_preview_pembersihan_mendeteksi_saldo_minus_3_9000(): void
    {
        $this->siapkanPendapatanBeban();

        $shu = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $cadangan = $this->akun('3-2001', 'Dana Cadangan Koperasi', 'Ekuitas', 'Dana Cadangan');
        $this->bersihkan($shu, $cadangan);

        // Pencatatan gantung: DEBET 3-9000 tanpa jurnal penutup lawan.
        $this->jurnal($shu, 103_000_000, 0, 'SHU');

        $pratinjau = app(IkhtisarShuService::class)->pratinjauBersihkan();

        $this->assertTrue($pratinjau['perlu_dibersihkan'], 'Saldo minus harus terdeteksi perlu dibersihkan.');
        $this->assertEqualsWithDelta(-103_000_000, $pratinjau['saldo'], 0.01);
        $this->assertSame('debet', $pratinjau['arah'], 'Saldo minus wajib dilaporkan sebagai saldo DEBET menggantung.');
        $this->assertNotEmpty($pratinjau['rincian'], 'Rincian mutasi penyebab minus harus tersedia untuk penelusuran.');
    }

    public function test_pembersihan_menolkan_saldo_3_9000_dengan_jurnal_balance(): void
    {
        $this->siapkanPendapatanBeban();

        $shu = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $cadangan = $this->akun('3-2001', 'Dana Cadangan Koperasi', 'Ekuitas', 'Dana Cadangan');
        $this->bersihkan($shu, $cadangan);

        $this->jurnal($shu, 103_000_000, 0, 'SHU');
        $this->assertEqualsWithDelta(-103_000_000, $shu->fresh()->saldoBerjalan(), 0.01);

        $hasil = app(IkhtisarShuService::class)->bersihkan();

        $this->assertEqualsWithDelta(103_000_000, $hasil['jumlah'], 0.01, 'Nominal pembersihan harus sebesar |saldo minus|.');
        $this->assertNotNull($hasil['jurnal']);

        // Saldo 3-9000 kembali Rp 0.
        $this->assertEqualsWithDelta(0, $shu->fresh()->saldoBerjalan(), 0.01, 'Saldo 3-9000 harus kembali Rp 0 setelah dibersihkan.');

        // Jurnal penyesuaian harus balance: Debet 3-2001 / Kredit 3-9000.
        $jurnal = $hasil['jurnal']->fresh()->load('detail');
        $this->assertEqualsWithDelta(
            (float) $jurnal->detail->sum('debet'),
            (float) $jurnal->detail->sum('kredit'),
            0.01,
            'Jurnal penyesuaian pembersihan wajib balance.'
        );
        $this->assertTrue((bool) $jurnal->is_balance);

        $barisCadangan = $jurnal->detail->firstWhere('coa_id', $cadangan->id);
        $barisIkhtisar = $jurnal->detail->firstWhere('coa_id', $shu->id);

        $this->assertNotNull($barisCadangan, 'Baris Debet ke 3-2001 harus ada.');
        $this->assertNotNull($barisIkhtisar, 'Baris Kredit ke 3-9000 harus ada.');
        $this->assertEqualsWithDelta(103_000_000, (float) $barisCadangan->debet, 0.01);
        $this->assertEqualsWithDelta(103_000_000, (float) $barisIkhtisar->kredit, 0.01);
    }

    public function test_pembersihan_bersifat_idempoten(): void
    {
        $this->siapkanPendapatanBeban();

        $shu = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $cadangan = $this->akun('3-2001', 'Dana Cadangan Koperasi', 'Ekuitas', 'Dana Cadangan');
        $this->bersihkan($shu, $cadangan);

        $this->jurnal($shu, 50_000_000, 0, 'SHU');

        $pertama = app(IkhtisarShuService::class)->bersihkan();
        $this->assertEqualsWithDelta(50_000_000, $pertama['jumlah'], 0.01);

        $kedua = app(IkhtisarShuService::class)->bersihkan();

        $this->assertEqualsWithDelta(0.0, $kedua['jumlah'], 0.01, 'Pembersihan kedua tidak boleh membuat jurnal baru.');
        $this->assertNull($kedua['jurnal']);
        $this->assertEqualsWithDelta(0, $shu->fresh()->saldoBerjalan(), 0.01, 'Saldo 3-9000 harus tetap Rp 0.');
    }

    public function test_saldo_positif_3_9000_tidak_dibersihkan(): void
    {
        $this->siapkanPendapatanBeban();

        $shu = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $cadangan = $this->akun('3-2001', 'Dana Cadangan Koperasi', 'Ekuitas', 'Dana Cadangan');
        $this->bersihkan($shu, $cadangan);

        // Saldo positif = laba berjalan yang sah → tidak boleh disentuh.
        $this->jurnal($shu, 0, 30_000_000, 'TutupBuku');

        $pratinjau = app(IkhtisarShuService::class)->pratinjauBersihkan();
        $this->assertFalse($pratinjau['perlu_dibersihkan'], 'Saldo positif harus dibiarkan untuk dipindahkan Tutup Buku.');

        $hasil = app(IkhtisarShuService::class)->bersihkan();
        $this->assertEqualsWithDelta(0.0, $hasil['jumlah'], 0.01);
        $this->assertEqualsWithDelta(30_000_000, $shu->fresh()->saldoBerjalan(), 0.01, 'Saldo positif tidak boleh berubah.');
    }

    /* ==================================================================
     | C. TUTUP BUKU PRESISI & IDEMPOTEN
     ================================================================== */

    public function test_tutup_buku_memindahkan_pendapatan_dan_beban_ke_3_9000(): void
    {
        $akunUji = $this->siapkanPendapatanBeban(['4-9031' => 'Pendapatan Uji Tutup Buku']);

        $shu = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $this->bersihkan($shu);

        $this->jurnal($akunUji['4-9031'], 0, 25_000_000);
        $this->jurnal($this->akun('5-9032', 'Beban Uji Tutup Buku'), 9_500_000, 0);

        $labaSebelum = round(25_000_000 - 9_500_000, 2);

        $hasil = app(IkhtisarShuService::class)->tutupBuku();

        $this->assertFalse($hasil['sudah_ditutup']);
        $this->assertEqualsWithDelta($labaSebelum, $hasil['selisih_shu'], 0.01, 'SHU harus tepat Pendapatan − Beban.');
        $this->assertSame('untung', $hasil['status']);

        // Akun 3-9000 bertambah tepat sebesar laba bersih.
        $this->assertEqualsWithDelta($labaSebelum, $shu->fresh()->saldoBerjalan(), 0.01);

        // Saldo Pendapatan & Beban kembali nol setelah penutupan.
        $this->assertEqualsWithDelta(0, $akunUji['4-9031']->fresh()->saldoBerjalan(), 0.01);
        $this->assertEqualsWithDelta(0, $this->akun('5-9032', 'Beban Uji Tutup Buku')->fresh()->saldoBerjalan(), 0.01);

        $jurnal = $hasil['jurnal']->fresh()->load('detail');
        $this->assertEqualsWithDelta(
            (float) $jurnal->detail->sum('debet'),
            (float) $jurnal->detail->sum('kredit'),
            0.01,
            'Jurnal penutup wajib balance.'
        );
    }

    public function test_tutup_buku_kedua_kali_tidak_menggandakan_shu(): void
    {
        $akunUji = $this->siapkanPendapatanBeban(['4-9033' => 'Pendapatan Uji Idempoten']);

        $shu = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $this->bersihkan($shu);

        $this->jurnal($akunUji['4-9033'], 0, 12_000_000);

        $pertama = app(IkhtisarShuService::class)->tutupBuku();
        $saldoSetelahPertama = $shu->fresh()->saldoBerjalan();

        $kedua = app(IkhtisarShuService::class)->tutupBuku();

        $this->assertTrue($kedua['sudah_ditutup'], 'Tutup Buku kedua harus dikenali sebagai sudah ditutup.');
        $this->assertNull($kedua['jurnal'], 'Tutup Buku kedua tidak boleh membuat jurnal baru.');
        $this->assertSame(0, $kedua['jumlah_baris']);
        $this->assertEqualsWithDelta(
            $saldoSetelahPertama,
            $shu->fresh()->saldoBerjalan(),
            0.01,
            'Saldo 3-9000 tidak boleh berubah pada Tutup Buku kedua.'
        );

        // Tidak ada dua jurnal penutup untuk tanggal yang sama.
        $jumlahPenutup = Jurnal::where('sumber', 'TutupBuku')
            ->where('no_jurnal', $pertama['jurnal']->no_jurnal)
            ->count();
        $this->assertSame(1, $jumlahPenutup);
    }

    public function test_tutup_buku_rugi_mendebet_3_9000(): void
    {
        $this->siapkanPendapatanBeban();

        $shu = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $this->bersihkan($shu);

        $this->jurnal($this->akun('5-9034', 'Beban Uji Rugi Tutup Buku'), 8_000_000, 0);

        $hasil = app(IkhtisarShuService::class)->tutupBuku();

        $this->assertFalse($hasil['sudah_ditutup']);
        $this->assertSame('rugi', $hasil['status'], 'Beban > Pendapatan harus berstatus rugi.');
        $this->assertEqualsWithDelta(-8_000_000, $shu->fresh()->saldoBerjalan(), 0.01, 'Rugi harus mendebet 3-9000.');
    }

    /* ==================================================================
     | D. NERACA: TOTAL ASET = TOTAL PASIVA & LABA/RUGI BERJALAN
     ================================================================== */

    public function test_neraca_menghasilkan_selisih_nol_dengan_laba_berjalan_negatif(): void
    {
        /* Beban melebihi pendapatan → laba berjalan negatif; inilah kondisi yang
         | dahulu memunculkan "selisih Rp 777.499" pada Neraca.
         |
         | Jurnal dibuat BERPASANGAN dengan Kas: buku besar harus tetap seimbang
         | secara global, supaya yang diuji benar-benar rumus Neraca dan bukan
         | selisih akibat baris jurnal yang sengaja diputus sebelah. */
        $kas = $this->akun('1-9035', 'Kas Uji Neraca Selisih', 'Aset', 'Aset Lancar');

        $this->jurnalPasangan(
            $this->akun('5-9035', 'Beban Uji Neraca Selisih'),
            $kas,
            777_498.66
        );

        $response = $this->actingAs($this->pengurus())
            ->get(route('laporan.neraca'))
            ->assertOk();

        $this->assertEqualsWithDelta(0.0, $response->viewData('selisihNeraca'), 0.01, 'Neraca wajib balance tanpa selisih.');
        $this->assertEqualsWithDelta(
            $response->viewData('totalAset'),
            $response->viewData('totalPasiva'),
            0.01,
            'Total Aset harus sama dengan Total Pasiva.'
        );
        $this->assertLessThan(0, $response->viewData('labaBersih'), 'Prasyarat uji: laba berjalan harus negatif.');
        $this->assertSame('rugi', $response->viewData('statusLaba'));
    }

    public function test_neraca_menampilkan_laba_rugi_berjalan_pada_rincian_pasiva(): void
    {
        /* Pendapatan bertambah lawan Kas agar buku tetap seimbang;
         | Laba/Rugi Berjalan = Pendapatan − Beban akan tampil positif. */
        $kas = $this->akun('1-9036', 'Kas Uji Tampil Neraca', 'Aset', 'Aset Lancar');

        /* Isolasi deterministik: buang jurnal penyesuaian CKPN (Debet 5-4001 /
         | Kredit 1-2100) yang tersisa di basis data uji bersama — dihapus DUA
         | SISI agar buku tetap seimbang — sehingga Beban global tidak
         | menenggelamkan pendapatan uji dan status "untung" dapat diuji. */
        $ckpnKontra = Coa::findByKode(CoaKode::CKPN_PIUTANG_PEMBIAYAAN);
        if ($ckpnKontra) {
            $jurnalCkpn = JurnalDetail::where('coa_id', $ckpnKontra->id)->pluck('jurnal_id');
            JurnalDetail::whereIn('jurnal_id', $jurnalCkpn)->delete();
        }

        $this->jurnalPasangan(
            $kas,
            $this->akun('4-9036', 'Pendapatan Uji Tampil Neraca'),
            4_250_000
        );

        $response = $this->actingAs($this->pengurus())
            ->get(route('laporan.neraca', ['mode' => 'tanggal', 'tanggal' => now()->toDateString()]))
            ->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('Laba/Rugi Berjalan', $html, 'Rincian Pasiva harus memuat baris Laba/Rugi Berjalan.');
        $this->assertStringContainsString('TOTAL PASIVA', $html, 'Neraca harus menampilkan Total Pasiva.');

        $this->assertEqualsWithDelta(0.0, $response->viewData('selisihNeraca'), 0.01);
        $this->assertSame('untung', $response->viewData('statusLaba'));
    }

    public function test_neraca_menandai_akun_ekuitas_bersaldo_minus(): void
    {
        /* Saldo 3-9000 dibuat minus lewat pencatatan gantung (Debet 3-9000 lawan
         | Kas) — kondisi nyata yang harus terbaca di Neraca, bukan disembunyikan. */
        $kas = $this->akun('1-9037', 'Kas Uji Badge Minus', 'Aset', 'Aset Lancar');

        $this->jurnalPasangan(
            $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan'),
            $kas,
            103_000_000,
            'SHU'
        );

        $response = $this->actingAs($this->pengurus())
            ->get(route('laporan.neraca'))
            ->assertOk();

        $this->assertLessThan(
            0,
            $response->viewData('totalEkuitas'),
            'Prasyarat uji: Total Ekuitas harus terbaca minus, bukan disembunyikan sebagai 0.'
        );

        $html = $response->getContent();
        $this->assertStringContainsString('Saldo Minus', $html, 'Akun Ekuitas bersaldo minus harus diberi badge peringatan.');
        $this->assertStringContainsString('3-9000', $html);
    }

    /* ==================================================================
     | E. ENDPOINT PREVIEW & RESET IKTISAR SHU
     ================================================================== */

    public function test_endpoint_preview_tidak_mengubah_data(): void
    {
        $this->siapkanPendapatanBeban();

        $shu = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $cadangan = $this->akun('3-2001', 'Dana Cadangan Koperasi', 'Ekuitas', 'Dana Cadangan');
        $this->bersihkan($shu, $cadangan);

        $this->jurnal($shu, 103_000_000, 0, 'SHU');
        $jumlahJurnalSebelum = Jurnal::count();

        $this->actingAs($this->superAdmin())
            ->post(route('pengurus.coa.ikhtisar-shu.preview'))
            ->assertRedirect(route('pengurus.coa.index'))
            ->assertSessionHas('ikhtisar_preview');

        $this->assertSame($jumlahJurnalSebelum, Jurnal::count(), 'Preview tidak boleh menambah jurnal.');
        $this->assertEqualsWithDelta(-103_000_000, $shu->fresh()->saldoBerjalan(), 0.01, 'Preview tidak boleh mengubah saldo.');
    }

    public function test_endpoint_reset_menolkan_saldo_3_9000(): void
    {
        $this->siapkanPendapatanBeban();

        $shu = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $cadangan = $this->akun('3-2001', 'Dana Cadangan Koperasi', 'Ekuitas', 'Dana Cadangan');
        $this->bersihkan($shu, $cadangan);

        $this->jurnal($shu, 103_000_000, 0, 'SHU');

        $this->actingAs($this->superAdmin())
            ->post(route('pengurus.coa.ikhtisar-shu.reset'))
            ->assertRedirect(route('pengurus.coa.index'))
            ->assertSessionHas('success');

        $this->assertEqualsWithDelta(0, $shu->fresh()->saldoBerjalan(), 0.01, 'Reset harus menolkan saldo 3-9000.');
    }

    public function test_tombol_perbaiki_akun_tersedia_di_halaman_coa(): void
    {
        $html = $this->actingAs($this->superAdmin())
            ->get(route('pengurus.coa.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Perbaiki Akun 3-9000', $html, 'Tombol perbaikan akun 3-9000 harus tersedia.');
        $this->assertStringContainsString(
            route('pengurus.coa.ikhtisar-shu.preview'),
            $html,
            'Tombol harus mengarah ke endpoint preview.'
        );
    }

    /* ==================================================================
     | F. UJI ULANG NERACA PASCA-PEMBERSIHAN & REKONSILIASI LINTAS LAPORAN
     ================================================================== */

    /**
     * Kondisi nyata temuan: distribusi SHU + alokasi Dana Cadangan mendebet
     * 3-9000 tanpa didahului jurnal penutup laba, sehingga saldo 3-9000
     * menggantung minus Rp 103.000.000 dan menekan Total Ekuitas.
     *
     * Setelah pembersihan dijalankan:
     *   - saldo 3-9000 kembali normal (tidak minus),
     *   - Neraca tetap BALANCE (Aset = Pasiva),
     *   - Total Ekuitas TIDAK berubah (penyesuaian hanya memindahkan nilai
     *     antar-akun Ekuitas: Debet 3-2001 / Kredit 3-9000).
     */
    public function test_neraca_tetap_balance_dan_ekuitas_tidak_berubah_setelah_pembersihan_3_9000(): void
    {
        /* Jurnal dibuat BERPASANGAN dengan Kas agar buku besar tetap seimbang
         | secara global — supaya yang diuji benar-benar Neraca, bukan selisih
         | akibat baris jurnal yang sengaja diputus sebelah. */
        $shu = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $cadangan = $this->akun('3-2001', 'Dana Cadangan Koperasi', 'Ekuitas', 'Dana Cadangan');
        $kas = $this->akun('1-1001', 'Kas Teller', 'Aset', 'Aset Lancar');

        $saldoShuAwal = $shu->fresh()->saldoBerjalan();
        $saldoCadanganAwal = $cadangan->fresh()->saldoBerjalan();

        // Pencatatan gantung: DEBET 3-9000 tanpa jurnal penutup laba lawan.
        $this->jurnalPasangan($shu, $kas, 103_000_000, 'SHU');

        $this->assertEqualsWithDelta(
            $saldoShuAwal - 103_000_000,
            $shu->fresh()->saldoBerjalan(),
            0.01,
            'Prasyarat uji: saldo 3-9000 harus turun Rp 103.000.000.'
        );

        $sebelum = $this->actingAs($this->pengurus())
            ->get(route('laporan.neraca'))
            ->assertOk()
            ->viewData();

        $this->assertEqualsWithDelta(
            0.0,
            $sebelum['selisihNeraca'],
            0.01,
            'Neraca harus tetap balance walaupun 3-9000 bersaldo minus.'
        );

        $pratinjau = app(IkhtisarShuService::class)->pratinjauBersihkan();
        $this->assertTrue($pratinjau['perlu_dibersihkan'], 'Saldo minus 3-9000 harus terdeteksi.');
        $nominal = round(abs($pratinjau['saldo']), 2);

        // Eksekusi: logika yang SAMA dengan tombol "Perbaiki Akun 3-9000".
        $hasil = app(IkhtisarShuService::class)->bersihkan();
        $this->assertEqualsWithDelta($nominal, $hasil['jumlah'], 0.01, 'Nominal pembersihan harus sebesar |saldo minus|.');

        // 1. Saldo 3-9000 sudah normal (tidak lagi minus).
        $this->assertGreaterThanOrEqual(
            -0.01,
            $shu->fresh()->saldoBerjalan(),
            'Saldo 3-9000 tidak boleh lagi minus setelah pembersihan.'
        );

        // 2. Akun lawan penyesuaian menyerap nilai yang sama.
        $this->assertEqualsWithDelta(
            $saldoCadanganAwal - $nominal,
            $cadangan->fresh()->saldoBerjalan(),
            0.01,
            'Penyesuaian harus terpindah ke akun lawan (Dana Cadangan) dengan nilai yang sama.'
        );

        $sesudah = $this->actingAs($this->pengurus())
            ->get(route('laporan.neraca'))
            ->assertOk()
            ->viewData();

        // 3. Neraca tetap memenuhi syarat keseimbangan akuntansi.
        $this->assertEqualsWithDelta(0.0, $sesudah['selisihNeraca'], 0.01, 'Neraca wajib tetap balance.');
        $this->assertEqualsWithDelta(
            $sesudah['totalAset'],
            $sesudah['totalPasiva'],
            0.01,
            'Total Aset harus sama dengan Total Pasiva.'
        );

        // 4. Penyesuaian antar-akun Ekuitas tidak boleh mengubah Total Ekuitas.
        $this->assertEqualsWithDelta(
            $sebelum['totalEkuitas'],
            $sesudah['totalEkuitas'],
            0.01,
            'Pembersihan 3-9000 tidak boleh mengubah Total Ekuitas secara tidak wajar.'
        );
        $this->assertEqualsWithDelta(
            $sebelum['totalAset'],
            $sesudah['totalAset'],
            0.01,
            'Pembersihan 3-9000 tidak boleh menyentuh sisi Aset.'
        );
    }

    /**
     * Rekonsiliasi lintas laporan setelah pembersihan:
     *   - Neraca  : Total Aset = Total Pasiva = Kewajiban + DST + Ekuitas + Laba,
     *   - Laba Rugi: Laba Berjalan = Pendapatan − Beban,
     *   - Perubahan Ekuitas: total akun Ekuitas = Total Ekuitas Neraca.
     * Tidak boleh ada selisih saldo pada laporan mana pun.
     */
    public function test_rekonsiliasi_laba_rugi_dan_perubahan_ekuitas_pasca_pembersihan(): void
    {
        $shu = $this->akun('3-9000', 'Ikhtisar SHU / Laba Ditahan');
        $cadangan = $this->akun('3-2001', 'Dana Cadangan Koperasi', 'Ekuitas', 'Dana Cadangan');
        $kas = $this->akun('1-1002', 'Kas Uji Rekonsiliasi', 'Aset', 'Aset Lancar');

        $this->jurnalPasangan($shu, $kas, 103_000_000, 'SHU');
        app(IkhtisarShuService::class)->bersihkan();

        $data = $this->actingAs($this->pengurus())
            ->get(route('laporan.neraca'))
            ->assertOk()
            ->viewData();
        $sampai = $data['sampai'];

        // 1. Neraca: keseimbangan dasar.
        $this->assertEqualsWithDelta(0.0, $data['selisihNeraca'], 0.01, 'Selisih Neraca harus Rp 0.');
        $this->assertEqualsWithDelta($data['totalAset'], $data['totalPasiva'], 0.01);

        // 2. Laporan Laba Rugi: laba berjalan = Pendapatan − Beban.
        $this->assertEqualsWithDelta(
            round($data['totalPendapatan'] - $data['totalBeban'], 2),
            $data['labaBersih'],
            0.01,
            'Laba/Rugi Berjalan pada Neraca harus konsisten dengan Laporan Laba Rugi.'
        );
        $this->assertEqualsWithDelta(
            Coa::labaBersihBerjalan($sampai),
            $data['labaBersih'],
            0.01,
            'Laba bersih Neraca harus sama dengan agregat akun Pendapatan & Beban.'
        );

        // 3. Laporan Perubahan Ekuitas: total akun Ekuitas = Total Ekuitas Neraca.
        //    Keduanya memakai basis penyajian laporan — jurnal penutup
        //    dikecualikan agar laba periode tidak dihitung dua kali
        //    (3-9000 + Laba Berjalan).
        $ekuitas = Coa::where('kategori', 'Ekuitas')->where('is_active', true)->get();
        $this->assertEqualsWithDelta(
            round((float) $ekuitas->sum(fn ($c) => round($c->saldoBerjalanLaporan($sampai), 2)), 2),
            $data['totalEkuitas'],
            0.01,
            'Perubahan Ekuitas harus rekonsiliasi dengan Total Ekuitas Neraca.'
        );

        // 4. Susunan Total Pasiva.
        $this->assertEqualsWithDelta(
            round($data['totalKewajiban'] + $data['totalDanaSyirkahTemporer'] + $data['totalEkuitas'] + $data['labaBersih'], 2),
            $data['totalPasiva'],
            0.01,
            'Total Pasiva harus = Kewajiban + DST + Ekuitas + Laba Berjalan.'
        );

        // 5. Total Aset = jumlah akun Aset.
        $aset = Coa::where('kategori', 'Aset')->where('is_active', true)->get();
        $this->assertEqualsWithDelta(
            round((float) $aset->sum(fn ($c) => round($c->saldoBerjalan($sampai), 2)), 2),
            $data['totalAset'],
            0.01,
            'Total Aset harus sama dengan jumlah saldo akun Aset.'
        );

        // 6. Akun 3-9000 tidak lagi bersaldo minus.
        $this->assertGreaterThanOrEqual(
            -0.01,
            Coa::findByKode('3-9000')->saldoBerjalan(),
            0.01,
            'Akun 3-9000 harus bersaldo normal setelah pembersihan.'
        );
    }
}
