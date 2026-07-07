<?php

namespace App\Modules\HR\Http\Requests;

use App\Modules\HR\Http\Requests\Concerns\DepartmentAuthzFailureTrait;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
{
    use DepartmentAuthzFailureTrait;

    protected string $authzAbility = 'update';

    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $department = $this->route('department');
        if (! $department instanceof Department) {
            $department = Department::find($department);
        }
        if (! $department) {
            return false;
        }

        return $this->user()->can('update', $department);
    }

    public function rules(): array
    {
        $user = $this->user();

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'parent_id' => ['nullable', 'exists:departments,id'],
            'level' => ['required', 'integer', 'in:1,2,3,4,5,6'],
            'manager_id' => [
                'nullable',
                Rule::exists('users', 'id')->where(function ($query) use ($user) {
                    if (! $user->isSuperAdmin()) {
                        $query->where('organization_id', $user->organization_id);
                    }
                }),
            ],
            'is_active' => ['boolean'],
        ];
    }
}
