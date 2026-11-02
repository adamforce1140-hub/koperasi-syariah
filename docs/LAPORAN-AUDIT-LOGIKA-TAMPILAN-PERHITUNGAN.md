# LAPORAN AUDIT LOGIKA, TAMPILAN, DAN PERHITUNGAN
## Sistem Koperasi Syariah — `koperasi_syariah`

| | |
|---|---|
| Tanggal audit | 21 Oktober 2026 |
| Lingkup | Logika bisnis, tampilan (Blade/UI), perhitungan akuntansi & saldo |
| Metode | Pembacaan kode statis + eksekusi skrip verifikasi *read-only* terhadap database `koperasi_syariah` |
| Sifat | Audit awal **READ-ONLY**; perbaikan diterapkan bertahap setelah keputusan Anda (lihat status tiap temuan) |
| Basis data | 13 anggota · 57 transaksi · 68 jurnal · 152 jurnal detail · 25 rekening · 5 pembiayaan |

> **Catatan penting:** seluruh temuan di bawah diverifikasi dengan angka nyata dari database Anda, bukan asumsi. Setiap temuan menyertakan file + baris, kutipan kode, dan bukti numerik. Status perbaikan ditandai pada masing-masing temuan; **D-1** (jurnal penyesuaian) dan **D-2** (reklasifikasi subledger) telah diputuskan untuk **dibiarkan apa adanya** (data demo tidak diubah).

---

## 1. Ringkasan Eksekutif

| No | Temuan | Tingkat | Jenis | Dampak langsung ke pengguna |
|---|---|---|---|---|
| **K-1** | Ability `role` tidak pernah terdaftar → form pengajuan pembiayaan **tidak pernah tampil** | **KRITIS** | Logika + Tampilan | AO & Manajer tidak bisa mengajukan pembiayaan lewat UI |
| **K-2** | Laporan Laba Rugi **impas semu** (0) padahal rugi Rp1.560.833 | **TINGGI** | Perhitungan | L/R, Perubahan Ekuitas, dan PSAK 101 menampilkan laba 0 |
| **S-1** | `TazirService::detail()` mengirim hari terlambat walau fitur ta'zir nonaktif | **SEDANG** | Logika + API | Belum bocor ke UI, tetapi kontrak data tidak konsisten |
| **S-2** | Dua konvensi `posisi` (Debet/Kredit) bercampur → **54 dari 57** `saldo_setelah` salah | **SEDANG** | Perhitungan + Tampilan | Saldo berjalan di mutasi/slip/riwayat salah |
| **S-3** | Saldo tanpa jejak mutasi: 9 dari 25 rekening (Rp21.200.000 saldo awal legacy) + atribusi `jurnal_detail.rekening_id` salah | **SEDANG** | Perhitungan | **Sudah diperbaiki/didokumentasikan** — lihat §4 S-3 |
| **S-4** | 2 transaksi `Sukses` tampak tanpa jurnal (sebenarnya tertutup jurnal bundling) | **RENDAH** | Perhitungan | **Sudah diperbaiki** — lihat §4 S-4 |
| **S-5** | `saldoAwalPeriode()` dan `registriPeriode()` memakai basis saldo berbeda | **SEDANG** | Perhitungan | **Sudah diperbaiki** — saldo awal + mutasi kini konsisten dengan basis laporan |
| **S-6** | Jurnal bundling tidak selalu menerima `rekeningWajibId` | **RENDAH** | Logika data | **Sudah diperbaiki** — atribusi baris Wajib ke rekening anggota |
| **S-7** | 5 test penahanan SHU ter-skip karena saldo `3-9000` negatif di DB dev | **RENDAH** | Test | **Sudah diperbaiki** — 0 skip |
| **S-8** | Selisih GL vs subledger simpanan masih ada (data seed) | **RENDAH** | Data | **Teridentifikasi** — menunggu D-1/D-2, tidak dieksekusi |
| **R-1** | `Penarikan Tunai` di data seed bertanda `Debet` (harusnya `Kredit`) | **RENDAH** | Logika data | Arah mutasi penarikan terbalik pada data demo |
| **S-9** | 6 rute menunjuk method controller yang tidak ada (route target rusak) | **TINGGI** | Logika | **Sudah diperbaiki** — lihat §4 S-9 |
| **S-10** | 12 area logika belum dinamis (nominal, peran, akun CoA, pecahan kas) | **TINGGI** | Logika | **Sudah diperbaiki** — lihat §4 S-10 |
| **S-11** | Pengajuan dari kanal nasabah menyimpan margin/angsuran **NULL** & plafon produk tidak divalidasi | **TINGGI** | Perhitungan | **Sudah diperbaiki** — lihat §4 S-11 |
| **S-12** | Rentang tahun & default kebijakan masih asumsi tahun takwim / duplikasi | **SEDANG** | Perhitungan | **Sudah diperbaiki** — lihat §4 S-12 |
| **S-13** | Dua limit pembiayaan Manajer ditimpa satu nilai, fallback salah sumber; kop nota struk literal; periode SHU memakai tahun takwim | **SEDANG** | Perhitungan + Tampilan | **Sudah diperbaiki** — lihat §4 S-13 |
| **S-14** | Awalan nomor dokumen (TRX/RKN/JRN/BBK/TRM/DEP) ditulis keras di kode | **SEDANG** | Logika | **Sudah diperbaiki** — lihat §4 S-14 |
| **S-15** | Hari libur & kalender hanya ada di basis data tanpa UI; tidak dapat dipelihara Pengurus | **SEDANG** | Tampilan + Logika | **Sudah diperbaiki** — lihat §4 S-15 |
| **S-16** | Retensi cadangan basis data ditulis keras (`--simpan-hari=14`) di jadwal | **SEDANG** | Logika | **Sudah diperbaiki** — lihat §4 S-16 |
| **S-17** | Menu "Hak Akses" hanya berisi daftar user; matriks izin tak terlihat dan override limit tanpa CRUD | **SEDANG** | Tampilan + Logika | **Sudah diperbaiki** — lihat §4 S-17 |
| **S-18** | Jalur CoA `AutoJournalingService` tidak konsisten: pencairan dinamis, angsuran/reversal statis | **SEDANG** | Logika | **Sudah diperbaiki** — lihat §4 S-18 |

**Yang justru sehat:** neraca seimbang, buku besar seimbang, seluruh 68 header jurnal cocok dengan detailnya, 5 pembiayaan konsisten, dan regresi penuh **1858 passed / 8813 assertions (0 skipped)**. Lihat §5.

---

## 2. K-1 — KRITIS: Form Pengajuan Pembiayaan Tidak Pernah Dirender

### Lokasi
[index.blade.php](file:///c:/laragon/www/koperasi_syariah/resources/views/pengajuan-pembiayaan/index.blade.php#L48)

```blade
{{-- FORM PENGAJUAN (AO) --}}
@can('role', ['ao','manajer'])
<div class="data-card">
    ...
    <form method="POST" action="{{ route('pengajuan-pembiayaan.store') }}" id="formPengajuan">
```

### Bukti
1. **Tidak ada satu pun `Gate::define` di seluruh project.** Pencarian `Gate::(define|before|after)|->define\(` pada direktori `app/` → **0 hasil**. Satu-satunya kemunculan `Gate::` di luar vendor adalah alias di `config/app.php:129`.
2. [AuthServiceProvider.php](file:///c:/laragon/www/koperasi_syariah/app/Providers/AuthServiceProvider.php#L9-L20) hanya mendaftarkan policy:

```php
protected $policies = [
    \App\Models\User::class => \App\Policies\TellerPolicy::class,
];
public function boot(): void { $this->registerPolicies(); }
```

3. [TellerPolicy.php](file:///c:/laragon/www/koperasi_syariah/app/Policies/TellerPolicy.php) hanya memiliki `transaksi()`, `bukaKas()`, `tutupKas()`. **Tidak ada method `role()`.**
4. **Bukti runtime** — `Gate::forUser($user)->check('role', ['ao','manajer'])`:

```
ao           role(ao,manajer)=false  transaksi=false
manajer      role(ao,manajer)=false  transaksi=false
teller       role(ao,manajer)=false  transaksi=TRUE
pengurus     role(ao,manajer)=false  transaksi=false
dps          role(ao,manajer)=false  transaksi=false
super_admin  role(ao,manajer)=false  transaksi=false
abilities terdaftar: (kosong)
```

### Dampak
- `@can('role', ...)` **selalu `false`** untuk semua peran, termasuk `ao` dan `manajer`.
- Akibatnya seluruh blok form pengajuan pembiayaan **tidak pernah dirender**. Halaman `pengajuan-pembiayaan.index` hanya menampilkan tabel daftar pengajuan tanpa form input.
- Route `POST /pengajuan-pembiayaan` tetap aktif di backend, tetapi tidak ada jalur UI untuk memakainya.
- Ini juga menjelaskan mengapa data `pembiayaan` hanya 5 baris: pengajuan tidak pernah bisa dibuat dari antarmuka.

### Perbandingan (untuk memastikan bukan salah baca)
`@can('transaksi', \App\Models\User::class)` dan `@can('tutupKas', ...)` di view lain **VALID** karena method-nya ada di `TellerPolicy` — terkonfirmasi runtime `teller transaksi=TRUE`. Jadi masalahnya spesifik pada ability `role` yang tidak pernah didefinisikan.

### Opsi perbaikan (dipilih: A)
- **A.** Tambahkan `Gate::define('role', fn (User $u, string ...$roles) => in_array($u->role, $roles, true));` di `AuthServiceProvider::boot()`.
- **B.** Ganti `@can('role', ['ao','manajer'])` menjadi `@if(auth()->user()->isAO() || auth()->user()->isManajer() || auth()->user()->isSuperAdmin())`.
- **C.** Pindahkan otorisasi ke `TellerPolicy::role()` agar konsisten dengan pola policy yang sudah ada.

> Rekomendasi: **A**, karena hanya satu titik perubahan dan langsung memperbaiki semua pemakaian `@can('role', ...)` di masa depan. Perlu juga ditambahkan test regresi yang memastikan form tampil untuk AO/Manajer dan tidak tampil untuk Teller.

### Status: SUDAH DIPERBAIKI (opsi A)
- [AuthServiceProvider.php](file:///c:/laragon/www/koperasi_syariah/app/Providers/AuthServiceProvider.php) — `Gate::define('role', ...)` ditambahkan di `boot()`; menerima string tunggal maupun array (flatten + filter).
- Test regresi: [AbilityRoleGateTest.php](file:///c:/laragon/www/koperasi_syariah/tests/Feature/AbilityRoleGateTest.php) — **6 passed / 18 assertions**.

---

## 3. K-2 — TINGGI: Laporan Laba Rugi "Impas Semu"

### Lokasi
[Coa.php](file:///c:/laragon/www/koperasi_syariah/app/Models/Coa.php#L196-L224) dan [LaporanKeuanganSyariahService.php](file:///c:/laragon/www/koperasi_syariah/app/Services/LaporanKeuanganSyariahService.php#L455-L484)

```php
// Coa::totalDebet() — hanya memfilter status, TIDAK memfilter jenis_jurnal
return (float) $this->jurnalDetail()
    ->whereHas('jurnal', function ($q) use ($sampai) {
        $q->where('status', 'Posted');
        if ($sampai !== null) {
            $q->where('tanggal_jurnal', '<=', $sampai);
        }
    })
    ->sum('debet');
```

```php
// LaporanKeuanganSyariahService::labaRugi()
$totalPendapatan = Setting::bulatkanUang($totalPendapatanUsaha + $totalPendapatanLain + $totalPendapatanTakKlas);
$totalBeban      = Setting::bulatkanUang($totalBebanUsaha + $totalBebanLain + $totalBebanTakKlas);
$labaSebelumPajak = Setting::bulatkanUang($totalPendapatan - $totalBeban);
```

### Bukti
**Output layanan saat ini:**

```
periode dari=2026-01-01 sampai=2026-10-31
totalPendapatan = 0,00
totalBeban      = 0,00
labaBersih      = 0,00   status = impas
```

**Mutasi riil bila jurnal penutup dikecualikan:**

| Kode | Nama Akun | Mutasi |
|---|---|---:|
| 4-1001 | Pendapatan Margin Murabahah | 100.000,00 |
| 4-1002 | Pendapatan Bagi Hasil Mudharabah | 62.500,00 |
| 4-1003 | Pendapatan Bagi Hasil Musyarakah | 66.667,00 |
| 4-1004 | Pendapatan Sewa Ijarah | 60.000,00 |
| 4-2001 | Pendapatan Biaya Administrasi | 150.000,00 |
| | **Total Pendapatan** | **439.167,00** |
| 5-1001 | Beban Operasional Koperasi | 1.200.000,00 |
| 5-1002 | Beban ATK & Perlengkapan | 300.000,00 |
| 5-1003 | Beban Utilitas / Listrik | 500.000,00 |
| | **Total Beban** | **2.000.000,00** |
| | **LABA (RUGI) SEBENARNYA** | **−1.560.833,00** |

**Penyebab:** jurnal `TUTUPBUKU-20261015-0028` (2026-10-15, `Posted`, `jenis_jurnal = Jurnal Penutup`, D=K=Rp2.000.000) mendebit seluruh akun pendapatan dan mengkredit seluruh akun beban. Karena `Coa::totalDebet()/totalKredit()` **tidak mengecualikan `jenis_jurnal = 'Jurnal Penutup'`**, saldo seluruh akun 4-xxxx dan 5-xxxx menjadi nol.

### Dampak berantai
1. **Laporan Laba Rugi** menampilkan pendapatan 0, beban 0, laba 0, status `impas`.
2. **Laporan Perubahan Ekuitas** memakai `$laba = $this->labaRugi($periode)['labaBersih']` → laba 0.
3. **Laporan PSAK 101** memakai `'labaRugi' => $this->labaRugi($periode)` → laba 0.
4. **Laporan Ikhtisar SHU** berpotensi menghitung ulang pembagian SHU dari angka 0 bila memakai service yang sama.
5. Angka **Ekuitas / Saldo Laba / SHU** di CoA saat ini **−1.560.833,00** — konsisten dengan laba sebenarnya, tetapi tidak pernah terhubung kembali ke laporan L/R.

### Catatan penting
Neraca **tetap seimbang** (Aset = Pasiva = Rp104.839.167). Jadi ini **bukan** masalah keseimbangan, melainkan **laporan laba rugi yang menyesatkan**. Jangan salah menyimpulkan bahwa neraca rusak.

### Opsi perbaikan (dipilih: gabungan A + B)
- **A (disarankan).** Tambahkan pengecualian `jenis_jurnal != 'Jurnal Penutup'` pada `Coa::totalDebet()`/`totalKredit()`, lalu sajikan laba berjalan dari mutasi non-penutup. Dampak: L/R kembali menampilkan 439.167 / 2.000.000 / −1.560.833.
- **B.** Tambahkan parameter opsional `$kecualiPenutup = true` agar pemakaian lain (mis. buku besar) tetap bisa melihat seluruh mutasi.
- **C.** Sajikan L/R per periode **sebelum** tanggal tutup buku dan hentikan penutupan otomatis pada periode berjalan.

> Perbaikan ini mengubah **output laporan keuangan**, sehingga dieksekusi hanya setelah persetujuan Anda.

### Status: SUDAH DIPERBAIKI (gabungan opsi A + B)
- [Coa.php](file:///c:/laragon/www/koperasi_syariah/app/Models/Coa.php) — `totalDebet()`/`totalKredit()` menerima parameter opsional `$kecualiJenis`; method baru `saldoBerjalanLaporan()` mengecualikan `Jurnal Penutup` pada **kedua sisi** (Pendapatan/Beban kembali riil, 3-9000 tidak dihitung ganda). `labaBersihBerjalan()`/`statusKeuangan()` ikut basis laporan. `saldoBerjalan()` **tidak diubah** → Buku Besar & verifikasi tutup buku tetap apa adanya.
- [LaporanKeuanganSyariahService.php](file:///c:/laragon/www/koperasi_syariah/app/Services/LaporanKeuanganSyariahService.php) — `jumlahPresisi()`, `barisAkun()`, `mutasiAkun()`, `perubahanEkuitas()` memakai basis laporan; `deteksiArusKasTakTerlacak()` sengaja memakai basis buku (`$kecualikanPenutup = false`).
- View neraca / PSAK 101 / ekspor Excel: baris Pendapatan, Beban, Ekuitas memakai `saldoBerjalanLaporan()`.
- Test regresi: [LaporanLabaRugiJurnalPenutupTest.php](file:///c:/laragon/www/koperasi_syariah/tests/Feature/LaporanLabaRugiJurnalPenutupTest.php) — **1 passed / 19 assertions**.

**Hasil verifikasi nyata (2026-10-31):**

| Angka | Sebelum | Sesudah |
|---|---:|---:|
| Total Pendapatan | 0,00 | **439.167,00** |
| Total Beban | 0,00 | **2.000.000,00** |
| Laba Bersih | 0,00 (impas) | **−1.560.833,00** |
| Total Aset = Pasiva | 104.839.167 | 104.839.167 |
| Selisih Neraca | 0,00 | **0,00** |
| Laba di Neraca / L/R / Perubahan Ekuitas / `Coa::labaBersihBerjalan()` | tidak konsisten | **identik −1.560.833,00** |

---

## 4. Temuan Tingkat SEDANG

### S-1 — `TazirService::detail()` Tidak Menggate Hari Terlambat

**Lokasi:** [TazirService.php](file:///c:/laragon/www/koperasi_syariah/app/Services/TazirService.php#L103-L143)

```php
public function detail(Angsuran $angsuran): array
{
    $nominal = $this->calculate($angsuran);   // ← 0 bila fitur nonaktif
    $hariTerlambat = 0;
    if ($angsuran->tanggal_jatuh_tempo && !$angsuran->tanggal_bayar) {
        ...
        if ($today->gt($jatuhTempo)) $hariTerlambat = (int) $jatuhTempo->diffInDays($today);
    }
    ...
    return [
        'nominal'        => $nominal,
        'hari_terlambat' => $hariTerlambat,   // ← TIDAK digate fiturAktif()
        'hari_efektif'   => max(0, $hariTerlambat - $gracePeriod),
        ...
    ];
}
```

**Bukti runtime** (`tazir_mode=FLAT_HARIAN`, `financing_late_fee_per_day=0.00`, `fiturAktif=false`):

```
angsuran#2 jatuh=2026-07-15 calc=0 nominal=0 terlambat=98 efektif=98 mode=FLAT_HARIAN
angsuran#3 jatuh=2026-08-15 calc=0 nominal=0 terlambat=67 efektif=67 mode=FLAT_HARIAN
angsuran#4 jatuh=2026-09-15 calc=0 nominal=0 terlambat=36 efektif=36 mode=FLAT_HARIAN
```

**Dampak UI:** pada [angsuran.blade.php](file:///c:/laragon/www/koperasi_syariah/resources/views/teller/angsuran.blade.php#L505-L526), cabang tampilan memakai `if (nominal > 0)`, sehingga box ta'zir **disembunyikan** dan tidak ada angka yang bocor ke pengguna. Namun:
- `else if (data.tazir.mode === 'DISABLED')` adalah **dead code** pada kondisi ini, karena `tazir_mode` = `FLAT_HARIAN`, bukan `DISABLED`.
- Kontrak JSON `detailAngsuran` tetap mengirim `hari_terlambat` dan `hari_efektif` yang menyesatkan ke konsumen API mana pun.

**Risiko lanjutan:** bila `financing_late_fee_per_day` diubah > 0 sementara `tazir_rate` masih 2000, perilaku `fiturAktif()` dan `detail()` dapat tidak sinkron. Perlu disatukan.

**Status: SUDAH DIPERBAIKI.** [TazirService.php](file:///c:/laragon/www/koperasi_syariah/app/Services/TazirService.php) — `detail()` kini menggate `hari_terlambat`/`hari_efektif` dengan `fiturAktif()` dan melaporkan `mode = 'DISABLED'` bila fitur nonaktif, sehingga cabang UI "tidak ada denda keterlambatan" aktif dan kontrak JSON tidak membocorkan angka menyesatkan. Test regresi: [TazirDetailKontrakTest.php](file:///c:/laragon/www/koperasi_syariah/tests/Feature/TazirDetailKontrakTest.php) — **4 passed / 18 assertions**.

---

### S-2 — Dua Konvensi `posisi` Bercampur → 54 dari 57 `saldo_setelah` Salah

**Bukti rekap `posisi` × `jenis_transaksi` (hanya transaksi `Sukses`):**

| Jenis Transaksi | Posisi | Jumlah | Total |
|---|---|---:|---:|
| Setoran Tunai | **Kredit** | 6 | 19.500.000 |
| Simpanan Pokok | **Kredit** | 5 | 2.500.000 |
| Simpanan Wajib | **Kredit** | 5 | 500.000 |
| Setoran Simpanan Pokok | Debet | 2 | 200.000 |
| Setoran Simpanan Wajib | Debet | 11 | 550.000 |
| Setoran Simpanan Sukarela | Debet | 2 | 7.000.000 |
| Setoran Simpanan Berjangka | Debet | 2 | 15.000.000 |
| Setoran Awal Anggota | Debet | 1 | 150.000 |
| Auto-Debit Simpanan Wajib | Debet | 9 | 450.000 |
| **Penarikan Tunai** | **Debet** | 2 | 800.000 |
| Bayar Angsuran | Kredit | 5 | 3.747.500 |
| Pencairan Pembiayaan | Debet | 5 | 56.000.000 |
| Biaya Administrasi | Kredit | 1 | 150.000 |
| Ta'zir | Debet | 1 | 50.000 |

Terlihat **dua konvensi hidup berdampingan**:
- `TellerController@setorStore` menulis setoran sebagai **`Debet`** (uang masuk) — [TellerController.php:140](file:///c:/laragon/www/koperasi_syariah/app/Http/Controllers/TellerController.php#L140).
- `DemoDataSeeder` menulis setoran sebagai **`Kredit`** (`Setoran Tunai`, `Simpanan Pokok`, `Simpanan Wajib`).
- `Penarikan Tunai` justru ditulis **`Debet`** (arah terbalik; seharusnya `Kredit`).

**Konsistensi `saldo_setelah`:** dengan asumsi `Debet = masuk`, **54 dari 57** transaksi memiliki `saldo_setelah` yang tidak sama dengan `saldo_sebelum ± jumlah`. Bahkan setelah arah dinormalisasi berdasarkan makna `jenis_transaksi`, masih **53 dari 57** yang tidak konsisten — artinya nilai `saldo_setelah` pada data seed **tidak diturunkan dari rantai transaksi**, melainkan diisi terpisah.

**Dampak tampilan (nyata, bukan teoretis):** `saldo_setelah` ditampilkan langsung di:
- [nasabah/mutasi.blade.php:157](file:///c:/laragon/www/koperasi_syariah/resources/views/nasabah/mutasi.blade.php#L157)
- [ao/nasabah/show.blade.php:129](file:///c:/laragon/www/koperasi_syariah/resources/views/ao/nasabah/show.blade.php#L129)
- [teller/slip.blade.php:156](file:///c:/laragon/www/koperasi_syariah/resources/views/teller/slip.blade.php#L156)
- [teller/anggota/show.blade.php:315](file:///c:/laragon/www/koperasi_syariah/resources/views/teller/anggota/show.blade.php#L315)
- [manajer/kas.blade.php:149](file:///c:/laragon/www/koperasi_syariah/resources/views/manajer/kas.blade.php#L149)
- [deposito-show.blade.php:216](file:///c:/laragon/www/koperasi_syariah/resources/views/deposito-show.blade.php#L216)

Jadi anggota/nasabah melihat **saldo berjalan yang salah** pada riwayat mutasi dan slip transaksi.

**Catatan:** `Transaksi::isMasuk()` sudah diperbaiki agar memprioritaskan `posisi`. Saat audit, method ini **hanya dipakai di test** dan data lama belum dimigrasi; keduanya sudah dituntaskan pada S-2b (migrasi data) dan S-2c (tampilan).

**Status: SUDAH DIPERBAIKI.** Empat langkah:
1. **S-2a — seeder.** `DemoDataSeeder` (6 titik), `DepositoProductSeeder` (2), `AdamRenataSeeder` (2) kini menulis `posisi` sesuai konvensi kas (`Debet` = uang masuk, `Kredit` = uang keluar) dan mengisi `saldo_setelah` dari saldo rekening yang sudah dimutasi, bukan angka lepas.
2. **S-2b — migrasi data.** [2026_10_21_000100_koreksi_posisi_dan_saldo_setelah_transaksi.php](file:///c:/laragon/www/koperasi_syariah/database/migrations/2026_10_21_000100_koreksi_posisi_dan_saldo_setelah_transaksi.php) menyelaraskan `posisi` dengan arah mutasi pada jurnal, melepaskan angsuran tunai dari rekening simpanan, lalu menghitung ulang `saldo_setelah` per rekening secara kronologis hingga berakhir tepat di `rekening.saldo`. Saldo rekening dan jurnal **tidak diubah**; tersedia `down()` untuk rollback.
3. **S-2c — tampilan.** Seluruh view kini memakai `Transaksi::isMasuk()`/`isKeluar()` sebagai satu sumber kebenaran. Heuristik `str_contains` (AUTODEBET/PENARIKAN/PEMOTONGAN/SETORAN) di [teller/riwayat.blade.php](file:///c:/laragon/www/koperasi_syariah/resources/views/teller/riwayat.blade.php), [dashboard/partials/teller-transaksi.blade.php](file:///c:/laragon/www/koperasi_syariah/resources/views/dashboard/partials/teller-transaksi.blade.php), dan [teller/anggota/show.blade.php](file:///c:/laragon/www/koperasi_syariah/resources/views/teller/anggota/show.blade.php) dihapus. Pengecualian: [manajer/laporan.blade.php](file:///c:/laragon/www/koperasi_syariah/resources/views/manajer/laporan.blade.php#L105) tetap membandingkan `posisi` langsung karena barisnya adalah hasil agregat `groupBy`, bukan model `Transaksi`.
4. **S-2d — verifikasi.** Pemeriksaan data: `posisi` menyimpang 0 dari 49 transaksi ber-jurnal simpanan, rantai `saldo_setelah` putus 0, dan setiap rekening berakhir tepat di `rekening.saldo`. Test: `DebitKreditKonsistensiTest` **8 passed / 23 assertions**; regresi penuh **1812 passed / 8560 assertions** (0 skipped).

**Residu auto-debit: SUDAH DIPERBAIKI.** 14 transaksi auto-debit (`TRX-AUTODEBIT-SW-5-2026-02..10` dan `TRX-20261015-000001..000005`) memindahkan dana antar akun simpanan (Sukarela → Wajib), sehingga jurnalnya net nol pada akun simpanan dan migrasi S-2b melewatinya. Perbaikan menyusul lewat [2026_10_21_000200_koreksi_posisi_autodebit_simpanan_wajib.php](file:///c:/laragon/www/koperasi_syariah/database/migrations/2026_10_21_000200_koreksi_posisi_autodebit_simpanan_wajib.php): `posisi` ke-14 transaksi diubah menjadi `Kredit` (dana keluar dari rekening Sukarela) dan `saldo_setelah` dihitung ulang. Nominal dan jurnal tidak berubah. Verifikasi: `posisi` menyimpang **0** dari seluruh transaksi ber-jurnal simpanan, dan rantai `saldo_setelah` setiap rekening tetap berakhir tepat di `rekening.saldo`.


---

### S-3 — 9 dari 25 Rekening: Saldo Tanpa Jejak Mutasi (Rp21.200.000)

**Status: SUDAH DIPERBAIKI / DIDOKUMENTASIKAN (3 kategori).**

Angka awal temuan (**16 rekening, Rp66.847.500**) diukur **sebelum** S-2b. Setelah `posisi` dan `saldo_setelah` dikoreksi, audit ulang memecah selisih menjadi tiga kategori yang berbeda sifatnya:

| # | Kategori | Cakupan | Nominal | Keputusan | Status |
|---|---|---|---:|---|---|
| **A** | Saldo awal legacy tanpa jejak akuntansi | 9 rekening (`REK-0001-01` s.d. `REK-0004-02`) | **21.200.000** | Diterima sebagai saldo awal seed/demo dan didokumentasikan | **Tidak ada perubahan** (jurnal tidak disentuh) |
| **B** | Rekening Sukarela menampung setoran Pokok + Wajib | 5 rekening (`100-2026-000001..000005`) | 600.000 × 5 | Perbaiki seeder, data lama dibiarkan | **SELESAI** — [DemoDataSeeder.php](file:///c:/laragon/www/koperasi_syariah/database/seeders/DemoDataSeeder.php) kini membuka rekening Pokok, Wajib, dan Sukarela terpisah |
| **C** | `jurnal_detail.rekening_id` salah tandai | 26 baris salah + 6 baris NULL (46 baris non-simpanan juga ikut bertanda) | — | Perbaiki `post()` per baris + normalisasi data | **SELESAI** |

**Kategori A — saldo awal legacy (diterima).** Sembilan rekening `REK-0001-01` s.d. `REK-0004-02` berisi saldo tetapi **tidak memiliki transaksi sama sekali** (`n=0`), total **Rp21.200.000**. Ini konsisten dengan temuan P1-2 (Rp18.500.000 Sukarela + Rp2.700.000 Pokok/Wajib) dan merupakan **keterbatasan data seed**, bukan bug kode berjalan. Sesuai keputusan, saldo dibiarkan dan **tidak dibuatkan jurnal penyesuaian** (menunggu keputusan D-1).

**Kategori B — seeder lama menggabungkan Pokok + Wajib ke rekening Sukarela.** Lima rekening `100-2026-000001..000005` menyimpan setoran awal Rp600.000 (Pokok + Wajib) di rekening Sukarela karena `DemoDataSeeder` lama hanya membuka satu rekening per anggota. Seeder kini membuka tiga rekening terpisah (`POK-2026-*`, `WAJ-2026-*`, `100-2026-*`). **Data lama tidak diubah** — koreksinya menunggu keputusan D-2 (reklasifikasi subledger).

**Kategori C — atribusi baris jurnal, bukan saldo yang hilang.** `AutoJournalingService::post()` dulu menempelkan satu `$rekeningId` global ke **semua** baris jurnal, termasuk baris kas `1-1001`, piutang, pendapatan, dan baris simpanan jenis lain. Akibatnya subledger per rekening menyesatkan meskipun total GL benar. Perbaikan:
- [AutoJournalingService.php](file:///c:/laragon/www/koperasi_syariah/app/Services/AutoJournalingService.php) — `post()` mengisi `rekening_id` **per baris** lewat `rekeningIdUntukCoa()`, dan pemanggil mengoper rekening yang sesuai.
- [2026_10_21_000300_normalisasi_rekening_id_jurnal_detail.php](file:///c:/laragon/www/koperasi_syariah/database/migrations/2026_10_21_000300_normalisasi_rekening_id_jurnal_detail.php) — menormalkan baris lama. Baris simpanan hanya diatribusikan ke rekening anggota yang sama dengan `jenis_rekening` yang cocok; bila rekening sah tidak ada, `rekening_id` dikosongkan. Nominal, pasangan debet/kredit, dan status jurnal **tidak diubah**.

**Verifikasi Kategori C (sebelum → sesudah):**

| Metrik | Sebelum | Sesudah |
|---|---:|---:|
| Baris simpanan dengan `rekening_id` salah | 26 | **0** |
| Baris simpanan dengan `rekening_id` NULL | 6 | 21 |
| Baris kas (`1-1001`) bertanda `rekening_id` | 29 | **0** |
| Baris non-simpanan bertanda `rekening_id` | 46 | **0** |
| Kode CoA yang muncul pada `rekening_id` | 16 | **4** (`2-1001`, `2-1003`, `3-1001`, `3-1002`) |

21 baris NULL yang tersisa berasal dari anggota 5–10 yang **belum memiliki rekening Pokok/Wajib** — keterbatasan data seed, bukan cacat logika. Prinsipnya: lebih baik tidak diatribusikan daripada salah tandai.

**Dampak:** saldo rekening kini dapat direkonsiliasi dari daftar mutasi yang ditampilkan ke anggota, dan subledger per rekening dapat dipakai untuk laporan. Selisih yang tersisa murni berasal dari kategori A dan B di atas.

---

### S-4 — 2 Transaksi `Sukses` Tanpa Jurnal

```
TRX-SEED-WAJIB1-12 | Setoran Simpanan Wajib | 50.000 | rek=21 | 2026-05-01 09:15:00
TRX-SEED-WAJIB1-13 | Setoran Simpanan Wajib | 50.000 | rek=24 | 2026-05-01 09:15:00
```

Keduanya berasal dari `DemoDataSeeder` (prefix `TRX-SEED-`). Total Rp100.000 uang tercatat sebagai transaksi tetapi tampak **tidak masuk buku besar**, sehingga subledger simpanan wajib lebih besar Rp100.000 dari GL — melengkapi temuan P1-2 (selisih `3-1002` sebesar −Rp850.000).

**Status: SUDAH DIPERBAIKI (deteksi, bukan jurnal).**

**Akar masalah.** Kedua transaksi **sudah** tercatat di buku besar, tetapi lewat jurnal **bundling** setoran awal: satu jurnal mengkredit rekening Pokok **dan** rekening Wajib sekaligus. `jurnal.transaksi_id` hanya dapat menunjuk satu transaksi (transaksi Pokok), sedangkan `transaksi` tidak memiliki kolom `jurnal_id`. Deteksi lama hanya memeriksa `jurnal.transaksi_id`, sehingga komponen Wajib selalu dinilai "belum terjurnal".

Bukti data: jurnal `JRN-20261015-0041` (transaksi Pokok 43) dan `JRN-20261015-0048` (transaksi Pokok 51) memang memiliki baris kredit `3-1002` yang menunjuk rekening Wajib anggota 12 (rekening 21) dan anggota 13 (rekening 24) — yaitu tepat dua transaksi `TRX-SEED-WAJIB1-12` dan `-13`.

**Perbaikan.** [SetoranAwalService.php](file:///c:/laragon/www/koperasi_syariah/app/Services/SetoranAwalService.php) — definisi "terjurnal" diperluas lewat `tercatatDalamJurnalBundling()`: komponen dianggap terjurnal bila ada jurnal bersumber `Setoran Awal Anggota (Bundling)` yang memiliki baris detail dengan `rekening_id` rekening tersebut dan `kredit > 0`. Atribusi baris kini dapat dipercaya karena Kategori C sudah menormalkan `rekening_id` per baris.

**Dampak:** **tidak ada** perubahan nominal maupun jurnal — hanya definisi kelengkapan. Anggota 12 dan 13 berubah dari `lengkap = N` menjadi `lengkap = Y` dengan `wajib(ada=Y, jurnal=Y, saldo=50.000)`. Anggota 1–11 tetap `N` karena memang belum memiliki rekening Pokok/Wajib atau belum ada setoran, sehingga tidak ada aktivasi palsu.

**Verifikasi:** test regresi `test_bundling_setoran_awal_diakui_menutup_komponen_wajib` di [AktivasiSetoranAwalTest.php](file:///c:/laragon/www/koperasi_syariah/tests/Feature/AktivasiSetoranAwalTest.php) — `AktivasiSetoranAwalTest` **7 passed / 18 assertions**.

---

### S-5 — Basis Laporan Belum Seragam di `LaporanKeuanganSyariahService`

**Status: SUDAH DIPERBAIKI.**

Setelah K-2 memperkenalkan dua basis saldo di [Coa.php](file:///c:/laragon/www/koperasi_syariah/app/Models/Coa.php), service laporan masih mencampur keduanya:

| Titik | Basis sebelum | Basis sesudah |
|---|---|---|
| `saldoAkun()` | laporan | laporan |
| `saldoAwalPeriode()` | **buku** (`saldoBerjalan()`) | **laporan** (`saldoBerjalanLaporan()`) |
| `registriPeriode()` | **memasukkan** jurnal penutup | **mengecualikan** `Jurnal Penutup` |

Akibat sebelum perbaikan: kolom debet/kredit periode bersih dari jurnal penutup, tetapi saldo awal masih terpengaruh jurnal penutup — sehingga **saldo akhir periode tidak sama dengan saldo awal + mutasi**. Pada akun kewajiban/ekuitas/pendapatan, arah pengurangan juga bisa berlawanan tanda bila posisi akun tidak diperhitungkan.

**Perbaikan** pada [LaporanKeuanganSyariahService.php](file:///c:/laragon/www/koperasi_syariah/app/Services/LaporanKeuanganSyariahService.php):
- `saldoAwalPeriode()` memakai `saldoBerjalanLaporan()` dan mengikuti **posisi akun**: akun bersaldo normal debet → `saldo_akhir − debet + kredit`; akun kredit → `saldo_akhir − kredit + debet`.
- `registriPeriode()` menambahkan `where('jurnal.jenis_jurnal', '!=', Coa::JENIS_JURNAL_PENUTUP)`.

Basis **buku** sengaja dipertahankan pada `mutasiAkun()` (default `$kecualikanPenutup = false`) dan `deteksiArusKasTakTerlacak()`, karena keduanya memang harus melihat seluruh mutasi termasuk penutup.

**Verifikasi:** `php -l` bersih; regresi laporan `--filter="LaporanKeuangan|ArusKas|Neraca|LabaRugi|PerubahanEkuitas|Registri"` → **80 passed / 467 assertions**.

---

### S-6 — `jurnalBundlingSetoranAwal` Tidak Selalu Menerima `rekeningWajibId`

**Status: SUDAH DIPERBAIKI.**

`MandatorySavingsService` membangun jurnal bundling untuk komponen Pokok + Wajib. Bila `$rekeningWajibId` tidak dioper, baris kredit `3-1002` tidak dapat diatribusikan ke rekening anggota — persis celah yang membuat 21 baris `rekening_id` menjadi NULL (S-3 Kategori C).

**Perbaikan:** method `rekeningWajib()` ditambahkan sebagai satu-satunya cara memperoleh rekening Wajib anggota, lalu **4 pemanggil** di `MandatorySavingsService` dan pemanggil ke-5 di [DepositoProductSeeder.php](file:///c:/laragon/www/koperasi_syariah/database/seeders/DepositoProductSeeder.php) dilengkapi argumen `$rekeningWajibId`.

**Verifikasi:** test baru `test_pelunasan_teller_mengatribusikan_baris_wajib_ke_rekening_anggota` di [MandatorySavingsSimpananWajibTest.php](file:///c:/laragon/www/koperasi_syariah/tests/Feature/MandatorySavingsSimpananWajibTest.php) — **13 passed / 88 assertions**. Test ini memastikan baris kredit Wajib pada jurnal hasil pelunasan teller menunjuk rekening Wajib anggota yang benar.

---

### S-7 — 5 Test `ShuHoldNotifikasiTest` Ter-skip

**Status: SUDAH DIPERBAIKI.**

Lima skenario penahanan SHU melewati `markTestSkipped('Saldo akun 3-9000 Ikhtisar SHU tidak mencukupi')`. Akar masalahnya **bukan kode**, melainkan kondisi database dev: buku sudah ditutup pada posisi rugi, sehingga saldo `3-9000` pada **basis buku** = **−1.560.833** (satu jurnal `TUTUPBUKU-20261015-0028`), sementara pada basis laporan = 0. Validasi SHU memang sengaja memakai basis buku (`IkhtisarShuService::saldoPenutup()` dan `ShuController`), karena akun Ikhtisar SHU harus melihat efek penutup apa adanya.

**Perbaikan:** [ShuHoldNotifikasiTest.php](file:///c:/laragon/www/koperasi_syariah/tests/Feature/ShuHoldNotifikasiTest.php) — helper `pastikanSaldoIkhtisar()` menambah `coas.saldo_awal` akun `3-9000` hanya bila kurang dari kebutuhan, di dalam `DatabaseTransactions` sehingga tidak mengubah data permanen. Tidak ada perubahan pada logika SHU maupun angka produksi.

**Verifikasi:** `ShuHoldNotifikasiTest` **10 passed / 66 assertions**, **0 skip** (sebelumnya 5 skip).

---

### S-8 — Selisih Subledger yang Masih Tersisa (Data Seed)

**Status: TERIDENTIFIKASI — menunggu keputusan D-1/D-2. Tidak dieksekusi.**

Setelah seluruh perbaikan kode, rekonsiliasi GL vs subledger per akun simpanan menghasilkan:

| CoA | GL | Subledger | Selisih (GL − SUB) |
|---|---:|---:|---:|
| `2-1001` Simpanan Sukarela | 80.950.000 | 102.450.000 | **−21.500.000** |
| `2-1003` Simpanan Berjangka | 15.000.000 | 15.000.000 | **0** |
| `3-1001` Simpanan Pokok | 2.800.000 | 600.000 | **+2.200.000** |
| `3-1002` Simpanan Wajib | 1.550.000 | 2.400.000 | **−850.000** |

**Akar masalah per kelompok anggota:**

| Kelompok | Kondisi | Penjelasan |
|---|---|---|
| Anggota 1–4 | Rekening Pokok/Wajib **bersaldo**, GL **0** | Saldo awal legacy `REK-0001-*` s.d. `REK-0004-*` tanpa jejak jurnal (Kategori A, Rp21.200.000) |
| Anggota 5–10 | GL Pokok/Wajib **bermutasi**, rekening Pokok/Wajib **tidak ada** | Rekening lama digabung ke Sukarela (Kategori B); 21 baris `rekening_id` NULL berasal dari kelompok ini |
| Anggota 12–13 | GL Wajib **lebih besar** dari saldo rekening Wajib | Komponen Wajib pada jurnal bundling setoran awal (S-4) |

Kesimpulan: **semua selisih berasal dari data seed/demo, bukan dari logika kode berjalan**. Sistem tidak boleh "menambal" selisih ini otomatis.

**Keputusan akhir (21 Oktober 2026): dibiarkan apa adanya.** Data demo tidak diubah, sehingga:
- **D-1** — jurnal penyesuaian Rp21.200.000 untuk 9 rekening legacy **tidak dibuat**; saldo tetap didokumentasikan sebagai saldo awal seed.
- **D-2** — reklasifikasi subledger (pembukaan rekening Pokok/Wajib anggota 5–10 dan koreksi saldo rekening anggota 1–4) **tidak dijalankan**.

Konsekuensinya, laporan yang membaca **GL** tetap benar dan seimbang; selisih hanya terlihat bila membandingkan GL dengan **subledger rekening** pada data demo. Pada implementasi produksi, selisih ini tidak akan muncul karena setiap rekening dibuka dan dimutasi lewat alur aplikasi.

---

### S-9 — Enam Rute Menunjuk Method Controller yang Tidak Ada

**Status: SELESAI.**

Sapuan seluruh 365 rute (`Route::getRoutes()` + pemeriksaan `method_exists` pada target) menemukan **6 rute** yang menunjuk method tidak ada. Kelas masalahnya sama dengan **K-1**: rute terdaftar, menu tampil, tetapi saat diklik pengguna mendapat galat 500. Seluruhnya sudah dikoreksi (3 sisa yang muncul setelah perbaikan adalah invokable bawaan vendor Ignition dan wajar).

`storage/app/audit_sweep_output.txt` kini mencatat **0 galat 4xx/5xx** pada seluruh peran (dps 22 · pengurus 52 · manajer 53 · ao 19 · teller 26 · super_admin 14 · admin 2 · anggota 14), dan `GALAT_TERDOKUMENTASI` pada `AuditSapuanRutePerPeranTest` kembali kosong.

### S-10 — Dua Belas Area Logika Belum Dinamis (Hardcode)

**Status: SELESAI.**

Nilai yang seharusnya mengikuti **kebijakan koperasi** atau **data master** sebelumnya ditulis langsung di kode, sehingga mengubah kebijakan lewat UI tidak berefek. Yang diperbaiki:

| # | Nilai hardcoded | Pengganti dinamis | Titik |
|---|---|---|---|
| 1 | `min:100000` / `min="100000"` pada pengajuan | `Setting::minimalPengajuanPembiayaan()` (Rp 1.000.000, dapat diubah) | 5 controller + 4 view |
| 2 | ~45 nilai default diduplikasi di `KebijakanKoperasi::getSetting()` | `new static(Setting::DEFAULTS)` | `KebijakanKoperasi.php:572` |
| 3 | AML `500000000` + teks "Rp 500 Juta" | `Setting::amlThreshold()` (Rp 100.000.000) | `teller/nota.blade.php` |
| 4 | `$approvalRole = 'pengurus'` | `HierarkiRole::penyetujuTertinggi()` | `TransactionLimitService.php` |
| 5 | Fallback CoA `'4-2001'` | `Coa::DANA_KEBAJIKAN` | `partials/deposito-bilyet-table.blade.php:277` |
| 6 | Duplikasi array pecahan kas | `TellerOpname::PECAHAN` | `TellerController.php:1760` |

**Sengaja DITUNDA (bukan bug):**

| Nilai | Alasan |
|---|---|
| Literal `'Posted'`/`'Draft'`/`'Void'` (19 file) dan `'Aktif'` (30+ file) | Nilai enum DB yang stabil, bukan kebijakan bisnis. Refactor ke konstanta menyentuh puluhan file dengan risiko regresi lebih besar daripada manfaatnya. |
| `min:100000` pada `DynamicLoanController` 71/114 | Ini **lantai plafon produk** saat pengurus membuat produk baru, bukan minimum pengajuan. Nilainya independent dari kebijakan pengajuan. |

### S-11 — Kolom Turunan Pengajuan NULL & Plafon Produk Tidak Divalidasi

**Status: SELESAI.**

Dua celah konsistensi antar kanal pengajuan pembiayaan:

1. **Kolom turunan kosong** — `NasabahController::pengajuanStore()` dan `pengajuanPinjamanStoreBaru()` menyimpan pengajuan **tanpa** `margin_persen`, `total_pembiayaan`, dan `angsuran_per_bulan` (tersimpan `NULL` → tampil **Rp 0** di layar verifikasi AO), padahal kanal AO mengisinya. Diperbaiki dengan satu helper `hitungPengajuan()` yang dipakai kedua method, memakai `Setting::marginDefaultPersen()` bila produk tidak punya margin.
2. **Plafon produk tidak diperiksa** — nominal di bawah `min_plafon` atau di atas `max_plafon` produk lolos di 3 kanal (nasabah ×2, wizard `storeBaru()`). Diperbaiki: `NasabahController::galatPlafonProduk()` + pemeriksaan eksplisit di `PengajuanPembiayaanController::storeBaru()`, membaca rentang dari `master_produk_pembiayaan`.

Bukti runtime: `hitungPengajuan(produk, 10.000.000, 12) → margin 12%, total 11.200.000, angsuran 933.333,33`; `galatPlafonProduk(produk, 100.000)` → *"Jumlah pengajuan kurang dari minimum plafon Rp 1.000.000 …"*.

### S-12 — Rentang Tahun Belum Sadar Tahun Buku & Default Kebijakan Terduplikasi

**Status: SELESAI.**

- `RentangPeriode::awalTahun()`/`akhirTahun()` masih mengasumsikan tahun takwim (1 Jan – 31 Des). Koperasi syariah boleh memakai tahun buku non-kalender (mis. Juli–Juni) sesuai kebijakan. Kini keduanya membaca `TahunBukuService`, sumber yang sama dengan perhitungan SHU, sehingga filter `AngsuranController`, `ManajerController`, dan `PengurusController::pengesahanShu()` konsisten.
- `KebijakanKoperasi::getSetting()` menduplikasi ~45 nilai default. Kini satu sumber: `Setting::DEFAULTS`.

**Dampak:** tidak ada. Pada kebijakan bawaan (Januari–Desember) hasilnya identik persis dengan sebelumnya — dijaga oleh `Tahap7SkalabilitasTest::test_rentang_tahun_mengikuti_tahun_buku`.

### S-13 — Dua Limit Pembiayaan Manajer, Kop Nota, dan Periode SHU

**Status: SELESAI.**

Tiga titik kekakuan yang tidak mengubah perilaku uang:

1. **Limit pembiayaan ditimpa satu nilai.** `PengurusController::updateKebijakan()` menulis `$kebijakan->limit_pembiayaan_manajer = $limitClean > 0 ? $limitClean : Setting::limitApprovalBeban();` — sehingga (a) plafon pembiayaan skala besar **selalu** disamakan dengan `limit_manajer`, dan (b) fallback memakai **batas approval beban** (Rp 1 juta), bukan plafon pembiayaan. Akibatnya menyimpan form dengan field kosong dapat menurunkan plafon persetujuan pembiayaan dari Rp 50 juta menjadi Rp 1 juta tanpa peringatan. Kini keduanya disimpan terpisah (`Setting::limitManajerPembiayaan()` / `Setting::limitPembiayaanManajer()`, default Rp 50 juta), field `limit_pembiayaan_manajer` menjadi input yang dapat diedit (sebelumnya read-only), dan labelnya menjelaskan peran masing-masing.
2. **Kop nota struk literal.** `teller/nota.blade.php` menulis keras "Koperasi Syariah Digital", alamat, dan telepon contoh, sehingga nota yang dicetak tidak memakai identitas koperasi. Kini membaca `config('pdf.koperasi')` — sumber yang sama dengan kop bilyet deposito, dapat diubah lewat `.env` (`KOPERASI_NAMA`, `KOPERASI_ALAMAT`, `KOPERASI_TELEPON`, dst.) tanpa mengubah kode.
   - **Bug tersembunyi yang ikut terbuka:** `@php($kop = ...)` satu baris tidak menutup blok `@php` dengan benar dan membuat blok berikutnya tidak dikompilasi, sehingga `$badgeClass` *undefined* dan nota gagal dirender (500). Diperbaiki dengan blok `@php ... @endphp` bertahap dan dijaga oleh `NotaKopIdentitasTest`.
3. **Periode SHU memakai tahun takwim.** `PengurusController` dan `ShuController` memakai `(int) now()->year` untuk `ringkasanPenahanan()`. Pada koperasi bertahun buku non-kalender (mis. Juli–Juni), Januari 2026 dihitung sebagai tahun buku 2026 padahal masih tahun buku 2025. Kini memakai `TahunBukuService::tahunBerjalan()` yang sudah dibuat pada perbaikan S-12.

**Dampak:** pada kebijakan bawaan (tahun buku Januari–Desember) hasil seluruh perhitungan **identik** dengan sebelumnya — dikunci oleh `TahunBukuBerjalanTest`. Perbaikan No. 1 mengembalikan plafon pembiayaan ke nilai yang benar bila sebelumnya sempat tertimpa (data yang ada di DB tidak diubah).

**Berkas:** `app/Support/Setting.php`, `app/Services/TahunBukuService.php`, `app/Http/Controllers/Dashboard/PengurusController.php`, `app/Http/Controllers/Pengurus/ShuController.php`, `resources/views/pengurus/kebijakan.blade.php`, `resources/views/teller/nota.blade.php`, `tests/Feature/LimitTellerParameterTest.php`, `tests/Feature/NotaKopIdentitasTest.php`, `tests/Feature/TahunBukuBerjalanTest.php`.

---

### S-14 — Awalan Nomor Dokumen Ditulis Keras

**Status: SELESAI.**

**Masalah.** Enam jenis dokumen memakai awalan literal di kode (`TRX`, `RKN`, `JRN`, `BBK`, `TRM`, `DEP`). Koperasi yang ingin memakai penomoran sendiri (mis. `KSP`, `ANG`, `BKM`) harus mengubah kode dan men-deploy ulang — padahal ini murni kebijakan administratif, bukan logika akuntansi.

**Perbaikan.** `App\Support\NomorDokumen` kini membaca awalan dari kebijakan:

```php
public static function kode(string $jenis): string
{
    $bawaan = self::BAWAAN[$jenis] ?? strtoupper($jenis);

    return Setting::prefixNomorDokumen($jenis) ?? $bawaan;
}
```

Enam kolom baru (`prefix_nomor_transaksi`, `_rekening`, `_jurnal`, `_pengeluaran_beban`, `_terminasi`, `_deposito`) masuk `Setting::DEFAULTS` dengan nilai `null`, sehingga **default aman**: selama kebijakan belum diisi, nomor yang dihasilkan **identik** dengan perilaku lama. Normalisasi input: huruf kecil menjadi huruf besar, tanda baca dibuang, maksimal 8 karakter, dan kosong berarti kembali ke bawaan.

**Dampak:** tidak ada nomor dokumen yang berubah pada data/kebijakan yang ada. Yang berubah hanya **sumber** awalan.

**Berkas:** `app/Support/NomorDokumen.php`, `app/Support/Setting.php`, `app/Models/KebijakanKoperasi.php`, `resources/views/pengurus/kebijakan.blade.php`, `database/migrations/*_add_prefix_nomor_*`, `tests/Feature/NomorDokumenPrefixTest.php`.

---

### S-15 — Hari Libur & Kalender Tanpa Antarmuka

**Status: SELESAI.**

**Masalah.** Tabel `hari_liburs` dan parameter kalender (`hari_libur_aktif`, `hari_libur_strategi`) sudah dipakai mesin penjadwalan angsuran, tetapi **tidak ada halaman** untuk memeliharanya. Akibatnya tanggal libur nasional/cuti bersama hanya dapat diubah lewat basis data, dan Pengurus tidak dapat menyesuaikan perilaku penjadwalan saat hari libur.

**Perbaikan.**
1. Section **"Periode & Kalender"** ditambahkan pada form Kebijakan Koperasi ([kebijakan.blade.php](file:///c:/laragon/www/koperasi_syariah/resources/views/pengurus/kebijakan.blade.php#L394)), memuat saklar aktif, strategi maju/mundur, dan tautan ke pengelola hari libur.
2. **CRUD hari libur** lengkap di `HariLiburController` dengan lima aksi (`index`, `store`, `update`, `toggle`, `destroy`) pada grup rute `izin:kebijakan.koperasi`:

```php
Route::get('/hari-libur',                      [HariLiburController::class, 'index'])->name('pengurus.hari-libur.index');
Route::post('/hari-libur',                     [HariLiburController::class, 'store'])->name('pengurus.hari-libur.store');
Route::put('/hari-libur/{hari_libur}',         [HariLiburController::class, 'update'])->name('pengurus.hari-libur.update');
Route::post('/hari-libur/{hari_libur}/toggle', [HariLiburController::class, 'toggle'])->name('pengurus.hari-libur.toggle');
Route::delete('/hari-libur/{hari_libur}',      [HariLiburController::class, 'destroy'])->name('pengurus.hari-libur.destroy');
```

3. Halaman menyaring per tahun (2000–2100, fallback tahun berjalan), menampilkan statistik, dan **pratinjau** dampak penjadwalan sebelum disimpan. Tombol ubah hanya tampil bagi peran dengan level `>= manajer`.

**Dampak:** perilaku penjadwalan **tidak berubah** bila tabel hari libur dan parameternya tidak diubah. Perubahan hanya menambah kemampuan memelihara data yang selama ini hanya bisa diisi lewat seeder.

**Berkas:** `app/Http/Controllers/HariLiburController.php`, `resources/views/pengurus/hari-libur.blade.php`, `resources/views/pengurus/kebijakan.blade.php`, `routes/web.php`, `tests/Feature/HariLiburCrudTest.php`.

---

### S-16 — Retensi Cadangan Basis Data Ditulis Keras

**Status: SELESAI.**

**Masalah.** Jadwal `db:cadangkan` menulis keras `--simpan-hari=14` di `routes/console.php`. Koperasi dengan volume transaksi besar mungkin butuh retensi lebih panjang; koperasi kecil mungkin ingin lebih pendek karena keterbatasan disk. Keduanya tidak dapat diubah tanpa menyentuh kode.

**Perbaikan.**
1. Kolom `backup_retensi_hari` (`unsignedSmallInteger`, default 14) ditambahkan pada `kebijakan_koperasis`.
2. Getter `Setting::backupRetensiHari()` mengunci nilai ke rentang **1–365** agar salah input tidak menghapus seluruh cadangan (`0`) atau memenuhi disk (nilai ekstrem).
3. Jadwal membaca kebijakan saat registrasi:

```php
Schedule::command('db:cadangkan --simpan-hari=' . Setting::backupRetensiHari())
    ->dailyAt('01:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/cadangan-basis-data.log'));
```

4. Field input ditambahkan pada section **"Pemeliharaan & Cadangan Basis Data"** di form kebijakan; validasi `nullable|integer|min:1|max:365`. Pembaruan sebagian (field tidak dikirim) **tidak** mereset nilai — mengikuti prinsip P4 Default Aman.

**Dampak:** pada kebijakan bawaan, jadwal menghasilkan perintah **identik** (`--simpan-hari=14`). Yang berubah hanya sumber angka.

**Berkas:** `database/migrations/2026_10_21_000300_add_backup_retensi_hari_to_kebijakan_koperasis.php`, `app/Support/Setting.php`, `app/Models/KebijakanKoperasi.php`, `app/Http/Controllers/Dashboard/PengurusController.php`, `resources/views/pengurus/kebijakan.blade.php`, `routes/console.php`, `tests/Feature/BackupRetensiSettingTest.php`.

---

### S-17 — Matriks Hak Akses Tak Terlihat & Override Limit Tanpa CRUD

**Status: SELESAI.**

**Masalah.** Dua celah pada halaman `pengurus/users`:
1. Judulnya "Manajemen User & **Hak Akses**", tetapi isinya hanya daftar user. Peta wewenang (`config/izin.php`) lengkap dan sudah menjadi sumber tunggal middleware `izin:`, namun **tidak pernah ditampilkan** — sehingga tidak ada cara memeriksa siapa boleh apa tanpa membaca kode.
2. Tabel `limit_transaksi_role` dibaca oleh `HierarkiRole::batas()`/`override()`, tetapi **tidak punya CRUD maupun validasi**. Satu-satunya tampilan (`dashboard/partials/pengurus-limit.blade.php`) bersifat read-only dan bahkan tidak menampilkan `limit_harian`.

**Perbaikan.**
1. **Matriks izin read-only** pada halaman yang sama: seluruh izin dari `Izin::peta()` dikelompokkan per modul (awalan sebelum titik) dan disilangkan dengan `HierarkiRole::peran()`. Sengaja **tidak ada** tabel override izin — wewenang tetap hanya dapat diubah lewat kode agar pemisahan tugas dapat diaudit.
2. **Editor override batas operasional** (tambah/ubah/hapus) dengan tiga aksi baru pada grup `izin:master.data`:

```php
Route::post('/users/limit',                [PengurusController::class, 'storeLimit'])->name('pengurus.limit-role.store');
Route::put('/users/limit/{limit_role}',    [PengurusController::class, 'updateLimit'])->name('pengurus.limit-role.update');
Route::delete('/users/limit/{limit_role}', [PengurusController::class, 'destroyLimit'])->name('pengurus.limit-role.destroy');
```

3. **Validasi** yang sebelumnya tidak ada sama sekali: `role`/`approval_role` harus anggota `HierarkiRole::peran()` (kolomnya VARCHAR tanpa foreign key), `jenis_transaksi` harus salah satu dari tiga jenis pada konfigurasi, nominal `numeric|min:0`, dan pasangan `(role, jenis_transaksi)` unik dengan `ignore` saat ubah.
4. **Semantik dipertahankan**: kosong = *unlimited* (`NULL`), `0` = *dilarang*. Saat `butuh_approval` dimatikan, `approval_role` dikosongkan agar tidak menyesatkan pembacaan berikutnya.
5. Kolom **"Berlaku"** menampilkan nilai efektif hasil tiga lapis: config default → override → kolom kebijakan. Ini penting karena limit teller masih dapat ditimpa dari menu Kebijakan; tanpa kolom ini selisih akan tampak seperti kesalahan data.
6. Cache override **otomatis** dibersihkan karena `LimitTransaksiRole::booted()` sudah memanggil `HierarkiRole::flush()` pada event `saved`/`deleted` — controller tidak melakukan flush manual.

**Dampak:** tidak ada nominal transaksi, jurnal, atau wewenang yang berubah. Halaman hanya membaca konfigurasi yang sudah berlaku dan menulis ke tabel override yang **sudah** dibaca mesin batas. Guard tidak dilonggarkan: Pengurus, Manajer, AO, dan Teller tetap **403** dari halaman maupun aksi tulis — dikunci oleh `MatriksHakAksesTest` dan `NavigasiWewenangPeranTest`.

**Berkas:** `app/Http/Controllers/Dashboard/PengurusController.php`, `resources/views/pengurus/users.blade.php`, `routes/web.php`, `tests/Feature/MatriksHakAksesTest.php`.

---

### S-18 — Jalur CoA `AutoJournalingService` Tidak Konsisten (Statis vs Dinamis)

**Status: SELESAI.**

**Masalah.** `CoaResolver` sudah menjadi pintu masuk resmi resolusi akun (tiga lapis: `pemetaan_akad_coa` → kebijakan koperasi → registri `App\Support\Coa`), tetapi baru dipakai separuh jalan:

| Titik | Sebelum perbaikan | Akibat |
|---|---|---|
| `jurnalPencairan()` | `CoaResolver` (dinamis) | Piutang bertambah di akun **baru** |
| `pendapatanByAkad()` / `piutangByAkad()` privat | `App\Support\Coa::*ByAkad()` (statis) | Angsuran mengurangi piutang di akun **lama** |
| `jurnalReversalPencairanPembiayaan()` | `Coa::piutangByAkad()` / `Coa::marginDitangguhkanByAkad()` (statis) | Pembatalan pencairan menyentuh akun **lama** |

Bila Pengurus mengarahkan `account_piutang_murabahah_id` ke akun lain lewat menu Kebijakan, piutang tercatat bertambah di akun baru saat pencairan tetapi berkurang di akun lama saat angsuran — **saldo piutang tidak akan pernah kembali nol**, dan pembatalan pencairan meninggalkan residu di kedua akun.

**Perbaikan.** Seluruh jalur pembiayaan kini melewati `CoaResolver`:

```php
private function pendapatanByAkad(string $akad): ?string
{
    return app(CoaResolver::class)->kodePendapatanByAkad($akad);
}

private function piutangByAkad(string $akad): string
{
    return app(CoaResolver::class)->kodePiutangByAkad($akad);
}
```

```php
$resolver    = app(CoaResolver::class);
$kodePiutang = $resolver->kodePiutangByAkad($akad);
$kodeMargin  = $resolver->kodeMarginDitangguhkanByAkad($akad);
```

Karena `jurnalAngsuran()`, `jurnalReversalAngsuran()`, dan `AngsuranTransactionService::postJurnal()` memanggil method privat/proxy tersebut, ketiganya otomatis ikut dinamis **tanpa** perubahan pemanggil.

**Risiko perilaku terukur NOL.** Skrip verifikasi menunjukkan **seluruh** kolom `account_*_id` pada `kebijakan_koperasis` bernilai `NULL` dan **seluruh** baris `pemetaan_akad_coa` kosong. Dengan begitu resolver jatuh ke lapis ketiga dan mengembalikan kode yang **identik** dengan registri `App\Support\Coa` — nomor akun pada jurnal existing tidak berubah.

**Sisa yang sengaja dipertahankan.** 48 pemakaian `self::KAS_TELLER`, `SIMPANAN_SUKARELA`, `BANK_KOPERASI`, `KAS_BRANKAS` di `AutoJournalingService` **belum** dialihkan karena dipakai sebagai *nilai default parameter* pada 7 signature; mengalihkannya menuntut method resolver baru (`kodeKasBrankas`, `kodeSimpananPokok`, dst.) yang melampaui batas `CoaResolverInterface` (maksimal 10 method, dijaga `KontrakArsitekturTest`). Konstanta itu sendiri adalah *alias* ke `App\Support\Coa`, bukan nomor liar.

**Berkas:** `app/Services/AutoJournalingService.php`, `tests/Feature/JurnalResolverCoaTest.php`.

---

## 5. Modul yang Diperiksa dan Terbukti BENAR

Bagian ini penting agar Anda tidak memperbaiki hal yang sudah sehat.

| Pemeriksaan | Hasil |
|---|---|
| Keseimbangan buku besar | **Debet = Kredit = Rp124.747.500,00**, selisih **0** |
| Header jurnal vs SUM detail | **0 tidak konsisten dari 68 jurnal** (semua `is_balance`) |
| Keseimbangan neraca | **Aset Rp104.839.167 = Pasiva Rp104.839.167**, selisih **0** |
| Konsistensi pembiayaan (pokok/total/sisa) | **5 dari 5 konsisten**; selisih hanya pembulatan sen (PMB-2026-0001 Rp9.999.999,96 vs 10.000.000; PMB-2026-0004 Rp8.000.000,04 vs 8.000.000) |
| Subledger simpanan berjangka `2-1003` | **Cocok** (GL Rp15.000.000 = subledger Rp15.000.000) |
| Jurnal `Posted` tanpa `transaksi_id`/`pembiayaan_id` | 13 jurnal, **semuanya sah** (opening balance, transfer internal, 3 jurnal beban, 1 jurnal penutup, 4 penerimaan zakat/infak, 3 penyaluran zakat/infak) |
| Atribusi anggota pada jurnal simpanan | `TANPA_ANGGOTA` = **0 baris / Rp0** untuk keempat akun simpanan |
| Rantai `saldo_setelah` per rekening | **0 salah dari 46 transaksi** ber-`rekening_id`; setiap rantai berakhir tepat di `rekening.saldo` (0 rekening tidak cocok) |
| Perhitungan posisi saldo akun | Menggunakan **kepala kode akun**, bukan kolom `tipe_saldo_normal` — sudah benar dan sengaja didokumentasikan di [Coa.php:226-245](file:///c:/laragon/www/koperasi_syariah/app/Models/Coa.php#L226-L245) |

---

## 6. False Positive yang Digugurkan

Temuan berikut **sempat muncul** saat audit tetapi setelah diverifikasi **bukan bug**. Saya cantumkan agar tidak menimbulkan alarm palsu.

| Dugaan | Hasil verifikasi |
|---|---|
| "Neraca tidak balance karena L/R = 0" | **Gugur.** Neraca tetap seimbang: Aset = Pasiva = Rp104.839.167. Yang bermasalah hanya L/R (lihat K-2). |
| "`$anggota` undefined di view pengajuan-pembiayaan" | **Gugur.** `PengajuanPembiayaanIntegController::index()` baris 62–72 mengirim `compact(...)` termasuk `anggota`. |
| "`jenis_transaksi` tidak konsisten antar modul" | **Gugur sebagian.** `Transaksi::isMasuk()` sudah memprioritaskan `posisi` (perbaikan DK-2). Yang tersisa adalah **data seed lama** (S-2), bukan logika method. |
| "Script neraca audit menyimpang Rp2.200.000" | **Gugur.** Script audit yang memakai `tipe_saldo_normal`; sistem memakai kepala kode akun. **Sistem yang benar.** |
| "`else if (mode === 'DISABLED')` menyebabkan box ta'zir tampil" | **Gugur untuk UI.** Karena `nominal = 0`, view masuk ke cabang `else` dan menyembunyikan box. |
| "Masih ada 10 `saldo_setelah` tidak konsisten" | **Gugur.** Skrip audit lama membandingkan transaksi secara **global lintas rekening**. Pemeriksaan ulang **per rekening** (`cek_rantai_saldo.php`) → **0 salah dari 46 transaksi**, dan setiap rantai berakhir tepat di `rekening.saldo`. |

---

## 7. Rekomendasi Prioritas dan Status Pelaksanaan

| Prioritas | Tindakan | Alasan | Status |
|---|---|---|---|
| **1** | Perbaiki **K-1** (`Gate::define('role', ...)`) + test regresi render form | Fitur inti (pengajuan pembiayaan) sempat **mati total** di UI | **SELESAI** |
| **2** | Perbaiki **K-2** (kecualikan `Jurnal Penutup` dari saldo L/R) | Laporan keuangan menyesatkan; memengaruhi SHU & PSAK 101 | **SELESAI** |
| **3** | Satukan konvensi `posisi` + migrasi data seed (**S-2**) | 54/57 saldo berjalan salah dan tampil ke anggota | **SELESAI** |
| **4** | Rekonsiliasi rekening (**S-3**) dan 2 transaksi tanpa jurnal (**S-4**) | Kredibilitas subledger; melengkapi P1-2 | **SELESAI** (S-3 kategori A diterima/didokumentasikan, B & C diperbaiki; S-4 deteksi diperbaiki) |
| **5** | Rapikan `TazirService::detail()` (**S-1**) | Mencegah inkonsistensi kontrak API | **SELESAI** |
| **6** | Selaraskan basis laporan (**S-5**), lengkapi `rekeningWajibId` (**S-6**), benahi test skip (**S-7**) | Laporan periode konsisten; subledger Wajib teratribusi; tidak ada kontrak yang tak teruji | **SELESAI** |
| **7** | Perbaiki 6 rute target rusak (**S-9**) | Menu tampil tetapi halaman 500 saat diklik | **SELESAI** |
| **8** | Dinamiskan 12 area hardcode (**S-10**) | Mengubah kebijakan lewat UI tidak berefek pada validasi/tampilan | **SELESAI** |
| **9** | Lengkapi kolom turunan + validasi plafon pengajuan (**S-11**) | Layar verifikasi AO menampilkan margin/angsuran Rp 0 | **SELESAI** |
| **10** | Akses API baca JSON + tahun buku di `RentangPeriode` (**S-12**) | Endpoint API & filter tahun konsisten dengan kebijakan koperasi | **SELESAI** |
| **11** | Pisahkan dua limit pembiayaan + kop nota dari config + periode SHU (**S-13**) | Plafon persetujuan tidak turun diam-diam; nota memakai identitas koperasi; penahanan SHU mengikuti tahun buku | **SELESAI** |
| **12** | Awalan nomor dokumen dari Setting (**S-14**) | Mengganti awalan TRX/RKN/JRN/BBK/TRM/DEP menuntut ubah kode | **SELESAI** |
| **13** | Section "Periode & Kalender" + CRUD hari libur (**S-15**) | Kalender hanya ada di basis data; Pengurus tidak dapat memeliharanya | **SELESAI** |
| **14** | Retensi cadangan dari Setting + jadwal scheduler (**S-16**) | `--simpan-hari=14` ditulis keras; tidak dapat disesuaikan | **SELESAI** |
| **15** | Matriks hak akses read-only + CRUD override limit per peran (**S-17**) | Izin tidak terlihat dari UI; `limit_transaksi_role` tanpa validasi/CRUD | **SELESAI** |
| **16** | Selaraskan jalur CoA `AutoJournalingService` ke `CoaResolver` (**S-18**) | Pencairan dinamis tetapi angsuran/reversal statis → saldo piutang tidak pernah nol saat akun dipindah | **SELESAI** |

**Catatan kepatuhan:** tidak ada perilaku uang atau nominal jurnal yang diubah. Perbaikan K-2 hanya mengubah **cara menyajikan** L/R; S-2/S-3 hanya menyelaraskan `posisi`, `saldo_setelah`, dan atribusi `rekening_id` tanpa menyentuh nominal maupun pasangan debet/kredit; S-4 hanya memperluas definisi "terjurnal"; S-5 hanya menyelaraskan basis saldo pada laporan; S-6 hanya melengkapi argumen atribusi; S-7 hanya menyesuaikan setup test di dalam transaksi; S-11 hanya **melengkapi kolom turunan** yang sebelumnya kosong (rumus sama dengan kanal AO) dan **menolak** nominal di luar plafon produk; S-12 identik hasilnya pada kebijakan tahun buku kalender; S-13 hanya **membetulkan sumber nilai** dua limit (tidak menyentuh nominal transaksi/jurnal), mengganti **teks kop** nota, dan mengganti **sumber tahun** pada perhitungan penahanan SHU (identik pada tahun buku kalender). **D-1** (jurnal penyesuaian Rp21.200.000) dan **D-2** (reklasifikasi subledger) telah diputuskan untuk **dibiarkan apa adanya** pada 21 Oktober 2026 — data demo tidak diubah. Rincian selisih ada di §4 S-8.

### Catatan verifikasi runtime (bukan temuan)

Harness tinker yang memanggil `app(Kernel::class)->handle()` berulang dalam satu proses **tidak** dapat dipakai menguji endpoint API: rute `auth:sanctum` memanggil `Auth::shouldUse('sanctum')` yang mengubah **guard bawaan proses**, sehingga panggilan berikutnya memakai `RequestGuard` (tanpa `getName()`/`logout()`) dan menghasilkan 500 palsu (`ERR-*`). Pengujian yang sah dilakukan lewat `tests/Feature/ApiBacaJsonTest.php` dengan guard `web` eksplisit — hasilnya **seluruh 13 endpoint baca membalas JSON 200**.

**`[nasabah] total=0` pada sapuan rute bukan bug:** peran `nasabah` (calon anggota non-anggota, OJK-06) sengaja tidak memiliki izin `nasabah.swalayan` — izin itu hanya untuk peran `anggota`. Modul `/nasabah/*` memerlukan relasi `anggota.user_id`, sehingga peran `nasabah` tidak memiliki halaman swalayan sampai statusnya naik menjadi anggota. Memang tidak ada rute lain yang memberinya akses baca.

---

## 8. Lampiran — Cara Mereproduksi

Skrip verifikasi bersifat sementara dan tidak disimpan di dalam project:

```
C:\Users\LENOVO\AppData\Local\Temp\audit_final.php
C:\Users\LENOVO\AppData\Local\Temp\audit_final2.php
C:\Users\LENOVO\AppData\Local\Temp\cek_39000.php
C:\Users\LENOVO\AppData\Local\Temp\audit_null_rekening.php
C:\Users\LENOVO\AppData\Local\Temp\audit_subledger.php
C:\Users\LENOVO\AppData\Local\Temp\cek_rantai_saldo.php
```

| Skrip | Kegunaan |
|---|---|
| `audit_final.php` / `audit_final2.php` | Keseimbangan buku besar, header vs detail, keseimbangan neraca |
| `cek_39000.php` | Saldo `3-9000` pada basis buku vs basis laporan + jurnal penutup terkait |
| `audit_null_rekening.php` | Daftar baris `jurnal_detail.rekening_id` NULL beserta jurnal/anggota asalnya |
| `audit_subledger.php` | Rekonsiliasi GL vs subledger per CoA simpanan dan per anggota |
| `cek_rantai_saldo.php` | Rantai `saldo_setelah` **per rekening** (bukan global) hingga berakhir di `rekening.saldo` |

Dijalankan dengan:

```powershell
php artisan tinker --execute="require 'C:/Users/LENOVO/AppData/Local/Temp/audit_final.php';"
```

Skrip hanya membaca (`SELECT`) dan tidak menulis ke database.
