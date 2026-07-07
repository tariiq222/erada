<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تحديث هيكل جدول scoped_role_definitions
 *
 * المشكلة: الـ migration القديمة (2026_01_12) أنشأت الجدول بأعمدة (name, display_name, scope_type)
 * والـ migration الجديدة (2026_01_13) تتخطى الإنشاء لأن الجدول موجود
 * لكن Model ScopedRoleDefinition يتوقع أعمدة (role_key, label_ar, scope_type_id)
 *
 * هذه الـ migration تضيف الأعمدة الناقصة وتحدث البيانات
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scoped_role_definitions', function (Blueprint $table) {
            // إضافة الأعمدة الجديدة إذا لم تكن موجودة
            if (! Schema::hasColumn('scoped_role_definitions', 'role_key')) {
                $table->string('role_key', 50)->nullable()->after('id');
            }
            if (! Schema::hasColumn('scoped_role_definitions', 'label_ar')) {
                $table->string('label_ar', 100)->nullable()->after('role_key');
            }
            if (! Schema::hasColumn('scoped_role_definitions', 'label_en')) {
                $table->string('label_en', 100)->nullable()->after('label_ar');
            }
            if (! Schema::hasColumn('scoped_role_definitions', 'scope_type_id')) {
                $table->unsignedBigInteger('scope_type_id')->nullable()->after('id');
            }
            if (! Schema::hasColumn('scoped_role_definitions', 'color')) {
                $table->string('color', 20)->default('primary')->after('description');
            }
            if (! Schema::hasColumn('scoped_role_definitions', 'permissions')) {
                $table->json('permissions')->nullable()->after('color');
            }
            if (! Schema::hasColumn('scoped_role_definitions', 'is_admin_role')) {
                $table->boolean('is_admin_role')->default(false)->after('permissions');
            }
            if (! Schema::hasColumn('scoped_role_definitions', 'can_manage_members')) {
                $table->boolean('can_manage_members')->default(false)->after('is_admin_role');
            }
            if (! Schema::hasColumn('scoped_role_definitions', 'can_edit')) {
                $table->boolean('can_edit')->default(false)->after('can_manage_members');
            }
            if (! Schema::hasColumn('scoped_role_definitions', 'can_delete')) {
                $table->boolean('can_delete')->default(false)->after('can_edit');
            }
            if (! Schema::hasColumn('scoped_role_definitions', 'can_view_all')) {
                $table->boolean('can_view_all')->default(false)->after('can_delete');
            }
            if (! Schema::hasColumn('scoped_role_definitions', 'sort_order')) {
                $table->integer('sort_order')->default(0)->after('is_active');
            }
        });

        // نسخ البيانات من الأعمدة القديمة إذا كانت موجودة
        if (Schema::hasColumn('scoped_role_definitions', 'name') && Schema::hasColumn('scoped_role_definitions', 'role_key')) {
            DB::table('scoped_role_definitions')
                ->whereNull('role_key')
                ->update([
                    'role_key' => DB::raw('"name"'),
                ]);
        }

        if (Schema::hasColumn('scoped_role_definitions', 'display_name') && Schema::hasColumn('scoped_role_definitions', 'label_ar')) {
            DB::table('scoped_role_definitions')
                ->whereNull('label_ar')
                ->update([
                    'label_ar' => DB::raw('"display_name"'),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('scoped_role_definitions', function (Blueprint $table) {
            $columns = ['role_key', 'label_ar', 'label_en', 'scope_type_id', 'color', 'permissions', 'is_admin_role', 'can_manage_members', 'can_edit', 'can_delete', 'can_view_all', 'sort_order'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('scoped_role_definitions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
