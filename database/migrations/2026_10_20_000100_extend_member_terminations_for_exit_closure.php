<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PERLUASAN MODUL EXIT KEANGGOTAAN menjadi PENUTUPAN SIMPANAN (member exit &
 * closure) — tanpa membuat tabel `member_exits` baru.
 *
 * Kolom baru pada `member_terminations` menampung rincian seluruh komponen
 * simpanan yang dicairkan (Sukarela/Wadiah & Berjangka/Deposito), akrual bagi
 * hasil proporsional harian, potongan zakat, biaya administrasi penutupan CIF,
 * total net yang diterima anggota, serta jejak approval Manajer untuk
 * pencairan bernilai tinggi.
 *
 * Nomor referensi dokumen resmi (`EXIT/YYYYMM/000001`) disimpan terpisah pada
 * `no_referensi` agar format `no_terminasi` lama (`TRM-...`) tetap kompatibel
 * dengan data & test yang sudah ada (P4 Default Aman).
 *
 * Tabel `exit_approval_logs` mencatat riwayat persetujuan/penolakan Manajer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('member_terminations')) {
            Schema::table('member_terminations', function (Blueprint $table) {
                if (! Schema::hasColumn('member_terminations', 'no_referensi')) {
                    $table->string('no_referensi', 40)->nullable()->unique()
                        ->after('no_terminasi')
                        ->comment('Nomor referensi dokumen penutupan: EXIT/YYYYMM/NNNNNN.');
                }

                if (! Schema::hasColumn('member_terminations', 'nominal_sukarela')) {
                    $table->decimal('nominal_sukarela', 15, 2)->default(0)->after('nominal_simpanan_wajib')
                        ->comment('Saldo Simpanan Sukarela/Wadiah yang dicairkan.');
                }

                if (! Schema::hasColumn('member_terminations', 'nominal_deposito')) {
                    $table->decimal('nominal_deposito', 15, 2)->default(0)->after('nominal_sukarela')
                        ->comment('Saldo Simpanan Berjangka/Deposito jatuh tempo yang dicairkan.');
                }

                if (! Schema::hasColumn('member_terminations', 'nominal_akrual_bagi_hasil')) {
                    $table->decimal('nominal_akrual_bagi_hasil', 15, 2)->default(0)->after('nominal_deposito')
                        ->comment('Akrual hak bagi hasil proporsional sampai tanggal pengunduran diri.');
                }

                if (! Schema::hasColumn('member_terminations', 'nominal_zakat')) {
                    $table->decimal('nominal_zakat', 15, 2)->default(0)->after('nominal_akrual_bagi_hasil')
                        ->comment('Potongan zakat yang menjadi kewajiban Dana Zakat (2-3002).');
                }

                if (! Schema::hasColumn('member_terminations', 'nominal_biaya_admin')) {
                    $table->decimal('nominal_biaya_admin', 15, 2)->default(0)->after('nominal_zakat')
                        ->comment('Biaya administrasi penutupan CIF (parameter closure_admin_fee).');
                }

                if (! Schema::hasColumn('member_terminations', 'total_net')) {
                    $table->decimal('total_net', 15, 2)->default(0)->after('nominal_biaya_admin')
                        ->comment('Total net yang diterima anggota setelah zakat & biaya admin.');
                }

                if (! Schema::hasColumn('member_terminations', 'butuh_approval')) {
                    $table->boolean('butuh_approval')->default(false)->after('total_net')
                        ->comment('True bila total net melebihi high_value_withdrawal_limit.');
                }

                if (! Schema::hasColumn('member_terminations', 'approved_at')) {
                    $table->timestamp('approved_at')->nullable()->after('butuh_approval')
                        ->comment('Waktu persetujuan Manajer atas pencairan nilai tinggi.');
                }

                if (! Schema::hasColumn('member_terminations', 'exit_reason')) {
                    $table->text('exit_reason')->nullable()->after('approved_at')
                        ->comment('Alasan pengunduran diri yang diinput operator.');
                }
            });
        }

        if (! Schema::hasTable('exit_approval_logs')) {
            Schema::create('exit_approval_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('member_termination_id')->constrained('member_terminations')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('aksi', 20)->default('APPROVED')->comment('APPROVED / REJECTED.');
                $table->text('catatan')->nullable();
                $table->timestamps();

                $table->index(['member_termination_id', 'created_at'], 'exit_approval_logs_termination_waktu_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exit_approval_logs');

        if (Schema::hasTable('member_terminations')) {
            Schema::table('member_terminations', function (Blueprint $table) {
                foreach ([
                    'exit_reason', 'approved_at', 'butuh_approval', 'total_net',
                    'nominal_biaya_admin', 'nominal_zakat', 'nominal_akrual_bagi_hasil',
                    'nominal_deposito', 'nominal_sukarela',
                ] as $kolom) {
                    if (Schema::hasColumn('member_terminations', $kolom)) {
                        $table->dropColumn($kolom);
                    }
                }

                if (Schema::hasColumn('member_terminations', 'no_referensi')) {
                    $table->dropUnique(['no_referensi']);
                    $table->dropColumn('no_referensi');
                }
            });
        }
    }
};
