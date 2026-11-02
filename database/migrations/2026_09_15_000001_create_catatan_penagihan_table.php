<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('catatan_penagihan')) {
            Schema::create('catatan_penagihan', function (Blueprint $table) {
                $table->id();
                $table->foreignId('angsuran_id')->constrained('angsuran')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('catatan');
                $table->timestamps();

                $table->index('angsuran_id');
            });
        }

        // Pindahkan catatan lama (kolom angsuran.catatan) ke riwayat agar tidak hilang.
        if (Schema::hasColumn('angsuran', 'catatan')) {
            $legacy = DB::table('angsuran')
                ->whereNotNull('catatan')
                ->where('catatan', '!=', '')
                ->get(['id', 'user_id', 'catatan', 'created_at', 'updated_at']);

            foreach ($legacy as $row) {
                $waktu = $row->updated_at ?? $row->created_at ?? now();

                DB::table('catatan_penagihan')->insert([
                    'angsuran_id' => $row->id,
                    'user_id' => $row->user_id,
                    'catatan' => $row->catatan,
                    'created_at' => $waktu,
                    'updated_at' => $waktu,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('catatan_penagihan');
    }
};
