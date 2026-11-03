<?php

namespace Tests\Feature;

use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\KasHarian;
use App\Models\TellerOpname;
use App\Models\Transaksi;
use App\Models\User;
use App\Support\JenisTransaksi;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regresi modul kas kasir: opening (saldo awal), saldo berjalan selama shift,
 * dan closing.
 *
 * Latar belakang insiden: opening berhasil dieksekusi tetapi posisi kas selalu
 * tampil Rp 0. Akar masalah:
 *  1. Kolom `dibuat_oleh` & `nominal` tidak ada di $fillable Transaksi sehingga
 *     diam-diam dibuang oleh mass-assignment.
 *  2. Daftar jenis transaksi & filter status tidak konsisten antar halaman
 *     (Opening/Rekap/Riwayat), sehingga banyak mutasi kas tidak terjaring.
 */
class KasKasirTest extends TestCase
{
    use DatabaseTransactions;

    private function teller(): User
    {
        return User::firstOrCreate(
            ['email' => 'teller.kas.test@example.test'],
            ['name' => 'Teller Kas Test', 'password' => Hash::make('secret'), 'role' => 'teller', 'is_active' => true]
        );
    }

    private function buatTransaksi(User $user, string $jenis, float $jumlah, string $status = 'Sukses', ?string $tanggal = null): Transaksi
    {
        return Transaksi::create([
            'no_transaksi' => 'TEST-'.strtoupper(bin2hex(random_bytes(5))),
            'waktu_transaksi' => ($tanggal ?? now()->toDateString()).' 09:00:00',
            'jenis_transaksi' => $jenis,
            'posisi' => 'Debet',
            'jumlah' => $jumlah,
            'user_id' => $user->id,
            'sumber_transaksi' => 'Teller',
            'status_transaksi' => $status,
            'dibuat_oleh' => $user->id,
        ]);
    }

    /** Pastikan baris shift (kas_harian) hari ini ada untuk teller. */
    private function pastikanKasHarian(User $user, float $modalAwal = 0): KasHarian
    {
        KasHarian::where('user_id', $user->id)->where('tanggal', now()->toDateString())->delete();

        return KasHarian::create([
            'user_id' => $user->id,
            'tanggal' => now()->toDateString(),
            'modal_awal' => $modalAwal,
            'tambah_modal' => [],
            'waktu_opening' => now()->format('H:i:s'),
            'status_opname' => KasHarian::STATUS_OPEN,
        ]);
    }

    /** Hapus opname & mutasi hari ini agar sesi kas bisa diuji ulang dalam satu test. */
    private function bersihkanSesiHariIni(User $user): void
    {
        $today = now()->toDateString();

        TellerOpname::where('user_id', $user->id)->whereDate('tanggal', $today)->delete();
        Transaksi::where('dibuat_oleh', $user->id)->whereDate('waktu_transaksi', $today)->delete();
    }

    /**
     * Isi Saldo Brankas Utama (CoA 1-1003) agar injeksi kas dari Vault
     * memenuhi guard anti-deficit.
     */
    private function danaiBrankas(float $nominal): void
    {
        $vault = Coa::findByKode('1-1003');
        $lawan = Coa::findByKode('3-1001') ?? Coa::findByKode('1-1001');

        $jurnal = Jurnal::create([
            'no_jurnal' => 'UJI-VAULT-'.strtoupper(bin2hex(random_bytes(4))),
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'Uji Kas Kasir',
            'keterangan' => 'Pengisian awal Brankas Utama untuk pengujian',
            'status' => 'Posted',
            'user_id' => $this->teller()->id,
        ]);

        JurnalDetail::create([
            'jurnal_id' => $jurnal->id, 'coa_id' => $vault->id,
            'baris' => 1, 'debet' => $nominal, 'kredit' => 0,
        ]);
        JurnalDetail::create([
            'jurnal_id' => $jurnal->id, 'coa_id' => $lawan->id,
            'baris' => 2, 'debet' => 0, 'kredit' => $nominal,
        ]);

        $jurnal->hitungBalance();
        $jurnal->save();
    }

    /**
     * Susun rincian pecahan (map: '100000' => jumlah lembar) yang totalnya persis
     * sama dengan nominal tertentu, untuk dikirim ke form opname/closing.
     *
     * @return array<string, int>
     */
    private function pecahanUntuk(float $nominal): array
    {
        $map = [];
        $sisa = (int) round($nominal);

        foreach (TellerOpname::PECAHAN as $pecahan) {
            $lembar = $sisa > 0 ? intdiv($sisa, $pecahan) : 0;

            $map[(string) $pecahan] = $lembar;
            $sisa -= $lembar * $pecahan;
        }

        return $map;
    }

    /** Root cause #1: 'dibuat_oleh' & 'nominal' harus tersimpan (dulu dibuang mass-assignment). */
    public function test_fillable_transaksi_menyimpan_dibuat_oleh_dan_nominal(): void
    {
        $teller = $this->teller();

        $trx = Transaksi::create([
            'no_transaksi' => 'TEST-FILL-'.strtoupper(bin2hex(random_bytes(4))),
            'waktu_transaksi' => now(),
            'jenis_transaksi' => 'Setoran Tunai',
            'posisi' => 'Debet',
            'jumlah' => 123456,
            'nominal' => 123456,
            'user_id' => $teller->id,
            'sumber_transaksi' => 'Teller',
            'status_transaksi' => 'Sukses',
            'dibuat_oleh' => $teller->id,
        ]);

        $this->assertSame($teller->id, (int) $trx->fresh()->dibuat_oleh, 'Kolom dibuat_oleh harus tersimpan.');
        $this->assertSame(123456.0, (float) $trx->fresh()->nominal, 'Kolom nominal harus tersimpan.');
    }

    /** Skema: kolom wajib modul kas harus ada di transaksi & kas_harian. */
    public function test_skema_kolom_kas_tersedia(): void
    {
        foreach (['user_id', 'dibuat_oleh', 'waktu_transaksi', 'jenis_transaksi', 'jumlah', 'status_transaksi'] as $kolom) {
            $this->assertTrue(
                Schema::hasColumn('transaksi', $kolom),
                "Kolom transaksi.{$kolom} hilang."
            );
        }

        foreach (['user_id', 'tanggal', 'modal_awal', 'waktu_opening', 'fisik_kas', 'saldo_sistem', 'selisih', 'waktu_closing'] as $kolom) {
            $this->assertTrue(
                Schema::hasColumn('kas_harian', $kolom),
                "Kolom kas_harian.{$kolom} hilang."
            );
        }
    }

    /** Opening TANPA modal awal harus ditolak — input tidak boleh default Rp 0. */
    public function test_opening_menolak_modal_awal_kosong(): void
    {
        $teller = $this->teller();
        $today = now()->toDateString();

        KasHarian::where('user_id', $teller->id)->where('tanggal', $today)->delete();

        $this->actingAs($teller)
            ->post(route('teller.kas.opening.store'), ['catatan' => 'Tanpa modal'])
            ->assertSessionHasErrors('modal_awal');

        $this->assertNull(
            KasHarian::where('user_id', $teller->id)->where('tanggal', $today)->first(),
            'Baris kas_harian tidak boleh terbentuk bila modal awal kosong.'
        );
    }

    /** Modal awal Rp 0 harus ditolak (nilai default lama yang jadi sumber insiden). */
    public function test_opening_menolak_modal_awal_nol(): void
    {
        $teller = $this->teller();
        $today = now()->toDateString();

        KasHarian::where('user_id', $teller->id)->where('tanggal', $today)->delete();

        $this->actingAs($teller)
            ->post(route('teller.kas.opening.store'), ['modal_awal' => 0])
            ->assertSessionHasErrors('modal_awal');

        $this->assertNull(
            KasHarian::where('user_id', $teller->id)->where('tanggal', $today)->first(),
            'Opening Rp 0 tidak boleh tercatat.'
        );
    }

    /** Modal awal negatif & non-numerik harus ditolak. */
    public function test_opening_menolak_modal_awal_negatif_dan_bukan_angka(): void
    {
        $teller = $this->teller();

        foreach ([-500000, 'seratus ribu', 'abc'] as $nilai) {
            $this->actingAs($teller)
                ->post(route('teller.kas.opening.store'), ['modal_awal' => $nilai])
                ->assertSessionHasErrors('modal_awal');
        }
    }

    /** Modal awal valid tersimpan apa adanya sesuai input pengguna. */
    public function test_opening_menyimpan_modal_awal_sesuai_input(): void
    {
        $teller = $this->teller();
        $today = now()->toDateString();

        $this->bersihkanSesiHariIni($teller);
        KasHarian::where('user_id', $teller->id)->where('tanggal', $today)->delete();

        $this->actingAs($teller)
            ->post(route('teller.kas.opening.store'), [
                'modal_awal' => 1500000,
                'catatan' => 'Kas fisik awal loket 1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $kas = KasHarian::where('user_id', $teller->id)->where('tanggal', $today)->first();
        $this->assertNotNull($kas, 'Baris kas_harian harus terbentuk setelah opening valid.');
        $this->assertSame(1500000.0, (float) $kas->modal_awal, 'Modal awal harus sama dengan input pengguna.');

        $posisi = KasHarian::ringkasanPosisiKas($teller->id, $today);
        $this->assertSame(1500000.0, $posisi['kas_awal'], 'Kas awal pada ringkasan posisi harus mengikuti input.');
        $this->assertSame(1500000.0, $posisi['saldo']);
    }

    /** Form opening tidak boleh lagi memuat nilai default Rp 0 pada input modal awal. */
    public function test_form_opening_tidak_memuat_default_nol(): void
    {
        $teller = $this->teller();
        $today = now()->toDateString();

        KasHarian::where('user_id', $teller->id)->where('tanggal', $today)->delete();

        $this->actingAs($teller)
            ->get(route('teller.kas.opening'))
            ->assertOk()
            ->assertSee('name="modal_awal"', false)
            ->assertDontSee('value="0" required', false);
    }

    /** Skenario shift BARU: opening menyimpan modal awal + posisi kas tampil benar. */
    public function test_skenario_shift_baru_opening_dan_posisi_kas(): void
    {
        $teller = $this->teller();
        $today = now()->toDateString();

        KasHarian::where('user_id', $teller->id)->where('tanggal', $today)->delete();

        $this->actingAs($teller)
            ->post(route('teller.kas.opening.store'), [
                'modal_awal' => 5000000,
                'catatan' => 'Kas awal shift pagi',
            ])
            ->assertRedirect();

        $kas = KasHarian::where('user_id', $teller->id)->where('tanggal', $today)->first();
        $this->assertNotNull($kas, 'Baris kas_harian harus dibuat saat opening.');
        $this->assertSame(5000000.0, (float) $kas->modal_awal, 'Modal awal harus tersimpan sesuai input.');
        $this->assertSame($teller->id, (int) $kas->user_id, 'user_id kasir harus tercatat.');
        $this->assertNotNull($kas->waktu_opening, 'waktu_opening harus terisi.');
        $this->assertNull($kas->waktu_closing, 'Shift baru belum boleh tercatat closing.');

        // Mutasi kas: cash in (termasuk jenis yang dulu tidak terjaring: Bayar Angsuran)
        // + cash out, plus status non-kas yang harus diabaikan.
        $this->buatTransaksi($teller, 'Setoran Tunai', 1000000);
        $this->buatTransaksi($teller, 'Bayar Angsuran', 500000);
        $this->buatTransaksi($teller, 'Setoran Simpanan Berjangka', 250000);
        $this->buatTransaksi($teller, 'Penarikan Tunai', 300000);
        $this->buatTransaksi($teller, 'Setoran Tunai', 999999, 'Dibatalkan');
        $this->buatTransaksi($teller, 'Setoran Tunai', 888888, 'Gagal');

        $posisi = KasHarian::ringkasanPosisiKas($teller->id, $today);

        $this->assertSame(5000000.0, $posisi['kas_awal']);
        $this->assertSame(1750000.0, $posisi['cash_in'], 'Cash in harus menjumlahkan Setoran Tunai + Bayar Angsuran + Setoran Berjangka.');
        $this->assertSame(300000.0, $posisi['cash_out']);
        $this->assertSame(6450000.0, $posisi['saldo'], 'Saldo = Kas Awal + Cash In - Cash Out.');
        $this->assertSame(4, $posisi['jumlah_transaksi'], 'Transaksi Dibatalkan/Gagal tidak boleh dihitung.');

        // Angka yang sama juga harus muncul di halaman Opening (UI).
        $this->actingAs($teller)
            ->get(route('teller.kas.opening'))
            ->assertOk()
            ->assertSee('6.450.000', false)
            ->assertSee('1.750.000', false)
            ->assertSee('300.000', false);
    }

    /**
     * Skenario shift LAMA: kas_harian sudah ada dengan modal_awal = 0 (insiden yang
     * dilaporkan) — data harus tetap terbaca & bisa dipulihkan lewat tambah modal.
     */
    public function test_skenario_shift_lama_dengan_modal_nol_dapat_dipulihkan(): void
    {
        $teller = $this->teller();
        $today = now()->toDateString();

        // Simulasikan baris shift yang terlanjur dibuka dengan modal 0 dan tambah_modal NULL.
        $kas = $this->pastikanKasHarian($teller, 0);
        $kas->forceFill(['tambah_modal' => null])->save();

        // Transaksi lama yang hanya punya user_id (dibuat_oleh NULL) tetap harus terbaca.
        $lama = $this->buatTransaksi($teller, 'Setoran Tunai', 750000);
        $lama->forceFill(['dibuat_oleh' => null])->save();

        $posisi = KasHarian::ringkasanPosisiKas($teller->id, $today);

        $this->assertSame(0.0, $posisi['kas_awal'], 'Kas awal 0 terbaca apa adanya, bukan disembunyikan.');
        $this->assertSame(750000.0, $posisi['cash_in'], 'Transaksi lama dengan dibuat_oleh NULL harus tetap terjaring lewat user_id.');
        $this->assertSame(750000.0, $posisi['saldo']);

        // Pemulihan data lama: tambah modal mengisi kas awal tanpa merusak mutasi.
        // Tambah modal kini berbentuk transfer Brankas Utama → Laci Teller
        // (zero-hardcoding + anti-deficit), sehingga Vault wajib bersaldo dulu.
        $this->danaiBrankas(4000000);

        $this->actingAs($teller)
            ->post(route('teller.kas.tambah-modal'), [
                'nominal_tambah' => 4000000,
                'keterangan' => 'Pemulihan modal awal shift yang terlanjur 0',
            ])
            ->assertRedirect();

        $posisiSetelah = KasHarian::ringkasanPosisiKas($teller->id, $today);
        $this->assertSame(4000000.0, $posisiSetelah['saldo'] - 750000.0, 'Modal pemulihan harus menambah kas awal.');
        $this->assertSame(4750000.0, $posisiSetelah['saldo']);
    }

    /** Skenario CLOSING: selisih dihitung dari saldo sistem yang sama dengan halaman opening. */
    public function test_skenario_closing_menghitung_selisih_dari_saldo_sistem(): void
    {
        $teller = $this->teller();
        $today = now()->toDateString();

        $this->bersihkanSesiHariIni($teller);
        $this->pastikanKasHarian($teller, 2000000);

        $posisi = KasHarian::ringkasanPosisiKas($teller->id, $today);
        $fisik = $posisi['saldo'] - 25000; // sengaja kurang Rp 25.000

        $this->actingAs($teller)->get(route('teller.kas.closing'))->assertOk();

        $this->actingAs($teller)
            ->post(route('teller.kas.closing.store'), [
                'rincian_pecahan' => $this->pecahanUntuk($fisik),
                'catatan' => 'Uji closing',
            ])
            ->assertRedirect(route('teller.kas.closing'));

        $opname = TellerOpname::where('user_id', $teller->id)->where('tanggal', $today)->first();
        $this->assertNotNull($opname, 'Opname harus tersimpan setelah submit closing.');
        $this->assertSame(TellerOpname::STATUS_PENDING, $opname->status, 'Status awal opname harus pending (menunggu verifikasi Manajer).');
        $this->assertSame(TellerOpname::SELISIH_SHORT, $opname->status_selisih);
        $this->assertSame(-25000.0, (float) $opname->selisih, 'Selisih = total fisik - saldo sistem.');

        // Sesi kas harus ditandai menunggu persetujuan, belum ditutup permanen.
        $kas = KasHarian::where('user_id', $teller->id)->where('tanggal', $today)->first();
        $this->assertSame(KasHarian::STATUS_PENDING_APPROVAL, $kas->status_opname);
    }

    /** Halaman closing tidak boleh error walau belum ada opening hari ini. */
    public function test_halaman_closing_tanpa_opening_tetap_tampil(): void
    {
        $teller = $this->teller();
        KasHarian::where('user_id', $teller->id)->where('tanggal', now()->toDateString())->delete();

        $this->actingAs($teller)
            ->get(route('teller.kas.closing'))
            ->assertOk()
            ->assertSee('Belum ada Opening hari ini');
    }

    /** Error handling: shift lama bermodal 0 tetap diberi peringatan jelas, bukan gagal senyap. */
    public function test_halaman_opening_menampilkan_peringatan_bila_modal_nol(): void
    {
        $teller = $this->teller();

        $this->pastikanKasHarian($teller, 0);

        $this->actingAs($teller)
            ->get(route('teller.kas.opening'))
            ->assertOk()
            ->assertSee('Shift ini tercatat dengan Modal Awal Rp 0')
            ->assertSee('Tambah Modal Kas');
    }

    /** End-to-end lewat HTTP: opening -> saldo berjalan -> closing. */
    public function test_e2e_opening_saldo_berjalan_closing(): void
    {
        $teller = $this->teller();
        $today = now()->toDateString();

        KasHarian::where('user_id', $teller->id)->where('tanggal', $today)->delete();
        Transaksi::where('dibuat_oleh', $teller->id)->whereDate('waktu_transaksi', $today)->delete();
        Transaksi::where('user_id', $teller->id)->whereDate('waktu_transaksi', $today)->delete();

        // 1) OPENING: input saldo awal Rp 5.000.000
        $this->actingAs($teller)
            ->post(route('teller.kas.opening.store'), ['modal_awal' => 5000000])
            ->assertRedirect();

        $kas = KasHarian::where('user_id', $teller->id)->where('tanggal', $today)->first();
        $this->assertNotNull($kas, 'Baris kas_harian harus tersimpan setelah opening.');
        $this->assertSame(5000000.0, (float) $kas->modal_awal);
        $this->assertSame($teller->id, (int) $kas->user_id);
        $this->assertNotNull($kas->waktu_opening, 'Timestamp waktu_opening harus terisi.');

        // 2) SALDO BERJALAN: mutasi kas hari ini.
        $this->buatTransaksi($teller, 'Setoran Tunai', 1000000);
        $this->buatTransaksi($teller, 'Penarikan Tunai', 250000);

        $posisi = KasHarian::ringkasanPosisiKas($teller->id, $today);
        $this->assertSame(5000000.0, $posisi['kas_awal']);
        $this->assertSame(1000000.0, $posisi['cash_in']);
        $this->assertSame(250000.0, $posisi['cash_out']);
        $this->assertSame(5750000.0, $posisi['saldo']);
        $this->assertSame(2, $posisi['jumlah_transaksi']);

        // Halaman opening harus menampilkan angka tersebut (bukan Rp 0 semua).
        $this->actingAs($teller)
            ->get(route('teller.kas.opening'))
            ->assertOk()
            ->assertSee('5.750.000')
            ->assertSee('Setoran Tunai')
            ->assertSee('Penarikan Tunai');

        // 3) CLOSING dengan kas fisik sesuai sistem -> selisih 0, opname pending.
        $this->actingAs($teller)
            ->post(route('teller.kas.closing.store'), [
                'rincian_pecahan' => $this->pecahanUntuk(5750000),
                'catatan' => 'E2E closing',
            ])
            ->assertRedirect(route('teller.kas.closing'));

        $opname = TellerOpname::where('user_id', $teller->id)->where('tanggal', now()->toDateString())->first();
        $this->assertNotNull($opname, 'Opname harus terbentuk setelah closing teller.');
        $this->assertSame(0.0, (float) $opname->selisih, 'Selisih harus 0 saat kas fisik = saldo sistem.');
        $this->assertSame(TellerOpname::SELISIH_BALANCED, $opname->status_selisih);

        $kas->refresh();
        $this->assertSame(KasHarian::STATUS_PENDING_APPROVAL, $kas->status_opname, 'Sesi kas menunggu approval Manajer.');
    }

    /**
     * Regresi insiden "kartu Kas & Rekening Rp 0".
     *
     * Akar masalah: kolom `transaksi.jenis_transaksi` berisi banyak varian nama
     * untuk peristiwa ekonomi yang sama (mis. "Setoran Simpanan Sukarela" vs
     * "Simpanan Sukarela", "Pembayaran Angsuran" vs "Bayar Angsuran"), sementara
     * pembaca hanya mencari satu literal sehingga menemukan Rp 0.
     *
     * Uji ini memastikan pembaca memakai registri tunggal App\Support\JenisTransaksi
     * dan hasilnya sama dengan agregasi mentah tabel `transaksi`.
     */
    public function test_kartu_kas_pengurus_mengenali_semua_varian_nama(): void
    {
        $pengurus = User::firstOrCreate(
            ['email' => 'pengurus.kas.test@example.test'],
            ['name' => 'Pengurus Kas Test', 'password' => Hash::make('secret'), 'role' => 'pengurus', 'is_active' => true]
        );

        $hari = now()->toDateString();

        // Sengaja memakai varian nama historis yang berbeda-beda.
        $this->buatTransaksi($pengurus, 'Setoran Simpanan Sukarela', 2000000);
        $this->buatTransaksi($pengurus, 'Simpanan Sukarela', 1500000); // alias lama
        $this->buatTransaksi($pengurus, 'Setoran Simpanan Pokok', 200000);
        $this->buatTransaksi($pengurus, 'Setoran Awal Anggota', 150000); // alias lama
        $this->buatTransaksi($pengurus, 'Pembayaran Angsuran', 600000);
        $this->buatTransaksi($pengurus, 'Bayar Angsuran', 400000); // alias lama
        $this->buatTransaksi($pengurus, 'Setoran Tunai', 250000);
        $this->buatTransaksi($pengurus, 'Penarikan Tunai', 750000);

        $response = $this->actingAs($pengurus)
            ->get(route('pengurus.kas.index', ['tgl_mulai' => $hari, 'tgl_selesai' => $hari]))
            ->assertOk();

        // Agregasi mentah langsung dari tabel transaksi.
        $agg = fn (array $keys) => (float) Transaksi::whereDate('waktu_transaksi', $hari)
            ->whereIn('jenis_transaksi', $keys)
            ->sum('jumlah');

        // 1) Ringkasan atas harus sama dengan agregasi registri.
        $this->assertSame($agg(JenisTransaksi::cashIn()), (float) $response->viewData('totalMasuk'));
        $this->assertSame($agg(JenisTransaksi::cashOut()), (float) $response->viewData('totalKeluar'));

        // 2) Bukti alias benar-benar dihitung: bila pembaca hanya mengenali nilai
        //    kanonik, total masuk akan lebih kecil dari yang seharusnya.
        $kanonikSaja = [
            JenisTransaksi::SETORAN_TUNAI, JenisTransaksi::SETORAN_SUKARELA,
            JenisTransaksi::SETORAN_POKOK, JenisTransaksi::SETORAN_WAJIB,
            JenisTransaksi::SETORAN_BERJANGKA, JenisTransaksi::PEMBAYARAN_ANGSURAN,
            JenisTransaksi::TRANSFER_MASUK, JenisTransaksi::BAGI_HASIL,
            JenisTransaksi::BIAYA_ADMINISTRASI, JenisTransaksi::DENDA, JenisTransaksi::TAZIR,
        ];
        $this->assertGreaterThan(
            $agg($kanonikSaja),
            $agg(JenisTransaksi::cashIn()),
            'Varian nama historis harus ikut terhitung, bukan hanya nilai kanonik.'
        );

        // 3) Kartu harus sama dengan agregasi registri per kelompok.
        $kartu = collect($response->viewData('kartu'))->keyBy('label');
        $this->assertSame($agg(JenisTransaksi::sukarela()), (float) $kartu['Simpanan Sukarela']['total']);
        $this->assertSame($agg(JenisTransaksi::pokok()), (float) $kartu['Simpanan Pokok']['total']);
        $this->assertSame($agg(JenisTransaksi::angsuran()), (float) $kartu['Pembayaran Angsuran']['total']);
        $this->assertSame($agg([JenisTransaksi::PENARIKAN_TUNAI]), (float) $kartu['Penarikan Tunai']['total']);

        // 4) Penjaga utama: tidak boleh ada nilai `jenis_transaksi` di DB yang
        //    belum terdaftar di registri (kelas bug "nilai hantu").
        $dikenal = array_unique(array_merge(
            JenisTransaksi::CASH_IN,
            JenisTransaksi::CASH_OUT,
            JenisTransaksi::AUTO_DEBIT_WAJIB,
            JenisTransaksi::PEMINDAHAN_PRODUK,
            JenisTransaksi::STATUS_NON_KAS
        ));
        $asing = DB::table('transaksi')->distinct()->pluck('jenis_transaksi')
            ->reject(fn ($j) => in_array($j, $dikenal, true))
            ->values()->all();

        $this->assertSame([], $asing,
            'Ada jenis_transaksi yang belum terdaftar di App\Support\JenisTransaksi: '.implode(', ', $asing));
    }

    /**
     * F-07 — Registri tunggal menggantikan mitigasi tambal-sulam.
     *
     * Sebelum perbaikan, setiap titik pemetaan alias (ReversalService,
     * TransactionLimitService, Setting) menuliskan `in_array($jenis, [...])`
     * dengan daftar literal yang berbeda-beda, sehingga alias yang terlewat di
     * salah satu titik membuat perlakuan tidak konsisten (mis. jurnal pembalik
     * tidak terbentuk, atau jenis partisipasi SHU tidak terhitung).
     *
     * Uji ini mengunci (1) peta alias → kanonik, (2) normalisasi, dan
     * (3) helper pengenalan angsuran agar seluruh titik dapat merujuknya.
     */
    public function test_registri_jenis_transaksi_menyatukan_alias_dan_normalisasi(): void
    {
        // 1) Seluruh alias angsuran memetakan ke satu nilai kanonik.
        foreach (JenisTransaksi::angsuran() as $alias) {
            $this->assertTrue(
                JenisTransaksi::adalahAngsuran($alias),
                "Nilai '{$alias}' seharusnya dikenali sebagai angsuran."
            );
            $this->assertSame(
                JenisTransaksi::PEMBAYARAN_ANGSURAN,
                JenisTransaksi::kanonik($alias),
                "Alias '{$alias}' harus dinormalisasi ke nilai kanonik."
            );
        }

        // 2) Alias setoran simpanan dinormalisasi ke kanonik masing-masing.
        $this->assertSame(JenisTransaksi::SETORAN_SUKARELA, JenisTransaksi::kanonik('Simpanan Sukarela'));
        $this->assertSame(JenisTransaksi::SETORAN_POKOK, JenisTransaksi::kanonik('Setoran Awal Anggota'));
        $this->assertSame(JenisTransaksi::SETORAN_WAJIB, JenisTransaksi::kanonik('Auto-Debit Simpanan Wajib'));

        // 3) Nilai kanonik memetakan ke dirinya sendiri; nilai asing dibiarkan apa adanya.
        $this->assertSame(JenisTransaksi::SETORAN_TUNAI, JenisTransaksi::kanonik(JenisTransaksi::SETORAN_TUNAI));
        $this->assertSame('Nilai Liar', JenisTransaksi::kanonik('Nilai Liar'));

        // 4) Heuristik substring lama ('Pembayaran' apa pun) tidak boleh lagi
        //    dianggap angsuran — hanya nilai terdaftar yang sah.
        $this->assertFalse(JenisTransaksi::adalahAngsuran('Pembayaran Biaya Admin'));
        $this->assertFalse(JenisTransaksi::adalahAngsuran('Setoran Tunai'));

        // 5) Basis partisipasi SHU (nilai default) menyertakan seluruh alias
        //    angsuran tanpa duplikat, selaras dengan registri tunggal.
        $partisipasi = array_map('trim', explode(',', (string) Setting::DEFAULTS['shu_jenis_partisipasi']));
        foreach (JenisTransaksi::angsuran() as $alias) {
            $this->assertContains($alias, $partisipasi, "Basis partisipasi SHU harus memuat alias '{$alias}'.");
        }
        $this->assertSame(
            array_values(array_unique($partisipasi)),
            $partisipasi,
            'Daftar partisipasi SHU tidak boleh memuat duplikat.'
        );
    }
}
