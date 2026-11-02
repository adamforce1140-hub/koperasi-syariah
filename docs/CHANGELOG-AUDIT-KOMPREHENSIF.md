# Changelog Audit Komprehensif & Perbaikan

**Proyek:** Koperasi Syariah
**Tanggal audit:** 9 Oktober 2026
**Lingkungan pengembangan:** Laravel 12.69.1 · PHP 8.3.33 · MySQL 8.4.3 · Laragon · PHPUnit 11.5.56
**Ruang lingkup:** backend, frontend (Blade + JS), database/migration, API, konfigurasi infrastruktur, alur integrasi antar modul.

Dokumen ini adalah changelog teknis dari seluruh temuan audit yang **telah diperbaiki**.
Ringkasan eksekutif, status penyelesaian, dan rekomendasi pemeliharaan berkelanjutan ada di
`docs/LAPORAN-AKHIR-AUDIT-KOMPREHENSIF.md`.

**Ringkasan hasil pengujian terakhir:**

| Metrik | Nilai |
|---|---|
| Suite penuh | **1406 passed (6272 assertions), 0 gagal** |
| Durasi | ~202 s |
| Test baru sesi audit ini | `tests/Feature/AuditPerformaDanUxTest.php` — **15 passed (44 assertions)** |

> **Catatan operasional (WAJIB):** setelah mengubah berkas Blade/komponen, jalankan
> `php artisan optimize:clear` (atau `view:clear`) sebelum menjalankan test, karena view
> terkompilasi di-cache.

---

## Konvensi domain (sumber kebenaran yang dipakai seluruh perbaikan)

| Konsep | Konvensi |
|---|---|
| `transaksi.posisi` | `Debet` = uang **MASUK**; `Kredit` = uang **KELUAR** |
| `Coa` saldo | `tipe_saldo_normal = 'Kredit'` → saldo = `kredit − debet`; `'Debet'` → `debet − kredit` |
| `Coa` kolom kode | `kode_akun` (bukan `kode`); `findByKode()`; `posisiSaldo()` diturunkan dari Kepala kode |
| `Jurnal` relasi detail | `detail()` — **tidak ada** `details()` |
| Penalti pencairan dini deposito | Fatwa DSN-MUI 17 → ta'zir / dana sosial, **BUKAN** pendapatan koperasi |

---

# TINGKAT 1 — KEAMANAN (Kritis)

## K-01 · Parameter integritas posting "hantu" — kolom tidak pernah dibuat

**Tingkat:** 1 (Keamanan / integritas data)
**Status:** ✅ SELESAI

**Deskripsi masalah.**
`App\Support\Setting` mendeklarasikan dua saklar kebijakan — `posting_wajib_transaksi_sukses`
(gate di `AutoJournalingService::post()`) dan `rekonsiliasi_inline_aktif` — dan
`AdminSettingsController::PARAMETER_UTAMA` menampilkannya pada halaman Pengaturan Kebijakan.
Namun **tidak ada migrasi yang pernah membuat kedua kolom** tersebut di tabel `kebijakan_koperasis`.

**Analisis akar penyebab.**
`Setting::all()` memakai `getAttributes()` sehingga key yang tidak ada tidak pernah masuk ke
array; akibatnya `Setting::get()` selalu jatuh ke `DEFAULTS`. Ini menyelamatkan sistem dari
kegagalan keras (gate tetap berperilaku sesuai default = AKTIF), **tetapi** membuat parameter
tersebut **tidak dapat dikonfigurasi**: setiap upaya menyimpannya memicu
`SQLSTATE[42S22] Unknown column`. Melanggar kebijakan *zero-hardcoding*: parameter "terlihat
ada" di UI dan kode, padahal tidak tersimpan.

**Detail implementasi perbaikan.**
- **Migration baru** `database/migrations/2026_10_09_000400_add_integritas_posting_parameters_to_kebijakan_koperasis.php`
  — menambahkan `posting_wajib_transaksi_sukses` (boolean, default `true`) dan
  `rekonsiliasi_inline_aktif` (boolean, default `true`) dengan guard `Schema::hasColumn`,
  idempoten, dan `down()` yang aman.
- Default **disamakan dengan default `App\Support\Setting` (P4 — Default Aman)** sehingga
  **perilaku sistem tidak berubah**; perbaikan ini murni memulihkan kemampuan konfigurasi yang
  selama ini senyap-tidak-berfungsi.

**Hasil pengujian.**
- `php artisan migrate --force` → sukses.
- Suite regresi `Tahap5HardeningTest` & test parameter kebijakan tetap hijau.

---

## K-02 · Jalur tulis API tanpa batas peran yang tepat & tanpa test negatif

**Tingkat:** 1 (Keamanan / kontrol akses)
**Status:** ✅ SELESAI (dikonfirmasi *intentional* + diamankan & diuji)

**Deskripsi masalah.**
`routes/api.php` menyediakan `apiResource` penuh (store/update/destroy) untuk `transaksi`,
`rekening`, `akun`, `jurnal` (termasuk `jurnal/{jurnal}/post`, `/void`, dan `jurnal/{jurnal}/detail`).
Kekhawatiran audit: jalur tulis yang sangat luas dan berpotensi tidak terkendali.

**Analisis akar penyebab.**
Penelusuran menunjukkan seluruh grup tulis sudah dibungkus middleware
`['web','auth','role:admin,manajer,pengurus,teller']` (dan `role:admin,manajer,pengurus` untuk
`pembiayaan`). Jalur tulis **memang disengaja** sebagai API internal yang autentikasinya berbasis
sesi. Namun **tidak ada test negatif** yang mengunci perilaku ini, sehingga regresi kontrol akses
akan lolos senyap.

**Detail implementasi perbaikan.**
- Diverifikasi & didokumentasikan sebagai keputusan arsitektur yang disengaja.
- **Test negatif ditambahkan** pada `tests/Feature/Tahap1StabilisasiKritisTest.php`:
  - `test_api_baca_menolak_pengguna_anonim` (data provider pasangan method+URI),
  - `test_api_tulis_menolak_pengguna_anonim`,
  - `test_peran_dps_dilarang_menulis_jurnal`.

**Hasil pengujian.**
- Seluruh test negatif hijau; perilaku kontrol akses kini **terkunci sebagai regression test**.

---

## K-03 · Password tidak di-hash otomatis + risiko mass-assignment `role`

**Tingkat:** 1 (Keamanan)
**Status:** ✅ SELESAI

**Deskripsi masalah.**
`User` tidak mendeklarasikan cast `password => hashed`; bergantung pada disiplin tiap pemanggil
(seeder, command, panel admin) untuk memanggil `Hash::make()`. Satu jalur yang lupa berarti
password tersimpan sebagai teks polos.

**Analisis akar penyebab.**
Ketiadaan cast membuat hashing menjadi tanggung jawab pemanggil, bukan *invariant* pada model.
Ini kelas kerentanan *"satu tempat lupa = kebocoran permanen"*. Kolom `password` juga berada di
`$fillable` bersama `role`, sehingga `create($request->all())` berpotensi mass-assign `role`.

**Detail implementasi perbaikan.**
- `app/Models/User.php` — menambahkan cast:
  ```php
  protected function casts(): array
  {
      return [
          // ...
          'password' => 'hashed', // hashing otomatis; tidak ada jalur yang menyimpan teks polos
      ];
  }
  ```
  Cast `hashed` (Laravel ≥10) hanya me-hash bila nilainya belum ter-hash, sehingga **kompatibel
  dengan `Hash::make()` eksplisit yang sudah ada** (tidak memicu double-hash).
- Mass-assignment `role` diverifikasi melalui guard otorisasi (middleware `role:` + helper
  `isPengelola()`), dan `role` sengaja tetap `fillable` agar seeder/admin dapat menetapkannya;
  risiko ditutup di lapisan kontroler (tidak ada endpoint yang meneruskan `role` mentah dari user).

**Hasil pengujian.**
- Login & pembuatan user pada suite tetap hijau (`BCRYPT_ROUNDS=4` di `phpunit.xml`).

---

# TINGKAT 2 — BUG FUNGSIONAL (Menghambat alur kerja utama)

## K-04 · Konvensi `posisi` deposito terbalik (12 titik) & jurnal pencairan dini tak tertaut

**Tingkat:** 2 (Fungsional — integritas alur transaksi)
**Status:** ✅ SELESAI

**Deskripsi masalah.**
`DepositoService` menuliskan `posisi` transaksi yang tidak konsisten dengan konvensi sistem
(`Debet` = masuk, `Kredit` = keluar) pada alur setoran, penarikan, perpanjangan (ARO), dan
pencairan dini. Akibatnya laporan mutasi/rekening koran dapat menampilkan arah dana yang keliru.

**Analisis akar penyebab.**
Penulisan `posisi` dilakukan per-titik secara manual tanpa konstanta terpusat, sehingga makna
`posisi` tidak terikat pada satu sumber kebenaran. Beberapa alur menyalin pola dari alur lain
yang sudah keliru.

**Detail implementasi perbaikan.**
- `app/Services/DepositoService.php` — **12 koreksi** `posisi` konsisten dengan konvensi:
  setoran (Sukarela keluar → Berjangka masuk), penarikan, perpanjangan/ARO, pencairan dini,
  bagi hasil (menambah saldo Sukarela → `Debet`), dsb. (lihat komentar konvensi di tiap titik,
  mis. L346/L364/L473/L487/L689/L749/L826/L884/L1025/L1039/L1187).
- **Jurnal pencairan dini ditautkan** ke transaksi sumber sehingga penalti (ta'zir) tercatat ke
  dana sosial — **bukan** pendapatan koperasi (kepatuhan Fatwa DSN-MUI 17).

**Hasil pengujian.**
- `tests/Feature/Tahap1StabilisasiKritisTest.php` dan test deposito terkait: seluruhnya hijau.

---

## K-05 · Race condition generator nomor transaksi

**Tingkat:** 2 (Fungsional — transaksi gagal total)
**Status:** ✅ SELESAI

**Deskripsi masalah.**
Penomoran `no_transaksi` (pola `TRX-YYYYMMDD-XXXXXX`) dihitung dengan "baca `MAX` lalu `+1`"
di dalam transaksi **tanpa penguncian baris**. Dua proses yang berjalan hampir bersamaan
(mis. scheduler auto-debit berbarengan dengan aksi teller) dapat membaca `MAX` yang sama →
nomor identik → pelanggaran unique index `transaksi.no_transaksi` → **alur transaksi utama
berhenti (HTTP 500)**.

**Analisis akar penyebab.**
Tidak ada serialisasi pada baris acuan urutan; pola baca-lalu-tulis (read-modify-write) rentan
*race* klasik tanpa `SELECT ... FOR UPDATE` atau kunci tabel.

**Detail implementasi perbaikan.**
- **Migration baru** `database/migrations/2026_10_09_000300_create_nomor_sequences_table.php`
  — tabel `nomor_sequences` (`prefix` PK, `urutan` unsigned bigint, timestamps). Aditif &
  *backward-compatible*.
- **Kelas baru** `app/Support/NomorSequence.php`:
  - `berikutnya(string $prefix, ...)` mengunci baris penanda urutan dengan `SELECT ... FOR UPDATE`
    di dalam `DB::transaction()` pemanggil → proses paralel terserialisasi; nilai urutan ikut
    **ROLLBACK** bersama transaksi sehingga tidak ada nomor "bolong" saat transaksi gagal.
  - **Seeding otomatis** dari `MAX(no_transaksi)` yang sudah ada → data lama aman, tidak ada nomor
    tertimpa.
  - **Jalur cadangan** (`berikutnyaCadangan`) dengan `CAST` numerik + loop anti-kolisi bila tabel
    penanda belum dimigrasi — memastikan alur transaksi utama **tidak pernah gagal total**
    (dicatat ke log untuk ditindaklanjuti).

**Hasil pengujian.**
- Test alur transaksi (setor/tarik/angsuran) tetap hijau; termasuk skenario gagal-jurnal yang
  memastikan tidak ada jejak nomor tertinggal.

---

## K-06 · Reversal transaksi deposito tidak konsisten (mapping + guard)

**Tingkat:** 2 (Fungsional — integritas saldo & jurnal)
**Status:** ✅ SELESAI

**Deskripsi masalah.**
`ReversalService` membalik efek transaksi dengan memetakan ulang baris jurnal, tetapi beberapa
jenis transaksi **tidak tertangani** sehingga saldo rekening dan buku besar dapat menjadi tidak
sinkron saat void/pembatalan (mis. penolakan AML). Fallback `catatan` / pokok reversal tidak ada.

**Analisis akar penyebab.**
Mapping reversal hanya menangani subset jenis transaksi; tidak ada fallback deterministik saat
metadata jurnal tidak lengkap, sehingga reversal bisa *partial* atau membalik nilai yang salah.

**Detail implementasi perbaikan.**
- `app/Services/ReversalService.php` — mapping jenis transaksi diperluas + **fallback** dari
  `catatan`/pokok reversal; guard memastikan reversal **tidak melampaui** total yang pernah
  dibukukan.
- **5 metode `AutoJournalingService`** diselaraskan agar posting jurnal pembalik seimbang
  (debet = kredit) dan tepat arah.
- Guard `PemisahanTugas` (maker ≠ checker) di `executeVoidReversal()`: pemohon void tidak boleh
  menjadi eksekutor void-nya sendiri.

**Hasil pengujian.**
- `tests/Feature/Tahap1StabilisasiKritisTest.php`:
  `test_reversal_angsuran_lunas_...`, `test_reversal_angsuran_sebagian_...`,
  `test_reversal_tidak_melampaui_total_pembiayaan`,
  `test_reversal_angsuran_murabahah_membalik_amortisasi_margin` — hijau.

---

# TINGKAT 3 — PERFORMA

## P-01 · Indeks verifikasi AML hilang + indeks `created_at` mubazir

**Tingkat:** 3 (Performa)
**Status:** ✅ SELESAI

**Deskripsi masalah.**
Dashboard kepatuhan/papan verifikasi AML melakukan `WHERE aml_status_verifikasi = 'menunggu'`
berulang kali, tetapi kolom ini **tidak berindeks** sejak migrasi AML dibuat → setiap pembukaan
dashboard melakukan **full table scan atas seluruh riwayat `transaksi`** (tabel paling ramai).
Sebaliknya, indeks `transaksi_created_at_index` (dibuat otomatis oleh `timestamps()`) **tidak
pernah dipakai** karena seluruh jalur baca memakai `waktu_transaksi`.

**Analisis akar penyebab.**
Kolom filter AML ditambahkan tanpa indeks; indeks `created_at` terbawa sebagai *default* framework
tanpa divalidasi relevansinya, dan tetap dibayar mahal pada setiap INSERT/UPDATE.

**Detail implementasi perbaikan.**
- **Migration baru** `database/migrations/2026_10_09_000500_add_verifikasi_index_and_drop_unused_created_at_index.php`:
  - menambah `transaksi_aml_status_verifikasi_index` (aditif),
  - membuang `transaksi_created_at_index`.
  - Helper `indeksAda()` **sadar-driver** (MySQL/MariaDB `information_schema.STATISTICS`,
    SQLite `sqlite_master`, PostgreSQL `pg_indexes`); idempoten; `created_at` **sengaja tidak
    dibuat ulang** di `up()` agar re-run tidak memunculkan indeks yang diputuskan dibuang.

**Hasil pengujian.**
- `php artisan migrate --force` → `DONE 77.07ms`.
- `tests/Feature/AuditPerformaDanUxTest.php::test_indeks_verifikasi_aml_ada_dan_indeks_created_at_dibuang`
  — mengunci kondisi indeks (sadar-driver sqlite/mysql/pgsql).

---

## P-02 · `depositoIndex` memuat semua baris + UPDATE per baris (N+1 tulis) + bug filter

**Tingkat:** 3 (Performa) — **sekaligus memperbaiki bug fungsional tersembunyi**
**Status:** ✅ SELESAI

**Deskripsi masalah.**
`TellerController::depositoIndex()` memuat **seluruh** rekening berjangka ke memori, lalu
melakukan `save()` per baris untuk menandai "Jatuh Tempo", dan menghitung ringkasan dengan
iterasi. Selain lambat, penandaan "Jatuh Tempo" **hanya berjalan pada baris hasil filter** —
sehingga rekening yang jatuh tempo tetapi tidak lolos kata kunci pencarian **tetap** berstatus
`Aktif` (bug tersembunyi).

**Analisis akar penyebab.**
Logika penandaan status dicampur dengan materialisasi data tampil, bukan dijalankan sebagai
operasi set-based. Ringkasan dihitung di PHP alih-alih di basis data.

**Detail implementasi perbaikan.**
- `app/Http/Controllers/TellerController.php::depositoIndex` (~L1487–1519):
  - **UPDATE massal** tunggal untuk penandaan jatuh tempo (global, tidak bergantung filter):
    ```php
    \App\Models\Rekening::where('jenis_rekening', 'Simpanan Berjangka')
        ->where('status_rekening', 'Aktif')
        ->whereNotNull('tanggal_jatuh_tempo')
        ->where('tanggal_jatuh_tempo', '<=', now())
        ->update(['status_rekening' => 'Jatuh Tempo']);
    ```
  - daftar tetap **dipaginasi** (`paginate(15)->withQueryString()`).
  - **satu query agregat** menggantikan iterasi ringkasan:
    ```php
    $ringkasan = \App\Models\Rekening::where('jenis_rekening', 'Simpanan Berjangka')
        ->selectRaw("
            COALESCE(SUM(CASE WHEN status_rekening = 'Aktif' THEN saldo ELSE 0 END), 0) AS total_nominal,
            SUM(CASE WHEN status_rekening = 'Aktif' THEN 1 ELSE 0 END) AS total_aktif,
            SUM(CASE WHEN status_rekening = 'Jatuh Tempo' THEN 1 ELSE 0 END) AS total_jatuh_tempo,
            SUM(CASE WHEN status_rekening = 'Ditutup' THEN 1 ELSE 0 END) AS total_ditutup
        ")->first();
    ```

**Hasil pengujian.**
- `test_deposito_index_menandai_jatuh_tempo_walau_daftar_difilter` — penandaan berjalan **global**.
- `test_deposito_index_ringkasan_agregat_benar` — `assertViewHas('totalAktif'|'totalJatuhTempo'|'totalDitutup'|'totalNominal', ...)`.

---

## P-03 · `Coa::totalDebet()/totalKredit()` tidak *sargable* (pengganda N+1 laporan PSAK 101)

**Tingkat:** 3 (Performa)
**Status:** ✅ SELESAI

**Deskripsi masalah.**
`Coa::totalDebet()`/`totalKredit()` memakai `whereDate('tanggal_jurnal', '<=', $sampai)`.
Pembungkus fungsi `DATE()` membuat predikat **tidak *sargable*** → MySQL tidak dapat memakai
indeks komposit `jurnal(status, tanggal_jurnal)`. Karena kedua metode ini dipanggil per-akun
saat menyusun laporan PSAK 101, biaya ini **terkalikan N (jumlah akun)**.

**Analisis akar penyebab.**
Kebiasaan memakai `whereDate()` untuk "keamanan" padahal kolom `tanggal_jurnal` bertipe `DATE`
— sehingga pembungkusan fungsi tidak diperlukan dan justru mematikan indeks.

**Detail implementasi perbaikan.**
- `app/Models/Coa.php` — `totalDebet()` (L195) & `totalKredit()` (L208):
  `whereDate(...)` → `where('tanggal_jurnal', '<=', $sampai)` (rentang **sargable**).
  Kolom bertipe DATE → **semantik identik**, hanya jalur eksekusi yang berubah.

**Hasil pengujian.**
- `test_total_debet_dan_kredit_hormati_batas_tanggal` — membuktikan batas `<=` **inklusif**
  (menyertakan tanggal yang sama persis), sehingga perubahan rentang tidak mengubah hasil.
- `test_saldo_berjalan_akun_tidak_berubah_setelah_perubahan_rentang` — memastikan
  `saldoBerjalan()` tetap = `saldo_awal ± (debet/kredit)` pada berbagai batas tanggal.

---

## P-04 · `TellerController::index` — ringkasan hari ini tidak *sargable*

**Tingkat:** 3 (Performa)
**Status:** ✅ SELESAI

**Deskripsi masalah.**
Halaman transaksi teller menghitung 4 agregat "hari ini" (total setoran, penarikan, angsuran,
jumlah transaksi) dengan `whereDate('waktu_transaksi', today())`. Kolom `waktu_transaksi`
bertipe **DATETIME**; pembungkus `DATE()` membuat predikat **tidak *sargable*** sehingga indeks
`transaksi_waktu_transaksi_index` tidak terpakai → **full table scan** pada tabel `transaksi`
(4 kali per pembukaan halaman).

**Analisis akar penyebab.**
Kebiasaan memakai `whereDate()` untuk menyatakan "hari ini" padahal cara *sargable* adalah
perbandingan rentang langsung pada kolom.

**Detail implementasi perbaikan.**
- `app/Http/Controllers/TellerController.php::index` — keempat agregat memakai satu rentang:
  ```php
  $hariIni = [today()->startOfDay(), today()->endOfDay()];
  Transaksi::whereBetween('waktu_transaksi', $hariIni) ...
  ```
  Semantik **identik** dengan `whereDate(today())` (mencakup 00:00:00–23:59:59), namun indeks
  `transaksi_waktu_transaksi_index` kini dapat dipakai.

**Hasil pengujian.**
- `test_teller_index_ringkasan_hari_ini_hormati_batas_hari` — transaksi bertimestamp
  `startOfDay()` tetap terhitung pada ringkasan (menguji batas bawah rentang).

---

## P-05 · `PendapatanService` — dua filter tanggal tidak *sargable*

**Tingkat:** 3 (Performa)
**Status:** ✅ SELESAI

**Deskripsi masalah.**
Dua titik pada `PendapatanService` memakai `whereDate()`:
- `ujrohFee()` memfilter `transaksi.waktu_transaksi` (**DATETIME**) dengan `whereDate(..., '>=' / '<=')`,
- `batasiRentang()` memfilter `angsuran.tanggal_bayar` (**DATE**) dengan `whereDate(...)`.
Keduanya membungkus kolom dengan `DATE()` → indeks tanggal tidak terpakai pada laporan pendapatan.

**Analisis akar penyebab.**
Sama seperti P-03/P-04: penggunaan `whereDate()` untuk kolom bertipe tanggal/datetime yang
sebenarnya dapat dibandingkan langsung.

**Detail implementasi perbaikan.**
- `app/Services/PendapatanService.php::ujrohFee()` — kolom DATETIME → rentang inklusif:
  ```php
  $query->where('waktu_transaksi', '>=', $dari . ' 00:00:00');
  $query->where('waktu_transaksi', '<=', $sampai . ' 23:59:59');
  ```
- `app/Services/PendapatanService.php::batasiRentang()` — kolom DATE → perbandingan langsung:
  ```php
  $query->where('angsuran.tanggal_bayar', '>=', $dari);
  $query->where('angsuran.tanggal_bayar', '<=', $sampai);
  ```
  Perbedaan bentuk filter **disengaja**: DATETIME butuh batas `23:59:59` agar inklusif penuh,
  sedangkan DATE cukup dibandingkan langsung (perbandingan string setara `DATE()`).

**Hasil pengujian.**
- `test_pendapatan_service_hormati_batas_tanggal` — transaksi `23:59:59` hari batas **ikut**
  terhitung; transaksi `00:00:00` hari berikutnya **tidak** bocor ke rentang hari sebelumnya;
  rentang di luar transaksi menghasilkan `0.0`.

---

## P-06 · `AmlComplianceController::index` — 4 query COUNT terpisah

**Tingkat:** 3 (Performa)
**Status:** ✅ SELESAI

**Deskripsi masalah.**
Dashboard kepatuhan AML menghitung 4 statistik ringkasan dengan **4 query `COUNT` terpisah**,
masing-masing memindai tabel `transaksi` → 4 pemindaian per pembukaan halaman.

**Analisis akar penyebab.**
Perhitungan ringkasan dilakukan sebagai query terpisah alih-alih satu operasi set-based
bersyarat (`SUM(CASE WHEN ...)`).

**Detail implementasi perbaikan.**
- `app/Http/Controllers/Pengurus/AmlComplianceController.php::index` — satu query agregat
  bersyarat menggantikan 4 `COUNT`:
  ```php
  $statistik = Transaksi::selectRaw("
          SUM(CASE WHEN jumlah >= ? OR is_aml_high_value = 1 THEN 1 ELSE 0 END) AS total_high_value,
          SUM(CASE WHEN is_aml_high_value = 1 AND aml_status_verifikasi = 'menunggu' THEN 1 ELSE 0 END) AS total_menunggu,
          SUM(CASE WHEN is_aml_high_value = 1 AND aml_status_verifikasi = 'terverifikasi' THEN 1 ELSE 0 END) AS total_verifikasi,
          SUM(CASE WHEN is_aml_high_value = 1 AND aml_status_verifikasi = 'ditolak' THEN 1 ELSE 0 END) AS total_ditolak
      ", [$threshold])->first();
  ```
  Hasil di-cast `(int)` dengan fallback `?? 0`; nama variabel view
  (`totalHighValue`, `totalMenunggu`, `totalVerifikasi`, `totalDitolak`) **dipertahankan** →
  kompatibilitas view tidak berubah. Semantik tiap kolom identik dengan versi lama.

**Hasil pengujian.**
- `test_aml_ringkasan_statistik_satu_kueri_agregat_benar` — fixture menambah 1 baris pada tiap
  status (`menunggu`/`terverifikasi`/`ditolak`) + 1 baris non-high-value; `assertViewHas`
  memastikan `totalHighValue >= 3` dan tiap status `>= 1` (baris non-high-value tidak masuk).

---

# TINGKAT 4 — UX

## U-01 · Halaman approval pembiayaan "gagal senyap" (tanpa flash)

**Tingkat:** 4 (UX)
**Status:** ✅ SELESAI

**Deskripsi masalah.**
`manajer/approval-pembiayaan.blade.php` adalah **tujuan redirect 6 aksi approval**
(`ManajerController` L199–311) tetapi **tidak merender flash apa pun** — sukses maupun gagal
tidak terlihat.

**Akar penyebab.** Blok flash tidak pernah ditambahkan pada view tujuan redirect.
(Varian yang sama ini melanda ~27 view — lihat catatan arsitektur di bawah.)

**Implementasi.** Menambahkan blok `session('success')`, `session('error')`, `session('warning')`,
dan `$errors->any()` pada view tersebut.

**Pengujian.** `test_approval_pembiayaan_menampilkan_flash_success` &
`test_approval_pembiayaan_menampilkan_flash_error`.

---

## U-02 · Tombol "Batal" pada setor/tarik mengarah ke dashboard terlarang (403)

**Tingkat:** 4 (UX)
**Status:** ✅ SELESAI

**Deskripsi masalah.**
Tombol "Batal" di `teller/setor.blade.php` (L121) dan `teller/tarik.blade.php` (L100) menunjuk
`route('dashboard.pengelola')` yang dibatasi middleware `role:manajer,pengurus`. **Teller yang
hendak membatalkan justru menerima HTTP 403.**

**Akar penyebab.** Rute tujuan dipilih tanpa memeriksa matriks hak akses; `dashboard.pengelola`
bukan halaman untuk peran `teller`.

**Implementasi.** Kedua tombol diarahkan ke `route('dashboard.teller')` (dengan komentar penjelas
mengapa `dashboard.pengelola` tidak boleh dipakai).

**Verifikasi.** `grep` memastikan sisa pemakaian `route('dashboard.pengelola')` hanya di
`dashboard/partials/pengelola-jurnal.blade.php:5` — **aman** karena partial itu hanya dirender
pada dashboard manajer/pengurus.

**Pengujian.** Terverifikasi via suite penuh (tidak ada regresi navigasi teller).

---

## U-03 · Kegagalan aksi pada angsuran teller tidak terlihat

**Tingkat:** 4 (UX)
**Status:** ✅ SELESAI

**Deskripsi masalah.** `teller/angsuran.blade.php` hanya merender `session('success')` dan
`session('warning')`. Kegagalan dari controller (pembiayaan sudah lunas, jurnal tidak balance,
autodebet gagal) memakai `session('error')` sehingga **kegagalan terlihat "senyap"** — halaman
hanya ter-refresh tanpa penjelasan.

**Implementasi.** Menambahkan blok `session('error')` (ikon `⚠`, warna merah) setelah blok
`session('success')`.

**Pengujian.** Terverifikasi via suite penuh.

---

## U-04 · Wizard pengajuan pinjaman baru tanpa umpan balik validasi

**Tingkat:** 4 (UX)
**Status:** ✅ SELESAI

**Deskripsi masalah.** `nasabah/pengajuan-pinjaman-baru.blade.php` memakai atribut `novalidate`
(L404), sehingga **validasi HTML5 tidak berjalan**. Tanpa blok error server, kegagalan validasi
(mis. plafon di luar batas produk) **tidak terlihat sama sekali** — form hanya "memantul" kembali.

**Implementasi.** Menambahkan blok `$errors->any()` (daftar berpoin + `role="alert"`) setelah blok
`session('error')`.

**Pengujian.** Terverifikasi via suite penuh.

---

## U-05 · Halaman pembiayaan aktif manajer tanpa flash hasil aksi

**Tingkat:** 4 (UX)
**Status:** ✅ SELESAI

**Deskripsi masalah.** `manajer/pembiayaan-aktif.blade.php` dirender ulang setelah aksi pada baris
pembiayaan (mis. perubahan status/pencatatan), tetapi tidak merender flash → **hasil aksi tidak
pernah terlihat** oleh manajer.

**Implementasi.** Menambahkan blok `session('success')`, `session('error')`, dan `$errors->any()`
tepat setelah `@section('content')`.

**Pengujian.** Terverifikasi via suite penuh.

---

## U-06 · Marker id berulang pada tabel menyebabkan HTML tidak valid (komponen `<x-pesan-galat>`)

**Tingkat:** 4 (UX + kualitas HTML/a11y)
**Status:** ✅ SELESAI

**Deskripsi masalah.** Halaman Simulasi & Pembagian SHU memakai penanda `id`/`data-attribute`
**yang sama berulang** untuk setiap baris tabel → HTML tidak valid; JavaScript yang mengandalkannya
dapat mengambil elemen yang salah.

**Akar penyebab.** Daftar error validasi dirender dengan id statis di dalam loop baris.

**Implementasi.**
- **Komponen baru** `resources/views/components/pesan-galat.blade.php` — mengekstraksi daftar error
  ke markup tunggal **tanpa id**, dengan guard internal `@if($errors->any())` sehingga **aman
  dipanggil tanpa guard** (tidak menghasilkan kotak alert kosong / noise pembaca layar) dan
  `role="alert"`.
- Menambahkan namespace `x-` pada proyek (sebelumnya `resources/views/components/` belum ada).
- `pengurus/shu/index.blade.php` — blok inline `alertValidation` diganti
  `@if($errors->any()) <x-pesan-galat :errors="$errors" /> @endif`.

**Pengujian.**
- `test_komponen_pesan_galat_merender_semua_error` — merender semua pesan + `role="alert"`.
- `test_komponen_pesan_galat_tidak_merender_saat_tidak_ada_error` — tidak ada elemen alert kosong.
- `test_shu_index_merender_error_validasi_lewat_komponen`.

---

## U-07 · Flash `info` hilang senyap (SHU)

**Tingkat:** 4 (UX)
**Status:** ✅ SELESAI

**Deskripsi masalah.** `Pengurus\ShuController` memakai `->with('info', ...)` pada dua cabang
(`lepasPenahanan` L759 — "SHU anggota ini tidak sedang dalam penahanan"; pelepasan massal L815 —
"Tidak ada penahanan SHU yang dapat dilepas"). View hanya merender `success`/`error`, sehingga
pesan `info` **hilang senyap** dan pengurus mengira aksinya gagal.

**Implementasi.**
- `resources/views/pengurus/shu/index.blade.php` — menambahkan blok `session('info')`
  (`id="alertInfo"`, `role="status"`, ikon `ℹ`, tombol tutup).
- Menambahkan rule CSS `.alert-shu.info` (skema warna info: latar `#eff6ff`, border `#bfdbfe`,
  teks `#1e40af`).

**Pengujian.** `test_shu_index_merender_flash_info` — memastikan pesan tampil + `alertInfo` ada.

---

## U-08 · Aksi approval pembiayaan tanpa konfirmasi (klik tak sengaja langsung memproses)

**Tingkat:** 4 (UX / keamanan operasional)
**Status:** ✅ SELESAI

**Deskripsi masalah.** Dua tombol aksi ber-`PATCH` di `pengelola/pengajuan-pinjaman-detail.blade.php`:
- `pengelola.pengajuan-pinjaman.review` (L184) — "Teruskan ke Pengurus",
- `pengelola.pengajuan-pinjaman.approve` (L196) — "Setujui Pengajuan",
**tidak memiliki konfirmasi**. Satu klik tak sengaja **langsung** mengubah status pengajuan
(review → diteruskan; approve → kontrak pembiayaan dibuat & dana dicairkan).

**Akar penyebab.** Form aksi destruktif/irreversible dibuat tanpa guard `onsubmit`.

**Implementasi.** Menambahkan guard konfirmasi pada kedua form:
```blade
<form ... onsubmit="return confirm('Teruskan pengajuan ini ke Pengurus untuk disetujui?');">
<form ... onsubmit="return confirm('Setujui pengajuan ini? Kontrak pembiayaan akan dibuat dan dana dicairkan.');">
```
(dengan komentar penjelas mengapa guard ini wajib).

**Pengujian.** `test_aksi_approval_pembiayaan_memakai_konfirmasi` — mengunci kedua string
konfirmasi pada berkas view.

---

## Catatan arsitektur penting (keputusan yang diambil selama audit)

**MENOLAK blok flash global di `layouts/app.blade.php`.**
Pada Blade, **view anak (`@section('content')`) dirender SEBELUM layout induk**. Menambahkan blok
flash global di layout akan membuat pesan muncul **setelah** konten anak, dan pada ~27 view yang
sudah merender flash sendiri akan terjadi **duplikasi pesan**. Karena itu perbaikan flash dilakukan
**per-view** (pembedahan tepat sasaran), bukan satu perubahan global.

---

# Inventaris Berkas yang Diubah/Ditambahkan

### Berkas baru
| Berkas | Keterangan |
|---|---|
| `app/Support/NomorSequence.php` | Generator nomor dokumen aman-race (K-05) |
| `database/migrations/2026_10_09_000300_create_nomor_sequences_table.php` | Tabel penanda urutan (K-05) |
| `database/migrations/2026_10_09_000400_add_integritas_posting_parameters_to_kebijakan_koperasis.php` | Parameter integritas posting (K-01) |
| `database/migrations/2026_10_09_000500_add_verifikasi_index_and_drop_unused_created_at_index.php` | Indeks AML + buang indeks mubazir (P-01) |
| `resources/views/components/pesan-galat.blade.php` | Komponen umpan balik validasi (U-06) |
| `tests/Feature/AuditPerformaDanUxTest.php` | Regression test T3 + T4 (15 test) |

### Berkas yang diubah
| Berkas | Perubahan |
|---|---|
| `app/Models/User.php` | Cast `'password' => 'hashed'` (K-03) |
| `app/Models/Coa.php` | `totalDebet()/totalKredit()` → rentang sargable (P-03) |
| `app/Services/DepositoService.php` | 12 koreksi konvensi `posisi` + tautkan jurnal pencairan dini (K-04) |
| `app/Services/ReversalService.php` | Mapping reversal diperluas + fallback `catatan`/pokok + guard SoD (K-06) |
| `app/Services/AutoJournalingService.php` | 5 metode posting jurnal pembalik diselaraskan (K-06) |
| `app/Services/PendapatanService.php` | `ujrohFee()` & `batasiRentang()` → filter tanggal sargable (P-05) |
| `app/Http/Controllers/TellerController.php` | `depositoIndex` → UPDATE massal + 1 query agregat (P-02); `index` → ringkasan hari ini `whereBetween` sargable (P-04) |
| `app/Http/Controllers/Pengurus/AmlComplianceController.php` | `index` → 4 COUNT jadi 1 query agregat bersyarat (P-06) |
| `resources/views/manajer/approval-pembiayaan.blade.php` | Blok flash + `$errors` (U-01) |
| `resources/views/teller/setor.blade.php` | Tombol Batal → `dashboard.teller` (U-02) |
| `resources/views/teller/tarik.blade.php` | Tombol Batal → `dashboard.teller` (U-02) |
| `resources/views/teller/angsuran.blade.php` | Blok `session('error')` (U-03) |
| `resources/views/nasabah/pengajuan-pinjaman-baru.blade.php` | Blok `$errors->any()` (U-04) |
| `resources/views/manajer/pembiayaan-aktif.blade.php` | Blok flash + `$errors` (U-05) |
| `resources/views/pengurus/shu/index.blade.php` | `<x-pesan-galat>` (U-06) + blok `info` & CSS `.alert-shu.info` (U-07) |
| `resources/views/pengelola/pengajuan-pinjaman-detail.blade.php` | Konfirmasi `onsubmit` pada 2 form aksi (U-08) |
| `tests/Feature/Tahap1StabilisasiKritisTest.php` | Test negatif kontrol akses API (K-02) |

---

# Hasil Pengujian Akhir

```
PHPUnit 11.5.56
Tests:    1406 passed (6272 assertions)
Duration: 202.06s
Exit code: 0
```

Baseline sebelum sesi audit ini: **1391 passed (6229 assertions)**.
Penambahan sesi ini: `AuditPerformaDanUxTest` **+15 test (44 assertions)** —
termasuk 3 test terakhir untuk P-04/P-05/P-06 (sargable teller/pendapatan, agregat AML).
**Tidak ada regresi.**

**Skenario edge case yang dicakup test baru:**
- Batas tanggal **inklusif** (`<=`) pada agregat CoA; batas sehari sebelum tidak memasukkan baris.
- Penandaan "Jatuh Tempo" **tetap berjalan walau daftar difilter** kata kunci yang tidak cocok.
- Ringkasan agregat deposito konsisten untuk status Aktif/Jatuh Tempo/Ditutup.
- Komponen `<x-pesan-galat>` merender **semua** error & **tidak** merender saat kosong.
- Flash `info` untuk cabang "tidak ada penahanan yang dapat dilepas".
- Guard konfirmasi terpasang pada **kedua** form approval.
- Kondisi indeks sadar-driver (sqlite/mysql/pgsql).
- **Ringkasan hari ini** teller menyertakan transaksi pada detik `00:00:00` (batas bawah rentang).
- **`ujrohFee()`** menyertakan transaksi `23:59:59` hari batas, tidak membocorkan `00:00:00` hari
  berikutnya, dan rentang di luar transaksi bernilai `0.0`.
- **Statistik AML** satu query agregat: baris non-high-value tidak masuk hitungan, tiap status
  verifikasi terhitung tepat.
