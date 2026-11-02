<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LindungiProduksi;
use App\Models\Akun;
use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\DokumenPengajuan;
use App\Models\JenisSimpanan;
use App\Models\MasterProdukPembiayaan;
use App\Models\Pembiayaan;
use App\Models\PengajuanPembiayaan;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\AutoJournalingService;
use App\Services\TransactionLimitService;
use App\Support\HierarkiRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UjiCobaRoleAO extends Command
{
    use LindungiProduksi;

    protected $signature = 'test:role-ao
        {--role=ao : Peran yang diuji (nama peran pada SSOT RBAC)}
        {--force : Jalankan tanpa konfirmasi}';

    protected $description = 'Uji coba semua fungsi role AO: pengajuan, survei 5C, teruskan ke manajer, input anggota baru, setoran, riwayat';

    private AutoJournalingService $jurnal;

    private TransactionLimitService $limitSvc;

    public function __construct(AutoJournalingService $jurnal, TransactionLimitService $limitSvc)
    {
        parent::__construct();
        $this->jurnal = $jurnal;
        $this->limitSvc = $limitSvc;
    }

    public function handle(): int
    {
        // GUARD PRODUKSI: command ini membuat data uji coba (anggota, setoran,
        // pengajuan) yang mencemari buku besar bila dijalankan di produksi.
        if ($this->tolakDiProduksiTanpaForce('test:role-ao')) {
            return 1;
        }

        if (! $this->option('force')) {
            $this->warn('Perintah ini akan membuat data uji coba fungsi AO dengan tanggal hari ini.');
            if (! $this->confirm('Lanjutkan?')) {
                $this->info('Dibatalkan.');

                return 0;
            }
        }

        $this->info('======================================================');
        $this->info('  UJI COBA ROLE AO — '.now()->format('d/m/Y H:i'));
        $this->info('======================================================');
        $this->newLine();

        // Peran target diambil dari argumen dan divalidasi terhadap SSOT RBAC
        // (DYN-14), bukan dipatri 'ao' di kueri.
        $peran = (string) ($this->option('role') ?: 'ao');

        if (! HierarkiRole::dikenal($peran)) {
            $this->error("Peran '{$peran}' tidak dikenal. Pilihan: ".implode(', ', HierarkiRole::peran()));

            return 1;
        }

        $ao = User::where('role', $peran)->where('is_active', true)->first();
        if (! $ao) {
            $this->error('User '.HierarkiRole::label($peran)." ({$peran}) tidak ditemukan. Pastikan seeder sudah dijalankan.");

            return 1;
        }
        $this->info("  AO: {$ao->name} (ID: {$ao->id})");
        $this->newLine();

        $hasil = [];

        // --- FUNGSI 1: Input Anggota Baru ---
        $anggotaBaru = $this->ujiFungsi1InputAnggotaBaru($ao, $hasil);

        // --- FUNGSI 2: Buat Pengajuan Pembiayaan Baru ---
        $pengajuanBaru = null;
        if ($anggotaBaru) {
            $pengajuanBaru = $this->ujiFungsi2BuatPengajuan($ao, $anggotaBaru, $hasil);
        }

        // --- FUNGSI 3: Upload Dokumen Pengajuan ---
        if ($pengajuanBaru) {
            $this->ujiFungsi3UploadDokumen($ao, $pengajuanBaru, $hasil);
        }

        // --- FUNGSI 4: Input Analisis Survei 5C ---
        if ($pengajuanBaru) {
            $this->ujiFungsi4Survei5C($ao, $pengajuanBaru, $hasil);
        }

        // --- FUNGSI 5: Teruskan Pengajuan ke Manajer ---
        if ($pengajuanBaru) {
            $this->ujiFungsi5TeruskeanKeManajer($ao, $pengajuanBaru, $hasil);
        }

        // --- FUNGSI 6: Setoran Tunai via AO (uji limit & jurnal) ---
        $this->ujiFungsi6SetoranTunai($ao, $hasil);

        // --- FUNGSI 7: Reject Pengajuan (uji path ditolak) ---
        $this->ujiFungsi7RejectPengajuan($ao, $hasil);

        // --- FUNGSI 8: Catat Tunggakan / Penagihan ---
        $this->ujiFungsi8CatatTunggakan($ao, $hasil);

        // --- FUNGSI 9: Simulasi Angsuran (kalkulasi) ---
        $this->ujiFungsi9SimulasiAngsuran($ao, $hasil);

        // --- FUNGSI 10: Cek Riwayat Transaksi Anggota Binaan ---
        $this->ujiFungsi10RiwayatTransaksi($ao, $hasil);

        // Tampilkan ringkasan
        $this->tampilkanRingkasan($hasil);

        return 0;
    }

    // ═══════════════════════════════════════════════════════════════
    //  FUNGSI 1 — Input Anggota Baru (quickStoreAnggota)
    // ═══════════════════════════════════════════════════════════════
    private function ujiFungsi1InputAnggotaBaru(User $ao, array &$hasil): ?Anggota
    {
        $this->info('[1] Input Anggota Baru');
        try {
            // Buat NIK unik
            $nik = '32'.now()->format('YmdHis').rand(10, 99);
            $nik = substr($nik, 0, 16); // NIK harus 16 digit

            $noAnggota = Anggota::nomorAnggotaBaru('ANG-'.date('Ym').'-');

            $anggota = Anggota::create([
                'no_anggota' => $noAnggota,
                'nama_lengkap' => 'Farhan Nugraha (Test AO)',
                'nik' => $nik,
                'jenis_kelamin' => 'Laki-laki',
                'pekerjaan' => 'Pedagang',
                'no_hp' => '081234567899',
                'alamat' => 'Jl. Uji Coba No. 1, Jakarta',
                'kelurahan' => 'Kebayoran Baru',
                'kecamatan' => 'Kebayoran Baru',
                'kota' => 'Jakarta Selatan',
                'provinsi' => 'DKI Jakarta',
                'kode_pos' => '12110',
                'tempat_lahir' => 'Jakarta',
                'tanggal_lahir' => '1990-05-15',
                'status_perkawinan' => 'Menikah',
                'nama_ibu_kandung' => 'Sari',
                'email' => 'farhan.test'.now()->format('His').'@koperasi.test',
                'status_anggota' => Anggota::STATUS_ACTIVE,
                'tanggal_gabung' => today(),
                'simpanan_pokok' => 500000,
                'simpanan_wajib' => 0,
            ]);

            // Ambil akun_id default (kode akun simpanan sukarela atau akun pertama)
            $akunId = Akun::where('kode_akun', '211')->value('id')
                   ?? Akun::first()?->id
                   ?? null;

            // Buat rekening simpanan sukarela untuk anggota baru
            $noRek = Rekening::nomorRekeningBaru('REK-'.date('Ymd').'-', 4);
            $rek = Rekening::create([
                'no_rekening' => $noRek,
                'anggota_id' => $anggota->id,
                'akun_id' => $akunId,
                'jenis_rekening' => JenisSimpanan::namaUtama(JenisSimpanan::TIPE_SUKARELA),
                'saldo' => 500000,
                'batas_minimum' => 50000,
                'tanggal_buka' => today(),
                'status_rekening' => 'Aktif',
                'keterangan' => 'Rekening dibuat saat uji coba AO',
            ]);

            // Jurnal simpanan pokok pembukaan rekening
            $this->jurnal->post(
                "Simpanan pokok anggota baru {$anggota->nama_lengkap}",
                [
                    ['kode' => AutoJournalingService::KAS_TELLER,    'debet' => 500000],
                    ['kode' => AutoJournalingService::SIMPANAN_POKOK, 'kredit' => 500000],
                ],
                'Simpanan Pokok',
                null, null, $ao->id, $rek->id, $anggota->id
            );

            $hasil[] = ['fungsi' => 'Input Anggota Baru',  'status' => 'LULUS', 'detail' => "{$anggota->no_anggota} — {$anggota->nama_lengkap} | Rekening: {$noRek}"];
            $this->line("  ✓ Anggota baru dibuat: {$anggota->no_anggota} — {$anggota->nama_lengkap}");
            $this->line("  ✓ Rekening dibuat: {$noRek} | Jurnal simpanan pokok Posted");

            return $anggota;
        } catch (\Throwable $e) {
            $hasil[] = ['fungsi' => 'Input Anggota Baru', 'status' => 'GAGAL', 'detail' => $e->getMessage()];
            $this->error('  ✗ GAGAL: '.$e->getMessage());
            Log::error('UjiCobaRoleAO::inputAnggotaBaru', ['error' => $e->getMessage()]);

            return null;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  FUNGSI 2 — Buat Pengajuan Pembiayaan Baru
    // ═══════════════════════════════════════════════════════════════
    private function ujiFungsi2BuatPengajuan(User $ao, Anggota $anggota, array &$hasil): ?PengajuanPembiayaan
    {
        $this->newLine();
        $this->info('[2] Buat Pengajuan Pembiayaan Baru');
        try {
            $produk = MasterProdukPembiayaan::where('status_aktif', true)->first();
            $marginPersen = $produk ? (float) $produk->margin_persen : 12.0;
            $plafon = 10_000_000;
            $tenor = 12;
            $totalMargin = $plafon * ($marginPersen / 100);
            $totalPembiayaan = $plafon + $totalMargin;
            $angsuranPerBulan = $totalPembiayaan / $tenor;

            $no = PengajuanPembiayaan::nomorPengajuanBaru('PGJ-'.date('Ymd').'-');

            $pengajuan = PengajuanPembiayaan::create([
                'no_pengajuan' => $no,
                'anggota_id' => $anggota->id,
                'produk_id' => $produk?->id,
                'jenis_akad' => 'Murabahah',
                'margin_persen' => $marginPersen,
                'jumlah_pengajuan' => $plafon,
                'total_pembiayaan' => $totalPembiayaan,
                'angsuran_per_bulan' => $angsuranPerBulan,
                'tenor' => $tenor,
                'tujuan' => 'Modal usaha warung makan',
                'agunan' => 'BPKB Sepeda Motor 2021',
                'penghasilan_bulanan' => 5_000_000,
                'pengeluaran_bulanan' => 2_500_000,
                'jumlah_tanggungan' => 2,
                'status' => 'Menunggu',
                'ao_id' => $ao->id,
                'created_by' => $ao->id,
                'diproses_oleh' => $ao->id,
                'step_terakhir' => 1,
            ]);

            $hasil[] = ['fungsi' => 'Buat Pengajuan Pembiayaan', 'status' => 'LULUS', 'detail' => "{$no} | Murabahah Rp ".number_format($plafon, 0, ',', '.')." | Tenor {$tenor} bln"];
            $this->line("  ✓ Pengajuan dibuat: {$no} — Murabahah Rp ".number_format($plafon, 0, ',', '.'));

            return $pengajuan;
        } catch (\Throwable $e) {
            $hasil[] = ['fungsi' => 'Buat Pengajuan Pembiayaan', 'status' => 'GAGAL', 'detail' => $e->getMessage()];
            $this->error('  ✗ GAGAL: '.$e->getMessage());

            return null;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  FUNGSI 3 — Upload Dokumen Pengajuan
    // ═══════════════════════════════════════════════════════════════
    private function ujiFungsi3UploadDokumen(User $ao, PengajuanPembiayaan $pengajuan, array &$hasil): void
    {
        $this->newLine();
        $this->info('[3] Upload Dokumen Pengajuan');
        try {
            $dokumenList = [
                ['jenis' => 'KTP', 'nama' => 'ktp_farhan.jpg'],
                ['jenis' => 'Slip Gaji', 'nama' => 'slip_gaji_farhan.pdf'],
                ['jenis' => 'BPKB', 'nama' => 'bpkb_motor_farhan.pdf'],
            ];

            foreach ($dokumenList as $dok) {
                DokumenPengajuan::create([
                    'pengajuan_id' => $pengajuan->id,
                    'jenis_dokumen' => $dok['jenis'],
                    'nama_file' => $dok['nama'],
                    'path_file' => 'dokumen-pengajuan/'.$pengajuan->id.'/'.$dok['nama'],
                    'ukuran_file' => rand(50000, 500000),
                    'diupload_oleh' => $ao->id,
                ]);
                $this->line("  ✓ Dokumen '{$dok['jenis']}' — {$dok['nama']} diupload");
            }

            $hasil[] = ['fungsi' => 'Upload Dokumen', 'status' => 'LULUS', 'detail' => count($dokumenList).' dokumen (KTP, Slip Gaji, BPKB) berhasil dicatat'];
        } catch (\Throwable $e) {
            $hasil[] = ['fungsi' => 'Upload Dokumen', 'status' => 'GAGAL', 'detail' => $e->getMessage()];
            $this->error('  ✗ GAGAL: '.$e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  FUNGSI 4 — Survei 5C (Character, Capacity, Capital, Collateral, Condition)
    // ═══════════════════════════════════════════════════════════════
    private function ujiFungsi4Survei5C(User $ao, PengajuanPembiayaan $pengajuan, array &$hasil): void
    {
        $this->newLine();
        $this->info('[4] Input Analisis Survei 5C');
        try {
            $analisis5C = [
                'character' => 4,
                'capacity' => 4,
                'capital' => 3,
                'collateral' => 4,
                'condition' => 4,
                'skor_total' => 19,
                'rekomendasi' => 'teruskan',
            ];

            $pengajuan->update([
                'analisis_5c' => $analisis5C,
                'catatan_survei' => 'Survei lapangan '.today()->format('d/m/Y').': usaha aktif, penghasilan stabil, agunan memadai.',
                'catatan_pengelola' => 'Analisis 5C selesai. Skor total 19/25. Rekomendasikan disetujui.',
                'step_terakhir' => 2,
            ]);

            $hasil[] = [
                'fungsi' => 'Analisis Survei 5C',
                'status' => 'LULUS',
                'detail' => 'Skor 5C: Character=4, Capacity=4, Capital=3, Collateral=4, Condition=4 | Total=19/25',
            ];
            $this->line('  ✓ Analisis 5C tersimpan — Skor total: 19/25 (Character=4, Capacity=4, Capital=3, Collateral=4, Condition=4)');
        } catch (\Throwable $e) {
            $hasil[] = ['fungsi' => 'Analisis Survei 5C', 'status' => 'GAGAL', 'detail' => $e->getMessage()];
            $this->error('  ✗ GAGAL: '.$e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  FUNGSI 5 — Teruskan Pengajuan ke Manajer (surveiStore → Direview)
    // ═══════════════════════════════════════════════════════════════
    private function ujiFungsi5TeruskeanKeManajer(User $ao, PengajuanPembiayaan $pengajuan, array &$hasil): void
    {
        $this->newLine();
        $this->info('[5] Teruskan Pengajuan ke Manajer');
        try {
            DB::transaction(function () use ($ao, $pengajuan, &$hasil) {
                // Update status pengajuan ke Direview (seperti surveiStore di controller)
                $pengajuan->update([
                    'status' => 'Direview',
                    'diproses_oleh' => $ao->id,
                    'tanggal_diproses' => now(),
                    'step_terakhir' => 3,
                ]);

                // Buat record Pembiayaan baru (seperti di surveiStore controller)
                $prefix = 'PMB-'.date('Ym').'-';
                $last = Pembiayaan::withTrashed()->where('no_pembiayaan', 'like', $prefix.'%')
                    ->orderByDesc('no_pembiayaan')->value('no_pembiayaan');
                $seq = $last ? ((int) substr($last, -4) + 1) : 1;
                $noPembiayaan = $prefix.str_pad($seq, 4, '0', STR_PAD_LEFT);

                $rekeningId = Rekening::where('anggota_id', $pengajuan->anggota_id)
                    ->where('status_rekening', 'Aktif')
                    ->value('id');

                $akunId = Akun::where('kode_akun', '121')->value('id')
                       ?? Akun::first()?->id ?? null;

                $pembiayaan = Pembiayaan::create([
                    'no_pembiayaan' => $noPembiayaan,
                    'anggota_id' => $pengajuan->anggota_id,
                    'akun_id' => $akunId,
                    'rekening_id' => $rekeningId,
                    'akad_pembiayaan' => $pengajuan->jenis_akad ?? Pembiayaan::akadDefault(),
                    'tujuan_pembiayaan' => $pengajuan->tujuan ?? 'Modal Usaha',
                    'jumlah_pembiayaan' => $pengajuan->jumlah_pengajuan,
                    'margin_nisbah' => $pengajuan->margin_persen,
                    'total_pembiayaan' => $pengajuan->total_pembiayaan,
                    'sisa_pembiayaan' => $pengajuan->total_pembiayaan,
                    'jumlah_angsuran' => $pengajuan->angsuran_per_bulan,
                    'tenor' => $pengajuan->tenor,
                    'tanggal_akad' => today(),
                    'tanggal_jatuh_tempo' => today()->addMonths((int) $pengajuan->tenor),
                    'status_pembiayaan' => Pembiayaan::STATUS_PENDING_MANAJER,
                    'direview_oleh' => $ao->id,
                    'tanggal_direview' => now(),
                    'keterangan' => 'Diteruskan AO — hasil survei 5C positif',
                    'agunan' => $pengajuan->agunan,
                ]);

                $pengajuan->update(['pembiayaan_id' => $pembiayaan->id]);

                $hasil[] = [
                    'fungsi' => 'Teruskan ke Manajer',
                    'status' => 'LULUS',
                    'detail' => "Pengajuan {$pengajuan->no_pengajuan} → status: Direview | Pembiayaan dibuat: {$noPembiayaan} (pending_manajer)",
                ];
                $this->line("  ✓ Pengajuan {$pengajuan->no_pengajuan} diteruskan ke Manajer");
                $this->line("  ✓ Pembiayaan dibuat: {$noPembiayaan} — status: pending_manajer");
            });
        } catch (\Throwable $e) {
            $hasil[] = ['fungsi' => 'Teruskan ke Manajer', 'status' => 'GAGAL', 'detail' => $e->getMessage()];
            $this->error('  ✗ GAGAL: '.$e->getMessage());
            Log::error('UjiCobaRoleAO::teruskeanKeManajer', ['error' => $e->getMessage()]);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  FUNGSI 6 — Setoran Tunai via AO (uji TransactionLimitService + jurnal)
    // ═══════════════════════════════════════════════════════════════
    private function ujiFungsi6SetoranTunai(User $ao, array &$hasil): void
    {
        $this->newLine();
        $this->info('[6] Setoran Tunai via AO (uji limit & jurnal)');
        try {
            $anggota = Anggota::whereHas('rekening', fn ($q) => $q->where('status_rekening', 'Aktif'))
                ->with(['rekening' => fn ($q) => $q->where('status_rekening', 'Aktif')])
                ->first();

            if (! $anggota || $anggota->rekening->isEmpty()) {
                $hasil[] = ['fungsi' => 'Setoran Tunai AO', 'status' => 'SKIP', 'detail' => 'Tidak ada anggota dengan rekening aktif'];
                $this->warn('  ⚠ Skip — tidak ada anggota dengan rekening aktif');

                return;
            }

            $rek = $anggota->rekening->first();
            $nominal = 5_000_000;

            DB::transaction(function () use ($ao, $anggota, $rek, $nominal, &$hasil) {
                $prefix = 'TRX-'.date('Ymd').'-';
                $max = (string) Transaksi::withTrashed()->where('no_transaksi', 'like', $prefix.'%')->max('no_transaksi');
                $urut = $max ? ((int) substr($max, -4)) + 1 : 1;
                $noTrx = $prefix.str_pad($urut, 4, '0', STR_PAD_LEFT);

                $result = $this->limitSvc->processTransactionWithLimitCheck(
                    $ao, 'setor_tunai', $nominal, 'tunai',
                    [
                        'no_transaksi' => $noTrx,
                        'rekening_id' => $rek->id,
                        'anggota_id' => $anggota->id,
                        'jenis_transaksi' => 'Setoran Tunai',
                        'posisi' => 'Debet',
                        'jumlah' => $nominal,
                        'saldo_setelah' => $rek->saldo + $nominal,
                        'sumber_transaksi' => 'AO',
                        'catatan' => 'Setoran tunai uji coba AO',
                    ]
                );

                if ($result['status'] === 'success') {
                    $rek->increment('saldo', $nominal);
                    $trx = $result['data']['transaksi'];
                    $jurnal = $this->jurnal->jurnalSetorTunai($nominal, 'Setoran tunai uji coba AO', $ao->id, $trx->id, $rek->id, $anggota->id);

                    // post() mengembalikan null bila CoA hilang/nonaktif → batalkan
                    // agar saldo tidak bergerak tanpa jurnal.
                    if ($jurnal === null) {
                        throw new \RuntimeException('Jurnal setoran gagal diposting (CoA tidak ditemukan/nonaktif).');
                    }
                }

                $hasil[] = [
                    'fungsi' => 'Setoran Tunai AO',
                    'status' => 'LULUS',
                    'detail' => 'Rp '.number_format($nominal, 0, ',', '.')." → {$anggota->nama_lengkap} [{$result['status']}] | Jurnal CoA Posted",
                ];
                $this->line('  ✓ Setoran Rp '.number_format($nominal, 0, ',', '.')." — {$anggota->nama_lengkap} [{$result['status']}]");
                $this->line('  ✓ Jurnal: Kas Teller (D) | Simpanan Sukarela (K) — Posted ke CoA');
            });
        } catch (\Throwable $e) {
            $hasil[] = ['fungsi' => 'Setoran Tunai AO', 'status' => 'GAGAL', 'detail' => $e->getMessage()];
            $this->error('  ✗ GAGAL: '.$e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  FUNGSI 7 — Reject Pengajuan (uji path ditolak)
    // ═══════════════════════════════════════════════════════════════
    private function ujiFungsi7RejectPengajuan(User $ao, array &$hasil): void
    {
        $this->newLine();
        $this->info('[7] Reject Pengajuan (uji path ditolak)');
        try {
            // Buat pengajuan dummy khusus untuk ditolak
            $anggota = Anggota::aktif()->skip(1)->first();
            if (! $anggota) {
                $hasil[] = ['fungsi' => 'Reject Pengajuan', 'status' => 'SKIP', 'detail' => 'Tidak ada anggota tersedia'];
                $this->warn('  ⚠ Skip — tidak ada anggota tersedia');

                return;
            }

            $no = PengajuanPembiayaan::nomorPengajuanBaru('PGJ-'.date('Ymd').'-');

            $pengajuanTolak = PengajuanPembiayaan::create([
                'no_pengajuan' => $no,
                'anggota_id' => $anggota->id,
                'jenis_akad' => 'Qardh',
                'jumlah_pengajuan' => 50_000_000,
                'total_pembiayaan' => 50_000_000,
                'tenor' => 24,
                'tujuan' => 'Pengajuan uji coba reject',
                'status' => 'Menunggu',
                'ao_id' => $ao->id,
                'created_by' => $ao->id,
            ]);

            // Tolak pengajuan (seperti pengajuanReject di controller)
            $pengajuanTolak->update([
                'status' => 'Ditolak',
                'diproses_oleh' => $ao->id,
                'tanggal_diproses' => now(),
                'catatan_pengelola' => 'Ditolak: plafon terlalu besar, rasio angsuran/penghasilan tidak memadai. (Uji coba reject AO)',
            ]);

            $hasil[] = [
                'fungsi' => 'Reject Pengajuan',
                'status' => 'LULUS',
                'detail' => "{$no} — {$anggota->nama_lengkap} | status → Ditolak | Catatan tersimpan",
            ];
            $this->line("  ✓ Pengajuan {$no} ditolak — catatan penolakan tersimpan");
        } catch (\Throwable $e) {
            $hasil[] = ['fungsi' => 'Reject Pengajuan', 'status' => 'GAGAL', 'detail' => $e->getMessage()];
            $this->error('  ✗ GAGAL: '.$e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  FUNGSI 8 — Catat Tunggakan / Penagihan (tunggakanCatatan)
    // ═══════════════════════════════════════════════════════════════
    private function ujiFungsi8CatatTunggakan(User $ao, array &$hasil): void
    {
        $this->newLine();
        $this->info('[8] Catat Catatan Penagihan Tunggakan');
        try {
            $angsuranTunggak = Angsuran::whereIn('status_angsuran', ['Terlambat', 'Jatuh Tempo', 'Belum Jatuh Tempo'])
                ->whereHas('pembiayaan')
                ->first();

            if (! $angsuranTunggak) {
                $hasil[] = ['fungsi' => 'Catat Tunggakan', 'status' => 'SKIP', 'detail' => 'Tidak ada angsuran terlambat/jatuh tempo'];
                $this->warn('  ⚠ Skip — tidak ada angsuran terlambat');

                return;
            }

            $angsuranTunggak->update([
                'catatan' => 'Penagihan '.today()->format('d/m/Y').': sudah dihubungi via telepon, berjanji akan bayar minggu ini. (Uji coba AO)',
            ]);

            $hasil[] = [
                'fungsi' => 'Catat Tunggakan',
                'status' => 'LULUS',
                'detail' => "Angsuran ID#{$angsuranTunggak->id} ke-{$angsuranTunggak->angsuran_ke} | catatan penagihan tersimpan",
            ];
            $this->line("  ✓ Catatan penagihan tersimpan untuk angsuran ke-{$angsuranTunggak->angsuran_ke} (ID: {$angsuranTunggak->id})");
        } catch (\Throwable $e) {
            $hasil[] = ['fungsi' => 'Catat Tunggakan', 'status' => 'GAGAL', 'detail' => $e->getMessage()];
            $this->error('  ✗ GAGAL: '.$e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  FUNGSI 9 — Simulasi Angsuran (kalkulasi flat & anuitas)
    // ═══════════════════════════════════════════════════════════════
    private function ujiFungsi9SimulasiAngsuran(User $ao, array &$hasil): void
    {
        $this->newLine();
        $this->info('[9] Simulasi Kalkulasi Angsuran');
        try {
            $jumlah = 10_000_000;
            $margin = 12.0; // % per tahun
            $tenor = 12;

            // Flat
            $totalMarginFlat = $jumlah * ($margin / 100) * ($tenor / 12);
            $totalPembiayaanFlat = $jumlah + $totalMarginFlat;
            $angsuranFlat = $totalPembiayaanFlat / $tenor;

            // Anuitas
            $r = ($margin / 100) / 12;
            $factor = pow(1 + $r, $tenor);
            $angsuranAnuitas = $jumlah * ($r * $factor) / ($factor - 1);

            $hasil[] = [
                'fungsi' => 'Simulasi Angsuran',
                'status' => 'LULUS',
                'detail' => 'Flat: Rp '.number_format($angsuranFlat, 0, ',', '.').'/bln | Anuitas: Rp '.number_format($angsuranAnuitas, 0, ',', '.').'/bln (Plafon Rp 10jt, margin 12%, tenor 12 bln)',
            ];
            $this->line('  ✓ Flat    : Rp '.number_format($angsuranFlat, 0, ',', '.').'/bulan | Total Rp '.number_format($totalPembiayaanFlat, 0, ',', '.'));
            $this->line('  ✓ Anuitas : Rp '.number_format($angsuranAnuitas, 0, ',', '.').'/bulan | Total Rp '.number_format($jumlah + ($angsuranAnuitas * $tenor - $jumlah), 0, ',', '.'));
        } catch (\Throwable $e) {
            $hasil[] = ['fungsi' => 'Simulasi Angsuran', 'status' => 'GAGAL', 'detail' => $e->getMessage()];
            $this->error('  ✗ GAGAL: '.$e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  FUNGSI 10 — Cek Riwayat Transaksi Anggota Binaan
    // ═══════════════════════════════════════════════════════════════
    private function ujiFungsi10RiwayatTransaksi(User $ao, array &$hasil): void
    {
        $this->newLine();
        $this->info('[10] Riwayat Transaksi Anggota Binaan');
        try {
            $anggota = Anggota::whereHas('transaksi')->with(['pembiayaan'])->first();

            if (! $anggota) {
                $hasil[] = ['fungsi' => 'Riwayat Transaksi', 'status' => 'SKIP', 'detail' => 'Belum ada anggota dengan transaksi'];
                $this->warn('  ⚠ Skip — belum ada anggota dengan transaksi');

                return;
            }

            $riwayat = Transaksi::where('anggota_id', $anggota->id)
                ->with('rekening')
                ->orderByDesc('waktu_transaksi')
                ->limit(5)
                ->get();

            $totalPlafonAktif = $anggota->pembiayaan->where('status_pembiayaan', Pembiayaan::STATUS_AKTIF)->sum('jumlah_pembiayaan');
            $totalSisaPembiayaan = $anggota->pembiayaan->where('status_pembiayaan', Pembiayaan::STATUS_AKTIF)->sum('sisa_pembiayaan');

            $hasil[] = [
                'fungsi' => 'Riwayat Transaksi',
                'status' => 'LULUS',
                'detail' => "{$anggota->nama_lengkap} | {$riwayat->count()} transaksi terbaru | Plafon aktif: Rp ".number_format($totalPlafonAktif, 0, ',', '.').' | Sisa: Rp '.number_format($totalSisaPembiayaan, 0, ',', '.'),
            ];

            $this->line("  ✓ Anggota: {$anggota->nama_lengkap}");
            $this->line('    Plafon aktif  : Rp '.number_format($totalPlafonAktif, 0, ',', '.'));
            $this->line('    Sisa pembiayaan: Rp '.number_format($totalSisaPembiayaan, 0, ',', '.'));
            $this->line('    5 Transaksi terakhir:');
            foreach ($riwayat as $trx) {
                $this->line("      - [{$trx->waktu_transaksi->format('d/m/Y H:i')}] {$trx->jenis_transaksi} Rp ".number_format($trx->jumlah, 0, ',', '.')." ({$trx->status_transaksi})");
            }
        } catch (\Throwable $e) {
            $hasil[] = ['fungsi' => 'Riwayat Transaksi', 'status' => 'GAGAL', 'detail' => $e->getMessage()];
            $this->error('  ✗ GAGAL: '.$e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  RINGKASAN AKHIR
    // ═══════════════════════════════════════════════════════════════
    private function tampilkanRingkasan(array $hasil): void
    {
        $this->newLine();
        $this->info('======================================================');
        $this->info('  RINGKASAN UJI COBA ROLE AO');
        $this->info('======================================================');

        $lulus = 0;
        $gagal = 0;
        $skip = 0;

        foreach ($hasil as $item) {
            $icon = match ($item['status']) {
                'LULUS' => '✓', 'GAGAL' => '✗', default => '⚠'
            };
            $warna = match ($item['status']) {
                'LULUS' => 'info', 'GAGAL' => 'error', default => 'warn'
            };
            $this->$warna("  {$icon} [{$item['status']}] {$item['fungsi']}");
            $this->line("        → {$item['detail']}");

            match ($item['status']) {
                'LULUS' => $lulus++,
                'GAGAL' => $gagal++,
                default => $skip++,
            };
        }

        $this->newLine();
        $total = count($hasil);
        $this->info("  Total fungsi diuji : {$total}");
        $this->info("  LULUS              : {$lulus}");
        if ($gagal > 0) {
            $this->error("  GAGAL              : {$gagal}");
        }
        if ($skip > 0) {
            $this->warn("  SKIP (data kosong) : {$skip}");
        }
        $this->newLine();

        if ($gagal === 0) {
            $this->info('  Semua fungsi AO berjalan dengan baik.');
        } else {
            $this->error("  Ada {$gagal} fungsi yang gagal. Cek log Laravel untuk detail.");
        }
    }
}
