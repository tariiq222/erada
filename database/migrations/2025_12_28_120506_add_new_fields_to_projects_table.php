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
        Schema::table('projects', function (Blueprint $table) {
            // ملخص المشروع
            $table->text('summary')->nullable()->after('description');

            // الأهداف والنطاق
            $table->json('objectives')->nullable()->after('summary'); // الأهداف
            $table->json('in_scope')->nullable()->after('objectives'); // ما يشمله النطاق
            $table->json('out_of_scope')->nullable()->after('in_scope'); // ما لا يشمله النطاق

            // الموارد
            $table->text('human_resources')->nullable()->after('actual_cost'); // الموارد البشرية
            $table->text('technical_resources')->nullable()->after('human_resources'); // الموارد التقنية
            $table->text('financial_resources')->nullable()->after('technical_resources'); // الموارد المالية

            // منشئ المشروع
            $table->foreignId('created_by')->nullable()->after('manager_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn([
                'summary',
                'objectives',
                'in_scope',
                'out_of_scope',
                'human_resources',
                'technical_resources',
                'financial_resources',
                'created_by',
            ]);
        });
    }
};
