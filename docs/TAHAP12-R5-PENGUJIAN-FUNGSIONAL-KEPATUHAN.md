# TAHAP 12 — R5: Pengujian Fungsional &amp; Pengujian Kepatuhan

**Status:** ✅ SELESAI &amp; TERVERIFIKASI
**Tanggal:** 2026-09-30
**Tahap:** R5 (Langkah 5 dari 6)
**Rujukan roadmap:** `docs/TAHAP12-SIMPANAN-WAJIB-ROADMAP.md` §3 R5

**Khalayak:** Tim TI, Pengurus, DPS, auditor eksternal.

---

## 1. Tujuan

Membuktikan secara **empiris** bahwa modul Simpanan Wajib berjalan **stabil, dinamis, dan
patuh** setelah seluruh perubahan R1–R4 — bukan sekadar klaim di atas kertas.

Pengujian dijalankan melalui *runner* resmi proyek yang menegakkan **Gerbang 1**
(`optimize:clear` wajib dijalankan lebih dulu untuk mencegah `config:cache` membekukan
`APP_KEY` → 419 CSRF massal):

```powershell
powershell -ExecutionPolicy Bypass -File scripts\run-tests.ps1
```

Runner menjalankan 3 langkah berurutan:
1. `php artisan optimize:clear` (WAJIB)
2. Verifikasi cache benar-benar bersih (`bootstrap\cache` tidak memuat `config.php` / `routes-*.php` / `events.php`)
3. `php artisan test`

---

## 2. Hasil Regresi Penuh (Bukti Utama)

```
Tests:    1120 passed (5139 assertions)
Duration: 162.34s
Exit code: 0
```

**Baseline sebelum Tahap 12:** 1110 passed. **Sesudah:** 1120 passed (**+10 test baru**),
**0 failure, 0 error, 0 skipped**. Tidak ada satu pun test lama yang rusak — membuktikan
prinsip **P3 Non-Disruptive** terpenuhi.

---

## 3. Berkas Uji Modul Simpanan Wajib

| Berkas | Jumlah Test | Fokus |
|---|---|---|
| `tests/Feature/MandatorySavingsSimpananWajibTest.php` | 12 | Siklus inti: penerbitan tagihan, auto-debit, teller, jatuh tempo, *edge case* tanggal |
| `tests/Feature/SimpananWajibKonfigurasiDinamisTest.php` | 9 | **R1** — default aman, rantai resolusi akun, validasi nilai tak sah, persistensi, jalur teller |
| `tests/Feature/TellerSimpananWajibTest.php` | 6 | Permukaan HTTP loket: daftar, filter, otorisasi, pelunasan, idempotensi |
| `tests/Feature/ValidasiKepatuhanSimpananWajibTest.php` | 10 | **R4** — 7 kategori validasi kepatuhan + bukti perintah hanya-baca |
| **Total** | **37** | |

---

## 4. Cakupan Pengujian Menurut Milestone

### M5.1 — Semua test lama hijau ✅
`1120 passed`, **0 failure, 0 error**. Tidak ada regresi.

### M5.2 — Test baru hijau ✅
Berkas uji baru untuk R1 (`SimpananWajibKonfigurasiDinamisTest`) dan R4
(`ValidasiKepatuhanSimpananWajibTest`) — seluruh assertion lulus.

### M5.3 — Penjaga lintas-jalur ✅
Bukti bahwa **seluruh jalur setoran mendarat di akun yang sama** diverifikasi melalui dua
mekanisme independen:

1. **Statis** — penelusuran kode: setiap jalur (auto-debit, teller, bundling CIF, API deposito,
   *reversal*) memanggil titik resolusi tunggal `MandatorySavingsService::akunSimpananWajib()`
   atau menerima hasilnya sebagai parameter. Lihat `docs/TAHAP12-R2-PENYATUAN-AKUN-COA.md` §2.2.
2. **Dinamis** — `SimpananWajibKonfigurasiDinamisTest::test_pelunasan_teller_memakai_akun_hasil_resolusi`
   dan `::test_resolusi_akun_mengikuti_konfigurasi_kebijakan` membuktikan jurnal pelunasan
   benar-benar mengikuti hasil resolusi.

### M5.4 — Default aman terbukti ✅
Dua test khusus membuktikan bahwa dengan parameter kosong/`NULL`, sistem berperilaku
**persis seperti sebelum Tahap 12**:

- `test_default_aman_mengikuti_akun_kanonik_standar` — akun jatuh ke `3-1002`
- `test_default_aman_jatuh_tempo_dan_penarikan` — accessor `Setting::simpananWajibOverdueDay()`
  sudah **dihapus** (jatuh tempo kini 100% otomatis: *anniversary* bulanan sejak anggota
  bergabung), penarikan **tertutup**

Ini adalah jaminan langsung **P4 Default Aman**.

### M5.5 — Audit data demo bersih ✅
`php artisan psak:validasi-simpanan-wajib` → **exit code `0`**, seluruh 7 kategori `0`
(lihat `docs/TAHAP12-R4-VALIDASI-AKUNTANSI-PSAK.md` §4).

### M5.6 — Edge case ✅

| Skenario | Test Pembuktian | Hasil |
|---|---|---|
| Anggota bergabung tgl 31 → jatuh tempo di-*clamp* ke akhir bulan | `test_tanggal_jatuh_tempo_disesuaikan_ke_akhir_bulan_untuk_gabung_tanggal_31` | ✅ |
| Anggota bergabung tanggal biasa | `test_anggota_bergabung_tanggal_biasa_ditagih_pada_tanggal_yang_sama` | ✅ |
| Penerbitan tagihan idempoten (tanpa duplikat) | `test_penerbitan_tagihan_idempoten_tanpa_duplikat` | ✅ |
| Saldo tidak cukup → `OVERDUE` + notifikasi | `test_saldo_tidak_cukup_menandai_overdue_dan_mengirim_notifikasi` | ✅ |
| Anggota tidak aktif → tidak ditagih | `test_anggota_tidak_aktif_tidak_ditagih` | ✅ |
| `--dry-run` tidak mengubah data | `test_dry_run_tidak_mengubah_data` | ✅ |
| Anggota tanpa auto-debit → tagihan `UNPAID` untuk teller | `test_anggota_tanpa_auto_debit_membiarkan_tagihan_unpaid_untuk_teller` | ✅ |
| Pelunasan kedua (idempotensi) → tanpa jurnal baru | `test_pelunasan_kedua_bersifat_idempoten_tanpa_jurnal_baru` | ✅ |
| Tagihan lewat jatuh tempo ditandai `OVERDUE` | `test_tagihan_teller_lewat_jatuh_tempo_ditandai_overdue` | ✅ |
| Anti-duplikat (anggota, periode) dijaga skema | `test_indeks_unik_periode_mencegah_tagihan_duplikat` | ✅ |
| Validasi menolak nilai tak sah (kanal, akun) | 2 test pada `SimpananWajibKonfigurasiDinamisTest` | ✅ |
| Field lama `simpanan_wajib_overdue_day` diabaikan & kolomnya sudah dihapus | `test_parameter_jatuh_tempo_lama_sudah_dihapus` | ✅ |

---

## 5. Hasil Uji End-to-End Data Demo

1. `php artisan db:seed --class=AdamRenataSeeder --force` — seeder berjalan bersih.
2. `php artisan psak:validasi-simpanan-wajib` — **0 temuan**, exit code `0`.

Uji ini menemukan **1 cacat nyata** pada seeder (nominal tagihan bundling) yang kemudian
diperbaiki — bukti bahwa rangkaian pengujian ini benar-benar *end-to-end*, bukan formalitas.
Rincian lengkap: `docs/TAHAP12-R4-VALIDASI-AKUNTANSI-PSAK.md` §5.

---

## 6. Catatan Kejujuran: Cakupan yang Belum Diuji

| Rencana Roadmap | Status | Alasan |
|---|---|---|
| Uji skala **1.000 anggota × 12 periode** | ⏳ Belum dijalankan | Bukan bagian dari dua permasalahan yang disasar; memerlukan *fixture* volume besar. Infrastruktur indeks jalur baca sudah diverifikasi terpisah pada `Tahap7SkalabilitasTest`. |
| `SimpananWajibPenarikanTest` | ⏳ Tidak dibuat | Bergantung pada R3 yang belum diimplementasikan sebagai fitur (lihat `docs/TAHAP12-R3-SIKLUS-PENARIKAN.md`). |
| `SimpananWajibAkunTest` | ✅ Tercakup | Digabung ke `SimpananWajibKonfigurasiDinamisTest` (test resolusi akun &amp; default aman) agar tidak menduplikasi *fixture*. |

Tidak ada cakupan yang diklaim lulus tanpa bukti.

---

## 7. Cara Menjalankan Ulang

```powershell
# Gerbang lengkap (WAJIB)
powershell -ExecutionPolicy Bypass -File scripts\run-tests.ps1

# Hanya berkas Simpanan Wajib
powershell -ExecutionPolicy Bypass -File scripts\run-tests.ps1 -Tests tests\Feature\ValidasiKepatuhanSimpananWajibTest.php

# Gerbang kepatuhan akuntansi
php artisan psak:validasi-simpanan-wajib
```

---

*Dokumen ini adalah bukti Milestone R5. Lanjut ke `docs/TAHAP12-R6-DOKUMENTASI-KEPATUHAN.md`.*
