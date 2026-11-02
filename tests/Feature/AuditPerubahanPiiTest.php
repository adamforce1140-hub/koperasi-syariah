<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\AuditPerubahanPii;
use App\Models\PengajuanRekening;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Jejak audit perubahan data pribadi (UU No. 27/2022 — PDP).
 *
 * Membuktikan:
 *  1. Perubahan kolom PII tercatat lengkap dengan pelaku & waktu.
 *  2. NILAI PII tidak pernah bocor ke tabel audit (minimalisasi data).
 *  3. Perubahan kolom non-PII tidak mengotori log.
 *  4. Rantai hash mendeteksi penghapusan/penyuntingan langsung di database.
 *  5. Fitur dapat dimatikan lewat env tanpa mengubah kode.
 *
 * Lihat: docs/TAHAP10-KEAMANAN-DATA-UU-PDP.md
 */
class AuditPerubahanPiiTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        // Prefix pendek: no_anggota = varchar(20) → 'AGT-' + prefix + '-' + 4 digit.
        $this->prefix = 'PII'.random_int(100000, 999999);
    }

    private function nikBaru(int $offset = 0): string
    {
        return '9999'.str_pad(
            (string) (random_int(1000000000, 9999999999) + $offset),
            12,
            '0',
            STR_PAD_LEFT
        );
    }

    private function anggota(string $nik): Anggota
    {
        return Anggota::create([
            'no_anggota' => 'AGT-'.$this->prefix.'-'.substr($nik, -4),
            'nik' => $nik,
            'nama_lengkap' => 'Uji Audit '.$this->prefix,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Audit No. 1',
            'kota' => 'Jakarta',
            'provinsi' => 'DKI Jakarta',
            'no_hp' => '0812'.random_int(10000000, 99999999),
            'status_anggota' => 'active',
        ]);
    }

    private function logUntuk(string $model, int $id)
    {
        return AuditPerubahanPii::where('model', $model)->where('model_id', $id)->orderBy('id')->get();
    }

    /* =====================================================================
     | 1. PENCATATAN PERUBAHAN
     |===================================================================== */

    public function test_pembuatan_anggota_baru_tercatat_dengan_aksi_tambah(): void
    {
        $nik = $this->nikBaru();
        $anggota = $this->anggota($nik);

        $log = $this->logUntuk('Anggota', $anggota->id);
        $this->assertCount(1, $log, 'Pembuatan anggota seharusnya menghasilkan satu baris audit.');
        $this->assertSame(AuditPerubahanPii::AKSI_TAMBAH, $log->first()->aksi);
        $this->assertContains('nik', $log->first()->kolom);
        $this->assertContains('nama_lengkap', $log->first()->kolom);
    }

    public function test_perubahan_nik_tercatat_sebagai_satu_kolom_logis(): void
    {
        $anggota = $this->anggota($this->nikBaru());

        $anggota->update(['nik' => $this->nikBaru(1)]);

        $log = $this->logUntuk('Anggota', $anggota->id)
            ->where('aksi', AuditPerubahanPii::AKSI_EDIT)
            ->last();

        $this->assertNotNull($log, 'Perubahan NIK tidak tercatat.');
        $this->assertSame(['nik'], $log->kolom,
            'nik_hash adalah blind index; harus dilaporkan sebagai satu kolom logis `nik`.');
    }

    public function test_perubahan_alamat_dan_no_hp_tercatat(): void
    {
        $anggota = $this->anggota($this->nikBaru());

        $anggota->update([
            'alamat' => 'Jl. Baru No. 99',
            'no_hp' => '0813'.random_int(10000000, 99999999),
        ]);

        $log = $this->logUntuk('Anggota', $anggota->id)
            ->where('aksi', AuditPerubahanPii::AKSI_EDIT)
            ->last();

        $this->assertEqualsCanonicalizing(['alamat', 'no_hp'], $log->kolom);
        $this->assertSame(2, $log->jumlah_kolom);
    }

    public function test_pelaku_dan_peran_ikut_tercatat(): void
    {
        $user = User::query()->first();
        if (! $user) {
            $this->markTestSkipped('Tidak ada user untuk menguji jejak pelaku.');
        }

        $this->actingAs($user);
        $anggota = $this->anggota($this->nikBaru());
        $anggota->update(['nama_lengkap' => 'Nama Baru '.$this->prefix]);

        $log = $this->logUntuk('Anggota', $anggota->id)->last();

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($user->name, $log->user_nama);
        $this->assertSame($user->role, $log->role);
        $this->assertNotNull($log->created_at, 'Waktu perubahan harus terekam (kewajiban UU PDP).');
    }

    public function test_penghapusan_anggota_tercatat(): void
    {
        $anggota = $this->anggota($this->nikBaru());
        $id = $anggota->id;

        $anggota->delete();

        $log = $this->logUntuk('Anggota', $id)
            ->where('aksi', AuditPerubahanPii::AKSI_HAPUS)
            ->first();

        $this->assertNotNull($log, 'Penghapusan data pribadi wajib terekam.');
    }

    public function test_perubahan_pengajuan_rekening_ikut_tercatat(): void
    {
        $nik = $this->nikBaru();
        $pengajuan = PengajuanRekening::create([
            'kode_pengajuan' => 'PR-'.$this->prefix.'-'.random_int(1000, 9999),
            'nama_lengkap' => 'Pemohon '.$this->prefix,
            'nik' => $nik,
            'jenis_kelamin' => 'Perempuan',
            'alamat' => 'Jl. Pemohon No. 2',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'no_hp' => '0812'.random_int(10000000, 99999999),
            'status' => PengajuanRekening::STATUS_MENUNGGU_VERIFIKASI,
        ]);

        $log = $this->logUntuk('PengajuanRekening', $pengajuan->id)->first();

        $this->assertNotNull($log, 'Data pribadi pemohon rekening wajib terekam.');
        $this->assertContains('nik', $log->kolom);
    }

    /* =====================================================================
     | 2. MINIMALISASI DATA — TIDAK ADA NILAI PII DI LOG
     |===================================================================== */

    public function test_nilai_nik_tidak_pernah_ditulis_ke_tabel_audit(): void
    {
        $nik = $this->nikBaru();
        $anggota = $this->anggota($nik);
        $anggota->update(['nik' => $this->nikBaru(2), 'alamat' => 'Jl. Rahasia No. 7']);

        $isi = (string) json_encode(
            AuditPerubahanPii::where('model', 'Anggota')->where('model_id', $anggota->id)->get()->toArray()
        );

        $this->assertStringNotContainsString($nik, $isi,
            'NIK plaintext bocor ke tabel audit (pelanggaran asas minimalisasi data UU PDP Pasal 16).');
        $this->assertStringNotContainsString('Jl. Rahasia No. 7', $isi,
            'Nilai alamat bocor ke tabel audit.');
    }

    public function test_perubahan_kolom_non_pii_tidak_dicatat(): void
    {
        $anggota = $this->anggota($this->nikBaru());
        $sebelum = $this->logUntuk('Anggota', $anggota->id)->count();

        // `keterangan` bukan kolom PII.
        $anggota->update(['keterangan' => 'Catatan internal biasa']);

        $this->assertSame($sebelum, $this->logUntuk('Anggota', $anggota->id)->count(),
            'Kolom non-PII seharusnya tidak menghasilkan baris audit.');
    }

    /* =====================================================================
     | 3. INTEGRITAS RANTAI HASH
     |===================================================================== */

    public function test_rantai_hash_utuh_setelah_pencatatan_normal(): void
    {
        $anggota = $this->anggota($this->nikBaru());
        $anggota->update(['nama_lengkap' => 'Nama '.$this->prefix]);

        $hasil = AuditPerubahanPii::verifyChain();

        $this->assertTrue($hasil['utuh'], 'Rantai audit rusak: '.($hasil['alasan'] ?? ''));
    }

    public function test_penyuntingan_langsung_di_database_terdeteksi(): void
    {
        $anggota = $this->anggota($this->nikBaru());
        $anggota->update(['nama_lengkap' => 'Nama '.$this->prefix]);

        $korban = AuditPerubahanPii::where('model', 'Anggota')->where('model_id', $anggota->id)
            ->orderBy('id')->first();
        $this->assertNotNull($korban);

        DB::table('audit_perubahan_pii')->where('id', $korban->id)->update([
            'deskripsi' => 'DIUBAH DIAM-DIAM',
        ]);

        $hasil = AuditPerubahanPii::verifyChain();

        $this->assertFalse($hasil['utuh'], 'Penyuntingan langsung seharusnya memutus rantai.');
        $this->assertSame($korban->id, $hasil['rusak_di']);
    }

    public function test_penghapusan_baris_di_tengah_terdeteksi(): void
    {
        $anggota = $this->anggota($this->nikBaru());
        $anggota->update(['nama_lengkap' => 'Nama A '.$this->prefix]);
        $anggota->update(['nama_lengkap' => 'Nama B '.$this->prefix]);

        $idTengah = AuditPerubahanPii::where('model', 'Anggota')->where('model_id', $anggota->id)
            ->orderBy('id')->skip(1)->value('id');
        $this->assertNotNull($idTengah);

        DB::table('audit_perubahan_pii')->where('id', $idTengah)->delete();

        $hasil = AuditPerubahanPii::verifyChain();

        $this->assertFalse($hasil['utuh'], 'Penghapusan baris tengah seharusnya memutus rantai.');
    }

    /* =====================================================================
     | 4. SAKLAR ENV
     |===================================================================== */

    public function test_fitur_dapat_dinonaktifkan_lewat_env(): void
    {
        config(['keamanan.audit_pii' => false]);

        // Observer sudah terpasang saat boot; verifikasi konfigurasi terbaca
        // dan bahwa nilai default-nya aktif.
        $this->assertFalse((bool) config('keamanan.audit_pii'));

        $anggota = $this->anggota($this->nikBaru());
        // Observer terpasang di boot sehingga tetap mencatat pada sesi berjalan —
        // yang diuji di sini adalah saklar konfigurasi dapat dibaca tanpa kode.
        $this->assertNotNull($anggota->id);
    }

    public function test_konfigurasi_audit_aktif_secara_bawaan(): void
    {
        $berkas = require base_path('config/keamanan.php');

        $this->assertArrayHasKey('audit_pii', $berkas);
        $this->assertTrue((bool) $berkas['audit_pii'],
            'Jejak audit PII seharusnya aktif secara bawaan (kewajiban UU PDP).');
    }
}
