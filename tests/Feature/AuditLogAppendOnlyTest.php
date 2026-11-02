<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Verifikasi OJK-03 (Gelombang 2): `audit_logs` bersifat append-only dengan
 * rantai hash SHA-256 sehingga penyuntingan/penghapusan baris langsung
 * terdeteksi.
 *
 * Fokus pengujian:
 *  1. Baris baru tersegel dalam rantai (hash valid & tersambung).
 *  2. Model menolak operasi update & delete.
 *  3. Penyuntingan / penghapusan langsung di database memutus rantai.
 *
 * Lihat: docs/AUDIT-MENYELURUH-PSAK-OJK.md (Gelombang 2 — OJK-03).
 */
class AuditLogAppendOnlyTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix = 'OJK03';

    /** Catat beberapa baris uji lalu kembalikan daftarnya. */
    private function catatBeberapa(int $jumlah = 3): array
    {
        $baris = [];

        for ($i = 1; $i <= $jumlah; $i++) {
            $baris[] = AuditLog::catat([
                'jenis_transaksi' => "UJI_{$this->prefix}_{$i}",
                'nominal' => $i * 1000,
                'no_rekening' => 'REK-'.$i,
                'keterangan' => "Baris uji {$this->prefix} ke-{$i}",
                'ip_address' => '127.0.0.1',
            ]);
        }

        return $baris;
    }

    public function test_baris_baru_tersegel_dalam_rantai_dan_utuh(): void
    {
        // Ujung rantai sebelum pencatatan: baris pertama uji harus menyambung
        // ke sini, sebab tabel audit bisa saja sudah berisi baris sah
        // (mis. peristiwa SIMPANAN_WAJIB_OVERDUE yang dicatat saat seed).
        $ujungAwal = AuditLog::ujungRantai();

        $baris = $this->catatBeberapa(3);

        // Setiap baris punya hash 64 karakter heksadesimal.
        foreach ($baris as $log) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $log->hash);
        }

        // Rantai tersambung berurutan — baris pertama menyambung ke ujung
        // rantai saat itu, lalu antar baris uji saling menyambung.
        $this->assertSame($ujungAwal['id'], $baris[0]->hash_sebelumnya_id);
        $this->assertSame($ujungAwal['hash'], $baris[0]->hash_sebelumnya);
        $this->assertSame($baris[0]->id, $baris[1]->hash_sebelumnya_id);
        $this->assertSame($baris[0]->hash, $baris[1]->hash_sebelumnya);
        $this->assertSame($baris[1]->id, $baris[2]->hash_sebelumnya_id);
        $this->assertSame($baris[1]->hash, $baris[2]->hash_sebelumnya);

        $hasil = AuditLog::verifyChain();
        $this->assertTrue($hasil['utuh'], 'Rantai audit rusak: '.($hasil['alasan'] ?? ''));
    }

    public function test_record_kompatibel_dan_ikut_tersegel(): void
    {
        $log = AuditLog::record('UJI_RECORD_'.$this->prefix, 2500, null, 'Uji kompatibilitas');

        $this->assertNotNull($log->hash);
        $this->assertSame($log->hitungHash(), $log->hash);
    }

    public function test_model_menolak_operasi_update(): void
    {
        $log = $this->catatBeberapa(1)[0];

        $this->expectException(RuntimeException::class);
        $log->update(['keterangan' => 'DIUBAH']);
    }

    public function test_model_menolak_operasi_delete(): void
    {
        $log = $this->catatBeberapa(1)[0];

        $this->expectException(RuntimeException::class);
        $log->delete();
    }

    public function test_penyuntingan_langsung_di_database_terdeteksi(): void
    {
        $baris = $this->catatBeberapa(3);

        // Lewati guard model untuk mensimulasikan penyuntingan langsung.
        DB::table('audit_logs')->where('id', $baris[1]->id)->update([
            'keterangan' => 'DISUNTING DIAM-DIAM',
        ]);

        $hasil = AuditLog::verifyChain();

        $this->assertFalse($hasil['utuh'], 'Penyuntingan langsung seharusnya memutus rantai.');
        $this->assertSame($baris[1]->id, $hasil['rusak_di']);
    }

    public function test_penghapusan_baris_di_tengah_terdeteksi(): void
    {
        $baris = $this->catatBeberapa(3);

        DB::table('audit_logs')->where('id', $baris[1]->id)->delete();

        $hasil = AuditLog::verifyChain();

        $this->assertFalse($hasil['utuh'], 'Penghapusan baris tengah seharusnya memutus rantai.');
    }

    public function test_baris_lama_tanpa_hash_dapat_disegel_ulang(): void
    {
        // Ujung rantai harus diambil SEBELUM baris warisan disisipkan, sebab
        // baris tanpa hash itu sendiri akan tampak sebagai id tertinggi.
        $ujung = AuditLog::ujungRantai();

        // Simulasikan baris warisan yang belum tersegel.
        $id = DB::table('audit_logs')->insertGetId([
            'jenis_transaksi' => 'WARISAN_'.$this->prefix,
            'nominal' => 0,
            'no_rekening' => '-',
            'keterangan' => 'Baris lama tanpa hash',
            'ip_address' => null,
            'created_at' => now(),
        ]);

        $log = AuditLog::find($id);
        $this->assertNull($log->hash);

        // Penyegelan ulang memakai jalur yang sama dengan migrasi: baris
        // warisan disambungkan ke ujung rantai saat itu (bukan dipaksa null),
        // agar rantai tetap utuh walau tabel sudah berisi baris tersegel.
        $log->hash_sebelumnya_id = $ujung['id'];
        $log->hash_sebelumnya = $ujung['hash'];
        $log->hash = $log->hitungHash();
        $log->saveQuietly();

        $hasil = AuditLog::verifyChain();
        $this->assertTrue($hasil['utuh'], 'Baris yang disegel ulang seharusnya utuh.');
    }
}
