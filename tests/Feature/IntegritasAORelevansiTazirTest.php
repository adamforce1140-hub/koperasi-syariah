<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\Coa;
use App\Models\KebijakanKoperasi;
use App\Models\Pembiayaan;
use App\Models\TazirReliefRequest;
use App\Models\User;
use App\Support\PemisahanTugas;
use App\Support\PiiHasher;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BATCH 14 - Integritas jalur AO (dua rute yang menyentuh data uang).
 *
 * 1. Verifikasi Keringanan Ta'zir (`ao.keringanan-tazir.*`).
 *    Kontrak rute yang diuji:
 *      - `rekomendasi()` hanya diproses bila pengajuan masih PENDING_AO;
 *      - hasil verifikasi = rekomendasi tercatat + status PENDING_MANAJER;
 *      - pengaju tidak boleh memverifikasi pengajuannya sendiri (R-01).
 *
 * 2. Pendaftaran anggota cepat oleh AO (`ao.anggota.quick-store`).
 *    Kontrak rute yang diuji:
 *      - NIK duplikat ditolak (blind index `nik_hash`, UU PDP 27/2022);
 *      - permintaan tidak sah tidak meninggalkan baris anggota yatim.
 *
 * Prinsip: test ini TIDAK melonggarkan kode produksi. Assertion ditulis
 * sesuai aturan GCG yang sudah berlaku di sistem (lihat
 * `DepositoPenaltyReliefController::rekomendasi()` yang memanggil
 * PemisahanTugas) sehingga kegagalan menandakan celah nyata.
 */
class IntegritasAORelevansiTazirTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    /** Nilai asli kolom kebijakan yang disentuh test. */
    private ?array $kebijakanAsli = null;

    private ?int $kebijakanId = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Baris `kebijakan_koperasis` adalah singleton; pulihkan di tearDown()
        // agar tidak mempolusi berkas test lain (pelajaran batch 13).
        $kebijakan = KebijakanKoperasi::query()->first();

        if ($kebijakan !== null) {
            $this->kebijakanId = (int) $kebijakan->getKey();
            $this->kebijakanAsli = [
                'tazir_mode' => $kebijakan->getRawOriginal('tazir_mode'),
                'financing_late_fee_per_day' => $kebijakan->getRawOriginal('financing_late_fee_per_day'),
            ];
        }

        // Berkas KTP jangan ditulis ke storage/app/dokumen sungguhan.
        Storage::fake('dokumen');

        if (Coa::query()->count() === 0) {
            $this->markTestSkipped('CoA belum tersedia pada basis data uji.');
        }

        $this->prefix = 'AZ'.strtoupper(substr(uniqid(), -6));

        if ($this->kebijakanId !== null) {
            KebijakanKoperasi::query()->whereKey($this->kebijakanId)->update([
                'tazir_mode' => 'FLAT_HARIAN',
                'financing_late_fee_per_day' => 2500.00,
            ]);
        }

        Setting::flush();
    }

    protected function tearDown(): void
    {
        if ($this->kebijakanAsli !== null && $this->kebijakanId !== null) {
            KebijakanKoperasi::query()->whereKey($this->kebijakanId)->update($this->kebijakanAsli);
        }

        $this->kebijakanAsli = null;
        $this->kebijakanId = null;

        Setting::flush();

        parent::tearDown();
    }

    /* =====================================================================
     | Fixture
     ===================================================================== */

    private function user(string $role, string $penanda): User
    {
        return User::firstOrCreate(
            ['email' => strtolower($role).'.'.$penanda.'@batch14.test'],
            [
                'name' => 'Batch14 '.$role.' '.$penanda,
                'password' => Hash::make('password'),
                'role' => $role,
                'is_active' => true,
            ]
        );
    }

    private function pembiayaan(): Pembiayaan
    {
        $anggota = Anggota::create([
            'nama_lengkap' => 'Anggota '.$this->prefix,
            'no_anggota' => Anggota::nomorAnggotaBaru('ANG-'.date('Ym').'-'),
            'nik' => str_pad((string) random_int(1000000000000000, 9999999999999999), 16, '0'),
            'jenis_kelamin' => 'Laki-laki',
            'no_hp' => '081200000001',
            'alamat' => 'Jl. Uji Batch 14',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_ACTIVE,
        ]);

        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => 'PMB-'.$this->prefix,
            'anggota_id' => $anggota->id,
            'akun_id' => Coa::query()->value('id'),
            'akad_pembiayaan' => 'Qardh',
            'jumlah_pembiayaan' => 1_200_000,
            'total_pembiayaan' => 1_200_000,
            'sisa_pembiayaan' => 1_200_000,
            'tenor' => 12,
            'jumlah_angsuran' => 100_000,
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths(12)->toDateString(),
            'status_pembiayaan' => 'Aktif',
        ]);

        for ($i = 1; $i <= 12; $i++) {
            Angsuran::create([
                'pembiayaan_id' => $pembiayaan->id,
                'angsuran_ke' => $i,
                'tanggal_jatuh_tempo' => now()->addMonths($i)->toDateString(),
                'pokok_angsuran' => 100_000,
                'margin_ujroh' => 0,
                'jumlah_angsuran' => 100_000,
                'sisa_pokok' => 100_000,
                'status_angsuran' => 'Belum Jatuh Tempo',
            ]);
        }

        return $pembiayaan;
    }

    /** Pengajuan keringanan ta'zir baru pada tahap PENDING_AO. */
    private function reliefPendingAo(User $teller): TazirReliefRequest
    {
        $pembiayaan = $this->pembiayaan();
        $angsuran = Angsuran::query()
            ->where('pembiayaan_id', $pembiayaan->id)
            ->orderBy('angsuran_ke')
            ->firstOrFail();

        $angsuran->update(['denda' => 30_000, 'status_tazir' => 'BELUM_LUNAS']);

        return TazirReliefRequest::create([
            'jadwal_angsuran_id' => $angsuran->id,
            'pembiayaan_id' => $pembiayaan->id,
            'anggota_id' => $pembiayaan->anggota_id,
            'nominal_tazir_awal' => 30_000,
            'nominal_tazir_disetujui' => 0,
            'alasan_teller' => 'Anggota sakit, mengajukan keringanan',
            'teller_id' => $teller->id,
            'status' => TazirReliefRequest::STATUS_PENDING_AO,
        ]);
    }

    /* =====================================================================
     | A ? Kontrak dasar jalur AO (perilaku yang harus tetap berlaku)
     ===================================================================== */

    public function test_ao_melihat_dan_merekomendasikan_pengajuan_pending_ao(): void
    {
        $teller = $this->user('teller', 'ao1');
        $ao = $this->user('ao', 'ao1');
        $relief = $this->reliefPendingAo($teller);

        $this->actingAs($ao)
            ->get(route('ao.keringanan-tazir.show', $relief->id))
            ->assertOk();

        $this->actingAs($ao)
            ->post(route('ao.keringanan-tazir.rekomendasi', $relief->id), [
                'rekomendasi_ao' => TazirReliefRequest::REKOMENDASI_SETUJU,
                'catatan_ao' => 'Terverifikasi: anggota menunggak karena sakit.',
            ])
            ->assertRedirect(route('ao.keringanan-tazir.index'))
            ->assertSessionHas('success');

        $relief->refresh();

        $this->assertSame(TazirReliefRequest::STATUS_PENDING_MANAJER, $relief->status);
        $this->assertSame(TazirReliefRequest::REKOMENDASI_SETUJU, $relief->rekomendasi_ao);
        $this->assertSame($ao->id, (int) $relief->ao_id);
        $this->assertSame('Terverifikasi: anggota menunggak karena sakit.', $relief->catatan_ao);
    }

    public function test_rekomendasi_ditolak_bila_pengajuan_sudah_melewati_tahap_ao(): void
    {
        $teller = $this->user('teller', 'ao2');
        $ao = $this->user('ao', 'ao2');
        $relief = $this->reliefPendingAo($teller);

        $relief->update(['status' => TazirReliefRequest::STATUS_PENDING_MANAJER]);

        $this->actingAs($ao)
            ->post(route('ao.keringanan-tazir.rekomendasi', $relief->id), [
                'rekomendasi_ao' => TazirReliefRequest::REKOMENDASI_TOLAK,
                'catatan_ao' => 'Percobaan verifikasi ulang.',
            ])
            ->assertSessionHas('error');

        $relief->refresh();

        $this->assertSame(TazirReliefRequest::STATUS_PENDING_MANAJER, $relief->status);
        $this->assertNull($relief->rekomendasi_ao);
        $this->assertNull($relief->ao_id);
    }

    public function test_rekomendasi_menolak_input_tidak_sah(): void
    {
        $teller = $this->user('teller', 'ao3');
        $ao = $this->user('ao', 'ao3');
        $relief = $this->reliefPendingAo($teller);

        $this->actingAs($ao)
            ->post(route('ao.keringanan-tazir.rekomendasi', $relief->id), [
                'rekomendasi_ao' => 'MUNGKIN',
            ])
            ->assertSessionHasErrors(['rekomendasi_ao', 'catatan_ao']);

        $this->assertSame(TazirReliefRequest::STATUS_PENDING_AO, $relief->fresh()->status);
    }

    /* =====================================================================
     | R-01 ? Pemisahan tugas (maker ? checker) pada verifikasi AO
     |
     | Jalur setara di sistem sudah menerapkan aturan ini:
     | `DepositoPenaltyReliefController::rekomendasi()` memanggil
     | `PemisahanTugas::dilanggar($relief->pengaju_id, Auth::id())`.
     | Jalur ta'zir WAJIB mengikuti aturan yang sama agar satu akun tidak
     | dapat mengajukan lalu memverifikasi pengajuan keringanannya sendiri.
     ===================================================================== */

    public function test_rekomendasi_ditolak_bila_ao_adalah_pengaju_yang_sama(): void
    {
        $ao = $this->user('ao', 'r01a');
        $relief = $this->reliefPendingAo($ao);

        $respons = $this->actingAs($ao)
            ->post(route('ao.keringanan-tazir.rekomendasi', $relief->id), [
                'rekomendasi_ao' => TazirReliefRequest::REKOMENDASI_SETUJU,
                'catatan_ao' => 'Saya mengajukan sekaligus memverifikasi.',
            ]);

        $relief->refresh();

        $this->assertSame(TazirReliefRequest::STATUS_PENDING_AO, $relief->status,
            'Pengajuan yang diajukan sendiri tidak boleh lolos ke Manajer oleh pengaju yang sama.');

        $this->assertNull($relief->ao_id,
            'Rekomendasi AO tidak boleh tercatat pada pengajuan milik pengaju sendiri.');

        $respons->assertSessionHas('error', PemisahanTugas::PESAN);
    }

    public function test_rekomendasi_tetap_diproses_bila_ao_berbeda_dari_pengaju(): void
    {
        $teller = $this->user('teller', 'r01b');
        $ao = $this->user('ao', 'r01b');
        $relief = $this->reliefPendingAo($teller);

        $this->actingAs($ao)
            ->post(route('ao.keringanan-tazir.rekomendasi', $relief->id), [
                'rekomendasi_ao' => TazirReliefRequest::REKOMENDASI_TOLAK,
                'catatan_ao' => 'Terverifikasi; rekomendasi tolak.',
            ])
            ->assertSessionHas('success');

        $relief->refresh();

        $this->assertSame(TazirReliefRequest::STATUS_PENDING_MANAJER, $relief->status);
        $this->assertSame($ao->id, (int) $relief->ao_id);
        $this->assertSame(TazirReliefRequest::REKOMENDASI_TOLAK, $relief->rekomendasi_ao);
    }

    /* =====================================================================
     | A-02 — Rute berparameter terbatas pada peran AO (perilaku terkunci)
     |
     | Rute `ao.keringanan-tazir.show|rekomendasi` dilindungi izin
     | `ao.pembiayaan` yang pemetaannya HANYA `['ao']` (config/izin.php),
     | ditegakkan `CekIzin` ? 403. Coret-coret awal mengira izin itu juga
     | dimiliki Manajer/Pengurus; pengukuran nyata menunjukkan seluruh peran
     | non-AO (teller/manajer/pengurus) mendapat 403. Test ini mengunci
     | perilaku tersebut agar tidak melemah bila pemetaan izin kelak diubah.
     ===================================================================== */

    public function test_peran_selain_ao_tidak_dapat_memproses_tahap_ao(): void
    {
        $teller = $this->user('teller', 'a02');
        $relief = $this->reliefPendingAo($teller);

        $peranLain = [
            'teller' => $teller,
            'manajer' => $this->user('manajer', 'a02'),
            'pengurus' => $this->user('pengurus', 'a02'),
        ];

        foreach ($peranLain as $peran => $pengguna) {
            $this->actingAs($pengguna)
                ->post(route('ao.keringanan-tazir.rekomendasi', $relief->id), [
                    'rekomendasi_ao' => TazirReliefRequest::REKOMENDASI_SETUJU,
                    'catatan_ao' => 'Percobaan memproses tahap AO oleh '.$peran.'.',
                ])
                ->assertForbidden();

            $this->actingAs($pengguna)
                ->get(route('ao.keringanan-tazir.index'))
                ->assertForbidden();
        }

        $relief->refresh();

        $this->assertSame(TazirReliefRequest::STATUS_PENDING_AO, $relief->status);
        $this->assertNull($relief->ao_id, 'Rekomendasi tahap AO tidak boleh diatribusikan kepada peran non-AO.');
        $this->assertNull($relief->rekomendasi_ao);
    }

    /* =====================================================================
     | B-02 — Pendaftaran anggota cepat (ao.anggota.quick-store)
     |
     | Rute ini membuat baris `anggota` dari jalur AO. Dua risiko yang diuji:
     |   1. NIK duplikat — kolom `nik` dienkripsi non-deterministik, sehingga
     |      keunikan HARUS ditegakkan blind index `nik_hash` (NikUnik). Bila
     |      pintu ini bolong, satu orang dapat terdaftar dua kali.
     |   2. Permintaan tidak sah tidak boleh meninggalkan baris anggota yatim.
     ===================================================================== */

    /** Payload sah untuk pendaftaran cepat AO. */
    private function payloadCepat(string $nik): array
    {
        return [
            'nama_lengkap' => 'Anggota Cepat '.$this->prefix,
            'nik' => $nik,
            'jenis_kelamin' => 'Laki-laki',
            'pekerjaan' => 'Karyawan',
            'no_hp' => '081200000002',
            'alamat' => 'Jl. Pendaftaran Cepat',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'pep_status' => Anggota::PEP_PILIHAN[0],
            'foto_ktp' => UploadedFile::fake()->image('ktp.jpg'),
        ];
    }

    public function test_pendaftaran_cepat_menolak_nik_duplikat(): void
    {
        $ao = $this->user('ao', 'b02');
        $nik = str_pad((string) random_int(1000000000000000, 9999999999999999), 16, '0');

        $this->actingAs($ao)
            ->post(route('ao.anggota.quick-store'), $this->payloadCepat($nik))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $idPertama = (int) Anggota::query()->where('nik_hash', PiiHasher::hash($nik))->value('id');
        $this->assertGreaterThan(0, $idPertama, 'Pendaftaran pertama harus menghasilkan satu baris anggota.');

        $this->actingAs($ao)
            ->post(route('ao.anggota.quick-store'), $this->payloadCepat($nik))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nik']);

        $this->assertSame(1, Anggota::query()->withTrashed()->where('nik_hash', PiiHasher::hash($nik))->count(),
            'NIK yang sama tidak boleh menghasilkan dua baris anggota.');

        $this->assertSame($nik, Anggota::query()->find($idPertama)->nik,
            'NIK harus tersimpan terdekripsi dan dapat dibaca kembali tanpa kehilangan digit.');
    }

    /**
     * B-02a - kontrak rute vs skema tabel `anggota`.
     *
     * `kota` dan `provinsi` adalah kolom NOT NULL tanpa default pada tabel
     * `anggota`. Sebelum perbaikan, rute quick-store tidak memvalidasi
     * keduanya sehingga NIK baru yang lolos validasi menabrak QueryException
     * (HTTP 500) dan TIDAK ada anggota yang terbentuk. Pembanding:
     * `PengajuanRekeningController` mengisi `?? '-'`, `CifController`
     * mengambil nilai dari form lengkap.
     *
     * Test ini mengunci hasil yang benar: 200 + satu baris anggota + tepat
     * satu berkas KTP tersimpan.
     */
    public function test_pendaftaran_cepat_mengisi_kolom_wajib_tabel_anggota(): void
    {
        $ao = $this->user('ao', 'b02a');
        $nik = str_pad((string) random_int(1000000000000000, 9999999999999999), 16, '0');
        $sebelum = Anggota::query()->count();

        $this->actingAs($ao)
            ->post(route('ao.anggota.quick-store'), $this->payloadCepat($nik))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame($sebelum + 1, Anggota::query()->count());
        $this->assertCount(1, Storage::disk('dokumen')->files('ktp'),
            'Pendaftaran berhasil menyimpan tepat satu berkas KTP.');
    }

    public function test_pendaftaran_cepat_tidak_meninggalkan_baris_yatim(): void
    {
        $ao = $this->user('ao', 'b02c');
        $sebelum = Anggota::query()->count();

        $payload = $this->payloadCepat('12345');
        unset($payload['foto_ktp']);

        $this->actingAs($ao)
            ->post(route('ao.anggota.quick-store'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nik', 'foto_ktp']);

        $this->assertSame($sebelum, Anggota::query()->count(),
            'Permintaan tidak sah tidak boleh menambah baris anggota.');
        $this->assertSame([], Storage::disk('dokumen')->files('ktp'),
            'Permintaan tidak sah tidak boleh menulis berkas KTP.');
    }
}
