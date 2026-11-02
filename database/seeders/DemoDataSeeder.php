<?php

namespace Database\Seeders;

use App\Models\Akun;
use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\Coa;
use App\Models\DanaKebajikan;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\KasHarian;
use App\Models\MasterProdukPembiayaan;
use App\Models\Pembiayaan;
use App\Models\PengajuanPembiayaan;
use App\Models\PengeluaranBeban;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\AutoJournalingService;
use App\Services\CoaResolver;
use App\Services\IkhtisarShuService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    /**
     * Konfigurasi 5 akad demo (LANGKAH 10).
     *
     * Dipakai bersama oleh `buatPembiayaan()` dan test regresi
     * `DemoDataSeederShuTest` untuk menghitung pendapatan margin bulanan
     * demo — sehingga nominal beban operasional dapat dijaga di bawahnya
     * (data demo wajib menghasilkan SHU positif, bukan rugi).
     */
    private const AKAD_DEMO = [
        ['akad' => 'Murabahah',  'jumlah' => 10_000_000, 'margin' => 12, 'tenor' => 12, 'tujuan' => 'Pembelian peralatan usaha',    'coa_piutang' => '1-2001'],
        ['akad' => 'Mudharabah', 'jumlah' => 15_000_000, 'margin' => 10, 'tenor' => 24, 'tujuan' => 'Modal usaha perdagangan',      'coa_piutang' => '1-2002'],
        ['akad' => 'Musyarakah', 'jumlah' => 20_000_000, 'margin' => 8,  'tenor' => 24, 'tujuan' => 'Perluasan usaha bersama',      'coa_piutang' => '1-2003'],
        ['akad' => 'Ijarah',     'jumlah' => 8_000_000,  'margin' => 9,  'tenor' => 12, 'tujuan' => 'Sewa peralatan produksi',      'coa_piutang' => '1-2004'],
        ['akad' => 'Qardh',      'jumlah' => 3_000_000,  'margin' => 0,  'tenor' => 6,  'tujuan' => 'Pinjaman kebajikan darurat',   'coa_piutang' => '1-2005'],
    ];

    /**
     * Beban operasional bulanan demo (LANGKAH 13).
     *
     * Totalnya SENGAJA dijaga di bawah pendapatan margin bulanan demo
     * (lihat `AKAD_DEMO`). Sebelumnya total Rp 2.000.000 jauh melebihi
     * pendapatan Rp 439.167 sehingga tutup buku menghasilkan rugi
     * Rp -1.560.833 pada akun 3-9000 — data demo tampak "koperasi rugi"
     * padahal bukan kesalahan logika penutupan.
     */
    private const BEBAN_OPERASIONAL_DEMO = [
        ['nominal' => 150_000, 'ket' => 'Beban gaji pegawai kontrak',        'ref' => 'BOP-GAJI-001',    'jenis' => 'gaji'],
        ['nominal' => 60_000,  'ket' => 'Beban listrik dan air kantor',      'ref' => 'BOP-LISTRIK-001', 'jenis' => 'utilitas'],
        ['nominal' => 40_000,  'ket' => 'Beban ATK dan perlengkapan kantor', 'ref' => 'BOP-ATK-001',     'jenis' => 'atk'],
    ];

    private AutoJournalingService $jurnal;

    private CoaResolver $resolver;

    private User $teller;

    private User $ao;

    private User $manajer;

    private User $pengurus;

    private User $dps;

    public function run(): void
    {
        // Idempoten: data demo hanya dibuat sekali. Seeder ini memakai nomor
        // referensi tetap (PMB-2026-xxxx, JRN-OPENING-001, dst.) sehingga
        // dijalankan ulang akan melanggar unique constraint.
        if (Jurnal::where('no_jurnal', 'JRN-OPENING-001')->exists()) {
            $this->command->info('DemoDataSeeder: data demo sudah ada — dilewati (idempoten).');

            return;
        }

        $this->jurnal = new AutoJournalingService;
        $this->resolver = app(CoaResolver::class);
        $this->teller = User::where('role', 'teller')->first();
        $this->ao = User::where('role', 'ao')->first();
        $this->manajer = User::where('role', 'manajer')->first();
        $this->pengurus = User::where('role', 'pengurus')->first();
        $this->dps = User::where('role', 'dps')->first();

        // ── LANGKAH 1: Kas Harian Opening (Teller) ───────────────────────────
        $this->buatKasHarian();

        // ── LANGKAH 2: Daftar 5 Anggota ──────────────────────────────────────
        $anggota = $this->buatAnggota();

        // ── LANGKAH 3: Buka Rekening Simpanan per Anggota ─────────────────────
        // Setiap anggota memperoleh rekening Pokok, Wajib, dan Sukarela yang
        // terpisah agar jenis_rekening benar-benar mencerminkan jenis simpanan.
        $rekening = $this->buatRekening($anggota);

        // ── LANGKAH 4: Jurnal Opening Balance (Kas / Dana Cadangan) ───────────
        $this->jurnalOpeningBalance();

        // ── LANGKAH 5: Setoran Simpanan Pokok (3-1001) ────────────────────────
        $this->setorSimpananPokok($anggota, $rekening['pokok']);

        // ── LANGKAH 6: Setoran Simpanan Wajib (3-1002 Ekuitas Modal) ──────────
        $this->setorSimpananWajib($anggota, $rekening['wajib']);

        // ── LANGKAH 7: Setoran Simpanan Sukarela (2-1001) ─────────────────────
        $this->setorSimpananSukarela($anggota, $rekening['sukarela']);

        // ── LANGKAH 8: Penarikan Simpanan Sukarela ────────────────────────────
        $this->tarikSimpananSukarela($anggota, $rekening['sukarela']);

        // ── LANGKAH 9: Transfer Kas → Bank (1-1001 → 1-1002) ─────────────────
        $this->transferKasKeBank();

        // ── LANGKAH 9b: Master Produk Pembiayaan (5 akad) ────────────────────
        // Tanpa langkah ini `pengajuan_pembiayaan.produk_id` selalu NULL dan
        // pemetaan akad→CoA per produk tidak pernah terpakai pada data demo.
        $this->buatProdukPembiayaan();

        // ── LANGKAH 10: 5 Pembiayaan (5 Akad berbeda) + Pencairan ─────────────
        $pembiayaan = $this->buatPembiayaan($anggota, $rekening['sukarela']);

        // ── LANGKAH 11: Bayar Angsuran (semua akad) ───────────────────────────
        $this->bayarAngsuran($pembiayaan);

        // ── LANGKAH 12: Biaya Administrasi (4-2001) ───────────────────────────
        $this->catatBiayaAdmin();

        // ── LANGKAH 13: Beban Operasional (5-1001) ───────────────────────────
        $this->catatBebanOperasional();

        // ── LANGKAH 14: Dana Kebajikan / Ta'zir (2-3001) ─────────────────────
        $this->catatDanaKebajikan($anggota, $rekening['sukarela']);

        // ── LANGKAH 15: Tutup Buku + Alokasi SHU (kepala 4 & 5 → 3-9000) ─────
        $this->alokasiShu();

        // ── LANGKAH 16: Simpanan Berjangka / Deposito Mudharabah (2-1003) ────
        $this->buatDeposito($anggota, $rekening['sukarela']);

        // ── LANGKAH 17: Kas Harian Closing ────────────────────────────────────
        $this->tutupKasHarian();

        $this->command->info('DemoDataSeeder selesai — semua CoA terisi, semua menu aktif.');
    }

    // =========================================================================
    // LANGKAH 1: KAS HARIAN OPENING
    // =========================================================================
    private function buatKasHarian(): void
    {
        KasHarian::firstOrCreate(
            ['user_id' => $this->teller->id, 'tanggal' => now()->toDateString()],
            [
                'modal_awal' => 10_000_000,
                'tambah_modal' => null,
                'catatan_opening' => 'Opening kas demo',
                'waktu_opening' => now(),
                'status_closing' => null,
            ]
        );
    }

    // =========================================================================
    // LANGKAH 2: BUAT 5 ANGGOTA
    // =========================================================================
    private function buatAnggota(): array
    {
        $data = [
            ['nama' => 'Budi Santoso',   'nik' => '3171010101800001', 'hp' => '081111111111', 'pekerjaan' => 'Pedagang'],
            ['nama' => 'Siti Aminah',    'nik' => '3171010202850002', 'hp' => '081222222222', 'pekerjaan' => 'Guru'],
            ['nama' => 'Ahmad Fauzi',    'nik' => '3171010303900003', 'hp' => '081333333333', 'pekerjaan' => 'Wiraswasta'],
            ['nama' => 'Dewi Rahayu',    'nik' => '3171010404750004', 'hp' => '081444444444', 'pekerjaan' => 'Ibu Rumah Tangga'],
            ['nama' => 'Hasan Basri',    'nik' => '3171010505820005', 'hp' => '081555555555', 'pekerjaan' => 'PNS'],
        ];

        $result = [];
        foreach ($data as $i => $d) {
            $urut = str_pad($i + 1, 4, '0', STR_PAD_LEFT);
            $result[] = Anggota::firstOrCreate(
                ['no_anggota' => 'ANG-2026-'.$urut],
                [
                    'no_cif' => 'CIF-20260101-'.$urut,
                    'user_id' => null,
                    'nik' => $d['nik'],
                    'nama_lengkap' => $d['nama'],
                    'tempat_lahir' => 'Jakarta',
                    'tanggal_lahir' => '1990-01-01',
                    'jenis_kelamin' => $i % 2 === 0 ? 'Laki-laki' : 'Perempuan',
                    'status_perkawinan' => 'Menikah',
                    'alamat' => 'Jl. Demo No. '.($i + 1),
                    'rt_rw' => '001/001',
                    'kelurahan' => 'Kelurahan Demo',
                    'kecamatan' => 'Kecamatan Demo',
                    'kota' => 'Jakarta',
                    'provinsi' => 'DKI Jakarta',
                    'kode_pos' => '10110',
                    'no_hp' => $d['hp'],
                    'email' => strtolower(str_replace(' ', '.', $d['nama'])).'@demo.test',
                    'pekerjaan' => $d['pekerjaan'],
                    'nama_ibu_kandung' => 'Ibu Demo',
                    'status_anggota' => Anggota::STATUS_ACTIVE,
                    'tanggal_gabung' => now()->subMonths(6)->toDateString(),
                    'simpanan_pokok' => 500_000,
                    'simpanan_wajib' => 100_000,
                ]);
        }

        return $result;
    }

    // =========================================================================
    // LANGKAH 3: BUKA REKENING
    // =========================================================================
    private function buatRekening(array $anggota): array
    {
        // Akun simpanan dari tabel akun (1131 Pokok, 1132 Wajib, 1133 Sukarela)
        $akunPokok = Akun::where('kode_akun', '1131')->first();
        $akunWajib = Akun::where('kode_akun', '1132')->first();
        $akunSukarela = Akun::where('kode_akun', '1133')->first()
            ?? Akun::where('jenis_akun', 'Detail')->first();

        $sukarela = $pokok = $wajib = [];
        foreach ($anggota as $i => $ang) {
            $urut = str_pad($i + 1, 6, '0', STR_PAD_LEFT);

            // Rekening Pokok & Wajib dipisah agar setoran masing-masing jenis
            // tidak lagi menumpang pada rekening berlabel Sukarela.
            $pokok[] = Rekening::create([
                'no_rekening' => 'POK-2026-'.$urut,
                'anggota_id' => $ang->id,
                'akun_id' => $akunPokok?->id ?? 1,
                'jenis_rekening' => 'Simpanan Pokok',
                'akad' => 'Wadiah',
                'saldo' => 0,
                'batas_minimum' => 0,
                'tanggal_buka' => now()->subMonths(6)->toDateString(),
                'status_rekening' => 'Aktif',
                'keterangan' => 'Rekening simpanan pokok demo',
            ]);

            $wajib[] = Rekening::create([
                'no_rekening' => 'WAJ-2026-'.$urut,
                'anggota_id' => $ang->id,
                'akun_id' => $akunWajib?->id ?? 1,
                'jenis_rekening' => 'Simpanan Wajib',
                'akad' => 'Wadiah',
                'saldo' => 0,
                'batas_minimum' => 0,
                'tanggal_buka' => now()->subMonths(6)->toDateString(),
                'status_rekening' => 'Aktif',
                'keterangan' => 'Rekening simpanan wajib demo',
            ]);

            $sukarela[] = Rekening::create([
                'no_rekening' => '100-2026-'.$urut,
                'anggota_id' => $ang->id,
                'akun_id' => $akunSukarela?->id ?? 1,
                'jenis_rekening' => 'Simpanan Sukarela',
                'akad' => 'Wadiah',
                'saldo' => 0,
                'batas_minimum' => 50_000,
                'tanggal_buka' => now()->subMonths(6)->toDateString(),
                'status_rekening' => 'Aktif',
                'keterangan' => 'Rekening tabungan demo',
            ]);
        }

        return ['pokok' => $pokok, 'wajib' => $wajib, 'sukarela' => $sukarela];
    }

    // =========================================================================
    // LANGKAH 4: JURNAL OPENING BALANCE
    // D: 1-1001 Kas Teller | K: 3-2001 Dana Cadangan
    // =========================================================================
    private function jurnalOpeningBalance(): void
    {
        $kasCoa = Coa::findByKode('1-1001');
        $cadCoa = Coa::findByKode('3-2001');
        if (! $kasCoa || ! $cadCoa) {
            return;
        }

        $jurnal = Jurnal::create([
            'no_jurnal' => 'JRN-OPENING-001',
            'tanggal_jurnal' => now()->subMonths(6)->toDateString(),
            'jenis_jurnal' => 'Jurnal Penyesuaian',
            'sumber' => 'Opening Balance',
            'no_ref' => 'OPENING-3-2001',
            'keterangan' => 'Saldo awal koperasi — Dana Cadangan',
            'total_debet' => 5_000_000,
            'total_kredit' => 5_000_000,
            'is_balance' => true,
            'status' => 'Posted',
            'posted_at' => now()->subMonths(6),
            'user_posted' => $this->pengurus->id,
            'user_id' => $this->pengurus->id,
        ]);

        JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $kasCoa->id, 'baris' => 1, 'debet' => 5_000_000, 'kredit' => 0, 'keterangan' => 'Kas Teller']);
        JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $cadCoa->id, 'baris' => 2, 'debet' => 0, 'kredit' => 5_000_000, 'keterangan' => 'Dana Cadangan Koperasi']);
    }

    // =========================================================================
    // LANGKAH 5: SETORAN SIMPANAN POKOK (3-1001)
    // D: 1-1001 Kas | K: 3-1001 Simpanan Pokok
    // =========================================================================
    private function setorSimpananPokok(array $anggota, array $rekening): void
    {
        $kasCoa = Coa::findByKode('1-1001');
        $pokokCoa = Coa::findByKode('3-1001');
        if (! $kasCoa || ! $pokokCoa) {
            return;
        }

        foreach ($anggota as $i => $ang) {
            $rek = $rekening[$i];
            $nominal = 500_000;

            // Update saldo rekening lebih dulu agar saldo_setelah mencerminkan
            // saldo nyata setelah mutasi (konvensi: Debet = uang masuk).
            $rek->increment('saldo', $nominal);
            $rek->refresh();

            // Transaksi
            $trx = Transaksi::create([
                'no_transaksi' => 'TRX-SP-'.str_pad($i + 1, 4, '0', STR_PAD_LEFT),
                'waktu_transaksi' => now()->subMonths(6),
                'rekening_id' => $rek->id,
                'anggota_id' => $ang->id,
                'jenis_transaksi' => 'Simpanan Pokok',
                'posisi' => 'Debet',
                'jumlah' => $nominal,
                'saldo_setelah' => (float) $rek->saldo,
                'user_id' => $this->teller->id,
                'sumber_transaksi' => 'Teller',
                'status_transaksi' => 'Sukses',
                'status' => 'Sukses',
                'catatan' => 'Setoran simpanan pokok awal keanggotaan',
            ]);

            // Jurnal D:Kas / K:Simpanan Pokok
            $jurnal = Jurnal::create([
                'no_jurnal' => 'JRN-SP-'.date('Ymd').'-'.str_pad($i + 1, 3, '0', STR_PAD_LEFT),
                'tanggal_jurnal' => now()->subMonths(6)->toDateString(),
                'jenis_jurnal' => 'Jurnal Otomatis',
                'transaksi_id' => $trx->id,
                'sumber' => 'Simpanan Pokok',
                'keterangan' => 'Setoran Simpanan Pokok - '.$ang->nama_lengkap,
                'total_debet' => $nominal,
                'total_kredit' => $nominal,
                'is_balance' => true,
                'status' => 'Posted',
                'posted_at' => now(),
                'user_posted' => $this->teller->id,
                'user_id' => $this->teller->id,
            ]);

            /* Atribusi `rekening_id` HANYA pada baris akun simpanan. Baris kas
             | bukan milik satu rekening anggota mana pun — memberinya
             | `rekening_id` simpanan membuat subledger per rekening menyesatkan
             | (inilah yang ditegakkan `PostingJurnal::rekeningIdUntukCoa()`). */
            JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $kasCoa->id,   'baris' => 1, 'debet' => $nominal, 'kredit' => 0,       'anggota_id' => $ang->id, 'rekening_id' => null,   'keterangan' => 'Kas Teller']);
            JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $pokokCoa->id, 'baris' => 2, 'debet' => 0,       'kredit' => $nominal, 'anggota_id' => $ang->id, 'rekening_id' => $rek->id, 'keterangan' => 'Simpanan Pokok Anggota']);
        }
    }

    // =========================================================================
    // LANGKAH 6: SETORAN SIMPANAN WAJIB (3-1002 Ekuitas Modal Anggota)
    // Diselesaikan melalui MandatorySavingsService::akunSimpananWajib() —
    // PSAK 101: Simpanan Wajib disajikan sebagai MODAL ANGGOTA, bukan kewajiban.
    // =========================================================================
    private function setorSimpananWajib(array $anggota, array $rekening): void
    {
        foreach ($anggota as $i => $ang) {
            $rek = $rekening[$i];
            $nominal = 100_000;

            $rek->increment('saldo', $nominal);
            $rek->refresh();

            $trx = Transaksi::create([
                'no_transaksi' => 'TRX-SW-'.str_pad($i + 1, 4, '0', STR_PAD_LEFT),
                'waktu_transaksi' => now()->subMonths(5),
                'rekening_id' => $rek->id,
                'anggota_id' => $ang->id,
                'jenis_transaksi' => 'Simpanan Wajib',
                'posisi' => 'Debet',
                'jumlah' => $nominal,
                'saldo_setelah' => (float) $rek->saldo,
                'user_id' => $this->teller->id,
                'sumber_transaksi' => 'Teller',
                'status_transaksi' => 'Sukses',
                'status' => 'Sukses',
                'catatan' => 'Setoran simpanan wajib bulanan',
            ]);

            $this->jurnal->jurnalSetorSimpananWajib(
                $nominal,
                'Simpanan Wajib - '.$ang->nama_lengkap,
                $this->teller->id,
                $trx->id,
                $rek->id,
                $ang->id
            );
        }
    }

    // =========================================================================
    // LANGKAH 7: SETORAN SIMPANAN SUKARELA (2-1001)
    // =========================================================================
    private function setorSimpananSukarela(array $anggota, array $rekening): void
    {
        $nominals = [2_000_000, 3_500_000, 1_500_000, 5_000_000, 2_500_000];

        foreach ($anggota as $i => $ang) {
            $rek = $rekening[$i];
            $nominal = $nominals[$i];

            $rek->increment('saldo', $nominal);
            $rek->refresh();

            $trx = Transaksi::create([
                'no_transaksi' => 'TRX-SS-'.str_pad($i + 1, 4, '0', STR_PAD_LEFT),
                'waktu_transaksi' => now()->subMonths(4),
                'rekening_id' => $rek->id,
                'anggota_id' => $ang->id,
                'jenis_transaksi' => 'Setoran Tunai',
                'posisi' => 'Debet',
                'jumlah' => $nominal,
                'saldo_setelah' => (float) $rek->saldo,
                'user_id' => $this->teller->id,
                'sumber_transaksi' => 'Teller',
                'status_transaksi' => 'Sukses',
                'status' => 'Sukses',
                'catatan' => 'Setoran simpanan sukarela',
            ]);

            $this->jurnal->jurnalSetorTunai(
                $nominal,
                'Setoran Sukarela - '.$ang->nama_lengkap,
                $this->teller->id,
                $trx->id,
                $rek->id,
                $ang->id
            );
        }
    }

    // =========================================================================
    // LANGKAH 8: PENARIKAN SIMPANAN SUKARELA
    // =========================================================================
    private function tarikSimpananSukarela(array $anggota, array $rekening): void
    {
        // Hanya anggota pertama dan ketiga yang melakukan penarikan
        $tarik = [0 => 500_000, 2 => 300_000];

        foreach ($tarik as $i => $nominal) {
            $ang = $anggota[$i];
            $rek = $rekening[$i];

            $rek->decrement('saldo', $nominal);
            $rek->refresh();

            $trx = Transaksi::create([
                'no_transaksi' => 'TRX-TK-'.str_pad($i + 1, 4, '0', STR_PAD_LEFT),
                'waktu_transaksi' => now()->subMonths(3),
                'rekening_id' => $rek->id,
                'anggota_id' => $ang->id,
                'jenis_transaksi' => 'Penarikan Tunai',
                'posisi' => 'Kredit',
                'jumlah' => $nominal,
                'saldo_setelah' => (float) $rek->saldo,
                'user_id' => $this->teller->id,
                'sumber_transaksi' => 'Teller',
                'status_transaksi' => 'Sukses',
                'status' => 'Sukses',
                'catatan' => 'Penarikan simpanan sukarela',
            ]);

            $this->jurnal->jurnalTarikTunai(
                $nominal,
                'Penarikan Sukarela - '.$ang->nama_lengkap,
                $this->teller->id,
                $trx->id,
                $rek->id,
                $ang->id
            );
        }
    }

    // =========================================================================
    // LANGKAH 9: TRANSFER KAS → BANK (1-1001 → 1-1002)
    // =========================================================================
    private function transferKasKeBank(): void
    {
        $nominal = 3_000_000;
        $kasCoa = Coa::findByKode('1-1001');
        $bankCoa = Coa::findByKode('1-1002');
        if (! $kasCoa || ! $bankCoa) {
            return;
        }

        $jurnal = Jurnal::create([
            'no_jurnal' => 'JRN-BANK-'.date('Ymd').'-001',
            'tanggal_jurnal' => now()->subMonths(3)->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'Transfer Internal',
            'no_ref' => 'TRF-KAS-BANK-001',
            'keterangan' => 'Transfer kas teller ke rekening bank koperasi',
            'total_debet' => $nominal,
            'total_kredit' => $nominal,
            'is_balance' => true,
            'status' => 'Posted',
            'posted_at' => now(),
            'user_posted' => $this->manajer->id,
            'user_id' => $this->manajer->id,
        ]);

        JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $bankCoa->id, 'baris' => 1, 'debet' => $nominal, 'kredit' => 0,       'keterangan' => 'Bank / Rekening Utama Koperasi']);
        JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $kasCoa->id,  'baris' => 2, 'debet' => 0,       'kredit' => $nominal, 'keterangan' => 'Kas Teller']);
    }

    // =========================================================================
    // LANGKAH 9b: MASTER PRODUK PEMBIAYAAN (5 AKAD)
    // =========================================================================
    /**
     * Membuat 5 produk pembiayaan yang memetakan satu akad ke satu produk.
     *
     * Wajib dijalankan SEBELUM buatPembiayaan() karena method tersebut
     * memetakan `akad_syariah` → `produk_id`; tanpa baris produk,
     * `pengajuan_pembiayaan.produk_id` akan selalu NULL dan pemetaan
     * akad → CoA per produk tidak pernah terpakai.
     *
     * Produk bagi hasil (Mudharabah/Musyarakah) wajib mencantumkan nisbah
     * anggota + koperasi yang berjumlah tepat 100% — lihat
     * MasterProdukPembiayaan::nilaiBisnisTidakValid().
     */
    private function buatProdukPembiayaan(): void
    {
        $produk = [
            [
                'kode_produk' => 'PRD-MRB-001',
                'nama_produk' => 'Pembiayaan Murabahah Peralatan Usaha',
                'akad_syariah' => 'Murabahah',
                'min_plafon' => 1_000_000,
                'max_plafon' => 50_000_000,
                'margin_persen' => 12,
                'nisbah_anggota' => null,
                'nisbah_koperasi' => null,
                'biaya_admin' => 50_000,
                'min_bulan_anggota' => 6,
                'min_tenor_bulan' => 6,
                'max_tenor_bulan' => 36,
            ],
            [
                'kode_produk' => 'PRD-MDR-001',
                'nama_produk' => 'Pembiayaan Mudharabah Modal Usaha',
                'akad_syariah' => 'Mudharabah',
                'min_plafon' => 1_000_000,
                'max_plafon' => 100_000_000,
                'margin_persen' => 10,
                'nisbah_anggota' => 60,
                'nisbah_koperasi' => 40,
                'biaya_admin' => 75_000,
                'min_bulan_anggota' => 12,
                'min_tenor_bulan' => 12,
                'max_tenor_bulan' => 48,
            ],
            [
                'kode_produk' => 'PRD-MSR-001',
                'nama_produk' => 'Pembiayaan Musyarakah Usaha Bersama',
                'akad_syariah' => 'Musyarakah',
                'min_plafon' => 2_000_000,
                'max_plafon' => 150_000_000,
                'margin_persen' => 8,
                'nisbah_anggota' => 55,
                'nisbah_koperasi' => 45,
                'biaya_admin' => 100_000,
                'min_bulan_anggota' => 12,
                'min_tenor_bulan' => 12,
                'max_tenor_bulan' => 60,
            ],
            [
                'kode_produk' => 'PRD-IJR-001',
                'nama_produk' => 'Pembiayaan Ijarah Sewa Peralatan',
                'akad_syariah' => 'Ijarah',
                'min_plafon' => 1_000_000,
                'max_plafon' => 40_000_000,
                'margin_persen' => 9,
                'nisbah_anggota' => null,
                'nisbah_koperasi' => null,
                'biaya_admin' => 50_000,
                'min_bulan_anggota' => 6,
                'min_tenor_bulan' => 6,
                'max_tenor_bulan' => 24,
            ],
            [
                'kode_produk' => 'PRD-QRD-001',
                'nama_produk' => 'Pembiayaan Qardh Kebajikan Darurat',
                'akad_syariah' => 'Qardh',
                'min_plafon' => 500_000,
                'max_plafon' => 10_000_000,
                'margin_persen' => 0,
                'nisbah_anggota' => null,
                'nisbah_koperasi' => null,
                'biaya_admin' => 0,
                'min_bulan_anggota' => 3,
                'min_tenor_bulan' => 3,
                'max_tenor_bulan' => 12,
            ],
        ];

        foreach ($produk as $data) {
            // firstOrCreate menjaga idempotensi bila seeder dijalankan ulang
            // pada basis data yang belum memiliki penanda JRN-OPENING-001.
            $model = MasterProdukPembiayaan::firstOrCreate(
                ['akad_syariah' => $data['akad_syariah']],
                $data + [
                    'status_aktif' => true,
                    'status_sertifikasi_dps' => MasterProdukPembiayaan::SERTIFIKASI_VALID,
                    'disahkan_oleh' => $this->dps?->id,
                    'tanggal_disahkan' => now()->subMonths(6)->toDateString(),
                    'catatan_dps' => 'Produk demo sesuai prinsip syariah',
                ]
            );

            $galat = $model->nilaiBisnisTidakValid();
            if ($galat !== []) {
                throw new \RuntimeException(
                    "Produk {$data['kode_produk']} tidak valid: ".implode(' ', $galat)
                );
            }
        }
    }

    // =========================================================================
    // LANGKAH 10: 5 PEMBIAYAAN (5 AKAD) + PENCAIRAN
    // =========================================================================
    private function buatPembiayaan(array $anggota, array $rekening): array
    {
        $produkMap = MasterProdukPembiayaan::pluck('id', 'akad_syariah')->toArray();

        // Akun pembiayaan dari tabel akun (kode 121-125)
        $akunPmb = [
            'Murabahah' => Akun::where('kode_akun', '121')->first()?->id,
            'Mudharabah' => Akun::where('kode_akun', '122')->first()?->id,
            'Musyarakah' => Akun::where('kode_akun', '124')->first()?->id,
            'Ijarah' => Akun::where('kode_akun', '125')->first()?->id,
            'Qardh' => Akun::where('kode_akun', '123')->first()?->id,
        ];
        $akunDefault = Akun::where('jenis_akun', 'Detail')->first()?->id ?? 1;

        $configs = self::AKAD_DEMO;

        $result = [];
        foreach ($configs as $i => $cfg) {
            $ang = $anggota[$i];
            $rek = $rekening[$i];
            $jumlah = $cfg['jumlah'];
            $total = $jumlah + ($jumlah * $cfg['margin'] / 100);
            $angsuranPerBulan = $total / $cfg['tenor'];
            $produkId = $produkMap[$cfg['akad']] ?? null;

            /* PSAK 108 — Margin ditangguhkan hanya sah pada akad yang akunnya
             | benar-benar terpetakan (Murabahah dkk). Pemetaan diambil dari
             | CoaResolver, bukan dari nama akad yang ditanam di seeder, agar
             | penambahan akad bermargin di masa depan tidak perlu ubah kode.
             | Akad tanpa akun margin (Mudharabah/Musyarakah/Ijarah/Qardh)
             | WAJIB bernilai 0 — bila dipaksa > 0, AutoJournalingService akan
             | membatalkan seluruh jurnal pencairannya. */
            $marginDitangguhkan = $this->resolver->kodeMarginDitangguhkanByAkad($cfg['akad']) !== null
                ? $total - $jumlah
                : 0.0;

            // Pengajuan Pembiayaan
            $pengajuan = PengajuanPembiayaan::create([
                'no_pengajuan' => 'PJN-2026-'.str_pad($i + 1, 4, '0', STR_PAD_LEFT),
                'anggota_id' => $ang->id,
                'produk_id' => $produkId,
                'jenis_akad' => $cfg['akad'],
                'margin_persen' => $cfg['margin'],
                'jumlah_pengajuan' => $jumlah,
                'total_pembiayaan' => $total,
                'angsuran_per_bulan' => $angsuranPerBulan,
                'tenor' => $cfg['tenor'],
                'tujuan' => $cfg['tujuan'],
                'agunan' => 'BPKB Kendaraan',
                'status' => 'Disetujui',
                'ao_id' => $this->ao->id,
                'created_by' => $this->ao->id,
                'direview_oleh' => $this->manajer->id,
                'tanggal_direview' => now()->subMonths(5),
                'catatan_manajer' => 'Disetujui untuk pencairan',
                'disetujui_oleh' => $this->pengurus->id,
                'tanggal_disetujui' => now()->subMonths(5),
                'catatan_pengurus' => 'Layak sesuai prinsip syariah',
                'penghasilan_bulanan' => 5_000_000,
                'pengeluaran_bulanan' => 2_000_000,
                'step_terakhir' => 5,
            ]);

            // Record Pembiayaan
            $pmb = Pembiayaan::create([
                'no_pembiayaan' => 'PMB-2026-'.str_pad($i + 1, 4, '0', STR_PAD_LEFT),
                'anggota_id' => $ang->id,
                'akun_id' => $akunPmb[$cfg['akad']] ?? $akunDefault,
                'akad_pembiayaan' => $cfg['akad'],
                'tujuan_pembiayaan' => $cfg['tujuan'],
                'jumlah_pembiayaan' => $jumlah,
                'margin_nisbah' => $cfg['margin'],
                'total_pembiayaan' => $total,
                'tenor' => $cfg['tenor'],
                'frekuensi_angsuran' => 'Bulanan',
                'jumlah_angsuran' => $angsuranPerBulan,
                'tanggal_akad' => now()->subMonths(5)->toDateString(),
                'tanggal_cair' => now()->subMonths(5)->toDateString(),
                'tanggal_jatuh_tempo' => now()->addMonths($cfg['tenor'] - 5)->toDateString(),
                'rekening_id' => $rek->id,
                'status_pembiayaan' => 'Aktif',
                'sisa_pembiayaan' => $total - $angsuranPerBulan, // sudah 1x angsuran
                'agunan' => 'BPKB Kendaraan',
                'user_approved' => $this->pengurus->id,
                'direview_oleh' => $this->manajer->id,
                'disetujui_oleh' => $this->pengurus->id,
                'disahkan_oleh' => $this->dps->id,
                'tanggal_disahkan' => now()->subMonths(5)->toDateString(),
                'catatan_dps' => 'Akad sesuai prinsip syariah',
                'tgl_pencairan' => now()->subMonths(5)->toDateString(),
                'tgl_dicairkan' => now()->subMonths(5),
            ]);

            // Update pengajuan dengan pembiayaan_id
            $pengajuan->update(['pembiayaan_id' => $pmb->id]);

            // Transaksi Pencairan
            $trxCair = Transaksi::create([
                'no_transaksi' => 'TRX-CAIR-'.str_pad($i + 1, 4, '0', STR_PAD_LEFT),
                'waktu_transaksi' => now()->subMonths(5),
                'rekening_id' => $rek->id,
                'anggota_id' => $ang->id,
                'pembiayaan_id' => $pmb->id,
                'jenis_transaksi' => 'Pencairan Pembiayaan',
                'posisi' => 'Debet',
                'jumlah' => $jumlah,
                'saldo_setelah' => $rek->fresh()->saldo + $jumlah,
                'user_id' => $this->teller->id,
                'sumber_transaksi' => 'Teller',
                'status_transaksi' => 'Sukses',
                'status' => 'Sukses',
                'catatan' => 'Pencairan pembiayaan akad '.$cfg['akad'],
            ]);

            $rek->increment('saldo', $jumlah);

            // Jurnal Pencairan: D: Piutang (pokok+margin) / K: Sumber Dana (pokok)
            //                  / K: Margin Ditangguhkan (margin, hanya akad bermargin)
            $this->jurnal->jurnalPencairan(
                $jumlah,
                $cfg['akad'],
                'Pencairan '.$cfg['akad'].' - '.$ang->nama_lengkap,
                $this->teller->id,
                $trxCair->id,
                $pmb->id,
                $rek->id,
                $ang->id,
                $marginDitangguhkan
            );

            // Buat jadwal angsuran
            $this->buatJadwalAngsuran($pmb, $cfg, $ang);

            $result[] = $pmb;
        }

        return $result;
    }

    private function buatJadwalAngsuran(Pembiayaan $pmb, array $cfg, Anggota $ang): void
    {
        $jumlah = $cfg['jumlah'];
        $total = $pmb->total_pembiayaan;
        $tenor = $cfg['tenor'];
        $angPerBln = $total / $tenor;
        $pokokPerBln = $jumlah / $tenor;
        $marginPerBln = ($jumlah * $cfg['margin'] / 100) / $tenor;
        $sisaPokok = (float) $jumlah;

        /* Jadwal mengikuti tanggal pencairan (`now() - 5 bulan`, lihat
         | `tanggal_cair` & `tanggal_akad` di atas): angsuran ke-1 jatuh tempo
         | 1 bulan setelah cair, dibayar `now() - 4 bulan` (tepat saat jatuh
         | tempo). Sisa tenor berjalan melewati hari ini sehingga data demo
         | memuat angsuran yang belum jatuh tempo, jatuh tempo bulan ini, dan
         | menunggak — bukan semua baris menunggak. */
        $tanggalCair = now()->subMonths(5)->startOfDay();

        for ($ke = 1; $ke <= $tenor; $ke++) {
            $jatuhTempo = $tanggalCair->copy()->addMonths($ke);
            $sisaPokok -= $pokokPerBln;

            /* `total_bayar` HANYA terisi untuk angsuran yang benar-benar sudah
             | dibayar. Sebelumnya seeder mengisinya untuk SEMUA baris jadwal,
             | sehingga angsuran yang belum dibayar tampil seolah sudah lunas
             | (portal nasabah memakai `total_bayar` sebagai nominal tagihan)
             | dan `AngsuranController` menganggapnya terhubung transaksi.
             | Angsuran ke-1 dibayar di LANGKAH 11 (`bayarAngsuran()`), yang
             | juga memperbarui `total_bayar`, jadi nilai awal cukup 0.
             | Pola ini sama dengan pembuatan jadwal di DisbursementService
             | dan RestrukturisasiService. */
            $sudahDibayar = $ke === 1;

            Angsuran::create([
                'pembiayaan_id' => $pmb->id,
                'angsuran_ke' => $ke,
                'tanggal_jatuh_tempo' => $jatuhTempo->toDateString(),
                'pokok_angsuran' => $pokokPerBln,
                'margin_ujroh' => $marginPerBln,
                'jumlah_angsuran' => $angPerBln,
                'denda' => 0,
                'total_bayar' => 0,
                'sisa_pokok' => max(0, $sisaPokok),
                // Status mengikuti tanggal jatuh tempo (bukan indeks tenor):
                // angsuran lewat JT wajib 'Terlambat', hari ini 'Jatuh Tempo'.
                'status_angsuran' => $sudahDibayar
                    ? 'Sudah Dibayar'
                    : $this->statusJadwal($jatuhTempo),
                'tanggal_bayar' => $sudahDibayar ? $jatuhTempo->toDateString() : null,
                'user_id' => $sudahDibayar ? $this->teller->id : null,
            ]);
        }
    }

    /**
     * Status dasar sebuah jadwal yang belum dibayar, berdasarkan tanggalnya.
     * Harus sejalan dengan `angsuran:sinkronkan-status` dan accessor
     * `Angsuran::status_dinamis`.
     */
    private function statusJadwal(Carbon $jatuhTempo): string
    {
        $jatuhTempo = $jatuhTempo->copy()->startOfDay();
        $hariIni = now()->startOfDay();

        if ($jatuhTempo->lt($hariIni)) {
            return 'Terlambat';
        }

        return $jatuhTempo->equalTo($hariIni) ? 'Jatuh Tempo' : 'Belum Jatuh Tempo';
    }

    // =========================================================================
    // LANGKAH 11: BAYAR ANGSURAN (semua 5 akad — angsuran ke-1 sudah terbayar)
    // =========================================================================
    private function bayarAngsuran(array $pembiayaan): void
    {
        $akads = ['Murabahah', 'Mudharabah', 'Musyarakah', 'Ijarah', 'Qardh'];

        foreach ($pembiayaan as $i => $pmb) {
            $angsuran = Angsuran::where('pembiayaan_id', $pmb->id)
                ->where('angsuran_ke', 1)
                ->first();

            if (! $angsuran) {
                continue;
            }

            $pokok = (float) $angsuran->pokok_angsuran;
            $margin = (float) $angsuran->margin_ujroh;
            $akad = $akads[$i];

            // Angsuran dibayar TUNAI: tidak memutasi rekening simpanan anggota,
            // jadi rekening_id & saldo_setelah dikosongkan (sama seperti
            // AngsuranTransactionService untuk metode non-autodebet).
            $trx = Transaksi::create([
                'no_transaksi' => 'TRX-ANG-'.str_pad($i + 1, 4, '0', STR_PAD_LEFT),
                'waktu_transaksi' => now()->subMonths(4),
                'rekening_id' => null,
                'anggota_id' => $pmb->anggota_id,
                'pembiayaan_id' => $pmb->id,
                'jenis_transaksi' => 'Bayar Angsuran',
                'posisi' => 'Debet',
                'jumlah' => $pokok + $margin,
                'saldo_setelah' => null,
                'user_id' => $this->teller->id,
                'sumber_transaksi' => 'Teller',
                'status_transaksi' => 'Sukses',
                'status' => 'Sukses',
                'catatan' => 'Pembayaran angsuran ke-1 akad '.$akad,
            ]);

            // Update angsuran
            $angsuran->update([
                'transaksi_id' => $trx->id,
                'tanggal_bayar' => now()->subMonths(4)->toDateString(),
                'status_angsuran' => 'Sudah Dibayar',
                'total_bayar' => $pokok + $margin,
                'user_id' => $this->teller->id,
            ]);

            // Jurnal Angsuran per akad
            $this->jurnal->jurnalAngsuran(
                $pokok,
                $margin,
                0,
                'Angsuran ke-1 '.$akad.' - '.($pmb->anggota->nama_lengkap ?? ''),
                $this->teller->id,
                $trx->id,
                $pmb->id,
                $akad,
                $pmb->rekening_id,
                $pmb->anggota_id
            );
        }
    }

    // =========================================================================
    // LANGKAH 12: BIAYA ADMINISTRASI (4-2001)
    // =========================================================================
    private function catatBiayaAdmin(): void
    {
        $nominal = 150_000;

        $trx = Transaksi::create([
            'no_transaksi' => 'TRX-ADM-0001',
            'waktu_transaksi' => now()->subMonths(3),
            'anggota_id' => null,
            'jenis_transaksi' => 'Biaya Administrasi',
            'posisi' => 'Kredit',
            'jumlah' => $nominal,
            'saldo_setelah' => 0,
            'user_id' => $this->teller->id,
            'sumber_transaksi' => 'Teller',
            'status_transaksi' => 'Sukses',
            'status' => 'Sukses',
            'catatan' => 'Biaya administrasi bulanan',
        ]);

        $this->jurnal->jurnalBiayaAdmin(
            $nominal,
            'Biaya Administrasi Bulanan',
            $this->teller->id,
            $trx->id
        );
    }

    // =========================================================================
    // LANGKAH 13: BEBAN OPERASIONAL (pemetaan akun spesifik per jenis beban)
    // Utilitas → 5-1003 | ATK → 5-1002 | Gaji → 5-1001 (akun general)
    // =========================================================================
    private function catatBebanOperasional(): void
    {
        $items = self::BEBAN_OPERASIONAL_DEMO;

        $coaKas = Coa::findByKode('1-1001');

        foreach ($items as $item) {
            // Resolusi akun beban spesifik via service resmi (fallback otomatis
            // ke 5-1001 bila akun spesifik tidak tersedia) agar klasifikasi
            // beban tidak menumpuk di satu akun general.
            $kodeBeban = $this->jurnal->kodeAkunBebanByJenis($item['jenis'], $item['ref']);
            $coaBeban = Coa::findByKode($kodeBeban);

            PengeluaranBeban::create([
                'no_pengeluaran' => $item['ref'],
                'tanggal' => now()->subMonths(2)->toDateString(),
                'coa_beban_id' => $coaBeban?->id,
                'coa_kas_id' => $coaKas?->id,
                'nominal' => $item['nominal'],
                'keterangan' => $item['ket'],
                'status' => 'Approved',
                'created_by' => $this->manajer->id,
                'approved_by' => $this->pengurus->id,
                'approved_at' => now()->subMonths(2),
            ]);

            $this->jurnal->jurnalPengeluaranBeban(
                $item['nominal'],
                $kodeBeban,
                '1-1001',
                $item['ket'],
                $this->manajer->id,
                $item['ref']
            );
        }
    }

    // =========================================================================
    // LANGKAH 14: DANA KEBAJIKAN / TA'ZIR (2-3001)
    // =========================================================================
    private function catatDanaKebajikan(array $anggota, array $rekening): void
    {
        $ang = $anggota[1]; // Anggota ke-2
        $rek = $rekening[1];
        $nominal = 50_000;

        DanaKebajikan::create([
            'no_referensi' => 'TAZIR-'.$ang->no_anggota,
            'sumber_dana' => 'Ta\'zir Angsuran - '.$ang->nama_lengkap,
            'jenis' => "Ta'zir",
            'jumlah' => $nominal,
            'keterangan' => 'Denda keterlambatan angsuran - '.$ang->nama_lengkap,
            'created_by' => $this->teller->id,
        ]);

        $rek->decrement('saldo', $nominal);
        $rek->refresh();

        $trx = Transaksi::create([
            'no_transaksi' => 'TRX-TAZIR-0001',
            'waktu_transaksi' => now()->subMonths(2),
            'rekening_id' => $rek->id,
            'anggota_id' => $ang->id,
            'jenis_transaksi' => "Ta'zir",
            'posisi' => 'Kredit',
            'jumlah' => $nominal,
            'saldo_setelah' => (float) $rek->saldo,
            'user_id' => $this->teller->id,
            'sumber_transaksi' => 'Teller',
            'status_transaksi' => 'Sukses',
            'status' => 'Sukses',
            'catatan' => "Pemotongan ta'zir keterlambatan",
        ]);

        $this->jurnal->jurnalTazir(
            $nominal,
            "Ta'zir - ".$ang->nama_lengkap,
            $this->teller->id,
            $trx->id,
            $rek->id,
            $ang->id
        );
    }

    // =========================================================================
    // LANGKAH 15: TUTUP BUKU & ALOKASI SHU (kepala 4 & 5 → 3-9000 → 3-2001)
    // =========================================================================
    private function alokasiShu(): void
    {
        /* SHU TIDAK BOLEH dibukukan dengan posting manual D Kas Teller / K
         | 3-9000: itu mengakui laba tanpa realisasi sekaligus menggelembungkan
         | Kas Teller tanpa arus kas nyata. Sesuai IkhtisarShuService, SHU hanya
         | boleh terbentuk dari PENUTUPAN Pendapatan (kepala 4) & Beban (kepala 5)
         | ke Ikhtisar SHU (3-9000) — jurnal selalu balance & idempoten. */
        $hasil = app(IkhtisarShuService::class)->tutupBuku(null, $this->pengurus->id);
        $shuBersih = (float) ($hasil['selisih_shu'] ?? 0);

        // Alokasi 20% SHU ke Dana Cadangan — HANYA bila periode menghasilkan
        // laba. Rugi tidak boleh dialokasikan ke Dana Cadangan (PSAK 101).
        if ($shuBersih > 0) {
            $alokasi = (int) ($shuBersih * 0.20);
            $this->jurnal->jurnalAlokasiShu(
                $alokasi,
                'Alokasi 20% SHU ke Dana Cadangan Koperasi',
                $this->pengurus->id
            );
        }
    }

    // =========================================================================
    // LANGKAH 16: SIMPANAN BERJANGKA / DEPOSITO MUDHARABAH (2-1003)
    // D: 1-1001 Kas Teller | K: 2-1003 Simpanan Berjangka
    // =========================================================================
    private function buatDeposito(array $anggota, array $rekening): void
    {
        $akunId = Akun::where('kode_akun', '1133')->first()?->id
               ?? Akun::where('jenis_akun', 'Detail')->first()?->id
               ?? 1;

        // Sampel 1: Deposito 6 bulan — anggota pertama (Budi Santoso)
        $ang1 = $anggota[0];
        $nominal1 = 5_000_000;
        $noRek1 = 'DEP-2026-0001';

        $rekDep1 = Rekening::create([
            'no_rekening' => $noRek1,
            'anggota_id' => $ang1->id,
            'akun_id' => $akunId,
            'jenis_rekening' => 'Simpanan Berjangka',
            'akad' => 'Mudharabah',
            'saldo' => $nominal1,
            'batas_minimum' => 0,
            'nisbah_nasabah' => 60,
            'nisbah_bank' => 40,
            'tanggal_buka' => now()->subMonths(1)->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths(5)->toDateString(),
            'status_rekening' => 'Aktif',
            'keterangan' => 'Deposito mudharabah 6 bulan — demo',
        ]);

        $trx1 = Transaksi::create([
            'no_transaksi' => 'TRX-DEP-2026-0001',
            'rekening_id' => $rekDep1->id,
            'anggota_id' => $ang1->id,
            'jenis_transaksi' => 'Setoran Simpanan Berjangka',
            'posisi' => 'Debet',
            'jumlah' => $nominal1,
            'saldo_setelah' => $nominal1,
            'sumber_transaksi' => 'Teller',
            'status_transaksi' => 'Sukses',
            'status' => 'success',
            'waktu_transaksi' => now()->subMonths(1),
            'user_id' => $this->teller->id,
            'catatan' => 'Pembukaan deposito mudharabah 6 bulan — '.$ang1->nama_lengkap,
        ]);

        $this->jurnal->jurnalSetorDeposito(
            $nominal1,
            'Pembukaan deposito '.$noRek1.' — '.$ang1->nama_lengkap,
            $this->teller->id,
            $trx1->id,
            $rekDep1->id,
            $ang1->id
        );

        // Sampel 2: Deposito 12 bulan — anggota kelima (Hasan Basri)
        $ang5 = $anggota[4];
        $nominal2 = 10_000_000;
        $noRek2 = 'DEP-2026-0002';

        $rekDep2 = Rekening::create([
            'no_rekening' => $noRek2,
            'anggota_id' => $ang5->id,
            'akun_id' => $akunId,
            'jenis_rekening' => 'Simpanan Berjangka',
            'akad' => 'Mudharabah',
            'saldo' => $nominal2,
            'batas_minimum' => 0,
            'nisbah_nasabah' => 65,
            'nisbah_bank' => 35,
            'tanggal_buka' => now()->subMonths(2)->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths(10)->toDateString(),
            'status_rekening' => 'Aktif',
            'keterangan' => 'Deposito mudharabah 12 bulan — demo',
        ]);

        $trx2 = Transaksi::create([
            'no_transaksi' => 'TRX-DEP-2026-0002',
            'rekening_id' => $rekDep2->id,
            'anggota_id' => $ang5->id,
            'jenis_transaksi' => 'Setoran Simpanan Berjangka',
            'posisi' => 'Debet',
            'jumlah' => $nominal2,
            'saldo_setelah' => $nominal2,
            'sumber_transaksi' => 'Teller',
            'status_transaksi' => 'Sukses',
            'status' => 'success',
            'waktu_transaksi' => now()->subMonths(2),
            'user_id' => $this->teller->id,
            'catatan' => 'Pembukaan deposito mudharabah 12 bulan — '.$ang5->nama_lengkap,
        ]);

        $this->jurnal->jurnalSetorDeposito(
            $nominal2,
            'Pembukaan deposito '.$noRek2.' — '.$ang5->nama_lengkap,
            $this->teller->id,
            $trx2->id,
            $rekDep2->id,
            $ang5->id
        );

        $this->command->info("  Deposito: {$noRek1} Rp 5.000.000 (6 bln) + {$noRek2} Rp 10.000.000 (12 bln) — Jurnal 2-1003 Posted.");
    }

    // =========================================================================
    // LANGKAH 17: KAS HARIAN CLOSING
    // =========================================================================
    private function tutupKasHarian(): void
    {
        $kas = KasHarian::where('user_id', $this->teller->id)
            ->whereDate('tanggal', now()->toDateString())
            ->whereNull('waktu_closing')
            ->first();

        if ($kas) {
            /* Saldo sistem WAJIB dihitung dari sumber yang sama dengan
             | TutupKasAction (`KasHarian::ringkasanPosisiKas`), bukan angka
             | hardcode. Sebelumnya seeder menulis fisik_kas & saldo_sistem
             | Rp 12.000.000 tanpa dasar: transaksi demo bertanggal beberapa
             | bulan lalu, sehingga sesi kas HARI INI hanya berisi modal awal
             | dan tidak pernah bernilai 12 juta. Akibatnya selisih closing
             | (Rp 0) tidak sesuai kenyataan dan laporan kas harian menampilkan
             | angka fiktif. */
            $saldoSistem = (float) KasHarian::ringkasanPosisiKas(
                $this->teller->id,
                now()->toDateString()
            )['saldo'];

            $kas->update([
                'fisik_kas' => $saldoSistem,
                'saldo_sistem' => $saldoSistem,
                'selisih' => 0,
                // Kolom string: 'Balanced' | 'Lebih' | 'Kurang' (lihat migrasi
                // kas_harian & TutupKasAction), bukan boolean.
                'status_closing' => 'Balanced',
                'catatan_closing' => 'Closing kas demo — semua transaksi tercatat',
                'waktu_closing' => now(),
            ]);
        }
    }
}
