<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
            // owner or admin see every office of the business. Null means an ordinary user
            // whose access comes only from the branch_user table.
            $table->string('tenant_role')->nullable()->after('tenant_id');
            $table->boolean('is_active')->default(true)->after('tenant_role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn(['tenant_role', 'is_active']);
        });
    }
};
