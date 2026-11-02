# Laporan Implementasi Perbaikan Zakat — R-09

**Sistem:** Koperasi Syariah (Laravel 12 / PHP 8.3 / MySQL 8.4)
**Tanggal:** 09 Oktober 2026
**Dasar:** `docs/LAPORAN-AUDIT-ZAKAT.md` (temuan **Z-09**, prioritas Rendah)
**Ruang Lingkup:** Rekomendasi **R-09** — menyertakan laporan sumber & penggunaan dana zakat di dalam bundel laporan keuangan syariah (CaLK PSAK 101), tidak lagi hanya di halaman modul.

**Ringkasan status:** ✅ **SELESAI 100%** — 1/1 rekomendasi diimplementasikan; **10 test baru LULUS** (33 assertions); regresi laporan keuangan **76 test lulus**; regresi modul Zakat **79 test lulus**; regresi suite penuh **1474 test lulus** (0 regresi).

---

## 1. IDENTIFIKASI DETAIL

### R-09 — Keabsahan/kelengkapan laporan syariah terpisah

| Aspek | Uraian |
|---|---|
| **Temuan asal** | Z-09 (Rendah) — "Keabsahan/kelengkapan laporan syariah terpisah." |
| **Kondisi awal** | Laporan sumber & penggunaan dana zakat hanya tersedia di halaman modul (`zakat.laporan`) dan sebagai **satu baris** komponen DIT (`perubahanDanaInvestasiTerikat()`). Bundel laporan keuangan syariah (CaLK) **tidak** memuat rincian penerimaan per jenis zakat, penyaluran per asnaf, maupun rekonsiliasi hak amil. |
| **Dampak** | (1) Transparansi kepada pemangku kepentingan eksternal (DPS, pembina, pengawas) terbatas; (2) pengungkapan PSAK 109 tentang dana titipan tidak lengkap di dalam satu bundel laporan; (3) DPS tidak dapat menilai kepatuhan Fatwa DSN-MUI No. 08 (batas amil 1/8) dari laporan keuangan saja. |
| **Standar acuan** | **PSAK 109** — dana zakat adalah dana titipan (amanah), bukan pendapatan, disajikan terpisah dan tidak memengaruhi SHU. **PSAK 101** — CaLK sebagai bagian tak terpisahkan laporan keuangan. **QS At-Taubah 60** — 8 asnaf. **Fatwa DSN-MUI No. 08/DSN-MUI/IV/2000** — porsi amil maksimal 1/8 (12,5%). |
| **Cakupan perubahan** | (1) `app/Services/LaporanKeuanganSyariahService.php` — import `ZakatTransaksi`, method baru `laporanZakat()`, key `'zakat'` pada `semuaKomponen()`; (2) `resources/views/laporan/psak101.blade.php` — sub-bagian **6.g** (4 tabel); (3) `resources/views/laporan/psak101-excel.blade.php` — sub-bagian **6.g**; (4) `tests/Feature/LaporanPsak101Test.php` — tambah kunci `'zakat'`; (5) `tests/Feature/ZakatLaporanCalkTest.php` — **baru**, 10 test. |
| **Spesifikasi fungsional** | • **Jurnal sebagai sumber kebenaran** — saldo awal/akhir & mutasi diambil dari jurnal (`Coa::totalKredit/totalDebet` + `mutasiAkun()`), konsisten dengan komponen DIT, sehingga CaLK tidak mungkin menyajikan angka berbeda untuk baris Dana Zakat yang sama.<br>• **Rincian dari buku besar modul** — penerimaan per jenis zakat & penyaluran per asnaf diambil dari `zakat_transaksis` (tidak dapat diturunkan dari jurnal karena jurnal memuat akun, bukan kategori mustahik).<br>• **Selisih diekspos, bukan ditutup** — perbedaan jurnal vs buku besar modul dihitung dan diuji terhadap `Setting::toleransiSelisihJurnal()`, lalu disajikan sebagai temuan CaLK.<br>• **Zero hardcoding** — porsi amil & toleransi dibaca dari `Setting`; kode akun dari `CoaResolver` melalui `akunDit()`.<br>• **P4 Default Aman** — kunci baru bersifat aditif; tidak ada perubahan perilaku komponen lama. |

---

## 2. RENCANA IMPLEMENTASI

### Dependensi antar modul

```
Setting (zakat_porsi_amil_persen, toleransi_selisih_jurnal)
   │  dibaca, bukan di-hardcode
   ▼
CoaResolver ──► akunDit()['dana_zakat']         (Coa 2-3002)
   │                     │
   │                     ├──► Coa::totalKredit/totalDebet  → saldo awal & akhir
   │                     └──► mutasiAkun()                 → penerimaan & penyaluran
   ▼
ZakatTransaksi (zakat_transaksis, dana = 'zakat')
   │  SUM(jumlah) GROUP BY kategori  → rincian 4 jenis zakat & 8 asnaf
   ▼
LaporanKeuanganSyariahService::laporanZakat($periode)
   │
   ▼
semuaKomponen()['zakat']
   │
   ├──► laporan/psak101.blade.php        (sub-bagian 6.g)
   └──► laporan/psak101-excel.blade.php  (sub-bagian 6.g)
```

**Interaksi dengan komponen DIT:** `laporanZakat()` & `perubahanDanaInvestasiTerikat()` membaca **akun & mutasi jurnal yang sama** melalui `akunDit()`, sehingga tidak ada divergensi saldo (menutup risiko Z-05 pada konteks baru).

### Milestone

| # | Milestone | Status |
|---|---|---|
| M1 | Konteks: sumber angka, titik integrasi, pola view & test | ✅ |
| M2 | Service: `laporanZakat()` (jurnal + buku besar modul + rekonsiliasi) | ✅ |
| M3 | Integrasi `semuaKomponen()['zakat']` | ✅ |
| M4 | View HTML: sub-bagian 6.g + guard akun tidak tersedia | ✅ |
| M5 | View Excel: sub-bagian 6.g | ✅ |
| M6 | Berkas uji (10 test) → hijau | ✅ |
| M7 | Regresi laporan, modul Zakat, suite penuh | ✅ |
| M8 | Dokumentasi laporan ini + mutakhirkan audit | ✅ |

---

## 3. PERUBAHAN BERKAS

| # | Berkas | Jenis | Pokok perubahan |
|---|---|---|---|
| 1 | `app/Services/LaporanKeuanganSyariahService.php` | Ubah | Import `App\Models\ZakatTransaksi`; method baru `laporanZakat(array $periode): array` (baris ±1135–1305); key `'zakat' => $this->laporanZakat($periode)` pada `semuaKomponen()`. |
| 2 | `resources/views/laporan/psak101.blade.php` | Ubah | Deklarasi `$zkt = $komponen['zakat'];`; sub-bagian **6.g** dengan 4 tabel (sumber/penggunaan, hak amil, rekonsiliasi) + guard temuan. |
| 3 | `resources/views/laporan/psak101-excel.blade.php` | Ubah | Deklarasi `$zkt`; sub-bagian **6.g** (5 blok tabel) setelah 6.f. |
| 4 | `tests/Feature/LaporanPsak101Test.php` | Ubah | Tambah `'zakat'` pada daftar kunci `test_service_mengembalikan_seluruh_komponen_yang_dibutuhkan_view`. |
| 5 | `tests/Feature/ZakatLaporanCalkTest.php` | **Baru** | 10 test / 33 assertions (lihat §5). |

> **Catatan:** Controller `LaporanKeuanganSyariahController` **tidak** diubah — komponen baru tersalur otomatis melalui `semuaKomponen()`, dan RBAC `laporan_psak101` yang ada sudah mencakup penyajian baru ini.

---

## 4. IMPLEMENTASI KUNCI

### 4.1 Service — `laporanZakat()` (`app/Services/LaporanKeuanganSyariahService.php`)

```php
$kode = $this->akunDit()['dana_zakat'] ?? Akun::DANA_ZAKAT;   // CoaResolver, sama dgn DIT
$coa  = Coa::findByKode($kode);

// (1) Saldo & mutasi dari JURNAL.
$saldoAwal  = round((float) $coa->totalKredit($hariSebelum) - (float) $coa->totalDebet($hariSebelum), 2);
$mutasi     = $this->mutasiAkun($kode, $dari, $sampai);
$penerimaan = $mutasi['kredit'];
$penyaluran = $mutasi['debet'];
$saldoAkhir = round((float) $coa->totalKredit($sampai) - (float) $coa->totalDebet($sampai), 2);

// (2) Rincian dari buku besar modul — dikunci ke DANA_ZAKAT.
$perKategori = /* SUM(jumlah) GROUP BY kategori WHERE jenis = penerimaan */;
$perAsnaf    = /* SUM(jumlah) GROUP BY kategori WHERE jenis = penyaluran  */;

// (3) Rekonsiliasi hak amil — parameter kebijakan, bukan angka tetap.
$hakAmil = [
    'persen'    => Setting::zakatPorsiAmilPersen(),
    'dasar'     => $penerimaan,
    'hak'       => Setting::zakatHakAmil($penerimaan),
    'realisasi' => round((float) ($perAsnaf['amil'] ?? 0), 2),
];
```

**Mengapa rincian tidak diambil dari jurnal:** jurnal hanya memuat akun CoA (mis. `2-3002`), bukan kategori mustahik (`fakir`, `miskin`, …) maupun jenis zakat (`zakat_maal`, …). Rincian itu hanya ada di buku besar modul. Agar tidak ada angka yang "dikarang", **selisih antara kedua sumber dihitung terbuka** dan disajikan sebagai temuan.

### 4.2 Kategori di luar daftar baku tetap disajikan

```php
$sisaPenerimaan = round($totalPenerimaan - round((float) $rincianPenerimaan->sum('jumlah'), 2), 2);

if ($sisaPenerimaan !== 0.0) {
    $rincianPenerimaan->push([
        'kode'   => '__lainnya',
        'label'  => 'Kategori lain di luar daftar baku',
        'jumlah' => $sisaPenerimaan,
    ]);
}
```

Angka yang tak dikenal **lebih berbahaya daripada angka yang tidak rapi** — dibiarkan tampil sebagai baris "Kategori lain" dengan badge peringatan agar selisih tetap terlihat oleh pembaca laporan, bukan disembunyikan.

### 4.3 View HTML — tabel rekonsiliasi (`psak101.blade.php`, 6.g.4)

```blade
@if(! $zkt['akunTersedia'])
<div class="alert alert-danger py-2 small mb-0">
    Akun DIT Dana Zakat <code>{{ $zkt['akun'] }}</code> belum ada pada CoA.
    Laporan ini tidak dapat direkonsiliasi sampai akun tersebut terdaftar.
</div>
@elseif(! $zkt['kontrol']['selaras'] || $zkt['kontrol']['tanpaJurnal'] > 0 || $zkt['kontrol']['asnafDiLuar'] > 0)
<div class="alert alert-warning py-2 small mb-0">
    <strong>Temuan rekonsiliasi zakat:</strong> …
</div>
@endif
```

Guard `akunTersedia` mencegah laporan "diam-diam nol" bila akun CoA terhapus — kondisi tersebut diperlakukan sebagai **temuan**, bukan angka normal.

---

## 5. PENGUJIAN

**Berkas:** `tests/Feature/ZakatLaporanCalkTest.php` (`DatabaseTransactions`; `Setting::flush()` pada `tearDown()`; helper `sebagai()`, `layanan()`, `komponen()`, `jurnal()`, `barisZakat()` yang membuat jurnal seimbang + baris buku besar terhubung).

| # | Test | Skenario | Hasil |
|---|---|---|---|
| 1 | `test_komponen_zakat_tersedia_lengkap` | 13 kunci wajib ada pada `$komponen['zakat']`. | ✅ |
| 2 | `test_komponen_zakat_memakai_akun_yang_sama_dengan_dit` | Akun CaLK = akun Dana Zakat pada komponen DIT (anti-divergensi Z-05). | ✅ |
| 3 | `test_balasan_saldo_mutasi_konsisten` | `saldoAwal + penerimaan − penyaluran = saldoAkhir`; `mutasiSeimbang = true`. | ✅ |
| 4 | `test_rincian_penerimaan_mengikuti_jenis_zakat` | +Rp1.000.000 pada satu jenis zakat → baris rincian naik tepat. | ✅ |
| 5 | `test_rincian_penyaluran_mengikuti_asnaf` | +Rp400.000 pada asnaf `fakir` → baris rincian naik tepat. | ✅ |
| 6 | `test_baris_tanpa_jurnal_terdeteksi_sebagai_temuan` | Baris `jurnal_id = NULL` → `tanpaJurnal` +1 & `selaras = false`. | ✅ |
| 7 | `test_penyaluran_di_luar_asnaf_terdeteksi` | Kategori `bantuan_pendidikan` → `asnafDiLuar` +1. | ✅ |
| 8 | `test_hak_amil_mengikuti_parameter_kebijakan` | `persen` = `Setting::zakatPorsiAmilPersen()`, `≤ 12,5%`, `hak` = `zakatHakAmil(penerimaan)`, `sisa = hak − realisasi`. | ✅ |
| 9 | `test_halaman_menyajikan_sub_bagian_calk_zakat` | HTML memuat "6.g Laporan Sumber" & "Dana Zakat". | ✅ |
| 10 | `test_ekspor_excel_memuat_sub_bagian_calk_zakat` | Ekspor memuat "6.g Laporan Sumber" & "6.g.3 Rekonsiliasi Hak Amil". | ✅ |

**Hasil:** `Tests: 10 passed (33 assertions)`.

### Regresi

| Suite | Hasil |
|---|---|
| `php artisan test tests/Feature/ZakatLaporanCalkTest.php` | **10 passed** (33 assertions) |
| `php artisan test tests/Feature/LaporanPsak101Test.php tests/Feature/LaporanKeuanganSyariahTest.php tests/Feature/LaporanKeuanganRbacTest.php` | **76 passed** (386 assertions) |
| `php artisan test --filter=Zakat` | **79 passed** (281 assertions) |
| `php artisan test` (suite penuh) | **1474 passed** (6582 assertions), durasi 199,14 s |

**0 regresi.** Suite penuh naik dari 1464 (setelah R-08) → 1474 (setelah R-09) = +10 test baru, tanpa test yang hilang.

---

## 6. VERIFIKASI KRITERIA KEBERHASILAN

Kriteria audit R-09: **"Sertakan laporan sumber & penggunaan dana zakat dalam bundel laporan keuangan syariah / CaLK."**

| Kriteria | Bukti | Status |
|---|---|---|
| **Laporan zakat tersedia di bundel laporan keuangan syariah** | Kunci `'zakat'` pada `semuaKomponen()`; sub-bagian **6.g** pada CaLK HTML & Excel | ✅ |
| **Sumber & penggunaan dana disajikan** | Tabel 6.g.1 (per jenis zakat) & 6.g.2 (per asnaf) dengan total | ✅ |
| **Angka konsisten dengan komponen DIT (jurnal)** | Test #2 & #3; `akunDit()` dipakai bersama | ✅ |
| **Rincian benar dari buku besar modul** | Test #4 & #5 — delta rincian tepat sesuai transaksi baru | ✅ |
| **Selisih diekspos sebagai temuan, bukan ditutup** | Test #6 & #7 — `tanpaJurnal`, `asnafDiLuar`, `selaras = false` | ✅ |
| **Kepatuhan amil (Fatwa DSN-MUI 08) dapat diaudit dari laporan** | Test #8 — porsi dari parameter, dibatasi ≤ 12,5%, hak & sisa dihitung | ✅ |
| **Tersedia pada ekspor Excel** | Test #10 — "6.g Laporan Sumber" & "6.g.3 Rekonsiliasi Hak Amil" | ✅ |
| **Zero hardcoding & dependensi terpenuhi** | Porsi amil & toleransi dari `Setting`; akun dari `CoaResolver`; kategori dari konstanta model | ✅ |
| **P4 Default Aman / backward compatible** | Kunci aditif; komponen lama tidak berubah (regresi 76 passed) | ✅ |
| **RBAC tetap terjaga** | Rute `laporan.psak101` (`role:manajer,pengurus,dps`) tidak berubah | ✅ |

**Kesimpulan:** seluruh kriteria **TERPENUHI**. Temuan **Z-09 tertutup**; rekomendasi **R-09 SELESAI**.

---

## 7. CATATAN & RISIKO SISA

- ✅ **Z-09 tertutup** — seluruh **9 temuan audit zakat (Z-01…Z-09)** kini tertutup.
- ✅ **0 regresi** — suite penuh 1474 passed (6582 assertions).
- ✅ Dokumentasi diperbarui: `docs/LAPORAN-AUDIT-ZAKAT.md` (status Z-09) + laporan ini.

> **Catatan desain (risiko tersisa):**
> - Rincian per kategori/asnaf bergantung pada baris `zakat_transaksis`. Baris lama **sebelum** modul zakat memakai pola ini tidak akan muncul di rincian, namun tetap terhitung pada saldo/mutasi jurnal — kondisi tersebut **terdeteksi** sebagai `selisihPenerimaan`/`selisihPenyaluran` dan disajikan sebagai temuan CaLK, bukan disembunyikan.
> - Penyesuaian jurnal manual langsung ke akun `2-3002` (tanpa melalui modul) akan muncul sebagai selisih yang sama; ini memang diharapkan agar setiap pergerakan dana titipan terverifikasi.
> - Cakupan CaLK 6.g sengaja dibatasi pada **Dana Zakat** (PSAK 109 sumber & penggunaan). Dana infak/sedekah penyajiannya sudah tercakup pada tabel DIT (komponen 5) dan dapat menjadi rekomendasi audit lanjutan bila diperlukan rincian serupa.

---

*Disusun sebagai bagian dari implementasi rekomendasi audit zakat. Seluruh perubahan diverifikasi melalui `php artisan test` pada 09 Oktober 2026.*
