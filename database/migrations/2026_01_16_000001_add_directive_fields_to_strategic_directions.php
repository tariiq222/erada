<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('strategic_directions', function (Blueprint $table) {
            // ارتباط الالتزام بالخطة الاستراتيجية الأعلى
            $table->string('strategic_plan_link')->nullable()->after('rationale');

            // جهة التوجيه (التجمع الثالث، وزارة الصحة، الصحة القابضة، جهة أخرى)
            $table->enum('directive_source', [
                'cluster_3',        // التجمع الثالث
                'moh',              // وزارة الصحة
                'holding',          // الصحة القابضة
                'other',             // جهة أخرى
            ])->nullable()->after('strategic_plan_link');

            // اسم الجهة الأخرى (في حالة اختيار "جهة أخرى")
            $table->string('directive_source_other')->nullable()->after('directive_source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('strategic_directions', function (Blueprint $table) {
            $table->dropColumn(['strategic_plan_link', 'directive_source', 'directive_source_other']);
        });
    }
};
