<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Coa;
use App\Models\Pembiayaan;
use App\Models\PengajuanPembiayaan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * BATCH 15 - Integritas keputusan approval pembiayaan oleh Manajer.
 *
 * Rute yang dikunci (`manajer.approval-pembiayaan.*`):
 *   - keputusan hanya sah untuk pengajuan yang masih menunggu Manajer;
 *   - keputusan tidak boleh ditimpa oleh keputusan berikutnya
 *     (sekali putus, tak boleh diubah diam-diam);
 *   - permintaan revisi wajib memakai payload yang sah.
 *
 * Catatan: `approve()` sudah menjaga status (`isStatus(PENDING_MANAJER)`),
 * sehingga jalur `reject` dan `revisi` wajib mengikuti aturan yang sama.
 */
class IntegritasApprovalPembiayaanManajerTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        if (Coa::query()->count() === 0) {
            $this->markTestSkipped('CoA belum tersedia pada basis data uji.');
        }

        $this->prefix = 'AP'.strtoupper(substr(uniqid(), -6));
    }

    private function user(string $peran, string $sufiks = ''): User
    {
        return User::create([
            'name' => ucfirst($peran).' '.$this->prefix.$sufiks,
            'email' => $peran.'-'.strtolower($this->prefix).$sufiks.'@contoh.test',
            'password' => Hash::make('rahasia123'),
            'role' => $peran,
            'status' => 'aktif',
        ]);
    }

    private function anggota(string $sufiks = ''): Anggota
    {
        return Anggota::create([
            'nama_lengkap' => 'Anggota '.$this->prefix.$sufiks,
            'no_anggota' => Anggota::nomorAnggotaBaru('ANG-'.date('Ym').'-'),
            'nik' => str_pad((string) random_int(1000000000000000, 9999999999999999), 16, '0'),
            'jenis_kelamin' => 'Laki-laki',
            'no_hp' => '081200000010',
            'alamat' => 'Jl. Uji Batch 15',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_ACTIVE,
        ]);
    }

    private function pembiayaan(string $status, string $sufiks = ''): Pembiayaan
    {
        $anggota = $this->anggota($sufiks);

        return Pembiayaan::create([
            'no_pembiayaan' => 'PB-'.$this->prefix.$sufiks,
            'anggota_id' => $anggota->id,
            'akun_id' => $this->akunId(),
            'akad_pembiayaan' => 'qardh',
            'jumlah_pembiayaan' => 2_000_000,
            'total_pembiayaan' => 2_000_000,
            'tenor' => 10,
            'jumlah_angsuran' => 200_000,
            'sisa_pembiayaan' => 2_000_000,
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths(10)->toDateString(),
            'status_pembiayaan' => $status,
        ]);
    }

    private function akunId(): int
    {
        return (int) Coa::query()->value('id');
    }

    private function pengajuan(Pembiayaan $pembiayaan, string $status = PengajuanPembiayaan::STATUS_DIREVIEW): PengajuanPembiayaan
    {
        return PengajuanPembiayaan::create([
            'no_pengajuan' => 'PG-'.$this->prefix.$pembiayaan->id,
            'anggota_id' => $pembiayaan->anggota_id,
            'pembiayaan_id' => $pembiayaan->id,
            'jenis_akad' => 'qardh',
            'jumlah_pengajuan' => 2_000_000,
            'tenor' => 10,
            'tujuan' => 'Modal usaha',
            'status' => $status,
        ]);
    }

    // ---------------------------------------------------------------------
    // Revisi
    // ---------------------------------------------------------------------

    public function test_revisi_hanya_berlaku_untuk_pengajuan_menunggu_manajer(): void
    {
        $manajer = $this->user('manajer', 'rv1');

        $kasus = [
            Pembiayaan::STATUS_DICAIRKAN,
            Pembiayaan::STATUS_LUNAS,
            Pembiayaan::STATUS_DITOLAK,
            Pembiayaan::STATUS_PENDING_AO,
            Pembiayaan::STATUS_PENDING_PENGURUS,
        ];

        foreach ($kasus as $status) {
            $item = $this->pembiayaan($status, 'rv1'.crc32($status));

            $this->actingAs($manajer)
                ->post(route('manajer.approval-pembiayaan.revisi', $item->id), [
                    'catatan_revisi' => 'Mohon dilengkapi.',
                ]);

            $this->assertSame($status, $item->fresh()->status_pembiayaan,
                'Status '.$status.' tidak boleh diubah menjadi pending_ao oleh permintaan revisi.');
        }
    }

    public function test_revisi_tidak_mengubah_pengajuan_yang_sudah_diputuskan(): void
    {
        $manajer = $this->user('manajer', 'rv2');
        $item = $this->pembiayaan(Pembiayaan::STATUS_DICAIRKAN, 'rv2');
        $pengajuan = $this->pengajuan($item, PengajuanPembiayaan::STATUS_DISETUJUI);
        $pengajuan->update(['analisis_5c' => 'Analisis awal lengkap.']);

        $this->actingAs($manajer)
            ->post(route('manajer.approval-pembiayaan.revisi', $item->id), [
                'catatan_revisi' => 'Revisi ulang setelah cair.',
            ]);

        $pengajuan->refresh();

        $this->assertSame(PengajuanPembiayaan::STATUS_DISETUJUI, $pengajuan->status,
            'Pengajuan yang sudah disetujui tidak boleh dikembalikan menjadi menunggu.');
        $this->assertSame('Analisis awal lengkap.', $pengajuan->analisis_5c,
            'Analisis 5C pada pengajuan yang sudah diputus tidak boleh dihapus.');
    }

    public function test_revisi_menolak_catatan_kosong(): void
    {
        $manajer = $this->user('manajer', 'rv3');
        $item = $this->pembiayaan(Pembiayaan::STATUS_PENDING_MANAJER, 'rv3');

        $this->actingAs($manajer)
            ->post(route('manajer.approval-pembiayaan.revisi', $item->id), ['catatan_revisi' => ''])
            ->assertSessionHasErrors(['catatan_revisi']);

        $this->assertSame(Pembiayaan::STATUS_PENDING_MANAJER, $item->fresh()->status_pembiayaan);
    }

    // ---------------------------------------------------------------------
    // Tolak
    // ---------------------------------------------------------------------

    public function test_tolak_hanya_berlaku_untuk_pengajuan_menunggu_manajer(): void
    {
        $manajer = $this->user('manajer', 'tj1');

        $kasus = [
            Pembiayaan::STATUS_DICAIRKAN,
            Pembiayaan::STATUS_LUNAS,
            Pembiayaan::STATUS_PENDING_AO,
            Pembiayaan::STATUS_PENDING_PENGURUS,
        ];

        foreach ($kasus as $status) {
            $item = $this->pembiayaan($status, 'tj1'.crc32($status));

            $this->actingAs($manajer)
                ->post(route('manajer.approval-pembiayaan.reject', $item->id), [
                    'catatan_tolak' => 'Ditolak.',
                ]);

            $this->assertSame($status, $item->fresh()->status_pembiayaan,
                'Status '.$status.' tidak boleh berubah menjadi ditolak.');
        }
    }

    public function test_tolak_tidak_menimpa_pembiayaan_yang_sudah_dicairkan(): void
    {
        $manajer = $this->user('manajer', 'tj2');
        $item = $this->pembiayaan(Pembiayaan::STATUS_DICAIRKAN, 'tj2');

        $this->actingAs($manajer)
            ->post(route('manajer.approval-pembiayaan.reject', $item->id), [
                'catatan_tolak' => 'Ditolak setelah dana cair.',
            ]);

        $this->assertSame(Pembiayaan::STATUS_DICAIRKAN, $item->fresh()->status_pembiayaan,
            'Pembiayaan yang sudah dicairkan tidak boleh menjadi ditolak.');
    }

    public function test_tolak_menolak_catatan_kosong(): void
    {
        $manajer = $this->user('manajer', 'tj3');
        $item = $this->pembiayaan(Pembiayaan::STATUS_PENDING_MANAJER, 'tj3');

        $this->actingAs($manajer)
            ->post(route('manajer.approval-pembiayaan.reject', $item->id), ['catatan_tolak' => ''])
            ->assertSessionHasErrors(['catatan_tolak']);

        $this->assertSame(Pembiayaan::STATUS_PENDING_MANAJER, $item->fresh()->status_pembiayaan);
    }

    // ---------------------------------------------------------------------
    // Perilaku sah yang dikunci
    // ---------------------------------------------------------------------

    public function test_tolak_diproses_untuk_pengajuan_menunggu_manajer(): void
    {
        $manajer = $this->user('manajer', 'ok1');
        $item = $this->pembiayaan(Pembiayaan::STATUS_PENDING_MANAJER, 'ok1');

        $this->actingAs($manajer)
            ->post(route('manajer.approval-pembiayaan.reject', $item->id), [
                'catatan_tolak' => 'Penghasilan tidak mencukupi.',
            ])
            ->assertRedirect(route('manajer.approval-pembiayaan.index'));

        $item->refresh();

        $this->assertSame(Pembiayaan::STATUS_DITOLAK, $item->status_pembiayaan);
        $this->assertSame($manajer->id, (int) $item->direview_oleh);
        $this->assertSame('Penghasilan tidak mencukupi.', $item->keterangan);
    }

    public function test_revisi_diproses_untuk_pengajuan_menunggu_manajer(): void
    {
        $manajer = $this->user('manajer', 'ok2');
        $item = $this->pembiayaan(Pembiayaan::STATUS_PENDING_MANAJER, 'ok2');
        $pengajuan = $this->pengajuan($item);

        $this->actingAs($manajer)
            ->post(route('manajer.approval-pembiayaan.revisi', $item->id), [
                'catatan_revisi' => 'Lengkapi slip gaji.',
            ])
            ->assertRedirect(route('manajer.approval-pembiayaan.index'));

        $this->assertSame(Pembiayaan::STATUS_PENDING_AO, $item->fresh()->status_pembiayaan);
        $this->assertSame(PengajuanPembiayaan::STATUS_MENUNGGU, $pengajuan->fresh()->status);
    }

    public function test_approve_tetap_menolak_pengajuan_di_luar_tahap_manajer(): void
    {
        $manajer = $this->user('manajer', 'ok3');
        $item = $this->pembiayaan(Pembiayaan::STATUS_DICAIRKAN, 'ok3');

        $this->actingAs($manajer)
            ->post(route('manajer.approval-pembiayaan.approve', $item->id));

        $this->assertSame(Pembiayaan::STATUS_DICAIRKAN, $item->fresh()->status_pembiayaan,
            'Approve tidak boleh mengubah pembiayaan yang sudah dicairkan.');
    }
}
