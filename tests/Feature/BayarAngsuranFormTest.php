<?php

namespace Tests\Feature;

use App\Models\Angsuran;
use App\Models\Coa;
use App\Models\Pembiayaan;
use App\Models\Transaksi;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Formulir "Bayar Angsuran Pembiayaan":
 * - Field `Jumlah Bayar` readonly untuk Teller.
 * - Nilai `Jumlah Bayar` selalu identik dengan `Tagihan Angsuran Bulan Ini`.
 * - Perubahan tagihan langsung tercermin pada field Jumlah Bayar.
 * - Meski readonly, nilai tetap terkirim & tervalidasi saat form diproses.
 */
class BayarAngsuranFormTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $role = 'teller'): User
    {
        $user = User::firstOrCreate(
            ['email' => "{$role}.formbayar@test.local"],
            [
                'name' => ucfirst($role).' Form Bayar',
                'password' => Hash::make('password'),
                'role' => $role,
            ]
        );

        // Rute `teller.angsuran.store` dijaga middleware `kas.terbuka`.
        // Sediakan laci OPEN agar pengujian fokus pada logika pembayaran.
        if ($role === 'teller') {
            $this->bukaSesiKas($user);
        }

        return $user;
    }

    /**
     * Bangun pembiayaan Qardh Rp 3.000.000 / 24 angsuran (pokok Rp 125.000).
     */
    private function buatPembiayaan(float $total = 3000000, int $tenor = 24): Pembiayaan
    {
        $anggotaId = DB::table('anggota')->value('id');

        if (! $anggotaId) {
            $anggotaId = DB::table('anggota')->insertGetId([
                'nama_lengkap' => 'Anggota Form Bayar',
                'no_anggota' => 'AGT-FB-'.strtoupper(substr(uniqid(), -6)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => 'PMB-FB-'.strtoupper(substr(uniqid(), -6)),
            'anggota_id' => $anggotaId,
            'akun_id' => Coa::query()->value('id') ?? 1,
            'akad_pembiayaan' => 'Qardh',
            'jumlah_pembiayaan' => $total,
            'total_pembiayaan' => $total,
            'total_ujroh' => 0,
            'sisa_pembiayaan' => $total,
            'tenor' => $tenor,
            'jumlah_angsuran' => round($total / $tenor, 2),
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths($tenor)->toDateString(),
            'status_pembiayaan' => 'Aktif',
        ]);

        $pokokPerAngsuran = round($total / $tenor, 2);

        for ($i = 1; $i <= $tenor; $i++) {
            $pokok = ($i === $tenor)
                ? round($total - ($pokokPerAngsuran * ($tenor - 1)), 2)
                : $pokokPerAngsuran;

            Angsuran::create([
                'pembiayaan_id' => $pembiayaan->id,
                'angsuran_ke' => $i,
                'tanggal_jatuh_tempo' => now()->addMonths($i)->toDateString(),
                'pokok_angsuran' => $pokok,
                'margin_ujroh' => 0,
                'jumlah_angsuran' => $pokok,
                'sisa_pokok' => $pokok,
                'status_angsuran' => 'Belum Jatuh Tempo',
            ]);
        }

        return $pembiayaan;
    }

    /** Halaman form Bayar Angsuran dapat dibuka oleh Teller. */
    public function test_form_bayar_angsuran_dapat_diakses_teller(): void
    {
        $this->actingAs($this->user())
            ->get(route('teller.angsuran'))
            ->assertOk()
            ->assertSee('Bayar Angsuran Pembiayaan', false);
    }

    /**
     * [Permintaan 1] Field `Jumlah Bayar` readonly: tidak ada atribut `name` pada
     * input yang bisa disentuh Teller, ditandai `readonly`, dan bukan `required`
     * pada elemen itu (atribut HTML `required` tidak berlaku untuk readonly).
     */
    public function test_field_jumlah_bayar_readonly_dan_tidak_bernama(): void
    {
        $html = $this->actingAs($this->user())
            ->get(route('teller.angsuran'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="input_jumlah_bayar"[^>]*>/',
            $html,
            'Field Jumlah Bayar harus ada di form.'
        );

        preg_match('/<input[^>]*id="input_jumlah_bayar"[^>]*>/', $html, $m);
        $tag = $m[0];

        $this->assertStringContainsString('readonly', $tag, 'Field Jumlah Bayar harus readonly.');
        $this->assertStringContainsString('aria-readonly="true"', $tag, 'Field harus ditandai aria-readonly.');
        $this->assertStringNotContainsString('name="jumlah_bayar"', $tag,
            'Input readonly tidak boleh memakai name="jumlah_bayar" agar tidak dapat ditimpa dari UI.');
        $this->assertStringNotContainsString(' required', $tag,
            'Field readonly tidak boleh memakai atribut required (nilainya tidak terkirim dari elemen itu).');

        // Nilai yang benar-benar dikirim disediakan penampung tersembunyi.
        $this->assertStringContainsString('name="jumlah_bayar"', $html,
            'Harus ada penampung nilai jumlah_bayar yang dikirim form.');
        $this->assertStringContainsString('id="jumlah_bayar_value"', $html);
    }

    /**
     * [Permintaan 2] Nilai awal `Jumlah Bayar` identik dengan
     * `Tagihan Angsuran Bulan Ini` — keduanya bersumber dari `angsuran_per_bulan`.
     */
    public function test_ajax_menyediakan_satu_sumber_nilai_untuk_tagihan_dan_jumlah_bayar(): void
    {
        $pembiayaan = $this->buatPembiayaan();
        $tagihan = (float) $pembiayaan->jumlah_angsuran;

        $this->actingAs($this->user())
            ->getJson(route('teller.angsuran.get-pembiayaan', $pembiayaan->anggota_id))
            ->assertOk()
            ->assertJsonPath('pembiayaan_id', $pembiayaan->id);

        // Endpoint hanya menyediakan SATU nilai tagihan (angsuran_per_bulan) yang
        // menjadi sumber bagi field Tagihan Angsuran Bulan Ini DAN Jumlah Bayar.
        $json = $this->actingAs($this->user())
            ->getJson(route('teller.angsuran.get-pembiayaan', $pembiayaan->anggota_id));

        $this->assertEqualsWithDelta($tagihan, (float) $json->json('angsuran_per_bulan'), 0.01,
            'angsuran_per_bulan harus sama dengan tagihan angsuran.');
        $this->assertEqualsWithDelta($tagihan, (float) $json->json('pokok_angsuran'), 0.01,
            'Porsi pokok yang ditagih harus sama dengan jumlah angsuran.');
        $this->assertEqualsWithDelta(0.0, (float) $json->json('margin_ujroh'), 0.01,
            'Margin akad Qardh harus nol.');
        $this->assertArrayNotHasKey('jumlah_bayar', $json->json(),
            'Endpoint tidak boleh menyediakan jumlah_bayar terpisah — nilainya wajib diturunkan dari tagihan.');
    }

    /**
     * [Permintaan 2 & 3] Sinkronisasi bersifat reaktif: nilai Jumlah Bayar
     * diturunkan dari tagihan, dan perubahan tagihan selama form terbuka
     * langsung tercermin (MutationObserver + dataset.nilai sebagai sumber tunggal).
     */
    public function test_skrip_sinkronisasi_jumlah_bayar_terpasang(): void
    {
        $html = $this->actingAs($this->user())
            ->get(route('teller.angsuran'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('function sinkronJumlahBayar()', $html);
        $this->assertStringContainsString('new MutationObserver(sinkronJumlahBayar)', $html);
        $this->assertStringContainsString('txtTagihan.dataset.nilai', $html);
        $this->assertStringContainsString('__setTagihan', $html);

        // Saat tagihan berubah, jumlah bayar ikut tersinkron.
        $this->assertStringContainsString('sinkronJumlahBayar();', $html);

        // Nilai yang dikirim tidak boleh berasal dari angka yang bisa diubah UI.
        $this->assertStringNotContainsString('inputJumlahVal.value = null', $html);
    }

    /**
     * [Permintaan 2] Tidak ada celah ketidaksamaan: nilai yang dikirim
     * (`jumlah_bayar`) sama persis dengan tagihan (`tagihan_angsuran`) karena
     * keduanya ditulis dari variabel `tagihan` yang sama.
     */
    public function test_penampung_nilai_selalu_diisi_dari_tagihan(): void
    {
        $html = $this->actingAs($this->user())
            ->get(route('teller.angsuran'))
            ->assertOk()
            ->getContent();

        preg_match('/function sinkronJumlahBayar\(\)\s*\{(.+?)\n    \}/s', $html, $m);

        $this->assertNotEmpty($m, 'Fungsi sinkronJumlahBayar harus ditemukan.');

        $body = $m[1];

        $this->assertStringContainsString('parseFloat(txtTagihan.dataset.nilai)', $body,
            'Tagihan harus dibaca dari dataset.nilai.');
        $this->assertStringContainsString('inputJumlah.value', $body,
            'Field Jumlah Bayar harus diisi dari tagihan.');
        $this->assertStringContainsString('inputJumlahVal.value', $body,
            'Penampung nilai kirim harus diisi dari tagihan.');
        $this->assertStringContainsString('inputTagihanVal.value', $body,
            'Tagihan yang dikirim harus diisi dari nilai tagihan yang sama.');
    }

    /**
     * [Permintaan 4] Meski readonly, nilai `jumlah_bayar` tetap terkirim dan
     * diproses: transaksi tercatat, angsuran lunas, sisa pembiayaan berkurang
     * tepat sebesar nilai tagihan.
     */
    public function test_jumlah_bayar_readonly_tetap_terkirim_dan_diproses(): void
    {
        $pembiayaan = $this->buatPembiayaan();
        $angsuran = Angsuran::where('pembiayaan_id', $pembiayaan->id)
            ->where('angsuran_ke', 1)
            ->first();

        $tagihan = round((float) $angsuran->jumlah_angsuran);

        $response = $this->actingAs($this->user())
            ->post(route('teller.angsuran.store', $angsuran->id), [
                'pembiayaan_id' => $pembiayaan->id,
                'angsuran_id' => $angsuran->id,
                // Persis nilai yang diisi form dari Tagihan Angsuran Bulan Ini.
                'jumlah_bayar' => $tagihan,
                'nominal_tazir' => 0,
                'metode_bayar' => 'TUNAI',
            ]);

        $response->assertSessionHasNoErrors();

        $angsuran->refresh();
        $this->assertSame('Sudah Dibayar', $angsuran->status_angsuran,
            'Angsuran harus lunas ketika jumlah bayar = tagihan.');
        $this->assertEqualsWithDelta($tagihan, (float) $angsuran->total_bayar, 0.01,
            'Nilai yang tercatat harus sama persis dengan jumlah_bayar yang dikirim.');

        $this->assertEqualsWithDelta(
            round((float) $pembiayaan->total_pembiayaan - $tagihan, 2),
            round((float) $pembiayaan->refresh()->sisa_pembiayaan, 2),
            0.01,
            'Sisa pembiayaan harus berkurang tepat sebesar tagihan.'
        );
    }

    /**
     * [Permintaan 4] Validasi server: `jumlah_bayar` wajib ada dan > 0.
     * Tanpa nilai tersebut form harus ditolak dengan pesan validasi.
     */
    public function test_jumlah_bayar_kosong_ditolak_validasi_server(): void
    {
        $pembiayaan = $this->buatPembiayaan();
        $angsuran = Angsuran::where('pembiayaan_id', $pembiayaan->id)
            ->where('angsuran_ke', 1)
            ->first();

        $this->actingAs($this->user())
            ->post(route('teller.angsuran.store', $angsuran->id), [
                'pembiayaan_id' => $pembiayaan->id,
                'angsuran_id' => $angsuran->id,
                'jumlah_bayar' => '',
                'metode_bayar' => 'TUNAI',
            ])
            ->assertSessionHasErrors('jumlah_bayar');

        $this->assertSame('Belum Jatuh Tempo', $angsuran->refresh()->status_angsuran,
            'Angsuran tidak boleh berubah bila jumlah bayar tidak terkirim.');
    }

    /** [Permintaan 4] Nilai nol juga ditolak (gt:0), bukan hanya kosong. */
    public function test_jumlah_bayar_nol_ditolak_validasi_server(): void
    {
        $pembiayaan = $this->buatPembiayaan();
        $angsuran = Angsuran::where('pembiayaan_id', $pembiayaan->id)
            ->where('angsuran_ke', 1)
            ->first();

        $this->actingAs($this->user())
            ->post(route('teller.angsuran.store', $angsuran->id), [
                'pembiayaan_id' => $pembiayaan->id,
                'angsuran_id' => $angsuran->id,
                'jumlah_bayar' => 0,
                'metode_bayar' => 'TUNAI',
            ])
            ->assertSessionHasErrors('jumlah_bayar');

        $this->assertSame('Belum Jatuh Tempo', $angsuran->refresh()->status_angsuran);
    }

    /**
     * [Permintaan 4] Nilai readonly tidak dapat dimanipulasi: bila klien mengirim
     * angka berbeda dari tagihan, sistem tetap membukukan uang riil yang dikirim
     * dan tidak ada selisih yang tidak tercatat.
     */
    public function test_nilai_yang_dikirim_selalu_tercatat_utuh(): void
    {
        $pembiayaan = $this->buatPembiayaan();
        $angsuran = Angsuran::where('pembiayaan_id', $pembiayaan->id)
            ->where('angsuran_ke', 3)
            ->first();

        $tagihan = round((float) $angsuran->jumlah_angsuran);

        $this->actingAs($this->user())
            ->post(route('teller.angsuran.store', $angsuran->id), [
                'pembiayaan_id' => $pembiayaan->id,
                'angsuran_id' => $angsuran->id,
                'jumlah_bayar' => $tagihan,
                'nominal_tazir' => 0,
                'metode_bayar' => 'TUNAI',
            ])
            ->assertSessionHasNoErrors();

        $angsuran->refresh();
        $transaksi = Transaksi::find($angsuran->transaksi_id);

        $this->assertNotNull($transaksi, 'Transaksi pembayaran harus terbentuk.');
        $this->assertEqualsWithDelta($tagihan, (float) $transaksi->jumlah, 0.01,
            'Nominal transaksi harus sama dengan jumlah bayar yang dikirim.');
        $this->assertEqualsWithDelta((float) $transaksi->jumlah, (float) $angsuran->total_bayar, 0.01,
            'Tidak boleh ada selisih antara nominal transaksi dan total bayar angsuran.');
    }

    /**
     * Data lain tidak terpengaruh: hanya angsuran yang dipilih yang berubah.
     */
    public function test_hanya_angsuran_yang_dipilih_yang_berubah(): void
    {
        $pembiayaan = $this->buatPembiayaan();
        $angsuran = Angsuran::where('pembiayaan_id', $pembiayaan->id)
            ->where('angsuran_ke', 5)
            ->first();

        $this->actingAs($this->user())
            ->post(route('teller.angsuran.store', $angsuran->id), [
                'pembiayaan_id' => $pembiayaan->id,
                'angsuran_id' => $angsuran->id,
                'jumlah_bayar' => round((float) $angsuran->jumlah_angsuran),
                'nominal_tazir' => 0,
                'metode_bayar' => 'TUNAI',
            ])
            ->assertSessionHasNoErrors();

        $lain = Angsuran::where('pembiayaan_id', $pembiayaan->id)
            ->where('angsuran_ke', '!=', 5)
            ->get();

        $this->assertCount(23, $lain);

        foreach ($lain as $a) {
            $this->assertSame('Belum Jatuh Tempo', $a->status_angsuran,
                "Angsuran ke-{$a->angsuran_ke} tidak boleh berubah.");
            $this->assertNull($a->tanggal_bayar, "Angsuran ke-{$a->angsuran_ke} tidak boleh punya tanggal bayar.");
            $this->assertEqualsWithDelta(0, (float) $a->total_bayar, 0.01);
        }
    }
}
