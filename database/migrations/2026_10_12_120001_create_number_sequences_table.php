<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            // purchase, sale, receipt, payment, transfer or journal
            $table->string('type', 12);
            // The number the next document will get.
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();

            // One counter per office and document type. The database enforces it,
            // so two requests creating a counter at once cannot make two.
            $table->unique(['branch_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};
