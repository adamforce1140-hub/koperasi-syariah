<?php

namespace Tests\Feature;

use App\Events\AngsuranLunas;
use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\Coa;
use App\Models\KebijakanKoperasi;
use App\Models\Pembiayaan;
use App\Models\RestrukturisasiRequest;
use App\Models\User;
use App\Services\AngsuranTransactionService;
use App\Services\RestrukturisasiService;
use App\Support\Coa as KodeCoa;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * BATCH 12 — Modul RESTRUKTURISASI (3R) utuh.
 *
 * `RestrukturisasiService` sebelumnya tidak memiliki berkas test sama sekali,
 * padahal modul ini mengubah kewajiban anggota dan berinteraksi langsung
 * dengan `AngsuranTransactionService::alokasiPembayaran()`.
 *
 * Fokus batch:
 *   R1  Ringkasan piutang memakai urutan alokasi yang SAMA dengan pembayaran
 *       angsuran (Pokok → Margin). Bila berbeda, sisa piutang hasil
 *       restrukturisasi bisa lebih kecil/kecil dari kewajiban sebenarnya.
 *   R2  Safeguard Fatwa DSN-MUI No. 49: tenor baru (Rescheduling) tidak boleh
 *       menambah total piutang (sisa pokok + sisa margin). Margin baru TIDAK
 *       boleh dihitung dari nisbah baru.
 *   R3  Pemisahan tugas (PemisahanTugas): AO pengaju tidak boleh menyetujui
 *       maupun menolak pengajuannya sendiri.
 *   R4  Status guard: pengajuan yang sudah diputus tidak dapat diputus lagi.
 *   R5  Penolakan tidak mengubah jadwal angsuran lama.
 *   R6  Selisih pembulatan dibebankan pada angsuran terakhir sehingga total
 *       pokok & margin jadwal baru TEPAT sama dengan sisa piutang.
 */
class IntegritasRestrukturisasiTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'RS'.strtoupper(substr(uniqid(), -6));

        foreach ([KodeCoa::KAS_TELLER, KodeCoa::PINJAMAN_QARDH] as $kode) {
            if (Coa::findByKode($kode) === null) {
                $this->markTestSkipped("CoA {$kode} belum tersedia pada basis data uji.");
            }
        }
    }

    /* =====================================================================
     | FIXTURE
     ===================================================================== */

    private function anggota(): Anggota
    {
        return Anggota::create([
            'no_anggota' => 'AGT-'.$this->prefix,
            'nik' => '3272'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Uji Restrukturisasi '.$this->prefix,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Pengujian Restrukturisasi No. 12',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_ACTIVE,
            'tanggal_gabung' => '2020-01-01',
            'is_auto_debit' => false,
        ]);
    }

    /**
     * Pembiayaan Murabahah 10 tenor × Rp 120.000 (pokok 100.000 + margin 20.000).
     *
     * @return array{0: Pembiayaan, 1: Angsuran}
     */
    private function pembiayaan(Anggota $anggota, int $hariTerlambat = 45): array
    {
        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => 'PMB-'.$this->prefix.'-'.strtoupper(substr(uniqid(), -4)),
            'anggota_id' => $anggota->id,
            'akun_id' => (int) (Coa::query()->value('id') ?? 1),
            'akad_pembiayaan' => 'Murabahah',
            'jumlah_pembiayaan' => 1000000.0,
            'total_pembiayaan' => 1200000.0,
            'total_ujroh' => 200000.0,
            'sisa_pembiayaan' => 1200000.0,
            'tenor' => 10,
            'jumlah_angsuran' => 120000.0,
            'margin_deferred_amount' => 200000.0,
            'tanggal_akad' => now()->subMonths(6)->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonth()->toDateString(),
            'status_pembiayaan' => Pembiayaan::STATUS_AKTIF,
        ]);

        $angsuran = Angsuran::create([
            'pembiayaan_id' => $pembiayaan->id,
            'angsuran_ke' => 1,
            'tanggal_jatuh_tempo' => now()->subDays($hariTerlambat)->toDateString(),
            'pokok_angsuran' => 100000.0,
            'margin_ujroh' => 20000.0,
            'jumlah_angsuran' => 120000.0,
            'sisa_pokok' => 0.0,
            'status_angsuran' => 'Terlambat',
        ]);

        // 9 angsuran berikutnya belum jatuh tempo.
        for ($ke = 2; $ke <= 10; $ke++) {
            Angsuran::create([
                'pembiayaan_id' => $pembiayaan->id,
                'angsuran_ke' => $ke,
                'tanggal_jatuh_tempo' => now()->addMonths($ke)->toDateString(),
                'pokok_angsuran' => 100000.0,
                'margin_ujroh' => 20000.0,
                'jumlah_angsuran' => 120000.0,
                'sisa_pokok' => 0.0,
                'status_angsuran' => 'Belum Jatuh Tempo',
            ]);
        }

        return [$pembiayaan, $angsuran];
    }

    /**
     * Dua aktor BERBEDA: AO pengaju dan Manajer pemutus.
     *
     * Pemisahan tugas menolak AO yang memutus pengajuannya sendiri, sehingga
     * fixture wajib memakai dua user yang tidak sama.
     *
     * @return array{0: int, 1: int}
     */
    private function duaAktor(): array
    {
        $aoId = (int) (DB::table('users')->value('id') ?? 0);

        if ($aoId === 0) {
            $aoId = (int) User::factory()->create()->id;
        }

        $manajerId = (int) DB::table('users')->where('id', '!=', $aoId)->value('id');

        if ($manajerId === 0) {
            $manajerId = (int) User::factory()->create()->id;
        }

        return [$aoId, $manajerId];
    }

    private function aturKebijakan(array $nilai): void
    {
        KebijakanKoperasi::query()->update($nilai);
        Setting::flush();
    }

    /**
     * Ajukan restrukturisasi langsung ke tabel (AO belum punya jalur service
     * `ajukan()`), memakai snapshot yang dihitung service.
     */
    private function ajukan(Pembiayaan $pembiayaan, int $tenorBaru, int $aoId, string $skema = 'Rescheduling'): RestrukturisasiRequest
    {
        $ringkasan = RestrukturisasiService::ringkasan($pembiayaan);

        return RestrukturisasiRequest::create([
            'pembiayaan_id' => $pembiayaan->id,
            'ao_id' => $aoId,
            'skema_restrukturisasi' => $skema,
            'tenor_baru' => $tenorBaru,
            'grace_period' => 0,
            'tanggal_jatuh_tempo_baru' => now()->addMonth()->toDateString(),
            'alasan_restrukturisasi' => 'Uji integritas batch 12 — kemampuan bayar menurun.',
            'kemampuan_bayar_baru' => round($ringkasan['sisaPiutang'] / max(1, $tenorBaru), 2),
            'sisa_pokok_snapshot' => $ringkasan['sisaPokok'],
            'sisa_margin_snapshot' => $ringkasan['sisaMargin'],
            'sisa_piutang_snapshot' => $ringkasan['sisaPiutang'],
            'angsuran_lama_snapshot' => $ringkasan['angsuranLama'],
            'status' => RestrukturisasiRequest::STATUS_PENDING_APPROVAL,
        ]);
    }

    /* =====================================================================
     | R1 — Keselarasan urutan alokasi dengan pembayaran angsuran
     ===================================================================== */

    public function test_sisa_margin_mengikuti_urutan_alokasi_pokok_didahulukan(): void
    {
        [$pembiayaan, $angsuran] = $this->pembiayaan($this->anggota());

        // Bayar hanya Rp 100.000 atas tagihan pokok 100.000 + margin 20.000.
        // Menurut alokasi yang berlaku (Pokok → Margin), seluruh uang habis
        // untuk pokok sehingga TIDAK ada margin yang diakui.
        app(AngsuranTransactionService::class)->proses($angsuran, [
            'jumlah_bayar' => 100000.0,
            'metode_bayar' => 'TUNAI',
        ]);

        $angsuran->refresh();
        $this->assertSame('Dibayar Sebagian', $angsuran->status_angsuran,
            'Pembayaran Rp 100.000 atas tagihan Rp 120.000 harus berstatus Dibayar Sebagian.');
        $this->assertSame(0.0, round((float) $angsuran->sisa_pokok, 2),
            'Seluruh uang Rp 100.000 tertagih sebagai pokok; tidak ada sisa pokok pada baris ini.');

        $ringkasan = RestrukturisasiService::ringkasan($pembiayaan);

        $this->assertSame(
            200000.0,
            round($ringkasan['sisaMargin'], 2),
            'R1: margin baris ini BELUM tertagih (uang habis untuk pokok), sehingga sisa '
            .'margin pembiayaan tetap Rp 200.000 (10 baris × 20.000; tidak satu pun margin '
            .'diakui karena uang baru menutup pokok satu baris). '
            .'Sebelum perbaikan, service menghitung margin TERAKUI = total_bayar - denda '
            .'= 100.000 padahal uang itu dipakai untuk pokok, sehingga sisa margin turun '
            .'palsu menjadi Rp 100.000 (seharusnya Rp 200.000).'
        );
    }

    public function test_margin_tertagih_penuh_membuat_sisa_margin_nol(): void
    {
        [$pembiayaan, $angsuran] = $this->pembiayaan($this->anggota());

        // Bayar penuh Rp 120.000 → pokok 100.000 + margin 20.000 terbayar.
        app(AngsuranTransactionService::class)->proses($angsuran, [
            'jumlah_bayar' => 120000.0,
            'metode_bayar' => 'TUNAI',
        ]);

        $angsuran->refresh();
        $this->assertSame('Sudah Dibayar', $angsuran->status_angsuran,
            'Kontrol positif: pembayaran penuh wajib melunasi angsuran.');

        $ringkasan = RestrukturisasiService::ringkasan($pembiayaan);

        // Tersisa 9 baris × 20.000 margin.
        $this->assertSame(180000.0, round($ringkasan['sisaMargin'], 2),
            'Kontrol positif R1: setelah satu baris lunas, sisa margin tinggal 9 × 20.000.');
    }

    /* =====================================================================
     | F-08 — Peristiwa AngsuranLunas hanya untuk pelunasan SESUNGGUHNYA
     ===================================================================== */

    public function test_angsuran_lunas_tidak_dikirim_saat_margin_belum_tertagih(): void
    {
        Event::fake([AngsuranLunas::class]);

        [, $angsuran] = $this->pembiayaan($this->anggota());

        // Pokok lunas (100.000) tetapi margin 20.000 belum tertagih.
        app(AngsuranTransactionService::class)->proses($angsuran, [
            'jumlah_bayar' => 100000.0,
            'metode_bayar' => 'TUNAI',
        ]);

        $this->assertSame('Dibayar Sebagian', $angsuran->fresh()->status_angsuran,
            'F-07: angsuran baru dinilai "Dibayar Sebagian" bila MASIH ada kewajiban '
            .'pada baris itu — margin Rp 20.000 belum terbayar.');

        Event::assertNotDispatched(AngsuranLunas::class,
            'F-08: peristiwa AngsuranLunas menandakan LUNAS total. Mengirimnya saat '
            .'margin belum tertagih membuat notifikasi & audit melaporkan pelunasan palsu.');
    }

    public function test_total_piutang_ringkasan_sama_dengan_sisa_komitmen_akad(): void
    {
        [$pembiayaan, $angsuran] = $this->pembiayaan($this->anggota());

        app(AngsuranTransactionService::class)->proses($angsuran, [
            'jumlah_bayar' => 100000.0,
            'metode_bayar' => 'TUNAI',
        ]);

        $ringkasan = RestrukturisasiService::ringkasan($pembiayaan->fresh());

        // Kewajiban awal 1.200.000; dibayar 100.000 (semuanya pokok).
        $this->assertSame(
            1100000.0,
            round($ringkasan['sisaPiutang'], 2),
            'R1: sisa piutang = sisa kewajiban akad setelah pembayaran (1.200.000 - 100.000). '
            .'Bila sisa pokok & sisa margin dihitung dengan urutan berbeda, angka ini bergeser '
            .'dan jadwal restrukturisasi menjadi salah dasar.'
        );
    }

    /* =====================================================================
     | R2 — Safeguard Fatwa DSN-MUI No. 49 (total piutang tidak bertambah)
     ===================================================================== */

    public function test_rescheduling_tidak_menambah_total_piutang(): void
    {
        [$pembiayaan] = $this->pembiayaan($this->anggota());
        [$aoId, $manajerId] = $this->duaAktor();

        $sebelum = RestrukturisasiService::ringkasan($pembiayaan);
        $req = $this->ajukan($pembiayaan, 24, $aoId);

        RestrukturisasiService::approve($req->id, $manajerId, 'Disetujui — tenor diperpanjang.');

        $totalBaru = (float) Angsuran::where('pembiayaan_id', $pembiayaan->id)
            ->whereIn('status_angsuran', ['Belum Jatuh Tempo', 'Jatuh Tempo', 'Terlambat'])
            ->sum(DB::raw('pokok_angsuran + margin_ujroh'));

        $this->assertSame(
            round((float) $sebelum['sisaPiutang'], 2),
            round($totalBaru, 2),
            'R2: total jadwal baru wajib TEPAT sama dengan sisa piutang (sisa pokok + sisa margin). '
            .'Menambah nominal karena tenor lebih panjang adalah riba (Fatwa DSN-MUI No. 49).'
        );
    }

    /* =====================================================================
     | R3 — Pemisahan tugas
     ===================================================================== */

    public function test_ao_pengaju_tidak_boleh_menyetujui_sendiri(): void
    {
        [$pembiayaan] = $this->pembiayaan($this->anggota());
        $aoId = $this->duaAktor()[0];
        $req = $this->ajukan($pembiayaan, 18, $aoId);

        $pesan = null;

        try {
            RestrukturisasiService::approve($req->id, $aoId, 'Setuju sendiri.');
        } catch (\Throwable $e) {
            $pesan = $e->getMessage();
        }

        $this->assertNotNull($pesan, 'R3: AO pengaju tidak boleh menyetujui pengajuannya sendiri.');
        $this->assertSame(
            RestrukturisasiRequest::STATUS_PENDING_APPROVAL,
            $req->fresh()->status,
            'R3: penolakan pemisahan tugas tidak boleh mengubah status pengajuan.'
        );
        $this->assertSame(
            10,
            Angsuran::where('pembiayaan_id', $pembiayaan->id)->count(),
            'R3: jadwal angsuran tidak boleh berubah bila approval ditolak.'
        );
    }

    /* =====================================================================
     | R4 — Status guard
     ===================================================================== */

    public function test_pengajuan_yang_sudah_diputus_tidak_dapat_diputus_lagi(): void
    {
        [$pembiayaan] = $this->pembiayaan($this->anggota());
        [$aoId, $manajerId] = $this->duaAktor();

        $req = $this->ajukan($pembiayaan, 15, $aoId);
        RestrukturisasiService::approve($req->id, $manajerId, 'Disetujui.');
        $jumlahSetelahApprove = Angsuran::where('pembiayaan_id', $pembiayaan->id)->count();

        $pesan = null;

        try {
            RestrukturisasiService::approve($req->id, $manajerId, 'Setuju lagi.');
        } catch (\Throwable $e) {
            $pesan = $e->getMessage();
        }

        $this->assertNotNull($pesan, 'R4: pengajuan yang sudah APPROVED tidak boleh disetujui dua kali.');
        $this->assertSame(
            $jumlahSetelahApprove,
            Angsuran::where('pembiayaan_id', $pembiayaan->id)->count(),
            'R4: approval kedua tidak boleh menambah jadwal angsuran lagi.'
        );
    }

    /* =====================================================================
     | R5 — Penolakan tidak mengubah jadwal
     ===================================================================== */

    public function test_penolakan_tidak_mengubah_jadwal_angsuran_lama(): void
    {
        [$pembiayaan] = $this->pembiayaan($this->anggota());
        [$aoId, $manajerId] = $this->duaAktor();

        $sebelum = Angsuran::where('pembiayaan_id', $pembiayaan->id)
            ->orderBy('angsuran_ke')
            ->get(['angsuran_ke', 'pokok_angsuran', 'margin_ujroh', 'status_angsuran'])
            ->toArray();

        $req = $this->ajukan($pembiayaan, 24, $aoId);
        $hasil = RestrukturisasiService::reject($req->id, $manajerId, 'Kemampuan bayar dinilai masih memadai.');

        $this->assertSame(RestrukturisasiRequest::STATUS_REJECTED, $hasil->status,
            'R5: penolakan wajib mengubah status pengajuan menjadi REJECTED.');

        $sesudah = Angsuran::where('pembiayaan_id', $pembiayaan->id)
            ->orderBy('angsuran_ke')
            ->get(['angsuran_ke', 'pokok_angsuran', 'margin_ujroh', 'status_angsuran'])
            ->toArray();

        $this->assertSame($sebelum, $sesudah,
            'R5: penolakan restrukturisasi TIDAK BOLEH menyentuh jadwal angsuran lama.');
    }

    /* =====================================================================
     | R6 — Selisih pembulatan dibebankan ke angsuran terakhir
     ===================================================================== */

    public function test_selisih_pembulatan_dibebankan_pada_angsuran_terakhir(): void
    {
        [$pembiayaan] = $this->pembiayaan($this->anggota());
        [$aoId, $manajerId] = $this->duaAktor();

        $sebelum = RestrukturisasiService::ringkasan($pembiayaan);
        $req = $this->ajukan($pembiayaan, 7, $aoId);

        RestrukturisasiService::approve($req->id, $manajerId, 'Disetujui — tenor 7 bulan.');

        $jadwalBaru = Angsuran::where('pembiayaan_id', $pembiayaan->id)
            ->where('status_angsuran', 'Belum Jatuh Tempo')
            ->orderBy('angsuran_ke')
            ->get();

        $this->assertSame(7, $jadwalBaru->count(),
            'R6: tenor baru 7 bulan harus menghasilkan tepat 7 baris jadwal.');

        $totalPokok = round((float) $jadwalBaru->sum('pokok_angsuran'), 2);
        $totalMargin = round((float) $jadwalBaru->sum('margin_ujroh'), 2);

        $this->assertSame(round((float) $sebelum['sisaPokok'], 2), $totalPokok,
            'R6: total pokok jadwal baru wajib tepat sama dengan sisa pokok.');
        $this->assertSame(round((float) $sebelum['sisaMargin'], 2), $totalMargin,
            'R6: total margin jadwal baru wajib tepat sama dengan sisa margin.');

        // Semua baris kecuali terakhir bernominal sama (pembulatan ditahan).
        $pola = $jadwalBaru->take(6)->map(fn ($a) => (float) $a->jumlah_angsuran)->unique();
        $this->assertCount(1, $pola,
            'R6: enam angsuran pertama wajib seragam; hanya angsuran terakhir yang menyerap selisih.');
    }
}
