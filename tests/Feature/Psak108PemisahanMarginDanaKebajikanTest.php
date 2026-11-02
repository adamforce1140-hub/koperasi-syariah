<?php

namespace Tests\Feature;

use App\Exceptions\JournalPostingException;
use App\Models\Anggota;
use App\Models\AuditLog;
use App\Models\Coa;
use App\Models\DanaKebajikan;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\KebijakanKoperasi;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\AutoJournalingService;
use App\Services\DanaKebajikanService;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * PSAK 108 / PSAK 109 — PEMISAHAN ABSOLUT MARGIN PEMBIAYAAN vs DANA KEBAJIKAN.
 *
 * Menguji seluruh aturan yang ditegakkan pada AutoJournalingService &
 * DanaKebajikanService:
 *
 *  1. MAPPING POST_TYPE (AutoJournalingService)
 *     - POST_TYPE_MARGIN  → hanya boleh KREDIT ke 4-100x (Pendapatan Operasional)
 *     - POST_TYPE_TAAZIR / POST_TYPE_DONATION → hanya boleh KREDIT ke 2-3001
 *     - POST_TYPE_QARDH   → tanpa margin apa pun
 *
 *  2. PENGALOKASIAN MARGIN (Lapis 1 & 3)
 *     - Margin DILARANG dialihkan ke Dana Kebajikan / Dana Sosial.
 *     - Jurnal yang mencampuradukkan Debet-Pendapatan 4-100x dengan
 *       Kredit-Dana Kebajikan 2-3001 DITOLAK.
 *     - Jurnal gabungan SAH (angsuran: pokok + margin + ta'zir) TETAP DIIZINKAN.
 *
 *  3. SUMBER DANA KEBAJIKAN (DanaKebajikanService)
 *     - Hanya Denda Ta'zir (bila fitur `financing_late_fee_per_day` aktif),
 *       Infaq/Shadaqah, dan pengembalian Qardhul Hasan yang sah.
 *     - Sumber lain (khususnya margin) DITOLAK + tercatat di audit log.
 *
 *  4. QARDHUL HASAN TANPA MARGIN
 *     - `marginQardhDilarang()` menolak margin/ujroh untuk akad Qardh.
 *     - Hanya Pokok Piutang Qardh (1-2005) yang dicatat.
 *
 * Setiap skenario pelanggaran diverifikasi BENAR-BENAR DITOLAK (tidak ada
 * jurnal terbentuk) dan meninggalkan jejak log audit (Log::error) untuk
 * keperluan audit internal maupun eksternal.
 */
class Psak108PemisahanMarginDanaKebajikanTest extends TestCase
{
    use DatabaseTransactions;

    private AutoJournalingService $jurnal;

    private DanaKebajikanService $dana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jurnal = app(AutoJournalingService::class);
        $this->dana = app(DanaKebajikanService::class);
    }

    /* =====================================================================
     |  HELPER
     ===================================================================== */

    private function kodeAkun(string $kode): ?string
    {
        return Coa::findByKode($kode) ? Coa::findByKode($kode)->kode_akun : null;
    }

    /** Pastikan CoA kunci tersedia, jika tidak → skip suite (lingkungan belum di-seed). */
    private function pastikanCoa(): void
    {
        foreach ([
            AutoJournalingService::KAS_TELLER,
            AutoJournalingService::SIMPANAN_SUKARELA,
            AutoJournalingService::DANA_KEBAJIKAN,
            AutoJournalingService::PENDAPATAN_MARGIN,
            AutoJournalingService::PINJAMAN_QARDH,
        ] as $kode) {
            if (! Coa::findByKode($kode)) {
                $this->markTestSkipped("CoA {$kode} belum ter-seed.");
            }
        }
    }

    /** Aktifkan / nonaktifkan fitur denda keterlambatan angsuran. */
    private function setFiturDenda(float $perHari): void
    {
        $kebijakan = KebijakanKoperasi::first() ?? new KebijakanKoperasi;
        $kebijakan->tazir_mode = $perHari > 0 ? 'FLAT_HARIAN' : 'DISABLED';
        $kebijakan->financing_late_fee_per_day = $perHari;
        $kebijakan->save();

        Setting::flush();
    }

    private function teller(): User
    {
        return User::firstOrCreate(
            ['email' => 'teller.psak108@example.test'],
            ['name' => 'Teller PSAK 108 Test', 'password' => Hash::make('secret'), 'role' => 'teller', 'is_active' => true]
        );
    }

    /** Buat anggota + rekening simpanan sukarela untuk uji auto-debet. */
    private function anggotaDenganRekening(string $suffix = 'PSAK108', float $saldo = 1_000_000): Anggota
    {
        $anggota = Anggota::create([
            'no_anggota' => 'A108-'.substr(md5($suffix), 0, 8),
            'nama_lengkap' => 'Anggota PSAK 108 '.$suffix,
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Pengujian PSAK No. 108',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => 'Aktif',
            'tanggal_gabung' => now()->toDateString(),
        ]);

        $coaSimpanan = Coa::findByKode(AutoJournalingService::SIMPANAN_SUKARELA)
            ?? Coa::query()->orderBy('id')->firstOrFail();

        $rekening = Rekening::create([
            'no_rekening' => 'R108-'.substr(md5($suffix), 0, 8),
            'anggota_id' => $anggota->id,
            'akun_id' => $coaSimpanan->id,
            'jenis_rekening' => 'Simpanan Sukarela',
            'akad' => 'Wadiah',
            'saldo' => $saldo,
            'batas_minimum' => 0,
            'tanggal_buka' => now()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);

        $anggota->rekening_id = $rekening->id;

        return $anggota;
    }

    /** Buat transaksi tunai teller (default: penerimaan ta'zir tunai). */
    private function buatTransaksiTunai(
        User $teller,
        string $jenis,
        float $jumlah,
        ?int $rekeningId = null,
        ?int $anggotaId = null
    ): Transaksi {
        return Transaksi::create([
            'no_transaksi' => 'TRX-PSAK108-'.uniqid(),
            'jenis_transaksi' => $jenis,
            'posisi' => 'Kredit',
            'jumlah' => $jumlah,
            'sumber_transaksi' => 'Teller',
            'status_transaksi' => 'Sukses',
            'status' => 'success',
            'user_id' => $teller->id,
            'rekening_id' => $rekeningId,
            'anggota_id' => $anggotaId,
            'waktu_transaksi' => now(),
            'catatan' => 'Uji PSAK 108 — '.$jenis,
        ]);
    }

    /** Ringkasan jumlah baris jurnal pada kode akun tertentu. */
    private function mutasiAkun(?Jurnal $jurnal, string $kodeAkun): array
    {
        if (! $jurnal) {
            return ['debet' => 0.0, 'kredit' => 0.0];
        }

        $coa = Coa::findByKode($kodeAkun);
        if (! $coa) {
            return ['debet' => 0.0, 'kredit' => 0.0];
        }

        $rows = JurnalDetail::where('jurnal_id', $jurnal->id)
            ->where('coa_id', $coa->id)
            ->get();

        return [
            'debet' => (float) $rows->sum('debet'),
            'kredit' => (float) $rows->sum('kredit'),
        ];
    }

    private function jumlahJurnal(): int
    {
        return Jurnal::query()->count();
    }

    /* =====================================================================
     |  1. MAPPING POST_TYPE — PENOLAKAN MISROUTING
     ===================================================================== */

    public function test_pastikan_coa_tersedia(): void
    {
        $this->pastikanCoa();
        $this->assertNotNull($this->kodeAkun(AutoJournalingService::DANA_KEBAJIKAN));
        $this->assertSame('4-', substr((string) $this->kodeAkun(AutoJournalingService::PENDAPATAN_MARGIN), 0, 2));
    }

    public function test_post_type_taazir_ditolak_bila_kredit_mengarah_ke_pendapatan_margin(): void
    {
        $this->pastikanCoa();

        $sebelum = $this->jumlahJurnal();

        // PELANGGARAN: ta'zir (non-operasional) dipetakan ke 4-1001 (operasional).
        try {
            $this->jurnal->post(
                "Uji misrouting: ta'zir ke akun pendapatan margin",
                [
                    ['kode' => AutoJournalingService::KAS_TELLER,      'debet' => 50_000],
                    ['kode' => AutoJournalingService::PENDAPATAN_MARGIN, 'kredit' => 50_000],
                ],
                'Uji Ta\'zir Misrouting',
                null, null, null, null, null, null,
                AutoJournalingService::POST_TYPE_TAAZIR
            );

            $this->fail('POST_TYPE_TAAZIR ke 4-1001 HARUS ditolak.');
        } catch (JournalPostingException $e) {
            $this->assertSame('MAPPING_POST_TYPE', $e->aturan);
        }

        $this->assertSame($sebelum, $this->jumlahJurnal(), 'Tidak boleh ada jurnal terbentuk saat misrouting.');
    }

    public function test_post_type_margin_ditolak_bila_kredit_mengarah_ke_dana_kebajikan(): void
    {
        $this->pastikanCoa();

        $sebelum = $this->jumlahJurnal();

        // PELANGGARAN: margin operasional dipetakan ke 2-3001 (non-operasional).
        try {
            $this->jurnal->post(
                'Uji misrouting: margin ke akun dana kebajikan',
                [
                    ['kode' => AutoJournalingService::KAS_TELLER,       'debet' => 100_000],
                    ['kode' => AutoJournalingService::DANA_KEBAJIKAN,   'kredit' => 100_000],
                ],
                'Uji Margin Misrouting',
                null, null, null, null, null, null,
                AutoJournalingService::POST_TYPE_MARGIN
            );

            $this->fail('POST_TYPE_MARGIN ke 2-3001 HARUS ditolak.');
        } catch (JournalPostingException $e) {
            $this->assertSame('MAPPING_POST_TYPE', $e->aturan);
        }

        $this->assertSame($sebelum, $this->jumlahJurnal());
    }

    public function test_post_type_taazir_sah_bila_kredit_ke_dana_kebajikan(): void
    {
        $this->pastikanCoa();

        $jurnal = $this->jurnal->post(
            "Ta'zir sah ke Dana Kebajikan",
            [
                ['kode' => AutoJournalingService::KAS_TELLER,     'debet' => 25_000],
                ['kode' => AutoJournalingService::DANA_KEBAJIKAN, 'kredit' => 25_000],
            ],
            "Penerimaan Ta'zir",
            null, null, null, null, null, null,
            AutoJournalingService::POST_TYPE_TAAZIR
        );

        $this->assertNotNull($jurnal, "Ta'zir ke 2-3001 harus SAH.");
        $this->assertSame(25_000.0, $this->mutasiAkun($jurnal, AutoJournalingService::DANA_KEBAJIKAN)['kredit']);
    }

    public function test_post_type_donation_sah_hanya_ke_dana_kebajikan(): void
    {
        $this->pastikanCoa();

        $jurnal = $this->jurnal->post(
            'Infaq sah ke Dana Kebajikan',
            [
                ['kode' => AutoJournalingService::KAS_TELLER,     'debet' => 10_000],
                ['kode' => AutoJournalingService::DANA_KEBAJIKAN, 'kredit' => 10_000],
            ],
            'Penerimaan Infaq',
            null, null, null, null, null, null,
            AutoJournalingService::POST_TYPE_DONATION
        );

        $this->assertNotNull($jurnal);

        // Pelanggaran: DONATION ke akun pendapatan.
        $sebelum = $this->jumlahJurnal();
        try {
            $this->jurnal->post(
                'Infaq misrouting ke pendapatan margin',
                [
                    ['kode' => AutoJournalingService::KAS_TELLER,       'debet' => 10_000],
                    ['kode' => AutoJournalingService::PENDAPATAN_MARGIN, 'kredit' => 10_000],
                ],
                'Uji Donation Misrouting',
                null, null, null, null, null, null,
                AutoJournalingService::POST_TYPE_DONATION
            );

            $this->fail('POST_TYPE_DONATION ke 4-1001 HARUS ditolak.');
        } catch (JournalPostingException $e) {
            $this->assertSame('MAPPING_POST_TYPE', $e->aturan);
        }

        $this->assertSame($sebelum, $this->jumlahJurnal());
    }

    /* =====================================================================
     |  2. LAPIS 1 & 3 — LARANGAN PENGALOKASIAN / PENCAMPURAN MARGIN
     ===================================================================== */

    public function test_pengalokasian_margin_ke_dana_kebajikan_ditolak(): void
    {
        $this->pastikanCoa();

        $sebelum = $this->jumlahJurnal();

        // POLA PELANGGARAN KLASIK: Debet Pendapatan Margin | Kredit Dana Kebajikan
        // (mengalihkan margin keluar dari Pendapatan Operasional).
        try {
            $this->jurnal->post(
                'Uji pengalihan margin ke dana kebajikan',
                [
                    ['kode' => AutoJournalingService::PENDAPATAN_MARGIN, 'debet' => 500_000],
                    ['kode' => AutoJournalingService::DANA_KEBAJIKAN,    'kredit' => 500_000],
                ],
                'Uji Alokasi Margin Terlarang'
            );

            $this->fail('Pengalokasian margin ke Dana Kebajikan HARUS ditolak permanen.');
        } catch (JournalPostingException $e) {
            $this->assertSame('PEMISAHAN_MARGIN_KEBAJIKAN', $e->aturan);
        }

        $this->assertSame($sebelum, $this->jumlahJurnal());
    }

    public function test_jurnal_gabungan_debet_pendapatan_dan_kredit_kebajikan_ditolak(): void
    {
        $this->pastikanCoa();

        $sebelum = $this->jumlahJurnal();

        // Mencampur dalam satu entri: 4-1001 (debet) + 2-3001 (kredit) — DILARANG.
        try {
            $this->jurnal->post(
                'Uji campur margin operasional dan dana kebajikan',
                [
                    ['kode' => AutoJournalingService::KAS_TELLER,        'debet' => 100_000],
                    ['kode' => AutoJournalingService::PENDAPATAN_MARGIN, 'debet' => 100_000],
                    ['kode' => AutoJournalingService::DANA_KEBAJIKAN,    'kredit' => 200_000],
                ],
                'Uji Campur Terlarang'
            );

            $this->fail('Entri campur 4-1001 & 2-3001 (debet pendapatan/kredit kebajikan) HARUS ditolak.');
        } catch (JournalPostingException $e) {
            // Lapis 1 (pemisahan margin) dievaluasi lebih dulu daripada lapis 3 (pencampuran).
            $this->assertSame('PEMISAHAN_MARGIN_KEBAJIKAN', $e->aturan);
        }

        $this->assertSame($sebelum, $this->jumlahJurnal());
    }

    public function test_jurnal_gabungan_angsuran_sah_tetap_diizinkan(): void
    {
        $this->pastikanCoa();

        // JURNAL GABUNGAN SAH: pokok + margin + ta'zir dalam satu entri angsuran.
        // Kredit 4-100x dan kredit 2-3001 berdampingan (BUKAN debet pendapatan).
        $jurnal = $this->jurnal->post(
            'Angsuran: pokok + margin + denda ta\'zir',
            [
                ['kode' => AutoJournalingService::KAS_TELLER,        'debet' => 1_050_000],
                ['kode' => \App\Support\Coa::PIUTANG_MURABAHAH,      'kredit' => 1_000_000],
                ['kode' => AutoJournalingService::PENDAPATAN_MARGIN, 'kredit' => 40_000],
                ['kode' => AutoJournalingService::DANA_KEBAJIKAN,    'kredit' => 10_000],
            ],
            'Angsuran'
        );

        $this->assertNotNull($jurnal, 'Jurnal gabungan angsuran yang sah HARUS tetap diizinkan.');
        $this->assertSame(40_000.0, $this->mutasiAkun($jurnal, AutoJournalingService::PENDAPATAN_MARGIN)['kredit']);
        $this->assertSame(10_000.0, $this->mutasiAkun($jurnal, AutoJournalingService::DANA_KEBAJIKAN)['kredit']);
    }

    public function test_pelanggaran_pemisahan_tercatat_di_log_audit(): void
    {
        $this->pastikanCoa();

        Log::spy();

        try {
            $this->jurnal->post(
                'Uji jejak audit pelanggaran pemisahan',
                [
                    ['kode' => AutoJournalingService::PENDAPATAN_MARGIN, 'debet' => 250_000],
                    ['kode' => AutoJournalingService::DANA_KEBAJIKAN,    'kredit' => 250_000],
                ],
                'Uji Audit Log'
            );
            $this->fail('Pelanggaran pemisahan margin HARUS ditolak dengan JournalPostingException.');
        } catch (JournalPostingException $e) {
            $this->assertSame('PEMISAHAN_MARGIN_KEBAJIKAN', $e->aturan);
        }

        Log::shouldHaveReceived('error')
            ->withArgs(function ($pesan, $konteks = []) {
                return str_contains($pesan, 'GAGAL posting jurnal')
                    && ($konteks['aturan'] ?? null) === 'PEMISAHAN_MARGIN_KEBAJIKAN'
                    && ($konteks['sumber'] ?? null) === 'Uji Audit Log';
            })
            ->atLeast()->once();
    }

    /* =====================================================================
     |  3. QARDHUL HASAN — TANPA MARGIN
     ===================================================================== */

    public function test_margin_qardh_dilarang_terdeteksi(): void
    {
        $this->assertTrue($this->jurnal->marginQardhDilarang('Qardh', 50_000));
        $this->assertTrue($this->jurnal->marginQardhDilarang('Qardhul Hasan', 0, 25_000));
        $this->assertFalse($this->jurnal->marginQardhDilarang('Qardh', 0, 0), 'Qardh tanpa margin = sah.');
        $this->assertFalse($this->jurnal->marginQardhDilarang('Murabahah', 50_000), 'Murabahah wajib punya margin.');
    }

    public function test_jurnal_angsuran_qardh_menolak_margin(): void
    {
        $this->pastikanCoa();

        $sebelum = $this->jumlahJurnal();

        // PELANGGARAN: angsuran akad Qardh DIBERI margin → harus ditolak.
        // Catatan: penolakan ini terjadi di gerbang awal `jurnalAngsuran()`
        // (marginQardhDilarang) sehingga masih mengembalikan null — belum masuk
        // `post()`. Tidak ada jurnal yang boleh terbentuk.
        $jurnal = $this->jurnal->jurnalAngsuran(
            1_000_000,   // pokok
            500_000,     // margin (TERLARANG untuk Qardh)
            0,
            'Uji angsuran qardh dengan margin',
            null, null, null,
            'Qardh'
        );

        $this->assertNull($jurnal, 'Qardh TIDAK BOLEH memiliki margin — jurnal harus ditolak.');
        $this->assertSame($sebelum, $this->jumlahJurnal());
    }

    public function test_jurnal_angsuran_qardh_tanpa_margin_hanya_pokok(): void
    {
        $this->pastikanCoa();

        $jurnal = $this->jurnal->jurnalAngsuran(
            1_000_000,   // pokok
            0,           // tanpa margin
            0,
            'Angsuran Qardh tanpa margin',
            null, null, null,
            'Qardh'
        );

        $this->assertNotNull($jurnal, 'Angsuran Qardh hanya pokok harus SAH.');

        // Hanya pokok piutang qardh (1-2005) yang berkurang — tidak ada pendapatan.
        $this->assertSame(1_000_000.0, $this->mutasiAkun($jurnal, AutoJournalingService::PINJAMAN_QARDH)['kredit']);
        $this->assertSame(0.0, $this->mutasiAkun($jurnal, AutoJournalingService::PENDAPATAN_MARGIN)['kredit']);
    }

    /* =====================================================================
     |  4. SUMBER DANA KEBAJIKAN — VALIDASI PERMANEN
     ===================================================================== */

    public function test_sumber_dana_taazir_hanya_sah_bila_fitur_aktif(): void
    {
        // Fitur NONAKTIF → ta'zir tidak sah.
        $this->setFiturDenda(0);
        $this->assertFalse($this->dana->sumberDanaKebajikanValid("Pemotongan Ta'zir Keterlambatan"));
        $this->assertFalse($this->dana->sumberDanaKebajikanValid('Denda Keterlambatan'));

        // Fitur AKTIF → ta'zir sah.
        $this->setFiturDenda(2500);
        $this->assertTrue($this->dana->sumberDanaKebajikanValid("Pemotongan Ta'zir Keterlambatan"));
        $this->assertTrue($this->dana->sumberDanaKebajikanValid('Denda Keterlambatan'));
    }

    public function test_sumber_dana_infaq_shadaqah_dan_qardh_selalu_sah(): void
    {
        $this->setFiturDenda(0); // bahkan saat fitur denda mati

        foreach ([
            'Infak Tunai',
            'Infaq Anggota',
            'Shadaqah',
            'Sedekah Anggota',
            'Kontribusi Kebajikan Tunai',
            'Pengembalian Pinjaman Qardhul Hasan',
        ] as $jenis) {
            $this->assertTrue(
                $this->dana->sumberDanaKebajikanValid($jenis),
                "Sumber \"{$jenis}\" seharusnya SAH."
            );
        }
    }

    public function test_sumber_dana_margin_selalu_ditolak(): void
    {
        $this->setFiturDenda(2500); // fitur aktif pun tidak menyelamatkan misrouting margin

        foreach ([
            'Margin Murabahah',
            'Bagi Hasil Mudharabah',
            'Bagi Hasil Musyarakah',
            'Pendapatan Margin Pembiayaan',
        ] as $jenis) {
            $this->assertFalse(
                $this->dana->sumberDanaKebajikanValid($jenis),
                "Sumber \"{$jenis}\" (pendapatan operasional) HARUS DITOLAK."
            );
            $this->assertTrue($this->dana->mengandungMarginTerlarang($jenis));
        }
    }

    public function test_sumber_dana_tidak_diakui_ditolak(): void
    {
        $this->setFiturDenda(2500);

        foreach (['Kontribusi Operasional', 'Setoran Modal', 'Bunga Pinjaman', ''] as $jenis) {
            $this->assertFalse(
                $this->dana->sumberDanaKebajikanValid($jenis),
                "Sumber \"{$jenis}\" harus DITOLAK."
            );
        }
    }

    /**
     * R7 (tindak lanjut) — Ta'zir penalti yang namanya memuat NAMA AKAD tidak
     * boleh dibaca sebagai misrouting margin.
     *
     * Penalti pencairan dini deposito dinamai "... Deposito Mudharabah" sehingga
     * kata 'mudharabah' (kata kunci margin terlarang) muncul pada jenis transaksi
     * ta'zir yang SAH. Pengabaian kata kunci tersebut HANYA berlaku bila jenis
     * transaksi menyatakan ta'zir/denda/penalti secara eksplisit — sehingga
     * margin pembiayaan yang sesungguhnya TETAP ditolak.
     */
    public function test_ta_zir_berakad_tidak_dibaca_sebagai_misrouting_margin(): void
    {
        $this->setFiturDenda(2500);

        // Kata kunci margin tetap terdeteksi (untuk keperluan audit)…
        $this->assertTrue($this->dana->mengandungMarginTerlarang('Penalti Pencairan Dini Deposito Mudharabah'));

        // …namun karena menyatakan ta'zir/penalti eksplisit, sumber dana tetap SAH.
        $this->assertTrue(
            $this->dana->sumberDanaKebajikanValid('Penalti Pencairan Dini Deposito Mudharabah'),
            'Penalti berakad (mis. Deposito Mudharabah) adalah ta\'zir, bukan margin pembiayaan.'
        );
        $this->assertTrue(
            $this->dana->sumberDanaKebajikanValid('Denda Keterlambatan Angsuran Murabahah'),
            'Denda keterlambatan pada akad Murabahah tetap ta\'zir yang sah.'
        );

        // Margin pembiayaan sesungguhnya TETAP DITOLAK meski fitur denda aktif.
        foreach (['Penerimaan Margin Murabahah', 'Pendapatan Bagi Hasil Mudharabah', 'Bagi Hasil Musyarakah'] as $jenis) {
            $this->assertFalse(
                $this->dana->sumberDanaKebajikanValid($jenis),
                "Margin \"{$jenis}\" harus TETAP DITOLAK."
            );
        }

        // Fitur denda nonaktif → ta'zir berakad ikut ditolak (ta'zir wajib diaktifkan pengurus).
        $this->setFiturDenda(0);
        $this->assertFalse(
            $this->dana->sumberDanaKebajikanValid('Penalti Pencairan Dini Deposito Mudharabah'),
            'Ta\'zir wajib ditolak bila fitur denda belum diaktifkan pengurus.'
        );
    }

    /**
     * R7 (tindak lanjut) — Ta'zir penalti pencairan dini deposito harus muncul di
     * riwayat Dana Kebajikan sebagai PENERIMAAN (badge Kredit), bukan penyaluran.
     */
    public function test_penalti_pencairan_dini_deposito_tampil_di_riwayat_sebagai_penerimaan(): void
    {
        $teller = $this->teller();
        $transaksi = $this->buatTransaksiTunai($teller, 'Pencairan Dini Deposito Mudharabah', 25_000);

        $adaDiRiwayat = $this->dana->riwayat(50)
            ->getCollection()
            ->contains(fn ($t) => $t->id === $transaksi->id);

        $this->assertTrue(
            $adaDiRiwayat,
            'Ta\'zir penalti pencairan dini deposito wajib tampil di riwayat Dana Kebajikan.'
        );

        $this->assertSame(
            'Kredit',
            $this->dana->posisiKebajikan('Pencairan Dini Deposito Mudharabah'),
            'Penalti pencairan dini adalah dana MASUK ke 2-3001 (badge Kredit), bukan penyaluran.'
        );
        $this->assertFalse(
            $this->dana->jenisPenyaluranDanaSosial('Pencairan Dini Deposito Mudharabah'),
            'Penalti pencairan dini BUKAN penyaluran dana sosial.'
        );
    }

    /* =====================================================================
     |  5. INTEGRASI — SINKRONISASI TRANSAKSI TUNAI TELLER
     ===================================================================== */

    public function test_ta_zir_tunai_ditolak_dan_tercatat_audit_saat_fitur_nonaktif(): void
    {
        $this->pastikanCoa();
        $this->setFiturDenda(0);

        $teller = $this->teller();
        $anggota = $this->anggotaDenganRekening('TAZIR-OFF');
        $transaksi = $this->buatTransaksiTunai($teller, "Pemotongan Ta'zir Keterlambatan", 40_000, $anggota->rekening_id, $anggota->id);

        $sebelumJurnal = $this->jumlahJurnal();
        $sebelumKebajikan = DanaKebajikan::query()->count();

        $hasil = $this->dana->sinkronkanTransaksiTunaiTeller($transaksi);

        $this->assertNull($hasil, "Ta'zir saat fitur NONAKTIF HARUS ditolak.");
        $this->assertSame($sebelumJurnal, $this->jumlahJurnal(), 'Tidak boleh ada jurnal Dana Kebajikan.');
        $this->assertSame($sebelumKebajikan, DanaKebajikan::query()->count(), 'Tidak boleh ada pencatatan dana kebajikan.');

        $this->assertTrue(
            AuditLog::where('jenis_transaksi', 'Pelanggaran Sumber Dana Kebajikan')->exists(),
            'Setiap upaya pelanggaran WAJIB tercatat di audit log.'
        );
    }

    public function test_margin_tunai_ditolak_dan_tercatat_audit_walaupun_fitur_denda_aktif(): void
    {
        $this->pastikanCoa();
        $this->setFiturDenda(2500);

        $teller = $this->teller();
        $anggota = $this->anggotaDenganRekening('MARGIN-REJECT');
        $transaksi = $this->buatTransaksiTunai($teller, 'Penerimaan Margin Murabahah', 500_000, $anggota->rekening_id, $anggota->id);

        $sebelumJurnal = $this->jumlahJurnal();

        $hasil = $this->dana->sinkronkanTransaksiTunaiTeller($transaksi);

        $this->assertNull($hasil, 'Margin pembiayaan DILARANG masuk ke Dana Kebajikan.');
        $this->assertSame($sebelumJurnal, $this->jumlahJurnal());

        // Alasan audit harus menyebut misrouting margin.
        $log = AuditLog::where('jenis_transaksi', 'Pelanggaran Sumber Dana Kebajikan')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('MISROUTING MARGIN', (string) $log->keterangan);
    }

    public function test_infak_tunai_sah_dan_membentuk_jurnal_kredit_dana_kebajikan(): void
    {
        $this->pastikanCoa();
        $this->setFiturDenda(0);

        $teller = $this->teller();
        $anggota = $this->anggotaDenganRekening('INFAQ-OK');
        $transaksi = $this->buatTransaksiTunai($teller, 'Infak Tunai', 75_000, $anggota->rekening_id, $anggota->id);

        $hasil = $this->dana->sinkronkanTransaksiTunaiTeller($transaksi);

        $this->assertNotNull($hasil, 'Infaq tunai harus SAH dicatat.');
        $this->assertEqualsWithDelta(75_000, (float) $hasil->jumlah, 0.01);

        $jurnal = Jurnal::where('transaksi_id', $transaksi->id)->first();
        $this->assertNotNull($jurnal, 'Infaq harus membentuk jurnal.');
        $this->assertSame(75_000.0, $this->mutasiAkun($jurnal, AutoJournalingService::DANA_KEBAJIKAN)['kredit']);
    }

    public function test_qardhul_hasan_tunai_sah_sebagai_pengembalian_pokok(): void
    {
        $this->pastikanCoa();
        $this->setFiturDenda(0);

        $teller = $this->teller();
        $anggota = $this->anggotaDenganRekening('QARDH-RET');
        $transaksi = $this->buatTransaksiTunai($teller, 'Pengembalian Pinjaman Qardhul Hasan', 200_000, $anggota->rekening_id, $anggota->id);

        $hasil = $this->dana->sinkronkanTransaksiTunaiTeller($transaksi);

        $this->assertNotNull($hasil, 'Pengembalian Qardhul Hasan harus SAH (tanpa margin).');

        $jurnal = Jurnal::where('transaksi_id', $transaksi->id)->first();
        $this->assertSame(200_000.0, $this->mutasiAkun($jurnal, AutoJournalingService::DANA_KEBAJIKAN)['kredit']);
        $this->assertSame(0.0, $this->mutasiAkun($jurnal, AutoJournalingService::PENDAPATAN_MARGIN)['kredit'], 'Qardh tidak boleh menambah pendapatan margin.');
    }

    /* =====================================================================
     |  6. PARAMETER financing_late_fee_per_day — SATU SUMBER NILAI
     ===================================================================== */

    public function test_parameter_financing_late_fee_per_day_terdaftar_dan_berfungsi(): void
    {
        $this->assertArrayHasKey('financing_late_fee_per_day', Setting::DEFAULTS);
        $this->assertSame('decimal', Setting::TIPE['financing_late_fee_per_day'] ?? null);

        $this->setFiturDenda(3500);
        $this->assertSame(3500.0, Setting::financingLateFeePerDay());
        $this->assertTrue(Setting::fiturDendaPembiayaanAktif());

        $this->setFiturDenda(0);
        $this->assertSame(0.0, Setting::financingLateFeePerDay());
        $this->assertFalse(Setting::fiturDendaPembiayaanAktif());
    }
}
