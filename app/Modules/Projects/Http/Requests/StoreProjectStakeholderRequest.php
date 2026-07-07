<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST /api/projects/{project}/stakeholders.
 *
 * Role whitelist enforcement lives in StakeholderService (single source of
 * truth for ALLOWED_ROLES) — this FormRequest intentionally accepts any
 * non-empty string to preserve the original controller behavior, and the
 * service coerces unknown roles to null.
 */
class StoreProjectStakeholderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        if (! $project instanceof Project) {
            $project = Project::find($project);
        }

        if (! $project) {
            return false;
        }

        return $this->user()->can('update', $project);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', 'string', 'max:255'],
            'organization' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'influence' => ['nullable', 'string', 'in:low,medium,high'],
            'interest' => ['nullable', 'string', 'in:low,medium,high'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
