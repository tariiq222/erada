<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * إضافة organization_id لجداول وحدة Strategy السبعة.
     *
     * الترتيب الصارم للـ backfill (Pitfall 3 — الأبناء يقرؤون الآباء المملوءة):
     *   1. portfolios  — من portfolio_owner_id أو created_by → users.organization_id
     *   2. programs    — من created_by → users.organization_id
     *   3. blockers    — من الأب polymorphic (project/program/task)
     *   4. decisions   — من الأب polymorphic (project/program)
     *   5. reviews     — من الأب polymorphic (project/program/StrategicObjective→portfolio)
     *   6. strategic_kpis — من الأب polymorphic (program/StrategicObjective→portfolio)
     *   7. strategic_kpi_measurements — من kpi_id → strategic_kpis.organization_id
     *
     * الصفوف التي لا يمكن اشتقاق org لها تبقى NULL (deny-not-bypass — D-03).
     * لا يُستخدم SQLite — PostgreSQL فقط (CLAUDE.md).
     */
    public function up(): void
    {
        // ─── 1. portfolios ───────────────────────────────────────────────────
        Schema::table('portfolios', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')
                ->constrained('organizations')->nullOnDelete();
            $table->index('organization_id', 'portfolios_org_id_idx');
        });

        // ─── 2. programs ──────────────────────────────────────────────────────
        Schema::table('programs', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')
                ->constrained('organizations')->nullOnDelete();
            $table->index('organization_id', 'programs_org_id_idx');
        });

        // ─── 3. blockers ──────────────────────────────────────────────────────
        Schema::table('blockers', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')
                ->constrained('organizations')->nullOnDelete();
            $table->index('organization_id', 'blockers_org_id_idx');
        });

        // ─── 4. decisions ─────────────────────────────────────────────────────
        Schema::table('decisions', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')
                ->constrained('organizations')->nullOnDelete();
            $table->index('organization_id', 'decisions_org_id_idx');
        });

        // ─── 5. reviews ───────────────────────────────────────────────────────
        Schema::table('reviews', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')
                ->constrained('organizations')->nullOnDelete();
            $table->index('organization_id', 'reviews_org_id_idx');
        });

        // ─── 6. strategic_kpis ───────────────────────────────────────────────
        Schema::table('strategic_kpis', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')
                ->constrained('organizations')->nullOnDelete();
            $table->index('organization_id', 'strategic_kpis_org_id_idx');
        });

        // ─── 7. strategic_kpi_measurements ───────────────────────────────────
        Schema::table('strategic_kpi_measurements', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')
                ->constrained('organizations')->nullOnDelete();
            $table->index('organization_id', 'strategic_kpi_measurements_org_id_idx');
        });

        // ═══════════════════════════════════════════════════════════════════════
        // BACKFILL — ترتيب صارم: الأبناء بعد الآباء
        // ═══════════════════════════════════════════════════════════════════════

        // ─── Backfill 1: portfolios ───────────────────────────────────────────
        // المالك: COALESCE(portfolio_owner_id, created_by) → users.organization_id
        DB::statement('
            UPDATE portfolios p
            SET organization_id = (
                SELECT u.organization_id
                FROM users u
                WHERE u.id = COALESCE(p.portfolio_owner_id, p.created_by)
                LIMIT 1
            )
            WHERE p.organization_id IS NULL
        ');

        // ─── Backfill 2: programs ─────────────────────────────────────────────
        // المالك: created_by → users.organization_id
        DB::statement('
            UPDATE programs p
            SET organization_id = (
                SELECT u.organization_id
                FROM users u
                WHERE u.id = p.created_by
                LIMIT 1
            )
            WHERE p.organization_id IS NULL
        ');

        // ─── Backfill 3: blockers (بـ blockable_type) ─────────────────────────
        // الأنواع الممكنة (من BlockerController::getModelClass()):
        //   'project' → App\Modules\Projects\Models\Project
        //   'program' → App\Modules\Strategy\Models\Program
        //   'task'    → App\Modules\Tasks\Models\Task

        DB::statement("
            UPDATE blockers b
            SET organization_id = (
                SELECT p.organization_id
                FROM projects p
                WHERE p.id = b.blockable_id
            )
            WHERE b.blockable_type = 'App\\Modules\\Projects\\Models\\Project'
              AND b.organization_id IS NULL
        ");

        DB::statement("
            UPDATE blockers b
            SET organization_id = (
                SELECT p.organization_id
                FROM programs p
                WHERE p.id = b.blockable_id
            )
            WHERE b.blockable_type = 'App\\Modules\\Strategy\\Models\\Program'
              AND b.organization_id IS NULL
        ");

        DB::statement("
            UPDATE blockers b
            SET organization_id = (
                SELECT t.organization_id
                FROM tasks t
                WHERE t.id = b.blockable_id
            )
            WHERE b.blockable_type = 'App\\Modules\\Tasks\\Models\\Task'
              AND b.organization_id IS NULL
        ");

        // ─── Backfill 4: decisions (بـ decidable_type) ────────────────────────
        // الأنواع الممكنة (من DecisionController::getModelClass()):
        //   'project' → App\Modules\Projects\Models\Project
        //   'program' → App\Modules\Strategy\Models\Program

        DB::statement("
            UPDATE decisions d
            SET organization_id = (
                SELECT p.organization_id
                FROM projects p
                WHERE p.id = d.decidable_id
            )
            WHERE d.decidable_type = 'App\\Modules\\Projects\\Models\\Project'
              AND d.organization_id IS NULL
        ");

        DB::statement("
            UPDATE decisions d
            SET organization_id = (
                SELECT p.organization_id
                FROM programs p
                WHERE p.id = d.decidable_id
            )
            WHERE d.decidable_type = 'App\\Modules\\Strategy\\Models\\Program'
              AND d.organization_id IS NULL
        ");

        // ─── Backfill 5: reviews (بـ reviewable_type) ─────────────────────────
        // الأنواع الممكنة (من ReviewController::getModelClass()):
        //   'project'   → App\Modules\Projects\Models\Project
        //   'program'   → App\Modules\Strategy\Models\Program
        //   'objective' → App\Modules\Strategy\Models\StrategicObjective
        //
        // ملاحظة: جدول strategic_objectives حُذف في migration 2026_01_16_200003.
        // البيانات أُرشفت في archived_strategic_objectives (مع portfolio_id).
        // الـ backfill يستخدم 2-hop: archived_strategic_objectives.portfolio_id → portfolios.organization_id

        DB::statement("
            UPDATE reviews r
            SET organization_id = (
                SELECT p.organization_id
                FROM projects p
                WHERE p.id = r.reviewable_id
            )
            WHERE r.reviewable_type = 'App\\Modules\\Projects\\Models\\Project'
              AND r.organization_id IS NULL
        ");

        DB::statement("
            UPDATE reviews r
            SET organization_id = (
                SELECT p.organization_id
                FROM programs p
                WHERE p.id = r.reviewable_id
            )
            WHERE r.reviewable_type = 'App\\Modules\\Strategy\\Models\\Program'
              AND r.organization_id IS NULL
        ");

        DB::statement("
            UPDATE reviews r
            SET organization_id = (
                SELECT po.organization_id
                FROM archived_strategic_objectives aso
                JOIN portfolios po ON po.id = aso.portfolio_id
                WHERE aso.original_id = r.reviewable_id
            )
            WHERE r.reviewable_type = 'App\\Modules\\Strategy\\Models\\StrategicObjective'
              AND r.organization_id IS NULL
        ");

        // ─── Backfill 6: strategic_kpis (بـ measurable_type) ─────────────────
        // الأنواع الممكنة (من StrategicKpiController::getModelClass()):
        //   'objective' → App\Modules\Strategy\Models\StrategicObjective (حُذفت في 200003)
        //   'program'   → App\Modules\Strategy\Models\Program
        //
        // ملاحظة: KPIs المرتبطة بـ StrategicObjective حُذفت في migration 200003.
        // هذا الـ UPDATE آمن (لا صفوف تنطبق) لكنه يبقى للاتساق مع بيانات قديمة.

        DB::statement("
            UPDATE strategic_kpis k
            SET organization_id = (
                SELECT p.organization_id
                FROM programs p
                WHERE p.id = k.measurable_id
            )
            WHERE k.measurable_type = 'App\\Modules\\Strategy\\Models\\Program'
              AND k.organization_id IS NULL
        ");

        DB::statement("
            UPDATE strategic_kpis k
            SET organization_id = (
                SELECT po.organization_id
                FROM archived_strategic_objectives aso
                JOIN portfolios po ON po.id = aso.portfolio_id
                WHERE aso.original_id = k.measurable_id
            )
            WHERE k.measurable_type = 'App\\Modules\\Strategy\\Models\\StrategicObjective'
              AND k.organization_id IS NULL
        ");

        // ─── Backfill 7: strategic_kpi_measurements (LAST) ───────────────────
        // يعتمد على strategic_kpis.organization_id (يجب أن يكون ممتلئاً الآن)
        DB::statement('
            UPDATE strategic_kpi_measurements m
            SET organization_id = (
                SELECT k.organization_id
                FROM strategic_kpis k
                WHERE k.id = m.kpi_id
            )
            WHERE m.organization_id IS NULL
        ');
    }

    /**
     * Reverse the migrations.
     *
     * الحذف بترتيب عكسي (الأبناء قبل الآباء).
     */
    public function down(): void
    {
        Schema::table('strategic_kpi_measurements', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });

        Schema::table('strategic_kpis', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });

        Schema::table('decisions', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });

        Schema::table('blockers', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });

        Schema::table('programs', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });

        Schema::table('portfolios', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });
    }
};
