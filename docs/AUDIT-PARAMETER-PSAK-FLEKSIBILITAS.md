# Audit Parameter Sistem — Fleksibilitas Dinamis & Kepatuhan PSAK

**Status:** Laporan Audit (read-only, tanpa perubahan kode)
**Tanggal:** 2026-10-01
**Cakupan:** Seluruh parameter operasional, keuangan, dan syariah pada sistem Koperasi Syariah
**Dasar standar:** PSAK 101, 102, 105, 106, 107, 108, 109, 110, 111 · Fatwa DSN-MUI · UU No. 25/1992 · Permen KUKM No. 09/2018
**Khalayak:** Pengurus, Manajer, Dewan Pengawas Syariah (DPS), Tim TI, Auditor Internal

---

## 1. Ringkasan Eksekutif

Sistem telah memiliki **infrastruktur kebijakan terpusat yang matang** (`App\Support\Setting` → tabel
`kebijakan_koperasis`, dengan cache + auto-invalidation + audit trail). Namun pemanfaatannya **belum merata**.

| Kategori | Jumlah | Contoh |
|---|---|---|
| ✅ Parameter sudah dinamis & terpusat | ± 60 kunci di `Setting::DEFAULTS` | CKPN kol1–kol5, ambang kolektibilitas, ta'zir, nisbah/margin default, limit teller, tutup buku |
| ⚠️ Parameter produk sudah dinamis | `jenis_simpanans`, `deposito_products`, `master_produk_pembiayaan`, `pemetaan_akad_coa` | nisbah produk, tarif penalti, plafon, tenor, pemetaan akad→CoA |
| 🔴 Parameter masih **hardcode** (kaku) | ≥ 25 temuan | Klasifikasi akad (PSAK 107), tahun buku, skema alokasi SHU, toleransi 0,01, aturan pembulatan |
| ❌ Modul/parameter **belum ada** | PSAK 110 (Sukuk), PSAK 111 (Waqaf) | Tidak ada modul, tabel, maupun parameter |

**Tiga temuan paling kritis:**

1. **🔴 Konflik kebijakan SHU (dua sumber kebenaran).** `ShuController` mengelola **5 pos** alokasi
   (`persen_cadangan`, `persen_jasa_modal`, `persen_jasa_transaksi`, `persen_pengurus`, `persen_sosial`)
   dari input form, sedangkan `Setting::alokasiShu()` mengelola **4 pos berbeda**
   (`shu_pct_cadangan_modal`, `shu_pct_jasa_anggota`, `shu_pct_jasa_modal`, `shu_pct_dana_pendidikan`).
   Nilai default keduanya pun berbeda (25/40/20/10/5 vs 30/40/20/10).
2. **🔴 Zakat atas SHU tidak dihitung otomatis.** `Coa::DANA_ZAKAT` (2-3002) hanya berfungsi sebagai
   wadah titipan; **tidak ada** parameter `zakat_rate`, `nisab`, maupun `haul` di seluruh sistem.
3. **🔴 Tahun buku dipatok Januari–Desember.** `ShuCalculatorService`, `LaporanKeuanganSyariahService`,
   dan `PendapatanService` mengasumsikan tahun buku kalender, sehingga koperasi dengan tahun buku
   non-kalender (mis. Hijriah atau April–Maret) salah hitung SHU & laporan.

**Kesimpulan:** Sistem **belum sepenuhnya dapat menyesuaikan perubahan regulasi/kebijakan tanpa
modifikasi kode inti.** Diperlukan penambahan ± 30 parameter baru (didominasi PSAK 109/110/111,
tahun buku, dan klasifikasi akad) agar target "dinamis tanpa ubah kode" tercapai.

---

## 2. Metodologi

1. Pemetaan parameter eksisting: `Setting::DEFAULTS` / `TIPE` / `DESKRIPSI`, `KebijakanKoperasi`,
   `AdminSettingsController`, `PengurusController::updateKebijakan`, serta seluruh model produk.
2. Penelusuran seluruh kode (`app/`, `database/`, `resources/`, `routes/`, `tests/`, `docs/`) untuk
   kata kunci: `waqaf|wakaf|sukuk|nisab|haul|hak amil|zakat_rate|2.5%`, `round(`, konstanta `PERSEN_*`,
   `RATE_*`, `DEFAULT_*`, `BATAS_*`, `LIMIT_*`.
3. Perbandingan terhadap persyaratan PSAK terkait.
4. Identifikasi titik kaku (nilai kebijakan yang ditanam di kode, bukan di basis data).
5. Validasi mekanisme penambahan parameter (apakah bisa tanpa modifikasi logika inti).

---

## 3. Peta Parameter yang Sudah Terimplementasi

### 3.1 Infrastruktur (fondasi — sudah benar)

| Komponen | Berkas | Fungsi |
|---|---|---|
| Registri parameter | [Setting.php](file:///c:/laragon/www/koperasi_syariah/app/Support/Setting.php#L38-L128) | `DEFAULTS` (±60 kunci), `TIPE`, `DESKRIPSI` |
| Penyimpanan & audit | [KebijakanKoperasi.php](file:///c:/laragon/www/koperasi_syariah/app/Models/KebijakanKoperasi.php) | `booted()` → `Setting::flush()` + `catatRiwayat()` |
| Editor UI | [AdminSettingsController.php](file:///c:/laragon/www/koperasi_syariah/app/Http/Controllers/AdminSettingsController.php#L60-L94) | Metadata dipublikasikan **generik** dari `Setting::DEFAULTS` |
| Jalur simpan | [PengurusController.php](file:///c:/laragon/www/koperasi_syariah/app/Http/Controllers/Dashboard/PengurusController.php#L669-L760) | `updateKebijakan()` — validasi & penugasan eksplisit per field |

> **Catatan sinkronisasi (2026-10-14):** parameter `limit_penarikan_teller` &
> `limit_setoran_teller` sebelumnya hanya ada di DB/model/`Setting` dan dipakai
> `HierarkiRole`, tetapi **tidak terekspos** di form kebijakan Pengurus (hanya dapat
> diubah lewat DB). Kini keduanya tampil & tersimpan dari section "Limit Kas & Transaksi
> Teller" pada halaman kebijakan Pengurus. Rincian: `LAPORAN-AUDIT-PARAMETER-LIMIT-TELLER.md`.


### 3.2 Parameter per Domain

**A. Operasional & Limit**

| Parameter | Default | Berkas |
|---|---|---|
| `limit_manajer`, `limit_pembiayaan_manajer` | 50.000.000 | Setting.php:40-41 |
| `limit_penarikan_teller` | 5.000.000 | Setting.php:42 — **dapat diubah dari UI Pengurus** (form kebijakan, section "Limit Kas & Transaksi Teller") |
| `limit_setoran_teller` | 50.000.000 | Setting.php:43 — **dapat diubah dari UI Pengurus** (form kebijakan, section "Limit Kas & Transaksi Teller") |
| `teller_max_cash_limit` | 25.000.000 | Setting.php:47 |
| `minimum_transaksi_teller` | 1.000 | Setting.php:48 |
| `limit_approval_beban` | 1.000.000 | Setting.php:69 |

**B. Keanggotaan & Simpanan**

| Parameter | Default | Berkas |
|---|---|---|
| `mandatory_principal_savings` (Simpanan Pokok) | 100.000 | Setting.php:49 |
| `mandatory_monthly_savings` (Simpanan Wajib) | 50.000 | Setting.php:50 |
| `minimum_pembukaan_rekening` / `maksimum_pembukaan_rekening` | 10 rb / 50 jt | Setting.php:52-53 |
| `simpanan_wajib_kanal_default` | AUTO_DEBIT | Setting.php:59 |
| `simpanan_wajib_boleh_ditarik` / `simpanan_wajib_hari_penarikan` | false / SETELAH_KELUAR | Setting.php:60-61 |
| `account_simpanan_wajib_id` | null | Setting.php:62 |
| `jenis_simpanans`: `min_deposit_amount`, `kode_akun`, `allow_auto_debit`, `account_coa_id` | per produk | `JenisSimpanan.php` |

**C. Bagi Hasil & Deposito (PSAK 105)**

| Parameter | Default | Berkas |
|---|---|---|
| `deposito_rate_pa` | 0.08 | Setting.php:87 |
| `nisbah_default_persen` / `margin_default_persen` | 60 / 12 | Setting.php:89-90 |
| `deposito_products`: `nisbah_anggota`, `nisbah_koperasi`, `tenor_months`, `min_amount`, `allow_early_withdrawal`, `penalty_fee_type`, `penalty_fee_amount`, `forfeit_monthly_profit`, `penalty_coa_id` | per produk | `DepositoProduct.php` |

**D. Pembiayaan Syariah (PSAK 102/106/107/108)**

| Parameter | Keterangan | Berkas |
|---|---|---|
| `master_produk_pembiayaan`: `akad_syariah`, `min/max_plafon`, `margin_persen`, `nisbah_anggota`, `nisbah_koperasi`, `biaya_admin`, `min_bulan_anggota`, `min/max_tenor_bulan`, `status_sertifikasi_dps` | per produk | `MasterProdukPembiayaan.php` |
| `pemetaan_akad_coa`: `kode_produk`, `akad_pembiayaan`, `account_piutang_id`, `account_pendapatan_id`, `account_margin_ditangguhkan_id` | akad→CoA dinamis | `PemetaanAkadCoa.php` |
| `account_piutang_*_id`, `account_investasi_*_id`, `account_margin_deferred_id` | null = ikuti standar | Setting.php:105-116 |
| `tazir_mode`, `tazir_rate`, `tazir_grace_period_days`, `tazir_default_method`, `tazir_default_nominal`, `akun_tazir_id`, `financing_late_fee_per_day` | ta'zir | Setting.php:74-84 |
| `hari_libur_aktif`, `hari_libur_strategi`, `tanggal_jatuh_tempo_default` | penjadwalan | Setting.php:119-123 |

**E. Risiko & CKPN (PSAK 101 / PSAK 71)**

| Parameter | Default | Berkas |
|---|---|---|
| `ckpn_persen_kol1..kol5` | 0.50 / 5 / 15 / 50 / 100 | Setting.php:97-101 |
| `kolektibilitas_kol2/3/4_hari` | 30 / 90 / 180 | Setting.php:71-73 |

> ✅ **Pola unggulan** — `CkpnService` + `CkpnController` sepenuhnya bebas hardcode; layak dijadikan
> acuan migrasi untuk modul lain.

**F. SHU**

| Parameter | Default | Berkas |
|---|---|---|
| `shu_pct_cadangan_modal` | 30.00 | Setting.php:64 |
| `shu_pct_jasa_anggota` | 40.00 | Setting.php:65 |
| `shu_pct_jasa_modal` | 20.00 | Setting.php:66 |
| `shu_pct_dana_pendidikan` | 10.00 | Setting.php:67 |
| `shu_hold_on_overdue` | true | Setting.php:54 |

**G. Periode, Integritas & Kepatuhan**

| Parameter | Default | Berkas |
|---|---|---|
| `tutup_buku_sampai` | null | Setting.php:127 |
| `pembulatan_desimal` | 0 | Setting.php:94 |
| `toleransi_selisih_jurnal` | 0.01 | Setting.php:95 |
| `aml_threshold` (PPATK) | 100.000.000 | Setting.php:92 |

**H. Zakat / Infak / Sedekah (PSAK 109) — modul ada, parameter syariah belum**

| Sudah ada | Berkas |
|---|---|
| Akun dana terpisah: `Coa::DANA_ZAKAT` (2-3002), `Coa::DANA_INFAK_SEDEKAH` (2-3004) | `Coa.php:85-99` |
| 8 asnaf + 4 kategori zakat + 3 kategori infak (**hardcode konstanta**) | `ZakatService.php:35-44`, `ZakatTransaksi.php:30-52` |
| Jurnal otomatis, laporan sumber & penggunaan dana, kontrol silang | `ZakatService.php` |
| `pembulatan_desimal` & `toleransi_selisih_jurnal` (sudah dinamis) | Setting.php:94-95 |

---

## 4. Perbandingan dengan PSAK — Celah Parameter

| PSAK | Cakupan | Status | Celah parameter |
|---|---|---|---|
| **101** | Penyajian Laporan Keuangan Syariah | ✅ Sebagian | Tahun buku non-kalender; ambang materialitas 0,01 hardcode |
| **102** | Murabahah | ✅ Sebagian | Margin ditangguhkan ada; DP/uang muka & provisi belum |
| **105** | Mudharabah | ✅ Sebagian | Nisbah produk ada; `bulan_per_tahun` (12) hardcode |
| **106** | Musyarakah | ✅ Sebagian | Nisbah produk ada |
| **107** | Ijarah | 🔴 Kaku | Klasifikasi akad (margin/bagi hasil/ujroh) **hardcode di kode** |
| **108** | Akuntansi Transaksi Syariah (pemurnian) | ✅ Sebagian | Ta'zir ada; pemetaan akun denda per-akad belum |
| **109** | Zakat, Infak, Sedekah | 🟠 Modul ada | **Nisab, tarif zakat, haul, hak amil, objek zakat, akun dana configurable** |
| **110** | Sukuk | ❌ **Tidak ada** | Seluruh modul & parameter |
| **111** | Waqaf | ❌ **Tidak ada** | Seluruh modul & parameter |

### 4.1 Celah PSAK 109 (Zakat / Infak / Sedekah)

| Parameter hilang | Kebutuhan PSAK / Fatwa | Dampak |
|---|---|---|
| `zakat_nisab_emas_gram` / `zakat_nisab_perak_gram` / `zakat_nisab_beras_kg` | Ambang wajib zakat (85 gr emas / 595 gr perak / 653 kg beras) | Tidak ada penentuan wajib/tidak wajib zakat |
| `zakat_rate_maal`, `zakat_rate_penghasilan`, `zakat_rate_perdagangan` (2,5%), `zakat_rate_pertanian` (5%/10%), `zakat_rate_rikaz` (20%) | Tarif per jenis harta | Zakat hanya dicatat manual; tidak dapat dihitung sistem |
| `zakat_haul_bulan` (12) / `zakat_periode` | Kepemilikan 1 tahun | Tidak ada kontrol haul |
| `zakat_hak_amil_persen` (maks 12,5%) | Porsi amil dari 8 asnaf | Tidak ada alokasi otomatis amil : mustahik |
| `zakat_objek_harta[]` (emas, uang, saham, ternak, pertanian) + konversi | Klasifikasi harta wajib zakat | Tidak ada basis zakat |
| `account_dana_zakat_id`, `account_dana_infak_sedekah_id`, `account_kas_zakat_id` | Akun configurable | Kode CoA dipatok (`Coa.php:92,99`) |
| `zakat_asnaf[]`, `zakat_kategori[]` | Daftar asnaf/kategori | Hardcode konstanta, tak bisa ditambah |

### 4.2 Celah PSAK 110 (Sukuk) — ❌ Belum ada sama sekali

Tidak ditemukan model, tabel, service, controller, route, view, akun CoA, maupun parameter.
Komentar pada [migrasi PSAK](file:///c:/laragon/www/koperasi_syariah/database/migrations/2026_09_26_000500_add_psak_parameters_to_kebijakan_koperasis.php#L34)
menyatakan "110 (sukuk — tidak dipakai)".

**Parameter yang dibutuhkan bila diaktifkan:** `sukuk_imbal_hasil_persen`, `sukuk_ujrah_persen`,
`sukuk_tenor_bulan`, `sukuk_underlying_asset[]`, `sukuk_jenis_akad` (ijarah/mudharabah/wakalah),
`account_sukuk_*_id`, `sukuk_denda_persen`, `sukuk_opsi_beli_persen`.

### 4.3 Celah PSAK 111 (Waqaf) — ❌ Belum ada sama sekali

Satu-satunya kemunculan kata "wakaf" hanya di dokumen analisis, bukan kode.

**Parameter yang dibutuhkan bila diaktifkan:** `waqaf_jenis[]` (tunai/aset), `waqaf_nazhir_persen`,
`waqaf_jangka_waktu` (perpetual/terbatas), `account_dana_waqaf_id`, `waqaf_mauquf_alaih[]`,
`waqaf_pemurnian_pokok_hasil` (pemisahan pokok vs hasil).

> **Catatan:** terdapat salah penomoran pada komentar migrasi (`111 (wakalah)` — seharusnya
> PSAK 111 adalah Akuntansi Waqaf, sedangkan wakalah adalah PSAK 112).

---

## 5. Parameter yang Menyebabkan Kekakuan (Hardcode)

| # | Temuan | Berkas:Baris | Nilai kaku | Parameter pengganti |
|---|---|---|---|---|
| K1 | 🔴 **Skema alokasi SHU 5-pos dari form** | [ShuController.php](file:///c:/laragon/www/koperasi_syariah/app/Http/Controllers/Pengurus/ShuController.php#L100-L104) | `persen_cadangan/jasa_modal/jasa_transaksi/pengurus/sosial` | `shu_alokasi_pos[]` terpusat |
| K2 | 🔴 **Default form SHU bentrok dengan Setting** | `shu/index.blade.php:680-684` | 25/40/20/10/5 vs 30/40/20/10 | satu sumber `Setting::alokasiShu()` |
| K3 | 🔴 **Klasifikasi akad pendapatan** | [PendapatanService.php](file:///c:/laragon/www/koperasi_syariah/app/Services/PendapatanService.php#L44-L53) | `AKAD_MARGIN`, `AKAD_BAGI_HASIL`, `AKAD_UJROH`, `STATUS_ANGSURAN_DIBAYAR` | `akad_margin[]`, `akad_bagi_hasil[]`, `akad_ujroh[]` |
| K4 | 🔴 **Tahun buku Jan–Des** | `ShuCalculatorService.php:574-575`; `LaporanKeuanganSyariahService.php:140`; `PendapatanService.php:206` | `Carbon::create($tahun,1,1)`…`12,31` | `awal_tahun_buku`, `akhir_tahun_buku` |
| K5 | 🔴 **Toleransi 0,01 hardcode** (padahal param tersedia) | `DanaKebajikanService.php:199`; `LaporanKeuanganSyariahService.php:700,719`; `DanaCadanganController.php:255` | `0.01` | `Setting::toleransiSelisihJurnal()` |
| K6 | 🟠 **Basis Jasa Modal & Jasa Transaksi SHU** | `ShuCalculatorService.php:44-52` | `JENIS_TRANSAKSI_PARTISIPASI`, `REKENING_MODAL` | `shu_partisipasi_jenis_transaksi[]`, `shu_rekening_modal[]` |
| K7 | 🟠 **Aturan pembulatan** (`round(...,2)` di 50+ lokasi) | Seluruh service | `round(..., 2)` | `Setting::pembulatanDesimal()` |
| K8 | 🟠 **Sumber tunai Dana Kebajikan** | `DanaKebajikanService.php:39-46` | 6 label literal | `kebajikan_sumber_tunai[]` |
| K9 | 🟠 **Whitelist kategori Dana Kebajikan** | `DanaKebajikanService.php:49-52,72-79,95-107` | `KATA_KUNCI_*`, `KATEGORI_*` | `kebajikan_kategori[]` |
| K10 | 🟠 **Batas hari restrukturisasi** | `RestrukturisasiService.php:36` | `BATAS_HARI_TERLAMBAT = 30` | `restrukturisasi_batas_hari` |
| K11 | 🟠 **Tenor deposito dibatasi** | `DepositoProduct.php:22` | `TENOR_DIIZINKAN = [1,3,6,12]` | `deposito_tenor_diizinkan[]` |
| K12 | 🟠 **Format nomor dokumen** (10+ lokasi) | SHU/DK/Deposito/Simpanan Wajib | `'SHU-'.Ymd`, `'DK-SALUR-'`, `str_pad(...,4)` | `format_no_*`, `lebar_urutan` |
| K13 | 🟠 **Ambang kode akun arus kas** | `LaporanKeuanganSyariahService.php:793` | `substr($akun,2,4) >= 1003` | registry CoA |
| K14 | 🟠 **Asumsi 12 bulan/tahun** | `DepositoService.php:104` | `($tenor/12)` | `bulan_per_tahun` |
| K15 | 🟠 **Duplikasi sumber produk simpanan** | `jenis_simpanans` vs `deposito_products` | dua tabel tipe pokok/wajib/sukarela | satukan sumber |
| K16 | 🟡 **Daftar akad kaku di CHECK constraint DB** | migrasi `2026_09_30_000700:43-48` | 8 akad | sudah dimitigasi `PemetaanAkadCoa` |
| K17 | 🟡 **`PendapatanService` tanpa `Setting`** | seluruh file | 100% rigid | tambahkan referensi Setting |
| K18 | 🟡 **Nisbah per-akad belum ada** | `MasterProdukPembiayaan` | `margin_persen` tunggal | `nisbah_persen`/`ujrah_persen` per akad |
| K19 | 🟡 **DP/uang muka, provisi, asuransi** | `MasterProdukPembiayaan`, `Pembiayaan` | tidak ada | `dp_persen`, `biaya_provisi`, `biaya_asuransi` |
| K20 | 🟡 **Grace period per-produk** | produk pembiayaan | tidak ada | `grace_period_hari` |

---

## 6. Laporan Celah: Daftar Parameter Hilang, Dampak & Rekomendasi

### Prioritas 1 — Integritas Kebijakan (risiko salah saji)

| Parameter usulan | Tipe | Domain | Dampak bila tidak ada | Rekomendasi |
|---|---|---|---|---|
| `shu_alokasi_pos[]` (JSON) | json | SHU | Dua sumber kebenaran (4 vs 5 pos) → SHU salah bagi | Satukan ke satu skema; `ShuController` membaca/menulis via `Setting` |
| `awal_tahun_buku`, `akhir_tahun_buku` | string | Periode | Tahun buku non-kalender salah hitung | Ganti asumsi Jan–Des di 3 service |
| `zakat_rate_*`, `zakat_nisab_*`, `zakat_haul_bulan`, `zakat_hak_amil_persen` | decimal/integer | PSAK 109 | Zakat atas SHU tidak dapat dihitung otomatis | Tambah kalkulator + parameter |
| `pph_rate` / `pajak_rate` | decimal | Pajak | PPh atas SHU tidak dihitung | Tambah parameter pajak |

### Prioritas 2 — Kepatuhan Syariah & PSAK

| Parameter usulan | Tipe | Domain | Dampak bila tidak ada | Rekomendasi |
|---|---|---|---|---|
| `akad_margin[]`, `akad_bagi_hasil[]`, `akad_ujroh[]` | json | PSAK 107 | Akad baru salah klasifikasi pendapatan | Eksternalisasi dari `PendapatanService` |
| `zakat_asnaf[]`, `zakat_kategori[]` | json | PSAK 109 | Asnaf/kategori tak dapat disesuaikan fatwa baru | Pindah dari konstanta ke Setting |
| `account_dana_zakat_id`, `account_dana_infak_sedekah_id` | integer | PSAK 109 | Akun dana dipatok kode | Tambah pemetaan akun |
| `waqaf_*` (lihat §4.3) | campuran | PSAK 111 | **Modul waqaf tidak ada** | Bangun modul bertahap |
| `sukuk_*` (lihat §4.2) | campuran | PSAK 110 | **Modul sukuk tidak ada** | Bangun modul bila diperlukan |

### Prioritas 3 — Fleksibilitas Operasional

| Parameter usulan | Tipe | Domain | Dampak bila tidak ada | Rekomendasi |
|---|---|---|---|---|
| `shu_partisipasi_jenis_transaksi[]`, `shu_rekening_modal[]` | json | SHU | Basis jasa transaksi/modal kaku | Eksternalisasi |
| `kebajikan_sumber_tunai[]`, `kebajikan_kategori[]` | json | Dana Kebajikan | Kategori baru wajib ubah kode | Eksternalisasi (dengan gerbang protektif) |
| `dp_persen`, `biaya_provisi`, `biaya_asuransi`, `grace_period_hari` | decimal/integer | Pembiayaan | Produk tidak fleksibel | Tambah kolom produk |
| `nisbah_persen`/`ujrah_persen` per akad | decimal | Pembiayaan | Satu `margin_persen` untuk semua akad | Tambah kolom per akad |
| `restrukturisasi_batas_hari`, `deposito_tenor_diizinkan[]` | integer/json | Operasional | Batas kaku | Eksternalisasi |
| `format_no_transaksi`, `format_no_jurnal`, `lebar_urutan` | string/integer | Dokumen | Format nomor kaku | Eksternalisasi |
| `bulan_per_tahun` | integer | Bagi hasil | Asumsi 12 bulan | Eksternalisasi |

### Prioritas 4 — Konsistensi Teknis

| Temuan | Rekomendasi |
|---|---|
| `round(..., 2)` di 50+ lokasi | Terapkan `Setting::pembulatanDesimal()` seragam |
| Toleransi `0.01` di 4 lokasi | Ganti dengan `Setting::toleransiSelisihJurnal()` |
| Duplikasi `jenis_simpanans` vs `deposito_products` | Satukan sumber parameter simpanan |
| `PendapatanService` tanpa `Setting` | Tambahkan referensi Setting |
| Inkonsistensi skema: `MasterProdukPembiayaan::generateKode()` merujuk `kode_produk` yang tidak ada di migrasi; `Angsuran.status_tazir` di `$fillable` tanpa kolom; `Pembiayaan.status_kolektibilitas`/`tanggal_hapus_buku` tanpa migrasi | Audit & sinkronkan skema |
| Dua sumber hari terlambat: `Angsuran::getHariTerlambatDinamisAttribute()` vs `hitungHariTerlambat()` | Satukan |

---

## 7. Validasi Usulan (Implementasi Tanpa Ubah Kode Inti)

### 7.1 Mekanisme yang Sudah Terbukti

Menambahkan parameter ke `Setting::DEFAULTS` **otomatis terekspos di UI** karena
[AdminSettingsController::index()](file:///c:/laragon/www/koperasi_syariah/app/Http/Controllers/AdminSettingsController.php#L73-L80)
membangun metadata secara **generik** dari `collect(Setting::DEFAULTS)->keys()` — tanpa menyalin daftar
ke Blade (prinsip zero-hardcoding). Perubahan nilai juga **langsung berlaku** karena
`KebijakanKoperasi::booted()` memanggil `Setting::flush()`.

### 7.2 Jalur yang Perlu "Plumbing Konfigurasi" (bukan logika inti)

Untuk tiap parameter baru, diperlukan 3–4 penambahan **deklaratif**:

1. Kunci di `Setting::DEFAULTS` (+ nilai default).
2. Tipe di `Setting::TIPE`.
3. Deskripsi di `Setting::DESKRIPSI` (opsional, untuk UI).
4. Aturan validasi + penugasan di `PengurusController::updateKebijakan()`.

> **Verifikasi:** langkah 1–4 bersifat **deklaratif/plumbing**; **tidak menyentuh logika bisnis**
> (perhitungan SHU, jurnal, CKPN, laporan). Setelah parameter tersedia, perubahan kebijakan
> (mis. tarif zakat, tahun buku, persentase alokasi) **cukup diubah dari UI Pengurus tanpa deploy ulang.**

### 7.3 Contoh Validasi: Parameter `zakat_rate_penghasilan`

| Langkah | Berkas | Sifat |
|---|---|---|
| Tambah `'zakat_rate_penghasilan' => 2.50` | `Setting::DEFAULTS` | deklaratif |
| Tambah `'zakat_rate_penghasilan' => 'decimal'` | `Setting::TIPE` | deklaratif |
| Tambah deskripsi | `Setting::DESKRIPSI` | deklaratif |
| Tambah `'zakat_rate_penghasilan' => 'nullable|numeric|min:0|max:100'` + `$kebijakan->zakat_rate_penghasilan = ...` | `updateKebijakan()` | deklaratif |
| Baca via `Setting::get('zakat_rate_penghasilan')` di kalkulator zakat | `ZakatService` | **hanya bila fitur baru** |

**Kesimpulan validasi:** ✅ Untuk parameter yang **sudah punya konsumen logika** (mis. mengganti
nilai toleransi/pembulatan/ambang), perubahan **100% dapat dilakukan tanpa modifikasi kode inti** —
cukup tambah deklarasi + ubah nilai di UI. ❌ Untuk **fitur/modul baru** (waqaf, sukuk, kalkulator
zakat), tetap diperlukan pengembangan kode sekali, namun **setelah itu parameternya dinamis**.

---

## 8. Roadmap Implementasi Bertahap

| Fase | Fokus | Keluaran |
|---|---|---|
| **F1** | Integritas kebijakan SHU & tahun buku | `shu_alokasi_pos[]`, `awal_tahun_buku`, `akhir_tahun_buku`; hapus konflik 4 vs 5 pos |
| **F2** | PSAK 109 lengkap | `zakat_nisab_*`, `zakat_rate_*`, `zakat_haul_bulan`, `zakat_hak_amil_persen`, akun dana configurable, asnaf/kategori dinamis |
| **F3** | Fleksibilitas pendapatan & kebajikan | `akad_margin[]`, `akad_bagi_hasil[]`, `akad_ujroh[]`, `kebajikan_sumber_tunai[]` |
| **F4** | Produk pembiayaan | DP, provisi, asuransi, grace period, nisbah/ujrah per akad |
| **F5** | Konsistensi teknis | Seragamkan pembulatan & toleransi; sinkronkan skema; satukan sumber produk simpanan |
| **F6** | PSAK 111 (Waqaf) | Modul baru (opsional, sesuai kebutuhan koperasi) |
| **F7** | PSAK 110 (Sukuk) | Modul baru (opsional) |

---

## 9. Lampiran — Berkas Referensi Utama

| Berkas | Peran |
|---|---|
| [Setting.php](file:///c:/laragon/www/koperasi_syariah/app/Support/Setting.php) | Registri parameter (`DEFAULTS`/`TIPE`/`DESKRIPSI`) |
| [KebijakanKoperasi.php](file:///c:/laragon/www/koperasi_syariah/app/Models/KebijakanKoperasi.php) | Penyimpanan, cache, audit trail |
| [AdminSettingsController.php](file:///c:/laragon/www/koperasi_syariah/app/Http/Controllers/AdminSettingsController.php) | Editor UI generik |
| [PengurusController.php](file:///c:/laragon/www/koperasi_syariah/app/Http/Controllers/Dashboard/PengurusController.php) | Jalur simpan kebijakan |
| [ZakatService.php](file:///c:/laragon/www/koperasi_syariah/app/Services/ZakatService.php) | PSAK 109 |
| [CkpnService.php](file:///c:/laragon/www/koperasi_syariah/app/Services/CkpnService.php) | Pola unggulan zero-hardcoding |
| [PendapatanService.php](file:///c:/laragon/www/koperasi_syariah/app/Services/PendapatanService.php) | PSAK 107 (paling rigid) |
| [PemetaanAkadCoa.php](file:///c:/laragon/www/koperasi_syariah/app/Models/PemetaanAkadCoa.php) | Pola akad→CoA dinamis |
| [Coa.php](file:///c:/laragon/www/koperasi_syariah/app/Support/Coa.php) | Registri standar akun (fallback) |
| [TAHAP11-FLEKSIBILITAS-DINAMIS-PSAK.md](file:///c:/laragon/www/koperasi_syariah/docs/TAHAP11-FLEKSIBILITAS-DINAMIS-PSAK.md) | Dasar fleksibilitas yang sudah dicapai |
