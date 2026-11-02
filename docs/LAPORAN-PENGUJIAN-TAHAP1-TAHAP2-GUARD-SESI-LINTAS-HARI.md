# Laporan Implementasi & Hasil Pengujian
## Tahap 1 — Guard Sesi Autentikasi/Kas · Tahap 2 — Aturan Lintas Hari

**Proyek:** Koperasi Syariah (`c:\laragon\www\koperasi_syariah`)
**Tanggal eksekusi:** 19 Oktober 2026
**Lingkungan:** Laravel 12.69.1 · PHP 8.3.33 · MySQL 8.4.3 · PHPUnit 11.5.56 · Windows (Laragon)
**Status akhir:** SELESAI — seluruh pengujian lulus, tidak ada regresi.

---

## 1. Ringkasan Eksekutif

| Tahap | Komponen | Test | Hasil |
|---|---|---|---|
| 1 | Guard sesi kas (`PastikanKasTerbuka`) + guard autentikasi Laravel | 18 | Lulus semua |
| 2 | `AturanLintasHariService` + integrasi Opening/Closing | 41 (28 unit + 13 E2E) | Lulus semua |
| Regresi | Suite kas & RBAC terkait | 135 | Lulus semua |
| **Verifikasi akhir** | **Suite penuh** | **5 skipped, 1718 passed (7824 assertions)** | **Lulus semua** |

Durasi suite penuh: **244,48 detik** (4 menit 5 detik). Exit code `0`.

---

## 2. Catatan Adaptasi Istilah (Penting)

Permintaan menggunakan istilah generik *"token autentikasi / token palsu / permintaan tanpa token"*.
Sistem ini **tidak** memakai API token (tanpa Sanctum/Passport); autentikasi berbasis **session
Laravel** (cookie + guard `web`, `SESSION_DRIVER=array` saat pengujian). Karena itu istilah tersebut
dipetakan sebagai berikut tanpa mengurangi cakupan skenario:

| Istilah permintaan | Padanan nyata pada sistem | Implementasi/verifikasi |
|---|---|---|
| Token autentikasi | Session ID + hash kata sandi pada session guard | `tests\Feature\KasGuardSesiTest.php` |
| Sesi kedaluwarsa | Session guard tidak lagi mengenali pengguna (hash tidak cocok) | `test_http_sesi_kedaluwarsa_hash_tidak_cocok_dialihkan_ke_login` |
| Token palsu / tidak terdaftar | ID pengguna pada session tidak ada di tabel `users` | `test_http_token_palsu_id_pengguna_tidak_terdaftar_dialihkan_ke_login` |
| Permintaan tanpa token | Request tanpa autentikasi (HTML → redirect login, JSON → 401) | `test_http_tanpa_autentikasi_*` |
| Akses tanpa otorisasi | Pengguna terautentikasi tetapi tanpa sesi kas sah / tanpa peran | `test_unit_permintaan_tanpa_pengguna_ditolak_401`, `test_http_tanpa_sesi_json_mendapat_403` |

Selain guard autentikasi, Tahap 1 juga memuat **guard sesi kas teller** — padanan domain dari
"sesi" pada permintaan: transaksi tunai hanya boleh diproses dari laci yang benar-benar dibuka
pada tanggal bisnis berjalan.

---

## 3. Tahap 1 — Guard Sesi

### 3.1 Komponen yang Dikembangkan

| Berkas | Peran |
|---|---|
| `app\Http\Middleware\PastikanKasTerbuka.php` | Guard sesi kas: validasi pengguna, tanggal bisnis, status sesi (`OPEN`/`PENDING_APPROVAL`/`CLOSED`), pencatatan penolakan ke log audit |
| `bootstrap\app.php` | Alias middleware `kas.terbuka` |
| `routes\web.php` | Pemasangan alias pada 10 rute POST transaksi tunai |

### 3.2 Dokumentasi Fungsi

`PastikanKasTerbuka::handle(Request $request, Closure $next): Response`
memiliki docblock lengkap dengan **Skema Input**, **Skema Output**, **Alur Logika** (7 langkah),
dan **Kasus Penggunaan**.

| Input | Tipe | Keterangan |
|---|---|---|
| `$request` | `Illuminate\Http\Request` | Request yang sudah melewati middleware `auth` |
| `$next` | `Closure(Request): Response` | Penerus rantai middleware |

| Output | Kondisi |
|---|---|
| `$next($request)` | Sesi `OPEN` valid, atau pengguna bukan teller |
| HTTP 401 | Tidak ada pengguna terautentikasi |
| HTTP 403 | Tanpa sesi kas pada tanggal bisnis berjalan |
| HTTP 403 | Sesi `PENDING_APPROVAL` (opname menunggu Manajer) |
| HTTP 403 | Sesi `CLOSED` (sudah ditutup) |

Alur logika: (1) ambil pengguna → 401 bila kosong; (2) non-teller diloloskan; (3) cari
`kas_harian` untuk (`user_id`, tanggal hari ini); (4) tidak ada → 403; (5) `PENDING_APPROVAL` → 403;
(6) `CLOSED` → 403; (7) `OPEN` → lanjut controller. Setiap penolakan dicatat `Log::warning`
(user, rute, metode, tanggal, status).

`PastikanKasTerbuka::catatPenolakan()` — helper privat, didokumentasikan, menulis satu baris
log audit per penolakan.

### 3.3 Skenario Pengujian (18 test — `tests\Feature\KasGuardSesiTest.php`)

**Unit (8):**
1. Permintaan tanpa pengguna → 401
2. Pengguna non-teller diloloskan tanpa sesi kas
3. Sesi `OPEN` diloloskan
4. Tanpa sesi kas → 403
5. Sesi `PENDING_APPROVAL` → 403
6. Sesi `CLOSED` → 403
7. Sesi kemarin tidak dianggap sah
8. Penolakan tercatat pada log audit

**Integrasi/HTTP (10):**
9. Sesi valid meloloskan transaksi sampai controller
10. Tanpa sesi kas → 403
11. Sesi `PENDING_APPROVAL` → 403
12. Sesi `CLOSED` → 403
13. Pengguna non-teller tidak dihalangi guard
14. Tanpa autentikasi (HTML) → redirect login
15. Tanpa autentikasi (JSON) → 401
16. **Sesi kedaluwarsa** (hash tidak cocok) → redirect login
17. **Token palsu** (ID pengguna tidak terdaftar) → redirect login
18. Tanpa sesi (JSON) → 403

### 3.4 Pemetaan Cakupan ≥ 95%

Driver coverage (Xdebug/PCOV) tidak tersedia pada lingkungan ini, sehingga cakupan diukur dengan
**pemetaan cabang eksplisit** terhadap seluruh kode yang dapat dieksekusi:

| Metrik | Hasil |
|---|---|
| Method publik `PastikanKasTerbuka` | 1/1 (100%) |
| Method privat `catatPenolakan` | 1/1 (100%) |
| Cabang `handle()` | 6/6 (100%) — 401, non-teller, tanpa sesi, pending, closed, open |
| Skenario wajib pengguna | 4/4 (100%) — valid, kedaluwarsa, token palsu, tanpa token |
| **Estimasi cakupan modul** | **100% (≥ 95% terpenuhi)** |

---

## 4. Tahap 2 — Aturan Lintas Hari

### 4.1 Komponen yang Dikembangkan

| Berkas | Perubahan |
|---|---|
| `app\Services\AturanLintasHariService.php` | **Baru** — 11 method publik + 5 privat, seluruhnya berdocblock |
| `app\Http\Controllers\TellerController.php` | `openingForm()` & `openingStore()` memakai service; opening ditolak bila ada sesi tertunda |
| `app\Http\Controllers\Teller\ClosingController.php` | `index()` menerima `?tanggal`; `storeOpname()` memvalidasi tanggal sesi; `batalOpname()` mempertahankan konteks tanggal |
| `app\Policies\TellerPolicy.php` | `tutupKas()` menerima `?string $tanggal` sehingga Closing sesi lampau diizinkan |
| `resources\views\teller\kas\closing.blade.php` | Date picker sesi, badge "SESI LINTAS HARI", daftar sesi tertunda, `@can` meneruskan `$tanggal` |
| `resources\views\teller\opening.blade.php` | Peringatan proaktif sesi yang belum ditutup |

### 4.2 Dokumentasi Fungsi

Seluruh method publik memiliki docblock berisi **Skema Input**, **Skema Output**, **Alur Logika**,
dan **Kasus Penggunaan**.

| Method | Signature | Keluaran | Kasus Penggunaan |
|---|---|---|---|
| `zonaWaktu` | `(): string` | Nama zona IANA | Sumber tunggal zona operasional |
| `sekarang` | `(): CarbonImmutable` | Waktu sekarang di zona operasional | Titik acuan seluruh perhitungan |
| `tanggalBisnis` | `(CarbonInterface\|string\|null $waktu = null, int $batasTengahMalamMenit = 0): string` | Tanggal `Y-m-d` | Guard sesi, Opening, Closing, transaksi lewat tengah malam |
| `selisihHari` | `(CarbonInterface\|string $dari, CarbonInterface\|string $sampai): int` | Positif/negatif/0 | Umur sesi, penentuan sesi tertunda, uji kabisat |
| `adalahHariKerja` | `(CarbonInterface\|string $tanggal): bool` | `true` bila operasional | Validasi tanggal jatuh tempo |
| `hariKerjaBerikutnya` | `(CarbonInterface\|string $tanggal): CarbonImmutable` | Hari kerja ≥ acuan | Geser jatuh tempo dari akhir pekan |
| `hariKerjaSebelumnya` | `(CarbonInterface\|string $tanggal): CarbonImmutable` | Hari kerja ≤ acuan | Strategi mundur penjadwalan |
| `tanggalSesiSah` | `(string $tanggal, ?string $tanggalBisnis = null): bool` | `true` bila format sah & ≤ hari ini | Validasi input tanggal Closing |
| `normalisasiTanggal` | `(?string $tanggal, ?string $acuan = null): string` | Tanggal aman | Sanitasi query string halaman Closing |
| `sesiBelumTutupSebelum` | `(int\|string $userId, ?string $tanggalBisnis = null): ?KasHarian` | Sesi tertunda terbaru | Blokir Opening lintas hari |
| `daftarSesiBelumTutup` | `(int\|string $userId, ?string $tanggalBisnis = null): Collection` | Daftar sesi tertunda | Tautan penyelesaian di halaman Closing |

Privat: `querySesiBelumTutup`, `cariHariKerja`, `keZonaWaktu`, `awalHari`, `parseTanggal` —
semuanya berdocblock dan tercakup tidak langsung lewat method publik.

**Aturan bisnis yang ditegakkan:**
- Tanggal bisnis selalu dihitung pada zona operasional `config('app.timezone')` (`Asia/Jakarta`),
  bukan zona server/browser.
- Selisih hari dihitung dari **awal hari**, sehingga 23:59:59 → 00:00:01 = 1 hari.
- Cut-off tengah malam dijepit ke rentang `0..1439` menit.
- Hari kerja = bukan Sabtu/Minggu dan tidak ada pada `hari_liburs` aktif.
- Sesi `OPEN`/`PENDING_APPROVAL` sebelum tanggal bisnis = pelanggaran lintas hari → Opening diblokir.
- Tanggal sesi masa depan atau format tidak sah ditolak/dinormalisasi.

### 4.3 Skenario Pengujian

#### A. Unit — `tests\Feature\AturanLintasHariServiceTest.php` (28 test)

*Zona waktu & tanggal bisnis (7):* konfigurasi zona, konversi UTC→operasional, string dianggap waktu
lokal, cut-off menggeser hari, batas tepat tidak digeser, clamp cut-off, **tanpa DST**.

*Selisih hari & kabisat (6):* positif/negatif/nol, tidak terpengaruh jam, memakai zona operasional,
melewati tahun kabisat (366 hari), 29 Februari 2028 = hari kerja, overflow `2027-02-29` ditolak.

*Hari kerja / akhir pekan / libur (7):* Sabtu–Minggu ditolak, hari libur aktif ditolak, hari libur
nonaktif diabaikan, lompat ke Senin, pertahankan hari kerja, lompati libur beruntun, mundur dari
akhir pekan.

*Validasi & normalisasi tanggal (4):* hanya sampai hari ini, format salah ditolak, input tidak sah
kembali ke acuan, tanggal lampau dipertahankan.

*Sesi belum ditutup (4):* hanya `OPEN`/`PENDING_APPROVAL` sebelum acuan, urutan terbaru dahulu,
`null` bila bersih, mengabaikan milik teller lain.

#### B. End-to-End — `tests\Feature\AturanLintasHariE2ETest.php` (13 test)

*Opening diblokir lintas hari (4):* sesi kemarin `OPEN`, sesi kemarin `PENDING_APPROVAL`,
diizinkan bila kemarin `CLOSED`, peringatan pada halaman Opening.

*Tengah malam (2):* transaksi pukul 00:15 tetap milik tanggal sebelumnya; Closing sesi kemarin tetap
dapat diakses setelah tengah malam.

*Closing lintas hari (3):* halaman menampilkan sesi lampau tanpa sesi hari ini (gate `tutupKas`
mendukung tanggal lampau), `storeOpname` untuk sesi lampau, `batalOpname` kembali ke tanggal sesi.

*Validasi tanggal (3):* tanggal masa depan ditolak, format salah menghasilkan error validasi,
tanggal masa depan pada URL dinormalisasi ke hari ini.

*Akhir pekan (1):* Sabtu tidak memblokir Buka Kas (aturan akhir pekan hanya untuk jatuh tempo).

### 4.4 Pemetaan Cakupan

| Metrik | Hasil |
|---|---|
| Method publik `AturanLintasHariService` | 11/11 (100%) |
| Method privat | 5/5 (100%) |
| Cabang `tanggalBisnis` (null/string/objek, cut-off aktif/nonaktif, clamp) | 100% |
| Cabang `parseTanggal` (format salah, overflow, sah) | 100% |
| Cabang `cariHariKerja` (sudah kerja, geser, batas iterasi) | 100% |
| Skenario wajib pengguna | 5/5 — tengah malam, akhir pekan, zona waktu, kabisat, DST (N/A) |
| **Estimasi cakupan modul** | **100% (≥ 95% terpenuhi)** |

### 4.5 Catatan Daylight Saving Time (DST)

`Asia/Jakarta` memakai offset tetap **+07:00** dan **tidak menerapkan DST**, sehingga edge case
perubahan waktu musim panas **tidak relevan** untuk sistem ini. Asumsi tersebut tidak dibiarkan
implisit: `test_zona_operasional_tanpa_perubahan_dst` mengunci perilaku pada Januari dan Juli
(keduanya `+07:00`), sehingga bila zona operasional diubah ke wilayah ber-DST, test akan gagal
dan memaksa peninjauan ulang.

---

## 5. Temuan Bug & Langkah Perbaikan

### 5.1 Bug yang Ditemukan Saat Pengujian

| # | Modul | Temuan | Dampak | Perbaikan |
|---|---|---|---|---|
| B1 | Tahap 2 | Gate `TellerPolicy::tutupKas` hanya memeriksa sesi pada **hari ini** | Closing sesi lampau tidak menampilkan form opname | `tutupKas(User, ?int, ?string $tanggal)` + helper `adaKasPadaTanggal()` berbasis `whereDate`; dua `@can` meneruskan `$tanggal` |
| B2 | Tahap 2 | `batalOpname()` kembali ke halaman Closing **hari ini** | Teller kehilangan konteks setelah membatalkan opname lintas hari | Redirect kembali ke `?tanggal=<tanggal sesi>` |
| B3 | Tahap 2 | Redirect `storeOpname()` selalu menambahkan `?tanggal=` walau sesi hari ini | Regresi 3 test lama (URL berubah) | Parameter hanya disertakan bila tanggal ≠ tanggal bisnis |
| B4 | Tahap 2 | Teks Blade berubah menjadi "Belum ada Opening **Kas** hari ini" | Regresi 1 test lama | Wording dikembalikan: "Belum ada Opening hari ini" / "pada tanggal …" |
| B5 | Pengujian | Asumsi test `hariKerjaSebelumnya(Senin)` = Jumat sebelumnya | 1 test gagal; implementasi sebenarnya benar | Test dikoreksi: hari kerja dipertahankan bila sudah hari kerja; mundur hanya dari akhir pekan |
| B6 | Pengujian | `actingAs()` menaruh objek User pada cache guard sehingga skenario "token palsu" tidak tereksekusi | Test token palsu lolos semu | `$this->app['auth']->forgetGuards()` sebelum request |

### 5.2 Regresi & Resolusinya

Setelah perubahan Tahap 2, suite kas menunjukkan **5 failed, 89 passed**. Penyebabnya murni
perubahan teks/URL (bukan perubahan logika uang). Setelah perbaikan B3 dan B4, hasilnya:

```
Tests: 135 passed (805 assertions) — 17,70s
```

Kemudian verifikasi suite penuh:

```
Tests: 5 skipped, 1718 passed (7824 assertions) — 244,48s
[OK] Pengujian LULUS.
```

Baseline sebelum pekerjaan ini: `5 skipped, 1677 passed`. Selisih **+41 test** persis sama dengan
jumlah test baru Tahap 2 (28 unit + 13 E2E) — tidak ada test lama yang hilang atau dinonaktifkan.

---

## 6. Keamanan & Kinerja

**Keamanan**
- Guard menolak request tanpa pengguna (401) dan tanpa sesi kas sah (403) — pertahanan berlapis
  di atas middleware `auth`.
- Validasi tanggal memakai `date_format:Y-m-d` + pemeriksaan overflow, mencegah input seperti
  `2027-02-29` atau tanggal masa depan.
- Perhitungan total fisik dan selisih opname tetap dilakukan **ulang di backend**; angka dari
  browser tidak dipercaya.
- Setiap penolakan guard dicatat ke log audit (user, rute, metode, tanggal, status).
- Tidak ada kredensial/secret baru yang diperkenalkan; tidak ada perubahan konfigurasi produksi.

**Kinerja**
- Guard menambah **satu** query `EXISTS`/`first` berindeks (`user_id`, `tanggal` — ada unique index).
- `daftarSesiBelumTutup` dibatasi `whereDate` + `whereIn` + `orderByDesc` pada kolom terindeks.
- Tidak ditemukan N+1 pada halaman Opening/Closing; relasi opname dimuat per sesi.
- Dampak waktu suite: tidak signifikan (135 test = 17,7 s; suite penuh 244 s).

**Peringatan yang tersisa (bukan dari modul ini):** PHPUnit menampilkan peringatan deprecation
"Metadata found in doc-comment" pada `KeamananHeaderTest`, `NavigasiWewenangPeranTest`, dan
`Tahap1StabilisasiKritisTest`. Peringatan ini **sudah ada sebelumnya**, tidak terkait Tahap 1/2,
dan tidak memengaruhi hasil. Rekomendasi: migrasi ke atribut PHPUnit (`#[Test]`) pada kesempatan
terpisah.

---

## 7. Risiko Residual

| Risiko | Tingkat | Catatan |
|---|---|---|
| `PastikanKasTerbuka` memakai `now()->toDateString()` (bukan service) | Rendah | Ekuivalen dengan `tanggalBisnis()` selama cut-off = 0 (nilai saat ini). Bila cut-off diaktifkan, guard perlu di-wire ke service. |
| `KasHarian::ringkasanPosisiKas()` fallback `now()` bila `$tanggal` null | Rendah | Semua pemanggil Tahap 2 sudah mengirim tanggal eksplisit. |
| `TestCase::bukaSesiKas()` memakai `updateOrCreate` | Rendah | Dapat menyisakan sesi lama pada data dev; test Tahap 2 sudah membersihkan sesi miliknya sendiri. |
| Belum ada driver coverage | Rendah | Cakupan diukur via pemetaan cabang eksplisit (bagian 3.4 & 4.4). Disarankan memasang PCOV/Xdebug di CI. |

---

## 8. Rollback

Proyek bukan repositori Git, sehingga rollback dilakukan manual:

1. Hapus `app\Services\AturanLintasHariService.php`.
2. Kembalikan `TellerController::openingForm()`/`openingStore()` ke versi sebelum integrasi
   (tanpa `sesiBelumTutupSebelum`).
3. Kembalikan `ClosingController` ke signature `index(): View` dan hapus parameter `tanggal`.
4. Kembalikan `TellerPolicy::tutupKas(User, ?int)` tanpa parameter `$tanggal`.
5. Hapus `PastikanKasTerbuka` + alias `kas.terbuka` + pemasangan pada rute POST tunai.
6. Hapus `tests\Feature\AturanLintasHariServiceTest.php`, `AturanLintasHariE2ETest.php`,
   `KasGuardSesiTest.php`.
7. `php artisan view:clear; php artisan view:cache`.

Tidak ada migrasi baru pada Tahap 1/2, sehingga rollback tidak menyentuh skema/data.

---

## 9. Kesimpulan

- **Tahap 1** dan **Tahap 2** terimplementasi, terdokumentasi, dan teruji.
- Seluruh skenario wajib pengguna tercakup: sesi valid, sesi kedaluwarsa, token palsu, tanpa token,
  akses tanpa otorisasi; transaksi melewati tengah malam, batasan akhir pekan, penyesuaian zona
  waktu, tahun kabisat, serta DST (dinyatakan tidak relevan dan dikunci dengan test).
- Tidak ada test gagal, tidak ada regresi, tidak ada temuan keamanan baru.
- **Siap melanjutkan ke tahap perbaikan berikutnya.**
