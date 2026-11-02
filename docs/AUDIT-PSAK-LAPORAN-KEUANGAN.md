# Laporan Audit Kepatuhan PSAK atas Laporan Keuangan Koperasi Syariah

**Tanggal audit**: 1 Oktober 2026
**Periode yang diuji**: 1 Januari 2026 s/d 31 Oktober 2026
**Ruang lingkup**: Seluruh komponen laporan keuangan (Neraca, Laba Rugi, Perubahan Ekuitas, Arus Kas, CaLK), pencatatan transaksi akad syariah, dan pengungkapan wajib.
**Standar acuan**: PSAK 101, 102, 103, 104, 105, 106, 107, 109, 71; Fatwa DSN-MUI No. 08/DSN-MUI/IV/2000, No. 17/IX/2000, No. 19/IV/2001.
**Kesimpulan umum**: **Laporan keuangan LAYAK DITERBITKAN.** Seluruh temuan material telah diperbaiki dan diverifikasi ulang. Regresi penuh **1170 passed (5354 assertions), 0 failure**.

---

## 1. Metodologi

Audit dilakukan dengan prinsip **jurnal sebagai satu-satunya sumber kebenaran nilai uang** (tabel `jurnal` + `jurnal_detail`, kolom kanonik `coa_id`). Setiap angka laporan direkonsiliasi ulang terhadap mutasi jurnal berstatus **Posted** pada batas `tanggal_jurnal` (tanggal kejadian), bukan `created_at`.

Uji yang dijalankan:
- Rekonsiliasi silang antarkomponen (identitas akuntansi: Aset = Kewajiban + Ekuitas).
- Identitas buku seimbang: `mutasi kas bersih ≡ (Pendapatan − Beban) + Σ akun neraca non-kas (kredit − debet)`.
- Uji regresi otomatis (`lib/LaporanPsak101Test`, dll.) untuk mengunci perilaku.
- Verifikasi data nyata pada basis data produksi-tiruan.

---

## 2. Temuan Audit dan Status Perbaikan

### TEMUAN A (KRITIS) — Double-count pada Laporan Arus Kas

**Kondisi**: Laporan Arus Kas menghitung satu pergerakan kas **dua kali** ketika satu jurnal memuat **lebih dari satu baris kas** dan/atau beberapa baris lawan (mis. angsuran murabahah: pokok piutang + margin dalam satu jurnal).

**Akar masalah**: `aliranKas()` lama memakai JOIN silang baris kas × baris lawan. Setiap pasangan `(baris kas, baris lawan)` menghasilkan satu atribusi, sehingga jurnal dengan N baris kas dan M baris lawan melahirkan N×M atribusi.

**Bukti (Jurnal #25 "Angsuran ke-1 Murabahah - Budi Santoso")**:

| Pos | Sebelum perbaikan | Sesudah perbaikan |
|---|---|---|
| Arus kas operasi | 5.145.000 | **1.897.500** |
| Arus kas pendanaan | (tidak konsisten) | **51.850.000** |
| Arus kas lainnya (sintetik) | −3.697.500 | **0** |
| Kenaikan kas (menurut arus kas) | ≠ saldo | **53.747.500 = saldo** |
| `selisihRekonsiliasi` | ≠ 0 | **0,00** |

**Perbaikan**: `aliranKas()` ditulis ulang — atribusi diambil dari **mutasi NETO akun lawan non-kas** (`SUM(lawan.kredit) − SUM(lawan.debet)`) pada jurnal yang memuat minimal satu baris kas. Karena setiap jurnal seimbang, jumlah neto seluruh akun lawan non-kas tepat sama dengan kenaikan kas; satu pergerakan kas tidak mungkin terhitung dua kali. Subkueri `$jurnalKas` mengidentifikasi jurnal Posted pada periode yang memuat baris akun kas arus kas.

**Status**: ✅ **SELESAI & TERVERIFIKASI**.

---

### TEMUAN B (SEDANG) — Kas Brankas Dikecualikan dari Definisi Kas Arus Kas

**Kondisi**: Akun **Kas Brankas (1-1003)** tidak diikutsertakan sebagai "kas dan setara kas" pada laporan arus kas, sehingga saldo awal/akhir kas dan mutasi kas tidak lengkap.

**Perbaikan**: Ditambahkan konstanta `Akun::AKUN_KAS_ARUS_KAS = [KAS_TELLER (1-1001), BANK_KOPERASI (1-1002), KAS_BRANKAS (1-1003)]` pada `app\Support\Coa.php`, dipakai konsisten di 6 titik pada service (`arusKas()`, `sisaAliranKas()`, `catatanRincian()`, `deteksiArusKasTakTerlacak()`, dll.).

**Status**: ✅ **SELESAI & TERVERIFIKASI**.

---

### TEMUAN D (SEDANG) — Ketidaklengkapan Pengungkapan CaLK

**Kondisi**: `kebijakanAkuntansi()['standar']` tidak menyebut **PSAK 103 (salam)** dan **PSAK 104 (istishna)**, padahal sistem mencatat akad tersebut (akun `1-2006` Piutang Istishna, `1-2007` Piutang Salam). Selain itu terdapat label **"PSAK 108"** yang menyesatkan (PSAK 108 resmi IAI = Asuransi Syariah, bukan pemisahan margin/dana kebajikan).

**Perbaikan**: `kebijakanAkuntansi()['standar']` kini menyebut PSAK 103 & 104; label "PSAK 108" dihapus; ditambahkan penjelasan pengakuan/amortisasi **margin murabahah ditangguhkan (2-4001)** sesuai PSAK 102, serta penyajian **dana kebajikan dan dana titipan sosial terpisah dari pendapatan** sesuai PSAK 101 jo. PSAK 109 dan Fatwa DSN-MUI No. 08/DSN-MUI/IV/2000.

**Status**: ✅ **SELESAI & TERVERIFIKASI**.

---

### TEMUAN E/F (KRITIS) — Positif Palsu Diagnostic "Arus Kas Tak Terlacak" (Rp 92.850.000)

**Kondisi**: Panel diagnostik melaporkan **selisih Rp 92.850.000** seolah-olah ada mutasi kas tak dapat dijelaskan, padahal **buku seimbang sempurna**. Ini adalah **positif palsu** yang dapat memicu kesimpulan audit salah.

**Akar masalah**: `deteksiArusKasTakTerlacak()` versi lama hanya memantau **daftar akun tetap** (`piutang + margin ditangguhkan + simpanan berjangka`). Seluruh pendanaan lain — Simpanan Sukarela/Wajib/Pokok (2-1001/2-1002/3-1001/3-1002), SHU belum dibagi (3-2001), modal penyertaan (3-9000), dana kebajikan (2-3001) — terlewat, sehingga buku yang seimbang dilaporkan "tidak terjelaskan" sebesar jumlah persis akun-akun tersebut.

**Bukti (periode 2026-01-01 s/d 2026-10-31)**:

| Metrik | Sebelum | Sesudah |
|---|---|---|
| `terdeteksi` | YA | **TIDAK** |
| `selisih` | 92.850.000 | **0,00** |
| `mutasiKasBersih` | — | 53.747.500 |
| `labaBersih` | — | −1.560.833 |
| `mutasiNonKas` | — | 55.308.333 |

**Perbaikan**: `deteksiArusKasTakTerlacak()` ditulis ulang memantau **seluruh kategori non-kas** (Aset, Kewajiban, Ekuitas non-kas) **ditambah** Pendapatan dan Beban, sesuai identitas buku seimbang. Konstanta usang `AKUN_NON_KAS_MEDIASI` dihapus.

**Status**: ✅ **SELESAI & TERVERIFIKASI**.

---

### TEMUAN C (RENDAH / INFORMATIF) — Dead Code dengan Akun Tidak Konsisten

**Kondisi**: Dua method tidak dipakai alur produksi dan memakai akun berbeda dari kanonik:
- `jurnalAngsuran()` (baris ~433-435) memakai `4-2001`.
- `jurnalBagiHasilDeposito()` memakai `5-1001` (Beban Operasional).

**Dampak**: **Nol terhadap laporan** karena method tidak dipanggil pada alur nyata. Bukan pelanggaran PSAK.

**Status**: ✅ **SELESAI (terverifikasi 5 Oktober 2026) — temuan sudah tidak berlaku.**
- `jurnalAngsuran()` **bukan** dead code: dipakai produksi oleh `TransactionLimitService` (L280),
  `DemoDataSeeder`, `SimulasiTransaksiHariIni`, dan test. Akunnya kini **ter-resolve dinamis**
  (`pendapatanByAkad()`, `getMarginDitangguhkanByAkad()`, `piutangByAkad()`) — bukan lagi `4-2001`
  hardcode.
- `jurnalBagiHasilDeposito()` memakai konstanta kanonik
  `self::BEBAN_BAGI_HASIL_DEPOSITO = App\Support\Coa::BEBAN_BAGI_HASIL_DEPOSITO` → **`5-3001`**
  (bukan lagi literal `5-1001`); ini benar secara PSAK 105/101 ¶71.
- Tidak ada lagi literal akun hardcode yang menyimpang dari registry `App\Support\Coa`.

---

### TEMUAN E (DOKUMEN) — Dokumen Lama Tidak Sinkron

**Kondisi**: `docs/PSAK108-PEMISAHAN-MARGIN-DANA-KEBAJIKAN.md` mengklaim akun Margin Ditangguhkan "tidak dibuat", padahal basis data dan kode produksi memakai akun **`2-4001`**.

**Dampak**: **Nol terhadap laporan**; hanya dokumentasi internal yang menyesatkan.

**Status**: ✅ **SELESAI (terverifikasi 5 Oktober 2026) — dokumen telah disinkronkan.**
- §8 dokumen PSAK 108 kini mencantumkan koreksi eksplisit: akun Margin Ditangguhkan awalnya
  dirancang `2-4001`, namun dikoreksi menjadi **`1-2101`** (`App\Support\Coa::MARGIN_DITANGGUHKAN`)
  karena `2-` = kewajiban, sedangkan margin ditangguhkan adalah **akun kontra aset**.
- Dokumen merujuk basis tunggal `App\Support\Coa` (bukan kode hardcode).

---

## 3. Klarifikasi (Bukan Temuan)

**Jurnal #20–#24 (Pencairan Pembiayaan) tanpa baris kas**: pencairan memakai metode `DisbursementMethod::TRANSFER_SAVINGS` — dana berpindah buku ke **Simpanan Sukarela (2-1001)**, bukan keluar sebagai kas. Ini **sah secara PSAK** (pindah buku antarpasiva), namun **disarankan diungkap** pada CaLK sebagai bagian kebijakan pengakuan penyaluran dana.

**Akun `2-1002` vs `3-1002`**: registry memiliki `2-1002` (Kewajiban, "Simpanan Wajib Anggota") di seeder **tetapi tidak dipakai transaksi apa pun (0 baris)**. Akun kanonik Simpanan Wajib = **`3-1002` (Ekuitas/Modal Anggota)**. Command `php artisan psak:validasi-simpanan-wajib` **LULUS, 0 pelanggaran**, mengonfirmasi `2-1002` tidak pernah digunakan.

---

## 4. Verifikasi Ulang (Poin 6)

| Pemeriksaan | Hasil |
|---|---|
| Neraca seimbang | `totalAset = totalPasiva = 107.489.167`, **selisih = 0** |
| Kepatuhan PSAK internal | `patuh = YA`, **temuan = 0** |
| Buku besar seimbang | debet = kredit = **120.747.500** |
| Laba bersih (periode uji) | **−1.560.833** (rugi) |
| Perubahan ekuitas | awal 0 + penambah 11.300.000 = **11.300.000** |
| Arus kas | kenaikan = 53.747.500 = mutasi saldo kas; `selisihRekonsiliasi = 0` |
| Diagnostic arus kas tak terlacak | `terdeteksi = TIDAK`, `selisih = 0,00` |
| Command `psak:validasi-simpanan-wajib` | **LULUS, 0 pelanggaran** |
| **Regresi penuh (`php artisan test`)** | **1170 passed (5354 assertions), 0 failure** |
| Regresi filter laporan | 113 passed (472 assertions), 0 failure |
| `LaporanPsak101Test` | 30 passed (97 assertions), 0 failure |

Dua **test regresi pengunci** ditambahkan ke `tests/Feature/LaporanPsak101Test.php`:
1. `test_jurnal_dua_baris_kas_tidak_menggandakan_atribusi_arus_kas` — mengunci **TEMUAN A**.
2. `test_diagnostik_tetap_nol_untuk_pendanaan_yang_tidak_ada_di_daftar_akun_tetap` — mengunci **TEMUAN E/F**.

---

## 5. Rekomendasi Tersisa (Terukur, Prioritas Rendah)

| # | Rekomendasi | Dampak Laporan | Prioritas |
|---|---|---|---|
| 1 | ✅ **SELESAI** — Dead code `jurnalAngsuran()` & `jurnalBagiHasilDeposito()`: keduanya kini memakai akun kanonik dari `App\Support\Coa` (dinamis / `5-3001`); tidak ada literal menyimpang. | Nol | Rendah |
| 2 | ✅ **SELESAI** — `docs/PSAK108-PEMISAHAN-MARGIN-DANA-KEBAJIKAN.md` disinkronkan (margin ditangguhkan = `1-2101`). | Nol | Rendah |
| 3 | Tambahkan pengungkapan CaLK atas pencairan metode `TRANSFER_SAVINGS` | Meningkatkan transparansi | Rendah |
| 4 | Turunkan akun `2-1002` yang tidak terpakai, atau beri keterangan sebagai akun cadangan | Nol | Rendah |

---

## 6. Kesimpulan

Seluruh komponen laporan keuangan (Neraca, Laba Rugi, Perubahan Ekuitas, Arus Kas, CaLK) telah diverifikasi terhadap PSAK 101/102/103/104/105/106/107/109/71 dan Fatwa DSN-MUI terkait. **Seluruh temuan material (A, B, D, E/F) telah diperbaiki dan diverifikasi ulang dengan data nyata.** Temuan non-material **C dan E-dokumen** kini juga **SELESAI** (akun kanonik terpusat di `App\Support\Coa`; dokumen PSAK 108 disinkronkan). **Laporan keuangan dinyatakan bebas dari kesalahan material dan layak diterbitkan.**
