# Laporan Implementasi Perbaikan Zakat — R-06

**Sistem:** Koperasi Syariah (Laravel 12 / PHP 8.3 / MySQL 8.4)
**Tanggal:** 09 Oktober 2026
**Dasar:** `docs/LAPORAN-AUDIT-ZAKAT.md` (temuan **Z-08**, prioritas Rendah)
**Ruang Lingkup:** Rekomendasi **R-06** — parameter porsi/hak amil (`Setting::zakatPorsiAmilPersen()` & `Setting::zakatHakAmil()`) beserta rekap **"Hak Amil"** pada laporan sumber & penggunaan dana zakat.

**Ringkasan status:** ✅ **SELESAI 100%** — 1/1 rekomendasi diimplementasikan; **6 test baru LULUS**; regresi suite penuh **1451 test lulus** (0 regresi).

---

## 1. IDENTIFIKASI DETAIL

### R-06 — Porsi/hak amil atas dana zakat (zero-hardcoding)

| Aspek | Uraian |
|---|---|
| **Temuan asal** | Z-08 (Rendah) — "Porsi/hak amil tidak dikonfigurasi eksplisit." |
| **Kondisi awal** | `amil` hanya **label kategori** pada `ZakatService::ASNAF` (`'amil' => 'Amil'`). Tidak ada parameter porsi (mis. 1/8 = 12,5%) di `Setting` maupun perhitungan otomatis; penetapan hak amil sepenuhnya manual tanpa panduan sistem. |
| **Standar acuan** | **Fatwa DSN-MUI No. 08/DSN-MUI/IV/2000** — amil termasuk salah satu dari 8 asnaf dan berhak atas bagian dana zakat (umumnya **maksimum 1/8 = 12,5%**). **QS At-Taubah 60** — 8 asnaf. **PSAK 109** — dana zakat = titipan (amanah); perhitungan hak amil bersifat **informatif (entitlement)**, tidak memotong jurnal. |
| **Cakupan perubahan** | (1) **Baru** — `Setting::zakatPorsiAmilPersen()` & `Setting::zakatHakAmil($dasar)` + DEFAULTS/TIPE/DESKRIPSI; (2) `ZakatController::laporan()` menghitung `dasarHakAmil`/`hakAmil`/`realisasiAmil`; (3) Rekap **"Hak Amil"** pada `resources/views/zakat/laporan.blade.php`; (4) Migration `2026_10_09_000800`; (5) Metadata `KebijakanKoperasi` (fillable/casts/label/riwayat); (6) Jalur UI `PengurusController::updateKebijakan()` + input `pengurus/kebijakan.blade.php`; (7) Berkas uji `tests/Feature/ZakatAmilPorsiTest.php`. |
| **Spesifikasi fungsional** | • **Porsi amil** dibaca dari `zakat_porsi_amil_persen` (default **12,50%**) dan **dijepit** pada rentang 0–100 (nilai tak masuk akal tidak diterima).<br>• **Hak amil (Rp)** = porsi (%) × **dasar penerimaan** dana zakat periode ini; dasar negatif dianggap nol (fail-safe).<br>• Laporan menyandingkan: porsi %, **hak amil (Rp)**, **realisasi penyaluran ke asnaf `amil`**, dan **selisih** (warna merah bila realisasi < hak).<br>• Bersifat **informatif** — tidak ada posting/penyesuaian jurnal otomatis (kendali manual Pengurus, sesuai PSAK 109).<br>• Parameter lama **tidak berubah** (backward compatible); default 12,50% hanyalah *guidance*, bukan pemotongan. |

---

## 2. RENCANA IMPLEMENTASI

### Dependensi antar modul

```
KebijakanKoperasi.zakat_porsi_amil_persen (DB)
        │  (Setting::all() → cache 5 menit)
        ▼
Setting::zakatPorsiAmilPersen()  ──►  Setting::zakatHakAmil($dasar)
        │                                     │
        ▼                                     ▼
ZakatController::laporan()  ◄── ZakatService::ringkasanSemua() (penerimaan dari jurnal Posted)
        │  (dasarHakAmil, hakAmil, realisasiAmil, porsiAmil)
        ▼
zakat/laporan.blade.php  ──►  Rekap "Hak Amil" (terpisah)
```

**Ketergantungan kritis:** `dasarHakAmil` dihitung dari **penerimaan berbasis jurnal berstatus `Posted`** pada akun dana zakat (`ZakatService::ringkasan()`), **bukan** dari tabel `zakat_transaksis` secara langsung. Karena itu pengujian laporan **wajib** melalui `ZakatService::terima()`/`salurkan()` agar jurnal ikut terposting (kontrol silang PSAK 109).

### Milestone

| # | Milestone | Status |
|---|---|---|
| M1 | Accessor `Setting` + DEFAULTS/TIPE/DESKRIPSI | ✅ |
| M2 | Migration kolom `zakat_porsi_amil_persen` | ✅ |
| M3 | Metadata model `KebijakanKoperasi` | ✅ |
| M4 | Jalur simpan UI (`PengurusController` + `pengurus/kebijakan.blade.php`) | ✅ |
| M5 | Perhitungan & render rekap pada `ZakatController::laporan()` + `zakat/laporan.blade.php` | ✅ |
| M6 | Uji (6 test) → hijau | ✅ |
| M7 | Regresi suite penuh | ✅ |
| M8 | Dokumentasi (laporan ini + pembaruan audit) | ✅ |

---

## 3. PERUBAHAN BERKAS

| # | Berkas | Jenis | Pokok perubahan |
|---|---|---|---|
| 1 | `app/Support/Setting.php` | Ubah | DEFAULTS `'zakat_porsi_amil_persen' => 12.50`; TIPE `=> 'decimal'`; DESKRIPSI; accessor `zakatPorsiAmilPersen()` (min/max 0–100) & `zakatHakAmil(float $dasar)` (dasar di-clamp ≥ 0, `round(..., 2)`). |
| 2 | `database/migrations/2026_10_09_000800_add_zakat_amil_porsi_parameter_to_kebijakan_koperasis.php` | **Baru** | Kolom `zakat_porsi_amil_persen` `DECIMAL(5,2)` **nullable** after `zakat_haul_hari`, komentar Fatwa DSN-MUI 08; guard `Schema::hasColumn` + `down()` ber-guard. |
| 3 | `app/Models/KebijakanKoperasi.php` | Ubah | Tambah `'zakat_porsi_amil_persen'` ke `$fillable`, `$casts` (`decimal:2`), label manusiawi `'Porsi Hak Amil atas Dana Zakat (%)'`, dan array riwayat/audit. |
| 4 | `app/Http/Controllers/Dashboard/PengurusController.php` | Ubah | Validasi `'zakat_porsi_amil_persen' => 'nullable|numeric|min:0|max:100'` + 3 pesan error kustom; assignment partial-update-safe (`$request->filled(...) ? (float) : Setting::zakatPorsiAmilPersen()`). |
| 5 | `resources/views/pengurus/kebijakan.blade.php` | Ubah | Input `number` (`step 0.01`, `min 0`, `max 100`, default 12.50) + span penjelasan Fatwa DSN-MUI 08 (1/8 = 12,5%). |
| 6 | `app/Http/Controllers/ZakatController.php` | Ubah | `laporan()` menghitung `dasarHakAmil` (penerimaan dana zakat dari `ringkasanSemua`), `hakAmil`, `realisasiAmil` (`perAsnaf['amil']`) dan mengoper 4 variabel ke view. |
| 7 | `resources/views/zakat/laporan.blade.php` | Ubah | Kartu **"Rekap Hak Amil (Fatwa DSN-MUI 08)"** — tabel 3 kolom (Uraian / Dasar (Rp) / Hak Amil (Rp)): porsi %, realisasi ke amil, dan selisih (merah bila selisih > 0). |
| 8 | `tests/Feature/ZakatAmilPorsiTest.php` | **Baru** | 6 test (lihat §5). |

**Nol hardcoding:** seluruh parameter dibaca dari `Setting`/`KebijakanKoperasi` (DB). **Nol perubahan merusak:** nilai default aman (P4).

---

## 4. IMPLEMENTASI KUNCI

### 4.1 Accessor `Setting` (`app/Support/Setting.php`)

```php
public static function zakatPorsiAmilPersen(): float
{
    return min(100.0, max(0.0, static::nominal('zakat_porsi_amil_persen', 12.50)));
}

public static function zakatHakAmil(float $dasar): float
{
    $dasar = max(0.0, $dasar);
    return round($dasar * (static::zakatPorsiAmilPersen() / 100), 2);
}
```

### 4.2 Rekap hak amil pada laporan (`ZakatController::laporan()`)

```php
// Rekap hak amil (Fatwa DSN-MUI 08): porsi amil atas penerimaan dana
// zakat periode ini, disandingkan dengan realisasi penyaluran ke asnaf
// `amil`. Bersifat informatif (entitlement) — tidak memotong jurnal.
$dasarHakAmil  = (float) ($ringkasan[ZakatTransaksi::DANA_ZAKAT]['penerimaan'] ?? 0);
$hakAmil       = Setting::zakatHakAmil($dasarHakAmil);
$realisasiAmil = (float) ($perAsnaf['amil'] ?? 0);

return view('zakat.laporan', [
    // ...
    'porsiAmil'     => Setting::zakatPorsiAmilPersen(),
    'dasarHakAmil'  => $dasarHakAmil,
    'hakAmil'       => $hakAmil,
    'realisasiAmil' => $realisasiAmil,
]);
```

### 4.3 Validasi jalur simpan (`PengurusController::updateKebijakan()`)

```php
'zakat_porsi_amil_persen' => 'nullable|numeric|min:0|max:100',
```

```php
$kebijakan->zakat_porsi_amil_persen = $request->filled('zakat_porsi_amil_persen')
    ? (float) $request->zakat_porsi_amil_persen
    : Setting::zakatPorsiAmilPersen();
```

---

## 5. PENGUJIAN

**Berkas:** `tests/Feature/ZakatAmilPorsiTest.php` (menggunakan `DatabaseTransactions`, `Setting::flush()` pada `tearDown()`).

| # | Test | Skenario | Hasil |
|---|---|---|---|
| 1 | `test_default_porsi_amil_duabelas_setengah_persen` | Default tanpa konfigurasi = 12,50% (setara 1/8). | ✅ |
| 2 | `test_hak_amil_adalah_porsi_dari_penerimaan_zakat` | 12,5% × Rp 10.000.000 = Rp 1.250.000. | ✅ |
| 3 | `test_porsi_amil_dapat_dikonfigurasi` | Ubah porsi via kebijakan → hak amil mengikuti (nol hardcoding). | ✅ |
| 4 | `test_porsi_amil_dijepit_pada_rentang_nol_sampai_seratus` | Nilai di luar 0–100 dijepit (mis. 150 → 100; negatif → 0). | ✅ |
| 5 | `test_hak_amil_dasar_negatif_dianggap_nol` | Dasar negatif → hak amil 0 (fail-safe). | ✅ |
| 6 | `test_laporan_menampilkan_rekap_hak_amil_terpisah` | Penerimaan Rp 8.000.000 **via `ZakatService::terima()`** & penyaluran ke `amil` Rp 250.000 **via `salurkan()`**; laporan menampilkan `dasarHakAmil` 8.000.000, `hakAmil` 1.000.000, `realisasiAmil` 250.000, dan memuat teks "Hak Amil". | ✅ |

**Hasil:** `Tests: 6 passed (14 assertions)`.

### Regresi

| Suite | Hasil |
|---|---|
| `php artisan test --filter=Zakat` | **56 passed** (202 assertions) |
| `php artisan test` (suite penuh) | **1451 passed** (6502 assertions) |

**0 regresi.**

> **Catatan pengujian penting:** pada iterasi pertama, test #6 gagal (`dasarHakAmil` = 0). Akar masalah: data uji dibuat langsung via `ZakatTransaksi::create()` **tanpa jurnal**, sedangkan `laporan()` menghitung penerimaan dari **jurnal berstatus `Posted`** (`ZakatService::ringkasan()`). Perbaikan: test menggunakan `ZakatService::terima()`/`salurkan()` — **mengikuti jalur nyata aplikasi** sehingga jurnal (DEBET kas / KREDIT akun dana) ikut terposting. Ini justru memperkuat validitas uji (menguji integrasi jurnal → laporan, bukan sekadar tabel).

---

## 6. VERIFIKASI KRITERIA KEBERHASILAN

Kriteria audit R-06: **"Laporan menampilkan kolom 'Hak Amil' terpisah."**

| Kriteria | Bukti | Status |
|---|---|---|
| Parameter porsi amil terkonfigurasi (default 12,5%) | Kolom DB `zakat_porsi_amil_persen` (migrasi DONE), `Setting::zakatPorsiAmilPersen()`, input UI Kebijakan | ✅ |
| Hak amil dihitung dari dasar penerimaan zakat | `Setting::zakatHakAmil()` + `ZakatController::laporan()` (12,5% × penerimaan jurnal Posted) | ✅ |
| **Kolom "Hak Amil" tampil terpisah pada laporan** | Kartu "Rekap Hak Amil (Fatwa DSN-MUI 08)" pada `zakat/laporan.blade.php`; dikunci test #6 (`assertSee('Hak Amil')`) | ✅ |
| Realisasi penyaluran ke amil disandingkan | Baris `realisasiAmil` + selisih pada kartu | ✅ |
| Nol hardcoding & backward compatible | Default aman; parameter lama tidak berubah | ✅ |
| Tidak ada dependensi terabaikan | Jalur UI lengkap (migrasi → model → controller → view) + pengujian integrasi jurnal→laporan | ✅ |

**Kesimpulan:** seluruh kriteria **TERPENUHI**. Temuan **Z-08 tertutup**; rekomendasi **R-06 SELESAI**.

---

## 7. CATATAN & RISIKO SISA

- ✅ **Z-08 tertutup** untuk aspek konfigurasi & pelaporan hak amil.
- ✅ **0 bug sisa** yang teridentifikasi selama pengujian.
- ✅ **0 regresi** pada suite penuh (1451 passed).
- ✅ Dokumentasi diperbarui: `docs/LAPORAN-AUDIT-ZAKAT.md` (status Z-08) + laporan ini.

**Temuan yang MASIH TERBUKA** (di luar lingkup R-06): Z-06 (pilihan kas/R-07), Z-07 (master pihak/R-08), Z-09 (integrasi CaLK/R-09) — dikerjakan pada gelombang berikutnya.

> **Risiko sisa (dicatat):** perhitungan hak amil bersifat **informatif (entitlement)** dan **tidak** memotong/menyesuaikan jurnal secara otomatis. Bila kelak diinginkan pembukuan otomatis hak amil (mis. split penerimaan 12,5% ke akun amil pada saat penerimaan), diperlukan mekanisme jurnal tersendiri dan keputusan Pengurus atas dasar yang dipakai (bruto penerimaan vs neto setelah penyaluran). Cakupan saat ini sengaja dibatasi pada **guidance & transparansi** agar tetap sesuai PSAK 109 dan kendali manual Pengurus.

---

*Disusun sebagai bagian dari implementasi rekomendasi audit zakat. Seluruh perubahan diverifikasi melalui `php artisan test` pada 09 Oktober 2026.*
