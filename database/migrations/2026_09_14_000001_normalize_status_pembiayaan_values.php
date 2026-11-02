<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Alias / varian lama -> nilai kanonik (lowercase snake_case).
     * Disalin dari App\Models\Pembiayaan::STATUS_ALIASES agar migration
     * tetap deterministik meskipun model berubah di masa depan.
     */
    private const ALIASES = [
        'draft' => 'pending_ao',
        'pengajuan' => 'pending_ao',
        'pending' => 'pending_ao',
        'menunggu' => 'pending_ao',
        'dikaji' => 'direview',
        'review' => 'direview',
        'disetujui_manajer' => 'disetujui',
        'disetujui_pengurus' => 'disetujui',
        'npf' => 'macet',
        'terlambat' => 'macet',
        'bermasalah' => 'macet',
        'menunggu pencairan' => 'pending_pencairan',
        'menunggu_approval_restrukturisasi' => 'pending_restrukturisasi',
    ];

    public function up(): void
    {
        $distinct = DB::table('pembiayaan')->distinct()->pluck('status_pembiayaan');

        foreach ($distinct as $value) {
            if ($value === null) {
                continue;
            }

            $key = strtolower(trim($value));

            if ($key === '') {
                continue;
            }

            $canonical = self::ALIASES[$key] ?? $key;

            if ($canonical !== $value) {
                DB::table('pembiayaan')
                    ->where('status_pembiayaan', $value)
                    ->update(['status_pembiayaan' => $canonical]);
            }
        }
    }

    public function down(): void
    {
        // Tidak ada pembalikan: normalisasi bersifat idempoten dan satu arah.
    }
};
