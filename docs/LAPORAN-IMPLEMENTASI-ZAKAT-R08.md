# Laporan Implementasi Perbaikan Zakat — R-08

**Sistem:** Koperasi Syariah (Laravel 12 / PHP 8.3 / MySQL 8.4)
**Tanggal:** 09 Oktober 2026
**Dasar:** `docs/LAPORAN-AUDIT-ZAKAT.md` (temuan **Z-07**, prioritas Rendah)
**Ruang Lingkup:** Rekomendasi **R-08** — relasi **opsional** `pihak_id` dari buku besar zakat ke master anggota, dengan kolom `pihak` tetap dipertahankan sebagai fallback teks bebas.

**Ringkasan status:** ✅ **SELESAI 100%** — 1/1 rekomendasi diimplementasikan; **6 test baru LULUS** (24 assertions); regresi modul Zakat **69 test lulus**; regresi suite penuh **1464 test lulus** (0 regresi).

---

## 1. IDENTIFIKASI DETAIL

### R-08 — Tidak ada master muzakki/mustahik & validasi identitas

| Aspek | Uraian |
|---|---|
| **Temuan asal** | Z-07 (Rendah) — "Tidak ada master muzakki/mustahik & validasi identitas." |
| **Kondisi awal** | Kolom `pihak` pada `zakat_transaksis` hanya **string bebas** (`max:150`) dan **tidak terhubung** ke master `anggota`. Validasi `store()`/`salurkan()` hanya mensyaratkan `pihak` terisi (required). |
| **Dampak** | (1) Penerima (asnaf) tidak dapat diverifikasi secara terstruktur; (2) tidak ada rekam jejak penerima berulang sehingga duplikasi & penyalahgunaan sulit dideteksi; (3) pelaporan ke pemangku kepentingan (DPS/OJK) tidak dapat memverifikasi keabsahan penerima. |
| **Standar acuan** | **POJK No. 27/POJK.03/2022 (APU-PPT)** — verifikasi data dan pencegahan pencucian uang. **PSAK 109** — pencatatan tetap melalui jurnal; relasi pihak adalah metadata operasional, tidak memengaruhi jurnal. **Tata kelola master data** — relasi terstruktur lebih dapat diandalkan daripada teks bebas. |
| **Cakupan perubahan** | (1) **Migration baru** `2026_10_09_000900_add_pihak_id_to_zakat_transaksis.php`; (2) `app/Models/ZakatTransaksi.php` — `pihak_id` di `$fillable`, relasi `pihakRelasi()`, accessor `pihak_tampil` & `tertaut_anggota`; (3) `app/Services/ZakatService.php` — `terima()`/`salurkan()` meneruskan `pihak_id` & fill-from-master; (4) `app/Http/Controllers/ZakatController.php` — import `Anggota`; data view `index()` mengirim `anggotaList`; `rulesPihakId()`/`pesanValidasiPihak()`; `pihak` jadi opsional; (5) `resources/views/zakat/index.blade.php` — dropdown anggota pada kedua form + badge `pihak_tampil` di tabel buku besar; (6) `resources/views/zakat/laporan.blade.php` — badge `pihak_tampil`; (7) `tests/Feature/ZakatPihakRelasiTest.php` — 6 test. |
| **Spesifikasi fungsional** | • **Relasi opsional** — kolom `pihak_id` **NULLABLE** dengan FK `nullOnDelete`. P4 **Default Aman**: baris lama tidak diubah; pemanggil tanpa `pihak_id` tetap valid.<br>• **Snapshot** — nama `pihak` **selalu tersimpan** (diisi dari master bila input teks kosong), sehingga laporan historis tetap utuh walau master berubah/terhapus.<br>• **Fail-closed** — `pihak_id` yang menunjuk anggota tidak ada **ditolak** validasi (`Rule::exists`); transaksi tidak terbentuk.<br>• **Fleksibel** — `pihak` tetap dapat diisi **tanpa** `pihak_id` (pihak eksternal / non-anggota), sesuai realitas mustahik yang bukan anggota.<br>• **Aman terhadap soft-delete** — accessor `pihak_tampil` dan fill-from-master memakai `withTrashed()` agar jejak historis tidak terputus.<br>• **Dropdown** — hanya anggota berstatus **Aktif** & **Menunggu Setoran Awal** (PENDING); anggota Keluar/Tidak Aktif/Dibekukan tidak dipilih. |

---

## 2. RENCANA IMPLEMENTASI

### Dependensi antar modul

```
migrasi 2026_10_09_000900
   │  (zakat_transaksis.pihak_id → anggota.id, nullable, nullOnDelete)
   ▼
ZakatTransaksi  ── Scope model: fillable + relasi(armacology) + accessorSnapshot
   │
   ▼
ZakatService::terima() / salurkan()
   │  meneruskan $pihak_id; isi $pihak dari master bila kosong
   ▼
ZakatController::store() / salurkan()
   │  rulesPihakId(): nullable|integer|exists(anggota,id)  → fail-closed
   │ DzakatController::index() → anggotaList() (dropdown)
   ▼
zakat/index.blade.php  &  zakat/laporan.blade.php
   (dropdown anggota + badge pihak_tampil)
```

**Interaksi dengan R-07 (Z-06):** tidak ada konflik. R-07 deals dengan akun kas; R-08 dengan identitas pihak. Keduanya memakai pola validasi yang konsisten dari `PengeluaranBebanController` (dropdown + `Rule::exists`). Kolom `kode_kas` & `pihak_id` saling independen.

### Milestone

| # | Milestone | Status |
|---|---|---|
| M1 | Konteks: skema `anggota` (kolom wajib), model `Anggota` (SoftDeletes), pola validasi | ✅ |
| M2 | Migrasi `pihak_id` (nullable + FK `nullOnDelete`) | ✅ |
| M3 | Model `ZakatTransaksi`: `fillable` + relasi `pihakRelasi()` + accessor | ✅ |
| M4 | Service: `terima()`/`salurkan()` meneruskan `pihak_id` & fill-from-master | ✅ |
| M5 | Controller: `rulesPihakId()`/`pesanValidasiPihak()`/`anggotaList()`; `pihak` jadi opsional | ✅ |
| M6 | View: dropdown anggota pada kedua form; badge `pihak_tampil` di buku besar & laporan | ✅ |
| M7 | Berkas uji (6 test) → hijau | ✅ |
| M8 | Regresi modul + suite penuh; dokumentasi | ✅ |

---

## 3. PERUBAHAN BERKAS

| # | Berkas | Jenis | Pokok perubahan |
|---|---|---|---|
| 1 | `database/migrations/2026_10_09_000900_add_pihak_id_to_zakat_transaksis.php` | **Baru** | Tambah `pihak_id` unsignedBigInteger **nullable**, FK `zakat_transaksis_pihak_id_foreign` → `anggota.id` **nullOnDelete**. `up()`/`down()` guarded (`hasTable`/`hasColumn`). |
| 2 | `app/Models/ZakatTransaksi.php` | Ubah | `pihak_id` masuk `$fillable`; relasi `pihakRelasi(): BelongsTo`; accessor `getPihakTampilAttribute()` (snapshot-first, `withTrashed()` fallback) & `getTertautAnggotaAttribute()`. |
| 3 | `app/Services/ZakatService.php` | Ubah | `terima()` & `salurkan()` membaca `$data['pihak_id']`; bila `$pihak` kosong dan `pihak_id` terisi → nama diambil dari master (`withTrashed()`), lalu di-`create()` bersama `pihak_id`. |
| 4 | `app/Http/Controllers/ZakatController.php` | Ubah | Import `App\Models\Anggota`; `index()` mengirim `anggotaList` & eager-load `with('pihakRelasi')`; `laporan()` eager-load `pihakRelasi`; `store()`/`salurkan()` gained `pihak_id` rule & pesan; `pihak` → `nullable`; 3 helper baru (`anggotaList`, `rulesPihakId`, `pesanValidasiPihak`). |
| 5 | `resources/views/zakat/index.blade.php` | Ubah | Dropdown anggota (`pihak_id`) pada **kedua** form; input `pihak` jadi non-required (`maxlength` + placeholder); tabel buku besar menampilkan `pihak_tampil` + badge `anggota`. |
| 6 | `resources/views/zakat/laporan.blade.php` | Ubah | Tabel mutasi menampilkan `pihak_tampil` + badge `anggota`. |
| 7 | `tests/Feature/ZakatPihakRelasiTest.php` | **Baru** | 6 test / 24 assertions (lihat §5). |

> **Catatan:** seluruh nama berkas di atas merujuk pada berkas aktual di repositori.

---

## 4. IMPLEMENTASI KUNCI

### 4.1 Migrasi (`2026_10_09_000900`)

```php
Schema::table('zakat_transaksis', function (Blueprint $table) {
    if (! Schema::hasColumn('zakat_transaksis', 'pihak_id')) {
        $table->unsignedBigInteger('pihak_id')
            ->nullable()
            ->after('pihak')
            ->comment('Relasi opsional ke master `anggota`. NULL = pihak eksternal (teks bebas pada kolom `pihak`).');

        $table->foreign('pihak_id', 'zakat_transaksis_pihak_id_foreign')
            ->references('id')->on('anggota')
            ->nullOnDelete();
    }
});
```

`nullOnDelete` memastikan rhetak historis(bookkeeping) tetap utuh bila anggota dihapus.

### 4.2 Relasi & snapshot (`app/Models/ZakatTransaksi.php`)

```php
public function pihakRelasi(): BelongsTo
{
    return $this->belongsTo(Anggota::class, 'pihak_id');
}

public function getPihakTampilAttribute(): string
{
    $teks = trim((string) $this->pihak);
    if ($this->pihak_id) {
        $anggota = $this->relationLoaded('pihakRelasi')
            ? $this->pihakRelasi
            : Anggota::withTrashed()->find($this->pihak_id);
        if ($anggota) {
            return $teks !== '' ? $teks : (string) $anggota->nama_lengkap;
        }
    }
    return $teks;
}

public function getTertautAnggotaAttribute(): bool
{
    return ! empty($this->pihak_id);
}
```

**Strategi tampilan:** snapshot teks (`pihak`) selalu diprioritaskan; master hanya dipakai sebagai fallback/indicator (badge). Ini menjamin laporan historis **tidak berubah** meski master anggota diperbarui — prinsip *snapshot for auditability*.

### 4.3 Service — fill-from-master (`app/Services/ZakatService.php`)

```php
$pihakId = $data['pihak_id'] ?? null;
if ($pihak === '' && $pihakId) {
    $anggota = \App\Models\Anggota::withTrashed()->find($pihakId);
    $pihak   = trim((string) ($anggota?->nama_lengkap ?? ''));
}
// ...
'pihak'    => $pihak,
'pihak_id' => $pihakId ?: null,
```

Berkas `terima()` & `salurkan()` identik. Bila `pihak_id` **tidak** ada (atau tidak valid), `pihak` **wajib** teks (fail-closed "Nama pihak wajib diisi").

### 4.4 Validasi & dropdown (`app/Http/Controllers/ZakatController.php`)

```php
private function rulesPihakId(): array
{
    return ['nullable', 'integer', Rule::exists('anggota', 'id')];
}

private function anggotaList()
{
    return Anggota::query()
        ->whereIn('status_anggota', [
            Anggota::STATUS_ACTIVE, 'Aktif',
            Anggota::STATUS_PENDING,
        ])
        ->orderBy('nama_lengkap')
        ->get(['id', 'no_anggota', 'nama_lengkap']);
}
```

Nilai `'Aktif'` disertakan sebagai **alias legacy** pada `status_anggota` (kompatibilitas data lama — lihat `Anggota::STATUS_ALIASES`).

### 4.5 View — dropdown (`zakat/index.blade.php`, form Penerimaan; identik untuk Penyaluran)

```blade
{{-- R-08 (Z-07) — relasi OPSIONAL ke master anggota; `pihak` tetap fallback teks --}}
<div>
    <label>Atau Pilih Anggota (opsional)</label>
    <select name="pihak_id" id="terima-pihak-id">
        <option value="">— Pihak eksternal (isi nama di atas) —</option>
        @foreach($anggotaList as $a)
            <option value="{{ $a->id }}">{{ $a->nama_lengkap }} ({{ $a->no_anggota }})</option>
        @endforeach
    </select>
</div>
```

---

## 5. PENGUJIAN

**Berkas:** `tests/Feature/ZakatPihakRelasiTest.php` (`DatabaseTransactions`; `Setting::flush()` pada `tearDown()`; helper `pengelola()`, `anggota()` — membuat anggota uji dengan seluruh kolom wajib skema: `no_anggota`, `nik`, `nama_lengkap`, `jenis_kelamin`, `alamat`, `kota`, `provinsi`, `status_anggota`, `tanggal_gabung`).

| # | Test | Skenario | Hasil |
|---|---|---|---|
| 1 | `test_form_menyediakan_dropdown_pihak_id_dan_input_teks_pihak` | HTML memuat `name="pihak_id"` **2×** (kedua form) & `name="pihak"` **2×** (fallback tetap ada). | ✅ |
| 2 | `test_penerimaan_dengan_pihak_id_menyimpan_relasi_anggota` | `POST zakat.store` dengan `pihak_id` → transaksi menyimpan `pihak_id` yang benar; `tertaut_anggota = true`; relasi ter- eager-load. | ✅ |
| 3 | `test_pihak_teks_kosong_diambil_dari_master_anggota` | `salurkan()` dengan `pihak = ''` + `pihak_id` → nama `pihak` terisi dari master. | ✅ |
| 4 | `test_validasi_menolak_pihak_id_tidak_dikenal` | `pihak_id = 99999999` → `assertSessionHasErrors('pihak_id')`; transaksi **tidak** terbentuk (fail-closed). | ✅ |
| 5 | `test_tanpa_pihak_id_teks_pihak_tetap_tersimpan` | Post **tanpa** `pihak_id` → `pihak_id` NULL, `tertaut_anggota = false`, `pihak_tampil` = teks (**kompatibilitas mundur**). | ✅ |
| 6 | `test_pihak_tampil_aman_terhadap_anggota_terhapus` | Soft-delete anggota setelah transaksi → `pihak_tampil` tetap mengembalikan snapshot teks; `tertaut_anggota` = true. | ✅ |

**Hasil:** `Tests: 6 passed (24 assertions)`.

### Regresi

| Suite | Hasil |
|---|---|
| `php artisan test tests/Feature/ZakatPihakRelasiTest.php` | **6 passed** (24 assertions) |
| `php artisan test --filter=Zakat` | **69 passed** (249 assertions) |
| `php artisan test` (suite penuh) | **1464 passed** (6549 assertions), durasi 177,73 s |

**0 regresi.** Suite penuh naik dari 1458 (setelah R-07) → 1464 (setelah R-08) = +6 test baru, tanpa test yang hilang.

---

## 6. VERIFIKASI KRITERIA KEBERHASILAN

Kriteria audit R-08: **"Tambah relasi opsional `pihak_id` → master anggota/pihak; field `pihak` tetap sebagai fallback teks bebas."**

| Kriteria | Bukti | Status |
|---|---|---|
| **Relasi `pihak_id` → master anggota tersedia (opsional)** | Migrasi `2026_10_09_000900` (nullable + FK) + relasi `pihakRelasi()` | ✅ |
| **`pihak_id` benar-benar terhubung & konsisten** | Test #2 — `pihak_id` = anggota terpilih; `tertaut_anggota` = true | ✅ |
| **`pihak` tetap sebagai **fallback teks bebas** | Test #5 — tanpa `pihak_id`, teks `pihak` tetap sah; test #4 — pihak eksternal dapat dicatat | ✅ |
| **Dropdown anggota tersedia pada kedua form** | Test #1 — `name="pihak_id"` **2×** | ✅ |
| **Fail-closed: `pihak_id` tak dikenal ditolak** | Test #4 — error `pihak_id`; transaksi tidak terbentuk | ✅ |
| **Nama dapat diambil dari master (snapshot)** | Test #3 — `pihak` kosong + `pihak_id` → nama terisi | ✅ |
| **Aman terhadap anggota terhapus** | Test #6 — soft-delete → snapshot utuh | ✅ |
| **P4 Default Aman / backward compatible** | Kolom nullable; baris lama NULL; pemanggil lama tanpa `pihak_id` valid (test #5) | ✅ |
| **Nol hardcoding & dependensi terpenuhi** | Status anggota & relasi dibaca dari model `Anggota`; FS CoA/Jurnal tidak berubah | ✅ |

**Kesimpulan:** seluruh kriteria **TERPENUHI**. Temuan **Z-07 tertutup**; rekomendasi **R-08 SELESAI**.

---

## 7. CATATAN & RISIKO SISA

- ✅ **Z-07 tertutup** — buku besar zakat kini punya relasi terstruktur ke master anggota tanpa kehilangan fallback teks.
- ✅ **0 regresi** — suite penuh 1464 passed.
- ✅ Dokumentasi diperbarui: `docs/LAPORAN-AUDIT-ZAKAT.md` (status Z-07) + laporan ini.

**Temuan yang MASIH TERBUKA:** **Z-09** (rekomendasi R-09 — penyajian laporan sumber & penggunaan dana zakat dalam bundel laporan keuangan syariah / CaLK).

> **Catatan desain (risiko tersisa):**
> - Relasi bersifat **opsional** dan sengaja **tidak mewajibkan** pihak beranggota — realitas mustahik zakat dapat merupakan pihak luar yang sah. Verifikasi kelayakan asnaf yang lebih mendalam (verifikasi kondisi ekonomi dan domisili) berada di luar lingkup Z-07 dan dapat menjadi rekomendasi audit lanjutan.
> - Snapshot `pihak` **tidak** diperbarui otomatis bila master anggota mengubah nama — memang disengaja demi **keutuhan jejak audit historis** (prinsip PSAK 109). Buku besar menampilkan `pihak_tampil` (snapshot) dengan badge `anggota` sebagai penanda keterkaitan terstruktur.
> - Foreign key memakai `nullOnDelete`: bila anggota dihapus permanen, `pihak_id` menjadi NULL namun `pihak` snapshot tetap utuh — konsistensi data tidak rusak.

---

*Disusun sebagai bagian dari implementasi rekomendasi audit zakat. Seluruh perubahan diverifikasi melalui `php artisan test` pada 09 Oktober 2026.*