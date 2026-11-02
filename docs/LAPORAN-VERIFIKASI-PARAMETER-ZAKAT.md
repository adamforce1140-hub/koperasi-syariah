# Laporan Verifikasi Parameter Zakat & Parameter Terkait

**Sistem:** Koperasi Syariah (Laravel 12 / PHP 8.3 / MySQL 8.4)
**Tanggal verifikasi:** 7 Oktober 2026
**Ruang lingkup:** Seluruh parameter zakat (hisab & operasional) beserta parameter terkait (akun CoA dana zakat/infak, asnaf, kategori) pada modul Zakat/PSAK 109.
**Metode:** Pembacaan kode (static), penelusuran pemakaian (grep), verifikasi runtime (`tinker`), dan eksekusi test (`phpunit`).

---

## 1. Ringkasan Eksekutif

Modul zakat **berfungsi dengan benar secara fungsional** untuk pencatatan penerimaan/penyaluran dana titipan (PSAK 109 & QS At-Taubah 60) dan **perhitungan hisab zakat (kadar 2,5% & nishab 85 gram emas) menghasilkan nilai yang tepat**.

Namun ditemukan **3 masalah nyata** yang membuat sebagian parameter **belum optimal**:

| Kode | Temuan | Tingkat |
|---|---|---|
| **Z-01** | `zakat_minimal_tarik` **terkonfigurasi lengkap tetapi tidak pernah dipakai** kode mana pun → parameter mati | 🔴 Tinggi |
| **Z-02** | 4 parameter hisab zakat **tidak dapat diubah lewat UI** (hanya via DB/model langsung), bertentangan dengan klaim migration "cukup lewat UI" | 🟠 Sedang |
| **Z-03** | **Inkonsistensi resolusi akun CoA zakat** antara `ZakatService` (konstanta hardcoded) dan `LaporanKeuanganSyariahService` (dinamis dari Setting) | 🟠 Sedang |
| **Z-04** | Endpoint kalkulator hisab `zakat.hitung` **berfungsi tetapi yatim** (tidak dipanggil UI/JS) | 🟡 Rendah |

Yang sudah benar (Z-05): hisab zakat, validasi kepatuhan PSAK 109, ketersediaan CoA 2-3002/2-3004, serta default syariah 2,5% / 85 gram.

---

## 2. Inventaris Parameter

Sumber nilai tunggal: tabel `kebijakan_koperasis` (satu baris), diakses melalui `App\Support\Setting`.

| # | Parameter | Kolom DB | Default | Tipe |
|---|---|---|---|---|
| 1 | Kadar zakat maal (%) | `zakat_rate` | 2,50 | decimal(5,2) |
| 2 | Nishab (gram emas) | `zakat_nisab_gram` | 85,00 | decimal(10,2) |
| 3 | Harga emas per gram (Rp) | `harga_emas_per_gram` | 1.500.000,00 | decimal(18,2) |
| 4 | Minimum penyaluran dana zakat (Rp) | `zakat_minimal_tarik` | 10.000,00 | decimal(18,2) |
| 5 | Akun CoA Dana Zakat | `account_dana_zakat_id` | null (= 2-3002) | integer |
| 6 | Akun CoA Dana Infak & Sedekah | `account_dana_infak_id` | null (= 2-3004) | integer |

Parameter terkait (non-kolom, konstanta/model):

| # | Parameter | Lokasi | Nilai |
|---|---|---|---|
| 7 | Daftar 8 asnaf penerima zakat | `ZakatService::ASNAF` | mualaf, riqab, gharim, fisabilillah, ibnu_sabil, dll. |
| 8 | Kategori penerimaan/penyaluran | `ZakatTransaksi::KATEGORI_*` | konstanta |
| 9 | Akun CoA standar | `Coa::DANA_ZAKAT` / `DANA_INFAK_SEDEKAH` | `2-3002` / `2-3004` |

---

## 3. Status Ketersediaan Setiap Parameter

Keterangan kolom: **Terkonfigurasi** = ada kolom DB + default + cast; **Terimplementasi** = dikonsumsi kode; **Berfungsi** = hasil runtime benar; **Via UI** = dapat diubah pengurus lewat antarmuka.

| Parameter | Terkonfigurasi | Terimplementasi | Berfungsi | Via UI | Status |
|---|---|---|---|---|---|
| `zakat_rate` | ✅ | ✅ `Setting::hitungZakat()` | ✅ | ❌ | ⚠️ Sebagian |
| `zakat_nisab_gram` | ✅ | ✅ | ✅ | ❌ | ⚠️ Sebagian |
| `harga_emas_per_gram` | ✅ | ✅ | ✅ | ❌ | ⚠️ Sebagian |
| `zakat_minimal_tarik` | ✅ | ❌ **tidak ada accessor & tidak dipakai** | ❌ | ❌ | 🔴 **Mati** |
| `account_dana_zakat_id` | ✅ | ⚠️ hanya di service laporan | ⚠️ | ❌ | ⚠️ Inkonsisten |
| `account_dana_infak_id` | ✅ | ⚠️ hanya di service laporan | ⚠️ | ❌ | ⚠️ Inkonsisten |
| Daftar 8 asnaf | ✅ (konstanta) | ✅ `salurkan()` | ✅ | ❌ (hardcoded) | ✅ Berfungsi |
| Kategori penerimaan | ✅ (konstanta) | ✅ `terima()` | ✅ | ❌ (hardcoded) | ✅ Berfungsi |

Ringkas: **2 dari 6 parameter zakat berstatus "Mati/Inkonsisten"**, dan **0 dari 6 dapat dikonfigurasi via UI**.

---

## 4. Temuan Rinci

### Z-01 — `zakat_minimal_tarik` terkonfigurasi tetapi TIDAK DIPAKAI 🔴

Parameter "Minimum Penyaluran Dana Zakat (Rp)" lengkap di semua lapisan deklaratif:
- Kolom & komentar: [migration 2026_10_05_000500](file:///c:/laragon/www/koperasi_syariah/database/migrations/2026_10_05_000500_add_zakat_parameters_to_kebijakan_koperasis.php#L55-L60)
- Default & tipe: [Setting.php](file:///c:/laragon/www/koperasi_syariah/app/Support/Setting.php#L169)
- `$fillable`, `$casts`, `LABEL_KOLOM`: [KebijakanKoperasi.php](file:///c:/laragon/www/koperasi_syariah/app/Models/KebijakanKoperasi.php#L101)

Namun:
- **Tidak ada accessor** `Setting::zakatMinimalTarik()` (bandingkan dengan `zakatRate()`, `zakatNisabGram()`, `hargaEmasPerGram()` yang ada).
- **Tidak ada label** di array `DESKRIPSI` Setting (hanya `account_dana_zakat_id`/`account_dana_infak_id` yang punya label zakat).
- **Tidak dikonsumsi** di `ZakatService::salurkan()` ([ZakatService.php](file:///c:/laragon/www/koperasi_syariah/app/Services/ZakatService.php#L180-L247)) — validasi penyaluran hanya memeriksa `jumlah > 0` dan `jumlah <= saldo`, tanpa ambang minimum.

**Dampak:** Pengurus dapat menetapkan batas minimum penyaluran, tetapi sistem mengabaikannya — penyaluran Rp 1 tetap lolos meski minimum diset Rp 10.000.

### Z-02 — Parameter hisab zakat tidak dapat diubah via UI 🟠

Migration menyatakan *"perubahan regulasi/kebijakan cukup lewat UI"*, tetapi:
- `PengurusController::updateKebijakan()` ([PengurusController.php](file:///c:/laragon/www/koperasi_syariah/app/Http/Controllers/Dashboard/PengurusController.php#L752-L1168)) **tidak memuat** keempat field zakat, baik di `$fieldNominal`, blok aturan validasi, maupun blok assignment.
- View [kebijakan.blade.php](file:///c:/laragon/www/koperasi_syariah/resources/views/pengurus/kebijakan.blade.php) **tidak memiliki seksi parameter zakat** (dan tidak memakai variabel `$metadata`/`$parameterUtama` yang dikirim controller — hand-written penuh).
- Bukti: test [ZakatHisabKonfigurasiTest.php](file:///c:/laragon/www/koperasi_syariah/tests/Feature/ZakatHisabKonfigurasiTest.php#L85-L97) mengubah kadar **langsung ke model** (`$kebijakan->zakat_rate = 5; $kebijakan->save();`) — bukan lewat HTTP.

**Dampak:** Perubahan kadar/nishab/harga emas hanya bisa dilakukan developer via DB; pengurus tidak punya jalur resmi (melanggar prinsip *zero-hardcoding* yang dianut sistem).

### Z-03 — Inkonsistensi resolusi akun CoA zakat 🟠

Dua service memakai sumber berbeda untuk akun dana zakat:

| Service | Cara resolusi |
|---|---|
| `ZakatService::akunDana()` ([L#55-60](file:///c:/laragon/www/koperasi_syariah/app/Services/ZakatService.php#L55-L60)) | **Hardcoded** `Coa::DANA_ZAKAT` / `Coa::DANA_INFAK_SEDEKAH` (2-3002 / 2-3004) |
| `LaporanKeuanganSyariahService::akunDit()` ([L#84-101](file:///c:/laragon/www/koperasi_syariah/app/Services/LaporanKeuanganSyariahService.php#L84-L101)) | **Dinamis** — `account_dana_zakat_id` dari Setting, fallback ke 2-3002 |

**Dampak:** Bila `account_dana_zakat_id` diubah ke akun lain, jurnal penerimaan/penyaluran tetap masuk **2-3002**, sedangkan laporan keuangan membaca akun baru → **selisih antara buku besar dan laporan**.

### Z-04 — Endpoint kalkulator hisab yatim 🟡

`ZakatController::hitung()` ([L#88-111](file:///c:/laragon/www/koperasi_syariah/app/Http/Controllers/ZakatController.php#L93-L111)) & route `zakat.hitung` ([web.php#L300](file:///c:/laragon/www/koperasi_syariah/routes/web.php#L300)) berfungsi (diuji), tetapi **tidak dipanggil view/JS mana pun** — tidak ada UI kalkulator di [zakat/index.blade.php](file:///c:/laragon/www/koperasi_syariah/resources/views/zakat/index.blade.php). Fitur hisab tak terjangkau pengguna.

### Z-05 — Yang sudah benar ✅

- Hisab: harta ≥ nishab → kadar 2,5%; di bawah nishab → tidak wajib zakat.
- Validasi kepatuhan modul: `{valid: true, catatan: []}`.
- CoA `2-3002` & `2-3004` tersedia dan aktif.
- Default parameter sesuai syariah (2,50% / 85 gram).
- Pemisahan dana titipan vs pendapatan (tidak masuk SHU) terjaga.

---

## 5. Bukti Runtime & Test

**Nilai kolom DB (aktual):**
```
rate=2.50  nisab_gram=85.00  harga_emas=1500000.00  min_tarik=10000.00
```

**Accessor Setting:**
```
rate=2.5  nisabGram=85  hargaEmas=1500000  nisabNominal=127500000
```

**Hisab:**
```
hitungZakat(130.000.000) = {kena_zakat:true, nishab:127500000, kadar_persen:2.5, zakat:3250000}
hitungZakat(50.000.000)  = {kena_zakat:false, zakat:0}
```

**CoA:** `zakat=2-3002  infak=2-3004`

**Test:**
```
php artisan test tests/Feature/ZakatHisabKonfigurasiTest.php tests/Feature/ZakatPsak109Test.php
→ Tests: 20 passed (88 assertions)
```

Catatan: `zakat_minimal_tarik` bernilai 10000 di DB, **namun tidak ada satu pun baris kode yang membacanya** (hasil grep: hanya migration, Setting DEFAULTS/TIPE, model `$fillable`/`$casts`/`LABEL_KOLOM`).

---

## 6. Rekomendasi Perbaikan

Diurutkan berdasarkan prioritas.

### R-01 (Tinggi) — Konsumsi atau hapus `zakat_minimal_tarik`
Pilih salah satu:
- **Opsi A (fungsionalkan):** Tambah accessor `Setting::zakatMinimalTarik(): float` dan terapkan di `ZakatService::salurkan()`:
  ```php
  $minimum = Setting::zakatMinimalTarik();
  if ($dana === ZakatTransaksi::DANA_ZAKAT && $jumlah < $minimum) {
      throw new \InvalidArgumentException(
          'Nominal penyaluran zakat di bawah minimum Rp '.number_format($minimum, 0, ',', '.').'.'
      );
  }
  ```
  Lengkapi juga label di `Setting::DESKRIPSI`.
- **Opsi B (hapus):** Bila tidak diperlukan, hapus kolom + entri Setting + `$fillable`/`$casts`/`LABEL_KOLOM` agar tidak menjadi parameter menyesatkan.

### R-02 (Sedang) — Sediakan jalur UI parameter zakat
Tambahkan seksi "Parameter Zakat (PSAK 109)" pada form kebijakan:
1. Field `zakat_rate`, `zakat_nisab_gram`, `harga_emas_per_gram`, `zakat_minimal_tarik` di view [kebijakan.blade.php](file:///c:/laragon/www/koperasi_syariah/resources/views/pengurus/kebijakan.blade.php).
2. Aturan validasi (mis. `nullable|numeric|min:0|max:100` untuk rate; `min:0` untuk nominal).
3. Assignment `$kebijakan->zakat_rate = ...;` dst. pada `updateKebijakan()`.
4. (Opsional) Field `account_dana_zakat_id` / `account_dana_infak_id` sebagai dropdown CoA.
Tambahkan test HTTP yang memverifikasi perubahan lewat endpoint `pengurus.kebijakan.update`.

### R-03 (Sedang) — Selaraskan resolusi akun CoA zakat
Ubah `ZakatService::akunDana()` agar membaca Setting dinamis (konsisten dengan `LaporanKeuanganSyariahService`):
```php
public function akunDana(string $dana): string
{
    $settingKey = $dana === ZakatTransaksi::DANA_INFAK_SEDEKAH
        ? 'account_dana_infak_id'
        : 'account_dana_zakat_id';
    return (new LaporanKeuanganSyariahService)->kodeAkunDariSetting($settingKey)
        ?? ($dana === ZakatTransaksi::DANA_INFAK_SEDEKAH ? Coa::DANA_INFAK_SEDEKAH : Coa::DANA_ZAKAT);
}
```
Atau satukan resolusi akun dalam satu helper bersama untuk menghindari duplikasi logika.

### R-04 (Rendah) — Sambungkan kalkulator hisab ke UI
Tambahkan panel kalkulator zakat di halaman zakat yang memanggil `route('zakat.hitung')` via `fetch` (AJAX), sehingga endpoint tidak lagi yatim. Bila tidak diperlukan, pertimbangkan menonaktifkan endpoint.

### R-05 (Kepatuhan — opsional) — Parameter haul
Belum ada parameter `zakat_haul` (periode kepemilikan 1 tahun hijriah). Bila koperasi ingin hisab zakat yang lengkap, tambahkan parameter haul & integrasikan ke `hitungZakat()`. (Saat ini `hitungZakat()` hanya mempertimbangkan nishab, tanpa haul.)

---

## 7. Kesimpulan

- **Konfigurasi:** Seluruh 6 parameter zakat **ada** di DB dengan default benar.
- **Implementasi:** 3 dari 6 (hisab) **terimplementasi & berfungsi**; 1 (`zakat_minimal_tarik`) **mati**; 2 (akun CoA) **dipakai tidak konsisten**.
- **Optimalitas:** Belum optimal — parameter tidak dapat dikonfigurasi via UI dan terdapat inkonsistensi yang berpotensi menimbulkan selisih laporan.
- **Tindak lanjut minimal:** R-01 (wajib) dan R-03 (wajib untuk konsistensi akuntansi); R-02 & R-04 untuk melengkapi pengalaman pengguna.

---

## 8. Lampiran — File Terkait

| File | Peran |
|---|---|
| `database/migrations/2026_10_05_000500_add_zakat_parameters_to_kebijakan_koperasis.php` | Definisi 4 kolom parameter zakat |
| `app/Support/Setting.php` | Default, tipe, label, accessor hisab |
| `app/Services/ZakatService.php` | Logika terima/salurkan, validasi, CoA dana |
| `app/Services/LaporanKeuanganSyariahService.php` | Resolusi CoA dana dinamis |
| `app/Http/Controllers/ZakatController.php` | index/store/hitung/salurkan |
| `app/Http/Controllers/Dashboard/PengurusController.php` | `updateKebijakan()` (tanpa zakat) |
| `app/Models/KebijakanKoperasi.php` | fillable/casts/LABEL_KOLOM zakat |
| `resources/views/pengurus/kebijakan.blade.php` | Form kebijakan (tanpa seksi zakat) |
| `resources/views/zakat/index.blade.php` | Buku besar zakat (tanpa kalkulator) |
| `tests/Feature/ZakatHisabKonfigurasiTest.php` | Test hisab & konfigurasi |
| `tests/Feature/ZakatPsak109Test.php` | Test kepatuhan PSAK 109 |
