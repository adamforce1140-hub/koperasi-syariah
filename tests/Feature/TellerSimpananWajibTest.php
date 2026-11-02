<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\MandatorySavingsBill;
use App\Models\User;
use App\Support\Coa as KodeCoa;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Permukaan HTTP Simpanan Wajib di loket Teller (Poin 4.1 — item tertunda).
 *
 * Mengunci jalur yang sebelumnya TIDAK ADA di sistem:
 *  1. Teller dapat membuka daftar tagihan Simpanan Wajib
 *     (`teller.simpanan-wajib.index`).
 *  2. Teller dapat melunasi tagihan manual via tunai/transfer
 *     (`teller.simpanan-wajib.bayar`) yang memanggil
 *     `MandatorySavingsService::bayarViaTeller()`.
 *  3. Jurnal PSAK 101 terposting: DEBET 1-1001 Kas Teller | KREDIT 3-1002
 *     Simpanan Wajib (Modal Anggota) — nominal seimbang.
 *  4. Pelunasan kedua atas tagihan yang sudah PAID bersifat idempoten:
 *     TIDAK membuat jurnal/transaksi baru (anti double posting).
 *  5. Metode AUTO_DEBIT ditolak di jalur loket (itu jalur scheduler).
 *  6. RBAC: role di luar teller/manajer/pengurus tidak boleh mengakses.
 *
 * Basis data memuat data nyata → memakai prefix unik + DatabaseTransactions.
 */
class TellerSimpananWajibTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'TELLWJB'.strtoupper(substr(uniqid(), -6));
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    private function user(string $role = 'teller'): User
    {
        $user = User::firstOrCreate(
            ['email' => "{$role}.{$this->prefix}@test.local"],
            [
                'name' => ucfirst($role).' '.$this->prefix,
                'password' => Hash::make('password'),
                'role' => $role,
            ]
        );

        // Rute `teller.simpanan-wajib.bayar` dijaga middleware `kas.terbuka`.
        if ($role === 'teller') {
            $this->bukaSesiKas($user);
        }

        return $user;
    }

    /** CoA 1-1001 & 3-1002 wajib ada agar jurnal dapat diposting. */
    private function pastikanCoa(): void
    {
        foreach ([KodeCoa::KAS_TELLER, KodeCoa::SIMPANAN_WAJIB_MODAL] as $kode) {
            if (! Coa::findByKode($kode)) {
                $this->markTestSkipped("CoA {$kode} belum tersedia — jalankan CoaSeeder.");
            }
        }
    }

    private function anggota(string $status = 'ACTIVE'): Anggota
    {
        return Anggota::create([
            'no_anggota' => 'AGT-'.$this->prefix,
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Wajib '.$this->prefix,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Alamat Uji Simpanan Wajib',
            'kota' => 'Jakarta',
            'provinsi' => 'DKI Jakarta',
            'status_anggota' => $status,
            'is_auto_debit' => false,
            'tanggal_gabung' => '2026-01-01',
        ]);
    }

    private function tagihan(Anggota $anggota, string $periode = '2099-02', float $nominal = 50000): MandatorySavingsBill
    {
        return MandatorySavingsBill::create([
            'member_id' => $anggota->id,
            'bill_period' => $periode,
            'amount' => $nominal,
            'status' => MandatorySavingsBill::STATUS_UNPAID,
        ]);
    }

    /* =====================================================================
     | 1. HALAMAN DAFTAR TAGIHAN
     |===================================================================== */

    public function test_teller_dapat_membuka_daftar_tagihan_simpanan_wajib(): void
    {
        $this->pastikanCoa();
        $anggota = $this->anggota();
        $this->tagihan($anggota);

        $this->actingAs($this->user())
            ->get(route('teller.simpanan-wajib.index'))
            ->assertOk()
            ->assertSee('Simpanan Wajib Bulanan', false)
            ->assertSee($this->prefix, false);
    }

    /**
     * Ambil hanya isi <tbody data-daftar-tagihan="simpanan-wajib"> agar
     * penelusuran nama anggota tidak tercemar nama pengguna di sidebar.
     */
    private function barisTabel(string $html): string
    {
        if (! preg_match('/<tbody data-daftar-tagihan="simpanan-wajib">(.*?)<\/tbody>/s', $html, $m)) {
            return '';
        }

        return $m[1];
    }

    public function test_filter_status_menyaring_daftar_tagihan(): void
    {
        $this->pastikanCoa();
        $anggota = $this->anggota();
        $this->tagihan($anggota, '2099-02');

        // 1) Tanpa filter, tagihan UNPAID anggota uji harus tampil.
        $htmlTanpaFilter = $this->actingAs($this->user())
            ->get(route('teller.simpanan-wajib.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            $this->prefix,
            $this->barisTabel($htmlTanpaFilter),
            'Tagihan UNPAID tanpa filter harus tampil di tabel.'
        );

        // 2) Filter PAID → tagihan UNPAID anggota uji tidak boleh tampil di tabel.
        $htmlFilterPaid = $this->actingAs($this->user())
            ->get(route('teller.simpanan-wajib.index', ['status' => 'PAID']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            $this->prefix,
            $this->barisTabel($htmlFilterPaid),
            'Filter PAID tidak boleh menampilkan tagihan UNPAID.'
        );
    }

    public function test_role_anggota_tidak_boleh_mengakses_daftar(): void
    {
        $this->actingAs($this->user('anggota'))
            ->get(route('teller.simpanan-wajib.index'))
            ->assertForbidden();
    }

    /* =====================================================================
     | 2. PELUNASAN MANUAL VIA TELLER
     |===================================================================== */

    public function test_pelunasan_tunai_melunasi_tagihan_dan_memposting_jurnal_psak101(): void
    {
        $this->pastikanCoa();
        $anggota = $this->anggota();
        $tagihan = $this->tagihan($anggota, '2026-02', 75000);

        $this->actingAs($this->user())
            ->post(route('teller.simpanan-wajib.bayar', $tagihan->id), [
                'metode' => MandatorySavingsBill::METODE_CASH,
            ])
            ->assertRedirect();

        $tagihan->refresh();

        $this->assertSame(MandatorySavingsBill::STATUS_PAID, $tagihan->status);
        $this->assertSame(MandatorySavingsBill::METODE_CASH, $tagihan->payment_method);
        $this->assertNotNull($tagihan->paid_at, 'paid_at wajib terisi setelah pelunasan.');
        $this->assertNotNull($tagihan->transaksi_id, 'Pelunasan wajib membuat Transaksi.');
        $this->assertNotNull($tagihan->jurnal_id, 'Pelunasan wajib memposting jurnal.');

        // Jurnal PSAK 101: DEBET 1-1001 Kas Teller | KREDIT 3-1002 Simpanan Wajib.
        $jurnal = Jurnal::with('detail')->findOrFail($tagihan->jurnal_id);

        $debet = (float) $jurnal->detail->sum('debet');
        $kredit = (float) $jurnal->detail->sum('kredit');

        $this->assertEqualsWithDelta(75000, $debet, 0.01, 'Total DEBET jurnal harus sama dengan nominal tagihan.');
        $this->assertEqualsWithDelta($debet, $kredit, 0.01, 'Jurnal harus seimbang (DEBET = KREDIT).');

        $kasTeller = Coa::findByKode(KodeCoa::KAS_TELLER);
        $wajibModal = Coa::findByKode(KodeCoa::SIMPANAN_WAJIB_MODAL);

        $this->assertTrue(
            DB::table('jurnal_detail')->where('jurnal_id', $jurnal->id)->where('coa_id', $kasTeller->id)->exists(),
            'Sisi DEBET harus memuat CoA Kas Teller '.KodeCoa::KAS_TELLER.'.'
        );
        $this->assertTrue(
            DB::table('jurnal_detail')->where('jurnal_id', $jurnal->id)->where('coa_id', $wajibModal->id)->exists(),
            'Sisi KREDIT harus memuat CoA Simpanan Wajib '.KodeCoa::SIMPANAN_WAJIB_MODAL.'.'
        );
    }

    public function test_pelunasan_kedua_bersifat_idempoten_tanpa_jurnal_baru(): void
    {
        $this->pastikanCoa();
        $anggota = $this->anggota();
        $tagihan = $this->tagihan($anggota, '2026-03', 50000);

        $user = $this->user();

        $this->actingAs($user)
            ->post(route('teller.simpanan-wajib.bayar', $tagihan->id), ['metode' => MandatorySavingsBill::METODE_CASH])
            ->assertRedirect();

        $tagihan->refresh();
        $jurnalPertama = $tagihan->jurnal_id;
        $trxPertama = $tagihan->transaksi_id;

        // Percobaan kedua atas tagihan yang sudah PAID → tidak boleh double posting.
        $this->actingAs($user)
            ->post(route('teller.simpanan-wajib.bayar', $tagihan->id), ['metode' => MandatorySavingsBill::METODE_TRANSFER])
            ->assertRedirect()
            ->assertSessionHas('warning');

        $tagihan->refresh();

        $this->assertSame($jurnalPertama, $tagihan->jurnal_id, 'Jurnal tidak boleh berubah pada pelunasan ulang.');
        $this->assertSame($trxPertama, $tagihan->transaksi_id, 'Transaksi tidak boleh dibuat ulang.');
        $this->assertSame(MandatorySavingsBill::METODE_CASH, $tagihan->payment_method, 'Metode awal harus dipertahankan.');
    }

    public function test_metode_auto_debit_ditolak_di_jalur_loket(): void
    {
        $this->pastikanCoa();
        $anggota = $this->anggota();
        $tagihan = $this->tagihan($anggota, '2026-04');

        $this->actingAs($this->user())
            ->post(route('teller.simpanan-wajib.bayar', $tagihan->id), [
                'metode' => MandatorySavingsBill::METODE_AUTO_DEBIT,
            ])
            ->assertSessionHasErrors('metode');

        $tagihan->refresh();
        $this->assertSame(MandatorySavingsBill::STATUS_UNPAID, $tagihan->status, 'Tagihan tidak boleh berubah saat validasi gagal.');
    }
}
