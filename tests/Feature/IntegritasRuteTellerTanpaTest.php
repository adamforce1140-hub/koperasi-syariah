<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\Coa;
use App\Models\Deposito;
use App\Models\DepositoPenaltyRelief;
use App\Models\DepositoProduct;
use App\Models\KasHarian;
use App\Models\Pembiayaan;
use App\Models\Rekening;
use App\Models\TazirReliefRequest;
use App\Models\Transaksi;
use App\Models\User;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * BATCH 24 — RUTE TELLER YANG BELUM PERNAH DIRUJUK TEST (13 rute).
 *
 *   T1  teller.rekap                        T8  teller.keringanan-penalti.create
 *   T2  teller.riwayat                      T9  teller.keringanan-penalti.store
 *   T3  teller.riwayat (ajax_check)         T10 teller.keringanan-penalti.index
 *   T4  teller.deposito.show                T11 teller.keringanan-penalti.anggota
 *   T5  teller.kas.setor-brankas            T12 teller.keringanan-tazir.index
 *   T6  teller.anggota.card                 T13 pagar RBAC 403
 *   T7  teller.pencairan-disetujui.index
 *
 * Fokus integritas:
 *  - halaman baca hanya memuat data yang relevan bagi teller yang login,
 *  - rute kas dijaga guard sesi kas (`kas.terbuka`) dan guard anti-deficit,
 *  - endpoint JSON tidak membocorkan PII (NIK / hash blind index) — UU PDP 27/2022,
 *  - bilyet deposito milik anggota lain tidak boleh muncul pada rekomendasi,
 *  - pagar RBAC menolak peran tanpa izin `teller.loket`.
 */
class IntegritasRuteTellerTanpaTest extends TestCase
{
    use DatabaseTransactions;

    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['anggota', 'depositos', 'deposito_products', 'deposito_penalty_reliefs',
            'tazir_relief_requests', 'kas_harian', 'transaksi', 'rekening'] as $tabel) {
            if (! Schema::hasTable($tabel)) {
                $this->markTestSkipped("Tabel {$tabel} belum tersedia.");
            }
        }

        $this->suffix = strtoupper(substr(uniqid(), -6));
        Setting::flush();
    }

    protected function tearDown(): void
    {
        Setting::flush();

        parent::tearDown();
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    private function user(string $role): User
    {
        return User::firstOrCreate(
            ['email' => "{$role}.b24.".strtolower($this->suffix).'@test.local'],
            [
                'name' => ucfirst($role).' B24 '.$this->suffix,
                'password' => Hash::make('password'),
                'role' => $role,
                'is_active' => true,
            ]
        );
    }

    private function anggota(string $kode = 'A', string $status = Anggota::STATUS_ACTIVE): Anggota
    {
        return Anggota::create([
            'no_anggota' => 'B24-'.$this->suffix.'-'.$kode,
            'no_cif' => 'CIF-B24-'.$this->suffix.'-'.$kode,
            'nik' => '3273'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Teller '.$kode.' '.$this->suffix,
            'tempat_lahir' => 'Bandung',
            'tanggal_lahir' => '1990-01-15',
            'jenis_kelamin' => 'Laki-laki',
            'status_perkawinan' => 'Menikah',
            'alamat' => 'Jl. Uji Batch 24 No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => $status,
            'tanggal_gabung' => now()->subYear()->toDateString(),
        ]);
    }

    private function produkDeposito(float $penaltiNominal = 50000): DepositoProduct
    {
        return DepositoProduct::create([
            'name' => 'Deposito B24 '.$this->suffix,
            'tipe_produk' => DepositoProduct::TIPE_DEPOSITO,
            'has_nisbah' => true,
            'coa_kode' => DepositoProduct::COA_BY_TIPE[DepositoProduct::TIPE_DEPOSITO],
            'tenor_months' => 3,
            'nisbah_anggota' => 60,
            'nisbah_koperasi' => 40,
            'min_amount' => 100000,
            'penalty_fee_type' => DepositoProduct::PENALTI_FIXED,
            'penalty_fee_amount' => $penaltiNominal,
            'is_active' => true,
        ]);
    }

    private function akunLegacyId(): int
    {
        $id = Akun::query()->value('id');

        if (! $id) {
            $this->markTestSkipped('Tabel `akun` kosong — jalankan AkunSeeder.');
        }

        return (int) $id;
    }

    private function rekening(Anggota $anggota, string $jenis = 'Simpanan Sukarela'): Rekening
    {
        return Rekening::create([
            'no_rekening' => 'RKN-B24-'.$this->suffix.'-'.strtoupper(substr(uniqid(), -5)),
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunLegacyId(),
            'jenis_rekening' => $jenis,
            'akad' => 'Wadiah',
            'saldo' => 0,
            'batas_minimum' => 0,
            'tanggal_buka' => now()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);
    }

    private function deposito(Anggota $anggota, string $status = Deposito::STATUS_ACTIVE): Deposito
    {
        $produk = $this->produkDeposito();

        return Deposito::create([
            'deposito_number' => 'DEP-B24-'.$this->suffix.'-'.strtoupper(substr(uniqid(), -5)),
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $this->rekening($anggota)->id,
            'principal_amount' => 2000000,
            'nisbah_anggota' => 60,
            'nisbah_koperasi' => 40,
            'start_date' => now()->subMonth()->toDateString(),
            'maturity_date' => now()->addMonths(2)->toDateString(),
            'status' => $status,
        ]);
    }

    private function pembiayaan(Anggota $anggota, string $status): Pembiayaan
    {
        $akunId = (int) (Coa::query()->value('id') ?? 0);

        if ($akunId === 0) {
            $this->markTestSkipped('CoA belum tersedia pada basis data uji.');
        }

        return Pembiayaan::create([
            'no_pembiayaan' => 'PB-B24-'.$this->suffix.'-'.strtoupper(substr(uniqid(), -5)),
            'anggota_id' => $anggota->id,
            'akun_id' => $akunId,
            'akad_pembiayaan' => 'qardh',
            'jumlah_pembiayaan' => 1000000,
            'total_pembiayaan' => 1000000,
            'tenor' => 10,
            'jumlah_angsuran' => 100000,
            'sisa_pembiayaan' => 1000000,
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths(10)->toDateString(),
            'status_pembiayaan' => $status,
        ]);
    }

    private function angsuran(Pembiayaan $pembiayaan): Angsuran
    {
        return Angsuran::create([
            'pembiayaan_id' => $pembiayaan->id,
            'angsuran_ke' => 1,
            'jumlah_angsuran' => 100000,
            'sisa_pokok' => 900000,
            'tanggal_jatuh_tempo' => now()->addMonth()->toDateString(),
        ]);
    }

    private function transaksi(Anggota $anggota, User $teller, array $atribut = []): Transaksi
    {
        return Transaksi::create(array_merge([
            'no_transaksi' => 'TRX-B24-'.$this->suffix.'-'.strtoupper(substr(uniqid(), -6)),
            'anggota_id' => $anggota->id,
            'user_id' => $teller->id,
            'jenis_transaksi' => 'Setoran Tunai',
            'posisi' => 'Kredit',
            'jumlah' => 100000,
            'waktu_transaksi' => now(),
            'status_transaksi' => 'Sukses',
        ], $atribut));
    }

    /* =====================================================================
     | T1 — REKAP KAS HARIAN
     |===================================================================== */

    public function test_rekap_kas_memuat_modal_awal_dan_transaksi_teller_sendiri(): void
    {
        $teller = $this->user('teller');
        $this->bukaSesiKas($teller, null, 500000);

        $anggota = $this->anggota();
        $this->transaksi($anggota, $teller, ['jumlah' => 250000]);

        $respons = $this->actingAs($teller)->get(route('teller.rekap'));

        $respons->assertOk()
            ->assertViewIs('teller.rekap')
            ->assertViewHas('modalAwal', fn ($v) => (float) $v === 500000.0)
            ->assertViewHas('saldoSistem', fn ($v) => (float) $v === 750000.0)
            ->assertViewHas('transaksiHariIni', fn ($t) => $t->count() === 1)
            ->assertViewHas('totalSetor', fn ($v) => (float) $v === 250000.0)
            ->assertViewHas('errorKas', null);
    }

    /* =====================================================================
     | T2 & T3 — RIWAYAT TRANSAKSI
     |===================================================================== */

    public function test_riwayat_menyaring_berdasarkan_pencarian_dan_jenis(): void
    {
        $teller = $this->user('teller');
        $anggota = $this->anggota('R');

        $target = $this->transaksi($anggota, $teller, [
            'no_transaksi' => 'TRX-B24-CARI-'.$this->suffix,
            'jenis_transaksi' => 'Setoran Tunai',
        ]);
        $this->transaksi($this->anggota('S'), $teller, ['jenis_transaksi' => 'Penarikan Tunai']);

        // Pencarian memakai nomor transaksi yang unik.
        $this->actingAs($teller)
            ->get(route('teller.riwayat', ['cari' => 'TRX-B24-CARI-'.$this->suffix]))
            ->assertOk()
            ->assertViewHas('transaksi', fn ($t) => $t->total() === 1
                && $t->first()->id === $target->id);

        // Filter jenis transaksi menyaring kelompok lain.
        $this->actingAs($teller)
            ->get(route('teller.riwayat', ['jenis' => 'Penarikan Tunai']))
            ->assertOk()
            ->assertViewHas('transaksi', fn ($t) => $t->pluck('jenis_transaksi')->unique()->all() === ['Penarikan Tunai']);
    }

    public function test_riwayat_ajax_mengembalikan_hanya_transaksi_teller_yang_login(): void
    {
        $teller = $this->user('teller');
        $tellerLain = User::firstOrCreate(
            ['email' => 'teller.lain.b24.'.strtolower($this->suffix).'@test.local'],
            ['name' => 'Teller Lain B24', 'password' => Hash::make('password'), 'role' => 'teller', 'is_active' => true]
        );

        $milikSaya = $this->transaksi($this->anggota('X'), $teller);
        $milikLain = $this->transaksi($this->anggota('Y'), $tellerLain);

        $respons = $this->actingAs($teller)->getJson(route('teller.riwayat', ['ajax_check' => 1]));

        $respons->assertOk();
        $ids = collect($respons->json('transaksi'))->pluck('id')->all();

        $this->assertContains($milikSaya->id, $ids);
        $this->assertNotContains($milikLain->id, $ids, 'Teller hanya boleh melihat status transaksinya sendiri.');
    }

    /* =====================================================================
     | T4 — DETAIL DEPOSITO
     |===================================================================== */

    public function test_detail_deposito_menghitung_bagi_hasil_dan_menolak_rekening_bukan_berjangka(): void
    {
        $teller = $this->user('teller');
        $anggota = $this->anggota('D');
        $deposito = $this->deposito($anggota);

        $rekeningBerjangka = $this->rekening($anggota, 'Simpanan Berjangka');

        $this->transaksi($anggota, $teller, [
            'rekening_id' => $rekeningBerjangka->id,
            'jenis_transaksi' => 'Bagi Hasil Deposito',
            'jumlah' => 30000,
        ]);
        $this->transaksi($anggota, $teller, [
            'rekening_id' => $rekeningBerjangka->id,
            'jenis_transaksi' => 'Bagi Hasil Deposito',
            'jumlah' => 20000,
        ]);

        $this->actingAs($teller)
            ->get(route('teller.deposito.show', $rekeningBerjangka))
            ->assertOk()
            ->assertViewIs('teller.deposito-show')
            ->assertViewHas('bagiHasilTotal', fn ($v) => (float) $v === 50000.0)
            ->assertViewHas('mutasi', fn ($m) => $m->count() === 2);

        // Rekening non-berjangka bukan objek halaman ini → 404, bukan data kosong.
        $sukarela = $this->rekening($anggota);
        $this->actingAs($teller)
            ->get(route('teller.deposito.show', $sukarela))
            ->assertNotFound();

        $this->assertSame(Deposito::STATUS_ACTIVE, $deposito->fresh()->status);
    }

    /* =====================================================================
     | T5 — SETOR KAS KE BRANKAS
     |===================================================================== */

    public function test_setor_brankas_ditolak_tanpa_sesi_kas_terbuka(): void
    {
        $teller = $this->user('teller');

        $this->actingAs($teller)
            ->post(route('teller.kas.setor-brankas'), ['nominal_setor' => 10000])
            ->assertRedirect();

        $this->assertSame(
            0,
            (int) KasHarian::where('user_id', $teller->id)->whereDate('tanggal', today())->count(),
            'Tanpa sesi Buka Kas, setoran brankas tidak boleh membuat sesi kas baru.'
        );
    }

    public function test_setor_brankas_menolak_nominal_di_atas_saldo_laci(): void
    {
        $teller = $this->user('teller');
        $this->bukaSesiKas($teller, null, 100000);

        $this->actingAs($teller)
            ->post(route('teller.kas.setor-brankas'), ['nominal_setor' => 500000])
            ->assertSessionHas('error');

        $kas = KasHarian::where('user_id', $teller->id)->whereDate('tanggal', today())->first();
        $this->assertNotNull($kas);
        $this->assertSame(0, (int) DB::table('transaksi')
            ->where('user_id', $teller->id)
            ->where('jenis_transaksi', 'like', '%Brankas%')
            ->count(), 'Setoran melebihi saldo laci tidak boleh dibukukan.');
    }

    public function test_setor_brankas_dalam_batas_saldo_laci_dibukukan(): void
    {
        $teller = $this->user('teller');
        $this->bukaSesiKas($teller, null, 1000000);

        $this->actingAs($teller)
            ->post(route('teller.kas.setor-brankas'), ['nominal_setor' => 250000])
            ->assertSessionHas('success');

        $posisi = KasHarian::hitungSaldoKasTeller($teller->id);
        $this->assertSame(750000.0, round((float) $posisi['saldo'], 2),
            'Setoran brankas harus mengurangi saldo laci teller sebesar nominal yang disetor.');
    }

    /* =====================================================================
     | T6 — KARTU ANGGOTA
     |===================================================================== */

    public function test_kartu_anggota_tampil_untuk_anggota_ada_dan_404_bila_tidak_ada(): void
    {
        $teller = $this->user('teller');
        $anggota = $this->anggota('K');
        $this->rekening($anggota);

        $this->actingAs($teller)
            ->get(route('teller.anggota.card', $anggota->id))
            ->assertOk()
            ->assertViewIs('teller.anggota.card')
            ->assertViewHas('anggota', fn ($a) => $a->id === $anggota->id && $a->rekening->isNotEmpty());

        $this->actingAs($teller)
            ->get(route('teller.anggota.card', 999999999))
            ->assertNotFound();
    }

    /* =====================================================================
     | T7 — DAFTAR PENCAIRAN DISETUJUI
     |===================================================================== */

    public function test_pencairan_disetujui_hanya_memuat_status_disetujui(): void
    {
        $teller = $this->user('teller');
        $anggota = $this->anggota('P');

        // Rencana pencairan hari ini agar bilyet layak cair.
        $siap = $this->pembiayaan($anggota, Pembiayaan::STATUS_DISETUJUI);
        $siap->update(['tgl_pencairan' => now()->toDateString()]);

        $belumDisetujui = $this->pembiayaan($anggota, Pembiayaan::STATUS_PENDING_PENCAIRAN);

        $this->actingAs($teller)
            ->get(route('teller.pencairan-disetujui.index'))
            ->assertOk()
            ->assertViewIs('teller.pencairan.index')
            ->assertViewHas('pembiayaan', function ($daftar) use ($siap, $belumDisetujui) {
                $ids = collect($daftar)->pluck('id')->all();

                return in_array($siap->id, $ids, true)
                    && ! in_array($belumDisetujui->id, $ids, true);
            });
    }

    /* =====================================================================
     | T8 & T9 — KERINGANAN PENALTI DEPOSITO (AJUKAN)
     |===================================================================== */

    public function test_form_keringanan_penalti_tanpa_dan_dengan_anggota(): void
    {
        $teller = $this->user('teller');
        $anggota = $this->anggota('F');
        $deposito = $this->deposito($anggota);

        $this->actingAs($teller)
            ->get(route('teller.keringanan-penalti.create'))
            ->assertOk()
            ->assertViewHas('anggota', null)
            ->assertViewHas('deposito', fn ($d) => $d->isEmpty());

        $this->actingAs($teller)
            ->get(route('teller.keringanan-penalti.create', ['anggota_id' => $anggota->id]))
            ->assertOk()
            ->assertViewHas('anggota', fn ($a) => $a->id === $anggota->id)
            ->assertViewHas('deposito', fn ($d) => $d->pluck('id')->all() === [$deposito->id]);
    }

    public function test_store_keringanan_menolak_deposito_yang_tidak_aktif(): void
    {
        $teller = $this->user('teller');
        $deposito = $this->deposito($this->anggota('G'), Deposito::STATUS_CLOSED);

        $this->actingAs($teller)
            ->post(route('teller.keringanan-penalti.store'), [
                'deposito_id' => $deposito->id,
                'alasan_pengaju' => 'Anggota mengalami musibah.',
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, DepositoPenaltyRelief::where('deposito_id', $deposito->id)->count(),
            'Deposito yang sudah ditutup tidak boleh menerima pengajuan keringanan.');
    }

    public function test_store_keringanan_ditolak_tanpa_alasan_validasi(): void
    {
        $teller = $this->user('teller');
        $deposito = $this->deposito($this->anggota('H'));

        $this->actingAs($teller)
            ->post(route('teller.keringanan-penalti.store'), ['deposito_id' => $deposito->id])
            ->assertSessionHasErrors('alasan_pengaju');

        $this->assertSame(0, DepositoPenaltyRelief::where('deposito_id', $deposito->id)->count());
    }

    public function test_store_keringanan_membuat_pengajuan_pending_ao_dan_menolak_duplikat(): void
    {
        $teller = $this->user('teller');
        $deposito = $this->deposito($this->anggota('I'));

        $this->actingAs($teller)
            ->post(route('teller.keringanan-penalti.store'), [
                'deposito_id' => $deposito->id,
                'alasan_pengaju' => 'Anggota sakit dan membutuhkan dana darurat.',
            ])
            ->assertRedirect(route('teller.keringanan-penalti.index'))
            ->assertSessionHas('success');

        $relief = DepositoPenaltyRelief::where('deposito_id', $deposito->id)->sole();

        $this->assertSame(DepositoPenaltyRelief::STATUS_PENDING_AO, $relief->status);
        $this->assertSame($teller->id, (int) $relief->pengaju_id);
        $this->assertSame((int) $deposito->member_id, (int) $relief->anggota_id,
            'Anggota pada pengajuan wajib diturunkan dari pemilik bilyet, bukan dari input klien.');
        $this->assertSame(50000.0, (float) $relief->nominal_penalti_awal,
            'Nominal penalti awal dihitung dari tarif produk, bukan dari input klien.');

        // Pengajuan kedua untuk bilyet yang sama harus ditolak.
        $this->actingAs($teller)
            ->post(route('teller.keringanan-penalti.store'), [
                'deposito_id' => $deposito->id,
                'alasan_pengaju' => 'Pengajuan ganda.',
            ])
            ->assertSessionHas('error');

        $this->assertSame(1, DepositoPenaltyRelief::where('deposito_id', $deposito->id)->count(),
            'Satu bilyet hanya boleh memiliki satu pengajuan keringanan yang berjalan.');
    }

    /* =====================================================================
     | T10 — RIWAYAT KERINGANAN PENALTI
     |===================================================================== */

    public function test_riwayat_keringanan_hanya_memuat_pengajuan_teller_sendiri(): void
    {
        $teller = $this->user('teller');
        $tellerLain = User::firstOrCreate(
            ['email' => 'teller.penalti.b24.'.strtolower($this->suffix).'@test.local'],
            ['name' => 'Teller Penalti B24', 'password' => Hash::make('password'), 'role' => 'teller', 'is_active' => true]
        );
        $anggota = $this->anggota('J');
        $depositoA = $this->deposito($anggota);
        $depositoB = $this->deposito($anggota);

        DepositoPenaltyRelief::create([
            'deposito_id' => $depositoA->id,
            'anggota_id' => $anggota->id,
            'nominal_penalti_awal' => 50000,
            'alasan_pengaju' => 'Milik teller login.',
            'pengaju_id' => $teller->id,
            'status' => DepositoPenaltyRelief::STATUS_PENDING_AO,
        ]);
        DepositoPenaltyRelief::create([
            'deposito_id' => $depositoB->id,
            'anggota_id' => $anggota->id,
            'nominal_penalti_awal' => 50000,
            'alasan_pengaju' => 'Milik teller lain.',
            'pengaju_id' => $tellerLain->id,
            'status' => DepositoPenaltyRelief::STATUS_PENDING_AO,
        ]);

        $this->actingAs($teller)
            ->get(route('teller.keringanan-penalti.index'))
            ->assertOk()
            ->assertViewHas('pengajuan', function ($halaman) use ($depositoA, $depositoB) {
                $ids = collect($halaman->items())->pluck('deposito_id')->all();

                return in_array($depositoA->id, $ids, true) && ! in_array($depositoB->id, $ids, true);
            });

        // Filter status tetap tersedia dan menyaring.
        $this->actingAs($teller)
            ->get(route('teller.keringanan-penalti.index', ['status' => DepositoPenaltyRelief::STATUS_APPROVED]))
            ->assertOk()
            ->assertViewHas('pengajuan', fn ($h) => $h->total() === 0);
    }

    /* =====================================================================
     | T11 — INFO BILYET ANGGOTA (JSON)
     |===================================================================== */

    public function test_info_anggota_hanya_mengembalikan_bilyet_aktif_milik_anggota_tersebut(): void
    {
        $teller = $this->user('teller');
        $pemilik = $this->anggota('L');
        $orangLain = $this->anggota('M');

        $aktif = $this->deposito($pemilik);
        $this->deposito($pemilik, Deposito::STATUS_MATURED);
        $this->deposito($orangLain);

        $respons = $this->actingAs($teller)
            ->getJson(route('teller.keringanan-penalti.anggota', $pemilik->id));

        $respons->assertOk()
            ->assertJsonPath('anggota.id', (int) $pemilik->id)
            ->assertJsonCount(1, 'deposito')
            ->assertJsonPath('deposito.0.id', (int) $aktif->id);

        $bilyet = $respons->json('deposito.0');

        // JSON tidak membedakan 2000000.0 dan 2000000 — bandingkan nominal.
        $this->assertSame(2000000.0, (float) $bilyet['pokok'],
            'Pokok bilyet harus diturunkan dari nilai deposito yang tersimpan.');
        $this->assertSame(50000.0, (float) $bilyet['penalti_estimasi'],
            'Estimasi penalti dihitung dari tarif produk, bukan dari input klien.');
        $this->assertSame(
            (string) Rekening::find($aktif->source_saving_account_id)->no_rekening,
            (string) $bilyet['sumber_sukarela'],
            'Rekomendasi wajib menunjukkan rekening sumber dana bilyet tersebut.'
        );
    }

    /**
     * Endpoint JSON tidak boleh mengirim nilai mentah `Anggota` maupun
     * hash blind index. `Anggota::$hidden` hanya memuat `nik_hash`, sedangkan
     * `nik` tersimpan terenkripsi (cast `encrypted`) sehingga terbaca kembali
     * di sisi klien bila objek model diserialisasi apa adanya.
     */
    public function test_info_anggota_tidak_membocorkan_nik_dan_hash(): void
    {
        $teller = $this->user('teller');
        $pemilik = $this->anggota('N');
        $this->deposito($pemilik);

        $respons = $this->actingAs($teller)
            ->getJson(route('teller.keringanan-penalti.anggota', $pemilik->id));

        $respons->assertOk();

        $isi = $respons->getContent();
        $this->assertStringNotContainsString((string) $pemilik->nik, $isi,
            'NIK penuh tidak boleh dikirim ke klien (UU PDP No. 27/2022).');
        $this->assertStringNotContainsString('nik_hash', $isi,
            'Hash blind index NIK tidak boleh dikirim ke klien.');
        $this->assertStringNotContainsString('tempat_lahir', $isi,
            'Endpoint ringkasan bilyet tidak memerlukan data pribadi anggota.');
    }

    /* =====================================================================
     | T12 — RIWAYAT KERINGANAN TAZIR
     |===================================================================== */

    public function test_riwayat_tazir_menghitung_ringkasan_per_status_milik_teller(): void
    {
        $teller = $this->user('teller');
        $tellerLain = User::firstOrCreate(
            ['email' => 'teller.tazir.b24.'.strtolower($this->suffix).'@test.local'],
            ['name' => 'Teller Tazir B24', 'password' => Hash::make('password'), 'role' => 'teller', 'is_active' => true]
        );
        $anggota = $this->anggota('T');

        foreach ([[$teller, TazirReliefRequest::STATUS_PENDING_AO],
            [$teller, TazirReliefRequest::STATUS_APPROVED],
            [$tellerLain, TazirReliefRequest::STATUS_APPROVED]] as $i => [$pemilik, $status]) {
            $pembiayaan = $this->pembiayaan($anggota, Pembiayaan::STATUS_AKTIF);

            TazirReliefRequest::create([
                'jadwal_angsuran_id' => $this->angsuran($pembiayaan)->id,
                'pembiayaan_id' => $pembiayaan->id,
                'anggota_id' => $anggota->id,
                'nominal_tazir_awal' => 10000,
                'alasan_teller' => 'Uji ringkasan '.$i,
                'teller_id' => $pemilik->id,
                'status' => $status,
            ]);
        }

        $this->actingAs($teller)
            ->get(route('teller.keringanan-tazir.index'))
            ->assertOk()
            ->assertViewHas('totalPending', 1)
            ->assertViewHas('totalApproved', 1)
            ->assertViewHas('totalRejected', 0)
            ->assertViewHas('keringanans', fn ($h) => $h->total() === 2);
    }

    /* =====================================================================
     | T13 — PAGAR RBAC
     |===================================================================== */

    public function test_peran_tanpa_izin_teller_loket_ditolak_403(): void
    {
        $anggota = $this->anggota('Z');
        $deposito = $this->deposito($anggota);

        $rute = [
            ['get', route('teller.rekap')],
            ['get', route('teller.riwayat')],
            ['get', route('teller.pencairan-disetujui.index')],
            ['get', route('teller.keringanan-penalti.create')],
            ['get', route('teller.keringanan-penalti.index')],
            ['get', route('teller.keringanan-tazir.index')],
            ['get', route('teller.anggota.card', $anggota->id)],
            ['get', route('teller.keringanan-penalti.anggota', $anggota->id)],
            ['post', route('teller.keringanan-penalti.store')],
        ];

        foreach (['ao', 'dps', 'anggota'] as $peran) {
            $pengguna = $this->user($peran);

            foreach ($rute as [$metode, $url]) {
                $this->actingAs($pengguna)
                    ->{$metode}($url)
                    ->assertForbidden();
            }
        }

        $this->assertSame(Deposito::STATUS_ACTIVE, $deposito->fresh()->status);
    }

    public function test_manajer_dan_pengurus_dapat_membuka_halaman_baca_teller(): void
    {
        foreach (['manajer', 'pengurus'] as $peran) {
            $pengguna = $this->user($peran);

            $this->actingAs($pengguna)->get(route('teller.rekap'))->assertOk();
            $this->actingAs($pengguna)->get(route('teller.riwayat'))->assertOk();
        }
    }
}
