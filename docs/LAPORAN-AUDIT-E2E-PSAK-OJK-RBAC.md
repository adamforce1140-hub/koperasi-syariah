# Laporan Audit End-to-End — Kepatuhan PSAK, Regulasi OJK, RBAC, dan Integritas Pencatatan Uang

**Sistem:** Koperasi Syariah (Laravel 12.69.1 / PHP 8.3.33 / MySQL 8.4.3)
**Tanggal Audit:** 5 Oktober 2026
**Ruang Lingkup:** Seluruh peran (role) pengguna, seluruh modul fungsional, data demo, alur transaksi syariah, pelaporan keuangan, RBAC/pemisahan tugas, dan integritas pencatatan uang.
**Metode:** Audit statis (pembacaan kode & konfigurasi) + audit dinamis (query basis data langsung pada DB `koperasi_syariah`) + eksekusi test suite penuh.
**Sifat:** Read-only. Tidak ada perubahan kode yang dilakukan dalam audit ini.

---

## A. RINGKASAN EKSEKUTIF

Audit berjalan atas data demo nyata dan seluruh test suite. Hasil:

| Aspek | Status | Keterangan |
|---|---|---|
| Integritas jurnal (balance) | **LULUS** | 62 jurnal, semua Posted, D=K=120.897.500, 0 tidak balance |
| Integritas jurnal (no-gap / duplikat) | **LULUS** | 62 no_jurnal unik, 0 duplikat, 0 jurnal tanpa detail |
| Neraca (Posisi Keuangan PSAK 101) | **LULUS** | totalAset = totalPasiva = 106.289.167, `selisihNeraca = 0,00`, `seimbang = YA` |
| Bagi hasil berbasis nisbah (bukan bunga) | **LULUS** | Nisbah disnapshot saat akad, tidak ada bunga tetap/nominal dijamin |
| Kontra-akun (PSAK 101) | **LULUS** | Akun kontra (1-2101) ber-`tipe_saldo_normal=Kredit` & terhitung benar di laporan |
| RBAC — hierarki & matriks izin | **LULUS** | Sumber tunggal `config/role_hierarki.php` |
| RBAC — pemisahan tugas (maker≠checker) | **GAGAL (Kritis)** | Celah pada modul pengajuan pembiayaan inti |
| Automated test (E2E) | **LULUS** | 1336 passed (5803 assertions), 0 failure |

**Kesimpulan:** Fondasi akuntansi dan integritas moneter sistem **solid dan akurat**. Namun terdapat **1 temuan KRITIS** (pemisahan tugas pada modul inti), **2 temuan SEDANG** (zakat tanpa kadar 2,5%/nishab; SHU bersih input manual), dan beberapa temuan RENDAH. Tidak ada indikasi data hilang, jurnal tidak balance, atau saldo fiktif.

---

## B. METODOLOGI & BUKTI VERIFIKASI INTEGRITAS UANG (e5)

### B.1 Balance jurnal
```
JURNAL_HEADER n=62 D=120897500.00 K=120897500.00 UNBALANCED=0
DETAIL_VS_HEADER mismatched=0 of 62
JURNAL_TANPA_DETAIL=0
DETAIL_TOTAL D=120897500.00 K=120897500.00 DIFF=0
```
**Interpretasi:** Setiap jurnal header seimbang; total detail per jurnal identik dengan header; tidak ada jurnal yatim. Signed sum seluruh detail `(Debet − Kredit) = 0` eksak.

### B.2 Penomoran jurnal
```
NO_JURNAL count=62 unique=62
  first=JRN-OPENING-001  last=JRN-20261002-0054
  DUPLICATES=0
  status Posted: 62 ; is_balance=0 count=0
```
**Interpretasi:** Tidak ada duplikasi maupun nomor terlewat (kecuali prefix berbeda antar-jenis, yang memang by design).

### B.3 Neraca (lapisan laporan, bukan hitung manual)
Dipanggil langsung `LaporanKeuanganSyariahService::posisiKeuangan()`:
```
totalAset      = 106.289.167,00
totalKewajiban =  81.050.000,00
totalDST       =  15.000.000,00   (Dana Syirkah Temporer, disajikan terpisah)
totalEkuitas   =  11.800.000,00
labaBersih     =  -1.560.833,00
totalPasiva    = 106.289.167,00
selisihNeraca  = 0,00
seimbang       = YA
statusLaba     = rugi
```
**Interpretasi:** Persamaan akuntansi terpenuhi paripurna. Dana Syirkah Temporer **dipisahkan di lapisan data** dari Kewajiban (benar secara PSAK 105 — DST bukan utang). Nilai pada laporan **berbeda** dari penjumlahan naif karena akun kontra (1-2101 Margin Ditangguhkan) diperlakukan sesuai `tipe_saldo_normal`-nya. Verifikasi bahwa laporan menghormati kontra-akun dengan benar.

### B.4 Catatan audit
- Tabel `coas` **tidak** menyimpan kolom saldo berjalan; saldo dihitung *on-the-fly* dari `jurnal_detail` (`Coa::saldoBerjalan()`), sehingga bebas dari risiko saldo *stale*.
- Terdapat 133 baris `jurnal_detail` dengan `akun_id` NULL (kolom warisan/legacy). **Tidak berdampak** pada pelaporan karena pelaporan memakai `coa_id`, bukan `akun_id`.

---

## C. TEMUAN TERSTRUKTUR

Format tiap temuan: **ID — Judul** · Kategori · Risiko · Skenario Pengujian · Bukti · Akar Masalah · Dampak · Rekomendasi.

---

### T-01 — Pemisahan Tugas (Maker≠Checker) Tidak Diterapkan pada Modul Pengajuan Pembiayaan Inti

- **Kategori:** RBAC / Pengendalian Internal (COSO SoD)
- **Risiko:** 🔴 **KRITIS**
- **Regulasi terkait:** OJK — prinsip tata kelola & pengendalian internal (SoD); praktik *four-eyes principle* pada keputusan pembiayaan.

**Skenario pengujian:** Pengguna dengan role `ao` membuat pengajuan pembiayaan. Diharapkan: pemutus (manajer/pengurus) tidak boleh orang yang sama dengan pengaju. Diuji dengan membaca seluruh jalur approval di `PengajuanPembiayaanIntegController` dan menyilangkan dengan pemakaian helper `PemisahanTugas` se-aplikasi.

**Bukti:**
- `grep "PemisahanTugas::" app/` → **46 pemakaian** (TellerPolicy, ReversalService, RestrukturisasiService, DepositoPenaltyReliefController, TransactionLimitService, PengeluaranBebanController, AmlComplianceController, dsb.) — **tetapi NOL pemakaian di `PengajuanPembiayaanIntegController.php`**.
- `PengajuanPembiayaanIntegController`:
  - `review()` (baris 336-354): `findOrFail($id)` → langsung `direview_oleh = Auth::id()` tanpa cek pengaju.
  - `tolakManajer()` (baris 360-377): idem tanpa cek.
  - `approvePengurus()` (baris 383-567): `findOrFail($id)` → menulis `'disetujui_oleh' => Auth::id()` (baris 473) tanpa membandingkan ke pengaju.
  - `tolakPengurus()` (baris 573-589): idem.
- Tabel `pengajuan_pembiayaan` **memiliki** kolom `ao_id`, `created_by`, `diproses_oleh`, `direview_oleh`, `disetujui_oleh` — jadi data pembanding tersedia, hanya tidak dipakai.
- `routes/web.php:695-718` hanya melindungi dengan `role:ao,manajer,pengurus` / `role:manajer,pengurus` / `role:pengurus` — **tidak ada object-level authorization** (SoD adalah *object-level*, tidak bisa ditangani oleh middleware `role:`).

**Akar masalah:** `PengajuanPembiayaanIntegController` adalah jalur approve "integ" yang dibangun terpisah dari jalur yang memakai `PemisahanTugas`. Helper `App\Support\PemisahanTugas` tersedia (bahkan docblock-nya mencontohkan tepat kasus `$pengajuan->created_by`), namun tidak pernah dipanggil di controller ini. Konsistensi kontrol hilang antar-modul.

**Dampak:** Satu orang (mis. role `pengurus`) dapat mengajukan, mereview, dan menyetujui pencairan pembiayaan yang sama — mencairkan dana tanpa pengawasan kedua pihak. Ini pelanggaran langsung prinsip syariah (gharar pengelolaan) dan pengendalian internal OJK.

**Rekomendasi (terukur):**
1. Tambahkan di awal `review()`, `tolakManajer()`, `approvePengurus()`, `tolakPengurus()`:
   `if (PemisahanTugas::dilanggarSalahSatu([$pembiayaan->ao_id, $pembiayaan->created_by], Auth::id())) return back()->with('error', PemisahanTugas::PESAN);`
2. Tambahkan test regresi: maker (AO) sebagai approver → 403/redirect error; pastikan suite tetap 1336+.
3. Jadikan pemakaian `PemisahanTugas` sebagai *checklist wajib* pada setiap controller yang memiliki method `approve*/review*/setujui*/tolak*` (bisa dipantau lewat static test).

---

### T-02 — Zakat Tidak Menghitung Kadar 2,5% / Nisbah Nishab

- **Kategori:** Syariah / PSAK 109
- **Risiko:** 🟠 **SEDANG**
- **Regulasi terkait:** PSAK 109 (Akuntansi Zakat, Infak, Sedekah) & ketentuan syariah kadar zakat maal 2,5% dengan syarat nishab.

**Skenario pengujian:** Menelusuri apakah sistem menghitung kewajiban zakat dari harta (2,5% × harta bila ≥ nishab) atau mengandalkan input manual.

**Bukti:**
- `grep "nishab|nisab|2\.5|0\.025|kadar_zakat" app/` → **No matches found**.
- `ZakatController::store()` (baris 64-71): validasi `'jumlah' => ['required','numeric','min:0.01']` — nominal **diinput manual**, bukan hasil kalkulasi.
- `ZakatService::terima()` (baris 85-146): memakai `$jumlah` apa adanya; tidak ada perhitungan kadar/nishab.

**Akar masalah:** Modul zakat dirancang sebagai **buku besar kas** (pencatatan penerimaan & penyaluran) — bukan *kalkulator zakat*. Tidak ada master nishab dan tidak ada mesin hitung kadar.

**Dampak:** Sistem tidak dapat memverifikasi kebenaran nominal zakat; risiko muzakki membayar kurang/lebih tanpa peringatan. Kepatuhan PSAK 109 secara *penyajian* baik (dana terpisah, sumber & penggunaan terlaporkan, saldo tidak boleh negatif), namun secara *kewajiban syariah* tidak tervalidasi.

**Rekomendasi (terukur):**
1. Tambahkan modul "Kalkulator Zakat Maal": input harta → cek `harta >= nishab (85 gram emas × harga emas harian)` → zakat = 2,5% × harta.
2. Tambahkan kolom/master `nishab_emas_gram` (default 85) & `harga_emas_harian` di `Setting`; kadar zakat di `Setting` (default 2,5%) agar dapat dikonfigurasi.
3. Bila input manual tetap dipertahankan, beri **peringatan lunak** bila nominal ≠ 2,5% × harta yang dideklarasikan.

---

### T-03 — Total SHU Bersih Diinput Manual, Tidak Divalidasi terhadap Laba Riil Jurnal

- **Kategori:** PSAK 101 / Praktik Distribusi SHU
- **Risiko:** 🟠 **SEDANG**

**Skenario pengujian:** Menelusuri sumber nilai `total_shu_bersih` pada distribusi SHU dan kontrol validasinya.

**Bukti:**
- `ShuController` baris 118/150/168: `$total = (float) $request->total_shu_bersih;` — berasal dari input HTTP.
- Baris 140: `'total_shu_bersih' => 'required|numeric|min:1'` — hanya validasi format.
- Baris 515-525: hanya memeriksa `$saldoIkhtisar < (float) $shu->total_shu_bersih` (mencegah over-distribution) — **tidak** membandingkan dengan laba riil (Pendapatan − Beban) dari jurnal.

**Akar masalah:** Distribusi SHU mengandalkan angka yang diinput pengurus; kontrol yang ada hanya "tidak melebihi saldo Ikhtisar SHU 3-9000", bukan "sama dengan laba periode hasil Tutup Buku".

**Dampak:** Distribusi SHU dapat berbeda dari laba yang benar-benar dihasilkan (walau tidak melebihi saldo), sehingga menyalahi alokasi laba oleh RAT.

**Rekomendasi (terukur):**
1. Isi otomatis field `total_shu_bersih` dari `Coa::labaBersihBerjalan($periodeTutupBuku)` (metode sudah tersedia) dan jadikan **read-only**.
2. Bila tetap dapat diedit, tambahkan validasi: `abs(input − labaRiil) <= Setting::toleransiSelisihJurnal()` → selain itu blokir.
3. Catat pada audit trail alasan bila ada override manual.

---

### T-04 — Role Phantom `'keuangan'` pada Otorisasi Pengeluaran Beban

- **Kategori:** RBAC / Bug Otentikasi
- **Risiko:** 🟡 **RENDAH**

**Skenario pengujian:** Memeriksa daftar role yang diizinkan mengelola beban operasional vs daftar role yang terdaftar di sistem.

**Bukti:**
- `PengeluaranBebanController.php:33` → `if (!in_array($role, ['teller', 'keuangan', 'manajer'], true))`.
- `config/role_hierarki.php` mendaftarkan: `dps, pengurus, manajer, ao, teller, admin, anggota, nasabah`. **Tidak ada role `keuangan`.**
- Akibatnya `'keuangan'` adalah *dead entry* (tidak pernah cocok) — tidak menyebabkan eskalasi (bukan bypass), tetapi menyesatkan pembaca dan berpotensi menimbulkan harapan akses yang tidak ada.

**Akar masalah:** Sisa refaktor/perubahan taksonomi role yang tidak dibersihkan.

**Dampak:** Kosmetik/kejelasan kode. Tidak ada celah keamanan aktif. Namun menyulitkan perawatan dan dapat menyesatkan audit berikutnya.

**Rekomendasi (terukur):**
1. Hapus literal `'keuangan'` dari array; bila memang dibutuhkan role keuangan, daftarkan resmi di `config/role_hierarki.php` beserta level/kategori/penyetuju/operasional.

---

### T-05 — Pemeriksaan Izin Ekspor Absen di Controller (Defense-in-Depth)

- **Kategori:** RBAC / Pertahanan Berlapis
- **Risiko:** 🟡 **RENDAH** (terlindungi oleh middleware)

**Skenario pengujian:** Memeriksa apakah cabang ekspor pada `LaporanKeuanganSyariahController` memeriksa `bolehEkspor`.

**Bukti:**
- `LaporanKeuanganSyariahController.php:50` menghitung `'bolehEkspor' => LaporanKeuanganPermission::boleh(...EKSPOR)`.
- Baris 54-59: `if ($request->input('export') === 'excel')` **tidak** memeriksa `bolehEkspor`.
- **Namun** route memakai middleware `laporan.akses:laporan_psak101`; `LaporanKeuanganAccess::tentukanAksi()` baris 74 mengubah `?export=`/`?unduh=` menjadi aksi **EKSPOR** sebelum controller → ekspor **sudah terlindungi**. Ini bukan exploit, hanya hilangnya pertahanan lapis kedua.

**Akar masalah:** Controller mengasumsikan middleware selalu terpasang.

**Rekomendasi (terukur):** Tambahkan guard eksplisit di controller: `abort_unless($bolehEkspor, 403)` sebelum menghasilkan berkas, agar aman meski route diubah kelak.

---

### T-06 — Data Demo: Master Produk Pembiayaan Kosong & `produk_id` NULL

- **Kategori:** Data Demo / Konfigurasi
- **Risiko:** 🟡 **RENDAH** (data demo, bukan kode produksi)

**Skenario pengujian:** Memverifikasi stok data demo terhadap DB live — apakah pemetaan akad→CoA per-produk benar-benar terpakai di data demo.

**Bukti:**
- Tabel `master_produk_pembiayaan` = **0 baris**.
- `pengajuan_pembiayaan.produk_id` = **NULL** untuk seluruh 5 pengajuan demo.
- Akibatnya, `CoaResolver`/pemetaan per-produk **jatuh ke padanan universal `'*'`** di seluruh data demo.

**Akar masalah:** `DemoDataSeeder` membuat pengajuan tanpa mengisi `produk_id` (atau master produk belum di-seed lebih dulu).

**Dampak:** Data demo tidak memperagakan kemampuan unggulan sistem (pemetaan akad→CoA spesifik per produk). Pengujian fitur tersebut secara E2E pada data demo tidak tervalidasi.

**Rekomendasi (terukur):** Lengkapi `DemoDataSeeder` agar meng-seed minimal 4 produk (Murabahah/Mudharabah/Musyarakah/Ijarah) dengan `akad_syariah` & `pemetaanAkadCoa()`, lalu set `produk_id` pada tiap `pengajuan_pembiayaan` demo. Pertahankan sifat idempoten seeder.

---

### T-07 — Amortisasi Margin Murabahah Bersifat Flat (Bukan Anuitas/Proporsional)

- **Kategori:** PSAK 102 / Penyajian
- **Risiko:** 🟡 **RENDAH**

**Bukti:** Pada `approvePengurus()` (baris 405-409): `$marginPerBulan = round($totalMargin / $tenor, 2)` — margin dibagi rata per bulan. Pada data demo, `1-2101 Margin Murabahah Ditangguhkan = 1.100.000` dan `3-9000` tidak menunjukkan pengakuan margin proporsional berbasis waktu.

**Akar masalah:** Sistem memakai amortisasi *straight-line* untuk margin ditangguhkan.

**Dampak:** Secara PSAK 102, *straight-line* masih dapat diterima untuk margin yang pengakuannya merata; namun tidak mencerminkan pola anuitas bila kebijakan koperasi mensyaratkan demikian. Dampak minor terhadap ketepatan pengakuan pendapatan antar-periode.

**Rekomendasi (terukur):** Beri opsi kebijakan metode amortisasi (flat vs anuitas) di `Setting`; dokumentasikan pilihan default beserta alasan syariah/akuntansinya.

---

## D. ASPEK YANG TERVALIDASI BAIK (Tidak Ada Temuan)

1. **Bagi hasil berbasis nisbah (bukan bunga):** `DepositoService` — nisbah anggota + koperasi diambil dari produk, disnapshot saat akad (`snapshot nisbah`), dihitung `grossRevenue × nisbah/100`. Jurnal `jurnalBagiHasilDepositoMudharabah` berlabel eksplisit "Bagi hasil BUKAN bunga" — sesuai PSAK 105/PSAK 101. Tidak ada nominal tetap dijamin.
2. **Kontra-akun:** `Coa::saldoBerjalan()` menghormati `tipe_saldo_normal`; akun `1-2101 Margin Murabahah Ditangguhkan` ber-posisi Kredit dan terhitung benar (terbukti neraca `selisihNeraca = 0,00`).
3. **Dana Syirkah Temporer:** dipisahkan dari Kewajiban di lapisan data (`klasifikasi_neraca = 'Dana Syirkah Temporer'`), sesuai PSAK 105.
4. **Simpanan Wajib sebagai modal (ekuitas):** `jurnalSetorSimpananWajib` memakai akun 3-1002 (bukan kewajiban legacy) — konsisten PSAK 101.
5. **Penyaluran zakat tidak boleh melebihi saldo:** `ZakatService::salurkan()` menolak over-distribution; ada test "penyaluran melebihi saldo dana ditolak".
6. **RBAC sumber tunggal:** `config/role_hierarki.php` (level/kategori/penyetuju/operasional/batas/frekuensi/akses_data) → dipakai konsisten via `HierarkiRole`. Batas transaksi & frekuensi harian terdefinisi (teller 50jt/5jt, ao 100jt/20jt, dst).
7. **Audit trail & pemisahan tugas** diterapkan luas (46 pemakaian `PemisahanTugas`) di luar T-01.
8. **Anti double-pencairan:** `is_disbursed` dicek sebelum `approvePengurus`; pencairan dibungkus `DB::transaction` + `lockForUpdate`.

---

## E. MATRIKS RISIKO & PRIORITAS PERBAIKAN

| ID | Temuan | Risiko | Prioritas | Estimasi Upaya |
|---|---|---|---|---|
| T-01 | SoD absen di `PengajuanPembiayaanIntegController` | 🔴 Kritis | P0 | Kecil (tambah 1 guard × 4 method + test) |
| T-02 | Zakat tanpa kadar 2,5%/nishab | 🟠 Sedang | P1 | Sedang (modul kalkulator + Setting) |
| T-03 | `total_shu_bersih` manual | 🟠 Sedang | P1 | Kecil (auto-isi dari `labaBersihBerjalan`) |
| T-04 | Role phantom `'keuangan'` | 🟡 Rendah | P2 | Sangat kecil |
| T-05 | `bolehEkspor` defense-in-depth | 🟡 Rendah | P2 | Sangat kecil |
| T-06 | Data demo produk kosong | 🟡 Rendah | P2 | Kecil (lengkapi seeder) |
| T-07 | Amortisasi margin flat | 🟡 Rendah | P3 | Sedang (opsi metode) |

---

## F. HASIL PENGUJIAN DINAMIS (e6)

```
Tests:    1336 passed (5803 assertions)
Duration: 273.51s
Failed:   0
```
Seluruh test per peran (teller/ao/manajer/pengurus/dps) & per modul (pembiayaan, simpanan, deposito, zakat, laporan PSAK, approval) lulus. Termasuk test khusus:
- `setiap transaksi terhubung ke jurnal posted berimbang`
- `pengurus dan manajer dapat membuka buku besar zakat` / `dps hanya dapat membaca` / `teller dan ao tidak dapat mengakses`
- `form penyaluran ditolak ketika saldo kurang`
- `halaman laporan sumber dan penggunaan dana dapat dirender`

---

## G. KESIMPULAN & LANGKAH LANJUT

**Integritas pencatatan uang: sempurna.** Tidak ada jurnal tidak balance, tidak ada data hilang, tidak ada duplikasi, neraca seimbang eksak, dan saldo dihitung ulang dari sumber transaksi (bebas *stale*).

**Risiko tertinggi ada pada RBAC (T-01), bukan pada akuntansi.** Sistem secara akuntansi-patuh PSAK, namun pengendalian *prosedural* (maker≠checker) bocor tepat di modul paling material — pencairan pembiayaan.

**Langkah lanjut yang disarankan (urutan):**
1. **[P0]** Tutup T-01 dengan `PemisahanTugas` di 4 method + test regresi.
2. **[P1]** Perbaiki T-02, T-03.
3. **[P2]** Bersihkan T-04, T-05; lengkapi T-06.
4. **[P3]** Evaluasi T-07 dengan kebijakan koperasi.

**Catatan:** Seluruh temuan bersifat *read-only finding*. Tidak ada perubahan kode dilakukan selama audit. Perbaikan menunggu persetujuan pemilik sistem.
