<?php

namespace App\Support;

use App\Models\KebijakanKoperasi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Pintu masuk tunggal (single source of truth) seluruh konfigurasi kebijakan
 * operasional koperasi — dipakai bersama oleh Controller, Service, dan
 * Scheduler tanpa perlu menyentuh kode inti.
 *
 * Sifat:
 *   - SELALU membaca nilai terbaru dari database (bukan config statis).
 *   - Cache 60 menit untuk menekan beban query pada proses harian/scheduler.
 *   - Cache diinvalidasi OTOMATIS saat baris kebijakan disimpan/dihapus
 *     (lihat KebijakanKoperasi::booted() → flushSettingCache()).
 *
 * Pemakaian:
 *   Setting::get('mandatory_monthly_savings', 50000);
 *   Setting::get('shu_hold_on_overdue', true);
 */
class Setting
{
    /** Kunci cache tunggal untuk seluruh parameter kebijakan. */
    public const CACHE_KEY = 'kebijakan_koperasi.settings';

    /** Masa hidup cache (detik) — 60 menit. */
    public const CACHE_TTL = 3600;

    /**
     * Nilai fallback bila kolom belum ada / baris kebijakan belum di-seed.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'nama_koperasi' => 'Koperasi Syariah',
        // ── Awalan nomor dokumen (dapat diubah dari UI Pengurus) ─────────
        // Nilai kosong = pakai awalan bawaan di `NomorDokumen::BAWAAN`,
        // sehingga perilaku sistem tidak berubah bila tidak diisi.
        'prefix_nomor_transaksi' => null,
        'prefix_nomor_rekening' => null,
        'prefix_nomor_jurnal' => null,
        'prefix_nomor_pengeluaran_beban' => null,
        'prefix_nomor_terminasi' => null,
        'prefix_nomor_deposito' => null,
        'limit_manajer' => 50000000,
        'limit_pembiayaan_manajer' => 50000000,
        'limit_penarikan_teller' => 5000000,
        'limit_setoran_teller' => 50000000,
        // Batas maksimum kas fisik yang boleh mengendap di laci teller.
        // Dipakai modul Teller (opening/closing & transfer Brankas ↔ Teller)
        // agar teller tidak menahan kas melebihi batas kebijakan koperasi.
        'teller_max_cash_limit' => 25000000.00,
        'minimum_transaksi_teller' => 1000.00,
        'mandatory_principal_savings' => 100000.00,
        'mandatory_monthly_savings' => 50000.00,
        // Batas dinamis nominal pembukaan rekening (dapat diubah dari UI Pengurus)
        'minimum_pembukaan_rekening' => 10000.00,
        'maksimum_pembukaan_rekening' => 50000000.00,
        'shu_hold_on_overdue' => true,
        // ── Parameter dinamis modul Simpanan Wajib (Tahap 12 / R1) ───────
        // Default = perilaku sistem saat ini, sehingga membiarkannya kosong
        // TIDAK mengubah apa pun (P4 Default Aman).
        'simpanan_wajib_kanal_default' => 'AUTO_DEBIT',
        'simpanan_wajib_boleh_ditarik' => false,
        'simpanan_wajib_hari_penarikan' => 'SETELAH_KELUAR',
        'account_simpanan_wajib_id' => null,
        // ── Penanganan dinamis tunggakan Simpanan Wajib (Tahap 12 / R4) ──
        // Saklar induk modul Auto-Debet. Nonaktif = sistem TIDAK pernah
        // memotong saldo Sukarela; seluruh tagihan dibayar manual di Teller.
        'simpanan_wajib_auto_debit_aktif' => true,
        // Saklar modul penanganan dinamis saat saldo kurang / kosong.
        // Nonaktif = perilaku lama (tandai OVERDUE + notifikasi saja).
        'simpanan_wajib_penanganan_dinamis' => true,
        // Maksimum cicilan yang boleh diajukan anggota. 0 = tanpa batas.
        'simpanan_wajib_maks_cicilan' => 6,
        // Tambahan bulan tenggat untuk setiap pengajuan reschedule.
        'simpanan_wajib_reschedule_bulan' => 1,
        // Maksimum reschedule per satu tagihan. 0 = tanpa batas.
        'simpanan_wajib_maks_reschedule' => 3,
        // Anonimisasi notifikasi pada kanal publik: identitas anggota
        // (nama/no_anggota) disamarkan (PDP / UU 27/2022). Nonaktif =
        // pesan boleh memuat identitas lengkap (kanal internal saja).
        'simpanan_wajib_notif_anonim' => true,
        // Batas kekurangan minimal yang masih dianggap "kurang sedikit" (Rp).
        // Di bawah ambang ini, sistem memprioritaskan reschedule ringan.
        'simpanan_wajib_ambang_kurang_ringan' => 25000.00,
        // ── Kebijakan konsinyasi: TIDAK ADA AUTO-DEBIT TANPA KONFIRMASI ───
        // Saklar INDUK kebijakan (default: AKTIF/true). Bila aktif, SELURUH
        // kanal auto-debit (Simpanan Wajib, Angsuran, Ta'zir) DILARANG
        // memotong saldo secara mandiri; scheduler hanya membuat USULAN yang
        // harus dikonfirmasi pengguna berwenang lewat Pusat Konfirmasi.
        // Menyetel false mengembalikan perilaku lama (auto-debit langsung) —
        // disediakan HANYA untuk kompatibilitas/migrasi, bukan default.
        'autodebit_wajib_konfirmasi' => true,
        // Jam posting transaksi auto-debit Simpanan Wajib (DYN-17). Sebelumnya
        // konstanta kelas `MandatorySavingsService::JAM_POSTING = '00:01'`
        // sehingga koperasi tidak dapat menggeser jam posting lewat UI.
        'jam_posting_simpanan_wajib' => '00:01',
        // Alokasi SHU (persentase) — dapat dikonfigurasi pengurus
        'shu_pct_cadangan_modal' => 30.00,
        'shu_pct_jasa_anggota' => 40.00,
        'shu_pct_jasa_modal' => 20.00,
        'shu_pct_dana_pendidikan' => 10.00,
        // Metode perhitungan partisipasi SHU anggota. Satu-satunya metode
        // yang didukung sistem: SRH (Saldo Rata-rata Harian).
        'metode_perhitungan_shu' => 'SRH',
        // Limit approval beban operasional (pengganti konstanta LIMIT_APPROVAL)
        'limit_approval_beban' => 1000000.00,
        // Nominal minimum pengajuan pembiayaan baru (dapat diubah dari UI).
        'minimal_pengajuan_pembiayaan' => 1000000.00,
        // Ambang hari kolektibilitas pembiayaan (NPF) — pola KSP syariah
        // (Kemenkop UKM): Lancar 0-10, Perhatian 11-90, Kurang Lancar 91-120,
        // Diragukan 121-180, Macet > 180 hari.
        'kolektibilitas_kol1_hari' => 10,
        'kolektibilitas_kol2_hari' => 90,
        'kolektibilitas_kol3_hari' => 120,
        'kolektibilitas_kol4_hari' => 180,
        'tazir_mode' => 'FLAT_HARIAN',
        'tazir_rate' => 2000.00,
        // Saklar tunggal fitur denda keterlambatan angsuran (Ta'zir / Late Fee).
        // 0 = fitur NONAKTIF → ta'zir tidak dihitung & tidak diposting.
        'financing_late_fee_per_day' => 0.00,
        'tazir_grace_period_days' => 0,
        'tazir_default_method' => 'TUNAI',
        // Nominal default ta'zir bila teller tidak mengirim nominal eksplisit.
        'tazir_default_nominal' => 250000.00,
        // Akun CoA penampung denda ta'zir (null = ikuti akun Dana Kebajikan).
        'akun_tazir_id' => null,
        // ── Parameter PSAK & regulasi (dapat dikonfigurasi Pengurus) ────
        // Bagi hasil deposito (PSAK 105): rate per tahun dalam desimal.
        'deposito_rate_pa' => 0.08,
        // Nisbah & margin default (persen) bila produk belum menetapkannya.
        'nisbah_default_persen' => 60.00,
        'margin_default_persen' => 12.00,
        // Ambang pelaporan transaksi keuangan mencurigakan (PPATK).
        'aml_threshold' => 100000000.00,
        // ── Pelaporan PPATK (UU 8/2010 & POJK 27/2022) ────────────────────
        // Ambang transaksi TUNAI wajib lapor (LTKT) — terpisah dari ambang
        // transaksi mencurigakan agar perubahan regulasi cukup di sini.
        'ltkt_threshold' => 500000000.00,
        'ppatk_jenis_tunai' => 'Setoran Tunai,Penarikan Tunai',
        'ppatk_nama_pelapor' => '',
        'ppatk_kode_pelapor' => '',
        'npwp_koperasi' => '',
        // Kebijakan pembulatan nominal jurnal & toleransi selisih debet-kredit.
        'pembulatan_desimal' => 0,
        'toleransi_selisih_jurnal' => 0.01,
        // Ambang penyimpangan TOTAL nisbah (anggota + koperasi) dari 100% yang
        // masih dianggap sah (persen). Dipakai validator produk simpanan &
        // pembiayaan. Default 0,01 = perilaku lama.
        'toleransi_persen' => 0.01,
        // ── Integritas posting & rekonsiliasi buku besar ─────────────────
        // Gate status keberhasilan: jurnal hanya boleh tertaut ke transaksi
        // yang berstatus SUKSES. Cegah pencatatan transaksi gagal/pending ke
        // buku besar. Jurnal tanpa transaksi_id tetap diizinkan (jurnal manual).
        'posting_wajib_transaksi_sukses' => true,
        // Rekonsiliasi otomatis inline: verifikasi ulang saldo & integritas
        // buku besar SETIAP KALI transaksi diposting, lalu rollback bila cacat.
        'rekonsiliasi_inline_aktif' => true,
        // CKPN piutang pembiayaan per tingkat kolektibilitas (PSAK 101).
        'ckpn_persen_kol1' => 0.50,
        'ckpn_persen_kol2' => 10.00,
        'ckpn_persen_kol3' => 50.00,
        'ckpn_persen_kol4' => 75.00,
        'ckpn_persen_kol5' => 100.00,
        // ── Pemetaan COA modul Pencairan Pembiayaan (ZERO-HARDCODING) ────
        // Semua bernilai null = ikuti pemetaan standar App\Support\Coa.
        // Pengurus dapat mengarahkan ke akun lain tanpa mengubah kode.
        'account_kas_teller_id' => null,
        'account_kas_brankas_id' => null,
        'account_bank_koperasi_id' => null,
        'account_piutang_murabahah_id' => null,
        'account_piutang_mudharabah_id' => null,
        'account_piutang_musyarakah_id' => null,
        'account_piutang_ijarah_id' => null,
        'account_piutang_qardh_id' => null,
        'account_investasi_mudharabah_id' => null,
        'account_investasi_musyarakah_id' => null,
        'account_margin_deferred_id' => null,
        'account_simpanan_sukarela_id' => null,
        // ── Pemetaan COA Dana Titipan PSAK 109 (ZERO-HARDCODING) ─────────
        // Kosong = ikuti registri standar App\Support\Coa (2-300x),
        // sehingga membiarkannya kosong TIDAK mengubah perilaku sistem.
        'account_dana_zakat_id' => null,
        'account_dana_infak_id' => null,
        'account_dana_kebajikan_id' => null,
        'account_dana_sosial_id' => null,
        'account_titipan_selisih_id' => null,
        // ── Parameter hisab Zakat (PSAK 109 & syariah) — ZERO-HARDCODING ──
        // Kadar zakat maal standar 2,5% (1/40) dan nishab 85 gram emas.
        // Nilai-nilai ini dapat diubah Pengurus tanpa mengubah kode inti.
        // Default = ketentuan syariah yang berlaku umum, sehingga membiarkan
        // kosong TIDAK mengubah hasil perhitungan yang benar secara syariah.
        'zakat_rate' => 2.50,
        'zakat_nisab_gram' => 85.00,
        'harga_emas_per_gram' => 1500000.00,
        'zakat_minimal_tarik' => 10000.00,
        // ── Syarat HAUL zakat maal (kepemilikan penuh 1 tahun hijriah) ───
        // Syarat wajib zakat maal = nishab DAN haul. Saklar `zakat_haul_aktif`
        // default NONAKTIF (P4 Default Aman): membiarkannya kosong menjaga
        // perilaku lama (hisab berbasis nishab saja) sehingga tidak mengubah
        // hasil perhitungan yang sudah berjalan. Bila Pengurus mengaktifkan,
        // `hitungZakat()` menuntut tanggal kepemilikan dan menolak status
        // "wajib" sampai haul (default 354 hari hijriah) terpenuhi.
        'zakat_haul_aktif' => false,
        'zakat_haul_hari' => 354,
        // ── Porsi/hak AMIL (pengelola) atas dana zakat (Fatwa DSN-MUI 08) ─
        // Amil termasuk salah satu 8 asnaf dan berhak atas bagian dana zakat;
        // batas umum maksimum 1/8 (12,5%). Parameter ini dipakai MENGHISAB hak
        // amil pada laporan sumber & penggunaan dana; realisasi penyaluran
        // tetap dicatat manual Pengurus (asnaf `amil`). Nilai 0 = tidak dihitung.
        'zakat_porsi_amil_persen' => 12.50,
        // ── Ambang DUAL CONTROL penyaluran dana amanah (Z-02) ────────────
        // Nominal penyaluran (Rp) yang mewajibkan persetujuan pengurus/manajer
        // LAIN sebelum jurnal diposting (maker-checker). Default 0 = dual
        // control NONAKTIF (P4 Default Aman) sehingga perilaku lama tidak
        // berubah: penyaluran di bawah/di-atas ambang tetap auto-post.
        'zakat_ambang_persetujuan' => 0,
        // ── Penjadwalan angsuran & kalender hari libur ──────────────────
        // Saklar penyesuaian tanggal jatuh tempo yang jatuh pada hari libur.
        'hari_libur_aktif' => true,
        // Strategi penggeseran: MAJU = hari kerja berikutnya, MUNDUR = sebelumnya.
        'hari_libur_strategi' => 'MAJU',
        // Tanggal jatuh tempo angsuran mengikuti tanggal berapa dalam bulan (1-28).
        'tanggal_jatuh_tempo_default' => 1,
        // ── Integritas periode (TAHAP 5) ────────────────────────────────
        // Cut-off tutup buku (YYYY-MM-DD). Jurnal bertanggal <= cut-off ditolak.
        // NULL = periode masih terbuka (belum pernah tutup buku).
        'tutup_buku_sampai' => null,
        // ── Tahun Buku Dinamis (PSAK 101 ¶23 / fleksibilitas periode) ────
        // Bulan pertama tahun buku (1 = Januari s.d. 12 = Desember).
        // Koperasi dapat memakai tahun buku non-kalender (mis. Juli–Juni).
        'tahun_buku_mulai_bulan' => 1,
        // Jumlah bulan dalam satu tahun buku (12 = setahun penuh).
        'jumlah_bulan_tahun_buku' => 12,
        // Mode cut-off periode saat nilai kebijakan tidak dapat diurai:
        // fail_closed = tolak posting (aman), fail_open = izinkan posting.
        'periode_cutoff_mode' => 'fail_open',
        // ── Klasifikasi Akad & Partisipasi SHU (PSAK 107/108/109) ───────
        // Daftar akad per kategori imbalan. Dipisah koma; dibandingkan
        // case-insensitive. Dapat diubah pengurus tanpa menyentuh kode
        // bila koperasi memakai akad lain (mis. Musawamah, Qardhul Hasan).
        'akad_margin' => 'Murabahah,Ijarah Muntahia Bittamlik,Istishna,Salam',
        'akad_bagi_hasil' => 'Mudharabah,Musyarakah',
        'akad_ujroh' => 'Ijarah',
        // Jenis transaksi yang dianggap sebagai PARTISIPASI usaha anggota
        // (basis Jasa Transaksi / Jasa Usaha pada perhitungan SHU).
        // Nilai default diselaraskan dengan registri tunggal
        // App\Support\JenisTransaksi (kanonik + alias) — lihat juga
        // jenisPartisipasiShu(). Konstanta array tidak dapat memanggil helper,
        // sehingga nilai di bawah adalah cerminan literal dari registri.
        'shu_jenis_partisipasi' => 'Pembayaran Angsuran,Bayar Angsuran,Angsuran,Bagi Hasil Simpanan Berjangka,Bagi Hasil',
        // ── Modul Member Exit & Closure (penutupan simpanan) ─────────────
        // P4 Default Aman: 0 = belum ada tarif / approval manajer nonaktif.
        'closure_admin_fee' => 0.00,
        'high_value_withdrawal_limit' => 0.00,
        // ── Retensi cadangan basis data (pemeliharaan sistem) ────────────
        // Umur maksimum berkas cadangan sebelum dihapus scheduler.
        // Default 14 hari = perilaku lama (dulu ditulis keras di
        // routes/console.php sebagai `--simpan-hari=14`).
        'backup_retensi_hari' => 14,
    ];

    /**
     * Tipe data (casting) tiap parameter kebijakan.
     *
     * Mendukung: string | integer | decimal | boolean | json. Nilai ini
     * menjadi metadata resmi (bukan sekadar disimpulkan dari DEFAULTS) dan
     * dipakai untuk casting otomatis saat memuat nilai dari tabel
     * `kebijakan_koperasis` maupun saat menampilkan editor di UI.
     *
     * @var array<string, string>
     */
    public const TIPE = [
        'nama_koperasi' => 'string',
        'prefix_nomor_transaksi' => 'string',
        'prefix_nomor_rekening' => 'string',
        'prefix_nomor_jurnal' => 'string',
        'prefix_nomor_pengeluaran_beban' => 'string',
        'prefix_nomor_terminasi' => 'string',
        'prefix_nomor_deposito' => 'string',
        'limit_manajer' => 'decimal',
        'limit_pembiayaan_manajer' => 'decimal',
        'limit_penarikan_teller' => 'decimal',
        'limit_setoran_teller' => 'decimal',
        'teller_max_cash_limit' => 'decimal',
        'minimum_transaksi_teller' => 'decimal',
        'mandatory_principal_savings' => 'decimal',
        'mandatory_monthly_savings' => 'decimal',
        'minimum_pembukaan_rekening' => 'decimal',
        'maksimum_pembukaan_rekening' => 'decimal',
        'shu_hold_on_overdue' => 'boolean',
        'simpanan_wajib_kanal_default' => 'string',
        'simpanan_wajib_boleh_ditarik' => 'boolean',
        'simpanan_wajib_hari_penarikan' => 'string',
        'account_simpanan_wajib_id' => 'integer',
        'simpanan_wajib_auto_debit_aktif' => 'boolean',
        'simpanan_wajib_penanganan_dinamis' => 'boolean',
        'simpanan_wajib_maks_cicilan' => 'integer',
        'simpanan_wajib_reschedule_bulan' => 'integer',
        'simpanan_wajib_maks_reschedule' => 'integer',
        'simpanan_wajib_notif_anonim' => 'boolean',
        'simpanan_wajib_ambang_kurang_ringan' => 'decimal',
        'autodebit_wajib_konfirmasi' => 'boolean',
        'jam_posting_simpanan_wajib' => 'string',
        'shu_pct_cadangan_modal' => 'decimal',
        'shu_pct_jasa_anggota' => 'decimal',
        'shu_pct_jasa_modal' => 'decimal',
        'shu_pct_dana_pendidikan' => 'decimal',
        'metode_perhitungan_shu' => 'string',
        'limit_approval_beban' => 'decimal',
        'minimal_pengajuan_pembiayaan' => 'decimal',
        'kolektibilitas_kol1_hari' => 'integer',
        'kolektibilitas_kol2_hari' => 'integer',
        'kolektibilitas_kol3_hari' => 'integer',
        'kolektibilitas_kol4_hari' => 'integer',
        'tazir_mode' => 'string',
        'tazir_rate' => 'decimal',
        'financing_late_fee_per_day' => 'decimal',
        'tazir_grace_period_days' => 'integer',
        'tazir_default_method' => 'string',
        'tazir_default_nominal' => 'decimal',
        'akun_tazir_id' => 'integer',
        'deposito_rate_pa' => 'decimal',
        'nisbah_default_persen' => 'decimal',
        'margin_default_persen' => 'decimal',
        'aml_threshold' => 'decimal',
        'ltkt_threshold' => 'decimal',
        'ppatk_jenis_tunai' => 'string',
        'ppatk_nama_pelapor' => 'string',
        'ppatk_kode_pelapor' => 'string',
        'npwp_koperasi' => 'string',
        'pembulatan_desimal' => 'integer',
        'toleransi_selisih_jurnal' => 'decimal',
        'toleransi_persen' => 'decimal',
        'posting_wajib_transaksi_sukses' => 'boolean',
        'rekonsiliasi_inline_aktif' => 'boolean',
        'ckpn_persen_kol1' => 'decimal',
        'ckpn_persen_kol2' => 'decimal',
        'ckpn_persen_kol3' => 'decimal',
        'ckpn_persen_kol4' => 'decimal',
        'ckpn_persen_kol5' => 'decimal',
        'account_kas_teller_id' => 'integer',
        'account_kas_brankas_id' => 'integer',
        'account_bank_koperasi_id' => 'integer',
        'account_piutang_murabahah_id' => 'integer',
        'account_piutang_mudharabah_id' => 'integer',
        'account_piutang_musyarakah_id' => 'integer',
        'account_piutang_ijarah_id' => 'integer',
        'account_piutang_qardh_id' => 'integer',
        'account_investasi_mudharabah_id' => 'integer',
        'account_investasi_musyarakah_id' => 'integer',
        'account_margin_deferred_id' => 'integer',
        'account_simpanan_sukarela_id' => 'integer',
        'account_dana_zakat_id' => 'integer',
        'account_dana_infak_id' => 'integer',
        'account_dana_kebajikan_id' => 'integer',
        'account_dana_sosial_id' => 'integer',
        'account_titipan_selisih_id' => 'integer',
        'zakat_rate' => 'decimal',
        'zakat_nisab_gram' => 'decimal',
        'harga_emas_per_gram' => 'decimal',
        'zakat_minimal_tarik' => 'decimal',
        'zakat_haul_aktif' => 'boolean',
        'zakat_haul_hari' => 'integer',
        'zakat_porsi_amil_persen' => 'decimal',
        'zakat_ambang_persetujuan' => 'decimal',
        'hari_libur_aktif' => 'boolean',
        'hari_libur_strategi' => 'string',
        'tanggal_jatuh_tempo_default' => 'integer',
        'tutup_buku_sampai' => 'string',
        'tahun_buku_mulai_bulan' => 'integer',
        'jumlah_bulan_tahun_buku' => 'integer',
        'periode_cutoff_mode' => 'string',
        'akad_margin' => 'string',
        'akad_bagi_hasil' => 'string',
        'akad_ujroh' => 'string',
        'shu_jenis_partisipasi' => 'string',
        'closure_admin_fee' => 'decimal',
        'high_value_withdrawal_limit' => 'decimal',
        'backup_retensi_hari' => 'integer',
    ];

    /**
     * Deskripsi singkat fungsi tiap parameter kebijakan.
     *
     * @var array<string, string>
     */
    public const DESKRIPSI = [
        'prefix_nomor_transaksi' => 'Awalan nomor transaksi, mis. TRX (hasil: TRX-20261021-000001).',
        'prefix_nomor_rekening' => 'Awalan nomor rekening, mis. RKN (hasil: RKN-2026-000001).',
        'prefix_nomor_jurnal' => 'Awalan nomor jurnal, mis. JRN (hasil: JRN-20261021-0001).',
        'prefix_nomor_pengeluaran_beban' => 'Awalan bukti kas/bank keluar, mis. BBK (hasil: BBK-20261021-0001).',
        'prefix_nomor_terminasi' => 'Awalan nomor terminasi anggota, mis. TRM (hasil: TRM-20261021-0001).',
        'prefix_nomor_deposito' => 'Awalan nomor bilyet deposito, mis. DEP (hasil: DEP-20261021-0001).',
        'teller_max_cash_limit' => 'Batas maksimum kas fisik yang boleh mengendap di laci teller (Rp).',
        'minimum_transaksi_teller' => 'Nominal minimum transaksi tunai di teller, mis. penarikan/setoran (Rp).',
        'ltkt_threshold' => 'Ambang transaksi TUNAI wajib lapor PPATK / LTKT (Rp). Default Rp 500 juta, terpisah dari ambang transaksi mencurigakan.',
        'ppatk_jenis_tunai' => 'Daftar jenis transaksi yang dikategorikan TUNAI untuk pelaporan LTKT PPATK (pisahkan dengan koma).',
        'ppatk_nama_pelapor' => 'Nama koperasi sebagai pihak pelapor PPATK (kop laporan). Kosong = pakai nama koperasi.',
        'ppatk_kode_pelapor' => 'Kode pelapor/ID goAML koperasi pada aplikasi PPATK.',
        'npwp_koperasi' => 'NPWP koperasi — bahan identitas pelapor pada berkas PPATK.',
        'mandatory_principal_savings' => 'Nominal Simpanan Pokok yang wajib dibayar saat pembukaan CIF (setoran awal).',
        'mandatory_monthly_savings' => 'Nominal Simpanan Wajib yang ditagihkan setiap bulan kepada anggota aktif.',
        'shu_hold_on_overdue' => 'Tahan pencairan SHU anggota yang masih menunggak tagihan Simpanan Wajib.',
        'simpanan_wajib_kanal_default' => 'Kanal pembayaran Simpanan Wajib default bagi anggota baru: AUTO_DEBIT, CASH, atau TRANSFER.',
        'simpanan_wajib_boleh_ditarik' => 'Izinkan penarikan saldo Simpanan Wajib anggota. Nonaktif = saldo terkunci sebagai modal anggota.',
        'simpanan_wajib_hari_penarikan' => 'Waktu penarikan Simpanan Wajib diizinkan: SETELAH_KELUAR (hanya saat anggota keluar) atau KAPAN_SAJA.',
        'account_simpanan_wajib_id' => 'Akun COA kanonik Simpanan Wajib (PSAK 101). Kosong = ikuti master jenis simpanan / standar 3-1002.',
        'simpanan_wajib_auto_debit_aktif' => 'Saklar induk modul Auto-Debet Simpanan Wajib. Nonaktif = sistem tidak pernah memotong saldo Sukarela; tagihan dibayar manual di teller.',
        'simpanan_wajib_penanganan_dinamis' => 'Aktifkan penanganan dinamis (jadwal bertahap & reschedule tanpa denda) saat saldo Sukarela kurang/kosong. Nonaktif = hanya tandai OVERDUE + notifikasi.',
        'simpanan_wajib_maks_cicilan' => 'Maksimum jumlah cicilan pada penjadwalan pembayaran bertahap. 0 = tanpa batas.',
        'simpanan_wajib_reschedule_bulan' => 'Jumlah bulan perpanjangan tenggat setiap satu pengajuan penjadwalan ulang (reschedule).',
        'simpanan_wajib_maks_reschedule' => 'Maksimum jumlah penjadwalan ulang per satu tagihan. 0 = tanpa batas.',
        'simpanan_wajib_notif_anonim' => 'Anonimkan identitas anggota (nama/no_anggota) pada notifikasi (PDP / UU 27/2022). Nonaktif = pesan kanal internal memuat identitas lengkap.',
        'simpanan_wajib_ambang_kurang_ringan' => 'Ambang kekurangan (Rp) yang masih dianggap ringan; di bawah ambang ini sistem memprioritaskan reschedule ringan.',
        'autodebit_wajib_konfirmasi' => 'Kebijakan induk: bila AKTIF, tidak ada auto-debit (Simpanan Wajib, Angsuran, Ta\'zir) yang boleh memotong saldo tanpa konfirmasi pengguna berwenang. Scheduler hanya membuat usulan di Pusat Konfirmasi.',
        'tazir_default_nominal' => "Nominal default Ta'zir (denda keterlambatan angsuran) dalam Rp.",
        'toleransi_persen' => 'Ambang penyimpangan total nisbah anggota + koperasi dari 100% (persen) yang masih dianggap sah pada validasi produk. Default 0,01.',
        'financing_late_fee_per_day' => "Tarif denda keterlambatan angsuran pembiayaan per hari (Rp). Nilai 0 berarti fitur denda NONAKTIF — ta'zir tidak dihitung dan tidak diposting ke Dana Kebajikan.",
        'account_kas_teller_id' => 'Akun COA Kas Teller sebagai sumber dana pencairan TUNAI (CASH).',
        'account_kas_brankas_id' => 'Akun COA Kas Brankas / Vault.',
        'account_bank_koperasi_id' => 'Akun COA Bank operasional utama koperasi.',
        'account_piutang_murabahah_id' => 'Akun COA Piutang Murabahah (debet saat pencairan akad Murabahah).',
        'account_piutang_mudharabah_id' => 'Akun COA Piutang/Penyertaan Mudharabah.',
        'account_piutang_musyarakah_id' => 'Akun COA Piutang/Penyertaan Musyarakah.',
        'account_piutang_ijarah_id' => 'Akun COA Piutang Ijarah.',
        'account_piutang_qardh_id' => 'Akun COA Pinjaman Qardh (tanpa margin).',
        'account_investasi_mudharabah_id' => 'Akun COA Investasi Mudharabah — dipakai bila koperasi menyajikan skema investasi, bukan piutang.',
        'account_investasi_musyarakah_id' => 'Akun COA Investasi Musyarakah — dipakai bila koperasi menyajikan skema investasi, bukan piutang.',
        'account_margin_deferred_id' => 'Akun COA Margin Ditangguhkan (Unearned Margin) untuk akad Murabahah sesuai PSAK 108.',
        'account_simpanan_sukarela_id' => 'Akun COA Simpanan Sukarela — tujuan pindah buku pencairan non-tunai.',
        'account_dana_zakat_id' => 'Akun COA Dana Zakat (PSAK 109). Kosong = ikuti standar 2-3002.',
        'account_dana_infak_id' => 'Akun COA Dana Infak & Sedekah (PSAK 109). Kosong = ikuti standar 2-3004.',
        'account_dana_kebajikan_id' => 'Akun COA Dana Kebajikan (PSAK 109). Kosong = ikuti standar 2-3001.',
        'account_dana_sosial_id' => 'Akun COA Dana Sosial (PSAK 109). Kosong = ikuti standar 2-3003.',
        'account_titipan_selisih_id' => 'Akun COA Titipan Selisih Kas / kewajiban lain-lain. Kosong = ikuti standar 2-3099.',
        'zakat_rate' => 'Kadar zakat maal dalam persen (standar syariah 2,5% = 1/40) sebagai bagian dari hisab wajib zakat.',
        'zakat_nisab_gram' => 'Ambang nishab zakat dalam gram emas (standar 85 gram). Harta di bawah nishab tidak wajib dizakati.',
        'harga_emas_per_gram' => 'Harga emas per gram (Rp) untuk mengubah nishab gram menjadi nishab nominal rupiah pada hisab zakat.',
        'zakat_minimal_tarik' => 'Nominal minimum penyaluran dana zakat/infak per transaksi (Rp) guna mencegah penyaluran bernilai sangat kecil. Nilai 0 = tanpa batas minimum.',
        'zakat_haul_aktif' => 'Aktifkan penegakan syarat HAUL (kepemilikan penuh 1 tahun hijriah) pada hisab zakat maal. Nonaktif = hisab hanya memeriksa nishab.',
        'zakat_haul_hari' => 'Panjang satu haul dalam hari (standar 354 hari hijriah). Nilai ini menentukan kapan harta yang mencapai nishab mulai wajib dizakati.',
        'zakat_porsi_amil_persen' => 'Porsi hak amil (pengelola zakat) atas dana zakat dalam persen — batas umum maksimum 1/8 (12,5%) sesuai Fatwa DSN-MUI 08. Dipakai merekap hak amil pada laporan; nilai 0 = tidak dihitung.',
        'zakat_ambang_persetujuan' => 'Ambang nominal penyaluran dana zakat/infak (Rp) yang mewajibkan PERSETUJUAN pengurus/manajer lain sebelum jurnal diposting (dual control maker-checker). Nilai 0 = dual control nonaktif.',
        'hari_libur_aktif' => 'Aktifkan penyesuaian tanggal jatuh tempo angsuran yang jatuh pada hari libur / akhir pekan.',
        'hari_libur_strategi' => 'Strategi penggeseran tanggal jatuh tempo ke hari kerja: MAJU (hari kerja berikutnya) atau MUNDUR (sebelumnya).',
        'tanggal_jatuh_tempo_default' => 'Tanggal jatuh tempo angsuran pertama dalam sebulan (1-28), dipakai saat menghitung first due date.',
        'tutup_buku_sampai' => 'Cut-off periode tutup buku (YYYY-MM-DD). Jurnal bertanggal sebelum atau sama dengan tanggal ini ditolak. Kosongkan bila periode masih terbuka.',
        'tahun_buku_mulai_bulan' => 'Bulan pertama tahun buku koperasi (1-12). Default 1 = Januari. Ubah bila koperasi memakai tahun buku non-kalender, mis. 7 = Juli.',
        'jumlah_bulan_tahun_buku' => 'Jumlah bulan dalam satu tahun buku (1-12). Default 12 = setahun penuh.',
        'periode_cutoff_mode' => 'Perilaku saat cut-off periode tidak dapat diurai: fail_closed (tolak posting, aman) atau fail_open (izinkan posting).',
        'jam_posting_simpanan_wajib' => 'Jam posting transaksi auto-debit Simpanan Wajib (format HH:MM, 24 jam). Dipakai bersama tanggal jatuh tempo sebagai waktu transaksi & waktu pelunasan tagihan agar keduanya selalu identik.',
        'akad_margin' => 'Daftar akad jual-beli yang imbalannya diakui sebagai MARGIN (pisahkan dengan koma). Default: Murabahah, Ijarah Muntahia Bittamlik, Istishna, Salam.',
        'akad_bagi_hasil' => 'Daftar akad kerja sama usaha yang imbalannya diakui sebagai BAGI HASIL (pisahkan dengan koma). Default: Mudharabah, Musyarakah.',
        'akad_ujroh' => 'Daftar akad sewa/jasa yang imbalannya diakui sebagai UJROH, bukan margin (pisahkan dengan koma). Default: Ijarah.',
        'shu_jenis_partisipasi' => 'Daftar jenis transaksi yang dihitung sebagai partisipasi usaha anggota pada pembagian SHU / Jasa Transaksi (pisahkan dengan koma).',
        'posting_wajib_transaksi_sukses' => 'Gate status keberhasilan: jurnal hanya boleh dicatat bila transaksi terkait berstatus SUKSES (Sukses/success). Cegah transaksi gagal/pending masuk buku besar. Jurnal manual tanpa transaksi tetap diizinkan.',
        'rekonsiliasi_inline_aktif' => 'Rekonsiliasi otomatis inline: verifikasi ulang keseimbangan & integritas buku besar SETIAP KALI transaksi diposting; bila cacat, posting dibatalkan (rollback).',
        'kolektibilitas_kol1_hari' => 'Batas hari tunggakan maksimum pembiayaan Lancar (Kol-1). Di atas nilai ini piutang masuk Kol-2 (Dalam Perhatian Khusus). Pola KSP syariah: 10 hari.',
        'kolektibilitas_kol2_hari' => 'Batas hari tunggakan maksimum pembiayaan Dalam Perhatian Khusus (Kol-2). Pola KSP syariah: 90 hari.',
        'kolektibilitas_kol3_hari' => 'Batas hari tunggakan maksimum pembiayaan Kurang Lancar (Kol-3). Pola KSP syariah: 120 hari.',
        'kolektibilitas_kol4_hari' => 'Batas hari tunggakan maksimum pembiayaan Diragukan (Kol-4). Di atas nilai ini piutang masuk Kol-5 (Macet). Pola KSP syariah: 180 hari.',
        'ckpn_persen_kol1' => 'Persentase cadangan CKPN untuk pembiayaan Lancar (Kol-1). Pola KSP syariah: 0,5%.',
        'ckpn_persen_kol2' => 'Persentase cadangan CKPN untuk pembiayaan Dalam Perhatian Khusus (Kol-2). Pola KSP syariah: 10%.',
        'ckpn_persen_kol3' => 'Persentase cadangan CKPN untuk pembiayaan Kurang Lancar (Kol-3). Pola KSP syariah: 50%.',
        'ckpn_persen_kol4' => 'Persentase cadangan CKPN untuk pembiayaan Diragukan (Kol-4). Pola KSP syariah: 75%.',
        'ckpn_persen_kol5' => 'Persentase cadangan CKPN untuk pembiayaan Macet (Kol-5). Pola KSP syariah: 100%.',
        'metode_perhitungan_shu' => 'Metode perhitungan partisipasi SHU anggota. Satu-satunya metode yang didukung: SRH (Saldo Rata-rata Harian).',
        'closure_admin_fee' => 'Biaya administrasi penutupan CIF yang dipotong dari dana exit anggota (Rp). 0 = tidak ada biaya.',
        'high_value_withdrawal_limit' => 'Ambang total net pencairan exit yang wajib persetujuan Manajer sebelum dana dibayarkan (Rp). 0 = approval nonaktif.',
        'backup_retensi_hari' => 'Umur maksimum berkas cadangan basis data (hari) sebelum dihapus otomatis oleh scheduler. Default 14 hari.',
    ];

    /**
     * Tipe data resmi sebuah parameter kebijakan.
     *
     * @return string salah satu: string|integer|decimal|boolean|json
     */
    public static function tipe(string $key): string
    {
        return static::TIPE[$key] ?? 'string';
    }

    /** Deskripsi fungsi sebuah parameter kebijakan. */
    public static function deskripsi(string $key): string
    {
        return static::DESKRIPSI[$key] ?? '';
    }

    /**
     * Jumlah angka desimal resmi sebuah parameter (default 2).
     *
     * Dipakai agar atribut `step` pada form kebijakan selalu sama dengan
     * presisi yang dipakai `cast()` — tidak ada lagi angka `step` yang
     * ditulis manual dan menyimpang dari metadata (DYN-08).
     */
    public static function presisiDesimal(string $key): int
    {
        return self::PRESISI_DESIMAL[$key] ?? 2;
    }

    /**
     * Nilai atribut `step` HTML untuk input numerik sebuah parameter.
     *
     * `integer` → 1; `decimal` → 0,0…1 sesuai presisi metadata.
     */
    public static function langkah(string $key): string
    {
        if (static::tipe($key) === 'integer') {
            return '1';
        }

        $presisi = static::presisiDesimal($key);

        return $presisi > 0
            ? '0.'.str_repeat('0', $presisi - 1).'1'
            : '1';
    }

    /**
     * Ambil nilai parameter kebijakan.
     *
     * Pembacaan pertama dalam 5 menit akan menyentuh database; setelahnya
     * dilayani dari cache. Nilai default dipakai bila kolom tidak tersedia
     * (mis. saat migrasi baru belum dijalankan).
     *
     * @param  string  $key  Nama kolom pada `kebijakan_koperasis`.
     * @param  mixed  $default  Pengganti bila key tidak dikenal.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $semua = static::all();

        if (array_key_exists($key, $semua) && $semua[$key] !== null) {
            return $semua[$key];
        }

        return $default ?? (static::DEFAULTS[$key] ?? null);
    }

    /**
     * Seluruh parameter kebijakan sebagai array asosiatif (ter-cache 5 menit).
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return Cache::remember(static::CACHE_KEY, static::CACHE_TTL, function (): array {
            $nilai = static::DEFAULTS;

            try {
                $kebijakan = KebijakanKoperasi::query()->first();
            } catch (\Throwable $e) {
                // Tabel belum tersedia (mis. sebelum migrasi) → pakai default.
                // Tetap dicatat: penyebab lain (mis. koneksi/hak akses) tidak
                // boleh hilang diam-diam karena seluruh parameter kebijakan
                // akan senyap kembali ke default.
                Log::warning('Setting: gagal membaca kebijakan koperasi, memakai nilai default.', [
                    'error' => $e->getMessage(),
                ]);

                return $nilai;
            }

            if (! $kebijakan) {
                return $nilai;
            }

            $atribut = $kebijakan->getAttributes();

            foreach ($nilai as $key => $default) {
                if (array_key_exists($key, $atribut) && $atribut[$key] !== null) {
                    $nilai[$key] = static::cast($key, $atribut[$key], $default);
                }
            }

            return $nilai;
        });
    }

    /**
     * Nilai nominal (rupiah) sebagai float — dipakai perhitungan keuangan.
     */
    public static function nominal(string $key, float $default = 0.0): float
    {
        return round((float) static::get($key, $default), 2);
    }

    /**
     * Nilai boolean (mis. shu_hold_on_overdue).
     */
    public static function bool(string $key, bool $default = false): bool
    {
        return filter_var(static::get($key, $default), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Nilai persentase (0-100) — dijaga agar tidak keluar rentang yang sah.
     */
    public static function persen(string $key, float $default = 0.0): float
    {
        return max(0.0, min(100.0, round((float) static::get($key, $default), 2)));
    }

    /** Nominal Simpanan Pokok (sekali bayar saat registrasi). */
    public static function simpananPokok(): float
    {
        return static::nominal('mandatory_principal_savings', 100000.00);
    }

    /** Nominal Simpanan Wajib per bulan. */
    public static function simpananWajibBulanan(): float
    {
        return static::nominal('mandatory_monthly_savings', 50000.00);
    }

    /** Total setoran awal bundling = Pokok + Wajib bulan ke-1. */
    public static function totalSetoranAwal(): float
    {
        return round(static::simpananPokok() + static::simpananWajibBulanan(), 2);
    }

    /* ── Parameter dinamis Simpanan Wajib (Tahap 12 / R1) ──────────────── */

    /**
     * Kanal pembayaran Simpanan Wajib default untuk anggota baru.
     *
     * Nilai tak dikenal dinormalkan menjadi 'AUTO_DEBIT' (perilaku sistem
     * saat ini) agar salah ketik kebijakan tidak mengubah alur transaksi.
     *
     * @return string 'AUTO_DEBIT' | 'CASH' | 'TRANSFER'
     */
    public static function simpananWajibKanalDefault(): string
    {
        $kanal = strtoupper(trim((string) static::get('simpanan_wajib_kanal_default', 'AUTO_DEBIT')));

        return in_array($kanal, ['AUTO_DEBIT', 'CASH', 'TRANSFER'], true) ? $kanal : 'AUTO_DEBIT';
    }

    /**
     * Apakah kanal default Simpanan Wajib berupa auto-debit?
     *
     * Dipakai saat pendaftaran anggota untuk mengisi `anggota.is_auto_debit`
     * tanpa hardcode nilai di Controller.
     */
    public static function simpananWajibKanalDefaultAutoDebit(): bool
    {
        return static::simpananWajibKanalDefault() === 'AUTO_DEBIT';
    }

    /**
     * Apakah penarikan saldo Simpanan Wajib diizinkan.
     *
     * Default FALSE = saldo terkunci sebagai modal anggota (PSAK 101),
     * yaitu perilaku sistem saat ini. Fitur penarikan baru dibuka pada R3.
     */
    public static function simpananWajibBolehDitarik(): bool
    {
        return static::bool('simpanan_wajib_boleh_ditarik', false);
    }

    /**
     * Waktu penarikan Simpanan Wajib diizinkan.
     *
     * @return string 'SETELAH_KELUAR' | 'KAPAN_SAJA'
     */
    public static function simpananWajibHariPenarikan(): string
    {
        $nilai = strtoupper(trim((string) static::get('simpanan_wajib_hari_penarikan', 'SETELAH_KELUAR')));

        return in_array($nilai, ['SETELAH_KELUAR', 'KAPAN_SAJA'], true) ? $nilai : 'SETELAH_KELUAR';
    }

    /**
     * Akun COA kanonik Simpanan Wajib (PSAK 101) — sisi KEWAJIBAN/EKUITAS.
     *
     * Mengembalikan null bila Pengurus belum menetapkannya, sehingga
     * pemanggil (MandatorySavingsService) dapat meneruskan rantai resolusi
     * ke master `jenis_simpanans` lalu ke registri standar App\Support\Coa.
     */
    public static function akunSimpananWajibId(): ?int
    {
        return static::akunId('account_simpanan_wajib_id');
    }

    /* ── Penanganan dinamis tunggakan Simpanan Wajib (Tahap 12 / R4) ───── */

    /**
     * Saklar induk modul Auto-Debet Simpanan Wajib.
     *
     * FALSE = sistem TIDAK PERNAH memotong saldo Simpanan Sukarela; seluruh
     * tagihan dibayar manual di Teller. TRUE (default) = perilaku saat ini.
     */
    public static function simpananWajibAutoDebitAktif(): bool
    {
        return static::bool('simpanan_wajib_auto_debit_aktif', true);
    }

    /**
     * Saklar modul penanganan dinamis tunggakan.
     *
     * FALSE = perilaku lama (hanya tandai OVERDUE + notifikasi). TRUE
     * (default) = tambah jadwal bertahap & reschedule tanpa denda.
     */
    public static function simpananWajibPenangananDinamis(): bool
    {
        return static::bool('simpanan_wajib_penanganan_dinamis', true);
    }

    /** Maksimum cicilan jadwal bertahap. 0 = tanpa batas. */
    public static function simpananWajibMaksCicilan(): int
    {
        return max(0, (int) static::get('simpanan_wajib_maks_cicilan', 6));
    }

    /** Tambahan bulan tenggat tiap pengajuan reschedule (0-12). */
    public static function simpananWajibRescheduleBulan(): int
    {
        return max(0, min(12, (int) static::get('simpanan_wajib_reschedule_bulan', 1)));
    }

    /** Maksimum reschedule per tagihan. 0 = tanpa batas. */
    public static function simpananWajibMaksReschedule(): int
    {
        return max(0, (int) static::get('simpanan_wajib_maks_reschedule', 3));
    }

    /** Apakah identitas anggota disamarkan pada notifikasi (PDP). */
    public static function simpananWajibNotifAnonim(): bool
    {
        return static::bool('simpanan_wajib_notif_anonim', true);
    }

    /** Ambang kekurangan (Rp) yang masih dianggap ringan. */
    public static function simpananWajibAmbangKurangRingan(): float
    {
        return static::nominal('simpanan_wajib_ambang_kurang_ringan', 25000.00);
    }

    /**
     * Jam posting transaksi auto-debit Simpanan Wajib (format `HH:MM`).
     *
     * Menggantikan konstanta `MandatorySavingsService::JAM_POSTING` (DYN-17)
     * agar Pengurus dapat menggeser jam posting tanpa mengubah kode. Nilai
     * yang tidak dapat diurai sebagai jam 24 jam selalu jatuh ke `00:01`
     * sehingga transaksi tidak pernah gagal dibuat karena konfigurasi salah.
     */
    public static function jamPostingSimpananWajib(): string
    {
        $nilai = trim((string) static::get('jam_posting_simpanan_wajib', '00:01'));

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $nilai) === 1 ? $nilai : '00:01';
    }

    /**
     * Kebijakan INDUK: auto-debit wajib dikonfirmasi pengguna.
     *
     * TRUE (default) = TIDAK ADA kanal auto-debit (Simpanan Wajib, Angsuran,
     * Ta'zir) yang boleh memotong saldo secara mandiri; scheduler hanya
     * membuat usulan di Pusat Konfirmasi Auto-Debit, dan pemotongan baru
     * terjadi setelah pengguna berwenang mengonfirmasi.
     *
     * FALSE = perilaku lama (auto-debit langsung) — hanya untuk kompatibilitas.
     */
    public static function autodebitWajibKonfirmasi(): bool
    {
        return static::bool('autodebit_wajib_konfirmasi', true);
    }

    /**
     * Batas maksimum kas fisik yang boleh mengendap di laci teller.
     *
     * Dipakai modul Teller untuk mencegah penumpukan kas berlebih di loket
     * (mendorong setoran ke Brankas Utama) tanpa perlu hardcode nominal.
     */
    public static function tellerMaxCashLimit(): float
    {
        return static::nominal('teller_max_cash_limit', 25000000.00);
    }

    /** Batas maksimum penarikan tunai per transaksi di teller. */
    public static function limitPenarikanTeller(): float
    {
        return static::nominal('limit_penarikan_teller', 5000000.00);
    }

    /** Batas maksimum setoran tunai per transaksi di teller. */
    public static function limitSetoranTeller(): float
    {
        return static::nominal('limit_setoran_teller', 50000000.00);
    }

    /** Nominal minimum transaksi tunai di teller (penarikan/setoran). */
    public static function minimumTransaksiTeller(): float
    {
        return static::nominal('minimum_transaksi_teller', 1000.00);
    }

    /** Kebijakan penahanan SHU bagi anggota menunggak. */
    public static function tahanShuSaatMenunggak(): bool
    {
        return static::bool('shu_hold_on_overdue', true);
    }

    /**
     * Nominal default Ta'zir (denda keterlambatan angsuran) dalam rupiah.
     *
     * Menggantikan hardcode `?? 250000` pada TellerController::prosesTazir()
     * agar nominal ta'zir dapat diubah pengurus dari UI tanpa deploy ulang.
     */
    public static function nominalTazirDefault(): float
    {
        return static::nominal('tazir_default_nominal', 250000.00);
    }

    /**
     * Tarif denda keterlambatan angsuran pembiayaan per hari (Ta'zir / Late Fee).
     *
     * Saklar tunggal fitur denda (PSAK 108/109): nilai > 0 berarti fitur AKTIF,
     * nilai 0 berarti NONAKTIF sehingga ta'zir tidak boleh dihitung maupun
     * diposting ke Dana Kebajikan (2-3001).
     */
    public static function financingLateFeePerDay(): float
    {
        return static::nominal('financing_late_fee_per_day', 0.00);
    }

    /** Apakah fitur denda keterlambatan angsuran sedang AKTIF. */
    public static function fiturDendaPembiayaanAktif(): bool
    {
        return static::financingLateFeePerDay() > 0;
    }

    /* ── Alokasi SHU (dinamis, tanpa hardcode) ─────────────────────────── */

    /**
     * SSOT TUNGGAL persentase alokasi SHU (kebijakan, 4 pos).
     *
     * ══ KONTRAK SUMBER TUNGGAL (penyatuan temuan audit K-03) ══════════════
     *
     * Penyatuan ini menetapkan DUA TINGKAT yang tidak boleh saling
     * bertentangan:
     *
     *  1. TINGKAT KEBIJAKAN (di sini) — 4 pos kanonik yang dapat diubah
     *     Pengurus dari UI Kebijakan (`shu_pct_*`). METODE INI adalah
     *     satu-satunya sumber nilai kebijakan; tidak ada modul lain yang
     *     boleh mendefinisikan ulang persentase kebijakan.
     *
     *  2. TINGKAT PERIODE (tabel `shu_periods`) — 5 pos yang benar-benar
     *     didistribusikan per tahun buku (jalur produksi
     *     `Pengurus\ShuController`). Nilai awalnya WAJIB berasal dari satu-
     *     satunya jembatan resmi {@see self::alokasiShuDrafDefault()}, bukan
     *     dari literal terpisah.
     *
     * Konsekuensi kontrak:
     *  - `shu_periods` tetap menyimpan angka historis per tahun (tidak
     *    ditimpa kebijakan) — kebijakan hanya menentukan NILAI AWAL.
     *  - Setiap pembaca persentase kebijakan HARUS lewat metode ini
     *    (langsung, atau via {@see self::alokasiShuDrafDefault()} untuk
     *    kebutuhkan 5 pos). `KebijakanKoperasi::alokasiShu()` hanya
     *    mendelegasikan ke sini (@deprecated K-03).
     *
     * @return array<string, float> kunci alokasi => persentase (0-100)
     */
    public static function alokasiShu(): array
    {
        return [
            'cadangan_modal' => static::nominal('shu_pct_cadangan_modal', 30.00),
            'jasa_anggota' => static::nominal('shu_pct_jasa_anggota', 40.00),
            'jasa_modal' => static::nominal('shu_pct_jasa_modal', 20.00),
            'dana_pendidikan' => static::nominal('shu_pct_dana_pendidikan', 10.00),
        ];
    }

    /**
     * JEMBATAN TUNGGAL 4 pos kebijakan → 5 pos produksi (`shu_periods`).
     *
     * Ini satu-satunya pemetaan resmi antara tingkat KEBIJAKAN (4 pos dari
     * {@see self::alokasiShu()}) dan tingkat PERIODE (5 pos yang
     * didistribusikan). Semua konsumen yang butuh nilai awal 5 pos —
     * `Pengurus\ShuController::store()`, `Dashboard\PengurusController`, dan
     * view `pengurus.shu.index` — WAJIB memakai metode ini agar tidak ada
     * definisi kedua yang menyimpang.
     *
     * Pemetaan pos:
     *
     *   cadangan_modal  → persen_cadangan
     *   jasa_modal      → persen_jasa_modal
     *   jasa_anggota    → persen_jasa_transaksi
     *   dana_pendidikan → persen_sosial
     *   sisa (menuju 100) → persen_pengurus
     *
     * Sisa penyeimbang selalu ≥ 0 sehingga total kelima pos TEPAT 100.
     *
     * @return array<string, float> persen_cadangan, persen_jasa_modal,
     *                              persen_jasa_transaksi, persen_pengurus,
     *                              persen_sosial
     */
    public static function alokasiShuDrafDefault(): array
    {
        $kebijakan = static::alokasiShu();

        $cadangan = round((float) $kebijakan['cadangan_modal'], 2);
        $jasaModal = round((float) $kebijakan['jasa_modal'], 2);
        $jasaTrans = round((float) $kebijakan['jasa_anggota'], 2);
        $sosial = round((float) $kebijakan['dana_pendidikan'], 2);

        $pengurus = round(100.0 - ($cadangan + $jasaModal + $jasaTrans + $sosial), 2);
        if ($pengurus < 0.0) {
            $pengurus = 0.0;
        }

        return [
            'persen_cadangan' => $cadangan,
            'persen_jasa_modal' => $jasaModal,
            'persen_jasa_transaksi' => $jasaTrans,
            'persen_pengurus' => $pengurus,
            'persen_sosial' => $sosial,
        ];
    }

    /** Total persentase alokasi SHU (diharapkan tepat 100). */
    public static function totalPersenAlokasiShu(): float
    {
        return round(array_sum(static::alokasiShu()), 2);
    }

    /**
     * Metode perhitungan partisipasi SHU anggota.
     *
     * Satu-satunya metode yang didukung sistem adalah SRH (Saldo Rata-rata
     * Harian). Nilai parameter yang tidak dikenal akan dinormalkan ke 'SRH'
     * agar perhitungan SHU selalu memakai basis yang sah.
     */
    public static function metodePerhitunganShu(): string
    {
        // Nilai tak dikenal pun dinormalkan ke 'SRH' — satu-satunya metode
        // yang sah, sehingga perhitungan SHU tidak pernah memakai basis lain.
        return 'SRH';
    }

    // =====================================================================
    // ZAKAT — parameter hisab yang dapat dikonfigurasi (PSAK 109 & syariah)
    // =====================================================================

    /** Kadar zakat maal dalam persen (standar syariah: 2,5% = 1/40). */
    public static function zakatRate(): float
    {
        return static::nominal('zakat_rate', 2.50);
    }

    /** Nishab zakat dalam gram emas (standar syariah: 85 gram). */
    public static function zakatNisabGram(): float
    {
        return static::nominal('zakat_nisab_gram', 85.00);
    }

    /** Harga emas per gram (Rp) — dipakai menghitung nishab nominal. */
    public static function hargaEmasPerGram(): float
    {
        return static::nominal('harga_emas_per_gram', 1500000.00);
    }

    /** Nominal nishab zakat (Rp) = gram nishab × harga emas per gram. */
    public static function zakatNisabNominal(): float
    {
        return round(static::zakatNisabGram() * static::hargaEmasPerGram(), 2);
    }

    /**
     * Nominal minimum penyaluran dana zakat/infak per satu transaksi (Rp).
     *
     * Kebijakan operasional untuk mencegah penyaluran bernilai sangat kecil
     * (mis. Rp 1) yang menyulitkan rekonsiliasi. Nilai 0 = tanpa batas minimum.
     * Dibaca dari `zakat_minimal_tarik` agar dapat diubah Pengurus tanpa
     * mengubah kode (nol hardcoding).
     */
    public static function zakatMinimalTarik(): float
    {
        return max(0.0, static::nominal('zakat_minimal_tarik', 10000.00));
    }

    /**
     * Saklar penegakan syarat HAUL pada hisab zakat maal.
     *
     * HAUL = kepemilikan penuh harta selama satu tahun hijriah. Bila aktif,
     * harta yang telah mencapai nishab TETAP belum wajib zakat sampai haul
     * terpenuhi. Default NONAKTIF (P4 Default Aman) agar hisab lama berbasis
     * nishab tidak berubah sampai Pengurus mengaktifkannya lewat Kebijakan.
     */
    public static function zakatHaulAktif(): bool
    {
        return static::bool('zakat_haul_aktif', false);
    }

    /**
     * Panjang satu haul dalam hari (standar 354 hari hijriah).
     *
     * Dapat dikonfigurasi (mis. 355 hari) agar koperasi menyesuaikan metode
     * hisabnya tanpa mengubah kode. Minimal 1 hari.
     */
    public static function zakatHaulHari(): int
    {
        return max(1, (int) static::get('zakat_haul_hari', 354));
    }

    /**
     * Porsi/hak AMIL (pengelola zakat) atas dana zakat, dalam persen.
     *
     * Fatwa DSN-MUI 08: amil termasuk salah satu dari 8 asnaf dan berhak
     * atas bagian dana zakat (umumnya maksimum 1/8 = 12,5%). Nilai dibaca
     * dari `zakat_porsi_amil_persen` (nol hardcoding) dan dijepit pada
     * rentang 0–100 agar tidak menghasilkan porsi yang tak masuk akal.
     */
    public static function zakatPorsiAmilPersen(): float
    {
        return min(100.0, max(0.0, static::nominal('zakat_porsi_amil_persen', 12.50)));
    }

    /**
     * Hisab hak amil (Rp) atas dasar penerimaan dana zakat.
     *
     * Hanya menghitung besaran hak (entitlement) untuk keperluan rekap
     * laporan; penyaluran kepada amil tetap dicatat manual Pengurus sebagai
     * penyaluran ber-asnaf `amil`.
     *
     * @param  float  $dasar  Penerimaan dana zakat pada periode (Rp)
     * @return float Hak amil = porsi (%) × dasar penerimaan
     */
    public static function zakatHakAmil(float $dasar): float
    {
        $dasar = max(0.0, $dasar);

        return round($dasar * (static::zakatPorsiAmilPersen() / 100), 2);
    }

    /**
     * Ambang nominal penyaluran dana zakat/infak (Rp) yang mewajibkan
     * persetujuan pengurus/manajer lain sebelum jurnal diposting (Z-02).
     *
     * Nilai 0 (default, P4 Default Aman) = dual control NONAKTIF sehingga
     * seluruh penyaluran tetap auto-post seperti perilaku lama. Nilai > 0
     * mengaktifkan maker-checker untuk penyaluran dengan jumlah ≥ ambang.
     */
    public static function zakatAmbangPersetujuan(): float
    {
        return max(0.0, static::nominal('zakat_ambang_persetujuan', 0.0));
    }

    /**
     * Biaya administrasi penutupan CIF (Rp) — dipotong dari dana exit anggota.
     *
     * Nilai 0 (default) berarti tidak ada biaya. Dipakai modul Member Exit &
     * Closure sebagai satu-satunya sumber tarif (nol hardcoding).
     */
    public static function closureAdminFee(): float
    {
        return max(0.0, static::nominal('closure_admin_fee', 0.0));
    }

    /**
     * Ambang total net pencairan exit yang mewajibkan persetujuan Manajer (Rp).
     *
     * Nilai 0 (default) = approval workflow NONAKTIF sehingga pencairan
     * langsung diproses seperti perilaku lama (P4 Default Aman).
     */
    public static function highValueWithdrawalLimit(): float
    {
        return max(0.0, static::nominal('high_value_withdrawal_limit', 0.0));
    }

    /**
     * Apakah sebuah parameter benar-benar dikenal sistem (ada di DEFAULTS).
     *
     * Dipakai modul yang wajib menolak proses bila parameternya belum
     * terdaftar — bukan sekadar mengembalikan nilai default secara diam-diam.
     */
    public static function parameterTersedia(string $key): bool
    {
        return array_key_exists($key, static::all());
    }

    /**
     * Hisab zakat maal atas harta yang telah mencapai nishab & haul.
     *
     * Syarat wajib zakat maal = NISHAB **DAN** HAUL (Fatwa DSN-MUI 08).
     *  - Bila `zakat_haul_aktif` NONAKTIF (default), hisab hanya memeriksa
     *    nishab — menjaga perilaku lama (backward compatible).
     *  - Bila AKTIF, `$tanggalKepemilikan` wajib diisi; status "wajib" baru
     *    diberikan setelah kepemilikan genap `zakat_haul_hari` hari. Bila
     *    tanggal tidak diberikan, haul dianggap BELUM terpenuhi (fail-closed)
     *    agar sistem tidak menyatakan wajib tanpa bukti haul.
     *
     * @param  float  $harta  Total harta bersih yang dizakati (Rp)
     * @param  string|null  $tanggalKepemilikan  Tanggal mulai kepemilikan penuh (Y-m-d)
     * @return array{kena_zakat: bool, nishab: float, nishab_terpenuhi: bool, kadar_persen: float,
     *               zakat: float, haul_aktif: bool, haul_terpenuhi: bool, haul_hari: int,
     *               haul_sejak: string|null, hari_kepemilikan: int|null}
     */
    public static function hitungZakat(float $harta, ?string $tanggalKepemilikan = null): array
    {
        $nishab = static::zakatNisabNominal();
        $kadar = static::zakatRate();
        $nishabTercapai = $harta >= $nishab && $nishab > 0.0;

        $haulAktif = static::zakatHaulAktif();
        $haulHari = static::zakatHaulHari();
        $haulTerpenuhi = true;
        $hariKepemilikan = null;

        if ($haulAktif) {
            if ($tanggalKepemilikan === null || $tanggalKepemilikan === '') {
                // Fail-closed: tanpa tanggal kepemilikan, haul tidak dapat
                // diverifikasi sehingga harta belum dinyatakan wajib zakat.
                $haulTerpenuhi = false;
            } else {
                $mulai = Carbon::parse($tanggalKepemilikan)->startOfDay();
                $hariKepemilikan = (int) $mulai->diffInDays(now()->startOfDay(), false);
                $haulTerpenuhi = $hariKepemilikan >= $haulHari;
            }
        }

        $wajib = $nishabTercapai && $haulTerpenuhi;

        return [
            'kena_zakat' => $wajib,
            'nishab' => $nishab,
            'nishab_terpenuhi' => $nishabTercapai,
            'kadar_persen' => $kadar,
            'zakat' => $wajib ? round($harta * ($kadar / 100), 2) : 0.0,
            'haul_aktif' => $haulAktif,
            'haul_terpenuhi' => $haulTerpenuhi,
            'haul_hari' => $haulHari,
            'haul_sejak' => ($haulAktif && $tanggalKepemilikan !== null && $tanggalKepemilikan !== '')
                ? Carbon::parse($tanggalKepemilikan)->toDateString()
                : null,
            'hari_kepemilikan' => $hariKepemilikan,
        ];
    }

    /**
     * Hisab zakat PENGHASILAN (profesi) bulanan — PSAK 109 & Fatwa DSN-MUI 08.
     *
     * Zakat penghasilan dihitung atas PENGHASILAN NETO (penghasilan bruto
     * dikurangi kebutuhan pokok) dan baru wajib bila neto mencapai NISHAB
     * BULANAN (nishab maal 85 gram emas ÷ 12 bulan). Kadar mengikuti
     * `zakat_rate` (default 2,5%) sehingga nol hardcoding.
     *
     * Bersifat PRA-ISI: hasilnya hanya dipakai mengisi form penerimaan dan
     * tetap memerlukan persetujuan (posting manual) Pengurus — bukan posting
     * otomatis.
     *
     * @param  float  $bruto  Penghasilan bruto bulanan (Rp)
     * @param  float  $kebutuhanPokok  Kebutuhan pokok bulanan yang dikurangkan (Rp)
     * @return array{bruto: float, kebutuhan_pokok: float, penghasilan_neto: float,
     *               nishab_bulanan: float, nishab_terpenuhi: bool, kadar_persen: float,
     *               zakat: float, kena_zakat: bool}
     */
    public static function hitungZakatPenghasilan(float $bruto, float $kebutuhanPokok = 0.0): array
    {
        $bruto = max(0.0, $bruto);
        $kebutuhanPokok = max(0.0, $kebutuhanPokok);
        // Kebutuhan pokok tidak dapat melebihi penghasilan → neto minimal nol.
        $neto = max(0.0, $bruto - $kebutuhanPokok);

        // Nishab bulanan = nishab maal (85 gram emas) dibagi 12 bulan.
        $nishabBulanan = static::bulatkanUang(static::zakatNisabNominal() / 12);
        $kadar = static::zakatRate();
        $nishabTercapai = $nishabBulanan > 0.0 && $neto >= $nishabBulanan;

        return [
            'bruto' => static::bulatkanUang($bruto),
            'kebutuhan_pokok' => static::bulatkanUang($kebutuhanPokok),
            'penghasilan_neto' => static::bulatkanUang($neto),
            'nishab_bulanan' => $nishabBulanan,
            'nishab_terpenuhi' => $nishabTercapai,
            'kadar_persen' => $kadar,
            'zakat' => $nishabTercapai ? static::bulatkanUang($neto * ($kadar / 100)) : 0.0,
            'kena_zakat' => $nishabTercapai,
        ];
    }

    /**
     * Hitung nominal alokasi SHU dari total pendapatan periode.
     *
     * @return array<string, float> kunci alokasi => nominal rupiah
     */
    public static function hitungAlokasiShu(float $totalPendapatan): array
    {
        $hasil = [];

        foreach (static::alokasiShu() as $kunci => $persen) {
            $hasil[$kunci] = round($totalPendapatan * ($persen / 100), 2);
        }

        return $hasil;
    }

    /**
     * Aturan validasi input persentase alokasi SHU.
     *
     * @param  bool  $wajib  true = keempat pos wajib dikirim (form penuh);
     *                       false = opsional, field yang tidak dikirim memakai
     *                       nilai kebijakan yang sedang berlaku.
     * @return array<string, string>
     */
    public static function aturanValidasiAlokasiShu(bool $wajib = true): array
    {
        $awalan = $wajib ? 'required' : 'nullable';

        return [
            'shu_pct_cadangan_modal' => "{$awalan}|numeric|min:0|max:100",
            'shu_pct_jasa_anggota' => "{$awalan}|numeric|min:0|max:100",
            'shu_pct_jasa_modal' => "{$awalan}|numeric|min:0|max:100",
            'shu_pct_dana_pendidikan' => "{$awalan}|numeric|min:0|max:100",
        ];
    }

    /**
     * Pesan validasi input persentase alokasi SHU.
     *
     * @return array<string, string>
     */
    public static function pesanValidasiAlokasiShu(): array
    {
        $pesan = [];
        $label = [
            'shu_pct_cadangan_modal' => 'Persentase Cadangan Modal',
            'shu_pct_jasa_anggota' => 'Persentase Jasa Anggota',
            'shu_pct_jasa_modal' => 'Persentase Jasa Modal',
            'shu_pct_dana_pendidikan' => 'Persentase Dana Pendidikan',
        ];

        foreach ($label as $field => $nama) {
            $pesan["{$field}.required"] = "{$nama} wajib diisi.";
            $pesan["{$field}.numeric"] = "{$nama} harus berupa angka.";
            $pesan["{$field}.min"] = "{$nama} tidak boleh negatif.";
            $pesan["{$field}.max"] = "{$nama} tidak boleh melebihi 100%.";
        }

        return $pesan;
    }

    /**
     * Validasi total persentase alokasi SHU harus tepat 100%.
     *
     * @param  array<string, mixed>  $persen  Peta kunci alokasi => persentase.
     * @return string|null Pesan error, atau null bila valid.
     */
    public static function validasiTotalAlokasiShu(array $persen): ?string
    {
        $total = round(array_sum(array_map('floatval', $persen)), 2);

        if (abs($total - 100) > static::toleransiSelisihJurnal()) {
            return 'Total persentase alokasi SHU harus tepat 100% (saat ini '
                .number_format($total, 2, ',', '.').'%).';
        }

        return null;
    }

    /* ── Limit approval beban operasional ──────────────────────────────── */

    /**
     * Nominal pengeluaran beban di atas nilai ini wajib approval Manajer.
     *
     * Menggantikan konstanta PengeluaranBeban::LIMIT_APPROVAL agar dapat
     * diubah dari UI tanpa deploy ulang.
     */
    public static function limitApprovalBeban(): float
    {
        return static::nominal('limit_approval_beban', 1000000.00);
    }

    /**
     * Awalan nomor dokumen yang dikonfigurasi Pengurus.
     *
     * Mengembalikan `null` bila belum diisi, sehingga pemanggil
     * (`NomorDokumen`) memakai awalan bawaan dan perilaku sistem tidak
     * berubah. Nilai dinormalkan: huruf besar, hanya A-Z/0-9, maksimal 8
     * karakter agar tetap muat pada kolom nomor (VARCHAR 30/40).
     */
    public static function prefixNomorDokumen(string $jenis): ?string
    {
        $nilai = static::get('prefix_nomor_'.$jenis);

        if ($nilai === null || trim((string) $nilai) === '') {
            return null;
        }

        $bersih = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $nilai));

        return $bersih === '' ? null : substr($bersih, 0, 8);
    }

    /**
     * Plafon pembiayaan yang masih boleh disetujui penuh oleh Manajer.
     *
     * Di atas nilai ini persetujuan wajib naik ke Pengurus. Sebelumnya form
     * kebijakan memakai `limitApprovalBeban()` sebagai fallback sehingga
     * plafon pembiayaan bisa tertimpa batas approval BEBAN (Rp 1 juta).
     */
    public static function limitManajerPembiayaan(): float
    {
        return static::nominal('limit_manajer', 50000000.00);
    }

    /**
     * Plafon pembiayaan skala besar yang wajib diteruskan ke Pengurus.
     *
     * Disimpan terpisah dari `limit_manajer` karena kedua ambang dapat
     * berbeda (mis. Manajer s.d. Rp 50 juta, Pengurus s.d. Rp 250 juta).
     */
    public static function limitPembiayaanManajer(): float
    {
        return static::nominal('limit_pembiayaan_manajer', 50000000.00);
    }

    /**
     * Nominal minimum pengajuan pembiayaan baru.
     *
     * Menggantikan literal `min:1000000` pada validasi pengajuan AO agar
     * ambang dapat diubah dari UI tanpa deploy ulang.
     */
    public static function minimalPengajuanPembiayaan(): float
    {
        return static::nominal('minimal_pengajuan_pembiayaan', 1000000.00);
    }

    /* ── Ambang kolektibilitas pembiayaan (NPF) ────────────────────────── */

    /**
     * Ambang hari tunggakan untuk tiap tingkat kolektibilitas.
     *
     * Nilai dinormalkan agar selalu menaik (kol1 < kol2 < kol3 < kol4)
     * sehingga konfigurasi yang tidak konsisten tidak merusak klasifikasi.
     *
     * Kunci `kol1` disertakan sejak ambang "Lancar" memiliki batas atas
     * eksplisit (pola KSP syariah: Lancar = 0–10 hari).
     *
     * @return array{kol1: int, kol2: int, kol3: int, kol4: int}
     */
    public static function ambangKolektibilitas(): array
    {
        $kol1 = max(0, (int) static::get('kolektibilitas_kol1_hari', 10));
        $kol2 = max($kol1 + 1, (int) static::get('kolektibilitas_kol2_hari', 90));
        $kol3 = max($kol2 + 1, (int) static::get('kolektibilitas_kol3_hari', 120));
        $kol4 = max($kol3 + 1, (int) static::get('kolektibilitas_kol4_hari', 180));

        return ['kol1' => $kol1, 'kol2' => $kol2, 'kol3' => $kol3, 'kol4' => $kol4];
    }

    /**
     * Klasifikasi kode kolektibilitas berdasarkan jumlah hari tunggakan.
     *
     * Menggantikan ambang hardcode 30/90/180 di ManajerController::npf().
     * Tunggakan ≤ batas Kol-1 (default 10 hari) dianggap LANCAR.
     *
     * @return string 'kol1' | 'kol2' | 'kol3' | 'kol4' | 'kol5'
     */
    public static function kolektibilitasDariHari(int $hariTunggakan): string
    {
        $ambang = static::ambangKolektibilitas();

        return match (true) {
            $hariTunggakan <= $ambang['kol1'] => 'kol1',
            $hariTunggakan <= $ambang['kol2'] => 'kol2',
            $hariTunggakan <= $ambang['kol3'] => 'kol3',
            $hariTunggakan <= $ambang['kol4'] => 'kol4',
            default => 'kol5',
        };
    }

    /* ── Parameter PSAK & regulasi ─────────────────────────────────────── */

    /**
     * Asumsi rate bagi hasil deposito per tahun (desimal, 0.08 = 8%).
     *
     * Dipakai kalkulator/simulasi deposito (PSAK 105 — Mudharabah) sehingga
     * asumsi bagi hasil dapat disesuaikan tanpa mengubah kode.
     */
    public static function depositoRatePa(): float
    {
        return max(0.0, (float) static::get('deposito_rate_pa', 0.08));
    }

    /** Nisbah bagi hasil default untuk anggota (persen) bila produk belum menetapkan. */
    public static function nisbahDefaultPersen(): float
    {
        return static::persen('nisbah_default_persen', 60.00);
    }

    /** Margin/ujrah default (persen per tahun) bila produk pembiayaan belum menetapkan. */
    public static function marginDefaultPersen(): float
    {
        return static::persen('margin_default_persen', 12.00);
    }

    /** Nominal transaksi yang wajib ditandai sebagai transaksi keuangan mencurigakan. */
    public static function amlThreshold(): float
    {
        return static::nominal('aml_threshold', 100000000.00);
    }

    /** Jumlah angka desimal pembulatan nominal jurnal (0-4). */
    public static function pembulatanDesimal(): int
    {
        return max(0, min(4, (int) static::get('pembulatan_desimal', 0)));
    }

    /** Bulatkan nominal sesuai kebijakan pembulatan sistem (satu sumber aturan). */
    public static function pembulatanNominal(float $nominal): float
    {
        return round($nominal, static::pembulatanDesimal());
    }

    /**
     * Bulatkan nominal ke PRESISI UANG (2 desimal = satuan sen).
     *
     * Berbeda dari `pembulatanNominal()` yang mengikuti kebijakan
     * `pembulatan_desimal` (default 0 = rupiah penuh): method ini adalah
     * presisi penyimpanan kolom `decimal(15,2)`, BUKAN kebijakan yang bisa
     * diubah operator. Dipakai untuk menyeragamkan idiom `round($x, 2)` yang
     * tersebar agar definisi presisi uang hidup di satu tempat (zero-hardcode).
     *
     * Selalu setara dengan `round($nominal, 2)` — tidak mengubah perilaku.
     */
    public static function bulatkanUang(float $nominal): float
    {
        return round($nominal, self::DESIMAL_UANG);
    }

    /** Jumlah desimal presisi uang = 2 (selaras kolom `decimal(15,2)`). */
    public const DESIMAL_UANG = 2;

    /**
     * Toleransi pembandingan dua nominal uang: setengah satuan terkecil
     * presisi uang (0,005 untuk 2 desimal).
     *
     * Dipakai saat membandingkan nilai yang sudah berada pada presisi
     * `DESIMAL_UANG` — mis. memeriksa apakah nominal berubah, atau apakah
     * selisih kas hasil hitung fisik masih dianggap nol. Diturunkan dari
     * `DESIMAL_UANG` agar tidak ada literal 0,005 yang tersebar. Berbeda
     * dari `toleransiSelisihJurnal()` yang merupakan KEBIJAKAN operator
     * untuk keseimbangan debet-kredit.
     */
    public static function toleransiUang(): float
    {
        return 0.5 * (10 ** -self::DESIMAL_UANG);
    }

    /** Toleransi selisih debet-kredit yang masih dianggap seimbang (rupiah). */
    public static function toleransiSelisihJurnal(): float
    {
        return max(0.0, (float) static::get('toleransi_selisih_jurnal', 0.01));
    }

    /**
     * Toleransi total nisbah anggota + nisbah koperasi agar dianggap pas 100%
     * (persen). Default 0,01. Dipakai validator produk agar ambangnya
     * terkonfigurasi, bukan literal tersebar.
     */
    public static function toleransiPersen(): float
    {
        return max(0.0, (float) static::get('toleransi_persen', 0.01));
    }

    /**
     * Wajibkan transaksi terkait berstatus SUKSES sebelum jurnal diposting
     * (default AKTIF). Jurnal tanpa `transaksi_id` (jurnal manual) selalu
     * diizinkan.
     */
    public static function postingWajibTransaksiSukses(): bool
    {
        return static::bool('posting_wajib_transaksi_sukses', true);
    }

    /**
     * Rekonsiliasi otomatis inline setiap kali transaksi diposting (default AKTIF).
     */
    public static function rekonsiliasiInlineAktif(): bool
    {
        return static::bool('rekonsiliasi_inline_aktif', true);
    }

    /**
     * Persentase CKPN (penyisihan kerugian) per tingkat kolektibilitas.
     *
     * @return array{kol1: float, kol2: float, kol3: float, kol4: float, kol5: float}
     */
    public static function persenCkpn(): array
    {
        return [
            'kol1' => static::persen('ckpn_persen_kol1', 0.50),
            'kol2' => static::persen('ckpn_persen_kol2', 10.00),
            'kol3' => static::persen('ckpn_persen_kol3', 50.00),
            'kol4' => static::persen('ckpn_persen_kol4', 75.00),
            'kol5' => static::persen('ckpn_persen_kol5', 100.00),
        ];
    }

    /**
     * Persentase CKPN untuk satu kode kolektibilitas ('kol2'..'kol5').
     * Kode tak dikenal dianggap lancar (kol1).
     */
    public static function persenCkpnKolektibilitas(?string $kode): float
    {
        $kunci = strtolower(trim((string) $kode));
        $peta = static::persenCkpn();

        return $peta[$kunci] ?? $peta['kol1'];
    }

    /** ID akun CoA penampung denda ta'zir (null = ikuti akun Dana Kebajikan). */
    public static function akunTazirId(): ?int
    {
        $id = static::get('akun_tazir_id');

        return ($id === null || $id === '') ? null : (int) $id;
    }

    /* ── Pemetaan COA pencairan pembiayaan (dinamis, zero-hardcoding) ───── */

    /**
     * Ambil ID akun CoA dari kebijakan koperasi berdasarkan nama setting.
     *
     * Selalu mengembalikan null bila kolom kosong / bernilai 0 sehingga pemanggil
     * dapat melakukan fallback ke pemetaan standar App\Support\Coa.
     *
     * @param  string  $key  Nama kolom, mis. `account_piutang_murabahah_id`.
     */
    public static function akunId(string $key): ?int
    {
        $id = static::get($key);

        if ($id === null || $id === '' || (int) $id <= 0) {
            return null;
        }

        return (int) $id;
    }

    /** Apakah penyesuaian tanggal jatuh tempo ke hari kerja diaktifkan? */
    public static function hariLiburAktif(): bool
    {
        return static::bool('hari_libur_aktif', true);
    }

    /**
     * Strategi penggeseran tanggal jatuh tempo: 'MAJU' atau 'MUNDUR'.
     * Nilai tak dikenal dinormalkan menjadi 'MAJU' (hari kerja berikutnya).
     */
    public static function hariLiburStrategi(): string
    {
        $strategi = strtoupper(trim((string) static::get('hari_libur_strategi', 'MAJU')));

        return in_array($strategi, ['MAJU', 'MUNDUR'], true) ? $strategi : 'MAJU';
    }

    /**
     * Tanggal jatuh tempo angsuran pertama dalam sebulan (dikunci 1-28 agar
     * aman untuk seluruh bulan termasuk Februari).
     */
    public static function tanggalJatuhTempoDefault(): int
    {
        $tanggal = (int) static::get('tanggal_jatuh_tempo_default', 1);

        return max(1, min(28, $tanggal));
    }

    /**
     * Retensi berkas cadangan basis data (hari) sebelum dihapus scheduler.
     *
     * Dikunci 1-365: nilai 0/negatif akan menghapus cadangan yang baru dibuat
     * (bahaya kehilangan data), sedangkan nilai terlalu besar memenuhi disk.
     */
    public static function backupRetensiHari(): int
    {
        $hari = (int) static::get('backup_retensi_hari', 14);

        return max(1, min(365, $hari));
    }

    /**
     * TAHAP 5 — Cut-off periode tutup buku.
     *
     * Mengembalikan tanggal (Y-m-d) periode terakhir yang sudah ditutup, atau
     * `null` bila periode masih terbuka. Nilai yang tidak dapat diurai sebagai
     * tanggal diperlakukan sebagai `null` (periode terbuka) agar salah ketik
     * kebijakan tidak mengunci seluruh sistem.
     */
    public static function tutupBukuSampai(): ?string
    {
        return static::parseTutupBukuSampai()['tanggal'];
    }

    /**
     * Apakah nilai kebijakan `tutup_buku_sampai` ADA tetapi tidak dapat
     * diurai sebagai tanggal?
     *
     * Dipakai `PeriodeAkuntansiService` untuk menerapkan parameter
     * `periode_cutoff_mode`: nilai kosong = periode terbuka (bukan kesalahan),
     * sedangkan nilai tak sah + mode `fail_closed` = tolak posting.
     */
    public static function tutupBukuSampaiTidakValid(): bool
    {
        return static::parseTutupBukuSampai()['tidak_valid'];
    }

    /**
     * Urai nilai `tutup_buku_sampai` sekali pakai.
     *
     * @return array{tanggal: ?string, tidak_valid: bool}
     */
    private static function parseTutupBukuSampai(): array
    {
        $nilai = static::get('tutup_buku_sampai');

        if ($nilai === null || $nilai === '') {
            return ['tanggal' => null, 'tidak_valid' => false];
        }

        try {
            return ['tanggal' => Carbon::parse($nilai)->toDateString(), 'tidak_valid' => false];
        } catch (\Throwable $e) {
            // Nilai tak sah → perilaku ditentukan periode_cutoff_mode di
            // PeriodeAkuntansiService; sebabnya WAJIB terlihat di log.
            Log::warning('Setting: nilai tutup_buku_sampai tidak dapat diurai.', [
                'nilai' => $nilai,
                'error' => $e->getMessage(),
            ]);

            return ['tanggal' => null, 'tidak_valid' => true];
        }
    }

    /* ── Tahun buku dinamis (fleksibilitas periode PSAK) ───────────────── */

    /**
     * Bulan pertama tahun buku koperasi (1-12).
     *
     * Default 1 = Januari (tahun buku kalender). Nilai dinormalkan ke rentang
     * 1-12 agar konfigurasi yang salah ketik tidak menghasilkan periode tak
     * sah — perilaku aman = kembali ke Januari.
     */
    public static function tahunBukuMulaiBulan(): int
    {
        $bulan = (int) static::get('tahun_buku_mulai_bulan', 1);

        return max(1, min(12, $bulan));
    }

    /**
     * Jumlah bulan dalam satu tahun buku (1-12).
     *
     * Default 12 = setahun penuh. Nilai dinormalkan ke rentang 1-12.
     */
    public static function jumlahBulanTahunBuku(): int
    {
        $jumlah = (int) static::get('jumlah_bulan_tahun_buku', 12);

        return max(1, min(12, $jumlah));
    }

    /**
     * Mode gerbang cut-off periode saat nilai kebijakan tidak dapat diurai.
     *
     * - 'fail_closed' → tolak posting (aman, konservatif).
     * - 'fail_open'   → izinkan posting (default, agar operasional tidak lumpuh).
     *
     * Nilai tak dikenal dinormalkan ke 'fail_open' (perilaku sistem saat ini).
     */
    public static function periodeCutoffMode(): string
    {
        $mode = strtolower(trim((string) static::get('periode_cutoff_mode', 'fail_open')));

        return in_array($mode, ['fail_closed', 'fail_open'], true) ? $mode : 'fail_open';
    }

    /* ── Klasifikasi akad & partisipasi SHU (fleksibilitas PSAK 107-109) ── */

    /**
     * Ubah daftar berkoma dari parameter kebijakan menjadi array bersih.
     *
     * Nilai kosong / hanya spasi → memakai `$default` (perilaku sistem saat
     * ini), sehingga parameter yang belum diisi tidak mengubah apa pun
     * (asas P4 Default Aman).
     *
     * @param  array<int, string>  $default
     * @return array<int, string>
     */
    private static function daftar(string $key, array $default): array
    {
        $raw = trim((string) static::get($key, ''));

        if ($raw === '') {
            return $default;
        }

        $items = array_values(array_filter(
            array_map(static fn ($v) => trim((string) $v), explode(',', $raw)),
            static fn ($v) => $v !== ''
        ));

        return $items === [] ? $default : $items;
    }

    /**
     * Akad jual-beli yang imbalannya diakui sebagai MARGIN (PSAK 107/108).
     *
     * @return array<int, string>
     */
    public static function akadMargin(): array
    {
        return static::daftar('akad_margin', ['Murabahah', 'Ijarah Muntahia Bittamlik', 'Istishna', 'Salam']);
    }

    /**
     * Jenis transaksi yang dikategorikan TUNAI untuk pelaporan APU-PPT.
     *
     * Dipakai modul pelaporan PPATK (LTKT) — UU 8/2010 & POJK 27/2022.
     *
     * @return array<int, string>
     */
    public static function jenisTransaksiTunai(): array
    {
        return static::daftar('ppatk_jenis_tunai', ['Setoran Tunai', 'Penarikan Tunai']);
    }

    /**
     * Akad kerja sama usaha yang imbalannya diakui sebagai BAGI HASIL
     * (PSAK 105/106).
     *
     * @return array<int, string>
     */
    public static function akadBagiHasil(): array
    {
        return static::daftar('akad_bagi_hasil', ['Mudharabah', 'Musyarakah']);
    }

    /**
     * Akad sewa/jasa yang imbalannya diakui sebagai UJROH (PSAK 107).
     *
     * @return array<int, string>
     */
    public static function akadUjroh(): array
    {
        return static::daftar('akad_ujroh', ['Ijarah']);
    }

    /**
     * Jenis transaksi yang dihitung sebagai partisipasi usaha anggota
     * (basis Jasa Transaksi / Jasa Usaha SHU).
     *
     * @return array<int, string>
     */
    public static function jenisPartisipasiShu(): array
    {
        return static::daftar('shu_jenis_partisipasi', array_merge(
            JenisTransaksi::angsuran(),
            ['Bagi Hasil Simpanan Berjangka', JenisTransaksi::BAGI_HASIL],
        ));
    }

    /**
     * Konversi input nominal berformat Indonesia/internasional menjadi float.
     *
     * Dipusatkan di sini agar SELURUH titik input (controller/service/form)
     * memakai aturan normalisasi yang sama — mencegah perbedaan tafsir
     * "1.000" (seribu) vs "1.000" (satu koma nol).
     *
     * Menerima "100.000", "100.000,50", "100,000.50", "100000", atau "Rp 1.000.000".
     */
    public static function parseNominal(mixed $nilai): float
    {
        $bersih = preg_replace('/[^0-9,.\-]/', '', (string) $nilai);

        if ($bersih === '' || $bersih === '-' || $bersih === '.' || $bersih === ',') {
            return 0.0;
        }

        $posisiTitik = strrpos($bersih, '.');
        $posisiKoma = strrpos($bersih, ',');

        if ($posisiTitik !== false && $posisiKoma !== false) {
            if ($posisiTitik > $posisiKoma) {
                $bersih = str_replace(',', '', $bersih);
            } else {
                $bersih = str_replace('.', '', $bersih);
                $bersih = str_replace(',', '.', $bersih);
            }
        } elseif ($posisiKoma !== false) {
            $desimal = strlen($bersih) - $posisiKoma - 1;
            $bersih = $desimal <= 2 && $desimal > 0
                ? str_replace(',', '.', $bersih)
                : str_replace(',', '', $bersih);
        } elseif ($posisiTitik !== false) {
            $desimal = strlen($bersih) - $posisiTitik - 1;
            if ($desimal > 2 || $desimal === 0) {
                $bersih = str_replace('.', '', $bersih);
            }
        }

        return round((float) $bersih, 2);
    }

    /* ── Batas dinamis nominal pembukaan rekening ──────────────────────── */

    /** Batas bawah nominal pembukaan rekening (dapat dikonfigurasi pengurus). */
    public static function minimumPembukaanRekening(): float
    {
        return static::nominal('minimum_pembukaan_rekening', 10000.00);
    }

    /** Batas atas nominal pembukaan rekening (dapat dikonfigurasi pengurus). */
    public static function maksimumPembukaanRekening(): float
    {
        return static::nominal('maksimum_pembukaan_rekening', 50000000.00);
    }

    /**
     * Batas nominal pembukaan rekening sebagai array siap pakai aturan validasi.
     *
     * Nilai maksimum dinormalkan agar tidak pernah lebih kecil dari minimum —
     * sehingga perubahan konfigurasi yang tidak konsisten tetap aman dipakai
     * tanpa mengubah kode inti.
     *
     * @return array{minimum: float, maksimum: float}
     */
    public static function batasPembukaanRekening(): array
    {
        $minimum = static::minimumPembukaanRekening();
        $maksimum = max($maksimum = static::maksimumPembukaanRekening(), $minimum);

        return ['minimum' => $minimum, 'maksimum' => $maksimum];
    }

    /**
     * Aturan validasi Laravel untuk parameter nominal pembukaan rekening.
     *
     * Semua jalur pembukaan rekening memakai aturan ini sehingga batas
     * minimum/maksimum selalu konsisten dan dapat berubah tanpa mengedit kode.
     *
     * @param  bool  $wajib  true = parameter wajib diisi (not-null);
     *                       false = opsional, tetapi bila diisi tetap dibatasi.
     * @return array<int, string>
     */
    public static function aturanNominalPembukaan(bool $wajib = true): array
    {
        $batas = static::batasPembukaanRekening();

        $aturan = [
            'numeric',
            'min:'.$batas['minimum'],
            'max:'.$batas['maksimum'],
        ];

        array_unshift($aturan, $wajib ? 'required' : 'nullable');

        return $aturan;
    }

    /**
     * Pesan error validasi nominal pembukaan rekening yang informatif.
     *
     * @param  string  $label  Nama field pada form (mis. "Setoran awal").
     * @return array<string, string> Peta `field.rule` → pesan.
     */
    public static function pesanValidasiNominalPembukaan(string $label = 'Nominal pembukaan rekening'): array
    {
        $batas = static::batasPembukaanRekening();
        $min = number_format($batas['minimum'], 2, ',', '.');
        $max = number_format($batas['maksimum'], 2, ',', '.');

        return [
            'required' => "{$label} wajib diisi.",
            'numeric' => "{$label} harus berupa angka.",
            'min' => "{$label} tidak boleh kurang dari Rp {$min} (nilai nominal di bawah batas minimum yang diizinkan).",
            'max' => "{$label} tidak boleh melebihi Rp {$max} (nilai nominal di atas batas maksimum yang diizinkan).",
        ];
    }

    /**
     * Validasi nilai nominal pembukaan rekening di luar konteks Laravel
     * Validator (mis. dari Service atau perhitungan internal).
     *
     * @return string|null Pesan error, atau null bila valid.
     */
    public static function validasiNominalPembukaan(mixed $nilai, string $label = 'Nominal pembukaan rekening'): ?string
    {
        $batas = static::batasPembukaanRekening();
        $pesan = static::pesanValidasiNominalPembukaan($label);

        if ($nilai === null || $nilai === '') {
            return $pesan['required'];
        }

        if (! is_numeric($nilai)) {
            return $pesan['numeric'];
        }

        $angka = round((float) $nilai, 2);

        if ($angka < $batas['minimum']) {
            return $pesan['min'];
        }

        if ($angka > $batas['maksimum']) {
            return $pesan['max'];
        }

        return null;
    }

    /**
     * Invalidasi cache secara manual. Dipanggil otomatis oleh model
     * KebijakanKoperasi pada event saved/deleted sehingga penyimpanan dari
     * manapun (UI, seeder, tinker) langsung tercermin di seluruh aplikasi.
     */
    public static function flush(): void
    {
        Cache::forget(static::CACHE_KEY);
    }

    /** Paksa baca ulang dari database lalu simpan ke cache. */
    public static function refresh(): array
    {
        static::flush();

        return static::all();
    }

    /** Konversi nilai mentah DB ke tipe PHP yang tepat sesuai default. */
    /**
     * Presisi desimal parameter yang membutuhkan lebih dari 2 angka desimal.
     *
     * Parameter lain cukup 2 desimal (rupiah/persen). `deposito_rate_pa`
     * disimpan sebagai desimal (0,0675 = 6,75% p.a.) sehingga WAJIB tetap
     * 4 desimal — tanpa ini, rate 6,75% diam-diam dibulatkan menjadi 7%.
     *
     * @var array<string, int>
     */
    private const PRESISI_DESIMAL = [
        'deposito_rate_pa' => 4,
    ];

    private static function cast(string $key, mixed $value, mixed $default): mixed
    {
        // Tipe resmi (kolom `type` pada metadata) lebih diutamakan daripada
        // menyimpulkan dari default, sehingga casting tetap benar walau nilai
        // default berubah atau parameter baru ditambahkan tanpa default float.
        if (array_key_exists($key, static::TIPE)) {
            return match (static::TIPE[$key]) {
                'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'integer' => (int) $value,
                'decimal' => round((float) $value, self::PRESISI_DESIMAL[$key] ?? 2),
                'json' => is_string($value)
                    ? (json_decode($value, true) ?? $value)
                    : $value,
                default => $value,
            };
        }

        return match (true) {
            is_bool($default) => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            is_int($default) => (int) $value,
            is_float($default) => round((float) $value, self::PRESISI_DESIMAL[$key] ?? 2),
            default => $value,
        };
    }
}
