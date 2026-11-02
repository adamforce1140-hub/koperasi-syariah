# Rekomendasi Arsitektur Modular & Fleksibel — Rencana Perbaikan Terukur

**Sistem:** Koperasi Syariah (Laravel 12.69.1 / PHP 8.3.33 / MySQL 8.4.3)
**Tanggal:** 5 Oktober 2026
**Sifat:** Analisis + rencana (read-only). Belum ada kode yang diubah.
**Baseline uji:** `1336 passed (5803 assertions), 0 failed` — **tidak boleh turun**.

> Dokumen ini melengkapi `docs/AUDIT-ARSITEKTUR-MODULARITAS-FLEKSIBILITAS.md` (temuan) dengan **daftar rekomendasi terperinci, terukur, dan dapat diimplementasikan**. Seluruh klaim di bawah diverifikasi langsung ke kode (bukan asumsi).

---

## 1. RINGKASAN EKSEKUTIF

Sistem memiliki **fondasi konfigurasi yang matang** — `App\Support\Setting` (≈60+ parameter dinamis, cache + invalidasi + audit trail) dan `config/role_hierarki.php` (RBAC config-driven). Ini modal besar dan **tidak perlu dibongkar**.

Namun sistem **kaku** pada 5 titik yang terverifikasi:

| # | Akar Kekakuan | Bukti Terverifikasi | Dampak |
|---|---|---|---|
| K1 | **Tanpa kontrak/DI** | `AppServiceProvider::register()` kosong; `app/Contracts`,`Interfaces`,`DTOs`,`Actions` **tidak ada**; 67 `use App\Services\` di 44 file; 3× `new \App\Services\` langsung | Modul saling mengikat ke kelas konkret → sulit diganti/di-mock/diuji terisolasi |
| K2 | **God-class (SRP dilanggar)** | `AutoJournalingService` 1993 baris; `TellerController` 1542; `MandatorySavingsService` 1459; `DepositoService` 1345; `Setting` 1258; `LaporanKeuanganSyariahService` 1117; `PengurusController` 1041 | Perubahan kecil berisiko tinggi; sulit diuji & diparalelkan |
| K3 | **Hardcode tersisa** | 279× `round(…, 2)` di 42 file (padahal ada `Setting::pembulatanNominal()`); 5× literal role di 3 controller (termasuk role phantom `'keuangan'`) | Perubahan kebijakan butuh sunting kode inti |
| K4 | **Tanpa lapisan asinkron & event** | `QUEUE_CONNECTION=sync`; `app/Jobs`,`Events`,`Listeners` **tidak ada**; `EventServiceProvider` `$listen=[]`, discovery `false`; 0× `dispatch()`/`Bus::`/`Queue::` | Operasi berat memblokir request; efek-samping tersebar; integrasi kaku |
| K5 | **Runtime satu node & tanpa observability** | `CACHE_DRIVER=file`, `SESSION_DRIVER=file`, `BROADCAST_DRIVER=log`; Redis tersedia tapi tak dipakai; **tidak ada** `/health`,`/readyz`,`/metrics`; API tanpa versi (`prefix('api')`); 1 controller API | Tidak bisa scale-out; tanpa probe orchestrator; API breaking-prone |

**Kesimpulan:** sistem benar sebagai **monolit sinkron single-node**, tetapi belum **dinamis, scalable, dan mudah diadaptasi**. Rekomendasi R1–R4 di bawah menutup kelima akar ini dengan urutan yang aman (aditif, di balik feature flag, tanpa mengubah perilaku default).

---

## 2. REKOMENDASI 1 — ARSITEKTUR MODULAR & PEMISAHAN TANGGUNG JAWAB

### 2.1 Masalah
`AutoJournalingService` (1993 baris) menangani **semua** posting jurnal lintas domain (simpanan, pembiayaan, zakat, SHU, deposito, CKPN). `TellerController` (1542 baris) menangani setoran, penarikan, opname, transfer brankas, dan cetak. Satu kelas = banyak alasan untuk berubah (melanggar SRP & OCP).

### 2.2 Rekomendasi
Terapkan **modular monolith berbasis domain** dengan pemisahan berlapis yang tegas:
`Controller (HTTP) → Action (use-case) → Service (domain) → Repository (data) → Model`.

### 2.3 Langkah Implementasi (step-by-step)
1. **Peta tanggung jawab**: inventarisasi semua method publik `AutoJournalingService` dan kelompokkan per domain (bukti: 9× `round`, method `jurnalSetorSimpananPokok`, `jurnalBagiHasilDepositoMudharabah`, `jurnalSetorSimpananWajib`, dst.).
2. **Pecah `AutoJournalingService`** menjadi *facade* tipis + journaler domain:
   - `app/Services/Jurnal/JournalKebajikan.php`, `JournalBebanShu.php`, `JournalSimpananModal.php`, `JournalDeposito.php`, `JournalPembiayaan.php`, `JournalKas.php`.
   - Pertahankan `PostingJurnal::post()` sebagai **satu-satunya gerbang** validasi/balance (agar kontrol PSAK tidak terduplikasi), lalu delegasikan per-domain. `AutoJournalingService` tetap menjadi pintu masuk publik yang kompatibel (implementasi `JournalPosterInterface`).
   - **Status: SELESAI (r4)** — seluruh resep jurnal sudah dipindahkan; `AutoJournalingService` kini 100% delegasi (1.259 baris termasuk docblock), journaler: Kebajikan 122, BebanShu 158, SimpananModal 509, Deposito 422, Pembiayaan 357, Kas 111 baris. Verifikasi per domain: Ta'zir 220, Beban/SHU 149, Simpanan Modal 172, Deposito 150, Pembiayaan 233, Kas/Teller 280 test hijau; suite penuh **1865 passed (8.838 assertions)**.
3. **Pecah `TellerController`** per use-case ke `app/Actions/Teller/` (mis. `SetorTunaiAction`, `TarikTunaiAction`, `OpnameAction`, `TransferBrankasAction`), controller hanya orkestrasi + validasi HTTP.
   - **Status: SELESAI (r5)** — 12 Action terbentuk di `app/Actions/Teller/`: `BukaKasAction`, `TambahModalKasAction`, `SetorKasKeBrankasAction`, `TutupKasAction`, `SetorTunaiAction`, `TarikTunaiAction`, `BukaDepositoAction`, `CairkanDepositoAction`, `ProsesPencairanAction`, `BayarAngsuranAction`, `ProsesTazirAction`, `AjukanVoidAction`. `TellerController` **1.877 → 844 baris (−55%)**; validasi HTTP tetap di controller, sedangkan logika bisnis + `DB::transaction` + pemanggilan jurnal berada di Action. Verifikasi bertahap: Kas/Brankas 298, Setor/Tarik 366, Deposito/Pencairan 587, Angsuran/Ta'zir/Void 85 test hijau; suite penuh **1865 passed (8.838 assertions)**.
   - Catatan: `pencairanIndex`, `pencairanProcess`, dan `rekapStore` di `TellerController` adalah kode legacy tanpa rute aktif (rute `teller.pencairan.proses` → `PencairanController`, rute `teller.kas.closing*` → `Teller\ClosingController`); dibiarkan apa adanya agar perilaku tidak berubah.
4. **Perkenalkan `Repository`** untuk query kompleks yang tersebar (mis. laporan) di `app/Repositories/`, dibalik interface (lihat R3).
   - **Status: SELESAI (r6)** — direktori `app/Repositories/` dibentuk dengan **3 repository** (masing-masing berpasangan kontrak di `app/Contracts/`):
     | Kontrak | Implementasi | Pemakai | Query yang dipindah |
     |---|---|---|---|
     | `KinerjaStafRepositoryInterface` (4 method) | `KinerjaStafRepository` | `ManajerController::kinerjaStaf()` | agregat pengajuan/transaksi per petugas, resolusi kelompok peran, identitas pengguna |
     | `PembiayaanBermasalahRepositoryInterface` (2 method) | `PembiayaanBermasalahRepository` | `ManajerController::npf()` | relasi terfilter + `orderByRaw` subkueri tunggakan terlama + paginasi |
     | `AuditAkadRepositoryInterface` (4 method) | `AuditAkadRepository` | `DpsController::auditAkad()` | tabel terfilter, badge counter per akad, total "Semua Akad", ringkasan per akad & status |
   - Binding ditambahkan di `AppServiceProvider` (**7 kontrak** total); `KontrakArsitekturTest` naik dari 5 → 8 pasangan kontrak/implementasi dan membuktikan tiap kontrak dapat di-*swap* tanpa menyentuh pemanggil.
   - Controller hanya menyisakan orkestrasi + validasi HTTP: `DpsController::auditAkad()` tetap memegang whitelist akad & normalisasi status (`Pembiayaan::normalizeStatus`) karena itu urusan masukan HTTP, sedangkan seluruh kueri berada di repository.
   - Bukti *behavior-preserving*: `DpsAuditAkadQueryTest` (5 test lama) tetap hijau tanpa diubah, ditambah 8 test karakterisasi baru di `DpsAuditAkadRepositoryTest`; `ManajerKinerjaStafRepositoryTest` 6 test dan `PembiayaanBermasalahRepositoryTest` 7 test. Suite penuh **1886 passed (8.931 assertions)**.
   - Catatan temuan: `HierarkiRole::kelompok('ao')`/`('teller')` mengembalikan `[]` karena keduanya **nama peran**, bukan nama kelompok. Laporan Kinerja Staf harus memakai kelompok `kinerja_ao`/`kinerja_teller` (`config/role_hierarki.php`); versi repository awal memakai nama peran sehingga daftar petugas kosong — sudah diperbaiki.
5. **Uji regresi tiap langkah**: ekstraksi harus *behavior-preserving* — suite tetap 1336+ hijau setelah tiap pemecahan.

### 2.4 Standar Kode
- **PSR-12**; satu kelas = satu tanggung jawab; kelas ≤ **500 baris** (target), method ≤ **40 baris**.
- **Type declarations** wajib (`declare(strict_types=1)`), return type & parameter typed.
- Controller **tanpa** query `DB::`/Eloquent kompleks (bukti saat ini hanya `CoaController` yang masih pakai `DB::` — bersihkan).
- Nama: `Action` berakhiran `Action`, `Repository` berakhiran `Repository`, interface berawalan `I…`/berakhiran `Interface`.
- Docblock Bahasa Indonesia untuk kontrak publik (konsisten dengan kode eksisting).

### 2.5 Skenario Pengujian
1. **Karakterisasi (characterization test)**: sebelum ekstraksi, kunci perilaku tiap method journaler dengan test yang membaca hasil jurnal (debet/kredit/no_jurnal) → jamin identik sesudah ekstraksi.
2. **Unit per kelas**: setiap `Journal*` diuji terpisah dengan `RefreshDatabase`.
3. **Integrasi**: alur E2E (setoran → jurnal → laporan) tetap sama.
4. **Static**: tambah `phpstan`/`larastan` level ≥5 pada CI; gagalkan bila kelas > 500 baris (custom rule).

### 2.6 Kriteria Keberhasilan Terukur
| Metrik | Sekarang | Target |
|---|---|---|
| Baris `AutoJournalingService` | 1993 | ≤ 300 (facade) |
| Kelas terbesar di `app/` | 1993 | ≤ 700 |
| Kelas > 500 baris | 7+ | ≤ 2 |
| Suite test | 1858 passed | ≥ 1858 passed, 0 failed |
| Larastan | belum ada | level ≥ 5, 0 error |

> **Catatan pembaruan 21 Oktober 2026 (langkah 2 dokumen §4.3 — migrasi konsumen konkret).**
> Pemecahan per-domain (butir 2 di atas) **belum** dilakukan; yang sudah dituntaskan adalah **penyeragaman jalur resolusi akun** di dalam `AutoJournalingService`: `pendapatanByAkad()`, `piutangByAkad()`, dan `jurnalReversalPencairanPembiayaan()` kini memanggil `CoaResolver` (sebelumnya `App\Support\Coa` statis). Akibatnya perpindahan akun lewat Kebijakan Koperasi berlaku **konsisten** pada pencairan, angsuran, dan pembatalan pencairan — sebelumnya piutang bertambah di akun baru tetapi berkurang di akun lama sehingga saldo tidak pernah nol.
> Ini menaikkan jumlah baris (1788 → 1993) karena docblock alasan pemakaian resolver; pemecahan facade tetap menjadi pekerjaan berikutnya.

---

## 3. REKOMENDASI 2 — KONFIGURASI TANPA MODIFIKASI KODE INTI

### 3.1 Masalah
Sudah ada `Setting` (60+ kunci) + `config/role_hierarki.php` + `config/keamanan.php`, **tetapi**: 279× `round(…,2)` masih hardcode; beberapa literal aturan bisnis tersebar; belum ada pemisahan tegas antara *secret/lingkungan* (env) vs *kebijakan bisnis* (Setting) vs *struktur* (config).

### 3.2 Rekomendasi
Terapkan **taksonomi konfigurasi 3-lapis** yang eksplisit:
- **Env (`.env`)** → rahasia & perbedaan lingkungan (kredensial DB, Redis, mail, feature flag infra).
- **`config/*.php`** → struktur/taksonomi yang stabil (RBAC, keamanan, denyut, pdf).
- **`Setting` (DB)** → kebijakan bisnis yang berubah tanpa deploy (limit, nisbah, kadar, ambang).

### 3.3 Langkah Implementasi
1. **Ganti 279× `round(…,2)`** → `Setting::pembulatanNominal()` secara bertahap per modul (mulai `LaporanKeuanganSyariahService` 50×, `DepositoService` 24×, `ShuController` 19×).
2. **Ganti `0.01` toleransi** → `Setting::toleransiSelisihJurnal()` di 4 lokasi.
3. **Pindahkan literal role** ke `config/role_hierarki.php`; hapus role phantom `'keuangan'` di `PengeluaranBebanController:33`.
4. **Tambah parameter zakat** (menutup temuan audit): `zakat_rate` (2.5), `zakat_nisab_gram` (85), `zakat_haul_bulan` (12), `harga_emas_harian`.
5. **Feature flag infra di `.env`**: `FEATURE_QUEUE_ASYNC=false`, `HEALTH_ENDPOINT_ENABLED=true` — default aman (perilaku lama).
6. **Config eksternal opsional**: dukung `config/override/*.php` (atau `.env.*`) yang dimuat `AppServiceProvider` bila ada — untuk deployment multi-tenant tanpa mengubah repo.

### 3.4 Standar Kode
- **Zero magic number**: setiap angka kebijakan wajib berasal dari `Setting`/`config()`.
- Kunci `Setting` baru **wajib** didaftarkan di `DEFAULTS` + peta *cast* + `DEFAULTS` dokumentasi.
- Nilai default **harus sama dengan perilaku saat ini** (prinsip "default aman" — sudah dipakai proyek).
- Test "anti-regresi hardcode": grep-gate di CI yang menolak `round(…,2)`/`0.01` pada modul yang sudah dimigrasikan.

### 3.5 Skenario Pengujian
1. Ubah `pembulatan_desimal` 0→2 via UI → nilai jurnal & laporan ikut berubah tanpa deploy.
2. Ubah `toleransi_selisih_jurnal` → validasi balance mengikuti.
3. Set `zakat_rate`/`zakat_nisab_gram` → kalkulasi zakat mengikuti.
4. Set `FEATURE_QUEUE_ASYNC=true` → perilaku E2E identik (lihat R4/R5).
5. Nonaktifkan `KEAMANAN_AUDIT_PII` → observer PII mati (sudah ada).

### 3.6 Kriteria Keberhasilan Terukur
| Metrik | Sekarang | Target |
|---|---|---|
| `round(…,2)` hardcode | 279 | 0 (pada modul termigrasi) |
| Literal `0.01` toleransi | 4 | 0 |
| Literal role di controller | 5 | 0 |
| Parameter zakat terkonfigurasi | 0 | 4 kunci |
| Perubahan kebijakan butuh deploy | Ya (sebagian) | Tidak |

---

## 4. REKOMENDASI 3 — ABSTRAKSI & PENGURANGAN COUPLING

### 4.1 Masalah
`AppServiceProvider::register()` **kosong**; tidak ada `app/Contracts`. 67 import service konkret di 44 file + 3 instansiasi `new` → perubahan tanda tangan satu service merembet luas (melanggar Dependency Inversion).

### 4.2 Rekomendasi
Perkenalkan **kontrak (interface) pada lapisan dependen** + binding di container, tanpa mengubah pemanggil lama (aditif).

### 4.3 Langkah Implementasi
1. Buat `app/Contracts/`:
   - `JournalPosterInterface` → `AutoJournalingService`
   - `PolicySourceInterface` → `App\Support\Setting`
   - `PeriodResolverInterface` → `TahunBukuService`
   - `CoaResolverInterface` → `CoaResolver`
   - `ApprovalGuardInterface` → `PemisahanTugas`
2. **Bind** di `AppServiceProvider::register()`: `$this->app->bind(JournalPosterInterface::class, AutoJournalingService::class);` dst.
3. **Migrasi konsumen bertahap**: ganti tipe dependency di constructor dari kelas konkret → interface (per modul, dengan test regresi tiap langkah).
4. **Hilangkan `new \App\Services\…`** (3 lokasi) → inject via container.
5. **Facade/alias opsional** agar pemanggil statis lama (`Setting::get()`) tetap bekerja.

### 4.4 Standar Kode
- **Dependency Inversion**: high-level module bergantung pada abstraksi, bukan konkresi.
- Interface **kecil & fokus** (≤ 7 method); dokumentasikan kontrak.
- **Tidak ada** `new` untuk service di dalam service/controller (selain value object/DTO).
- Binding **selalu** di ServiceProvider, bukan di controller.

### 4.5 Skenario Pengujian
1. `app()->make(JournalPosterInterface::class)` → instance benar.
2. **Mock test**: bind fake `PolicySourceInterface` → service memakai fake (bukti decoupling).
3. **Swap test**: ganti implementasi `JournalPosterInterface` dengan double → konsumen tidak berubah.
4. Suite penuh tetap 1336+ hijau (bukti nol regresi).

### 4.6 Kriteria Keberhasilan Terukur
| Metrik | Sekarang | Target |
|---|---|---|
| Interface di `app/Contracts` | 0 | ≥ 5 |
| Binding di ServiceProvider | 0 | ≥ 5 |
| `use App\Services\` langsung | 67 | turun ≥ 50% |
| `new \App\Services\` | 3 | 0 |
| Service dapat di-mock tanpa boot penuh | Tidak | Ya |

---

## 5. REKOMENDASI 4 — PENGELOLAAN DATA & ALUR PROSES DINAMIS

### 4.1 Masalah
Alur persetujuan (`status_menunggu`, `rantai`) sudah di `config/role_hierarki.php` (baik), tetapi: **dua sumber kebenaran SHU** (`Setting::alokasiShu()` 4 pos vs `shu_periods` 5 pos input); transisi status & aturan approval sebagian di kode; operasi berat sinkron; tanpa event.

### 4.2 Rekomendasi
Jadikan **aturan bisnis sebagai data**, bukan kode: state machine status, matriks approval, pemetaan akad→CoA, dan alur proses asinkron berbasis event.

### 4.3 Langkah Implementasi
1. **State machine terpusat**: definisikan transisi status (mis. `PENDING_AO → DIREVIEW → PENDING_PENGURUS → DICAIRKAN`) di `config/alur_pembiayaan.php`; guard transisi membaca config (bukan `if` tersebar).
2. **Matriks approval config-driven**: pindahkan `rantai`/`status_menunggu`/`batas` (sudah ada) menjadi satu sumber yang dipakai **semua** modul — termasuk menutup temuan SoD di `PengajuanPembiayaanIntegController`.
3. **Satukan SHU**: `Setting::alokasiShu()` jadi **satu-satunya** sumber; `ShuController::store()` menghitung 5 pos dari kebijakan (hapus input manual) atau memvalidasi terhadapnya dalam toleransi.
4. **Pemetaan akad→CoA** (sudah ada `pemetaan_akad_coa` + `CoaResolver`) → lengkapi seed produk & jadikan UI-editable.
5. **Lapisan asinkron**: publikasikan tabel `jobs`/`failed_jobs`; ekstrak operasi berat ke `app/Jobs/` (`ProsesJatuhTempoDepositoJob`, `DistribusiBagiHasilJob`, `TutupBukuShuJob`, `ProsesTagihanSimpananWajibJob`) dengan `ShouldQueue`, `$tries`, `$backoff`, `$timeout`, `failed()`, dan **idempotency guard**.
6. **Event-driven**: aktifkan discovery; buat `app/Events/` (`AngsuranLunas`, `PembiayaanDicairkan`, `ShuDidistribusikan`, `DepositoJatuhTempo`, `KebijakanBerubah`) + `app/Listeners/` (audit, notifikasi, kolektibilitas, invalidasi cache) — **idempoten**.
7. **Saklar aman**: semua di balik `FEATURE_QUEUE_ASYNC` (default `false`) sampai lulus uji.

### 4.4 Standar Kode
- **Aturan bisnis = data**: tidak ada `if ($status === 'X')` yang menduplikasi config.
- **Job idempoten**: cek status (`is_processed`) sebelum efek; aman di-retry.
- **Event listener idempoten** & tidak melempar ke pemanggil (async-safe).
- Perubahan `Setting` memicu event `KebijakanBerubah` → invalidasi cache lintas-node.

### 4.5 Skenario Pengujian
1. **Transisi status**: setiap transisi sah diizinkan, yang tidak sah ditolak (data-driven test dari config).
2. **SoD**: pembuat = pemutus → ditolak (menutup temuan audit T-01).
3. **Satu sumber SHU**: test gagal bila 5 pos ≠ kebijakan di luar toleransi.
4. **Idempotency job**: jalankan job 2× → jurnal/efek hanya sekali.
5. **Retry & failed_jobs**: kegagalan sengaja → masuk `failed_jobs`; retry sukses tanpa efek ganda.
6. **Event**: `Event::fake()` + `assertDispatched`; listener 2× → efek sekali.

### 4.6 Kriteria Keberhasilan Terukur
| Metrik | Sekarang | Target |
|---|---|---|
| Sumber kebenaran SHU | 2 | 1 |
| Aturan approval di config | sebagian | 100% |
| Job asinkron | 0 | ≥ 4 |
| Event domain | 0 | ≥ 5 |
| Idempotency job terbukti | — | 100% job |
| Endpoint berat (p95) | detik | turun ≥ 70% |

---

## 6. REKOMENDASI 5 (LINTAS-CUTTING) — STANDAR, PENGUJIAN, KRITERIA SUKSES

Bagian ini memenuhi permintaan poin 5: setiap rekomendasi di atas **wajib** disertai keempat elemen berikut (matriks konsolidasi).

### 6.1 Standar Kode Wajib (berlaku semua rekomendasi)
| Aspek | Standar |
|---|---|
| Gaya | PSR-12, `declare(strict_types=1)`, type hint penuh |
| Ukuran | Kelas ≤ 500 baris; method ≤ 40 baris; kompleksitas siklomatik ≤ 10 |
| Larangan | `round(…,2)` & `0.01` hardcode; literal role; `new Service` di controller; query `DB::` di controller |
| Wajib | Interface untuk dependensi lintas-modul; binding di ServiceProvider; docblock ID |
| Statik | Larastan/PHPStan level ≥ 5, 0 error |
| Uji | Setiap perubahan menyertakan test; suite ≥ baseline (1336 passed) |

### 6.2 Skenario Pengujian Konsolidasi (verifikasi kinerja & stabilitas)
| Kategori | Skenario | Kriteria Lulus |
|---|---|---|
| Regresi | Suite penuh tiap fase | ≥ 1336 passed, 0 failed |
| Kontrak | `app()->make(Interface)` & mock/swap | Instance benar, konsumen tak berubah |
| Config | Ubah `Setting` → perilaku berubah tanpa deploy | Nilai jurnal/laporan ikut |
| Dinamis | Transisi status & SoD data-driven | Sah diizinkan, tak sah ditolak |
| Asinkron | Job 2× (idempotensi), retry, `failed_jobs` | Tanpa efek ganda, tanpa kehilangan |
| Beban | Operasi berat dipindah ke queue | p95 endpoint turun ≥ 70% |
| Konkurensi | N transaksi bersamaan | Jurnal tetap balance, 0 orphan |
| Multi-node | 2 worker + cache/session Redis | Tanpa race/kebocoran |
| Kepatuhan | PSAK 101/102/105/108/109 + OJK | Test kepatuhan tetap hijau |

### 6.3 Kriteria Keberhasilan Akhir (measurable — "definition of done")
| Dimensi | KPI | Target |
|---|---|---|
| **Modularitas** | Kelas > 500 baris | ≤ 2 |
| | Kelas terbesar | ≤ 700 baris |
| **Konfigurasi** | Magic number kebijakan | 0 |
| | Perubahan kebijakan tanpa deploy | 100% |
| **Coupling** | Interface kontrak | ≥ 5 |
| | `new Service` di controller/service | 0 |
| **Dinamis** | Aturan bisnis di config | 100% |
| | Job asinkron + event | ≥ 4 job, ≥ 5 event |
| **Skalabilitas** | Health/ready endpoint | Ada, 200/503 benar |
| | Cache/session/queue shared | Redis-ready (env) |
| | Multi-node terbukti | Ya (uji 2 worker) |
| **Kualitas** | Suite test | ≥ 1336 passed, 0 failed |
| | Larastan | level ≥ 5, 0 error |

---

## 7. ROADMAP & DEPENDENSI

```
FASE A — Kontrak & Abstraksi (R3)         [fondasi, wajib pertama]
   └─> FASE B — Modularisasi & SRP (R1)   [paralel dgn C]
   └─> FASE C — Konfigurasi & Zero-Hardcode (R2)
FASE D — Aturan Dinamis + Asinkron + Event (R4)   [bergantung A & C]
   └─> FASE E — Shared Runtime, Health, API v1     [bergantung D]
```

- **A** memberi titik ekstensi → tanpa ini B/C/D berisiko regresi.
- **C** menutup hardcode → prasyarat aturan bisnis jadi data (D).
- **D** adalah inti "proses dinamis + integrasi longgar".
- **E** adalah inti "scalable".
- Setiap fase **aditif**, di balik feature flag (default = perilaku lama), dan **wajib** lulus suite ≥ baseline sebelum lanjut.

---

## 7B. STATUS IMPLEMENTASI R4 (bukti kode)

Bagian ini mencatat realisasi Rekomendasi 4 pada basis kode. Seluruh perubahan
bersifat **aditif** dan **tidak mengubah perilaku uang/jurnal**; saklar asinkron
tetap **default `false`** (perilaku lama).

| Langkah (§4.3) | Artefak | Status |
|---|---|---|
| 1. State machine terpusat | `config/alur_pembiayaan.php` + `App\Support\AlurStatusPembiayaan`; test `tests/Feature/AlurStatusPembiayaanTest.php` (9) | ✅ |
| 2. Matriks approval config-driven | `config/alur_pembiayaan.php` + `App\Support\MatriksApproval` + `App\Contracts\ApprovalGuardInterface`; test `tests/Feature/MatriksApprovalTest.php` (10) | ✅ |
| 3. Satukan sumber SHU | Lihat §7C di bawah | ✅ |
| 4. Pemetaan akad→CoA | `pemetaan_akad_coa` + `App\Contracts\CoaResolverInterface` (sudah ada; dilengkapi kontrak) | ✅ |
| 5. Lapisan asinkron | `app/Jobs/`: `ProsesJatuhTempoDepositoJob`, `DistribusiBagiHasilJob`, `TutupBukuShuJob`, `ProsesTagihanSimpananWajibJob` (4 job, idempotent); tabel `jobs`/`failed_jobs`; test `tests/Feature/JobAsinkronTest.php` (11) | ✅ |
| 6. Event-driven | `app/Events/`: `AngsuranLunas`, `PembiayaanDicairkan`, `ShuDidistribusikan`, `DepositoJatuhTempo`, `KebijakanBerubah` (5); `app/Listeners/`: `CatatPeristiwaDomain`, `InvalidasiCacheKebijakan`; test `tests/Feature/PeristiwaDomainTest.php` | ✅ |
| 7. Saklar aman | `FEATURE_QUEUE_ASYNC` (default `false`) — `config/queue.php` / `.env.example` | ✅ |

### Kriteria §4.6 — realisasi
| Metrik | Sebelum | Target | Capaian |
|---|---|---|---|
| Sumber kebenaran SHU | 2 | 1 | **1** (§7C) |
| Aturan approval di config | sebagian | 100% | **100%** (`MatriksApproval`) |
| Job asinkron | 0 | ≥ 4 | **4** |
| Event domain | 0 | ≥ 5 | **5** |
| Idempotency job | — | 100% job | **4/4** (diuji `JobAsinkronTest`) |

---

## 7C. KONTRAK SUMBER TUNGGAL SHU (R4-5)

Sebelumnya alokasi SHU memiliki **dua sumber kebenaran**: `Setting::alokasiShu()`
(**4 pos** kebijakan, `shu_pct_*`) versus tabel `shu_periods` (**5 pos** input).
R4-5 menetapkan kontrak sumber tunggal berikut:

1. **TINGKAT KEBIJAKAN** — `Setting::alokasiShu()` adalah **satu-satunya** pembaca
   persentase kebijakan (4 pos: `cadangan_modal`, `jasa_anggota`, `jasa_modal`,
   `dana_pendidikan`), bersumber dari `shu_pct_*` dan **wajib** berjumlah 100%
   (`Setting::totalPersenAlokasiShu()` / `Setting::validasiTotalAlokasiShu()`).
   Tidak ada modul lain yang boleh membaca `shu_pct_*` secara langsung.
2. **TINGKAT PERIODE** — `Setting::alokasiShuDrafDefault()` adalah **jembatan
   tunggal 4→5 pos** menuju `shu_periods`. Pemetaan tetap:
   `cadangan_modal→persen_cadangan`, `jasa_modal→persen_jasa_modal`,
   `jasa_anggota→persen_jasa_transaksi`, `dana_pendidikan→persen_sosial`,
   sisanya → `persen_pengurus` (dijaga ≥ 0). Konsumen resmi: `ShuController::store()`,
   `Dashboard\PengurusController`, dan view `pengurus.shu.index`.
3. **ANGKA PERIODE = HISTORIS** — tabel `shu_periods` menyimpan angka hasil
   keputusan per tahun buku. Perubahan kebijakan **tidak menimpa** periode yang
   sudah ada (menjaga jejak audit).

**Bukti:** `tests/Feature/PenyatuanSumberShuTest.php` (8 test) mengunci keempat
butir di atas — termasuk total tepat 100%, pemetaan pos, delegasi
`KebijakanKoperasi`, ketiadaan literal persen di view kebijakan & `TestShu.php`,
serta kekekalan angka historis periode.

---

## 8. CATATAN AKHIR

- Seluruh temuan bersumber dari **verifikasi kode langsung** (glob/grep/read), bukan asumsi dokumen lama. Sebagian dokumen lama (`AUDIT-PARAMETER-PSAK-FLEKSIBILITAS.md`) sudah **stale** (tahun buku kini sudah dinamis).
- Analisis ini **read-only** — belum ada perubahan kode. Implementasi menunggu persetujuan.
- Prioritas: **R3 (kontrak) → R2 (zero-hardcode) → R1 (SRP) → R4 (dinamis/asinkron)**.
