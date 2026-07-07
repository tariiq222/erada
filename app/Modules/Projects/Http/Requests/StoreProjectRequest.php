<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Services\ProjectAuthorizationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreProjectRequest - التحقق من بيانات إنشاء مشروع جديد
 *
 * يستخرج قواعد التحقق من ProjectController::store
 * ويوحد منطق التحقق في مكان واحد
 */
class StoreProjectRequest extends FormRequest
{
    /**
     * Context-aware create authorization: the decision depends on the project
     * TYPE and the TARGET department, not just a flat capability. A department
     * manager/member may create within their own department subtree; a member of
     * the governing department for the type may create that type anywhere; an
     * org-level functional role may create anywhere. See ProjectAuthorizationService.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $departmentId = $this->input('department_id');

        return app(ProjectAuthorizationService::class)->canCreate(
            $user,
            $this->input('type'),
            $departmentId !== null && $departmentId !== '' ? (int) $departmentId : null
        );
    }

    /**
     * Whether this is a partial "save as draft" submit. A draft persists
     * incomplete data so the user can finish it later; the normally-required
     * charter fields are relaxed and the status is forced to 'draft'.
     */
    protected function isDraft(): bool
    {
        return $this->boolean('save_as_draft');
    }

    /**
     * A draft is always stored with status 'draft' regardless of the submitted
     * value, so it surfaces as a draft in the projects list.
     */
    protected function prepareForValidation(): void
    {
        if ($this->isDraft()) {
            $this->merge(['status' => 'draft']);
        }
    }

    /**
     * قواعد التحقق
     */
    public function rules(): array
    {
        return array_merge(
            $this->projectRules(),
            $this->methodologyRules(),
            $this->milestoneRules(),
            $this->riskRules(),
            $this->stakeholderRules(),
            $this->teamRules(),
            $this->taskRules()
        );
    }

    /**
     * قواعد المشروع الأساسية
     */
    protected function projectRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            // Optional explicit project manager. When set to a valid different
            // user, that user becomes the project manager instead of the creator.
            // Server-side scope/eligibility re-check happens in ProjectCrudService.
            'manager_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'department_id' => 'nullable|exists:departments,id',
            'program_id' => 'nullable|exists:programs,id',
            'description' => 'nullable|string',
            'objectives' => 'nullable|array',
            'in_scope' => 'nullable|array',
            'out_of_scope' => 'nullable|array',
            'status' => 'nullable|in:draft,planning,in_progress,on_hold,completed,cancelled',
            // Relaxed for drafts: a partial save only needs a name.
            'priority' => [Rule::requiredIf(fn () => ! $this->isDraft()), 'in:low,medium,high,urgent,critical'],
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'budget' => 'nullable|numeric|min:0',
            'human_resources' => 'nullable|string',
            'technical_resources' => 'nullable|string',
            'financial_resources' => 'nullable|string',
        ];
    }

    /**
     * قواعد المنهجية (نوع المشروع + الفرز + الحقول الخاصة بكل منهجية)
     */
    protected function methodologyRules(): array
    {
        $isImprovement = $this->input('type') === 'improvement';

        return [
            // النوع والفرز
            'type' => 'required|in:development,improvement',
            'triage_answers' => 'nullable|array',

            // حقول المشروع التطويري (PMBOK) — ممنوعة على المشاريع التحسينية
            'business_case' => 'nullable|string|prohibited_unless:type,development',
            'success_criteria' => 'nullable|array|prohibited_unless:type,development',
            'requirements' => 'nullable|array|prohibited_unless:type,development',
            'manager_authority' => 'nullable|array|prohibited_unless:type,development',
            'approval_criteria' => 'nullable|string|prohibited_unless:type,development',
            'exit_criteria' => 'nullable|string|prohibited_unless:type,development',

            // حقول المشروع التحسيني (FOCUS-PDCA) — ممنوعة على المشاريع التطويرية
            // Relaxed for drafts: required only on a full (non-draft) improvement save.
            'problem_statement' => [
                Rule::requiredIf(fn () => $this->input('type') === 'improvement' && ! $this->isDraft()),
                'prohibited_unless:type,improvement',
                'string',
            ],
            'target_process' => 'nullable|string|prohibited_unless:type,improvement',
            'root_cause' => 'nullable|string|prohibited_unless:type,improvement',
            'expected_benefits' => 'nullable|array|prohibited_unless:type,improvement',
            // A new improvement project must start at 'plan'; later phases only move
            // through PATCH /projects/{project}/pdca-phase (sequential + KPI gate).
            'current_pdca_phase' => 'nullable|string|in:plan|prohibited_unless:type,improvement',

            // مؤشرات الأداء — إلزامية (≥1) لمشاريع التحسين، تُنشأ في نظام Performance
            // Relaxed for drafts: a partial improvement save may omit KPIs.
            'kpis' => $isImprovement && ! $this->isDraft() ? ['required', 'array', 'min:1'] : ['nullable', 'array'],
            'kpis.*.name' => ['required_with:kpis', 'string', 'max:255'],
            'kpis.*.target' => ['required_with:kpis', 'numeric'],
            'kpis.*.baseline' => ['nullable', 'numeric'],
            'kpis.*.current_value' => ['nullable', 'numeric'],
            'kpis.*.unit' => ['nullable', 'string', 'max:50'],
            'kpis.*.measurement_method' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * قواعد المراحل
     */
    protected function milestoneRules(): array
    {
        return [
            'milestones' => 'nullable|array',
            'milestones.*.name' => 'nullable|string|max:255',
            'milestones.*.start_date' => 'nullable|date',
            'milestones.*.due_date' => 'nullable|date|after_or_equal:milestones.*.start_date',
            'milestones.*.description' => 'nullable|string',
            'milestones.*.deliverables' => 'nullable|array',
            'milestones.*.deliverables.*.name' => 'nullable|string|max:255',
            'milestones.*.deliverables.*.description' => 'nullable|string',
        ];
    }

    /**
     * قواعد المخاطر
     */
    protected function riskRules(): array
    {
        return [
            'risks' => 'nullable|array',
            'risks.*.description' => 'nullable|string|max:1000',
            'risks.*.probability' => 'nullable|in:low,medium,high',
            'risks.*.impact' => 'nullable|in:low,medium,high',
            'risks.*.mitigation' => 'nullable|string|max:1000',
        ];
    }

    /**
     * قواعد أصحاب المصلحة
     */
    protected function stakeholderRules(): array
    {
        return [
            'stakeholders' => 'nullable|array',
            'stakeholders.*.user_id' => 'nullable|exists:users,id',
            'stakeholders.*.name' => 'nullable|string|max:255',
            'stakeholders.*.role' => 'nullable|string|max:255',
            'stakeholders.*.contact' => 'nullable|string|max:255',
            'stakeholders.*.influence' => 'nullable|in:low,medium,high',
        ];
    }

    /**
     * قواعد فريق العمل
     */
    protected function teamRules(): array
    {
        return [
            'team_members' => 'nullable|array',
            'team_members.*.user_id' => 'nullable|exists:users,id',
            'team_members.*.name' => 'nullable|string|max:255',
            'team_members.*.role' => 'nullable|string|max:255',
        ];
    }

    /**
     * قواعد المهام
     */
    protected function taskRules(): array
    {
        return [
            'tasks' => 'nullable|array',
            'tasks.*.name' => 'nullable|string|max:255',
            'tasks.*.description' => 'nullable|string',
            'tasks.*.milestone_index' => 'nullable|integer',
            'tasks.*.assigned_to' => 'nullable|exists:users,id',
            'tasks.*.priority' => 'nullable|in:low,medium,high,urgent,critical',
            'tasks.*.start_date' => 'nullable|date',
            'tasks.*.due_date' => 'nullable|date|after_or_equal:tasks.*.start_date',
        ];
    }

    /**
     * أسماء الحقول بالعربية
     */
    public function attributes(): array
    {
        return [
            'name' => 'اسم المشروع',
            'department_id' => 'القسم',
            'program_id' => 'المبادرة',
            'priority' => 'الأولوية',
            'start_date' => 'تاريخ البداية',
            'end_date' => 'تاريخ الانتهاء',
            'budget' => 'الميزانية',
            'type' => 'نوع المشروع',
            'triage_answers' => 'إجابات الفرز',
            'business_case' => 'مبرر المشروع',
            'success_criteria' => 'معايير النجاح',
            'requirements' => 'المتطلبات',
            'manager_authority' => 'صلاحيات المدير',
            'approval_criteria' => 'متطلبات الموافقة',
            'exit_criteria' => 'معايير الإنهاء',
            'problem_statement' => 'بيان المشكلة',
            'target_process' => 'العملية المستهدفة',
            'root_cause' => 'الأسباب الجذرية',
            'expected_benefits' => 'الفوائد المتوقعة',
            'current_pdca_phase' => 'طور PDCA الحالي',
        ];
    }

    /**
     * رسائل الخطأ المخصصة
     */
    public function messages(): array
    {
        return [
            'name.required' => 'اسم المشروع مطلوب',
            'name.max' => 'اسم المشروع يجب ألا يتجاوز 255 حرفاً',
            'priority.required' => 'أولوية المشروع مطلوبة',
            'priority.in' => 'أولوية المشروع يجب أن تكون: منخفضة، متوسطة، عالية، عاجلة، أو حرجة',
            'end_date.after_or_equal' => 'تاريخ الانتهاء يجب أن يكون بعد أو يساوي تاريخ البداية',
            'budget.min' => 'الميزانية يجب أن تكون صفر أو أكثر',
        ];
    }
}
