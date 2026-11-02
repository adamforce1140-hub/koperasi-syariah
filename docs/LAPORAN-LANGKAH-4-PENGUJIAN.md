# LAPORAN LANGKAH 4 — PENGUJIAN & PERBAIKAN

Status: **berjalan**. Setiap batch diakhiri regression test + gate penuh.
Database uji: `koperasi_syariah_test` (lihat `phpunit.xml`).

## Gate yang dipakai

| Gate | Perintah | Cakupan |
|---|---|---|
| Test penuh | `php artisan test` | seluruh suite |
| Test batch | `php artisan test tests/Feature/<BerkasBatch>.php` | berkas batch |
| Pint | `vendor\bin\pint <berkas yang berubah> --test` | **hanya berkas yang disentuh batch** |

Keputusan pengguna: gate Pint **dipersempit ke berkas yang berubah**. Pelanggaran
gaya pra-eksisting di berkas lain dicatat sebagai utang teknis, tidak diperbaiki
dalam batch ini agar diff tetap terukur.

## Batch 1 — Otorisasi kepemilikan data

Berkas: `tests/Feature/OtorisasiKepemilikanDataTest.php` (7 test).

| Pintu | Perilaku yang dikunci |
|---|---|
| `POST /api/transaksi/setor`, `/tarik` | anggota tidak dapat bertransaksi pada rekening anggota lain (403, tanpa transaksi); teller tetap dapat melayani rekening siapa pun |
| `GET /nasabah/deposito/{id}/bagi-hasil` | bilyet milik anggota lain → 403 |
| `PUT /nasabah/profil` | kolom di luar allowlist (`role`, `user_id`, `nik`, `no_anggota`, `status_anggota`) tidak dapat ditimpa |

Catatan: ketiga temuan ini sudah berstatus selesai pada Gelombang 1 KRITIS
(`docs/AUDIT-MENYELURUH-PSAK-OJK.md`). Batch 1 berfungsi sebagai **regression
lock**, bukan perbaikan baru.

## Batch 2 — Cakupan antrean approval transaksi

Berkas: `tests/Feature/CakupanAntreanApprovalTest.php` (8 test).

### Temuan A-01 (baru, terkonfirmasi)

`ApprovalTransaksiController::index()` membatasi kueri per peran, tetapi
`show()` tidak. Akibatnya pembatasan hanya lapisan tampilan: manajer yang
menebak ID dapat membuka detail approval `PENDING_PENGURUS` maupun keputusan
manajer lain. Dikonfirmasi lewat test (respons 200, bukan 403) sebelum diperbaiki.

### Perbaikan

`show()` kini menerapkan `batasiKeCakupanPeran()` yang sama dengan `index()`
dan mengembalikan 403 bila baris di luar cakupan. Tanpa literal peran baru —
aturan tetap diturunkan dari `config/role_hierarki.php`.

### Perilaku yang dikunci

- Daftar: manajer tidak melihat antrean pengurus; pengurus melihat seluruh antrean.
- Detail: manajer hanya membuka antrean `PENDING_MANAJER` dan keputusan yang ia
  buat sendiri; pengurus membuka semua.
- `GET /api/approval/status`: anggota menerima antrean kosong; manajer hanya
  antrean manajernya (tidak membocorkan antrean peran lain).
- `POST /pengelola/approval/{id}/approve`: manajer tidak dapat menyetujui
  antrean `PENDING_PENGURUS`; status baris tidak berubah.

## Batch 3 — Integritas buku besar pada API transaksi

Berkas: `tests/Feature/IntegritasBukuBesarTransaksiTest.php` (7 test).

### Temuan A-02 (baru, terkonfirmasi)

`TransaksiController::update()` dan `destroy()` tidak memeriksa apakah transaksi
sudah diposting ke jurnal. `DELETE /api/transaksi/{id}` menghapus bukti
transaksi sementara jurnalnya tetap `Posted` → buku besar tidak seimbang dan
jejak audit (PSAK 101) hilang. `PUT` juga membolehkan perubahan `jumlah` dan
`rekening_id` pada transaksi terjurnal.

Dikonfirmasi lewat test: keempat skenario mengembalikan **200**, bukan 422.

### Perbaikan

Guard `pastikanTransaksiDapatDimutasi()` pada `update()` dan `destroy()`:

- transaksi dengan jurnal berstatus `Posted` → 422, arahkan ke alur void;
- transaksi `Pending Void` / `Voided` → 422 (sedang menunggu otorisasi).

Transaksi draf (belum terjurnal) tetap bebas dikoreksi/dihapus — dikunci oleh
dua kontrol positif agar perbaikan tidak memblokir jalur sah.

### Perilaku yang dikunci

- Hapus/ubah transaksi terjurnal → 422; baris dan jurnal tetap utuh.
- Nominal dan rekening transaksi terjurnal tidak berubah.
- Transaksi `Pending Void` tidak dapat dihapus.
- Transaksi draf tanpa jurnal tetap dapat dihapus (200) dan diubah (200).

## Batch 4 — Guard saldo & simpanan modal pada penarikan tunai

Berkas: `tests/Feature/GuardSaldoDanSimpananModalTest.php` (7 test, 60 assertion).

Jalur `POST teller/tarik` (`TarikTunaiAction`) memiliki tiga gerbang: allowlist
Simpanan Sukarela, kecukupan kas fisik teller, dan batas saldo/batas minimum.
Batch ini mengunci ketiganya **beserta kontrol positifnya** (penarikan sah tetap
berhasil & terjurnal), sehingga penguncian tidak berubah menjadi "aman tetapi
memblokir yang sah".

### Temuan A-03 (baru, terkonfirmasi)

`TellerController::tarikForm()` menyaring `status_rekening = 'Aktif'`, tetapi
`tarikStore()` hanya memvalidasi `exists:rekening,id`. `TarikTunaiAction` hanya
memanggil `GuardSimpananModal::pastikanRekeningDapatDitarikTunai()` yang
memeriksa `jenis_rekening`, dan `Rekening::isDapatDitarik()` hanya memeriksa
saldo & `batas_minimum`. Akibatnya rekening `Ditutup`/`Dibekukan`/`Jatuh Tempo`
berjenis Simpanan Sukarela **masih dapat ditarik tunai** lewat request langsung.

Dikonfirmasi lewat test sebelum diperbaiki: penarikan Rp 100.000 dari rekening
berstatus `Ditutup` (saldo Rp 500.000) **berhasil** — respons `302` ke
`teller.nota/{id}`, saldo berkurang, transaksi & jurnal terbentuk.

### Perbaikan

- `Rekening::isAktif()` — predikat status tunggal (case-insensitive), sejalan
  dengan `scopeAktif()`.
- `TarikTunaiAction` — GATE 2 baru setelah allowlist: tolak rekening non-Aktif
  dengan pesan yang menyebut nomor & status rekening.
- Pemeriksaan status **diulang di dalam** `DB::transaction` setelah
  `lockForUpdate()`, sehingga rekening yang ditutup bersamaan tidak lolos
  (pola yang sama dengan pengulangan guard saldo).

### Perilaku yang dikunci

- Penarikan sah: saldo berkurang tepat, `Transaksi` berstatus `Sukses` dengan
  `saldo_setelah` benar, dan `Jurnal` bertaut berstatus `Posted`.
- Melebihi saldo / menembus `batas_minimum` / melebihi kas laci teller → ditolak;
  saldo utuh dan tidak ada baris transaksi baru.
- Penarikan tepat sampai `batas_minimum` tetap diizinkan (kontrol positif).
- Rekening `Ditutup`, `Dibekukan`, `Jatuh Tempo` → ditolak; saldo utuh, tanpa
  baris transaksi.
- `Simpanan Pokok` & `Simpanan Wajib` → ditolak dengan `GuardSimpananModal::PESAN`
  persis, saldo utuh, dan `audit_log` `PENARIKAN_MODAL_DIBLOKIR` bertambah.
- Allowlist tetap `['Simpanan Sukarela']` (tidak melebar).

## Batch 5 — Integritas pembayaran angsuran (pembiayaan/angsuran)

Berkas: `tests/Feature/IntegritasPembayaranAngsuranTest.php` (6 test, 25 assertion).

Batch ini menguji dua jalur pembayaran angsuran — **TUNAI** (uang masuk laci)
dan **AUTODEBET** (pemindahan dana dari Simpanan Sukarela) — beserta kontrol
positifnya, alokasi ta'zir, dan pengaruhnya terhadap kas fisik teller.

### Temuan A-04 (baru, terkonfirmasi) — autodebet menembus `batas_minimum`

`AngsuranTransactionService::proses()` memeriksa saldo **di luar**
`DB::transaction`, pada baris yang **tidak terkunci**, dan hanya membandingkan
`saldo < totalTagihan` — tidak memanggil `Rekening::isDapatDitarik()`. Di dalam
transaksi, baris rekening dikunci dengan `->first()` lalu langsung
`decrement()` **tanpa** pemeriksaan ulang saldo.

Akibatnya autodebet dapat memotong dana sampai menyisakan saldo **di bawah
`batas_minimum`** rekening; pada eksekusi konkuren dua autodebet dapat
sama-sama lolos pra-cek dan membuat saldo negatif; bila rekening terhapus,
`decrement()` dipanggil pada `null`.

Dikonfirmasi lewat test sebelum diperbaiki: autodebet Rp 125.000 dari rekening
saldo Rp 600.000 dengan `batas_minimum` Rp 500.000 **berhasil dipotong**
(tidak ada exception, saldo berkurang).

### Temuan A-05 (baru, terkonfirmasi) — autodebet menambah kas fisik laci

`AngsuranTransactionService` menulis transaksi autodebet dengan
`jenis_transaksi = 'Pembayaran Angsuran'`, nilai yang terdaftar pada
`JenisTransaksi::CASH_IN`. `KasHarian::hitungSaldoKasTeller()` menjumlahkan
seluruh `CASH_IN` **tanpa** memfilter `jenis_pembayaran`, sehingga autodebet —
yang tidak pernah menerima uang tunai — ikut dihitung sebagai **cash-in laci
teller**. Angka yang sama dipakai guard penarikan tunai (`TarikTunaiAction`),
jadi kelebihan hitung ini **memperlonggar** gerbang penarikan.

Kontradiksi internal: docblock `KasHarian::ringkasanPosisiKas()` menyatakan
bahwa transaksi bersifat pemindahan (auto-debit) "sengaja diabaikan agar tidak
salah menambah/mengurangi kas fisik", tetapi `Pembayaran Angsuran` sudah
terdaftar di `CASH_IN` sehingga tidak pernah masuk cabang pengabaian itu.

Dikonfirmasi lewat test sebelum diperbaiki: setelah autodebet Rp 125.000,
`hitungSaldoKasTeller()` melaporkan `cash_in = 125.000` dan saldo laci
Rp 1.125.000 — padahal laci tidak menerima uang.

### Perbaikan

- `App\Support\JenisTransaksi::PEMBAYARAN_NON_KAS = ['autodebet']` — registri
  tunggal nilai `jenis_pembayaran` yang bersifat pemindahan dana, bukan kas fisik.
- `AngsuranTransactionService` — pra-cek memakai `Rekening::isDapatDitarik()`
  (menghormati `batas_minimum`), dan guard **diulang di dalam kunci** setelah
  `lockForUpdate()` dengan `firstOrFail()`; pesan penolakan menyebut saldo,
  batas minimum, dan nominal yang dibutuhkan. Pola ini konsisten dengan
  `TazirService::prosesAutodebetSusulan()` dan
  `MandatorySavingsService::autoDebit()`.
- `KasHarian::hitungSaldoKasTeller()` & `ringkasanPosisiKas()` — mengecualikan
  baris ber-`jenis_pembayaran` non-kas dari perhitungan cash-in/cash-out
  (helper privat `kecualikanPembayaranNonKas()`); `ringkasanPosisiKas()` juga
  melewati baris non-kas di dalam loop klasifikasi.

### Perilaku yang dikunci

- Pembayaran tunai penuh: jurnal `Posted` & balance, `sisa_pembiayaan` turun
  tepat, angsuran `Sudah Dibayar`, pembiayaan `Lunas`, `jenis_pembayaran = tunai`.
- Autodebet saldo cukup: saldo rekening turun tepat, sisi debet jurnal memakai
  `2-1001` (Simpanan Sukarela), `jenis_pembayaran = autodebet`.
- Autodebet yang menyisakan saldo di bawah `batas_minimum` → **ditolak**;
  saldo utuh, `tanggal_bayar` tetap `null`.
- Autodebet **tidak** menambah `cash_in` laci teller (saldo laci tetap), baik
  pada `hitungSaldoKasTeller()` maupun `ringkasanPosisiKas()`.
- Kontrol positif: pembayaran tunai **memang** menambah `cash_in` laci sebesar
  uang yang diterima.
- Ta'zir dikreditkan ke `2-3001` (Dana Kebajikan) sebesar nominal diterima dan
  **tidak pernah** ke akun pendapatan kelas 4 (PSAK 109); jurnal tetap balance.

## Batch 6 — Integritas status pembiayaan pada pembayaran angsuran

Berkas: `tests/Feature/IntegritasStatusPembiayaanAngsuranTest.php`
(10 test / 41 assertion, termasuk data provider).

Batch ini menuntaskan kandidat temuan yang dicatat pada akhir batch 5:
`status_pembiayaan` tidak pernah diperiksa saat angsuran dibayar.

### Temuan A-06 (baru, terkonfirmasi) — pembayaran diterima pada pembiayaan yang tidak berjalan

`AngsuranTransactionService::proses()` hanya memeriksa status **angsuran**
(`isSudahDibayar()`, `Dibayar Sebagian` di `BayarAngsuranAction`) dan
`tanggal_bayar !== null` di `AutodebitService::eksekusiAngsuran()`. Status
**pembiayaan** — induk kontraknya — tidak pernah dilihat.

Dikonfirmasi lewat test sebelum diperbaiki. Seluruh status berikut tetap
menerima pembayaran: `lunas`, `macet`, `ditolak`, `dibatalkan`, `pending_ao`
(pra-pencairan), dan `pending_dps` (pra-pengesahan akad). Pada jalur autodebet
pembiayaan `macet`, saldo Simpanan Sukarela anggota **benar-benar dipotong**
Rp 125.000 dan kas laci teller bertambah walau tidak ada uang tunai.

Dampaknya bukan hanya pembukuan salah: pembayaran pada kontrak `lunas` atau
`dibatalkan` menciptakan **penerimaan tanpa dasar akad**, mengurangi
`sisa_pembiayaan` yang seharusnya sudah nol, serta dapat mengubah status
kembali ke `lunas` sehingga menyamarkan penyimpangan. Pembayaran pra-pencairan
mencatat pelunasan atas piutang yang belum pernah diakui.

### Perbaikan

Satu gerbang di titik pusat — `AngsuranTransactionService::proses()` — karena
kedua jalur (tunai via `BayarAngsuranAction`, autodebet via `AutodebitService`)
melewati service ini:

```php
if (! in_array($pembiayaan->status_pembiayaan, Pembiayaan::statusBerjalan(), true)) {
    throw new \Exception('Pembayaran angsuran ditolak: pembiayaan ... berstatus "..." ...');
}
```

Tidak ada literal status baru: daftar sah diambil dari
`Pembiayaan::statusBerjalan()` — *satu-satunya sumber kebenaran* status
"berjalan" (7 status). Ini sekaligus memperkecil temuan turunan yang sempat
dicatat batch 5 (perbedaan daftar status antara `TellerController::angsuranForm()`,
`RunAutodebetAngsuran`, dan model).

Gerbang ditaruh **sebelum** `DB::transaction` sehingga penolakan tidak
meninggalkan transaksi, jurnal, maupun baris `dana_kebajikan`.

### Perilaku yang dikunci

- Pembayaran **tunai** pada pembiayaan `lunas`, `macet`, `ditolak`,
  `dibatalkan`, `pending_ao`, `pending_dps` → **ditolak**; tidak ada baris
  `transaksi`, `sisa_pembiayaan` utuh, `tanggal_bayar` tetap `null`.
- Pembayaran **autodebet** pada pembiayaan `macet` → **ditolak**; saldo
  rekening utuh, `cash_in` laci teller tetap 0.
- Kontrol positif: `aktif`, `dicairkan`, dan `restrukturisasi` **tetap dapat
  dibayar** — angsuran `Sudah Dibayar`, `sisa_pembiayaan` 0, pembiayaan
  otomatis `lunas`. Ini mencegah gerbang menjadi terlalu ketat.

### Catatan terbuka (belum diuji, di luar lingkup batch ini)

- `TellerController::angsuranForm()` masih memakai 4 status hardcoded dan
  `RunAutodebetAngsuran` 4 status dengan susunan berbeda dari
  `Pembiayaan::statusBerjalan()` (7 status). Daftar **penampilan** form tidak
  lagi berbahaya (pembayaran di luar status berjalan kini ditolak service),
  tetapi ketidakkonsistenan tampilan masih utang teknis.

## Batch 7 — Gerbang kelayakan Exit Keanggotaan

Modul Exit Keanggotaan adalah satu-satunya modul inti yang belum memiliki
berkas test sama sekali, padahal `ExitKeanggotaanService::pastikanLayakExit()`
adalah gerbang terakhir sebelum dana ekuitas anggota (Simpanan Pokok &
Simpanan Wajib) dikembalikan. Berkas baru:
`tests/Feature/IntegritasExitKeanggotaanTest.php`.

### Temuan A-07 — FALSE POSITIVE (tidak diperbaiki, dikunci sebagai regresi)

Kandidat temuan: `eksekusi()` menghitung `rincianSaldo()` **di luar**
`DB::transaction` lalu memakai angka pratinjau tersebut tanpa hitung ulang —
kelas bug TOCTOU yang dulu nyata di `ZakatService`.

Test `test_pencairan_exit_memakai_saldo_terbaru_bukan_pratinjau` menyusun
skenario: `ajukan()` menghasilkan pratinjau total Rp 250.000, lalu saldo
Simpanan Wajib dikurangi Rp 50.000 lewat jurnal koreksi (meniru penarikan
modal setelah pengajuan), baru kemudian `eksekusi()` dijalankan.

Hasil: `total_dikembalikan = Rp 200.000` — sesuai saldo **saat eksekusi**.
Artinya `eksekusi()` **sudah** memakai saldo terkini dan **tidak ada bug**.
Tidak ada perubahan kode produksi pada batch ini; test dipertahankan sebagai
*regression lock* agar perilaku benar tersebut tidak mundur.

### Perilaku yang dikunci

- **G1 — pembiayaan berjalan:** anggota dengan pembiayaan `aktif`
  (sisa Rp 2.000.000) → `layakExit()` false, `kendalaExit()` tidak kosong,
  `pastikanLayakExit()` melempar `RuntimeException`.
- **G2b — tunggakan pada kontrak berjalan:** satu angsuran `Terlambat` pada
  pembiayaan `aktif` → gerbang `kendalaAngsuran` ikut menyala bersama
  `kendalaPembiayaan` (pertahanan berlapis), exit diblokir.
- **G2 — tunggakan pada kontrak `hapus_buku`:** status bukan berjalan dan
  `sisa_pembiayaan = 0` sehingga `isLunas()` true, **tetapi** masih ada
  angsuran terbuka → exit tetap diblokir. Ini menutup celah "lunas di atas
  kertas, menunggak di bawah".
- **G3 — A-07:** pencairan memakai saldo saat eksekusi, bukan pratinjau.
- **Kontrol positif:** anggota tanpa pembiayaan & tanpa tunggakan →
  `layakExit()` true (mencegah gerbang terlalu ketat).

### Catatan terbuka

- `kendalaAngsuran` hanya memindai pembiayaan yang **berjalan**. Untuk status
  `macet` dan `hapus_buku`, perlindungan tunggakan bergantung pada
  `kendalaPembiayaan` (status dianggap berjalan) dan `kendalaPenjamin`.
  Kandidat pemeriksaan pada batch berikutnya: apakah tunggakan pada
  pembiayaan `dibatalkan`/`ditolak` memang sengaja tidak dipindai.

### Insiden gate — kegagalan lingkungan (bukan bug)

Gate pertama batch 7 berakhir **MERAH** (exit 1) pada
`PemeliharaanSistemTest::test_pemantauan_operasional_melaporkan_seluruh_indikator_sehat`.
Penyebabnya bukan kode:

```
[GAGAL] Cadangan: terbaru berumur 41.4 jam (ambang 26 jam)
```

Berkas cadangan terakhir bertanggal 28 Okt 2026 15:06, sehingga melampaui
ambang RPO 26 jam. Tindakan: `php artisan db:cadangkan` (berhasil,
`koperasi_syariah-20261030-083335.sql.gz.enc`, 51.7 KB, terenkripsi), lalu
`denyut:pemeriksaan` → **SELURUH INDIKATOR SEHAT**. Gate diulang dan hijau.

Catatan utang teknis: test ini bergantung pada artefak cadangan nyata di disk,
sehingga gate akan merah setiap kali jadwal cadangan harian tertunda —
sebaiknya diisolasi ke direktori sementara seperti test lain di berkas yang
sama.

## Batch 8 — Kebijakan "tidak ada auto-debit tanpa konfirmasi pengguna"

Kebijakan induk sistem: `Setting::autodebitWajibKonfirmasi()` (default **AKTIF**)
melarang SELURUH kanal auto-debit (Simpanan Wajib, Angsuran, Ta'zir) memotong
saldo secara mandiri. Jalur yang sah: scheduler hanya **mengusulkan**
(`AutodebitUsulan`) → pengguna berwenang mengonfirmasi
(`AutodebitService::konfirmasi()`) → baru dipotong.

Berkas uji: `tests/Feature/IntegritasKebijakanAutodebitTest.php` (2 test).

### Temuan

| # | Temuan | Bukti (sebelum perbaikan) | Status |
|---|---|---|---|
| C1 | `MandatorySavingsService::autoDebit()` hanya memeriksa otorisasi produk & anggota, **tidak** memeriksa kebijakan induk. Pemanggilan langsung (seeder/command/scheduler lama) memotong saldo tanpa konfirmasi. | Saldo Sukarela 1.000.000 → **800.000** setelah pemanggilan langsung; `autoDebit()` mengembalikan `true`. | **BUG NYATA — diperbaiki** |
| C2 | `MandatorySavingsService::bayarCicilan()` (endpoint `POST /simpanan-wajib/{tagihan}/cicilan`) **tidak** memanggil `bolehAutoDebit()`, sehingga anggota yang menolak auto-debit (`is_auto_debit = false`) tetap dipotong. | Saldo 1.000.000 → **900.000** untuk anggota penolak auto-debit; hasil `ok = true`. | **BUG NYATA — diperbaiki** |

C2 lebih berat karena mengabaikan penolakan eksplisit anggota.

### Perbaikan (minimal)

1. `MandatorySavingsService::autoDebit()` — parameter baru
   `bool $viaKonfirmasi = false`. Bila `! $viaKonfirmasi && Setting::autodebitWajibKonfirmasi()`,
   pemotongan **ditolak** (`return false`) + `Log::warning`. Hanya dua pemanggil
   yang sah melewati gerbang: `AutodebitService::eksekusiSimpananWajib()` (eksekusi
   usulan yang sudah dikonfirmasi) dan `MandatorySavingsService::konfirmasiPenangguhan()`
   (konfirmasi manual petugas). Keduanya kini mengirim `true` secara eksplisit.
2. `MandatorySavingsService::bayarCicilan()` — gerbang `bolehAutoDebit($anggota)`
   sebelum pemotongan, dengan catatan tindakan `TIDAK_BERHAK_AUTO_DEBIT` dan
   kembalian `['ok' => false, ...]` agar tagihan dilunasi lewat jalur tunai Teller.

Penyesuaian fixture test lama `MandatorySavingsPenangananDinamisTest`:
`test_cicilan_memotong_saldo_sukarela_dan_mencatat_riwayat` kini memakai anggota
dengan `is_auto_debit = true` (sebelumnya `false`), karena cicilan dari saldo
Sukarela memang berstatus auto-debit dan fixture lama memanfaatkan bug C2.

### Perilaku yang dikunci

- Pemanggilan langsung `autoDebit()` saat kebijakan aktif → saldo utuh, `false`.
- `bayarCicilan()` untuk anggota penolak auto-debit → saldo utuh, `ok = false`.
- Jalur konfirmasi (`AutodebitService::konfirmasi()` dan
  `konfirmasiPenangguhan()`) tetap memotong normal — diverifikasi
  `AutodebitKonfirmasiTest` (15 test) dan `MandatorySavingsPenangananDinamisTest`.

Regression batch: `IntegritasKebijakanAutodebitTest`, `AutodebitKonfirmasiTest`,
`MandatorySavingsPenangananDinamisTest`, `MandatorySavingsSimpananWajibTest`,
`SimpananWajibPenarikanTest`, `SimpananWajibKonfigurasiDinamisTest`,
`ValidasiKepatuhanSimpananWajibTest`, `JatuhTempoSimpananWajibOtomatisTest` →
**97 passed / 406 assertions**.

## Batch 9 — Kebijakan autodebit pada kanal Angsuran & Ta'zir

Batch 8 menutup kanal Simpanan Wajib. Kebijakan induk yang sama berlaku untuk
kanal **Angsuran** dan **Ta'zir**; batch ini menguji apakah kedua kanal itu juga
menolak pemotongan saldo tanpa konfirmasi pengguna.

Berkas uji: `tests/Feature/IntegritasKebijakanAutodebitKanalTest.php` (3 test).

### Temuan

| # | Temuan | Bukti (sebelum perbaikan) | Status |
|---|---|---|---|
| D1 | `AngsuranTransactionService::proses()` dengan `metode_bayar = 'AUTODEBET'` langsung memotong Simpanan Sukarela, tanpa memeriksa kebijakan induk. Pemanggil langsung (tinker, command, kode baru) dapat mengambil dana anggota. | Saldo Sukarela 1.000.000 → **875.000** setelah `proses()` langsung; tanpa penolakan. | **BUG NYATA — diperbaiki** |
| D2 | `TazirService::prosesAutodebetSusulan()` memotong saldo hanya dengan syarat saklar fitur denda aktif; kebijakan wajib konfirmasi tidak diperiksa. | Saldo 1.000.000 → **950.000** (Rp 50.000 terpotong) meski belum ada usulan/konfirmasi. | **BUG NYATA — diperbaiki** |

D2 khusus berbahaya karena ta'zir adalah denda: memotongnya otomatis membuat
anggota dikenai sanksi dana tanpa persetujuan, di samping melanggar kebijakan
induk.

### Perbaikan (minimal)

1. `AngsuranTransactionService::proses()` — gerbang setelah pembacaan
   `metode_bayar`: bila `AUTODEBET`, `via_konfirmasi` opsi bukan `true`, dan
   `Setting::autodebitWajibKonfirmasi()` aktif → lempar exception berisi arahan
   mengajukan usulan lewat Pusat Konfirmasi Auto-Debit. Gerbang A-06 (status
   pembiayaan) tetap dijalankan lebih dulu.
2. `TazirService::prosesAutodebetSusulan()` — parameter baru
   `bool $viaKonfirmasi = false`; bila `false` dan kebijakan aktif → `Log::warning`
   + `return 0.0` (tidak ada dana dipotong).
3. `AutodebitService` sebagai satu-satunya jalur sah kini mengirim penanda
   konfirmasi: `'via_konfirmasi' => true` pada opsi `proses()` di
   `eksekusiAngsuran()`, dan argumen keempat `true` pada
   `prosesAutodebetSusulan()` di `eksekusiTazir()`.

Kontrol positif `test_konfirmasi_usulan_angsuran_tetap_memotong_saldo`
membuktikan jalur sah tetap bekerja: `usulkan()` → `konfirmasi()` memotong
saldo 1.000.000 → 875.000.

Penyesuaian dua fixture test lama di `IntegritasPembayaranAngsuranTest`
(`test_autodebet_saldo_cukup_...`, `test_autodebet_tidak_menambah_kas_fisik_laci_teller`)
yang selama ini memakai jalur autodebet langsung: kini menambahkan
`'via_konfirmasi' => true` karena yang diuji adalah perilaku pasca-konfirmasi
(akun 2-1001 dan ketiadaan kas fisik), bukan jalan pintas tanpa konfirmasi.

### Perilaku yang dikunci

- `proses()` langsung dengan `AUTODEBET` saat kebijakan aktif → **exception**,
  saldo utuh.
- `prosesAutodebetSusulan()` tanpa `$viaKonfirmasi` → `0.0`, saldo utuh,
  `is_tazir_paid` tetap `false`.
- `AutodebitService::konfirmasi()` (Angsuran & Ta'zir) tetap memotong normal.

Regression batch: `IntegritasPembayaranAngsuranTest`,
`IntegritasStatusPembiayaanAngsuranTest`, `AutodebitKonfirmasiTest`,
`BayarAngsuranFormTest`, `AngsuranSisaKewajibanTest`,
`AngsuranStatusTurunanTest`, `IntegritasKebijakanAutodebitTest`,
`IntegritasKebijakanAutodebitKanalTest`,
`MandatorySavingsPenangananDinamisTest` → **87 passed / 440 assertions**.

## Batch 10 — Gerbang PENJAMIN pada Exit Keanggotaan

`ExitKeanggotaanService::kendalaPenjamin()` adalah **satu-satunya** pembaca
tabel `pembiayaan_penjamin` di seluruh aplikasi (diverifikasi dengan
`Select-String` pada `app/`). Gerbang ini menentukan apakah seorang anggota
boleh keluar dan menerima kembali ekuitas Simpanan Pokok/Wajib padahal ia
masih menjamin pembiayaan anggota lain — sehingga salah nyala berarti hak
anggota tertahan, sedangkan salah mati berarti koperasi kehilangan jaminan.

Berkas uji: `tests/Feature/IntegritasPenjaminanExitTest.php` (22 test).

### Gerbang yang diuji

| # | Skenario | Harapan |
|---|---|---|
| G1 | Penjamin kontrak AKTIF sehat (tanpa tunggakan > 3 bulan) | tidak memblokir |
| G2 | Penjamin kontrak MACET / diragukan / hapus buku | memblokir |
| G3 | Penjamin kontrak berjalan dengan tunggakan > 3 bulan | memblokir + mencantumkan tanggal tunggakan |
| G4 | Penjaminan pada kontrak LUNAS / DITOLAK / DIBATALKAN | tidak memblokir |
| G5 | (E-01) Penjaminan pada pengajuan PRA-PENCAIRAN | tidak memblokir |

### Temuan

| # | Temuan | Bukti (sebelum perbaikan) | Status |
|---|---|---|---|
| E-01 | `kendalaPenjamin()` menyaring dengan daftar "kecualikan lunas/ditolak/dibatalkan". Akibatnya pengajuan yang **belum dicairkan** (`pending_ao`, `pending_manajer`, `pending_pengurus`, `pending_dps`, `direview`, `disetujui`, `disahkan`, `pending_pencairan`) ikut menahan penjamin keluar, meski belum ada dana yang disalurkan dan belum ada risiko yang dijamin. | 16 test G5 (`8 status × 2 skenario`) gagal: `assertSame([], $kendalaPenjamin)` → array berisi pesan "Anggota terdaftar sebagai PENJAMIN…". Skenario lapangan: debitur punya pinjaman AKTIF + ajuan KEDUA pra-pencairan; penjamin ditahan oleh ajuan yang belum cair. | **BUG NYATA — diperbaiki** |

G1–G4 dan gerbang bermasalah sudah benar sejak awal — dikunci oleh test agar tidak
mundur.

### Perbaikan (minimal)

`kendalaPenjamin()` — filter diubah dari "kecualikan 3 status" menjadi
**whitelist** `Pembiayaan::statusTercair()` (dicairkan, aktif, macet,
restrukturisasi, pending_restrukturisasi) ditambah dua status bermasalah
`hapus_buku` dan `diragukan`:

```php
$statusMenahanPenjamin = array_values(array_unique(array_merge(
    Pembiayaan::statusTercair(),
    [Pembiayaan::STATUS_HAPUS_BUKU, 'diragukan'],
)));
```

`statusTercair()` dipilih (bukan `statusBerjalan()`) karena `statusBerjalan()`
justru memuat tiga status pra-pencairan; keduanya diverifikasi via
`php artisan tinker` sebelum perbaikan. `hapus_buku` & `diragukan` sengaja
ditambahkan kembali karena keduanya adalah alasan utama penjamin ditahan dan
tidak tercakup `statusTercair()` (hapus buku sudah di-derecognize dari neraca).

Catatan desain: arah filter kini **fail-safe**. Status baru yang belum
diklasifikasikan tidak akan otomatis menahan penjamin; ia baru berperan setelah
didaftarkan pada `statusTercair()`/daftar bermasalah.

### Perilaku yang dikunci

- Kontrak pra-pencairan tidak pernah memblokir penjamin (8 status × 2 skenario).
- Kontrak macet / diragukan / hapus buku selalu memblokir, dan pesan kendala
  menyebut nomor pembiayaan yang dijamin.
- Tunggakan > 3 bulan memblokir dan menyebut tanggal tunggakan tertua.
- Penjamin kontrak aktif sehat tetap layak exit.

Regression batch: `ExitApprovalWorkflowTest`, `ExitDokumenCetakTest`,
`ExitEndToEndTest`, `ExitKeanggotaanTest`, `IntegritasExitKeanggotaanTest`,
`IntegritasPenjaminanExitTest`, `CkpnHapusBukuTest`,
`PenguncianEkuitasPenarikanTest` → **93 passed / 500 assertions**.

## Batch 11 — Modul Ta'zir utuh (saklar, grace, mode, denda ganda, keringanan, jurnal tunggakan)

Modul ta'zir adalah modul paling sensitif secara syariah: denda tidak boleh
menjadi pendapatan koperasi (PSAK 109 & Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000),
dan harus benar-benar berhenti saat pengurus mematikannya. Matrix berkas:
`tests/Feature/IntegritasTazirTest.php` (11 test, T1–T7 + F-01…F-05).

### Temuan

| Kode | Temuan | Bukti |
|---|---|---|
| F-01 | `postJurnal()` hanya membukukan `nominal_tazir`, bukan ta'zir + tunggakan, padahal sisi debet kas sudah memuat keduanya → pelunasan tunggakan ta'zir **selalu** gagal `JURNAL_TIDAK_BALANCE` sebesar tunggakan | test pelunasan tunggakan |
| F-02 | `proses()` mengabaikan `TazirService::fiturAktif()`: ta'zir **dan tunggakan lama** tetap ditagih walau pengurus mematikan fitur | test saklar nonaktif |
| F-03 | Margin diakui (amortisasi 1-2101 → pendapatan) walau uang yang diterima habis untuk pokok | `test_margin_tak_tertagih_tidak_diakui_sebagai_pendapatan` |
| F-04 | `postJurnal()` memakai tagihan bruto alih-alih porsi **terbayar**, sehingga `jumlah_bayar` dipakai membayar ulang margin yang sudah dibayar → piutang dikredit ganda | F-01 assert piutang |
| F-05 | `alokasiPembayaran()` mengembalikan ta'zir **ditagih** ke variabel `$tazirTerbayar`, lalu nilai itu dipakai sebagai `nominal_tazir` di `postJurnal()` → tunggakan terhitung **dua kali** (Dana Kebajikan Rp 80.000 atas kas Rp 160.000) | diagnostik `nominal=40000 tunggakan=40000` |
| F-06 | (regresi yang ditangkap regression test) Perbaikan F-02 sempat memaksa `nominal_tazir` eksplisit dari pemanggil menjadi 0 saat saklar default mati → ta'zir Rp 5.000 lenyap dari `dana_kebajikans` | `AngsuranSisaKewajibanTest` & `IntegritasPembayaranAngsuranTest` |

Catatan penting: F-04 membuktikan bahwa jurnal sebelumnya **balance secara
aritmetika tetapi salah sebaran** — kelebihan kredit ditampung otomatis oleh
penyeimbang `PostingJurnal` ke Dana Kebajikan (2-3001). Ketika sisi kredit
diperbaiki, ketidakseimbangan yang sebenarnya baru muncul sebagai
`JURNAL_TIDAK_BALANCE debet=220.000 kredit=180.000`, lalu ditutup oleh F-05.

### Perbaikan (minimal)

Semua di `app/Services/AngsuranTransactionService.php`:

1. **F-01** — parameter ke-12 `postJurnal()` bernama `$tunggakanTazir`;
   `$totalTazir = $nominalTazir + max(0.0, $tunggakanTazir)` dan
   `$total = $uangAngsuran + $totalTazir`.
2. **F-02** — saklar hanya mematikan **tunggakan** (`$tunggakanTazir = 0`).
   Nominal ta'zir yang dikirim **eksplisit** oleh pemanggil tetap dihormati
   karena jalur UI/Action sudah melewati saklar dan uang telah disepakati kas.
   Test T7 (`saklar nonaktif menutup penagihan tunggakan ta'zir`) tetap hijau.
3. **F-03** — urutan alokasi uang angsuran dibalik menjadi **pokok lalu
   margin**. Margin hanya diakui sebesar sisa uang yang benar-benar ada.
4. **F-04** — `$uangAngsuran = min($jumlah_bayar, pokok + margin)` dipakai
   sebagai kas masuk porsi angsuran; `$porsiPiutang = $pokok + $margin`
   (porsi **terbayar**). Untuk akad bermargin (Murabahah/Ijarah, ada akun
   kontra 1-2101) piutang bruto memang sebesar pokok+margin sehingga
   dikredit penuh; untuk Qardh/bagi hasil tanpa akun kontra, piutang hanya
   sebesar pokok. Tanpa pemisahan ini akad Qardh terlihat gagal padahal
   konvensinya piutang = pokok.
5. **F-05** — `postJurnal()` menerima `$tazirPeriodeBerjalan` (bukan
   `$tazirTerbayar` = total ditagih) sebagai argumen `nominal_tazir`, dan
   `$tunggakanTazirTerbayar` sebagai argumen `tunggakanTazir`.
6. **F-06** — pemanggil eksplisit tidak lagi dinolkan (lihat butir 2).

### Perilaku yang dikunci

- Tarif 0 atau `tazir_mode = DISABLED` → ta'zir 0.
- Keterlambatan di dalam `tazir_grace_period_days` tidak dikenai ta'zir;
  di luar grace dihitung hanya pada hari efektif.
- `FIXED_PER_KEJADIAN` tidak bertambah walau makin terlambat; kalkulasi
  berulang tidak menumpuk; angsuran lunas bebas ta'zir.
- Keringanan `APPROVED` memaksa ta'zir 0 walau klien mengirim nominal.
- Pelunasan tunggakan ta'zir: seluruh ta'zir diterima masuk kredit 2-3001
  **tepat sebesar** nominalnya, kas didebet tepat uang diterima, jurnal
  balance, dan baris tunggakan ditandai `is_tazir_paid`.
- Margin yang belum tertagih **tidak** diakui sebagai pendapatan.

Regression modul ta'zir/angsuran (14 berkas): **127 passed / 516 assertions**.

## Batch 12 — Modul Restrukturisasi (3R)

`RestrukturisasiService` sebelumnya **tidak memiliki test sama sekali**, padahal
ia mengubah jadwal kewajiban anggota dan mengonsumsi
`AngsuranTransactionService::alokasiPembayaran()` yang urutannya baru diubah
pada batch 11. Matriks: `tests/Feature/IntegritasRestrukturisasiTest.php`
(9 test, R1–R6).

### Temuan

| Kode | Temuan | Bukti |
|---|---|---|
| F-07 | `ringkasan()` menyimpulkan **margin terakui = `total_bayar − denda`**. Setelah alokasi diubah menjadi POKOK → MARGIN, uang habis untuk pokok tetap dihitung sebagai margin terakui → sisa margin (dan sisa piutang) **lebih kecil** dari kewajiban sesungguhnya; jadwal restrukturisasi dibentuk dari dasar yang salah | `test_sisa_margin_mengikuti_urutan_alokasi_pokok_didahulukan` (`200000.0` vs `20000.0`), `test_total_piutang_ringkasan_sama_dengan_sisa_komitmen_akad` (`1080000.0` vs `1100000.0`) |
| F-08 | Baris angsuran berstatus `Sudah Dibayar` (lunas) hanya karena **pokok** tertagih, walau margin belum tertagih → sisa piutang baris itu hilang dari laporan & CKPN, dan peristiwa `AngsuranLunas` dikirim premature | `test_angsuran_lunas_tidak_dikirim_saat_margin_belum_tertagih` (`−'Dibayar Sebagian' +'Sudah Dibayar'`) |

Kedua bug berasal dari akar yang sama: **alokasi baru (batch 11) tidak
diikuti oleh seluruh pembaca data angsuran.**

### Perbaikan (minimal)

1. **F-08** — `AngsuranTransactionService`: `$marginKurang` dihitung di samping
   `$pokokKurang`; status `Sudah Dibayar` hanya bila
   `$pokokKurang <= 0 && $marginKurang <= 0`, selain itu
   `Dibayar Sebagian` dengan catatan sisa margin. Peristiwa `AngsuranLunas`
   digerbangi `$lunasPenuh` dengan makna yang sama (dikirim di luar
   transaksi DB, dibungkus `try/catch` agar kegagalan listener tidak
   menggagalkan pembayaran).
2. **F-07** — `RestrukturisasiService::marginBelumTerakui()` baru menghitung
   margin belum terakui dari **data tersimpan** (`pokok_angsuran`,
   `sisa_pokok`, `total_bayar`, `denda`) memakai urutan **POKOK → MARGIN**
   yang sama dengan `alokasiPembayaran()`; `ringkasan()` memakainya dan
   komentar usang (masih menyebut urutan Ta'zir → Margin → Pokok) diperbaiki.

### Perilaku yang dikunci

- Rescheduling tidak menambah total piutang (patuh Fatwa DSN-MUI No. 49).
- Total piutang `ringkasan()` = sisa komitmen akad setelah pembayaran.
- AO pengaju tidak boleh menyetujui pengajuannya sendiri
  (`PemisahanTugas`); pengajuan yang sudah diputus tidak dapat diputus lagi.
- Penolakan tidak mengubah jadwal angsuran lama.
- Selisih pembulatan dibebankan seluruhnya pada angsuran terakhir.

Regression batch (14 berkas): **164 passed / 525 assertions**.
Test batch: **9 passed / 21 assertions**.

## Batch 13 — Modul Pelaporan PPATK (LTKT / LTKM / STR)

`PpatkReportService` — inti kewajiban pelaporan APU-PPT (UU No. 8/2010 &
POJK No. 27/POJK.03/2022) — sebelumnya **tidak memiliki satu pun test**,
padahal berkas CSV-nya diunduh Pengurus dan diserahkan ke PPATK melalui
goAML. Kesalahan di sini berarti koperasi melaporkan transaksi yang tidak
pernah terjadi, atau gagal melaporkan transaksi yang wajib dilaporkan.
Matriks: `tests/Feature/IntegritasPelaporanPpatkTest.php` (11 test, P1–P8).

### Temuan

| Kode | Temuan | Bukti |
|---|---|---|
| G-01 | **Penolakan AML kedua atas transaksi berstatus terminal** (`Gagal`/`Voided`/`Pending Void`) tetap menjalankan `ReversalService::reverseTransactionEffect()`. Untuk transaksi posisi `Kredit`, pembalikan justru **menambah** saldo tanpa penjagaan → saldo rekening anggota membengkak, disertai pesan sukses palsu *"dana telah dikembalikan dan saldo rekening dipulihkan"*. Penjagaan lama hanya memeriksa `aml_status_verifikasi === 'ditolak'` | `test_penolakan_aml_tidak_boleh_mengulang_transaksi_terminal` — saldo `1.000.000` → `601.000.000` |
| G-02 | Kartu **"Total High Value"** pada halaman kepatuhan AML tidak menghitung `is_large_transaction`, sedangkan daftar di halaman yang sama menghitungnya → angka pengawasan PPATK **lebih kecil** dari daftar yang sedang dilihat Pengurus | `test_kartu_total_high_value_konsisten_dengan_daftar` (`0` vs `1`) |

### Perbaikan (minimal)

1. **G-01** — Helper `pesanStatusTerminal(Transaksi): ?string` di
   `AmlComplianceController` mengembalikan pesan penolakan bila
   `status_transaksi` sudah terminal; dipakai **kedua** jalur keputusan
   (`verify()` dan `tolakAml()`) **sebelum** penjagaan `'ditolak'`. Dengan
   begitu status kepatuhan tidak dapat diubah lagi dan pembalikan
   dana/jurnal berganda tidak mungkin terjadi.
2. **G-02** — Predikat `total_high_value` disamakan persis dengan predikat
   daftar: `jumlah >= ambang OR is_aml_high_value = 1 OR is_large_transaction = 1`
   (satu kueri agregat tetap dipertahankan).

### Cacat test (bukan bug produksi)

- **P3.** Test awalnya menulis `ppatk_jenis_tunai` sebagai **array** →
  tersimpan JSON `'["Setoran Tunai","Pencairan Pembiayaan"]'`, sementara
  `Setting::daftar()` memecah nilai dengan `explode(',')` (format resmi
  `PengurusController` adalah **string ber-koma**). Hasil pembacaan menjadi
  item mentah `'["Setoran Tunai"'` dan `'"Pencairan Pembiayaan"]'`.
  Dibuktikan lewat skrip diagnostik sementara, lalu test dikoreksi memakai
  string ber-koma. **Tidak ada perubahan kode produksi** untuk P3.

### Pelajaran isolation (kegagalan gate pertama)

Gate penuh pertama batch ini **MERAH**: `DashboardDpsPendapatanTest >
kategori akad konsisten dengan pengelompokan angka` (`'margin'` vs
`'non_pendapatan'`). Penyebabnya **polusi lintas-berkas**: test batch ini
menulis ke baris **singleton** `kebijakan_koperasis` yang dibaca `Setting::`
oleh seluruh modul; nilai `akad_margin` pada basis data uji tertinggal
terpotong (`'Murabahah,Ijarah Muntahia Bittamlik'`) sehingga
`PendapatanService::kategoriAkad('Istishna')` berubah kategori. Basis data
produksi terverifikasi bersih.

Perbaikan isolation (test-only): `setUp()` menyimpan nilai asli
`ppatk_jenis_tunai` & `akad_margin` beserta ID baris, `tearDown()`
memulihkannya **tanpa syarat** (termasuk saat `markTestSkipped`) lalu
`Setting::flush()`. Setelah perbaikan ini, menjalankan berkas test tidak lagi
mengubah baris kebijakan basis data uji (dibuktikan lewat pemeriksaan
langsung sebelum/sesudah).

### Perilaku yang dikunci

- Periode laporan inklusif di kedua ujung; baris di luar periode tidak bocor.
- LTKT hanya memuat transaksi tunai ber-nominal ≥ ambang; transaksi non-tunai
  yang lebih besar **tidak** masuk LTKT.
- Jenis transaksi tunai dibaca dari kebijakan (`Setting`), bukan daftar literal.
- LTKM memuat penanda AML (`is_aml_high_value` / `is_large_transaction`) dan
  nominal ≥ ambang AML; transaksi biasa tidak ikut.
- STR hanya memuat transaksi yang **sudah diputus** kepatuhan
  (`terverifikasi`/`ditolak`); yang masih `menunggu` tidak terkirim.
- CSV: kop identitas pelapor, judul kolom per jenis, jumlah baris
  = 5 kop + 1 kosong + 1 judul + N data.
- Transaksi berstatus terminal tidak dapat diputus atau dibalik ulang (G-01).
- Kartu statistik AML selalu sama dengan jumlah baris daftar (G-02).

Test batch: **11 passed / 39 assertions**.
Regression terdampak (`DashboardDpsPendapatan`, `AuditPerformaDanUx`,
`KebijakanFormMetadata`, `KebijakanKontrakParameter`): **43 passed / 167 assertions**.

## Batch 14 — Integritas jalur AO

Dua rute `ao.*` ditemukan **tanpa sebutan test** pada peta rute→test (364
rute bernama, 138 tanpa sebutan). Keduanya menyentuh data sensitif:
verifikasi Keringanan Ta'zir dan pendaftaran anggota cepat.
Matriks: `tests/Feature/IntegritasAORelevansiTazirTest.php` (9 test).

### Temuan

| Kode | Temuan | Bukti |
|---|---|---|
| R-01 | `KeringananTazirController::rekomendasi()` menggeser `PENDING_AO → PENDING_MANAJER` **tanpa** memeriksa pemisahan tugas (maker ≠ checker). AO yang mengajukan keringanan dapat memverifikasi pengajuannya sendiri. Jalur setara `DepositoPenaltyReliefController::rekomendasi()` sudah memanggil `PemisahanTugas::dilanggar()` — jalur ta'zir tidak | `test_rekomendasi_ditolak_bila_ao_adalah_pengaju_yang_sama` (`-'PENDING_AO'` / `+'PENDING_MANAJER'`, `ao_id` terisi) |
| B-02a | `AOController::quickStoreAnggota()` tidak memvalidasi/mengisi `kota` & `provinsi` — keduanya kolom **NOT NULL tanpa default** di tabel `anggota`. NIK baru yang lolos validasi menabrak `QueryException` → HTTP 500 dan anggota **tidak terbentuk sama sekali** | `test_pendaftaran_cepat_mengisi_kolom_wajib_tabel_anggota` (`Field 'kota' doesn't have a default value`) |
| B-02b | Endpoint AJAX murni ini tidak menetapkan `expectsJson()`. Saat galat validasi, klien menerima **redirect 302** (HTML) alih-alih 422 + galat per-field. Terbukti: respons pertama JSON `{"errors":{"nik":…}}` bila `Accept: application/json`, tetapi 302 bila tidak | diagnosis langsung pada dua request — `DIAG2 status=302 errors=[…]` vs `DIAG3 status=422` |
| B-02c | Berkas KTP ditulis ke disk `dokumen` **sebelum** baris anggota dibuat; bila pembuatan baris gagal, dokumen identitas (UU PDP 27/2022) tertinggal yatim | `test_pendaftaran_cepat_gagal_…` awal (`+ 'ktp/wEfR1NiREE3…jpg'`) |
| A-02 | **FALSE POSITIVE.** Dugaan izin `ao.pembiayaan` juga dimiliki Manajer/Pengurus salah. `config/izin.php` baris 38 → `['ao']` saja; diukur `manajer/pengurus/teller` semuanya HTTP 403 dan status tetap `PENDING_AO / ao_id=NULL`. Gerbang hierarki yang sempat ditambahkan **dibatalkan sendiri** | `test_peran_selain_ao_tidak_dapat_memproses_tahap_ao` |

### Perbaikan

1. `KeringananTazirController::rekomendasi()` — gerbang
   `PemisahanTugas::dilanggar($relief->teller_id, Auth::id())` sebelum
   transisi status (R-01).
2. `AOController::quickStoreAnggota()` —
   (a) validasi `kota` & `provinsi` (`required|string|max:100`) dan ikut
   disimpan (B-02a);
   (b) `$request->headers->set('Accept', 'application/json')` agar galat
   validasi selalu 422 + JSON (B-02b);
   (c) berkas KTP dihapus via `Storage::disk('dokumen')->delete()` bila
   `Anggota::create` melempar (B-02c).

### Perilaku yang dikunci

- Pengajuan yang diajukan sendiri tidak lolos ke Manajer oleh pengaju yang sama.
- Pengajuan yang sudah melewati tahap AO tidak dapat direkomendasikan ulang.
- Peran selain `ao` tidak dapat membuka maupun memproses tahap AO (403).
- NIK duplikat ditolak lewat blind index `nik_hash` (UU PDP 27/2022);
  NIK tersimpan utuh tanpa kehilangan digit.
- Pendaftaran sah = 200 + satu baris anggota + tepat satu berkas KTP.
- Permintaan tidak sah = 422 + JSON, tanpa baris anggota dan tanpa berkas KTP.

Test batch: **9 passed / 51 assertions**.
Regression terdampak (`IntegritasTazirTest`, `PemisahanTugasGcgTest`,
`Fase4RegresiTest`, `ApprovalTazir`, `KeringananTazir`,
`KonfirmasiPembayaranPengajuanRekening`): **56 passed / 258 assertions**.

## Batch 15 — Integritas keputusan approval pembiayaan (Manajer)

Peta rute→test yang diperkaya (nama rute **atau** jalur URI) menemukan
**14 rute `manajer.*` tanpa sebutan test**. Diperiksa berurutan; area
paling berisiko = keputusan uang pada `manajer.approval-pembiayaan.*`.
Matriks: `tests/Feature/IntegritasApprovalPembiayaanManajerTest.php` (9 test).

### Temuan

| Kode | Temuan | Bukti |
|---|---|---|
| M-01 | `ManajerController::approvalPembiayaanReject()` **tidak memeriksa status awal**. Pembiayaan yang sudah **dicairkan**, **lunas**, atau masih di tahap AO/Pengurus dapat ditimpa menjadi `ditolak`; `keterangan` & `direview_oleh` ikut berubah | `test_tolak_tidak_menimpa_pembiayaan_yang_sudah_dicairkan` (`+'ditolak'`, harapan `dicairkan`) |
| M-02 | `ManajerController::approvalPembiayaanRevisi()` **tidak memeriksa status awal**. Pembiayaan yang sudah dicairkan dikembalikan ke `pending_ao`, dan pengajuan yang **sudah disetujui** dikembalikan ke `Menunggu` **serta `analisis_5c` dihapus** | `test_revisi_tidak_mengubah_pengajuan_yang_sudah_diputuskan` (`-'Disetujui'` / `+'Menunggu'`) |

Jalur setara yang sudah benar (pembanding disparitas): `approvalPembiayaanApprove()`
memakai `isStatus(Pembiayaan::STATUS_PENDING_MANAJER)`; `RestrukturisasiService::approve()`
memakai `lockForUpdate()` + cek status + `PemisahanTugas`; `DepositoPenaltyReliefController::approve()`
memakai `isPendingManajer()` + `PemisahanTugas`.

### Perbaikan

`Dashboard\ManajerController` — gerbang `isStatus(Pembiayaan::STATUS_PENDING_MANAJER)`
di awal `approvalPembiayaanReject()` dan `approvalPembiayaanRevisi()`, dengan
pesan galat yang identik dengan jalur `approve()`.

### Perilaku yang dikunci

- Tolak & revisi hanya berlaku pada tahap `pending_manajer`.
- Keputusan yang sudah diambil (cair/lunas/ditolak/disetujui) tidak dapat ditimpa.
- `analisis_5c` pada pengajuan yang sudah diputus tidak dihapus.
- Catatan tolak/revisi wajib diisi (validasi tetap berlaku).
- Jalur sah: tolak → `ditolak` + `direview_oleh` + `keterangan`;
  revisi → `pending_ao` + pengajuan `Menunggu`.

Test batch: **9 passed / 29 assertions**.
Regression terdampak (`AlurStatusPembiayaan`, `EskalasiPembiayaanDinamis`,
`MatriksApproval`, `IntegritasRestrukturisasi`, `IntegritasStatusPembiayaanAngsuran`,
`PemisahanTugas*`): **90 passed / 325 assertions**.

## Batch 16 — Integritas jalur Teller (pencairan, ta'zir, jadwal angsuran)

Peta rute→test menemukan **16 rute `teller.*` tanpa sebutan test**. Yang
diperiksa lebih dulu = tiga area yang menggerakkan uang atau kewajiban:
pencairan (`teller.pencairan.proses`, `teller.pencairan.cairkan`), ta'zir
(`teller.tazir.proses`), dan penerbitan jadwal angsuran
(`teller.angsuran.generate-jadwal`). Matriks:
`IntegritasPencairanTellerTest` (T-01…T-03),
`IntegritasTazirTellerTest` (T-04…T-06),
`IntegritasJadwalAngsuranTellerTest` (T-07…T-09).

### Temuan

| Kode | Temuan | Bukti |
|---|---|---|
| T-01 | Pencairan oleh **penyetuju sendiri** lewat `teller.pencairan.proses` | **False positive** — `PencairanController` meneruskan `Auth::id()`; `DisbursementService` menolak dengan `PemisahanTugas::PESAN`, status tetap `disetujui`, `is_disbursed` tetap `false` |
| T-02 | Sama seperti T-01 lewat pintu kedua `teller.pencairan.cairkan` | **False positive** — aturan identik pada kedua pintu |
| T-03 | Pencairan pada akad `dicairkan` / `lunas` / `ditolak` | **False positive** — ditolak sebelum menyentuh buku besar |
| T-04 | Ta'zir diproses saat `TazirService::fiturAktif()` = `false` | **False positive** — `ProsesTazirAction` mengirim galat `nominal`, saldo utuh |
| T-05 | Ta'zir melebihi saldo sukarela | **False positive** — saldo tetap, tidak menjadi negatif |
| T-06 | Ta'zir nominal `0` / negatif | **False positive** — ditolak via `withErrors(['nominal'])` |
| T-07 | `TellerController::generateJadwalAngsuran()` **tidak memeriksa status akad**: akad **lunas** memperoleh **12 tagihan baru** | `test_generate_jadwal_tidak_menumbuhkan_jadwal_pada_akad_lunas` (`Failed asserting that 12 is identical to 0`) |
| T-08 | Akad yang masih **menunggu keputusan** (`pending_manajer`) memperoleh 12 tagihan | `test_generate_jadwal_tidak_menumbuhkan_jadwal_pada_akad_belum_disetujui` |

Catatan metode: T-04 dan T-06 **awalnya tampak bug** (sesi tidak memuat galat
`nominal`). Dibuktikan lewat test diagnosa sementara — aplikasi membalas 302
dengan galat `Fitur denda keterlambatan (Ta'zir) sedang nonaktif…` — sehingga
perilaku sistem benar; penyebabnya fixture yang lupa `bukaSesiKas()` sehingga
middleware `kas.terbuka` memblokir lebih dulu. **Tidak ada kode aplikasi yang
diubah** untuk T-01…T-06.

### Perbaikan

`TellerController::generateJadwalAngsuran()` — gerbang status sebelum jadwal
dibuat, memakai daftar yang **sama** dengan `angsuranForm()` (disparitas
tersebut yang menjadi sumber cacatnya):

```php
if (! $pb->isStatus([
    Pembiayaan::STATUS_DICAIRKAN,
    Pembiayaan::STATUS_DISETUJUI,
    Pembiayaan::STATUS_RESTRUKTURISASI,
    Pembiayaan::STATUS_AKTIF,
])) {
    return response()->json([...], 422);
}
```

### Perilaku yang dikunci

- Akad `lunas` / `ditolak` / masih menunggu keputusan **tidak** memperoleh
  jadwal angsuran baru (422 + `status_pembiayaan` pada balasan).
- Akad berjalan tetap menerbitkan tepat `tenor` baris; permintaan berulang
  idempoten (`sudah_ada: true`, jumlah tetap).
- Penyetuju pembiayaan tidak dapat mencairkan sendiri lewat kedua pintu.
- Pencairan hanya dari status siap-cair; akad lain tidak menyentuh buku besar.
- Ta'zir ditolak saat fitur nonaktif, saat nominal ≤ 0, dan saat saldo tidak
  mencukupi — dalam ketiga kasus saldo anggota tidak berubah.

Test batch: **9 passed / 34 assertions** (T-01…T-03: 3 passed / 12 assertions;
T-04…T-06: 3 passed / 11 assertions; T-07…T-09: 3 passed / 11 assertions).
Regression terdampak (`Teller|Angsuran|Pencairan|Disbursement|PemisahanTugas|Tazir|Jadwal|StatusAngsuran`):
**382 passed / 1492 assertions**.

## Batch 17 — Integritas pelepasan penahanan SHU (Hold)

Rute `pengurus.shu.lepas-hold-anggota` (satuan), `pengurus.shu.lepas-hold`
(massal), dan `pengurus.shu.destroy` sebelumnya belum disebut satu test pun,
padahal ketiganya berada langsung pada jalur uang: pelepasan HOLD memindahkan
saldo dari pos utang **2-1004** ke **2-1001** Simpanan Sukarela anggota.
Berkas: `tests/Feature/IntegritasPelepasanHoldShuTest.php` — **8 test**,
`DatabaseTransactions` + label tahun buku unik.

### Temuan

| Kode | Tingkat | Uraian | Bukti |
|---|---|---|---|
| BUG #1 | TINGGI | Auto-create rekening Sukarela pada pelepasan HOLD tidak mengisi `akun_id` dan `tanggal_buka` — keduanya kolom **NOT NULL tanpa default** pada `rekening`. Akibatnya **setiap anggota yang belum punya rekening Sukarela gagal dilepas SHU-nya**, galat SQL bocor sebagai pesan flash ke loket teller. Dua salinan: `ShuCalculatorService::cairkanShuDitahan()` dan `ShuController::cairkanShuDitahan()`. | `SQLSTATE[HY000] 1364 Field 'akun_id' doesn't have a default value ... insert into rekening (SUK-050409, ...)` |
| BUG #2 (P-04) | SEDANG | `ShuController::destroy()` hanya memanggil `$shu->delete()`. `ShuPeriod` memakai `SoftDeletes`, sehingga FK `shu_distributions.shu_period_id` (`onDelete('cascade')`) **tidak berjalan** dan baris distribusi tertinggal yatim. | P-04 merah sebelum perbaikan |
| BUG #3 (P-07) | SEDANG | `ShuController::proses()` memeriksa gerbang `isDraft()` **di luar** `DB::transaction` dan tanpa `lockForUpdate()`. Dua permintaan bersamaan sama-sama lolos gerbang dan SHU dapat tercairkan dua kali. | P-07 merah sebelum perbaikan |
| BUG #4 (P-03) | SEDANG | `ShuController::lepasPenahananOtomatis()` membaca daftar HOLD di luar transaksi lalu mencairkan per baris; baris klaim tidak dikunci. | Perbaikan klaim atomik |

### Cacat test (bukan bug produksi)

- P-03 awal memakai patokan `sum(total_shu)` seluruh periode uji, padahal
  pelepasan **massal** memang memproses seluruh baris HOLD di sistem, termasuk
  milik anggota dari fixture lain. Assertion diperbaiki menjadi per-anggota.
- `hitungDasar()` mengambil **tahun** dari string `tahun_buku` (regex 4 digit,
  fallback `now()->year`). Label unik awal `B17-XXXXXX` tidak memuat tahun,
  sehingga perhitungan saldo bergantung pada fallback dan selang-seling
  menghasilkan nol distribusi (flaky ±50%). Label kini `B17-<tahun>-XXXXXX`.
- P-01/P-03 sempat memakai `update(['status' => PAID])` mentah untuk melunasi
  tunggakan — jalur itu melewati jurnal, pembersihan `deferred_*`, dan subledger
  rekening Wajib, sehingga sistem **benar** menolak pelepasan. Diganti jalur sah
  `teller.simpanan-wajib.bayar` (HTTP).

### Perbaikan (minimal)

- `ShuCalculatorService::cairkanShuDitahan()` & `ShuController::cairkanShuDitahan()`:
  rekening otomatis kini mengisi `akun_id` (resolusi
  `JenisSimpanan::akunLegacyId()`), `tanggal_buka`, dan `batas_minimum`; bila
  akun legacy tidak ditemukan → `RuntimeException` (fail-closed).
- `ShuController::destroy()`: `$shu->distributions()->delete()` di dalam
  `DB::transaction` yang sama sebelum `$shu->delete()`.
- `ShuController::proses()`: status dibaca ulang dengan `lockForUpdate()` **di
  dalam** transaksi; bila bukan Draft lagi → `RuntimeException` yang sudah
  ditangani menjadi pesan ramah.
- `ShuController::lepasPenahananOtomatis()`: tiap baris diklaim atomik
  (`where('is_hold', true)->lockForUpdate()`), cairkan dan klaim berada di satu
  transaksi.

### Perilaku yang dikunci

- Pelepasan HOLD bersifat sekali-jalan: aksi kedua tidak menambah saldo
  Sukarela maupun membuat mutasi `SHU-REL-*` baru.
- Anggota yang masih menunggak tetap ditolak (`error`); saldo tidak berubah dan
  `is_hold` tetap `true`.
- Anggota **tanpa** rekening Sukarela tetap dapat dilepas; rekening otomatis
  yang terbentuk lengkap (`akun_id`, `tanggal_buka`, `no_rekening`).
- `destroy` menolak periode yang sudah didistribusikan (bahkan tidak
  soft-delete) dan menghapus draft **beserta** seluruh baris distribusinya.
- Proses SHU kedua atas periode `Selesai` tidak menambah saldo/mutasi.
- `proses()` wajib mengambil kunci periode di dalam transaksi (dijaga test
  struktural).

Test batch: **9 passed / 100 assertions** (3× ulang berturut-turut: 9 passed).
Regression terdampak (`Shu|PelepasanHold|MandatorySavings|SimpananWajib`):
**207 passed / 981 assertions**.

## Batch 18 — Jalur satuan lepas HOLD + deduplikasi logika pencairan

Berkas uji: `tests/Feature/IntegritasPelepasanHoldShuTest.php` (9 test, P-09 baru).

### Fakta sistem yang dikonfirmasi lebih dulu

Pelunasan tunggakan Simpanan Wajib lewat Teller
(`teller.simpanan-wajib.bayar` → `MandatorySavingsService::bayarViaTeller()`)
**sudah melepas HOLD secara otomatis** melalui `lepasPenahananShuBilaLunas()`
pada akhir transaksi. Konsekuensinya tombol **Pelepasan Penahanan** manual
pada alur normal memang menjawab *"SHU anggota ini tidak sedang dalam
penahanan."* — ini perilaku **benar**, bukan bug. Skenario uji P-09 karena itu
dibangun dari HOLD yang **direkonstruksi** (mis. koreksi admin) atas baris
yang pencairan sebelumnya sudah berjalan; justru di sinilah cacat nyata muncul.

### Temuan

| Kode | Tingkat | Modul | Ringkas | Bukti |
|---|---|---|---|---|
| BUG-20 | SEDANG | `ShuController::lepaskanPenahanan()` | Jalur **satuan** hanya memeriksa `is_hold` di luar transaksi; dua permintaan berurutan sama-sama lolos klaim dan saldo Sukarela bertambah dua kali | P-09 merah sebelum perbaikan (klaim tanpa kunci) |
| BUG #6 | TINGGI | `cairkanShuDitahan()` (dua salinan) | Tidak ada syarat **baseline** `transaksi_id IS NULL`; baris yang sudah pernah dicairkan dapat dicairkan ulang bila `is_hold` dikembalikan `true` | Saldo `132132.14` dari `66066.07` pada run P-09 |
| BUG #7 | TINGGI | `cairkanShuDitahan()` (dua salinan) | `no_transaksi` berformat `SHU-REL-YmdHis-{anggota}` bentrok pada indeks unik | `SQLSTATE[23000] 1062 Duplicate entry 'SHU-REL-20261030142907-51474'` |

Ketiga temuan saling menutupi pada run yang sama: penomoran yang bentrok
menggagalkan transaksi, sementara penambahan saldo sudah terlanjur berjalan
sebelum kegagalan — bukti langsung bahwa keduanya adalah bug **terpisah**.

### Perbaikan (minimal)

1. `ShuController::lepaskanPenahanan()` — klaim atomik: `lockForUpdate()` +
   `where('is_hold', true)` di dalam `DB::transaction()`, cek tunggakan di
   dalam transaksi, keputusan `cairkan` diambil dari baris terkunci.
2. `ShuCalculatorService::cairkanShuDitahan()` dan
   `ShuController::cairkanShuDitahan()` — klaim **berbaseline**:
   `where('is_hold', true)->whereNull('transaksi_id')->lockForUpdate()`.
   Bila tidak terklaim, fungsi kembali tanpa menyentuh saldo. Seluruh
   penulisan lanjutan memakai model hasil klaim (`$diklaim`), termasuk
   `referensi` `SHU-RELEASE-{periode}`.
3. `no_transaksi` pelepasan HOLD memakai
   `NomorSequence::berikutnya('SHU-REL-'.date('Ymd').'-')` — sekuensial
   (`SELECT ... FOR UPDATE`), format `SHU-REL-Ymd-000001`. Awalan `SHU-REL`
   dipertahankan agar semua pemanggil lama (`like 'SHU-REL-%'`) tetap sah.
4. Diagnostik sementara (`fwrite(STDERR, ...)`) dicabut dari test.

### Perilaku yang dikunci

- Klik ganda pada pelepasan HOLD **satuan** tidak menambah saldo Sukarela
  maupun mutasi `SHU-REL-*` untuk kedua kalinya.
- Baris distribusi yang sudah memiliki `transaksi_id` tidak pernah dicairkan
  ulang meskipun `is_hold` dikembalikan `true` (baseline pencairan).
- Dua pencairan pada detik yang sama tetap memperoleh nomor dokumen berbeda
  (tidak ada `Duplicate entry`).
- Pelunasan tunggakan lewat Teller tetap otomatis melepas HOLD satu kali.

Test batch: **9 passed / 100 assertions** (3× ulang berturut-turut: 9 passed).
Regression terdampak: **207 passed / 981 assertions**.

## Batch 19 — Sisa utang SHU: `rekening.akun_id`, deduplikasi pencairan, FK cascade vs SoftDeletes

Berkas uji: `tests/Feature/IntegritasPelepasanHoldShuTest.php` (11 test; Q-01 dan
Q-02 baru). Menuntaskan utang #20, #22, #23, #24.

### Fakta sistem yang dikonfirmasi lebih dulu

Ada **dua tabel akun** yang berbeda: `coas` (bagan akun modern, dipakai untuk
jurnal lewat `App\Support\Coa`) dan `akun` (bagan akun legacy, diisi
`AkunSeeder` dengan `kode_akun` `'1'`, `'11'`, `'111'`, …). Kolom
`rekening.akun_id` adalah rujukan ke tabel **`akun` legacy**, bukan `coas` —
paritas `PengajuanRekeningController` dan `DepositoService`.

FK `shu_distributions.shu_period_id` terverifikasi ber-`DELETE_RULE = CASCADE`
lewat `information_schema.REFERENTIAL_CONSTRAINTS`.

### Temuan

| Kode | Tingkat | Modul | Ringkas | Bukti |
|---|---|---|---|---|
| BUG-23 | **TINGGI** | `ShuController::proses()` | Auto-create rekening Sukarela menulis `$coaSukarela->id` (ID tabel `coas`) ke `rekening.akun_id` yang menunjuk tabel `akun` legacy → rekening anggota menunjuk akun yang tidak ada, saldo tampil di bagan akun yang salah | Q-01 merah: `Failed asserting that 20 is identical to 10` (`id coas` 20 vs `id akun` legacy 10) |
| #23 | — | `ShuPeriod` (SoftDeletes) vs FK cascade | Dugaan "cascade tidak pernah berjalan" | **FALSE POSITIVE untuk hapus tegas** — Q-02 hijau (1 passed / 8 assertions); `DELETE_RULE=CASCADE`. Hapus lunak menyisakan baris (`[SOFT-DELETE] distribusi tersisa=1`), tetapi `destroy()` produksi sudah menghapus distribusi lebih dulu (P-04), sehingga tidak ada kebocoran produksi |
| #24 | SEDANG | `ShuController::cairkanShuDitahan()` | Salinan ±110 baris logika pencairan dari service — sumber penyimpangan berulang | Kode: dua implementasi identik; kini satu |
| #23b | RENDAH | `shu_periods.tahun_buku` | Dugaan unique index DB memblokir pembuatan ulang label setelah soft-delete | **Tidak terbukti** — `SHOW INDEX … Non_unique = 0` hanya `PRIMARY(id)`; `label_sama_setelah_softdelete galat=NULL` |

### Perbaikan (minimal)

1. `ShuController::proses()` — rekening Sukarela otomatis memakai
   `JenisSimpanan::akunLegacyId(JenisSimpanan::namaUtama(TIPE_SUKARELA))`;
   bila akun legacy tidak ditemukan, distribusi **dibatalkan** dengan
   `RuntimeException` (fail-fast, bukan menulis rujukan palsu).
2. `ShuCalculatorService::cairkanShuDitahan()` (private, `void`) menjadi
   `cairkanBilaDalamPenahanan(ShuDistribution, Anggota): bool` **publik** —
   satu-satunya jalur pencairan HOLD.
3. `ShuController::cairkanShuDitahan()` — salinan ±110 baris **dihapus**,
   diganti delegasi `return $this->calculator->cairkanBilaDalamPenahanan(...)`.
   `lepaskanPenahanan()` dan `lepasPenahananOtomatis()` meneruskan nilai bool
   sehingga pesan "tidak sedang dalam penahanan" tetap jujur saat klik ganda.
4. Berkas diagnostik sementara `tests/Feature/CekFkSementaraTest.php` dihapus
   setelah bukti FK/index tercatat.

### Perilaku yang dikunci

- Rekening Sukarela yang dibuat otomatis oleh proses SHU menunjuk `akun.id`
  legacy (bukan `coas.id`).
- `forceDelete()` periode SHU **tidak** meninggalkan baris distribusi yatim
  (cascade bekerja).
- Pencairan HOLD hanya bisa terjadi lewat satu jalur service; controller tidak
  menyimpan salinan logika.
- Klik ganda pelepasan HOLD (satuan maupun massal) tetap tidak mencairkan dua
  kali setelah deduplikasi.

Test batch: **11 passed / 113 assertions** (3× ulang berturut-turut: 11 passed).
Regression terdampak (`Shu|PelepasanHold|MandatorySavings|SimpananWajib`):
**209 passed / 994 assertions**.

## Batch 20 — Rute Pengurus yang belum pernah disentuh test (8 rute)

Pemindaian `route:list --json` (377 rute) disilangkan dengan seluruh berkas
`tests/Feature` menghasilkan **65 rute `pengurus.*`**, dan **12 di antaranya
NOL rujukan test**. Batch ini menutup 8 di antaranya; 4 sisanya
(`pengurus.shu.store`, `pengurus.shu.preview`, `pengurus.shu.show`,
`pengurus.cif.export`) menyusul batch berikutnya.

| Kode | Rute | Yang dikunci |
|---|---|---|
| R1 | `pengurus.shu.export-excel` | CSV memuat SELURUH baris distribusi + kop pelaporan; periode tak ada → galat tanpa unduhan; patuh batas baris peran |
| R2 | `pengurus.laporan-syariah.index` | Opini DPS terbaru tampil; database kosong tetap terbuka (bukan 500) |
| R3 | `pengurus.laporan.dana-kebajikan` | `saldo_akhir = saldo_awal + penerimaan − penyaluran`; periode terpisah per tanggal |
| R4 | `pengurus.pengesahan-shu.index` | Nominal alokasi = `totalPendapatan × persen / 100`; total persen = jumlah baris |
| R5 | `pengurus.produk.update` | `max_plafon < min_plafon` ditolak & data tidak berubah; simpan parameter baru; **Pengurus 403** |
| R6 | `pengurus.coa.store` | Klasifikasi di luar kategori ditolak; kode ganda ditolak; akun sah tersimpan; **Pengurus 403** |
| R7 | `pengurus.aml.export` | Jenis tak dikenal 404 (dibatasi `whereIn`); CSV LTKT bertanda kop pelapor |
| R8 | `pengurus.pembiayaan.show` | Menampilkan pengajuan yang diminta; id tak ada → 404 |

### Temuan

| Kode | Tingkat | Objek | Uraian | Bukti |
|---|---|---|---|---|
| T-20a | RENDAH (cakupan) | 12 rute `pengurus.*` | Nol rujukan test: perubahan pada rute/rute-guard tidak akan tertangkap regresi | Hasil silang `route:list` × isi `tests/Feature` |
| T-20b | — (bukan bug) | `pengurus.coa.store`, `pengurus.produk.update` | Percobaan pertama memakai peran `pengurus` → **403** pada 5 test | `Expected [201,301,…] but received 403`; `Session is missing expected key [errors]` |

T-20b dianalisis lebih dulu sebelum mengubah apa pun: `pengurus.coa.store`
berada di grup `Route::middleware('izin:coa.kelola')` dan
`pengurus.produk.update` di grup `izin:master.data` — **keduanya eksklusif
Super Admin** (routes/web.php L338–345, L446), sesuai komentar rute yang
menegaskan CoA/master data "bukan kewenangan kebijakan strategis Pengurus".
Jadi 403 itu **perilaku benar**, bukan cacat sistem; yang salah adalah fixture
test. Tidak ada satu baris kode aplikasi yang diubah pada batch ini.

### Perbaikan (minimal)

1. Fixture test memakai peran yang berwenang: `superAdmin` (`super_admin`)
   untuk tiga test `pengurus.coa.store` dan dua test `pengurus.produk.update`.
2. Dua test pengunci wewenang ditambahkan sehingga kesalahan peran tidak bisa
   lolos diam-diam: Pengurus → `pengurus.coa.store` = **403** dan akun tidak
   tercipta; Pengurus → `pengurus.produk.update` = **403** dan margin tidak
   berubah (dari 10.00 tetap 10.00 meski dikirim 99).
3. Tidak ada perubahan skema, controller, service, maupun middleware.

### Perilaku yang dikunci

- Ekspor SHU dan ekspor PPATK tetap menghormati batas baris peran
  (`HierarkiRole::aksesData(..., 'maks_baris_ekspor')`); melampaui batas
  menghasilkan galat, bukan berkas berisi seluruh data anggota.
- Laporan dana kebajikan memisahkan periode berdasarkan `created_at` dan
  memakai `max(0, …)` untuk saldo awal (tidak pernah negatif).
- Laporan syariah pada database kosong tetap 200 dengan empty state.
- Pengurus **tidak** dapat mengubah master produk maupun menambah akun CoA —
  pemisahan tugas (maker-checker) utuh.

Test batch: **19 passed / 60 assertions** (3× ulang berturut-turut: 19 passed).
Regression terdampak (`Shu|Pengurus|Pembiayaan|Coa|Aml|DanaKebajikan`):
**513 passed / 2397 assertions**.

## Batch 21 — Sisa rute Pengurus tanpa rujukan test (4 rute)

Menutup 12 rute temuan T-20a secara tuntas. Kini **tidak ada** rute
`pengurus.*` yang nol rujukan test.

| Kode | Rute | Yang dikunci |
|---|---|---|
| R9 | `pengurus.shu.preview` | Nominal tiap porsi = `total × persen / 100`; jumlah lima porsi 100% = total SHU tepat |
| R10 | `pengurus.shu.store` | Alokasi ≠ 100% ditolak (`errors.persen`) & tidak tersimpan; SHU melebihi laba riil ditolak (`errors.total_shu_bersih`); tahun buku ganda ditolak; draft sah tersimpan beserta nominal & pembuatnya |
| R11 | `pengurus.shu.show` | Seluruh baris distribusi dikirim; HANYA `is_hold` masuk `holdRows`; id tak ada → redirect + `error` |
| R12 | `pengurus.cif.export` | Tanpa `?export=excel` hanya kembali (bukan unduhan); CSV memuat kolom CIF & baris anggota; batas baris peran memblokir unduhan |

### Temuan

| Kode | Tingkat | Objek | Uraian | Bukti |
|---|---|---|---|---|
| T-21a | — (temuan proses) | Test fixture sendiri | `Coa::IKHTISAR_SHU` ditulis pada kelas model → `Undefined constant App\Models\Coa::IKHTISAR_SHU` | Fatal error PHPUnit pada R10 keempat |
| T-21b | — (bukan bug) | `pengurus.cif.export` | Pagar batas baris mustahil dipicu data uji: batas pengurus **50.000** baris vs 13 anggota → test awal hanya bisa di-skip | `aksesData('pengurus','maks_baris_ekspor')` = 50000, `count(anggota)` = 13 |

Tidak ada cacat sistem yang ditemukan pada batch ini. T-21a adalah kesalahan
penulisan test; T-21b adalah keterbatasan data uji, bukan kelemahan pagar.

### Perbaikan (minimal)

1. Impor konstanta dari sumber sebenarnya: `App\Support\Coa` di-*alias*
   `CoaSupport` (kelas model `App\Models\Coa` bernama sama).
2. Test pagar CIF dibuat **tegas, tanpa skip**: batas peran diturunkan
   sementara menjadi 1 lewat `config(['role_hierarki' => …])`, di dalam
   `try/finally` yang memulihkan konfigurasi asli tanpa syarat, lalu
   diverifikasi pulih (`assertGreaterThan(1, …)`). Dengan begitu pagar tetap
   terbukti bekerja tanpa perlu menanam ribuan baris anggota.
3. Tidak ada perubahan kontroler, service, middleware, maupun skema.

### Perilaku yang dikunci

- Draft SHU hanya lahir bila alokasi **tepat 100%** dan total tidak melebihi
  saldo Ikhtisar SHU (anti-over-distribution, PSAK 101).
- Tahun buku tidak dapat diduplikasi pada level aplikasi (utang #26 mencatat
  bahwa penjagaan ini belum didukung indeks unik DB).
- Halaman detail SHU memisahkan baris HOLD ke seksi tersendiri, sehingga
  penahanan SHU anggota menunggak selalu terlihat oleh Pengurus.
- Ekspor CIF menolak mengunduh berkas utuh bila volume melampaui batas peran.

Test batch: **10 passed / 48 assertions, 0 skipped** (3× ulang berturut-turut).
Regression terdampak (`Shu|Pengurus|Cif|Anggota|Hold`):
**478 passed / 2081 assertions**.

## Batch 22 — Kanal Nasabah (11 rute tanpa rujukan test)

Modul paling dekat dengan anggota: semua 11 rute `nasabah.*` yang belum pernah
disentuh test kini tertutup.

| Kode | Rute | Yang dikunci |
|---|---|---|
| N1 | `nasabah.deposito.index` | Hanya bilyet milik anggota; total pokok hanya status `ACTIVE` |
| N2 | `nasabah.pembiayaan.index` | Pembiayaan anggota lain tidak tampil |
| N3 | `nasabah.pengajuan.index` | Pengajuan anggota lain tidak tampil |
| N4 | `nasabah.pengajuan.create` | Daftar produk tersedia di formulir |
| N5 | `nasabah.pengajuan.store` | Margin/total/angsuran terhitung; `anggota_id` dari sesi, bukan input; plafon di luar rentang ditolak |
| N6 | `nasabah.pengajuan-pinjaman.index` | Produk + riwayat tampil |
| N7 | `nasabah.pengajuan-pinjaman.store` | Kolom turunan terhitung (12% → total & angsuran tepat) |
| N8 | `nasabah.pengajuan-pinjaman.baru` | Formulir wizard terbuka |
| N9 | `nasabah.pengajuan-pinjaman.store.baru` | Pengajuan + 3 dokumen wajib tersimpan dengan kolom yang benar |
| N10 | `nasabah.status-pengajuan.index` | Riwayat hanya milik anggota |
| N11 | `nasabah.angsuran.index` | Tagihan angsuran hanya milik anggota |

### Temuan

| Kode | Tingkat | Objek | Uraian | Bukti |
|---|---|---|---|---|
| **BUG-25** | **TINGGI** | `NasabahController::pengajuanPinjamanStoreBaru()` | Unggahan dokumen pengajuan menulis kolom `nama_dokumen` & `path` yang **tidak ada di `$fillable`** `DokumenPengajuan`; keduanya dibuang diam-diam sehingga `nama_file` (NOT NULL tanpa default) kosong → **HTTP 500**. Seluruh pengajuan lewat wizard 3-step nasabah gagal di akhir proses; pengajuan sudah tercipta sebelum galat, jadi anggota melihat error padahal data masuk (risiko pengajuan ganda). | `SQLSTATE[HY000]: 1364 Field 'nama_file' doesn't have a default value … insert into dokumen_pengajuan_pembiayaan (jenis_dokumen, pengajuan_id, …)`; `Expected … but received 500` |
| T-22a | RENDAH | `NasabahController` (kanal nasabah) | Tidak ada test sama sekali untuk 11 rute; BUG-25 karena itu tidak terdeteksi | Silang `route:list` × isi `tests/Feature` |

BUG-25 dikonfirmasi lebih dulu dengan test pembuktian (bukan diasumsikan),
lalu dibandingkan dengan jalur yang sudah benar: `AOController::unggahDokumen()`
menulis `nama_file`, `path_file`, `ukuran_file`, `diupload_oleh`.

### Perbaikan (minimal)

1. `NasabahController::pengajuanPinjamanStoreBaru()` menulis kolom sesuai
   skema: `nama_file`, `path_file`, `ukuran_file`, `diupload_oleh` —
   menyamakan perilaku dengan kanal AO. Tidak ada perubahan skema/middleware.
2. Test regresi BUG-25 memeriksa keempat kolom **dan** keberadaan berkas fisik
   di disk (`Storage::disk('dokumen')->assertExists(...)`), sehingga kesalahan
   nama kolom tidak bisa lolos lagi.

### Perilaku yang dikunci

- Akun berperan `anggota` tanpa baris `anggota` ditolak **403** — bukan 500 dan
  bukan halaman kosong.
- `anggota_id` pengajuan selalu berasal dari sesi (`getAnggota()`), tidak pernah
  dari payload — mencegah pengajuan atas nama anggota lain.
- Seluruh daftar (deposito, pembiayaan, pengajuan, angsuran) terfilter per
  anggota; kebocoran data antaranggotа tertangkap test.
- Plafon di bawah minimum / di atas maksimum produk ditolak **sebelum**
  tersimpan, dengan pesan `error` yang dibaca anggota.
- Jenis akad pengajuan diambil dari produk, bukan dari input pengguna.

Test batch: **16 passed / 66 assertions** (3× ulang berturut-turut: 16 passed).
Regression terdampak (`Nasabah|Pengajuan|Anggota|Deposito|Pembiayaan`):
**570 passed / 2382 assertions**.

## Batch 23 — Modul pengunduran diri (`member-terminations`, 5 rute tanpa rujukan test)

Rute keputusan keuangan yang paling sensitif (pengembalian Simpanan Pokok &
Wajib) sebelumnya hanya diuji lewat *service*, bukan lewat HTTP. Lima rute
permukaan yang belum tersentuh kini tertutup.

| Kode | Rute | Yang dikunci |
|---|---|---|
| T1 | `member-terminations.index` | Ringkasan hanya menghitung `DIAJUKAN`+`MENUNGGU_APPROVAL` dan total hanya `DISETUJUI`; baris `DITOLAK` tidak masuk total |
| T2 | `member-terminations.index?status=` | Filter menyaring benar; nilai status tak dikenal diabaikan (bukan 500) |
| T3 | `member-terminations.index?cari=` | Pencarian No. Terminasi & nama/no. anggota menyaring tepat |
| T4 | `member-terminations.create` | Formulir terbuka; `anggota_id` valid → pratinjau terisi; ID tak ada → `null` (bukan 500) |
| T5 | `member-terminations.cari-anggota` | Ditemukan lewat No. Anggota/No. CIF/NIK (blind index); NIK **selalu tersamar** (`************0001`), NIK penuh tidak pernah dikirim |
| T6 | `member-terminations.cari-anggota` (validasi) | `q` wajib & minimal 3 karakter → 422 |
| T7 | `member-terminations.cari-anggota` (negatif) | Tidak ditemukan → `found=false` + pesan, bukan galat |
| T8 | `member-terminations.cari-anggota` (TERMINATED) | `boleh_ajukan=false` + kendala eksplisit `TERMINATED` |
| T9 | `member-terminations.cari-anggota` (pending) | Pengajuan berjalan dilaporkan (`pending.id` + kendala "belum selesai") |
| T10 | `member-terminations.tolak` | Status → `DITOLAK`, alasan & pelaku tercatat, audit `EXIT_KEANGGOTAAN_DITOLAK` |
| T11 | `member-terminations.tolak` (validasi) | Tanpa alasan → `errors.alasan_penolakan`; status tidak berubah |
| T12 | `member-terminations.tolak` (ganda) | Dokumen `DITOLAK` tidak bisa ditolak lagi; alasan asli tidak tertimpa |
| T13 | `member-terminations.batalkan` | Status → `DIBATALKAN`, alasan opsional, audit `EXIT_KEANGGOTAAN_DIBATALKAN` |
| T14 | `member-terminations.batalkan` (final) | Dokumen `DISETUJUI` tidak bisa dibatalkan |
| T15 | RBAC kelima rute | Peran `anggota` → **403** di semua rute; status dokumen tidak berubah |
| T16 | RBAC positif | `manajer` & `pengurus` dapat membuka daftar (200) |

### Temuan

| Kode | Tingkat | Objek | Uraian | Bukti |
|---|---|---|---|---|
| T-23a | RENDAH | `IntegritasModulTerminasiTest` | Assertion audit awal memakai `no_terminasi`, padahal `AuditLog::record()` menulis `no_referensi` pada kolom `keterangan`. **Salah pada test**, bukan aplikasi. | 2 test gagal `Failed asserting that 0 is identical to 1`, lalu hijau setelah assertion dibetulkan |

Tidak ada cacat produksi pada batch ini: seluruh perilaku yang diuji sudah
benar (status terminal tidak bisa ditimpa, NIK tersamar, RBAC berlapis).

### Perbaikan (minimal)

1. Hanya berkas test baru `tests/Feature/IntegritasModulTerminasiTest.php`.
   **Nol perubahan kode aplikasi** — sama seperti Batch 20 & 21.
2. Assertion jejak audit diperbaiki agar memakai `no_referensi` (nilai yang
   benar-benar ditulis `AuditLog::record`).

### Perilaku yang dikunci

- Ringkasan daftar **tidak** menjumlahkan status `DITOLAK`/`DIBATALKAN` ke
  total dana yang dikembalikan — hanya `DISETUJUI`.
- NIK tidak pernah keluar dari server dalam bentuk penuh, bahkan pada respons
  JSON pencarian (UU PDP No. 27/2022).
- Anggota `TERMINATED` atau yang masih punya pengajuan berjalan tidak bisa
  diajukan exit lagi (`boleh_ajukan=false` + alasan yang dibaca operator).
- Keputusan `DITOLAK`/`DIBATALKAN` bersifat terminal; `DISETUJUI` tidak bisa
  dibatalkan — mencegah keputusan bolak-balik (PSAK 108).
- Seluruh rute modul tertutup untuk peran di luar `teller`/`manajer`/`pengurus`.

Test batch: **16 passed / 90 assertions** (3× ulang berturut-turut: 16 passed).
Regression terdampak (`Exit|Terminasi|Anggota|GuardSimpanan`):
**301 passed / 1161 assertions**.

## Batch 24 — Rute Teller tanpa rujukan test (13 rute)

Berkas: `tests/Feature/IntegritasRuteTellerTanpaTest.php`.

| Kode | Rute | Perilaku yang dikunci |
|---|---|---|
| T1 | `teller.rekap` | Modal awal & saldo sistem dari sesi kas milik teller |
| T2 | `teller.riwayat` | Filter `cari` (no. transaksi) dan `jenis` menyaring benar |
| T3 | `teller.riwayat` (`ajax_check`) | Hanya transaksi milik teller yang login |
| T4 | `teller.deposito.show` | Bagi hasil = Σ mutasi; rekening non-berjangka → 404 |
| T5 | `teller.kas.setor-brankas` | Ditolak tanpa sesi kas; anti-deficit; dalam batas → saldo laci turun |
| T6 | `teller.anggota.card` | Tampil + relasi rekening; id tak ada → 404 |
| T7 | `teller.pencairan-disetujui.index` | Hanya status `DISETUJUI` |
| T8 | `teller.keringanan-penalti.create` | Tanpa & dengan `anggota_id` |
| T9 | `teller.keringanan-penalti.store` | Bilyet non-aktif ditolak; alasan wajib; status `PENDING_AO`; dedup |
| T10 | `teller.keringanan-penalti.index` | Hanya pengajuan milik teller; filter status |
| T11 | `teller.keringanan-penalti.anggota` | Hanya bilyet AKTIF milik anggota; **tanpa NIK/hash** |
| T12 | `teller.keringanan-tazir.index` | Ringkasan pending/approved/rejected per teller |
| T13 | RBAC | `ao`/`dps`/`anggota` → 403; `manajer`/`pengurus` dapat membuka |

### Temuan

| Kode | Tingkat | Letak | Kesimpulan |
|---|---|---|---|
| T-24a | — | fixture test | `transaksi.posisi` NOT NULL tanpa default → fixture menambahkan `posisi => 'Kredit'`. Salah pada test. |
| T-24b | — | fixture test | `tazir_relief_requests.jadwal_angsuran_id` NOT NULL → fixture wajib membuat `Angsuran`. Salah pada test. |
| T-24c | — | assertion test | `assertJsonPath(..., 2000000.0)` gagal karena JSON membandingkan `2000000` — bukan bug. Bandingkan via `(float)`. Salah pada test. |

**Nol cacat produksi baru.** Endpoint `teller.keringanan-penalti.anggota` sudah
membentuk respons sebagai array eksplisit (`id`/`no_anggota`/`nama`), sehingga
NIK (ber-cast `encrypted`) dan `nik_hash` tidak pernah terkirim; perilaku ini
kini **dikunci** oleh test T11b agar tidak mundur bila struktur respons diubah.
Docblock `DepositoProduct::hitungPenalti()` diperjelas (nilai cadangan
`PENALTI_AMOUNT_DEFAULT` dan nominal 0 yang dipilih produk) — **komentar saja,
nol perubahan logika**.

### Perbaikan (minimal)

1. `tests/Feature/IntegritasRuteTellerTanpaTest.php` — berkas baru, 19 test.
2. `app/Models/DepositoProduct.php` — docblock `hitungPenalti()` diperjelas.
3. `app/Http/Controllers/DepositoPenaltyReliefController.php` — docblock
   `infoAnggota()` ditambah catatan minimisasi data (UU PDP 27/2022).

Test batch: **19 passed / 107 assertions** (stabil pada dua kali eksekusi).
Regression terdampak (`Teller|Deposito|Keringanan|Tazir|Pencairan|Kas`):
**613 passed / 2826 assertions**.

## Batch 25 — Rute Manajer tanpa rujukan test (14 rute)

Berkas: `tests/Feature/IntegritasRuteManajerTanpaTest.php`.

| Kode | Rute | Perilaku yang dikunci |
|---|---|---|
| M1 | `manajer.cif.index` | Pencarian `no_cif`; filter status menerima alias (`Aktif`) maupun kanonik; tamu → login |
| M2 | `manajer.cif.show` | Portofolio (pembiayaan aktif); `?partial=deposito` mengembalikan fragmen tanpa layout; id tak ada → 404 |
| M3 | `manajer.cif.edit` | Form ubah CIF |
| M4 | `manajer.cif.update` | Perubahan tersimpan, tetapi `status_anggota` **dibuang** (wewenang `admin`/`pengurus`); NIK & no. HP milik anggota lain ditolak |
| M5 | `manajer.cif.export` | Tanpa `?export=excel` → kembali; CSV UTF-8 BOM + header lengkap; batas ekspor manajer 20.000 baris (UU PDP 27/2022) |
| M6 | `manajer.approval-restrukturisasi.index` | Default hanya `PENDING_APPROVAL`; filter status + pencarian `no_pembiayaan`; status tak dikenal kembali ke pending |
| M7 | `manajer.approval-restrukturisasi.approve` | Addendum terbit, jadwal baru tanpa tambahan margin (Σ pokok+margin = piutang snapshot, Fatwa DSN-MUI 49), `status_kolektibilitas` dikosongkan agar dihitung dinamis; pemisahan tugas; pengajuan sudah diproses; catatan ≤ 1000 karakter |
| M8 | `manajer.approval-restrukturisasi.reject` | Catatan wajib; jadwal lama tidak berubah |
| M9 | `manajer.approval-restrukturisasi.addendum` | Ditolak bila belum disetujui; tampil setelah disetujui (5 baris jadwal) |
| M10 | `manajer.approval-pembiayaan.show` | Detail pengajuan; id tak ada → 404 |
| M11 | `manajer.pembiayaan.show` | Tunggakan & kolektibilitas dihitung dinamis; override kode dipetakan ke label; kode tak dikenal → label `default` |
| M12 | `manajer.keringanan-tazir.index` | Default hanya tahap `PENDING_MANAJER`; filter status + pencarian |
| M13 | `manajer.keringanan-tazir.approve` | Denda angsuran menjadi 0 & `DIBEBASKAN` tanpa membuat penangguhan; menolak tahap AO; pemisahan tugas |
| M14 | `manajer.keringanan-tazir.reject` | Catatan wajib; **saldo tidak dipotong**, ta'zir masuk `TazirPenangguhan` + usulan auto-debit `MENUNGGU_KONFIRMASI` |
| RBAC | 14 rute | `teller`/`ao`/`pengurus`/`dps` → 403 |

### Temuan

| Kode | Tingkat | Letak | Kesimpulan |
|---|---|---|---|
| M-25a | SEDANG | `ManajerController::pembiayaanShow()` | Override `status_kolektibilitas` dikirim **mentah** ke view, sehingga halaman menampilkan `kol5` — bukan `Macet (Kol-5)` — dan kode asing tampil apa adanya, padahal jalur hitung dinamis menampilkan label. **Bug produksi**, diperbaiki. |
| M-25b | — | fixture test | `anggota.no_anggota` varchar(20); penanda `TAZIRTOLAK0` membuat 21 karakter → `Data too long`. Salah pada test (format nomor diubah). |
| M-25c | — | assertion test | Asersi lama mengharapkan label pada override; setelah M-25a diperbaiki, asersi tetap dan kini mengunci pemetaan kode → label. |

### Perbaikan (minimal)

1. `tests/Feature/IntegritasRuteManajerTanpaTest.php` — berkas baru, 36 test
   (35 cakupan + 1 pagar RBAC).
2. `app/Http/Controllers/Dashboard/ManajerController.php` — `pembiayaanShow()`
   memetakan override melalui `labelKolektibilitas()` (kode asing → `default`),
   menyelaraskan jalur override dengan jalur hitung dinamis.

Test batch: **36 passed / 226 assertions**.

## Batch 26 — Rute AO tanpa rujukan test (11 rute)

Berkas: `tests/Feature/IntegritasRuteAoTanpaTest.php`.

| Kode | Rute | Perilaku yang dikunci |
|---|---|---|
| A1 | `ao.anggota.search` | Pencarian nama/no. anggota/CIF/no. HP; pencarian NIK tetap bekerja lewat blind index `nik_hash` |
| A2 | `ao.anggota.finansial` | Total simpanan sukarela (termasuk alias `Tabungan`/`Wadiah`), rekening utama, penanda deposito |
| A3 | `ao.pengajuan.create` | Form pengajuan dapat dibuka |
| A4 | `ao.pengajuan.store` | Margin & angsuran dihitung dari `margin_persen` produk; status `Menunggu`; `ao_id`/`created_by` = AO |
| A5 | `ao.pengajuan.show` | Detail pengajuan menampilkan nomor pengajuan |
| A6 | `ao.pengajuan.dokumen.upload` | Baris `dokumen_pengajuan_pembiayaan` + berkas tersimpan di disk `dokumen` |
| A7 | `ao.pengajuan.dokumen.hapus` | Baris dokumen & berkas fisik terhapus |
| A8 | `ao.restrukturisasi.store` | Guard keterlambatan > `kol2` (90 hari), tenor baru > sisa tenor, angsuran baru ≤ kemampuan bayar; snapshot sisa pokok/margin/piutang/angsuran lama |
| A9 | `ao.simulasi.index` | Metode flat: Σ pokok = plafon, sisa pokok bulan terakhir = 0 (selisih pembulatan dibebankan di angsuran terakhir) |
| A10 | `ao.survei.store` | Skor 5C tersimpan; rekomendasi `teruskan` → status `Direview` + pembiayaan `pending_manajer` |
| A11 | `ao.tunggakan.catatan` | Riwayat `catatan_penagihan` + sinkronisasi kolom `angsuran.catatan` |
| RBAC | 11 rute | `teller`/`manajer`/`pengurus`/`dps` → 403 |

### Temuan

| Kode | Tingkat | Letak | Kesimpulan |
|---|---|---|---|
| B-26a | TINGGI | `AOController::pengajuanStore()` L161–170 | Berkas survei ditulis ke `DokumenPembiayaan` dengan `pembiayaan_id = id pengajuan`. `dokumen_pembiayaan.pembiayaan_id` ber-FK ke tabel `pembiayaan`, dan `nama_dokumen` bukan kolom/`$fillable` sehingga `nama_file` (NOT NULL tanpa default) kosong → **HTTP 500 setiap unggahan berkas survei**. **Bug produksi**, diperbaiki. |
| B-26b | SEDANG | `AOController::searchAnggota()` L203–213 | `$extra = $a->nik ?: $a->no_hp` menyisipkan **NIK penuh terdekripsi** ke teks respons JSON autocomplete — pelanggaran minimisasi data UU PDP 27/2022. **Bug produksi**, diperbaiki. |

### Perbaikan (minimal)

1. `app/Http/Controllers/AOController.php` — `pengajuanStore()` menulis berkas
   survei ke `DokumenPengajuan` (`pengajuan_id`, `jenis_dokumen`, `nama_file`,
   `path_file`, `ukuran_file`, `diupload_oleh`); impor `DokumenPembiayaan`
   yang tak terpakai dihapus.
2. `app/Http/Controllers/AOController.php` — `searchAnggota()` tidak lagi
   memilih/`mengirim` kolom `nik`; teks autocomplete memakai nomor HP.

Test batch: **14 passed / 125 assertions**.

## Batch 27 — Modul DPS tanpa rujukan test (6 rute)

Berkas: `tests/Feature/IntegritasRuteDpsTest.php`.

| Kode | Rute | Perilaku yang dikunci |
|---|---|---|
| D1 | `dps.opini-catatan` | Arsip opini & catatan syariah tampil beserta judulnya |
| D2 | `dps.opini-catatan.store` | Default `kategori = opini_tahunan`, `status_kepatuhan = patuh`; lampiran tersimpan di disk `dokumen`; `judul` & `isi` wajib |
| D3 | `dps.pengesahan-akad.detail` | JSON detail hanya untuk pembiayaan `pending_dps`; status lain → 404 |
| D4 | `dps.pengesahan-akad.approve` | `tgl_pencairan` wajib; status → `disetujui`, `disahkan_oleh`/`tanggal_disahkan`/`catatan_dps`/`tgl_pencairan` tercatat; non-`pending_dps` → 404 |
| D5 | `dps.pengesahan-akad.reject` | `catatan_dps` wajib; status → `ditolak` + jejak pengesahan DPS |
| D6 | `dps.review-produk.approve` | Sertifikasi produk → `Disertifikasi` + audit log `SERTIFIKASI_PRODUK_DPS`; idempoten (sertifikasi ulang tidak menduplikasi audit log dan tidak menimpa catatan) |
| RBAC | 6 rute | `admin`/`manajer`/`pengurus`/`ao`/`teller` → 403 |

### Temuan

Tidak ada temuan. Seluruh perilaku keenam rute sesuai harapan pada eksekusi
pertama; **nol perubahan kode aplikasi** pada batch ini.

Test batch: **14 passed / 95 assertions**.

## Batch 28 — Rute `pengelola.*` tanpa rujukan test (11 rute)

Berkas: `tests/Feature/IntegritasRutePengelolaTanpaTest.php`.

Fokus batch ini adalah **antrean keputusan pengelola**: penolakan approval
transaksi, verifikasi pengajuan rekening, konfirmasi pembayaran simpanan awal,
serta review/tolak pengajuan pinjaman.

| Kode | Rute | Perilaku yang dikunci |
|---|---|---|
| P1 | `pengelola.approval.reject` | `catatan` wajib (maks 500); sukses menulis `REJECTED` + `catatan_approver` + `role_approver` + `approved_at`, transaksi referensi → `status_transaksi='Gagal'`, `alasan_penolakan`, `approved_by`; jejak `approval_transaksi_logs` peristiwa `ditolak`; pengaju tidak boleh memutus dirinya sendiri (`PemisahanTugas::PESAN`); peran di bawah status antrean ditolak service |
| P2 | `pengelola.pengajuan-rekening.index` | Hitungan per status dan filter status tampil sesuai data |
| P3 | `pengelola.pengajuan-rekening.show` | Detail menampilkan batas pembukaan dari `KebijakanKoperasi::batasPembukaanRekening()` |
| P4 | `pengelola.pengajuan-rekening.setujui` | Nominal wajib berada dalam batas kebijakan (kosong & `minimum - 1` → error validasi, status tidak berubah); nominal sah → `STATUS_MENUNGGU_PEMBAYARAN` |
| P5 | `pengelola.pengajuan-rekening.tolak` | `alasan_tolak` wajib dan tersimpan |
| P6 | `pengelola.pengajuan-rekening.tolak-pembayaran` | `catatan` wajib; bukti pembayaran ditolak dan status pengajuan dikembalikan |
| P7 | `pengelola.pengajuan-pinjaman.show` | Ringkasan pengajuan tampil (binding `PengajuanPembiayaan`) |
| P8 | `pengelola.pengajuan-pinjaman.review` | Hanya `pending_ao` yang boleh direview; sukses meneruskan ke pengurus (binding `Pembiayaan`) |
| P9 | `pengelola.pengajuan-pinjaman.tolak` | `catatan` wajib; alasan tersimpan pada `keterangan` + status `ditolak` |
| P10 | `pengelola.produk-pembiayaan.create` | Form baru dirender dengan `produk = null` |
| P11 | `pengelola.produk-pembiayaan.edit` | Form edit memuat data produk yang dipilih |
| RBAC | 11 rute | `admin`/`dps`/`anggota` → 403 untuk seluruh rute; akses silang antar kelompok izin (teller→approval, ao→produk, manajer→create, dsb.) → 403 |

### Temuan

Tidak ada cacat produksi. Dua kegagalan pertama pada pagar RBAC (404 alih-alih
403) berasal dari **fixture test**, bukan sistem: rute `pengajuan-pinjaman.show`
ber-binding `PengajuanPembiayaan` sedangkan `review`/`tolak` ber-binding
`Pembiayaan` pada segmen URI yang sama. Karena `SubstituteBindings` (grup `web`)
berjalan **sebelum** middleware `izin:`, id `Pembiayaan` yang tidak ada di tabel
`pengajuan_pembiayaan` menghasilkan 404 sebelum otorisasi sempat menolak.
Perbaikan: fixture `PengajuanPembiayaan` terpisah untuk rute `show`.

**Nol perubahan kode aplikasi** pada batch ini.

Test batch: **22 passed / 140 assertions**.

## Batch 29 — Sisa rute tanpa rujukan test (14 rute)

Berkas: `tests/Feature/IntegritasRuteSisaBatch29Test.php`.

Batch penutup pemetaan rute: 4 rute web sisa (settings, zakat, cicilan
simpanan wajib, detail pengajuan pembiayaan) + 10 rute `apiResource`
`rekening`/`akun` (5 aksi per resource).

| Kode | Rute | Perilaku yang dikunci |
|---|---|---|
| W1 | `admin.settings.clear-cache` | `super_admin` → redirect `admin.settings.index` + pesan sukses pemuatan ulang cache; `teller` → 403 |
| W2 | `zakat.ringkasan` | JSON selalu memuat dua dana (`zakat`, `infak_sedekah`) dengan 8 kunci saldo + `valid` boolean + `diperbarui_pada`; rentang terbalik (`dari` > `sampai`) → 422 pada `sampai` |
| W3 | `teller.simpanan-wajib.cicilan` | Tanpa hak auto-debit (`anggota.is_auto_debit = false`): **saldo tidak dipotong**, tagihan tetap `UNPAID`, tindakan tercatat `GAGAL`; tagihan `PAID` → pesan "sudah lunas" tanpa perubahan |
| W4 | `pengajuan-pembiayaan.show` | Pengurus melihat detail + nomor pengajuan; id tidak ada → 404 |
| A1 | `GET /api/rekening/{id}` | Detail rekening + relasi anggota/akun, 200 |
| A2 | `POST /api/rekening` | Nominal = batas minimum kebijakan → 201 & tersimpan |
| A3 | `POST /api/rekening` | Nominal `minimum − 1` → 422 `nominal_pembukaan`, tidak tersimpan |
| A4 | `PUT /api/rekening/{id}` | **K-03**: `saldo` yang dikirim diabaikan, saldo tidak berubah; atribut lain tetap diperbarui |
| A5 | `DELETE /api/rekening/{id}` | Hapus lunak (`assertSoftDeleted`), bukan hapus tegas |
| A6 | `GET /api/akun/{id}` | Detail akun (parent/children/rekening/jurnal), 200 |
| A7 | `POST /api/akun` | `super_admin` membuat akun → 201 & tersimpan |
| A8 | `PUT /api/akun/{id}` | `super_admin` memperbarui nama akun → 200 |
| A9 | `DELETE /api/akun/{id}` | Akun yang masih dirujuk `rekening` → **409** + pesan "tidak dapat dihapus" + tidak terhapus |
| A10 | `DELETE /api/akun/{id}` | Akun tanpa referensi → 200 + hapus lunak |
| RBAC-1 | 10 rute api | `anggota` → 403 untuk seluruh aksi; `dps`/`ao`/`super_admin` → 403 pada aksi tulis `rekening`; 7 peran non-`super_admin` → 403 pada aksi tulis `akun` |
| RBAC-2 | 4 rute web | `anggota` → 403; tamu → redirect `login` |

### Temuan

| Kode | Tingkat | Letak | Kesimpulan |
|---|---|---|---|
| B-29a | RENDAH | `routes/api.php` L47–58 vs L89–91 | **Paradox `super_admin`**: boleh `POST/PUT/DELETE /api/akun` (izin `coa.kelola`) tetapi `GET /api/akun/{id}` → 403, karena grup baca memakai `role:admin,manajer,pengurus,teller,ao,dps` tanpa `super_admin`. Dibuktikan lebih dulu lewat test, lalu **diperbaiki**: rute baca `akun` dipindah ke grup sendiri dengan `super_admin` ditambahkan, sehingga wewenang baca = wewenang tulis tanpa melebarkan akses resource lain. |
| B-29b | SANGAT RENDAH | `Teller\SimpananWajibController::pesanAlasanPenanganan()` L353–368 | Tidak ada cabang `TIDAK_BERHAK_AUTO_DEBIT` → pengguna menerima pesan generik "Tindakan tidak dapat dijalankan.". **Diperbaiki**: cabang pesan spesifik "Anggota belum menyetujui auto-debit — cicilan tidak dapat dipotong dari Simpanan Sukarela." |

### Perbaikan (minimal)

1. `routes/api.php` — `akun` dikeluarkan dari grup baca bersama, lalu dimasukkan
   ke grup `role:admin,manajer,pengurus,teller,ao,dps,super_admin` tersendiri.
   Resource lain tidak berubah.
2. `app/Http/Controllers/Teller/SimpananWajibController.php` —
   `pesanAlasanPenanganan()` mendapat cabang `TIDAK_BERHAK_AUTO_DEBIT`.
3. `tests/Feature/IntegritasRuteSisaBatch29Test.php` — test B-29a berubah dari
   `assertForbidden()` menjadi `assertOk()` + `data.id`/`data.kode_akun`
   (nama: `test_super_admin_dapat_menulis_dan_membaca_kembali_akun_lewat_api`),
   dan test W3 mengunci pesan auto-debit yang baru.

Test batch: **24 passed / 116 assertions**.

## Hasil gate

| Batch | Test | Assertions | Durasi | Exit |
|---|---|---|---|---|
| Awal langkah 4 | 2032 | 9481 | — | 0 |
| Batch 1 | 2039 | 9503 | 365.68s | 0 |
| Batch 2 | 2047 | 9529 | 376.47s | 0 |
| Batch 3 | 2054 | 9545 | 348.23s | 0 |
| Batch 4 | 2061 | 9604 | 370.84s | 0 |
| Batch 5 | 2067 | 9628 | 375.27s | 0 |
| Batch 6 | 2077 | 9668 | 363.34s | 0 |
| Batch 7 | 2082 | 9689 | 512.40s | 0 |
| Batch 8 | 2084 | 9694 | 370.30s | 0 |
| Batch 9 | 2087 | 9703 | 370.10s | 0 |
| Batch 10 | 2109 | 9758 | 335.31s | 0 |
| Batch 11 | 2120 | 9788 | 359.16s | 0 |
| Batch 12 | 2129 | 9808 | 378.50s | 0 |
| Batch 13 | 2140 | 9846 | 415.20s | 0 |
| Batch 14 | 2149 | 9896 | — | 0 |
| Batch 15 | 2158 | 9924 | — | 0 |
| Batch 16 | 2167 | 9955 | 432.18s | 0 |
| Batch 17 | 2178 | 10041 | 438.01s | 0 |
| Batch 18 | 2175 | 10053 | 397.87s | 0 |
| Batch 19 | 2178 | 10067 | 373.34s | 0 |
| Batch 20 | 2197 | 10126 | 390.03s | 0 |
| Batch 21 | 2207 | 10173 | 399.87s | 0 |
| Batch 22 | 2223 | 10238 | 496.48s | 0 |
| Batch 23 | 2239 | 10327 | 470.10s | 0 |
| Batch 24 | 2258 | 10433 | 522.10s | 0 |
| Batch 25 | 2294 | 10658 | 528.49s | 0 |
| Batch 26 | 2308 | 10782 | 507.78s | 0 |
| Batch 27 | 2322 | 10876 | 548.65s | 0 |
| Batch 28 | 2344 | 11015 | 549.61s | 0 |
| Batch 29 (setelah perbaikan B-29a/B-29b) | 2368 | 11131 | 429.34s | 0 |
| Verifikasi ulang (30 Okt) | 2178 | 10067 | 369.67s | 0 |

Catatan verifikasi ulang 30 Oktober 2026 (permintaan "uji ulang keseluruhan"):
gate penuh diulang dari keadaan berkas terkini — **2178 passed / 10067
assertions / 369.67s / exit 0**, tanpa regresi. `denyut:pemeriksaan` seluruh
indikator sehat; `route:list` memuat 377 rute aplikasi; 182 berkas Feature +
1 berkas Unit; tidak ada sisa berkas test diagnostik sementara.
`vendor\bin\pint --test` pada `app/`, `database/`, `routes/`: **0 berkas gagal**.
Kegagalan Pint terdeteksi hanya di `config/` (12), `bootstrap/app.php`,
`scripts/restore_verify.php`, dan 152 berkas `tests/` — seluruhnya
**pra-eksisting** (lihat utang teknis #1), tidak berasal dari batch ini.

Catatan Batch 29: jumlah test naik 24 (`2344` → `2368`) dari berkas baru
`IntegritasRuteSisaBatch29Test` yang menutup **14 rute terakhir** tanpa rujukan
test: 4 rute web (`admin.settings.clear-cache`, `zakat.ringkasan`,
`teller.simpanan-wajib.cicilan`, `pengajuan-pembiayaan.show`) + 10 aksi
`apiResource` `rekening`/`akun` — termasuk pagar khas: K-03 (`saldo` pada
`PUT /api/rekening` tidak pernah berubah), 409 untuk akun yang masih dirujuk,
hapus lunak rekening, dan gerbang otorisasi auto-debit `bayarCicilan`.
**Dua temuan diperbaiki** dengan perubahan minimal: B-29a (paradox `super_admin`
— rute baca `akun` dipindah ke grup `role:` tersendiri yang memuat
`super_admin`, sehingga wewenang baca = wewenang tulis) dan B-29b (cabang pesan
`TIDAK_BERHAK_AUTO_DEBIT` pada `pesanAlasanPenanganan()`).

Catatan Batch 28: jumlah test naik 22 (`2322` → `2344`) dari berkas baru
`IntegritasRutePengelolaTanpaTest` yang menutup 11 rute `pengelola.*`
(penolakan approval transaksi, daftar/detail/setujui/tolak pengajuan rekening,
tolak bukti pembayaran, detail/review/tolak pengajuan pinjaman, form
produk pembiayaan baru & edit) + 2 pagar RBAC. **Nol perubahan kode aplikasi**:
seluruh perilaku sudah benar sejak eksekusi pertama. Dua kegagalan awal pada
pagar RBAC (404 bukan 403) terbukti berasal dari fixture test — `show` ber-binding
`PengajuanPembiayaan` sedangkan `review`/`tolak` ber-binding `Pembiayaan` pada
segmen URI yang sama, dan `SubstituteBindings` berjalan sebelum `izin:`.

Catatan Batch 27: jumlah test naik 14 (`2308` → `2322`) dari berkas baru
`IntegritasRuteDpsTest` yang menutup 6 rute `dps.*` (halaman & simpan opini
catatan, detail/approve/reject pengesahan akad, sertifikasi produk) + 1 pagar
RBAC. **Nol perubahan kode aplikasi**: keenam rute sudah berperilaku benar
(guard status `pending_dps` via `findOrFail` → 404, validasi `tgl_pencairan`
dan `catatan_dps`, idempotensi sertifikasi produk yang tidak menduplikasi
audit log). Rute `dps.audit-akad`, `dps.beban.index`, `dps.jurnal-umum`,
`dps.laporan-keuangan*`, `dps.dana-kebajikan*`, `dps.zakat.*`, dan
`dps.review-produk.index` tidak dihitung karena sudah dirujuk test lain.

Catatan Batch 26: jumlah test naik 14 (`2294` → `2308`) dari berkas baru
`IntegritasRuteAoTanpaTest` yang menutup 11 rute `ao.*` (pencarian & info
finansial anggota, form/simpan/detail pengajuan, unggah & hapus dokumen
pengajuan, simpan restrukturisasi, simulasi, simpan survei 5C, catatan
penagihan) + 1 pagar RBAC. **Dua bug produksi diperbaiki**: (B-26a, TINGGI)
berkas survei pada `pengajuanStore()` ditulis ke `DokumenPembiayaan` dengan
`pembiayaan_id` = id pengajuan — FK ke tabel `pembiayaan` dan kolom
`nama_dokumen` tidak ada sehingga `nama_file` NOT NULL kosong dan unggahan
gagal HTTP 500; kini ditulis ke `DokumenPengajuan`; (B-26b, SEDANG)
`searchAnggota()` menyisipkan NIK penuh terdekripsi ke teks respons JSON
autocomplete — kini hanya nomor HP. Rute `ao.cif.*`, `ao.keringanan-penalti.*`,
dan `ao.keringanan-tazir.*` tidak dihitung karena sudah dirujuk test lain.

Catatan Batch 25: jumlah test naik 36 (`2258` → `2294`) dari berkas baru
`IntegritasRuteManajerTanpaTest` yang menutup 14 rute `manajer.*` (5 CIF,
5 approval restrukturisasi, `approval-pembiayaan.show`,
`pembiayaan.show`, 3 keringanan ta'zir). **Satu bug produksi diperbaiki**
(M-25a, SEDANG): `pembiayaanShow()` mengirim kode override kolektibilitas
mentah ke view sehingga halaman menampilkan `kol5`/kode asing alih-alih label
manusiawi; kini dipetakan lewat `labelKolektibilitas()` (kode asing →
`default`), seragam dengan jalur hitung dinamis. Dua temuan lain murni salah
test (batas varchar `no_anggota`). Rute `manajer.keringanan-penalti.*`
(3 rute) tidak dihitung karena sudah teruji di `Fase4RegresiTest`.

Catatan Batch 23: jumlah test naik 16 (`2223` → `2239`) dari berkas baru
`IntegritasModulTerminasiTest` yang menutup 5 rute `member-terminations.*`
yang belum pernah dirujuk test (`index`, `create`, `cari-anggota`, `tolak`,
`batalkan`). Sama seperti Batch 20 & 21, **nol perubahan kode aplikasi**:
satu-satunya temuan (T-23a, RENDAH) terbukti salah pada assertion test —
`AuditLog::record()` menulis `no_referensi ?? no_terminasi` ke kolom
`keterangan`, sehingga pencarian jejak audit harus memakai `no_referensi`.

Catatan Batch 22: jumlah test naik 16 (`2207` → `2223`) dari berkas baru
`IntegritasKanalNasabahTest` yang menutup 11 rute `nasabah.*`. Berbeda dari
Batch 20 & 21, batch ini **mengubah 1 berkas aplikasi** —
`NasabahController::pengajuanPinjamanStoreBaru()` — untuk memperbaiki
**BUG-25 (TINGGI)**: kolom dokumen ditulis dengan nama yang tidak ada di
`$fillable` (`nama_dokumen`/`path`) sehingga `nama_file` (NOT NULL tanpa
default) kosong dan unggahan gagal HTTP 500 di akhir wizard 3 langkah.

Catatan Batch 21: jumlah test naik 10 (`2197` → `2207`) dari berkas baru
`IntegritasDraftShuDanEksporCifTest` — **tanpa skip**, seluruh pagar
kebijakan terbukti aktif. Sama seperti Batch 20, **nol perubahan kode
aplikasi**: kedua temuan terbukti berada di sisi test (konstanta salah kelas)
atau keterbatasan data uji (batas 50.000 baris), bukan cacat sistem.

Catatan Batch 20: jumlah test naik 19 (`2178` → `2197`), seluruhnya dari
berkas baru `IntegritasRutePengurusTerbukaTest` (17 test cakupan + 2 test
pengunci wewenang 403). **Nol perubahan kode aplikasi** pada batch ini —
temuan T-20b terbukti salah pada fixture test, bukan pada sistem.

Catatan Batch 19: jumlah test naik 3 dibanding Batch 18 (`2175` → `2178`).
Penyebab utamanya dua test pengunci baru (Q-01, Q-02) pada
`IntegritasPelepasanHoldShuTest` (kini 11 test); sisanya dari pergeseran
penghitungan dataset `#[DataProvider]`. Seluruh berkas test Feature dijalankan
dan `PASS`.

Pint berkas batch 1–28: `PASS` (Batch 28:
`IntegritasRutePengelolaTanpaTest.php` — 1 berkas PASS tanpa perubahan gaya;
Batch 27:
`IntegritasRuteDpsTest.php` — 1 berkas PASS tanpa perubahan gaya; Batch 26:
`AOController.php` + `IntegritasRuteAoTanpaTest.php` — 2 berkas PASS
tanpa perubahan gaya; Batch 25:
`ManajerController.php` + `IntegritasRuteManajerTanpaTest.php` — 2 berkas PASS
tanpa perubahan gaya; `pint --test app database routes` → 449 berkas PASS;
Batch 24:
`IntegritasRuteTellerTanpaTest.php` + `DepositoPenaltyReliefController.php` +
`DepositoProduct.php` — 3 berkas, 1 perbaikan gaya otomatis
`unary_operator_spaces`/`no_unused_imports`/`not_operator_with_successor_space`,
test diulang setelah perbaikan dan tetap 19 passed; Batch 23:
`IntegritasModulTerminasiTest.php` — 1 perbaikan gaya otomatis, test diulang
dan tetap hijau; Batch 22: `NasabahController.php` +
`IntegritasKanalNasabahTest.php` — 2 berkas, 1 perbaikan gaya otomatis
`line_ending`/`unary_operator_spaces`/`not_operator_with_successor_space`,
test diulang setelah perbaikan dan tetap 16 passed; Batch 21:
`IntegritasDraftShuDanEksporCifTest.php` — 1 perbaikan gaya otomatis
`unary_operator_spaces`/`no_unused_imports`/`not_operator_with_*`, test diulang
setelah perbaikan dan tetap 10 passed; Batch 20:
`IntegritasRutePengurusTerbukaTest.php` — satu-satunya berkas yang disentuh;
Batch 19: `ShuController.php`, `ShuCalculatorService.php`,
`IntegritasPelepasanHoldShuTest.php`).
`php artisan db:cadangkan` + `php artisan denyut:pemeriksaan`: seluruh
indikator sehat (basis data tersambung, jurnal seimbang, rekonsiliasi subledger
cocok, cadangan terenkripsi 51.7 KB).

## Utang teknis (belum dikerjakan)

1. Pelanggaran Pint pra-eksisting di puluhan berkas test lama.
2. `.gitignore` berisi `*` sehingga tidak ada berkas yang terlacak git;
   riwayat perubahan tidak dapat ditinjau lewat `git diff`.
3. Daftar status pembiayaan pada tampilan form angsuran
   (`TellerController::angsuranForm()`) dan `RunAutodebetAngsuran` belum
   diseragamkan ke `Pembiayaan::statusBerjalan()`. Penegakan sudah aman
   (service menolak pembayaran di luar status berjalan), tetapi tampilan masih
   bisa menawarkan baris yang akan ditolak.
4. `PemeliharaanSistemTest::test_pemantauan_operasional_melaporkan_seluruh_indikator_sehat`
   membaca berkas cadangan nyata di `storage/app/backups`, sehingga gate
   bergantung pada jadwal backup harian dan akan merah bila backup tertunda.
   Sebaiknya memakai direktori cadangan sementara seperti test lain di berkas
   yang sama.
5. Kontrak "wajib konfirmasi" kini dijaga di **tiga** titik dan harus dipelihara
   bersamaan: `MandatorySavingsService::autoDebit($…, bool $viaKonfirmasi = false)`
   (batch 8), `AngsuranTransactionService::proses()` lewat opsi
   `'via_konfirmasi'` (batch 9), dan `TazirService::prosesAutodebetSusulan($…,
   bool $viaKonfirmasi = false)` (batch 9). Pemanggil baru WAJIB menilai sendiri
   apakah pemotongan sudah mendapat persetujuan manusia; default `false` sengaja
   dipilih agar kelalaian berujung pada penolakan (fail-safe), bukan pemotongan
   dana. Idealnya suatu kali disatukan menjadi satu penjaga bersama, tetapi
   perubahan itu sengaja ditunda agar tiap batch tetap kecil dan terukur.
6. (batch 10) Tidak ada UI untuk mengelola `pembiayaan_penjamin`: tabel dibuat
   oleh migrasi exit dan hanya dibaca `kendalaPenjamin()`, sehingga penjamin
   baru harus didaftarkan lewat basis data. Akibatnya gerbang penjamin praktis
   tidak pernah berbunyi pada koperasi yang tidak mengisi tabel itu. Perlu
   formulir pendaftaran penjamin pada pengajuan/pembiayaan, beserta validasi
   bahwa penjamin sudah menjadi anggota aktif dan tidak sedang menjamin
   dirinya sendiri.
7. (batch 10) `kendalaPenjamin()` memakai whitelist `Pembiayaan::statusTercair()`
   + `hapus_buku` + `diragukan`. Bila kelak ada status pembiayaan baru yang
   tetap menahan penjamin (mis. restrukturisasi gagal), status itu harus
   didaftarkan eksplisit — kalau tidak, penjamin dapat keluar lebih awal.
8. (batch 11) Konvensi `jumlah_bayar` = **porsi angsuran saja** kini
   mengikat: ta'zir dan tunggakan wajib dikirim terpisah lewat
   `nominal_tazir` / `tunggakan_tazir`. Pemanggil baru
   (`BayarAngsuranAction`, `AutodebitService`, `TazirService`) sudah patuh,
   tetapi kontrak ini belum ditegakkan secara tipe (mis. lewat DTO/validasi
   tunggal). Perlu satu validasi di pintu masuk agar pemanggil lama tidak
   diam-diam memasukkan ta'zir ke dalam `jumlah_bayar`.
9. (batch 11) Saklar `TazirService::fiturAktif()` kini **tidak** mematikan
   `nominal_tazir` eksplisit dari pemanggil (hanya tunggakan) demi menjaga
   pelunasan ta'zir yang sudah disepakati kas. Konsekuensinya, gerbang saklar
   untuk ta'zir periode berjalan sepenuhnya bergantung pada lapisan UI/Action.
   Perlu audit terpisah bahwa `TellerController` dan `BayarAngsuranAction`
   tidak pernah mengirim `nominal_tazir > 0` saat fitur mati.
10. (batch 11) Mode `FIXED_PER_KEJADIAN` belum memiliki kebijakan berapa kali
    boleh ditagih per kontrak (saat ini `calculate()` mengembalikan `rate`
    sekali per pemanggilan dan anti-tumpuk hanya diuji lewat perulangan
    `calculate()`, bukan lewat data tunggakan tersimpan).
11. (batch 12) Makna "margin terakui" pada baris `Dibayar Sebagian` kini
    dihitung ulang di tempat berbeda — `RestrukturisasiService::marginBelumTerakui()`
    merekonstruksi dari kolom tersimpan, sementara `AngsuranTransactionService`
    memilikinya langsung saat transaksi. Tabel `angsuran` **tidak menyimpan
    sisa margin**; selama belum ada kolom/ledger margin eksplisit, kedua rumus
    wajib dijaga tetap sinkron. Kandidat perbaikan struktural: simpan
    `margin_terbayar`/`sisa_margin` di baris angsuran.
12. (batch 12) Pencarian row-level pada `ringkasan()` memakai `get([...])`
    dengan daftar kolom eksplisit; bila kelak ada kolom masa depan yang
    dibutuhkan `marginBelumTerakui()`, daftar ini harus ikut diubah — belum
    ada test yang menjaga kontrak daftar kolom tersebut.
13. (batch 13) Baris **singleton** `kebijakan_koperasis` dibaca `Setting::`
    oleh seluruh modul, sehingga setiap test yang mengubah kolomnya berisiko
    mencemari berkas test lain (terbukti: `akad_margin` membuat
    `DashboardDpsPendapatanTest` merah pada gate penuh pertama batch 13).
    Perbaikan saat ini hanya lokal (`setUp`/`tearDown` di
    `IntegritasPelaporanPpatkTest`). Kandidat solusi terpusat: trait
    `MemulihkanKebijakan` di `Tests\TestCase` yang menyimpan-memulihkan baris
    kebijakan secara otomatis, lalu diterapkan ke seluruh test yang menulis
    tabel itu. Belum ada deteksi otomatis bila sebuah test lupa memulihkan.
14. (batch 13) `AmlComplianceController::pesanStatusTerminal()` baru dipakai
    pada `verify()` dan `tolakAml()`. Jalur AML lain (dashboard kepatuhan,
    aksi massal, atau modul laporan yang memutus status kepatuhan) belum
    diperiksa apakah memerlukan penjagaan status terminal yang sama.
15. (batch 14) `ao.anggota.quick-store` tidak punya **pemanggil di UI** —
    tidak ada satu pun rujukan `quick-store` di `resources/` maupun `public/`.
    Setelah B-02a, rute kini mewajibkan `kota` & `provinsi`; bila modal AO
    (di luar repo ini, mis. aplikasi mobile) tidak mengirim keduanya, rute
    akan membalas 422. Perlu verifikasi terhadap klien yang memakainya.
16. (batch 14) `AOController::quickStoreAnggota()` belum memakai transaksi
    basis data. Kompensasi manual (hapus berkas KTP di blok `catch`) sudah
    ditambahkan, tetapi pola ini berbeda dari `CifController` /
    `PengajuanRekeningController` yang membungkus seluruh alur dalam
    `DB::transaction()`. Kandidat penyelarasan.
17. (batch 16) `TellerController::generateJadwalAngsuran()` menyusun baris
    angsuran dengan `margin_ujroh => 0` dan `jumlah_angsuran = pokok` saja,
    serta menghitung jatuh tempo `tanggal_persetujuan` + i bulan tanpa
    `HariLiburService`. `DisbursementService` (jalur resmi) memakai margin
    akad dan pergeseran hari libur. Dua jalur penerbitan jadwal ini akan
    menghasilkan jadwal berbeda untuk akad yang sama. Bila endpoint ini
    memang jalur cadangan, atau seharusnya dilarang saat
    `DisbursementService` sudah menerbitkan jadwal, keputusannya perlu
    diambil eksplisit.
18. (batch 16) `ProsesTazirAction` membuat `no_transaksi` berformat
    `TRX-TAZIR-YmdHis`. Dua ta'zir pada detik yang sama (mis. klik ganda dari
    dua peramban) berpotensi bentrok pada indeks unik. Perlu nomor urut
    sekuensial seperti pembuatan `no_transaksi` modul lain.
19. ~~(batch 17) `no_transaksi` pelepasan HOLD berformat
    `SHU-REL-YmdHis-{anggota_id}`. Dua pelepasan untuk anggota yang sama pada
    detik yang sama berpotensi bentrok pada indeks unik.~~
    **TUNTAS (batch 18)** — memakai `NomorSequence::berikutnya()` dengan
    prefix `SHU-REL-Ymd-`; terbukti pada P-09 (dulu `Duplicate entry`).
20. ~~(batch 17) Logika pencairan SHU ditahan **terduplikasi** di dua tempat
    (`ShuCalculatorService::cairkanShuDitahan()` dan
    `ShuController::cairkanShuDitahan()`), termasuk auto-create rekening.
    Keduanya sudah pernah menyimpang (BUG #1 hanya diperbaiki serentak karena
    kebetulan). Sebaiknya controller memanggil service, bukan menyalin.~~
    **TUNTAS (batch 19)** — `ShuCalculatorService::cairkanBilaDalamPenahanan()`
    menjadi satu-satunya jalur pencairan; salinan ±110 baris di controller
    dihapus dan diganti delegasi.
21. ~~(batch 17) `ShuController::lepaskanPenahanan()` (satuan) masih memeriksa
    `tunggakanAnggota()` di luar transaksi sebelum memanggil
    `cairkanShuDitahan()`. Klaim atomik baru diterapkan pada jalur **massal**.
    Jalur satuan belum diuji untuk konkurensi (klik ganda).~~
    **TUNTAS (batch 18)** — klaim atomik `lockForUpdate()` + `is_hold` +
    `transaksi_id IS NULL` di dalam transaksi; dikunci oleh P-09.
24. ~~(batch 18) `ShuController::cairkanShuDitahan()` masih **menyalin** logika
    service (sisa utang #20). Batch 18 menyelaraskan kedua salinan (baseline
    `transaksi_id`, nomor sekuensial), tetapi duplikasi tetap menjadi sumber
    penyimpangan berikutnya. Kandidat: hapus salinan controller dan panggil
    `ShuCalculatorService` publik.~~
    **TUNTAS (batch 19)** — salinan dihapus; controller memanggil
    `cairkanBilaDalamPenahanan()`.
25. (batch 18) `NomorSequence` dipakai dengan prefix khusus `SHU-REL-Ymd-`
    sementara awalan nomor transaksi lain mengikuti `NomorDokumen::kode()`
    (dapat diubah Pengurus). Prefix `SHU-REL` masih tertanam di kode; bila
    Pengurus ingin mengubahnya lewat UI, perlu kunci baru pada `NomorDokumen`.
22. ~~(batch 17) `ShuController::proses()` mengisi `rekening.akun_id` dengan
    `$coaSukarela->id` (ID tabel `coas`), sedangkan FK `rekening.akun_id`
    menunjuk tabel `akun` legacy (paritas `PengajuanRekeningController`,
    `DepositoService`). Belum terbukti menimbulkan galat karena kolomnya hanya
    NOT NULL tanpa FK keras — perlu test pembuktian sebelum diubah.~~
    **TUNTAS (batch 19)** — terbukti bug nyata (Q-01: `20 !== 10`, BUG-23);
    kini memakai `JenisSimpanan::akunLegacyId()`.
23. ~~(batch 17) FK `shu_distributions.shu_period_id` memakai
    `onDelete('cascade')`, tetapi `ShuPeriod` ber-`SoftDeletes` sehingga cascade
    tidak pernah berjalan. Penghapusan eksplisit di controller hanya menutup
    satu pintu; pemanggilan `forceDelete()`/`delete()` lain bisa mengulang bug
    yang sama. Kandidat: hard delete + `deleting` observer, atau FK tanpa
    cascade + pembersihan terpusat.~~
    **SELESAI (batch 19) — sebagian false positive.** `forceDelete()` terbukti
    menjalankan cascade (Q-02). Hapus lunak memang menyisakan baris distribusi,
    tetapi jalur produksi `destroy()` sudah menghapus distribusi lebih dulu.
    Perilaku cascade dikunci oleh Q-02.
26. (batch 19) `shu_periods.tahun_buku` **tidak** memiliki indeks unik di basis
    data (hanya `PRIMARY(id)`); keunikan tahun buku dijaga oleh alur aplikasi.
    Bila kelak ditambahkan indeks unik, `SoftDeletes` akan memblokir pembuatan
    ulang label periode yang pernah dihapus — perlu partial/functional index
    atau daftar nilai terhapus ikut diperiksa.
