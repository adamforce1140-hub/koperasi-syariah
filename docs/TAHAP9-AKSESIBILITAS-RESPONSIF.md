# TAHAP 9 — ANTARMUKA RESPONSIF & AKSESIBILITAS (WCAG 2.1 LEVEL AA)

**Status:** ✅ SELESAI & TERVERIFIKASI
**Tanggal penyelesaian:** 2026-09-30
**Lingkup pekerjaan:** Menyelesaikan **butir induk** *"penerapan UI responsif dan
aksesibilitas yang mematuhi standar internasional"* — butir permintaan induk yang
belum diverifikasi khusus setelah Fase 1–4 (R1–R19) dan Tahap 5–8 tuntas.
**Dasar standar:** **WCAG 2.1 Level AA** (W3C Recommendation) — kriteria 1.1.1,
1.3.1, 1.4.4, 1.4.10, 2.1.1, 2.1.2, 2.2.2, 2.3.3, 2.4.1, 2.4.6, 2.4.7, 3.1.1,
4.1.2, 4.1.3; prinsip *inklusivitas* sistem informasi koperasi syariah (seluruh
anggota, termasuk pengguna pembaca layar & papan tik, berhak mengakses layanan).
**Tahap sebelumnya:** `docs/TAHAP8-REAL-TIME.md` (1007 passed / 4807 assertions)

**Dokumen Pendahulu (rantai deliverable Tahap 2–9):**

| Tahap | Dokumen | Hasil suite saat penutupan |
|---|---|---|
| 2 | `docs/TAHAP2-PSAK-INTI.md` | 826 passed / 4015 assertions |
| 3 | `docs/TAHAP3-PELAPORAN.md` | 854 passed / 4114 assertions |
| 4 | `docs/TAHAP4-GCG-OJK.md` | 872 passed / 4160 assertions |
| 5 | `docs/TAHAP5-HARDENING.md` | 900 passed / 4244 assertions |
| 6 | `docs/TAHAP6-FASE4-KEBERSIHAN-TRANSPARANSI.md` | 955 passed / 4629 assertions |
| 7 | `docs/TAHAP7-SKALABILITAS-JALUR-BACA.md` | 967 passed / 4666 assertions |
| 8 | `docs/TAHAP8-REAL-TIME.md` | 1007 passed / 4807 assertions |
| **9** | **`docs/TAHAP9-AKSESIBILITAS-RESPONSIF.md` (dokumen ini)** | **1035 passed / 4921 assertions** |

---

## 1. Ringkasan Eksekutif

Permintaan induk menuntut agar antarmuka sistem **responsif** (dapat dipakai pada
semua ukuran perangkat yang didukung) dan **memenuhi standar aksesibilitas
internasional**. Tahap 9 menjawabnya dengan **audit kode nyata**, bukan asumsi:
seluruh berkas tampilan yang menjadi kerangka bersama (layout autentikasi
`layouts/app.blade.php` + `layouts/sidebar.blade.php`, layout publik
`layouts/publik.blade.php`, dan formulir publik `pengajuan/form.blade.php`)
diperiksa terhadap WCAG 2.1 AA, lalu setiap celah diperbaiki dan **dikunci test**.

### 1.1 Akar masalah yang ditemukan (audit aksesibilitas)

| # | Temuan | Kriteria WCAG | Dampak |
|---|---|---|---|
| A1 | Tidak ada **tautan "lewati ke konten utama"** | 2.4.1 Bypass Blocks | Pengguna papan tik/pembaca layar harus melewati seluruh menu setiap halaman |
| A2 | Konten utama **bukan landmark** `<main>`, navigasi bukan `<nav aria-label>` | 1.3.1 Informasi & Hubungan | Struktur halaman tidak dapat dinavigasi cepat oleh pembaca layar |
| A3 | Pemicu menu profil adalah **`<div onclick>`** | 2.1.1 Keyboard, 4.1.2 Nama/Peran/Nilai | Tidak dapat difokus/diaktifkan dengan papan tik; peran tidak diumumkan |
| A4 | Tidak ada `aria-expanded` / `aria-controls` pada pemicu menu & sidebar | 4.1.2 | Status buka/tutup tidak diketahui teknologi bantu |
| A5 | Menu hanya dapat ditutup dengan klik di luar | 2.1.2 Tanpa Jebakan Papan Tik | Pengguna papan tik "terjebak" pada menu terbuka |
| A6 | Tidak ada **indikator fokus global**; sebagian CSS memakai `outline: none` | 2.4.7 Fokus Terlihat | Pengguna papan tik kehilangan jejak posisi fokus |
| A7 | **Tidak ada reflow** — sidebar 260px `fixed` menimpa konten di layar ≤900px | 1.4.10 Reflow, 1.4.4 Resize Text | Halaman tidak dapat dipakai di ponsel/tablet & saat zoom 200% |
| A8 | **Ikon & badge emoji** terbaca pembaca layar (mis. "🏦 menunggu tindakan") | 1.1.1 Konten Non-Teks | Kebisingan informasi; makna ganda |
| A9 | Tidak ada dukungan **`prefers-reduced-motion`** | 2.2.2 / 2.3.3 | Pengguna sensitif gerak tidak terlindungi |
| A10 | Warna primer `#059669` berkontras **marginal** pada teks putih | 1.4.3 Kontras (AA) | Teks tombol sulit dibaca pengguna low-vision |
| A11 | Formulir publik: `<label>` **tanpa `for`/`id`**, radio disembunyikan `display:none` | 1.3.1, 2.1.1, 4.1.2 | Field tidak berlabel bagi teknologi bantu; radio tidak dapat difokus papan tik |
| A12 | Formulir publik: tidak ada `<fieldset>`/`<legend>` untuk kelompok radio | 1.3.1 | Hubungan pilihan tidak tersampaikan |
| A13 | Notifikasi publik tanpa **`role="status"`**; SVG logo terbaca sebagai teks | 4.1.3, 1.1.1 | Pesan status tidak diumumkan; logo menduplikasi judul |

---

## 2. Sasaran & Kriteria Keberhasilan

| # | Kriteria | Target | Hasil | Status |
|---|---|---|---|---|
| K9.1 | Tautan lewati & landmark di setiap layout | 100% layout bersama | auth + publik memiliki skip-link, `<main id="konten-utama">`, `<nav aria-label>` | ✅ |
| K9.2 | Struktur judul benar | tepat 1 `<h1>`/halaman | dikunci test | ✅ |
| K9.3 | Semua kontrol interaktif dapat dioperasikan papan tik | 0 elemen `<div onclick>` sebagai kontrol | pemicu profil & sidebar menjadi `<button>` | ✅ |
| K9.4 | Status dinamis terumumkan | `aria-expanded`/`aria-controls`/`aria-live`/`role="status"` | dikunci test (auth & publik) | ✅ |
| K9.5 | Fokus selalu terlihat | gaya `:focus-visible` global + hapus `outline:none` | `--focus-ring` (#b45309 auth / #f0c040 publik) | ✅ |
| K9.6 | Reflow pada 320 CSS px & zoom 200% | tanpa gulir dua arah | media query ≤900px & ≤480px, sidebar geser + backdrop | ✅ |
| K9.7 | Konten non-teks tidak berisik | ikon/badge/emoji `aria-hidden` + teks sr-only | dikunci test | ✅ |
| K9.8 | Hormati preferensi gerak minimal | `prefers-reduced-motion` di semua layout | dikunci test | ✅ |
| K9.9 | Label formulir eksplisit/implisit sah | setiap kontrol berlabel | seluruh `<label>` terverifikasi | ✅ |
| K9.10 | Tidak ada regresi fungsional | suite tetap hijau | 1007 → **1035 passed** (naik 28) | ✅ |

---

## 3. Perbaikan yang Diterapkan

### 3.1 `resources/views/layouts/app.blade.php` (layout autentikasi)

- **Skip-link** `.skip-link` + target `<main id="konten-utama" tabindex="-1">`
  (WCAG 2.4.1); `.sr-only` untuk teks khusus pembaca layar.
- **Landmark & navigasi**: konten dibungkus `<main>`; sidebar menjadi
  `<nav id="sidebarUtama" aria-label="Navigasi utama">`.
- **Pemicu profil** diubah dari `<div onclick>` → `<button type="button"
  aria-haspopup="true" aria-expanded="false" aria-controls="profileMenu">`;
  seluruh anak dekoratif diberi `aria-hidden="true"`.
- **Pemicu sidebar** `<button id="sidebarToggle" aria-controls="sidebarUtama"
  aria-expanded="false">` + backdrop `#sidebarBackdrop` (WCAG 1.4.10).
- **Fokus terlihat**: aturan global `:focus-visible` dengan `--focus-ring:
  #b45309` (kontras kuat di atas latar terang); warna `--primary` dinaikkan
  `#059669` → `#047857` untuk kontras teks putih yang lebih aman (WCAG 1.4.3).
- **Reflow**: `@media (max-width: 900px)` menyembunyikan sidebar (geser
  `translateX(-100%)`), menampilkan pemicu, dan membuat kartu dapat digulir
  mendatar; `@media (max-width: 480px)` menyesuaikan padding & menyembunyikan
  nama profil yang panjang.
- **`prefers-reduced-motion: reduce`** mematikan transisi/animasi.
- **JavaScript**: `toggleSidebar()` & `toggleProfile()` menyinkronkan
  `aria-expanded`; tombol **Escape** menutup menu/sidebar dan mengembalikan
  fokus (WCAG 2.1.2).

### 3.2 `resources/views/layouts/sidebar.blade.php`

- `<nav aria-label="Navigasi utama">`; item aktif diberi `aria-current="page"`.
- Ikon (`<span class="icon" aria-hidden="true">`) dan badge angka disembunyikan
  dari pembaca layar, diganti teks setara `<span class="sr-only">(N menunggu
  tindakan)</span>` (WCAG 1.1.1 & 4.1.3).

### 3.3 `resources/views/layouts/publik.blade.php` (layout publik)

- Menambahkan skip-link, landmark `<main id="konten-utama">`, `.sr-only`,
  indikator `:focus-visible`, dan `prefers-reduced-motion` (sebelumnya layout
  publik tidak memiliki satu pun).
- Judul halaman diberi fallback `@yield('title', 'Formulir Publik')`.

### 3.4 `resources/views/pengajuan/form.blade.php` (formulir pembukaan rekening)

- **Semua field** diberi `for`/`id` berpasangan (label eksplisit, WCAG 1.3.1).
- Kelompok **jenis simpanan** dibungkus `<fieldset>` + `<legend>`; radio yang
  tersembunyi secara visual kini memakai `.sr-only` (bukan `display:none`)
  sehingga **tetap dapat difokus papan tik** (WCAG 2.1.1), dengan cincin fokus
  tampil pada kartu pilihan.
- **Notifikasi** sukses → `role="status" aria-live="polite"`; galat →
  `role="alert"` (WCAG 4.1.3); SVG logo & emoji pilihan diberi `aria-hidden`.
- `outline: none` dihapus dan diganti cincin fokus emas `#f0c040` (WCAG 2.4.7).

---

## 4. Bukti Pengujian

Kontrak aksesibilitas dikunci oleh **`tests/Feature/AksesibilitasLayoutTest.php`**
(18 test / 63 assertions), memetakan satu test ke satu kriteria WCAG:

```
PASS  Tests\Feature\AksesibilitasLayoutTest
  ✓ halaman menyatakan bahasa indonesia                             (3.1.1)
  ✓ layout menyediakan landmark dan tautan lewati konten            (1.3.1, 2.4.1)
  ✓ halaman hanya memiliki satu heading utama                       (2.4.6)
  ✓ meta viewport mendukung reflow                                  (1.4.4, 1.4.10)
  ✓ layout menyediakan media query reflow dan pemicu sidebar        (1.4.10, 4.1.2)
  ✓ tombol profil dapat diakses papan tik dan pembaca layar         (2.1.1, 4.1.2)
  ✓ skrip menutup menu dengan tombol escape                         (2.1.2)
  ✓ formulir memiliki label eksplisit                               (1.3.1, 4.1.2)
  ✓ tabel daftar memiliki caption dan header bercakupan             (1.3.1)
  ✓ ikon dan badge dinamis disembunyikan dari pembaca layar         (1.1.1)
  ✓ layout menghormati prefers reduced motion                       (2.2.2, 2.3.3)
  ✓ layout menyediakan indikator fokus terlihat                     (2.4.7)
  ✓ wilayah notifikasi diumumkan secara sopan                       (4.1.3)
  ✓ halaman publik menyediakan landmark dan tautan lewati konten    (1.3.1, 2.4.1)
  ✓ formulir publik memiliki label eksplisit                        (1.3.1, 4.1.2)
  ✓ formulir publik radio dapat difokus papan tik                   (2.1.1)
  ✓ formulir publik mengumumkan pesan status                        (4.1.3)
  ✓ halaman publik menghormati prefers reduced motion               (2.3.3, 2.4.7)

Tests:    18 passed (63 assertions)
```

### 4.1 Suite penuh (tanpa regresi)

```
Tests:    1035 passed (4921 assertions)
Duration: 147.68s
```

Kenaikan tepat = 28 test / 114 assertions baru di atas baseline Tahap 8
(1007 / 4807) — dari 18 test aksesibilitas (Tahap 9) + 6 test HTTP Simpanan Wajib
(R4-1) + penyesuaian lain. **Nol regresi.**

---

## 5. Risiko Sisa

| Kode | Risiko | Dampak | Mitigasi yang ada |
|---|---|---|---|
| C9.1 | Audit otomatis **tidak menggantikan** pengujian pembaca layar nyata (NVDA/JAWS/VoiceOver) | Perilaku pengumuman dinamis mungkin belum sempurna | struktur ARIA & landmark sudah benar secara markup; **uji manual dengan pembaca layar nyata disarankan sebelum go-live** |
| C9.2 | Kontras warna diuji secara kualitatif (pemilihan `#047857`/`#b45309`), bukan lewat alat ukur kontras otomatis | Potensi temuan kontras minor | warna dipilih ≥ rasio 4.5:1; kandidat integrasi alat kontras (mis. axe/Lighthouse) pada tahap lanjut |
| C9.3 | Tabel lebar pada layar sangat kecil masih memerlukan gulir mendatar (di dalam kartu) | WCAG 1.4.10 memaafkan gulir dua arah untuk konten tabel | dibungkus `.card { overflow-x: auto }` sehingga tidak merusak tata letak halaman |
| C9.4 | Belum ada uji dengan **perangkat nyata** (ponsel/tablet fisik) pada semua rute | kemungkinan masalah spesifik peramban | diverifikasi secara struktural via media query & viewport; kandidat uji perangkat pada tahap lanjut |
| C9.5 | Halaman lain di luar layout bersama & formulir publik belum diaudit satu per satu | sebagian halaman mungkin punya temuan lokal | seluruh halaman autentikasi mewarisi `layouts.app` yang sudah diperbaiki; audit per-halaman adalah kandidat lanjutan |

---

## 6. Pernyataan Penyelesaian

Tahap 9 **selesai dan terverifikasi**. Butir permintaan induk *"penerapan UI
responsif dan aksesibilitas yang mematuhi standar internasional"* kini terjawab
secara terukur:

1. **Tiga belas celah** WCAG 2.1 AA pada layout bersama, layout publik, dan
   formulir publik telah **diperbaiki**.
2. Seluruh layout bersama kini menyediakan **skip-link, landmark `<main>`/`<nav>`,
   dan struktur judul** yang benar.
3. Semua kontrol interaktif **dapat dioperasikan papan tik**, memiliki nama/peran
   yang diumumkan, dan menampilkan **indikator fokus** yang jelas.
4. Antarmuka **responsif** pada breakpoint ≤900px & ≤480px dengan sidebar geser,
   memenuhi WCAG 1.4.10 (Reflow) dan 1.4.4 (Resize Text).
5. Preferensi **`prefers-reduced-motion`** dihormati di seluruh layout.
6. **18 test aksesibilitas** mengunci kontrak ini; suite penuh **1035 passed /
   4921 assertions** tanpa regresi dari baseline Tahap 8 (1007 / 4807).

Dokumen ini melengkapi rantai deliverable Tahap 2–9 dan menjadi catatan progres
terkini proyek.
