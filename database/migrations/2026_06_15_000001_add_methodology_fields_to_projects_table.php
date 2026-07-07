<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * إضافة حقول المنهجيتين (PMBOK جديد / FOCUS-PDCA تحسيني) إلى جدول projects.
     *
     * الحقول المشتركة:
     *   - type: نوع المشروع (new أو improvement)
     *   - triage_answers: إجابات الفرز الأولي (JSON)
     *
     * حقول المشروع الجديد (PMBOK):
     *   - business_case, success_criteria, requirements
     *   - manager_authority, approval_criteria, exit_criteria
     *
     * حقول المشروع التحسيني (FOCUS-PDCA):
     *   - problem_statement, target_process, root_cause
     *   - expected_benefits, current_pdca_phase
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // الحقول المشتركة
            $table->string('type')->default('new')->nullable()->after('code');
            $table->json('triage_answers')->nullable()->after('type');

            // حقول المشروع الجديد (PMBOK)
            $table->text('business_case')->nullable()->after('triage_answers');
            $table->json('success_criteria')->nullable()->after('business_case');
            $table->json('requirements')->nullable()->after('success_criteria');
            $table->json('manager_authority')->nullable()->after('requirements');
            $table->text('approval_criteria')->nullable()->after('manager_authority');
            $table->text('exit_criteria')->nullable()->after('approval_criteria');

            // حقول المشروع التحسيني (FOCUS-PDCA)
            $table->text('problem_statement')->nullable()->after('exit_criteria');
            $table->text('target_process')->nullable()->after('problem_statement');
            $table->text('root_cause')->nullable()->after('target_process');
            $table->json('expected_benefits')->nullable()->after('root_cause');
            $table->string('current_pdca_phase')->nullable()->after('expected_benefits');
        });
    }

    /**
     * التراجع عن إضافة الحقول.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'type',
                'triage_answers',
                'business_case',
                'success_criteria',
                'requirements',
                'manager_authority',
                'approval_criteria',
                'exit_criteria',
                'problem_statement',
                'target_process',
                'root_cause',
                'expected_benefits',
                'current_pdca_phase',
            ]);
        });
    }
};
