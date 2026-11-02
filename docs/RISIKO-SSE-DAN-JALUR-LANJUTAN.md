# RISIKO SISA SSE & JALUR PENGEMBANGAN LANJUTAN

**Status:** 📄 TERDOKUMENTASI (risiko diterima / *accepted risk*)
**Tanggal:** 2026-09-30
**Pemilik dokumen:** Tim Pengembang Sistem Informasi Koperasi Syariah
**Audiens:** **Seluruh tim pengembangan** dan **pemangku kepentingan**
(Pengurus, Manajer, DPS, Pengawas, pengelola TI).
**Kaitan:** `docs/TAHAP8-REAL-TIME.md` §7 (mesin "Denyut"), §6.3 (mematikan jalur cepat).

> **Ringkasan satu paragraf (untuk pembaca non-teknis).**
> Fitur pembaruan otomatis dashboard memakai teknologi bernama **SSE**.
> Konsekuensinya: **setiap pengguna yang sedang membuka dashboard menahan satu
> proses PHP di server sampai ia menutup tab.** Selama jumlah pengguna serentak
> masih dalam batas kapasitas server, ini **aman dan sudah diukur**. Bila kelak
> pengguna serentak tumbuh jauh lebih besar, jalur peningkatan yang tepat adalah
> **WebSocket** atau layanan **Pusher** — namun **kedua opsi itu berada DI LUAR
> lingkup proyek saat ini** dan belum diimplementasikan.

---

## 1. Pernyataan Risiko (transparan)

| # | Risiko sisa | Sifat | Tingkat saat ini |
|---|---|---|---|
| **RS-1** | **SSE mempertahankan 1 proses PHP untuk setiap koneksi yang aktif.** Selama tab dashboard terbuka dan alur cepat menyala, satu *worker* PHP CLI terpakai khusus untuk koneksi itu. | Struktural (melekat pada cara kerja SSE) | **Rendah–Sedang** — terkendali pada skala saat ini |
| **RS-2** | Bila jumlah pengguna serentak melampaui jumlah worker PHP yang tersedia, permintaan **pengguna lain** dapat mengantre/menunggu. | Turunan dari RS-1 | **Rendah** pada skala saat ini |
| **RS-3** | Jaminan "pembaruan < 2 detik" diukur pada lingkungan ini (driver cache `file`, satu host). Pada latensi jaringan eksternal, angka aktual bergantung pada RTT klien. | Lingkungan | **Rendah** |
| **RS-4** | Degradasi anggun menyembunyikan detail: bila cache mati, gejala pengguna tetap "dashboard diam". | Observabilitas | **Rendah** — kini **tercatat** & **terdeteksi** |

**Kami menyatakan secara terbuka:** RS-1 **tidak dihilangkan**, melainkan
**diterima secara sadar** dengan mitigasi berlapis (§3). Menghilangkannya
sepenuhnya menuntut perubahan arsitektur (§5) yang **di luar lingkup proyek saat
ini**.

---

## 2. Mengapa SSE, dan apa akibatnya

Lingkungan sistem ini (Laragon + PHP CLI) **tidak** memiliki WebSocket asli
maupun broker pesan: `.env` menetapkan `BROADCAST_DRIVER=log` dan
`QUEUE_CONNECTION=sync` (lihat `docs/TAHAP8-REAL-TIME.md` §1.1 akar A & B).
Menambahkan WebSocket/Pusher berarti **menambah infrastruktur layanan eksternal**
(daemon/Redis/Pusher), bukan sekadar mengubah konfigurasi.

Karena itu dipilih **SSE** — transportasi *push* tercepat yang **tersedia tanpa
dependensi tambahan** — dengan **long polling** dan **polling adaptif** sebagai
fallback berjenjang.

**Konsekuensi teknis yang jujur:** SSE adalah koneksi **HTTP panjang**.
Sementara koneksi hidup, ia memegang **satu proses PHP** (satu *worker*). Ini
adalah **biaya wajar** dari SSE di lingkungan PHP-FPM/PHP CLI, dan **bukan bug**.

### Ilustrasi kapasitas (angka indikatif, bukan janji)

| Skenario pengguna serentak (tab dashboard terbuka) | Proses PHP tertahan oleh SSE | Penilaian |
|---|---|---|
| 10–50 | 10–50 | Aman |
| 50–200 | 50–200 | Perlu pemantauan; sesuaikan jumlah worker |
| > jumlah worker tersedia | melampaui kapasitas | **Wajib** matikan alur cepat (§3.1) atau naikkan arsitektur (§5) |

> Angka pasti bergantung pada jumlah worker PHP (`pm.max_children` untuk
> PHP-FPM, atau jumlah proses `php artisan serve`) dan memori server. **Ukur
> sebelum menyimpulkan** — jangan berasumsi.

---

## 3. Mitigasi yang sudah ada (terpasang & terverifikasi)

### 3.1 Saklar mati total lewat variabel lingkungan (tanpa ubah kode)

Alur cepat dapat **dimatikan sepenuhnya** hanya dengan mengubah `.env`:

```dotenv
DENYUT_SSE=false      # matikan SSE (transportasi "1 proses per koneksi")
DENYUT_TUNGGU=false   # matikan long polling
```

Bila keduanya `false`, klien **otomatis beralih** ke polling adaptif — **tanpa
memuat ulang halaman**, tanpa intervensi operator. Ini adalah **peredam
operasional utama** untuk RS-1: pada jam sibuk atau saat kapasitas menipis,
alur cepat cukup dimatikan.

Rujukan: `config/denyut.php`; `docs/TAHAP8-REAL-TIME.md` §6.3.

### 3.2 Transportasi berjenjang (fallback otomatis)

`SSE → long polling → polling adaptif`. Klien memilih transportasi terbaik yang
tersedia dan **turun satu tingkat** bila yang di atas tidak dapat dipakai.

### 3.3 Polling nol beban basis data

Endpoint `/denyut` **tidak menyentuh database** (0 kueri; dibuktikan
`test_snapshot_tidak_membuka_database`). Long polling/polling adaptif tetap
murah karena hanya membaca buku versi di cache.

### 3.4 Pemantauan & deteksi dini

- Kanal log khusus `storage/logs/denyut.log` (daily, 30 hari).
- Perintah operator: `php artisan denyut:kesehatan --rinci` — **exit code
  non-nol** bila tidak sehat, dapat dipakai sebagai gerbang pada cron/pipeline.
- Klien memancarkan event `denyut:transportasi` saat status transportasi
  berubah, sehingga pemadaman alur cepat dapat dipantau dari sisi halaman.

---

## 4. Prosedur operasional bila kapasitas menipis

| Gejala | Tindakan |
|---|---|
| Server mulai lambat melayani permintaan biasa | Set `DENYUT_SSE=false` pada `.env`, lalu `php artisan optimize:clear` |
| Masih berat | Set juga `DENYUT_TUNGGU=false` → seluruh klien memakai polling adaptif |
| Ingin tahu kondisi mesin denyut | `php artisan denyut:kesehatan --rinci` |
| Curiga ada koneksi menahan worker | Periksa log `storage/logs/denyut.log` dan jumlah proses PHP aktif |

> Setelah mengubah `.env`, **selalu** jalankan `php artisan optimize:clear`
> (lihat `docs/RUNNING-SERVER.md` bagian 7 — Prosedur Pengujian).

---

## 5. Jalur pengembangan lanjutan (untuk skala besar)

Bila kebutuhan skala tumbuh melampaui kapasitas yang dapat dilayani SSE dengan
aman, dua jalur berikut adalah **pengganti yang wajar** untuk transportasi
real-time:

| Jalur | Ringkasan | Prasyarat infrastruktur | Dampak pada kode |
|---|---|---|---|
| **WebSocket** (mis. `laravel/reverb`) | Koneksi dua arah persisten; *server push* murni; tidak menahan satu proses PHP per koneksi. | Proses daemon Reverb + (disarankan) Redis; supervisor/process manager; port khusus. | Perlu penambahan *broadcasting*; `DenyutData`/observer **dapat dipertahankan** sebagai sumber event. |
| **Layanan Pusher** (atau setara: Ably, dsb.) | *Push* terkelola pihak ketiga; tanpa daemon sendiri. | Akun & kredensial layanan; koneksi internet keluar; biaya langganan. | Penambahan driver broadcasting; `DenyutData`/observer **dapat dipertahankan**. |

**Catatan desain yang menguntungkan:** mesin **Denyut** sudah memisahkan
*deteksi perubahan* (buku versi + observer) dari *transportasi*. Karena itu,
berpindah ke WebSocket/Pusher kelak **tidak** menuntut penulisan ulang logika
bisnis — hanya lapisan transportasi yang diganti.

### 5.1 Pernyataan lingkup (WAJIB dibaca)

> **Baik implementasi WebSocket maupun layanan Pusher berada DI LUAR LINGKUP
> proyek saat ini.** Keduanya **belum** dikerjakan, **belum** diuji, dan
> **bukan** bagian dari deliverable yang diserahkan. Bagian ini murni
> **rekomendasi jalur** untuk keputusan pengembangan di masa depan — bukan
> komitmen implementasi.

---

## 6. Keputusan & persetujuan

| Peran | Keputusan yang diharapkan |
|---|---|
| Tim Pengembang | Menerima RS-1 sebagai risiko sadar, dengan mitigasi §3 terpasang |
| Pengelola TI / Operasional | Mengetahui saklar `DENYUT_SSE`/`DENYUT_TUNGGU` sebagai peredam darurat (§3.1, §4) |
| Pemangku kepentingan | Mengetahui bahwa peningkatan skala besar menuntut jalur §5 (**di luar lingkup saat ini**) dan keputusan anggaran/infrastruktur tersendiri |

**Siklus peninjauan:** dokumen ini ditinjau ulang **setiap kali** ada
peningkatan jumlah pengguna serentak yang signifikan, perubahan infrastruktur
server, atau sebelum perencanaan anggaran pengembangan lanjutan.

---

## 7. Rujukan

- `docs/TAHAP8-REAL-TIME.md` — mesin Denyut; §1.1 akar masalah A–B; §6.3 mematikan
  jalur cepat; §6.4 wajib `optimize:clear`; §7 risiko sisa.
- `docs/RUNNING-SERVER.md` — bagian 7 (Prosedur Pengujian), bagian 11 (Pemecahan
  Masalah Cepat).
- `config/denyut.php` — saklar `DENYUT_SSE`, `DENYUT_TUNGGU`, jeda & tenggat.
- `.env.example` — blok konfigurasi Denyut.
- `app/Services/DenyutMonitor.php`, `app/Console/Commands/DenyutKesehatan.php` —
  pemantauan & perintah kesehatan.
