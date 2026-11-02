<?php

namespace Tests\Feature;

use App\Models\Transaksi;
use App\Models\User;
use App\Services\TransactionLimitService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Temuan audit — `jenis_pembayaran` pada tabel `transaksi` dibuang oleh
 * mass assignment.
 *
 * Kolom `transaksi.jenis_pembayaran` ada di skema (varchar(20), nullable) dan
 * dipakai untuk mencatat kanal pembayaran (tunai / non_tunai / autodebit),
 * tetapi TIDAK terdaftar pada `Transaksi::$fillable`. Semua jalur penulisan
 * memakai `Transaksi::create([...])`, sehingga Eloquent diam-diam membuang
 * field tersebut. Akibatnya:
 *   - `TransactionLimitService` (jalur PENDING_APPROVAL & SUCCESS),
 *   - `AngsuranTransactionService` (bayar angsuran tunai/autodebit),
 * semuanya menyimpan NULL — kanal pembayaran hilang sehingga rekonsiliasi
 * kanal pembayaran dan analisis AML tidak mungkin dilakukan.
 *
 * Test ini mengunci: kolom WAJIB bisa diisi lewat mass assignment dan
 * nilainya harus benar-benar tersimpan di database.
 */
class JenisPembayaranTransaksiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_jenis_pembayaran_masuk_fillable_transaksi(): void
    {
        $fillable = (new Transaksi)->getFillable();

        $this->assertContains(
            'jenis_pembayaran',
            $fillable,
            'Kolom jenis_pembayaran harus mass-assignable; jika tidak, nilainya dibuang diam-diam oleh Eloquent.'
        );
    }

    public function test_jenis_pembayaran_tersimpan_ulang_lewat_mass_assignment(): void
    {
        $trx = Transaksi::create([
            'no_transaksi' => 'TRX-JP-'.uniqid(),
            'waktu_transaksi' => now(),
            'jenis_transaksi' => 'Setoran Tunai',
            'posisi' => 'Debet',
            'jumlah' => 1000,
            'nominal' => 1000,
            'jenis_pembayaran' => 'tunai',
            'status' => 'success',
            'status_transaksi' => 'Sukses',
        ]);

        $trx->refresh();

        $this->assertSame('tunai', $trx->jenis_pembayaran, 'jenis_pembayaran harus tersimpan di database.');
    }

    public function test_transaksi_dari_transaction_limit_service_menyimpan_jenis_pembayaran(): void
    {
        $user = User::create([
            'name' => 'Teller JP',
            'email' => 'teller.jp.'.uniqid().'@test.local',
            'password' => Hash::make('rahasia-kuat-123'),
            'role' => 'teller',
            'is_active' => true,
        ]);

        $hasil = app(TransactionLimitService::class)->processTransactionWithLimitCheck(
            $user,
            'setor_tunai',
            10000,
            'tunai',
            [
                'no_transaksi' => 'TRX-JPL-'.uniqid(),
                'rekening_id' => null,
                'anggota_id' => null,
                'jenis_transaksi' => 'Setoran Tunai',
                'posisi' => 'Debet',
                'jumlah' => 10000,
                'nominal' => 10000,
                'sumber_transaksi' => 'Teller',
            ],
            'Uji regression jenis pembayaran'
        );

        $this->assertNotNull($hasil['data'] ?? null, 'Transaksi harus berhasil dibuat: '.($hasil['message'] ?? ''));

        $trx = $hasil['data']['transaksi'];
        $trx->refresh();

        $this->assertSame('tunai', $trx->jenis_pembayaran, 'Layanan limit transaksi harus menyimpan kanal pembayaran.');
    }
}
