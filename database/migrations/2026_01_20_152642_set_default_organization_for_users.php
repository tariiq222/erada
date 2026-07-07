<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ربط المستخدمين الذين ليس لديهم organization_id بالمنظمة الافتراضية
 *
 * المشكلة: 22 مستخدم من 22 ليس لديهم organization_id
 * الحل: ربطهم بالمنظمة الافتراضية (id = 1)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // التحقق من وجود منظمة افتراضية
        $defaultOrg = DB::table('organizations')->first();

        if ($defaultOrg) {
            // تحديث المستخدمين الذين ليس لديهم organization_id
            DB::table('users')
                ->whereNull('organization_id')
                ->update(['organization_id' => $defaultOrg->id]);

            // تسجيل عدد المستخدمين المُحدّثين
            $updatedCount = DB::table('users')
                ->where('organization_id', $defaultOrg->id)
                ->count();

            // يمكن رؤية هذا في log
            if (app()->runningInConsole()) {
                echo "  → Updated {$updatedCount} users with organization_id = {$defaultOrg->id}\n";
            }
        } else {
            // لا توجد منظمة - تحذير
            if (app()->runningInConsole()) {
                echo "  ⚠️ No organization found. Users not updated.\n";
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // لا نريد إزالة organization_id لأنه قد يكون صحيحاً
        // هذا migration للبيانات وليس للهيكل

        if (app()->runningInConsole()) {
            echo "  → This migration cannot be reversed (data migration)\n";
        }
    }
};
