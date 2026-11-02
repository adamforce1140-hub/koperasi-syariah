# TAHAP 13 — RBAC: Hierarki Peran, Batas Operasional, & Approval Berjenjang

**Status:** ✅ SELESAI
**Tanggal:** 2026-09-30
**Tahap:** 13 (RBAC & Pengendalian Internal)
**Dokumen pendamping:** `docs/TAHAP4-GCG-OJK.md` (§2.5 Audit RBAC, §2.6 Rantai Hash),
`docs/TAHAP10-KEAMANAN-DATA-UU-PDP.md`

**Khalayak:** Pengurus, Dewan Pengawas Syariah (DPS), auditor internal & eksternal, Tim TI.

---

## 1. Ringkasan Eksekutif

Tahap 13 menghilangkan **7 celah pengendalian internal (RBAC)** yang membuat batas kewenangan
peran hanya ditegakkan sebagian: sejumlah jalur transaksi dan ekspor data dapat melampaui
limit tanpa otorisasi peran yang lebih tinggi, status approval jenjang tertentu tidak pernah
terbentuk (`PENDING_AO` dilebur ke Manajer), dan frekuensi aksi/volume data tidak dibatasi
sama sekali.

Prinsip yang dipakai konsisten dengan tahap sebelumnya:

| Prinsip | Penjabaran |
|---|---|
| **Sumber kebenaran tunggal** | `config/role_hierarki.php` + tabel `limit_transaksi_role`, diakses **hanya** lewat `App\Support\HierarkiRole` |
| **Default aman (P4)** | Seluruh nilai default = perilaku sistem saat ini; membiarkan konfigurasi apa adanya tidak mengubah apa pun |
| **Konfigurasi, bukan kode** | Peran, rantai approval, dan batas diubah dari config/UI tanpa deploy |
| **Maker ≠ Checker** | Pemisahan tugas ditegakkan (`App\Support\PemisahanTugas`); self-approval ditolak & direkam |
| **Jejak tidak dapat disunting** | Setiap peristiwa approval disegel rantai hash SHA-256, append-only |

**Hasil terukur:**

| Indikator | Sebelum Tahap 13 | Sesudah Tahap 13 |
|---|---|---|
| Sumber aturan RBAC | Tersebar: komentar model, `in_array`, konstanta di service, baris DB | **Satu kelas**: `App\Support\HierarkiRole` (config + override DB) |
| Status approval jenjang AO | Tidak pernah terbentuk (dilebur ke `PENDING_MANAJER`) | **`PENDING_AO`** nyata, diturunkan otomatis dari nama peran |
| Batas frekuensi aksi | Tidak ada | `frekuensi.maks_transaksi_per_hari` ditegakkan di titik masuk transaksi |
| Volume akses data (ekspor) | Tidak ada | `akses_data.maks_baris_ekspor` ditegakkan di **semua jalur ekspor** |
| Jalur lolos batas | ≥ 2 jalur ekspor + beberapa literal status hardcode | **0** (dibuktikan test) |
| Perubahan kebijakan limit | Ubah kode + rilis ulang | **Config / panel**, tanpa deploy |
| Jejak audit approval | Sebagian peristiwa | **Semua** peristiwa (diajukan/disetujui/ditolak/gagal_diputus/dibatalkan) |
| Regresi pengujian | 1135 passed | **1156 passed (5274 assertions)**, 0 failure |

---

## 2. Matriks Kepatuhan: Kriteria → Implementasi → Bukti

### 2.1 Hierarki peran & permission set yang berbeda

| Kriteria | Implementasi | Bukti |
|---|---|---|
| Terdapat daftar peran tunggal dengan tingkat kewenangan eksplisit | `role_hierarki.peran` — `dps`(60) > `pengurus`(50) > `manajer`(40) > `ao`(30) > `teller`(20) > `admin`(10) > `anggota`(0) | `config/role_hierarki.php:45–95` |
| Kategori peran dibedakan (pengawas/pengurus/pengelola/sistem/anggota) | Atribut `kategori` tiap peran; `operasional` menandai peran yang terlibat transaksi harian | `config/role_hierarki.php:45–95` |
| Hanya peran tertentu boleh menjadi pemutus (checker) | Atribut `penyetuju`; DPS & Admin **tidak** berada di rantai operasional | `config/role_hierarki.php:50,85` |
| Urutan kewenangan tidak bergantung urutan penulisan file | `HierarkiRole::peran()` mengurutkan menurut `level` dan mengembalikan `list<string>` | `app/Support/HierarkiRole.php:44–53` |
| Pertanyaan "apakah A lebih tinggi dari B" terpusat | `level()`, `lebihTinggi()`, `atasanDari()`, `rantai()` | `app/Support/HierarkiRole.php` |
| Seluruh aplikasi memakai pintu masuk yang sama | Tidak ada pemanggilan `config('role_hierarki…')` langsung dari controller/service | grep `role_hierarki` pada `app/Http`, `app/Services` |

### 2.2 Batas operasional spesifik per peran

| Kriteria | Implementasi | Bukti |
|---|---|---|
| **Nominal per transaksi** berbeda per peran | `batas.<peran>.<jenis>.limit_per_transaksi` — teller setor 50 jt / tarik 5 jt; ao 100 jt / 20 jt; `0` = dilarang total (mis. teller non-tunai) | `config/role_hierarki.php:151–172` |
| **Akumulasi harian** | `limit_harian`; dijumlahkan terhadap transaksi hari berjalan sebelum diputus | `TransactionLimitService::nilaiBatas()` |
| **Frekuensi aksi** (jumlah transaksi/hari) | `frekuensi.<peran>.maks_transaksi_per_hari`; dinilai **sebelum** nominal agar penyalahgunaan berulang dapat dibendung | `config/role_hierarki.php:182–187`; test `test_frekuensi_aksi_per_hari_menolak_transaksi_berikutnya` |
| **Volume akses data** (baris ekspor) | `akses_data.<peran>.maks_baris_ekspor`; `NULL` = tanpa batas (perilaku lama) | `config/role_hierarki.php:197–202` |
| Batas volume ditegakkan di **semua** jalur ekspor | `HierarkiRole::ambilDenganBatas()` dipakai di `CifController::export()` **dan** `ShuController::exportExcel()`; permintaan di atas batas **ditolak dengan pesan**, bukan dipotong diam-diam | `app/Http/Controllers/Pengurus/ShuController.php` (`exportExcel`); `app/Http/Controllers/CifController.php` (`export`) |
| Limit `0` benar-benar menolak, bukan "tanpa batas" | `batas()` memperlakukan `0` sebagai larangan; peran tanpa jalur otorisasi tidak dapat melolosinya | test `test_limit_nol_menolak_peran_tanpa_jalur_otorisasi`, `test_limit_nol_peran_lain_ditolak_oleh_penilaian_batas` |
| Kebijakan koperasi tetap menjadi batas luar untuk tunai | `KebijakanKoperasi::limit_penarikan_teller` / `limit_setoran_teller` dibaca di jalur tunai | test setUp `RbacHierarkiRoleTest.php:53–56` |

### 2.3 Approval berjenjang (eskalasi otomatis)

| Kriteria | Implementasi | Bukti |
|---|---|---|
| Rantai eskalasi terdefinisi | `rantai`: teller→ao, ao→manajer, manajer→pengurus, pengurus→null (puncak) | `config/role_hierarki.php:107–112` |
| Aksi di atas limit butuh otorisasi peran atasan | `butuh_approval` + `approval_role` per jenis transaksi | `config/role_hierarki.php:151–172` |
| Eskalasi berulang bila nominal juga melebihi limit atasan | `penyetujuUntuk()` menaikkan jenjang sampai ditemukan peran berwenang | `app/Support/HierarkiRole.php`; test `test_transaksi_melebihi_limit_teller_naik_ke_ao` |
| Status approval per jenjang nyata & diturunkan otomatis | `status_menunggu` = `PENDING_<PERAN>`; `statusMenunggu()`, `peranUntukStatus()`, `semuaStatusMenunggu()` | `config/role_hierarki.php:123–127` |
| AML high-value menaikkan jenjang ke Pengurus | Transaksi ≥ `Setting::amlThreshold()` memaksa `approvalRole = 'pengurus'` | `app/Services/TransactionLimitService.php` (cabang AML) |
| Pemutusan hanya sah oleh peran pada tahap itu | `approveTransaction()` / `rejectTransaction()` memverifikasi `peranUntukStatus()` sebelum memproses | test `test_ao_berwenang_memutus_pengajuan_tingkat_ao`, `test_pengurus_berwenang_memutus_tahap_pengurus` |
| Pemutusan tetap memakai transaksi DB terkunci | `DB::transaction` + `lockForUpdate()` saldo; jurnal wajib berhasil atau rollback | `TransactionLimitService::approveTransaction()` |

### 2.4 Pencegahan konflik permission lintas-peran

| Kriteria | Implementasi | Bukti |
|---|---|---|
| Peran tak berwenang **gagal** memutus & terekam | Percobaan pemutusan di luar tahap ditolak dan dicatat sebagai `gagal_diputus` sekaligus `role_berwenang` | test `test_peran_tak_berwenang_gagal_memutus_dan_terekam` |
| Maker tidak boleh menjadi Checker (self-approval) | `PemisahanTugas::dilanggar()` + `PemisahanTugas::PESAN` dipanggil sebelum memutus | test `test_self_approval_ditolak_dan_terekam` |
| Peran non-penyetuju tidak dapat menyetujui apa pun | `penyetuju = false` pada DPS/Admin/Teller/Anggota; divalidasi saat pemutusan | test `test_pemisahan_peran_penyetuju_vs_non_penyetuju_dijaga` |
| Override DB menang atas default config (tanpa mengubah kode) | `HierarkiRole::override()` membaca `limit_transaksi_role`, cache 60 menit, `flush()` pada simpan/hapus | test `test_override_db_menang_atas_default_config` |
| Jenis transaksi tak dikenal tidak membuka celah | Diklasifikasikan sebagai `non_tunai` (paling ketat) | test `test_jenis_transaksi_tak_dikenal_dinilai_sebagai_non_tunai` |

### 2.5 Jejak audit untuk semua peristiwa approval

| Kriteria | Implementasi | Bukti |
|---|---|---|
| Setiap peristiwa approval dicatat | `ApprovalTransaksiLog::catatPeristiwa()` dengan konstanta `PERISTIWA_DIAJUKAN / DISETUJUI / DITOLAK / GAGAL_DIPUTUS / DIBATALKAN` | `app/Models/ApprovalTransaksiLog.php:36–58` |
| Jejak tidak dapat disunting/dihapus | Guard `booted()` menolak `updating`/`deleting`; `UPDATED_AT = null` (append-only) | `app/Models/ApprovalTransaksiLog.php:33–34` |
| Jejak disegel rantai hash | `hash = SHA256(isi_baris + hash_sebelumnya)`; `verifyChain()` mendeteksi pemutusan rantai | `app/Models/ApprovalTransaksiLog.php`; test `test_jejak_audit_approval_utuh_dan_tak_dapat_disunting` |
| Pengajuan merekam tahap berwenang sejak awal | Peristiwa `diajukan` menyertakan `role_berwenang` hasil `peranUntukStatus()` | test `test_jejak_merekam_peristiwa_diajukan_dengan_tahap_berwenang` |
| Penolakan terekam lengkap (alasan + aktor) | Peristiwa `ditolak` menyimpan `catatan` dan `user_id`/`role` | test `test_penolakan_terekam_pada_jejak_audit` |
| Konteks teknis tersimpan | `ip_address`, `user_agent`, `nominal`, `kode_approval`, `status_sebelum/sesudah` | `app/Models/ApprovalTransaksiLog.php:60–77` |

### 2.6 Kepatuhan regulasi & standar

| Rujukan | Ketentuan | Implementasi |
|---|---|---|
| **UU No. 25/1992 Pasal 30** | Kewenangan Pengurus atas pengelolaan koperasi | `pengurus` = puncak rantai approval (`level` 50, `penyetuju = true`) |
| **PP No. 7/2021** | Tata kelola & pengawasan koperasi | Kategori peran `pengawas` (DPS) dipisah dari `pengelola`; DPS di luar rantai operasional |
| **Permen KUKM No. 09/2018** | Pengelolaan koperasi & pembatasan kewenangan petugas | Batas nominal/harian/frekuensi per peran; maker ≠ checker |
| **POJK No. 76/2016** | Penerapan manajemen risiko & pengendalian internal (4-eyes principle) | `PemisahanTugas::dilanggar()` menolak self-approval |
| **UU PDP No. 27/2022** | Minimalisasi data — akses seperlunya | `akses_data.maks_baris_ekspor` membatasi volume ekspor per peran |
| **PSAK 101–111 / 71** | Pengakuan & pengukuran; jejak audit yang dapat diverifikasi | Jurnal wajib posting sebelum approval final; rantai hash pada log approval |

---

## 3. Skalabilitas: Menambah Peran / Batas Tanpa Refactoring

Sistem dirancang agar **perubahan kebijakan tidak menyentuh kode program**.

### 3.1 Menambah peran baru (mis. `supervisor`)

Cukup menambah entri pada **satu file** `config/role_hierarki.php`:

```php
'peran' => [
    'supervisor' => [
        'label'       => 'Supervisor',
        'level'       => 35,          // di antara AO (30) dan Manajer (40)
        'kategori'    => 'pengelola',
        'penyetuju'   => true,
        'operasional' => true,
    ],
    // …peran lain tetap…
],

'rantai' => [
    'ao'         => 'supervisor',     // AO kini naik ke Supervisor, bukan Manajer
    'supervisor' => 'manajer',        // Supervisor naik ke Manajer
    // …sisanya tetap…
],
```

**Yang otomatis mengikuti tanpa perubahan kode:**

| Turunan | Mekanisme |
|---|---|
| Urutan kewenangan | `peran()` mengurutkan menurut `level` |
| Status approval | `statusMenunggu('supervisor')` → `PENDING_SUPERVISOR` (pola `PENDING_<PERAN>`) |
| Label UI | `label()` / `labelJenis()` |
| Penyetuju | `penyetujuUntuk()`, `peranUntukStatus()` |
| Validasi pemutusan | `approveTransaction()` memakai `peranUntukStatus()` yang membaca `status_menunggu` |

Penambahan `enum` kolom `users.role` diperlukan **hanya bila** peran baru harus disimpan pada
tabel pengguna (migrasi aditif, bukan refactoring).

### 3.2 Mengubah batas (nominal / harian / frekuensi / volume)

Dua lapis, **tanpa deploy**:

| Lapis | Cara | Kapan dipakai |
|---|---|---|
| **Config** | Sunting `config/role_hierarki.php` (`batas`, `frekuensi`, `akses_data`) | Perubahan default sistem |
| **DB override (UI)** | Baris tabel `limit_transaksi_role` (`role`, `jenis_transaksi`, `limit_per_transaksi`, `limit_harian`, `butuh_approval`, `approval_role`) | Perubahan operasional oleh Pengurus; **menang** atas config |

Mekanisme override: `HierarkiRole::override()` membaca tabel dengan cache 60 menit
(`CACHE_KEY = role_hierarki.override`); model `LimitTransaksiRole::booted()` memanggil
`HierarkiRole::flush()` saat baris disimpan/dihapus sehingga perubahan langsung berlaku.

### 3.3 Menambah batas baru

Tambahkan kunci pada bagian yang sesuai (`batas` untuk nominal, `frekuensi` untuk aksi,
`akses_data` untuk volume) dan baca lewat API `HierarkiRole` yang relevan. Tidak ada
percabangan aturan di controller/service.

---

## 4. Hasil Pengujian

**Berkas:** `tests/Feature/RbacHierarkiRoleTest.php` — **21 test, 98 assertions, HIJAU**.

Cakupan:

| Kelompok | Test |
|---|---|
| Sumber kebenaran hierarki | urutan kewenangan; rantai berjenjang; pemisahan penyetuju vs non-penyetuju; jenis transaksi tak dikenal |
| Penegakan batas | di bawah limit tanpa approval; melebihi limit teller → AO; limit `0` menolak; limit `0` ditolak penilaian batas; akumulasi harian; frekuensi per hari; override DB menang atas config |
| Rantai approval | AO memutus tahap AO; peran tak berwenang gagal & terekam; Pengurus memutus tahap Pengurus; self-approval ditolak & terekam |
| Jejak audit | penolakan terekam; peristiwa `diajukan` menyertakan tahap berwenang; rantai hash utuh & tak dapat disunting |
| Volume akses data | tanpa batas = seluruh baris; terbatas menandai permintaan melampaui; tepat pada batas tidak dianggap melampaui |

**Regresi penuh** (`scripts\run-tests.ps1`):

| | Nilai |
|---|---|
| Perintah | `powershell -File scripts\run-tests.ps1` |
| Hasil | **1156 passed (5274 assertions)** |
| Kegagalan | **0** |
| Durasi | 00:02:53 |
| Exit code | 0 |
| Baseline sebelum Tahap 13 | 1135 passed |

---

## 5. Celah yang Ditutup

| # | Celah | Penanganan |
|---|---|---|
| 1 | Sumber aturan RBAC tersebar & saling bertentangan | Disatukan ke `config/role_hierarki.php` + `App\Support\HierarkiRole` |
| 2 | Batas harian tidak ditegakkan konsisten | `limit_harian` via `nilaiBatas()` (frekuensi → harian → per transaksi) |
| 3 | Frekuensi aksi per hari tidak dibatasi | `frekuensi.maks_transaksi_per_hari` ditegakkan di titik masuk |
| 4 | Kolom `butuh_approval` tidak dihormati sebagian jalur | Penegakan di `TransactionLimitService` untuk seluruh jenis |
| 5 | `PENDING_AO` tidak pernah terbentuk | `status_menunggu` + `peranUntukStatus()`; rangkaian jenjang Teller→AO→Manajer→Pengurus |
| 6 | Status rantai RBAC masih berupa literal `PENDING_MANAJER` di controller/view | Diturunkan dari `HierarkiRole::statusMenunggu('manajer')` (`ManajerController`, `TellerController`, `pengelola/approval/show.blade.php`) |
| 7 | Batas volume ekspor tidak ditegakkan | `HierarkiRole::ambilDenganBatas()` di `CifController::export()` **dan** `ShuController::exportExcel()` |

---

## 6. Berkas Dibuat / Diubah

| Berkas | Peran | Keterangan | Status |
|---|---|---|---|
| `config/role_hierarki.php` | rbac-1 | Sumber kebenaran: peran, rantai, status_menunggu, batas, frekuensi, akses_data | ✅ |
| `app/Support/HierarkiRole.php` | rbac-1 | Pintu masuk tunggal: struktur + batas + `ambilDenganBatas()` | ✅ |
| `app/Models/LimitTransaksiRole.php` | rbac-1 | Override DB; `booted()` → `HierarkiRole::flush()` | ✅ |
| `app/Services/TransactionLimitService.php` | rbac-2/3 | Penegakan limit, eskalasi, pemutusan berwenang, AML high-value | ✅ |
| `app/Support/PemisahanTugas.php` | rbac-4 | Maker ≠ Checker (`dilanggar()`, `PESAN`) | ✅ |
| `app/Models/ApprovalTransaksiLog.php` | rbac-4 | Jejak audit append-only + rantai hash + 5 peristiwa | ✅ |
| `app/Http/Controllers/CifController.php` | rbac-5b | Batas volume pada `export()` | ✅ |
| `app/Http/Controllers/Pengurus/ShuController.php` | rbac-5b | Batas volume pada `exportExcel()` (tolak + pesan) | ✅ |
| `app/Http/Controllers/Dashboard/ManajerController.php` | rbac-5c | Status menunggu dinamis | ✅ |
| `app/Http/Controllers/TellerController.php` | rbac-5c | Status void dinamis dari `HierarkiRole` | ✅ |
| `resources/views/pengelola/approval/show.blade.php` | rbac-5c | Label status via `peranUntukStatus()` | ✅ |
| `tests/Feature/RbacHierarkiRoleTest.php` | rbac-5 | 21 test penegakan limit, rantai approval, konflik lintas-peran | ✅ |

**Catatan:** status domain yang punya siklus bisnis sendiri (`Pembiayaan::STATUS_PENDING_*`,
`TazirReliefRequest`, `DepositoPenaltyRelief`, `RestrukturisasiRequest`, `PengajuanPembiayaan`,
`AmlComplianceController`) **sengaja dibiarkan** — masing-masing memakai konstanta domain dan
alur terpisah dari rantai `approval_transaksi`, bukan bagian RBAC umum.

---

## 7. Risiko Sisa

| Risiko | Tingkat | Mitigasi |
|---|---|---|
| Override DB di-cache 60 menit → perubahan tidak langsung terlihat pada proses lain | Rendah | `LimitTransaksiRole::booted()` memanggil `flush()` pada simpan/hapus |
| Batas `NULL` = tanpa batas tetap ada (default aman) | Sedang | Pengurus menetapkan angka eksplisit via config/UI saat kebijakan siap |
| Peran baru perlu migrasi `enum users.role` | Rendah | Migrasi aditif; tidak mengubah alur yang ada |
| Uji skala besar (1.000 anggota × 12 periode) belum dijalankan untuk jalur ekspor berbatas | Rendah | Terpisah dari lingkup RBAC; jalur ekspor hanya menambah `limit`/`take` |

---

## 8. Pernyataan Penyelesaian

Seluruh kriteria permintaan **terpenuhi dan terbukti**:

1. ✅ **Hierarki peran & permission set berbeda** — `role_hierarki.peran` + `HierarkiRole`.
2. ✅ **Batas operasional per peran** — nominal per transaksi, akumulasi harian, frekuensi aksi, volume akses data.
3. ✅ **Approval berjenjang** — rantai Teller→AO→Manajer→Pengurus, eskalasi otomatis, **jejak audit semua peristiwa** dengan rantai hash append-only.
4. ✅ **Pengujian komprehensif** — 21 test khusus + regresi **1156 passed / 0 failure**; batas ditegakkan, rantai berfungsi, konflik lintas-peran (termasuk self-approval) dicegah.
5. ✅ **Skalabilitas** — peran/batas baru via config `role_hierarki.php` + override DB, tanpa refactoring kode.
