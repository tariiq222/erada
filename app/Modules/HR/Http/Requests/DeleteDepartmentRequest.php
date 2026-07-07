<?php

namespace App\Modules\HR\Http\Requests;

use App\Modules\HR\Http\Requests\Concerns\DepartmentAuthzFailureTrait;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Http\FormRequest;

class DeleteDepartmentRequest extends FormRequest
{
    use DepartmentAuthzFailureTrait;

    protected string $authzAbility = 'delete';

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

        return $this->user()->can('delete', $department);
    }

    public function rules(): array
    {
        return [];
    }
}
