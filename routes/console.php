<?php

use App\Support\Setting;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Pemrosesan Deposito Mudharabah Jatuh Tempo (ARO Dinamis)
|--------------------------------------------------------------------------
| Dijalankan tiap jam (bukan dipatok 00:01 WIB) — command mengambil SELURUH
| deposito berstatus ACTIVE dengan maturity_date <= hari ini, lalu memprosesnya
| sesuai mode ARO pada bilyet:
|
|   NON_ARO   → pokok cair ke Rekening Simpanan Sukarela (2-1001), status CLOSED,
|               jurnal DEBET 2-1003 | KREDIT 2-1001.
|   ARO_POKOK → bagi hasil masuk 2-1001, pokok diperpanjang + tenor awal,
|               roll_count +1, last_rolled_at diperbarui.
|   ARO_PLUS  → bagi hasil dikapitalisasi menjadi pokok baru (jurnal adjustment
|               DEBET 2-1001 | KREDIT 2-1003), pokok diperpanjang + tenor awal,
|               roll_count +1, last_rolled_at diperbarui.
|
| Catatan: registrasi sengaja diletakkan di sini (bukan app/Console/Kernel.php)
| karena bootstrap Laravel 12 proyek ini memakai Illuminate\Foundation\Console\Kernel,
| sehingga method schedule() pada App\Console\Kernel tidak pernah dipanggil.
*/
Schedule::command('deposito:process-maturity')
    ->hourly()
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/deposito-jatuh-tempo.log'));

/*
|--------------------------------------------------------------------------
| Sinkronisasi Status Angsuran (Anti-Status Basi)
|--------------------------------------------------------------------------
| Dijalankan tiap jam (bukan dipatok 00:01 WIB) — setelah seluruh proses
| pergantian hari selesai (deposito, simpanan wajib) dan SEBELUM autodebet
| 07:00, sehingga autodebet & penagihan bekerja atas status yang benar.
| Pengulangan per jam membuat hari yang terlewat (mesin tidur) tetap
| tersinkron pada hari yang sama.
|
| `angsuran.status_angsuran` disimpan sebagai kolom (bukan dihitung saat
| query) karena dipakai sebagai filter SQL oleh teller, portal nasabah, CKPN,
| restrukturisasi, dan autodebet. Bila tidak diselaraskan, nilainya membeku
| pada saat jadwal dibuat: angsuran yang jatuh tempo bulan lalu tetap tertulis
| 'Jatuh Tempo', sehingga daftar tunggakan & NPF tidak akurat.
|
| Command idempoten: hanya baris yang statusnya berbeda yang ditulis, dan
| baris 'Dibayar Sebagian' (pokok belum tertagih penuh) tidak pernah ditimpa.
*/
Schedule::command('angsuran:sinkronkan-status')
    ->hourly()
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/sinkronisasi-status-angsuran.log'));

/*
|--------------------------------------------------------------------------
| Catatan R12 — command legacy `deposito:process-matured` TIDAK dijadwalkan
|--------------------------------------------------------------------------
| Command `deposito:process-matured` hanya menangani deposito non-ARO, yaitu
| himpunan bagian dari `deposito:process-maturity` (yang memproses NON_ARO +
| ARO_POKOK + ARO_PLUS). Keduanya pernah dijadwalkan pada slot 00:01 yang sama
| sehingga berpotensi saling menimpa (race/duplikasi pencairan). Penjadwalan
| harian kini HANYA ditangani `deposito:process-maturity`; command lama tetap
| tersedia untuk pemanggilan manual/CLI bila diperlukan.
*/

/*
|--------------------------------------------------------------------------
| Penagihan Simpanan Wajib Bulanan (Anniversary & End-of-Month)
|--------------------------------------------------------------------------
| Dijalankan tiap jam (bukan dipatok 00:01 WIB). Command memproses SELURUH
| anggota berstatus ACTIVE:
|
|  1. Terbitkan tagihan Simpanan Wajib periode bulan berjalan dengan
|     `firstOrCreate` (anti-duplikat) berdasarkan tanggal bergabung anggota.
|     Anggota yang bergabung pada tgl 29/30/31 disesuaikan ke akhir bulan
|     berjalan (Carbon::isEndOfMonth()), sehingga anggota yang bergabung
|     31 Januari ditagih pada 28 Februari (29 pada tahun kabisat).
|  2. Auto-Debit saldo Simpanan Sukarela (2-1001) → bila cukup: tagihan PAID
|     + jurnal DEBET 2-1001 | KREDIT 3-1002 (Simpanan Wajib — Modal Anggota).
|  3. Bila saldo tidak mencukupi: tagihan OVERDUE + notifikasi anggota & admin.
*/
Schedule::command('mandatory-savings:process-daily')
    ->hourly()
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/simpanan-wajib-harian.log'));

/*
|--------------------------------------------------------------------------
| Penerbitan Tagihan Jatuh Tempo Simpanan Wajib BULANAN (ProcessWajibDuesJob)
|--------------------------------------------------------------------------
| Dijalankan setiap TANGGAL 1 pukul 00:01 WIB — langsung setelah pemrosesan
| harian, sehingga anggota yang baru menjadi ACTIVE pada tanggal 1 tetap
| memperoleh tagihan periode berjalan pada hari yang sama.
|
| Command `wajib-dues:process` menerbitkan tagihan (`mandatory_savings_bills`
| / `wajib_dues`) berstatus UNPAID untuk SELURUH anggota ACTIVE sekaligus,
| dengan nominal DINAMIS dari master produk Simpanan Wajib
| (`jenis_simpanans.min_deposit_amount`, fallback parameter koperasi).
|
| Anti-duplikat: `MandatorySavingsService::issueBill()` memakai
| `firstOrCreate(['member_id', 'bill_period'])`, sehingga pemanggilan berulang
| (retry / double-schedule) TIDAK PERNAH menghasilkan tagihan ganda.
|
| Pemrosesan pembayaran (Jalur A manual Teller / Jalur B auto-debit pindah
| buku) tetap ditangani `mandatory-savings:process-daily`, bukan di sini.
*/
Schedule::command('wajib-dues:process')
    ->monthlyOn(1, '00:01')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/wajib-dues-bulanan.log'));

/*
|--------------------------------------------------------------------------
| Penyesuaian Harian CKPN Piutang Pembiayaan (PSAK 71)
|--------------------------------------------------------------------------
| Dijalankan setiap hari pukul 07:30 WIB — SESUDAH seluruh mutasi piutang
| harian selesai. Urutan ini penting (CKPN-6): bila CKPN dihitung pukul 00:01
| (sebelum autodebet 07:00), penyisihan dihitung atas saldo piutang yang masih
| akan dibayar pada hari yang sama, sehingga target CKPN berlebih dan selisih
| hari berikutnya menimbulkan jurnal pemulihan yang tidak perlu.
|
| Command menghitung target penyisihan berbasis kolektibilitas seluruh
| pembiayaan berjalan, menyandingkannya dengan saldo tercatat akun 1-2100, lalu
| memposting jurnal penyesuaian untuk selisihnya. Idempoten: bila selisih sudah
| di bawah toleransi kebijakan, tidak ada jurnal yang dibuat.
*/
Schedule::command('ckpn:hitung-penyesuaian')
    ->dailyAt('07:30')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/ckpn-penyesuaian.log'));

/*
|--------------------------------------------------------------------------
| Autodebet Angsuran Jatuh Tempo
|--------------------------------------------------------------------------
| Dijalankan setiap hari pukul 07:00 WIB. Command memproses seluruh angsuran
| jatuh tempo milik anggota yang metode ta'zirnya AUTODEBET, memotong saldo
| Simpanan Sukarela, memposting jurnal angsuran + ta'zir (ta'zir ke Dana
| Kebajikan 2-3001), lalu memotong tunggakan ta'zir susulan bila saldo cukup.
|
| Catatan: registrasi sengaja diletakkan di sini (bukan app/Console/Kernel.php)
| karena bootstrap Laravel 12 proyek ini memakai Illuminate\Foundation\Console\Kernel,
| sehingga method schedule() pada App\Console\Kernel tidak pernah dipanggil.
*/
Schedule::command('angsuran:run-autodebet')
    ->dailyAt('07:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/autodebet-angsuran.log'));

/*
|--------------------------------------------------------------------------
| Distribusi Bagi Hasil Deposito dari Laba Riil (R8)
|--------------------------------------------------------------------------
| Dijalankan setiap tanggal 1 pukul 00:30 WIB untuk periode BULAN SEBELUMNYA.
| Alur: `PendapatanService::pendapatanInvestasiBulanan()` menghitung total
| pendapatan investasi riil periode (margin + bagi hasil + ujroh) dari angsuran
| yang benar-benar sudah dibayar; lalu `DepositoService::bagiHasilPeriode()`
| mengalokasikan porsi bagi hasil secara PROPORSIONAL terhadap pokok deposito
| aktif dan memposting jurnal DEBET 5-3001 | KREDIT 2-1001 per deposito.
|
| Idempoten: deposito yang bagi hasil periodenya sudah dibayar akan dilewati,
| sehingga menjalankan ulang command tidak menghasilkan jurnal ganda.
*/
Schedule::command('deposito:bagi-hasil')
    ->monthlyOn(1, '00:30')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/bagi-hasil-deposito.log'));

/*
|--------------------------------------------------------------------------
| Purge Jejak Audit Kedaluwarsa (OJK-04)
|--------------------------------------------------------------------------
| Dijalankan setiap hari pukul 02:30 WIB. Command mengarsipkan lalu menghapus
| baris jejak audit yang melampaui masa retensi (config/keamanan.php →
| retensi_log), kemudian menyegel ulang rantai hash baris yang tersisa.
|
| Dasar: UU No. 27/2022 (PDP) Pasal 16 (pembatasan penyimpanan) & Pasal 35
| (hak penghapusan), dengan tetap menghormati kewajiban penyimpanan jejak
| audit keuangan (UU No. 25/1992 & POJK).
|
| Sengaja memakai `--force` karena dijalankan non-interaktif oleh scheduler.
*/
Schedule::command('log:purge-kedaluwarsa --force')
    ->dailyAt('02:30')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/purge-log-kedaluwarsa.log'));

/*
|--------------------------------------------------------------------------
| PEMANTAUAN INTEGRITAS BUKU BESAR & KESEHATAN SISTEM (PSAK / OJK)
|--------------------------------------------------------------------------
| Gerbang kepatuhan otomatis yang berjalan setiap malam SETELAH seluruh
| proses mutasi harian selesai (deposito 00:01 → autodebet 07:00 → CKPN
| 07:30). Tujuannya menjaga jaminan PSAK — "jurnal adalah satu-satunya
| sumber kebenaran akuntansi" — tetap utuh dari hari ke hari, tanpa
| menunggu temuan auditor.
|
|   1. jurnal:rekonsiliasi      — total debet harus = total kredit.
|      Dasar: PSAK 101 (penyajian laporan keuangan) & prinsip double-entry.
|   2. jurnal:deteksi-orphan    — header Posted wajib didukung baris detail
|      seimbang; tidak ada detail menggantung (auditabilitas).
|   3. denyut:kesehatan         — mesin sinkronisasi real-time hidup
|      (mendeteksi kegagalan cache yang ditelan diam-diam).
|
| Jalur alternatif `runInBackground()` dipakai agar exit code tidak
| dianggap kegagalan scheduler; keluaran tiap command tetap direkam ke
| berkas log tersendiri untuk jejak audit operator (OJK).
*/
Schedule::command('jurnal:rekonsiliasi')
    ->dailyAt('03:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/monitor-jurnal-rekonsiliasi.log'));

Schedule::command('jurnal:deteksi-orphan --contoh=20')
    ->dailyAt('03:10')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/monitor-jurnal-orphan.log'));

Schedule::command('denyut:kesehatan')
    ->dailyAt('03:20')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/monitor-denyut-kesehatan.log'));

/*
|--------------------------------------------------------------------------
| Cadangan Basis Data Harian (RPO ≤ 24 jam)
|--------------------------------------------------------------------------
| Dicoba SETIAP JAM mulai 01:00 WIB — setelah pemrosesan deposito dan
| simpanan wajib (00:01) selesai, sehingga cadangan memuat kondisi buku
| besar yang sudah final untuk hari itu.
|
| Mengapa tidak `dailyAt('01:00')` saja: scheduler hanya menjalankan event
| yang jatuh pada menit itu. Bila komputer/server mati atau tidur pukul
| 01:00, cadangan hari tersebut hilang sama sekali dan indikator kesegaran
| cadangan menyala keesokan harinya. Dengan pengulangan per jam plus opsi
| `--lewati-bila-hari-ini-ada`, cadangan pertama yang berhasil dibuat pada
| hari itu langsung menghentikan percobaan berikutnya — tetap satu berkas
| per hari, tetapi tidak lagi bergantung pada mesin menyala tepat 01:00.
|
| Retensi berkas cadangan dibaca dari parameter kebijakan
| `backup_retensi_hari` (menu Kebijakan Koperasi → Pemeliharaan & Cadangan
| Basis Data), default 14 hari. Nilai dibaca saat `schedule:run` sehingga
| perubahan pengurus langsung berlaku tanpa mengubah kode. Berkas disimpan
| di storage/app/backups (di luar public/), atau di `BACKUP_DIREKTORI`.
|
| Salinan offsite: setel `BACKUP_SALINAN_DIREKTORI` pada .env (misalnya
| E:\backup-koperasi atau share jaringan host cadangan) agar berkas yang
| sudah terenkripsi ikut disalin ke lokasi kedua. Bila kosong, hanya cadangan
| lokal yang dibuat. Kegagalan menyalin dicatat sebagai kegagalan command.
|
| Pemulihan:
|   gunzip -c storage/app/backups/koperasi_syariah-YYYYmmdd-His.sql.gz |
|     mysql -u root -p koperasi_syariah
*/
$parameterCadangan = [
    '--simpan-hari' => Setting::backupRetensiHari(),
    '--lewati-bila-hari-ini-ada',
];

if ($salinanCadangan = config('cadangan.salinan_direktori')) {
    // Dikirim sebagai parameter terpisah agar nilainya dikutip. Bila digabung
    // menjadi satu string perintah, jalur ber-spasi (mis. "Cadangan Koperasi
    // Syariah") dipecah menjadi argumen liar dan cadangan gagal setiap jam.
    $parameterCadangan['--salinan-tujuan'] = $salinanCadangan;
}

Schedule::command('db:cadangkan', $parameterCadangan)
    ->hourly()
    ->between('01:00', '23:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/cadangan-basis-data.log'));

/*
|--------------------------------------------------------------------------
| Pemantauan Operasional Setiap Jam
|--------------------------------------------------------------------------
| Setelah cadangan selesai (01:00), periksa tiga indikator ketersediaan:
| koneksi basis data, integritas jurnal (debet = kredit), dan kesegaran
| berkas cadangan. Dijalankan tiap jam agar gangguan terdeteksi maksimal
| satu jam setelah terjadi (mendukung target ketersediaan 99,5%).
|
| Perintah keluar dengan kode 1 bila ada indikator gagal; rinciannya
| tersimpan di logs/pemantauan-operasional.log dan kanal log `denyut`.
*/
Schedule::command('denyut:pemeriksaan')
    ->hourly()
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/pemantauan-operasional.log'));
