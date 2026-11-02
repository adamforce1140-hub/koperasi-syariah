# DOKUMEN TEMUAN — AUDIT AKSES DASHBOARD PER PERAN

**Tanggal audit:** 2026-10-19
**Lingkup:** 9 peran terdaftar, seluruh dashboard, seluruh rute GET tanpa parameter yang dijaga middleware `izin:`
**Sifat dokumen:** bahan mentah temuan (Langkah 4). Laporan lengkap + rekomendasi ada di
[`LAPORAN-AUDIT-MENYELURUH-PERAN.md`](LAPORAN-AUDIT-MENYELURUH-PERAN.md).

---

## 1. Metode & Bukti

| Instrumen | Berkas | Hasil |
|---|---|---|
| Uji perilaku akses per peran (10 skenario, 231 assertion) | `tests/Feature/AuditAksesDashboardPerPeranTest.php` | **10 passed** |
| Sapuan seluruh rute GET per peran (202 rute × 9 peran) | `tests/Feature/AuditSapuanRutePerPeranTest.php` | 201 × 2xx/3xx, **1 × HTTP 500** |
| Rekaman hasil sapuan | `storage/app/audit_sweep_output.txt` | lampiran A |
| Audit statis (rute, view, menu, izin) | `storage/app/audit_statik_output.txt` | integritas **bersih** |
| Klasifikasi celah menu vs rute | `storage/app/audit_gap2_output.txt` | lampiran B |

**Batas metode:** pengujian memakai HTTP request nyata (bukan pembacaan kode saja) dan basis data
transaksional. Yang diukur adalah **keterjangkauan halaman** (status HTTP, render view, integritas
rute/izin). Interaksi JavaScript di dalam halaman (klik, unggah berkas, validasi klien) tidak
disimulasikan; karena itu temuan bertipe "tombol tidak berfungsi" hanya dicatat bila terbukti dari
rute/controller yang tidak ada.

---

## 2. Ringkasan Keparahan

| Keparahan | Jumlah | Kode |
|---|---|---|
| **KRITIS** — memblokir peran / error 500 | 2 | F-01, F-09 |
| **TINGGI** — akses tidak sesuai wewenang / tidak ada dashboard | 2 | F-02, F-03 |
| **SEDANG** — fitur tidak terjangkau, cakupan uji bolong, izin lintas fungsi | 7 | F-04, F-05, F-07, F-10, F-11, F-12, F-14 |
| **RENDAH** — rute mubazir, pengalihan ramah, dokumentasi | 4 | F-06, F-08, F-13, F-15 |

---

## 3. Temuan KRITIS

### F-01 — Peran `nasabah` tidak punya dashboard dan tidak punya satu pun izin

| | |
|---|---|
| **Keparahan** | KRITIS |
| **Kategori** | Ketidaksesuaian akses ↔ role |
| **Lokasi** | `config/role_hierarki.php:144-151`; `config/izin.php` (tidak ada entri `nasabah`); `app/Http/Controllers/WebAuthController.php:94-101`; `routes/web.php:37-42` |

**Fakta terukur**

- Entri `nasabah` **tidak memiliki kunci `dashboard_route`** (bandingkan `anggota` di baris 133).
- `config/izin.php` tidak memuat `nasabah` pada satu izin pun → `Izin::peranEfektif([...])` tidak pernah
  mengembalikan `nasabah`.
- Sapuan: `[nasabah] total=0` — nol rute GET dapat diakses.
- Menu peran `nasabah` berisi **6 tautan alias** ke rute milik `anggota`; keenamnya dijawab **403**.

**Akibat**

1. `redirectByRole()` menemukan `dashboard_route === null`, lalu `redirect('/')`.
2. Rute `/` memanggil `redirectByRolePublic()` untuk pengguna yang sudah login → kembali ke `/`.
3. Terjadi **redirect loop**; pengguna berperan `nasabah` tidak pernah mencapai halaman mana pun.

**Bukti uji:** `AuditAksesDashboardPerPeranTest::test_temuan_nasabah_tanpa_dashboard_dan_seluruh_menu_ditolak` (hijau).

---

### F-09 — `GET /pengelola/pengajuan-pinjaman/create` menghasilkan HTTP 500

| | |
|---|---|
| **Keparahan** | KRITIS |
| **Kategori** | Masalah teknis — method controller tidak ada |
| **Lokasi** | `routes/web.php:668` → `PengajuanPembiayaanController::create`; controller `app/Http/Controllers/PengajuanPembiayaanController.php` |

**Fakta terukur**

- Method publik yang benar-benar ada: `form`, `store`, `formBaru`, `storeBaru`, `statusPengajuan`,
  `index`, `show`, `review`, `approve`, `tolak`. **Tidak ada `create`.**
- Sapuan mengembalikan **500** untuk tiga peran sekaligus:

```
[pengurus] pengelola.pengajuan-pinjaman.create 500
[manajer]  pengelola.pengajuan-pinjaman.create 500
[ao]       pengelola.pengajuan-pinjaman.create 500
```

**Akibat:** siapa pun yang mengetik URL tersebut (atau menekan tautan lama/bookmark) menerima halaman
error 500. Rute ini juga **tidak ditautkan dari Blade mana pun** (lihat F-11/F-13 — kategori "tanpa pintu masuk").

**Catatan:** rute ini tidak boleh diarahkan ke `form()`/`formBaru()` karena kedua method itu adalah alur
**nasabah** (`Auth::user()->anggota`; gagal → `redirect()->route('dashboard.nasabah')`), bukan alur staf
`/pengelola`. Keputusan yang diperlukan: hapus rute, atau implementasikan form staf yang benar.

---

## 4. Temuan TINGGI

### F-02 — Guard dashboard tidak seragam; `admin` tidak punya dashboard eksklusif

| | |
|---|---|
| **Keparahan** | TINGGI |
| **Kategori** | Ketidaksesuaian akses ↔ role |
| **Lokasi** | `config/role_hierarki.php:124`; `app/Http/Controllers/AdminSettingsController.php:60-72`; `routes/web.php:438-441` |

**Fakta terukur**

- `dashboard_route` untuk `admin` adalah **`admin.settings.index`**, bukan `dashboard.admin`.
- `Izin::ada('dasbor.admin') === false` → tidak ada izin dasbor untuk admin.
- `AdminSettingsController::index()` merender **`view('pengurus.kebijakan')`** — view milik peran Pengurus.
- `admin.settings.update` justru menunjuk `PengurusController::updateKebijakan` (rute lintas-controller).

**Akibat:** setelah login, `admin` diarahkan ke halaman kebijakan milik Pengurus. Tidak ada ringkasan
kondisi sistem, tidak ada pemisahan tampilan, dan perubahan kebijakan tidak melewati controller admin.

---

### F-03 — `teller.loket` membuka ±45 rute loket bagi `manajer` dan `pengurus` tanpa tautan menu

| | |
|---|---|
| **Keparahan** | TINGGI |
| **Kategori** | Ketidaksesuaian akses ↔ role (over-privilege) |
| **Lokasi** | `config/izin.php:50` (`'teller.loket' => ['teller','manajer','pengurus']`); `app/Http/Middleware/PastikanKasTerbuka.php` |

**Fakta terukur**

- Sapuan: `[manajer] celah=42`, `[pengurus] celah=33` rute terizinkan tetapi tidak ada di menu.
- Contoh rute teller yang dapat dibuka manajer/pengurus: `teller.setor`, `teller.tarik`, `teller.angsuran`,
  `teller.deposito`, `teller.kas.opening`, `teller.kas.closing`, `teller.riwayat`, `teller.rekap`.
- `PastikanKasTerbuka::handle()` berbunyi `if (! $user->isTeller()) return $next($request);` → manajer/pengurus
  **lolos guard sesi kas**, padahal mereka bukan operator loket.

**Akibat:** pelanggaran pemisahan tugas (maker-checker). Peran pengawas/pengelola dapat melakukan transaksi
tunai tanpa jejak pembukaan kas teller. Tidak terlihat di UI, sehingga tidak terdeteksi lewat pemakaian normal.

---

## 5. Temuan SEDANG

### F-04 — `super_admin` dapat membuka halaman setoran angsuran operasional

| | |
|---|---|
| **Keparahan** | SEDANG |
| **Lokasi** | `config/izin.php:52`; `routes/web.php:292-298` |

`'angsuran.setoran' => ['teller','super_admin']` memberi administrator teknis (non-operasional, `penyetuju: false`)
akses ke `pengurus.angsuran.index`. Komentar di rute menyatakan pengecualian ini **disengaja**; temuan ini
dicatat sebagai risiko tata kelola, bukan bug tak sengaja.

---

### F-05 — Cakupan uji per peran tidak merata

| | |
|---|---|
| **Keparahan** | SEDANG |
| **Lokasi** | `tests/Feature/DemonstrasiPerPeranTest.php` (6 peran); `tests/Feature/PetaAksesPeranMenuTest.php` (8 peran) |

`admin`, `anggota`, dan `nasabah` tidak diuji oleh suite lama. **Sebagian dimitigasi** oleh dua instrumen audit
baru yang mencakup 9 peran, tetapi suite lama tetap memberi rasa aman palsu bila dijalankan sendiri.

---

### F-07 — Fungsi back-office dapat diakses peran di luar pemilik menu

| | |
|---|---|
| **Keparahan** | SEDANG |
| **Lokasi** | `config/izin.php:64,78`; `config/navigasi.php` |

| Peran | Rute yang dapat dibuka (200) | Menu peran tersebut |
|---|---|---|
| `admin` | `autodebit.index` | tidak ada |
| `manajer` | `zakat.index`, `zakat.laporan`, `zakat.ringkasan` | tidak ada (menu zakat ada di pengurus & dps) |
| `dps` | `laporan.ckpn` | tidak ada di menu DPS |

Fungsi dapat dijalankan tetapi tidak dapat ditemukan; sebaliknya, peran yang tidak memilikinya tetap dapat
menjalankannya lewat URL langsung.

---

### F-10 — Empat halaman `anggota` terizinkan tetapi tidak punya pintu masuk

| | |
|---|---|
| **Keparahan** | SEDANG |
| **Bukti** | `audit_gap2_output.txt` → `[anggota] tanpa-pintu=4` |

```
nasabah.pembiayaan.index
nasabah.pengajuan-pinjaman.index
nasabah.rekening.index
nasabah.status-pengajuan.index
```

Keempatnya membalas **200** untuk `anggota`, tetapi tidak ditautkan dari Blade mana pun. Fitur hidup tanpa
jalur akses; pengguna harus menebak URL.

---

### F-11 — Halaman pencairan yang disetujui tidak dapat dijangkau dari UI

| | |
|---|---|
| **Keparahan** | SEDANG |
| **Lokasi** | `routes/web.php:593-594`; `teller.pencairan-disetujui.index` |

Rute membalas **200** untuk `teller`, `manajer`, dan `pengurus`, tetapi **nol tautan** di seluruh Blade.
Fitur pencairan yang sudah disetujui tidak dapat diakses melalui navigasi normal — hanya lewat URL manual.

---

### F-12 — Modul zakat terbuka untuk `manajer` tanpa pintu masuk

| | |
|---|---|
| **Keparahan** | SEDANG |
| **Lokasi** | `config/izin.php:64` (`zakat.kelola => ['pengurus','manajer']`); `config/navigasi.php` |

`zakat.index`, `zakat.laporan`, dan `zakat.ringkasan` membalas 200 untuk `manajer`, tetapi tidak ada menu zakat
untuk manajer. Ketiganya masuk kategori "tanpa pintu masuk" pada klasifikasi.

---

### F-14 — Pengurus dapat membuka `/admin/settings` tetapi tidak ditautkan; aksi update lintas controller

| | |
|---|---|
| **Keparahan** | SEDANG |
| **Lokasi** | `routes/web.php:438-441`; `config/izin.php:93`; `config/navigasi.php` |

- `pengaturan.admin => ['super_admin','admin','pengurus']` → `pengurus` mendapat 200 pada `admin.settings.index`.
- Halaman itu **tidak ada di menu pengurus** (hanya di menu `admin` dan `super_admin`).
- `admin.settings.update` menunjuk `PengurusController::updateKebijakan` — definisi rute admin bergantung pada
  controller pengurus, sehingga perubahan pada salah satu sisi berisiko memutus yang lain.

---

## 6. Temuan RENDAH

### F-06 — Pengalihan ramah (302) menggantikan 403 pada dua kelompok

| | |
|---|---|
| **Keparahan** | RENDAH |
| **Lokasi** | `config/role_hierarki.php:399-422` |

- `pengelola_beban.ao` → `dashboard.ao` (sapuan: `pengeluaran-beban.index = 302`).
- `manajer_fitur_tersembunyi.manajer` → `dashboard.manajer` (sapuan: 7 rute manajer = 302, termasuk
  `manajer.kas.index`, `manajer.audit.index`, `manajer.laporan.index`).

Perilaku ini disengaja dan tidak merusak. Dicatat agar tidak dilaporkan sebagai "fitur rusak" oleh penguji lain.

---

### F-08 — `super_admin` dapat membuka halaman keuangan tanpa tautan menu

| | |
|---|---|
| **Keparahan** | RENDAH |
| **Bukti** | `audit_gap2_output.txt` → `[super_admin] celah=7` |

`pengurus.coa.index`, `laporan.neraca`, `laporan.buku-besar`, `laporan.psak101`, `pengurus.angsuran.index`,
`autodebit.index` — semua 200, sebagian besar ditautkan dari dalam halaman (halaman turunan). Tidak ada
pelanggaran pemisahan tugas karena super_admin tidak memegang hak tulis jurnal.

---

### F-13 — Rute kas manajer mubazir

| | |
|---|---|
| **Keparahan** | RENDAH |
| **Lokasi** | `config/navigasi.php`; `config/role_hierarki.php:416-421` |

`manajer.closing-kas.index` dan `manajer.kas.index` tidak ditautkan dari Blade mana pun, dan `manajer.kas.index`
justru **ditolak** oleh `FITUR_DISEMBUNYIKAN` (302 ke dashboard). Rute hanya menambah permukaan serangan tanpa
nilai fungsional.

---

### F-15 — `dps.dana-kebajikan.ringkasan` terdeteksi "tanpa pintu" (positif palsu klasifikasi)

| | |
|---|---|
| **Keparahan** | RENDAH |
| **Lokasi** | `routes/web.php:453`; `app/Http/Controllers/DpsController.php` |

Endpoint JSON ini tidak dirujuk JavaScript dashboard (3 kemunculan di repo: rute + 2 berkas uji). Klasifikasi
otomatis memasukkannya ke "tanpa pintu" karena pola regex tidak mencakup nama `ringkasan`. **Bukan bug**, tetapi
menandakan endpoint tanpa konsumen UI — kandidat konsolidasi atau dokumentasi.

---

## 7. Positif — Yang Terverifikasi Bersih

| Pemeriksaan | Hasil |
|---|---|
| Nama view yang dirujuk di seluruh aplikasi | **176/176 ada** |
| `route()` di Blade yang menunjuk rute tak terdaftar | hanya `password.request`, aman karena dibungkus `@if (Route::has(...))` |
| Menu 9 peran → rute terdaftar + ber-middleware `izin:` | **9/9 OK** |
| Izin dirujuk rute tetapi tidak ada di `config/izin.php` | **0** |
| Izin di `config/izin.php` tetapi tidak dipakai rute | **0** (sinkron dua arah) |
| Dashboard sendiri dapat dimuat untuk 7 peran pemilik | **semua 200** |
| Dashboard peran lain ditolak | **semua 403/302 sesuai aturan** |
| Galat 404 / 422 / 429 pada 202 rute terizinkan | **0** |
| 302 pada `*.cif.export` | wajar — `CifController::export()` mensyaratkan `?export=excel` |
| 302 pada `pengajuan-pembiayaan.eligibility-check` | wajar — endpoint butuh parameter kueri |

---

## 8. Lampiran

### Lampiran A — Rekaman sapuan (`storage/app/audit_sweep_output.txt`)

```
[dps]         total=22 200=22
[pengurus]    total=52 200=49 302=2 lain=1
[manajer]     total=53 200=45 302=7 lain=1
[ao]          total=20 200=17 302=2 lain=1
[teller]      total=25 200=25
[super_admin] total=14 200=14
[admin]       total=2  200=2
[anggota]     total=14 200=14
[nasabah]     total=0
```

### Lampiran B — Klasifikasi celah (`storage/app/audit_gap2_output.txt`)

| Peran | Celah | Tanpa pintu | Halaman turunan | Endpoint |
|---|---|---|---|---|
| dps | 7 | 1 (positif palsu, F-15) | 4 | 2 |
| pengurus | 33 | 4 | 24 | 5 |
| manajer | 42 | 7 | 30 | 5 |
| ao | 8 | 1 | 4 | 3 |
| teller | 13 | 1 | 9 | 3 |
| super_admin | 7 | 0 | 6 | 1 |
| admin | 1 | 0 | 1 | 0 |
| anggota | 8 | 4 | 3 | 1 |
| nasabah | 0 | 0 | 0 | 0 |
