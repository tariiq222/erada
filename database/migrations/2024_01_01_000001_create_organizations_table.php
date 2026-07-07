<?php

/*
|--------------------------------------------------------------------------
| IMPORTANT — Filename vs. Actual Behavior
|--------------------------------------------------------------------------
| Despite the filename `2024_01_01_000001_create_organizations_table.php`,
| this migration does NOT create the `organizations` table.
|
| It actually creates the `system_settings` table (and adds a few columns
| to the `users` table).
|
| The `organizations` table is created by a later migration:
|   2026_01_12_100002_add_organization_support.php
|
| DO NOT RENAME THIS FILE.
| Renaming it would change its lexicographic ordering and break the
| migration sequence for environments that have already applied it.
| On those deployments, Laravel would try to re-run the renamed file as
| a "new" migration (or skip it, depending on the migration table state),
| causing a `Base table or view already exists: system_settings` error.
|
| This discrepancy is a historical artifact: the project originally
| planned to use a single `organizations` table for tenant identity, then
| split it into `system_settings` (per-deployment) and `organizations`
| (multi-tenant). The filename was kept stable to preserve migration
| order. The original Arabic notes inside the class document the same
| evolution.
*/

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * إنشاء جدول إعدادات النظام
     * (تم تغييره من organizations إلى system_settings)
     *
     * NOTE: Despite this migration's filename, it creates `system_settings`
     * (not `organizations`). See the file-level docblock above.
     */
    public function up(): void
    {
        // NOTE: This creates `system_settings`, not `organizations`.
        // The `organizations` table is created later by
        // 2026_01_12_100002_add_organization_support.php.
        // Do not rename the file — see the file-level docblock.
        //
        // إنشاء جدول إعدادات النظام بدلاً من organizations
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // اسم النظام بالعربي
            $table->string('name_en')->nullable(); // اسم النظام بالإنجليزي
            $table->string('code')->unique()->nullable(); // رمز النظام
            $table->string('region')->nullable(); // المنطقة
            $table->string('city')->nullable(); // المدينة
            $table->string('address')->nullable(); // العنوان
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('logo')->nullable();
            $table->json('settings')->nullable(); // إعدادات إضافية
            $table->timestamps();
        });

        // إضافة الأعمدة الإضافية لجدول المستخدمين (بدون organization_id)
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->string('job_title')->nullable()->after('phone'); // المسمى الوظيفي
            $table->boolean('is_active')->default(true)->after('job_title');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'job_title', 'is_active']);
        });

        Schema::dropIfExists('system_settings');
    }
};
