<?php

namespace Tests\Feature;

use App\Exceptions\JournalPostingException;
use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\Pembiayaan;
use App\Models\User;
use App\Services\AutoJournalingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Perapihan COA kelompok Piutang Pembiayaan.
 *
 * Cakupan pengujian:
 *  A. Akun alias duplikat (1-2010 / 1-2020 / 1-2030 / 1-2040) nonaktif dan
 *     tidak dapat dipakai untuk transaksi baru.
 *  B. Pemetaan akad -> akun piutang utama (1-2001..1-2005) konsisten pada
 *     seluruh skenario pencairan pembiayaan.
 *  C. Posting jurnal pencairan selalu balance dan tanpa error sistem.
 *  D. CoaSeeder tidak lagi membuat akun alias.
 */
class PerapihanCoaPiutangPembiayaanTest extends TestCase
{
    use DatabaseTransactions;

    /** Akun alias yang harus sudah nonaktif. */
    private const AKUN_ALIAS = ['1-2010', '1-2020', '1-2030', '1-2040'];

    /** Kontrak mapping akad -> akun piutang utama. */
    private const PETA_AKAD = [
        'Murabahah' => '1-2001',
        'Mudharabah' => '1-2002',
        'Musyarakah' => '1-2003',
        'Ijarah' => '1-2004',
        'Qardh' => '1-2005',
    ];

    private function pengurus(): User
    {
        return User::firstOrCreate(
            ['email' => 'pengurus.coapiutang@test.local'],
            ['name' => 'Pengurus COA Piutang', 'password' => Hash::make('password'), 'role' => 'pengurus']
        );
    }

    private function service(): AutoJournalingService
    {
        return app(AutoJournalingService::class);
    }

    /** Cari baris jurnal berdasarkan sumber (jurnalPencairan → 'Pencairan Pembiayaan'). */
    private function jurnalTerakhir(string $sumber = 'Pencairan Pembiayaan'): ?Jurnal
    {
        return Jurnal::where('sumber', $sumber)->orderByDesc('id')->first();
    }

    // ══════════════════════════════════════════════════════════════════
    // A. AKUN ALIAS DUPLIKAT SUDAH NONAKTIF & TIDAK BISA DIPAKAI
    // ══════════════════════════════════════════════════════════════════

    public function test_akun_alias_piutang_sudah_nonaktif_dan_akun_utama_tetap_aktif(): void
    {
        foreach (self::AKUN_ALIAS as $kode) {
            $alias = Coa::where('kode_akun', $kode)->first();

            // Instalasi BARU tidak lagi membuat akun alias sama sekali (lihat
            // CoaSeeder + migrasi penonaktifan yang secara eksplisit melewati
            // akun tak ada). Instalasi LAMA mempertahankannya sebagai baris
            // nonaktif demi jejak audit. Invarian yang dikunci di sini:
            // bila barisnya ada, ia TIDAK BOLEH aktif dan TIDAK BOLEH
            // dapat dipakai untuk transaksi baru.
            if ($alias !== null) {
                $this->assertFalse((bool) $alias->is_active, "Akun alias {$kode} harus berstatus nonaktif.");
            }

            $this->assertNull(
                Coa::findByKode($kode),
                "Akun alias {$kode} tidak boleh aktif / dapat dipakai transaksi baru."
            );
        }

        foreach (array_values(self::PETA_AKAD) as $kode) {
            $utama = Coa::findByKode($kode);

            $this->assertNotNull($utama, "Akun utama {$kode} harus aktif dan dapat ditemukan via Coa::findByKode().");
            $this->assertTrue((bool) $utama->is_active, "Akun utama {$kode} harus tetap aktif.");
        }
    }

    public function test_akun_alias_tidak_dapat_dipakai_untuk_transaksi_baru(): void
    {
        foreach (self::AKUN_ALIAS as $kode) {
            // findByKode() hanya mengembalikan akun aktif → akun nonaktif tidak ditemukan.
            $this->assertNull(
                Coa::findByKode($kode),
                "Akun nonaktif {$kode} tidak boleh ditemukan oleh Coa::findByKode()."
            );

            $jumlahSebelum = Jurnal::where('sumber', 'Pencairan Pembiayaan')->count();

            // Mencoba posting jurnal langsung ke akun nonaktif harus DITOLAK.
            // Penolakan diwujudkan sebagai `JournalPostingException` agar
            // `DB::transaction` pemanggil ikut rollback (bukan lagi `return null`).
            $ditolak = false;

            try {
                $this->service()->post(
                    'Uji tolak akun nonaktif '.$kode,
                    [['kode' => $kode, 'debet' => 100000]],
                    'Pencairan Pembiayaan'
                );
            } catch (JournalPostingException $e) {
                $ditolak = true;
                $this->assertContains(
                    $e->aturan,
                    ['COA_TIDAK_DITEMUKAN', 'COA_NONAKTIF'],
                    "Penolakan akun nonaktif {$kode} harus beralasan CoA tidak ditemukan/nonaktif."
                );
            }

            $this->assertTrue($ditolak, "Posting ke akun nonaktif {$kode} harus ditolak dengan exception.");
            $this->assertSame(
                $jumlahSebelum,
                Jurnal::where('sumber', 'Pencairan Pembiayaan')->count(),
                "Tidak boleh ada jurnal terbentuk saat memakai akun nonaktif {$kode}."
            );
        }
    }

    public function test_tidak_ada_jurnal_historis_yang_merujuk_akun_alias(): void
    {
        $idAlias = Coa::whereIn('kode_akun', self::AKUN_ALIAS)->pluck('id')->all();

        $this->assertSame(
            0,
            JurnalDetail::whereIn('coa_id', $idAlias)->count(),
            'Akun alias bersaldo nol seharusnya tidak pernah dipakai di jurnal mana pun.'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // B. MAPPING AKAD -> AKUN PIUTANG UTAMA (5 SKENARIO PENCAIRAN)
    // ══════════════════════════════════════════════════════════════════

    public function test_setiap_akad_terposting_ke_akun_piutang_utama_yang_benar(): void
    {
        foreach (self::PETA_AKAD as $akad => $kodeHarapan) {
            $jurnal = $this->service()->jurnalPencairan(
                1_000_000,
                $akad,
                "Uji pencairan akad {$akad}",
                $this->pengurus()->id
            );

            $this->assertNotNull($jurnal, "Jurnal pencairan akad {$akad} harus terbentuk.");
            $this->assertSame('Pencairan Pembiayaan', $jurnal->sumber);

            $coaDipakai = Coa::find($jurnal->detail[0]->coa_id ?? null);
            $this->assertNotNull($coaDipakai, "Detail jurnal akad {$akad} harus punya akun CoA.");

            $this->assertSame(
                $kodeHarapan,
                $coaDipakai->kode_akun,
                "Akad {$akad} harus diposting ke akun {$kodeHarapan}, bukan {$coaDipakai->kode_akun}."
            );
        }
    }

    public function test_jurnal_pencairan_selalu_balance_untuk_setiap_akad(): void
    {
        foreach (self::PETA_AKAD as $akad => $kodeHarapan) {
            $jurnal = $this->service()->jurnalPencairan(
                7_500_000,
                $akad,
                "Uji balance akad {$akad}",
                $this->pengurus()->id
            );

            $this->assertNotNull($jurnal, "Jurnal akad {$akad} harus terbentuk.");

            $debet = (float) JurnalDetail::where('jurnal_id', $jurnal->id)->sum('debet');
            $kredit = (float) JurnalDetail::where('jurnal_id', $jurnal->id)->sum('kredit');

            $this->assertEqualsWithDelta(
                $debet,
                $kredit,
                0.01,
                "Jurnal pencairan akad {$akad} harus balance (debet {$debet} vs kredit {$kredit})."
            );
            $this->assertEqualsWithDelta(7_500_000, $debet, 0.01, "Nominal jurnal akad {$akad} harus sesuai.");
            $this->assertEqualsWithDelta($jurnal->total_debet, $jurnal->total_kredit, 0.01);
        }
    }

    public function test_akun_piutang_didebet_dan_sumber_dana_dikredit(): void
    {
        $jurnal = $this->service()->jurnalPencairan(
            3_000_000,
            'Murabahah',
            'Uji posisi debet/kredit',
            $this->pengurus()->id
        );

        $this->assertNotNull($jurnal);

        $detail = JurnalDetail::where('jurnal_id', $jurnal->id)->get();
        $debet = $detail->firstWhere(fn ($d) => (float) $d->debet > 0);
        $kredit = $detail->firstWhere(fn ($d) => (float) $d->kredit > 0);

        $this->assertNotNull($debet, 'Harus ada baris debet.');
        $this->assertNotNull($kredit, 'Harus ada baris kredit.');

        $this->assertSame(
            '1-2001',
            Coa::find($debet->coa_id)->kode_akun,
            'Posisi DEBET harus akun piutang akad (1-2001 untuk Murabahah).'
        );
        $this->assertEqualsWithDelta(3_000_000, (float) $debet->debet, 0.01);
        $this->assertSame(
            AutoJournalingService::SIMPANAN_SUKARELA,
            Coa::find($kredit->coa_id)->kode_akun,
            'Posisi KREDIT harus akun sumber dana (2-1001 Simpanan Sukarela).'
        );
    }

    public function test_mapping_piutang_konsisten_lewat_jurnal_nyata(): void
    {
        // piutangByAkad() bersifat private, jadi konsistensi mapping diuji lewat
        // jurnal nyata (perilaku yang benar-benar sampai ke pengguna).
        // Akad yang tidak dikenal harus jatuh ke akun Murabahah (default aman).
        $jurnalDefault = $this->service()->jurnalPencairan(1_000_000, 'TidakDikenal', 'Uji default akad');
        $this->assertNotNull($jurnalDefault, 'Akad tidak dikenal harus tetap menghasilkan jurnal (default Murabahah).');
        $this->assertSame(
            '1-2001',
            Coa::find($jurnalDefault->detail[0]->coa_id)->kode_akun,
            'Akad tidak dikenal harus jatuh ke akun Murabahah 1-2001.'
        );

        // Setiap akad wajib mengarah ke akun AKTIF (bukan alias nonaktif).
        foreach (self::PETA_AKAD as $akad => $kodeHarapan) {
            $jurnal = $this->service()->jurnalPencairan(1_000_000, $akad, "Uji akad {$akad}");
            $this->assertNotNull($jurnal, "Jurnal akad {$akad} harus terbentuk.");

            $kode = Coa::find($jurnal->detail[0]->coa_id)->kode_akun;

            $this->assertSame($kodeHarapan, $kode, "Akad {$akad} harus mengarah ke {$kodeHarapan}.");
            $this->assertNotContains($kode, self::AKUN_ALIAS, "Akad {$akad} tidak boleh mengarah ke akun alias.");
            $this->assertNotNull(Coa::findByKode($kode), "Akun {$kode} untuk akad {$akad} harus ada dan aktif.");
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // C. SEEDER & DATA COA
    // ══════════════════════════════════════════════════════════════════

    public function test_seeder_tidak_lagi_membuat_akun_alias(): void
    {
        $isiSeeder = file_get_contents(database_path('seeders/CoaSeeder.php'));

        foreach (self::AKUN_ALIAS as $kode) {
            $this->assertStringNotContainsString(
                "'{$kode}'",
                $isiSeeder,
                "CoaSeeder tidak boleh lagi mendefinisikan akun alias {$kode}."
            );
        }

        // Akun utama tetap ada di seeder.
        foreach (array_values(self::PETA_AKAD) as $kode) {
            $this->assertStringContainsString(
                "'{$kode}'",
                $isiSeeder,
                "CoaSeeder harus tetap mendefinisikan akun utama {$kode}."
            );
        }
    }

    public function test_migrasi_penonaktifan_tidak_menyentuh_akun_yang_terpakai(): void
    {
        // Akun utama punya jurnal → status aktifnya tidak boleh berubah oleh migrasi.
        foreach (array_values(self::PETA_AKAD) as $kode) {
            $this->assertNotNull(Coa::findByKode($kode), "Akun utama {$kode} tidak boleh ikut dinonaktifkan.");
        }

        // Akun alias nol transaksi → dinonaktifkan.
        foreach (self::AKUN_ALIAS as $kode) {
            $this->assertNull(Coa::findByKode($kode), "Akun alias {$kode} tidak boleh aktif.");
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // D. TIDAK ADA ERROR SISTEM SAAT PROSES TRANSAKSI
    // ══════════════════════════════════════════════════════════════════

    public function test_pencairan_via_http_tidak_menghasilkan_error_sistem(): void
    {
        $pembiayaan = $this->pembiayaanDisetujui('Musyarakah', 12_000_000);
        $jumlahSebelum = Jurnal::where('sumber', 'Pencairan Pembiayaan')->count();

        $respons = $this->actingAs($this->pengurus())
            ->post(route('teller.pencairan.cairkan', $pembiayaan->id));

        // Tidak boleh 500 — boleh redirect (sukses) atau redirect balik dengan pesan validasi bisnis.
        $this->assertContains(
            $respons->getStatusCode(),
            [302, 200],
            'Pencairan tidak boleh memunculkan error sistem (500).'
        );

        $pembiayaan->refresh();
        $this->assertSame(
            Pembiayaan::STATUS_DICAIRKAN,
            $pembiayaan->status_pembiayaan,
            'Status pembiayaan harus berubah menjadi dicairkan.'
        );

        // Otomatisasi jurnal baru: pencairan WAJIB menghasilkan jurnal.
        $this->assertGreaterThan(
            $jumlahSebelum,
            Jurnal::where('sumber', 'Pencairan Pembiayaan')->count(),
            'Pencairan lewat TellerPencairanController harus menghasilkan jurnal.'
        );

        $jurnal = $this->jurnalTerakhir();
        $this->assertNotNull($jurnal, 'Jurnal pencairan harus terbentuk.');
        $this->assertSame(
            '1-2003',
            Coa::find($jurnal->detail[0]->coa_id)->kode_akun,
            'Pencairan akad Musyarakah harus terposting ke akun 1-2003.'
        );

        // Nilai yang dicatat adalah POKOK, bukan total_pembiayaan (margin belum jadi hak koperasi).
        $debet = (float) JurnalDetail::where('jurnal_id', $jurnal->id)->sum('debet');
        $this->assertEqualsWithDelta(
            12_000_000.0,
            $debet,
            0.01,
            'Jurnal memakai pokok pembiayaan (jumlah_pembiayaan), bukan total termasuk margin.'
        );
    }

    public function test_setiap_akad_lewat_http_terposting_ke_akun_yang_benar(): void
    {
        foreach (self::PETA_AKAD as $akad => $kodeHarapan) {
            $pembiayaan = $this->pembiayaanDisetujui($akad, 5_000_000);

            $this->actingAs($this->pengurus())
                ->post(route('teller.pencairan.cairkan', $pembiayaan->id))
                ->assertRedirect();

            $pembiayaan->refresh();
            $this->assertSame(
                Pembiayaan::STATUS_DICAIRKAN,
                $pembiayaan->status_pembiayaan,
                "Pencairan akad {$akad} harus berhasil."
            );

            $jurnal = Jurnal::where('sumber', 'Pencairan Pembiayaan')
                ->where('pembiayaan_id', $pembiayaan->id)
                ->orderByDesc('id')
                ->first();

            $this->assertNotNull($jurnal, "Jurnal untuk pencairan akad {$akad} harus terbentuk.");

            $kodeDipakai = Coa::find($jurnal->detail[0]->coa_id)->kode_akun;
            $this->assertSame(
                $kodeHarapan,
                $kodeDipakai,
                "Pencairan akad {$akad} lewat HTTP harus terposting ke {$kodeHarapan}, bukan {$kodeDipakai}."
            );
            $this->assertNotContains(
                $kodeDipakai,
                self::AKUN_ALIAS,
                "Pencairan akad {$akad} tidak boleh memakai akun alias yang sudah nonaktif."
            );
        }
    }

    /** Buat pembiayaan berstatus DISETUJUI yang siap dicairkan (dibuat di dalam transaksi test). */
    private function pembiayaanDisetujui(string $akad, float $pokok): Pembiayaan
    {
        static $urut = 0;
        $urut++;

        return Pembiayaan::create([
            'no_pembiayaan' => 'UJI-COA-'.str_pad((string) $urut, 3, '0', STR_PAD_LEFT),
            'anggota_id' => 1,
            'akun_id' => 1,
            'akad_pembiayaan' => $akad,
            'tujuan_pembiayaan' => 'Uji perapihan COA piutang',
            'jumlah_pembiayaan' => $pokok,
            'margin_nisbah' => 10,
            'total_ujroh' => $pokok * 0.1,
            'total_pembiayaan' => $pokok * 1.1,
            'jumlah_angsuran' => round($pokok * 1.1 / 12, 2),
            'tenor' => 12,
            'sisa_pembiayaan' => $pokok * 1.1,
            'status_pembiayaan' => Pembiayaan::STATUS_DISETUJUI,
            'tanggal_akad' => now()->toDateString(),
            // Kolom NOT NULL tanpa default pada tabel pembiayaan — wajib diisi
            // agar pencairan lewat HTTP dapat diuji (controller membaca
            // tgl_pencairan untuk memvalidasi jatuh tempo pencairan).
            'tanggal_jatuh_tempo' => now()->addMonths(12)->toDateString(),
            'tgl_pencairan' => now()->subDay()->toDateString(),
            'is_disbursed' => false,
        ]);
    }

    public function test_post_menolak_coa_tidak_ada_dengan_exception_bukan_silent_null(): void
    {
        // Kode akun yang tidak terdaftar harus DITOLAK dengan JournalPostingException
        // agar `DB::transaction` pemanggil ikut rollback dan data transaksi tidak
        // tersimpan tanpa jurnal. (Perilaku lama `return null` sudah tidak dipakai.)
        $ditolak = false;

        try {
            $this->service()->post(
                'Uji kode CoA tidak dikenal',
                [['kode' => '9-9999', 'debet' => 100000]],
                'Pencairan Pembiayaan'
            );
        } catch (JournalPostingException $e) {
            $ditolak = true;
            $this->assertSame('COA_TIDAK_DITEMUKAN', $e->aturan);
        }

        $this->assertTrue($ditolak, 'Kode CoA tidak dikenal harus ditolak dengan JournalPostingException.');
    }
}
