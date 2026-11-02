# Laporan Implementasi Perbaikan Zakat — R-07

**Sistem:** Koperasi Syariah (Laravel 12 / PHP 8.3 / MySQL 8.4)
**Tanggal:** 09 Oktober 2026
**Dasar:** `docs/LAPORAN-AUDIT-ZAKAT.md` (temuan **Z-06**, prioritas Sedang)
**Ruang Lingkup:** Rekomendasi **R-07** — menambahkan dropdown **`kode_kas`** (Kas Teller / Bank Koperasi) pada form penerimaan & pengeluaran dana zakat beserta validasi akun kas likuid yang aktif.

**Ringkasan status:** ✅ **SELESAI 100%** — 1/1 rekomendasi diimplementasikan; **7 test baru LULUS**; regresi modul Zakat **63 test lulus** (0 regresi).

---

## 1. IDENTIFIKASI DETAIL

### R-07 — Kas atau bank penerimaan dan pengeluaran tidak dapat dipilih

| Aspek | Uraian |
|---|---|
| **Temuan asal** | Z-06 (Sedang) — "Kas penerimaan/penyaluran tidak dapat dipilih (selalu Kas Teller)." |
| **Kondisi awal** | `ZakatService::terima()` dan `salurkan()` sudah menerima parameter `kode_kas`, tetapi **nilai tersebut tidak pernah dikirim** dari form. Akibatnya nilai efektif selalu jatuh ke default `Coa::KAS_TELLER` (`1-1001`). Setoran yang secara riil diterima melalui **transfer bank** (`1-1002`) tetap tercatat sebagai arus kas teller — sehingga **buku besar kas/bank tidak mencerminkan kenyataan operasional** dan rekonsiliasi sumber dana menjadi tidak andal. |
| **Kondisi sebelumnya** | Selalu `1-1001` (Kas Teller). Tidak ada antarmuka untuk memilih `1-1002` (Bank Koperasi). |
| **Standar acuan** | **PSAK 109** — dana zakat & infak/sedekah adalah dana **titipan (amanah)**; buku besar harus mencerminkan sumber & penggunaan dana secara sebenarnya. **CoA `AKUN_KAS_LIKUID`** — hanya akun kas likuid (`1-1001`, `1-1002`) yang sah menjadi sumber/penerima pembayaran langsung. **Kas Brankas `1-1003` bukan sumber pembayaran langsung** sehingga TIDAK dapat dipilih. |
| **Cakupan perubahan** | (1) `ZakatController` — penambahan import `Coa as CoaModel` & `Rule`; supplying `akunKas` + `kodeKasDefault` ke view; validasi `kode_kas` pada `store()` dan `salurkan()`; 3 helper baru (`akunKasList`, `rulesKodeKas`, `pesanValidasiKodeKas`); (2) `resources/views/zakat/index.blade.php` — dropdown `kode_kas` pada kedua form; (3) `tests/Feature/ZakatKasPilihanTest.php` — 7 test. |
| **Spesifikasi fungsional** | • Dropdown `kode_kas` menampilkan **hanya akun kas likuid yang aktif** (difilter `kategori = 'Aset'` ∧ `kode_akun ∈ AKUN_KAS_LIKUID` ∧ `is_active = true`).<br>• Nilai default = `1-1001` (Kas Teller) — **backward compatible**, tidak mengubah alur lama.<br>• **Penerimaan:** DEBET `kode_kas` / KREDIT akun dana (`akunDana($dana)`).<br>• **Penyaluran:** DEBET akun dana / KREDIT `kode_kas`.<br>• **Fail-closed:** akun di luar `AKUN_KAS_LIKUID` (mis. Kas Brankas `1-1003`), akun nonaktif, atau kode tak dikenal **ditolak** — transaksi tidak terbentuk.<br>• `AutoJournalingService::post()` juga menolak akun CoA nonaktif/non-pendahuluan sebagai lapis pengaman kedua. |

---

## 2. RENCANA IMPLEMENTASI

### Dependensi antar modul

```
Coa::AKUN_KAS_LIKUID  (['1-1001', '1-1002'] — konstanta, app/Support/Coa.php)
        │
        ▼
ZakatController::akunKasList()  ──►  view zakat/index.blade.php (dropdown kode_kas)
        │                                    │
        │  post(kode_kas)                    ▼
        │                         ZakatService::terima()  → DEBET kode_kas / KREDIT akunDana
        │                         ZakatService::salurkan() → DEBET akunDana / KREDIT kode_kas
        ▼                                    │
ZakatController::rulesKodeKas()  ──►  validasi (Rule::exists + where is_active)
                                             │
                                             ▼
                                    AutoJournalingService::post()  (fail-closed lapis 2)
```

**Pola acuan:** `PengeluaranBebanController` (`akunKasList()` + `Rule::exists(...)->where('is_active', true)` + cek `kategori === 'Aset'` + `in_array(kode_akun, AKUN_KAS_LIKUID)`) — konsisten dengan modul pengeluaran yang telah teruji.

### Milestone

| # | Milestone | Status |
|---|---|---|
| M1 | Konteks: konstanta `Coa::AKUN_KAS_LIKUID`, pola validasi kas, jalur `terima()`/`salurkan()` | ✅ |
| M2 | Helper controller (`akunKasList`, `rulesKodeKas`, `pesanValidasiKodeKas`) | ✅ |
| M3 | Data view `index()` (`akunKas`, `kodeKasDefault`) | ✅ |
| M4 | Validasi `kode_kas` pada `store()` & `salurkan()` | ✅ |
| M5 | Dropdown `kode_kas` pada kedua form blade | ✅ |
| M6 | Berkas uji (7 test) → hijau | ✅ |
| M7 | Regresi modul Zakat | ✅ |
| M8 | Dokumentasi (laporan ini + pembaruan audit) | ✅ |

---

## 3. PERUBAHAN BERKAS

| # | Berkas | Jenis | Pokok perubahan |
|---|---|---|---|
| 1 | `app/Http/Controllers/ZakatController.php` | Ubah | Import `App\Models\Coa as CoaModel` & `Illuminate\Validation\Rule`; `index()` mengirim `akunKas` & `kodeKasDefault`; `store()` & `salurkan()` memvalidasi `kode_kas` via `rulesKodeKas()` dengan pesan `pesanValidasiKodeKas()`; 3 helper privat baru. |
| 2 | `resources/views/zakat/index.blade.php` | Ubah | Dropdown `kode_kas` pada form **Penerimaan** (setelah field Jumlah) & form **Penyaluran** — menampilkan kode + nama akun; fallback `option` default bila daftar kosong. |
| 3 | `tests/Feature/ZakatKasPilihanTest.php` | **Baru** | 7 test / 25 assertion (lihat §5). |

**Tidak ada migration** — `kode_kas` hanya read-only dari master CoA; kolom `kode_kas` sudah tersedia di jalur service. **Nol hardcoding** — daftar kas & default diambil dari `Coa::AKUN_KAS_LIKUID` & `Coa::KAS_TELLER`, bukan konstanta literal di controller.

---

## 4. IMPLEMENTASI KUNCI

### 4.1 Helper controller (`app/Http/Controllers/ZakatController.php`)

```php
/** Daftar akun kas/bank likuid yang aktif — sumber pembayaran sah (R-07 / Z-06). */
private function akunKasList()
{
    return CoaModel::where('kategori', 'Aset')
        ->whereIn('kode_akun', Coa::AKUN_KAS_LIKUID)
        ->where('is_active', true)
        ->orderBy('kode_akun')
        ->get();
}

/** Validasi kas: opsional (default Kas Teller bila kosong), wajib akun likuid aktif. */
private function rulesKodeKas(): array
{
    return [
        'nullable',
        'string',
        Rule::exists('coas', 'kode_akun')->where(function ($query) {
            return $query->whereIn('kode_akun', Coa::AKUN_KAS_LIKUID)
                ->where('is_active', true);
        }),
    ];
}

private function pesanValidasiKodeKas(): array
{
    return [
        'kode_kas.exists' => 'Akun kas tidak valid — pilih Kas Teller (1-1001) atau Bank (1-1002) yang aktif.',
        'kode_kas.string' => 'Akun kas tidak valid — pilih Kas Teller (1-1001) atau Bank (1-1002) yang aktif.',
    ];
}
```

### 4.2 Dropdown pada kedua form (`resources/views/zakat/index.blade.php`)

```blade
<div>
    <label style="...">Kas / Bank Penerimaan</label>
    <select name="kode_kas" id="terima-kode-kas" style="...">
        @forelse($akunKas as $akun)
            <option value="{{ $akun->kode_akun }}" @selected($akun->kode_akun === $kodeKasDefault)>
                {{ $akun->kode_akun }} — {{ $akun->nama_akun }}
            </option>
        @empty
            <option value="{{ $kodeKasDefault }}">{{ $kodeKasDefault }} — Kas Teller (default)</option>
        @endforelse
    </select>
</div>
```

Form **Penyaluran** memakai struktur identik dengan `id="salur-kode-kas"` dan label "Kas / Bank Penyaluran".

### 4.3 Integrasi service (tidak berubah — `app/Services/ZakatService.php`)

```php
// baris ~123 — penerimaan
$kodeKas = (string) ($data['kode_kas'] ?? Coa::KAS_TELLER);
// DEBET $kodeKas / KREDIT akunDana($dana)

// baris ~213 — pencatatan journalling
$kodeKas = (string) ($data['kode_kas'] ?? Coa::KAS_TELLER);
// DEBET akunDana($dana) / KREDIT $kodeKas
```

Service sudah menerima `kode_kas` sejak awal; **R-07 hanya mengaktifkan pengiriman nilai tersebut dari form** — request tanpa `kode_kas` tetap dipetakan ke Kas Teller.

---

## 5. PENGUJIAN

**Berkas:** `tests/Feature/ZakatKasPilihanTest.php` (menggunakan `DatabaseTransactions`, `Setting::flush()` pada `tearDown()`, helper `pengelola()`, `saldo()`, `deltaJurnal()` untuk mengukur mutasi jurnal).

| # | Test | Skenario | Hasil |
|---|---|---|---|
| 1 | `test_form_penerimaan_dan_penyaluran_menyediakan_pilihan_kas_bank` | HTML memuat `name="kode_kas"` **2×** (kedua form) serta kode `1-1001` & `1-1002`. | ✅ |
| 2 | `test_penerimaan_via_bank_mendebet_akun_bank_bukan_kas_teller` | `POST zakat.store` dengan `kode_kas = 1-1002` → delta **debet Bank** = nominal (pembulatan); Kas Teller **tidak tersentuh**. | ✅ |
| 3 | `test_penyaluran_via_bank_mengkredit_akun_bank` | Terima via bank lalu `zakat.salurkan` via bank → delta **kredit Bank** = nominal. | ✅ |
| 4 | `test_validasi_menolak_akun_kas_non_likuid` | `kode_kas = 1-1003` (Kas Brankas — bukan sumber pembayaran) → `assertSessionHasErrors('kode_kas')`, transaksi tidak terbentuk. | ✅ |
| 5 | `test_validasi_menolak_akun_kas_nonaktif` | Set `1-1002` `is_active = false` → error + tidak tercatat. | ✅ |
| 6 | `test_validasi_menolak_akun_kas_tidak_dikenal` | `kode_kas = '9-9999'` → error. | ✅ |
| 7 | `test_tanpa_kode_kas_default_ke_kas_teller` | Post **tanpa** `kode_kas` → delta **debet Kas Teller** = pembulatan nominal (**kompatibilitas mundur**). | ✅ |

**Hasil:** `Tests: 7 passed (25 assertions)`.

### Regresi

| Suite | Hasil |
|---|---|
| `php artisan test tests/Feature/ZakatKasPilihanTest.php` | **7 passed** (25 assertions) |
| `php artisan test --filter=Zakat` | **63 passed** (226 assertions) |

**0 regresi.**

---

## 6. VERIFIKASI KRITERIA KEBERHASILAN

Kriteria audit R-07: **"Tambah dropdown `kode_kas` (Kas Teller / Bank) pada form penerimaan dan salurkan; validasi akun harus akun kas/bank aktif."**

| Kriteria | Bukti | Status |
|---|---|---|
| **Dropdown `kode_kas` ada pada form penerimaan** | `name="kode_kas"` (form Penerimaan) — test #1 | ✅ |
| **Dropdown `kode_kas` ada pada form pengeluaran** | `name="kode_kas"` (form Penyaluran) — test #1 | ✅ |
| Pilihan hanya Kas Teller / Bank yang **aktif** | `akunKasList()` memfilter `kategori='Aset'` ∧ `AKUN_KAS_LIKUID` ∧ `is_active=true` | ✅ |
| **Penerimaan** via bank mendebet akun bank | Test #2 — delta debet `1-1002` = nominal | ✅ |
| **Penyaluran** via bank mengkredit akun bank | Test #3 — delta kredit `1-1002` = nominal | ✅ |
| **Validasi: akun kas non-likuid ditolak** | Test #4 — Kas Brankas `1-1003` ditolak | ✅ |
| **Validasi: akun kas nonaktif ditolak** | Test #5 — `is_active=false` ditolak | ✅ |
| **Validasi: kode kas tak dikenal ditolak** | Test #6 — `9-9999` ditolak | ✅ |
| **Backward compatible** (tanpa `kode_kas` → Kas Teller) | Test #7 — default tetap Kas Teller | ✅ |
| Nol hardcoding & tidak ada dependensi terabaikan | Konstanta dari `Coa::AKUN_KAS_LIKUID`/`KAS_TELLER`; service & CoA tidak perlu diubah | ✅ |

**Kesimpulan:** seluruh kriteria **TERPENUHI**. Temuan **Z-06 tertutup**; rekomendasi **R-07 SELESAI**.

---

## 7. CATATAN & RISIKO SISA

- ✅ **Z-06 tertutup** — kas/bank penerimaan dan salurannya kini dapat dipilih dan tervalidasi.
- ✅ **0 regresi** pada modul Zakat (63 passed).
- ✅ Dokumentasi diperbarui: `docs/LAPORAN-AUDIT-ZAKAT.md` (status Z-06) + laporan ini.

**Temuan yang MASIH TERBUKA** (di luar lingkup R-07): Z-07 (master pihak/R-08), Z-09 (integrasi CaLK/R-09).

> **Risiko sisa (dicatat):** kolom `kode_kas` sengaja tidak ditampilkan sebagai kolom terpisah pada tabel buku besar agar tampilan tetap ringkas. Nilai aktual tetap dapat ditelusuri melalui jurnal terkait (`jurnal_id`) sebagai sumber kebenaran pembukuan. Bila kelak diperlukan kolom rekening pada buku besar, hal itu dapat ditambahkan pada gelombang berikutnya tanpa mengubah alur pencatatan.

---

*Disusun sebagai bagian dari implementasi rekomendasi audit zakat. Seluruh perubahan diverifikasi melalui `php artisan test` pada 09 Oktober 2026.*