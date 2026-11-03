<?php

namespace Tests\Feature;

use App\Models\ApprovalTransaksi;
use App\Models\Coa;
use App\Models\Transaksi;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PEMBUKTIAN TEMUAN AUDIT APPROVAL — jalur void pada ApprovalTransaksiController::approve().
 *
 * Jalur approval biasa memeriksa peran pemutus lewat HierarkiRole::peranUntukStatus()
 * (TransactionLimitService::approveTransaction). Jalur void di controller memanggil
 * ReversalService::executeVoidReversal() tanpa pemeriksaan peran, hanya pemisahan tugas.
 * Akibatnya peran yang lolos gerbang izin (manajer, pengurus) dapat memutus void
 * yang statusnya bukan wewenangnya.
 */
class BuktiPeranApprovalVoidTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $role, string $kunci): User
    {
        return User::firstOrCreate(
            ['email' => "bukti.{$kunci}@test.local"],
            [
                'name' => 'Bukti '.ucfirst($kunci),
                'password' => Hash::make('password'),
                'role' => $role,
                'is_active' => true,
            ],
        );
    }

    private function transaksiPendingVoid(User $pengaju): Transaksi
    {
        return Transaksi::create([
            'no_transaksi' => 'TRX-BUKTI-'.strtoupper(substr(uniqid(), -6)),
            'waktu_transaksi' => now(),
            'jenis_transaksi' => 'Setoran Tunai',
            'posisi' => 'Debet',
            'jumlah' => 75000000.0,
            'user_id' => $pengaju->id,
            'status_transaksi' => Transaksi::STATUS_TRANSAKSI_PENDING_VOID,
            'status' => Transaksi::STATUS_TEKNIS_SUCCESS,
        ]);
    }

    private function approval(string $status, User $pengaju, string $tipe, ?Transaksi $transaksi = null): ApprovalTransaksi
    {
        return ApprovalTransaksi::create([
            'kode_approval' => ApprovalTransaksi::generateKode(),
            'tipe_referensi' => $tipe,
            'referensi_id' => $transaksi?->id ?? random_int(100000, 999999),
            'jenis_transaksi' => 'Setoran Tunai',
            'nominal' => 75000000.0,
            'jenis_pembayaran' => 'tunai',
            'status' => $status,
            'diajukan_oleh' => $pengaju->id,
            'role_pengaju' => $pengaju->role,
        ]);
    }

    /** KONTROL: jalur biasa sudah menolak peran di luar wewenang status. */
    public function test_kontrol_pengurus_ditolak_menyetujui_approval_biasa_status_manajer(): void
    {
        $teller = $this->user('teller', 'pengaju');
        $pengurus = $this->user('pengurus', 'pemutus');
        $approval = $this->approval('PENDING_MANAJER', $teller, 'transaksi_biasa_bukti');

        $this->actingAs($pengurus)
            ->post(route('pengelola.approval.approve', $approval))
            ->assertSessionHas('error', 'Anda tidak memiliki hak untuk menyetujui transaksi ini.');

        $this->assertSame('PENDING_MANAJER', $approval->fresh()->status);
    }

    /** TEMUAN: jalur void tidak memeriksa peran — pengurus dapat memutus void berstatus PENDING_MANAJER. */
    public function test_temuan_pengurus_tidak_boleh_memutus_void_berstatus_manajer(): void
    {
        $teller = $this->user('teller', 'pengaju');
        $pengurus = $this->user('pengurus', 'pemutus');
        $transaksi = $this->transaksiPendingVoid($teller);
        $approval = $this->approval('PENDING_MANAJER', $teller, 'transaksi', $transaksi);

        $respons = $this->actingAs($pengurus)
            ->post(route('pengelola.approval.approve', $approval));

        $respons->assertSessionHas('error', 'Anda tidak memiliki hak untuk menyetujui transaksi ini.');
        $this->assertSame('PENDING_MANAJER', $approval->fresh()->status);
        $this->assertSame(Transaksi::STATUS_TRANSAKSI_PENDING_VOID, $transaksi->fresh()->status_transaksi);
    }

    /** TEMUAN 2: jurnal pembalik gagal di jalur void → RuntimeException lolos tanpa try/catch. */
    public function test_temuan_void_dengan_jurnal_gagal_tidak_boleh_menghasilkan_galat_mentah(): void
    {
        $teller = $this->user('teller', 'pengaju');
        $manajer = $this->user('manajer', 'pemutus');
        $transaksi = $this->transaksiPendingVoid($teller);
        $approval = $this->approval('PENDING_MANAJER', $teller, 'transaksi', $transaksi);

        Coa::query()->update(['is_active' => false]);

        $respons = $this->actingAs($manajer)
            ->post(route('pengelola.approval.approve', $approval));

        $respons->assertRedirect(route('pengelola.approval.index'));
        $respons->assertSessionHas('error');
        $this->assertSame('PENDING_MANAJER', $approval->fresh()->status);
        $this->assertSame(Transaksi::STATUS_TRANSAKSI_PENDING_VOID, $transaksi->fresh()->status_transaksi);
    }
}
