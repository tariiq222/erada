<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('authorization_role_assignments', 'source')) {
            Schema::table('authorization_role_assignments', function (Blueprint $table): void {
                $table->string('source', 16)->default('manual');
            });
        }

        if (! Schema::hasColumn('authorization_role_assignments', 'granted_by')) {
            Schema::table('authorization_role_assignments', function (Blueprint $table): void {
                $table->foreignId('granted_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                DO $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1
                        FROM pg_constraint
                        WHERE conname = 'authorization_role_assignments_source_check'
                          AND conrelid = 'authorization_role_assignments'::regclass
                    ) THEN
                        ALTER TABLE authorization_role_assignments
                        ADD CONSTRAINT authorization_role_assignments_source_check
                        CHECK (source IN ('manual', 'auto', 'migration'));
                    END IF;
                END
                $$
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql' && Schema::hasTable('authorization_role_assignments')) {
            DB::statement(
                'ALTER TABLE authorization_role_assignments '
                .'DROP CONSTRAINT IF EXISTS authorization_role_assignments_source_check'
            );
        }

        if (Schema::hasColumn('authorization_role_assignments', 'granted_by')) {
            Schema::table('authorization_role_assignments', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('granted_by');
            });
        }

        if (Schema::hasColumn('authorization_role_assignments', 'source')) {
            Schema::table('authorization_role_assignments', function (Blueprint $table): void {
                $table->dropColumn('source');
            });
        }
    }
};
