<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\Coa;
use App\Models\Pembiayaan;
use App\Models\User;
use App\Services\CkpnService;
use App\Support\Coa as CoaKode;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * CKPN — Cadangan Kerugian Penurunan Nilai piutang pembiayaan.
 *
 * Mengunci kepatuhan PSAK 71 (instrumen keuangan) yang disajikan menurut
 * PSAK 101, sekaligus memastikan modul benar-benar OPERASIONAL:
 *
 *  1. Klasifikasi kolektibilitas mengikuti AMBANG dinamis dari parameter
 *     kebijakan ({@see Setting::ambangKolektibilitas()}), bukan hardcode 30/90/180.
 *  2. Persentase penyisihan dibaca dari parameter kebijakan
 *     ({@see Setting::persenCkpn()}) — zero-hardcoding.
 *  3. Hanya pembiayaan BERJALAN dengan sisa > 0 yang disisihkan.
 *  4. Selisih = target − saldo tercatat akun 1-2100, dan jurnal penyesuaian
 *     memakai pasangan 5-4001 (debet) | 1-2100 (kredit) saat penyisihan naik.
 *  5. Idempotensi: memanggil penyesuaian dua kali tidak menggandakan jurnal.
 *  6. RBAC: halaman dapat dibaca manajer/pengurus/DPS; POST posting hanya
 *     manajer/pengurus; teller & AO ditolak.
 */
class CkpnPsak71Test extends TestCase
{
    use DatabaseTransactions;

    private CkpnService $ckpn;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ckpn = app(CkpnService::class);
        $this->prefix = 'CKPNTEST'.strtoupper(substr(uniqid(), -6));

        if (! Coa::findByKode(CoaKode::CKPN_PIUTANG_PEMBIAYAAN) || ! Coa::findByKode(CoaKode::BEBAN_CKPN_PEMBIAYAAN)) {
            $this->markTestSkipped('CoA 1-2100 / 5-4001 (CKPN) belum ter-seed.');
        }
    }

    /* ─────────────────────────── HELPER ─────────────────────────── */

    private function pengguna(string $role): User
    {
        return User::firstOrCreate(
            ['email' => "{$role}.ckpn.psak71@test.local"],
            [
                'name' => ucfirst($role).' Uji CKPN',
                'password' => Hash::make('password'),
                'role' => $role,
            ]
        );
    }

    private function anggota(): Anggota
    {
        return Anggota::firstOrCreate(
            ['no_anggota' => $this->prefix.'AG'],
            [
                'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
                'nama_lengkap' => 'Anggota Uji CKPN',
                'jenis_kelamin' => 'Laki-laki',
                'alamat' => 'Alamat Uji CKPN',
                'kota' => 'Jakarta',
                'provinsi' => 'DKI Jakarta',
                'status_anggota' => 'Aktif',
                'tanggal_gabung' => now()->toDateString(),
            ]
        );
    }

    /**
     * Buat pembiayaan BERJALAN dengan satu angsuran menunggak $hariTunggakan
     * hari (dihitung dari tanggal acuan) dan sisa pokok $sisa.
     */
    private function pembiayaanMenunggak(float $sisa, int $hariTunggakan, ?Carbon $acuan = null): Pembiayaan
    {
        $acuan = ($acuan ?? Carbon::today())->copy()->startOfDay();
        $jatuhTempo = $acuan->copy()->subDays($hariTunggakan)->toDateString();

        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => $this->prefix.'-'.strtoupper(substr(uniqid(), -6)),
            'anggota_id' => $this->anggota()->id,
            'akun_id' => Coa::query()->value('id') ?? 1,
            'akad_pembiayaan' => 'Murabahah',
            'tujuan_pembiayaan' => 'Uji CKPN PSAK 71',
            'jumlah_pembiayaan' => $sisa,
            'total_pembiayaan' => $sisa,
            'sisa_pembiayaan' => $sisa,
            'total_ujroh' => 0,
            'tenor' => 12,
            'jumlah_angsuran' => round($sisa / 12, 2),
            'tanggal_akad' => $acuan->copy()->subMonths(6)->toDateString(),
            'tanggal_jatuh_tempo' => $jatuhTempo,
            'status_pembiayaan' => Pembiayaan::STATUS_AKTIF,
            'status_kolektibilitas' => null,
        ]);

        Angsuran::create([
            'pembiayaan_id' => $pembiayaan->id,
            'angsuran_ke' => 1,
            'tanggal_jatuh_tempo' => $jatuhTempo,
            'pokok_angsuran' => $sisa,
            'margin_ujroh' => 0,
            'jumlah_angsuran' => $sisa,
            'sisa_pokok' => $sisa,
            'status_angsuran' => 'Jatuh Tempo',
        ]);

        return $pembiayaan->fresh();
    }

    /* ═══════════ 1. KLASIFIKASI KOLEKTIBILITAS (DINAMIS) ═══════════ */

    public function test_klasifikasi_kolektibilitas_mengikuti_ambang_parameter_kebijakan(): void
    {
        $ambang = Setting::ambangKolektibilitas();

        $this->assertSame(Setting::kolektibilitasDariHari($ambang['kol2']), 'kol2');
        $this->assertSame(Setting::kolektibilitasDariHari($ambang['kol3']), 'kol3');
        $this->assertSame(Setting::kolektibilitasDariHari($ambang['kol4']), 'kol4');
        $this->assertSame(Setting::kolektibilitasDariHari($ambang['kol4'] + 1), 'kol5');

        // Ambang wajib menaik supaya klasifikasi tidak pernah tumpang tindih.
        $this->assertGreaterThan($ambang['kol2'], $ambang['kol3']);
        $this->assertGreaterThan($ambang['kol3'], $ambang['kol4']);
    }

    public function test_pembiayaan_lancar_diklasifikasikan_kol1(): void
    {
        $pembiayaan = $this->pembiayaanMenunggak(12000000.00, 0);

        $this->assertSame(0, $this->ckpn->hariTunggakan($pembiayaan));
        $this->assertSame('kol1', $this->ckpn->kolektibilitasKode($pembiayaan));
    }

    public function test_tunggakan_menggeser_kolektibilitas_sesuai_hari(): void
    {
        $ambang = Setting::ambangKolektibilitas();

        $this->assertSame('kol2', $this->ckpn->kolektibilitasKode($this->pembiayaanMenunggak(1000000.00, $ambang['kol2'])));
        $this->assertSame('kol3', $this->ckpn->kolektibilitasKode($this->pembiayaanMenunggak(1000000.00, $ambang['kol3'])));
        $this->assertSame('kol4', $this->ckpn->kolektibilitasKode($this->pembiayaanMenunggak(1000000.00, $ambang['kol4'])));
        $this->assertSame('kol5', $this->ckpn->kolektibilitasKode($this->pembiayaanMenunggak(1000000.00, $ambang['kol4'] + 30)));
    }

    public function test_kolom_status_kolektibilitas_menimpa_perhitungan_hari(): void
    {
        $pembiayaan = $this->pembiayaanMenunggak(5000000.00, 0);
        $pembiayaan->update(['status_kolektibilitas' => 'kol3']);

        $this->assertSame(
            'kol3',
            $this->ckpn->kolektibilitasKode($pembiayaan->fresh()),
            'Kolom status_kolektibilitas adalah penetapan eksplisit yang wajib dihormati.'
        );
    }

    /* ═══════════ 2. TARGET PENYISIHAN (PARAMETER KEBIJAKAN) ═══════════ */

    public function test_rincian_memakai_persentase_dari_parameter_kebijakan(): void
    {
        $persen = Setting::persenCkpn();
        $sisa = 10000000.00;
        $pembiayaan = $this->pembiayaanMenunggak($sisa, 0);
        $pembiayaan->update(['status_kolektibilitas' => 'kol4']);

        $rincian = collect($this->ckpn->rincian())->firstWhere('pembiayaan_id', $pembiayaan->id);

        $this->assertNotNull($rincian, 'Pembiayaan berjalan wajib muncul pada rincian CKPN.');
        $this->assertSame('kol4', $rincian['kolektibilitas']);
        $this->assertSame($persen['kol4'], $rincian['persen']);
        $this->assertSame(round($sisa * $persen['kol4'] / 100, 2), $rincian['ckpn']);
    }

    public function test_pembiayaan_lunas_tidak_disishkan(): void
    {
        $pembiayaan = $this->pembiayaanMenunggak(8000000.00, 0);
        $pembiayaan->update(['status_pembiayaan' => Pembiayaan::STATUS_LUNAS]);

        $id = collect($this->ckpn->rincian())->pluck('pembiayaan_id')->all();

        $this->assertNotContains(
            $pembiayaan->id,
            $id,
            'Pembiayaan lunas tidak boleh lagi membentuk CKPN.'
        );
    }

    public function test_target_saldo_sama_dengan_jumlah_rincian(): void
    {
        $pembiayaan = $this->pembiayaanMenunggak(4000000.00, 0);
        $pembiayaan->update(['status_kolektibilitas' => 'kol5']);

        $target = $this->ckpn->targetSaldo();
        $jumlah = round(array_sum(array_column($this->ckpn->rincian(), 'ckpn')), 2);

        $this->assertSame($jumlah, $target);
        $this->assertGreaterThanOrEqual(
            round(4000000.00 * Setting::persenCkpn()['kol5'] / 100, 2),
            $target,
            'Target minimal memuat penyisihan 100% atas pembiayaan kol5 uji.'
        );
    }

    /* ═══════════ 3. PENYESUAIAN & IDEMPOTENSI ═══════════ */

    public function test_penyesuaian_memposting_beban_dan_penyisihan_sesuai_target(): void
    {
        $pembiayaan = $this->pembiayaanMenunggak(2000000.00, 0);
        $pembiayaan->update(['status_kolektibilitas' => 'kol5']);

        $target = $this->ckpn->targetSaldo();
        $tercatat = $this->ckpn->saldoTercatat();
        $selisih = $this->ckpn->selisih();

        $this->assertSame(round($target - $tercatat, 2), $selisih);

        $jurnal = $this->ckpn->catatPenyesuaian(null, $this->pengguna('manajer')->id);

        $this->assertNotNull($jurnal, 'Selisih di luar toleransi wajib menghasilkan jurnal penyesuaian.');
        $this->assertSame('Posted', $jurnal->status);

        // Idempotensi: setelah selaras, pemanggilan kedua tidak membuat jurnal baru.
        $this->assertNull(
            $this->ckpn->catatPenyesuaian(null, $this->pengguna('manajer')->id),
            'Pemanggilan kedua harus idempoten (tidak membuat jurnal baru).'
        );

        $baris = $jurnal->detail()->get();

        $akunBeban = Coa::findByKode(CoaKode::BEBAN_CKPN_PEMBIAYAAN)->id;
        $akunCKPN = Coa::findByKode(CoaKode::CKPN_PIUTANG_PEMBIAYAAN)->id;

        $beban = $baris->firstWhere('coa_id', $akunBeban);
        $ckpn = $baris->firstWhere('coa_id', $akunCKPN);

        $this->assertNotNull($beban, 'Jurnal penyesuaian wajib mendebet 5-4001 Beban CKPN.');
        $this->assertNotNull($ckpn, 'Jurnal penyesuaian wajib mengkredit 1-2100 Penyisihan CKPN.');
        $this->assertSame(round((float) $beban->debet, 2), round((float) $ckpn->kredit, 2));

        // Setelah posting, saldo tercatat harus sama dengan target. Selisih sisa
        // yang masih ada adalah pembulatan satu-satunya (rupiah penuh kebijakan
        // Setting::pembulatanNominal()), jadi batasnya toleransi + 1 rupiah.
        $this->assertLessThanOrEqual(
            Setting::toleransiSelisihJurnal() + 1.0,
            abs($this->ckpn->selisih()),
            'Setelah penyesuaian, saldo tercatat harus sama dengan target (sisa hanya pembulatan 1 rupiah).'
        );
    }

    public function test_penyesuaian_tidak_dibuat_bila_selisih_dalam_toleransi(): void
    {
        // Posting sekali untuk menyelaraskan saldo, lalu panggil lagi.
        $this->ckpn->catatPenyesuaian(null, $this->pengguna('pengurus')->id);

        $this->assertNull(
            $this->ckpn->catatPenyesuaian(null, $this->pengguna('pengurus')->id),
            'Selisih dalam toleransi tidak boleh menghasilkan jurnal baru.'
        );
    }

    /* ═══════════ 4. RBAC & RENDER HALAMAN ═══════════ */

    public function test_manajer_pengurus_dan_dps_dapat_membuka_halaman_ckpn(): void
    {
        foreach (['manajer', 'pengurus', 'dps'] as $role) {
            $this->actingAs($this->pengguna($role))
                ->get(route('laporan.ckpn'))
                ->assertOk()
                ->assertSee('CKPN');
        }
    }

    public function test_teller_dan_ao_tidak_dapat_membuka_halaman_ckpn(): void
    {
        foreach (['teller', 'ao'] as $role) {
            $this->actingAs($this->pengguna($role))
                ->get(route('laporan.ckpn'))
                ->assertForbidden();
        }
    }

    public function test_hanya_pengurus_yang_dapat_memposting_penyesuaian(): void
    {
        // DPS & Manajer bersifat lihat-saja: penyesuaian mengubah nilai neraca,
        // sehingga dijaga modul tulis 'penyesuaian_jurnal' — di mana matriks
        // LaporanKeuanganPermission memberi hak 'edit' HANYA kepada Pengurus
        // (pemisahan tugas R19/B11: modul laporan sendiri hanya-baca).
        foreach (['dps', 'manajer', 'teller', 'ao'] as $role) {
            $this->actingAs($this->pengguna($role))
                ->post(route('laporan.ckpn.posting'), ['tanggal' => now()->toDateString()])
                ->assertForbidden();
        }

        // Pengurus diizinkan (dapat berupa redirect sukses atau info toleransi).
        $this->actingAs($this->pengguna('pengurus'))
            ->post(route('laporan.ckpn.posting'), ['tanggal' => now()->toDateString()])
            ->assertRedirect();
    }
}
