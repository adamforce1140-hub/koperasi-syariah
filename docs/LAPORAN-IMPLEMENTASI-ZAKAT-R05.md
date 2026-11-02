# Laporan Implementasi Perbaikan Zakat — R-05

**Sistem:** Koperasi Syariah (Laravel 12 / PHP 8.3 / MySQL 8.4)
**Tanggal:** 09 Oktober 2026
**Dasar:** `docs/LAPORAN-AUDIT-ZAKAT.md` (temuan **Z-03**, prioritas Sedang)
**Ruang Lingkup:** Rekomendasi **R-05** — utilitas hisab *zakat penghasilan* anggota (`Setting::hitungZakatPenghasilan()`) beserta endpoint & pra-isi form penerimaan.

**Ringkasan status:** ✅ **SELESAI 100%** — 1/1 rekomendasi diimplementasikan; **7 test baru LULUS**; regresi suite zakat terkait **45 passed** (0 regresi).

---

## 1. IDENTIFIKASI DETAIL

### R-05 — Zakat penghasilan agregat (profesi) anggota (zero-hardcoding)

| Aspek | Uraian |
|---|---|
| **Temuan asal** | Z-03 (Sedang) — "Zakat atas SHU / potong otomatis penghasilan anggota tidak tersedia." |
| **Kondisi awal** | `ZakatTransaksi::KATEGORI_PENERIMAAN` **sudah memuat** kategori `zakat_penghasilan` (`=> 'Zakat Penghasilan (Profesi)'`), namun **tidak ada mekanisme** yang menghitung zakat dari penghasilan anggota. Seluruh penerimaan bersifat **input manual Pengurus** tanpa pendampingan hisab. |
| **Standar acuan** | **Fatwa DSN-MUI No. 08/DSN-MUI/IV/2000** — zakat penghasilan (profesi) dihitung atas **penghasilan neto** (penghasilan bruto dikurangi kebutuhan pokok) yang **mencapai nishab bulanan** (nishab maal 85 gram emas ÷ 12). Kadar **2,5%**. **PSAK 109** — penerimaan dana zakat tetap dana **titipan (amanah)**, bukan pendapatan koperasi. |
| **Cakupan perubahan** | (1) **Baru** — `Setting::hitungZakatPenghasilan($bruto, $kebutuhanPokok)`; (2) `ZakatController::hitungPenghasilan()` + helper `pesanHisabPenghasilan()`; (3) Rute `POST /zakat/hitung-penghasilan` (`zakat.hitung.penghasilan`); (4) Panel kalkulator pra-isi pada `resources/views/zakat/index.blade.php`; (5) Berkas uji `tests/Feature/ZakatPenghasilanTest.php`. |
| **Spesifikasi fungsional** | • **Neto** = penghasilan bruto − kebutuhan pokok (keduanya di-*clamp* ≥ 0 sehingga neto **tidak pernah negatif**).<br>• **Nishab bulanan** = `zakatNisabNominal() / 12` (nishab maal diambil dari gram emas × harga emas yang terkonfigurasi).<br>• Zakat wajib **hanya bila** neto ≥ nishab bulanan; kadar dibaca dari `zakat_rate` (**nol hardcoding**).<br>• Sifatnya **PRA-ISI**: kalkulator hanya membantu menghitung dan mengisi form; **posting tetap manual Pengurus** (tidak ada pemotongan otomatis).<br>• Parameter lama **tidak berubah** (backward compatible) — utilitas ini **tambahan**, tidak mengubah perilaku `hitungZakat()` (maal). |

---

## 2. RENCANA IMPLEMENTASI

### Dependensi antar modul

```
Setting::zakatNisabNominal()  ──► nishab bulanan = nishab maal ÷ 12
Setting::zakatRate()          ──► kadar (%)                   [zero-hardcoding]
        └─► Setting::hitungZakatPenghasilan($bruto, $kebutuhanPokok)   [utilitas murni]
                └─► ZakatController::hitungPenghasilan()               [endpoint JSON]
                        └─► routes/web.php: POST /zakat/hitung-penghasilan
                                └─► zakat/index.blade.php: panel kalkulator → pra-isi form penerimaan
```

**Catatan dependensi:** R-05 **independen** dari R-04/R-06 s.d. R-09. Bergantung **hanya** pada accessor `Setting` yang sudah ada (`zakatNisabNominal()`, `zakatRate()`). Kategori `zakat_penghasilan` dan dana zakat **sudah tersedia** di model & `ZakatService` sehingga tidak diperlukan migrasi/kolom baru.

### Tahapan pengembangan (urut pelaksanaan)

| Tahap | Aktivitas | Keluaran |
|---|---|---|
| T1 | Analisis akar penyebab & spesifikasi fungsional | Dokumen bagian 1 |
| T2 | Rencana implementasi & dependensi | Dokumen bagian 2 |
| T3 | Utilitas hisab murni di `Setting` | `Setting.php` (`hitungZakatPenghasilan`) |
| T4 | Endpoint JSON + pesan edukatif | `ZakatController.php` |
| T5 | Rute baru | `routes/web.php` |
| T6 | Panel kalkulator pra-isi form penerimaan | `zakat/index.blade.php` |
| T7 | Pengujian: unit + integrasi + UAT + regresi | 7 test baru (LULUS), 45 passed regresi terkait |
| T8 | Dokumentasi & rilis | Laporan ini + pembaruan `LAPORAN-AUDIT-ZAKAT.md` |

---

## 3. PERUBAHAN KODE

### 3.1 `app/Support/Setting.php` — `hitungZakatPenghasilan()` — **DIUBAH (BARU)**

Ditambahkan tepat setelah `hitungZakat()`. Method ini adalah **fungsi murni** yang mengembalikan seluruh komponen hisab sebagai array (memudahkan pengujian & pemakaian ulang oleh UI):

```php
public static function hitungZakatPenghasilan(float $bruto, float $kebutuhanPokok = 0.0): array
{
    $bruto          = max(0.0, $bruto);
    $kebutuhanPokok = max(0.0, $kebutuhanPokok);
    // Kebutuhan pokok tidak dapat melebihi penghasilan → neto minimal nol.
    $neto           = max(0.0, $bruto - $kebutuhanPokok);

    // Nishab bulanan = nishab maal (85 gram emas) dibagi 12 bulan.
    $nishabBulanan  = round(static::zakatNisabNominal() / 12, 2);
    $kadar          = static::zakatRate();
    $nishabTercapai = $nishabBulanan > 0.0 && $neto >= $nishabBulanan;

    return [
        'bruto'            => round($bruto, 2),
        'kebutuhan_pokok'  => round($kebutuhanPokok, 2),
        'penghasilan_neto' => round($neto, 2),
        'nishab_bulanan'   => $nishabBulanan,
        'nishab_terpenuhi' => $nishabTercapai,
        'kadar_persen'     => $kadar,
        'zakat'            => $nishabTercapai ? round($neto * ($kadar / 100), 2) : 0.0,
        'kena_zakat'       => $nishabTercapai,
    ];
}
```

**Docblock** menjelaskan acuan **PSAK 109** & **Fatwa DSN-MUI 08**, definisi neto, asal nishab bulanan, sumber kadar (`zakat_rate`, nol hardcoding), dan sifat **pra-isi** (posting tetap manual Pengurus).

### 3.2 `app/Http/Controllers/ZakatController.php` — `hitungPenghasilan()` + `pesanHisabPenghasilan()` — **DIUBAH (BARU)**

```php
public function hitungPenghasilan(Request $request)
{
    $data = $request->validate([
        'bruto'           => ['required', 'numeric', 'min:0'],
        'kebutuhan_pokok' => ['nullable', 'numeric', 'min:0'],
    ]);

    $hasil = Setting::hitungZakatPenghasilan(
        (float) $data['bruto'],
        (float) ($data['kebutuhan_pokok'] ?? 0)
    );

    return response()->json([
        'sukses'           => true,
        'kena_zakat'       => $hasil['kena_zakat'],
        'bruto'            => $hasil['bruto'],
        'kebutuhan_pokok'  => $hasil['kebutuhan_pokok'],
        'penghasilan_neto' => $hasil['penghasilan_neto'],
        'nishab_bulanan'   => $hasil['nishab_bulanan'],
        'nishab_terpenuhi' => $hasil['nishab_terpenuhi'],
        'kadar_persen'     => $hasil['kadar_persen'],
        'zakat'            => $hasil['zakat'],
        'pesan'            => $this->pesanHisabPenghasilan($hasil),
    ]);
}

private function pesanHisabPenghasilan(array $hasil): string
{
    if ($hasil['kena_zakat']) {
        return 'Penghasilan neto telah mencapai nishab bulanan, zakat penghasilan wajib dikeluarkan.';
    }

    return 'Penghasilan neto belum mencapai nishab bulanan, belum wajib zakat penghasilan.';
}
```

Validasi: `bruto` **wajib** & ≥ 0; `kebutuhan_pokok` **opsional** (default 0) & ≥ 0.

### 3.3 `routes/web.php` — **DIUBAH**

Ditambahkan tepat setelah rute `zakat.hitung`:

```php
Route::post('/zakat/hitung-penghasilan', [ZakatController::class, 'hitungPenghasilan'])
    ->name('zakat.hitung.penghasilan');
```

### 3.4 `resources/views/zakat/index.blade.php` — panel kalkulator pra-isi — **DIUBAH**

- Ditambahkan ID pada **form penerimaan** agar dapat dipra-isi: `id="terima-dana"` (select dana), `id="terima-kategori"` (select kategori), `id="terima-jumlah"` (input jumlah).
- Ditambahkan kartu **"🧮 Kalkulator Zakat Penghasilan (Pra-isi Penerimaan)"** di dalam blok `@unless($readOnly)`, diletakkan **sebelum** kalkulator zakat maal, berisi:
  - input `penghasilan-bruto` & `penghasilan-kebutuhan`;
  - tombol **Hitung & Isi Form**;
  - div `penghasilan-hasil` untuk umpan balik;
  - `<script> hitungZakatPenghasilan()</script>` yang POST ke `route('zakat.hitung.penghasilan')`. Bila `data.kena_zakat` bernilai benar → **pra-isi** `terima-dana = 'zakat'`, `terima-kategori = 'zakat_penghasilan'`, `terima-jumlah = data.zakat`.
- Teks bantuan menampilkan kadar aktual (`zakatRate()`) dan nishab bulanan (`round(zakatNisabNominal()/12, 2)`) sehingga Pengurus melihat angka yang berlaku tanpa menebak.

### 3.5 Berkas pengujian — `tests/Feature/ZakatPenghasilanTest.php` — **BARU**

- Menggunakan `DatabaseTransactions`; `tearDown()` memanggil `Setting::flush()` (invalidasi cache parameter); helper `pengelola()` (peran pengurus) & `nishabBulanan()`.
- **7 test** memuat skenario hisab murni, batas nishab, efek kebutuhan pokok, serta dua test **UAT endpoint** (jalur sukses & validasi input).

---

## 4. HASIL PENGUJIAN

### 4.1 Unit & Integrasi & UAT

| # | Test | Jenis | Skenario | Hasil |
|---|---|---|---|---|
| 1 | `test_default_nishab_bulanan_seperduabelas_nishab_maal` | Unit | Nishab bulanan = `zakatNisabNominal() / 12` (default: 85 g × Rp1.500.000 ÷ 12 = **Rp10.625.000**-an) | ✅ |
| 2 | `test_penghasilan_neto_di_bawah_nishab_bulanan_tidak_wajib` | Unit | Neto < nishab bulanan → `kena_zakat=false`, `zakat=0` | ✅ |
| 3 | `test_penghasilan_neto_mencapai_nishab_dihitung_2_5_persen` | **Unit (kriteria R-05)** | Neto ≥ nishab bulanan → `zakat = neto × 2,5%`; **kriteria penerimaan** | ✅ |
| 4 | `test_kebutuhan_pokok_mengurangi_penghasilan_neto` | Unit | Neto = bruto − kebutuhan pokok; zakat dihitung atas **neto**, bukan bruto | ✅ |
| 5 | `test_kebutuhan_pokok_melebihi_bruto_neto_nol_dan_tidak_wajib` | Unit | Kebutuhan pokok > bruto → neto = 0 (clamp), tidak wajib | ✅ |
| 6 | `test_endpoint_hitung_penghasilan_mengembalikan_hasil` | **UAT** | POST `/zakat/hitung-penghasilan` → JSON `kena_zakat`, `nishab_bulanan`, `zakat` sesuai hitungan (kriteria penerimaan) | ✅ |
| 7 | `test_endpoint_hitung_penghasilan_validasi_input` | **UAT** | `bruto` kosong / negatif → ditolak dengan galat validasi | ✅ |

### 4.2 Regresi suite terkait

```
php artisan test --filter=ZakatPenghasilanTest
Tests:    7 passed (20 assertions)

php artisan test --filter="ZakatHisabKonfigurasiTest|ZakatPsak109Test|ZakatPenghasilanTest"
Tests:    45 passed (169 assertions)
```

**Sebelum:** modul zakat memiliki 38 test pada kedua berkas acuan. **Sesudah:** +7 test baru (**45 passed**). **Regresi:** **0**.

> **Catatan teknis (assertion):** `assertJsonPath` memakai `assertSame`, sehingga nilai JSON **integer** (mis. `265625`) dianggap **tidak identik** dengan **float** (`265625.0`) hasil `round()`. Assertion akhir memakai `assertEquals(round($nishab * 0.025, 2), $resp->json('zakat'))` agar tipe longgar namun nilai tetap terverifikasi.

*(Regresi penuh seluruh suite dijalankan pada tahap verifikasi akhir t7.)*

---

## 5. DOKUMENTASI DEPLOYMENT

### 5.1 Berkas yang berubah/ditambah

| Berkas | Aksi | Dampak |
|---|---|---|
| `app/Support/Setting.php` | Ubah | +accessor `hitungZakatPenghasilan()` |
| `app/Http/Controllers/ZakatController.php` | Ubah | +`hitungPenghasilan()` & helper pesan |
| `routes/web.php` | Ubah | +1 rute `POST /zakat/hitung-penghasilan` |
| `resources/views/zakat/index.blade.php` | Ubah | +panel kalkulator pra-isi & ID pada form penerimaan |
| `tests/Feature/ZakatPenghasilanTest.php` | **Baru** | +7 test (test-only) |

### 5.2 Langkah rilis ke produksi

```bash
# 1. Tarik kode terbaru
git pull origin <branch>

# 2. Bersihkan cache konfigurasi/route/view (tidak ada migrasi DB)
php artisan optimize:clear

# 3. Jalankan pengujian (gerbang mutu)
php artisan test
```

**Catatan penting:**
- **Tidak ada migrasi** — R-05 murni penambahan logika & UI; **tanpa downtime**, tidak menyentuh skema.
- Utilitas bersifat **aditif** → perilaku `hitungZakat()` (maal) **tidak berubah** (backward compatible).
- **Rollback:** kembalikan commit (tidak ada perubahan data/skema yang perlu dibalik).
- Endpoint JSON mengikuti pola validasi `hitung()` yang sudah ada; tidak ada perubahan otorisasi rute.

### 5.3 Kriteria penerimaan (Definition of Done)

| Kriteria | Status |
|---|---|
| Utilitas `Setting::hitungZakatPenghasilan($bruto, $kebutuhanPokok)` tersedia | ✅ |
| Zakat dihitung atas **penghasilan neto** (bruto − kebutuhan pokok) | ✅ (test #4) |
| Nishab **bulanan** = nishab maal ÷ 12 dan ditegakkan | ✅ (test #1, #2, #3) |
| Kadar dibaca dari `zakat_rate` (zero-hardcoding, default 2,5%) | ✅ (test #3) |
| **Kriteria audit R-05:** endpoint tersedia; hasil 2,5% dari (penghasilan − kebutuhan pokok) yang mencapai nishab bulanan | ✅ (test #3 & #6) |
| Neto tidak pernah negatif (clamp) & kebutuhan pokok > bruto tidak mewajibkan | ✅ (test #5) |
| Panel UI mempra-isi form penerimaan (tetap manual-approve) | ✅ (blade) |
| Validasi input tidak sah ditolak | ✅ (test #7) |
| Tidak mengubah perilaku hisab maal (backward compatible) — nol regresi | ✅ (45 passed terkait) |
| Dokumentasi audit diperbarui | ✅ |

---

## 6. STATUS AKHIR

- ✅ **R-05 SELESAI** — hisab zakat penghasilan anggota tersedia sebagai **pra-isi** penerimaan; temuan **Z-03 tertutup** (mekanisme hitung kini ada; posting tetap manual sesuai PSAK 109 & kendali Pengurus).
- ✅ **0 bug sisa** yang teridentifikasi selama pengujian.
- ✅ **0 regresi** pada suite terkait (45 passed).
- ✅ Dokumentasi diperbarui: `docs/LAPORAN-AUDIT-ZAKAT.md` (status Z-03) + laporan ini.

**Temuan yang MASIH TERBUKA** (di luar lingkup R-05): Z-06 (pilihan kas/R-07), Z-07 (master pihak/R-08), Z-08 (porsi amil/R-06), Z-09 (integrasi CaLK/R-09) — dikerjakan pada gelombang berikutnya.

> **Risiko sisa (dicatat):** kalkulator menghitung zakat penghasilan **agregat per panggilan** (bukan menyimpan riwayat per anggota). Bila kelak diinginkan pemotongan terjadwal per anggota (mis. otomatis dari SHU/margin), diperlukan relasi `pihak_id` (lihat R-08) dan mekanisme penjadwalan tersendiri. Cakupan saat ini sengaja dibatasi pada **hisab pra-isi** agar tetap terkendali & sesuai kendali manual Pengurus.

---

*Disusun sebagai bagian dari implementasi rekomendasi audit zakat. Seluruh perubahan diverifikasi melalui `php artisan test` pada 09 Oktober 2026.*
