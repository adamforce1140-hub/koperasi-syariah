# Spesifikasi Teknis Modul — Koperasi Syariah

**Sistem:** Laravel 12.69.1 / PHP 8.3 / MySQL 8.4.3
**Status dokumen:** Langkah 1 rencana pengembangan (spesifikasi). Dokumen ini hanya berisi spesifikasi dan status verifikasi; tidak ada perubahan kode.
**Dasar:** `docs/AUDIT-MODUL-SISTEM.md` (29 Sep 2026) yang sebagian sudah usang. Setiap status di bawah diverifikasi ulang terhadap kode.

---

## 1. Prinsip Syariah yang Diterapkan

| Prinsip | Ketentuan sistem |
|---|---|
| Bebas riba | Tidak ada bunga atas simpanan maupun pembiayaan. Qardh tanpa margin. |
| Bagi hasil | Simpanan berjangka (mudharabah) dibagi dari hasil usaha riil, bukan estimasi bunga. |
| Transparansi | Nisbah, margin, dan biaya tercatat dan dapat ditelusuri ke jurnal. |
| Berbagi risiko | Kerugian ditanggung bersama sesuai akad; ta'zir hanya untuk keterlambatan yang disengaja dan tidak masuk pendapatan koperasi. |
| Akad dan pemisahan akun | Piutang dan pendapatan dipisah per akad (PSAK 102–107). |
| Pengawasan | DPS mensertifikasi produk; audit akad per transaksi. |

---

## 2. Spesifikasi per Modul

### 2.1 Manajemen Anggota (M3)
- **Fungsi:** pendaftaran, verifikasi, CIF, status keanggotaan (aktif/nonaktif/keluar), pengunduran diri.
- **Entitas utama:** `Anggota`, `MemberTermination`, `ExitApprovalLog`, `ExitDocumentService`, `ExitKeanggotaanService`, `CddService`.
- **Aturan:** anggota non-aktif diblokir oleh middleware `PastikanAnggotaAktif`. Data PII (NIK) disimpan sebagai hash (`nik_hash`), perubahan dicatat di `AuditPerubahanPii`.
- **Pencarian:** NIK, No. CIF, atau No. Anggota (lihat `MemberTerminationController::cariAnggota`).

### 2.2 Pembiayaan Syariah (M7)
- **Fungsi:** pengajuan, survei AO, approval berjenjang, akad, pencairan, angsuran, restrukturisasi.
- **Entitas utama:** `PengajuanPembiayaan`, `Pembiayaan`, `MasterProdukPembiayaan`, `DisbursementService`, `RestrukturisasiService`.
- **Akad yang tercatat di kode:** Murabahah, Musyarakah, Mudharabah, Ijarah, Qardh, IMBT, Istishna, Salam (`Pembiayaan.php`).
- **Piutang per akad:** Murabahah `1-2001`, Istishna `1-2006`, Salam `1-2007` (`Coa.php`, `PIUTANG_BY_AKAD`).
- **Status state machine:** `config/alur_pembiayaan.php` + `App\Support\AlurStatusPembiayaan`.
- **Matriks approval:** `config/alur_pembiayaan.php` + `App\Support\MatriksApproval`.
- **Nisbah produk:** `MasterProdukPembiayaan` mewajibkan nisbah untuk akad bagi hasil (Mudharabah, Musyarakah) saat validasi produk.
- **Nisbah kontrak (B6, selesai):** `margin_nisbah` dibatasi 0–100% pada tiga jalur tulis (pengajuan AO, API `store`, API `update`). Akad bagi hasil ditolak bila nisbah ≤ 0%. Kontrak bagi hasil mengisi `nisbah_pengelola` = `margin_nisbah` dan `nisbah_pemilik` = `100 − margin_nisbah` sehingga porsi selalu utuh 100%; API juga menolak bila keduanya dikirim tetapi tidak berjumlah 100% (`PembiayaanController::pastikanNisbahUtuh()`). Dikunci `NisbahPembiayaanPengajuanTest` (7 test).
- **Jatuh tempo kontrak:** `pembiayaan.tanggal_jatuh_tempo` bersifat NOT NULL; jalur pengajuan kini mengisinya (`tanggal akad + tenor`, `addMonthsNoOverflow`) sehingga tidak lagi gagal `SQLSTATE 1364`.

### 2.3 Simpanan Pokok, Wajib, dan Sukarela (M4, M6)
- **Simpanan pokok:** setoran awal saat pembukaan keanggotaan (`SetoranAwalService`).
- **Simpanan wajib:** tagihan bulanan berbasis anniversary, auto-debit dari saldo sukarela, status OVERDUE (`MandatorySavingsService`, `MandatorySavingsBill`, `ProcessMandatorySavings`).
- **Simpanan sukarela:** setor dan tarik melalui teller, dengan batas transaksi per peran (`TransactionLimitService`, `LimitTransaksiRole`).
- **Jenis simpanan:** dikelola `JenisSimpanan` dan `Coa::SIMPANAN_*`.
- **Aturan:** operasi saldo memakai `lockForUpdate`. Setiap mutasi menghasilkan jurnal melalui `AutoJournalingService`.

### 2.4 Deposito Mudharabah dan Bagi Hasil (M5)
- **Fungsi:** pembukaan deposito, bilyet, bagi hasil, jatuh tempo, perpanjangan ARO (NON_ARO, ARO_POKOK, ARO_PLUS).
- **Entitas utama:** `Deposito`, `DepositoProduct`, `DepositoProfitSharing`, `DepositoService`, `ProsesJatuhTempoDepositoJob`, `DistribusiBagiHasilJob`.
- **Aturan bagi hasil:** nisbah wajib berjumlah 100% (`DepositoProduct::nisbahValid()`). Realisasi bagi hasil diambil dari laba riil, bukan estimasi bunga.
- **Perlu verifikasi (langkah 3):**
  - Estimasi bagi hasil pada teller masih memakai pola `pokok × rate_pa × tenor/12` (`depositoRatePa`). Ini berpola bunga dan harus diganti atau diberi label jelas sebagai proyeksi, bukan janji.
  - Denda penarikan dini: pastikan tidak masuk akun pendapatan admin (`4-2001`). Menurut Fatwa DSN-MUI No. 17/2000, ta'zir tidak boleh menjadi pendapatan koperasi; dana tersebut harus masuk Dana Kebajikan.
  - Jalur bagi hasil aktif (teller) perlu dicek apakah sudah membayar bagi hasil riil atau hanya dari test.

### 2.5 Pembagian Hasil Usaha dan SHU (M11, R4 §7C)
- **Fungsi:** perhitungan dan pengesahan SHU per tahun buku.
- **Entitas utama:** `ShuCalculatorService`, `ShuPeriod`, `ShuDistribution`, `IkhtisarShuService`, `TutupBukuShuJob`, `TahunBukuService`.
- **Aturan:** `Setting::alokasiShu()` adalah satu-satunya pembaca persentase kebijakan (4 pos, total 100%). Angka periode bersifat historis dan tidak ditimpa saat kebijakan berubah.
- **Status:** sumber tunggal SHU sudah diterapkan (R4-5), dikunci `PenyatuanSumberShuTest`.

### 2.6 Zakat, Infak, Sedekah, dan Dana Kebajikan (M12)
- **Fungsi:** penghimpunan dan penyaluran dana ZIS, dana titipan di luar pendapatan koperasi.
- **Entitas utama:** `ZakatService`, `ZakatTransaksi`, `DanaKebajikanService`, `DanaKebajikan`.
- **Aturan:** dana ZIS dan kebajikan tidak masuk pendapatan koperasi (PSAK 109 dan 101). Akun diatur lewat `KebijakanKoperasi`.

### 2.7 Audit Internal dan Kepatuhan Syariah (M15, M19)
- **Audit umum:** `AuditLog::record()` untuk jejak perubahan.
- **Audit laporan:** `LaporanKeuanganLog` dengan hash-chain append-only.
- **Audit akad DPS:** `DpsController::auditAkad` dan `AuditAkadRepository`.
- **Sertifikasi produk DPS:** `DpsController::reviewProdukApprove` (sudah menyimpan, idempoten).
- **Deteksi jurnal orphan:** `DeteksiJurnalOrphan`, `JurnalReconciliationService`.
- **Pemisahan tugas (maker ≠ checker):** `PemisahanTugas` dipakai di banyak modul (jurnal, approval limit transaksi, pengeluaran beban, ta'zir, restrukturisasi, AML, zakat, pembiayaan). Pada pembiayaan, `PengajuanPembiayaanIntegController::langgarPemisahanTugas()` mengecek `ao_id` dan `created_by` dari `pengajuan_pembiayaan` terhadap pemutus.
- **Temuan koreksi:** klaim sebelumnya "pengurus yang menyetujui tidak boleh mencairkan" TIDAK SESUAI KODE. `approvePengurus()` menyetujui sekaligus mencairkan dalam satu aksi. Ini kesenjangan pemisahan tugas dan masuk langkah 3.
- **Perlu verifikasi (langkah 3):** apakah pengurus masih bisa mengedit dan menghapus laporan tanpa maker-checker (B11 audit lama).

### 2.8 Laporan Keuangan Syariah (M10)
- **Fungsi:** lima komponen PSAK 101 (neraca, laba rugi, perubahan ekuitas, arus kas, CaLK), serta laporan dana kebajikan dan zakat.
- **Entitas utama:** `LaporanKeuanganSyariahService`, `LaporanKeuanganController`, `LaporanKeuanganAccess` (gerbang akses).
- **Aturan:** seluruh angka berasal dari jurnal, bukan dari input manual. Akses dibatasi peran.

### 2.9 Akuntansi Inti (M9)
- **Fungsi:** COA `[Kategori]-[Nomor]`, auto-journaling, buku besar, neraca saldo, void dan reversal.
- **Entitas utama:** `AutoJournalingService` (facade) dengan journaler per domain di `app/Services/Jurnal/`, `PostingJurnal`, `ReversalService`, `CoaResolver`.
- **Aturan:** `PostingJurnal::post()` adalah satu-satunya gerbang validasi keseimbangan debet dan kredit. Transaksi bisnis dan jurnal berada dalam satu `DB::transaction`.

### 2.10 Akses dan Peran (M1)
- **Peran:** `super_admin`, `pengurus`, `manajer`, `ao`, `teller`, `dps`, `admin`, `anggota`, `nasabah`.
- **Konfigurasi:** `config/role_hierarki.php`, `CheckRole`, `izin:` middleware.

### 2.11 Pemeliharaan Sistem dan Cadangan
- **Fungsi:** `php artisan db:cadangkan` membuat cadangan basis data terenkripsi; `php artisan db:buka-cadangan <berkas>` memulihkan berkas agar dapat diimpor.
- **Alur:** `mysqldump` (stdout) → `deflate` gzip level 6 → **XChaCha20-Poly1305 secretstream** (libsodium) → berkas `.sql.gz.enc`. Plaintext tidak pernah ditampung utuh di memori maupun ditulis ke disk.
- **Framing:** setiap potongan 1 MiB ditulis sebagai `[4 byte panjang plaintext big-endian][ciphertext + 17 byte tag]`, ditutup `[0][TAG_FINAL]`. Panjang eksplisit wajib karena `fread()` dapat mengembalikan short read.
- **Kunci:** `BACKUP_ENCRYPTION_KEY` (base64 32 byte) atau turunan HKDF-SHA256 dari `APP_KEY` dengan info `koperasi-cadangan`. Kunci harus disimpan terpisah; tanpa kunci cadangan tidak dapat dipulihkan.
- **Retensi:** `--simpan-hari` (default dari `Setting::backupRetensiHari()`, rentang 1–365) membersihkan `.sql.gz` lama maupun `.sql.gz.enc`.
- **Pemantauan:** `denyut:pemeriksaan` memeriksa kedua ekstensi; `BACKUP_ENKRIPSI=false` hanya untuk pemulihan darurat dan tidak disarankan.
- **Regression test:** `CadanganEnkripsiTest` (6 test: derivasi kunci, kunci salah, bolak-balik multi potongan, kunci berbeda, berkas terpotong, enkripsi dimatikan) dan `PemeliharaanSistemTest`.

---

## 3. Status Temuan Audit Lama (29 Sep 2026) Setelah Verifikasi

| Kode lama | Temuan | Status saat ini |
|---|---|---|
| A0 | Autodebet angsuran tidak terjadwal | **Sudah diperbaiki.** Terdaftar di `routes/console.php`. |
| A1 | Sertifikasi DPS tidak disimpan | **Sudah diperbaiki (terverifikasi).** `reviewProdukApprove` menyimpan `status_sertifikasi_dps`, `disahkan_oleh`, dan audit log dalam `DB::transaction`. Belum ada regression test khusus (langkah 4). |
| Pemisahan tugas pencairan | Pengurus menyetujui sekaligus mencairkan | **Sudah diperbaiki (terverifikasi).** `approvePengurus()` hanya menetapkan `STATUS_DISETUJUI`; pencairan terpisah di `cairkan()` dan ditolak bila `disetujui_oleh` = pencair (`PemisahanTugas::dilanggar()`). |
| A2 | Rute dana kebajikan salah | **Tertutup.** Nama rute sidebar Dana Kebajikan memakai `*.index` yang terdaftar (`AUDIT-MODUL-SISTEM.md`). |
| A3 | Pencairan tanpa `DB::transaction` | Dead code, sudah ditutup `TellerPencairanController` dan `PencairanController` |
| B5 | PIUTANG Istishna/Salam sama dengan Murabahah | **Sudah diperbaiki.** Akun dipisah `1-2006` dan `1-2007`. |
| B7 | Enum akad produk hanya 5 | **Kemungkinan sudah diperbaiki.** Akad produk divalidasi terhadap daftar di `Pembiayaan` (memuat IMBT, Istishna, Salam). Konstanta akad ada di `Pembiayaan.php` baris 183–207; `PengajuanPembiayaan.php` memetakan Istishna, Salam, IMBT ke CoA. Belum dibuktikan dengan test. |
| B2, B3, B4 | Denda deposito, bagi hasil, estimasi berpola bunga | **Sudah diperbaiki (terverifikasi).** Ta'zir penarikan dini masuk `2-3001` Dana Kebajikan (bukan pendapatan koperasi) dan nominalnya dibaca ulang saat void (`DepositoService`); bagi hasil memakai laba riil; estimasi teller memakai prorata hari efektif per bulan kalender, bukan `rate_pa × tenor/12`. |
| B6 | Nisbah pembiayaan tidak divalidasi | **Sudah diperbaiki (terverifikasi).** Lihat §2.2 — batas 0–100%, tolak nisbah 0% pada akad bagi hasil, isi `nisbah_pengelola`/`nisbah_pemilik` berjumlah 100%, API menolak porsi tidak utuh. Dikunci `NisbahPembiayaanPengajuanTest`. |

---

## 4. Ruang Lingkup Langkah Berikutnya

- **Langkah 2:** arsitektur, keamanan, dan kepatuhan regulasi. Rujuk `docs/REKOMENDASI-ARSITEKTUR-FLEKSIBEL-TERUKUR.md` (R1–R4 sudah selesai) dan `docs/AUDIT-ROLE-KEPATUHAN-SYARIAH-PSAK.md`.
- **Langkah 3:** verifikasi item "Perlu verifikasi" di atas, lalu perbaiki yang benar-benar bug, satu per satu dengan regression test.
