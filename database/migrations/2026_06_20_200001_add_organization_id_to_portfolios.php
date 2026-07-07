<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // idempotent: column may already exist from a prior migration
        if (! Schema::hasColumn('portfolios', 'organization_id')) {
            Schema::table('portfolios', function (Blueprint $table) {
                $table->foreignId('organization_id')
                    ->nullable()
                    ->after('created_by')
                    ->constrained('organizations')
                    ->nullOnDelete();
                $table->index('organization_id', 'portfolios_org_id_idx');
            });
        }

        // Backfill: derive org from the portfolio_owner (user.organization_id)
        // Only for rows where organization_id is still null
        DB::statement('
            UPDATE portfolios p
            SET organization_id = u.organization_id
            FROM users u
            WHERE p.portfolio_owner_id = u.id
              AND p.organization_id IS NULL
              AND u.organization_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        if (Schema::hasColumn('portfolios', 'organization_id')) {
            Schema::table('portfolios', function (Blueprint $table) {
                try {
                    $table->dropIndex('portfolios_org_id_idx');
                } catch (Throwable) {
                }

                try {
                    $table->dropForeign(['organization_id']);
                } catch (Throwable) {
                }

                $table->dropColumn('organization_id');
            });
        }
    }
};
