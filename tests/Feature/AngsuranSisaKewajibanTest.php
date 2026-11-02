<?php

namespace Tests\Feature;

use App\Models\Angsuran;
use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\Pembiayaan;
use App\Models\User;
use App\Services\AngsuranTransactionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Regresi insiden sisa kewajiban:
 * Dua transaksi dengan nominal berbeda (Rp 100.000 dan Rp 50.000) terhadap
 * tagihan pokok Rp 104.166,67 menghasilkan sisa kewajiban yang sama karena
 * sistem memotong pokok penuh setiap kali pembayaran.
 */
class AngsuranSisaKewajibanTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $role = 'teller'): User
    {
        return User::firstOrCreate(
            ['email' => "{$role}.siswa@test.local"],
            [
                'name' => ucfirst($role).' Sisa Kewajiban',
                'password' => Hash::make('password'),
                'role' => $role,
            ]
        );
    }

    /**
     * Bangun pembiayaan Qardh Rp 3.000.000 / 24 angsuran (pokok Rp 125.000).
     * Tidak menyentuh data produksi.
     */
    private function buatPembiayaan(float $total = 3000000, int $tenor = 24): Pembiayaan
    {
        // anggota_id merujuk tabel `anggota`, bukan `users`.
        $anggotaId = DB::table('anggota')->value('id');

        if (! $anggotaId) {
            $anggotaId = DB::table('anggota')->insertGetId([
                'nama_lengkap' => 'Anggota Sisa Kewajiban',
                'no_anggota' => 'AGT-TEST-'.strtoupper(substr(uniqid(), -6)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => 'PMB-TEST-'.strtoupper(substr(uniqid(), -6)),
            'anggota_id' => $anggotaId,
            'akun_id' => Coa::query()->value('id') ?? 1,
            'akad_pembiayaan' => 'Qardh',
            'jumlah_pembiayaan' => $total,
            'total_pembiayaan' => $total,
            'total_ujroh' => 0,
            'sisa_pembiayaan' => $total,
            'tenor' => $tenor,
            'jumlah_angsuran' => round($total / $tenor, 2),
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths($tenor)->toDateString(),
            'status_pembiayaan' => 'Aktif',
        ]);

        $pokokPerAngsuran = round($total / $tenor, 2);

        for ($i = 1; $i <= $tenor; $i++) {
            $pokok = ($i === $tenor)
                ? round($total - ($pokokPerAngsuran * ($tenor - 1)), 2)
                : $pokokPerAngsuran;

            Angsuran::create([
                'pembiayaan_id' => $pembiayaan->id,
                'angsuran_ke' => $i,
                'tanggal_jatuh_tempo' => now()->addMonths($i)->toDateString(),
                'pokok_angsuran' => $pokok,
                'margin_ujroh' => 0,
                'jumlah_angsuran' => $pokok,
                'sisa_pokok' => $pokok,
                'status_angsuran' => 'Belum Jatuh Tempo',
            ]);
        }

        return $pembiayaan;
    }

    private function service(): AngsuranTransactionService
    {
        return app(AngsuranTransactionService::class);
    }

    /**
     * Pembiayaan Murabahah bermargin: pokok Rp 1.200.000 / 12 angsuran,
     * margin Rp 25.000 per angsuran (total Rp 1.500.000). Dipakai untuk menguji
     * jalur amortisasi Margin Ditangguhkan (1-2101) saat pembayaran ber-ta'zir.
     */
    private function buatPembiayaanMurabahah(): Pembiayaan
    {
        $anggotaId = DB::table('anggota')->value('id');

        if (! $anggotaId) {
            $anggotaId = DB::table('anggota')->insertGetId([
                'nama_lengkap' => 'Anggota Ta\'zir Murabahah',
                'no_anggota' => 'AGT-TZR-'.strtoupper(substr(uniqid(), -6)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $pokokTotal = 1200000.0;
        $marginTotal = 300000.0;
        $tenor = 12;
        $pokokPer = round($pokokTotal / $tenor, 2);
        $marginPer = round($marginTotal / $tenor, 2);

        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => 'PMB-MRB-'.strtoupper(substr(uniqid(), -6)),
            'anggota_id' => $anggotaId,
            'akun_id' => Coa::query()->value('id') ?? 1,
            'akad_pembiayaan' => 'Murabahah',
            'jumlah_pembiayaan' => $pokokTotal,
            'total_pembiayaan' => $pokokTotal + $marginTotal,
            'total_ujroh' => $marginTotal,
            'sisa_pembiayaan' => $pokokTotal + $marginTotal,
            'tenor' => $tenor,
            'jumlah_angsuran' => $pokokPer + $marginPer,
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths($tenor)->toDateString(),
            'status_pembiayaan' => 'Aktif',
        ]);

        for ($i = 1; $i <= $tenor; $i++) {
            $pokok = ($i === $tenor)
                ? round($pokokTotal - ($pokokPer * ($tenor - 1)), 2)
                : $pokokPer;

            Angsuran::create([
                'pembiayaan_id' => $pembiayaan->id,
                'angsuran_ke' => $i,
                'tanggal_jatuh_tempo' => now()->addMonths($i)->toDateString(),
                'pokok_angsuran' => $pokok,
                'margin_ujroh' => $marginPer,
                'jumlah_angsuran' => $pokok + $marginPer,
                'sisa_pokok' => $pokok,
                'status_angsuran' => 'Belum Jatuh Tempo',
            ]);
        }

        return $pembiayaan;
    }

    /**
     * K-1 (KRITIS) — Regresi: pembayaran angsuran ber-ta'zir WAJIB menghasilkan
     * jurnal yang seimbang (debet = kredit), bukan ditolak JURNAL_TIDAK_BALANCE.
     *
     * Insiden yang dicegah: alokasi ta'zir dihitung dari porsi angsuran saja,
     * sehingga kredit piutang lebih kecil dari debet kas tepat sebesar ta'zir.
     */
    public function test_pembayaran_angsuran_dengan_tazir_menghasilkan_jurnal_balance(): void
    {
        $pembiayaan = $this->buatPembiayaan(); // Qardh, pokok Rp 125.000/angsuran
        $user = $this->user();

        $ke1 = Angsuran::where('pembiayaan_id', $pembiayaan->id)->where('angsuran_ke', 1)->first();

        $saldoDkSebelum = (float) DB::table('dana_kebajikans')
            ->where('jenis', 'Penerimaan')
            ->sum('jumlah');

        $hasil = $this->service()->proses($ke1, [
            'jumlah_bayar' => 125000,
            'nominal_tazir' => 5000,
            'metode_bayar' => 'TUNAI',
            'user_id' => $user->id,
        ]);

        $jurnal = Jurnal::where('transaksi_id', $hasil['transaksi_id'])->first();

        $this->assertNotNull($jurnal, 'Jurnal angsuran ber-ta\'zir harus terbentuk (tidak ditolak).');
        $this->assertSame(
            round((float) $jurnal->total_debet, 2),
            round((float) $jurnal->total_kredit, 2),
            'Jurnal ber-ta\'zir harus balance: total debet = total kredit.'
        );

        $saldoDkSesudah = (float) DB::table('dana_kebajikans')
            ->where('jenis', 'Penerimaan')
            ->sum('jumlah');

        $this->assertSame(
            5000.0,
            round($saldoDkSesudah - $saldoDkSebelum, 2),
            'Ta\'zir yang diterima wajib masuk Dana Kebajikan tepat sebesar nominalnya (PSAK 109).'
        );

        $ke1->refresh();
        $this->assertSame('Sudah Dibayar', $ke1->status_angsuran,
            'Angsuran harus lunas karena porsi angsuran dibayar penuh (ta\'zir di luar porsi).');
        $this->assertSame(5000.0, (float) $ke1->denda,
            'Denda yang tercatat harus sebesar ta\'zir yang benar-benar diterima.');
    }

    /**
     * K-1 (KRITIS) — Regresi jalur amortisasi Margin Ditangguhkan (1-2101):
     * angsuran bermargin yang disertai ta'zir tetap menghasilkan jurnal balance.
     */
    public function test_pembayaran_angsuran_tazir_pada_akad_bermargin_tetap_balance(): void
    {
        $pembiayaan = $this->buatPembiayaanMurabahah();
        $user = $this->user();

        $ke1 = Angsuran::where('pembiayaan_id', $pembiayaan->id)->where('angsuran_ke', 1)->first();
        $tagihan = (float) $ke1->jumlah_angsuran; // pokok 100.000 + margin 25.000

        $hasil = $this->service()->proses($ke1, [
            'jumlah_bayar' => $tagihan,
            'nominal_tazir' => 7500,
            'metode_bayar' => 'TUNAI',
            'user_id' => $user->id,
        ]);

        $jurnal = Jurnal::where('transaksi_id', $hasil['transaksi_id'])->first();

        $this->assertNotNull($jurnal, 'Jurnal angsuran Murabahah ber-ta\'zir harus terbentuk.');
        $this->assertSame(
            round((float) $jurnal->total_debet, 2),
            round((float) $jurnal->total_kredit, 2),
            'Jurnal angsuran bermargin + ta\'zir harus balance (amortisasi 1-2101 tetap konsisten).'
        );

        // Amortisasi: baris DEBET 1-2101 dan KREDIT pendapatan margin sebesar margin terbayar.
        $kode = \App\Support\Coa::MARGIN_DITANGGUHKAN;
        $debetMargin = (float) $jurnal->detail()
            ->whereHas('coa', fn ($q) => $q->where('kode_akun', $kode))
            ->sum('debet');

        $this->assertEqualsWithDelta(
            25000.0,
            $debetMargin,
            0.01,
            'Margin ditangguhkan harus didebit sebesar margin yang menjadi hak (Rp 25.000).'
        );
    }

    /** Inti insiden: dua nominal berbeda harus menghasilkan sisa kewajiban berbeda. */
    public function test_pembayaran_sebagian_menghasilkan_sisa_kewajiban_berbeda(): void
    {
        $pembiayaan = $this->buatPembiayaan();
        $user = $this->user();

        $ke1 = Angsuran::where('pembiayaan_id', $pembiayaan->id)->where('angsuran_ke', 1)->first();
        $ke2 = Angsuran::where('pembiayaan_id', $pembiayaan->id)->where('angsuran_ke', 2)->first();
        $ke3 = Angsuran::where('pembiayaan_id', $pembiayaan->id)->where('angsuran_ke', 3)->first();

        // ke-1: bayar penuh
        $this->service()->proses($ke1, [
            'jumlah_bayar' => 125000,
            'metode_bayar' => 'TUNAI',
            'user_id' => $user->id,
        ]);

        // ke-2: bayar Rp 100.000 (kurang Rp 25.000)
        $this->service()->proses($ke2, [
            'jumlah_bayar' => 100000,
            'metode_bayar' => 'TUNAI',
            'user_id' => $user->id,
        ]);

        $pembiayaan->refresh();
        $sisaSetelahKe2 = (float) $pembiayaan->sisa_pembiayaan;

        // ke-3: bayar Rp 50.000 (kurang Rp 75.000)
        $this->service()->proses($ke3, [
            'jumlah_bayar' => 50000,
            'metode_bayar' => 'TUNAI',
            'user_id' => $user->id,
        ]);

        $pembiayaan->refresh();
        $sisaSetelahKe3 = (float) $pembiayaan->sisa_pembiayaan;

        // Sisa kewajiban harus MENCERMINKAN uang riil yang diterima.
        $this->assertSame(
            3000000.0 - 125000.0 - 100000.0,
            $sisaSetelahKe2,
            'Sisa setelah pembayaran Rp 100.000 harus turun Rp 100.000, bukan Rp 125.000.'
        );

        $this->assertSame(
            3000000.0 - 125000.0 - 100000.0 - 50000.0,
            $sisaSetelahKe3,
            'Sisa setelah pembayaran Rp 50.000 harus turun Rp 50.000.'
        );

        $this->assertNotSame(
            $sisaSetelahKe2,
            $sisaSetelahKe3,
            'Dua pembayaran dengan nominal berbeda TIDAK boleh menghasilkan sisa kewajiban yang sama.'
        );
    }

    /** Kekurangan pokok harus tersimpan di sisa_pokok, bukan hilang. */
    public function test_sisa_pokok_mencatat_kekurangan_yang_belum_dibayar(): void
    {
        $pembiayaan = $this->buatPembiayaan();
        $user = $this->user();

        $ke1 = Angsuran::where('pembiayaan_id', $pembiayaan->id)->where('angsuran_ke', 1)->first();

        $this->service()->proses($ke1, [
            'jumlah_bayar' => 100000,
            'metode_bayar' => 'TUNAI',
            'user_id' => $user->id,
        ]);

        $ke1->refresh();

        $this->assertSame(
            25000.0,
            (float) $ke1->sisa_pokok,
            'Kekurangan pokok Rp 25.000 harus tercatat di sisa_pokok.'
        );
        $this->assertSame(
            'Dibayar Sebagian',
            $ke1->status_angsuran,
            'Angsuran yang pokoknya belum penuh harus berstatus Dibayar Sebagian.'
        );
        $this->assertSame(100000.0, (float) $ke1->total_bayar);
    }

    /** Pembayaran penuh tidak boleh menghasilkan status Dibayar Sebagian. */
    public function test_pembayaran_penuh_berstatus_sudah_dibayar(): void
    {
        $pembiayaan = $this->buatPembiayaan();
        $user = $this->user();

        $ke1 = Angsuran::where('pembiayaan_id', $pembiayaan->id)->where('angsuran_ke', 1)->first();

        $this->service()->proses($ke1, [
            'jumlah_bayar' => 125000,
            'metode_bayar' => 'TUNAI',
            'user_id' => $user->id,
        ]);

        $ke1->refresh();

        $this->assertSame('Sudah Dibayar', $ke1->status_angsuran);
        $this->assertSame(0.0, (float) $ke1->sisa_pokok);
    }

    /** sisa_pembiayaan harus sama dengan total pembiayaan dikurangi uang riil diterima. */
    public function test_sisa_pembiayaan_konsisten_dengan_aliran_kas(): void
    {
        $pembiayaan = $this->buatPembiayaan();
        $user = $this->user();

        $nominal = [125000, 100000, 50000, 125000];

        foreach ($nominal as $i => $bayar) {
            $angsuran = Angsuran::where('pembiayaan_id', $pembiayaan->id)
                ->where('angsuran_ke', $i + 1)
                ->first();

            $this->service()->proses($angsuran, [
                'jumlah_bayar' => $bayar,
                'metode_bayar' => 'TUNAI',
                'user_id' => $user->id,
            ]);
        }

        $pembiayaan->refresh();

        $totalDibayar = array_sum($nominal);

        $this->assertSame(
            round(3000000.0 - $totalDibayar, 2),
            round((float) $pembiayaan->sisa_pembiayaan, 2),
            'sisa_pembiayaan harus = total pembiayaan - seluruh uang riil diterima.'
        );
    }

    /** Validasi otomatis: sisa_pembiayaan negatif harus terdeteksi sebagai anomali. */
    public function test_validasi_otomatis_mendeteksi_sisa_negatif(): void
    {
        $pembiayaan = $this->buatPembiayaan();
        $user = $this->user();

        // Paksa kondisi rusak: sisa kewajiban negatif.
        $pembiayaan->update(['sisa_pembiayaan' => -1000]);

        $ke1 = Angsuran::where('pembiayaan_id', $pembiayaan->id)->where('angsuran_ke', 1)->first();

        $hasil = $this->service()->verifikasiKonsistensiSisaKewajiban(
            $pembiayaan->fresh(),
            $ke1,
            125000.0,
            0.0
        );

        $this->assertNotEmpty($hasil['anomali'], 'Sisa kewajiban negatif harus dilaporkan sebagai anomali.');
    }

    /** Validasi otomatis: kekurangan pokok dengan sisa Rp 0 harus terdeteksi. */
    public function test_validasi_otomatis_mendeteksi_kekurangan_dengan_sisa_nol(): void
    {
        $pembiayaan = $this->buatPembiayaan();
        $pembiayaan->update(['sisa_pembiayaan' => 0]);

        $ke1 = Angsuran::where('pembiayaan_id', $pembiayaan->id)->where('angsuran_ke', 1)->first();

        $hasil = $this->service()->verifikasiKonsistensiSisaKewajiban(
            $pembiayaan->fresh(),
            $ke1,
            100000.0,
            25000.0
        );

        $this->assertNotEmpty(
            $hasil['anomali'],
            'Masih ada pokok belum tertagih tetapi sisa Rp 0 harus dianggap anomali.'
        );
    }

    /** Kondisi sehat tidak boleh menghasilkan anomali (tidak ada false positive). */
    public function test_kondisi_sehat_tidak_menghasilkan_anomali(): void
    {
        $pembiayaan = $this->buatPembiayaan();
        $user = $this->user();

        $ke1 = Angsuran::where('pembiayaan_id', $pembiayaan->id)->where('angsuran_ke', 1)->first();

        $this->service()->proses($ke1, [
            'jumlah_bayar' => 100000,
            'metode_bayar' => 'TUNAI',
            'user_id' => $user->id,
        ]);

        $hasil = $this->service()->verifikasiKonsistensiSisaKewajiban(
            $pembiayaan->fresh(),
            $ke1->fresh(),
            100000.0,
            25000.0
        );

        $this->assertSame([], $hasil['anomali'], 'Kondisi sehat tidak boleh dilaporkan anomali.');
    }

    /** Angsuran dibayar sebagian tetap dihitung sebagai kewajiban belum lunas. */
    public function test_dibayar_sebagian_masih_terhitung_kewajiban(): void
    {
        $pembiayaan = $this->buatPembiayaan();
        $user = $this->user();

        $ke1 = Angsuran::where('pembiayaan_id', $pembiayaan->id)->where('angsuran_ke', 1)->first();

        $this->service()->proses($ke1, [
            'jumlah_bayar' => 100000,
            'metode_bayar' => 'TUNAI',
            'user_id' => $user->id,
        ]);

        $belumDibayar = Angsuran::belumDibayar()
            ->where('pembiayaan_id', $pembiayaan->id)
            ->count();

        // 24 angsuran total; 1 sudah dibayar sebagian → 24 tetap belum lunas.
        $this->assertSame(24, $belumDibayar, 'Angsuran Dibayar Sebagian harus masuk daftar belum dibayar.');

        $this->assertSame(
            25000.0,
            Angsuran::totalSisaPokok($pembiayaan->id),
            'totalSisaPokok harus menjumlahkan kekurangan pokok.'
        );
    }
}
