<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (! Schema::hasColumn('kebijakan_koperasis', 'limit_manajer')) {
                $table->decimal('limit_manajer', 15, 2)->default(50000000)->after('id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (Schema::hasColumn('kebijakan_koperasis', 'limit_manajer')) {
                $table->dropColumn('limit_manajer');
            }
        });
    }
};
