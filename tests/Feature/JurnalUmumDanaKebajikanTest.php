<?php

namespace Tests\Feature;

use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\User;
use App\Services\AutoJournalingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Modul Jurnal Umum & Verifikasi Dana Kebajikan (/dps/jurnal-umum) — DPS.
 *
 * Cakupan pengujian:
 *   A. Running balance akun 2-3001 (Kewajiban/Passiva, posisi saldo KREDIT)
 *      wajib mengikuti formulir  Saldo Baru = Saldo Sebelumnya + KREDIT − DEBET
 *      untuk setiap baris, dihitung setelah urutan kronologis deterministik
 *      (created_at ASC, id ASC), dengan nilai debet/kredit tervalidasi.
 *   B. Kolom KETERANGAN tabel mutasi menampilkan narasi transaksi sebenarnya,
 *      bukan nama akun statis, dan tetap terbaca (dipotong + tooltip).
 *   C. Nilai summary card 'Saldo Dana Kebajikan (2-3001)' identik dengan saldo
 *      baris terakhir tabel mutasi (satu sumber perhitungan).
 *
 * Catatan: basis data dev memuat data nyata, sehingga pengujian memakai
 * SELISIH (delta) terhadap mutasi yang dibuat test ini agar deterministik.
 */
class JurnalUmumDanaKebajikanTest extends TestCase
{
    use DatabaseTransactions;

    /** Prefix unik agar data uji mudah diisolasi dari data dev. */
    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'JU'.strtoupper(substr(uniqid(), -6));
    }

    private function penggunaDps(): User
    {
        return User::firstOrCreate(
            ['email' => 'dps.jurnalumum@test.local'],
            [
                'name' => 'DPS Jurnal Umum',
                'password' => Hash::make('password'),
                'role' => 'dps',
                'is_active' => true,
            ]
        );
    }

    private function akunKebajikan(): ?Coa
    {
        return Coa::findByKode(AutoJournalingService::DANA_KEBAJIKAN);
    }

    /** Buka halaman jurnal umum sebagai DPS read-only. */
    private function bukaHalaman()
    {
        return $this->actingAs($this->penggunaDps())->get(route('dps.jurnal-umum'));
    }

    /* =====================================================================
     | A. RUNNING BALANCE AKUN 2-3001
     ===================================================================== */

    public function test_akun_2_3001_adalah_kewajiban_posisi_kredit(): void
    {
        $akun = $this->akunKebajikan();

        if (! $akun) {
            $this->markTestSkipped('Akun 2-3001 belum ter-seed pada basis data ini.');
        }

        $this->assertSame('Kewajiban', $akun->kategori, 'Dana Kebajikan (2-3001) wajib berkategori Kewajiban/Passiva.');
        $this->assertSame('Kredit', $akun->posisiSaldo(), 'Akun 2-3001 wajib berposisi saldo Kredit.');
    }

    public function test_running_balance_mengikuti_formula_saldo_baru_sama_dengan_saldo_lama_plus_kredit_minus_debet(): void
    {
        $respon = $this->bukaHalaman()->assertOk();

        $mutasi = $respon->viewData('mutasiDanaKebajikan');
        $akun = $this->akunKebajikan();

        $this->assertGreaterThan(0, $mutasi->count(), 'Tabel mutasi wajib terisi agar saldo berjalan dapat diverifikasi.');

        $saldo = (float) $akun->saldo_awal;

        foreach ($mutasi as $i => $baris) {
            $saldo = round($saldo + (float) $baris->kredit_valid - (float) $baris->debet_valid, 2);

            $this->assertSame(
                $saldo,
                round((float) $baris->saldo_berjalan, 2),
                "Saldo baris ke-{$i} wajib = saldo sebelumnya + KREDIT − DEBET (akun Kewajiban)."
            );
        }
    }

    public function test_urutan_mutasi_kronologis_deterministik_created_at_lalu_id(): void
    {
        $mutasi = $this->bukaHalaman()->assertOk()->viewData('mutasiDanaKebajikan');
        $kunci = $mutasi->map(fn ($m) => $m->created_at.'#'.str_pad((string) $m->id, 10, '0', STR_PAD_LEFT))->all();
        $urut = $kunci;

        sort($urut, SORT_STRING);

        $this->assertSame(
            $urut,
            $kunci,
            'Mutasi wajib terurut kronologis created_at ASC dengan id ASC sebagai pemecah seri (mencegah saldo meloncat).'
        );
    }

    public function test_saldo_akhir_running_balance_sama_dengan_akumulasi_kredit_minus_debet_seluruh_mutasi(): void
    {
        $respon = $this->bukaHalaman()->assertOk();
        $akun = $this->akunKebajikan();

        $this->assertSame(
            round((float) $respon->viewData('totalMutasiKredit') - (float) $respon->viewData('totalMutasiDebet') + (float) $akun->saldo_awal, 2),
            round((float) $respon->viewData('saldoDanaKebajikan'), 2),
            'Saldo akhir running balance wajib = saldo awal + SUM(kredit) − SUM(debet) akun 2-3001.'
        );
    }

    public function test_nilai_negatif_tidak_wajar_tidak_membalik_arah_saldo(): void
    {
        $akun = $this->akunKebajikan();

        if (! $akun) {
            $this->markTestSkipped('Akun 2-3001 belum ter-seed pada basis data ini.');
        }

        $jurnal = Jurnal::create([
            'no_jurnal' => 'JRN-UJI-'.$this->prefix,
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Penyesuaian',
            'sumber' => 'Uji Negatif',
            'keterangan' => 'Uji nilai negatif '.$this->prefix,
            'total_debet' => 0,
            'total_kredit' => 0,
            'is_balance' => true,
            'status' => 'Posted',
            'posted_at' => now(),
        ]);

        // Nilai negatif tidak wajar: tidak boleh mengurangi saldo kebajikan.
        JurnalDetail::create([
            'jurnal_id' => $jurnal->id,
            'coa_id' => $akun->id,
            'baris' => 1,
            'debet' => -50_000,
            'kredit' => 0,
            'keterangan' => 'Uji nilai negatif tidak wajar '.$this->prefix,
        ]);

        $respon = $this->bukaHalaman()->assertOk();
        $baris = $respon->viewData('mutasiDanaKebajikan')->firstWhere('jurnal_id', $jurnal->id);

        $this->assertNotNull($baris, 'Baris uji wajib muncul di tabel mutasi.');
        $this->assertSame(0.0, (float) $baris->debet_valid, 'Debet negatif wajib dinolkan sebelum masuk kalkulasi saldo.');
        $this->assertSame(
            round((float) $baris->saldo_berjalan, 2),
            round((float) $respon->viewData('saldoDanaKebajikan'), 2),
            'Baris negatif tidak boleh mengubah saldo akhir.'
        );
    }

    /* =====================================================================
     | B. KOLOM KETERANGAN TABEL MUTASI
     ===================================================================== */

    public function test_kolom_keterangan_menampilkan_narasi_transaksi_bukan_nama_akun_statis(): void
    {
        $respon = $this->bukaHalaman()->assertOk();
        $akun = $this->akunKebajikan();
        $html = $respon->getContent();

        foreach ($respon->viewData('mutasiDanaKebajikan') as $baris) {
            $this->assertNotSame(
                mb_strtolower(trim((string) $akun->nama_akun)),
                mb_strtolower(trim((string) $baris->keterangan_tampil)),
                'Kolom keterangan tidak boleh lagi menampilkan nama akun statis.'
            );
        }

        // Tooltip (title) + pemotongan teks (ellipsis) wajib ada agar teks panjang tetap terbaca.
        $this->assertMatchesRegularExpression(
            '/<td[^>]*title="[^"]+"[^>]*>/',
            $html,
            'Kolom keterangan wajib memiliki tooltip berisi teks lengkap.'
        );
        $this->assertStringContainsString('text-overflow:ellipsis', $html, 'Teks panjang wajib dipotong sesuai lebar kolom.');
        $this->assertStringContainsString('white-space:nowrap', $html, 'Kolom keterangan wajib dijaga satu baris agar tabel rapi.');
    }

    public function test_narasi_diambil_dari_keterangan_jurnal_saat_keterangan_baris_hanya_nama_akun(): void
    {
        $akun = $this->akunKebajikan();

        if (! $akun) {
            $this->markTestSkipped('Akun 2-3001 belum ter-seed pada basis data ini.');
        }

        $narasi = 'Narasi uji ta`zir keterangan jurnal '.$this->prefix;

        $jurnal = Jurnal::create([
            'no_jurnal' => 'JRN-NAR-'.$this->prefix,
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Otomatis',
            'sumber' => "Ta'zir",
            'keterangan' => $narasi,
            'total_debet' => 0,
            'total_kredit' => 0,
            'is_balance' => true,
            'status' => 'Posted',
            'posted_at' => now(),
        ]);

        // Meniru perilaku AutoJournalingService: keterangan baris = NAMA AKUN (statis).
        JurnalDetail::create([
            'jurnal_id' => $jurnal->id,
            'coa_id' => $akun->id,
            'baris' => 1,
            'debet' => 0,
            'kredit' => 10_000,
            'keterangan' => $akun->nama_akun,
        ]);

        $baris = $this->bukaHalaman()->assertOk()
            ->viewData('mutasiDanaKebajikan')
            ->firstWhere('jurnal_id', $jurnal->id);

        $this->assertNotNull($baris, 'Baris uji wajib muncul di tabel mutasi.');
        $this->assertSame(
            $narasi,
            $baris->keterangan_tampil,
            'Bila keterangan baris hanya nama akun, narasi wajib diambil dari keterangan jurnal.'
        );
    }

    /* =====================================================================
     | C. KONSISTENSI SUMMARY CARD
     ===================================================================== */

    public function test_summary_card_sama_persis_dengan_saldo_baris_terakhir_tabel_mutasi(): void
    {
        $respon = $this->bukaHalaman()->assertOk();
        $mutasi = $respon->viewData('mutasiDanaKebajikan');

        $this->assertGreaterThan(0, $mutasi->count(), 'Tabel mutasi wajib terisi.');

        $this->assertSame(
            round((float) $mutasi->last()->saldo_berjalan, 2),
            round((float) $respon->viewData('saldoDanaKebajikan'), 2),
            'Nilai card Saldo Dana Kebajikan wajib sama persis dengan saldo baris terakhir tabel mutasi.'
        );
    }

    public function test_nilai_card_ditampilkan_di_html_dan_punya_penanda_uji(): void
    {
        $respon = $this->bukaHalaman()->assertOk();
        $html = $respon->getContent();
        $saldo = (float) $respon->viewData('saldoDanaKebajikan');

        $this->assertStringContainsString('data-field="saldo-dana-kebajikan"', $html, 'Nilai card wajib punya penanda data-field.');
        $this->assertMatchesRegularExpression(
            '/data-field="saldo-dana-kebajikan"[^>]*>\s*Rp '.preg_quote(number_format($saldo, 2, ',', '.'), '/').'/',
            $html,
            'Angka pada card wajib sama dengan saldo akhir running balance.'
        );
    }

    /* =====================================================================
     | D. SIMULASI TRANSAKSI: TAMBAH, EDIT, HAPUS
     ===================================================================== */

    public function test_skenario_tambah_edit_hapus_menjaga_running_balance_dan_card_konsisten(): void
    {
        $akun = $this->akunKebajikan();

        if (! $akun) {
            $this->markTestSkipped('Akun 2-3001 belum ter-seed pada basis data ini.');
        }

        $layanan = app(AutoJournalingService::class);
        $saldoAwal = (float) $this->bukaHalaman()->assertOk()->viewData('saldoDanaKebajikan');

        /* --- 1) TAMBAH: penerimaan 250.000 → saldo naik tepat 250.000 --- */
        $masuk = $layanan->jurnalPenerimaanDanaKebajikan(
            250_000,
            'Uji tambah penerimaan kebajikan '.$this->prefix,
            $this->penggunaDps()->id
        );

        $this->assertNotNull($masuk, 'Jurnal penerimaan dana kebajikan wajib terbentuk.');

        $setelahTambah = $this->bukaHalaman()->assertOk();
        $barisMasuk = $setelahTambah->viewData('mutasiDanaKebajikan')->firstWhere('jurnal_id', $masuk->id);

        $this->assertNotNull($barisMasuk, 'Mutasi baru wajib tampil di tabel.');
        $this->assertSame(
            round($saldoAwal + 250_000, 2),
            round((float) $setelahTambah->viewData('saldoDanaKebajikan'), 2),
            'Penambahan KREDIT wajib menaikkan saldo kebajikan.'
        );
        $this->assertSame(
            round((float) $setelahTambah->viewData('saldoDanaKebajikan'), 2),
            round((float) $setelahTambah->viewData('mutasiDanaKebajikan')->last()->saldo_berjalan, 2),
            'Card wajib tetap konsisten dengan saldo baris terakhir setelah penambahan.'
        );

        /* --- 2) TAMBAH PENYALURAN: debet 100.000 → saldo turun tepat 100.000 --- */
        $keluar = $layanan->jurnalPenyaluranDanaKebajikan(
            100_000,
            'Uji tambah penyaluran kebajikan '.$this->prefix,
            $this->penggunaDps()->id
        );

        $this->assertNotNull($keluar, 'Jurnal penyaluran dana kebajikan wajib terbentuk.');

        $setelahSalur = $this->bukaHalaman()->assertOk();

        $this->assertSame(
            round($saldoAwal + 250_000 - 100_000, 2),
            round((float) $setelahSalur->viewData('saldoDanaKebajikan'), 2),
            'Penyaluran DEBET wajib mengurangi saldo kebajikan.'
        );

        /* --- 3) EDIT: nilai kredit diubah 250.000 → 300.000 --- */
        $detail = JurnalDetail::where('jurnal_id', $masuk->id)->where('coa_id', $akun->id)->firstOrFail();
        $detail->update(['kredit' => 300_000]);

        $setelahEdit = $this->bukaHalaman()->assertOk();

        $this->assertSame(
            round($saldoAwal + 300_000 - 100_000, 2),
            round((float) $setelahEdit->viewData('saldoDanaKebajikan'), 2),
            'Perubahan nilai kredit wajib langsung tercermin pada saldo akhir.'
        );

        // Seluruh baris tetap mematuhi formula dan baris terakhir = card.
        $saldo = (float) $akun->saldo_awal;
        foreach ($setelahEdit->viewData('mutasiDanaKebajikan') as $baris) {
            $saldo = round($saldo + (float) $baris->kredit_valid - (float) $baris->debet_valid, 2);
            $this->assertSame($saldo, round((float) $baris->saldo_berjalan, 2), 'Running balance wajib tetap akurat setelah edit.');
        }
        $this->assertSame(
            round((float) $setelahEdit->viewData('saldoDanaKebajikan'), 2),
            round($saldo, 2),
            'Card wajib sama dengan saldo akhir perhitungan berurutan setelah edit.'
        );

        /* --- 4) HAPUS: hapus jurnal penyaluran → saldo kembali naik 100.000 --- */
        JurnalDetail::where('jurnal_id', $keluar->id)->delete();
        Jurnal::whereKey($keluar->id)->delete();

        $setelahHapus = $this->bukaHalaman()->assertOk();

        $this->assertSame(
            round($saldoAwal + 300_000, 2),
            round((float) $setelahHapus->viewData('saldoDanaKebajikan'), 2),
            'Penghapusan mutasi DEBET wajib mengembalikan saldo kebajikan.'
        );
        $this->assertNull(
            $setelahHapus->viewData('mutasiDanaKebajikan')->firstWhere('jurnal_id', $keluar->id),
            'Baris mutasi yang dihapus tidak boleh lagi tampil.'
        );
        $this->assertSame(
            round((float) $setelahHapus->viewData('saldoDanaKebajikan'), 2),
            round((float) $setelahHapus->viewData('mutasiDanaKebajikan')->last()->saldo_berjalan, 2),
            'Card wajib tetap konsisten dengan saldo baris terakhir setelah penghapusan.'
        );

        // Bersihkan sisa data uji
        JurnalDetail::where('jurnal_id', $masuk->id)->delete();
        Jurnal::whereKey($masuk->id)->delete();
    }

    public function test_halaman_tetap_berjalan_normal_tanpa_error(): void
    {
        $this->bukaHalaman()
            ->assertOk()
            ->assertSee('Jurnal Umum & Verifikasi Dana Kebajikan')
            ->assertSee('Mutasi Dana Kebajikan — Akun 2-3001')
            ->assertSee('Saldo Dana Kebajikan (2-3001)');
    }
}
