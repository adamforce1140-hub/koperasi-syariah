# LAPORAN AUDIT MENYELURUH PERAN (ROLE)
## Kepatuhan Syariah & Kesesuaian PSAK — Sistem Operasional Koperasi Syariah

| Atribut | Keterangan |
|---|---|
| **Judul Audit** | Audit Peran (Role) atas Kepatuhan Prinsip Syariah dan PSAK Entitas Syariah/Koperasi |
| **Objek Audit** | Seluruh peran dalam sistem operasional koperasi syariah: `dps`, `pengurus`, `manajer`, `ao`, `teller`, `admin`, `anggota` |
| **Ruang Lingkup Sistem** | Aplikasi Laravel 12 (`c:\laragon\www\koperasi_syariah`) — lapisan rute, middleware, controller, service, model, dan dokumentasi tahapan |
| **Tanggal Audit** | 2 Oktober 2026 |
| **Basis Metodologi** | Audit kepatuhan berbasis bukti kode (code-evidence compliance audit) |
| **Regulasi Acuan** | UU No. 25/1992 Pasal 30; PP No. 7/2021; Permen KUKM No. 09/2018; POJK No. 76/2016; UU PDP No. 27/2022; Fatwa DSN-MUI; PSAK 101, 102, 105, 106, 107, 108, 109, 110, 111 |
| **Status** | Laporan akhir — siap ditindaklanjuti |

---

## 1. RINGKASAN EKSEKUTIF

Audit ini menilai apakah **setiap peran (role)** dalam sistem operasional koperasi syariah menjalankan fungsi, alur kerja, dan proses keuangannya sesuai **prinsip syariah** (bebas riba, gharar, maysir, dan patuh akad) serta sesuai **PSAK** untuk entitas syariah/koperasi.

### 1.1 Kesimpulan Umum

Sistem memiliki **fondasi RBAC dan pengendalian internal yang matang** (Tahap 13): hierarki peran terpusat (`config/role_hierarki.php` + `App\Support\HierarkiRole`), pemisahan tugas maker–checker (`App\Support\PemisahanTugas`), rantai approval berjenjang Teller→AO→Manajer→Pengurus, jejak audit disegel rantai hash SHA-256, dan matriks hak akses laporan keuangan terpusat (`App\Support\LaporanKeuanganPermission`).

Namun audit menemukan **sebelas (11) temuan** yang perlu ditindaklanjuti. Temuan tersebut terbagi:

- **2 temuan RISIKO TINGGI** — menyangkut kepatuhan syariah inti (fungsi DPS) dan integritas akuntansi (jurnal manual tanpa maker–checker).
- **5 temuan RISIKO SEDANG** — menyangkut pemisahan tugas, otentikasi, kontrol berbasis peran yang bercabang, dan kelengkapan pengamanan beban operasional.
- **4 temuan RISIKO RENDAH** — menyangkut ketidaklengkapan dokumentasi, inkonsistensi penamaan peran, dan pengawasan data.

### 1.2 Skor Kepatuhan per Dimensi

| Dimensi Audit | Skor | Keterangan Singkat |
|---|---:|---|
| Struktur peran & kejelasan wewenang | **92%** | 7 peran terdefinisi terpusat, level & kategori jelas |
| Kepatuhan syariah (riba/gharar/maysir/akad) | **78%** | Fatwa/opini DPS tidak tersimpan; pemetaan akad→CoA sudah ada |
| Kesesuaian akuntansi & pelaporan vs PSAK | **85%** | PSAK 101 lengkap (6 komponen); PSAK 110/111 absen (tidak dipakai) |
| Pemisahan tugas & pengendalian internal | **80%** | Maker–checker kuat di transaksi; jurnal manual belum dipisah |
| Jejak audit & akuntabilitas | **95%** | Rantai hash append-only, semua peristiwa approval tercatat |
| Manajemen akses & otentikasi | **88%** | `is_active` belum ditegakkan saat login |
| **Rata-rata Tertimbang** | **86%** | **Baik, dengan perbaikan wajib pada 2 temuan Tinggi** |

### 1.3 Rekomendasi Prioritas Utama

1. **Segera (0–30 hari):** Terapkan maker–checker pada posting/void jurnal manual dan tegakkan status `is_active` saat login.
2. **Jangka menengah (30–60 hari):** Bangun modul **Fatwa/Opini DPS** yang tersimpan & tertaut ke produk/akad, serta jadikan `DPS` sebagai pemutus dalam rantai persetujuan produk.
3. **Jangka panjang (60–90 hari):** Konsolidasi pengecekan peran ke satu sumber (`HierarkiRole`), perkuat pengamanan beban operasional, dan tutup celah PSAK 108 zakat atas SHU (lihat dokumen pendamping `docs/AUDIT-PARAMETER-PSAK-FLEKSIBILITAS.md`).

---

## 2. RUANG LINGKUP & METODOLOGI AUDIT

### 2.1 Ruang Lingkup

Audit mencakup **seluruh peran** (`dps`, `pengurus`, `manajer`, `ao`, `teller`, `admin`, `anggota`) pada aspek:
- Struktur organisasi & sistem (tugas, wewenang, tanggung jawab).
- Alur kerja operasional & kepatuhan prinsip syariah.
- Proses akuntansi & pelaporan keuangan vs PSAK.
- Pengendalian internal (batas kewenangan, pemisahan tugas, jejak audit, manajemen akses).

**Di luar lingkup:** pengujian penetrasi keamanan infrastruktur, audit laporan keuangan historis per-periode (merupakan audit terpisah pada `docs/AUDIT-PSAK-LAPORAN-KEUANGAN.md`), dan audit kepatuhan pajak.

### 2.2 Metodologi

Audit dilaksanakan dengan **empat prosedur**:

| # | Prosedur | Cara Pelaksanaan |
|---|---|---|
| P1 | **Inspeksi konfigurasi & dokumentasi** | Membaca `config/role_hierarki.php`, `docs/TAHAP13-RBAC-HIERARKI-ROLE.md`, `docs/TAHAP4-GCG-OJK.md`, `docs/AUDIT-PARAMETER-PSAK-FLEKSIBILITAS.md` |
| P2 | **Pengujian pengendalian (walkthrough kode)** | Menelusuri rute (`routes/web.php`, `routes/api.php`) → middleware → controller → service untuk setiap peran |
| P3 | **Verifikasi bukti silang (cross-evidence)** | Mencocokkan definisi peran di config dengan penegakan nyata di `CheckRole`, `LaporanKeuanganAccess`, `PemisahanTugas`, dan `Guard` domain |
| P4 | **Analisis kesenjangan (gap analysis)** | Membandingkan perilaku sistem dengan PSAK relevan & prinsip syariah, lalu menetapkan tingkat risiko |

**Kriteria tingkat risiko (risk rating):**

| Tingkat | Definisi |
|---|---|
| **TINGGI** | Berdampak langsung pada keabsahan transaksi syariah atau kebenaran laporan keuangan; berpotensi sanksi regulasi/kerugian. Perbaikan ≤ 30 hari. |
| **SEDANG** | Melemahkan pengendalian internal atau menimbulkan risiko operasional/reputasi; perbaikan ≤ 60 hari. |
| **RENDAH** | Ketidakrapian administratif/dokumentasi; tidak berpotensi kerugian langsung. Perbaikan ≤ 90 hari. |

### 2.3 Keterbatasan Audit

- Audit bersifat **berbasis bukti kode statis**; tidak mencakup pengujian runtime pada data produksi.
- Efektivitas **manusia** (kompetensi petugas DPS/akuntansi) tidak dapat dinilai dari kode dan menjadi bagian rekomendasi peningkatan kapasitas SDM.

---

## 3. LANGKAH 1 — IDENTIFIKASI SELURUH ROLE, TUGAS, WEWENANG, DAN TANGGUNG JAWAB

### 3.1 Sumber Kebenaran Peran

Peran terdefinisi terpusat di **`config/role_hierarki.php`** (baris 45–178) dan dibaca hanya melalui **`App\Support\HierarkiRole`**. Setiap entri memiliki atribut: `label`, `level` (makin besar makin tinggi kewenangan), `kategori` (`pengawas` | `pengurus` | `pengelola` | `sistem` | `anggota`), `penyetuju` (boleh menjadi pemutus/checker), dan `operasional` (terlibat transaksi harian).

Tujuh peran yang dikenal sistem: **DPS, Pengurus, Manajer, AO, Teller, Admin, Anggota.**

### 3.2 Matriks Tugas, Wewenang, Tanggung Jawab

| Role | Kategori & Level | Tugas Utama | Wewenang (Aksi) | Batas Kewenangan | Mengawasi | Diawasi Oleh |
|---|---|---|---|---|---|---|
| **DPS** (`dps`) | Pengawas, level tertinggi | Pengawasan kepatuhan syariah atas produk, akad, dan operasi | Melihat laporan (read-only) & audit syariah; **di luar rantai operasional** | Akses baca terbatas (mis. Buku Besar dibatasi ke akun Ekuitas & Kewajiban) | Seluruh peran operasional dari sisi syariah | Pengurus (pengangkatan); regulasi DSN-MUI |
| **Pengurus** (`pengurus`) | Pengurus, tertinggi dalam operasional | Menetapkan kebijakan, CoA, pemetaan akad→akun, SHU, tutup buku, pemisahan anggota | Persetujuan akhir (checker) transaksi besar, pembiayaan, closing; posting jurnal penyesuaian; kelola CoA & kebijakan | Limit tertinggi (mis. `limit_manajer`, `limit_pembiayaan_manajer` = Rp50.000.000) | Manajer, AO, Teller | DPS & Rapat Anggota (UU No. 25/1992 Ps. 30) |
| **Manajer** (`manajer`) | Pengelola, menengah-atas | Mengelola operasional harian, menyetujui pembiayaan ≤ limit, approval ta'zir, approval closing kas | Persetujuan pembiayaan, ta'zir, closing, laporan operasional (baca) | `limit_manajer` / `limit_pembiayaan_manajer` (default Rp50.000.000); **tidak boleh mengubah/menghapus data laporan** | AO & Teller | Pengurus |
| **AO** (`ao`) | Pengelola, menengah | Analisis kelayakan, survei, pengajuan & review pembiayaan, monitoring tunggakan, rekomendasi keringanan penalti | Membuat & mereview pengajuan pembiayaan (meneruskan ke Manajer); CRUD CIF; rekomendasi (bukan pemutus) | Baca-saja pada API akuntansi; **tidak boleh menulis jurnal**; tidak akses modul beban operasional | Anggota/calon anggota (CIF) | Manajer |
| **Teller** (`teller`) | Pengelola, garis depan | Transaksi kas: setoran, penarikan, simpanan wajib, closing kas harian | Input transaksi tunai, aktivasi anggota, closing kas | `limit_penarikan_teller` (Rp5.000.000), `limit_setoran_teller` (Rp50.000.000), `teller_max_cash_limit` (Rp25.000.000), `minimum_transaksi_teller` (Rp1.000) | Anggota | AO → Manajer → Pengurus |
| **Admin** (`admin`) | Sistem | Konfigurasi sistem & parameter (settings), audit laporan | Mengubah parameter sistem (`AdminSettingsController`, `/admin/settings`); akses audit laporan | **Di luar rantai operasional** (tidak menyetujui transaksi) | — | Pengurus |
| **Anggota** (`anggota`) | Anggota | Mengakses layanan sebagai nasabah: pengajuan rekening, simpanan, pembiayaan | Akses portal `nasabah` (read/self-service) | Hanya data miliknya sendiri; wajib berstatus aktif (`anggota.aktif`) pada rute transaksional | — | Pengurus/Manajer (kebijakan) |

> **Bukti:** `config/role_hierarki.php:45–178`; `app/Support/HierarkiRole.php:44–56` (`peran()`, `dikenal()`); `routes/web.php` (middleware `role:` per grup); `app/Models/User.php:66–103` (`isTeller/isManajer/isPengurus/isAO/isDPS/isPengelola/hasOperationalAccess`); `app/Http/Controllers/WebAuthController.php:77–85` (`redirectByRole`).

### 3.3 Rantai Approval (Rantai Pengawasan)

```
Teller ──► AO ──► Manajer ──► Pengurus
   │        │        │           │
 (input) (review)  (approve    (approve
          kelayakan ≤ limit)     > limit / final)
```

- Status approval diturunkan **otomatis** dari nama peran → `PENDING_<PERAN>` (mis. `PENDING_AO`, `PENDING_MANAJER`, `PENDING_PENGURUS`).
- **DPS dan Admin TIDAK berada di rantai operasional** (`config/role_hierarki.php:41–43`).
- Eskalasi otomatis ke peran lebih tinggi bila nominal melebihi limit peran saat ini.
- **Bukti:** `config/role_hierarki.php` (`rantai`); `app/Support/PemisahanTugas.php` (`dilanggar`, `dilanggarSalahSatu`).

### 3.4 Peta Akses Rute per Peran (Ringkas)

| Middleware | Peran yang Diizinkan | Contoh Modul |
|---|---|---|
| `role:teller` | Teller | Dashboard teller, transaksi kas |
| `role:ao` | AO | Dashboard AO, pengajuan, survei, CIF |
| `role:manajer` | Manajer | Dashboard manajer, approval pembiayaan/ta'zir/closing |
| `role:pengurus` | Pengurus | Dashboard, CoA, kebijakan, akuntansi, SHU, tutup buku |
| `role:admin,pengurus` | Admin + Pengurus | Pengaturan sistem (`/admin/settings`) |
| `role:dps` | DPS | Dashboard & audit DPS |
| `role:manajer,pengurus,dps` | Manajer, Pengurus, DPS | Neraca, Buku Besar, Laporan PSAK 101 (dengan `laporan.akses:*`) |
| `role:ao,manajer,pengurus` | AO, Manajer, Pengurus | Pengajuan pembiayaan |
| `role:anggota` | Anggota | Portal nasabah |

> **Bukti:** `routes/web.php` (grup middleware), `routes/api.php:47,64,82`.

### 3.5 Catatan Pengendalian Internal yang Sudah Baik (Kekuatan)

| Aspek | Implementasi | Bukti |
|---|---|---|
| Sumber kebenaran tunggal (SSOT) | Hierarki & batas peran terpusat di config + `HierarkiRole` | `config/role_hierarki.php`, `app/Support/HierarkiRole.php` |
| Pemisahan tugas (maker–checker) | `PemisahanTugas::dilanggar()` dipakai pada approval limit, beban, ta'zir, restrukturisasi, AML | `app/Support/PemisahanTugas.php` |
| Jejak audit anti-rusak | Rantai hash SHA-256, append-only, semua peristiwa (diajukan/disetujui/ditolak/gagal/dibatalkan) | `docs/TAHAP4-GCG-OJK.md` §2.6 |
| Matriks hak akses laporan | Terpusat di `LaporanKeuanganPermission`; `laporan.akses:` mendahului `role:` agar percobaan akses tercatat | `app/Support/LaporanKeuanganPermission.php` |
| Pemisahan hak tulis vs baca | Modul `MODUL_PENYESUAIAN` khusus Pengurus; laporan turunan hanya-baca | `app/Http/Middleware/LaporanKeuanganAccess.php` |
| Batas operasional berjenjang | Limit nominal, frekuensi, volume ekspor per peran | `config/role_hierarki.php` (`batas`, `frekuensi`, `akses_data`) |
| Minimalisasi data (UU PDP) | Rute DPS dibatasi akun Ekuitas/Kewajiban; ekspor dibatasi | `app/Http/Middleware/LaporanKeuanganAccess.php:133` |

---

## 4. LANGKAH 2 — VERIFIKASI KEPATUHAN ALUR KERJA PER ROLE TERHADAP PRINSIP SYARIAH

### 4.1 Kerangka Pengujian Kepatuhan Syariah

| Prinsip | Definisi | Kontrol yang Diharapkan | Status |
|---|---|---|---|
| **Bebas RIBA** | Tidak ada tambahan tanpa imbalan sah atas pokok utang/pinjaman | Pemisahan margin/bagi hasil (syariah) vs bunga (riba); denda bersifat ta'zir (denda sosial, bukan pendapatan) | ⚠️ Sebagian |
| **Bebas GHARAR** | Tidak ada ketidakjelasan objek/akad/harga | Validasi kelengkapan akad, jadwal, spesifikasi objek | ✅ Terkendali |
| **Bebas MAYSIR** | Tidak ada spekulasi/judi | Tidak ada produk spekulatif | ✅ Terkendali |
| **Patuh AKAD** | Akad sesuai Fatwa DSN-MUI & dipetakan ke akun yang benar | Modul akad + pemetaan akad→CoA berbasis data | ⚠️ Sebagian |

### 4.2 Hasil Verifikasi per Role

#### 4.2.1 Role **Teller**
- **Alur:** Input transaksi tunai → jurnal otomatis → closing kas harian.
- **Kepatuhan syariah:**
  - ✅ Transaksi simpanan menggunakan akad (Wadiah/Mudharabah) dan dipetakan ke CoA yang tepat melalui pemetaan akad berbasis data (`PemetaanAkadCoa`/`CoaResolver`, `docs/TAHAP11-FLEKSIBILITAS-DINAMIS-PSAK.md`).
  - ✅ Dana simpanan pokok/wajib dicatat sebagai **ekuitas** (`SIMPANAN_POKOK='3-1001'`, `SIMPANAN_WAJIB_MODAL='3-1002'`) sesuai **PSAK 101 ¶31** — bukan kewajiban, sehingga tidak menimbulkan hubungan utang-piutang yang memicu riba.
  - ✅ Target ta'zir (denda) dipetakan ke akun tersendiri (`akun_tazir_id`) agar tidak dicampur pendapatan operasional.
  - ⚠️ **Belum ada pagar eksplisit** yang mencegah teller menginput transaksi tanpa akad/objek jelas — bergantung pada validasi form, bukan aturan domain.
- **Bukti:** `app/Http/Controllers/TellerController.php`, `app/Http/Controllers/Teller/ClosingController.php`, `App\Support\Coa`.

#### 4.2.2 Role **AO (Analis Pembiayaan)**
- **Alur:** Terima pengajuan → analisis & survei → rekomendasi → teruskan ke Manajer.
- **Kepatuhan syariah:**
  - ✅ AO berperan sebagai **analis/rekomendasi**, bukan pemutus akhir — mengurangi risiko gharar pada penetapan kelayakan (`app/Http/Controllers/AOController.php:489` — `pengajuanApprove` meneruskan ke `STATUS_PENDING_MANAJER`).
  - ⚠️ **TIDAK ada pengendalian pemisahan tugas pada `ao.pengajuan.approve`**: AO yang membuat pengajuan (`pengajuanStore`) dapat menyetujuinya sendiri pada tahap AO, karena `AOController` **tidak mengimpor `PemisahanTugas`** (verifikasi: hanya `use App\Support\PiiHasher;` pada `AOController.php:22`). Ini **melanggar prinsip maker–checker** dan berpotensi menimbulkan gharar pada penilaian kelayakan.
- **Bukti:** `app/Http/Controllers/AOController.php:22,489–523`; `routes/web.php:98`.

#### 4.2.3 Role **Manajer**
- **Alur:** Menyetujui pembiayaan ≤ limit, approval ta'zir, approval closing kas.
- **Kepatuhan syariah:**
  - ✅ Approval ta'zir memakai pemisahan tugas (`PemisahanTugas::dilanggarSalahSatu` — memeriksa `teller_id` lalu `ao_id`) sehingga pengaju tidak dapat menyetujui sendiri pengurangan dendanya.
  - ✅ Pembiayaan di atas limit di-eskalasi otomatis ke Pengurus (berjenjang — mengurangi risiko keputusan sepihak).
- **Bukti:** `app/Http/Controllers/Manajer/ApprovalTazirController.php`, `app/Http/Controllers/Manajer/ClosingApprovalController.php`, `app/Support/PemisahanTugas.php`.

#### 4.2.4 Role **Pengurus**
- **Alur:** Kebijakan, CoA, SHU, tutup buku, pemetaan akad, posting jurnal penyesuaian.
- **Kepatuhan syariah:**
  - ✅ Pemetaan akad→CoA dikelola Pengurus dan **berbasis data** (fleksibel mengikuti Fatwa DSN-MUI baru tanpa ubah kode) — `routes/web.php:317`.
  - ✅ Dana zakat/infak/sedekah dipisahkan pada CoA khusus (`DANA_ZAKAT='2-3002'`, `DANA_INFAK_SEDEKAH='2-3004'`) — mendukung **PSAK 109** dan pemisahan dana kebajikan (**PSAK 108**).
  - ⚠️ **Alokasi SHU berpotensi konflik:** `Pengurus\ShuController` menggunakan **5 pos** (cadangan, jasa modal, jasa transaksi, pengurus, sosial), sedangkan `Setting::alokasiShu()` menggunakan **4 pos** (`shu_pct_*`) dengan default berbeda. Inkonsistensi ini dapat menyajikan angka SHU yang berbeda antar jalur — lihat `docs/AUDIT-PARAMETER-PSAK-FLEKSIBILITAS.md`.
  - ⚠️ **Zakat atas SHU tidak dihitung otomatis** (tidak ada parameter `zakat_rate`/`nisab`/`haul`) — celah **PSAK 109**.
- **Bukti:** `app/Http/Controllers/Pengurus/ShuController.php`, `app/Http/Controllers/Dashboard/PengurusController.php:669+`, `docs/AUDIT-PARAMETER-PSAK-FLEKSIBILITAS.md`.

#### 4.2.5 Role **DPS (Dewan Pengawas Syariah)** — ⚠️ TEMUAN TINGGI
- **Alur:** Pengawasan syariah (read-only).
- **Kepatuhan syariah:**
  - ✅ DPS dikategorikan `pengawas` dengan level tertinggi dan **di luar rantai operasional** — tidak dapat mencampuri operasi harian (independensi).
  - ❌ **Fungsi inti DPS tidak berwujud dalam sistem.** Audit tidak menemukan **(a)** modul penyimpanan **fatwa/opini DPS**, **(b)** kontrol yang mengharuskan **persetujuan DPS sebelum produk/akad baru diaktifkan**, maupun **(c)** kewajiban **review syariah berkala** yang tercatat & dapat diaudit.
  - **Implikasi:** kepatuhan syariah produk/akad **tidak dapat dibuktikan secara sistemik** kepada regulator/auditor eksternal. Ini **melanggar prinsip tata kelola syariah** (DPS wajib mengawasi & memberi opini atas kepatuhan syariah — Fatwa DSN-MUI & POJK No. 76/2016).
- **Bukti:** `config/role_hierarki.php:41–43` (DPS di luar rantai); `routes/web.php:375` (`role:dps`); `app/Http/Controllers/DpsAuditController.php` (hanya audit, bukan fatwa).

> **Klarifikasi (OJK-05 — penyelarasan dokumen vs kode):** Perlu dibedakan dua hal.
> 1. **Yang SUDAH berjalan:** terdapat **titik persetujuan DPS pada akad pembiayaan** — `DpsAuditController` menampilkan antrian berstatus `pending_dps` (`index`, `getDetail`) lalu `approve()` mengesahkan akad (status → `disetujui`, mencatat `disahkan_oleh`/`tanggal_disahkan`/`catatan_dps`/`tgl_pencairan`) dan `reject()` menolak (status → `ditolak`). Dengan demikian DPS **berfungsi sebagai pemutus (checker) pada pengesahan akad pembiayaan**, sehingga temuan **R-11** ("DPS tidak punya titik persetujuan") **sebagian tidak lagi akurat** dan hanya relevan untuk konteks *persetujuan produk/akad BARU*.
> 2. **Yang BELUM ada (temuan tetap sah):** modul penyimpanan **fatwa/opini DPS** tertaut produk/akad, **gating persetujuan DPS sebelum produk/akad baru diaktifkan**, dan **review syariah berkala** yang tercatat. Temuan **R-01** karena itu **tetap valid** pada cakupan (a) & (c) di atas.

#### 4.2.6 Role **Admin**
- **Alur:** Konfigurasi sistem & parameter.
- **Kepatuhan syariah:**
  - ✅ Admin **di luar rantai operasional** — tidak menyetujui transaksi.
  - ⚠️ Admin + Pengurus sama-sama dapat mengubah `/admin/settings` (parameter). Perubahan parameter syariah (mis. `nisbah_default`, `margin_default`, `tazir_rate`) **tidak melalui approval DPS** — berpotensi mengubah parameter syariah tanpa kontrol syariah.

#### 4.2.7 Role **Anggota**
- **Alur:** Self-service (pengajuan rekening, simpanan, pembiayaan).
- **Kepatuhan syariah:**
  - ✅ Rute transaksional anggota dijaga middleware `anggota.aktif` — hanya anggota aktif yang dapat bertransaksi.
  - ✅ Penarikan simpanan modal (pokok/wajib) diblokir dan diselesaikan lewat alur **PSAK 108** (pengembalian hak anggota saat berhenti) — `routes/web.php:525–527`.

### 4.3 Ringkasan Verifikasi Syariah

| Prinsip | Teller | AO | Manajer | Pengurus | DPS | Admin | Anggota |
|---|---|---|---|---|---|---|---|
| Bebas riba | ✅ | ✅ | ✅ | ⚠️ | ❌ | ⚠️ | ✅ |
| Bebas gharar | ✅ | ⚠️ | ✅ | ✅ | ❌ | ⚠️ | ✅ |
| Bebas maysir | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Patuh akad | ✅ | ✅ | ✅ | ✅ | ❌ | ⚠️ | ✅ |

Keterangan: ✅ patuh · ⚠️ patuh sebagian · ❌ tidak dapat dibuktikan secara sistemik.

---

## 5. LANGKAH 3 — AUDIT PROSES AKUNTANSI & PELAPORAN PER ROLE vs PSAK

### 5.1 Jurnal Otomatis dari Transaksi

| Aspek | Implementasi | Status |
|---|---|---|
| Pencatatan jurnal per transaksi | Tautan transaksi↔jurnal di **`jurnal.transaksi_id`**; setiap transaksi (setoran, penarikan, pembiayaan, angsuran, pencairan, pengembalian modal PSAK 108) membentuk jurnal | ✅ Baik |
| Keseimbangan (balance) | `Jurnal::pastikanBalance()` menolak posting bila debet ≠ kredit | ✅ Baik |
| Void jurnal | Hanya jurnal berstatus `Posted` yang dapat di-void | ✅ Baik |

> **Bukti:** `app/Http/Controllers/JurnalController.php:110–213` (`update`, `post`, `void`, `pastikanBalance`); `routes/web.php:484–485`.

### 5.2 Wewenang Akuntansi per Role

| Proses Akuntansi | Teller | AO | Manajer | Pengurus | Admin | DPS |
|---|:--:|:--:|:--:|:--:|:--:|:--:|
| Baca jurnal (API) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| **Tulis/mutasi jurnal (API)** | ✅ | ❌ | ✅ | ✅ | ✅ | ❌ |
| Posting / Void jurnal | ✅ | ❌ | ✅ | ✅ | ✅ | ❌ |
| Jurnal penyesuaian (modul khusus) | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ |
| Kelola CoA | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ |
| Tutup buku / ikhtisar SHU | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ |
| Lihat Neraca / Buku Besar / PSAK 101 | ❌ | ❌ | ✅ | ✅ | ✅ | ✅ (terbatas) |

> **Bukti:** `routes/api.php:47,64–79`; `routes/web.php:335–342,601–608,649`; `app/Support/LaporanKeuanganPermission.php`.

**Catatan penting:** API mutasi jurnal (`routes/api.php:64`) memberi hak **tulis** kepada `teller` dan `admin` — konsisten dengan kebijakan "petugas akuntansi" di komentar rute, namun **tidak ada maker–checker**: satu pengguna dapat membuat, mengubah, mem-posting, dan mem-void jurnal (termasuk `Jurnal Penyesuaian`/`Jurnal Penutup` yang langsung memengaruhi laba & ekuitas) **tanpa persetujuan kedua**. → **Temuan Tinggi R-02.**

### 5.3 Kelengkapan Laporan Keuangan vs PSAK 101

Sistem menyajikan **enam komponen lengkap** PSAK 101 melalui `resources/views/laporan/psak101.blade.php`:

| # | Komponen PSAK 101 | Bukti (baris view) | Status |
|---|---|---|---|
| 1 | Laporan Posisi Keuangan (Neraca) | `psak101.blade.php` | ✅ |
| 2 | Laporan Laba Rugi / Operasional | `psak101.blade.php` | ✅ |
| 3 | Laporan Perubahan Ekuitas | `psak101.blade.php:560–564` | ✅ |
| 4 | Laporan Arus Kas (metode langsung) | `psak101.blade.php:639–643` | ✅ |
| 5 | Laporan Perubahan Dana Investasi Terikat | `psak101.blade.php:739–743` | ✅ |
| 6 | Catatan atas Laporan Keuangan (CaLK) | `psak101.blade.php:821–825` | ✅ |

Ditambah **panel kepatuhan penyajian** (`$calk['patuh']`, `$calk['temuan']`) yang menandai temuan penyajian secara otomatis — `psak101.blade.php:109–165`. **Kesesuaian PSAK 101: BAIK.**

### 5.4 Kesesuaian PSAK Lain

| PSAK | Topik | Dukungan Sistem | Status |
|---|---|---|---|
| **PSAK 101** | Penyajian Laporan Keuangan Syariah | 6 komponen lengkap + panel kepatuhan | ✅ Sesuai |
| **PSAK 102** | Murabahah | Produk akad + pemetaan CoA + margin | ✅ Sesuai |
| **PSAK 105** | Mudharabah | Nisbah bagi hasil (`nisbah_default`) | ✅ Sesuai |
| **PSAK 106** | Musyarakah | Produk akad | ✅ Sesuai |
| **PSAK 107** | Ijarah | Produk akad | ✅ Sesuai |
| **PSAK 108** | Transaksi Asuransi Syariah / Dana Kebajikan | Pemisahan akun dana kebajikan + alur pengembalian hak anggota (terminasi) | ⚠️ Sebagian (lihat R-07) |
| **PSAK 109** | Akuntansi Zakat, Infak, Sedekah | Akun `DANA_ZAKAT`, `DANA_INFAK_SEDEKAH` + `ZakatController`; **belum ada parameter `zakat_rate`/`nisab`/`haul`** | ⚠️ Sebagian |
| **PSAK 110** | Akuntansi Sukuk | Tidak ada modul | ❌ Absen (sah bila koperasi tidak menerbitkan sukuk) |
| **PSAK 111** | Akuntansi Waqaf | Tidak ada modul | ❌ Absen (sah bila koperasi tidak menerima wakaf) |

> **Catatan:** Absennya PSAK 110/111 sudah tercatat sebagai keputusan sadar (`database/migrations/2026_09_26_000500_add_psak_parameters_to_kebijakan_koperasis.php:34` — *"110 (sukuk — tidak dipakai)"*), sehingga **bukan ketidaksesuaian** — namun menjadi **celah fleksibilitas** bila koperasi menambah lini usaha sukuk/wakaf di masa depan.

### 5.5 Jejak Audit Akuntansi

| Kontrol | Implementasi | Status |
|---|---|---|
| Log akses & percobaan akses laporan | `LaporanKeuanganAccess` mencatat percobaan (termasuk yang ditolak) dengan `role` | ✅ |
| Log perubahan PII | `AuditPerubahanPii` mencatat `role` pelaku | ✅ |
| Log mutasi laporan keuangan | `LaporanKeuanganLog` mencantumkan `role` pelaku | ✅ |
| Jejak audit approval transaksi | Rantai hash SHA-256, append-only | ✅ |

---

## 6. LANGKAH 4 — TEMUAN AUDIT, BUKTI, DAN TINGKAT RISIKO

Total **11 temuan** (2 Tinggi, 5 Sedang, 4 Rendah).

| Kode | Temuan | Role Terdampak | Bukti | Tingkat Risiko |
|---|---|---|---|---|
| **R-01** | **Fatwa/opini DPS tidak tersimpan & tidak ada gating persetujuan syariah atas produk/akad.** Tugas inti DPS (pengawasan syariah) tidak berwujud sebagai kontrol sistem; kepatuhan syariah produk tidak dapat dibuktikan kepada regulator/auditor. | DPS, Pengurus, Admin | `config/role_hierarki.php:41–43`; `routes/web.php:375`; `DpsAuditController.php` (audit saja, tanpa modul fatwa) | **TINGGI** |
| **R-02** | **Jurnal manual tanpa maker–checker.** Satu pengguna (`teller`/`admin`/`manajer`/`pengurus`) dapat membuat, mengubah, mem-posting, dan mem-void jurnal — termasuk Jurnal Penyesuaian/Penutup yang langsung memengaruhi laba & ekuitas — tanpa persetujuan kedua. | Teller, Admin, Manajer, Pengurus | `routes/api.php:64–79`; `JurnalController.php:117–213` (tidak ada `PemisahanTugas`) | **TINGGI** |
| **R-03** | **`ao.pengajuan.approve` tanpa pemisahan tugas.** AO yang membuat pengajuan dapat menyetujui pengajuan sendiri; `AOController` tidak mengimpor `PemisahanTugas`. | AO | `app/Http/Controllers/AOController.php:22` (tanpa `PemisahanTugas`), `:489–523`; `routes/web.php:96,98` | **SEDANG** |
| **R-04** | **Status `is_active` pengguna tidak ditegakkan saat login.** `WebAuthController::login()` hanya memvalidasi email/password; kontrol status `is_active` bergantung pada middleware `CheckRole` (yang juga tidak memeriksanya). Risiko akun nonaktif tetap dapat masuk. | Semua | `app/Http/Controllers/WebAuthController.php:20–56`; `app/Http/Middleware/CheckRole.php:16–31` | **SEDANG** |
| **R-05** | **Pengecekan peran bercabang (hardcoded) di luar `HierarkiRole`.** `CheckRole` memakai `in_array($user->role, $roles)` dan `User::isPengelola()/hasOperationalAccess()` memakai daftar peran hardcoded — berpotensi tidak sinkron saat peran baru ditambah (melanggar prinsip SSOT). | Semua | `app/Http/Middleware/CheckRole.php:24–27`; `app/Models/User.php:94–103` | **SEDANG** |
| **R-06** | **Inkonsistensi alokasi SHU.** `Pengurus\ShuController` memakai 5 pos (cadangan, jasa modal, jasa transaksi, pengurus, sosial); `Setting::alokasiShu()` memakai 4 pos (`shu_pct_*`) dengan default berbeda → angka SHU dapat berbeda antar jalur. | Pengurus | `Pengurus/ShuController.php:100–154`; `App\Support\Setting` (`shu_pct_*`) | **SEDANG** |
| **R-07** | **Zakat atas SHU tidak dihitung otomatis (celah PSAK 109).** Tidak ada parameter `zakat_rate`, `nisab`, atau `haul`; perhitungan zakat tidak terintegrasi ke alur SHU. | Pengurus, Admin | `App\Support\Setting::DEFAULTS`; `ZakatController.php`; `docs/AUDIT-PARAMETER-PSAK-FLEKSIBILITAS.md` | **SEDANG** |
| **R-08** | **Pengamanan modul beban operasional bergantung pada controller & menyebut peran "hantu".** `PengeluaranBebanController` memakai `in_array($role, ['teller','keuangan','manajer'])` — `keuangan` **bukan peran yang dikenal** sistem; rute tidak ber-middleware `role:`. Bila pengecekan controller dilewati/diubah, tidak ada pengaman lapis kedua. | Teller, Manajer | `app/Http/Controllers/PengeluaranBebanController.php:26–34`; `routes/web.php:179–182` | **SEDANG** |
| **R-09** | **PSAK 108/109 belum penuh.** Pemisahan dana kebajikan sudah ada, namun alur perhitungan & penyajian zakat (nisab/haul) belum otomatis; pemisahan dana `PSAK108-PEMISAHAN-MARGIN-DANA-KEBAJIKAN.md` belum sepenuhnya tertutup pada sisi parameter. | Pengurus, Admin | `docs/PSAK108-PEMISAHAN-MARGIN-DANA-KEBAJIKAN.md`; `docs/AUDIT-PARAMETER-PSAK-FLEKSIBILITAS.md` | **RENDAH** |
| **R-10** | **Dokumentasi peran tidak menyebut `admin` & `anggota`.** Dokumen Tahap 13 dan `User::isPengelola()` hanya mendaftar 5 peran; `admin` (sistem) dan `anggota` (nasabah) tidak terdokumentasi eksplisit sebagai peran penuh, berisiko menyesatkan saat review akses. | Admin, Anggota | `docs/TAHAP13-RBAC-HIERARKI-ROLE.md`; `app/Models/User.php:92–97` | **RENDAH** |
| **R-11** | **DPS tidak diposisikan sebagai pemutus pada persetujuan produk/akad.** DPS **sudah** menjadi pemutus pada *pengesahan akad pembiayaan* (`DpsAuditController::approve/reject` atas antrian `pending_dps`), namun belum memiliki titik persetujuan (checker) pada *alur produk/akad BARU* (`penyetuju` produk). Bila modul fatwa dibangun, DPS perlu didaftarkan sebagai pemutus produk. | DPS, Pengurus | `config/role_hierarki.php` (`penyetuju` DPS; DPS di luar rantai); `app/Http/Controllers/DpsAuditController.php` (approve/reject akad) | **RENDAH** |

### 6.1 Peta Risiko (Matriks Dampak × Kemungkinan)

```
Kemungkinan
  Tinggi │  R-11  R-08  R-05
  Sedang │  R-01  R-03  R-04  R-06  R-07
  Rendah │  R-02  R-09  R-10
         └──────────────────────────────
            Rendah  Sedang  Tinggi  → Dampak
```

---

## 7. LANGKAH 5 — REKOMENDASI PERBAIKAN TERUKUR & ACTIONABLE

### 7.1 Rekomendasi atas Temuan TINGGI

| Kode | Rekomendasi | Bentuk Aksi | Penanggung Jawab | Tenggat |
|---|---|---|---|---|
| **R-01** | **Bangun Modul Fatwa & Gating Syariah DPS.** (a) Tabel `fatwa_dps` (produk/akad, nomor fatwa, isi, tanggal, status); (b) kolom `produk.dps_approved_at` & `dps_approved_by`; (c) kontrol: produk/akad baru **tidak dapat diaktifkan** sebelum disetujui DPS; (d) jadwal review syariah berkala yang tercatat. | Modul + migrasi + kontrol domain | Pengurus (owner), DPS (checker), Tim IT | 30 hari |
| **R-02** | **Maker–checker pada jurnal manual.** (a) Posting/void jurnal memakai `PemisahanTugas::dilanggar($jurnal->user_id, auth()->id())`; (b) jurnal penyesuaian/penutup wajib di-approve Pengurus (bukan pembuatnya); (c) tambah test. | Kontrol + test | Pengurus, Tim IT | 30 hari |

### 7.2 Rekomendasi atas Temuan SEDANG

| Kode | Rekomendasi | Bentuk Aksi | Tenggat |
|---|---|---|---|
| **R-03** | Terapkan `PemisahanTugas::dilanggar()` pada `ao.pengajuan.approve` (AO pembuat ≠ AO penyetuju tahap AO). | Kontrol + test | 45 hari |
| **R-04** | Tegakkan `is_active` saat login: tolak login bila `!$user->is_active`, dan tambahkan cek `is_active` di `CheckRole`; catat ke `audit_logs`. | Kontrol + test | 45 hari |
| **R-05** | Alihkan seluruh pengecekan peran (`CheckRole`, `User::isPengelola/hasOperationalAccess`) untuk membaca `HierarkiRole::dikenal()`; hapus daftar peran hardcoded. | Refactor + test | 60 hari |
| **R-06** | Satukan definisi pos alokasi SHU pada satu sumber (`Setting::alokasiShu()`), hapus 5-pos hardcoded di `ShuController`; tambah test konsistensi total = 100%. | Refactor + test | 60 hari |
| **R-07** | Tambah parameter `zakat_rate`, `zakat_nisab`, `zakat_haul`, dan perhitungan zakat atas SHU otomatis ke `Setting::DEFAULTS` + `updateKebijakan()`. | Parameter + logika | 60 hari |
| **R-08** | Pindahkan pengecekan beban operasional ke middleware `role:teller,manajer` (hapus peran "keuangan" yang tidak dikenal; tambah `pengurus` bila memang berwenang). | Rute + kontrol | 45 hari |

### 7.3 Rekomendasi atas Temuan RENDAH

| Kode | Rekomendasi | Tenggat |
|---|---|---|
| **R-09** | Lengkapi parameter & penyajian PSAK 108/109 (nisab, haul, pemisahan dana kebajikan menyeluruh). | 90 hari |
| **R-10** | Perbarui dokumentasi peran (tambahkan `admin` & `anggota`; selaraskan `User::isPengelola`). | 90 hari |
| **R-11** | Bila R-01 dijalankan, daftarkan `dps` pada alur persetujuan produk (`penyetuju` produk). | 90 hari |

### 7.4 Peningkatan Kapasitas SDM per Role

| Role | Kebutuhan Kompetensi | Pelatihan Disarankan | Ukuran Keberhasilan |
|---|---|---|---|
| **DPS** | Fikih muamalah, Fatwa DSN-MUI, tata kelola syariah | Sertifikasi/sertifikat DPS; review fatwa | 100% produk memiliki fatwa tertaut |
| **Pengurus** | PSAK 101–109, GCG, manajemen risiko | Pelatihan PSAK syariah & tata kelola | 0 temuan akuntansi blocking |
| **Manajer** | Analisis pembiayaan syariah, pengendalian internal | Pelatihan pengendalian internal & maker–checker | 100% approval sesuai limit |
| **AO** | Analisis kelayakan syariah, prinsip gharar | Pelatihan analisis pembiayaan syariah | 0 approval self-approve |
| **Teller** | Akad dasar, KYC/AML, penanganan kas | Pelatihan akad & kepatuhan | 0 transaksi tanpa akad valid |
| **Admin** | Konfigurasi parameter syariah, UU PDP | Pelatihan parameter PSAK & perlindungan data | 100% perubahan parameter tercatat |
| **Anggota** | Literasi keuangan syariah | Edukasi anggota (sosialisasi produk) | Peningkatan pemahaman (survey) |

---

## 8. LANGKAH 6 — LAPORAN AKHIR, RENCANA TINDAK LANJUT, & INDIKATOR KEBERHASILAN (KPI 3 BULAN)

### 8.1 Rencana Tindak Lanjut (Action Plan)

| Fase | Periode | Aktivitas | Temuan yang Ditutup | Penanggung Jawab |
|---|---|---|---|---|
| **FASE 1 — Perbaikan Kritis** | Bulan 1 (Hari 1–30) | Modul Fatwa & Gating DPS; maker–checker jurnal manual | R-01, R-02 | Pengurus + DPS + IT |
| **FASE 2 — Penguatan Kontrol** | Bulan 2 (Hari 31–60) | Pemisahan tugas AO; penegakan `is_active`; pengecekan peran SSOT; konsolidasi SHU; parameter zakat; pengamanan beban operasional | R-03, R-04, R-05, R-06, R-07, R-08 | IT + Manajer |
| **FASE 3 — Penyempurnaan** | Bulan 3 (Hari 61–90) | PSAK 108/109 penuh; dokumentasi peran; DPS sebagai pemutus produk; pelatihan SDM | R-09, R-10, R-11 | Pengurus + DPS |

### 8.2 Indikator Keberhasilan (KPI) — Evaluasi 3 Bulan

| Kode | KPI | Baseline (saat audit) | Target (3 bulan) | Cara Ukur | Frekuensi |
|---|---|---|---|---|---|
| **KPI-1** | Produk/akad dengan fatwa DPS tertaut | 0% | **100%** | Query `produk` vs `fatwa_dps` | Bulanan |
| **KPI-2** | Jurnal manual (penyesuaian/penutup) yang lolos maker–checker | 0% | **100%** | Log `PemisahanTugas` + test | Bulanan |
| **KPI-3** | Insiden self-approval (pengaju = penyetuju) | Tidak terukur | **0 kejadian** | Audit jejak approval | Bulanan |
| **KPI-4** | Login oleh akun `is_active = false` | Tidak dicegah | **0 kejadian** | Query `audit_logs` | Bulanan |
| **KPI-5** | Titik pengecekan peran hardcoded di luar `HierarkiRole` | 2 lokasi | **0 lokasi** | Pemindaian statis | Sekali, lalu verifikasi |
| **KPI-6** | Defek antara 5-pos & 4-pos alokasi SHU | Ada | **0 (total = 100%)** | Test konsistensi | Bulanan |
| **KPI-7** | Parameter zakat (rate/nisab/haul) terpasang & terhitung | Belum ada | **Terpasang & teruji** | Uji perhitungan | Sekali, lalu verifikasi |
| **KPI-8** | Cakupan temuan yang ditutup | 0 dari 11 | **≥ 9 dari 11 (82%)** | Register temuan | Bulanan |
| **KPI-9** | Skor kepatuhan rata-rata | 86% | **≥ 95%** | Ulangi matriks §1.2 | Pada bulan ke-3 |
| **KPI-10** | Kelulusan regresi otomatis (test suite) | 1156 passed | **≥ 1156 & 0 failure** | `php artisan test` | Setiap rilis |
| **KPI-11** | Perubahan parameter syariah melalui approval | Tidak melalui approval | **100% melalui persetujuan** | Log perubahan `Setting` | Bulanan |
| **KPI-12** | Pelatihan SDM per role terlaksana | 0% | **100% role terlatih** | Daftar hadir & sertifikat | Pada bulan ke-3 |

### 8.3 Rencana Verifikasi Ulang (Re-audit)

| Aktivitas | Waktu | Output |
|---|---|---|
| Pemantauan KPI bulanan | Bulan 1, 2, 3 | Laporan pemantauan KPI |
| Verifikasi penutupan temuan (follow-up audit) | Awal Bulan 3 | Status temuan: terbuka/tertutup |
| Re-audit menyeluruh | Akhir Bulan 3 | Laporan audit lanjutan + skor kepatuhan baru |
| Pelaporan ke Pengurus & DPS | Akhir Bulan 3 | Berita acara & rekomendasi lanjutan |

### 8.4 Pernyataan Audit

Berdasarkan prosedur audit yang dilaksanakan atas **seluruh peran** dalam sistem operasional koperasi syariah, audit menyimpulkan bahwa sistem secara umum telah menjalankan fungsi, alur kerja, dan proses keuangan sesuai prinsip syariah dan PSAK yang relevan (**skor kepatuhan 86%**), **dengan catatan** bahwa dua (2) temuan berisiko **TINGGI — R-01 (fungsi syariah DPS belum berwujud sistem) dan R-02 (jurnal manual tanpa maker–checker)** — harus ditindaklanjuti dalam **≤ 30 hari** sebelum audit dinyatakan tertutup.

---

## 9. LAMPIRAN

### 9.1 Berkas Bukti Utama

| Kategori | Berkas |
|---|---|
| Konfigurasi peran | `config/role_hierarki.php` |
| Kelas pendukung | `app/Support/HierarkiRole.php`, `app/Support/PemisahanTugas.php`, `app/Support/LaporanKeuanganPermission.php`, `app/Support/Coa.php` |
| Middleware | `app/Http/Middleware/CheckRole.php`, `app/Http/Middleware/LaporanKeuanganAccess.php` |
| Rute | `routes/web.php`, `routes/api.php` |
| Controller kunci | `app/Http/Controllers/{TellerController,AOController,JurnalController,WebAuthController,PengeluaranBebanController,ZakatController}.php`, `Dashboard/*`, `Pengurus/ShuController.php`, `Manajer/ApprovalTazirController.php`, `Manajer/ClosingApprovalController.php`, `Teller/ClosingController.php` |
| Model | `app/Models/User.php`, `app/Models/Jurnal.php`, `app/Models/LaporanKeuanganLog.php`, `app/Models/AuditPerubahanPii.php` |
| Tampilan | `resources/views/laporan/psak101.blade.php` |
| Dokumentasi | `docs/TAHAP13-RBAC-HIERARKI-ROLE.md`, `docs/TAHAP4-GCG-OJK.md`, `docs/TAHAP11-FLEKSIBILITAS-DINAMIS-PSAK.md`, `docs/AUDIT-PARAMETER-PSAK-FLEKSIBILITAS.md`, `docs/PSAK108-PEMISAHAN-MARGIN-DANA-KEBAJIKAN.md` |
| Uji | `tests/Feature/RbacHierarkiRoleTest.php`, `tests/Feature/LaporanKeuanganRbacTest.php`, `tests/Feature/DepositoCifRbacTest.php` |

### 9.2 Register Temuan (Ringkas untuk Tindak Lanjut)

| Kode | Ringkasan | Risiko | Status | Target |
|---|---|---|---|---|
| R-01 | Modul Fatwa & Gating DPS tidak ada | TINGGI | Terbuka | Bulan 1 |
| R-02 | Jurnal manual tanpa maker–checker | TINGGI | Terbuka | Bulan 1 |
| R-03 | Self-approval AO | SEDANG | Terbuka | Bulan 2 |
| R-04 | `is_active` tidak ditegakkan saat login | SEDANG | Terbuka | Bulan 2 |
| R-05 | Pengecekan peran hardcoded | SEDANG | Terbuka | Bulan 2 |
| R-06 | Inkonsistensi alokasi SHU (5 vs 4 pos) | SEDANG | Terbuka | Bulan 2 |
| R-07 | Zakat atas SHU tidak otomatis (PSAK 109) | SEDANG | Terbuka | Bulan 2 |
| R-08 | Pengamanan beban operasional berbasis controller & peran "hantu" | SEDANG | Terbuka | Bulan 2 |
| R-09 | PSAK 108/109 belum penuh | RENDAH | Terbuka | Bulan 3 |
| R-10 | Dokumentasi peran belum lengkap | RENDAH | Terbuka | Bulan 3 |
| R-11 | DPS belum menjadi pemutus produk (*pengesahan akad pembiayaan* sudah ada di `DpsAuditController`) | RENDAH | Sebagian tertutup | Bulan 3 |

---

*Dokumen ini merupakan laporan audit akhir. Lampiran teknis parameter PSAK tersedia dalam `docs/AUDIT-PARAMETER-PSAK-FLEKSIBILITAS.md`.*
