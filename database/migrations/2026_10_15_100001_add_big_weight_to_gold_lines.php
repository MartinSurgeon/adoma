<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Raw gold is weighed twice. The whole gold gives the pounds. With the particles taken out, which cannot be
        // measured in water, it is weighed again: that "big" weight goes with the water weight to give the density.
        // Empty means there were no particles, so the density used the whole weight, exactly as before.
        Schema::table('gold_lines', function (Blueprint $table) {
            $table->decimal('big_weight_g', 14, 2)->nullable()->after('weight_g');
        });
    }

    public function down(): void
    {
        Schema::table('gold_lines', function (Blueprint $table) {
            $table->dropColumn('big_weight_g');
        });
    }
};
