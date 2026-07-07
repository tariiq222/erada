<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * تحويل جدول initiatives إلى programs:
     * 1. إضافة portfolio_id
     * 2. نقل البيانات من objective.portfolio_id إلى initiative.portfolio_id
     * 3. إضافة الحقول الجديدة للـ Program
     * 4. حذف objective_id
     * 5. إعادة تسمية الجدول
     * 6. تغيير code prefix من INI- إلى PRG-
     */
    public function up(): void
    {
        // إذا كان جدول programs موجود بالفعل، نتحقق من الأعمدة فقط
        if (Schema::hasTable('programs')) {
            // إضافة الأعمدة الناقصة إن وجدت
            $this->addMissingColumnsToPrograms();

            return;
        }

        // إذا لم يكن جدول initiatives موجود، لا شيء للتحويل
        if (! Schema::hasTable('initiatives')) {
            return;
        }

        // الخطوة 1: إضافة عمود portfolio_id (nullable مؤقتاً)
        // فحص إذا كانت الأعمدة موجودة مسبقاً (في حالة إعادة تشغيل الـ migration)
        if (! Schema::hasColumn('initiatives', 'portfolio_id')) {
            Schema::table('initiatives', function (Blueprint $table) {
                $table->foreignId('portfolio_id')
                    ->nullable()
                    ->after('objective_id')
                    ->constrained('portfolios')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('initiatives', 'program_manager_id')) {
            Schema::table('initiatives', function (Blueprint $table) {
                $table->foreignId('program_manager_id')
                    ->nullable()
                    ->after('owner_id')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('initiatives', 'executive_sponsor_id')) {
            Schema::table('initiatives', function (Blueprint $table) {
                $table->foreignId('executive_sponsor_id')
                    ->nullable()
                    ->after('program_manager_id')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('initiatives', 'total_program_budget')) {
            Schema::table('initiatives', function (Blueprint $table) {
                $table->decimal('total_program_budget', 15, 2)
                    ->nullable()
                    ->after('budget');
            });
        }

        if (! Schema::hasColumn('initiatives', 'progress_calculation_method')) {
            Schema::table('initiatives', function (Blueprint $table) {
                $table->string('progress_calculation_method')
                    ->default('average')
                    ->after('progress');
            });
        }

        // الخطوة 2: نقل البيانات من objective.portfolio_id إلى initiative.portfolio_id
        // استخدام صيغة متوافقة مع SQLite
        if (Schema::hasColumn('initiatives', 'objective_id') && Schema::hasTable('strategic_objectives')) {
            DB::statement('
                UPDATE initiatives
                SET portfolio_id = (
                    SELECT strategic_objectives.portfolio_id
                    FROM strategic_objectives
                    WHERE strategic_objectives.id = initiatives.objective_id
                )
                WHERE objective_id IS NOT NULL
            ');
        }

        // الخطوة 3: حذف FK وعمود objective_id
        if (Schema::hasColumn('initiatives', 'objective_id')) {
            if (DB::getDriverName() === 'sqlite') {
                // SQLite: تعطيل الـ foreign keys مؤقتاً ثم إعادة بناء الجدول
                DB::statement('PRAGMA foreign_keys = OFF');

                // إنشاء جدول مؤقت بدون objective_id
                DB::statement('
                    CREATE TABLE initiatives_new (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        code VARCHAR NOT NULL,
                        name VARCHAR NOT NULL,
                        description TEXT,
                        portfolio_id INTEGER,
                        department_id INTEGER,
                        budget DECIMAL(15,2) DEFAULT 0,
                        total_program_budget DECIMAL(15,2),
                        spent_amount DECIMAL(15,2) DEFAULT 0,
                        start_date DATE,
                        end_date DATE,
                        progress INTEGER DEFAULT 0,
                        progress_calculation_method VARCHAR DEFAULT "average",
                        weight DECIMAL(5,2) DEFAULT 1,
                        status VARCHAR DEFAULT "draft",
                        priority VARCHAR DEFAULT "medium",
                        owner_id INTEGER,
                        program_manager_id INTEGER,
                        executive_sponsor_id INTEGER,
                        created_by INTEGER,
                        "order" INTEGER DEFAULT 0,
                        created_at DATETIME,
                        updated_at DATETIME,
                        deleted_at DATETIME,
                        FOREIGN KEY (portfolio_id) REFERENCES portfolios(id) ON DELETE SET NULL,
                        FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
                        FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
                        FOREIGN KEY (program_manager_id) REFERENCES users(id) ON DELETE SET NULL,
                        FOREIGN KEY (executive_sponsor_id) REFERENCES users(id) ON DELETE SET NULL,
                        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
                    )
                ');

                // نسخ البيانات
                DB::statement('
                    INSERT INTO initiatives_new
                    SELECT id, code, name, description, portfolio_id, department_id, budget, total_program_budget,
                           spent_amount, start_date, end_date, progress, progress_calculation_method, weight,
                           status, priority, owner_id, program_manager_id, executive_sponsor_id, created_by,
                           "order", created_at, updated_at, deleted_at
                    FROM initiatives
                ');

                // حذف الجدول القديم
                DB::statement('DROP TABLE initiatives');

                // إعادة تسمية الجدول الجديد
                DB::statement('ALTER TABLE initiatives_new RENAME TO initiatives');

                DB::statement('PRAGMA foreign_keys = ON');
            } else {
                // MySQL/PostgreSQL
                $driver = DB::getDriverName();

                // فحص وجود FK قبل الحذف
                if ($driver === 'pgsql') {
                    $fkExists = DB::select("
                        SELECT 1 FROM information_schema.table_constraints
                        WHERE constraint_type = 'FOREIGN KEY'
                        AND table_name = 'initiatives'
                        AND constraint_name LIKE '%objective_id%'
                    ");
                    if (! empty($fkExists)) {
                        Schema::table('initiatives', function (Blueprint $table) {
                            $table->dropForeign(['objective_id']);
                        });
                    }

                    // فحص وحذف الـ index
                    $indexExists = DB::select("
                        SELECT 1 FROM pg_indexes
                        WHERE tablename = 'initiatives'
                        AND indexname = 'initiatives_objective_id_status_deleted_at_index'
                    ");
                    if (! empty($indexExists)) {
                        Schema::table('initiatives', function (Blueprint $table) {
                            $table->dropIndex('initiatives_objective_id_status_deleted_at_index');
                        });
                    }
                } else {
                    // MySQL
                    try {
                        Schema::table('initiatives', function (Blueprint $table) {
                            $table->dropForeign(['objective_id']);
                        });
                    } catch (Exception $e) {
                    }

                    try {
                        Schema::table('initiatives', function (Blueprint $table) {
                            $table->dropIndex('initiatives_objective_id_status_deleted_at_index');
                        });
                    } catch (Exception $e) {
                    }
                }

                if (Schema::hasColumn('initiatives', 'objective_id')) {
                    Schema::table('initiatives', function (Blueprint $table) {
                        $table->dropColumn('objective_id');
                    });
                }
            }
        }

        // الخطوة 4: جعل portfolio_id NOT NULL (حسب قاعدة PMI - كل Program يجب أن ينتمي لـ Portfolio)
        // ملاحظة: سنبقيه nullable لأنه قد يكون هناك initiatives قديمة بدون objective
        // يمكن تشديد هذا لاحقاً

        // الخطوة 5: إعادة تسمية الجدول
        if (Schema::hasTable('initiatives') && ! Schema::hasTable('programs')) {
            Schema::rename('initiatives', 'programs');
        }

        // الخطوة 6: تحديث الـ code prefix من INI- إلى PRG-
        if (Schema::hasTable('programs')) {
            DB::statement("UPDATE programs SET code = REPLACE(code, 'INI-', 'PRG-') WHERE code LIKE 'INI-%'");
        }

        // الخطوة 7: إضافة indexes جديدة (تخطي إذا كان الـ index موجود)
        // ملاحظة: نتحقق فقط من وجود الجدول
        if (Schema::hasTable('programs')) {
            try {
                Schema::table('programs', function (Blueprint $table) {
                    $table->index(['portfolio_id', 'status', 'deleted_at'], 'programs_portfolio_status_deleted_index');
                });
            } catch (Exception $e) {
                // تجاهل إذا كان الـ index موجود مسبقاً
            }
        }
    }

    /**
     * إضافة الأعمدة الناقصة لجدول programs الموجود
     */
    private function addMissingColumnsToPrograms(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            if (! Schema::hasColumn('programs', 'portfolio_id')) {
                $table->foreignId('portfolio_id')
                    ->nullable()
                    ->constrained('portfolios')
                    ->nullOnDelete();
            }
        });

        Schema::table('programs', function (Blueprint $table) {
            if (! Schema::hasColumn('programs', 'program_manager_id')) {
                $table->foreignId('program_manager_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });

        Schema::table('programs', function (Blueprint $table) {
            if (! Schema::hasColumn('programs', 'executive_sponsor_id')) {
                $table->foreignId('executive_sponsor_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });

        Schema::table('programs', function (Blueprint $table) {
            if (! Schema::hasColumn('programs', 'total_program_budget')) {
                $table->decimal('total_program_budget', 15, 2)->nullable();
            }
        });

        Schema::table('programs', function (Blueprint $table) {
            if (! Schema::hasColumn('programs', 'progress_calculation_method')) {
                $table->string('progress_calculation_method')->default('average');
            }
        });

        // إضافة index إذا غير موجود
        try {
            Schema::table('programs', function (Blueprint $table) {
                $table->index(['portfolio_id', 'status', 'deleted_at'], 'programs_portfolio_status_deleted_index');
            });
        } catch (Exception $e) {
            // الـ index موجود مسبقاً
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // إعادة تسمية الجدول
        Schema::rename('programs', 'initiatives');

        // تحديث الـ code prefix
        DB::statement("UPDATE initiatives SET code = REPLACE(code, 'PRG-', 'INI-') WHERE code LIKE 'PRG-%'");

        // حذف الـ index الجديد
        Schema::table('initiatives', function (Blueprint $table) {
            $table->dropIndex(['portfolio_id', 'status', 'deleted_at']);
        });

        // إضافة عمود objective_id مرة أخرى
        Schema::table('initiatives', function (Blueprint $table) {
            $table->foreignId('objective_id')
                ->nullable()
                ->after('description')
                ->constrained('strategic_objectives')
                ->nullOnDelete();

            $table->index(['objective_id', 'status', 'deleted_at']);
        });

        // حذف الحقول الجديدة
        Schema::table('initiatives', function (Blueprint $table) {
            $table->dropForeign(['portfolio_id']);
            $table->dropForeign(['program_manager_id']);
            $table->dropForeign(['executive_sponsor_id']);
            $table->dropColumn([
                'portfolio_id',
                'program_manager_id',
                'executive_sponsor_id',
                'total_program_budget',
                'progress_calculation_method',
            ]);
        });
    }
};
