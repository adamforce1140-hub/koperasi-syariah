<?php

namespace App\Policies;

use App\Models\KasHarian;
use App\Models\User;
use App\Services\AturanLintasHariService;
use App\Support\PemisahanTugas;

/**
 * BUG-08 — Kebijakan otorisasi operasional loket teller (defense-in-depth).
 *
 * Sebelumnya keputusan "boleh/tidak" hanya hidup di middleware rute
 * (`role:teller`) dan di dalam controller. Bila suatu saat rute salah
 * dikonfigurasi, atau ada endpoint baru yang lupa dipasangi middleware, view
 * tetap MENAMPILKAN tombol aksi berbahaya kepada peran yang tidak berwenang —
 * dan tidak ada lapisan kedua yang menahannya.
 *
 * Kebijakan ini memberi lapisan kedua: view memakai `@can(...)` sehingga
 * tombol aksi hanya dirender untuk peran yang benar-benar berwenang. Ini
 * MELENGKAPI (bukan menggantikan) middleware rute & pengecekan controller.
 * Bila gate dan middleware berbeda pendapat, middleware/controller tetap
 * menjadi penentu akhir — gate hanya mencegah UI "menjanjikan" aksi yang
 * akan ditolak server.
 */
class TellerPolicy
{
    public function __construct(private AturanLintasHariService $lintasHari) {}

    /**
     * Boleh melakukan transaksi tulis loket (setor/tarik/angsuran/deposito/
     * pencairan). Diselaraskan dengan grup rute `role:teller`.
     */
    public function transaksi(User $user): bool
    {
        return $user->isTeller();
    }

    /**
     * Boleh membuka kas hari ini.
     *
     * Teller yang sudah membuka kas hari ini tidak boleh membuka lagi (controller
     * `openingStore()` menolaknya). Gate ini mencegah tombol "Buka Kas"
     * ditampilkan ketika kas hari ini sudah ada.
     */
    public function bukaKas(User $user): bool
    {
        if (! $user->isTeller()) {
            return false;
        }

        return ! $this->adaKasHariIni($user->id);
    }

    /**
     * Boleh menutup / menyetor kas. Mensyaratkan sesi kas pada tanggal yang
     * sedang ditutup sudah dibuka.
     *
     * Parameter `$tanggal` (opsional, format Y-m-d) memungkinkan Closing
     * LINTAS HARI: sesi lampau yang belum ditutup tetap dapat diselesaikan
     * meskipun teller belum membuka kas pada tanggal bisnis berjalan.
     * Bila `$tanggal` null, dipakai tanggal bisnis berjalan (perilaku lama).
     *
     * Parameter `$idPengajuOpname` (opsional) memungkinkan view memastikan
     * pengaju opname bukan pemutusnya sendiri (maker ≠ checker).
     */
    public function tutupKas(User $user, ?int $idPengajuOpname = null, ?string $tanggal = null): bool
    {
        if (! $user->isTeller()) {
            return false;
        }

        if (! $this->adaKasPadaTanggal($user->id, $tanggal)) {
            return false;
        }

        return ! PemisahanTugas::dilanggar($idPengajuOpname, $user->id);
    }

    /**
     * Boleh mem-void transaksi. Membutuhkan peran operasional loket sekaligus
     * pemisahan tugas: pembuat transaksi tidak boleh mem-void transaksinya
     * sendiri. Controller tetap memvalidasi ulang aturan yang sama.
     */
    public function void(User $user, ?int $idPembuatTransaksi = null): bool
    {
        if (! $user->isTeller()) {
            return false;
        }

        return ! PemisahanTugas::dilanggar($idPembuatTransaksi, $user->id);
    }

    /** Apakah teller ini sudah punya sesi kas pada tanggal hari ini. */
    private function adaKasHariIni(int $userId): bool
    {
        return $this->adaKasPadaTanggal($userId, $this->lintasHari->tanggalBisnis());
    }

    /**
     * Apakah teller punya sesi kas pada tanggal tertentu.
     *
     * Memakai `whereDate` (bukan `where`) agar aman terhadap kolom bertipe
     * datetime, dan memakai tanggal bisnis dari service (bukan `now()` langsung)
     * sehingga konsisten dengan controller serta sadar zona waktu.
     */
    private function adaKasPadaTanggal(int $userId, ?string $tanggal = null): bool
    {
        $tanggal ??= $this->lintasHari->tanggalBisnis();

        return KasHarian::query()
            ->where('user_id', $userId)
            ->whereDate('tanggal', $tanggal)
            ->exists();
    }
}
