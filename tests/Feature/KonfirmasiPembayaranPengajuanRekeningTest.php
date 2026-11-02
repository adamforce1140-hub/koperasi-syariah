<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Coa;
use App\Models\JenisSimpanan;
use App\Models\Jurnal;
use App\Models\PembayaranSimpanan;
use App\Models\PengajuanRekening;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use App\Support\Coa as KodeCoa;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * KONFIRMASI PEMBAYARAN PENGAJUAN REKENING → AKTIVASI + JURNAL.
 *
 * Jalur `PengajuanRekeningController::konfirmasiPembayaran()` adalah satu-
 * satunya pintu aktivasi rekening daring. Karena itu tiga hal wajib dijaga:
 *
 *  1. Setoran awal tidak hanya menambah saldo rekening, tetapi juga dicatat
 *     sebagai transaksi berstatus Sukses DAN diposting ke jurnal yang
 *     seimbang — tanpa jurnal, neraca koperasi tidak seimbang.
 *  2. Konfirmasi ganda (klik dua kali / retry jaringan) tidak boleh
 *     menerbitkan anggota, rekening, transaksi, atau jurnal kedua.
 *  3. Bila jurnal gagal diposting (CoA tidak tersedia), seluruh perubahan
 *     harus di-rollback: tidak ada rekening "hantu" tanpa jurnal.
 */
class KonfirmasiPembayaranPengajuanRekeningTest extends TestCase
{
    use DatabaseTransactions;

    private User $teller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teller = User::firstOrCreate(
            ['email' => 'teller.konfirmasi@test.local'],
            [
                'name' => 'Teller Konfirmasi Uji',
                'password' => Hash::make('password'),
                'role' => 'teller',
                'is_active' => true,
            ]
        );
    }

    /** CoA & akun legacy wajib tersedia agar rekening dan jurnal bisa dibuat. */
    private function pastikanPrasyarat(string $jenis = 'Simpanan Pokok'): void
    {
        $wajib = ['1-1001', '3-1001', '3-1002', '2-1001'];

        if (Coa::whereIn('kode_akun', $wajib)->count() < count($wajib)) {
            $this->markTestSkipped('CoA '.implode(', ', $wajib).' belum tersedia — jalankan seeder CoA.');
        }

        if (JenisSimpanan::akunLegacyId($jenis) === null) {
            $this->markTestSkipped('Akun legacy untuk "'.$jenis.'" belum tersedia — jalankan AkunSeeder.');
        }
    }

    /** Pengajuan menunggu konfirmasi pembayaran + baris pembayaran. */
    private function pengajuanMenungguKonfirmasi(float $nominal = 100000.0): PengajuanRekening
    {
        $pengajuan = PengajuanRekening::create([
            'kode_pengajuan' => PengajuanRekening::generateKode(),
            'nama_lengkap' => 'Calon Anggota '.substr(uniqid(), -4),
            'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
            'tempat_lahir' => 'Bandung',
            'tanggal_lahir' => '1995-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji Konfirmasi No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'no_hp' => '08'.str_pad((string) random_int(1, 9999999999), 10, '0', STR_PAD_LEFT),
            'email' => 'calon'.substr(uniqid(), -6).'@test.local',
            'pekerjaan' => 'Karyawan',
            'jenis_simpanan' => 'Simpanan Pokok',
            'nominal_simpanan_awal' => $nominal,
            'status' => PengajuanRekening::STATUS_MENUNGGU_KONFIRMASI,
        ]);

        PembayaranSimpanan::create([
            'pengajuan_id' => $pengajuan->id,
            'nominal' => $nominal,
            'metode_bayar' => 'Transfer Bank',
            'status' => 'Menunggu Konfirmasi',
        ]);

        return $pengajuan;
    }

    private function konfirmasi(PengajuanRekening $pengajuan)
    {
        $this->actingAs($this->teller);

        return $this->post(route('pengelola.pengajuan-rekening.konfirmasi-pembayaran', $pengajuan));
    }

    public function test_konfirmasi_menerbitkan_rekening_dan_memposting_jurnal_seimbang(): void
    {
        $this->pastikanPrasyarat();

        $nominal = 150000.0;
        $pengajuan = $this->pengajuanMenungguKonfirmasi($nominal);

        $this->konfirmasi($pengajuan)->assertRedirect();

        $pengajuan->refresh();

        // 1. Pengajuan aktif + nomor rekening terbit + anggota tertaut.
        $this->assertSame(PengajuanRekening::STATUS_AKTIF, $pengajuan->status);
        $this->assertNotEmpty($pengajuan->no_rekening_terbit);
        $this->assertNotNull($pengajuan->anggota_id);

        // 2. Pembayaran terkonfirmasi.
        $pembayaran = $pengajuan->pembayaran()->first();
        $this->assertSame('Dikonfirmasi', $pembayaran->status);
        $this->assertSame($this->teller->id, (int) $pembayaran->dikonfirmasi_oleh);

        // 3. Anggota & rekening tercipta dengan saldo = nominal setoran awal.
        $anggota = Anggota::findOrFail($pengajuan->anggota_id);
        $this->assertSame(Anggota::STATUS_ACTIVE, $anggota->status_anggota);

        $rekening = Rekening::where('anggota_id', $anggota->id)->sole();
        $this->assertSame($pengajuan->no_rekening_terbit, $rekening->no_rekening);
        $this->assertEqualsWithDelta($nominal, (float) $rekening->saldo, 0.01);
        $this->assertEqualsWithDelta($nominal, (float) $rekening->nominal_pembukaan, 0.01);

        // 4. Transaksi setoran awal berstatus Sukses (bukan Pending).
        $transaksi = Transaksi::where('rekening_id', $rekening->id)->sole();
        $this->assertTrue($transaksi->sukses(), 'Transaksi setoran awal wajib berstatus Sukses.');
        $this->assertSame('Debet', $transaksi->posisi, 'Uang masuk = Debet (konvensi kas).');
        $this->assertEqualsWithDelta($nominal, (float) $transaksi->jumlah, 0.01);
        $this->assertEqualsWithDelta($nominal, (float) $transaksi->saldo_setelah, 0.01);

        // 5. Jurnal terposting, seimbang, dan bukan reversal.
        $jurnal = Jurnal::where('transaksi_id', $transaksi->id)->sole();
        $this->assertFalse((bool) $jurnal->is_reversal);
        $this->assertSame('Posted', $jurnal->status);
        $this->assertEqualsWithDelta($nominal, (float) $jurnal->total_debet, 0.01);
        $this->assertEqualsWithDelta($nominal, (float) $jurnal->total_kredit, 0.01);
        $this->assertCount(2, $jurnal->detail);

        // 6. Sisi Debet = Kas Teller, sisi Kredit = Simpanan Pokok.
        $kode = $jurnal->detail->map(fn ($d) => $d->coa->kode_akun)->sort()->values()->all();
        $this->assertSame(
            collect([KodeCoa::KAS_TELLER, KodeCoa::SIMPANAN_POKOK])->sort()->values()->all(),
            $kode
        );
    }

    public function test_konfirmasi_ganda_tidak_menerbitkan_rekening_kedua(): void
    {
        $this->pastikanPrasyarat();

        $nominal = 100000.0;
        $pengajuan = $this->pengajuanMenungguKonfirmasi($nominal);

        $this->konfirmasi($pengajuan)->assertRedirect();
        $pengajuan->refresh();
        $anggotaId = $pengajuan->anggota_id;
        $rekeningId = Rekening::where('anggota_id', $anggotaId)->value('id');

        // Klik kedua: harus ditolak tanpa efek samping.
        $this->konfirmasi($pengajuan->fresh())->assertRedirect();

        $this->assertSame(1, Anggota::where('id', $anggotaId)->count());
        $this->assertSame(1, Rekening::where('anggota_id', $anggotaId)->count());
        $this->assertSame(1, Transaksi::where('rekening_id', $rekeningId)->count());
        $this->assertSame(
            1,
            Jurnal::where('transaksi_id', Transaksi::where('rekening_id', $rekeningId)->value('id'))->count()
        );

        // Saldo tidak bertambah dua kali.
        $this->assertEqualsWithDelta($nominal, (float) Rekening::find($rekeningId)->saldo, 0.01);
    }

    public function test_konfirmasi_rollback_penuh_bila_coa_kas_tidak_tersedia(): void
    {
        $this->pastikanPrasyarat();

        $pengajuan = $this->pengajuanMenungguKonfirmasi(75000.0);
        $anggotaSebelum = Anggota::where('user_id', $pengajuan->user_id)->count();
        $rekeningSebelum = Rekening::count();
        $transaksiSebelum = Transaksi::count();
        $jurnalSebelum = Jurnal::count();

        // Nonaktifkan akun kas teller → posting jurnal gagal.
        Coa::where('kode_akun', KodeCoa::KAS_TELLER)->update(['is_active' => false]);
        // Buang cache master agar perubahan langsung terbaca.
        JenisSimpanan::flush();

        try {
            $response = $this->konfirmasi($pengajuan);
        } finally {
            Coa::where('kode_akun', KodeCoa::KAS_TELLER)->update(['is_active' => true]);
            JenisSimpanan::flush();
        }

        // Kegagalan validasi jurnal adalah penolakan BISNIS, bukan gangguan
        // server: pengelola dikembalikan ke halaman pengajuan dengan pesan yang
        // bisa ditindaklanjuti (bukan halaman 500 generik).
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString('CoA', (string) session('error'));
        $this->assertStringContainsString('dibatalkan', (string) session('error'));

        $pengajuan->refresh();
        $this->assertNotSame(PengajuanRekening::STATUS_AKTIF, $pengajuan->status);
        $this->assertNull($pengajuan->anggota_id);
        $this->assertNull($pengajuan->no_rekening_terbit);
        $this->assertSame('Menunggu Konfirmasi', $pengajuan->pembayaran()->value('status'));

        $this->assertSame($anggotaSebelum, Anggota::where('user_id', $pengajuan->user_id)->count());
        $this->assertSame($rekeningSebelum, Rekening::count());
        $this->assertSame($transaksiSebelum, Transaksi::count());
        $this->assertSame($jurnalSebelum, Jurnal::count());
    }
}
