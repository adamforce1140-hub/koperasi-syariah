<?php

namespace Tests\Feature;

use App\Enums\DisbursementMethod;
use App\Models\Anggota;
use App\Models\Coa;
use App\Models\Pembiayaan;
use App\Models\User;
use App\Support\PemisahanTugas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Integritas jalur pencairan pembiayaan milik Teller.
 *
 * Batch 16 memeriksa rute `teller.pencairan.*`. Terdapat DUA pintu masuk
 * pencairan yang sama-sama berujung ke DisbursementService:
 *
 *   1. `teller.pencairan.proses`      -> PencairanController@prosesPencairan
 *   2. `teller.pencairan.cairkan`     -> TellerPencairanController@cairkan
 *
 * DisbursementService sendiri sudah menegakkan pemisahan tugas (penyetuju
 * tidak boleh mencairkan). Yang diuji di sini adalah apakah KEDUA pintu
 * meneruskan `userId` dengan benar — pintu yang meneruskan `null` akan
 * melumpuhkan penjagaan tersebut tanpa terlihat.
 */
class IntegritasPencairanTellerTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        if (Coa::query()->count() === 0) {
            $this->markTestSkipped('CoA belum tersedia pada basis data uji.');
        }

        $this->prefix = 'PC'.strtoupper(substr(uniqid(), -6));
    }

    private function user(string $peran, string $sufiks = ''): User
    {
        return User::create([
            'name' => ucfirst($peran).' '.$this->prefix.$sufiks,
            'email' => $peran.'-'.strtolower($this->prefix).$sufiks.'@contoh.test',
            'password' => Hash::make('rahasia123'),
            'role' => $peran,
            'is_active' => true,
        ]);
    }

    private function pembiayaan(string $status, ?int $disetujuiOleh = null): Pembiayaan
    {
        $anggota = Anggota::create([
            'nama_lengkap' => 'Anggota '.$this->prefix,
            'no_anggota' => Anggota::nomorAnggotaBaru('ANG-'.date('Ym').'-'),
            'nik' => str_pad((string) random_int(1000000000000000, 9999999999999999), 16, '0'),
            'jenis_kelamin' => 'Laki-laki',
            'no_hp' => '081200000016',
            'alamat' => 'Jl. Uji Batch 16',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_ACTIVE,
        ]);

        return Pembiayaan::create([
            'no_pembiayaan' => 'PB-'.$this->prefix,
            'anggota_id' => $anggota->id,
            'akun_id' => (int) Coa::query()->value('id'),
            'akad_pembiayaan' => 'qardh',
            'jumlah_pembiayaan' => 1_000_000,
            'total_pembiayaan' => 1_000_000,
            'tenor' => 10,
            'jumlah_angsuran' => 100_000,
            'sisa_pembiayaan' => 1_000_000,
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths(10)->toDateString(),
            'status_pembiayaan' => $status,
            'disetujui_oleh' => $disetujuiOleh,
        ]);
    }

    /**
     * T-01 — pemisahan tugas pada pintu `teller.pencairan.proses`.
     *
     * Pihak yang MENYETUJUI pembiayaan tidak boleh menjadi teller yang
     * MENCAIRKAN dana. DisbursementService sudah menolak keadaan ini; test
     * ini memastikan jalur HTTP-nya benar-benar sampai ke penjagaan itu.
     */
    public function test_pencairan_menolak_penyetuju_yang_mencairkan_sendiri(): void
    {
        $teller = $this->user('teller', 't01');
        $pembiayaan = $this->pembiayaan(Pembiayaan::STATUS_DISETUJUI, $teller->id);
        $this->bukaSesiKas($teller, null, 10_000_000);

        $this->actingAs($teller)
            ->post(route('teller.pencairan.proses', $pembiayaan->id), [
                'disbursement_method' => DisbursementMethod::TRANSFER_SAVINGS->value,
            ])
            ->assertSessionHas('error', PemisahanTugas::PESAN);

        $segar = $pembiayaan->fresh();

        $this->assertSame(Pembiayaan::STATUS_DISETUJUI, $segar->status_pembiayaan,
            'Penyetuju tidak boleh mencairkan pembiayaan yang ia setujui sendiri.');
        $this->assertFalse((bool) $segar->is_disbursed,
            'Tidak boleh ada penandaan sudah-cair pada pelanggaran pemisahan tugas.');
    }

    /**
     * T-02 — pintu kembar `teller.pencairan.cairkan` menerapkan aturan yang sama.
     *
     * Kedua rute berujung ke DisbursementService, sehingga disparitas di sini
     * berarti satu pintu bisa dipakai untuk melewati aturan pintu lainnya.
     */
    public function test_jalur_pencairan_disutujui_juga_menolak_penyetuju(): void
    {
        $teller = $this->user('teller', 't02');
        $pembiayaan = $this->pembiayaan(Pembiayaan::STATUS_DISETUJUI, $teller->id);
        $pembiayaan->update(['tgl_pencairan' => now()->toDateString()]);
        $this->bukaSesiKas($teller, null, 10_000_000);

        $this->actingAs($teller)
            ->post(route('teller.pencairan.cairkan', $pembiayaan->id), [
                'disbursement_method' => DisbursementMethod::TRANSFER_SAVINGS->value,
            ])
            ->assertSessionHas('error', PemisahanTugas::PESAN);

        $this->assertSame(Pembiayaan::STATUS_DISETUJUI, $pembiayaan->fresh()->status_pembiayaan,
            'Jalur pencairan-disetujui wajib memakai aturan pemisahan tugas yang sama.');
    }

    /**
     * T-03 — status di luar daftar siap-cair ditolak tanpa menyentuh buku besar.
     */
    public function test_pencairan_menolak_status_di_luar_siap_cair(): void
    {
        $teller = $this->user('teller', 't03');
        $this->bukaSesiKas($teller, null, 10_000_000);

        foreach ([Pembiayaan::STATUS_DICAIRKAN, Pembiayaan::STATUS_LUNAS, Pembiayaan::STATUS_DITOLAK] as $status) {
            $pembiayaan = $this->pembiayaan($status);
            $pembiayaan->update(['no_pembiayaan' => 'PB-'.$this->prefix.crc32($status)]);

            $this->actingAs($teller)
                ->post(route('teller.pencairan.proses', $pembiayaan->id), [
                    'disbursement_method' => DisbursementMethod::TRANSFER_SAVINGS->value,
                ])
                ->assertSessionHas('error');

            $this->assertSame($status, $pembiayaan->fresh()->status_pembiayaan,
                'Status '.$status.' tidak boleh berubah menjadi dicairkan.');
        }
    }
}
