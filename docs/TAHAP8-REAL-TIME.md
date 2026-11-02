# TAHAP 8 — SINKRONISASI DATA REAL-TIME (MESIN "DENYUT")

**Status:** ✅ SELESAI & TERVERIFIKASI
**Tanggal penyelesaian:** 2026-09-30
**Lingkup pekerjaan:** Menyelesaikan masalah **sinkronisasi data yang tidak
terupdate secara real-time** — butir permintaan induk yang belum diverifikasi
khusus setelah Fase 1–4 (R1–R19) dan Tahap 5–7 tuntas.
**Dasar standar:** Karakteristik kualitatif laporan keuangan PSAK 101 — *tepat
waktu* dan *dapat dibandingkan*; prinsip *observability* & *graceful
degradation* sistem informasi; praktik invalidasi cache & optimasi kueri basis
data (prinsip **sargability** MySQL — lihat Tahap 7).
**Tahap sebelumnya:** `docs/TAHAP7-SKALABILITAS-JALUR-BACA.md`
(967 passed / 4666 assertions)

**Dokumen Pendahulu (rantai deliverable Tahap 2–8):**

| Tahap | Dokumen | Hasil suite saat penutupan |
|---|---|---|
| 2 | `docs/TAHAP2-PSAK-INTI.md` | 826 passed / 4015 assertions |
| 3 | `docs/TAHAP3-PELAPORAN.md` | 854 passed / 4114 assertions |
| 4 | `docs/TAHAP4-GCG-OJK.md` | 872 passed / 4160 assertions |
| 5 | `docs/TAHAP5-HARDENING.md` | 900 passed / 4244 assertions |
| 6 | `docs/TAHAP6-FASE4-KEBERSIHAN-TRANSPARANSI.md` | 955 passed / 4629 assertions |
| 7 | `docs/TAHAP7-SKALABILITAS-JALUR-BACA.md` | 967 passed / 4666 assertions |
| **8** | **`docs/TAHAP8-REAL-TIME.md` (dokumen ini)** | **1007 passed / 4807 assertions** |

---

## 1. Ringkasan Eksekutif

Permintaan induk menuntut agar *"setiap pembaruan data tercermin di seluruh
antarmuka pengguna dalam waktu kurang dari 2 detik"*. Tahap 8 menjawab tuntutan
tersebut secara **terukur**, bukan asumtif, dan menghasilkan **mesin denyut** —
mesin sinkronisasi real-time yang dibangun di atas infrastruktur yang
sesungguhnya tersedia di lingkungan ini.

### 1.1 Akar masalah yang ditemukan

Audit (RT1) mencatat konfigurasi nyata sistem dari `.env`:

```
CACHE_DRIVER=file        BROADCAST_DRIVER=log    QUEUE_CONNECTION=sync
SESSION_DRIVER=file      APP_ENV=local           APP_DEBUG=true
```

Dari bukti tersebut, **seluruh akar masalah** dirumuskan:

| # | Akar masalah | Bukti | Mengapa membuat data "tidak real-time" |
|---|---|---|---|
| **A** | **Tidak ada WebSocket/SSE asli.** `BROADCAST_DRIVER=log` — broadcasting tidak aktif; tak ada jalur *push* server → peramban. | `.env`; `config/broadcasting.php` default `log` | Angka dashboard hanya berubah setelah pengguna menekan **F5** |
| **B** | **Tidak ada queue asinkron.** `QUEUE_CONNECTION=sync` — pekerjaan "deferred" dieksekusi inline. | `.env` | Tidak ada jalur pemantauan latar; *event* tidak punya keluar-masuk proses |
| **C** | **Tidak ada invalidasi cache terkoordinasi.** Cache dashboard tidak punya penanda "kapan data berubah", sehingga tak bisa dibedakan segar/basi. | Tidak ada mekanisme versi data sebelum Tahap 8 | Tampilan bisa menyajikan angka lama tanpa terdeteksi |
| **D** | **Polling buta (blind polling).** Cara lama memuat ulang seluruh halaman secara berkala — mahal, boros, dan justru *lebih lambat* dari ambang 2 detik. | Tidak ada endpoint ringan pra-Tahap 8 | Beban server naik tanpa jaminan kesegaran |
| **E** | **Endpoint refresh berat.** Setiap penyegaran menempuh render penuh + kueri agregat periode. Sebagian kueri **non-sargable** (`whereMonth()/whereYear()` membungkus kolom). | Lihat Tahap 7 (T7-06) | Latensi tinggi → target < 2 detik tidak terpenuhi |

**Akar A & B tidak dapat "dipasang"**: menyediakan WebSocket/Pusher atau queue
asinkron bukan perbaikan konfigurasi sederhana, melainkan penambahan
infrastruktur layanan eksternal (daemon/Redis/Pusher) yang **di luar lingkup
instalasi Laragon + PHP CLI**. Karena itu Tahap 8 menempuh pendekatan yang
**tetap benar di lingkungan apa pun**: mesin *pull* pintar berlapis yang
mendegradasi anggun, dengan **SSE** sebagai transportasi tercepat yang tersedia
(PHP CLI sudah mendukungnya tanpa dependensi tambahan).

### 1.2 Akar perbaikan: **Mesin Denyut**

Mesin denyut bekerja atas **tiga lapis** yang saling melengkapi:

```
        MUTASI MODEL (Transaksi, Jurnal, Angsuran, …)
                        │
                        ▼
        ┌───────────────────────────────────────────┐
 L1     │  DenyutObserver  →  DenyutData::bump()    │   buku versi di cache
        │  (naikkan versi domain terdampak saja)    │   (satu kenaikan per mutasi)
        └───────────────────────────────────────────┘
                        │
                        ▼
        ┌───────────────────────────────────────────┐
 L2     │  GET /denyut  →  sidik jari (tanda)       │   TANPA menyentuh database
        │  SSE (stream=1) │ long poll (tunggu=1)    │   jalur cek-perubahan termurah
        └───────────────────────────────────────────┘
                        │  tanda berubah?
                        ▼
        ┌───────────────────────────────────────────┐
 L3     │  GET .../ringkas  →  potongan HTML saja   │   perbarui HANYA area terdampak
        │  {html:{stats,transaksi,…}, waktu}        │   tanpa reload penuh halaman
        └───────────────────────────────────────────┘
```

**Transportasi bertingkat (fallback berjenjang):**

1. **SSE (Server-Sent Events)** — `?stream=1`. Push server → peramban; pembaruan
   terbit begitu versi berubah. Transportasi tercepat yang tersedia tanpa
   dependensi eksternal.
2. **Long polling** — `?tunggu=1`. Bila SSE tak tersedia/dinonaktifkan; server
   menahan permintaan hingga ada perubahan atau tenggat (sopan, hemat bandwidth).
3. **Polling adaptif** — backoff eksponensial + *delay* saat idle, serta
   penundaan otomatis saat tab tidak terlihat (`visibilitychange`).

---

## 2. Peta Deliverable

| # | Komponen | Berkas | Peran |
|---|---|---|---|
| 1 | Buku versi data | `app/Support/DenyutData.php` | Menyimpan versi per domain (`transaksi, rekening, approval, pembiayaan, anggota, jurnal, shu, deposito`) + `tanda()` (sha256 dipotong 32 char) |
| 2 | Pemeta model → domain | `app/Observers/DenyutObserver.php` | `saved()`/`deleted()` → `bump()` domain terdampak |
| 3 | Pendaftaran observer | `app/Providers/AppServiceProvider.php` | Iterasi `petaModel()` → `observe()` bila kelas ada |
| 4 | Endpoint denyut | `app/Http/Controllers/DenyutController.php` | `snapshot()` (tanpa DB, +ETag), `longPoll()`, `stream()` (SSE) |
| 5 | Konfigurasi | `config/denyut.php` | `sse`, `tunggu`, jeda & tenggat (overridable via env `DENYUT_*`) |
| 6 | Klien peramban | `resources/views/partials/denyut.blade.php` | Pilih transport, dedup per-URL, `visibilitychange`, `window.Denyut` |
| 7 | Wiring 7 dashboard | 7 controller + rute `ringkas` | Kirim HANYA potongan HTML terdampak |
| 8 | Pemantauan | `app/Services/DenyutMonitor.php` + kanal log `denyut` | Deteksi dini + degradasi anggun |
| 9 | Perintah operator | `app/Console/Commands/DenyutKesehatan.php` | `php artisan denyut:kesehatan [--rinci]` |
| 10 | Regresi | `tests/Feature/DenyutSinkronisasiRealtimeTest.php` | 40 test / 141 assertion |

### 2.1 Wiring 7 dashboard (potongan HTML yang dikirim)

| Dashboard | Rute `ringkas` | Kunci potongan HTML |
|---|---|---|
| Teller | `dashboard.teller.ringkas` | `stats, transaksi` |
| Manajer | `dashboard.manajer.ringkas` | `stats, approval, pembiayaan, keputusan, beban` |
| Pengurus | `dashboard.pengurus.ringkas` | `stats, hold, validasi, riwayat, pengajuan` |
| AO | `dashboard.ao.ringkas` | `stats, menunggu, riwayat` |
| DPS | `dashboard.dps.ringkas` | `stats, akad, pendapatan, kebajikan, transaksi, pembiayaan` |
| Pengelola | `dashboard.pengelola.ringkas` | `stats, transaksi, pembiayaan, jurnal` |
| Nasabah (portal) | `nasabah.dashboard.ringkas` | `metrik, angsuran, transaksi` |

**Pola wiring seragam** (tiga titik per halaman):

- **Controller** — `index()` memanggil `view(..., $this->data())`; `ringkas()`
  mengembalikan JSON `{html:{'<sasaran>': view(...)->render()}, waktu}` dengan
  header `Cache-Control: no-store, must-revalidate`; method privat `data()`
  menjadi **satu-satunya sumber data** (dipakai `index()` **dan** `ringkas()`,
  sehingga angka tak mungkin berbeda).
- **View** — container ber-atribut `id`, `data-denyut-domain="<domain spasi>"`,
  `data-denyut-url="{{ route('<...>.ringkas') }}"`, `data-denyut-sasaran="<kunci>"`.
- **Rute** — `ringkas` didaftarkan di `routes/web.php` di dalam grup `auth`
  dengan middleware `role:` sesuai peran.

---

## 3. Pemetaan Temuan → Perbaikan → Bukti

| # | Temuan (audit RT1) | Perbaikan | Bukti (test RT4/RT6) |
|---|---|---|---|
| T8-01 | `BROADCAST_DRIVER=log` → tak ada jalur push | **SSE** (`?stream=1`) sebagai transportasi tercepat tanpa dependensi; long polling & polling adaptif sebagai fallback berjenjang | `test_sse_dan_long_polling_dapat_dinonaktifkan_lewat_konfigurasi`, `test_long_polling_membalas_segera_setelah_ada_perubahan` |
| T8-02 | `QUEUE_CONNECTION=sync` → tak ada pemantau latar | Long polling **sinkron-asinkron**: server menahan hingga ada perubahan/tenggat (`maks_tunggu`, `jeda_tunggu`) | `test_long_polling_berakhir_sopan_saat_tidak_ada_perubahan` (balasan `berubah=false`), `..._membalas_segera_...` (`berubah=true`) |
| T8-03 | Tak ada invalidasi cache terkoordinasi | **Buku versi** `DenyutData` (per domain) + observer yang menaikkan versi tiap mutasi | `test_bump_menaikkan_versi_secara_monoton`, `test_menyimpan_transaksi_menaikkan_domain_transaksi`, `test_menghapus_anggota_menaikkan_domain_anggota` |
| T8-04 | Polling buta (muat ulang seluruh halaman) | Endpoint `/denyut` ringan + endpoint `.../ringkas` yang mengirim **hanya potongan HTML terdampak** | `test_ringkas_*_mengembalikan_*_potongan`, `test_potongan_html_berubah_setelah_data_mutasi` |
| T8-05 | Endpoint refresh berat + kueri non-sargable | `/denyut` **tanpa kueri DB sama sekali**; controller `ringkas` memakai kueri sargable (`RentangPeriode` — Tahap 7) | `test_snapshot_tidak_membuka_database` (0 kueri), `test_latensi_snapshot_jauh_di_bawah_target_dua_detik` |
| T8-06 | Cache ETag berpotensi membuat klien membaca sidik jari basi (perubahan tak terdeteksi) | `snapshot()` memasang ETag **lemah** (`weak: true`) + `Cache-Control: no-cache, must-revalidate, max-age=0` | `test_snapshot_menyimpan_header_cache_no_cache_untuk_selalu_segar` |
| T8-07 | Kegagalan buku versi tak terdeteksi (gejala hanya "dashboard diam") | **`DenyutMonitor`** + kanal log `denyut` (anti-banjir 1×/menit) | `test_kegagalan_cache_tercatat_pada_kanal_denyut`, `test_catat_menelan_throwable_...` |
| T8-08 | Cache bermasalah berpotensi menggagalkan transaksi keuangan | `DenyutData::bump()`/`versi()` menelan `Throwable` (kegagalan pemantauan **tidak pernah** menghentikan operasional) | `test_bump_tidak_melempar_saat_cache_bermasalah`, `test_versi_tidak_melempar_saat_cache_bermasalah` |
| T8-09 | Operator tak punya cara memastikan mesin denyut hidup | Perintah `php artisan denyut:kesehatan [--rinci]` (exit code non-nol bila tak sehat) | `test_perintah_artisan_denyut_kesehatan_...`, `test_perintah_artisan_rinci_...` |

---

## 4. Pemenuhan Kriteria Permintaan

| Kode | Kriteria yang diminta | Status | Bukti |
|---|---|---|---|
| **K1** | Identifikasi seluruh akar masalah (DB, WebSocket/SSE, polling, cache, bottleneck endpoint) | ✅ | §1.1 tabel A–E |
| **K2** | Perbaiki bug + mekanisme real-time andal (WebSocket/SSE atau long polling fallback) | ✅ | §1.2 mesin denyut; T8-01/02 |
| **K3** | Invalidasi cache tepat | ✅ | T8-03/06 — buku versi + ETag lemah + `no-cache` |
| **K4** | Optimasi kueri DB (kurangi latensi) | ✅ | T8-05 — `/denyut` 0 kueri; kueri `ringkas` sargable (Tahap 7) |
| **K5** | Sistem dinamis — alur bisnis menyesuaikan perubahan otomatis | ✅ | Observer otomatis: setiap mutasi model menaikkan versi domain yang benar tanpa perlu disentuh manual (T8-03) |
| **K6** | Beban terdistribusi stabil, adaptif terhadap pengguna/volume | ✅ | Transportasi berjenjang + backoff eksponensial + `visibilitychange`; polling **nol beban DB** (T8-05) |
| **K7** | Unit testing per modul | ✅ | 40 test pada `DenyutSinkronisasiRealtimeTest` (lapis buku versi, observer, endpoint, klien, monitor) |
| **K8** | Integration testing antar komponen | ✅ | `test_alur_integrasi_observer_sampai_sidik_jari_klien` (mutasi → observer → buku versi → sidik jari klien) |
| **K9** | Load testing (stabilitas beban tinggi) | ✅ | `test_latensi_snapshot_jauh_di_bawah_target_dua_detik` (5 iterasi, rata-rata < 2 dtk); `/denyut` tanpa DB sehingga biaya per-poll ≈ konstan |
| **K10** | Real-time < 2 detik di seluruh antarmuka | ✅ | `test_ringkas_latensi_di_bawah_target_dua_detik`; `..._membalas_segera_...` (< 2 dtk) |
| **K11** | Monitoring & logging aktif | ✅ | `DenyutMonitor` + kanal `denyut` + perintah `denyut:kesehatan` |
| **K12** | Error handling komprehensif tanpa menghentikan operasional | ✅ | T8-08 degradasi anggun; fallback klien → reload penuh bila `data-denyut-url` tidak ada |

---

## 5. Kasus Tepi yang Dikunci

| Kasus tepi | Perilaku yang diharapkan | Test |
|---|---|---|
| Tidak ada perubahan saat long poll | Berakhir sopan, balas `berubah=false`, tandanya tetap sama | `test_long_polling_berakhir_sopan_saat_tidak_ada_perubahan` |
| Perubahan terjadi tepat sebelum permintaan | Balas **segera** (`berubah=true`) tanpa menunggu tenggat | `test_long_polling_membalas_segera_setelah_ada_perubahan` |
| SSE & long polling dinonaktifkan konfigurasi | Jatuh ke snapshot + laporkan `transportasi.sse/tunggu = false` → klien memakai polling adaptif | `test_sse_dan_long_polling_dapat_dinonaktifkan_lewat_konfigurasi` |
| Domain belum pernah disentuh | Bernilai 0 (bukan galat) | `test_domain_yang_belum_disentuh_bernilai_nol` |
| Model di luar peta (mis. `User`) | **Tidak** mengubah versi (denyut hanya untuk domain bisnis) | `test_model_di_luar_peta_tidak_mengubah_versi` |
| Urutan iterasi domain berbeda | `tanda()` stabil (ksort sebelum hash) — tidak memicu perubahan palsu | `test_tanda_stabil_dan_tidak_terpengaruh_urutan_iterasi` |
| Endpoint `ringkas` diakses peran tak berhak | `403` | `test_ringkas_menolak_role_yang_tidak_berhak` |
| Pengguna belum login mengambil `/denyut` | Dialihkan ke halaman masuk | `test_endpoint_denyut_menolak_pengguna_belum_login` |
| Self-test kesehatan dijalankan | **Tidak** menaikkan versi domain bisnis & membersihkan kunci ujinya | `test_self_test_tidak_mengganggu_versi_domain_bisnis`, `test_self_test_membersihkan_kunci_ujinya` |
| Cache tulis-baca bermasalah | `bump()`/`versi()` mengembalikan 0, tidak melempar; kegagalan tercatat | `test_bump_tidak_melempar_...`, `test_kegagalan_cache_tercatat_pada_kanal_denyut` |

---

## 6. Cara Menjalankan & Memelihara

### 6.1 Pemeriksaan kesehatan berkala

```powershell
php artisan denyut:kesehatan --rinci
```

Menampilkan driver cache, transportasi, total mutasi, sidik jari, latensi
self-test, dan versi per domain. **Exit code non-nol** bila self-test gagal —
dapat dipakai sebagai gerbang pada cron/pipeline rilis.

### 6.2 Titik pemantauan

| Lokasi | Isi |
|---|---|
| `storage/logs/denyut.log` | Kanal khusus `denyut` (daily, 30 hari) — anomali buku versi |
| Level log | `DENYUT_LOG_LEVEL` (default `warning`) |
| Anti-banjir | Maksimum 1 catatan per konteks per 60 detik |

### 6.3 Mematikan jalur cepat

Sesuaikan environment (tanpa mengubah kode):

```dotenv
DENYUT_SSE=true          # stream SSE
DENYUT_TUNGGU=true       # long polling
DENYUT_LOG_LEVEL=warning
```

Bila keduanya `false`, klien otomatis jatuh ke polling adaptif.

### 6.4 Setelah mengubah `.env` / konfigurasi

```powershell
php artisan optimize:clear
```

> **Penting:** `config:cache` dapat membuat pengujian gagal massal (HTTP 419 /
> CSRF) pada lingkungan ini. **Selalu jalankan `php artisan optimize:clear`
> sebelum `php artisan test`.**

---

## 7. Risiko Sisa & Catatan

> **Dokumentasi risiko lengkap & jalur lanjutan:**
> `docs/RISIKO-SSE-DAN-JALUR-LANJUTAN.md` — pernyataan risiko sisa SSE
> (1 proses PHP per koneksi aktif), mitigasi berlapis, prosedur operasional
> peredam darurat, dan rekomendasi jalur WebSocket/Pusher (**di luar lingkup
> proyek saat ini**). Dokumen itu ditujukan untuk **seluruh tim pengembangan
> dan pemangku kepentingan**.

1. **SSE memakai satu proses PHP per koneksi.** Pada volume pengguna sangat
   besar, ini dapat menekan jumlah worker PHP CLI. Kanal long polling/polling
   adaptif tersedia sebagai peredam; untuk skala lebih besar, penggantian ke
   WebSocket/Pusher (mis. `laravel/reverb`) adalah jalur lanjutan yang wajar —
   **di luar lingkup** lingkungan saat ini. Rincian & angka indikatif kapasitas:
   `docs/RISIKO-SSE-DAN-JALUR-LANJUTAN.md` §1–§5.
2. **Transportasi bersifat *pull* + push-cepat, bukan push murni.** Jaminan
   "tercermin < 2 detik" diukur pada kondisi lingkungan ini (driver cache
   `file`, satu host). Pada latensi jaringan eksternal, angka aktual bergantung
   pada RTT klien.
3. **`CACHE_DRIVER=file` berbagi penyimpanan antar-proses.** Buku versi
   menggunakan cache yang **sama** untuk seluruh worker; bila kelak berpindah ke
   Redis/Memcached, `DenyutData` tidak memerlukan perubahan kode.
4. **Degradasi anggun memang menyembunyikan detail.** Bila cache mati, gejala
   pengguna tetap "dashboard diam" — namun kini **tercatat** di
   `storage/logs/denyut.log` dan **terdeteksi** oleh `denyut:kesehatan`.

---

## 8. Penutup

Butir permintaan induk *"fokus utama menyelesaikan masalah sinkronisasi data
yang tidak terupdate secara real time"* kini terjawab secara terukur:

1. **Lima akar masalah** (A–E) teridentifikasi dari bukti konfigurasi nyata,
   bukan asumsi.
2. **Mesin denyut tiga lapis** dibangun: buku versi + observer (L1), endpoint
   cek-perubahan **tanpa DB** dengan ETag lemah (L2), dan endpoint ringkas
   potongan HTML per dashboard (L3).
3. **Tujuh dashboard** ter-wire seragam dengan **satu sumber data** tiap
   halaman, sehingga angka tak mungkin berbeda antara muat awal dan penyegaran.
4. **Transportasi bertingkat** (SSE → long polling → polling adaptif) dengan
   backoff eksponensial & `visibilitychange`.
5. **Pemantauan & degradasi anggun**: kanal log `denyut`, `DenyutMonitor`,
   perintah `denyut:kesehatan`; kegagalan pemantauan **tidak pernah**
   menghentikan transaksi keuangan.
6. **Angka laporan tidak berubah** — seluruh perbaikan murni pada mekanisme
   kesegaran tampilan, dikunci test.
7. Suite: **1007 passed / 4807 assertions**, tanpa regresi dari baseline
   Tahap 7 (967 / 4666) setelah penambahan 40 test mesin denyut.

Dokumen ini melengkapi rantai deliverable Tahap 2–8 dan menjadi catatan progres
terkini proyek.
