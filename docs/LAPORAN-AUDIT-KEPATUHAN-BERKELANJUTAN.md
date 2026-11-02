# LAPORAN AUDIT KEPATUHAN BERKELANJUTAN
## RBAC · PSAK · OJK — Koperasi Syariah

Tanggal audit: 2026-10-05
Sifat: **read-only (tanpa penyimpangan/modifikasi sistem)** — verifikasi kesesuaian terhadap tiga kerangka regulasi.
Mandat: *tidak melakukan penyimpangan/modifikasi; mematuhi RBAC, PSAK, dan OJK; mengaudit kesesuaian secara berkelanjutan.*

---

## 0. PERNYATAAN KEPATUHAN (ATTESTATION)

> **Tidak ada penyimpangan yang ditemukan. Tidak ada modifikasi yang dilakukan pada audit ini.**
> Seluruh mekanisme RBAC, PSAK, dan OJK yang telah diterapkan **terverifikasi utuh dan aktif**. Bukti regresi: **full test suite 1319 passed (5758 assertions), 0 failed** — baseline tidak turun.

| Kerangka | Status | Sumber Kebenaran Tunggal | Gerbang Otomatis |
|---|---|---|---|
| **RBAC** | ✅ Patuh | `config/role_hierarki.php` → `App\Support\HierarkiRole`; `App\Support\LaporanKeuanganPermission` | `tests/Feature/RbacHierarkiRoleTest`, `MatriksHakAksesAuditTest`, `LaporanKeuanganRbacTest` |
| **PSAK** | ✅ Patuh | `App\Support\Coa` + `CoaResolver` + `pemetaan_akad_coa`; `AutoJournalingService::post()` | `psak:validasi-simpanan-wajib`, `jurnal:rekonsiliasi`, `jurnal:deteksi-orphan` |
| **OJK** | ✅ Patuh | `PpatkReportService`, `CddService`, `TransactionLimitService`, `config/keamanan.php` | `log:purge-kedaluwarsa`, `ckpn:hapus-buku`, test AML/PDP |

---

## 1. KEPATUHAN RBAC — Pengelolaan Hak Akses

### 1.1 Bukti implementasi
| Kontrol | Bukti | Status |
|---|---|---|
| Peran terdefinisi dengan level & kategori | [role_hierarki.php](file:///c:/laragon/www/koperasi_syariah/config/role_hierarki.php#L45-L110) — 8 peran (dps, pengurus, manajer, ao, teller, admin, anggota, nasabah) | ✅ |
| **Sumber kebenaran tunggal** (dilarang `config()` langsung) | Doktrin tertulis di header [role_hierarki.php](file:///c:/laragon/www/koperasi_syariah/config/role_hierarki.php#L11-L13); dibaca via `HierarkiRole` | ✅ |
| Rantai approval berjenjang (Teller→AO→Manajer→Pengurus) | [role_hierarki.php](file:///c:/laragon/www/koperasi_syariah/config/role_hierarki.php#L122-L127) + `status_menunggu` diturunkan otomatis | ✅ |
| **Pemisahan tugas (maker ≠ checker)** | [PemisahanTugas.php](file:///c:/laragon/www/koperasi_syariah/app/Support/PemisahanTugas.php#L26-L62) — satu definisi & pesan untuk semua modul | ✅ |
| Batas nominal & frekuensi per peran | `batas` (limit per transaksi + harian) & `frekuensi` (maks/hari) di [role_hierarki.php](file:///c:/laragon/www/koperasi_syariah/config/role_hierarki.php#L166-L203) | ✅ |
| Batas volume ekspor (minimalisasi data) | `akses_data.maks_baris_ekspor` [role_hierarki.php](file:///c:/laragon/www/koperasi_syariah/config/role_hierarki.php#L215-L220) | ✅ |
| Gerbang + **jejak audit** akses laporan | [LaporanKeuanganAccess.php](file:///c:/laragon/www/koperasi_syariah/app/Http/Middleware/LaporanKeuanganAccess.php#L34-L62) — menolak **dan mencatat** percobaan tak sah; dipasang SEBELUM `role:` agar percobaan tercatat | ✅ |
| Middleware peran | [CheckRole.php](file:///c:/laragon/www/koperasi_syariah/app/Http/Middleware/CheckRole.php#L16-L31) | ✅ |
| Override dinamis tanpa deploy | Tabel `limit_transaksi_role` (nilai tabel menang atas file) | ✅ |

### 1.2 Kepatuhan terhadap doktrin "jangan dinamiskan RBAC"
Sesuai analisis kelayakan sebelumnya, matriks RBAC **sengaja statis & diaudit baris-per-baris** (bukan dinamis di runtime) untuk mencegah *privilege escalation*. **Direktif "jangan menyimpang" ini sejalan** — audit memastikan matriks **tidak diubah** dan tetap utuh.

**Status: ✅ PATUH — tidak ada penyimpangan.**

---

## 2. KEPATUHAN PSAK — Pelaporan Keuangan Akuntabel

### 2.1 Bukti implementasi
| Standar | Kontrol | Bukti | Status |
|---|---|---|---|
| **PSAK 101** | Simpanan Wajib = Modal Anggota (`3-1002`), bukan kewajiban | Titik resolusi tunggal `MandatorySavingsService::akunSimpananWajib()`; `Coa::SIMPANAN_WAJIB_MODAL` | ✅ |
| **PSAK 108** | Margin Murabahah ditangguhkan; Qardhul Hasan **dilarang** punya margin | [AutoJournalingService.php](file:///c:/laragon/www/koperasi_syariah/app/Services/AutoJournalingService.php#L350-L386) — guard eksplisit | ✅ |
| **PSAK 109** | Dana Zakat & Infak/Sedekah **akun terpisah**, tidak menyentuh pendapatan | `ZakatService::ASNAF` (8 asnaf); `ZakatPsak109Test` 15 test lulus | ✅ |
| **PSAK 71** | CKPN piutang berbasis kolektibilitas, idempoten | `ckpn:hitung-penyesuaian` (harian 07:30 WIB, urut setelah mutasi) | ✅ |
| **Integritas jurnal** | Satu jalur tulis tunggal `AutoJournalingService::post()`; debet=kredit; menolak posting tak sah | `post()` melempar `JournalPostingException` via `tolakPosting(): never` | ✅ |
| **Pemisahan dana** | `AKUN_KREDIT_SAH_BY_POST_TYPE` + `AKUN_DANA_SOSIAL` menjaga akun sah per jenis posting | [AutoJournalingService.php](file:///c:/laragon/www/koperasi_syariah/app/Services/AutoJournalingService.php#L80-L139) | ✅ |
| **Kebijakan dapat ditetapkan & diungkapkan** (PSAK 101 ¶45–47) | ±60 parameter di `Setting` (cache + invalidasi + audit trail) | `App\Support\Setting` | ✅ |
| **Fleksibilitas akad** | Pemetaan akad→CoA dinamis | Tabel `pemetaan_akad_coa`; `FleksibilitasAkadPsakTest` 12 test lulus | ✅ |

### 2.2 Gerbang kepatuhan otomatis
- `php artisan psak:validasi-simpanan-wajib` — 7 kategori + exit code (dapat dipakai CI).
- `php artisan jurnal:rekonsiliasi` — verifikasi keseimbangan & konsistensi.
- `php artisan jurnal:deteksi-orphan` — mendeteksi jurnal tanpa pasangan.
- **Audit trail append-only + hash chain** — `AuditLogAppendOnlyTest` membuktikan baris tak dapat diubah/dihapus; penyuntingan langsung di DB **terdeteksi**.

**Status: ✅ PATUH — tidak ada penyimpangan.**

---

## 3. KEPATUHAN OJK — Operasional Koperasi Syariah

### 3.1 Bukti implementasi
| Regulasi | Kontrol | Bukti | Status |
|---|---|---|---|
| **APU-PPT / POJK 76/2016** | CDD, PEP, klasifikasi risiko; peran `nasabah` (non-anggota) dibedakan dari `anggota` | [role_hierarki.php](file:///c:/laragon/www/koperasi_syariah/config/role_hierarki.php#L95-L109); `CddService`; `Anggota::RISIKO_PILIHAN`/`CDD_PILIHAN`/`PEP_PILIHAN` | ✅ |
| **Pelaporan PPATK** | LTKT (tunai ≥ ambang), LTKM (mencurigakan), STR — ambang **dapat dikonfigurasi** | `PpatkReportService::AMBANG_TUNAI_DEFAULT=500.000.000` via `Setting::nominal('ltkt_threshold')` | ✅ |
| **GCG / pemisahan tugas** | Maker ≠ Checker; DPS & Admin di luar rantai operasional | `PemisahanTugas`; `HierarkiRole::penyetuju()` | ✅ |
| **Batas operasional** | Limit nominal, frekuensi harian, volume ekspor per peran | `config/role_hierarki.php` + tabel `limit_transaksi_role` | ✅ |
| **UU PDP No. 27/2022** | Jejak audit perubahan PII; retensi & purge; header keamanan; hashing PII | `ObserverAuditPii`; `log:purge-kedaluwarsa` (02:30 WIB); [KeamananHeader.php](file:///c:/laragon/www/koperasi_syariah/app/Http/Middleware/KeamananHeader.php); `PiiHasher`; `AuditPerubahanPiiTest` | ✅ |
| **Keamanan transparan** | CSP (+ mode ketat nonce), HSTS (hanya HTTPS), X-Frame-Options, Permissions-Policy | [KeamananHeader.php](file:///c:/laragon/www/koperasi_syariah/app/Http/Middleware/KeamananHeader.php#L38-L135) | ✅ |
| **Kewenangan Pengurus** (UU 25/1992, Permen KUKM 09/2018) | Panel kebijakan Pengurus; perubahan tercatat jejak audit | `PengurusController`; dokumen `TAHAP12-R6` | ✅ |

**Status: ✅ PATUH — tidak ada penyimpangan.**

---

## 4. MEKANISME AUDIT BERKELANJUTAN (yang sudah aktif)

Direktif "selalu mengaudit kesesuaian secara berkelanjutan" **sudah terpenuhi** oleh mekanisme berikut (terverifikasi, tanpa perlu penambahan):

| Lapis | Mekanisme | Frekuensi | Bukti |
|---|---|---|---|
| **Pre-merge (CI)** | Full test suite (1319 test) + gerbang `psak:validasi-simpanan-wajib` | Setiap perubahan | Baseline 1319/5758 hijau |
| **Runtime — jejak audit** | `LaporanKeuanganLog` (akses laporan), `AuditPerubahanPii` (PII), audit perubahan kebijakan | Real-time | Middleware + Observer |
| **Runtime — integritas** | Hash chain append-only; `jurnal:rekonsiliasi`, `jurnal:deteksi-orphan` | Manual/terjadwal | `AuditLogAppendOnlyTest` |
| **Terjadwal — operasional** | CKPN harian (07:30), autodebet (07:00), deposito jatuh tempo (00:01), bagi hasil (tgl 1), purge log (02:30) | Harian/bulanan | [console.php](file:///c:/laragon/www/koperasi_syariah/routes/console.php) |
| **Retensi data** | `log:purge-kedaluwarsa` (PDP Pasal 16 & 35, hormati kewajiban audit keuangan) | Harian 02:30 | `config/keamanan.php` |
| **Pemantauan sistem** | `denyut:kesehatan --rinci` (mesin Denyut) | On-demand | `DenyutKesehatan` |

### 4.1 Catatan kesenjangan (bukan penyimpangan) — usulan, TIDAK diimplementasikan
Berikut adalah area **perbaikan** (bukan pelanggaran) yang dapat dipertimbangkan **hanya bila disetujui**, tanpa menyentuh perilaku yang sudah ada:

| # | Kesenjangan | Dampak | Usulan (menunggu persetujuan) |
|---|---|---|---|
| 1 | Gerbang `psak:validasi-simpanan-wajib` belum dijadwalkan otomatis (masih manual/CI) | Kepatuhan PSAK tidak terpantau berkala | Jadwalkan sebagai pemeriksaan harian + notifikasi bila exit ≠ 0 |
| 2 | Belum ada health/readiness endpoint HTTP (`/health`, `/readyz`) | Orkestrator tak punya probe | Tambah endpoint read-only (aditif, tanpa mengubah alur) |
| 3 | Parameter zakat atas SHU belum ada (`rate`/`nisab`/`haul`) | Zakat SHU tidak dapat dihitung otomatis | Tambah parameter di `Setting` (aditif) |
| 4 | Kebijakan alokasi SHU masih dua sumber (5 pos form vs 4 pos Setting) | Risiko salah alokasi dana anggota | Satukan ke `Setting::alokasiShu()` |

> **Semua item di atas adalah USULAN.** Sesuai direktif "jangan menyimpang/modifikasi", **tidak satu pun diimplementasikan** dalam audit ini. Menunggu persetujuan eksplisit.

---

## 5. PERNYATAAN PENUTUP

1. **Tidak ada penyimpangan** terhadap RBAC, PSAK, maupun OJK yang ditemukan pada sistem yang diterapkan.
2. **Tidak ada modifikasi** yang dilakukan pada sistem dalam proses audit ini.
3. **Mekanisme audit berkelanjutan sudah aktif** (CI suite + gerbang PSAK + jejak audit real-time + penjadwalan operasional + retensi PDP).
4. **Bukti regresi:** `1319 passed (5758 assertions), 0 failed` — kepatuhan terjaga tanpa penurunan.
5. Setiap implementasi fitur baru **wajib** melewati gerbang: (a) RBAC utuh, (b) `post()` sebagai satu jalur jurnal & debet=kredit, (c) pemisahan dana PSAK 108/109, (d) jejak audit, (e) suite ≥ baseline.

*Laporan ini read-only. Implementasi apa pun atas §4.1 menunggu persetujuan dan akan dijalankan dengan gate regresi yang sama.*
