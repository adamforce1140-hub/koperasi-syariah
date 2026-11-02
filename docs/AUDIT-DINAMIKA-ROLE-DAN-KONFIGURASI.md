# Audit Dinamika Sistem per Role

**Aplikasi:** Koperasi Syariah
**Tanggal audit:** 19 Oktober 2026
**Ruang lingkup:** seluruh peran (`dps`, `pengurus`, `manajer`, `ao`, `teller`, `super_admin`, `admin`, `anggota`/`nasabah`)
**Sifat dokumen:** temuan audit — **belum ada perubahan kode**
**Rujukan baris:** berlaku untuk kondisi kode saat audit

---

## 1. Tujuan & Pertanyaan Audit

Audit ini menjawab satu pertanyaan:

> Bagian mana dari sistem yang **kurang dinamis**, sehingga perubahan kebijakan koperasi
> (menambah peran, mengubah wewenang, menambah produk, menambah parameter) masih
> menuntut perubahan kode dan deploy?

Kriteria "dinamis" yang dipakai:

| Tingkat | Definisi |
|---|---|
| **Dinamis penuh** | Perubahan cukup lewat konfigurasi/DB/UI, tanpa deploy |
| **Semi-dinamis** | Nilai bisnis ada di DB, tetapi bentuk UI/label/alur masih di kode |
| **Statis** | Perubahan menuntut edit kode, dan bila terlewat gagal secara senyap |

---

## 2. Ringkasan Eksekutif

Sistem **sudah memiliki pondasi SSOT yang baik**:

- `config/role_hierarki.php` + `App\Support\HierarkiRole` → hierarki, rantai approval, batas nominal, frekuensi, akses data.
- `App\Support\Setting` + tabel `kebijakan_koperasis` → parameter PSAK (CKPN, kolektibilitas, SHU, zakat, tazir).
- `config/alur_pembiayaan.php` + `MatriksApproval` → batas keputusan pembiayaan.
- `TahunBukuService` → awal/akhir tahun buku.

**Masalah utamanya:** SSOT tersebut baru dipakai untuk **angka dan wewenang**, belum dipakai untuk **bentuk UI, menu, rute, dan label**. Akibatnya:

1. Menambah peran baru → **sidebar kosong** (tidak error, hanya hilang).
2. Menambah parameter baru → harus menyentuh **5 tempat**.
3. Menambah produk simpanan → beberapa jalur masih memakai **nama literal**.
4. Mengubah alur approval → satu titik masih **mem-bypass** rantai di `HierarkiRole`.

### Rekapitulasi temuan

| Tingkat | Jumlah | ID |
|---|---|---|
| Kritis | 4 | DYN-01, DYN-02, DYN-05, DYN-18 |
| Tinggi | 5 | DYN-03, DYN-04, DYN-08, DYN-16, DYN-19 |
| Sedang | 6 | DYN-06, DYN-07, DYN-09, DYN-10, DYN-12, DYN-15 |
| Rendah | 4 | DYN-11, DYN-13, DYN-14, DYN-17 |

**Status keseluruhan: SELESAI** — 19 dari 19 temuan (DYN-01 … DYN-19) sudah ditutup. Verifikasi akhir 2026-10-22: suite penuh **1865 passed (8838 assertions)** dan `php artisan rbac:periksa` **0 temuan** (9 peran, 43 izin).

---

## 3. Matriks Peran yang Berlaku

Sumber: `config/role_hierarki.php` (`peran`, `level`, `kategori`, `penyetuju`).

| Peran | Level | Kategori | Penyetuju | Operasional |
|---|---:|---|---|---|
| `dps` | 60 | pengawas | tidak | tidak |
| `pengurus` | 50 | pengurus | ya | ya |
| `manajer` | 40 | pengelola | ya | ya |
| `ao` | 30 | pengelola | — | ya |
| `teller` | 20 | pengelola | — | ya |
| `super_admin` | 15 | sistem | tidak | tidak |
| `admin` | 10 | sistem | tidak | tidak |
| `anggota` / `nasabah` | — | anggota | tidak | tidak |

Catatan: `super_admin` sengaja **tidak** masuk rantai approval dan tidak memegang hak tulis jurnal (pemisahan tugas).

---

## 4. Temuan Detail

Format tiap temuan: **Bukti → Dampak → Rekomendasi → Risiko perbaikan**.

---

### DYN-01 — `users.role` bertipe ENUM MySQL (KRITIS)

**Lokasi**

- [2024_01_01_000001_create_users_table.php:17](file:///c:/laragon/www/koperasi_syariah/database/migrations/2024_01_01_000001_create_users_table.php#L17)
- [2024_01_01_000017_add_pengurus_to_users_role_enum.php:9](file:///c:/laragon/www/koperasi_syariah/database/migrations/2024_01_01_000017_add_pengurus_to_users_role_enum.php#L9)
- [2024_01_01_000018_add_ao_dps_to_users_role_enum.php:9](file:///c:/laragon/www/koperasi_syariah/database/migrations/2024_01_01_000018_add_ao_dps_to_users_role_enum.php#L9)
- [2026_10_02_000100_add_nasabah_to_users_role_enum.php:28](file:///c:/laragon/www/koperasi_syariah/database/migrations/2026_10_02_000100_add_nasabah_to_users_role_enum.php#L28)
- [2026_10_14_000100_add_super_admin_to_users_role_enum.php:25](file:///c:/laragon/www/koperasi_syariah/database/migrations/2026_10_14_000100_add_super_admin_to_users_role_enum.php#L25)

**Bukti**

```php
DB::statement(
    "ALTER TABLE users MODIFY COLUMN role "
    . "ENUM('super_admin','admin','teller','manajer','pengurus','anggota','ao','dps','nasabah') "
    . "NOT NULL DEFAULT 'anggota'"
);
```

`down()` juga harus menurunkan data lebih dulu (`where('role','super_admin')->update(['role'=>'admin'])`)
sebelum menyempitkan ENUM.

**Dampak**

- Setiap peran baru = migrasi SQL mentah baru. Ada **4 migrasi** yang isinya hanya menambah nilai ENUM.
- Peran baru tidak bisa dikelola dari UI.
- Bila migrasi terlewat: insert/update user dengan peran baru **gagal atau terpotong**, dan rollback berisiko kehilangan data peran.
- ENUM juga mengikat sistem ke MySQL (portabilitas ke PostgreSQL/SQLite untuk pengujian terbatas).

**Rekomendasi**

- Ubah `users.role` menjadi `VARCHAR(32)` + validasi di aplikasi melalui `HierarkiRole::dikenal()`.
- Tambahkan tabel `roles` (opsional, bila kelak butuh metadata per peran dari DB).
- Sediakan `php artisan role:daftar` untuk menampilkan peran dikenal vs peran terpakai di DB.

**Risiko perbaikan:** sedang-tinggi (menyentuh kolom inti + data user). **Kerjakan terakhir**, dengan migrasi bertahap: tambah kolom → backfill → alihkan kode → hapus ENUM lama.

**Status: SELESAI (Paket 5)** — 2026-10-19

| Berkas | Perubahan |
|---|---|
| [2026_10_19_000100_users_role_enum_to_varchar.php](file:///c:/laragon/www/koperasi_syariah/database/migrations/2026_10_19_000100_users_role_enum_to_varchar.php) | Baru. `up()`: `ALTER TABLE users MODIFY COLUMN role VARCHAR(32) NOT NULL DEFAULT 'anggota'` (dilewati bila driver bukan MySQL). `down()`: menurunkan nilai peran yang tidak dikenal ENUM lama ke `anggota` lebih dulu, lalu mengembalikan ENUM. Tidak ada data yang disalin ulang — MySQL mempertahankan nilai saat ALTER. |
| [User.php](file:///c:/laragon/www/koperasi_syariah/app/Models/User.php#L48-L60) | Hook `booted()` → `saving()` menolak peran tak dikenal dengan `InvalidArgumentException`. Daftar peran sah dibaca dari `HierarkiRole::dikenal()` (SSOT `config/role_hierarki.php`), bukan dari skema. |
| [PetaAksesPeranMenuTest.php](file:///c:/laragon/www/koperasi_syariah/tests/Feature/PetaAksesPeranMenuTest.php#L98-L108) | `nilaiPeranSah()` dialihkan dari pembacaan ENUM skema ke `HierarkiRole::peran()`. |
| [LaporanKeuanganRbacTest.php](file:///c:/laragon/www/koperasi_syariah/tests/Feature/LaporanKeuanganRbacTest.php) | Penyesuaian daftar peran uji agar tidak lagi bergantung pada ENUM. |

*Hasil uji:* kelompok RBAC/navigasi **118 passed**; suite penuh **5 skipped, 1656 passed (7522 assertions)**.

*Rollback:* `php artisan migrate:rollback --step=1` (mengembalikan ENUM; peran di luar daftar lama otomatis menjadi `anggota`). Bila dump pra-migrasi tersedia, pulihkan `users` dari dump tersebut. Guard `User::booted()` boleh dibiarkan — ia hanya menolak nama peran yang memang tidak ada di SSOT.

---

### DYN-02 — Menu sidebar di-`match` per peran (KRITIS)

**Lokasi:** [NavigationService.php:23-41](file:///c:/laragon/www/koperasi_syariah/app/Services/NavigationService.php#L23-L41)

**Bukti**

```php
return match ($role) {
    'teller'      => static::menuTeller(),
    'ao'          => static::menuAO(),
    'manajer'     => static::menuManajer(),
    'pengurus'    => static::menuPengurus(),
    'dps'         => static::menuDPS(),
    'anggota'     => static::menuNasabah(),
    'nasabah'     => static::menuNasabah(),
    'admin'       => static::menuAdmin(),
    'super_admin' => static::menuSuperAdmin(),
    default       => [],
};
```

8 metode menu terpisah: `menuTeller()` (baris 42), `menuAO()` (109), `menuManajer()` (176), `menuPengurus()` (236), `menuAdmin()` (375), `menuSuperAdmin()` (404), `menuNasabah()` (475), `menuDPS()` (514).

**Dampak**

- Peran baru login → **sidebar kosong tanpa pesan error**. Pengguna mengira sistem rusak.
- Ini **bertentangan langsung** dengan klaim di `config/role_hierarki.php` baris 24-26: *"menambah peran baru cukup dengan menambah satu entri… Tidak ada kode yang perlu diubah."*
- Duplikasi menu antar peran (mis. `anggota`/`nasabah` → satu metode) menandakan menu seharusnya berbasis **izin**, bukan berbasis **nama peran**.

**Rekomendasi**

- Definisikan menu sebagai data: `config/navigasi.php` dengan atribut `izin` (mis. `pembiayaan.approve`, `kas.buka`).
- `NavigationService` memfilter menu berdasarkan izin efektif peran, bukan `match`.
- Tambahkan fallback eksplisit: peran dikenal tapi tidak punya menu → tampilkan menu minimal + log peringatan (bukan `[]`).

**Risiko perbaikan:** rendah (presentasi saja, tidak menyentuh uang/jurnal).

---

### DYN-03 — Ikon & badge sidebar hardcode di Blade (TINGGI)

**Lokasi:** [sidebar.blade.php:24-95](file:///c:/laragon/www/koperasi_syariah/resources/views/layouts/sidebar.blade.php#L24-L95)

**Bukti**

```php
$ikonMap = [ /* emoji per nama ikon */ ];
$badgeMap = [
    'tazir_pending_ao'        => fn () => ...,
    'closing_pending_manajer' => fn () => ...,
    'autodebit_pending'       => fn () => ...,
    'super_admin_antrean'     => fn () => ...,
];
```

**Dampak**

- Badge baru = edit view.
- **Query database berada di lapisan presentasi** (closure di Blade), sehingga sulit diuji dan berpotensi N+1 saat menu bertambah.
- Ikon memakai emoji di view; tidak bisa diganti tanpa deploy.

**Rekomendasi**

- Pindahkan `ikonMap` ke konfigurasi/komponen (`config/navigasi.php`).
- Pindahkan `badgeMap` ke `NavigationService` sebagai resolver bernama, lalu view hanya memanggil nilai yang sudah dihitung.
- Sertakan `badge` sebagai bagian definisi menu (data), bukan pemetaan terpisah.

**Risiko perbaikan:** rendah.

---

### DYN-04 — Pemeriksaan peran literal di Blade (TINGGI)

**Lokasi**

- [neraca.blade.php:34](file:///c:/laragon/www/koperasi_syariah/resources/views/laporan/neraca.blade.php#L34) — `role === 'pengurus'`
- [neraca.blade.php:370](file:///c:/laragon/www/koperasi_syariah/resources/views/laporan/neraca.blade.php#L370) — `role === 'super_admin'`
- [manajer/closing/index.blade.php:16](file:///c:/laragon/www/koperasi_syariah/resources/views/manajer/closing/index.blade.php#L16) — `role === 'manajer'`
- [pengelola/pengajuan-pinjaman.blade.php:104,115,130,132](file:///c:/laragon/www/koperasi_syariah/resources/views/pengelola/pengajuan-pinjaman.blade.php#L104-L132)
- [pengelola/pengajuan-pinjaman-detail.blade.php:187,200](file:///c:/laragon/www/koperasi_syariah/resources/views/pengelola/pengajuan-pinjaman-detail.blade.php#L187-L200)
- [dashboard/partials/pengurus-limit.blade.php:22-25](file:///c:/laragon/www/koperasi_syariah/resources/views/dashboard/partials/pengurus-limit.blade.php#L22-L25) — warna badge per `$l->role`

> *Pembaruan 2026-10-21:* pemeliharaan limit per peran (termasuk `limit_harian`, status approval, dan nilai efektif) kini dilakukan di **menu Pengurus → Pengguna & Hak Akses** (`pengurus/users.blade.php`, guard `izin:master.data`). Partial di atas tetap dipakai dasbor sebagai ringkasan baca-saja, sehingga pemeriksaan `$l->role` di dalamnya masih relevan.

**Dampak**

- Tombol, kolom, dan warna tampil salah untuk peran baru atau peran gabungan.
- Perubahan wewenang (mis. `admin` diberi akses lihat neraca) tidak tercermin di UI meski middleware sudah dilonggarkan.

**Pembanding yang sudah benar (pola acuan)**

- [pengelola/approval/show.blade.php:139](file:///c:/laragon/www/koperasi_syariah/resources/views/pengelola/approval/show.blade.php#L139)
- [pengelola/approval/index.blade.php:81](file:///c:/laragon/www/koperasi_syariah/resources/views/pengelola/approval/index.blade.php#L81)
- [manajer/approval-transaksi.blade.php:157](file:///c:/laragon/www/koperasi_syariah/resources/views/manajer/approval-transaksi.blade.php#L157)

Ketiganya memakai `in_array(auth()->user()->role, $peranPemutus, true)` — variabel dikirim controller, bukan literal.

**Rekomendasi**

- Teruskan `$peranBerwenang` / `$peranPemutus` dari controller (atau view composer) yang nilainya berasal dari `HierarkiRole::penyetuju()` / `statusMenunggu()`.
- Untuk warna badge per peran, gunakan `HierarkiRole::label()` + kelas gaya dari konfigurasi.

**Risiko perbaikan:** rendah.

---

### DYN-05 — Alur approval pembiayaan mem-bypass `HierarkiRole` (KRITIS)

**Lokasi:** [ManajerController.php:283-284](file:///c:/laragon/www/koperasi_syariah/app/Http/Controllers/Dashboard/ManajerController.php#L283-L284)

**Bukti**

```php
$target = $request->input('target', 'pengurus'); // pengurus / dps
$newStatus = $target === 'dps'
    ? Pembiayaan::STATUS_PENDING_DPS
    : Pembiayaan::STATUS_PENDING_PENGURUS;
```

**Dampak**

- Target approval ditentukan **oleh input request**, bukan oleh `HierarkiRole::rantai()` / `statusMenunggu()`.
- Menambah tahap approval baru (mis. `komite`) tidak akan pernah terpakai di jalur ini.
- Risiko kepatuhan: pengirim dapat memilih penerus wewenang yang tidak sesuai matriks, selama lolos validasi request.
- Alur menjadi **tidak konsisten**: sebagian jalur memakai matriks, jalur ini memakai input.

**Rekomendasi**

- Turunkan `$newStatus` dari `HierarkiRole::statusMenunggu($target)` (atau `rantai()`), bukan ternary.
- Validasi `$target` terhadap `HierarkiRole::dikenal()` + `penyetuju()`; tolak peran yang tidak berada di rantai.
- Tambahkan test: target di luar rantai harus 422, bukan diam-diam jatuh ke `pengurus`.

**Risiko perbaikan:** sedang (menyentuh alur keputusan, bukan nominal/jurnal). Wajib test regresi.

---

### DYN-06 — Label & warna kolektibilitas hardcode (SEDANG)

**Lokasi:** [ManajerController.php:847-874](file:///c:/laragon/www/koperasi_syariah/app/Http/Controllers/Dashboard/ManajerController.php#L847-L874)

**Bukti**

```php
return match ($kode) {
    'kol1'  => 'Lancar (Kol-1)',
    'kol2'  => 'Dalam Perhatian Khusus (Kol-2)',
    'kol3'  => 'Kurang Lancar (Kol-3)',
    'kol4'  => 'Diragukan (Kol-4)',
    default => 'Macet (Kol-5)',
};
```

`gayaKolektibilitas()` juga mengunci kelas Tailwind + inline style per kode.

**Dampak**

- **Inkonsisten**: ambang hari sudah dinamis (`Setting::ambangKolektibilitas()`) dan persentase CKPN sudah dinamis (`Setting::persenCkpn()`), tetapi label & warna tidak.
- Jika koperasi mengubah jumlah kategori (mis. menambah Kol-6 atau menggabungkan kategori), UI tetap menampilkan 5 label lama.
- Label juga dipakai di dashboard manajer (baris 764, 822), sehingga perubahan menyebar.

**Rekomendasi**

- Tambahkan metadata label & gaya pada `Setting` (mis. `kolektibilitas_kol1_label`, `kolektibilitas_kol1_warna`), lalu `labelKolektibilitas()` membaca metadata tersebut.
- Bila tidak ingin menambah kolom: definisikan peta label di `config/kolektibilitas.php` yang kuncinya **diturunkan dari daftar kode aktif** di `Setting`.
- Jangan ubah ambang/rumus — hanya sumber label.

**Risiko perbaikan:** rendah.

---

### DYN-07 — Special-case `teller` di `HierarkiRole` (SEDANG)

**Lokasi:** [HierarkiRole.php:388-389](file:///c:/laragon/www/koperasi_syariah/app/Support/HierarkiRole.php#L388-L389)

**Bukti**

```php
// Kekhususan teller (mempertahankan perilaku sistem saat ini)
if ($peran === 'teller' && in_array($jenisTransaksi, ['setor_tunai', 'tarik_tunai'], true)) {
    $kebijakan = static::kebijakanTellerNominal($jenisTransaksi);
    if ($kebijakan !== null) {
        $nilai['limit_per_transaksi'] = $kebijakan;
    }
}
```

**Dampak**

- Batas nominal teller punya jalur perhitungan sendiri di luar tabel `limit_transaksi_role`.
- Peran lain yang secara operasional setara teller (mis. `kasir_pusat`) tidak akan mewarisi kebijakan ini tanpa mengubah kode.
- SSOT menjadi bercabang: dua mekanisme untuk pertanyaan yang sama ("berapa batas transaksi peran X untuk transaksi Y").

**Rekomendasi**

- Pindahkan kebijakan teller ke data: kolom/entri `limit_transaksi_role` dengan kunci `(peran, jenis_transaksi)`.
- Bila rumusnya memang berbeda, jadikan **resolver berbasis konfigurasi** (`config/role_hierarki.php` → `kebijakan_khusus`), bukan `if` per nama peran.

**Risiko perbaikan:** sedang (menyentuh batas transaksi). Wajib test batas teller setor/tarik.

---

### DYN-08 — Form kebijakan ±63 input ditulis manual (TINGGI)

**Lokasi:** [pengurus/kebijakan.blade.php](file:///c:/laragon/www/koperasi_syariah/resources/views/pengurus/kebijakan.blade.php)

**Bukti**

- 63 atribut `name="..."` ditulis satu per satu (`shu_pct_*`, `kolektibilitas_kol1..4_hari`, `ckpn_persen_kol1..5`, `tazir_*`, `zakat_*`, `akad_*`, dst.).
- `App\Support\Setting` sudah memiliki **267 baris metadata** (DEFAULTS, TIPE, DESKRIPSI, PRESISI_DESIMAL), tetapi form tidak membacanya.

**Dampak**

- Menambah 1 parameter = menyentuh **5 tempat**: migrasi, `Setting`, `KebijakanKoperasi`, `PengurusController`, Blade.
- Risiko field terlewat: parameter ada di DB tetapi tidak bisa diubah dari UI, atau sebaliknya.
- Validasi tipe/presisi bisa berbeda antara form dan `Setting`.

**Rekomendasi**

- Render form dari metadata `Setting` (loop per grup: SHU, kolektibilitas, CKPN, zakat, tazir, akad).
- Gunakan `TIPE` untuk memilih input (`number`, `select`, `text`) dan `PRESISI_DESIMAL` untuk `step`.
- Pertahankan nama field yang sama agar tidak ada perubahan perilaku penyimpanan.

**Risiko perbaikan:** rendah-sedang (UI + validasi; tidak mengubah rumus). Cocok dikerjakan sebelum DYN-01.

**Status: SELESAI (Paket 3)** — 2026-10-19

*Keputusan desain:* loop render penuh **tidak** diterapkan. Markup tetap per-field karena setiap parameter punya label, teks bantuan, `min`/`max`, dan ikatan JavaScript yang spesifik; mengganti ke loop generik akan menghapus konteks tersebut dan memaksa penulisan ulang 55 aturan validasi `updateKebijakan()`, 13 field `$fieldNominal`, serta 12 ikatan JS. Sebagai gantinya, **setiap atribut `step` diikat ke metadata** sehingga presisi form tidak bisa lagi menyimpang dari presisi `Setting::cast()`.

| Berkas | Perubahan |
|---|---|
| [Setting.php](file:///c:/laragon/www/koperasi_syariah/app/Support/Setting.php) | (a) `'minimal_pengajuan_pembiayaan' => 'decimal'` ditambahkan ke `TIPE` — sebelumnya ada di `DEFAULTS` tetapi absen di `TIPE`, sehingga `Setting::tipe()` salah mengembalikan `'string'`. (b) Accessor publik baru `presisiDesimal(string $key): int` dan `langkah(string $key): string` (turunan atribut `step` HTML: `integer` → `1`, `decimal` → `0.01` / `0.0001`). `PRESISI_DESIMAL` tetap `private`. |
| [kebijakan.blade.php](file:///c:/laragon/www/koperasi_syariah/resources/views/pengurus/kebijakan.blade.php) | 29 atribut `step` numerik literal diganti `{{ \App\Support\Setting::langkah('<key>') }}`. Pengecualian disengaja: `tazir_rate` tetap `step="100"` (pembulatan UI ke ratusan rupiah, bukan presisi tipe data). Tidak ada perubahan pada `name`, `id`, `class`, `value`, `min`, `max`, `required`, pola checkbox, maupun blok `<script>`. |
| [KebijakanFormMetadataTest.php](file:///c:/laragon/www/koperasi_syariah/tests/Feature/KebijakanFormMetadataTest.php) | Baru, 4 test penjaga regresi: (1) setiap `name=` di form terdaftar di `Setting::TIPE`; (2) himpunan field form **tepat sama** dengan `TIPE` − `TIDAK_DI_FORM` (kunci yang sah dikelola modul lain); (3) setiap `step` mengikuti metadata; (4) `presisiDesimal()`/`langkah()` konsisten dengan metadata. |

*33 kunci `TIDAK_DI_FORM`* (dikelola modul lain, sengaja tidak diedit dari form kebijakan): identitas & teknis (6 kunci), kepatuhan PPATK/LTKT (4 kunci), operasional simpanan wajib (8 kunci), dan pemetaan COA kanonik (15 kunci).

*Pembaruan 2026-10-21:* `hari_libur_aktif`, `hari_libur_strategi`, `tanggal_jatuh_tempo_default`, dan `backup_retensi_hari` **kini tampil** di form kebijakan (section "Periode & Kalender" dan "Pencadangan"), sehingga dikeluarkan dari daftar ini. CRUD hari libur tersedia di menu Pengurus → Periode & Kalender.

*Pembaruan 2026-10-22:* `jam_posting_simpanan_wajib` (DYN-17) juga **tampil** di form, sehingga tidak didaftarkan ke `TIDAK_DI_FORM`. Hitungan turun dari 34 → 33.

*Hasil uji:* `KebijakanFormMetadata` **4 passed (41 assertions)**; regresi 10 kelas terkait **201 passed (793 assertions)**; suite penuh **5 skipped, 1651 passed (7517 assertions)** (baseline sebelumnya 1647 → +4 test baru, tidak ada regresi).

*Catatan inkonsistensi (belum diubah, menunggu keputusan):* `limit_pengurus` (baris 327–331) masih memakai literal `$kebijakan->limit_manajer ?? 50000000`, bukan `Setting::limitManajer()`; `#preview-tazir` menghitung dari `tazir_rate` sedangkan saklar sesungguhnya di `TazirService` adalah `financing_late_fee_per_day`.

*Rollback:* pulihkan 3 berkas dari backup (tidak ada migrasi/DDL); `php artisan optimize:clear`.

---

### DYN-09 — Default akad `?? 'Murabahah'` di 10 tempat (SEDANG)

**Lokasi**

| File | Baris |
|---|---|
| [AngsuranTransactionService.php](file:///c:/laragon/www/koperasi_syariah/app/Services/AngsuranTransactionService.php#L473) | 473 |
| [AOController.php](file:///c:/laragon/www/koperasi_syariah/app/Http/Controllers/AOController.php#L541) | 541, 767 |
| [ReversalService.php](file:///c:/laragon/www/koperasi_syariah/app/Services/ReversalService.php#L211) | 211, 437 |
| [TransactionLimitService.php](file:///c:/laragon/www/koperasi_syariah/app/Services/TransactionLimitService.php#L255) | 255 |
| [TellerController.php](file:///c:/laragon/www/koperasi_syariah/app/Http/Controllers/TellerController.php#L1693) | 1693 |
| [PengajuanPembiayaanIntegController.php](file:///c:/laragon/www/koperasi_syariah/app/Http/Controllers/PengajuanPembiayaanIntegController.php#L550) | 550 |
| [PengajuanPembiayaan.php](file:///c:/laragon/www/koperasi_syariah/app/Models/PengajuanPembiayaan.php#L430) | 430 |
| [UjiCobaRoleAO.php](file:///c:/laragon/www/koperasi_syariah/app/Console/Commands/UjiCobaRoleAO.php#L350) | 350 |

**Dampak**

- Akad default diputuskan di kode, bukan di master akad.
- Bila koperasi menambah akad (Mudharabah Musytarakah, IMBT, Istishna), data tanpa akad akan **salah klasifikasi jurnal** dan salah pemetaan COA.
- Sepuluh titik berarti satu perubahan kebijakan = 10 edit.

**Rekomendasi**

- Tambahkan master akad (tabel/config) dengan penanda `default`.
- Ganti seluruh `?? 'Murabahah'` dengan `Akad::default()`.
- Tambahkan test yang memastikan tidak ada literal akad baru muncul.

**Risiko perbaikan:** rendah bila hanya mengganti sumber default dengan nilai yang sama.

**Status: SELESAI (Paket 4)** — 2026-10-19

*Keputusan desain:* rekomendasi "master akad dengan penanda `default`" **tidak** diterapkan karena tabel `PemetaanAkadCoa` tidak memiliki kolom `default`. SSOT baru dibuat di `config/pembiayaan.php` (`'akad_default' => 'Murabahah'`) dan dibaca lewat `Pembiayaan::akadDefault()`.

| Berkas | Perubahan |
|---|---|
| [config/pembiayaan.php](file:///c:/laragon/www/koperasi_syariah/config/pembiayaan.php) | Baru — satu kunci `akad_default`. |
| [Pembiayaan.php](file:///c:/laragon/www/koperasi_syariah/app/Models/Pembiayaan.php) | `akadDefault()` membaca konfigurasi; `aturanValidasiAkad()` memakai daftar akad kanonik. |
| 11 titik di `app/` | `?? 'Murabahah'` diganti `Pembiayaan::akadDefault()` (AngsuranTransactionService, AOController, ReversalService, TransactionLimitService, TellerController, PengajuanPembiayaanIntegController, PengajuanPembiayaan, UjiCobaRoleAO). |
| 2 Blade | Nilai akad default dari `Pembiayaan::akadDefault()`. |
| [AkadDefaultDinamisTest.php](file:///c:/laragon/www/koperasi_syariah/tests/Feature/AkadDefaultDinamisTest.php) | Baru, 5 test — termasuk pemindai yang memastikan tidak ada lagi literal `?? 'Murabahah'` di `app/`. |

*Hasil uji:* `AkadDefaultDinamisTest` **5 passed**. *Rollback:* hapus `config/pembiayaan.php`, pulihkan berkas dari backup, `php artisan optimize:clear`.

---

### DYN-10 — Nama produk simpanan literal (SELESAI)

**Lokasi**

- [AngsuranTransactionService.php:88](file:///c:/laragon/www/koperasi_syariah/app/Services/AngsuranTransactionService.php#L88) — `whereIn('jenis_rekening', ['Simpanan Sukarela', 'Wadiah'])`
- [AutodebitService.php:368](file:///c:/laragon/www/koperasi_syariah/app/Services/AutodebitService.php#L368)
- [DepositoService.php:260](file:///c:/laragon/www/koperasi_syariah/app/Services/DepositoService.php#L260)
- [DisbursementService.php:552](file:///c:/laragon/www/koperasi_syariah/app/Services/DisbursementService.php#L552)
- [CifController.php:259,314,516,542,846](file:///c:/laragon/www/koperasi_syariah/app/Http/Controllers/CifController.php#L259)
- [MandatorySavingsService.php:1501](file:///c:/laragon/www/koperasi_syariah/app/Services/MandatorySavingsService.php#L1501)
- [ShuCalculatorService.php:412,421](file:///c:/laragon/www/koperasi_syariah/app/Services/ShuCalculatorService.php#L412)
- [ManajerController.php:683](file:///c:/laragon/www/koperasi_syariah/app/Http/Controllers/Dashboard/ManajerController.php#L683)
- [PengurusController.php:308](file:///c:/laragon/www/koperasi_syariah/app/Http/Controllers/Dashboard/PengurusController.php#L308) — `['Simpanan Pokok','Simpanan Wajib','Simpanan Sukarela']`
- [RunAutodebetAngsuran.php:130](file:///c:/laragon/www/koperasi_syariah/app/Console/Commands/RunAutodebetAngsuran.php#L130)

**Dampak**

- Master produk `JenisSimpanan` sudah ada (`CODE_POKOK`, `CODE_WAJIB`, `CODE_SUKARELA`, `CODE_BERJANGKA`), tetapi konsumen masih memakai **nama literal**.
- Produk baru (mis. "Tabungan Haji") tidak akan dikenali pada jalur autodebit, angsuran, SHU, dan CIF.
- Penggantian nama produk di UI akan **memutus** query lama.

**Rekomendasi**

- Gunakan `JenisSimpanan::kodeUntukNama()` / kode produk sebagai kunci query, bukan nama tampilan.
- Untuk query multi-produk, ambil daftar kode dari master lalu `whereIn`.
- Audit terakhir: grep literal nama produk harus nol di `app/Services` dan `app/Http`.

**Risiko perbaikan:** sedang (menyentuh query saldo). Wajib test saldo & rekonsiliasi.

**Status: SELESAI (Paket 4)** — 2026-10-19

*Penyimpangan dari rekomendasi audit:* method `JenisSimpanan::kodeUntukNama()` **tidak ada**. Penggantinya `JenisSimpanan::namaUtama(string $tipe)` / `namaRekening(string $tipe)` yang menurunkan nama dari master `jenis_simpanans`.

*Cakupan:* 7 berkas / 15 titik — `TellerController` (filter deposito, agregat, status, allowlist penarikan, jalur pencairan), `AOController`, `RekeningController`, `Dashboard\PengurusController`, `pengurus\simpanan.blade.php`, `SimulasiTransaksiHariIni`, `UjiCobaRoleAO`. Allowlist penarikan memakai `GuardSimpananModal::jenisRekeningDapatDitarik()`; konstanta `JENIS_REKENING_DAPAT_DITARIK` sengaja dipertahankan (dikunci `PenguncianEkuitasPenarikanTest`).

*Hasil uji:* subset regresi **5 skipped, 511 passed (2076 assertions)**, 65,06 s, exit 0. *Rollback:* pulihkan 7 berkas dari backup (tanpa DDL), `php artisan optimize:clear`.

---

### DYN-11 — Kode COA literal di command/controller (SELESAI)

**Lokasi**

- [TestShu.php](file:///c:/laragon/www/koperasi_syariah/app/Console/Commands/TestShu.php) baris 193, 245, 251, 257, 263, 374-376, 520
- [DanaCadanganController.php:358](file:///c:/laragon/www/koperasi_syariah/app/Http/Controllers/DanaCadanganController.php#L358) — default `'2-1003'`

**Dampak**

- Perubahan bagan akun (penomoran COA) memutus command dan default controller.
- Berbeda dengan kode aplikasi lain yang memakai `App\Support\Coa`.

**Rekomendasi**

- Ganti dengan konstanta `Coa::*`.
- Untuk default controller, ambil dari `Coa` atau konfigurasi, bukan string.

**Risiko perbaikan:** rendah.

**Status: SELESAI (Paket 4)** — 2026-10-19

| Berkas | Perubahan |
|---|---|
| [TestShu.php](file:///c:/laragon/www/koperasi_syariah/app/Console/Commands/TestShu.php) | 6 titik kode COA literal → `KodeAkun::SIMPANAN_SUKARELA`, `IKHTISAR_SHU`, `CADANGAN_KOPERASI`, `UTANG_DANA_PENGURUS`. Import memakai alias `use App\Support\Coa as KodeAkun;` karena nama `Coa` sudah dipakai model `App\Models\Coa`. |
| [DanaCadanganController.php](file:///c:/laragon/www/koperasi_syariah/app/Http/Controllers/DanaCadanganController.php#L358) | Default `kode_akun` Buku Besar `'2-1003'` → `\App\Support\Coa::SIMPANAN_BERJANGKA`. |

*Hasil uji:* `Tahap1StabilisasiKritisTest` **31 passed**, `PenyatuanSumberShuTest` **8 passed**, `Tahap5HardeningTest` **34 passed**. *Rollback:* pulihkan 2 berkas dari backup.

---

### DYN-12 — Denda 0,1 %/hari hardcode di simulasi (SELESAI)

**Lokasi:** [SimulasiTransaksiHariIni.php:466](file:///c:/laragon/www/koperasi_syariah/app/Console/Commands/SimulasiTransaksiHariIni.php#L466)

**Bukti**

```php
$denda = round($pokok * 0.001 * $hari, 0); // 0.1% per hari
```

**Dampak**

- Angka denda tidak berasal dari `Setting`, sehingga simulasi dapat **berbeda** dari perhitungan nyata.
- Bila kebijakan denda diubah, simulasi memberi gambaran yang salah.

**Rekomendasi**

- Tambahkan parameter `denda_persen_per_hari` di `Setting`, lalu pakai di simulasi dan di perhitungan nyata.
- Pastikan tidak ada dua rumus denda yang hidup berdampingan.

**Risiko perbaikan:** rendah bila hanya menyatukan sumber angka.

**Status: SELESAI** — 2026-10-22

*Koreksi atas rekomendasi di atas:* hardcode `round($pokok * 0.001 * $hari, 0)` pada `SimulasiTransaksiHariIni.php` **sudah tidak ada** — baris tersebut kini memanggil `app(TazirService::class)->calculate($angsuran)`, sehingga simulasi dan perhitungan riil memakai SSOT yang sama. Tidak perlu parameter denda baru; parameter yang sudah ada (`tazir_mode`, `financing_late_fee_per_day`, `tazir_rate`, `tazir_grace_period_days`) sudah cukup.

Celah nyata yang ditemukan saat verifikasi justru ada di **preview UI**, bukan di simulasi:

| Berkas | Perubahan |
|---|---|
| [kebijakan.blade.php](file:///c:/laragon/www/koperasi_syariah/resources/views/pengurus/kebijakan.blade.php) | `updatePreview()` kini mengikuti **saklar sesungguhnya**, `financing_late_fee_per_day` (bawaan `0` = ta'zir tidak dikenakan), bukan `tazir_rate` saja. Sebelumnya layar menampilkan "Nominal Ta'zir = Rp 10.000" padahal tagihan riil Rp 0. Ditambah pesan eksplisit untuk mode `DISABLED` vs denda nonaktif, serta listener `input` pada field denda. |
| [kebijakan.blade.php](file:///c:/laragon/www/koperasi_syariah/resources/views/pengurus/kebijakan.blade.php) | Referensi JS ke `#limit_pengurus` dihapus — elemen itu **tidak pernah dirender**, sehingga blok sinkronisasi limit pengurus tidak pernah aktif. Pengikatan yang benar adalah `#limit_manajer` ↔ `#limit_pembiayaan_manajer`. |
| [KebijakanFormMetadataTest.php](file:///c:/laragon/www/koperasi_syariah/tests/Feature/KebijakanFormMetadataTest.php) | Test baru `test_preview_tazir_memakai_saklar_financing_late_fee_per_day()` mengunci ketiganya: preview membaca field denda, membandingkan `lateFee <= 0`, dan tidak lagi memakai `limit_pengurus`. |

*Hasil uji:* `KebijakanFormMetadataTest|TazirDetailKontrakTest|Psak108PemisahanMarginDanaKebajikanTest|DanaKebajikanTazirTest` **55 passed (228 assertions)**. *Rollback:* pulihkan `kebijakan.blade.php` dan test dari backup.

---

### DYN-13 — Tahun buku `'2026'` hardcode di command (SELESAI)

**Lokasi:** [TestShu.php:60,72,100](file:///c:/laragon/www/koperasi_syariah/app/Console/Commands/TestShu.php#L60)

**Dampak**

- Command tidak bisa dipakai ulang untuk tahun berikutnya tanpa edit kode.
- Risiko menjalankan simulasi pada tahun yang salah.

**Rekomendasi**

- Ambil dari `TahunBukuService` atau argumen `--tahun`.

**Risiko perbaikan:** rendah.

**Status: SELESAI (Paket 4)** — 2026-10-19

| Perubahan pada [TestShu.php](file:///c:/laragon/www/koperasi_syariah/app/Console/Commands/TestShu.php) | Keterangan |
|---|---|
| Opsi `--tahun=` | Validasi `ctype_digit`; default `TahunBukuService::tahunBukuDari(now())` — mengikuti tahun buku **berjalan**, bukan tahun kalender. |
| STEP 2, 3, 4, 5B | Tahun buku, `no_ref` jurnal (`SHU-{tahun}`), dan pesan status tidak lagi dipatri. |
| `seedSampelAnggota(int $tahunBuku)` | Tanggal gabung diturunkan dari kalender tahun buku (`awalPeriode($tahun)->subYear()`, `bulanKe($tahun, 1)`, `bulanKe($tahun, 7)`). |
| STEP 6 (assertion prorate) | Rasio harapan dihitung dari `jumlahBulan()` dan `bulanKe(..., 7)`; tidak lagi mengasumsikan 12 bulan / rasio 0,5. |

*Hasil uji:* `Tahap1StabilisasiKritisTest` **31 passed** (guard `test:shu` tetap exit 1 di produksi), `PenyatuanSumberShuTest` **8 passed** (tidak ada persentase pos SHU literal). *Rollback:* pulihkan `TestShu.php` dari backup.

---

### DYN-14 — Nama peran hardcode di command (SELESAI)

**Lokasi**

- [ResetDataAnggota.php:88](file:///c:/laragon/www/koperasi_syariah/app/Console/Commands/ResetDataAnggota.php#L88) — `where('role','anggota')`
- [UjiCobaRoleAO.php:60](file:///c:/laragon/www/koperasi_syariah/app/Console/Commands/UjiCobaRoleAO.php#L60) — `'ao'`

**Dampak**

- Perubahan penamaan/penambahan peran tidak terikuti di command pemeliharaan.
- Berbeda dengan controller/service yang sudah memakai `HierarkiRole::kelompok()`.

**Rekomendasi**

- Gunakan `HierarkiRole::kelompok('anggota')` / `peran()`.
- Untuk command uji, ambil peran target dari argumen.

**Risiko perbaikan:** rendah.

**Status: SELESAI (Paket 4)** — 2026-10-19

| Berkas | Perubahan |
|---|---|
| [ResetDataAnggota.php](file:///c:/laragon/www/koperasi_syariah/app/Console/Commands/ResetDataAnggota.php) | `where('role', 'anggota')` → `whereIn('role', HierarkiRole::kelompok('anggota'))`. Daftar peran anggota & staff diturunkan dari SSOT RBAC; teks bantuan tidak lagi menyebut peran secara literal. Efek: peran baru berkategori anggota ikut terhapus, dan role `nasabah` (kategori anggota) kini ikut terhapus — sebelumnya tidak. |
| [UjiCobaRoleAO.php](file:///c:/laragon/www/koperasi_syariah/app/Console/Commands/UjiCobaRoleAO.php) | Opsi baru `--role=ao`; peran divalidasi dengan `HierarkiRole::dikenal()` dan label pesan memakai `HierarkiRole::label()`. Perilaku bawaan tidak berubah. |

*Hasil uji:* `Tahap1StabilisasiKritisTest` **31 passed** (guard `reset:data-anggota` & `test:role-ao` tetap exit 1 di produksi tanpa `--force`). *Rollback:* pulihkan 2 berkas dari backup.

---

### DYN-15 — `JenisSimpanan` masih punya fallback hardcode (SELESAI)

**Lokasi:** [JenisSimpanan.php:70-95](file:///c:/laragon/www/koperasi_syariah/app/Models/JenisSimpanan.php#L70-L95)

**Bukti**

```php
public const CODE_BOLEH_AUTO_DEBIT = [ self::CODE_WAJIB ];
```

```php
public const FALLBACK = [
    'Simpanan Pokok'     => [... 'min_deposit_amount' => 100000, 'allow_auto_debit' => false],
    'Simpanan Wajib'     => [... 'min_deposit_amount' => 50000,  'allow_auto_debit' => false],
    'Simpanan Sukarela'  => [... 'min_deposit_amount' => 0,      'allow_auto_debit' => false],
    'Simpanan Berjangka' => [... 'min_deposit_amount' => 0,      'allow_auto_debit' => false],
];
```

**Dampak**

- Master produk ada di DB (`min_deposit_amount`, `allow_auto_debit`), tetapi ada **jalur kedua** di kode.
- Produk yang boleh autodebit ditentukan oleh konstanta kode, bukan kolom `allow_auto_debit` → mengubah izin autodebit produk menuntut deploy.
- Nominal minimal bisa berbeda antara DB dan fallback bila data tidak lengkap.

**Rekomendasi**

- `CODE_BOLEH_AUTO_DEBIT` → baca `allow_auto_debit` dari master.
- `FALLBACK` hanya untuk kondisi darurat (mis. instalasi baru), dan harus **selaras** dengan seeder/migrasi.
- Tambahkan test yang membandingkan `FALLBACK` dengan isi tabel.

**Risiko perbaikan:** sedang (menyentuh validasi setoran & autodebit).

**Status: SELESAI (Paket 4)** — 2026-10-19

| Berkas | Perubahan |
|---|---|
| [config/simpanan.php](file:///c:/laragon/www/koperasi_syariah/config/simpanan.php) | Baru — `auto_debit_kode => ['WAJIB']` (default aman, identik perilaku lama). |
| [JenisSimpanan.php](file:///c:/laragon/www/koperasi_syariah/app/Models/JenisSimpanan.php) | `kodeAutoDebitDiizinkan()` membaca `config('simpanan.auto_debit_kode')` dengan normalisasi & fallback konstanta; `kodeBolehAutoDebit(?string $code)` menjadi satu-satunya pemeriksa. `FALLBACK` tetap ada sebagai cadangan instalasi baru (4 produk), dengan `allow_auto_debit` dibaca dari kolom DB lebih dulu lewat `produkIzinkanAutoDebit()`. |

*Hasil uji:* termasuk dalam subset Paket 4 **5 skipped, 511 passed**. *Rollback:* hapus `config/simpanan.php`, pulihkan `JenisSimpanan.php` dari backup, `php artisan optimize:clear`.

---

### DYN-16 — `CheckRole` tidak memvalidasi nama peran (SELESAI)

**Lokasi:** [CheckRole.php:16-33](file:///c:/laragon/www/koperasi_syariah/app/Http/Middleware/CheckRole.php#L16-L33)

**Bukti**

```php
if (!in_array($user->role, $roles, true)) {
    abort(403, 'Akses ditolak...');
}
```

**Dampak**

- Salah ketik di rute (`role:manajerx`) tidak terdeteksi saat boot; hasilnya **403 permanen** yang sulit dilacak.
- Peran yang dihapus dari config tetapi masih dipakai di rute tidak memunculkan peringatan.
- Tidak ada satu titik yang menjamin "semua `role:` di rute merujuk peran yang dikenal".

**Rekomendasi**

- Panggil `HierarkiRole::dikenal($role)` untuk setiap argumen; bila tidak dikenal → `throw new InvalidArgumentException` (fail fast saat request pertama, idealnya saat route cache).
- Tambahkan command `php artisan rbac:periksa` yang memindai `routes/web.php` + config dan melaporkan peran tak dikenal.
- Tambahkan test yang memastikan semua peran di rute ada di `HierarkiRole::peran()`.

**Risiko perbaikan:** rendah (justru menambah keamanan).

**Status: SELESAI** — 2026-10-22

| Berkas | Perubahan |
|---|---|
| [CheckRole.php](file:///c:/laragon/www/koperasi_syariah/app/Http/Middleware/CheckRole.php) | Setiap argumen `role:` diperiksa dengan `HierarkiRole::dikenal()`; nama tak dikenal → `InvalidArgumentException` (gagal cepat, bukan 403 yang menyesatkan). |
| [PeriksaRbac.php](file:///c:/laragon/www/koperasi_syariah/app/Console/Commands/PeriksaRbac.php) | **Baru** — command `php artisan rbac:periksa`. Memindai SELURUH `Route::getRoutes()` + `config/role_hierarki.php` + `config/izin.php`, read-only, exit code `FAILURE` bila ada temuan (siap dipakai sebagai gerbang rilis). Enam kategori: `ROUTE_ROLE_TAK_DIKENAL`, `ROUTE_IZIN_TAK_TERDAFTAR`, `IZIN_PERAN_TAK_DIKENAL`, `KONFIG_PERAN_TAK_DIKENAL` (rantai/batas/approval_role/frekuensi/akses_data/kelompok/izin_dokumen/kelompok_tolak), `DASBOR_TAK_TERDAFTAR` (`peran.*.dashboard_route` → `Route::has()`), `PENGALIHAN_TAK_TERDAFTAR` (`kelompok_tolak.*.*.route`). |
| [RbacRuteKonsistenTest.php](file:///c:/laragon/www/koperasi_syariah/tests/Feature/RbacRuteKonsistenTest.php) | **Baru**, 4 test: (1) setiap `role:` pada rute dikenal `HierarkiRole`; (2) `rbac:periksa` memberi 0 temuan pada konfigurasi nyata; (3) command benar-benar gagal (exit 1) saat peta izin memuat peran tak dikenal; (4) command benar-benar gagal saat rute menyebut `role:manajerx`. |

*Catatan cakupan:* `routes/web.php` kini **tidak** memakai `role:` sama sekali (sudah berpindah ke `izin:` pada DYN-18); `role:` tersisa hanya di `routes/api.php` dan ditangani oleh test (1).

*Hasil uji:* `rbac:periksa` → 0 temuan (9 peran dikenal, 43 izin terdaftar); `RbacRuteKonsistenTest|IzinRuteTest` **7 passed (177 assertions)**. *Rollback:* hapus `PeriksaRbac.php` + test, pulihkan `CheckRole.php` dari backup.

---

### DYN-17 — Jam transaksi `setTime(0, 1)` hardcode (RENDAH)

**Lokasi:** [MandatorySavingsService.php:723,759](file:///c:/laragon/www/koperasi_syariah/app/Services/MandatorySavingsService.php#L723)

**Dampak**

- Jam posting simpanan wajib dikunci 00:01. Bila kebijakan jam posting berubah, harus edit kode.
- Menyulitkan audit bila ingin membedakan transaksi otomatis vs manual berdasarkan jam.

**Rekomendasi**

- Jadikan parameter `jam_posting_simpanan_wajib` di `Setting`, atau gunakan konstanta bernama di service.

**Risiko perbaikan:** rendah.

**Status: SELESAI** — 2026-10-22

Dipilih opsi **parameter kebijakan penuh** (bukan sekadar konstanta bernama), agar jam posting dapat digeser Pengurus lewat UI tanpa deploy.

| Berkas | Perubahan |
|---|---|
| [migrasi `add_jam_posting_simpanan_wajib`](file:///c:/laragon/www/koperasi_syariah/database/migrations/2026_10_22_000100_add_jam_posting_simpanan_wajib_to_kebijakan_koperasis.php) | **Baru** — kolom `string(5)` nullable, idempoten (`hasColumn`), sudah dijalankan. |
| [Setting.php](file:///c:/laragon/www/koperasi_syariah/app/Support/Setting.php) | `DEFAULTS` `'00:01'`, `TIPE` `'string'`, `DESKRIPSI`, dan accessor `jamPostingSimpananWajib()` yang **memvalidasi format `HH:MM` 24 jam** dan jatuh ke `00:01` bila rusak — sehingga `setTimeFromTimeString()` tidak pernah melempar exception saat posting. |
| [MandatorySavingsService.php](file:///c:/laragon/www/koperasi_syariah/app/Services/MandatorySavingsService.php) | Konstanta `JAM_POSTING` dihapus, diganti `private function jamPosting()` sebagai **satu titik** agar `waktu_transaksi` dan `paid_at` selalu identik. |
| [KebijakanKoperasi.php](file:///c:/laragon/www/koperasi_syariah/app/Models/KebijakanKoperasi.php) | `jam_posting_simpanan_wajib` ditambahkan ke `$fillable`. |
| [PengurusController.php](file:///c:/laragon/www/koperasi_syariah/app/Http/Controllers/Dashboard/PengurusController.php) | Validasi `regex` HH:MM + pesan bahasa Indonesia + penyimpanan (kosong → nilai aman saat ini). |
| [kebijakan.blade.php](file:///c:/laragon/www/koperasi_syariah/resources/views/pengurus/kebijakan.blade.php) | Field teks "Simpanan Wajib — Jam Posting Auto-Debit" dengan `pattern`/`title` sejajar validasi server. |
| [SimpananWajibKonfigurasiDinamisTest.php](file:///c:/laragon/www/koperasi_syariah/tests/Feature/SimpananWajibKonfigurasiDinamisTest.php) | +2 test: nilai mengikuti kebijakan & 4 nilai rusak (`25:00`, `8:30`, `jam`, kosong) jatuh ke `00:01`; validasi menolak `25:99` tanpa menyimpan. |

*Catatan:* jalur setoran manual (`setTime(now()->hour, now()->minute)`) sengaja **tidak** disentuh — hanya auto-debit yang memakai jam kebijakan.

*Hasil uji:* `SimpananWajibKonfigurasiDinamisTest|KebijakanFormMetadataTest|SimpananWajibKonfigurasiKepatuhanTest|AutodebitKonfirmasiTest` **29 passed (134 assertions)**. *Rollback:* `php artisan migrate:rollback --step=1`, pulihkan 6 berkas dari backup.

---

### DYN-18 — Deklarasi `role:` literal di 57 titik rute (KRITIS)

**Lokasi:** [routes/web.php](file:///c:/laragon/www/koperasi_syariah/routes/web.php) — 57 baris berisi `role:` (baris 95 s.d. 798)

**Bukti (contoh)**

```php
Route::middleware('role:teller')->prefix('teller')->group(...);
Route::middleware('role:teller,manajer,ao')->prefix('pengeluaran-beban')->group(...);
Route::middleware(['laporan.akses:neraca', 'role:manajer,pengurus,dps'])->group(...);
Route::middleware('role:super_admin,admin,pengurus')->prefix('admin')->group(...);
```

**Dampak**

- Menambah/mengubah wewenang peran = audit manual 57 titik; sangat mudah ada yang terlewat.
- Tidak ada tampilan matriks "peran X boleh apa" yang bisa dibaca dari satu sumber.
- Berisiko **over-permission** (peran diberi akses yang tidak seharusnya) atau **under-permission** (fitur hilang) tanpa disadari.

**Rekomendasi**

- Definisikan izin bernama di config (mis. `kas.buka`, `pembiayaan.approve`, `laporan.neraca`).
- Petakan peran → izin di `config/role_hierarki.php` (atau tabel).
- Ubah rute menjadi `izin:kas.buka`, lalu middleware baru mengecek izin efektif.
- Lakukan bertahap: tambahkan middleware `izin:` berdampingan dengan `role:` dulu, migrasikan grup per grup, lalu hapus `role:` setelah semua test hijau.

**Risiko perbaikan:** tinggi bila sekaligus; rendah bila bertahap dengan test per grup.

**Status: SELESAI (Paket 5)** — 2026-10-19

*Keputusan desain:* pemetaan izin → peran ditempatkan di `config/izin.php` (bukan di `config/role_hierarki.php`) agar hierarki peran tetap murni dan matriks wewenang rute dapat dibaca sebagai satu daftar. Middleware `role:` **tidak dihapus** — alias `izin` didaftarkan berdampingan, sehingga rollback cukup mengembalikan definisi rute.

| Berkas | Perubahan |
|---|---|
| [config/izin.php](file:///c:/laragon/www/koperasi_syariah/config/izin.php) | Baru — SSOT 42 izin bernama → daftar peran. Seluruh pemetaan adalah **pemindahan persis** dari `role:` sebelumnya; tidak ada hak yang ditambah/dicabut. |
| [Izin.php](file:///c:/laragon/www/koperasi_syariah/app/Support/Izin.php) | Baru — pembaca SSOT: `ada()`, `peran()`, dan `peranEfektif()` (irisan untuk rute ber-izin ganda). Dipakai middleware dan helper test agar tidak ada daftar peran kedua. |
| [CekIzin.php](file:///c:/laragon/www/koperasi_syariah/app/Http/Middleware/CekIzin.php) | Baru — gerbang izin. Nama izin tak terdaftar → `InvalidArgumentException` (fail-fast, bukan 403 diam); beberapa izin pada satu rute bersifat **DAN**; pesan 403 disamakan dengan `CheckRole`. |
| [bootstrap/app.php](file:///c:/laragon/www/koperasi_syariah/bootstrap/app.php) | Alias `'izin' => CekIzin::class` ditambahkan **berdampingan** dengan `'role'`. |
| [routes/web.php](file:///c:/laragon/www/koperasi_syariah/routes/web.php) | **48 titik** `role:` → `izin:` (42 nama izin, semuanya terpakai). Termasuk grup laporan (`laporan.neraca`, `laporan.buku_besar`, `laporan.psak101`, `laporan.manajer`, `laporan.audit`), Dana Cadangan, dan tiga tingkat `pengajuan-pembiayaan` (pengajuan → review manajer → persetujuan pengurus). Tidak ada `role:` tersisa di berkas rute. |
| [NavigasiWewenangPeranTest.php](file:///c:/laragon/www/koperasi_syariah/tests/Feature/NavigasiWewenangPeranTest.php), [NavigasiAdminMenuTest.php](file:///c:/laragon/www/koperasi_syariah/tests/Feature/NavigasiAdminMenuTest.php), [NavigasiSuperAdminMenuTest.php](file:///c:/laragon/www/koperasi_syariah/tests/Feature/NavigasiSuperAdminMenuTest.php) | Helper `peranDiizinkan()` dimigrasikan **lebih dulu** agar membaca `role:` **atau** `izin:` (resolusi lewat `Izin::peranEfektif()`), sehingga migrasi rute per grup tidak memutus invariant navigasi. |

*Verifikasi:* `php -l` bersih pada 8 berkas; `Grep "role:" routes/web.php` → **0 hasil**; kelompok RBAC/navigasi **58 passed (309 assertions)**; `IzinRuteTest` **3 passed (159 assertions)**; suite penuh **5 skipped, 1659 passed (7680 assertions)** — baseline 1656 utuh, +3 test invariant baru, tanpa regresi.

*Rollback:* (1) kembalikan definisi rute dari salinan `routes/web.php` sebelum migrasi (atau ubah kembali `izin:<nama>` → `role:<daftar peran>` memakai tabel pemetaan di `config/izin.php`); (2) hapus alias `'izin'` di `bootstrap/app.php`; (3) hapus `config/izin.php`, `app/Support/Izin.php`, `app/Http/Middleware/CekIzin.php`; (4) `php artisan optimize:clear`. Tidak ada DDL/migrasi pada tahap ini, sehingga rollback tidak menyentuh data.

---

### DYN-19 — Daftar status valid ditulis ulang di controller (TINGGI)

**Lokasi:** [ManajerController.php:168-175](file:///c:/laragon/www/koperasi_syariah/app/Http/Controllers/Dashboard/ManajerController.php#L168-L175)

**Bukti:** array `$validStatus` berisi status pembiayaan literal.

**Dampak**

- Menambah status alur (mis. `pending_komite`) tidak otomatis diakui.
- Status tersebar di model, controller, dan view → sumber kebenaran ganda.

**Rekomendasi**

- Pindahkan daftar status ke konstanta model (`Pembiayaan::STATUS_*` + `Pembiayaan::semuaStatus()`).
- Turunkan status yang menunggu dari `HierarkiRole::statusMenunggu()`.

**Risiko perbaikan:** rendah.

---

## 5. Area yang Sudah Dinamis (Jangan Diubah Tanpa Alasan)

| Area | Bukti |
|---|---|
| Matriks keputusan pembiayaan | `MatriksApproval` dari `config/alur_pembiayaan.php`; dipakai `ManajerController::approvalPembiayaanApprove()` |
| Hierarki & kelompok peran di service | `HierarkiRole::kelompok()` di [AutodebitService:351](file:///c:/laragon/www/koperasi_syariah/app/Services/AutodebitService.php#L351), [MandatorySavingsService:1356](file:///c:/laragon/www/koperasi_syariah/app/Services/MandatorySavingsService.php#L1356), [ShuController:753](file:///c:/laragon/www/koperasi_syariah/app/Http/Controllers/Pengurus/ShuController.php#L753), [ManajerController:500-501](file:///c:/laragon/www/koperasi_syariah/app/Http/Controllers/Dashboard/ManajerController.php#L500-L501) |
| Ambang & persentase CKPN | `Setting::ambangKolektibilitas()`, `Setting::persenCkpn()` (sudah di DB) |
| Tahun buku | `TahunBukuService::awalPeriode()/akhirPeriode()` — tidak mengasumsikan 1 Jan–31 Des |
| View approval | Memakai `$peranPemutus` / `$peranBerwenang` dari controller |
| Override batas peran | Tabel `limit_transaksi_role` + cache `role_hierarki.override` |
| Parameter SSOT | `App\Support\Setting` + `kebijakan_koperasis` (cache 60 menit) |

**Implikasi:** perbaikan harus **memperluas** SSOT ini, bukan membuat SSOT baru yang paralel.

---

## 6. Rencana Eksekusi yang Diusulkan

Urutan disusun dari **dampak tertinggi + risiko terendah**.

### Paket 1 — RBAC Dinamis (UI & menu)

- DYN-02 (menu berbasis izin)
- DYN-03 (ikon/badge pindah ke service)
- DYN-04 (hapus literal role di Blade)
- DYN-16 (`CheckRole` validasi peran dikenal)
- DYN-19 (status dari model)

**Dampak:** peran baru langsung punya menu; tidak ada lagi sidebar kosong.
**Risiko:** rendah — presentasi & validasi, tidak menyentuh uang/jurnal.

**Status: SELESAI** — DYN-02/03/04/19 dan DYN-16 diterapkan; DYN-16 ditutup 2026-10-22 dengan command `php artisan rbac:periksa` (6 kategori temuan) + `tests/Feature/RbacRuteKonsistenTest.php`. Baseline pemindaian: 9 peran, 43 izin, **0 temuan**.

### Paket 2 — Alur Approval Konsisten

- DYN-05 (target approval dari `HierarkiRole`)
- DYN-07 (kebijakan teller ke data)

**Dampak:** satu mekanisme wewenang untuk semua jalur.
**Risiko:** sedang — wajib test regresi approval & batas transaksi.

**Status: SELESAI** — DYN-05 dan DYN-07 diterapkan; target approval dan kebijakan teller kini bersumber dari `HierarkiRole` dan `Setting`.

### Paket 3 — Parameter & Label

- DYN-08 (form kebijakan dari metadata `Setting`)
- DYN-06 (label/warna kolektibilitas dari `Setting`)
- DYN-12 (denda ke `Setting`)
- DYN-17 (jam posting ke parameter)

**Dampak:** menambah parameter tidak lagi menyentuh 5 file.
**Risiko:** rendah-sedang — UI + sumber angka, rumus tetap.

**Status: SELESAI** — DYN-08/06/12/17 diterapkan. DYN-12 dan DYN-17 masing-masing ditutup pada 2026-10-22 (rincian, hasil uji, dan rollback ada pada blok status temuan masing-masing).

### Paket 4 — Akad & Produk

- DYN-09 (default akad dari master)
- DYN-10 (kode produk, bukan nama literal)
- DYN-15 (`allow_auto_debit` dari DB)
- DYN-11, DYN-13, DYN-14 (command & COA)

**Dampak:** produk/akad baru langsung dikenali seluruh jalur.
**Risiko:** sedang — menyentuh query saldo & jurnal; wajib test saldo.

**Status: SELESAI** — 2026-10-19. Seluruh 6 temuan (DYN-09, 10, 11, 13, 14, 15) diterapkan tanpa migrasi/DDL. Verifikasi akhir: suite penuh (lihat hasil pada tiap temuan).

### Paket 5 — Struktur Peran (terakhir)

- DYN-01 (ENUM → VARCHAR + validasi aplikasi)
- DYN-18 (rute `role:` → `izin:` bertahap)

**Dampak:** peran benar-benar bisa ditambah tanpa migrasi SQL.
**Risiko:** tinggi — kerjakan setelah Paket 1–4 stabil, dengan migrasi bertahap dan test penuh.

**Status: SELESAI** — 2026-10-19. DYN-01 (ENUM → `VARCHAR(32)` + guard `HierarkiRole::dikenal()`) dan DYN-18 (48 titik rute → `izin:` dengan SSOT `config/izin.php`) diterapkan bertahap; rincian, hasil uji, dan rollback ada pada blok status masing-masing temuan.

---

### Verifikasi Akhir Paket 1–5

| Aspek | Metode | Hasil |
|---|---|---|
| Fungsional & integrasi | Suite penuh `scripts\run-tests.ps1` | **5 skipped, 1659 passed (7680 assertions)**, 231.13s — baseline 1656 utuh + 3 test invariant baru |
| Invariant izin rute | `IzinRuteTest` (baru) | **3 passed (159 assertions)** — semua `izin:` pada rute terdaftar, tidak ada izin yatim, semua peran pada peta dikenal SSOT RBAC |
| Navigasi & otorisasi per peran | 5 kelas RBAC/navigasi | **58 passed (309 assertions)** — termasuk 403 lintas peran dan menu tiap peran |
| Kompatibilitas cache produksi | `php artisan config:cache` + `route:cache` | Keduanya berhasil (0,61s / 0,58s) — `config/izin.php` serializable dan tidak ada rute ber-closure |
| Kinerja halaman | Durasi test E2E per peran | Dashboard 0,1–0,6s per permintaan; tidak ada kenaikan berarti versus baseline |
| Keamanan | `KeamananHeaderTest`, `AuditLogAppendOnlyTest`, `Tahap5HardeningTest`, `RbacHierarkiRoleTest` | Seluruhnya lulus pada suite penuh — header keamanan, audit log append-only, dan pemisahan tugas tetap utuh |
| Sisa `role:` di rute | `Grep "role:" routes\web.php` | **0 hasil** |

Tidak ada perubahan pada perilaku nominal, jurnal, saldo, atau rumus perhitungan sepanjang Paket 1–5.

---

## 7. Lampiran — Cara Verifikasi Ulang

Perintah pemeriksaan cepat (jalankan dari root proyek):

```powershell
# 1. Peran literal di view
Select-String -Path resources\views\**\*.blade.php -Pattern "role === '|role == '"

# 2. Izin pada rute (DYN-18: `role:` sudah tidak dipakai)
Select-String -Path routes\web.php -Pattern "izin:"

# 3. Default akad hardcode
Select-String -Path app\**\*.php -Pattern "\?\? 'Murabahah'"

# 4. Nama produk simpanan literal
Select-String -Path app\**\*.php -Pattern "'Simpanan Sukarela'|'Wadiah'"

# 5. Kode COA literal
Select-String -Path app\**\*.php -Pattern "'\d-\d{4}'"

# 6. Pastikan semua peran di rute dikenal (usulan command baru)
php artisan rbac:periksa
```

### Checklist penerimaan (definition of done)

- [ ] Tidak ada `role === '...'` di `resources/views`.
- [ ] Tidak ada `match ($role)` di `NavigationService`.
- [ ] Semua `izin:` di rute terdaftar di `config/izin.php` (dijaga `IzinRuteTest`).
- [ ] Peran baru dapat ditambahkan tanpa mengedit Blade/menu.
- [ ] Parameter baru dapat ditambahkan tanpa mengedit form kebijakan.
- [ ] Tidak ada `?? 'Murabahah'` dan nama produk literal di `app/`.
- [ ] Suite test tetap pada baseline (tidak ada regresi).
- [ ] Tidak ada perubahan perilaku nominal, jurnal, atau saldo.

---

## 8. Catatan Kepatuhan

Seluruh rekomendasi di dokumen ini dirancang untuk **tidak mengubah perilaku uang/jurnal**. Perubahan yang menyentuh perhitungan (DYN-07, DYN-12) hanya **memindahkan sumber angka**, bukan mengubah rumus atau nilai default.

Acuan yang dipertahankan:

- UU No. 25/1992 Pasal 30 — kewenangan Pengurus.
- PP No. 7/2021 dan Permen KUKM No. 09/2018 — tata kelola koperasi.
- POJK No. 76/2016 — pemisahan fungsi (maker/checker).
- PSAK 101–107 — penyajian laporan keuangan syariah.
- UU PDP No. 27/2022 — pembatasan akses data per peran.
