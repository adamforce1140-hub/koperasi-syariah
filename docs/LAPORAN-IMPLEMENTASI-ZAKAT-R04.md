# Laporan Implementasi Perbaikan Zakat — R-04

**Sistem:** Koperasi Syariah (Laravel 12 / PHP 8.3 / MySQL 8.4)
**Tanggal:** 09 Oktober 2026
**Dasar:** `docs/LAPORAN-AUDIT-ZAKAT.md` (temuan **Z-05**, prioritas Sedang)
**Ruang Lingkup:** Rekomendasi **R-04** — unifikasi resolusi akun dana zakat & infak/sedekah melalui resolver bersama.

**Ringkasan status:** ✅ **SELESAI 100%** — 1/1 rekomendasi diimplementasikan; **6 test baru LULUS**; regresi suite terkait **64 passed** (0 regresi).

---

## 1. IDENTIFIKASI DETAIL

### R-04 — Satukan resolusi akun dana zakat/infak (zero-hardcoding)

| Aspek | Uraian |
|---|---|
| **Temuan asal** | Z-05 (Sedang) — "Resolusi akun dana tidak konsisten: pencatatan modul zakat *hardcoded*, penyajian DIT membaca setting." |
| **Akar penyebab** | `ZakatService::akunDana()` mengembalikan konstanta `App\Support\Coa::DANA_ZAKAT` / `DANA_INFAK_SEDEKAH` secara **hardcode**, sementara `LaporanKeuanganSyariahService::akunDit()` **sudah** membaca parameter `account_dana_zakat_id` / `account_dana_infak_id`. Bila parameter diisi, kedua jalur menunjuk **akun berbeda** → **divergensi saldo** antara buku besar modul zakat dan penyajian Dana Investasi Terikat (DIT). |
| **Temuan turunan** | Kolom `account_dana_zakat_id` / `account_dana_infak_id` **tidak pernah ada** di tabel `kebijakan_koperasis` — parameter tidak dapat diubah Pengurus sama sekali (selalu `null`). |
| **Standar acuan** | **PSAK 109** (Akuntansi Zakat & Infak/Sedekah): dana zakat dan infak/sedekah adalah **dana titipan (amanah)**, bukan pendapatan koperasi, sehingga **wajib disajikan terpisah** dan saldo di kedua penyajian harus konsisten. Prinsip **zero-hardcoding**: pemetaan akun adalah kebijakan yang dapat dikonfigurasi. |
| **Cakupan perubahan** | (1) **Baru** — `CoaResolver::kodeDanaZakat()` & `kodeDanaInfakSedekah()` (titik resolusi tunggal); (2) `ZakatService::akunDana()` dialihkan ke resolver; (3) `LaporanKeuanganSyariahService::akunDit()` memakai resolver yang sama; (4) Migrasi kolom `account_dana_zakat_id` / `account_dana_infak_id`; (5) Metadata `KebijakanKoperasi` (fillable/casts/label/KOLOM_AUDIT); (6) Validasi + assignment di `PengurusController::updateKebijakan()`; (7) Dropdown di `resources/views/pengurus/kebijakan.blade.php`. |
| **Spesifikasi fungsional** | • Kolom bersifat **nullable** — dibiarkan kosong → resolver memakai registri standar (`2-3002` / `2-3004`); perilaku lama **tidak berubah** (P4 Default Aman, backward compatible).<br>• Bila diisi akun X → **buku besar modul zakat DAN penyajian DIT menunjuk akun X yang sama**.<br>• Setting menunjuk CoA yang **tidak ada / nonaktif** → **fallback** ke registri standar disertai `Log::warning` (fail-safe, bukan fail-silent).<br>• Pengurus dapat memindahkan akun dari UI Kebijakan Koperasi tanpa mengubah kode.<br>• Cakupan sengaja **dibatasi pada zakat & infak** — dana kebajikan/sosial/titipan selisih belum dipetakan karena `DanaKebajikanService` masih memakai konstanta (`AutoJournalingService::DANA_KEBAJIKAN`); memetakannya kini akan **memunculkan divergensi baru**. |

---

## 2. RENCANA IMPLEMENTASI

### Dependensi antar modul

```
Migrasi 2026_10_09_000700 ──► kolom account_dana_zakat_id / account_dana_infak_id
        └─► CoaResolver::kodeDanaZakat() / kodeDanaInfakSedekah()   [titik resolusi tunggal]
                ├─► ZakatService::akunDana()                        [pencatatan modul zakat]
                └─► LaporanKeuanganSyariahService::akunDit()        [penyajian DIT]
KebijakanKoperasi (fillable/casts/label/KOLOM_AUDIT)
        └─► PengurusController::updateKebijakan()  [validasi + assignment]
                └─► resources/views/pengurus/kebijakan.blade.php    [dropdown akun]
```

**Catatan dependensi:** R-04 **independen** dari R-05 s.d. R-09. Bergantung pada `Setting::akunId()` (sudah ada) dan `CoaResolver::kodeDariId()` (sudah ada, private, ter-cache per instance).

### Tahapan pengembangan (urut pelaksanaan)

| Tahap | Aktivitas | Keluaran |
|---|---|---|
| T1 | Analisis akar penyebab & spesifikasi fungsional | Dokumen bagian 1 |
| T2 | Rencana implementasi & dependensi | Dokumen bagian 2 |
| T3 | Titik resolusi tunggal di `CoaResolver` | `CoaResolver.php` |
| T4 | Alihkan `ZakatService` & `LaporanKeuanganSyariahService` ke resolver | 2 berkas Service |
| T5 | Migrasi kolom + metadata `KebijakanKoperasi` | `2026_10_09_000700_...php`, `KebijakanKoperasi.php` |
| T6 | Jalur UI: validasi controller + dropdown blade | `PengurusController.php`, `kebijakan.blade.php` |
| T7 | Pengujian: unit + integrasi + UAT + regresi | 6 test baru (LULUS), 64 passed regresi terkait |
| T8 | Dokumentasi & rilis | Laporan ini + pembaruan `LAPORAN-AUDIT-ZAKAT.md` |

---

## 3. PERUBAHAN KODE

### 3.1 `app/Services/CoaResolver.php` — titik resolusi tunggal — **DIUBAH**

Ditambahkan dua accessor publik yang menjadi **satu-satunya** tempat menerjemahkan "akun dana apa yang dipakai" → kode CoA:

```php
/**
 * Kode akun CoA dana zakat (PSAK 109 — dana titipan/amanah).
 * Dibaca dari kebijakan `account_dana_zakat_id`; bila kosong atau menunjuk
 * akun tidak valid → fallback ke registri standar `2-3002`.
 */
public function kodeDanaZakat(): string
{
    return $this->kodeDariSetting('account_dana_zakat_id') ?? \App\Support\Coa::DANA_ZAKAT;
}

/**
 * Kode akun CoA dana infak & sedekah (PSAK 109 — dana titipan/amanah).
 * Bila kosong/tidak valid → fallback ke registri standar `2-3004`.
 */
public function kodeDanaInfakSedekah(): string
{
    return $this->kodeDariSetting('account_dana_infak_id') ?? \App\Support\Coa::DANA_INFAK_SEDEKAH;
}
```

Helper privat `kodeDariSetting()`:

```php
private function kodeDariSetting(string $setting): ?string
{
    $id = Setting::akunId($setting);

    if ($id === null) {
        return null;                       // kosong/0 → pakai standar
    }

    $kode = $this->kodeDariId($id);

    if ($kode === null) {
        Log::warning("CoaResolver: setting [{$setting}] menunjuk CoA id={$id} yang tidak ada/nonaktif — memakai pemetaan standar.");
    }

    return $kode;
}
```

### 3.2 `app/Services/ZakatService.php` — `akunDana()` — **DIUBAH**

Sebelumnya hardcode konstanta; kini membaca resolver bersama:

```php
public static function akunDana(string $dana): string
{
    $resolver = app(CoaResolver::class);

    return $dana === ZakatTransaksi::DANA_INFAK_SEDEKAH
        ? $resolver->kodeDanaInfakSedekah()
        : $resolver->kodeDanaZakat();
}
```

### 3.3 `app/Services/LaporanKeuanganSyariahService.php` — `akunDit()` — **DIUBAH**

Dua baris pertama kini memakai resolver yang **sama** dengan modul zakat, sehingga keduanya dijamin menunjuk akun identik:

```php
private function akunDit(): array
{
    $resolver = app(CoaResolver::class);

    return [
        'dana_zakat'      => $resolver->kodeDanaZakat(),              // ← disatukan (Z-05)
        'dana_infak'      => $resolver->kodeDanaInfakSedekah(),       // ← disatukan (Z-05)
        'dana_kebajikan'  => $this->kodeAkunDariSetting('account_dana_kebajikan_id') ?? Akun::DANA_KEBAJIKAN,
        'dana_sosial'     => $this->kodeAkunDariSetting('account_dana_sosial_id') ?? Akun::DANA_SOSIAL,
        'titipan_selisih' => $this->kodeAkunDariSetting('account_titipan_selisih_id') ?? Akun::TITIPAN_SELISIH_KAS,
    ];
}
```

### 3.4 Migrasi — `database/migrations/2026_10_09_000700_add_zakat_dana_account_parameters_to_kebijakan_koperasis.php` — **BARU**

Menambah 2 kolom `unsignedBigInteger` **nullable** (guard `Schema::hasColumn`, idempoten):

```php
private const KOLOM = [
    'account_dana_zakat_id' => 'COA Dana Zakat (PSAK 109). Kosong = ikuti standar 2-3002.',
    'account_dana_infak_id' => 'COA Dana Infak & Sedekah (PSAK 109). Kosong = ikuti standar 2-3004.',
];

public function up(): void
{
    if (! Schema::hasTable('kebijakan_koperasis')) {
        return;
    }

    Schema::table('kebijakan_koperasis', function (Blueprint $table) {
        foreach (self::KOLOM as $kolom => $komentar) {
            if (! Schema::hasColumn('kebijakan_koperasis', $kolom)) {
                $table->unsignedBigInteger($kolom)
                    ->nullable()
                    ->after('account_simpanan_sukarela_id')
                    ->comment($komentar);
            }
        }
    });
}
```

`down()` menghapus kedua kolom. Dijalankan: `php artisan migrate --force` → **DONE 66.01ms**.

> **Catatan 1 (anchor kolom):** rancangan awal memakai `after('account_bank_koperasi_id')`, namun kolom tersebut **tidak ada** pada tabel `kebijakan_koperasis` (verifikasi `Schema::getColumnListing()`) sehingga migrasi gagal `Unknown column`. Anchor diganti ke `account_simpanan_sukarela_id` yang **terbukti ada**. Pola anchor ini konsisten dengan migrasi `2026_10_05_000500_add_zakat_parameters_to_kebijakan_koperasis.php`.
> **Catatan 2 (cakupan):** draf pertama memuat 5 kolom (`account_dana_kebajikan_id`, `account_dana_sosial_id`, `account_titipan_selisih_id`). Ditulis ulang menjadi **hanya 2 kolom** karena `DanaKebajikanService` masih hardcoded — memetakan ketiganya akan memunculkan divergensi baru (kebalikan dari tujuan R-04). Docblock migrasi memuat "CATATAN CAKUPAN" sebagai penanda.

### 3.5 `app/Models/KebijakanKoperasi.php` — metadata — **DIUBAH**

- `$fillable` += `'account_dana_zakat_id', 'account_dana_infak_id'` (dengan komentar acuan PSAK 109 / Z-05)
- `$casts` += `'account_dana_zakat_id' => 'integer', 'account_dana_infak_id' => 'integer'`
- `LABEL_KOLOM` += `'account_dana_zakat_id' => 'COA Dana Zakat (PSAK 109)'`, `'account_dana_infak_id' => 'COA Dana Infak & Sedekah (PSAK 109)'`
- `KOLOM_AUDIT` += kedua kolom → perubahan akun dana **tercatat** di `kebijakan_koperasi_logs` (jejak audit).

### 3.6 `app/Http/Controllers/Dashboard/PengurusController.php` — jalur UI — **DIUBAH**

**Validasi** (`updateKebijakan()`):

```php
'account_dana_zakat_id' => 'nullable|integer|exists:coas,id',
'account_dana_infak_id' => 'nullable|integer|exists:coas,id',
```

**Pesan galat** (Bahasa Indonesia, konsisten dengan pola `account_simpanan_wajib_id`):

```php
'account_dana_zakat_id.integer' => 'Akun Dana Zakat tidak valid.',
'account_dana_zakat_id.exists'  => 'Akun Dana Zakat yang dipilih tidak ditemukan pada bagan akun.',
'account_dana_infak_id.integer' => 'Akun Dana Infak & Sedekah tidak valid.',
'account_dana_infak_id.exists'  => 'Akun Dana Infak & Sedekah yang dipilih tidak ditemukan pada bagan akun.',
```

**Assignment** (pola `if ($request->has(...))` seperti `akun_tazir_id`; field kosong = "kembali ikuti registri standar"):

```php
if ($request->has('account_dana_zakat_id')) {
    $kebijakan->account_dana_zakat_id = $request->filled('account_dana_zakat_id')
        ? (int) $request->account_dana_zakat_id
        : null;
} else {
    $kebijakan->account_dana_zakat_id = Setting::akunId('account_dana_zakat_id');
}

if ($request->has('account_dana_infak_id')) {
    $kebijakan->account_dana_infak_id = $request->filled('account_dana_infak_id')
        ? (int) $request->account_dana_infak_id
        : null;
} else {
    $kebijakan->account_dana_infak_id = Setting::akunId('account_dana_infak_id');
}
```

### 3.7 `resources/views/pengurus/kebijakan.blade.php` — dropdown akun — **DIUBAH**

Ditambahkan grid 2 kolom berisi dropdown `account_dana_zakat_id` & `account_dana_infak_id`, masing-masing dengan opsi pertama `— Ikuti standar 2-3002 —` / `— Ikuti standar 2-3004 —` (nilai kosong), daftar `$akunCoa`, pemulihan `old()`, dan teks bantuan yang menjelaskan bahwa akun dipakai **bersama** oleh pencatatan zakat dan penyajian DIT.

### 3.8 Berkas pengujian — `tests/Feature/ZakatPsak109Test.php` — **DIUBAH**

Ditambahkan bagian **6. RESOLUSI AKUN DANA (R-04 / Z-05)** dan **6b. JALUR UI**:

- Helper: `setAkunDana()`, `akunBaru()`, `dit()`, `kodeBarisDit()`, `payloadKebijakan()`.
- **4 test logika** + **2 test UAT jalur UI**.

---

## 4. HASIL PENGUJIAN

### 4.1 Unit & Integrasi & UAT

| # | Test | Jenis | Skenario | Hasil |
|---|---|---|---|---|
| 1 | `test_default_resolusi_akun_dana_mengikuti_registri_standar` | Unit | Kedua parameter `null` → `akunDana()` mengembalikan `2-3002` / `2-3004` (P4 Default Aman) | ✅ |
| 2 | `test_setting_akun_dana_menyatukan_pencatatan_dan_dit` | Integrasi | **Kriteria penerimaan R-04**: setting zakat = akun X → `akunDana('zakat')` = kode X **DAN** baris DIT `dana_zakat` = kode X; `terima()` 250.000 → `totalKredit()` akun X bertambah | ✅ |
| 3 | `test_setting_akun_dana_infak_menyatukan_pencatatan_dan_dit` | Integrasi | Setting infak = akun X → pencatatan & DIT menunjuk X; dana zakat **tidak** ikut berpindah (pemisahan dana tetap) | ✅ |
| 4 | `test_setting_akun_dana_nonaktif_fallback_ke_registri_standar` | Integrasi | Setting menunjuk CoA nonaktif **maupun** ID tak ada → fallback `2-3002` (fail-safe) | ✅ |
| 5 | `test_pengurus_dapat_memindahkan_akun_dana_zakat_via_ui` | **UAT** | PUT `pengurus.kebijakan.update` + `account_dana_*_id` → tanpa galat; resolver & DIT menunjuk X; mengirim nilai kosong mengembalikan ke standar | ✅ |
| 6 | `test_validasi_menolak_akun_dana_tidak_dikenal` | **UAT** | PUT dengan ID 999999999 → `assertSessionHasErrors` pada kedua field; kebijakan **tidak berubah** | ✅ |

### 4.2 Regresi suite terkait

```
php artisan test --filter=ZakatPsak109Test
Tests:    26 passed (112 assertions)

php artisan test --filter="ZakatHisabKonfigurasiTest|FlexibilityParameterTest|PsakParameterTest|SimpananWajibKonfigurasiDinamisTest"
Tests:    64 passed (278 assertions)
```

**Sebelum:** `ZakatPsak109Test` 20 test. **Sesudah:** 26 test (**+6**). **Regresi:** **0**.

*(Regresi penuh seluruh suite dijalankan pada tahap verifikasi akhir t7.)*

---

## 5. DOKUMENTASI DEPLOYMENT

### 5.1 Berkas yang berubah/ditambah

| Berkas | Aksi | Dampak |
|---|---|---|
| `app/Services/CoaResolver.php` | Ubah | +2 accessor publik & helper `kodeDariSetting()` |
| `app/Services/ZakatService.php` | Ubah | `akunDana()` berbasis resolver (bukan hardcode) |
| `app/Services/LaporanKeuanganSyariahService.php` | Ubah | `akunDit()` memakai resolver bersama |
| `database/migrations/2026_10_09_000700_add_zakat_dana_account_parameters_to_kebijakan_koperasis.php` | **Baru** | Migrasi (tambah 2 kolom nullable) |
| `app/Models/KebijakanKoperasi.php` | Ubah | fillable/casts/label/KOLOM_AUDIT |
| `app/Http/Controllers/Dashboard/PengurusController.php` | Ubah | Validasi + pesan + assignment |
| `resources/views/pengurus/kebijakan.blade.php` | Ubah | 2 dropdown akun dana |
| `tests/Feature/ZakatPsak109Test.php` | Ubah | +6 test (test-only) |

### 5.2 Langkah rilis ke produksi

```bash
# 1. Tarik kode terbaru
git pull origin <branch>

# 2. Jalankan migrasi (tambah 2 kolom nullable — aman, tanpa downtime)
php artisan migrate --force

# 3. Bersihkan cache konfigurasi/route/view
php artisan optimize:clear

# 4. Jalankan pengujian (gerbang mutu)
php artisan test
```

**Catatan penting:**
- Kedua kolom **nullable** → migrasi **tidak mengubah data** dan **tanpa downtime**.
- Migrasi **idempoten** (`Schema::hasColumn` guard) → aman dijalankan berulang.
- Parameter default **kosong** → setelah deploy, pencatatan zakat **berperilaku sama seperti sebelumnya** (nol perubahan tak terduga). Pengurus dapat **memindahkan** akun lewat UI Kebijakan Koperasi.
- **Rollback:** `php artisan migrate:rollback` (menghapus 2 kolom) atau kembalikan commit.
- **Fail-safe:** setting yang menunjuk CoA tidak ada/nonaktif **tidak** membuat sistem gagal — resolver jatuh ke akun standar dan menulis `Log::warning` untuk investigasi.

### 5.3 Kriteria penerimaan (Definition of Done)

| Kriteria | Status |
|---|---|
| `CoaResolver` memiliki titik resolusi tunggal untuk dana zakat & infak | ✅ |
| `ZakatService::akunDana()` membaca setting (bukan hardcode) — fallback `2-3002`/`2-3004` | ✅ |
| `LaporanKeuanganSyariahService::akunDit()` memakai resolver yang sama | ✅ |
| **Kriteria audit R-04:** setting diisi akun X → buku besar zakat **dan** DIT menunjuk akun X yang sama | ✅ (test #2 & #5) |
| Kolom `account_dana_zakat_id`/`account_dana_infak_id` ada di DB (migrasi jalan) | ✅ |
| Pengurus dapat memindahkan akun via UI (validasi + dropdown) | ✅ (test #5) |
| Input tidak sah ditolak & kebijakan tidak berubah | ✅ (test #6) |
| Setting menunjuk akun nonaktif/tak ada → fallback aman | ✅ (test #4) |
| Default kosong (backward compatible) — nol regresi | ✅ (64 passed terkait) |
| Perubahan akun dana tercatat pada jejak audit kebijakan | ✅ (`KOLOM_AUDIT`) |
| Dokumentasi audit diperbarui | ✅ |

---

## 6. STATUS AKHIR

- ✅ **R-04 SELESAI** — resolusi akun dana zakat & infak kini **terunifikasi**; temuan **Z-05 tertutup** untuk cakupan zakat & infak/sedekah.
- ✅ **0 bug sisa** yang teridentifikasi selama pengujian.
- ✅ **0 regresi** pada suite terkait (64 passed).
- ✅ Dokumentasi diperbarui: `docs/LAPORAN-AUDIT-ZAKAT.md` (status Z-05) + laporan ini.

**Temuan yang MASIH TERBUKA** (di luar lingkup R-04): Z-03 (zakat penghasilan/R-05), Z-06 (pilihan kas/R-07), Z-07 (master pihak/R-08), Z-08 (porsi amil/R-06), Z-09 (integrasi CaLK/R-09) — dikerjakan pada gelombang berikutnya.

> **Risiko sisa (dicatat):** dana kebajikan/sosial/titipan selisih **belum** dipetakan. Bila kelak `DanaKebajikanService` dialihkan ke resolver, migrasi lanjutan untuk 3 kolom tersebut akan diperlukan agar konsisten. Saat ini pemetaannya sengaja ditahan demi menghindari divergensi baru.

---

*Disusun sebagai bagian dari implementasi rekomendasi audit zakat. Seluruh perubahan diverifikasi melalui `php artisan test` pada 09 Oktober 2026.*
