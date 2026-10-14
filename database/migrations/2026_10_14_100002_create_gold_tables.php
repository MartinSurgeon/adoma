<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The gold on a purchase or a sale: what was weighed and what it worked out to.
        Schema::create('gold_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->string('form', 8);                              // raw or refined
            $table->decimal('weight_g', 14, 2);
            $table->decimal('water_weight_g', 14, 2)->nullable();   // raw gold only
            $table->decimal('pounds', 14, 2);
            $table->decimal('density', 8, 2)->nullable();           // raw gold only
            $table->decimal('karat', 5, 2);
            $table->decimal('price', 18, 2);                        // per pound, at the base karat
            $table->decimal('amount', 18, 2);                       // whole cedis
            $table->foreignId('formula_set_id')->constrained('formula_sets')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique('document_id');
        });

        // A parcel of gold an office holds, from one purchase.
        Schema::create('stock_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('gold_line_id')->constrained('gold_lines')->restrictOnDelete();
            $table->date('date');
            $table->string('form', 8);
            $table->decimal('karat', 5, 2);
            $table->decimal('weight_g', 14, 2);          // what came in
            $table->decimal('remaining_g', 14, 2);       // what is left
            $table->decimal('cost', 18, 2);              // what it cost
            $table->decimal('remaining_cost', 18, 2);    // the cost of what is left
            $table->timestamps();

            $table->unique('gold_line_id');
            $table->index(['tenant_id', 'branch_id', 'remaining_g', 'date']);
        });

        // Every gram in or out of a lot. Never changed, never deleted.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('lot_id')->constrained('stock_lots')->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->decimal('weight_g', 14, 2);          // plus for gold in, minus for gold out
            $table->decimal('cost', 18, 2);              // the cost that moves with it
            $table->date('date');
            $table->timestamp('created_at')->useCurrent();

            $table->index('document_id');
            $table->index('lot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_lots');
        Schema::dropIfExists('gold_lines');
    }
};
