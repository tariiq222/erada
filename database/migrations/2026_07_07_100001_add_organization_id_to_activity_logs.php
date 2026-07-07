<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add organization_id to activity_logs for tenant isolation.
 *
 * Additive only: nullable column, no default. Legacy rows remain valid until
 * the backfill artisan command runs. Indexes are added one-at-a-time so a
 * single failure does not abort the column add.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('activity_logs', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->after('target_user_id')
                    ->constrained('organizations')->nullOnDelete();
            }
        });

        try {
            Schema::table('activity_logs', function (Blueprint $table) {
                $table->index('organization_id', 'activity_logs_organization_id_idx');
            });
        } catch (Exception $e) {
            // Index already exists.
        }

        try {
            Schema::table('activity_logs', function (Blueprint $table) {
                $table->index(['organization_id', 'created_at'], 'activity_logs_org_created_at_idx');
            });
        } catch (Exception $e) {
            // Index already exists.
        }

        try {
            Schema::table('activity_logs', function (Blueprint $table) {
                $table->index(['organization_id', 'user_id'], 'activity_logs_org_user_id_idx');
            });
        } catch (Exception $e) {
            // Index already exists.
        }

        try {
            Schema::table('activity_logs', function (Blueprint $table) {
                $table->index(['organization_id', 'loggable_type', 'loggable_id'], 'activity_logs_org_loggable_idx');
            });
        } catch (Exception $e) {
            // Index already exists.
        }
    }

    public function down(): void
    {
        // Drop indexes first.
        foreach ([
            'activity_logs_org_loggable_idx',
            'activity_logs_org_user_id_idx',
            'activity_logs_org_created_at_idx',
            'activity_logs_organization_id_idx',
        ] as $indexName) {
            try {
                Schema::table('activity_logs', function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                });
            } catch (Exception $e) {
                // Index didn't exist.
            }
        }

        Schema::table('activity_logs', function (Blueprint $table) {
            if (Schema::hasColumn('activity_logs', 'organization_id')) {
                try {
                    $table->dropForeign(['organization_id']);
                } catch (Exception $e) {
                }
                $table->dropColumn('organization_id');
            }
        });
    }
};