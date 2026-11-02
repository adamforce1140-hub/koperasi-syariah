<?php

namespace App\Observers;

use App\Models\AuditPerubahanPii;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * ObserverAuditPii — jejak audit perubahan DATA PRIBADI.
 *
 * Dasar hukum: UU No. 27/2022 tentang Pelindungan Data Pribadi (Pasal 35, 39,
 * 40) mewajibkan pengendali data mampu menunjukkan siapa mengubah data pribadi
 * dan kapan. Karena itu setiap perubahan kolom PII pada `anggota` dan
 * `pengajuan_rekening` dicatat ke tabel `audit_perubahan_pii` yang bersifat
 * APPEND-ONLY dan dilindungi rantai hash.
 *
 * MINIMALISASI DATA (Pasal 16): yang dicatat hanya NAMA kolom yang berubah,
 * BUKAN nilainya. Menyalin NIK/alamat ke tabel log akan memperbanyak permukaan
 * kebocoran tanpa menambah nilai audit.
 *
 * Dipasang di level model (bukan controller) supaya seluruh jalur tulis —
 * controller, command Artisan, seeder, job, tinker — ikut terekam.
 *
 * Lihat: docs/TAHAP10-KEAMANAN-DATA-UU-PDP.md
 */
class ObserverAuditPii
{
    /**
     * Kolom yang tergolong data pribadi. Perubahan di luar daftar ini tidak
     * dicatat agar log tetap ringkas dan mudah diaudit.
     *
     * @var list<string>
     */
    public const KOLOM_PII = [
        'nik',
        'nama_lengkap',
        'tempat_lahir',
        'tanggal_lahir',
        'jenis_kelamin',
        'status_perkawinan',
        'alamat',
        'rt_rw',
        'kelurahan',
        'kecamatan',
        'kota',
        'provinsi',
        'kode_pos',
        'no_telepon',
        'no_hp',
        'email',
        'pekerjaan',
        'nama_ibu_kandung',
        'foto_ktp',
        'foto_selfie_ktp',
        'no_cif',
    ];

    public function created(Model $model): void
    {
        $terisi = $this->kolomTerisi($model);

        if ($terisi !== []) {
            $this->catat($model, AuditPerubahanPii::AKSI_TAMBAH, $terisi);
        }
    }

    public function updated(Model $model): void
    {
        $berubah = $this->kolomBerubah($model);

        if ($berubah !== []) {
            $this->catat($model, AuditPerubahanPii::AKSI_EDIT, $berubah);
        }
    }

    public function deleted(Model $model): void
    {
        $this->catat($model, AuditPerubahanPii::AKSI_HAPUS, []);
    }

    public function restored(Model $model): void
    {
        $this->catat($model, AuditPerubahanPii::AKSI_PULIHKAN, []);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Penentuan kolom
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Kolom PII yang nilainya berubah.
     *
     * `nik_hash` (blind index) selalu ikut berubah saat `nik` berubah, sehingga
     * keduanya dilaporkan sebagai satu kolom logis `nik` agar tidak ganda.
     *
     * @return list<string>
     */
    private function kolomBerubah(Model $model): array
    {
        $berubah = array_values(array_intersect(
            array_keys($model->getChanges()),
            self::KOLOM_PII
        ));

        if (in_array('nik_hash', array_keys($model->getChanges()), true)
            && ! in_array('nik', $berubah, true)) {
            $berubah[] = 'nik';
        }

        return $berubah;
    }

    /**
     * Kolom PII yang terisi saat baris dibuat.
     *
     * @return list<string>
     */
    private function kolomTerisi(Model $model): array
    {
        $atribut = $model->getAttributes();

        $terisi = array_filter(
            $atribut,
            static fn ($nilai) => $nilai !== null && $nilai !== ''
        );

        return array_values(array_intersect(array_keys($terisi), self::KOLOM_PII));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Penulisan
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Tulis satu baris audit.
     *
     * Kegagalan penulisan audit tidak boleh menggagalkan transaksi bisnis
     * (mis. anggota sudah sah disimpan namun tabel log bermasalah). Karena itu
     * galat ditangkap dan dilaporkan tanpa memuat nilai PII apa pun.
     *
     * @param  list<string>  $kolom
     */
    private function catat(Model $model, string $aksi, array $kolom): void
    {
        try {
            $pengguna = Auth::user();

            AuditPerubahanPii::catat([
                'user_id' => $pengguna?->id,
                'user_nama' => $pengguna?->name ?? 'sistem',
                'role' => $pengguna?->role ?? 'sistem',
                'model' => class_basename($model),
                'model_id' => (int) $model->getKey(),
                'aksi' => $aksi,
                'kolom' => $kolom,
                'jumlah_kolom' => count($kolom),
                'deskripsi' => $this->deskripsi($model, $aksi, $kolom),
                'ip_address' => request()->ip(),
                'user_agent' => mb_substr((string) request()->userAgent(), 0, 500),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Gagal menulis jejak audit perubahan data pribadi.', [
                'model' => get_class($model),
                'id' => $model->getKey(),
                'pesan' => $e->getMessage(),
            ]);
        }
    }

    /** @param list<string> $kolom */
    private function deskripsi(Model $model, string $aksi, array $kolom): string
    {
        $nama = class_basename($model);
        $id = $model->getKey();

        return match ($aksi) {
            AuditPerubahanPii::AKSI_TAMBAH => "Menambah data pribadi {$nama} #{$id}"
                .($kolom !== [] ? ' (kolom: '.implode(', ', $kolom).')' : ''),
            AuditPerubahanPii::AKSI_HAPUS => "Menghapus data pribadi {$nama} #{$id}",
            AuditPerubahanPii::AKSI_PULIHKAN => "Memulihkan data pribadi {$nama} #{$id}",
            default => "Mengubah data pribadi {$nama} #{$id}"
                .($kolom !== [] ? ' (kolom: '.implode(', ', $kolom).')' : ''),
        };
    }
}
