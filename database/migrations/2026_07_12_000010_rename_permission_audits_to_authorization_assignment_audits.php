<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give the preserved assignment history a canonical authorization name.
     *
     * Renaming instead of copying is intentional: every historical receipt and
     * primary key remains intact, while fresh installs still execute the legacy
     * schema migrations in their original order before reaching this cutover.
     */
    public function up(): void
    {
        if (Schema::hasTable('permission_audits') && ! Schema::hasTable('authorization_assignment_audits')) {
            Schema::rename('permission_audits', 'authorization_assignment_audits');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('authorization_assignment_audits') && ! Schema::hasTable('permission_audits')) {
            Schema::rename('authorization_assignment_audits', 'permission_audits');
        }
    }
};
