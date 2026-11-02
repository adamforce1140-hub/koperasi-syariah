# TAHAP 2 — PSAK INTI (Klaster A)

**Status:** ✅ SELESAI & TERVERIFIKASI
**Tanggal penyelesaian:** 2026-09-25
**Lingkup temuan:** Klaster A — 15 temuan ketidaksesuaian PSAK inti
**Dasar standar:** PSAK 101, 102, 105, 106, 107, 109, 71 + Fatwa DSN-MUI

---

## 1. Ringkasan Eksekutif

Tahap 2 menutup 15 temuan Klaster A pada tiga area inti:

1. **Dana Syirkah Temporer (DST)** dipisahkan dari Kewajiban pada lapisan **data**,
   bukan sekadar tampilan — DST adalah dana investasi pemilik dana (bukan utang),
   sehingga wajib disajikan sebagai unsur tersendiri dalam Laporan Posisi Keuangan.
2. **CKPN / PSAK 71** dioperasionalkan penuh: klasifikasi kolektibilitas dinamis,
   tarif penyisihan berasal dari parameter kebijakan, jurnal penyesuaian otomatis,
   dan penjadwalan harian.
3. **Modul Zakat, Infak & Sedekah (PSAK 109)** dibangun dari nol: akun CoA terpisah,
   alur penerimaan/penyaluran, batas syariah 8 asnaf, serta laporan sumber &
   penggunaan dana.

### Kriteria Keberhasilan Tahap 2

| # | Kriteria | Target | Hasil | Status |
|---|---|---|---|---|
| K2.1 | DST tidak lagi tergabung dalam total Kewajiban | DST terpisah di data & penyajian | `$totalKewajiban` dan `$totalDanaSyirkahTemporer` dihitung terpisah; Total Pasiva = Kewajiban + DST + Ekuitas + Laba | ✅ |
| K2.2 | Kebijakan penyisihan CKPN zero-hardcoding | 100% tarif & ambang dari `Setting` | `Setting::persenCkpn()`, `Setting::ambangKolektibilitas()` — tidak ada 30/90/180 atau 5/15/50/100 di kode | ✅ |
| K2.3 | CKPN berjalan tanpa intervensi manual | Command terjadwal harian | `ckpn:hitung-penyesuaian` daily 00:01 Asia/Jakarta, `withoutOverlapping()` | ✅ |
| K2.4 | Dana zakat & infak/sedekah pada akun terpisah | CoA 2-3002 & 2-3004 berbeda, tidak bercampur 2-3001 | `Coa::DANA_ZAKAT=2-3002`, `Coa::DANA_INFAK_SEDEKAH=2-3004`, terpisah dari `DANA_KEBAJIKAN=2-3001` | ✅ |
| K2.5 | Zakat hanya untuk 8 asnaf (QS At-Taubah 60) | Penyaluran ke non-asnaf ditolak | `ZakatService::ASNAF` (tepat 8), `InvalidArgumentException` untuk kategori non-asnaf | ✅ |
| K2.6 | Saldo dana = mutasi jurnal CoA (sumber kebenaran) | Buku besar = jurnal, presisi identik | Pembulatan disatukan ke `Setting::pembulatanNominal()` pada terima/salurkan | ✅ |
| K2.7 | Suite regresi tidak turun | ≥ 799 passed / 3876 assertions | **826 passed / 4015 assertions** | ✅ |
| K2.8 | RBAC modul baru terbukti | Teller/AO ditolak; DPS read-only | Terverifikasi pada test Zakat & CKPN | ✅ |

---

## 2. Aktivitas yang Dilaksanakan

### 2.1 Rekonsiliasi Struktur Data & Pendukung (recon)

Verifikasi struktur nyata basis data sebelum penulisan kode:

| Objek | Temuan | Implikasi |
|---|---|---|
| Tabel induk jurnal | `jurnal` (**bukan** `jurnals`) | Semua query join wajib memakai `jurnal` |
| Tabel baris jurnal | `jurnal_detail` (tunggal) | — |
| Kolom penghubung CoA | **`coa_id`** → FK `coas.id` | Kolom warisan `akun_id` → FK `akun` **selalu NULL** untuk jurnal berbasis CoA |
| Kolom tanggal jurnal | `tanggal_jurnal` | Bukan `created_at` untuk penentuan periode |
| Sumber jurnal aplikasi | `AutoJournalingService::post()` | Mengisi `coa_id`, mengosongkan `akun_id` |

> **Temuan penting:** kolom `akun_id` adalah warisan skema lama dan **tidak pernah terisi**
> oleh jurnal yang diposting aplikasi. Query laporan yang memakai `akun_id` akan selalu
> mengembalikan 0. Kesalahan ini sempat terjadi pada `ZakatService::ringkasan()` dan
> diperbaiki pada Tahap 2 (lihat §4).

### 2.2 Pemisahan Dana Syirkah Temporer dari Kewajiban (lapisan data)

DST dipisahkan pada **perhitungan**, bukan hanya presentasi. `DanaCadanganController`
memisahkan klasifikasi neraca `'Dana Syirkah Temporer'` dari `'Kewajiban'`, sehingga:

```
Total Pasiva = Total Kewajiban + Total Dana Syirkah Temporer + Total Ekuitas + Laba Berjalan
```

Pemisahan pada lapisan controller (bukan view) bersifat **intentional** — view tetap
menerima dua variabel terpisah dan tidak pernah menjumlahkannya sendiri.

**Bukti:** `app/Http/Controllers/DanaCadanganController.php` → `$totalKewajiban`,
`$totalDanaSyirkahTemporer`, `$totalEkuitas`, `$labaBersih`, `$selisihNeraca`.

### 2.3 Operasionalisasi CKPN / PSAK 71 (penuh)

| Komponen | Berkas | Keterangan |
|---|---|---|
| Service | `app/Services/CkpnService.php` | `kolektibilitasKode()`, `hariTunggakan()`, `rincian()`, `targetSaldo()`, `saldoTercatat()`, `selisih()`, `catatPenyesuaian()` |
| Command | `app/Console/Commands/HitungCkpnPenyesuaian.php` | `ckpn:hitung-penyesuaian {--dry-run} {--date=}` |
| Penjadwalan | `routes/console.php` | `dailyAt('00:01')->timezone('Asia/Jakarta')->withoutOverlapping()` |
| Halaman | `app/Http/Controllers/CkpnController.php` + `resources/views/laporan/ckpn.blade.php` | Ringkasan per kolektibilitas + rincian per pembiayaan + tombol penyesuaian |

**Aturan jurnal penyesuaian:**

| Kondisi | Debet | Kredit |
|---|---|---|
| Penyisihan **naik** (target > tercatat) | `5-4001` Beban CKPN | `1-2100` Penyisihan CKPN |
| Penyisihan **turun** (pemulihan) | `1-2100` Penyisihan CKPN | `5-4001` Beban CKPN |

**Ambang kolektibilitas (dinamis, dari `Setting`):** default kol2 ≥ 30 hari,
kol3 ≥ 90 hari, kol4 ≥ 180 hari, kol5 > kol4. Tarif default: kol1 0,50% ·
kol2 5,00% · kol3 15,00% · kol4 50,00% · kol5 100,00%.

**Bukti dry-run:** kol4 — 5 pembiayaan, sisa Rp 57.272.500,00, tarif 50,00% →
CKPN Rp 28.636.250,01; target Rp 28.636.250,01; tercatat Rp 0,00.

### 2.4 Pembangunan Modul Zakat, Infak & Sedekah (PSAK 109)

**Landasan:** PSAK 109 menetapkan dana zakat serta infak/sedekah sebagai **dana titipan
(amanah)** — bukan pendapatan koperasi — sehingga wajib disajikan terpisah. Penyaluran
zakat dibatasi 8 asnaf (QS At-Taubah 60) sesuai Fatwa DSN-MUI No. 08/DSN-MUI/IV/2000.

**Artefak:**

| Lapisan | Berkas |
|---|---|
| CoA | Migrasi `2026_09_27_000100_add_zakat_coa` → akun `2-3002` Dana Zakat, `2-3004` Dana Infak & Sedekah |
| Tabel | Migrasi `2026_09_27_000200_create_zakat_transaksis_table` |
| Konstanta | `App\Support\Coa::DANA_ZAKAT`, `Coa::DANA_INFAK_SEDEKAH` |
| Model | `app/Models/ZakatTransaksi.php` |
| Service | `app/Services/ZakatService.php` |
| Controller | `app/Http/Controllers/ZakatController.php` |
| View | `resources/views/zakat/index.blade.php`, `resources/views/zakat/laporan.blade.php` |
| Route | `zakat.index`, `zakat.laporan`, `zakat.ringkasan`, `zakat.store`, `zakat.salurkan`, `dps.zakat.index`, `dps.zakat.laporan` |
| Navigasi | `NavigationService::menuPengurus()`, `menuManajer()`, `menuDPS()` |

**Aturan posisi jurnal (konsisten dengan `DanaKebajikanService`):**

| Transaksi | Debet | Kredit |
|---|---|---|
| Penerimaan | Kas/Bank | `2-3002` atau `2-3004` |
| Penyaluran | `2-3002` atau `2-3004` | Kas/Bank |

**Kategori penerimaan:** zakat maal, zakat penghasilan, infak, kegiatan sosial.
**Kategori penyaluran:** 8 asnaf + 3 kategori sosial (khusus dana infak/sedekah).

**RBAC:**

| Peran | Buku besar (`zakat.index`) | Laporan (`zakat.laporan`) | Posting |
|---|---|---|---|
| Pengurus | ✅ | ✅ | ✅ |
| Manajer | ✅ | ✅ | ✅ |
| DPS | ✅ (read-only, `dps.zakat.*`) | ✅ (read-only) | ❌ 403 |
| Teller / AO | ❌ 403 | ❌ 403 | ❌ 403 |

### 2.5 Perbaikan Bug Kritis yang Terungkap

Pengujian modul baru mengungkap **empat** cacat nyata:

| # | Gejala | Akar masalah | Perbaikan |
|---|---|---|---|
| B1 | `Table 'koperasi_syariah.jurnals' doesn't exist` | Nama tabel salah (plural) | `jurnals` → `jurnal` |
| B2 | Saldo dana selalu 0 padahal transaksi tercatat | Query memakai kolom warisan `akun_id` yang selalu NULL | `jurnal_detail.akun_id` → `jurnal_detail.coa_id` |
| B3 | Saldo buku besar (Rp 1.500.001) ≠ besar transaksi (Rp 1.500.000,55) | `AutoJournalingService` memakai `Setting::pembulatanNominal()`, sedangkan `ZakatService` memakai `round(..., 2)` | `ZakatService::terima()/salurkan()` disatukan ke `Setting::pembulatanNominal()` |
| B4 | Saldo awal periode tidak utuh | Saldo awal hanya menjumlah transaksi modul, mengabaikan jurnal manual/adjustment langsung ke CoA | Saldo awal kini = SUM(kredit) − SUM(debet) **seluruh** jurnal `Posted` akun dana s/d `$dari − 1` |

**Prinsip yang ditegakkan (B3 & B4):** nilai buku besar **wajib identik** dengan nilai
jurnal agar kontrol silang PSAK 109 sahih. Satu sumber aturan pembulatan
(`Setting::pembulatanNominal()`) berlaku untuk semua jalur pencatatan.

### 2.6 Penyelarasan RBAC CKPN

Ditemukan inkonsistensi berlapis pada Tombol "Posting Penyesuaian CKPN":

- Route POST terdaftar untuk `role:manajer,pengurus`.
- Namun matriks `LaporanKeuanganPermission` memberi hak `edit` modul `neraca`
  **hanya kepada Pengurus** — manajer hanya `[LIHAT]`.

Akibatnya setiap POST dari manajer **pasti ditolak 403** oleh middleware
`laporan.akses:neraca` sebelum mencapai controller — rute tersebut menyajikan wewenang
yang tidak dapat dilaksanakan. Diselaraskan menjadi:

| Lapisan | Sebelum | Sesudah |
|---|---|---|
| Route POST | `role:manajer,pengurus` | **`role:pengurus`** |
| View (tombol posting) | `isPengurus()` (sempat diubah, dikembalikan) | `isPengurus()` |
| Matriks RBAC | `manajer: [LIHAT]` | tetap — konsisten |

> **Catatan lintas-tahap:** ketidakselarasan pola *route gate* vs *matriks hak* ini
> dicatat sebagai temuan untuk Tahap 4 (audit RBAC menyeluruh).

---

## 3. Hasil Pengujian

### 3.1 Test suite baru

| Berkas | Jumlah test | Status |
|---|---|---|
| `tests/Feature/ZakatPsak109Test.php` | 15 | ✅ 15 passed (76 assertions) |
| `tests/Feature/CkpnPsak71Test.php` | 12 | ✅ 12 passed (~44 assertions) |

**Cakupan `ZakatPsak109Test` (15 test):** pemisahan akun zakat vs infak/sedekah vs dana
kebajikan; posisi jurnal penerimaan & penyaluran; batas 8 asnaf; penolakan penyaluran
melebihi saldo; penolakan input tidak sah; keterhubungan buku besar ↔ jurnal `Posted`
berimbang; struktur hasil `validasi()`; 4 komponen PSAK 109 pada `ringkasan()`; RBAC
pengurus/manajer; DPS read-only; teller & AO ditolak; alur HTTP form penerimaan;
penolakan HTTP saat saldo kurang; render halaman laporan.

**Cakupan `CkpnPsak71Test` (12 test):** klasifikasi kolektibilitas mengikuti ambang
parameter; pembiayaan lancar → kol1; pergeseran kol2/kol3/kol4/kol5 sesuai hari
tunggakan; kolom `status_kolektibilitas` menimpa perhitungan hari; tarif dari parameter
kebijakan; pembiayaan lunas tidak disisihkan; konsistensi `targetSaldo()` = Σ rincian;
jurnal penyesuaian debet 5-4001 / kredit 1-2100; idempotensi (pemanggilan kedua tidak
membuat jurnal); selisih dalam toleransi tidak menghasilkan jurnal; RBAC baca
manajer/pengurus/DPS; RBAC posting hanya pengurus.

### 3.2 Suite regresi penuh

| Metrik | Baseline (akhir Tahap 1) | Setelah Tahap 2 | Delta |
|---|---|---|---|
| Test passed | 799 | **826** | **+27** |
| Assertions | 3.876 | **4.015** | **+139** |
| Test gagal | 0 | **0** | 0 |
| Durasi | — | 92,27 s | — |

**Perintah:** `php artisan test`

```
Tests:    826 passed (4015 assertions)
Duration: 92.27s
```

---

## 4. Validasi Menyeluruh

| # | Butir validasi | Cara validasi | Hasil |
|---|---|---|---|
| V2.1 | Migrasi Tahap 2 benar-benar diterapkan di basis data | `php artisan migrate --force` | `2026_09_27_000100_add_zakat_coa` **DONE**; `2026_09_27_000200_create_zakat_transaksis_table` **DONE** |
| V2.2 | Kolom penghubung CoA terkonfirmasi | `SHOW COLUMNS FROM jurnal_detail` | `id, jurnal_id, akun_id, coa_id, baris, debet, kredit, ...` — `coa_id` terisi 133/133 baris, `akun_id` NULL 133/133 |
| V2.3 | Akun dana terpisah ter-seed dengan benar | Query `coas` | `2-3001` Dana Kebajikan (id 16), `2-3002` Dana Zakat (id 1725), `2-3003` Dana Sosial (id 17), `2-3004` Dana Infak & Sedekah (id 1726) |
| V2.4 | Tidak ada query lain memakai nama tabel/kolom keliru | Grep `coa_id` / `akun_id` / `jurnals` di `app/` | Sesuai harapan: modul lain memakai `coa_id` (benar); koreksi terbatas pada `ZakatService` |
| V2.5 | Route Zakat & CKPN terdaftar | `php artisan route:list --path=zakat` | 7 route Zakat |
| V2.6 | Syntax seluruh berkas tersentuh bersih | `php -l` | Bersih |
| V2.7 | Scheduler CKPN aktif | `php artisan schedule:list` | `ckpn:hitung-penyesuaian` 00:01 Asia/Jakarta |
| V2.8 | Command CKPN berfungsi tanpa efek samping | `ckpn:hitung-penyesuaian --dry-run` | Angka konsisten, tidak memposting |
| V2.9 | Saldo dana = mutasi jurnal | Test `test_penyaluran_mendebet_akun_dana_dan_tidak_menyentuh_akun_lain` | Saldo awal + penerimaan − penyaluran cocok persis |
| V2.10 | Setiap transaksi zakat terhubung jurnal Posted berimbang | Test `test_setiap_transaksi_terhubung_ke_jurnal_posted_berimbang` | Lulus |
| V2.11 | Idempotensi penyesuaian CKPN | Test `test_penyesuaian_tidak_dibuat_bila_selisih_dalam_toleransi` | Lulus |
| V2.12 | Batas syariah ditegakkan | Test `test_zakat_hanya_dapat_disalurkan_kepada_delapan_asnaf` | Lulus |
| V2.13 | RBAC seluruh peran pada modul baru | 4 test RBAC (2 Zakat + 2 CKPN) | Lulus |
| V2.14 | Regresi nol | `php artisan test` | 826 passed, 0 failed |

---

## 5. Berkas yang Dibuat / Diubah

### Dibuat

| Berkas | Keterangan |
|---|---|
| `database/migrations/2026_09_27_000100_add_zakat_coa.php` | CoA 2-3002 & 2-3004 |
| `database/migrations/2026_09_27_000200_create_zakat_transaksis_table.php` | Tabel buku besar zakat |
| `app/Models/ZakatTransaksi.php` | Model + konstanta jenis/dana/kategori + aksesor label |
| `app/Services/ZakatService.php` | Logika PSAK 109 |
| `app/Services/CkpnService.php` | Logika CKPN / PSAK 71 |
| `app/Http/Controllers/ZakatController.php` | Controller modul zakat |
| `app/Http/Controllers/CkpnController.php` | Controller halaman CKPN |
| `app/Console/Commands/HitungCkpnPenyesuaian.php` | Command penyesuaian terjadwal |
| `resources/views/zakat/index.blade.php` | Buku besar + form penerimaan/penyaluran |
| `resources/views/zakat/laporan.blade.php` | Laporan sumber & penggunaan dana |
| `resources/views/laporan/ckpn.blade.php` | Halaman CKPN |
| `tests/Feature/ZakatPsak109Test.php` | 15 test PSAK 109 |
| `tests/Feature/CkpnPsak71Test.php` | 12 test PSAK 71 |

### Diubah

| Berkas | Perubahan |
|---|---|
| `app/Support/Coa.php` | Konstanta `DANA_ZAKAT`, `DANA_INFAK_SEDEKAH`, `CKPN_PIUTANG_PEMBIAYAAN`, `BEBAN_CKPN_PEMBIAYAAN` |
| `app/Support/Setting.php` | `persenCkpn()`, `persenCkpnKolektibilitas()`, `ambangKolektibilitas()`, `kolektibilitasDariHari()`, `pembulatanNominal()`, `toleransiSelisihJurnal()` |
| `app/Services/AutoJournalingService.php` | Dukungan akun dana sosial/zakat |
| `app/Services/NavigationService.php` | Entri CKPN & Zakat pada menu Pengurus, Manajer, DPS |
| `routes/web.php` | Route Zakat (pengurus/manajer + DPS read-only) & CKPN; POST CKPN diselaraskan ke `role:pengurus` |
| `routes/console.php` | Penjadwalan `ckpn:hitung-penyesuaian` |
| `resources/views/laporan/ckpn.blade.php` | Tombol posting dibatasi `isPengurus()` |

---

## 6. Risiko Sisa & Serah Terima ke Tahap Berikut

| # | Risiko / temuan sisa | Dampak | Ditangani di |
|---|---|---|---|
| R2.1 | 5 dari 8 komponen laporan PSAK 101 belum ada (Laba Rugi mandiri, Perubahan Ekuitas, Arus Kas, DST mandiri, CaLK) | Kepatuhan penyajian belum utuh | **Tahap 3** |
| R2.2 | Belum ada guard maker-checker (self-approval) | Risiko integritas persetujuan | **Tahap 4** |
| R2.3 | LTKM/LTKT & penapisan sanksi belum ada | Kepatuhan APU-PPT OJK | **Tahap 4** |
| R2.4 | Pola *route gate* vs matriks hak belum diaudit menyeluruh (temuan B/RBAC CKPN) | Potensi rute menyajikan wewenang yang tak dapat dilaksanakan | **Tahap 4** |
| R2.5 | Pembulatan ke rupiah penuh memunculkan selisih sisa ≤ Rp 1 pada target CKPN | Kosmetik; tetap dalam pengendalian toleransi | **Tahap 5** |
| R2.6 | `DanaCadanganController` menampung logika Neraca; belum ada service agregator laporan | Kemudahan pemeliharaan | **Tahap 3 / 5** |

---

## 7. Pernyataan Penyelesaian

Seluruh 15 temuan Klaster A telah ditutup. Kriteria keberhasilan K2.1–K2.8 **terpenuhi
seluruhnya**. Validasi V2.1–V2.14 lulus tanpa pengecualian. Suite regresi tidak
mengalami penurunan: **826 passed / 4015 assertions, 0 gagal**.

Tahap 2 dinyatakan **SELESAI** dan siap menjadi fondasi Tahap 3 (Pelaporan).
