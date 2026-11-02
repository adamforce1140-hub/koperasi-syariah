<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\Coa as CoaModel;
use App\Models\JurnalDetail;
use App\Models\Pembiayaan;
use App\Models\User;
use App\Services\CkpnService;
use App\Support\Coa as CoaKode;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * CKPN-5 — HAPUS BUKU (write-off) piutang pembiayaan macet.
 *
 * Mengunci kepatuhan PSAK 71 (penghentian pengakuan aset keuangan) dan
 * POJK 12/2023:
 *
 *  1. Hapus buku hanya boleh atas piutang yang SUDAH TERCAIR dan berkualitas
 *     MACET (kol5 / status macet) — bukan atas piutang lancar atau kontrak
 *     yang belum disalurkan.
 *  2. Jurnal memakai CADANGAN lebih dahulu: DEBET 1-2100 (maksimal saldo
 *     penyisihan) + DEBET 5-4001 (kekurangan), KREDIT piutang per akad sebesar
 *     nilai bruto, dan DEBET 1-2101 untuk membatalkan margin ditangguhkan.
 *  3. Setelah hapus buku: sisa = 0, status = hapus_buku, keluar dari basis
 *     CKPN dan dari daftar autodebet.
 *  4. Idempotensi: pemanggilan kedua tidak menggandakan jurnal.
 */
class CkpnHapusBukuTest extends TestCase
{
    use DatabaseTransactions;

    private CkpnService $ckpn;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ckpn = app(CkpnService::class);
        $this->prefix = 'HAPUSBUKU'.strtoupper(substr(uniqid(), -6));

        foreach ([
            CoaKode::CKPN_PIUTANG_PEMBIAYAAN,
            CoaKode::BEBAN_CKPN_PEMBIAYAAN,
            CoaKode::MARGIN_DITANGGUHKAN,
            CoaKode::PIUTANG_MURABAHAH,
        ] as $kode) {
            if (! CoaModel::findByKode($kode)) {
                $this->markTestSkipped("CoA {$kode} belum ter-seed.");
            }
        }
    }

    /* ─────────────────────────── HELPER ─────────────────────────── */

    private function pengurus(): User
    {
        return User::firstOrCreate(
            ['email' => 'pengurus.hapusbuku@test.local'],
            [
                'name' => 'Pengurus Uji Hapus Buku',
                'password' => Hash::make('password'),
                'role' => 'pengurus',
            ]
        );
    }

    private function anggota(): Anggota
    {
        return Anggota::firstOrCreate(
            ['no_anggota' => $this->prefix.'AG'],
            [
                'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
                'nama_lengkap' => 'Anggota Uji Hapus Buku',
                'jenis_kelamin' => 'Laki-laki',
                'alamat' => 'Alamat Uji Hapus Buku',
                'kota' => 'Jakarta',
                'provinsi' => 'DKI Jakarta',
                'status_anggota' => 'Aktif',
                'tanggal_gabung' => now()->toDateString(),
            ]
        );
    }

    /**
     * Pembiayaan Murabahah tercair dengan sisa POKOK & MARGIN tertentu dan
     * satu angsuran menunggak (agar kolektibilitas mudah diarahkan).
     */
    private function pembiayaanDenganTunggakan(
        float $pokokSisa,
        float $marginSisa,
        int $hariTerlambat,
        string $status = Pembiayaan::STATUS_AKTIF
    ): Pembiayaan {
        $anggota = $this->anggota();

        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => $this->prefix.'-'.random_int(1000, 9999),
            'anggota_id' => $anggota->id,
            'akun_id' => CoaModel::query()->value('id') ?? 1,
            'akad_pembiayaan' => Pembiayaan::AKAD_MURABAHAH,
            'jumlah_pembiayaan' => $pokokSisa,
            'total_pembiayaan' => $pokokSisa + $marginSisa,
            'tenor' => 12,
            'jumlah_angsuran' => round(($pokokSisa + $marginSisa) / 12, 2),
            'tanggal_akad' => now()->subMonths(6)->toDateString(),
            'tanggal_cair' => now()->subMonths(6)->toDateString(),
            'tanggal_jatuh_tempo' => now()->subDays($hariTerlambat)->toDateString(),
            'status_pembiayaan' => $status,
            'sisa_pembiayaan' => $pokokSisa + $marginSisa,
            'margin_deferred_amount' => $marginSisa,
            'is_disbursed' => true,
        ]);

        Angsuran::create([
            'pembiayaan_id' => $pembiayaan->id,
            'angsuran_ke' => 1,
            'tanggal_jatuh_tempo' => now()->subDays($hariTerlambat)->toDateString(),
            'pokok_angsuran' => $pokokSisa,
            'margin_ujroh' => $marginSisa,
            'jumlah_angsuran' => $pokokSisa + $marginSisa,
            'sisa_pokok' => $pokokSisa,
            'status_angsuran' => 'Jatuh Tempo',
        ]);

        return $pembiayaan->fresh();
    }

    /* ─────────────────────────── TEST ─────────────────────────── */

    public function test_piutang_macet_dihapus_buku_dengan_menggunakan_cadangan(): void
    {
        $pembiayaan = $this->pembiayaanDenganTunggakan(1_000_000, 200_000, 400);
        $pengurus = $this->pengurus();

        // Bentuk cadangan terlebih dahulu (100% kol5 atas pokok).
        $this->ckpn->catatPenyesuaian(now(), $pengurus->id);
        $cadanganAwal = $this->ckpn->saldoTercatat(now());
        $this->assertGreaterThan(0, $cadanganAwal, 'Prasyarat: cadangan CKPN harus terbentuk.');

        $jurnal = $this->ckpn->hapusBuku($pembiayaan, now(), $pengurus->id);
        $this->assertNotNull($jurnal, 'Hapus buku harus menghasilkan jurnal.');

        /* Jurnal: KREDIT piutang = bruto (pokok+margin); DEBET 1-2101 = margin;
         | DEBET 1-2100 = min(pokok, cadangan); sisa pokok → DEBET 5-4001. */
        $debet = fn (string $kode) => (float) JurnalDetail::where('jurnal_id', $jurnal->id)
            ->where('coa_id', CoaModel::findByKode($kode)->id)
            ->sum('debet');
        $kredit = fn (string $kode) => (float) JurnalDetail::where('jurnal_id', $jurnal->id)
            ->where('coa_id', CoaModel::findByKode($kode)->id)
            ->sum('kredit');

        $this->assertEqualsWithDelta(1_000_000, $debet(CoaKode::CKPN_PIUTANG_PEMBIAYAAN), 0.01,
            'DEBET 1-2100 harus sebesar pokok (cadangan mencukupi).');
        $this->assertEqualsWithDelta(0, $debet(CoaKode::BEBAN_CKPN_PEMBIAYAAN), 0.01,
            'Cadangan mencukupi → tidak ada beban tambahan.');
        $this->assertEqualsWithDelta(200_000, $debet(CoaKode::MARGIN_DITANGGUHKAN), 0.01,
            'DEBET 1-2101 harus sebesar margin ditangguhkan.');
        $this->assertEqualsWithDelta(1_200_000, $kredit(CoaKode::PIUTANG_MURABAHAH), 0.01,
            'KREDIT piutang harus sebesar nilai bruto.');
        $jurnal->pastikanBalance();

        // Pembiayaan keluar dari neraca & ditandai hapus buku.
        $pembiayaan->refresh();
        $this->assertSame(0.0, (float) $pembiayaan->sisa_pembiayaan);
        $this->assertSame(Pembiayaan::STATUS_HAPUS_BUKU, $pembiayaan->status_pembiayaan);
        $this->assertNotNull($pembiayaan->tanggal_hapus_buku);
        $this->assertTrue($pembiayaan->isHapusBuku());
    }

    public function test_kekurangan_cadangan_dibebankan_ke_5_4001(): void
    {
        $pembiayaan = $this->pembiayaanDenganTunggakan(1_000_000, 0, 400);

        /* Isolasi deterministik: cabang yang diuji adalah "kekurangan
         | cadangan", jadi cadangan CKPN yang sudah terbentuk di basis data
         | uji bersama harus dikosongkan lebih dahulu. Seluruh jurnal yang
         | menyentuh akun kontra 1-2100 dibuang DUA SISI agar buku tetap
         | seimbang. */
        $ckpnKontra = CoaModel::findByKode(CoaKode::CKPN_PIUTANG_PEMBIAYAAN);
        if ($ckpnKontra) {
            $jurnalCkpn = JurnalDetail::where('coa_id', $ckpnKontra->id)->pluck('jurnal_id');
            JurnalDetail::whereIn('jurnal_id', $jurnalCkpn)->delete();
            $ckpnKontra->update(['saldo_awal' => 0]);
        }

        // Tanpa pembentukan cadangan → seluruh pokok menjadi beban periode.
        $jurnal = $this->ckpn->hapusBuku($pembiayaan, now(), $this->pengurus()->id);
        $this->assertNotNull($jurnal);

        $debet = fn (string $kode) => (float) JurnalDetail::where('jurnal_id', $jurnal->id)
            ->where('coa_id', CoaModel::findByKode($kode)->id)
            ->sum('debet');

        $this->assertEqualsWithDelta(0, $debet(CoaKode::CKPN_PIUTANG_PEMBIAYAAN), 0.01);
        $this->assertEqualsWithDelta(1_000_000, $debet(CoaKode::BEBAN_CKPN_PEMBIAYAAN), 0.01);
        $jurnal->pastikanBalance();
    }

    public function test_piutang_lancar_tidak_boleh_dihapus_buku(): void
    {
        $pembiayaan = $this->pembiayaanDenganTunggakan(1_000_000, 100_000, 0);

        $this->expectException(\RuntimeException::class);
        $this->ckpn->hapusBuku($pembiayaan, now(), $this->pengurus()->id);
    }

    public function test_kontrak_belum_tercair_tidak_boleh_dihapus_buku(): void
    {
        $pembiayaan = $this->pembiayaanDenganTunggakan(
            1_000_000, 100_000, 400, Pembiayaan::STATUS_DISETUJUI
        );

        $this->expectException(\RuntimeException::class);
        $this->ckpn->hapusBuku($pembiayaan, now(), $this->pengurus()->id);
    }

    public function test_hapus_buku_idempoten_dan_keluar_dari_basis_ckpn(): void
    {
        $pembiayaan = $this->pembiayaanDenganTunggakan(500_000, 0, 400);

        $pertama = $this->ckpn->hapusBuku($pembiayaan, now(), $this->pengurus()->id);
        $this->assertNotNull($pertama);

        $kedua = $this->ckpn->hapusBuku($pembiayaan->fresh(), now(), $this->pengurus()->id);
        $this->assertNull($kedua, 'Pemanggilan kedua harus dilewati (idempoten).');

        $basis = collect($this->ckpn->rincian(now()))->pluck('pembiayaan_id');
        $this->assertFalse($basis->contains($pembiayaan->id),
            'Pembiayaan yang sudah dihapus buku tidak boleh masuk basis CKPN.');
    }

    public function test_command_hapus_buku_dry_run_tidak_memposting(): void
    {
        $pembiayaan = $this->pembiayaanDenganTunggakan(500_000, 0, 400);

        $this->artisan('ckpn:hapus-buku', ['--id' => [$pembiayaan->id], '--dry-run' => true])
            ->assertExitCode(0);

        $pembiayaan->refresh();
        $this->assertNotSame(Pembiayaan::STATUS_HAPUS_BUKU, $pembiayaan->status_pembiayaan);
        $this->assertGreaterThan(0, (float) $pembiayaan->sisa_pembiayaan);
    }

    public function test_command_hapus_buku_tanpa_sasaran_ditolak(): void
    {
        $this->artisan('ckpn:hapus-buku')->assertExitCode(2);
    }

    public function test_hari_tunggakan_tidak_mempengaruhi_kolektibilitas_setelah_hapus_buku(): void
    {
        $pembiayaan = $this->pembiayaanDenganTunggakan(500_000, 0, 400);
        $this->ckpn->hapusBuku($pembiayaan, now(), $this->pengurus()->id);

        $this->assertSame(0, $this->ckpn->hariTunggakan($pembiayaan->fresh()),
            'Setelah hapus buku, tidak ada lagi tunggakan yang dihitung.');
    }
}
