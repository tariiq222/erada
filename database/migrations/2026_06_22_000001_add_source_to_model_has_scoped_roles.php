<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_has_scoped_roles', function (Blueprint $table) {
            // 'auto'   = granted by the department capacity-role automation
            // 'manual' = granted explicitly by an admin (deputy, ad-hoc delegation)
            $table->string('source', 10)->default('manual')->after('granted_by');
            // Index leads with the most-selective column for the hot queries
            // (syncUser filters user_id+scope_type+source; reconcile filters by scope).
            $table->index(['user_id', 'scope_type', 'source'], 'idx_user_scope_source');
        });
    }

    public function down(): void
    {
        Schema::table('model_has_scoped_roles', function (Blueprint $table) {
            $table->dropIndex('idx_user_scope_source');
            $table->dropColumn('source');
        });
    }
};
