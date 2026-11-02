<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\KebijakanKoperasi;
use App\Models\MandatorySavingsBill;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\AutoJournalingService;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Modul Member & Bundling Setoran Awal (Poin 1 — PSAK 101/108).
 *
 * Aturan bisnis & akuntansi yang dikunci oleh test ini:
 *  1. Nominal Simpanan Pokok & Simpanan Wajib bulan ke-1 diambil DINAMIS dari
 *     `kebijakan_koperasis.mandatory_principal_savings` &
 *     `mandatory_monthly_savings` — tidak ada angka yang di-hardcode.
 *  2. Anggota baru selalu dicatat `status_anggota = PENDING`; transisi ke
 *     `ACTIVE` hanya terjadi setelah setoran awal tervalidasi & jurnal terposting.
 *  3. Bundling membuka 2 rekening sekaligus (Simpanan Pokok + Simpanan Wajib)
 *     dengan saldo sesuai porsi komponennya + 2 transaksi setoran.
 *  4. Auto-journal: DEBET 1-1001 (Kas/Bank) = total setoran;
 *     KREDIT 3-1001 (Simpanan Pokok) = porsi pokok;
 *     KREDIT 3-1002 (Simpanan Wajib bulan 1) = porsi wajib.
 *  5. Tagihan Simpanan Wajib periode berjalan langsung dicatat PAID (CASH)
 *     agar scheduler tidak menagih ulang bulan yang sama.
 *
 * Catatan: basis data dev memuat data nyata, sehingga pengujian memakai
 * SELISIH (delta) dan data uji berprefix unik agar deterministik.
 */
class BundlingSetoranAwalTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'BNDL'.strtoupper(substr(uniqid(), -6));
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    private function penggunaAo(): User
    {
        return User::firstOrCreate(
            ['email' => 'bundling.ao@test.local'],
            [
                'name' => 'AO Bundling Uji',
                'password' => Hash::make('password'),
                'role' => 'ao',
                'is_active' => true,
            ]
        );
    }

    /** Isi tabel `akun` (FK wajib `rekening.akun_id`) — lewati bila seeder belum dijalankan. */
    private function akunId(): int
    {
        $id = Akun::where('kode_akun', '1133')->value('id') ?? Akun::query()->value('id');

        if (! $id) {
            $this->markTestSkipped('Tabel akun kosong — jalankan AkunSeeder.');
        }

        return (int) $id;
    }

    /** Akun CoA wajib tersedia agar auto-journal bundling dapat terposting. */
    private function pastikanCoaBundling(): void
    {
        foreach ([
            AutoJournalingService::KAS_TELLER,
            AutoJournalingService::SIMPANAN_POKOK,
            AutoJournalingService::SIMPANAN_WAJIB_MODAL,
        ] as $kode) {
            if (! Coa::findByKode($kode)) {
                $this->markTestSkipped("CoA {$kode} belum tersedia — jalankan CoaSeeder.");
            }
        }
    }

    /**
     * Payload pendaftaran anggota baru yang valid & unik.
     *
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        $suffix = strtoupper(substr(uniqid(), -5));
        $hp = '8'.str_pad((string) random_int(0, 999999999), 11, '0', STR_PAD_LEFT);

        return array_merge([
            'nama_lengkap' => 'Anggota Bundling '.$suffix,
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'jenis_kelamin' => 'Laki-laki',
            'no_hp' => $hp,
            'alamat' => 'Jl. Uji Bundling No. 1',
            'kota' => 'Jakarta',
            'provinsi' => 'DKI Jakarta',
            // OJK-08: deklarasi PEP wajib pada onboarding CIF.
            'pep_status' => Anggota::PEP_BUKAN,
        ], $override);
    }

    /** Saldo akun CoA tertentu (dari jurnal_detail) — untuk uji mutasi akuntansi. */
    private function saldoCoa(string $kode): float
    {
        $coa = Coa::findByKode($kode);

        if (! $coa) {
            return 0.0;
        }

        $debet = (float) DB::table('jurnal_detail')->where('coa_id', $coa->id)->sum('debet');
        $kredit = (float) DB::table('jurnal_detail')->where('coa_id', $coa->id)->sum('kredit');

        return match ($coa->tipe_saldo_normal) {
            'Debet' => $debet - $kredit,
            'Kredit' => $kredit - $debet,
            default => $debet - $kredit,
        };
    }

    /** Detail jurnal berdasarkan kode akun, dibatasi pada jurnal tertentu. */
    private function detailJurnal(Jurnal $jurnal, string $kodeAkun): ?object
    {
        $coa = Coa::findByKode($kodeAkun);

        if (! $coa) {
            return null;
        }

        return DB::table('jurnal_detail')
            ->where('jurnal_id', $jurnal->id)
            ->where('coa_id', $coa->id)
            ->first();
    }

    /**
     * Jurnal bundling setoran awal milik anggota.
     *
     * Tabel `jurnal` tidak menyimpan kolom `anggota_id` (atribusi anggota ada
     * di `jurnal_detail`), sehingga jurnal ditelusuri lewat tagihan Simpanan
     * Wajib periode pertama yang menyimpan `jurnal_id` hasil bundling.
     */
    private function jurnalBundling(Anggota $anggota): ?Jurnal
    {
        $jurnalId = MandatorySavingsBill::where('member_id', $anggota->id)
            ->orderBy('id')
            ->value('jurnal_id');

        if (! $jurnalId) {
            return null;
        }

        return Jurnal::find($jurnalId);
    }

    /* =====================================================================
     | 1. BUNDLING REGISTRASI DINAMIS
     |===================================================================== */

    public function test_registrasi_membuka_bundling_simpanan_dan_mengaktifkan_anggota(): void
    {
        $this->pastikanCoaBundling();
        $this->akunId();

        $pokok = KebijakanKoperasi::simpananPokokWajib();
        $wajib1 = KebijakanKoperasi::simpananWajibBulanan();
        $total = round($pokok + $wajib1, 2);

        $response = $this->actingAs($this->penggunaAo())
            ->post(route('ao.cif.store'), $this->payload([
                'setoran_pertama' => $total,
            ]));

        $anggota = Anggota::where('nama_lengkap', 'like', 'Anggota Bundling %')
            ->where('created_at', '>=', now()->subMinute())
            ->latest('id')
            ->first();

        $this->assertNotNull($anggota, 'Anggota baru tidak tercatat pada tabel anggota.');
        $this->assertSame(Anggota::STATUS_ACTIVE, $anggota->status_anggota);
        $this->assertNotNull($anggota->no_cif);
        $this->assertNotNull($anggota->tanggal_gabung);
        $this->assertSame((float) $pokok, (float) $anggota->simpanan_pokok);
        $this->assertSame((float) $wajib1, (float) $anggota->simpanan_wajib);

        $response->assertRedirect(route('ao.cif.show', $anggota->id));

        // Bundling: 2 rekening (Pokok & Wajib) dengan saldo sesuai porsinya.
        $rekeningPokok = Rekening::where('anggota_id', $anggota->id)
            ->where('jenis_rekening', 'Simpanan Pokok')->first();
        $rekeningWajib = Rekening::where('anggota_id', $anggota->id)
            ->where('jenis_rekening', 'Simpanan Wajib')->first();

        $this->assertNotNull($rekeningPokok, 'Rekening Simpanan Pokok tidak dibuka.');
        $this->assertNotNull($rekeningWajib, 'Rekening Simpanan Wajib tidak dibuka.');
        $this->assertSame((float) $pokok, (float) $rekeningPokok->saldo);
        $this->assertSame((float) $wajib1, (float) $rekeningWajib->saldo);

        // 2 transaksi setoran awal (Debet, Sukses).
        $transaksi = Transaksi::where('anggota_id', $anggota->id)->get();
        $this->assertCount(2, $transaksi);
        $this->assertSame(2, $transaksi->where('posisi', 'Debet')->count());
        $this->assertSame(2, $transaksi->where('status_transaksi', 'Sukses')->count());

        // Tagihan periode berjalan langsung lunas agar tidak ditagih ulang scheduler.
        $bill = MandatorySavingsBill::where('member_id', $anggota->id)
            ->where('bill_period', now()->format('Y-m'))->first();

        $this->assertNotNull($bill, 'Tagihan Simpanan Wajib periode berjalan tidak dicatat.');
        $this->assertSame(MandatorySavingsBill::STATUS_PAID, $bill->status);
        $this->assertSame(MandatorySavingsBill::METODE_CASH, $bill->payment_method);
        $this->assertSame((float) $wajib1, (float) $bill->amount);
    }

    public function test_jurnal_bundling_mematuhi_psak_101_108(): void
    {
        $this->pastikanCoaBundling();
        $this->akunId();

        $pokok = KebijakanKoperasi::simpananPokokWajib();
        $wajib1 = KebijakanKoperasi::simpananWajibBulanan();
        $total = round($pokok + $wajib1, 2);

        $deltaKas = $this->saldoCoa(AutoJournalingService::KAS_TELLER);
        $deltaPokok = $this->saldoCoa(AutoJournalingService::SIMPANAN_POKOK);
        $deltaWajib = $this->saldoCoa(AutoJournalingService::SIMPANAN_WAJIB_MODAL);

        $this->actingAs($this->penggunaAo())
            ->post(route('ao.cif.store'), $this->payload(['setoran_pertama' => $total]));

        $anggota = Anggota::where('nama_lengkap', 'like', 'Anggota Bundling %')
            ->where('created_at', '>=', now()->subMinute())
            ->latest('id')
            ->first();

        $this->assertNotNull($anggota);

        $jurnal = $this->jurnalBundling($anggota);

        $this->assertNotNull($jurnal, 'Jurnal bundling setoran awal tidak terposting.');
        $this->assertSame('Setoran Awal Anggota (Bundling)', $jurnal->sumber);
        $this->assertSame('Posted', $jurnal->status);
        $this->assertTrue((bool) $jurnal->is_balance);
        $this->assertSame($total, round((float) $jurnal->total_debet, 2));
        $this->assertSame($total, round((float) $jurnal->total_kredit, 2));

        // DEBET 1-1001 (Kas/Bank) = total setoran.
        $barisKas = $this->detailJurnal($jurnal, AutoJournalingService::KAS_TELLER);
        $this->assertNotNull($barisKas, 'Baris debet 1-1001 tidak ditemukan.');
        $this->assertSame($total, round((float) $barisKas->debet, 2));
        $this->assertSame(0.0, round((float) $barisKas->kredit, 2));

        // KREDIT 3-1001 (Simpanan Pokok) = porsi pokok.
        $barisPokok = $this->detailJurnal($jurnal, AutoJournalingService::SIMPANAN_POKOK);
        $this->assertNotNull($barisPokok, 'Baris kredit 3-1001 tidak ditemukan.');
        $this->assertSame((float) $pokok, round((float) $barisPokok->kredit, 2));

        // KREDIT 3-1002 (Simpanan Wajib bulan 1) = porsi wajib.
        $barisWajib = $this->detailJurnal($jurnal, AutoJournalingService::SIMPANAN_WAJIB_MODAL);
        $this->assertNotNull($barisWajib, 'Baris kredit 3-1002 tidak ditemukan.');
        $this->assertSame((float) $wajib1, round((float) $barisWajib->kredit, 2));

        // Mutasi Buku Besar bertambah tepat sebesar porsi masing-masing akun.
        $this->assertSame(round($deltaKas + $total, 2), round($this->saldoCoa(AutoJournalingService::KAS_TELLER), 2));
        $this->assertSame(round($deltaPokok + $pokok, 2), round($this->saldoCoa(AutoJournalingService::SIMPANAN_POKOK), 2));
        $this->assertSame(round($deltaWajib + $wajib1, 2), round($this->saldoCoa(AutoJournalingService::SIMPANAN_WAJIB_MODAL), 2));
    }

    public function test_nominal_bundling_diambil_dinamis_dari_kebijakan_koperasi(): void
    {
        $this->pastikanCoaBundling();
        $this->akunId();

        // Kebijakan kustom — membuktikan nominal tidak di-hardcode pada kode.
        // Baris kebijakan tunggal dimutasi (bukan create baris baru) agar
        // Setting::all() — yang membaca baris pertama — ikut berubah.
        $kebijakan = KebijakanKoperasi::getSetting();
        $nilaiAsli = [
            'nama_koperasi' => $kebijakan->nama_koperasi,
            'mandatory_principal_savings' => $kebijakan->mandatory_principal_savings,
            'mandatory_monthly_savings' => $kebijakan->mandatory_monthly_savings,
        ];

        $kebijakan->update([
            'nama_koperasi' => 'Koperasi Uji Bundling',
            'mandatory_principal_savings' => 250000.00,
            'mandatory_monthly_savings' => 75000.00,
        ]);
        Setting::flush();

        try {
            $this->assertSame(250000.0, KebijakanKoperasi::simpananPokokWajib());
            $this->assertSame(75000.0, KebijakanKoperasi::simpananWajibBulanan());
            $this->assertSame(325000.0, KebijakanKoperasi::totalSetoranAwal());

            // Setoran di bawah total bundling kustom → ditolak, anggota baru tidak dibuat.
            $this->actingAs($this->penggunaAo())
                ->from(route('ao.cif.create'))
                ->post(route('ao.cif.store'), $this->payload(['setoran_pertama' => 200000]))
                ->assertRedirect(route('ao.cif.create'))
                ->assertSessionHas('error');

            $this->assertSame(0, Anggota::where('nama_lengkap', 'like', 'Anggota Bundling %')
                ->where('created_at', '>=', now()->subMinute())->count());

            // Nomor anggota berikutnya diarahkan ke no_anggota bebas agar uji deterministik.
            $this->hindariBentrokNoAnggota();

            $this->actingAs($this->penggunaAo())
                ->post(route('ao.cif.store'), $this->payload(['setoran_pertama' => 325000]));

            $anggota = Anggota::where('nama_lengkap', 'like', 'Anggota Bundling %')
                ->where('created_at', '>=', now()->subMinute())
                ->latest('id')
                ->first();

            $this->assertNotNull($anggota, 'Anggota baru tidak tercatat.');
            $this->assertSame(Anggota::STATUS_ACTIVE, $anggota->status_anggota);
            $this->assertSame(250000.0, (float) $anggota->simpanan_pokok);
            $this->assertSame(75000.0, (float) $anggota->simpanan_wajib);

            $this->assertSame(250000.0, (float) Rekening::where('anggota_id', $anggota->id)
                ->where('jenis_rekening', 'Simpanan Pokok')->value('saldo'));
            $this->assertSame(75000.0, (float) Rekening::where('anggota_id', $anggota->id)
                ->where('jenis_rekening', 'Simpanan Wajib')->value('saldo'));

            $jurnal = $this->jurnalBundling($anggota);

            $this->assertNotNull($jurnal);
            $this->assertSame(325000.0, round((float) $this->detailJurnal($jurnal, AutoJournalingService::KAS_TELLER)->debet, 2));
            $this->assertSame(250000.0, round((float) $this->detailJurnal($jurnal, AutoJournalingService::SIMPANAN_POKOK)->kredit, 2));
            $this->assertSame(75000.0, round((float) $this->detailJurnal($jurnal, AutoJournalingService::SIMPANAN_WAJIB_MODAL)->kredit, 2));
        } finally {
            $kebijakan->update($nilaiAsli);
            Setting::flush();
        }
    }

    /**
     * GUARD DOUBLE POSTING — memanggil bundling setoran awal dua kali untuk
     * anggota yang sama tidak boleh menghasilkan jurnal kedua.
     */
    public function test_guard_double_posting_mencegah_jurnal_bundling_ganda(): void
    {
        $this->pastikanCoaBundling();
        $this->akunId();

        $layanan = app(AutoJournalingService::class);

        $anggota = Anggota::create([
            'no_anggota' => 'ANG-9999-GUARD',
            'no_cif' => 'CIF-GUARD-'.$this->prefix,
            'nama_lengkap' => 'Anggota Guard '.$this->prefix,
            'nik' => '9999'.random_int(1000000000, 9999999999),
            'no_hp' => '0899'.random_int(1000000, 9999999),
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Guard No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_PENDING,
            'tanggal_gabung' => today()->toDateString(),
        ]);

        $sebelum = Jurnal::where('sumber', AutoJournalingService::SUMBER_BUNDLING_SETORAN_AWAL)->count();

        $jurnalPertama = $layanan->jurnalBundlingSetoranAwal(
            100000.0, 50000.0, 'Setoran awal guard #1', null, null, null, $anggota->id
        );

        $this->assertNotNull($jurnalPertama);

        $jurnalKedua = $layanan->jurnalBundlingSetoranAwal(
            100000.0, 50000.0, 'Setoran awal guard #2 (double submit)', null, null, null, $anggota->id
        );

        // Percobaan kedua mengembalikan jurnal yang SAMA — bukan jurnal baru.
        $this->assertSame($jurnalPertama->id, $jurnalKedua->id);

        $sesudah = Jurnal::where('sumber', AutoJournalingService::SUMBER_BUNDLING_SETORAN_AWAL)->count();
        $this->assertSame($sebelum + 1, $sesudah, 'Jurnal bundling bertambah lebih dari satu (double posting).');

        // Baris detail juga tidak boleh berduplikasi.
        $this->assertSame(3, DB::table('jurnal_detail')->where('jurnal_id', $jurnalPertama->id)->count());
    }

    /**
     * CifController menurunkan `no_anggota` dari jumlah baris anggota sehingga
     * dapat bertabrakan dengan data nyata (mis. ANG-2026-0017). Untuk uji yang
     * tidak menguji penomoran, geser baris penghalang ke nomor bebas.
     */
    private function hindariBentrokNoAnggota(): void
    {
        $total = Anggota::withTrashed()->count();
        $calon = 'ANG-'.date('Y').'-'.str_pad((string) ($total + 1), 4, '0', STR_PAD_LEFT);

        if (! Anggota::withTrashed()->where('no_anggota', $calon)->exists()) {
            return;
        }

        $noBebas = null;
        for ($i = $total + 2; $i <= $total + 200; $i++) {
            $kandidat = 'ANG-'.date('Y').'-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            if (! Anggota::withTrashed()->where('no_anggota', $kandidat)->exists()) {
                $noBebas = $kandidat;
                break;
            }
        }

        if ($noBebas) {
            DB::table('anggota')->where('no_anggota', $calon)->update(['no_anggota' => $noBebas]);
        }
    }
}
