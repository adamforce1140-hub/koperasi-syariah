# AUDIT ARSITEKTUR SISTEM
## Modularitas · Fleksibilitas Konfigurasi · Kemampuan Adaptasi · Penskalaan & Integrasi

**Koperasi Syariah — Audit Arsitektural Menyeluruh**
Tanggal audit: 2026-10-05
Sifat: **read-only (tidak ada perubahan kode)** — laporan ini adalah basis keputusan sebelum implementasi.

---

## 0. RINGKASAN EKSEKUTIF

Sistem memiliki **fondasi konfigurasi kebijakan yang matang** (`App\Support\Setting` → tabel `kebijakan_koperasis` dengan cache, invalidasi otomatis, audit trail) dan **modul produk dinamis** yang baik (simpanan, deposito, pembiayaan, pemetaan akad→CoA). Ini adalah modal besar.

Namun audit menemukan bahwa **lapisan operasional (eksekusi asinkron, event, observability, kontrak antar-modul, API lifecycle) belum terimplementasi sama sekali**, dan **sebagian parameter keuangan masih tersebar/hardcode**. Secara ringkas:

| Dimensi | Status | Ringkasan |
|---|---|---|
| Modularitas kode | 🟡 Sedang | Modul bisnis terpisah jelas, **tetapi tidak ada kontrak (interface)**, tidak ada event, tidak ada job → tightly coupled ke pemanggilan langsung. |
| Fleksibilitas konfigurasi | 🟢 Baik (dengan catatan) | ~60 parameter dinamis terpusat, **namun ≥25 titik masih hardcode** dan **ada 2 sumber kebenaran SHU**. |
| Kemampuan adaptasi | 🟡 Sedang | Tahun buku & produk sudah dinamis; **perubahan beban kerja tidak terduga belum didukung** (queue sync, cache file, tanpa scaling). |
| Penskalaan otomatis | 🔴 Belum ada | `QUEUE_CONNECTION=sync`, `CACHE_DRIVER=file`, `SESSION_DRIVER=file`, tanpa health-check, tanpa job tabel, tanpa worker. |
| Konfigurasi real-time | 🟡 Sebagian | `Setting` sudah cache+invalidasi, **tetapi cache `file` tidak dibagi antar-proses**; mesin Denyut pakai polling, bukan push. |
| Integrasi antar-modul | 🔴 Belum ada | `EventServiceProvider` kosong, event discovery dimatikan, **tidak ada `app/Events`, `app/Listeners`, `app/Contracts`** → integrasi hanya via pemanggilan service langsung. |

**Kesimpulan:** sistem **berjalan benar sebagai monolit sinkron single-node**, tetapi **belum siap untuk operasi dinamis multi-node, beban kerja fluktuatif, atau integrasi longgar antar-modul**. Seluruh elemen yang hilang teridentifikasi di Bagian 2, rencana implementasi di Bagian 4, dan strategi pengujian di Bagian 5.

---

## 1. METODOLOGI AUDIT

Audit dilakukan melalui:
1. Pemetaan struktur direktori (`app/`, `config/`, `routes/`, `database/migrations/`, `composer.json`, `.env.example`).
2. Pembacaan dokumen audit/tahap sebelumnya (37 dokumen di `docs/`).
3. Verifikasi langsung bukti kode (grep/glob) untuk setiap klaim (bukan asumsi).

Metode **verifikasi bukti** (bukan asumsi dokumen) menemukan bahwa **sebagian dokumen audit lama sudah STALE**, misalnya `AUDIT-PARAMETER-PSAK-FLEKSIBILITAS.md` menyatakan tahun buku masih kaku Jan–Des, padahal sudah dinamis (lihat Bagian 3.2).

---

## 2. ELEMEN YANG HILANG / BELUM TERIMPLEMENTASI

### 2.1 🔴 KRITIS-ARSITEKTURAL — Lapisan Eksekusi Asinkron Tidak Ada

**Bukti:**
- **Tidak ada `app/Jobs/`** — `Glob app/Jobs/**/*.php` → *No file found*.
- **Tidak ada migrasi tabel `jobs` / `failed_jobs`** — `Glob database/migrations/*queue*` dan `*jobs*` → *No file found*, padahal `config/queue.php` mendefinisikan koneksi `database` & `failed` (database-uuids).
- **Tidak ada `dispatch()` / `Bus::` / `Queue::` sama sekali di `app/`** — grep → *No matches found*. Satu-satunya `ShouldQueue` adalah `app/Notifications/ShuDitahanNotification.php` (notifikasi, bukan job).
- **`QUEUE_CONNECTION=sync`** (`.env.example`) → semua pekerjaan berat dieksekusi **di dalam request HTTP**.

**Dampak:** Operasi berat (buka rekening massal, proses jatuh tempo deposito, distribusi SHU, bagi hasil, notifikasi massal) **memblokir request pengguna** dan **tidak dapat diskalakan horizontal**. Tidak ada retry otomatis, tidak ada backoff, tidak ada dead-letter (`failed_jobs`) → satu kegagalan = kegagalan transaksi pengguna.

**Severity: KRITIS** — ini adalah penghambat utama "penskalaan otomatis" yang diminta.

---

### 2.2 🔴 KRITIS-ARSITEKTURAL — Tidak Ada Event-Driven Architecture

**Bukti — `app/Providers/EventServiceProvider.php`:**
```php
protected $listen = [
    //
];
public function boot(): void
{
    //
}
public function shouldDiscoverEvents(): bool
{
    return false;
}
```
- `Glob app/Events/**/*.php` → *No file found*
- `Glob app/Listeners/**/*.php` → *No file found*

**Dampak:** Modul terhubung **hanya** via pemanggilan service langsung (contoh: `AngsuranTransactionService`, `ShuCalculatorService`, `ZakatService`, `AutoJournalingService` saling memanggil). Setiap penambahan efek samping (mis. "setiap angsuran lunas → kirim notifikasi + update kolektibilitas + catat audit") menuntut **modifikasi kode inti** modul pemanggil → melanggar Open/Closed Principle, dan integrasi antar-modul **tidak fleksibel**.

**Severity: KRITIS** — penghambat utama "integrasi yang fleksibel dengan modul lain".

---

### 2.3 🔴 TINGGI — Tidak Ada Kontrak / Dependency Inversion

**Bukti:** `Glob app/Contracts/**/*.php` dan `app/Interfaces/**/*.php` → *No file found*. `AppServiceProvider::register()` **kosong** (tanpa binding interface).

**Dampak:**
- Service konkret (mis. `AutoJournalingService`, `Setting`) di-hardcode sebagai dependency → sulit diganti, ditiru (mock), atau diuji terisolasi.
- Tidak ada titik ekstensi resmi (mis. `JournalPosterInterface`, `PolicySourceInterface`) → penggantian implementasi (mis. adapter jurnal eksternal, sumber kebijakan dari layanan pusat) menuntut penulisan ulang pemanggil.

**Severity: TINGGI.**

---

### 2.4 🔴 TINGGI — Penskalaan & Runtime Satu Node

**Bukti:**
| Konfigurasi | Nilai saat ini | Konsekuensi scaling |
|---|---|---|
| `QUEUE_CONNECTION` | `sync` | Tidak ada worker; beban di proses web. |
| `CACHE_DRIVER` | `file` | Cache **tidak dibagi** antar-node; invalidasi `Setting` tidak konsisten multi-node. |
| `SESSION_DRIVER` | `file` | **Sticky session wajib**; tidak bisa multi-node tanpa load balancer sticky. |
| `BROADCAST_DRIVER` | `log` | Tidak ada push real-time asli. |
| Health endpoint | **tidak ada** | Load balancer / orchestrator tidak punya probe (`/health`, `/readyz`). |
| Redis client | env tersedia, **tidak dipakai** | Infrastruktur bersama belum dimanfaatkan. |

**Dampak:** Tidak ada yang bisa **diduplikasi (scale-out)**. Beban kerja puncak (buka serentak, tutup buku, distribusi SHU) hanya bisa ditangani dengan menaikkan spesifikasi satu server (scale-up), bukan otomatis.

**Severity: TINGGI.**

---

### 2.5 🟠 SEDANG — Tidak Ada Observability & Rate Limiting Berlapis

**Bukti:**
- Tidak ada `/health`, `/healthz`, `/readyz`, `/metrics` di `routes/` (grep → *No matches*).
- `RouteServiceProvider`: hanya satu limiter `api` = **60/menit untuk semua endpoint** (tanpa limiter khusus login/transaksi berat).
- Tidak ada dashboard metrik (Telescope/Pulse tidak terpasang di `composer.json`).
- Mesin Denyut (real-time) sudah punya kanal log `denyut` + `denyut:kesehatan --rinci` — **aset bagus**, tetapi bukan health-check HTTP untuk orchestrator.

**Severity: SEDANG.**

---

### 2.6 🟠 SEDANG — API Tanpa Versioning & Cakupan Terbatas

**Bukti — `RouteServiceProvider`:**
```php
Route::middleware('api')->prefix('api')->group(base_path('routes/api.php'));
```
- Prefix `api` **tanpa segmen versi** (`/api/v1/...`).
- `app/Http/Controllers/Api/` hanya berisi **1 controller**: `DepositoController`.
- `routes/api.php` hanya punya prefix `deposito` (temuan sebelumnya).

**Dampak:** Perubahan kontrak API berisiko breaking tanpa jalur paralel; integrasi pihak ketiga (mobile, mitra, OJK reporting gateway) tidak punya permukaan API memadai.

**Severity: SEDANG.**

---

### 2.7 🟠 SEDANG — Utang Teknis Konfigurasi (≥25 titik hardcode) & Dua Sumber Kebenaran SHU

**7a. Konflik kebijakan SHU (dua sumber kebenaran) — 🔴 masih ada, sebagian teratasi**

Ditemukan:
- `ShuController` (jalur produksi `shu_periods`) mengelola **5 pos** dari **input form**: `persen_cadangan, persen_jasa_modal, persen_jasa_transaksi, persen_pengurus, persen_sosial` (`ShuController::store`, baris 139–155).
- `Setting::alokasiShu()` mengelola **4 pos kebijakan berbeda**: `shu_pct_cadangan_modal(30), shu_pct_jasa_anggota(40), shu_pct_jasa_modal(20), shu_pct_dana_pendidikan(10)`.

**Status terkini:** **sudah dijembatani sebagian** oleh `Setting::alokasiShuDrafDefault()` (dipakai di `ShuController::index` baris 103 dan `Dashboard\PengurusController` baris 570) yang memetakan 4→5 pos. **Namun** ini hanya **nilai awal (prefill) UI** — 5 pos pada `shu_periods` tetap **tidak divalidasi terhadap kebijakan** saat `store()`, sehingga masih **bisa menyimpang** dari `Setting` dan tetap merupakan **dua sumber kebenaran**. **Severity: SEDANG** (turun dari KRITIS setelah jembatan, tapi belum tuntas).

**7b. Parameter yang masih hardcode** (dari `AUDIT-PARAMETER-PSAK-FLEKSIBILITAS.md`, perlu verifikasi ulang saat implementasi):
- `round(..., 2)` di **50+ lokasi** alih-alih `Setting::pembulatanDesimal()`.
- Toleransi selisih jurnal `0.01` di **4 lokasi** alih-alih `Setting::toleransiSelisihJurnal()`.
- `PendapatanService` belum sepenuhnya membaca `Setting`.
- Duplikasi konsep `jenis_simpanans` vs `deposito_products`.
- Dua sumber perhitungan "hari terlambat".
- Ketidaksinkronan skema: `MasterProdukPembiayaan::generateKode()` merujuk kolom `kode_produk` yang tidak ada di migrasi; `Angsuran.status_tazir` ada di `$fillable` tanpa kolom; `Pembiayaan.status_kolektibilitas`/`tanggal_hapus_buku` (sebagian sudah ditambahkan lewat migrasi `2026_10_02_000100_add_hapus_buku_to_pembiayaan_table.php`).

**7c. Parameter zakat atas SHU belum ada** — grep `Setting.php` untuk `zakat|nisab|haul` hanya menemukan `account_dana_zakat_id` (akun CoA PSAK 109) — **tidak ada** `zakat_rate`, `nisab`, `haul`. Artinya zakat atas SHU anggota/organisasi **tidak dapat dihitung otomatis** dan tidak dapat dikonfigurasi.

**Severity: SEDANG.**

---

### 2.8 🟡 RENDAH — Utang Teknis Ringan

- `App\Console\Kernel::schedule()` **tidak pernah dipanggil** (bootstrap Laravel 12 memakai Kernel framework); penjadwalan ada di `routes/console.php`. Sudah **didokumentasikan baik** di file tersebut, tetapi tetap jebakan bagi pengembang baru (baris 29 masih berisi `$schedule->command('inspire')` sebagai dead-code dokumentasi).
- Tidak ada modul PSAK 110 (Sukuk) & PSAK 111 (Waqaf) — **memang di luar lingkup** operasional koperasi simpan-pinjam, hanya relevan bila ekspansi produk.
- Guzzle ada di `composer.json` tetapi **tidak dipakai** di `app/` → indikasi belum ada integrasi keluar (gateway pembayaran, e-KYC, OJK reporting).

---

## 3. ANALISIS MODULARITAS, FLEKSIBILITAS, ADAPTASI

### 3.1 Modularitas — 🟡 Sedang (modular secara file, monolitik secara coupling)

**Kekuatan:**
- Pemisahan folder jelas: `Models`, `Services`, `Http/Controllers`, `Console/Commands`, `Support`, `Jobs (belum ada)`, `Notifications`, `Observers`.
- Service domain terpisah: `AutoJournalingService`, `ShuCalculatorService`, `AngsuranTransactionService`, `MandatorySavingsService`, `DepositoService`, `ZakatService`, `RestrukturisasiService`, `CddService`, `IkhtisarShuService`, `JurnalReconciliationService`.
- Registri CoA (`App\Support\Coa`) + resolver (`CoaResolver`) + pemetaan akad→CoA (`pemetaan_akad_coa`) → perlakuan akuntansi **dapat diubah tanpa kode**.

**Kelemahan:**
- **Tanpa interface** → tidak ada inversion; modul saling memanggil kelas konkret.
- **Tanpa event** → efek-samping tersebar sebagai pemanggilan langsung.
- **Tanpa job** → orkestrasi berat berada di controller/service sinkron.

### 3.2 Fleksibilitas Konfigurasi — 🟢 Baik (perlu penuntasan)

**Kekuatan (terverifikasi):**
- `App\Support\Setting` = **single source of truth** kebijakan; `DEFAULTS` ±60 kunci; casting tipe dideklarasikan; cache `kebijakan_koperasi.settings` (TTL 3600s) dengan **invalidasi otomatis** via `KebijakanKoperasi::booted()` + **audit trail**.
- **Tahun buku DINAMIS — SUDAH terimplementasi** (verifikasi: `App\Services\TahunBukuService` + `Setting::tahunBukuMulaiBulan()/jumlahBulanTahunBuku()` + migrasi `2026_10_02_000100_add_tahun_buku_dinamis_to_kebijakan_koperasis.php`). Dipakai di `ShuCalculatorService`, `PendapatanService`, `LaporanKeuanganSyariahService`, `DepositoService`, `Anggota`, dll. → **dokumen `AUDIT-PARAMETER-PSAK-FLEKSIBILITAS.md` STALE pada bagian ini.**
- Parameter Simpanan Wajib (5 kunci) dipindah ke panel kebijakan (migrasi `2026_09_30_004000`).
- Produk simpanan/deposito/pembiayaan dinamis melalui master tabel.

**Kelemahan:** dua sumber SHU (2.7a), zakat SHU (2.7c), ±25 hardcode (2.7b).

### 3.3 Kemampuan Adaptasi — 🟡 Sedang

**Sudah adaptif terhadap:** perubahan kebijakan nominal, tahun buku, produk, akad, pembulatan.
**Belum adaptif terhadap:** perubahan **beban kerja** (queue sync), perubahan **jumlah node** (session file), perubahan **real-time** (broadcast log), **integrasi longgar** (tanpa event/contract), **degradasi** (tanpa circuit breaker/health).

---

## 4. RENCANA IMPLEMENTASI TERSTRUKTUR

> Prinsip: **aditif & backward-compatible** — setiap fase tidak mengubah perilaku produksi secara default (di balik env switch), sehingga tidak mengganggu alur operasional yang ada. Urutan: fondasi → asinkron → event → API/ops → adaptasi.

### FASE A — FONDASI KONTRAK & ABSTRAKSI (prasyarat semua fase)
**Tujuan:** memberi titik ekstensi resmi tanpa mengubah perilaku.
1. Buat `app/Contracts/`:
   - `JournalPosterInterface` (diimplementasi `AutoJournalingService`) — resolusi publikasi jurnal.
   - `PolicySourceInterface` (diimplementasi `App\Support\Setting`) — sumber kebijakan.
   - `PeriodResolverInterface` (diimplementasi `TahunBukuService`).
2. Daftarkan binding di `AppServiceProvider::register()` (interface → implementasi konkret saat ini).
3. **Nol perubahan perilaku** — pemanggil lama tetap bekerja; binding hanya mengubah cara resolusi.
4. **Uji:** seluruh test suite harus tetap hijau (bukti tidak ada regresi).

### FASE B — LAPISAN ASINKRON (Jobs & Queue) 🔴 PRIORITAS
**Tujuan:** memindahkan pekerjaan berat keluar dari request; mendukung retry & scaling.
1. Publikasikan tabel queue: migrasi `create_jobs_table` & `create_failed_jobs_table`.
2. Tambah `app/Jobs/`: ekstrak operasi berat dari controller/command menjadi job:
   - `ProsesJatuhTempoDepositoJob`, `DistribusiBagiHasilDepositoJob`, `TutupBukuShuJob`, `ProsesTagihanSimpananWajibJob`, `KirimNotifikasiMassalJob`.
   - Terapkan `ShouldQueue`, `$tries`, `$backoff`, `failed()` (ajukan ke `failed_jobs`), dan **`$timeout`** sesuai SLA.
3. **Idempotency guard** wajib: setiap job memeriksa status (mis. `is_processed`) agar retry aman (penting untuk integritas keuangan).
4. Saklar `FEATURE_QUEUE_ASYNC` di `.env`; **default `false`** (tetap `sync`) hingga diverifikasi; ubah ke `database`/`redis` setelah lulus uji.
5. **Uji:** job dijalankan `sync` dalam test (deterministik) + uji terpisah dengan `queue:work --once` pada koneksi database.

### FASE C — EVENT-DRIVEN & INTEGRASI LONGGAR 🟠
**Tujuan:** integrasi fleksibel antar-modul tanpa kopling kode inti.
1. Aktifkan `EventServiceProvider`: set `shouldDiscoverEvents() => true` **atau** daftarkan eksplisit di `$listen`.
2. Buat `app/Events/`: `AngsuranLunas`, `PembiayaanDicairkan`, `SimpananDisetor`, `ShuDidistribusikan`, `DepositoJatuhTempo`, `KebijakanBerubah`.
3. Buat `app/Listeners/`: pencatatan audit, notifikasi, pembaruan kolektibilitas, invalidasi cache — **idempoten**.
4. `KebijakanBerubah` → listener invalidasi cache lintas-node (setelah Fase D) → **dukungan konfigurasi real-time konsisten multi-node**.
5. **Uji:** `Event::fake()` memverifikasi event dipicu tanpa efek samping nyata; uji listener terpisah.

### FASE D — INFRASTRUKTUR SHARED & PENSKALAAN OTOMATIS 🔴
**Tujuan:** siap multi-node, cache bersama, health-check.
1. **Redis optional:** siapkan `CACHE_DRIVER=redis`, `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis` (semua opsional via env; default tetap file/sync untuk dev).
2. **Health & readiness endpoints:** `GET /health` (liveness), `GET /readyz` (DB + cache + queue), *tanpa* autentikasi, rate-limited ringan, memanfaatkan `denyut:kesehatan` yang sudah ada.
3. **Rate limiting berlapis:** limiter `login` (mis. 5/menit/IP), `transaksi` (lebih ketat), `api` tetap 60/menit.
4. **Metadata skala:** dokumentasikan strategy `queue:work` + supervisor/systemd, jumlah worker adaptif terhadap panjang antrean.
5. **Uji:** health mengembalikan 200 saat sehat & 503 saat DB/cache mati (simulasi); uji limiter; uji multi-proses cache invalidation.

### FASE E — TUNTASKAN UTANG KONFIGURASI 🟠
1. **SATUKAN SHU (tuntas):** jadikan `Setting::alokasiShu()` satu-satunya sumber; `ShuController::store()` **memvalidasi** 5 pos terhadap kebijakan (tolak penyimpangan di luar toleransi) **atau** menghitung 5 pos dari kebijakan (hapus input manual). Tulis satu test yang gagal bila dua sumber berbeda.
2. **Zakat atas SHU:** tambah parameter `zakat_shu_rate`, `zakat_nisab`, `zakat_haul_bulan` → dipakai kalkulator SHU/zakat.
3. **Ganti `round(...,2)` & `0.01`** dengan `Setting::pembulatanDesimal()` / `Setting::toleransiSelisihJurnal()` secara bertahap (per modul + test regresi tiap langkah).
4. **Rapikan skema:** sinkronkan `kode_produk` / `status_tazir` / `status_kolektibilitas` (tambah kolom atau hapus dari `$fillable`); satukan hari-terlambat.
5. **Uji:** test "satu sumber kebenaran" untuk setiap parameter yang disatukan.

### FASE F — API LIFECYCLE & INTEGRASI KELUAR 🟠
1. **Versioning:** aktifkan `/api/v1/...` (petakan rute lama `/api/...` sebagai alias deprecated) → perubahan non-breaking.
2. **Perluas cakupan API** dengan Sanctum untuk modul inti (simpanan, pembiayaan, anggota) secara bertahap.
3. **Adapter integrasi keluar** (memanfaatkan Guzzle yang sudah ada): abstraksi `PaymentGatewayInterface`, `EkycInterface` di balik `Contracts/` (Fase A) + `Http::` dengan **timeout, retry, circuit-breaker sederhana**.
4. **Uji:** contract test API v1; uji adapter dengan HTTP fake.

---

## 5. STRATEGI PENGUJIAN KOMPREHENSIF

Mengikuti pola proyek: `php artisan optimize:clear` **sebelum** `php artisan test`; baseline saat audit = **1319 passed (5758 assertions), 0 failed** — **baseline ini tidak boleh turun**.

### 5.1 Uji Fungsi Modul & Parameter Baru
| Fase | Uji | Kriteria lulus |
|---|---|---|
| A | Seluruh suite lama | Tetap 1319+ passed, 0 failed (bukti nol regresi) |
| A | Binding interface | `app()->make(JournalPosterInterface::class)` mengembalikan implementasi benar |
| B | Setiap job: eksekusi langsung | Job menyelesaikan efek yang sama seperti jalur sinkron |
| B | Idempotency job | Menjalankan job 2× tidak menggandakan jurnal/efek |
| B | Retry & failed_jobs | Kegagalan sengaja → masuk `failed_jobs`; setelah retry sukses tidak ada efek ganda |
| C | Event dipicu | `Event::fake()` + `assertDispatched` untuk tiap event domain |
| C | Listener idempoten | Listener dijalankan 2× → efek satu kali |
| D | `/health`, `/readyz` | 200 saat sehat; 503 saat DB/cache dimatikan |
| D | Limiter | Login ke-6 ditolak; api tetap 60/menit |
| D | Cache invalidation multi-proses | Perubahan `Setting` di proses A terlihat di proses B |
| E | SHU satu sumber | Test gagal bila 5 pos ≠ kebijakan (di luar toleransi) |
| E | Zakat SHU | Perhitungan zakat mengikuti `zakat_shu_rate`/`nisab`/`haul` |
| E | Pembulatan & toleransi | Nilai mengikuti `Setting::pembulatanDesimal()`/`toleransiSelisihJurnal()` |
| F | API v1 contract | Skema respons v1 stabil; alias lama tetap bekerja |

### 5.2 Uji Beban Kerja (Perubahan Beban)
1. **Beban sinkron vs asinkron:** ukur waktu respons endpoint saat operasi berat dipindah ke job (harus turun signifikan, dari detik → milidetik).
2. **Antrean menumpuk:** suntik N job → verifikasi worker menghabiskan antrean tanpa kehilangan; `failed_jobs` kosong.
3. **Konkurensi tulis:** simulasi banyak transaksi bersamaan → integritas jurnal tetap terjaga (debet=kredit, tidak ada jurnal orphan) — manfatakan `JurnalReconciliationService` & `RekonsiliasiJurnal`.
4. **Buka/tutup buku puncak:** jalankan distribusi SHU & bagi hasil sebagai job, ukur throughput.
5. **Scale-out simulasi:** dua proses worker + cache/session Redis → tidak ada race/kebocoran.

### 5.3 Uji Tanpa Gangguan Alur Operasional (Regression Safety)
1. **Jalur kritis end-to-end** harus tetap identik hasilnya baik `sync` maupun `queue`:
   - Setoran/penarikan simpanan, angsuran (termasuk ta'zir), pencairan pembiayaan, deposito jatuh tempo, distribusi SHU, tutup buku.
2. **Default aman:** semua saklar (`FEATURE_QUEUE_ASYNC`, Redis) **OFF** secara default → perilaku produksi identik hingga diaktifkan sadar.
3. **Zero-hardcoding regression:** test yang menolak nilai `round(...,2)`/`0.01` pada modul yang sudah dimigrasikan ke `Setting` (mengunci agar tidak kembali hardcode).
4. **Compliance:** test PSAK 108/109/110 + OJK tetap hijau (validasi jurnal `post()`, pemisahan dana, ta'zir ke dana kebajikan).
5. **Gate:** setiap fase baru boleh digabung **hanya bila** suite penuh hijau ≥ baseline dan tidak ada penurunan assertion.

---

## 6. URUTAN PELAKSANAAN & DEPENDENSI

```
Fase A (Kontrak)  ──┬──> Fase B (Queue/Jobs)  ──┐
                    ├──> Fase C (Event)          ├──> Fase D (Redis/Health/Scaling)
                    └──> Fase F (API/Integrasi)  ┘
Fase E (Tuntaskan konfigurasi) — paralel, tidak bergantung fase lain
```
- **A wajib pertama** (fondasi kontrak).
- **B & C** adalah inti permintaan "operasi dinamis + integrasi fleksibel".
- **D** adalah inti "penskalaan otomatis + konfigurasi real-time multi-node".
- **E** adalah inti "fleksibilitas konfigurasi + adaptasi".
- **F** opsional/akhir.

---

## 7. LAMPIRAN — BUKTI VERIFIKASI CEPAT

| Klaim | Perintah verifikasi | Hasil |
|---|---|---|
| Tidak ada Jobs | `Glob app/Jobs/**/*.php` | No file found |
| Tidak ada Events | `Glob app/Events/**/*.php` | No file found |
| Tidak ada Listeners | `Glob app/Listeners/**/*.php` | No file found |
| Tidak ada Contracts | `Glob app/Contracts/**/*.php` | No file found |
| Tidak ada tabel queue | `Glob database/migrations/*queue*` & `*jobs*` | No file found |
| Tidak ada dispatch | grep `ShouldQueue|dispatch(|Bus::|Queue::` di `app/` | No matches |
| Event kosong | Baca `EventServiceProvider` | `$listen = []`, discovery `false` |
| Queue sync | `.env.example` | `QUEUE_CONNECTION=sync` |
| Cache/session file | `.env.example` | `CACHE_DRIVER=file`, `SESSION_DRIVER=file` |
| Tahun buku dinamis | `TahunBukuService` + `Setting` + migrasi | **Sudah ada** (dokumen lama stale) |
| Tanpa health endpoint | grep routes | No matches |
| API tanpa versi | `RouteServiceProvider` | `prefix('api')` saja |
| Konflik SHU | `ShuController::store` vs `Setting::alokasiShu` | 5 pos vs 4 pos (dijembatani sebagian) |
| Zakat SHU absen | grep `zakat|nisab|haul` di `Setting` | hanya `account_dana_zakat_id` |

---

*Dokumen ini bersifat read-only. Implementasi menunggu persetujuan; setiap fase dijalankan bersama langkah pengujiannya dan dijaga agar baseline 1319 passed tidak turun.*
