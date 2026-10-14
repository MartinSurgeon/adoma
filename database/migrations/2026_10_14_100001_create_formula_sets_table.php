<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formula_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Versions only go up. A change makes a new version, so old documents keep what they were saved with.
            $table->unsignedInteger('version');
            // The constants from the client's Excel sheet.
            $table->decimal('grams_per_pound', 8, 2);     // 7.75: grams in one "pound" of gold
            $table->decimal('density_offset', 8, 2);      // 10.51: used in the karat step
            $table->decimal('karat_factor', 10, 3);       // 52.838: used in the karat step
            $table->unsignedSmallInteger('karat_base');   // 23: the karat the price is quoted at
            $table->date('effective_from');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formula_sets');
    }
};
