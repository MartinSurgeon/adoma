<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A transfer is two documents, one in each office's books. The receiving one points at the sending one,
        // so both halves can always be found from either side, and each office's books balance on their own.
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('paired_id')->nullable()->after('reverses_id')->constrained('documents')->restrictOnDelete();
            $table->unique('paired_id');
        });

        // Gold that arrives by transfer becomes a lot in the receiving office. It has no purchase of its own,
        // so it remembers the lot it came from and the document that brought it.
        Schema::table('stock_lots', function (Blueprint $table) {
            $table->unsignedBigInteger('gold_line_id')->nullable()->change();
            $table->foreignId('source_lot_id')->nullable()->after('gold_line_id')->constrained('stock_lots')->restrictOnDelete();
            $table->foreignId('document_id')->nullable()->after('source_lot_id')->constrained('documents')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_lots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_id');
            $table->dropConstrainedForeignId('source_lot_id');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique(['paired_id']);
            $table->dropConstrainedForeignId('paired_id');
        });
    }
};
