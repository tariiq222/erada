<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase هـ — Task 5: حذف أعمدة Strategy FK المهجورة
 *
 * بعد بذر الأدوار السياقية (scoped_roles) في المرحلة ج/i، لم تعد هذه الأعمدة
 * مصدر قرار AuthZ. السياستان (ProgramPolicy/PortfolioPolicy) أصبحتا engine-only.
 *
 * الأعمدة المحذوفة:
 *  - programs.program_manager_id
 *  - programs.owner_id
 *  - programs.executive_sponsor_id
 *  - portfolios.portfolio_owner_id
 *
 * idempotent: محمي بـ Schema::hasColumn + فحص constraint_name مباشرة في pg_catalog.
 * down: يعيد الأعمدة nullable (بدون FK — الـ FK الأصلية كانت في migrations سابقة).
 */
return new class extends Migration
{
    /**
     * هل يوجد foreign key constraint باسم معين على جدول معين؟
     */
    private function constraintExists(string $table, string $constraintName): bool
    {
        $result = DB::selectOne(
            "SELECT 1 FROM information_schema.table_constraints
             WHERE table_schema = 'public'
               AND table_name = ?
               AND constraint_name = ?
               AND constraint_type = 'FOREIGN KEY'",
            [$table, $constraintName]
        );

        return $result !== null;
    }

    public function up(): void
    {
        // ─── programs ───────────────────────────────────────────
        Schema::table('programs', function (Blueprint $table) {
            // program_manager_id
            if (Schema::hasColumn('programs', 'program_manager_id')) {
                if ($this->constraintExists('programs', 'programs_program_manager_id_foreign')) {
                    $table->dropForeign(['program_manager_id']);
                }
                $table->dropColumn('program_manager_id');
            }

            // owner_id
            if (Schema::hasColumn('programs', 'owner_id')) {
                if ($this->constraintExists('programs', 'programs_owner_id_foreign')) {
                    $table->dropForeign(['owner_id']);
                }
                $table->dropColumn('owner_id');
            }

            // executive_sponsor_id
            if (Schema::hasColumn('programs', 'executive_sponsor_id')) {
                if ($this->constraintExists('programs', 'programs_executive_sponsor_id_foreign')) {
                    $table->dropForeign(['executive_sponsor_id']);
                }
                $table->dropColumn('executive_sponsor_id');
            }
        });

        // ─── portfolios ──────────────────────────────────────────
        Schema::table('portfolios', function (Blueprint $table) {
            if (Schema::hasColumn('portfolios', 'portfolio_owner_id')) {
                if ($this->constraintExists('portfolios', 'portfolios_portfolio_owner_id_foreign')) {
                    $table->dropForeign(['portfolio_owner_id']);
                }
                $table->dropColumn('portfolio_owner_id');
            }
        });
    }

    public function down(): void
    {
        // ─── programs ───────────────────────────────────────────
        Schema::table('programs', function (Blueprint $table) {
            if (! Schema::hasColumn('programs', 'program_manager_id')) {
                $table->unsignedBigInteger('program_manager_id')->nullable();
            }

            if (! Schema::hasColumn('programs', 'owner_id')) {
                $table->unsignedBigInteger('owner_id')->nullable();
            }

            if (! Schema::hasColumn('programs', 'executive_sponsor_id')) {
                $table->unsignedBigInteger('executive_sponsor_id')->nullable();
            }
        });

        // ─── portfolios ──────────────────────────────────────────
        Schema::table('portfolios', function (Blueprint $table) {
            if (! Schema::hasColumn('portfolios', 'portfolio_owner_id')) {
                $table->unsignedBigInteger('portfolio_owner_id')->nullable();
            }
        });
    }
};
