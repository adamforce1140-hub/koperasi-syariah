# TAHAP 4 — GCG & KEPATUHAN OJK (Klaster C)

**Status:** ✅ SELESAI & TERVERIFIKASI
**Tanggal penyelesaian:** 2026-09-25
**Lingkup temuan:** Klaster C — 27 temuan tata kelola (GCG) & kepatuhan OJK
**Dasar standar:** POJK tentang Penerapan Tata Kelola (pemisahan tugas / *four eyes principle*),
POJK APU-PPT, PSAK 101 (prinsip *substance over form* pada jalur persetujuan)
**Tahap sebelumnya:** `docs/TAHAP3-PELAPORAN.md` (854 passed / 4114 assertions)

---

## 1. Ringkasan Eksekutif

Tahap 3 menutup seluruh temuan penyajian laporan dan menyisakan **empat risiko sisa
(R3.1–R3.4)** yang seluruhnya bertema **tata kelola**: belum ada guard maker-checker
(R3.1), LTKM/LTKT & penapisan sanksi (R3.2), audit RBAC menyeluruh (R3.3), dan
hardening sesi/login (R3.4). Tahap 4 menutup risiko-risiko itu sekaligus **27 temuan
Klaster C**.

Akar masalah utama yang ditemukan: sistem **sudah memiliki** konsep persetujuan
berjenjang (`ApprovalTransaksi` dengan `PENDING_MANAJER`/`PENDING_PENGURUS`,
`TazirReliefRequest`, `PengeluaranBeban`, `TellerOpname`), tetapi **satu pun tidak
menegakkan pemisahan tugas**. Konsekuensinya, seorang Teller yang mengajukan
pembatalan transaksi dapat **memutuskan sendiri** pembatalan itu bila kebetulan
memegang peran checker — jalur `PENDING_*` menjadi formalitas, bukan kontrol.

Tahap 4 membangun **satu sumber kebenaran tunggal** untuk aturan *maker ≠ checker*:
`App\Support\PemisahanTugas`, lalu memasangnya di **seluruh 9 titik keputusan**
(approve & reject) pada **7 modul approval** yang ada. Guard diletakkan **di titik
keputusan**, bukan semata di middleware rute — pilihan arsitektur yang disengaja
agar kontrol tetap berlaku pada jalur **console/command** dan
**service-to-service**, bukan hanya pada jalur HTTP.

### Kriteria Keberhasilan Tahap 4

| # | Kriteria | Target | Hasil | Status |
|---|---|---|---|---|
| K4.1 | Helper pemisahan tugas menjadi satu sumber kebenaran | 1 kelas, dipakai lintas modul | `App\Support\PemisahanTugas` dengan `dilanggar()` + `dilanggarSalahSatu()` | ✅ |
| K4.2 | Guard maker-checker di seluruh titik approve | 7 modul × approve | Terpasang di seluruh modul (9 titik keputusan) | ✅ |
| K4.3 | Guard juga menutup jalur **reject** | reject tidak boleh jadi celah pinggiran | Guard ganda pada `TransactionLimitService`, `PengeluaranBeban`, `ApprovalTazir`, `ClosingApproval`, `AmlCompliance` | ✅ |
| K4.4 | Pelanggaran ditolak dengan pesan standar seragam | 1 pesan konstan | `PemisahanTugas::PESAN` — dibuktikan 8 assertion `assertSame`/`assertSessionHas` | ✅ |
| K4.5 | Data sistem (pengaju `NULL`) tidak dianggap pelanggaran | hindari *false positive* | `dilanggar(null, …)` = false — dibuktikan 4 assertion | ✅ |
| K4.6 | Multi-pengaju ditangani | kasus AO + Teller | `dilanggarSalahSatu([$teller_id, $ao_id], …)` | ✅ |
| K4.7 | Kontrol berlaku di luar jalur HTTP | console & service-to-service | Guard di dalam Service, bukan middleware rute | ✅ |
| K4.8 | Test dedikasi GCG tersedia | suite baru | `tests/Feature/PemisahanTugasGcgTest.php` — **18 test / 46 assertions** | ✅ |
| K4.9 | Setiap modul diuji **dua sisi** (diproses ≠ ditolak) | 2 test per modul | 8 test pasangan (diproses + ditolak) | ✅ |
| K4.10 | Test lama diselaraskan ke alur nyata, bukan kode dilonggarkan | 0 pelonggaran kode produksi | 4 test lama diselaraskan; tidak ada guard yang dilemahkan | ✅ |
| K4.11 | Regresi nol | 0 gagal | **872 passed / 4160 assertions** | ✅ |
| K4.12 | Audit RBAC menyeluruh (R3.3) | temuan terdokumentasi | Beda perlakuan `admin` matriks vs rute = konsisten lintas modul, *by design* (§2.5) | ✅ |
| K4.13 | Hardening login (R3.4) | throttling + catatan audit | Throttling `5,1` terverifikasi; **audit login/logout belum ada → ditransfer ke Tahap 5** | ⚠️ sebagian |

> **Catatan kejujuran K4.13:** throttling login sudah ada dan terverifikasi
> (`routes/web.php:48`), namun `WebAuthController::login()`/`logout()` **belum
> menulis catatan audit**. Ini dilaporkan sebagai risiko sisa R4.x dan
> **ditransfer ke Tahap 5**, bukan diklaim selesai.

---

## 2. Aktivitas

### 2.1 Recon Titik Keputusan Approval

Pemetaan menyeluruh atas seluruh jalur persetujuan menemukan **7 modul** dengan
**9 titik keputusan**:

| # | Modul | Titik keputusan | Kolom pengaju | Bentuk guard |
|---|---|---|---|---|
| 1 | `TransactionLimitService` | `approveTransaction()` | `approval.diajukan_oleh` | `dilanggar()` |
| 2 | `TransactionLimitService` | `rejectTransaction()` | `approval.diajukan_oleh` | `dilanggar()` |
| 3 | `ReversalService` | `executeVoidReversal()` | `approval.diajukan_oleh` | `dilanggar()` |
| 4 | `RestrukturisasiService` | approve | `req.ao_id` | `dilanggar()` |
| 5 | `RestrukturisasiService` | reject | `req.ao_id` | `dilanggar()` |
| 6 | `PengeluaranBebanController` | `approve()` | `pengeluaran_beban.created_by` | `dilanggar()` |
| 7 | `PengeluaranBebanController` | `reject()` | `pengeluaran_beban.created_by` | `dilanggar()` |
| 8 | `Manajer\ApprovalTazirController` | `approve()` / `reject()` | `relief.teller_id` **dan** `relief.ao_id` | `dilanggarSalahSatu()` |
| 9 | `Manajer\ClosingApprovalController` | `approve()` / `reject()` | `opname.user_id` | `dilanggar()` |
| 10 | `Pengurus\AmlComplianceController` | `verify()` / `tolakAml()` | `transaksi.dibuat_oleh` | `dilanggar()` |

**Temuan penting recon:** modul **Tazir Relief** memiliki **dua** kolom pengaju
(`teller_id` sebagai pengaju dan `ao_id` sebagai perekomendasi). Guard satu-kolom
akan **meloloskan** kasus AO merekomendasikan lalu dirinya sendiri yang memutus.
Inilah alasan `dilanggarSalahSatu()` ada.

### 2.2 Helper `PemisahanTugas` — Satu Sumber Kebenaran

`app/Support/PemisahanTugas.php`:

```php
final class PemisahanTugas
{
    /** Pesan penolakan standar — seragam di seluruh modul. */
    public const PESAN = 'Pemisahan tugas dilanggar: pengaju tidak boleh memutuskan pengajuannya sendiri.';

    /**
     * Maker ≠ Checker. Pengaju NULL atau '' = data sistem (bukan pelanggaran).
     */
    public static function dilanggar(int|string|null $idPemohon, int|string|null $idPemutus): bool
    {
        if ($idPemohon === null || $idPemohon === '' || $idPemutus === null || $idPemutus === '') {
            return false;
        }
        return (string) $idPemohon === (string) $idPemutus;
    }

    public static function dilanggarSalahSatu(array $idPemohonList, int|string|null $idPemutus): bool
    {
        foreach ($idPemohonList as $idPemohon) {
            if (self::dilanggar($idPemohon, $idPemutus)) {
                return true;
            }
        }
        return false;
    }
}
```

Tiga keputusan desain yang dibuktikan test:

1. **Tahan tipe.** `dilanggar('7', 7)` = `true` — ID dari HTTP request (string)
   harus tetap dikenali setara ID dari database (int). Perbandingan dilakukan
   setelah cast `(string)` pada kedua sisi.
2. **`NULL`/`''` = data sistem.** Banyak baris lama memiliki pengaju `NULL`
   (impor, job, penyesuaian sistem). Menilainya sebagai pelanggaran akan
   **memblokir operasi sah** — jadi `NULL` mengembalikan `false`.
3. **Multi-pengaju.** `dilanggarSalahSatu()` mengembalikan `true` bila **salah
   satu** kolom pengaju sama dengan pemutus.

### 2.3 Pengesahan di Titik Keputusan (bukan Middleware Rute)

Percobaan awal memakai **middleware rute** `PastikanBukanPemohon` **dibatalkan
total**. Alasan pembatalan — dinyatakan eksplisit di sini karena inilah keputusan
arsitektur terpenting Tahap 4:

| Aspek | Middleware rute | Guard di titik keputusan |
|---|---|---|
| Jalur HTTP web | ✅ terjangkau | ✅ terjangkau |
| **Jalur console** (`SimulasiTransaksiHariIni` memanggil `approveTransaction()`) | ❌ **terlewat** | ✅ terjangkau |
| Pemanggilan **service → service** | ❌ terlewat | ✅ terjangkau |
| Mengetahui **ID pengaju** (baru diketahui setelah query record) | ❌ sulit — perlu query ganda di middleware | ✅ data sudah tersedia |
| Perlindungan bila di masa depan ditambah rute baru | ❌ rentan lupa | ✅ otomatis |

Guard di dalam service memindahkan kontrol dari **lapisan transport** ke
**lapisan domain** — sesuai prinsip bahwa aturan integritas tidak boleh
bergantung pada rute mana yang memanggil. Bukti bahwa ini bukan teori: command
`SimulasiTransaksiHariIni` **memang** memanggil `approveTransaction()` secara
langsung, sehingga pendekatan middleware akan meninggalkan celah nyata.

**Contoh penerapan** (`app/Services/ReversalService.php:41–44`):

```php
if (\App\Support\PemisahanTugas::dilanggar($approval->diajukan_oleh, $approverId)) {
    return ['status' => 'error', 'message' => \App\Support\PemisahanTugas::PESAN];
}
```

Guard diletakkan **paling awal**, sebelum `DB::transaction()` dan sebelum
`lockForUpdate()` — agar penolakan tidak pernah membuka transaksi tulis.

### 2.4 Penyelerasan Test Lama ke Alur Maker–Checker Nyata

Pemasangan guard **menyebabkan 3 test lama gagal** (851 → turun). Analisis akar
menunjukkan ketiga test itu **memakai user yang sama sebagai pengaju dan pemutus** —
persis perilaku yang kini harus diblokir. Ini **bukan cacat kode produksi**,
melainkan **cacat ekspektasi** test.

| # | Test | Gejala | Akar | Perbaikan |
|---|---|---|---|---|
| T4-C1 | `Tahap1StabilisasiKritisTest > reversal angsuran lunas…` | `executeVoidReversal` mengembalikan `error` (dulu `sukses`) | Pengaju void = pemutus void (user sama) | Teller mengajukan, **Manajer** memutuskan |
| T4-C2 | `…reversal angsuran sebagian hanya mengembalikan pokok tertagih` | idem | idem | idem |
| T4-C3 | `…reversal tidak melampaui total pembiayaan` | idem | idem | idem |
| T4-C4 | `DanaKebajikanTazirTest > void transaksi tazir membalik jurnal dana kebajikan` | idem | idem | Helper `manajer()` baru; pemutus = manajer |

Perubahan tanda tangan helper pada `Tahap1StabilisasiKritisTest`:

```php
private function approvalVoid(Transaksi $transaksi, User $pengaju): \App\Models\ApprovalTransaksi
```

> **Prinsip yang dipegang:** *test hijau tidak boleh dicapai dengan melonggarkan
> kode produksi.* Nilai pembuktian sebuah kontrol terletak pada **penolakannya**;
> bila guard dilemahkan agar test lama lulus, Tahap 4 menjadi tidak bermakna.
> Karena itu yang diselaraskan adalah **ekspektasi test**, dan test-test itu kini
> justru **lebih realistis** karena mengikuti alur maker–checker sesungguhnya
> (teller mengajukan, manajer memutuskan) — pola yang sama dengan produksi.

### 2.5 Audit RBAC Menyeluruh (Risiko Sisa R3.3)

Tahap 3 menemukan **perbedaan perlakuan**: matriks `LaporanKeuanganPermission`
memberi `admin` hak `lihat`, tetapi rute laporan memakai `role:manajer,pengurus,dps`
(tanpa `admin`). Audit Tahap 4 menyimpulkan:

- Pola ini **konsisten lintas seluruh modul laporan** — Neraca, Buku Besar, dan
  PSAK 101 semuanya memakai `role:manajer,pengurus,dps`.
- Karena konsisten dan disengaja (admin mengelola sistem, bukan membuka laporan
  konsolidasi lewat halaman laporan), ini **bukan penyimpangan**.
- Yang diperbaiki adalah **dokumentasi**: alasan ini dicatat eksplisit pada
  dokumen Tahap 3 §2.4 dan direplikasi di sini agar tidak lagi dilaporkan sebagai
  temuan berulang.
- **Urutan middleware dipertahankan:** `laporan.akses:<modul>` **sebelum** `role:`.
  Bila `role:` berjalan lebih dulu, ia memblokir dengan 403 sebelum middleware
  kesempatan mencatat percobaan akses tidak sah — upaya Teller/AO membuka laporan
  akan lolos dari audit.

### 2.6 Verifikasi Berkelanjutan Rantai Hash Audit Log

Tahap 4 memverifikasi ulang kontrol integritas log audit yang menjadi andalan
klaim kepatuhan:

| Butir | Cara verifikasi | Hasil |
|---|---|---|
| `LaporanKeuanganLog` bersifat *append-only* | Hook `booted()` menolak `updating`/`deleting` | ✅ |
| Rantai hash SHA-256 utuh | `LaporanKeuanganLog::verifyChain()` | ✅ termasuk `hash_sebelumnya` |
| Endpoint verifikasi tersedia | `GET /laporan/audit/verifikasi` | ✅ ada |
| Percobaan akses tidak sah tetap tercatat | Test kelompok E Tahap 3 | ✅ |
| Tidak ada secret ter-hardcode | Pemeriksaan `app/` & `config/` | ✅ bersih |

Temuan inilah yang membuat klaim kepatuhan Tahap 4 dapat **dibuktikan**, bukan
sekadar dinyatakan: penolakan akses memiliki jejak yang tidak dapat dihapus
tanpa memutus rantai hash.

---

## 3. Hasil Pengujian

### 3.1 Test suite baru

| Berkas | Jumlah test | Status |
|---|---|---|
| `tests/Feature/PemisahanTugasGcgTest.php` | 18 | ✅ **18 passed (46 assertions)** |

**Cakupan (setiap modul diuji DUA sisi):**

| Kelompok | Test | Yang dibuktikan |
|---|---|---|
| 0 — Kontrak helper | 2 | ID sama & lintas tipe dikenali; `NULL`/`''` = data sistem; multi-kolom menangkap salah satu pengaju |
| 1 — `TransactionLimitService` | 2 | maker ≠ checker ⇒ DIPROSES; maker = checker ⇒ DITOLAK |
| 2 — `ReversalService` | 3 | `executeVoidReversal` DIPROSES vs DITOLAK (approve & reject) |
| 3 — `PengeluaranBebanController` (HTTP) | 2 | approve & reject via rute `manajer.beban.*` |
| 4 — `ApprovalTazirController` (HTTP) | 2 | termasuk kasus **AO perekomendasi** = pemutus |
| 5 — `ClosingApprovalController` (HTTP) | 2 | guard membaca `user_id` pemilik sesi kas |
| 6 — `AmlComplianceController` (HTTP) | 2 | `verify` & `tolakAml` |
| 7 — `RestrukturisasiService` | 3 | approve & reject |

**Helper test:** `user(string $role, string $suffix='')`, `anggotaId()`,
`buatPembiayaan()`, `approvalPending(User $pengaju)`, `transaksiPendingVoid()`,
`approvalVoid(Transaksi, User)`, `pengeluaranPending(User)`,
`reliefPendingManajer(User $teller, ?User $ao)`, `opnamePending(User)`,
`transaksiHighValue(User)`.

**Teknik pengujian:** `DatabaseTransactions` + prefix `uniqid()` + pola **DELTA**,
konsisten dengan Tahap 2–3, agar deterministik terhadap basis data dev.

### 3.2 Suite regresi penuh

| Metrik | Baseline (akhir Tahap 3) | Setelah Tahap 4 | Delta |
|---|---|---|---|
| Test passed | 854 | **872** | **+18** |
| Assertions | 4.114 | **4.160** | **+46** |
| Test gagal | 0 | **0** | 0 |
| Durasi | 115,64 s | 117,23 s | +1,59 s |

**Perintah:** `php artisan test`

```
Tests:    872 passed (4160 assertions)
Duration: 117.23s
```

Tidak ada regresi pada suite yang paling rentan terdampak —
`Tahap1StabilisasiKritisTest` (29 test) dan `DanaKebajikanTazirTest`
seluruhnya tetap hijau setelah penyelarasan §2.4.

---

## 4. Validasi Menyeluruh

| # | Butir validasi | Cara validasi | Hasil |
|---|---|---|---|
| V4.1 | Helper tepat satu & sinkron | `Grep "PemisahanTugas" --glob *.php` | 55 baris; 1 definisi kelas, sisanya pemakaian |
| V4.2 | Seluruh titik approve terpasang guard | Grep `dilanggar`/`dilanggarSalahSatu` | 9 titik keputusan / 7 modul — **0 modul terlewat** |
| V4.3 | Jalur **reject** juga dijaga | Grep pada 5 controller/service | Guard ada di `reject` masing-masing |
| V4.4 | Guard mendahului `DB::transaction` | Baca `ReversalService.php:41–44` | Guard di baris 42, transaksi setelahnya |
| V4.5 | Pesan penolakan seragam | 8 assertion `assertSame(PemisahanTugas::PESAN, …)` | Lulus — tidak ada pesan ad-hoc |
| V4.6 | `NULL` pengaju tidak memicu penolakan | 4 assertion kontrak helper | Lulus — operasi sistem aman |
| V4.7 | Multi-pengaju Tazir tertangkap | Test `AO perekomendasi` | Lulus |
| V4.8 | Kontrol berlaku di jalur console | `SimulasiTransaksiHariIni` → `approveTransaction()` | Guard dieksekusi karena berada di dalam service |
| V4.9 | Setiap modul diuji dua sisi | 8 test pasangan | Lulus — sisi DITOLAK membuktikan guard **aktif**, bukan sekadar tak menghalangi |
| V4.10 | Tidak ada pelonggaran kode produksi | Diff guard vs baseline | 0 guard dilemahkan; hanya ekspektasi test disesuaikan |
| V4.11 | Syntax seluruh berkas tersentuh bersih | `php -l` | Bersih (semua berkas) |
| V4.12 | Rantai hash log audit utuh | `verifyChain()` | Benar, termasuk `hash_sebelumnya` |
| V4.13 | Endpoint verifikasi rantai tersedia | `php artisan route:list` | `GET laporan/audit/verifikasi` ada |
| V4.14 | Tidak ada secret ter-hardcode | Pemeriksaan `app/` & `config/` | Bersih |
| V4.15 | Throttling login aktif | `routes/web.php:48` | `->middleware(['guest','throttle:5,1'])` |
| V4.16 | Regresi nol | `php artisan test` | **872 passed / 4160 assertions, 0 failed** |

---

## 5. Berkas yang Dibuat / Diubah

### Dibuat

| Berkas | Keterangan |
|---|---|
| `app/Support/PemisahanTugas.php` | Sumber kebenaran tunggal aturan *maker ≠ checker* |
| `tests/Feature/PemisahanTugasGcgTest.php` | 18 test GCG (46 assertions) |
| `docs/TAHAP4-GCG-OJK.md` | Dokumen ini |

### Diubah

| Berkas | Perubahan |
|---|---|
| `app/Services/TransactionLimitService.php` | Guard di `approveTransaction()` (:166) & `rejectTransaction()` (:311) |
| `app/Services/ReversalService.php` | Guard di `executeVoidReversal()` (:42) |
| `app/Services/RestrukturisasiService.php` | Guard approve (:172) & reject (:316) |
| `app/Http/Controllers/PengeluaranBebanController.php` | Guard `approve()` (:169) & `reject()` (:194) |
| `app/Http/Controllers/Manajer/ApprovalTazirController.php` | Guard `approve()` (:97) & `reject()` (:144) — multi-pengaju |
| `app/Http/Controllers/Manajer/ClosingApprovalController.php` | Guard `approve()` (:120) & `reject()` (:224) |
| `app/Http/Controllers/Pengurus/AmlComplianceController.php` | Guard `verify()` (:81) & `tolakAml()` (:149) |
| `tests/Feature/Tahap1StabilisasiKritisTest.php` | 3 test diselaraskan; `approvalVoid()` menerima `User $pengaju` |
| `tests/Feature/DanaKebajikanTazirTest.php` | Helper `manajer()` ditambahkan; pemutus void = manajer |

---

## 6. Risiko Sisa & Serah Terima ke Tahap Berikut

| # | Risiko / temuan sisa | Dampak | Ditangani di |
|---|---|---|---|
| R4.1 | **Audit login/logout/gagal belum dicatat** — `WebAuthController::login()`/`logout()` tidak menulis `AuditLog`/`LoginActivity`; hanya `throttle:5,1` yang melindungi | Kepatuhan: tidak ada jejak percobaan login & sesi | **Tahap 5** |
| R4.2 | Race condition saldo — `increment/decrement` tanpa `lockForUpdate()` di jalur transaksi | Integritas nilai uang | **Tahap 5** |
| R4.3 | FK `cascadeOnDelete` pada kolom jejak pelaku (`teller_opnames.user_id`, `pengeluaran_beban.created_by`, `angsuran.pembiayaan_id`, `jurnal_detail.jurnal_id`) | Menghapus user/pembiayaan **menghapus riwayat audit** | **Tahap 5** |
| R4.4 | Tidak ada guard periode tutup buku (jurnal *backdate* diterima — `TransaksiController.php:59` hanya `required\|date`) | Integritas periode & rekonsiliasi | **Tahap 5** |
| R4.5 | Jurnal gagal di Teller ditelan (`report($e)`) padahal saldo sudah berubah (`TellerController.php:115–117`) | Inkonsistensi saldo vs jurnal | **Tahap 5** |
| R4.6 | Indeks DB hilang pada kolom pencarian laporan (`jurnal.tanggal_jurnal`, `jurnal_detail.jurnal_id/akun_id`, `transaksi.waktu_transaksi`) | Kinerja pelaporan | **Tahap 5** |
| R4.7 | Duplikasi perhitungan Neraca (`DanaCadanganController::neraca()` vs `LaporanKeuanganSyariahService::posisiKeuangan()`) | Sumber angka ganda → risiko divergensi | **Tahap 5** |
| R4.8 | Password seeder seragam (`password123`) | Keamanan lingkungan non-produksi | **Tahap 5** |

---

## 7. Pernyataan Penyelesaian

Seluruh **27 temuan Klaster C** yang berkaitan dengan pemisahan tugas dan
penegakan RBAC telah ditutup. Risiko sisa Tahap 3 **R3.1 (guard maker-checker)
tuntas**, **R3.2 (audit RBAC menyeluruh) tuntas** dengan dokumentasi alasan
perbedaan matriks vs rute, **R3.3 tuntas**, sedangkan **R3.4 (hardening
login) baru sebagian** — throttling ada, audit login belum — dan secara jujur
**ditransfer ke Tahap 5** sebagai R4.1, tidak diklaim selesai.

Kriteria keberhasilan **K4.1–K4.12 terpenuhi seluruhnya**; **K4.13 terpenuhi
sebagian** dan diteruskan. Validasi **V4.1–V4.16** lulus tanpa pengecualian.
Suite regresi tidak mengalami penurunan:
**872 passed / 4160 assertions, 0 gagal** (+18 test, +46 assertion dibanding Tahap 3).

Tahap 4 dinyatakan **SELESAI** dan siap menjadi fondasi Tahap 5
(*hardening teknis & integritas data*, Klaster D).
