<?php

namespace App\Rules;

use App\Support\PiiHasher;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * KEUNIKAN NIK BERBASIS BLIND INDEX — KEPATUHAN UU PDP No. 27/2022.
 *
 * Pengganti aturan bawaan `unique:anggota,nik` / `unique:pengajuan_rekening,nik`.
 *
 * Aturan `unique` bawaan membandingkan nilai input secara langsung dengan isi
 * kolom. Setelah kolom `nik` dienkripsi (cast `encrypted`), isi kolom adalah
 * ciphertext NON-DETERMINISTIK sehingga perbandingan itu selalu gagal —
 * artinya dua anggota bisa memiliki NIK yang sama. Aturan ini memulihkan
 * jaminan keunikan dengan membandingkan blind index `nik_hash`.
 *
 * Perilaku yang dipertahankan dari aturan bawaan:
 *   - baris yang di-soft delete TETAP dianggap terpakai (sama seperti `unique`
 *     bawaan yang membaca tabel secara langsung);
 *   - nilai kosong / bukan skalar tidak divalidasi di sini — biarkan aturan
 *     `required`/`digits:16` yang melaporkan galatnya.
 */
class NikUnik implements ValidationRule
{
    /**
     * @param  class-string<Model>  $kelasModel  Model pemilik kolom `nik_hash`.
     * @param  int|null  $kecualiId  ID baris yang dikecualikan
     *                               (dipakai pada operasi update).
     * @param  string|null  $pesan  Pesan galat kustom.
     */
    public function __construct(
        private readonly string $kelasModel,
        private readonly ?int $kecualiId = null,
        private readonly ?string $pesan = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $hash = PiiHasher::hash($this->sebagaiTeks($value));

        // Nilai kosong bukan urusan aturan ini.
        if ($hash === null) {
            return;
        }

        $model = new $this->kelasModel;
        $query = $model->newQuery();

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withTrashed();
        }

        if ($this->kecualiId !== null) {
            $query->whereKeyNot($this->kecualiId);
        }

        if ($query->where('nik_hash', $hash)->exists()) {
            $fail($this->pesan ?? 'NIK sudah terdaftar. Gunakan NIK yang berbeda.');
        }
    }

    /** Ubah nilai input menjadi string tanpa memaksa tipe pada nilai non-skalar. */
    private function sebagaiTeks(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
