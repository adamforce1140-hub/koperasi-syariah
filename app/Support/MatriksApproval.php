<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Matriks persetujuan pembiayaan — pintu masuk tunggal.
 *
 * Menyatukan aturan berjenjang "siapa menyetujui pada nominal berapa" yang
 * sebelumnya ditulis prosedural di controller (dengan literal angka rupiah
 * sebagai fallback). Aturan kini dibaca dari `config/alur_pembiayaan.php`,
 * sementara ambang nominal peran tetap dapat diatur Pengurus melalui kolom
 * kebijakan koperasi (dua lapis: kebijakan DB → fallback config).
 *
 * Kelas ini TIDAK menyimpan aturan sendiri — ia hanya membaca dan menerapkan.
 * Menambah/mengubah jenjang persetujuan cukup dengan menyunting konfigurasi.
 */
class MatriksApproval
{
    /**
     * Seluruh matriks persetujuan (default aman) dari config.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function konfigurasi(): array
    {
        return (array) config('alur_pembiayaan.persetujuan', []);
    }

    /**
     * Definisi satu tahap persetujuan untuk sebuah peran.
     *
     * @return array<string, mixed>
     */
    public static function tahap(string $peran): array
    {
        return (array) (static::konfigurasi()[$peran] ?? []);
    }

    /**
     * Status pembiayaan yang menjadi syarat tahap persetujuan sebuah peran.
     * Null bila peran tidak memiliki tahap persetujuan.
     */
    public static function statusMasuk(string $peran): ?string
    {
        $status = static::tahap($peran)['status_masuk'] ?? null;

        return $status !== null ? (string) $status : null;
    }

    /**
     * Ambang kewenangan penuh satu peran (Rp). Dibaca BERURUTAN dari kolom
     * kebijakan yang dideklarasikan pada konfigurasi; kolom pertama yang tidak
     * kosong dipakai. Bila seluruhnya kosong, dipakai `limit_default` config.
     *
     * Null hanya bila konfigurasi tidak mendeklarasikan ambang sama sekali.
     */
    public static function limitPeran(string $peran): ?float
    {
        $tahap = static::tahap($peran);

        foreach ((array) ($tahap['kebijakan_limit'] ?? []) as $kolom) {
            $nilai = static::nilaiKebijakan((string) $kolom);

            if ($nilai !== null) {
                return $nilai;
            }
        }

        return isset($tahap['limit_default']) ? (float) $tahap['limit_default'] : null;
    }

    /**
     * Keputusan persetujuan untuk sebuah peran pada nominal tertentu.
     *
     * @return array{status: string|null, final: bool, label: string, limit: float|null}
     */
    public static function keputusan(string $peran, float $jumlah): array
    {
        $tahap = static::tahap($peran);
        $limit = static::limitPeran($peran);

        // Tahap dengan cabang nominal: bandingkan jumlah dengan ambang peran.
        if (isset($tahap['dalam_limit'], $tahap['luar_limit'])) {
            $dalamLimit = $limit !== null && $jumlah <= $limit;
            $cabang = $dalamLimit ? $tahap['dalam_limit'] : $tahap['luar_limit'];

            return [
                'status' => isset($cabang['status']) ? (string) $cabang['status'] : null,
                'final' => (bool) ($cabang['final'] ?? false),
                'label' => (string) ($cabang['label'] ?? ''),
                'limit' => $limit,
            ];
        }

        // Tahap tanpa cabang nominal: langsung menuju status_keluar.
        return [
            'status' => isset($tahap['status_keluar']) ? (string) $tahap['status_keluar'] : null,
            'final' => false,
            'label' => (string) ($tahap['label'] ?? ''),
            'limit' => $limit,
        ];
    }

    /**
     * Baca satu parameter kebijakan koperasi sebagai nominal. Null bila nilai
     * tidak tersedia sehingga pemanggil dapat melanjutkan ke fallback
     * berikutnya tanpa error (pola yang sama dengan HierarkiRole::batas()).
     */
    private static function nilaiKebijakan(string $kolom): ?float
    {
        try {
            // Setting::all() sudah menangani kegagalan baca (tabel belum
            // termigrasi dsb.) dengan fallback default, jadi di sini cukup
            // memastikan nilainya ada dan dapat dikonversi.
            if (! array_key_exists($kolom, Setting::all())) {
                return null;
            }

            $nilai = Setting::nominal($kolom);

            return $nilai > 0.0 ? $nilai : null;
        } catch (\Throwable $e) {
            Log::warning('MatriksApproval: gagal membaca parameter kebijakan, memakai fallback config.', [
                'kolom' => $kolom,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
