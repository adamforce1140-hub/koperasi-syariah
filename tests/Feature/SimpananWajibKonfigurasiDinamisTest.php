<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\KebijakanKoperasi;
use App\Models\MandatorySavingsBill;
use App\Models\User;
use App\Services\MandatorySavingsService;
use App\Support\Coa as KodeCoa;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Konfigurasi DINAMIS modul Simpanan Wajib (Tahap 12 / R1 — PSAK 101).
 *
 * Kontrak yang dikunci:
 *  1. Default aman (P4): tanpa perubahan kebijakan, perilaku sistem identik
 *     dengan sebelumnya (akun 3-1002, jatuh tempo tanggal 10, penarikan tertutup).
 *  2. Titik resolusi tunggal `MandatorySavingsService::akunSimpananWajib()`
 *     menghormati `kebijakan_koperasis.account_simpanan_wajib_id` yang diisi
 *     Pengurus dari UI — TANPA mengubah kode inti.
 *  3. Validasi rentang parameter (1–28, whitelist kanal, whitelist waktu penarikan)
 *     menolak nilai tidak sah sebelum tersimpan.
 *  4. Jalur Teller memakai akun hasil resolusi, bukan konstanta kelas.
 */
class SimpananWajibKonfigurasiDinamisTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'CFGWJB'.strtoupper(substr(uniqid(), -6));

        // getSetting() tanpa baris DB mengembalikan objek belum tersimpan;
        // update() pada objek itu tidak menulis apa pun. Pastikan baris ada
        // (rollback otomatis oleh DatabaseTransactions).
        if (! KebijakanKoperasi::query()->exists()) {
            (new KebijakanKoperasi)->save();
            Setting::flush();
        }
    }

    protected function tearDown(): void
    {
        Setting::flush();

        parent::tearDown();
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    private function user(string $role = 'pengurus'): User
    {
        return User::firstOrCreate(
            ['email' => "{$role}.{$this->prefix}@test.local"],
            [
                'name' => ucfirst($role).' '.$this->prefix,
                'password' => Hash::make('password'),
                'role' => $role,
            ]
        );
    }

    private function pastikanCoa(): void
    {
        foreach ([KodeCoa::KAS_TELLER, KodeCoa::SIMPANAN_WAJIB_MODAL, KodeCoa::SIMPANAN_SUKARELA] as $kode) {
            if (! Coa::findByKode($kode)) {
                $this->markTestSkipped("CoA {$kode} belum tersedia — jalankan CoaSeeder.");
            }
        }
    }

    private function anggota(): Anggota
    {
        return Anggota::create([
            'no_anggota' => 'AGT-'.$this->prefix,
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Konfigurasi '.$this->prefix,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Alamat Uji Konfigurasi Simpanan Wajib',
            'kota' => 'Jakarta',
            'provinsi' => 'DKI Jakarta',
            'status_anggota' => 'ACTIVE',
            'is_auto_debit' => false,
            'tanggal_gabung' => '2026-01-01',
        ]);
    }

    private function tagihan(Anggota $anggota, string $periode = '2026-02', float $nominal = 50000): MandatorySavingsBill
    {
        return MandatorySavingsBill::create([
            'member_id' => $anggota->id,
            'bill_period' => $periode,
            'amount' => $nominal,
            'status' => MandatorySavingsBill::STATUS_UNPAID,
        ]);
    }

    /** Payload minimal yang selalu lolos validasi `updateKebijakan()`. */
    private function payloadDasar(): array
    {
        $kebijakan = KebijakanKoperasi::getSetting();

        return [
            'limit_manajer' => (string) ($kebijakan->limit_manajer ?: 1000000),
            'tazir_mode' => $kebijakan->tazir_mode ?: 'DISABLED',
            'tazir_rate' => (string) ($kebijakan->tazir_rate ?? 0),
            'tazir_grace_period_days' => (string) ($kebijakan->tazir_grace_period_days ?? 0),
            'tazir_default_method' => $kebijakan->tazir_default_method ?: 'TUNAI',
            'mandatory_principal_savings' => (string) ($kebijakan->mandatory_principal_savings ?? 100000),
            'mandatory_monthly_savings' => (string) ($kebijakan->mandatory_monthly_savings ?? 50000),
            'minimum_pembukaan_rekening' => (string) ($kebijakan->minimum_pembukaan_rekening ?? 50000),
            'maksimum_pembukaan_rekening' => (string) ($kebijakan->maksimum_pembukaan_rekening ?? 10000000),
        ];
    }

    private function service(): MandatorySavingsService
    {
        return app(MandatorySavingsService::class);
    }

    /* =====================================================================
     | 1. P4 DEFAULT AMAN — perilaku tidak berubah tanpa konfigurasi
     |===================================================================== */

    public function test_default_aman_mengikuti_akun_kanonik_standar(): void
    {
        $this->pastikanCoa();

        $kebijakan = KebijakanKoperasi::getSetting();
        $kebijakan->update(['account_simpanan_wajib_id' => null]);
        Setting::flush();

        $this->assertNull(
            Setting::akunSimpananWajibId(),
            'Bawaan (default) `account_simpanan_wajib_id` harus NULL.'
        );

        $this->assertSame(
            KodeCoa::SIMPANAN_WAJIB_MODAL,
            $this->service()->akunSimpananWajib(),
            'Tanpa konfigurasi, akun Simpanan Wajib harus jatuh ke akun kanonik standar (3-1002).'
        );
    }

    public function test_default_aman_jatuh_tempo_dan_penarikan(): void
    {
        // Jatuh tempo kini OTOMATIS (anniversary bulanan sejak bergabung),
        // bukan lagi parameter kebijakan. Tidak ada accessor konfigurasi.
        $this->assertFalse(
            method_exists(Setting::class, 'simpananWajibOverdueDay'),
            'Setting::simpananWajibOverdueDay() harus sudah dihapus (jatuh tempo otomatis).'
        );
        $this->assertFalse($this->service()->penarikanDiizinkan(), 'Penarikan default = tertutup.');
        $this->assertSame('AUTO_DEBIT', Setting::simpananWajibKanalDefault());
        $this->assertSame('SETELAH_KELUAR', Setting::simpananWajibHariPenarikan());
    }

    /* =====================================================================
     | 2. TITIK RESOLUSI TUNGGAL — menghormati konfigurasi Pengurus
     |===================================================================== */

    public function test_resolusi_akun_mengikuti_konfigurasi_kebijakan(): void
    {
        $this->pastikanCoa();

        // Akun alternatif yang aktif: pakai CoA Simpanan Sukarela sebagai
        // "akun uji" supaya tidak bergantung pada akun tambahan apa pun.
        $alternatif = Coa::findByKode(KodeCoa::SIMPANAN_SUKARELA);

        $kebijakan = KebijakanKoperasi::getSetting();
        $kebijakan->update(['account_simpanan_wajib_id' => $alternatif->id]);
        Setting::flush();

        $this->assertSame($alternatif->id, Setting::akunSimpananWajibId());
        $this->assertSame(
            $alternatif->kode_akun,
            $this->service()->akunSimpananWajib(),
            'Akun Simpanan Wajib harus mengikuti konfigurasi kebijakan yang aktif.'
        );

        // Bersihkan agar test lain tidak terpengaruh (kebijakan tidak di-rollback
        // oleh DatabaseTransactions bila baris dibuat di luar transaksi).
        $kebijakan->update(['account_simpanan_wajib_id' => null]);
        Setting::flush();
    }

    public function test_resolusi_akun_mengabaikan_coa_nonaktif(): void
    {
        $this->pastikanCoa();

        $nonaktif = Coa::findByKode(KodeCoa::SIMPANAN_SUKARELA);
        $kebijakan = KebijakanKoperasi::getSetting();
        $kebijakan->update(['account_simpanan_wajib_id' => $nonaktif->id]);
        Setting::flush();

        // Nonaktifkan sementara di dalam transaksi — otomatis di-rollback.
        DB::table('coas')->where('id', $nonaktif->id)->update(['is_active' => false]);
        Setting::flush();

        $this->assertSame(
            KodeCoa::SIMPANAN_WAJIB_MODAL,
            $this->service()->akunSimpananWajib(),
            'CoA nonaktif harus ditolak dan sistem kembali ke akun kanonik standar.'
        );

        DB::table('coas')->where('id', $nonaktif->id)->update(['is_active' => true]);
        $kebijakan->update(['account_simpanan_wajib_id' => null]);
        Setting::flush();
    }

    /* =====================================================================
     | 3. VALIDASI AKUNTANSI / RENTANG PARAMETER
     |===================================================================== */

    public function test_parameter_jatuh_tempo_lama_sudah_dihapus(): void
    {
        $this->pastikanCoa();

        // Field lama tidak lagi dikenal: dikirim pun diabaikan (tidak ada error,
        // tidak tersimpan) karena jatuh tempo kini dihitung otomatis.
        $this->actingAs($this->user())
            ->put(route('pengurus.kebijakan.update'), array_merge($this->payloadDasar(), [
                'simpanan_wajib_overdue_day' => 15,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertFalse(
            Schema::hasColumn('kebijakan_koperasis', 'simpanan_wajib_overdue_day'),
            'Kolom simpanan_wajib_overdue_day harus sudah dihapus dari kebijakan_koperasis.'
        );
    }

    public function test_validasi_menolak_kanal_dan_waktu_penarikan_tidak_dikenal(): void
    {
        $this->pastikanCoa();

        $this->actingAs($this->user())
            ->put(route('pengurus.kebijakan.update'), array_merge($this->payloadDasar(), [
                'simpanan_wajib_kanal_default' => 'BITCOIN',
                'simpanan_wajib_hari_penarikan' => 'BEBAS',
            ]))
            ->assertSessionHasErrors(['simpanan_wajib_kanal_default', 'simpanan_wajib_hari_penarikan']);
    }

    public function test_validasi_menolak_akun_simpanan_wajib_tidak_dikenal(): void
    {
        $this->pastikanCoa();

        $this->actingAs($this->user())
            ->put(route('pengurus.kebijakan.update'), array_merge($this->payloadDasar(), [
                'account_simpanan_wajib_id' => 999999999,
            ]))
            ->assertSessionHasErrors('account_simpanan_wajib_id');
    }

    /* =====================================================================
     | 4. PERSISTENSI — konfigurasi dinamis tersimpan & berlaku
     |===================================================================== */

    public function test_pengurus_dapat_menyimpan_parameter_simpanan_wajib(): void
    {
        $this->pastikanCoa();

        $alternatif = Coa::findByKode(KodeCoa::SIMPANAN_SUKARELA);

        $this->actingAs($this->user())
            ->put(route('pengurus.kebijakan.update'), array_merge($this->payloadDasar(), [
                'simpanan_wajib_kanal_default' => 'CASH',
                'simpanan_wajib_boleh_ditarik' => 1,
                'simpanan_wajib_hari_penarikan' => 'KAPAN_SAJA',
                'account_simpanan_wajib_id' => $alternatif->id,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Setting::flush();

        $this->assertSame('CASH', Setting::simpananWajibKanalDefault());
        $this->assertTrue(Setting::simpananWajibBolehDitarik());
        $this->assertSame('KAPAN_SAJA', Setting::simpananWajibHariPenarikan());
        $this->assertSame($alternatif->id, Setting::akunSimpananWajibId());
        $this->assertSame($alternatif->kode_akun, $this->service()->akunSimpananWajib());

        // Kembalikan ke bawaan.
        $this->actingAs($this->user())
            ->put(route('pengurus.kebijakan.update'), array_merge($this->payloadDasar(), [
                'simpanan_wajib_kanal_default' => 'AUTO_DEBIT',
                'simpanan_wajib_boleh_ditarik' => 0,
                'simpanan_wajib_hari_penarikan' => 'SETELAH_KELUAR',
                'account_simpanan_wajib_id' => '',
            ]))
            ->assertRedirect();

        Setting::flush();
        $this->assertNull(Setting::akunSimpananWajibId(), 'Field kosong = kembali ke akun standar.');
    }

    /* =====================================================================
     | 5. JALUR JURNAL MEMAKAI AKUN HASIL RESOLUSI (PSAK 101)
     |===================================================================== */

    public function test_pelunasan_teller_memakai_akun_hasil_resolusi(): void
    {
        $this->pastikanCoa();

        $alternatif = Coa::findByKode(KodeCoa::SIMPANAN_SUKARELA);
        $kebijakan = KebijakanKoperasi::getSetting();
        $kebijakan->update(['account_simpanan_wajib_id' => $alternatif->id]);
        Setting::flush();

        $anggota = $this->anggota();
        $tagihan = $this->tagihan($anggota, '2026-02', 60000);

        $this->service()->bayarViaTeller(
            $tagihan,
            MandatorySavingsBill::METODE_CASH,
            null,
            $this->user('teller')->id
        );

        $tagihan->refresh();
        $this->assertNotNull($tagihan->jurnal_id, 'Pelunasan wajib memposting jurnal.');

        $jurnal = Jurnal::with('detail')->findOrFail($tagihan->jurnal_id);

        $this->assertEqualsWithDelta(
            60000,
            (float) $jurnal->detail->sum('debet'),
            0.01,
            'Total DEBET harus sama dengan nominal tagihan.'
        );
        $this->assertEqualsWithDelta(
            (float) $jurnal->detail->sum('debet'),
            (float) $jurnal->detail->sum('kredit'),
            0.01,
            'Jurnal PSAK 101 harus seimbang (DEBET = KREDIT).'
        );

        $this->assertTrue(
            DB::table('jurnal_detail')->where('jurnal_id', $jurnal->id)->where('coa_id', $alternatif->id)->exists(),
            'Sisi KREDIT harus memakai akun hasil resolusi kebijakan, bukan konstanta kelas.'
        );

        $kebijakan->update(['account_simpanan_wajib_id' => null]);
        Setting::flush();
    }

    /* =====================================================================
     | 6. JAM POSTING AUTO-DEBIT DINAMIS (DYN-17)
     |===================================================================== */

    public function test_jam_posting_mengikuti_kebijakan_dan_menjaga_nilai_aman(): void
    {
        $this->assertSame(
            '00:01',
            Setting::jamPostingSimpananWajib(),
            'Bawaan jam posting auto-debit Simpanan Wajib harus 00:01.'
        );

        $kebijakan = KebijakanKoperasi::getSetting();
        $kebijakan->update(['jam_posting_simpanan_wajib' => '08:30']);
        Setting::flush();

        $this->assertSame(
            '08:30',
            Setting::jamPostingSimpananWajib(),
            'Jam posting harus mengikuti kebijakan yang disimpan Pengurus.'
        );

        // Nilai rusak (format salah / tidak mungkin) TIDAK boleh membuat
        // `setTimeFromTimeString()` melempar exception saat posting.
        foreach (['25:00', '8:30', 'jam', ''] as $rusak) {
            $kebijakan->update(['jam_posting_simpanan_wajib' => $rusak]);
            Setting::flush();

            $this->assertSame(
                '00:01',
                Setting::jamPostingSimpananWajib(),
                "Nilai jam posting \"{$rusak}\" harus jatuh ke 00:01."
            );
        }

        $kebijakan->update(['jam_posting_simpanan_wajib' => null]);
        Setting::flush();
    }

    public function test_validasi_menolak_format_jam_posting_tidak_sah(): void
    {
        $this->pastikanCoa();

        $this->actingAs($this->user())
            ->put(route('pengurus.kebijakan.update'), array_merge($this->payloadDasar(), [
                'jam_posting_simpanan_wajib' => '25:99',
            ]))
            ->assertSessionHasErrors('jam_posting_simpanan_wajib');

        Setting::flush();
        $this->assertSame('00:01', Setting::jamPostingSimpananWajib(), 'Nilai tidak sah tidak boleh tersimpan.');
    }
}
