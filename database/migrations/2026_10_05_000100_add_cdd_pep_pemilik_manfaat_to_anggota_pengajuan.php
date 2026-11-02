<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OJK-08 — Customer Due Diligence (CDD), Politically Exposed Person (PEP),
 * dan Pemilik Manfaat (beneficial owner) pada onboarding anggota.
 *
 * Dasar hukum:
 * - POJK No. 27/POJK.03/2022 tentang Penerapan Program Anti Pencucian Uang,
 *   Pencegahan Pendanaan Terorisme, dan Pencegahan Pendanaan Proliferasi
 *   Senjata Pemusnah Massal bagi Lembaga Jasa Keuangan.
 * - UU No. 8/2010 tentang Pencegahan dan Pemberantasan TPPU (Pasal 18 —
 *   kewajiban mengenali pemilik manfaat).
 * - Perpres No. 13/2018 tentang Penerapan Prinsip Mengenali Pemilik Manfaat
 *   dari Korporasi.
 *
 * Kolom ditambahkan ke DUA tabel:
 * 1. `anggota` — data CDD final milik anggota aktif (hasil verifikasi).
 * 2. `pengajuan_rekening` — pasangan entry form agar nilai dari nasabah
 *    terbawa utuh sampai anggota dibuat (tanpa input ulang).
 *
 * Semua kolom nullable agar tidak memutus alur onboarding yang sudah berjalan;
 * kebijakan "wajib/tidak" ditegakkan di lapisan validasi (form request).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anggota', function (Blueprint $table) {
            if (! Schema::hasColumn('anggota', 'cdd_jenis')) {
                // Jenis verifikasi identitas: 'Sederhana' (CDD) atau
                // 'Mendalam' (Enhanced Due Diligence / EDD). Null = belum di-CDD.
                $table->string('cdd_jenis', 20)->nullable()->after('nama_ibu_kandung');
            }
            if (! Schema::hasColumn('anggota', 'cdd_verified_at')) {
                // Waktu verifikasi identitas diselesaikan.
                $table->timestamp('cdd_verified_at')->nullable()->after('cdd_jenis');
            }
            if (! Schema::hasColumn('anggota', 'cdd_verified_by')) {
                // Petugas yang memverifikasi identitas (jejak akuntabilitas).
                $table->unsignedBigInteger('cdd_verified_by')->nullable()->after('cdd_verified_at');
            }
            if (! Schema::hasColumn('anggota', 'pep_status')) {
                // PEP: NULL (belum dinilai) | 'Bukan PEP' | 'PEP'.
                // Disimpan sebagai string (bukan boolean) agar dapat dibedakan
                // antara "belum dinilai" dan "sudah dinilai: bukan PEP".
                $table->string('pep_status', 20)->nullable()->after('cdd_verified_by');
            }
            if (! Schema::hasColumn('anggota', 'pep_keterangan')) {
                // Jabatan/relasi PEP (wajib bila pep_status = 'PEP').
                $table->string('pep_keterangan', 255)->nullable()->after('pep_status');
            }
            if (! Schema::hasColumn('anggota', 'pemilik_manfaat_nama')) {
                // Nama pemilik manfaat sebenarnya (beneficial owner).
                $table->string('pemilik_manfaat_nama', 255)->nullable()->after('pep_keterangan');
            }
            if (! Schema::hasColumn('anggota', 'pemilik_manfaat_hubungan')) {
                // Hubungan pemilik manfaat dengan anggota (mis. "suami", "direktur").
                $table->string('pemilik_manfaat_hubungan', 100)->nullable()->after('pemilik_manfaat_nama');
            }
            if (! Schema::hasColumn('anggota', 'pemilik_manfaat_keterangan')) {
                // Catatan tambahan atas pemilik manfaat.
                $table->text('pemilik_manfaat_keterangan')->nullable()->after('pemilik_manfaat_hubungan');
            }
            if (! Schema::hasColumn('anggota', 'profil_risiko')) {
                // Profil risiko nasabah (POJK 27/2022): 'Rendah' | 'Sedang' | 'Tinggi'.
                $table->string('profil_risiko', 20)->nullable()->after('pemilik_manfaat_keterangan');
            }
        });

        Schema::table('pengajuan_rekening', function (Blueprint $table) {
            if (! Schema::hasColumn('pengajuan_rekening', 'pep_status')) {
                $table->string('pep_status', 20)->nullable()->after('pekerjaan');
            }
            if (! Schema::hasColumn('pengajuan_rekening', 'pep_keterangan')) {
                $table->string('pep_keterangan', 255)->nullable()->after('pep_status');
            }
            if (! Schema::hasColumn('pengajuan_rekening', 'pemilik_manfaat_nama')) {
                $table->string('pemilik_manfaat_nama', 255)->nullable()->after('pep_keterangan');
            }
            if (! Schema::hasColumn('pengajuan_rekening', 'pemilik_manfaat_hubungan')) {
                $table->string('pemilik_manfaat_hubungan', 100)->nullable()->after('pemilik_manfaat_nama');
            }
        });
    }

    public function down(): void
    {
        Schema::table('anggota', function (Blueprint $table) {
            foreach ([
                'profil_risiko', 'pemilik_manfaat_keterangan', 'pemilik_manfaat_hubungan',
                'pemilik_manfaat_nama', 'pep_keterangan', 'pep_status',
                'cdd_verified_by', 'cdd_verified_at', 'cdd_jenis',
            ] as $kolom) {
                if (Schema::hasColumn('anggota', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });

        Schema::table('pengajuan_rekening', function (Blueprint $table) {
            foreach ([
                'pemilik_manfaat_hubungan', 'pemilik_manfaat_nama',
                'pep_keterangan', 'pep_status',
            ] as $kolom) {
                if (Schema::hasColumn('pengajuan_rekening', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
