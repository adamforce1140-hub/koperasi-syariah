<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\Coa;
use App\Models\JenisSimpanan;
use App\Models\KasHarian;
use App\Models\Pembiayaan;
use App\Models\Rekening;
use App\Models\User;
use App\Services\AngsuranTransactionService;
use App\Support\Coa as KodeCoa;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BATCH 6 — Integritas status pembiayaan pada pembayaran angsuran.
 *
 * Kandidat temuan A-06: `AngsuranTransactionService::proses()` dan jalur
 * `AutodebitService::eksekusiAngsuran()` tidak memeriksa `status_pembiayaan`.
 * Akibat yang diduga: angsuran milik pembiayaan yang sudah `lunas`, `macet`,
 * `ditolak`, `dibatalkan`, atau masih pra-pencairan tetap dapat dibayar —
 * uang masuk, jurnal terbentuk, dan `sisa_pembiayaan` berubah.
 *
 * Berkas ini menuliskan PERILAKU YANG SEHARUSNYA lebih dulu. Bila gate G1/G2
 * GAGAL, temuan terkonfirmasi nyata dan baru boleh diperbaiki.
 */
class IntegritasStatusPembiayaanAngsuranTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    private AngsuranTransactionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'PS'.strtoupper(substr(uniqid(), -6));
        $this->service = app(AngsuranTransactionService::class);

        $this->pastikanCoa();
    }

    private function pastikanCoa(): void
    {
        foreach ([
            KodeCoa::KAS_TELLER,
            KodeCoa::PINJAMAN_QARDH,
            KodeCoa::SIMPANAN_SUKARELA,
        ] as $kode) {
            if (Coa::findByKode($kode) === null) {
                $this->markTestSkipped("CoA {$kode} belum tersedia pada basis data uji.");
            }
        }
    }

    private function anggota(): Anggota
    {
        // Kolom NOT NULL tanpa default pada tabel `anggota` wajib diisi eksplisit.
        return Anggota::create([
            'no_anggota' => 'AGT-'.$this->prefix,
            'nik' => '3273'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'nama_lengkap' => 'Anggota Uji Status '.$this->prefix,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Uji No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
            'status_anggota' => Anggota::STATUS_ACTIVE,
            'tanggal_gabung' => '2020-01-01',
        ]);
    }

    private function rekening(Anggota $anggota, float $saldo = 1000000.0): Rekening
    {
        return Rekening::create([
            'no_rekening' => 'RKN-'.$this->prefix.'-'.strtoupper(substr(uniqid(), -4)),
            'anggota_id' => $anggota->id,
            'akun_id' => Coa::findByKode(KodeCoa::SIMPANAN_SUKARELA)->id,
            'jenis_rekening' => JenisSimpanan::NAMA_SUKARELA,
            'akad' => 'Wadiah',
            'saldo' => $saldo,
            'batas_minimum' => 0.0,
            'tanggal_buka' => now()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);
    }

    private function teller(float $modalAwal = 1000000.0): User
    {
        $user = User::create([
            'name' => 'Teller '.$this->prefix,
            'email' => strtolower($this->prefix).'.teller@test.local',
            'password' => Hash::make('password'),
            'role' => 'teller',
            'is_active' => true,
        ]);

        $this->bukaSesiKas($user, null, $modalAwal);

        return $user;
    }

    /**
     * Pembiayaan Qardh dengan satu angsuran yang BELUM dibayar.
     *
     * @return array{0: Pembiayaan, 1: Angsuran}
     */
    private function buatPembiayaan(Anggota $anggota, string $status, float $pokok = 125000.0): array
    {
        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => 'PMB-'.$this->prefix.'-'.strtoupper(substr(uniqid(), -4)),
            'anggota_id' => $anggota->id,
            'akun_id' => Coa::query()->value('id') ?? 1,
            'akad_pembiayaan' => 'Qardh',
            'jumlah_pembiayaan' => $pokok,
            'total_pembiayaan' => $pokok,
            'total_ujroh' => 0,
            'sisa_pembiayaan' => $pokok,
            'tenor' => 1,
            'jumlah_angsuran' => $pokok,
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonth()->toDateString(),
            'status_pembiayaan' => $status,
        ]);

        $angsuran = Angsuran::create([
            'pembiayaan_id' => $pembiayaan->id,
            'angsuran_ke' => 1,
            'tanggal_jatuh_tempo' => now()->addMonth()->toDateString(),
            'pokok_angsuran' => $pokok,
            'margin_ujroh' => 0,
            'jumlah_angsuran' => $pokok,
            'sisa_pokok' => $pokok,
            'status_angsuran' => 'Belum Jatuh Tempo',
        ]);

        return [$pembiayaan, $angsuran];
    }

    /** @return array<string, array{0: string}> */
    public static function statusTidakBolehDibayar(): array
    {
        return [
            'lunas' => [Pembiayaan::STATUS_LUNAS],
            'macet' => [Pembiayaan::STATUS_MACET],
            'ditolak' => [Pembiayaan::STATUS_DITOLAK],
            'dibatalkan' => [Pembiayaan::STATUS_DIBATALKAN],
            'pending_ao (pra-pencairan)' => [Pembiayaan::STATUS_PENDING_AO],
            'pending_dps (pra-pengesahan akad)' => [Pembiayaan::STATUS_PENDING_DPS],
        ];
    }

    /**
     * G1. (A-06) Pembayaran TUNAI pada pembiayaan yang kontraknya TIDAK
     * berjalan wajib ditolak: tidak ada transaksi, tidak ada jurnal, saldo
     * pembiayaan tidak berubah, angsuran tetap belum dibayar.
     */
    #[DataProvider('statusTidakBolehDibayar')]
    public function test_pembayaran_tunai_ditolak_bila_pembiayaan_tidak_berjalan(string $status): void
    {
        $anggota = $this->anggota();
        $this->rekening($anggota);
        $teller = $this->teller();
        [$pembiayaan, $angsuran] = $this->buatPembiayaan($anggota, $status);

        $gagal = null;

        try {
            $this->service->proses($angsuran, [
                'jumlah_bayar' => 125000.0,
                'nominal_tazir' => 0,
                'metode_bayar' => 'TUNAI',
                'user_id' => $teller->id,
            ]);
        } catch (\Throwable $e) {
            $gagal = $e;
        }

        $this->assertNotNull(
            $gagal,
            "Pembayaran angsuran pada pembiayaan berstatus '{$status}' harus DITOLAK."
        );

        $this->assertSame(
            0,
            DB::table('transaksi')->where('pembiayaan_id', $pembiayaan->id)->count(),
            'Penolakan tidak boleh meninggalkan transaksi.'
        );
        $this->assertEquals(
            125000.0,
            (float) $pembiayaan->fresh()->sisa_pembiayaan,
            'sisa_pembiayaan tidak boleh berubah saat pembayaran ditolak.'
        );
        $this->assertNull(
            $angsuran->fresh()->tanggal_bayar,
            'Angsuran tidak boleh ditandai dibayar saat pembayaran ditolak.'
        );
    }

    /**
     * G2. (A-06 jalur kedua) `AutodebitService::eksekusiAngsuran()` juga tidak
     * memeriksa status pembiayaan. Bila angsuran milik pembiayaan `macet`,
     * auto-debit yang dikonfirmasi pengguna tetap tidak boleh memotong saldo.
     */
    public function test_autodebet_tidak_memotong_saldo_bila_pembiayaan_macet(): void
    {
        $anggota = $this->anggota();
        $rekening = $this->rekening($anggota, 500000.0);
        $teller = $this->teller();
        [$pembiayaan, $angsuran] = $this->buatPembiayaan($anggota, Pembiayaan::STATUS_MACET);

        $gagal = null;

        try {
            $this->service->proses($angsuran, [
                'jumlah_bayar' => 125000.0,
                'nominal_tazir' => 0,
                'metode_bayar' => 'AUTODEBET',
                'user_id' => $teller->id,
            ]);
        } catch (\Throwable $e) {
            $gagal = $e;
        }

        $this->assertNotNull($gagal, 'Autodebet pada pembiayaan macet harus DITOLAK.');
        $this->assertEquals(
            500000.0,
            (float) $rekening->fresh()->saldo,
            'Saldo anggota tidak boleh dipotong untuk pembiayaan macet.'
        );
        $this->assertSame(
            0.0,
            (float) KasHarian::hitungSaldoKasTeller($teller->id)['cash_in'],
            'Penolakan tidak boleh menambah kas fisik laci teller.'
        );
        $this->assertEquals(125000.0, (float) $pembiayaan->fresh()->sisa_pembiayaan);
    }

    /**
     * G3. Kontrol POSITIF — status yang memang berjalan WAJIB tetap dapat
     * dibayar. Mencegah perbaikan A-06 menjadi terlalu ketat dan memutus alur
     * normal pembayaran angsuran.
     */
    #[DataProvider('statusBolehDibayar')]
    public function test_pembayaran_tetap_berhasil_untuk_status_berjalan(string $status): void
    {
        $anggota = $this->anggota();
        $this->rekening($anggota);
        $teller = $this->teller();
        [$pembiayaan, $angsuran] = $this->buatPembiayaan($anggota, $status);

        $hasil = $this->service->proses($angsuran, [
            'jumlah_bayar' => 125000.0,
            'nominal_tazir' => 0,
            'metode_bayar' => 'TUNAI',
            'user_id' => $teller->id,
        ]);

        $this->assertNotNull($hasil['transaksi_id'] ?? null, "Status '{$status}' harus tetap dapat dibayar.");
        $this->assertSame('Sudah Dibayar', $angsuran->fresh()->status_angsuran);
        $this->assertEquals(0.0, (float) $pembiayaan->fresh()->sisa_pembiayaan);
        $this->assertSame(
            Pembiayaan::STATUS_LUNAS,
            $pembiayaan->fresh()->status_pembiayaan,
            'Pelunasan angsuran terakhir wajib menandai pembiayaan lunas.'
        );
    }

    /** @return array<string, array{0: string}> */
    public static function statusBolehDibayar(): array
    {
        return [
            'aktif' => [Pembiayaan::STATUS_AKTIF],
            'dicairkan' => [Pembiayaan::STATUS_DICAIRKAN],
            'restrukturisasi' => [Pembiayaan::STATUS_RESTRUKTURISASI],
        ];
    }
}
