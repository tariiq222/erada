<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 9-B — Organization hierarchy schema (data-only).
     *
     * Phase 9-A (design audit) established that the authorization engine is
     * strict-equality everywhere — adding `parent_id` does NOT grant a parent
     * user visibility into child-org records. This migration is therefore
     * pure schema with no engine change.
     *
     * Adds to `organizations`:
     *   - parent_id BIGINT UNSIGNED NULL FK → organizations.id (ON DELETE RESTRICT)
     *   - type      VARCHAR(32) NOT NULL DEFAULT 'organization' + CHECK
     *               (cluster | hospital | center | organization | other)
     *   - sort_order INT NULL DEFAULT 0
     *   - CHECK (parent_id IS NULL OR parent_id <> id) — يمنع self-reference
     *   - INDEX (parent_id), INDEX (type), COMPOSITE INDEX (parent_id, type)
     *
     * Backward compatibility (Phase 9-A §5):
     *   - All existing rows get parent_id = NULL (root) and type = 'organization'.
     *   - The migration is idempotent — guarded by Schema::hasColumn + an
     *     information_schema check for the CHECK constraint, so re-runs on
     *     `migrate:fresh` or in tests are safe.
     *
     * NOT in scope for Phase 9-B (deferred to Phase 9-C / 9-D):
     *   - materialized `path`
     *   - computed `level`
     *   - cluster_tree (a separate authorization concept, NOT hierarchy)
     *   - settings inheritance
     *   - any engine or frontend change
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (! Schema::hasColumn('organizations', 'parent_id')) {
            Schema::table('organizations', function (Blueprint $table) {
                // self-FK على organizations (هيكلة: parent → child)
                // RESTRICT وليس nullOnDelete عمداً: لا يمكن حذف parent ولديه
                // children — يفرضه الـ controller أيضًا بـ 422 على children()->exists()
                $table->foreignId('parent_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('organizations')
                    ->restrictOnDelete();

                $table->index('parent_id', 'organizations_parent_id_idx');
            });
        }

        if (! Schema::hasColumn('organizations', 'type')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->string('type', 32)
                    ->default('organization')
                    ->after('code');

                $table->index('type', 'organizations_type_idx');
            });
        }

        if (! Schema::hasColumn('organizations', 'sort_order')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->integer('sort_order')
                    ->default(0)
                    ->after('is_active');
            });
        }

        // Composite index: queries "all hospitals under a cluster" become O(log n)
        if (! $this->indexExists('organizations', 'organizations_parent_type_idx')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->index(['parent_id', 'type'], 'organizations_parent_type_idx');
            });
        }

        // PostgreSQL CHECK constraints — guarded by information_schema so re-runs are safe.
        if ($driver === 'pgsql') {
            $this->ensureCheckConstraint(
                'organizations',
                'organizations_type_check',
                "type IN ('cluster','hospital','center','organization','other')"
            );
            $this->ensureCheckConstraint(
                'organizations',
                'organizations_parent_id_not_self_check',
                'parent_id IS NULL OR parent_id <> id'
            );
        }

        // Backfill: الصفوف الموجودة تحصل على type='organization' (default) و parent_id=NULL.
        // الـ default في DDL يضمن ذلك عند إضافة العمود، لكن نطبّق UPDATE صريح
        // للـ safety في حال الـ DB أُعيد من dump قديم أو أعيدت العملية.
        DB::statement("
            UPDATE organizations
               SET type = 'organization'
             WHERE type IS NULL OR type = ''
        ");
    }

    /**
     * Reverse the migrations.
     *
     * الترتيب مهم: نحذف الـ CHECK constraints قبل الـ indexes لتجنّب
     * dependent-object errors في PostgreSQL.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $this->dropCheckIfExists('organizations', 'organizations_parent_id_not_self_check');
            $this->dropCheckIfExists('organizations', 'organizations_type_check');
        }

        if ($this->indexExists('organizations', 'organizations_parent_type_idx')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->dropIndex('organizations_parent_type_idx');
            });
        }

        if (Schema::hasColumn('organizations', 'sort_order')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->dropColumn('sort_order');
            });
        }

        if (Schema::hasColumn('organizations', 'type')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->dropIndex('organizations_type_idx');
                $table->dropColumn('type');
            });
        }

        if (Schema::hasColumn('organizations', 'parent_id')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->dropForeign(['parent_id']);
                $table->dropIndex('organizations_parent_id_idx');
                $table->dropColumn('parent_id');
            });
        }
    }

    /**
     * هل الـ index موجود؟ (PostgreSQL فقط — للـ tests + migration re-runs)
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'pgsql') {
            // SQLite + MySQL: لا نطبق composite index idempotently — relies on Schema::hasColumn guards
            return false;
        }

        $database = DB::connection()->getDatabaseName();

        $result = DB::selectOne(
            'SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?',
            [$table, $indexName]
        );

        return $result !== null;
    }

    /**
     * إضافة CHECK constraint فقط إذا لم يكن موجوداً (PostgreSQL).
     */
    private function ensureCheckConstraint(string $table, string $constraintName, string $expression): void
    {
        $exists = DB::selectOne(
            "SELECT 1 FROM information_schema.table_constraints
              WHERE table_name = ? AND constraint_name = ? AND constraint_type = 'CHECK'",
            [$table, $constraintName]
        );

        if ($exists === null) {
            // الاسم يجب أن يكون غير محتاج quoting لأنه snake_case بدون keywords
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraintName} CHECK ({$expression})");
        }
    }

    /**
     * حذف CHECK constraint بأمان (PostgreSQL).
     */
    private function dropCheckIfExists(string $table, string $constraintName): void
    {
        $exists = DB::selectOne(
            "SELECT 1 FROM information_schema.table_constraints
              WHERE table_name = ? AND constraint_name = ? AND constraint_type = 'CHECK'",
            [$table, $constraintName]
        );

        if ($exists !== null) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraintName}");
        }
    }
};
