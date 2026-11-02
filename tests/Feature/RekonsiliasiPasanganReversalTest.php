<?php

namespace Tests\Feature;

use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\AutoJournalingService;
use App\Services\JurnalReconciliationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * REKONSILIASI — KELENGKAPAN PASANGAN REVERSAL.
 *
 * Dua cacat buku besar yang tidak tertangkap pemeriksaan keseimbangan biasa:
 *
 *  A. REVERSAL TANPA JURNAL ASAL (reversal yatim).
 *     Jurnal `is_reversal = true` diposting untuk transaksi yang tidak pernah
 *     punya jurnal asli. Jurnal tersebut menjadi mutasi yatim: akun yang
 *     disentuhnya bergeser permanen tanpa pasangan.
 *
 *  B. TRANSAKSI BATAL BELUM DIBALIK.
 *     Transaksi sudah `Voided`/`Dibatalkan` tetapi jumlah (debet − kredit)
 *     jurnal-jurnalnya per akun belum nol — artinya sebagian/seluruh jurnal
 *     asli masih hidup di buku besar.
 *
 * Keduanya HANYA dilaporkan pada rekonsiliasi menyeluruh
 * (`rekonsiliasiBukuBesar()`), TIDAK pada pemeriksaan inline
 * (`periksaJurnal()`/`pastikanJurnalSehat()`) — karena saat jurnal pembalik
 * diposting, pemanggil belum tentu sudah menandai transaksi sebagai batal,
 * sehingga menegakkannya secara inline akan memblokir void yang sah.
 */
class RekonsiliasiPasanganReversalTest extends TestCase
{
    use DatabaseTransactions;

    private AutoJournalingService $jurnal;

    private JurnalReconciliationService $rekonsiliasi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jurnal = app(AutoJournalingService::class);
        $this->rekonsiliasi = app(JurnalReconciliationService::class);

        $this->pastikanCoa();
    }

    /* =====================================================================
     | FIXTURE
     ===================================================================== */

    private function pastikanCoa(): void
    {
        $daftar = [
            AutoJournalingService::KAS_TELLER => ['Kas Teller', 'Aset', 'Aset Lancar', 'Debet'],
            AutoJournalingService::SIMPANAN_SUKARELA => ['Simpanan Sukarela', 'Kewajiban', 'Kewajiban Jangka Pendek', 'Kredit'],
        ];

        foreach ($daftar as $kode => [$nama, $kategori, $klasifikasi, $tipe]) {
            Coa::firstOrCreate(
                ['kode_akun' => $kode],
                [
                    'nama_akun' => $nama,
                    'kategori' => $kategori,
                    'klasifikasi' => $klasifikasi,
                    'tipe_saldo' => $tipe,
                    'is_active' => true,
                ]
            );
        }
    }

    private function userTeller(): User
    {
        return User::firstOrCreate(
            ['email' => 'uji.pasangan.reversal@test.local'],
            [
                'name' => 'Uji Pasangan Reversal',
                'password' => Hash::make('password'),
                'role' => 'teller',
                'is_active' => true,
            ]
        );
    }

    private function transaksi(): Transaksi
    {
        return Transaksi::create([
            'no_transaksi' => 'TRX-RVR-'.strtoupper(bin2hex(random_bytes(4))),
            'waktu_transaksi' => now(),
            'jenis_transaksi' => 'Uji Pasangan Reversal',
            'posisi' => 'Debet',
            'jumlah' => 100_000,
            'sumber_transaksi' => 'Teller',
            'status_transaksi' => Transaksi::STATUS_TRANSAKSI_SUKSES,
            'status' => Transaksi::STATUS_TEKNIS_SUCCESS,
            'user_id' => $this->userTeller()->id,
        ]);
    }

    /**
     * Ubah status transaksi SETELAH jurnal asal diposting.
     *
     * Gate posting hanya menerima jurnal NON-reversal untuk transaksi
     * `Sukses`, jadi urutan alaminya memang: posting asal dulu, baru void.
     */
    private function voidkan(Transaksi $transaksi): Transaksi
    {
        $transaksi->update(['status_transaksi' => Transaksi::STATUS_TRANSAKSI_VOIDED]);

        return $transaksi->fresh();
    }

    /** Posting jurnal asal: DEBET Kas Teller | KREDIT Simpanan Sukarela. */
    private function jurnalAsal(Transaksi $transaksi, float $nominal = 100_000): Jurnal
    {
        return $this->jurnal->post('Setoran uji pasangan', [
            ['kode' => AutoJournalingService::KAS_TELLER,        'debet' => $nominal],
            ['kode' => AutoJournalingService::SIMPANAN_SUKARELA, 'kredit' => $nominal],
        ], 'Setoran', $transaksi->id, null, $this->userTeller()->id);
    }

    /** Posting jurnal pembalik (contra): DEBET Simpanan Sukarela | KREDIT Kas Teller. */
    private function jurnalPembalik(Transaksi $transaksi, float $nominal = 100_000): Jurnal
    {
        return $this->jurnal->post('REVERSAL - setoran uji pasangan', [
            ['kode' => AutoJournalingService::SIMPANAN_SUKARELA, 'debet' => $nominal],
            ['kode' => AutoJournalingService::KAS_TELLER,        'kredit' => $nominal],
        ], 'Reversal Uji', $transaksi->id, null, $this->userTeller()->id,
            null, null, null, null, null, null, null, false, true);
    }

    /** @return array<int, array<string, mixed>> */
    private function temuan(int $transaksiId, string $aturan): array
    {
        $hasil = $this->rekonsiliasi->rekonsiliasiBukuBesar();

        return array_values(array_filter(
            $hasil['temuan'],
            static fn (array $t): bool => ($t['aturan'] ?? null) === $aturan
                && (int) ($t['konteks']['transaksi_id'] ?? 0) === $transaksiId
        ));
    }

    /* =====================================================================
     | A. REVERSAL TANPA JURNAL ASAL
     ===================================================================== */

    public function test_reversal_tanpa_jurnal_asal_dilaporkan(): void
    {
        $transaksi = $this->transaksi();

        // Tidak pernah ada jurnal asal — langsung jurnal pembalik.
        $this->jurnalPembalik($transaksi);

        $ditemukan = $this->temuan($transaksi->id, 'REVERSAL_TANPA_JURNAL_ASAL');

        $this->assertCount(
            1,
            $ditemukan,
            'Jurnal pembalik tanpa jurnal asal WAJIB dilaporkan sebagai REVERSAL_TANPA_JURNAL_ASAL.'
        );
        $this->assertNotEmpty(
            (string) ($ditemukan[0]['konteks']['no_jurnal'] ?? ''),
            'Temuan harus menyebutkan nomor jurnal pembalik yang yatim.'
        );
    }

    public function test_pasangan_reversal_lengkap_tidak_dilaporkan(): void
    {
        $transaksi = $this->transaksi();

        $this->jurnalAsal($transaksi);
        $this->voidkan($transaksi);
        $this->jurnalPembalik($transaksi);

        $this->assertSame(
            [],
            $this->temuan($transaksi->id, 'REVERSAL_TANPA_JURNAL_ASAL'),
            'Reversal yang memiliki jurnal asal tidak boleh dilaporkan sebagai reversal yatim.'
        );

        $this->assertSame(
            [],
            $this->temuan($transaksi->id, 'TRANSAKSI_VOID_BELUM_DIBALIK'),
            'Transaksi void yang sudah diputar-balik penuh tidak boleh dilaporkan.'
        );
    }

    /* =====================================================================
     | B. TRANSAKSI BATAL BELUM DIBALIK
     ===================================================================== */

    public function test_transaksi_void_tanpa_jurnal_pembalik_dilaporkan(): void
    {
        $transaksi = $this->transaksi();

        // Hanya jurnal asal: saldo rekening sudah dikembalikan (status Voided)
        // tetapi buku besar belum ikut diputar-balik.
        $this->jurnalAsal($transaksi);
        $this->voidkan($transaksi);

        $ditemukan = $this->temuan($transaksi->id, 'TRANSAKSI_VOID_BELUM_DIBALIK');

        $this->assertCount(
            2,
            $ditemukan,
            'Kedua akun yang tersentuh jurnal asal WAJIB dilaporkan belum nol.'
        );

        $akun = array_map(static fn (array $t): string => (string) $t['konteks']['kode_akun'], $ditemukan);
        sort($akun);

        $diharapkan = [AutoJournalingService::KAS_TELLER, AutoJournalingService::SIMPANAN_SUKARELA];
        sort($diharapkan);

        $this->assertSame($diharapkan, $akun);
    }

    public function test_pembalikan_sebagian_dilaporkan_pada_akun_yang_tersisa(): void
    {
        $transaksi = $this->transaksi();

        $this->jurnalAsal($transaksi, 100_000);
        $this->voidkan($transaksi);
        // Pembalikan hanya separuh: buku besar masih bergeser Rp 50.000.
        $this->jurnalPembalik($transaksi, 50_000);

        $ditemukan = $this->temuan($transaksi->id, 'TRANSAKSI_VOID_BELUM_DIBALIK');

        $this->assertCount(2, $ditemukan);

        foreach ($ditemukan as $t) {
            $this->assertEqualsWithDelta(
                50_000,
                abs((float) $t['konteks']['selisih']),
                0.01,
                'Selisih yang dilaporkan harus sebesar efek buku yang belum diputar-balik.'
            );
        }
    }

    /* =====================================================================
     | C. MODE INLINE TIDAK IKUT MENEGAKKAN PASANGAN REVERSAL
     ===================================================================== */

    /**
     * Saat jurnal pembalik diposting, transaksi asal belum tentu sudah
     * berstatus Voided. Pemeriksaan inline TIDAK boleh menolak keadaan ini,
     * kalau tidak seluruh alur void yang sah akan gagal.
     */
    public function test_pemeriksaan_inline_tidak_menolak_reversal_saat_transaksi_belum_ditandai_void(): void
    {
        $transaksi = $this->transaksi();

        $asal = $this->jurnalAsal($transaksi);

        // Pemohon void baru mengajukan pembatalan; pelaksana belum menyetujui.
        $transaksi->update(['status_transaksi' => Transaksi::STATUS_TRANSAKSI_PENDING_VOID]);
        $transaksi->refresh();

        $balik = $this->jurnalPembalik($transaksi);

        // Jurnal asal (non-reversal) pada transaksi Pending Void memang
        // dilaporkan TRANSAKSI_BELUM_SUKSES — itu perilaku yang benar dan
        // sudah dikunci PenguatanPencatatanKeuanganTest.
        $temuanAsal = collect($this->rekonsiliasi->periksaJurnal($asal))
            ->pluck('aturan')
            ->all();

        $this->assertSame(
            ['TRANSAKSI_BELUM_SUKSES'],
            $temuanAsal,
            'Jurnal asal non-reversal pada transaksi Pending Void cukup dilaporkan TRANSAKSI_BELUM_SUKSES.'
        );

        // Inti penguncian: pemeriksaan inline TIDAK menegakkan pasangan
        // reversal. Jurnal pembalik tetap lolos walau transaksi belum Voided.
        $this->assertSame(
            [],
            $this->rekonsiliasi->periksaJurnal($balik),
            'Jurnal pembalik tidak boleh ditolak inline.'
        );

        $this->assertNotContains(
            'REVERSAL_TANPA_JURNAL_ASAL',
            $temuanAsal,
            'Pemeriksaan pasangan reversal tidak boleh masuk jalur inline.'
        );
        $this->assertNotContains(
            'TRANSAKSI_VOID_BELUM_DIBALIK',
            $temuanAsal,
            'Pemeriksaan pasangan reversal tidak boleh masuk jalur inline.'
        );
    }
}
