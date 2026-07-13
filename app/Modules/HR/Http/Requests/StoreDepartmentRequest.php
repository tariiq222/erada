<?php

namespace App\Modules\HR\Http\Requests;

use App\Modules\HR\Http\Requests\Concerns\DepartmentAuthzFailureTrait;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDepartmentRequest extends FormRequest
{
    use DepartmentAuthzFailureTrait;

    protected string $authzAbility = 'create';

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        // D-03 null-org gate is enforced in failedAuthorization() (via the
        // DepartmentAuthzFailureTrait) so the response carries the precise
        // reason. authorize() returns true here so validation runs first and
        // any other 403 still flows through the trait's per-ability message.

        return $this->user()->can('create', Department::class);
    }

    public function rules(): array
    {
        $user = $this->user();
        $targetOrganizationId = $user->isSuperAdmin()
            ? $this->input('organization_id', $user->organization_id)
            : $user->organization_id;
        $parentExistsRule = $user->isSuperAdmin()
            ? Rule::exists('departments', 'id')
                ->where(fn ($query) => $query->where('organization_id', $targetOrganizationId))
            : 'exists:departments,id';

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'parent_id' => [
                'nullable',
                $parentExistsRule,
            ],
            'level' => ['required', 'integer', 'in:1,2,3,4,5,6'],
            'manager_id' => [
                'nullable',
                Rule::exists('users', 'id')
                    ->where(fn ($query) => $query->where('organization_id', $targetOrganizationId)),
            ],
            'is_active' => ['boolean'],
        ];

        if ($user->isSuperAdmin()) {
            $rules['organization_id'] = [
                Rule::requiredIf($user->organization_id === null),
                'nullable',
                'exists:organizations,id',
            ];
        }

        return $rules;
    }
}
