<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            // purchase, sale, receipt, payment, transfer or journal
            $table->string('type', 12);
            // The voucher number, such as REC-KUM-000042.
            $table->string('number', 32);
            $table->date('date');
            // The other party: who paid us, or whom we paid.
            $table->foreignId('ledger_id')->nullable()->constrained('ledgers')->restrictOnDelete();
            // The cash or bank account the money went into or out of.
            $table->foreignId('cash_ledger_id')->nullable()->constrained('ledgers')->restrictOnDelete();
            $table->decimal('total', 18, 2);
            $table->text('note')->nullable();
            // posted, or reversed once a reversing document has been posted against it.
            $table->string('status', 12)->default('posted');
            // Set on a reversing document: the document it undoes.
            $table->foreignId('reverses_id')->nullable()->constrained('documents')->restrictOnDelete();
            // Sent with the form, so saving twice (a double click, a retry) posts once.
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->useCurrent();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->unique('reverses_id'); // a document can be reversed only once
            $table->index(['tenant_id', 'branch_id', 'date']);
            $table->index(['tenant_id', 'type', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
