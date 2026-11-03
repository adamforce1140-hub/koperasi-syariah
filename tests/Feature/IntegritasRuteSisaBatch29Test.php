<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\JenisSimpanan;
use App\Models\KebijakanKoperasi;
use App\Models\MandatorySavingsBill;
use App\Models\Pembiayaan;
use App\Models\Rekening;
use App\Models\User;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BATCH 29 — RUTE SISA YANG BELUM PERNAH DIRUJUK TEST.
 *
 *   W1  admin.settings.clear-cache                POST /admin/settings/clear-cache
 *   W2  zakat.ringkasan                            GET  /pengelola/zakat/ringkasan
 *   W3  teller.simpanan-wajib.cicilan              POST /teller/simpanan-wajib/{tagihan}/cicilan
 *   W4  pengajuan-pembiayaan.show                  GET  /pengajuan-pembiayaan/{id}
 *
 *   A1  rekening.index                             GET  /api/rekening
 *   A2  rekening.show                              GET  /api/rekening/{rekening}
 *   A3  rekening.store                             POST /api/rekening
 *   A4  rekening.update                            PUT  /api/rekening/{rekening}
 *   A5  rekening.destroy                           DEL  /api/rekening/{rekening}
 *
 *   A6  akun.index                                 GET  /api/akun
 *   A7  akun.show                                  GET  /api/akun/{akun}
 *   A8  akun.store                                 POST /api/akun
 *   A9  akun.update                                PUT  /api/akun/{akun}
 *   A10 akun.destroy                               DEL  /api/akun/{akun}
 *
 * Fokus integritas:
 *  - Master `akun` dijaga izin `coa.kelola` (SSOT: super_admin saja); BACA
 *    rekening mengikuti daftar peran operasional, sedangkan BACA akun memakai
 *    daftar yang sama ditambah `super_admin` agar wewenang baca = wewenang tulis.
 *  - `PUT /api/rekening/{id}` TIDAK boleh mengubah `saldo` (K-03): saldo adalah
 *    turunan buku besar PSAK 101, bukan kolom yang dapat disetel lewat API.
 *  - `DELETE /api/akun/{id}` menolak (409) bila akun masih dirujuk rekening,
 *    jurnal, atau akun anak — bukan menghapus diam-diam.
 *  - `DELETE /api/rekening/{id}` bersifat soft delete sehingga jejak audit tetap.
 *  - Cicilan Simpanan Wajib TIDAK memotong saldo bila anggota tidak berhak
 *    auto-debit (`is_auto_debit = false`); tagihan tetap belum lunas.
 *  - `zakat.ringkasan` tetap membalas 200 + struktur dua dana walau CoA dana
 *    belum tersedia (`akunTersedia = false`), bukan 500.
 *  - `pengajuan-pembiayaan.show` memakai `findOrFail` manual (bukan route model
 *    binding) sehingga id tak dikenal harus 404.
 */
class IntegritasRuteSisaBatch29Test extends TestCase
{
    use DatabaseTransactions;

    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('dokumen');

        foreach (['users', 'anggota', 'akun', 'rekening', 'pembiayaan',
            'mandatory_savings_bills', 'kebijakan_koperasis'] as $tabel) {
            if (! Schema::hasTable($tabel)) {
                $this->markTestSkipped("Tabel {$tabel} belum tersedia pada basis data uji.");
            }
        }

        if (Akun::query()->count() === 0) {
            $this->markTestSkipped('Tabel `akun` kosong — jalankan AkunSeeder.');
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

    private function user(string $role, string $penanda): User
    {
        return User::firstOrCreate(
            ['email' => strtolower($role).'.'.$penanda.'.b29.'.strtolower($this->suffix).'@test.local'],
            [
                'name' => 'B29 '.$role.' '.$penanda,
                'password' => Hash::make('password'),
                'role' => $role,
                'is_active' => true,
            ]
        );
    }

    private function anggota(string $kode = 'A'): Anggota
    {
        return Anggota::create([
            'no_anggota' => $kode.'-B29-'.$this->suffix,
            'no_cif' => 'CIF-B29-'.$this->suffix.'-'.$kode,
            'nik' => '3275'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Batch 29 '.$kode.' '.$this->suffix,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji Batch 29 No. '.$kode,
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'no_hp' => '0813'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status_anggota' => Anggota::STATUS_ACTIVE,
            'tanggal_gabung' => now()->subYear()->toDateString(),
            'is_auto_debit' => false,
        ]);
    }

    private function akunLegacyId(): int
    {
        return (int) Akun::query()->value('id');
    }

    /** Akun baru yang belum dirujuk apa pun (aman untuk uji hapus). */
    private function akunBaru(string $klasifikasi = 'Aset'): Akun
    {
        $kode = 'B29'.strtoupper(substr(uniqid(), -6));

        return Akun::create([
            'kode_akun' => $kode,
            'nama_akun' => 'Akun Uji B29 '.$kode,
            'klasifikasi' => $klasifikasi,
            'jenis_akun' => 'Detail',
            'saldo_normal' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * Nama jenis simpanan aktif yang tidak menuntut tanggal jatuh tempo.
     *
     * `rekening.store` memvalidasi `jenis_rekening` terhadap master aktif dan
     * mewajibkan `tanggal_jatuh_tempo` hanya untuk tipe berjangka.
     */
    private function jenisSukarela(): string
    {
        $aktif = JenisSimpanan::daftarAktif();

        if (in_array(JenisSimpanan::NAMA_SUKARELA, $aktif, true)) {
            return JenisSimpanan::NAMA_SUKARELA;
        }

        foreach ($aktif as $nama) {
            if (JenisSimpanan::tipe($nama) !== JenisSimpanan::TIPE_BERJANGKA) {
                return $nama;
            }
        }

        $this->markTestSkipped('Tidak ada jenis simpanan non-berjangka yang aktif pada master.');
    }

    private function rekening(Anggota $anggota, ?Akun $akun = null): Rekening
    {
        return Rekening::create([
            'no_rekening' => 'RKN-B29-'.$this->suffix.'-'.strtoupper(substr(uniqid(), -4)),
            'anggota_id' => $anggota->id,
            'akun_id' => $akun?->id ?? $this->akunLegacyId(),
            'jenis_rekening' => $this->jenisSukarela(),
            'akad' => 'Wadiah',
            'saldo' => 250000.0,
            'nominal_pembukaan' => 250000.0,
            'tanggal_buka' => now()->subMonth()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);
    }

    private function pembiayaan(Anggota $anggota, string $status = Pembiayaan::STATUS_PENDING_AO): Pembiayaan
    {
        return Pembiayaan::create([
            'no_pembiayaan' => 'PMB-B29-'.$this->suffix.'-'.strtoupper(substr(uniqid(), -4)),
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunLegacyId(),
            'akad_pembiayaan' => 'Murabahah',
            'tujuan_pembiayaan' => 'Modal Usaha',
            'jumlah_pembiayaan' => 1000000.0,
            'total_pembiayaan' => 1200000.0,
            'total_ujroh' => 200000.0,
            'sisa_pembiayaan' => 1200000.0,
            'tenor' => 10,
            'jumlah_angsuran' => 120000.0,
            'margin_deferred_amount' => 200000.0,
            'tanggal_akad' => now()->subMonths(6)->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths(10)->toDateString(),
            'status_pembiayaan' => $status,
        ]);
    }

    private function tagihan(Anggota $anggota, string $status = MandatorySavingsBill::STATUS_UNPAID): MandatorySavingsBill
    {
        return MandatorySavingsBill::create([
            'member_id' => $anggota->id,
            'bill_period' => '2099-'.str_pad((string) random_int(1, 12), 2, '0', STR_PAD_LEFT),
            'amount' => 50000.0,
            'status' => $status,
        ]);
    }

    /** Batas nominal pembukaan dari kebijakan (bukan angka literal). */
    private function batasNominal(): array
    {
        return KebijakanKoperasi::batasPembukaanRekening();
    }

    /* =====================================================================
     | W1 — CLEAR CACHE PENGATURAN KEBIJAKAN
     |===================================================================== */

    public function test_clear_cache_pengaturan_mengembalikan_ke_halaman_pengaturan(): void
    {
        $superAdmin = $this->user('super_admin', 'cache');

        $respons = $this->actingAs($superAdmin)
            ->from(route('admin.settings.index'))
            ->post(route('admin.settings.clear-cache'));

        $respons->assertRedirect(route('admin.settings.index'));
        $respons->assertSessionHas(
            'success',
            'Cache pengaturan kebijakan berhasil dibersihkan dan dimuat ulang dari database.'
        );
    }

    public function test_clear_cache_pengaturan_menolak_peran_tanpa_izin(): void
    {
        $teller = $this->user('teller', 'cache');

        $this->actingAs($teller)
            ->post(route('admin.settings.clear-cache'))
            ->assertForbidden();
    }

    /* =====================================================================
     | W2 — RINGKASAN ZAKAT (PSAK 109)
     |===================================================================== */

    public function test_ringkasan_zakat_mengembalikan_struktur_dua_dana(): void
    {
        $pengurus = $this->user('pengurus', 'zakat');

        $respons = $this->actingAs($pengurus)->getJson(route('zakat.ringkasan'));

        $respons->assertOk();
        $respons->assertJsonStructure([
            'ringkasan' => [
                'zakat' => ['dana', 'label', 'akun', 'saldoAwal', 'penerimaan', 'penyaluran', 'saldoAkhir', 'akunTersedia'],
                'infak_sedekah' => ['dana', 'label', 'akun', 'saldoAwal', 'penerimaan', 'penyaluran', 'saldoAkhir', 'akunTersedia'],
            ],
            'valid',
            'diperbarui_pada',
        ]);

        $respons->assertJsonPath('ringkasan.zakat.dana', 'zakat');
        $respons->assertJsonPath('ringkasan.infak_sedekah.dana', 'infak_sedekah');
        $this->assertIsBool($respons->json('valid'));
    }

    public function test_ringkasan_zakat_menolak_rentang_tanggal_terbalik(): void
    {
        $manajer = $this->user('manajer', 'zakat');

        $respons = $this->actingAs($manajer)->getJson(route('zakat.ringkasan', [
            'dari' => '2099-12-31',
            'sampai' => '2099-01-01',
        ]));

        $respons->assertStatus(422);
        $respons->assertJsonValidationErrors('sampai');
    }

    /* =====================================================================
     | W3 — CICILAN SIMPANAN WAJIB DARI SALDO SUKARELA
     |===================================================================== */

    public function test_cicilan_simpanan_wajib_tidak_memotong_saldo_tanpa_hak_auto_debit(): void
    {
        $teller = $this->user('teller', 'cicilan');
        $anggota = $this->anggota('C');
        $tagihan = $this->tagihan($anggota);

        // Anggota `is_auto_debit = false` → gerbang otorisasi auto-debit menolak
        // sebelum jadwal/saldo diperiksa, dan pesan spesifik disampaikan ke petugas.
        $respons = $this->actingAs($teller)
            ->from(route('teller.simpanan-wajib.index'))
            ->post(route('teller.simpanan-wajib.cicilan', $tagihan));

        $respons->assertRedirect(route('teller.simpanan-wajib.index'));
        $respons->assertSessionHas(
            'warning',
            'Anggota belum menyetujui auto-debit — cicilan tidak dapat dipotong dari Simpanan Sukarela.'
        );

        $this->assertSame(
            MandatorySavingsBill::STATUS_UNPAID,
            $tagihan->fresh()->status,
            'Tagihan tidak boleh berubah status bila pemotongan ditolak.'
        );
    }

    public function test_cicilan_simpanan_wajib_menolak_tagihan_yang_sudah_lunas(): void
    {
        $teller = $this->user('teller', 'cicilan');
        $tagihan = $this->tagihan($this->anggota('L'), MandatorySavingsBill::STATUS_PAID);

        $respons = $this->actingAs($teller)
            ->from(route('teller.simpanan-wajib.index'))
            ->post(route('teller.simpanan-wajib.cicilan', $tagihan));

        $respons->assertRedirect(route('teller.simpanan-wajib.index'));
        $respons->assertSessionHas('warning', 'Tagihan sudah lunas — tidak ada tindakan yang diperlukan.');

        $this->assertSame(MandatorySavingsBill::STATUS_PAID, $tagihan->fresh()->status);
    }

    /* =====================================================================
     | A1–A5 — API REKENING
     |===================================================================== */

    public function test_api_rekening_menampilkan_detail(): void
    {
        $teller = $this->user('teller', 'rekening');
        $rekening = $this->rekening($this->anggota('R'));

        $respons = $this->actingAs($teller, 'web')->getJson('/api/rekening/'.$rekening->id);

        $respons->assertOk();
        $respons->assertJsonPath('success', true);
        $respons->assertJsonPath('data.id', $rekening->id);
    }

    public function test_api_rekening_menyimpan_rekening_baru(): void
    {
        $teller = $this->user('teller', 'rekening');
        $anggota = $this->anggota('S');
        $nominal = (float) $this->batasNominal()['minimum'];

        $respons = $this->actingAs($teller, 'web')->postJson('/api/rekening', [
            'no_rekening' => 'RKN-B29-BARU-'.$this->suffix,
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunLegacyId(),
            'jenis_rekening' => $this->jenisSukarela(),
            'akad' => 'Wadiah',
            'nominal_pembukaan' => $nominal,
            'tanggal_buka' => now()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);

        $respons->assertCreated();
        $respons->assertJsonPath('success', true);

        $this->assertDatabaseHas('rekening', [
            'no_rekening' => 'RKN-B29-BARU-'.$this->suffix,
            'anggota_id' => $anggota->id,
        ]);
    }

    public function test_api_rekening_menolak_nominal_di_bawah_batas_kebijakan(): void
    {
        $teller = $this->user('teller', 'rekening');
        $anggota = $this->anggota('T');

        $respons = $this->actingAs($teller, 'web')->postJson('/api/rekening', [
            'no_rekening' => 'RKN-B29-MURAH-'.$this->suffix,
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunLegacyId(),
            'jenis_rekening' => $this->jenisSukarela(),
            'akad' => 'Wadiah',
            'nominal_pembukaan' => (float) $this->batasNominal()['minimum'] - 1,
            'tanggal_buka' => now()->toDateString(),
        ]);

        $respons->assertStatus(422);
        $respons->assertJsonValidationErrors('nominal_pembukaan');
        $this->assertDatabaseMissing('rekening', ['no_rekening' => 'RKN-B29-MURAH-'.$this->suffix]);
    }

    public function test_api_rekening_update_tidak_mengubah_saldo(): void
    {
        $teller = $this->user('teller', 'rekening');
        $anggota = $this->anggota('U');
        $rekening = $this->rekening($anggota);
        $saldoAwal = (float) $rekening->saldo;

        $respons = $this->actingAs($teller, 'web')->putJson('/api/rekening/'.$rekening->id, [
            'no_rekening' => $rekening->no_rekening,
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunLegacyId(),
            'jenis_rekening' => $rekening->jenis_rekening,
            'akad' => 'Mudharabah',
            // K-03 — nilai ini harus DIABAIKAN: saldo hanya berubah lewat jurnal.
            'saldo' => 99999999,
            'tanggal_buka' => $rekening->tanggal_buka->toDateString(),
        ]);

        $respons->assertOk();

        $segar = $rekening->fresh();
        $this->assertSame('Mudharabah', $segar->akad);
        $this->assertSame($saldoAwal, (float) $segar->saldo, 'PUT /api/rekening tidak boleh mengubah saldo.');
    }

    public function test_api_rekening_menghapus_rekening_secara_soft_delete(): void
    {
        $teller = $this->user('teller', 'rekening');
        $rekening = $this->rekening($this->anggota('H'));

        $respons = $this->actingAs($teller, 'web')->deleteJson('/api/rekening/'.$rekening->id);

        $respons->assertOk();
        $respons->assertJsonPath('success', true);
        $this->assertSoftDeleted('rekening', ['id' => $rekening->id]);
    }

    public function test_api_rekening_menolak_peran_di_luar_kelompok_izin_tulis(): void
    {
        $anggota = $this->anggota('X');
        $payload = [
            'no_rekening' => 'RKN-B29-TOLAK-'.$this->suffix,
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunLegacyId(),
            'jenis_rekening' => $this->jenisSukarela(),
            'akad' => 'Wadiah',
            'nominal_pembukaan' => (float) $this->batasNominal()['minimum'],
            'tanggal_buka' => now()->toDateString(),
        ];

        // Grup tulis `routes/api.php` dijaga `izin:buku_besar.tulis`
        // (teller, manajer, pengurus, super_admin — lihat K-1). Peran yang
        // tidak tercantum di sana wajib ditolak sebelum menyentuh database.
        // `admin` ikut ditolak: K-1 mencabut wewenang tulis keuangannya.
        foreach (['dps', 'ao', 'admin'] as $peran) {
            $this->actingAs($this->user($peran, 'tolakrek'), 'web')
                ->postJson('/api/rekening', $payload)
                ->assertForbidden();
        }

        $this->assertDatabaseMissing('rekening', ['no_rekening' => 'RKN-B29-TOLAK-'.$this->suffix]);
    }

    /* =====================================================================
     | A6–A10 — API MASTER AKUN (izin `coa.kelola` = super_admin)
     |===================================================================== */

    public function test_api_akun_menampilkan_detail(): void
    {
        $teller = $this->user('teller', 'akun');
        $akun = $this->akunBaru();

        $respons = $this->actingAs($teller, 'web')->getJson('/api/akun/'.$akun->id);

        $respons->assertOk();
        $respons->assertJsonPath('success', true);
        $respons->assertJsonPath('data.id', $akun->id);
    }

    public function test_api_akun_menyimpan_akun_baru_sebagai_super_admin(): void
    {
        $superAdmin = $this->user('super_admin', 'akun');
        $kode = 'B29NEW'.strtoupper(substr(uniqid(), -5));

        $respons = $this->actingAs($superAdmin, 'web')->postJson('/api/akun', [
            'kode_akun' => $kode,
            'nama_akun' => 'Akun Baru Batch 29',
            'klasifikasi' => 'Aset',
            'jenis_akun' => 'Detail',
            'saldo_normal' => 0,
            'is_active' => true,
        ]);

        $respons->assertCreated();
        $respons->assertJsonPath('success', true);
        $this->assertDatabaseHas('akun', ['kode_akun' => $kode]);
    }

    public function test_api_akun_memperbarui_akun_sebagai_super_admin(): void
    {
        $superAdmin = $this->user('super_admin', 'akun');
        $akun = $this->akunBaru();
        $namaBaru = 'Akun Diperbarui B29 '.$this->suffix;

        $respons = $this->actingAs($superAdmin, 'web')->putJson('/api/akun/'.$akun->id, [
            'kode_akun' => $akun->kode_akun,
            'nama_akun' => $namaBaru,
            'klasifikasi' => $akun->klasifikasi,
            'jenis_akun' => $akun->jenis_akun,
            'saldo_normal' => 0,
            'is_active' => true,
        ]);

        $respons->assertOk();
        $this->assertSame($namaBaru, $akun->fresh()->nama_akun);
    }

    public function test_api_akun_menolak_hapus_akun_yang_masih_dipakai(): void
    {
        $superAdmin = $this->user('super_admin', 'akun');
        $akun = $this->akunBaru();
        $this->rekening($this->anggota('P'), $akun);

        $respons = $this->actingAs($superAdmin, 'web')->deleteJson('/api/akun/'.$akun->id);

        $respons->assertStatus(409);
        $this->assertStringContainsString('tidak dapat dihapus', (string) $respons->json('message'));
        $this->assertNotSoftDeleted('akun', ['id' => $akun->id]);
    }

    public function test_api_akun_menghapus_akun_tanpa_referensi(): void
    {
        $superAdmin = $this->user('super_admin', 'akun');
        $akun = $this->akunBaru();

        $respons = $this->actingAs($superAdmin, 'web')->deleteJson('/api/akun/'.$akun->id);

        $respons->assertOk();
        $respons->assertJsonPath('success', true);
        $this->assertSoftDeleted('akun', ['id' => $akun->id]);
    }

    public function test_api_akun_menolak_peran_tanpa_izin_coa_kelola(): void
    {
        foreach (['admin', 'pengurus', 'teller', 'manajer', 'ao', 'dps', 'anggota'] as $peran) {
            $this->actingAs($this->user($peran, 'tolakakun'), 'web')
                ->postJson('/api/akun', [
                    'kode_akun' => 'B29X'.strtoupper(substr(uniqid(), -5)),
                    'nama_akun' => 'Akun Ditolak B29',
                    'klasifikasi' => 'Aset',
                    'jenis_akun' => 'Detail',
                ])
                ->assertForbidden();
        }
    }

    /**
     * PEMBUKTIAN PERILAKU — super_admin satu-satunya pengelola master akun.
     *
     * Grup TULIS `/api/akun` dijaga `izin:coa.kelola` (super_admin). Sebelum
     * B-29a diperbaiki, grup BACA memakai daftar peran operasional pada `role:`
     * yang tidak memuat super_admin sehingga `POST` 201 namun `GET` 403.
     * Regresi ini mengunci kesetaraan wewenang: apa yang boleh ditulis harus
     * boleh dibaca kembali oleh peran yang sama.
     */
    public function test_super_admin_dapat_menulis_dan_membaca_kembali_akun_lewat_api(): void
    {
        $superAdmin = $this->user('super_admin', 'akun');
        $kode = 'B29SA'.strtoupper(substr(uniqid(), -5));

        $simpan = $this->actingAs($superAdmin, 'web')->postJson('/api/akun', [
            'kode_akun' => $kode,
            'nama_akun' => 'Akun Super Admin B29',
            'klasifikasi' => 'Aset',
            'jenis_akun' => 'Detail',
        ]);
        $simpan->assertCreated();

        $id = (int) Akun::query()->where('kode_akun', $kode)->value('id');
        $this->assertGreaterThan(0, $id);

        $this->actingAs($superAdmin, 'web')
            ->getJson('/api/akun/'.$id)
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.kode_akun', $kode);
    }

    /* =====================================================================
     | W4 — DETAIL PENGAJUAN PEMBIAYAAN
     |===================================================================== */

    public function test_halaman_detail_pengajuan_pembiayaan_dapat_ditampilkan(): void
    {
        $pengurus = $this->user('pengurus', 'pembiayaan');
        $pembiayaan = $this->pembiayaan($this->anggota('D'));

        $respons = $this->actingAs($pengurus)
            ->get(route('pengajuan-pembiayaan.show', $pembiayaan->id));

        $respons->assertOk();
        $respons->assertViewIs('pengajuan-pembiayaan.show');
        $respons->assertViewHas('pembiayaan', fn ($nilai) => $nilai->is($pembiayaan));
        $respons->assertSee($pembiayaan->no_pembiayaan);
    }

    public function test_detail_pengajuan_pembiayaan_mengembalikan_404_untuk_id_tidak_ada(): void
    {
        $ao = $this->user('ao', 'pembiayaan');

        $this->actingAs($ao)
            ->get(route('pengajuan-pembiayaan.show', 2147483000))
            ->assertNotFound();
    }

    /* =====================================================================
     | PAGAR RBAC
     |===================================================================== */

    public function test_rute_api_batch_29_menolak_peran_anggota(): void
    {
        $anggota = $this->anggota('G');
        $rekening = $this->rekening($anggota);
        $akun = $this->akunBaru();
        $anggotaPengguna = $this->user('anggota', 'rbac');

        $rute = [
            ['GET', '/api/rekening'],
            ['GET', '/api/rekening/'.$rekening->id],
            ['POST', '/api/rekening'],
            ['PUT', '/api/rekening/'.$rekening->id],
            ['DELETE', '/api/rekening/'.$rekening->id],
            ['GET', '/api/akun'],
            ['GET', '/api/akun/'.$akun->id],
            ['POST', '/api/akun'],
            ['PUT', '/api/akun/'.$akun->id],
            ['DELETE', '/api/akun/'.$akun->id],
        ];

        $this->assertCount(10, $rute, 'Batch 29 harus mencakup tepat 10 rute apiResource rekening + akun.');

        foreach ($rute as [$metode, $uri]) {
            $this->actingAs($anggotaPengguna, 'web')->call($metode, $uri)->assertForbidden();
        }
    }

    public function test_rute_web_batch_29_menolak_peran_anggota(): void
    {
        $anggota = $this->anggota('G');
        $tagihan = $this->tagihan($anggota);
        $pembiayaan = $this->pembiayaan($anggota);
        $anggotaPengguna = $this->user('anggota', 'rbac');

        $rute = [
            ['POST', route('admin.settings.clear-cache')],
            ['GET', route('zakat.ringkasan')],
            ['POST', route('teller.simpanan-wajib.cicilan', $tagihan)],
            ['GET', route('pengajuan-pembiayaan.show', $pembiayaan->id)],
        ];

        $this->assertCount(4, $rute, 'Batch 29 harus mencakup tepat 4 rute web sisa.');

        foreach ($rute as [$metode, $uri]) {
            $this->actingAs($anggotaPengguna)->call($metode, $uri)->assertForbidden();
        }
    }

    public function test_tamu_diarahkan_ke_halaman_login(): void
    {
        $rekening = $this->rekening($this->anggota('N'));
        $akun = $this->akunBaru();
        $pembiayaan = $this->pembiayaan($this->anggota('N2'));

        foreach ([
            route('zakat.ringkasan'),
            route('pengajuan-pembiayaan.show', $pembiayaan->id),
            '/api/rekening/'.$rekening->id,
            '/api/akun/'.$akun->id,
        ] as $uri) {
            $this->get($uri)->assertRedirect(route('login'));
        }
    }
}
