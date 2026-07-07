<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Projects\Models\Project;
use App\Modules\Strategy\Models\Program;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreBlockerRequest - validation + engine-only authz for creating a Blocker.
 *
 * The previous controller called authorizeStrategy('create') directly and then
 * validated the payload inline. authorize() now resolves the strategy.create
 * capability through AccessDecision::can(), and the controller can drop both
 * the redundant authz call and the inline $request->validate() call.
 */
class StoreBlockerRequest extends FormRequest
{
    /**
     * Engine-only authorization for blocker creation.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && AccessDecision::can($user, Capability::STRATEGY_CREATE);
    }

    /**
     * Resolve the polymorphic blockable class for a given blockable_type key.
     * Mirrors BlockerController::getModelClass() so the FormRequest can run
     * type-aware rules (e.g. existence on the right table) when needed.
     */
    public function blockableModelClass(): string
    {
        return match ((string) $this->input('blockable_type')) {
            'program' => Program::class,
            'project' => Project::class,
            'task' => Task::class,
            default => '',
        };
    }

    /**
     * Validation rules for blocker creation.
     */
    public function rules(): array
    {
        $user = $this->user();
        $userRule = Rule::exists('users', 'id');

        if ($user?->isSuperAdmin() !== true) {
            if ($user?->organization_id === null) {
                // Mirror the controller's fail-fast on missing org: leave a
                // syntactically valid rule that will fail validation when the
                // controller attempts any further use.
                $userRule = Rule::exists('users', 'id')->where('organization_id', -1);
            } else {
                $userRule = $userRule->where('organization_id', $user->organization_id);
            }
        }

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'blockable_type' => ['required', Rule::in(['program', 'project', 'task'])],
            'blockable_id' => ['required', 'integer'],
            'severity' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'identified_date' => ['required', 'date'],
            'expected_resolution_date' => ['nullable', 'date', 'after_or_equal:identified_date'],
            'assigned_to' => ['nullable', $userRule],
        ];
    }

    public function attributes(): array
    {
        return [
            'title' => 'عنوان التعثر',
            'description' => 'وصف التعثر',
            'blockable_type' => 'نوع العنصر',
            'blockable_id' => 'معرّف العنصر',
            'severity' => 'درجة الخطورة',
            'identified_date' => 'تاريخ الرصد',
            'expected_resolution_date' => 'تاريخ الحل المتوقع',
            'assigned_to' => 'المسؤول',
        ];
    }
}
