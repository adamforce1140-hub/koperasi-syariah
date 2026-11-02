<?php

namespace Tests\Feature;

use App\Http\Controllers\CifController;
use App\Models\Anggota;
use App\Models\PengajuanRekening;
use App\Models\User;
use App\Rules\NikUnik;
use App\Support\PiiHasher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * TAHAP 10 — KEAMANAN DATA PRIBADI (UU PDP No. 27/2022).
 *
 * Mengunci mekanisme enkripsi NIK at-rest + blind index yang menggantikan
 * peran plaintext untuk keunikan dan pencarian:
 *
 *  1. NIK tersimpan sebagai CIPHERTEXT di kolom `nik` (bukan teks biasa),
 *     dan dapat dibaca kembali sebagai plaintext oleh model (cast `encrypted`).
 *  2. Kolom `nik_hash` berisi HMAC-SHA256 deterministik (64 hex) yang
 *     dihitung ulang secara otomatis pada setiap create/update (`saving` hook).
 *  3. Pencarian NIK memakai blind index dengan KECOCOKAN TEPAT
 *     (`scopeDenganNik`); query `where('nik', $plaintext)` TIDAK menemukan
 *     apa pun karena ciphertext bersifat non-deterministik.
 *  4. Aturan `NikUnik` menolak NIK duplikat — termasuk baris yang telah
 *     di-soft delete — dan mengizinkan baris yang sedang di-update
 *     mempertahankan NIK-nya sendiri (`kecualiId`).
 *  5. `nik_hash` tidak pernah ikut terserialisasi (ada di `$hidden`).
 *  6. Normalisasi membuat variasi pemisah ("3273 0101 ...") menghasilkan
 *     blind index yang sama.
 *
 * Basis data memuat data nyata → memakai prefix unik + DatabaseTransactions.
 */
class KeamananPiiNikTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        // Prefix pendek (9 karakter) agar `no_anggota` tetap muat pada
        // kolom varchar(20): "AGT-" (4) + prefix (9) + "-" (1) + 4 digit = 18.
        $this->prefix = 'PII'.random_int(100000, 999999);
    }

    /* =====================================================================
     | UTILITAS
     |===================================================================== */

    private function nikBaru(int $offset = 0): string
    {
        // 16 digit unik per test-run (menghindari bentrok unique index).
        return '9999'.str_pad((string) (random_int(1000000000, 9999999999) + $offset), 12, '0', STR_PAD_LEFT);
    }

    private function anggota(string $nik): Anggota
    {
        return Anggota::create([
            'no_anggota' => 'AGT-'.$this->prefix.'-'.substr($nik, -4),
            'nik' => $nik,
            'nama_lengkap' => 'Anggota Uji PII '.$this->prefix,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji PII No. 1',
            'kota' => 'Jakarta',
            'provinsi' => 'DKI Jakarta',
            'status_anggota' => Anggota::STATUS_ACTIVE,
            'tanggal_gabung' => '2026-01-01',
        ]);
    }

    /* =====================================================================
     | 1. CIPHERTEXT AT-REST
     |===================================================================== */

    public function test_nik_tersimpan_sebagai_ciphertext_bukan_plaintext(): void
    {
        $nik = $this->nikBaru();
        $anggota = $this->anggota($nik);

        $mentah = DB::table('anggota')->where('id', $anggota->id)->value('nik');

        // Nilai mentah di basis data TIDAK boleh memuat NIK asli.
        $this->assertNotSame($nik, $mentah, 'NIK masih tersimpan sebagai plaintext di basis data.');
        $this->assertStringNotContainsString($nik, (string) $mentah,
            'NIK asli masih terbaca pada nilai mentah kolom `nik`.');

        // Ciphertext Laravel selalu berupa payload base64 bersandi IV+value+MAC.
        $this->assertNotFalse(base64_decode((string) $mentah, true),
            'Nilai kolom `nik` bukan payload terenkripsi Laravel yang sah.');

        // Namun model tetap dapat membacanya kembali sebagai plaintext.
        $this->assertSame($nik, Anggota::find($anggota->id)->nik);
    }

    public function test_ciphertext_bersifat_non_deterministik_untuk_nilai_sama(): void
    {
        $nik = $this->nikBaru();

        // Enkripsi nilai yang sama dua kali menghasilkan ciphertext berbeda
        // (IV acak) — inilah alasan `unique` bawaan tidak lagi dapat dipakai.
        $a = Crypt::encryptString($nik);
        $b = Crypt::encryptString($nik);

        $this->assertNotSame($a, $b);
        $this->assertSame($nik, Crypt::decryptString($a));
        $this->assertSame($nik, Crypt::decryptString($b));
    }

    /* =====================================================================
     | 2. SINKRONISASI BLIND INDEX
     |===================================================================== */

    public function test_blind_index_terhitung_otomatis_dan_berbentuk_hmac_hex_64(): void
    {
        $nik = $this->nikBaru();
        $anggota = $this->anggota($nik);

        $hash = DB::table('anggota')->where('id', $anggota->id)->value('nik_hash');

        $this->assertNotNull($hash, 'Blind index `nik_hash` tidak terisi saat create.');
        $this->assertSame(64, strlen($hash), 'Blind index harus 64 karakter hex (HMAC-SHA256).');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        $this->assertSame(PiiHasher::hash($nik), $hash);
    }

    public function test_blind_index_ikut_berubah_saat_nik_diubah(): void
    {
        $anggota = $this->anggota($this->nikBaru());
        $nikLama = $anggota->nik;

        $nikGanti = $this->nikBaru(7777);
        $anggota->update(['nik' => $nikGanti]);

        $hash = DB::table('anggota')->where('id', $anggota->id)->value('nik_hash');

        $this->assertSame(PiiHasher::hash($nikGanti), $hash);
        $this->assertNotSame(PiiHasher::hash($nikLama), $hash);
        $this->assertSame($nikGanti, $anggota->fresh()->nik);
    }

    public function test_memperbarui_kolom_lain_tidak_merusak_blind_index(): void
    {
        $nik = $this->nikBaru();
        $anggota = $this->anggota($nik);
        $hashAwal = DB::table('anggota')->where('id', $anggota->id)->value('nik_hash');

        $anggota->update(['pekerjaan' => 'Pedagang']);

        $this->assertSame($hashAwal, DB::table('anggota')->where('id', $anggota->id)->value('nik_hash'));
    }

    /* =====================================================================
     | 3. PENCARIAN MEMAKAI BLIND INDEX (KECOCOKAN TEPAT)
     |===================================================================== */

    public function test_pencarian_nik_memakai_scope_dengan_nik(): void
    {
        $nik = $this->nikBaru();
        $anggota = $this->anggota($nik);

        $this->assertSame(1, Anggota::denganNik($nik)->count());
        $this->assertSame($anggota->id, Anggota::denganNik($nik)->first()->id);

        // query langsung ke kolom terenkripsi TIDAK menemukan apa pun.
        $this->assertSame(0, Anggota::where('nik', $nik)->count(),
            'Kolom `nik` terenkripsi tidak boleh lagi dipakai untuk pencarian langsung.');
    }

    public function test_pencarian_nik_abaikan_format_pemisah(): void
    {
        $nik = $this->nikBaru();
        $anggota = $this->anggota($nik);

        // Format berspasi harus menghasilkan blind index yang sama.
        $berpemisah = implode(' ', str_split($nik, 4));

        $this->assertSame(1, Anggota::denganNik($berpemisah)->count());
        $this->assertSame($anggota->id, Anggota::denganNik($berpemisah)->first()->id);
    }

    public function test_pencarian_nik_kosong_tidak_mencocokkan_baris_apa_pun(): void
    {
        $this->anggota($this->nikBaru());

        $this->assertSame(0, Anggota::denganNik(null)->count());
        $this->assertSame(0, Anggota::denganNik('')->count());
        $this->assertSame(0, Anggota::denganNik('   ')->count());
    }

    public function test_nik_tidak_bocor_ke_serialisasi(): void
    {
        $anggota = $this->anggota($this->nikBaru());
        $array = $anggota->toArray();

        $this->assertArrayNotHasKey('nik_hash', $array,
            'Blind index tidak boleh ikut terserialisasi.');
        // `nik` tetap boleh tersedia bagi pemilik data (cast mendekripsi),
        // tetapi nilainya harus plaintext — bukan ciphertext mentah.
        $this->assertSame($anggota->nik, $array['nik']);
        $this->assertStringNotContainsString('eyJpdiI', (string) $array['nik']);
    }

    /* =====================================================================
     | 4. ATURAN KEUNIKAN BERBASIS BLIND INDEX
     |===================================================================== */

    public function test_rule_nik_unik_menolak_duplikat(): void
    {
        $nik = $this->nikBaru();
        $this->anggota($nik);

        $validator = Validator::make(
            ['nik' => $nik],
            ['nik' => ['required', 'digits:16', new NikUnik(Anggota::class)]]
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('nik', $validator->errors()->toArray());
    }

    public function test_rule_nik_unik_menerima_nik_baru(): void
    {
        $validator = Validator::make(
            ['nik' => $this->nikBaru()],
            ['nik' => ['required', 'digits:16', new NikUnik(Anggota::class)]]
        );

        $this->assertFalse($validator->fails());
    }

    public function test_rule_nik_unik_mengizinkan_nik_sendiri_saat_update(): void
    {
        $nik = $this->nikBaru();
        $anggota = $this->anggota($nik);

        // Tanpa pengecualian: NIK sendiri dianggap duplikat.
        $tanpaPengecualian = Validator::make(
            ['nik' => $nik],
            ['nik' => [new NikUnik(Anggota::class)]]
        );
        $this->assertTrue($tanpaPengecualian->fails());

        // Dengan kecualiId (operasi update): NIK sendiri harus diterima.
        $denganPengecualian = Validator::make(
            ['nik' => $nik],
            ['nik' => [new NikUnik(Anggota::class, $anggota->id)]]
        );
        $this->assertFalse($denganPengecualian->fails());
    }

    public function test_rule_nik_unik_tetap_menolak_baris_soft_deleted(): void
    {
        $nik = $this->nikBaru();
        $anggota = $this->anggota($nik);

        $anggota->delete(); // soft delete
        $this->assertSoftDeleted('anggota', ['id' => $anggota->id]);

        $validator = Validator::make(
            ['nik' => $nik],
            ['nik' => [new NikUnik(Anggota::class)]]
        );

        $this->assertTrue($validator->fails(),
            'NIK milik baris soft-deleted harus tetap dianggap terpakai (paritas aturan unique bawaan).');
    }

    public function test_unique_index_mencegah_duplikasi_nik_hash(): void
    {
        $nik = $this->nikBaru();
        $this->anggota($nik);

        // Penulisan langsung ke basis data dengan blind index yang sama harus
        // ditolak oleh constraint `anggota_nik_hash_unique`.
        $this->expectException(QueryException::class);

        DB::table('anggota')->insert([
            'no_anggota' => 'AGT-DUP-'.$this->prefix,
            'nik' => Crypt::encryptString($nik),
            'nik_hash' => PiiHasher::hash($nik),
            'nama_lengkap' => 'Duplikat Uji',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Duplikat No. 1',
            'kota' => 'Jakarta',
            'provinsi' => 'DKI Jakarta',
            'status_anggota' => Anggota::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /* =====================================================================
     | 5. PENGECEKAN PADA PENGAJUAN REKENING (kolom tanpa unique index)
     |===================================================================== */

    public function test_pengajuan_rekening_juga_memakai_ciphertext_dan_blind_index(): void
    {
        $nik = $this->nikBaru();

        $pengajuan = PengajuanRekening::create([
            'kode_pengajuan' => 'PGJ-'.$this->prefix,
            'nama_lengkap' => 'Pemohon Uji PII '.$this->prefix,
            'nik' => $nik,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Pengajuan No. 1',
            'jenis_simpanan' => 'Simpanan Pokok',
            'status' => PengajuanRekening::STATUS_MENUNGGU_VERIFIKASI,
        ]);

        $mentah = DB::table('pengajuan_rekening')->where('id', $pengajuan->id)->value('nik');
        $hash = DB::table('pengajuan_rekening')->where('id', $pengajuan->id)->value('nik_hash');

        $this->assertNotSame($nik, $mentah, 'NIK pengajuan masih tersimpan sebagai plaintext.');
        $this->assertSame(PiiHasher::hash($nik), $hash);
        $this->assertSame(1, PengajuanRekening::denganNik($nik)->count());
        $this->assertSame($nik, PengajuanRekening::find($pengajuan->id)->nik);
    }

    /* =====================================================================
     | 6. TIDAK ADA KEBOCORAN NIK PLAINTEXT
     |===================================================================== */

    /**
     * Duplikat NIK harus ditolak di lapisan VALIDASI (`NikUnik`), bukan dengan
     * menabrakkan unique index lalu memicu galat basis data. Ini penting karena
     * jalur galat basis data berpotensi menuliskan konteks (termasuk NIK) ke log.
     */
    public function test_duplikat_nik_ditolak_validasi_tanpa_galat_basis_data(): void
    {
        // Rute POST /ao/cif khusus peran `ao` — peran admin/teller akan ditolak 403.
        $user = User::where('role', 'ao')->first();

        if (! $user) {
            $this->markTestSkipped('Tidak ada user berperan ao untuk menguji endpoint CIF.');
        }

        $nik = $this->nikBaru();
        $this->anggota($nik);

        $logPath = storage_path('logs/laravel.log');
        $posisiAwal = file_exists($logPath) ? filesize($logPath) : 0;

        $response = $this->actingAs($user)->from('/ao/cif/create')->post('/ao/cif', [
            'nama_lengkap' => 'Duplikat Uji '.$this->prefix,
            'nik' => $nik,
            'jenis_kelamin' => 'Laki-laki',
            'no_hp' => '0812'.random_int(10000000, 99999999),
            'alamat' => 'Jl. Duplikat No. 1',
            'kota' => 'Jakarta',
            'provinsi' => 'DKI Jakarta',
            'pekerjaan' => 'Pedagang',
        ]);

        $response->assertRedirect('/ao/cif/create');
        $response->assertSessionHasErrors('nik');
        $response->assertStatus(302);

        // Tidak ada QueryException yang tercatat (validasi menangkap lebih dulu).
        if (file_exists($logPath) && filesize($logPath) > $posisiAwal) {
            $ekor = (string) file_get_contents($logPath, false, null, $posisiAwal);
            $this->assertStringNotContainsString('QueryException', $ekor,
                'Duplikat NIK seharusnya ditolak validasi, bukan memicu galat basis data.');
            $this->assertStringNotContainsString($nik, $ekor,
                'NIK plaintext tercatat ke log aplikasi (pelanggaran UU PDP No. 27/2022).');
        }
    }

    /**
     * Penerjemah galat duplikasi harus mengenali nama constraint BARU
     * (`anggota_nik_hash_unique`) — bukan lagi `anggota_nik_unique` yang sudah
     * dihapus migrasi — agar pengguna menerima pesan yang ramah, bukan trace.
     */
    public function test_pesan_galat_duplikat_mengenali_constraint_blind_index(): void
    {
        $method = new \ReflectionMethod(CifController::class, 'pesanGalatDuplikat');
        $method->setAccessible(true);

        $galat = new QueryException(
            'mysql',
            'insert into `anggota` (`nik_hash`) values (?)',
            ['abc'],
            new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'abc' for key 'anggota_nik_hash_unique'")
        );

        $pesan = $method->invoke(null, $galat);

        $this->assertNotNull($pesan, 'Constraint blind index tidak dikenali penerjemah galat.');
        $this->assertStringContainsString('NIK', $pesan);
    }
}
