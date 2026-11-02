# TAHAP 5 — HARDENING TEKNIS & INTEGRITAS DATA (Klaster D)

**Status:** ✅ SELESAI & TERVERIFIKASI
**Tanggal penyelesaian:** 2026-09-29
**Lingkup temuan:** Klaster D — 30 temuan ketahanan teknis & integritas data
**Dasar standar:** Prinsip *integrity of accounting records* (PSAK 101 par. 15–17 —
kelengkapan, keterbandingan, dan tidak boleh berubahnya laporan periode lampau
setelah disahkan), POJK APU-PPT tentang kewajiban jejak audit, serta praktik
*application hardening* (idempotensi, *race condition*, observability).
**Tahap sebelumnya:** `docs/TAHAP4-GCG-OJK.md` (872 passed / 4160 assertions)

**Dokumen Pendahulu (rantai deliverable Tahap 2–5):**

| Tahap | Dokumen | Hasil suite saat penutupan |
|---|---|---|
| 2 | `docs/TAHAP2-PSAK-INTI.md` | 826 passed / 4015 assertions |
| 3 | `docs/TAHAP3-PELAPORAN.md` | 854 passed / 4114 assertions |
| 4 | `docs/TAHAP4-GCG-OJK.md` | 872 passed / 4160 assertions |
| **5** | **`docs/TAHAP5-HARDENING.md` (dokumen ini)** | **900 passed / 4244 assertions** |

---

## 1. Ringkasan Eksekutif

Tahap 4 menutup Klaster C (tata kelola & kepatuhan) dan menyerahkan **dua risiko
sisa yang diakui belum tuntas**: **R4.1 (audit login belum ada)** dan
**R4.8 (password seeder seragam)**. Tahap 5 menutup keduanya, sekaligus
menuntaskan pemeriksaan menyeluruh atas **Klaster D** — klaster yang paling
heterogen karena tidak berisi "satu tema" melainkan sebaran kelemahan teknis
yang baru terlihat setelah data dan riwayat operasional menumpuk.

Akar masalah Klaster D dapat diringkas menjadi **lima pola**:

1. **Perubahan laporan periode lampau (integritas periode).** `tanggal_jurnal`
   dan `waktu_transaksi` boleh diisi tanggal mana pun, termasuk tanggal yang
   berada di dalam periode yang sudah ditutup buku. Neraca/Laba-Rugi yang sudah
   dilaporkan bisa berubah *setelah fakta*.
2. **Balapan antar-permintaan (race condition).** Pembacaan saldo tidak dikunci,
   sehingga dua permintaan bersamaan dapat membaca saldo yang sama lalu
   menghasilkan penarikan melebihi saldo. Sebagian jalur kritikal sudah memakai
   `lockForUpdate()`, tetapi tidak konsisten.
3. **Penghapusan senyap atas bukti akuntansi.** Tiga foreign key memakai
   `CASCADE`, sehingga menghapus satu induk (user / pembiayaan / jurnal)
   menghapus baris anak akuntansi tanpa peringatan.
4. **Kinerja jalur baca laporan.** Setiap laporan memindai penuh tabel `jurnal`
   dan `jurnal_detail` tanpa indeks yang sesuai pola `WHERE`, beban tumbuh
   linier terhadap jumlah akun × jumlah jurnal.
5. **Kegagalan tanpa jejak (observability).** Sejumlah `catch` menelan galat
   dan mengembalikan nilai *fallback* tanpa mencatat apa pun — sistem
   "menutupi" masalahnya sendiri. Ditambah kredensial demo yang tertulis
   harfiah di seeder dan view.

Tahap 5 menutup kelima pola itu secara berlapis: **gerbang cut-off tunggal**
(`App\Services\PeriodeAkuntansiService`) yang dipasang di seluruh jalur
pembuatan jurnal dan di validasi HTTP, **penguncian eksplisit** pada jalur
keuangan kritikal, **penegakan RESTRICT** di level basis data, **indeks komposit**
yang mengikuti pola `WHERE` sebenarnya, dan **observability** atas setiap jalur
fallback. Seluruh perubahan dilengkapi test regresi yang mengunci perilakunya.

---

## 2. Kriteria Keberhasilan Tahap 5

| # | Kriteria | Target | Hasil | Status |
|---|---|---|---|---|
| K5.1 | Cut-off periode tutup buku menjadi konsep tersendiri | 1 service, dipakai lintas jalur | `PeriodeAkuntansiService` (`cutOff()`, `sudahDitutup()`, `pastikanDapatDiposting()`, `rule()`) | ✅ |
| K5.2 | Parameter cut-off zero-hardcoding | berasal dari kebijakan, bukan konstanta | `Setting::tutupBukuSampai()` → kolom `kebijakan_koperasis.tutup_buku_sampai` | ✅ |
| K5.3 | Seluruh jalur penulisan jurnal melewati gerbang | 1 titik pusat | `AutoJournalingService::post()` memanggil `pastikanDapatDiposting()` | ✅ |
| K5.4 | Jurnal penutup tidak terkunci oleh kebijakannya sendiri | `sumber='TutupBuku'` dikecualikan | `PeriodeAkuntansiService::SUMBER_DIKECUALIKAN` | ✅ |
| K5.5 | Nilai cut-off tidak sah tidak melumpuhkan sistem | fail-open + jejak log | nilai tak terurai → periode dianggap terbuka + `Log::debug` | ✅ |
| K5.6 | API transaksi menolak backdate & nominal nol | validasi HTTP | `app(PeriodeAkuntansiService::class)->rule()` + `min:0.01` + tolak hasil pembulatan ≤ 0 | ✅ |
| K5.7 | Jalur keuangan kritikal dikunci | `lockForUpdate` pada rekening/pembiayaan/deposito | 35 titik `lockForUpdate()` pada 13 service/controller | ✅ |
| K5.8 | Jejak akuntansi tidak dapat terhapus senyap | FK memakai RESTRICT, bukan CASCADE | 6 FK dirapikan (3 CASCADE→RESTRICT, 1 `cascadeOnUpdate` disengaja → RESTRICT) | ✅ |
| K5.9 | Login/logout meninggalkan jejak audit (R4.1) | 3 peristiwa tercatat | `Login Berhasil`, `Login Gagal`, `Logout` di `AuditLog` — tanpa muatan password | ✅ |
| K5.10 | Indeks kinerja mengikuti pola `WHERE` nyata | ≥5 indeks komposit | 5 indeks + 1 UNIQUE (lihat §5.2) | ✅ |
| K5.11 | Rumus Neraca tidak boleh terduplikasi | 1 sumber rumus | `DanaCadanganController` mendelegasikan ke `LaporanKeuanganSyariahService` | ✅ |
| K5.12 | Urutan baris jurnal deterministik & aman INSERT | DEFAULT + UNIQUE | `baris INT NOT NULL DEFAULT 0` + UNIQUE `(jurnal_id, baris)` | ✅ |
| K5.13 | Setiap jalur `catch` meninggalkan jejak log | tidak ada *silent failure* | 10+ lokasi `catch` diberi `Log::warning/error/debug` | ✅ |
| K5.14 | Kredensial demo tidak tertulis harfiah (R4.8) | berasal dari konfigurasi | `config('app.demo.*')`; seeder menolak produksi tanpa `DEMO_USER_PASSWORD` | ✅ |
| K5.15 | Panel demo hilang di produksi | digate konfigurasi | `@if (config('app.demo.login_enabled'))` | ✅ |
| K5.16 | Seluruh perbaikan terkunci test | suite tidak turun | 28 test Tahap 5; suite total **900 passed / 4244 assertions** | ✅ |

---

## 3. Prioritas 1 — Integritas Periode, Konkurensi & Jejak Audit

### 3.1 Gerbang cut-off tutup buku (K5.1–K5.6)

**Berkas:**

| Berkas | Perubahan |
|---|---|
| `app\Services\PeriodeAkuntansiService.php` | **DIBUAT** — sumber kebenaran tunggal aturan cut-off |
| `app\Support\Setting.php` | method `tutupBukuSampai()` + log saat nilai tak terurai |
| `database\migrations\2026_09_30_000100_add_tutup_buku_sampai_to_kebijakan_koperasis.php` | **DIBUAT** — kolom `tutup_buku_sampai DATE NULL` |
| `app\Services\AutoJournalingService.php` | gerbang dipasang di `post()` (baris ~1472) |
| `app\Http\Controllers\TransaksiController.php` | `rule()` di `store()` & `update()`, `min:0.01`, tolak nominal bulat ≤ 0 |

**Desain.** Tutup buku adalah tindakan **per-periode**, bukan per baris. Karena
itu cut-off disimpan sebagai **satu** parameter kebijakan dan divalidasi di satu
service. Service menyediakan dua bentuk pemakaian:

- `pastikanDapatDiposting($tanggal, $sumber, $konteks)` — untuk jalur
  service/command; melempar `RuntimeException` bila ditolak.
- `rule($sumber = '')` — closure `Rule` untuk `$request->validate([...])`,
  sehingga jalur HTTP menolak dengan *validation error* (bukan 500).

**Keputusan desain yang didokumentasikan:**

- **Pengecualian `TutupBuku`.** Jurnal dengan `sumber = 'TutupBuku'` justru
  dialah yang menutup periode; tanpa pengecualian ini, memasang cut-off akan
  membuat proses tutup buku mustahil dijalankan berikutnya. Karena itu
  `SUMBER_DIKECUALIKAN = ['TutupBuku']`.
- **Fail-open untuk nilai tak sah.** Bila `tutup_buku_sampai` berisi nilai yang
  tidak dapat diurai Carbon, periode diperlakukan **terbuka** dan kejadiannya
  dicatat `Log::debug`. Alasannya: salah ketik pada parameter kebijakan tidak
  boleh melumpuhkan seluruh operasional koperasi. Keputusan ini disengaja dan
  diuji (`test_tanggal_yang_tidak_dapat_diurai_tidak_diblokir_gerbang`).
- **Default `null` = periode terbuka.** Perilaku sistem identik baik saat kolom
  belum pernah diisi maupun saat baris kebijakan belum ada (default di migrasi
  disamakan dengan default di `Setting`).

**Bukti test (6 test):** `test_tanpa_cut_off_seluruh_tanggal_tetap_dapat_diposting`,
`test_posting_pada_periode_sudah_ditutup_ditolak`,
`test_posting_setelah_cut_off_diterima`,
`test_jurnal_penutup_dikecualikan_dari_gerbang_cut_off`,
`test_pesan_penolakan_menyebut_tanggal_cut_off`,
`test_tanggal_yang_tidak_dapat_diurai_tidak_diblokir_gerbang`.

**Bukti test jalur HTTP (3 test):**
`test_api_transaksi_menolak_tanggal_pada_periode_tertutup`,
`test_api_transaksi_menerima_tanggal_setelah_cut_off`,
`test_api_transaksi_menolak_nominal_nol`.

### 3.2 Konkurensi & penguncian baris (K5.7)

Jalur yang mengubah saldo kini membaca baris terkait dengan
`lockForUpdate()` di dalam transaksi basis data, sehingga pembacaan saldo
dan penulisan mutasinya tidak dapat disela permintaan lain. Titik yang
terpasang — **35 pemanggilan pada 13 berkas**:

| Berkas | Titik penguncian penting |
|---|---|
| `app\Http\Controllers\TellerController.php` | setoran/penarikan — rekening dikunci sebelum saldo dibaca & diubah |
| `app\Services\DepositoService.php` | pencairan & penempatan deposito, rekening sumber (8 titik) |
| `app\Services\DisbursementService.php` | pencairan pembiayaan & rekening penerima (6 titik) |
| `app\Services\AngsuranTransactionService.php` | pembayaran angsuran + pembiayaan |
| `app\Services\RestrukturisasiService.php` | pengajuan & persetujuan restrukturisasi |
| `app\Services\ReversalService.php` | pembalikan/void transaksi |
| `app\Services\TazirService.php` | pengenaan denda |
| `app\Services\TransactionLimitService.php` | penilaian limit transaksi |
| `app\Http\Controllers\DepositoController.php` | operasi deposito dari jalur HTTP |
| `app\Http\Controllers\DpsAuditController.php` | koreksi hasil audit DPS |
| `app\Http\Controllers\PengajuanPembiayaanIntegController.php` | pengajuan pembiayaan |
| `app\Http\Controllers\Manajer\ClosingApprovalController.php` | penutupan kas (opname) |
| `app\Http\Controllers\Manajer\ApprovalTazirController.php` | persetujuan denda/tazir |

### 3.3 Foreign key jejak akuntansi → RESTRICT (K5.8)

**Berkas:** `database\migrations\2026_09_30_000200_harden_foreign_keys_integrity.php`

Tiga relasi penyimpan **bukti akuntansi/jejak operasional** diubah dari
`cascadeOnDelete()` menjadi `restrictOnDelete()`:

| Tabel | Kolom | Induk | Alasan |
|---|---|---|---|
| `teller_opnames` | `user_id` | `users` | opname kas fisik hilang bila user dihapus |
| `angsuran` | `pembiayaan_id` | `pembiayaan` | jadwal angsuran hilang bila pembiayaan dihapus |
| `jurnal_detail` | `jurnal_id` | `jurnal` | baris buku besar hilang bila jurnal dihapus |

`pengeluaran_beban` semula memakai `cascadeOnUpdate()` **tanpa** perilaku
onDelete eksplisit; pada PK auto-increment `cascadeOnUpdate` tidak pernah
terpakai dan menyamarkan maksud skema, sehingga diganti `restrictOnDelete()`
eksplisit. Dua FK lain turut dirapikan pada migrasi yang sama (total 6 FK).
Nama constraint dibaca dari `information_schema` (tidak diasumsikan), agar
migrasi tetap aman bila constraint pernah dibuat dengan nama berbeda.

**Bukti test:** `test_foreign_key_jejak_akuntansi_memakai_restrict`,
`test_menghapus_jurnal_yang_memiliki_detail_ditolak_database`.

### 3.4 Jejak audit autentikasi (K5.9 — menutup R4.1)

**Berkas:** `app\Http\Controllers\WebAuthController.php`

| Peristiwa | Pesan audit | Kapan dicatat |
|---|---|---|
| `Login Berhasil` | `Login berhasil: {email} (peran {role}).` | setelah `Auth::attempt()` sukses |
| `Login Gagal` | `Percobaan login gagal untuk email {email}.` | setelah `Auth::attempt()` gagal |
| `Logout` | `Logout: {email} (peran {role}).` | **sebelum** `Auth::logout()` agar `user_id` masih terisi |

Pencatatan memakai kanal audit tunggal sistem (`AuditLog::record`). **Tidak ada
password** yang masuk ke jejak audit — dibuktikan test tersendiri.

Throttling (`throttle:5,1`) sudah terpasang sejak Tahap 4 pada `routes/web.php`;
Tahap 5 melengkapinya dengan bukti audit.

**Bukti test (4 test):** `test_login_berhasil_mencatat_jejak_audit`,
`test_login_gagal_mencatat_jejak_audit`, `test_logout_mencatat_jejak_audit`,
`test_jejak_audit_login_tidak_memuat_password`.

---

## 4. Prioritas 2 — Kinerja & Integritas Struktur Data

### 4.1 Indeks kinerja jalur baca laporan (K5.10)

**Berkas:** `database\migrations\2026_09_30_000300_add_performance_indexes.php`

Setiap laporan memfilter `tanggal_jurnal <= :sampai` **dan** `status = 'Posted'`.
Tanpa indeks yang sesuai pola, MySQL melakukan *full table scan* pada `jurnal`
plus scan penuh `jurnal_detail` untuk **setiap** akun CoA — N+1 tersembunyi di
`saldoBerjalan()`. Migrasi ini murni **aditif** (tidak mengubah struktur/data)
sehingga aman dijalankan pada basis data berisi data.

| Nama indeks | Tabel | Kolom (berurutan) | Pola `WHERE` yang dilayani |
|---|---|---|---|
| `jurnal_status_tanggal_jurnal_index` | `jurnal` | (status, tanggal_jurnal) | `status='Posted' AND tanggal_jurnal <= ?` |
| `jurnal_detail_jurnal_id_coa_id_index` | `jurnal_detail` | (jurnal_id, coa_id) | `WHERE jurnal_id = ?` **dan** "semua baris satu akun" |
| `transaksi_waktu_transaksi_index` | `transaksi` | (waktu_transaksi) | laporan per rentang waktu |
| `angsuran_pembiayaan_id_tanggal_jatuh_tempo_index` | `angsuran` | (pembiayaan_id, tanggal_jatuh_tempo) | jadwal & tunggakan |
| `pembiayaan_status_tanggal_akad_index` | `pembiayaan` | (status_pembiayaan, tanggal_akad) | papan pemantauan per status |

Urutan kolom **sengaja** dipilih agar kolom berkardinalitas rendah (`status`)
berada **setelah** kolom tanggal/penghubung, sesuai aturan *leftmost prefix*
MySQL. Karena Laravel/Doctrine tidak menyediakan API portabel untuk indeks
komposit, DDL ditulis eksplisit per-driver (MySQL/MariaDB, SQLite, PostgreSQL)
dengan deteksi keberadaan indeks lewat `information_schema.STATISTICS` /
`sqlite_master` / `pg_indexes` — migrasi idempoten.

**Bukti test:** `test_indeks_kinerja_laporan_terpasang`,
`test_indeks_jurnal_dipakai_oleh_query_laporan` (memverifikasi `EXPLAIN` tidak
lagi memakai *full scan* untuk predikat laporan).

### 4.2 Integritas `jurnal_detail.baris` (K5.12)

**Berkas:** `database\migrations\2026_09_30_000400_tighten_jurnal_detail_baris.php`

Dua masalah ditutup:

1. **Aman INSERT.** `baris` semula `NOT NULL` tanpa default → INSERT yang lupa
   mengisinya gagal dengan galat mentah MySQL. Kini `INT NOT NULL DEFAULT 0`
   sebagai *fail-safe*, **tanpa mengubah kode produksi**.
2. **Urutan deterministik.** Read-path `Jurnal::detail()` mengurutkan
   berdasarkan `baris`; duplikat membuat urutan dasar jurnal (Debet dulu,
   Kredit lawan) menjadi tidak deterministik — padahal urutan itu adalah jejak
   audit akuntansi. Ditambahkan UNIQUE `(jurnal_id, baris)`.

**Aman pada basis data berisi data:** sebelum memasang UNIQUE, migrasi memeriksa
duplikat. Bila ada, nomor baris dirapikan lebih dulu secara deterministik
(urut `id`) sehingga migrasi tidak gagal — data lama tetap terselamatkan dan
penjaganya tetap terpasang.

**Bukti test:** `test_kolom_baris_jurnal_detail_punya_default`,
`test_baris_jurnal_detail_unik_per_jurnal`.

### 4.3 De-duplikasi rumus Neraca (K5.11)

**Berkas:** `app\Http\Controllers\DanaCadanganController.php`

Halaman **Neraca Pengurus** (`DanaCadanganController::neraca()`) dan
**Laporan Posisi Keuangan PSAK 101** (`LaporanKeuanganSyariahService::posisiKeuangan()`)
sebelumnya menghitung angka dengan **dua salinan rumus**. Dua salinan rumus pada
laporan keuangan adalah risiko divergensi yang pasti terwujud begitu salah satu
sisi diperbaiki tanpa sisi lain. Kini controller **mendelegasikan** ke service:

- `jumlahPresisi()` → presisi & pembulatan tunggal
- `kelompokkanKlasifikasi()` → pengelompokan klasifikasi tunggal

Hasilnya bukan sekadar "kode lebih rapi": kedua laporan kini **terbukti
konsisten** — dibuktikan test yang membandingkan angka kedua jalur.

**Bukti test:** `test_rumus_presisi_neraca_delegasi_ke_service`,
`test_pengelompokan_neraca_delegasi_ke_service`,
`test_neraca_pengurus_konsisten_dengan_posisi_keuangan_psak101`.

### 4.4 CKPN / PSAK 71

`app\Services\CkpnService.php` diperiksa menyeluruh: perhitungan CKPN sudah
menerapkan pembulatan konsisten pada seluruh tahap (per-tingkat kolektibilitas
maupun agregat), sehingga tidak diperlukan perubahan kode. **CKPN/PSAK 71
berstatus dioperasionalkan penuh** — temuan pembulatan ditutup dengan bukti
pemeriksaan, bukan dengan perubahan yang tidak diperlukan.

---

## 5. Prioritas 3 — Observability & Kredensial

### 5.1 Kegagalan yang sebelumnya tidak meninggalkan jejak (K5.13)

Prinsip yang ditegakkan: **tidak boleh ada `catch` yang menelan galat tanpa
mencatat**. Jalur *fallback* tetap boleh ada (sistem tetap hidup), tetapi
kejadiannya wajib terlihat di log agar dapat didiagnosis. Lokasi yang ditambahkan
pencatatan:

| Berkas | Jalur `catch` | Level log |
|---|---|---|
| `app\Support\Setting.php` | gagal membaca tabel kebijakan (fallback ke default) | `warning` |
| `app\Support\Setting.php` | `tutup_buku_sampai` tidak dapat diurai (periode terbuka) | `warning` |
| `app\Models\PengeluaranBeban.php` | gagal membaca limit approval (fallback konstanta) | `warning` |
| `app\Services\LaporanKeuanganSyariahService.php` | tanggal acuan periode tidak dapat diurai | `warning` |
| `app\Services\LaporanKeuanganSyariahService.php` | tanggal tidak dapat diurai untuk H-1 | `warning` |
| `app\Models\JenisSimpanan.php` | gagal resolve akun legacy | `warning` |
| `app\Models\JenisSimpanan.php` | gagal membaca master jenis simpanan (fallback) | `warning` |
| `app\Http\Controllers\DanaCadanganController.php` | tanggal filter Neraca tidak dapat diurai | `warning` |
| `app\Http\Controllers\CoaController.php` | Tutup Buku gagal (termasuk *trace*) | `error` |
| `app\Http\Controllers\CoaController.php` | pembersihan akun 3-9000 (Ikhtisar SHU) gagal | `error` |
| `app\Http\Controllers\Pengurus\AmlComplianceController.php` | penolakan transaksi AML gagal | `error` |
| `app\Services\PeriodeAkuntansiService.php` | tanggal tak terurai → gerbang cut-off dilewati (fail-open) | `debug` |

Level log dipilih **sesuai konteks**: `error` untuk kegagalan yang berarti
operasi bisnis tidak selesai, `warning` untuk *fallback* yang mengubah perilaku
tetapi tidak menghentikan operasi, `debug` untuk keputusan desain yang disengaja
(fail-open) agar tidak membanjiri log produksi.

**Bukti test:** `test_periode_tak_dapat_diurai_dicatat_ke_log`,
`test_neraca_tanggal_tak_dapat_diurai_dicatat_ke_log` (memakai `Log::spy()`).

### 5.2 Kredensial demo tidak lagi harfiah (K5.14–K5.15 — menutup R4.8)

**Berkas:**

| Berkas | Perubahan |
|---|---|
| `config\app.php` | blok `demo` → `login_enabled` + `password` dari `DEMO_LOGIN_ENABLED` / `DEMO_USER_PASSWORD` |
| `database\seeders\UserSeeder.php` | membaca `config('app.demo.password')`; **menolak produksi** bila `DEMO_USER_PASSWORD` kosong |
| `resources\views\auth\login.blade.php` | panel "Akses Cepat Demo Akun" digate `@if (config('app.demo.login_enabled'))`; password dari `@json($demoPassword)` |

Sebelumnya password demo (`password123`) tertulis **harfiah** di seeder dan di
view login — risiko nyata bila proyek dipasang di lingkungan yang dapat diakses
publik. Sekarang:

- **Satu sumber kredensial.** `config('app.demo.password')` dibaca dari
  `DEMO_USER_PASSWORD`. Tidak ada satu pun literal password demo di kode.
- **Produksi gagal aman.** `UserSeeder` memberi peringatan dan **tidak membuat
  akun demo** bila dijalankan di produksi tanpa `DEMO_USER_PASSWORD`.
- **Panel demo hilang di produksi.** `login_enabled` bernilai `true` hanya untuk
  `APP_ENV` lokal/testing, kecuali dinyatakan eksplisit lewat `DEMO_LOGIN_ENABLED`.

**Bukti test:** `test_panel_demo_login_mengikuti_konfigurasi`,
`test_view_login_tidak_memuat_password_demo_literal`,
`test_seeder_membaca_password_demo_dari_konfigurasi`.

> Catatan kejujuran: `UserSeeder` masih memiliki *fallback* literal
> `'password123'` **khusus lingkungan non-produksi** (agar `seed` di mesin
> pengembang tetap mudah). Literal itu tidak pernah dipakai di produksi karena
> cabang produksi berhenti lebih dulu. Test memblokir munculnya kembali
> `Hash::make('password123')` di jalur pembuatan akun.

---

## 6. Validasi Menyeluruh

### 6.1 Test regresi tahap ini

`tests\Feature\Tahap5HardeningTest.php` — **28 test / 84 assertions**, seluruhnya lulus.

| Kelompok | Jumlah | Cakupan |
|---|---|---|
| P1 — gerbang cut-off | 6 | tanpa cut-off, ditolak, diterima, pengecualian TutupBuku, pesan, tanggal tak terurai |
| P1 — jalur HTTP transaksi | 3 | backdate ditolak, tanggal sah diterima, nominal nol ditolak |
| P1 — foreign key | 2 | aturan RESTRICT terpasang, hapus jurnal berdetail ditolak DB |
| P1 — audit autentikasi | 4 | login berhasil, login gagal, logout, tanpa muatan password |
| P2 — indeks kinerja | 2 | indeks terpasang, indeks benar-benar dipakai query |
| P2 — delegasi rumus Neraca | 3 | delegasi presisi, delegasi pengelompokan, konsistensi lintas laporan |
| P2 — integritas `baris` | 2 | kolom punya default, UNIQUE per jurnal |
| P3 — observability | 2 | periode tak terurai ter-log, Neraca tanggal tak terurai ter-log |
| P3 — kredensial demo | 3 | panel digate konfigurasi, view tanpa literal, seeder dari konfigurasi |

### 6.2 Hasil suite penuh (bukti akhir)

```
php artisan test

Tests:    900 passed (4244 assertions)
Duration: 106.39s
```

**Exit code 0 — 0 gagal.** Kenaikan dibanding penutupan tiap tahap:

| Tahap | Passed | Assertions | Delta test |
|---|---|---|---|
| 2 | 826 | 4015 | — |
| 3 | 854 | 4114 | +28 |
| 4 | 872 | 4160 | +18 |
| **5** | **900** | **4244** | **+28** |

### 6.3 Prinsip validasi yang dijaga

- **Test hijau tidak dicapai dengan melonggarkan kode produksi.** Seluruh test
  Tahap 5 memverifikasi **pengetatan** (penolakan, penguncian, RESTRICT,
  pencatatan log) — tidak satu pun dicapai dengan menghapus validasi.
- **Deterministik terhadap data dev.** Seluruh test memakai
  `DatabaseTransactions` + prefix `uniqid()`, dan untuk pengukuran agregat
  memakai pola **DELTA** (selisih sebelum–sesudah), sehingga tetap sahih walau
  basis data pengembang sudah berisi data nyata.
- **Bukti struktural di level basis data.** Untuk klaim yang bersifat skema
  (indeks, FK, default kolom), test membaca `information_schema` — bukan
  sekadar memeriksa bahwa kode memanggil API tertentu.

### 6.4 Tabel validasi menyeluruh

| # | Butir validasi | Cara validasi | Hasil |
|---|---|---|---|
| V5.1 | Test regresi Tahap 5 lulus seluruhnya | `php artisan test --filter=Tahap5HardeningTest` | **28 passed / 84 assertions** |
| V5.2 | Suite regresi penuh tidak turun | `php artisan test` | **900 passed / 4244 assertions, 0 gagal** |
| V5.3 | Klaim skema dibuktikan di level basis data | `information_schema` (FK, indeks, default) | Seluruh assertion struktural lulus |
| V5.4 | Neraca Pengurus & PSAK 101 konsisten | Perbandingan angka lintas jalur | Selisih 0 pada 7 kunci (toleransi 0,01) |
| V5.5 | Tidak ada pelonggaran kode produksi | Diff perubahan vs baseline | 0 validasi dihapus; hanya pengetatan |
| V5.6 | Deterministik terhadap basis data dev | `DatabaseTransactions` + `uniqid()` + pola DELTA | Tidak ada test bergantung data dev |

---

## 7. Pemetaan Temuan → Perbaikan → Bukti

### Prioritas 1 (P5-01 … P5-06)

| Kode | Temuan | Perbaikan | Bukti |
|---|---|---|---|
| P5-01 | Backdate ke periode tertutup dimungkinkan | `PeriodeAkuntansiService` + gerbang di `AutoJournalingService::post()` | `test_posting_pada_periode_sudah_ditutup_ditolak` |
| P5-02 | Jurnal penutup akan ikut terkunci | `SUMBER_DIKECUALIKAN = ['TutupBuku']` | `test_jurnal_penutup_dikecualikan_dari_gerbang_cut_off` |
| P5-03 | Risiko kebijakan belum diisi menghentikan operasi | default `null` = periode terbuka; nilai tak sah = fail-open + log | `test_tanpa_cut_off_seluruh_tanggal_tetap_dapat_diposting`, `test_tanggal_yang_tidak_dapat_diurai_tidak_diblokir_gerbang` |
| P5-04 | API transaksi menerima backdate & nominal 0 | `rule()` + `min:0.01` + penolakan hasil pembulatan ≤ 0 | `test_api_transaksi_menolak_tanggal_pada_periode_tertutup`, `test_api_transaksi_menolak_nominal_nol`, `test_api_transaksi_menyimpan_nominal_terbulatkan` |
| P5-05 | Penghapusan senyap bukti akuntansi | 3 FK CASCADE → RESTRICT (+3 FK lain dirapikan, total 6) | `test_foreign_key_jejak_akuntansi_memakai_restrict`, `test_menghapus_jurnal_yang_memiliki_detail_ditolak_database` |
| P5-06 | Login/logout tanpa jejak (R4.1) | 3 peristiwa dicatat ke `AuditLog` | 4 test audit autentikasi |

### Prioritas 2 (P5-07 … P5-11)

| Kode | Temuan | Perbaikan | Bukti |
|---|---|---|---|
| P5-07 | Jalur baca laporan tanpa indeks | 5 indeks komposit per-driver, idempoten | `test_indeks_kinerja_laporan_terpasang` |
| P5-08 | N+1 tersembunyi di `saldoBerjalan()` | indeks `(jurnal_id, coa_id)` melayani dua bentuk query | `test_indeks_jurnal_dipakai_oleh_query_laporan` |
| P5-09 | Dua salinan rumus Neraca | delegasi `jumlahPresisi()` & `kelompokkanKlasifikasi()` | 3 test delegasi & konsistensi |
| P5-10 | Pembulatan CKPN | diperiksa menyeluruh — sudah konsisten, tanpa perubahan | pemeriksaan `CkpnService.php` (PSAK 71 operasional penuh) |
| P5-11 | `jurnal_detail.baris` tanpa default & tanpa keunikan | `DEFAULT 0` + UNIQUE `(jurnal_id, baris)` + penanganan duplikat | `test_kolom_baris_jurnal_detail_punya_default`, `test_baris_jurnal_detail_unik_per_jurnal` |

### Prioritas 3 (P5-12 … P5-15)

| Kode | Temuan | Perbaikan | Bukti |
|---|---|---|---|
| P5-12 | `catch` menelan galat tanpa log | 10+ lokasi diberi `Log::warning/error/debug` sesuai konteks | `test_periode_tak_dapat_diurai_dicatat_ke_log`, `test_neraca_tanggal_tak_dapat_diurai_dicatat_ke_log` |
| P5-13 | Kegagalan tutup buku & AML tanpa bukti diagnosis | `Log::error` + trace pada `CoaController` (×2) & `AmlComplianceController` | pemeriksaan + jalur log teruji |
| P5-14 | Password demo harfiah di seeder & view (R4.8) | `config('app.demo.*')` + `DEMO_USER_PASSWORD`; seeder menolak produksi | `test_seeder_membaca_password_demo_dari_konfigurasi`, `test_view_login_tidak_memuat_password_demo_literal` |
| P5-15 | Panel demo selalu tampil | gate `@if (config('app.demo.login_enabled'))` | `test_panel_demo_login_mengikuti_konfigurasi` |

---

## 8. Risiko Sisa Setelah Tahap 5

Tidak ada risiko sisa yang bersifat **fungsional** — seluruh 30 temuan Klaster D
tertutup. Yang secara jujur **tetap perlu diperhatikan** (bukan temuan terbuka,
melainkan konsekuensi desain):

| # | Catatan | Sifat | Tindak lanjut |
|---|---|---|---|
| C5.1 | Cut-off disimpan sebagai **satu** tanggal, bukan daftar periode tertutup | keterbatasan desain | Cukup untuk pola tutup buku tahunan; bila kelak perlu multi-periode, perlu tabel periode akuntansi |
| C5.2 | Gerbang cut-off bersifat *fail-open* untuk nilai kebijakan tak sah | keputusan disengaja | Sudah di-log `debug`; monitoring log disarankan |
| C5.3 | `UserSeeder` masih punya fallback literal untuk non-produksi | kemudahan pengembangan | Cabang produksi berhenti lebih dulu; test memblokir `Hash::make('password123')` |
| C5.4 | `lockForUpdate()` hanya efektif pada transaksi basis data | karakteristik MySQL | Seluruh titik penguncian berada di dalam `DB::transaction()` |

---

## 9. Pernyataan Penyelesaian

Seluruh **30 temuan Klaster D** (hardening teknis & integritas data) telah
ditutup dalam tiga prioritas:

- **Prioritas 1** — integritas periode (gerbang cut-off tunggal & zero-hardcoding),
  penguncian baris pada jalur keuangan kritikal, foreign key RESTRICT pada jejak
  akuntansi, dan jejak audit autentikasi.
- **Prioritas 2** — indeks kinerja jalur baca laporan, de-duplikasi rumus Neraca,
  verifikasi pembulatan CKPN/PSAK 71, dan integritas urutan baris jurnal.
- **Prioritas 3** — observability atas seluruh jalur fallback, dan hardening
  kredensial demo.

**Risiko sisa Tahap 4 tuntas seluruhnya:** R4.1 (audit login) tuntas,
R4.8 (password seeder) tuntas.

Kriteria keberhasilan **K5.1–K5.16 terpenuhi seluruhnya**. Validasi
**V5.1–V5.6** (test regresi 28 test, suite penuh, pemeriksaan struktural basis
data, konsistensi lintas laporan, larangan melonggarkan kode produksi,
determinisme data dev) lulus tanpa pengecualian. Suite regresi tidak mengalami
penurunan:

**900 passed / 4244 assertions, 0 gagal** (+28 test, +84 assertion dibanding Tahap 4).

Tahap 5 dinyatakan **SELESAI**, dan dengan demikian **seluruh deliverable
Tahap 2 sampai Tahap 5 telah diserahkan**:

| Tahap | Lingkup | Dokumen | Status |
|---|---|---|---|
| 2 | PSAK inti (Klaster A, 15 temuan) | `docs/TAHAP2-PSAK-INTI.md` | ✅ Selesai |
| 3 | Pelaporan (Klaster B, 12 temuan) | `docs/TAHAP3-PELAPORAN.md` | ✅ Selesai |
| 4 | GCG & Kepatuhan OJK (Klaster C, 27 temuan) | `docs/TAHAP4-GCG-OJK.md` | ✅ Selesai |
| 5 | Hardening & Integritas Data (Klaster D, 30 temuan) | `docs/TAHAP5-HARDENING.md` | ✅ Selesai |

Total **84 temuan** ditutup, dengan **900 test lulus** sebagai penjaga regresi.
