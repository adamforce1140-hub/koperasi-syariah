# TAHAP 15 — Simpanan Dinamis (Zero-Hardcoding) & Jatuh Tempo Simpanan Wajib

**Status:** ✅ SELESAI
**Tanggal:** 2026-10-01
**Tahap:** 15 (Simpanan Dinamis, Jatuh Tempo Simpanan Wajib, Penguncian Ekuitas)
**Dasar syariah & akuntansi:** PSAK 101 ¶ 31 & ¶ 45–47; PSAK 108; PSAK 71 (CKPN)
**Dokumen pendamping:** `docs/PANDUAN-SIMPANAN-WAJIB.md`,
`docs/TAHAP14-SIMPANAN-MODAL-KEPATUHAN.md`, `docs/TAHAP12-R3-SIKLUS-PENARIKAN.md`,
`docs/TAHAP13-RBAC-HIERARKI-ROLE.md`

**Khalayak:** Pengurus, Dewan Pengawas Syariah (DPS), auditor internal & eksternal, Tim TI.

---

## 1. Ringkasan Eksekutif

Tahap 15 menutup empat celah pada modul **Simpanan & Jatuh Tempo Simpanan Wajib**:

| # | Celah sebelum Tahap 15 | Penyelesaian Tahap 15 |
|---|---|---|
| 1 | Parameter produk simpanan (nominal, izin auto-debit, akun COA) tersebar & sebagian hardcode | Kolom DINAMIS pada `jenis_simpanans`: `code`, `name`, `min_deposit_amount`, `allow_auto_debit`, `account_coa_id` (FK ke `coas`) |
| 2 | Anggota baru tidak otomatis punya 3 rekening; dropdown Teller menampilkan "-- Pilih Anggota Dulu --" walau `?anggota_id=` terisi; status PENDING tidak naik ke ACTIVE | Auto-generate 3 rekening (Pokok/Wajib/Sukarela, saldo Rp 0) + auto-select Teller + `SetoranAwalService` mengaktivasi anggota |
| 3 | Tidak ada penjadwalan tagihan Simpanan Wajib bulanan; auto-debit bisa berjalan tanpa otorisasi produk | `ProcessWajibDuesJob` (tgl 1 tiap bulan) + dua jalur pembayaran (A manual / B auto-debit) ber-gate produk |
| 4 | Penarikan tunai bisa menyasar Simpanan Pokok/Wajib; refund ekuitas tidak terkontrol | Allowlist ketat Sukarela-only + pesan penolakan standar + refund eksklusif via `member_terminations` (NPF = 0) |

**Prinsip utama:** seluruh perilaku dapat diubah **tanpa mengubah kode inti** — hanya
lewat konfigurasi master produk & parameter koperasi, dengan jejak audit otomatis.

---

## 2. Pemetaan Nama Tabel (permintaan → sistem aktual)

Permintaan pengguna memakai penamaan umum; sistem ini memakai penamaan internal
berbahasa Indonesia. Pemetaan yang dipakai sepanjang dokumen ini:

| Nama pada spesifikasi | Nama tabel/model aktual |
|---|---|
| `saving_products` | `jenis_simpanans` (`App\Models\JenisSimpanan`) |
| `savings_accounts` | `rekening` (`App\Models\Rekening`) |
| `savings_transactions` | `transaksi` (`App\Models\Transaksi`) |
| `wajib_dues` | `mandatory_savings_bills` (`App\Models\MandatorySavingsBill`) |
| `members` | `anggota` (`App\Models\Anggota`) |
| `member_terminations` | modul pengunduran diri (`ExitKeanggotaanService`) |

---

## 3. BAGIAN 1 — Skema Database Produk Simpanan Dinamis

### 3.1 Migrasi

Berkas: `database/migrations/2026_10_03_000100_add_dynamic_product_columns_to_jenis_simpanans.php`

| Kolom | Tipe | Aturan | Keterangan |
|---|---|---|---|
| `code` | `string(20)` | **UNIQUE**, nullable | Nilai sah: `POKOK` \| `WAJIB` \| `SUKARELA` (dijaga konstanta `JenisSimpanan::CODE_DIIZINKAN`) |
| `name` | `string(100)` | nullable | Nama resmi produk (backfill dari kolom `nama` lama) |
| `min_deposit_amount` | `unsignedBigInteger` | default `0` | Nominal minimal setoran (rupiah bulat) |
| `allow_auto_debit` | `boolean` | default `false` | Izin auto-debit pindah buku tingkat produk |
| `account_coa_id` | `unsignedBigInteger` | nullable, **FK → `coas.id` `restrictOnDelete`** | Integritas referensial COA |

**Konfigurasi awal (backfill + seeder):**

| Produk | `code` | `min_deposit_amount` | `account_coa_id` | `allow_auto_debit` |
|---|---|---|---|---|
| Simpanan Pokok | `POKOK` | 100.000 | `3-1001` | `false` |
| Simpanan Wajib | `WAJIB` | 50.000 | `3-1002` | `false` |
| Simpanan Sukarela | `SUKARELA` | 0 | `2-1001` | `false` |

### 3.2 Model & Konstanta

`App\Models\JenisSimpanan`:

- Konstanta kode: `CODE_POKOK`, `CODE_WAJIB`, `CODE_SUKARELA`; allowlist `CODE_DIIZINKAN`.
- `CODE_BOLEH_AUTO_DEBIT = [CODE_WAJIB]` — auto-debit **hanya sah untuk produk Simpanan Wajib**.
- Helper resolusi (titik kebenaran tunggal):
  `semua()`, `cari()`, `coaKode()`, `cariKode()`, `kode()`, `minimalSetoran()`,
  `minimalSetoranWajib()`, `minimalSetoranPokok()`, `produkIzinkanAutoDebit()`,
  `bolehAutoDebit()`, `kodeBolehAutoDebit()`, `coaId()`.
- Cache produk di-flush (`static::flush()`) pada event `saved`/`deleted` agar perubahan konfigurasi langsung berlaku.

### 3.3 Validasi Otorisasi `allow_auto_debit`

Berkas: `app/Http/Controllers/JenisSimpananController.php`

Dua gerbang yang ditegakkan saat menyimpan produk:

1. **Peran berwenang** — hanya `manajer` (Manager) atau `pengurus` (Admin/otoritas tertinggi)
   yang boleh menyalakan `allow_auto_debit` (alias `manager`/`admin` ditoleransi).
2. **Kode produk** — nilai `true` hanya sah untuk produk berkode `WAJIB`
   (`JenisSimpanan::CODE_BOLEH_AUTO_DEBIT`).

Pelanggaran menghasilkan pesan validasi yang jelas dan menolak penyimpanan.

---

## 4. BAGIAN 2 — Auto-Generate Rekening & Sinkronisasi Teller

### 4.1 Auto-generate 3 rekening saat pendaftaran

Berkas: `app/Http/Controllers/CifController.php`

Saat anggota baru dibuat (status awal **selalu `PENDING`**), sistem langsung membuka
**3 rekening** dalam satu transaksi:

| Rekening | `jenis_rekening` | Saldo awal | COA (resolusi dinamis) |
|---|---|---|---|
| Simpanan Pokok | `Simpanan Pokok` | sesuai porsi setoran awal | dari `jenis_simpanans.account_coa_id` produk `POKOK` |
| Simpanan Wajib | `Simpanan Wajib` | sesuai porsi setoran awal | produk `WAJIB` |
| Simpanan Sukarela | `Simpanan Sukarela` | **Rp 0** | produk `SUKARELA` (2-1001) |

COA di-resolve via `coaProduk()` dari master produk (`account_coa_id`), **bukan hardcode**.
Rekening Sukarela bersaldo Rp 0 menjadi wadah setoran bebas sekaligus sumber dana auto-debit (Jalur B).

### 4.2 Auto-select di Teller Setor Tunai

Berkas: `app/Http/Controllers/TellerController.php` — `setorForm()`

- Bila URL memuat `?anggota_id={id}`, controller **langsung mengikat** anggota tersebut
  (selalu `prepend` agar tampil walau di luar populasi standar) dan merender **seluruh
  rekening aktif** anggota (Pokok, Wajib, Sukarela) **di server**.
- Dropdown Rekening **tidak pernah** menampilkan placeholder `-- Pilih Anggota Dulu --`
  ketika `?anggota_id=` sudah terisi.
- Bila ID tidak valid → redirect dengan pesan jelas.

Endpoint pendukung: `GET teller.rekening-by-anggota` (`getRekeningByAnggota()`).
Mode `setor` mengembalikan seluruh rekening; mode lain (penarikan) menerapkan allowlist Sukarela-only (lihat §6).

### 4.3 Aktivasi otomatis PENDING → ACTIVE

Berkas: `app/Services/SetoranAwalService.php` (di-wire pada `TellerController::setorStore()`)

- `periksa(Anggota)` memverifikasi **kedua** komponen setoran awal:
  rekening ada & aktif, saldo buku ≥ ambang, dan terdapat mutasi setoran (`posisi = Debet`)
  yang **sudah tertaut ke jurnal** (`jurnal.transaksi_id`).
- Ambang 100% dinamis: `jenis_simpanans.min_deposit_amount` (POKOK/WAJIB), fallback parameter koperasi.
- `aktivasiJikaLengkap(Anggota)` mengubah `anggota.status_anggota` → `ACTIVE` **hanya bila**
  `periksa()` menyatakan lengkap. Kegagalan aktivasi **tidak** membatalkan setoran yang sudah sah.

---

## 5. BAGIAN 3 — Jatuh Tempo Simpanan Wajib Bulanan

### 5.1 Penjadwalan (`ProcessWajibDuesJob`)

Berkas: `app/Console/Commands/ProcessWajibDuesJob.php`, didaftarkan di `routes/console.php`.

- Signature: `wajib-dues:process {--dry-run} {--date=}`.
- Jadwal: **`1 0 1 * *`** — tanggal 1 setiap bulan pukul 00:00.
- Untuk setiap anggota `ACTIVE`, menerbitkan tagihan (`mandatory_savings_bills`) berstatus
  **`UNPAID`** dengan nominal dari `jenis_simpanans.min_deposit_amount` produk `WAJIB`.
- **Idempoten** (`firstOrCreate` per `member_id` + `bill_period`) — pemanggilan ulang tidak
  menghasilkan tagihan ganda.
- Logging komprehensif: jumlah terbit / sudah ada / gagal per eksekusi.

### 5.2 Dua Jalur Pembayaran (pemilihan DINAMIS)

Berkas: `app/Services/MandatorySavingsService.php` — `processDaily()`, `autoDebit()`, `bayarViaTeller()`.

#### JALUR A — DEFAULT (Manual Teller)

Aktif bila `jenis_simpanans.allow_auto_debit == false` **ATAU** `anggota.is_auto_debit == false`.

- Tagihan tetap `UNPAID` → menjadi `OVERDUE` setelah melewati tanggal jatuh tempo.
- Pelunasan manual di loket via `bayarViaTeller()`, jurnal PSAK:
  - **DEBET** `1-1001` Kas Teller
  - **KREDIT** `3-1002` Simpanan Wajib (Modal Anggota)
- Menambah saldo laci fisik Teller (kas riil masuk).

#### JALUR B — OPT-IN (Auto-Debit Pindah Buku)

Aktif **hanya bila SEMUA** kondisi terpenuhi:

1. `jenis_simpanans.allow_auto_debit == true`
2. `anggota.is_auto_debit == true`
3. Rekening sumber Simpanan Sukarela (2-1001) tersedia & saldonya mencukupi.

**Saldo CUKUP** — seluruh proses dalam `DB::transaction()` (atomik):

- Catat **DEBIT** saldo Sukarela (pengurangan) pada `transaksi`.
- Catat **KREDIT** saldo Wajib (penambahan).
- Jurnal PSAK: **DEBET** `2-1001` Simpanan Sukarela | **KREDIT** `3-1002` Simpanan Wajib.
- Tagihan → `PAID` + `paid_at` + metode `AUTO_DEBIT` + keterangan
  **`Auto-debit dari Simpanan Sukarela`**.
- **DILARANG** menyentuh Kas Teller (1-1001) — transaksi non-tunai pindah buku internal.

**Saldo TIDAK CUKUP:**

- **Tidak ada** saldo yang dipotong di rekening mana pun.
- Tagihan → `OVERDUE`, `notified_at` diisi, notifikasi dikirim ke anggota
  (in-app/email/SMS) memuat periode, nominal, dan permintaan setor manual via Teller.

### 5.3 Gerbang otorisasi berlapis

`autoDebit()` **memeriksa ulang** `bolehAutoDebit()` sebelum memotong saldo — pertahanan
berlapis agar pemanggil langsung (seeder, command, job, test) tidak dapat memicu auto-debit
tanpa otorisasi produk **dan** anggota. Bila gagal, saldo **tidak** dipotong dan
`Log::warning` tercatat.

---

## 6. BAGIAN 4 — Penguncian Ekuitas (Pullout/Withdrawal Restriction)

### 6.1 Guard tunggal

Berkas: `app/Support/GuardSimpananModal.php`

- `JENIS_REKENING_DAPAT_DITARIK = ['Simpanan Sukarela']` — allowlist ketat.
- `KODE_AKUN_SUKARELA = Coa::SIMPANAN_SUKARELA` (`2-1001`).
- `pastikanRekeningDapatDitarikTunai()` / `rekeningDapatDitarikTunai()` — penegakan.
- **Pesan penolakan persis:**
  > `Penarikan tunai tidak diizinkan untuk rekening Simpanan Pokok/Wajib, silakan gunakan modul pengunduran diri anggota untuk proses pengembalian dana`

### 6.2 Penegakan di lapisan controller

- `TellerController::getRekeningByAnggota()` — mode penarikan hanya menampilkan Sukarela.
- `TellerController::tarikForm()` — populasi anggota hanya yang punya rekening Sukarela aktif.
- `TellerController::tarikStore()` — **GATE 1** `GuardSimpananModal::pastikanRekeningDapatDitarikTunai()`
  di dalam `try/catch` → pesan error ditampilkan tanpa mengubah saldo.
- Percobaan yang diblokir dicatat pada audit log: **`PENARIKAN_MODAL_DIBLOKIR`**.

### 6.3 Refund ekuitas eksklusif via pengunduran diri

Berkas: `app/Services/ExitKeanggotaanService.php`

- Pengembalian Simpanan Pokok/Wajib **hanya** melalui modul `member_terminations`.
- **Gerbang NPF = 0** — `npfAnggota()` + `pastikanNpfNol()` dipanggil di dalam `eksekusi()`
  tepat setelah `pastikanLayakExit()`. Refund ditolak bila masih ada pembiayaan berjalan.
- Audit trail lengkap: `EXIT_KEANGGOTAAN` (+ `total_dana_dikembalikan`) dan
  `EXIT_KEANGGOTAAN_DIBLOKIR` saat gate menolak.

---

## 7. Berkas yang Diubah / Ditambah

| Berkas | Perubahan |
|---|---|
| `database/migrations/2026_10_03_000100_add_dynamic_product_columns_to_jenis_simpanans.php` | **Baru** — kolom dinamis + FK COA + backfill |
| `database/seeders/JenisSimpananSeeder.php` | Seeder kolom `code`, `min_deposit_amount`, `allow_auto_debit`, `account_coa_id` |
| `app/Models/JenisSimpanan.php` | Konstanta kode + helper resolusi dinamis |
| `app/Http/Controllers/JenisSimpananController.php` | Validasi otorisasi `allow_auto_debit` (peran + kode produk) |
| `app/Http/Controllers/CifController.php` | Auto-generate 3 rekening (COA dinamis) |
| `app/Http/Controllers/TellerController.php` | Auto-select anggota/rekening; aktivasi; allowlist penarikan |
| `app/Services/SetoranAwalService.php` | **Baru** — verifikasi setoran awal & aktivasi PENDING→ACTIVE |
| `app/Console/Commands/ProcessWajibDuesJob.php` | **Baru** — job tagihan bulanan |
| `app/Services/MandatorySavingsService.php` | Dua jalur pembayaran + gerbang otorisasi + keterangan auto-debit |
| `app/Support/GuardSimpananModal.php` | Guard allowlist Sukarela-only + pesan standar |
| `app/Services/ExitKeanggotaanService.php` | Gate NPF = 0 + audit trail |
| `routes/console.php` | Penjadwalan `1 0 1 * *` |
| `tests/Feature/MandatorySavingsSimpananWajibTest.php` | 12 test (Bagian 3) |
| `tests/Feature/ProcessWajibDuesJobTest.php` | **Baru** — 7 test (Bagian 3) |
| `tests/Feature/PenguncianEkuitasPenarikanTest.php` | **Baru** — 11 test (Bagian 4) |

---

## 8. Hasil Pengujian

| Berkas test | Fokus | Hasil |
|---|---|---|
| `MandatorySavingsSimpananWajibTest` | Jalur A/B, jurnal PSAK, overdue, notifikasi, dry-run, anti-sentuh Kas Teller | **12 passed** |
| `ProcessWajibDuesJobTest` | Penerbitan tagihan, nominal dinamis, idempotensi, scheduler | **7 passed** |
| `PenguncianEkuitasPenarikanTest` | Pesan persis, allowlist, guard, NPF, audit | **11 passed** |
| `TellerSimpananWajibTest` | Daftar tagihan teller, pelunasan tunai, idempotensi | **6 passed** |
| `JenisSimpananParameterTest` | Parameterisasi produk (kode/COA/auto-debit) | **26 passed** |

**Regresi penuh (seluruh suite):**

```
Tests:    1226 passed (5541 assertions)
Duration: 177.21s
```

> Catatan regresi: dua kegagalan sempat muncul dan telah diperbaiki —
> (1) `TellerSimpananWajibTest` bersifat *flaky* karena `paginate(25)` +
> `orderByDesc('bill_period')`; periode tagihan uji diubah ke `2099-02` agar
> deterministik. (2) `JenisSimpananParameterTest` gagal karena kode produk
> diturunkan dari `tipe`, sehingga produk turunan (mis. "Simpanan Hari Raya",
> bertipe `sukarela`) berebut kode `SUKARELA` dengan produk baku. Penurunan kode
> kini memakai **nama resmi produk baku** (`kodeDariNama()`); produk turunan
> tersimpan dengan `code` **NULL** sehingga aman berdampingan dengan kode baku
> (di MySQL perbandingan `NULL = 'SUKARELA'` bernilai UNKNOWN, jadi tak pernah
> memicu bentrok palsu).

Verifikasi kunci pada Jalur B:

- Jurnal hanya memuat baris `2-1001` (debet) & `3-1002` (kredit); **tidak ada** baris `1-1001`.
- Saldo Simpanan Sukarela berkurang tepat sebesar nominal tagihan.
- Tagihan berstatus `PAID` dengan keterangan memuat `Auto-debit dari Simpanan Sukarela`.
- Saldo tidak cukup → tidak ada potongan; status `OVERDUE`; `notified_at` terisi;
  audit log `SIMPANAN_WAJIB_OVERDUE` tercatat.

---

## 9. Catatan Kepatuhan

- **PSAK 101 ¶ 31** — Simpanan Pokok (3-1001) & Simpanan Wajib (3-1002) disajikan sebagai
  **MODAL/EKUITAS**; Simpanan Sukarela (2-1001) sebagai **KEWAJIBAN** (titipan). Karena itu
  penarikan rutin ekuitas dilarang dan auto-debit dipindahbukukan antar akun internal
  (bukan kas).
- **PSAK 108** — Pengembalian hak anggota saat berhenti hanya melalui modul pengunduran diri,
  dan hanya setelah seluruh kewajiban (pembiayaan) selesai (NPF = 0).
- **Jejak audit** — setiap penerbitan, pelunasan, penolakan penarikan, dan refund tercatat
  pada `audit_logs` serta `Log`.
