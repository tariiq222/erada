<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Services\ProjectAuthorizationService;
use App\Modules\Tasks\Enums\TaskStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdateProjectRequest - التحقق من بيانات تحديث مشروع
 *
 * يستخرج قواعد التحقق من ProjectController::update
 * ويوحد منطق التحقق في مكان واحد
 */
class UpdateProjectRequest extends FormRequest
{
    protected ?Project $project = null;

    /**
     * التحقق من صلاحية المستخدم باستخدام ProjectPolicy
     */
    public function authorize(): bool
    {
        // المسار يستخدم {project} route model binding
        $project = $this->route('project');

        // إذا لم يكن model binding، جرب البحث بالـ ID
        if (! $project instanceof Project) {
            $project = Project::find($project);
        }

        if (! $project) {
            return false;
        }

        $this->project = $project;

        // استخدام Policy مباشرة للتحقق من الصلاحية
        return $this->user()->can('update', $project);
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
            $this->kpiRules(),
            $this->riskRules(),
            $this->stakeholderRules(),
            $this->teamRules(),
            $this->taskRules()
        );
    }

    /**
     * قواعد المراحل (تُزامَن بالـ id عند التحديث)
     */
    protected function milestoneRules(): array
    {
        return [
            'milestones' => 'nullable|array',
            'milestones.*.id' => 'nullable|integer|exists:milestones,id',
            'milestones.*.name' => 'nullable|string|max:255',
            'milestones.*.start_date' => 'nullable|date',
            'milestones.*.due_date' => 'nullable|date|after_or_equal:milestones.*.start_date',
            'milestones.*.description' => 'nullable|string',
            'milestones.*.deliverables' => 'nullable|array',
            'milestones.*.deliverables.*.id' => 'nullable|integer',
            'milestones.*.deliverables.*.name' => 'nullable|string|max:255',
            'milestones.*.deliverables.*.description' => 'nullable|string',
        ];
    }

    /**
     * قواعد مؤشرات الأداء (تُزامَن بالـ id عند التحديث — نظام Performance)
     */
    protected function kpiRules(): array
    {
        // The resulting type after this update: incoming `type` if present, else the
        // stored type. An improvement project must end up with at least one KPI
        // (mirrors StoreProjectRequest), including a new→improvement flip via the
        // update path. We only force `kpis` when the project would otherwise have
        // ZERO KPIs — i.e. it has no linked KPI today and the request adds none —
        // so a partial update of an already-valid improvement project (which omits
        // `kpis`) is not forced to resend them.
        $resultingType = $this->input('type', $this->project?->type);
        $isImprovement = $resultingType === 'improvement';
        $requireKpis = $isImprovement
            && ! $this->filled('kpis')
            && ($this->project === null || $this->project->kpis()->count() === 0);

        // Improvement: an explicitly-sent kpis array must be non-empty (min:1).
        // New: kpis is optional and may be an empty array.
        $kpisRule = match (true) {
            $requireKpis => 'required|array|min:1',
            $isImprovement => 'nullable|array|min:1',
            default => 'nullable|array',
        };

        return [
            'kpis' => $kpisRule,
            'kpis.*.id' => 'nullable|integer|exists:kpis,id',
            'kpis.*.name' => 'required_with:kpis|string|max:255',
            'kpis.*.target' => 'nullable|numeric',
            'kpis.*.baseline' => 'nullable|numeric',
            'kpis.*.current_value' => 'nullable|numeric',
            'kpis.*.unit' => 'nullable|string|max:50',
            'kpis.*.measurement_method' => 'nullable|string|max:255',
        ];
    }

    /**
     * قواعد المشروع الأساسية
     */
    protected function projectRules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'department_id' => 'nullable|exists:departments,id',
            'program_id' => 'nullable|exists:programs,id',
            'description' => 'nullable|string',
            'objectives' => 'nullable|array',
            'in_scope' => 'nullable|array',
            'out_of_scope' => 'nullable|array',
            'status' => 'sometimes|required|in:draft,planning,in_progress,on_hold,completed,cancelled',
            'priority' => 'sometimes|required|in:low,medium,high,urgent,critical',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'actual_start_date' => 'nullable|date',
            'actual_end_date' => 'nullable|date',
            'budget' => 'nullable|numeric|min:0',
            'actual_cost' => 'nullable|numeric|min:0',
            'human_resources' => 'nullable|string',
            'technical_resources' => 'nullable|string',
            'financial_resources' => 'nullable|string',
            // حقول الإغلاق
            'lessons_learned' => 'nullable|string',
            'outcome_summary' => 'nullable|string',
            'sustainability_plan' => 'nullable|string',
            'achievement_percentage' => 'nullable|numeric|min:0|max:100',
            'achievement_status' => 'nullable|string|in:achieved,partial,not_achieved',
        ];
    }

    /**
     * قواعد المنهجية (نوع المشروع + الفرز + الحقول الخاصة بكل منهجية)
     */
    protected function methodologyRules(): array
    {
        // The resulting type after this update: incoming `type` if present, else the
        // stored type. Used to prohibit methodology fields that belong to the OTHER
        // methodology so the two sets never cross-contaminate (e.g. a PMBOK field
        // submitted on an improvement project, or vice-versa).
        $resultingType = $this->input('type', $this->project?->type);

        $prohibitNew = $this->prohibitedFieldValidator(
            $resultingType !== 'development',
            'هذا الحقل مخصّص للمشاريع التطويرية (PMBOK) فقط.'
        );
        $prohibitImprovement = $this->prohibitedFieldValidator(
            $resultingType !== 'improvement',
            'هذا الحقل مخصّص للمشاريع التحسينية (FOCUS-PDCA) فقط.'
        );

        return [
            // النوع والفرز (sometimes في التحديث)
            'type' => 'sometimes|in:development,improvement',
            'triage_answers' => 'nullable|array',

            // حقول المشروع الجديد (PMBOK) — ممنوعة على المشاريع التحسينية
            'business_case' => [$prohibitNew, 'nullable', 'string'],
            'success_criteria' => [$prohibitNew, 'nullable', 'array'],
            'requirements' => [$prohibitNew, 'nullable', 'array'],
            'manager_authority' => [$prohibitNew, 'nullable', 'array'],
            'approval_criteria' => [$prohibitNew, 'nullable', 'string'],
            'exit_criteria' => [$prohibitNew, 'nullable', 'string'],

            // حقول المشروع التحسيني (FOCUS-PDCA) — ممنوعة على المشاريع الجديدة
            'problem_statement' => [
                $prohibitImprovement,
                function ($attribute, $value, $fail) use ($resultingType) {
                    if ($resultingType === 'improvement' && $this->has('type') && empty($value) && empty($this->project?->problem_statement)) {
                        $fail('بيان المشكلة مطلوب للمشاريع التحسينية.');
                    }
                },
                'nullable',
                'string',
            ],
            'target_process' => [$prohibitImprovement, 'nullable', 'string'],
            'root_cause' => [$prohibitImprovement, 'nullable', 'string'],
            'expected_benefits' => [$prohibitImprovement, 'nullable', 'array'],
            // current_pdca_phase intentionally NOT accepted here: phase changes must
            // go through PATCH /projects/{project}/pdca-phase, which enforces the
            // sequential state machine and the mandatory KPI gate on 'check'.
        ];
    }

    /**
     * Build a closure rule that fails when a field is present in the request while
     * it does not belong to the resulting project type. Mirrors `prohibited_unless`
     * but resolves the type from (incoming || stored) so a partial update of an
     * existing project — which may omit `type` — is judged against the real type.
     */
    protected function prohibitedFieldValidator(bool $prohibited, string $message): callable
    {
        return function ($attribute, $value, $fail) use ($prohibited, $message) {
            if ($prohibited && $this->has($attribute) && $this->hasMeaningfulValue($value)) {
                $fail($message);
            }
        };
    }

    /**
     * قواعد المخاطر
     */
    protected function riskRules(): array
    {
        return [
            'risks' => 'nullable|array',
            'risks.*.id' => 'nullable|integer|exists:project_risks,id',
            'risks.*.description' => 'nullable|string|max:1000',
            'risks.*.probability' => 'nullable|in:low,medium,high',
            'risks.*.impact' => 'nullable|in:low,medium,high',
            'risks.*.mitigation' => 'nullable|string|max:1000',
            'risks.*.status' => 'nullable|in:open,mitigated,closed',
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
            'tasks.*.id' => 'nullable|integer|exists:tasks,id',
            'tasks.*.name' => 'nullable|string|max:255',
            'tasks.*.description' => 'nullable|string',
            'tasks.*.milestone_index' => 'nullable|integer',
            'tasks.*.milestone_id' => 'nullable|integer|exists:milestones,id',
            'tasks.*.assigned_to' => 'nullable|exists:users,id',
            'tasks.*.priority' => 'nullable|in:low,medium,high,urgent,critical',
            'tasks.*.start_date' => 'nullable|date',
            'tasks.*.due_date' => 'nullable|date|after_or_equal:tasks.*.start_date',
        ];
    }

    /**
     * خريطة انتقالات حالة المشروع المسموحة (state machine على الخادم).
     * يمنع الانتقالات غير المنطقية مثل completed→draft أو إعادة فتح cancelled لحالة منتهية.
     */
    protected const STATUS_TRANSITIONS = [
        'draft' => ['planning', 'in_progress', 'on_hold', 'completed', 'cancelled'],
        'planning' => ['draft', 'in_progress', 'on_hold', 'completed', 'cancelled'],
        'in_progress' => ['planning', 'on_hold', 'completed', 'cancelled'],
        'on_hold' => ['planning', 'in_progress', 'completed', 'cancelled'],
        'completed' => ['in_progress', 'on_hold'],
        'cancelled' => ['draft', 'planning'],
    ];

    /**
     * فحص انتقال الحالة بعد قواعد التحقق الأساسية.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->project) {
                return;
            }

            // Re-validate a department reassignment against the editor's creatable
            // scope (P3). Runs independently of the status flow below.
            $this->ensureDepartmentWithinScope($validator);

            if (! $this->filled('status')) {
                return;
            }

            $from = $this->project->status;
            $to = $this->input('status');

            if ($from === $to) {
                return;
            }

            $allowed = self::STATUS_TRANSITIONS[$from] ?? [];
            if (! in_array($to, $allowed, true)) {
                $validator->errors()->add('status', "لا يمكن نقل المشروع من حالة \"{$from}\" إلى \"{$to}\".");
            }

            if ($to === 'completed') {
                $this->ensureClosureFieldsPresent($validator);
                $this->ensureNoOpenTasks($validator);
            }
        });
    }

    /**
     * منع إكمال المشروع وله مهام لم تُغلق بعد. تُعتبر المهمة مغلقة إذا كانت
     * "مكتملة" (completed) أو "ملغاة" (cancelled)؛ أي حالة أخرى (todo / in_progress
     * / in_review / on_hold) تمنع الإغلاق. كان هذا يُحذَّر منه على الواجهة فقط؛
     * الآن يُفرَض على الخادم أيضاً.
     */
    protected function ensureNoOpenTasks($validator): void
    {
        $hasOpenTasks = $this->project->tasks()
            ->whereNotIn('status', [TaskStatus::COMPLETED->value, TaskStatus::CANCELLED->value])
            ->exists();

        if ($hasOpenTasks) {
            $validator->errors()->add(
                'status',
                'لا يمكن إكمال المشروع وبه مهام غير مكتملة. أكمِل المهام المفتوحة أو ألغِها أولاً.'
            );
        }
    }

    /**
     * عند تغيير قسم المشروع (department_id) إلى قسم مختلف، يجب أن يكون القسم
     * الجديد ضمن نطاق الإنشاء المسموح للمستخدم — بنفس آلية مسار create
     * (ProjectAuthorizationService::canCreate). يمنع نقل المشروع إلى قسم خارج
     * صلاحية المُعدِّل عبر تحديث مباشر.
     */
    protected function ensureDepartmentWithinScope($validator): void
    {
        if (! $this->has('department_id')) {
            return;
        }

        $newDepartmentId = $this->input('department_id');
        $newDepartmentId = $newDepartmentId !== null && $newDepartmentId !== ''
            ? (int) $newDepartmentId
            : null;

        // Unchanged target — nothing to re-check.
        if ($newDepartmentId === ($this->project->department_id !== null ? (int) $this->project->department_id : null)) {
            return;
        }

        // Clearing the department is not a scope escalation; leave it to other rules.
        if ($newDepartmentId === null) {
            return;
        }

        $user = $this->user();
        if ($user === null) {
            return;
        }

        $type = $this->input('type', $this->project->type);

        $allowed = app(ProjectAuthorizationService::class)->canCreate($user, $type, $newDepartmentId);

        if (! $allowed) {
            $validator->errors()->add(
                'department_id',
                'لا يمكنك نقل المشروع إلى قسم خارج نطاق صلاحيتك.'
            );
        }
    }

    /**
     * فرض حقول الإغلاق عند نقل المشروع إلى completed:
     *  - يجب توفير lessons_learned أو outcome_summary (واحد على الأقل)
     *  - يجب توفير achievement_status
     * يطبَّق فقط على الانتقال من حالة ≠ completed إلى completed، ويُراعى
     * القيم الحالية المخزّنة في DB (لا يُطلب من المستخدم إعادة إدخالها).
     */
    protected function ensureClosureFieldsPresent($validator): void
    {
        $incomingLessons = $this->input('lessons_learned');
        $existingLessons = $this->project?->lessons_learned;
        $incomingOutcome = $this->input('outcome_summary');
        $existingOutcome = $this->project?->outcome_summary;

        $hasLessons = $this->hasMeaningfulValue($incomingLessons) || $this->hasMeaningfulValue($existingLessons);
        $hasOutcome = $this->hasMeaningfulValue($incomingOutcome) || $this->hasMeaningfulValue($existingOutcome);

        if (! $hasLessons && ! $hasOutcome) {
            $message = 'حقل "الدروس المستفادة" أو "ملخص النتائج" مطلوب لإغلاق المشروع.';
            $validator->errors()->add('lessons_learned', $message);
            $validator->errors()->add('outcome_summary', $message);
        }

        $incomingAchievement = $this->input('achievement_status');
        $existingAchievement = $this->project?->achievement_status;
        if (! $this->hasMeaningfulValue($incomingAchievement) && ! $this->hasMeaningfulValue($existingAchievement)) {
            $validator->errors()->add(
                'achievement_status',
                'حقل "حالة التحقيق" مطلوب لإغلاق المشروع.'
            );
        }
    }

    /**
     * قيمة ذات معنى: ليست null، ليست فارغة، ليست نصاً من فراغات فقط،
     * وليست مصفوفة فارغة.
     */
    protected function hasMeaningfulValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    /**
     * الحصول على المشروع المحمّل
     */
    public function getProject(): ?Project
    {
        return $this->project;
    }

    /**
     * أسماء الحقول بالعربية
     */
    public function attributes(): array
    {
        return [
            'name' => 'اسم المشروع',
            'status' => 'حالة المشروع',
            'priority' => 'الأولوية',
            'start_date' => 'تاريخ البداية',
            'end_date' => 'تاريخ الانتهاء',
            'budget' => 'الميزانية',
            'actual_cost' => 'التكلفة الفعلية',
        ];
    }

    /**
     * رسائل الخطأ المخصصة
     */
    public function messages(): array
    {
        return [
            'name.required' => 'اسم المشروع مطلوب',
            'status.in' => 'حالة المشروع غير صالحة',
            'priority.in' => 'أولوية المشروع غير صالحة',
            'end_date.after_or_equal' => 'تاريخ الانتهاء يجب أن يكون بعد أو يساوي تاريخ البداية',
            'budget.min' => 'الميزانية يجب أن تكون صفر أو أكثر',
            'actual_cost.min' => 'التكلفة الفعلية يجب أن تكون صفر أو أكثر',
        ];
    }
}
