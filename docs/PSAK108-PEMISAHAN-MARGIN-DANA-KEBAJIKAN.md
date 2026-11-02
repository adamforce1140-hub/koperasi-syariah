# PSAK 108/109 — Pemisahan Absolut Margin Pembiayaan vs Dana Kebajikan

Dokumen audit (internal & eksternal) atas aturan dan validasi ketat yang menjamin
**pemisahan mutlak** antara akun **Margin Pembiayaan** (Pendapatan Operasional /
komponen pembentuk SHU) dan **Dana Kebajikan** (non-operasional).

| Aspek | Nilai |
|---|---|
| Dasar syariah | PSAK 101/108/109, Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000 (ta'zir), No. 19/DSN-MUI/IV/2001 (Qardhul Hasan) |
| Modul | Pembiayaan & Akuntansi |
| Kelas utama | `App\Services\AutoJournalingService`, `App\Services\DanaKebajikanService`, `App\Services\TazirService` |
| Sifat aturan | **PERMANEN** — tidak dapat dimodifikasi dari UI; pelanggaran selalu ditolak + dicatat di log |
| Test | `tests/Feature/Psak108PemisahanMarginDanaKebajikanTest.php`, `tests/Feature/DanaKebajikanTazirTest.php` |

---

## 1. Peta Akun (CoA) yang Dipertahankan

Aturan berikut **mempertahankan pemetaan CoA existing** — tidak ada akun baru yang
dibuat. Konstanta bersumber tunggal dari `App\Support\Coa`.

| Kode | Konstanta | Peran |
|---|---|---|
| `1-1001` | `KAS_TELLER` | Kas Teller |
| `1-2001` | `PIUTANG_MURABAHAH` | Piutang Murabahah |
| `1-2002` | `PIUTANG_MUDHARABAH` | Piutang Mudharabah |
| `1-2003` | `PIUTANG_MUSYARAKAH` | Piutang Musyarakah |
| `1-2004` | `PIUTANG_IJARAH` | Piutang Ijarah |
| `1-2005` | `PINJAMAN_QARDH` | Pokok Piutang Qardhul Hasan (tanpa margin) |
| `2-1001` | `SIMPANAN_SUKARELA` | Simpanan Sukarela Anggota (sisi auto-debet) |
| `2-3001` | `DANA_KEBAJIKAN` | **Kewajiban Dana Kebajikan / Dana Sosial** |
| `2-3003` | `DANA_SOSIAL` | Dana Sosial |
| `4-1001` | `PENDAPATAN_MARGIN` | **Pendapatan Margin Murabahah** |
| `4-1002` | `PENDAPATAN_BAGI_HASIL` | Bagi Hasil Mudharabah |
| `4-1003` | `PENDAPATAN_BAGI_HASIL_MUSYARAKAH` | Bagi Hasil Musyarakah |
| `4-1004` | `PENDAPATAN_IJARAH` | Pendapatan Ijarah (ujrah) |

**Kelas `4-`** = Pendapatan Operasional (prefix `PREFIX_PENDAPATAN_OPERASIONAL`).
**`2-3001` & `2-3003`** = penampung dana sosial (`AKUN_DANA_SOSIAL`).

---

## 2. Aturan Pengakuan Margin Pembiayaan (Pendapatan Operasional)

Seluruh margin dari Murabahah/Mudharabah/Musyarakah masuk **100%** sebagai
Pendapatan Operasional dan menjadi komponen pembentuk SHU — **tidak boleh**
dialokasikan ke Dana Kebajikan/Dana Sosial.

### 2.1 Pencairan / Dropping

`AutoJournalingService::jurnalPencairan()` — sumber jurnal `'Pencairan Pembiayaan'`:

| Posisi | Akun |
|---|---|
| DEBET | `1-200x` Piutang/Pembiayaan sesuai akad |
| KREDIT | `2-1001` Simpanan Sukarela Anggota |

Pemetaan akun piutang per akad (`piutangByAkad`): Murabahah→`1-2001`,
Mudharabah→`1-2002`, Musyarakah→`1-2003`, Ijarah→`1-2004`, Qardh→`1-2005`.
Qardh **tidak memiliki akun pendapatan** (`pendapatanByAkad()` → `null`), sehingga
tidak ada margin yang dapat diakui sejak tahap pencairan.

### 2.2 Penerimaan Angsuran (Teller / Auto-Debit)

`AngsuranTransactionService::postJurnal()` — sumber jurnal `'Angsuran'`:

| Posisi | Akun | Keterangan |
|---|---|---|
| DEBET | `1-1001` Kas Teller (Teller) atau `2-1001` Simpanan Sukarela (Auto-Debit) | sebesar total pembayaran |
| KREDIT | `1-200x` Piutang sesuai akad | porsi pokok |
| KREDIT | `4-100x` Pendapatan sesuai akad | **porsi margin bulan berjalan** |
| KREDIT | `2-3001` Dana Kebajikan | porsi ta'zir (jika ada, lihat §3) |

Pengakuan margin bersifat **per-angsuran (bertahap)**: porsi margin yang diakui
pada setiap angsuran divalidasi terhadap jadwal angsuran terdaftar
(`angsuran.angsuran_ke`, `pokok`, `margin`). Untuk akad Qardh, baris margin
di-skip secara eksplisit karena `akunPendapatan === null`.

---

## 3. Pemisahan Dana Kebajikan / Dana Sosial (Non-Operasional)

### 3.1 Daftar Sumber Tertutup (Whitelist Permanen)

`DanaKebajikanService::sumberDanaKebajikanValid()` — Dana Kebajikan hanya boleh
berasal dari **dua kategori**:

| Kategori | Konstanta | Syarat |
|---|---|---|
| **a. Denda Ta'zir / Late Fee** | `KATEGORI_TAAZIR` | **Hanya sah bila** parameter `cooperative_settings.financing_late_fee_per_day` (kolom `kebijakan_koperasis.financing_late_fee_per_day`) **> 0**. Jumlah divalidasi terhadap nilai terkonfigurasi. |
| **b. Infaq / Shadaqah / Pengembalian Qardhul Hasan** | `KATEGORI_DONASI` | Selalu sah. |

Sumber di luar daftar ini **ditolak secara permanen**
(`KATA_KUNCI_MARGIN_TERLARANG` — setiap jenis transaksi yang mengandung penanda
`margin`, `murabahah`, `mudharabah`, `musyarakah`, `bagi hasil`, `pendapatan`).

### 3.2 Jurnal Ta'zir / Late Fee (tidak dapat dimodifikasi)

| Posisi | Akun |
|---|---|
| DEBET | `1-1001` Kas Teller |
| KREDIT | `2-3001` Kewajiban Dana Kebajikan / Dana Sosial |

Tidak ada pengecualian. Ta'zir dibukukan **cash basis** — hanya saat benar-benar
diterima (PSAK 109). Reversal/void (`jurnalReversalTazir()`) membalik dengan
DEBET `2-3001` / KREDIT `2-1001` atau `1-1001` sesuai sumber dana asal.

### 3.3 Pembiayaan Qardh (Pinjaman Kebajikan)

`AutoJournalingService::marginQardhDilarang($akad, $margin, $ujroh)` — akad Qardh
**dilarang memiliki margin/ujroh apa pun**. Hanya **Pokok Piutang Qardh**
(`1-2005`) yang dicatat, baik saat pencairan maupun pengembalian. Pelanggaran
ditolak dan dicatat dengan aturan `QARDH_TANPA_MARGIN`.

---

## 4. Validasi COA & Auto-Journaling Service

`AutoJournalingService::post($keterangan, $entries, $sumber, ..., ?string $postType = null)`
menjalankan **3 lapis guard** (semuanya permanen, tidak dapat dilonggarkan dari UI):

### Lapis 2 — `mappingPostTypeValid()`

`$postType` di-resolve otomatis via `deteksiPostType($sumber)` bila tidak diberikan
eksplisit. Pemetaan sah (`AKUN_KREDIT_SAH_BY_POST_TYPE`):

| POST_TYPE | Akun KREDIT sah |
|---|---|
| `POST_TYPE_MARGIN` | `4-1001`, `4-1002`, `4-1003`, `4-1004` |
| `POST_TYPE_TAAZIR` | `2-3001` |
| `POST_TYPE_DONATION` | `2-3001` |
| `POST_TYPE_QARDH` | `1-2005` — **cek tambahan:** tidak boleh ada kredit ke akun Pendapatan Operasional (`4-`) |

Mapping ke akun di luar daftar → ditolak.

### Lapis 1 — `posisiMarginValid()`

Menolak pola **misrouting margin**: baris DEBET pada akun Pendapatan (`4-`) yang
dipasangkan dengan baris KREDIT pada `2-3001`/`2-3003` — yaitu memindahkan margin
keluar dari Pendapatan Operasional.

### Lapis 3 — `posisiCampuranMarginValid()`

Melarang **pencampuran `4-1001` (Pendapatan Margin) dengan `2-3001` (Dana
Kebajikan)** dalam satu entri jurnal. Bila dijumpai baris **DEBET** pada akun
Pendapatan Operasional (`4-`) — yaitu margin sedang dialokasikan keluar —
maka **setiap** baris pada `2-3001`/`2-3003` **DILARANG berposisi DEBET**.
Pengecualian: `SUMBER_GABUNGAN_SAH = ['Angsuran']`.

> **Rasional pengecualian:** jurnal angsuran anggota yang memuat pokok + margin +
> denda keterlambatan adalah jurnal gabungan yang **SAH** secara syariah. Yang
> dilarang adalah **mengalokasikan** margin ke Dana Kebajikan, bukan keberadaan
> kedua akun dalam satu jurnal angsuran.

### Log Audit & Mekanisme Penolakan

Setiap penolakan di `post()` kini melewati **satu gerbang tunggal** `tolakPosting()`
yang melakukan **dua hal sekaligus**:

1. **Logging alur (GAGAL)** — `Log::error('AutoJournaling: GAGAL posting jurnal — <pesan>')`
   dengan konteks terstruktur: `tahap='GAGAL'`, `aturan`, `sumber`, `transaksi_id`.
2. **Melempar `App\Exceptions\JournalPostingException`** (bukan lagi `return null`).

Karena exception dilempar, `DB::transaction` pemanggil **otomatis rollback** —
inilah penegakan prinsip *"hanya mencatat transaksi yang telah berhasil diproses
sepenuhnya"*: bila validasi jurnal gagal, tidak ada data transaksi yang tersisa
tanpa jurnal. Exception membawa properti `aturan`, `sumber`, `transaksi_id`, dan
`konteks` sehingga pemanggil dapat menangani/menelusuri penyebabnya.

`DanaKebajikanService::catatPelanggaranSumberDana(Transaksi $transaksi, string $alasan)`
→ `Log::error(...)` + `AuditLog::record('Pelanggaran Sumber Dana Kebajikan', ...)`
sehingga upaya misrouting dari jalur transaksi tunai teller juga terekam di tabel
audit dan dapat ditelusuri pada audit internal maupun eksternal.

---

## 5. Matriks Uji (Skenario yang HARUS Ditolak / Diizinkan)

Seluruh skenario berikut terverifikasi otomatis.

| # | Skenario | Harapan |
|---|---|---|
| 1 | `POST_TYPE_MARGIN` mengkredit `2-3001` | **Ditolak** |
| 2 | `POST_TYPE_TAAZIR` mengkredit `4-1001` | **Ditolak** |
| 3 | `POST_TYPE_DONATION` mengkredit selain `2-3001` | **Ditolak** |
| 4 | DEBET Pendapatan `4-` / KREDIT `2-3001` | **Ditolak** |
| 5 | Campuran `4-1001` + `2-3001` di luar sumber `'Angsuran'` | **Ditolak** |
| 6 | Jurnal gabungan angsuran (pokok+margin+ta'zir), sumber `'Angsuran'` | **Diizinkan** |
| 7 | Setiap penolakan | Tercatat di log audit |
| 8 | Akad Qardh dengan margin > 0 | **Ditolak** |
| 9 | Akad Qardh hanya pokok `1-2005` | **Diizinkan** |
| 10 | Ta'zir saat `financing_late_fee_per_day = 0` | **Ditolak** |
| 11 | Infak/Shadaqah/Qardhul Hasan | **Diizinkan** |
| 12 | Sumber dana mengandung penanda margin | **Ditolak** |

---

## 6. Parameter Dinamis

| Parameter | Lokasi | Default | Fungsi |
|---|---|---|---|
| `financing_late_fee_per_day` | tabel `kebijakan_koperasis`, dibaca via `Setting::financingLateFeePerDay()` | `0.00` | Nominal denda keterlambatan per hari. **0 = fitur ta'zir nonaktif.** |
| `tazir_mode` | `Setting::get('tazir_mode')` | `FLAT_HARIAN` | Mode perhitungan ta'zir (`DISABLED` mematikan fitur) |

Gate `TazirService::fiturAktif()` → `false` bila `tazir_mode === 'DISABLED'` atau
`financing_late_fee_per_day <= 0`. Saat nonaktif, `TazirService::calculate()`
mengembalikan `0.0` dan `TellerController::prosesTazir()` menolak permintaan.

---

## 7. Bukti Pengujian

```
php artisan test tests/Feature/Psak108PemisahanMarginDanaKebajikanTest.php \
                  tests/Feature/DanaKebajikanTazirTest.php
→ Tests: 44 passed (157 assertions)

php artisan test
→ Tests: 715 passed (3613 assertions)
```

---

## 8. Catatan Perubahan terhadap Spesifikasi Awal

Spesifikasi awal menyebut `2-4001` (Dana Kebajikan) dan `2-3001` (Margin
Ditangguhkan) serta Qardh di `1-2002`. Setelah konfirmasi pemilik sistem,
implementasi **mempertahankan arsitektur existing** agar tidak memutus data
historis:

| Spesifikasi awal | Implementasi final | Alasan |
|---|---|---|
| Dana Kebajikan di `2-4001` | Tetap `2-3001` | Akun existing; menghindari migrasi saldo |
| Margin Ditangguhkan `2-3001` | **`1-2101`** (`Coa::MARGIN_DITANGGUHKAN`) | Dikoreksi dari rancangan `2-4001`: margin ditangguhkan adalah **akun kontra aset**, bukan kewajiban; diakui bertahap (DEBET `1-2101` \| KREDIT `4-100x`) per angsuran |
| Qardh di `1-2002` | Tetap `1-2005` | `1-2002` adalah Piutang Mudharabah |
| Larang semua jurnal campuran | Larang hanya misrouting margin | Jurnal gabungan angsuran sah secara syariah |

Substansi aturan (pemisahan absolut margin vs dana kebajikan, whitelist sumber
dana, larangan margin Qardh, log audit) tetap ditegakkan sepenuhnya.

> **Koreksi dokumen (5 Oktober 2026):** tabel di atas sebelumnya menyatakan
> "Margin Ditangguhkan `2-3001` — Tidak dibuat". Klaim itu **tidak lagi akurat**:
> akun margin ditangguhkan **dibuat** dengan kode kanonik **`1-2101`**
> (`App\Support\Coa::MARGIN_DITANGGUHKAN`) dan digunakan oleh
> `AutoJournalingService` (amortisasi margin per-angsuran). Basis tunggal kode
> akun adalah `App\Support\Coa` — tidak ada kode akun hardcode.
