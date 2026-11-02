<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LindungiProduksi;
use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\ApprovalTransaksi;
use App\Models\DanaKebajikan;
use App\Models\JenisSimpanan;
use App\Models\Jurnal;
use App\Models\KasHarian;
use App\Models\Pembiayaan;
use App\Models\PengajuanPembiayaan;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\AutoJournalingService;
use App\Services\TazirService;
use App\Services\TransactionLimitService;
use App\Support\HierarkiRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SimulasiTransaksiHariIni extends Command
{
    use LindungiProduksi;

    protected $signature = 'simulate:transaksi-hari-ini {--force : Jalankan tanpa konfirmasi}';

    protected $description = 'Simulasi transaksi realtime hari ini dari semua role untuk men-trigger semua fungsi sistem';

    private AutoJournalingService $jurnal;

    private TransactionLimitService $limitSvc;

    // Users per role
    private User $teller;

    private User $ao;

    private User $manajer;

    private User $pengurus;

    private User $dps;

    public function __construct(AutoJournalingService $jurnal, TransactionLimitService $limitSvc)
    {
        parent::__construct();
        $this->jurnal = $jurnal;
        $this->limitSvc = $limitSvc;
    }

    public function handle(): int
    {
        // GUARD PRODUKSI: command ini membuat transaksi & jurnal nyata dari semua role.
        if ($this->tolakDiProduksiTanpaForce('simulate:transaksi-hari-ini')) {
            return 1;
        }

        if (! $this->option('force')) {
            $this->warn('Perintah ini akan membuat transaksi baru dari semua role dengan tanggal hari ini.');
            $this->line('Pastikan DemoDataSeeder sudah dijalankan terlebih dahulu.');
            $this->newLine();
            if (! $this->confirm('Lanjutkan simulasi?')) {
                $this->info('Dibatalkan.');

                return 0;
            }
        }

        $this->info('======================================================');
        $this->info('  SIMULASI TRANSAKSI HARI INI — '.now()->format('d/m/Y H:i'));
        $this->info('======================================================');
        $this->newLine();

        // Load users per role
        if (! $this->loadUsers()) {
            return 1;
        }

        // Jalankan simulasi per role
        $this->simulasiTeller();
        $this->simulasiAO();
        $this->simulasiManajer();
        $this->simulasiPengurus();
        $this->simulasiDPS();

        $this->newLine();
        $this->info('======================================================');
        $this->info('  SIMULASI SELESAI');
        $this->info('======================================================');
        $this->ringkasanAkhir();

        return 0;
    }

    // ─────────────────────────────────────────────────────────────
    //  LOAD USERS
    // ─────────────────────────────────────────────────────────────

    private function loadUsers(): bool
    {
        $roles = ['teller', 'ao', 'manajer', 'pengurus', 'dps'];
        foreach ($roles as $role) {
            $user = User::where('role', $role)->where('is_active', true)->first();
            if (! $user) {
                $this->error("User dengan role '{$role}' tidak ditemukan. Pastikan seeder sudah dijalankan.");

                return false;
            }
            $this->$role = $user;
        }

        $this->line("  Users dimuat: Teller={$this->teller->name}, AO={$this->ao->name}, Manajer={$this->manajer->name}, Pengurus={$this->pengurus->name}, DPS={$this->dps->name}");
        $this->newLine();

        return true;
    }

    // ─────────────────────────────────────────────────────────────
    //  ROLE: TELLER
    // ─────────────────────────────────────────────────────────────

    private function simulasiTeller(): void
    {
        $this->info('--- [TELLER] '.$this->teller->name.' ---');

        // Ambil anggota yang punya rekening aktif
        $anggota = Anggota::whereHas('rekening', fn ($q) => $q->where('status_rekening', 'Aktif'))
            ->with(['rekening' => fn ($q) => $q->where('status_rekening', 'Aktif')])
            ->take(5)->get();

        if ($anggota->isEmpty()) {
            $this->warn('  Tidak ada anggota aktif. Skip.');

            return;
        }

        // 1. Setoran simpanan sukarela normal (trigger: Kas Teller + Simpanan Sukarela)
        $anggota1 = $anggota->first();
        $rek1 = $anggota1->rekening->whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_SUKARELA))->first()
                 ?? $anggota1->rekening->first();
        if ($rek1) {
            $this->setorSukarela($this->teller, $anggota1, $rek1, 2_500_000, 'Setoran tunai simpanan sukarela harian');
        }

        // 2. Setoran simpanan wajib bulanan (trigger: Kas Teller + Simpanan Wajib)
        if ($anggota->count() >= 2) {
            $anggota2 = $anggota->get(1);
            $rek2 = $anggota2->rekening->first();
            if ($rek2) {
                $this->setorWajib($this->teller, $anggota2, $rek2, 300_000, 'Setoran simpanan wajib bulan '.now()->format('F Y'));
            }
        }

        // 3. Penarikan tunai (trigger: Simpanan Sukarela + Kas Teller)
        if ($anggota->count() >= 3) {
            $anggota3 = $anggota->get(2);
            $rek3 = $anggota3->rekening->whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_SUKARELA))->first()
                     ?? $anggota3->rekening->first();
            if ($rek3 && $rek3->saldo >= 1_000_000) {
                $this->tarikTunai($this->teller, $anggota3, $rek3, 750_000, 'Penarikan tunai kebutuhan harian');
            }
        }

        // 4. Pembayaran angsuran pembiayaan via teller (trigger: Kas Teller + Piutang + Pendapatan)
        $pembiayaanAktif = Pembiayaan::statusIn([Pembiayaan::STATUS_AKTIF])
            ->whereHas('angsuran', fn ($q) => $q->whereIn('status_angsuran', ['Belum Jatuh Tempo', 'Jatuh Tempo', 'Terlambat']))
            ->with(['angsuran' => fn ($q) => $q->whereIn('status_angsuran', ['Belum Jatuh Tempo', 'Jatuh Tempo', 'Terlambat'])->orderBy('angsuran_ke')])
            ->first();

        if ($pembiayaanAktif) {
            $angsuranBayar = $pembiayaanAktif->angsuran->first();
            if ($angsuranBayar) {
                $this->bayarAngsuran($this->teller, $pembiayaanAktif, $angsuranBayar);
            }
        }

        // 5. Setoran AML HIGH-VALUE >= Rp 100 juta (trigger: AML path, is_aml_high_value = true)
        if ($anggota->count() >= 4) {
            $anggota4 = $anggota->get(3);
            $rek4 = $anggota4->rekening->first();
            if ($rek4) {
                $this->setorAmlHighValue($this->teller, $anggota4, $rek4, 125_000_000, 'Setoran besar hasil penjualan aset');
            }
        }

        // 6. Kas harian opening (trigger: KasHarian)
        $this->kasHarianOpening($this->teller);

        $this->newLine();
    }

    // ─────────────────────────────────────────────────────────────
    //  ROLE: AO (Account Officer)
    // ─────────────────────────────────────────────────────────────

    private function simulasiAO(): void
    {
        $this->info('--- [AO] '.$this->ao->name.' ---');

        // 1. Buat pengajuan pembiayaan baru (trigger: PengajuanPembiayaan, step_terakhir)
        $anggotaBaru = Anggota::whereDoesntHave('pembiayaan', fn ($q) => $q->statusIn([Pembiayaan::STATUS_AKTIF, Pembiayaan::STATUS_PENDING_PENCAIRAN]))
            ->first();

        if ($anggotaBaru) {
            $this->buatPengajuanPembiayaan($this->ao, $anggotaBaru);
        } else {
            $this->line('  Semua anggota sudah punya pembiayaan aktif, skip pengajuan baru.');
        }

        // 2. Proses survei pengajuan yang masih menunggu (trigger: catatan_survei, analisis_5c, step_terakhir)
        $pengajuanSurvei = PengajuanPembiayaan::statusIn([
            PengajuanPembiayaan::STATUS_MENUNGGU,
            PengajuanPembiayaan::STATUS_DIPROSES,
        ])->first();

        if ($pengajuanSurvei) {
            $this->prosesSurveiPengajuan($this->ao, $pengajuanSurvei);
        }

        // 3. Setoran di atas limit teller (trigger: PENDING_MANAJER approval path)
        $anggota = Anggota::whereHas('rekening', fn ($q) => $q->where('status_rekening', 'Aktif'))
            ->with(['rekening' => fn ($q) => $q->where('status_rekening', 'Aktif')])
            ->skip(1)->first();

        if ($anggota) {
            $rek = $anggota->rekening->first();
            if ($rek) {
                $this->setorMelebihiLimitTeller($this->ao, $anggota, $rek, 75_000_000, 'Setoran modal usaha anggota');
            }
        }

        $this->newLine();
    }

    // ─────────────────────────────────────────────────────────────
    //  ROLE: MANAJER
    // ─────────────────────────────────────────────────────────────

    private function simulasiManajer(): void
    {
        $this->info('--- [MANAJER] '.$this->manajer->name.' ---');

        // 1. Approve pending approval transaksi (trigger: approveTransaction, saldo update)
        $pending = ApprovalTransaksi::where('status', HierarkiRole::statusMenunggu('manajer'))->first();
        if ($pending) {
            $this->approveTransaksiPending($this->manajer, $pending);
        } else {
            $this->line('  Tidak ada transaksi pending untuk diapprove.');
        }

        // 2. Review pengajuan pembiayaan → status Direview (trigger: direview_oleh, catatan_manajer)
        $pengajuan = PengajuanPembiayaan::statusIn([
            PengajuanPembiayaan::STATUS_DIREVIEW,
            PengajuanPembiayaan::STATUS_DIPROSES,
            PengajuanPembiayaan::STATUS_MENUNGGU,
        ])->first();

        if ($pengajuan) {
            $this->reviewPengajuanManajer($this->manajer, $pengajuan);
        }

        // 3. Transfer kas ke bank (trigger: jurnalPengeluaranBeban — Kas Teller ke Bank Koperasi)
        $this->transferKasKeBank($this->manajer, 10_000_000, 'Transfer kas operasional ke rekening bank koperasi');

        // 4. Pengeluaran beban operasional (trigger: Beban Operasional + Kas)
        $this->pengeluaranBeban($this->manajer, 500_000, 'Beban listrik dan air kantor bulan '.now()->format('F Y'));

        $this->newLine();
    }

    // ─────────────────────────────────────────────────────────────
    //  ROLE: PENGURUS
    // ─────────────────────────────────────────────────────────────

    private function simulasiPengurus(): void
    {
        $this->info('--- [PENGURUS] '.$this->pengurus->name.' ---');

        // 1. Setujui pengajuan pembiayaan yang sudah direview manajer (trigger: disetujui_oleh, catatan_pengurus)
        $pengajuan = PengajuanPembiayaan::statusIn([
            PengajuanPembiayaan::STATUS_DIREVIEW,
        ])->first();

        if ($pengajuan) {
            $this->setujuiPengajuanPengurus($this->pengurus, $pengajuan);
        } else {
            $this->line('  Tidak ada pengajuan untuk disetujui Pengurus.');
        }

        // 2. Alokasi SHU ke cadangan koperasi (trigger: jurnalAlokasiShu — SHU + Cadangan)
        $this->alokasiShu($this->pengurus, 5_000_000, 'Alokasi SHU triwulan ke dana cadangan koperasi');

        // 3. Kas harian closing (trigger: KasHarian status_closing)
        $this->kasHarianClosing($this->pengurus);

        // 4. Pengeluaran beban sosial dari dana kebajikan (trigger: Dana Kebajikan + Beban)
        $this->bebanDanaKebajikan($this->pengurus, 250_000, 'Penyaluran dana kebajikan kepada mustahiq');

        $this->newLine();
    }

    // ─────────────────────────────────────────────────────────────
    //  ROLE: DPS
    // ─────────────────────────────────────────────────────────────

    private function simulasiDPS(): void
    {
        $this->info('--- [DPS] '.$this->dps->name.' ---');

        // 1. Review akad pembiayaan yang sudah disetujui Pengurus (trigger: disahkan_oleh, catatan_dps)
        $pengajuan = PengajuanPembiayaan::statusIn([
            PengajuanPembiayaan::STATUS_DISETUJUI,
        ])->first();

        if ($pengajuan) {
            $this->pengesahanAkadDPS($this->dps, $pengajuan);
        } else {
            // Cek pembiayaan langsung
            $pembiayaan = Pembiayaan::whereNull('disahkan_oleh')
                ->statusIn([Pembiayaan::STATUS_AKTIF])
                ->first();
            if ($pembiayaan) {
                $this->pengesahanPembiayaanDPS($this->dps, $pembiayaan);
            } else {
                $this->line('  Tidak ada pengajuan/pembiayaan yang perlu disahkan DPS.');
            }
        }

        // 2. Biaya administrasi / ujroh akad (trigger: Kas Teller + Pendapatan Admin)
        $this->jurnalBiayaAdmin($this->dps, 150_000, 'Biaya review akad dan pengesahan DPS');

        $this->newLine();
    }

    // ═══════════════════════════════════════════════════════════════
    //  HELPER METHODS — TELLER
    // ═══════════════════════════════════════════════════════════════

    private function setorSukarela(User $user, Anggota $anggota, Rekening $rek, float $nominal, string $catatan): void
    {
        try {
            DB::transaction(function () use ($user, $anggota, $rek, $nominal, $catatan) {
                $noTrx = $this->generateNoTrx();
                $saldoBaru = $rek->saldo + $nominal;

                $result = $this->limitSvc->processTransactionWithLimitCheck(
                    $user, 'setor_tunai', $nominal, 'tunai',
                    [
                        'no_transaksi' => $noTrx,
                        'rekening_id' => $rek->id,
                        'anggota_id' => $anggota->id,
                        'jenis_transaksi' => 'Setoran Tunai',
                        'posisi' => 'Debet',
                        'jumlah' => $nominal,
                        'saldo_setelah' => $saldoBaru,
                        'sumber_transaksi' => 'Teller',
                        'catatan' => $catatan,
                    ]
                );

                if ($result['status'] === 'success') {
                    $rek->increment('saldo', $nominal);
                    $trx = $result['data']['transaksi'];
                    $jurnal = $this->jurnal->jurnalSetorTunai($nominal, $catatan, $user->id, $trx->id, $rek->id, $anggota->id);

                    // post() mengembalikan null bila CoA hilang/nonaktif → batalkan
                    // seluruh simulasi agar saldo tidak bergerak tanpa jurnal.
                    if ($jurnal === null) {
                        throw new \RuntimeException('Jurnal setoran gagal diposting (CoA tidak ditemukan/nonaktif).');
                    }

                    $this->line('  ✓ [Teller] Setoran sukarela Rp '.number_format($nominal, 0, ',', '.')." — {$anggota->nama_lengkap} [{$result['status']}]");
                } elseif ($result['status'] === 'PENDING_APPROVAL') {
                    $this->line('  ⏳ [Teller] Setoran Rp '.number_format($nominal, 0, ',', '.')." → PENDING approval [{$anggota->nama_lengkap}]");
                }
            });
        } catch (\Throwable $e) {
            $this->error('  ✗ setorSukarela gagal: '.$e->getMessage());
            Log::error('SimulasiTransaksi::setorSukarela', ['error' => $e->getMessage()]);
        }
    }

    private function setorWajib(User $user, Anggota $anggota, Rekening $rek, float $nominal, string $catatan): void
    {
        try {
            DB::transaction(function () use ($user, $anggota, $rek, $nominal, $catatan) {
                $noTrx = $this->generateNoTrx();
                $saldoBaru = $rek->saldo + $nominal;

                $trx = Transaksi::create([
                    'no_transaksi' => $noTrx,
                    'rekening_id' => $rek->id,
                    'anggota_id' => $anggota->id,
                    'jenis_transaksi' => 'Simpanan Wajib',
                    'posisi' => 'Debet',
                    'jumlah' => $nominal,
                    'saldo_setelah' => $saldoBaru,
                    'sumber_transaksi' => 'Teller',
                    'status_transaksi' => 'Sukses',
                    'status' => 'success',
                    'waktu_transaksi' => now(),
                    'user_id' => $user->id,
                    'dibuat_oleh' => $user->id,
                    'catatan' => $catatan,
                ]);

                $rek->increment('saldo', $nominal);
                $jurnal = $this->jurnal->jurnalSetorSimpananWajib($nominal, $catatan, $user->id, $trx->id, $rek->id, $anggota->id);
                if ($jurnal === null) {
                    throw new \RuntimeException('Jurnal setoran wajib gagal diposting (CoA tidak ditemukan/nonaktif).');
                }
                $this->line('  ✓ [Teller] Setoran wajib Rp '.number_format($nominal, 0, ',', '.')." — {$anggota->nama_lengkap}");
            });
        } catch (\Throwable $e) {
            $this->error('  ✗ setorWajib gagal: '.$e->getMessage());
            Log::error('SimulasiTransaksi::setorWajib', ['error' => $e->getMessage()]);
        }
    }

    private function tarikTunai(User $user, Anggota $anggota, Rekening $rek, float $nominal, string $catatan): void
    {
        try {
            if (! $rek->isDapatDitarik($nominal)) {
                $this->warn("  ⚠ [Teller] Saldo tidak cukup untuk penarikan {$anggota->nama_lengkap}. Skip.");

                return;
            }

            DB::transaction(function () use ($user, $anggota, $rek, $nominal, $catatan) {
                $noTrx = $this->generateNoTrx();
                $saldoBaru = $rek->saldo - $nominal;

                $result = $this->limitSvc->processTransactionWithLimitCheck(
                    $user, 'tarik_tunai', $nominal, 'tunai',
                    [
                        'no_transaksi' => $noTrx,
                        'rekening_id' => $rek->id,
                        'anggota_id' => $anggota->id,
                        'jenis_transaksi' => 'Penarikan Tunai',
                        'posisi' => 'Kredit',
                        'jumlah' => $nominal,
                        'saldo_setelah' => $saldoBaru,
                        'sumber_transaksi' => 'Teller',
                        'catatan' => $catatan,
                    ]
                );

                if ($result['status'] === 'success') {
                    $rek->decrement('saldo', $nominal);
                    $trx = $result['data']['transaksi'];
                    $jurnal = $this->jurnal->jurnalTarikTunai($nominal, $catatan, $user->id, $trx->id, $rek->id, $anggota->id);
                    if ($jurnal === null) {
                        throw new \RuntimeException('Jurnal penarikan gagal diposting (CoA tidak ditemukan/nonaktif).');
                    }
                    $this->line('  ✓ [Teller] Penarikan Rp '.number_format($nominal, 0, ',', '.')." — {$anggota->nama_lengkap} [{$result['status']}]");
                } elseif ($result['status'] === 'PENDING_APPROVAL') {
                    $this->line('  ⏳ [Teller] Penarikan Rp '.number_format($nominal, 0, ',', '.')." → PENDING approval [{$anggota->nama_lengkap}]");
                }
            });
        } catch (\Throwable $e) {
            $this->error('  ✗ tarikTunai gagal: '.$e->getMessage());
            Log::error('SimulasiTransaksi::tarikTunai', ['error' => $e->getMessage()]);
        }
    }

    private function bayarAngsuran(User $user, Pembiayaan $pembiayaan, Angsuran $angsuran): void
    {
        try {
            DB::transaction(function () use ($user, $pembiayaan, $angsuran) {
                $pokok = (float) $angsuran->pokok_angsuran;
                $margin = (float) $angsuran->margin_ujroh;

                // Denda memakai SSOT TazirService agar simulasi tidak memakai
                // rumus sendiri (DYN-12). Saklar financing_late_fee_per_day,
                // tazir_mode, tazir_rate, dan grace period otomatis dihormati.
                $denda = app(TazirService::class)->calculate($angsuran);

                $total = $pokok + $margin + $denda;
                $noTrx = $this->generateNoTrx();
                $anggota = $pembiayaan->anggota;
                $rek = $pembiayaan->rekening ?? $anggota?->rekening()->aktif()->first();

                $trx = Transaksi::create([
                    'no_transaksi' => $noTrx,
                    'rekening_id' => $rek?->id,
                    'anggota_id' => $anggota?->id,
                    'pembiayaan_id' => $pembiayaan->id,
                    'jenis_transaksi' => 'Bayar Angsuran',
                    'posisi' => 'Debet',
                    'jumlah' => $total,
                    'saldo_setelah' => $rek ? $rek->saldo : 0,
                    'sumber_transaksi' => 'Teller',
                    'status_transaksi' => 'Sukses',
                    'status' => 'success',
                    'waktu_transaksi' => now(),
                    'user_id' => $user->id,
                    'dibuat_oleh' => $user->id,
                    'catatan' => "Bayar angsuran ke-{$angsuran->angsuran_ke} pembiayaan {$pembiayaan->no_pembiayaan}",
                ]);

                // Update angsuran
                $sisaPokok = (float) $pembiayaan->sisa_pembiayaan - $pokok;
                $angsuran->update([
                    'transaksi_id' => $trx->id,
                    'tanggal_bayar' => today(),
                    'denda' => $denda,
                    'total_bayar' => $total,
                    'sisa_pokok' => max(0, $sisaPokok),
                    'status_angsuran' => 'Sudah Dibayar',
                    'user_id' => $user->id,
                ]);

                // Update sisa pembiayaan
                $pembiayaan->decrement('sisa_pembiayaan', $pokok);
                if ($sisaPokok <= 0) {
                    $pembiayaan->update(['status_pembiayaan' => Pembiayaan::STATUS_LUNAS]);
                }

                // Jurnal angsuran (Kas Teller D | Piutang K | Pendapatan K)
                $jurnal = $this->jurnal->jurnalAngsuran(
                    $pokok, $margin, 0,
                    "Bayar angsuran ke-{$angsuran->angsuran_ke} akad {$pembiayaan->akad_pembiayaan}",
                    $user->id, $trx->id, $pembiayaan->id,
                    $pembiayaan->akad_pembiayaan,
                    $rek?->id, $anggota?->id
                );
                if ($jurnal === null) {
                    throw new \RuntimeException('Jurnal angsuran gagal diposting (CoA tidak ditemukan/nonaktif).');
                }

                // Jurnal ta'zir jika ada denda
                if ($denda > 0 && $rek) {
                    $jurnalTazir = $this->jurnal->jurnalTazir(
                        $denda, "Ta'zir keterlambatan angsuran ke-{$angsuran->angsuran_ke}",
                        $user->id, $trx->id, $rek->id, $anggota?->id
                    );
                    if ($jurnalTazir === null) {
                        throw new \RuntimeException('Jurnal ta\'zir gagal diposting (CoA tidak ditemukan/nonaktif).');
                    }
                }

                $this->line("  ✓ [Teller] Bayar angsuran ke-{$angsuran->angsuran_ke} Rp ".number_format($total, 0, ',', '.')." — {$pembiayaan->no_pembiayaan}".($denda > 0 ? ' (denda: Rp '.number_format($denda, 0, ',', '.').')' : ''));
            });
        } catch (\Throwable $e) {
            $this->error('  ✗ bayarAngsuran gagal: '.$e->getMessage());
            Log::error('SimulasiTransaksi::bayarAngsuran', ['error' => $e->getMessage()]);
        }
    }

    private function setorAmlHighValue(User $user, Anggota $anggota, Rekening $rek, float $nominal, string $catatan): void
    {
        try {
            DB::transaction(function () use ($user, $anggota, $rek, $nominal, $catatan) {
                $noTrx = $this->generateNoTrx();
                $saldoBaru = $rek->saldo + $nominal;

                $result = $this->limitSvc->processTransactionWithLimitCheck(
                    $user, 'setor_tunai', $nominal, 'tunai',
                    [
                        'no_transaksi' => $noTrx,
                        'rekening_id' => $rek->id,
                        'anggota_id' => $anggota->id,
                        'jenis_transaksi' => 'Setoran Tunai',
                        'posisi' => 'Debet',
                        'jumlah' => $nominal,
                        'saldo_setelah' => $saldoBaru,
                        'sumber_transaksi' => 'Teller',
                        'catatan' => $catatan,
                    ]
                );

                if ($result['status'] === 'success') {
                    $rek->increment('saldo', $nominal);
                    $trx = $result['data']['transaksi'];
                    $jurnal = $this->jurnal->jurnalSetorTunai($nominal, $catatan, $user->id, $trx->id, $rek->id, $anggota->id);
                    if ($jurnal === null) {
                        throw new \RuntimeException('Jurnal setoran gagal diposting (CoA tidak ditemukan/nonaktif).');
                    }
                    $this->line('  ✓ [Teller] Setoran AML high-value Rp '.number_format($nominal, 0, ',', '.')." — {$anggota->nama_lengkap} [{$result['status']}]");
                } elseif ($result['status'] === 'PENDING_APPROVAL') {
                    // AML high-value (>= Rp 100 juta) wajib diverifikasi Pengurus.
                    // Saldo & posisi kas TIDAK dicatat sampai terverifikasi.
                    $this->line('  ⏳ [Teller] Setoran AML high-value Rp '.number_format($nominal, 0, ',', '.')." — {$anggota->nama_lengkap} → PENDING verifikasi AML Pengurus ⚠");
                }
            });
        } catch (\Throwable $e) {
            $this->error('  ✗ setorAmlHighValue gagal: '.$e->getMessage());
            Log::error('SimulasiTransaksi::setorAmlHighValue', ['error' => $e->getMessage()]);
        }
    }

    private function kasHarianOpening(User $user): void
    {
        try {
            // Modal awal kas BUKAN pendapatan, melainkan pemindahan kas fisik
            // Brankas → Laci Teller. Wajib dijurnal (R6) agar total aset Kas
            // tetap seimbang. Hanya dijurnal saat baris KasHarian BARU dibuat
            // agar re-run command tidak menggandakan jurnal.
            $kas = KasHarian::firstOrCreate(
                ['tanggal' => today(), 'user_id' => $user->id],
                [
                    'modal_awal' => 10_000_000,
                    'catatan_opening' => 'Opening kas harian simulasi '.now()->format('d/m/Y'),
                    'waktu_opening' => now(),
                ]
            );

            if ($kas->wasRecentlyCreated) {
                $keterangan = 'Pembukaan kas teller (modal awal shift) — Rp 10.000.000';
                $jurnal = $this->jurnal->jurnalPembukaanKasTeller((float) $kas->modal_awal, $keterangan, $user->id);
                if ($jurnal === null) {
                    throw new \RuntimeException('Jurnal pembukaan kas gagal diposting (CoA Kas Teller/Brankas tidak ditemukan/nonaktif).');
                }
            }

            $this->line('  ✓ [Teller] Kas harian opening Rp 10.000.000 — '.today()->format('d/m/Y').($kas->wasRecentlyCreated ? ' (baru)' : ' (sudah ada)'));
        } catch (\Throwable $e) {
            $this->error('  ✗ kasHarianOpening gagal: '.$e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  HELPER METHODS — AO
    // ═══════════════════════════════════════════════════════════════

    private function buatPengajuanPembiayaan(User $aoUser, Anggota $anggota): void
    {
        try {
            $noPengajuan = PengajuanPembiayaan::nomorPengajuanBaru('PJN-'.date('Ymd').'-');

            $pengajuan = PengajuanPembiayaan::create([
                'no_pengajuan' => $noPengajuan,
                'anggota_id' => $anggota->id,
                'jenis_akad' => 'Murabahah',
                'margin_persen' => 1.5,
                'jumlah_pengajuan' => 15_000_000,
                'total_pembiayaan' => 17_700_000,
                'angsuran_per_bulan' => 1_475_000,
                'tenor' => 12,
                'tujuan' => 'Modal usaha warung sembako',
                'agunan' => 'BPKB Motor Honda Beat 2022',
                'status' => PengajuanPembiayaan::STATUS_MENUNGGU,
                'ao_id' => $aoUser->id,
                'created_by' => $aoUser->id,
                'step_terakhir' => 1,
                'penghasilan_bulanan' => 4_500_000,
                'pengeluaran_bulanan' => 2_000_000,
                'jumlah_tanggungan' => 2,
                'analisis_5c' => [
                    'character' => 'Anggota aktif, riwayat baik',
                    'capacity' => 'Penghasilan cukup untuk angsuran',
                    'capital' => 'Ada tabungan di koperasi',
                    'collateral' => 'BPKB senilai Rp 18 juta',
                    'condition' => 'Usaha berjalan 2 tahun',
                ],
                'catatan_survei' => 'Survei lapangan '.today()->format('d/m/Y').': usaha aktif, kondisi baik.',
            ]);

            $this->line("  ✓ [AO] Pengajuan pembiayaan baru {$noPengajuan} — {$anggota->nama_lengkap} (Murabahah Rp 15.000.000)");
        } catch (\Throwable $e) {
            $this->error('  ✗ buatPengajuanPembiayaan gagal: '.$e->getMessage());
            Log::error('SimulasiTransaksi::buatPengajuanPembiayaan', ['error' => $e->getMessage()]);
        }
    }

    private function prosesSurveiPengajuan(User $aoUser, PengajuanPembiayaan $pengajuan): void
    {
        try {
            $pengajuan->update([
                'status' => 'Survei Selesai',
                'ao_id' => $aoUser->id,
                'step_terakhir' => 2,
                'catatan_survei' => 'Survei lapangan selesai '.today()->format('d/m/Y').'. Anggota layak dipertimbangkan.',
                'analisis_5c' => array_merge((array) ($pengajuan->analisis_5c ?? []), [
                    'updated_at' => now()->toDateTimeString(),
                    'rekomendasi' => 'Layak disetujui dengan agunan memadai',
                ]),
            ]);
            $this->line("  ✓ [AO] Survei pengajuan {$pengajuan->no_pengajuan} selesai → status: Survei Selesai");
        } catch (\Throwable $e) {
            $this->error('  ✗ prosesSurveiPengajuan gagal: '.$e->getMessage());
        }
    }

    private function setorMelebihiLimitTeller(User $user, Anggota $anggota, Rekening $rek, float $nominal, string $catatan): void
    {
        try {
            DB::transaction(function () use ($user, $anggota, $rek, $nominal, $catatan) {
                $noTrx = $this->generateNoTrx();
                $saldoBaru = $rek->saldo + $nominal;

                $result = $this->limitSvc->processTransactionWithLimitCheck(
                    $user, 'setor_tunai', $nominal, 'tunai',
                    [
                        'no_transaksi' => $noTrx,
                        'rekening_id' => $rek->id,
                        'anggota_id' => $anggota->id,
                        'jenis_transaksi' => 'Setoran Tunai',
                        'posisi' => 'Debet',
                        'jumlah' => $nominal,
                        'saldo_setelah' => $saldoBaru,
                        'sumber_transaksi' => 'AO',
                        'catatan' => $catatan,
                    ]
                );

                if ($result['status'] === 'success') {
                    $rek->increment('saldo', $nominal);
                    $trx = $result['data']['transaksi'];
                    $jurnal = $this->jurnal->jurnalSetorTunai($nominal, $catatan, $user->id, $trx->id, $rek->id, $anggota->id);
                    if ($jurnal === null) {
                        throw new \RuntimeException('Jurnal setoran gagal diposting (CoA tidak ditemukan/nonaktif).');
                    }
                }

                $this->line('  ✓ [AO] Setoran Rp '.number_format($nominal, 0, ',', '.')." — {$anggota->nama_lengkap} [{$result['status']}]");
            });
        } catch (\Throwable $e) {
            $this->error('  ✗ setorMelebihiLimitTeller gagal: '.$e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  HELPER METHODS — MANAJER
    // ═══════════════════════════════════════════════════════════════

    private function approveTransaksiPending(User $manajer, ApprovalTransaksi $approval): void
    {
        try {
            $result = $this->limitSvc->approveTransaction($approval, $manajer, 'Disetujui manajer — simulasi hari ini '.today()->format('d/m/Y'));
            $this->line("  ✓ [Manajer] Approve transaksi {$approval->kode_approval} [{$result['status']}]");
        } catch (\Throwable $e) {
            $this->error('  ✗ approveTransaksiPending gagal: '.$e->getMessage());
        }
    }

    private function reviewPengajuanManajer(User $manajer, PengajuanPembiayaan $pengajuan): void
    {
        try {
            $pengajuan->update([
                'status' => PengajuanPembiayaan::STATUS_DIREVIEW,
                'direview_oleh' => $manajer->id,
                'tanggal_direview' => now(),
                'catatan_manajer' => 'Telah direview manajer '.today()->format('d/m/Y').'. Analisis keuangan memadai, lanjut ke Pengurus.',
                'step_terakhir' => 3,
            ]);
            $this->line("  ✓ [Manajer] Review pengajuan {$pengajuan->no_pengajuan} → status: Direview Manajer");
        } catch (\Throwable $e) {
            $this->error('  ✗ reviewPengajuanManajer gagal: '.$e->getMessage());
        }
    }

    private function transferKasKeBank(User $manajer, float $nominal, string $catatan): void
    {
        try {
            // Debet Bank Koperasi (1-1002), Kredit Kas Teller (1-1001)
            $jurnal = $this->jurnal->post($catatan, [
                ['kode' => AutoJournalingService::BANK_KOPERASI, 'debet' => $nominal],
                ['kode' => AutoJournalingService::KAS_TELLER,    'kredit' => $nominal],
            ], 'Transfer Kas-Bank', null, null, $manajer->id);

            if ($jurnal) {
                $this->line('  ✓ [Manajer] Transfer kas Rp '.number_format($nominal, 0, ',', '.')." ke bank — jurnal {$jurnal->no_jurnal}");
            }
        } catch (\Throwable $e) {
            $this->error('  ✗ transferKasKeBank gagal: '.$e->getMessage());
        }
    }

    private function pengeluaranBeban(User $manajer, float $nominal, string $catatan): void
    {
        try {
            $jurnal = $this->jurnal->jurnalPengeluaranBeban(
                $nominal,
                AutoJournalingService::BEBAN_OPERASIONAL,
                AutoJournalingService::KAS_TELLER,
                $catatan,
                $manajer->id
            );

            if ($jurnal) {
                $this->line('  ✓ [Manajer] Beban operasional Rp '.number_format($nominal, 0, ',', '.')." — jurnal {$jurnal->no_jurnal}");
            }
        } catch (\Throwable $e) {
            $this->error('  ✗ pengeluaranBeban gagal: '.$e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  HELPER METHODS — PENGURUS
    // ═══════════════════════════════════════════════════════════════

    private function setujuiPengajuanPengurus(User $pengurus, PengajuanPembiayaan $pengajuan): void
    {
        try {
            $pengajuan->update([
                'status' => PengajuanPembiayaan::STATUS_DISETUJUI,
                'disetujui_oleh' => $pengurus->id,
                'tanggal_disetujui' => now(),
                'catatan_pengurus' => 'Disetujui Pengurus '.today()->format('d/m/Y').'. Akad dapat dilanjutkan ke DPS.',
                'step_terakhir' => 4,
            ]);

            /*
             | Sinkronisasi wajib: pengajuan Disetujui harus punya record
             | Pembiayaan. Tanpa langkah ini pengajuan menggantung
             | (pembiayaan_id NULL) dan tidak terhitung di Dashboard Pengurus.
             */
            $pembiayaan = $pengajuan->sinkronkanKePembiayaan(Pembiayaan::STATUS_PENDING_DPS);

            if (! $pembiayaan) {
                $alasan = implode('; ', $pengajuan->alasanTidakLayakSinkron());
                $this->error("  ✗ [Pengurus] Pengajuan {$pengajuan->no_pengajuan} disetujui tetapi GAGAL sinkron: {$alasan}");
                Log::error('SimulasiTransaksi::setujuiPengajuanPengurus — sinkronisasi gagal', [
                    'no_pengajuan' => $pengajuan->no_pengajuan,
                    'alasan' => $alasan,
                ]);

                return;
            }

            $this->line("  ✓ [Pengurus] Setujui pengajuan {$pengajuan->no_pengajuan} → Disetujui, kontrak {$pembiayaan->no_pembiayaan} tersinkron");
        } catch (\Throwable $e) {
            $this->error('  ✗ setujuiPengajuanPengurus gagal: '.$e->getMessage());
        }
    }

    private function alokasiShu(User $pengurus, float $nominal, string $catatan): void
    {
        try {
            $jurnal = $this->jurnal->jurnalAlokasiShu($nominal, $catatan, $pengurus->id);
            if ($jurnal) {
                $this->line('  ✓ [Pengurus] Alokasi SHU Rp '.number_format($nominal, 0, ',', '.')." ke cadangan — jurnal {$jurnal->no_jurnal}");
            }
        } catch (\Throwable $e) {
            $this->error('  ✗ alokasiShu gagal: '.$e->getMessage());
        }
    }

    private function kasHarianClosing(User $pengurus): void
    {
        try {
            $kas = KasHarian::whereDate('tanggal', today())->first();
            if (! $kas) {
                $kas = KasHarian::create([
                    'user_id' => $pengurus->id,
                    'tanggal' => today(),
                    'modal_awal' => 10_000_000,
                    'catatan_opening' => 'Opening oleh pengurus simulasi',
                    'waktu_opening' => now()->subHours(8),
                ]);
            }

            if ($kas->sudahClosing()) {
                $this->line('  ℹ [Pengurus] Kas harian hari ini sudah closing. Skip.');

                return;
            }

            // Hitung total transaksi hari ini sebagai saldo sistem
            $totalMasuk = Transaksi::whereDate('waktu_transaksi', today())->whereIn('status_transaksi', ['Sukses'])->where('posisi', 'Debet')->sum('jumlah');
            $totalKeluar = Transaksi::whereDate('waktu_transaksi', today())->whereIn('status_transaksi', ['Sukses'])->where('posisi', 'Kredit')->sum('jumlah');
            $saldoSistem = $kas->modal_awal + $totalMasuk - $totalKeluar;

            $kas->update([
                'fisik_kas' => $saldoSistem, // asumsi fisik = sistem
                'saldo_sistem' => $saldoSistem,
                'selisih' => 0,
                'status_closing' => true,
                'catatan_closing' => 'Closing kas harian simulasi '.today()->format('d/m/Y'),
                'waktu_closing' => now(),
            ]);

            $this->line('  ✓ [Pengurus] Kas harian closing — saldo sistem Rp '.number_format($saldoSistem, 0, ',', '.'));
        } catch (\Throwable $e) {
            $this->error('  ✗ kasHarianClosing gagal: '.$e->getMessage());
        }
    }

    private function bebanDanaKebajikan(User $pengurus, float $nominal, string $catatan): void
    {
        try {
            /* Penyaluran dana kebajikan = dana KELUAR dari akun 2-3001.
             | Posisi jurnal yang benar: DEBET 2-3001 | KREDIT 1-1001 (Kas Teller).
             | Beban Operasional (5-1001) TIDAK dipakai — ta'zir bukan pendapatan
             | maupun beban operasional koperasi (PSAK 109). */
            $jurnal = $this->jurnal->jurnalPenyaluranDanaKebajikan(
                $nominal,
                $catatan,
                $pengurus->id
            );

            if ($jurnal) {
                DanaKebajikan::create([
                    'no_referensi' => 'DK-SALUR-'.now()->format('YmdHis'),
                    'sumber_dana' => 'Penyaluran dana kebajikan',
                    'jenis' => 'Penyaluran',
                    'jumlah' => $nominal,
                    'keterangan' => $catatan,
                    'created_by' => $pengurus->id,
                ]);

                $this->line('  ✓ [Pengurus] Penyaluran dana kebajikan Rp '.number_format($nominal, 0, ',', '.')." — jurnal {$jurnal->no_jurnal}");
            }
        } catch (\Throwable $e) {
            $this->error('  ✗ bebanDanaKebajikan gagal: '.$e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  HELPER METHODS — DPS
    // ═══════════════════════════════════════════════════════════════

    private function pengesahanAkadDPS(User $dps, PengajuanPembiayaan $pengajuan): void
    {
        try {
            $pengajuan->update([
                'status' => 'Disetujui',
                'step_terakhir' => 5,
            ]);

            // Update pembiayaan terkait jika ada
            if ($pengajuan->pembiayaan_id) {
                Pembiayaan::where('id', $pengajuan->pembiayaan_id)->update([
                    'disahkan_oleh' => $dps->id,
                    'tanggal_disahkan' => today(),
                    'catatan_dps' => 'Akad disahkan DPS '.today()->format('d/m/Y').'. Sesuai prinsip syariah.',
                ]);
            }

            $this->line("  ✓ [DPS] Pengesahan akad pengajuan {$pengajuan->no_pengajuan} → status: Disetujui");
        } catch (\Throwable $e) {
            $this->error('  ✗ pengesahanAkadDPS gagal: '.$e->getMessage());
        }
    }

    private function pengesahanPembiayaanDPS(User $dps, Pembiayaan $pembiayaan): void
    {
        try {
            $pembiayaan->update([
                'disahkan_oleh' => $dps->id,
                'tanggal_disahkan' => today(),
                'catatan_dps' => 'Pembiayaan {$pembiayaan->no_pembiayaan} disahkan DPS '.today()->format('d/m/Y').'. Akad sesuai syariah.',
            ]);
            $this->line("  ✓ [DPS] Pengesahan pembiayaan {$pembiayaan->no_pembiayaan} — disahkan DPS");
        } catch (\Throwable $e) {
            $this->error('  ✗ pengesahanPembiayaanDPS gagal: '.$e->getMessage());
        }
    }

    private function jurnalBiayaAdmin(User $user, float $nominal, string $catatan): void
    {
        try {
            $jurnal = $this->jurnal->jurnalBiayaAdmin($nominal, $catatan, $user->id);
            if ($jurnal) {
                $this->line('  ✓ [DPS] Biaya admin/ujroh Rp '.number_format($nominal, 0, ',', '.')." — jurnal {$jurnal->no_jurnal}");
            }
        } catch (\Throwable $e) {
            $this->error('  ✗ jurnalBiayaAdmin gagal: '.$e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  UTILITIES
    // ═══════════════════════════════════════════════════════════════

    private function generateNoTrx(): string
    {
        $prefix = 'TRX-'.date('Ymd').'-';
        $max = (string) Transaksi::withTrashed()->where('no_transaksi', 'like', $prefix.'%')->max('no_transaksi');
        $urut = $max ? ((int) substr($max, -4)) + 1 : 1;

        return $prefix.str_pad((string) $urut, 4, '0', STR_PAD_LEFT);
    }

    private function ringkasanAkhir(): void
    {
        $this->newLine();
        $this->info('  RINGKASAN:');

        $trxHariIni = Transaksi::whereDate('waktu_transaksi', today())->count();
        $trxSukses = Transaksi::whereDate('waktu_transaksi', today())->where('status_transaksi', 'Sukses')->count();
        $trxPending = Transaksi::whereDate('waktu_transaksi', today())->where('status_transaksi', 'Pending')->count();
        $trxAml = Transaksi::whereDate('waktu_transaksi', today())->where('is_aml_high_value', true)->count();
        $jurnalHariIni = Jurnal::whereDate('tanggal_jurnal', today())->where('status', 'Posted')->count();
        $approvalCount = ApprovalTransaksi::whereDate('created_at', today())->count();

        $this->line("  Total transaksi hari ini : {$trxHariIni}");
        $this->line("  Sukses                   : {$trxSukses}");
        $this->line("  Pending approval         : {$trxPending}");
        $this->line("  AML high-value flagged   : {$trxAml}");
        $this->line("  Jurnal Posted hari ini   : {$jurnalHariIni}");
        $this->line("  Approval records         : {$approvalCount}");
        $this->newLine();
        $this->info('  Cek CoA di menu Buku Besar / Neraca untuk melihat semua akun terisi.');
    }
}
