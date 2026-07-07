<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_expenses', function (Blueprint $table) {
            $table->boolean('is_finalized')->default(false)->after('attachment_path');
            $table->timestamp('finalized_at')->nullable()->after('is_finalized');
            $table->foreignId('finalized_by')->nullable()->after('finalized_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_expenses', function (Blueprint $table) {
            $table->dropForeign(['finalized_by']);
            $table->dropColumn(['is_finalized', 'finalized_at', 'finalized_by']);
        });
    }
};
