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
     * إضافة دعم المؤسسات المتعددة (Tenant-Ready)
     * - جدول organizations
     * - إضافة organization_id للجداول الأساسية
     */
    public function up(): void
    {
        // جدول المؤسسات (Organizations / Tenants)
        if (! Schema::hasTable('organizations')) {
            Schema::create('organizations', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code', 50)->unique()->comment('رمز المؤسسة الفريد');
                $table->string('logo')->nullable();
                $table->text('description')->nullable();

                // معلومات التواصل
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->string('address')->nullable();
                $table->string('website')->nullable();

                // الإعدادات
                $table->json('settings')->nullable()->comment('إعدادات خاصة بالمؤسسة');
                $table->boolean('is_active')->default(true);

                // التتبع
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index('is_active');
            });
        }

        $driver = Schema::getConnection()->getDriverName();
        $isSqlite = $driver === 'sqlite';

        // إضافة organization_id للجداول الأساسية
        // users
        if (! Schema::hasColumn('users', 'organization_id')) {
            Schema::table('users', function (Blueprint $table) use ($isSqlite) {
                if ($isSqlite) {
                    $table->unsignedBigInteger('organization_id')->nullable()->after('id');
                } else {
                    $table->foreignId('organization_id')->nullable()->after('id')
                        ->constrained('organizations')->nullOnDelete();
                }
                $table->index('organization_id', 'users_org_id_idx');
            });
        }

        // departments
        if (! Schema::hasColumn('departments', 'organization_id')) {
            Schema::table('departments', function (Blueprint $table) use ($isSqlite) {
                if ($isSqlite) {
                    $table->unsignedBigInteger('organization_id')->nullable()->after('id');
                } else {
                    $table->foreignId('organization_id')->nullable()->after('id')
                        ->constrained('organizations')->nullOnDelete();
                }
                $table->index('organization_id', 'departments_org_id_idx');
            });
        }

        // projects
        if (! Schema::hasColumn('projects', 'organization_id')) {
            Schema::table('projects', function (Blueprint $table) use ($isSqlite) {
                if ($isSqlite) {
                    $table->unsignedBigInteger('organization_id')->nullable()->after('id');
                } else {
                    $table->foreignId('organization_id')->nullable()->after('id')
                        ->constrained('organizations')->nullOnDelete();
                }
                $table->index('organization_id', 'projects_org_id_idx');
            });
        }

        // tasks
        if (! Schema::hasColumn('tasks', 'organization_id')) {
            Schema::table('tasks', function (Blueprint $table) use ($isSqlite) {
                if ($isSqlite) {
                    $table->unsignedBigInteger('organization_id')->nullable()->after('id');
                } else {
                    $table->foreignId('organization_id')->nullable()->after('id')
                        ->constrained('organizations')->nullOnDelete();
                }
                $table->index('organization_id', 'tasks_org_id_idx');
            });
        }

        // إنشاء المؤسسة الافتراضية (إذا لم تكن موجودة)
        $existingOrg = DB::table('organizations')->where('id', 1)->orWhere('code', 'DEFAULT')->first();
        if (! $existingOrg) {
            DB::table('organizations')->insert([
                'name' => 'المؤسسة الافتراضية',
                'code' => 'DEFAULT',
                'description' => 'المؤسسة الافتراضية للنظام',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // ربط جميع البيانات الموجودة بالمؤسسة الافتراضية
        DB::table('users')->whereNull('organization_id')->update(['organization_id' => 1]);
        DB::table('departments')->whereNull('organization_id')->update(['organization_id' => 1]);
        DB::table('projects')->whereNull('organization_id')->update(['organization_id' => 1]);
        DB::table('tasks')->whereNull('organization_id')->update(['organization_id' => 1]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // إزالة organization_id من الجداول
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });

        Schema::dropIfExists('organizations');
    }
};
