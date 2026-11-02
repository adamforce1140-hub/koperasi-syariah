# Panduan Konfigurasi &amp; Pengaturan Fitur Simpanan Wajib

**Untuk:** Pengurus, Manajer Operasional, Dewan Pengawas Syariah (DPS), Kepala Teller
**Versi:** 1.2 — 2026-10-13 (Jatuh Tempo Otomatis; penanganan dinamis v1.1)
**Dasar syariah:** PSAK 101 ¶ 31 &amp; ¶ 45–47; Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000;
QS. Al-Baqarah 2:280 (tidak ada denda atas keterlambatan)
**Dasar regulasi:** UU No. 25/1992 Pasal 30; Permen KUKM No. 09/2018 Pasal 24–26;
UU No. 27/2022 (Pelindungan Data Pribadi)

> **Prinsip utama panduan ini:** seluruh pengaturan di bawah ini dapat diubah
> **tanpa mengubah kode program dan tanpa menghentikan layanan**. Perubahan berlaku
> pada transaksi **berikutnya** dan tercatat otomatis di jejak audit.

---

## Bagian 1 — Identifikasi Modul &amp; Lokasi Pengaturan

### 1.1 Di mana fitur ini berada?

| Lapisan | Lokasi | Fungsi |
|---|---|---|
| **Panel Pengurus** | Menu **Kebijakan** → panel **"Ketentuan Simpanan Wajib"** | Tempat Pengurus mengubah seluruh parameter |
| **Loket Teller** | Menu **Teller → Simpanan Wajib** | Daftar tertagih (identitas, nominal, tenggat) + pelunasan &amp; penanganan dinamis |
| **Riwayat tindakan** | Teller → Simpanan Wajib → tombol **Riwayat** | Audit trail pemotongan &amp; penjadwalan ulang (transparansi anggota) |
| **Proses otomatis harian** | Scheduler `mandatory-savings:process-daily` | Menerbitkan tagihan bulanan + auto-debit |
| **Gerbang kepatuhan** | Command `php artisan psak:validasi-simpanan-wajib` | Memeriksa kepatuhan PSAK 101/108 |

### 1.2 Berkas teknis (untuk Tim TI)

| Fungsi | Berkas |
|---|---|
| Logika inti modul | `app/Services/MandatorySavingsService.php` |
| Jurnal otomatis | `app/Services/AutoJournalingService.php` |
| Permukaan loket | `app/Http/Controllers/Teller/SimpananWajibController.php` |
| Riwayat/audit tindakan | `app/Models/SimpananWajibTindakan.php`, `resources/views/teller/simpanan-wajib-riwayat.blade.php` |
| Panel kebijakan | `app/Http/Controllers/Dashboard/PengurusController.php` |
| Tampilan kebijakan | `resources/views/pengurus/kebijakan.blade.php` |
| Registri parameter | `app/Support/Setting.php` |
| Gerbang kepatuhan | `app/Console/Commands/ValidasiKepatuhanSimpananWajib.php` |
| Proses terjadwal | `routes/console.php` |

---

## Bagian 2 — Parameter yang Dapat Diubah

### 2.1 Tabel Parameter

| Parameter | Arti bagi Pengurus | Nilai Sah | Default | Letak di UI |
|---|---|---|---|---|
| `mandatory_monthly_savings` | **Batas nominal** Simpanan Wajib yang ditagih setiap bulan | ≥ 0 (Rupiah) | Rp 50.000 | Ketentuan Simpanan Wajib |
| `mandatory_principal_savings` | **Batas nominal** Simpanan Pokok saat pembukaan keanggotaan | ≥ 0 (Rupiah) | Rp 100.000 | Ketentuan Simpanan Wajib |
| `simpanan_wajib_kanal_default` | Kanal pembayaran default anggota baru | `AUTO_DEBIT` \| `CASH` \| `TRANSFER` | `AUTO_DEBIT` | Ketentuan Simpanan Wajib |
| `simpanan_wajib_boleh_ditarik` | **Batasan akses**: izinkan penarikan saldo Simpanan Wajib | aktif / nonaktif | **nonaktif** | Ketentuan Simpanan Wajib |
| `simpanan_wajib_hari_penarikan` | Waktu penarikan diizinkan | `SETELAH_KELUAR` \| `KAPAN_SAJA` | `SETELAH_KELUAR` | Ketentuan Simpanan Wajib |
| `shu_hold_on_overdue` | **Aturan akumulasi**: tahan pencairan SHU anggota yang menunggak | aktif / nonaktif | aktif | Ketentuan SHU |
| `account_simpanan_wajib_id` | Akun buku besar (CoA) tujuan Simpanan Wajib | ID akun **aktif** | kosong → `3-1002` | Ketentuan Simpanan Wajib |

### 2.1b Parameter Penanganan Dinamis Tunggakan (baru — v1.1)

Seluruh parameter di bawah ini **default-nya mengamankan perilaku sistem saat ini**
(menerapkan prinsip *default aman*): mengubahnya tidak pernah merusak data historis,
hanya mengubah perilaku transaksi berikutnya.

| Parameter | Arti bagi Pengurus | Nilai Sah | Default | Letak di UI |
|---|---|---|---|---|
| `simpanan_wajib_auto_debit_aktif` | **Saklar induk** modul auto-debet. Nonaktif = sistem **tidak pernah** memotong saldo Sukarela; semua tagihan dibayar manual di loket | aktif / nonaktif | aktif | Ketentuan Simpanan Wajib |
| `simpanan_wajib_penanganan_dinamis` | **Saklar induk** penanganan dinamis (jadwal bertahap &amp; reschedule tanpa denda) saat saldo Sukarela kurang/kosong. Nonaktif = hanya tandai OVERDUE + notifikasi | aktif / nonaktif | aktif | Ketentuan Simpanan Wajib |
| `simpanan_wajib_maks_cicilan` | **Maksimum jumlah cicilan** pada penjadwalan bertahap. `0` = tanpa batas | ≥ 0 (bilangan bulat) | 6 | Ketentuan Simpanan Wajib |
| `simpanan_wajib_reschedule_bulan` | **Jarak penggeseran** tenggat saat reschedule (dalam bulan) | 0–12 | 1 | Ketentuan Simpanan Wajib |
| `simpanan_wajib_maks_reschedule` | **Maksimum berapa kali** satu tagihan boleh dijadwalkan ulang. `0` = tanpa batas | ≥ 0 (bilangan bulat) | 3 | Ketentuan Simpanan Wajib |
| `simpanan_wajib_notif_anonim` | Notifikasi tunggakan **tidak mengekspos identitas anggota** (patuh UU 27/2022 PDP) | aktif / nonaktif | aktif | Ketentuan Simpanan Wajib |
| `simpanan_wajib_ambang_kurang_ringan` | **Ambang kekurangan "ringan"**. Kekurangan ≤ nilai ini ditangani dengan pendekatan berbeda (mis. toleransi/penjadwalan ringan) dibanding kekurangan berat | ≥ 0 (Rupiah) | Rp 25.000 | Ketentuan Simpanan Wajib |

> **Catatan penting:** `denda` **selalu 0** pada seluruh jalur. Koperasi syariah tidak
> mengenakan denda/bunga atas keterlambatan (QS. Al-Baqarah 2:280). Kolom denda hanya
> dicatat sebagai bukti kepatuhan, bukan sebagai beban anggota.

### 2.2 Bagaimana Tanggal Jatuh Tempo Ditentukan? (Otomatis)

Tanggal jatuh tempo tagihan Simpanan Wajib **tidak lagi diatur manual oleh Pengurus**.
Sejak pembaruan terbaru, sistem menghitungnya **otomatis per anggota** sebagai
*anniversary* bulanan, terhitung sejak **tanggal anggota bergabung menjadi anggota aktif**
(`tanggal_mulai_keanggotaan`). Dua aturan penyesuaian tanggal berlaku:

1. **Anggota bergabung tgl 1–28** → jatuh tempo pada **tanggal yang sama** setiap bulan,
   dan otomatis di-*clamp* ke hari terakhir bila bulan berjalan lebih pendek (mis. tgl 30
   pada Februari → jatuh tempo 28/29).
2. **Anggota bergabung pada akhir bulan (tgl 29/30/31)** → jatuh tempo **selalu akhir bulan**
   berjalan. Contoh: bergabung 31 Januari → tagihan Februari jatuh tempo 28 Februari
   (29 pada tahun kabisat).

Konsekuensinya, **tidak ada lagi parameter kebijakan** untuk mengatur tanggal jatuh tempo.
Setiap anggota bisa memiliki tanggal jatuh tempo yang berbeda, sesuai tanggal bergabungnya.
Penandaan `OVERDUE` mengikuti tanggal jatuh tempo otomatis masing-masing anggota ini.

> **Catatan historis:** parameter `simpanan_wajib_overdue_day` (tanggal jatuh tempo global
> 1–28, default 10) **telah dihapus menyeluruh** dari sistem — UI, validasi, model, registry
> `Setting`, dan kolom database `kebijakan_koperasis` — karena usang. Lihat
> `database/migrations/2026_10_13_000100_drop_simpanan_wajib_overdue_day_from_kebijakan_koperasis.php`
> dan `MandatorySavingsService::tanggalJatuhTempo()`.

### 2.3 Mengapa Penarikan Default NONAKTIF?

Menurut **PSAK 101 ¶ 31**, Simpanan Wajib adalah **Modal Anggota** (ekuitas), bukan tabungan
bebas. Karena itu saldo secara bawaan **terkunci** dan baru dapat ditarik bila anggota keluar
dari keanggotaan. Kondisi bawaan ini **sesuai standar** dan **tidak perlu diubah** kecuali
Anggaran Dasar koperasi Anda memang mengizinkan penarikan.

### 2.4 Aturan Akumulasi yang Berlaku

| Aturan | Perilaku Sistem |
|---|---|
| Akumulasi setoran | Setiap bulan, tagihan baru diterbitkan untuk **setiap anggota aktif**; saldo terakumulasi sebagai modal anggota (akun `3-1002`) |
| Anti-duplikat | Satu anggota **hanya boleh** memiliki **satu** tagihan per bulan. Percobaan penerbitan ulang tidak menghasilkan tagihan baru |
| Anggota akhir bulan | Anggota yang bergabung pada akhir bulan tetap ditagih bulan itu, jatuh tempo disesuaikan |
| Anggota tidak aktif | **Tidak ditagih** otomatis |
| Tunggakan | Tagihan yang melewati tanggal jatuh tempo ditandai **`OVERDUE`** |
| Penahanan SHU | Bila `shu_hold_on_overdue` aktif, SHU anggota yang menunggak **ditahan** hingga tagihannya lunas |

### 2.4b Alur Penanganan Dinamis saat Saldo Sukarela Kurang/Kosong (v1.1)

Ketika auto-debet tidak dapat memotong tagihan karena **saldo Simpanan Sukarela tidak
mencukupi atau kosong**, sistem **tidak** langsung menolak/mengunci anggota. Sistem
mengadaptasi prinsip koperasi syariah yang **adil &amp; memberdayakan** melalui tiga jalur:

1. **Penjadwalan pembayaran bertahap** — tagihan dipecah menjadi beberapa cicilan sesuai
   kemampuan anggota (maksimum mengikuti `simpanan_wajib_maks_cicilan`). Setiap cicilan
   berikutnya dipotong dari saldo Sukarela bila tersedia.
2. **Notifikasi otomatis informatif &amp; aman** — pesan memuat periode, nominal, tenggat, dan
   opsi pemulihan, **tanpa** menyebut nama/`no_anggota`/NIK anggota (bila
   `simpanan_wajib_notif_anonim` aktif) sehingga tidak mengekspos identitas pada kanal publik.
3. **Penjadwalan ulang tenggat TANPA DENDA** — tenggat digeser `simpanan_wajib_reschedule_bulan`
   bulan ke depan, maksimum `simpanan_wajib_maks_reschedule` kali per tagihan. **Tidak ada
   denda/bunga** (QS. Al-Baqarah 2:280).

Seluruh keputusan di atas tercatat **permanen (append-only)** pada riwayat tindakan dan dapat
dibuka Teller kapan pun melalui tombol **Riwayat** — transparansi bagi anggota &amp; bahan audit.

### 2.5 Batasan Akses (Siapa Boleh Apa)

| Peran | Kewenangan |
|---|---|
| **Pengurus** | Mengubah seluruh parameter kebijakan di atas |
| **Kepala Teller / Teller** | Melihat daftar tertagih, melakukan pelunasan, menetapkan jadwal bertahap, memproses cicilan/reschedule, dan membuka riwayat tindakan |
| **Anggota** | Hanya melihat tagihannya sendiri |
| **DPS** | Membaca laporan &amp; membaca hasil gerbang kepatuhan (tidak mengubah parameter) |

Perubahan parameter **selalu** tercatat: siapa mengubah, kapan, dan nilai lama → nilai baru.

---

## Bagian 3 — Panduan Aturan Bisnis: Kapan Mengubah Apa

### 3.1 Skenario Perubahan yang Umum

| Kondisi Koperasi | Yang Diubah | Dampak |
|---|---|---|
| Rapat Anggota menaikkan iuran dari Rp 50.000 → Rp 75.000 | `mandatory_monthly_savings` | Tagihan **bulan berikutnya** memakai nominal baru. Tagihan yang **sudah terbit** &amp; historis **tidak berubah** |
| Ingin mengubah tanggal jatuh tempo tagihan | **Tidak dapat diubah lewat kebijakan** — jatuh tempo bersifat **otomatis** mengikuti tanggal bergabung anggota (lihat §2.2) | Setiap anggota mengikuti *anniversary* bulanan tanggal bergabungnya; penandaan `OVERDUE` mengikuti tanggal otomatis tersebut sejak proses harian berikutnya |
| Ingin seluruh anggota baru otomatis dipotong dari Simpanan Sukarela | `simpanan_wajib_kanal_default` = `AUTO_DEBIT` | Anggota yang mendaftar **setelah** perubahan memakai kanal ini |
| Musim panen — anggota ingin bayar tunai di loket | `simpanan_wajib_kanal_default` = `CASH` | Tagihan baru berstatus `UNPAID` menunggu pelunasan di loket |
| Anggaran Dasar mengizinkan penarikan saat keluar | `simpanan_wajib_boleh_ditarik` = aktif + `simpanan_wajib_hari_penarikan` = `SETELAH_KELUAR` | **Catatan:** alur penarikan di loket belum tersedia pada versi ini (lihat §3.2) |
| Menunggak tidak lagi menahan SHU | `shu_hold_on_overdue` = nonaktif | SHU anggota menunggak tidak lagi ditahan |
| Akuntansi ingin memakai akun CoA lain | `account_simpanan_wajib_id` | **Hanya** mengubah akun untuk transaksi **berikutnya**. Transaksi historis tidak berubah |
| Banyak anggota gagal potong karena saldo Sukarela tipis | `simpanan_wajib_maks_cicilan` (mis. `3`) | Jadwal bertahap memakai lebih sedikit cicilan → tagihan cepat lunas |
| Ingin menonaktifkan auto-debet sementara (mis. musim panen) | `simpanan_wajib_auto_debit_aktif` = nonaktif | Sistem berhenti memotong saldo Sukarela; tagihan dibayar manual di loket |
| Tidak ingin menawarkan cicilan/reschedule otomatis | `simpanan_wajib_penanganan_dinamis` = nonaktif | Hanya penandaan `OVERDUE` + notifikasi; penanganan sepenuhnya manual |
| Ingin memberi ruang lebih panjang saat anggota kesulitan | `simpanan_wajib_reschedule_bulan` = `2` atau `3` | Tenggat bergeser lebih jauh — tetap **tanpa denda** |
| Regulator meminta notifikasi tidak menyebut nama | `simpanan_wajib_notif_anonim` = aktif | Notifikasi hanya memuat periode &amp; nominal (tanpa identitas) |

### 3.2 Himbauan Penting Sebelum Mengubah Akun CoA

1. **Konsultasikan dengan DPS** — akun Simpanan Wajib **wajib** berkategori
   **Ekuitas → Modal Anggota** (PSAK 101 ¶ 31). Akun berkategori *Kewajiban* akan
   **ditolak** oleh gerbang kepatuhan.
2. **Pastikan akun berstatus aktif** — sistem menolak akun yang tidak aktif dan otomatis
   kembali ke akun standar `3-1002` (sambil mencatat peringatan di log).
3. **Jangan mengosongkan tanpa alasan** — membiarkannya kosong berarti memakai rantai
   standar (master produk → `3-1002`), yang sudah benar.

### 3.3 Urutan Aman Mengubah Kebijakan (SOP)

1. **Waktu** — lakukan di luar jam sibuk, sebaiknya setelah proses harian selesai.
2. **Catat** — tulis nilai lama dan nilai baru di berita acara.
3. **Ubah** — satu parameter per langkah agar dampaknya mudah ditelusuri.
4. **Verifikasi** — jalankan gerbang kepatuhan (§4.1).
5. **Uji nyata** — lakukan satu transaksi uji pada anggota contoh, periksa jurnalnya.
6. **Dokumentasikan** — simpan berita acara bersama laporan kepatuhan.

---

## Bagian 4 — Langkah Pengujian Mandiri (untuk Pengurus &amp; DPS)

### 4.1 Uji Kepatuhan Otomatis (wajib setelah setiap perubahan)

```bash
php artisan psak:validasi-simpanan-wajib
```

**Cara membaca hasil:**

| Keluaran | Arti | Tindakan |
|---|---|---|
| `✔ LULUS` + semua kategori `0` + exit code `0` | Seluruh transaksi patuh PSAK 101/108 | Lanjutkan |
| Ada kategori bernilai &gt; 0 + exit code `1` | Ditemukan pelanggaran | **JANGAN lanjutkan** — laporkan ke Tim TI &amp; DPS |

Tujuh kategori yang diperiksa: akun Simpanan Wajib salah kategori (kewajiban), tagihan lunas
tanpa jurnal, tagihan lunas tanpa transaksi, jurnal tidak berimbang, nominal tagihan tidak
sesuai jurnal, tagihan duplikat periode, dan saldo modal negatif.

### 4.2 Uji Cepat Fungsional (5 menit)

| # | Langkah | Hasil yang Benar |
|---|---|---|
| 1 | Buka **Teller → Simpanan Wajib**, pilih satu tagihan `UNPAID` | Tagihan tampil dengan nominal yang benar |
| 2 | Lunasi tagihan tersebut (mis. `CASH`) | Muncul pesan sukses + nota |
| 3 | Periksa nota: jurnal tercatat | **Debet** Kas, **Kredit** `3-1002` Simpanan Wajib |
| 4 | Jalankan ulang pelunasan tagihan yang sama | Ditolak/idempoten — **tidak ada jurnal baru** |
| 5 | Jalankan `psak:validasi-simpanan-wajib` | `✔ LULUS`, exit code `0` |

### 4.2b Uji Pelacakan Tertagih &amp; Penanganan Dinamis (v1.1)

| # | Langkah | Hasil yang Benar |
|---|---|---|
| 1 | Buka **Teller → Simpanan Wajib**, perhatikan kolom **Tenggat** | Setiap baris menampilkan **nama &amp; nomor anggota**, nominal tagihan, dan tenggat (mis. `10/10/2026`) |
| 2 | Cari anggota yang menunggak (filter status `OVERDUE` / `UNPAID`) | Baris tunggakan tampil dengan tandaan **"Lewat jatuh tempo"** (merah) bila melewati tenggat |
| 3 | Klik **Jadwal Bertahap** pada tagihan anggota yang saldo Sukarela-nya tidak cukup | Muncul pesan sukses; ringkasan penanganan menampilkan jumlah cicilan |
| 4 | Klik **Bayar Cicilan** | Saldo Sukarela berkurang; sisa kekurangan diperbarui |
| 5 | Klik **Reschedule** | Tenggat bergeser; **denda tetap Rp 0** — tanpa denda |
| 6 | Klik **Riwayat** pada tagihan tersebut | Tampil tabel audit: waktu, jenis tindakan, nominal, hasil, keterangan, petugas — dengan tombol paginasi |
| 7 | Nonaktifkan `simpanan_wajib_auto_debit_aktif` di panel Pengurus, lalu buka ulang loket | Tombol penanganan dinamis tersembunyi / memberi pesan modul nonaktif |
| 8 | Jalankan `psak:validasi-simpanan-wajib` | `✔ LULUS`, exit code `0` |

### 4.3 Uji Perubahan Parameter

| # | Langkah | Hasil yang Benar |
|---|---|---|
| 1 | Ubah nominal `mandatory_monthly_savings` dari 50.000 → 75.000, simpan | Tersimpan + muncul di jejak audit |
| 2 | Periksa panel **Ketentuan Simpanan Wajib** | **Tidak ada lagi** kolom "Tanggal Jatuh Tempo" — jatuh tempo bersifat otomatis (lihat §2.2) |
| 3 | Coba `account_simpanan_wajib_id` yang tidak aktif | **Ditolak** |
| 4 | Kembalikan ke nilai semula | Tersimpan + tercatat di audit |

> **Uji jatuh tempo otomatis:** buat/lihat tagihan dua anggota dengan tanggal bergabung
> berbeda (mis. tgl 10 dan tgl 31). Verifikasi tanggal jatuh tempo tagihan mengikuti
> tanggal bergabung masing-masing anggota (anggota tgl 31 → jatuh tempo akhir bulan).
> Cakupan otomatis: `tests/Feature/JatuhTempoSimpananWajibOtomatisTest.php`.

### 4.4 Uji Regresi Teknis (untuk Tim TI)

```powershell
powershell -ExecutionPolicy Bypass -File scripts\run-tests.ps1
```

Target: **seluruh test lulus** dan exit code `0`. Bila ada yang gagal → **tahan deploy**.

---

## Bagian 5 — Pemeliharaan &amp; Pemantauan

### 5.1 Rutin Harian

| Kegiatan | Cara | Yang Diharapkan |
|---|---|---|
| Pantau proses tagihan otomatis | Periksa `storage/logs/simpanan-wajib-harian.log` | Terlihat jumlah tagihan terbit / terbayar / menunggak, tanpa `ERROR` |
| Pantau tunggakan | Menu Teller → Simpanan Wajib, filter status `OVERDUE` | Daftar tunggakan bertambah sesuai ekspektasi, tidak melonjak tak wajar |
| Pantau penanganan dinamis | Buka **Riwayat** pada tagihan bermasalah | Terlihat riwayat jadwal bertahap, cicilan, reschedule (+ notifikasi) — semuanya `denda = 0` |

### 5.2 Rutin Bulanan

| Kegiatan | Cara |
|---|---|
| Gerbang kepatuhan | `php artisan psak:validasi-simpanan-wajib` → simpan keluarannya sebagai bukti bulan berjalan |
| Rekonsiliasi saldo | Bandingkan saldo akun `3-1002` di buku besar dengan rekap simpanan wajib anggota |
| Tinjauan tunggakan | Laporkan daftar `OVERDUE` ke Manajer Operasional untuk penagihan |

### 5.3 Rutin Tahunan (Sebelum Rapat Anggota)

| Kegiatan | Cara |
|---|---|
| Audit internal | Jalankan gerbang kepatuhan, arsipkan keluaran sebagai bukti audit |
| Tinjauan kebijakan | Periksa apakah nominal (`mandatory_monthly_savings`) masih sesuai keputusan Rapat Anggota |
| Tinjauan penahanan SHU | Pastikan `shu_hold_on_overdue` sesuai kebijakan yang berlaku |

### 5.4 Gejala Masalah &amp; Tindakan Pertama

| Gejala | Kemungkinan Penyebab | Tindakan |
|---|---|---|
| Tagihan bulan ini belum terbit | Proses harian tidak berjalan | Hubungi Tim TI untuk memeriksa scheduler `mandatory-savings:process-daily` |
| Tagihan lunas tetapi saldo tidak bertambah | Akun CoA salah / tidak aktif | Jalankan `psak:validasi-simpanan-wajib`, periksa `account_simpanan_wajib_id` |
| Gerbang kepatuhan melaporkan temuan | Penyimpangan pencatatan | Tahan pelaporan; serahkan ke Tim TI &amp; DPS — **jangan** melakukan koreksi manual di jurnal |
| Muncul tagihan ganda pada anggota | (Secara desain dicegah indeks unik) | Laporkan segera ke Tim TI sebagai anomali |

### 5.5 Pantangan

> **DILARANG** mengubah atau menghapus baris jurnal historis secara langsung di basis data.
> Seluruh koreksi **wajib** melalui **jurnal penyesuaian** yang disetujui Pengurus.
> Larangan ini adalah syarat integritas jejak audit dan Kepatuhan PSAK.

---

*Panduan ini ditutup pada Tahap 12 / R6. Lihat `docs/TAHAP12-R6-DOKUMENTASI-KEPATUHAN.md`
untuk matriks kepatuhan lengkap &amp; catatan rollback.*

*Pembaruan v1.1 (2026-10-05): ditambahkan modul **Pelacakan Tertagih &amp; Penanganan Dinamis
Tunggakan** (daftar tertagih + tenggat, saklar auto-debet, jadwal bertahap, reschedule tanpa
denda, notifikasi aman-PDP, dan riwayat audit `simpanan_wajib_tindakans`). Pengujian:
`tests/Feature/MandatorySavingsPenangananDinamisTest.php` (18 skenario).*

*Pembaruan v1.2 (2026-10-13): parameter **`simpanan_wajib_overdue_day` dihapus menyeluruh**
(UI, validasi, model, registry `Setting`, kolom DB `kebijakan_koperasis`). Tanggal jatuh tempo
kini **100% otomatis** per anggota (*anniversary* bulanan dari tanggal bergabung aktif) —
lihat `MandatorySavingsService::tanggalJatuhTempo()` dan
`tests/Feature/JatuhTempoSimpananWajibOtomatisTest.php`. Panel "Ketentuan Simpanan Wajib" tidak
lagi memuat input tanggal jatuh tempo.*
