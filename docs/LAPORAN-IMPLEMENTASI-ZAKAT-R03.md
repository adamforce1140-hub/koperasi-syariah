# Laporan Implementasi Perbaikan Zakat — R-03

**Sistem:** Koperasi Syariah (Laravel 12 / PHP 8.3 / MySQL 8.4)
**Tanggal:** 09 Oktober 2026
**Dasar:** `docs/LAPORAN-AUDIT-ZAKAT.md` (temuan **Z-04**, prioritas Sedang)
**Ruang Lingkup:** Rekomendasi **R-03** — penegakan syarat **HAUL** (kepemilikan penuh 1 tahun hijriah) pada hisab zakat maal.

**Ringkasan status:** ✅ **SELESAI 100%** — 1/1 rekomendasi diimplementasikan; **7 test baru LULUS**; regresi suite penuh **1432 passed** (0 regresi).

---

## 1. IDENTIFIKASI DETAIL

### R-03 — Penegakan syarat HAUL pada hisab zakat maal

| Aspek | Uraian |
|---|---|
| **Temuan asal** | Z-04 (Sedang) — "Tidak ada mekanisme HAUL (kepemilikan 1 tahun)" |
| **Akar penyebab** | `Setting::hitungZakat()` hanya memeriksa **nishab**; tidak ada parameter haul maupun field tanggal kepemilikan (grep kata kunci `haul` di `app/` = **0 hasil**). Akibatnya harta yang belum genap satu haul tetap dapat dinyatakan "wajib zakat" → hasil hisab **berpotensi tidak sah secara syariah**. |
| **Standar acuan** | Syarat wajib zakat maal = **NISHAB DAN HAUL** (Fatwa DSN-MUI No. 08/DSN-MUI/IV/2000) — kepemilikan penuh selama satu tahun hijriah (± 354 hari). |
| **Cakupan perubahan** | (1) Migrasi kolom parameter `zakat_haul_aktif` & `zakat_haul_hari`; (2) Metadata `Setting` (DEFAULTS/TIPE/DESKRIPSI) & `KebijakanKoperasi` (fillable/casts/label); (3) Accessor `Setting::zakatHaulAktif()`/`zakatHaulHari()`; (4) Logika haul di `Setting::hitungZakat()`; (5) `ZakatController::hitung()` + `pesanHisab()`; (6) Panel kalkulator di `zakat/index.blade.php` (menghidupkan endpoint `zakat.hitung` yang sebelumnya yatim/tak dipanggil UI). |
| **Spesifikasi fungsional** | • Saklar `zakat_haul_aktif` default **NONAKTIF** (P4 Default Aman) → hisab lama berbasis nishab **tidak berubah** (backward compatible, nol regresi).<br>• Bila **AKTIF**: status "wajib" baru diberikan setelah kepemilikan genap `zakat_haul_hari` hari (default **354** hari hijriah).<br>• Harta **≥ nishab** tetapi kepemilikan **< haul** → `kena_zakat = false`.<br>• **Fail-closed**: bila haul aktif tetapi tanggal kepemilikan **kosong** → haul dianggap **belum terpenuhi** (sistem tidak menyatakan wajib tanpa bukti).<br>• Panjang haul **bukan konstanta kode** — dibaca dari DB (nol hardcoding); dapat diubah Pengurus.<br>• Endpoint `POST /zakat/hitung` menerima `tanggal_kepemilikan` (nullable, format tanggal) & mengembalikan status haul + pesan hisab yang kontekstual. |

---

## 2. RENCANA IMPLEMENTASI

### Dependensi antar modul

```
Migrasi 2026_10_09_000600 ──► kolom zakat_haul_aktif / zakat_haul_hari
        └─► Setting (DEFAULTS/TIPE/DESKRIPSI + accessor zakatHaulAktif()/zakatHaulHari())
                └─► Setting::hitungZakat($harta, $tanggalKepemilikan)  [inti logika]
                        └─► ZakatController::hitung() + pesanHisab()   [endpoint]
                                └─► view zakat/index.blade.php          [panel kalkulator AJAX]
KebijakanKoperasi (fillable/casts/LABEL_KOLOM) ──► UI Kebijakan Koperasi (parameter dapat diubah Pengurus)
```

**Catatan dependensi:** R-03 **independen** dari R-01/R-02. Membaca parameter haul dari `KebijakanKoperasi` sehingga memerlukan kolom (migrasi) sebelum accessor berfungsi.

### Tahapan pengembangan (urut pelaksanaan)

| Tahap | Aktivitas | Keluaran |
|---|---|---|
| T1 | Analisis akar penyebab & spesifikasi fungsional haul | Dokumen bagian 1 |
| T2 | Rencana implementasi & dependensi | Dokumen bagian 2 |
| T3 | Migrasi + parameter (Setting & KebijakanKoperasi) | `2026_10_09_000600_add_zakat_haul_parameters_to_kebijakan_koperasis.php` |
| T4 | Logika haul di `Setting::hitungZakat()` + endpoint & validasi | `Setting.php`, `ZakatController.php` |
| T5 | Panel kalkulator hisab (input tanggal + status haul) | `zakat/index.blade.php` |
| T6 | Pengujian: unit + integrasi + UAT + regresi penuh | 7 test baru (LULUS), 1432 passed |
| T7 | Dokumentasi & rilis | Laporan ini + pembaruan `LAPORAN-AUDIT-ZAKAT.md` |

---

## 3. PERUBAHAN KODE

### 3.1 Migrasi — `database/migrations/2026_10_09_000600_add_zakat_haul_parameters_to_kebijakan_koperasis.php` — **BARU**

Menambah 2 kolom nullable (dengan guard `Schema::hasColumn`, idempoten):

```php
Schema::table('kebijakan_koperasis', function (Blueprint $table) {
    if (! Schema::hasColumn('kebijakan_koperasis', 'zakat_haul_aktif')) {
        $table->boolean('zakat_haul_aktif')->nullable()->after('zakat_minimal_tarik')
            ->comment('Saklar penegakan syarat haul (kepemilikan 1 tahun hijriah) pada hisab zakat maal.');
    }
    if (! Schema::hasColumn('kebijakan_koperasis', 'zakat_haul_hari')) {
        $table->unsignedSmallInteger('zakat_haul_hari')->nullable()->after('zakat_haul_aktif')
            ->comment('Panjang satu haul dalam hari (standar 354 hari hijriah).');
    }
});
```

`down()` menghapus kedua kolom. Dijalankan: `php artisan migrate --force` → **DONE 65.73ms**.

### 3.2 `app/Support/Setting.php` — parameter & accessor (R-03)

**DEFAULTS** (P4 Default Aman — default NONAKTIF agar perilaku lama tidak berubah):

```php
'zakat_haul_aktif' => false,   // Saklar penegakan syarat haul
'zakat_haul_hari'  => 354,     // 354 hari hijriah
```

**TIPE:** `'zakat_haul_aktif' => 'boolean'`, `'zakat_haul_hari' => 'integer'`.

**DESKRIPSI:** entri untuk `zakat_haul_aktif` & `zakat_haul_hari` (ditambah pula entri `zakat_rate`, `zakat_nisab_gram`, `harga_emas_per_gram`, `zakat_minimal_tarik`).

**Accessor baru:**

```php
public static function zakatHaulAktif(): bool
{
    return static::bool('zakat_haul_aktif', false);
}

public static function zakatHaulHari(): int
{
    return max(1, (int) static::get('zakat_haul_hari', 354));
}
```

### 3.3 `app/Support/Setting.php` — `hitungZakat()` diperluas

```php
public static function hitungZakat(float $harta, ?string $tanggalKepemilikan = null): array
{
    $nishab         = static::zakatNisabNominal();
    $kadar          = static::zakatRate();
    $nishabTercapai = $harta >= $nishab && $nishab > 0.0;

    $haulAktif       = static::zakatHaulAktif();
    $haulHari        = static::zakatHaulHari();
    $haulTerpenuhi   = true;
    $hariKepemilikan = null;

    if ($haulAktif) {
        if ($tanggalKepemilikan === null || $tanggalKepemilikan === '') {
            // Fail-closed: tanpa tanggal kepemilikan, haul tidak dapat
            // diverifikasi sehingga harta belum dinyatakan wajib zakat.
            $haulTerpenuhi = false;
        } else {
            $mulai           = Carbon::parse($tanggalKepemilikan)->startOfDay();
            $hariKepemilikan = (int) $mulai->diffInDays(now()->startOfDay(), false);
            $haulTerpenuhi   = $hariKepemilikan >= $haulHari;
        }
    }

    $wajib = $nishabTercapai && $haulTerpenuhi;

    return [
        'kena_zakat'       => $wajib,
        'nishab'           => $nishab,
        'nishab_terpenuhi' => $nishabTercapai,
        'kadar_persen'     => $kadar,
        'zakat'            => $wajib ? round($harta * ($kadar / 100), 2) : 0.0,
        'haul_aktif'       => $haulAktif,
        'haul_terpenuhi'   => $haulTerpenuhi,
        'haul_hari'        => $haulHari,
        'haul_sejak'       => ($haulAktif && $tanggalKepemilikan !== null && $tanggalKepemilikan !== '')
            ? Carbon::parse($tanggalKepemilikan)->toDateString() : null,
        'hari_kepemilikan' => $hariKepemilikan,
    ];
}
```

*Backward compatible:* kunci `kena_zakat`, `nishab`, `kadar_persen`, `zakat` tetap sama seperti sebelumnya.

### 3.4 `app/Models/KebijakanKoperasi.php` — metadata

- `$fillable` += `'zakat_haul_aktif', 'zakat_haul_hari'`
- `$casts` += `'zakat_haul_aktif' => 'boolean', 'zakat_haul_hari' => 'integer'`
- `LABEL_KOLOM` += `'zakat_haul_aktif' => 'Penegakan Syarat Haul Zakat Maal'`, `'zakat_haul_hari' => 'Panjang Haul Zakat (hari hijriah)'`

### 3.5 `app/Http/Controllers/ZakatController.php` — endpoint `hitung()`

```php
$data = $request->validate([
    'harta'               => ['required', 'numeric', 'min:0'],
    'tanggal_kepemilikan' => ['nullable', 'date'],
]);

$hasil = Setting::hitungZakat((float) $data['harta'], $data['tanggal_kepemilikan'] ?? null);

return response()->json([
    'sukses' => true, 'kena_zakat' => $hasil['kena_zakat'], 'nishab' => $hasil['nishab'],
    'nishab_terpenuhi' => $hasil['nishab_terpenuhi'], 'kadar_persen' => $hasil['kadar_persen'],
    'zakat' => $hasil['zakat'], 'haul_aktif' => $hasil['haul_aktif'],
    'haul_terpenuhi' => $hasil['haul_terpenuhi'], 'haul_hari' => $hasil['haul_hari'],
    'haul_sejak' => $hasil['haul_sejak'], 'hari_kepemilikan' => $hasil['hari_kepemilikan'],
    'pesan' => $this->pesanHisab($hasil),
]);
```

Helper `pesanHisab()` membedakan pesan: (a) wajib → "Harta telah mencapai nishab dan haul, zakat wajib dikeluarkan."; (b) nishab belum tercapai → "Harta belum mencapai nishab, belum wajib zakat."; (c) haul aktif & tanggal kosong → "Syarat haul aktif: isi tanggal kepemilikan harta untuk menilai kewajiban zakat."; (d) haul aktif belum genap → "Harta telah mencapai nishab, namun haul (kepemilikan %d hari) belum genap — belum wajib zakat."; (e) fallback.

### 3.6 `resources/views/zakat/index.blade.php` — panel kalkulator (UAT)

Menambahkan blok `@unless($readOnly)` berisi kartu "🧮 Kalkulator Hisab Zakat Maal" dengan input `harta` & `tanggal_kepemilikan` + `<script>` `hitungZakat()` yang memanggil `route('zakat.hitung')` (POST JSON + CSRF) dan menampilkan nishab, kadar, status haul (bila `data.haul_aktif`), zakat terutang, serta `data.pesan` (kotak hijau bila `kena_zakat`, kuning bila belum). Endpoint `zakat.hitung` yang sebelumnya **yatim** (tak dipanggil UI) kini **hidup**.

### 3.7 Berkas pengujian

- `tests/Feature/ZakatHisabKonfigurasiTest.php` — **+7 test** haul (bagian R-03).

---

## 4. HASIL PENGUJIAN

### 4.1 Unit & Integrasi & UAT

| # | Test | Jenis | Skenario | Hasil |
|---|---|---|---|---|
| 1 | `test_default_haul_nonaktif_dan_panjang_354_hari` | Unit | Default: `zakatHaulAktif()=false`, `zakatHaulHari()=354` | ✅ |
| 2 | `test_haul_nonaktif_hisab_hanya_memeriksa_nishab` | Unit | Haul nonaktif → harta ≥ nishab wajib **tanpa** tanggal (perilaku lama tetap) | ✅ |
| 3 | `test_haul_aktif_kepemilikan_kurang_dari_setahun_belum_wajib` | Unit | **Kriteria penerimaan R-03**: harta ≥ nishab, kepemilikan 100 hari → `kena_zakat=false`, `haul_terpenuhi=false` | ✅ |
| 4 | `test_haul_aktif_kepemilikan_genap_setahun_wajib_zakat` | Unit | Kepemilikan 354 hari → `kena_zakat=true`, zakat 2,5% | ✅ |
| 5 | `test_haul_aktif_tanpa_tanggal_kepemilikan_fail_closed` | Unit | Haul aktif, tanggal kosong → `kena_zakat=false` (fail-closed) | ✅ |
| 6 | `test_panjang_haul_dapat_dikonfigurasi` | Unit | Panjang haul diubah ke 200 hari (nol hardcoding) → hasil ikut berubah | ✅ |
| 7 | `test_endpoint_kalkulator_menegakkan_haul_saat_aktif` | **UAT** | POST `/zakat/hitung` (`tanggal_kepemilikan` 30 hari) → `haul_aktif=true`, `haul_terpenuhi=false`, `kena_zakat=false`, `zakat=0` | ✅ |

### 4.2 Regresi penuh

```
php artisan test
Tests:    1432 passed (6441 assertions)
Duration: 198.11s
```

**Sebelum:** 1425 test (baseline pasca R-01/R-02). **Sesudah:** 1432 test (**+7**). **Regresi:** **0**.

*(Suite terkait zakat: `ZakatHisabKonfigurasiTest` 12 test, `ZakatPsak109Test`, `ZakatDemoSeederTest` — semua hijau. Seluruh modul lain juga hijau.)*

---

## 5. DOKUMENTASI DEPLOYMENT

### 5.1 Berkas yang berubah/ditambah

| Berkas | Aksi | Dampak |
|---|---|---|
| `database/migrations/2026_10_09_000600_add_zakat_haul_parameters_to_kebijakan_koperasis.php` | **Baru** | Migrasi (tambah 2 kolom nullable) |
| `app/Support/Setting.php` | Ubah | Accessor + logika hisab + metadata |
| `app/Models/KebijakanKoperasi.php` | Ubah | fillable/casts/label |
| `app/Http/Controllers/ZakatController.php` | Ubah | Endpoint + pesan hisab |
| `resources/views/zakat/index.blade.php` | Ubah | Panel kalkulator AJAX |
| `tests/Feature/ZakatHisabKonfigurasiTest.php` | Ubah | +7 test (test-only) |

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
- Saklar `zakat_haul_aktif` default **NONAKTIF** → setelah deploy, hisab **berperilaku sama seperti sebelumnya** (nol perubahan tak terduga). Pengurus dapat **mengaktifkan** lewat UI Kebijakan Koperasi.
- **Rollback:** `php artisan migrate:rollback` (menghapus 2 kolom) atau kembalikan commit.
- **Perilaku fail-closed:** saat haul diaktifkan, pastikan operator mengisi `tanggal_kepemilikan` pada kalkulator; tanpa itu sistem **tidak** menyatakan wajib zakat (aman syariah).

### 5.3 Kriteria penerimaan (Definition of Done)

| Kriteria | Status |
|---|---|
| Parameter `zakat_haul_aktif` & `zakat_haul_hari` ada (migrasi + 4 lapisan metadata) | ✅ |
| Field tanggal kepemilikan diterima endpoint & UI | ✅ |
| `hitungZakat()` mengembalikan `haul_terpenuhi` (+ status haul lain) | ✅ |
| Harta ≥ nishab dengan kepemilikan < 1 tahun → `kena_zakat=false` | ✅ |
| Fail-closed saat tanggal kosong & haul aktif | ✅ |
| Panjang haul dapat dikonfigurasi (nol hardcoding) | ✅ |
| Default NONAKTIF (backward compatible) — nol regresi | ✅ |
| Tidak ada regresi (1432 passed) | ✅ |
| Dokumentasi audit diperbarui | ✅ |

---

## 6. STATUS AKHIR

- ✅ **R-03 SELESAI** — syarat **HAUL** kini ditegakkan (opsional, fail-closed). Temuan **Z-04 tertutup**.
- ✅ **0 bug sisa** yang teridentifikasi selama pengujian.
- ✅ **0 regresi** — 1432 test lulus (6441 assertions).
- ✅ Dokumentasi diperbarui: `docs/LAPORAN-AUDIT-ZAKAT.md` (status Z-04 + matriks kesesuaian DSN-MUI 08 haul) + laporan ini.

**Temuan yang MASIH TERBUKA** (di luar lingkup R-03, lihat `LAPORAN-AUDIT-ZAKAT.md`): Z-03 (zakat penghasilan/R-05), Z-05 (konsistensi resolusi akun/R-04), Z-06 (pilihan kas/R-07), Z-07 (master pihak/R-08), Z-08 (porsi amil/R-06), Z-09 (integrasi CaLK/R-09) — direkomendasikan sebagai gelombang berikutnya.

---

*Disusun sebagai bagian dari implementasi rekomendasi audit zakat. Seluruh perubahan diverifikasi melalui `php artisan test` (1432 passed) pada 09 Oktober 2026.*
