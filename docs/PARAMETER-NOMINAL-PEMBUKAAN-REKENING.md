# Parameter Dinamis — Nominal Pembukaan Rekening

Dokumen ini menjelaskan parameter **nominal pembukaan rekening** yang dikelola
secara dinamis (tanpa hardcode) pada Sistem Informasi Koperasi Syariah.

Parameter ini terdiri dari tiga bagian yang saling terkait:

| Bagian | Lokasi | Sifat |
|---|---|---|
| Batas minimum | `kebijakan_koperasis.minimum_pembukaan_rekening` | Dapat dikonfigurasi pengurus |
| Batas maksimum | `kebijakan_koperasis.maksimum_pembukaan_rekening` | Dapat dikonfigurasi pengurus |
| Jejak nominal aktual | `rekening.nominal_pembukaan` | Tercatat otomatis saat pembukaan rekening |

Nilai batas **tidak boleh** ditulis langsung di kode. Seluruh alur pembukaan
rekening mengambil batas melalui satu resolver terpusat sehingga perubahan
kebijakan langsung berlaku tanpa modifikasi kode inti.

---

## 1. Ringkasan Arsitektur

```
                 pengurus/kebijakan.blade.php  (form konfigurasi)
                              │  PUT pengurus.kebijakan.update
                              ▼
          PengurusController::updateKebijakan()
                 │  parseNominal() → normalisasi format Indonesia
                 ▼
          KebijakanKoperasi (tabel kebijakan_koperasis)
                 │  event saved/deleted → Setting::flush() otomatis
                 ▼
   Setting (app/Support/Setting.php)  ← SINGLE SOURCE OF TRUTH
     • minimumPembukaanRekening()      : float
     • maksimumPembukaanRekening()     : float
     • batasPembukaanRekening()        : ['minimum'=>..,'maksimum'=>..]
     • aturanNominalPembukaan(bool)    : aturan validasi Laravel
     • pesanValidasiNominalPembukaan() : pesan error berkonteks
     • validasiNominalPembukaan()      : validasi di luar Validator
                 │
   ┌─────────────┼──────────────────────────────┬─────────────────────┐
   ▼             ▼                              ▼                     ▼
 CifController  TellerController   PengajuanRekeningController   Api\RekeningController
 /DepositoService  (deposito)          (konfirmasi publik)         /Api\DepositoController
```

`App\Models\KebijakanKoperasi` menyediakan **method delegasi** dengan signature
identik. Kode pemanggil boleh memakai `KebijakanKoperasi::` atau `Setting::` —
keduanya menghasilkan nilai yang sama.

---

## 2. Spesifikasi Parameter

### 2.1 Kolom `kebijakan_koperasis`

| Kolom | Tipe | Default | Null | Keterangan |
|---|---|---|---|---|
| `minimum_pembukaan_rekening` | `decimal(15,2)` | `10000.00` | tidak | Batas bawah nominal pembukaan |
| `maksimum_pembukaan_rekening` | `decimal(15,2)` | `50000000.00` | tidak | Batas atas nominal pembukaan |

Migrasi: `database/migrations/2026_09_26_000300_add_nominal_pembukaan_parameters.php`
(dilindungi guard `Schema::hasColumn()` sehingga aman dijalankan ulang).

Nilai default cadangan bila kebijakan belum pernah diatur didefinisikan di
`Setting::DEFAULTS` — bukan disebar di controller.

### 2.2 Kolom `rekening`

| Kolom | Tipe | Null | Keterangan |
|---|---|---|---|
| `nominal_pembukaan` | `decimal(15,2)` | ya | Nominal yang benar-benar disetor saat rekening dibuka |

Kolom ini **sengaja nullable** agar rekening lama (sebelum parameter ada) tetap
valid → tidak ada regresi. Rekening lama di-*backfill* dari `saldo`.

**Perbedaan dengan `saldo`:** `saldo` adalah nilai berjalan (berubah setiap
transaksi), sedangkan `nominal_pembukaan` adalah **jejak historis** nominal saat
akun dibuka dan tidak ikut berubah.

Presisi `decimal(15,2)`: hingga 13 digit rupiah dengan 2 desimal (maksimum
±9.999.999.999.999,99) — cukup untuk mata uang, bebas galat pembulatan float.
Nilai selalu dibulatkan `round($nilai, 2)` sebelum disimpan.

---

## 3. Resolver Terpusat (`Setting` / `KebijakanKoperasi`)

| Method | Return | Kegunaan |
|---|---|---|
| `minimumPembukaanRekening()` | `float` | Ambil batas bawah |
| `maksimumPembukaanRekening()` | `float` | Ambil batas atas |
| `batasPembukaanRekening()` | `['minimum'=>float,'maksimum'=>float]` | Batas untuk view |
| `aturanNominalPembukaan(bool $wajib = true)` | `array<int,string>` | Aturan validasi Laravel |
| `pesanValidasiNominalPembukaan(string $label)` | `array<string,string>` | Pesan error `field.rule` |
| `validasiNominalPembukaan(mixed $nilai, string $label)` | `?string` | Validasi nilai, di luar Validator |

Contoh aturan yang dihasilkan: `['required','numeric','min:10000.0','max:50000000.0']`.

**Normalisasi maksimum:** `batasPembukaanRekening()` memaksa
`maksimum = max(maksimum, minimum)`. Bila konfigurasi tidak konsisten
(maks < min), sistem tetap aman dipakai.

**Cache:** `Setting::CACHE_KEY = 'kebijakan_koperasi.settings'`,
`Setting::CACHE_TTL = 300` detik. `Setting::flush()` dipanggil otomatis oleh
event `saved`/`deleted` model, dan dipanggil eksplisit oleh controller setelah
menyimpan kebijakan → perubahan berlaku *real-time*.

### Audit

Setiap perubahan dua kolom ini tercatat otomatis di `kebijakan_koperasi_logs`
(kolom terdaftar di `KebijakanKoperasi::KOLOM_AUDIT`, label di `LABEL_KOLOM`,
format nilai rupiah di `formatNilai()`).

---

## 4. Jalur Pembukaan Rekening yang Memakai Parameter

| # | Jalur | Controller / Service | Field input | Wajib |
|---|---|---|---|---|
| 1 | Buka rekening simpanan via CIF (AO) | `CifController::bukaRekening()` | `setoran_awal` | ya |
| 2 | Buka deposito via CIF (AO) | `CifController::bukaDeposito()` | `setoran_awal` | ya |
| 3 | Setoran simpanan | `DepositoService` | `nominal` | ya |
| 4 | Deposito via teller | `TellerController::depositoStore()` | `nominal` | ya |
| 5 | Konfirmasi pengajuan publik | `PengajuanRekeningController::konfirmasiPembayaran()` | `nominal_simpanan_awal` | ya |
| 6 | API rekening — create | `Api\RekeningController::store()` | `nominal_pembukaan` | opsional* |
| 7 | API rekening — update | `Api\RekeningController::update()` | `nominal_pembukaan` | opsional |
| 8 | API deposito | `Api\DepositoController` | `nominal` | ya |

\* **Aturan not-null API create:** bila `nominal_pembukaan` tidak dikirim,
jejak diambil dari `saldo`. Bila **keduanya** kosong, request ditolak dengan
pesan *"Nominal pembukaan rekening wajib diisi bila saldo awal tidak dikirim."*
(logika `Api\RekeningController::resolveNominalPembukaan()`).

---

## 5. Spesifikasi API

### 5.1 `POST /api/rekening` — buat rekening

**Request body**

| Field | Tipe | Wajib | Catatan |
|---|---|---|---|
| `no_rekening` | string ≤30 | ya | unik |
| `anggota_id` | integer | ya | harus ada di `anggota` |
| `akun_id` | integer | ya | harus ada di `akun` |
| `jenis_rekening` | enum | ya | `Simpanan Pokok`, `Simpanan Wajib`, `Simpanan Sukarela`, `Simpanan Berjangka`, `Simpanan Hari Raya`, `Modal Penyertaan` |
| `akad` | enum | ya | `Wadiah`, `Mudharabah`, `Musharakah` |
| `nominal_pembukaan` | decimal(15,2) | opsional | fallback ke `saldo`; minimal `min`, maksimal `maks` |
| `saldo` | decimal(15,2) | opsional | ≥ 0 |
| `tanggal_buka` | date | ya | |
| `tanggal_jatuh_tempo` | date | kondisional | wajib bila `jenis_rekening = Simpanan Berjangka` |
| `status_rekening` | enum | opsional | `Aktif`, `Dibekukan`, `Ditutup`, `Jatuh Tempo` |

**Contoh payload benar**

```json
{
  "no_rekening": "SW-2026-000123",
  "anggota_id": 42,
  "akun_id": 7,
  "jenis_rekening": "Simpanan Sukarela",
  "akad": "Wadiah",
  "nominal_pembukaan": 100000,
  "tanggal_buka": "2026-09-24",
  "status_rekening": "Aktif"
}
```

**Respons sukses `201`**

```json
{
  "success": true,
  "message": "Rekening berhasil dibuka",
  "data": {
    "id": 501,
    "no_rekening": "SW-2026-000123",
    "nominal_pembukaan": "100000.00",
    "saldo": "100000.00"
  }
}
```

**Contoh respons error validasi `422`**

```json
{
  "message": "Nominal pembukaan rekening tidak boleh kurang dari Rp 10.000,00 (nilai nominal di bawah batas minimum yang diizinkan). (and 1 more error)",
  "errors": {
    "nominal_pembukaan": [
      "Nominal pembukaan rekening tidak boleh kurang dari Rp 10.000,00 (nilai nominal di bawah batas minimum yang diizinkan)."
    ]
  }
}
```

Variasi pesan lain (semua memuat konteks spesifik penyebab gagal):

| Kondisi | Pesan |
|---|---|
| Kosong & `saldo` juga kosong | `Nominal pembukaan rekening wajib diisi bila saldo awal tidak dikirim.` |
| Bukan angka | `Nominal pembukaan rekening harus berupa angka.` |
| < batas minimum | `… tidak boleh kurang dari Rp <min> (nilai nominal di bawah batas minimum yang diizinkan).` |
| > batas maksimum | `… tidak boleh melebihi Rp <maks> (nilai nominal di atas batas maksimum yang diizinkan).` |

### 5.2 `PUT /api/rekening/{id}` — perbarui rekening

Body sama dengan create (`no_rekening` diabaikan-untuk-diri-sendiri saat cek
unik). `nominal_pembukaan` bersifat **opsional** — tidak dikirim berarti nilai
lama dipertahankan; bila dikirim, tetap divalidasi terhadap batas dinamis.

### 5.3 API deposito

`nominal` bersifat **wajib** dan divalidasi dengan batas yang sama.

---

## 6. Integrasi Modul COA

Nominal pembukaan tidak diarahkan ke akun secara hardcode. Pengaitan dilakukan
mengikuti **jenis rekening** melalui master `JenisSimpanan`:

```
jenis_rekening ──► JenisSimpanan::coaKode($nama)      ──► kode akun COA (tabel `coas`)
                └─► JenisSimpanan::akunLegacyId($nama) ──► akun legacy  (kolom `rekening.akun_id`)
```

| Method | Hasil | Dipakai untuk |
|---|---|---|
| `JenisSimpanan::coaKode($nama)` | kode akun aktif (mis. `2-1001`) | posting jurnal / nisbah |
| `JenisSimpanan::akunLegacyId($nama)` | id tabel `akun` | kolom `rekening.akun_id` |
| `JenisSimpanan::coaKodeBertipe($tipe)` | kode akun dari jenis aktif bertipe tertentu | fallback pengelompokan |
| `JenisSimpanan::tipe($nama)` | tipe pengelompokan jenis simpanan | pengelompokan laporan |

**Alur jurnal setoran awal.** `AutoJournalingService::jurnalSetorSimpanan()`
membuat jurnal balance: **debet** = kas/bank, **kredit** = akun COA hasil
`coaKode(jenis_rekening)`, dengan `total_debet = total_kredit = nominal`.

### Menyesuaikan pengelompokan tanpa mengubah kode

1. Buka menu **Pengurus → Master Jenis Simpanan**.
2. Ubah `kode_akun` pada jenis simpanan yang bersangkutan (atau tambahkan jenis
   baru beserta kode akunnya).
3. Simpan. Cache master di-*flush* otomatis oleh event model.
4. Pembukaan rekening berikutnya langsung memakai akun COA baru — **tanpa edit
   kode**.

Bila sebuah jenis tidak ditemukan / nonaktif, `coaKode()` mengembalikan `null`
dan sistem memakai `FALLBACK` bawaan sehingga alur tidak putus.

---

## 7. Panduan Frontend

### 7.1 Sumber batas untuk komponen

Controller mengirim `$batasPembukaan = KebijakanKoperasi::batasPembukaanRekening()`.
View **wajib** membacanya dari variabel ini — jangan menulis angka batas di
markup/JS.

```blade
@php
    $minPembukaan  = (float) ($batasPembukaan['minimum'] ?? 10000);
    $maksPembukaan = (float) ($batasPembukaan['maksimum'] ?? 50000000);
@endphp

<input type="number" name="nominal" id="nominal"
       min="{{ $minPembukaan }}" max="{{ $maksPembukaan }}" step="1000"
       data-min="{{ $minPembukaan }}" data-max="{{ $maksPembukaan }}"
       placeholder="Contoh: {{ (int) $minPembukaan }}" required>
<div id="nominal-warn"></div>
```

Atribut `data-min`/`data-max` dipakai validasi JS agar pesan konsisten dengan
aturan server. Karena batasnya dinamis, **jangan** menyalin nilai ke JS literal.

### 7.2 Aturan format input

| Aspek | Aturan |
|---|---|
| Tipe input | `type="number"` (`step="1000"`) pada form rekening/deposito |
| Input teks berformat | Form kebijakan memakai `inputmode="numeric"` + format `number_format` (`80.000.000`) — controller menormalkan via `parseNominal()` |
| Desimal | maksimal 2 angka di belakang koma; server membulatkan `round(...,2)` |
| Notasi ilmiah | tidak didukung — kirim angka polos |
| Simbol mata uang di payload API | tidak diizinkan — kirim angka mentah |

### 7.3 Pesan error yang ditampilkan

Selalu tampilkan pesan dari server (`@error(...)`) agar konteks sebab gagal
(minimum/maksimum/format) ikut terlihat:

```blade
@error('nominal_simpanan_awal')
    <span style="color:#dc2626;font-size:0.75rem;">{{ $message }}</span>
@enderror
```

Validasi sisi klien hanya untuk umpan balik cepat; **server tetap otoritatif**.

### 7.4 Alur pengiriman data

1. Pengguna mengisi nominal; JS memeriksa `data-min`/`data-max` dan menampilkan
   peringatan bila di luar rentang (form tetap boleh dikirim).
2. Form dikirim ke endpoint jalur terkait (lihat §4).
3. Server memvalidasi dengan `aturanNominalPembukaan()` + pesan berkonteks.
4. Bila gagal → kembali ke form dengan `old()` + `@error`.
5. Bila lolos → `nominal_pembukaan` disimpan, jurnal setoran awal terbentuk.

---

## 8. Konfigurasi Dinamis (Tanpa Ubah Kode)

| Yang diubah | Cara | Perlu deploy kode? |
|---|---|---|
| Batas minimum / maksimum | Pengurus → Kebijakan → "Batas Nominal Pembukaan Rekening" | tidak |
| Akun COA per jenis rekening | Pengurus → Master Jenis Simpanan | tidak |
| Jenis simpanan baru + akunnya | Pengurus → Master Jenis Simpanan | tidak |
| Label pesan error per form | argumen `$label` pada `pesanValidasiNominalPembukaan($label)` | tidak (runtime) |
| Default cadangan sistem | `Setting::DEFAULTS` | ya (kode) |

Karena seluruh jalur menurunkan aturan dari resolver yang sama, mengubah
kebijakan **langsung** mengubah perilaku semua endpoint dan form.

---

## 9. Pengujian

Berkas: `tests/Feature/NominalPembukaanRekeningParameterTest.php` (27 test).

```powershell
php artisan test --filter=NominalPembukaanRekeningParameterTest
```

Cakupan: skema & persisten parameter, resolver & delegasi, fleksibilitas
dinamis, pesan error berkonteks, jejak `nominal_pembukaan` + backfill, integrasi
COA (jurnal debet/kredit balance), serta regresi rekening lama tanpa jejak.

Regresi penuh:

```powershell
php artisan test
```

---

## 10. Pemecahan Masalah

| Gejala | Penyebab | Tindakan |
|---|---|---|
| Pesan "di bawah batas minimum" padahal nilai wajar | Batas parameter salah | Perbaiki di Pengurus → Kebijakan |
| Perubahan batas tidak berefek | Cache Setting belum di-*flush* | `php artisan cache:clear` (normalnya otomatis) |
| Endpoint menolak `"80.000.000"` pada API | Format Indonesia di payload API | Kirim angka mentah `80000000` |
| `nominal_pembukaan` `null` pada rekening lama | Belum di-*backfill* | Jalankan migrasi/backfill |
| Jurnal tidak balance | Akun COA jenis simpanan salah/nonaktif | Perbaiki kode akun di Master Jenis Simpanan |
