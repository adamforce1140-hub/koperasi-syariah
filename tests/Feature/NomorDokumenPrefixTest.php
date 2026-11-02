<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\KebijakanKoperasi;
use App\Models\MasterProdukPembiayaan;
use App\Models\PengajuanPembiayaan;
use App\Models\User;
use App\Support\NomorDokumen;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PREFIX NOMOR DOKUMEN — awalan dapat diubah Pengurus tanpa deploy.
 *
 * Mengunci tiga hal:
 *   1. Default aman (P4): bila kebijakan belum diisi, nomor yang dihasilkan
 *      IDENTIK dengan awalan lama (TRX/RKN/JRN/BBK/TRM/DEP).
 *   2. Prefix dari form kebijakan benar-benar mengubah nomor dokumen baru.
 *   3. Normalisasi input: huruf kecil → huruf besar, tanda baca dibuang,
 *      maksimal 8 karakter, dan kosong = kembali ke bawaan.
 */
class NomorDokumenPrefixTest extends TestCase
{
    use DatabaseTransactions;

    private User $pengurus;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['kebijakan_koperasis', 'nomor_sequences'] as $tabel) {
            if (! Schema::hasTable($tabel)) {
                $this->markTestSkipped("Tabel {$tabel} belum tersedia.");
            }
        }

        foreach (array_keys(NomorDokumen::BAWAAN) as $jenis) {
            if (! Schema::hasColumn('kebijakan_koperasis', 'prefix_nomor_'.$jenis)) {
                $this->markTestSkipped('Kolom prefix_nomor_* belum dimigrasi.');
            }
        }

        $this->pengurus = User::firstOrCreate(
            ['email' => 'prefix.pengurus@test.local'],
            [
                'name' => 'Uji Prefix Pengurus',
                'password' => Hash::make('password'),
                'role' => 'pengurus',
                'is_active' => true,
            ]
        );

        Setting::flush();
    }

    private function kebijakan(): KebijakanKoperasi
    {
        return KebijakanKoperasi::first() ?? KebijakanKoperasi::create([]);
    }

    /** Muatan form lengkap (field wajib) untuk menembak rute update sungguhan. */
    private function muatanKebijakan(array $override = []): array
    {
        $kebijakan = $this->kebijakan();

        return array_merge([
            'limit_manajer' => (string) $kebijakan->limit_manajer,
            'tazir_mode' => $kebijakan->tazir_mode ?: 'FLAT_HARIAN',
            'tazir_rate' => (string) ($kebijakan->tazir_rate ?? 0),
            'tazir_grace_period_days' => (string) ($kebijakan->tazir_grace_period_days ?? 0),
            'tazir_default_method' => $kebijakan->tazir_default_method ?: 'TUNAI',
            'mandatory_principal_savings' => (string) $kebijakan->mandatory_principal_savings,
            'mandatory_monthly_savings' => (string) $kebijakan->mandatory_monthly_savings,
            'minimum_pembukaan_rekening' => (string) $kebijakan->minimum_pembukaan_rekening,
            'maksimum_pembukaan_rekening' => (string) $kebijakan->maksimum_pembukaan_rekening,
            'nama_koperasi' => (string) $kebijakan->nama_koperasi,
            'shu_pct_cadangan_modal' => (string) Setting::get('shu_pct_cadangan_modal', 30),
            'shu_pct_jasa_anggota' => (string) Setting::get('shu_pct_jasa_anggota', 40),
            'shu_pct_jasa_modal' => (string) Setting::get('shu_pct_jasa_modal', 20),
            'shu_pct_dana_pendidikan' => (string) Setting::get('shu_pct_dana_pendidikan', 10),
            'limit_approval_beban' => (string) (int) Setting::limitApprovalBeban(),
            'kolektibilitas_kol2_hari' => (string) Setting::ambangKolektibilitas()['kol2'],
            'kolektibilitas_kol3_hari' => (string) Setting::ambangKolektibilitas()['kol3'],
            'kolektibilitas_kol4_hari' => (string) Setting::ambangKolektibilitas()['kol4'],
        ], $override);
    }

    /* ══════════════════════════════════════════════════════════════════
     |  1. DEFAULT AMAN
     ══════════════════════════════════════════════════════════════════ */

    public function test_tanpa_prefix_kebijakan_memakai_awalan_bawaan(): void
    {
        foreach (NomorDokumen::BAWAAN as $jenis => $bawaan) {
            $this->assertSame($bawaan, NomorDokumen::kode($jenis), "Awalan bawaan `{$jenis}` berubah.");
        }

        $this->assertSame('TRX-2026-10-21-', NomorDokumen::prefix('transaksi', '2026-10-21'));
        $this->assertSame('RKN-2026-', NomorDokumen::prefix('rekening', tanggal: 'Y'));
    }

    /* ══════════════════════════════════════════════════════════════════
     |  2. PREFIX DINAMIS
     ══════════════════════════════════════════════════════════════════ */

    public function test_prefix_tersimpan_dan_dipakai_generator(): void
    {
        $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), $this->muatanKebijakan([
                'prefix_nomor_transaksi' => 'KSP',
                'prefix_nomor_jurnal' => 'JKK',
            ]))
            ->assertSessionHasNoErrors();

        Setting::flush();

        $this->assertSame('KSP', NomorDokumen::kode('transaksi'));
        $this->assertSame('JKK', NomorDokumen::kode('jurnal'));
        $this->assertSame('KSP-2026-10-21-', NomorDokumen::prefix('transaksi', '2026-10-21'));
        $this->assertSame('JKK-20261021-', NomorDokumen::prefix('jurnal', '20261021'));

        // Jenis lain yang tidak dikirim tetap memakai bawaan (P4 Default Aman).
        $this->assertSame('DEP', NomorDokumen::kode('deposito'));
    }

    public function test_nomor_transaksi_baru_memakai_prefix_kustom(): void
    {
        $kebijakan = $this->kebijakan();
        $kebijakan->prefix_nomor_transaksi = 'KSP';
        $kebijakan->save();
        Setting::flush();

        $nomor = DB::transaction(
            fn () => NomorDokumen::buat('transaksi', periode: '20261021')
        );

        $this->assertMatchesRegularExpression('/^KSP-20261021-\d{6}$/', $nomor);
    }

    /* ══════════════════════════════════════════════════════════════════
     |  3. NORMALISASI INPUT
     ══════════════════════════════════════════════════════════════════ */

    public function test_input_dinormalkan_menjadi_huruf_besar(): void
    {
        $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), $this->muatanKebijakan([
                'prefix_nomor_transaksi' => 'ksp',
                'prefix_nomor_deposito' => ' d1 ',
            ]))
            ->assertSessionHasNoErrors();

        Setting::flush();

        $kebijakan = $this->kebijakan();

        $this->assertSame('KSP', $kebijakan->prefix_nomor_transaksi);
        $this->assertSame('D1', $kebijakan->prefix_nomor_deposito);
    }

    public function test_field_kosong_mengembalikan_awalan_bawaan(): void
    {
        $kebijakan = $this->kebijakan();
        $kebijakan->prefix_nomor_terminasi = 'TMX';
        $kebijakan->save();
        Setting::flush();

        $this->assertSame('TMX', NomorDokumen::kode('terminasi'));

        $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), $this->muatanKebijakan([
                'prefix_nomor_terminasi' => '',
            ]))
            ->assertSessionHasNoErrors();

        Setting::flush();

        $this->assertNull($this->kebijakan()->prefix_nomor_terminasi);
        $this->assertSame('TRM', NomorDokumen::kode('terminasi'));
    }

    public function test_prefix_tidak_valid_ditolak(): void
    {
        foreach (['KSP-1', 'KSP SYARIAH', 'KOPERASIX'] as $tidakValid) {
            $this->actingAs($this->pengurus)
                ->put(route('pengurus.kebijakan.update'), $this->muatanKebijakan([
                    'prefix_nomor_rekening' => $tidakValid,
                ]))
                ->assertSessionHasErrors(['prefix_nomor_rekening']);
        }
    }

    /* ══════════════════════════════════════════════════════════════════
     |  4. GENERATOR NOMOR PENGAJUAN PEMBIAYAAN
     ══════════════════════════════════════════════════════════════════ */

    /**
     * Regresi: `PengajuanPembiayaan` TIDAK memakai SoftDeletes, sehingga
     * `withTrashed()` melempar BadMethodCallException dan SELURUH pembuatan
     * pengajuan pembiayaan baru gagal 500. Generator wajib berjalan dan
     * menghasilkan urutan numerik berikutnya.
     */
    public function test_nomor_pengajuan_baru_tidak_memakai_with_trashed(): void
    {
        if (! Schema::hasTable('pengajuan_pembiayaan')) {
            $this->markTestSkipped('Tabel pengajuan_pembiayaan belum tersedia.');
        }

        $prefix = 'UJI-'.now()->format('Ymd').'-';

        $pertama = PengajuanPembiayaan::nomorPengajuanBaru($prefix);
        $this->assertSame($prefix.'0001', $pertama);

        $kedua = PengajuanPembiayaan::nomorPengajuanBaru($prefix);
        $this->assertSame($prefix.'0001', $kedua, 'Tanpa baris tersimpan, nomor boleh sama.');

        // Menyimpan satu baris membuat urutan berikutnya bergeser.
        PengajuanPembiayaan::create([
            'no_pengajuan' => $pertama,
            'anggota_id' => Anggota::query()->value('id'),
            'produk_id' => MasterProdukPembiayaan::query()->value('id'),
            'jenis_akad' => 'Murabahah',
            'margin_persen' => 12,
            'jumlah_pengajuan' => 1_000_000,
            'total_pembiayaan' => 1_120_000,
            'angsuran_per_bulan' => 93_333.33,
            'tenor' => 12,
            'tujuan' => 'Uji nomor pengajuan',
            'status' => PengajuanPembiayaan::STATUS_MENUNGGU,
        ]);

        $this->assertSame($prefix.'0002', PengajuanPembiayaan::nomorPengajuanBaru($prefix));
    }
}
