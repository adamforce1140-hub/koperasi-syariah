<?php

namespace Tests\Feature;

use App\Models\Angsuran;
use App\Models\ApprovalTransaksi;
use App\Models\Coa;
use App\Models\Pembiayaan;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\AngsuranTransactionService;
use App\Services\ReversalService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Regresi dua temuan audit:
 *
 *  - #34 : `AngsuranController@store/update` harus menerima status turunan
 *          `'Dibayar Sebagian'` — status ini dipakai lintas service
 *          (AngsuranTransactionService, CkpnService, RestrukturisasiService,
 *          PembiayaanBermasalahRepository), tetapi sebelumnya ditolak validasi.
 *  - Tunggakan/NPF : setelah void, status angsuran yang jatuh temponya sudah
 *          lewat WAJIB `'Terlambat'` (diturunkan dari tanggal), bukan
 *          `'Belum Jatuh Tempo'`.
 */
class AngsuranStatusTurunanTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $role = 'teller'): User
    {
        return User::firstOrCreate(
            ['email' => "{$role}.statusangsuran@test.local"],
            [
                'name' => ucfirst($role).' Status Angsuran',
                'password' => Hash::make('password'),
                'role' => $role,
            ]
        );
    }

    private function anggotaId(): int
    {
        $anggotaId = DB::table('anggota')->value('id');

        if (! $anggotaId) {
            $anggotaId = DB::table('anggota')->insertGetId([
                'nama_lengkap' => 'Anggota Status Angsuran',
                'no_anggota' => 'AGT-SA-'.strtoupper(substr(uniqid(), -6)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return (int) $anggotaId;
    }

    /** Pembiayaan Qardh + satu jadwal angsuran dengan jatuh tempo yang ditentukan. */
    private function buatAngsuran(string $tanggalJatuhTempo, float $pokok = 100_000): Angsuran
    {
        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => 'PMB-SA-'.strtoupper(substr(uniqid(), -6)),
            'anggota_id' => $this->anggotaId(),
            'akun_id' => Coa::query()->value('id') ?? 1,
            'akad_pembiayaan' => 'Qardh',
            'jumlah_pembiayaan' => $pokok,
            'total_pembiayaan' => $pokok,
            'total_ujroh' => 0,
            'sisa_pembiayaan' => $pokok,
            'tenor' => 1,
            'jumlah_angsuran' => $pokok,
            'tanggal_akad' => now()->subMonths(2)->toDateString(),
            'tanggal_jatuh_tempo' => $tanggalJatuhTempo,
            'status_pembiayaan' => 'Aktif',
        ]);

        return Angsuran::create([
            'pembiayaan_id' => $pembiayaan->id,
            'angsuran_ke' => 1,
            'tanggal_jatuh_tempo' => $tanggalJatuhTempo,
            'pokok_angsuran' => $pokok,
            'margin_ujroh' => 0,
            'jumlah_angsuran' => $pokok,
            'sisa_pokok' => $pokok,
            'status_angsuran' => 'Belum Jatuh Tempo',
        ]);
    }

    private function approvalVoid(Transaksi $transaksi, User $pengaju): ApprovalTransaksi
    {
        return ApprovalTransaksi::create([
            'kode_approval' => ApprovalTransaksi::generateKode(),
            'tipe_referensi' => 'transaksi',
            'referensi_id' => $transaksi->id,
            'jenis_transaksi' => 'VOID',
            'nominal' => (float) $transaksi->jumlah,
            'status' => 'PENDING_MANAJER',
            'diajukan_oleh' => $pengaju->id,
            'role_pengaju' => $pengaju->role,
            'catatan_pengaju' => 'Uji status turunan angsuran',
        ]);
    }

    /* =====================================================================
     | #34 — validasi menerima 'Dibayar Sebagian'
     ===================================================================== */

    public function test_update_angsuran_menerima_status_dibayar_sebagian(): void
    {
        $angsuran = $this->buatAngsuran(now()->addMonth()->toDateString());
        $teller = $this->user();

        $payload = [
            'pembiayaan_id' => $angsuran->pembiayaan_id,
            'angsuran_ke' => $angsuran->angsuran_ke,
            'tanggal_jatuh_tempo' => $angsuran->tanggal_jatuh_tempo->toDateString(),
            'jumlah_angsuran' => (float) $angsuran->jumlah_angsuran,
            'sisa_pokok' => (float) $angsuran->sisa_pokok,
            'status_angsuran' => 'Dibayar Sebagian',
        ];

        $this->actingAs($teller)
            ->putJson("/api/angsuran/{$angsuran->id}", $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('Dibayar Sebagian', $angsuran->fresh()->status_angsuran);
    }

    public function test_update_angsuran_menolak_status_di_luar_daftar(): void
    {
        $angsuran = $this->buatAngsuran(now()->addMonth()->toDateString());
        $teller = $this->user();

        $payload = [
            'pembiayaan_id' => $angsuran->pembiayaan_id,
            'angsuran_ke' => $angsuran->angsuran_ke,
            'tanggal_jatuh_tempo' => $angsuran->tanggal_jatuh_tempo->toDateString(),
            'jumlah_angsuran' => (float) $angsuran->jumlah_angsuran,
            'sisa_pokok' => (float) $angsuran->sisa_pokok,
            'status_angsuran' => 'Status Tidak Dikenal',
        ];

        $this->actingAs($teller)
            ->putJson("/api/angsuran/{$angsuran->id}", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('status_angsuran');
    }

    /* =====================================================================
     | Void angsuran yang jatuh temponya sudah lewat → 'Terlambat'
     ===================================================================== */

    public function test_void_angsuran_jatuh_tempo_lampau_menjadi_terlambat(): void
    {
        $angsuran = $this->buatAngsuran(now()->subDays(5)->toDateString());
        $teller = $this->user();

        app(AngsuranTransactionService::class)->proses($angsuran, [
            'jumlah_bayar' => (float) $angsuran->pokok_angsuran,
            'metode_bayar' => 'TUNAI',
            'user_id' => $teller->id,
        ]);

        $angsuran->refresh();
        $this->assertSame('Sudah Dibayar', $angsuran->status_angsuran);

        $transaksi = Transaksi::findOrFail($angsuran->transaksi_id);
        $transaksi->update(['status_transaksi' => 'Pending Void']);

        $manajer = $this->user('manajer');

        $hasil = app(ReversalService::class)->executeVoidReversal(
            $this->approvalVoid($transaksi, $teller),
            $manajer->id
        );

        $this->assertSame('success', $hasil['status'], $hasil['message']);

        $angsuran->refresh();
        $this->assertSame(
            'Terlambat',
            $angsuran->status_angsuran,
            'Angsuran berjatuh tempo lampau harus kembali berstatus Terlambat setelah void.'
        );
        $this->assertNull($angsuran->tanggal_bayar);
        $this->assertSame((float) $angsuran->pokok_angsuran, (float) $angsuran->sisa_pokok);
    }
}
