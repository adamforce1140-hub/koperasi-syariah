<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Coa;
use App\Models\JenisSimpanan;
use App\Models\KebijakanKoperasi;
use App\Models\Rekening;
use App\Models\User;
use App\Services\TazirService;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Integritas jalur Ta'zir milik Teller (`teller.tazir.proses`).
 *
 * Pemotongan ta'zir langsung oleh teller tidak melalui jadwal angsuran,
 * sehingga tidak ada objek angsuran yang menjadi "penjaga" tagihan. Karena itu
 * penjagaan harus melekat pada nominal & saldo itu sendiri.
 */
class IntegritasTazirTellerTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    private ?array $kebijakanAsli = null;

    private ?int $kebijakanId = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (Coa::query()->count() === 0) {
            $this->markTestSkipped('CoA belum tersedia pada basis data uji.');
        }

        $this->kebijakanAsli = KebijakanKoperasi::query()->first()?->toArray();
        $this->kebijakanId = KebijakanKoperasi::query()->value('id');
        $this->prefix = 'TZ'.strtoupper(substr(uniqid(), -6));

        // Bawaan tiap test: denda AKTIF. Test T-04 mematikannya sendiri dan
        // tearDown() memulihkan nilai asli apa pun hasil test-nya.
        $this->aktifkanDenda(true);
    }

    protected function tearDown(): void
    {
        // Pemulihan tanpa syarat: nilainya diambil dari baris yang benar-benar
        // ada, bukan dari flag yang mungkin tidak sempat diset.
        if ($this->kebijakanId !== null && array_key_exists('financing_late_fee_per_day', $this->kebijakanAsli ?? [])) {
            KebijakanKoperasi::query()->whereKey($this->kebijakanId)
                ->update(['financing_late_fee_per_day' => $this->kebijakanAsli['financing_late_fee_per_day']]);
        }

        Setting::flush();

        parent::tearDown();
    }

    private function user(string $peran, string $sufiks = ''): User
    {
        return User::create([
            'name' => ucfirst($peran).' '.$this->prefix.$sufiks,
            'email' => $peran.'-'.strtolower($this->prefix).$sufiks.'@contoh.test',
            'password' => Hash::make('rahasia123'),
            'role' => $peran,
            'is_active' => true,
        ]);
    }

    private function anggotaDenganSukarela(float $saldo): Anggota
    {
        $anggota = Anggota::create([
            'nama_lengkap' => 'Anggota '.$this->prefix,
            'no_anggota' => Anggota::nomorAnggotaBaru('ANG-'.date('Ym').'-'),
            'nik' => str_pad((string) random_int(1000000000000000, 9999999999999999), 16, '0'),
            'jenis_kelamin' => 'Laki-laki',
            'no_hp' => '081200000019',
            'alamat' => 'Jl. Uji Batch 16 Ta\'zir',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_ACTIVE,
        ]);

        Rekening::create([
            'no_rekening' => 'REK-'.$this->prefix,
            'anggota_id' => $anggota->id,
            'akun_id' => (int) Coa::query()->value('id'),
            'jenis_rekening' => JenisSimpanan::namaRekening(JenisSimpanan::TIPE_SUKARELA)[0],
            'saldo' => $saldo,
            'tanggal_buka' => now()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);

        return $anggota;
    }

    private function aktifkanDenda(bool $aktif): void
    {
        KebijakanKoperasi::query()->whereKey($this->kebijakanId)->update([
            'financing_late_fee_per_day' => $aktif ? 5000 : 0,
        ]);
        Setting::flush();
    }

    /**
     * T-04 — ta'zir ditolak saat fitur denda nonaktif.
     *
     * Tanpa gerbang ini, teller dapat memungut denda tanpa dasar kebijakan
     * tertulis (PSAK 109 — Dana Kebajikan). Kebijakan uji disetel 0 lalu
     * dipulihkan di tearDown().
     */
    public function test_tazir_ditolak_saat_fitur_denda_nonaktif(): void
    {
        $this->aktifkanDenda(false);

        $this->assertFalse(app(TazirService::class)->fiturAktif(),
            'Prasyarat: denda harus nonaktif sebelum permintaan dikirim.');

        $teller = $this->user('teller', 't04');
        $anggota = $this->anggotaDenganSukarela(100_000);
        $this->bukaSesiKas($teller);

        $this->actingAs($teller)
            ->post(route('teller.tazir.proses'), [
                'anggota_id' => $anggota->id,
                'nominal' => 5_000,
            ])
            ->assertSessionHasErrors('nominal');

        $rekening = Rekening::query()->where('anggota_id', $anggota->id)->first();

        $this->assertSame(100_000.0, (float) $rekening->saldo,
            'Ta\'zir yang ditolak tidak boleh mengurangi saldo anggota.');
    }

    /**
     * T-05 — ta'zir ditolak bila saldo sukarela anggota tidak mencukupi.
     *
     * Catatan: perilaku saat ini MENOLAK (saldo tetap, tanpa transaksi).
     * Test ini mengunci perilaku tersebut agar perubahan apa pun di kemudian
     * hari bersifat sadar, bukan kebetulan.
     */
    public function test_tazir_ditolak_bila_saldo_tidak_mencukupi(): void
    {
        $this->aktifkanDenda(true);

        $teller = $this->user('teller', 't05');
        $anggota = $this->anggotaDenganSukarela(1_000);
        $this->bukaSesiKas($teller);

        $this->actingAs($teller)
            ->post(route('teller.tazir.proses'), [
                'anggota_id' => $anggota->id,
                'nominal' => 5_000,
            ]);

        $rekening = Rekening::query()->where('anggota_id', $anggota->id)->first();

        $this->assertSame(1_000.0, (float) $rekening->saldo,
            'Saldo anggota tidak boleh menjadi negatif / berubah saat dana tidak cukup.');
    }

    /**
     * T-06 — nominal nol / negatif tidak pernah diterima.
     */
    public function test_tazir_menolak_nominal_tidak_positif(): void
    {
        $this->aktifkanDenda(true);

        $teller = $this->user('teller', 't06');
        $anggota = $this->anggotaDenganSukarela(100_000);
        $this->bukaSesiKas($teller);

        foreach ([0, -5_000] as $nominal) {
            $this->actingAs($teller)
                ->post(route('teller.tazir.proses'), [
                    'anggota_id' => $anggota->id,
                    'nominal' => $nominal,
                ])
                ->assertSessionHasErrors('nominal');
        }

        $this->assertSame(100_000.0,
            (float) Rekening::query()->where('anggota_id', $anggota->id)->value('saldo'),
            'Nominal tidak sah tidak boleh mengubah saldo anggota.');
    }
}
