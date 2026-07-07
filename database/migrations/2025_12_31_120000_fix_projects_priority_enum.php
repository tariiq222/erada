<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * إصلاح enum الأولوية في جدول المشاريع
     * تغيير من: low, medium, high, critical
     * إلى: low, medium, high, urgent
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // تحديث القيم الموجودة من critical إلى urgent (أو high مؤقتاً)
        DB::table('projects')->where('priority', 'critical')->update(['priority' => 'high']);

        if ($driver === 'mysql') {
            // MySQL: تغيير enum
            DB::statement("ALTER TABLE projects MODIFY COLUMN priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium'");
        } elseif ($driver === 'sqlite') {
            // SQLite: لا يدعم enum، الحقل text بالفعل فلا حاجة لتغيير
            // فقط نتأكد أن القيم صحيحة
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // تحديث القيم الموجودة من urgent إلى high
        DB::table('projects')->where('priority', 'urgent')->update(['priority' => 'high']);

        if ($driver === 'mysql') {
            // إرجاع enum القديم
            DB::statement("ALTER TABLE projects MODIFY COLUMN priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium'");
        }
    }
};
