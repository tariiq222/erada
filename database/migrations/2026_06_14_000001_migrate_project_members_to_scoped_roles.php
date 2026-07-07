<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            if (! Schema::hasTable('project_members')) {
                return;
            }

            $conditions = [
                "pm.role IN ('member','viewer')",
                'pm.user_id IS DISTINCT FROM p.manager_id',
            ];

            if (Schema::hasColumn('projects', 'supervisor_id')) {
                $conditions[] = 'pm.user_id IS DISTINCT FROM p.supervisor_id';
            }

            if (Schema::hasColumn('projects', 'sponsor_id')) {
                $conditions[] = 'pm.user_id IS DISTINCT FROM p.sponsor_id';
            }

            if (Schema::hasColumn('project_members', 'deleted_at')) {
                $conditions[] = 'pm.deleted_at IS NULL';
            }

            $whereClause = implode("\n      AND ", $conditions);

            DB::statement(<<<SQL
                INSERT INTO model_has_scoped_roles (user_id, role, scope_type, scope_id, inherit_to_children, granted_by, expires_at, created_at, updated_at)
                SELECT pm.user_id,
                       CASE pm.role WHEN 'viewer' THEN 'viewer' ELSE 'member' END,
                       'project', pm.project_id, false, NULL, NULL, NOW(), NOW()
                FROM project_members pm
                JOIN projects p ON p.id = pm.project_id
                WHERE {$whereClause}
                  AND NOT EXISTS (
                    SELECT 1 FROM model_has_scoped_roles s
                    WHERE s.user_id = pm.user_id AND s.scope_type = 'project' AND s.scope_id = pm.project_id
                  )
                SQL);

            Schema::dropIfExists('project_members');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('project_members')) {
            return;
        }

        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('role', ['manager', 'member', 'viewer'])->default('member');
            $table->date('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'user_id']);
        });
    }
};
