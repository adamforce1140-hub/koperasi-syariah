<?php

namespace Tests\Feature;

use App\Exceptions\JournalPostingException;
use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\User;
use App\Services\AutoJournalingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Regresi alur keamanan posting jurnal (R2):
 *
 *  A. pastikanBalance() di jalur produksi — AutoJournalingService::post() wajib
 *     membuktikan header jurnal didukung baris detail yang seimbang, dan
 *     transaksi pemanggil harus ikut ter-rollback bila bukti itu gagal.
 *  B. Command `jurnal:deteksi-orphan` — mendeteksi 7 kategori orphan dengan
 *     laporan yang jelas, tidak mengubah data, dan mengembalikan exit code
 *     FAILURE saat menemukan orphan.
 *  C. Pencegahan orphan di API JurnalController — jurnal Posted tanpa detail
 *     ditolak (422), Draft tanpa detail tetap boleh, dan post() tanpa detail
 *     ditolak.
 *
 * Memakai DatabaseTransactions agar setiap test mulai dari kondisi bersih dan
 * tidak mengotori basis data pengembangan.
 */
class JurnalOrphanDanBalanceTest extends TestCase
{
    use DatabaseTransactions;

    /* ==================================================================
     | FIXTURE
     ================================================================== */

    /**
     * Endpoint API jurnal kini WAJIB terautentikasi (RBAC). Test ini
     * memakai peran `admin` — peran akuntansi yang berwenang menulis jurnal.
     */
    private function loginSebagaiAdmin(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'uji.orphan.admin@test.local'],
            [
                'name' => 'Uji Orphan Admin',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        $this->actingAs($admin);
    }

    private function akun(string $kode, string $nama): Coa
    {
        $kepala = substr($kode, 0, 1);

        [$kategori, $klasifikasi, $tipe] = match ($kepala) {
            '1' => ['Aset', 'Aset Lancar', 'Debet'],
            '2' => ['Kewajiban', 'Kewajiban Jangka Pendek', 'Kredit'],
            '3' => ['Ekuitas', 'Saldo Laba / SHU', 'Kredit'],
            '4' => ['Pendapatan', 'Pendapatan', 'Kredit'],
            default => ['Beban', 'Beban Operasional', 'Debet'],
        };

        return Coa::updateOrCreate(
            ['kode_akun' => $kode],
            [
                'nama_akun' => $nama,
                'kategori' => $kategori,
                'klasifikasi_neraca' => $klasifikasi,
                'tipe_saldo_normal' => $tipe,
                'saldo_awal' => 0,
                'is_active' => true,
            ]
        );
    }

    /** Header jurnal Posted tanpa baris detail — bahan uji orphan. */
    private function jurnalKosong(string $nomor, string $sumber = 'UJI-ORPHAN'): Jurnal
    {
        return Jurnal::create([
            'no_jurnal' => $nomor,
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => $sumber,
            'keterangan' => 'Uji deteksi orphan',
            'total_debet' => 0,
            'total_kredit' => 0,
            'is_balance' => true,
            'status' => 'Posted',
            'posted_at' => now(),
        ]);
    }

    /* ==================================================================
     | A. pastikanBalance() DI JALUR PRODUKSI
     ================================================================== */

    public function test_posting_otomatis_menyimpan_jurnal_balance_dan_menyelaraskan_header(): void
    {
        $kas = $this->akun('1-9101', 'Kas Uji Posting Otomatis');
        $pendapatan = $this->akun('4-9101', 'Pendapatan Uji Posting Otomatis');

        $jurnal = app(AutoJournalingService::class)->post(
            'Uji posting otomatis balance',
            [
                ['kode' => $kas->kode_akun, 'debet' => 250_000, 'kredit' => 0],
                ['kode' => $pendapatan->kode_akun, 'debet' => 0, 'kredit' => 250_000],
            ],
            'UJI'
        );

        $this->assertNotNull($jurnal, 'Jurnal seimbang harus berhasil diposting.');
        $this->assertSame('Posted', $jurnal->status);
        $this->assertTrue((bool) $jurnal->is_balance);

        // Header wajib diselaraskan dari baris detail yang benar-benar tersimpan.
        $this->assertEqualsWithDelta(250_000, (float) $jurnal->fresh()->total_debet, 0.01);
        $this->assertEqualsWithDelta(250_000, (float) $jurnal->fresh()->total_kredit, 0.01);
        $this->assertSame(2, $jurnal->detail()->count());
    }

    public function test_posting_otomatis_membatalkan_seluruh_jurnal_bila_akun_tidak_ditemukan(): void
    {
        $kas = $this->akun('1-9102', 'Kas Uji Batal Otomatis');

        // Sejak penguatan integritas: kegagalan validasi MELEMPAR exception
        // (bukan lagi mengembalikan null) agar DB::transaction pemanggil
        // rollback. Tidak ada jurnal setengah jadi yang lolos ke buku besar.
        $this->expectException(JournalPostingException::class);

        app(AutoJournalingService::class)->post(
            'Uji posting otomatis dengan akun tidak dikenal',
            [
                ['kode' => $kas->kode_akun, 'debet' => 100_000, 'kredit' => 0],
                ['kode' => '9-9999', 'debet' => 0, 'kredit' => 100_000],
            ],
            'UJI'
        );
    }

    public function test_pastikan_balance_menolak_header_posted_tanpa_detail_dan_rollback_transaksi(): void
    {
        $nomor = 'UJI-RB-'.uniqid();

        try {
            DB::transaction(function () use ($nomor) {
                $jurnal = $this->jurnalKosong($nomor);

                // Bukti akhir wajib gagal: header Posted tanpa baris detail.
                $jurnal->pastikanBalance();
            });

            $this->fail('pastikanBalance() seharusnya melempar RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tidak memiliki baris detail', $e->getMessage());
        }

        $this->assertNull(
            Jurnal::where('no_jurnal', $nomor)->first(),
            'Transaksi pemanggil harus ter-rollback sehingga jurnal kosong tidak tersimpan.'
        );
    }

    public function test_pastikan_balance_menyelaraskan_header_dari_baris_detail_tersimpan(): void
    {
        $kas = $this->akun('1-9103', 'Kas Uji Selaras Header');

        // Header sengaja diisi angka yang salah; pastikanBalance() wajib
        // memperbaikinya dari baris detail, bukan dari angka memori.
        $jurnal = Jurnal::create([
            'no_jurnal' => 'UJI-SEL-'.uniqid(),
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'UJI',
            'keterangan' => 'Uji penyelarasan header',
            'total_debet' => 9_999_999,
            'total_kredit' => 9_999_999,
            'is_balance' => false,
            'status' => 'Posted',
            'posted_at' => now(),
        ]);

        JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $kas->id, 'baris' => 1, 'debet' => 400_000, 'kredit' => 0]);
        JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $kas->id, 'baris' => 2, 'debet' => 0, 'kredit' => 400_000]);

        $jurnal->pastikanBalance();

        $this->assertEqualsWithDelta(400_000, (float) $jurnal->fresh()->total_debet, 0.01);
        $this->assertEqualsWithDelta(400_000, (float) $jurnal->fresh()->total_kredit, 0.01);
        $this->assertTrue((bool) $jurnal->fresh()->is_balance);
    }

    /* ==================================================================
     | B. COMMAND DETEKSI ORPHAN
     ================================================================== */

    public function test_command_orphan_melaporkan_seluruh_kategori(): void
    {
        $this->artisan('jurnal:deteksi-orphan')
            ->expectsOutputToContain('PEMERIKSAAN JURNAL ORPHAN')
            ->expectsOutputToContain('JURNAL_KOSONG')
            ->expectsOutputToContain('JURNAL_TIMBANG')
            ->expectsOutputToContain('HEADER_TIDAK_SINKRON')
            ->expectsOutputToContain('DETAIL_TANPA_INDUK')
            ->expectsOutputToContain('DETAIL_TANPA_COA')
            ->expectsOutputToContain('DETAIL_KOSONG')
            ->expectsOutputToContain('DETAIL_DUA_SISI');
    }

    public function test_command_orphan_mendeteksi_header_tanpa_detail(): void
    {
        $nomor = 'UJI-ORK-'.uniqid();
        $this->jurnalKosong($nomor);

        $this->artisan('jurnal:deteksi-orphan', ['--contoh' => 5])
            ->expectsOutputToContain('JURNAL_KOSONG')
            ->expectsOutputToContain($nomor)
            ->assertExitCode(1);
    }

    public function test_command_orphan_mendeteksi_detail_terputus_dari_induk_terhapus(): void
    {
        $kas = $this->akun('1-9104', 'Kas Uji Detail Yatim');
        $jurnal = $this->jurnalKosong('UJI-YATIM-'.uniqid());

        JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $kas->id, 'baris' => 1, 'debet' => 75_000, 'kredit' => 0]);

        // Induk di-soft-delete: baris detailnya tetap ada tetapi terputus
        // dari jurnal yang masih aktif — inilah orphan yang dicari.
        $jurnal->delete();

        $this->artisan('jurnal:deteksi-orphan', ['--contoh' => 5])
            ->expectsOutputToContain('DETAIL_TANPA_INDUK')
            ->assertExitCode(1);
    }

    public function test_command_orphan_mendeteksi_header_tidak_sinkron(): void
    {
        $kas = $this->akun('1-9105', 'Kas Uji Header Tidak Sinkron');
        $jurnal = $this->jurnalKosong('UJI-TIDAKSINKRON-'.uniqid());

        JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $kas->id, 'baris' => 1, 'debet' => 500_000, 'kredit' => 0]);

        // Paksa header menyimpan angka yang tidak sesuai baris detail.
        DB::table('jurnal')->where('id', $jurnal->id)->update([
            'total_debet' => 123_456,
            'total_kredit' => 0,
            'is_balance' => false,
        ]);

        $this->artisan('jurnal:deteksi-orphan', ['--contoh' => 5])
            ->expectsOutputToContain('HEADER_TIDAK_SINKRON')
            ->assertExitCode(1);
    }

    public function test_command_orphan_mendeteksi_baris_detail_hampa_dan_dua_sisi(): void
    {
        $kas = $this->akun('1-9106', 'Kas Uji Baris Tidak Sah');
        $jurnal = $this->jurnalKosong('UJI-BARIS-'.uniqid());

        JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $kas->id, 'baris' => 1, 'debet' => 0, 'kredit' => 0]);
        JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $kas->id, 'baris' => 2, 'debet' => 10_000, 'kredit' => 10_000]);

        $this->artisan('jurnal:deteksi-orphan', ['--contoh' => 5])
            ->expectsOutputToContain('DETAIL_KOSONG')
            ->expectsOutputToContain('DETAIL_DUA_SISI')
            ->assertExitCode(1);
    }

    public function test_command_orphan_mendeteksi_detail_tanpa_coa_valid(): void
    {
        $jurnal = $this->jurnalKosong('UJI-TANPACOA-'.uniqid());

        JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => null, 'baris' => 1, 'debet' => 30_000, 'kredit' => 0]);

        $this->artisan('jurnal:deteksi-orphan', ['--contoh' => 5])
            ->expectsOutputToContain('DETAIL_TANPA_COA')
            ->assertExitCode(1);
    }

    public function test_command_orphan_tidak_mengubah_data(): void
    {
        $nomor = 'UJI-READONLY-'.uniqid();
        $jurnal = $this->jurnalKosong($nomor);

        $jurnalSebelum = Jurnal::count();
        $detailSebelum = JurnalDetail::count();

        $this->artisan('jurnal:deteksi-orphan')->assertExitCode(1);

        $this->assertSame($jurnalSebelum, Jurnal::count(), 'Deteksi orphan tidak boleh menghapus/menambah jurnal.');
        $this->assertSame($detailSebelum, JurnalDetail::count(), 'Deteksi orphan tidak boleh menyentuh baris detail.');
        $this->assertNotNull($jurnal->fresh(), 'Jurnal yang dilaporkan harus tetap ada — command bersifat baca-saja.');
    }

    public function test_command_orphan_melihat_bagian_yang_tidak_tertangkap_oleh_sql_mentah(): void
    {
        $nomor = 'UJI-TIMBANG-'.uniqid();

        // Jurnal dengan baris detail yang tidak balance (debet 100rb, kredit 50rb).
        $kas = $this->akun('1-9107', 'Kas Uji Timbang');
        $jurnal = Jurnal::create([
            'no_jurnal' => $nomor,
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'UJI-ORPHAN',
            'keterangan' => 'Uji jurnal timbang',
            'total_debet' => 100_000,
            'total_kredit' => 100_000,
            'is_balance' => true,
            'status' => 'Posted',
            'posted_at' => now(),
        ]);

        JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $kas->id, 'baris' => 1, 'debet' => 100_000, 'kredit' => 0]);
        JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $kas->id, 'baris' => 2, 'debet' => 0, 'kredit' => 50_000]);

        $this->artisan('jurnal:deteksi-orphan', ['--contoh' => 5])
            ->expectsOutputToContain('JURNAL_TIMBANG')
            ->assertExitCode(1);
    }

    /* ==================================================================
     | C. PENCEGAHAN ORPHAN DI API JURNAL
     ================================================================== */

    public function test_api_menolak_membuat_jurnal_langsung_berstatus_posted(): void
    {
        $this->loginSebagaiAdmin();

        $response = $this->postJson('/api/jurnal', [
            'no_jurnal' => 'UJI-API-'.uniqid(),
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'keterangan' => 'Uji pembuatan jurnal Posted tanpa detail',
            'status' => 'Posted',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertStringContainsString('tidak boleh berstatus Posted', $response->json('message'));
    }

    public function test_api_mengizinkan_membuat_jurnal_draft_tanpa_detail(): void
    {
        $nomor = 'UJI-APIDRAFT-'.uniqid();
        $this->loginSebagaiAdmin();

        $this->postJson('/api/jurnal', [
            'no_jurnal' => $nomor,
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'keterangan' => 'Uji pembuatan jurnal Draft tanpa detail',
            'status' => 'Draft',
        ])->assertStatus(201)->assertJsonPath('success', true);

        $this->assertSame('Draft', Jurnal::where('no_jurnal', $nomor)->first()?->status);
    }

    public function test_api_menolak_post_jurnal_tanpa_baris_detail(): void
    {
        $this->loginSebagaiAdmin();

        $nomor = 'UJI-APIPOST-'.uniqid();
        $jurnal = Jurnal::create([
            'no_jurnal' => $nomor,
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'UJI',
            'keterangan' => 'Uji post jurnal tanpa detail',
            'total_debet' => 0,
            'total_kredit' => 0,
            'is_balance' => true,
            'status' => 'Draft',
        ]);

        $response = $this->postJson("/api/jurnal/{$jurnal->id}/post");

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertStringContainsString('belum memiliki baris detail', $response->json('message'));
        $this->assertSame('Draft', $jurnal->fresh()->status, 'Status tidak boleh berubah saat post ditolak.');
    }

    public function test_api_menolak_post_jurnal_yang_tidak_balance(): void
    {
        $this->loginSebagaiAdmin();

        $kas = $this->akun('1-9108', 'Kas Uji Post Tidak Balance');
        $jurnal = Jurnal::create([
            'no_jurnal' => 'UJI-APIPOSTNB-'.uniqid(),
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'UJI',
            'keterangan' => 'Uji post jurnal tidak balance',
            'total_debet' => 200_000,
            'total_kredit' => 200_000,
            'is_balance' => true,
            'status' => 'Draft',
        ]);

        JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $kas->id, 'baris' => 1, 'debet' => 200_000, 'kredit' => 0]);
        JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $kas->id, 'baris' => 2, 'debet' => 0, 'kredit' => 120_000]);

        $response = $this->postJson("/api/jurnal/{$jurnal->id}/post");

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertStringContainsString('tidak balance', $response->json('message'));
        $this->assertSame('Draft', $jurnal->fresh()->status, 'Jurnal tidak balance harus tetap Draft.');
    }

    public function test_api_memposting_jurnal_draft_yang_balance(): void
    {
        $this->loginSebagaiAdmin();

        $kas = $this->akun('1-9109', 'Kas Uji Post Balance');
        $jurnal = Jurnal::create([
            'no_jurnal' => 'UJI-APIPOSTOK-'.uniqid(),
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'UJI',
            'keterangan' => 'Uji post jurnal balance',
            'total_debet' => 0,
            'total_kredit' => 0,
            'is_balance' => false,
            'status' => 'Draft',
        ]);

        JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $kas->id, 'baris' => 1, 'debet' => 350_000, 'kredit' => 0]);
        JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $kas->id, 'baris' => 2, 'debet' => 0, 'kredit' => 350_000]);

        $this->postJson("/api/jurnal/{$jurnal->id}/post")
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $jurnal->refresh();
        $this->assertSame('Posted', $jurnal->status);
        $this->assertEqualsWithDelta(350_000, (float) $jurnal->total_debet, 0.01);
        $this->assertTrue((bool) $jurnal->is_balance);
    }

    public function test_api_menolak_update_ke_posted_tanpa_baris_detail(): void
    {
        $this->loginSebagaiAdmin();

        $jurnal = Jurnal::create([
            'no_jurnal' => 'UJI-APIUPD-'.uniqid(),
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'UJI',
            'keterangan' => 'Uji update ke Posted tanpa detail',
            'total_debet' => 0,
            'total_kredit' => 0,
            'is_balance' => true,
            'status' => 'Draft',
        ]);

        $response = $this->putJson("/api/jurnal/{$jurnal->id}", [
            'no_jurnal' => $jurnal->no_jurnal,
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'keterangan' => 'Uji update ke Posted tanpa detail',
            'status' => 'Posted',
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertStringContainsString('belum memiliki baris detail', $response->json('message'));
        $this->assertSame('Draft', $jurnal->fresh()->status);
    }

    public function test_api_menolak_update_ke_posted_bila_tidak_balance(): void
    {
        $this->loginSebagaiAdmin();

        $kas = $this->akun('1-9110', 'Kas Uji Update Tidak Balance');
        $jurnal = Jurnal::create([
            'no_jurnal' => 'UJI-APIUPDNB-'.uniqid(),
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'UJI',
            'keterangan' => 'Uji update ke Posted tidak balance',
            'total_debet' => 0,
            'total_kredit' => 0,
            'is_balance' => false,
            'status' => 'Draft',
        ]);

        JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $kas->id, 'baris' => 1, 'debet' => 90_000, 'kredit' => 0]);

        $response = $this->putJson("/api/jurnal/{$jurnal->id}", [
            'no_jurnal' => $jurnal->no_jurnal,
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'keterangan' => 'Uji update ke Posted tidak balance',
            'status' => 'Posted',
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertStringContainsString('tidak balance', $response->json('message'));
        $this->assertSame('Draft', $jurnal->fresh()->status);
    }

    /* ==================================================================
     | D. VOID JURNAL MANUAL → JURNAL PEMBALIK (T-2 & S-1)
     ================================================================== */

    /** Membuat user berperan admin yang berbeda dari pemosting jurnal. */
    private function adminPemutus(string $suffix): User
    {
        return User::firstOrCreate(
            ['email' => "uji.void.{$suffix}@test.local"],
            [
                'name' => 'Uji Void '.$suffix,
                'password' => Hash::make('password'),
                'role' => 'admin',
                'is_active' => true,
            ]
        );
    }

    /**
     * Void jurnal manual wajib menghasilkan jurnal PEMBALIK yang:
     *  - memakai jalur tunggal post() dengan nomor ≤ 30 karakter (S-1),
     *  - menukar posisi debet/kredit, dan
     *  - menandai jurnal asal berstatus Void.
     */
    public function test_void_jurnal_manual_membentuk_pembalik_tervalidasi_dan_nonor_pendek(): void
    {
        $this->loginSebagaiAdmin();
        $pemosting = $this->adminPemutus('pemosting');
        $pemutus = $this->adminPemutus('pemutus');

        $kas = $this->akun('1-9111', 'Kas Uji Void');
        $pendapatan = $this->akun('4-9111', 'Pendapatan Uji Void');

        // Jurnal manual Posted — nomor asal sengaja panjang untuk menguji S-1.
        $noAsal = 'JRN-PANJANG-SEKALI-'.strtoupper(substr(uniqid(), -8));
        $jurnal = Jurnal::create([
            'no_jurnal' => $noAsal,
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'Jurnal Umum',
            'keterangan' => 'Uji void jurnal manual',
            'status' => 'Posted',
            'posted_at' => now(),
            'user_posted' => $pemosting->id,
            'user_id' => $pemosting->id,
        ]);

        JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $kas->id, 'baris' => 1, 'debet' => 150_000, 'kredit' => 0]);
        JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $pendapatan->id, 'baris' => 2, 'debet' => 0, 'kredit' => 150_000]);
        $jurnal->pastikanBalance();

        $this->actingAs($pemutus);

        $response = $this->postJson("/api/jurnal/{$jurnal->id}/void", [
            'alasan' => 'Salah input akun pendapatan, perlu dibalik penuh.',
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $pembalikId = $response->json('data.jurnal_pembalik.id');
        $pembalik = Jurnal::findOrFail($pembalikId);

        $this->assertLessThanOrEqual(30, strlen($pembalik->no_jurnal), 'no_jurnal wajib ≤ 30 karakter (varchar(30)).');
        $this->assertSame('Posted', $pembalik->status);
        $this->assertTrue((bool) $pembalik->is_balance, 'Jurnal pembalik wajib balance.');
        $this->assertSame('Void', $jurnal->fresh()->status, 'Jurnal asal wajib berstatus Void.');

        // Kontra-entry: posisi debet/kredit bertukar relatif terhadap jurnal asal.
        $barisKas = $pembalik->detail->firstWhere('coa_id', $kas->id);
        $this->assertNotNull($barisKas);
        $this->assertEquals(0.0, (float) $barisKas->debet);
        $this->assertEquals(150_000.0, (float) $barisKas->kredit);

        // Saldo bersih akun kas kembali nol (debet asal − kredit pembalik).
        $saldoKas = (float) JurnalDetail::where('coa_id', $kas->id)
            ->whereIn('jurnal_id', [$jurnal->id, $pembalik->id])
            ->sum(DB::raw('debet - kredit'));
        $this->assertEquals(0.0, $saldoKas);
    }
}
