<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Karat is a share of 24, so a figure above it is normally a mistake in the weights. An admin can allow it, for a
        // second opinion. Who allowed it and why stays with the gold line, which never changes.
        Schema::table('gold_lines', function (Blueprint $table) {
            $table->string('karat_override_reason', 300)->nullable()->after('karat');
            $table->foreignId('karat_overridden_by')->nullable()->after('karat_override_reason')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('gold_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('karat_overridden_by');
            $table->dropColumn('karat_override_reason');
        });
    }
};
