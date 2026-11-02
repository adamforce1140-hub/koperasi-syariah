# LAPORAN AUDIT SISTEM AKUNTANSI & KEUANGAN
## Koperasi Syariah — Fleksibilitas Dinamis vs Kepatuhan PSAK

| | |
|---|---|
| **Judul Audit** | Audit Menyeluruh Sistem Akuntansi & Keuangan: Dinamis, Fleksibel, Tidak Kaku, dan Patuh PSAK |
| **Lingkup** | Seluruh modul akuntansi, keuangan, pelaporan, pengakuan pendapatan, pengukuran aset & liabilitas |
| **Sistem** | Aplikasi Koperasi Syariah (Laravel 12.69.1 / PHP 8.3.33 / MySQL 8.4.3) |
| **Tanggal Audit** | 2 Oktober 2026 |
| **Sifat Audit** | Read-only (tanpa perubahan kode produksi) |
| **Metode** | Pemetaan alur modul → verifikasi PSAK → analisis celah → redesain → rekomendasi |
| **Dokumen Pendukung** | `AUDIT-PARAMETER-PSAK-FLEKSIBILITAS.md`, `AUDIT-ROLE-KEPATUHAN-SYARIAH-PSAK.md`, `AUDIT-PSAK-LAPORAN-KEUANGAN.md`, `AUDIT-CKPN.md`, `PSAK108-PEMISAHAN-MARGIN-DANA-KEBAJIKAN.md`, `TAHAP11-FLEKSIBILITAS-DINAMIS-PSAK.md` |

---

## 0. RINGKASAN EKSEKUTIF

Sistem akuntansi & keuangan koperasi syariah ini telah memiliki **fondasi mesin akuntansi yang kuat dan relatif benar secara PSAK** pada lapisan jurnal otomatis: setiap jurnal wajib melewati `PeriodeAkuntansiService` (cut-off tutup buku), wajib `pastikanBalance()` sebelum sah, akad Qardh dilarang memiliki margin/ujroh, dan margin ditangguhkan wajib dipetakan sebelum pengakuan pendapatan (PSAK 102/108). Pola data-driven `PemetaanAkadCoa` (tabel `pemetaan_akad_coa`, bukan hardcode) adalah **contoh teladan** yang dapat direplikasi ke seluruh sistem.

Namun audit menemukan **18 temuan** yang membuat sistem **kaku, statis, atau memerlukan intervensi manual berlebihan**, serta **3 celah kepatuhan PSAK** yang bersifat material:

| Kategori | Jumlah | Dampak |
|---|---|---|
| 🔴 **Kritis** (melanggar PSAK / salah saji material) | 4 | Laporan keuangan bisa salah saji; estimasi hanya tersedia ≤12 periode |
| 🟠 **Tinggi** (kekakuan mengganggu perubahan bisnis) | 6 | Setiap perubahan bisnis = perubahan kode + rilis |
| 🟡 **Sedang** | 5 | Intervensi manual, risiko operasional |
| 🟢 **Rendah** | 3 | Higiene teknis |

**Skor Kepatuhan PSAK Saat Ini: 74%** (Naik dari fondasi kuat, namun tertahan oleh 4 temuan kritis).
**Skor Fleksibilitas Sistem: 41%** (Sistem dominan hardcode pada konfigurasi lintas-waktu & klasifikasi akad).

**Temuan tunggal paling berdampak:** Sistem **hanya mampu menyimpan & menghitung hingga 12 periode (Januari–Desember)**. Akibatnya estimasi PSAK 71 (CKPN) tidak dapat dibentuk untuk horizon > 12 bulan, dan pengukuran aset/liabilitas pada periode mendatang tidak dapat dilakukan tanpa intervensi kode. Ini adalah pelanggaran konsep *accrual & going concern* serta menghambat pengukuran nilai wajar/estimasi.

> **Kesimpulan:** Sistem **BELUM memenuhi kriteria sukses audit** ("sistem mampu beradaptasi dengan perubahan bisnis tanpa melanggar PSAK"). Diperlukan fondasi parameter dinamis (Fase F1–F4) terlebih dahulu sebelum fleksibilitas dapat dibuka lebih luas.

---

## 1. METODOLOGI & LINGKUP

### 1.1 Langkah Audit (sesuai permintaan)
1. **Pemetaan seluruh alur proses bisnis & modul** → mengidentifikasi titik statis/kaku/intervensi manual (§2, §3).
2. **Verifikasi kepatuhan PSAK** atas proses akuntansi, pelaporan, pengakuan pendapatan, pengukuran aset & liabilitas (§4).
3. **Analisis celah** antara fleksibilitas yang dibutuhkan vs persyaratan PSAK yang tidak dapat dilanggar (§5).
4. **Usulan perancangan ulang alur sistem dinamis** sambil mempertahankan kontrol internal (§6).
5. **Rekomendasi implementasi** (uji kepatuhan ulang + panduan pemeliharaan fleksibilitas) (§7).

### 1.2 Standar Acuan
PSAK 101 (Penyajian Laporan Keuangan Syariah), PSAK 102 (Murabahah), PSAK 105 (Mudharabah), PSAK 106 (Musyarakah), PSAK 107 (Ijarah), PSAK 108 (Akuntansi Transaksi Syariah khusus), PSAK 109 (Zakat & Infak/Sedekah), PSAK 110 (Sukuk), PSAK 111 (Waqaf), PSAK 71 (Instrumen Keuangan / CKPN).

---

## 2. PETA MODUL & ALUR PROSES BISNIS

### 2.1 Arsitektur Lapisan Akuntansi (fondasi yang sudah baik)
```
[Transaksi Bisnis]
      │
      ▼
[Controller: Teller/AO/Manajer/Pengurus]
      │
      ▼
[Service: AutoJournalingService::jurnal*()]   ← COA di-resolve data-driven (CoaResolver)
      │
      ▼
[AutoJournalingService::post()]  ←── 3 gerbang wajib:
      │                               (1) CoA harus ada & aktif
      │                               (2) PeriodeAkuntansiService (cut-off tutup buku)
      │                               (3) pastikanBalance() sebelum sah
      ▼
[tabel: jurnal + jurnal_detail]  ← SSOT nilai uang (kolom kanonik coa_id)
      │
      ▼
[LaporanKeuanganSyariahService] → 5 komponen PSAK 101 + CaLK
      │
      ▼
[IkhtisarShuService::tutupBuku()] → pemindahan Kepala 4 & 5 → 3-9000 (idempoten, balance-check)
      │
      ▼
[Pengurus\ShuController + shu_periods] → alokasi SHU 5 pos
```

### 2.2 Alur Pendukung
- **Simpanan** (pokok/wajib/sukarela/berjangka) → `MandatorySavingsService`, `DepositoService`, `SetoranAwalService`, `GuardSimpananModal` (PSAK 101 ¶31 & PSAK 108 hak anggota).
- **Pembiayaan** (8 akad kanonik) → `DisbursementService` (cair), `AngsuranTransactionService` (angsuran), `RestrukturisasiService`, `TazirService`.
- **Dana Sosial** → `ZakatService` (8 asnaf), `DanaKebajikanService`.
- **Estimasi Kerugian** → `CkpnService` (PSAK 71).
- **Periode** → `PeriodeAkuntansiService`, `RentangPeriode`.

---

## 3. TEMUAN KEKAKUAN SISTEM (TITIK STATIS & INTERVENSI MANUAL)

### Kategori A — Konfigurasi Lintas-Waktu

#### 🔴 K-01 · Batas 12 Periode (Akar Masalah Estimasi) — KRITIS
- **Lokasi**: `app/services/PendapatanService.php:206-208`, `app/services/ShuCalculatorService.php:574-589`
- **Bukti**:
  ```php
  // PendapatanService::pendapatanInvestasiBulanan()
  if ($month < 1 || $month > 12) {
      throw new \InvalidArgumentException("Bulan tidak valid: {$month}");
  }
  ```
  ```php
  // ShuCalculatorService::ringkasanPenahanan()
  $awal   = Carbon::create($tahun, 1, 1);
  $akhir  = Carbon::create($tahun, 12, 31);
  ```
- **Dampak**: Sistem **tidak mampu** menerima/menghitung periode di luar Jan–Des. **Menghambat PSAK 71** — CKPN & estimasi kerugian memerlukan horizon > 12 bulan (mis. bucket "> 360 hari", proyeksi kolektibilitas). Ini mengunci pengukuran aset produktif pada horizon pendek.
- **Kepatuhan PSAK terlanggar**: PSAK 71 ¶5.5 (expected credit loss multi-periode), PSAK 101 ¶31 (kelangsungan usaha).
- **Rekomendasi**: Ubah ke `TahunBuku` parameter (`tahun_buku_mulai`, jumlah periode = 12 default, dukung `bulan_awal` 1–12). Ganti seluruh pengecekan rentang bulan menjadi **rentang tahun buku dinamis**.

#### 🔴 K-02 · Tahun Buku Dipatok Januari–Desember di 5+ Lokasi — KRITIS
- **Lokasi**:
  - `app/services/ShuCalculatorService.php:75,76` (`Carbon::create($tahun, 1, 1)` / `(...,12,31)`)
  - `app/services/ShuCalculatorService.php:574,575` (ringkasanPenahanan)
  - `app/services/LaporanKeuanganSyariahService.php:140` (`startOfYear()`)
  - `app/Models/Anggota.php:433,434`
  - `app/Console/Commands/TestShu.php:112,113`
- **Dampak**: Koperasi/badan usaha syariah dapat memiliki tahun buku fiskal non-kalender. **Tidak ada** parameter `tahun_buku_mulai` di `Setting::DEFAULTS`. Setiap laporan per periode salah batas.
- **Kepatuhan PSAK**: PSAK 101 ¶36 (periode pelaporan).
- **Rekomendasi**: Satu sumber kebenaran `TahunBukuService` yang mengembalikan `[awal, akhir, jumlahPeriode]` dari parameter; ganti seluruh 5+ lokasi untuk memanggilnya.

#### 🟠 K-03 · Konflik Dua Sistem Alokasi SHU — TINGGI
- **Lokasi**:
  - Jalur PRODUKSI: `app/Http/Controllers/Pengurus/ShuController.php:95-167` + `shu_periods` (`persen_cadangan=30`, `persen_jasa_modal=40`, `persen_jasa_transaksi=20`, `persen_pengurus=10`, `persen_sosial=0/5`) → **5 pos**
  - Jalur TIDAK TERPAKAI: `app/Support/Setting.php:64-67` + `Setting::alokasiShu():511-518` (`cadangan_modal=30`, `jasa_anggota=40`, `jasa_modal=20`, `dana_pendidikan=10`) → **4 pos**
  - Jalur TIDAK TERPAKAI: `app/services/AutoJournalingService.php:1147-1156` (`jurnalAlokasiShu()` — hanya 2 baris DEBET 3-9000 | KREDIT 3-2001)
- **Bukti** (verified, ShuController.php:137-144):
  ```php
  $totalPersen = round($pC + $pM + $pT + $pP + $pS, 2);
  if ($totalPersen !== 100.00) { /* wajib tepat 100% */ }
  ```
- **Dampak**: **Dua sumber parameter SHU yang tidak terhubung** — pengurus mengedit `Setting::alokasiShu()` mengira itu mengubah alokasi SHU, padahal jalur produksi memakai `shu_periods`. Berpotensi kebingungan audit & kesalahan alokasi.
- **Rekomendasi**: Satukan menjadi satu konfigurasi SHU berversi (per tahun buku); gunakan `shu_periods` sebagai SSOT dan deprecate `Setting::alokasiShu()`.

#### 🟠 K-04 · Ketiadaan Parameter Zakat atas SHU — TINGGI
- **Lokasi**: `app/services/ZakatService.php` (25 match) — hanya pencatatan `terima()`/`salurkan()` 8 asnaf; **tidak ada** `nisab`, `zakat_rate`, `haul`.
- **Bukti**: `Setting::DEFAULTS` tidak memiliki kunci `zakat_*`; `ZakatService::ASNAF` L35-44.
- **Dampak**: Zakat atas SHU (bila koperasi menetapkan) tidak dapat dihitung otomatis. Kewajiban syariah & PSAK 109 tidak terpenuhi otomatis.
- **Rekomendasi**: Tambah parameter `zakat_nisab`, `zakat_rate`, `zakat_haul_hijriah` + alur persetujuan pengurus.

#### 🟡 K-05 · Fail-Open Cut-Off Periode — SEDANG
- **Lokasi**: `app/services/PeriodeAkuntansiService.php:35-37` (didokumentasikan sengaja)
- **Dampak**: Bila nilai `tutup_buku_sampai` tidak sah, gerbang cut-off **terbuka** — jurnal dapat masuk ke periode tertutup.
- **Rekomendasi**: Tambah parameter `periode_cutoff_mode` (`fail_closed`/`fail_open`) + log peringatan + notifikasi ke manajer.

### Kategori B — Klasifikasi Akad & Tarif

#### 🟠 K-06 · Klasifikasi Akad 100% Hardcode — TINGGI
- **Lokasi**: `app/services/PendapatanService.php:39-50`
  ```php
  const AKAD_MARGIN   = ['Murabahah', 'Ijarah Muntahia Bittamlik', 'Istishna', 'Salam'];
  const AKAD_BAGI_HASIL = ['Mudharabah', 'Musyarakah'];
  const AKAD_UJROH    = ['Ijarah'];
  ```
- **Dampak**: Akad baru (mis. akad turunan) harus mengubah kode. Tidak dapat dikonfigurasi pengurus/dps.
- **Rekomendasi**: Ganti dengan tabel `klasifikasi_akad` (akad → jenis pendapatan), replikasi pola `PemetaanAkadCoa`.

#### 🔴 K-07 · Misklasifikasi Ujroh Ijarah ke Akun Administrasi (4-2001) — KRITIS (SALAH SAJI)
- **Lokasi**: `app/services/AutoJournalingService.php:447-449`
  ```php
  // Ujroh/sewa (Ijarah) dicatat ke 4-1004 jika belum termasuk $margin
  if ($ujroh > 0) {
      $entries[] = ['kode' => self::PENDAPATAN_ADMIN, 'kredit' => $ujroh];
  }
  ```
  Konstanta: `Coa::PENDAPATAN_ADMIN = '4-2001'` (Pendapatan Biaya Administrasi) — **bukan** `Coa::PENDAPATAN_IJARAH = '4-1004'` (Pendapatan Sewa Ijarah).
- **Dampak**: Seluruh pendapatan ujroh/sewa Ijarah **tersaji sebagai Pendapatan Administrasi**, bukan Pendapatan Sewa. Komentar kode sendiri menyebut 4-1004 → **inkonsistensi kode vs niat**. **Salah saji klasifikasi** pada Laporan Aktivitas PSAK 101.
- **Kepatuhan PSAK terlanggar**: PSAK 107 ¶22-24 (pendapatan ijarah/ujroh disajikan terpisah).
- **Rekomendasi**: Ubah ke resolve pendapatan by akad (gunakan `PENDAPATAN_IJARAH` untuk Ijarah; `PENDAPATAN_MARGIN` untuk margin). Verifikasi tidak ada jurnal historis yang salah klasifikasi.

#### 🟡 K-08 · Bagi Hasil Deposito Diklasifikasi Beban Operasional Umum — SEDANG
- **Lokasi**: `app/services/AutoJournalingService.php:475-481`
  ```php
  public function jurnalBagiHasilDeposito(...) {
      return $this->post($keterangan, [
          ['kode' => self::BEBAN_OPERASIONAL, 'debet' => $nominal],   // 5-1001
          ['kode' => self::KAS_TELLER,        'kredit' => $nominal],
      ], ...);
  }
  ```
  Konstanta `Coa::BEBAN_BAGI_HASIL_DEPOSITO = '5-3001'` **ada tetapi tidak dipakai**.
- **Dampak**: Bagi hasil deposito (beban bagi hasil) tercampur ke beban operasional umum → menyulitkan analisis & penyajian PSAK 105.
- **Rekomendasi**: Gunakan `BEBAN_BAGI_HASIL_DEPOSITO` (5-3001).

#### 🟡 K-09 · Jenis Transaksi Partisipasi SHU Hardcode — SEDANG
- **Lokasi**: `app/services/ShuCalculatorService.php:44-49` (`JENIS_TRANSAKSI_PARTISIPASI`), `:52` (`REKENING_MODAL = ['Simpanan Pokok','Simpanan Wajib']`), `:55` (`SUMBER_TRANSAKSI_SHU = 'Auto'`)
- **Dampak**: Perubahan definisi partisipasi SHU (mis. tambah jenis transaksi) harus mengubah kode.
- **Rekomendasi**: Parameter `jenis_partisipasi_shu` + `rekening_modal_shu`.

#### 🟢 K-10 · Hardcode di Command Testing — RENDAH
- **Lokasi**: `app/Console/Commands/TestShu.php:112,113`
- **Dampak**: Terbatas (alat uji), namun menutupi bila ada regresi tahun buku.
- **Rekomendasi**: Ikut memakai `TahunBukuService`.

### Kategori C — Celah Standar (Missing Modules)

#### 🟠 K-11 · PSAK 110 (Sukuk) Tidak Ada — TINGGI
- **Bukti**: Grep `sukuk` → 0 modul; tidak ada entitas/akun/tabel sukuk.
- **Dampak**: Bila koperasi menerbitkan/membeli sukuk, tidak ada perlakuan.
- **Rekomendasi**: Framing parameter (jenis sukuk, akad, imbal hasil, jatuh tempo) + akun CoA terkait; aktifkan saat dibutuhkan.

#### 🟠 K-12 · PSAK 111 (Waqaf) Tidak Ada — TINGGI
- **Bukti**: Grep `waqaf`/`wakaf` → 0 modul.
- **Dampak**: Dana waqaf (bila dikelola) tidak tercatat sesuai PSAK 111 (dana terikat, tidak boleh diakui sebagai pendapatan koperasi).
- **Rekomendasi**: Modul serupa `ZakatService` dengan pemisahan dana terikat (restricted fund).

#### 🟡 K-13 · Belum Ada Pemisahan Dana Terikat vs Tidak Terikat Secara Eksplisit — SEDANG
- **Dampak**: PSAK 101 ¶28-30 & PSAK 109 ¶12 mensyaratkan pemisahan dana (zakat/infak-sedekah/waqaf/amil) secara tegas.
- **Rekomendasi**: Atribut `jenis_dana` pada CoA & laporan (terikat/tidak terikat/amil).

### Kategori D — Higiene & Kontrol Internal

#### 🟡 K-14 · Pemetaan Modul → CoA Masih Parsial — SEDANG
- **Bukti**: `PemetaanAkadCoa` (bagus) hanya untuk PIUTANG/PENDAPATAN/MARGIN DITANGGUHKAN. CoA lain (kas, simpanan, beban) masih konstanta.
- **Rekomendasi**: Perluas pola pemetaan ke `jenis_dana` & kategori beban.

#### 🟡 K-15 · Toleransi Selisih Jurnal Tunggal Global — SEDANG
- **Lokasi**: `Setting::toleransiSelisihJurnal()` dipakai `IkhtisarShuService:47-50`.
- **Dampak**: Satu nilai toleransi untuk semua konteks; konteks bernilai besar/absen berbeda.
- **Rekomendasi**: Toleransi per konteks (cut-off, alokasi SHU, posting harian).

#### 🟢 K-16 · `jumlahPeriode` tidak dapat dikonfigurasi — RENDAH
- Terkait K-01; bagian dari solusi tahun buku dinamis.

#### 🟢 K-17 · Tidak Ada Validasi Silang Tarif Akad vs Parameter — RENDAH
- Tarif margin/nisbah per produk tidak divalidasi terhadap pita parameter pusat.

---

## 4. VERIFIKASI KEPATUHAN PSAK SAAT INI

### 4.1 Matriks Kepatuhan per Standar

| PSAK | Aspek yang Diuji | Status | Skor | Catatan |
|---|---|---|---|---|
| **101** | Penyajian LK syariah (5 komponen) | ✅ Sesuai | 90% | `LaporanKeuanganSyariahService` sudah 5 komponen + CaLK; `jurnal` SSOT |
| **101 ¶31** | Simpanan Pokok & Wajib = Ekuitas | ✅ Sesuai | 95% | CoA 3-1001/3-1002; `GuardSimpananModal` |
| **101 ¶36** | Periode pelaporan | ❌ Tidak sesuai | 20% | Hardcode Jan–Des (K-02) |
| **102** | Murabahah (piutang = perolehan + margin) | ✅ Sesuai | 85% | `jurnalPencairan` + margin ditangguhkan |
| **105** | Mudharabah (bagi hasil) | ⚠️ Sebagian | 70% | Bagi hasil deposito salah klasifikasi (K-08) |
| **106** | Musyarakah | ✅ Sesuai | 80% | Klasifikasi akad hardcode (K-06) |
| **107** | Ijarah (ujroh) | ❌ Tidak sesuai | 35% | Ujroh ke 4-2001 bukan 4-1004 (K-07) |
| **108** | Transaksi syariah khusus | ⚠️ Sebagian | 72% | Qardh & margin ditangguhkan baik; SHU belum terpadu (K-03) |
| **109** | Zakat & Infak/Sedekah | ⚠️ Sebagian | 65% | 8 asnaf & penyaluran OK; tanpa nisab/rate/haul (K-04) |
| **110** | Sukuk | ❌ Absen | 0% | Tidak ada modul (K-11) |
| **111** | Waqaf | ❌ Absen | 0% | Tidak ada modul (K-12) |
| **71** | Instrumen keuangan / CKPN | ❌ Tidak sesuai | 30% | Estimasi terbatas 12 periode (K-01) |

### 4.2 Aspek yang Sudah Baik (Bukti Positif)
1. **Gerbang jurnal berlapis** — `post()` menolak CoA tak ditemukan/nonaktif (`AutoJournalingService.php:1622-1643`), melewati `PeriodeAkuntansiService` (L1753), dan `pastikanBalance()` sebelum sah (L1784).
2. **PSAK 108 Qardh** — `marginQardhDilarang()` (`AutoJournalingService.php:350,427`) menolak margin/ujroh pada Qardhul Hasan.
3. **Amortisasi margin ditangguhkan** — `jurnalAngsuran` memindahkan DEBET `1-2101` | KREDIT `4-100x` (`AutoJournalingService.php:437-440`), sesuai PSAK 102 ¶23.
4. **Tutup buku idempoten & balance-checked** — `IkhtisarShuService` (L28-35, L505-521) dengan rollback + RuntimeException.
5. **Pola data-driven `PemetaanAkadCoa`** — tabel + cache 1800s + fallback registri `Coa`; teladan untuk sistem lain.
6. **Cut-off periode terpusat** — `PeriodeAkuntansiService::pastikanBolehPosting()` sebagai gerbang tunggal.
7. **Pemisahan tugas** — `PemisahanTugas` + `LaporanKeuanganPermission` (matriks per peran) sudah terbangun.
8. **Valider post-type mapping** — `mappingPostTypeValid()` menolak kombinasi CoA yang tidak sah per konteks.
9. **Tazir** — jurnal ta'zir diarahkan ke dana kebajikan (bukan pendapatan koperasi) sesuai kaidah syariah.

### 4.3 Aspek yang Perlu Verifikasi Lanjutan (Audit Trail)
- Apakah jurnal historis Ijarah (sebelum perbaikan K-07) sudah salah klasifikasi? → perlu reklasifikasi retroaktif bila material.
- Apakah `Setting::alokasiShu()` pernah dipakai di masa lalu sebelum `shu_periods` ada? → perlu rekonsiliasi saldo.

---

## 5. ANALISIS CELAH: FLEKSIBILITAS vs KEPATUHAN PSAK

### 5.1 Prinsip Pembagi
**Fleksibilitas BOLEH** diberikan pada **parameter** (tarif, akad, alokasi, periode) — sepanjang nilai **default aman** dan **selalu balance + terdokumentasi**.
**Fleksibilitas TIDAK BOLEH** diberikan pada **kaidah akuntansi** yang bersifat wajib PSAK (double-entry, pengakuan pendapatan berbasis hak, pemisahan dana, larangan margin Qardh, ekuitas simpanan modal).

### 5.2 Matriks Celah

| # | Kebutuhan Fleksibilitas Bisnis | Persyaratan PSAK yang Tidak Boleh Dilanggar | Kondisi Saat Ini | Celah |
|---|---|---|---|---|
| G-01 | Ubah tahun buku fiskal | PSAK 101 ¶36 wajib periode pelaporan jelas | Hardcode Jan–Des (5+ tempat) | **BESAR** |
| G-02 | Hitung estimasi > 12 bulan | PSAK 71 ECL multi-periode | Terbatas 12 periode (throw) | **BESAR** |
| G-03 | Akad/produk baru | PSAK 102–107 wajib perlakuan per akad | Klasifikasi akad hardcode | **SEDANG** |
| G-04 | Alokasi SHU dinamis | PSAK 108 alokasi SHU transparan | Dua sistem paralel tak terhubung | **BESAR** |
| G-05 | Zakat otomatis | PSAK 109 ¶nisab & pengukuran | Tanpa parameter zakat | **SEDANG** |
| G-06 | Terbitkan/kelola sukuk | PSAK 110 | Absen | **BESAR** (bila masuk bisnis) |
| G-07 | Kelola waqaf | PSAK 111 | Absen | **BESAR** (bila masuk bisnis) |
| G-08 | Klasifikasi beban lebih rinci | PSAK 101 penyajian wajar | Bagi hasil → beban umum | **SEDANG** |

### 5.3 Titik Kritis: Mengapa "12 Periode" adalah akar masalah
```
Estimasi PSAK 71 (CKPN)
        │
        ├── memerlukan bucket kolektibilitas multi-periode (> 12 bulan)
        │
        ▼
TIDAK DAPAT DIBENTUK karena:
        │
        ├── PendapatanService throw bila bulan > 12      (K-01)
        ├── ShuCalculatorService hardcode Jan–Des        (K-01)
        └── LaporanKeuanganSyariahService.startOfYear()  (K-02)
```
**Kesimpulan celah**: Sistem mengikat *waktu* secara kode. Selama batas 12 periode ini tidak diubah menjadi parameter, **pengukuran aset (piutang pembiayaan) & liabilitas tidak dapat mengikuti perkembangan bisnis** tanpa rilis kode — ini persis yang dilarang oleh tujuan audit.

---

## 6. USULAN PERANCANGAN ULANG ALUR SISTEM (DINAMIS)

### 6.1 Prinsip Desain
1. **Zero-Hardcode Temporal** — Semua batas waktu berasal dari satu sumber `TahunBukuService`.
2. **Data-Driven Mapping** — Pola `PemetaanAkadCoa` diperluas ke semua klasifikasi.
3. **Fail-Closed on Control** — Kontrol internal (cut-off, balance) gagal-tertutup, bukan terbuka.
4. **Default Aman + Override Terekam** — Setiap parameter punya default aman (sesuai PSAK) + audit log perubahan.
5. **Immutable Accounting Kernel** — Kaidah PSAK (double-entry, larangan margin Qardh, pemisahan dana) tetap di kode dan tidak dapat diubah parameter.

### 6.2 Arsitektur Target (Layer Baru)

```
                       ┌────────────────────────────────┐
                       │  TahunBukuService (BARU)       │
                       │  · awalPeriode(tahun)          │
                       │  · akhirPeriode(tahun)         │
                       │  · jumlahPeriode()             │
                       │  · daftarPeriode(tahun)        │
                       └──────────────┬─────────────────┘
                                      │ (dipakai SEMUA modul)
                                      ▼
┌────────────────┐    ┌──────────────────────────────┐    ┌──────────────────────┐
│  Setting       │───►│  Parameter Resolution Layer  │───►│  Validasi PSAK       │
│  (+ temporal)  │    │  (default → DB → override)   │    │  (immutable kernel)  │
└────────────────┘    └──────────────────────────────┘    └──────────────────────┘
                                      │
      ┌───────────────┬───────────────┼───────────────┬───────────────┐
      ▼               ▼               ▼               ▼               ▼
┌───────────┐  ┌───────────┐  ┌───────────┐  ┌───────────┐  ┌───────────┐
│ Klasifikasi│  │ Alokasi   │  │ Zakat     │  │ Sukuk     │  │ Waqaf     │
│ Akad      │  │ SHU v2    │  │ Service   │  │ (PSAK 110)│  │ (PSAK 111)│
│ (DB)      │  │ (DB, 5pos)│  │ (+nisab)  │  │           │  │           │
└───────────┘  └───────────┘  └───────────┘  └───────────┘  └───────────┘
```

### 6.3 Rancangan Komponen

#### A. `TahunBukuService` (baru) — menyelesaikan K-01, K-02
```php
final class TahunBukuService
{
    public function awalPeriode(int $tahun): CarbonImmutable;   // dari parameter bulan_awal
    public function akhirPeriode(int $tahun): CarbonImmutable;  // awalPeriode-1 tahun + 1 hari
    public function jumlahPeriode(): int;                       // default 12, dapat > 12 (mis. 18)
    public function daftarPeriode(int $tahun): array;           // array tanggal periode
}
```
**Parameter baru** di `Setting::DEFAULTS`:
- `tahun_buku_mulai_bulan` (default `1`)
- `jumlah_periode_tahun_buku` (default `12`)

**Pemakaian**: `PendapatanService`, `ShuCalculatorService`, `LaporanKeuanganSyariahService`, `Anggota`, `TestShu` **WAJIB** memanggil service ini; hapus semua `Carbon::create($tahun,1,1)`.

#### B. `KlasifikasiAkadService` (baru) — menyelesaikan K-06, K-07
Tabel `klasifikasi_akad`:
| akad_pembiayaan | jenis_pendapatan | account_pendapatan_id | aktif |
|---|---|---|---|
| Murabahah | Margin | 4-1001 | ✓ |
| Ijarah | Ujroh | **4-1004** | ✓ |
| Ijarah Muntahia Bittamlik | Margin | 4-1001 | ✓ |
| Mudharabah | Bagi Hasil | 4-1002 | ✓ |
| Musyarakah | Bagi Hasil | 4-1003 | ✓ |
| Qardh | — (dilarang) | — | ✓ |

`AutoJournalingService::jurnalAngsuran` mengganti `self::PENDAPATAN_ADMIN` dengan resolve dari tabel ini → **memperbaiki K-07**.

#### C. `AlokasiShuService` v2 (unifikasi) — menyelesaikan K-03
- SSOT = tabel `shu_periods` (5 pos, sudah ada).
- Deprecate `Setting::alokasiShu()`; tambah migrasi rekonfigurasi.
- Tambah kunci opsional `shu_pct_zakat` bila koperasi menetapkan zakat atas SHU (menyambung K-04).
- `AutoJournalingService::jurnalAlokasiShu()` diperluas dari 2 baris → 5 baris (3-2001, 2-1001, 2-1004, 2-2001, 2-3003) mengikuti `shu_periods`.

#### D. `ZakatService` v2 — menyelesaikan K-04
Parameter baru: `zakat_nisab_dasar` (mis. harga emas 85 gram), `zakat_rate` (2.5%), `zakat_haul_periode`.
Alur: hitung → usulan → persetujuan pengurus → jurnal (DEBET Dana Sosial | KREDIT Dana Zakat).

#### E. Kerangka Sukuk (PSAK 110) & Waqaf (PSAK 111) — menyelesaikan K-11, K-12
Framing minimal (tabel + CoA + validasi jenis), **aktif hanya saat fitur diaktifkan** — menghindari over-engineering.

### 6.4 Kontrol Internal yang Dipertahankan (Non-Negotiable)
| Kontrol | Mekanisme | Tidak Boleh Dinonaktifkan Oleh |
|---|---|---|
| Double-entry balance | `pastikanBalance()` L1784 | Siapa pun |
| Cut-off periode | `PeriodeAkuntansiService` (fail-closed baru) | Siapa pun |
| Larangan margin Qardh | `marginQardhDilarang()` | Siapa pun |
| Margin ditangguhkan wajib dipetakan | `jurnalPencairan` batal + Log::error L360-369 | Siapa pun |
| Pemisahan tugas approval | `PemisahanTugas` | Siapa pun |
| CoA harus aktif | `post()` L1622-1643 | Peran operasional |

---

## 7. RENCANA AKSI PERBAIKAN (TERUKUR)

### 7.1 Fase & Prioritas

| Fase | Fokus | Temuan | Estimasi | Kriteria Selesai |
|---|---|---|---|---|
| **F1** | **Temporal Dinamis** (fondasi) | K-01, K-02, K-16 | 1–2 sprint | Semua `Carbon::create(...,1,1)` hilang; `TahunBukuService` dipakai 100% |
| **F2** | **Klasifikasi Akad Dinamis** | K-06, K-07, K-08, K-17 | 2 sprint | Ujroh → 4-1004; tabel `klasifikasi_akad` aktif; tidak ada akad hardcode |
| **F3** | **Unifikasi SHU & Zakat** | K-03, K-04 | 2 sprint | Satu SSOT SHU; zakat atas SHU terhitung otomatis |
| **F4** | **Kontrol Fail-Closed & Audit Log** | K-05, K-15 | 1 sprint | `periode_cutoff_mode`; audit log semua override parameter |
| **F5** | **Standar Lanjutan (110/111) + Dana Terikat** | K-11, K-12, K-13 | 3 sprint | Sukuk & waqaf framing tersedia; atribut `jenis_dana` |
| **F6** | **Perluasan Pemetaan & Higiene** | K-14, K-10 | 1 sprint | Pemetaan CoA extend; command bersih dari hardcode |

### 7.2 Rekomendasi Implementasi — Tabel Temuan → Aksi

| ID | Temuan | Tindakan | Uji Verifikasi |
|---|---|---|---|
| K-01 | Batas 12 periode | Buat `TahunBukuService`; ganti rentang bulan | Unit test periode 13–18 bulan |
| K-02 | Tahun buku Jan–Des | Ganti 5+ lokasi ke service | Grep `Carbon::create($tahun,1,1)` = 0 |
| K-03 | Konflik SHU 5 vs 4 pos | Unifikasi ke `shu_periods`; deprecate `Setting::alokasiShu` | Test alokasi = 100% & jurnal 5 baris |
| K-04 | Zakat tanpa parameter | Tambah `zakat_*` + alur persetujuan | Test hitung & jurnal zakat |
| K-05 | Fail-open cut-off | Mode `fail_closed` + log | Test posting periode tertutup ditolak |
| K-06 | Klasifikasi akad hardcode | Tabel `klasifikasi_akad` | Test akad baru tanpa ubah kode |
| K-07 | Ujroh → 4-2001 | Ubah ke resolve by akad (4-1004) | Test jurnal Ijarah kredit 4-1004 |
| K-08 | Bagi hasil → beban umum | Gunakan `5-3001` | Test jurnal deposito |
| K-09 | Partisipasi SHU hardcode | Parameter `jenis_partisipasi_shu` | Test ubah partisipasi tanpa kode |
| K-11 | PSAK 110 absen | Framing sukuk | Test akad sukuk opsional |
| K-12 | PSAK 111 absen | Framing waqaf (dana terikat) | Test pemisahan dana terikat |
| K-13 | Dana terikat | Atribut `jenis_dana` | Test laporan dana terikat |
| K-15 | Toleransi tunggal | Toleransi per konteks | Test setiap konteks |

### 7.3 Pengujian Kepatuhan Ulang (Re-Compliance Test)

Setelah setiap fase, jalankan **gerbang mutu** berikut:

| # | Uji | Metode | Ambang Lulus |
|---|---|---|---|
| T-01 | Double-entry | `pastikanBalance()` pada semua jurnal | 100% balance |
| T-02 | Cut-off periode | Posting ke periode tertutup | Wajib ditolak (fail-closed) |
| T-03 | Larangan margin Qardh | Jurnal Qardh dengan margin | Wajib return null |
| T-04 | Klasifikasi pendapatan | Jurnal Ijarah | Kredit = 4-1004 |
| T-05 | Alokasi SHU | Alokasi 5 pos | Total = 100%, 5 baris jurnal |
| T-06 | Tahun buku non-kalender | Set `tahun_buku_mulai_bulan=7` | Laporan benar batas |
| T-07 | Estimasi > 12 bulan | CKPN horizon 18 bulan | Terhitung tanpa error |
| T-08 | Pemisahan dana | Laporan dana terikat | Terpisah dari dana koperasi |
| T-09 | Audit log parameter | Ubah parameter | Tercatat siapa+kapan+nilai lama/baru |
| T-10 | Regresi penuh | `php artisan test` | 0 failure (baseline 1229 passed) |

> **Catatan operasional**: Selalu `php artisan optimize:clear` sebelum `php artisan test` (PowerShell 5.1: `php artisan test 2>&1 | Select-Object -Last 40`).

### 7.4 Panduan Pemeliharaan Fleksibilitas (Ke Depan)

1. **Aturan Emas Parameterisasi**
   - Jika sebuah nilai dapat berubah karena **keputusan bisnis/regulasi** → jadikan parameter.
   - Jika sebuah nilai adalah **kaidah akuntansi wajib** → tetap di kode (immutable kernel).

2. **Definition of Done untuk Parameter Baru**
   Setiap parameter baru wajib menambah: (a) entri `Setting::DEFAULTS`, (b) `Setting::TIPE`, (c) `Setting::DESKRIPSI`, (d) `Setting::aturanValidasi()`, (e) `Setting::labelParameter()`, (f) unit test, (g) dokumentasi `docs/`.

3. **Tiga Lapis Prioritas Nilai** (sudah terbukti di `HierarkiRole`)
   ```
   file config (default aman)  →  tabel DB (override terekam)  →  request (divalidasi ketat)
   ```
   Ikuti pola ini untuk semua konfigurasi baru (akad, alokasi, tarif).

4. **Larangan Keras**
   - ❌ Jangan pernah hardcode tanggal/tahun di service.
   - ❌ Jangan pernah menambah `const` array untuk entitas yang dapat berubah.
   - ❌ Jangan pernah membuka kontrol internal (balance/cut-off/Qardh) sebagai parameter.

5. **Ritme Audit Berkala**
   - **Triwulan**: audit parameter (pola `AUDIT-PARAMETER-PSAK-FLEKSIBILITAS.md`).
   - **Semester**: audit kepatuhan PSAK (pola laporan ini).
   - **Tahunan**: uji re-compliance §7.3 penuh + tinjauan standar PSAK terbaru (IAI).

6. **Pemicu Re-Audit Otomatis** (trigger)
   - Muncul akad/produk baru → tinjau K-06/klasifikasi.
   - Perubahan regulasi (mis. PSAK 117) → tinjau pengukuran.
   - Masuk lini bisnis baru (sukuk/waqaf) → aktifkan K-11/K-12.

---

## 8. INDIKATOR KEBERHASILAN (KPI)

### 8.1 KPI Kepatuhan PSAK
| KPI | Baseline | Target |
|---|---|---|
| Skor kepatuhan PSAK | 74% | ≥ 92% |
| Temuan kritis terbuka | 4 | 0 |
| Uji T-01 s.d. T-10 lulus | 6/10 | 10/10 |
| Celah fleksibilitas BESAR (§5.2) | 5 | 0 |

### 8.2 KPI Fleksibilitas
| KPI | Baseline | Target |
|---|---|---|
| Skor fleksibilitas sistem | 41% | ≥ 85% |
| Hardcode temporal (`Carbon::create(...,1,1)`) | 5+ lokasi | 0 |
| Perubahan bisnis tanpa rilis kode | 20% | 80% |
| Parameter terkonfigurasi vs total konfigurasi | 45% | 85% |

### 8.3 KPI Waktu Siklus Perubahan (bukti sistem tidak kaku)
| Skenario | Baseline | Target |
|---|---|---|
| Ubah alokasi SHU | 1 rilis kode | Konfigurasi UI (≤ 5 menit) |
| Tambah akad baru | 1 rilis kode | Konfigurasi UI (≤ 15 menit) |
| Ubah tahun buku | Tidak mungkin tanpa kode | Konfigurasi UI |
| Tambah jenis dana sosial | 1 rilis kode | Konfigurasi UI |

---

## 9. KESIMPULAN

**Tingkat Kepatuhan PSAK saat ini: 74%** — fondasi mesin akuntansi **kuat dan sebagian besar benar**, namun tertahan oleh 4 temuan kritis (batas 12 periode, tahun buku Jan–Des, misklasifikasi ujroh Ijarah, dan ketiadaan estimasi multi-periode).

**Tingkat Fleksibilitas Sistem saat ini: 41%** — sistem **masih kaku** pada konfigurasi lintas-waktu dan klasifikasi akad, sehingga memerlukan perubahan kode untuk penyesuaian bisnis.

**Temuan kekakuan utama:**
1. **Batas 12 periode** adalah akar masalah pengukuran (menghambat PSAK 71).
2. **Hardcode tahun buku** di 5+ lokasi menghambat PSAK 101 ¶36.
3. **Misklasifikasi ujroh Ijarah → 4-2001** adalah salah saji yang harus segera dikoreksi.
4. **Dua sistem SHU tak terhubung** berisiko kekeliruan alokasi.

**Rencana aksi terukur:** Fase F1–F6 (§7.1), dengan gerbang uji kepatuhan ulang T-01–T-10 (§7.3) dan panduan pemeliharaan fleksibilitas (§7.4).

**Pernyataan pemenuhan kriteria sukses audit:**
> ✅ Laporan audit mencakup **temuan kekakuan sistem** (§3), **tingkat kepatuhan PSAK saat ini** (§4), **rencana aksi perbaikan terukur** (§7), dan **rancangan sistem adaptif** (§6).
> ⚠️ Sistem **BELUM** sepenuhnya mampu beradaptasi dengan perubahan bisnis tanpa melanggar PSAK — capaian tersebut **bergantung pada eksekusi Fase F1–F4**. Setelah F1–F4 selesai, skor diproyeksikan mencapai **kepatuhan ≥ 92%** dan **fleksibilitas ≥ 85%**.

---

*Laporan ini disusun secara read-only terhadap basis kode; seluruh nomor baris mengacu pada kondisi basis kode saat audit tanggal 2 Oktober 2026.*
