<?php

namespace Tests\Feature;

use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\KasHarian;
use App\Models\TellerOpname;
use App\Models\Transaksi;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Uji modul Kas Opname, Closing & Approval (Teller -> Manajer).
 *
 * Memverifikasi:
 *  - Kalkulasi backend otoritatif (total fisik & selisih tidak dipercaya dari browser)
 *  - Transisi status kas_harian: OPEN -> PENDING_APPROVAL -> CLOSED
 *  - Jurnal pemindahan kas BALANCE di ketiga kondisi (balanced/over/short)
 *  - Otorisasi role & guard anti-double-approve
 */
class KasOpnameClosingTest extends TestCase
{
    use DatabaseTransactions;

    private function teller(): User
    {
        return User::firstOrCreate(
            ['email' => 'teller.opname@example.test'],
            ['name' => 'Teller Opname Test', 'password' => Hash::make('secret'), 'role' => 'teller', 'is_active' => true]
        );
    }

    private function manajer(): User
    {
        return User::firstOrCreate(
            ['email' => 'manajer.opname@example.test'],
            ['name' => 'Manajer Opname Test', 'password' => Hash::make('secret'), 'role' => 'manajer', 'is_active' => true]
        );
    }

    /** Siapkan sesi Buka Kas hari ini dengan modal awal tertentu. */
    private function sesiKas(User $teller, float $modalAwal): KasHarian
    {
        $today = now()->toDateString();

        KasHarian::where('user_id', $teller->id)->where('tanggal', $today)->delete();
        TellerOpname::where('user_id', $teller->id)->whereDate('tanggal', $today)->delete();
        Transaksi::where('dibuat_oleh', $teller->id)->whereDate('waktu_transaksi', $today)->delete();
        Transaksi::where('user_id', $teller->id)->whereDate('waktu_transaksi', $today)->delete();

        return KasHarian::create([
            'user_id' => $teller->id,
            'tanggal' => $today,
            'modal_awal' => $modalAwal,
            'tambah_modal' => [],
            'waktu_opening' => now()->format('H:i:s'),
            'status_opname' => KasHarian::STATUS_OPEN,
        ]);
    }

    private function mutasi(User $teller, string $jenis, float $jumlah): Transaksi
    {
        return Transaksi::create([
            'no_transaksi' => 'OPN-'.strtoupper(bin2hex(random_bytes(5))),
            'waktu_transaksi' => now()->toDateString().' 09:00:00',
            'jenis_transaksi' => $jenis,
            'posisi' => 'Debet',
            'jumlah' => $jumlah,
            'user_id' => $teller->id,
            'sumber_transaksi' => 'Teller',
            'status_transaksi' => 'Sukses',
            'dibuat_oleh' => $teller->id,
        ]);
    }

    /** Susun map pecahan agar total fisik tepat sejumlah target (pakai 100rb & 50rb & 1rb). */
    private function pecahanUntuk(float $target): array
    {
        $sisa = (int) round($target);
        $map = [];

        foreach ([100000, 50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100] as $p) {
            $lembar = intdiv($sisa, $p);
            $map[$p] = $lembar;
            $sisa -= $lembar * $p;
        }

        return $map;
    }

    // ─────────────────────────────────────────────────────────
    // Skema & CoA
    // ─────────────────────────────────────────────────────────

    public function test_skema_tabel_dan_akun_coa_tersedia(): void
    {
        foreach ([
            'kas_harian_id', 'user_id', 'tanggal', 'saldo_sistem', 'total_fisik', 'selisih',
            'rincian_pecahan', 'status', 'status_selisih', 'catatan',
            'approved_by', 'approved_at', 'jurnal_id',
        ] as $kolom) {
            $this->assertTrue(Schema::hasColumn('teller_opnames', $kolom), "Kolom teller_opnames.{$kolom} hilang.");
        }

        $this->assertTrue(Schema::hasColumn('kas_harian', 'status_opname'), 'Kolom kas_harian.status_opname hilang.');

        foreach (['1-1001', '1-1003', '1-1099', '2-3099'] as $kode) {
            $this->assertNotNull(Coa::findByKode($kode), "Akun CoA {$kode} belum tersedia (jurnal akan gagal).");
        }
    }

    /** Akun lama tidak boleh tertimpa oleh migrasi CoA baru. */
    public function test_akun_coa_lama_tidak_tertimpa(): void
    {
        $this->assertSame('Kas Teller', Coa::findByKode('1-1001')?->nama_akun);
        $this->assertStringContainsString('Dana Kebajikan', (string) Coa::findByKode('2-3001')?->nama_akun,
            'Akun 2-3001 harus tetap Dana Kebajikan (tidak tertimpa Titipan Selisih Kas).');
    }

    /** Model TellerOpname menghitung rincian pecahan & mengabaikan pecahan tidak sah. */
    public function test_perhitungan_rincian_pecahan(): void
    {
        $hasil = TellerOpname::hitungRincianPecahan([
            '100000' => 3,
            '50000' => 2,
            '1000' => 5,
            '777' => 100, // pecahan tidak sah -> diabaikan
            '100' => -4,  // negatif -> dinormalkan jadi 0
        ]);

        $this->assertSame(405000.0, $hasil['total']);
        $this->assertSame(10, count($hasil['baris']), 'Harus mengembalikan 10 baris pecahan resmi.');

        $baris100k = collect($hasil['baris'])->firstWhere('pecahan', 100000);
        $this->assertSame(3, $baris100k['lembar']);
        $this->assertSame(300000.0, $baris100k['subtotal']);
    }

    public function test_penentuan_status_selisih(): void
    {
        $this->assertSame(TellerOpname::SELISIH_BALANCED, TellerOpname::tentukanStatusSelisih(0.0));
        $this->assertSame(TellerOpname::SELISIH_OVER, TellerOpname::tentukanStatusSelisih(25000.0));
        $this->assertSame(TellerOpname::SELISIH_SHORT, TellerOpname::tentukanStatusSelisih(-25000.0));
    }

    // ─────────────────────────────────────────────────────────
    // Teller: form & submit opname
    // ─────────────────────────────────────────────────────────

    public function test_halaman_opname_menampilkan_ringkasan_kas(): void
    {
        $teller = $this->teller();
        $this->sesiKas($teller, 5000000);
        $this->mutasi($teller, 'Setoran Tunai', 1000000);

        $this->actingAs($teller)
            ->get(route('teller.kas.closing'))
            ->assertOk()
            ->assertSee('Kas Opname', false)
            ->assertSee('6.000.000', false)     // saldo sistem = 5jt + 1jt
            ->assertSee('BALANCED')
            ->assertSee('rincian_pecahan[100000]', false);
    }

    /** Backend menghitung ulang total fisik, mengabaikan angka kiriman browser. */
    public function test_submit_opname_menghitung_total_di_backend(): void
    {
        $teller = $this->teller();
        $kas = $this->sesiKas($teller, 1000000);
        $this->mutasi($teller, 'Setoran Tunai', 500000);

        // Saldo sistem = 1.500.000. Fisik dikirim 1.500.000 (balanced).
        $this->actingAs($teller)
            ->post(route('teller.kas.closing.store'), [
                'rincian_pecahan' => $this->pecahanUntuk(1500000),
                'catatan' => 'Hitung ulang dua kali, hasil sama.',
            ])
            ->assertRedirect(route('teller.kas.closing'));

        $opname = TellerOpname::where('kas_harian_id', $kas->id)->first();
        $this->assertNotNull($opname);
        $this->assertSame(1500000.0, (float) $opname->saldo_sistem);
        $this->assertSame(1500000.0, (float) $opname->total_fisik);
        $this->assertSame(0.0, (float) $opname->selisih);
        $this->assertSame(TellerOpname::SELISIH_BALANCED, $opname->status_selisih);
        $this->assertSame(TellerOpname::STATUS_PENDING, $opname->status);
        $this->assertNull($opname->approved_by);

        // Sesi Buka Kas harus ditandai menunggu approval.
        $kas->refresh();
        $this->assertSame(KasHarian::STATUS_PENDING_APPROVAL, $kas->status_opname);
    }

    /** Kas lebih: fisik > sistem. */
    public function test_submit_opname_kas_lebih(): void
    {
        $teller = $this->teller();
        $kas = $this->sesiKas($teller, 1000000);

        $this->actingAs($teller)
            ->post(route('teller.kas.closing.store'), ['rincian_pecahan' => $this->pecahanUntuk(1030000)])
            ->assertRedirect();

        $opname = TellerOpname::where('kas_harian_id', $kas->id)->first();
        $this->assertSame(30000.0, (float) $opname->selisih);
        $this->assertSame(TellerOpname::SELISIH_OVER, $opname->status_selisih);
    }

    /** Kas kurang: fisik < sistem. */
    public function test_submit_opname_kas_kurang(): void
    {
        $teller = $this->teller();
        $kas = $this->sesiKas($teller, 1000000);

        $this->actingAs($teller)
            ->post(route('teller.kas.closing.store'), ['rincian_pecahan' => $this->pecahanUntuk(975000)])
            ->assertRedirect();

        $opname = TellerOpname::where('kas_harian_id', $kas->id)->first();
        $this->assertSame(-25000.0, (float) $opname->selisih);
        $this->assertSame(TellerOpname::SELISIH_SHORT, $opname->status_selisih);
    }

    public function test_opname_ditolak_bila_fisik_nol(): void
    {
        $teller = $this->teller();
        $this->sesiKas($teller, 1000000);

        $this->actingAs($teller)
            ->post(route('teller.kas.closing.store'), ['rincian_pecahan' => $this->pecahanUntuk(0)])
            ->assertSessionHas('error');

        $this->assertSame(0, TellerOpname::where('user_id', $teller->id)->count());
    }

    public function test_opname_ditolak_bila_belum_opening(): void
    {
        $teller = $this->teller();
        KasHarian::where('user_id', $teller->id)->where('tanggal', now()->toDateString())->delete();

        $this->actingAs($teller)
            ->post(route('teller.kas.closing.store'), ['rincian_pecahan' => $this->pecahanUntuk(100000)])
            ->assertSessionHas('error');
    }

    /** Cegah pengajuan ganda saat masih ada opname pending. */
    public function test_tidak_bisa_ajukan_opname_ganda(): void
    {
        $teller = $this->teller();
        $this->sesiKas($teller, 1000000);

        $this->actingAs($teller)
            ->post(route('teller.kas.closing.store'), ['rincian_pecahan' => $this->pecahanUntuk(1000000)])
            ->assertRedirect();

        $this->actingAs($teller)
            ->post(route('teller.kas.closing.store'), ['rincian_pecahan' => $this->pecahanUntuk(1000000)])
            ->assertSessionHas('error');

        $this->assertSame(1, TellerOpname::where('user_id', $teller->id)->count());
    }

    public function test_validasi_lembar_negatif_dan_bukan_angka(): void
    {
        $teller = $this->teller();
        $this->sesiKas($teller, 1000000);

        $this->actingAs($teller)
            ->post(route('teller.kas.closing.store'), ['rincian_pecahan' => ['100000' => -5]])
            ->assertSessionHasErrors('rincian_pecahan.100000');
    }

    // ─────────────────────────────────────────────────────────
    // Manajer: antrean, approve, jurnal
    // ─────────────────────────────────────────────────────────

    public function test_manajer_melihat_antrean_pending(): void
    {
        $teller = $this->teller();
        $manajer = $this->manajer();
        $this->sesiKas($teller, 2000000);

        $this->actingAs($teller)
            ->post(route('teller.kas.closing.store'), ['rincian_pecahan' => $this->pecahanUntuk(2000000)]);

        $this->actingAs($manajer)
            ->get(route('manajer.closing.index'))
            ->assertOk()
            ->assertSee('Approval Closing Kas', false)
            ->assertSee($teller->name)
            ->assertSee('2.000.000', false)
            ->assertSee('BALANCED');
    }

    public function test_teller_tidak_bisa_akses_halaman_manajer(): void
    {
        $this->actingAs($this->teller())
            ->get(route('manajer.closing.index'))
            ->assertForbidden();
    }

    /**
     * Halaman opname teller memakai grup route teller,manajer,pengurus (konvensi
     * RBAC proyek ini), jadi Manajer boleh membukanya sebagai pengawas. Yang penting
     * adalah Manajer hanya melihat form — data opname ter-scope per user_id.
     */
    public function test_halaman_opname_terpisah_per_teller(): void
    {
        $teller = $this->teller();
        $lain = User::firstOrCreate(
            ['email' => 'teller.scope@example.test'],
            ['name' => 'Teller Scope', 'password' => Hash::make('secret'), 'role' => 'teller', 'is_active' => true]
        );
        $this->sesiKas($lain, 7777000);

        // Teller lain tidak boleh melihat saldo sesi milik teller pertama.
        $this->actingAs($teller)
            ->get(route('teller.kas.closing'))
            ->assertOk()
            ->assertDontSee('7.777.000', false);
    }

    /** Kondisi BALANCED: D Vault(fisik) ; K Kas Teller(sistem). */
    public function test_approve_balanced_membentuk_jurnal_seimbang(): void
    {
        $teller = $this->teller();
        $manajer = $this->manajer();
        $kas = $this->sesiKas($teller, 5000000);
        $this->mutasi($teller, 'Setoran Tunai', 1000000); // sistem 6.000.000

        $this->actingAs($teller)->post(route('teller.kas.closing.store'), [
            'rincian_pecahan' => $this->pecahanUntuk(6000000),
        ]);

        $opname = TellerOpname::where('kas_harian_id', $kas->id)->first();

        $this->actingAs($manajer)
            ->post(route('manajer.closing.approve', $opname->id), ['catatan_approval' => 'Fisik sesuai.'])
            ->assertRedirect(route('manajer.closing.index'));

        $opname->refresh();
        $this->assertSame(TellerOpname::STATUS_CLOSED, $opname->status);
        $this->assertSame($manajer->id, (int) $opname->approved_by);
        $this->assertNotNull($opname->approved_at);
        $this->assertNotNull($opname->jurnal_id);

        // Sesi kas tertutup
        $kas->refresh();
        $this->assertSame(KasHarian::STATUS_CLOSED, $kas->status_opname);

        // Jurnal balance & komposisi akun tepat
        $jurnal = Jurnal::with('detail')->find($opname->jurnal_id);
        $this->assertTrue($jurnal->hitungBalance(), 'Jurnal harus balance.');
        $this->assertSame('Opname Kas Teller', $jurnal->sumber);

        $this->assertSame(6000000.0, (float) $jurnal->total_debet);
        $this->assertSame(6000000.0, (float) $jurnal->total_kredit);

        $debet = $this->totalPerAkun($jurnal->detail, 'debet');
        $kredit = $this->totalPerAkun($jurnal->detail, 'kredit');

        $this->assertSame(6000000.0, $debet['1-1003'], 'Vault didebet sebesar total fisik.');
        $this->assertSame(6000000.0, $kredit['1-1001'], 'Kas Teller dikredit sebesar saldo sistem.');
        $this->assertArrayNotHasKey('1-1099', $debet, 'Kondisi balanced tidak boleh memakai akun selisih.');
        $this->assertArrayNotHasKey('2-3099', $kredit, 'Kondisi balanced tidak boleh memakai akun titipan.');
    }

    /** Kondisi CASH OVER: D Vault(fisik) ; K Kas Teller(sistem) ; K Titipan Selisih(selisih). */
    public function test_approve_kas_lebih_membentuk_jurnal_dengan_titipan_selisih(): void
    {
        $teller = $this->teller();
        $manajer = $this->manajer();
        $kas = $this->sesiKas($teller, 1000000); // sistem 1.000.000

        $this->actingAs($teller)->post(route('teller.kas.closing.store'), [
            'rincian_pecahan' => $this->pecahanUntuk(1045000), // fisik lebih 45.000
        ]);

        $opname = TellerOpname::where('kas_harian_id', $kas->id)->first();
        $this->assertSame(TellerOpname::SELISIH_OVER, $opname->status_selisih);

        $this->actingAs($manajer)->post(route('manajer.closing.approve', $opname->id));

        $jurnal = Jurnal::with('detail')->find($opname->fresh()->jurnal_id);
        $this->assertTrue($jurnal->hitungBalance(), 'Jurnal kas lebih harus balance.');

        $debet = $this->totalPerAkun($jurnal->detail, 'debet');
        $kredit = $this->totalPerAkun($jurnal->detail, 'kredit');

        $this->assertSame(1045000.0, $debet['1-1003'], 'Vault didebet sebesar total fisik.');
        $this->assertSame(1000000.0, $kredit['1-1001'], 'Kas Teller dikredit sebesar saldo sistem.');
        $this->assertSame(45000.0, $kredit['2-3099'], 'Selisih lebih masuk Titipan Selisih Kas.');
        $this->assertArrayNotHasKey('1-1099', $debet);
    }

    /** Kondisi CASH SHORT: D Vault(fisik) ; D Piutang Teller(selisih) ; K Kas Teller(sistem). */
    public function test_approve_kas_kurang_membentuk_jurnal_piutang_teller(): void
    {
        $teller = $this->teller();
        $manajer = $this->manajer();
        $kas = $this->sesiKas($teller, 1000000); // sistem 1.000.000

        $this->actingAs($teller)->post(route('teller.kas.closing.store'), [
            'rincian_pecahan' => $this->pecahanUntuk(970000), // fisik kurang 30.000
        ]);

        $opname = TellerOpname::where('kas_harian_id', $kas->id)->first();
        $this->assertSame(TellerOpname::SELISIH_SHORT, $opname->status_selisih);

        $this->actingAs($manajer)->post(route('manajer.closing.approve', $opname->id));

        $jurnal = Jurnal::with('detail')->find($opname->fresh()->jurnal_id);
        $this->assertTrue($jurnal->hitungBalance(), 'Jurnal kas kurang harus balance.');

        $debet = $this->totalPerAkun($jurnal->detail, 'debet');
        $kredit = $this->totalPerAkun($jurnal->detail, 'kredit');

        $this->assertSame(970000.0, $debet['1-1003'], 'Vault didebet sebesar total fisik.');
        $this->assertSame(30000.0, $debet['1-1099'], 'Selisih kurang jadi Piutang Teller.');
        $this->assertSame(1000000.0, $kredit['1-1001'], 'Kas Teller dikredit sebesar saldo sistem.');
        $this->assertSame(1000000.0, (float) $jurnal->total_debet, 'Total debet = fisik + selisih.');
        $this->assertSame(1000000.0, (float) $jurnal->total_kredit, 'Total kredit = saldo sistem.');
    }

    /** Approve ganda harus ditolak (anti-double posting jurnal). */
    public function test_approve_dua_kali_ditolak(): void
    {
        $teller = $this->teller();
        $manajer = $this->manajer();
        $kas = $this->sesiKas($teller, 1000000);

        $this->actingAs($teller)->post(route('teller.kas.closing.store'), [
            'rincian_pecahan' => $this->pecahanUntuk(1000000),
        ]);
        $opname = TellerOpname::where('kas_harian_id', $kas->id)->first();

        $this->actingAs($manajer)->post(route('manajer.closing.approve', $opname->id));
        $jurnalPertama = $opname->fresh()->jurnal_id;

        $this->actingAs($manajer)
            ->post(route('manajer.closing.approve', $opname->id))
            ->assertSessionHas('error');

        $this->assertSame($jurnalPertama, $opname->fresh()->jurnal_id, 'Jurnal tidak boleh berubah.');
        $this->assertSame(1, Jurnal::where('sumber', 'Opname Kas Teller')
            ->where('keterangan', 'like', '%#'.$opname->id.' %')->count(), 'Jurnal tidak boleh terduplikasi.');
    }

    /** Tolak opname: sesi kas kembali OPEN agar teller bisa hitung ulang. */
    public function test_reject_mengembalikan_sesi_kas_ke_open(): void
    {
        $teller = $this->teller();
        $manajer = $this->manajer();
        $kas = $this->sesiKas($teller, 1000000);

        $this->actingAs($teller)->post(route('teller.kas.closing.store'), [
            'rincian_pecahan' => $this->pecahanUntuk(900000),
        ]);
        $opname = TellerOpname::where('kas_harian_id', $kas->id)->first();

        $this->actingAs($manajer)
            ->post(route('manajer.closing.reject', $opname->id), ['catatan_approval' => 'Selisih terlalu besar, hitung ulang.'])
            ->assertRedirect(route('manajer.closing.index'));

        $opname->refresh();
        $this->assertSame(TellerOpname::STATUS_REJECTED, $opname->status);
        $this->assertNull($opname->jurnal_id, 'Penolakan tidak boleh membentuk jurnal.');

        $kas->refresh();
        $this->assertSame(KasHarian::STATUS_OPEN, $kas->status_opname);
        $this->assertNull($kas->waktu_closing);

        // Teller bisa mengajukan ulang setelah ditolak.
        $this->actingAs($teller)
            ->post(route('teller.kas.closing.store'), ['rincian_pecahan' => $this->pecahanUntuk(1000000)])
            ->assertRedirect();
        $this->assertSame(2, TellerOpname::where('kas_harian_id', $kas->id)->count());
    }

    public function test_reject_wajib_alasan(): void
    {
        $teller = $this->teller();
        $manajer = $this->manajer();
        $kas = $this->sesiKas($teller, 1000000);

        $this->actingAs($teller)->post(route('teller.kas.closing.store'), [
            'rincian_pecahan' => $this->pecahanUntuk(1000000),
        ]);
        $opname = TellerOpname::where('kas_harian_id', $kas->id)->first();

        $this->actingAs($manajer)
            ->post(route('manajer.closing.reject', $opname->id), ['catatan_approval' => ''])
            ->assertSessionHasErrors('catatan_approval');

        $this->assertSame(TellerOpname::STATUS_PENDING, $opname->fresh()->status);
    }

    /** Teller dapat membatalkan pengajuan pending miliknya sendiri. */
    public function test_teller_bisa_batalkan_opname_pending(): void
    {
        $teller = $this->teller();
        $kas = $this->sesiKas($teller, 1000000);

        $this->actingAs($teller)->post(route('teller.kas.closing.store'), [
            'rincian_pecahan' => $this->pecahanUntuk(1000000),
        ]);
        $opname = TellerOpname::where('kas_harian_id', $kas->id)->first();

        $this->actingAs($teller)
            ->post(route('teller.kas.closing.batal', $opname->id))
            ->assertRedirect(route('teller.kas.closing'));

        $this->assertSame(TellerOpname::STATUS_REJECTED, $opname->fresh()->status);
        $this->assertSame(KasHarian::STATUS_OPEN, $kas->fresh()->status_opname);
    }

    /** Teller lain tidak boleh membatalkan opname orang lain. */
    public function test_teller_lain_tidak_bisa_batalkan_opname_orang_lain(): void
    {
        $teller = $this->teller();
        $lain = User::firstOrCreate(
            ['email' => 'teller.lain@example.test'],
            ['name' => 'Teller Lain', 'password' => Hash::make('secret'), 'role' => 'teller', 'is_active' => true]
        );
        $kas = $this->sesiKas($teller, 1000000);

        $this->actingAs($teller)->post(route('teller.kas.closing.store'), [
            'rincian_pecahan' => $this->pecahanUntuk(1000000),
        ]);
        $opname = TellerOpname::where('kas_harian_id', $kas->id)->first();

        $this->actingAs($lain)
            ->post(route('teller.kas.closing.batal', $opname->id))
            ->assertForbidden();
    }

    /** Setelah sesi CLOSED, teller tidak boleh mengajukan opname lagi. */
    public function test_tidak_bisa_opname_setelah_sesi_closed(): void
    {
        $teller = $this->teller();
        $manajer = $this->manajer();
        $kas = $this->sesiKas($teller, 1000000);

        $this->actingAs($teller)->post(route('teller.kas.closing.store'), [
            'rincian_pecahan' => $this->pecahanUntuk(1000000),
        ]);
        $opname = TellerOpname::where('kas_harian_id', $kas->id)->first();
        $this->actingAs($manajer)->post(route('manajer.closing.approve', $opname->id));

        $this->actingAs($teller)
            ->post(route('teller.kas.closing.store'), ['rincian_pecahan' => $this->pecahanUntuk(1000000)])
            ->assertSessionHas('error');
    }

    /** Helper: total debet/kredit per kode akun dari koleksi JurnalDetail. */
    private function totalPerAkun($details, string $kolom): array
    {
        $hasil = [];

        foreach ($details as $d) {
            $kode = Coa::find($d->coa_id)?->kode_akun;
            if (! $kode) {
                continue;
            }

            $nilai = (float) $d->{$kolom};
            if ($nilai > 0) {
                $hasil[$kode] = ($hasil[$kode] ?? 0.0) + $nilai;
            }
        }

        return $hasil;
    }
}
