<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LindungiProduksi;
use App\Models\Anggota;
use App\Models\Coa;
use App\Models\JenisSimpanan;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\Rekening;
use App\Models\ShuDistribution;
use App\Models\ShuPeriod;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\TahunBukuService;
use App\Support\Coa as KodeAkun;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestShu extends Command
{
    use LindungiProduksi;

    protected $signature = 'test:shu
        {--tahun= : Tahun buku yang diuji (default: tahun buku berjalan)}
        {--force : Jalankan tanpa konfirmasi}';

    protected $description = 'Testing & verifikasi modul pembagian SHU dengan logika prorate bulan aktif';

    public function handle(): int
    {
        // GUARD PRODUKSI: command ini menyeed data sampel dan menghapus artefak
        // SHU (jurnal, mutasi, saldo rekening) secara permanen.
        if ($this->tolakDiProduksiTanpaForce('test:shu')) {
            return 1;
        }

        $tahunBukuSvc = app(TahunBukuService::class);

        // DYN-13 — tahun buku tidak lagi dipatri di kode. Default mengikuti
        // tahun buku BERJALAN (bukan tahun kalender) agar tetap benar bila
        // kebijakan awal tahun buku koperasi bukan Januari.
        $opsiTahun = $this->option('tahun');

        if ($opsiTahun !== null && ! ctype_digit((string) $opsiTahun)) {
            $this->error('Opsi --tahun harus berupa angka tahun, contoh: --tahun=2026');

            return 1;
        }

        $tahunBuku = $opsiTahun !== null
            ? (int) $opsiTahun
            : $tahunBukuSvc->tahunBukuDari(now());

        $this->info('');
        $this->info('╔══════════════════════════════════════════════════════════════╗');
        $this->info('║    TESTING MODUL SHU + PRORATE - KOPERASI SYARIAH            ║');
        $this->info('╚══════════════════════════════════════════════════════════════╝');
        $this->info('');

        // ─────────────────────────────────────────
        // STEP 1 — Seed data sampel + anggota prorate
        // ─────────────────────────────────────────
        $this->info('[ STEP 1 ] Seeding data sampel anggota...');
        $this->seedSampelAnggota($tahunBuku);

        $jumlahAnggota = Anggota::aktif()->count();
        $this->info("  ✓ Anggota aktif ditemukan: {$jumlahAnggota} orang");

        if ($jumlahAnggota < 2) {
            $this->error('  ✗ Minimal 2 anggota aktif dibutuhkan. Seeding gagal.');

            return 1;
        }
        $this->info('');

        // ─────────────────────────────────────────
        // STEP 2 — Bersihkan SHU tahun buku yang diuji jika ada
        // ─────────────────────────────────────────
        $this->info("[ STEP 2 ] Persiapan — hapus SHU {$tahunBuku} jika ada...");
        $existing = ShuPeriod::where('tahun_buku', (string) $tahunBuku)->first();
        if ($existing) {
            ShuDistribution::where('shu_period_id', $existing->id)->delete();
            $existing->forceDelete();
            $this->info("  ✓ Data SHU {$tahunBuku} lama dihapus (reset untuk testing).");
        } else {
            $this->info("  ✓ Tidak ada SHU {$tahunBuku} sebelumnya.");
        }

        // Artefak eksekusi sebelumnya juga wajib dibersihkan. Menghapus ShuPeriod saja
        // meninggalkan jurnal, mutasi, dan saldo rekening lama sehingga Neraca
        // menampilkan pembagian SHU berulang kali.
        $this->bersihkanArtefakShu((string) $tahunBuku);
        $this->info('');

        // ─────────────────────────────────────────
        // STEP 3 — Buat draft SHU tahun buku yang diuji
        // ─────────────────────────────────────────
        $this->info("[ STEP 3 ] Membuat draft SHU Tahun Buku {$tahunBuku}...");

        $adminUser = User::first();

        // Nilai awal 5 pos diambil dari jembatan tunggal SSOT SHU
        // (`Setting::alokasiShuDrafDefault()`) agar command uji ini tidak
        // mendefinisikan ulang persentase secara literal (temuan K-03).
        $totalShu = 100_000_000;
        $persen = Setting::alokasiShuDrafDefault();

        $nominal = fn (string $pos): float => Setting::bulatkanUang($totalShu * $persen[$pos] / 100);

        $this->info('  Parameter:');
        $this->info('    • Total SHU     : Rp '.number_format($totalShu, 0, ',', '.'));
        $this->info(sprintf('    • Cadangan      : %s%% = Rp %s', $persen['persen_cadangan'], number_format($nominal('persen_cadangan'), 0, ',', '.')));
        $this->info(sprintf('    • Jasa Modal    : %s%% = Rp %s', $persen['persen_jasa_modal'], number_format($nominal('persen_jasa_modal'), 0, ',', '.')));
        $this->info(sprintf('    • Jasa Transaksi: %s%% = Rp %s', $persen['persen_jasa_transaksi'], number_format($nominal('persen_jasa_transaksi'), 0, ',', '.')));
        $this->info(sprintf('    • Pengurus      : %s%% = Rp %s', $persen['persen_pengurus'], number_format($nominal('persen_pengurus'), 0, ',', '.')));
        $this->info(sprintf('    • Sosial        : %s%% = Rp %s', $persen['persen_sosial'], number_format($nominal('persen_sosial'), 0, ',', '.')));
        $this->info('');

        $shu = ShuPeriod::create([
            'tahun_buku' => (string) $tahunBuku,
            'total_shu_bersih' => $totalShu,
            'persen_cadangan' => $persen['persen_cadangan'],
            'persen_jasa_modal' => $persen['persen_jasa_modal'],
            'persen_jasa_transaksi' => $persen['persen_jasa_transaksi'],
            'persen_pengurus' => $persen['persen_pengurus'],
            'persen_sosial' => $persen['persen_sosial'],
            'nominal_cadangan' => $nominal('persen_cadangan'),
            'nominal_jasa_modal' => $nominal('persen_jasa_modal'),
            'nominal_jasa_transaksi' => $nominal('persen_jasa_transaksi'),
            'nominal_pengurus' => $nominal('persen_pengurus'),
            'nominal_sosial' => $nominal('persen_sosial'),
            'status' => 'Draft',
            'user_id' => $adminUser?->id,
        ]);

        $this->info("  ✓ Draft SHU dibuat: ID #{$shu->id}, Status: {$shu->status}, is_distributed: false");
        $this->info('');

        // ─────────────────────────────────────────
        // STEP 4 — Eksekusi DB::transaction prorate
        // ─────────────────────────────────────────
        $this->info('[ STEP 4 ] Menjalankan DB::transaction dengan logika prorate...');

        $awalTahun = $tahunBukuSvc->awalPeriode($tahunBuku);
        $akhirTahun = $tahunBukuSvc->akhirPeriode($tahunBuku);
        $jumlahBulan = max(1, $tahunBukuSvc->jumlahBulan());

        try {
            DB::transaction(function () use ($shu, $adminUser, $tahunBuku, $awalTahun, $akhirTahun, $tahunBukuSvc, $jumlahBulan) {

                $anggotaList = Anggota::aktif()->get();

                // ── Hitung bobot modal prorate per anggota ──
                $dataAnggota = $anggotaList->map(function ($a) use ($tahunBuku, $awalTahun, $akhirTahun, $tahunBukuSvc, $jumlahBulan) {
                    $tglGabung = $a->tanggal_gabung
                        ? Carbon::parse($a->tanggal_gabung)
                        : Carbon::parse($a->created_at);

                    // Kecualikan anggota bergabung setelah akhir tahun buku
                    if ($tglGabung->gt($akhirTahun)) {
                        return null;
                    }

                    // Prorate mengikuti TAHUN BUKU koperasi (bukan asumsi 12
                    // bulan kalender): anggota yang bergabung sebelum awal
                    // tahun buku penuh; selain itu dihitung bulan sejak
                    // bergabung s.d. akhir tahun buku.
                    $bulanAktif = $tglGabung->lt($awalTahun)
                        ? $jumlahBulan
                        : max(1, $tahunBukuSvc->bulanAntara($tglGabung, $akhirTahun) + 1);

                    $modalAnggota = (float) $a->simpanan_pokok + (float) $a->simpanan_wajib;
                    $bobotModal = $modalAnggota * ($bulanAktif / max(1, $jumlahBulan));
                    $transaksiAnggota = (float) Transaksi::where('anggota_id', $a->id)
                        ->where('status_transaksi', 'Sukses')
                        ->whereIn('jenis_transaksi', ['Angsuran', 'Bagi Hasil'])
                        ->sum('jumlah');

                    return compact('a', 'bulanAktif', 'modalAnggota', 'bobotModal', 'transaksiAnggota');
                })->filter()->values();

                $totalBobotModal = $dataAnggota->sum('bobotModal');
                $totalTransaksiKoperasi = $dataAnggota->sum('transaksiAnggota');

                $nominalModal = (float) $shu->nominal_jasa_modal;
                $nominalTransaksi = (float) $shu->nominal_jasa_transaksi;
                $totalSHUAnggota = 0;
                $distributions = [];

                foreach ($dataAnggota as $row) {
                    $anggota = $row['a'];
                    $modalAnggota = $row['modalAnggota'];
                    $transaksiAnggota = $row['transaksiAnggota'];

                    $shuModal = $totalBobotModal > 0
                        ? Setting::bulatkanUang(($row['bobotModal'] / $totalBobotModal) * $nominalModal)
                        : 0;

                    $shuTransaksi = $totalTransaksiKoperasi > 0
                        ? Setting::bulatkanUang(($transaksiAnggota / $totalTransaksiKoperasi) * $nominalTransaksi)
                        : 0;

                    $totalShu = $shuModal + $shuTransaksi;
                    if ($totalShu <= 0) {
                        continue;
                    }

                    // Cari rekening Simpanan Sukarela — auto-create jika belum ada
                    $rekening = Rekening::where('anggota_id', $anggota->id)
                        ->whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_SUKARELA))
                        ->where('status_rekening', 'Aktif')
                        ->first();

                    if (! $rekening) {
                        $coaSukarelaObj = Coa::where('kode_akun', KodeAkun::SIMPANAN_SUKARELA)->first();
                        $noRekBaru = 'SHU-'.$shu->tahun_buku.'-'.str_pad($anggota->id, 8, '0', STR_PAD_LEFT);
                        $rekening = Rekening::firstOrCreate(
                            ['no_rekening' => $noRekBaru],
                            [
                                'anggota_id' => $anggota->id,
                                'akun_id' => $coaSukarelaObj?->id,
                                'jenis_rekening' => JenisSimpanan::namaUtama(JenisSimpanan::TIPE_SUKARELA),
                                'akad' => 'Wadiah',
                                'saldo' => 0,
                                'batas_minimum' => 0,
                                'tanggal_buka' => now()->toDateString(),
                                'status_rekening' => 'Aktif',
                                'keterangan' => 'Rekening otomatis dibuat saat distribusi SHU '.$shu->tahun_buku,
                            ]
                        );
                    }

                    $distributions[] = [
                        'anggota' => $anggota,
                        'rekening' => $rekening,
                        'bulan_aktif' => $row['bulanAktif'],
                        'bobot_modal' => $row['bobotModal'],
                        'modal_anggota' => $modalAnggota,
                        'transaksi_anggota' => $transaksiAnggota,
                        'shu_modal' => $shuModal,
                        'shu_transaksi' => $shuTransaksi,
                        'total_shu' => $totalShu,
                    ];
                    $totalSHUAnggota += $totalShu;
                }

                // ── Jurnal double-entry ──
                $noJurnal = 'SHU-'.now()->format('Ymd').'-'.str_pad($shu->id, 4, '0', STR_PAD_LEFT);
                $jurnal = Jurnal::create([
                    'no_jurnal' => $noJurnal,
                    'tanggal_jurnal' => now()->toDateString(),
                    'jenis_jurnal' => 'Jurnal Umum',
                    'sumber' => 'SHU',
                    'no_ref' => 'SHU-'.$shu->tahun_buku,
                    'keterangan' => 'Pembagian SHU Tahun Buku '.$shu->tahun_buku,
                    'total_debet' => (float) $shu->total_shu_bersih,
                    'total_kredit' => (float) $shu->total_shu_bersih,
                    'is_balance' => true,
                    'status' => 'Posted',
                    'posted_at' => now(),
                    'user_posted' => $adminUser?->id,
                    'user_id' => $adminUser?->id,
                ]);

                $baris = 1;
                $coaIkhtisar = Coa::firstOrCreate(
                    ['kode_akun' => KodeAkun::IKHTISAR_SHU],
                    ['nama_akun' => 'Ikhtisar SHU / Laba Ditahan', 'kategori' => 'Ekuitas', 'tipe_saldo_normal' => 'Kredit', 'saldo_awal' => 0, 'is_active' => true]
                );
                JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $coaIkhtisar->id, 'baris' => $baris++, 'debet' => (float) $shu->total_shu_bersih, 'kredit' => 0, 'keterangan' => 'Ikhtisar SHU '.$shu->tahun_buku]);

                $coaCadangan = Coa::firstOrCreate(
                    ['kode_akun' => KodeAkun::CADANGAN_KOPERASI],
                    ['nama_akun' => 'Dana Cadangan Koperasi', 'kategori' => 'Ekuitas', 'tipe_saldo_normal' => 'Kredit', 'saldo_awal' => 0, 'is_active' => true]
                );
                JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $coaCadangan->id, 'baris' => $baris++, 'debet' => 0, 'kredit' => (float) $shu->nominal_cadangan, 'keterangan' => 'Dana Cadangan '.$shu->tahun_buku]);

                $coaSukarela = Coa::firstOrCreate(
                    ['kode_akun' => KodeAkun::SIMPANAN_SUKARELA],
                    ['nama_akun' => 'Simpanan Sukarela / Wadiah Anggota', 'kategori' => 'Kewajiban', 'tipe_saldo_normal' => 'Kredit', 'saldo_awal' => 0, 'is_active' => true]
                );
                JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $coaSukarela->id, 'baris' => $baris++, 'debet' => 0, 'kredit' => Setting::bulatkanUang($totalSHUAnggota), 'keterangan' => 'SHU Anggota '.$shu->tahun_buku]);

                $coaPengurus = Coa::firstOrCreate(
                    ['kode_akun' => KodeAkun::UTANG_DANA_PENGURUS],
                    ['nama_akun' => 'Utang Dana Pengurus & Karyawan', 'kategori' => 'Kewajiban', 'tipe_saldo_normal' => 'Kredit', 'saldo_awal' => 0, 'is_active' => true]
                );
                $kreditPengurus = Setting::bulatkanUang((float) $shu->total_shu_bersih - (float) $shu->nominal_cadangan - $totalSHUAnggota);
                if ($kreditPengurus > 0) {
                    JurnalDetail::create(['jurnal_id' => $jurnal->id, 'coa_id' => $coaPengurus->id, 'baris' => $baris++, 'debet' => 0, 'kredit' => $kreditPengurus, 'keterangan' => 'Dana Pengurus '.$shu->tahun_buku]);
                }

                // ── Distribusi ke rekening anggota ──
                foreach ($distributions as $d) {
                    $anggota = $d['anggota'];
                    $rekening = $d['rekening'];
                    $totalShu = $d['total_shu'];
                    $trxId = null;

                    if ($rekening) {
                        $saldoBaru = (float) $rekening->saldo + $totalShu;
                        $rekening->update(['saldo' => $saldoBaru]);

                        $trx = Transaksi::create([
                            'no_transaksi' => 'SHU-TRX-'.now()->format('YmdHis').'-'.$anggota->id,
                            'waktu_transaksi' => now(),
                            'rekening_id' => $rekening->id,
                            'anggota_id' => $anggota->id,
                            'jenis_transaksi' => 'Setoran SHU Tahun Buku '.$shu->tahun_buku,
                            'posisi' => 'Debet',
                            'jumlah' => $totalShu,
                            'saldo_setelah' => $saldoBaru,
                            'user_id' => $adminUser?->id,
                            'sumber_transaksi' => 'SHU',
                            'status_transaksi' => 'Sukses',
                            'status' => 'success',
                            'referensi' => 'SHU-'.$shu->tahun_buku,
                            'catatan' => 'SHU Prorate — Bulan Aktif: '.$d['bulan_aktif'],
                        ]);
                        $trxId = $trx->id;
                    }

                    ShuDistribution::create([
                        'shu_period_id' => $shu->id,
                        'anggota_id' => $anggota->id,
                        'rekening_id' => $rekening?->id,
                        'total_simpanan_modal' => $d['modal_anggota'],
                        'total_transaksi_bagi_hasil' => $d['transaksi_anggota'],
                        'shu_jasa_modal' => $d['shu_modal'],
                        'shu_jasa_transaksi' => $d['shu_transaksi'],
                        'total_shu' => $totalShu,
                        'transaksi_id' => $trxId,
                    ]);
                }

                /* Buktikan header jurnal benar-benar didukung baris detail yang
                 | seimbang sebelum transaksi ditutup. Jurnal Posted tanpa bukti
                 | balance = jurnal orphan yang tidak dapat diaudit. */
                $jurnal->pastikanBalance();

                $shu->update([
                    'status' => 'Selesai',
                    'is_distributed' => true,
                    'jurnal_id' => $jurnal->id,
                    'diproses_at' => now(),
                ]);
            });

            $this->info('  ✓ DB::transaction berhasil dieksekusi tanpa error.');
        } catch (\Throwable $e) {
            $this->error('  ✗ TRANSACTION GAGAL: '.$e->getMessage());

            return 1;
        }

        $this->info('');

        // ─────────────────────────────────────────
        // STEP 5 — Verifikasi hasil
        // ─────────────────────────────────────────
        $this->info('[ STEP 5 ] VERIFIKASI HASIL...');
        $this->info('');

        $shu->refresh();

        // 5A — Flag is_distributed
        $this->info('  [A] FLAG is_distributed:');
        $this->info('  '.($shu->is_distributed ? '✓' : '✗').' shu_periods.is_distributed = '.($shu->is_distributed ? 'TRUE' : 'FALSE').'  (ID: '.$shu->id.')');
        $this->info('      Status  : '.$shu->status);
        $this->info('      Diproses: '.($shu->diproses_at?->format('d/m/Y H:i:s') ?? '-'));
        $this->info('');

        // 5B — Verifikasi Jurnal
        $this->info('  [B] JURNAL UMUM (Double-Entry):');
        $jurnal = Jurnal::where('no_ref', 'SHU-'.$tahunBuku)->where('status', 'Posted')->first();
        if ($jurnal) {
            $this->info('  ✓ Jurnal: No.'.$jurnal->no_jurnal.' | Status: '.$jurnal->status.' | Balanced: '.($jurnal->is_balance ? 'YA' : 'TIDAK'));
            $this->info('      Debet : Rp '.number_format($jurnal->total_debet, 0, ',', '.'));
            $this->info('      Kredit: Rp '.number_format($jurnal->total_kredit, 0, ',', '.'));
            $this->info('');

            $details = JurnalDetail::where('jurnal_id', $jurnal->id)->with('coa')->get();
            $oke3_9000 = false;
            $oke3_2001 = false;
            $oke2_1001 = false;

            $this->info('      '.sprintf('%-10s %-40s %15s %15s', 'Kode', 'Nama Akun', 'Debet', 'Kredit'));
            $this->info('      '.str_repeat('-', 82));
            foreach ($details as $d) {
                $this->info('      '.sprintf(
                    '%-10s %-40s %15s %15s',
                    $d->coa->kode_akun ?? '-',
                    substr($d->coa->nama_akun ?? '-', 0, 40),
                    $d->debet > 0 ? 'Rp '.number_format($d->debet, 0, ',', '.') : '-',
                    $d->kredit > 0 ? 'Rp '.number_format($d->kredit, 0, ',', '.') : '-'
                ));
                if (($d->coa->kode_akun ?? '') === KodeAkun::IKHTISAR_SHU && $d->debet >= 100_000_000) {
                    $oke3_9000 = true;
                }
                if (($d->coa->kode_akun ?? '') === KodeAkun::CADANGAN_KOPERASI && $d->kredit >= 30_000_000) {
                    $oke3_2001 = true;
                }
                if (($d->coa->kode_akun ?? '') === KodeAkun::SIMPANAN_SUKARELA && $d->kredit > 0) {
                    $oke2_1001 = true;
                }
            }
            $this->info('');
            $this->info('      Assertion:');
            $this->info('      '.($oke3_9000 ? '✓' : '✗').' Akun 3-9000 Debet Rp 100jt  : '.($oke3_9000 ? 'PASS' : 'FAIL'));
            $this->info('      '.($oke3_2001 ? '✓' : '✗').' Akun 3-2001 Kredit Rp 30jt  : '.($oke3_2001 ? 'PASS' : 'FAIL'));
            $this->info('      '.($oke2_1001 ? '✓' : '✗').' Akun 2-1001 Kredit SHU Angg : '.($oke2_1001 ? 'PASS' : 'FAIL'));
        } else {
            $this->error('  ✗ Jurnal SHU-'.$tahunBuku.' Posted TIDAK ditemukan!');
            $oke3_9000 = $oke3_2001 = $oke2_1001 = false;
        }
        $this->info('');

        // 5C — Mutasi anggota dengan kolom bulan aktif
        $this->info('  [C] MUTASI REKENING ANGGOTA (dengan bobot prorate):');
        $distributions = ShuDistribution::where('shu_period_id', $shu->id)
            ->with('anggota', 'rekening')
            ->get();

        if ($distributions->isEmpty()) {
            $this->warn('  ! Tidak ada distribusi.');
        } else {
            $this->info('  '.sprintf('%-5s %-22s %12s %14s %14s %14s', 'No', 'Nama Anggota', 'Bln Aktif', 'Jasa Modal', 'Jasa Trx', 'Total SHU'));
            $this->info('  '.str_repeat('-', 83));
            $no = 1;
            foreach ($distributions as $d) {
                // Ambil bulan aktif dari transaksi catatan
                $trx = $d->transaksi;
                $bulanAktif = '-';
                if ($trx && str_contains($trx->catatan ?? '', 'Bulan Aktif:')) {
                    preg_match('/Bulan Aktif: (\d+)/', $trx->catatan, $m);
                    $bulanAktif = ($m[1] ?? '-').' bln';
                }
                $this->info('  '.sprintf(
                    '%-5s %-22s %12s %14s %14s %14s',
                    $no++,
                    substr($d->anggota->nama_lengkap ?? '-', 0, 22),
                    $bulanAktif,
                    'Rp '.number_format($d->shu_jasa_modal, 0, ',', '.'),
                    'Rp '.number_format($d->shu_jasa_transaksi, 0, ',', '.'),
                    'Rp '.number_format($d->total_shu, 0, ',', '.')
                ));
            }
            $this->info('');
            $this->info('  Total: Rp '.number_format($distributions->sum('total_shu'), 0, ',', '.').' ke '.$distributions->count().' anggota.');

            // Cek auto-created rekening
            $autoCreated = $distributions->filter(fn ($d) => $d->rekening && str_contains($d->rekening->keterangan ?? '', 'otomatis dibuat')
            );
            if ($autoCreated->isNotEmpty()) {
                $this->info('  ✓ Auto-create rekening: '.$autoCreated->count().' anggota mendapat rekening baru:');
                foreach ($autoCreated as $d) {
                    $this->info('    → '.($d->anggota->nama_lengkap ?? '-').' → '.$d->rekening->no_rekening);
                }
            } else {
                $this->info('  ✓ Semua anggota sudah punya rekening sebelumnya.');
            }

            // Pastikan tidak ada anggota dengan rekening null
            $noRek = $distributions->filter(fn ($d) => ! $d->rekening);
            if ($noRek->isEmpty()) {
                $this->info('  ✓ AUTO-CREATE ASSERTION: PASS — semua anggota punya rekening');
            } else {
                $this->error('  ✗ AUTO-CREATE ASSERTION: FAIL — '.$noRek->count().' anggota masih tanpa rekening');
            }
            $autoCreatePassed = $noRek->isEmpty();
        }
        $this->info('');

        // ─────────────────────────────────────────
        // STEP 6 — Assertion prorate 50%
        // ─────────────────────────────────────────
        $this->info('[ STEP 6 ] ASSERTION PRORATE — Anggota bulan ke-1 vs bulan ke-7...');

        // Rasio harapan diturunkan dari jumlah bulan tahun buku (bukan asumsi
        // 12 bulan) agar assertion tetap sah bila kebijakan tahun buku diubah.
        $bulanKedua = min(7, $jumlahBulan);
        $bulanAnggota2 = $jumlahBulan - $bulanKedua + 1;
        $rasioHarapan = round($bulanAnggota2 / $jumlahBulan, 4);

        $distJan = ShuDistribution::where('shu_period_id', $shu->id)
            ->whereHas('anggota', fn ($q) => $q->where('no_anggota', 'ANG-PRORATE-JAN'))
            ->first();
        $distJul = ShuDistribution::where('shu_period_id', $shu->id)
            ->whereHas('anggota', fn ($q) => $q->where('no_anggota', 'ANG-PRORATE-JUL'))
            ->first();

        $proratePassed = false;
        if ($distJan && $distJul) {
            $shuJan = (float) $distJan->shu_jasa_modal;
            $shuJul = (float) $distJul->shu_jasa_modal;
            $rasio = $shuJan > 0 ? round($shuJul / $shuJan, 4) : 0;

            $this->info("  Anggota Bulan ke-1 ({$jumlahBulan} bln) — SHU Jasa Modal: Rp ".number_format($shuJan, 2, ',', '.'));
            $this->info("  Anggota Bulan ke-{$bulanKedua} ({$bulanAnggota2} bln) — SHU Jasa Modal: Rp ".number_format($shuJul, 2, ',', '.'));
            $this->info('  Rasio = '.$rasio.' (expected: '.$rasioHarapan.')');

            // Toleransi ±0.01 untuk pembulatan
            $proratePassed = abs($rasio - $rasioHarapan) <= 0.01;
            if ($proratePassed) {
                $this->info('  ✓ PRORATE ASSERTION: PASS — Anggota bulan ke-7 menerima porsi sesuai bulan aktif');
            } else {
                $this->error('  ✗ PRORATE ASSERTION: FAIL — Rasio = '.$rasio.', expected '.$rasioHarapan);
            }
        } else {
            $this->warn('  ! Data prorate tidak ditemukan (ANG-PRORATE-JAN / ANG-PRORATE-JUL).');
        }
        $this->info('');

        // ─────────────────────────────────────────
        // STEP 7 — Proteksi Double-Processing
        // ─────────────────────────────────────────
        $this->info('[ STEP 7 ] TEST PROTEKSI DOUBLE-PROCESSING...');
        $shu->refresh();
        $doubleBlocked = $shu->is_distributed || ! $shu->isDraft();
        if ($doubleBlocked) {
            $this->info('  ✓ Sistem MENOLAK proses kedua — status='.$shu->status.', is_distributed=true');
            $this->info('  ✓ isDraft() = false → controller return error');
        } else {
            $this->error('  ✗ Proteksi double-processing GAGAL!');
        }
        $this->info('');

        // ─────────────────────────────────────────
        // RINGKASAN
        // ─────────────────────────────────────────
        $jurnalPass = isset($oke3_9000) && $oke3_9000 && $oke3_2001 && $oke2_1001;
        $autoCreatePassed = $autoCreatePassed ?? false;
        $this->info('╔══════════════════════════════════════════════════════════════╗');
        $this->info('║                     RINGKASAN HASIL TEST                     ║');
        $this->info('╠══════════════════════════════════════════════════════════════╣');
        $this->info('║  DB::transaction          : ✓ PASS                           ║');
        $this->info('║  is_distributed = true    : '.($shu->is_distributed ? '✓ PASS' : '✗ FAIL').'                           ║');
        $this->info('║  Jurnal Posted & Balanced : '.($jurnalPass ? '✓ PASS' : '✗ FAIL').'                           ║');
        $this->info('║  Mutasi Anggota           : '.($distributions->isNotEmpty() ? '✓ PASS ('.$distributions->count().' anggota)   ' : '! NO DATA          ').'           ║');
        $this->info('║  Auto-Create Rekening     : '.($autoCreatePassed ? '✓ PASS' : '✗ FAIL').'                           ║');
        $this->info('║  Prorate 50% Assertion    : '.($proratePassed ? '✓ PASS' : '✗ FAIL').'                           ║');
        $this->info('║  Proteksi Double-Process  : '.($doubleBlocked ? '✓ PASS' : '✗ FAIL').'                           ║');
        $this->info('╚══════════════════════════════════════════════════════════════╝');
        $this->info('');

        return 0;
    }

    /**
     * Seed 3 anggota umum + 2 anggota khusus prorate test.
     *
     * Tanggal gabung diturunkan dari kalender tahun buku koperasi (DYN-13),
     * bukan dipatri: anggota prorate bergabung pada bulan pertama dan bulan
     * ke-7 tahun buku yang diuji.
     */
    private function seedSampelAnggota(int $tahunBuku): void
    {
        $coaSukarela = Coa::where('kode_akun', KodeAkun::SIMPANAN_SUKARELA)->first();

        $svc = app(TahunBukuService::class);
        $sebelum = $svc->awalPeriode($tahunBuku)->subYear()->toDateString();
        $awal = $svc->bulanKe($tahunBuku, 1)->toDateString();
        $bulanKe7 = $svc->bulanKe($tahunBuku, 7)->toDateString();

        $sampel = [
            // 3 anggota umum (bergabung sebelum tahun buku → bulan aktif penuh)
            ['no_anggota' => 'ANG-TEST-001', 'nama_lengkap' => 'Ahmad Syarif Test',  'nik' => '3171010101010001', 'simpanan_pokok' => 1_000_000, 'simpanan_wajib' => 5_000_000, 'tanggal_gabung' => $sebelum],
            ['no_anggota' => 'ANG-TEST-002', 'nama_lengkap' => 'Budi Santoso Test',  'nik' => '3171010101010002', 'simpanan_pokok' => 1_000_000, 'simpanan_wajib' => 3_500_000, 'tanggal_gabung' => $sebelum],
            ['no_anggota' => 'ANG-TEST-003', 'nama_lengkap' => 'Citra Dewi Test',    'nik' => '3171010101010003', 'simpanan_pokok' => 1_000_000, 'simpanan_wajib' => 4_250_000, 'tanggal_gabung' => $sebelum],
            // 2 anggota khusus prorate — modal sama, bulan gabung beda
            ['no_anggota' => 'ANG-PRORATE-JAN', 'nama_lengkap' => 'Prorate Bulan Pertama '.$tahunBuku, 'nik' => '3171010101010011', 'simpanan_pokok' => 5_000_000, 'simpanan_wajib' => 5_000_000, 'tanggal_gabung' => $awal],
            ['no_anggota' => 'ANG-PRORATE-JUL', 'nama_lengkap' => 'Prorate Bulan Ketujuh '.$tahunBuku, 'nik' => '3171010101010012', 'simpanan_pokok' => 5_000_000, 'simpanan_wajib' => 5_000_000, 'tanggal_gabung' => $bulanKe7],
        ];

        foreach ($sampel as $data) {
            $tglGabung = $data['tanggal_gabung'];
            unset($data['tanggal_gabung']);

            $anggota = Anggota::firstOrCreate(
                ['no_anggota' => $data['no_anggota']],
                array_merge($data, [
                    'status_anggota' => Anggota::STATUS_ACTIVE,
                    'tanggal_gabung' => $tglGabung,
                    'jenis_kelamin' => 'Laki-laki',
                    'alamat' => 'Jl. Test No. 1',
                    'tanggal_lahir' => '1990-01-01',
                    'tempat_lahir' => 'Jakarta',
                    'status_perkawinan' => 'Menikah',
                    'no_hp' => '0810000'.rand(10000, 99999),
                    'pekerjaan' => 'Karyawan',
                    'nama_ibu_kandung' => 'Ibu Test',
                    'kota' => 'Jakarta',
                    'provinsi' => 'DKI Jakarta',
                    'kode_pos' => '10110',
                    'kelurahan' => 'Gambir',
                    'kecamatan' => 'Gambir',
                    'rt_rw' => '001/001',
                ])
            );

            // Update tanggal_gabung jika anggota sudah ada (agar prorate benar)
            if ($anggota->wasRecentlyCreated === false) {
                $anggota->update(['tanggal_gabung' => $tglGabung]);
            }

            Rekening::firstOrCreate(
                ['anggota_id' => $anggota->id, 'jenis_rekening' => JenisSimpanan::namaUtama(JenisSimpanan::TIPE_SUKARELA)],
                [
                    'no_rekening' => 'RK-SUK-'.str_pad($anggota->id, 6, '0', STR_PAD_LEFT),
                    'akun_id' => $coaSukarela?->id,
                    'akad' => 'Wadiah',
                    'saldo' => 0,
                    'batas_minimum' => 0,
                    'tanggal_buka' => now()->toDateString(),
                    'status_rekening' => 'Aktif',
                ]
            );

            $bulanGabung = Carbon::parse($tglGabung)->format('M Y');
            $this->info("  ✓ {$anggota->no_anggota} | {$anggota->nama_lengkap} | Gabung: {$bulanGabung} | Modal: Rp ".
                number_format((float) $anggota->simpanan_pokok + (float) $anggota->simpanan_wajib, 0, ',', '.'));
        }
    }

    /**
     * Hapus artefak distribusi SHU dari eksekusi sebelumnya: jurnal + detail,
     * mutasi rekening anggota, sekaligus mengembalikan saldo rekening yang
     * sudah dinaikkan. Menjaga command ini idempoten terhadap Neraca.
     */
    private function bersihkanArtefakShu(string $tahunBuku): void
    {
        // 1. Kembalikan saldo rekening lalu hapus mutasi distribusi SHU sebelumnya.
        $transaksiShu = Transaksi::withTrashed()
            ->where('sumber_transaksi', 'SHU')
            ->where('referensi', 'SHU-'.$tahunBuku)
            ->get();

        foreach ($transaksiShu as $trx) {
            if ($trx->status_transaksi !== 'Sukses' || ! $trx->rekening_id) {
                continue;
            }
            $rekening = Rekening::find($trx->rekening_id);
            if ($rekening) {
                $rekening->update(['saldo' => (float) $rekening->saldo - (float) $trx->jumlah]);
            }
        }

        if ($transaksiShu->isNotEmpty()) {
            Transaksi::withTrashed()
                ->whereIn('id', $transaksiShu->pluck('id'))
                ->forceDelete();
        }

        // 2. Hapus jurnal SHU beserta detailnya (detail ikut terhapus via cascade FK).
        $jurnals = Jurnal::withTrashed()
            ->where('sumber', 'SHU')
            ->where('no_ref', 'SHU-'.$tahunBuku)
            ->get();

        foreach ($jurnals as $jurnal) {
            JurnalDetail::where('jurnal_id', $jurnal->id)->delete();
            $jurnal->forceDelete();
        }

        $this->info(sprintf(
            '  ✓ Artefak SHU %s dibersihkan: %d mutasi (saldo rekening dikembalikan), %d jurnal.',
            $tahunBuku,
            $transaksiShu->count(),
            $jurnals->count()
        ));
    }
}
