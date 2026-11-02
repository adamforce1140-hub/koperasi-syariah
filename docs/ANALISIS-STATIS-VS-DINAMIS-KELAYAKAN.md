# ANALISIS KELAYAKAN: SISTEM STATIS vs DINAMIS
## Evaluasi Kebutuhan Implementasi Fitur Dinamis — Koperasi Syariah

Tanggal: 2026-10-05
Sifat: **analisis keputusan (read-only)** — basis rekomendasi sebelum investasi pengembangan.

---

## 0. RINGKASAN KEPUTUSAN (TL;DR)

**Rekomendasi: TIDAK mengimplementasikan semua fitur dinamis secara menyeluruh. Terapkan pendekatan SELEKTIF berbasis risiko ("dinamis di tempat yang tepat").**

Sistem **sudah punya fondasi dinamis yang matang dan justru unggul** di area yang paling bernilai: kebijakan moneter operasional (`Setting`, ±60 parameter), produk (simpanan/deposito/pembiayaan), akad→CoA, tahun buku, dan RBAC. Menjadikan **semuanya** dinamis akan **menurunkan** keandalan sistem keuangan yang wajib auditabel (PSAK/OJK), bukan meningkatkannya.

**Namun** audit menemukan **1 celah dinamis bernilai tinggi yang benar-benar perlu** dan **beberapa area yang justru harus TETAP statis**.

> **Status per verifikasi ulang:** kedua celah kebijakan di bawah **sudah ditutup**
> di kode (lihat §1.2). Dokumen ini dipertahankan sebagai catatan keputusan;
> daftar pekerjaan yang benar-benar tersisa ada di §5.2.

| Keputusan | Komponen | Alasan |
|---|---|---|
| ✅ **SELESAI** | Parameter zakat SHU (`rate`/`nisab`/`haul`) | Dulu tidak ada; kini tersedia di `Setting` (`zakat_rate`, `zakat_nisab_gram`, `zakat_haul_aktif`, `zakat_haul_hari`, `zakat_porsi_amil_persen`) |
| ✅ **SELESAI** | Kebijakan alokasi SHU (5 pos vs 4 pos) | Dulu dua sumber; kini dijembatani `Setting::alokasiShuDrafDefault()` |
| ✅ **PERLU (opsional, terukur)** | Eksekusi asinkron (queue) | Bukan soal "dinamis", tapi soal beban kerja — lihat 3.3 |
| ⛔ **TETAP statis** | Matriks RBAC & pemisahan tugas | Harus diaudit baris-per-baris; dinamis = celah keamanan tak terlihat |
| ⛔ **TETAP statis** | Aturan inti akuntansi (PSAK 108/109, akun sah per post-type) | Standar eksternal, bukan preferensi; mengubahnya = pelanggaran regulasi |
| ⛔ **TETAP statis** | Status/enum domain (status anggota, CDD, kolektibilitas) | Bernilai tetap lintas koperasi; dinamis = kompleksitas tanpa manfaat |
| ⚠️ **SUDAH dinamis, jangan diutak-atik** | Nominal, tahun buku, produk, akad, pembulatan | Sudah benar; menambah lapisan = risiko regresi |

---

## 1. IDENTIFIKASI KOMPONEN STATIS & DAMPAK POTENSIAL JIKA DINAMISKAN

### 1.1 ✅ Komponen statis yang SUDAH tepat (jangan dinamiskan)

| Komponen | Bukti | Dampak jika dipaksa dinamis |
|---|---|---|
| **Enum status anggota** | `Anggota::STATUS_LIST`, `STATUS_LABEL`, `STATUS_ALIASES`, `STATUS_PILIHAN_MANUAL` | 🔴 **Negatif** — 8 status dengan alias & aturan transisi; dinamis memindahkan logika state-machine ke DB → sulit divalidasi, rawan status ilegal |
| **Klasifikasi risiko & CDD** | `Anggota::RISIKO_PILIHAN`, `CDD_PILIHAN`, `PEP_PILIHAN` | 🔴 **Negatif** — ditetapkan regulasi (APU-PPT/OJK), bukan preferensi koperasi |
| **Aturan akun sah per post-type** | `AutoJournalingService::AKUN_KREDIT_SAH_BY_POST_TYPE`, `AKUN_DANA_SOSIAL`, `PREFIX_PENDAPATAN_OPERASIONAL='4-'` | 🔴 **Negatif fatal** — ini *guard* integritas jurnal. Dinamis = pintu masuk jurnal salah akun yang lolos validasi |
| **Prefix & jenis akun CoA** | `Coa` (registri), pengelompokan `1-/2-/3-/4-/5-` | 🔴 **Negatif** — struktur bagan akun adalah kerangka standar, bukan konfigurasi |
| **Kode asnaf zakat** | `ZakatService::ASNAF` (8 asnaf) | 🟡 **Batas** — ditetapkan QS At-Taubah 60 (tetap secara syariah) |
| **Pemetaan pos SHU→akun CoA** | `ShuController` memakai `Setting::alokasiShuDrafDefault()` + `pemetaan_akad_coa` | 🟢 Sudah terhubung & disatukan |

### 1.2 🟡 Komponen statis yang BERMANFAAT dijadikan dinamis (nilai tinggi)

| Komponen | Status saat ini | Nilai jika dinamis | Prioritas |
|---|---|---|---|
| **Zakat atas SHU** | ✅ **SUDAH DIPENUHI** — `Setting` memuat `zakat_rate` (2,50), `zakat_nisab_gram` (85), `harga_emas_per_gram`, `zakat_minimal_tarik`, `zakat_haul_aktif`, `zakat_haul_hari` (354), `zakat_porsi_amil_persen` (12,50), `zakat_ambang_persetujuan`; diakses lewat `Setting::zakatRate()` / `Setting::hitungZakat()` | Tidak ada lagi pekerjaan tersisa | ✅ Selesai |
| **Kebijakan alokasi SHU** | ✅ **SUDAH DISATUKAN** — `Setting::alokasiShu()` (4 pos kanonik) dijembatani `Setting::alokasiShuDrafDefault()` ke 5 pos; `Pengurus\ShuController:108` dan `PengurusController:775` sama-sama membaca jembatan itu, bukan menulis ulang persentase | Risiko salah alokasi dana anggota tertutup | ✅ Selesai |
| **Parameter zakat/infak terkait kebijakan lain** (mis. persen amil) | ✅ **SUDAH ADA** — `zakat_porsi_amil_persen` (batas 1/8 sesuai Fatwa DSN-MUI 08) | Fleksibilitas penyaluran sesuai fatwa DSN-MUI | ✅ Selesai |
| **Override limit per peran** | 🟢 **sudah dinamis** via tabel `limit_transaksi_role` + `config/role_hierarki.php` (lihat `HierarkiRole`) | — sudah selesai, contoh pola yang benar | ✅ Selesai |

### 1.3 ⚠️ Komponen statis "abu-abu" — dinamis opsional dengan biaya

| Komponen | Bukti | Pertimbangan |
|---|---|---|
| **Nisbah default & rate asumsi deposito** | `DepositoService::ASUMSI_RATE_PA=0.08` (konstanta dokumentasi), `Setting::depositoRatePa()`, `nisbahDefaultPersen()`, `marginDefaultPersen()` | 🟢 Sudah dinamis (konstanta hanya dokumentasi) → **tidak perlu diubah** |
| **Penalti deposito default** | `DepositoProduct::PENALTI_AMOUNT_DEFAULT=50000`, `PENALTI_COA_DEFAULT` | 🟢 Sudah jadi *fallback* produk; dinamis via produk → **cukup, tidak perlu parameter global** |
| **`round(...,2)`** | Verifikasi ulang: **52 kemunculan di 19 berkas `app/`** (angka lama "362/48" menghitung seluruh repo termasuk test, view, seeder, migration, dan view terkompilasi). Telaah lanjutan: hanya **9** yang benar-benar membulatkan **uang ke 2 desimal**, dan kesembilannya berada di `app/Support/Setting.php` — kelas yang *mendefinisikan* `DESIMAL_UANG`. Sisanya pembulatan hari/bulan/rasio/latensi, pembulatan ke **rupiah penuh** yang disengaja, atau konversi ke sen `(int) round($x * 100)` | 🟢 **BUKAN utang.** Tidak ada konsolidasi yang perlu dikerjakan; mengganti kelompok terakhir dengan `Setting::bulatkanUang()` justru **mengubah perilaku** |
| **Toleransi selisih jurnal `0.01`** | `Setting::toleransiSelisihJurnal()` ada, tapi `0.01` masih tersebar | 🟡 Sama — konsolidasi, bukan dinamisisasi |

### 1.4 ⛔ Komponen statis yang BERISIKO jika dinamis (jangan)

| Komponen | Bukti | Risiko |
|---|---|---|
| **Matriks RBAC laporan** | `LaporanKeuanganPermission::MATRIKS` (peran×modul×hak), `PERAN`, sengaja ditulis lengkap *"agar dapat diaudit baris per baris"* | 🔴 Dinamis = siapa pun yang bisa mengedit DB/matriks dapat **meningkatkan hak aksesnya sendiri** tanpa jejak kode (privilege escalation). Auditability hilang |
| **Rantai approval maker-checker** | `HierarkiRole` (rantai dari config), `ApprovalTransaksi` status `PENDING_<PERAN>` | 🔴 Pemisahan tugas (GCG) harus **tidak dapat diubah saat runtime** |
| **Guard penarikan simpanan modal** | `GuardSimpananModal::JENIS_REKENING_DAPAT_DITARIK`, `KODE_AKUN_MODAL`, `PESAN` | 🔴 Pelindung dana; dinamis = dana modal berisiko ditarik tunai |
| **Fitur denda/ta'zir & saklar** | `TazirService` (mode FLAT_HARIAN, grace period, rate 2000) — **sudah** membaca `Setting` | 🟢 Sudah dinamis + ada saklar ON/OFF → pola tepat, jangan diubah |

---

## 2. EVALUASI KEBUTUHAN FUNGSIONAL & NON-FUNGSIONAL

### 2.1 Kebutuhan Fungsional
Koperasi simpan-pinjam syariah memiliki **variabilitas kebijakan tinggi** (nominal simpanan, bagi hasil, nisbah, tahun buku, ta'zir, penanganan tunggakan) tetapi **aturan inti akuntansi & syariah hampir tidak bervariasi** (PSAK 101/108/109/110, QS At-Taubah). 
→ **Kesimpulan:** kebutuhan dinamis **terpusat di lapisan kebijakan**, **bukan di lapisan aturan**. Sistem sudah memenuhi ini dengan baik.

### 2.2 Skalabilitas
| Dimensi | Status | Kebutuhan nyata |
|---|---|---|
| Beban request web | 🟢 Baik — full table scan dihapus (TAHAP7), indeks ditambah | Skala koperasi (ribuan anggota) → **memadai** |
| Beban puncak (tutup buku, distribusi SHU) | 🔴 **Risiko** — `QUEUE_CONNECTION=sync`, tidak ada `app/Jobs/`, tidak ada tabel `jobs`/`failed_jobs` | **Perlu asinkron** untuk operasi berat agar tidak memblokir request |
| Multi-node | 🔴 Cache/session `file` | **Belum perlu** untuk koperasi tunggal; siapkan hanya bila ekspansi multi-cabang |
> **Catatan penting:** ini **bukan** soal "fitur dinamis". Ini soal **arsitektur eksekusi**. Memaksa "semuanya dinamis" tidak menyelesaikan beban; **queue** yang menyelesaikan.

### 2.3 Maintainability
- 🟢 **Kuat:** `Setting` satu pintu, `HierarkiRole` satu pintu, `LaporanKeuanganPermission` satu pintu, `TahunBukuService` satu pintu → pola "single source of truth" konsisten.
- 🟡 **Lemah:** `0.01` masih tersebar di beberapa modul. (Dua sumber SHU **sudah disatukan**; 52 `round()` di 19 berkas `app/` **bukan** utang — lihat 1.3.)
→ **Kebutuhan:** konsolidasi konstanta toleransi, bukan penambahan lapisan dinamis baru.

### 2.4 Fleksibilitas pengembangan ke depan
- 🟢 Produk baru: cukup tambah baris master (dinamis) — **baik**.
- 🟡 Akad baru: perlu entri `pemetaan_akad_coa` + daftar akad — **setengah dinamis, memadai**.
- 🔴 Integrasi longgar antar-modul: **tidak ada** event/contract (`EventServiceProvider` kosong, `app/Contracts` kosong) → efek samping harus diubah di kode inti.
- 🔴 Observability: tanpa health endpoint, tanpa versi API.

---

## 3. ANALISIS BIAYA vs MANFAAT

### 3.1 Matriks skor (1–5; Manfaat tinggi & Biaya rendah = prioritas)

| Kandidat | Manfaat | Biaya | Kompleksitas | Risiko regresi | **Skor (M/B)** | Putusan |
|---|---|---|---|---|---|---|
| Parameter zakat SHU | 5 | 2 | Rendah | Rendah | **2.5** | ✅ **SELESAI** |
| Satukan kebijakan SHU | 5 | 2 | Rendah–sedang | Sedang (keuangan) | **2.5** | ✅ **SELESAI** |
| Queue/job asinkron | 4 | 3 | Sedang | Sedang | **1.33** | ✅ **KERJAKAN (bertahap)** |
| Konsolidasi `round()`/`0.01` | 3 | 4 | Sedang (19 berkas `app/`) | Rendah (setara perilaku) | **0.75** | ✅ `round()` **bukan utang** (lihat 1.3); sisa: konstanta `0.01` |
| Event/Listener + Contracts | 3 | 4 | Sedang–tinggi | Sedang | **0.75** | 🟡 Tunda sampai ada kebutuhan integrasi |
| Redis (cache/session/queue multi-node) | 2 | 3 | Sedang | Sedang | **0.67** | ⛔ Tunda (butuh infra) |
| API versioning | 2 | 2 | Rendah | Rendah | **1.0** | 🟡 Saat ada konsumen API |
| **Dinamiskan RBAC/matriks izin** | **1** | 4 | Tinggi | **Tinggi (keamanan)** | **0.25** | ⛔ **JANGAN** |
| **Dinamiskan enum/status domain** | **1** | 4 | Tinggi | Tinggi | **0.25** | ⛔ **JANGAN** |
| **Dinamiskan aturan akuntansi inti** | **1** | 5 | Sangat tinggi | **Sangat tinggi** | **0.2** | ⛔ **JANGAN** |

### 3.2 Estimasi waktu & sumber daya (relatif, 1 pengembang)

| Pekerjaan | Estimasi | Sumber daya |
|---|---|---|
| Parameter zakat SHU + test | Kecil | 1 dev, tanpa infra — **selesai** |
| Satukan kebijakan SHU + test | Kecil | 1 dev, tanpa infra — **selesai** |
| Queue asinkron (migrasi tabel, 3–5 job, idempotency, test) | Sedang | 1 dev + pengujian beban |
| Konsolidasi konstanta `0.01` | Kecil (tersebar) | 1 dev, iteratif |
| Event/Contracts | Sedang | 1 dev, setelah ada pemicu bisnis |
| Redis/multi-node | Sedang + infra | 1 dev + ops |
| **Dinamiskan RBAC (jika dipaksa)** | **Besar** | 1 dev + audit keamanan ulang — **biaya tinggi, manfaat negatif** |

### 3.3 Analisis khusus: kapan "dinamis" TIDAK lagi menguntungkan
Ada titik di mana penambahan konfigurasi justru **merugikan**:
- **Biaya kognitif:** setiap parameter dinamis = kombinasi keadaan yang harus diuji (ledakan ruang uji).
- **Biaya audit:** auditor harus memeriksa konfigurasi live, bukan kode — melemahkan jaminan.
- **Biaya integritas:** aturan yang seharusnya tetap (PSAK) menjadi dapat dilanggar tanpa deploy.
→ Untuk **aturan regulasi & keamanan**, "statis" adalah **fitur**, bukan keterbatasan.

---

## 4. PERBANDINGAN SKENARIO

| Kriteria | **Skenario A: Semua Statis** | **Skenario B: Semua Dinamis** | **Skenario C: Selektif (REKOMENDASI)** |
|---|---|---|---|
| Perubahan kebijakan nominal/tahun buku/produk | ❌ Butuh deploy/ubah kode | ✅ Instan via UI | ✅ Instan (sudah ada) |
| Kepatuhan PSAK/OJK | ✅ Terjamin (kode terkunci) | 🔴 **Berisiko** (aturan bisa diubah) | ✅ Terjamin + parameter resmi dinamis |
| Keamanan (RBAC/pemisahan tugas) | ✅ Kuat & auditabel | 🔴 **Bahaya privilege escalation** | ✅ Tetap kuat |
| Waktu pengembangan | ✅ Minimal | 🔴 Sangat tinggi | 🟡 Sedang (terarah) |
| Kompleksitas kode | ✅ Rendah | 🔴 Tinggi | 🟡 Sedang |
| Skalabilitas beban puncak | 🔴 `sync` memblokir | 🔴 Tidak menyelesaikan | ✅ Queue menyelesaikan |
| Maintainability | 🟡 Duplikasi tersebar | 🔴 Ledakan konfigurasi | ✅ Single source of truth diperkuat |
| Zakat (kewajiban syariah) | ❌ Tidak bisa dipenuhi | ✅ (jika dibangun) | ✅ Dibangun |
| Uji & audit | ✅ Mudah diverifikasi | 🔴 Ruang uji meledak | ✅ Terverifikasi + gate regresi |

**Kesimpulan skenario:** Skenario A **gagal fleksibilitas**, Skenario B **gagal kepatuhan & keamanan**, Skenario C **unggul di semua kriteria penting**.

---

## 5. REKOMENDASI FINAL (berbasis bukti)

### 5.1 Keputusan
> **JANGAN implementasikan "sistem dinamis menyeluruh". Implementasikan dinamisitas SELEKTIF: 2 item wajib (cepat, berdampak tinggi), 1 item opsional-bertahap (queue), dan pertahankan kekakuan pada aturan regulasi & keamanan.**

Sistem **sudah cukup dinamis untuk beroperasi optimal hari ini**. Dua celah kebijakan yang dulu ditemukan **sudah ditutup**; yang benar-benar tersisa hanyalah **kesiapan beban kerja** (queue). Klaim "utang konsistensi pembulatan" **tidak terbukti** setelah telaah ulang (lihat 1.3).

### 5.2 Persetujuan yang diminta (urutan eksekusi)

**Tahap 1 — WAJIB (manfaat tinggi, biaya rendah): ✅ SELESAI**
1. **Parameter zakat atas SHU** — ✅ tersedia: `zakat_rate`, `zakat_nisab_gram`, `harga_emas_per_gram`, `zakat_minimal_tarik`, `zakat_haul_aktif`, `zakat_haul_hari`, `zakat_porsi_amil_persen`, `zakat_ambang_persetujuan` di `Setting::DEFAULTS` + `Setting::hitungZakat()`.
2. **Satukan kebijakan SHU** — ✅ tersedia: `Setting::alokasiShu()` sebagai sumber tunggal, dijembatani `Setting::alokasiShuDrafDefault()`; `Pengurus\ShuController` dan `PengurusController` membaca jembatan itu.

**Tahap 2 — OPSIONAL (bila beban kerja terbukti jadi masalah):**
3. **Queue asinkron** untuk operasi berat (tutup buku, distribusi SHU, bagi hasil, notifikasi massal) — di balik saklar default OFF.

**Tahap 3 — BERTAHAP (opsional, bukan utang):**
4. Konsolidasi konstanta `0.01` ke `Setting` per modul. Konsolidasi `round(...,2)` **tidak diperlukan** — lihat 1.3.

**TETAP STATIS (keputusan sadar, tidak diubah):**
- Matriks RBAC & pemisahan tugas, aturan akun sah PSAK, enum/status domain, guard dana modal.

### 5.3 Bukti pendukung utama
- ✅ Dinamisitas yang **sudah ada & bernilai**: `Setting` (76 method kebijakan, ±60 kunci, cache+invalidasi+audit trail), `HierarkiRole` (override DB tanpa deploy), produk dinamis, `TahunBukuService`, `pemetaan_akad_coa`, parameter zakat.
- ❌ Celah nyata yang tersisa: `QUEUE_CONNECTION=sync` tanpa `app/Jobs/`/tabel queue.
- ⛔ Risiko dinamisisasi yang salah: `LaporanKeuanganPermission::MATRIKS` sengaja lengkap untuk auditabilitas; `GuardSimpananModal` melindungi dana modal; `AKUN_KREDIT_SAH_BY_POST_TYPE` menjaga integritas jurnal.

### 5.4 Risiko bila rekomendasi diabaikan
| Jika tetap semua statis | Jika dipaksa semua dinamis |
|---|---|
| Operasi berat memblokir request pada jam sibuk | Privilege escalation RBAC; pelanggaran PSAK dapat terjadi tanpa deploy; ruang uji & biaya audit meledak |

---

*Dokumen ini bersifat analisis keputusan. Status Tahap 1 sudah diverifikasi di kode; Tahap 2 belum dikerjakan; Tahap 3 tinggal konstanta `0.01` (konsolidasi `round()` terbukti tidak diperlukan). Gate tetap: baseline test tidak boleh turun (kini 1.953 passed / 9.214 assertions).*
