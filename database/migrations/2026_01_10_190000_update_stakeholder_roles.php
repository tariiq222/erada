<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * تحديث أدوار أصحاب المصلحة لتكون أكثر ملاءمة
     * الأدوار الجديدة:
     * - end_user: مستخدم نهائي
     * - implementer: جهة منفذة
     * - consultant: مستشار
     * - governance: جهة رقابية
     * - operations: داعم تشغيلي
     * - influencer: صاحب تأثير
     * - other: أخرى
     */
    public function up(): void
    {
        // تحويل الأدوار القديمة إلى الجديدة
        DB::table('stakeholders')->where('role', 'sponsor')->update(['role' => 'other']);
        DB::table('stakeholders')->where('role', 'client')->update(['role' => 'end_user']);
        DB::table('stakeholders')->where('role', 'team_member')->update(['role' => 'implementer']);
        DB::table('stakeholders')->where('role', 'vendor')->update(['role' => 'implementer']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // تحويل الأدوار الجديدة إلى القديمة
        DB::table('stakeholders')->where('role', 'end_user')->update(['role' => 'client']);
        DB::table('stakeholders')->where('role', 'implementer')->update(['role' => 'vendor']);
        DB::table('stakeholders')->where('role', 'governance')->update(['role' => 'other']);
        DB::table('stakeholders')->where('role', 'operations')->update(['role' => 'other']);
        DB::table('stakeholders')->where('role', 'influencer')->update(['role' => 'other']);
    }
};
