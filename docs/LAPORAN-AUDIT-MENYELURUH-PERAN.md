# LAPORAN AUDIT MENYELURUH — PERAN, DASHBOARD, DAN FITUR

**Tanggal:** 2026-10-19
**Sistem:** Koperasi Syariah (Laravel 12 / PHP 8.3 / MySQL 8.4)
**Sifat:** Audit baca-saja. **Tidak ada kode produksi yang diubah** dalam proses ini.
**Dokumen pendukung:** [TEMUAN-AUDIT-AKSES-PER-PERAN.md](TEMUAN-AUDIT-AKSES-PER-PERAN.md)

---

## 1. Ringkasan Eksekutif

Audit mencakup **9 peran**, **7 dashboard eksklusif**, **202 rute GET** yang dijaga middleware `izin:`,
dan **176 nama view**. Hasil utamanya:

| Aspek | Status |
|---|---|
| Integritas statis (rute ↔ view ↔ menu ↔ izin) | **BERSIH** — 0 rute/view yatim, `config/izin.php` sinkron dua arah dengan rute |
| Dashboard peran pemilik dapat dimuat | **7/7 OK** (200) |
| Dashboard peran lain ditolak | **OK** (403/302 sesuai aturan) |
| Fitur dashboard per peran | **201 dari 202 rute sehat**; **1 rute menghasilkan HTTP 500** |
| Kesesuaian akses ↔ wewenang | **2 temuan KRITIS**, 2 TINGGI, 7 SEDANG, 4 RENDAH |

**Dua hal yang harus diputuskan sebelum produksi:**

1. **F-09 (KRITIS):** `GET /pengelola/pengajuan-pinjaman/create` **error 500** untuk AO, Manajer, dan Pengurus
   karena rute menunjuk method `create()` yang tidak ada.
2. **F-01 (KRITIS):** peran **`nasabah` tidak punya dashboard dan tidak punya izin apa pun** sehingga
   mengalami **redirect loop** dan seluruh menunya 403.

Selain itu, isu tata kelola terbesar adalah **F-03**: izin `teller.loket` diberikan juga kepada Manajer dan
Pengurus, membuka ±45 rute transaksi loket bagi mereka tanpa tautan menu dan tanpa guard sesi kas.

---

## 2. Ruang Lingkup, Metode, dan Batasan

### 2.1 Ruang lingkup

- Seluruh peran pada `config/role_hierarki.php` (9 peran).
- Seluruh dashboard peran dan endpoint ringkasnya.
- Seluruh rute **GET tanpa parameter** yang memiliki middleware `izin:` (202 rute) — inilah permukaan yang
  dapat dibuka lewat address bar oleh peran yang berwenang.
- Seluruh menu navigasi per peran (`config/navigasi.php`) dan seluruh rujukan `route()`/`view()` di Blade.

### 2.2 Metode

| Langkah | Instrumen | Keluaran |
|---|---|---|
| Inventaris peran & hak akses | pembacaan SSOT `role_hierarki.php` + `izin.php` + `navigasi.php` | Tabel §3 |
| Verifikasi dashboard per peran | `AuditAksesDashboardPerPeranTest` (10 skenario, 231 assertion) | Tabel §4 |
| Uji fungsi fitur | `AuditSapuanRutePerPeranTest` (202 rute × 9 peran, deteksi 5xx) | Tabel §5 |
| Integritas statis | `audit_statik.php` (5 pemeriksaan) | §5.3 |
| Klasifikasi celah menu | `audit_gap2.php` | §6 |

### 2.3 Batasan

- Tidak ada driver cakupan (Xdebug/PCOV); cakupan diukur dari **pemetaan cabang eksplisit**, bukan persentase.
- Interaksi JavaScript (klik, unggah, validasi klien) tidak disimulasikan. Temuan "tombol tidak berfungsi"
  hanya dilaporkan bila terbukti dari rute/controller yang tidak ada.
- Uji memakai basis data transaksional (`DatabaseTransactions`) dan `SESSION_DRIVER=array`.

---

## 3. Langkah 1 — Inventaris Peran & Hak Akses yang Seharusnya

### 3.1 Daftar peran (SSOT: `config/role_hierarki.php`)

| Peran | Level | Kategori | Dashboard | Izin | Rute menu | Rute GET terizinkan |
|---|---:|---|---|---:|---:|---:|
| `dps` | 60 | pengawas | `dashboard.dps` | 6 | 15 | 22 |
| `pengurus` | 50 | pengurus | `dashboard.pengurus` | 22 | 19 | 52 |
| `manajer` | 40 | pengelola | `dashboard.manajer` | 18 | 11 | 53 |
| `ao` | 30 | pengelola | `dashboard.ao` | 5 | 12 | 20 |
| `teller` | 20 | pengelola | `dashboard.teller` | 8 | 13 | 25 |
| `super_admin` | 15 | sistem | `dashboard.super-admin` | 10 | 7 | 14 |
| `admin` | 10 | sistem | `admin.settings.index` | 2 | 1 | 2 |
| `anggota` | 0 | anggota | `dashboard.nasabah` | 1 | 6 | 14 |
| `nasabah` | 0 | anggota | **— (tidak ada)** | **0** | 6 (alias) | **0** |

Catatan: `level` **bukan** peringkat akses. Ini peringkat kewenangan organisasi; `super_admin` sengaja
ditempatkan di bawah peran operasional dan **tidak** berada di rantai approval (pemisahan tugas).

### 3.2 Hak akses yang seharusnya per peran

**`dps` — Dewan Pengawas Syariah (pengawas, non-operasional)**
Seharusnya: membaca laporan keuangan & audit, memantau kepatuhan syariah, melihat CKPN.
Izin yang dipegang: `dps.pengawasan`, `laporan.neraca`, `laporan.buku_besar`, `laporan.psak101`,
`laporan.audit`, `ckpn.lihat`. **Tidak boleh** menulis jurnal atau menyetujui transaksi. **Sesuai.**

**`pengurus` — Pengurus Koperasi (pemegang kewenangan tertinggi operasional)**
Seharusnya: menetapkan kebijakan, menyetujui pembiayaan & transaksi, mengelola zakat/dana kebajikan/SHU,
mengesahkan laporan, mengelola produk pembiayaan.
Izin: 22 izin mulai `kebijakan.koperasi`, `pembiayaan.persetujuan`, `ckpn.posting`, `pengurus.keuangan`,
`produk.pembiayaan`, hingga `laporan.*`. **Sesuai**, dengan catatan F-03 (over-privilege loket) dan F-09.

**`manajer` — Manajer (pengelola operasional)**
Seharusnya: menyetujui transaksi dalam batas, mengawasi AO/teller, mengelola beban, melihat laporan manajerial.
Izin: 18 izin termasuk `approval.transaksi`, `pembiayaan.approve`, `manajer.operasional`, `laporan.manajer`.
**Sesuai**, dengan catatan F-03, F-07 (zakat tanpa menu), F-12, F-13.

**`ao` — Account Officer (pengelola pembiayaan)**
Seharusnya: survei & mengajukan pembiayaan, mengelola CIF calon debitur, melihat beban operasional.
Izin: `ao.pembiayaan`, `ao.cif`, `beban.operasional`, `pembiayaan.pengajuan`, `dasbor.ao`. **Sesuai**,
dengan catatan F-09.

**`teller` — Teller (operator loket)**
Seharusnya: transaksi tunai/non-tunai, setoran angsuran, pembukaan/penutupan kas, pengajuan rekening,
terminasi keanggotaan, CIF.
Izin: `dasbor.teller`, `cif.teller`, `beban.operasional`, `teller.loket`, `anggota.edit`,
`angsuran.setoran`, `pengajuan.rekening`, `terminasi.pengajuan`. **Sesuai**, dengan catatan F-11.

**`super_admin` — Administrator sistem (non-operasional)**
Seharusnya: master data, produk/akad, user & hak akses, COA, konfigurasi sistem, konfirmasi autodebit.
Izin: `master.data`, `simpanan.jenis`, `coa.kelola`, `super_admin.administrasi`, `autodebit.konfirmasi`,
`pengaturan.admin`, `laporan.*`. **Sesuai secara teknis**, dengan catatan tata kelola F-04.

**`admin` — Administrator (non-operasional)**
Seharusnya: konfigurasi administratif terbatas.
Izin: `pengaturan.admin`, `autodebit.konfirmasi`. **Tidak sesuai** — `dashboard_route` menunjuk halaman
milik Pengurus (F-02).

**`anggota` — Anggota koperasi (swalayan)**
Seharusnya: melihat simpanan, mutasi, pembiayaan, status pengajuan, mengajukan pembiayaan/keanggotaan.
Izin: `nasabah.swalayan`. **Sesuai sebagian** — 4 halaman tidak punya pintu masuk (F-10).

**`nasabah` — Nasabah/calon anggota (non-anggota, OJK-06)**
Seharusnya: melihat produk & mengajukan keanggotaan; **tidak** melihat data anggota.
Izin: **tidak ada**. **Tidak sesuai** — tidak dapat masuk sama sekali (F-01).

---

## 4. Langkah 2 — Verifikasi Dashboard per Peran

| Peran | URL dashboard | Controller / View | Status | Verdict |
|---|---|---|---|---|
| `dps` | `/dps/dashboard` | `DpsController@index` → `dashboard.dps` | 200 | **Sesuai** |
| `pengurus` | `/pengurus/dashboard` | `PengurusController@index` → `dashboard.pengurus` | 200 | **Sesuai**, catatan F-03/F-09 |
| `manajer` | `/manajer/dashboard` | `ManajerController@index` → `dashboard.manajer` | 200 | **Sesuai**, catatan F-03/F-12/F-13 |
| `ao` | `/ao/dashboard` | `AoDashboardController@index` → `dashboard.ao` | 200 | **Sesuai**, catatan F-09 |
| `teller` | `/teller/dashboard` | `TellerDashboardController@index` → `dashboard.teller` | 200 | **Sesuai**, catatan F-11 |
| `super_admin` | `/super-admin/dashboard` | `SuperAdminController@index` → `dashboard.super-admin` | 200 | **Sesuai**, catatan F-04 |
| `admin` | `/admin/settings` | `AdminSettingsController@index` → **`pengurus.kebijakan`** | 200 | **TIDAK sesuai** (F-02) |
| `anggota` | `/nasabah/dashboard` | `NasabahController@dashboard` → `nasabah.dashboard` | 200 | **Sesuai**, catatan F-10 |
| `nasabah` | **tidak ada** | — | **redirect loop** | **TIDAK sesuai** (F-01) |

**Yang diuji (10 skenario, 231 assertion — semuanya lulus):**

1. Inventaris 9 peran dan pemetaan dashboard.
2. Dashboard sendiri dapat dimuat untuk setiap peran.
3. Endpoint ringkas (`*.ringkas`) dapat dimuat.
4. Dashboard peran lain **selalu ditolak**.
5. Seluruh tautan menu per peran dapat dibuka.
6. Nasabah tanpa dashboard dan seluruh menu ditolak.
7. Dashboard admin berbagi halaman dengan pengurus & super_admin.
8. `teller.loket` dapat diakses manajer & pengurus tanpa menu.
9. Izin operasional pada peran non-operasional.
10. Fitur manajer tersembunyi dialihkan (bukan 403).

**Kesimpulan Langkah 2:** mekanisme *guard* bekerja (peran lain ditolak), tetapi **definisi wewenangnya**
masih memiliki dua lubang: `admin` tidak punya dashboard sendiri, dan `nasabah` tidak punya jalan masuk.

---

## 5. Langkah 3 — Hasil Uji Fungsi Fitur per Dashboard

### 5.1 Sapuan rute GET per peran

| Peran | Total rute | 200 | 302 | 403 | 404 | 422 | 5xx |
|---|---:|---:|---:|---:|---:|---:|---:|
| `dps` | 22 | 22 | 0 | 0 | 0 | 0 | 0 |
| `pengurus` | 52 | 49 | 2 | 0 | 0 | 0 | **1** |
| `manajer` | 53 | 45 | 7 | 0 | 0 | 0 | **1** |
| `ao` | 20 | 17 | 2 | 0 | 0 | 0 | **1** |
| `teller` | 25 | 25 | 0 | 0 | 0 | 0 | 0 |
| `super_admin` | 14 | 14 | 0 | 0 | 0 | 0 | 0 |
| `admin` | 2 | 2 | 0 | 0 | 0 | 0 | 0 |
| `anggota` | 14 | 14 | 0 | 0 | 0 | 0 | 0 |
| `nasabah` | 0 | 0 | 0 | 0 | 0 | 0 | 0 |
| **Total** | **202** | **188** | **11** | **0** | **0** | **0** | **3** |

### 5.2 Triage status non-200

| Rute | Status | Klasifikasi |
|---|---|---|
| `pengelola.pengajuan-pinjaman.create` (×3 peran) | **500** | **BUG — F-09** |
| `pengurus.cif.export`, `manajer.cif.export` | 302 | **Wajar** — `CifController::export()` mensyaratkan `?export=excel` |
| `pengajuan-pembiayaan.eligibility-check` (×3 peran) | 302 | **Wajar** — endpoint butuh parameter kueri |
| `pengeluaran-beban.index` (ao) | 302 | **Wajar** — pengalihan ramah AO (F-06) |
| `manajer.audit/bagi-hasil/kas/kinerja-staf/laporan` | 302 | **Disengaja** — `FITUR_DISEMBUNYIKAN` (F-06/F-13) |

Tidak ada **404**, **422**, atau **429** pada rute yang diizinkan — seluruh tautan menu menunjuk halaman
yang benar-benar ada.

### 5.3 Integritas statis

| Pemeriksaan | Hasil |
|---|---|
| `route()` di Blade yang menunjuk rute tak terdaftar | 1 (`password.request`) — **aman**, dibungkus `@if (Route::has(...))` |
| Nama view yang dirujuk tetapi tidak ada | **0 dari 176** |
| Menu per peran → rute tidak ada / tanpa middleware `izin:` | **0** (9/9 peran OK) |
| Izin dirujuk rute tetapi tidak ada di `config/izin.php` | **0** |
| Izin di `config/izin.php` tetapi tidak dipakai rute | **0** |

**Kesimpulan Langkah 3:** tidak ada elemen yang gagal dimuat karena rute/view hilang. Satu-satunya
kegagalan fungsional nyata adalah **F-09 (500)**. Selebihnya adalah fitur yang **hidup tetapi tidak
terjangkau dari UI** (F-10, F-11, F-12, F-13).

---

## 6. Langkah 4 — Daftar Temuan & Tingkat Keparahan

Rincian bukti per temuan ada di [TEMUAN-AUDIT-AKSES-PER-PERAN.md](TEMUAN-AUDIT-AKSES-PER-PERAN.md).

| Kode | Keparahan | Ringkas | Bukti utama |
|---|---|---|---|
| **F-01** | **KRITIS** | `nasabah` tanpa dashboard & tanpa izin → redirect loop; 6 menu 403 | `role_hierarki.php:144-151`; sapuan `total=0` |
| **F-09** | **KRITIS** | `pengelola.pengajuan-pinjaman.create` → HTTP 500 (method `create` tidak ada) | `routes/web.php:668`; sapuan 500 ×3 peran |
| **F-02** | TINGGI | `admin` tidak punya dashboard; diarahkan ke view `pengurus.kebijakan` | `AdminSettingsController:60-72`; `Izin::ada('dasbor.admin') === false` |
| **F-03** | TINGGI | `teller.loket` membuka ±45 rute loket ke manajer/pengurus tanpa menu & tanpa guard kas | `izin.php:50`; `PastikanKasTerbuka`; celah 42/33 |
| **F-04** | SEDANG | `super_admin` dapat membuka setoran angsuran operasional | `izin.php:52`; komentar rute menyatakan disengaja |
| **F-05** | SEDANG | Suite uji lama hanya mencakup 6–8 peran; `admin`/`anggota`/`nasabah` bolong | `DemonstrasiPerPeranTest`, `PetaAksesPeranMenuTest` |
| **F-07** | SEDANG | Fungsi back-office di luar menu pemilik (admin→autodebit, manajer→zakat, dps→ckpn) | `audit_gap2` |
| **F-10** | SEDANG | 4 halaman `anggota` 200 tetapi tanpa pintu masuk | `audit_gap2`: tanpa-pintu=4 |
| **F-11** | SEDANG | `teller.pencairan-disetujui.index` 200 tetapi tidak ditautkan dari Blade mana pun | `routes/web.php:592`; `audit_gap2` |
| **F-12** | SEDANG | Modul zakat terbuka untuk `manajer` tanpa menu | `izin.php:64`; `audit_gap2` |
| **F-14** | SEDANG | Pengurus dapat membuka `/admin/settings` tanpa menu; update lintas-controller | `routes/web.php:438-441` |
| **F-06** | RENDAH | Pengalihan ramah 302 menggantikan 403 (AO beban; fitur manajer tersembunyi) | `role_hierarki.php:399-422` |
| **F-08** | RENDAH | `super_admin` membuka halaman keuangan tanpa tautan menu (halaman turunan) | `audit_gap2`: celah=7 |
| **F-13** | RENDAH | `manajer.kas.index`/`closing-kas` mubazir (tanpa tautan + diblokir) | `role_hierarki.php:416-421` |
| **F-15** | RENDAH | `dps.dana-kebajikan.ringkasan` tanpa konsumen UI (positif palsu klasifikasi) | `routes/web.php:453` |

**Rekapitulasi:** KRITIS 2 · TINGGI 2 · SEDANG 7 · RENDAH 4 → **15 temuan**.

---

## 7. Langkah 5 — Rekomendasi Perbaikan

Prinsip: **perbaikan minimal, berbasis SSOT, tanpa mengubah perilaku uang/jurnal.** Setiap perbaikan
diverifikasi dengan dua instrumen audit yang sudah ada.

### P0 — Wajib sebelum produksi

| # | Temuan | Tindakan | Risiko | Verifikasi |
|---|---|---|---|---|
| 1 | **F-09** | **Hapus** rute `pengelola.pengajuan-pinjaman.create` (tidak ada method, tidak ada tautan UI). Bila form staf memang dibutuhkan, buat method `create()` + view `pengelola.pengajuan-pinjaman-create` yang memakai alur staf, **bukan** `form()`/`formBaru()` (keduanya alur nasabah). | Rendah — rute tidak dipakai UI | Sapuan: 0 × 500 |
| 2 | **F-01** | Pilih salah satu: **(a)** jadikan `nasabah` peran yang benar-benar aktif — tambahkan `dashboard_route` ke halaman landing non-anggota (produk + ajukan keanggotaan) dan izin khusus `nasabah.publik`; **jangan** beri akses ke rute `nasabah.*` milik anggota. **(b)** bila belum dipakai, nonaktifkan pembuatan akun berperan `nasabah`. **Wajib juga:** ubah fallback `redirectByRole()` agar tidak `redirect('/')` (mis. halaman "akses belum tersedia" + pesan), untuk memutus redirect loop. | Sedang — menyentuh autentikasi | Uji: login `nasabah` tidak loop; 0 tautan menu 403 |

### P1 — Segera setelah P0

| # | Temuan | Tindakan | Risiko |
|---|---|---|---|
| 3 | **F-03** | Pisahkan izin: `teller.loket` hanya `['teller']`. Bila manajer/pengurus memang perlu **melihat** (bukan menjalankan), buat izin baca terpisah (`teller.loket.lihat`) yang hanya membuka halaman daftar/riwayat. Perbaiki `PastikanKasTerbuka` agar tidak otomatis meloloskan non-teller pada aksi tulis. | Sedang — perlu uji regresi transaksi |
| 4 | **F-02** | Buat dashboard `admin` yang nyata (`dashboard.admin` + izin `dasbor.admin` + view ringkasan sistem), atau — bila admin memang hanya pengelola pengaturan — ganti `dashboard_route` ke halaman netral dan **pisahkan view** dari `pengurus.kebijakan`. Pindahkan `admin.settings.update` ke `AdminSettingsController`. | Rendah–sedang |

### P2 — Perbaikan keterjangkauan fitur

| # | Temuan | Tindakan |
|---|---|---|
| 5 | **F-10** | Tambahkan tautan menu/shortcut ke 4 halaman anggota, **atau** hapus rutenya bila duplikat dari `nasabah.pengajuan.index`/`status-pengajuan`. |
| 6 | **F-11** | Tambahkan tautan ke `teller.pencairan-disetujui.index` dari dashboard/daftar pencairan teller (fitur ini sudah punya otomatisasi jurnal — sayang bila tidak terjangkau). |
| 7 | **F-12** | Tambahkan menu zakat untuk `manajer` (bila memang wewenangnya) **atau** cabut `manajer` dari `zakat.kelola`. Jangan biarkan "terbuka tetapi tersembunyi". |
| 8 | **F-14** | Tambahkan menu `/admin/settings` untuk pengurus **atau** cabut `pengurus` dari `pengaturan.admin`; satukan controller. |
| 9 | **F-07** | Selaraskan menu dengan izin: tampilkan menu untuk fungsi yang memang boleh dijalankan, cabut izin untuk yang tidak. |

### P3 — Kebersihan & dokumentasi

| # | Temuan | Tindakan |
|---|---|---|
| 10 | **F-05** | Pertahankan dua test audit baru sebagai bagian suite reguler; tambahkan `admin`, `anggota`, `nasabah` ke `DemonstrasiPerPeranTest`. |
| 11 | **F-06** | Dokumentasikan 302 sebagai perilaku yang disengaja agar tidak dilaporkan ulang sebagai bug. |
| 12 | **F-08** | Biarkan (sesuai peran sistem), cukup catat di matriks akses. |
| 13 | **F-13** | Hapus rute kas manajer yang tidak tertaut dan sudah diblokir. |
| 14 | **F-15** | Dokumentasikan endpoint atau hapus bila tidak dikonsumsi UI. |
| 15 | **F-04** | Catat sebagai risiko tata kelola; bila ingin ketat, cabut `super_admin` dari `angsuran.setoran` dan berikan izin baca terpisah. |

### Urutan eksekusi yang disarankan

```
P0-1 (F-09) → P0-2 (F-01) → P1-3 (F-03) → P1-4 (F-02)
   → P2 (F-10…F-14) → P3 (kebersihan)
```

Setiap langkah diakhiri dengan:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\run-tests.ps1 -Filter "AuditAksesDashboardPerPeranTest|AuditSapuanRutePerPeranTest"
```

Kriteria lulus: **10 + 1 test hijau**, **0 × 5xx**, dan tidak ada penurunan jumlah rute 200.

---

## 8. Kesimpulan

1. **Fondasi RBAC sehat.** SSOT peran dan izin konsisten; tidak ada rute/view yatim; seluruh menu menunjuk
   halaman yang ada; dashboard peran lain ditolak dengan benar.
2. **Dua kegagalan nyata** menghalangi kelayakan produksi: **F-09 (500)** dan **F-01 (nasabah tidak bisa masuk)**.
3. **Isu tata kelola utama** adalah **F-03**: Manajer dan Pengurus memegang seluruh izin loket tanpa tampilan
   menu dan tanpa guard sesi kas — berpotensi melanggar pemisahan tugas maker-checker.
4. **Tujuh fitur hidup tanpa pintu masuk** (F-07, F-10, F-11, F-12, F-13, F-14) menunjukkan menu navigasi
   belum sepenuhnya menjadi cermin dari `config/izin.php`.
5. Setelah P0–P1 dikerjakan, sistem memenuhi syarat "dashboard berjalan normal dan akses sesuai role";
   P2–P3 bersifat penyempurnaan.

---

## 9. Lampiran

- `storage/app/audit_sweep_output.txt` — rekaman 202 rute × 9 peran.
- `storage/app/audit_statik_output.txt` — hasil 5 pemeriksaan integritas.
- `storage/app/audit_gap2_output.txt` — klasifikasi celah menu vs rute.
- `tests/Feature/AuditAksesDashboardPerPeranTest.php` — 10 skenario akses.
- `tests/Feature/AuditSapuanRutePerPeranTest.php` — sapuan rute & deteksi 5xx.
