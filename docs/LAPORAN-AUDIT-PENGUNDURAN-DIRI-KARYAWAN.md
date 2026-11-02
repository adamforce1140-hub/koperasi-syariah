# Laporan Audit Menyeluruh — Manajemen Pengunduran Diri Karyawan (Exit Keanggotaan)

**Sistem:** Koperasi Syariah (Laravel 12.69.1 / PHP 8.3.33 / MySQL 8.4.3)
**Tanggal audit:** 20 Oktober 2026
**Metode:** Audit statis (*read-only*) — pembacaan kode sumber, skema migrasi, konfigurasi RBAC, rute, tampilan, serta query verifikasi data pada basis data aktif melalui `php artisan tinker`. Tidak ada kode, migrasi, maupun data yang diubah selama audit.
**Ruang lingkup:** Seluruh tahap proses pengunduran diri/keluarnya anggota — pengajuan, verifikasi kelayakan, persetujuan (approval), penyelesaian administrasi (pencairan + jurnal + penutupan rekening), pencetakan dokumen bukti, hingga pemutusan akses sistem.

---

## 0. Catatan Ruang Lingkup yang Penting (Batasan Audit)

Permintaan audit menyebut **"pengunduran diri karyawan"**. Sistem `koperasi_syariah` **tidak memiliki modul kepegawaian (HRIS), payroll, absensi karyawan, maupun data karyawan** — tidak ada tabel `karyawan`, `pegawai`, `employees`, atau sejenisnya. Satu-satunya entitas yang memiliki siklus "keluar dari organisasi" adalah **anggota** (`anggota` / `member_terminations`).

Karena itu audit dipetakan sebagai berikut:

| Konsep dalam permintaan | Padanan riil di sistem | Status |
|---|---|---|
| Pengajuan pengunduran diri | `member_terminations` (status `DIAJUKAN`) | Ada |
| Verifikasi | `ExitKeanggotaanService::kendalaExit()` + `pastikanNpfNol()` | Ada |
| Penyelesaian administrasi | `eksekusi()` — pencairan dana + jurnal PSAK 108 + penutupan rekening | Ada |
| Hak akhir (uang pesangon/pengembalian hak) | Simpanan Pokok + Wajib + Sukarela + Deposito + Akrual Bagi Hasil − Zakat − Biaya Admin | Ada |
| **Pemutusan akses sistem** | **Tidak ada** (tidak ada penonaktifan `users.is_active`) | **Celah (GAP)** |
| Modul kepegawaian & offboarding karyawan | **Tidak ada sama sekali** | **Celah (GAP)** |

Kedua celah di atas dicatat sebagai temuan **AUD-01** dan **AUD-02** pada Bab 2.

---

## 1. INVENTARIS MODUL, ALUR KERJA, DAN BASIS DATA

### 1.1 Peta Modul Sistem yang Terlibat

| # | Lapisan | Berkas / Objek | Peran dalam proses exit |
|---|---|---|---|
| 1 | Rute | `routes/web.php` L636–660 (3 grup) | Registrasi endpoint pengajuan, eksekusi, approval |
| 2 | Middleware izin | `app/Http/Middleware/CekIzin.php` + `config/izin.php` L79–83 | Gerbang RBAC per aksi |
| 3 | Middleware status | `app/Http/Middleware/PastikanAnggotaAktif.php` | **Tidak terpasang** di alur exit |
| 4 | Controller | `app/Http/Controllers/MemberTerminationController.php` | 11 aksi: `index`, `show`, `create`, `store`, `cariAnggota`, `buktiPdf`, `buktiThermal`, `eksekusi`, `tolak`, `batalkan`, `setujui`, `tolakApproval` |
| 5 | Service domain | `app/Services/ExitKeanggotaanService.php` (≈1.470 baris) | Seluruh logika bisnis exit |
| 6 | Service jurnal | `app/Services/AutoJournalingService::jurnalPengembalianDanaExit()` L862–911 | Posting jurnal PSAK 108 |
| 7 | Service deposito | `app/Services/DepositoService.php` | Sumber akrual & saldo deposito |
| 8 | Service simpanan wajib | `SimpananWajibService::saldoSimpananWajib()` | Sumber saldo Wajib |
| 9 | Model | `MemberTermination`, `ExitApprovalLog`, `Anggota`, `Rekening`, `Deposito`, `Pembiayaan`, `Angsuran`, `MandatorySavingsBill`, `TazirPenangguhan`, `Transaksi`, `Jurnal`, `AuditLog` | Entitas transaksional |
| 10 | SSOT parameter | `app/Support/Setting.php` | `closure_admin_fee`, `high_value_withdrawal_limit`, `pembulatan_desimal`, `toleransi_selisih_jurnal` |
| 11 | SSOT CoA | `app/Support/Coa.php` / `CoaRegistry` | Kode akun 3-1001, 3-1002, 2-1001, 2-1003, 4-2001, 2-3002, 5-3001, 1-1001, 1-1002 |
| 12 | SSOT RBAC | `app/Support/HierarkiRole.php`, `config/role_hierarki.php`, `config/izin.php` | Wewenang per peran |
| 13 | Tampilan | `resources/views/member-terminations/{index,create,show,bukti-pdf,bukti-thermal}.blade.php` | UI 5 halaman |
| 14 | Navigasi | `config/navigasi.php` L125–126, L213, L241 | Menu Teller / Manajer / Pengurus |
| 15 | Navigasi & otentikasi | `app/Http/Controllers/WebAuthController.php` L33–51 | Gerbang login (`is_active`) |
| 16 | Portal anggota | `app/Http/Controllers/NasabahController.php` | Akses self-service anggota |
| 17 | Nomor dokumen | `app/Support/NomorSequence.php` + `MemberTermination::generateNoTerminasi()` / `generateNoReferensi()` | Penomoran dokumen |

### 1.2 Alur Kerja Administratif (As-Is)

```
[1] PENGAJUAN                    MemberTerminationController::store()
    POST /member-terminations         └─ izin: terminasi.pengajuan (teller|manajer|pengurus)
        │                             └─ $exit->ajukan()
        │                                  ├─ cek isTerminated() & pengajuan duplikat (menunggu)
        │                                  ├─ rincianSaldo()  ← DI LUAR DB::transaction (L409)
        │                                  ├─ butuhApproval(total_net) → MENUNGGU_APPROVAL | DIAJUKAN
        │                                  └─ create() + generateNoTerminasi() + generateNoReferensi()
        │
[2] VERIFIKASI (pratinjau)       GET /member-terminations/cari-anggota
        │                             └─ kendalaExit() = 5 kendala:
        │                                  kendalaPembiayaan() + kendalaAngsuran() +
        │                                  kendalaTazirDenda() + kendalaTagihanSimpananWajib() +
        │                                  kendalaPenjamin()
        │
[3] PERSETUJUAN (kondisional)    POST /{id}/setujui      izin: terminasi.approval (manajer|pengurus)
        │                        POST /{id}/tolak-approval
        │                             └─ hanya aktif bila high_value_withdrawal_limit > 0
        │
[4] PENYELESAIAN ADMINISTRASI    POST /{id}/eksekusi    izin: terminasi.eksekusi (teller|manajer|pengurus)
        │                             └─ $exit->eksekusi():
        │                                  1. pastikanLayakExit()      ← DI LUAR transaksi (L674)
        │                                  2. pastikanNpfNol()         ← DI LUAR transaksi (L679)
        │                                  3. rincianSaldo()           ← DI LUAR transaksi (L686)
        │                                  4. pastikanAkunEkuitasSiap()
        │                                  5. DB::transaction():
        │                                     a. hingga 5 baris `transaksi` (CASH_OUT / BANK_OUT)
        │                                     b. 1 jurnal via jurnalPengembalianDanaExit()
        │                                     c. tutup `rekening` (status 'Aktif' → 'Ditutup')
        │                                     d. tutup `depositos` (ACTIVE/MATURED → CLOSED)
        │                                     e. update CIF `anggota` → TERMINATED
        │                                     f. update `member_terminations` → DISETUJUI
        │                                     g. (opsional) ExitApprovalLog
        │
[5] DOKUMEN BUKTI                GET /{id}/bukti-pdf      izin: terminasi.pengajuan
        │                        GET /{id}/bukti-thermal
        │                             └─ pastikanDokumenFinal() → hanya status DISETUJUI
        │
[6] PEMUTUSAN AKSES SISTEM       ← TIDAK ADA IMPLEMENTASI (temuan AUD-01)
[7] ARSIP / HISTORI              tabel member_terminations + exit_approval_logs + audit_logs
```

### 1.3 Basis Data yang Terlibat

**Tabel utama — `member_terminations`** (dibuat `2026_09_30_006000_create_member_terminations_table.php`, diperluas `2026_10_20_000100_extend_member_terminations_for_exit_closure.php`):

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | bigint PK | |
| `anggota_id` | FK → `anggota` `restrictOnDelete` | |
| `no_terminasi` | varchar(40) UNIQUE | Format internal `TRM-YYYYMMDD-NNNN` |
| `no_referensi` | varchar(40) UNIQUE | Format resmi `EXIT/YYYYMM/NNNNNN` (race-safe) |
| `status` | varchar(20) | DIAJUKAN / MENUNGGU_APPROVAL / DISETUJUI / DITOLAK / DIBATALKAN |
| `metode` | varchar(20) | CASH / TRANSFER_BANK |
| `nomor_rekening_tujuan`, `nama_bank_tujuan` | varchar | Wajib bila TRANSFER_BANK |
| `nominal_simpanan_pokok`, `_wajib`, `nominal_sukarela`, `nominal_deposito`, `nominal_akrual_bagi_hasil`, `nominal_zakat`, `nominal_biaya_admin`, `total_net` | decimal(15,2) | Snapshot pratinjau saat pengajuan, dihitung ulang saat eksekusi |
| `total_dikembalikan` | decimal(15,2) | Nominal aktual yang dibayar |
| `butuh_approval`, `approved_at` | bool, timestamp | |
| `exit_reason`, `catatan`, `alasan_penolakan` | text | |
| `transaksi_id`, `jurnal_id`, `no_jurnal` | bigint, varchar | Tautan ke ledger |
| `diajukan_oleh`, `diproses_oleh`, `disetujui_oleh` | FK → `users` nullOnDelete | |
| `tanggal_pengunduran_diri`, `diajukan_pada`, `diproses_pada` | date, timestamp, timestamp | **Tidak ada kolom `disetujui_pada`** |

**Catatan skema:** kolom `status` bertipe `VARCHAR(20)` tanpa `CHECK`/`ENUM` — nilai status tidak ditegakkan di level basis data. Tidak ada kolom `approved_by` terpisah; `disetujui_oleh` dipakai ganda untuk "penyetuju approval" **dan** "pelaksana eksekusi" (lihat AUD-11).

**Tabel pendukung:**

| Tabel | Fungsi | Catatan audit |
|---|---|---|
| `exit_approval_logs` | Jejak keputusan approval | `aksi` varchar(20) default 'APPROVED' (APPROVED/REJECTED); **tanpa** IP/user-agent, **tanpa** constraint unik 1-approval, **tanpa** aksi CANCELLED (AUD-12) |
| `anggota` | CIF anggota | `status_anggota`, `tanggal_keluar`, `tanggal_terminasi`, `total_dana_dikembalikan`, `terminasi_diproses_oleh`, `exit_date`, `exit_reason`, `exit_approved_by`; `user_id` nullable |
| `rekening` | Rekening simpanan | Ditutup pada eksekusi (hanya yang `status_rekening='Aktif'`) |
| `depositos` | Deposito berjangka | Ditutup (ACTIVE + MATURED) pada eksekusi |
| `transaksi` | Ledger kas | Hingga 5 baris per exit (satu per komponen yang bernilai > 0) |
| `jurnal` + `jurnal_detail` | Jurnal umum PSAK 108 | 1 jurnal per exit |
| `audit_logs` | Kanal audit tunggal | `EXIT_KEANGGOTAAN`, `EXIT_APPROVAL_*`, `EXIT_KEANGGOTAAN_DIBLOKIR/_DITOLAK/_DIBATALKAN` |
| `users` | Akun login | `is_active` **tidak** disentuh saat exit (AUD-01) |

**Indeks:** `(anggota_id, status)` dan `(status, created_at)`. **Tidak ada indeks pada `no_referensi` selain unique** — memadai untuk pencarian dokumen.

### 1.4 Matriks Izin Aktual (`config/izin.php` L79–83)

| Izin | Peran | Endpoint |
|---|---|---|
| `terminasi.pengajuan` | teller, **manajer**, **pengurus** | index, create, cari-anggota, store, **show**, **bukti-pdf**, **bukti-thermal** |
| `terminasi.eksekusi` | teller, **manajer**, **pengurus** | eksekusi, tolak, batalkan |
| `terminasi.approval` | **manajer**, **pengurus** | setujui, tolak-approval |

Terdapat **SSOT ganda**: grup `'eksekusi_terminasi' => ['teller','manajer','pengurus']` di `config/role_hierarki.php` L346 dipakai `show.blade.php` L19 untuk menyembunyikan/menampilkan tombol, terpisah dari `config/izin.php`. Tidak ada grup `approval_terminasi`.

---

## 2. TEMUAN AUDIT

**Skala keparahan:** **KRITIS** = berpotensi kerugian finansial langsung, penyalahgunaan wewenang, atau kegagalan kontrol wajib; **TINGGI** = kesalahan perhitungan/proses berdampak material atau celah kontrol signifikan; **SEDANG** = inkonsistensi yang menimbulkan salah operasional/jejak audit lemah; **RENDAH** = kosmetik, dokumen, atau peningkatan kualitas.

### 2.1 Ringkasan Temuan

| # | Temuan | Keparahan | Lokasi utama |
|---|---|---|---|
| AUD-01 | Pemutusan akses sistem saat exit tidak diimplementasikan (`users.is_active` tidak dinonaktifkan; sesi aktif tidak diputus; `NasabahController` tanpa cek status) | **KRITIS** | `ExitKeanggotaanService::eksekusi()` L871–881; `NasabahController.php` L23–25; `routes/web.php` L703–733; `WebAuthController.php` L33–51 |
| AUD-02 | Tidak ada modul kepegawaian/offboarding karyawan (payroll, pesangon, serah terima aset, cuti) | **KRITIS** (GAP arsitektural) | Seluruh basis data |
| AUD-03 | Self-approval mungkin: peran `manajer` memegang pengajuan + approval + eksekusi sekaligus; tidak ada cek `diajukan_oleh !== penyetuju` | **KRITIS** | `config/izin.php` L80–82; `ExitKeanggotaanService::setujui()` L461–523 |
| AUD-04 | Workflow approval nonaktif secara default: `high_value_withdrawal_limit = 0` → seluruh exit lolos tanpa persetujuan | **KRITIS** (konfigurasi produksi) | `Setting.php` L247, L1023–1026; `butuhApproval()` L1184–1189 |
| AUD-05 | Gerbang kelayakan, NPF, dan perhitungan saldo dijalankan **di luar** `DB::transaction` → *time-of-check to time-of-use* (TOCTOU) | **TINGGI** | `eksekusi()` L674, L679, L686 sebelum L727–731 |
| AUD-06 | Cek-lalu-tulis pada `MemberTermination` tanpa `lockForUpdate` → dua eksekusi paralel dapat menghasilkan jurnal ganda | **TINGGI** | `eksekusi()` L636–659 vs L731 |
| AUD-07 | Biaya administrasi penutupan CIF dipotong dari hak anggota tetapi **tidak pernah** masuk daftar kendala exit; docblock mengklaim sebaliknya. Default `closure_admin_fee = 0` → potongan fiktif di UI/PDF | **TINGGI** | `kendalaTazirDenda()` L257–271; `rincianSaldo()` L1064; `Setting.php` L246 |
| AUD-08 | Kendala angsuran memakai `scopeBelumDibayar()` yang mencakup **'Belum Jatuh Tempo'** → angsuran **masa depan** memblokir exit; inkonsisten dengan NPF yang hanya menghitung sisa riil | **TINGGI** | `kendalaAngsuran()` L227–249; `Angsuran::scopeBelumDibayar()` |
| AUD-09 | `show()` dan `bukti-*.pdf/thermal` memakai izin `terminasi.pengajuan` → **teller** dapat melihat & mencetak data keuangan pengajuan **nilai tinggi yang belum disetujui** | **TINGGI** | `routes/web.php` L643–646; Controller L335, L362 |
| AUD-10 | `cariAnggota()` tanpa throttle, mengembalikan rincian keuangan penuh + `nik_masked` untuk **setiap** `anggota_id` — dapat dipakai enumerasi data | **TINGGI** | Controller L205–304; `routes/web.php` L641 |
| AUD-11 | `exit_approved_by` pada CIF diisi pelaksana eksekusi bila tidak ada approval (`$terminasi->disetujui_oleh ?: $userId`); `disetujui_oleh` juga diisi eksekutor di L901 → jejak persetujuan **palsu** | **SEDANG** | `eksekusi()` L880, L901 |
| AUD-12 | `exit_approval_logs` tanpa constraint unik per (dokumen, aksi), tanpa IP/user-agent, enum `aksi` tanpa `CANCELLED`; `tolak()`/`batalkan()` tidak menulis log ke tabel ini | **SEDANG** | migrasi `2026_10_20_000100` L82–93; `tolak()` L1349–1384; `batalkan()` L1386–1424 |
| AUD-13 | `generateNoTerminasi()` memakai pola *max-scan* tanpa lock (race pada pengajuan bersamaan); berbeda dari `generateNoReferensi()` yang race-safe | **SEDANG** | `MemberTermination.php` L253–266 |
| AUD-14 | Akrual bagi hasil deposito dihitung sebagai `pokok × rate_pa × (hari/365) × (nisbah/100)` — asumsi **rate tetap**, bukan bagi hasil atas pendapatan aktual (mudharabah) | **SEDANG** | `rincianDeposito()` L1122–1172; `Setting::depositoRatePa()` |
| AUD-15 | `jurnalPengembalianDanaExit()` mengembalikan `null` untuk kasus normal (bruto 0 / net ≤ 0) dan `eksekusi()` memperlakukannya sebagai kegagalan meski pesan pengecualian sudah tersedia | **SEDANG** | `AutoJournalingService.php` L892–911 |
| AUD-16 | Tidak ada halaman antrean approval; menu Manajer/Pengurus mengarah ke `member-terminations.index` tanpa preset filter `MENUNGGU_APPROVAL` | **SEDANG** | `config/navigasi.php` L213, L241 |
| AUD-17 | `kendalaPenjamin()` fail-open bila tabel `pembiayaan_penjamin` tidak ada → penjamin tidak pernah diverifikasi pada instalasi yang belum menerapkan migrasi | **SEDANG** | `kendalaPenjamin()` L307–366 |
| AUD-18 | Tidak ada kolom `disetujui_pada` / `approved_by` terpisah; keputusan approval hanya tertanggal `approved_at`, tidak ada SLA *timestamp* tiap tahap yang terstruktur | **SEDANG** | Skema `member_terminations` |
| AUD-19 | `config/navigasi.php` menampilkan menu approval kepada Manajer/Pengurus meskipun fitur tidak aktif (limit = 0) — UI menjanjikan alur yang tidak berjalan | **RENDAH** | `config/navigasi.php` L213, L241 |
| AUD-20 | Klaim UI menyesatkan: `create.blade.php` L126 menyatakan pengajuan "ditolak otomatis"; pesan `setujui()`/`tolakApproval()` menyebut "Hanya Manajer" padahal `pengurus` juga berwenang | **RENDAH** | `create.blade.php` L126; Controller L505, L525 |
| AUD-21 | Nomor `transaksi` exit memakai `date('Ymd')` (tanggal server hari ini), bukan `$tanggal` pengunduran diri → baris ledger dapat berlabel tanggal berbeda dengan tanggal dokumen | **RENDAH** | `generateNoTransaksi()` L1318–1324 |
| AUD-22 | Hanya baris `transaksi` pertama yang tertaut ke dokumen (`transaksi_id`), 4 baris lain hanya terhubung melalui `referensi` string | **RENDAH** | `eksekusi()` L785, L897 |
| AUD-23 | `AuditLog::record(..., $anggota->rekening()->first(), ...)` dapat mengirim `null` → `no_rekening` tercatat `'-'`; pada beberapa jalur panggilan juga berpotensi `null` dereference di `$terminasi->anggota?->rekening()` | **RENDAH** | `eksekusi()` L919–922; `catatBlokirExit()` L1337–1342 |
| AUD-24 | `index()` hanya memfilter berdasarkan status & pencarian; tidak ada filter rentang tanggal, cabang, atau ekspor — menyulitkan audit historis | **RENDAH** | Controller L61–92 |
| AUD-25 | Tidak ada tautan dokumen pendukung (surat pengunduran diri, berita acara, KTP, bukti transfer) pada dokumen exit | **RENDAH** | Skema `member_terminations` |

**Distribusi:** KRITIS 4 · TINGGI 6 · SEDANG 8 · RENDAH 7 · **Total 25 temuan**.

### 2.2 Rincian Temuan, Dampak, dan Bukti

#### AUD-01 — Pemutusan akses sistem tidak diimplementasikan · **KRITIS**

**Lokasi:** `ExitKeanggotaanService::eksekusi()` L871–881; `NasabahController.php` L23–25; `routes/web.php` L703–733 & L636–660; `WebAuthController.php` L33–51; `PastikanAnggotaAktif.php`.

**Kondisi faktual:**
1. `eksekusi()` memperbarui `anggota` menjadi `TERMINATED` (L871–881) tetapi **tidak pernah** menyentuh `users.is_active` milik anggota (`where('user_id', ...)`). Grep di seluruh `app/` menunjukkan `ExitKeanggotaanService` hanya menyentuh `User` pada L1213 (mencari manajer).
2. `NasabahController` (L23–25) mengambil `Anggota::where('user_id', Auth::id())->first()` dan hanya `abort_if(!$anggota)`. **Tidak ada** pemeriksaan `isActive()`/`isTerminated()` pada 12 rute `nasabah/*`. Rute tersebut juga **tidak** memasang middleware `anggota.aktif` (komentar L704–706 menyebut `anggota.aktif`, tetapi middleware tidak dipasang).
3. Middleware `PastikanAnggotaAktif` hanya terpasang di 3 baris (`routes/web.php` L163, L523, L527); grup `member-terminations` (L636–660) dan `nasabah/*` (L703–733) tidak memakainya.
4. `WebAuthController` L39 memeriksa `is_active` **hanya saat login**. Sesi yang sudah berjalan tetap hidup sampai *logout* / kedaluwarsa. Tidak ada mekanisme invalidasi sesi (mis. tabel `sessions` atau middleware *heartbeat*).

**Dampak:** Anggota yang sudah resmi keluar **tetap dapat login dan menggunakan portal anggota** — melihat saldo, riwayat, mengajukan pembiayaan baru (`nasabah.pengajuan-pinjaman.store`), serta memutakhirkan profil — tanpa batas waktu, karena `is_active` tidak pernah diubah. Sesi yang sedang aktif **tidak terputus** pada detik eksekusi. Ini adalah kegagalan langsung atas langkah 4 permintaan audit ("pemutusan akses sistem") dan melanggar prinsip *least privilege* serta kewajiban penutupan akses pada UU PDP 27/2022 Pasal 35 (penghentian pemrosesan data pribadi).

#### AUD-02 — Tidak ada modul kepegawaian/offboarding karyawan · **KRITIS (GAP)**

**Lokasi:** Seluruh basis data & `app/`.

**Kondisi faktual:** Tidak ada tabel/model `karyawan`, `pegawai`, `employee`, `payroll`, `penggajian`; tidak ada relasi `User` → karyawan (`User` hanya memiliki `role` & `is_active`, `$fillable` L22–23). Tidak ada entitas pesangon, cuti, serah terima aset, atau *exit interview*.

**Dampak:** Seluruh permintaan audit yang menyasar "karyawan" **tidak dapat dipenuhi secara substansi** oleh sistem saat ini. Bila koperasi mempekerjakan karyawan, proses pengunduran dirinya dijalankan **di luar sistem** (manual/spreadsheet) sehingga: (a) tidak ada jejak audit atas hak akhir karyawan (gaji terakhir, pesangon, sisa cuti, potongan pinjaman karyawan); (b) hak akses akun `users` untuk pegawai tidak dikelola oleh alur apa pun; (c) risiko *ghost employee* dan salah bayar tidak terdeteksi. Ini harus diputuskan oleh manajemen sebagai keputusan lingkup, bukan ditutupi sebagai "menyesuaikan modul anggota".

#### AUD-03 — Self-approval mungkin (segregation of duties gagal) · **KRITIS**

**Lokasi:** `config/izin.php` L80–82; `ExitKeanggotaanService::setujui()` L461–523, `kirimPermintaanApproval()` L1198–1240; `MemberTerminationController::setujui()` L503–521.

**Kondisi faktual:** `terminasi.pengajuan`, `terminasi.eksekusi`, dan `terminasi.approval` **semuanya** dimiliki `manajer`. `setujui()` tidak membandingkan `$terminasi->diajukan_oleh` dengan `$userId`. `kirimPermintaanApproval()` juga tidak mengecualikan pengaju dari daftar penerima (L1213–1216) dan tidak memfilter `is_active`.

**Dampak:** Satu orang Manajer dapat mengajukan → menyetujui → mencairkan exit senilai berapa pun tanpa keterlibatan pihak kedua. Kontrol *maker-checker* yang menjadi tujuan fitur approval menjadi **tidak berfungsi**. Ini adalah pelanggaran prinsip *segregation of duties* dan eksposur fraud langsung.

#### AUD-04 — Workflow approval nonaktif secara default (nilai aktual DB = 0) · **KRITIS (konfigurasi)**

**Lokasi:** `Setting.php` L247 (`high_value_withdrawal_limit => 0.00`), L246 (`closure_admin_fee => 0.00`), L1023–1026, L1184–1189.

**Kondisi faktual (verifikasi tinker pada DB aktif):** `Setting::highValueWithdrawalLimit()` = **0**; `closureAdminFee()` = **0**; `pembulatanDesimal()` = **0**; `depositoRatePa()` = **0.08**. Karena `butuhApproval()` mensyaratkan `$limit > 0`, **seluruh pengajuan** langsung berstatus `DIAJUKAN` dan dapat dicairkan oleh teller tanpa persetujuan. Menu approval tetap tampil di navigasi (AUD-19).

**Dampak:** Fitur persetujuan manajer — satu-satunya kontrol atas pencairan bernilai besar — **mati secara operasional** pada konfigurasi saat ini, sehingga AUD-03 pun tidak termitigasi (self-approval bahkan tidak diperlukan; siapa pun di peran `teller` dapat langsung mencairkan). Ini adalah temuan konfigurasi produksi, bukan sekadar default kode.

#### AUD-05 — TOCTOU: verifikasi & perhitungan di luar transaksi · **TINGGI**

**Lokasi:** `eksekusi()` L674 `pastikanLayakExit()`, L679 `pastikanNpfNol()`, L686 `rincianSaldo()`, L717 `pastikanAkunEkuitasSiap()` — semuanya **sebelum** `DB::transaction()` dibuka pada L730 dan sebelum `SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED` (L727).

**Kondisi faktual:** Seluruh gerbang dan nominal dihitung dari *snapshot* yang tidak terkunci. Di dalam transaksi (L730+) hanya saldo `rekening` yang dikunci (`lockForUpdate`, L847). `AJUKAN()` juga menghitung `rincianSaldo()` di L409 di luar transaksi (L412).

**Dampak:** Antara L686 dan pelaksanaan jurnal, proses lain (setoran, penarikan teller, posting angsuran, pencairan deposito) dapat mengubah saldo, sehingga nominal yang dijurnal **berbeda** dari saldo riil saat jurnal diposting. `pastikanLayakExit()` juga dapat menjadi basi: pembiayaan baru cair di antara verifikasi dan jurnal, dan exit tetap diproses meski NPF ≠ 0. Konsekuensi: penyimpangan buku besar & potensi kerugian.

#### AUD-06 — Race condition pada dokumen terminasi · **TINGGI**

**Lokasi:** `eksekusi()` L636–659 (baca `sudahDiposting()` & status dari objek di memori) vs L731–903 (tulis).

**Kondisi faktual:** Model `MemberTermination` tidak di-*refresh* dengan `lockForUpdate()` di dalam transaksi. Guard idempotensi (`jurnal_id !== null`) dibaca sebelum transaksi.

**Dampak:** Dua klik/dua *request* bersamaan (mis. *double submit* teller) dapat lolos guard yang sama dan memposting **dua jurnal + sepuluh baris transaksi** untuk satu dokumen, berarti pembayaran ganda. `unique` hanya ada pada `no_terminasi`/`no_referensi` (baris dokumen), **bukan** pada jurnal.

#### AUD-07 — Biaya administrasi tidak diverifikasi pada tahap kendala · **TINGGI**

**Lokasi:** `kendalaTazirDenda()` L257–271 (docblock menyebut *"ta'zir/denda & biaya administrasi"*, kode hanya `TazirPenangguhan::totalTunggakanAnggota()`); `rincianSaldo()` L1064 (memotong `closure_admin_fee`); `Setting.php` L246.

**Kondisi faktual:** `kendalaExit()` (L91–100) merangkai 5 fungsi kendala; **tidak ada** yang memeriksa biaya administrasi maupun saldo minimal untuk menutup biaya. `rincianSaldo()` memotong biaya dari `totalNet` dan membatasi `max(0.0, ...)` (L1078). `parameterTersedia()` (L1034–1037) selalu bernilai `true` karena hanya mengecek `array_key_exists` pada default — jadi cabang pengecualian L1043–1048 **tidak pernah** menyala.

**Dampak:** (a) Docblock menyesatkan auditor/pengembang berikutnya sehingga kontrol dianggap ada; (b) jika biaya > 0, potongan diterapkan tanpa pernah ditampilkan sebagai kendala, dan bila biaya > bruto, `total_net` menjadi 0 dengan `eksekusi()` menolak di L707 — **pengajuan permanen tidak dapat dieksekusi tanpa penjelasan kendala**; (c) pada DB aktif biaya = 0, sehingga PDF & UI menampilkan baris "Biaya Administrasi Penutupan CIF" senilai Rp 0 (kebingungan anggota).

#### AUD-08 — Angsuran masa depan memblokir exit · **TINGGI**

**Lokasi:** `kendalaAngsuran()` L227–249; `Angsuran::scopeBelumDibayar()` = `['Belum Jatuh Tempo','Jatuh Tempo','Terlambat','Dibayar Sebagian']`.

**Kondisi faktual:** Kendala menghitung **semua** angsuran yang belum dibayar, termasuk yang **belum jatuh tempo** (sisa jadwal hingga akhir tenor). Sementara `npfAnggota()` (L116–150) menghitung sisa riil dan `pastikanNpfNol()` hanya menolak bila NPF > 0 — padahal NPF memang > 0 selama ada sisa pokok berjalan. Kedua gerbang ini dapat saling bertentangan: exit anggota dengan pembiayaan berjalan selalu gagal, meski pelunasan dipercepat sudah dilakukan dengan benar (mis. pelunasan dipercepat lewat mekanisme lain tanpa memutakhirkan jadwal angsuran).

**Dampak:** Exit **tidak dapat diselesaikan** untuk anggota yang sisa angsurannya hanya masa depan, meskipun tidak ada tunggakan **nyata**. Anggota terjebak berstatus `DIAJUKAN` tanpa jalan keluar di sistem; operator dipaksa memanipulasi data. Ini penyimpangan dari SOP koperasi syariah umum yang memakai **pelunasan dipercepat (muqashshah)** bukan pelunasan seluruh tenor.

#### AUD-09 — Kontrol akses dokumen tidak sesuai kewenangan · **TINGGI**

**Lokasi:** `routes/web.php` L643–646; Controller L335, L362.

**Kondisi faktual:** `show`, `bukti-pdf`, `bukti-thermal` berada di grup `izin:terminasi.pengajuan` — peran `teller` termasuk. Dokumen `show` & bukti memuat rincian seluruh komponen dana + NIK (PDF menampilkan NIK penuh).

**Dampak:** Teller dapat membuka dan mencetak dokumen **pencairan bernilai tinggi yang masih `MENUNGGU_APPROVAL`** — melemahkan kontrol approval (dokumen seolah siap dibayar), serta membuka akses PII penuh di luar kewenangan kasir. Tidak ada pembatasan "dokumen final saja" (`pastikanDokumenFinal()` hanya diterapkan pada PDF/thermal, tidak pada `show`).

#### AUD-10 — Enumerasi data melalui `cariAnggota()` · **TINGGI**

**Lokasi:** Controller L205–304; rute L641.

**Kondisi faktual:** Endpoint menerima `anggota_id` dan mengembalikan JSON berisi nama, `nik_masked`, alamat/kontak (bila ada), `kendala`, dan **rincian finansial penuh** (`rincian`: pokok, wajib, sukarela, deposito, akrual, zakat, total_net). Tidak ada `throttle:` pada rute mana pun dalam grup exit (perbandingan: rute login biasanya diberi throttle).

**Dampak:** Peran `teller` (atau sesi yang dibajak) dapat memindai ID berurutan dan memanen profil keuangan seluruh anggota. Tidak ada pergantian jejak audit per permintaan pencarian.

#### AUD-11 hingga AUD-25 (ringkas)

| # | Kondisi faktual | Dampak |
|---|---|---|
| AUD-11 | `exit_approved_by = $terminasi->disetujui_oleh ?: $userId` (L880); `disetujui_oleh` juga diisi `$userId` pada L901 | CIF mencatat "disetujui oleh" = teller pelaksana → jejak persetujuan palsu; tidak dapat membedakan approve vs eksekusi |
| AUD-12 | `exit_approval_logs` tanpa unique (dokumen, aksi); tanpa IP/UA; `tolak()`/`batalkan()` hanya menulis `audit_logs` | Approve ganda dapat tercatat; tidak dapat membuktikan *siapa dari perangkat mana*; riwayat keputusan terpecah dua kanal |
| AUD-13 | `generateNoTerminasi()` L253–266: `orderByDesc(...)->value()` tanpa lock di dalam transaksi | Dua pengajuan paralel dapat menghasilkan `no_terminasi` sama → `QueryException` unique (pengajuan gagal acak) |
| AUD-14 | `rincianDeposito()` L1122–1172 memakai `rate_pa` 8% tetap × nisbah | Akrual tidak mencerminkan pendapatan aktual; berpotensi membayar bagi hasil lebih/kurang dari hak |
| AUD-15 | `jurnalPengembalianDanaExit()` mengembalikan `null` (L892–911) | `eksekusi()` memperlakukannya sebagai kegagalan generik; pesan sebenarnya tertimbun di log |
| AUD-16 | `config/navigasi.php` L213 & L241 → `member-terminations.index` | Tidak ada antrean approval; Manajer harus mencari manual di daftar campuran |
| AUD-17 | `kendalaPenjamin()` L307–366 `return []` bila tabel tidak ada | Penjamin tidak diperiksa → exit lolos meski ada penjamin aktif |
| AUD-18 | Skema tanpa `disetujui_pada` | SLA antar-tahap tidak dapat diukur secara terstruktur (hanya `diajukan_pada`/`diproses_pada`) |
| AUD-19 | Menu approval tampil meski limit = 0 | Ekspektasi pengguna vs perilaku sistem tidak selaras |
| AUD-20 | `create.blade.php` L126 klaim "ditolak otomatis"; pesan L505/L525 "Hanya Manajer" | Informasi menyesatkan; dasar sengketa |
| AUD-21 | `generateNoTransaksi()` memakai `date('Ymd')` | Nomor transaksi berlabel tanggal hari ini, bukan tanggal pengunduran diri |
| AUD-22 | Hanya `transaksiUtama` ditautkan (L785, L897) | Rekonsiliasi harus melalui pencocokan string `referensi` |
| AUD-23 | `$anggota->rekening()->first()` dapat `null` | `no_rekening` = '-' di audit log; kehilangan korelasi rekening |
| AUD-24 | `index()` tanpa filter tanggal/cabang/ekspor | Audit historis & pelaporan lambat |
| AUD-25 | Tidak ada lampiran dokumen (surat resign, BA, bukti transfer) | Kelengkapan dokumen tidak dapat diverifikasi dari sistem |

---

## 3. VERIFIKASI INTEGRITAS DATA

### 3.1 Metode

Query `php artisan tinker` (read-only) terhadap basis data aktif `koperasi_syariah`, ditambah pemeriksaan skema aktual (`Schema::getColumnListing`).

### 3.2 Hasil Pemeriksaan

| # | Uji integritas | Query / dasar | Hasil |
|---|---|---|---|
| 1 | Jumlah dokumen exit | `MemberTermination::count()` | **0** |
| 2 | Distribusi status | `groupBy('status')` | kosong |
| 3 | Dokumen tanpa `no_referensi` | `whereNull('no_referensi')` | **0** |
| 4 | Status DISETUJUI tanpa `jurnal_id` | `where('status','DISETUJUI')->whereNull('jurnal_id')` | **0** |
| 5 | `approved_at` terisi tanpa `exit_approval_logs` | `whereNotNull('approved_at')->whereDoesntHave('approvalLogs')` | **0** |
| 6 | Jumlah baris `exit_approval_logs` | `DB::table('exit_approval_logs')->count()` | **0** |
| 7 | Anggota `TERMINATED` | `where('status_anggota','TERMINATED')` | **0** |
| 8 | Akun `users` aktif milik anggota TERMINATED | join `anggota.user_id` + `users.is_active=1` | **0** |
| 9 | Rekening bersaldo > 0 milik anggota TERMINATED | join `rekening.anggota_id` | **0** |
| 10 | Deposito ACTIVE/MATURED milik anggota TERMINATED | join `depositos.member_id` | **0** |
| 11 | Anggota tanpa akun login (`user_id` NULL) | `whereNull('user_id')` | **13** |

**Skema aktual terverifikasi:** `member_terminations` memiliki 34 kolom (termasuk `no_referensi`, `nominal_*`, `total_net`, `butuh_approval`, `approved_at`, `exit_reason`) — **sesuai** dengan migrasi `2026_10_20_000100`. `exit_approval_logs` memiliki 7 kolom (`id`, `member_termination_id`, `user_id`, `aksi`, `catatan`, `created_at`, `updated_at`) — **tidak ada** kolom IP/user-agent sebagaimana AUD-12.

### 3.3 Kesimpulan Integritas

- **Riwayat pengunduran diri: belum ada data historis sama sekali** (`member_terminations` = 0, anggota TERMINATED = 0). Karena itu **tidak ditemukan inkonsistensi data riil** — tetapi hal ini juga berarti **seluruh temuan pada Bab 2 belum teruji terhadap data produksi**; satu-satunya validasi adalah telaah kode dan suite otomatis yang sudah ada. **Kesimpulan: integritas historis "belum dapat diaudit" (not auditable), bukan "terbukti bersih".**
- **Kelengkapan dokumen:** tidak dapat dinilai dari data; secara struktural ditemukan celah AUD-25 (tanpa lampiran) dan AUD-18 (tanpa cap waktu per tahap).
- **Akurasi hak akhir:** formula `bruto = pokok + wajib + sukarela + deposito + akrual`; `net = bruto − zakat − biaya_admin`, dibatasi `max(0, ...)`. Dua risiko akurasi teridentifikasi: **AUD-14** (akrual deposito berbasis rate tetap, bukan bagi hasil aktual) dan **AUD-07** (potongan biaya admin tanpa kendala/transparansi). Pembulatan konsisten memakai `Setting::bulatkanUang()` (2 desimal) — **tidak ditemukan** percampuran `pembulatanNominal()` dan `bulatkanUang()` yang menyebabkan selisih pada jalur exit.
- **Ketepatan waktu eksekusi:** tidak dapat diukur pada data kosong; secara struktural, SLA **tidak dapat dihitung** karena skema tidak memiliki `disetujui_pada` (AUD-18) dan tidak ada kolom waktu terpisah untuk "approval diberikan" vs "dana dibayar".
- **Pemutusan akses:** **tidak dapat diverifikasi karena tidak diimplementasikan** (AUD-01). Uji #8 menghasilkan 0 hanya karena tidak ada anggota TERMINATED; bila ada, `is_active` dipastikan tetap 1 berdasarkan jalur kode.
- **Catatan tambahan:** 13 anggota tidak memiliki `user_id`. Bagi anggota tanpa akun login, AUD-01 tidak relevan secara individual, tetapi menunjukkan bahwa relasi anggota↔akun bersifat opsional dan tidak divalidasi pada saat exit.

---

## 4. ANALISIS AKAR MASALAH

| Akar masalah | Temuan yang diturunkan | Penjelasan |
|---|---|---|
| **A. Ruang lingkup sistem tidak mencakup kepegawaian; "exit" hanya dimodelkan untuk anggota** | AUD-01, AUD-02 | Proses keluar dirancang sebagai penutupan **keuangan keanggotaan** (`member_terminations`), bukan *offboarding* organisasi. Tidak ada konsep "akun sistem milik orang yang keluar", sehingga langkah terakhir (pemutusan akses) tidak pernah menjadi *use case* yang ditulis. |
| **B. Kontrol dipisah antar-lapisan tanpa kontrak bersama** | AUD-05, AUD-06, AUD-08, AUD-13 | Gerbang kelayakan (`kendalaExit`, `npfAnggota`, `rincianSaldo`) adalah fungsi *read-only* yang dipanggil di luar transaksi; di dalam transaksi hanya saldo rekening yang dikunci. Tidak ada kebijakan penguncian tunggal (mis. "semua entitas anggota dikunci pada awal eksekusi"). |
| **C. RBAC berbasis izin kasar (coarse-grained) dengan SSOT ganda** | AUD-03, AUD-09, AUD-10 | Tiga izin hanya membedakan *aksi*, tidak *subjek* (pengaju vs penyetuju) maupun *objek* (dokumen belum/sudah final). `config/role_hierarki.php` L346 menduplikasi daftar peran yang sama. Akibatnya `manajer` memegang seluruh rantai, dan `teller` memegang *view* dokumen bernilai tinggi. |
| **D. Parameter kebijakan bernilai nol pada data aktif, dan validasi parameter palsu** | AUD-04, AUD-07, AUD-19 | `high_value_withdrawal_limit = 0` dan `closure_admin_fee = 0` membuat fitur approval & biaya "ada tapi mati". `parameterTersedia()` yang selalu `true` (L1034–1037) menghapus satu-satunya lapisan validasi konfigurasi. |
| **E. Semantik "tunggakan" tidak dibedakan dari "sisa kewajiban"** | AUD-08 | `scopeBelumDibayar()` dirancang untuk penagihan (mencakup masa depan), lalu dipakai ulang sebagai gerbang exit tanpa *adapter* khusus exit. |
| **F. Model jejak audit tidak lengkap** | AUD-11, AUD-12, AUD-16, AUD-18, AUD-21, AUD-22, AUD-23 | Kolom "siapa" dipakai ganda (`disetujui_oleh`), tabel log tidak imutabel/kaya konteks, tidak ada cap waktu per tahap, dan relasi ledger ke dokumen bersifat parsial. |
| **G. Validasi & pengujian hanya pada jalur bahagia** | AUD-14, AUD-15, AUD-17 | Cabang `null` pada jurnal, `return []` pada penjamin, dan asumsi rate tetap tidak memiliki test yang mengunci perilaku negatif; pesan galatnya pun tidak dipakai sebagai output yang bermakna. |
| **H. UX/dokumentasi tidak disinkronkan dengan perilaku** | AUD-20, AUD-24, AUD-25 | Teks UI & docblock menuliskan janji yang tidak ditegakkan kode ("ditolak otomatis", "cek biaya administrasi", "hanya Manajer"). |

**Pola akar yang menyatukan:** modul exit tumbuh sebagai penutupan akuntansi (fokus jurnal & saldo) **tanpa** lapisan *governance* (otorisasi berjenjang, pemutusan akses, kelengkapan dokumen) dan **tanpa** lapisan *hardening* (penguncian, throttle, validasi parameter). Kontrol yang tampak ada (approval, biaya admin, penjamin) sebagian besar **tidak aktif secara konfigurasi** atau **tidak terhubung** ke alur.

---

## 5. REKOMENDASI PERBAIKAN (TERUKUR & DAPAT DIIMPLEMENTASIKAN)

Prioritas mengikuti keparahan. Setiap item memuat **lokasi**, **tindakan**, dan **kriteria penerimaan (DoD)** yang dapat diuji otomatis.

### 5.1 Prioritas 0 — Segera (KRITIS), target 1 sprint

| Ref | Rekomendasi | Kriteria penerimaan |
|---|---|---|
| R-01 | **Implementasikan pemutusan akses pada eksekusi exit.** Di dalam `DB::transaction` `eksekusi()`, setelah `anggota` di-`forceFill`, tambahkan: jika `$anggota->user_id` tidak null → `User::whereKey($anggota->user_id)->update(['is_active' => false])`; lalu hapus sesi: `DB::table(config('session.table'))->where('user_id', $anggota->user_id)->delete()` (bila driver `database`) dan `Auth::logoutOtherDevices` ekuivalen. Catat `AuditLog::record('EXIT_AKSES_DIPUTUS', ...)`. Tambahkan juga middleware `anggota.aktif` pada grup `nasabah/*` (`routes/web.php` L703–733). | (a) Test: setelah `eksekusi()`, `users.is_active` anggota = false; (b) test: request ke `/nasabah/angsuran` sebagai anggota TERMINATED → 403; (c) test: baris `sessions` milik user tersebut terhapus; (d) `audit_logs` memuat `EXIT_AKSES_DIPUTUS`. |
| R-02 | **Tetapkan lingkup kepegawaian secara eksplisit** (keputusan manajemen). Bila karyawan ada: buat modul karyawan terpisah (tabel `karyawan`, relasi `users.karyawan_id`), dan **jangan** memaksakan proses karyawan ke `member_terminations`. Bila karyawan tidak ada: dokumentasikan resmi bahwa "pengunduran diri" = exit anggota. | Dokumen keputusan tertandatangani; bila modul dibuat, skema & alur offboarding (pesangon, cuti, serah terima, nonaktif akun) tercantum dengan test. |
| R-03 | **Tegakkan segregation of duties.** Tambahkan guard di `setujui()`: `if ((int) $terminasi->diajukan_oleh === (int) $userId) throw new RuntimeException('Penyetuju tidak boleh sama dengan pengaju.');`. Di `config/izin.php` L80–82, pisahkan: `terminasi.approval` minimal tanpa `teller`; pertimbangkan memisahkan peran `manajer` (approval) dari `teller` (eksekusi) secara prosedur. Kecualikan pengaju dari `kirimPermintaanApproval()` (`where('id','!=',$terminasi->diajukan_oleh)`) dan filter `where('is_active', true)`. | Test: `setujui()` dengan `userId == diajukan_oleh` → exception; test: notifikasi tidak terkirim ke pengaju; test: user `is_active=false` tidak menerima notifikasi. |
| R-04 | **Aktifkan & tuntut konfigurasi produksi.** Isi `high_value_withdrawal_limit` dengan nilai riil (mis. 10.000.000) dan `closure_admin_fee` dengan nilai kebijakan. Tambahkan **validasi startup**: command `koperasi:cek-kebijakan-exit` yang gagal (exit code ≠ 0) bila limit = 0 atau biaya belum ditetapkan; jalankan di pipeline rilis. Perbaiki `Setting::parameterTersedia()` agar membedakan "default" vs "diset eksplisit". | (a) Nilai DB ≠ 0 terdokumentasi di SOP; (b) command gagal pada konfigurasi 0; (c) test `butuhApproval()` untuk kasus limit > 0. |

### 5.2 Prioritas 1 — TINGGI, target 1–2 sprint

| Ref | Rekomendasi | Kriteria penerimaan |
|---|---|---|
| R-05 | **Pindahkan seluruh gerbang & perhitungan ke dalam transaksi + kunci baris.** Urutan baru di `eksekusi()`: buka `DB::transaction` → `Anggota::whereKey($anggota->id)->lockForUpdate()->firstOrFail()` → `MemberTermination::whereKey($terminasi->id)->lockForUpdate()->firstOrFail()` → ulangi `sudahDiposting()`, status, `pastikanLayakExit()`, `pastikanNpfNol()`, `rincianSaldo()` → baru tulis. Hapus duplikasi baca pra-transaksi atau jadikan hanya untuk *fail-fast* UI. | Test konkurensi (dua transaksi paralel via `DB::beginTransaction` terpisah) menghasilkan **satu** jurnal; test bahwa perubahan saldo di antara request tidak mengubah nominal jurnal. |
| R-06 | **Idempotensi berlapis.** Tambahkan indeks unik parsial/logis pada `member_terminations(jurnal_id)` tidak memadai — gunakan `update ... where jurnal_id is null` bersyarat (compare-and-set) sebagai klaim eksekusi sebelum posting: `$claimed = MemberTermination::whereKey($id)->whereNull('jurnal_id')->update(['diproses_pada' => now()]); if ($claimed === 0) return $terminasi->fresh();`. | Test: dua pemanggilan `eksekusi()` berurutan tetap 1 jurnal; test paralel → 1 jurnal + 1 set transaksi. |
| R-07 | **Satukan semantik kendala dengan kontrol biaya.** Tambahkan `kendalaBiayaAdministrasi()` ke `kendalaExit()` yang menampilkan biaya & sisa hak; perbaiki docblock `kendalaTazirDenda()` (hapus klaim "biaya administrasi"). Pastikan baris PDF hanya tampil bila > 0. | Test: `kendalaExit()` memuat entri biaya bila `closure_admin_fee` > bruto; test PDF tidak memuat baris biaya bila = 0. |
| R-08 | **Pisahkan tunggakan nyata dari sisa masa depan.** Ganti `belumDibayar()` dengan scope exit khusus (mis. `whereIn('status_angsuran', ['Jatuh Tempo','Terlambat','Dibayar Sebagian'])` atau `where('tanggal_jatuh_tempo','<=', $tanggal)`), dan sediakan mekanisme **pelunasan dipercepat (muqashshah)** yang memposting pelunasan sisa pokok + margin proporsional sebelum exit dieksekusi. | Test: anggota dengan angsuran hanya masa depan dan tanpa tunggakan **boleh** diproses setelah pelunasan dipercepat; test: anggota dengan angsuran 'Terlambat' tetap diblokir. |
| R-09 | **Pisahkan izin baca dokumen dari izin pengajuan.** Buat izin baru `terminasi.lihat` (teller, manajer, pengurus) dan perketat `show()`/`bukti-*`: dokumen `MENUNGGU_APPROVAL` hanya untuk `terminasi.approval`; tambahkan filter data (NIK ter-mask pada HTML — sudah; PDF NIK penuh hanya untuk peran berwenang). Hapus grup duplikat `eksekusi_terminasi` di `config/role_hierarki.php` L346 dan gunakan `Izin::peran()` sebagai satu-satunya sumber. | Test: teller → `show()` dokumen MENUNGGU_APPROVAL = 403; test: semua `abort_unless` memakai `Izin::peran()`; test: `config/role_hierarki` tidak lagi memuat grup `eksekusi_terminasi`. |
| R-10 | **Hardening `cariAnggota()`.** Tambahkan `->middleware('throttle:30,1')` pada rute L641; wajibkan parameter pencarian eksplisit (bukan hanya `anggota_id` berurutan); catat `AuditLog::record('EXIT_ANGGOTA_DICARI', ...)`; kembalikan `total_net` hanya bila `boleh_ajukan`. | Test: 31 request/menit → 429; test: audit log tercatat; test: respons tanpa rincian bila ada kendala. |

### 5.3 Prioritas 2 — SEDANG, target 2–3 sprint

| Ref | Rekomendasi | Kriteria penerimaan |
|---|---|---|
| R-11 | Pisahkan kolom `approved_by` (penyetuju) dari `diproses_oleh` (eksekutor); hapus pengisian `disetujui_oleh` di L901; isi `exit_approved_by` hanya bila approval benar-benar terjadi, selain itu biarkan `null`. | Migrasi + test: CIF untuk jalur tanpa approval memiliki `exit_approved_by = null`. |
| R-12 | Perkuat `exit_approval_logs`: tambahkan `ip_address`, `user_agent`; tambahkan `aksi` `CANCELLED`; tulis log dari `tolak()` & `batalkan()`; tambahkan unique `(member_termination_id, aksi)` untuk APPROVED. | Migrasi + test: approve dua kali gagal di level DB; test: batalkan menulis log CANCELLED. |
| R-13 | Ganti `generateNoTerminasi()` dengan `NomorSequence::berikutnya('TRM-'.Ymd.'-', 4, 'member_terminations', 'no_terminasi')` (atau hentikan pemakaiannya; cukup `no_referensi`). | Test konkurensi: 50 pengajuan paralel → 50 nomor unik. |
| R-14 | Akrual deposito: gunakan mekanisme `DepositoService` yang menyimpan realisasi bagi hasil (`whereIn status STATUS_DIAKUI`/`STATUS_PAID`) sebagai dasar prorata, bukan `rate_pa × nisbah`. Sertakan `deposito_detail` di dokumen. | Test: akrual = Σ realisasi bagi hasil × (hari berjalan / hari periode), dihitung dengan test data nyata. |
| R-15 | `jurnalPengembalianDanaExit()`: kembalikan objek hasil (mis. `Jurnal|null` + alasan) dan di `eksekusi()` bedakan "tidak ada dana" (bukan error) dari "gagal posting". | Test: bruto 0 → pesan spesifik "tidak ada dana"; test: kegagalan CoA → pesan CoA. |
| R-16 | Buat halaman/route approval khusus (`member-terminations.approval.index`) dengan filter default `MENUNGGU_APPROVAL` + `butuh_approval = 1`; arahkan menu `config/navigasi.php` L213 & L241 ke sana. | Test: route menampilkan hanya dokumen menunggu; menu mengarah ke route baru. |
| R-17 | `kendalaPenjamin()`: fail-**closed** — bila tabel tidak ada, kembalikan kendala "verifikasi penjamin tidak tersedia, hubungi administrator" alih-alih `[]`. | Test: tanpa tabel → kendala muncul. |
| R-18 | Tambahkan kolom `disetujui_pada` (timestamp) dan `batas_sla_hari` (int) pada `member_terminations`; buat laporan SLA (jumlah hari pengajuan → approval → eksekusi). | Migrasi + test; laporan SLA tersedia. |

### 5.4 Prioritas 3 — RENDAH, target 3–4 sprint

| Ref | Rekomendasi |
|---|---|
| R-19 | Sembunyikan menu approval bila `high_value_withdrawal_limit = 0` (`config/navigasi.php`). |
| R-20 | Perbaiki teks: `create.blade.php` L126 (hilangkan klaim "ditolak otomatis", ganti dengan "dapat ditolak bila kendala ditemukan saat eksekusi"); pesan Controller L505/L525 → "Hanya Manajer/Pengurus yang berwenang...". |
| R-21 | `generateNoTransaksi()` menerima parameter `$tanggal` dan memakai tanggal pengunduran diri untuk prefix nomor. |
| R-22 | Tambahkan `referensi` + `member_termination_id` pada **seluruh** baris `transaksi` exit (bukan hanya pertama). |
| R-23 | `AuditLog::record`: pastikan `rekening` tidak `null` dengan mengambil rekening utama secara eksplisit (mis. `$anggota->rekening()->where('status_rekening','Aktif')->first()`) dan berikan `no_rekening` eksplisit bila tidak ada. |
| R-24 | Lengkapi `index()` dengan filter rentang tanggal, cabang/unit, dan ekspor CSV/Excel untuk audit historis. |
| R-25 | Tambahkan tabel lampiran `member_termination_documents` (jenis: SURAT_RESIGN, BERITA_ACARA, KTP, BUKTI_TRANSFER, dll.) dengan verifikasi wajib sebelum `eksekusi()`. |

### 5.5 Peta Jalan & Metrik Keberhasilan

| Fase | Item | Estimasi | Metrik |
|---|---|---|---|
| Sprint 1 | R-01, R-03, R-04, R-02 (keputusan) | 8–10 hari kerja | 0 akun aktif milik anggota TERMINATED; 0 self-approval; konfigurasi limit ≠ 0 tervalidasi |
| Sprint 2 | R-05, R-06, R-07, R-08, R-09, R-10 | 12–15 hari kerja | 0 jurnal ganda pada uji konkurensi; 0 dokumen menunggu approval yang dapat dicetak teller; exit tanpa tunggakan nyata dapat diselesaikan |
| Sprint 3 | R-11 s.d. R-18 | 12–15 hari kerja | Jejak approval lengkap (100% log ber-IP); SLA terukur; akrual deposito sesuai realisasi |
| Sprint 4 | R-19 s.d. R-25 | 8–10 hari kerja | UI konsisten; rekonsiliasi ledger 1:1; kelengkapan dokumen 100% |

**Rekomendasi tata kelola berkelanjutan:** (a) review RBAC setiap kuartal dengan matriks *maker-checker* untuk seluruh alur bernilai > batas materialitas; (b) masukkan `koperasi:cek-kebijakan-exit` ke pipeline rilis; (c) jadwalkan **audit berbasis data** setelah 30 transaksi exit pertama terkumpul untuk memvalidasi Bab 3 terhadap data riil (integritas historis saat ini *not auditable* karena 0 data); (d) buat register risiko yang memetakan setiap temuan AUD ke pemilik dan tanggal penyelesaian.

---

## 6. RINGKASAN EKSEKUTIF

- Audit menemukan **25 temuan**: **4 KRITIS**, **6 TINGGI**, **8 SEDANG**, **7 RENDAH**.
- **Tiga temuan paling mendesak** — pemutusan akses sistem tidak ada (**AUD-01**), tidak ada modul kepegawaian untuk "pengunduran diri karyawan" (**AUD-02**), dan kontrol approval dapat dilewati baik karena self-approval (**AUD-03**) maupun karena nonaktif secara konfigurasi (**AUD-04**) — membuat proses exit saat ini **tidak memenuhi standar tata kelola koperasi syariah** untuk langkah verifikasi berjenjang dan pemutusan akses.
- **Integritas data historis belum dapat dinilai** karena tabel `member_terminations` kosong (0 baris) dan belum ada anggota berstatus `TERMINATED`. Semua temuan berasal dari telaah kode & konfigurasi yang terverifikasi pada berkas dan basis data aktif, bukan dari insiden data.
- **Akar masalah dominan** adalah desain modul exit yang berfokus pada penutupan akuntansi tanpa lapisan *governance* (otorisasi, pemutusan akses, kelengkapan dokumen) dan tanpa lapisan *hardening* (penguncian, throttle, validasi konfigurasi).
- Dengan menyelesaikan **Prioritas 0 dan 1 (14 rekomendasi)** dalam dua sprint, risiko kerugian finansial langsung dan penyalahgunaan wewenang dapat diturunkan secara material; Prioritas 2–3 menyelesaikan inkonsistensi operasional dan jejak audit.

*— Akhir laporan —*
