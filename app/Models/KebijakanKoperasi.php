<?php

namespace App\Models;

use App\Events\KebijakanBerubah;
use App\Support\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Schema;

class KebijakanKoperasi extends Model
{
    protected $table = 'kebijakan_koperasis';

    protected $fillable = [
        'limit_manajer',
        'limit_pembiayaan_manajer',
        // Awalan nomor dokumen (TRX/RKN/JRN/BBK/TRM/DEP) — dapat diubah Pengurus
        'prefix_nomor_transaksi',
        'prefix_nomor_rekening',
        'prefix_nomor_jurnal',
        'prefix_nomor_pengeluaran_beban',
        'prefix_nomor_terminasi',
        'prefix_nomor_deposito',
        'limit_penarikan_teller',
        'limit_setoran_teller',
        // Batas kas fisik laci teller & nominal minimum transaksi tunai
        'teller_max_cash_limit',
        'minimum_transaksi_teller',
        'nama_koperasi',
        // Parameter bundling setoran awal anggota baru (tanpa hardcode)
        'mandatory_principal_savings',
        'mandatory_monthly_savings',
        // Batas dinamis nominal pembukaan rekening (tanpa hardcode)
        'minimum_pembukaan_rekening',
        'maksimum_pembukaan_rekening',
        // Kebijakan penahanan SHU anggota menunggak Simpanan Wajib
        'shu_hold_on_overdue',
        // Parameter dinamis modul Simpanan Wajib (Tahap 12 / R1)
        'simpanan_wajib_kanal_default',
        'simpanan_wajib_boleh_ditarik',
        'simpanan_wajib_hari_penarikan',
        // Jam posting auto-debit Simpanan Wajib (DYN-17) — format HH:MM.
        'jam_posting_simpanan_wajib',
        'account_simpanan_wajib_id',
        // Parameter penanganan dinamis tunggakan Simpanan Wajib (Tahap 12 / R4).
        'simpanan_wajib_auto_debit_aktif',
        'simpanan_wajib_penanganan_dinamis',
        'simpanan_wajib_maks_cicilan',
        'simpanan_wajib_reschedule_bulan',
        'simpanan_wajib_maks_reschedule',
        'simpanan_wajib_notif_anonim',
        'simpanan_wajib_ambang_kurang_ringan',
        // Alokasi SHU (persentase, dapat dikonfigurasi)
        'shu_pct_cadangan_modal',
        'shu_pct_jasa_anggota',
        'shu_pct_jasa_modal',
        'shu_pct_dana_pendidikan',
        // Limit approval beban operasional (tanpa hardcode)
        'limit_approval_beban',
        // Parameter modul Member Exit & Closure (tanpa hardcode)
        'closure_admin_fee',
        'high_value_withdrawal_limit',
        // Retensi berkas cadangan basis data (hari) — dibaca scheduler.
        'backup_retensi_hari',
        // Nominal minimum pengajuan pembiayaan baru (tanpa hardcode)
        'minimal_pengajuan_pembiayaan',
        // Ambang hari kolektibilitas pembiayaan (NPF)
        'kolektibilitas_kol1_hari',
        'kolektibilitas_kol2_hari',
        'kolektibilitas_kol3_hari',
        'kolektibilitas_kol4_hari',
        // Metode perhitungan partisipasi SHU anggota (SSOT: SRH)
        'metode_perhitungan_shu',
        // Ta'zir config
        'tazir_mode',
        'tazir_rate',
        // Tarif denda keterlambatan angsuran pembiayaan per hari (0 = NONAKTIF)
        'financing_late_fee_per_day',
        'tazir_grace_period_days',
        'tazir_default_method',
        'tazir_default_nominal',
        'akun_tazir_id',
        // Parameter PSAK & regulasi (tanpa hardcode)
        'deposito_rate_pa',
        'nisbah_default_persen',
        'margin_default_persen',
        'aml_threshold',
        // Pelaporan PPATK (UU 8/2010 & POJK 27/2022) — LTKT/LTKM/STR.
        'ltkt_threshold',
        'ppatk_jenis_tunai',
        'ppatk_nama_pelapor',
        'ppatk_kode_pelapor',
        'npwp_koperasi',
        'pembulatan_desimal',
        'toleransi_selisih_jurnal',
        'ckpn_persen_kol1',
        'ckpn_persen_kol2',
        'ckpn_persen_kol3',
        'ckpn_persen_kol4',
        'ckpn_persen_kol5',
        // Pemetaan COA pencairan pembiayaan (dinamis / zero-hardcoding).
        'account_kas_teller_id',
        'account_kas_brankas_id',
        'account_bank_koperasi_id',
        'account_piutang_murabahah_id',
        'account_piutang_mudharabah_id',
        'account_piutang_musyarakah_id',
        'account_piutang_ijarah_id',
        'account_piutang_qardh_id',
        'account_investasi_mudharabah_id',
        'account_investasi_musyarakah_id',
        'account_margin_deferred_id',
        'account_simpanan_sukarela_id',
        // Parameter hisab Zakat (PSAK 109 & syariah) — tanpa hardcode.
        'zakat_rate',
        'zakat_nisab_gram',
        'harga_emas_per_gram',
        'zakat_minimal_tarik',
        'zakat_haul_aktif',
        'zakat_haul_hari',
        // Porsi/hak amil atas dana zakat (Fatwa DSN-MUI 08 / temuan Z-08).
        'zakat_porsi_amil_persen',
        // Ambang dual control penyaluran dana amanah (temuan Z-02).
        'zakat_ambang_persetujuan',
        // Pemetaan COA dana zakat & infak/sedekah (PSAK 109 / temuan Z-05).
        'account_dana_zakat_id',
        'account_dana_infak_id',
        'account_dana_kebajikan_id',
        'account_dana_sosial_id',
        'account_titipan_selisih_id',
        // Penjadwalan angsuran & kalender hari libur.
        'hari_libur_aktif',
        'hari_libur_strategi',
        'tanggal_jatuh_tempo_default',
        // Tahun buku dinamis & integritas periode (fleksibilitas temporal).
        'tahun_buku_mulai_bulan',
        'jumlah_bulan_tahun_buku',
        'periode_cutoff_mode',
        'tutup_buku_sampai',
        // Klasifikasi akad & partisipasi SHU (fleksibilitas PSAK 107-109).
        'akad_margin',
        'akad_bagi_hasil',
        'akad_ujroh',
        'shu_jenis_partisipasi',
        // Gate integritas posting & rekonsiliasi inline (boolean).
        'posting_wajib_transaksi_sukses',
        'rekonsiliasi_inline_aktif',
        // Parameter tanpa kolom sebelumnya (migrasi 2026_10_28_000300).
        'autodebit_wajib_konfirmasi',
        'toleransi_persen',
    ];

    protected $casts = [
        'posting_wajib_transaksi_sukses' => 'boolean',
        'rekonsiliasi_inline_aktif' => 'boolean',
        'autodebit_wajib_konfirmasi' => 'boolean',
        'toleransi_persen' => 'decimal:4',
        'account_dana_kebajikan_id' => 'integer',
        'account_dana_sosial_id' => 'integer',
        'account_titipan_selisih_id' => 'integer',
        'limit_manajer' => 'decimal:2',
        'limit_pembiayaan_manajer' => 'decimal:2',
        'limit_penarikan_teller' => 'decimal:2',
        'limit_setoran_teller' => 'decimal:2',
        'teller_max_cash_limit' => 'decimal:2',
        'minimum_transaksi_teller' => 'decimal:2',
        'mandatory_principal_savings' => 'decimal:2',
        'mandatory_monthly_savings' => 'decimal:2',
        'minimum_pembukaan_rekening' => 'decimal:2',
        'maksimum_pembukaan_rekening' => 'decimal:2',
        'shu_hold_on_overdue' => 'boolean',
        // Parameter dinamis modul Simpanan Wajib (Tahap 12 / R1).
        'simpanan_wajib_boleh_ditarik' => 'boolean',
        'account_simpanan_wajib_id' => 'integer',
        // Parameter penanganan dinamis tunggakan Simpanan Wajib (Tahap 12 / R4).
        'simpanan_wajib_auto_debit_aktif' => 'boolean',
        'simpanan_wajib_penanganan_dinamis' => 'boolean',
        'simpanan_wajib_maks_cicilan' => 'integer',
        'simpanan_wajib_reschedule_bulan' => 'integer',
        'simpanan_wajib_maks_reschedule' => 'integer',
        'simpanan_wajib_notif_anonim' => 'boolean',
        'simpanan_wajib_ambang_kurang_ringan' => 'decimal:2',
        'shu_pct_cadangan_modal' => 'decimal:2',
        'shu_pct_jasa_anggota' => 'decimal:2',
        'shu_pct_jasa_modal' => 'decimal:2',
        'shu_pct_dana_pendidikan' => 'decimal:2',
        'limit_approval_beban' => 'decimal:2',
        'closure_admin_fee' => 'decimal:2',
        'high_value_withdrawal_limit' => 'decimal:2',
        'backup_retensi_hari' => 'integer',
        'minimal_pengajuan_pembiayaan' => 'decimal:2',
        'kolektibilitas_kol1_hari' => 'integer',
        'kolektibilitas_kol2_hari' => 'integer',
        'kolektibilitas_kol3_hari' => 'integer',
        'kolektibilitas_kol4_hari' => 'integer',
        'metode_perhitungan_shu' => 'string',
        'prefix_nomor_transaksi' => 'string',
        'prefix_nomor_rekening' => 'string',
        'prefix_nomor_jurnal' => 'string',
        'prefix_nomor_pengeluaran_beban' => 'string',
        'prefix_nomor_terminasi' => 'string',
        'prefix_nomor_deposito' => 'string',
        'tazir_rate' => 'decimal:2',
        // Saklar & tarif denda keterlambatan angsuran pembiayaan (PSAK 108/109).
        'financing_late_fee_per_day' => 'decimal:2',
        'tazir_grace_period_days' => 'integer',
        'tazir_default_nominal' => 'decimal:2',
        'deposito_rate_pa' => 'decimal:4',
        'nisbah_default_persen' => 'decimal:2',
        'margin_default_persen' => 'decimal:2',
        'aml_threshold' => 'decimal:2',
        'ltkt_threshold' => 'decimal:2',
        'pembulatan_desimal' => 'integer',
        'toleransi_selisih_jurnal' => 'decimal:2',
        'ckpn_persen_kol1' => 'decimal:2',
        'ckpn_persen_kol2' => 'decimal:2',
        'ckpn_persen_kol3' => 'decimal:2',
        'ckpn_persen_kol4' => 'decimal:2',
        'ckpn_persen_kol5' => 'decimal:2',
        // ID akun CoA disimpan sebagai integer agar casting konsisten saat
        // dibaca Setting::akunId() maupun form editor kebijakan.
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
        // Parameter hisab Zakat (PSAK 109 & syariah).
        'zakat_rate' => 'decimal:2',
        'zakat_nisab_gram' => 'decimal:2',
        'harga_emas_per_gram' => 'decimal:2',
        'zakat_minimal_tarik' => 'decimal:2',
        'zakat_haul_aktif' => 'boolean',
        'zakat_haul_hari' => 'integer',
        'zakat_porsi_amil_persen' => 'decimal:2',
        'zakat_ambang_persetujuan' => 'decimal:2',
        // Pemetaan COA dana zakat & infak/sedekah (PSAK 109 / temuan Z-05).
        'account_dana_zakat_id' => 'integer',
        'account_dana_infak_id' => 'integer',
        'hari_libur_aktif' => 'boolean',
        'hari_libur_strategi' => 'string',
        'tanggal_jatuh_tempo_default' => 'integer',
        'tahun_buku_mulai_bulan' => 'integer',
        'jumlah_bulan_tahun_buku' => 'integer',
    ];

    /**
     * Label manusiawi untuk tiap kolom kebijakan — dipakai pada riwayat
     * perubahan agar pengurus mudah memahami konteks nilainya.
     *
     * @var array<string, string>
     */
    public const LABEL_KOLOM = [
        'nama_koperasi' => 'Nama Koperasi',
        'prefix_nomor_transaksi' => 'Awalan Nomor Transaksi',
        'prefix_nomor_rekening' => 'Awalan Nomor Rekening',
        'prefix_nomor_jurnal' => 'Awalan Nomor Jurnal',
        'prefix_nomor_pengeluaran_beban' => 'Awalan Bukti Kas/Bank Keluar',
        'prefix_nomor_terminasi' => 'Awalan Nomor Terminasi Anggota',
        'prefix_nomor_deposito' => 'Awalan Nomor Bilyet Deposito',
        'limit_manajer' => 'Limit Otorisasi Manajer',
        'limit_pembiayaan_manajer' => 'Limit Pembiayaan Manajer',
        'limit_penarikan_teller' => 'Limit Penarikan Teller',
        'limit_setoran_teller' => 'Limit Setoran Teller',
        'teller_max_cash_limit' => 'Batas Maksimum Kas Laci Teller (Rp)',
        'minimum_transaksi_teller' => 'Minimum Transaksi Tunai Teller (Rp)',
        'mandatory_principal_savings' => 'Simpanan Pokok (Setoran Awal)',
        'mandatory_monthly_savings' => 'Simpanan Wajib per Bulan',
        'minimum_pembukaan_rekening' => 'Batas Minimum Nominal Pembukaan Rekening',
        'maksimum_pembukaan_rekening' => 'Batas Maksimum Nominal Pembukaan Rekening',
        'shu_hold_on_overdue' => 'Penahanan SHU saat Menunggak',
        'shu_pct_cadangan_modal' => 'Persentase SHU — Cadangan Modal',
        'shu_pct_jasa_anggota' => 'Persentase SHU — Jasa Anggota',
        'shu_pct_jasa_modal' => 'Persentase SHU — Jasa Modal',
        'shu_pct_dana_pendidikan' => 'Persentase SHU — Dana Pendidikan',
        'limit_approval_beban' => 'Limit Approval Beban Operasional',
        'minimal_pengajuan_pembiayaan' => 'Nominal Minimum Pengajuan Pembiayaan',
        'kolektibilitas_kol1_hari' => 'Ambang Kolektibilitas Kol-1 / Lancar (hari)',
        'kolektibilitas_kol2_hari' => 'Ambang Kolektibilitas Kol-2 (hari)',
        'kolektibilitas_kol3_hari' => 'Ambang Kolektibilitas Kol-3 (hari)',
        'kolektibilitas_kol4_hari' => 'Ambang Kolektibilitas Kol-4 (hari)',
        'metode_perhitungan_shu' => 'Metode Perhitungan Partisipasi SHU (SRH)',
        'tazir_mode' => "Mode Ta'zir",
        'tazir_rate' => "Tarif Ta'zir",
        'financing_late_fee_per_day' => 'Tarif Denda Keterlambatan Angsuran Pembiayaan per Hari (Rp) — 0 = Nonaktif',
        'tazir_grace_period_days' => "Masa Tenggang Ta'zir (hari)",
        'tazir_default_method' => "Metode Ta'zir Default",
        'tazir_default_nominal' => "Nominal Default Ta'zir (Rp)",
        'deposito_rate_pa' => 'Asumsi Rate Bagi Hasil Deposito per Tahun (desimal, 0.08 = 8%)',
        'nisbah_default_persen' => 'Nisbah Bagi Hasil Anggota Default (%)',
        'margin_default_persen' => 'Margin/Ujrah Default (% per tahun)',
        'aml_threshold' => 'Ambang Transaksi Keuangan Mencurigakan (Rp)',
        'ltkt_threshold' => 'Ambang Transaksi Tunai Wajib Lapor PPATK / LTKT (Rp)',
        'ppatk_jenis_tunai' => 'Jenis Transaksi Tunai untuk LTKT (dipisah koma)',
        'ppatk_nama_pelapor' => 'Nama Pelapor PPATK (kop laporan)',
        'ppatk_kode_pelapor' => 'Kode Pelapor / ID goAML',
        'npwp_koperasi' => 'NPWP Koperasi (identitas pelapor)',
        'pembulatan_desimal' => 'Pembulatan Nominal Jurnal (angka desimal)',
        'toleransi_selisih_jurnal' => 'Toleransi Selisih Debet-Kredit (Rp)',
        'ckpn_persen_kol1' => 'CKPN Kol-1 / Lancar (%)',
        'ckpn_persen_kol2' => 'CKPN Kol-2 / Dalam Perhatian Khusus (%)',
        'ckpn_persen_kol3' => 'CKPN Kol-3 / Kurang Lancar (%)',
        'ckpn_persen_kol4' => 'CKPN Kol-4 / Diragukan (%)',
        'ckpn_persen_kol5' => 'CKPN Kol-5 / Macet (%)',
        'account_kas_teller_id' => 'COA Kas Teller',
        'account_kas_brankas_id' => 'COA Kas Brankas',
        'account_bank_koperasi_id' => 'COA Bank Operasional Koperasi',
        'account_piutang_murabahah_id' => 'COA Piutang Murabahah',
        'account_piutang_mudharabah_id' => 'COA Piutang Mudharabah',
        'account_piutang_musyarakah_id' => 'COA Piutang Musyarakah',
        'account_piutang_ijarah_id' => 'COA Piutang Ijarah',
        'account_piutang_qardh_id' => 'COA Pinjaman Qardh',
        'account_investasi_mudharabah_id' => 'COA Investasi Mudharabah',
        'account_investasi_musyarakah_id' => 'COA Investasi Musyarakah',
        'account_margin_deferred_id' => 'COA Margin Ditangguhkan (PSAK 108)',
        'account_simpanan_sukarela_id' => 'COA Simpanan Sukarela',
        'zakat_rate' => 'Kadar Zakat Maal (%)',
        'zakat_nisab_gram' => 'Nishab Zakat (gram emas)',
        'harga_emas_per_gram' => 'Harga Emas per Gram (Rp)',
        'zakat_minimal_tarik' => 'Minimum Penyaluran Dana Zakat (Rp)',
        'zakat_haul_aktif' => 'Penegakan Syarat Haul Zakat Maal',
        'zakat_haul_hari' => 'Panjang Haul Zakat (hari hijriah)',
        'zakat_porsi_amil_persen' => 'Porsi Hak Amil atas Dana Zakat (%)',
        'zakat_ambang_persetujuan' => 'Ambang Persetujuan Penyaluran Dana (Rp)',
        'account_dana_zakat_id' => 'COA Dana Zakat (PSAK 109)',
        'account_dana_infak_id' => 'COA Dana Infak & Sedekah (PSAK 109)',
        'hari_libur_aktif' => 'Penyesuaian Jatuh Tempo ke Hari Kerja',
        'hari_libur_strategi' => 'Strategi Penggeseran Jatuh Tempo (MAJU/MUNDUR)',
        'tanggal_jatuh_tempo_default' => 'Tanggal Jatuh Tempo Angsuran dalam Sebulan',
        'backup_retensi_hari' => 'Retensi Berkas Cadangan Basis Data (Hari)',
        'tahun_buku_mulai_bulan' => 'Bulan Awal Tahun Buku (1-12)',
        'jumlah_bulan_tahun_buku' => 'Jumlah Bulan dalam Tahun Buku (1-12)',
        'periode_cutoff_mode' => 'Mode Cut-off Periode (fail_open/fail_closed)',
        'tutup_buku_sampai' => 'Cut-off Tutup Buku (YYYY-MM-DD)',
        'akad_margin' => 'Akad Margin / Jual-Beli (PSAK 107)',
        'akad_bagi_hasil' => 'Akad Bagi Hasil / Kerja Sama Usaha (PSAK 105-106)',
        'akad_ujroh' => 'Akad Ujroh / Sewa-Jasa (PSAK 107)',
        'shu_jenis_partisipasi' => 'Jenis Transaksi Partisipasi SHU',
        // Parameter penanganan dinamis tunggakan Simpanan Wajib (Tahap 12 / R4).
        'simpanan_wajib_auto_debit_aktif' => 'Saklar Auto-Debet Simpanan Wajib',
        'simpanan_wajib_penanganan_dinamis' => 'Penanganan Dinamis Tunggakan (Bertahap & Reschedule)',
        'simpanan_wajib_maks_cicilan' => 'Maksimum Cicilan Jadwal Bertahap',
        'simpanan_wajib_reschedule_bulan' => 'Perpanjangan Tenggat per Reschedule (bulan)',
        'simpanan_wajib_maks_reschedule' => 'Maksimum Reschedule per Tagihan',
        'simpanan_wajib_notif_anonim' => 'Anonimisasi Notifikasi (PDP)',
        'simpanan_wajib_ambang_kurang_ringan' => 'Ambang Kekurangan Ringan (Rp)',
        'simpanan_wajib_kanal_default' => 'Kanal Default Auto-Debet Simpanan Wajib',
        'simpanan_wajib_boleh_ditarik' => 'Simpanan Wajib Boleh Ditarik',
        'simpanan_wajib_hari_penarikan' => 'Hari Penarikan Simpanan Wajib',
        'account_simpanan_wajib_id' => 'COA Simpanan Wajib',
        'jam_posting_simpanan_wajib' => 'Jam Posting Auto-Debet Simpanan Wajib (HH:MM)',
        'akun_tazir_id' => "COA Ta'zir",
        'posting_wajib_transaksi_sukses' => 'Gate Posting Hanya Transaksi Sukses',
        'rekonsiliasi_inline_aktif' => 'Rekonsiliasi Inline Saat Posting',
        'closure_admin_fee' => 'Biaya Administrasi Penutupan Keanggotaan (Rp)',
        'high_value_withdrawal_limit' => 'Batas Penarikan Bernilai Tinggi (Rp)',
        'autodebit_wajib_konfirmasi' => 'Auto-Debet Simpanan Wajib Wajib Dikonfirmasi',
        'toleransi_persen' => 'Toleransi Selisih Rekonsiliasi (persen)',
        'account_dana_kebajikan_id' => 'COA Dana Kebajikan',
        'account_dana_sosial_id' => 'COA Dana Sosial',
        'account_titipan_selisih_id' => 'COA Titipan Selisih',
    ];

    /**
     * Kolom kebijakan yang dipantau untuk riwayat perubahan.
     *
     * @var list<string>
     */
    public const KOLOM_AUDIT = [
        'nama_koperasi',
        'prefix_nomor_transaksi',
        'prefix_nomor_rekening',
        'prefix_nomor_jurnal',
        'prefix_nomor_pengeluaran_beban',
        'prefix_nomor_terminasi',
        'prefix_nomor_deposito',
        'limit_manajer',
        'limit_pembiayaan_manajer',
        'limit_penarikan_teller',
        'limit_setoran_teller',
        'teller_max_cash_limit',
        'minimum_transaksi_teller',
        'mandatory_principal_savings',
        'mandatory_monthly_savings',
        'minimum_pembukaan_rekening',
        'maksimum_pembukaan_rekening',
        'shu_hold_on_overdue',
        'shu_pct_cadangan_modal',
        'shu_pct_jasa_anggota',
        'shu_pct_jasa_modal',
        'shu_pct_dana_pendidikan',
        'limit_approval_beban',
        'minimal_pengajuan_pembiayaan',
        'kolektibilitas_kol1_hari',
        'kolektibilitas_kol2_hari',
        'kolektibilitas_kol3_hari',
        'kolektibilitas_kol4_hari',
        'metode_perhitungan_shu',
        'tazir_mode',
        'tazir_rate',
        'financing_late_fee_per_day',
        'tazir_grace_period_days',
        'tazir_default_method',
        'tazir_default_nominal',
        'deposito_rate_pa',
        'nisbah_default_persen',
        'margin_default_persen',
        'aml_threshold',
        'ltkt_threshold',
        'ppatk_jenis_tunai',
        'ppatk_nama_pelapor',
        'ppatk_kode_pelapor',
        'npwp_koperasi',
        'pembulatan_desimal',
        'toleransi_selisih_jurnal',
        'ckpn_persen_kol1',
        'ckpn_persen_kol2',
        'ckpn_persen_kol3',
        'ckpn_persen_kol4',
        'ckpn_persen_kol5',
        'account_kas_teller_id',
        'account_kas_brankas_id',
        'account_bank_koperasi_id',
        'account_piutang_murabahah_id',
        'account_piutang_mudharabah_id',
        'account_piutang_musyarakah_id',
        'account_piutang_ijarah_id',
        'account_piutang_qardh_id',
        'account_investasi_mudharabah_id',
        'account_investasi_musyarakah_id',
        'account_margin_deferred_id',
        'account_simpanan_sukarela_id',
        'account_dana_zakat_id',
        'account_dana_infak_id',
        'zakat_porsi_amil_persen',
        'hari_libur_aktif',
        'hari_libur_strategi',
        'tanggal_jatuh_tempo_default',
        'backup_retensi_hari',
        'tahun_buku_mulai_bulan',
        'jumlah_bulan_tahun_buku',
        'periode_cutoff_mode',
        'tutup_buku_sampai',
        'akad_margin',
        'akad_bagi_hasil',
        'akad_ujroh',
        'shu_jenis_partisipasi',
        'simpanan_wajib_kanal_default',
        'simpanan_wajib_boleh_ditarik',
        'simpanan_wajib_hari_penarikan',
        'account_simpanan_wajib_id',
        'simpanan_wajib_auto_debit_aktif',
        'simpanan_wajib_penanganan_dinamis',
        'simpanan_wajib_maks_cicilan',
        'simpanan_wajib_reschedule_bulan',
        'simpanan_wajib_maks_reschedule',
        'simpanan_wajib_notif_anonim',
        'simpanan_wajib_ambang_kurang_ringan',
        'jam_posting_simpanan_wajib',
        'akun_tazir_id',
        'posting_wajib_transaksi_sukses',
        'rekonsiliasi_inline_aktif',
        'zakat_rate',
        'zakat_nisab_gram',
        'harga_emas_per_gram',
        'zakat_minimal_tarik',
        'zakat_haul_aktif',
        'zakat_haul_hari',
        'zakat_ambang_persetujuan',
        'closure_admin_fee',
        'high_value_withdrawal_limit',
        'autodebit_wajib_konfirmasi',
        'toleransi_persen',
        'account_dana_kebajikan_id',
        'account_dana_sosial_id',
        'account_titipan_selisih_id',
    ];

    /**
     * Event model:
     *  - `saved`   → invalidasi cache Setting + catat riwayat perubahan.
     *  - `deleted` → invalidasi cache Setting.
     *
     * Dengan begitu nilai terbaru selalu tersedia real-time bagi Controller,
     * Service, maupun Scheduler tanpa restart aplikasi.
     */
    protected static function booted(): void
    {
        static::saved(function (self $kebijakan) {
            Setting::flush();

            try {
                $kebijakan->catatRiwayat();
            } catch (\Throwable $e) {
                // Riwayat bersifat audit tambahan — jangan ganggu transaksi utama.
                Log::warning(
                    'Gagal mencatat riwayat perubahan kebijakan: '.$e->getMessage()
                );
            }

            /*
             | Publikasikan peristiwa domain "kebijakan berubah".
             | Dikirim SETELAH cache dibersihkan & riwayat tercatat, dan
             | dibungkus try/catch: kegagalan listener tidak boleh
             | menggagalkan penyimpanan kebijakan (fail-safe, aditif).
             */
            try {
                KebijakanBerubah::dispatch(
                    collect(static::KOLOM_AUDIT)
                        ->filter(fn (string $kolom): bool => $kebijakan->isDirty($kolom))
                        ->values()
                        ->all(),
                    Auth::id(),
                );
            } catch (\Throwable $e) {
                Log::warning(
                    'Gagal mempublikasikan peristiwa KebijakanBerubah: '.$e->getMessage()
                );
            }
        });

        static::deleted(function () {
            Setting::flush();
        });
    }

    /**
     * Catat setiap kolom yang berubah ke `kebijakan_koperasi_logs`.
     * Hanya kolom pada KOLOM_AUDIT yang dipantau.
     */
    public function catatRiwayat(?string $keterangan = null): void
    {
        if (! Schema::hasTable('kebijakan_koperasi_logs')) {
            return;
        }

        $perubahan = [];

        foreach (static::KOLOM_AUDIT as $kolom) {
            if (! $this->isDirty($kolom) && $this->exists) {
                continue;
            }

            $lama = $this->getOriginal($kolom);
            $baru = $this->getAttribute($kolom);

            if ($this->exists && static::normalisasi($lama) === static::normalisasi($baru)) {
                continue;
            }

            $perubahan[$kolom] = [$lama, $baru];
        }

        if (empty($perubahan)) {
            return;
        }

        $user = Auth::user();
        $role = $user->role ?? null;
        $aksi = $this->wasRecentlyCreated ? 'CREATE' : 'UPDATE';
        $waktu = now();

        foreach ($perubahan as $kolom => [$lama, $baru]) {
            \DB::table('kebijakan_koperasi_logs')->insert([
                'user_id' => $user->id ?? null,
                'nama_user' => $user->name ?? 'Sistem',
                'role_user' => $role,
                'aksi' => $aksi,
                'kolom' => $kolom,
                'nilai_lama' => static::formatNilai($kolom, $lama),
                'nilai_baru' => static::formatNilai($kolom, $baru),
                'keterangan' => $keterangan ?? (static::LABEL_KOLOM[$kolom] ?? $kolom).' diperbarui',
                'ip_address' => Request::ip(),
                'created_at' => $waktu,
                'updated_at' => $waktu,
            ]);
        }
    }

    /** Riwayat perubahan terbaru (default 50 baris). */
    public static function riwayatPerubahan(int $limit = 50)
    {
        if (! Schema::hasTable('kebijakan_koperasi_logs')) {
            return collect();
        }

        return \DB::table('kebijakan_koperasi_logs')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** Normalkan nilai agar perbandingan 100000 vs "100000.00" tidak dianggap berubah. */
    private static function normalisasi(mixed $nilai): string
    {
        if ($nilai === null) {
            return '';
        }

        if (is_bool($nilai)) {
            return $nilai ? '1' : '0';
        }

        if (is_numeric($nilai)) {
            return rtrim(rtrim(number_format((float) $nilai, 2, '.', ''), '0'), '.') ?: '0';
        }

        return trim((string) $nilai);
    }

    /** Tampilan nilai untuk riwayat (nominal diformat rupiah, boolean Ya/Tidak). */
    private static function formatNilai(string $kolom, mixed $nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }

        if ($kolom === 'shu_hold_on_overdue') {
            return filter_var($nilai, FILTER_VALIDATE_BOOLEAN) ? 'Ya (ditahan)' : 'Tidak (dicairkan)';
        }

        if (str_contains($kolom, 'pct') || str_contains($kolom, 'persen')) {
            return number_format((float) $nilai, 2, ',', '.').'%';
        }

        if (str_contains($kolom, 'kolektibilitas')) {
            return number_format((float) $nilai, 0, ',', '.').' hari';
        }

        if (str_contains($kolom, 'limit')
            || str_contains($kolom, 'savings')
            || str_contains($kolom, 'pembukaan')
            || str_contains($kolom, 'rate')
            || str_contains($kolom, 'threshold')
            || str_contains($kolom, 'toleransi')) {
            return number_format((float) $nilai, 2, ',', '.');
        }

        return (string) $nilai;
    }

    /**
     * Dapatkan baris setting kebijakan aktif / fallback default.
     *
     * Selalu membaca nilai terbaru dari database (tanpa cache model) agar
     * pemanggil yang membutuhkan instance utuh — mis. form pengurus —
     * memperoleh data paling mutakhir.
     */
    public static function getSetting(): self
    {
        $tersimpan = static::first();

        if ($tersimpan !== null) {
            return $tersimpan;
        }

        // Fallback TIDAK diduplikasi di sini: seluruh nilai bawaan bersumber
        // dari Setting::DEFAULTS agar perubahan default hanya di satu tempat.
        // Kunci yang bukan kolom/fillable diabaikan oleh mass assignment.
        return new static(Setting::DEFAULTS);
    }

    /* ── Parameter bundling setoran awal ───────────────────────────────── */

    /**
     * Nominal Simpanan Pokok wajib (sekali bayar saat registrasi).
     * Sumber: `kebijakan_koperasis.mandatory_principal_savings`.
     */
    public static function simpananPokokWajib(): float
    {
        return Setting::simpananPokok();
    }

    /**
     * Nominal Simpanan Wajib per bulan (bulan ke-1 dibayar saat registrasi).
     * Sumber: `kebijakan_koperasis.mandatory_monthly_savings`.
     */
    public static function simpananWajibBulanan(): float
    {
        return Setting::simpananWajibBulanan();
    }

    /** Total setoran awal bundling = Simpanan Pokok + Simpanan Wajib bulan ke-1. */
    public static function totalSetoranAwal(): float
    {
        return Setting::totalSetoranAwal();
    }

    /** Kebijakan penahanan SHU bagi anggota yang menunggak Simpanan Wajib. */
    public static function tahanShuSaatMenunggak(): bool
    {
        return Setting::tahanShuSaatMenunggak();
    }

    /* ── Parameter batas nominal pembukaan rekening ─────────────────────── */

    /**
     * Batas bawah nominal pembukaan rekening yang diizinkan.
     * Sumber: `kebijakan_koperasis.minimum_pembukaan_rekening`.
     */
    public static function minimumPembukaanRekening(): float
    {
        return Setting::minimumPembukaanRekening();
    }

    /**
     * Batas atas nominal pembukaan rekening yang diizinkan.
     * Sumber: `kebijakan_koperasis.maksimum_pembukaan_rekening`.
     */
    public static function maksimumPembukaanRekening(): float
    {
        return Setting::maksimumPembukaanRekening();
    }

    /**
     * Batas nominal pembukaan rekening (minimum & maksimum) untuk dipakai
     * langsung pada aturan validasi seluruh jalur pembukaan rekening.
     *
     * @return array{minimum: float, maksimum: float}
     */
    public static function batasPembukaanRekening(): array
    {
        return Setting::batasPembukaanRekening();
    }

    /**
     * Aturan validasi nominal pembukaan rekening (dapat dikonfigurasi).
     *
     * @return array<int, string>
     */
    public static function aturanNominalPembukaan(bool $wajib = true): array
    {
        return Setting::aturanNominalPembukaan($wajib);
    }

    /**
     * Pesan error validasi nominal pembukaan rekening yang informatif.
     *
     * @return array<string, string>
     */
    public static function pesanValidasiNominalPembukaan(string $label = 'Nominal pembukaan rekening'): array
    {
        return Setting::pesanValidasiNominalPembukaan($label);
    }

    /**
     * Validasi nilai nominal pembukaan rekening secara terprogram
     * (dipakai jalur non-form, mis. service/batch import).
     *
     * @return string|null Pesan error bila tidak valid, null bila valid.
     */
    public static function validasiNominalPembukaan(mixed $nilai, string $label = 'Nominal pembukaan rekening'): ?string
    {
        return Setting::validasiNominalPembukaan($nilai, $label);
    }

    /* ── Alokasi SHU (dinamis) ─────────────────────────────────────────── */

    /**
     * Persentase alokasi SHU (kebijakan, 4 pos) yang berlaku saat ini.
     *
     * @deprecated Sejak perbaikan K-03, alokasi SHU yang DIDISTRIBUSIKAN
     *             adalah tabel `shu_periods` (5 pos). Nilai di sini hanya
     *             kebijakan/nilai awal — pakai `Setting::alokasiShuDrafDefault()`
     *             bila ingin mengisi draf SHU produksi.
     *
     * @return array<string, float>
     */
    public static function alokasiShu(): array
    {
        return Setting::alokasiShu();
    }

    /**
     * Nominal alokasi SHU per pos untuk total pendapatan tertentu.
     *
     * @deprecated Lihat catatan pada `alokasiShu()` — SSOT produksi adalah
     *             tabel `shu_periods`.
     *
     * @return array<string, float>
     */
    public static function hitungAlokasiShu(float $totalPendapatan): array
    {
        return Setting::hitungAlokasiShu($totalPendapatan);
    }

    /**
     * Total persentase alokasi SHU (kebijakan 4 pos; diharapkan 100).
     *
     * @deprecated Lihat catatan pada `alokasiShu()`.
     */
    public static function totalPersenAlokasiShu(): float
    {
        return Setting::totalPersenAlokasiShu();
    }

    /* ── Limit approval beban operasional (dinamis) ────────────────────── */

    /** Nominal di atas ini pengeluaran beban wajib approval Manajer. */
    public static function limitApprovalBeban(): float
    {
        return Setting::limitApprovalBeban();
    }

    /* ── Batas kas & transaksi teller (dinamis) ────────────────────────── */

    /** Batas maksimum kas fisik yang boleh mengendap di laci teller. */
    public static function tellerMaxCashLimit(): float
    {
        return Setting::tellerMaxCashLimit();
    }

    /** Nominal minimum transaksi tunai di teller (penarikan/setoran). */
    public static function minimumTransaksiTeller(): float
    {
        return Setting::minimumTransaksiTeller();
    }

    /* ── Parameter dinamis Simpanan Wajib (Tahap 12 / R1) ──────────────── */

    /** Kanal pembayaran default anggota baru: AUTO_DEBIT | CASH | TRANSFER. */
    public static function simpananWajibKanalDefault(): string
    {
        return Setting::simpananWajibKanalDefault();
    }

    /** Apakah penarikan saldo Simpanan Wajib diizinkan. */
    public static function simpananWajibBolehDitarik(): bool
    {
        return Setting::simpananWajibBolehDitarik();
    }

    /** Waktu penarikan diizinkan: SETELAH_KELUAR | KAPAN_SAJA. */
    public static function simpananWajibHariPenarikan(): string
    {
        return Setting::simpananWajibHariPenarikan();
    }

    /** ID akun CoA kanonik Simpanan Wajib (null = ikuti standar 3-1002). */
    public static function akunSimpananWajibId(): ?int
    {
        return Setting::akunSimpananWajibId();
    }

    /* ── Ambang kolektibilitas pembiayaan (dinamis) ────────────────────── */

    /**
     * Ambang hari tunggakan kolektibilitas (kol2 < kol3 < kol4).
     *
     * @return array{kol2: int, kol3: int, kol4: int}
     */
    public static function ambangKolektibilitas(): array
    {
        return Setting::ambangKolektibilitas();
    }

    /**
     * Kode kolektibilitas ('kol2'..'kol5') dari jumlah hari tunggakan.
     */
    public static function kolektibilitasDariHari(int $hariTunggakan): string
    {
        return Setting::kolektibilitasDariHari($hariTunggakan);
    }

    /* ── Normalisasi input nominal (terpusat) ──────────────────────────── */

    /**
     * Normalisasi input nominal berformat Indonesia/internasional menjadi float.
     */
    public static function parseNominal(mixed $nilai): float
    {
        return Setting::parseNominal($nilai);
    }
}
