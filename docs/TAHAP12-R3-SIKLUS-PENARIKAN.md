# TAHAP 12 — R3: Siklus Penarikan Simpanan Wajib

> ⚠️ **SUPERSEDED (Gelombang 3, 2026-10-05):** dokumen ini merekam implementasi R3 saat
> penarikan masih dikendalikan saklar `simpanan_wajib_boleh_ditarik`. Sejak audit kepatuhan
> Gelombang 3, penarikan rutin Simpanan Wajib **DIKUNCI PERMANEN** per PSAK 101 ¶31 — saklar
> kebijakan **tidak lagi berpengaruh** dan rute penarikan selalu ditolak dengan audit log
> `PENARIKAN_MODAL_DIBLOKIR`. Satu-satunya jalur sah = modul `member_terminations`
> (`\App\Services\ExitKeanggotaanService`). Lihat `tests/Feature/SimpananWajibPenarikanTest.php`
> dan `docs/TAHAP12-SIMPANAN-WAJIB-ROADMAP.md` §0/§6. Isi teknis di bawah tetap
> dipertahankan sebagai catatan sejarah implementasi.

**Status:** ✅ SELESAI & TERVERIFIKASI — infrastruktur **dan** permukaan operasional telah tersedia
**Tanggal:** 2026-09-30
**Tahap:** R3 (bagian dari Langkah 3 dari 6)
**Rujukan roadmap:** `docs/TAHAP12-SIMPANAN-WAJIB-ROADMAP.md` §3 R3
**Rujukan analisis:** `docs/TAHAP12-SIMPANAN-WAJIB-ANALISIS.md` (hardcode H10)

**Dasar syariah:** PSAK 101 ¶ 31 — Simpanan Wajib adalah **Modal Anggota**; penarikannya
bukan transaksi rutin melainkan **pengurangan penyertaan anggota**, umumnya hanya sah
saat anggota **keluar dari keanggotaan**.
**Dasar regulasi:** UU No. 25/1992 Pasal 30 &amp; Anggaran Dasar koperasi mengenai tata cara
pengembalian simpanan anggota pada saat berhenti; Permen KUKM No. 09/2018 Pasal 24–26.

**Khalayak:** Tim TI, Pengurus, DPS, auditor eksternal.

---

## 1. Mengapa Siklus Penarikan Perlu Ada

Tanpa siklus penarikan, sistem **tidak dapat menolkan saldo** Simpanan Wajib seorang anggota
yang keluar. Akibatnya:

- Saldo modal anggota **menggantung selamanya** di neraca meski anggota sudah tidak aktif.
- Buku besar tidak pernah seimbang kembali terhadap jumlah anggota riil.
- Koperasi tidak dapat memenuhi kewajibannya mengembalikan simpanan anggota yang berhenti.

Inilah satu-satunya jalur yang membuat putaran akuntansi Simpanan Wajib **tertutup**
(setor → saldo naik; tarik → saldo kembali ke `0,00`).

---

## 2. Status Implementasi (Verifikasi 2026-09-30)

### 2.1 Infrastruktur yang Sudah Ada Sebelumnya ✅

| Komponen | Lokasi | Keterangan |
|---|---|---|
| Gerbang kebijakan | `MandatorySavingsService::penarikanDiizinkan()` baris 104 | Mengembalikan `Setting::simpananWajibBolehDitarik()` |
| Saklar kebijakan | `Setting::simpananWajibBolehDitarik()` baris 408 | Default **`false`** = saldo terkunci (perilaku sistem saat ini) |
| Waktu penarikan | `Setting::simpananWajibHariPenarikan()` baris 418 | `SETELAH_KELUAR` (default) \| `KAPAN_SAJA` |
| Validasi UI | `PengurusController.php:756` | `nullable\|boolean` + whitelist `AdminSettingsController.php:51` |
| Kontrol UI | `resources/views/pengurus/kebijakan.blade.php:163–165` | Checkbox `simpanan_wajib_boleh_ditarik` + hidden input |
| Jurnal penarikan | `AutoJournalingService::jurnalTarikSimpananWajib()` baris 816–822 | `DEBET` akun kanonik (hasil resolusi) / `KREDIT 1-1001` Kas Teller |

### 2.2 Yang BARU Diimplementasikan pada R3 ✅

| Komponen | Lokasi | Keterangan |
|---|---|---|
| Guard kebijakan &amp; waktu | `MandatorySavingsService::alasanPenarikanDitolak()` baris 583 | Mengembalikan `null` bila diizinkan, atau **string alasan** penolakan (pesan menyebut PSAK 101) |
| Saldo per anggota | `MandatorySavingsService::saldoSimpananWajib()` baris 609 | Menjumlahkan mutasi `jurnal_detail` akun kanonik **difilter `anggota_id`** — bukan saldo akun agregat |
| Layanan penarikan | `MandatorySavingsService::tarikSimpananWajib()` baris 648 | Guard kebijakan → metode → saldo → `Transaksi` + jurnal, seluruhnya dalam **satu transaksi DB** |
| Aksi HTTP | `Teller\SimpananWajibController::tarik()` baris 142 | Validasi masukan, pemanggilan service, penanganan kegagalan + `Log::error` |
| Rute | `routes/web.php` | `POST /teller/simpanan-wajib/{anggota}/tarik` → `teller.simpanan-wajib.tarik` |
| Permukaan loket | `resources/views/teller/simpanan-wajib.blade.php` | Panel penarikan; tampil hanya bila kebijakan mengizinkan, dengan penjelasan bila tidak |
| Uji | `tests/Feature/SimpananWajibPenarikanTest.php` | **15 test / 39 assertion**, semuanya lulus |

Rumus jurnal yang diposting (memakai titik resolusi tunggal dari R1):

```
DEBET  : 3-1002 Simpanan Wajib Anggota (Modal Anggota)   = nominal
KREDIT : 1-1001 Kas Teller / Bank                        = nominal
```

> **Catatan penting — mengapa rute tidak memakai middleware `anggota.aktif`:**
> middleware tersebut menolak seluruh anggota non-`ACTIVE`, padahal justru anggota
> berstatus **Keluar** yang menjadi sasaran utama penarikan (pengembalian penyertaan).
> Gerbang status ditegakkan di dalam service melalui `alasanPenarikanDitolak()`.

---

## 3. Desain Keamanan Berlapis

Empat gerbang berurutan — penarikan hanya lolos bila **semuanya** terbuka:

| # | Gerbang | Perilaku bila gagal |
|---|---|---|
| 1 | Saklar `simpanan_wajib_boleh_ditarik` | Ditolak; pesan menyebut PSAK 101 ¶ 31 |
| 2 | Waktu `simpanan_wajib_hari_penarikan` | `SETELAH_KELUAR` → hanya status Keluar; `KAPAN_SAJA` → semua anggota |
| 3 | Metode | Hanya `CASH` / `TRANSFER`; `AUTO_DEBIT` ditolak (itu jalur scheduler) |
| 4 | Saldo | Nominal &gt; saldo ditolak; saldo tidak boleh negatif (overdraw penyertaan dilarang) |

**Integritas transaksional:** `Transaksi` dan jurnal dibuat di dalam satu `DB::transaction`.
Bila posting jurnal gagal, seluruhnya di-rollback — **saldo anggota tidak pernah berkurang
tanpa jejak jurnal**, dan tidak ada baris transaksi menggantung.

### 3.1 Temuan Nyata Saat Pengujian: Pembulatan Nominal

Uji `test_penarikan_mengikuti_kebijakan_pembulatan_koperasi` menemukan ketidaksesuaian:

- Teller meminta Rp **37.500,50**.
- `AutoJournalingService::post()` membulatkan ke kebijakan koperasi
  (`Setting::pembulatanNominal()`, default **rupiah penuh**) → buku besar mencatat Rp **37.501**.
- Tanpa koreksi, layar Teller akan menampilkan saldo yang **selisih sen** dari buku besar —
  memutus jejak audit (PSAK 101 ¶ 45–47).

**Perbaikan:** `tarikSimpananWajib()` kini membulatkan nominal memakai
`Setting::pembulatanNominal()` **sebelum** guard saldo, sehingga angka yang dilaporkan ke
Teller identik dengan yang dibukukan.

---

## 4. Pemenuhan Milestone

| ID | Milestone | Kriteria Lulus | Status | Bukti |
|---|---|---|---|---|
| **M3.1** | Gerbang kebijakan ada | `penarikanDiizinkan()` membaca `Setting` (default `false`) | ✅ | `MandatorySavingsService.php:104`; `Setting.php:408` |
| **M3.2** | Jurnal penarikan terdefinisi | `DEBET` akun kanonik / `KREDIT` Kas Teller; total seimbang | ✅ | `AutoJournalingService.php:816–822` |
| **M3.3** | Method `tarikSimpananWajib()` ada | Guard kebijakan, waktu, metode, saldo | ✅ | `MandatorySavingsService.php:648`; `alasanPenarikanDitolak()` :583 |
| **M3.4** | Permukaan HTTP tersedia | Rute + aksi controller + form di loket | ✅ | `routes/web.php`; `Teller/SimpananWajibController.php:142`; `teller/simpanan-wajib.blade.php` |
| **M3.5** | Kanal tunai &amp; non-tunai | Penarikan tunai (Kas Teller) dan transfer (Bank) | ✅ | Keduanya memakai akun kanonik; metode divalidasi `in:CASH,TRANSFER` |
| **M3.6** | Penarikan berulang aman | Melebihi saldo ditolak; kegagalan tidak menyisakan jejak | ✅ | 4 test guard saldo + `test_kegagalan_tidak_meninggalkan_transaksi_menggantung` |
| **M3.7** | Regresi nihil | `scripts\run-tests.ps1` hijau | ✅ | **1135 passed (5177 assertions)**, exit code 0 |

---

## 5. Cara Mengaktifkan (Tanpa Deploy, Tanpa Ubah Kode)

Fitur penarikan **default NONAKTIF** (`simpanan_wajib_boleh_ditarik = false`) — konsisten
dengan prinsip **P4 Default Aman**: perilaku sistem tidak berubah sampai Pengurus memutuskan.

Urutan aktivasi dari panel **Kebijakan Koperasi → Ketentuan Simpanan Wajib**:

1. Set saklar **Izinkan penarikan** = aktif.
2. Pilih **waktu penarikan**: `SETELAH_KELUAR` (disarankan) atau `KAPAN_SAJA`.
3. Simpan. Panel penarikan langsung muncul di `/teller/simpanan-wajib`.
4. Jalankan `php artisan psak:validasi-simpanan-wajib` — harus tetap `✔ LULUS`.

Tidak ada perubahan kode, tidak ada deploy, tidak ada migrasi.

---

## 6. Rollback

Bila penarikan perlu dihentikan kembali: **matikan saklar** `simpanan_wajib_boleh_ditarik`
dari panel Kebijakan. Seluruh jalur penarikan langsung tertutup (gerbang #1), sementara
jurnal historis yang sudah diposting **tetap utuh** dan tidak boleh diubah.

Tidak ada migrasi skema pada R3 → tidak ada rollback basis data yang diperlukan.

---

*Dokumen ini adalah bukti Milestone R3. Lanjut ke `docs/TAHAP12-R4-VALIDASI-AKUNTANSI-PSAK.md`.*
