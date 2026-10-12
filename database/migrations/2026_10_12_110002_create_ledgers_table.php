<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // An office ledger belongs to one office. A shared ledger has no office
            // and every office can use it (Sales, Gold Stock, the other offices' accounts).
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_shared')->default(false);
            $table->foreignId('group_id')->constrained('ledger_groups')->restrictOnDelete();
            // customer, supplier, bank, cash, expense, branch or account
            $table->string('kind', 12);
            $table->string('name', 120);
            $table->string('phone', 30)->nullable();
            // For kind "branch": the office this account stands for.
            $table->foreignId('linked_branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            // A fixed key so posting code can find Gold Stock, Sales and Cost of Sales.
            $table->string('system_key', 40)->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'system_key']);
            $table->unique(['tenant_id', 'linked_branch_id']);
            $table->index(['tenant_id', 'branch_id', 'kind']);
            $table->index(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledgers');
    }
};
