<?php

namespace App\Services;

use App\Models\ApprovalTransaksi;
use App\Models\ApprovalTransaksiLog;
use App\Models\Pembiayaan;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use App\Support\HierarkiRole;
use App\Support\JenisTransaksi;
use App\Support\PemisahanTugas;
use App\Support\Setting;
use Illuminate\Support\Facades\DB;

class TransactionLimitService
{
    /**
     * Proses transaksi dengan pengecekan batas peran dan approval workflow.
     * State machine: DRAFT -> PENDING_APPROVAL -> APPROVED -> SUCCESS / REJECTED
     *
     * Seluruh batas (nominal per transaksi, akumulasi harian, frekuensi aksi)
     * dan rantai penyetuju dibaca dari App\Support\HierarkiRole. Menambah peran
     * baru atau mengubah batas TIDAK memerlukan perubahan pada kelas ini.
     */
    public function processTransactionWithLimitCheck(
        User $user,
        string $jenisTransaksi,   // setor_tunai, tarik_tunai, non_tunai, pencairan, angsuran
        float $nominal,
        string $jenisPembayaran,  // tunai, non_tunai
        array $transaksiData,    // data untuk insert ke tabel transaksi
        string $catatanPengaju = ''
    ): array {
        // 1. Teller tidak boleh non-tunai
        if ($user->isTeller() && $jenisPembayaran === 'non_tunai') {
            return ['status' => 'REJECTED', 'message' => 'Teller tidak memiliki akses transaksi non-tunai.', 'data' => null];
        }

        // 2. Jenis batas yang berlaku: tiga jenis transaksi operasional, sisanya
        //    (pencairan, angsuran, …) dinilai sebagai non-tunai.
        $jenisBatas = in_array($jenisTransaksi, ['setor_tunai', 'tarik_tunai', 'non_tunai'], true)
            ? $jenisTransaksi
            : 'non_tunai';

        // Batas peran dibaca dari satu sumber kebenaran (config + override DB).
        $batas = HierarkiRole::batas($user->role, $jenisBatas);

        // 3. Penilaian batas: nominal per transaksi, akumulasi harian, dan
        //    frekuensi aksi. Ambang AML/PPU-PPT tetap berlaku sebagai lapisan
        //    kepatuhan tambahan yang tidak dapat dimatikan oleh konfigurasi.
        $penilaian = $this->nilaiBatas($user, $jenisBatas, $nominal, $batas);

        $perluApproval = $penilaian['butuh_approval'];
        $approvalRole = $penilaian['approval_role'];

        // Transaksi bernilai besar (>= ambang kebijakan, default Rp 100 juta)
        // wajib melewati verifikasi kepatuhan AML/PPU-PPT Pengurus sebelum
        // saldo & posisi kas dicatat.
        $isAmlHighValue = $nominal >= Setting::amlThreshold();
        if ($isAmlHighValue) {
            $perluApproval = true;
            // Wajib naik ke pemutus TERTINGGI pada hierarki (bukan nama peran
            // literal), sehingga penambahan/penataan ulang peran ikut berlaku.
            $approvalRole = HierarkiRole::penyetujuTertinggi() ?? $approvalRole;
        }

        // Penolakan keras: melampaui batas keras peran (mis. batas harian atau
        // frekuensi aksi) yang memang tidak dapat dilampaui lewat approval.
        if ($penilaian['ditolak']) {
            return ['status' => 'REJECTED', 'message' => $penilaian['alasan'], 'data' => null];
        }

        // Bila pengajuan memerlukan pemutus tetapi rantai approval tidak
        // menyediakan peran penyetuju, transaksi TIDAK boleh lolos diam-diam.
        if ($perluApproval && ! $approvalRole) {
            return [
                'status' => 'REJECTED',
                'message' => 'Transaksi ini memerlukan otorisasi, tetapi tidak ada peran penyetuju yang berwenang. Hubungi Pengurus untuk menetapkan rantai approval.',
                'data' => null,
            ];
        }

        $statusMenunggu = $approvalRole ? HierarkiRole::statusMenunggu($approvalRole) : null;

        return DB::transaction(function () use ($user, $jenisTransaksi, $nominal, $jenisPembayaran, $transaksiData, $catatanPengaju, $perluApproval, $approvalRole, $statusMenunggu, $batas, $isAmlHighValue) {
            if ($perluApproval) {
                // Buat approval request
                $approval = ApprovalTransaksi::create([
                    'kode_approval' => ApprovalTransaksi::generateKode(),
                    'tipe_referensi' => 'transaksi',
                    'referensi_id' => 0, // akan diupdate setelah transaksi dibuat
                    'jenis_transaksi' => $jenisTransaksi,
                    'nominal' => $nominal,
                    'jenis_pembayaran' => $jenisPembayaran,
                    'status' => $statusMenunggu,
                    'diajukan_oleh' => $user->id,
                    'role_pengaju' => $user->role,
                    'catatan_pengaju' => $catatanPengaju,
                ]);

                // Buat transaksi dengan status PENDING
                // Saldo rekening & posisi kas TIDAK dicatat sebelum disetujui.
                $transaksiData['status_transaksi'] = 'Pending';
                $transaksiData['status'] = 'pending_approval';
                $transaksiData['jenis_pembayaran'] = $jenisPembayaran;
                $transaksiData['approval_id'] = $approval->id;
                $transaksiData['dibuat_oleh'] = $user->id;
                $transaksiData['user_id'] = $user->id;
                $transaksiData['waktu_transaksi'] = now();

                // Penanda pengawasan AML/PPU-PPT
                if ($jenisTransaksi === 'setor_tunai') {
                    $transaksiData['is_large_transaction'] = true;
                }
                if ($isAmlHighValue) {
                    $transaksiData['is_large_transaction'] = true;
                    $transaksiData['is_aml_high_value'] = true;
                    $transaksiData['aml_status_verifikasi'] = 'menunggu';
                }

                $transaksi = Transaksi::create($transaksiData);

                // Update referensi_id
                $approval->update(['referensi_id' => $transaksi->id]);

                // Jejak audit: pengajuan otorisasi masuk ke rantai approval.
                ApprovalTransaksiLog::catatPeristiwa($approval, ApprovalTransaksiLog::PERISTIWA_DIAJUKAN, $user, [
                    'status_sebelum' => null,
                    'status_sesudah' => $statusMenunggu,
                    'role_berwenang' => $approvalRole,
                    'catatan' => $catatanPengaju,
                ]);

                $nominalFormatted = number_format($nominal, 0, ',', '.');

                $message = $isAmlHighValue
                    ? "Setoran bernilai Rp {$nominalFormatted} wajib diverifikasi kepatuhan AML/PPU-PPT oleh Pengurus. Saldo belum ditambahkan sampai diverifikasi."
                    : "Transaksi bernilai Rp {$nominalFormatted} melebihi limit otorisasi (Rp ".number_format((float) ($batas['limit_per_transaksi'] ?? 0), 0, ',', '.').'). Pengajuan otorisasi telah dikirim ke '.HierarkiRole::label($approvalRole).'.';

                return [
                    'status' => 'PENDING_APPROVAL',
                    'message' => $message,
                    'data' => ['transaksi' => $transaksi, 'approval' => $approval],
                ];
            }

            // Langsung SUCCESS
            $transaksiData['status_transaksi'] = 'Sukses';
            $transaksiData['status'] = 'success';
            $transaksiData['jenis_pembayaran'] = $jenisPembayaran;
            $transaksiData['dibuat_oleh'] = $user->id;
            $transaksiData['user_id'] = $user->id;
            $transaksiData['waktu_transaksi'] = now();
            $transaksi = Transaksi::create($transaksiData);

            return [
                'status' => 'success',
                'message' => 'Transaksi berhasil diproses.',
                'data' => ['transaksi' => $transaksi],
            ];
        });
    }

    /**
     * Approve transaksi yang pending
     */
    public function approveTransaction(ApprovalTransaksi $approval, User $approver, string $catatan = ''): array
    {
        // Validasi role approver — peran pemutus diturunkan dari status baris
        // (PENDING_AO / PENDING_MANAJER / PENDING_PENGURUS) melalui hierarki,
        // bukan dipetakan manual, sehingga peran baru langsung berfungsi.
        $allowedRoles = HierarkiRole::peranUntukStatus($approval->status);

        if (! in_array($approver->role, $allowedRoles, true)) {
            // Percobaan otorisasi oleh peran tak berwenang adalah sinyal
            // kepatuhan (GCG) — wajib terekam, bukan ditelan diam-diam.
            ApprovalTransaksiLog::catatPeristiwa($approval, ApprovalTransaksiLog::PERISTIWA_GAGAL_DIPUTUS, $approver, [
                'status_sebelum' => $approval->status,
                'status_sesudah' => $approval->status,
                'role_berwenang' => $allowedRoles[0] ?? null,
                'catatan' => $catatan,
            ]);

            return ['status' => 'error', 'message' => 'Anda tidak memiliki hak untuk menyetujui transaksi ini.'];
        }

        // GCG — pemisahan tugas: pengaju tidak boleh menyetujui pengajuannya sendiri.
        if (PemisahanTugas::dilanggar($approval->diajukan_oleh, $approver->id)) {
            ApprovalTransaksiLog::catatPeristiwa($approval, ApprovalTransaksiLog::PERISTIWA_GAGAL_DIPUTUS, $approver, [
                'status_sebelum' => $approval->status,
                'status_sesudah' => $approval->status,
                'role_berwenang' => $approver->role,
                'catatan' => 'Percobaan self-approval ditolak (pemisahan tugas).',
            ]);

            return ['status' => 'error', 'message' => PemisahanTugas::PESAN];
        }

        $statusSebelum = $approval->status;

        $hasil = DB::transaction(function () use ($approval, $approver, $catatan) {
            $approval->update([
                'status' => 'APPROVED',
                'disetujui_oleh' => $approver->id,
                'role_approver' => $approver->role,
                'catatan_approver' => $catatan,
                'approved_at' => now(),
            ]);

            // Update status transaksi
            $transaksi = Transaksi::where('id', $approval->referensi_id)->first();
            if ($transaksi) {
                $transaksi->update([
                    'status_transaksi' => 'Sukses',
                    'status' => 'success',
                    'approved_by' => $approver->id,
                ]);

                // Mutasi saldo rekening saat disetujui Manajer.
                // TAHAP 5 — INTEGRITAS SALDO: rekening WAJIB dikunci
                // (`lockForUpdate`) sebelum baca-ubah-tulis. Tanpa kunci, dua
                // persetujuan yang berjalan bersamaan sama-sama membaca saldo
                // lama, sehingga salah satu mutasi hilang (lost update) dan
                // saldo tidak lagi dapat direkonsiliasi dengan mutasi jurnal.
                $rekening = null;
                if ($transaksi->rekening_id) {
                    $rekening = Rekening::whereKey($transaksi->rekening_id)
                        ->lockForUpdate()
                        ->first();
                    if ($rekening) {
                        // Guard saldo minimum — cegah saldo negatif / di bawah minimum
                        if ($transaksi->posisi === 'Kredit') {
                            $saldoMinimum = (float) ($rekening->saldo_minimum ?? $rekening->batas_minimum ?? 0);
                            $saldoSetelah = (float) $rekening->saldo - (float) $transaksi->jumlah;
                            if ($saldoSetelah < 0) {
                                return ['status' => 'error', 'message' => 'Persetujuan ditolak: Saldo tidak mencukupi (saldo akan menjadi negatif).'];
                            }
                            if ($saldoSetelah < $saldoMinimum) {
                                return ['status' => 'error', 'message' => 'Persetujuan ditolak: Saldo tidak mencukupi atau melewati batas saldo minimum.'];
                            }
                        }

                        if ($transaksi->posisi === 'Debet') {
                            $rekening->increment('saldo', $transaksi->jumlah);
                        } elseif ($transaksi->posisi === 'Kredit') {
                            $rekening->decrement('saldo', $transaksi->jumlah);
                        }
                        // Update saldo_setelah ke nilai aktual setelah mutasi
                        $rekening->refresh();
                        $transaksi->update(['saldo_setelah' => $rekening->saldo]);
                    }
                }

                // Posting jurnal akuntansi setelah saldo dimutasi
                try {
                    $journalService = app(AutoJournalingService::class);
                    $keterangan = ($transaksi->catatan ?? $transaksi->jenis_transaksi)
                        .' (Disetujui: '.$approver->name.')';

                    $jenis = $transaksi->jenis_transaksi;
                    $jumlah = (float) $transaksi->jumlah;
                    $akad = $transaksi->akad ?? Pembiayaan::akadDefault();
                    $jurnal = null;

                    if ($jenis === 'Penarikan Tunai') {
                        $jurnal = $journalService->jurnalTarikTunai(
                            $jumlah, $keterangan, $approver->id,
                            $transaksi->id, $transaksi->rekening_id, $transaksi->anggota_id
                        );
                    } elseif ($jenis === 'Setoran Tunai') {
                        $jurnal = $journalService->jurnalSetorTunai(
                            $jumlah, $keterangan, $approver->id,
                            $transaksi->id, $transaksi->rekening_id, $transaksi->anggota_id
                        );
                    } elseif (in_array($jenis, ['Pencairan Pembiayaan', 'Pencairan'])) {
                        // Debet: Piutang/Pembiayaan sesuai akad | Kredit: Simpanan Sukarela
                        $jurnal = $journalService->jurnalPencairan(
                            $jumlah, $akad, $keterangan, $approver->id,
                            $transaksi->id, $transaksi->pembiayaan_id ?? null,
                            $transaksi->rekening_id, $transaksi->anggota_id
                        );
                    } elseif (JenisTransaksi::adalahAngsuran((string) $jenis)) {
                        // Debet: Kas | Kredit: Piutang Pokok + Pendapatan Margin/Ujroh
                        $pokok = (float) ($transaksi->pokok ?? $jumlah);
                        $margin = (float) ($transaksi->margin ?? 0);
                        $ujroh = (float) ($transaksi->ujroh ?? 0);
                        $jurnal = $journalService->jurnalAngsuran(
                            $pokok, $margin, $ujroh, $keterangan, $approver->id,
                            $transaksi->id, $transaksi->pembiayaan_id ?? null,
                            $akad, $transaksi->rekening_id, $transaksi->anggota_id
                        );
                    } elseif (in_array($jenis, ['Biaya Admin', 'Biaya Administrasi', 'Biaya Operasional', 'Operasional'])) {
                        // Debet: Kas | Kredit: Pendapatan Admin
                        $jurnal = $journalService->jurnalBiayaAdmin(
                            $jumlah, $keterangan, $approver->id, $transaksi->id
                        );
                    } elseif (in_array($jenis, ['Setoran Simpanan Berjangka', 'Setoran Deposito'])) {
                        $jurnal = $journalService->jurnalSetorDeposito(
                            $jumlah, $keterangan, $approver->id,
                            $transaksi->id, $transaksi->rekening_id, $transaksi->anggota_id
                        );
                    } elseif (in_array($jenis, ['Penarikan Simpanan Berjangka', 'Penarikan Deposito'])) {
                        $jurnal = $journalService->jurnalTarikDeposito(
                            $jumlah, $keterangan, $approver->id,
                            $transaksi->id, $transaksi->rekening_id, $transaksi->anggota_id
                        );
                    } elseif (in_array($jenis, ['Setoran Simpanan Wajib'])) {
                        $jurnal = $journalService->jurnalSetorSimpananWajib(
                            $jumlah, $keterangan, $approver->id,
                            $transaksi->id, $transaksi->rekening_id, $transaksi->anggota_id
                        );
                    } else {
                        /* Jenis di luar daftar di atas belum punya pemetaan jurnal
                         | otomatis. DILARANG menyetujui tanpa jurnal: bila dibiarkan,
                         | saldo rekening sudah bermutasi padahal tidak ada pencatatan
                         | double-entry. Lempar agar URL/permintaan ditolak & rollback. */
                        throw new \RuntimeException(
                            'Jenis transaksi "'.$jenis.'" belum memiliki pemetaan jurnal otomatis. '.
                            'Persetujuan dibatalkan agar saldo dan jurnal tetap konsisten.'
                        );
                    }

                    /* `post()` mengembalikan null (bukan throw) bila CoA tidak
                     | ditemukan/nonaktif. Tanpa pemeriksaan ini saldo sudah
                     | bermutasi padahal jurnal kosong → neraca tidak seimbang. */
                    if ($jurnal === null) {
                        throw new \RuntimeException(
                            'Jurnal tidak dapat diposting (CoA tidak ditemukan atau nonaktif).'
                        );
                    }
                } catch (\Throwable $e) {
                    // Jurnal GAGAL tidak boleh dibiarkan lolos: approval yang
                    // sukses tanpa jurnal akan membuat saldo rekening bergerak
                    // tanpa pencatatan double-entry (neraca tidak seimbang).
                    // Lempar kembali agar DB::transaction ikut rollback.
                    report($e);

                    throw new \RuntimeException(
                        'Persetujuan dibatalkan: jurnal untuk transaksi '.$transaksi->no_transaksi.
                        ' gagal diposting. Seluruh perubahan telah dibatalkan agar saldo dan jurnal tetap konsisten.',
                        0,
                        $e
                    );
                }
            }

            return ['status' => 'success', 'message' => 'Transaksi disetujui dan jurnal telah diposting.'];
        });

        // Jejak audit ditulis SETELAH transaksi DB sukses, sehingga kegagalan
        // penulisan jejak tidak pernah membatalkan keputusan yang sah.
        if (($hasil['status'] ?? null) === 'success') {
            ApprovalTransaksiLog::catatPeristiwa($approval, ApprovalTransaksiLog::PERISTIWA_DISETUJUI, $approver, [
                'status_sebelum' => $statusSebelum,
                'status_sesudah' => 'APPROVED',
                'role_berwenang' => $approver->role,
                'catatan' => $catatan,
            ]);
        }

        return $hasil;
    }

    /**
     * Reject transaksi yang pending
     */
    public function rejectTransaction(ApprovalTransaksi $approval, User $approver, string $catatan): array
    {
        // Validasi role approver — sama seperti approveTransaction(): peran
        // pemutus diturunkan dari status baris melalui hierarki.
        $allowedRoles = HierarkiRole::peranUntukStatus($approval->status);

        if (! in_array($approver->role, $allowedRoles, true)) {
            ApprovalTransaksiLog::catatPeristiwa($approval, ApprovalTransaksiLog::PERISTIWA_GAGAL_DIPUTUS, $approver, [
                'status_sebelum' => $approval->status,
                'status_sesudah' => $approval->status,
                'role_berwenang' => $allowedRoles[0] ?? null,
                'catatan' => $catatan,
            ]);

            return ['status' => 'error', 'message' => 'Anda tidak memiliki hak untuk menolak transaksi ini.'];
        }

        // GCG — pemisahan tugas: pengaju tidak boleh menolak pengajuannya sendiri.
        if (PemisahanTugas::dilanggar($approval->diajukan_oleh, $approver->id)) {
            ApprovalTransaksiLog::catatPeristiwa($approval, ApprovalTransaksiLog::PERISTIWA_GAGAL_DIPUTUS, $approver, [
                'status_sebelum' => $approval->status,
                'status_sesudah' => $approval->status,
                'role_berwenang' => $approver->role,
                'catatan' => 'Percobaan self-reject ditolak (pemisahan tugas).',
            ]);

            return ['status' => 'error', 'message' => PemisahanTugas::PESAN];
        }

        $statusSebelum = $approval->status;

        $hasil = DB::transaction(function () use ($approval, $approver, $catatan) {
            $approval->update([
                'status' => 'REJECTED',
                'disetujui_oleh' => $approver->id,
                'role_approver' => $approver->role,
                'catatan_approver' => $catatan,
                'approved_at' => now(),
            ]);

            $transaksi = Transaksi::find($approval->referensi_id);

            // Jika yang ditolak adalah permintaan VOID: transaksi asal tetap berlaku,
            // sehingga statusnya dikembalikan ke Sukses (saldo tidak pernah dibalik).
            if ($transaksi && $transaksi->status_transaksi === 'Pending Void') {
                $transaksi->update([
                    'status_transaksi' => 'Sukses',
                    'status' => 'success',
                    'approved_by' => $approver->id,
                ]);

                return ['status' => 'success', 'message' => 'Permintaan void ditolak. Transaksi asal tetap berlaku.'];
            }

            Transaksi::where('id', $approval->referensi_id)->update([
                'status_transaksi' => 'Gagal',
                'status' => 'rejected',
                'approved_by' => $approver->id,
                'alasan_penolakan' => $catatan,
            ]);

            return ['status' => 'success', 'message' => 'Transaksi ditolak.'];
        });

        if (($hasil['status'] ?? null) === 'success') {
            ApprovalTransaksiLog::catatPeristiwa($approval, ApprovalTransaksiLog::PERISTIWA_DITOLAK, $approver, [
                'status_sebelum' => $statusSebelum,
                'status_sesudah' => 'REJECTED',
                'role_berwenang' => $approver->role,
                'catatan' => $catatan,
            ]);
        }

        return $hasil;
    }

    /**
     * Nilai satu pengajuan terhadap batas peran.
     *
     * Menegakkan tiga lapis batas secara berurutan (yang paling keras lebih
     * dulu):
     *   1. FREKUENSI — jumlah transaksi peran hari ini vs `maks_transaksi_per_hari`
     *      (tolak keras; tidak dapat dilampaui lewat approval).
     *   2. BATAS HARIAN — akumulasi nominal hari ini + nominal baru vs
     *      `limit_harian` (tolak keras).
     *   3. LIMIT PER TRANSAKSI — nominal vs `limit_per_transaksi`:
     *        - limit = 0        -> tolak keras (peran tidak berwenang sama sekali);
     *        - limit = null     -> tanpa batas;
     *        - nominal > limit  -> butuh approval; bila `butuh_approval` = false
     *          dan tidak ada peran penyetuju, pengajuan ditolak (tidak boleh
     *          lolos diam-diam).
     *
     * @return array{butuh_approval: bool, approval_role: string|null, ditolak: bool, alasan: string|null}
     */
    private function nilaiBatas(User $user, string $jenis, float $nominal, array $batas): array
    {
        $hasil = [
            'butuh_approval' => false,
            'approval_role' => null,
            'ditolak' => false,
            'alasan' => null,
        ];

        // 1. Frekuensi aksi — jumlah transaksi pengaju hari ini.
        $maksPerHari = HierarkiRole::frekuensi($user->role, 'maks_transaksi_per_hari');
        if ($maksPerHari !== null && $maksPerHari > 0) {
            $jumlahHariIni = Transaksi::where('user_id', $user->id)
                ->whereDate('waktu_transaksi', today())
                ->whereNotIn('status_transaksi', ['Gagal', 'Dibatalkan'])
                ->count();

            if ($jumlahHariIni >= $maksPerHari) {
                $hasil['ditolak'] = true;
                $hasil['alasan'] = 'Batas frekuensi aksi peran '.HierarkiRole::label($user->role)
                    ." ({$maksPerHari} transaksi/hari) telah tercapai. Silakan lanjutkan hari berikutnya.";

                return $hasil;
            }
        }

        // 2. Batas akumulasi harian (nominal) — seluruh transaksi pengaju hari
        //    ini, tanpa memandang jenis, agar tidak dapat dipecah-pecah.
        $limitHarian = $batas['limit_harian'] ?? null;
        if ($limitHarian !== null && $limitHarian > 0) {
            $akumulasiHariIni = (float) Transaksi::where('user_id', $user->id)
                ->whereDate('waktu_transaksi', today())
                ->whereNotIn('status_transaksi', ['Gagal', 'Dibatalkan'])
                ->sum('jumlah');

            if ($akumulasiHariIni + $nominal > $limitHarian) {
                $hasil['ditolak'] = true;
                $hasil['alasan'] = 'Akumulasi transaksi hari ini (Rp '.number_format($akumulasiHariIni, 0, ',', '.')
                    .') ditambah pengajuan (Rp '.number_format($nominal, 0, ',', '.')
                    .') melampaui batas harian peran '.HierarkiRole::label($user->role)
                    .' (Rp '.number_format((float) $limitHarian, 0, ',', '.').').';

                return $hasil;
            }
        }

        // 3. Limit per transaksi.
        $limit = $batas['limit_per_transaksi'] ?? null;

        if ($limit !== null && (float) $limit === 0.0) {
            $hasil['ditolak'] = true;
            $hasil['alasan'] = 'Peran '.HierarkiRole::label($user->role)
                .' tidak berwenang melakukan transaksi '.HierarkiRole::labelJenis($jenis).'.';

            return $hasil;
        }

        if ($limit !== null && $nominal > (float) $limit) {
            $butuh = (bool) ($batas['butuh_approval'] ?? false);
            $role = $batas['approval_role'] ?? null;

            if ($butuh && $role) {
                $hasil['butuh_approval'] = true;
                $hasil['approval_role'] = $role;
            } else {
                // Melampaui limit tetapi kebijakan tidak mengizinkan approval:
                // tolak agar tidak ada transaksi yang lolos tanpa dasar.
                $hasil['ditolak'] = true;
                $hasil['alasan'] = 'Transaksi bernilai Rp '.number_format($nominal, 0, ',', '.')
                    .' melampaui limit peran '.HierarkiRole::label($user->role)
                    .' (Rp '.number_format((float) $limit, 0, ',', '.')
                    .') dan tidak tersedia jalur otorisasi.';
            }
        }

        return $hasil;
    }
}
