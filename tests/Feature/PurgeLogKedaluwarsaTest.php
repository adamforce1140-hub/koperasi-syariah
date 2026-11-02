<?php

namespace Tests\Feature;

use App\Models\AuditPerubahanPii;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * Kebijakan retensi & purge jejak audit kedaluwarsa (OJK-04).
 *
 * Dasar: UU No. 27/2022 (PDP) Pasal 16 & 35.
 *
 * Membuktikan:
 *  1. Mode bawaan hanya PRATINJAU (tidak menghapus).
 *  2. `--force` menghapus hanya baris yang melampaui cutoff.
 *  3. Baris yang masih dalam masa retensi TIDAK tersentuh.
 *  4. Rantai hash baris tersisa tetap UTUH setelah purge (tidak error verifyChain).
 *  5. Retensi 0 (tak terbatas) melindungi tabel dari penghapusan.
 */
class PurgeLogKedaluwarsaTest extends TestCase
{
    use DatabaseTransactions;

    /** Paksa cutoff ke angka tertentu lewat config, lalu jalankan command. */
    private function jalankan(array $opsi = [], int $hari = 1): PendingCommand|int
    {
        config()->set('keamanan.retensi_log', [
            'log_hari' => $hari,
            'log_keuangan_hari' => $hari,
            'pii_hari' => $hari,
            'batch' => 100,
        ]);

        return $this->artisan('log:purge-kedaluwarsa', $opsi);
    }

    /** Sisipkan baris log lama (append-only di-bypass lewat query builder). */
    private function sisipAuditLog(string $keterangan, Carbon $kapan): int
    {
        return (int) DB::table('audit_logs')->insertGetId([
            'jenis_transaksi' => 'uji-retensi',
            'keterangan' => $keterangan,
            'nominal' => 0,
            'created_at' => $kapan,
            'updated_at' => $kapan,
        ]);
    }

    /** Baris PII butuh kolom NOT NULL (model, model_id, aksi, hash, created_at). */
    private function sisipPii(string $deskripsi, Carbon $kapan): int
    {
        return (int) DB::table('audit_perubahan_pii')->insertGetId([
            'model' => 'Anggota',
            'model_id' => 1,
            'aksi' => 'edit',
            'deskripsi' => $deskripsi,
            'jumlah_kolom' => 0,
            'hash' => str_repeat('a', 64),
            'created_at' => $kapan,
        ]);
    }

    /* =====================================================================
     | 1. PRATINJAU BAWAAN
     ===================================================================== */

    public function test_bawaan_hanya_pratinjau_tidak_menghapus(): void
    {
        $lama = $this->sisipAuditLog('lama', now()->subDays(30));

        $this->jalankan()   // tanpa --force
            ->expectsOutputToContain('PRATINJAU')
            ->assertExitCode(0);

        $this->assertDatabaseHas('audit_logs', ['id' => $lama]);
    }

    public function test_dry_run_memblokir_force(): void
    {
        $lama = $this->sisipAuditLog('lama', now()->subDays(30));

        $this->jalankan(['--force' => true, '--dry-run' => true])->assertExitCode(0);

        $this->assertDatabaseHas('audit_logs', ['id' => $lama]);
    }

    /* =====================================================================
     | 2. PURGE DENGAN --force
     ===================================================================== */

    public function test_force_menghapus_hanya_baris_kedaluwarsa(): void
    {
        $lama = $this->sisipAuditLog('kedaluwarsa', now()->subDays(30));
        $baru = $this->sisipAuditLog('masih berlaku', now()->subHours(2));

        $this->jalankan(['--force' => true], hari: 15)->assertExitCode(0);

        $this->assertDatabaseMissing('audit_logs', ['id' => $lama]);
        $this->assertDatabaseHas('audit_logs', ['id' => $baru]);
    }

    public function test_force_menghapus_pii_kedaluwarsa(): void
    {
        $lama = $this->sisipPii('pii lama', now()->subDays(400));
        $baru = $this->sisipPii('pii baru', now()->subHours(1));

        $this->jalankan(['--force' => true], hari: 365)->assertExitCode(0);

        $this->assertDatabaseMissing('audit_perubahan_pii', ['id' => $lama]);
        $this->assertDatabaseHas('audit_perubahan_pii', ['id' => $baru]);
    }

    /* =====================================================================
     | 3. RANTAI HASH BARIS TERSISA TETAP UTUH
     ===================================================================== */

    public function test_rantai_hash_utuh_setelah_purge(): void
    {
        // Buat 3 baris PII berantal, yang tertua kedaluwarsa.
        $kapanLama = now()->subDays(400);
        DB::table('audit_perubahan_pii')->insertGetId([
            'model' => 'Anggota', 'model_id' => 1, 'aksi' => 'tambah',
            'jumlah_kolom' => 0, 'hash' => str_repeat('0', 64), 'created_at' => $kapanLama,
        ]);
        // Dua baris berikutnya dibuat dengan rantai yang benar via model.
        AuditPerubahanPii::query()->orderBy('id')->each(function ($log) {
            $log->hash = $log->hitungHash();
            DB::table('audit_perubahan_pii')->where('id', $log->id)->update(['hash' => $log->hash]);
        });

        $piiBaru = $this->sisipPii('pii baru', now());
        // Segel baris baru agar rantai benar sebelum purge.
        $this->segelUlangRantaiPii();

        $utuhSebelum = AuditPerubahanPii::verifyChain();
        $this->assertTrue($utuhSebelum['utuh'], 'Prasyarat: rantai harus utuh sebelum purge.');

        $this->jalankan(['--force' => true], hari: 365)->assertExitCode(0);

        // Baris lama terhapus; rantai tersisa harus DISEGEL ULANG → tetap utuh.
        $this->assertDatabaseMissing('audit_perubahan_pii', ['created_at' => $kapanLama]);

        $utuhSesudah = AuditPerubahanPii::verifyChain();
        $this->assertTrue(
            $utuhSesudah['utuh'],
            'Rantai harus tetap utuh setelah purge; rusak di #'.$utuhSesudah['rusak_di']
            .' ('.$utuhSesudah['alasan'].')'
        );
    }

    private function segelUlangRantaiPii(): void
    {
        $hash = null;
        $id = null;
        foreach (AuditPerubahanPii::query()->orderBy('id')->get() as $log) {
            $log->hash_sebelumnya_id = $id;
            $log->hash_sebelumnya = $hash;
            $segel = $log->hitungHash();
            DB::table('audit_perubahan_pii')->where('id', $log->id)->update([
                'hash_sebelumnya_id' => $id,
                'hash_sebelumnya' => $hash,
                'hash' => $segel,
            ]);
            $hash = $segel;
            $id = $log->id;
        }
    }

    /* =====================================================================
     | 4. RETENSI TAK TERBATAS (0) MELINDUNGI TABEL
     ===================================================================== */

    public function test_retensi_nol_tidak_menghapus(): void
    {
        $lama = $this->sisipAuditLog('sangat lama', now()->subYears(20));

        // hari = 0 → tak terbatas untuk semua tabel.
        $this->jalankan(['--force' => true], hari: 0)->assertExitCode(0);

        $this->assertDatabaseHas('audit_logs', ['id' => $lama]);
    }

    /* =====================================================================
     | 5. ARSIP DIBUAT SEBELUM PENGHAPUSAN
     ===================================================================== */

    public function test_baris_kedaluwarsa_diarsipkan(): void
    {
        $lama = $this->sisipAuditLog('perlu diarsipkan', now()->subDays(60));

        $this->jalankan(['--force' => true], hari: 30)->assertExitCode(0);

        $this->assertDatabaseMissing('audit_logs', ['id' => $lama]);

        $berkas = storage_path('app/arsip-log/audit_logs-'.now()->format('Ymd').'.jsonl');
        $this->assertFileExists($berkas);

        $isi = file_get_contents($berkas);
        $this->assertStringContainsString('perlu diarsipkan', $isi);

        @unlink($berkas);
    }
}
