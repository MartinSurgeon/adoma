<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A transfer between branches is a sale, so the gold that leaves one branch is priced. This is what that
        // slice of gold was sold for, and what it will cost the branch that accepts it. Empty on everything else,
        // and on transfers made before they were priced, which moved at cost.
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('value', 18, 2)->nullable()->after('cost');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn('value');
        });
    }
};
