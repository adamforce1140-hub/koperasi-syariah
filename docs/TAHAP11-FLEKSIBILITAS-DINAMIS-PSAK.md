# Fleksibilitas Dinamis — Kepatuhan PSAK Koperasi Syariah

**Status:** ✅ SELESAI & TERVERIFIKASI
**Tanggal penyelesaian:** 2026-09-30
**Tahap:** 11 (menutup butir terakhir permintaan induk — "sistem dinamis & fleksibel")
**Dasar syariah:** PSAK 101, 105, 106, 107, 108, 109, 111 · Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000 dan fatwa terkait akad
**Dasar regulasi:** UU No. 25/1992 tentang Perkoperasian · Permen KUKM No. 09/2018 tentang Usaha Simpan Pinjam (KSPPS)
**Khalayak:** Pengurus, Manajer, Dewan Pengawas Syariah (DPS), Tim TI, Auditor Internal

---

## 1. Ringkasan untuk Pemangku Kepentingan

Permintaan induk menuntut sistem yang **berjalan secara dinamis dan fleksibel (tidak kaku)** dengan
sepenuhnya mengadopsi standar koperasi syariah PSAK. Sebelum tahap ini, sistem memang sudah
mengadopsi PSAK untuk **perhitungan** (CKPN, bagi hasil, margin ditangguhkan, dana zakat/infak),
tetapi masih memiliki satu titik kaku yang penting: **pemetaan akad syariah ke akun CoA ditanam
sebagai konstanta di dalam kode PHP.**

Akibatnya, begitu koperasi ingin menerapkan akad yang **belum dikenal kode** — misalnya akad
turunan (*Musyarakah Mutanaqisah*) atau akad baru hasil fatwa DSN-MUI terbaru — sistem **menolak**
input tersebut dan menuntut perubahan kode + deploy ulang oleh Tim TI.

Tahap 11 memindahkan pemetaan tersebut ke **basis data yang dapat dikelola Pengurus**, sehingga
penambahan akad syariah baru menjadi **keputusan tata kelola (governance), bukan pekerjaan teknis.**

| Aspek | Sebelum (kaku) | Sesudah (dinamis) |
|---|---|---|
| Menambah akad syariah baru | Ubah kode `App\Support\Coa` + deploy | Isi panel **Pemetaan Akad Syariah** di menu Kebijakan → langsung aktif |
| Memindahkan akun piutang/pendapatan/margin-ditangguhkan | Ubah kode + deploy | Pilih akun lain dari dropdown → langsung berlaku |
| Validasi input akad | Daftar tetap `in:` dari konstanta | Membaca daftar akad dari basis data (+ akad kanonik sebagai jaring pengaman) |
| Jejak perubahan | Tidak ada | Tercatat pada audit trail konfigurasi (siapa, kapan, nilai lama → baru) |

**Jaminan tanpa gangguan operasional:** seluruh akad kanonik yang sudah berjalan **tidak berubah
perilakunya**. Migrasi hanya membuat baris pemetaan dengan akun **kosong**, yang berarti
"ikuti pemetaan standar". Jurnal yang sudah ada dan yang akan datang tetap identik sampai Pengurus
secara sadar mengubahnya.

---

## 2. Kriteria Pemenuhan

| # | Kriteria | Implementasi | Bukti |
|---|---|---|---|
| K11.1 | Akad syariah dapat **ditambah tanpa mengubah kode sumber** | Tabel `pemetaan_akad_coa`; `PemetaanAkadCoa::daftarAkad()` menggabungkan akad kanonik + akad hasil pemetaan | `test_akad_baru_dapat_ditambahkan_tanpa_mengubah_kode`, `test_pengurus_dapat_menyimpan_pemetaan_akad_baru_lewat_ui` |
| K11.2 | Validasi input akad **mengikuti daftar dinamis** | `Pembiayaan::aturanValidasiAkad()` → `Rule::in(Pembiayaan::daftarAkad())` yang kini membaca basis data | `test_akad_baru_dapat_dipetakan_ke_akun_coa`, `test_akad_nonaktif_tidak_dianggap_akad_sah` |
| K11.3 | Akun CoA per akad dapat diubah Pengurus lewat UI (tanpa deploy) | `CoaResolver::kodePiutangByAkad()` / `kodePendapatanByAkad()` / `kodeMarginDitangguhkanByAkad()` membaca pemetaan lebih dulu | `test_pemetaan_berbasis_data_mengalahkan_registri_standar` |
| K11.4 | Prinsip **zero-hardcoding**: bila pemetaan kosong, sistem memakai standar | Rantai fallback berbasis data → kebijakan koperasi → registri `App\Support\Coa` | `test_pemetaan_kosong_memakai_registri_standar` |
| K11.5 | Akun yang **nonaktif** tidak dipakai; sistem mundur dengan aman | `CoaResolver::kodeDariId()` memfilter `is_active=true` + `Log::warning` | `test_akun_nonaktif_diabaikan_dan_jatuh_ke_standar` |
| K11.6 | Perubahan berlaku **segera** (cache dibersihkan otomatis) | `PemetaanAkadCoa::booted()` → `saved`/`deleted` memanggil `flush()` | `test_perubahan_pemetaan_membersihkan_cache` |
| K11.7 | **Hanya Pengurus** yang boleh mengubah pemetaan | Rute dalam grup `role:pengurus` | `test_pengguna_tanpa_peran_pengurus_tidak_dapat_menyimpan` |
| K11.8 | Daftar akad kanonik **tetap utuh** sebagai jaring pengaman | `Pembiayaan::daftarAkadKanonik()` (8 akad) selalu dikenali | `test_akad_kanonik_selalu_dikenali_sistem` |
| K11.9 | Nilai akun tidak valid ditolak dengan pesan jelas | Validasi `exists:coas,id` + pesan Bahasa Indonesia | `test_pemetaan_akun_tidak_valid_ditolak` |
| K11.10 | Panel dapat diakses & ramah pembaca layar | `aria-label`, `<caption class="sr-only">`, `role="status"`/`role="alert"` | `test_halaman_kebijakan_menampilkan_panel_pemetaan_akad` |

Total: **12 pengujian, 32 assertion** (`tests/Feature/FleksibilitasAkadPsakTest.php`), semuanya lulus.

---

## 3. Arsitektur: Rantai Resolusi Akun CoA

Untuk setiap akad, sistem menelusuri akun secara berurutan dan **berhenti pada yang pertama cocok**:

```
1. Pemetaan berbasis DATA  (tabel pemetaan_akad_coa)      ← diisi Pengurus dari UI
        │  kolom: account_piutang_id / account_pendapatan_id /
        │          account_margin_ditangguhkan_id
        │  (kosong / menunjuk akun nonaktif → lanjut ke 2)
        ▼
2. Pemetaan dari KEBIJAKAN KOPERASI (kebijakan_koperasis)
        │  kolom: account_piutang_murabahah_id, account_investasi_mudharabah_id,
        │          account_piutang_ijarah_id, account_margin_deferred_id, dst.
        ▼
3. REGISTRI STANDAR (App\Support\Coa)                     ← jaring pengaman terakhir
        │  konstanta kode akun (PIUTANG_MURABAHAH = 1-2001, dst.)
```

Dengan pola ini:

- **Tidak ada nomor akun yang ditanam** di Controller/Service pencairan.
- Akun yang dipindahkan Pengurus langsung berlaku pada jurnal pencairan, angsuran, dan CKPN.
- Bila semua pemetaan kosong, sistem tetap menghasilkan jurnal yang benar (standar bawaan).
- Bila sebuah akun dinonaktifkan di master CoA, sistem **tidak gagal** — ia mencatat peringatan
  ke log dan memakai akun standar, sehingga operasional tidak pernah terhenti.

---

## 4. Titik Kaku yang Diselesaikan

| Lokasi lama (kaku) | Sifat kaku | Penyelesaian Tahap 11 |
|---|---|---|
| `Pembiayaan::daftarAkad()` (konstanta akad) | Daftar akad tidak dapat bertambah tanpa edit kode | Dipecah: `daftarAkadKanonik()` (jaring pengaman) + `daftarAkad()` (dinamis dari basis data) |
| `App\Support\Coa::PIUTANG_BY_AKAD` | Akad → akun piutang dipatok kode | `CoaResolver::kodePiutangByAkad()` membaca pemetaan basis data lebih dulu |
| `App\Support\Coa::PENDAPATAN_BY_AKAD` | Akad → akun pendapatan dipatok kode | `CoaResolver::kodePendapatanByAkad()` (metode baru) membaca pemetaan basis data |
| `App\Support\Coa::MARGIN_DITANGGUHKAN_BY_AKAD` | Akad → akun margin ditangguhkan dipatok kode | `CoaResolver::kodeMarginDitangguhkanByAkad()` membaca pemetaan basis data lebih dulu |

> **Catatan penting:** `App\Support\Coa` **tidak dihapus**. Konstanta tersebut kini berperan sebagai
> *registri standar* — rujukan terakhir yang menjaga sistem tetap berjalan benar pada instalasi
> baru, saat pemulihan bencana, atau bila tabel pemetaan belum terisi. Ini prinsip *defensive
> design*: dinamis, tetapi tidak rapuh.

---

## 5. Panduan Operasional Pengurus

### 5.1 Menambah Akad Syariah Baru

1. Buka menu **Pengurus → Kebijakan Koperasi**.
2. Gulir ke panel **“Pemetaan Akad Syariah ke Akun CoA”**.
3. Pada salah satu baris, ubah kolom **Akad** menjadi **“— Akad Baru —”**.
4. Isikan **Nama Akad** pada kolom teks di bawahnya (mis. `Musyarakah Mutanaqisah`).
5. Pilih akun **Piutang/Penyertaan**, **Pendapatan**, dan **Margin Ditangguhkan** (boleh
   dikosongkan bila ingin mengikuti standar).
6. Klik **Simpan Pemetaan Akad**.
7. Akad baru **langsung tersedia** pada formulir pengajuan/pencairan pembiayaan — tanpa perlu
   deploy ulang.

### 5.2 Memindahkan Akun CoA Suatu Akad

1. Buka panel **Pemetaan Akad Syariah**.
2. Ubah dropdown akun yang diinginkan pada baris akad terkait.
3. Simpan. Perubahan berlaku pada transaksi **berikutnya** (jurnal yang sudah diposting
   tidak diubah otomatis — sesuai prinsip akuntansi, koreksi dilakukan lewat jurnal koreksi).

### 5.3 Mengembalikan ke Standar

Kosongkan kembali dropdown akun, lalu simpan. Sistem akan kembali memakai pemetaan kebijakan
koperasi / registri standar.

---

## 6. Panduan Pemeliharaan & Pemantauan

### 6.1 Pemantauan Rutin

| Yang dipantau | Cara | Tindakan bila tidak wajar |
|---|---|---|
| Peringatan resolver akun | Cari `CoaResolver:` pada `storage/logs/laravel.log` | Perbaiki pemetaan akun yang menunjuk CoA nonaktif / terhapus |
| Peringatan pemetaan akad | Cari `PemetaanAkadCoa:` pada `storage/logs/laravel.log` | Pastikan tabel `pemetaan_akad_coa` ada dan dapat diakses |
| Perubahan pemetaan | Kartu **Riwayat Perubahan Konfigurasi** di menu Kebijakan | Verifikasi bahwa perubahan memang dilakukan pengurus berwenang |

### 6.2 Uji Pasca-Ubah Akun (Smoke Test)

Setelah mengubah pemetaan akun, lakukan uji terbatas di lingkungan non-produksi:

1. Buat pengajuan pencairan pembiayaan pada akad yang baru diubah.
2. Periksa jurnal yang terbentuk: pastikan akun piutang/pendapatan/margin sesuai pilihan baru.
3. Periksa laporan **Posisi Keuangan PSAK 101** — pastikan tetap seimbang.

### 6.3 Kapan Harus Menjalankan Test Suite

Setiap kali menyentuh kode terkait pemetaan akad, jalankan:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\run-tests.ps1 -Filter FleksibilitasAkadPsakTest
```

Runner resmi ini **wajib** karena menjalankan `php artisan optimize:clear` lebih dahulu —
lihat `docs/PANDUAN-PENGUJIAN.md`.

---

## 7. Bukti Pengujian

| Berkas | Jumlah | Fokus |
|---|---|---|
| `tests/Feature/FleksibilitasAkadPsakTest.php` | 12 test / 32 assertion | Daftar akad dinamis, resolver berbasis data, fallback aman, otorisasi, validasi, tampilan panel |
| Suite penuh (`scripts\run-tests.ps1`) | **1101 test / 5087 assertion** | Tanpa regresi |

Perintah:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\run-tests.ps1
```

---

## 8. Risiko Sisa

| # | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| R1 | **Perubahan akun tidak mengoreksi jurnal historis** | Jurnal lama tetap memakai akun lama | Sesuai prinsip akuntansi: koreksi dilakukan lewat jurnal koreksi, bukan penulisan ulang riwayat. Prosedur ada di §5.2 |
| R2 | **Salah pilih akun** oleh pengurus | Laporan keuangan dapat salah klasifikasi | Dropdown menampilkan kode + nama akun; ada audit trail; proses uji pasca-ubah (§6.2) |
| R3 | **Akun dinonaktifkan** setelah dipakai di pemetaan | Resolver mundur ke standar + peringatan log | Pemantauan log (§6.1); pesan `Log::warning` menyebut akad, kolom, dan id CoA |
| R4 | Akad baru dibuat tetapi **tidak dipetakan akunnya** | Pembiayaan memakai akun standar (mungkin kurang tepat) | Boleh dikosongkan secara sadar; uji pasca-ubah mendeteksi lebih dini |
| R5 | Nama akad berbeda tipis (mis. `Murabahah` vs `Murabahah `) | Duplikasi baris pemetaan | Normalisasi kunci akad (huruf kecil + spasi tunggal) di `kunciAkad()`; unique `(kode_produk, akad_pembiayaan)` |
| R6 | Pemetaan per-produk belum diekspos di UI | Belum dapat membedakan akun per produk | Kolom `kode_produk` sudah tersedia di skema (nilai `*` = semua produk); pengembangan UI lanjutan dapat ditambahkan tanpa migrasi baru |

---

## 9. Peran & Tanggung Jawab

| Peran | Tanggung jawab |
|---|---|
| **Pengurus** | Menetapkan & memutakhirkan pemetaan akad → akun CoA; memastikan klasifikasi sesuai karakter akad |
| **Dewan Pengawas Syariah (DPS)** | Memverifikasi bahwa akad baru dan pemetaan akunnya sesuai fatwa DSN-MUI & prinsip syariah |
| **Manajer** | Mengusulkan akad/produk baru beserta kebutuhan akunnya; menjalankan uji pasca-ubah |
| **Tim TI** | Memastikan tabel `pemetaan_akad_coa` termigrasi; memantau log resolver; menjaga uji regresi tetap hijau |
| **Auditor Internal** | Menelaah kartu Riwayat Perubahan Konfigurasi dan kesesuaian akun pada laporan PSAK 101 |

---

## 10. Hubungan dengan Tahap Lain

- **TAHAP2 (PSAK Inti)** — CKPN, DST, dana zakat/infak, dan neraca PSAK 101 tetap menjadi dasar.
  Tahap 11 melengkapinya dengan **pemetaan akun yang dapat dipindahkan Pengurus.**
- **TAHAP5 (Hardening)** — gerbang cutoff, FK `restrict`, dan batas transaksi teller tetap berlaku;
  pemetaan akun tidak melewati kontrol tersebut.
- **TAHAP9 (Aksesibilitas)** — panel pemetaan mengikuti kaidah WCAG 2.1 AA (label ARIA, caption
  tersembunyi, pengumuman status berperan `role`).
- **TAHAP10 (Keamanan Data UU PDP)** — rute pemetaan hanya dapat diakses `role:pengurus`.
- **Butir induk "sistem dinamis & fleksibel"** — **TUNTAS** bersama dokumen ini.

---

## 11. Berkas Terkait

| Berkas | Peran |
|---|---|
| `database/migrations/2026_09_30_003000_create_pemetaan_akad_coa_table.php` | Skema + backfill akad kanonik (akun kosong = ikuti standar) |
| `app/Models/PemetaanAkadCoa.php` | Model; daftar akad dinamis, peta ter-cache, pembersihan cache otomatis |
| `app/Models/Pembiayaan.php` | `daftarAkadKanonik()` (jaring pengaman) + `daftarAkad()` (dinamis) |
| `app/Services/CoaResolver.php` | Rantai resolusi: basis data → kebijakan → registri standar |
| `app/Support/Coa.php` | Registri standar akun (fallback terakhir) |
| `app/Http/Controllers/Dashboard/PengurusController.php` | `kebijakan()` menyediakan data panel; `updatePemetaanAkad()` menyimpan |
| `resources/views/pengurus/kebijakan.blade.php` | Panel UI **Pemetaan Akad Syariah ke Akun CoA** |
| `routes/web.php` | `pengurus.kebijakan.pemetaan-akad` (grup `role:pengurus`) |
| `tests/Feature/FleksibilitasAkadPsakTest.php` | 12 pengujian fleksibilitas akad & PSAK |
| `docs/PANDUAN-PENGUJIAN.md` | Prosedur pengujian (wajib `optimize:clear` lebih dahulu) |
| `docs/TAHAP2-PSAK-INTI.md` | Fondasi kepatuhan PSAK |
