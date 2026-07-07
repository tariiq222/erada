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
     * تحديث جدول projects:
     * 1. إعادة تسمية initiative_id إلى program_id
     * 2. تحديث FK ليشير إلى programs
     */
    public function up(): void
    {
        // تخطي إذا كان program_id موجود مسبقاً
        if (Schema::hasColumn('projects', 'program_id')) {
            return;
        }

        // تخطي إذا لم يكن initiative_id موجوداً
        if (! Schema::hasColumn('projects', 'initiative_id')) {
            // إضافة program_id مباشرة إذا لم يكن initiative_id موجوداً
            $driver = Schema::getConnection()->getDriverName();
            $isSqlite = $driver === 'sqlite';

            Schema::table('projects', function (Blueprint $table) use ($isSqlite) {
                if ($isSqlite) {
                    $table->unsignedBigInteger('program_id')->nullable()->after('department_id');
                } else {
                    $table->foreignId('program_id')
                        ->nullable()
                        ->after('department_id')
                        ->constrained('programs')
                        ->nullOnDelete();
                }
                $table->index(['program_id', 'status'], 'projects_program_status_idx');
            });

            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        $isSqlite = $driver === 'sqlite';

        if ($isSqlite) {
            // SQLite: استخدام طريقة إعادة بناء الجدول
            DB::statement('PRAGMA foreign_keys = OFF');

            // إعادة تسمية العمود مباشرة
            Schema::table('projects', function (Blueprint $table) {
                $table->renameColumn('initiative_id', 'program_id');
            });

            // محاولة حذف الـ index القديم بشكل آمن
            try {
                Schema::table('projects', function (Blueprint $table) {
                    $table->dropIndex('projects_initiative_status_idx');
                });
            } catch (Exception $e) {
                // تجاهل إذا لم يكن موجوداً
            }

            // إضافة index جديد
            try {
                Schema::table('projects', function (Blueprint $table) {
                    $table->index(['program_id', 'status'], 'projects_program_status_idx');
                });
            } catch (Exception $e) {
                // تجاهل إذا كان موجوداً
            }

            DB::statement('PRAGMA foreign_keys = ON');
        } else {
            // MySQL/PostgreSQL
            $isPgsql = $driver === 'pgsql';

            if ($isPgsql) {
                // PostgreSQL: فحص FK قبل الحذف
                $fkExists = DB::select("
                    SELECT 1 FROM information_schema.table_constraints
                    WHERE constraint_type = 'FOREIGN KEY'
                    AND table_name = 'projects'
                    AND constraint_name LIKE '%initiative_id%'
                ");
                if (! empty($fkExists)) {
                    Schema::table('projects', function (Blueprint $table) {
                        $table->dropForeign(['initiative_id']);
                    });
                }

                // فحص Index قبل الحذف
                $indexExists = DB::select("
                    SELECT 1 FROM pg_indexes
                    WHERE tablename = 'projects'
                    AND indexname LIKE '%initiative%status%'
                ");
                if (! empty($indexExists)) {
                    $indexName = DB::select("
                        SELECT indexname FROM pg_indexes
                        WHERE tablename = 'projects'
                        AND indexname LIKE '%initiative%status%'
                        LIMIT 1
                    ");
                    if (! empty($indexName)) {
                        DB::statement("DROP INDEX IF EXISTS \"{$indexName[0]->indexname}\"");
                    }
                }
            } else {
                // MySQL
                try {
                    Schema::table('projects', function (Blueprint $table) {
                        $table->dropForeign(['initiative_id']);
                    });
                } catch (Exception $e) {
                }

                try {
                    Schema::table('projects', function (Blueprint $table) {
                        $table->dropIndex(['initiative_id', 'status']);
                    });
                } catch (Exception $e) {
                }
            }

            // إعادة تسمية العمود
            Schema::table('projects', function (Blueprint $table) {
                $table->renameColumn('initiative_id', 'program_id');
            });

            // إضافة FK و Index جديد
            if ($isPgsql) {
                $fkExists = DB::select("
                    SELECT 1 FROM information_schema.table_constraints
                    WHERE constraint_type = 'FOREIGN KEY'
                    AND table_name = 'projects'
                    AND constraint_name LIKE '%program_id%'
                ");
                if (empty($fkExists)) {
                    Schema::table('projects', function (Blueprint $table) {
                        $table->foreign('program_id')
                            ->references('id')
                            ->on('programs')
                            ->nullOnDelete();
                    });
                }

                $indexExists = DB::select("
                    SELECT 1 FROM pg_indexes
                    WHERE tablename = 'projects'
                    AND indexname LIKE '%program%status%'
                ");
                if (empty($indexExists)) {
                    Schema::table('projects', function (Blueprint $table) {
                        $table->index(['program_id', 'status']);
                    });
                }
            } else {
                try {
                    Schema::table('projects', function (Blueprint $table) {
                        $table->foreign('program_id')
                            ->references('id')
                            ->on('programs')
                            ->nullOnDelete();
                    });
                } catch (Exception $e) {
                }

                try {
                    Schema::table('projects', function (Blueprint $table) {
                        $table->index(['program_id', 'status']);
                    });
                } catch (Exception $e) {
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('projects', 'program_id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        $isSqlite = $driver === 'sqlite';

        if ($isSqlite) {
            DB::statement('PRAGMA foreign_keys = OFF');

            Schema::table('projects', function (Blueprint $table) {
                $table->renameColumn('program_id', 'initiative_id');
            });

            try {
                Schema::table('projects', function (Blueprint $table) {
                    $table->dropIndex('projects_program_status_idx');
                });
            } catch (Exception $e) {
                // تجاهل
            }

            try {
                Schema::table('projects', function (Blueprint $table) {
                    $table->index(['initiative_id', 'status'], 'projects_initiative_status_idx');
                });
            } catch (Exception $e) {
                // تجاهل
            }

            DB::statement('PRAGMA foreign_keys = ON');
        } else {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropForeign(['program_id']);
                $table->dropIndex(['program_id', 'status']);
            });

            Schema::table('projects', function (Blueprint $table) {
                $table->renameColumn('program_id', 'initiative_id');
            });

            Schema::table('projects', function (Blueprint $table) {
                $table->foreign('initiative_id')
                    ->references('id')
                    ->on('initiatives')
                    ->nullOnDelete();

                $table->index(['initiative_id', 'status']);
            });
        }
    }
};
