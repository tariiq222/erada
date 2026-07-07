<?php

namespace App\Modules\HR\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class DeleteEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user || ! AccessDecision::can($user, Capability::HR_MANAGE)) {
            return false;
        }

        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return false;
        }

        $employee = $this->route('employee');
        if (! $employee instanceof User) {
            $employee = User::find($employee);
        }
        if (! $employee) {
            return false;
        }

        if (! $user->isSuperAdmin() && $employee->organization_id !== $user->organization_id) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
