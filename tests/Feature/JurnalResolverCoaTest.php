<?php

namespace Tests\Feature;

use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\KebijakanKoperasi;
use App\Models\User;
use App\Services\AutoJournalingService;
use App\Support\Coa as CoaRegistri;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * REGRESI — AutoJournalingService WAJIB memakai CoaResolver (zero-hardcoding).
 *
 * Sebelum perbaikan, jalur akun tidak konsisten:
 *   - `jurnalPencairan()`  → akun piutang dari CoaResolver (dinamis), sedangkan
 *   - `jurnalAngsuran()`   → akun piutang dari registri statis App\Support\Coa.
 *
 * Akibatnya, bila pengurus memindahkan `account_piutang_murabahah_id`, piutang
 * BERTAMBAH di akun baru saat pencairan tetapi BERKURANG di akun lama saat
 * angsuran — saldo piutang tidak akan pernah nol.
 *
 * Test ini mengunci dua hal sekaligus:
 *   1. Perilaku lama tetap utuh saat kebijakan kosong (kode akun tidak berubah).
 *   2. Saat kebijakan diarahkan ke akun lain, SEMUA jalur (pencairan, angsuran,
 *      reversal) ikut berpindah ke akun yang sama.
 */
class JurnalResolverCoaTest extends TestCase
{
    use DatabaseTransactions;

    /* ==================================================================
     | FIXTURE
     ================================================================== */

    private function akun(
        string $kode,
        string $nama,
        string $kategori = 'Aset',
        string $klasifikasi = 'Aset Lancar',
        string $tipe = 'Debet'
    ): Coa {
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

    private function pengurus(): User
    {
        return User::firstOrCreate(
            ['email' => 'pengurus.resolvercoa@test.local'],
            [
                'name' => 'Pengurus Resolver CoA',
                'password' => Hash::make('password'),
                'role' => 'pengurus',
                'is_active' => true,
            ]
        );
    }

    private function aturKebijakan(array $kolom): void
    {
        $kebijakan = KebijakanKoperasi::first() ?? KebijakanKoperasi::create([]);
        $kebijakan->forceFill($kolom)->save();
        Setting::flush();
    }

    /** Akun standar minimum agar setiap jurnal dapat diposting. */
    private function siapkanAkunStandar(): void
    {
        $this->akun(CoaRegistri::KAS_TELLER, 'Kas Teller');
        $this->akun(CoaRegistri::SIMPANAN_SUKARELA, 'Simpanan Sukarela Anggota', 'Kewajiban', 'Kewajiban Jangka Pendek', 'Kredit');
        $this->akun(CoaRegistri::PIUTANG_MURABAHAH, 'Piutang Pembiayaan Murabahah');
        $this->akun(CoaRegistri::MARGIN_DITANGGUHKAN, 'Margin Murabahah Ditangguhkan', 'Kewajiban', 'Kewajiban Jangka Pendek', 'Kredit');
        $this->akun(CoaRegistri::PENDAPATAN_MARGIN, 'Pendapatan Margin Murabahah', 'Pendapatan', 'Pendapatan', 'Kredit');
    }

    /** Kumpulan kode akun yang muncul pada detail jurnal. */
    private function kodeJurnal(?Jurnal $jurnal): array
    {
        $this->assertNotNull($jurnal, 'Jurnal wajib terbentuk.');

        return $jurnal->detail()
            ->with('coa')
            ->get()
            ->map(fn ($baris) => $baris->coa?->kode_akun)
            ->filter()
            ->values()
            ->all();
    }

    /* ==================================================================
     | 1. PERILAKU LAMA TERJAGA (kebijakan kosong)
     ================================================================== */

    public function test_kode_akun_tetap_standar_saat_kebijakan_coa_kosong(): void
    {
        $this->siapkanAkunStandar();
        $user = $this->pengurus();

        $this->aturKebijakan([
            'account_piutang_murabahah_id' => null,
            'account_margin_deferred_id' => null,
        ]);

        $jurnal = app(AutoJournalingService::class)->jurnalAngsuran(
            1_000_000,
            200_000,
            0,
            'Angsuran murabahah',
            $user->id,
            null,
            null,
            'Murabahah'
        );

        $kode = $this->kodeJurnal($jurnal);

        $this->assertContains(CoaRegistri::PIUTANG_MURABAHAH, $kode, 'Piutang wajib tetap di akun standar.');
        $this->assertContains(CoaRegistri::MARGIN_DITANGGUHKAN, $kode, 'Margin ditangguhkan wajib tetap di akun standar.');
        $this->assertContains(CoaRegistri::PENDAPATAN_MARGIN, $kode, 'Pendapatan wajib tetap di akun standar.');
    }

    /* ==================================================================
     | 2. PENC AIRAN & ANGSURAN MEMAKAI AKUN PIUTANG YANG SAMA
     ================================================================== */

    public function test_pencairan_dan_angsuran_memakai_akun_piutang_yang_sama(): void
    {
        $this->siapkanAkunStandar();
        $user = $this->pengurus();

        $this->aturKebijakan([
            'account_piutang_murabahah_id' => null,
            'account_margin_deferred_id' => null,
        ]);

        $service = app(AutoJournalingService::class);

        $pencairan = $service->jurnalPencairan(
            1_000_000,
            'Murabahah',
            'Pencairan murabahah',
            $user->id,
            null,
            null,
            null,
            null,
            200_000
        );

        $angsuran = $service->jurnalAngsuran(
            1_000_000,
            200_000,
            0,
            'Angsuran murabahah',
            $user->id,
            null,
            null,
            'Murabahah'
        );

        $this->assertContains(CoaRegistri::PIUTANG_MURABAHAH, $this->kodeJurnal($pencairan));
        $this->assertContains(CoaRegistri::PIUTANG_MURABAHAH, $this->kodeJurnal($angsuran));

        // Konsistensi eksplisit: akun piutang pada kedua jurnal identik.
        $piutangPencairan = array_values(array_filter(
            $this->kodeJurnal($pencairan),
            fn (string $k) => str_starts_with($k, '1-2')
        ));
        $piutangAngsuran = array_values(array_filter(
            $this->kodeJurnal($angsuran),
            fn (string $k) => str_starts_with($k, '1-2')
        ));

        $this->assertSame(
            $piutangPencairan,
            $piutangAngsuran,
            'Pencairan dan angsuran WAJIB menyentuh akun piutang yang sama.'
        );
    }

    /* ==================================================================
     | 3. PERPINDAHAN AKUN LEWAT KEBIJAKAN DIIKUTI SEMUA JALUR
     ================================================================== */

    public function test_angsuran_mengikuti_perpindahan_akun_piutang_dari_kebijakan(): void
    {
        $this->siapkanAkunStandar();
        $user = $this->pengurus();

        $piutangBaru = $this->akun('1-2011', 'Piutang Murabahah Produktif');
        $marginBaru = $this->akun(
            '1-2102',
            'Margin Murabahah Ditangguhkan Produktif',
            'Kewajiban',
            'Kewajiban Jangka Pendek',
            'Kredit'
        );

        $this->aturKebijakan([
            'account_piutang_murabahah_id' => $piutangBaru->id,
            'account_margin_deferred_id' => $marginBaru->id,
        ]);

        $service = app(AutoJournalingService::class);

        $angsuran = $service->jurnalAngsuran(
            1_000_000,
            200_000,
            0,
            'Angsuran murabahah',
            $user->id,
            null,
            null,
            'Murabahah'
        );

        $kodeAngsuran = $this->kodeJurnal($angsuran);

        $this->assertContains($piutangBaru->kode_akun, $kodeAngsuran, 'Angsuran wajib mengikuti akun piutang baru.');
        $this->assertNotContains(CoaRegistri::PIUTANG_MURABAHAH, $kodeAngsuran, 'Akun piutang lama tidak boleh disentuh lagi.');
        $this->assertContains($marginBaru->kode_akun, $kodeAngsuran, 'Amortisasi margin wajib mengikuti akun margin baru.');

        // Proxy publik yang dipakai AngsuranTransactionService juga ikut berpindah.
        $this->assertSame($piutangBaru->kode_akun, $service->getPiutangByAkad('Murabahah'));
        $this->assertSame($marginBaru->kode_akun, $service->getMarginDitangguhkanByAkad('Murabahah'));
    }

    public function test_reversal_pencairan_memakai_akun_yang_sama_dengan_pencairan(): void
    {
        $this->siapkanAkunStandar();

        $piutangBaru = $this->akun('1-2011', 'Piutang Murabahah Produktif');
        $marginBaru = $this->akun(
            '1-2102',
            'Margin Murabahah Ditangguhkan Produktif',
            'Kewajiban',
            'Kewajiban Jangka Pendek',
            'Kredit'
        );

        $this->aturKebijakan([
            'account_piutang_murabahah_id' => $piutangBaru->id,
            'account_margin_deferred_id' => $marginBaru->id,
        ]);

        $service = app(AutoJournalingService::class);

        $pencairan = $service->jurnalPencairan(
            1_000_000,
            'Murabahah',
            'Pencairan murabahah',
            null,
            null,
            null,
            null,
            null,
            200_000
        );

        $reversal = $service->jurnalReversalPencairanPembiayaan(
            1_000_000,
            200_000,
            'Pembatalan pencairan',
            'Murabahah'
        );

        $kodePencairan = $this->kodeJurnal($pencairan);
        $kodeReversal = $this->kodeJurnal($reversal);

        $this->assertContains($piutangBaru->kode_akun, $kodePencairan);
        $this->assertContains($piutangBaru->kode_akun, $kodeReversal, 'Reversal wajib memakai akun piutang yang sama dengan pencairan.');
        $this->assertContains($marginBaru->kode_akun, $kodeReversal, 'Reversal wajib memakai akun margin yang sama dengan pencairan.');
        $this->assertNotContains(CoaRegistri::PIUTANG_MURABAHAH, $kodeReversal, 'Reversal tidak boleh memakai akun piutang lama.');
    }
}
