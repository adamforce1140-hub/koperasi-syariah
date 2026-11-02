# TAHAP 14 — Penguncian Simpanan Pokok (3-1001) & Simpanan Wajib (3-1002) sebagai Ekuitas Koperasi

**Status:** ✅ SELESAI
**Tanggal:** 2026-10-01
**Tahap:** 14 (Ekuitas Koperasi Syariah & Exit Keanggotaan)
**Dokumen pendamping:** `docs/PSAK108-PEMISAHAN-MARGIN-DANA-KEBAJIKAN.md`,
`docs/TAHAP12-R3-SIKLUS-PENARIKAN.md`, `docs/TAHAP13-RBAC-HIERARKI-ROLE.md`,
`docs/TAHAP4-GCG-OJK.md`

**Khalayak:** Pengurus, Dewan Pengawas Syariah (DPS), auditor internal & eksternal, Tim TI.

---

## 1. Ringkasan Eksekutif

Simpanan Pokok (`3-1001`) dan Simpanan Wajib (`3-1002`) menurut **PSAK 101 ¶ 31**
adalah **MODAL/EKUITAS anggota koperasi syariah** — bukan kewajiban jangka pendek yang dapat
ditarik kapan saja. Sebelum Tahap 14, kedua akun tersebut masih dapat ditarik melalui alur
penarikan rutin (teller, mobile, simpanan wajib) dan tidak ada satu pun pintu pengembalian dana
yang mengikatkan diri pada prinsip ekuitas.

Tahap 14 menutup celah tersebut secara **end-to-end** dengan empat pengunci:

| Pengunci | Penjabaran |
|---|---|
| **Guard tunggal** | `App\Support\GuardSimpananModal` — satu-satunya titik penegakan larangan penarikan; seluruh endpoint WAJIB lewat sini |
| **Pintu refund tunggal** | Pengembalian dana HANYA melalui modul `member_terminations` (`ExitKeanggotaanService`) |
| **Auto-journal PSAK 108** | Jurnal DEBET `3-1001` + DEBET `3-1002` → KREDIT `1-1001`/`1-1002`, sekali-post & append-only |
| **Status otomatis** | `members.status` → `TERMINATED` **hanya setelah** refund & jurnal selesai |

**Hasil terukur:**

| Indikator | Sebelum Tahap 14 | Sesudah Tahap 14 |
|---|---|---|
| Jalur penarikan rutin 3-1001/3-1002 | Terbuka (teller/mobile/wajib) | **0** — diblokir di guard tunggal |
| Pesan penolakan | Tidak seragam | Satu pesan standar (lihat §2.4) |
| Jejak audit penarikan diblokir | Tidak ada | `PENARIKAN_MODAL_DIBLOKIR` (log + `audit_logs`) |
| Pintu pengembalian dana | Tidak terkontrol | **Eksklusif** `member_terminations` |
| Syarat pengembalian | Tidak ada | Pembiayaan `PAID_OFF`/NPF=0 + seluruh kewajiban bersih |
| Jurnal pengembalian | Tidak ada | Auto-journal PSAK 108, sekali-post, append-only |
| Status anggota pasca-exit | `INACTIVE` (kurang spesifik) | **`TERMINATED`** (+ alias `Keluar`) |
| Jejak audit exit | Tidak ada | `EXIT_KEANGGOTAAN` + kolom audit pada `anggota` |
| Regresi pengujian | 1164 passed | **1164 passed (5326 assertions)**, 0 failure |

---

## 2. Bagian 1 — Aturan Penjaringan & Penguncian Penarikan (Restriction Rules)

### 2.1 Titik penegakan tunggal

| Kriteria | Implementasi | Bukti |
|---|---|---|
| Larangan ditegakkan di satu kelas | `App\Support\GuardSimpananModal` | `app/Support/GuardSimpananModal.php` |
| Daftar akun modal terkunci | `KODE_AKUN_MODAL = [Coa::SIMPANAN_POKOK (3-1001), Coa::SIMPANAN_WAJIB_MODAL (3-1002)]` | `GuardSimpananModal.php:38–41` |
| Pencocokan jenis rekening legacy | `JENIS_REKENING_MODAL = ['Simpanan Pokok','Simpanan Wajib']`, case-insensitive | `GuardSimpananModal.php:44–47`, `cocok()` |
| Pesan standar | `PESAN` — lihat §2.4 | `GuardSimpananModal.php:35` |

Metode penegakan yang tersedia:

- `pastikanTidakDitarikRutin(...)` — dipakai alur penarikan berbasis akun CoA.
- `pastikanRekeningDapatDitarik(...)` — dipakai alur penarikan berbasis objek/ID rekening.
- `pastikanAnggotaTanpaRekeningModal(...)` — mencegah pembukaan rekening modal baru yang dapat ditarik.

### 2.2 Matriks endpoint yang dikunci

| Modul | Endpoint / Metode | Titik guard | Bukti |
|---|---|---|---|
| Teller (tunai) | `GET teller.tarik` / `POST teller.tarik.store` | `pastikanRekeningDapatDitarik` | `TellerController.php:177–222`; `routes/web.php:440–443` |
| Teller (Simpanan Wajib) | `POST teller.simpanan-wajib.tarik` | `pastikanTidakDitarikRutin` | `Teller/SimpananWajibController.php:171`; `routes/web.php:465–466` |
| Mobile / API | `POST api.transaksi.tarik` | `pastikanRekeningDapatDitarik` | `TransaksiMobileController.php:55`; `routes/api.php:170` |
| Daftar rekening teller | `getRekeningByAnggota` / `tarikForm` | Rekening modal **dikecualikan** dari daftar | `TellerController.php:177–222` |

### 2.3 Penguncian antarmuka (UI)

Seluruh kendali penarikan rutin untuk Simpanan Pokok & Simpanan Wajib **dihapus/dinonaktifkan**
dan digantikan **panel terkunci** dengan penjelasan PSAK 101 ¶ 31:

| Berkas | Perubahan |
|---|---|
| `resources/views/teller/tarik.blade.php:150` | Opsi 3-1001/3-1002 tidak dapat dipilih untuk penarikan |
| `resources/views/teller/simpanan-wajib.blade.php:122–129` | Panel **TERKUNCI** — "tidak dapat ditarik selama anggota berstatus aktif" |
| `resources/views/teller/riwayat.blade.php:214–215` | Riwayat tidak menyediakan aksi penarikan modal |
| `resources/views/member-terminations/*` | Satu-satunya pintu pengembalian dana (lihat §3) |

### 2.4 Pesan penolakan standar (verbatim)

> **Simpanan Pokok dan Wajib adalah Modal/Ekuitas Koperasi dan tidak dapat ditarik selama menjadi anggota aktif.**

Pesan ini didefinisikan sekali di `GuardSimpananModal::PESAN` dan dilempar sebagai
`RuntimeException` pada **setiap** upaya penarikan yang terdeteksi menyasar akun `3-1001`/`3-1002`.

### 2.5 Logging terpusat untuk audit keamanan

Setiap upaya penarikan yang diblokir dicatat **terpusat** pada `GuardSimpananModal::blokir()`:

| Sasaran | Isi |
|---|---|
| Laravel log | `Log::warning('PENARIKAN_MODAL_DIBLOKIR', [...])` — konteks, rekening, anggota |
| Tabel `audit_logs` | `AuditLog::record('PENARIKAN_MODAL_DIBLOKIR', 0, no_rekening, keterangan)` |

Penulisan audit dibungkus `try/catch` agar **kegagalan audit tidak menggagalkan penolakan**
itu sendiri (`GuardSimpananModal.php:196–201`) — prinsip *fail-closed* penarikan tetap terjaga.

---

## 3. Bagian 2 — Penguatan Mekanisme Pengembalian Dana

### 3.1 Eksklusivitas jalur refund

| Kriteria | Implementasi | Bukti |
|---|---|---|
| Pengembalian dana hanya via modul exit | `App\Services\ExitKeanggotaanService::eksekusi()` | `app/Services/ExitKeanggotaanService.php` |
| Tidak ada modul lain yang dapat mengembalikan dana modal | Guard `pastikanTidakDitarikRutin` menutup seluruh alur penarikan rutin (§2) | `GuardSimpananModal` pada semua endpoint |
| Dokumen exit bersifat append-only | Guard `booted()` pada model | `app/Models/MemberTermination.php:112–127` |

### 3.2 Syarat kelayakan sebelum exit (gate)

`ExitKeanggotaanService::pastikanLayakExit()` memanggil `kendalaExit()` yang menggabungkan
**empat** pemeriksaan independen. Bila ada satu saja kendala, seluruh proses exit **dibatalkan
otomatis**, diblokir tercatat, dan `RuntimeException` dilempar dengan daftar kendala.

| # | Pemeriksaan | Aturan | Implementasi |
|---|---|---|---|
| a | **Pembiayaan** | Semua kontrak wajib `PAID_OFF`/LUNAS; tidak ada pembiayaan aktif; NPF = 0 | `kendalaPembiayaan()` — mengabaikan `STATUS_LUNAS`/`DITOLAK`/`DIBATALKAN`; pesan memuat "harus LUNAS (PAID_OFF) … (NPF = 0)" |
| a | **Angsuran** | Tidak ada angsuran tertunggak | `kendalaAngsuran()` — "Terdapat %d angsuran yang belum dibayar (termasuk tunggakan)…" |
| b | **Ta'zir/denda** | Tidak ada denda/tindakan disiplin yang belum diselesaikan | `kendalaTazirDenda()` |
| b | **Tagihan Simpanan Wajib** | Tidak ada tagihan bulanan belum lunas | `kendalaTagihanSimpananWajib()` |

Pesan akhir penolakan menyertakan rujukan eksplisit ke **PSAK 101 ¶ 31** agar alasan hukum
transparan bagi anggota.

### 3.3 Pencatatan blokir exit

Setiap exit yang gagal dicatat melalui `catatBlokirExit()`:

- `Log::warning('EXIT_KEANGGOTAAN_DIBLOKIR', [...])` — anggota, no_anggota, user, daftar kendala.
- `AuditLog::record('EXIT_KEANGGOTAAN_DIBLOKIR', 0, rekening, '… | …')`.

---

## 4. Bagian 3 — Logika Eksekusi Pengembalian Dana & Auto-Journal (PSAK 108)

### 4.1 Metode pengembalian yang terstandarisasi

| Metode | Konstanta | Ledger | Sumber transaksi | Akun kredit |
|---|---|---|---|---|
| Tunai via Teller | `MemberTermination::METODE_CASH` = `'CASH'` | tabel `transaksi`, jenis `CASH_OUT` | `Teller` | `1-1001` Kas Teller |
| Transfer Bank Koperasi | `MemberTermination::METODE_TRANSFER_BANK` = `'TRANSFER_BANK'` | tabel `transaksi`, jenis `BANK_OUT` | `Transfer` | `1-1002` Kas di Bank |

Metode di luar daftar `METODE_LIST` ditolak (`/tidak dikenal/`). `TRANSFER_BANK` **mewajibkan**
`nomor_rekening_tujuan` terisi (pesan "mengharuskan nomor rekening tujuan diisi").

### 4.2 Auto-journal PSAK 108

Diposting otomatis oleh `AutoJournalingService::jurnalPengembalianDanaExit()` setelah persetujuan.
Baris **DEBET** dihasilkan hanya bila nominal komponen > 0:

**a. Metode `CASH` (Tunai via Teller)**

| Posisi | Akun | Nilai |
|---|---|---|
| **DEBET** | `3-1001` Simpanan Pokok | Nominal Simpanan Pokok tercatat |
| **DEBET** | `3-1002` Simpanan Wajib | Total akumulasi Simpanan Wajib terkumpul |
| **KREDIT** | `1-1001` Kas Teller | **Total dana yang diterima anggota** |

**b. Metode `TRANSFER_BANK` (Transfer via Rekening Bank Koperasi)**

| Posisi | Akun | Nilai |
|---|---|---|
| **DEBET** | `3-1001` Simpanan Pokok | Nominal Simpanan Pokok tercatat |
| **DEBET** | `3-1002` Simpanan Wajib | Total akumulasi Simpanan Wajib terkumpul |
| **KREDIT** | `1-1002` Kas di Bank | **Total dana yang diterima anggota** |

### 4.3 Integritas jurnal (sekali-post & tidak dapat diubah)

| Kriteria | Implementasi | Bukti |
|---|---|---|
| Jurnal hanya diposting **satu kali** | Idempotensi `sudahDiposting()`; eksekusi kedua mengembalikan dokumen tanpa efek samping | `ExitKeanggotaanService::eksekusi()` |
| Jurnal tidak dapat diubah setelah tercatat | Guard append-only model `MemberTermination` (throw pesan memuat **"permanen"** bila update/delete setelah `jurnal_id` terisi) | `MemberTermination.php:112–127` |
| Validasi akun CoA siap | `pastikanAkunEkuitasSiap()` sebelum posting | `ExitKeanggotaanService` |
| Atomisitas | Seluruh langkah dibungkus `DB::transaction` | `ExitKeanggotaanService::eksekusi()` |
| Sumber jurnal | `AutoJournalingService::SUMBER_PENGEMBALIAN_EXIT` = `'Pengembalian Dana Exit Keanggotaan'` | `AutoJournalingService` |

### 4.4 Jejak transaksi per komponen

Satu baris `Transaksi` dibuat **per komponen** (Pokok / Wajib) yang bernilai > 0 dengan
`posisi = 'Kredit'`, `referensi = no_terminasi`, `status_transaksi = 'Sukses'`, dan `catatan`
memuat label akun serta keterangan rekening tujuan (nomor rekening untuk `TRANSFER_BANK`).

---

## 5. Bagian 4 — Perubahan Status Keanggotaan Otomatis

### 5.1 Trigger status → `TERMINATED`

Perubahan status **hanya** dijalankan di dalam transaksi, **setelah** refund tercatat dan jurnal
berhasil ter-posted. Bila auto-journal gagal (`null`), transaksi di-rollback dan status anggota
**tidak** berubah.

| Kriteria | Implementasi | Bukti |
|---|---|---|
| Status baru lebih spesifik dari `INACTIVE` | `Anggota::STATUS_TERMINATED` = `'TERMINATED'` (alias `Keluar`) | `App\Models\Anggota` |
| Trigger hanya setelah refund + jurnal selesai | `forceFill([...])->save()` berada **setelah** posting jurnal dalam `DB::transaction` | `ExitKeanggotaanService.php:446–469` |
| Kolom status yang benar | `status_anggota` (bukan `status`) | `ExitKeanggotaanService.php:449` |
| Helper status | `isTerminated()`, `isActive()`, `normalizeStatus()` | `App\Models\Anggota` |

### 5.2 Catatan audit pada entri anggota

| Kolom `anggota` | Isi |
|---|---|
| `tanggal_keluar` | Tanggal pengunduran diri anggota |
| `tanggal_terminasi` | Tanggal eksekusi terminasi |
| `total_dana_dikembalikan` | Total dana yang dikembalikan (cast `decimal:2`) |
| `terminasi_diproses_oleh` | ID user yang memproses exit |

Ditambah jejak pada tabel `audit_logs`:

`AuditLog::record('EXIT_KEANGGOTAAN', $total, $rekening, 'Pengembalian dana Simpanan Pokok + Wajib anggota %s via %s. Tanggal pengunduran diri %s, diproses user #%s.')`

---

## 6. Bagian 5 — Validasi & Pengujian

### 6.1 Berkas pengujian

| Berkas | Cakupan | Hasil |
|---|---|---|
| `tests/Feature/SimpananWajibPenarikanTest.php` | Larangan penarikan rutin 3-1001/3-1002 + logging audit | ✅ Lulus |
| `tests/Feature/ExitKeanggotaanTest.php` | Seluruh alur exit keanggotaan (11 test) | ✅ **11 passed (62 assertions)** |
| Regresi penuh (`php artisan test`) | Seluruh suite | ✅ **1164 passed (5326 assertions)**, 0 failure |

### 6.2 Matriks skenario `ExitKeanggotaanTest`

| # | Skenario | Ekspektasi | Status |
|---|---|---|---|
| 1 | Masih ada tagihan Simpanan Wajib belum lunas | Exit dibatalkan; pesan memuat "kewajiban"; status tetap `ACTIVE`; `jurnal_id` null; dokumen tetap `DIAJUKAN`; audit `EXIT_KEANGGOTAAN_DIBLOKIR` bertambah | ✅ |
| 2 | Masih ada pembiayaan berjalan (aktif, sisa > 0) | `RuntimeException` — exit dibatalkan | ✅ |
| 3 | Tidak ada saldo ekuitas | `RuntimeException` `/Tidak ada dana/` | ✅ |
| 4 | Exit sukses metode `CASH` | Dokumen `DISETUJUI`; jurnal Posted; DEBET `3-1001`=100000, DEBET `3-1002`=50000, KREDIT `1-1001`=150000; 2 baris `CASH_OUT` sum 150000; anggota `TERMINATED`; kolom audit terisi; jejak `EXIT_KEANGGOTAAN` | ✅ |
| 5 | Exit sukses metode `TRANSFER_BANK` | DEBET `3-1001`=200000, DEBET `3-1002`=60000, KREDIT `1-1002`=260000; 2 baris `BANK_OUT`; catatan memuat nomor rekening tujuan; anggota `TERMINATED` | ✅ |
| 6 | `TRANSFER_BANK` tanpa nomor rekening tujuan | `RuntimeException` `/rekening tujuan/` | ✅ |
| 7 | Metode tidak dikenal (`GOPAY`) | `RuntimeException` `/tidak dikenal/` | ✅ |
| 8 | Eksekusi idempoten (dijalankan 2×) | `jurnal_id` sama; jumlah jurnal & transaksi tidak bertambah | ✅ |
| 9 | Dokumen terminasi append-only setelah jurnal diposting | Update & delete → `RuntimeException` memuat "permanen"; data tetap `DISETUJUI` | ✅ |
| 10 | Pengajuan ganda untuk anggota yang sama | `RuntimeException` `/masih menunggu/` | ✅ |
| 11 | Anggota yang sudah `TERMINATED` mengajukan exit lagi | `RuntimeException` `/TERMINATED/` | ✅ |

### 6.3 Verifikasi tidak ada celah penarikan ilegal

| Pertanyaan audit | Jawaban | Bukti |
|---|---|---|
| Bisakah anggota aktif menarik 3-1001/3-1002 via teller? | **Tidak** | Guard + test `SimpananWajibPenarikanTest` |
| Via mobile/API? | **Tidak** | `TransaksiMobileController.php:55` |
| Via modul Simpanan Wajib? | **Tidak** | `Teller/SimpananWajibController.php:171` |
| Apakah jurnal exit sesuai PSAK 108? | **Ya** | Test #4 & #5 memverifikasi pasangan DEBET/KREDIT |
| Apakah jurnal dapat diposting dua kali / diubah? | **Tidak** | Test #8 (idempotensi) & #9 (append-only) |
| Apakah status berubah tanpa refund? | **Tidak** | Test #1–#3 memastikan status tetap `ACTIVE` saat exit gagal |

### 6.4 Cara menjalankan pengujian

```powershell
# Wajib: bersihkan cache konfigurasi SEBELUM pengujian
php artisan optimize:clear

# Uji fokus modul exit keanggotaan
powershell -ExecutionPolicy Bypass -File scripts\run-tests.ps1 -Tests tests/Feature/ExitKeanggotaanTest.php

# Regresi penuh
powershell -ExecutionPolicy Bypass -File scripts\run-tests.ps1
```

---

## 7. Rujukan Standar

| Standar | Kaitan |
|---|---|
| **PSAK 101 ¶ 31** | Simpanan Pokok & Simpanan Wajib = Modal/Ekuitas anggota koperasi |
| **PSAK 108** | Dasar penyusunan jurnal pengembalian dana exit keanggotaan |
| **UU 25/1992 Pasal 30** | Kewajiban & hak anggota, syarat berhentinya keanggotaan |
| **PP 7/2021** | Kemudahan, pelindungan, dan pemberdayaan koperasi |
| **POJK 76/2016** | Pengendalian internal & tata kelola koperasi |
| **UU PDP 27/2022** | Pelindungan data pribadi pada jejak audit anggota |
