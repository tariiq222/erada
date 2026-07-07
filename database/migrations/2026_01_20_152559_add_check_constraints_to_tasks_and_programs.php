<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * إضافة CHECK Constraints لجداول tasks و programs
 *
 * ملاحظة: SQLite لا يدعم ALTER TABLE ADD CONSTRAINT
 * لذلك نستخدم triggers للتحقق من القيم
 */
return new class extends Migration
{
    /**
     * القيم المسموحة لكل عمود
     */
    private array $allowedValues = [
        'tasks' => [
            'status' => ['todo', 'in_progress', 'in_review', 'review', 'completed', 'cancelled', 'pending', 'blocked'],
            'priority' => ['low', 'medium', 'high', 'urgent', 'critical'],
            'type' => ['project', 'personal', 'department', 'recurring'],
        ],
        'programs' => [
            'status' => ['draft', 'planning', 'in_progress', 'on_hold', 'completed', 'cancelled'],
            'priority' => ['low', 'medium', 'high', 'urgent', 'critical'],
        ],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite: استخدام Triggers للتحقق
            $this->createSqliteTriggers();
        } elseif ($driver === 'mysql') {
            // MySQL: استخدام CHECK constraints
            $this->addMysqlCheckConstraints();
        } elseif ($driver === 'pgsql') {
            // PostgreSQL: استخدام CHECK constraints
            $this->addPostgresCheckConstraints();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->dropSqliteTriggers();
        } elseif ($driver === 'mysql') {
            $this->dropMysqlCheckConstraints();
        } elseif ($driver === 'pgsql') {
            $this->dropPostgresCheckConstraints();
        }
    }

    /**
     * إنشاء Triggers لـ SQLite
     */
    private function createSqliteTriggers(): void
    {
        // Tasks - Insert trigger
        $taskStatusValues = implode("', '", $this->allowedValues['tasks']['status']);
        $taskPriorityValues = implode("', '", $this->allowedValues['tasks']['priority']);

        DB::statement('DROP TRIGGER IF EXISTS tasks_check_insert');
        DB::statement("
            CREATE TRIGGER tasks_check_insert
            BEFORE INSERT ON tasks
            FOR EACH ROW
            BEGIN
                SELECT CASE
                    WHEN NEW.status NOT IN ('{$taskStatusValues}') THEN
                        RAISE(ABORT, 'Invalid status value for tasks')
                    WHEN NEW.priority NOT IN ('{$taskPriorityValues}') THEN
                        RAISE(ABORT, 'Invalid priority value for tasks')
                END;
            END
        ");

        // Tasks - Update trigger
        DB::statement('DROP TRIGGER IF EXISTS tasks_check_update');
        DB::statement("
            CREATE TRIGGER tasks_check_update
            BEFORE UPDATE ON tasks
            FOR EACH ROW
            BEGIN
                SELECT CASE
                    WHEN NEW.status NOT IN ('{$taskStatusValues}') THEN
                        RAISE(ABORT, 'Invalid status value for tasks')
                    WHEN NEW.priority NOT IN ('{$taskPriorityValues}') THEN
                        RAISE(ABORT, 'Invalid priority value for tasks')
                END;
            END
        ");

        // Programs - Insert trigger
        $programStatusValues = implode("', '", $this->allowedValues['programs']['status']);
        $programPriorityValues = implode("', '", $this->allowedValues['programs']['priority']);

        DB::statement('DROP TRIGGER IF EXISTS programs_check_insert');
        DB::statement("
            CREATE TRIGGER programs_check_insert
            BEFORE INSERT ON programs
            FOR EACH ROW
            BEGIN
                SELECT CASE
                    WHEN NEW.status NOT IN ('{$programStatusValues}') THEN
                        RAISE(ABORT, 'Invalid status value for programs')
                    WHEN NEW.priority NOT IN ('{$programPriorityValues}') THEN
                        RAISE(ABORT, 'Invalid priority value for programs')
                END;
            END
        ");

        // Programs - Update trigger
        DB::statement('DROP TRIGGER IF EXISTS programs_check_update');
        DB::statement("
            CREATE TRIGGER programs_check_update
            BEFORE UPDATE ON programs
            FOR EACH ROW
            BEGIN
                SELECT CASE
                    WHEN NEW.status NOT IN ('{$programStatusValues}') THEN
                        RAISE(ABORT, 'Invalid status value for programs')
                    WHEN NEW.priority NOT IN ('{$programPriorityValues}') THEN
                        RAISE(ABORT, 'Invalid priority value for programs')
                END;
            END
        ");
    }

    /**
     * حذف Triggers من SQLite
     */
    private function dropSqliteTriggers(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS tasks_check_insert');
        DB::statement('DROP TRIGGER IF EXISTS tasks_check_update');
        DB::statement('DROP TRIGGER IF EXISTS programs_check_insert');
        DB::statement('DROP TRIGGER IF EXISTS programs_check_update');
    }

    /**
     * إضافة CHECK constraints لـ MySQL
     */
    private function addMysqlCheckConstraints(): void
    {
        $taskStatusValues = implode("', '", $this->allowedValues['tasks']['status']);
        $taskPriorityValues = implode("', '", $this->allowedValues['tasks']['priority']);

        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status IN ('{$taskStatusValues}'))");
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_priority_check CHECK (priority IN ('{$taskPriorityValues}'))");

        $programStatusValues = implode("', '", $this->allowedValues['programs']['status']);
        $programPriorityValues = implode("', '", $this->allowedValues['programs']['priority']);

        DB::statement("ALTER TABLE programs ADD CONSTRAINT programs_status_check CHECK (status IN ('{$programStatusValues}'))");
        DB::statement("ALTER TABLE programs ADD CONSTRAINT programs_priority_check CHECK (priority IN ('{$programPriorityValues}'))");
    }

    /**
     * حذف CHECK constraints من MySQL
     */
    private function dropMysqlCheckConstraints(): void
    {
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_status_check');
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_priority_check');
        DB::statement('ALTER TABLE programs DROP CONSTRAINT IF EXISTS programs_status_check');
        DB::statement('ALTER TABLE programs DROP CONSTRAINT IF EXISTS programs_priority_check');
    }

    /**
     * إضافة CHECK constraints لـ PostgreSQL
     */
    private function addPostgresCheckConstraints(): void
    {
        $taskStatusValues = implode("', '", $this->allowedValues['tasks']['status']);
        $taskPriorityValues = implode("', '", $this->allowedValues['tasks']['priority']);

        // فحص قبل الإضافة
        if (! $this->constraintExists('tasks', 'tasks_status_check')) {
            DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status IN ('{$taskStatusValues}'))");
        }
        if (! $this->constraintExists('tasks', 'tasks_priority_check')) {
            DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_priority_check CHECK (priority IN ('{$taskPriorityValues}'))");
        }

        $programStatusValues = implode("', '", $this->allowedValues['programs']['status']);
        $programPriorityValues = implode("', '", $this->allowedValues['programs']['priority']);

        if (! $this->constraintExists('programs', 'programs_status_check')) {
            DB::statement("ALTER TABLE programs ADD CONSTRAINT programs_status_check CHECK (status IN ('{$programStatusValues}'))");
        }
        if (! $this->constraintExists('programs', 'programs_priority_check')) {
            DB::statement("ALTER TABLE programs ADD CONSTRAINT programs_priority_check CHECK (priority IN ('{$programPriorityValues}'))");
        }
    }

    /**
     * فحص وجود constraint في PostgreSQL
     */
    private function constraintExists(string $table, string $constraintName): bool
    {
        $result = DB::select('
            SELECT 1 FROM information_schema.table_constraints
            WHERE table_name = ? AND constraint_name = ?
        ', [$table, $constraintName]);

        return ! empty($result);
    }

    /**
     * حذف CHECK constraints من PostgreSQL
     */
    private function dropPostgresCheckConstraints(): void
    {
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_status_check');
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_priority_check');
        DB::statement('ALTER TABLE programs DROP CONSTRAINT IF EXISTS programs_status_check');
        DB::statement('ALTER TABLE programs DROP CONSTRAINT IF EXISTS programs_priority_check');
    }
};
