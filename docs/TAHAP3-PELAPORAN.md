# TAHAP 3 — PELAPORAN (Klaster B)

**Status:** ✅ SELESAI & TERVERIFIKASI
**Tanggal penyelesaian:** 2026-09-25
**Lingkup temuan:** Klaster B — 12 temuan ketidaklengkapan penyajian laporan
**Dasar standar:** PSAK 101 (penyajian laporan keuangan syariah), PSAK 109, PSAK 108
**Tahap sebelumnya:** `docs/TAHAP2-PSAK-INTI.md` (826 passed / 4015 assertions)

---

## 1. Ringkasan Eksekutif

Tahap 2 menutup **seluruh** temuan Klaster A dan menyisakan satu risiko sisa yang
paling material (R2.1): *"5 dari 8 komponen laporan PSAK 101 belum ada"*. Tahap 3
menutup risiko itu sekaligus 12 temuan Klaster B pada area **penyajian laporan**.

Sebelum Tahap 3, koperasi hanya memiliki dua potongan laporan:

- Neraca (Laporan Posisi Keuangan) — `laporan.neraca`
- Buku Besar per akun — `laporan.buku-besar`

Keduanya **tidak dapat direkonsiliasi silang**: tidak ada Laporan Laba Rugi, tidak ada
Laporan Perubahan Ekuitas, tidak ada Laporan Arus Kas, tidak ada Laporan Perubahan Dana
Investasi Terikat, dan tidak ada Catatan atas Laporan Keuangan. Akibatnya laporan tidak
dapat dibuktikan konsisten satu terhadap yang lain, dan tidak memenuhi PSAK 101 yang
mewajibkan **laporan keuangan lengkap** sebagai satu kesatuan penyajian.

Tahap 3 membangun **satu halaman Laporan Keuangan Syariah lengkap (PSAK 101)** yang
menyajikan keenam komponen sekaligus, seluruhnya dihasilkan dari **satu sumber
kebenaran** (mutasi jurnal CoA):

1. **Laporan Posisi Keuangan** — Aset = Kewajiban + DST + Ekuitas + Laba berjalan
2. **Laporan Laba Rugi** — pemisahan Pendapatan Usaha / Lain / Tak Terklasifikasi
   dan Beban Usaha / Lain / Tak Terklasifikasi, beserta margin
3. **Laporan Perubahan Ekuitas** — saldo awal → penambah → pengurang → saldo akhir
   per akun ekuitas
4. **Laporan Arus Kas (Metode Langsung)** — Operasi / Investasi / Pendanaan / Lain-lain
   + rekonsiliasi saldo kas
5. **Laporan Perubahan Dana Investasi Terikat** — saldo awal → penerimaan → penyaluran
   → saldo akhir (PSAK 109)
6. **Catatan atas Laporan Keuangan (CaLK)** — kebijakan akuntansi, temuan penyajian,
   dan diagnostik integritas

### Kriteria Keberhasilan Tahap 3

| # | Kriteria | Target | Hasil | Status |
|---|---|---|---|---|
| K3.1 | Keenam komponen PSAK 101 disajikan dalam satu halaman | 6 bagian berjudul + CaLK | Konstanta `JUDUL_BAGIAN` (6 judul) terbukti tampil di HTML | ✅ |
| K3.2 | Laba bersih identik di Neraca, Laba Rugi, dan Perubahan Ekuitas | selisih = 0 | Test `test_laba_bersih_identik_di_neraca_laba_rugi_dan_perubahan_ekuitas` | ✅ |
| K3.3 | Neraca seimbang | Total Aset = Total Pasiva | Test `test_total_aset_sama_dengan_total_pasiva`; `totalPasiva = Kewajiban + DST + Ekuitas + Laba berjalan` | ✅ |
| K3.4 | Arus kas merekonsiliasi perubahan saldo kas | saldo akhir − saldo awal = Σ arus | Test `test_arus_kas_rekonsiliasi_dengan_perubahan_saldo_kas` | ✅ |
| K3.5 | Mutasi DIT seimbang dengan saldo akhir | debet = kredit, saldo akhir konsisten | Test `test_dit_mutasi_seimbang_dengan_saldo_akhir` | ✅ |
| K3.6 | Dana titipan PSAK 109 tidak pernah jadi pendapatan | 0 kebocoran | Test `test_akun_dana_titipan_tidak_pernah_masuk_pendapatan_laba_rugi` | ✅ |
| K3.7 | Akun tak terklasifikasi tetap disajikan (tidak hilang) | muncul + jadi temuan CaLK | Test `test_akun_tak_terklasifikasi_tetap_disajikan_dan_menjadi_temuan` | ✅ |
| K3.8 | Identitas arus kas tahan penambahan jurnal nyata | delta jurnal = delta arus kas | 3 test delta jurnal kas | ✅ |
| K3.9 | RBAC modul `laporan_psak101` terdaftar & ditegakkan | matriks + 403 + log audit | 7 test RBAC | ✅ |
| K3.10 | Ekspor Excel berfungsi & tunduk RBAC | attachment + 6 komponen + 403 | 3 test ekspor | ✅ |
| K3.11 | Periode masa depan dijepit ke hari ini | tidak ada laporan periode belum terjadi | 2 test periode | ✅ |
| K3.12 | Suite regresi tidak turun | ≥ 826 passed / 4015 assertions | **854 passed / 4114 assertions** (+28 test, +99 assertion) | ✅ |

---

## 2. Aktivitas yang Dilaksanakan

### 2.1 Rekonsiliasi Ulang Struktur Data (recon lanjutan)

Sebelum menulis agregator, struktur nyata diverifikasi kembali agar tidak mengulang
kesalahan `jurnals` / `akun_id` yang terjadi di Tahap 2:

| Objek | Temuan | Implikasi untuk Tahap 3 |
|---|---|---|
| Tabel baris jurnal | `jurnal_detail` | Simpan sebagai sumber tunggal semua angka |
| Kolom penghubung CoA | **`coa_id`** | Semua agregasi laporan memakai `coa_id`, **tidak** `akun_id` |
| Kolom batas waktu | **`tanggal_jurnal`** | Batas periode arus kas & saldo **wajib** `tanggal_jurnal`, **bukan** `created_at` |
| Kolom wajib `jurnal_detail` | `baris` **NOT NULL** tanpa default | Pembuatan jurnal uji wajib mengisi `baris` berurutan |
| Model `LaporanKeuanganLog` | kolom `role` & `aktivitas` | Test audit memakai nama kolom ini (bukan `user_peran`/`aksi`) |
| `Setting::toleransiSelisihJurnal()` | tersedia | Dipakai sebagai ambang `terdeteksi`/`seimbang`, bukan hardcode `0.01` |
| `Setting::pembulatanDesimal()` | tersedia | Rujukan presisi penyajian |

> **Catatan temuan tambahan (T3-B1):** kolom `jurnal_detail.baris` bersifat
> `int NOT NULL` **tanpa nilai default**, sehingga setiap insert jurnal manual
> **wajib** mengisi `baris`. Ini terungkap saat pengujian dan dicatat sebagai
> masukan untuk Tahap 5 (integritas data / kelengkapan skema).

### 2.2 Pembangunan Service Agregator Laporan

Satu service menjadi **satu-satunya** tempat angka laporan dihitung, sehingga
controller dan view tidak pernah menghitung ulang dan tidak mungkin berbeda:

**`app/Services/LaporanKeuanganSyariahService.php`**

| Method | Keluaran |
|---|---|
| `periode($tanggal, $mode)` | `dari, sampai, dariLalu, sampaiLalu, dariStr, sampaiStr, tahun, mode, tanggalInput` |
| `posisiKeuangan()` | `asetGroups, kewajibanGroups, dstGroups, ekuitasGroups, totalAset, totalKewajiban, totalDanaSyirkahTemporer, totalEkuitas, labaBersih, totalPasiva, selisihNeraca, seimbang, statusLaba` |
| `labaRugi()` | `pendapatanUsaha/Lain/TakKlasifikasi, bebanUsaha/Lain/TakKlasifikasi, totalPendapatan, totalBeban, labaKotor, labaSebelumPajak, labaBersih, marginLabaKotor, marginLabaBersih, statusLaba, adaTakKlasifikasi` |
| `perubahanEkuitas()` | baris `[kode, nama, klasifikasi, saldoAwal, penambah, pengurang, saldoAkhir]` + `labaBersih` |
| `arusKas()` | `operasi, investasi, pendanaan, lainnya` (masing-masing `label/baris/total`), `kenaikanKas, saldoAwalKas, saldoAkhirKas, kenaikanMenurutSaldo, selisihRekonsiliasi, rekonsiliasiCocok, adaLainnya` |
| `perubahanDanaInvestasiTerikat()` | baris `[kunci, kode, label, akunTersedia, saldoAwal, penerimaan, penyaluran, saldoAkhir, mutasiDebet, mutasiKredit]` + `totalAwal, totalPenerimaan, totalPenyaluran, totalAkhir, mutasiSeimbang, adaAkunHilang` |
| `kepatuhan()` | `patuh, temuan, kelompokDisajikan, akunTakKlasifikasi, mutasi` |
| `deteksiArusKasTakTerlacak()` | `terdeteksi, selisih, mutasiKasBersih, labaBersih, mutasiNonKas, keterangan` |
| `semuaKomponen($periode)` | `periode, posisi, labaRugi, perubahanEkuitas, arusKas, dit, kepatuhan, kebijakan, catatan, diagnostik` |

**Prinsip yang ditegakkan:**

- **Satu sumber kebenaran.** Setiap angka berasal dari `SUM(debet)`/`SUM(kredit)`
  pada `jurnal_detail` untuk jurnal berstatus `Posted`, dibatasi `tanggal_jurnal`.
- **Presisi dua tahap.** `jumlahPresisi()` membulatkan **per akun** ke tingkat sen
  **lalu** menjumlahkan — mencegah "selisih palsu Rp 1" yang muncul bila total
  dibulatkan sekali di akhir.
- **Pengaman periode masa depan.** `periode()` menjepit acuan masa depan:
  `if ($acuan->gt(now())) $acuan = now();`
- **Akun tak terklasifikasi tidak boleh hilang.** Akun aktif tanpa klasifikasi
  tetap masuk kelompok penyajian **dan** memunculkan temuan CaLK
  (`adaTakKlasifikasi`).
- **DST dipisah di lapisan data.** `$dst = $kewajiban->where('klasifikasi_neraca', 'Dana Syirkah Temporer')`,
  sehingga `Total Pasiva = Kewajiban + DST + Ekuitas + Laba berjalan`.

### 2.3 Penyajian Halaman & Berkas Ekspor

| Lapisan | Berkas |
|---|---|
| Controller | `app/Http/Controllers/LaporanKeuanganSyariahController.php` |
| View halaman | `resources/views/laporan/psak101.blade.php` (~1063 baris, 6 bagian) |
| View ekspor | `resources/views/laporan/psak101-excel.blade.php` (HTML standalone, tanpa `@extends`) |
| Route | `laporan.psak101` → `GET /laporan/keuangan-syariah` |
| Navigasi | `NavigationService::menuManajer()`, `menuPengurus()`, `menuDPS()` |
| RBAC | `LaporanKeuanganPermission::MODUL_LAPORAN_PSAK101` |

**Pola ekspor (dipakai konsisten dengan modul Neraca):**

```php
response()->view('laporan.psak101-excel', $data, 200, [
    'Content-Type'        => 'application/vnd.ms-excel; charset=UTF-8',
    'Content-Disposition' => 'attachment; filename="LaporanKeuanganSyariah-'
                             . $periode['sampaiStr'] . '.xls"',
]);
```

Ekspor dikendalikan lewat kueri `?export=excel` pada **rute yang sama**. Ini
disengaja: middleware `LaporanKeuanganAccess::tentukanAksi()` menilai permintaan
ekspor sebagai hak **`ekspor`**, bukan `lihat` — sehingga peran yang hanya boleh
melihat (mis. DPS) tidak dapat mengekspor data. View ekspor memakai format
standalone (tanpa `@extends`) berisi `<table>` HTML yang dapat dibuka Excel,
dengan judul bagian bergaya cetak **HURUF BESAR**.

**Format rupiah:**

| Konteks | Desimal | Negatif |
|---|---|---|
| Neraca & halaman utama | 0 desimal | dalam tanda kurung |
| View dana titipan (zakat) | 2 desimal | dalam tanda kurung |

### 2.4 Penegakan RBAC Modul `laporan_psak101`

Modul didaftarkan pada matriks `LaporanKeuanganPermission` dengan kebijakan
**konservatif** (mempertahankan akses yang ada sambil menutup celah):

| Peran | lihat | unduh | edit | hapus | ekspor |
|---|---|---|---|---|---|
| admin | ✅ | ✅ | ❌ | ❌ | ✅ |
| pengurus | ✅ | ✅ | ✅ | ✅ | ✅ |
| manajer | ✅ | ✅ | ❌ | ❌ | ✅ |
| dps | ✅ | ❌ | ❌ | ❌ | ❌ |
| teller / ao / anggota | ❌ | ❌ | ❌ | ❌ | ❌ |

**Route gate:**

```php
Route::middleware(['laporan.akses:laporan_psak101', 'role:manajer,pengurus,dps'])->group(function () {
    Route::get('/laporan/keuangan-syariah', [LaporanKeuanganSyariahController::class, 'index'])
        ->name('laporan.psak101');
});
```

`laporan.akses` sengaja diletakkan **SEBELUM** `role:` — bila `role:` berjalan lebih
dulu, ia memblokir dengan 403 sebelum middleware kesempatan mencatat percobaan akses
tidak sah, sehingga upaya Teller/AO membuka laporan lolos dari audit. Urutan ini
menjamin **dua-duanya tercatat**: penolakan RBAC *dan* penolakan peran.

**Konsistensi matriks vs route gate:** matriks memberi `admin` hak `lihat`, tetapi
rute ini sengaja **tidak** menyertakan `admin` pada `role:` — sejalan dengan rute
Neraca & Buku Besar (`role:manajer,pengurus,dps`). Ini praktik sistem yang sudah
berlaku (admin tidak membuka laporan keuangan konsolidasi lewat halaman laporan),
bukan penyimpangan baru. Konstanta `PERAN_BERWENANG` pada test disetel ke
`['pengurus', 'manajer', 'dps']` agar sejalan dengan rute. Perbedaan semacam ini
dicatat sebagai bahan **audit RBAC menyeluruh Tahap 4**.

### 2.5 Log Audit Akses Laporan

Setiap akses — **berhasil maupun ditolak** — tercatat pada `LaporanKeuanganLog`:

| Aktivitas | Pemicu |
|---|---|
| `LIHAT` | pembukaan halaman biasa (`aksiDefault`) |
| `EKSPOR` | kueri `?export=`/`?unduh=` terisi, atau nama rute mengandung export/ekspor |
| `UNDUH` | nama rute mengandung download/unduh |
| `HAPUS` | metode `DELETE` |
| `EDIT` | metode `POST`/`PUT`/`PATCH` |
| `VERIFIKASI` | pemeriksaan rantai hash |

Log bersifat **append-only** dengan **rantai hash SHA-256**: hook `booted()` pada
model menolak operasi `updating`/`deleting`, dan `verifyChain()` memungkinkan
pembuktian bahwa tidak ada baris yang disisipkan/diubah/dihapus di tengah.

### 2.6 Perbaikan Cacat yang Terungkap Saat Pengujian

Menyusun test suite baru mengungkap **empat** ketidaksepakatan nyata antara
ekspektasi dan kenyataan sistem. Semuanya diverifikasi terhadap kode sumber
kebenaran sebelum diputuskan:

| # | Gejala | Akar masalah | Keputusan perbaikan |
|---|---|---|---|
| T3-B2 | Test `halaman menyajikan keenam bagian` gagal mencari `5. Perubahan Dana Investasi Terikat` | Judul aktual view adalah `5. **Laporan** Perubahan Dana Investasi Terikat` | Ekspektasi test diselaraskan ke judul view (view = sumber kebenaran tampilan) |
| T3-B3 | 4 test jurnal gagal `SQLSTATE[HY000]: 1364 Field 'baris' doesn't have a default value` | `jurnal_detail.baris` `int NOT NULL` tanpa default, sedangkan helper test tidak mengisinya | Helper `jurnalKas()` & blok jurnal dana kebajikan mengisi `baris` 1..2 |
| T3-B4 | Test `peran berwenang dapat membuka halaman` gagal `403 is not identical to 200` | `PERAN_BERWENANG` test memuat `admin`, padahal rute memakai `role:manajer,pengurus,dps` | Konstanta diselaraskan ke `['pengurus','manajer','dps']` + dokumentasi alasan |
| T3-B5 | Test `ekspor excel memuat seluruh komponen` gagal mencari `Laporan Posisi Keuangan` | Berkas ekspor memakai judul **HURUF BESAR** (`LAPORAN POSISI KEUANGAN`) bergaya cetak | Perbandingan test dinormalkan `mb_strtolower()` — menguji keberadaan komponen, bukan gaya huruf |

> **Prinsip penyelesaian:** setiap kegagalan diuji lebih dulu apakah ia cacat *kode*
> atau cacat *ekspektasi*. Empat kasus di atas **seluruhnya cacat ekspektasi**
> (kecuali T3-B3 yang merupakan kelengkapan kontrak data). Tidak ada kode produksi
> yang diubah untuk "memaksa" test hijau — memaksa test hijau dengan melonggarkan
> kode produksi akan menghapus nilai pembuktiannya.

---

## 3. Hasil Pengujian

### 3.1 Test suite baru

| Berkas | Jumlah test | Status |
|---|---|---|
| `tests/Feature/LaporanPsak101Test.php` | 28 | ✅ **28 passed (91 assertions)** |

**Cakupan per kelompok:**

| Kelompok | Test | Yang dibuktikan |
|---|---|---|
| A — Kelengkapan penyajian | 3 | keenam bagian tampil; service menyediakan seluruh kunci; periode = setahun berjalan |
| B — Rekonsiliasi antar-komponen | 6 | laba bersih identik 3 tempat; Total Aset = Total Pasiva; Total Pasiva = Kewajiban+DST+Ekuitas+Laba; arus kas rekonsiliasi saldo kas; DIT mutasi seimbang; ekuitas saldo akhir konsisten |
| C — Identitas arus kas tahan data nyata | 4 | jurnal kas masuk menggeser arus kas sebesar nominalnya; jurnal kas keluar menggeser negatif; selisih rekonsiliasi tidak berubah oleh jurnal seimbang; diagnostik terbaca |
| D — Kemurnian PSAK 109 | 2 | akun dana titipan tidak pernah masuk Pendapatan; mutasi dana kebajikan tidak mengubah total pendapatan |
| E — RBAC & audit | 7 | modul terdaftar di matriks; peran tak berwenang 403; peran berwenang 200; akses ditolak tetap tercatat; akses sah tercatat `LIHAT`; tombol ekspor hanya bagi yang berhak; pengunjung diarahkan ke login |
| F — Ekspor Excel | 3 | terkirim sebagai attachment; memuat keenam komponen; ditolak bagi peran tanpa hak ekspor |
| G — Periode | 2 | tanggal masa depan dijepit ke hari ini; tanggal tidak valid jatuh ke hari ini |
| H — Akun tak terklasifikasi | 1 | tetap disajikan di Neraca + memunculkan temuan CaLK |

**Teknik pengujian:** `DatabaseTransactions` + prefix `uniqid()` + pola **DELTA
(selisih)**. Nilai absolut sengaja **tidak** dijadikan patokan karena basis data dev
juga berisi data nyata; seluruh pembuktian rekonsiliasi diukur sebagai **selisih**,
sehingga deterministik dan tidak rapuh terhadap data lingkungan.

### 3.2 Suite regresi penuh

| Metrik | Baseline (akhir Tahap 2) | Setelah Tahap 3 | Delta |
|---|---|---|---|
| Test passed | 826 | **854** | **+28** |
| Assertions | 4.015 | **4.114** | **+99** |
| Test gagal | 0 | **0** | 0 |
| Durasi | 92,27 s | 115,64 s | +23,37 s |

**Perintah:** `php artisan test`

```
Tests:    854 passed (4114 assertions)
Duration: 115.64s
```

Tidak ada regresi pada suite RBAC & audit yang paling rentan terdampak
(`LaporanKeuanganRbacTest`, `MatriksHakAksesAuditTest`, `BukuBesarAkunTest`,
`NeracaIkhtisarShuPerbaikanTest`) — seluruhnya tetap hijau.

---

## 4. Validasi Menyeluruh

| # | Butir validasi | Cara validasi | Hasil |
|---|---|---|---|
| V3.1 | Route laporan terdaftar tepat satu | `php artisan route:list --name=psak101` | `GET\|HEAD laporan/keuangan-syariah → laporan.psak101 › LaporanKeuanganSyariahController@index` (1 route) |
| V3.2 | Syntax seluruh berkas tersentuh bersih | `php -l` pada controller, service, permission, navigation, audit controller, routes | Bersih (6/6) |
| V3.3 | Keenam judul bagian benar-benar ada di view | Grep `judul-bagian` pada `psak101.blade.php` | 6 baris: Laporan Posisi Keuangan, Laba Rugi, Perubahan Ekuitas, Arus Kas, Perubahan DIT, CaLK |
| V3.4 | Keenam komponen benar-benar ada di berkas ekspor | Grep judul pada `psak101-excel.blade.php` | 6 blok judul (HURUF BESAR) |
| V3.5 | Neraca seimbang | Test `test_total_aset_sama_dengan_total_pasiva` | Lulus |
| V3.6 | Laba bersih konsisten lintas komponen | Test `test_laba_bersih_identik_di_neraca_laba_rugi_dan_perubahan_ekuitas` | Lulus |
| V3.7 | Arus kas merekonsiliasi saldo kas | Test `test_arus_kas_rekonsiliasi_dengan_perubahan_saldo_kas` | Lulus |
| V3.8 | Identitas arus kas tahan jurnal nyata (delta) | 3 test jurnal kas | Lulus — penetapan jurnal menggeser arus kas persis sebesar nominalnya |
| V3.9 | Kemurnian PSAK 109 pada Laba Rugi | 2 test kelompok D | Lulus — 0 kebocoran dana titipan ke Pendapatan |
| V3.10 | Akun tak terklasifikasi tidak hilang | Test kelompok H | Lulus — disajikan + jadi temuan |
| V3.11 | Ambang selisih memakai toleransi kebijakan | Test `test_diagnostik_arus_kas...` vs `Setting::toleransiSelisihJurnal()` | Lulus — tidak ada hardcode `0.01` |
| V3.12 | RBAC ditegakkan & terekam | 7 test kelompok E | Lulus — termasuk penolakan tetap tercatat di log audit |
| V3.13 | Nama kolom log audit benar | Model `LaporanKeuanganLog` | `role` & `aktivitas` (bukan `user_peran`/`aksi`) |
| V3.14 | Ekspor tunduk RBAC | Test `test_ekspor_ditolak_bagi_peran_tanpa_hak_ekspor` | Lulus |
| V3.15 | Kontrak data `jurnal_detail` dihormati | `php artisan db:table jurnal_detail` | `baris int` non-null → dipenuhi pada semua insert uji |
| V3.16 | Regresi nol | `php artisan test` | **854 passed / 4114 assertions, 0 failed** |

---

## 5. Berkas yang Dibuat / Diubah

### Dibuat

| Berkas | Keterangan |
|---|---|
| `app/Services/LaporanKeuanganSyariahService.php` | Agregator laporan PSAK 101 — satu-satunya sumber perhitungan angka laporan |
| `app/Http/Controllers/LaporanKeuanganSyariahController.php` | Halaman + jalur ekspor Excel |
| `resources/views/laporan/psak101.blade.php` | Halaman 6 bagian PSAK 101 + CaLK |
| `resources/views/laporan/psak101-excel.blade.php` | Berkas ekspor Excel (HTML standalone) |
| `tests/Feature/LaporanPsak101Test.php` | 28 test Tahap 3 (91 assertions) |
| `docs/TAHAP3-PELAPORAN.md` | Dokumen ini |

### Diubah

| Berkas | Perubahan |
|---|---|
| `app/Support/LaporanKeuanganPermission.php` | `MODUL_LAPORAN_PSAK101` + label + entri matriks admin/pengurus/manajer/dps |
| `routes/web.php` | Route `laporan.psak101` dengan gate `laporan.akses:laporan_psak101` sebelum `role:` |
| `app/Services/NavigationService.php` | Entri menu **"Laporan Keuangan Syariah (PSAK 101)"** (`bi-file-earmark-bar-graph`) pada menu Manajer, Pengurus, dan DPS |
| `resources/views/laporan/audit.blade.php` | Kolom matriks untuk modul `laporan_psak101` |
| `app/Http/Controllers/AuditLaporanKeuanganController.php` | `payloadMatriks()` mencakup modul baru |

---

## 6. Risiko Sisa & Serah Terima ke Tahap Berikut

| # | Risiko / temuan sisa | Dampak | Ditangani di |
|---|---|---|---|
| R3.1 | Belum ada guard maker-checker (self-approval) | Risiko integritas persetujuan | **Tahap 4** |
| R3.2 | LTKM/LTKT & penapisan sanksi belum ada | Kepatuhan APU-PPT OJK | **Tahap 4** |
| R3.3 | Matriks RBAC vs route gate belum diaudit menyeluruh (ditemukan beda perlakuan `admin` pada matriks vs rute; pola sama di Neraca/Buku Besar/PSAK 101) | Rute berpotensi menyajikan wewenang yang tidak dapat dilaksanakan, atau sebaliknya | **Tahap 4** |
| R3.4 | Hardening sesi/login belum menyeluruh | Risiko keamanan akun | **Tahap 4** |
| R3.5 | `jurnal_detail.baris` `NOT NULL` tanpa default — mudah menimbulkan kegagalan pada insert manual (terungkap sebagai T3-B3) | Integritas & kemudahan pemeliharaan data | **Tahap 5** |
| R3.6 | `DanaCadanganController` masih menampung logika Neraca; belum memakai agregator bersama | Duplikasi perhitungan antar-halaman | **Tahap 5** |
| R3.7 | Pembulatan ke rupiah penuh memunculkan sisa ≤ Rp 1 pada target CKPN (R2.5) | Kosmetik; dalam toleransi | **Tahap 5** |

---

## 7. Pernyataan Penyelesaian

Seluruh **12 temuan Klaster B** telah ditutup, termasuk risiko sisa R2.1 dari Tahap 2
(*"5 dari 8 komponen laporan PSAK 101 belum ada"*) yang kini **tuntas**: keenam
komponen PSAK 101 tersedia dalam satu halaman yang saling merekonsiliasi.

Kriteria keberhasilan **K3.1–K3.12 terpenuhi seluruhnya**. Validasi **V3.1–V3.16**
lulus tanpa pengecualian. Suite regresi tidak mengalami penurunan:
**854 passed / 4114 assertions, 0 gagal** (+28 test, +99 assertion dibanding Tahap 2).

Tahap 3 dinyatakan **SELESAI** dan siap menjadi fondasi Tahap 4 (GCG/OJK).
