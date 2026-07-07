<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StorePortfolioRequest - validation + engine-only authz for creating a Portfolio.
 *
 * The previous controller called authorizeStrategy('create') and validated
 * inline, with two super_admin-only rules (organization_id selection) and a
 * downstream priority/weight restriction check. authorize() now resolves
 * strategy.create through AccessDecision::can(); the priority/weight rule
 * stays as a normal validation rule (the controller still drops the field
 * silently when the user lacks the privilege, mirroring the original
 * behaviour for update where unset → strip; here, for store, the original
 * code returned 403 — we surface that as a validation rule).
 */
class StorePortfolioRequest extends FormRequest
{
    /**
     * Engine-only authorization for portfolio creation.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && AccessDecision::can($user, Capability::STRATEGY_CREATE);
    }

    /**
     * Whether the current user may set priority/weight on create.
     */
    public function canManagePriority(): bool
    {
        $user = $this->user();

        return $user !== null
            && AccessDecision::can($user, Capability::STRATEGY_MANAGE_PRIORITY);
    }

    /**
     * Validation rules for portfolio creation.
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'rationale' => ['nullable', 'string'],
            'strategic_plan_link' => ['nullable', 'string', 'max:500'],
            'directive_source' => ['nullable', Rule::in(['cluster_3', 'moh', 'holding', 'other'])],
            'directive_source_other' => ['nullable', 'string', 'max:255', 'required_if:directive_source,other'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', Rule::in(['draft', 'active', 'completed', 'cancelled'])],
            'portfolio_status' => ['nullable', Rule::in(['active', 'rebalancing', 'frozen', 'closed_strategically'])],
            'order' => ['nullable', 'integer', 'min:0'],
            'priority_rank' => ['nullable', 'integer', 'min:0'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];

        if ($this->user()?->isSuperAdmin() === true) {
            $rules['organization_id'] = ['nullable', Rule::exists('organizations', 'id')];
        }

        return $rules;
    }

    /**
     * Reject priority/weight from users who cannot manage them.
     *
     * Mirrors the controller's prior behavior: a non-privileged user attempting
     * to set either field receives 403-shaped validation errors. The controller
     * still has the option to silently strip the fields (matches the update
     * path) — the validator only fails when the user is unauthorized AND sent
     * the field.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->canManagePriority()) {
                return;
            }

            if ($this->filled('priority_rank')) {
                $validator->errors()->add(
                    'priority_rank',
                    __('validation.messages.portfolio_priority_not_allowed')
                );
            }

            if ($this->filled('weight')) {
                $validator->errors()->add(
                    'weight',
                    __('validation.messages.portfolio_weight_not_allowed')
                );
            }
        });
    }

    public function attributes(): array
    {
        return [
            'name' => 'اسم الالتزام',
            'directive_source' => 'جهة التوجيه',
            'directive_source_other' => 'جهة التوجيه (أخرى)',
            'portfolio_status' => 'الحالة الاستراتيجية',
            'priority_rank' => 'ترتيب الأولوية',
            'weight' => 'الوزن',
        ];
    }

    /**
     * Whether the request passed the priority/weight guard. Controllers can use
     * this to silently strip unauthorized fields instead of returning 403, the
     * same way the update() path used to.
     */
    public function shouldStripPrivilegedFields(): bool
    {
        return ! $this->canManagePriority();
    }
}
