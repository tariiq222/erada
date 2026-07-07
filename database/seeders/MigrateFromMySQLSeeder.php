<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * نقل البيانات من MySQL إلى PostgreSQL
 *
 * الاستخدام:
 * 1. تأكد من إعداد MYSQL_* في ملف .env
 * 2. شغّل: php artisan db:seed --class=MigrateFromMySQLSeeder
 */
class MigrateFromMySQLSeeder extends Seeder
{
    /**
     * اتصال MySQL المصدر
     */
    protected $mysqlConnection;

    /**
     * الجداول المراد نقلها بالترتيب (مهم للعلاقات)
     */
    protected array $tables = [
        'users',
        'departments',
        'system_settings',
        'projects',
        'milestones',
        'tasks',
        'task_dependencies',
        'task_attachments',
        'comments',
        'activity_logs',
        'notifications',
        'sessions',
    ];

    public function run(): void
    {
        // التحقق من وجود إعدادات MySQL
        $mysqlHost = env('MYSQL_HOST');
        $mysqlDb = env('MYSQL_DATABASE');
        $mysqlUser = env('MYSQL_USERNAME');
        $mysqlPass = env('MYSQL_PASSWORD');

        if (! $mysqlHost || ! $mysqlDb) {
            $this->command->error('يرجى إعداد متغيرات MYSQL_* في ملف .env');
            $this->command->info('MYSQL_HOST=xxx');
            $this->command->info('MYSQL_DATABASE=xxx');
            $this->command->info('MYSQL_USERNAME=xxx');
            $this->command->info('MYSQL_PASSWORD=xxx');

            return;
        }

        // إعداد اتصال MySQL
        config([
            'database.connections.mysql_source' => [
                'driver' => 'mysql',
                'host' => $mysqlHost,
                'port' => env('MYSQL_PORT', 3306),
                'database' => $mysqlDb,
                'username' => $mysqlUser,
                'password' => $mysqlPass,
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ],
        ]);

        $this->command->info('جاري الاتصال بـ MySQL...');

        try {
            DB::connection('mysql_source')->getPdo();
            $this->command->info('✓ تم الاتصال بـ MySQL بنجاح');
        } catch (\Exception $e) {
            $this->command->error('فشل الاتصال بـ MySQL: '.$e->getMessage());

            return;
        }

        // تعطيل فحص المفاتيح الأجنبية مؤقتاً
        DB::statement('SET session_replication_role = replica;');

        foreach ($this->tables as $table) {
            $this->migrateTable($table);
        }

        // إعادة تفعيل فحص المفاتيح الأجنبية
        DB::statement('SET session_replication_role = DEFAULT;');

        // إعادة تعيين sequences
        $this->resetSequences();

        $this->command->info('');
        $this->command->info('✓ تم نقل جميع البيانات بنجاح!');
    }

    protected function migrateTable(string $table): void
    {
        $this->command->info("جاري نقل جدول: {$table}...");

        try {
            // التحقق من وجود الجدول في MySQL
            if (! $this->tableExistsInMySQL($table)) {
                $this->command->warn('  ⚠ الجدول غير موجود في MySQL، تخطي...');

                return;
            }

            // التحقق من وجود الجدول في PostgreSQL
            if (! $this->tableExistsInPostgres($table)) {
                $this->command->warn('  ⚠ الجدول غير موجود في PostgreSQL، تخطي...');

                return;
            }

            // جلب البيانات من MySQL
            $data = DB::connection('mysql_source')->table($table)->get();

            if ($data->isEmpty()) {
                $this->command->info('  - لا توجد بيانات للنقل');

                return;
            }

            // حذف البيانات الموجودة في PostgreSQL
            DB::table($table)->truncate();

            // إدراج البيانات
            $chunks = $data->chunk(100);
            $total = 0;

            foreach ($chunks as $chunk) {
                $records = $chunk->map(function ($item) {
                    return (array) $item;
                })->toArray();

                DB::table($table)->insert($records);
                $total += count($records);
            }

            $this->command->info("  ✓ تم نقل {$total} سجل");

        } catch (\Exception $e) {
            $this->command->error('  ✗ خطأ: '.$e->getMessage());
            Log::error("Migration error for table {$table}: ".$e->getMessage());
        }
    }

    protected function tableExistsInMySQL(string $table): bool
    {
        try {
            DB::connection('mysql_source')->table($table)->limit(1)->get();

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function tableExistsInPostgres(string $table): bool
    {
        try {
            DB::table($table)->limit(1)->get();

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function resetSequences(): void
    {
        $this->command->info('جاري إعادة تعيين sequences...');

        $tables = [
            'users' => 'id',
            'departments' => 'id',
            'projects' => 'id',
            'tasks' => 'id',
            'milestones' => 'id',
            'system_settings' => 'id',
        ];

        foreach ($tables as $table => $column) {
            try {
                $max = DB::table($table)->max($column) ?? 0;
                $sequence = "{$table}_{$column}_seq";
                DB::statement("SELECT setval('{$sequence}', ?, true)", [$max]);
                $this->command->info("  ✓ {$table}: sequence = {$max}");
            } catch (\Exception $e) {
                // تجاهل الأخطاء للجداول التي ليس لها sequence
            }
        }
    }
}
