<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The client's sheet takes 0.16 off the karat before working out what a supplier is paid ("Karat (-0.7%)"). It is a
 * constant of their formula, so it lives with the others, and arrives as a new formula version: purchases already saved
 * keep version 1, which had no deduction, and new ones use version 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('formula_sets', function (Blueprint $table) {
            // Karat taken off before a purchase is priced. 0 on the versions that came before it.
            $table->decimal('karat_deduction', 5, 2)->default(0)->after('karat_base');
        });

        Schema::table('gold_lines', function (Blueprint $table) {
            // The karat a purchase was paid at (karat less the deduction). Null for sales and for older purchases.
            $table->decimal('paid_karat', 5, 2)->nullable()->after('karat');
        });

        $latest = DB::table('formula_sets')->select('tenant_id', DB::raw('MAX(version) as version'))->groupBy('tenant_id')->get();

        foreach ($latest as $row) {
            $current = DB::table('formula_sets')->where('tenant_id', $row->tenant_id)->where('version', $row->version)->first();

            DB::table('formula_sets')->insert([
                'tenant_id' => $current->tenant_id,
                'version' => $current->version + 1,
                'grams_per_pound' => $current->grams_per_pound,
                'density_offset' => $current->density_offset,
                'karat_factor' => $current->karat_factor,
                'karat_base' => $current->karat_base,
                'karat_deduction' => '0.16',
                'effective_from' => now()->toDateString(),
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Versions that carry a deduction are removed only when nothing was saved with them.
        $used = DB::table('gold_lines')->pluck('formula_set_id')->unique();
        DB::table('formula_sets')->where('karat_deduction', '>', 0)->whereNotIn('id', $used)->delete();

        Schema::table('gold_lines', fn (Blueprint $table) => $table->dropColumn('paid_karat'));
        Schema::table('formula_sets', fn (Blueprint $table) => $table->dropColumn('karat_deduction'));
    }
};
