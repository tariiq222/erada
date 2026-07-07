<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * إصلاح تناقضات قيم الـ Enum بين قاعدة البيانات والـ API والـ Frontend
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->migrateSqlite();
        } elseif ($driver === 'pgsql') {
            $this->migratePostgresql();
        } else {
            $this->migrateMysql();
        }
    }

    /**
     * Migration for PostgreSQL
     */
    private function migratePostgresql(): void
    {
        // 1. إصلاح project_risks.status
        DB::table('project_risks')->where('status', 'identified')->update(['status' => 'open']);
        DB::table('project_risks')->where('status', 'mitigating')->update(['status' => 'open']);
        DB::table('project_risks')->where('status', 'resolved')->update(['status' => 'closed']);
        DB::table('project_risks')->where('status', 'accepted')->update(['status' => 'mitigated']);

        // 2. إصلاح milestones.status
        DB::table('milestones')->where('status', 'overdue')->update(['status' => 'delayed']);

        // PostgreSQL لا يدعم ENUM بنفس طريقة MySQL، القيم ستُفحص عبر CHECK constraints أو Application level
    }

    /**
     * Migration for MySQL/MariaDB
     */
    private function migrateMysql(): void
    {
        // 1. إصلاح project_risks.status
        DB::table('project_risks')->where('status', 'identified')->update(['status' => 'open']);
        DB::table('project_risks')->where('status', 'mitigating')->update(['status' => 'open']);
        DB::table('project_risks')->where('status', 'resolved')->update(['status' => 'closed']);
        DB::table('project_risks')->where('status', 'accepted')->update(['status' => 'mitigated']);
        DB::statement("ALTER TABLE project_risks MODIFY COLUMN status ENUM('open', 'mitigated', 'closed') DEFAULT 'open'");

        // 2. إصلاح milestones.status
        DB::table('milestones')->where('status', 'overdue')->update(['status' => 'delayed']);
        DB::statement("ALTER TABLE milestones MODIFY COLUMN status ENUM('pending', 'in_progress', 'completed', 'delayed') DEFAULT 'pending'");

        // 3. إصلاح tasks.priority
        DB::statement("ALTER TABLE tasks MODIFY COLUMN priority ENUM('low', 'medium', 'high', 'urgent', 'critical') DEFAULT 'medium'");

        // 4. إصلاح projects.priority
        DB::statement("ALTER TABLE projects MODIFY COLUMN priority ENUM('low', 'medium', 'high', 'urgent', 'critical') DEFAULT 'medium'");
    }

    /**
     * Migration for SQLite - إعادة إنشاء الجداول مع القيم الجديدة
     */
    private function migrateSqlite(): void
    {
        // تعطيل foreign keys مؤقتاً
        DB::statement('PRAGMA foreign_keys = OFF');

        // 1. إصلاح project_risks
        $this->recreateProjectRisksTable();

        // 2. إصلاح milestones
        $this->recreateMilestonesTable();

        // 3. إصلاح tasks priority
        $this->recreateTasksTable();

        // 4. إصلاح projects priority
        $this->recreateProjectsTable();

        // إعادة تفعيل foreign keys
        DB::statement('PRAGMA foreign_keys = ON');
    }

    private function recreateProjectRisksTable(): void
    {
        // إنشاء جدول مؤقت بالقيم الجديدة
        DB::statement("CREATE TABLE project_risks_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            risk TEXT NOT NULL,
            probability TEXT DEFAULT 'medium' CHECK(probability IN ('low', 'medium', 'high')),
            impact TEXT DEFAULT 'medium' CHECK(impact IN ('low', 'medium', 'high')),
            response TEXT,
            status TEXT DEFAULT 'open' CHECK(status IN ('open', 'mitigated', 'closed')),
            \"order\" INTEGER DEFAULT 0,
            created_at TEXT,
            updated_at TEXT,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        )");

        // نقل البيانات مع تحويل القيم
        DB::statement("INSERT INTO project_risks_new (id, project_id, risk, probability, impact, response, status, \"order\", created_at, updated_at)
            SELECT id, project_id, risk, probability, impact, response,
                CASE status
                    WHEN 'identified' THEN 'open'
                    WHEN 'mitigating' THEN 'open'
                    WHEN 'resolved' THEN 'closed'
                    WHEN 'accepted' THEN 'mitigated'
                    ELSE status
                END,
                \"order\", created_at, updated_at
            FROM project_risks");

        // حذف الجدول القديم وإعادة تسمية الجديد
        DB::statement('DROP TABLE project_risks');
        DB::statement('ALTER TABLE project_risks_new RENAME TO project_risks');
    }

    private function recreateMilestonesTable(): void
    {
        DB::statement("CREATE TABLE milestones_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            description TEXT,
            start_date TEXT,
            due_date TEXT,
            completed_date TEXT,
            status TEXT DEFAULT 'pending' CHECK(status IN ('pending', 'in_progress', 'completed', 'delayed')),
            progress REAL DEFAULT 0,
            \"order\" INTEGER DEFAULT 0,
            created_at TEXT,
            updated_at TEXT,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        )");

        DB::statement("INSERT INTO milestones_new (id, project_id, name, description, start_date, due_date, completed_date, status, progress, \"order\", created_at, updated_at)
            SELECT id, project_id, name, description, start_date, due_date, completed_date,
                CASE status
                    WHEN 'overdue' THEN 'delayed'
                    ELSE status
                END,
                progress, \"order\", created_at, updated_at
            FROM milestones");

        DB::statement('DROP TABLE milestones');
        DB::statement('ALTER TABLE milestones_new RENAME TO milestones');
    }

    private function recreateTasksTable(): void
    {
        // Tasks table has more columns, so we need to preserve them all
        $columns = Schema::getColumnListing('tasks');

        // Simply update the check constraint by recreating
        DB::statement("CREATE TABLE tasks_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            milestone_id INTEGER,
            parent_id INTEGER,
            assigned_to INTEGER,
            created_by INTEGER,
            title TEXT NOT NULL,
            description TEXT,
            status TEXT DEFAULT 'todo' CHECK(status IN ('todo', 'in_progress', 'in_review', 'completed')),
            priority TEXT DEFAULT 'medium' CHECK(priority IN ('low', 'medium', 'high', 'urgent', 'critical')),
            start_date TEXT,
            due_date TEXT,
            completed_date TEXT,
            progress REAL DEFAULT 0,
            estimated_hours INTEGER,
            actual_hours INTEGER,
            \"order\" INTEGER DEFAULT 0,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (milestone_id) REFERENCES milestones(id) ON DELETE SET NULL,
            FOREIGN KEY (parent_id) REFERENCES tasks(id) ON DELETE SET NULL,
            FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        )");

        DB::statement('INSERT INTO tasks_new SELECT * FROM tasks');

        DB::statement('DROP TABLE tasks');
        DB::statement('ALTER TABLE tasks_new RENAME TO tasks');
    }

    private function recreateProjectsTable(): void
    {
        // Get existing data
        $projects = DB::table('projects')->get();

        // Create new table with updated priority enum
        DB::statement("CREATE TABLE projects_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            code TEXT UNIQUE,
            description TEXT,
            objectives TEXT,
            in_scope TEXT,
            out_of_scope TEXT,
            department_id INTEGER,
            manager_id INTEGER,
            supervisor_id INTEGER,
            created_by INTEGER,
            status TEXT DEFAULT 'draft' CHECK(status IN ('draft', 'planning', 'in_progress', 'on_hold', 'completed', 'cancelled')),
            priority TEXT DEFAULT 'medium' CHECK(priority IN ('low', 'medium', 'high', 'urgent', 'critical')),
            start_date TEXT,
            end_date TEXT,
            actual_start_date TEXT,
            actual_end_date TEXT,
            progress REAL DEFAULT 0,
            budget REAL,
            spent_amount REAL DEFAULT 0,
            actual_cost REAL,
            human_resources TEXT,
            technical_resources TEXT,
            financial_resources TEXT,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )");

        // Copy data
        foreach ($projects as $project) {
            DB::table('projects_new')->insert((array) $project);
        }

        DB::statement('DROP TABLE projects');
        DB::statement('ALTER TABLE projects_new RENAME TO projects');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // For SQLite, we would need to recreate tables again
            // Skipping for simplicity - this is mainly for testing
        } else {
            DB::table('project_risks')->where('status', 'open')->update(['status' => 'identified']);
            DB::table('project_risks')->where('status', 'mitigated')->update(['status' => 'accepted']);
            DB::table('project_risks')->where('status', 'closed')->update(['status' => 'resolved']);
            DB::statement("ALTER TABLE project_risks MODIFY COLUMN status ENUM('identified', 'mitigating', 'resolved', 'accepted') DEFAULT 'identified'");

            DB::table('milestones')->where('status', 'delayed')->update(['status' => 'overdue']);
            DB::statement("ALTER TABLE milestones MODIFY COLUMN status ENUM('pending', 'in_progress', 'completed', 'overdue') DEFAULT 'pending'");

            DB::statement("ALTER TABLE tasks MODIFY COLUMN priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium'");
            DB::statement("ALTER TABLE projects MODIFY COLUMN priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium'");
        }
    }
};
